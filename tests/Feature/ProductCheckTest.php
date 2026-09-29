<?php

declare(strict_types=1);

namespace Tests\Feature;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;

final class ProductCheckTest extends FeatureTestCase
{
    private const KASPI = 'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный';

    private const RIGHT = 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)';

    private const WRONG = 'Samsung Galaxy S26 Ultra 12 ГБ/512 GB черный (350145977598027)';

    /**
     * @param  array<string, mixed>  $body
     */
    private function check(array $body): \App\Http\Response
    {
        return $this->post('/api/bitrix/check-product', $body + ['token' => 'test-callback-token']);
    }

    public function test_matching_product_is_written_back_to_bitrix(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::RIGHT)]);

        $response = $this->check(['deal_id' => 10]);

        self::assertSame(200, $response->status);
        self::assertTrue($response->data['match']);
        self::assertSame('OK', $response->data['check_status']);
        self::assertSame('OK', $this->deals[10][self::STATUS_FIELD]);
        self::assertSame(64, strlen($this->deals[10][self::HASH_FIELD]));
    }

    public function test_storage_mismatch_writes_only_status_and_logs_reason(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::WRONG)]);

        $response = $this->check(['deal_id' => 10]);

        self::assertSame(200, $response->status);
        self::assertFalse($response->data['match']);
        self::assertSame('storage', $response->data['differences'][0]['field']);
        self::assertSame([self::STATUS_FIELD, self::HASH_FIELD], array_keys($this->callsTo('crm.deal.update')[0]['fields']));
        $this->assertLogged(
            'Bitrix product check completed',
            static fn (array $context): bool => str_contains($context['message'], 'Память: Kaspi 256 GB / Bitrix 512 GB'),
        );
    }

    public function test_wrong_product_posts_timeline_comment_and_notifies_users(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::WRONG)]);

        $this->check(['deal_id' => 10]);

        $comment = $this->callsTo('crm.timeline.comment.add')[0]['fields'];
        self::assertSame(10, $comment['ENTITY_ID']);
        self::assertStringContainsString('Неверный товар', $comment['COMMENT']);

        $notified = array_column($this->callsTo('im.notify.system.add'), 'USER_ID');
        sort($notified);
        self::assertSame([5, 9], $notified);
        self::assertStringContainsString(
            '[URL=https://example.bitrix24.kz/crm/deal/details/10/]',
            $this->callsTo('im.notify.system.add')[0]['MESSAGE'],
        );
    }

    public function test_notification_failure_does_not_break_the_check(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::WRONG)]);
        $this->overrides['im.notify.system.add'] = static fn (): PsrResponse => new PsrResponse(
            401,
            [],
            (string) json_encode(['error' => 'insufficient_scope', 'error_description' => 'im']),
        );

        $response = $this->check(['deal_id' => 10]);

        self::assertSame(200, $response->status);
        self::assertSame('ERROR', $this->deals[10][self::STATUS_FIELD]);
        $this->assertLogged('Bitrix product check notification failed');
    }

    public function test_missing_product_rows_are_recorded_as_not_attached(): void
    {
        $this->givenDeal(10, self::KASPI, []);

        $response = $this->check(['deal_id' => 10]);

        self::assertFalse($response->data['match']);
        self::assertSame('ERROR', $this->deals[10][self::STATUS_FIELD]);
        self::assertCount(1, $this->callsTo('crm.timeline.comment.add'));
    }

    public function test_deal_prefix_and_document_id_are_accepted(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::RIGHT)]);

        self::assertSame(10, $this->check(['document_id' => ['crm', 'CCrmDocumentDeal', 'DEAL_10']])->data['deal_id']);
        self::assertSame(10, $this->check(['deal_id' => 'DEAL_10'])->data['deal_id']);
    }

    public function test_query_deal_id_and_header_token_are_accepted(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::RIGHT)]);

        $response = $this->post('/api/bitrix/check-product', [], ['deal_id' => '10'], ['X-Bitrix-Token' => 'test-callback-token']);

        self::assertSame(200, $response->status);
    }

    public function test_deal_id_is_required(): void
    {
        self::assertSame(422, $this->check([])->status);
    }

    public function test_token_is_required(): void
    {
        $response = $this->post('/api/bitrix/check-product', ['deal_id' => 10, 'token' => 'wrong']);

        self::assertSame(401, $response->status);
        self::assertSame([], $this->calls);
    }

    public function test_empty_kaspi_field_returns_422(): void
    {
        $this->givenDeal(10, '  ', [$this->row(self::RIGHT)]);

        self::assertSame(422, $this->check(['deal_id' => 10])->status);
        self::assertSame([], $this->callsTo('crm.deal.update'));
    }

    public function test_missing_deal_returns_404(): void
    {
        self::assertSame(404, $this->check(['deal_id' => 999])->status);
    }

    public function test_bitrix_server_error_is_retried_then_returns_502(): void
    {
        $this->overrides['crm.deal.get'] = static fn (): PsrResponse => new PsrResponse(500, [], '{}');

        $response = $this->check(['deal_id' => 10]);

        self::assertSame(502, $response->status);
        self::assertCount(3, $this->callsTo('crm.deal.get'));
    }

    public function test_connection_errors_do_not_log_the_webhook(): void
    {
        $this->overrides['crm.deal.get'] = static fn (): ConnectException => new ConnectException(
            'cURL error 28 for '.self::WEBHOOK.'/crm.deal.get',
            new PsrRequest('POST', self::WEBHOOK),
        );

        $response = $this->check(['deal_id' => 10]);

        self::assertSame(502, $response->status);
        self::assertStringNotContainsString('secret-webhook-key', (string) json_encode($response->data));
        $this->assertLogsDoNotContain('secret-webhook-key');
        $this->assertLogged('Bitrix API connection failed');
    }

    public function test_duplicate_inflight_check_returns_429(): void
    {
        mkdir($this->lockDir, 0775, true);
        $handle = fopen($this->lockDir.'/deal-10.lock', 'c');
        flock($handle, LOCK_EX);

        try {
            $response = $this->check(['deal_id' => 10]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertSame(429, $response->status);
    }

    public function test_debug_route_is_available_only_locally(): void
    {
        $this->givenDeal(10, self::KASPI, [$this->row(self::RIGHT)]);

        self::assertSame(404, $this->get('/api/debug/bitrix/deal/10')->status);

        $this->env['APP_ENV'] = 'local';
        $response = $this->get('/api/debug/bitrix/deal/10');

        self::assertSame(200, $response->status);
        self::assertSame(64, strlen($response->data['current_products_hash']));
        self::assertStringNotContainsString('secret-webhook-key', (string) json_encode($response->data));
    }
}

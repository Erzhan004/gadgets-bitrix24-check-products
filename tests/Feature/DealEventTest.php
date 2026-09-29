<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductRowsHashService;

final class DealEventTest extends FeatureTestCase
{
    private const KASPI = 'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный';

    private const RIGHT = 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)';

    private const WRONG = 'Samsung Galaxy S26 Ultra 12 ГБ/512 GB черный';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(int $dealId, array $overrides = []): array
    {
        return array_replace_recursive([
            'event' => 'ONCRMDEALUPDATE',
            'event_handler_id' => '12',
            'data' => ['FIELDS' => ['ID' => (string) $dealId]],
            'ts' => '1790000000',
            'auth' => ['application_token' => 'test-application-token', 'domain' => 'example.bitrix24.kz'],
        ], $overrides);
    }

    public function test_invalid_application_token_is_rejected(): void
    {
        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1, ['auth' => ['application_token' => 'wrong']]));

        self::assertSame(401, $response->status);
        self::assertSame([], $this->calls);
    }

    public function test_query_callback_token_is_accepted_as_fallback(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::RIGHT)]);
        $body = $this->event(1);
        unset($body['auth']);

        $response = $this->post('/api/bitrix/events/deal-update', $body, ['token' => 'test-callback-token']);

        self::assertSame(200, $response->status);
        self::assertSame('checked', $response->data['status']);
    }

    public function test_missing_deal_id_returns_422(): void
    {
        $response = $this->post('/api/bitrix/events/deal-update', $this->event(0, ['data' => ['FIELDS' => ['ID' => '']]]));

        self::assertSame(422, $response->status);
    }

    public function test_other_events_are_ignored(): void
    {
        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1, ['event' => 'ONCRMCONTACTUPDATE']));

        self::assertSame('ignored', $response->data['status']);
        self::assertSame([], $this->calls);
    }

    public function test_empty_hash_with_products_runs_full_check_and_saves_hash(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::WRONG)]);

        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('checked', $response->data['status']);
        self::assertFalse($response->data['match']);

        $update = $this->callsTo('crm.deal.update')[0]['fields'];
        self::assertSame([self::STATUS_FIELD, self::HASH_FIELD], array_keys($update));
        self::assertSame('ERROR', $update[self::STATUS_FIELD]);
        self::assertSame((new ProductRowsHashService)->make($this->rows[1]), $update[self::HASH_FIELD]);
        self::assertCount(1, $this->callsTo('crm.timeline.comment.add'));
        $this->assertLogged('First product check');
    }

    public function test_empty_hash_without_products_is_skipped(): void
    {
        $this->givenDeal(1, self::KASPI, []);

        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('skipped', $response->data['status']);
        self::assertSame('no_products', $response->data['reason']);
        self::assertSame([], $this->callsTo('crm.deal.update'));
    }

    public function test_same_hash_skips_check(): void
    {
        $rows = [$this->row(self::RIGHT)];
        $this->givenDeal(1, self::KASPI, $rows, [self::HASH_FIELD => (new ProductRowsHashService)->make($rows)]);

        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('products_not_changed', $response->data['reason']);
        self::assertSame([], $this->callsTo('crm.deal.update'));
    }

    public function test_changed_hash_runs_check(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::RIGHT)], [self::HASH_FIELD => str_repeat('a', 64)]);

        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('checked', $response->data['status']);
        self::assertTrue($response->data['match']);
        $this->assertLogged('Product rows changed, running full check');
    }

    public function test_match_saves_ok_without_notification(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::RIGHT)]);

        $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('OK', $this->deals[1][self::STATUS_FIELD]);
        self::assertSame([], $this->callsTo('crm.timeline.comment.add'));
        self::assertSame([], $this->callsTo('im.notify.system.add'));
    }

    public function test_repeated_event_after_saving_hash_is_skipped(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::WRONG)]);

        $this->post('/api/bitrix/events/deal-update', $this->event(1));
        $second = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('products_not_changed', $second->data['reason']);
        self::assertCount(1, $this->callsTo('crm.deal.update'));
    }

    public function test_price_change_does_not_trigger_check(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::RIGHT, price: 1000)]);
        $this->post('/api/bitrix/events/deal-update', $this->event(1));

        $this->rows[1] = [$this->row(self::RIGHT, price: 900)];
        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('products_not_changed', $response->data['reason']);
    }

    public function test_empty_kaspi_field_skips_without_saving_hash(): void
    {
        $this->givenDeal(1, '', [$this->row(self::RIGHT)]);

        $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));

        self::assertSame('kaspi_product_missing', $response->data['reason']);
        self::assertSame([], $this->callsTo('crm.deal.update'));
    }

    public function test_busy_deal_is_reported_as_skipped(): void
    {
        $this->givenDeal(1, self::KASPI, [$this->row(self::RIGHT)]);
        mkdir($this->lockDir, 0775, true);
        $handle = fopen($this->lockDir.'/deal-1.lock', 'c');
        flock($handle, LOCK_EX);

        try {
            $response = $this->post('/api/bitrix/events/deal-update', $this->event(1));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        self::assertSame(200, $response->status);
        self::assertSame('already_running', $response->data['reason']);
        self::assertSame([], $this->calls);
    }
}

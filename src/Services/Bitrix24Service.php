<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BitrixApiException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class Bitrix24Service
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly string $webhookUrl,
        private readonly int $timeoutSeconds = 15,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getDeal(int $dealId): array
    {
        $result = $this->call('crm.deal.get', ['id' => $dealId]);

        if (! is_array($result)) {
            throw new BitrixApiException('Bitrix24 вернул пустую сделку.', 404);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDealProducts(int $dealId): array
    {
        $result = $this->call('crm.deal.productrows.get', ['id' => $dealId]);

        if (! is_array($result)) {
            return [];
        }

        return array_values(array_filter($result, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public function updateDeal(int $dealId, array $fields): mixed
    {
        return $this->call('crm.deal.update', [
            'id' => $dealId,
            'fields' => $fields,
        ]);
    }

    public function addDealTimelineComment(int $dealId, string $comment): mixed
    {
        return $this->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $dealId,
                'ENTITY_TYPE' => 'deal',
                'COMMENT' => $comment,
            ],
        ]);
    }

    public function notifyUser(int $userId, string $message): mixed
    {
        return $this->call('im.notify.system.add', [
            'USER_ID' => $userId,
            'MESSAGE' => $message,
        ]);
    }

    public function dealUrl(int $dealId): ?string
    {
        $parts = parse_url($this->webhookUrl);

        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].'/crm/deal/details/'.$dealId.'/';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call(string $method, array $payload): mixed
    {
        if (trim($this->webhookUrl) === '') {
            throw new BitrixApiException('BITRIX_WEBHOOK_URL не настроен.', 502);
        }

        try {
            $response = $this->http->request('POST', rtrim($this->webhookUrl, '/').'/'.$method, [
                RequestOptions::JSON => $payload,
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
                RequestOptions::TIMEOUT => $this->timeoutSeconds,
                RequestOptions::HTTP_ERRORS => false,
            ]);
        } catch (ConnectException $exception) {
            $this->logger->error('Bitrix API connection failed', [
                'method' => $method,
                'message' => $this->redact($exception->getMessage()),
            ]);

            throw new BitrixApiException('Не удалось подключиться к Bitrix24.', 502);
        } catch (GuzzleException $exception) {
            $this->logger->error('Bitrix API request failed', [
                'method' => $method,
                'message' => $this->redact($exception->getMessage()),
            ]);

            throw new BitrixApiException('Ошибка Bitrix24 API.', 502);
        }

        return $this->decode($method, $response);
    }

    private function decode(string $method, ResponseInterface $response): mixed
    {
        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body)) {
            $body = [];
        }

        $error = $body['error'] ?? null;
        $description = $body['error_description'] ?? null;
        $failed = $response->getStatusCode() >= 400;

        if ($failed || $this->hasBitrixError($error, $description)) {
            $this->logger->error('Bitrix API request failed', [
                'method' => $method,
                'status' => $response->getStatusCode(),
                'error' => is_scalar($error) ? $this->redact((string) $error) : null,
                'error_description' => is_scalar($description) ? $this->redact((string) $description) : null,
            ]);

            $status = $this->isNotFound($error, $description) ? 404 : 502;

            throw new BitrixApiException(
                $status === 404 ? 'Сделка не найдена.' : 'Ошибка Bitrix24 API.',
                $status,
            );
        }

        return $body['result'] ?? null;
    }

    private function hasBitrixError(mixed $error, mixed $description): bool
    {
        if (is_string($error) && $error !== '') {
            return true;
        }

        return is_string($description) && trim($description) !== '';
    }

    private function isNotFound(mixed $error, mixed $description): bool
    {
        $text = mb_strtolower(trim((is_scalar($error) ? (string) $error : '').' '.(is_scalar($description) ? (string) $description : '')));

        return str_contains($text, 'not found')
            || str_contains($text, 'не найден')
            || str_contains($text, 'error_not_found');
    }

    private function redact(string $value): string
    {
        $base = rtrim($this->webhookUrl, '/');

        if ($base !== '') {
            $value = str_replace($base, '[redacted-webhook]', $value);
        }

        return preg_replace('#https?://\S+/rest/\d+/[^/\s]+#i', '[redacted-webhook]', $value) ?? $value;
    }
}

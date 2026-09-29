<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\BitrixApiException;
use App\Exceptions\BitrixConfigurationException;
use App\Exceptions\KaspiProductMissingException;
use App\Services\Bitrix24Service;
use App\Services\DealProductCheckService;
use App\Services\ProductParserService;
use App\Services\ProductRowsHashService;
use App\Support\DealLock;
use App\Support\Settings;
use Psr\Log\LoggerInterface;
use Throwable;

final class Kernel
{
    private const DEAL_ID_KEYS = ['deal_id', 'dealId', 'DEAL_ID', 'ID', 'id'];

    private const TOKEN_KEYS = ['token', 'TOKEN'];

    public function __construct(
        private readonly Settings $settings,
        private readonly DealProductCheckService $service,
        private readonly Bitrix24Service $bitrix,
        private readonly ProductParserService $parser,
        private readonly ProductRowsHashService $hasher,
        private readonly DealLock $lock,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return $this->route($request);
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled error', [
                'path' => $request->path,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return Response::json(['success' => false, 'message' => 'Внутренняя ошибка сервера.'], 500);
        }
    }

    private function route(Request $request): Response
    {
        $path = preg_replace('#^/api(?=/)#', '', $request->path) ?? $request->path;

        if ($request->method === 'POST' && $path === '/bitrix/events/deal-update') {
            return $this->dealUpdated($request);
        }

        if ($request->method === 'POST' && $path === '/bitrix/check-product') {
            return $this->checkProduct($request);
        }

        if ($this->settings->isLocal()
            && $request->method === 'GET'
            && preg_match('#^/debug/bitrix/deal/(\d+)$#', $path, $matches) === 1) {
            return $this->debugDeal((int) $matches[1]);
        }

        return Response::json(['success' => false, 'message' => 'Not found.'], 404);
    }

    /**
     * Исходящий вебхук ONCRMDEALUPDATE. Проверка выполняется сразу в запросе:
     * на обычном хостинге нет воркера очередей.
     */
    private function dealUpdated(Request $request): Response
    {
        if (! $this->eventAuthorized($request)) {
            return $this->unauthorized();
        }

        $event = strtoupper(trim(is_scalar($request->input('event')) ? (string) $request->input('event') : ''));

        if ($event !== '' && ! in_array($event, $this->settings->events, true)) {
            return Response::json(['success' => true, 'status' => 'ignored', 'event' => $event]);
        }

        $rawId = $request->input('data.FIELDS.ID');
        $dealId = is_numeric($rawId) ? (int) $rawId : 0;

        if ($dealId <= 0) {
            return Response::json(['success' => false, 'message' => 'Не передан ID сделки.'], 422);
        }

        $this->logger->info('Bitrix deal update received', ['deal_id' => $dealId, 'event' => $event]);

        return $this->locked($dealId, fn (): array => $this->service->checkIfProductsChanged($dealId), busyAsSkip: true);
    }

    private function checkProduct(Request $request): Response
    {
        if (! $this->callbackAuthorized($request)) {
            return $this->unauthorized();
        }

        $dealId = $this->resolveDealId($request);

        if ($dealId === null) {
            return Response::json(['success' => false, 'message' => 'Параметр deal_id обязателен.'], 422);
        }

        return $this->locked($dealId, fn (): array => $this->service->check($dealId), busyAsSkip: false);
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    private function locked(int $dealId, callable $callback, bool $busyAsSkip): Response
    {
        try {
            $outcome = $this->lock->run($dealId, $callback);
        } catch (KaspiProductMissingException $exception) {
            return $this->failure($exception->getMessage(), $dealId, 422);
        } catch (BitrixConfigurationException $exception) {
            return $this->failure($exception->getMessage(), $dealId, 500);
        } catch (BitrixApiException $exception) {
            $status = $exception->httpStatus === 404 ? 404 : 502;

            return $this->failure($status === 404 ? 'Сделка не найдена.' : 'Ошибка Bitrix24 API.', $dealId, $status);
        }

        if (! $outcome['acquired']) {
            // Bitrix повторяет событие при не-2xx ответе, поэтому занятую сделку отдаём как пропуск.
            return $busyAsSkip
                ? Response::json(['success' => true, 'deal_id' => $dealId, 'status' => 'skipped', 'reason' => 'already_running'])
                : $this->failure('Проверка этой сделки уже выполняется.', $dealId, 429);
        }

        return Response::json($outcome['result']);
    }

    private function debugDeal(int $dealId): Response
    {
        try {
            $deal = $this->bitrix->getDeal($dealId);
            $rows = $this->bitrix->getDealProducts($dealId);
        } catch (BitrixApiException $exception) {
            $status = $exception->httpStatus === 404 ? 404 : 502;

            return Response::json([
                'success' => false,
                'message' => $status === 404 ? 'Сделка не найдена.' : 'Ошибка Bitrix24 API.',
            ], $status);
        }

        $kaspiText = $this->settings->kaspiField !== '' ? ($deal[$this->settings->kaspiField] ?? null) : null;

        if (is_array($kaspiText)) {
            $kaspiText = implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $kaspiText));
        }

        $kaspiText = is_string($kaspiText) && trim($kaspiText) !== '' ? trim($kaspiText) : null;

        return Response::json([
            'deal_id' => $dealId,
            'title' => $deal['TITLE'] ?? null,
            'stage_id' => $deal['STAGE_ID'] ?? null,
            'fields' => [
                'kaspi_product' => $kaspiText,
                'check_status' => $this->settings->statusField !== '' ? ($deal[$this->settings->statusField] ?? null) : null,
                'products_hash' => $deal[$this->settings->hashField] ?? null,
            ],
            'products' => array_map(static fn (array $row): array => [
                'product_id' => $row['PRODUCT_ID'] ?? null,
                'name' => $row['PRODUCT_NAME'] ?? null,
                'quantity' => $row['QUANTITY'] ?? null,
            ], $rows),
            'current_products_hash' => $rows === [] ? null : $this->hasher->make($rows),
            'parsed' => [
                'kaspi' => $kaspiText === null ? [] : array_map(
                    static fn ($product) => $product->toArray(),
                    $this->parser->parseKaspiProducts($kaspiText),
                ),
                'bitrix' => array_map(
                    static fn ($product) => $product->toArray(),
                    $this->parser->parseBitrixProducts($rows),
                ),
            ],
        ]);
    }

    private function eventAuthorized(Request $request): bool
    {
        $appToken = $request->input('auth.application_token');

        if ($this->settings->eventApplicationToken !== '' && is_string($appToken) && $appToken !== ''
            && hash_equals($this->settings->eventApplicationToken, $appToken)) {
            return true;
        }

        $queryToken = $request->query('token');

        return $this->settings->callbackToken !== '' && is_string($queryToken) && $queryToken !== ''
            && hash_equals($this->settings->callbackToken, $queryToken);
    }

    private function callbackAuthorized(Request $request): bool
    {
        if ($this->settings->callbackToken === '') {
            return false;
        }

        $candidates = [];

        foreach (self::TOKEN_KEYS as $key) {
            $candidates[] = $request->input($key);
        }

        $candidates[] = $request->header('x-bitrix-token');
        $candidates[] = $request->bearerToken();

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && hash_equals($this->settings->callbackToken, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function resolveDealId(Request $request): ?int
    {
        $raw = null;

        foreach (self::DEAL_ID_KEYS as $key) {
            $value = $request->input($key);

            if ($value !== null && $value !== '') {
                $raw = $value;
                break;
            }
        }

        if ($raw === null) {
            $documentId = $request->input('document_id');

            if (is_string($documentId) && $documentId !== '') {
                $raw = $documentId;
            } elseif (is_array($documentId) && $documentId !== []) {
                $raw = $documentId[2] ?? end($documentId);
            }
        }

        $raw ??= $request->input('data.FIELDS.ID');

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/\d+/', $raw, $matches) === 1) {
            $id = (int) $matches[0];

            return $id > 0 ? $id : null;
        }

        return null;
    }

    private function failure(string $message, int $dealId, int $status): Response
    {
        return Response::json(['success' => false, 'message' => $message, 'deal_id' => $dealId], $status);
    }

    private function unauthorized(): Response
    {
        return Response::json(['success' => false, 'message' => 'Unauthorized.'], 401);
    }
}

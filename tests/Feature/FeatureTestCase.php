<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application;
use App\Http\Request;
use App\Http\Response;
use App\Support\Settings;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

abstract class FeatureTestCase extends TestCase
{
    protected const WEBHOOK = 'https://example.bitrix24.kz/rest/1/secret-webhook-key';

    protected const KASPI_FIELD = 'UF_CRM_KASPI_PRODUCT';

    protected const STATUS_FIELD = 'UF_CRM_PRODUCT_CHECK_STATUS';

    protected const HASH_FIELD = 'UF_CRM_PRODUCTS_HASH';

    /** @var array<int, array<string, mixed>> */
    protected array $deals = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    protected array $rows = [];

    /** @var array<int, array{method: string, payload: array<string, mixed>}> */
    protected array $calls = [];

    /**
     * Ответ-заглушка на конкретный метод: callable(array $payload): PsrResponse|\Throwable.
     *
     * @var array<string, callable>
     */
    protected array $overrides = [];

    protected TestHandler $logs;

    protected string $lockDir;

    /** @var array<string, string> */
    protected array $env = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new TestHandler;
        $this->lockDir = sys_get_temp_dir().'/bitrix-check-locks-'.bin2hex(random_bytes(4));
        $this->env = [
            'APP_ENV' => 'testing',
            'BITRIX_WEBHOOK_URL' => self::WEBHOOK,
            'BITRIX_CALLBACK_TOKEN' => 'test-callback-token',
            'BITRIX_EVENT_APPLICATION_TOKEN' => 'test-application-token',
            'BITRIX_KASPI_PRODUCT_FIELD' => self::KASPI_FIELD,
            'BITRIX_PRODUCT_CHECK_STATUS_FIELD' => self::STATUS_FIELD,
            'BITRIX_PRODUCTS_HASH_FIELD' => self::HASH_FIELD,
            'BITRIX_NOTIFY_USER_IDS' => '9',
            'BITRIX_HTTP_RETRY_SLEEP_MS' => '0',
            'LOCK_DIR' => $this->lockDir,
        ];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->lockDir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->lockDir);

        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $extra
     */
    protected function givenDeal(int $id, string $kaspi, array $rows, array $extra = []): void
    {
        $this->deals[$id] = array_merge([
            'ID' => (string) $id,
            'TITLE' => 'Заказ '.$id,
            'ASSIGNED_BY_ID' => '5',
            self::KASPI_FIELD => $kaspi,
        ], $extra);
        $this->rows[$id] = $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(string $name, int|float $quantity = 1, int $productId = 100, float $price = 1000): array
    {
        return ['PRODUCT_ID' => $productId, 'PRODUCT_NAME' => $name, 'QUANTITY' => $quantity, 'PRICE' => $price];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     */
    protected function post(string $path, array $body = [], array $query = [], array $headers = []): Response
    {
        return $this->kernel()->handle(new Request('POST', $path, $query, $body, array_change_key_case($headers)));
    }

    protected function get(string $path): Response
    {
        return $this->kernel()->handle(new Request('GET', $path));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['payload'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }

    protected function assertLogged(string $message, ?callable $contextCheck = null): void
    {
        foreach ($this->logs->getRecords() as $record) {
            if ($record->message === $message && ($contextCheck === null || $contextCheck($record->context))) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        self::fail('Log record not found: '.$message);
    }

    protected function assertLogsDoNotContain(string $needle): void
    {
        foreach ($this->logs->getRecords() as $record) {
            self::assertStringNotContainsString($needle, $record->message.json_encode($record->context, JSON_UNESCAPED_UNICODE));
        }
    }

    protected function kernel(): \App\Http\Kernel
    {
        return Application::kernel(
            Settings::fromEnv($this->env, dirname(__DIR__, 2)),
            dirname(__DIR__, 2),
            new Logger('test', [$this->logs]),
            fn (RequestInterface $request, array $options): PromiseInterface => $this->handleBitrix($request),
        );
    }

    private function handleBitrix(RequestInterface $request): PromiseInterface
    {
        $method = basename($request->getUri()->getPath());
        $payload = json_decode((string) $request->getBody(), true) ?: [];
        $this->calls[] = ['method' => $method, 'payload' => $payload];

        if (isset($this->overrides[$method])) {
            $result = ($this->overrides[$method])($payload);

            if ($result instanceof ConnectException) {
                return Create::rejectionFor(new ConnectException($result->getMessage(), $request));
            }

            return Create::promiseFor($result);
        }

        $id = (int) ($payload['id'] ?? 0);

        $result = match ($method) {
            'crm.deal.get' => isset($this->deals[$id])
                ? ['result' => $this->deals[$id]]
                : ['error' => 'ERROR_NOT_FOUND', 'error_description' => 'Not found'],
            'crm.deal.productrows.get' => ['result' => $this->rows[$id] ?? []],
            'crm.deal.update' => $this->applyUpdate($id, $payload['fields'] ?? []),
            default => ['result' => true],
        };

        return Create::promiseFor(new PsrResponse(isset($result['error']) ? 400 : 200, [], json_encode($result, JSON_UNESCAPED_UNICODE)));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function applyUpdate(int $id, array $fields): array
    {
        $this->deals[$id] = array_merge($this->deals[$id] ?? [], $fields);

        return ['result' => true];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductCheckStatus;
use App\Exceptions\BitrixConfigurationException;
use App\Exceptions\KaspiProductMissingException;
use App\Support\Settings;
use Psr\Log\LoggerInterface;

final class DealProductCheckService
{
    public function __construct(
        private readonly Bitrix24Service $bitrix,
        private readonly ProductParserService $parser,
        private readonly ProductComparisonService $comparison,
        private readonly DealCheckNotifier $notifier,
        private readonly ProductRowsHashService $hasher,
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Сценарий ONCRMDEALUPDATE: полная проверка только если товарные позиции изменились.
     * Прошлый hash хранится в сделке (UF_CRM_PRODUCTS_HASH), своей БД нет.
     * Когда hash совпадает, сделка не обновляется: иначе Bitrix пришлёт новое событие.
     *
     * @return array<string, mixed>
     */
    public function checkIfProductsChanged(int $dealId): array
    {
        $hashField = $this->hashField();
        $deal = $this->bitrix->getDeal($dealId);
        $rows = $this->bitrix->getDealProducts($dealId);

        if ($rows === []) {
            $this->logger->info('Product check skipped', ['deal_id' => $dealId, 'reason' => 'no_products']);

            return $this->skipped($dealId, 'no_products');
        }

        $currentHash = $this->hasher->make($rows);
        $oldHash = trim(is_scalar($deal[$hashField] ?? null) ? (string) $deal[$hashField] : '');

        $this->logger->info('Product rows hash compared', [
            'deal_id' => $dealId,
            'changed' => $oldHash !== $currentHash,
        ]);

        if ($oldHash === $currentHash) {
            $this->logger->info('Product check skipped', ['deal_id' => $dealId, 'reason' => 'products_not_changed']);

            return $this->skipped($dealId, 'products_not_changed');
        }

        if ($oldHash === '') {
            $this->logger->info('First product check', ['deal_id' => $dealId]);
        } else {
            $this->logger->info('Product rows changed, running full check', ['deal_id' => $dealId]);
        }

        try {
            return $this->runFullCheck($dealId, $deal, $rows, $currentHash);
        } catch (KaspiProductMissingException $exception) {
            // Hash не сохраняется: когда Kaspi-поле заполнят, следующее событие запустит проверку.
            $this->logger->warning('Product check skipped', [
                'deal_id' => $dealId,
                'reason' => 'kaspi_product_missing',
                'message' => $exception->getMessage(),
            ]);

            return $this->skipped($dealId, 'kaspi_product_missing');
        }
    }

    /**
     * Ручная проверка без сравнения hash. Hash тоже сохраняется,
     * чтобы последующие ONCRMDEALUPDATE не повторяли ту же проверку.
     *
     * @return array<string, mixed>
     */
    public function check(int $dealId): array
    {
        $this->logger->info('Bitrix product check started', ['deal_id' => $dealId]);

        $deal = $this->bitrix->getDeal($dealId);
        $rows = $this->bitrix->getDealProducts($dealId);

        return $this->runFullCheck($dealId, $deal, $rows, $this->hasher->make($rows));
    }

    /**
     * @param  array<string, mixed>  $deal
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function runFullCheck(int $dealId, array $deal, array $rows, string $currentHash): array
    {
        if ($this->settings->kaspiField === '') {
            throw new BitrixConfigurationException('Не настроено поле BITRIX_KASPI_PRODUCT_FIELD.');
        }

        $kaspiText = $this->extractKaspiText($deal[$this->settings->kaspiField] ?? null);

        if ($kaspiText === null) {
            throw new KaspiProductMissingException('Поле товара Kaspi пустое.');
        }

        $bitrixProducts = $this->parser->parseBitrixProducts($rows);

        if ($bitrixProducts === []) {
            $message = 'Товар не привязан к сделке.';
            $this->write($dealId, ProductCheckStatus::NotAttached, $currentHash);
            $this->notifier->notifyProblem($dealId, $deal, $message, []);

            $this->logger->info('Bitrix product check completed', [
                'deal_id' => $dealId,
                'match' => false,
                'status' => $this->statusLabel(ProductCheckStatus::NotAttached),
                'message' => $message,
            ]);

            return [
                'success' => true,
                'deal_id' => $dealId,
                'status' => 'checked',
                'match' => false,
                'check_status' => $this->statusLabel(ProductCheckStatus::NotAttached),
                'differences' => [],
            ];
        }

        $kaspiProducts = $this->parser->parseKaspiProducts($kaspiText);

        if ($kaspiProducts === []) {
            throw new KaspiProductMissingException('Не удалось разобрать название товара Kaspi.');
        }

        $result = $this->comparison->compare($kaspiProducts, $bitrixProducts);
        $status = $result['match'] ? ProductCheckStatus::Match : ProductCheckStatus::Mismatch;

        $this->write($dealId, $status, $currentHash);

        if (! $result['match']) {
            $this->notifier->notifyProblem(
                $dealId,
                $deal,
                'Неверный товар в сделке.',
                array_map(static fn (array $difference): string => $difference['message'], $result['differences']),
            );
        }

        $this->logger->info('Bitrix product check completed', [
            'deal_id' => $dealId,
            'match' => $result['match'],
            'status' => $this->statusLabel($status),
            'message' => $this->message($result),
            'differences' => $this->publicDifferences($result['differences']),
        ]);

        return [
            'success' => true,
            'deal_id' => $dealId,
            'status' => 'checked',
            'match' => $result['match'],
            'check_status' => $this->statusLabel($status),
            'differences' => $this->publicDifferences($result['differences']),
        ];
    }

    private function extractKaspiText(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode("\n", array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array{
     *     match: bool,
     *     differences: array<int, array{field: string, kaspi: mixed, bitrix: mixed, message: string}>,
     *     pair_summaries: array<int, array{kaspi: string, bitrix: string}>
     * }  $result
     */
    private function message(array $result): string
    {
        if ($result['match']) {
            return 'Товар совпадает';
        }

        $kaspi = [];
        $bitrix = [];

        foreach ($result['pair_summaries'] as $pair) {
            $kaspi[] = $pair['kaspi'];
            $bitrix[] = $pair['bitrix'];
        }

        foreach ($result['differences'] as $difference) {
            if ($difference['field'] !== 'product') {
                continue;
            }

            if (is_string($difference['kaspi'])) {
                $kaspi[] = $difference['kaspi'];
            }

            if (is_string($difference['bitrix'])) {
                $bitrix[] = $difference['bitrix'];
            }
        }

        $lines = ['Kaspi:', ...($kaspi === [] ? ['—'] : $kaspi), '', 'Bitrix:', ...($bitrix === [] ? ['—'] : $bitrix), '', 'Ошибка:'];

        foreach ($result['differences'] as $difference) {
            $lines[] = $difference['message'];
        }

        return implode("\n", $lines);
    }

    private function write(int $dealId, ProductCheckStatus $status, string $productsHash): void
    {
        $fields = [];

        if ($this->settings->statusField !== '') {
            $fields[$this->settings->statusField] = $this->statusLabel($status);
        }

        $fields[$this->hashField()] = $productsHash;

        $this->bitrix->updateDeal($dealId, $fields);
    }

    private function hashField(): string
    {
        if ($this->settings->hashField === '') {
            throw new BitrixConfigurationException('Не настроено поле BITRIX_PRODUCTS_HASH_FIELD.');
        }

        return $this->settings->hashField;
    }

    /**
     * @return array{success: true, deal_id: int, status: string, reason: string}
     */
    private function skipped(int $dealId, string $reason): array
    {
        return [
            'success' => true,
            'deal_id' => $dealId,
            'status' => 'skipped',
            'reason' => $reason,
        ];
    }

    private function statusLabel(ProductCheckStatus $status): string
    {
        return match ($status) {
            ProductCheckStatus::Match => $this->settings->valueMatch,
            ProductCheckStatus::Mismatch => $this->settings->valueMismatch,
            ProductCheckStatus::NotAttached => $this->settings->valueNotAttached,
        };
    }

    /**
     * @param  array<int, array{field: string, kaspi: mixed, bitrix: mixed, message: string}>  $differences
     * @return array<int, array{field: string, kaspi: mixed, bitrix: mixed}>
     */
    private function publicDifferences(array $differences): array
    {
        return array_map(
            static fn (array $difference): array => [
                'field' => $difference['field'],
                'kaspi' => $difference['kaspi'],
                'bitrix' => $difference['bitrix'],
            ],
            $differences,
        );
    }
}

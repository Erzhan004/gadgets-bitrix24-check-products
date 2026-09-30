<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ProductData;

final readonly class ProductParserService
{
    /** @var array<string, string> */
    private array $colorAliases;

    /** @var array<string, string> */
    private array $brandAliases;

    /**
     * @param  array<string, array<int, string>>  $colors
     * @param  array<int|string, string|array<int, string>>  $brands
     */
    public function __construct(
        private array $colors,
        private array $brands,
    ) {
        $this->colorAliases = $this->compileColors($colors);
        $this->brandAliases = $this->compileBrands($brands);
    }

    /**
     * @return array<int, ProductData>
     */
    public function parseKaspiProducts(string $text): array
    {
        $text = $this->prepareKaspiText($text);

        if ($text === '') {
            return [];
        }

        $pattern = '/Номер товара\s+\d+\.\s*(.+?)(?=Номер товара\s+\d+\.|Итого\s*:|$)/uis';
        $found = preg_match_all($pattern, $text, $matches);

        if (is_int($found) && $found > 0) {
            $products = [];

            foreach ($matches[1] as $chunk) {
                $chunk = trim((string) $chunk);

                if ($chunk === '') {
                    continue;
                }

                $products[] = $this->parse($chunk);
            }

            if ($products !== []) {
                return $products;
            }
        }

        $text = preg_replace('/Итого\s*:.*/uis', '', $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return [$this->parse($text)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, ProductData>
     */
    public function parseBitrixProducts(array $rows): array
    {
        $products = [];

        foreach ($rows as $row) {
            $name = $row['PRODUCT_NAME'] ?? $row['ORIGINAL_PRODUCT_NAME'] ?? $row['NAME'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $products[] = $this->parse($name, $this->quantityFromRow($row['QUANTITY'] ?? null));
        }

        return $products;
    }

    public function parse(string $name, ?int $quantity = null): ProductData
    {
        $original = trim($name);
        $working = $original;
        $imei = $this->extractIdentifier($working);

        $textQuantity = null;

        if (preg_match('/(\d+)\s*шт\.?/u', $working, $quantityMatch) === 1) {
            $textQuantity = (int) $quantityMatch[1];
            $working = preg_replace('/,?\s*\d+\s*шт\.?/u', ' ', $working) ?? $working;
        }

        $working = preg_replace('/\d[\d\s\x{00A0}]*(?:[.,]\d+)?\s*(?:тенге|тг)\.?/u', ' ', $working) ?? $working;
        $working = preg_replace('/Итого\s*:.*/u', ' ', $working) ?? $working;
        $modelSource = $this->collapseSpaces(preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $working) ?? $working);
        $working = $this->collapseSpaces(str_replace(['(', ')', '[', ']'], ' ', $working));

        $specs = $this->pullSpecs($this->normalizeText($working));
        $modelNormalized = $this->pullSpecs($this->normalizeText($modelSource))['rest'];
        $model = $this->restoreCasing($modelSource, $modelNormalized);

        return new ProductData(
            brand: $specs['brand'],
            model: $model !== '' ? $model : null,
            ram: $specs['ram'],
            storage: $specs['storage'],
            color: $specs['color'],
            imei: $imei,
            originalName: $original,
            quantity: $quantity ?? $textQuantity,
            sim: $specs['sim'],
        );
    }

    /**
     * Характеристики ищутся по всему названию, включая скобки.
     * Модель берётся из названия без скобок, поэтому пропускается через те же шаги отдельно.
     *
     * @return array{ram: ?int, storage: ?int, color: ?string, sim: ?string, brand: ?string, rest: string}
     */
    private function pullSpecs(string $normalized): array
    {
        [$ram, $storage, $normalized] = $this->pullMemory($normalized);
        [$color, $normalized] = $this->pullColor($normalized);
        [$sim, $normalized] = $this->pullSim($normalized);
        [$brand, $rest] = $this->pullBrand($normalized);

        return [
            'ram' => $ram,
            'storage' => $storage,
            'color' => $color,
            'sim' => $sim,
            'brand' => $brand,
            'rest' => $rest,
        ];
    }

    private function collapseSpaces(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private function prepareKaspiText(string $text): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);

        return trim($text);
    }

    private function extractIdentifier(string &$working): ?string
    {
        $identifier = null;

        if (preg_match_all('/\(([^)]*)\)/u', $working, $groups, PREG_SET_ORDER) < 1) {
            return null;
        }

        foreach ($groups as $group) {
            $inner = trim($group[1]);

            if (! $this->isIdentifier($inner)) {
                continue;
            }

            $identifier ??= $inner;
            $working = str_replace($group[0], ' ', $working);
        }

        return $identifier;
    }

    private function isIdentifier(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) >= 8 && preg_match('/^[A-Za-z0-9\-]+$/', $value) === 1;
    }

    /**
     * Kaspi и Bitrix пишут память как RAM/storage. 1 TB = 1024 GB.
     *
     * @return array{0: ?int, 1: ?int, 2: string}
     */
    private function pullMemory(string $normalized): array
    {
        $pattern = '/(\d+)\s*(gb|tb)\s*\/\s*(\d+)\s*(gb|tb)/u';

        if (preg_match($pattern, $normalized, $matches) !== 1) {
            return [null, null, $normalized];
        }

        $ram = $this->toGigabytes((int) $matches[1], $matches[2]);
        $storage = $this->toGigabytes((int) $matches[3], $matches[4]);
        $normalized = preg_replace($pattern, ' ', $normalized, 1) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        return [$ram, $storage, $normalized];
    }

    private function toGigabytes(int $value, string $unit): int
    {
        return $unit === 'tb' ? $value * 1024 : $value;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function pullColor(string $normalized): array
    {
        foreach ($this->colorAliases as $alias => $canonical) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $normalized) !== 1) {
                continue;
            }

            $normalized = preg_replace($pattern, ' ', $normalized, 1) ?? $normalized;
            $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

            return [$canonical, $normalized];
        }

        return [null, $normalized];
    }

    /**
     * Порядок важен: «nano-SIM + eSIM» и «Dual eSIM» проверяются раньше, чем «SIM» и «eSIM» по отдельности.
     *
     * @return array{0: ?string, 1: string}
     */
    private function pullSim(string $normalized): array
    {
        $sim = '(?:sim|сим)';
        $esim = '(?:e[\s-]?'.$sim.'|е[\s-]?сим)';
        $variants = [
            'SIM+eSIM' => '(?:nano[\s-]?)?'.$sim.'\s*\+\s*'.$esim,
            'eSIM' => '(?:dual\s+)?'.$esim,
            'Dual SIM' => '(?:dual[\s-]?'.$sim.'|2[\s-]?'.$sim.')',
            'SIM' => '(?:single[\s-]?'.$sim.'|1[\s-]?'.$sim.'|nano[\s-]?'.$sim.')',
        ];

        foreach ($variants as $canonical => $body) {
            $pattern = '/(?<![\p{L}\p{N}])'.$body.'(?![\p{L}\p{N}])/u';

            if (preg_match($pattern, $normalized) !== 1) {
                continue;
            }

            $normalized = preg_replace($pattern, ' ', $normalized, 1) ?? $normalized;

            return [$canonical, $this->collapseSpaces($normalized)];
        }

        return [null, $normalized];
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function pullBrand(string $normalized): array
    {
        $tokens = $normalized === '' ? [] : explode(' ', $normalized);
        $brand = null;
        $modelTokens = [];

        foreach ($tokens as $token) {
            if ($brand === null && isset($this->brandAliases[$token])) {
                $brand = $this->brandAliases[$token];

                continue;
            }

            $modelTokens[] = $token;
        }

        return [$brand, trim(implode(' ', $modelTokens))];
    }

    private function restoreCasing(string $working, string $modelNormalized): string
    {
        if ($modelNormalized === '') {
            return '';
        }

        $wanted = explode(' ', $modelNormalized);
        $tokens = preg_split('/\s+/u', str_replace([',', ';'], ' ', $working)) ?: [];
        $picked = [];
        $index = 0;

        foreach ($tokens as $token) {
            if ($index >= count($wanted)) {
                break;
            }

            $key = $this->tokenKey($token);

            if ($key === '' || $key !== $wanted[$index]) {
                continue;
            }

            $picked[] = trim($token, ".,;:\"'");
            $index++;
        }

        if ($picked !== [] && $index === count($wanted)) {
            return implode(' ', $picked);
        }

        return $modelNormalized;
    }

    private function tokenKey(string $token): string
    {
        $key = $this->normalizeText($token);

        return str_replace(' ', '', $key);
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace('ё', 'е', $value);
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = str_replace(['гб', 'тб'], ['gb', 'tb'], $value);
        $value = str_replace(['／', '⁄'], '/', $value);
        $value = str_replace([',', ';', '.'], ' ', $value);
        $value = preg_replace('/\s*\/\s*/u', '/', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function quantityFromRow(mixed $quantity): ?int
    {
        if ($quantity === null || $quantity === '' || ! is_numeric($quantity)) {
            return null;
        }

        $value = (int) round((float) $quantity);

        return $value > 0 ? $value : null;
    }

    /**
     * @param  array<string, array<int, string>>  $colors
     * @return array<string, string>
     */
    private function compileColors(array $colors): array
    {
        $map = [];

        foreach ($colors as $canonical => $aliases) {
            if (! is_string($canonical)) {
                continue;
            }

            $map[$this->normalizeText($canonical)] = $canonical;

            foreach ($aliases as $alias) {
                $map[$this->normalizeText((string) $alias)] = $canonical;
            }
        }

        uksort(
            $map,
            static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left),
        );

        return $map;
    }

    /**
     * @param  array<int|string, string|array<int, string>>  $brands
     * @return array<string, string>
     */
    private function compileBrands(array $brands): array
    {
        $map = [];

        foreach ($brands as $key => $value) {
            if (is_int($key) && is_string($value)) {
                $map[$this->normalizeText($value)] = $value;

                continue;
            }

            if (! is_string($key)) {
                continue;
            }

            $map[$this->normalizeText($key)] = $key;

            foreach ((array) $value as $alias) {
                $map[$this->normalizeText((string) $alias)] = $key;
            }
        }

        uksort(
            $map,
            static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left),
        );

        return $map;
    }
}

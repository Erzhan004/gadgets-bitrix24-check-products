<?php

declare(strict_types=1);

namespace App\Services;

use App\DTO\ProductData;

final class ProductComparisonService
{
    /**
     * Слова, которые не меняют товар: 5G, Dual SIM и похожие пометки.
     * Ultra/Plus/Pro/Max/FE не входят сюда. SIM сравнивается отдельным полем.
     *
     * @var array<int, string>
     */
    private const NOISE_TOKENS = ['5g', '4g', 'lte', 'wifi', 'nfc', 'dual', 'sim', 'esim'];

    /**
     * Соответствие ищется по модели и характеристикам, а не по позиции в массиве.
     *
     * @param  array<int, ProductData>  $kaspiProducts
     * @param  array<int, ProductData>  $bitrixProducts
     * @return array{
     *     match: bool,
     *     differences: array<int, array{field: string, kaspi: mixed, bitrix: mixed, message: string}>,
     *     kaspi: array<int, array<string, mixed>>,
     *     bitrix: array<int, array<string, mixed>>,
     *     pair_summaries: array<int, array{kaspi: string, bitrix: string}>
     * }
     */
    public function compare(array $kaspiProducts, array $bitrixProducts): array
    {
        $assignment = $this->assign($kaspiProducts, $bitrixProducts);
        $prefixProduct = count($kaspiProducts) > 1 || count($bitrixProducts) > 1;
        $differences = [];

        foreach ($assignment['pairs'] as $pair) {
            array_push($differences, ...$this->comparePair($pair['kaspi'], $pair['bitrix'], $prefixProduct));
        }

        foreach ($assignment['unmatched_kaspi'] as $product) {
            $differences[] = [
                'field' => 'product',
                'kaspi' => $product->summary(),
                'bitrix' => null,
                'message' => 'Товар Kaspi не найден среди привязанных: '.$product->summary(),
            ];
        }

        foreach ($assignment['unmatched_bitrix'] as $product) {
            $differences[] = [
                'field' => 'product',
                'kaspi' => null,
                'bitrix' => $product->summary(),
                'message' => 'Лишний товар Bitrix: '.$product->summary(),
            ];
        }

        return [
            'match' => $differences === [],
            'differences' => $differences,
            'kaspi' => array_map(static fn (ProductData $product): array => $product->toArray(), $kaspiProducts),
            'bitrix' => array_map(static fn (ProductData $product): array => $product->toArray(), $bitrixProducts),
            'pair_summaries' => array_map(
                static fn (array $pair): array => [
                    'kaspi' => $pair['kaspi']->summary(),
                    'bitrix' => $pair['bitrix']->summary(),
                ],
                $assignment['pairs'],
            ),
        ];
    }

    /**
     * @param  array<int, ProductData>  $kaspiProducts
     * @param  array<int, ProductData>  $bitrixProducts
     * @return array{
     *     pairs: array<int, array{kaspi: ProductData, bitrix: ProductData}>,
     *     unmatched_kaspi: array<int, ProductData>,
     *     unmatched_bitrix: array<int, ProductData>
     * }
     */
    private function assign(array $kaspiProducts, array $bitrixProducts): array
    {
        $candidates = [];

        foreach ($kaspiProducts as $kaspiIndex => $kaspiProduct) {
            foreach ($bitrixProducts as $bitrixIndex => $bitrixProduct) {
                $candidates[] = [
                    'kaspi_index' => $kaspiIndex,
                    'bitrix_index' => $bitrixIndex,
                    'score' => $this->score($kaspiProduct, $bitrixProduct),
                ];
            }
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score'],
        );

        $usedKaspi = [];
        $usedBitrix = [];
        $pairs = [];

        foreach ($candidates as $candidate) {
            if (isset($usedKaspi[$candidate['kaspi_index']]) || isset($usedBitrix[$candidate['bitrix_index']])) {
                continue;
            }

            $usedKaspi[$candidate['kaspi_index']] = true;
            $usedBitrix[$candidate['bitrix_index']] = true;
            $pairs[] = [
                'kaspi' => $kaspiProducts[$candidate['kaspi_index']],
                'bitrix' => $bitrixProducts[$candidate['bitrix_index']],
            ];
        }

        $unmatchedKaspi = [];
        $unmatchedBitrix = [];

        foreach ($kaspiProducts as $index => $product) {
            if (! isset($usedKaspi[$index])) {
                $unmatchedKaspi[] = $product;
            }
        }

        foreach ($bitrixProducts as $index => $product) {
            if (! isset($usedBitrix[$index])) {
                $unmatchedBitrix[] = $product;
            }
        }

        return [
            'pairs' => $pairs,
            'unmatched_kaspi' => $unmatchedKaspi,
            'unmatched_bitrix' => $unmatchedBitrix,
        ];
    }

    private function score(ProductData $kaspi, ProductData $bitrix): float
    {
        $score = 0.0;
        $score += $this->specScore($this->normalizeBrand($kaspi->brand), $this->normalizeBrand($bitrix->brand), 20);
        $score += $this->specScore($kaspi->ram, $bitrix->ram, 35);
        $score += $this->specScore($kaspi->storage, $bitrix->storage, 35);
        $score += $this->specScore($kaspi->color, $bitrix->color, 20);
        $score += $this->specScore($kaspi->sim, $bitrix->sim, 10);
        $score += $this->modelsMatch($kaspi, $bitrix) ? 25 : 0;
        $score += $this->modelSimilarity($kaspi, $bitrix) * 0.25;

        return $score;
    }

    private function specScore(mixed $left, mixed $right, float $weight): float
    {
        $leftEmpty = $left === null || $left === '';
        $rightEmpty = $right === null || $right === '';

        if ($leftEmpty && $rightEmpty) {
            return $weight * 0.2;
        }

        if (! $leftEmpty && ! $rightEmpty && $left === $right) {
            return $weight;
        }

        if ($leftEmpty || $rightEmpty) {
            return 0.0;
        }

        return -$weight;
    }

    /**
     * @return array<int, array{field: string, kaspi: mixed, bitrix: mixed, message: string}>
     */
    private function comparePair(ProductData $kaspi, ProductData $bitrix, bool $prefixProduct): array
    {
        $differences = [];
        $prefix = $prefixProduct ? $kaspi->summary().': ' : '';

        if (! $this->brandsCompatible($kaspi->brand, $bitrix->brand)) {
            $differences[] = $this->difference(
                'brand',
                $kaspi->brand,
                $bitrix->brand,
                $prefix.'Бренд: Kaspi '.$this->display($kaspi->brand).' / Bitrix '.$this->display($bitrix->brand),
            );
        }

        if (! $this->modelsMatch($kaspi, $bitrix)) {
            $differences[] = $this->difference(
                'model',
                $kaspi->model,
                $bitrix->model,
                $prefix.'Модель: Kaspi '.$this->display($kaspi->model).' / Bitrix '.$this->display($bitrix->model),
            );
        }

        if (! $this->valuesMatch($kaspi->ram, $bitrix->ram)) {
            $differences[] = $this->difference(
                'ram',
                $kaspi->ram,
                $bitrix->ram,
                $prefix.'Оперативная память: Kaspi '.$this->formatGb($kaspi->ram).' / Bitrix '.$this->formatGb($bitrix->ram),
            );
        }

        if (! $this->valuesMatch($kaspi->storage, $bitrix->storage)) {
            $differences[] = $this->difference(
                'storage',
                $kaspi->storage,
                $bitrix->storage,
                $prefix.'Память: Kaspi '.$this->formatGb($kaspi->storage).' / Bitrix '.$this->formatGb($bitrix->storage),
            );
        }

        if (! $this->valuesMatch($kaspi->color, $bitrix->color)) {
            $differences[] = $this->difference(
                'color',
                $kaspi->color,
                $bitrix->color,
                $prefix.'Цвет: Kaspi '.$this->display($kaspi->color).' / Bitrix '.$this->display($bitrix->color),
            );
        }

        if (! $this->simCompatible($kaspi->sim, $bitrix->sim)) {
            $differences[] = $this->difference(
                'sim',
                $kaspi->sim,
                $bitrix->sim,
                $prefix.'SIM: Kaspi '.$this->display($kaspi->sim).' / Bitrix '.$this->display($bitrix->sim),
            );
        }

        if ($kaspi->quantity !== null && $bitrix->quantity !== null && $kaspi->quantity !== $bitrix->quantity) {
            $differences[] = $this->difference(
                'quantity',
                $kaspi->quantity,
                $bitrix->quantity,
                $prefix.'Количество: Kaspi '.$kaspi->quantity.' / Bitrix '.$bitrix->quantity,
            );
        }

        return $differences;
    }

    /**
     * @return array{field: string, kaspi: mixed, bitrix: mixed, message: string}
     */
    private function difference(string $field, mixed $kaspi, mixed $bitrix, string $message): array
    {
        return [
            'field' => $field,
            'kaspi' => $kaspi,
            'bitrix' => $bitrix,
            'message' => $message,
        ];
    }

    private function brandsCompatible(?string $left, ?string $right): bool
    {
        if ($left === null || $left === '' || $right === null || $right === '') {
            return true;
        }

        return $this->normalizeBrand($left) === $this->normalizeBrand($right);
    }

    private function valuesMatch(mixed $left, mixed $right): bool
    {
        return $left === $right;
    }

    /**
     * SIM указывают не во всех названиях, поэтому расхождение — только когда SIM есть с обеих сторон.
     */
    private function simCompatible(?string $left, ?string $right): bool
    {
        if ($left === null || $left === '' || $right === null || $right === '') {
            return true;
        }

        return $left === $right;
    }

    /**
     * Подстрока в обе стороны по нормализованной строке «бренд + модель»:
     * «Apple iPhone 17 Pro Max» и «iPhone 17 Pro Max (IMEI)» совпадают.
     * Память, RAM, цвет и SIM проверяются отдельно, их модель не перекрывает.
     */
    private function modelsMatch(ProductData $kaspi, ProductData $bitrix): bool
    {
        $kaspiHasModel = $this->normalizeModel($kaspi->model) !== '';
        $bitrixHasModel = $this->normalizeModel($bitrix->model) !== '';

        if (! $kaspiHasModel || ! $bitrixHasModel) {
            return $kaspiHasModel === $bitrixHasModel;
        }

        $kaspiKey = $this->modelKey($kaspi);
        $bitrixKey = $this->modelKey($bitrix);

        return str_contains($kaspiKey, $bitrixKey) || str_contains($bitrixKey, $kaspiKey);
    }

    private function modelSimilarity(ProductData $kaspi, ProductData $bitrix): float
    {
        $kaspiKey = $this->modelKey($kaspi);
        $bitrixKey = $this->modelKey($bitrix);

        if ($kaspiKey === '' && $bitrixKey === '') {
            return 100.0;
        }

        if ($kaspiKey === '' || $bitrixKey === '') {
            return 0.0;
        }

        similar_text($kaspiKey, $bitrixKey, $percent);

        return $percent;
    }

    private function modelKey(ProductData $product): string
    {
        return $this->normalizeModel(trim(($product->brand ?? '').' '.($product->model ?? '')));
    }

    /**
     * «Apple iPhone 17 Pro-Max (350145977598027)» → «appleiphone17promax».
     */
    private function normalizeModel(?string $model): string
    {
        $model = mb_strtolower((string) $model);
        $model = str_replace('ё', 'е', $model);
        $model = preg_replace('/\([^)]*\)/u', ' ', $model) ?? $model;
        $model = str_replace(['wi-fi', 'wi fi'], ' ', $model);
        $tokens = preg_split('/[^a-z0-9а-я]+/u', $model, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, self::NOISE_TOKENS, true),
        );

        return implode('', $tokens);
    }

    private function normalizeBrand(?string $brand): string
    {
        $brand = mb_strtolower(trim((string) $brand));

        return str_replace('ё', 'е', $brand);
    }

    private function formatGb(?int $value): string
    {
        return $value === null ? 'не указано' : $value.' GB';
    }

    private function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'не указано';
        }

        return (string) $value;
    }
}

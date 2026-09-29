<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Hash товарных позиций хранится в самой сделке и отличает изменение товаров
 * от любых других правок сделки. Цена и скидка намеренно не входят.
 */
final class ProductRowsHashService
{
    /**
     * @param  array<int, array<string, mixed>>  $products  строки crm.deal.productrows.get (UPPER_CASE) или crm.item.productrow.list (camelCase)
     */
    public function make(array $products): string
    {
        $json = json_encode(
            $this->normalize($products),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', $json);
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array{product_id: string, product_name: string, quantity: string}>
     */
    public function normalize(array $products): array
    {
        $rows = [];

        foreach ($products as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = [
                'product_id' => $this->productId($row['PRODUCT_ID'] ?? $row['productId'] ?? null),
                'product_name' => $this->productName($row['PRODUCT_NAME'] ?? $row['productName'] ?? null),
                'quantity' => $this->quantity($row['QUANTITY'] ?? $row['quantity'] ?? null),
            ];
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => [$left['product_id'], $left['product_name'], $left['quantity']]
                <=> [$right['product_id'], $right['product_name'], $right['quantity']],
        );

        return $rows;
    }

    private function productId(mixed $value): string
    {
        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function productName(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    /**
     * "1", 1, "1.0000" и 1.0 дают одно и то же значение.
     */
    private function quantity(mixed $value): string
    {
        if (! is_numeric($value)) {
            return is_scalar($value) ? trim((string) $value) : '';
        }

        $formatted = number_format((float) $value, 4, '.', '');

        return rtrim(rtrim($formatted, '0'), '.');
    }
}

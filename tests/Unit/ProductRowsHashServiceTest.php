<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductRowsHashService;
use PHPUnit\Framework\TestCase;

final class ProductRowsHashServiceTest extends TestCase
{
    private ProductRowsHashService $hasher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hasher = new ProductRowsHashService;
    }

    public function test_hash_is_sha256(): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->hasher->make($this->rows()));
    }

    public function test_row_order_does_not_change_hash(): void
    {
        $this->assertSame(
            $this->hasher->make($this->rows()),
            $this->hasher->make(array_reverse($this->rows())),
        );
    }

    public function test_product_id_change_changes_hash(): void
    {
        $changed = $this->rows();
        $changed[0]['PRODUCT_ID'] = '999';

        $this->assertNotSame($this->hasher->make($this->rows()), $this->hasher->make($changed));
    }

    public function test_product_name_change_changes_hash(): void
    {
        $changed = $this->rows();
        $changed[0]['PRODUCT_NAME'] = 'Samsung Galaxy S26 Ultra, 12 ГБ/512 GB, черный';

        $this->assertNotSame($this->hasher->make($this->rows()), $this->hasher->make($changed));
    }

    public function test_quantity_change_changes_hash(): void
    {
        $changed = $this->rows();
        $changed[0]['QUANTITY'] = '2';

        $this->assertNotSame($this->hasher->make($this->rows()), $this->hasher->make($changed));
    }

    public function test_price_and_discount_do_not_change_hash(): void
    {
        $changed = $this->rows();
        $changed[0]['PRICE'] = '1.00';
        $changed[0]['DISCOUNT_SUM'] = '500';
        $changed[0]['DISCOUNT_RATE'] = '10';
        $changed[0]['ID'] = '777';

        $this->assertSame($this->hasher->make($this->rows()), $this->hasher->make($changed));
    }

    public function test_equivalent_formats_give_same_hash(): void
    {
        $formatted = [
            [
                'PRODUCT_ID' => 12345,
                'PRODUCT_NAME' => '  Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный  ',
                'QUANTITY' => 1,
            ],
            [
                'PRODUCT_ID' => '555',
                'PRODUCT_NAME' => 'Apple AirPods Pro 2, белый',
                'QUANTITY' => '2.0000',
            ],
        ];

        $this->assertSame($this->hasher->make($this->rows()), $this->hasher->make($formatted));
    }

    public function test_camel_case_rows_are_supported(): void
    {
        $camel = [
            ['productId' => 12345, 'productName' => 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный', 'quantity' => 1],
            ['productId' => 555, 'productName' => 'Apple AirPods Pro 2, белый', 'quantity' => 2],
        ];

        $this->assertSame($this->hasher->make($this->rows()), $this->hasher->make($camel));
    }

    public function test_normalized_data_keeps_unicode_readable(): void
    {
        $normalized = $this->hasher->normalize([$this->rows()[0]]);

        $this->assertSame([[
            'product_id' => '12345',
            'product_name' => 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный',
            'quantity' => '1',
        ]], $normalized);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function rows(): array
    {
        return [
            [
                'ID' => '1',
                'PRODUCT_ID' => '12345',
                'PRODUCT_NAME' => 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный',
                'QUANTITY' => '1.0000',
                'PRICE' => '578855.00',
            ],
            [
                'ID' => '2',
                'PRODUCT_ID' => '555',
                'PRODUCT_NAME' => 'Apple AirPods Pro 2, белый',
                'QUANTITY' => '2',
                'PRICE' => '60000.00',
            ],
        ];
    }
}

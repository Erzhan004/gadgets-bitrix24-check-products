<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\ProductData;
use App\Services\ProductComparisonService;
use App\Services\ProductParserService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductComparisonServiceTest extends TestCase
{
    #[DataProvider('scenarios')]
    public function test_product_names(
        string $kaspi,
        string $bitrix,
        bool $match,
        ?string $field,
        mixed $kaspiValue,
        mixed $bitrixValue,
        ?string $message,
    ): void {
        $result = $this->compareNames($kaspi, $bitrix);

        $this->assertSame($match, $result['match']);

        if ($field === null) {
            $this->assertSame([], $result['differences']);

            return;
        }

        $this->assertSame($field, $result['differences'][0]['field']);
        $this->assertSame($kaspiValue, $result['differences'][0]['kaspi']);
        $this->assertSame($bitrixValue, $result['differences'][0]['bitrix']);
        $this->assertSame($message, $result['differences'][0]['message']);
    }

    public function test_different_imei_does_not_affect_match(): void
    {
        $result = $this->compareNames(
            'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный (111111111111111)',
            'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)',
        );

        $this->assertTrue($result['match']);
        $this->assertNotSame(
            $result['kaspi'][0]['imei'],
            $result['bitrix'][0]['imei'],
        );
    }

    public function test_generation_number_is_not_fuzzy(): void
    {
        $result = $this->compareNames(
            'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
            'Samsung Galaxy S25 Ultra 12 ГБ/256 ГБ черный',
        );

        $this->assertFalse($result['match']);
        $this->assertSame('model', $result['differences'][0]['field']);
    }

    public function test_5g_suffix_matches_when_specs_match(): void
    {
        $result = $this->compareNames(
            'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
            'Samsung Galaxy S26 Ultra 5G, 12 ГБ/256 GB, черный',
        );

        $this->assertTrue($result['match']);
    }

    public function test_fe_variant_does_not_match(): void
    {
        $result = $this->compareNames(
            'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
            'Samsung Galaxy S26 Ultra FE 12 ГБ/256 ГБ черный',
        );

        $this->assertFalse($result['match']);
        $this->assertSame('model', $result['differences'][0]['field']);
    }

    public function test_similarity_threshold_rejects_a_short_typo_when_set_to_100(): void
    {
        $kaspi = new ProductData('Samsung', 'Galaxy S26 Ultr', 12, 256, 'черный');
        $bitrix = new ProductData('Samsung', 'Galaxy S26 Ultra', 12, 256, 'черный');

        $strict = new ProductComparisonService(100);
        $normal = new ProductComparisonService(90);

        $this->assertFalse($strict->compare([$kaspi], [$bitrix])['match']);
        $this->assertTrue($normal->compare([$kaspi], [$bitrix])['match']);
    }

    public function test_products_match_by_best_characteristics_not_by_array_position(): void
    {
        $parser = self::parser();
        $comparison = new ProductComparisonService(90);

        $kaspi = $parser->parseKaspiProducts(<<<'TEXT'
Номер товара 1. Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный, 1 шт, 578855.00 тенге.
Номер товара 2. Apple AirPods Pro 2 белый, 2 шт, 120000 тенге.
Итого: 698855 тенге.
TEXT);
        $bitrix = $parser->parseBitrixProducts([
            [
                'PRODUCT_NAME' => 'Apple AirPods Pro 2, белый',
                'QUANTITY' => '2',
            ],
            [
                'PRODUCT_NAME' => 'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)',
                'QUANTITY' => '1',
            ],
        ]);

        $result = $comparison->compare($kaspi, $bitrix);

        $this->assertTrue($result['match']);
        $this->assertSame([], $result['differences']);
    }

    public function test_quantity_mismatch_is_reported(): void
    {
        $parser = self::parser();
        $comparison = new ProductComparisonService(90);

        $result = $comparison->compare(
            $parser->parseKaspiProducts('Номер товара 1. Apple AirPods Pro 2 белый, 2 шт, 120000 тенге.'),
            $parser->parseBitrixProducts([
                ['PRODUCT_NAME' => 'Apple AirPods Pro 2 белый', 'QUANTITY' => '1'],
            ]),
        );

        $this->assertFalse($result['match']);
        $this->assertSame('quantity', $result['differences'][0]['field']);
        $this->assertSame(2, $result['differences'][0]['kaspi']);
        $this->assertSame(1, $result['differences'][0]['bitrix']);
    }

    /**
     * @return array<string, mixed>
     */
    private function compareNames(string $kaspi, string $bitrix): array
    {
        $parser = self::parser();

        return (new ProductComparisonService(90))->compare(
            [$parser->parse($kaspi)],
            [$parser->parse($bitrix)],
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: ?string, 4: mixed, 5: mixed, 6: ?string}>
     */
    public static function scenarios(): array
    {
        return [
            'same phone in kaspi and bitrix formats' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                'Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)',
                true,
                null,
                null,
                null,
                null,
            ],
            'storage mismatch' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                'Samsung Galaxy S26 Ultra 12 ГБ/512 GB черный (350145977598027)',
                false,
                'storage',
                256,
                512,
                'Память: Kaspi 256 GB / Bitrix 512 GB',
            ],
            'color mismatch' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                'Samsung Galaxy S26 Ultra 12 ГБ/256 GB белый',
                false,
                'color',
                'черный',
                'белый',
                'Цвет: Kaspi черный / Bitrix белый',
            ],
            'terabyte equals 1024 gigabytes' => [
                'Samsung Galaxy S26 Ultra 12GB/1TB Black',
                'Samsung Galaxy S26 Ultra 12 ГБ/1024 ГБ черный',
                true,
                null,
                null,
                null,
                null,
            ],
            'extra commas and spaces' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                'Samsung   Galaxy S26 Ultra,  12 ГБ / 256 GB , черный',
                true,
                null,
                null,
                null,
                null,
            ],
            'yo and e are the same color' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ чёрный',
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                true,
                null,
                null,
                null,
                null,
            ],
            'black and черный are the same color' => [
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                'Samsung Galaxy S26 Ultra 12GB/256GB Black',
                true,
                null,
                null,
                null,
                null,
            ],
            'gb and ГБ do not matter' => [
                'Samsung Galaxy S26 Ultra 12GB/256GB черный',
                'Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный',
                true,
                null,
                null,
                null,
                null,
            ],
        ];
    }
}

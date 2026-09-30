<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductParserServiceTest extends TestCase
{
    public function test_parses_kaspi_phone_name(): void
    {
        $product = $this->parser()->parse('Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный');

        $this->assertSame('Samsung', $product->brand);
        $this->assertSame('Galaxy S26 Ultra', $product->model);
        $this->assertSame(12, $product->ram);
        $this->assertSame(256, $product->storage);
        $this->assertSame('черный', $product->color);
        $this->assertNull($product->imei);
    }

    public function test_parses_bitrix_name_and_ignores_imei_in_parentheses(): void
    {
        $product = $this->parser()->parse('Samsung Galaxy S26 Ultra, 12 ГБ/256 GB, черный (350145977598027)');

        $this->assertSame('Samsung', $product->brand);
        $this->assertSame('Galaxy S26 Ultra', $product->model);
        $this->assertSame(12, $product->ram);
        $this->assertSame(256, $product->storage);
        $this->assertSame('черный', $product->color);
        $this->assertSame('350145977598027', $product->imei);
    }

    #[DataProvider('memoryFormats')]
    public function test_memory_formats(string $name, int $ram, int $storage): void
    {
        $product = $this->parser()->parse($name);

        $this->assertSame($ram, $product->ram);
        $this->assertSame($storage, $product->storage);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function memoryFormats(): array
    {
        return [
            'cyrillic units' => ['Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный', 12, 256],
            'compact cyrillic' => ['Samsung Galaxy S26 Ultra 12ГБ/256ГБ черный', 12, 256],
            'spaced english' => ['Samsung Galaxy S26 Ultra 12 GB / 256 GB black', 12, 256],
            'compact english' => ['Samsung Galaxy S26 Ultra 12GB/256GB black', 12, 256],
            'terabyte' => ['Samsung Galaxy S26 Ultra 12 ГБ / 1 ТБ черный', 12, 1024],
            'compact terabyte' => ['Samsung Galaxy S26 Ultra 12GB/1TB Black', 12, 1024],
            'gigabyte equivalent of terabyte' => ['Samsung Galaxy S26 Ultra 12 ГБ/1024 ГБ черный', 12, 1024],
        ];
    }

    public function test_color_aliases_use_one_canonical_value(): void
    {
        $parser = $this->parser();

        $this->assertSame('черный', $parser->parse('Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ чёрный')->color);
        $this->assertSame('черный', $parser->parse('Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный')->color);
        $this->assertSame('черный', $parser->parse('Samsung Galaxy S26 Ultra 12GB/256GB Black')->color);
        $this->assertSame('серый', $parser->parse('Samsung Galaxy S26 Ultra 12GB/256GB Space Gray')->color);
    }

    public function test_extra_commas_and_spaces_do_not_change_parsed_fields(): void
    {
        $messy = $this->parser()->parse('Samsung   Galaxy S26 Ultra,  12 ГБ / 256 GB , черный');
        $clean = $this->parser()->parse('Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный');

        $this->assertSame($clean->brand, $messy->brand);
        $this->assertSame($clean->model, $messy->model);
        $this->assertSame($clean->ram, $messy->ram);
        $this->assertSame($clean->storage, $messy->storage);
        $this->assertSame($clean->color, $messy->color);
    }

    public function test_kaspi_order_text_parses_several_products(): void
    {
        $products = $this->parser()->parseKaspiProducts(<<<'TEXT'
Номер товара 1. Samsung Galaxy S26 Ultra 12 ГБ/256 ГБ черный, 1 шт, 578855.00 тенге.
Номер товара 2. Apple AirPods Pro 2 белый, 2 шт, 120000 тенге.
Итого: 698855 тенге.
TEXT);

        $this->assertCount(2, $products);
        $this->assertSame('Galaxy S26 Ultra', $products[0]->model);
        $this->assertSame(1, $products[0]->quantity);
        $this->assertSame('Apple', $products[1]->brand);
        $this->assertSame('AirPods Pro 2', $products[1]->model);
        $this->assertSame('белый', $products[1]->color);
        $this->assertSame(2, $products[1]->quantity);
        $this->assertNull($products[1]->ram);
        $this->assertNull($products[1]->storage);
    }

    public function test_bitrix_rows_use_product_name_and_quantity(): void
    {
        $products = $this->parser()->parseBitrixProducts([
            [
                'PRODUCT_NAME' => 'Apple AirPods Pro 2, белый',
                'QUANTITY' => '2.0000',
            ],
        ]);

        $this->assertCount(1, $products);
        $this->assertSame('AirPods Pro 2', $products[0]->model);
        $this->assertSame(2, $products[0]->quantity);
    }

    #[DataProvider('simFormats')]
    public function test_sim_is_parsed_into_its_own_field(string $name, ?string $sim): void
    {
        $product = $this->parser()->parse($name);

        $this->assertSame($sim, $product->sim);
        $this->assertSame('iPhone 17 Pro Max', $product->model);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function simFormats(): array
    {
        return [
            'esim only' => ['Apple iPhone 17 Pro Max 256 ГБ/12 ГБ черный eSIM', 'eSIM'],
            'dual esim' => ['Apple iPhone 17 Pro Max Dual eSIM 12 ГБ/256 ГБ', 'eSIM'],
            'nano sim plus esim' => ['Apple iPhone 17 Pro Max 12 ГБ/256 ГБ nano-SIM + eSIM', 'SIM+eSIM'],
            'dual sim' => ['Apple iPhone 17 Pro Max Dual SIM 12 ГБ/256 ГБ', 'Dual SIM'],
            'two sim' => ['Apple iPhone 17 Pro Max 12 ГБ/256 ГБ 2 SIM', 'Dual SIM'],
            'no sim' => ['Apple iPhone 17 Pro Max 12 ГБ/256 ГБ черный', null],
        ];
    }

    public function test_model_drops_everything_in_parentheses(): void
    {
        $product = $this->parser()->parse('Apple iPhone 17 Pro Max (A3257) 12 ГБ/256 ГБ (черный)');

        $this->assertSame('iPhone 17 Pro Max', $product->model);
        $this->assertSame('черный', $product->color);
        $this->assertSame(256, $product->storage);
    }

    public function test_color_alias_does_not_match_inside_brand(): void
    {
        $product = $this->parser()->parse('Redmi Note 13 8 ГБ/256 ГБ синий');

        $this->assertSame('Redmi', $product->brand);
        $this->assertSame('Note 13', $product->model);
        $this->assertSame('синий', $product->color);
    }
}

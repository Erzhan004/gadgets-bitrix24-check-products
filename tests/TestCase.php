<?php

declare(strict_types=1);

namespace Tests;

use App\Services\ProductParserService;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected static function parser(): ProductParserService
    {
        /** @var array{colors: array<string, array<int, string>>, brands: array<int, string>} $products */
        $products = require dirname(__DIR__).'/config/products.php';

        return new ProductParserService($products['colors'], $products['brands']);
    }
}

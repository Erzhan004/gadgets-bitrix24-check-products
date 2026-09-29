<?php

declare(strict_types=1);

use App\Application;
use App\Http\Request;
use App\Support\Settings;
use Dotenv\Dotenv;

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

Dotenv::createImmutable($basePath)->safeLoad();

$settings = Settings::fromEnv($_ENV, $basePath);

Application::kernel($settings, $basePath)
    ->handle(Request::fromGlobals())
    ->send();

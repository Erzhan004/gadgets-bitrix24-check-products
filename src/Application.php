<?php

declare(strict_types=1);

namespace App;

use App\Http\Kernel;
use App\Services\Bitrix24Service;
use App\Services\DealCheckNotifier;
use App\Services\DealProductCheckService;
use App\Services\ProductComparisonService;
use App\Services\ProductParserService;
use App\Services\ProductRowsHashService;
use App\Support\DealLock;
use App\Support\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

final class Application
{
    /**
     * @param  callable|null  $httpHandler  подменяется в тестах (Guzzle MockHandler)
     */
    public static function kernel(
        Settings $settings,
        string $basePath,
        ?LoggerInterface $logger = null,
        ?callable $httpHandler = null,
    ): Kernel {
        $logger ??= self::logger($settings);

        $bitrix = new Bitrix24Service(
            self::httpClient($settings, $httpHandler),
            $logger,
            $settings->webhookUrl,
            $settings->httpTimeout,
        );

        /** @var array{colors: array<string, array<int, string>>, brands: array<int, string>} $products */
        $products = require $basePath.'/config/products.php';
        $parser = new ProductParserService($products['colors'], $products['brands']);
        $hasher = new ProductRowsHashService;

        $notifier = new DealCheckNotifier(
            $bitrix,
            $logger,
            $settings->notifyEnabled,
            $settings->notifyTimeline,
            $settings->notifyAssigned,
            $settings->notifyUserIds,
            $settings->emoji,
        );

        $service = new DealProductCheckService(
            $bitrix,
            $parser,
            new ProductComparisonService($settings->modelSimilarityThreshold),
            $notifier,
            $hasher,
            $settings,
            $logger,
        );

        return new Kernel($settings, $service, $bitrix, $parser, $hasher, new DealLock($settings->lockDir), $logger);
    }

    public static function logger(Settings $settings): LoggerInterface
    {
        $handler = new StreamHandler($settings->logPath, Level::fromName(ucfirst(strtolower($settings->logLevel))));
        $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true, true));

        return new Logger('app', [$handler]);
    }

    private static function httpClient(Settings $settings, ?callable $handler): Client
    {
        $stack = HandlerStack::create($handler);
        $retries = $settings->httpRetryTimes;
        $sleepMs = $settings->httpRetrySleepMs;

        $stack->push(Middleware::retry(
            static function (int $attempt, RequestInterface $request, ?ResponseInterface $response, mixed $reason) use ($retries): bool {
                if ($attempt + 1 >= $retries) {
                    return false;
                }

                if ($reason instanceof ConnectException) {
                    return true;
                }

                return $response !== null && $response->getStatusCode() >= 500;
            },
            static fn (): int => $sleepMs,
        ));

        return new Client(['handler' => $stack]);
    }
}

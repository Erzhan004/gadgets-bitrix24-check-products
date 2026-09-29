<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Settings
{
    /**
     * @param  array<int, int>  $notifyUserIds
     * @param  array<int, string>  $events
     */
    public function __construct(
        public string $appEnv,
        public string $webhookUrl,
        public string $callbackToken,
        public string $eventApplicationToken,
        public string $kaspiField,
        public string $hashField,
        public string $statusField,
        public string $valueMatch,
        public string $valueMismatch,
        public string $valueNotAttached,
        public bool $notifyEnabled,
        public bool $notifyTimeline,
        public bool $notifyAssigned,
        public array $notifyUserIds,
        public bool $emoji,
        public int $modelSimilarityThreshold,
        public int $httpTimeout,
        public int $httpRetryTimes,
        public int $httpRetrySleepMs,
        public string $logPath,
        public string $logLevel,
        public string $lockDir,
        public array $events = ['ONCRMDEALUPDATE', 'ONCRMDEALADD'],
    ) {}

    /**
     * @param  array<string, mixed>  $env
     */
    public static function fromEnv(array $env, string $basePath): self
    {
        $get = static function (string $key, string $default = '') use ($env): string {
            $value = $env[$key] ?? null;

            return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
        };

        $bool = static fn (string $key, bool $default): bool => filter_var(
            $get($key, $default ? 'true' : 'false'),
            FILTER_VALIDATE_BOOLEAN,
        );

        $userIds = array_values(array_filter(
            array_map('intval', explode(',', $get('BITRIX_NOTIFY_USER_IDS'))),
            static fn (int $id): bool => $id > 0,
        ));

        return new self(
            appEnv: $get('APP_ENV', 'production'),
            webhookUrl: $get('BITRIX_WEBHOOK_URL'),
            callbackToken: $get('BITRIX_CALLBACK_TOKEN'),
            eventApplicationToken: $get('BITRIX_EVENT_APPLICATION_TOKEN'),
            kaspiField: $get('BITRIX_KASPI_PRODUCT_FIELD'),
            hashField: $get('BITRIX_PRODUCTS_HASH_FIELD', 'UF_CRM_PRODUCTS_HASH'),
            statusField: $get('BITRIX_PRODUCT_CHECK_STATUS_FIELD', $get('BITRIX_PRODUCT_CHECK_FIELD')),
            valueMatch: $get('BITRIX_VALUE_MATCH', 'OK'),
            valueMismatch: $get('BITRIX_VALUE_MISMATCH', 'ERROR'),
            valueNotAttached: $get('BITRIX_VALUE_NOT_ATTACHED', 'ERROR'),
            notifyEnabled: $bool('BITRIX_NOTIFY_ENABLED', true),
            notifyTimeline: $bool('BITRIX_NOTIFY_TIMELINE', true),
            notifyAssigned: $bool('BITRIX_NOTIFY_ASSIGNED', true),
            notifyUserIds: $userIds,
            emoji: $bool('BITRIX_COMMENT_EMOJI', true),
            modelSimilarityThreshold: (int) $get('BITRIX_MODEL_SIMILARITY_THRESHOLD', '90'),
            httpTimeout: max(1, (int) $get('BITRIX_HTTP_TIMEOUT', '15')),
            httpRetryTimes: min(5, max(1, (int) $get('BITRIX_HTTP_RETRY_TIMES', '3'))),
            httpRetrySleepMs: max(0, (int) $get('BITRIX_HTTP_RETRY_SLEEP_MS', '500')),
            logPath: $get('LOG_PATH', $basePath.'/storage/logs/app.log'),
            logLevel: $get('LOG_LEVEL', 'info'),
            lockDir: $get('LOCK_DIR', $basePath.'/storage/locks'),
        );
    }

    public function isLocal(): bool
    {
        return $this->appEnv === 'local';
    }
}

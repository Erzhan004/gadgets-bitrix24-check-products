<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Не даёт двум одновременным событиям проверять одну сделку.
 * Держится на flock(): ОС снимает блокировку сама, даже если процесс упал.
 */
final class DealLock
{
    public function __construct(
        private readonly string $directory,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return array{acquired: bool, result: T|null}
     */
    public function run(int $dealId, callable $callback): array
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $handle = @fopen($this->directory.'/deal-'.$dealId.'.lock', 'c');

        if ($handle === false) {
            return ['acquired' => true, 'result' => $callback()];
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return ['acquired' => false, 'result' => null];
        }

        try {
            return ['acquired' => true, 'result' => $callback()];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

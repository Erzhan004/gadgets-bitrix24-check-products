<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class BitrixApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 502,
    ) {
        parent::__construct($message);
    }
}

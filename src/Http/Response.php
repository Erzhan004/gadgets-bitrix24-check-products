<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Response
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $status,
        public array $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, $data);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

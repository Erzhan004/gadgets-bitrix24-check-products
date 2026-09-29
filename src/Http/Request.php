<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Request
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers  ключи в нижнем регистре
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $body = $_POST;

        if (str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return new self(
            method: strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            path: '/'.trim(is_string($path) ? $path : '/', '/'),
            query: $_GET,
            body: $body,
            headers: $headers,
        );
    }

    /**
     * Значение из тела или query-строки. Ключ через точку: data.FIELDS.ID.
     */
    public function input(string $key): mixed
    {
        $value = $this->dig($this->body, $key);

        return $value ?? $this->dig($this->query, $key);
    }

    public function query(string $key): mixed
    {
        return $this->dig($this->query, $key);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';

        return str_starts_with($header, 'Bearer ') ? substr($header, 7) : null;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function dig(array $source, string $key): mixed
    {
        $value = $source;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

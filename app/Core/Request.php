<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    private array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,mixed> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $cookies,
        private string $body = ''
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = '/' . trim($path, '/');

        $post = $_POST;
        $body = '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $body = (string) file_get_contents('php://input');
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $post = $decoded + $post;
            }
        }

        // Method override lets HTML forms send PUT/DELETE.
        if ($method === 'POST' && isset($post['_method'])) {
            $override = strtoupper((string) $post['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $override;
            }
        }

        return new self($method, $path, $_GET, $post, $_SERVER, $_COOKIE, $body);
    }

    /** @param array<string,string> $params */
    public function withParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, ?string $default = null): ?string
    {
        return $this->params[$key] ?? $default;
    }

    public function intParam(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function raw(string $key): mixed
    {
        return $this->post[$key] ?? null;
    }

    public function boolean(string $key): bool
    {
        $value = $this->post[$key] ?? null;

        return in_array(is_scalar($value) ? strtolower((string) $value) : '', ['1', 'true', 'on', 'yes'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->post[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /** @return array<int,string> */
    public function arrayInput(string $key): array
    {
        $value = $this->post[$key] ?? [];

        return is_array($value) ? array_values(array_map(static fn ($v): string => (string) $v, $value)) : [];
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->post;
    }

    public function cookie(string $key, ?string $default = null): ?string
    {
        $value = $this->cookies[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** The raw request body, as posted. Only populated for JSON requests. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * How much the client says it is sending. Read before the body is used, so
     * an endpoint can refuse something enormous rather than parse it.
     */
    public function contentLength(): int
    {
        return (int) ($this->server['CONTENT_LENGTH'] ?? 0);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && $this->server['HTTPS'] !== 'off') {
            return true;
        }

        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? 'localhost');
    }

    public function baseUrl(): string
    {
        return ($this->isSecure() ? 'https://' : 'http://') . $this->host();
    }

    public function wantsJson(): bool
    {
        $accept = (string) ($this->server['HTTP_ACCEPT'] ?? '');

        return str_contains($accept, 'application/json')
            || $this->header('X-Requested-With') === 'fetch'
            || str_starts_with($this->path, '/api/');
    }
}

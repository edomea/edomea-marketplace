<?php

declare(strict_types=1);

namespace Emp\Core\Http;

/**
 * Richiesta HTTP ridotta a ciò che serve al Core.
 */
final class Request
{
    /**
     * @param array<string, string> $headers nomi in minuscolo
     * @param array<string, string> $params parametri del percorso, valorizzati dal Router
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers = [],
        public readonly string $remoteAddr = '',
        public readonly string $body = '',
        public readonly array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $body = (string) file_get_contents('php://input', false, null, 0, 65536);

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            is_string($path) ? $path : '/',
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $body,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function param(string $name): string
    {
        return $this->params[$name] ?? '';
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->headers, $this->remoteAddr, $this->body, $params);
    }
}

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
        private readonly string $inputUri = 'php://input',
    ) {
    }

    /**
     * Corpo completo della richiesta come flusso, per i caricamenti di file
     * ($body contiene al massimo i primi 64 KiB, sufficienti per il JSON).
     *
     * @return resource
     */
    public function openBody()
    {
        $in = @fopen($this->inputUri, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Corpo della richiesta non leggibile');
        }

        return $in;
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
        unset($headers['x-emp-https']);
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $headers['x-emp-https'] = '1';
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

    /** Corpo JSON come array (vuoto se assente o non valido). */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** Campo testuale del corpo JSON ('' se assente o non testuale). */
    public function input(string $name): string
    {
        $v = $this->json()[$name] ?? '';

        return is_string($v) ? $v : '';
    }

    public function cookie(string $name): ?string
    {
        foreach (explode(';', (string) $this->header('cookie')) as $pair) {
            [$k, $v] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($k === $name) {
                return $v;
            }
        }

        return null;
    }

    /** True se la richiesta è arrivata in HTTPS (direttamente o tramite Nginx). */
    public function isSecure(): bool
    {
        return ($this->headers['x-emp-https'] ?? '') === '1';
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->headers, $this->remoteAddr, $this->body, $params, $this->inputUri);
    }
}

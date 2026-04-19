<?php

declare(strict_types=1);

namespace Core\Http;

/**
 * Request — abstracción tipada de la petición HTTP actual.
 *
 * Singleton ligero: Request::capture() siempre devuelve la misma instancia
 * dentro del ciclo de vida de la petición.
 *
 * Uso en controladores (vía $this->request()):
 *   $email = $this->request()->str('email');
 *   $page  = $this->request()->int('page', 1);
 *
 * Uso estático:
 *   $req = Request::capture();
 */
final class Request
{
    private static ?self $instance = null;

    private function __construct(
        private readonly array $get,
        private readonly array $post,
        private readonly array $files,
        private readonly array $server,
        private readonly array $cookies,
    ) {}

    /** Devuelve el singleton de la petición actual. */
    public static function capture(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(
                $_GET    ?? [],
                $_POST   ?? [],
                $_FILES  ?? [],
                $_SERVER ?? [],
                $_COOKIE ?? [],
            );
        }

        return self::$instance;
    }

    // ── Lectura de input ──────────────────────────────────────────────────

    /** POST tiene precedencia sobre GET. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->get[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /** Datos de archivo subido. Null si el campo no existe. */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /** Extrae solo las claves indicadas del input combinado. */
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }
        return $result;
    }

    /** Todos los inputs (POST ∪ GET, POST tiene precedencia). */
    public function all(): array
    {
        return array_merge($this->get, $this->post);
    }

    /** true si la clave existe (puede ser vacía). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->post) || array_key_exists($key, $this->get);
    }

    /** true si la clave existe y no es cadena vacía. */
    public function filled(string $key): bool
    {
        $value = $this->input($key);
        return $value !== null && trim((string) $value) !== '';
    }

    // ── Tipado de valores ─────────────────────────────────────────────────

    /** Input trimmeado a string. */
    public function str(string $key, string $default = ''): string
    {
        return trim((string) $this->input($key, $default));
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    /** Acepta '1', 'true', 'on', 'yes' como true. */
    public function bool(string $key, bool $default = false): bool
    {
        $val = $this->input($key);
        if ($val === null) {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    // ── Metadatos de la petición ──────────────────────────────────────────

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function uri(): string
    {
        return parse_url($this->server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->server[$key])) {
                return trim(explode(',', $this->server[$key])[0]);
            }
        }
        return '0.0.0.0';
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($this->server[$key] ?? $default);
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('X-Requested-With')) === 'xmlhttprequest';
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    public function isSecure(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off')
            || ($this->server['SERVER_PORT'] ?? '') === '443';
    }
}
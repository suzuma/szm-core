<?php

declare(strict_types=1);

namespace Core\Cache;

/**
 * NullCache — implementación sin almacenamiento.
 *
 * Útil para:
 *   - Tests unitarios (no contamina APCu ni disco entre pruebas)
 *   - Entornos que explícitamente no quieren caché
 *
 * get() siempre devuelve $default, set() siempre retorna true.
 */
final class NullCache implements CacheInterface
{
    public function get(string $key, mixed $default = null): mixed  { return $default; }
    public function set(string $key, mixed $value, int $ttl = 0): bool { return true; }
    public function has(string $key): bool                          { return false; }
    public function delete(string $key): bool                       { return true; }
    public function increment(string $key, int $by = 1, int $ttl = 0): int|false { return $by; }
    public function flush(): bool                                   { return true; }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $callback();
    }
}
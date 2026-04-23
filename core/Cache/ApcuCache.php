<?php

declare(strict_types=1);

namespace Core\Cache;

/**
 * ApcuCache — implementación de CacheInterface usando APCu.
 *
 * APCu almacena datos en la memoria compartida del proceso PHP-FPM,
 * lo que lo hace extremadamente rápido (sin I/O de disco).
 * Los datos no persisten entre reinicios del servidor.
 *
 * Requiere: extensión `apcu` habilitada en php.ini
 *           `apc.enable_cli=1` en php.ini para usar desde CLI (tests).
 */
final class ApcuCache implements CacheInterface
{
    public function __construct(
        private readonly string $prefix = 'szm:',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = apcu_fetch($this->k($key), $found);
        return $found ? $value : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return apcu_store($this->k($key), $value, $ttl);
    }

    public function has(string $key): bool
    {
        return apcu_exists($this->k($key));
    }

    public function delete(string $key): bool
    {
        return apcu_delete($this->k($key));
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int|false
    {
        $k = $this->k($key);

        // Crea con TTL solo si no existe (preserva la ventana original)
        apcu_add($k, 0, $ttl);

        return apcu_inc($k, $by);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $k = $this->k($key);
        $value = apcu_fetch($k, $found);

        if ($found) {
            return $value;
        }

        $value = $callback();
        apcu_store($k, $value, $ttl);
        return $value;
    }

    public function flush(): bool
    {
        return apcu_clear_cache();
    }

    private function k(string $key): string
    {
        return $this->prefix . $key;
    }
}
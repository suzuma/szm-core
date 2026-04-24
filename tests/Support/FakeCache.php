<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Cache\CacheInterface;

/**
 * FakeCache — implementación en memoria de CacheInterface para tests.
 *
 * No usa APCu ni sistema de archivos. Todas las operaciones son síncronas
 * y deterministas, lo que hace los tests rápidos y predecibles.
 *
 * El TTL no se hace cumplir (los valores no expiran durante el test),
 * lo que simplifica la verificación de contadores y lógica de límite.
 */
final class FakeCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int|false
    {
        $current           = (int) ($this->store[$key] ?? 0);
        $this->store[$key] = $current + $by;
        return $this->store[$key];
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        if (!$this->has($key)) {
            $this->set($key, $callback(), $ttl);
        }
        return $this->get($key);
    }

    public function flush(): bool
    {
        $this->store = [];
        return true;
    }

    /** Utilidad de test — verifica si la clave existe en el store interno. */
    public function spy(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }
}
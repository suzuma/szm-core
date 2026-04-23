<?php

declare(strict_types=1);

namespace Core\Cache;

use Core\ServicesContainer;

/**
 * Cache — fachada estática para el sistema de caché.
 *
 * Delega todas las operaciones al driver registrado en el contenedor
 * (CacheInterface::class). Registrado automáticamente en providers.php.
 *
 * Uso:
 *   Cache::set('user:1', $user, ttl: 300);
 *   $user = Cache::get('user:1');
 *   $stats = Cache::remember('stats', 60, fn() => Stats::compute());
 *   Cache::delete('user:1');
 *   Cache::flush();
 */
final class Cache
{
    private function __construct() {}

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::driver()->get($key, $default);
    }

    public static function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return self::driver()->set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::driver()->has($key);
    }

    public static function delete(string $key): bool
    {
        return self::driver()->delete($key);
    }

    public static function increment(string $key, int $by = 1, int $ttl = 0): int|false
    {
        return self::driver()->increment($key, $by, $ttl);
    }

    /**
     * Obtiene de caché o ejecuta $callback, almacena y retorna.
     *
     * Ejemplo:
     *   $posts = Cache::remember('posts.featured', 300, fn() => Post::featured()->get());
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        return self::driver()->remember($key, $ttl, $callback);
    }

    public static function flush(): bool
    {
        return self::driver()->flush();
    }

    private static function driver(): CacheInterface
    {
        return ServicesContainer::get(CacheInterface::class);
    }
}
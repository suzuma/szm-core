<?php

declare(strict_types=1);

namespace Core\Cache;

/**
 * CacheInterface — contrato para el sistema de caché del framework.
 *
 * Implementaciones incluidas:
 *   - ApcuCache  → usa APCu (memoria compartida, mejor rendimiento)
 *   - FileCache  → usa sistema de archivos (sin dependencias de extensión)
 *   - NullCache  → sin almacenamiento (para tests o entornos que no requieren caché)
 *
 * Registro en providers.php:
 *   ServicesContainer::bind(CacheInterface::class, fn() => new ApcuCache());
 *
 * Uso vía fachada:
 *   Cache::get('stats');
 *   Cache::set('stats', $data, ttl: 300);
 *   Cache::remember('stats', 300, fn() => Stats::compute());
 */
interface CacheInterface
{
    /**
     * Obtiene un valor. Retorna $default si la clave no existe o expiró.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Guarda un valor.
     *
     * @param int $ttl  Segundos de vida. 0 = sin expiración.
     */
    public function set(string $key, mixed $value, int $ttl = 0): bool;

    /** Verifica si la clave existe y no ha expirado. */
    public function has(string $key): bool;

    /** Elimina una clave. */
    public function delete(string $key): bool;

    /**
     * Incrementa un contador numérico.
     *
     * Si la clave no existe, la crea con valor $by y TTL $ttl.
     * Si existe, la incrementa preservando el TTL original.
     *
     * @return int|false  Nuevo valor o false si falló.
     */
    public function increment(string $key, int $by = 1, int $ttl = 0): int|false;

    /**
     * Obtiene el valor si existe; si no, ejecuta $callback, lo almacena y lo retorna.
     *
     * @param int      $ttl       Segundos de vida del valor cacheado.
     * @param callable $callback  Función que produce el valor si no está en caché.
     */
    public function remember(string $key, int $ttl, callable $callback): mixed;

    /** Elimina todas las claves del caché. */
    public function flush(): bool;
}
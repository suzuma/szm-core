<?php
declare(strict_types=1);

namespace Core;

use Core\Database\DbContext;
use Twig\Environment;

final class ServicesContainer
{
    // ── Estado interno ────────────────────────────────────────────────
    private static array $config   = [];
    private static array $services = [];
    private static array $bindings = []; // closures registradas
    private static bool  $dbReady  = false;

    private function __construct() {}

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }
    /**
     * Obtiene toda la configuración o un valor por dot-notation.
     *
     * Ejemplos:
     *   getConfig()                → array completo
     *   getConfig('app')           → sub-array 'app'
     *   getConfig('app.debug')     → bool
     *   getConfig('database.host') → string
     *   getConfig('no.existe', 'default') → 'default'
     */
    public static function getConfig(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return self::$config;
        }

        // Dot-notation: 'database.host' → $config['database']['host']
        if (str_contains($key, '.')) {
            return self::resolveDotKey($key, $default);
        }

        return self::$config[$key] ?? $default;
    }


    /* ==================================================================
     | REGISTRO Y RESOLUCIÓN DE SERVICIOS
     ================================================================== */

    /**
     * Registra un servicio como closure (lazy — se ejecuta solo al pedirlo).
     *
     * Uso:
     *   ServicesContainer::bind('mailer', fn() => new Mailer($config));
     */
    public static function bind(string $id, \Closure $factory): void
    {
        self::$bindings[$id] = $factory;
        // Si ya estaba resuelto, lo invalidamos
        unset(self::$services[$id]);
    }

    /**
     * Registra una instancia ya construida (singleton manual).
     *
     * Uso:
     *   ServicesContainer::instance('cache', $cacheObject);
     */
    public static function instance(string $id, object $service): void
    {
        self::$services[$id] = $service;
    }

    /**
     * Resuelve un servicio por id.
     * Si tiene binding, lo instancia y cachea (singleton automático).
     *
     * @throws \RuntimeException si el servicio no está registrado
     */
    public static function get(string $id): mixed
    {
        // Ya resuelto → devuelve cacheado
        if (isset(self::$services[$id])) {
            return self::$services[$id];
        }

        // Tiene binding → resuelve y cachea
        if (isset(self::$bindings[$id])) {
            self::$services[$id] = (self::$bindings[$id])();
            return self::$services[$id];
        }

        throw new \RuntimeException("Servicio no registrado en el contenedor: [{$id}]");
    }

    /** Comprueba si un servicio está registrado (binding o instancia) */
    public static function has(string $id): bool
    {
        return isset(self::$services[$id]) || isset(self::$bindings[$id]);
    }

    /** Elimina un servicio del contenedor (útil en tests) */
    public static function forget(string $id): void
    {
        unset(self::$services[$id], self::$bindings[$id]);
    }

    /* ==================================================================
     | ACCESORES TIPADOS — los servicios más frecuentes
     ================================================================== */

    /**
     * Devuelve la instancia de Twig\Environment (lazy, singleton).
     * La construcción está delegada a TwigFactory.
     */
    public static function twig(): Environment
    {
        if (!isset(self::$services['twig'])) {
            self::$services['twig'] = TwigFactory::create(
                self::getConfig('app.environment', 'prod'),
                self::getConfig('logging.path', '')
            );
        }

        return self::$services['twig'];
    }

    /**
     * Inicializa el contexto de base de datos una sola vez.
     * Idempotente — llamadas repetidas son seguras.
     */
    public static function initializeDbContext(): void
    {
        if (self::$dbReady) {
            return;
        }

        DbContext::initialize();
        self::$dbReady = true;
    }

    /* ==================================================================
     | DIAGNÓSTICO (solo en entorno dev)
     ================================================================== */

    /**
     * Lista los ids de todos los servicios registrados.
     * Útil para debug — no exponer en producción.
     *
     * @return string[]
     */
    public static function registered(): array
    {
        return array_unique([
            ...array_keys(self::$services),
            ...array_keys(self::$bindings),
        ]);
    }

    /* ==================================================================
     | HELPERS PRIVADOS
     ================================================================== */

    /**
     * Resuelve una clave dot-notation sobre self::$config.
     * 'database.options.PDO::ATTR_ERRMODE' → anidado N niveles.
     */
    private static function resolveDotKey(string $key, mixed $default): mixed
    {
        $segments = explode('.', $key);
        $value    = self::$config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
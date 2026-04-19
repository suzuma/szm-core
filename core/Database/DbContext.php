<?php
declare(strict_types=1);
namespace Core\Database;

use Core\ServicesContainer;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
/**
 * DbContext
 *
 * Inicializa Illuminate\Database (Eloquent + Query Builder)
 * fuera de Laravel usando Capsule Manager.
 *
 * Uso desde los Repositories:
 *   // Eloquent ORM
 *   Socio::find(1);
 *   Socio::where('activo', true)->get();
 *
 *   // Query Builder
 *   DbContext::table('socios')->where('activo', 1)->get();
 *
 *   // Transacciones
 *   DbContext::transaction(function () { ... });
 */
class DbContext
{
    private static bool $initialized = false;
    private static Capsule $capsule;

    // Clase utilitaria — no se instancia
    private function __construct() {}

    /* ------------------------------------------------------------------
 | INICIALIZACIÓN — llamada una sola vez desde ServicesContainer
 ------------------------------------------------------------------ */

    /**
     * Conecta Eloquent con la configuración del contenedor.
     * Idempotente — llamadas repetidas son seguras.
     *
     * @throws \RuntimeException si faltan credenciales de BD
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        $config = ServicesContainer::getConfig('database');

        if (empty($config)) {
            throw new \RuntimeException('Configuración de base de datos no encontrada.');
        }

        self::$capsule = new Capsule();

        // ── Conexión principal ────────────────────────────────────────
        // El array de config_.php ya usa las claves exactas de Illuminate:
        // driver, host, port, database, username, password, charset, collation
        self::$capsule->addConnection([
            'driver'    => $config['driver']    ?? 'mysql',
            'host'      => $config['host']      ?? '127.0.0.1',
            'port'      => $config['port']      ?? 3306,
            'database'  => $config['database']  ?? 'siap',
            'username'  => $config['username']  ?? 'root',
            'password'  => $config['password']  ?? 'mysql',
            'charset'   => $config['charset']   ?? 'utf8mb4',
            'collation' => $config['collation'] ?? 'utf8mb4_unicode_ci',
            'prefix'    => $config['prefix']    ?? '',
            'strict'    => $config['strict']    ?? true,
            'engine'    => $config['engine']    ?? 'InnoDB',
        ]);

        // ── Event Dispatcher (necesario para Eloquent observers/events) ─
        self::$capsule->setEventDispatcher(
            new Dispatcher(new Container())
        );

        // ── Activa Eloquent globalmente ───────────────────────────────
        self::$capsule->setAsGlobal();
        self::$capsule->bootEloquent();

        // ── Configura el paginador para entorno standalone ────────────
        \Illuminate\Pagination\Paginator::currentPageResolver(
            function (string $pageName): int {
                return max(1, (int) ($_GET[$pageName] ?? 1));
            }
        );
        \Illuminate\Pagination\Paginator::currentPathResolver(
            function (): string {
                $uri = $_SERVER['REQUEST_URI'] ?? '/';
                $pos = strpos($uri, '?');
                return $pos !== false ? substr($uri, 0, $pos) : $uri;
            }
        );

        // ── Log de queries en entorno dev ─────────────────────────────
        if (ServicesContainer::getConfig('app.environment', 'prod') === 'dev') {
            self::enableQueryLog();
        }

        self::$initialized = true;
    }

    /* ------------------------------------------------------------------
 | ACCESOS ESTÁTICOS — shortcuts para los Repositories
 ------------------------------------------------------------------ */

    /**
     * Query Builder sobre una tabla.
     *
     * Ejemplo:
     *   DbContext::table('socios')->where('activo', 1)->get();
     */
    public static function table(string $table): \Illuminate\Database\Query\Builder
    {
        return self::capsule()->getConnection()->table($table);
    }

    /**
     * Acceso directo a la conexión PDO subyacente.
     * Útil para queries raw o stored procedures.
     *
     * Ejemplo:
     *   DbContext::connection()->select('CALL sp_reporte(?)', [$id]);
     */
    public static function connection(string $name = 'default'): \Illuminate\Database\Connection
    {
        return self::capsule()->getConnection($name);
    }

    /**
     * Ejecuta un bloque dentro de una transacción.
     * Hace rollback automático si se lanza cualquier excepción.
     *
     * Ejemplo:
     *   DbContext::transaction(function () use ($data) {
     *       Socio::create($data);
     *       Afiliacion::create([...]);
     *   });
     */
    public static function transaction(\Closure $callback): mixed
    {
        return self::connection()->transaction($callback);
    }

    /**
     * Ejecuta SQL raw con bindings opcionales.
     *
     * Ejemplo:
     *   DbContext::raw('SELECT * FROM socios WHERE id = ?', [1]);
     */
    public static function raw(string $sql, array $bindings = []): array
    {
        return self::connection()->select($sql, $bindings);
    }

    /**
     * Retorna todas las queries ejecutadas (solo disponible en dev).
     *
     * @return array<int, array{query: string, bindings: array, time: float}>
     */
    public static function getQueryLog(): array
    {
        return self::connection()->getQueryLog();
    }

    /**
     * Imprime el log de queries formateado (solo usar en dev/debug).
     */
    public static function dumpQueryLog(): void
    {
        $queries = self::getQueryLog();

        foreach ($queries as $i => $q) {
            $bindings = implode(', ', array_map(
                static fn($b) => is_string($b) ? "'{$b}'" : (string) $b,
                $q['bindings']
            ));

            printf(
                "[Query #%d] (%.2f ms)\n  SQL: %s\n  Bindings: [%s]\n\n",
                $i + 1,
                $q['time'],
                $q['query'],
                $bindings
            );
        }
    }

    /* ------------------------------------------------------------------
     | HELPERS PRIVADOS
     ------------------------------------------------------------------ */

    /**
     * Retorna la instancia de Capsule, garantizando que esté inicializada.
     */
    private static function capsule(): Capsule
    {
        if (!self::$initialized) {
            throw new \RuntimeException(
                'DbContext no ha sido inicializado. Llama a DbContext::initialize() primero.'
            );
        }

        return self::$capsule;
    }

    /**
     * Activa el log de queries en la conexión activa.
     */
    private static function enableQueryLog(): void
    {
        self::$capsule->getConnection()->enableQueryLog();
    }

}
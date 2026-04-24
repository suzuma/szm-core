<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Cache\Cache;
use Core\Cache\CacheInterface;
use Core\Database\DbContext;
use Core\Http\Response;
use Core\ServicesContainer;
use Core\Storage\StorageInterface;
use Core\Storage\Storage;

/**
 * HealthController — endpoint de diagnóstico del sistema.
 *
 * GET /health → JSON con estado de DB, caché y storage.
 * HTTP 200 si todo está ok, 503 si algún componente falla.
 *
 * Diseñado para ser consumido por load balancers y sistemas de monitoreo
 * sin requerir autenticación. No expone datos sensibles.
 */
class HealthController extends BaseController
{
    public function check(): never
    {
        $checks  = [];
        $overall = 'ok';

        // ── Base de datos ─────────────────────────────────────────────────
        try {
            $start = microtime(true);
            DbContext::raw('SELECT 1');
            $checks['db'] = [
                'status'     => 'ok',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Throwable) {
            $checks['db'] = ['status' => 'error', 'message' => 'No disponible'];
            $overall      = 'error';
        }

        // ── Caché ─────────────────────────────────────────────────────────
        try {
            $probe  = '_health_' . bin2hex(random_bytes(4));
            Cache::set($probe, 1, 5);
            $hit = Cache::get($probe) === 1;
            Cache::delete($probe);

            $driver         = $this->shortClass(ServicesContainer::get(CacheInterface::class));
            $checks['cache'] = ['status' => $hit ? 'ok' : 'error', 'driver' => $driver];

            if (!$hit) {
                $overall = 'error';
            }
        } catch (\Throwable) {
            $checks['cache'] = ['status' => 'error', 'message' => 'No disponible'];
            $overall         = 'error';
        }

        // ── Storage ───────────────────────────────────────────────────────
        try {
            $driver          = $this->shortClass(ServicesContainer::get(StorageInterface::class));
            $storagePath     = Storage::path('');
            $writable        = is_dir($storagePath) && is_writable($storagePath);
            $checks['storage'] = ['status' => $writable ? 'ok' : 'error', 'driver' => $driver];

            if (!$writable) {
                $overall = 'error';
            }
        } catch (\Throwable) {
            $checks['storage'] = ['status' => 'error', 'message' => 'No disponible'];
            $overall           = 'error';
        }

        Response::json([
            'status'    => $overall,
            'checks'    => $checks,
            'timestamp' => date('c'),
        ], $overall === 'ok' ? 200 : 503)->send();
    }

    /** Retorna solo el nombre corto de la clase (sin namespace). */
    private function shortClass(object $obj): string
    {
        $parts = explode('\\', $obj::class);
        return end($parts);
    }
}
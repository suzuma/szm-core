<?php

declare(strict_types=1);

namespace Core\Security\Waf\Identity;

/**
 * IpResolver — fuente de verdad única para resolver la IP real del cliente.
 *
 * Centraliza la lógica que antes existía duplicada en:
 *   - IpManagementTrait::resolveIp()   (WAF)
 *   - Application::resolveClientIp()   (Bootstrap / access log)
 *
 * Estrategia:
 *   - REMOTE_ADDR es la fuente primaria. En conexión directa nunca puede
 *     ser falsificada por el cliente.
 *   - X-Forwarded-For / X-Real-IP solo se leen si REMOTE_ADDR pertenece
 *     a un proxy de confianza. Un atacante externo no puede inyectar su
 *     propia IP "limpia" a través de estos headers.
 *   - Toda IP candidata se valida con FILTER_VALIDATE_IP antes de usarse,
 *     previniendo valores malformados en logs y en la base de datos.
 */
final class IpResolver
{
    /**
     * Proxies de confianza: REMOTE_ADDR debe pertenecer a esta lista
     * para que el WAF lea headers X-Forwarded-For / X-Real-IP.
     * Ampliar si hay balanceadores o proxies internos propios.
     *
     * @var list<string>
     */
    private static array $trustedProxies = [
        '127.0.0.1',
        '::1',
    ];

    // Clase estática — no se instancia
    private function __construct() {}

    /**
     * Resuelve la IP real del cliente de forma segura.
     *
     * @param array<string, string> $server  $_SERVER (inyectable para tests)
     */
    public static function resolve(array $server = []): string
    {
        if (empty($server)) {
            $server = $_SERVER;
        }

        $remoteAddr = $server['REMOTE_ADDR'] ?? '0.0.0.0';

        // Si REMOTE_ADDR no es un proxy de confianza, es la IP real del cliente.
        // No leemos headers adicionales — podrían estar falsificados por el atacante.
        if (!in_array($remoteAddr, self::$trustedProxies, true)) {
            return $remoteAddr;
        }

        // Solo llegamos aquí si la conexión viene de un proxy interno de confianza.
        // Intentamos obtener la IP del cliente original desde headers estándar.
        $candidates = [
            $server['HTTP_CF_CONNECTING_IP'] ?? null, // Cloudflare
            $server['HTTP_X_REAL_IP']        ?? null, // Nginx proxy
            $server['HTTP_X_FORWARDED_FOR']  ?? null, // RFC 7239 estándar
        ];

        foreach ($candidates as $candidate) {
            if (empty($candidate)) {
                continue;
            }

            // X-Forwarded-For puede ser "client, proxy1, proxy2"
            // El cliente real es siempre el primero de la lista (RFC 7239)
            $ip = trim(explode(',', $candidate)[0]);

            // Solo aceptamos IPs públicas válidas — descartamos privadas y reservadas
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Fallback: si los headers no contienen IP pública válida, usamos REMOTE_ADDR
        return $remoteAddr;
    }
}
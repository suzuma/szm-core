<?php

declare(strict_types=1);

namespace Tests\Waf\Identity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Core\Security\Waf\Identity\IpResolver;

/**
 * IpResolverTest — verifica que IpResolver resuelve la IP real del cliente
 * de forma segura, respetando proxies de confianza y validando IPs.
 *
 * IpResolver::resolve() acepta un array $server para tests sin $_SERVER real.
 */
final class IpResolverTest extends TestCase
{
    // ── CONEXIÓN DIRECTA (sin proxy) ──────────────────────────────────────────

    #[Test]
    public function returnsRemoteAddrOnDirectConnection(): void
    {
        $result = IpResolver::resolve(['REMOTE_ADDR' => '203.0.113.5']);
        self::assertSame('203.0.113.5', $result);
    }

    #[Test]
    public function ignoresForwardedForWhenRemoteAddrIsNotTrusted(): void
    {
        // Un atacante externo envía X-Forwarded-For con IP "limpia" → se ignora
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '203.0.113.99',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        self::assertSame('203.0.113.99', $result);
    }

    #[Test]
    public function returnsFallbackIpWhenNoRemoteAddr(): void
    {
        $result = IpResolver::resolve([]);
        self::assertSame('0.0.0.0', $result);
    }

    // ── PROXY DE CONFIANZA (127.0.0.1 / ::1) ─────────────────────────────────

    #[Test]
    public function usesXForwardedForFromTrustedProxy(): void
    {
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '85.12.34.56',
        ]);
        self::assertSame('85.12.34.56', $result);
    }

    #[Test]
    public function usesFirstIpFromMultiValueXForwardedFor(): void
    {
        // "cliente, proxy1, proxy2" → cliente es el primero (RFC 7239)
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '91.10.20.30, 10.0.0.1, 10.0.0.2',
        ]);
        self::assertSame('91.10.20.30', $result);
    }

    #[Test]
    public function usesXRealIpFromTrustedProxy(): void
    {
        $result = IpResolver::resolve([
            'REMOTE_ADDR'       => '127.0.0.1',
            'HTTP_X_REAL_IP'    => '77.88.99.100',
        ]);
        self::assertSame('77.88.99.100', $result);
    }

    #[Test]
    public function usesCloudflareHeaderFromTrustedProxy(): void
    {
        $result = IpResolver::resolve([
            'REMOTE_ADDR'              => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP'    => '45.67.89.12',
        ]);
        self::assertSame('45.67.89.12', $result);
    }

    #[Test]
    public function prioritizesCfConnectingIpOverXForwardedFor(): void
    {
        // CF-Connecting-IP es el primero en la lista de candidatos
        $result = IpResolver::resolve([
            'REMOTE_ADDR'              => '127.0.0.1',
            'HTTP_CF_CONNECTING_IP'    => '111.22.33.44',
            'HTTP_X_FORWARDED_FOR'     => '222.33.44.55',
        ]);
        self::assertSame('111.22.33.44', $result);
    }

    #[Test]
    public function usesIpv6TrustedProxy(): void
    {
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '::1',
            'HTTP_X_FORWARDED_FOR' => '8.8.8.8',
        ]);
        self::assertSame('8.8.8.8', $result);
    }

    // ── VALIDACIÓN DE IP ──────────────────────────────────────────────────────

    #[Test]
    public function fallsBackToRemoteAddrWhenForwardedIpIsPrivate(): void
    {
        // XFF contiene IP privada → no se acepta (FILTER_FLAG_NO_PRIV_RANGE)
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '192.168.1.1',
        ]);
        self::assertSame('127.0.0.1', $result);
    }

    #[Test]
    public function fallsBackToRemoteAddrWhenForwardedIpIsReserved(): void
    {
        // XFF contiene IP reservada (loopback) → no se acepta
        $result = IpResolver::resolve([
            'REMOTE_ADDR'          => '127.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.2',
        ]);
        self::assertSame('127.0.0.1', $result);
    }
}
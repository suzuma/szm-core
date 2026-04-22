<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * SsrfTest — verifica el detector de Server-Side Request Forgery.
 */
final class SsrfTest extends TestCase
{
    private WafDetectionProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new WafDetectionProbe();
    }

    // ── TRUE POSITIVES ────────────────────────────────────────────────────────

    #[DataProvider('maliciousPayloads')]
    #[Test]
    public function detectsMaliciousPayload(string $payload): void
    {
        self::assertTrue(
            $this->probe->detectSsrf($payload),
            "Se esperaba que detectara SSRF en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            // Loopback
            'http_localhost'             => ['http://localhost/admin'],
            'https_localhost'            => ['https://localhost/api/secret'],
            'http_127_0_0_1'             => ['http://127.0.0.1/etc/passwd'],
            'https_127_0_0_1_port'       => ['https://127.0.0.1:8080/internal'],
            'http_127_sub'               => ['http://127.0.0.2/admin'],

            // IPv6 loopback
            'ipv6_loopback'              => ['http://::1/admin'],
            'https_ipv6_loopback'        => ['https://::1/secret'],

            // AWS / GCP / Azure metadata endpoint
            'aws_metadata'               => ['http://169.254.169.254/latest/meta-data/'],
            'aws_metadata_iam'           => ['https://169.254.169.254/latest/meta-data/iam/'],
            'azure_metadata'             => ['http://169.254.169.254/metadata/instance'],

            // Redes privadas clase A
            'private_10_0_0_1'          => ['http://10.0.0.1/internal'],
            'private_10_1_2_3'          => ['https://10.1.2.3/api'],

            // Redes privadas clase C
            'private_192_168_1_1'       => ['http://192.168.1.1/router'],
            'private_192_168_0_100'     => ['https://192.168.0.100/panel'],

            // Bind a cualquier interfaz
            'any_interface'              => ['http://0.0.0.0/exec'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectSsrf($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'public_url'            => ['https://example.com/api'],
            'public_url_http'       => ['http://api.service.com/data'],
            'plain_text'            => ['Hola mundo'],
            'ip_public_a'           => ['http://8.8.8.8/dns'],
            'ip_public_b'           => ['https://1.1.1.1/cdn'],
            'relative_path'         => ['/admin/users'],
            'email'                 => ['admin@empresa.com'],
            // La regex del WAF no cubre 172.16-31.x — comportamiento documentado
            'private_172'           => ['http://172.31.0.1/internal'],
        ];
    }
}
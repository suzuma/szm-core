<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * OpenRedirectTest — verifica el detector de Open Redirect.
 *
 * El detector lee $_ENV['APP_URL'] para excluir el dominio propio.
 * Lo fijamos en setUp() para tener entorno determinista.
 */
final class OpenRedirectTest extends TestCase
{
    private WafDetectionProbe $probe;

    protected function setUp(): void
    {
        $_ENV['APP_URL'] = 'https://miapp.com';
        $this->probe     = new WafDetectionProbe();
    }

    protected function tearDown(): void
    {
        unset($_ENV['APP_URL']);
    }

    // ── TRUE POSITIVES ────────────────────────────────────────────────────────

    #[DataProvider('maliciousPayloads')]
    #[Test]
    public function detectsMaliciousPayload(string $payload): void
    {
        self::assertTrue(
            $this->probe->detectOpenRedirect($payload),
            "Se esperaba que detectara Open Redirect en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            // URLs absolutas en parámetros de redirección
            'redirect_https'             => ['?redirect=https://evil.com'],
            'redirect_http'              => ['?redirect=http://phishing.org'],
            'next_https'                 => ['?next=https://attacker.net'],
            'url_param_https'            => ['?url=https://malware.io'],
            'return_param'               => ['?return=http://evil.com'],

            // Protocol-relative URLs (//)
            'protocol_relative_redirect' => ['?redirect=//evil.com'],
            'protocol_relative_next'     => ['?next=//phishing.org'],
            'assign_protocol_relative'   => ['=//attacker.net'],

            // URL al inicio del valor
            'absolute_at_start'          => ['https://evil.com/login'],

            // Espacios como separador (cubren el patrón \s en el regex)
            'space_before_url'           => [' https://evil.com'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectOpenRedirect($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            // Rutas relativas — nunca son un redirect externo
            'relative_path'              => ['/dashboard'],
            'relative_nested'            => ['/admin/users/edit/5'],
            'anchor'                     => ['#section'],

            // Dominio propio excluido
            'own_domain_https'           => ['https://miapp.com/profile'],
            'own_domain_http'            => ['http://miapp.com/login'],
            'own_domain_www'             => ['https://www.miapp.com/page'],

            // Texto sin URL
            'plain_text'                 => ['Redirigir al inicio'],
            'email'                      => ['user@miapp.com'],

            // Parámetro con ruta relativa
            'redirect_relative'          => ['?redirect=/inicio'],
        ];
    }
}
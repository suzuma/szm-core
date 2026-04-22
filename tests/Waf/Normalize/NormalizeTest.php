<?php

declare(strict_types=1);

namespace Tests\Waf\Normalize;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * NormalizeTest — verifica que normalize() decodifica y limpia
 * todas las técnicas de evasión antes de aplicar reglas de detección.
 */
final class NormalizeTest extends TestCase
{
    private WafDetectionProbe $probe;

    protected function setUp(): void
    {
        $this->probe = new WafDetectionProbe();
    }

    // ── URL ENCODING ──────────────────────────────────────────────────────────

    #[Test]
    public function decodesSimpleUrlEncoding(): void
    {
        // %3C → <   %3E → >
        self::assertSame('<script>', $this->probe->runNormalize('%3Cscript%3E'));
    }

    #[Test]
    public function decodesDoubleUrlEncoding(): void
    {
        // %253C → %3C → <
        self::assertSame('<script>', $this->probe->runNormalize('%253Cscript%253E'));
    }

    #[Test]
    public function decodesTripleUrlEncoding(): void
    {
        // %25253C → %253C → %3C → <
        self::assertSame('<script>', $this->probe->runNormalize('%25253Cscript%25253E'));
    }

    // ── HTML ENTITIES ─────────────────────────────────────────────────────────

    #[Test]
    public function decodesHtmlNamedEntities(): void
    {
        self::assertSame('<script>', $this->probe->runNormalize('&lt;script&gt;'));
    }

    #[Test]
    public function decodesHtmlDecimalEntities(): void
    {
        // &#60; → <   &#62; → >
        self::assertSame('<b>', $this->probe->runNormalize('&#60;b&#62;'));
    }

    #[Test]
    public function decodesHtmlHexEntities(): void
    {
        // &#x3C; → <
        self::assertSame('<b>', $this->probe->runNormalize('&#x3C;b&#x3E;'));
    }

    // ── UNICODE ESCAPING ──────────────────────────────────────────────────────

    #[Test]
    public function decodesJsUnicodeEscapes(): void
    {
        // \u003c → <   \u003e → >
        self::assertSame('<script>', $this->probe->runNormalize('\u003cscript\u003e'));
    }

    #[Test]
    public function decodesIisUnicodeEncoding(): void
    {
        // %u0053 → S   %u0045 → E
        self::assertSame('SELECT', $this->probe->runNormalize('%u0053%u0045LECT'));
    }

    // ── CONTROL CHARS & NULL BYTES ────────────────────────────────────────────

    #[Test]
    public function removesControlCharacters(): void
    {
        // \x01 y \x1F son caracteres de control eliminados
        self::assertSame('helloworld', $this->probe->runNormalize("hello\x01world"));
        self::assertSame('helloworld', $this->probe->runNormalize("hello\x1Fworld"));
    }

    #[Test]
    public function removesNullBytes(): void
    {
        self::assertSame('hello', $this->probe->runNormalize("hel\x00lo"));
        self::assertSame('hello', $this->probe->runNormalize('hel%00lo'));
    }

    // ── WHITESPACE NORMALIZATION ──────────────────────────────────────────────

    #[Test]
    public function normalizesTabsToSpace(): void
    {
        self::assertSame('UNION SELECT', $this->probe->runNormalize("UNION\tSELECT"));
    }

    #[Test]
    public function normalizesNewlinesToSpace(): void
    {
        self::assertSame('UNION SELECT', $this->probe->runNormalize("UNION\nSELECT"));
    }

    #[Test]
    public function collapsesMultipleSpaces(): void
    {
        self::assertSame('hello world', $this->probe->runNormalize('hello   world'));
    }

    // ── SQL INLINE COMMENTS ───────────────────────────────────────────────────

    #[Test]
    public function removesSqlInlineComments(): void
    {
        // El comentario entre TOKENS se reemplaza con espacio, no se elimina silenciosamente.
        // Esto evita fusionar los tokens adyacentes: UNION/**/SELECT → UNION SELECT
        self::assertSame('UNION SELECT', $this->probe->runNormalize('UNION/**/SELECT'));
    }

    #[Test]
    public function removesMultilineSqlComments(): void
    {
        self::assertSame('UNION SELECT', $this->probe->runNormalize('UNION/* bypass */SELECT'));
    }

    // ── ARRAY INPUT ───────────────────────────────────────────────────────────

    #[Test]
    public function convertsArrayToJson(): void
    {
        $result = $this->probe->runNormalize(['key' => 'value']);
        self::assertStringContainsString('key', $result);
        self::assertStringContainsString('value', $result);
    }

    // ── TRUNCATION ────────────────────────────────────────────────────────────

    #[Test]
    public function truncatesInputAt10KiloBytes(): void
    {
        $input  = str_repeat('a', 10_241);
        $result = $this->probe->runNormalize($input);
        self::assertSame(10_240, strlen($result));
    }

    // ── COMBINED EVASION ─────────────────────────────────────────────────────

    #[Test]
    public function handlesUrlEncodedPathTraversal(): void
    {
        // ..%2f..%2fetc/passwd → ../../etc/passwd
        $result = $this->probe->runNormalize('..%2f..%2fetc/passwd');
        self::assertStringContainsString('../', $result);
    }

    #[Test]
    public function doesNotAlterCleanInput(): void
    {
        self::assertSame('Juan Pérez', $this->probe->runNormalize('Juan Pérez'));
        self::assertSame('user@example.com', $this->probe->runNormalize('user@example.com'));
    }
}
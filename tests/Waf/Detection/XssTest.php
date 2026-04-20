<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * XssTest — verifica el detector de Cross-Site Scripting.
 */
final class XssTest extends TestCase
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
            $this->probe->detectXss($payload),
            "Se esperaba que detectara XSS en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            // Etiquetas script
            'script_basic'              => ['<script>alert(1)</script>'],
            'script_with_space'         => ['<script >alert(1)'],
            'script_close'              => ['</script>'],

            // Protocolos
            'javascript_protocol'       => ['javascript:alert(1)'],
            'javascript_with_space'     => ['javascript :alert(1)'],
            'vbscript_protocol'         => ['vbscript:MsgBox(1)'],

            // Event handlers
            'onerror'                   => ['<img src=x onerror=alert(1)>'],
            'onload'                    => ['<body onload=alert(1)>'],
            'onclick'                   => ['<div onclick=alert(1)>'],
            'onmouseover'               => ['<a onmouseover=alert(1)>'],

            // Funciones JS
            'alert'                     => ['alert(1)'],
            'confirm'                   => ['confirm(1)'],
            'prompt'                    => ['prompt(1)'],
            'eval'                      => ['eval(atob("YWxlcnQoMSk="))'],
            'setTimeout'                => ['setTimeout(function(){},0)'],
            'setInterval'               => ['setInterval(x,100)'],

            // DOM manipulation
            'document_cookie'           => ['document.cookie'],
            'document_write'            => ['document.write("<img src=x>")'],
            'document_location'         => ['document.location="http://evil.com"'],
            'innerHTML'                 => ['el.innerHTML="<b>x</b>"'],
            'outerHTML'                 => ['el.outerHTML="<script>x</script>"'],
            'insertAdjacentHTML'        => ['el.insertAdjacentHTML("beforeend","<img>")'],

            // Técnicas avanzadas
            'fromCharCode'              => ['String.fromCharCode(60,115,99)'],
            'window_location'           => ['window.location="//evil.com"'],
            'svg_with_event'            => ['<svg onload=alert(1)>'],
            'iframe'                    => ['<iframe src="javascript:alert(1)">'],
            'object_tag'                => ['<object data="javascript:alert(1)">'],
            'embed_tag'                 => ['<embed src="javascript:alert(1)">'],
            'base_href'                 => ['<base href="http://evil.com">'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectXss($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'plain_text'            => ['Hola mundo'],
            'email'                 => ['user@example.com'],
            'html_escaped'          => ['&lt;script&gt;alert(1)&lt;/script&gt;'],
            'url_normal'            => ['https://example.com/page'],
            'code_in_text'          => ['Use the alert() in your console'],
            'comment_text'          => ['This is a comment about JavaScript'],
            'doc_reference'         => ['See the documentation for details'],
        ];
    }
}
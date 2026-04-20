<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * PathTraversalTest — verifica el detector de Directory/Path Traversal.
 */
final class PathTraversalTest extends TestCase
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
            $this->probe->detectPathTraversal($payload),
            "Se esperaba que detectara Path Traversal en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            'unix_double_dot_slash'    => ['../../etc/passwd'],
            'unix_triple_dot'          => ['../../../etc/shadow'],
            'unix_deep'                => ['../../../../var/www/config.php'],
            'windows_double_dot'       => ['..\\..\\Windows\\system32'],
            'windows_mixed'            => ['..\\..\\..\\etc\\passwd'],
            'mixed_slash_backslash'    => ['..\\../etc/passwd'],
            'file_prefix'              => ['file:///../../etc/passwd'],
            'param_traversal'          => ['?file=../../config.php'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectPathTraversal($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'absolute_unix'   => ['/var/www/html/index.php'],
            'relative_child'  => ['images/photo.jpg'],
            'filename'        => ['report.pdf'],
            'url_path'        => ['/admin/users/edit/5'],
            'dot_file'        => ['.env'],
            'single_dot'      => ['./index.php'],       // ./ no es ../
        ];
    }
}
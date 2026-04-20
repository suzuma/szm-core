<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * SqlInjectionTest — verifica que el detector de SQLi bloquea lo que debe
 * y deja pasar entradas legítimas.
 *
 * Estructura de cada test:
 *   TRUE POSITIVES  — payloads maliciosos → detectSql() === true
 *   TRUE NEGATIVES  — entradas válidas    → detectSql() === false
 */
final class SqlInjectionTest extends TestCase
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
            $this->probe->detectSql($payload),
            "Se esperaba que detectara SQLi en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            // Clásicos UNION SELECT
            'union_select_basic'          => ["' UNION SELECT 1,2,3--"],
            'union_all_select'            => ["1 UNION ALL SELECT null,null,null--"],
            'union_select_comment'        => ["1 UNION/**/SELECT user()"],

            // Lógicos
            'or_1_equals_1'              => ["' OR 1=1--"],
            'or_a_equals_a'              => ["' OR 'a'='a"],
            'and_1_equals_1_parens'      => ["1 AND(1=1)"],
            'or_with_parens'             => ["1 OR(1=1)--"],

            // Blind — basados en tiempo
            'sleep_function'             => ["'; SLEEP(5)--"],
            'benchmark'                  => ["BENCHMARK(1000000,MD5('x'))"],
            'pg_sleep'                   => ["'; SELECT pg_sleep(5)--"],
            'waitfor_delay'              => ["'; WAITFOR DELAY '0:0:5'--"],

            // Terminadores de sentencia
            'comment_double_dash'        => ["admin'--"],
            'comment_hash'               => ["admin'#"],
            'semicolon_terminator'       => ["admin';"],
            'inline_comment'             => ["admin'/*"],
            'versioned_comment'          => ["/*!50000 UNION*/SELECT 1"],

            // Funciones críticas de manipulación
            'drop_table'                 => ["'; DROP TABLE users--"],
            'delete_from'               => ["'; DELETE FROM admins--"],
            'insert_into'               => ["'; INSERT INTO users VALUES(1,'h','h')--"],
            'load_file'                  => ["LOAD_FILE('/etc/passwd')"],
            'into_outfile'              => ["SELECT 1 INTO OUTFILE '/tmp/x'"],

            // Funciones de extracción de datos
            'extractvalue'               => ["AND EXTRACTVALUE(1,CONCAT(0x7e,version()))"],
            'updatexml'                  => ["AND UPDATEXML(1,CONCAT(0x7e,user()),1)"],
            'group_concat'               => ["UNION SELECT group_concat(table_name) FROM information_schema.tables"],

            // Subqueries
            'subquery_select_from'       => ["' AND (SELECT 1 FROM users WHERE id=1)='1"],
            'subquery_where'             => ["UNION SELECT 1 WHERE 1=1"],

            // Hex encoding
            'hex_encoding'               => ["SELECT 0x61646d696e"],

            // Stacked queries
            'stacked_drop'               => ["1; DROP TABLE sessions"],
            'stacked_select'             => ["1; SELECT * FROM users"],

            // CHAR() encoding
            'char_function'              => ["CHAR(65,68,77,73,78)"],

            // Evasión con whitespace
            'tab_between_keywords'       => ["UNION\tSELECT\t1"],
            'newline_between_keywords'   => ["UNION\nSELECT\n1"],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectSql($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'simple_name'            => ['Juan Pérez'],
            'email'                  => ['usuario@dominio.com'],
            'search_word'            => ['sistema de gestión'],
            'numeric_id'             => ['42'],
            'path'                   => ['/admin/users'],
            'uuid'                   => ['550e8400-e29b-41d4-a716-446655440000'],
            'html_content'           => ['<b>hola</b>'],
            'text_with_apostrophe'   => ["L'Oréal"],
            'order_word'             => ['ordenar'],           // contiene OR
            'android_word'           => ['android phone'],    // no es AND R
            'select_as_verb'         => ['please select an option'],
        ];
    }
}
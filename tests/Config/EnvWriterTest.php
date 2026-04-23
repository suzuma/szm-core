<?php

declare(strict_types=1);

namespace Tests\Config;

use Core\Config\EnvWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EnvWriterTest — verifica lectura, escritura y comportamiento de seguridad
 * del servicio EnvWriter sin tocar el .env real del proyecto.
 *
 * Cada test trabaja con un archivo .env temporal en sys_get_temp_dir().
 */
final class EnvWriterTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'szm_env_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        $backup = $this->tmpFile . '.backup';
        if (file_exists($backup)) {
            unlink($backup);
        }
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    #[Test]
    public function readParsesSimpleKeyValue(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\nEMPRESA_NOMBRE=MiSistema\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertSame('dev', $values['APP_ENV']);
        $this->assertSame('MiSistema', $values['EMPRESA_NOMBRE']);
    }

    #[Test]
    public function readIgnoresCommentLines(): void
    {
        file_put_contents($this->tmpFile, "# Este es un comentario\nAPP_ENV=prod\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertArrayNotHasKey('#', $values);
        $this->assertSame('prod', $values['APP_ENV']);
    }

    #[Test]
    public function readStripsInlineComments(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev   # dev | prod | stop\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertSame('dev', $values['APP_ENV']);
    }

    #[Test]
    public function readHandlesDoubleQuotedValues(): void
    {
        file_put_contents($this->tmpFile, "EMPRESA_NOMBRE=\"Mi Sistema Nuevo\"\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertSame('Mi Sistema Nuevo', $values['EMPRESA_NOMBRE']);
    }

    #[Test]
    public function readHandlesSingleQuotedValues(): void
    {
        file_put_contents($this->tmpFile, "EMPRESA_NOMBRE='Mi Sistema'\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertSame('Mi Sistema', $values['EMPRESA_NOMBRE']);
    }

    #[Test]
    public function readHandlesEmptyValues(): void
    {
        file_put_contents($this->tmpFile, "REDIS_HOST=\nAPP_URL=\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertSame('', $values['REDIS_HOST']);
        $this->assertSame('', $values['APP_URL']);
    }

    #[Test]
    public function readIgnoresBlankLines(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\n\n\nEMPRESA_NOMBRE=Foo\n");

        $values = EnvWriter::read($this->tmpFile);

        $this->assertCount(2, $values);
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    #[Test]
    public function writeUpdatesExistingKey(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\nEMPRESA_NOMBRE=Viejo\n");

        EnvWriter::write('EMPRESA_NOMBRE', 'Nuevo', $this->tmpFile);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('Nuevo', $values['EMPRESA_NOMBRE']);
    }

    #[Test]
    public function writePreservesOtherLines(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\nEMPRESA_NOMBRE=Viejo\nREDIS_HOST=\n");

        EnvWriter::write('EMPRESA_NOMBRE', 'Nuevo', $this->tmpFile);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('dev', $values['APP_ENV']);
        $this->assertSame('', $values['REDIS_HOST']);
    }

    #[Test]
    public function writePreservesCommentLines(): void
    {
        $original = "# Sección aplicación\nAPP_ENV=dev\n# otro comentario\nREDIS_HOST=\n";
        file_put_contents($this->tmpFile, $original);

        EnvWriter::write('APP_ENV', 'prod', $this->tmpFile);

        $content = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('# Sección aplicación', $content);
        $this->assertStringContainsString('# otro comentario', $content);
    }

    #[Test]
    public function writePreservesInlineComment(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev   # dev | prod | stop\n");

        EnvWriter::write('APP_ENV', 'prod', $this->tmpFile);

        $content = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('# dev | prod | stop', $content);
        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('prod', $values['APP_ENV']);
    }

    #[Test]
    public function writeAppendsNewKey(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\n");

        EnvWriter::write('NUEVA_CLAVE', 'valor', $this->tmpFile);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('valor', $values['NUEVA_CLAVE']);
        $this->assertSame('dev', $values['APP_ENV']);
    }

    #[Test]
    public function writeQuotesValuesWithSpaces(): void
    {
        file_put_contents($this->tmpFile, "EMPRESA_NOMBRE=Viejo\n");

        EnvWriter::write('EMPRESA_NOMBRE', 'Mi Sistema Nuevo', $this->tmpFile);

        $content = file_get_contents($this->tmpFile);
        $this->assertStringContainsString('"Mi Sistema Nuevo"', $content);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('Mi Sistema Nuevo', $values['EMPRESA_NOMBRE']);
    }

    #[Test]
    public function writeHandlesEmptyValue(): void
    {
        file_put_contents($this->tmpFile, "REDIS_HOST=localhost\n");

        EnvWriter::write('REDIS_HOST', '', $this->tmpFile);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('', $values['REDIS_HOST']);
    }

    // ── WriteMany ─────────────────────────────────────────────────────────────

    #[Test]
    public function writeManyUpdatesMultipleKeys(): void
    {
        file_put_contents($this->tmpFile, "MAIL_HOST=old.smtp.com\nMAIL_PORT=25\nAPP_ENV=dev\n");

        EnvWriter::writeMany([
            'MAIL_HOST' => 'new.smtp.com',
            'MAIL_PORT' => '587',
        ], $this->tmpFile);

        $values = EnvWriter::read($this->tmpFile);
        $this->assertSame('new.smtp.com', $values['MAIL_HOST']);
        $this->assertSame('587', $values['MAIL_PORT']);
        $this->assertSame('dev', $values['APP_ENV']); // no modificada
    }

    // ── Seguridad ─────────────────────────────────────────────────────────────

    #[Test]
    public function writeThrowsForReadonlyKey(): void
    {
        file_put_contents($this->tmpFile, "DB_HOST=localhost\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/solo lectura/');

        EnvWriter::write('DB_HOST', '10.0.0.1', $this->tmpFile);
    }

    #[Test]
    public function writeManyThrowsIfAnyKeyIsReadonly(): void
    {
        file_put_contents($this->tmpFile, "DB_PASSWORD=secret\nMAIL_HOST=smtp.x.com\n");

        $this->expectException(\RuntimeException::class);

        EnvWriter::writeMany(['DB_PASSWORD' => 'nuevo', 'MAIL_HOST' => 'nuevo.smtp.com'], $this->tmpFile);
    }

    // ── isSensitive / mask ────────────────────────────────────────────────────

    #[Test]
    public function isSensitiveReturnsTrueForPasswordKeys(): void
    {
        $this->assertTrue(EnvWriter::isSensitive('DB_PASSWORD'));
        $this->assertTrue(EnvWriter::isSensitive('MAIL_PASSWORD'));
        $this->assertTrue(EnvWriter::isSensitive('TELEGRAM_BOT_TOKEN'));
        $this->assertTrue(EnvWriter::isSensitive('WAF_BYPASS_SECRET'));
    }

    #[Test]
    public function isSensitiveReturnsFalseForRegularKeys(): void
    {
        $this->assertFalse(EnvWriter::isSensitive('APP_ENV'));
        $this->assertFalse(EnvWriter::isSensitive('EMPRESA_NOMBRE'));
        $this->assertFalse(EnvWriter::isSensitive('MAIL_HOST'));
    }

    #[Test]
    public function maskReturnsDotsForSensitiveNonEmptyValue(): void
    {
        $this->assertSame('••••••', EnvWriter::mask('MAIL_PASSWORD', 'secreto'));
    }

    #[Test]
    public function maskReturnsEmptyStringForSensitiveEmptyValue(): void
    {
        $this->assertSame('', EnvWriter::mask('MAIL_PASSWORD', ''));
    }

    #[Test]
    public function maskReturnsValueForNonSensitiveKey(): void
    {
        $this->assertSame('smtp.example.com', EnvWriter::mask('MAIL_HOST', 'smtp.example.com'));
    }

    // ── isReadonly ────────────────────────────────────────────────────────────

    #[Test]
    public function isReadonlyReturnsTrueForDbKeys(): void
    {
        $this->assertTrue(EnvWriter::isReadonly('DB_HOST'));
        $this->assertTrue(EnvWriter::isReadonly('DB_PASSWORD'));
        $this->assertTrue(EnvWriter::isReadonly('SESSION_NAME'));
    }

    #[Test]
    public function isReadonlyReturnsFalseForEditableKeys(): void
    {
        $this->assertFalse(EnvWriter::isReadonly('APP_ENV'));
        $this->assertFalse(EnvWriter::isReadonly('MAIL_HOST'));
        $this->assertFalse(EnvWriter::isReadonly('EMPRESA_NOMBRE'));
    }

    // ── Backup ────────────────────────────────────────────────────────────────

    #[Test]
    public function writeCreatesBackupFile(): void
    {
        file_put_contents($this->tmpFile, "APP_ENV=dev\n");
        $backup = $this->tmpFile . '.backup';

        // Asegurar que el backup no existe o es antiguo
        if (file_exists($backup)) {
            touch($backup, time() - 600);
        }

        EnvWriter::write('APP_ENV', 'prod', $this->tmpFile);

        $this->assertFileExists($backup);
        $this->assertStringContainsString('APP_ENV=dev', file_get_contents($backup));
    }
}
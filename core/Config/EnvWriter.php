<?php

declare(strict_types=1);

namespace Core\Config;

/**
 * EnvWriter — lectura y escritura segura del archivo .env.
 *
 * Principios de diseño:
 *  - Preserva la estructura completa del .env: comentarios, secciones,
 *    líneas en blanco e inline-comments (`KEY=value # nota`).
 *  - Escritura atómica: escribe en un archivo temporal y luego hace
 *    rename() para evitar que una escritura parcial corrompa el .env.
 *  - Exclusión mutua: flock(LOCK_EX) previene escrituras concurrentes.
 *  - Backup automático antes de cualquier modificación.
 *  - Valores con espacios o caracteres especiales se envuelven en comillas.
 *
 * Uso:
 *   $values = EnvWriter::read();           // ['APP_ENV' => 'dev', ...]
 *   EnvWriter::write('EMPRESA_NOMBRE', 'Mi App');
 *   EnvWriter::writeMany(['MAIL_HOST' => 'smtp.x.com', 'MAIL_PORT' => '587']);
 */
final class EnvWriter
{
    /**
     * Claves cuyo valor se considera sensible.
     * En logs y auditoría se muestran como "••••••".
     */
    private const SENSITIVE_KEYS = [
        'DB_PASSWORD',
        'MAIL_PASSWORD',
        'WAF_BYPASS_SECRET',
        'TELEGRAM_BOT_TOKEN',
        'REDIS_PASSWORD',
        'APP_KEY',
    ];

    /**
     * Claves protegidas: se muestran en la UI como solo lectura.
     * Modificarlas desde la web puede dejar la app inaccesible.
     */
    private const READONLY_KEYS = [
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'SESSION_NAME',
    ];

    // ── Lectura ───────────────────────────────────────────────────────────────

    /**
     * Parsea el archivo .env y devuelve un mapa [KEY => value].
     *
     * - Ignora líneas de comentario (empiezan con #) y líneas vacías.
     * - Elimina inline-comments (`KEY=value # comentario → value`).
     * - Elimina comillas simples y dobles que envuelven el valor.
     *
     * @return array<string, string>
     * @throws \RuntimeException si el archivo no existe o no es legible
     */
    public static function read(?string $path = null): array
    {
        $path = $path ?? self::envPath();

        if (!file_exists($path)) {
            throw new \RuntimeException(".env no encontrado en: {$path}");
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(".env no es legible: {$path}");
        }

        $result = [];
        $lines  = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Saltar comentarios y líneas vacías
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $rawValue] = explode('=', $trimmed, 2);
            $key = trim($key);

            if ($key === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $result[$key] = self::parseValue($rawValue);
        }

        return $result;
    }

    /**
     * Escribe un único valor en el .env.
     * Si la clave existe, actualiza su valor preservando el resto de la línea.
     * Si no existe, la agrega al final del archivo.
     *
     * @throws \RuntimeException si la clave es de solo lectura o el archivo no es escribible
     */
    public static function write(string $key, string $value, ?string $path = null): void
    {
        $path = $path ?? self::envPath();

        self::guardWritable($path);
        self::guardNotReadonly($key);
        self::backup($path);

        $lines   = file($path) ?: [];
        $written = false;
        $pattern = '/^' . preg_quote($key, '/') . '\s*=/';

        foreach ($lines as &$line) {
            if (preg_match($pattern, $line)) {
                // Preservar inline-comment si existe
                $inlineComment = self::extractInlineComment($line);
                $encoded       = self::encodeValue($value);
                $line          = $inlineComment !== ''
                    ? "{$key}={$encoded}   {$inlineComment}\n"
                    : "{$key}={$encoded}\n";
                $written = true;
                break;
            }
        }
        unset($line);

        if (!$written) {
            $lines[] = "{$key}=" . self::encodeValue($value) . "\n";
        }

        self::atomicWrite($path, implode('', $lines));
    }

    /**
     * Escribe múltiples valores en una sola operación (un solo backup, un solo write).
     *
     * @param array<string, string> $values
     * @throws \RuntimeException
     */
    public static function writeMany(array $values, ?string $path = null): void
    {
        $path = $path ?? self::envPath();

        self::guardWritable($path);

        foreach (array_keys($values) as $key) {
            self::guardNotReadonly($key);
        }

        self::backup($path);

        $lines   = file($path) ?: [];
        $written = [];

        foreach ($lines as &$line) {
            foreach ($values as $key => $value) {
                if (isset($written[$key])) {
                    continue;
                }
                if (preg_match('/^' . preg_quote($key, '/') . '\s*=/', $line)) {
                    $inlineComment = self::extractInlineComment($line);
                    $encoded       = self::encodeValue($value);
                    $line          = $inlineComment !== ''
                        ? "{$key}={$encoded}   {$inlineComment}\n"
                        : "{$key}={$encoded}\n";
                    $written[$key] = true;
                    break;
                }
            }
        }
        unset($line);

        // Agregar claves que no existían en el archivo
        $missing = array_diff_key($values, $written);
        foreach ($missing as $key => $value) {
            $lines[] = "{$key}=" . self::encodeValue($value) . "\n";
        }

        self::atomicWrite($path, implode('', $lines));
    }

    // ── Backup ────────────────────────────────────────────────────────────────

    /**
     * Crea una copia de seguridad del .env como .env.backup.
     * Solo sobrescribe si el backup tiene más de 5 minutos de antigüedad,
     * para no perder backups en sesiones de edición rápida.
     */
    public static function backup(?string $path = null): void
    {
        $path   = $path ?? self::envPath();
        $backup = $path . '.backup';

        if (file_exists($backup) && (time() - filemtime($backup)) < 300) {
            return; // backup reciente, no sobreescribir
        }

        @copy($path, $backup);
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    /** Retorna true si la clave tiene un valor sensible (password, token, secret). */
    public static function isSensitive(string $key): bool
    {
        return in_array(strtoupper($key), self::SENSITIVE_KEYS, true);
    }

    /** Retorna true si la clave está protegida y no puede editarse desde la UI. */
    public static function isReadonly(string $key): bool
    {
        return in_array(strtoupper($key), self::READONLY_KEYS, true);
    }

    /**
     * Enmascara el valor si la clave es sensible.
     * Útil para mostrar en logs o en la UI.
     */
    public static function mask(string $key, string $value): string
    {
        if (!self::isSensitive($key)) {
            return $value;
        }

        return $value !== '' ? '••••••' : '';
    }

    /** Lista de claves consideradas de solo lectura. */
    public static function readonlyKeys(): array
    {
        return self::READONLY_KEYS;
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    /**
     * Parsea el valor crudo de una línea .env:
     *   - Elimina comillas envolventes (' o ")
     *   - Elimina inline-comments (# ...)
     *   - Trim de espacios
     */
    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);

        // Valor entre comillas dobles: preservar espacios, no eliminar inline-comment
        if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && strlen($raw) >= 2) {
            return stripslashes(substr($raw, 1, -1));
        }

        // Valor entre comillas simples: literal, sin sustituciones
        if (str_starts_with($raw, "'") && str_ends_with($raw, "'") && strlen($raw) >= 2) {
            return substr($raw, 1, -1);
        }

        // Sin comillas: eliminar inline-comment y trim
        if (str_contains($raw, ' #') || str_contains($raw, "\t#")) {
            $raw = (string) preg_replace('/\s+#.*$/', '', $raw);
        }

        return trim($raw);
    }

    /**
     * Extrae el inline-comment de una línea (incluyendo el #).
     * `KEY=value   # comentario\n` → `# comentario`
     */
    private static function extractInlineComment(string $line): string
    {
        // Eliminar la parte KEY=value y buscar el comentario
        $valuePart = ltrim(explode('=', $line, 2)[1] ?? '');

        // Si el valor está entre comillas, el comentario está después de la comilla de cierre
        if (preg_match('/^(["\']).*?\1\s*(#.*)$/s', $valuePart, $m)) {
            return trim($m[2]);
        }

        // Sin comillas: el comentario empieza con \s+#
        if (preg_match('/\s+(#.*)$/', $valuePart, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Codifica un valor para escribirlo en .env.
     * - Si contiene espacios, caracteres especiales o # → envuelve en comillas dobles.
     * - Si está vacío → escribe vacío (sin comillas).
     */
    private static function encodeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Si necesita comillas: contiene espacio, #, comillas, backslash o caracteres de control
        if (preg_match('/[\s#"\'\\\\]/', $value) || $value !== trim($value)) {
            return '"' . addslashes($value) . '"';
        }

        return $value;
    }

    /**
     * Escritura atómica: escribe en un temporal y usa rename().
     * En el mismo filesystem, rename() es una operación atómica en Linux/macOS.
     * Usa flock(LOCK_EX) para exclusión mutua entre procesos concurrentes.
     *
     * @throws \RuntimeException si no se puede escribir el temporal o hacer rename
     */
    private static function atomicWrite(string $path, string $content): void
    {
        $tmp = $path . '.tmp.' . getmypid();
        $fh  = @fopen($tmp, 'w');

        if ($fh === false) {
            throw new \RuntimeException("No se pudo crear el archivo temporal: {$tmp}");
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                throw new \RuntimeException("No se pudo obtener el lock exclusivo para escribir .env");
            }

            fwrite($fh, $content);
            fflush($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("No se pudo reemplazar el .env con el archivo temporal");
        }
    }

    private static function guardWritable(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException(".env no encontrado en: {$path}");
        }
        if (!is_writable($path)) {
            throw new \RuntimeException(".env no tiene permisos de escritura: {$path}");
        }
    }

    private static function guardNotReadonly(string $key): void
    {
        if (self::isReadonly($key)) {
            throw new \RuntimeException("La variable '{$key}' es de solo lectura y no puede modificarse desde la UI.");
        }
    }

    private static function envPath(): string
    {
        // Busca .env relativo al directorio raíz del proyecto.
        // core/Config/EnvWriter.php → dirname x3 = raíz del proyecto
        return dirname(__DIR__, 2) . '/.env';
    }
}
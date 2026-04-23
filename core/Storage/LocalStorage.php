<?php

declare(strict_types=1);

namespace Core\Storage;

/**
 * LocalStorage — almacenamiento de archivos en el sistema de archivos local.
 *
 * Los archivos se guardan en $basePath (absoluto) y se sirven bajo $baseUrl.
 *
 * Configuración recomendada:
 *   basePath = /ruta/al/proyecto/storage/uploads
 *   baseUrl  = /storage                          (ruta pública)
 *
 * Para que los archivos sean accesibles desde el navegador, agrega en .htaccess
 * o en el servidor web una regla que dirija /storage/* a storage/uploads/*.
 * Alternativamente, añade una ruta `GET /storage/{path}` en web.php que sirva
 * el archivo vía Response con el Content-Type adecuado.
 */
final class LocalStorage implements StorageInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $baseUrl = '/storage',
    ) {
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, recursive: true);
        }
    }

    // ── Escritura ─────────────────────────────────────────────────────────

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->fullPath($path);
        $dir      = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    public function putFile(string $directory, array $file): string|false
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }

        $tmpPath   = $file['tmp_name'] ?? '';
        $original  = $file['name']     ?? 'file';
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

        if (!is_uploaded_file($tmpPath)) {
            return false;
        }

        $filename = bin2hex(random_bytes(16)) . ($extension !== '' ? ".{$extension}" : '');
        $relPath  = trim($directory, '/') . '/' . $filename;
        $fullPath = $this->fullPath($relPath);
        $dir      = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, recursive: true);
        }

        if (!move_uploaded_file($tmpPath, $fullPath)) {
            return false;
        }

        return $relPath;
    }

    // ── Lectura ───────────────────────────────────────────────────────────

    public function get(string $path): string|false
    {
        $fullPath = $this->fullPath($path);
        return file_exists($fullPath) ? file_get_contents($fullPath) : false;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function size(string $path): int
    {
        $fullPath = $this->fullPath($path);
        return file_exists($fullPath) ? (int) filesize($fullPath) : -1;
    }

    public function files(string $directory = ''): array
    {
        $dir   = $this->fullPath($directory);
        $files = glob($dir . '/*') ?: [];
        $base  = rtrim($this->basePath, '/') . '/';

        return array_values(array_map(
            fn($f) => str_replace($base, '', $f),
            array_filter($files, 'is_file'),
        ));
    }

    // ── Eliminación ───────────────────────────────────────────────────────

    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);
        return !file_exists($fullPath) || @unlink($fullPath);
    }

    // ── URLs ──────────────────────────────────────────────────────────────

    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    public function path(string $path): string
    {
        return $this->fullPath($path);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function fullPath(string $path): string
    {
        // Prevenir path traversal
        $clean = preg_replace('/\.\.[\\/\\\\]/', '', $path);
        return rtrim($this->basePath, '/') . '/' . ltrim($clean, '/');
    }
}
<?php

declare(strict_types=1);

namespace Core\Cache;

/**
 * FileCache — implementación de CacheInterface usando el sistema de archivos.
 *
 * Fallback cuando APCu no está disponible. Almacena cada entrada como un
 * archivo JSON en storage/cache/. Usa flock() para escrituras concurrentes.
 *
 * No requiere extensiones adicionales de PHP.
 * Los datos persisten entre reinicios del servidor (hasta que expiren).
 */
final class FileCache implements CacheInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly string $prefix = 'szm_',
    ) {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, recursive: true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->filePath($key);

        if (!file_exists($file)) {
            return $default;
        }

        $entry = $this->readEntry($file);

        if ($entry === null) {
            return $default;
        }

        if ($entry['expires'] !== 0 && $entry['expires'] < time()) {
            @unlink($file);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $entry = [
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $value,
        ];

        return $this->writeEntry($this->filePath($key), $entry);
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__MISS__') !== '__MISS__';
    }

    public function delete(string $key): bool
    {
        $file = $this->filePath($key);
        return !file_exists($file) || @unlink($file);
    }

    public function increment(string $key, int $by = 1, int $ttl = 0): int|false
    {
        $file = $this->filePath($key);
        $fh   = @fopen($file, 'c+');

        if ($fh === false) {
            return false;
        }

        try {
            flock($fh, LOCK_EX);
            $content = stream_get_contents($fh);
            $entry   = $content !== '' ? json_decode($content, true) : null;

            if ($entry === null || ($entry['expires'] !== 0 && $entry['expires'] < time())) {
                // No existe o expiró — crea nuevo con TTL
                $newValue = $by;
                $expires  = $ttl > 0 ? time() + $ttl : 0;
            } else {
                $newValue = ((int) $entry['value']) + $by;
                $expires  = $entry['expires'];  // preservar TTL original
            }

            $newEntry = ['expires' => $expires, 'value' => $newValue];
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, json_encode($newEntry));
            fflush($fh);
            flock($fh, LOCK_UN);

            return $newValue;
        } finally {
            fclose($fh);
        }
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $value = $this->get($key, $miss = new \stdClass());

        if ($value !== $miss) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }

    public function flush(): bool
    {
        $files = glob($this->directory . '/' . $this->prefix . '*.cache') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        return true;
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function filePath(string $key): string
    {
        return $this->directory . '/' . $this->prefix . hash('sha256', $key) . '.cache';
    }

    private function readEntry(string $file): ?array
    {
        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return null;
        }
        $entry = json_decode($content, true);
        return is_array($entry) ? $entry : null;
    }

    private function writeEntry(string $file, array $entry): bool
    {
        $fh = @fopen($file, 'w');
        if ($fh === false) {
            return false;
        }

        try {
            flock($fh, LOCK_EX);
            fwrite($fh, json_encode($entry));
            fflush($fh);
            flock($fh, LOCK_UN);
            return true;
        } finally {
            fclose($fh);
        }
    }
}
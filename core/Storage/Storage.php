<?php

declare(strict_types=1);

namespace Core\Storage;

use Core\ServicesContainer;

/**
 * Storage — fachada estática para el sistema de archivos.
 *
 * Delega al driver registrado (StorageInterface::class) en el contenedor.
 *
 * Uso:
 *   // Guardar un archivo subido via formulario
 *   $path = Storage::putFile('avatars', $request->file('avatar'));
 *   // → 'avatars/a1b2c3d4ef56.jpg'
 *
 *   // Guardar contenido generado (CSV, PDF, etc.)
 *   Storage::put('exports/report-2026.csv', $csvContent);
 *
 *   // URL para mostrar en la vista
 *   $imgUrl = Storage::url($user->avatar);
 *   // → '/storage/avatars/a1b2c3d4ef56.jpg'
 *
 *   // Ruta absoluta para librerías que la necesitan
 *   $absPath = Storage::path('exports/report-2026.csv');
 *
 *   // Eliminar
 *   Storage::delete($user->avatar);
 */
final class Storage
{
    private function __construct() {}

    public static function put(string $path, string $contents): bool
    {
        return self::disk()->put($path, $contents);
    }

    /**
     * @param array<string, mixed> $file  Entrada de $_FILES para un campo
     */
    public static function putFile(string $directory, array $file): string|false
    {
        return self::disk()->putFile($directory, $file);
    }

    public static function get(string $path): string|false
    {
        return self::disk()->get($path);
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists($path);
    }

    public static function delete(string $path): bool
    {
        return self::disk()->delete($path);
    }

    public static function url(string $path): string
    {
        return self::disk()->url($path);
    }

    public static function path(string $path): string
    {
        return self::disk()->path($path);
    }

    public static function size(string $path): int
    {
        return self::disk()->size($path);
    }

    /** @return list<string> */
    public static function files(string $directory = ''): array
    {
        return self::disk()->files($directory);
    }

    private static function disk(): StorageInterface
    {
        return ServicesContainer::get(StorageInterface::class);
    }
}
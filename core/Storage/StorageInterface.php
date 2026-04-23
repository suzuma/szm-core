<?php

declare(strict_types=1);

namespace Core\Storage;

/**
 * StorageInterface — contrato para el sistema de almacenamiento de archivos.
 *
 * Implementaciones incluidas:
 *   - LocalStorage → almacena en storage/uploads/ del proyecto
 *
 * Registro en providers.php:
 *   ServicesContainer::bind(StorageInterface::class, fn() =>
 *       new LocalStorage(basePath: __DIR__ . '/storage/uploads', baseUrl: '/storage')
 *   );
 *
 * Uso vía fachada:
 *   // Guardar archivo subido
 *   $path = Storage::putFile('avatars', $request->file('avatar'));
 *   // → 'avatars/a1b2c3d4.jpg'
 *
 *   // Guardar contenido directamente
 *   Storage::put('exports/report.csv', $csvContent);
 *
 *   // URL pública
 *   Storage::url('avatars/a1b2c3d4.jpg');
 *   // → '/storage/avatars/a1b2c3d4.jpg'
 */
interface StorageInterface
{
    /**
     * Guarda $contents en la ruta dada (relativa a la raíz del disco).
     * Crea los directorios intermedios si no existen.
     */
    public function put(string $path, string $contents): bool;

    /**
     * Mueve un archivo subido ($_FILES) a la ruta $directory dentro del disco.
     * El nombre del archivo se genera con un hash único.
     *
     * @param string               $directory  Subdirectorio destino (ej: 'avatars')
     * @param array<string, mixed> $file       Entrada de $_FILES para un campo
     * @return string|false  Ruta relativa almacenada (ej: 'avatars/abc123.jpg') o false si falló
     */
    public function putFile(string $directory, array $file): string|false;

    /**
     * Obtiene el contenido de un archivo.
     * Retorna false si no existe.
     */
    public function get(string $path): string|false;

    /** Verifica si un archivo existe en el disco. */
    public function exists(string $path): bool;

    /** Elimina un archivo. */
    public function delete(string $path): bool;

    /**
     * Retorna la URL pública del archivo.
     * Ej: 'avatars/foto.jpg' → '/storage/avatars/foto.jpg'
     */
    public function url(string $path): string;

    /**
     * Retorna la ruta absoluta en el sistema de archivos.
     * Ej: 'avatars/foto.jpg' → '/var/www/storage/uploads/avatars/foto.jpg'
     */
    public function path(string $path): string;

    /** Tamaño del archivo en bytes. -1 si no existe. */
    public function size(string $path): int;

    /**
     * Lista los archivos de un directorio.
     *
     * @return list<string>  Rutas relativas
     */
    public function files(string $directory = ''): array;
}
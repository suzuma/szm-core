<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\Media;
use Core\Auth\Auth;

/**
 * MediaController — gestión de subida de archivos para posts.
 *
 * Rutas (filtro 'admin'):
 *   POST /admin/blog/media  → store()  (AJAX multipart/form-data)
 */
class MediaController extends BaseController
{
    private const ALLOWED_MIME = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    private const UPLOAD_DIR = 'public/uploads/blog';

    public function store(): never
    {
        $file = $_FILES['file'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->json(['ok' => false, 'error' => 'No se recibió ningún archivo.'], 422);
        }

        if ($file['size'] > self::MAX_BYTES) {
            $this->json(['ok' => false, 'error' => 'El archivo supera el límite de 5 MB.'], 422);
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $this->json(['ok' => false, 'error' => 'Tipo de archivo no permitido.'], 422);
        }

        $ext      = $this->extensionFromMime($mime);
        $filename = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
        $dir      = rtrim(_BASE_PATH_, '/') . '/' . self::UPLOAD_DIR;
        $destPath = $dir . '/' . $filename;
        $destUrl  = '/' . self::UPLOAD_DIR . '/' . $filename;

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->json(['ok' => false, 'error' => 'No se pudo crear el directorio de subida.'], 500);
        }

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['ok' => false, 'error' => 'Error al guardar el archivo.'], 500);
        }

        // Dimensiones para imágenes rasterizadas
        [$width, $height] = $this->imageDimensions($destPath, $mime);

        $postId = $this->request()->int('post_id') ?: null;

        $media = Media::create([
            'post_id'       => $postId,
            'collection'    => 'content',
            'filename'      => $filename,
            'original_name' => $file['name'],
            'path'          => $destPath,
            'url'           => $destUrl,
            'mime_type'     => $mime,
            'size_bytes'    => $file['size'],
            'width'         => $width,
            'height'        => $height,
            'alt_text'      => null,
            'uploaded_by'   => Auth::id(),
        ]);

        // ok/url/id  → used by featured-image upload JS
        // success/file → required by Editor.js Image plugin
        $this->json([
            'ok'      => true,
            'success' => 1,
            'url'     => $destUrl,
            'file'    => ['url' => $destUrl],
            'id'      => $media->id,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function extensionFromMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'     => 'jpg',
            'image/png'      => 'png',
            'image/gif'      => 'gif',
            'image/webp'     => 'webp',
            'image/svg+xml'  => 'svg',
            default          => 'bin',
        };
    }

    private function imageDimensions(string $path, string $mime): array
    {
        if ($mime === 'image/svg+xml') {
            return [null, null];
        }

        $info = @getimagesize($path);
        return $info ? [$info[0], $info[1]] : [null, null];
    }
}
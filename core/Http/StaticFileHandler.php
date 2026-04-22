<?php
declare(strict_types=1);

namespace Core\Http;

/**
 * StaticFileHandler
 *
 * Sirve archivos estáticos (CSS, JS, imágenes, fuentes) con el
 * Content-Type correcto sin pasar por el router de la aplicación.
 *
 * Reglas de seguridad:
 *  - Extensiones sensibles bloqueadas con 403.
 *  - Path traversal prevenido con realpath().
 *  - Solo sirve archivos dentro del directorio público.
 */
final class StaticFileHandler
{
    /** Extensiones que NUNCA deben servirse directamente */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar',  // ejecutables PHP
        'env', 'json', 'lock',   // configuración / secretos
        'sql', 'log', 'config',  // datos sensibles
        'sh', 'bash',            // scripts
    ];

    /** Mapa explícito de MIME types (más confiable que mime_content_type) */
    private const MIME_TYPES = [
        // Texto
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'mjs'   => 'application/javascript; charset=UTF-8',
        'map'   => 'application/json',
        // Imágenes
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'svg'   => 'image/svg+xml',
        'avif'  => 'image/avif',
        // Fuentes
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        // Documentos / datos
        'pdf'   => 'application/pdf',
        'txt'   => 'text/plain; charset=UTF-8',
        'xml'   => 'application/xml',
    ];

    // Clase utilitaria — no se instancia.
    private function __construct() {}

    /**
     * Evalúa si la URI apunta a un asset estático válido.
     * Si lo es: envía headers, transmite el archivo y hace exit.
     * Si no lo es: retorna sin hacer nada (el flujo continúa).
     *
     * @param string $publicDir  Directorio raíz de /public
     * @param string $uri        URI limpia (sin query string)
     */
    public static function serveOrContinue(string $publicDir, string $uri): void
    {
        // La raíz nunca es un asset
        if ($uri === '/') {
            return;
        }

        $candidatePath = self::resolvePath($publicDir, $uri);

        if ($candidatePath === null) {
            return; // Path traversal detectado o archivo no existe
        }

        // Bloquear dotfiles (.env, .htaccess, .git, etc.) — nunca deben ser accesibles
        $basename = basename($candidatePath);
        if (str_starts_with($basename, '.')) {
            http_response_code(403);
            exit('403 Forbidden');
        }

        $extension = strtolower(pathinfo($candidatePath, PATHINFO_EXTENSION));

        if (self::isForbidden($extension)) {
            http_response_code(403);
            exit('403 Forbidden');
        }

        $contentType = self::MIME_TYPES[$extension] ?? mime_content_type($candidatePath);

        self::sendFile($candidatePath, $contentType);
    }

    /* ------------------------------------------------------------------
     | Helpers privados
     ------------------------------------------------------------------ */

    /**
     * Resuelve la ruta física real del archivo.
     * Devuelve null si el archivo no existe o está fuera del publicDir.
     */
    private static function resolvePath(string $publicDir, string $uri): ?string
    {
        $candidate = $publicDir . $uri;
        $realPath  = realpath($candidate);

        if ($realPath === false || !is_file($realPath)) {
            return null;
        }

        // Prevenir path traversal: el archivo debe estar dentro del publicDir
        $realPublic = realpath($publicDir);

        if ($realPublic === false || !str_starts_with($realPath, $realPublic)) {
            return null;
        }

        return $realPath;
    }

    /** Comprueba si la extensión está en la lista de prohibidas */
    private static function isForbidden(string $extension): bool
    {
        return in_array($extension, self::FORBIDDEN_EXTENSIONS, strict: true);
    }

    /** Envía el archivo con los headers apropiados */
    private static function sendFile(string $path, string $contentType): never
    {
        $size = filesize($path);

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=31536000, immutable'); // 1 año para assets versionados
        header('X-Content-Type-Options: nosniff');

        readfile($path);
        exit;
    }
}
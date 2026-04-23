<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Request;
use Core\Http\Response;
use Core\ServicesContainer;
use Twig\Environment;

/**
 * BaseController
 *
 * Provee helpers de respuesta HTTP a todos los controladores.
 * Internamente construye un Response y llama send() — esto centraliza
 * toda la lógica de envío HTTP en la clase Response y elimina el
 * patrón header()+exit disperso en los controladores.
 *
 * No contiene lógica de negocio.
 */
abstract class BaseController
{
    private Environment $twig;

    public function __construct()
    {
        $this->twig = ServicesContainer::twig();
    }

    // ── Request ───────────────────────────────────────────────────────────

    /** Devuelve la instancia singleton de la petición HTTP actual. */
    protected function request(): Request
    {
        return Request::capture();
    }

    // ── Respuestas ────────────────────────────────────────────────────────

    /**
     * Renderiza una plantilla Twig y retorna el HTML.
     *
     * Ejemplo: return $this->view('auth/login.twig', ['error' => 'msg']);
     */
    protected function view(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Redirige a una URL y termina la ejecución.
     *
     * Ejemplo: $this->redirect('/dashboard');
     */
    protected function redirect(string $url): never
    {
        Response::redirect($url)->send();
    }

    /**
     * Redirige a la URL anterior (Referer) o al fallback indicado.
     * Valida que el Referer sea del mismo host para prevenir open redirect.
     */
    protected function back(string $fallback = '/'): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $ownHost = $_SERVER['HTTP_HOST']    ?? '';
        $url     = $fallback;

        if ($referer !== '') {
            $parsed  = parse_url($referer);
            if ($parsed !== false) {
                $refHost = $parsed['host'] ?? null;
                // Aceptar: URL relativa (sin host) o mismo host
                if ($refHost === null || $refHost === $ownHost) {
                    $url = $referer;
                }
            }
        }

        Response::redirect($url)->send();
    }

    /**
     * Responde con JSON y termina la ejecución.
     *
     * Ejemplo: $this->json(['ok' => true]);
     */
    protected function json(mixed $data, int $status = 200): never
    {
        Response::json($data, $status)->send();
    }

    /**
     * Aborta con un código HTTP.
     * Si existe una vista errors/{code}.twig la renderiza; si no, muestra el mensaje plano.
     */
    protected function abort(int $code, string $message = ''): never
    {
        $template = "errors/{$code}.twig";
        $viewPath  = _APP_PATH_ . "Views/{$template}";
        $body      = file_exists($viewPath) ? $this->view($template) : $message;

        Response::html($body, $code)->send();
    }

    /**
     * Inicia una descarga de archivo (streaming).
     *
     * Envía los headers de descarga y luego ejecuta $writer, que debe
     * escribir el contenido directamente a la salida (p. ej. con fputcsv
     * sobre php://output). Termina la ejecución al finalizar.
     *
     * Ejemplo:
     *   $this->download('reporte.csv', 'text/csv; charset=UTF-8', function () {
     *       $out = fopen('php://output', 'w');
     *       fputcsv($out, ['col1', 'col2']);
     *       fclose($out);
     *   });
     *
     * @param callable(): void $writer
     */
    protected function download(string $filename, string $mimeType, callable $writer): never
    {
        Response::make()
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . addslashes($filename) . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store')
            ->withHeader('Pragma', 'no-cache')
            ->sendHeaders();

        $writer();
        exit;
    }
}
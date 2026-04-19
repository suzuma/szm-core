<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Http\Request;
use Core\ServicesContainer;
use Twig\Environment;

/**
 * BaseController
 *
 * Provee helpers de respuesta HTTP a todos los controladores.
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
     * Ejemplo: return $this->redirect('/dashboard');
     */
    protected function redirect(string $url): never
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    /**
     * Redirige a la URL anterior (Referer) o a un fallback.
     */
    protected function back(string $fallback = '/'): never
    {
        $this->redirect($_SERVER['HTTP_REFERER'] ?? $fallback);
    }

    /**
     * Responde con JSON y termina la ejecución.
     *
     * Ejemplo: return $this->json(['ok' => true]);
     */
    protected function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Aborta con un código HTTP.
     * Si existe una vista errors/{code}.twig la renderiza; si no, muestra el mensaje plano.
     */
    protected function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        $template = "errors/{$code}.twig";
        $viewPath = _APP_PATH_ . "Views/{$template}";
        echo file_exists($viewPath) ? $this->view($template) : $message;
        exit;
    }
}
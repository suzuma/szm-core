<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * ErrorController
 *
 * Maneja respuestas de error HTTP del framework.
 * Referenciado directamente en Application::handleDispatchError().
 */
final class ErrorController extends BaseController
{
    /** 404 — Ruta no encontrada */
    public function notFound(): string
    {
        http_response_code(404);
        return $this->view('errors/404.twig');
    }

    /** 403 — Acceso denegado */
    public function forbidden(): string
    {
        http_response_code(403);
        return $this->view('errors/403.twig');
    }

    /** 500 — Error interno del servidor */
    public function serverError(): string
    {
        http_response_code(500);
        return $this->view('errors/500.twig');
    }

    /** 503 — Mantenimiento */
    public function maintenance(): string
    {
        http_response_code(503);
        return $this->view('errors/503.twig');
    }

    /** 419 — Sesión o token CSRF expirado */
    public function sessionExpired(): string
    {
        http_response_code(419);
        return $this->view('errors/419.twig');
    }
}
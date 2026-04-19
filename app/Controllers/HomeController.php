<?php

declare(strict_types=1);

namespace App\Controllers;

/**
 * HomeController
 *
 * Punto de entrada tras el login. Los proyectos pueden
 * reemplazar este controlador o añadir lógica de dashboard.
 */
final class HomeController extends BaseController
{
    public function index(): string
    {
        return $this->view('home/index.twig');
    }
}
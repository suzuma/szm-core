<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeControllerCommand — genera un stub de controlador.
 *
 * Uso:
 *   php bin/szm make:controller NombreController
 *   php bin/szm make:controller Admin/DashboardController
 *
 * El sufijo "Controller" se añade automáticamente si no está presente.
 * Se pueden crear controladores en subdirectorios usando barras:
 *   Admin/UsersController → app/Controllers/Admin/UsersController.php
 */
final class MakeControllerCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un nuevo controlador en app/Controllers/';
    }

    public function usage(): string
    {
        return 'make:controller <Nombre>';
    }

    public function handle(array $args): int
    {
        $name = $args[0] ?? '';

        if ($name === '') {
            App::err('Especifica el nombre del controlador.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:controller NombreController'));
            return 1;
        }

        // Normalizar separadores
        $name = str_replace('\\', '/', $name);

        // Añadir sufijo Controller si no está presente
        $parts    = explode('/', $name);
        $class    = end($parts);
        if (!str_ends_with($class, 'Controller')) {
            $class   .= 'Controller';
            $parts[count($parts) - 1] = $class;
        }

        // Subnamespace a partir del subdirectorio (p.ej. Admin/)
        $subPath      = implode('/', $parts);
        $subNamespace = count($parts) > 1
            ? '\\' . implode('\\', array_slice($parts, 0, -1))
            : '';
        $namespace    = 'App\\Controllers' . str_replace('/', '\\', $subNamespace);

        $targetDir  = $this->basePath . '/app/Controllers/' . implode('/', array_slice($parts, 0, -1));
        $targetFile = $this->basePath . '/app/Controllers/' . $subPath . '.php';

        // Crear directorio si no existe
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, recursive: true)) {
            App::err("No se pudo crear el directorio: {$targetDir}");
            return 1;
        }

        if (file_exists($targetFile)) {
            App::warn("Ya existe: app/Controllers/{$subPath}.php");
            return 0;
        }

        // Vista sugerida basada en el nombre (snake_case sin sufijo)
        $viewName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0',
            str_replace('Controller', '', $class)));

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use App\Controllers\BaseController;

        final class {$class} extends BaseController
        {
            public function index(): string
            {
                return \$this->view('{$viewName}/index.twig');
            }
        }
        PHP;

        file_put_contents($targetFile, $stub . PHP_EOL);

        App::success("Controlador creado: app/Controllers/{$subPath}.php");
        return 0;
    }
}
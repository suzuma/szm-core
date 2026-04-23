<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeEventCommand — genera un stub de evento de dominio.
 *
 * Uso:
 *   php bin/szm make:event UserRegistered
 *   php bin/szm make:event ProductoCreado
 *
 * Genera: app/Events/UserRegisteredEvent.php
 *
 * Para registrar el listener en providers.php:
 *   EventDispatcher::listen(UserRegistered::class, function (UserRegistered $e): void {
 *       // lógica del listener
 *   });
 */
final class MakeEventCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un evento de dominio en app/Events/';
    }

    public function usage(): string
    {
        return 'make:event <NombreEvento>';
    }

    public function handle(array $args): int
    {
        $name = trim($args[0] ?? '');

        if ($name === '') {
            App::err('Especifica el nombre del evento.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:event UserRegistered'));
            return 1;
        }

        $class  = ucfirst($name);
        $target = $this->basePath . '/app/Events/' . $class . '.php';

        if (file_exists($target)) {
            App::warn("Ya existe: app/Events/{$class}.php");
            return 0;
        }

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Events;

        /**
         * {$class} — evento de dominio.
         *
         * Registro del listener en providers.php:
         *
         *   EventDispatcher::listen({$class}::class, function ({$class} \$e): void {
         *       // lógica
         *   });
         *
         * Dispatch desde un servicio o controlador:
         *
         *   EventDispatcher::dispatch(new {$class}(\$entity));
         */
        final class {$class}
        {
            public function __construct(
                // public readonly User \$user,
                // Agrega las propiedades que el listener necesita
            ) {}
        }
        PHP;

        file_put_contents($target, $stub . PHP_EOL);

        App::success("Evento creado: app/Events/{$class}.php");
        App::info("Registra el listener en: app/providers.php");
        App::out('');
        App::out(App::gray("  EventDispatcher::listen({$class}::class, function ({$class} \$e): void {"));
        App::out(App::gray('      // lógica'));
        App::out(App::gray('  });'));
        App::out('');

        return 0;
    }
}
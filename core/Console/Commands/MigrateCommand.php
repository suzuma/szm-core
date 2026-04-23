<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MigrateCommand — ejecuta las migraciones y seeds del proyecto.
 *
 * Delega en database/migrate.php pasando los flags recibidos.
 *
 * Uso:
 *   php bin/szm migrate
 *   php bin/szm migrate --fresh
 *   php bin/szm migrate --no-seed
 *   php bin/szm migrate --fresh --no-seed
 */
final class MigrateCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Ejecuta migraciones y seeds de la base de datos';
    }

    public function usage(): string
    {
        return 'migrate [--fresh] [--no-seed]';
    }

    public function handle(array $args): int
    {
        $migrator = $this->basePath . '/database/migrate.php';

        if (!file_exists($migrator)) {
            App::err("No se encontró database/migrate.php en {$this->basePath}");
            return 1;
        }

        // Construir el comando pasando los flags tal como se recibieron
        $flags = array_map('escapeshellarg', $args);
        $cmd   = PHP_BINARY . ' ' . escapeshellarg($migrator) . ' ' . implode(' ', $flags);

        passthru($cmd, $exitCode);

        return $exitCode;
    }
}
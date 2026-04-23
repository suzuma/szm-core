<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeMigrationCommand — genera un stub de migración SQL numerado.
 *
 * El número se asigna automáticamente incrementando el último archivo
 * existente en database/migrations/.
 *
 * Uso:
 *   php bin/szm make:migration create_productos_table
 *   php bin/szm make:migration add_precio_to_productos
 *   php bin/szm make:migration alter_usuarios_add_avatar
 */
final class MakeMigrationCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un nuevo archivo de migración SQL';
    }

    public function usage(): string
    {
        return 'make:migration <nombre_snake_case>';
    }

    public function handle(array $args): int
    {
        $name = trim($args[0] ?? '');

        if ($name === '') {
            App::err('Especifica el nombre de la migración.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:migration create_productos_table'));
            return 1;
        }

        // Normalizar: solo minúsculas y guiones bajos
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));
        $name = preg_replace('/_+/', '_', trim($name, '_'));

        $migrationsDir = $this->basePath . '/database/migrations';

        if (!is_dir($migrationsDir)) {
            App::err("No se encontró el directorio database/migrations.");
            return 1;
        }

        $nextNumber = $this->nextMigrationNumber($migrationsDir);
        $prefix     = str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $filename   = "{$prefix}_{$name}.sql";
        $targetFile = $migrationsDir . '/' . $filename;

        if (file_exists($targetFile)) {
            App::warn("Ya existe: database/migrations/{$filename}");
            return 0;
        }

        $date      = date('Y-m-d H:i:s');
        $tableName = $this->guessTableName($name);
        $stub      = $this->buildStub($name, $tableName, $date);

        file_put_contents($targetFile, $stub);

        App::success("Migración creada: database/migrations/{$filename}");
        if ($tableName !== '') {
            App::info("Tabla detectada:  {$tableName}  (edita el SQL según necesites)");
        }
        return 0;
    }

    /**
     * Determina el siguiente número de migración escaneando los existentes.
     */
    private function nextMigrationNumber(string $dir): int
    {
        $files = glob($dir . '/*.sql') ?: [];
        if (empty($files)) {
            return 1;
        }

        $max = 0;
        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^(\d+)_/', $base, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $max + 1;
    }

    /**
     * Intenta deducir el nombre de la tabla a partir del nombre de la migración.
     * create_productos_table → productos
     * add_precio_to_productos → productos
     * alter_usuarios_add_avatar → usuarios
     */
    private function guessTableName(string $name): string
    {
        // create_X_table
        if (preg_match('/^create_(.+)_table$/', $name, $m)) {
            return $m[1];
        }
        // add/drop_X_to/from_Y
        if (preg_match('/(?:_to_|_from_|_in_)(.+)$/', $name, $m)) {
            return $m[1];
        }
        // alter_X_...
        if (preg_match('/^alter_([^_]+)/', $name, $m)) {
            return $m[1];
        }

        return '';
    }

    private function buildStub(string $name, string $tableName, string $date): string
    {
        $isCreate = str_starts_with($name, 'create_');

        if ($isCreate && $tableName !== '') {
            return <<<SQL
            -- Migración: {$name}
            -- Creada:    {$date}

            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            SQL;
        }

        // ALTER / genérica
        $tableHint = $tableName !== '' ? "`{$tableName}`" : '`nombre_tabla`';

        return <<<SQL
        -- Migración: {$name}
        -- Creada:    {$date}

        ALTER TABLE {$tableHint}
            ADD COLUMN `nueva_columna` VARCHAR(255) NULL DEFAULT NULL AFTER `id`;

        SQL;
    }
}
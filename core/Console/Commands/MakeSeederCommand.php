<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeSeederCommand — genera un stub de seeder SQL o PHP.
 *
 * Uso:
 *   php bin/szm make:seeder productos         → 004_productos.sql
 *   php bin/szm make:seeder AdminUser --php   → 004_admin_user.php
 *
 * El número de secuencia se auto-detecta a partir del último archivo en
 * database/seeds/.
 */
final class MakeSeederCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un seeder en database/seeds/';
    }

    public function usage(): string
    {
        return 'make:seeder <nombre> [--php]';
    }

    public function handle(array $args): int
    {
        $name = trim($args[0] ?? '');

        if ($name === '') {
            App::err('Especifica el nombre del seeder.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:seeder productos'));
            App::out('       ' . App::cyan('php bin/szm make:seeder AdminUser --php'));
            return 1;
        }

        $isPhp = in_array('--php', $args, true);

        // Convertir a snake_case
        $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug);

        $seedsDir = $this->basePath . '/database/seeds';
        $next     = $this->nextNumber($seedsDir);
        $ext      = $isPhp ? 'php' : 'sql';
        $filename = sprintf('%03d_%s.%s', $next, $slug, $ext);
        $target   = $seedsDir . '/' . $filename;

        if (file_exists($target)) {
            App::warn("Ya existe: database/seeds/{$filename}");
            return 0;
        }

        $stub = $isPhp ? $this->phpStub($name) : $this->sqlStub($slug);

        file_put_contents($target, $stub . PHP_EOL);
        App::success("Seeder creado: database/seeds/{$filename}");

        if ($isPhp) {
            App::info('Recuerda: los seeders PHP son interactivos. Usa readline() o fgets(STDIN).');
        }

        return 0;
    }

    // ── Stubs ─────────────────────────────────────────────────────────────

    private function sqlStub(string $table): string
    {
        return <<<SQL
        -- Seeder: {$table}
        -- Insertado por: php bin/szm make:seeder {$table}

        INSERT INTO `{$table}` (/* columnas */) VALUES
            (/* valores */);
        SQL;
    }

    private function phpStub(string $name): string
    {
        return <<<PHP
        <?php

        /**
         * Seeder: {$name}
         *
         * Este archivo se ejecuta como proceso interactivo.
         * Carga de Eloquent y PDO ya están disponibles aquí.
         *
         * Variables disponibles:
         *   \$pdo  → instancia de PDO
         */

        // Ejemplo:
        // \$name  = readline("Nombre: ");
        // \$email = readline("Email: ");
        // \$hash  = password_hash(readline("Contraseña: "), PASSWORD_BCRYPT, ['cost' => 12]);
        //
        // \$pdo->prepare("INSERT INTO szm_users (name, email, password) VALUES (?,?,?)")
        //     ->execute([\$name, \$email, \$hash]);
        //
        // echo "Registro creado correctamente.\n";
        PHP;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function nextNumber(string $dir): int
    {
        $files = glob($dir . '/[0-9][0-9][0-9]_*.{sql,php}', GLOB_BRACE) ?: [];

        if (empty($files)) {
            return 1;
        }

        $last = max(array_map(
            fn($f) => (int) basename($f),
            $files,
        ));

        return $last + 1;
    }
}
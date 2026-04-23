<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeModelCommand — genera un stub de modelo Eloquent.
 *
 * Uso:
 *   php bin/szm make:model Producto
 *   php bin/szm make:model OrdenCompra
 *
 * El nombre de tabla se deriva automáticamente convirtiendo PascalCase
 * a snake_case y pluralizando (pluralización simple en inglés/español).
 */
final class MakeModelCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un nuevo modelo Eloquent en app/Models/';
    }

    public function usage(): string
    {
        return 'make:model <Nombre>';
    }

    public function handle(array $args): int
    {
        $name = trim($args[0] ?? '');

        if ($name === '') {
            App::err('Especifica el nombre del modelo.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:model Producto'));
            return 1;
        }

        // Solo el nombre de la clase (PascalCase), sin slashes
        $class = ucfirst(str_replace(['/', '\\'], '', $name));

        $targetFile = $this->basePath . '/app/Models/' . $class . '.php';

        if (file_exists($targetFile)) {
            App::warn("Ya existe: app/Models/{$class}.php");
            return 0;
        }

        $table = $this->toSnakeCase($class);

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Models;

        /**
         * {$class}
         *
         * @property int    \$id
         * @property string \$created_at
         * @property string \$updated_at
         */
        final class {$class} extends BaseModel
        {
            protected \$table = '{$table}';

            protected \$fillable = [
                // Define aquí los campos asignables en masa
            ];

            protected \$casts = [
                // Ejemplo: 'activo' => 'boolean',
            ];
        }
        PHP;

        file_put_contents($targetFile, $stub . PHP_EOL);

        App::success("Modelo creado:      app/Models/{$class}.php");
        App::info("Tabla sugerida:     {$table}");
        App::info("Crea la migración:  php bin/szm make:migration create_{$table}_table");
        return 0;
    }

    /**
     * Convierte PascalCase a snake_case.
     * OrdenCompra → orden_compra
     */
    private function toSnakeCase(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}

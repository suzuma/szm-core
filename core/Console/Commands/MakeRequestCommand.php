<?php

declare(strict_types=1);

namespace Core\Console\Commands;

use Core\Console\CommandInterface;
use Core\Console\ConsoleApplication as App;

/**
 * MakeRequestCommand — genera un stub de FormRequest.
 *
 * Uso:
 *   php bin/szm make:request StoreProducto
 *   php bin/szm make:request UpdateProducto
 *
 * Genera: app/Http/Requests/StoreProductoRequest.php
 */
final class MakeRequestCommand implements CommandInterface
{
    public function __construct(private readonly string $basePath) {}

    public function description(): string
    {
        return 'Genera un FormRequest en app/Http/Requests/';
    }

    public function usage(): string
    {
        return 'make:request <Nombre>';
    }

    public function handle(array $args): int
    {
        $name = trim($args[0] ?? '');

        if ($name === '') {
            App::err('Especifica el nombre del request.');
            App::out('  Uso: ' . App::cyan('php bin/szm make:request StoreProducto'));
            return 1;
        }

        // Quitar sufijo "Request" si el usuario lo escribió, luego re-añadir
        $base   = preg_replace('/Request$/i', '', $name);
        $class  = ucfirst($base) . 'Request';
        $target = $this->basePath . '/app/Http/Requests/' . $class . '.php';

        if (file_exists($target)) {
            App::warn("Ya existe: app/Http/Requests/{$class}.php");
            return 0;
        }

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Http\Requests;

        use App\Http\FormRequest;

        /**
         * {$class}
         */
        final class {$class} extends FormRequest
        {
            public function rules(): array
            {
                return [
                    // 'campo' => ['required', 'max:255'],
                ];
            }

            public function messages(): array
            {
                return [
                    // 'campo.required' => 'El campo es requerido.',
                ];
            }
        }
        PHP;

        file_put_contents($target, $stub . PHP_EOL);

        App::success("Request creado: app/Http/Requests/{$class}.php");
        return 0;
    }
}
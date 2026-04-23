<?php

declare(strict_types=1);

namespace Core\Console;

/**
 * CommandInterface — contrato para los comandos del CLI szm.
 *
 * Cada comando recibe los argumentos posicionales y flags que
 * el usuario escribió después del nombre del comando, y retorna
 * un código de salida (0 = éxito, distinto de 0 = error).
 */
interface CommandInterface
{
    /**
     * Ejecuta el comando.
     *
     * @param string[] $args  Argumentos después del nombre del comando
     * @return int            Código de salida: 0 = ok, 1 = error
     */
    public function handle(array $args): int;

    /** Descripción corta mostrada en `szm list`. */
    public function description(): string;

    /** Uso con parámetros mostrado en `szm list`. */
    public function usage(): string;
}
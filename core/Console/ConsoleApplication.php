<?php

declare(strict_types=1);

namespace Core\Console;

use Core\Console\Commands\MakeControllerCommand;
use Core\Console\Commands\MakeMigrationCommand;
use Core\Console\Commands\MakeModelCommand;
use Core\Console\Commands\MigrateCommand;

/**
 * ConsoleApplication — dispatcher del CLI szm.
 *
 * Registra los comandos disponibles, parsea argv y delega la
 * ejecución al comando correspondiente.
 *
 * Uso:
 *   $app = new ConsoleApplication('/ruta/al/proyecto');
 *   exit($app->run($argv));
 */
final class ConsoleApplication
{
    /** @var array<string, class-string<CommandInterface>> */
    private array $commands = [
        'migrate'         => MigrateCommand::class,
        'make:controller' => MakeControllerCommand::class,
        'make:model'      => MakeModelCommand::class,
        'make:migration'  => MakeMigrationCommand::class,
    ];

    public function __construct(private readonly string $basePath) {}

    /**
     * Punto de entrada principal.
     *
     * @param string[] $argv  Argumentos del proceso (incluye el nombre del script en [0])
     * @return int            Código de salida
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'list';
        $args    = array_slice($argv, 2);

        if ($command === 'list' || $command === '--help' || $command === '-h') {
            $this->showHelp();
            return 0;
        }

        if (!isset($this->commands[$command])) {
            self::err("Comando desconocido: '{$command}'");
            self::out("Usa " . self::cyan('php bin/szm list') . " para ver los comandos disponibles.");
            return 1;
        }

        $class   = $this->commands[$command];
        $handler = new $class($this->basePath);

        return $handler->handle($args);
    }

    // ── Salida ────────────────────────────────────────────────────────────────

    private function showHelp(): void
    {
        self::out('');
        self::out(self::bold('SZM Framework CLI'));
        self::out('');
        self::out('Uso: ' . self::cyan('php bin/szm') . ' <comando> [opciones]');
        self::out('');
        self::out(self::bold('Comandos disponibles:'));
        self::out('');

        foreach ($this->commands as $name => $class) {
            /** @var CommandInterface $cmd */
            $cmd  = new $class($this->basePath);
            $pad  = str_pad($cmd->usage(), 38);
            self::out('  ' . self::green($pad) . ' ' . $cmd->description());
        }

        self::out('');
        self::out(self::bold('Ejemplos:'));
        self::out('  ' . self::cyan('php bin/szm migrate'));
        self::out('  ' . self::cyan('php bin/szm migrate --fresh'));
        self::out('  ' . self::cyan('php bin/szm migrate --no-seed'));
        self::out('  ' . self::cyan('php bin/szm make:controller Dashboard'));
        self::out('  ' . self::cyan('php bin/szm make:model Producto'));
        self::out('  ' . self::cyan('php bin/szm make:migration create_productos_table'));
        self::out('');
    }

    // ── Helpers de salida (estáticos — disponibles en comandos via trait o herencia) ──

    public static function out(string $msg): void
    {
        echo $msg . PHP_EOL;
    }

    public static function err(string $msg): void
    {
        fwrite(STDERR, self::red('ERROR') . ' ' . $msg . PHP_EOL);
    }

    public static function success(string $msg): void
    {
        self::out(self::green('✓') . ' ' . $msg);
    }

    public static function info(string $msg): void
    {
        self::out(self::cyan('→') . ' ' . $msg);
    }

    public static function warn(string $msg): void
    {
        self::out(self::yellow('⚠') . ' ' . $msg);
    }

    // ── ANSI color helpers ─────────────────────────────────────────────────────

    public static function green(string $t): string  { return "\033[32m{$t}\033[0m"; }
    public static function red(string $t): string    { return "\033[31m{$t}\033[0m"; }
    public static function yellow(string $t): string { return "\033[33m{$t}\033[0m"; }
    public static function cyan(string $t): string   { return "\033[36m{$t}\033[0m"; }
    public static function gray(string $t): string   { return "\033[90m{$t}\033[0m"; }
    public static function bold(string $t): string   { return "\033[1m{$t}\033[0m"; }
}
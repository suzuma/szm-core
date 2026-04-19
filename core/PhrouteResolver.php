<?php

declare(strict_types=1);

namespace Core;

use Core\ServicesContainer;
use Phroute\Phroute\HandlerResolverInterface;
/**
 * PhrouteResolver
 *
 * Le indica a Phroute cómo instanciar los controladores
 * cuando recibe un handler como 'App\Controllers\HomeController@index'.
 *
 * Implementa HandlerResolverInterface que Phroute exige.
 * Si el controlador está registrado en ServicesContainer lo obtiene
 * de ahí (con sus dependencias inyectadas), si no lo instancia directo.
 */
final class PhrouteResolver implements HandlerResolverInterface
{
    /**
     * Resuelve el handler de la ruta.
     *
     * Phroute pasa el handler como:
     *   - array:  [ClaseString, 'metodo']  → más común
     *   - object: instancia ya construida
     *   - string: 'ClaseString'
     *
     * Debe retornar [instancia, 'metodo'] para que Phroute lo invoque.
     *
     * @param  mixed $handler
     * @return array|callable
     */
    public function resolve($handler): mixed
    {
        // Caso más común — Phroute pasa [ClaseString, 'metodo']
        if (is_array($handler)) {
            [$class, $method] = $handler;

            $instance = $this->instantiate($class);

            return [$instance, $method];
        }

        // Handler ya es un objeto instanciado
        if (is_object($handler)) {
            return $handler;
        }

        // Handler es un string — solo la clase sin método
        if (is_string($handler)) {
            return $this->instantiate($handler);
        }

        throw new \RuntimeException(
            'PhrouteResolver: formato de handler no soportado.'
        );
    }

    /**
     * Instancia una clase — primero busca en el contenedor,
     * si no la instancia directamente.
     */
    private function instantiate(string $class): object
    {
        if (ServicesContainer::has($class)) {
            return ServicesContainer::get($class);
        }

        if (class_exists($class)) {
            return new $class();
        }

        throw new \RuntimeException(
            "PhrouteResolver: Clase no encontrada [{$class}]."
        );
    }
}
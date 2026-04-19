<?php

declare(strict_types=1);

namespace Core\Events;

/**
 * EventDispatcher — bus de eventos simple (pub/sub en memoria).
 *
 * Sin dependencias externas: los listeners se registran como callables
 * y se ejecutan sincrónicamente al despachar.
 *
 * Uso en providers.php (registro):
 *   use App\Events\UserLoggedIn;
 *   use Core\Events\EventDispatcher;
 *
 *   EventDispatcher::listen(UserLoggedIn::class, function (UserLoggedIn $e): void {
 *       Log::channel('audit')->info('login', ['user_id' => $e->user->id]);
 *   });
 *
 * Uso en servicios (despacho):
 *   EventDispatcher::dispatch(new UserLoggedIn($user));
 */
final class EventDispatcher
{
    /** @var array<class-string, list<callable>> */
    private static array $listeners = [];

    private function __construct() {}

    /**
     * Registra un listener para un tipo de evento.
     * Se pueden registrar múltiples listeners por evento.
     *
     * @param class-string $eventClass FQCN del evento
     */
    public static function listen(string $eventClass, callable $listener): void
    {
        self::$listeners[$eventClass][] = $listener;
    }

    /**
     * Despacha un evento a todos sus listeners en el orden de registro.
     * Si un listener lanza una excepción, los siguientes no se ejecutan.
     */
    public static function dispatch(object $event): void
    {
        $class = get_class($event);

        foreach (self::$listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }

    /**
     * Elimina todos los listeners de un evento.
     * Útil en tests para aislar comportamiento.
     */
    public static function forget(string $eventClass): void
    {
        unset(self::$listeners[$eventClass]);
    }

    /**
     * Lista los eventos que tienen listeners registrados.
     *
     * @return class-string[]
     */
    public static function registered(): array
    {
        return array_keys(self::$listeners);
    }
}
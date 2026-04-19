<?php

declare(strict_types=1);

namespace Core\Auth;

use App\Models\User;
use Core\Security\Session;

/**
 * Auth
 *
 * Fachada estática para acceder al usuario autenticado
 * desde cualquier parte de la aplicación.
 *
 * Uso:
 *   Auth::check()           → bool
 *   Auth::user()            → User|null
 *   Auth::id()              → int|null
 *   Auth::role()            → string
 *   Auth::can('users.edit') → bool
 *   Auth::login($user)      → void
 *   Auth::logout()          → void
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    /** Cache del usuario en memoria para el request actual */
    private static ?User $cachedUser = null;

    private function __construct() {}

    /* ------------------------------------------------------------------
     | Estado
     ------------------------------------------------------------------ */

    public static function check(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /* ------------------------------------------------------------------
     | Usuario autenticado
     ------------------------------------------------------------------ */

    public static function user(): ?User
    {
        if (!self::check()) {
            return null;
        }

        // Cache en memoria — evita N queries por request
        if (self::$cachedUser === null) {
            self::$cachedUser = User::with(['role.permissions'])
                ->find(Session::get(self::SESSION_KEY));
        }

        return self::$cachedUser;
    }

    public static function id(): ?int
    {
        return Session::get(self::SESSION_KEY);
    }

    public static function role(): string
    {
        return self::user()?->role?->name ?? 'guest';
    }

    /* ------------------------------------------------------------------
     | Permisos
     ------------------------------------------------------------------ */

    /**
     * Verifica si el usuario autenticado tiene un permiso.
     *
     * Ejemplo:
     *   Auth::can('users.edit')
     *   Auth::can('posts.delete')
     */
    public static function can(string $permission): bool
    {
        $user = self::user();

        if ($user === null) {
            return false;
        }

        // Admin tiene todos los permisos
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role?->hasPermission($permission) ?? false;
    }

    public static function cannot(string $permission): bool
    {
        return !self::can($permission);
    }

    /* ------------------------------------------------------------------
     | Login / Logout
     ------------------------------------------------------------------ */

    /**
     * Inicia sesión para el usuario dado.
     * Regenera el ID de sesión para prevenir session fixation.
     */
    public static function login(User $user): void
    {
        Session::regenerate();
        Session::put([
            self::SESSION_KEY => $user->id,
            'user_role'       => $user->role?->name ?? 'user',
            'user'            => $user->toArray(),
        ]);

        self::$cachedUser = $user;
    }

    /**
     * Cierra la sesión y limpia el cache.
     */
    public static function logout(): void
    {
        self::$cachedUser = null;
        Session::destroy();
    }
}
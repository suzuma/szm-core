<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\PasswordResetRequested;
use App\Events\UserLoggedIn;
use App\Models\User;
use Core\Auth\Auth;
use Core\Events\EventDispatcher;

/**
 * AuthService — lógica de negocio del ciclo de autenticación.
 *
 * El AuthController delega aquí todas las reglas; él solo orquesta
 * input/output HTTP. Esto hace que la lógica sea reutilizable y testeable
 * sin depender del contexto HTTP.
 *
 * Los proyectos pueden extender este servicio para agregar pasos extra
 * (2FA, auditoría extendida, notificaciones push, etc.) y registrar la
 * subclase en providers.php:
 *
 *   ServicesContainer::bind(AuthService::class, fn() => new MyAuthService());
 */
class AuthService
{
    /**
     * Autentica al usuario con email + contraseña.
     *
     * En caso de fallo, lanza \RuntimeException con el mensaje de error
     * listo para mostrar al usuario (sin revelar información sensible).
     *
     * Tras el login exitoso despacha UserLoggedIn.
     *
     * @throws \RuntimeException
     */
    public function attemptLogin(string $email, string $password): User
    {
        $user = User::with('role.permissions')
            ->where('email', $email)
            ->first();

        if ($user === null) {
            throw new \RuntimeException('Credenciales incorrectas.');
        }

        if (!$user->isActive()) {
            throw new \RuntimeException('Tu cuenta está desactivada. Contacta al administrador.');
        }

        if ($user->isLocked()) {
            throw new \RuntimeException('Cuenta bloqueada temporalmente. Intenta de nuevo en unos minutos.');
        }

        if (!$user->verifyPassword($password)) {
            $user->recordFailedAttempt();
            throw new \RuntimeException('Credenciales incorrectas.');
        }

        $user->clearFailedAttempts();
        Auth::login($user);

        EventDispatcher::dispatch(new UserLoggedIn($user));

        return $user;
    }

    /**
     * Genera un token de recuperación para el email indicado.
     *
     * Siempre retorna sin excepción aunque el email no exista
     * (no revela qué emails están registrados — previene user enumeration).
     *
     * Tras generar el token despacha PasswordResetRequested para que el
     * proyecto envíe el email con el enlace.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user === null || !$user->isActive()) {
            return;
        }

        $token = $user->generatePasswordResetToken();

        EventDispatcher::dispatch(new PasswordResetRequested($user, $token));
    }

    /**
     * Aplica una nueva contraseña usando el token de recuperación.
     *
     * @throws \RuntimeException si el token es inválido o ya expiró
     */
    public function resetPassword(string $token, string $password): void
    {
        // Valida formato antes de consultar la DB (evita queries con tokens malformados)
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            throw new \RuntimeException('El enlace de recuperación es inválido o ha expirado.');
        }

        $hashed = hash('sha256', $token);
        $user   = User::where('reset_token', $hashed)->first();

        if ($user === null || !$user->isValidResetToken($token)) {
            throw new \RuntimeException('El enlace de recuperación es inválido o ha expirado.');
        }

        // BCrypt procesa solo los primeros 72 bytes; se trunca explícitamente
        // para que contraseñas largas no den falsa sensación de seguridad.
        $user->update([
            'password'            => password_hash(substr($password, 0, 72), PASSWORD_BCRYPT, ['cost' => 12]),
            'reset_token'         => null,
            'reset_token_expires' => null,
            'failed_attempts'     => 0,
            'locked_until'        => null,
        ]);
    }

    /**
     * Busca un usuario por reset token hasheado y valida que no haya expirado.
     * Usado por el controlador para mostrar el formulario de reset.
     */
    public function findUserByResetToken(string $token): ?User
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $hashed = hash('sha256', $token);
        $user   = User::where('reset_token', $hashed)->first();

        if ($user === null || !$user->isValidResetToken($token)) {
            return null;
        }

        return $user;
    }
}
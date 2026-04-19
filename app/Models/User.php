<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User — modelo de autenticación del núcleo.
 *
 * Solo contiene los campos mínimos para auth + RBAC.
 * Los proyectos extienden este modelo para agregar
 * columnas de perfil, área, puesto, etc.
 *
 * Tabla: szm_users (migración create_auth_tables)
 *
 * @property int          $id
 * @property int          $role_id
 * @property string       $name
 * @property string       $email
 * @property string       $password
 * @property bool         $active
 * @property int          $failed_attempts
 * @property Carbon|null  $locked_until
 * @property string|null  $reset_token
 * @property Carbon|null  $reset_token_expires
 * @property string|null  $last_login_ip
 * @property-read Role|null $role
 */
class User extends BaseModel
{
    protected $table = 'szm_users';

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'active',
        'failed_attempts',
        'locked_until',
        'last_login_at',
        'last_login_ip',
        'reset_token',
        'reset_token_expires',
    ];

    protected $hidden = ['password', 'reset_token', 'reset_token_expires'];

    protected $casts = [
        'active'              => 'boolean',
        'failed_attempts'     => 'integer',
        'locked_until'        => 'datetime',
        'reset_token_expires' => 'datetime',
        'last_login_at'       => 'datetime',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    // ── Auth ──────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role?->name === 'admin';
    }

    public function isActive(): bool
    {
        return $this->active === true;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, $this->password);
    }

    public function hasRole(string $role): bool
    {
        return $this->role?->name === $role;
    }

    /** Incrementa intentos fallidos y bloquea a los 5 intentos (30 min). */
    public function recordFailedAttempt(): void
    {
        $this->increment('failed_attempts');

        if ($this->failed_attempts >= 5) {
            $this->update(['locked_until' => Carbon::now()->addMinutes(30)]);
        }
    }

    /** Limpia bloqueo y registra IP + timestamp del login exitoso. */
    public function clearFailedAttempts(): void
    {
        $this->update([
            'failed_attempts' => 0,
            'locked_until'    => null,
            'last_login_at'   => Carbon::now(),
            'last_login_ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /**
     * Genera token de recuperación de contraseña (válido 60 min).
     * Almacena el hash SHA-256; retorna el token en texto plano para el email.
     */
    public function generatePasswordResetToken(): string
    {
        $token = bin2hex(random_bytes(32));

        $this->update([
            'reset_token'        => hash('sha256', $token),
            'reset_token_expires' => Carbon::now()->addMinutes(60),
        ]);

        return $token;
    }

    /** Valida token de recuperación con comparación segura en tiempo constante. */
    public function isValidResetToken(string $token): bool
    {
        if ($this->reset_token === null || $this->reset_token_expires === null) {
            return false;
        }

        return hash_equals($this->reset_token, hash('sha256', $token))
            && $this->reset_token_expires->isFuture();
    }
}
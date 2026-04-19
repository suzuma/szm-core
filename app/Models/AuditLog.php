<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AuditLog — registro de auditoría de acciones críticas.
 *
 * Tabla: szm_audit_log
 *
 * Migración mínima:
 * -----------------------------------------------------------------------
 * CREATE TABLE szm_audit_log (
 *     id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 *     user_id     INT UNSIGNED NULL,
 *     action      VARCHAR(100)  NOT NULL,
 *     entity_type VARCHAR(150)  NULL,
 *     entity_id   INT UNSIGNED  NULL,
 *     old_values  JSON          NULL,
 *     new_values  JSON          NULL,
 *     ip          VARCHAR(45)   NULL,
 *     user_agent  VARCHAR(500)  NULL,
 *     created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP,
 *
 *     INDEX (user_id),
 *     INDEX (action),
 *     INDEX (entity_type, entity_id),
 *     INDEX (created_at)
 * );
 * -----------------------------------------------------------------------
 *
 * Acciones recomendadas:
 *   'user.login'             — inicio de sesión exitoso
 *   'user.logout'            — cierre de sesión
 *   'user.login_failed'      — intento fallido
 *   'user.locked'            — cuenta bloqueada por intentos fallidos
 *   'password.reset_request' — solicitud de recuperación
 *   'password.reset'         — contraseña restablecida
 *   'model.created'          — registro creado (trait Auditable)
 *   'model.updated'          — registro modificado (trait Auditable)
 *   'model.deleted'          — registro eliminado (trait Auditable)
 */
class AuditLog extends BaseModel
{
    protected $table = 'szm_audit_log';

    /** No usar timestamps gestionados por Eloquent; created_at lo define MySQL. */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    // ── Relación ──────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers de escritura ──────────────────────────────────────────────

    /**
     * Registra una acción en el log de auditoría.
     *
     * Falla silenciosamente — el log nunca debe interrumpir el flujo principal.
     *
     * Uso:
     *   AuditLog::record('user.login', user: $user);
     *   AuditLog::record('model.updated', entity: $model, old: $model->getOriginal());
     *
     * @param string       $action     Clave de acción (ej. 'user.login')
     * @param object|null  $entity     Modelo u objeto afectado
     * @param array        $old        Valores anteriores al cambio
     * @param array        $new        Valores nuevos tras el cambio
     * @param int|null     $userId     ID del usuario que realiza la acción
     */
    public static function record(
        string  $action,
        ?object $entity  = null,
        array   $old     = [],
        array   $new     = [],
        ?int    $userId  = null,
    ): void {
        try {
            static::create([
                'user_id'     => $userId ?? ($_SESSION['user_id'] ?? null),
                'action'      => $action,
                'entity_type' => $entity ? get_class($entity) : null,
                'entity_id'   => $entity?->id ?? null,
                'old_values'  => $old ?: null,
                'new_values'  => $new ?: null,
                'ip'          => self::resolveIp(),
                'user_agent'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            ]);
        } catch (\Throwable) {
            // Silencioso — el log no debe romper el flujo principal
        }
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeForEntity($query, string $type, int $id)
    {
        return $query->where('entity_type', $type)->where('entity_id', $id);
    }

    // ── Helper privado ────────────────────────────────────────────────────

    private static function resolveIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Permission
 *
 * Permiso atómico del sistema (ej. 'users.edit', 'reports.view').
 * Los permisos se agrupan por módulo y se asignan a roles.
 *
 * Tabla: szm_permissions
 *
 * @property int    $id
 * @property string $name   Slug único: 'module.action'
 * @property string $label  Etiqueta legible para humanos
 * @property string $group  Módulo: 'users', 'reports', 'personal'...
 */
class Permission extends BaseModel
{
    protected $table = 'szm_permissions';

    protected $fillable = ['name', 'label', 'group'];

    // ── Relaciones ────────────────────────────────────────────────────────

    /** Roles que tienen asignado este permiso */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'szm_role_permissions',
            'permission_id',
            'role_id'
        );
    }
}
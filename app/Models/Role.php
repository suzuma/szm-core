<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Role
 *
 * Agrupa permisos y se asigna a los usuarios.
 * El rol 'admin' tiene bypass total (chequeado en Auth::can()).
 *
 * Tabla: szm_roles
 *
 * @property int    $id
 * @property string $name   Slug del rol: 'admin', 'user', 'rh', 'docente'...
 * @property string $label  Etiqueta legible: 'Administrador', 'Recursos Humanos'...
 */
class Role extends BaseModel
{
    protected $table = 'szm_roles';

    protected $fillable = ['name', 'label'];

    // ── Relaciones ────────────────────────────────────────────────────────

    /** Permisos asignados a este rol (via szm_role_permissions) */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'szm_role_permissions',
            'role_id',
            'permission_id'
        );
    }

    /** Usuarios que pertenecen a este rol */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id');
    }

    // ── Lógica de dominio ─────────────────────────────────────────────────

    /**
     * Verifica si el rol tiene un permiso por nombre.
     * Aprovecha la colección en memoria si ya fue eager-loaded.
     *
     * Ejemplo: $role->hasPermission('users.edit')
     */
    public function hasPermission(string $name): bool
    {
        if ($this->relationLoaded('permissions')) {
            return $this->permissions->contains('name', $name);
        }

        return $this->permissions()->where('name', $name)->exists();
    }
}
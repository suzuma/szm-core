<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PasswordHistory — registro de contraseñas anteriores por usuario.
 *
 * Usada por AuthService y UserController para evitar que el usuario
 * reutilice contraseñas recientes al hacer un reset o cambio.
 *
 * Solo se almacenan hashes bcrypt — nunca texto plano.
 *
 * Uso:
 *   // Verificar si una contraseña en texto plano coincide con algún historial
 *   PasswordHistory::matchesRecent($userId, $plainPassword, last: 5);
 *
 *   // Registrar la contraseña actual antes de cambiarla
 *   PasswordHistory::record($userId, $currentHash);
 */
class PasswordHistory extends Model
{
    protected $table      = 'szm_password_history';
    public    $timestamps = false;

    protected $fillable = ['user_id', 'password_hash', 'created_at'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Helpers estáticos ────────────────────────────────────────────────

    /**
     * Verifica si $plainPassword coincide con alguna de las últimas $last
     * contraseñas del usuario.
     *
     * @param  int    $userId        ID del usuario.
     * @param  string $plainPassword Contraseña en texto plano a verificar.
     * @param  int    $last          Cuántas entradas recientes revisar (default: 5).
     * @return bool                  true si hay coincidencia (contraseña reutilizada).
     */
    public static function matchesRecent(int $userId, string $plainPassword, int $last = 5): bool
    {
        $hashes = self::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($last)
            ->pluck('password_hash');

        foreach ($hashes as $hash) {
            if (password_verify($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Guarda el hash actual en el historial antes de reemplazarlo.
     *
     * Debe llamarse con el hash VIGENTE antes de aplicar el nuevo.
     *
     * @param int    $userId       ID del usuario.
     * @param string $currentHash  Hash bcrypt almacenado actualmente en szm_users.
     */
    public static function record(int $userId, string $currentHash): void
    {
        self::create([
            'user_id'       => $userId,
            'password_hash' => $currentHash,
            'created_at'    => now(),
        ]);
    }
}
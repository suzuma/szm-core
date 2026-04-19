<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Core\Auth\Auth;

/**
 * UserController — CRUD de usuarios.
 *
 * Todas las acciones de escritura responden en JSON (AJAX).
 * Cada mutación registra en szm_audit_log con old/new values para diff.
 *
 * Campos excluidos del audit log por seguridad:
 *   password, reset_token, reset_token_expires
 */
class UserController extends BaseController
{
    private const PER_PAGE = 20;

    /** Campos que nunca deben aparecer en el audit log. */
    private const AUDIT_EXCLUDE = ['password', 'reset_token', 'reset_token_expires'];

    // ── Listado ───────────────────────────────────────────────────────────

    public function index(): string
    {
        $req    = $this->request();
        $search = trim($req->str('q'));
        $roleId = $req->int('role_id') ?: null;
        $status = $req->str('status');        // 'active' | 'inactive' | ''
        $page   = max(1, $req->int('page', 1));

        $query = User::with('role')->orderBy('name');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($roleId !== null) {
            $query->where('role_id', $roleId);
        }

        if ($status === 'active') {
            $query->where('active', true);
        } elseif ($status === 'inactive') {
            $query->where('active', false);
        }

        $total      = $query->count();
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $users      = $query->skip(($page - 1) * self::PER_PAGE)
                            ->take(self::PER_PAGE)
                            ->get();

        $roles = Role::orderBy('label')->get();

        return $this->view('users/index.twig', [
            'users'         => $users,
            'roles'         => $roles,
            'search'        => $search,
            'filter_role'   => $roleId,
            'filter_status' => $status,
            'page'          => $page,
            'per_page'      => self::PER_PAGE,
            'total'         => $total,
            'total_pages'   => $totalPages,
        ]);
    }

    // ── Crear ─────────────────────────────────────────────────────────────

    public function store(): never
    {
        $form = StoreUserRequest::fromRequest();

        if ($form->fails()) {
            $this->json(['ok' => false, 'errors' => $form->fieldErrors()], 422);
        }

        if (User::where('email', $form->str('email'))->exists()) {
            $this->json(['ok' => false, 'errors' => ['email' => 'Este correo ya está registrado.']], 422);
        }

        $user = User::create([
            'role_id'         => $form->int('role_id'),
            'name'            => $form->str('name'),
            'email'           => $form->str('email'),
            'password'        => password_hash($form->str('password'), PASSWORD_BCRYPT, ['cost' => 12]),
            'active'          => true,
            'failed_attempts' => 0,
        ]);

        AuditLog::record(
            action:  'user.created',
            entity:  $user,
            new:     $this->auditableAttributes($user),
            userId:  Auth::id(),
        );

        $this->json([
            'ok'   => true,
            'user' => $this->userPayload($user->load('role')),
        ], 201);
    }

    // ── Actualizar ────────────────────────────────────────────────────────

    public function update(int $id): never
    {
        $user = User::with('role')->find($id);

        if ($user === null) {
            $this->json(['ok' => false, 'message' => 'Usuario no encontrado.'], 404);
        }

        $form = UpdateUserRequest::fromRequest();

        if ($form->fails()) {
            $this->json(['ok' => false, 'errors' => $form->fieldErrors()], 422);
        }

        if (User::where('email', $form->str('email'))->where('id', '!=', $id)->exists()) {
            $this->json(['ok' => false, 'errors' => ['email' => 'Este correo ya está registrado.']], 422);
        }

        // Capturar estado anterior ANTES de mutar
        $oldValues = $this->auditableAttributes($user);

        $data = [
            'role_id' => $form->int('role_id'),
            'name'    => $form->str('name'),
            'email'   => $form->str('email'),
        ];

        $newPassword = trim($this->request()->str('password'));
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                $this->json(['ok' => false, 'errors' => ['password' => 'La contraseña debe tener al menos 8 caracteres.']], 422);
            }
            $data['password'] = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        }

        $user->update($data);

        // Solo registrar campos que realmente cambiaron
        $newValues = $this->auditableAttributes($user->fresh());
        $changed   = array_filter(
            $newValues,
            fn($v, $k) => ($oldValues[$k] ?? null) !== $v,
            ARRAY_FILTER_USE_BOTH
        );

        if (!empty($changed)) {
            $before = array_intersect_key($oldValues, $changed);
            AuditLog::record(
                action:  'user.updated',
                entity:  $user,
                old:     $before,
                new:     $changed,
                userId:  Auth::id(),
            );
        }

        // Si cambió la contraseña registrar sin exponer el hash
        if ($newPassword !== '') {
            AuditLog::record(
                action:  'user.password_changed',
                entity:  $user,
                userId:  Auth::id(),
            );
        }

        $this->json([
            'ok'   => true,
            'user' => $this->userPayload($user->load('role')),
        ]);
    }

    // ── Activar / Desactivar ──────────────────────────────────────────────

    public function toggleActive(int $id): never
    {
        $user = User::find($id);

        if ($user === null) {
            $this->json(['ok' => false, 'message' => 'Usuario no encontrado.'], 404);
        }

        if ($user->id === Auth::id()) {
            $this->json(['ok' => false, 'message' => 'No puedes desactivar tu propia cuenta.'], 403);
        }

        $wasActive = (bool) $user->active;
        $user->update(['active' => !$wasActive]);

        AuditLog::record(
            action:  $wasActive ? 'user.deactivated' : 'user.activated',
            entity:  $user,
            old:     ['active' => $wasActive],
            new:     ['active' => !$wasActive],
            userId:  Auth::id(),
        );

        $this->json(['ok' => true, 'active' => $user->active]);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────

    public function destroy(int $id): never
    {
        $user = User::find($id);

        if ($user === null) {
            $this->json(['ok' => false, 'message' => 'Usuario no encontrado.'], 404);
        }

        if ($user->id === Auth::id()) {
            $this->json(['ok' => false, 'message' => 'No puedes eliminar tu propia cuenta.'], 403);
        }

        // Capturar datos antes de eliminar para el log
        $snapshot = $this->auditableAttributes($user);

        AuditLog::record(
            action:  'user.deleted',
            entity:  $user,
            old:     $snapshot,
            userId:  Auth::id(),
        );

        $user->delete();

        $this->json(['ok' => true]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Atributos del usuario sin campos sensibles, listos para el audit log.
     */
    private function auditableAttributes(User $user): array
    {
        return array_diff_key(
            $user->getAttributes(),
            array_flip(self::AUDIT_EXCLUDE)
        );
    }

    private function userPayload(User $user): array
    {
        return [
            'id'         => $user->id,
            'name'       => $user->name,
            'email'      => $user->email,
            'role_id'    => $user->role_id,
            'role_label' => $user->role?->label ?? '—',
            'active'     => $user->active,
            'created_at' => $user->created_at?->format('d/m/Y'),
        ];
    }
}
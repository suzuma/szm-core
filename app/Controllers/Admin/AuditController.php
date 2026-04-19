<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AuditLog;

/**
 * AuditController — panel de auditoría para administradores.
 *
 *   GET /admin/audit-log → index()
 */
final class AuditController extends BaseController
{
    /**
     * Lista las últimas entradas del audit log con filtros opcionales.
     * Solo accesible para usuarios con rol 'admin' (verificado en filtro de ruta).
     */
    public function index(): string
    {
        $req    = $this->request();
        $action = $req->str('action');
        $userId = $req->int('user_id');
        $perPage = 50;
        $page    = max(1, $req->int('page', 1));

        $query = AuditLog::with('user')
            ->orderByDesc('created_at');

        if ($action !== '') {
            $query->forAction($action);
        }

        if ($userId > 0) {
            $query->forUser($userId);
        }

        $total   = (clone $query)->count();
        $logs    = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
        $actions = AuditLog::selectRaw('DISTINCT action')->orderBy('action')->pluck('action');

        return $this->view('admin/audit_log.twig', [
            'logs'        => $logs,
            'actions'     => $actions,
            'filter_action'  => $action,
            'filter_user_id' => $userId ?: '',
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }
}
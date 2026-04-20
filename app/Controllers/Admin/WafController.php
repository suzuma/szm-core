<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Helpers\Flash;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * WafController — Panel de monitoreo del WAF.
 *
 *   GET  /admin/waf                → index()
 *   GET  /admin/waf/blocked-ips   → blockedIps()
 *   GET  /admin/waf/attack-logs   → attackLogs()
 *   POST /admin/waf/unban/{id}    → unban()
 */
final class WafController extends BaseController
{
    // ── Overview ──────────────────────────────────────────────────────────────

    public function index(): string
    {
        $now       = new \DateTimeImmutable();
        $today     = $now->format('Y-m-d 00:00:00');
        $weekAgo   = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $dayAgo    = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        // KPIs ─────────────────────────────────────────────────────────────────
        $attacksToday = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $today)
            ->count();

        $attacksWeek = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $weekAgo)
            ->count();

        $bannedIps = Capsule::table('waf_blocked_ips_szm')
            ->where('is_banned', 1)
            ->where(function ($q) use ($now): void {
                $q->whereNull('ban_until')
                  ->orWhere('ban_until', '>', $now->format('Y-m-d H:i:s'));
            })
            ->count();

        $topRule = Capsule::table('waf_attack_logs_szm')
            ->select('rule_triggered', Capsule::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('rule_triggered')
            ->orderByDesc('total')
            ->first();

        // Timeline: ataques por hora en las últimas 24 h ───────────────────────
        $hourlyRows = Capsule::table('waf_attack_logs_szm')
            ->select(Capsule::raw('DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\') AS hour, COUNT(*) AS total'))
            ->where('created_at', '>=', $dayAgo)
            ->groupByRaw('DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\')')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // Rellenar las 24 horas aunque no haya ataques
        $timeline = [];
        for ($i = 23; $i >= 0; $i--) {
            $key           = $now->modify("-{$i} hours")->format('Y-m-d H:00:00');
            $label         = $now->modify("-{$i} hours")->format('H:00');
            $timeline[]    = [
                'hour'  => $label,
                'total' => (int) ($hourlyRows[$key]->total ?? 0),
            ];
        }

        // Distribución de reglas (últimos 7 días) ─────────────────────────────
        $rulesRaw = Capsule::table('waf_attack_logs_szm')
            ->select('rule_triggered', Capsule::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('rule_triggered')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $rulesMax   = $rulesRaw->max('total') ?: 1;
        $rules      = $rulesRaw->map(fn($r) => [
            'rule'    => $r->rule_triggered,
            'total'   => (int) $r->total,
            'percent' => (int) round($r->total / $rulesMax * 100),
        ])->all();

        // Top 5 IPs por risk_score ─────────────────────────────────────────────
        $topIps = Capsule::table('waf_blocked_ips_szm')
            ->orderByDesc('risk_score')
            ->limit(5)
            ->get(['ip_address', 'risk_score', 'country', 'is_banned', 'last_attempt']);

        return $this->view('admin/waf/index.twig', [
            'attacks_today' => $attacksToday,
            'attacks_week'  => $attacksWeek,
            'banned_ips'    => $bannedIps,
            'top_rule'      => $topRule?->rule_triggered ?? '—',
            'timeline'      => $timeline,
            'rules'         => $rules,
            'top_ips'       => $topIps,
        ]);
    }

    // ── IPs bloqueadas ────────────────────────────────────────────────────────

    public function blockedIps(): string
    {
        $req     = $this->request();
        $country = $req->str('country');
        $minScore = $req->int('min_score');
        $status  = $req->str('status');   // 'active' | 'expired' | ''
        $perPage = 50;
        $page    = max(1, $req->int('page', 1));
        $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $query = Capsule::table('waf_blocked_ips_szm')
            ->orderByDesc('risk_score');

        if ($country !== '') {
            $query->where('country', $country);
        }

        if ($minScore > 0) {
            $query->where('risk_score', '>=', $minScore);
        }

        if ($status === 'active') {
            $query->where('is_banned', 1)
                  ->where(fn($q) => $q->whereNull('ban_until')->orWhere('ban_until', '>', $now));
        } elseif ($status === 'expired') {
            $query->where(fn($q) => $q->where('is_banned', 0)->orWhere('ban_until', '<=', $now));
        }

        $total   = (clone $query)->count();
        $ips     = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Listado de países para el filtro
        $countries = Capsule::table('waf_blocked_ips_szm')
            ->select('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        return $this->view('admin/waf/blocked_ips.twig', [
            'ips'          => $ips,
            'countries'    => $countries,
            'filter_country'   => $country,
            'filter_min_score' => $minScore ?: '',
            'filter_status'    => $status,
            'page'         => $page,
            'per_page'     => $perPage,
            'total'        => $total,
            'total_pages'  => (int) ceil($total / $perPage),
        ]);
    }

    // ── Log de ataques ────────────────────────────────────────────────────────

    public function attackLogs(): string
    {
        $req      = $this->request();
        $rule     = $req->str('rule');
        $ip       = $req->str('ip');
        $dateFrom = $req->str('date_from');
        $dateTo   = $req->str('date_to');
        $perPage  = 50;
        $page     = max(1, $req->int('page', 1));

        $query = Capsule::table('waf_attack_logs_szm')
            ->orderByDesc('created_at');

        if ($rule !== '') {
            $query->where('rule_triggered', $rule);
        }

        if ($ip !== '') {
            $query->where('ip_address', 'like', '%' . $ip . '%');
        }

        if ($dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        }

        if ($dateTo !== '') {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $total = (clone $query)->count();
        $logs  = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        // Reglas disponibles para el filtro desplegable
        $rules = Capsule::table('waf_attack_logs_szm')
            ->select('rule_triggered')
            ->distinct()
            ->orderBy('rule_triggered')
            ->pluck('rule_triggered');

        return $this->view('admin/waf/attack_logs.twig', [
            'logs'        => $logs,
            'rules'       => $rules,
            'filter_rule'      => $rule,
            'filter_ip'        => $ip,
            'filter_date_from' => $dateFrom,
            'filter_date_to'   => $dateTo,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ]);
    }

    // ── Desbloquear IP ────────────────────────────────────────────────────────

    public function unban(int $id): never
    {
        $affected = Capsule::table('waf_blocked_ips_szm')
            ->where('id', $id)
            ->update([
                'is_banned'  => 0,
                'risk_score' => 0,
                'ban_until'  => null,
            ]);

        if ($affected === 0) {
            Flash::set('error', 'IP no encontrada o ya desbloqueada.');
        } else {
            Flash::set('success', 'IP desbloqueada correctamente.');
        }

        $this->back('/admin/waf/blocked-ips');
    }
}
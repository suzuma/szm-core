<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Helpers\Flash;
use Core\Security\CsrfToken;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * WafController — Panel de monitoreo del WAF.
 *
 *   GET  /admin/waf                          → index()
 *   GET  /admin/waf/blocked-ips              → blockedIps()
 *   GET  /admin/waf/attack-logs              → attackLogs()
 *   GET  /admin/waf/geo-map                  → geoMap()
 *   POST /admin/waf/unban/{id}               → unban()
 *   POST /admin/waf/sync-geo/{id}            → syncGeo()
 *   GET  /admin/waf/export/attack-logs       → exportAttackLogs()
 *   GET  /admin/waf/export/blocked-ips       → exportBlockedIps()
 */
final class WafController extends BaseController
{
    // ── Overview ──────────────────────────────────────────────────────────────

    public function index(): string
    {
        $now         = new \DateTimeImmutable();
        $nowStr      = $now->format('Y-m-d H:i:s');
        $today       = $now->format('Y-m-d 00:00:00');
        $yesterday   = $now->modify('-1 day')->format('Y-m-d 00:00:00');
        $yesterdayEnd= $now->modify('-1 day')->format('Y-m-d 23:59:59');
        $weekAgo     = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $twoWeeksAgo = $now->modify('-14 days')->format('Y-m-d H:i:s');
        $dayAgo      = $now->modify('-24 hours')->format('Y-m-d H:i:s');

        // KPIs actuales ────────────────────────────────────────────────────────
        $attacksToday = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $today)->count();

        $attacksWeek = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $weekAgo)->count();

        $bannedIps = Capsule::table('waf_blocked_ips_szm')
            ->where('is_banned', 1)
            ->where(fn($q) => $q->whereNull('ban_until')->orWhere('ban_until', '>', $nowStr))
            ->count();

        $topRule = Capsule::table('waf_attack_logs_szm')
            ->select('rule_triggered', Capsule::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('rule_triggered')->orderByDesc('total')->first();

        // Deltas comparativos ──────────────────────────────────────────────────
        $attacksYesterday = Capsule::table('waf_attack_logs_szm')
            ->whereBetween('created_at', [$yesterday, $yesterdayEnd])->count();

        $attacksLastWeek = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $twoWeeksAgo)
            ->where('created_at', '<', $weekAgo)->count();

        $bannedYesterday = Capsule::table('waf_blocked_ips_szm')
            ->where('is_banned', 1)->where('created_at', '<', $yesterday)->count();

        $deltaToday = $attacksYesterday > 0
            ? (int) round(($attacksToday - $attacksYesterday) / $attacksYesterday * 100)
            : ($attacksToday > 0 ? 100 : 0);

        $deltaWeek = $attacksLastWeek > 0
            ? (int) round(($attacksWeek - $attacksLastWeek) / $attacksLastWeek * 100)
            : ($attacksWeek > 0 ? 100 : 0);

        $deltaBanned = $bannedYesterday > 0
            ? (int) round(($bannedIps - $bannedYesterday) / $bannedYesterday * 100)
            : ($bannedIps > 0 ? 100 : 0);

        // Timeline: ataques por hora en las últimas 24 h ───────────────────────
        $hourlyRows = Capsule::table('waf_attack_logs_szm')
            ->select(Capsule::raw('DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\') AS hour, COUNT(*) AS total'))
            ->where('created_at', '>=', $dayAgo)
            ->groupByRaw('DATE_FORMAT(created_at, \'%Y-%m-%d %H:00:00\')')
            ->orderBy('hour')->get()->keyBy('hour');

        $timeline = [];
        for ($i = 23; $i >= 0; $i--) {
            $key        = $now->modify("-{$i} hours")->format('Y-m-d H:00:00');
            $label      = $now->modify("-{$i} hours")->format('H:00');
            $timeline[] = ['hour' => $label, 'total' => (int) ($hourlyRows[$key]->total ?? 0)];
        }

        // Distribución de reglas (últimos 7 días) ─────────────────────────────
        $rulesRaw = Capsule::table('waf_attack_logs_szm')
            ->select('rule_triggered', Capsule::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $weekAgo)
            ->groupBy('rule_triggered')->orderByDesc('total')->limit(10)->get();

        $rulesMax = $rulesRaw->max('total') ?: 1;
        $rules    = $rulesRaw->map(fn($r) => [
            'rule'    => $r->rule_triggered,
            'total'   => (int) $r->total,
            'percent' => (int) round($r->total / $rulesMax * 100),
        ])->all();

        // Top 5 IPs por risk_score ─────────────────────────────────────────────
        $topIps = Capsule::table('waf_blocked_ips_szm')
            ->orderByDesc('risk_score')->limit(5)
            ->get(['ip_address', 'risk_score', 'country', 'is_banned', 'last_attempt']);

        // Top 10 URIs más atacadas (7 días) ────────────────────────────────────
        $topUris = Capsule::table('waf_attack_logs_szm')
            ->select('uri', Capsule::raw('COUNT(*) as total'))
            ->where('created_at', '>=', $weekAgo)
            ->whereNotNull('uri')->where('uri', '!=', '')
            ->groupBy('uri')->orderByDesc('total')->limit(10)->get();

        // Métricas de efectividad ──────────────────────────────────────────────
        $uniqueIpsWeek = Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '>=', $weekAgo)
            ->distinct()->count('ip_address');

        $totalSeen   = Capsule::table('waf_blocked_ips_szm')->count();
        $totalBanned = Capsule::table('waf_blocked_ips_szm')->where('is_banned', 1)->count();
        $banRate     = $totalSeen > 0 ? (int) round($totalBanned / $totalSeen * 100) : 0;

        $uriMax = $topUris->max('total') ?: 1;

        return $this->view('admin/waf/index.twig', [
            'attacks_today'    => $attacksToday,
            'attacks_week'     => $attacksWeek,
            'banned_ips'       => $bannedIps,
            'top_rule'         => $topRule?->rule_triggered ?? '—',
            'delta_today'      => $deltaToday,
            'delta_week'       => $deltaWeek,
            'delta_banned'     => $deltaBanned,
            'attacks_yesterday'=> $attacksYesterday,
            'attacks_last_week'=> $attacksLastWeek,
            'timeline'         => $timeline,
            'rules'            => $rules,
            'top_ips'          => $topIps,
            'top_uris'         => $topUris,
            'uri_max'          => $uriMax,
            'unique_ips_week'  => $uniqueIpsWeek,
            'ban_rate'         => $banRate,
            'total_seen'       => $totalSeen,
        ]);
    }

    // ── IPs bloqueadas ────────────────────────────────────────────────────────

    public function blockedIps(): string
    {
        $req     = $this->request();
        $country = $req->str('country');
        $minScore = $req->int('min_score');
        $status  = $req->str('status');   // 'active' | 'expired' | ''
        $perPage = 20;
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
        $perPage  = 20;
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

    // ── Mapa de geolocalización ───────────────────────────────────────────────

    public function geoMap(): string
    {
        // IPs con coordenadas conocidas (máx. 500 pins)
        $markers = Capsule::table('waf_blocked_ips_szm')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->select('ip_address', 'city', 'country', 'isp', 'risk_score', 'is_banned', 'latitude', 'longitude', 'last_attempt')
            ->orderByDesc('risk_score')
            ->limit(500)
            ->get();

        // Top 10 países por número de IPs registradas
        $byCountry = Capsule::table('waf_blocked_ips_szm')
            ->select('country', Capsule::raw('COUNT(*) as total, MAX(risk_score) as max_score'))
            ->whereNotNull('country')
            ->where('country', '!=', 'Unknown')
            ->where('country', '!=', 'Local')
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // Total de IPs sin coordenadas (registros previos a la migración 009)
        $withoutCoords = Capsule::table('waf_blocked_ips_szm')
            ->where(fn($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->count();

        return $this->view('admin/waf/geo_map.twig', [
            'markers'       => $markers,
            'by_country'    => $byCountry,
            'without_coords'=> $withoutCoords,
            'total_pins'    => $markers->count(),
        ]);
    }

    // ── Sincronizar geolocalización de una IP ─────────────────────────────────

    public function syncGeo(int $id): never
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
        if (!CsrfToken::validate($token)) {
            $this->json(['ok' => false, 'error' => 'Token CSRF inválido.'], 419);
        }

        $record = Capsule::table('waf_blocked_ips_szm')->where('id', $id)->first();

        if (!$record) {
            $this->json(['ok' => false, 'error' => 'Registro no encontrado.'], 404);
        }

        $geo = $this->fetchGeoFromApi($record->ip_address);

        Capsule::table('waf_blocked_ips_szm')->where('id', $id)->update([
            'city'      => $geo['city'],
            'country'   => $geo['country'],
            'isp'       => $geo['isp'],
            'latitude'  => $geo['lat'],
            'longitude' => $geo['lon'],
        ]);

        $this->json(['ok' => true, 'geo' => $geo]);
    }

    // ── Desbloquear IP ────────────────────────────────────────────────────────

    // ── Exportación CSV ───────────────────────────────────────────────────────

    public function exportAttackLogs(): never
    {
        $req      = $this->request();
        $rule     = $req->str('rule');
        $ip       = $req->str('ip');
        $dateFrom = $req->str('date_from');
        $dateTo   = $req->str('date_to');

        $query = Capsule::table('waf_attack_logs_szm')->orderByDesc('created_at');

        if ($rule !== '')     $query->where('rule_triggered', $rule);
        if ($ip !== '')       $query->where('ip_address', 'like', '%' . $ip . '%');
        if ($dateFrom !== '') $query->where('created_at', '>=', $dateFrom . ' 00:00:00');
        if ($dateTo !== '')   $query->where('created_at', '<=', $dateTo . ' 23:59:59');

        $logs = $query->limit(10000)->get();

        $severityMap = [
            'path_traversal'    => 'CRÍTICA',
            'command_injection' => 'CRÍTICA',
            'xxe'               => 'CRÍTICA',
            'ssrf'              => 'CRÍTICA',
            'sql_injection'     => 'ALTA',
            'xss'               => 'ALTA',
            'open_redirect'     => 'ALTA',
            'rate_limit'        => 'MEDIA',
            'rate_limit_redis'  => 'MEDIA',
        ];

        $filename = 'waf_attacks_' . date('Ymd_His') . '.csv';

        $this->download($filename, 'text/csv; charset=UTF-8', function () use ($logs, $severityMap) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
            fputcsv($out, ['Fecha', 'IP', 'Regla', 'Severidad', 'Parámetro', 'URI', 'Payload', 'User-Agent']);

            foreach ($logs as $log) {
                fputcsv($out, [
                    $log->created_at,
                    $log->ip_address,
                    $log->rule_triggered,
                    $severityMap[$log->rule_triggered] ?? 'BAJA',
                    $log->parameter  ?? '',
                    $log->uri        ?? '',
                    $log->payload    ?? '',
                    $log->user_agent ?? '',
                ]);
            }

            fclose($out);
        });
    }

    public function exportBlockedIps(): never
    {
        $req      = $this->request();
        $country  = $req->str('country');
        $minScore = $req->int('min_score');
        $status   = $req->str('status');
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $query = Capsule::table('waf_blocked_ips_szm')->orderByDesc('risk_score');

        if ($country !== '')  $query->where('country', $country);
        if ($minScore > 0)    $query->where('risk_score', '>=', $minScore);

        if ($status === 'active') {
            $query->where('is_banned', 1)
                  ->where(fn($q) => $q->whereNull('ban_until')->orWhere('ban_until', '>', $now));
        } elseif ($status === 'expired') {
            $query->where(fn($q) => $q->where('is_banned', 0)->orWhere('ban_until', '<=', $now));
        }

        $ips      = $query->limit(10000)->get();
        $filename = 'waf_blocked_ips_' . date('Ymd_His') . '.csv';

        $this->download($filename, 'text/csv; charset=UTF-8', function () use ($ips) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'IP', 'País', 'Ciudad', 'ISP', 'Latitud', 'Longitud', 'Score', 'Baneada', 'Ban hasta', 'Razón', 'Último intento', 'Creado']);

            foreach ($ips as $ip) {
                fputcsv($out, [
                    $ip->id,
                    $ip->ip_address,
                    $ip->country      ?? '',
                    $ip->city         ?? '',
                    $ip->isp          ?? '',
                    $ip->latitude     ?? '',
                    $ip->longitude    ?? '',
                    $ip->risk_score,
                    $ip->is_banned ? 'Sí' : 'No',
                    $ip->ban_until    ?? '',
                    $ip->reason       ?? '',
                    $ip->last_attempt ?? '',
                    $ip->created_at   ?? '',
                ]);
            }

            fclose($out);
        });
    }

    // ── Helper privado — consulta ip-api.com ──────────────────────────────────

    private function fetchGeoFromApi(string $ip): array
    {
        $default = ['city' => 'Unknown', 'country' => 'Unknown', 'isp' => 'Unknown', 'lat' => null, 'lon' => null];

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return ['city' => 'Localhost', 'country' => 'Local', 'isp' => 'Internal Network', 'lat' => null, 'lon' => null];
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
            $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,isp,lat,lon", false, $ctx);

            if ($res === false) return $default;

            $data = json_decode($res, true);

            if (isset($data['status']) && $data['status'] === 'success') {
                return [
                    'city'    => $data['city']    ?? 'Unknown',
                    'country' => $data['country'] ?? 'Unknown',
                    'isp'     => $data['isp']     ?? 'Unknown',
                    'lat'     => $data['lat']     ?? null,
                    'lon'     => $data['lon']     ?? null,
                ];
            }
        } catch (\Throwable) {}

        return $default;
    }

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
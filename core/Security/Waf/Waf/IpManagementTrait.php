<?php

namespace Core\Security\Waf\Waf;

use Core\WafConfig;
use Illuminate\Database\Capsule\Manager as Capsule;

/***
 * (Gestión de Identidad y Bloqueos)
 * Funciones relacionadas con el baneo, Geo-IP, Redis y el estado de la IP.
 *
 * CAMBIOS PUNTO 2:
 *  - addRiskScore(): corregido bug fatal cuando $record es null en el paso 5.
 *  - addRiskScore(): recarga $record después de INSERT y UPDATE para leer ban_until real.
 *  - addRiskScore(): ahora obtiene geodatos al crear registro nuevo.
 *  - addRiskScore(): notifyIntrusion() se llama aquí una sola vez al momento del baneo.
 *  - block(): eliminada lógica de scoring duplicada, delega en addRiskScore().
 *  - block(): geodatos se leen del registro existente antes de llamar API externa.
 *  - checkFastBan(): exit explícito después de renderBlockedPage().
 *  - checkFastBan(): validación de JSON antes de usarlo.
 *  - checkFastBan(): corregida asignación innecesaria en argumento.
 *  - renderBlockedPage(): exit garantizado en todos los flujos.
 *  - renderBlockedPage(): fallback mínimo si falta waf_blocked.html.
 *  - renderBlockedPage(): htmlspecialchars en todos los valores interpolados.
 */
trait IpManagementTrait
{

    /**
     * Aplica control de Rate Limiting por dirección IP.
     * Sin cambios en este método.
     */
    protected function rateLimit(): void
    {
        if ($this->redis) {
            try {
                $key = "waf:rate:{$this->ip}";
                $count = $this->redis->incr($key);

                if ($count === 1) {
                    $this->redis->expire($key, WafConfig::REDIS_RATE_LIMIT_TTL_SECONDS);
                }

                if ($count > WafConfig::RATE_LIMIT_PER_MINUTE) {
                    $this->block("rate_limit_redis", "requests_per_minute", $count, $this->ip, true);
                }

                return;
            } catch (\Exception $e) {
                error_log("WAF Redis Error: " . $e->getMessage());
            }
        }

        if (rand(1, 100) <= WafConfig::RATE_LIMIT_CLEANUP_PROBABILITY) {
            Capsule::table('waf_requests_szm')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime(
                    '-' . WafConfig::RATE_LIMIT_RECORD_TTL_MINUTES . ' minutes'
                )))
                ->delete();
        }

        Capsule::table('waf_requests_szm')->insert([
            'ip_address' => $this->ip,
            'uri' => $this->uri,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $count = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-1 minute')))
            ->count();

        if ($count > 60) {
            $this->block("rate_limit", "requests_per_minute", $count, $this->ip, true);
        }
    }

    /**
     * Verifica si la IP actual se encuentra bloqueada por el WAF.
     * Sin cambios en este método.
     */
    protected function checkIpStatus(): void
    {
        $cacheKey = "waf:ban:{$this->ip}";

        if ($this->redis && $this->redis->exists($cacheKey)) {
            $data = json_decode($this->redis->get($cacheKey), true);
            $this->renderBlockedPage($this->ip, $data['expires'], $data['city'], $data['country']);
            return;
        }

        $blocked = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $this->ip)
            ->where('is_banned', 1)
            ->first();

        if ($blocked) {
            if (strtotime($blocked->ban_until) > time()) {
                if ($this->redis) {
                    $ttl = strtotime($blocked->ban_until) - time();
                    $this->redis->setex($cacheKey, $ttl, json_encode([
                        'expires' => $blocked->ban_until,
                        'city' => $blocked->city,
                        'country' => $blocked->country
                    ]));
                }
                $this->renderBlockedPage($this->ip, $blocked->ban_until, $blocked->city, $blocked->country);
            } else {
                if ($this->redis) {
                    $this->redis->del($cacheKey);
                }
                Capsule::table('waf_blocked_ips_szm')
                    ->where('ip_address', $this->ip)
                    ->update([
                        'is_banned' => 0,
                        'attempts' => 0,
                        'reason' => 'Ban expired'
                    ]);
            }
        }
    }

    /**
     * Verifica bloqueos activos utilizando Redis para aplicar un "Fast Ban".
     *
     * CAMBIOS:
     *  - Exit explícito después de renderBlockedPage() como segunda línea de defensa.
     *  - Validación de JSON antes de usarlo.
     *  - Corregida asignación innecesaria en argumento ($country =).
     */
    protected function checkFastBan(): void
    {
        if (!$this->redis) return;

        $ipKey = "waf:ban:{$this->ip}";
        $fpKey = "waf:ban:fp:{$this->fingerprint}";

        $results = $this->redis->mget([$ipKey, $fpKey]);

        foreach ($results as $hit) {
            if (!$hit) continue;

            $data = json_decode($hit, true);

            // Validamos que el JSON sea válido antes de usarlo
            if (!is_array($data)) continue;

            error_log("[WAF-REDIS] Bloqueo rápido ejecutado para: {$this->ip}");

            $this->renderBlockedPage(
                $this->ip,
                $data['expires'] ?? 'N/A',
                $data['city'] ?? 'Desconocida',
                $data['country'] ?? 'Desconocido'  // ✅ sin asignación innecesaria
            );

            // Exit explícito como segunda línea de defensa.
            // Si renderBlockedPage() falla antes de su propio exit, este lo garantiza.
            exit;
        }
    }

    /**
     * Verifica si el fingerprint del cliente está bloqueado por el WAF.
     * Sin cambios en este método.
     */
    protected function checkFingerprintBan(): void
    {
        $fp = $this->fingerprint;
        if (!$fp) return;

        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('fingerprint', $fp)
            ->where('is_banned', 1)
            ->where(function ($query) {
                $query->where('ban_until', '>', date('Y-m-d H:i:s'))
                    ->orWhereNull('ban_until');
            })
            ->first();

        if ($record) {
            error_log("[WAF] Acceso denegado por Fingerprint: $fp");
            $this->renderBlockedPage(
                $this->ip,
                $record->ban_until ?? date('Y-m-d H:i:s'),
                $record->city ?? 'Desconocida',
                $record->country ?? 'Desconocido'
            );
        }
    }

    /**
     * Incrementa el puntaje de riesgo (Risk Score) de una IP o huella digital
     * y ejecuta un baneo automático cuando se supera el umbral definido.
     *
     * CAMBIOS:
     *  - Corregido bug fatal: $record puede ser null en el paso 5.
     *  - Se recarga $record después del INSERT para tener datos reales en renderBlockedPage().
     *  - Se recarga $record después del UPDATE para leer ban_until recién escrito.
     *  - Se obtienen geodatos al crear un registro nuevo.
     *  - notifyIntrusion() se llama aquí una sola vez al momento del baneo.
     *  - Nullsafe fallbacks en renderBlockedPage() como defensa adicional.
     */
    protected function addRiskScore(string $ip, int $points, string $reason): void
    {
        $fp = $this->fingerprint;
        $now = date('Y-m-d H:i:s');

        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $ip)
            ->orWhere('fingerprint', $fp)
            ->first();

        if (!$record) {
            // Primera vez que vemos esta IP: obtenemos geodatos
            $geo = $this->getIpDetails($ip);

            Capsule::table('waf_blocked_ips_szm')->insert([
                'ip_address' => $ip,
                'fingerprint' => $fp,
                'risk_score' => $points,
                'is_banned' => 0,
                'city' => $geo['city'],
                'country' => $geo['country'],
                'isp' => $geo['isp'],
                'reason' => $reason,
                'last_attempt' => $now,
                'created_at' => $now
            ]);

            $currentScore = $points;

            // ✅ Recargamos $record para que el paso final siempre tenga datos reales
            $record = Capsule::table('waf_blocked_ips_szm')
                ->where('ip_address', $ip)
                ->first();

        } else {
            $currentScore = $record->risk_score + $points;

            $updateData = [
                'risk_score' => $currentScore,
                'reason' => $record->reason . " | " . $reason,
                'last_attempt' => $now,
                'ip_address' => $ip
            ];

            if ($currentScore >= WafConfig::BAN_THRESHOLD_SCORE && $record->is_banned == 0) {
                $updateData['is_banned'] = 1;
                $updateData['ban_until'] = date('Y-m-d H:i:s', strtotime('+' . WafConfig::BAN_DURATION_HOURS . ' hours'));

                // ✅ notifyIntrusion se llama aquí, una sola vez, cuando ocurre el baneo
                $geo = [
                    'city' => $record->city,
                    'country' => $record->country,
                    'isp' => $record->isp
                ];
                $this->notifyIntrusion($ip, $reason, '', $geo);
                $this->logBan($ip, $fp, $currentScore, $updateData['reason']);
            }

            Capsule::table('waf_blocked_ips_szm')
                ->where('id', $record->id)
                ->update($updateData);

            // ✅ Recargamos $record para leer ban_until recién escrito
            $record = Capsule::table('waf_blocked_ips_szm')
                ->where('id', $record->id)
                ->first();
        }

        // ✅ $record SIEMPRE tiene datos válidos en este punto
        if ($currentScore >= WafConfig::BAN_THRESHOLD_SCORE) {
            $this->renderBlockedPage(
                $ip,
                $record->ban_until ?? $now,
                $record->city ?? 'Desconocida',
                $record->country ?? 'Desconocido'
            );
        }
    }

    /**
     * Ejecuta la acción de bloqueo cuando una regla del WAF es activada.
     *
     * CAMBIOS:
     *  - Eliminada lógica de scoring duplicada: delega completamente en addRiskScore().
     *  - Geodatos se leen del registro existente antes de llamar API externa.
     *  - notifyIntrusion() eliminada de aquí: ahora vive en addRiskScore().
     *  - El método queda enfocado en su responsabilidad: registrar el evento.
     */
    protected function block(string $rule, string $key, string $value, string $ip, bool $immediateBan = false): void
    {
        // 1. Registro del log de ataque (responsabilidad exclusiva de block)
        Capsule::table('waf_attack_logs_szm')->insert([
            'ip_address' => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'rule_triggered' => $rule,
            'parameter' => $key,
            'fingerprint' => $this->fingerprint,
            'payload' => substr($value, 0, 500),
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // 2. Geodatos: leer de registro existente antes de llamar API externa
        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $ip)
            ->first();

        $geo = ($record && $record->city && $record->city !== 'Unknown')
            ? ['city' => $record->city, 'country' => $record->country, 'isp' => $record->isp]
            : $this->getIpDetails($ip);

        // 3. Guardar geodatos si el registro existe pero no los tenía
        if ($record && (!$record->city || $record->city === 'Unknown')) {
            Capsule::table('waf_blocked_ips_szm')
                ->where('id', $record->id)
                ->update([
                    'city' => $geo['city'],
                    'country' => $geo['country'],
                    'isp' => $geo['isp']
                ]);
        }

        // 4. Delegamos TODO el scoring y la decisión de baneo a addRiskScore()
        //    immediateBan suma 20 puntos para forzar el umbral de bloqueo
        $points = $immediateBan ?
            WafConfig::IMMEDIATE_BAN_SCORE
            : WafConfig::NORMAL_BLOCK_SCORE;;
        $this->addRiskScore($ip, $points, "Rule: $rule | Key: $key");
    }

    /**
     * Obtiene información geográfica básica de una IP con cache en Redis.
     * Sin cambios en este método.
     */
    protected function getIpDetails(string $ip): array
    {
        $default = ['city' => 'Unknown', 'country' => 'Unknown', 'isp' => 'Unknown'];

        if ($ip === '127.0.0.1' || $ip === '::1') {
            return ['city' => 'Localhost', 'country' => 'Local', 'isp' => 'Internal Network'];
        }

        $cacheKey = "waf:geo:{$ip}";

        if ($this->redis) {
            $cached = $this->redis->get($cacheKey);
            if ($cached) {
                return array_merge($default, json_decode($cached, true));
            }
        }

        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => WafConfig::GEO_API_TIMEOUT_SECONDS,
                    'ignore_errors' => true
                ]
            ]);

            $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,isp", false, $ctx);

            if ($res === false) return $default;

            $data = json_decode($res, true);

            if (isset($data['status']) && $data['status'] === 'success') {
                unset($data['status']);

                if ($this->redis) {
                    $this->redis->setex($cacheKey, WafConfig::GEO_CACHE_TTL_SECONDS, json_encode($data));
                }

                return array_merge($default, $data);
            }

        } catch (\Throwable $e) {
            // Silencioso para no interrumpir el flujo del WAF
        }

        return $default;
    }

    /**
     * Genera un fingerprint del cliente basado en cabeceras HTTP.
     * Sin cambios en este método.
     */
    protected function generateFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['HTTP_ACCEPT'] ?? '',          // tipo de contenido esperado
            $_SERVER['HTTP_CONNECTION'] ?? '',      // keep-alive vs close
            $_SERVER['HTTP_CACHE_CONTROL'] ?? '',   // patrón de caché del cliente
            $_SERVER['SSL_PROTOCOL'] ?? '',
        ];
        return hash('sha256', implode('|', $data));
    }

    /**
     * Registra un baneo definitivo en el historial de auditoría.
     * Sin cambios en este método.
     */
    protected function logBan(string $ip, string $fp, int $score, string $reason): void
    {
        $evidence = [
            'final_score' => $score,
            'full_reason' => $reason,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
        ];

        Capsule::table('waf_attack_logs_szm')->insert([
            'ip_address' => $ip,
            'fingerprint' => $fp,
            'rule_triggered' => 'FINAL_BAN_SENTENCE',
            'parameter' => 'RISK_THRESHOLD_EXCEEDED',
            'payload' => json_encode($evidence),
            'user_agent' => $evidence['user_agent'],
            'uri' => $evidence['request_uri'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        error_log("[WAF-BAN] IP: $ip bloqueada permanentemente. Motivo: $reason Score: $score");
    }

    /**
     * Resuelve la IP real del cliente de forma segura.
     *
     * Estrategia para hosting compartido / servidor directo:
     *
     * - REMOTE_ADDR es la fuente de verdad principal. En conexión directa
     *   siempre contiene la IP real y no puede ser falsificada por el cliente.
     *
     * - X-Forwarded-For / X-Real-IP se leen SOLO si REMOTE_ADDR pertenece
     *   a un proxy de confianza explícitamente declarado (ej: proxy interno
     *   de cPanel). Si el header llega desde una IP no confiable, se ignora
     *   completamente — un atacante no puede inyectar su propia IP "limpia".
     *
     * - Si X-Forwarded-For contiene múltiples IPs ("client, proxy1, proxy2"),
     *   tomamos siempre la primera (más a la izquierda), que es la del cliente
     *   original según el estándar RFC 7239.
     *
     * - Se valida con FILTER_VALIDATE_IP antes de usar cualquier valor
     *   para prevenir inyección de valores malformados en los logs y en la DB.
     */
    protected function resolveIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Lista de proxies internos de confianza.
        // En hosting compartido normalmente solo el loopback.
        // Amplía esta lista si tienes un proxy/balanceador propio.
        $trustedProxies = [
            '127.0.0.1',
            '::1',
        ];

        // Si REMOTE_ADDR NO es un proxy de confianza, es la IP real del cliente.
        // No leemos ningún header adicional — podría estar falsificado.
        if (!in_array($remoteAddr, $trustedProxies, true)) {
            return $remoteAddr;
        }

        // Solo llegamos aquí si la conexión viene de un proxy de confianza.
        // Intentamos obtener la IP original del cliente desde los headers
        // en orden de prioridad.
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null, // Cloudflare (por si se añade después)
            $_SERVER['HTTP_X_REAL_IP'] ?? null, // Nginx proxy
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null, // Estándar general
        ];

        foreach ($candidates as $candidate) {
            if (empty($candidate)) continue;

            // X-Forwarded-For puede ser "client, proxy1, proxy2"
            // El cliente real es siempre el primero de la lista
            $ip = trim(explode(',', $candidate)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        // Fallback: si los headers no tienen IP pública válida, usamos REMOTE_ADDR
        return $remoteAddr;
    }
}
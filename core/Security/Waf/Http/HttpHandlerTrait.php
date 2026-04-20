<?php

declare(strict_types=1);

namespace Core\Security\Waf\Http;

trait HttpHandlerTrait
{
    /**
     * Inyecta una política de seguridad de contenido (CSP) basada en nonce.
     *
     * Cada request genera un nonce criptográfico de 128 bits (16 bytes aleatorios
     * codificados en base64). Solo los scripts que incluyen ese nonce exacto son
     * ejecutados por el navegador, invalidando cualquier script inyectado por XSS.
     *
     * El nonce se expone en $_SERVER['CSP_NONCE'] para que TwigFactory lo inyecte
     * como global {{ csp_nonce }} disponible en todas las plantillas.
     *
     * Uso en plantillas:
     *   <script nonce="{{ csp_nonce }}" src="/app.js"></script>
     *   <script nonce="{{ csp_nonce }}">/* inline JS *\/</script>
     */
    protected function injectCSP(): void
    {
        // Nonce criptográfico único por request — 16 bytes = 128 bits de entropía
        $nonce = base64_encode(random_bytes(16));

        // Exponemos el nonce para que TwigFactory lo registre como global
        $_SERVER['CSP_NONCE'] = $nonce;

        $csp  = "default-src 'self'; ";
        // 'nonce-{nonce}' reemplaza 'unsafe-inline' — solo scripts con ese nonce exacto se ejecutan
        $csp .= "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; ";
        $csp .= "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; ";
        $csp .= "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; ";
        $csp .= "img-src 'self' data: https:; ";
        // frame-ancestors 'self': la app puede mostrar PDFs propios en iframes,
        // pero bloquea clickjacking desde dominios externos.
        $csp .= "frame-ancestors 'self';";

        header("Content-Security-Policy: {$csp}");
    }

    /**
     * Limpia cabeceras sensibles y aplica ofuscación (Security by Obscurity)
     */
    protected function cleanHeaders(): void
    {
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
            header_remove('Server');
            header_remove('X-AspNet-Version'); // Limpieza extra por si acaso
        }

        // Engaño: Hacemos creer al atacante que usamos una tecnología distinta
        header("X-Powered-By: ASP.NET");
        header("Server: Microsoft-IIS/10.0");
    }

    /**
     * Inyecta capas de seguridad activa en las cabeceras HTTP
     */
    protected function injectSecurityHeaders(): void
    {
        // Evita que el sitio sea embebido en iFrames externos (Anti-Clickjacking).
        // SAMEORIGIN permite iframes dentro del propio dominio (visor PDF inline en evaluaciones).
        header('X-Frame-Options: SAMEORIGIN');

        // Evita que el navegador "adivine" el tipo de contenido (Anti-MIME Sniffing)
        header('X-Content-Type-Options: nosniff');

        // Filtro XSS básico para navegadores antiguos
        header('X-XSS-Protection: 1; mode=block');

        // No envía la URL de origen al navegar a otros sitios por privacidad
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Si tu sitio tiene SSL (Recomendado para PRO FIT), descomenta la siguiente línea:
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        // Inyectamos la CSP al final
        $this->injectCSP();
    }

    /**
     * Establece el código de estado 403 y detiene intentos de renderizado previo
     */
    protected function setForbiddenHeader(): void
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Status: 403 Forbidden');
        }
    }
}
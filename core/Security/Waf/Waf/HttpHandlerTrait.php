<?php

namespace Core\Security\Waf\Waf;

trait HttpHandlerTrait
{
    /**
     * Inyecta una política de seguridad de contenido (CSP)
     * Protege contra XSS y ataques de inyección de datos.
     */
    protected function injectCSP(): void
    {
        // 'self': Recursos propios
        // unsafe-inline: Necesario si tienes JS/CSS dentro del HTML
        $csp = "default-src 'self'; ";
        $csp .= "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; ";
        $csp .= "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; ";
        $csp .= "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; ";
        $csp .= "img-src 'self' data: https:; ";
        // 'self' permite que la propia app muestre PDFs en iframes (visor inline de evaluación).
        // Sigue bloqueando clickjacking desde dominios externos.
        $csp .= "frame-ancestors 'self';";

        header("Content-Security-Policy: " . $csp);
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
        error_log("[WAF-SYSTEM] injectSecurityHeaders");
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
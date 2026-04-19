<?php

declare(strict_types=1);

namespace App\Services;

/**
 * HtmlSanitizer — saneamiento seguro del HTML generado por editores WYSIWYG.
 *
 * Estrategia dual:
 *   1. HTMLPurifier (ezyang/htmlpurifier) cuando está instalado — método robusto
 *      y ampliamente auditado para producción.
 *   2. Fallback DOMDocument — elimina eventos JS, atributos peligrosos y URLs
 *      no permitidas sin dependencias externas.
 *
 * Instalar HTMLPurifier (recomendado):
 *   composer require ezyang/htmlpurifier
 *
 * Elementos permitidos:
 *   Texto:     p br strong b em i u s del ins
 *   Estructura: h2 h3 h4 h5 h6 ul ol li blockquote pre code hr abbr
 *   Media:     a img figure figcaption
 *   Tablas:    table thead tbody tfoot tr th td
 *
 * Bloqueado:
 *   - Todos los atributos "on*" (onclick, onerror, …)
 *   - Atributos style e id
 *   - URLs con esquemas peligrosos (javascript:, data:, vbscript:)
 */
final class HtmlSanitizer
{
    private static ?\HTMLPurifier $purifier = null;

    /** Etiquetas permitidas (sin atributos — se controlan por separado). */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins',
        'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'figure', 'figcaption', 'hr', 'abbr',
    ];

    /** Atributos permitidos por etiqueta. '*' = aplica a todas. */
    private const ALLOWED_ATTRS = [
        'a'    => ['href', 'title', 'rel', 'target'],
        'img'  => ['src', 'alt', 'title', 'width', 'height', 'loading'],
        'th'   => ['scope', 'colspan', 'rowspan'],
        'td'   => ['colspan', 'rowspan'],
        'abbr' => ['title'],
    ];

    /** Esquemas de URL aceptados en href y src. */
    private const SAFE_URL_PATTERN = '/^(https?:\/\/|mailto:|#|\/)/i';

    // ── API pública ───────────────────────────────────────────────────────

    /**
     * Sanea HTML de editor y retorna HTML seguro.
     *
     * @param string $html HTML bruto del editor WYSIWYG
     * @return string HTML saneado listo para almacenar / mostrar
     */
    public static function purify(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        return \class_exists(\HTMLPurifier::class)
            ? self::purifyWithLibrary($html)
            : self::purifyWithDom($html);
    }

    // ── HTMLPurifier (dependencia externa — recomendada) ──────────────────

    private static function purifyWithLibrary(string $html): string
    {
        if (self::$purifier === null) {
            $config = \HTMLPurifier_Config::createDefault();

            // Cache serializado para mejorar rendimiento en producción
            $cacheDir = \rtrim(\defined('_APP_PATH_') ? _APP_PATH_ : __DIR__ . '/../../', '/')
                      . '/../storage/htmlpurifier';

            if (!\is_dir($cacheDir)) {
                @\mkdir($cacheDir, 0755, true);
            }

            if (\is_writable($cacheDir)) {
                $config->set('Cache.SerializerPath', $cacheDir);
            } else {
                $config->set('Cache.DefinitionImpl', null);
            }

            // Charset
            $config->set('Core.Encoding', 'UTF-8');

            // Elementos y atributos permitidos
            $config->set('HTML.Allowed',
                'p,br,strong,b,em,i,u,s,del,ins,' .
                'h2,h3,h4,h5,h6,' .
                'ul,ol,li,' .
                'blockquote,pre,code,' .
                'a[href|title|rel|target],' .
                'img[src|alt|title|width|height|loading],' .
                'table,thead,tbody,tfoot,tr,' .
                'th[scope|colspan|rowspan],td[colspan|rowspan],' .
                'figure,figcaption,hr,abbr[title]'
            );

            // Esquemas de URI permitidos
            $config->set('URI.AllowedSchemes', [
                'http'   => true,
                'https'  => true,
                'mailto' => true,
            ]);

            // Abrir enlaces externos en pestaña nueva con rel="noopener"
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.TargetNoreferrer', true);

            self::$purifier = new \HTMLPurifier($config);
        }

        return self::$purifier->purify($html);
    }

    // ── Fallback DOMDocument (sin dependencias externas) ─────────────────

    private static function purifyWithDom(string $html): string
    {
        // 1. Strip tags que no están en la lista
        $tagString = '<' . \implode('><', self::ALLOWED_TAGS) . '>';
        $clean     = \strip_tags($html, $tagString);

        // 2. Parsear con DOMDocument para controlar atributos
        $dom = new \DOMDocument('1.0', 'UTF-8');
        \libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><body>' . $clean . '</body>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD
        );
        \libxml_clear_errors();

        // 3. Recorrer todos los elementos y sanear atributos
        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//*');

        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!($node instanceof \DOMElement)) {
                    continue;
                }

                self::sanitizeElement($node);
            }
        }

        // 4. Extraer solo el contenido del body
        $body   = $dom->getElementsByTagName('body')->item(0);
        $output = '';

        if ($body !== null) {
            foreach ($body->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }
        }

        return $output !== '' ? $output : $clean;
    }

    private static function sanitizeElement(\DOMElement $node): void
    {
        $tag     = \strtolower($node->tagName);
        $allowed = self::ALLOWED_ATTRS[$tag] ?? [];

        // Recopilar atributos a eliminar en una primera pasada
        $toRemove = [];

        foreach ($node->attributes as $attr) {
            $name = \strtolower($attr->name);

            // Bloquear eventos JS, style e id
            if (\str_starts_with($name, 'on') || \in_array($name, ['style', 'id', 'class'], true)) {
                $toRemove[] = $attr->name;
                continue;
            }

            // Bloquear atributos no permitidos para este elemento
            if (!\in_array($name, $allowed, true)) {
                $toRemove[] = $attr->name;
                continue;
            }

            // Sanear URLs en href y src
            if (\in_array($name, ['href', 'src'], true)) {
                $val = \trim($attr->value);
                if (!\preg_match(self::SAFE_URL_PATTERN, $val)) {
                    $node->setAttribute($attr->name, '#');
                }
            }

            // Forzar rel="noopener noreferrer" en enlaces externos
            if ($name === 'href' && $tag === 'a') {
                $val = \trim($attr->value);
                if (\str_starts_with($val, 'http')) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }

        foreach ($toRemove as $attrName) {
            $node->removeAttribute($attrName);
        }
    }
}
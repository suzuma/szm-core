<?php

declare(strict_types=1);

namespace App\Services;

/**
 * EditorJsConverter — convierte bloques JSON de Editor.js a HTML limpio.
 *
 * Soporta: paragraph, header, list, quote, code, image, embed,
 *          delimiter, table, raw.
 */
class EditorJsConverter
{
    /**
     * Detecta si el string es un payload JSON de Editor.js
     * (tiene la clave "blocks" en el nivel raíz).
     */
    public static function isEditorJs(string $content): bool
    {
        $trimmed = \ltrim($content);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            return false;
        }
        $data = \json_decode($trimmed, true);
        return \is_array($data) && isset($data['blocks']);
    }

    /**
     * Convierte el JSON de Editor.js en un string HTML.
     */
    public static function toHtml(string $json): string
    {
        $data = \json_decode($json, true);
        if (!\is_array($data) || empty($data['blocks'])) {
            return '';
        }

        $html = '';
        foreach ($data['blocks'] as $block) {
            $html .= self::blockToHtml((array) $block);
        }
        return $html;
    }

    // ── Bloque dispatcher ─────────────────────────────────────────────────

    private static function blockToHtml(array $block): string
    {
        $type = $block['type'] ?? '';
        $data = (array) ($block['data'] ?? []);

        return match ($type) {
            'paragraph' => self::paragraph($data),
            'header'    => self::header($data),
            'list'      => self::list($data),
            'quote'     => self::quote($data),
            'code'      => self::code($data),
            'image'     => self::image($data),
            'embed'     => self::embed($data),
            'delimiter' => "<hr>\n",
            'table'     => self::table($data),
            'raw'       => ($data['html'] ?? '') . "\n",
            default     => '',
        };
    }

    // ── Convertidores por tipo ────────────────────────────────────────────

    private static function paragraph(array $data): string
    {
        $text = $data['text'] ?? '';
        if ($text === '') {
            return '';
        }
        return "<p>{$text}</p>\n";
    }

    private static function header(array $data): string
    {
        $level = (int) ($data['level'] ?? 2);
        $level = \max(1, \min(6, $level));
        $text  = \htmlspecialchars($data['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<h{$level}>{$text}</h{$level}>\n";
    }

    private static function list(array $data): string
    {
        $tag   = ($data['style'] ?? 'unordered') === 'ordered' ? 'ol' : 'ul';
        $items = (array) ($data['items'] ?? []);
        if (empty($items)) {
            return '';
        }

        $html = "<{$tag}>\n";
        foreach ($items as $item) {
            // Editor.js v2 nested lists use objects; v1 uses plain strings
            $text  = \is_array($item) ? ($item['content'] ?? '') : (string) $item;
            $html .= "<li>{$text}</li>\n";
        }
        $html .= "</{$tag}>\n";
        return $html;
    }

    private static function quote(array $data): string
    {
        $text    = $data['text'] ?? '';
        $caption = \htmlspecialchars($data['caption'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html    = "<blockquote>\n<p>{$text}</p>\n";
        if ($caption !== '') {
            $html .= "<cite>{$caption}</cite>\n";
        }
        $html .= "</blockquote>\n";
        return $html;
    }

    private static function code(array $data): string
    {
        $code = \htmlspecialchars($data['code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return "<pre><code>{$code}</code></pre>\n";
    }

    private static function image(array $data): string
    {
        $url     = \htmlspecialchars($data['file']['url'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = \htmlspecialchars($data['caption'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($url === '') {
            return '';
        }

        $classes = [];
        if (!empty($data['withBorder'])) {
            $classes[] = 'img--border';
        }
        if (!empty($data['stretched'])) {
            $classes[] = 'img--stretched';
        }
        if (!empty($data['withBackground'])) {
            $classes[] = 'img--bg';
        }
        $classAttr = $classes ? ' class="' . \implode(' ', $classes) . '"' : '';

        $html = "<figure{$classAttr}>\n<img src=\"{$url}\" alt=\"{$caption}\" loading=\"lazy\">\n";
        if ($caption !== '') {
            $html .= "<figcaption>{$caption}</figcaption>\n";
        }
        $html .= "</figure>\n";
        return $html;
    }

    private static function embed(array $data): string
    {
        $service = \htmlspecialchars($data['service'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $embed   = \htmlspecialchars($data['embed'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = \htmlspecialchars($data['caption'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($embed === '') {
            return '';
        }

        $html  = "<figure class=\"embed embed--{$service}\">\n";
        $html .= "<iframe src=\"{$embed}\" allowfullscreen loading=\"lazy\"></iframe>\n";
        if ($caption !== '') {
            $html .= "<figcaption>{$caption}</figcaption>\n";
        }
        $html .= "</figure>\n";
        return $html;
    }

    private static function table(array $data): string
    {
        $content      = (array) ($data['content'] ?? []);
        $withHeadings = (bool) ($data['withHeadings'] ?? false);
        if (empty($content)) {
            return '';
        }

        $html = "<table>\n";
        foreach ($content as $i => $row) {
            $row  = (array) $row;
            $cell = ($withHeadings && $i === 0) ? 'th' : 'td';
            $html .= "<tr>";
            foreach ($row as $col) {
                $col   = \htmlspecialchars((string) $col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= "<{$cell}>{$col}</{$cell}>";
            }
            $html .= "</tr>\n";
        }
        $html .= "</table>\n";
        return $html;
    }
}
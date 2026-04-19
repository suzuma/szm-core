<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Category;
use App\Models\Post;

/**
 * FeedController — endpoints de descubrimiento y SEO.
 *
 * Rutas (públicas, sin filtro):
 *   GET /sitemap.xml  → sitemap()
 *   GET /rss.xml      → rss()
 *   GET /robots.txt   → robots()
 */
class FeedController extends BaseController
{
    // ── Sitemap XML ───────────────────────────────────────────────────────

    public function sitemap(): never
    {
        $appUrl = $this->appUrl();

        $posts = Post::published()
            ->select(['slug', 'updated_at'])
            ->latest('published_at')
            ->get();

        $categories = Category::active()
            ->select(['slug', 'updated_at'])
            ->orderBy('sort_order')
            ->get();

        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ([['/', '1.0', 'daily'], ['/blog', '0.9', 'daily']] as [$path, $pri, $freq]) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->xe($appUrl . $path) . '</loc>';
            $lines[] = "    <changefreq>{$freq}</changefreq>";
            $lines[] = "    <priority>{$pri}</priority>";
            $lines[] = '  </url>';
        }

        foreach ($categories as $cat) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->xe($appUrl . '/blog/categoria/' . $cat->slug) . '</loc>';
            if ($cat->updated_at) {
                $lines[] = '    <lastmod>' . $cat->updated_at->format('Y-m-d') . '</lastmod>';
            }
            $lines[] = '    <changefreq>weekly</changefreq>';
            $lines[] = '    <priority>0.7</priority>';
            $lines[] = '  </url>';
        }

        foreach ($posts as $post) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->xe($appUrl . '/blog/' . $post->slug) . '</loc>';
            if ($post->updated_at) {
                $lines[] = '    <lastmod>' . $post->updated_at->format('Y-m-d') . '</lastmod>';
            }
            $lines[] = '    <changefreq>monthly</changefreq>';
            $lines[] = '    <priority>0.8</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        $this->xmlResponse(implode("\n", $lines));
    }

    // ── RSS 2.0 ───────────────────────────────────────────────────────────

    public function rss(): never
    {
        $appUrl  = $this->appUrl();
        $appName = $_ENV['APP_NAME'] ?? 'Blog';

        $posts = Post::with(['author', 'category'])
            ->published()
            ->latest('published_at')
            ->take(20)
            ->get();

        $lines   = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">';
        $lines[] = '  <channel>';
        $lines[] = '    <title>' . $this->xe($appName) . '</title>';
        $lines[] = '    <link>' . $this->xe($appUrl . '/blog') . '</link>';
        $lines[] = '    <description>Últimas entradas del blog.</description>';
        $lines[] = '    <language>es</language>';
        $lines[] = '    <lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>';
        $lines[] = '    <atom:link href="' . $this->xe($appUrl . '/rss.xml') . '" rel="self" type="application/rss+xml"/>';

        foreach ($posts as $post) {
            $link    = $appUrl . '/blog/' . $post->slug;
            $excerpt = $post->excerpt
                ? strip_tags($post->excerpt)
                : mb_substr(strip_tags((string) $post->content), 0, 300);

            $lines[] = '    <item>';
            $lines[] = '      <title>' . $this->xe($post->title) . '</title>';
            $lines[] = '      <link>' . $this->xe($link) . '</link>';
            $lines[] = '      <guid isPermaLink="true">' . $this->xe($link) . '</guid>';
            $lines[] = '      <description>' . $this->xe($excerpt) . '</description>';
            $lines[] = '      <pubDate>' . ($post->published_at?->format(DATE_RSS) ?? date(DATE_RSS)) . '</pubDate>';
            if ($post->author) {
                $lines[] = '      <author>' . $this->xe($post->author->email . ' (' . $post->author->name . ')') . '</author>';
            }
            if ($post->category) {
                $lines[] = '      <category>' . $this->xe($post->category->name) . '</category>';
            }
            $lines[] = '    </item>';
        }

        $lines[] = '  </channel>';
        $lines[] = '</rss>';

        $this->xmlResponse(implode("\n", $lines), 'application/rss+xml');
    }

    // ── robots.txt ────────────────────────────────────────────────────────

    public function robots(): never
    {
        $appUrl = $this->appUrl();

        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin/',
            'Disallow: /login',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            '',
            "Sitemap: {$appUrl}/sitemap.xml",
            '',
        ]);

        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /** Escape XML seguro para contenido de elementos y atributos. */
    private function xe(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Devuelve la URL base de la app, con autodetect como fallback. */
    private function appUrl(): string
    {
        $configured = rtrim($_ENV['APP_URL'] ?? '', '/');

        if ($configured !== '') {
            return $configured;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    /** Envía una respuesta XML y termina la ejecución. */
    private function xmlResponse(string $body, string $contentType = 'application/xml'): never
    {
        header("Content-Type: {$contentType}; charset=utf-8");
        echo $body;
        exit;
    }
}
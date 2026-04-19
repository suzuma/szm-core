<?php

declare(strict_types=1);

namespace App\Controllers\Blog;

use App\Controllers\BaseController;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;

/**
 * BlogController — frontend público del blog.
 *
 * Rutas (sin filtro de auth — acceso público):
 *   GET /blog                      → index()
 *   GET /blog/categoria/{slug}     → byCategory()
 *   GET /blog/tag/{slug}           → byTag()
 *   GET /blog/{slug}               → show()
 */
class BlogController extends BaseController
{
    private const PER_PAGE = 9;

    // ── Feed principal ────────────────────────────────────────────────────

    public function index(): string
    {
        $req    = $this->request();
        $search = trim($req->str('q'));
        $page   = max(1, $req->int('page', 1));

        $query = Post::with(['author', 'category', 'tags'])
            ->published()
            ->latest('published_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%");
            });
        }

        $total      = $query->count();
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $posts      = $query->skip(($page - 1) * self::PER_PAGE)
                            ->take(self::PER_PAGE)
                            ->get();

        // Post destacado: el primero de la primera página sin buscar
        $featured = ($page === 1 && $search === '') ? $posts->shift() : null;

        return $this->view('blog/index.twig', [
            'posts'       => $posts,
            'featured'    => $featured,
            'categories'  => $this->sidebarCategories(),
            'popular_tags'=> $this->sidebarTags(),
            'search'      => $search,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => $totalPages,
            // SEO
            'page_title'       => 'Blog',
            'meta_description' => 'Últimas noticias y artículos.',
        ]);
    }

    // ── Por categoría ─────────────────────────────────────────────────────

    public function byCategory(string $slug): string
    {
        $category = Category::bySlug($slug)->active()->firstOrFail();
        $page     = max(1, $this->request()->int('page', 1));

        $query = Post::with(['author', 'category', 'tags'])
            ->published()
            ->byCategory($category->id)
            ->latest('published_at');

        $total      = $query->count();
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $posts      = $query->skip(($page - 1) * self::PER_PAGE)
                            ->take(self::PER_PAGE)
                            ->get();

        return $this->view('blog/index.twig', [
            'posts'        => $posts,
            'featured'     => null,
            'categories'   => $this->sidebarCategories(),
            'popular_tags' => $this->sidebarTags(),
            'active_category' => $category,
            'search'       => '',
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => $totalPages,
            // SEO
            'page_title'       => $category->name,
            'meta_description' => $category->description ?? "Artículos en {$category->name}.",
        ]);
    }

    // ── Por tag ───────────────────────────────────────────────────────────

    public function byTag(string $slug): string
    {
        $tag  = Tag::bySlug($slug)->firstOrFail();
        $page = max(1, $this->request()->int('page', 1));

        $query = Post::with(['author', 'category', 'tags'])
            ->published()
            ->byTag($slug)
            ->latest('published_at');

        $total      = $query->count();
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $posts      = $query->skip(($page - 1) * self::PER_PAGE)
                            ->take(self::PER_PAGE)
                            ->get();

        return $this->view('blog/index.twig', [
            'posts'        => $posts,
            'featured'     => null,
            'categories'   => $this->sidebarCategories(),
            'popular_tags' => $this->sidebarTags(),
            'active_tag'   => $tag,
            'search'       => '',
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => $totalPages,
            // SEO
            'page_title'       => '#' . $tag->name,
            'meta_description' => "Artículos etiquetados con {$tag->name}.",
        ]);
    }

    // ── Vista individual ──────────────────────────────────────────────────

    public function show(string $slug): string
    {
        $post = Post::with(['author', 'category', 'tags'])
            ->published()
            ->bySlug($slug)
            ->firstOrFail();

        // Incrementar vistas sin tocar updated_at
        $post->incrementViews();

        // Artículos relacionados
        $related = Post::with(['author', 'category'])
            ->relatedTo($post, 3)
            ->get();

        return $this->view('blog/show.twig', [
            'post'         => $post,
            'related'      => $related,
            'categories'   => $this->sidebarCategories(),
            'popular_tags' => $this->sidebarTags(),
            // SEO dinámico
            'page_title'       => $post->seoTitle,
            'meta_description' => $post->seoDescription,
            'og_image'         => $post->featured_image
                                    ? (($_ENV['APP_URL'] ?? '') . '/' . ltrim($post->featured_image, '/'))
                                    : null,
            'canonical_url'    => ($_ENV['APP_URL'] ?? '') . $post->url,
        ]);
    }

    // ── Sidebar compartido ────────────────────────────────────────────────

    private function sidebarCategories(): \Illuminate\Database\Eloquent\Collection
    {
        return Category::forMenu()->withCount([
            'posts' => fn($q) => $q->published(),
        ])->get();
    }

    private function sidebarTags(): \Illuminate\Database\Eloquent\Collection
    {
        return Tag::popular(15)->get();
    }
}
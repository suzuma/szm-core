<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Post;
use App\Models\Tag;
use App\Helpers\Flash;
use App\Helpers\OldInput;
use App\Services\EditorJsConverter;
use App\Services\HtmlSanitizer;
use Carbon\Carbon;
use Core\Auth\Auth;

/**
 * PostAdminController — backoffice de gestión de posts.
 *
 * Rutas (filtro 'admin'):
 *   GET    /admin/blog               → index()
 *   GET    /admin/blog/create        → create()
 *   POST   /admin/blog               → store()
 *   GET    /admin/blog/{id}/edit     → edit()
 *   PUT    /admin/blog/{id}          → update()
 *   DELETE /admin/blog/{id}          → destroy()
 *   POST   /admin/blog/{id}/publish  → publish()   (acción rápida)
 *   POST   /admin/blog/slug-preview  → slugPreview() (AJAX)
 */
class PostAdminController extends BaseController
{
    private const PER_PAGE = 15;

    // ── Listado ───────────────────────────────────────────────────────────

    public function index(): string
    {
        $req    = $this->request();
        $search = trim($req->str('q'));
        $status = $req->str('status');
        $catId  = $req->int('category_id') ?: null;
        $page   = max(1, $req->int('page', 1));

        $query = Post::with(['author', 'category'])
            ->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where('title', 'like', "%{$search}%");
        }

        if (in_array($status, ['draft', 'published', 'scheduled'], true)) {
            $query->where('status', $status);
        }

        if ($catId !== null) {
            $query->where('category_id', $catId);
        }

        $total      = $query->count();
        $totalPages = (int) ceil($total / self::PER_PAGE);
        $posts      = $query->skip(($page - 1) * self::PER_PAGE)
                            ->take(self::PER_PAGE)
                            ->get();

        return $this->view('admin/blog/index.twig', [
            'posts'       => $posts,
            'categories'  => Category::forMenu()->get(),
            'search'      => $search,
            'filter_status'   => $status,
            'filter_category' => $catId,
            'page'        => $page,
            'total'       => $total,
            'total_pages' => $totalPages,
        ]);
    }

    // ── Formulario de creación ────────────────────────────────────────────

    public function create(): string
    {
        [$old, $errors] = OldInput::pull();

        return $this->view('admin/blog/form.twig', [
            'post'       => null,
            'categories' => Category::forMenu()->get(),
            'tags'       => Tag::orderBy('name')->get(),
            'old'        => $old,
            'errors'     => $errors,
        ]);
    }

    // ── Guardar nuevo post ────────────────────────────────────────────────

    public function store(): never
    {
        $form = StorePostRequest::fromRequest();

        if ($form->fails()) {
            OldInput::flash($this->request()->all(), $form->fieldErrors());
            Flash::set('error', $form->firstError());
            $this->redirect('/admin/blog/create');
        }

        $req  = $this->request();
        $post = new Post();

        $this->fillPost($post, $req);

        $post->author_id    = Auth::id();
        $post->slug         = Post::generateSlug($form->str('title'));
        $post->reading_time = $post->calculateReadingTime();
        $post->save();

        // Tags
        $tagIds = $this->resolveTagIds($req->str('tags'));
        $post->tags()->sync($tagIds);

        AuditLog::record(
            action: 'post.created',
            entity: $post,
            new:    ['title' => $post->title, 'status' => $post->status],
            userId: Auth::id(),
        );

        Flash::set('success', 'Artículo creado correctamente.');
        $this->redirect('/admin/blog');
    }

    // ── Formulario de edición ─────────────────────────────────────────────

    public function edit(int $id): string
    {
        $post   = Post::with('tags')->findOrFail($id);
        [$old, $errors] = OldInput::pull();

        return $this->view('admin/blog/form.twig', [
            'post'       => $post,
            'categories' => Category::forMenu()->get(),
            'tags'       => Tag::orderBy('name')->get(),
            'post_tags'  => $post->tags->pluck('id')->toArray(),
            'old'        => $old,
            'errors'     => $errors,
        ]);
    }

    // ── Actualizar post ───────────────────────────────────────────────────

    public function update(int $id): never
    {
        $post = Post::findOrFail($id);
        $form = UpdatePostRequest::fromRequest();

        if ($form->fails()) {
            OldInput::flash($this->request()->all(), $form->fieldErrors());
            Flash::set('error', $form->firstError());
            $this->redirect("/admin/blog/{$id}/edit");
        }

        $req    = $this->request();
        $oldSnap = ['title' => $post->title, 'status' => $post->status];

        // Regenerar slug solo si el título cambió
        if ($post->title !== $form->str('title')) {
            $post->slug = Post::generateSlug($form->str('title'), $id);
        }

        $this->fillPost($post, $req);
        $post->reading_time = $post->calculateReadingTime();
        $post->save();

        // Tags
        $tagIds = $this->resolveTagIds($req->str('tags'));
        $post->tags()->sync($tagIds);

        AuditLog::record(
            action: 'post.updated',
            entity: $post,
            old:    $oldSnap,
            new:    ['title' => $post->title, 'status' => $post->status],
            userId: Auth::id(),
        );

        Flash::set('success', 'Artículo actualizado correctamente.');
        $this->redirect('/admin/blog');
    }

    // ── Eliminar ──────────────────────────────────────────────────────────

    public function destroy(int $id): never
    {
        $post = Post::findOrFail($id);

        AuditLog::record(
            action: 'post.deleted',
            entity: $post,
            old:    ['title' => $post->title],
            userId: Auth::id(),
        );

        $post->tags()->detach();
        $post->delete();

        Flash::set('success', 'Artículo eliminado.');
        $this->redirect('/admin/blog');
    }

    // ── Publicar rápido (toggle draft ↔ published) ────────────────────────

    public function publish(int $id): never
    {
        $post = Post::findOrFail($id);

        if ($post->status === Post::STATUS_PUBLISHED) {
            $post->update(['status' => Post::STATUS_DRAFT, 'published_at' => null]);
            $action = 'post.unpublished';
        } else {
            $post->update([
                'status'       => Post::STATUS_PUBLISHED,
                'published_at' => $post->published_at ?? Carbon::now(),
            ]);
            $action = 'post.published';
        }

        AuditLog::record(action: $action, entity: $post, userId: Auth::id());

        $this->json(['ok' => true, 'status' => $post->status]);
    }

    // ── Vista previa de slug (AJAX) ───────────────────────────────────────

    public function slugPreview(): never
    {
        $title   = $this->request()->str('title');
        $id      = $this->request()->int('id') ?: null;
        $slug    = Post::generateSlug($title, $id);
        $this->json(['slug' => $slug]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    private function fillPost(Post $post, \Core\Http\Request $req): void
    {
        $status = $req->str('status');
        if (!in_array($status, ['draft', 'published', 'scheduled'], true)) {
            $status = 'draft';
        }

        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = $post->published_at ?? Carbon::now();
        } elseif ($status === 'scheduled') {
            $raw = $req->str('published_at');
            $publishedAt = $raw !== '' ? $raw : null;
        }

        $post->fill([
            'title'             => $req->str('title'),
            'category_id'       => $req->int('category_id') ?: null,
            'excerpt'           => $req->str('excerpt') ?: null,
            'content'           => $this->sanitizeContent($req->input('content', '')),
            'featured_image'    => $req->str('featured_image') ?: null,
            'featured_image_alt'=> $req->str('featured_image_alt') ?: null,
            'status'            => $status,
            'published_at'      => $publishedAt,
            'meta_title'        => $req->str('meta_title') ?: null,
            'meta_description'  => $req->str('meta_description') ?: null,
        ]);
    }

    /**
     * Convierte Editor.js JSON → HTML si aplica, luego sanea con HtmlSanitizer.
     */
    private function sanitizeContent(mixed $html): string
    {
        $str = (string) $html;
        if (EditorJsConverter::isEditorJs($str)) {
            $str = EditorJsConverter::toHtml($str);
        }
        return HtmlSanitizer::purify($str);
    }

    /**
     * Resuelve o crea tags a partir de una cadena CSV de nombres.
     * Retorna array de IDs.
     *
     * @return int[]
     */
    private function resolveTagIds(string $tagsInput): array
    {
        if (trim($tagsInput) === '') {
            return [];
        }

        $names = array_filter(
            array_map('trim', explode(',', $tagsInput))
        );

        $ids = [];
        foreach ($names as $name) {
            if ($name === '') continue;
            $tag = Tag::firstOrCreate(
                ['slug' => Tag::generateSlug($name)],
                ['name' => $name]
            );
            $ids[] = $tag->id;
        }

        return $ids;
    }
}
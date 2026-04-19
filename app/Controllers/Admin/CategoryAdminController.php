<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\AuditLog;
use App\Models\Category;
use Core\Auth\Auth;

/**
 * CategoryAdminController — CRUD AJAX de categorías del blog.
 *
 * Rutas (filtro 'admin'):
 *   GET    /admin/blog/categories         → index()
 *   POST   /admin/blog/categories         → store()
 *   PUT    /admin/blog/categories/{id}    → update()
 *   DELETE /admin/blog/categories/{id}    → destroy()
 *   PATCH  /admin/blog/categories/{id}/toggle → toggleActive()
 */
class CategoryAdminController extends BaseController
{
    // ── Listado ───────────────────────────────────────────────────────────

    public function index(): string
    {
        $categories = Category::withCount([
                'posts' => fn($q) => $q->published(),
            ])
            ->with('parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Lista plana de categorías para el selector de "padre"
        $all = Category::orderBy('name')->get(['id', 'name']);

        return $this->view('admin/blog/categories.twig', [
            'categories'     => $categories,
            'all_categories' => $all,
        ]);
    }

    // ── Crear ─────────────────────────────────────────────────────────────

    public function store(): never
    {
        $name      = trim($this->request()->str('name'));
        $desc      = trim($this->request()->str('description'));
        $parentId  = $this->request()->int('parent_id') ?: null;
        $sortOrder = $this->request()->int('sort_order', 0);

        if ($name === '') {
            $this->json(['ok' => false, 'error' => 'El nombre es obligatorio.'], 422);
        }

        $category = Category::create([
            'name'        => $name,
            'slug'        => Category::generateSlug($name),
            'description' => $desc ?: null,
            'parent_id'   => $parentId,
            'sort_order'  => $sortOrder,
            'active'      => true,
        ]);

        AuditLog::record(
            action: 'category.created',
            entity: $category,
            new:    ['name' => $category->name, 'slug' => $category->slug],
            userId: Auth::id(),
        );

        $this->json(['ok' => true, 'category' => [
            'id'          => $category->id,
            'name'        => $category->name,
            'slug'        => $category->slug,
            'description' => $category->description,
            'sort_order'  => $category->sort_order,
            'parent_id'   => $category->parent_id,
            'posts_count' => 0,
            'active'      => true,
        ]]);
    }

    // ── Actualizar ────────────────────────────────────────────────────────

    public function update(int $id): never
    {
        $category  = Category::findOrFail($id);
        $name      = trim($this->request()->str('name'));
        $desc      = trim($this->request()->str('description'));
        $parentId  = $this->request()->int('parent_id') ?: null;
        $sortOrder = $this->request()->int('sort_order', 0);

        if ($name === '') {
            $this->json(['ok' => false, 'error' => 'El nombre es obligatorio.'], 422);
        }

        if ($parentId === $id) {
            $this->json(['ok' => false, 'error' => 'Una categoría no puede ser su propio padre.'], 422);
        }

        $old = ['name' => $category->name, 'slug' => $category->slug];

        if ($category->name !== $name) {
            $category->slug = Category::generateSlug($name, $id);
        }

        $category->update([
            'name'        => $name,
            'slug'        => $category->slug,
            'description' => $desc ?: null,
            'parent_id'   => $parentId,
            'sort_order'  => $sortOrder,
        ]);

        AuditLog::record(
            action: 'category.updated',
            entity: $category,
            old:    $old,
            new:    ['name' => $category->name, 'slug' => $category->slug],
            userId: Auth::id(),
        );

        $this->json(['ok' => true, 'category' => [
            'id'         => $category->id,
            'name'       => $category->name,
            'slug'       => $category->slug,
            'sort_order' => $category->sort_order,
        ]]);
    }

    // ── Eliminar ──────────────────────────────────────────────────────────

    public function destroy(int $id): never
    {
        $category = Category::findOrFail($id);

        if ($category->posts()->count() > 0) {
            $this->json([
                'ok'    => false,
                'error' => 'No se puede eliminar una categoría con artículos asociados.',
            ], 422);
        }

        if ($category->children()->count() > 0) {
            $this->json([
                'ok'    => false,
                'error' => 'No se puede eliminar una categoría que tiene subcategorías.',
            ], 422);
        }

        AuditLog::record(
            action: 'category.deleted',
            entity: $category,
            old:    ['name' => $category->name],
            userId: Auth::id(),
        );

        $category->delete();
        $this->json(['ok' => true]);
    }

    // ── Toggle activo ─────────────────────────────────────────────────────

    public function toggleActive(int $id): never
    {
        $category  = Category::findOrFail($id);
        $wasActive = (bool) $category->active;

        $category->update(['active' => !$wasActive]);

        AuditLog::record(
            action: $wasActive ? 'category.deactivated' : 'category.activated',
            entity: $category,
            old:    ['active' => $wasActive],
            new:    ['active' => !$wasActive],
            userId: Auth::id(),
        );

        $this->json(['ok' => true, 'active' => !$wasActive]);
    }
}
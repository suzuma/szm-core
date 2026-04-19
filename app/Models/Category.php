<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category — categoría del blog.
 *
 * Tabla: blog_categories
 *
 * @property int         $id
 * @property int|null    $parent_id
 * @property string      $name
 * @property string      $slug
 * @property string|null $description
 * @property int         $sort_order
 * @property bool        $active
 */
class Category extends BaseModel
{
    use HasSlug, Auditable;

    protected $table    = 'blog_categories';
    protected $fillable = ['parent_id', 'name', 'slug', 'description', 'sort_order', 'active'];

    protected $casts = [
        'active'     => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected array $auditExclude = [];

    // ── Relaciones ────────────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Solo categorías raíz (sin padre). */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Ordenadas para menú. */
    public function scopeForMenu(Builder $query): Builder
    {
        return $query->active()->orderBy('sort_order')->orderBy('name');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Número de posts publicados en esta categoría. */
    public function publishedPostsCount(): int
    {
        return $this->posts()->published()->count();
    }
}
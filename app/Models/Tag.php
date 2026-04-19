<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tag — etiqueta del blog.
 *
 * Tabla: blog_tags
 * Pivot: blog_post_tag
 *
 * @property int    $id
 * @property string $name
 * @property string $slug
 */
class Tag extends BaseModel
{
    use HasSlug;

    protected $table    = 'blog_tags';
    protected $fillable = ['name', 'slug'];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'blog_post_tag', 'tag_id', 'post_id');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    /** Tags con al menos un post publicado, ordenadas por uso. */
    public function scopePopular(Builder $query, int $limit = 20): Builder
    {
        return $query->withCount(['posts' => fn($q) => $q->published()])
                     ->having('posts_count', '>', 0)
                     ->orderByDesc('posts_count')
                     ->limit($limit);
    }
}
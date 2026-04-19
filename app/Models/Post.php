<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasSlug;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Post — artículo del blog.
 *
 * Tabla: blog_posts
 *
 * @property int         $id
 * @property int         $author_id
 * @property int|null    $category_id
 * @property string      $title
 * @property string      $slug
 * @property string|null $excerpt
 * @property string      $content           HTML del editor
 * @property string|null $featured_image
 * @property string|null $featured_image_alt
 * @property string      $status            draft | published | scheduled
 * @property Carbon|null $published_at
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property int         $views
 * @property int         $reading_time      minutos estimados
 */
class Post extends BaseModel
{
    use HasSlug, Auditable;

    protected $table    = 'blog_posts';
    protected $fillable = [
        'author_id', 'category_id',
        'title', 'slug', 'excerpt', 'content',
        'featured_image', 'featured_image_alt',
        'status', 'published_at',
        'meta_title', 'meta_description',
        'views', 'reading_time',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'views'        => 'integer',
        'reading_time' => 'integer',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    protected array $auditExclude = ['views'];

    // ── Constantes de estado ──────────────────────────────────────────────

    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_SCHEDULED = 'scheduled';

    // ── Relaciones ────────────────────────────────────────────────────────

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag', 'post_id', 'tag_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'post_id');
    }

    public function featuredMedia(): HasMany
    {
        return $this->hasMany(Media::class, 'post_id')->where('collection', 'featured');
    }

    // ── Scopes de estado ──────────────────────────────────────────────────

    /** Posts visibles al público: published + published_at <= ahora. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
                     ->where('published_at', '<=', Carbon::now());
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
                     ->where('published_at', '>', Carbon::now());
    }

    /** Todos los no-borrador (para backoffice). */
    public function scopeNotDraft(Builder $query): Builder
    {
        return $query->where('status', '!=', self::STATUS_DRAFT);
    }

    // ── Scopes de filtro ──────────────────────────────────────────────────

    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    public function scopeByTag(Builder $query, string $tagSlug): Builder
    {
        return $query->whereHas('tags', fn($q) => $q->where('slug', $tagSlug));
    }

    public function scopeRelatedTo(Builder $query, Post $post, int $limit = 3): Builder
    {
        return $query->published()
                     ->where('id', '!=', $post->id)
                     ->when(
                         $post->category_id,
                         fn($q) => $q->where('category_id', $post->category_id)
                     )
                     ->latest('published_at')
                     ->limit($limit);
    }

    // ── Atributos calculados ──────────────────────────────────────────────

    /** Excerpt visible: usa el manual si existe, sino auto-genera desde content. */
    public function getExcerptTextAttribute(): string
    {
        if ($this->excerpt !== null && $this->excerpt !== '') {
            return $this->excerpt;
        }

        $plain = strip_tags($this->content);
        return mb_substr($plain, 0, 200, 'UTF-8') . (mb_strlen($plain) > 200 ? '…' : '');
    }

    /** URL pública del post. */
    public function getUrlAttribute(): string
    {
        return '/blog/' . $this->slug;
    }

    /** URL del meta title (usa meta_title si existe, sino title). */
    public function getSeoTitleAttribute(): string
    {
        return $this->meta_title ?: $this->title;
    }

    /** Descripción SEO (usa meta_description si existe, sino excerpt auto). */
    public function getSeoDescriptionAttribute(): string
    {
        return $this->meta_description ?: mb_substr(strip_tags($this->excerptText), 0, 160, 'UTF-8');
    }

    // ── Helpers de negocio ────────────────────────────────────────────────

    /**
     * Calcula y guarda el tiempo de lectura estimado.
     * Promedio: 200 palabras por minuto.
     * Se llama antes de guardar en el controlador.
     */
    public function calculateReadingTime(): int
    {
        $words = str_word_count(strip_tags($this->content));
        return (int) max(1, ceil($words / 200));
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED
            && $this->published_at !== null
            && $this->published_at->isFuture();
    }

    /** Incrementa el contador de vistas de forma silenciosa. */
    public function incrementViews(): void
    {
        $this->timestamps = false;
        $this->increment('views');
        $this->timestamps = true;
    }
}
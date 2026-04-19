<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Media — archivo subido al blog.
 *
 * Tabla: blog_media
 * Un post puede tener muchos Media en distintas colecciones
 * (featured, gallery, document…).
 *
 * @property int         $id
 * @property int|null    $post_id
 * @property string      $collection
 * @property string      $filename
 * @property string      $original_name
 * @property string      $path
 * @property string      $url
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property int|null    $width
 * @property int|null    $height
 * @property string|null $alt_text
 * @property int|null    $uploaded_by
 */
class Media extends BaseModel
{
    protected $table      = 'blog_media';
    public    $timestamps = false;          // solo created_at, manejado por MySQL

    protected $fillable = [
        'post_id', 'collection',
        'filename', 'original_name', 'path', 'url',
        'mime_type', 'size_bytes',
        'width', 'height', 'alt_text',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes'  => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
        'post_id'     => 'integer',
        'uploaded_by' => 'integer',
        'created_at'  => 'datetime',
    ];

    // ── Relaciones ────────────────────────────────────────────────────────

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('collection', 'featured');
    }

    public function scopeGallery(Builder $query): Builder
    {
        return $query->where('collection', 'gallery');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Tamaño legible: "1.2 MB", "320 KB". */
    public function humanSize(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
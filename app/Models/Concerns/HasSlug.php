<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * HasSlug — genera y garantiza slugs únicos en la tabla del modelo.
 *
 * Uso:
 *   class Post extends BaseModel
 *   {
 *       use HasSlug;
 *
 *       protected string $slugSource = 'title';   // columna origen (default: 'title')
 *       protected string $slugColumn = 'slug';    // columna destino (default: 'slug')
 *   }
 *
 *   // En el controlador:
 *   $post->slug = Post::generateSlug($request->str('title'));
 *   // O al crear:
 *   $post = Post::create([..., 'slug' => Post::generateSlug($title)]);
 *
 * Colisión: "mi-articulo" → "mi-articulo-2" → "mi-articulo-3" …
 * El modelo actual se excluye para permitir actualizaciones sin colisión.
 */
trait HasSlug
{
    /** Columna de origen para generar el slug (override en el modelo). */
    protected string $slugSource = 'title';

    /** Columna donde se almacena el slug (override en el modelo). */
    protected string $slugColumn = 'slug';

    // ── API pública ───────────────────────────────────────────────────────

    /**
     * Genera un slug único para la tabla del modelo.
     *
     * @param string   $value    Texto base (ej. el título del post)
     * @param int|null $excludeId ID a excluir de la búsqueda de colisión (al editar)
     */
    public static function generateSlug(string $value, ?int $excludeId = null): string
    {
        $instance   = new static();
        $base       = self::slugify($value);
        $slugColumn = $instance->slugColumn;

        $slug      = $base;
        $suffix    = 2;

        while (true) {
            $query = static::where($slugColumn, $slug);

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            if (!$query->exists()) {
                break;
            }

            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * Scope para buscar por slug.
     *
     * Ejemplo: Post::bySlug('mi-articulo')->firstOrFail()
     */
    public function scopeBySlug(Builder $query, string $slug): Builder
    {
        return $query->where($this->slugColumn, $slug);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * Convierte texto arbitrario a un slug ASCII URL-friendly.
     *
     * Ejemplos:
     *   "¿Cómo optimizar PHP 8.3?" → "como-optimizar-php-83"
     *   "  Tilde & más  "          → "tilde-mas"
     *   "Hello World!!!"           → "hello-world"
     */
    private static function slugify(string $value): string
    {
        // 1. Transliterar caracteres Unicode → ASCII
        if (\function_exists('transliterator_transliterate')) {
            $slug = \transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $value);
        } else {
            $slug = false;
        }

        if ($slug === false || $slug === '') {
            // Fallback si la extensión intl no está disponible
            $slug = \mb_strtolower($value, 'UTF-8');
            $map  = [
                'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u',
                'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u',
                'ä'=>'a','ë'=>'e','ï'=>'i','ö'=>'o','ü'=>'u',
                'â'=>'a','ê'=>'e','î'=>'i','ô'=>'o','û'=>'u',
                'ñ'=>'n','ç'=>'c','ß'=>'ss','ã'=>'a','õ'=>'o',
            ];
            $slug = \strtr($slug, $map);
        }

        // 2. Minúsculas
        $slug = mb_strtolower($slug, 'UTF-8');

        // 3. Reemplazar todo lo que no sea letra, número o guion
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? '';

        // 4. Espacios y guiones múltiples → guion simple
        $slug = preg_replace('/[\s-]+/', '-', trim($slug)) ?? '';

        // 5. Recortar guiones del inicio/fin
        return trim($slug, '-');
    }
}
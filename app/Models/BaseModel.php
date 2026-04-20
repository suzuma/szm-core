<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
/**
 * BaseModel
 *
 * Modelo Eloquent base para todos los modelos de la aplicación.
 *
 * Ubicación: app/Models/BaseModel.php
 *
 * Qué centraliza:
 *  - Protección contra mass assignment accidental
 *  - Casts de fechas por defecto
 *  - Scopes reutilizables (search, active, latest, oldest)
 *  - Helpers de conversión (toArray limpio, toJson)
 *  - Constantes de timestamps configurables por hijo
 *
 * Qué NO hace:
 *  - No define $table  → cada modelo hijo la define
 *  - No define $fillable → cada modelo hijo la define
 *  - No contiene lógica de negocio
 *
 * Uso:
 *   final class User extends BaseModel { ... }
 *   final class Producto extends BaseModel { ... }
 */
abstract class BaseModel extends Model
{
    /* ------------------------------------------------------------------
     | TIMESTAMPS
     | Sobreescribibles en modelos hijos si usan nombres distintos
     ------------------------------------------------------------------ */
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    /* ------------------------------------------------------------------
     | MASS ASSIGNMENT
     | $guarded protege 'id' por defecto.
     | Cada hijo define su $fillable con los campos permitidos.
     ------------------------------------------------------------------ */
    protected $guarded = ['id'];

    /* ------------------------------------------------------------------
     | CASTS POR DEFECTO
     | Los modelos hijos pueden agregar más casts sin perder estos.
     ------------------------------------------------------------------ */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------
     | COLECCIONES
     | Illuminate 13 introdujo el atributo CollectedBy para colecciones
     | personalizadas. Cuando una clase hereda de un modelo intermedio
     | abstracto (este BaseModel), intenta hacer `new BaseModel()` para
     | resolver el atributo, lo que falla por ser abstracta.
     | Sobreescribimos newCollection() aquí para evitar esa ruta.
     ------------------------------------------------------------------ */

    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /* ==================================================================
     | SCOPES REUTILIZABLES
     | Disponibles en todos los modelos que extiendan BaseModel
     ================================================================== */

    /**
     * Búsqueda LIKE en múltiples columnas.
     *
     * Ejemplo:
     *   User::search('juan', ['name', 'email'])->get();
     *   Producto::search($term, ['nombre', 'descripcion', 'sku'])->paginate(20);
     */
    public function scopeSearch(Builder $query, ?string $term, array $columns): Builder
    {
        if (empty(trim((string) $term))) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term, $columns): void {
            foreach ($columns as $column) {
                $q->orWhere($column, 'LIKE', '%' . $term . '%');
            }
        });
    }

    /**
     * Filtra registros activos.
     * Requiere columna `active` (boolean/tinyint) en la tabla.
     *
     * Ejemplo:
     *   User::active()->get();
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Filtra registros inactivos.
     *
     * Ejemplo:
     *   User::inactive()->get();
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('active', false);
    }

    /**
     * Ordena por columna descendente (más reciente primero).
     *
     * Ejemplo:
     *   Post::latest()->limit(10)->get();
     *   Post::latest('published_at')->get();
     */
    public function scopeLatest(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->orderBy($column, 'desc');
    }

    /**
     * Ordena por columna ascendente (más antiguo primero).
     *
     * Ejemplo:
     *   Post::oldest()->get();
     */
    public function scopeOldest(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->orderBy($column, 'asc');
    }

    /**
     * Filtra por rango de fechas en una columna.
     *
     * Ejemplo:
     *   Pedido::betweenDates('2026-01-01', '2026-03-31')->get();
     *   Pedido::betweenDates('2026-01-01', '2026-03-31', 'updated_at')->get();
     */
    public function scopeBetweenDates(
        Builder $query,
        string  $from,
        string  $to,
        string  $column = 'created_at'
    ): Builder {
        return $query->whereBetween($column, [$from . ' 00:00:00', $to . ' 23:59:59']);
    }

    /**
     * Filtra registros creados hoy.
     *
     * Ejemplo:
     *   Acceso::today()->count();
     */
    public function scopeToday(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->whereDate($column, Carbon::now()->toDateString());
    }

    /**
     * Filtra registros creados esta semana.
     */
    public function scopeThisWeek(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->whereBetween($column, [
            Carbon::now()->startOfWeek()->toDateTimeString(),
            Carbon::now()->endOfWeek()->toDateTimeString(),
        ]);
    }

    /**
     * Filtra registros creados este mes.
     *
     * Ejemplo:
     *   Venta::thisMonth()->sum('total');
     */
    public function scopeThisMonth(Builder $query, string $column = 'created_at'): Builder
    {
        return $query->whereMonth($column, Carbon::now()->month)
            ->whereYear($column, Carbon::now()->year);
    }

    /* ==================================================================
     | HELPERS DE INSTANCIA
     ================================================================== */

    /**
     * Retorna el modelo como array limpio (sin atributos ocultos).
     * Útil para pasar datos a las vistas.
     */
    public function toCleanArray(): array
    {
        return $this->toArray();
    }

    /**
     * Verifica si el modelo fue creado recientemente (últimas N horas).
     *
     * Ejemplo:
     *   if ($user->isRecentlyCreated(24)) { ... }
     */
    public function isRecentlyCreated(int $hours = 1): bool
    {
        return $this->created_at !== null
            && $this->created_at->diffInHours(Carbon::now()) <= $hours;
    }

    /**
     * Retorna la fecha de creación formateada.
     *
     * Ejemplo:
     *   $model->createdAtFormatted()        → '12/03/2026'
     *   $model->createdAtFormatted('d M Y') → '12 Mar 2026'
     */
    public function createdAtFormatted(string $format = 'd/m/Y'): string
    {
        return $this->created_at?->format($format) ?? '';
    }
}
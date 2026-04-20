<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Auditable — trait para registrar automáticamente cambios en modelos Eloquent.
 *
 * Uso: agregar `use Auditable;` en cualquier modelo que necesite auditoría.
 *
 *   class Product extends BaseModel
 *   {
 *       use Auditable;
 *
 *       // Campos a excluir del log (ej. datos sensibles)
 *       protected array $auditExclude = ['password', 'remember_token'];
 *   }
 *
 * Genera entradas en szm_audit_log para create, update y delete.
 * Los valores nulos o sin cambio se omiten en el diff de 'model.updated'.
 */
trait Auditable
{
    /**
     * Campos que NUNCA deben aparecer en el audit log.
     * Override en el modelo para personalizar.
     */
    protected array $auditExclude = [
        'password',
        'reset_token',
        'reset_token_expires',
    ];

    protected static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            AuditLog::record(
                action: 'model.created',
                entity: $model,
                new:    $model->getAuditableAttributes(),
            );
        });

        static::updated(function (self $model): void {
            $old = array_intersect_key($model->getOriginal(), $model->getDirty());
            $new = $model->getDirty();

            // Excluir campos sensibles
            $exclude = $model->auditExclude ?? [];
            foreach ($exclude as $field) {
                unset($old[$field], $new[$field]);
            }

            if (empty($new)) {
                return; // Nada relevante cambió
            }

            AuditLog::record(
                action: 'model.updated',
                entity: $model,
                old:    $old,
                new:    $new,
            );
        });

        static::deleted(function (self $model): void {
            AuditLog::record(
                action: 'model.deleted',
                entity: $model,
                old:    $model->getAuditableAttributes(),
            );
        });
    }

    /**
     * Retorna los atributos del modelo excluyendo campos sensibles.
     */
    private function getAuditableAttributes(): array
    {
        $exclude = $this->auditExclude ?? [];
        return array_diff_key($this->getAttributes(), array_flip($exclude));
    }
}
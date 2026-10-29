<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes a before/after diff to the audit log whenever the model is created, updated or
 * deleted. Models can list extra attributes to leave out of the diff in $auditExclude.
 *
 * @mixin Model
 */
trait Auditable
{
    /**
     * Attributes never written to the audit log, for every model.
     *
     * @var list<string>
     */
    private static array $alwaysExcludedFromAudit = ['created_at', 'updated_at', 'password', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            $model->writeAudit('created', ['after' => $model->auditableAttributes($model->getAttributes())]);
        });

        static::updated(function (self $model): void {
            $after = $model->auditableAttributes($model->getChanges());

            if ($after === []) {
                return;
            }

            $before = array_intersect_key($model->getRawOriginal(), $after);

            $model->writeAudit('updated', ['before' => $before, 'after' => $after]);
        });

        static::deleted(function (self $model): void {
            $model->writeAudit('deleted');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function auditableAttributes(array $attributes): array
    {
        $excluded = [...self::$alwaysExcludedFromAudit, ...($this->auditExclude ?? [])];

        return array_diff_key($attributes, array_flip($excluded));
    }

    /**
     * @param  array<string, mixed>|null  $changes
     */
    protected function writeAudit(string $event, ?array $changes = null): void
    {
        app(AuditLogger::class)->record($this->getMorphClass().'.'.$event, $this, $changes);
    }
}

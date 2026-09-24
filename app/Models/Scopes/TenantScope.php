<?php

namespace App\Models\Scopes;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Tum sorgulara tenant_id kosulunu ekler.
 *
 * Amaci sadece kolaylik degil guvenliktir: bir ekrani gizlemek yetmez,
 * sorgunun kendisi baska kurumun verisini hic getirmemelidir.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::has()) {
            $builder->where($model->getTable().'.tenant_id', Tenancy::id());
        }
    }
}

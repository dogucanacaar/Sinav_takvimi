<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        // Yeni kayitlarda tenant_id otomatik doldurulur; unutulmasi
        // veri sizintisinin en kolay yoludur.
        static::creating(function ($model) {
            if ($model->tenant_id === null && Tenancy::has()) {
                $model->tenant_id = Tenancy::id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

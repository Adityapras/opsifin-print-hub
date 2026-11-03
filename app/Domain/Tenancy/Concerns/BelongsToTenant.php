<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();

            // Tanpa konteks tenant, hasil harus kosong agar worker atau request baru
            // tidak mewarisi data tenant lain secara tidak sengaja.
            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
        });

        static::creating(function (Model $model): void {
            $contextTenantId = app(TenantContext::class)->id();
            $modelTenantId = $model->getAttribute('tenant_id');

            if ($contextTenantId === null) {
                throw new LogicException('A tenant context is required when creating business data.');
            }

            if ($modelTenantId !== null && $modelTenantId !== $contextTenantId) {
                throw new LogicException('tenant_id does not match the active tenant context.');
            }

            $model->setAttribute('tenant_id', $contextTenantId);
        });
    }
}

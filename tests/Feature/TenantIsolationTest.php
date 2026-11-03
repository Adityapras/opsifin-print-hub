<?php

use App\Domain\Fleet\Models\Site;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;

it('membatasi model bisnis ke konteks tenant aktif dan default deny tanpa konteks', function () {
    $tenantA = Tenant::query()->create(['code' => 'a', 'name' => 'Tenant A']);
    $tenantB = Tenant::query()->create(['code' => 'b', 'name' => 'Tenant B']);
    $context = app(TenantContext::class);

    $context->set($tenantA->getKey());
    Site::query()->create(['name' => 'Site A']);

    $context->set($tenantB->getKey());
    Site::query()->create(['name' => 'Site B']);

    expect(Site::query()->pluck('name')->all())->toBe(['Site B']);

    $context->clear();

    expect(Site::query()->count())->toBe(0)
        ->and(Site::withoutGlobalScopes()->count())->toBe(2);
});

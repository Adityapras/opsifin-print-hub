<?php

use App\Domain\Fleet\Models\AgentPairingCode;
use App\Domain\Fleet\Models\Site;
use App\Domain\Tenancy\Models\Tenant;

it('menyiapkan tenant site dan pairing code untuk integrasi awal', function () {
    $this->artisan('hub:bootstrap-agent', [
        'tenant_code' => 'ACME',
        'tenant_name' => 'PT ACME',
        'site_name' => 'Kantor Jakarta',
    ])->assertSuccessful();

    expect(Tenant::query()->where('code', 'ACME')->count())->toBe(1)
        ->and(Site::withoutGlobalScopes()->where('name', 'Kantor Jakarta')->count())->toBe(1)
        ->and(AgentPairingCode::withoutGlobalScopes()->count())->toBe(1);
});

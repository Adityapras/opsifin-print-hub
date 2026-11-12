<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentPairingCode;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\AgentControlService;
use App\Domain\Fleet\Services\PairingCodeService;
use App\Domain\Fleet\Services\PrinterControlService;
use App\Domain\Identity\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

function grantFleetMembership(User $user, Tenant $tenant, string $role): void
{
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    } finally {
        $context->clear();
    }
}

function fleetFixture(string $code = 'fleet'): array
{
    $tenant = Tenant::query()->create(['code' => $code, 'name' => strtoupper($code)]);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        $site = Site::query()->create([
            'name' => 'Gudang '.$code,
            'pic_name' => 'Budi',
        ]);
        $agent = Agent::query()->create([
            'site_id' => $site->getKey(),
            'hostname' => 'PC-'.$code,
            'os' => 'Windows 11',
            'version' => '1.0.0',
            'machine_id' => 'machine-'.$code,
            'secret_hash' => hash('sha256', 'secret'),
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
        $printer = Printer::query()->create([
            'agent_id' => $agent->getKey(),
            'windows_name' => 'EPSON-'.$code,
            'alias' => 'Kasir '.$code,
            'status' => 'READY',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
    } finally {
        $context->clear();
    }

    return compact('tenant', 'site', 'agent', 'printer');
}

it('merender alur site agent dan printer hanya untuk tenant aktif', function () {
    $fleet = fleetFixture('fleet-a');
    fleetFixture('fleet-b');
    $admin = User::factory()->create();
    grantFleetMembership($admin, $fleet['tenant'], 'tenant_admin');

    $this->actingAs($admin)
        ->get('/admin/tenants/fleet-a/sites')
        ->assertOk()
        ->assertSee('Gudang fleet-a')
        ->assertDontSee('Gudang fleet-b');

    $this->get('/admin/tenants/fleet-a/agents')
        ->assertOk()
        ->assertSee('PC-fleet-a')
        ->assertSee('Create pairing code')
        ->assertSee('Revoke access')
        ->assertDontSee('PC-fleet-b');

    $this->get('/admin/tenants/fleet-a/printers')
        ->assertOk()
        ->assertSee('EPSON-fleet-a')
        ->assertDontSee('EPSON-fleet-b');
});

it('menghitung status agent dari umur heartbeat terakhir', function () {
    $fleet = fleetFixture('fleet-presence');
    $agent = $fleet['agent'];

    $agent->forceFill(['last_seen_at' => now()->subSeconds(89), 'revoked_at' => null])->save();
    expect($agent->fresh()->presence_status)->toBe('online');

    $agent->forceFill(['last_seen_at' => now()->subSeconds(120)])->save();
    expect($agent->fresh()->presence_status)->toBe('stale');

    $agent->forceFill(['last_seen_at' => now()->subMinutes(9)])->save();
    expect($agent->fresh()->presence_status)->toBe('offline');

    $agent->forceFill(['revoked_at' => now()])->save();
    expect($agent->fresh()->presence_status)->toBe('revoked');
});

it('menurunkan availability printer ketika sumber heartbeat agent kedaluwarsa', function () {
    $fleet = fleetFixture('fleet-printer-presence');
    $agent = $fleet['agent'];
    $printer = $fleet['printer'];
    $context = app(TenantContext::class);
    $context->set($fleet['tenant']->getKey());

    try {
        expect($printer->fresh()->effective_status)->toBe('READY');
        expect(Printer::query()->withEffectiveIssue()->count())->toBe(0);

        $agent->forceFill(['last_seen_at' => now()->subSeconds(120)])->save();
        expect($printer->fresh()->effective_status)->toBe('AGENT_STALE')
            ->and(Printer::query()->withEffectiveIssue()->count())->toBe(1);

        $agent->forceFill(['last_seen_at' => now()->subMinutes(9)])->save();
        expect($printer->fresh()->effective_status)->toBe('AGENT_OFFLINE')
            ->and($printer->fresh()->status)->toBe('READY');
    } finally {
        $context->clear();
    }

    $admin = User::factory()->create();
    grantFleetMembership($admin, $fleet['tenant'], 'tenant_admin');

    $this->actingAs($admin)
        ->get('/admin/tenants/fleet-printer-presence/printers')
        ->assertOk()
        ->assertSee('Agent offline')
        ->assertSee('Last report: Ready');

    $context->set($fleet['tenant']->getKey());

    try {
        $agent->forceFill(['revoked_at' => now()])->save();
        expect($printer->fresh()->effective_status)->toBe('AGENT_REVOKED');
    } finally {
        $context->clear();
    }
});

it('mencabut akses agent dan hanya menghapus agent tanpa printer', function () {
    $fleet = fleetFixture('fleet-revoke');
    $context = app(TenantContext::class);
    $context->set($fleet['tenant']->getKey());

    try {
        $unused = Agent::query()->create([
            'site_id' => $fleet['site']->getKey(),
            'hostname' => 'PC-UNUSED',
            'os' => 'Windows 11',
            'version' => '1.0.0',
            'machine_id' => 'machine-unused',
            'secret_hash' => hash('sha256', 'unused-secret'),
        ]);
        $service = app(AgentControlService::class);
        $revoked = $service->revoke($fleet['agent']);
        $cannotDelete = $service->deleteUnused($revoked);
        $service->revoke($unused);
        $deleted = $service->deleteUnused($unused);
    } finally {
        $context->clear();
    }

    $printer = Printer::withoutGlobalScopes()->findOrFail($fleet['printer']->getKey());

    expect($revoked->revoked_at)->not->toBeNull()
        ->and($revoked->presence_status)->toBe('revoked')
        ->and($printer->status)->toBe('OFFLINE')
        ->and($cannotDelete)->toBeFalse()
        ->and($deleted)->toBeTrue()
        ->and(Agent::withoutGlobalScopes()->find($unused->getKey()))->toBeNull();
});

it('membatasi konfigurasi armada tetapi mengizinkan operator melihat printer', function () {
    $fleet = fleetFixture('fleet-role');
    $operator = User::factory()->create();
    grantFleetMembership($operator, $fleet['tenant'], 'operator');

    $this->actingAs($operator)
        ->get('/admin/tenants/fleet-role/printers')
        ->assertOk();

    $this->get("/admin/tenants/fleet-role/printers/{$fleet['printer']->getKey()}/edit")
        ->assertForbidden();

    $this->get('/admin/tenants/fleet-role/sites/create')
        ->assertForbidden();
});

it('menaikkan config version setiap printer diubah atau dijeda', function () {
    $fleet = fleetFixture('fleet-config');
    $context = app(TenantContext::class);
    $context->set($fleet['tenant']->getKey());

    try {
        $service = app(PrinterControlService::class);
        $updated = $service->update($fleet['printer'], [
            'alias' => 'Kasir utama',
            'print_settings' => ['engine' => 'pdfium', 'copies' => 2],
        ]);
        $paused = $service->setPaused($updated, true);
    } finally {
        $context->clear();
    }

    expect($paused->alias)->toBe('Kasir utama')
        ->and($paused->is_paused)->toBeTrue()
        ->and(Agent::withoutGlobalScopes()->findOrFail($fleet['agent']->getKey())->config_version)->toBe(3);
});

it('menerbitkan pairing code sekali pakai untuk site tenant', function () {
    $fleet = fleetFixture('fleet-pair');
    $context = app(TenantContext::class);
    $context->set($fleet['tenant']->getKey());

    try {
        $code = app(PairingCodeService::class)->issue($fleet['site']);
        $pairing = AgentPairingCode::query()->sole();
    } finally {
        $context->clear();
    }

    expect($code)->toHaveLength(8)
        ->and($pairing->code_hash)->toBe(hash('sha256', $code))
        ->and($pairing->expires_at->isFuture())->toBeTrue();
});

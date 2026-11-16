<?php

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Services\InternalAccessService;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Middleware\SetFilamentTenantContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Livewire\Livewire;

function grantPanelMembership(User $user, Tenant $tenant, string $role): Membership
{
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        return Membership::query()->create([
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);
    } finally {
        $context->clear();
    }
}

function createPanelSource(Tenant $tenant, string $name, string $password = 'very-secret'): SourceConnection
{
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        return SourceConnection::query()->create([
            'name' => $name,
            'base_url' => 'https://'.str($name)->slug().'.test/api',
            'username' => 'api-user',
            'password_enc' => $password,
        ]);
    } finally {
        $context->clear();
    }
}

it('menjadikan tenant sebagai master internal dan membatasi daftar sesuai akses tim', function () {
    $tenantA = Tenant::query()->create(['code' => 'master-a', 'name' => 'Master A']);
    Tenant::query()->create(['code' => 'master-b', 'name' => 'Master B']);
    $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($platformAdmin)
        ->get('/admin/tenants')
        ->assertOk()
        ->assertSee('Master A')
        ->assertSee('Master B')
        ->assertSee('/admin/tenants/master-a', false)
        ->assertSee('New tenant');

    $operator = User::factory()->create();
    grantPanelMembership($operator, $tenantA, 'operator');

    $this->actingAs($operator)
        ->get('/admin/tenants')
        ->assertOk()
        ->assertSee('Master A')
        ->assertDontSee('Master B')
        ->assertDontSee('New tenant');
});

it('membuat integration id permanen dan unik untuk setiap tenant', function () {
    $tenantA = Tenant::query()->create(['code' => 'identity-a', 'name' => 'Identity A']);
    $tenantB = Tenant::query()->create(['code' => 'identity-b', 'name' => 'Identity B']);

    expect($tenantA->integration_id)->not->toBeNull()
        ->and($tenantB->integration_id)->not->toBeNull()
        ->and($tenantA->integration_id)->not->toBe($tenantB->integration_id);

    $tenantA->update(['name' => 'Identity A Renamed']);

    expect($tenantA->fresh()->integration_id)->toBe($tenantA->integration_id);
});

it('mempertahankan identifikasi tenant pada request Livewire', function () {
    $persistentMiddleware = Livewire::getPersistentMiddleware();

    expect($persistentMiddleware)
        ->toContain(SetFilamentTenantContext::class);
});

it('mempertahankan konteks domain sampai aksi Livewire selesai', function () {
    $tenant = Tenant::query()->create(['code' => 'livewire', 'name' => 'Livewire Tenant']);
    $user = User::factory()->create();
    grantPanelMembership($user, $tenant, 'tenant_admin');
    $context = app(TenantContext::class);
    $request = Request::create(
        '/admin/tenants/livewire/source-connections/create',
        'POST',
        server: ['HTTP_X_LIVEWIRE' => 'true'],
    );
    $route = new Route('POST', '/admin/tenants/{tenant}/source-connections/create', fn (): null => null);
    $route->bind($request);
    $route->setParameter('tenant', $tenant->code);
    $request->setRouteResolver(fn (): Route => $route);

    $this->actingAs($user);

    try {
        app(SetFilamentTenantContext::class)->handle($request, function () use ($context, $tenant): null {
            expect($context->id())->toBe($tenant->getKey());

            return null;
        });

        // Aksi komponen dijalankan setelah pipeline persistent selesai.
        expect($context->id())->toBe($tenant->getKey());
    } finally {
        $context->clear();
    }
});

it('hanya menampilkan data tenant yang ada di URL panel', function () {
    $tenantA = Tenant::query()->create(['code' => 'panel-a', 'name' => 'Panel A']);
    $tenantB = Tenant::query()->create(['code' => 'panel-b', 'name' => 'Panel B']);
    $user = User::factory()->create();
    grantPanelMembership($user, $tenantA, 'tenant_admin');
    createPanelSource($tenantA, 'Sumber Tenant A');
    createPanelSource($tenantB, 'Sumber Tenant B');

    $this->actingAs($user)
        ->get('/admin/tenants/panel-a/source-connections')
        ->assertOk()
        ->assertSee('fi-page-has-sub-navigation-end', false)
        ->assertSee('fi-page-sub-navigation-sidebar', false)
        ->assertSee('Source Connections')
        ->assertSee('What to configure')
        ->assertSee('Sumber Tenant A')
        ->assertDontSee('Sumber Tenant B');

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('menampilkan setup guide visual dengan readiness tenant', function () {
    $tenant = Tenant::query()->create(['code' => 'guided', 'name' => 'Guided Tenant']);
    $admin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($admin)
        ->get('/admin/tenants/guided/setup')
        ->assertOk()
        ->assertSee('End-to-end tenant setup')
        ->assertSee('Register the integration identity')
        ->assertSee('Connect and verify Opsifin Main')
        ->assertSee('Run the end-to-end print test')
        ->assertSee('tenant-setup-flow', false);
});

it('menolak user membuka tenant tanpa membership', function () {
    $tenantA = Tenant::query()->create(['code' => 'member-a', 'name' => 'Member A']);
    Tenant::query()->create(['code' => 'member-b', 'name' => 'Member B']);
    $user = User::factory()->create();
    grantPanelMembership($user, $tenantA, 'tenant_admin');

    $this->actingAs($user)
        ->get('/admin/tenants/member-b/source-connections')
        ->assertNotFound();
});

it('membatasi perubahan konfigurasi ke tenant admin', function () {
    $tenant = Tenant::query()->create(['code' => 'viewer', 'name' => 'Viewer Tenant']);
    $viewer = User::factory()->create();
    grantPanelMembership($viewer, $tenant, 'viewer');

    $this->actingAs($viewer)
        ->get('/admin/tenants/viewer/source-connections/create')
        ->assertForbidden();

    $admin = User::factory()->create();
    grantPanelMembership($admin, $tenant, 'tenant_admin');

    $this->actingAs($admin)
        ->get('/admin/tenants/viewer/source-connections/create')
        ->assertOk();
});

it('tidak pernah menampilkan ulang password sumber di form edit', function () {
    $tenant = Tenant::query()->create(['code' => 'secret', 'name' => 'Secret Tenant']);
    $admin = User::factory()->create();
    grantPanelMembership($admin, $tenant, 'tenant_admin');
    $source = createPanelSource($tenant, 'Secret Source', 'do-not-render-me');

    $this->actingAs($admin)
        ->get("/admin/tenants/secret/source-connections/{$source->getKey()}/edit")
        ->assertOk()
        ->assertDontSee('do-not-render-me');
});

it('merender observabilitas job dan callback tenant', function () {
    $tenant = Tenant::query()->create(['code' => 'operations', 'name' => 'Operations Tenant']);
    $user = User::factory()->create();
    grantPanelMembership($user, $tenant, 'operator');
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        $site = Site::query()->create(['name' => 'Gudang']);
        $agent = Agent::query()->create([
            'site_id' => $site->getKey(),
            'hostname' => 'PRINT-PC',
            'os' => 'Windows 11',
            'version' => '1.0.0',
            'machine_id' => 'print-pc',
            'secret_hash' => hash('sha256', 'secret'),
        ]);
        $printer = Printer::query()->create([
            'agent_id' => $agent->getKey(),
            'windows_name' => 'EPSON-LQ',
            'is_active' => true,
        ]);
        $source = createPanelSource($tenant, 'Operations Source');
        $context->set($tenant->getKey());
        $binding = PrinterBinding::query()->create([
            'source_connection_id' => $source->getKey(),
            'printer_id' => $printer->getKey(),
            'source_printer_name' => 'Gudang 01',
            'source_identifier' => 'GDG-01',
            'printer_version' => 1,
        ]);
        $job = PrintJob::query()->create([
            'binding_id' => $binding->getKey(),
            'printer_id' => $printer->getKey(),
            'inv_no' => 'INV-PANEL-001',
            'item_type' => 'INV',
            'version_inv' => 1,
            'state' => PrintJobState::Failed,
            'failure_code' => 'PAPER_OUT',
            'available_at' => now(),
            'received_at' => now(),
        ]);
        $job->events()->create([
            'actor_type' => 'agent',
            'type' => 'print_error',
            'from_state' => PrintJobState::Printing->value,
            'to_state' => PrintJobState::Failed->value,
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);
        $outbox = CallbackOutbox::query()->create([
            'job_id' => $job->getKey(),
            'source_connection_id' => $source->getKey(),
            'endpoint' => $source->base_url,
            'payload' => ['InvNo' => $job->inv_no, 'PrintProcess' => 'Failed'],
            'status' => 'pending',
            'next_attempt_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    } finally {
        $context->clear();
    }

    $this->actingAs($user)
        ->get('/admin/tenants/operations')
        ->assertOk()
        ->assertSee('fi-page-has-sub-navigation-end', false)
        ->assertSee('fi-page-sub-navigation-sidebar', false)
        ->assertDontSee('fi-page-sub-navigation-tabs', false)
        ->assertSee('Overview')
        ->assertSee('Setup guide')
        ->assertSee('Integration identity')
        ->assertDontSee('Review tenant status, estate totals, and routing topology.')
        ->assertSee('What to configure')
        ->assertSee('What to monitor')
        ->assertSee('Tenant overview')
        ->assertSee('Managed estate')
        ->assertSee('/admin/tenants/operations/source-connections', false)
        ->assertSee('/admin/tenants/operations/sites', false)
        ->assertSee('/admin/tenants/operations/agents', false);

    $this->actingAs($user)
        ->get('/admin/tenants/operations/print-jobs')
        ->assertOk()
        ->assertSee('INV-PANEL-001')
        ->assertSee('PAPER_OUT')
        ->assertSee('Activity');

    $this->get("/admin/tenants/operations/print-jobs/{$job->getKey()}")
        ->assertNotFound();

    $this->get('/admin/tenants/operations/callback-outboxes')
        ->assertOk()
        ->assertSee('INV-PANEL-001')
        ->assertSee('Details');

    $this->get("/admin/tenants/operations/callback-outboxes/{$outbox->getKey()}")
        ->assertNotFound();
});

it('memberi membership tenant melalui command bootstrap', function () {
    Tenant::query()->create(['code' => 'command', 'name' => 'Command Tenant']);
    $user = User::factory()->create(['email' => 'admin@example.test']);

    $this->artisan('hub:grant-tenant-user', [
        'email' => $user->email,
        'tenant_code' => 'command',
        '--role' => 'operator',
    ])->assertSuccessful();

    expect(Membership::withoutGlobalScopes()->sole()->role)->toBe('operator');
});

it('merender control plane global dan membatasi menu administratif', function () {
    $tenant = Tenant::query()->create(['code' => 'control-plane', 'name' => 'Control Plane Tenant']);
    $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

    $this->actingAs($platformAdmin)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Global Dashboard')
        ->assertSee('Control plane pulse');

    $this->get('/admin/global-monitoring')
        ->assertOk()
        ->assertSee('Global Monitoring')
        ->assertSee('Control Plane Tenant');

    $this->get('/admin/internal-team')
        ->assertOk()
        ->assertSee('Internal Team &amp; Access', false);

    $this->get('/admin/platform-settings')
        ->assertOk()
        ->assertSee('Internal team only')
        ->assertSee('Database queue')
        ->assertDontSee('/horizon', false);

    $this->get('/admin/tenants/control-plane/identity')
        ->assertOk()
        ->assertSee('Integration Identity')
        ->assertSee($tenant->integration_id);

    $operator = User::factory()->create();
    grantPanelMembership($operator, $tenant, 'operator');

    $this->actingAs($operator)
        ->get('/admin/internal-team')
        ->assertForbidden();

    $this->get('/admin/platform-settings')
        ->assertForbidden();

    $this->get('/admin/documentation')
        ->assertOk()
        ->assertSee('From invoice decision to verified print.')
        ->assertSee('End-to-end document journey.')
        ->assertSee('Onboard a tenant safely.');
});

it('mengarsipkan tenant tanpa menghapus estate dan menghentikan akses agent', function () {
    $secret = 'archive-agent-secret';
    $tenant = Tenant::query()->create(['code' => 'archive-me', 'name' => 'Archive Me']);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        $site = Site::query()->create(['name' => 'Jakarta Office']);
        $agent = Agent::query()->create([
            'site_id' => $site->getKey(),
            'hostname' => 'PC-KASIR-01',
            'os' => 'Windows 11',
            'version' => '1.0.0',
            'machine_id' => 'archive-pc',
            'secret_hash' => hash('sha256', $secret),
        ]);
        $printer = Printer::query()->create([
            'agent_id' => $agent->getKey(),
            'windows_name' => 'EPSON L3110',
            'is_active' => true,
        ]);
    } finally {
        $context->clear();
    }

    $tenant->delete();

    $archived = Tenant::withTrashed()->findOrFail($tenant->getKey());

    expect($archived->trashed())->toBeTrue()
        ->and($archived->status)->toBe('suspended')
        ->and(Site::withoutGlobalScopes()->find($site->getKey()))->not->toBeNull()
        ->and(Agent::withoutGlobalScopes()->find($agent->getKey()))->not->toBeNull()
        ->and(Printer::withoutGlobalScopes()->find($printer->getKey()))->not->toBeNull();

    $this->postJson('/agent/v1/heartbeat', [
        'agent_version' => '1.0.0',
        'uptime_s' => 10,
        'agent_time' => now()->toIso8601String(),
        'config_version' => 0,
        'capabilities' => [],
        'printers' => [],
    ], [
        'Authorization' => "Bearer {$agent->getKey()}.{$secret}",
        'X-Agent-Version' => '1.0.0',
    ])->assertUnauthorized();

    $archived->restore();

    expect($archived->fresh()->status)->toBe('suspended');
});

it('menyinkronkan akses tim internal tanpa membawa konteks tenant antar assignment', function () {
    $tenantA = Tenant::query()->create(['code' => 'access-a', 'name' => 'Access A']);
    $tenantB = Tenant::query()->create(['code' => 'access-b', 'name' => 'Access B']);
    $user = User::factory()->create();
    $context = app(TenantContext::class);

    app(InternalAccessService::class)->sync($user, [
        ['tenant_id' => $tenantA->getKey(), 'role' => 'operator'],
        ['tenant_id' => $tenantB->getKey(), 'role' => 'viewer'],
    ]);

    expect($context->id())->toBeNull()
        ->and($user->memberships()->count())->toBe(2)
        ->and($user->memberships()->where('tenant_id', $tenantA->getKey())->sole()->role)->toBe('operator')
        ->and($user->memberships()->where('tenant_id', $tenantB->getKey())->sole()->role)->toBe('viewer');

    app(InternalAccessService::class)->sync($user, [
        ['tenant_id' => $tenantB->getKey(), 'role' => 'tenant_admin'],
    ]);

    expect($user->memberships()->count())->toBe(1)
        ->and($user->memberships()->sole()->tenant_id)->toBe($tenantB->getKey())
        ->and($user->memberships()->sole()->role)->toBe('tenant_admin')
        ->and($context->id())->toBeNull();
});

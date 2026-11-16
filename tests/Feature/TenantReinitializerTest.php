<?php

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Identity\Models\Membership;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TenantReinitializer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('reinitializes operational data while preserving tenant identity access and audit', function () {
    Storage::fake('local');
    Http::fake([
        'https://opsifin.test/opsifin_api_print/resetPrinterConfiguration' => Http::response([
            'status' => true,
            'deleted' => ['customer_printers' => 1],
        ], 200),
    ]);

    $tenant = Tenant::query()->create(['code' => 'reset-me', 'name' => 'Reset Me']);
    $integrationId = $tenant->integration_id;
    $actor = User::factory()->create(['is_platform_admin' => true]);
    app(TenantContext::class)->set($tenant->getKey());

    $membership = Membership::query()->create([
        'user_id' => $actor->getKey(),
        'role' => 'tenant_admin',
        'site_ids' => ['temporary-site'],
    ]);
    $site = Site::query()->create(['name' => 'Jakarta']);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PRINT-PC',
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'reset-machine',
        'secret_hash' => hash('sha256', 'secret'),
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'EPSON',
        'is_active' => true,
    ]);
    $connection = SourceConnection::query()->create([
        'name' => 'Opsifin',
        'base_url' => 'https://opsifin.test/opsifin_api_print',
        'username' => 'hub',
        'password_enc' => 'secret',
    ]);
    $connection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $integrationId,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->save();
    $binding = PrinterBinding::query()->create([
        'source_connection_id' => $connection->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'Cashier',
        'source_identifier' => 'JKT-01',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    Storage::disk('local')->put("tenants/{$tenant->getKey()}/documents/test.pdf", '%PDF-test');
    $document = Document::query()->create([
        'disk' => 'local',
        'path' => "tenants/{$tenant->getKey()}/documents/test.pdf",
        'sha256' => hash('sha256', '%PDF-test'),
        'pages' => 1,
        'size_bytes' => 9,
    ]);
    $job = PrintJob::query()->create([
        'binding_id' => $binding->getKey(),
        'printer_id' => $printer->getKey(),
        'document_id' => $document->getKey(),
        'inv_no' => 'INV-RESET-001',
        'item_type' => 'INV',
        'version_inv' => 1,
        'state' => PrintJobState::Printed,
        'available_at' => now(),
        'received_at' => now(),
        'printed_at' => now(),
    ]);
    CallbackOutbox::query()->create([
        'job_id' => $job->getKey(),
        'source_connection_id' => $connection->getKey(),
        'endpoint' => 'https://opsifin.test/callback',
        'payload' => ['InvNo' => 'INV-RESET-001'],
        'status' => 'sent',
        'attempts' => 1,
        'next_attempt_at' => now(),
        'expires_at' => now()->addHour(),
        'sent_at' => now(),
    ]);

    // Livewire actions do not guarantee that the request-scoped context survives until
    // the service call, so the service must establish and restore its own tenant context.
    app(TenantContext::class)->clear();
    $counts = app(TenantReinitializer::class)->run($tenant, $actor, 'Reconfigure client workstation fleet');

    expect(app(TenantContext::class)->id())->toBeNull();
    app(TenantContext::class)->set($tenant->getKey());

    expect($counts['print_jobs'])->toBe(1)
        ->and($tenant->fresh()->integration_id)->toBe($integrationId)
        ->and($tenant->fresh()->status)->toBe('suspended')
        ->and(Membership::query()->findOrFail($membership->getKey())->site_ids)->toBeNull()
        ->and($tenant->sites()->count())->toBe(0)
        ->and($tenant->sourceConnections()->count())->toBe(0)
        ->and($tenant->printJobs()->count())->toBe(0)
        ->and($tenant->auditLogs()->where('action', 'tenant.reinitialized')->count())->toBe(1);

    Storage::disk('local')->assertMissing("tenants/{$tenant->getKey()}/documents/test.pdf");
    Http::assertSent(fn ($request): bool => $request['IntegrationId'] === $integrationId
        && $request['Reason'] === 'Reconfigure client workstation fleet');

    app(TenantContext::class)->clear();
});

it('blocks tenant reinitialization while a print job is active', function () {
    Http::fake();
    $tenant = Tenant::query()->create(['code' => 'busy-reset', 'name' => 'Busy Reset']);
    $actor = User::factory()->create(['is_platform_admin' => true]);
    app(TenantContext::class)->set($tenant->getKey());
    $site = Site::query()->create(['name' => 'Jakarta']);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'BUSY-PC',
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'busy-reset-machine',
        'secret_hash' => hash('sha256', 'secret'),
    ]);
    $printer = Printer::query()->create(['agent_id' => $agent->getKey(), 'windows_name' => 'EPSON']);
    PrintJob::query()->create([
        'printer_id' => $printer->getKey(),
        'inv_no' => 'INV-BUSY',
        'item_type' => 'INV',
        'version_inv' => 1,
        'state' => PrintJobState::Printing,
        'available_at' => now(),
        'received_at' => now(),
    ]);

    app(TenantContext::class)->clear();
    expect(fn () => app(TenantReinitializer::class)->run($tenant, $actor, 'Reset after incident review'))
        ->toThrow(RuntimeException::class, 'Active Print Jobs: 1');

    expect(app(TenantContext::class)->id())->toBeNull();
    app(TenantContext::class)->set($tenant->getKey());

    expect($tenant->fresh()->status)->toBe('active')
        ->and($tenant->printJobs()->count())->toBe(1);
    Http::assertNothingSent();

    app(TenantContext::class)->clear();
});

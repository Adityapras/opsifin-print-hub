<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Http;

function printerStatusFixture(): array
{
    $tenant = Tenant::query()->create(['code' => 'status-sync', 'name' => 'Status Sync']);
    app(TenantContext::class)->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Jakarta']);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PRINT-PC',
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'status-sync-machine',
        'secret_hash' => hash('sha256', 'secret'),
        'last_seen_at' => now(),
        'status' => 'online',
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'EPSON-L3110',
        'status' => 'ONLINE',
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
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->save();
    $binding = PrinterBinding::query()->create([
        'source_connection_id' => $connection->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'Cashier Printer',
        'source_identifier' => 'JKT-01',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    app(TenantContext::class)->clear();

    return compact('tenant', 'agent', 'printer', 'connection', 'binding');
}

it('sends effective printer status asynchronously and marks an offline Agent as offline', function () {
    $fixture = printerStatusFixture();
    Http::fake([
        'https://opsifin.test/opsifin_api_print/PrinterStatus' => Http::response([], 200),
    ]);

    $this->artisan('hub:sync-printer-statuses')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['PrinterName'] === 'Cashier Printer'
        && $request['Identifier'] === 'JKT-01'
        && $request['PrinterStatus'] === 'ONLINE');

    $binding = PrinterBinding::withoutGlobalScopes()->findOrFail($fixture['binding']->getKey());
    expect($binding->last_synced_printer_status)->toBe('ONLINE')
        ->and($binding->last_status_synced_at)->not->toBeNull()
        ->and($binding->last_status_sync_error)->toBeNull();

    Agent::withoutGlobalScopes()->whereKey($fixture['agent']->getKey())->update([
        'last_seen_at' => now()->subMinutes(4),
    ]);
    PrinterBinding::withoutGlobalScopes()->whereKey($fixture['binding']->getKey())->update([
        'next_status_sync_at' => now()->subSecond(),
    ]);

    $this->artisan('hub:sync-printer-statuses')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request['PrinterStatus'] === 'OFFLINE');
    expect(PrinterBinding::withoutGlobalScopes()->findOrFail($fixture['binding']->getKey())->last_synced_printer_status)
        ->toBe('OFFLINE');
});

it('reschedules a failed printer status delivery without blocking heartbeat', function () {
    $fixture = printerStatusFixture();
    Http::fake([
        'https://opsifin.test/opsifin_api_print/PrinterStatus' => Http::response(['message' => 'down'], 503),
    ]);

    $this->artisan('hub:sync-printer-statuses')->assertFailed();

    $binding = PrinterBinding::withoutGlobalScopes()->findOrFail($fixture['binding']->getKey());
    expect($binding->status_sync_attempts)->toBe(1)
        ->and($binding->last_status_sync_error)->toContain('HTTP 503')
        ->and($binding->next_status_sync_at)->not->toBeNull();
});

<?php

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function scheduledPullFixture(string $tenantCode): array
{
    $tenant = Tenant::query()->create([
        'code' => $tenantCode,
        'name' => strtoupper($tenantCode),
    ]);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Site '.$tenantCode]);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PC-'.$tenantCode,
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'machine-'.$tenantCode,
        'secret_hash' => hash('sha256', 'secret-'.$tenantCode),
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'PRINTER-'.$tenantCode,
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connection = SourceConnection::query()->create([
        'name' => 'Opsifin '.$tenantCode,
        'base_url' => 'https://'.$tenantCode.'.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
        'pull_interval_s' => 120,
        'active_from' => '00:00:00',
        'active_until' => '23:59:59',
    ]);
    $connection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
    $binding = PrinterBinding::query()->create([
        'source_connection_id' => $connection->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'Queue '.$tenantCode,
        'source_identifier' => strtoupper($tenantCode),
        'printer_version' => 1,
        'is_active' => true,
    ]);

    $context->clear();

    return compact('tenant', 'connection', 'binding', 'printer');
}

it('menarik binding lintas tenant ketika scheduler tidak memiliki tenant context', function () {
    $fixtureA = scheduledPullFixture('scheduled-a');
    $fixtureB = scheduledPullFixture('scheduled-b');
    Http::fake([
        'https://scheduled-a.test/rest/autoprint/getData' => Http::response([], 200),
        'https://scheduled-b.test/rest/autoprint/getData' => Http::response([], 200),
    ]);

    $this->artisan('hub:pull-sources')
        ->expectsOutputToContain('Pull completed: 0 received, 0 queued, 0 skipped, 0 failed items, 0 duplicates, 0 source failures.')
        ->assertSuccessful();

    foreach ([$fixtureA, $fixtureB] as $fixture) {
        $binding = PrinterBinding::withoutGlobalScopes()->findOrFail($fixture['binding']->getKey());

        expect($binding->last_pulled_at)->not->toBeNull()
            ->and($binding->next_pull_at)->not->toBeNull()
            ->and($binding->last_pulled_at->diffInSeconds($binding->next_pull_at))->toBe(120.0);
    }

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://scheduled-a.test/rest/autoprint/getData'
        && $request['PrinterName'] === 'Queue scheduled-a'
        && $request['Identifier'] === 'SCHEDULED-A');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://scheduled-b.test/rest/autoprint/getData'
        && $request['PrinterName'] === 'Queue scheduled-b'
        && $request['Identifier'] === 'SCHEDULED-B');
});

it('menunda pull hanya untuk binding yang masih memiliki batch aktif', function () {
    $busy = scheduledPullFixture('scheduled-busy');
    $ready = scheduledPullFixture('scheduled-ready');
    $context = app(TenantContext::class);
    $context->set($busy['tenant']->getKey());

    PrintJob::query()->create([
        'binding_id' => $busy['binding']->getKey(),
        'printer_id' => $busy['printer']->getKey(),
        'inv_no' => 'TK-BUSY-001',
        'item_type' => 'TK',
        'version_inv' => 1,
        'is_hardcopy' => true,
        'source_payload' => [],
        'state' => PrintJobState::Queued,
        'available_at' => now(),
        'received_at' => now(),
    ]);
    $context->clear();

    Http::fake([
        'https://scheduled-busy.test/rest/autoprint/getData' => Http::response([], 200),
        'https://scheduled-ready.test/rest/autoprint/getData' => Http::response([], 200),
    ]);

    $this->artisan('hub:pull-sources')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://scheduled-ready.test/rest/autoprint/getData');

    expect(PrinterBinding::withoutGlobalScopes()->findOrFail($busy['binding']->getKey())->last_pulled_at)->toBeNull()
        ->and(PrinterBinding::withoutGlobalScopes()->findOrFail($ready['binding']->getKey())->last_pulled_at)->not->toBeNull();
});

it('menunda pull sampai callback batch sebelumnya selesai', function () {
    $busy = scheduledPullFixture('scheduled-callback');
    $ready = scheduledPullFixture('scheduled-after-callback');
    $context = app(TenantContext::class);
    $context->set($busy['tenant']->getKey());

    $job = PrintJob::query()->create([
        'binding_id' => $busy['binding']->getKey(),
        'printer_id' => $busy['printer']->getKey(),
        'inv_no' => 'TK-CALLBACK-001',
        'item_type' => 'TK',
        'version_inv' => 1,
        'is_hardcopy' => true,
        'source_payload' => [],
        'state' => PrintJobState::Printed,
        'available_at' => now(),
        'received_at' => now(),
        'printed_at' => now(),
    ]);
    $outbox = CallbackOutbox::query()->create([
        'job_id' => $job->getKey(),
        'source_connection_id' => $busy['connection']->getKey(),
        'endpoint' => 'https://scheduled-callback.test/rest/autoprint',
        'payload' => ['InvNo' => $job->inv_no],
        'status' => 'pending',
        'attempts' => 0,
        'next_attempt_at' => now(),
        'expires_at' => now()->addDay(),
    ]);
    $context->clear();

    Http::fake([
        'https://scheduled-callback.test/rest/autoprint/getData' => Http::response([], 200),
        'https://scheduled-after-callback.test/rest/autoprint/getData' => Http::response([], 200),
    ]);

    $this->artisan('hub:pull-sources')->assertSuccessful();
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://scheduled-after-callback.test/rest/autoprint/getData');

    $context->set($busy['tenant']->getKey());
    $outbox->update(['status' => 'sent', 'sent_at' => now()]);
    $context->clear();
    Http::fake([
        'https://scheduled-callback.test/rest/autoprint/getData' => Http::response([], 200),
        'https://scheduled-after-callback.test/rest/autoprint/getData' => Http::response([], 200),
    ]);

    $this->artisan('hub:pull-sources')->assertSuccessful();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://scheduled-callback.test/rest/autoprint/getData');
});

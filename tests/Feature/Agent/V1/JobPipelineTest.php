<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function createQueuedJobFixture(string $tenantCode, string $secret): array
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
        'secret_hash' => hash('sha256', $secret),
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
        'source_printer_name' => 'PRINTER-'.$tenantCode,
        'source_identifier' => strtoupper($tenantCode),
        'printer_version' => 1,
        'is_active' => true,
    ]);

    $pdf = "%PDF-1.4\n%%EOF\n";
    $path = "tenants/{$tenant->getKey()}/documents/test.pdf";
    Storage::disk('local')->put($path, $pdf);

    $document = Document::query()->create([
        'disk' => 'local',
        'path' => $path,
        'sha256' => hash('sha256', $pdf),
        'pages' => 1,
        'size_bytes' => strlen($pdf),
    ]);
    $job = PrintJob::query()->create([
        'binding_id' => $binding->getKey(),
        'printer_id' => $printer->getKey(),
        'document_id' => $document->getKey(),
        'inv_no' => 'INV-'.$tenantCode,
        'item_type' => 'INV',
        'version_inv' => 1,
        'customer_name' => 'Sensitive Customer '.$tenantCode,
        'source_payload' => ['Amount' => 1250000],
        'state' => PrintJobState::Queued,
        'available_at' => now(),
        'received_at' => now(),
    ]);

    $context->clear();

    return compact('tenant', 'agent', 'printer', 'connection', 'binding', 'document', 'job', 'secret', 'pdf');
}

function jobApiHeaders(Agent $agent, string $secret, string $version = '1.0.0'): array
{
    return [
        'Authorization' => "Bearer {$agent->getKey()}.{$secret}",
        'X-Agent-Version' => $version,
    ];
}

it('mengklaim satu job per printer dan menyediakan signed URL dokumen', function () {
    Storage::fake('local');
    $fixture = createQueuedJobFixture('tenant-a', 'secret-a');

    $response = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], jobApiHeaders($fixture['agent'], $fixture['secret']));

    $response
        ->assertOk()
        ->assertJsonCount(1, 'jobs')
        ->assertJsonPath('jobs.0.job_id', $fixture['job']->getKey())
        ->assertJsonPath('jobs.0.printer_id', $fixture['printer']->getKey())
        ->assertJsonPath('jobs.0.display.invoice_no', $fixture['job']->inv_no)
        ->assertJsonPath('jobs.0.display.item_type', $fixture['job']->item_type)
        ->assertJsonPath('jobs.0.display.received_at', $fixture['job']->received_at->utc()->toIso8601String())
        ->assertJsonMissingPath('jobs.0.display.customer_name')
        ->assertJsonMissingPath('jobs.0.display.amount')
        ->assertJsonMissingPath('jobs.0.customer_name')
        ->assertJsonMissingPath('jobs.0.source_payload')
        ->assertJsonPath('jobs.0.document.sha256', $fixture['document']->sha256);

    $freshJob = PrintJob::withoutGlobalScopes()->findOrFail($fixture['job']->getKey());

    expect($freshJob->state)->toBe(PrintJobState::Claimed)
        ->and($freshJob->attempt_count)->toBe(1)
        ->and($freshJob->lease_agent_id)->toBe($fixture['agent']->getKey())
        ->and($freshJob->attempts()->withoutGlobalScopes()->count())->toBe(1)
        ->and($freshJob->events()->withoutGlobalScopes()->where('type', 'claimed')->count())->toBe(1);

    $this->get($response->json('jobs.0.document.url'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], jobApiHeaders($fixture['agent'], $fixture['secret']))->assertNoContent();
});

it('tetap melayani agent lama yang mengabaikan metadata display tambahan', function () {
    Storage::fake('local');
    $fixture = createQueuedJobFixture('tenant-legacy-agent', 'secret-legacy-agent');

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], jobApiHeaders($fixture['agent'], $fixture['secret'], '0.1.0'))
        ->assertOk()
        ->assertJsonPath('jobs.0.job_id', $fixture['job']->getKey())
        ->assertJsonPath('jobs.0.display.invoice_no', $fixture['job']->inv_no);
});

it('tidak mengizinkan agent mengklaim printer tenant lain', function () {
    Storage::fake('local');
    $fixtureA = createQueuedJobFixture('tenant-a', 'secret-a');
    $fixtureB = createQueuedJobFixture('tenant-b', 'secret-b');

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixtureB['printer']->getKey()],
    ], jobApiHeaders($fixtureA['agent'], $fixtureA['secret']))->assertNoContent();

    expect(PrintJob::withoutGlobalScopes()->findOrFail($fixtureB['job']->getKey())->state)
        ->toBe(PrintJobState::Queued);
});

it('mengembalikan job dengan lease kedaluwarsa ke antrean agar printer tidak terkunci', function () {
    Storage::fake('local');
    $fixture = createQueuedJobFixture('tenant-expired-lease', 'secret-expired-lease');
    $headers = jobApiHeaders($fixture['agent'], $fixture['secret']);

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)->assertOk();

    $this->travel((int) config('print_hub.lease_seconds') + 1)->seconds();

    $this->artisan('hub:release-expired-job-leases')
        ->expectsOutputToContain('Released 1 expired print job lease(s).')
        ->assertSuccessful();

    $released = PrintJob::withoutGlobalScopes()->findOrFail($fixture['job']->getKey());
    $attempt = $released->attempts()->withoutGlobalScopes()->firstOrFail();
    $event = $released->events()->withoutGlobalScopes()->where('type', 'lease_expired')->firstOrFail();

    expect($released->state)->toBe(PrintJobState::Queued)
        ->and($released->lease_agent_id)->toBeNull()
        ->and($released->lease_until)->toBeNull()
        ->and($attempt->outcome)->toBe('lease_expired')
        ->and($attempt->finished_at)->not->toBeNull()
        ->and($event->from_state)->toBe(PrintJobState::Claimed->value)
        ->and($event->to_state)->toBe(PrintJobState::Queued->value);

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('jobs.0.job_id', $fixture['job']->getKey());

    expect($released->fresh()->attempt_count)->toBe(2);
});

it('memproses event secara idempoten sampai job tercetak', function () {
    Storage::fake('local');
    $fixture = createQueuedJobFixture('tenant-a', 'secret-a');
    $headers = jobApiHeaders($fixture['agent'], $fixture['secret']);

    $claim = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)->assertOk();

    $attemptId = $claim->json('jobs.0.attempt_id');
    $spooledId = (string) Str::uuid7();
    $completedId = (string) Str::uuid7();
    $events = [
        [
            'event_id' => $spooledId,
            'attempt_id' => $attemptId,
            'type' => 'spooled',
            'occurred_at' => '2026-10-29T08:00:00Z',
            'data' => ['spooler_job_id' => 42],
        ],
        [
            'event_id' => $completedId,
            'attempt_id' => $attemptId,
            'type' => 'completed',
            'occurred_at' => '2026-10-29T08:00:05Z',
            'data' => ['pages_printed' => 1],
        ],
    ];

    $this->postJson("/agent/v1/jobs/{$fixture['job']->getKey()}/events", compact('events'), $headers)
        ->assertOk()
        ->assertJsonPath('accepted', [$spooledId, $completedId])
        ->assertJsonPath('duplicates', [])
        ->assertJsonPath('job_state', 'printed')
        ->assertJsonPath('lease_until', null)
        ->assertJsonPath('abort', false);

    $this->postJson("/agent/v1/jobs/{$fixture['job']->getKey()}/events", compact('events'), $headers)
        ->assertOk()
        ->assertJsonPath('accepted', [])
        ->assertJsonPath('duplicates', [$spooledId, $completedId])
        ->assertJsonPath('job_state', 'printed');

    $job = PrintJob::withoutGlobalScopes()->findOrFail($fixture['job']->getKey());

    expect($job->state)->toBe(PrintJobState::Printed)
        ->and($job->printed_at)->not->toBeNull()
        ->and(PrintJobEvent::withoutGlobalScopes()->where('job_id', $job->getKey())->count())->toBe(3);
});

it('tidak retry otomatis ketika error terjadi setelah job masuk spooler', function () {
    Storage::fake('local');
    $fixture = createQueuedJobFixture('tenant-spooled-error', 'secret-spooled-error');
    $headers = jobApiHeaders($fixture['agent'], $fixture['secret']);

    $claim = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)->assertOk();

    $events = [
        [
            'event_id' => (string) Str::uuid7(),
            'attempt_id' => $claim->json('jobs.0.attempt_id'),
            'type' => 'spooled',
            'occurred_at' => '2026-11-09T09:37:00Z',
            'data' => ['spooler_job_id' => 3],
        ],
        [
            'event_id' => (string) Str::uuid7(),
            'attempt_id' => $claim->json('jobs.0.attempt_id'),
            'type' => 'print_error',
            'occurred_at' => '2026-11-09T09:37:01Z',
            'data' => [
                'error_code' => 'PRINT_FAILED',
                'message' => 'GetJob probe failed for JobId 3',
            ],
        ],
    ];

    $this->postJson(
        "/agent/v1/jobs/{$fixture['job']->getKey()}/events",
        compact('events'),
        $headers,
    )
        ->assertOk()
        ->assertJsonPath('job_state', 'failed');

    $job = PrintJob::withoutGlobalScopes()->findOrFail($fixture['job']->getKey());
    $attempt = $job->attempts()->withoutGlobalScopes()->firstOrFail();

    expect($job->state)->toBe(PrintJobState::Failed)
        ->and($job->failure_code)->toBe('PRINT_FAILED')
        ->and($job->attempt_count)->toBe(1)
        ->and($attempt->outcome)->toBe('failed');

    $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)->assertNoContent();
});

it('menolak event ketika lease dimiliki agent lain', function () {
    Storage::fake('local');
    $fixtureA = createQueuedJobFixture('tenant-a', 'secret-a');
    $fixtureB = createQueuedJobFixture('tenant-b', 'secret-b');
    $claim = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixtureA['printer']->getKey()],
    ], jobApiHeaders($fixtureA['agent'], $fixtureA['secret']))->assertOk();

    $events = [[
        'event_id' => (string) Str::uuid7(),
        'attempt_id' => $claim->json('jobs.0.attempt_id'),
        'type' => 'download_started',
        'occurred_at' => '2026-10-29T08:00:00Z',
    ]];

    $this->postJson(
        "/agent/v1/jobs/{$fixtureA['job']->getKey()}/events",
        compact('events'),
        jobApiHeaders($fixtureB['agent'], $fixtureB['secret']),
    )->assertConflict()->assertJsonPath('error.code', 'lease_lost');
});

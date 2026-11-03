<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobEvent;
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
        'printer_id' => $printer->getKey(),
        'document_id' => $document->getKey(),
        'inv_no' => 'INV-'.$tenantCode,
        'item_type' => 'INV',
        'version_inv' => 1,
        'state' => PrintJobState::Queued,
        'available_at' => now(),
        'received_at' => now(),
    ]);

    $context->clear();

    return compact('tenant', 'agent', 'printer', 'document', 'job', 'secret', 'pdf');
}

function jobApiHeaders(Agent $agent, string $secret): array
{
    return [
        'Authorization' => "Bearer {$agent->getKey()}.{$secret}",
        'X-Agent-Version' => '1.0.0',
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

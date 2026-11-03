<?php

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Delivery\Services\CallbackProcessor;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourcePullService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function sourceFixture(string $code = 'source-a', int $printerVersion = 1): array
{
    $tenant = Tenant::query()->create([
        'code' => $code,
        'name' => strtoupper($code),
        'timezone' => 'Asia/Jakarta',
    ]);
    app(TenantContext::class)->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Site '.$code]);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PC-'.$code,
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'machine-'.$code,
        'secret_hash' => hash('sha256', 'secret'),
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'PRINTER-'.$code,
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connection = SourceConnection::query()->create([
        'name' => 'Legacy '.$code,
        'base_url' => 'https://legacy-'.$code.'.test/api/print',
        'username' => 'api-user',
        'password_enc' => 'super-secret',
        'active_from' => '00:00:00',
        'active_until' => '23:59:59',
    ]);
    $binding = PrinterBinding::query()->create([
        'source_connection_id' => $connection->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'Kasir 01',
        'source_identifier' => 'CAB-01',
        'printer_version' => $printerVersion,
        'is_active' => true,
    ]);

    return compact('tenant', 'site', 'agent', 'printer', 'connection', 'binding');
}

function validPdf(): string
{
    $pdf = new FPDF;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(40, 10, 'Opsifin Print Hub');

    return $pdf->Output('S');
}

function sourcePayload(array $overrides = []): array
{
    return $overrides + [
        'InvNo' => 'INV-001',
        'ItemType' => 'INV',
        'IsHardCopyInv' => 'Y',
        'VersionInv' => 2,
        'Link' => 'https://documents.test/invoice.pdf',
        'CustomerID' => 'C-100',
        'CustomerName' => 'Toko Uji',
        'BranchID' => 'JKT',
        'ImplantCode' => 'IMP-1',
    ];
}

it('menarik job, memvalidasi PDF, menyimpan snapshot, dan mengantrekannya', function () {
    Storage::fake('local');
    $fixture = sourceFixture();
    $pdf = validPdf();

    Http::fake([
        'https://legacy-source-a.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response($pdf, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $result = app(SourcePullService::class)->pull($fixture['binding']);

    expect($result)->toBe([
        'received' => 1,
        'queued' => 1,
        'skipped' => 0,
        'failed' => 0,
        'duplicates' => 0,
    ]);

    $job = PrintJob::query()->sole();
    $document = Document::query()->sole();

    expect($job->state)->toBe(PrintJobState::Queued)
        ->and($job->binding_id)->toBe($fixture['binding']->getKey())
        ->and($job->source_payload['CustomerName'])->toBe('Toko Uji')
        ->and($document->sha256)->toBe(hash('sha256', $pdf))
        ->and($document->pages)->toBe(1)
        ->and(Storage::disk('local')->get($document->path))->toBe($pdf)
        ->and($job->events()->pluck('type')->all())->toBe(['received', 'queued']);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://legacy-source-a.test/api/print/getData'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('api-user:super-secret'))
            && $request['VersionAPI'] === 2
            && $request['PrinterName'] === 'Kasir 01'
            && $request['PrinterVersion'] === 1
            && $request['Identifier'] === 'CAB-01';
    });

    expect(DB::table('source_connections')->value('password_enc'))->not->toBe('super-secret');
});

it('mengabaikan payload duplikat tanpa mengunduh ulang PDF', function () {
    Storage::fake('local');
    $fixture = sourceFixture('duplicate');
    $pdf = validPdf();

    Http::fake([
        'https://legacy-duplicate.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response($pdf),
    ]);

    $service = app(SourcePullService::class);
    $service->pull($fixture['binding']);
    $result = $service->pull($fixture['binding']->fresh());

    expect($result['duplicates'])->toBe(1)
        ->and(PrintJob::query()->count())->toBe(1)
        ->and(Document::query()->count())->toBe(1)
        ->and(PrintJobEvent::query()->where('type', 'duplicate_ignored')->count())->toBe(1);

    Http::assertSentCount(3);
});

it('menandai non-hardcopy sebagai skipped dan menyiapkan callback kompatibel v2', function () {
    Storage::fake('local');
    $fixture = sourceFixture('skipped');

    Http::fake([
        'https://legacy-skipped.test/api/print/getData' => Http::response([
            sourcePayload(['IsHardCopyInv' => 'N']),
        ]),
    ]);

    $result = app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $outbox = CallbackOutbox::query()->sole();

    expect($result['skipped'])->toBe(1)
        ->and($job->state)->toBe(PrintJobState::Skipped)
        ->and($job->document_id)->toBeNull()
        ->and($outbox->payload)->toBe([
            'InvNo' => 'INV-001',
            'ItemType' => 'INV',
            'PrintProcess' => '',
            'PrintProcessV2' => 'Success',
        ]);
});

it('menjadikan PDF rusak sebagai job failed dan callback gagal cetak', function () {
    Storage::fake('local');
    $fixture = sourceFixture('invalid-pdf');

    Http::fake([
        'https://legacy-invalid-pdf.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response('bukan-pdf'),
    ]);

    $result = app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $outbox = CallbackOutbox::query()->sole();

    expect($result['failed'])->toBe(1)
        ->and($job->state)->toBe(PrintJobState::Failed)
        ->and($job->failure_code)->toBe('PDF_INVALID')
        ->and($outbox->payload['PrintProcessV2'])->toBe('Failed')
        ->and($outbox->payload['PrinterName'])->toBe('Kasir 01');
});

it('membuat callback sukses ketika agent menyelesaikan job cetak', function () {
    Storage::fake('local');
    $fixture = sourceFixture('printed');

    Http::fake([
        'https://legacy-printed.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response(validPdf()),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $headers = [
        'Authorization' => "Bearer {$fixture['agent']->getKey()}.secret",
        'X-Agent-Version' => '1.0.0',
    ];

    $claim = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$fixture['printer']->getKey()],
    ], $headers)->assertOk();

    $this->postJson("/agent/v1/jobs/{$job->getKey()}/events", [
        'events' => [[
            'event_id' => (string) Str::uuid7(),
            'attempt_id' => $claim->json('jobs.0.attempt_id'),
            'type' => 'completed',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['pages_printed' => 1],
        ]],
    ], $headers)->assertOk()->assertJsonPath('job_state', 'printed');

    app(TenantContext::class)->set($fixture['tenant']->getKey());
    $outbox = CallbackOutbox::query()->sole();

    expect($outbox->payload['PrintProcessV2'])->toBe('Success')
        ->and($outbox->payload['PrinterName'])->toBe('Kasir 01');
});

it('mengirim callback outbox lalu menandai job telah dilaporkan', function () {
    Storage::fake('local');
    $fixture = sourceFixture('callback-ok');

    Http::fake([
        'https://legacy-callback-ok.test/api/print/getData' => Http::response([
            sourcePayload(['IsHardCopyInv' => 'N']),
        ]),
        'https://legacy-callback-ok.test/api/print' => Http::response(['ok' => true]),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $result = app(CallbackProcessor::class)->process();

    expect($result)->toBe(['sent' => 1, 'failed' => 0])
        ->and(CallbackOutbox::query()->sole()->status)->toBe('sent')
        ->and(PrintJob::query()->sole()->reported_at)->not->toBeNull()
        ->and(PrintJobEvent::query()->where('type', 'callback_succeeded')->count())->toBe(1);
});

it('menjadwalkan ulang callback yang mendapat respons gagal', function () {
    Storage::fake('local');
    $fixture = sourceFixture('callback-retry');

    Http::fake([
        'https://legacy-callback-retry.test/api/print/getData' => Http::response([
            sourcePayload(['IsHardCopyInv' => 'N']),
        ]),
        'https://legacy-callback-retry.test/api/print' => Http::response('server error', 500),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $result = app(CallbackProcessor::class)->process();
    $outbox = CallbackOutbox::query()->sole();

    expect($result)->toBe(['sent' => 0, 'failed' => 1])
        ->and($outbox->status)->toBe('pending')
        ->and($outbox->attempts)->toBe(1)
        ->and($outbox->last_http_status)->toBe(500)
        ->and($outbox->next_attempt_at->isFuture())->toBeTrue()
        ->and(PrintJobEvent::query()->where('type', 'callback_failed')->count())->toBe(1);
});

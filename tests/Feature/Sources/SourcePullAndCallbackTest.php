<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Delivery\Services\CallbackOutboxService;
use App\Domain\Delivery\Services\CallbackProcessor;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Jobs\ProcessRefetchRetry;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Domain\Jobs\Services\ManualJobRetryService;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourcePullService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    $connection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
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

it('menormalkan whitespace dan BOM terbatas sebelum header PDF', function () {
    Storage::fake('local');
    $fixture = sourceFixture('pdf-prefix');
    $pdf = validPdf();
    $sourcePdf = "\xEF\xBB\xBF \r\n".$pdf;

    Http::fake([
        'https://legacy-pdf-prefix.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response($sourcePdf, 200, ['Content-Type' => 'application/pdf']),
    ]);

    $result = app(SourcePullService::class)->pull($fixture['binding']);
    $document = Document::query()->sole();

    expect($result['queued'])->toBe(1)
        ->and($document->sha256)->toBe(hash('sha256', $pdf))
        ->and($document->size_bytes)->toBe(strlen($pdf))
        ->and(Storage::disk('local')->get($document->path))->toBe($pdf);
});

it('menolak payload non-whitespace yang menyisipkan teks header PDF', function () {
    Storage::fake('local');
    $fixture = sourceFixture('pdf-prefix-invalid');

    Http::fake([
        'https://legacy-pdf-prefix-invalid.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response('<html>%PDF-1.4</html>'),
    ]);

    $result = app(SourcePullService::class)->pull($fixture['binding']);

    expect($result['failed'])->toBe(1)
        ->and(PrintJob::query()->sole()->failure_code)->toBe('PDF_INVALID')
        ->and(Document::query()->count())->toBe(0);
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

it('mengantrekan ulang job failed memakai PDF tersimpan tanpa menghapus histori', function () {
    Storage::fake('local');
    $fixture = sourceFixture('manual-retry-stored');
    $pdf = validPdf();

    Http::fake([
        'https://legacy-manual-retry-stored.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response($pdf),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $documentId = $job->document_id;
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PRINTER_NOT_FOUND',
        'reported_at' => now(),
    ])->save();
    app(CallbackOutboxService::class)->enqueueForTerminal($job->fresh(), $fixture['binding']);
    $replacementPrinter = Printer::query()->create([
        'agent_id' => $fixture['agent']->getKey(),
        'windows_name' => 'PHYSICAL-PRINTER',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $destinationBinding = PrinterBinding::query()->create([
        'source_connection_id' => $fixture['connection']->getKey(),
        'printer_id' => $replacementPrinter->getKey(),
        'source_printer_name' => 'PHYSICAL-PRINTER',
        'source_identifier' => 'physical-destination',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    $fixture['binding']->forceFill(['is_active' => false])->save();
    $fixture['printer']->forceFill(['is_active' => false])->save();
    $actor = User::factory()->create(['is_platform_admin' => true]);

    $retried = app(ManualJobRetryService::class)->retryPrinting(
        $job->fresh(),
        $actor,
        'Printer mapping has been corrected.',
        $destinationBinding->getKey(),
    );
    $retryEvent = $retried->events()->where('type', 'reprint_requested')->firstOrFail();

    expect($retried->getKey())->toBe($job->getKey())
        ->and($retried->state)->toBe(PrintJobState::Queued)
        ->and($retried->binding_id)->toBe($destinationBinding->getKey())
        ->and($retried->printer_id)->toBe($replacementPrinter->getKey())
        ->and($retried->document_id)->toBe($documentId)
        ->and($retried->failure_code)->toBeNull()
        ->and($retried->reported_at)->toBeNull()
        ->and($retried->run_number)->toBe(2)
        ->and(PrintJob::query()->count())->toBe(1)
        ->and(Document::query()->count())->toBe(1)
        ->and(CallbackOutbox::query()->sole()->status)->toBe('superseded')
        ->and($retryEvent->data['mode'])->toBe('stored_pdf')
        ->and($retryEvent->data['previous_binding_id'])->toBe($fixture['binding']->getKey())
        ->and($retryEvent->data['binding_id'])->toBe($destinationBinding->getKey())
        ->and(AuditLog::query()->where('action', 'print_job.retried')->count())->toBe(1);

    $headers = [
        'Authorization' => "Bearer {$fixture['agent']->getKey()}.secret",
        'X-Agent-Version' => '1.0.0',
    ];
    $claim = $this->postJson('/agent/v1/jobs/claim', [
        'printer_ids' => [$replacementPrinter->getKey()],
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

    expect(CallbackOutbox::query()->count())->toBe(2)
        ->and(CallbackOutbox::query()->where('job_run', 2)->sole()->payload['PrintProcessV2'])->toBe('Success');
});

it('mengunduh snapshot PDF baru saat refetch retry tanpa menghapus dokumen lama', function () {
    Storage::fake('local');
    $fixture = sourceFixture('manual-retry-refetch');
    $oldPdf = validPdf();
    $newPdf = validPdf().'\n% refreshed';
    $sourcePdf = ' '.$newPdf;

    Http::fake([
        'https://legacy-manual-retry-refetch.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::sequence()
            ->push($oldPdf)
            ->push($sourcePdf),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $oldDocument = $job->document;
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PDF_INVALID',
    ])->save();
    $replacementPrinter = Printer::query()->create([
        'agent_id' => $fixture['agent']->getKey(),
        'windows_name' => 'REPLACEMENT-PRINTER',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $fixture['binding']->forceFill(['printer_id' => $replacementPrinter->getKey()])->save();
    $fixture['printer']->forceFill(['is_active' => false])->save();
    $actor = User::factory()->create(['is_platform_admin' => true]);

    $retried = app(ManualJobRetryService::class)->refetchAndRetry(
        $job->fresh(),
        $actor,
        'The PDF source was regenerated in Opsifin.',
        $fixture['binding']->getKey(),
    );
    $retryEvent = $retried->events()->where('type', 'reprint_requested')->firstOrFail();

    expect($retried->state)->toBe(PrintJobState::Queued)
        ->and($retried->document_id)->not->toBe($oldDocument->getKey())
        ->and($retried->document->sha256)->toBe(hash('sha256', $newPdf))
        ->and($retried->printer_id)->toBe($replacementPrinter->getKey())
        ->and($retried->run_number)->toBe(2)
        ->and(Document::query()->count())->toBe(2)
        ->and(Storage::disk('local')->exists($oldDocument->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($retried->document->path))->toBeTrue()
        ->and(Storage::disk('local')->get($retried->document->path))->toBe($newPdf)
        ->and($retryEvent->data['mode'])->toBe('refetched_pdf')
        ->and($retryEvent->data['previous_document_id'])->toBe($oldDocument->getKey())
        ->and($retryEvent->data['previous_printer_id'])->toBe($fixture['printer']->getKey())
        ->and($retryEvent->data['printer_id'])->toBe($replacementPrinter->getKey());
});

it('mengantrekan refetch ke Horizon dan mencegah request aktif ganda', function () {
    Storage::fake('local');
    Queue::fake();
    $fixture = sourceFixture('async-refetch');
    $oldPdf = validPdf();
    $newPdf = validPdf().'\n% async refreshed';

    Http::fake([
        'https://legacy-async-refetch.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::sequence()
            ->push($oldPdf)
            ->push($newPdf),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PDF_INVALID',
    ])->save();
    $replacementPrinter = Printer::query()->create([
        'agent_id' => $fixture['agent']->getKey(),
        'windows_name' => 'ASYNC-PHYSICAL-PRINTER',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $destinationBinding = PrinterBinding::query()->create([
        'source_connection_id' => $fixture['connection']->getKey(),
        'printer_id' => $replacementPrinter->getKey(),
        'source_printer_name' => 'ASYNC-PHYSICAL-PRINTER',
        'source_identifier' => 'async-physical-destination',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    $fixture['binding']->forceFill(['is_active' => false])->save();
    $fixture['printer']->forceFill(['is_active' => false])->save();
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $service = app(ManualJobRetryService::class);

    $request = $service->requestRefetchAndRetry(
        $job->fresh(),
        $actor,
        'Fetch the corrected PDF in the background.',
        $destinationBinding->getKey(),
    );

    expect($request->status)->toBe('pending')
        ->and($request->destination_binding_id)->toBe($destinationBinding->getKey())
        ->and($job->fresh()->state)->toBe(PrintJobState::Failed)
        ->and($job->fresh()->document_id)->not->toBeNull()
        ->and(PrintJobEvent::query()->where('type', 'refetch_retry_requested')->count())->toBe(1);

    Queue::assertPushed(
        ProcessRefetchRetry::class,
        fn (ProcessRefetchRetry $queued): bool => $queued->requestId === $request->getKey(),
    );

    expect(fn () => $service->requestRefetchAndRetry(
        $job->fresh(),
        $actor,
        'This duplicate request must be rejected.',
        $destinationBinding->getKey(),
    ))->toThrow(DomainException::class, 'already being processed');

    Queue::fake();
    $this->artisan('hub:dispatch-refetch-retries')->assertSuccessful();
    Queue::assertPushed(
        ProcessRefetchRetry::class,
        fn (ProcessRefetchRetry $queued): bool => $queued->requestId === $request->getKey(),
    );

    (new ProcessRefetchRetry((string) $request->getKey()))->handle(
        app(TenantContext::class),
        $service,
    );
    app(TenantContext::class)->set($fixture['tenant']->getKey());
    $request->refresh();
    $retried = $job->fresh('document');

    expect($request->status)->toBe('succeeded')
        ->and($request->started_at)->not->toBeNull()
        ->and($request->completed_at)->not->toBeNull()
        ->and($retried->state)->toBe(PrintJobState::Queued)
        ->and($retried->binding_id)->toBe($destinationBinding->getKey())
        ->and($retried->printer_id)->toBe($replacementPrinter->getKey())
        ->and($retried->run_number)->toBe(2)
        ->and($retried->document->sha256)->toBe(hash('sha256', $newPdf))
        ->and(Document::query()->count())->toBe(2);
});

it('menyimpan kegagalan background refetch tanpa mengubah job failed', function () {
    Storage::fake('local');
    Queue::fake();
    $fixture = sourceFixture('async-refetch-failed');

    Http::fake([
        'https://legacy-async-refetch-failed.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::sequence()
            ->push(validPdf())
            ->push('<html>not a pdf</html>'),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PDF_INVALID',
    ])->save();
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $service = app(ManualJobRetryService::class);
    $request = $service->requestRefetchAndRetry(
        $job->fresh(),
        $actor,
        'Capture a failed background validation.',
        $fixture['binding']->getKey(),
    );

    expect(fn () => (new ProcessRefetchRetry((string) $request->getKey()))->handle(
        app(TenantContext::class),
        $service,
    ))->toThrow(DomainException::class, 'could not be downloaded or validated');

    $failedRequest = PrintJobRetryRequest::withoutGlobalScopes()->findOrFail($request->getKey());
    $failedJob = PrintJob::withoutGlobalScopes()->findOrFail($job->getKey());

    expect($failedRequest->status)->toBe('failed')
        ->and($failedRequest->error_message)->toContain('valid PDF header')
        ->and($failedRequest->completed_at)->not->toBeNull()
        ->and($failedJob->state)->toBe(PrintJobState::Failed)
        ->and($failedJob->run_number)->toBe(1)
        ->and(PrintJobEvent::withoutGlobalScopes()->where('type', 'refetch_retry_failed')->count())->toBe(1);
});

it('menolak manual retry untuk job yang belum failed', function () {
    Storage::fake('local');
    $fixture = sourceFixture('manual-retry-guard');

    Http::fake([
        'https://legacy-manual-retry-guard.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response(validPdf()),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $actor = User::factory()->create(['is_platform_admin' => true]);

    expect(fn () => app(ManualJobRetryService::class)->retryPrinting(
        $job,
        $actor,
        'Retry should not be allowed.',
        $fixture['binding']->getKey(),
    ))->toThrow(DomainException::class, 'Only failed print jobs can be retried.');
});

it('menolak manual retry dari konteks tenant lain', function () {
    Storage::fake('local');
    $fixture = sourceFixture('manual-retry-tenant-a');

    Http::fake([
        'https://legacy-manual-retry-tenant-a.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response(validPdf()),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PRINTER_NOT_FOUND',
    ])->save();
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $otherTenant = Tenant::query()->create([
        'code' => 'manual-retry-tenant-b',
        'name' => 'Manual Retry Tenant B',
    ]);
    app(TenantContext::class)->set($otherTenant->getKey());

    expect(fn () => app(ManualJobRetryService::class)->retryPrinting(
        $job,
        $actor,
        'This tenant must not access the job.',
        $fixture['binding']->getKey(),
    ))->toThrow(DomainException::class, 'You are not allowed to retry this print job.');
});

it('menolak destination binding dari Opsifin connection lain dalam tenant yang sama', function () {
    Storage::fake('local');
    $fixture = sourceFixture('manual-retry-other-source');

    Http::fake([
        'https://legacy-manual-retry-other-source.test/api/print/getData' => Http::response([sourcePayload()]),
        'https://documents.test/invoice.pdf' => Http::response(validPdf()),
    ]);

    app(SourcePullService::class)->pull($fixture['binding']);
    $job = PrintJob::query()->sole();
    $job->forceFill([
        'state' => PrintJobState::Failed,
        'failure_code' => 'PRINTER_NOT_FOUND',
    ])->save();

    $otherConnection = SourceConnection::query()->create([
        'name' => 'Another Opsifin instance',
        'base_url' => 'https://another-source.test/api/print',
        'username' => 'api-user',
        'password_enc' => 'super-secret',
    ]);
    $otherConnection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $fixture['tenant']->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
    $otherBinding = PrinterBinding::query()->create([
        'source_connection_id' => $otherConnection->getKey(),
        'printer_id' => $fixture['printer']->getKey(),
        'source_printer_name' => 'OTHER-SOURCE-PRINTER',
        'source_identifier' => 'other-source',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    $actor = User::factory()->create(['is_platform_admin' => true]);

    expect(fn () => app(ManualJobRetryService::class)->retryPrinting(
        $job->fresh(),
        $actor,
        'Cross-source routing must be rejected.',
        $otherBinding->getKey(),
    ))->toThrow(
        DomainException::class,
        'The destination binding must use the same Opsifin connection as the original job.',
    );
});

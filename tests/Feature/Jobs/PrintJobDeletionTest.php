<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Identity\Models\Membership;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobAttempt;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Domain\Jobs\Services\PrintJobDeletionService;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/** @return array<string, mixed> */
function printJobDeletionFixture(string $code, string $role = 'tenant_admin'): array
{
    Storage::fake('local');
    $tenant = Tenant::query()->create(['code' => $code, 'name' => strtoupper($code)]);
    app(TenantContext::class)->set($tenant->getKey());
    $user = User::factory()->create();
    Membership::query()->create(['user_id' => $user->getKey(), 'role' => $role]);
    $site = Site::query()->create(['name' => 'Deletion Site']);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'DELETE-PC',
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => "machine-{$code}",
        'secret_hash' => hash('sha256', 'secret'),
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'DELETE-PRINTER',
        'status' => 'READY',
        'is_active' => true,
    ]);
    $source = SourceConnection::query()->create([
        'name' => 'Deletion Source',
        'base_url' => 'https://deletion.test/opsifin_api_print',
        'username' => 'api-user',
        'password_enc' => 'secret',
    ]);
    $binding = PrinterBinding::query()->create([
        'source_connection_id' => $source->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'DELETE-PRINTER',
        'source_identifier' => 'DELETE-01',
        'printer_version' => 1,
        'is_active' => true,
    ]);
    $oldPath = "tenants/{$tenant->getKey()}/documents/old.pdf";
    $currentPath = "tenants/{$tenant->getKey()}/documents/current.pdf";
    Storage::disk('local')->put($oldPath, '%PDF-old');
    Storage::disk('local')->put($currentPath, '%PDF-current');
    $oldDocument = Document::query()->create([
        'disk' => 'local',
        'path' => $oldPath,
        'sha256' => hash('sha256', '%PDF-old'),
        'pages' => 1,
        'size_bytes' => 8,
    ]);
    $currentDocument = Document::query()->create([
        'disk' => 'local',
        'path' => $currentPath,
        'sha256' => hash('sha256', '%PDF-current'),
        'pages' => 1,
        'size_bytes' => 12,
    ]);
    $job = PrintJob::query()->create([
        'binding_id' => $binding->getKey(),
        'source_connection_id' => $source->getKey(),
        'printer_id' => $printer->getKey(),
        'document_id' => $currentDocument->getKey(),
        'dedupe_key' => hash('sha256', "JOB-{$code}"),
        'inv_no' => "INV-{$code}",
        'item_type' => 'INV',
        'version_inv' => 1,
        'state' => PrintJobState::Printed,
        'attempt_count' => 1,
        'run_number' => 2,
        'available_at' => now(),
        'received_at' => now(),
        'printed_at' => now(),
        'reported_at' => now(),
    ]);
    $attempt = $job->attempts()->create([
        'agent_id' => $agent->getKey(),
        'attempt_no' => 1,
        'started_at' => now(),
        'finished_at' => now(),
        'outcome' => 'printed',
    ]);
    $job->events()->create([
        'attempt_id' => $attempt->getKey(),
        'actor_type' => 'user',
        'actor_id' => (string) $user->getKey(),
        'type' => 'reprint_requested',
        'from_state' => PrintJobState::Failed->value,
        'to_state' => PrintJobState::Queued->value,
        'occurred_at' => now(),
        'recorded_at' => now(),
        'data' => [
            'previous_document_id' => $oldDocument->getKey(),
            'document_id' => $currentDocument->getKey(),
        ],
    ]);
    $outbox = CallbackOutbox::query()->create([
        'job_id' => $job->getKey(),
        'job_run' => 2,
        'source_connection_id' => $source->getKey(),
        'endpoint' => $source->base_url,
        'payload' => ['InvNo' => $job->inv_no, 'PrintProcess' => 'Success'],
        'status' => 'sent',
        'attempts' => 1,
        'next_attempt_at' => now(),
        'expires_at' => now()->addDay(),
        'sent_at' => now(),
    ]);
    $retry = $job->retryRequests()->create([
        'destination_binding_id' => $binding->getKey(),
        'requested_by' => $user->getKey(),
        'mode' => 'refetched_pdf',
        'status' => 'succeeded',
        'reason' => 'PDF was corrected.',
        'requested_at' => now(),
        'completed_at' => now(),
    ]);

    return compact(
        'tenant',
        'user',
        'job',
        'attempt',
        'outbox',
        'retry',
        'oldDocument',
        'currentDocument',
        'oldPath',
        'currentPath',
    );
}

it('menghapus job terminal beserta seluruh history dan snapshot dokumennya', function () {
    $fixture = printJobDeletionFixture('delete-complete');
    $this->actingAs($fixture['user']);

    $result = app(PrintJobDeletionService::class)->delete(
        $fixture['job'],
        $fixture['user'],
        'Operational test data is no longer required.',
    );

    expect($result)->toBe([
        'deleted_counts' => [
            'print_jobs' => 1,
            'print_job_attempts' => 1,
            'print_job_events' => 1,
            'print_job_retry_requests' => 1,
            'callback_outbox' => 1,
            'documents' => 2,
        ],
        'file_cleanup_failures' => 0,
    ])->and(PrintJob::query()->count())->toBe(0)
        ->and(PrintJobAttempt::query()->count())->toBe(0)
        ->and(PrintJobEvent::query()->count())->toBe(0)
        ->and(PrintJobRetryRequest::query()->count())->toBe(0)
        ->and(CallbackOutbox::query()->count())->toBe(0)
        ->and(Document::query()->count())->toBe(0);

    Storage::disk('local')->assertMissing($fixture['oldPath']);
    Storage::disk('local')->assertMissing($fixture['currentPath']);

    $audit = AuditLog::query()->sole();
    expect($audit->action)->toBe('print_job.deleted')
        ->and($audit->subject_id)->toBe($fixture['job']->getKey())
        ->and($audit->before_data['document_number'])->toBe('INV-delete-complete')
        ->and($audit->after_data['reason'])->toBe('Operational test data is no longer required.')
        ->and($audit->after_data['deleted_counts']['documents'])->toBe(2);
});

it('menolak penghapusan saat job atau delivery masih aktif', function () {
    $fixture = printJobDeletionFixture('delete-blocked');
    $service = app(PrintJobDeletionService::class);
    $fixture['job']->forceFill(['state' => PrintJobState::Queued])->save();

    expect(fn () => $service->delete(
        $fixture['job']->fresh(),
        $fixture['user'],
        'Attempting an unsafe active job deletion.',
    ))->toThrow(DomainException::class, 'Only completed, skipped, failed, or cancelled jobs can be deleted.');

    $fixture['job']->forceFill(['state' => PrintJobState::Printed])->save();
    $fixture['outbox']->update(['status' => 'pending']);

    expect(fn () => $service->delete(
        $fixture['job']->fresh(),
        $fixture['user'],
        'Attempting deletion before callback completion.',
    ))->toThrow(DomainException::class, 'The job still has a pending Opsifin callback.');

    $fixture['outbox']->update(['status' => 'sent']);
    $fixture['retry']->update(['status' => 'processing']);

    expect(fn () => $service->delete(
        $fixture['job']->fresh(),
        $fixture['user'],
        'Attempting deletion during an active refetch.',
    ))->toThrow(DomainException::class, 'The job still has an active Refetch and retry request.');

    expect(PrintJob::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('membatasi hard delete ke tenant admin', function () {
    $fixture = printJobDeletionFixture('delete-operator', 'operator');

    expect(fn () => app(PrintJobDeletionService::class)->delete(
        $fixture['job'],
        $fixture['user'],
        'Operator must not delete permanent evidence.',
    ))->toThrow(DomainException::class, 'You are not allowed to delete this print job.');

    expect(PrintJob::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

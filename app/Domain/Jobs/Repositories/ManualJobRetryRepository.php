<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ManualJobRetryRepository
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function scheduleRefetch(
        PrintJob $job,
        User $actor,
        string $reason,
        string $destinationBindingId,
    ): PrintJobRetryRequest {
        return DB::transaction(function () use ($job, $actor, $reason, $destinationBindingId): PrintJobRetryRequest {
            $locked = PrintJob::query()
                ->with(['sourceConnection', 'binding.sourceConnection', 'binding.printer', 'printer'])
                ->whereKey($job->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $destination = $this->resolveDestinationBinding($locked, $destinationBindingId);

            $activeRequest = PrintJobRetryRequest::query()
                ->where('job_id', $locked->getKey())
                ->whereIn('status', PrintJobRetryRequest::ACTIVE_STATUSES)
                ->first();

            if ($activeRequest instanceof PrintJobRetryRequest) {
                throw new DomainException('A Refetch and retry request is already being processed.');
            }

            $request = PrintJobRetryRequest::query()->create([
                'job_id' => $locked->getKey(),
                'destination_binding_id' => $destination->getKey(),
                'requested_by' => $actor->getKey(),
                'mode' => 'refetched_pdf',
                'status' => 'pending',
                'reason' => $reason,
                'requested_at' => now(),
            ]);

            $locked->events()->create([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->getKey(),
                'type' => 'refetch_retry_requested',
                'from_state' => $locked->state->value,
                'to_state' => $locked->state->value,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'data' => [
                    'request_id' => $request->getKey(),
                    'reason' => $reason,
                    'mode' => $request->mode,
                    'destination_binding_id' => $destination->getKey(),
                    'destination_printer_id' => $destination->printer_id,
                ],
            ]);

            $this->audit->record($actor, 'print_job.refetch_scheduled', $locked, [
                'state' => $locked->state->value,
            ], [
                'state' => $locked->state->value,
                'request_id' => $request->getKey(),
                'mode' => $request->mode,
                'reason' => $reason,
                'destination_binding_id' => $destination->getKey(),
                'destination_printer_id' => $destination->printer_id,
            ]);

            return $request;
        }, attempts: 3);
    }

    public function markProcessing(PrintJobRetryRequest $request): PrintJobRetryRequest
    {
        return DB::transaction(function () use ($request): PrintJobRetryRequest {
            $locked = PrintJobRetryRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'pending') {
                $locked->forceFill([
                    'status' => 'processing',
                    'started_at' => now(),
                    'error_message' => null,
                ])->save();
            }

            return $locked;
        }, attempts: 3);
    }

    public function markFailed(PrintJobRetryRequest $request, string $message): void
    {
        DB::transaction(function () use ($request, $message): void {
            $locked = PrintJobRetryRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, ['succeeded', 'failed'], true)) {
                return;
            }

            $locked->forceFill([
                'status' => 'failed',
                'error_message' => mb_substr($message, 0, 2000),
                'completed_at' => now(),
            ])->save();

            $job = $locked->getRelationValue('job');

            if ($job instanceof PrintJob) {
                $job->events()->create([
                    'actor_type' => 'hub',
                    'type' => 'refetch_retry_failed',
                    'from_state' => $job->state->value,
                    'to_state' => $job->state->value,
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                    'data' => [
                        'request_id' => $locked->getKey(),
                        'message' => $locked->error_message,
                    ],
                ]);
            }
        }, attempts: 3);
    }

    /**
     * @param  array{disk: string, path: string, sha256: string, pages: int, size_bytes: int, source_url: string}|null  $refetchedDocument
     */
    public function requeue(
        PrintJob $job,
        User $actor,
        string $reason,
        string $mode,
        string $destinationBindingId,
        ?array $refetchedDocument = null,
        ?PrintJobRetryRequest $retryRequest = null,
    ): PrintJob {
        return DB::transaction(function () use ($job, $actor, $reason, $mode, $destinationBindingId, $refetchedDocument, $retryRequest): PrintJob {
            $locked = PrintJob::query()
                ->with(['document', 'sourceConnection', 'binding.sourceConnection', 'binding.printer', 'printer'])
                ->whereKey($job->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $destination = $this->resolveDestinationBinding($locked, $destinationBindingId);
            $destinationPrinter = $destination->printer;
            $lockedRetryRequest = null;

            if ($retryRequest instanceof PrintJobRetryRequest) {
                $lockedRetryRequest = PrintJobRetryRequest::query()
                    ->whereKey($retryRequest->getKey())
                    ->where('job_id', $locked->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedRetryRequest->isActive()) {
                    throw new DomainException('The Refetch and retry request is no longer active.');
                }
            }

            $previous = [
                'state' => $locked->state->value,
                'failure_code' => $locked->failure_code,
                'document_id' => $locked->document_id,
                'binding_id' => $locked->binding_id,
                'printer_id' => $locked->printer_id,
                'run_number' => $locked->run_number,
                'reported_at' => $locked->reported_at?->toIso8601String(),
            ];
            $previousDocument = $locked->document;
            $nextDocument = $previousDocument;

            if ($refetchedDocument !== null) {
                $nextDocument = Document::query()->create($refetchedDocument + [
                    'fetched_at' => now(),
                ]);
            }

            // Callback gagal yang belum terkirim tidak boleh berlomba dengan run baru.
            $locked->outboxes()
                ->where('job_run', $locked->run_number)
                ->where('status', 'pending')
                ->update(['status' => 'superseded']);

            $locked->forceFill([
                'document_id' => $nextDocument?->getKey(),
                // Binding dan printer dipindahkan bersama agar source route, callback, dan
                // destination printer tidak pernah menunjuk konfigurasi yang berbeda.
                'binding_id' => $destination->getKey(),
                'printer_id' => $destinationPrinter->getKey(),
                'state' => PrintJobState::Queued,
                'failure_code' => null,
                'lease_agent_id' => null,
                'lease_until' => null,
                'available_at' => now(),
                'printed_at' => null,
                'reported_at' => null,
                'run_number' => $locked->run_number + 1,
            ])->save();

            if ($lockedRetryRequest instanceof PrintJobRetryRequest) {
                // Status request dan requeue harus commit bersama agar worker retry tidak
                // mengulang cetak setelah job sebenarnya sudah berhasil masuk antrean.
                $lockedRetryRequest->forceFill([
                    'status' => 'succeeded',
                    'error_message' => null,
                    'completed_at' => now(),
                ])->save();
            }

            $locked->events()->create([
                'actor_type' => 'user',
                'actor_id' => (string) $actor->getKey(),
                'type' => 'reprint_requested',
                'from_state' => PrintJobState::Failed->value,
                'to_state' => PrintJobState::Queued->value,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'data' => [
                    'reason' => $reason,
                    'mode' => $mode,
                    'previous_failure_code' => $previous['failure_code'],
                    'previous_document_id' => $previousDocument?->getKey(),
                    'document_id' => $nextDocument?->getKey(),
                    'previous_binding_id' => $previous['binding_id'],
                    'binding_id' => $destination->getKey(),
                    'previous_printer_id' => $previous['printer_id'],
                    'printer_id' => $destinationPrinter->getKey(),
                    'previous_sha256' => $previousDocument?->sha256,
                    'sha256' => $nextDocument?->sha256,
                    'run_number' => $locked->run_number,
                ],
            ]);

            $this->audit->record($actor, 'print_job.retried', $locked, $previous, [
                'state' => $locked->state->value,
                'failure_code' => null,
                'document_id' => $locked->document_id,
                'binding_id' => $locked->binding_id,
                'printer_id' => $locked->printer_id,
                'run_number' => $locked->run_number,
                'mode' => $mode,
                'reason' => $reason,
            ]);

            return $locked->fresh(['document', 'events', 'outbox']);
        }, attempts: 3);
    }

    private function resolveDestinationBinding(PrintJob $job, string $destinationBindingId): PrinterBinding
    {
        if ($job->state !== PrintJobState::Failed) {
            throw new DomainException('Only failed print jobs can be retried.');
        }

        if ($job->lease_agent_id !== null || $job->lease_until !== null) {
            throw new DomainException('The print job still has an active Agent lease.');
        }

        $sourceConnectionId = $job->source_connection_id ?? $job->binding?->source_connection_id;

        if ($sourceConnectionId === null) {
            throw new DomainException('The original Opsifin connection is no longer available.');
        }

        $destination = PrinterBinding::query()
            ->with(['sourceConnection', 'printer'])
            ->whereKey($destinationBindingId)
            ->lockForUpdate()
            ->first();

        if (! $destination instanceof PrinterBinding || $destination->tenant_id !== $job->tenant_id) {
            throw new DomainException('The selected destination binding is not available for this tenant.');
        }

        if ($destination->source_connection_id !== $sourceConnectionId) {
            throw new DomainException('The destination binding must use the same Opsifin connection as the original job.');
        }

        if ($destination->is_active === false) {
            throw new DomainException('The selected destination binding must be active before retrying.');
        }

        if (
            $destination->sourceConnection->is_active === false
            || $destination->sourceConnection->identity_status !== SourceIdentityStatus::Verified
        ) {
            throw new DomainException('The destination Opsifin connection must be active and verified before retrying.');
        }

        $destinationPrinter = $destination->getRelationValue('printer');

        if (! $destinationPrinter instanceof Printer || $destinationPrinter->is_active === false) {
            throw new DomainException('The printer on the selected destination binding must be active before retrying.');
        }

        return $destination;
    }
}

<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PrintJobDeletionRepository
{
    /** @var array<int, PrintJobState> */
    private const TERMINAL_STATES = [
        PrintJobState::Printed,
        PrintJobState::Skipped,
        PrintJobState::Failed,
        PrintJobState::Cancelled,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function blocker(PrintJob $job): ?string
    {
        if (! in_array($job->state, self::TERMINAL_STATES, true)) {
            return 'Only completed, skipped, failed, or cancelled jobs can be deleted.';
        }

        if ($job->lease_agent_id !== null || $job->lease_until !== null) {
            return 'The job still has an Agent lease. Wait for the Agent operation to finish.';
        }

        if ($job->retryRequests()->whereIn('status', PrintJobRetryRequest::ACTIVE_STATUSES)->exists()) {
            return 'The job still has an active Refetch and retry request.';
        }

        if ($job->outboxes()->where('status', 'pending')->exists()) {
            return 'The job still has a pending Opsifin callback.';
        }

        return null;
    }

    /**
     * @return array{
     *     deleted_counts: array<string, int>,
     *     files: array<int, array{disk: string, path: string}>
     * }
     */
    public function delete(PrintJob $job, User $actor, string $reason): array
    {
        return DB::transaction(function () use ($job, $actor, $reason): array {
            $locked = PrintJob::query()
                ->whereKey($job->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $blocker = $this->blocker($locked);

            if ($blocker !== null) {
                throw new DomainException($blocker);
            }

            $events = $locked->events()->get();
            $documents = $this->documentsFor($locked, $events);
            $documentsToDelete = $documents->filter(
                fn (Document $document): bool => ! $document->jobs()
                    ->whereKeyNot($locked->getKey())
                    ->exists(),
            );
            $counts = [
                'print_jobs' => 1,
                'print_job_attempts' => $locked->attempts()->count(),
                'print_job_events' => $events->count(),
                'print_job_retry_requests' => $locked->retryRequests()->count(),
                'callback_outbox' => $locked->outboxes()->count(),
                'documents' => $documentsToDelete->count(),
            ];
            $before = [
                'document_number' => $locked->inv_no,
                'document_type' => $locked->item_type,
                'state' => $locked->state->value,
                'source_connection_id' => $locked->source_connection_id,
                'printer_id' => $locked->printer_id,
                'run_number' => $locked->run_number,
            ];

            // Audit disimpan di transaksi yang sama agar hard delete tanpa jejak tidak mungkin
            // terjadi walaupun database gagal di tengah proses.
            $this->audit->record($actor, 'print_job.deleted', $locked, $before, [
                'reason' => $reason,
                'deleted_counts' => $counts,
            ]);

            $locked->delete();
            $files = [];

            foreach ($documentsToDelete as $document) {
                $files[] = ['disk' => $document->disk, 'path' => $document->path];
                $document->delete();
            }

            return ['deleted_counts' => $counts, 'files' => $files];
        }, attempts: 3);
    }

    /**
     * @param  Collection<int, PrintJobEvent>  $events
     * @return Collection<int, Document>
     */
    private function documentsFor(PrintJob $job, Collection $events): Collection
    {
        $documentIds = collect([$job->document_id]);

        foreach ($events->where('type', 'reprint_requested') as $event) {
            $data = $event->getAttribute('data');

            if (! is_array($data)) {
                continue;
            }

            $documentIds->push($data['previous_document_id'] ?? null);
            $documentIds->push($data['document_id'] ?? null);
        }

        return Document::query()
            ->whereKey($documentIds->filter(fn (mixed $id): bool => is_string($id) && $id !== '')->unique()->all())
            ->get();
    }
}

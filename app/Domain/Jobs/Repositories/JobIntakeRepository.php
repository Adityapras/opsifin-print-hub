<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Delivery\Services\CallbackOutboxService;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\PrinterBinding;
use Illuminate\Support\Facades\DB;

final class JobIntakeRepository
{
    public function __construct(private readonly CallbackOutboxService $callbacks) {}

    /** @param array<string, mixed> $payload */
    public function findDuplicate(PrinterBinding $binding, array $payload): ?PrintJob
    {
        return PrintJob::query()
            ->where('inv_no', (string) $payload['InvNo'])
            ->where('item_type', (string) $payload['ItemType'])
            ->where('version_inv', (int) $payload['VersionInv'])
            ->first();
    }

    public function recordDuplicate(PrintJob $job, PrinterBinding $binding): void
    {
        $job->events()->create([
            'actor_type' => 'source',
            'actor_id' => $binding->source_connection_id,
            'type' => 'duplicate_ignored',
            'from_state' => $job->state->value,
            'to_state' => $job->state->value,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'data' => ['binding_id' => $binding->getKey()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{disk: string, path: string, sha256: string, pages: int, size_bytes: int, source_url: string}  $document
     */
    public function createQueued(PrinterBinding $binding, array $payload, array $document): PrintJob
    {
        return DB::transaction(function () use ($binding, $payload, $document): PrintJob {
            $storedDocument = Document::query()->create($document + [
                'fetched_at' => now(),
            ]);
            $job = $this->createJob($binding, $payload, PrintJobState::Queued, [
                'document_id' => $storedDocument->getKey(),
            ]);

            $this->recordEvent($job, $binding, 'received', null, PrintJobState::Received);
            $this->recordEvent($job, $binding, 'queued', PrintJobState::Received, PrintJobState::Queued);

            return $job;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    public function createSkipped(PrinterBinding $binding, array $payload): PrintJob
    {
        return DB::transaction(function () use ($binding, $payload): PrintJob {
            $job = $this->createJob($binding, $payload, PrintJobState::Skipped);

            $this->recordEvent($job, $binding, 'received', null, PrintJobState::Received);
            $this->recordEvent($job, $binding, 'skipped', PrintJobState::Received, PrintJobState::Skipped);
            $this->callbacks->enqueueForTerminal($job, $binding);

            return $job;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $payload */
    public function createFailed(PrinterBinding $binding, array $payload, string $failureCode): PrintJob
    {
        return DB::transaction(function () use ($binding, $payload, $failureCode): PrintJob {
            $job = $this->createJob($binding, $payload, PrintJobState::Failed, [
                'failure_code' => $failureCode,
            ]);

            $this->recordEvent($job, $binding, 'received', null, PrintJobState::Received);
            $this->recordEvent(
                $job,
                $binding,
                'failed',
                PrintJobState::Received,
                PrintJobState::Failed,
                ['failure_code' => $failureCode],
            );
            $this->callbacks->enqueueForTerminal($job, $binding);

            return $job;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra
     */
    private function createJob(
        PrinterBinding $binding,
        array $payload,
        PrintJobState $state,
        array $extra = [],
    ): PrintJob {
        return PrintJob::query()->create($extra + [
            'binding_id' => $binding->getKey(),
            'printer_id' => $binding->printer_id,
            'inv_no' => (string) $payload['InvNo'],
            'item_type' => (string) $payload['ItemType'],
            'version_inv' => (int) $payload['VersionInv'],
            'is_hardcopy' => strtoupper((string) ($payload['IsHardCopyInv'] ?? 'Y')) !== 'N',
            'customer_id' => $this->first($payload, ['CustomerID', 'CustomerId', 'CustID']),
            'customer_name' => $this->first($payload, ['CustomerName', 'CustName']),
            'branch_id' => $this->first($payload, ['BranchID', 'BranchId']),
            'implant_code' => $this->first($payload, ['ImplantCode', 'Implant']),
            'source_payload' => $payload,
            'state' => $state,
            'available_at' => now(),
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordEvent(
        PrintJob $job,
        PrinterBinding $binding,
        string $type,
        ?PrintJobState $from,
        PrintJobState $to,
        array $data = [],
    ): void {
        $job->events()->create([
            'actor_type' => 'source',
            'actor_id' => $binding->source_connection_id,
            'type' => $type,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'occurred_at' => now(),
            'recorded_at' => now(),
            'data' => $data + ['binding_id' => $binding->getKey()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function first(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        return null;
    }
}

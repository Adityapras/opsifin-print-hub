<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobAttempt;
use App\Domain\Jobs\Models\PrintJobEvent;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class JobClaimRepository
{
    /**
     * @param  array<int, string>  $printerIds
     * @return array<int, array{job: PrintJob, attempt: PrintJobAttempt}>
     */
    public function claim(Agent $agent, array $printerIds): array
    {
        return DB::transaction(function () use ($agent, $printerIds): array {
            $printers = Printer::query()
                ->where('agent_id', $agent->getKey())
                ->whereIn('id', $printerIds)
                ->where('is_active', true)
                ->where('is_paused', false)
                ->orderBy('id')
                ->get(['id']);

            $claimed = [];

            foreach ($printers as $printer) {
                // MySQL 5.7 belum mendukung SKIP LOCKED. Mengunci baris printer
                // membuat klaim tetap paralel antar-printer, tetapi serial untuk
                // printer yang sama agar dua request tidak mengambil dua job.
                $lockedPrinter = Printer::query()
                    ->whereKey($printer->getKey())
                    ->where('agent_id', $agent->getKey())
                    ->where('is_active', true)
                    ->where('is_paused', false)
                    ->lockForUpdate()
                    ->first();

                if ($lockedPrinter === null || $this->printerHasActiveJob($lockedPrinter)) {
                    continue;
                }

                $jobQuery = PrintJob::query()
                    ->where('printer_id', $lockedPrinter->getKey())
                    ->where('state', PrintJobState::Queued)
                    ->whereHas('binding', fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereHas('sourceConnection', fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->where('identity_status', SourceIdentityStatus::Verified->value)))
                    ->where('available_at', '<=', now())
                    ->orderBy('received_at');

                $job = $jobQuery->lockForUpdate()->first();

                if ($job === null || $job->document_id === null) {
                    continue;
                }

                $leaseUntil = now()->addSeconds(config('print_hub.lease_seconds'));
                $attemptNumber = $job->attempt_count + 1;

                $job->update([
                    'state' => PrintJobState::Claimed,
                    'attempt_count' => $attemptNumber,
                    'lease_agent_id' => $agent->getKey(),
                    'lease_until' => $leaseUntil,
                ]);

                $attempt = PrintJobAttempt::query()->create([
                    'job_id' => $job->getKey(),
                    'agent_id' => $agent->getKey(),
                    'attempt_no' => $attemptNumber,
                    'started_at' => now(),
                ]);

                PrintJobEvent::query()->create([
                    'job_id' => $job->getKey(),
                    'attempt_id' => $attempt->getKey(),
                    'actor_type' => 'agent',
                    'actor_id' => $agent->getKey(),
                    'type' => 'claimed',
                    'from_state' => PrintJobState::Queued->value,
                    'to_state' => PrintJobState::Claimed->value,
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                    'data' => ['lease_until' => $leaseUntil->toIso8601String()],
                ]);

                $claimed[] = [
                    'job' => $job->fresh(['document', 'printer']),
                    'attempt' => $attempt,
                ];
            }

            return $claimed;
        }, attempts: 3);
    }

    private function printerHasActiveJob(Printer $printer): bool
    {
        return PrintJob::query()
            ->where('printer_id', $printer->getKey())
            ->whereIn('state', [PrintJobState::Claimed, PrintJobState::Printing])
            ->exists();
    }
}

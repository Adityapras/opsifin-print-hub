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
                ->get();

            $claimed = [];

            foreach ($printers as $printer) {
                if ($this->printerHasActiveJob($printer)) {
                    continue;
                }

                $jobQuery = PrintJob::query()
                    ->where('printer_id', $printer->getKey())
                    ->where('state', PrintJobState::Queued)
                    ->whereHas('binding', fn (Builder $query): Builder => $query
                        ->where('is_active', true)
                        ->whereHas('sourceConnection', fn (Builder $query): Builder => $query
                            ->where('is_active', true)
                            ->where('identity_status', SourceIdentityStatus::Verified->value)))
                    ->where('available_at', '<=', now())
                    ->orderBy('received_at');

                $this->lockNextAvailable($jobQuery);
                $job = $jobQuery->first();

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

    /** @param Builder<PrintJob> $query */
    private function lockNextAvailable(Builder $query): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $query->lock('FOR UPDATE SKIP LOCKED');

            return;
        }

        // SQLite pada test tidak punya row lock; transaksi tetap menguji alur sekuensial.
        $query->lockForUpdate();
    }
}

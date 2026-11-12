<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobAttempt;
use Illuminate\Support\Facades\DB;

final class ExpiredJobLeaseRepository
{
    public function release(int $limit = 100): int
    {
        $jobIds = PrintJob::query()
            ->whereIn('state', [PrintJobState::Claimed, PrintJobState::Printing])
            ->whereNotNull('lease_until')
            ->where('lease_until', '<=', now())
            ->oldest('lease_until')
            ->limit(max(1, $limit))
            ->pluck('id');

        $released = 0;

        foreach ($jobIds as $jobId) {
            $released += $this->releaseOne((string) $jobId) ? 1 : 0;
        }

        return $released;
    }

    private function releaseOne(string $jobId): bool
    {
        return DB::transaction(function () use ($jobId): bool {
            $job = PrintJob::query()
                ->whereKey($jobId)
                ->lockForUpdate()
                ->first();

            if (
                ! $job instanceof PrintJob
                || ! in_array($job->state, [PrintJobState::Claimed, PrintJobState::Printing], true)
                || $job->lease_until === null
                || $job->lease_until->isFuture()
            ) {
                return false;
            }

            $fromState = $job->state;
            $expiredAt = $job->lease_until;
            $leaseAgentId = $job->lease_agent_id;
            $attempt = PrintJobAttempt::query()
                ->where('job_id', $job->getKey())
                ->whereNull('finished_at')
                ->latest('attempt_no')
                ->lockForUpdate()
                ->first();

            if ($attempt instanceof PrintJobAttempt) {
                $attempt->forceFill([
                    'finished_at' => now(),
                    'outcome' => 'lease_expired',
                ])->save();
            }

            $job->forceFill([
                'state' => PrintJobState::Queued,
                'lease_agent_id' => null,
                'lease_until' => null,
                'available_at' => now(),
            ])->save();

            $job->events()->create([
                'attempt_id' => $attempt?->getKey(),
                'actor_type' => 'hub',
                'type' => 'lease_expired',
                'from_state' => $fromState->value,
                'to_state' => PrintJobState::Queued->value,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'data' => [
                    'lease_agent_id' => $leaseAgentId,
                    'lease_until' => $expiredAt->toIso8601String(),
                ],
            ]);

            return true;
        }, attempts: 3);
    }
}

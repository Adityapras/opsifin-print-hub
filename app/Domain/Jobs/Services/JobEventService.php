<?php

namespace App\Domain\Jobs\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Jobs\Repositories\JobEventRepository;

final class JobEventService
{
    public function __construct(private readonly JobEventRepository $repository) {}

    /**
     * @param  array<int, array{event_id: string, attempt_id: string, type: string, occurred_at: string, data?: array<string, mixed>}>  $events
     * @return array<string, mixed>|null
     */
    public function record(Agent $agent, string $jobId, array $events): ?array
    {
        $result = $this->repository->record($agent, $jobId, $events);

        if ($result === null) {
            return null;
        }

        $job = $result['job'];

        return [
            'accepted' => $result['accepted'],
            'duplicates' => $result['duplicates'],
            'job_state' => $job->state->value,
            'lease_until' => $job->lease_until?->utc()->toIso8601String(),
            'abort' => $result['abort'],
        ];
    }
}

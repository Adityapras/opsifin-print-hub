<?php

namespace App\Domain\Jobs\Repositories;

use App\Domain\Delivery\Services\CallbackOutboxService;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Jobs\Enums\AgentJobEventType;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Models\PrintJobAttempt;
use App\Domain\Jobs\Models\PrintJobEvent;
use Illuminate\Support\Facades\DB;

final class JobEventRepository
{
    public function __construct(private readonly CallbackOutboxService $callbacks) {}

    /**
     * @param  array<int, array{event_id: string, attempt_id: string, type: string, occurred_at: string, data?: array<string, mixed>}>  $events
     * @return array{accepted: array<int, string>, duplicates: array<int, string>, job: PrintJob, abort: bool}|null
     */
    public function record(Agent $agent, string $jobId, array $events): ?array
    {
        return DB::transaction(function () use ($agent, $jobId, $events): ?array {
            $job = PrintJob::query()->whereKey($jobId)->lockForUpdate()->first();

            if ($job === null) {
                return null;
            }

            $eventIds = array_column($events, 'event_id');
            $existingIds = PrintJobEvent::query()
                ->where('job_id', $job->getKey())
                ->whereIn('id', $eventIds)
                ->pluck('id')
                ->all();
            $duplicates = array_values(array_intersect($eventIds, $existingIds));
            $newEvents = array_values(array_filter(
                $events,
                fn (array $event): bool => ! in_array($event['event_id'], $existingIds, true),
            ));

            if ($job->state === PrintJobState::Cancelled) {
                return compact('duplicates', 'job') + ['accepted' => [], 'abort' => true];
            }

            if ($newEvents === []) {
                return compact('duplicates', 'job') + ['accepted' => [], 'abort' => false];
            }

            if (! $this->hasValidLease($job, $agent)) {
                return null;
            }

            $accepted = [];

            foreach ($newEvents as $eventPayload) {
                $attempt = PrintJobAttempt::query()
                    ->whereKey($eventPayload['attempt_id'])
                    ->where('job_id', $job->getKey())
                    ->where('agent_id', $agent->getKey())
                    ->first();

                if ($attempt === null || ! $this->canAcceptEventForState($job->state)) {
                    return null;
                }

                $fromState = $job->state;
                $type = AgentJobEventType::from($eventPayload['type']);
                $data = $eventPayload['data'] ?? [];

                $this->applyEvent($job, $attempt, $type, $data);

                PrintJobEvent::query()->create([
                    'id' => $eventPayload['event_id'],
                    'job_id' => $job->getKey(),
                    'attempt_id' => $attempt->getKey(),
                    'actor_type' => 'agent',
                    'actor_id' => $agent->getKey(),
                    'type' => $type->value,
                    'from_state' => $fromState->value,
                    'to_state' => $job->state->value,
                    'occurred_at' => $eventPayload['occurred_at'],
                    'recorded_at' => now(),
                    'data' => $data,
                ]);

                $accepted[] = $eventPayload['event_id'];
            }

            $this->callbacks->enqueueForTerminal($job);

            return compact('accepted', 'duplicates', 'job') + ['abort' => false];
        }, attempts: 3);
    }

    private function hasValidLease(PrintJob $job, Agent $agent): bool
    {
        return $job->lease_agent_id === $agent->getKey()
            && $job->lease_until !== null
            && $job->lease_until->isFuture();
    }

    private function canAcceptEventForState(PrintJobState $state): bool
    {
        return in_array($state, [PrintJobState::Claimed, PrintJobState::Printing], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyEvent(
        PrintJob $job,
        PrintJobAttempt $attempt,
        AgentJobEventType $type,
        array $data,
    ): void {
        if ($type === AgentJobEventType::Spooled) {
            $job->state = PrintJobState::Printing;
            $attempt->spooler_job_id = $data['spooler_job_id'] ?? $attempt->spooler_job_id;
            $attempt->spooled_at = now();
            $attempt->save();
        }

        if (in_array($type, [AgentJobEventType::Completed, AgentJobEventType::PrintedUnverified], true)) {
            $job->state = PrintJobState::Printed;
            $job->printed_at = now()->toImmutable();
            $job->lease_agent_id = null;
            $job->lease_until = null;

            $attempt->finished_at = now();
            $attempt->pages_printed = $data['pages_printed'] ?? null;
            $attempt->outcome = $type === AgentJobEventType::Completed ? 'printed' : 'printed_unverified';
            $attempt->save();
        } elseif ($type === AgentJobEventType::PrintError) {
            $this->applyPrintError($job, $attempt, $data);
        } else {
            $job->lease_until = now()->addSeconds(config('print_hub.lease_seconds'))->toImmutable();
        }

        $job->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyPrintError(PrintJob $job, PrintJobAttempt $attempt, array $data): void
    {
        $errorCode = (string) ($data['error_code'] ?? 'UNKNOWN');
        $permanent = in_array($errorCode, config('print_hub.permanent_print_errors'), true);
        $attemptExhausted = $job->attempt_count >= config('print_hub.max_print_attempts');

        $attempt->finished_at = now();

        if ($permanent || $attemptExhausted) {
            $job->state = PrintJobState::Failed;
            $job->failure_code = $errorCode;
            $attempt->outcome = 'failed';
        } else {
            $job->state = PrintJobState::Queued;
            $job->available_at = now()->addSeconds($this->retryDelay($job->attempt_count));
            $attempt->outcome = 'retry';
        }

        $job->lease_agent_id = null;
        $job->lease_until = null;
        $attempt->save();
    }

    private function retryDelay(int $attemptNumber): int
    {
        return config('print_hub.print_retry_delays')[$attemptNumber - 1]
            ?? config('print_hub.print_retry_delays')[array_key_last(config('print_hub.print_retry_delays'))];
    }
}

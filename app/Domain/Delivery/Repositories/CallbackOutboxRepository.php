<?php

namespace App\Domain\Delivery\Repositories;

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CallbackOutboxRepository
{
    /** @param array<string, mixed> $payload */
    public function enqueue(
        PrintJob $job,
        SourceConnection $connection,
        string $endpoint,
        array $payload,
    ): CallbackOutbox {
        return CallbackOutbox::query()->firstOrCreate(
            ['job_id' => $job->getKey(), 'job_run' => $job->run_number],
            [
                'source_connection_id' => $connection->getKey(),
                'endpoint' => $endpoint,
                'payload' => $payload,
                'status' => 'pending',
                'attempts' => 0,
                'next_attempt_at' => now(),
                'expires_at' => now()->addHours(config('print_hub.callback_expiry_hours')),
            ],
        );
    }

    /** @return Collection<int, CallbackOutbox> */
    public function due(int $limit): Collection
    {
        return CallbackOutbox::query()
            ->with(['job.binding', 'sourceConnection'])
            ->where('status', 'pending')
            ->whereHas('sourceConnection', fn ($query) => $query
                ->where('is_active', true)
                ->where('identity_status', SourceIdentityStatus::Verified->value))
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get();
    }

    public function markSent(CallbackOutbox $outbox, int $httpStatus, string $response): void
    {
        DB::transaction(function () use ($outbox, $httpStatus, $response): void {
            $locked = CallbackOutbox::query()->whereKey($outbox->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return;
            }

            $locked->update([
                'status' => 'sent',
                'attempts' => $locked->attempts + 1,
                'last_http_status' => $httpStatus,
                'last_response' => mb_substr($response, 0, 4000),
                'sent_at' => now(),
            ]);
            $locked->job()->update(['reported_at' => now()]);

            $locked->job->events()->create([
                'actor_type' => 'hub',
                'type' => 'callback_succeeded',
                'from_state' => $locked->job->state->value,
                'to_state' => $locked->job->state->value,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'data' => ['http_status' => $httpStatus],
            ]);
        }, attempts: 3);
    }

    public function markFailed(CallbackOutbox $outbox, ?int $httpStatus, string $response): void
    {
        DB::transaction(function () use ($outbox, $httpStatus, $response): void {
            $locked = CallbackOutbox::query()->whereKey($outbox->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                return;
            }

            $attempts = $locked->attempts + 1;
            $expired = $locked->expires_at->isPast();
            $delays = config('print_hub.callback_retry_delays');
            $delay = $delays[min($attempts - 1, array_key_last($delays))];

            $locked->update([
                'status' => $expired ? 'failed' : 'pending',
                'attempts' => $attempts,
                'last_http_status' => $httpStatus,
                'last_response' => mb_substr($response, 0, 4000),
                'next_attempt_at' => now()->addSeconds($delay),
            ]);

            $locked->job->events()->create([
                'actor_type' => 'hub',
                'type' => 'callback_failed',
                'from_state' => $locked->job->state->value,
                'to_state' => $locked->job->state->value,
                'occurred_at' => now(),
                'recorded_at' => now(),
                'data' => ['http_status' => $httpStatus, 'will_retry' => ! $expired],
            ]);
        }, attempts: 3);
    }
}

<?php

namespace App\Domain\Delivery\Services;

use App\Domain\Delivery\Repositories\CallbackOutboxRepository;
use App\Domain\Sources\Services\LegacyOpsifinClient;
use Throwable;

final class CallbackProcessor
{
    public function __construct(
        private readonly CallbackOutboxRepository $repository,
        private readonly LegacyOpsifinClient $client,
    ) {}

    /** @return array{sent: int, failed: int} */
    public function process(int $limit = 100): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($this->repository->due($limit) as $outbox) {
            try {
                $response = $this->client->sendCallback(
                    $outbox->sourceConnection,
                    $outbox->endpoint,
                    $outbox->payload,
                );

                if ($response->successful()) {
                    $this->repository->markSent($outbox, $response->status(), $response->body());
                    $sent++;

                    continue;
                }

                $this->repository->markFailed($outbox, $response->status(), $response->body());
            } catch (Throwable $exception) {
                // Pesan dibatasi dan credential tidak pernah dimasukkan ke payload error.
                $this->repository->markFailed($outbox, null, $exception->getMessage());
            }

            $failed++;
        }

        return compact('sent', 'failed');
    }
}

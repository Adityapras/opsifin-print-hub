<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Repositories\HeartbeatRepository;

final class HeartbeatService
{
    public function __construct(private readonly HeartbeatRepository $repository) {}

    /**
     * @param  array{agent_version: string, uptime_s: int, agent_time: string, capabilities?: array<int, string>, printers: array<int, array<string, mixed>>}  $payload
     */
    public function record(Agent $agent, array $payload): Agent
    {
        return $this->repository->record($agent, $payload);
    }
}

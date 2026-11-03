<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Repositories\AgentControlRepository;

final class AgentControlService
{
    public function __construct(private readonly AgentControlRepository $repository) {}

    public function revoke(Agent $agent): Agent
    {
        return $this->repository->revoke($agent);
    }

    public function deleteUnused(Agent $agent): bool
    {
        return $this->repository->deleteUnused($agent);
    }
}

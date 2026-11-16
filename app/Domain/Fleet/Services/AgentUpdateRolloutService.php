<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Platform\Services\AgentReleaseService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class AgentUpdateRolloutService
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly AgentReleaseService $releases,
        private readonly AgentUpdateCommandPayload $payloads,
    ) {}

    public function scheduleOne(
        AgentRelease $release,
        Agent $agent,
        User $actor,
    ): AgentUpdateDeployment {
        $this->assertCanSchedule($release, $agent);

        return AgentUpdateDeployment::query()->getConnection()->transaction(function () use ($release, $agent, $actor): AgentUpdateDeployment {
            $deployment = AgentUpdateDeployment::query()->firstOrNew([
                'agent_release_id' => $release->getKey(),
                'agent_id' => $agent->getKey(),
            ]);

            if ($deployment->exists && ! in_array($deployment->status, ['failed', 'cancelled', 'stalled'], true)) {
                return $deployment;
            }

            $deployment->fill([
                'agent_command_id' => null,
                'scheduled_by' => $actor->getKey(),
                'install_policy' => $release->install_policy,
                'status' => 'pending',
                'progress_percent' => 0,
                'message' => null,
                'scheduled_at' => now(),
                'delivered_at' => null,
                'accepted_at' => null,
                'installed_at' => null,
                'failed_at' => null,
                'last_observed_at' => now(),
            ])->save();

            $command = $this->commands->dispatch($agent, 'update', $this->payloads->build($deployment));

            $deployment->update(['agent_command_id' => $command->getKey()]);

            return $deployment->fresh(['release', 'agent', 'command']);
        }, attempts: 3);
    }

    /** @return Collection<int, AgentUpdateDeployment> */
    public function schedulePilot(AgentRelease $release, User $actor): Collection
    {
        $eligible = $this->eligibleAgents($release);
        $count = $eligible->isEmpty() ? 0 : max(1, (int) ceil($eligible->count() * 0.10));

        return $this->scheduleMany($release, $eligible->take($count), $actor, 'rollout.pilot_scheduled');
    }

    /** @return Collection<int, AgentUpdateDeployment> */
    public function scheduleRemaining(AgentRelease $release, User $actor): Collection
    {
        return $this->scheduleMany(
            $release,
            $this->eligibleAgents($release),
            $actor,
            'rollout.remaining_scheduled',
        );
    }

    /** @return Collection<int, Agent> */
    private function eligibleAgents(AgentRelease $release): Collection
    {
        return Agent::query()
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Agent $agent): bool => version_compare($agent->version, $release->version, '<'))
            ->filter(fn (Agent $agent): bool => ! AgentUpdateDeployment::query()
                ->where('agent_release_id', $release->getKey())
                ->where('agent_id', $agent->getKey())
                ->whereNotIn('status', ['failed', 'cancelled', 'stalled'])
                ->exists())
            ->values();
    }

    /**
     * @param  Collection<int, Agent>  $agents
     * @return Collection<int, AgentUpdateDeployment>
     */
    private function scheduleMany(
        AgentRelease $release,
        Collection $agents,
        User $actor,
        string $eventType,
    ): Collection {
        $deployments = $agents->map(
            fn (Agent $agent): AgentUpdateDeployment => $this->scheduleOne($release, $agent, $actor),
        );

        $this->releases->event($release, $actor, $eventType, [
            'scheduled_count' => $deployments->count(),
        ]);

        return $deployments;
    }

    private function assertCanSchedule(AgentRelease $release, Agent $agent): void
    {
        if ($release->status !== AgentRelease::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['release' => 'Only published releases can be scheduled.']);
        }

        if ($agent->revoked_at !== null) {
            throw ValidationException::withMessages(['agent' => 'A revoked Agent cannot receive updates.']);
        }

        if (version_compare($agent->version, $release->version, '>=')) {
            throw ValidationException::withMessages(['release' => 'This Agent already has this version or a newer version.']);
        }
    }
}

<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Platform\Services\AgentReleaseService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

final class AgentUpdateRolloutService
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly AgentReleaseService $releases,
    ) {}

    public function scheduleOne(
        AgentRelease $release,
        Agent $agent,
        User $actor,
        string $installPolicy = 'automatic',
    ): AgentUpdateDeployment {
        $this->assertCanSchedule($release, $agent, $installPolicy);

        return AgentUpdateDeployment::query()->getConnection()->transaction(function () use ($release, $agent, $actor, $installPolicy): AgentUpdateDeployment {
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
                'install_policy' => $installPolicy,
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

            $downloadUrl = URL::temporarySignedRoute(
                'agent.v1.updates.package',
                now()->addMinutes((int) config('print_hub.agent_updates.download_url_ttl_minutes')),
                ['deploymentId' => $deployment->getKey()],
            );

            $args = [
                'version' => $release->version,
                'url' => $downloadUrl,
                'sha256' => $release->sha256,
                'install_policy' => $installPolicy,
                'release_notes' => $release->release_notes,
            ];
            if ($release->signature_type === AgentRelease::SIGNATURE_AUTHENTICODE) {
                // Marker lama dipertahankan agar Agent sebelum bridge tetap dapat diperbarui.
                $args['signature'] = AgentRelease::SIGNATURE_AUTHENTICODE;
            } else {
                $args += [
                    'signature_scheme' => $release->signature_type,
                    'signature' => $release->signature_value,
                    'signing_key_id' => $release->signing_key_id,
                    'size_bytes' => $release->size_bytes,
                    'channel' => $release->channel,
                ];
            }

            $command = $this->commands->dispatch($agent, 'update', $args);

            $deployment->update(['agent_command_id' => $command->getKey()]);

            return $deployment->fresh(['release', 'agent', 'command']);
        }, attempts: 3);
    }

    /** @return Collection<int, AgentUpdateDeployment> */
    public function schedulePilot(AgentRelease $release, User $actor): Collection
    {
        $eligible = $this->eligibleAgents($release);
        $count = $eligible->isEmpty() ? 0 : max(1, (int) ceil($eligible->count() * 0.10));

        return $this->scheduleMany($release, $eligible->take($count), $actor, 'automatic', 'rollout.pilot_scheduled');
    }

    /** @return Collection<int, AgentUpdateDeployment> */
    public function scheduleRemaining(AgentRelease $release, User $actor): Collection
    {
        return $this->scheduleMany(
            $release,
            $this->eligibleAgents($release),
            $actor,
            'automatic',
            'rollout.remaining_scheduled',
        );
    }

    /** @return Collection<int, Agent> */
    private function eligibleAgents(AgentRelease $release): Collection
    {
        $internalSignatureMinimum = (string) config(
            'print_hub.agent_updates.internal_signature_min_agent_version',
            '0.3.12',
        );

        return Agent::query()
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Agent $agent): bool => version_compare($agent->version, $release->version, '<'))
            ->filter(fn (Agent $agent): bool => $release->signature_type !== AgentRelease::SIGNATURE_OPSIGO_INTERNAL
                || version_compare($agent->version, $internalSignatureMinimum, '>='))
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
        string $installPolicy,
        string $eventType,
    ): Collection {
        $deployments = $agents->map(
            fn (Agent $agent): AgentUpdateDeployment => $this->scheduleOne($release, $agent, $actor, $installPolicy),
        );

        $this->releases->event($release, $actor, $eventType, [
            'scheduled_count' => $deployments->count(),
        ]);

        return $deployments;
    }

    private function assertCanSchedule(AgentRelease $release, Agent $agent, string $installPolicy): void
    {
        if ($release->status !== AgentRelease::STATUS_PUBLISHED) {
            throw ValidationException::withMessages(['release' => 'Only published releases can be scheduled.']);
        }

        if (! in_array($installPolicy, ['manual', 'automatic'], true)) {
            throw ValidationException::withMessages(['install_policy' => 'Choose manual or automatic.']);
        }

        if ($agent->revoked_at !== null) {
            throw ValidationException::withMessages(['agent' => 'A revoked Agent cannot receive updates.']);
        }

        $internalSignatureMinimum = (string) config(
            'print_hub.agent_updates.internal_signature_min_agent_version',
            '0.3.12',
        );
        if ($release->signature_type === AgentRelease::SIGNATURE_OPSIGO_INTERNAL
            && version_compare($agent->version, $internalSignatureMinimum, '<')) {
            throw ValidationException::withMessages([
                'agent' => "Install the Authenticode bridge Agent {$internalSignatureMinimum} before scheduling an internally signed release.",
            ]);
        }

        if (version_compare($agent->version, $release->version, '>=')) {
            throw ValidationException::withMessages(['release' => 'This Agent already has this version or a newer version.']);
        }
    }
}

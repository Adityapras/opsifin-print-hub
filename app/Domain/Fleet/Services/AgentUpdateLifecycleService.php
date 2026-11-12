<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentCommand;
use App\Domain\Fleet\Models\AgentUpdateDeployment;
use Illuminate\Support\Facades\URL;

final class AgentUpdateLifecycleService
{
    /** @var array<int, string> */
    private const REPORTED_STATUSES = [
        'downloading',
        'ready',
        'waiting_for_idle',
        'installing',
        'failed',
    ];

    public function prepareCommandForDelivery(AgentCommand $command): void
    {
        $deployment = $command->updateDeployment;
        if ($command->type !== 'update' || $deployment === null) {
            return;
        }

        // URL dibuat ulang tepat saat Agent online agar mesin yang lama offline
        // tidak menerima tautan paket yang sudah kedaluwarsa.
        $arguments = $command->args ?? [];
        $arguments['url'] = URL::temporarySignedRoute(
            'agent.v1.updates.package',
            now()->addMinutes((int) config('print_hub.agent_updates.download_url_ttl_minutes')),
            ['deploymentId' => $deployment->getKey()],
        );
        $command->update(['args' => $arguments]);
    }

    public function commandDelivered(AgentCommand $command): void
    {
        if ($command->type !== 'update') {
            return;
        }

        $deployment = $command->updateDeployment;
        if ($deployment === null || $deployment->status !== 'pending') {
            return;
        }

        $deployment->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'last_observed_at' => now(),
        ]);
    }

    public function commandResult(AgentCommand $command): void
    {
        if ($command->type !== 'update' || $command->updateDeployment === null) {
            return;
        }

        // Command sukses hanya berarti paket diterima Agent. Versi heartbeat tetap
        // menjadi bukti akhir agar Hub tidak melaporkan pemasangan yang belum terjadi.
        $command->updateDeployment->update($command->status === 'succeeded'
            ? [
                'status' => 'accepted',
                'accepted_at' => now(),
                'last_observed_at' => now(),
                'message' => $command->result_message,
            ]
            : [
                'status' => 'failed',
                'failed_at' => now(),
                'last_observed_at' => now(),
                'message' => $command->result_message,
            ]);
    }

    /** @param array<string, mixed>|null $reportedUpdate */
    public function observeHeartbeat(Agent $agent, ?array $reportedUpdate): void
    {
        $deployments = AgentUpdateDeployment::query()
            ->with('release')
            ->where('agent_id', $agent->getKey())
            ->whereNotIn('status', AgentUpdateDeployment::TERMINAL_STATUSES)
            ->get();

        foreach ($deployments as $deployment) {
            if (version_compare($agent->version, $deployment->release->version, '>=')) {
                $deployment->update([
                    'status' => 'installed',
                    'progress_percent' => 100,
                    'installed_at' => now(),
                    'last_observed_at' => now(),
                    'message' => 'Target version confirmed by Agent heartbeat.',
                ]);

                continue;
            }

            if ($reportedUpdate === null
                || ($reportedUpdate['command_id'] ?? null) !== $deployment->agent_command_id
                || ! in_array($reportedUpdate['status'] ?? null, self::REPORTED_STATUSES, true)) {
                continue;
            }

            $status = (string) $reportedUpdate['status'];
            $deployment->update([
                'status' => $status,
                'progress_percent' => (int) ($reportedUpdate['progress_percent'] ?? $deployment->progress_percent),
                'message' => $reportedUpdate['message'] ?? $deployment->message,
                'last_observed_at' => now(),
                'failed_at' => $status === 'failed' ? now() : null,
            ]);
        }
    }

    public function markStalled(): int
    {
        $cutoff = now()->subMinutes((int) config('print_hub.agent_updates.stalled_after_minutes'));

        return AgentUpdateDeployment::query()
            ->whereNotIn('status', [...AgentUpdateDeployment::TERMINAL_STATUSES, 'stalled'])
            ->where(fn ($query) => $query
                ->where('last_observed_at', '<', $cutoff)
                ->orWhere(fn ($query) => $query->whereNull('last_observed_at')->where('scheduled_at', '<', $cutoff)))
            ->update([
                'status' => 'stalled',
                'message' => 'No update progress was observed within the configured time limit.',
                'updated_at' => now(),
            ]);
    }
}

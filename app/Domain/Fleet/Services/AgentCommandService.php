<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentCommand;
use Illuminate\Http\UploadedFile;

final class AgentCommandService
{
    public function __construct(private readonly AgentUpdateLifecycleService $updates) {}

    /** @param array<string, mixed> $args */
    public function dispatch(Agent $agent, string $type, array $args = []): AgentCommand
    {
        return AgentCommand::query()->create([
            'agent_id' => $agent->getKey(),
            'type' => $type,
            'args' => $args,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array<int, array{id: string, type: string, args: array<string, mixed>}>
     */
    public function forHeartbeat(Agent $agent): array
    {
        $commands = AgentCommand::query()
            ->where('agent_id', $agent->getKey())
            ->whereIn('status', ['pending', 'delivered'])
            ->oldest()
            ->limit(20)
            ->get();

        foreach ($commands as $command) {
            if ($command->status === 'pending') {
                $this->updates->prepareCommandForDelivery($command);
                $command->update([
                    'status' => 'delivered',
                    'delivered_at' => now(),
                ]);
                $this->updates->commandDelivered($command);
            }
        }

        return $commands->map(fn (AgentCommand $command): array => [
            'id' => $command->getKey(),
            'type' => $command->type,
            'args' => $command->args ?? [],
        ])->all();
    }

    public function recordResult(
        Agent $agent,
        string $commandId,
        string $status,
        ?string $message,
        ?UploadedFile $attachment,
    ): void {
        $command = AgentCommand::query()
            ->whereKey($commandId)
            ->where('agent_id', $agent->getKey())
            ->first();

        if ($command === null || in_array($command->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $attachmentPath = null;

        if ($attachment !== null) {
            $storedPath = $attachment->store(
                "tenants/{$agent->tenant_id}/agent-command-results/{$command->getKey()}",
                'local',
            );
            $attachmentPath = $storedPath === false ? null : $storedPath;
        }

        $command->update([
            'status' => $status,
            'finished_at' => now(),
            'result_message' => $message,
            'attachment_disk' => $attachmentPath === null ? null : 'local',
            'attachment_path' => $attachmentPath,
        ]);

        $this->updates->commandResult($command->refresh());
    }
}

<?php

namespace App\Domain\Fleet\Repositories;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use Illuminate\Support\Facades\DB;

final class HeartbeatRepository
{
    /**
     * @param  array{agent_version: string, uptime_s: int, agent_time: string, capabilities?: array<int, string>, printers: array<int, array<string, mixed>>}  $payload
     */
    public function record(Agent $agent, array $payload): Agent
    {
        return DB::transaction(function () use ($agent, $payload): Agent {
            $seenAt = now();

            $agent->update([
                'version' => $payload['agent_version'],
                'uptime_s' => $payload['uptime_s'],
                'agent_time' => $payload['agent_time'],
                'capabilities' => $payload['capabilities'] ?? [],
                'last_seen_at' => $seenAt,
                'status' => 'online',
            ]);

            $reportedNames = [];

            foreach ($payload['printers'] as $report) {
                $reportedNames[] = $report['name'];

                Printer::query()->updateOrCreate(
                    [
                        'agent_id' => $agent->getKey(),
                        'windows_name' => $report['name'],
                    ],
                    [
                        'driver' => $report['driver'] ?? null,
                        'port' => $report['port'] ?? null,
                        'is_default' => $report['is_default'] ?? false,
                        'status' => $report['status'],
                        'raw_status' => $report['raw_status'] ?? null,
                        'queue_length' => $report['queue_length'] ?? 0,
                        'last_seen_at' => $seenAt,
                    ],
                );
            }

            $missingPrinters = Printer::query()->where('agent_id', $agent->getKey());

            if ($reportedNames !== []) {
                $missingPrinters->whereNotIn('windows_name', $reportedNames);
            }

            $missingPrinters->update(['status' => 'OFFLINE']);

            return $agent->fresh(['printers']);
        }, attempts: 3);
    }
}

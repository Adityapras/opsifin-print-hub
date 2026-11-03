<?php

namespace App\Domain\Fleet\Repositories;

use App\Domain\Fleet\Models\Agent;
use Illuminate\Support\Facades\DB;

final class AgentControlRepository
{
    public function revoke(Agent $agent): Agent
    {
        return DB::transaction(function () use ($agent): Agent {
            $locked = Agent::query()->whereKey($agent->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->revoked_at !== null) {
                return $locked;
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'status' => 'offline',
            ])->save();

            // Printer tetap disimpan untuk menjaga binding dan riwayat job, tetapi
            // statusnya harus langsung mencerminkan bahwa pemiliknya tidak aktif.
            $locked->printers()->update([
                'status' => 'OFFLINE',
            ]);

            $locked->commands()
                ->whereIn('status', ['pending', 'delivered'])
                ->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'result_message' => 'Agent access was revoked before the command completed.',
                ]);

            return $locked->fresh(['printers']);
        }, attempts: 3);
    }

    public function deleteUnused(Agent $agent): bool
    {
        return DB::transaction(function () use ($agent): bool {
            $locked = Agent::query()->whereKey($agent->getKey())->lockForUpdate()->firstOrFail();

            // Agent yang sudah melaporkan printer dapat terhubung ke binding dan job.
            // Hard delete dibatasi agar jejak operasional tidak ikut terhapus.
            if ($locked->revoked_at === null || $locked->printers()->exists()) {
                return false;
            }

            return (bool) $locked->delete();
        }, attempts: 3);
    }
}

<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;

final class AgentConfigVersionService
{
    /** @param array<int, string> $printerIds */
    public function bumpForPrinters(array $printerIds): void
    {
        $printerIds = array_values(array_unique(array_filter($printerIds)));

        if ($printerIds === []) {
            return;
        }

        $agentIds = Printer::query()
            ->whereIn('id', $printerIds)
            ->pluck('agent_id')
            ->unique()
            ->values();

        if ($agentIds->isEmpty()) {
            return;
        }

        // Remote config hanya dikirim ketika versinya berubah, sehingga setiap Agent
        // terdampak harus dipaksa mengambil ulang konfigurasi pada heartbeat berikutnya.
        Agent::query()
            ->whereIn('id', $agentIds)
            ->increment('config_version');
    }
}

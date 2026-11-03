<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;

final class AgentConfigBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(Agent $agent): array
    {
        $printers = $agent->printers()
            ->where('is_active', true)
            ->where('is_paused', false)
            ->orderBy('windows_name')
            ->get()
            ->map(fn (Printer $printer): array => [
                'printer_id' => $printer->getKey(),
                'windows_name' => $printer->windows_name,
                'print' => array_replace(config('print_hub.default_print_settings'), $printer->print_settings ?? []),
            ])
            ->values()
            ->all();

        return [
            'claim_interval_s' => config('print_hub.claim_interval_s'),
            'heartbeat_interval_s' => config('print_hub.heartbeat_interval_s'),
            'log_level' => config('print_hub.agent_log_level'),
            'printers' => $printers,
        ];
    }
}

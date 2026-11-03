<?php

namespace App\Domain\Fleet\Repositories;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentPairingCode;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

final class AgentEnrollmentRepository
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array{hostname: string, os: string, agent_version: string, machine_id: string}  $attributes
     */
    public function consumePairingCode(string $codeHash, string $secretHash, array $attributes): ?Agent
    {
        return DB::transaction(function () use ($codeHash, $secretHash, $attributes): ?Agent {
            $pairingCode = AgentPairingCode::withoutGlobalScopes()
                ->where('code_hash', $codeHash)
                ->lockForUpdate()
                ->first();

            if ($pairingCode === null || $pairingCode->used_at !== null || $pairingCode->expires_at->isPast()) {
                return null;
            }

            $previousTenantId = $this->tenantContext->id();
            $this->tenantContext->set($pairingCode->tenant_id);

            try {
                $agent = Agent::query()
                    ->where('machine_id', $attributes['machine_id'])
                    ->lockForUpdate()
                    ->first();

                $agent ??= new Agent;
                $agent->forceFill([
                    'site_id' => $pairingCode->site_id,
                    'hostname' => $attributes['hostname'],
                    'os' => $attributes['os'],
                    'version' => $attributes['agent_version'],
                    'machine_id' => $attributes['machine_id'],
                    'secret_hash' => $secretHash,
                    'last_seen_at' => null,
                    'status' => 'offline',
                    'revoked_at' => null,
                ])->save();

                // Pairing ulang pada PC yang sama mempertahankan Agent ID dan printer.
                // Printer baru akan diselaraskan kembali pada heartbeat pertama.
                $agent->printers()->update(['status' => 'OFFLINE']);

                $pairingCode->forceFill(['used_at' => now()])->save();

                return $agent->fresh();
            } finally {
                if ($previousTenantId === null) {
                    $this->tenantContext->clear();
                } else {
                    $this->tenantContext->set($previousTenantId);
                }
            }
        }, attempts: 3);
    }
}

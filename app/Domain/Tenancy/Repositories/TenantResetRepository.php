<?php

namespace App\Domain\Tenancy\Repositories;

use Illuminate\Support\Facades\DB;

final class TenantResetRepository
{
    /** @return array<string, int> */
    public function blockers(string $tenantId): array
    {
        return array_filter([
            'active_print_jobs' => DB::table('print_jobs')
                ->where('tenant_id', $tenantId)
                ->whereIn('state', ['claimed', 'printing'])
                ->count(),
            'pending_callbacks' => DB::table('callback_outbox')
                ->where('tenant_id', $tenantId)
                ->where('status', 'pending')
                ->count(),
            'active_refetch_requests' => DB::table('print_job_retry_requests')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['pending', 'processing'])
                ->count(),
            'active_agent_updates' => DB::table('agent_update_deployments')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['downloading', 'ready', 'waiting_for_idle', 'installing'])
                ->count(),
        ]);
    }

    /** @return array<string, int> */
    public function counts(string $tenantId): array
    {
        $tables = [
            'source_connections',
            'sites',
            'agent_pairing_codes',
            'agents',
            'printers',
            'printer_bindings',
            'print_jobs',
            'print_job_attempts',
            'print_job_events',
            'print_job_retry_requests',
            'callback_outbox',
            'documents',
            'agent_commands',
            'agent_update_deployments',
        ];

        return collect($tables)
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->where('tenant_id', $tenantId)->count(),
            ])
            ->all();
    }

    /** @return array<int, array{disk: string, path: string}> */
    public function documentFiles(string $tenantId): array
    {
        return DB::table('documents')
            ->where('tenant_id', $tenantId)
            ->get(['disk', 'path'])
            ->map(fn (object $document): array => [
                'disk' => (string) $document->disk,
                'path' => (string) $document->path,
            ])
            ->all();
    }

    public function clear(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            // Urutan child-to-parent mempertahankan seluruh foreign key tetap aktif selama reset.
            foreach ([
                'callback_outbox',
                'print_job_retry_requests',
                'print_job_events',
                'print_job_attempts',
                'print_jobs',
                'documents',
                'printer_bindings',
                'agent_update_deployments',
                'agent_commands',
                'printers',
                'agents',
                'agent_pairing_codes',
                'sites',
                'source_connections',
            ] as $table) {
                DB::table($table)->where('tenant_id', $tenantId)->delete();
            }

            // Membership dipertahankan, tetapi pembatas site lama tidak boleh menunjuk ID yang sudah hilang.
            DB::table('memberships')->where('tenant_id', $tenantId)->update([
                'site_ids' => null,
                'updated_at' => now(),
            ]);
        }, attempts: 3);
    }
}

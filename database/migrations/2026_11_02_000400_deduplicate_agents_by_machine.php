<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('agents')
            ->select(['tenant_id', 'machine_id'])
            ->groupBy('tenant_id', 'machine_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::transaction(function () use ($duplicate): void {
                $agents = DB::table('agents')
                    ->where('tenant_id', $duplicate->tenant_id)
                    ->where('machine_id', $duplicate->machine_id)
                    ->orderByRaw('revoked_at IS NULL DESC')
                    ->orderByDesc('last_seen_at')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->get();

                $canonical = $agents->first();

                foreach ($agents->skip(1) as $obsolete) {
                    $this->mergeAgent($canonical->id, $obsolete->id, $duplicate->tenant_id);
                }
            });
        }

        Schema::table('agents', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'machine_id'], 'agents_tenant_machine_unique');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropUnique('agents_tenant_machine_unique');
        });
    }

    private function mergeAgent(string $canonicalAgentId, string $obsoleteAgentId, string $tenantId): void
    {
        DB::table('print_job_attempts')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $obsoleteAgentId)
            ->update(['agent_id' => $canonicalAgentId]);

        DB::table('print_jobs')
            ->where('tenant_id', $tenantId)
            ->where('lease_agent_id', $obsoleteAgentId)
            ->update(['lease_agent_id' => $canonicalAgentId]);

        DB::table('print_job_events')
            ->where('tenant_id', $tenantId)
            ->where('actor_type', 'agent')
            ->where('actor_id', $obsoleteAgentId)
            ->update(['actor_id' => $canonicalAgentId]);

        DB::table('agent_commands')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $obsoleteAgentId)
            ->update(['agent_id' => $canonicalAgentId]);

        $obsoletePrinters = DB::table('printers')
            ->where('tenant_id', $tenantId)
            ->where('agent_id', $obsoleteAgentId)
            ->get();

        foreach ($obsoletePrinters as $printer) {
            $canonicalPrinter = DB::table('printers')
                ->where('tenant_id', $tenantId)
                ->where('agent_id', $canonicalAgentId)
                ->where('windows_name', $printer->windows_name)
                ->first();

            if ($canonicalPrinter === null) {
                DB::table('printers')->where('id', $printer->id)->update([
                    'agent_id' => $canonicalAgentId,
                    'status' => 'OFFLINE',
                ]);

                continue;
            }

            DB::table('printer_bindings')
                ->where('tenant_id', $tenantId)
                ->where('printer_id', $printer->id)
                ->update(['printer_id' => $canonicalPrinter->id]);

            DB::table('print_jobs')
                ->where('tenant_id', $tenantId)
                ->where('printer_id', $printer->id)
                ->update(['printer_id' => $canonicalPrinter->id]);

            DB::table('printers')->where('id', $printer->id)->delete();
        }

        DB::table('agents')->where('id', $obsoleteAgentId)->delete();
    }
};

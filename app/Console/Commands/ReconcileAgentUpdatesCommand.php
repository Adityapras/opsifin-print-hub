<?php

namespace App\Console\Commands;

use App\Domain\Fleet\Services\AgentUpdateLifecycleService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class ReconcileAgentUpdatesCommand extends Command
{
    protected $signature = 'hub:reconcile-agent-updates';

    protected $description = 'Mark Agent update deployments that stopped reporting progress';

    public function handle(TenantContext $context, AgentUpdateLifecycleService $updates): int
    {
        $marked = 0;

        try {
            Tenant::query()->each(function (Tenant $tenant) use ($context, $updates, &$marked): void {
                $context->set($tenant->getKey());
                $marked += $updates->markStalled();
            });
        } finally {
            $context->clear();
        }

        $this->info("Marked {$marked} Agent update deployment(s) as stalled.");

        return self::SUCCESS;
    }
}

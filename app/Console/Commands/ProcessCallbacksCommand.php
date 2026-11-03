<?php

namespace App\Console\Commands;

use App\Domain\Delivery\Services\CallbackProcessor;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class ProcessCallbacksCommand extends Command
{
    protected $signature = 'hub:process-callbacks {--limit=100 : Callback limit per tenant}';

    protected $description = 'Send due terminal callbacks from the outbox';

    public function handle(TenantContext $context, CallbackProcessor $processor): int
    {
        $totals = ['sent' => 0, 'failed' => 0];

        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $context->set($tenant->getKey());

            try {
                $result = $processor->process((int) $this->option('limit'));
                $totals['sent'] += $result['sent'];
                $totals['failed'] += $result['failed'];
            } finally {
                $context->clear();
            }
        }

        $this->components->info("Callbacks completed: {$totals['sent']} sent, {$totals['failed']} rescheduled.");

        return self::SUCCESS;
    }
}

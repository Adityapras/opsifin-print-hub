<?php

namespace App\Console\Commands;

use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Services\SourcePullService;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Throwable;

final class PullSourcesCommand extends Command
{
    protected $signature = 'hub:pull-sources {--binding= : Process only one printer binding}';

    protected $description = 'Pull due print queues from all active sources';

    public function handle(TenantContext $context, SourcePullService $service): int
    {
        $query = PrinterBinding::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query): void {
                $query->whereNull('next_pull_at')->orWhere('next_pull_at', '<=', now());
            });

        if ($bindingId = $this->option('binding')) {
            $query->whereKey((string) $bindingId);
        }

        $totals = ['received' => 0, 'queued' => 0, 'skipped' => 0, 'failed' => 0, 'duplicates' => 0];
        $sourceFailures = 0;

        foreach ($query->cursor() as $binding) {
            $context->set($binding->tenant_id);

            try {
                foreach ($service->pull($binding) as $key => $count) {
                    $totals[$key] += $count;
                }
            } catch (Throwable $exception) {
                $sourceFailures++;
                $this->components->warn("Binding {$binding->getKey()}: {$exception->getMessage()}");
            } finally {
                // Scheduler long-lived tidak boleh membawa konteks tenant ke iterasi berikutnya.
                $context->clear();
            }
        }

        $this->components->info(sprintf(
            'Pull completed: %d received, %d queued, %d skipped, %d failed items, %d duplicates, %d source failures.',
            $totals['received'],
            $totals['queued'],
            $totals['skipped'],
            $totals['failed'],
            $totals['duplicates'],
            $sourceFailures,
        ));

        return $sourceFailures === 0 ? self::SUCCESS : self::FAILURE;
    }
}

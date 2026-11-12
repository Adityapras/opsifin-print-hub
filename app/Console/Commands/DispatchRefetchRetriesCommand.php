<?php

namespace App\Console\Commands;

use App\Domain\Jobs\Jobs\ProcessRefetchRetry;
use App\Domain\Jobs\Models\PrintJobRetryRequest;
use Illuminate\Console\Command;

final class DispatchRefetchRetriesCommand extends Command
{
    protected $signature = 'hub:dispatch-refetch-retries {--limit=100 : Maximum requests to recover per run}';

    protected $description = 'Dispatch pending or stale PDF refetch requests to Horizon';

    public function handle(): int
    {
        $requests = PrintJobRetryRequest::withoutGlobalScopes()
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'processing')
                            ->where('started_at', '<=', now()->subMinutes(5));
                    });
            })
            ->oldest('requested_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id']);

        foreach ($requests as $request) {
            // ShouldBeUnique mencegah recovery scheduler menggandakan job yang masih ada di Redis.
            ProcessRefetchRetry::dispatch((string) $request->getKey());
        }

        $this->components->info("Dispatched {$requests->count()} PDF refetch request(s).");

        return self::SUCCESS;
    }
}

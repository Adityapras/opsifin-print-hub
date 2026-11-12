<?php

namespace App\Console\Commands;

use App\Domain\Jobs\Repositories\ExpiredJobLeaseRepository;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class ReleaseExpiredJobLeasesCommand extends Command
{
    protected $signature = 'hub:release-expired-job-leases
        {--limit=100 : Maximum expired leases to release per tenant}';

    protected $description = 'Return jobs with expired Agent leases to the print queue';

    public function handle(TenantContext $context, ExpiredJobLeaseRepository $leases): int
    {
        $released = 0;

        foreach (Tenant::query()->where('status', 'active')->cursor() as $tenant) {
            $context->set($tenant->getKey());

            try {
                $released += $leases->release((int) $this->option('limit'));
            } finally {
                // Scheduler melayani semua tenant; konteks tenant tidak boleh terbawa ke iterasi berikutnya.
                $context->clear();
            }
        }

        $this->components->info("Released {$released} expired print job lease(s).");

        return self::SUCCESS;
    }
}

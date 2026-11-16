<?php

namespace App\Console\Commands;

use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Services\PrinterStatusSyncService;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class SyncPrinterStatusesCommand extends Command
{
    protected $signature = 'hub:sync-printer-statuses {--binding= : Process only one printer binding}';

    protected $description = 'Send due effective printer statuses to Opsifin Main';

    public function handle(TenantContext $context, PrinterStatusSyncService $service): int
    {
        $query = PrinterBinding::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('tenant', fn ($query) => $query->where('status', 'active'))
            ->whereHas('sourceConnection', fn ($query) => $query
                ->withoutGlobalScope('tenant')
                ->whereColumn('source_connections.tenant_id', 'printer_bindings.tenant_id')
                ->where('is_active', true)
                ->where('identity_status', SourceIdentityStatus::Verified->value))
            ->where(function ($query): void {
                $query->whereNull('next_status_sync_at')
                    ->orWhere('next_status_sync_at', '<=', now());
            });

        if ($bindingId = $this->option('binding')) {
            $query->whereKey((string) $bindingId);
        }

        $synced = 0;
        $failed = 0;

        foreach ($query->cursor() as $binding) {
            $context->set($binding->tenant_id);

            try {
                $service->sync($binding) ? $synced++ : $failed++;
            } finally {
                // Scheduler long-lived tidak boleh membawa konteks tenant ke binding berikutnya.
                $context->clear();
            }
        }

        $this->components->info("Printer status sync completed: {$synced} sent, {$failed} rescheduled.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

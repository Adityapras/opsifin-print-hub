<?php

namespace App\Domain\Sources\Services;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Sources\Models\PrinterBinding;
use RuntimeException;
use Throwable;

final class PrinterStatusSyncService
{
    public function __construct(private readonly LegacyOpsifinClient $client) {}

    public function sync(PrinterBinding $binding): bool
    {
        $binding->loadMissing(['sourceConnection', 'printer.agent']);
        $status = $this->opsifinStatus($binding->printer);

        try {
            $response = $this->client->sendPrinterStatus($binding->sourceConnection, [
                'PrinterName' => $binding->source_printer_name,
                'Identifier' => $binding->source_identifier,
                'PrinterStatus' => $status,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("Opsifin returned HTTP {$response->status()}.");
            }

            $binding->update([
                'last_synced_printer_status' => $status,
                'status_sync_attempts' => 0,
                'last_status_synced_at' => now(),
                'next_status_sync_at' => now()->addSeconds(config('print_hub.printer_status_interval_s')),
                'last_status_sync_error' => null,
            ]);

            return true;
        } catch (Throwable $exception) {
            $attempts = $binding->status_sync_attempts + 1;
            $delays = config('print_hub.printer_status_retry_delays');
            $delay = $delays[min($attempts - 1, array_key_last($delays))];

            $binding->update([
                'status_sync_attempts' => $attempts,
                'next_status_sync_at' => now()->addSeconds($delay),
                'last_status_sync_error' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            return false;
        }
    }

    private function opsifinStatus(Printer $printer): string
    {
        $status = $printer->effective_status;

        return match ($status) {
            'AGENT_STALE', 'AGENT_OFFLINE', 'AGENT_REVOKED', 'UNKNOWN' => 'OFFLINE',
            default => strtoupper($status),
        };
    }
}

<?php

namespace App\Domain\Sources\Observers;

use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Services\SourceAgentConfigInvalidator;

final class PrinterBindingConfigObserver
{
    public function __construct(private readonly SourceAgentConfigInvalidator $config) {}

    public function created(PrinterBinding $binding): void
    {
        $this->config->forPrinters([$binding->printer_id]);
    }

    public function updated(PrinterBinding $binding): void
    {
        if (! $binding->wasChanged(['printer_id', 'source_connection_id', 'is_active'])) {
            return;
        }

        $this->config->forPrinters([
            (string) $binding->getOriginal('printer_id'),
            $binding->printer_id,
        ]);
    }

    public function deleted(PrinterBinding $binding): void
    {
        $this->config->forPrinters([$binding->printer_id]);
    }
}

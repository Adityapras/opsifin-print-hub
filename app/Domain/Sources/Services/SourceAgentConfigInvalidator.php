<?php

namespace App\Domain\Sources\Services;

use App\Domain\Fleet\Services\AgentConfigVersionService;
use App\Domain\Sources\Models\SourceConnection;

final class SourceAgentConfigInvalidator
{
    public function __construct(private readonly AgentConfigVersionService $versions) {}

    public function forConnection(SourceConnection $connection): void
    {
        $this->forPrinters($connection->bindings()->pluck('printer_id')->all());
    }

    /** @param array<int, string> $printerIds */
    public function forPrinters(array $printerIds): void
    {
        $this->versions->bumpForPrinters($printerIds);
    }
}

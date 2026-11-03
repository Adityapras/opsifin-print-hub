<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Repositories\PrinterControlRepository;

final class PrinterControlService
{
    public function __construct(private readonly PrinterControlRepository $repository) {}

    /** @param array<string, mixed> $attributes */
    public function update(Printer $printer, array $attributes): Printer
    {
        return $this->repository->update($printer, $attributes);
    }

    public function setPaused(Printer $printer, bool $paused): Printer
    {
        return $this->repository->update($printer, ['is_paused' => $paused]);
    }
}

<?php

namespace App\Domain\Fleet\Repositories;

use App\Domain\Fleet\Models\Printer;
use Illuminate\Support\Facades\DB;

final class PrinterControlRepository
{
    /** @param array<string, mixed> $attributes */
    public function update(Printer $printer, array $attributes): Printer
    {
        return DB::transaction(function () use ($printer, $attributes): Printer {
            $locked = Printer::query()->whereKey($printer->getKey())->lockForUpdate()->firstOrFail();
            $locked->update($attributes);

            // Agent hanya mengunduh config ketika versi berubah.
            $locked->agent()->increment('config_version');

            return $locked->fresh(['agent', 'bindings']);
        }, attempts: 3);
    }
}

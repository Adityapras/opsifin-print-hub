<?php

namespace App\Domain\Sources\Services;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\PrinterBinding;
use Illuminate\Database\Eloquent\Builder;

final class SourcePullGuard
{
    public function shouldDefer(PrinterBinding $binding): bool
    {
        return PrintJob::query()
            ->where('binding_id', $binding->getKey())
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('state', [
                        PrintJobState::Received,
                        PrintJobState::Queued,
                        PrintJobState::Claimed,
                        PrintJobState::Printing,
                    ])
                    ->orWhereHas('outboxes', fn (Builder $outboxQuery): Builder => $outboxQuery
                        ->where('status', 'pending'));
            })
            ->exists();
    }
}

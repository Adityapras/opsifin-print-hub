<?php

namespace App\Domain\Sources\Repositories;

use App\Domain\Sources\Models\PrinterBinding;

final class SourcePullRepository
{
    public function markSuccess(PrinterBinding $binding, int $failedItems): void
    {
        $message = $failedItems > 0 ? "{$failedItems} items failed to process." : null;

        $binding->update([
            'last_pulled_at' => now(),
            'next_pull_at' => now()->addSeconds($binding->sourceConnection->pull_interval_s),
            'last_error' => $message,
        ]);
        $binding->sourceConnection->update([
            'last_ok_at' => now(),
            'last_error' => $message,
        ]);
    }

    public function markFailed(PrinterBinding $binding, string $message): void
    {
        $safeMessage = mb_substr($message, 0, 4000);

        $binding->update([
            'next_pull_at' => now()->addSeconds($binding->sourceConnection->pull_interval_s),
            'last_error' => $safeMessage,
        ]);
        $binding->sourceConnection->update(['last_error' => $safeMessage]);
    }
}

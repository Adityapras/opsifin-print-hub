<?php

namespace App\Domain\Sources\Services;

use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\SourceConnection;
use Illuminate\Database\Eloquent\Builder;

final class AgentSourceIntervalResolver
{
    public function resolve(string $agentId): ?int
    {
        $intervals = SourceConnection::query()
            ->where('is_active', true)
            ->where('identity_status', SourceIdentityStatus::Verified->value)
            ->whereHas('bindings', fn (Builder $bindingQuery): Builder => $bindingQuery
                ->where('is_active', true)
                ->whereHas('printer', fn (Builder $printerQuery): Builder => $printerQuery
                    ->where('agent_id', $agentId)
                    ->where('is_active', true)
                    ->where('is_paused', false)))
            ->pluck('pull_interval_s')
            ->map(fn (mixed $interval): int => (int) $interval)
            ->unique()
            ->values();

        // Satu angka hanya aman ditampilkan bila semua source aktif memakai interval
        // yang sama; null mencegah Agent menyederhanakan jadwal yang sebenarnya berbeda.
        return $intervals->count() === 1 ? $intervals->first() : null;
    }
}

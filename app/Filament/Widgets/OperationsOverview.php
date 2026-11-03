<?php

namespace App\Filament\Widgets;

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

final class OperationsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '10s';

    protected ?string $heading = 'Needs attention';

    protected ?string $description = 'Current tenant operational signals, refreshed every 10 seconds.';

    protected function getStats(): array
    {
        $problemPrinters = Printer::query()
            ->where('is_active', true)
            ->whereNotIn('status', ['ONLINE', 'READY', 'IDLE'])
            ->count();
        $offlineAgents = Agent::query()
            ->where(function ($query): void {
                $query->where('status', 'offline')
                    ->orWhereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(3));
            })
            ->count();
        $failedJobs = PrintJob::query()
            ->where('state', PrintJobState::Failed)
            ->where('received_at', '>=', now()->subDay())
            ->count();
        $pendingCallbacks = CallbackOutbox::query()
            ->where('status', 'pending')
            ->where('next_attempt_at', '<=', now())
            ->count();
        $oldestQueued = PrintJob::query()
            ->where('state', PrintJobState::Queued)
            ->oldest('received_at')
            ->first();

        return [
            Stat::make('Printer issues', $problemPrinters)
                ->description($problemPrinters > 0 ? 'Check printers before the queue grows' : 'All active printers are ready')
                ->descriptionIcon($problemPrinters > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($problemPrinters > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedPrinter),
            Stat::make('Offline agents', $offlineAgents)
                ->description($offlineAgents > 0 ? 'Not seen for more than 3 minutes' : 'All agents are connected')
                ->descriptionIcon($offlineAgents > 0 ? Heroicon::OutlinedSignalSlash : Heroicon::OutlinedSignal)
                ->color($offlineAgents > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedComputerDesktop),
            Stat::make('Failed jobs · 24 hours', $failedJobs)
                ->description($failedJobs > 0 ? 'Requires diagnosis or reprint' : 'No new failures')
                ->descriptionIcon($failedJobs > 0 ? Heroicon::OutlinedExclamationCircle : Heroicon::OutlinedCheckCircle)
                ->color($failedJobs > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedDocumentMagnifyingGlass),
            Stat::make('Due callbacks', $pendingCallbacks)
                ->description($pendingCallbacks > 0 ? 'Waiting for retry to Opsifin' : 'Outbox delivered on time')
                ->descriptionIcon($pendingCallbacks > 0 ? Heroicon::OutlinedClock : Heroicon::OutlinedCheckCircle)
                ->color($pendingCallbacks > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedPaperAirplane),
            Stat::make('Oldest queued job', $oldestQueued?->received_at?->diffForHumans(short: true) ?? '—')
                ->description($oldestQueued === null ? 'Queue is empty' : $oldestQueued->inv_no)
                ->descriptionIcon($oldestQueued === null ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedQueueList)
                ->color($oldestQueued === null ? 'success' : 'warning')
                ->icon(Heroicon::OutlinedQueueList),
        ];
    }
}

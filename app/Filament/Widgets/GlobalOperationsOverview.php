<?php

namespace App\Filament\Widgets;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

final class GlobalOperationsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '15s';

    protected ?string $heading = 'Control plane pulse';

    protected ?string $description = 'Live operational totals across every tenant available to your internal account.';

    protected function getStats(): array
    {
        $tenants = $this->tenantQuery()
            ->withCount([
                'agents',
                'agents as online_agents_count' => fn (Builder $query): Builder => $query
                    ->whereNull('revoked_at')
                    ->where('last_seen_at', '>=', now()->subSeconds(90)),
                'agents as offline_agents_count' => fn (Builder $query): Builder => $query
                    ->whereNull('revoked_at')
                    ->where(fn (Builder $query): Builder => $query
                        ->whereNull('last_seen_at')
                        ->orWhere('last_seen_at', '<', now()->subMinutes(3))),
                'printers as active_printers_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                'printers as problem_printers_count' => fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereNotIn('status', ['ONLINE', 'READY', 'IDLE']),
                'printJobs as failed_jobs_count' => fn (Builder $query): Builder => $query
                    ->where('state', PrintJobState::Failed)
                    ->where('received_at', '>=', now()->subDay()),
                'callbackOutboxes as due_callbacks_count' => fn (Builder $query): Builder => $query
                    ->where('status', 'pending')
                    ->where('next_attempt_at', '<=', now()),
            ])
            ->get();

        $onlineAgents = (int) $tenants->sum('online_agents_count');
        $offlineAgents = (int) $tenants->sum('offline_agents_count');
        $printerProblems = (int) $tenants->sum('problem_printers_count');
        $failedJobs = (int) $tenants->sum('failed_jobs_count');
        $dueCallbacks = (int) $tenants->sum('due_callbacks_count');

        return [
            Stat::make('Active tenants', $tenants->where('status', 'active')->count())
                ->description($tenants->count().' accessible tenants')
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice)
                ->color('primary')
                ->icon(Heroicon::OutlinedBuildingOffice),
            Stat::make('Agents online', $onlineAgents)
                ->description($offlineAgents.' offline for more than 3 minutes')
                ->descriptionIcon($offlineAgents > 0 ? Heroicon::OutlinedSignalSlash : Heroicon::OutlinedSignal)
                ->color($offlineAgents > 0 ? 'warning' : 'success')
                ->icon(Heroicon::OutlinedComputerDesktop),
            Stat::make('Active printers', (int) $tenants->sum('active_printers_count'))
                ->description($printerProblems.' reporting an issue')
                ->descriptionIcon($printerProblems > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($printerProblems > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedPrinter),
            Stat::make('Operational exceptions', $failedJobs + $dueCallbacks)
                ->description($failedJobs.' failed jobs · '.$dueCallbacks.' due callbacks')
                ->descriptionIcon(($failedJobs + $dueCallbacks) > 0 ? Heroicon::OutlinedExclamationCircle : Heroicon::OutlinedCheckCircle)
                ->color(($failedJobs + $dueCallbacks) > 0 ? 'danger' : 'success')
                ->icon(Heroicon::OutlinedQueueList),
        ];
    }

    /** @return Builder<Tenant> */
    private function tenantQuery(): Builder
    {
        $query = Tenant::query();
        $user = Filament::auth()->user();

        if (! $user instanceof User || $user->is_platform_admin) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()));
    }
}

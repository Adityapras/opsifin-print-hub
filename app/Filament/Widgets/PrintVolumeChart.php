<?php

namespace App\Filament\Widgets;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

final class PrintVolumeChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    protected ?string $heading = 'Today\'s print volume';

    protected ?string $description = 'Jobs received per hour in the tenant timezone; failures are shown as a separate layer.';

    protected ?string $maxHeight = '320px';

    protected function getData(): array
    {
        $timezone = $this->tenantTimezone();
        $startLocal = CarbonImmutable::now($timezone)->startOfDay();
        $endLocal = $startLocal->endOfDay();
        $volume = array_fill(0, 24, 0);
        $failed = array_fill(0, 24, 0);

        $jobs = PrintJob::query()
            ->whereBetween('received_at', [$startLocal->utc(), $endLocal->utc()])
            ->get(['received_at', 'state']);

        foreach ($jobs as $job) {
            $hour = $job->received_at->timezone($timezone)->hour;
            $volume[$hour]++;

            if ($job->state === PrintJobState::Failed) {
                $failed[$hour]++;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Received jobs',
                    'data' => $volume,
                    'backgroundColor' => 'rgba(245, 158, 11, 0.72)',
                    'borderColor' => '#d97706',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ],
                [
                    'label' => 'Failed',
                    'data' => $failed,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.78)',
                    'borderColor' => '#dc2626',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ],
            ],
            'labels' => array_map(
                fn (int $hour): string => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                range(0, 23),
            ),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    private function tenantTimezone(): string
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

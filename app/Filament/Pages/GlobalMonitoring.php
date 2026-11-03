<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\TenantHealthTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

final class GlobalMonitoring extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?string $navigationLabel = 'Global Monitoring';

    protected static ?string $title = 'Global Monitoring';

    protected static ?string $slug = 'global-monitoring';

    protected static ?int $navigationSort = 20;

    protected ?string $subheading = 'Cross-tenant operational health. Access remains limited to the tenants assigned to your internal account.';

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            TenantHealthTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int
    {
        return 1;
    }
}

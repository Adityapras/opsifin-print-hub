<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\GlobalOperationsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

final class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Global Dashboard';

    protected static ?string $title = 'Global Dashboard';

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            GlobalOperationsOverview::class,
        ];
    }
}

<?php

namespace App\Filament\Resources\Sites\Pages;

use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSites extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = SiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

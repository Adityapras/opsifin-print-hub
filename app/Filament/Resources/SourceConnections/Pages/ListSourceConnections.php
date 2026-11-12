<?php

namespace App\Filament\Resources\SourceConnections\Pages;

use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSourceConnections extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = SourceConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\InternalUsers\Pages;

use App\Filament\Resources\InternalUsers\InternalUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListInternalUsers extends ListRecords
{
    protected static string $resource = InternalUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New internal user'),
        ];
    }
}

<?php

namespace App\Filament\Resources\AgentReleases\Pages;

use App\Filament\Resources\AgentReleases\AgentReleaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAgentReleases extends ListRecords
{
    protected static string $resource = AgentReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Upload Agent MSI')];
    }
}

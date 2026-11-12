<?php

namespace App\Filament\Resources\AgentReleases\Pages;

use App\Domain\Platform\Services\AgentReleaseService;
use App\Filament\Resources\AgentReleases\AgentReleaseResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateAgentRelease extends CreateRecord
{
    protected static string $resource = AgentReleaseResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Filament::auth()->user();

        return app(AgentReleaseService::class)->create($data, $actor);
    }

    protected function getRedirectUrl(): string
    {
        return AgentReleaseResource::getUrl('view', ['record' => $this->record]);
    }
}

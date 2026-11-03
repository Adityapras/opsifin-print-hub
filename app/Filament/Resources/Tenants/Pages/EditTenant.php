<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditTenant extends EditRecord
{
    protected static string $resource = TenantResource::class;

    protected function getRedirectUrl(): string
    {
        return TenantResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('Archive tenant')
                ->modalDescription('Agent access and source pulling stop immediately. Configuration and history remain available for restore.')
                ->successRedirectUrl(TenantResource::getUrl()),
        ];
    }
}

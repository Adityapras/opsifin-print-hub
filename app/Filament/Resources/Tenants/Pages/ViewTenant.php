<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->record;

        return [
            EditAction::make()->visible(fn (): bool => TenantResource::canManageTenants()),
            DeleteAction::make()
                ->label('Archive tenant')
                ->modalHeading('Archive tenant')
                ->modalDescription('Agent access and source pulling stop immediately. Configuration and history remain available for restore.')
                ->successRedirectUrl(TenantResource::getUrl())
                ->visible(fn (): bool => TenantResource::canManageTenants()),
        ];
    }
}

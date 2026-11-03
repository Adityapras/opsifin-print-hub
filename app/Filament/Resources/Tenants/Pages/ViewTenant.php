<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\PrintJobs\PrintJobResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->record;
        $parameters = ['tenant' => $tenant->code];

        return [
            Action::make('identity')->label('Integration identity')->icon(Heroicon::OutlinedIdentification)->url(TenantResource::getUrl('identity', ['record' => $tenant])),
            Action::make('connections')->label('Connections')->icon(Heroicon::OutlinedCircleStack)->url(SourceConnectionResource::getUrl('index', $parameters)),
            Action::make('sites')->label('Sites')->icon(Heroicon::OutlinedMapPin)->url(SiteResource::getUrl('index', $parameters)),
            Action::make('agents')->label('Agents')->icon(Heroicon::OutlinedComputerDesktop)->url(AgentResource::getUrl('index', $parameters)),
            Action::make('printers')->label('Printers')->icon(Heroicon::OutlinedPrinter)->url(PrinterResource::getUrl('index', $parameters)),
            Action::make('bindings')->label('Bindings')->icon(Heroicon::OutlinedLink)->url(PrinterBindingResource::getUrl('index', $parameters)),
            Action::make('jobs')->label('Print jobs')->icon(Heroicon::OutlinedQueueList)->url(PrintJobResource::getUrl('index', $parameters)),
            Action::make('callbacks')->label('Callbacks')->icon(Heroicon::OutlinedArrowPath)->url(CallbackOutboxResource::getUrl('index', $parameters)),
            Action::make('audit')->label('Audit log')->icon(Heroicon::OutlinedClipboardDocumentList)->url(AuditLogResource::getUrl('index', $parameters)),
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

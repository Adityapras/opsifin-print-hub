<?php

namespace App\Filament\Resources\Agents\Pages;

use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\PairingCodeService;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

final class ListAgents extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = AgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issuePairingCode')
                ->label('Create pairing code')
                ->icon(Heroicon::OutlinedKey)
                ->color('warning')
                ->visible(fn (): bool => AgentResource::canManageFleet())
                ->modalHeading('Install Print Agent')
                ->modalDescription('Select the installation location. The code is shown once and remains valid for 15 minutes.')
                ->schema([
                    Select::make('site_id')
                        ->label('Site')
                        ->options(fn () => Site::query()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, PairingCodeService $pairingCodes): void {
                    $site = Site::query()->findOrFail($data['site_id']);
                    $code = $pairingCodes->issue($site);
                    $tenant = app(TenantContext::class)->tenant();

                    Notification::make()
                        ->title('Pairing code ready')
                        ->body("Client **{$tenant?->name}** · Site **{$site->name}** · Code **{$code}**. Valid for 15 minutes and usable once.")
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }
}

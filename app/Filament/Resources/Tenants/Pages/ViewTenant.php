<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantReinitializer;
use App\Filament\Resources\Tenants\Concerns\HasTenantWorkspaceGuide;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

final class ViewTenant extends ViewRecord
{
    use HasTenantWorkspaceGuide;

    protected static string $resource = TenantResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->record;

        return [
            EditAction::make()->visible(fn (): bool => TenantResource::canManageTenants()),
            Action::make('reinitializeTenant')
                ->label('Reinitialize tenant')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->visible(fn (): bool => TenantResource::canManageTenants())
                ->requiresConfirmation()
                ->modalHeading('Reinitialize tenant')
                ->modalDescription('This permanently clears Source Connections, sites, pairing codes, Agents, printers, bindings, print jobs, callbacks, documents, and tenant Agent deployments. The tenant, Integration ID, internal access, and audit log remain. Remote printer configuration in Opsifin Main is cleaned first. The tenant remains Suspended after completion.')
                ->modalSubmitActionLabel('Reinitialize tenant')
                ->schema([
                    TextInput::make('confirmation')
                        ->label("Type {$tenant->code} to confirm")
                        ->required(),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->helperText('Recorded in the audit log. Minimum 10 characters.')
                        ->minLength(10)
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data, TenantReinitializer $reinitializer) use ($tenant): void {
                    if ($data['confirmation'] !== $tenant->code) {
                        Notification::make()
                            ->title('Confirmation does not match')
                            ->body("Type {$tenant->code} exactly. No data was changed.")
                            ->danger()
                            ->send();

                        return;
                    }

                    $actor = Filament::auth()->user();

                    if (! $actor instanceof User) {
                        abort(403);
                    }

                    try {
                        $counts = $reinitializer->run($tenant, $actor, $data['reason']);

                        Notification::make()
                            ->title('Tenant reinitialized')
                            ->body(array_sum($counts).' operational records were cleared. The tenant is Suspended and ready for setup from step 1.')
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (\Throwable $exception) {
                        Notification::make()
                            ->title('Tenant could not be reinitialized')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            DeleteAction::make()
                ->label('Archive tenant')
                ->modalHeading('Archive tenant')
                ->modalDescription('Agent access and source pulling stop immediately. Configuration and history remain available for restore.')
                ->successRedirectUrl(TenantResource::getUrl())
                ->visible(fn (): bool => TenantResource::canManageTenants()),
        ];
    }
}

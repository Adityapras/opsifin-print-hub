<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Tenants\Concerns\HasTenantWorkspaceGuide;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class ViewTenantIdentity extends ViewRecord
{
    use HasTenantWorkspaceGuide;

    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Integration Identity';

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Hub-issued identity')
                ->description('This UUID permanently identifies the client across Print Hub and its opsifin-main instance.')
                ->icon(Heroicon::OutlinedIdentification)
                ->schema([
                    TextEntry::make('name')->label('Tenant')->weight('bold'),
                    TextEntry::make('code')->label('Tenant code')->badge()->color('gray'),
                    TextEntry::make('integration_id')
                        ->label('Integration ID')
                        ->fontFamily('mono')
                        ->copyable()
                        ->helperText('Identity only—not a password or API credential.')
                        ->columnSpanFull(),
                    TextEntry::make('status')
                        ->label('Tenant status')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'active' ? 'success' : 'warning'),
                    TextEntry::make('connections_total')
                        ->label('Source connections')
                        ->state(fn (Tenant $record): int => $record->sourceConnections()->count()),
                ])
                ->columns(2),
            Section::make('Connection rule')
                ->description('When opsifin-main exposes /identity, the Hub must reject a Source Connection whose identity does not match this value.')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    TextEntry::make('identity_rule')
                        ->label('Required match')
                        ->state('Hub Integration ID = opsifin-main Integration ID')
                        ->badge()
                        ->color('info'),
                ]),
        ]);
    }
}

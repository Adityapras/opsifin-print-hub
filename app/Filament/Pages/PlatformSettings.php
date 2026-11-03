<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

final class PlatformSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Platform Settings';

    protected static ?string $title = 'Platform Settings';

    protected static ?string $slug = 'platform-settings';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Control plane')
                ->description('Read-only platform facts. Sensitive environment values are never exposed here.')
                ->icon(Heroicon::OutlinedServerStack)
                ->schema([
                    TextEntry::make('hub_name')->label('Application')->state('Opsifin Print Hub'),
                    TextEntry::make('access_model')->label('Access model')->state('Internal team only')->badge()->color('success'),
                    TextEntry::make('agent_api')->label('Agent API')->state('/agent/v1')->fontFamily('mono')->copyable(),
                    TextEntry::make('storage_time')->label('Stored time standard')->state('UTC · DATETIME(6)'),
                    TextEntry::make('queue')->label('Queue connection')->state(fn (): string => (string) config('queue.default')),
                    TextEntry::make('environment')->label('Environment')->state(fn (): string => (string) app()->environment())->badge()->color('gray'),
                ])
                ->columns(2),
            Section::make('Safety boundaries')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    TextEntry::make('tenant_rule')->label('Tenant isolation')->state('Application scope + CI architecture tests'),
                    TextEntry::make('delete_rule')->label('Tenant deletion')->state('Archive and restore; no hard delete from the UI'),
                    TextEntry::make('binding_rule')->label('Binding cutover')->state('Enable only after legacy autoprint is stopped'),
                    TextEntry::make('identity_rule')->label('Integration identity')->state('Hub-issued permanent UUIDv7'),
                ])
                ->columns(2),
        ]);
    }
}

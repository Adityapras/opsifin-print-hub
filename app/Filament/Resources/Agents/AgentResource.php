<?php

namespace App\Filament\Resources\Agents;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Services\AgentCommandService;
use App\Domain\Fleet\Services\AgentControlService;
use App\Domain\Fleet\Services\AgentUpdateRolloutService;
use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\Agents\Pages\ListAgents;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use App\Support\DateTimeDisplay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class AgentResource extends Resource
{
    protected static ?string $model = Agent::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $modelLabel = 'agent';

    protected static ?string $pluralModelLabel = 'Agents';

    protected static string|\UnitEnum|null $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'hostname';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Agent identity')
                ->icon(Heroicon::OutlinedComputerDesktop)
                ->schema([
                    TextEntry::make('hostname')->label('Hostname')->weight('bold')->copyable(),
                    TextEntry::make('presence_status')
                        ->label('Status')
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('site.name')->label('Site'),
                    TextEntry::make('machine_id')->label('Machine ID')->copyable(),
                    TextEntry::make('os')->label('Operating system'),
                    TextEntry::make('version')->label('Agent version')->badge()->color('gray'),
                    TextEntry::make('config_version')->label('Config version'),
                    TextEntry::make('uptime_s')
                        ->label('Uptime')
                        ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : self::duration($state)),
                    TextEntry::make('last_seen_at')
                        ->label('Last seen')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('Never'),
                    TextEntry::make('revoked_at')
                        ->label('Revoked at')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('Active'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('hostname')
                    ->label('Agent')
                    ->description(fn (Agent $record): string => $record->os)
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('presence_status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('version')
                    ->label('Version')
                    ->badge()
                    ->color('info'),
                TextColumn::make('printers_count')
                    ->label('Printers')
                    ->counts('printers')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->formatStateUsing(fn (mixed $state): string => DateTimeDisplay::relativeWithClock($state, self::tenantTimezone()))
                    ->placeholder('Never')
                    ->tooltip(fn (Agent $record): ?string => $record->last_seen_at?->timezone(self::tenantTimezone())->format('d M Y, H:i:s'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('presence_status')
                    ->label('Status')
                    ->options([
                        'active' => 'All active',
                        'online' => 'Online',
                        'stale' => 'Stale',
                        'offline' => 'Offline',
                        'revoked' => 'Revoked',
                        'all' => 'All statuses',
                    ])
                    ->default('active')
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'active' => $query->whereNull('revoked_at'),
                            'online' => $query
                                ->whereNull('revoked_at')
                                ->where('last_seen_at', '>', now()->subSeconds(90)),
                            'stale' => $query
                                ->whereNull('revoked_at')
                                ->whereBetween('last_seen_at', [now()->subSeconds(180), now()->subSeconds(90)]),
                            'offline' => $query
                                ->whereNull('revoked_at')
                                ->where(function (Builder $query): void {
                                    $query->whereNull('last_seen_at')
                                        ->orWhere('last_seen_at', '<', now()->subSeconds(180));
                                }),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->poll('10s')
            ->recordActions([
                ActionGroup::make([
                    Action::make('scheduleUpdate')
                        ->label('Schedule update')
                        ->icon(Heroicon::OutlinedCloudArrowUp)
                        ->color('info')
                        ->visible(fn (Agent $record): bool => self::canManageFleet() && $record->revoked_at === null)
                        ->schema([
                            Select::make('release_id')
                                ->label('Target release')
                                ->options(fn (Agent $record): array => AgentRelease::query()
                                    ->where('status', AgentRelease::STATUS_PUBLISHED)
                                    ->orderByDesc('published_at')
                                    ->get()
                                    ->filter(fn (AgentRelease $release): bool => version_compare($record->version, $release->version, '<'))
                                    ->mapWithKeys(fn (AgentRelease $release): array => [$release->getKey() => "{$release->version} · {$release->channel}"])
                                    ->all())
                                ->helperText(fn (Agent $record): string => "Installed version: {$record->version}")
                                ->searchable()
                                ->required(),
                            Select::make('install_policy')
                                ->label('Install policy')
                                ->options([
                                    'automatic' => 'Install automatically when Agent is idle',
                                    'manual' => 'Download only; user installs from Agent UI',
                                ])
                                ->default('automatic')
                                ->native(false)
                                ->required(),
                        ])
                        ->modalHeading(fn (Agent $record): string => "Update {$record->hostname}")
                        ->modalDescription('The Agent verifies SHA-256 and Authenticode before installation. Active print work is never interrupted by the when-idle policy.')
                        ->action(function (Agent $record, array $data, AgentUpdateRolloutService $rollout): void {
                            $release = AgentRelease::query()->findOrFail($data['release_id']);
                            /** @var User $actor */
                            $actor = Filament::auth()->user();
                            $rollout->scheduleOne($release, $record, $actor, $data['install_policy']);

                            Notification::make()
                                ->title('Agent update scheduled')
                                ->body("{$record->hostname} will receive Agent {$release->version} on its next heartbeat.")
                                ->success()
                                ->send();
                        }),
                    Action::make('uploadLogs')
                        ->label('Request logs')
                        ->icon(Heroicon::OutlinedArrowUpTray)
                        ->visible(fn (Agent $record): bool => self::canManageFleet() && $record->revoked_at === null)
                        ->requiresConfirmation()
                        ->modalDescription('The command will be sent on the next Agent heartbeat and request logs from the last 24 hours.')
                        ->action(function (Agent $record, AgentCommandService $commands): void {
                            $commands->dispatch($record, 'upload_logs', ['hours' => 24]);

                            Notification::make()
                                ->title('Log request scheduled')
                                ->body("Agent {$record->hostname} will upload logs on its next heartbeat.")
                                ->success()
                                ->send();
                        }),
                    Action::make('revoke')
                        ->label('Revoke access')
                        ->icon(Heroicon::OutlinedNoSymbol)
                        ->color('danger')
                        ->visible(fn (Agent $record): bool => self::canManageFleet() && $record->revoked_at === null)
                        ->requiresConfirmation()
                        ->modalHeading('Revoke Agent access?')
                        ->modalDescription('The current device token will be rejected immediately. Its printers and operational history will be retained.')
                        ->action(function (Agent $record, AgentControlService $agents): void {
                            $agents->revoke($record);

                            Notification::make()
                                ->title('Agent access revoked')
                                ->body("{$record->hostname} can no longer authenticate to the Hub.")
                                ->success()
                                ->send();
                        }),
                    Action::make('deleteUnused')
                        ->label('Delete permanently')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->visible(fn (Agent $record): bool => self::canManageFleet() && $record->revoked_at !== null)
                        ->disabled(fn (Agent $record): bool => $record->printers()->exists())
                        ->tooltip(fn (Agent $record): ?string => $record->printers()->exists()
                            ? 'Agents with discovered printers are retained to protect bindings and job history.'
                            : null)
                        ->requiresConfirmation()
                        ->modalHeading('Delete this Agent permanently?')
                        ->modalDescription('Only revoked Agents without discovered printers can be deleted. This action cannot be undone.')
                        ->action(function (Agent $record, AgentControlService $agents): void {
                            $deleted = $agents->deleteUnused($record);

                            Notification::make()
                                ->title($deleted ? 'Agent deleted' : 'Agent cannot be deleted')
                                ->body($deleted
                                    ? 'The unused Agent record was removed permanently.'
                                    : 'Revoke the Agent first and ensure it has no discovered printers.')
                                ->color($deleted ? 'success' : 'danger')
                                ->send();
                        }),
                    ViewAction::make()
                        ->label('Details')
                        ->modalHeading(fn (Agent $record): string => "Agent details · {$record->hostname}")
                        ->modalDescription('Review device identity and health without leaving the fleet list.')
                        ->modalWidth(Width::FiveExtraLarge),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No agents yet')
            ->emptyStateDescription('Use Create pairing code to install the first Print Agent.')
            ->emptyStateIcon(Heroicon::OutlinedComputerDesktop);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgents::route('/'),
        ];
    }

    public static function canManageFleet(): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, ['tenant_admin']);
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'online' => 'success',
            'stale' => 'warning',
            'offline', 'revoked' => 'danger',
            default => 'gray',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'online' => 'Online',
            'stale' => 'Stale',
            'offline' => 'Offline',
            'revoked' => 'Revoked',
            default => ucfirst($status),
        };
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }

    private static function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return $days > 0 ? "{$days} days {$hours} hours" : "{$hours} hours";
    }
}

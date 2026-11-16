<?php

namespace App\Filament\Resources\AgentReleases;

use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Domain\Fleet\Services\AgentUpdateRolloutService;
use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Platform\Services\AgentReleaseService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\AgentReleases\Pages\CreateAgentRelease;
use App\Filament\Resources\AgentReleases\Pages\ListAgentReleases;
use App\Filament\Resources\AgentReleases\Pages\ViewAgentRelease;
use App\Models\User;
use App\Support\DateTimeDisplay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class AgentReleaseResource extends Resource
{
    protected static ?string $model = AgentRelease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static ?string $navigationLabel = 'Agent Releases';

    protected static ?string $modelLabel = 'Agent release';

    protected static ?string $pluralModelLabel = 'Agent Releases';

    protected static string|\UnitEnum|null $navigationGroup = 'Platform';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'version';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Agent update package')
                ->description('Upload one MSI. The Hub verifies ProductVersion and calculates SHA-256 automatically.')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->schema([
                    TextInput::make('version')
                        ->label('Version')
                        ->placeholder('0.3.10')
                        ->helperText('Must exactly match ProductVersion embedded in the MSI.')
                        ->regex('/^\d+\.\d+\.\d+$/')
                        ->required()
                        ->unique(ignoreRecord: true),
                    Select::make('install_policy')
                        ->label('Installation policy')
                        ->options([
                            AgentRelease::INSTALL_MANUAL => 'Manual — user selects Install update',
                            AgentRelease::INSTALL_AUTOMATIC => 'Automatic — install when Agent is idle',
                        ])
                        ->default(AgentRelease::INSTALL_MANUAL)
                        ->helperText('Both policies wait for active printing and event delivery to finish.')
                        ->native(false)
                        ->required(),
                    FileUpload::make('path')
                        ->label('Agent MSI')
                        ->disk('local')
                        ->directory('agent-releases/packages')
                        ->visibility('private')
                        ->getUploadedFileNameForStorageUsing(fn (): string => Str::uuid().'.msi')
                        ->acceptedFileTypes([
                            'application/x-msi',
                            'application/x-ole-storage',
                            'application/octet-stream',
                        ])
                        ->maxSize((int) ceil(((int) config('print_hub.agent_updates.max_package_bytes')) / 1024))
                        ->helperText('Maximum package size: 512 MB. Keep this page open until the upload finishes.')
                        ->storeFileNamesIn('original_name')
                        ->required()
                        ->columnSpanFull(),
                    Textarea::make('release_notes')
                        ->label('Release notes')
                        ->rows(5)
                        ->maxLength(10000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Release integrity')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->schema([
                    TextEntry::make('version')->badge()->color('info'),
                    TextEntry::make('status')->badge()->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('install_policy')
                        ->label('Installation')
                        ->formatStateUsing(fn (string $state): string => self::installPolicyLabel($state))
                        ->badge()
                        ->color(fn (string $state): string => $state === AgentRelease::INSTALL_AUTOMATIC ? 'warning' : 'info'),
                    TextEntry::make('original_name')->label('Package'),
                    TextEntry::make('size_bytes')->label('Size')->formatStateUsing(fn (int $state): string => number_format($state / 1048576, 2).' MB'),
                    TextEntry::make('sha256')->label('SHA-256')->fontFamily('mono')->copyable()->columnSpanFull(),
                    TextEntry::make('release_notes')->label('Release notes')->placeholder('No release notes')->columnSpanFull(),
                ])
                ->columns(3),
            Section::make('Rollout status across all tenants')
                ->description('Installed is confirmed only after the Agent heartbeat reports the target version.')
                ->schema([
                    TextEntry::make('pending_total')->label('Pending')->state(fn (AgentRelease $record): int => self::deploymentQuery($record)->whereIn('status', ['pending', 'delivered', 'accepted', 'downloading', 'ready', 'waiting_for_idle', 'installing'])->count())->badge()->color('warning'),
                    TextEntry::make('installed_total')->label('Succeeded')->state(fn (AgentRelease $record): int => self::deploymentQuery($record)->where('status', 'installed')->count())->badge()->color('success'),
                    TextEntry::make('failed_total')->label('Failed / stalled')->state(fn (AgentRelease $record): int => self::deploymentQuery($record)->whereIn('status', ['failed', 'stalled'])->count())->badge()->color('danger'),
                ])
                ->columns(3),
            Section::make('Agent deployment lifecycle')
                ->description('Succeeded is shown only after a heartbeat confirms the target Agent version.')
                ->schema([
                    RepeatableEntry::make('deployment_statuses')
                        ->label('')
                        ->state(fn (AgentRelease $record): array => self::deploymentEntries($record))
                        ->schema([
                            TextEntry::make('tenant')->weight('semibold'),
                            TextEntry::make('agent')->label('Agent'),
                            TextEntry::make('install_policy')->label('Policy')->badge()->color('gray'),
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (string $state): string => self::deploymentStatusColor($state))
                                ->formatStateUsing(fn (string $state): string => self::deploymentStatusLabel($state)),
                            TextEntry::make('progress')->label('Progress'),
                            TextEntry::make('observed_at')->label('Last update'),
                            TextEntry::make('message')->placeholder('No status message')->columnSpanFull(),
                        ])
                        ->columns(5),
                ]),
            Section::make('Audit trail')
                ->schema([
                    RepeatableEntry::make('events')
                        ->label('')
                        ->schema([
                            TextEntry::make('type')->label('Event')->badge()->color('gray'),
                            TextEntry::make('actor.name')->label('Operator')->placeholder('System'),
                            TextEntry::make('occurred_at')->label('Time')->dateTime('d M Y, H:i:s'),
                        ])
                        ->columns(3),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')->label('Version')->badge()->color('info')->searchable()->sortable(),
                TextColumn::make('install_policy')
                    ->label('Installation')
                    ->formatStateUsing(fn (string $state): string => self::installPolicyLabel($state))
                    ->badge()
                    ->color(fn (string $state): string => $state === AgentRelease::INSTALL_AUTOMATIC ? 'warning' : 'info'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('deployments_total')
                    ->label('Scheduled')
                    ->state(fn (AgentRelease $record): int => self::deploymentQuery($record)->count())
                    ->badge()
                    ->color('gray'),
                TextColumn::make('published_at')
                    ->label('Published')
                    ->formatStateUsing(fn (mixed $state): string => DateTimeDisplay::relativeWithClock($state, config('app.timezone')))
                    ->placeholder('Not published')
                    ->sortable(),
                TextColumn::make('created_at')->label('Uploaded')->dateTime('d M Y, H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('15s')
            ->recordActions([
                ActionGroup::make([
                    Action::make('publish')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->visible(fn (AgentRelease $record): bool => $record->status === AgentRelease::STATUS_DRAFT)
                        ->requiresConfirmation()
                        ->modalDescription('Publishing makes this MSI available for rollout. Its SHA-256 and installation policy remain immutable.')
                        ->action(function (AgentRelease $record, AgentReleaseService $service): void {
                            $service->publish($record, self::actor());
                            Notification::make()->title("Agent {$record->version} published")->success()->send();
                        }),
                    Action::make('pilot')
                        ->label('Pilot 10%')
                        ->icon(Heroicon::OutlinedRocketLaunch)
                        ->color('warning')
                        ->visible(fn (AgentRelease $record): bool => $record->status === AgentRelease::STATUS_PUBLISHED)
                        ->schema([self::tenantSelect()])
                        ->requiresConfirmation()
                        ->modalDescription('Select a client. The Hub schedules 10% of its eligible Agents, with a minimum of one Agent.')
                        ->action(function (AgentRelease $record, array $data, AgentUpdateRolloutService $rollout): void {
                            $count = self::withinTenant(
                                $data['tenant_id'],
                                fn (): int => $rollout->schedulePilot($record, self::actor())->count(),
                            );
                            Notification::make()->title('Pilot rollout scheduled')->body("{$count} eligible Agent(s) queued.")->success()->send();
                        }),
                    Action::make('rollout')
                        ->label('Roll out remaining')
                        ->icon(Heroicon::OutlinedCloudArrowUp)
                        ->visible(fn (AgentRelease $record): bool => $record->status === AgentRelease::STATUS_PUBLISHED)
                        ->schema([self::tenantSelect()])
                        ->requiresConfirmation()
                        ->modalDescription('Select a client. Every remaining eligible Agent is scheduled and active print work is allowed to finish before installation.')
                        ->action(function (AgentRelease $record, array $data, AgentUpdateRolloutService $rollout): void {
                            $count = self::withinTenant(
                                $data['tenant_id'],
                                fn (): int => $rollout->scheduleRemaining($record, self::actor())->count(),
                            );
                            Notification::make()->title('Remaining rollout scheduled')->body("{$count} eligible Agent(s) queued.")->success()->send();
                        }),
                    Action::make('retire')
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->color('danger')
                        ->visible(fn (AgentRelease $record): bool => $record->status === AgentRelease::STATUS_PUBLISHED)
                        ->requiresConfirmation()
                        ->modalDescription('Retiring prevents new downloads and rollout. Existing deployment history is retained.')
                        ->action(function (AgentRelease $record, AgentReleaseService $service): void {
                            $service->retire($record, self::actor());
                            Notification::make()->title("Agent {$record->version} retired")->success()->send();
                        }),
                    ViewAction::make(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No Agent releases')
            ->emptyStateDescription('Upload one MSI to begin a controlled rollout.')
            ->emptyStateIcon(Heroicon::OutlinedCloudArrowUp);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentReleases::route('/'),
            'create' => CreateAgentRelease::route('/create'),
            'view' => ViewAgentRelease::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        return self::isPlatformAdmin();
    }

    public static function canViewAny(): bool
    {
        return self::isPlatformAdmin();
    }

    public static function canCreate(): bool
    {
        return self::isPlatformAdmin();
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function actor(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    private static function isPlatformAdmin(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            AgentRelease::STATUS_PUBLISHED => 'success',
            AgentRelease::STATUS_RETIRED => 'danger',
            default => 'warning',
        };
    }

    private static function installPolicyLabel(string $policy): string
    {
        return $policy === AgentRelease::INSTALL_AUTOMATIC ? 'Automatic' : 'Manual';
    }

    /** @return array<int, array<string, string>> */
    private static function deploymentEntries(AgentRelease $release): array
    {
        return AgentUpdateDeployment::query()
            ->withoutGlobalScopes()
            ->leftJoin('tenants', 'tenants.id', '=', 'agent_update_deployments.tenant_id')
            ->leftJoin('agents', 'agents.id', '=', 'agent_update_deployments.agent_id')
            ->select([
                'agent_update_deployments.*',
                'tenants.name as tenant_name',
                'agents.hostname as agent_hostname',
            ])
            ->where('agent_release_id', $release->getKey())
            ->latest('scheduled_at')
            ->get()
            ->map(fn (AgentUpdateDeployment $deployment): array => [
                'tenant' => (string) ($deployment->getAttribute('tenant_name') ?? 'Unknown tenant'),
                'agent' => (string) ($deployment->getAttribute('agent_hostname') ?? 'Unknown Agent'),
                'install_policy' => self::installPolicyLabel($deployment->install_policy),
                'status' => $deployment->status,
                'progress' => "{$deployment->progress_percent}%",
                'observed_at' => DateTimeDisplay::relativeWithClock($deployment->last_observed_at, config('app.timezone')),
                'message' => $deployment->message ?? '',
            ])
            ->all();
    }

    private static function deploymentStatusLabel(string $status): string
    {
        return $status === 'installed'
            ? 'Succeeded'
            : str($status)->replace('_', ' ')->title()->toString();
    }

    private static function deploymentStatusColor(string $status): string
    {
        return match ($status) {
            'installed' => 'success',
            'failed', 'stalled' => 'danger',
            'ready', 'downloading', 'installing' => 'info',
            default => 'warning',
        };
    }

    private static function tenantSelect(): Select
    {
        return Select::make('tenant_id')
            ->label('Client')
            ->options(fn (): array => Tenant::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->required();
    }

    /** @return Builder<AgentUpdateDeployment> */
    private static function deploymentQuery(AgentRelease $release): Builder
    {
        return AgentUpdateDeployment::query()
            ->withoutGlobalScopes()
            ->where('agent_release_id', $release->getKey());
    }

    private static function withinTenant(string $tenantId, callable $callback): mixed
    {
        $tenant = Tenant::query()->where('status', 'active')->findOrFail($tenantId);
        $context = app(TenantContext::class);
        $context->set($tenant->getKey());

        try {
            return $callback();
        } finally {
            // Resource ini global; konteks manual tidak boleh bocor ke aksi Livewire berikutnya.
            $context->clear();
        }
    }
}

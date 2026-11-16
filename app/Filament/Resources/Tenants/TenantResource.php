<?php

namespace App\Filament\Resources\Tenants;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\PrintJobs\PrintJobResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Resources\Tenants\Pages\EditTenant;
use App\Filament\Resources\Tenants\Pages\ListTenants;
use App\Filament\Resources\Tenants\Pages\TenantSetupGuide;
use App\Filament\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Resources\Tenants\Pages\ViewTenantIdentity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = 'Tenants';

    protected static ?string $modelLabel = 'tenant';

    protected static ?string $pluralModelLabel = 'Tenants';

    protected static ?string $slug = 'tenants';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $recordRouteKeyName = 'code';

    protected static ?int $navigationSort = 10;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::End;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Client identity')
                ->description('The Hub creates the permanent integration identity automatically.')
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->schema([
                    TextInput::make('name')
                        ->label('Client name')
                        ->placeholder('Bravo')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, callable $set, string $operation): void {
                            if ($operation === 'create') {
                                $set('code', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('code')
                        ->label('Tenant code')
                        ->helperText('Used in internal URLs and cannot be changed after creation.')
                        ->alphaDash()
                        ->required()
                        ->maxLength(50)
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->dehydrated(),
                    TextInput::make('timezone')
                        ->label('Timezone')
                        ->default('Asia/Jakarta')
                        ->required()
                        ->maxLength(64),
                    Select::make('status')
                        ->label('Status')
                        ->options([
                            'active' => 'Active',
                            'suspended' => 'Suspended',
                        ])
                        ->default('active')
                        ->native(false)
                        ->required(),
                ])
                ->columns(2),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tenant overview')
                ->description('All connections, sites, Agents, printers, and jobs below are isolated to this tenant.')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    TextEntry::make('name')->label('Client')->weight('bold'),
                    TextEntry::make('code')->label('Tenant code')->badge()->color('gray'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => ucfirst($state))
                        ->color(fn (string $state): string => $state === 'active' ? 'success' : 'danger'),
                    TextEntry::make('timezone')->label('Timezone'),
                    TextEntry::make('integration_id')
                        ->label('Integration ID')
                        ->helperText('Copy this value into the matching opsifin-main instance. It is an identity, not a password.')
                        ->fontFamily('mono')
                        ->copyable()
                        ->columnSpan(2),
                ])
                ->columns(2),
            Section::make('Managed estate')
                ->icon(Heroicon::OutlinedChartBar)
                ->schema([
                    TextEntry::make('sites_total')->label('Sites')->state(fn (Tenant $record): int => $record->sites()->count()),
                    TextEntry::make('agents_total')->label('Agents')->state(fn (Tenant $record): int => $record->agents()->count()),
                    TextEntry::make('printers_total')->label('Printers')->state(fn (Tenant $record): int => $record->printers()->count()),
                    TextEntry::make('connections_total')->label('Connections')->state(fn (Tenant $record): int => $record->sourceConnections()->count()),
                    TextEntry::make('bindings_total')->label('Bindings')->state(fn (Tenant $record): int => $record->printerBindings()->count()),
                    TextEntry::make('jobs_total')->label('Print jobs')->state(fn (Tenant $record): int => $record->printJobs()->count()),
                ])
                ->columns(3),
            Section::make('Estate topology')
                ->description('Site → Agent PC → Windows printer. This hierarchy is the routing boundary used by the Hub.')
                ->icon(Heroicon::OutlinedShare)
                ->schema([
                    RepeatableEntry::make('sites')
                        ->label('')
                        ->schema([
                            TextEntry::make('name')
                                ->label('Site')
                                ->weight('bold')
                                ->icon(Heroicon::OutlinedMapPin),
                            TextEntry::make('agents_count')
                                ->label('Agent PCs')
                                ->state(fn ($record): int => $record->agents()->count())
                                ->badge()
                                ->color('gray'),
                            RepeatableEntry::make('agents')
                                ->label('Agent PCs')
                                ->schema([
                                    TextEntry::make('hostname')
                                        ->label('Computer')
                                        ->weight('semibold')
                                        ->icon(Heroicon::OutlinedComputerDesktop),
                                    TextEntry::make('presence_status')
                                        ->label('Status')
                                        ->badge()
                                        ->color(fn (string $state): string => match ($state) {
                                            'online' => 'success',
                                            'stale' => 'warning',
                                            'revoked' => 'danger',
                                            default => 'gray',
                                        }),
                                    RepeatableEntry::make('printers')
                                        ->label('Printers')
                                        ->schema([
                                            TextEntry::make('display_name')
                                                ->label('Printer')
                                                ->state(fn ($record): string => $record->alias ?: $record->windows_name)
                                                ->icon(Heroicon::OutlinedPrinter),
                                            TextEntry::make('effective_status')
                                                ->label('Availability')
                                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                                    'AGENT_STALE' => 'Agent stale',
                                                    'AGENT_OFFLINE' => 'Agent offline',
                                                    'AGENT_REVOKED' => 'Agent revoked',
                                                    default => str($state)->replace('_', ' ')->title()->toString(),
                                                })
                                                ->badge()
                                                ->color(fn (?string $state): string => match (true) {
                                                    in_array($state, ['ONLINE', 'READY', 'IDLE'], true) => 'success',
                                                    $state === 'AGENT_STALE' => 'warning',
                                                    in_array($state, ['AGENT_OFFLINE', 'AGENT_REVOKED', 'OFFLINE', 'ERROR', 'PAPER_OUT'], true) => 'danger',
                                                    default => 'gray',
                                                }),
                                        ])
                                        ->columns(2)
                                        ->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Client')
                    ->description(fn (Tenant $record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->weight('semibold'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Tenant $record): string => $record->trashed() ? 'archived' : $record->status)
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('sites_count')->label('Sites')->counts('sites')->badge()->color('gray'),
                TextColumn::make('agents_count')->label('Agents')->counts('agents')->badge()->color('gray'),
                TextColumn::make('printers_count')->label('Printers')->counts('printers')->badge()->color('gray'),
                TextColumn::make('source_connections_count')->label('Connections')->counts('sourceConnections')->badge()->color('info'),
                TextColumn::make('integration_id')
                    ->label('Integration ID')
                    ->formatStateUsing(fn (string $state): string => Str::limit($state, 18))
                    ->copyable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'suspended' => 'Suspended',
                    ]),
                TrashedFilter::make()->label('Archived tenants'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()->label('Open tenant'),
                    EditAction::make()->visible(fn (): bool => self::canManageTenants()),
                    DeleteAction::make()
                        ->label('Archive tenant')
                        ->modalHeading('Archive tenant')
                        ->modalDescription('Agent access and source pulling stop immediately. All configuration, print history, and audit data remain available for restore.')
                        ->successNotificationTitle('Tenant archived')
                        ->visible(fn (): bool => self::canManageTenants()),
                    RestoreAction::make()
                        ->label('Restore tenant')
                        ->modalDescription('The tenant will be restored as Suspended. Review its connections and bindings before setting it back to Active.')
                        ->successNotificationTitle('Tenant restored')
                        ->visible(fn (): bool => self::canManageTenants()),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No tenants yet')
            ->emptyStateDescription('Create the first client before configuring connections, sites, and Print Agents.')
            ->emptyStateIcon(Heroicon::OutlinedBuildingOffice);
    }

    /** @return Builder<Tenant> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Tenant> $query */
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user instanceof User || $user->is_platform_admin) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()));
    }

    public static function canCreate(): bool
    {
        return self::canManageTenants();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageTenants();
    }

    public static function canDelete(Model $record): bool
    {
        return self::canManageTenants();
    }

    public static function canRestore(Model $record): bool
    {
        return self::canManageTenants();
    }

    public static function canForceDelete(Model $record): bool
    {
        // Histori print dan audit harus tetap dapat ditelusuri setelah client berhenti.
        return false;
    }

    /** @return array<NavigationItem> */
    public static function getRecordSubNavigation(Page $page): array
    {
        if (! method_exists($page, 'getRecord')) {
            return [];
        }

        $tenant = $page->getRecord();

        if (! $tenant instanceof Tenant) {
            return [];
        }

        return self::getTenantSubNavigation($tenant);
    }

    /** @return array<NavigationItem> */
    public static function getTenantSubNavigation(Tenant $tenant): array
    {

        $parameters = ['tenant' => $tenant->code];

        return [
            NavigationItem::make('Overview')
                ->key('overview')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->url(self::getUrl('view', ['record' => $tenant]))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.view')),
            NavigationItem::make('Setup guide')
                ->key('setup-guide')
                ->icon(Heroicon::OutlinedMap)
                ->url(self::getUrl('setup', ['record' => $tenant]))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.setup')),
            NavigationItem::make('Integration identity')
                ->key('integration-identity')
                ->icon(Heroicon::OutlinedIdentification)
                ->url(self::getUrl('identity', ['record' => $tenant]))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.identity')),
            NavigationItem::make('Connections')
                ->key('connections')
                ->icon(Heroicon::OutlinedCircleStack)
                ->url(SourceConnectionResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.source-connections.*')),
            NavigationItem::make('Sites')
                ->key('sites')
                ->icon(Heroicon::OutlinedMapPin)
                ->url(SiteResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.sites.*')),
            NavigationItem::make('Agents')
                ->key('agents')
                ->icon(Heroicon::OutlinedComputerDesktop)
                ->url(AgentResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.agents.*')),
            NavigationItem::make('Printers')
                ->key('printers')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(PrinterResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.printers.*')),
            NavigationItem::make('Bindings')
                ->key('bindings')
                ->icon(Heroicon::OutlinedLink)
                ->url(PrinterBindingResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.printer-bindings.*')),
            NavigationItem::make('Print jobs')
                ->key('print-jobs')
                ->icon(Heroicon::OutlinedQueueList)
                ->url(PrintJobResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.print-jobs.*')),
            NavigationItem::make('Callbacks')
                ->key('callbacks')
                ->icon(Heroicon::OutlinedArrowPath)
                ->url(CallbackOutboxResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.callback-outboxes.*')),
            NavigationItem::make('Audit log')
                ->key('audit-log')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(AuditLogResource::getUrl('index', $parameters))
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.resources.tenants.audit-logs.*')),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
            'setup' => TenantSetupGuide::route('/{record}/setup'),
            'identity' => ViewTenantIdentity::route('/{record}/identity'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function canManageTenants(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }
}

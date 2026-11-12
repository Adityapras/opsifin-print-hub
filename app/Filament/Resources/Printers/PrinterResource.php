<?php

namespace App\Filament\Resources\Printers;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Services\PrinterControlService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\Printers\Pages\EditPrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use App\Support\DateTimeDisplay;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PrinterResource extends Resource
{
    protected static ?string $model = Printer::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $navigationLabel = 'Printers';

    protected static ?string $modelLabel = 'printer';

    protected static ?string $pluralModelLabel = 'Printers';

    protected static string|\UnitEnum|null $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'windows_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Windows printer')
                ->description('Printer identity comes from the Agent heartbeat and cannot be changed from the Hub.')
                ->icon(Heroicon::OutlinedPrinter)
                ->schema([
                    TextInput::make('windows_name')
                        ->label('Windows name')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('agent.hostname')
                        ->label('Agent')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('alias')
                        ->label('Operational alias')
                        ->placeholder('Front cashier printer')
                        ->maxLength(255),
                    TextInput::make('driver')
                        ->label('Driver')
                        ->disabled()
                        ->dehydrated(false),
                    Toggle::make('is_active')
                        ->label('Active printer')
                        ->helperText('Only active printers can receive jobs from the Hub.'),
                    Toggle::make('is_paused')
                        ->label('Pause queue')
                        ->helperText('Jobs remain queued but cannot be claimed by the Agent.'),
                ])
                ->columns(2),
            Section::make('Print settings')
                ->description('Changes are sent to the Agent through config_version on the next heartbeat.')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->schema([
                    Select::make('print_settings.engine')
                        ->label('Print engine')
                        ->options([
                            'pdfium' => 'PDFium',
                            'sumatra' => 'SumatraPDF',
                        ])
                        ->default('pdfium')
                        ->native(false),
                    TextInput::make('print_settings.copies')
                        ->label('Copies')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(1),
                    Toggle::make('print_settings.duplex')
                        ->label('Duplex')
                        ->default(false),
                    TextInput::make('print_settings.tray')
                        ->label('Tray')
                        ->placeholder('Default printer'),
                    Select::make('print_settings.scale')
                        ->label('Scale')
                        ->options([
                            'noscale' => 'Original size',
                            'fit' => 'Fit to page',
                        ])
                        ->default('noscale')
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('windows_name')
                    ->label('Printer')
                    ->description(fn (Printer $record): string => $record->alias ?: ($record->driver ?: 'No alias'))
                    ->searchable(['windows_name', 'alias'])
                    ->weight('semibold'),
                TextColumn::make('agent.hostname')
                    ->label('Agent')
                    ->description(fn (Printer $record): string => $record->agent->site->name)
                    ->searchable(),
                TextColumn::make('effective_status')
                    ->label('Availability')
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->description(fn (Printer $record): string => self::lastReportDescription($record))
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('queue_length')
                    ->label('Queue')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                IconColumn::make('is_paused')
                    ->label('Paused')
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('gray'),
                TextColumn::make('bindings_count')
                    ->label('Binding')
                    ->counts('bindings')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->formatStateUsing(fn (mixed $state): string => DateTimeDisplay::relativeWithClock($state, self::tenantTimezone()))
                    ->placeholder('Never')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('agent_id')
                    ->label('Agent')
                    ->relationship('agent', 'hostname')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('effective_status')
                    ->label('Availability')
                    ->options([
                        'READY' => 'Ready',
                        'ONLINE' => 'Online',
                        'OFFLINE' => 'Offline',
                        'PAPER_OUT' => 'Paper out',
                        'ERROR' => 'Error',
                        'AGENT_STALE' => 'Agent stale',
                        'AGENT_OFFLINE' => 'Agent offline',
                        'AGENT_REVOKED' => 'Agent revoked',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::applyAvailabilityFilter(
                        $query,
                        isset($data['value']) ? (string) $data['value'] : null,
                    )),
                TernaryFilter::make('is_active')->label('Active printer'),
                TernaryFilter::make('is_paused')->label('Paused queue'),
            ])
            ->defaultSort('windows_name')
            ->poll('10s')
            ->recordActions([
                ActionGroup::make([
                    Action::make('togglePause')
                        ->label(fn (Printer $record): string => $record->is_paused ? 'Resume' : 'Pause')
                        ->icon(fn (Printer $record): Heroicon => $record->is_paused ? Heroicon::OutlinedPlay : Heroicon::OutlinedPause)
                        ->color(fn (Printer $record): string => $record->is_paused ? 'success' : 'warning')
                        ->visible(fn (): bool => self::canOperateFleet())
                        ->requiresConfirmation()
                        ->action(function (Printer $record, PrinterControlService $printers): void {
                            $updated = $printers->setPaused($record, ! $record->is_paused);

                            Notification::make()
                                ->title($updated->is_paused ? 'Printer queue paused' : 'Printer queue resumed')
                                ->success()
                                ->send();
                        }),
                    EditAction::make(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No printers detected')
            ->emptyStateDescription('Printers appear automatically after the Agent sends a heartbeat.')
            ->emptyStateIcon(Heroicon::OutlinedPrinter);
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageFleet();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrinters::route('/'),
            'edit' => EditPrinter::route('/{record}/edit'),
        ];
    }

    private static function canManageFleet(): bool
    {
        return self::hasRole(['tenant_admin']);
    }

    private static function canOperateFleet(): bool
    {
        return self::hasRole(['tenant_admin', 'operator']);
    }

    /** @param array<int, string> $roles */
    private static function hasRole(array $roles): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, $roles);
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'READY', 'ONLINE', 'IDLE' => 'success',
            'AGENT_STALE', 'PAUSED' => 'warning',
            'PAPER_OUT', 'OFFLINE', 'ERROR', 'AGENT_OFFLINE', 'AGENT_REVOKED' => 'danger',
            default => 'gray',
        };
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'AGENT_STALE' => 'Agent stale',
            'AGENT_OFFLINE' => 'Agent offline',
            'AGENT_REVOKED' => 'Agent revoked',
            'PAPER_OUT' => 'Paper out',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    private static function lastReportDescription(Printer $printer): string
    {
        $reportedAt = $printer->last_seen_at === null
            ? 'never'
            : DateTimeDisplay::relativeWithClock($printer->last_seen_at, self::tenantTimezone());

        return 'Last report: '.self::statusLabel($printer->status).' · '.$reportedAt;
    }

    /**
     * @param  Builder<Printer>  $query
     * @return Builder<Printer>
     */
    private static function applyAvailabilityFilter(Builder $query, ?string $status): Builder
    {
        if ($status === null || $status === '') {
            return $query;
        }

        if (str_starts_with($status, 'AGENT_')) {
            return $query->whereHas('agent', function (Builder $query) use ($status): void {
                match ($status) {
                    'AGENT_REVOKED' => $query->whereNotNull('revoked_at'),
                    'AGENT_STALE' => $query
                        ->whereNull('revoked_at')
                        ->whereBetween('last_seen_at', [
                            now()->subSeconds(Agent::OFFLINE_THRESHOLD_SECONDS),
                            now()->subSeconds(Agent::ONLINE_THRESHOLD_SECONDS),
                        ]),
                    default => $query
                        ->whereNull('revoked_at')
                        ->where(fn (Builder $query) => $query
                            ->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', now()->subSeconds(Agent::OFFLINE_THRESHOLD_SECONDS))),
                };
            });
        }

        return $query
            ->where('status', $status)
            ->whereHas('agent', fn (Builder $query): Builder => $query
                ->whereNull('revoked_at')
                ->where('last_seen_at', '>', now()->subSeconds(Agent::ONLINE_THRESHOLD_SECONDS)));
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

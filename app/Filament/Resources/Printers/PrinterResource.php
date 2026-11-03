<?php

namespace App\Filament\Resources\Printers;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Services\PrinterControlService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\Printers\Pages\EditPrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
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
                TextColumn::make('status')
                    ->label('Status')
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
                    ->since(fn (): string => self::tenantTimezone())
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
                SelectFilter::make('status')
                    ->options([
                        'READY' => 'Ready',
                        'ONLINE' => 'Online',
                        'OFFLINE' => 'Offline',
                        'PAPER_OUT' => 'Paper out',
                        'ERROR' => 'Error',
                    ]),
                TernaryFilter::make('is_active')->label('Active printer'),
                TernaryFilter::make('is_paused')->label('Paused queue'),
            ])
            ->defaultSort('windows_name')
            ->poll('10s')
            ->recordActions([
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
            'PAPER_OUT', 'OFFLINE', 'ERROR' => 'danger',
            'PAUSED' => 'warning',
            default => 'gray',
        };
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

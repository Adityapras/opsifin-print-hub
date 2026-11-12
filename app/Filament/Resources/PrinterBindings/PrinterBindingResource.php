<?php

namespace App\Filament\Resources\PrinterBindings;

use App\Domain\Sources\Exceptions\PrinterBindingSyncException;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Services\PrinterBindingService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\PrinterBindings\Pages\CreatePrinterBinding;
use App\Filament\Resources\PrinterBindings\Pages\EditPrinterBinding;
use App\Filament\Resources\PrinterBindings\Pages\ListPrinterBindings;
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
use Illuminate\Database\Eloquent\Model;

final class PrinterBindingResource extends Resource
{
    protected static ?string $model = PrinterBinding::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'Printer Bindings';

    protected static ?string $modelLabel = 'printer binding';

    protected static ?string $pluralModelLabel = 'Printer Bindings';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'source_printer_name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Printer routing')
                ->description('Source values must match the legacy autoprint configuration during cutover.')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->schema([
                    Select::make('source_connection_id')
                        ->label('Opsifin connection')
                        ->relationship('sourceConnection', 'name')
                        ->searchable()
                        ->preload()
                        ->disabledOn('edit')
                        ->helperText('The connection cannot be moved after creation because the legacy API cannot remove the old remote record.')
                        ->required(),
                    Select::make('printer_id')
                        ->label('Destination printer')
                        ->relationship('printer', 'windows_name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('source_printer_name')
                        ->label('Source PrinterName')
                        ->placeholder('Cashier 01')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('source_identifier')
                        ->label('Source identifier')
                        ->placeholder('CAB-JKT-01')
                        ->required()
                        ->maxLength(255),
                    Select::make('printer_version')
                        ->label('Printer version')
                        ->options([
                            1 => 'Version 1',
                            2 => 'Version 2',
                            3 => 'Version 3',
                        ])
                        ->native(false)
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Active binding')
                        ->helperText('Enable only after stopping the legacy autoprint to prevent duplicate printing.')
                        ->default(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source_printer_name')
                    ->label('Source name')
                    ->description(fn (PrinterBinding $record): string => $record->source_identifier)
                    ->searchable(['source_printer_name', 'source_identifier'])
                    ->weight('semibold'),
                TextColumn::make('printer.windows_name')
                    ->label('Printer Windows')
                    ->description(fn (PrinterBinding $record): ?string => $record->printer->alias)
                    ->searchable(),
                TextColumn::make('sourceConnection.name')
                    ->label('Connection')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('printer_version')
                    ->label('Version')
                    ->formatStateUsing(fn (int $state): string => "v{$state}"),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_pulled_at')
                    ->label('Last pull')
                    ->formatStateUsing(fn (mixed $state): string => DateTimeDisplay::relativeWithClock($state, self::tenantTimezone()))
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label('Issue')
                    ->placeholder('Normal')
                    ->limit(36)
                    ->color(fn (?string $state): string => filled($state) ? 'danger' : 'success')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('source_connection_id')
                    ->label('Connection')
                    ->relationship('sourceConnection', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Binding status')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->defaultSort('source_printer_name')
            ->recordActions([
                ActionGroup::make([
                    Action::make('syncToOpsifin')
                        ->label('Sync to Opsifin')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->visible(fn (): bool => self::canManageConfiguration())
                        ->requiresConfirmation()
                        ->modalDescription('Send this binding to the legacy printerName endpoint now. Use this for bindings created before automatic synchronization was enabled.')
                        ->action(function (PrinterBinding $record, PrinterBindingService $bindings): void {
                            try {
                                $bindings->syncExisting($record);

                                Notification::make()
                                    ->title('Printer binding synchronized')
                                    ->body("{$record->source_printer_name} is registered in Opsifin.")
                                    ->success()
                                    ->send();
                            } catch (PrinterBindingSyncException $exception) {
                                Notification::make()
                                    ->title('Printer binding sync failed')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }),
                    EditAction::make(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No printer bindings yet')
            ->emptyStateDescription('The Hub will not pull jobs until a printer is connected to a source.')
            ->emptyStateIcon(Heroicon::OutlinedLink);
    }

    public static function canCreate(): bool
    {
        return self::canManageConfiguration();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageConfiguration();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrinterBindings::route('/'),
            'create' => CreatePrinterBinding::route('/create'),
            'edit' => EditPrinterBinding::route('/{record}/edit'),
        ];
    }

    private static function canManageConfiguration(): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, ['tenant_admin']);
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

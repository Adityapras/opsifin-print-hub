<?php

namespace App\Filament\Resources\PrintJobs;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Filament\Resources\PrintJobs\Pages\ViewPrintJob;
use App\Filament\Resources\Tenants\TenantResource;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class PrintJobResource extends Resource
{
    protected static ?string $model = PrintJob::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?string $navigationLabel = 'Print Jobs';

    protected static ?string $modelLabel = 'print job';

    protected static ?string $pluralModelLabel = 'Print Jobs';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'inv_no';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Job summary')
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    TextEntry::make('inv_no')->label('Document number')->weight('bold')->copyable(),
                    TextEntry::make('state')
                        ->label('Status')
                        ->formatStateUsing(fn (PrintJobState $state): string => self::stateLabel($state))
                        ->badge()
                        ->color(fn (PrintJobState $state): string => self::stateColor($state)),
                    TextEntry::make('item_type')->label('Type')->badge()->color('gray'),
                    TextEntry::make('version_inv')->label('Invoice version'),
                    TextEntry::make('customer_name')->label('Customer')->placeholder('—'),
                    TextEntry::make('customer_id')->label('Customer ID')->placeholder('—'),
                    TextEntry::make('printer.windows_name')->label('Printer Windows'),
                    TextEntry::make('binding.source_printer_name')->label('Source printer name')->placeholder('—'),
                    TextEntry::make('received_at')
                        ->label('Received')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone()),
                    TextEntry::make('printed_at')
                        ->label('Printed')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('—'),
                    TextEntry::make('failure_code')
                        ->label('Failure code')
                        ->badge()
                        ->color('danger')
                        ->placeholder('—'),
                    TextEntry::make('attempt_count')->label('Attempts'),
                ])
                ->columns(3),
            Section::make('Document and callback')
                ->icon(Heroicon::OutlinedPaperClip)
                ->schema([
                    TextEntry::make('document.sha256')
                        ->label('SHA-256')
                        ->fontFamily('mono')
                        ->copyable()
                        ->placeholder('No document'),
                    TextEntry::make('document.pages')->label('Pages')->placeholder('—'),
                    TextEntry::make('document.size_bytes')->label('Size')->formatStateUsing(
                        fn (?int $state): string => $state === null ? '—' : number_format($state / 1024, 1).' KiB',
                    ),
                    TextEntry::make('outbox.status')
                        ->label('Status callback')
                        ->badge()
                        ->color(fn (?string $state): string => match ($state) {
                            'sent' => 'success',
                            'failed' => 'danger',
                            'pending' => 'warning',
                            default => 'gray',
                        })
                        ->placeholder('Not created'),
                    TextEntry::make('outbox.attempts')->label('Callback attempts')->placeholder('—'),
                    TextEntry::make('reported_at')
                        ->label('Reported')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('—'),
                ])
                ->columns(3),
            Section::make('Timeline')
                ->description('Append-only trail from job receipt through callback completion.')
                ->icon(Heroicon::OutlinedQueueList)
                ->schema([
                    RepeatableEntry::make('events')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('type')->label('Event')->badge()->color('gray'),
                            TextEntry::make('actor_type')->label('Actor'),
                            TextEntry::make('to_state')->label('Resulting status')->placeholder('—'),
                            TextEntry::make('recorded_at')
                                ->label('Time')
                                ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone()),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('inv_no')
                    ->label('Document number')
                    ->description(fn (PrintJob $record): string => $record->item_type.' · v'.$record->version_inv)
                    ->searchable()
                    ->weight('semibold')
                    ->copyable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->description(fn (PrintJob $record): ?string => $record->customer_id)
                    ->searchable(['customer_name', 'customer_id'])
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('printer.windows_name')
                    ->label('Printer')
                    ->searchable(),
                TextColumn::make('state')
                    ->label('Status')
                    ->formatStateUsing(fn (PrintJobState $state): string => self::stateLabel($state))
                    ->badge()
                    ->color(fn (PrintJobState $state): string => self::stateColor($state)),
                TextColumn::make('failure_code')
                    ->label('Failure')
                    ->badge()
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->since(fn (): string => self::tenantTimezone())
                    ->tooltip(fn (PrintJob $record): string => $record->received_at->timezone(self::tenantTimezone())->format('d M Y, H:i:s'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->multiple()
                    ->options(collect(PrintJobState::cases())->mapWithKeys(
                        fn (PrintJobState $state): array => [$state->value => self::stateLabel($state)],
                    )->all()),
                SelectFilter::make('printer_id')
                    ->label('Printer')
                    ->relationship('printer', 'windows_name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('item_type')
                    ->label('Document type')
                    ->options([
                        'INV' => 'Invoice',
                        'PO' => 'Purchase Order',
                        'TO' => 'Transfer Order',
                    ]),
            ])
            ->defaultSort('received_at', 'desc')
            ->poll('5s')
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No print jobs yet')
            ->emptyStateDescription('Jobs appear automatically after an active binding receives a queue from Opsifin.')
            ->emptyStateIcon(Heroicon::OutlinedPrinter);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintJobs::route('/'),
            'view' => ViewPrintJob::route('/{record}'),
        ];
    }

    private static function stateLabel(PrintJobState $state): string
    {
        return match ($state) {
            PrintJobState::Received => 'Received',
            PrintJobState::Queued => 'Queued',
            PrintJobState::Claimed => 'Claimed by Agent',
            PrintJobState::Printing => 'Printing',
            PrintJobState::Printed => 'Printed',
            PrintJobState::Skipped => 'Skipped',
            PrintJobState::Failed => 'Failed',
            PrintJobState::Cancelled => 'Cancelled',
        };
    }

    private static function stateColor(PrintJobState $state): string
    {
        return match ($state) {
            PrintJobState::Printed => 'success',
            PrintJobState::Failed, PrintJobState::Cancelled => 'danger',
            PrintJobState::Skipped => 'gray',
            PrintJobState::Claimed, PrintJobState::Printing => 'info',
            PrintJobState::Received, PrintJobState::Queued => 'warning',
        };
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

<?php

namespace App\Filament\Resources\PrintJobs;

use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Jobs\Services\ManualJobRetryService;
use App\Domain\Jobs\Services\PrintJobDeletionService;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\PrintJobs\Pages\ListPrintJobs;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use App\Support\DateTimeDisplay;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\HtmlString;
use Throwable;

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
                    TextEntry::make('sourceConnection.name')
                        ->label('Source connection')
                        ->placeholder('Source connection was not recorded'),
                    TextEntry::make('item_type')->label('Document type')->badge()->color('gray'),
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
                    TextEntry::make('printed_document')
                        ->label('Stored document')
                        ->state('Download PDF')
                        ->icon(Heroicon::OutlinedArrowDownTray)
                        ->color('primary')
                        ->url(fn (PrintJob $record): ?string => self::documentDownloadUrl($record))
                        ->visible(fn (PrintJob $record): bool => $record->document_id !== null),
                    TextEntry::make('document.source_url')
                        ->label('Source document URL')
                        ->icon(Heroicon::OutlinedLink)
                        ->fontFamily('mono')
                        ->copyable()
                        ->url(fn (?string $state): ?string => self::safeExternalDocumentUrl($state))
                        ->openUrlInNewTab()
                        ->placeholder('Source URL was not recorded')
                        ->columnSpanFull(),
                    TextEntry::make('failure_code')
                        ->label('Failure code')
                        ->badge()
                        ->color('danger')
                        ->placeholder('—'),
                    TextEntry::make('attempt_count')->label('Attempts'),
                    TextEntry::make('run_number')->label('Print run'),
                    TextEntry::make('latestRetryRequest.status')
                        ->label('Refetch request')
                        ->formatStateUsing(fn (?string $state): string => self::retryStatusLabel($state))
                        ->badge()
                        ->color(fn (?string $state): string => self::retryStatusColor($state))
                        ->placeholder('None'),
                    TextEntry::make('latestRetryRequest.error_message')
                        ->label('Refetch error')
                        ->color('danger')
                        ->visible(fn (PrintJob $record): bool => $record->latestRetryRequest?->status === 'failed')
                        ->columnSpanFull(),
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
                        ->label('Latest callback')
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
                ->description('Append-only trail in a fixed-height panel. Newest events appear first; technical payloads stay collapsed until needed.')
                ->icon(Heroicon::OutlinedQueueList)
                ->schema([
                    TextEntry::make('timeline_panel')
                        ->hiddenLabel()
                        ->state(fn (PrintJob $record): HtmlString => new HtmlString(view(
                            'filament.infolists.print-job-timeline',
                            [
                                'events' => $record->events()->reorder('recorded_at', 'desc')->get(),
                                'timezone' => self::tenantTimezone(),
                            ],
                        )->render())),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'latestRetryRequest',
                'sourceConnection',
            ]))
            ->columns([
                TextColumn::make('inv_no')
                    ->label('Document number')
                    ->description(fn (PrintJob $record): string => 'Version '.$record->version_inv)
                    ->searchable()
                    ->weight('semibold')
                    ->copyable(),
                TextColumn::make('sourceConnection.name')
                    ->label('Source connection')
                    ->description(fn (PrintJob $record): ?string => $record->sourceConnection?->base_url)
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('item_type')
                    ->label('Document type')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
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
                TextColumn::make('latestRetryRequest.status')
                    ->label('Refetch')
                    ->formatStateUsing(fn (?string $state): string => self::retryStatusLabel($state))
                    ->badge()
                    ->color(fn (?string $state): string => self::retryStatusColor($state))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->label('Received')
                    ->formatStateUsing(fn (mixed $state): string => DateTimeDisplay::relativeWithClock($state, self::tenantTimezone()))
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
                SelectFilter::make('source_connection_id')
                    ->label('Source connection')
                    ->relationship('sourceConnection', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('item_type')
                    ->label('Document type')
                    ->options(fn (): array => PrintJob::query()
                        ->select('item_type')
                        ->whereNotNull('item_type')
                        ->distinct()
                        ->orderBy('item_type')
                        ->pluck('item_type', 'item_type')
                        ->all())
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('received_at', 'desc')
            ->poll('5s')
            ->recordActions([
                ActionGroup::make([
                    self::retryPrintingAction(),
                    self::refetchAndRetryAction(),
                    ViewAction::make()
                        ->label('Activity')
                        ->modalHeading(fn (PrintJob $record): string => "Print activity · {$record->inv_no}")
                        ->modalDescription('Review the complete print trail without leaving the job list.')
                        ->modalWidth(Width::SevenExtraLarge),
                    self::deleteAction(),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button()
                    ->dropdownAutoPlacement(),
            ])
            ->emptyStateHeading('No print jobs yet')
            ->emptyStateDescription('Jobs appear automatically after an active binding receives a queue from Opsifin.')
            ->emptyStateIcon(Heroicon::OutlinedPrinter);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrintJobs::route('/'),
        ];
    }

    public static function retryPrintingAction(): Action
    {
        return self::retryAction('retryPrinting')
            ->label('Retry printing')
            ->icon(Heroicon::OutlinedArrowPath)
            ->modalHeading('Retry using the stored PDF?')
            ->modalDescription('Use this after an Agent, spooler, or printer issue has been resolved. The existing PDF snapshot will be preserved.');
    }

    public static function refetchAndRetryAction(): Action
    {
        return self::retryAction('refetchAndRetry')
            ->label('Refetch and retry')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->modalHeading('Download a fresh PDF and retry?')
            ->modalDescription('Use this after the PDF was corrected in Opsifin. A background worker downloads and validates it while the previous evidence remains available.')
            ->modalSubmitActionLabel('Queue refetch');
    }

    public static function deleteAction(): Action
    {
        return Action::make('deletePermanently')
            ->label('Delete permanently')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => self::canDeleteJobs())
            ->disabled(fn (PrintJob $record): bool => app(PrintJobDeletionService::class)->blocker($record) !== null)
            ->tooltip(fn (PrintJob $record): ?string => app(PrintJobDeletionService::class)->blocker($record))
            ->requiresConfirmation()
            ->modalHeading(fn (PrintJob $record): string => "Delete {$record->inv_no} permanently?")
            ->modalDescription('This permanently removes the job, attempts, activity timeline, callback and retry history, and all stored PDF snapshots for this job. The deletion audit remains. If Opsifin still exposes the document, the Hub can pull it again.')
            ->modalSubmitActionLabel('Delete permanently')
            ->schema([
                TextInput::make('confirmation')
                    ->label(fn (PrintJob $record): string => "Type {$record->inv_no} to confirm")
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason')
                    ->helperText('Recorded in the Audit Log. Minimum 10 characters.')
                    ->minLength(10)
                    ->maxLength(500)
                    ->required()
                    ->rows(3),
            ])
            ->action(function (PrintJob $record, array $data, PrintJobDeletionService $deletion): void {
                if ($data['confirmation'] !== $record->inv_no) {
                    Notification::make()
                        ->title('Confirmation does not match')
                        ->body("Type {$record->inv_no} exactly. No data was changed.")
                        ->danger()
                        ->send();

                    return;
                }

                $actor = Filament::auth()->user();

                if (! $actor instanceof User) {
                    abort(403);
                }

                try {
                    $result = $deletion->delete($record, $actor, (string) $data['reason']);
                    $deleted = array_sum($result['deleted_counts']);
                    $notification = Notification::make()
                        ->title('Print job deleted')
                        ->body("{$deleted} job records and stored documents were removed permanently.");

                    if ($result['file_cleanup_failures'] > 0) {
                        $notification
                            ->title('Print job deleted with storage warning')
                            ->body("The database history was removed, but {$result['file_cleanup_failures']} stored PDF file(s) could not be cleaned up. Check storage permissions.")
                            ->warning()
                            ->persistent();
                    } else {
                        $notification->success();
                    }

                    $notification->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Print job could not be deleted')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Print job could not be deleted')
                        ->body('An unexpected error occurred. No active job operation was interrupted.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    private static function retryAction(string $mode): Action
    {
        return Action::make($mode)
            ->color('warning')
            ->visible(fn (PrintJob $record): bool => self::canOperateJobs()
                && $record->state === PrintJobState::Failed
                && ! self::hasActiveRetry($record))
            ->schema([
                Select::make('destination_binding_id')
                    ->label('Destination binding')
                    ->options(fn (PrintJob $record): array => self::destinationBindingOptions($record))
                    ->default(fn (PrintJob $record): ?string => self::defaultDestinationBindingId($record))
                    ->helperText('Only active bindings on the same Opsifin connection are available. The selected binding and printer are recorded in the audit trail.')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->native(false),
                Textarea::make('reason')
                    ->label('Reason')
                    ->placeholder('Describe what was fixed before retrying this job.')
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->action(function (PrintJob $record, array $data, ManualJobRetryService $retries) use ($mode): void {
                $user = Filament::auth()->user();

                if (! $user instanceof User) {
                    return;
                }

                try {
                    if ($mode === 'retryPrinting') {
                        $retries->retryPrinting(
                            $record,
                            $user,
                            (string) $data['reason'],
                            (string) $data['destination_binding_id'],
                        );
                    } else {
                        $retries->requestRefetchAndRetry(
                            $record,
                            $user,
                            (string) $data['reason'],
                            (string) $data['destination_binding_id'],
                        );
                    }

                    Notification::make()
                        ->title($mode === 'retryPrinting' ? 'Print job queued again' : 'PDF refetch queued')
                        ->body($mode === 'retryPrinting'
                            ? 'The stored PDF will be used for the next print attempt.'
                            : 'The background worker will download and validate a fresh PDF. The job remains failed until that step succeeds.')
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Print job could not be retried')
                        ->body($exception->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->title('Print job could not be retried')
                        ->body('An unexpected error occurred. No job data was changed.')
                        ->danger()
                        ->persistent()
                        ->send();
                }
            });
    }

    private static function canOperateJobs(): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, ['tenant_admin', 'operator']);
    }

    private static function canDeleteJobs(): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, ['tenant_admin']);
    }

    private static function documentDownloadUrl(PrintJob $job): ?string
    {
        $tenant = app(TenantContext::class)->tenant();

        if (! $tenant instanceof Tenant || $job->document_id === null) {
            return null;
        }

        // Livewire tidak selalu mengisi Filament::getTenant() ketika modal di-refresh,
        // sedangkan TenantContext dipertahankan oleh middleware sampai aksi selesai.
        return URL::temporarySignedRoute(
            'admin.print-jobs.document.download',
            now()->addMinutes(5),
            [
                'tenant' => $tenant,
                'jobId' => $job->getKey(),
            ],
        );
    }

    private static function safeExternalDocumentUrl(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Source URL berasal dari payload sistem lain. Hanya HTTP(S) yang boleh
        // menjadi link aktif agar nilai seperti javascript: tetap sekadar teks.
        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            ? $url
            : null;
    }

    private static function hasActiveRetry(PrintJob $job): bool
    {
        $request = $job->latestRetryRequest;

        return $request !== null && $request->isActive();
    }

    /** @return array<string, string> */
    private static function destinationBindingOptions(PrintJob $job): array
    {
        $sourceConnectionId = $job->source_connection_id ?? $job->binding?->source_connection_id;

        if ($sourceConnectionId === null) {
            return [];
        }

        return PrinterBinding::query()
            ->with(['printer.agent'])
            ->where('source_connection_id', $sourceConnectionId)
            ->where('is_active', true)
            ->whereHas('printer', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get()
            ->sortBy(fn (PrinterBinding $binding): string => $binding->printer->windows_name)
            ->mapWithKeys(function (PrinterBinding $binding): array {
                $agent = $binding->printer->agent->hostname;
                $label = "{$binding->printer->windows_name} — {$agent}";

                if ($binding->source_printer_name !== $binding->printer->windows_name) {
                    $label .= " · Source: {$binding->source_printer_name}";
                }

                return [(string) $binding->getKey() => $label];
            })
            ->all();
    }

    private static function defaultDestinationBindingId(PrintJob $job): ?string
    {
        $options = self::destinationBindingOptions($job);

        if (array_key_exists((string) $job->binding_id, $options)) {
            return (string) $job->binding_id;
        }

        $matchingPrinter = PrinterBinding::query()
            ->whereKey(array_keys($options))
            ->where('printer_id', $job->printer_id)
            ->value('id');

        if (is_string($matchingPrinter)) {
            return $matchingPrinter;
        }

        return count($options) === 1 ? (string) array_key_first($options) : null;
    }

    private static function retryStatusLabel(?string $status): string
    {
        return match ($status) {
            'pending' => 'Queued',
            'processing' => 'Downloading PDF',
            'succeeded' => 'Refetch succeeded',
            'failed' => 'Refetch failed',
            default => 'None',
        };
    }

    private static function retryStatusColor(?string $status): string
    {
        return match ($status) {
            'pending', 'processing' => 'warning',
            'succeeded' => 'success',
            'failed' => 'danger',
            default => 'gray',
        };
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

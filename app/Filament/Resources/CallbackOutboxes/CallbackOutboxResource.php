<?php

namespace App\Filament\Resources\CallbackOutboxes;

use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\CallbackOutboxes\Pages\ListCallbackOutboxes;
use App\Filament\Resources\CallbackOutboxes\Pages\ViewCallbackOutbox;
use App\Filament\Resources\Tenants\TenantResource;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CallbackOutboxResource extends Resource
{
    protected static ?string $model = CallbackOutbox::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?string $navigationLabel = 'Callback Outbox';

    protected static ?string $modelLabel = 'callback';

    protected static ?string $pluralModelLabel = 'Callback Outbox';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Callback delivery')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->schema([
                    TextEntry::make('job.inv_no')->label('Document number')->copyable()->weight('bold'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state)),
                    TextEntry::make('attempts')->label('Attempts'),
                    TextEntry::make('sourceConnection.name')->label('Source connection'),
                    TextEntry::make('endpoint')->label('Endpoint')->copyable()->columnSpan(2),
                    TextEntry::make('next_attempt_at')
                        ->label('Next attempt')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('—'),
                    TextEntry::make('sent_at')
                        ->label('Sent')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                        ->placeholder('—'),
                    TextEntry::make('last_http_status')->label('Last HTTP status')->badge()->placeholder('—'),
                ])
                ->columns(3),
            Section::make('Payload and response')
                ->description('Basic Auth credentials are not stored with the payload.')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->schema([
                    TextEntry::make('payload_preview')
                        ->label('Payload JSON')
                        ->state(fn (CallbackOutbox $record): string => (string) json_encode(
                            $record->payload,
                            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                        ))
                        ->fontFamily('mono')
                        ->copyable(),
                    TextEntry::make('last_response')
                        ->label('Last response')
                        ->fontFamily('mono')
                        ->placeholder('No response yet'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job.inv_no')
                    ->label('Document number')
                    ->searchable()
                    ->weight('semibold')
                    ->copyable(),
                TextColumn::make('sourceConnection.name')
                    ->label('Source')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state)),
                TextColumn::make('attempts')
                    ->label('Attempts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_http_status')
                    ->label('HTTP')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('next_attempt_at')
                    ->label('Next scheduled attempt')
                    ->since(fn (): string => self::tenantTimezone())
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->since(fn (): string => self::tenantTimezone())
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'sent' => 'Sent',
                        'failed' => 'Permanently failed',
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('10s')
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Outbox is empty')
            ->emptyStateDescription('Terminal callbacks appear when a job is printed, skipped, or permanently failed.')
            ->emptyStateIcon(Heroicon::OutlinedPaperAirplane);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = self::getModel()::query()->where('status', 'pending')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCallbackOutboxes::route('/'),
            'view' => ViewCallbackOutbox::route('/{record}'),
        ];
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'sent' => 'success',
            'failed' => 'danger',
            'pending' => 'warning',
            default => 'gray',
        };
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

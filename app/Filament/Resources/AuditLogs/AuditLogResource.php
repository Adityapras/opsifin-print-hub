<?php

namespace App\Filament\Resources\AuditLogs;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
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

final class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit Logs';

    protected static ?string $modelLabel = 'audit log';

    protected static ?string $pluralModelLabel = 'Audit Logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Activity')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->schema([
                    TextEntry::make('actor.name')->label('Performed by')->placeholder('Deleted user'),
                    TextEntry::make('action')
                        ->label('Action')
                        ->formatStateUsing(fn (string $state): string => self::actionLabel($state))
                        ->badge()
                        ->color('info'),
                    TextEntry::make('subject_type')
                        ->label('Object type')
                        ->formatStateUsing(fn (string $state): string => class_basename($state)),
                    TextEntry::make('subject_id')->label('Object ID')->copyable(),
                    TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
                    TextEntry::make('occurred_at')
                        ->label('Time')
                        ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone()),
                    TextEntry::make('user_agent')->label('User agent')->columnSpanFull()->placeholder('—'),
                ])
                ->columns(3),
            Section::make('Changes')
                ->description('Sensitive fields are always redacted before storage.')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->schema([
                    TextEntry::make('before_preview')
                        ->label('Before')
                        ->state(fn (AuditLog $record): string => self::prettyJson($record->before_data))
                        ->fontFamily('mono')
                        ->placeholder('None'),
                    TextEntry::make('after_preview')
                        ->label('After')
                        ->state(fn (AuditLog $record): string => self::prettyJson($record->after_data))
                        ->fontFamily('mono')
                        ->placeholder('None'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Time')
                    ->dateTime('d M Y, H:i:s', fn (): string => self::tenantTimezone())
                    ->sortable(),
                TextColumn::make('actor.name')
                    ->label('User')
                    ->searchable()
                    ->placeholder('Deleted user'),
                TextColumn::make('action')
                    ->label('Action')
                    ->formatStateUsing(fn (string $state): string => self::actionLabel($state))
                    ->badge()
                    ->color('info'),
                TextColumn::make('subject_type')
                    ->label('Object')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->description(fn (AuditLog $record): string => $record->subject_id)
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label('IP')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        'configuration.created' => 'Created',
                        'configuration.updated' => 'Updated',
                        'configuration.deleted' => 'Deleted',
                    ]),
                SelectFilter::make('actor_id')
                    ->label('User')
                    ->relationship('actor', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('No configuration changes yet')
            ->emptyStateDescription('Connection, binding, site, and printer changes are recorded automatically.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
            'view' => ViewAuditLog::route('/{record}'),
        ];
    }

    private static function actionLabel(string $action): string
    {
        return match ($action) {
            'configuration.created' => 'Created',
            'configuration.updated' => 'Updated',
            'configuration.deleted' => 'Deleted',
            default => $action,
        };
    }

    /** @param array<string, mixed>|null $data */
    private static function prettyJson(?array $data): string
    {
        return $data === null || $data === []
            ? '—'
            : (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function tenantTimezone(): string
    {
        $tenant = app(TenantContext::class)->tenant();

        return $tenant instanceof Tenant ? $tenant->timezone : 'UTC';
    }
}

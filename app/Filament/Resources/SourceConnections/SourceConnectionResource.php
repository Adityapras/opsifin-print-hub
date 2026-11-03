<?php

namespace App\Filament\Resources\SourceConnections;

use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceConnectionTestService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\SourceConnections\Pages\CreateSourceConnection;
use App\Filament\Resources\SourceConnections\Pages\EditSourceConnection;
use App\Filament\Resources\SourceConnections\Pages\ListSourceConnections;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SourceConnectionResource extends Resource
{
    protected static ?string $model = SourceConnection::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $navigationLabel = 'Opsifin Connections';

    protected static ?string $modelLabel = 'source connection';

    protected static ?string $pluralModelLabel = 'Opsifin Connections';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Endpoint and credentials')
                ->description('Credentials are encrypted with APP_KEY and are never displayed again.')
                ->icon(Heroicon::OutlinedKey)
                ->schema([
                    TextInput::make('name')
                        ->label('Connection name')
                        ->placeholder('Opsifin Jakarta')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('base_url')
                        ->label('Base URL')
                        ->placeholder('https://opsifin.example.com/rest/autoprint')
                        ->url()
                        ->required()
                        ->maxLength(2048)
                        ->columnSpanFull(),
                    TextInput::make('username')
                        ->label('REST username')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('password_enc')
                        ->label('REST password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->afterStateHydrated(fn (TextInput $component): TextInput => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Leave blank when editing to keep the current password.'),
                ])
                ->columns(2),
            Section::make('Pull schedule')
                ->description('The Hub only pulls the queue during the tenant active hours.')
                ->icon(Heroicon::OutlinedClock)
                ->schema([
                    TextInput::make('api_version')
                        ->label('API version')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(3)
                        ->required(),
                    TextInput::make('pull_interval_s')
                        ->label('Pull interval (seconds)')
                        ->numeric()
                        ->minValue(10)
                        ->maxValue(3600)
                        ->required(),
                    TimePicker::make('active_from')
                        ->label('Active from')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('active_until')
                        ->label('Active until')
                        ->seconds(false)
                        ->required(),
                    TextInput::make('connect_timeout_s')
                        ->label('Connect timeout (seconds)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(60)
                        ->required(),
                    TextInput::make('timeout_s')
                        ->label('Request timeout (seconds)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(300)
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Active connection')
                        ->helperText('Disable to stop all pulls and callbacks for this connection.')
                        ->default(true)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Source')
                    ->description(fn (SourceConnection $record): string => $record->base_url)
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('api_version')
                    ->label('API')
                    ->formatStateUsing(fn (int $state): string => "v{$state}")
                    ->badge()
                    ->color('gray'),
                TextColumn::make('pull_interval_s')
                    ->label('Interval')
                    ->suffix(' sec')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_ok_at')
                    ->label('Last healthy')
                    ->since(fn (): string => self::tenantTimezone())
                    ->placeholder('Never')
                    ->tooltip(fn (SourceConnection $record): ?string => $record->last_ok_at?->timezone(self::tenantTimezone())->format('d M Y, H:i:s'))
                    ->sortable(),
                TextColumn::make('last_error')
                    ->label('Last condition')
                    ->placeholder('Normal')
                    ->limit(42)
                    ->color(fn (?string $state): string => filled($state) ? 'danger' : 'success')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon(Heroicon::OutlinedSignal)
                    ->visible(fn (): bool => self::canManageConfiguration())
                    ->action(function (SourceConnection $record, SourceConnectionTestService $tester): void {
                        $result = $tester->test($record);
                        $notification = Notification::make()
                            ->title($result->successful ? 'Connection successful' : 'Connection failed')
                            ->body($result->message);

                        $result->successful
                            ? $notification->success()
                            : $notification->danger()->persistent();

                        $notification->send();
                    }),
                EditAction::make(),
            ])
            ->emptyStateHeading('No Opsifin connections yet')
            ->emptyStateDescription('Add the tenant REST endpoint before creating a printer binding.')
            ->emptyStateIcon(Heroicon::OutlinedCircleStack);
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
            'index' => ListSourceConnections::route('/'),
            'create' => CreateSourceConnection::route('/create'),
            'edit' => EditSourceConnection::route('/{record}/edit'),
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

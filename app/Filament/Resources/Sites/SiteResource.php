<?php

namespace App\Filament\Resources\Sites;

use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\PairingCodeService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static ?string $parentResource = TenantResource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $navigationLabel = 'Sites';

    protected static ?string $modelLabel = 'site';

    protected static ?string $pluralModelLabel = 'Sites';

    protected static string|\UnitEnum|null $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Installation location')
                ->description('A site can contain multiple Agent PCs and printers.')
                ->icon(Heroicon::OutlinedBuildingOffice)
                ->schema([
                    TextInput::make('name')
                        ->label('Site name')
                        ->placeholder('Jakarta Office')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('timezone')
                        ->label('Custom timezone')
                        ->placeholder('Use tenant timezone')
                        ->helperText('Example: Asia/Jakarta. Leave blank to use the tenant timezone.')
                        ->maxLength(64),
                    Textarea::make('address')
                        ->label('Address')
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('pic_name')
                        ->label('Contact person')
                        ->maxLength(255),
                    TextInput::make('pic_phone')
                        ->label('Contact phone')
                        ->tel()
                        ->maxLength(50),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Site')
                    ->description(fn (Site $record): string => $record->timezone ?: 'Tenant timezone')
                    ->searchable()
                    ->weight('semibold'),
                TextColumn::make('address')
                    ->label('Address')
                    ->limit(44)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('pic_name')
                    ->label('Contact')
                    ->description(fn (Site $record): ?string => $record->pic_phone)
                    ->placeholder('—'),
                TextColumn::make('agents_count')
                    ->label('Agents')
                    ->counts('agents')
                    ->badge()
                    ->color('info'),
                TextColumn::make('printers_count')
                    ->label('Printers')
                    ->counts('printers')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('name')
            ->recordActions([
                ActionGroup::make([
                    Action::make('pairingCode')
                        ->label('Install Agent here')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('warning')
                        ->visible(fn (): bool => self::canManageFleet())
                        ->action(function (Site $record, PairingCodeService $pairingCodes): void {
                            $code = $pairingCodes->issue($record);

                            Notification::make()
                                ->title('Pairing code ready')
                                ->body("Client **{$record->tenant->name}** · Site **{$record->name}** · Code **{$code}**. Valid for 15 minutes and usable once.")
                                ->success()
                                ->persistent()
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
            ->emptyStateHeading('No sites yet')
            ->emptyStateDescription('Create the first location before installing a Print Agent.')
            ->emptyStateIcon(Heroicon::OutlinedMapPin);
    }

    public static function canCreate(): bool
    {
        return self::canManageFleet();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageFleet();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSites::route('/'),
            'create' => CreateSite::route('/create'),
            'edit' => EditSite::route('/{record}/edit'),
        ];
    }

    private static function canManageFleet(): bool
    {
        $user = Filament::auth()->user();
        $tenant = app(TenantContext::class)->tenant();

        return $user instanceof User
            && $tenant instanceof Tenant
            && $user->hasTenantRole($tenant, ['tenant_admin']);
    }
}

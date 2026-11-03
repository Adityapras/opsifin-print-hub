<?php

namespace App\Filament\Resources\InternalUsers;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\InternalUsers\Pages\CreateInternalUser;
use App\Filament\Resources\InternalUsers\Pages\EditInternalUser;
use App\Filament\Resources\InternalUsers\Pages\ListInternalUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class InternalUserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Internal Team & Access';

    protected static ?string $modelLabel = 'internal user';

    protected static ?string $pluralModelLabel = 'Internal Team & Access';

    protected static ?string $slug = 'internal-team';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Internal account')
                ->description('Only Opsifin internal team members belong here. Client users must never be provisioned in this Hub.')
                ->icon(Heroicon::OutlinedIdentification)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('Leave blank when editing to keep the current password.'),
                    Toggle::make('is_platform_admin')
                        ->label('Platform administrator')
                        ->helperText('Platform administrators can manage every tenant, internal account, and platform setting.'),
                ])
                ->columns(2),
            Section::make('Tenant assignments')
                ->description('Non-platform users only see tenants listed here. Assign the minimum role required for their work.')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->schema([
                    Repeater::make('tenant_access')
                        ->label('Accessible tenants')
                        ->schema([
                            Select::make('tenant_id')
                                ->label('Tenant')
                                ->options(fn (): array => Tenant::query()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('role')
                                ->options([
                                    'tenant_admin' => 'Tenant administrator',
                                    'operator' => 'Operator',
                                    'viewer' => 'Viewer',
                                ])
                                ->default('viewer')
                                ->native(false)
                                ->required(),
                        ])
                        ->columns(2)
                        ->addActionLabel('Assign tenant')
                        ->defaultItems(0)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Team member')->searchable()->weight('semibold'),
                TextColumn::make('email')->searchable()->copyable(),
                IconColumn::make('is_platform_admin')->label('Platform admin')->boolean(),
                TextColumn::make('tenants.name')->label('Tenant assignments')->badge()->separator(','),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No internal users')
            ->emptyStateDescription('Create an internal account, then assign only the tenants that person operates.');
    }

    public static function canViewAny(): bool
    {
        return self::canManageInternalUsers();
    }

    public static function canCreate(): bool
    {
        return self::canManageInternalUsers();
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManageInternalUsers();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInternalUsers::route('/'),
            'create' => CreateInternalUser::route('/create'),
            'edit' => EditInternalUser::route('/{record}/edit'),
        ];
    }

    private static function canManageInternalUsers(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->is_platform_admin;
    }
}

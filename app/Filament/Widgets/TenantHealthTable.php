<?php

namespace App\Filament\Widgets;

use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Tenants\TenantResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

final class TenantHealthTable extends TableWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Tenant health matrix')
            ->description('Open a tenant to inspect its exact connection, site, Agent, printer, and job context.')
            ->query(
                $this->tenantQuery()->withCount([
                    'sites',
                    'agents',
                    'agents as offline_agents_count' => fn (Builder $query): Builder => $query
                        ->whereNull('revoked_at')
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('last_seen_at')
                            ->orWhere('last_seen_at', '<', now()->subMinutes(3))),
                    'printers as active_printers_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                    'sourceConnections',
                    'printerBindings as active_bindings_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                ]),
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Tenant')
                    ->description(fn (Tenant $record): string => $record->code)
                    ->searchable(['name', 'code'])
                    ->weight('semibold'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'active' ? 'success' : 'warning'),
                TextColumn::make('source_connections_count')->label('Connections')->badge()->color('info'),
                TextColumn::make('sites_count')->label('Sites')->numeric(),
                TextColumn::make('agents_count')->label('Agents')->numeric(),
                TextColumn::make('offline_agents_count')->label('Offline')->badge()->color(fn (int $state): string => $state > 0 ? 'danger' : 'success'),
                TextColumn::make('active_printers_count')->label('Printers')->numeric(),
                TextColumn::make('active_bindings_count')->label('Active bindings')->numeric(),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->defaultSort('name');
    }

    /** @return Builder<Tenant> */
    private function tenantQuery(): Builder
    {
        $query = Tenant::query();
        $user = Filament::auth()->user();

        if (! $user instanceof User || $user->is_platform_admin) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $query): Builder => $query->whereKey($user->getKey()));
    }
}

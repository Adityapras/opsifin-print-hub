<?php

namespace App\Filament\Resources\SourceConnections\Pages;

use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\CreateRecord;

final class CreateSourceConnection extends CreateRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = SourceConnectionResource::class;
}

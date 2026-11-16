<?php

namespace App\Filament\Resources\SourceConnections\Pages;

use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\EditRecord;

final class EditSourceConnection extends EditRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = SourceConnectionResource::class;
}

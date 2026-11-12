<?php

namespace App\Filament\Resources\CallbackOutboxes\Pages;

use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\ListRecords;

final class ListCallbackOutboxes extends ListRecords
{
    use HasTenantSubNavigation;

    protected static string $resource = CallbackOutboxResource::class;
}

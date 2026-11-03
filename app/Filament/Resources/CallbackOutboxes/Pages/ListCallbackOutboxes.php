<?php

namespace App\Filament\Resources\CallbackOutboxes\Pages;

use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use Filament\Resources\Pages\ListRecords;

final class ListCallbackOutboxes extends ListRecords
{
    protected static string $resource = CallbackOutboxResource::class;
}

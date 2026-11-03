<?php

namespace App\Filament\Resources\CallbackOutboxes\Pages;

use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCallbackOutbox extends ViewRecord
{
    protected static string $resource = CallbackOutboxResource::class;
}

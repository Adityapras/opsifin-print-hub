<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Filament\Resources\Printers\PrinterResource;
use Filament\Resources\Pages\ListRecords;

final class ListPrinters extends ListRecords
{
    protected static string $resource = PrinterResource::class;
}

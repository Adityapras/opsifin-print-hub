<?php

namespace App\Filament\Resources\PrinterBindings\Pages;

use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPrinterBindings extends ListRecords
{
    protected static string $resource = PrinterBindingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

<?php

namespace App\Filament\Resources\Printers\Pages;

use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Services\PrinterControlService;
use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditPrinter extends EditRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = PrinterResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Printer $record */
        return app(PrinterControlService::class)->update($record, $data);
    }
}

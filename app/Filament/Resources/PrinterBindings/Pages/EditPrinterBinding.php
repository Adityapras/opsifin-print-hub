<?php

namespace App\Filament\Resources\PrinterBindings\Pages;

use App\Domain\Sources\Exceptions\PrinterBindingSyncException;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Services\PrinterBindingService;
use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class EditPrinterBinding extends EditRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = PrinterBindingResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            /** @var PrinterBinding $record */
            return app(PrinterBindingService::class)->update($record, $data);
        } catch (PrinterBindingSyncException $exception) {
            throw ValidationException::withMessages([
                'data.source_connection_id' => $exception->getMessage(),
            ]);
        }
    }
}

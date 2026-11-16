<?php

namespace App\Filament\Resources\PrinterBindings\Pages;

use App\Domain\Sources\Exceptions\PrinterBindingSyncException;
use App\Domain\Sources\Services\PrinterBindingService;
use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use App\Filament\Resources\Tenants\Concerns\HasTenantSubNavigation;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class CreatePrinterBinding extends CreateRecord
{
    use HasTenantSubNavigation;

    protected static string $resource = PrinterBindingResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PrinterBindingService::class)->create($data);
        } catch (PrinterBindingSyncException $exception) {
            throw ValidationException::withMessages([
                'data.source_connection_id' => $exception->getMessage(),
            ]);
        }
    }
}

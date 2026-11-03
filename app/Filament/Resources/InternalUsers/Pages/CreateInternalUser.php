<?php

namespace App\Filament\Resources\InternalUsers\Pages;

use App\Domain\Identity\Services\InternalAccessService;
use App\Filament\Resources\InternalUsers\InternalUserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

final class CreateInternalUser extends CreateRecord
{
    protected static string $resource = InternalUserResource::class;

    /** @var array<int, array{tenant_id?: string|null, role?: string|null}> */
    private array $tenantAccess = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->tenantAccess = $data['tenant_access'] ?? [];
        unset($data['tenant_access']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var User $user */
        $user = $this->record;
        app(InternalAccessService::class)->sync($user, $this->tenantAccess);
    }

    protected function getRedirectUrl(): string
    {
        return InternalUserResource::getUrl();
    }
}

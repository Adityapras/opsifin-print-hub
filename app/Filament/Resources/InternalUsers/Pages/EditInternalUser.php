<?php

namespace App\Filament\Resources\InternalUsers\Pages;

use App\Domain\Identity\Services\InternalAccessService;
use App\Filament\Resources\InternalUsers\InternalUserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;

final class EditInternalUser extends EditRecord
{
    protected static string $resource = InternalUserResource::class;

    /** @var array<int, array{tenant_id?: string|null, role?: string|null}> */
    private array $tenantAccess = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;
        $data['tenant_access'] = $user->memberships()
            ->orderBy('tenant_id')
            ->get(['tenant_id', 'role'])
            ->map(fn ($membership): array => [
                'tenant_id' => $membership->tenant_id,
                'role' => $membership->role,
            ])
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->tenantAccess = $data['tenant_access'] ?? [];
        unset($data['tenant_access']);

        return $data;
    }

    protected function afterSave(): void
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

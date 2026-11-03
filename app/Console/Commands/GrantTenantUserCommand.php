<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Console\Command;

final class GrantTenantUserCommand extends Command
{
    protected $signature = 'hub:grant-tenant-user
        {email : Email of an existing Filament user}
        {tenant_code : Tenant code}
        {--role=tenant_admin : tenant_admin, operator, or viewer}';

    protected $description = 'Grant a Filament user access to a tenant panel';

    public function handle(TenantContext $context): int
    {
        $role = (string) $this->option('role');

        if (! in_array($role, ['tenant_admin', 'operator', 'viewer'], true)) {
            $this->components->error('Role must be tenant_admin, operator, or viewer.');

            return self::INVALID;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();
        $tenant = Tenant::query()->where('code', (string) $this->argument('tenant_code'))->first();

        if ($user === null || $tenant === null) {
            $this->components->error('User or tenant not found.');

            return self::FAILURE;
        }

        $context->set($tenant->getKey());

        try {
            Membership::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                ['role' => $role],
            );
        } finally {
            $context->clear();
        }

        $this->components->info("{$user->email} now has the {$role} role in {$tenant->name}.");

        return self::SUCCESS;
    }
}

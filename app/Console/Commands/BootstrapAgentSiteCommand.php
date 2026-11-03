<?php

namespace App\Console\Commands;

use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\PairingCodeService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

final class BootstrapAgentSiteCommand extends Command
{
    protected $signature = 'hub:bootstrap-agent
        {tenant_code : Unique tenant code}
        {tenant_name : Tenant name}
        {site_name : Agent installation location name}';

    protected $description = 'Prepare a tenant, site, and initial pairing code for Print Agent integration';

    public function handle(TenantContext $context, PairingCodeService $pairingCodes): int
    {
        $tenantCode = (string) $this->argument('tenant_code');
        $tenant = Tenant::query()->firstOrCreate(
            ['code' => $tenantCode],
            ['name' => (string) $this->argument('tenant_name')],
        );

        $context->set($tenant->getKey());

        try {
            $site = Site::query()->firstOrCreate([
                'name' => (string) $this->argument('site_name'),
            ]);
            $pairingCode = $pairingCodes->issue($site);
        } finally {
            // Command scheduler berbagi proses; konteks tenant tidak boleh bocor ke command berikutnya.
            $context->clear();
        }

        $this->components->info('The tenant and site are ready for Agent enrollment.');
        $this->table(
            ['Tenant ID', 'Site ID', 'Pairing code', 'Validity'],
            [[$tenant->getKey(), $site->getKey(), $pairingCode, '15 minutes / single use']],
        );

        return self::SUCCESS;
    }
}

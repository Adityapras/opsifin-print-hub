<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Filament\Resources\Agents\AgentResource;
use App\Filament\Resources\CallbackOutboxes\CallbackOutboxResource;
use App\Filament\Resources\PrinterBindings\PrinterBindingResource;
use App\Filament\Resources\Printers\PrinterResource;
use App\Filament\Resources\PrintJobs\PrintJobResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\SourceConnections\SourceConnectionResource;
use App\Filament\Resources\Tenants\TenantResource;
use Filament\Resources\Pages\ViewRecord;

final class TenantSetupGuide extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    protected static ?string $title = 'Tenant Setup Guide';

    protected string $view = 'filament.resources.tenants.pages.tenant-setup-guide';

    /**
     * @return array<int, array{number: int, title: string, description: string, result: string, ready: bool, url: string}>
     */
    public function getSetupSteps(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->record;
        $parameters = ['tenant' => $tenant->code];
        $connectionReady = $tenant->sourceConnections()
            ->where('identity_status', SourceIdentityStatus::Verified->value)
            ->where('is_active', true)
            ->exists();
        $siteReady = $tenant->sites()->exists();
        $agentReady = $tenant->agents()
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>', now()->subSeconds(Agent::ONLINE_THRESHOLD_SECONDS))
            ->exists();
        $printerReady = $tenant->printers()->where('is_active', true)->exists();
        $bindingReady = $tenant->printerBindings()->where('is_active', true)->exists();
        $statusReady = $tenant->printerBindings()->whereNotNull('last_status_synced_at')->exists();
        $printedReady = $tenant->printJobs()->where('state', 'printed')->exists();
        $callbackReady = $tenant->callbackOutboxes()->where('status', 'sent')->exists();

        return [
            [
                'number' => 1,
                'title' => 'Register the integration identity',
                'description' => 'Copy this tenant Integration ID into its matching Opsifin Main instance. Never reuse it for another client.',
                'result' => 'A permanent cross-system identity exists.',
                'ready' => filled($tenant->integration_id),
                'url' => TenantResource::getUrl('identity', ['record' => $tenant]),
            ],
            [
                'number' => 2,
                'title' => 'Connect and verify Opsifin Main',
                'description' => 'Create the Source Connection, verify /identity, then activate it only after the UUID matches.',
                'result' => 'The source is verified and active.',
                'ready' => $connectionReady,
                'url' => SourceConnectionResource::getUrl('index', $parameters),
            ],
            [
                'number' => 3,
                'title' => 'Create the physical site',
                'description' => 'Add the office or branch where the Agent PC and printers are physically located.',
                'result' => 'Fleet ownership has a clear location boundary.',
                'ready' => $siteReady,
                'url' => SiteResource::getUrl('index', $parameters),
            ],
            [
                'number' => 4,
                'title' => 'Pair and validate the Agent',
                'description' => 'Generate a site pairing code, enroll the Windows Agent, and wait until heartbeat reports Online.',
                'result' => 'At least one Agent is Online.',
                'ready' => $agentReady,
                'url' => AgentResource::getUrl('index', $parameters),
            ],
            [
                'number' => 5,
                'title' => 'Approve the destination printer',
                'description' => 'Review discovered printers, select the exact Windows queue, configure it, and mark it Active.',
                'result' => 'At least one printer is approved for use.',
                'ready' => $printerReady,
                'url' => PrinterResource::getUrl('index', $parameters),
            ],
            [
                'number' => 6,
                'title' => 'Create the printer binding',
                'description' => 'Map PrinterName, Identifier, and version from Opsifin to one exact destination printer. Stop legacy autoprint before activation.',
                'result' => 'A safe source-to-printer route is active.',
                'ready' => $bindingReady,
                'url' => PrinterBindingResource::getUrl('index', $parameters),
            ],
            [
                'number' => 7,
                'title' => 'Confirm printer status synchronization',
                'description' => 'Wait for the Hub scheduler to send effective availability to Opsifin Main, including Agent offline detection.',
                'result' => 'Opsifin has received at least one status update.',
                'ready' => $statusReady,
                'url' => PrinterBindingResource::getUrl('index', $parameters),
            ],
            [
                'number' => 8,
                'title' => 'Run the end-to-end print test',
                'description' => 'Submit one controlled invoice, confirm it prints or completes in simulation, then verify the callback is delivered.',
                'result' => 'A Printed job and Sent callback prove the full path.',
                'ready' => $printedReady && $callbackReady,
                'url' => $printedReady
                    ? CallbackOutboxResource::getUrl('index', $parameters)
                    : PrintJobResource::getUrl('index', $parameters),
            ],
        ];
    }
}

<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentPairingCode;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\PairingCodeService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;

function enrollmentPayload(string $pairingCode): array
{
    return [
        'pairing_code' => $pairingCode,
        'hostname' => 'PC-FIN-01',
        'os' => 'Windows 11 Pro 24H2',
        'agent_version' => '1.0.0',
        'machine_id' => 'machine-guid-a',
    ];
}

function siteWithPairingCode(): array
{
    $tenant = Tenant::query()->create([
        'code' => 'tenant-a',
        'name' => 'Tenant A',
    ]);

    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Jakarta']);
    $code = app(PairingCodeService::class)->issue($site);

    $context->clear();

    return [$tenant, $site, $code];
}

it('menukar pairing code sekali pakai dengan identitas dan secret agent', function () {
    [$tenant, $site, $code] = siteWithPairingCode();

    $response = $this->postJson('/agent/v1/enroll', enrollmentPayload($code));

    $response
        ->assertCreated()
        ->assertJsonPath('tenant_id', $tenant->getKey())
        ->assertJsonPath('site_id', $site->getKey())
        ->assertJsonPath('config.claim_interval_s', 5)
        ->assertJsonPath('config.heartbeat_interval_s', 30)
        ->assertJsonPath('config.printers', []);

    $agent = Agent::withoutGlobalScopes()->sole();
    $deviceSecret = $response->json('device_secret');

    expect($deviceSecret)->toBeString()->not->toBeEmpty()
        ->and($agent->secret_hash)->toBe(hash('sha256', $deviceSecret))
        ->and($agent->secret_hash)->not->toContain($deviceSecret)
        ->and($agent->tenant_id)->toBe($tenant->getKey());

    expect(AgentPairingCode::withoutGlobalScopes()->sole()->used_at)->not->toBeNull();

    $this->postJson('/agent/v1/enroll', enrollmentPayload($code))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'pairing_code_invalid');
});

it('menolak pairing code yang kedaluwarsa', function () {
    [, , $code] = siteWithPairingCode();

    AgentPairingCode::withoutGlobalScopes()->firstOrFail()->update([
        'expires_at' => now()->subSecond(),
    ]);

    $this->postJson('/agent/v1/enroll', enrollmentPayload($code))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'pairing_code_invalid');

    expect(Agent::withoutGlobalScopes()->count())->toBe(0);
});

it('mengaktifkan kembali agent yang sama tanpa menduplikasi printer', function () {
    [$tenant, $site, $firstCode] = siteWithPairingCode();
    $firstResponse = $this->postJson('/agent/v1/enroll', enrollmentPayload($firstCode))->assertCreated();
    $agentId = $firstResponse->json('agent_id');
    $oldSecret = $firstResponse->json('device_secret');
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        $agent = Agent::query()->findOrFail($agentId);
        $agent->forceFill(['revoked_at' => now(), 'last_seen_at' => now()->subMinute()])->save();
        Printer::query()->create([
            'agent_id' => $agent->getKey(),
            'windows_name' => 'EPSON-REUSE',
            'status' => 'ONLINE',
        ]);
        $secondCode = app(PairingCodeService::class)->issue($site);
    } finally {
        $context->clear();
    }

    $secondResponse = $this->postJson('/agent/v1/enroll', enrollmentPayload($secondCode))
        ->assertCreated()
        ->assertJsonPath('agent_id', $agentId);

    $freshAgent = Agent::withoutGlobalScopes()->findOrFail($agentId);
    $newSecret = $secondResponse->json('device_secret');

    expect(Agent::withoutGlobalScopes()->count())->toBe(1)
        ->and(Printer::withoutGlobalScopes()->count())->toBe(1)
        ->and($freshAgent->revoked_at)->toBeNull()
        ->and($freshAgent->last_seen_at)->toBeNull()
        ->and($freshAgent->status)->toBe('offline')
        ->and($freshAgent->secret_hash)->toBe(hash('sha256', $newSecret))
        ->and($freshAgent->secret_hash)->not->toBe(hash('sha256', $oldSecret));
});

it('mengembalikan format error kontrak untuk payload enrollment yang tidak valid', function () {
    $this->postJson('/agent/v1/enroll', [])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['code', 'message']])
        ->assertJsonPath('error.code', 'validation_failed');
});

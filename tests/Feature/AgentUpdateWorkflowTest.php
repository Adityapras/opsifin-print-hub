<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Domain\Fleet\Models\Site;
use App\Domain\Fleet\Services\AgentCommandService;
use App\Domain\Fleet\Services\AgentUpdateLifecycleService;
use App\Domain\Fleet\Services\AgentUpdateRolloutService;
use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Platform\Services\AgentPackageInspection;
use App\Domain\Platform\Services\AgentPackageInspector;
use App\Domain\Platform\Services\AgentReleaseManifestVerifier;
use App\Domain\Platform\Services\AgentReleaseService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

function updateWorkflowFleet(string $version = '0.3.12'): array
{
    $tenant = Tenant::query()->create(['code' => 'ota-'.str()->random(8), 'name' => 'OTA Client']);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Jakarta']);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PRINT-PC',
        'os' => 'Windows 11',
        'version' => $version,
        'machine_id' => 'ota-machine-'.str()->random(8),
        'secret_hash' => hash('sha256', 'secret'),
        'last_seen_at' => now(),
        'status' => 'online',
    ]);

    return compact('tenant', 'site', 'agent');
}

function createVerifiedRelease(
    User $actor,
    string $version = '0.3.13',
    string $signatureType = AgentRelease::SIGNATURE_OPSIGO_INTERNAL,
): AgentRelease {
    Storage::fake('local');
    Storage::disk('local')->put("agent-releases/packages/agent-{$version}.msi", 'signed-msi-content');

    app()->instance(AgentPackageInspector::class, new class($version) implements AgentPackageInspector
    {
        public function __construct(private readonly string $version) {}

        public function inspect(string $absolutePath, bool $verifyAuthenticode = true): AgentPackageInspection
        {
            return new AgentPackageInspection(
                $this->version,
                $verifyAuthenticode ? 'CN=Opsifin Test Signing' : null,
            );
        }
    });

    $manifestPath = null;
    if ($signatureType === AgentRelease::SIGNATURE_OPSIGO_INTERNAL) {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $details = openssl_pkey_get_details($key);
        expect($details)->toBeArray();
        $publicKey = preg_replace('/-----[^-]+-----|\s+/', '', $details['key']);
        config()->set('print_hub.agent_updates.internal_public_keys.test-release-key', $publicKey);

        $size = strlen('signed-msi-content');
        $sha256 = hash('sha256', 'signed-msi-content');
        $payload = implode("\n", [
            'opsigo-agent-release-v1',
            'product=opsifin-print-agent',
            'version='.$version,
            'channel=stable',
            'size_bytes='.$size,
            'sha256='.$sha256,
            'key_id=test-release-key',
            '',
        ]);
        expect(openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256))->toBeTrue();
        $manifestPath = "agent-releases/manifests/agent-{$version}.release.json";
        Storage::disk('local')->put($manifestPath, json_encode([
            'schema_version' => 1,
            'product' => 'opsifin-print-agent',
            'version' => $version,
            'channel' => 'stable',
            'size_bytes' => $size,
            'sha256' => $sha256,
            'signature_scheme' => AgentReleaseManifestVerifier::SIGNATURE_SCHEME,
            'signing_key_id' => 'test-release-key',
            'signature' => base64_encode($signature),
        ], JSON_THROW_ON_ERROR));
    }

    $release = app(AgentReleaseService::class)->create([
        'version' => $version,
        'channel' => 'stable',
        'path' => "agent-releases/packages/agent-{$version}.msi",
        'signature_type' => $signatureType,
        'signature_manifest_path' => $manifestPath,
        'original_name' => "OpsifinPrintAgent-Setup-{$version}-win-x64.msi",
        'release_notes' => 'OTA workflow verification.',
    ], $actor);

    return app(AgentReleaseService::class)->publish($release, $actor);
}

it('verifies an internal release and schedules an immutable signed update command', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createVerifiedRelease($actor);

    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne(
        $release,
        $fleet['agent'],
        $actor,
        'automatic',
    );

    $command = $deployment->command;

    expect($deployment->status)->toBe('pending')
        ->and($command->type)->toBe('update')
        ->and($command->args['version'])->toBe('0.3.13')
        ->and($command->args['sha256'])->toBe(hash('sha256', 'signed-msi-content'))
        ->and($command->args['signature_scheme'])->toBe(AgentRelease::SIGNATURE_OPSIGO_INTERNAL)
        ->and($command->args['signature'])->toBe($release->signature_value)
        ->and($command->args['signing_key_id'])->toBe('test-release-key')
        ->and($command->args['size_bytes'])->toBe(strlen('signed-msi-content'))
        ->and($command->args['channel'])->toBe('stable')
        ->and($command->args['install_policy'])->toBe('automatic')
        ->and($command->args['url'])->toContain('/agent/v1/updates/'.$deployment->getKey().'/package')
        ->and($command->args['url'])->toContain('signature=');

    $this->get($command->args['url'])
        ->assertOk()
        ->assertHeader('content-disposition');

    $this->get('/agent/v1/updates/'.$deployment->getKey().'/package')->assertForbidden();
});

it('keeps the Authenticode command shape for pre-bridge Agents', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet('0.3.11');
    $release = createVerifiedRelease($actor, '0.3.12', AgentRelease::SIGNATURE_AUTHENTICODE);

    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    expect($deployment->command->args['signature'])->toBe('authenticode')
        ->and($deployment->command->args)->not->toHaveKey('signature_scheme')
        ->and($deployment->command->args)->not->toHaveKey('signing_key_id');
});

it('requires the bridge before scheduling an internally signed release', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet('0.3.11');
    $release = createVerifiedRelease($actor, '0.3.13');

    expect(fn () => app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor))
        ->toThrow(ValidationException::class);
});

it('rejects a release manifest whose signature was changed after signing', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $release = createVerifiedRelease($actor);
    $manifestPath = (string) $release->manifest_path;
    $manifest = json_decode(
        Storage::disk('local')->get($manifestPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $manifest['signature'] = base64_encode('tampered-signature');
    Storage::disk('local')->put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(fn () => app(AgentReleaseManifestVerifier::class)->verify(
        Storage::disk('local')->path($manifestPath),
        $release->version,
        $release->channel,
        $release->size_bytes,
        $release->sha256,
    ))->toThrow(RuntimeException::class);
});

it('does not report installed until heartbeat confirms the target version', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createVerifiedRelease($actor);
    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);
    $commands = app(AgentCommandService::class);

    $commands->forHeartbeat($fleet['agent']);
    expect($deployment->fresh()->status)->toBe('delivered');

    $commands->recordResult($fleet['agent'], $deployment->agent_command_id, 'succeeded', 'Package accepted.', null);
    expect($deployment->fresh()->status)->toBe('accepted')
        ->and($deployment->fresh()->installed_at)->toBeNull();

    $fleet['agent']->update(['version' => '0.3.13']);
    app(AgentUpdateLifecycleService::class)->observeHeartbeat($fleet['agent']->fresh(), null);

    expect($deployment->fresh()->status)->toBe('installed')
        ->and($deployment->fresh()->progress_percent)->toBe(100)
        ->and($deployment->fresh()->installed_at)->not->toBeNull();
});

it('records detailed Agent progress and rejects an equal or older target', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet('0.3.13');
    $release = createVerifiedRelease($actor);

    expect(fn () => app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor))
        ->toThrow(ValidationException::class);

    $fleet['agent']->update(['version' => '0.3.12']);
    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    app(AgentUpdateLifecycleService::class)->observeHeartbeat($fleet['agent'], [
        'command_id' => $deployment->agent_command_id,
        'status' => 'downloading',
        'progress_percent' => 42,
        'message' => 'Downloading update package.',
    ]);

    expect($deployment->fresh()->status)->toBe('downloading')
        ->and($deployment->fresh()->progress_percent)->toBe(42);
});

it('accepts lifecycle progress through the authenticated heartbeat contract', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet('0.3.12');
    $release = createVerifiedRelease($actor);
    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    $this->postJson('/agent/v1/heartbeat', [
        'agent_version' => '0.3.12',
        'uptime_s' => 120,
        'agent_time' => now()->toIso8601String(),
        'config_version' => 1,
        'printers' => [],
        'update' => [
            'command_id' => $deployment->agent_command_id,
            'target_version' => '0.3.13',
            'status' => 'ready',
            'progress_percent' => 100,
            'message' => 'Package verified and ready.',
            'updated_at' => now()->toIso8601String(),
        ],
    ], [
        'Authorization' => "Bearer {$fleet['agent']->getKey()}.secret",
        'X-Agent-Version' => '0.3.12',
    ])->assertOk();

    expect($deployment->fresh()->status)->toBe('ready')
        ->and($deployment->fresh()->progress_percent)->toBe(100)
        ->and($deployment->fresh()->message)->toBe('Package verified and ready.');
});

it('keeps deployments tenant isolated and denies release management to tenant users', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createVerifiedRelease($actor);
    app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    $otherTenant = Tenant::query()->create(['code' => 'other-ota', 'name' => 'Other OTA']);
    app(TenantContext::class)->set($otherTenant->getKey());
    expect(AgentUpdateDeployment::query()->count())->toBe(0);

    $tenantUser = User::factory()->create();
    $this->actingAs($tenantUser)->get('/admin/agent-releases')->assertForbidden();

    $this->actingAs($actor)->get('/admin/agent-releases')->assertOk()->assertSee('Agent Releases');
});

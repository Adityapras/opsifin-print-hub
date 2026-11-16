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

function createAgentRelease(
    User $actor,
    string $version = '0.3.13',
    string $installPolicy = AgentRelease::INSTALL_MANUAL,
    ?string $releaseNotes = 'OTA workflow verification.',
): AgentRelease {
    Storage::fake('local');
    Storage::disk('local')->put("agent-releases/packages/agent-{$version}.msi", 'msi-content');

    app()->instance(AgentPackageInspector::class, new class($version) implements AgentPackageInspector
    {
        public function __construct(private readonly string $version) {}

        public function inspect(string $absolutePath): AgentPackageInspection
        {
            return new AgentPackageInspection($this->version);
        }
    });

    $release = app(AgentReleaseService::class)->create([
        'version' => $version,
        'path' => "agent-releases/packages/agent-{$version}.msi",
        'install_policy' => $installPolicy,
        'original_name' => "OpsifinPrintAgent-Setup-{$version}-win-x64.msi",
        'release_notes' => $releaseNotes,
    ], $actor);

    return app(AgentReleaseService::class)->publish($release, $actor);
}

it('accepts an extensionless storage path when the original package name is MSI', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);

    Storage::fake('local');
    Storage::disk('local')->put('agent-releases/packages/random-storage-name', 'msi-content');

    app()->instance(AgentPackageInspector::class, new class implements AgentPackageInspector
    {
        public function inspect(string $absolutePath): AgentPackageInspection
        {
            return new AgentPackageInspection('1.0.0');
        }
    });

    $release = app(AgentReleaseService::class)->create([
        'version' => '1.0.0',
        'path' => 'agent-releases/packages/random-storage-name',
        'original_name' => 'OpsifinPrintAgent-Setup-1.0.0-win-x64.msi',
    ], $actor);

    expect($release->path)->toBe('agent-releases/packages/random-storage-name')
        ->and($release->original_name)->toBe('OpsifinPrintAgent-Setup-1.0.0-win-x64.msi');
});

it('calculates SHA-256 and sends only the simplified update command', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createAgentRelease($actor, installPolicy: AgentRelease::INSTALL_AUTOMATIC);

    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    $command = $deployment->command;

    expect($deployment->status)->toBe('pending')
        ->and($command->type)->toBe('update')
        ->and($command->args['version'])->toBe('0.3.13')
        ->and($command->args['sha256'])->toBe(hash('sha256', 'msi-content'))
        ->and($command->args['install_policy'])->toBe('automatic')
        ->and(array_keys($command->args))->toBe(['version', 'url', 'sha256', 'install_policy', 'release_notes'])
        ->and($command->args['url'])->toContain('/agent/v1/updates/'.$deployment->getKey().'/package')
        ->and($command->args['url'])->toContain('signature=');

    $this->get($command->args['url'], [
        'Authorization' => "Bearer {$fleet['agent']->getKey()}.secret",
    ])
        ->assertOk()
        ->assertHeader('content-disposition');

    $this->get($command->args['url'])->assertUnauthorized();

    $this->get('/agent/v1/updates/'.$deployment->getKey().'/package', [
        'Authorization' => "Bearer {$fleet['agent']->getKey()}.secret",
    ])->assertForbidden();
});

it('uses manual installation by default and omits empty release notes', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createAgentRelease($actor, releaseNotes: null);

    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    expect($release->install_policy)->toBe(AgentRelease::INSTALL_MANUAL)
        ->and($deployment->install_policy)->toBe(AgentRelease::INSTALL_MANUAL)
        ->and($deployment->command->args)->not->toHaveKey('release_notes');
});

it('allows only the assigned Agent to download an update package', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createAgentRelease($actor);
    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);
    $otherAgent = Agent::query()->create([
        'site_id' => $fleet['site']->getKey(),
        'hostname' => 'OTHER-PC',
        'os' => 'Windows 11',
        'version' => '0.3.12',
        'machine_id' => 'other-machine-'.str()->random(8),
        'secret_hash' => hash('sha256', 'other-secret'),
        'last_seen_at' => now(),
        'status' => 'online',
    ]);

    $this->get($deployment->command->args['url'], [
        'Authorization' => "Bearer {$otherAgent->getKey()}.other-secret",
    ])->assertNotFound();
});

it('rebuilds a pending legacy command to the simplified payload before delivery', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createAgentRelease($actor);
    $deployment = app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);
    $deployment->command->update([
        'args' => [
            'version' => $release->version,
            'url' => 'https://expired.example/package',
            'sha256' => $release->sha256,
            'signature_scheme' => 'obsolete',
            'signature' => 'obsolete',
            'signing_key_id' => 'obsolete',
        ],
    ]);

    app(AgentCommandService::class)->forHeartbeat($fleet['agent']);

    expect(array_keys($deployment->command->fresh()->args))
        ->toBe(['version', 'url', 'sha256', 'install_policy', 'release_notes']);
});

it('does not report installed until heartbeat confirms the target version', function () {
    $actor = User::factory()->create(['is_platform_admin' => true]);
    $fleet = updateWorkflowFleet();
    $release = createAgentRelease($actor);
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
    $release = createAgentRelease($actor);

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
    $release = createAgentRelease($actor);
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
    $release = createAgentRelease($actor);
    app(AgentUpdateRolloutService::class)->scheduleOne($release, $fleet['agent'], $actor);

    $otherTenant = Tenant::query()->create(['code' => 'other-ota', 'name' => 'Other OTA']);
    app(TenantContext::class)->set($otherTenant->getKey());
    expect(AgentUpdateDeployment::query()->count())->toBe(0);

    $tenantUser = User::factory()->create();
    $this->actingAs($tenantUser)->get('/admin/agent-releases')->assertForbidden();

    $this->actingAs($actor)
        ->get('/admin/agent-releases')
        ->assertOk()
        ->assertSee('Agent Releases');

    $this->actingAs($actor)
        ->get('/admin/agent-releases/create')
        ->assertOk()
        ->assertSee('Agent MSI')
        ->assertSee('Installation policy')
        ->assertDontSee('release manifest')
        ->assertDontSee('Authenticode');

    $this->actingAs($actor)
        ->get('/admin/agent-releases/'.$release->getRouteKey())
        ->assertOk()
        ->assertSee('Agent deployment lifecycle')
        ->assertSee('Manual');
});

<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentCommand;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;

function createHeartbeatAgent(string $tenantCode, string $secret): array
{
    $tenant = Tenant::query()->create([
        'code' => $tenantCode,
        'name' => strtoupper($tenantCode),
    ]);

    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Site '.$tenantCode]);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PC-'.$tenantCode,
        'os' => 'Windows 11',
        'version' => '0.9.0',
        'machine_id' => 'machine-'.$tenantCode,
        'secret_hash' => hash('sha256', $secret),
    ]);

    $context->clear();

    return [$tenant, $site, $agent];
}

function heartbeatPayload(int $configVersion = 0, array $printers = []): array
{
    return [
        'agent_version' => '1.0.0',
        'uptime_s' => 1234,
        'agent_time' => '2026-10-29T07:00:00Z',
        'config_version' => $configVersion,
        'capabilities' => ['pdfium', 'sumatra'],
        'printers' => $printers,
    ];
}

function heartbeatHeaders(Agent $agent, string $secret): array
{
    return [
        'Authorization' => "Bearer {$agent->getKey()}.{$secret}",
        'X-Agent-Version' => '1.0.0',
    ];
}

it('mencatat heartbeat dan menemukan printer secara otomatis', function () {
    $secret = 'secret-agent-a';
    [$tenant, , $agent] = createHeartbeatAgent('tenant-a', $secret);

    $response = $this->postJson('/agent/v1/heartbeat', heartbeatPayload(printers: [
        [
            'name' => 'EPSON-LQ-01',
            'driver' => 'EPSON Universal',
            'port' => 'IP_10.0.0.10',
            'is_default' => true,
            'status' => 'ONLINE',
            'raw_status' => 0,
            'queue_length' => 2,
        ],
    ]), heartbeatHeaders($agent, $secret));

    $response
        ->assertOk()
        ->assertJsonPath('config_version', 1)
        ->assertJsonPath('config.source_poll_interval_s', null)
        ->assertJsonPath('config.printers', [])
        ->assertJsonPath('commands', []);

    $freshAgent = Agent::withoutGlobalScopes()->findOrFail($agent->getKey());
    $printer = Printer::withoutGlobalScopes()->sole();

    expect($freshAgent->tenant_id)->toBe($tenant->getKey())
        ->and($freshAgent->status)->toBe('online')
        ->and($freshAgent->version)->toBe('1.0.0')
        ->and($freshAgent->capabilities)->toBe(['pdfium', 'sumatra'])
        ->and($freshAgent->last_seen_at)->not->toBeNull()
        ->and($printer->tenant_id)->toBe($tenant->getKey())
        ->and($printer->windows_name)->toBe('EPSON-LQ-01')
        ->and($printer->status)->toBe('ONLINE')
        ->and($printer->is_active)->toBeFalse();
});

it('mengirim interval source tunggal dan null ketika jadwal agent ambigu', function () {
    $secret = 'secret-agent-a';
    [$tenant, , $agent] = createHeartbeatAgent('tenant-source-interval', $secret);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $printerA = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'INTERVAL-PRINTER-A',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connectionA = SourceConnection::query()->create([
        'name' => 'Interval Source A',
        'base_url' => 'https://interval-a.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
        'pull_interval_s' => 30,
    ]);
    $connectionA->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
    PrinterBinding::query()->create([
        'source_connection_id' => $connectionA->getKey(),
        'printer_id' => $printerA->getKey(),
        'source_printer_name' => 'INTERVAL-PRINTER-A',
        'source_identifier' => 'SOURCE-A',
        'printer_version' => 1,
        'is_active' => true,
    ]);

    $versionWithOneSource = $agent->fresh()->config_version;
    $context->clear();

    $this->postJson(
        '/agent/v1/heartbeat',
        heartbeatPayload($versionWithOneSource - 1, [['name' => $printerA->windows_name, 'status' => 'ONLINE']]),
        heartbeatHeaders($agent, $secret),
    )
        ->assertOk()
        ->assertJsonPath('config.source_poll_interval_s', 30);

    $context->set($tenant->getKey());
    $printerB = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'INTERVAL-PRINTER-B',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connectionB = SourceConnection::query()->create([
        'name' => 'Interval Source B',
        'base_url' => 'https://interval-b.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
        'pull_interval_s' => 30,
    ]);
    $connectionB->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
    PrinterBinding::query()->create([
        'source_connection_id' => $connectionB->getKey(),
        'printer_id' => $printerB->getKey(),
        'source_printer_name' => 'INTERVAL-PRINTER-B',
        'source_identifier' => 'SOURCE-B',
        'printer_version' => 1,
        'is_active' => true,
    ]);

    $versionWithMatchingSources = $agent->fresh()->config_version;
    $context->clear();

    $this->postJson(
        '/agent/v1/heartbeat',
        heartbeatPayload($versionWithMatchingSources - 1, [
            ['name' => $printerA->windows_name, 'status' => 'ONLINE'],
            ['name' => $printerB->windows_name, 'status' => 'ONLINE'],
        ]),
        heartbeatHeaders($agent, $secret),
    )
        ->assertOk()
        ->assertJsonPath('config.source_poll_interval_s', 30);

    $context->set($tenant->getKey());
    $connectionB->update(['pull_interval_s' => 60]);
    $ambiguousVersion = $agent->fresh()->config_version;
    $context->clear();

    $this->postJson(
        '/agent/v1/heartbeat',
        heartbeatPayload($versionWithMatchingSources, [
            ['name' => $printerA->windows_name, 'status' => 'ONLINE'],
            ['name' => $printerB->windows_name, 'status' => 'ONLINE'],
        ]),
        heartbeatHeaders($agent, $secret),
    )
        ->assertOk()
        ->assertJsonPath('config_version', $ambiguousVersion)
        ->assertJsonPath('config.source_poll_interval_s', null);
});

it('menaikkan config version saat interval source berubah', function () {
    $secret = 'secret-agent-a';
    [$tenant, , $agent] = createHeartbeatAgent('tenant-source-version', $secret);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'VERSION-PRINTER',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connection = SourceConnection::query()->create([
        'name' => 'Version Source',
        'base_url' => 'https://version-source.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
        'pull_interval_s' => 30,
    ]);
    $connection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
        'is_active' => true,
    ])->saveQuietly();
    PrinterBinding::query()->create([
        'source_connection_id' => $connection->getKey(),
        'printer_id' => $printer->getKey(),
        'source_printer_name' => 'VERSION-PRINTER',
        'source_identifier' => 'VERSION-SOURCE',
        'printer_version' => 1,
        'is_active' => true,
    ]);

    $oldVersion = $agent->fresh()->config_version;
    $connection->update(['pull_interval_s' => 45]);
    $newVersion = $agent->fresh()->config_version;
    $context->clear();

    expect($newVersion)->toBe($oldVersion + 1);

    $this->postJson(
        '/agent/v1/heartbeat',
        heartbeatPayload($oldVersion, [['name' => $printer->windows_name, 'status' => 'ONLINE']]),
        heartbeatHeaders($agent, $secret),
    )
        ->assertOk()
        ->assertJsonPath('config_version', $newVersion)
        ->assertJsonPath('config.source_poll_interval_s', 45);
});

it('hanya mengirim config ketika versi agent berbeda dan hanya memuat printer aktif', function () {
    $secret = 'secret-agent-a';
    [, , $agent] = createHeartbeatAgent('tenant-a', $secret);
    $context = app(TenantContext::class);
    $context->set($agent->tenant_id);

    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'ACTIVE-PRINTER',
        'status' => 'ONLINE',
        'is_active' => true,
        'print_settings' => ['copies' => 2, 'duplex' => true],
    ]);

    $context->clear();

    $report = [[
        'name' => $printer->windows_name,
        'status' => 'ONLINE',
    ]];

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(0, $report), heartbeatHeaders($agent, $secret))
        ->assertOk()
        ->assertJsonPath('config.printers.0.printer_id', $printer->getKey())
        ->assertJsonPath('config.printers.0.print.copies', 2)
        ->assertJsonPath('config.printers.0.print.duplex', true);

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(1, $report), heartbeatHeaders($agent, $secret))
        ->assertOk()
        ->assertJsonMissingPath('config');
});

it('tidak mengubah printer tenant lain saat menyinkronkan inventaris', function () {
    $secretA = 'secret-agent-a';
    [, , $agentA] = createHeartbeatAgent('tenant-a', $secretA);
    [, , $agentB] = createHeartbeatAgent('tenant-b', 'secret-agent-b');

    $context = app(TenantContext::class);
    $context->set($agentB->tenant_id);
    $printerB = Printer::query()->create([
        'agent_id' => $agentB->getKey(),
        'windows_name' => 'SHARED-NAME',
        'status' => 'PAPER_OUT',
    ]);
    $context->clear();

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(printers: [
        ['name' => 'SHARED-NAME', 'status' => 'ONLINE'],
    ]), heartbeatHeaders($agentA, $secretA))->assertOk();

    expect(Printer::withoutGlobalScopes()->findOrFail($printerB->getKey())->status)->toBe('PAPER_OUT')
        ->and(Printer::withoutGlobalScopes()->count())->toBe(2);
});

it('menolak token salah, agent dicabut, dan versi agent lama', function () {
    $secret = 'secret-agent-a';
    [, , $agent] = createHeartbeatAgent('tenant-a', $secret);

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(), heartbeatHeaders($agent, 'wrong-secret'))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');

    config()->set('print_hub.min_agent_version', '2.0.0');

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(), heartbeatHeaders($agent, $secret))
        ->assertStatus(426)
        ->assertJsonPath('error.code', 'agent_outdated');

    $agent->forceFill(['revoked_at' => now()])->save();

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(), heartbeatHeaders($agent, $secret))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'invalid_token');
});

it('mengirim remote command lewat heartbeat dan menerima hasilnya', function () {
    $secret = 'secret-agent-a';
    [, , $agent] = createHeartbeatAgent('tenant-a', $secret);
    $context = app(TenantContext::class);
    $context->set($agent->tenant_id);
    $command = AgentCommand::query()->create([
        'agent_id' => $agent->getKey(),
        'type' => 'test_page',
        'args' => ['printer_id' => '019a0000-0000-7000-8000-000000000001'],
    ]);
    $context->clear();

    $this->postJson('/agent/v1/heartbeat', heartbeatPayload(1), heartbeatHeaders($agent, $secret))
        ->assertOk()
        ->assertJsonPath('commands.0.id', $command->getKey())
        ->assertJsonPath('commands.0.type', 'test_page');

    $this->post(
        "/agent/v1/commands/{$command->getKey()}/result",
        ['status' => 'succeeded', 'message' => 'Halaman uji tercetak.'],
        heartbeatHeaders($agent, $secret),
    )->assertNoContent();

    $freshCommand = AgentCommand::withoutGlobalScopes()->findOrFail($command->getKey());

    expect($freshCommand->status)->toBe('succeeded')
        ->and($freshCommand->result_message)->toBe('Halaman uji tercetak.')
        ->and($freshCommand->finished_at)->not->toBeNull();
});

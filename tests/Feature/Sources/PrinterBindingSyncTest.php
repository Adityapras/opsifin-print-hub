<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Sources\Enums\SourceIdentityStatus;
use App\Domain\Sources\Exceptions\PrinterBindingSyncException;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\PrinterBindingService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function bindingSyncFixture(string $code = 'binding-sync'): array
{
    $tenant = Tenant::query()->create(['code' => $code, 'name' => strtoupper($code)]);
    app(TenantContext::class)->set($tenant->getKey());

    $site = Site::query()->create(['name' => 'Site '.$code]);
    $agent = Agent::query()->create([
        'site_id' => $site->getKey(),
        'hostname' => 'PC-'.$code,
        'os' => 'Windows 11',
        'version' => '1.0.0',
        'machine_id' => 'machine-'.$code,
        'secret_hash' => hash('sha256', 'secret'),
    ]);
    $printer = Printer::query()->create([
        'agent_id' => $agent->getKey(),
        'windows_name' => 'EPSON-'.$code,
        'alias' => 'Front Desk',
        'status' => 'ONLINE',
        'is_active' => true,
    ]);
    $connection = SourceConnection::query()->create([
        'name' => 'Legacy '.$code,
        'base_url' => 'https://'.$code.'.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
    ]);
    $connection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $tenant->integration_id,
        'identity_verified_at' => now(),
    ])->saveQuietly();

    return compact('tenant', 'site', 'agent', 'printer', 'connection');
}

function bindingAttributes(array $fixture, array $overrides = []): array
{
    return $overrides + [
        'source_connection_id' => $fixture['connection']->getKey(),
        'printer_id' => $fixture['printer']->getKey(),
        'source_printer_name' => 'EPSON Legacy',
        'source_identifier' => 'CAB-01',
        'printer_version' => 1,
        'is_active' => false,
    ];
}

it('menyinkronkan binding baru ke endpoint printerName sebelum menyimpannya', function () {
    $fixture = bindingSyncFixture('binding-create');
    Http::fake([
        'https://binding-create.test/rest/autoprint/printerName' => Http::response(null, 200),
    ]);

    $binding = app(PrinterBindingService::class)->create(bindingAttributes($fixture));

    expect($binding->exists)->toBeTrue()
        ->and($binding->is_active)->toBeFalse()
        ->and($fixture['connection']->fresh()->last_ok_at)->not->toBeNull()
        ->and($fixture['connection']->fresh()->last_error)->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://binding-create.test/rest/autoprint/printerName'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('rest-user:rest-password'))
            && $request['PrinterName'] === 'EPSON Legacy'
            && $request['PrinterNameOld'] === 'EPSON Legacy'
            && $request['PrinterAlias'] === 'Front Desk'
            && $request['PrinterAliasOld'] === 'Front Desk'
            && $request['PrinterVersion'] === 1
            && $request['Identifier'] === 'CAB-01';
    });
});

it('menolak binding sebelum source connection terverifikasi', function () {
    $fixture = bindingSyncFixture('binding-unverified');
    $fixture['connection']->forceFill([
        'identity_status' => SourceIdentityStatus::Pending,
        'identity_verified_at' => null,
    ])->saveQuietly();
    Http::fake();

    expect(fn () => app(PrinterBindingService::class)->create(bindingAttributes($fixture)))
        ->toThrow(PrinterBindingSyncException::class, 'Verify the Opsifin Integration Identity');

    expect(PrinterBinding::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('mengirim nilai nama lama saat routing binding diubah', function () {
    $fixture = bindingSyncFixture('binding-update');
    $binding = PrinterBinding::query()->create(bindingAttributes($fixture));
    Http::fake([
        'https://binding-update.test/rest/autoprint/printerName' => Http::response(null, 200),
    ]);

    $updated = app(PrinterBindingService::class)->update($binding, [
        'source_printer_name' => 'EPSON Renamed',
        'printer_version' => 3,
    ]);

    expect($updated->source_printer_name)->toBe('EPSON Renamed')
        ->and($updated->printer_version)->toBe(3);

    Http::assertSent(fn (Request $request): bool => $request['PrinterName'] === 'EPSON Renamed'
        && $request['PrinterNameOld'] === 'EPSON Legacy'
        && $request['PrinterAlias'] === 'Front Desk'
        && $request['PrinterAliasOld'] === 'Front Desk'
        && $request['PrinterVersion'] === 3
        && $request['Identifier'] === 'CAB-01');
});

it('menolak memindahkan binding yang sudah dibuat ke source connection lain', function () {
    $fixture = bindingSyncFixture('binding-source-immutable');
    $binding = PrinterBinding::query()->create(bindingAttributes($fixture));
    $otherConnection = SourceConnection::query()->create([
        'name' => 'Another Opsifin',
        'base_url' => 'https://binding-source-other.test/rest/autoprint',
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
    ]);
    $otherConnection->forceFill([
        'identity_status' => SourceIdentityStatus::Verified,
        'remote_integration_id' => $fixture['tenant']->integration_id,
        'identity_verified_at' => now(),
    ])->saveQuietly();
    Http::fake();

    expect(fn () => app(PrinterBindingService::class)->update($binding, [
        'source_connection_id' => $otherConnection->getKey(),
    ]))->toThrow(PrinterBindingSyncException::class, 'cannot be changed');

    expect($binding->fresh()->source_connection_id)->toBe($fixture['connection']->getKey());
    Http::assertNothingSent();
});

it('tidak memanggil printerName ketika hanya status aktif binding berubah', function () {
    $fixture = bindingSyncFixture('binding-toggle');
    $binding = PrinterBinding::query()->create(bindingAttributes($fixture));
    $oldConfigVersion = $fixture['agent']->fresh()->config_version;
    Http::fake();

    $updated = app(PrinterBindingService::class)->update($binding, ['is_active' => true]);

    expect($updated->is_active)->toBeTrue()
        ->and($fixture['agent']->fresh()->config_version)->toBe($oldConfigVersion + 1);
    Http::assertNothingSent();
});

it('dapat menyinkronkan ulang binding lama tanpa mengubah datanya', function () {
    $fixture = bindingSyncFixture('binding-existing');
    $binding = PrinterBinding::query()->create(bindingAttributes($fixture));
    Http::fake([
        'https://binding-existing.test/rest/autoprint/printerName' => Http::response(null, 200),
    ]);

    app(PrinterBindingService::class)->syncExisting($binding);

    Http::assertSent(fn (Request $request): bool => $request['PrinterName'] === 'EPSON Legacy'
        && $request['PrinterNameOld'] === 'EPSON Legacy'
        && $request['PrinterAlias'] === 'Front Desk'
        && $request['PrinterAliasOld'] === 'Front Desk');
});

it('tidak menyimpan binding ketika sinkronisasi ditolak Opsifin', function () {
    $fixture = bindingSyncFixture('binding-failed');
    Http::fake([
        'https://binding-failed.test/rest/autoprint/printerName' => Http::response('Unauthorized', 401),
    ]);

    expect(fn () => app(PrinterBindingService::class)->create(bindingAttributes($fixture)))
        ->toThrow(PrinterBindingSyncException::class, 'username or password');

    expect(PrinterBinding::query()->count())->toBe(0)
        ->and($fixture['connection']->fresh()->last_error)->toContain('username or password');
});

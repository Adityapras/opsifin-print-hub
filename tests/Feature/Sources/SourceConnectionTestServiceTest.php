<?php

use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceConnectionTestService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function connectionTestFixture(string $baseUrl = 'https://opsifin.test/rest/autoprint'): SourceConnection
{
    $tenant = Tenant::query()->create(['code' => 'connection-test', 'name' => 'Connection Test']);
    app(TenantContext::class)->set($tenant->getKey());

    return SourceConnection::query()->create([
        'name' => 'Opsifin Test',
        'base_url' => $baseUrl,
        'username' => 'rest-user',
        'password_enc' => 'rest-password',
    ]);
}

it('menguji endpoint PrinterStatus dengan credential terenkripsi', function () {
    $connection = connectionTestFixture();

    Http::fake([
        'https://opsifin.test/rest/autoprint/PrinterStatus' => Http::response(['ok' => true]),
    ]);

    $result = app(SourceConnectionTestService::class)->test($connection);

    expect($result->successful)->toBeTrue()
        ->and($result->code)->toBe('OK')
        ->and($connection->fresh()->last_ok_at)->not->toBeNull()
        ->and($connection->fresh()->last_error)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://opsifin.test/rest/autoprint/PrinterStatus'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('rest-user:rest-password'))
        && $request['PrinterName'] === '__PRINT_HUB_CONNECTION_TEST__'
        && $request['Identifier'] === '__PRINT_HUB__'
        && $request['PrinterStatus'] === 'ONLINE');
});

it('memberi diagnosis jelas untuk respons HTTP sumber', function (int $status, string $code, string $message) {
    $connection = connectionTestFixture();
    Http::fake(['*' => Http::response('error', $status)]);

    $result = app(SourceConnectionTestService::class)->test($connection);

    expect($result->successful)->toBeFalse()
        ->and($result->code)->toBe($code)
        ->and($result->message)->toContain($message)
        ->and($connection->fresh()->last_error)->toBe($result->message);
})->with([
    'credential ditolak' => [401, 'AUTH_FAILED', 'password'],
    'endpoint salah' => [404, 'ENDPOINT_NOT_FOUND', 'Base URL'],
    'server gagal' => [500, 'HTTP_ERROR', 'HTTP 500'],
]);

it('menangani kegagalan jaringan tanpa membocorkan credential', function () {
    $connection = connectionTestFixture();
    Http::fake(['*' => Http::failedConnection('connection timed out for rest-user:rest-password')]);

    $result = app(SourceConnectionTestService::class)->test($connection);

    expect($result->successful)->toBeFalse()
        ->and($result->code)->toBe('CONNECTION_FAILED')
        ->and($result->message)->not->toContain('rest-user')
        ->and($result->message)->not->toContain('rest-password');
});

it('menolak base URL yang bukan HTTP tanpa mengirim request', function () {
    $connection = connectionTestFixture('ftp://opsifin.test/autoprint');
    Http::fake();

    $result = app(SourceConnectionTestService::class)->test($connection);

    expect($result->successful)->toBeFalse()
        ->and($result->code)->toBe('INVALID_URL');

    Http::assertNothingSent();
});

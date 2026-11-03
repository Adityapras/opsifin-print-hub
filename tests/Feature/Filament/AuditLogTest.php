<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\Membership;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;

it('mencatat perubahan konfigurasi manusia dan menyamarkan secret', function () {
    $tenant = Tenant::query()->create(['code' => 'audit', 'name' => 'Audit Tenant']);
    $user = User::factory()->create(['name' => 'Admin Audit']);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());
    Membership::query()->create([
        'user_id' => $user->getKey(),
        'role' => 'tenant_admin',
    ]);
    $this->actingAs($user);

    $source = SourceConnection::query()->create([
        'name' => 'Opsifin Lama',
        'base_url' => 'https://audit.test/rest',
        'username' => 'audit-user',
        'password_enc' => 'old-secret',
    ]);
    $source->update([
        'name' => 'Opsifin Baru',
        'password_enc' => 'new-secret',
    ]);

    $logs = AuditLog::query()->oldest('occurred_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe('configuration.created')
        ->and($logs[0]->after_data['password_enc'])->toBe('[REDACTED]')
        ->and($logs[1]->action)->toBe('configuration.updated')
        ->and($logs[1]->before_data['password_enc'])->toBe('[REDACTED]')
        ->and($logs[1]->after_data['password_enc'])->toBe('[REDACTED]')
        ->and(json_encode($logs->toArray()))->not->toContain('old-secret')
        ->and(json_encode($logs->toArray()))->not->toContain('new-secret');

    $context->clear();

    $this->get('/admin/tenants/audit/audit-logs')
        ->assertOk()
        ->assertSee('Admin Audit')
        ->assertSee('SourceConnection');
});

it('tidak mencatat perubahan health otomatis sebagai konfigurasi manusia', function () {
    $tenant = Tenant::query()->create(['code' => 'audit-health', 'name' => 'Audit Health']);
    $user = User::factory()->create();
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());
    $this->actingAs($user);

    $source = SourceConnection::query()->create([
        'name' => 'Health Source',
        'base_url' => 'https://health.test/rest',
        'username' => 'user',
        'password_enc' => 'secret',
    ]);
    $source->update([
        'last_ok_at' => now(),
        'last_error' => null,
    ]);

    expect(AuditLog::query()->count())->toBe(1);
});

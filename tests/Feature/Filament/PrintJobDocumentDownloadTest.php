<?php

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Identity\Models\Membership;
use App\Domain\Jobs\Enums\PrintJobState;
use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function createPrintedDocumentFixture(string $tenantCode): array
{
    $tenant = Tenant::query()->create([
        'code' => $tenantCode,
        'name' => strtoupper($tenantCode),
    ]);
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        $site = Site::query()->create(['name' => 'Download Test Site']);
        $agent = Agent::query()->create([
            'site_id' => $site->getKey(),
            'hostname' => 'PC-'.$tenantCode,
            'os' => 'Windows 11',
            'version' => '1.0.0',
            'machine_id' => 'machine-'.$tenantCode,
            'secret_hash' => hash('sha256', 'secret-'.$tenantCode),
        ]);
        $printer = Printer::query()->create([
            'agent_id' => $agent->getKey(),
            'windows_name' => 'PRINTER-'.$tenantCode,
            'is_active' => true,
        ]);
        $pdf = "%PDF-1.4\nprinted-document\n%%EOF";
        $path = "tenants/{$tenant->getKey()}/documents/download-test.pdf";
        Storage::disk('local')->put($path, $pdf);
        $document = Document::query()->create([
            'disk' => 'local',
            'path' => $path,
            'sha256' => hash('sha256', $pdf),
            'pages' => 1,
            'size_bytes' => strlen($pdf),
            'source_url' => 'https://opsifin.example.test/print/invoice/INV-DOWNLOAD-001',
        ]);
        $job = PrintJob::query()->create([
            'printer_id' => $printer->getKey(),
            'document_id' => $document->getKey(),
            'inv_no' => 'INV-DOWNLOAD-001',
            'item_type' => 'INV',
            'version_inv' => 1,
            'state' => PrintJobState::Printed,
            'available_at' => now(),
            'received_at' => now(),
            'printed_at' => now(),
        ]);
    } finally {
        $context->clear();
    }

    return compact('tenant', 'document', 'job', 'pdf');
}

function grantDownloadMembership(User $user, Tenant $tenant): void
{
    $context = app(TenantContext::class);
    $context->set($tenant->getKey());

    try {
        Membership::query()->create([
            'user_id' => $user->getKey(),
            'role' => 'viewer',
        ]);
    } finally {
        $context->clear();
    }
}

it('mengunduh snapshot PDF job printed untuk anggota tenant', function () {
    Storage::fake('local');
    $fixture = createPrintedDocumentFixture('download-a');
    $user = User::factory()->create();
    grantDownloadMembership($user, $fixture['tenant']);
    $url = URL::temporarySignedRoute(
        'admin.print-jobs.document.download',
        now()->addMinutes(5),
        ['tenant' => $fixture['tenant'], 'jobId' => $fixture['job']->getKey()],
    );

    $response = $this->actingAs($user)->get($url);

    $response
        ->assertOk()
        ->assertDownload('inv-download-001.pdf')
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('cache-control', 'no-store, private');
    expect($response->streamedContent())->toBe($fixture['pdf']);
});

it('menolak download lintas tenant walaupun URL ditandatangani', function () {
    Storage::fake('local');
    $fixtureA = createPrintedDocumentFixture('download-owner');
    $fixtureB = createPrintedDocumentFixture('download-other');
    $user = User::factory()->create();
    grantDownloadMembership($user, $fixtureB['tenant']);
    $url = URL::temporarySignedRoute(
        'admin.print-jobs.document.download',
        now()->addMinutes(5),
        ['tenant' => $fixtureA['tenant'], 'jobId' => $fixtureA['job']->getKey()],
    );

    $this->actingAs($user)->get($url)->assertNotFound();
});

it('mengizinkan snapshot untuk job yang belum berstatus printed', function () {
    Storage::fake('local');
    $fixture = createPrintedDocumentFixture('download-queued');
    $fixture['job']->forceFill(['state' => PrintJobState::Queued])->saveQuietly();
    $user = User::factory()->create();
    grantDownloadMembership($user, $fixture['tenant']);
    $url = URL::temporarySignedRoute(
        'admin.print-jobs.document.download',
        now()->addMinutes(5),
        ['tenant' => $fixture['tenant'], 'jobId' => $fixture['job']->getKey()],
    );

    $this->actingAs($user)
        ->get($url)
        ->assertOk()
        ->assertDownload('inv-download-001.pdf');
});

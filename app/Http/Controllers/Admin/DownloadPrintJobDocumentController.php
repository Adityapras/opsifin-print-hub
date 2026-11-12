<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Jobs\Models\Document;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadPrintJobDocumentController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, string $jobId): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessTenant($tenant), 404);

        $job = PrintJob::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereKey($jobId)
            ->firstOrFail();

        abort_if($job->document_id === null, 404);

        $document = Document::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereKey($job->document_id)
            ->firstOrFail();
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        $baseName = Str::slug((string) $job->inv_no);
        $fileName = ($baseName !== '' ? $baseName : 'print-job-'.$job->getKey()).'.pdf';

        // Snapshot adalah bukti operasional tenant; response tidak boleh disimpan oleh
        // browser bersama atau proxy setelah operator selesai memeriksanya.
        return $disk->download($document->path, $fileName, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Jobs\Models\Document;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadDocumentController extends Controller
{
    public function __invoke(string $documentId): StreamedResponse
    {
        $document = Document::withoutGlobalScopes()->findOrFail($documentId);
        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return response()->stream(function () use ($disk, $document): void {
            $stream = $disk->readStream($document->path);

            abort_if($stream === null, 404);

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) $document->size_bytes,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}

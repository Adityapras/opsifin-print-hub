<?php

namespace App\Http\Controllers\Agent\V1;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\AgentUpdateDeployment;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadUpdatePackageController extends Controller
{
    /** @throws FileNotFoundException */
    public function __invoke(Request $request, string $deploymentId): StreamedResponse
    {
        /** @var Agent $authenticatedAgent */
        $authenticatedAgent = $request->attributes->get('agent');

        $deployment = AgentUpdateDeployment::query()
            ->with('release')
            ->where('agent_id', $authenticatedAgent->getKey())
            ->findOrFail($deploymentId);

        if ($deployment->status === 'cancelled' || $deployment->release->status !== 'published') {
            abort(404);
        }

        $release = $deployment->release;
        if (! Storage::disk($release->disk)->exists($release->path)) {
            abort(404, 'Update package is unavailable.');
        }

        return Storage::disk($release->disk)->download(
            $release->path,
            $release->original_name,
            [
                'Content-Type' => 'application/x-msi',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}

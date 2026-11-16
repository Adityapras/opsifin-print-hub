<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\AgentUpdateDeployment;
use Illuminate\Support\Facades\URL;
use RuntimeException;

final class AgentUpdatePackageUrl
{
    public function temporary(AgentUpdateDeployment $deployment): string
    {
        $url = URL::temporarySignedRoute(
            'agent.v1.updates.package',
            now()->addMinutes((int) config('print_hub.agent_updates.download_url_ttl_minutes')),
            ['deploymentId' => $deployment->getKey()],
        );

        // SHA-256 melindungi integritas paket, tetapi transport produksi tetap harus
        // terenkripsi agar token Agent dan paket tidak terlihat di jaringan.
        if (app()->environment('production') && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Production Agent update package URLs must use HTTPS.');
        }

        return $url;
    }
}

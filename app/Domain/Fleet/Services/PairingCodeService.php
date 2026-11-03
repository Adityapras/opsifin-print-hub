<?php

namespace App\Domain\Fleet\Services;

use App\Domain\Fleet\Models\AgentPairingCode;
use App\Domain\Fleet\Models\Site;
use DateInterval;

final class PairingCodeService
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function issue(Site $site, ?DateInterval $ttl = null): string
    {
        do {
            $code = $this->randomCode();
            $codeHash = hash('sha256', $code);
        } while (AgentPairingCode::withoutGlobalScopes()->where('code_hash', $codeHash)->exists());

        AgentPairingCode::query()->create([
            'site_id' => $site->getKey(),
            'code_hash' => $codeHash,
            'expires_at' => now()->add($ttl ?? new DateInterval('PT15M')),
        ]);

        return $code;
    }

    private function randomCode(): string
    {
        $code = '';
        $lastIndex = strlen(self::ALPHABET) - 1;

        for ($position = 0; $position < 8; $position++) {
            $code .= self::ALPHABET[random_int(0, $lastIndex)];
        }

        return $code;
    }
}

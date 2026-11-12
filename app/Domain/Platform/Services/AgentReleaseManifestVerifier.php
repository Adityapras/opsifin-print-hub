<?php

namespace App\Domain\Platform\Services;

use JsonException;
use RuntimeException;

final class AgentReleaseManifestVerifier
{
    public const SIGNATURE_SCHEME = 'opsigo-rsa-sha256-v1';

    public const PRODUCT = 'opsifin-print-agent';

    public function verify(
        string $manifestPath,
        string $version,
        string $channel,
        int $sizeBytes,
        string $sha256,
    ): AgentReleaseManifest {
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The Opsigo release manifest is no longer available.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The Opsigo release manifest is not valid JSON.', previous: $exception);
        }

        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['product'] ?? null) !== self::PRODUCT
            || ($manifest['version'] ?? null) !== $version
            || ($manifest['channel'] ?? null) !== $channel
            || ($manifest['size_bytes'] ?? null) !== $sizeBytes
            || ($manifest['sha256'] ?? null) !== strtolower($sha256)
            || ($manifest['signature_scheme'] ?? null) !== self::SIGNATURE_SCHEME
            || ! is_string($manifest['signing_key_id'] ?? null)
            || ! is_string($manifest['signature'] ?? null)) {
            throw new RuntimeException('The Opsigo release manifest does not match the uploaded MSI and release form.');
        }

        $keyId = $manifest['signing_key_id'];
        $publicKeys = config('print_hub.agent_updates.internal_public_keys', []);
        $publicKey = is_array($publicKeys) ? ($publicKeys[$keyId] ?? null) : null;
        if (! is_string($publicKey) || $publicKey === '') {
            throw new RuntimeException("The Opsigo release key '{$keyId}' is not trusted by this Hub.");
        }

        $signature = base64_decode($manifest['signature'], true);
        $keyDer = base64_decode($publicKey, true);
        if ($signature === false || $keyDer === false) {
            throw new RuntimeException('The Opsigo release signature or public key is not valid Base64.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($keyDer), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
        $verified = openssl_verify(
            $this->payload($version, $channel, $sizeBytes, $sha256, $keyId),
            $signature,
            $pem,
            OPENSSL_ALGO_SHA256,
        );
        if ($verified !== 1) {
            throw new RuntimeException('The Opsigo release signature is invalid. The package was not published.');
        }

        return new AgentReleaseManifest(
            self::SIGNATURE_SCHEME,
            $keyId,
            $manifest['signature'],
        );
    }

    private function payload(
        string $version,
        string $channel,
        int $sizeBytes,
        string $sha256,
        string $keyId,
    ): string {
        // Format baris tetap menghindari perbedaan canonical JSON antara PHP dan .NET.
        return implode("\n", [
            'opsigo-agent-release-v1',
            'product='.self::PRODUCT,
            'version='.$version,
            'channel='.$channel,
            'size_bytes='.$sizeBytes,
            'sha256='.strtolower($sha256),
            'key_id='.$keyId,
            '',
        ]);
    }
}

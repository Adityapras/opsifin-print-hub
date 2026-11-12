<?php

namespace App\Domain\Platform\Services;

use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Platform\Models\AgentReleaseEvent;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class AgentReleaseService
{
    public function __construct(
        private readonly AgentPackageInspector $inspector,
        private readonly AgentReleaseManifestVerifier $manifestVerifier,
    ) {}

    /**
     * @param array{
     *     version: string,
     *     channel: string,
     *     path: string,
     *     signature_type?: string|null,
     *     signature_manifest_path?: string|null,
     *     release_notes?: string|null,
     *     original_name?: string|null
     * } $data
     */
    public function create(array $data, User $actor): AgentRelease
    {
        $version = trim($data['version']);
        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw ValidationException::withMessages(['version' => 'Use a three-part semantic version such as 0.3.10.']);
        }

        $disk = 'local';
        $path = $data['path'];
        if (! str_ends_with(strtolower($path), '.msi') || ! Storage::disk($disk)->exists($path)) {
            throw ValidationException::withMessages(['path' => 'Upload a valid MSI package.']);
        }

        $size = Storage::disk($disk)->size($path);
        $maximumBytes = (int) config('print_hub.agent_updates.max_package_bytes');
        if ($size <= 0 || $size > $maximumBytes) {
            throw ValidationException::withMessages(['path' => 'The MSI exceeds the configured package size limit.']);
        }

        $sha256 = hash_file('sha256', Storage::disk($disk)->path($path));
        if (! is_string($sha256)) {
            throw ValidationException::withMessages(['path' => 'The MSI SHA-256 could not be calculated.']);
        }

        $signatureType = ($data['signature_type'] ?? null) ?: AgentRelease::SIGNATURE_AUTHENTICODE;
        if (! in_array($signatureType, [
            AgentRelease::SIGNATURE_AUTHENTICODE,
            AgentRelease::SIGNATURE_OPSIGO_INTERNAL,
        ], true)) {
            throw ValidationException::withMessages(['signature_type' => 'Choose a supported release verification method.']);
        }

        try {
            $inspection = $this->inspector->inspect(
                Storage::disk($disk)->path($path),
                $signatureType === AgentRelease::SIGNATURE_AUTHENTICODE,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        if ($inspection->productVersion !== $version) {
            throw ValidationException::withMessages([
                'version' => "The form version {$version} does not match MSI ProductVersion {$inspection->productVersion}.",
            ]);
        }

        $manifestPath = null;
        $manifest = null;
        if ($signatureType === AgentRelease::SIGNATURE_OPSIGO_INTERNAL) {
            $manifestPath = $data['signature_manifest_path'] ?? null;
            if (! is_string($manifestPath)
                || ! str_ends_with(strtolower($manifestPath), '.json')
                || ! Storage::disk($disk)->exists($manifestPath)) {
                throw ValidationException::withMessages([
                    'signature_manifest_path' => 'Upload the JSON release manifest created by Opsifin.Agent.ReleaseSigner.',
                ]);
            }

            try {
                $manifest = $this->manifestVerifier->verify(
                    Storage::disk($disk)->path($manifestPath),
                    $version,
                    $data['channel'],
                    $size,
                    $sha256,
                );
            } catch (\RuntimeException $exception) {
                throw ValidationException::withMessages([
                    'signature_manifest_path' => $exception->getMessage(),
                ]);
            }
        }

        return AgentRelease::query()->getConnection()->transaction(function () use ($data, $actor, $disk, $path, $size, $version, $inspection, $sha256, $signatureType, $manifestPath, $manifest): AgentRelease {
            $release = AgentRelease::query()->create([
                'version' => $version,
                'channel' => $data['channel'],
                'disk' => $disk,
                'path' => $path,
                'original_name' => ($data['original_name'] ?? null) ?: basename($path),
                'size_bytes' => $size,
                'sha256' => $sha256,
                'signature_type' => $signatureType,
                'signature_value' => $manifest?->signature,
                'signing_key_id' => $manifest?->signingKeyId,
                'manifest_path' => $manifestPath,
                'signature_subject' => $inspection->signatureSubject
                    ?? ($manifest === null ? null : "Opsigo internal release key {$manifest->signingKeyId}"),
                'signature_verified_at' => now(),
                'release_notes' => $data['release_notes'] ?? null,
                'status' => AgentRelease::STATUS_DRAFT,
                'created_by' => $actor->getKey(),
            ]);

            $this->event($release, $actor, 'release.created', [
                'version' => $version,
                'sha256' => $release->sha256,
                'size_bytes' => $size,
                'signature_type' => $signatureType,
                'signing_key_id' => $manifest?->signingKeyId,
            ]);

            return $release;
        });
    }

    public function publish(AgentRelease $release, User $actor): AgentRelease
    {
        if ($release->status !== AgentRelease::STATUS_DRAFT) {
            return $release;
        }

        $release->update([
            'status' => AgentRelease::STATUS_PUBLISHED,
            'published_by' => $actor->getKey(),
            'published_at' => now(),
        ]);
        $this->event($release, $actor, 'release.published');

        return $release->fresh();
    }

    public function retire(AgentRelease $release, User $actor): AgentRelease
    {
        if ($release->status === AgentRelease::STATUS_RETIRED) {
            return $release;
        }

        $release->update([
            'status' => AgentRelease::STATUS_RETIRED,
            'retired_at' => now(),
        ]);
        $this->event($release, $actor, 'release.retired');

        return $release->fresh();
    }

    /** @param array<string, mixed> $data */
    public function event(AgentRelease $release, ?User $actor, string $type, array $data = []): AgentReleaseEvent
    {
        return AgentReleaseEvent::query()->create([
            'agent_release_id' => $release->getKey(),
            'actor_id' => $actor?->getKey(),
            'type' => $type,
            'data' => $data,
            'occurred_at' => now(),
        ]);
    }
}

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
    ) {}

    /**
     * @param array{
     *     version: string,
     *     path: string,
     *     install_policy?: string|null,
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
        $originalName = ($data['original_name'] ?? null) ?: basename($path);
        if (! str_ends_with(strtolower($originalName), '.msi') || ! Storage::disk($disk)->exists($path)) {
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

        $installPolicy = ($data['install_policy'] ?? null) ?: AgentRelease::INSTALL_MANUAL;
        if (! in_array($installPolicy, AgentRelease::INSTALL_POLICIES, true)) {
            throw ValidationException::withMessages(['install_policy' => 'Choose manual or automatic installation.']);
        }

        try {
            $inspection = $this->inspector->inspect(Storage::disk($disk)->path($path));
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['path' => $exception->getMessage()]);
        }

        if ($inspection->productVersion !== $version) {
            throw ValidationException::withMessages([
                'version' => "The form version {$version} does not match MSI ProductVersion {$inspection->productVersion}.",
            ]);
        }

        return AgentRelease::query()->getConnection()->transaction(function () use ($data, $actor, $disk, $path, $originalName, $size, $version, $sha256, $installPolicy): AgentRelease {
            $release = AgentRelease::query()->create([
                'version' => $version,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $originalName,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'install_policy' => $installPolicy,
                'release_notes' => $data['release_notes'] ?? null,
                'status' => AgentRelease::STATUS_DRAFT,
                'created_by' => $actor->getKey(),
            ]);

            $this->event($release, $actor, 'release.created', [
                'version' => $version,
                'sha256' => $release->sha256,
                'size_bytes' => $size,
                'install_policy' => $installPolicy,
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

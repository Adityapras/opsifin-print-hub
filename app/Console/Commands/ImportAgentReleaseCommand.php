<?php

namespace App\Console\Commands;

use App\Domain\Platform\Services\AgentReleaseService;
use App\Models\User;
use Illuminate\Console\Command;

final class ImportAgentReleaseCommand extends Command
{
    protected $signature = 'hub:import-agent-release
        {path : Path relative to the private local storage disk}
        {version : Three-part MSI ProductVersion}
        {--install-policy=manual : manual or automatic}
        {--notes=}
        {--actor= : Platform administrator email}
        {--publish : Publish immediately after verification}';

    protected $description = 'Import an Agent MSI already present in private storage and calculate its SHA-256';

    public function handle(AgentReleaseService $releases): int
    {
        $actorQuery = User::query()->where('is_platform_admin', true);
        if (is_string($this->option('actor')) && $this->option('actor') !== '') {
            $actorQuery->where('email', $this->option('actor'));
        }

        $actor = $actorQuery->first();
        if (! $actor instanceof User) {
            $this->error('A matching platform administrator is required.');

            return self::FAILURE;
        }

        $release = $releases->create([
            'version' => (string) $this->argument('version'),
            'path' => (string) $this->argument('path'),
            'install_policy' => (string) $this->option('install-policy'),
            'original_name' => basename((string) $this->argument('path')),
            'release_notes' => $this->option('notes'),
        ], $actor);

        if ($this->option('publish')) {
            $release = $releases->publish($release, $actor);
        }

        $this->info("Agent {$release->version} imported with status {$release->status}.");
        $this->line("SHA-256: {$release->sha256}");
        $this->line("Installation policy: {$release->install_policy}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Domain\Sources\Observers;

use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceAgentConfigInvalidator;

final class SourceConnectionConfigObserver
{
    public function __construct(private readonly SourceAgentConfigInvalidator $config) {}

    public function updated(SourceConnection $connection): void
    {
        if (! $connection->wasChanged(['pull_interval_s', 'is_active', 'identity_status'])) {
            return;
        }

        $this->config->forConnection($connection);
    }

    public function deleting(SourceConnection $connection): void
    {
        // Binding dihapus oleh cascade database, jadi Agent harus ditandai sebelum
        // relasi tersebut tidak lagi tersedia untuk menemukan printer terdampak.
        $this->config->forConnection($connection);
    }
}

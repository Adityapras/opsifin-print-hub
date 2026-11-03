<?php

namespace App\Domain\Audit\Observers;

use App\Domain\Audit\Services\AuditLogger;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class ConfigurationAuditObserver
{
    private const IGNORED_FIELDS = [
        'created_at',
        'updated_at',
        'last_ok_at',
        'last_error',
        'last_pulled_at',
        'next_pull_at',
        'last_seen_at',
        'raw_status',
        'queue_length',
    ];

    public function __construct(private readonly AuditLogger $logger) {}

    public function created(Model $model): void
    {
        $actor = $this->actor();

        if ($actor === null) {
            return;
        }

        $after = $this->withoutIgnored($model->getAttributes());
        $this->logger->record($actor, 'configuration.created', $model, [], $after);
    }

    public function updated(Model $model): void
    {
        $actor = $this->actor();

        if ($actor === null) {
            return;
        }

        $after = $this->withoutIgnored($model->getChanges());

        if ($after === []) {
            return;
        }

        $before = [];

        foreach (array_keys($after) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        $this->logger->record($actor, 'configuration.updated', $model, $before, $after);
    }

    public function deleted(Model $model): void
    {
        $actor = $this->actor();

        if ($actor === null) {
            return;
        }

        $this->logger->record(
            $actor,
            'configuration.deleted',
            $model,
            $this->withoutIgnored($model->getAttributes()),
            [],
        );
    }

    private function actor(): ?User
    {
        $actor = Auth::user();

        return $actor instanceof User ? $actor : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutIgnored(array $data): array
    {
        return array_diff_key($data, array_flip(self::IGNORED_FIELDS));
    }
}

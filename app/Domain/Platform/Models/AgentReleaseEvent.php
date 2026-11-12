<?php

namespace App\Domain\Platform\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $type
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $occurred_at
 */
class AgentReleaseEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'agent_release_id',
        'actor_id',
        'type',
        'data',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AgentRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(AgentRelease::class, 'agent_release_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

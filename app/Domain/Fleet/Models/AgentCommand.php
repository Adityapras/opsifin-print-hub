<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $type
 * @property string $status
 * @property array<string, mixed>|null $args
 */
class AgentCommand extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'agent_id',
        'type',
        'args',
        'status',
        'delivered_at',
        'finished_at',
        'result_message',
        'attachment_disk',
        'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'args' => 'array',
            'delivered_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasOne<AgentUpdateDeployment, $this> */
    public function updateDeployment(): HasOne
    {
        return $this->hasOne(AgentUpdateDeployment::class);
    }
}

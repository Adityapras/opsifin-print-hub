<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Platform\Models\AgentRelease;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $status
 * @property string $install_policy
 * @property int $progress_percent
 * @property string|null $message
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $installed_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $last_observed_at
 */
class AgentUpdateDeployment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const TERMINAL_STATUSES = ['installed', 'failed', 'cancelled'];

    protected $fillable = [
        'agent_release_id',
        'agent_id',
        'agent_command_id',
        'scheduled_by',
        'install_policy',
        'status',
        'progress_percent',
        'message',
        'scheduled_at',
        'delivered_at',
        'accepted_at',
        'installed_at',
        'failed_at',
        'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'installed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AgentRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(AgentRelease::class, 'agent_release_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<AgentCommand, $this> */
    public function command(): BelongsTo
    {
        return $this->belongsTo(AgentCommand::class, 'agent_command_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by');
    }
}

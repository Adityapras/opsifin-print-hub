<?php

namespace App\Domain\Jobs\Models;

use App\Domain\Fleet\Models\Agent;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $agent_id
 * @property int $attempt_no
 */
class PrintJobAttempt extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'job_id',
        'agent_id',
        'attempt_no',
        'spooler_job_id',
        'started_at',
        'spooled_at',
        'finished_at',
        'pages_printed',
        'outcome',
    ];

    protected function casts(): array
    {
        return [
            'attempt_no' => 'integer',
            'spooler_job_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'spooled_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'pages_printed' => 'integer',
        ];
    }

    /** @return BelongsTo<PrintJob, $this> */
    public function job(): BelongsTo
    {
        return $this->belongsTo(PrintJob::class, 'job_id');
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}

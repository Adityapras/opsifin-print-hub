<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $tenant_id
 * @property string $site_id
 * @property int $config_version
 * @property string $hostname
 * @property string $status
 * @property string $os
 * @property string $version
 * @property-read string $presence_status
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $revoked_at
 */
class Agent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'site_id',
        'hostname',
        'os',
        'version',
        'machine_id',
        'secret_hash',
        'config_version',
        'capabilities',
        'uptime_s',
        'agent_time',
        'last_seen_at',
        'status',
        'revoked_at',
    ];

    protected $hidden = [
        'secret_hash',
    ];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'capabilities' => 'array',
            'uptime_s' => 'integer',
            'agent_time' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return HasMany<Printer, $this> */
    public function printers(): HasMany
    {
        // Agent ID sudah menjadi batas relasi yang kuat untuk topology tenant.
        return $this->hasMany(Printer::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    public function getPresenceStatusAttribute(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->last_seen_at === null) {
            return 'offline';
        }

        if ($this->last_seen_at->greaterThan(now()->subSeconds(90))) {
            return 'online';
        }

        if ($this->last_seen_at->greaterThanOrEqualTo(now()->subSeconds(180))) {
            return 'stale';
        }

        return 'offline';
    }
}

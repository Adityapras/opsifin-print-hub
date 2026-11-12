<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $windows_name
 * @property string $agent_id
 * @property-read Agent|null $agent
 * @property string|null $alias
 * @property string $status
 * @property-read string $effective_status
 * @property CarbonImmutable|null $last_seen_at
 * @property bool $is_active
 * @property bool $is_paused
 * @property array<string, mixed>|null $print_settings
 */
class Printer extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const HEALTHY_REPORTED_STATUSES = ['ONLINE', 'READY', 'IDLE'];

    protected $fillable = [
        'agent_id',
        'windows_name',
        'alias',
        'driver',
        'port',
        'is_default',
        'status',
        'raw_status',
        'queue_length',
        'is_active',
        'is_paused',
        'print_settings',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'raw_status' => 'integer',
            'queue_length' => 'integer',
            'is_active' => 'boolean',
            'is_paused' => 'boolean',
            'print_settings' => 'array',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return HasMany<PrinterBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(PrinterBinding::class);
    }

    public function getEffectiveStatusAttribute(): string
    {
        $agent = $this->agent;

        if (! $agent instanceof Agent) {
            // Tanpa konteks tenant, relasi agent memang harus tersembunyi. Availability
            // dibuat fail-safe agar status mentah ONLINE tidak dianggap masih valid.
            return 'UNKNOWN';
        }

        return match ($agent->presence_status) {
            'revoked' => 'AGENT_REVOKED',
            'offline' => 'AGENT_OFFLINE',
            'stale' => 'AGENT_STALE',
            default => $this->status,
        };
    }

    /**
     * @param  Builder<Printer>  $query
     * @return Builder<Printer>
     */
    public function scopeWithEffectiveIssue(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNotIn('status', self::HEALTHY_REPORTED_STATUSES)
                ->orWhereHas('agent', function (Builder $query): void {
                    // Widget global berjalan tanpa TenantContext. Scope tenant relasi boleh
                    // dilepas hanya dengan korelasi tenant eksplisit agar tidak membaca Agent lain.
                    $query->withoutGlobalScope('tenant')
                        ->whereColumn('agents.tenant_id', 'printers.tenant_id')
                        ->where(function (Builder $query): void {
                            $query->whereNotNull('revoked_at')
                                ->orWhereNull('last_seen_at')
                                ->orWhere('last_seen_at', '<=', now()->subSeconds(Agent::ONLINE_THRESHOLD_SECONDS));
                        });
                });
        });
    }
}

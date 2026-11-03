<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $windows_name
 * @property string $agent_id
 * @property string|null $alias
 * @property string $status
 * @property bool $is_active
 * @property bool $is_paused
 * @property array<string, mixed>|null $print_settings
 */
class Printer extends Model
{
    use BelongsToTenant;
    use HasUuids;

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
}

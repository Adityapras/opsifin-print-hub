<?php

namespace App\Domain\Fleet\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Site extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'name',
        'address',
        'pic_name',
        'pic_phone',
        'timezone',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        // Relasi parent sudah mengunci site_id, sehingga scope tenant tambahan hanya
        // menghalangi rendering topology dari halaman master tenant global.
        return $this->hasMany(Agent::class)->withoutGlobalScope('tenant');
    }

    /** @return HasManyThrough<Printer, Agent, $this> */
    public function printers(): HasManyThrough
    {
        return $this->hasManyThrough(Printer::class, Agent::class)
            ->withoutGlobalScope('tenant');
    }
}

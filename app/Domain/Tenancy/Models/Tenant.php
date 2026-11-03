<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Delivery\Models\CallbackOutbox;
use App\Domain\Fleet\Models\Agent;
use App\Domain\Fleet\Models\Printer;
use App\Domain\Fleet\Models\Site;
use App\Domain\Identity\Models\Membership;
use App\Domain\Jobs\Models\PrintJob;
use App\Domain\Sources\Models\PrinterBinding;
use App\Domain\Sources\Models\SourceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'timezone',
        'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            // Identitas integrasi dibuat oleh Hub dan tidak menerima input form agar
            // tidak dapat tertukar dengan identitas client lain.
            $tenant->integration_id ??= (string) Str::uuid7();
        });

        static::deleting(function (Tenant $tenant): void {
            if (! $tenant->isForceDeleting()) {
                // Archive harus langsung menghentikan operasi tenant. Data tetap ada
                // untuk restore, histori print, dan audit internal.
                $tenant->forceFill(['status' => 'suspended'])->saveQuietly();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        // Semua URL admin memakai kode tenant agar link parent dan child resource
        // membentuk serta menyelesaikan identitas tenant dengan aturan yang sama.
        return 'code';
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<Agent, $this> */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<Printer, $this> */
    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<SourceConnection, $this> */
    public function sourceConnections(): HasMany
    {
        return $this->hasMany(SourceConnection::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<PrinterBinding, $this> */
    public function printerBindings(): HasMany
    {
        return $this->hasMany(PrinterBinding::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<PrintJob, $this> */
    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<CallbackOutbox, $this> */
    public function callbackOutboxes(): HasMany
    {
        return $this->hasMany(CallbackOutbox::class)->withoutGlobalScope('tenant');
    }

    /** @return HasMany<AuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class)->withoutGlobalScope('tenant');
    }

    /** @return BelongsToMany<User, $this, Membership, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'site_ids'])
            ->withTimestamps();
    }
}

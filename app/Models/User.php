<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Identity\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'is_platform_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Tenant, $this, Membership, 'pivot'> */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'memberships')
            ->using(Membership::class)
            ->withPivot(['id', 'role', 'site_ids'])
            ->withTimestamps();
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        // Halaman akses internal bersifat global; user_id sudah menjadi batas relasi.
        return $this->hasMany(Membership::class)->withoutGlobalScope('tenant');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin || $this->tenants()->exists();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Tenant) {
            return false;
        }

        return $this->is_platform_admin || $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    /** @return Collection<int, Tenant> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->is_platform_admin
            ? Tenant::query()->where('status', 'active')->orderBy('name')->get()
            : $this->tenants()->where('status', 'active')->orderBy('name')->get();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return $this->getTenants($panel)->first();
    }

    /** @param array<int, string> $roles */
    public function hasTenantRole(Tenant $tenant, array $roles): bool
    {
        return $this->is_platform_admin
            || $this->tenants()
                ->whereKey($tenant->getKey())
                ->wherePivotIn('role', $roles)
                ->exists();
    }
}

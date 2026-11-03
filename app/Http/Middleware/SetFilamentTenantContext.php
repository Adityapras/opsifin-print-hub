<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;

final class SetFilamentTenantContext
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Tenant) {
            $routeTenant = $request->route('tenant');

            $tenant = match (true) {
                $routeTenant instanceof Tenant => $routeTenant,
                is_string($routeTenant) => Tenant::query()->where('code', $routeTenant)->first(),
                default => null,
            };
        }

        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && ! $user->canAccessTenant($tenant)) {
            // Tolak sebelum konteks dipasang agar request internal tidak sempat
            // menjalankan query bisnis menggunakan identitas tenant yang salah.
            abort(404);
        }

        $this->tenantContext->set($tenant->getKey());

        if ($request->hasHeader('X-Livewire')) {
            // Persistent middleware Livewire selesai sebelum method komponen dipanggil.
            // Scoped binding akan dibuang di akhir request, sehingga konteks harus tetap
            // tersedia setelah pipeline pendahuluan ini selesai.
            return $next($request);
        }

        try {
            return $next($request);
        } finally {
            // Livewire dapat memakai worker yang sama untuk request tenant berbeda.
            $this->tenantContext->clear();
        }
    }
}

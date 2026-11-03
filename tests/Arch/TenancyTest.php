<?php

use App\Domain\Tenancy\Concerns\BelongsToTenant;

// Lapis kedua isolasi tenant (pengganti RLS, lihat docs/PRD.md bagian Multi-tenant).
// MySQL tidak punya Row Level Security, jadi aturan ini yang menjaga query lintas tenant.

arch('semua model bisnis fleet memakai scope tenant')
    ->expect([
        'App\Domain\Fleet\Models',
        'App\Domain\Sources\Models',
        'App\Domain\Jobs\Models',
        'App\Domain\Delivery\Models',
        'App\Domain\Identity\Models',
        'App\Domain\Audit\Models',
    ])
    ->toUseTrait(BelongsToTenant::class);

arch('query mentah hanya boleh di repository class')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Support\Facades\DB')
    ->ignoring([
        'App\Domain\Tenancy\Repositories',
        'App\Domain\Identity\Repositories',
        'App\Domain\Fleet\Repositories',
        'App\Domain\Sources\Repositories',
        'App\Domain\Jobs\Repositories',
        'App\Domain\Delivery\Repositories',
        'App\Domain\Notifications\Repositories',
        'App\Domain\Reporting\Repositories',
        'App\Domain\Audit\Repositories',
    ]);

arch('tidak ada helper debug yang ikut ter-commit')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

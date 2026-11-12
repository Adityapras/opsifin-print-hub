<?php

use App\Support\DateTimeDisplay;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('menampilkan waktu relatif beserta jam lokal', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 12:06:00 UTC'));

    expect(DateTimeDisplay::relativeWithClock(
        CarbonImmutable::parse('2026-11-11 12:00:00 UTC'),
        'Asia/Jakarta',
    ))->toBe('6 minutes ago · 19:00');
});

it('menambahkan tanggal ketika timestamp bukan hari yang sama', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 12:06:00 UTC'));

    expect(DateTimeDisplay::relativeWithClock(
        CarbonImmutable::parse('2026-11-10 12:00:00 UTC'),
        'Asia/Jakarta',
    ))->toBe('1 day ago · 10 Nov 2026, 19:00');
});

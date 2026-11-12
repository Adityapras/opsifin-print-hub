<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;

final class DateTimeDisplay
{
    public static function relativeWithClock(
        CarbonInterface|DateTimeInterface|string|null $value,
        string $timezone = 'UTC',
    ): string {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = $value instanceof CarbonInterface
            ? $value->toImmutable()
            : CarbonImmutable::parse($value);
        $local = $date->setTimezone($timezone);
        $absolute = $local->isToday()
            ? $local->format('H:i')
            : $local->format('d M Y, H:i');

        return $date->diffForHumans().' · '.$absolute;
    }
}

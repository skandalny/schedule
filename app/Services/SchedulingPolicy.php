<?php
declare(strict_types=1);

namespace App\Services;

final class SchedulingPolicy
{
    public const SHIFT_HOURS = 8.0;
    public const WEEKLY_TARGET_HOURS = 40.0;
    public const MAX_DAILY_HOURS = 8.0;
    public const MAX_WORK_DAYS_PER_WEEK = 5;
    public const MIN_DAYS_OFF_PER_WEEK = 2;

    public const SERVICE_START_HOUR = 8;
    public const SERVICE_END_HOUR = 24;

    // 08:00–16:00 ... 16:00–00:00.
    public static function startHours(): array
    {
        return range(8, 16);
    }

    public static function endHour(int $startHour): int
    {
        return $startHour + 8;
    }

    public static function formatTime(int $hour): string
    {
        return sprintf('%02d:00:00', $hour % 24);
    }

    public static function isLateShift(int $startHour): bool
    {
        return $startHour >= 14;
    }

    public static function isWeekend(string $date): bool
    {
        return (int)date('N', strtotime($date)) >= 6;
    }
}

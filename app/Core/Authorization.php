<?php
declare(strict_types=1);

namespace App\Core;

final class Authorization
{
    public static function canManageSchedule(): bool
    {
        $user = Auth::user();
        return $user !== null && in_array($user['role'], ['admin', 'editor'], true);
    }

    public static function canManageUsers(): bool
    {
        $user = Auth::user();
        return $user !== null && $user['role'] === 'admin';
    }

    public static function canEditOwnPreferences(): bool
    {
        return Auth::check();
    }
}

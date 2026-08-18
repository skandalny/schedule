<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Preference
{
    public static function get(int $userId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM employee_preferences WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function save(int $userId, array $data): void
    {
        $exists = self::get($userId);
        $days = $data['preferred_days_json'] ?? '[]';

        if ($exists) {
            $stmt = Database::pdo()->prepare(
                'UPDATE employee_preferences
                 SET preferred_shift_start = ?, preferred_shift_end = ?, weekend_mode = ?,
                     preferred_days_json = ?, min_hours_per_month = ?, max_hours_per_month = ?,
                     max_consecutive_days = ?, min_rest_hours = ?, notes = ?,
                     vacation_start = ?, vacation_end = ?
                 WHERE user_id = ?'
            );
            $stmt->execute([
                $data['preferred_shift_start'] ?: null,
                $data['preferred_shift_end'] ?: null,
                $data['weekend_mode'],
                $days,
                $data['min_hours_per_month'] ?: null,
                $data['max_hours_per_month'] ?: null,
                $data['max_consecutive_days'] ?: 5,
                $data['min_rest_hours'] !== '' ? $data['min_rest_hours'] : 8,
                $data['notes'] ?: null,
                $data['vacation_start'] ?: null,
                $data['vacation_end'] ?: null,
                $userId
            ]);
            return;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO employee_preferences
             (user_id, preferred_shift_start, preferred_shift_end, weekend_mode, preferred_days_json,
              min_hours_per_month, max_hours_per_month, max_consecutive_days, min_rest_hours, notes,
              vacation_start, vacation_end)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $data['preferred_shift_start'] ?: null,
            $data['preferred_shift_end'] ?: null,
            $data['weekend_mode'],
            $days,
            $data['min_hours_per_month'] ?: null,
            $data['max_hours_per_month'] ?: null,
            $data['max_consecutive_days'] ?: 5,
            $data['min_rest_hours'] !== '' ? $data['min_rest_hours'] : 8,
            $data['notes'] ?: null,
            $data['vacation_start'] ?: null,
            $data['vacation_end'] ?: null,
        ]);
    }

    public static function all(): array
    {
        $sql = "SELECT
                    u.id, u.name, u.email, u.role,
                    p.preferred_shift_start, p.preferred_shift_end, p.weekend_mode,
                    p.preferred_days_json, p.min_hours_per_month, p.max_hours_per_month,
                    p.max_consecutive_days, p.min_rest_hours, p.notes,
                    p.vacation_start, p.vacation_end
                FROM users u
                LEFT JOIN employee_preferences p ON p.user_id = u.id
                ORDER BY u.name ASC";
        return Database::pdo()->query($sql)->fetchAll();
    }

    public static function allForPeriod(): array
    {
        $sql = "SELECT
                    u.id, u.name, u.email, u.role,
                    p.preferred_shift_start, p.preferred_shift_end, p.weekend_mode,
                    p.preferred_days_json, p.min_hours_per_month, p.max_hours_per_month,
                    p.max_consecutive_days, p.min_rest_hours, p.notes,
                    p.vacation_start, p.vacation_end
                FROM users u
                LEFT JOIN employee_preferences p ON p.user_id = u.id
                WHERE u.role IN ('employee','admin','editor','viewer')
                ORDER BY u.name ASC";
        return Database::pdo()->query($sql)->fetchAll();
    }
}

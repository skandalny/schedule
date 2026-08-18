<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class SchedulePeriod
{
    public static function findOrCreate(int $year, int $month, int $createdBy): int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM schedule_periods WHERE year = ? AND month = ? LIMIT 1');
        $stmt->execute([$year, $month]);
        $id = $stmt->fetchColumn();
        if ($id) return (int) $id;

        $title = sprintf('%04d-%02d', $year, $month);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO schedule_periods (title, year, month, created_by) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$title, $year, $month, $createdBy]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM schedule_periods WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM schedule_periods ORDER BY year DESC, month DESC'
        )->fetchAll();
    }

    public static function requirements(int $periodId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM shift_requirements WHERE period_id = ? ORDER BY work_date, start_time'
        );
        $stmt->execute([$periodId]);
        return $stmt->fetchAll();
    }

    public static function shifts(int $periodId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*, u.name FROM schedule_shifts s JOIN users u ON u.id = s.user_id
             WHERE s.period_id = ? ORDER BY s.work_date, s.start_time, u.name'
        );
        $stmt->execute([$periodId]);
        return $stmt->fetchAll();
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Authorization;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\View;
use App\Core\Database;
use App\Models\SchedulePeriod;
use App\Models\Preference;
use App\Models\User;
use App\Services\ScheduleGenerator;

final class ScheduleController
{
    public function page(): void
    {
        Auth::requireAuth();

        View::render('schedule/index', [
            'title' => 'График',
            'user' => Auth::user(),
            'csrf' => Csrf::token(),
        ]);
    }

    public function periods(): never
    {
        Auth::requireAuth();
        Response::json(['periods' => SchedulePeriod::all()]);
    }

    public function createPeriod(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $year = (int)($_POST['year'] ?? 0);
        $month = (int)($_POST['month'] ?? 0);
        if ($year < 2024 || $month < 1 || $month > 12) Response::json(['error' => 'Некорректный месяц'], 422);

        $id = SchedulePeriod::findOrCreate($year, $month, (int)Auth::user()['id']);
        Response::json(['ok' => true, 'id' => $id]);
    }

    public function data(): never
    {
        Auth::requireAuth();
        $periodId = (int)($_GET['period_id'] ?? 0);
        $period = SchedulePeriod::find($periodId);
        if (!$period) Response::json(['error' => 'Период не найден'], 404);

        $currentUser = Auth::user();
        $preferences = in_array($currentUser['role'], ['admin', 'editor'], true)
            ? Preference::all()
            : array_values(array_filter(
                Preference::all(),
                static fn(array $item): bool => (int)$item['id'] === (int)$currentUser['id']
            ));

        Response::json([
            'period' => $period,
            'current_user' => $currentUser,
            'employees' => User::allEmployees(),
            'preferences' => $preferences,
            'requirements' => SchedulePeriod::requirements($periodId),
            'shifts' => SchedulePeriod::shifts($periodId),
            'csrf' => Csrf::token(),
        ]);
    }

    public function saveRequirement(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $periodId = (int)$_POST['period_id'];
        $date = trim((string)($_POST['work_date'] ?? ''));
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end = trim((string)($_POST['end_time'] ?? ''));
        $staff = max(1, (int)($_POST['required_staff'] ?? 1));

        if (!$periodId || !$date || !$start || !$end) Response::json(['error' => 'Заполните все поля'], 422);

        $stmt = Database::pdo()->prepare(
            'INSERT INTO shift_requirements (period_id, work_date, start_time, end_time, required_staff)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$periodId, $date, $start, $end, $staff]);

        Response::json(['ok' => true]);
    }

    public function deleteRequirement(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $id = (int)$_POST['id'];
        $stmt = Database::pdo()->prepare('DELETE FROM shift_requirements WHERE id = ?');
        $stmt->execute([$id]);
        Response::json(['ok' => true]);
    }

    public function generate(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        try {
            $result = ScheduleGenerator::generate((int)$_POST['period_id'], (int)Auth::user()['id']);
            Response::json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 422);
        }
    }

    public function savePreference(): never
    {
        Auth::requireAuth();
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $userId = (int)Auth::user()['id'];
        if (Authorization::canManageUsers() && isset($_POST['user_id'])) {
            $userId = (int)$_POST['user_id'];
        }

        $days = $_POST['preferred_days'] ?? [];
        if (!is_array($days)) $days = [];

        Preference::save($userId, [
            'preferred_shift_start' => trim((string)($_POST['preferred_shift_start'] ?? '')),
            'preferred_shift_end' => trim((string)($_POST['preferred_shift_end'] ?? '')),
            'weekend_mode' => in_array($_POST['weekend_mode'] ?? 'any', ['together','separate','any'], true)
                ? $_POST['weekend_mode'] : 'any',
            'preferred_days_json' => json_encode(array_values(array_map('intval', $days))),
            'min_hours_per_month' => trim((string)($_POST['min_hours_per_month'] ?? '')),
            'max_hours_per_month' => trim((string)($_POST['max_hours_per_month'] ?? '')),
            'max_consecutive_days' => (int)($_POST['max_consecutive_days'] ?? 5),
            'min_rest_hours' => trim((string)($_POST['min_rest_hours'] ?? '8')),
            'notes' => trim((string)($_POST['notes'] ?? '')),
        ]);

        Response::json(['ok' => true]);
    }


    public function weeklyRequirements(): never
    {
        Auth::requireAuth();
        $stmt = Database::pdo()->query(
            'SELECT id, weekday, start_time, end_time, required_staff, label
             FROM weekly_requirements
             ORDER BY weekday, start_time'
        );
        Response::json(['requirements' => $stmt->fetchAll()]);
    }

    public function saveWeeklyRequirement(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $weekday = (int)($_POST['weekday'] ?? 0);
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end = trim((string)($_POST['end_time'] ?? ''));
        $staff = max(1, (int)($_POST['required_staff'] ?? 1));
        $label = trim((string)($_POST['label'] ?? ''));

        if ($weekday < 1 || $weekday > 7 || !$start || !$end) {
            Response::json(['error' => 'Некорректный шаблон'], 422);
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO weekly_requirements
             (weekday, start_time, end_time, required_staff, label, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$weekday, $start, $end, $staff, $label ?: null, (int)Auth::user()['id']]);

        Response::json(['ok' => true]);
    }

    public function deleteWeeklyRequirement(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $stmt = Database::pdo()->prepare('DELETE FROM weekly_requirements WHERE id = ?');
        $stmt->execute([(int)($_POST['id'] ?? 0)]);
        Response::json(['ok' => true]);
    }

    public function applyWeeklyRequirements(): never
    {
        Auth::requireAuth();
        if (!Authorization::canManageSchedule()) Response::json(['error' => 'Нет прав'], 403);
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $periodId = (int)($_POST['period_id'] ?? 0);
        $period = SchedulePeriod::find($periodId);
        if (!$period) Response::json(['error' => 'Период не найден'], 404);

        $templates = Database::pdo()->query(
            'SELECT weekday, start_time, end_time, required_staff
             FROM weekly_requirements
             ORDER BY weekday, start_time'
        )->fetchAll();

        if (!$templates) {
            Response::json(['error' => 'Недельный шаблон пока пуст'], 422);
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM shift_requirements WHERE period_id = ?');
            $delete->execute([$periodId]);

            $insert = $pdo->prepare(
                'INSERT INTO shift_requirements
                 (period_id, work_date, start_time, end_time, required_staff)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $days = cal_days_in_month(CAL_GREGORIAN, (int)$period['month'], (int)$period['year']);
            $count = 0;

            for ($day = 1; $day <= $days; $day++) {
                $date = sprintf('%04d-%02d-%02d', $period['year'], $period['month'], $day);
                $weekday = (int)date('N', strtotime($date));

                foreach ($templates as $tpl) {
                    if ((int)$tpl['weekday'] !== $weekday) continue;
                    $insert->execute([
                        $periodId,
                        $date,
                        $tpl['start_time'],
                        $tpl['end_time'],
                        (int)$tpl['required_staff']
                    ]);
                    $count++;
                }
            }

            $pdo->commit();
            Response::json(['ok' => true, 'count' => $count]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function wishes(): never
    {
        Auth::requireAuth();
        Response::json(['preferences' => Preference::allForPeriod()]);
    }

    public function addUnavailability(): never
    {
        Auth::requireAuth();
        if (!Csrf::verify($_POST['_csrf'] ?? null)) Response::json(['error' => 'CSRF'], 419);

        $userId = (int)Auth::user()['id'];
        if (Authorization::canManageUsers() && isset($_POST['user_id'])) $userId = (int)$_POST['user_id'];

        $stmt = Database::pdo()->prepare(
            'INSERT INTO availability_blocks
             (user_id, period_id, date_from, date_to, start_time, end_time, is_available, reason)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $userId,
            (int)$_POST['period_id'],
            $_POST['date_from'],
            $_POST['date_to'],
            $_POST['start_time'] !== '' ? $_POST['start_time'] : null,
            $_POST['end_time'] !== '' ? $_POST['end_time'] : null,
            trim((string)($_POST['reason'] ?? '')),
        ]);

        Response::json(['ok' => true]);
    }
}

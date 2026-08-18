<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Preference;
use App\Models\SchedulePeriod;

final class ScheduleGenerator
{
    /**
     * Генерация выполняется в два логических этапа:
     *
     * 1. На каждый день определяется необходимое количество 8-часовых смен.
     * 2. Эти смены распределяются по сотрудникам с учетом:
     *    - максимум 1 смены в день;
     *    - максимум 5 рабочих дней в ISO-неделю;
     *    - минимум 2 выходных;
     *    - отпуска;
     *    - минимального отдыха;
     *    - желаемого времени;
     *    - пожеланий по выходным;
     *    - равномерности поздних/вечерних смен;
     *    - равномерности выходных смен;
     *    - стремления к 40 часам в неделю.
     *
     * Пожелание "выходные Сб/Вс" — НЕ жесткое. Если потребность
     * требует работу в субботу или воскресенье, часть сотрудников
     * будет выведена на выходные, а им будут даны выходные в другие дни.
     */
    public static function generate(int $periodId, int $actorId): array
    {
        $pdo = Database::pdo();
        $period = SchedulePeriod::find($periodId);

        if (!$period) {
            throw new \InvalidArgumentException('Период не найден');
        }

        $requirements = SchedulePeriod::requirements($periodId);
        $employees = Preference::all();

        if (!$employees) {
            throw new \RuntimeException('Нет сотрудников для построения графика');
        }

        $daysInMonth = cal_days_in_month(
            CAL_GREGORIAN,
            (int)$period['month'],
            (int)$period['year']
        );

        // Сначала удаляем автоматически созданные смены этого месяца.
        // Ручные смены сохраняем: генератор обязан учитывать их как занятость.
        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare(
                'DELETE FROM schedule_shifts
                 WHERE period_id = ? AND is_manual = 0'
            );
            $delete->execute([$periodId]);

            /*
             * Важная часть: загружаем существующие смены вокруг месяца.
             * Это позволяет учитывать:
             * - предыдущую неделю;
             * - последнюю неделю месяца;
             * - ручные смены;
             * - уже опубликованные смены соседнего периода.
             */
            $monthStart = sprintf(
                '%04d-%02d-01',
                (int)$period['year'],
                (int)$period['month']
            );
            $monthEnd = sprintf(
                '%04d-%02d-%02d',
                (int)$period['year'],
                (int)$period['month'],
                $daysInMonth
            );

            $rangeStart = date('Y-m-d', strtotime($monthStart . ' -7 days'));
            $rangeEnd = date('Y-m-d', strtotime($monthEnd . ' +7 days'));

            $existingStmt = $pdo->prepare(
                'SELECT user_id, work_date, start_time, end_time, period_id
                 FROM schedule_shifts
                 WHERE work_date BETWEEN ? AND ?
                 ORDER BY work_date, start_time'
            );
            $existingStmt->execute([$rangeStart, $rangeEnd]);
            $existingRows = $existingStmt->fetchAll();

            $existingWork = [];
            $existingWeekDays = [];
            $existingWeekHours = [];
            $existingLate = [];
            $existingWeekend = [];

            foreach ($employees as $employee) {
                $uid = (int)$employee['id'];
                $existingWork[$uid] = [];
                $existingWeekDays[$uid] = [];
                $existingWeekHours[$uid] = [];
                $existingLate[$uid] = 0;
                $existingWeekend[$uid] = 0;
            }

            foreach ($existingRows as $row) {
                $uid = (int)$row['user_id'];
                if (!isset($existingWork[$uid])) {
                    $existingWork[$uid] = [];
                    $existingWeekDays[$uid] = [];
                    $existingWeekHours[$uid] = [];
                    $existingLate[$uid] = 0;
                    $existingWeekend[$uid] = 0;
                }

                $date = $row['work_date'];
                $weekKey = date('o-W', strtotime($date));

                $existingWork[$uid][$date] = true;
                $existingWeekDays[$uid][$weekKey][$date] = true;

                $startHour = (int)substr($row['start_time'], 0, 2);
                $endHour = (int)substr($row['end_time'], 0, 2);
                if ($endHour === 0) $endHour = 24;
                $hours = $endHour - $startHour;
                if ($hours <= 0) $hours = 8;

                $existingWeekHours[$uid][$weekKey] =
                    ($existingWeekHours[$uid][$weekKey] ?? 0) + $hours;

                if (SchedulingPolicy::isLateShift($startHour)) {
                    $existingLate[$uid]++;
                }
                if (SchedulingPolicy::isWeekend($date)) {
                    $existingWeekend[$uid]++;
                }
            }

            // Индексы потребности по дню.
            $requirementsByDate = [];
            foreach ($requirements as $req) {
                $requirementsByDate[$req['work_date']][] = $req;
            }

            $hoursByUser = [];
            $weekHoursByUser = [];
            $workedDaysByUserWeek = [];
            $workedDatesByUser = [];
            $lateShiftsByUser = $existingLate;
            $weekendShiftsByUser = $existingWeekend;

            foreach ($employees as $employee) {
                $uid = (int)$employee['id'];
                $hoursByUser[$uid] = 0.0;
                $weekHoursByUser[$uid] = $existingWeekHours[$uid] ?? [];
                $workedDaysByUserWeek[$uid] = $existingWeekDays[$uid] ?? [];
                $workedDatesByUser[$uid] = $existingWork[$uid] ?? [];
            }

            $assignedShifts = 0;
            $uncoveredHours = 0.0;
            $score = 0.0;

            /*
             * Группируем даты по ISO-неделям. Внутри каждой недели
             * сначала обрабатываем наиболее "дефицитные" дни,
             * чтобы выходные не терялись после Пн–Пт.
             */
            $dates = [];
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $date = sprintf(
                    '%04d-%02d-%02d',
                    (int)$period['year'],
                    (int)$period['month'],
                    $day
                );
                $dates[] = $date;
            }

            $weeks = [];
            foreach ($dates as $date) {
                $weekKey = date('o-W', strtotime($date));
                $weeks[$weekKey][] = $date;
            }

            foreach ($weeks as $weekKey => $weekDates) {
                $dayPlans = [];

                // Строим "слоты смен" на каждый день.
                foreach ($weekDates as $date) {
                    $need = self::buildHourlyNeed($requirementsByDate[$date] ?? []);

                    if (!array_sum($need)) {
                        $dayPlans[$date] = [
                            'slots' => [],
                            'need' => $need,
                            'priority' => 0,
                        ];
                        continue;
                    }

                    $maxNeed = max($need);
                    $personHours = array_sum($need);

                    // Минимум работников по пиковому часу и по общему объёму.
                    $slotCount = max(
                        $maxNeed,
                        (int)ceil($personHours / 8)
                    );

                    $slotCount = min($slotCount, count($employees));

                    $slots = self::buildShiftSlots($need, $slotCount);

                    // Чем больше дефицит и чем сильнее потребность в разных часах,
                    // тем раньше обрабатываем день.
                    $remaining = array_sum($need);
                    $priority = ($maxNeed * 100) + $remaining;

                    if (SchedulingPolicy::isWeekend($date)) {
                        // Выходные не должны автоматически проигрывать будням.
                        $priority += 35;
                    }

                    $dayPlans[$date] = [
                        'slots' => $slots,
                        'need' => $need,
                        'priority' => $priority,
                    ];
                }

                uasort(
                    $dayPlans,
                    static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']
                );

                /*
                 * Ключевой момент: каждый dayPlan содержит конкретные смены,
                 * а сотрудник выбирается только после того, как мы знаем:
                 * - сколько смен нужно на этот день;
                 * - кому сегодня можно работать;
                 * - сколько дней у него уже занято на этой ISO-неделе.
                 *
                 * Поэтому невозможно заполнить Пн–Пт и потом "обнаружить",
                 * что на выходные никого не осталось.
                 */
                foreach ($dayPlans as $date => $plan) {
                    foreach ($plan['slots'] as $slot) {
                        $best = self::chooseEmployee(
                            $pdo,
                            $employees,
                            $date,
                            $weekKey,
                            $slot['start_hour'],
                            $slot['end_hour'],
                            $workedDatesByUser,
                            $workedDaysByUserWeek,
                            $weekHoursByUser,
                            $lateShiftsByUser,
                            $weekendShiftsByUser
                        );

                        if ($best === null) {
                            $uncoveredHours += 8;
                            continue;
                        }

                        $uid = (int)$best['employee']['id'];

                        $startTime = SchedulingPolicy::formatTime($slot['start_hour']);
                        $endTime = $slot['end_hour'] === 24
                            ? '00:00:00'
                            : SchedulingPolicy::formatTime($slot['end_hour']);

                        /*
                         * Жёсткая серверная проверка перед INSERT:
                         * 1 смена/день.
                         */
                        if (isset($workedDatesByUser[$uid][$date])) {
                            throw new \RuntimeException(
                                'Генератор попытался назначить вторую смену сотруднику за один день.'
                            );
                        }

                        $insert = $pdo->prepare(
                            'INSERT INTO schedule_shifts
                             (period_id, user_id, work_date, start_time, end_time, is_manual, created_by)
                             VALUES (?, ?, ?, ?, ?, 0, ?)'
                        );

                        $insert->execute([
                            $periodId,
                            $uid,
                            $date,
                            $startTime,
                            $endTime,
                            $actorId,
                        ]);

                        $workedDatesByUser[$uid][$date] = true;
                        $workedDaysByUserWeek[$uid][$weekKey][$date] = true;

                        $weekHoursByUser[$uid][$weekKey] =
                            ($weekHoursByUser[$uid][$weekKey] ?? 0) + 8;

                        $hoursByUser[$uid] += 8;

                        if (SchedulingPolicy::isLateShift($slot['start_hour'])) {
                            $lateShiftsByUser[$uid]++;
                        }

                        if (SchedulingPolicy::isWeekend($date)) {
                            $weekendShiftsByUser[$uid]++;
                        }

                        $assignedShifts++;
                        $score += $best['score'];
                    }
                }

                /*
                 * После распределения всех слотов считаем реальный остаток
                 * по часовому покрытию.
                 */
                foreach ($weekDates as $date) {
                    $need = self::buildHourlyNeed($requirementsByDate[$date] ?? []);
                    if (!$need) continue;

                    $shifts = self::loadShiftsForDate(
                        $pdo,
                        $periodId,
                        $date
                    );

                    foreach ($need as $hour => $required) {
                        $covered = 0;

                        foreach ($shifts as $shift) {
                            if (self::shiftCoversHour($shift, $hour)) {
                                $covered++;
                            }
                        }

                        if ($covered < $required) {
                            $uncoveredHours += ($required - $covered);
                        }
                    }
                }
            }

            self::validateGeneratedSchedule(
                $pdo,
                $periodId,
                $employees
            );

            $summary = [
                'period_id' => $periodId,
                'score' => round($score, 2),
                'uncovered_hours' => round($uncoveredHours, 2),
                'assigned_shifts' => $assignedShifts,
                'hours_by_user' => $hoursByUser,
                'policy' => [
                    'shift_hours' => 8,
                    'weekly_target_hours' => 40,
                    'max_daily_hours' => 8,
                    'max_work_days_per_week' => 5,
                    'min_days_off_per_week' => 2,
                ],
            ];

            $run = $pdo->prepare(
                'INSERT INTO generation_runs
                 (period_id, started_by, score, uncovered_hours, assigned_shifts)
                 VALUES (?, ?, ?, ?, ?)'
            );

            $run->execute([
                $periodId,
                $actorId,
                $summary['score'],
                $summary['uncovered_hours'],
                $assignedShifts,
            ]);

            $pdo->commit();

            return $summary;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function buildHourlyNeed(array $requirements): array
    {
        $need = array_fill(0, 24, 0);

        foreach ($requirements as $req) {
            $startHour = (int)substr($req['start_time'], 0, 2);
            $endHour = (int)substr($req['end_time'], 0, 2);

            if ($req['end_time'] === '00:00:00' || substr($req['end_time'], 0, 5) === '00:00') {
                $endHour = 24;
            }

            if ($endHour <= $startHour) {
                for ($h = $startHour; $h < 24; $h++) {
                    $need[$h] = max($need[$h], (int)$req['required_staff']);
                }
                for ($h = 0; $h < $endHour; $h++) {
                    $need[$h] = max($need[$h], (int)$req['required_staff']);
                }
            } else {
                for ($h = $startHour; $h < $endHour; $h++) {
                    if ($h >= 8 && $h < 24) {
                        $need[$h] = max($need[$h], (int)$req['required_staff']);
                    }
                }
            }
        }

        for ($h = 0; $h < 8; $h++) {
            $need[$h] = 0;
        }

        return $need;
    }

    /**
     * Возвращает конкретный набор 8-часовых смен, который максимально закрывает
     * текущую потребность. Несколько одинаковых смен разрешены — они будут
     * назначены разным сотрудникам.
     */
    private static function buildShiftSlots(array $need, int $slotCount): array
    {
        $work = $need;
        $slots = [];

        for ($i = 0; $i < $slotCount; $i++) {
            $bestStart = null;
            $bestGain = 0;

            foreach (SchedulingPolicy::startHours() as $startHour) {
                $endHour = $startHour + 8;
                if ($endHour > 24) {
                    continue;
                }

                $gain = 0;

                for ($h = $startHour; $h < $endHour; $h++) {
                    $gain += $work[$h] ?? 0;
                }

                if ($gain > $bestGain) {
                    $bestGain = $gain;
                    $bestStart = $startHour;
                }
            }

            if ($bestStart === null || $bestGain <= 0) {
                break;
            }

            $bestEnd = $bestStart + 8;

            for ($h = $bestStart; $h < $bestEnd; $h++) {
                if (($work[$h] ?? 0) > 0) {
                    $work[$h] = max(0, $work[$h] - 1);
                }
            }

            $slots[] = [
                'start_hour' => $bestStart,
                'end_hour' => $bestEnd,
            ];
        }

        /*
         * Если одна комбинация создаёт перекос, сортируем смены по началу:
         * ранние сначала, поздние потом. Сотрудники уже будут подобраны
         * с учетом справедливости поздних смен.
         */
        usort(
            $slots,
            static fn(array $a, array $b): int => $a['start_hour'] <=> $b['start_hour']
        );

        return $slots;
    }

    private static function chooseEmployee(
        \PDO $pdo,
        array $employees,
        string $date,
        string $weekKey,
        int $startHour,
        int $endHour,
        array $workedDatesByUser,
        array $workedDaysByUserWeek,
        array $weekHoursByUser,
        array $lateShiftsByUser,
        array $weekendShiftsByUser
    ): ?array {
        $candidates = [];

        foreach ($employees as $employee) {
            $uid = (int)$employee['id'];

            // Нельзя две смены в один день.
            if (isset($workedDatesByUser[$uid][$date])) {
                continue;
            }

            // Максимум 5 рабочих дней в ISO-неделю.
            $weekDays = $workedDaysByUserWeek[$uid][$weekKey] ?? [];
            if (count($weekDays) >= SchedulingPolicy::MAX_WORK_DAYS_PER_WEEK) {
                continue;
            }

            // Жесткий отпуск.
            if (
                !empty($employee['vacation_start']) &&
                !empty($employee['vacation_end']) &&
                $date >= $employee['vacation_start'] &&
                $date <= $employee['vacation_end']
            ) {
                continue;
            }

            // Минимум отдыха между сменами.
            $minRest = $employee['min_rest_hours'] !== null
                ? (float)$employee['min_rest_hours']
                : 8.0;

            if (!self::passesRestRule(
                $pdo,
                $uid,
                $date,
                $startHour,
                $minRest
            )) {
                continue;
            }

            // Максимум последовательных рабочих дней.
            $maxConsecutive = (int)($employee['max_consecutive_days'] ?: 5);
            if (self::wouldBreakConsecutiveLimit(
                $workedDatesByUser[$uid] ?? [],
                $date,
                $maxConsecutive
            )) {
                continue;
            }

            $weekHours = (float)($weekHoursByUser[$uid][$weekKey] ?? 0);
            $lateCount = (int)($lateShiftsByUser[$uid] ?? 0);
            $weekendCount = (int)($weekendShiftsByUser[$uid] ?? 0);

            $score = 0.0;

            // Сильный приоритет тем, кто еще далеко от 40 часов.
            $score += max(0, 40 - $weekHours) * 1.8;

            // Не даем одним людям постоянно получать вечер.
            if (SchedulingPolicy::isLateShift($startHour)) {
                $score -= $lateCount * 7.5;
            }

            // И выходные распределяем между людьми.
            if (SchedulingPolicy::isWeekend($date)) {
                $score -= $weekendCount * 6.0;
            }

            // Предпочтительное время — важный, но не абсолютный критерий.
            if (!empty($employee['preferred_shift_start'])) {
                $preferredHour = (int)substr($employee['preferred_shift_start'], 0, 2);
                $distance = abs($preferredHour - $startHour);
                $score += max(0, 15 - $distance * 2);
            }

            // Предпочтительный день недели.
            $preferredDays = json_decode(
                (string)($employee['preferred_days_json'] ?? '[]'),
                true
            );

            $weekday = (int)date('N', strtotime($date));

            if (
                is_array($preferredDays) &&
                in_array($weekday, array_map('intval', $preferredDays), true)
            ) {
                $score += 7;
            }

            // "Сб/Вс" как желаемые выходные — это мягкий минус,
            // но не запрет. Если требуется покрытие, человек все равно будет
            // поставлен на выходной, а другие дни недели станут его выходными.
            if ($weekday >= 6) {
                if (($employee['weekend_mode'] ?? 'any') === 'together') {
                    $score -= 8;
                }
            }

            // Немного награждаем раздельные выходные за непохожесть с предыдущим днем.
            if (($employee['weekend_mode'] ?? 'any') === 'separate') {
                $previous = date('Y-m-d', strtotime($date . ' -1 day'));
                if (!isset($workedDatesByUser[$uid][$previous])) {
                    $score -= 2;
                }
            }

            // Справедливость по общему количеству часов за месяц.
            $monthHours = 0.0;
            foreach ($weekHoursByUser[$uid] as $h) {
                $monthHours += (float)$h;
            }
            $score -= $monthHours * 0.08;

            // Детеминированный tie-breaker.
            $score += (($uid % 17) / 1000);

            $candidates[] = [
                'employee' => $employee,
                'score' => $score,
            ];
        }

        if (!$candidates) {
            return null;
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => $b['score'] <=> $a['score']
        );

        return $candidates[0];
    }

    private static function passesRestRule(
        \PDO $pdo,
        int $userId,
        string $date,
        int $startHour,
        float $minRest
    ): bool {
        $stmt = $pdo->prepare(
            'SELECT work_date, end_time
             FROM schedule_shifts
             WHERE user_id = ?
             ORDER BY work_date DESC, end_time DESC
             LIMIT 20'
        );
        $stmt->execute([$userId]);

        $newStart = strtotime(
            $date . ' ' . sprintf('%02d:00:00', $startHour)
        );

        foreach ($stmt->fetchAll() as $last) {
            $lastEnd = strtotime(
                $last['work_date'] . ' ' . $last['end_time']
            );

            if ($last['end_time'] === '00:00:00') {
                $lastEnd += 86400;
            }

            // Ищем ближайшую прошлую смену.
            if ($lastEnd <= $newStart) {
                return (($newStart - $lastEnd) / 3600) >= $minRest;
            }
        }

        return true;
    }

    private static function wouldBreakConsecutiveLimit(
        array $workedDates,
        string $date,
        int $maxConsecutive
    ): bool {
        if ($maxConsecutive <= 0) {
            return false;
        }

        $count = 1;
        $cursor = strtotime($date . ' -1 day');

        while ($count <= $maxConsecutive) {
            $previous = date('Y-m-d', $cursor);

            if (!isset($workedDates[$previous])) {
                break;
            }

            $count++;
            $cursor -= 86400;
        }

        return $count > $maxConsecutive;
    }

    private static function shiftCoversHour(array $shift, int $hour): bool
    {
        $start = (int)substr($shift['start_time'], 0, 2);
        $end = (int)substr($shift['end_time'], 0, 2);

        if ($end === 0) {
            $end = 24;
        }

        if ($end <= $start) {
            return $hour >= $start || $hour < $end;
        }

        return $hour >= $start && $hour < $end;
    }

    private static function loadShiftsForDate(
        \PDO $pdo,
        int $periodId,
        string $date
    ): array {
        $stmt = $pdo->prepare(
            'SELECT * FROM schedule_shifts
             WHERE work_date = ?
             ORDER BY start_time'
        );
        $stmt->execute([$date]);

        return $stmt->fetchAll();
    }

    private static function validateGeneratedSchedule(
        \PDO $pdo,
        int $periodId,
        array $employees
    ): void {
        // 1. Ни одного сотрудника с двумя сменами в один день.
        $stmt = $pdo->prepare(
            'SELECT user_id, work_date, COUNT(*) AS cnt
             FROM schedule_shifts
             WHERE period_id = ?
             GROUP BY user_id, work_date
             HAVING COUNT(*) > 1
             LIMIT 1'
        );
        $stmt->execute([$periodId]);

        if ($stmt->fetch()) {
            throw new \RuntimeException(
                'Генерация остановлена: обнаружены две смены одного сотрудника в один день.'
            );
        }

        // 2. Не более 5 рабочих дней в ISO-неделю.
        $stmt = $pdo->prepare(
            "SELECT user_id, YEARWEEK(work_date, 3) AS iso_week,
                    COUNT(DISTINCT work_date) AS work_days
             FROM schedule_shifts
             WHERE work_date BETWEEN
                (SELECT CONCAT(year, '-', LPAD(month, 2, '0'), '-01')
                 FROM schedule_periods WHERE id = ?)
                AND
                LAST_DAY(
                    (SELECT CONCAT(year, '-', LPAD(month, 2, '0'), '-01')
                     FROM schedule_periods WHERE id = ?)
                )
             GROUP BY user_id, YEARWEEK(work_date, 3)
             HAVING COUNT(DISTINCT work_date) > 5
             LIMIT 1"
        );
        $stmt->execute([$periodId, $periodId]);

        if ($stmt->fetch()) {
            throw new \RuntimeException(
                'Генерация остановлена: сотруднику назначено более 5 рабочих дней в неделю.'
            );
        }

        // 3. Все смены автоматически имеют 8 часов.
        $stmt = $pdo->prepare(
            'SELECT id
             FROM schedule_shifts
             WHERE period_id = ?
               AND is_manual = 0
               AND (
                   TIMESTAMPDIFF(
                       MINUTE,
                       CONCAT(work_date, " ", start_time),
                       CASE
                           WHEN end_time = "00:00:00"
                           THEN DATE_ADD(CONCAT(work_date, " ", end_time), INTERVAL 1 DAY)
                           ELSE CONCAT(work_date, " ", end_time)
                       END
                   ) <> 480
               )
             LIMIT 1'
        );
        $stmt->execute([$periodId]);

        if ($stmt->fetch()) {
            throw new \RuntimeException(
                'Генерация остановлена: автоматически создана смена не на 8 часов.'
            );
        }

        // 4. Отпуск не должен содержать смены.
        $stmt = $pdo->prepare(
            'SELECT s.id
             FROM schedule_shifts s
             JOIN employee_preferences p ON p.user_id = s.user_id
             WHERE s.period_id = ?
               AND p.vacation_start IS NOT NULL
               AND p.vacation_end IS NOT NULL
               AND s.work_date BETWEEN p.vacation_start AND p.vacation_end
             LIMIT 1'
        );

        try {
            $stmt->execute([$periodId]);
            if ($stmt->fetch()) {
                throw new \RuntimeException(
                    'Генерация остановлена: в период отпуска назначена смена.'
                );
            }
        } catch (\PDOException $e) {
            // Для старой базы без vacation_* не ломаем генерацию здесь.
            if (strpos($e->getMessage(), 'vacation_start') === false) {
                throw $e;
            }
        }
    }
}

-- Workforce Scheduler v5
-- Выполнить ПОСЛЕ upgrade_v4.sql в уже существующей базе.
-- Добавляет отпуск сотрудника.
-- NULL в обоих полях = отпуска нет.

ALTER TABLE employee_preferences
    ADD COLUMN vacation_start DATE NULL AFTER notes,
    ADD COLUMN vacation_end DATE NULL AFTER vacation_start;

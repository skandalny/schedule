-- Для уже установленной версии проекта.
-- Выполните этот файл в выбранной базе Beget.
ALTER TABLE schedule_periods
    ADD UNIQUE KEY uq_schedule_periods_year_month (year, month);

ALTER TABLE employee_preferences
    ADD COLUMN min_hours_per_month DECIMAL(6,2) NULL AFTER preferred_days_json,
    ADD COLUMN max_hours_per_month DECIMAL(6,2) NULL AFTER min_hours_per_month,
    ADD COLUMN max_consecutive_days SMALLINT UNSIGNED NULL DEFAULT 5 AFTER max_hours_per_month,
    ADD COLUMN min_rest_hours DECIMAL(5,2) NULL DEFAULT 8 AFTER max_consecutive_days;

CREATE TABLE IF NOT EXISTS generation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    period_id BIGINT UNSIGNED NOT NULL,
    started_by BIGINT UNSIGNED NULL,
    score DECIMAL(12,2) NOT NULL DEFAULT 0,
    uncovered_hours DECIMAL(12,2) NOT NULL DEFAULT 0,
    assigned_shifts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_generation_runs_period (period_id),
    CONSTRAINT fk_generation_runs_period
        FOREIGN KEY (period_id) REFERENCES schedule_periods(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_generation_runs_user
        FOREIGN KEY (started_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

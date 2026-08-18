<?php
declare(strict_types=1);
?>
<div class="page-head schedule-page-head">
    <div>
        <span class="eyebrow">WORKFORCE PLANNER</span>
        <h1>График</h1>
        <p class="muted">Сначала задайте потребность на выбранный месяц, затем сгенерируйте весь период и проверяйте результат по дням.</p>
    </div>
    <div class="schedule-toolbar">
        <label>
            <span>Период</span>
            <select id="period-select"><option value="">Нет созданных периодов</option></select>
        </label>
        <?php if (in_array($user['role'] ?? '', ['admin', 'editor'], true)): ?>
            <button id="generate-schedule" class="button">Сгенерировать период</button>
        <?php endif; ?>
    </div>
</div>

<div id="schedule-app"
     data-csrf="<?= \App\Core\View::e($csrf) ?>"
     data-role="<?= \App\Core\View::e($user['role']) ?>">

    <div class="view-switcher card">
        <div class="view-tabs" role="tablist" aria-label="Вид графика">
            <button id="view-month" class="view-tab active" type="button">Месяц</button>
            <button id="view-day" class="view-tab" type="button">День</button>
        </div>
        <div class="period-navigation">
            <button id="prev-period" class="icon-button" type="button" aria-label="Предыдущий месяц">‹</button>
            <button id="today-period" class="button secondary" type="button">Сегодня</button>
            <button id="next-period" class="icon-button" type="button" aria-label="Следующий месяц">›</button>
        </div>
        <div class="period-summary">
            <strong id="period-title">—</strong>
            <span id="period-hours">0/0 ч</span>
        </div>
    </div>

    <div id="message" aria-live="polite"></div>

    <section id="month-view" class="view-panel">
        <div class="month-head card">
            <div>
                <div class="month-title-line">
                    <h2 id="month-name">—</h2>
                    <button id="month-dropdown" class="small-chevron" type="button" aria-label="Выбрать месяц">⌄</button>
                </div>
                <p class="muted">Красным отмечены выходные. Цветные полосы показывают нагрузку по часам.</p>
            </div>
            <div class="load-switcher">
                <button id="load-mode" class="load-tab active" type="button">Нагрузка</button>
                <button id="hours-mode" class="load-tab" type="button">Часы</button>
            </div>
        </div>

        <div id="month-grid" class="month-grid"></div>

        <?php if (in_array($user['role'] ?? '', ['admin', 'editor'], true)): ?>
        <section class="card admin-tools">
            <div class="section-head">
                <div>
                    <h2>Потребность</h2>
                    <p class="muted">Добавляйте нужное количество сотрудников на конкретную дату и интервал.</p>
                </div>
            </div>

            <form id="requirement-form" class="compact-form">
                <label><span>Дата</span><input id="req-date" type="date" required></label>
                <label><span>Начало</span><input id="req-start" type="time" value="08:00" required></label>
                <label><span>Конец</span><input id="req-end" type="time" value="16:00" required></label>
                <label><span>Нужно сотрудников</span><input id="req-staff" type="number" min="1" value="2" required></label>
                <button class="button" type="submit">Добавить потребность</button>
            </form>

            <div class="table-wrap">
                <table id="requirements-table">
                    <thead><tr><th>Дата</th><th>Время</th><th>Нужно</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>


        <?php if (in_array($user['role'] ?? '', ['admin', 'editor'], true)): ?>
        <section class="card weekly-template-section">
            <div class="section-head">
                <div>
                    <h2>Недельная потребность</h2>
                    <p class="muted">Настройте норму для каждого дня недели один раз. Затем примените шаблон к выбранному месяцу.</p>
                </div>
                <button id="apply-weekly-template" class="button" type="button">Применить к выбранному месяцу</button>
            </div>

            <div class="weekly-template-grid">
                <div class="weekly-days">
                    <?php foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $idx => $label): ?>
                        <button class="weekly-day-tab<?= $idx === 0 ? ' active' : '' ?>" type="button" data-weekday="<?= $idx + 1 ?>">
                            <?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <form id="weekly-form" class="weekly-form">
                    <input type="hidden" id="weekly-weekday" value="1">
                    <label><span>Начало</span><input id="weekly-start" type="time" value="08:00" required></label>
                    <label><span>Конец</span><input id="weekly-end" type="time" value="16:00" required></label>
                    <label><span>Нужно сотрудников</span><input id="weekly-staff" type="number" min="1" value="2" required></label>
                    <label class="weekly-label-field"><span>Название (необязательно)</span><input id="weekly-label" type="text" placeholder="Например, утро"></label>
                    <button class="button" type="submit">Добавить интервал</button>
                </form>

                <div id="weekly-items" class="weekly-items"></div>
            </div>
        </section>
        <?php endif; ?>

        <section class="card wishes-section">
            <div class="section-head">
                <div>
                    <h2><?= in_array($user['role'] ?? '', ['admin', 'editor'], true) ? 'Пожелания сотрудников' : 'Мои пожелания' ?></h2>
                    <p class="muted"><?= in_array($user['role'] ?? '', ['admin', 'editor'], true)
                        ? 'Администратор и редактор видят пожелания всей команды.'
                        : 'Эти данные видны только вам и используются при построении вашего графика.' ?></p>
                </div>
            </div>


            <form id="my-preference-form" class="my-preference-form">
                <div class="policy-hint full">
                    <strong>Правила системы:</strong> базовая норма — 40 часов в неделю, максимум 8 часов в день и минимум 2 выходных дня в неделю.
                    Эти значения не нужно заполнять вручную.
                </div>

                <label>
                    <span>Желаемое начало</span>
                    <select id="my-pref-start"></select>
                </label>
                <label>
                    <span>Желаемый конец</span>
                    <select id="my-pref-end"></select>
                </label>
                <label>
                    <span>Выходные</span>
                    <select id="my-pref-weekend">
                        <option value="any">Без разницы</option>
                        <option value="together">Предпочитаю вместе</option>
                        <option value="separate">Предпочитаю раздельно</option>
                    </select>
                </label>

                <label>
                    <span>Отпуск</span>
                    <select id="vacation-mode">
                        <option value="none">Нет отпуска</option>
                        <option value="period">Есть отпуск</option>
                    </select>
                </label>
                <label id="vacation-start-wrap" class="hidden">
                    <span>Начало отпуска</span>
                    <input id="my-vacation-start" type="date">
                </label>
                <label id="vacation-end-wrap" class="hidden">
                    <span>Конец отпуска</span>
                    <input id="my-vacation-end" type="date">
                </label>

                <label>
                    <span>Макс. дней подряд</span>
                    <input id="my-pref-consecutive" type="number" min="1" max="31" value="5">
                </label>
                <label>
                    <span>Минимальный отдых</span>
                    <input id="my-pref-rest" type="number" min="0" step="0.5" value="8">
                </label>

                <label class="full">
                    <span>Предпочтительные дни недели</span>
                    <div id="my-weekday-picker" class="weekday-picker"></div>
                </label>

                <label class="full">
                    <span>Комментарий</span>
                    <textarea id="my-pref-notes" rows="3"></textarea>
                </label>

                <button class="button full" type="submit">Сохранить мои пожелания</button>
            </form>

            <?php if (in_array($user['role'] ?? '', ['admin', 'editor'], true)): ?>
                <div id="wishes-table-wrap" class="table-wrap admin-wishes-table"></div>
            <?php endif; ?>
        </section>
    </section>

    <section id="day-view" class="view-panel hidden">
        <div class="day-head card">
            <div class="day-title">
                <div class="day-number" id="day-number">—</div>
                <div>
                    <h2 id="day-title">—</h2>
                    <div class="day-meta">
                        <span id="day-hours">0/0 ч</span>
                        <span id="day-coverage">0%</span>
                    </div>
                </div>
            </div>
            <div class="day-nav">
                <button id="prev-day" class="icon-button" type="button" aria-label="Предыдущий день">‹</button>
                <button id="today-day" class="button secondary" type="button">Сегодня</button>
                <button id="next-day" class="icon-button" type="button" aria-label="Следующий день">›</button>
            </div>
        </div>

        <div class="hour-overview card">
            <div id="hour-cells" class="hour-cells"></div>
        </div>

        <div class="card day-schedule-card">
            <div class="day-grid-head">
                <div class="person-column-title">Сотрудники</div>
                <div id="timeline-labels" class="timeline-labels"></div>
            </div>
            <div id="employee-timeline" class="employee-timeline"></div>
        </div>
    </section>
</div>

<script src="/assets/js/schedule.js" defer></script>

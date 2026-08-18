(() => {
    'use strict';

    const app = document.getElementById('schedule-app');
    if (!app) return;

    const csrf = app.dataset.csrf;
    const role = app.dataset.role;
    const canManage = role === 'admin' || role === 'editor';

    const $ = (id) => document.getElementById(id);

    const state = {
        periods: [],
        data: null,
        currentPeriodId: null,
        currentDate: null,
        view: 'month',
        loadMode: 'load',
    };

    const monthNames = [
        'Январь','Февраль','Март','Апрель','Май','Июнь',
        'Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'
    ];
    const weekDays = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];

    function fillTimeSelect(select, selected = '') {
        if (!select) return;
        clear(select);
        const options = [el('option', {value:'', text:'Без предпочтения'})];
        for (let hour = 0; hour < 24; hour++) {
            for (let minute of [0, 30]) {
                const value = `${pad(hour)}:${pad(minute)}`;
                options.push(el('option', {value, text:value}));
            }
        }
        options.forEach(option => select.appendChild(option));
        select.value = selected || '';
    }


    function el(tag, props = {}) {
        const node = document.createElement(tag);
        Object.entries(props).forEach(([key, value]) => {
            if (key === 'text') node.textContent = value;
            else if (key === 'class') node.className = value;
            else if (key.startsWith('on')) node.addEventListener(key.slice(2).toLowerCase(), value);
            else node.setAttribute(key, value);
        });
        return node;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function showMessage(text, type = 'success') {
        const target = $('message');
        clear(target);
        if (!text) return;
        target.appendChild(el('div', {class: `alert alert-${type}`, text}));
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.headers || {})
            }
        });
        const data = await response.json().catch(() => ({error: 'Некорректный ответ сервера'}));
        if (!response.ok || data.error) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        return data;
    }

    function formData(extra = {}) {
        const fd = new FormData();
        fd.set('_csrf', csrf);
        Object.entries(extra).forEach(([key, value]) => fd.set(key, value));
        return fd;
    }

    function pad(n) { return String(n).padStart(2, '0'); }

    function dateToIso(date) {
        return `${date.getFullYear()}-${pad(date.getMonth()+1)}-${pad(date.getDate())}`;
    }

    function parseDate(iso) {
        const [y,m,d] = iso.split('-').map(Number);
        return new Date(y, m - 1, d);
    }

    function daysInMonth(year, month) {
        return new Date(year, month, 0).getDate();
    }

    function dayOfWeekMondayFirst(date) {
        const js = date.getDay();
        return js === 0 ? 7 : js;
    }

    function formatHours(value) {
        const n = Number(value || 0);
        return Number.isInteger(n) ? String(n) : n.toFixed(1);
    }

    function shiftHours(shift) {
        const start = Number(shift.start_time.slice(0,2)) + Number(shift.start_time.slice(3,5))/60;
        let end = Number(shift.end_time.slice(0,2)) + Number(shift.end_time.slice(3,5))/60;
        if (end <= start) end += 24;
        return end - start;
    }

    function getPeriod() {
        return state.data?.period || null;
    }

    function periodTotalHours() {
        let required = 0;
        let covered = 0;
        const requirements = state.data?.requirements || [];
        const shifts = state.data?.shifts || [];

        for (const r of requirements) {
            const start = new Date(`2000-01-01T${r.start_time}`);
            let end = new Date(`2000-01-01T${r.end_time}`);
            if (end <= start) end = new Date(end.getTime() + 86400000);
            const hours = (end - start) / 3600000;
            required += hours * Number(r.required_staff);

            const sameDay = shifts.filter(s => s.work_date === r.work_date);
            const count = sameDay.filter(s => s.start_time < r.end_time && s.end_time > r.start_time).length;
            covered += hours * Math.min(count, Number(r.required_staff));
        }
        return {required, covered, percent: required ? Math.round(covered / required * 100) : 0};
    }

    function findRequirementsForDate(date) {
        return (state.data?.requirements || []).filter(r => r.work_date === date);
    }

    function findShiftsForDate(date) {
        return (state.data?.shifts || []).filter(s => s.work_date === date);
    }

    function renderPeriodHeader() {
        const period = getPeriod();
        if (!period) return;
        $('period-title').textContent = `${monthNames[period.month - 1]} ${period.year}`;
        const totals = periodTotalHours();
        $('period-hours').textContent = `${formatHours(totals.covered)}/${formatHours(totals.required)} ч`;
        $('month-name').textContent = monthNames[period.month - 1];

        if (!state.currentDate) {
            state.currentDate = `${period.year}-${pad(period.month)}-01`;
        }
    }

    function renderMonth() {
        const grid = $('month-grid');
        clear(grid);

        const period = getPeriod();
        if (!period) return;

        const year = Number(period.year);
        const month = Number(period.month);
        const totalDays = daysInMonth(year, month);

        let firstDay = dayOfWeekMondayFirst(new Date(year, month - 1, 1));
        let cursor = 1;

        // Monday-based weeks; incomplete first/last weeks are intentionally compact.
        let weekStart = 1 - (firstDay - 1);
        if (weekStart < 1) weekStart = 1;

        const firstGridDay = new Date(year, month - 1, 1);
        const firstMondayOffset = firstDay - 1;
        let gridStart = new Date(year, month - 1, 1 - firstMondayOffset);

        // The screenshot uses week rows. Render all calendar weeks including spillover.
        while (true) {
            const week = el('section', {class: 'calendar-week'});
            const heading = el('div', {class: 'calendar-week-heading'});
            const monday = new Date(gridStart);
            const sunday = new Date(gridStart);
            sunday.setDate(monday.getDate() + 6);

            const mondayLabel = `${monday.getDate()} ${monthNames[monday.getMonth()].slice(0,3)}`;
            const sundayLabel = `${sunday.getDate()} ${monthNames[sunday.getMonth()].slice(0,3)}`;
            heading.appendChild(el('h3', {text: `${getIsoWeek(monday)} неделя, ${mondayLabel} — ${sundayLabel} ${sunday.getFullYear()}`}));

            const weekReq = [];
            const weekShifts = [];
            for (let i=0; i<7; i++) {
                const d = new Date(monday);
                d.setDate(monday.getDate() + i);
                const iso = dateToIso(d);
                weekReq.push(...findRequirementsForDate(iso));
                weekShifts.push(...findShiftsForDate(iso));
            }

            let reqHours = 0, covHours = 0;
            weekReq.forEach(r => {
                const start = new Date(`2000-01-01T${r.start_time}`);
                let end = new Date(`2000-01-01T${r.end_time}`);
                if (end <= start) end = new Date(end.getTime()+86400000);
                const h = (end-start)/3600000;
                reqHours += h * Number(r.required_staff);
                const count = weekShifts.filter(s => s.work_date === r.work_date && s.start_time < r.end_time && s.end_time > r.start_time).length;
                covHours += h * Math.min(count, Number(r.required_staff));
            });

            heading.appendChild(el('span', {class:'week-load', text:`${formatHours(covHours)}/${formatHours(reqHours)}ч`}));
            week.appendChild(heading);

            const cards = el('div', {class:'day-card-grid'});

            for (let i=0; i<7; i++) {
                const d = new Date(monday);
                d.setDate(monday.getDate()+i);
                const iso = dateToIso(d);

                if (d.getMonth() !== month-1) {
                    const filler = el('div', {class:'day-card outside'});
                    filler.appendChild(el('div', {class:'day-card-title', text:`${d.getDate()} ${monthNames[d.getMonth()].slice(0,3)}`}));
                    cards.appendChild(filler);
                    continue;
                }

                const reqs = findRequirementsForDate(iso);
                const shifts = findShiftsForDate(iso);
                const req = dailyRequired(reqs);
                const cov = dailyCovered(reqs, shifts);
                const dayCard = el('button', {class:'day-card', type:'button'});
                if (iso === state.currentDate) dayCard.classList.add('selected');
                if (i >= 5) dayCard.classList.add('weekend');

                dayCard.addEventListener('click', () => {
                    state.currentDate = iso;
                    setView('day');
                    renderDay();
                });

                const titleRow = el('div', {class:'day-card-title'});
                titleRow.appendChild(el('span', {text:`${d.getDate()} ${weekDays[i]}`}));
                dayCard.appendChild(titleRow);

                const loadRow = el('div', {class:'day-card-hours', text:`${formatHours(cov)}/${formatHours(req)}ч`});
                dayCard.appendChild(loadRow);

                const bars = el('div', {class:'day-load-bars'});
                for (let h=0; h<24; h++) {
                    const need = requirementAtHour(reqs, h);
                    const have = coverageAtHour(shifts, h, reqs);
                    const bar = el('span', {class:'load-cell'});
                    if (need === 0 && have === 0) bar.classList.add('empty');
                    else {
                        bar.classList.add(have >= need ? 'ok' : (have > 0 ? 'partial' : 'critical'));
                        if (need > 0 && have > need) bar.classList.add('over');
                    }
                    bars.appendChild(bar);
                }
                dayCard.appendChild(bars);

                if (state.loadMode === 'hours') {
                    const shiftSummary = el('div', {class:'day-card-shifts'});
                    const byEmployee = new Map();
                    shifts.forEach(s => {
                        byEmployee.set(s.user_id, (byEmployee.get(s.user_id) || 0) + shiftHours(s));
                    });
                    for (const [uid, hours] of byEmployee) {
                        const person = shifts.find(s => s.user_id === uid);
                        shiftSummary.appendChild(el('span', {text:`${person?.name || ''} ${formatHours(hours)}ч`}));
                    }
                    dayCard.appendChild(shiftSummary);
                }

                cards.appendChild(dayCard);
            }

            week.appendChild(cards);
            grid.appendChild(week);

            const next = new Date(gridStart);
            next.setDate(gridStart.getDate()+7);
            gridStart = next;

            if (next.getMonth() !== month-1 && next.getFullYear() !== year && next > new Date(year, month, 7)) break;
            if (next.getMonth() !== month-1 && next.getDate() > 7) break;
        }
    }

    function getIsoWeek(date) {
        const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
        const day = d.getUTCDay() || 7;
        d.setUTCDate(d.getUTCDate() + 4 - day);
        const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
        return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
    }

    function dailyRequired(reqs) {
        let result = 0;
        reqs.forEach(r => {
            const start = new Date(`2000-01-01T${r.start_time}`);
            let end = new Date(`2000-01-01T${r.end_time}`);
            if (end <= start) end = new Date(end.getTime()+86400000);
            result += ((end-start)/3600000) * Number(r.required_staff);
        });
        return result;
    }

    function dailyCovered(reqs, shifts) {
        let result = 0;
        reqs.forEach(r => {
            const start = new Date(`2000-01-01T${r.start_time}`);
            let end = new Date(`2000-01-01T${r.end_time}`);
            if (end <= start) end = new Date(end.getTime()+86400000);
            const h = (end-start)/3600000;
            const count = shifts.filter(s => s.start_time < r.end_time && s.end_time > r.start_time).length;
            result += h * Math.min(count, Number(r.required_staff));
        });
        return result;
    }

    function requirementAtHour(reqs, hour) {
        let count = 0;
        for (const r of reqs) {
            const startH = Number(r.start_time.slice(0,2));
            const endH = Number(r.end_time.slice(0,2));
            const overnight = r.end_time <= r.start_time;
            const active = overnight ? (hour >= startH || hour < endH) : (hour >= startH && hour < endH);
            if (active) count = Math.max(count, Number(r.required_staff));
        }
        return count;
    }

    function coverageAtHour(shifts, hour, reqs) {
        let count = 0;
        for (const s of shifts) {
            const sh = Number(s.start_time.slice(0,2));
            const eh = Number(s.end_time.slice(0,2));
            const overnight = s.end_time <= s.start_time;
            if (overnight ? (hour >= sh || hour < eh) : (hour >= sh && hour < eh)) count++;
        }
        return count;
    }

    function renderDay() {
        const date = parseDate(state.currentDate);
        const dateLabel = `${date.getDate()} ${monthNames[date.getMonth()].toLowerCase()}, ${weekDays[dayOfWeekMondayFirst(date)-1].toLowerCase()}`;

        $('day-number').textContent = String(date.getDate()).padStart(2, '0');
        $('day-title').textContent = `${dateLabel}`;
        $('req-date')?.setAttribute('value', state.currentDate);

        const reqs = findRequirementsForDate(state.currentDate);
        const shifts = findShiftsForDate(state.currentDate);

        const reqHours = dailyRequired(reqs);
        const covHours = dailyCovered(reqs, shifts);
        $('day-hours').textContent = `${formatHours(covHours)}/${formatHours(reqHours)} ч`;
        $('day-coverage').textContent = `${reqHours ? Math.round(covHours/reqHours*100) : 0}% покрытия`;

        renderHourOverview(reqs, shifts);
        renderTimeline(shifts);
    }

    function renderHourOverview(reqs, shifts) {
        const wrap = $('hour-cells');
        clear(wrap);

        for (let hour=0; hour<24; hour++) {
            const need = requirementAtHour(reqs, hour);
            const have = coverageAtHour(shifts, hour, reqs);
            const cell = el('div', {class:'hour-cell'});
            if (need > 0) {
                cell.classList.add(have >= need ? 'ok' : (have > 0 ? 'partial' : 'critical'));
            } else {
                cell.classList.add('idle');
            }
            cell.appendChild(el('span', {class:'hour-label', text:`${pad(hour)}:00`}));
            cell.appendChild(el('strong', {text:String(have)}));
            cell.appendChild(el('small', {text:String(need)}));
            wrap.appendChild(cell);
        }
    }

    function renderTimeline(shifts) {
        const labels = $('timeline-labels');
        clear(labels);
        for (let h=0; h<24; h++) {
            labels.appendChild(el('span', {text:`${pad(h)}:00`}));
        }

        const timeline = $('employee-timeline');
        clear(timeline);

        const employees = state.data?.employees || [];
        const shiftsByUser = new Map();
        shifts.forEach(s => {
            if (!shiftsByUser.has(String(s.user_id))) shiftsByUser.set(String(s.user_id), []);
            shiftsByUser.get(String(s.user_id)).push(s);
        });

        employees.forEach(employee => {
            const row = el('div', {class:'employee-row'});
            const person = el('div', {class:'employee-info'});
            person.appendChild(el('span', {class:'employee-dot'}));
            person.appendChild(el('span', {class:'employee-name', text:employee.name}));
            const own = shiftsByUser.get(String(employee.id)) || [];
            const hours = own.reduce((sum, s) => sum + shiftHours(s), 0);
            person.appendChild(el('span', {class:'employee-hours', text:`${formatHours(hours)}ч`}));
            row.appendChild(person);

            const track = el('div', {class:'employee-track'});

            for (let h=0; h<24; h++) {
                const slot = el('span', {class:'timeline-slot'});
                track.appendChild(slot);
            }

            own.forEach(s => {
                const start = Number(s.start_time.slice(0,2)) + Number(s.start_time.slice(3,5))/60;
                let end = Number(s.end_time.slice(0,2)) + Number(s.end_time.slice(3,5))/60;
                if (end <= start) end += 24;
                const left = Math.max(0, Math.min(24, start) / 24 * 100);
                const right = Math.max(0, Math.min(24, end) / 24 * 100);
                const block = el('div', {class:'shift-block'});
                block.style.left = `${left}%`;
                block.style.width = `${Math.max(1, right-left)}%`;
                block.appendChild(el('strong', {text:`${formatHours(shiftHours(s))}ч`}));
                block.appendChild(el('span', {text:`${s.start_time.slice(0,5)}–${s.end_time.slice(0,5)}`}));
                track.appendChild(block);
            });

            row.appendChild(track);
            timeline.appendChild(row);
        });
    }


    async function renderWeeklyRequirements() {
        const wrap = $('weekly-items');
        if (!wrap) return;
        clear(wrap);

        const data = await request('/api/weekly-requirements');
        const all = data.requirements || [];
        const weekday = Number($('weekly-weekday').value);
        const items = all.filter(x => Number(x.weekday) === weekday);

        if (!items.length) {
            wrap.appendChild(el('div', {class:'empty-state', text:'Для этого дня недели интервалы ещё не заданы.'}));
            return;
        }

        items.forEach(item => {
            const row = el('div', {class:'weekly-item'});
            row.appendChild(el('span', {class:'weekly-item-time', text:`${item.start_time.slice(0,5)}–${item.end_time.slice(0,5)}`}));
            row.appendChild(el('strong', {text:`${item.required_staff} чел.`}));
            if (item.label) row.appendChild(el('span', {class:'weekly-item-label', text:item.label}));

            const del = el('button', {class:'link-btn danger-text', type:'button', text:'Удалить'});
            del.addEventListener('click', async () => {
                try {
                    await request('/api/weekly-requirement/delete', {
                        method:'POST',
                        body:formData({id:item.id})
                    });
                    await renderWeeklyRequirements();
                    showMessage('Интервал недельного шаблона удалён.');
                } catch(e) { showMessage(e.message,'error'); }
            });
            row.appendChild(del);
            wrap.appendChild(row);
        });
    }


    function renderMyPreference() {
        fillTimeSelect($('my-pref-start'));
        fillTimeSelect($('my-pref-end'));

        const picker = $('my-weekday-picker');
        if (!picker) return;

        clear(picker);
        weekDays.forEach((label, index) => {
            const wrap = el('label', {class:'weekday-check'});
            const input = el('input', {type:'checkbox', value:String(index + 1)});
            wrap.appendChild(input);
            wrap.appendChild(el('span',{text:label}));
            picker.appendChild(wrap);
        });

        const prefs = state.data?.preferences || [];
        const currentId = Number(state.data?.current_user?.id || 0);
        const p = prefs.find(x => Number(x.id) === currentId) || null;

        if (!p) {
            $('my-pref-start').value = '';
            $('my-pref-end').value = '';
            $('my-pref-weekend').value = 'any';
            $('my-pref-consecutive').value = '5';
            $('my-pref-rest').value = '8';
            $('my-pref-notes').value = '';
            $('vacation-mode').value = 'none';
            $('vacation-start-wrap').classList.add('hidden');
            $('vacation-end-wrap').classList.add('hidden');
            $('my-vacation-start').value = '';
            $('my-vacation-end').value = '';
            return;
        }

        $('my-pref-start').value = p.preferred_shift_start ? p.preferred_shift_start.slice(0,5) : '';
        $('my-pref-end').value = p.preferred_shift_end ? p.preferred_shift_end.slice(0,5) : '';
        $('my-pref-weekend').value = p.weekend_mode || 'any';
        $('my-pref-consecutive').value = p.max_consecutive_days ?? 5;
        $('my-pref-rest').value = p.min_rest_hours ?? 8;
        $('my-pref-notes').value = p.notes || '';

        const hasVacation = !!(p.vacation_start && p.vacation_end);
        $('vacation-mode').value = hasVacation ? 'period' : 'none';
        $('vacation-start-wrap').classList.toggle('hidden', !hasVacation);
        $('vacation-end-wrap').classList.toggle('hidden', !hasVacation);
        $('my-vacation-start').value = p.vacation_start || '';
        $('my-vacation-end').value = p.vacation_end || '';

        let days = [];
        try { days = JSON.parse(p.preferred_days_json || '[]'); } catch (_) {}
        picker.querySelectorAll('input').forEach(input => {
            input.checked = days.includes(Number(input.value));
        });
    }


    function renderWishes() {
        renderMyPreference();

        const wrap = $('wishes-table-wrap');
        if (!wrap) return;
        clear(wrap);

        const prefs = state.data?.preferences || [];
        if (!prefs.length) {
            wrap.appendChild(el('div', {class:'empty-state', text:'Пока никто не сохранил пожелания.'}));
            return;
        }

        const table = el('table');
        const thead = el('thead');
        const headRow = el('tr');
        ['Сотрудник','Желаемая смена','Выходные','Предпочтительные дни','Лимит часов','Отдых','Комментарий'].forEach(h =>
            headRow.appendChild(el('th',{text:h}))
        );
        thead.appendChild(headRow);
        table.appendChild(thead);

        const body = el('tbody');
        prefs.forEach(p => {
            const tr = el('tr');
            tr.appendChild(el('td',{text:p.name}));

            const prefShift = p.preferred_shift_start && p.preferred_shift_end
                ? `${p.preferred_shift_start.slice(0,5)}–${p.preferred_shift_end.slice(0,5)}`
                : 'Без предпочтения';
            tr.appendChild(el('td',{text:prefShift}));

            const weekendMap = {together:'Смежные', separate:'Раздельные', any:'Без разницы'};
            tr.appendChild(el('td',{text:weekendMap[p.weekend_mode] || '—'}));

            let preferredDays = 'Без разницы';
            try {
                const ids = JSON.parse(p.preferred_days_json || '[]');
                if (Array.isArray(ids) && ids.length) preferredDays = ids.map(x => weekDays[Number(x)-1]).join(', ');
            } catch (_) {}
            tr.appendChild(el('td',{text:preferredDays}));

            const min = p.min_hours_per_month ? `${formatHours(p.min_hours_per_month)} ч мин.` : '';
            const max = p.max_hours_per_month ? `${formatHours(p.max_hours_per_month)} ч макс.` : '';
            tr.appendChild(el('td',{text:[min,max].filter(Boolean).join(' / ') || 'Без лимита'}));
            tr.appendChild(el('td',{text:`${p.min_rest_hours ?? 8} ч`}));
            tr.appendChild(el('td',{text:p.notes || '—'}));
            body.appendChild(tr);
        });
        table.appendChild(body);
        wrap.appendChild(table);
    }

    function renderAll() {
        renderPeriodHeader();
        renderMonth();
        renderDay();
        renderWishes();
        renderWeeklyRequirements().catch(e => showMessage(e.message, 'error'));
    }

    function setView(view) {
        state.view = view;
        $('view-month').classList.toggle('active', view === 'month');
        $('view-day').classList.toggle('active', view === 'day');
        $('month-view').classList.toggle('hidden', view !== 'month');
        $('day-view').classList.toggle('hidden', view !== 'day');
        if (view === 'day') renderDay();
    }

    async function loadPeriods(preferredId = null) {
        state.periods = (await request('/api/periods')).periods || [];
        const select = $('period-select');
        clear(select);

        if (!state.periods.length) {
            select.appendChild(el('option', {value:'', text:'Создайте первый месяц'}));
            return;
        }

        state.periods.forEach(p => {
            select.appendChild(el('option', {
                value:p.id,
                text:`${p.title} · ${p.status}`
            }));
        });

        let target = preferredId || state.currentPeriodId || state.periods[0].id;
        const exists = state.periods.some(p => Number(p.id) === Number(target));
        if (!exists) target = state.periods[0].id;

        select.value = String(target);
        await loadPeriod(Number(target));
    }

    async function loadPeriod(periodId) {
        if (!periodId) return;
        state.currentPeriodId = Number(periodId);
        state.data = await request(`/api/schedule-data?period_id=${encodeURIComponent(periodId)}`);

        const period = getPeriod();
        if (!state.currentDate ||
            !state.currentDate.startsWith(`${period.year}-${pad(period.month)}-`)) {
            state.currentDate = `${period.year}-${pad(period.month)}-01`;
        }

        renderAll();
    }

    function updatePeriodByDelta(delta) {
        if (!getPeriod()) return;
        const period = getPeriod();
        let y = Number(period.year);
        let m = Number(period.month) - 1 + delta;
        y += Math.floor(m / 12);
        m = (m % 12 + 12) % 12;
        const match = state.periods.find(p => Number(p.year) === y && Number(p.month) === m + 1);
        if (match) {
            loadPeriod(Number(match.id)).catch(e => showMessage(e.message,'error'));
        } else {
            showMessage('Для соседнего месяца ещё не создан период.', 'error');
        }
    }

    $('view-month').addEventListener('click', () => setView('month'));
    $('view-day').addEventListener('click', () => setView('day'));

    $('load-mode').addEventListener('click', () => {
        state.loadMode = 'load';
        $('load-mode').classList.add('active');
        $('hours-mode').classList.remove('active');
        renderMonth();
    });

    $('hours-mode').addEventListener('click', () => {
        state.loadMode = 'hours';
        $('hours-mode').classList.add('active');
        $('load-mode').classList.remove('active');
        renderMonth();
    });

    $('period-select').addEventListener('change', () => {
        loadPeriod(Number($('period-select').value)).catch(e => showMessage(e.message,'error'));
    });

    $('prev-period').addEventListener('click', () => updatePeriodByDelta(-1));
    $('next-period').addEventListener('click', () => updatePeriodByDelta(1));

    $('prev-day').addEventListener('click', () => {
        const d = parseDate(state.currentDate);
        d.setDate(d.getDate()-1);
        state.currentDate = dateToIso(d);
        if (getPeriod() && (d.getMonth()+1 !== Number(getPeriod().month) || d.getFullYear() !== Number(getPeriod().year))) {
            const target = state.periods.find(p => Number(p.year) === d.getFullYear() && Number(p.month) === d.getMonth()+1);
            if (target) loadPeriod(Number(target.id)).catch(e => showMessage(e.message,'error'));
        }
        renderDay();
    });

    $('next-day').addEventListener('click', () => {
        const d = parseDate(state.currentDate);
        d.setDate(d.getDate()+1);
        state.currentDate = dateToIso(d);
        if (getPeriod() && (d.getMonth()+1 !== Number(getPeriod().month) || d.getFullYear() !== Number(getPeriod().year))) {
            const target = state.periods.find(p => Number(p.year) === d.getFullYear() && Number(p.month) === d.getMonth()+1);
            if (target) loadPeriod(Number(target.id)).catch(e => showMessage(e.message,'error'));
        }
        renderDay();
    });

    function goToday() {
        const now = new Date();
        const iso = dateToIso(now);
        const target = state.periods.find(p => Number(p.year) === now.getFullYear() && Number(p.month) === now.getMonth()+1);
        if (!target) {
            showMessage('Период текущего месяца ещё не создан.', 'error');
            return;
        }
        state.currentDate = iso;
        loadPeriod(Number(target.id)).catch(e => showMessage(e.message,'error'));
    }

    $('today-period').addEventListener('click', goToday);
    $('today-day').addEventListener('click', goToday);

    if ($('requirement-form')) {
        $('requirement-form').addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await request('/api/requirement/save', {
                    method:'POST',
                    body:formData({
                        period_id:$('period-select').value,
                        work_date:$('req-date').value,
                        start_time:$('req-start').value,
                        end_time:$('req-end').value,
                        required_staff:$('req-staff').value
                    })
                });
                await loadPeriod(Number($('period-select').value));
                showMessage('Потребность добавлена.');
            } catch(e) {
                showMessage(e.message,'error');
            }
        });
    }

    if ($('generate-schedule')) {
        $('generate-schedule').addEventListener('click', async () => {
            try {
                const result = await request('/api/schedule/generate', {
                    method:'POST',
                    body:formData({period_id:$('period-select').value})
                });
                await loadPeriod(Number($('period-select').value));
                const r = result.result;
                showMessage(`Период сгенерирован. Назначено смен: ${r.assigned_shifts}; незакрыто часов: ${r.uncovered_hours}.`);
                setView('day');
            } catch(e) {
                showMessage(e.message,'error');
            }
        });
    }


    // My own preferences: every authenticated user can edit only their own wishes.
    if ($('my-preference-form')) {
        $('my-preference-form').addEventListener('submit', async event => {
            event.preventDefault();
            const days = Array.from(document.querySelectorAll('#my-weekday-picker input:checked')).map(x => x.value);
            try {
                await request('/api/preferences/save', {
                    method:'POST',
                    body:formData({
                        preferred_shift_start:$('my-pref-start').value,
                        preferred_shift_end:$('my-pref-end').value,
                        weekend_mode:$('my-pref-weekend').value,
                        preferred_days:days,
                        max_consecutive_days:$('my-pref-consecutive').value,
                        min_rest_hours:$('my-pref-rest').value,
                        notes:$('my-pref-notes').value,
                        vacation_start:$('vacation-mode').value === 'period' ? $('my-vacation-start').value : '',
                        vacation_end:$('vacation-mode').value === 'period' ? $('my-vacation-end').value : ''
                    })
                });
                await loadPeriod(Number($('period-select').value));
                showMessage('Ваши пожелания сохранены.');
            } catch(e) {
                showMessage(e.message,'error');
            }
        });
    }

    document.querySelectorAll('.weekly-day-tab').forEach(tab => {
        tab.addEventListener('click', async () => {
            document.querySelectorAll('.weekly-day-tab').forEach(x => x.classList.remove('active'));
            tab.classList.add('active');
            $('weekly-weekday').value = tab.dataset.weekday;
            await renderWeeklyRequirements();
        });
    });

    if ($('weekly-form')) {
        $('weekly-form').addEventListener('submit', async event => {
            event.preventDefault();
            try {
                await request('/api/weekly-requirement/save', {
                    method:'POST',
                    body:formData({
                        weekday:$('weekly-weekday').value,
                        start_time:$('weekly-start').value,
                        end_time:$('weekly-end').value,
                        required_staff:$('weekly-staff').value,
                        label:$('weekly-label').value
                    })
                });
                $('weekly-label').value = '';
                await renderWeeklyRequirements();
                showMessage('Интервал добавлен в недельный шаблон.');
            } catch(e) { showMessage(e.message,'error'); }
        });
    }

    if ($('apply-weekly-template')) {
        $('apply-weekly-template').addEventListener('click', async () => {
            try {
                const result = await request('/api/weekly-requirements/apply', {
                    method:'POST',
                    body:formData({period_id:$('period-select').value})
                });
                await loadPeriod(Number($('period-select').value));
                showMessage(`Шаблон применён к месяцу. Создано интервалов: ${result.count}.`);
            } catch(e) { showMessage(e.message,'error'); }
        });
    }

    loadPeriods().catch(e => showMessage(e.message,'error'));
})();

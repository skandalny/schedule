(() => {
    'use strict';

    const table = document.querySelector('#users-table tbody');
    if (!table) return;

    function el(tag, props = {}) {
        const node = document.createElement(tag);
        Object.entries(props).forEach(([key, value]) => {
            if (key === 'text') node.textContent = value;
            else node.setAttribute(key, value);
        });
        return node;
    }

    async function load() {
        const response = await fetch('/api/admin/users', {credentials: 'same-origin'});
        const data = await response.json();
        while (table.firstChild) table.removeChild(table.firstChild);

        (data.users || []).forEach(user => {
            const tr = el('tr');
            tr.appendChild(el('td', {text: user.name}));
            tr.appendChild(el('td', {text: user.email}));

            const roleTd = el('td');
            const select = el('select');
            ['admin','editor','viewer','employee'].forEach(role => {
                const option = el('option', {value: role, text: role});
                option.selected = role === user.role;
                select.appendChild(option);
            });
            roleTd.appendChild(select);
            tr.appendChild(roleTd);

            const actionTd = el('td');
            const button = el('button', {type: 'button', class: 'button small', text: 'Сохранить'});
            button.addEventListener('click', async () => {
                const fd = new FormData();
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
                fd.set('_csrf', csrf);
                fd.set('id', user.id);
                fd.set('role', select.value);
                const response = await fetch('/api/admin/role', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const result = await response.json();
                if (result.error) alert(result.error);
                else load();
            });
            actionTd.appendChild(button);
            tr.appendChild(actionTd);
            table.appendChild(tr);
        });
    }

    load().catch(error => {
        table.appendChild(el('tr', {text: error.message}));
    });
})();

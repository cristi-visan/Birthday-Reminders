(() => {
    'use strict';

    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'];

    const $ = (sel, root = document) => root.querySelector(sel);
    const state = { birthdays: [], view: 'upcoming', query: '', editing: null };

    // ---------- API ----------
    async function api(path, { method = 'GET', body } = {}) {
        const res = await fetch('api/' + path, {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'fetch',
                ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
        let data = {};
        try { data = await res.json(); } catch (_) { /* empty body */ }
        if (res.status === 401 && path !== 'login') {
            showLogin();
        }
        if (!res.ok) {
            const err = new Error(data.error || `Request failed (${res.status})`);
            err.status = res.status;
            throw err;
        }
        return data;
    }

    // ---------- Helpers ----------
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    function initials(name) {
        const parts = name.trim().split(/\s+/).filter(Boolean);
        const first = parts[0]?.[0] ?? '?';
        const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
        return (first + last).toUpperCase();
    }

    function hue(name) {
        let h = 0;
        for (const ch of name) h = (h * 31 + ch.codePointAt(0)) % 360;
        return h;
    }

    function countdown(days) {
        if (days === 0) return 'Today';
        if (days === 1) return 'Tomorrow';
        return `in ${days} days`;
    }

    function shortDate(b) {
        return `${b.day} ${MONTHS[b.month - 1].slice(0, 3)}`;
    }

    let toastTimer;
    function toast(msg) {
        const el = $('#toast');
        el.textContent = msg;
        el.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { el.hidden = true; }, 2600);
    }

    // ---------- Auth ----------
    function showLogin() {
        $('#app').hidden = true;
        $('#login').hidden = false;
        setTimeout(() => $('#login-form input[name=password]').focus(), 50);
    }

    async function showApp() {
        $('#login').hidden = true;
        $('#app').hidden = false;
        await load();
    }

    $('#login-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.currentTarget;
        const btn = form.querySelector('button');
        const err = $('#login-error');
        err.hidden = true;
        btn.disabled = true;
        try {
            await api('login', { method: 'POST', body: { password: form.elements.password.value } });
            form.reset();
            await showApp();
        } catch (ex) {
            err.textContent = ex.message;
            err.hidden = false;
        } finally {
            btn.disabled = false;
        }
    });

    // ---------- Data ----------
    async function load() {
        const data = await api('birthdays');
        state.birthdays = data.birthdays;
        render();
    }

    function filtered() {
        const q = state.query.trim().toLowerCase();
        if (!q) return state.birthdays;
        const terms = q.split(/\s+/);
        return state.birthdays.filter((b) => {
            const hay = [b.name, b.relation, (b.tags || []).join(' '), b.notes, b.gift_ideas].join(' ').toLowerCase();
            return terms.every((t) => hay.includes(t));
        });
    }

    // ---------- Render ----------
    function render() {
        renderHero();
        const list = filtered();
        const root = $('#list');

        if (!state.birthdays.length) {
            root.innerHTML = `<div class="empty">No birthdays yet. Add the first one, or ask Claude to add them for you.</div>`;
            return;
        }
        if (!list.length) {
            root.innerHTML = `<div class="empty">Nobody matches “${esc(state.query)}”.</div>`;
            return;
        }

        const groups = state.view === 'upcoming' ? groupUpcoming(list) : groupByMonth(list);
        root.innerHTML = groups.map(([title, items, note]) => `
            <div class="group">
                <h3 class="group-title"><span>${esc(title)}</span>${note ? `<span>${esc(note)}</span>` : ''}</h3>
                <div class="cards">${items.map(itemHtml).join('')}</div>
            </div>`).join('');
    }

    function groupUpcoming(list) {
        const buckets = [
            ['Today', (d) => d === 0],
            ['This week', (d) => d > 0 && d <= 7],
            ['This month', (d) => d > 7 && d <= 31],
            ['Next three months', (d) => d > 31 && d <= 92],
            ['Later', (d) => d > 92],
        ];
        return buckets
            .map(([title, test]) => [title, list.filter((b) => test(b.days_until))])
            .filter(([, items]) => items.length);
    }

    function groupByMonth(list) {
        const byMonth = new Map();
        for (const b of [...list].sort((a, z) => a.month - z.month || a.day - z.day || a.name.localeCompare(z.name))) {
            if (!byMonth.has(b.month)) byMonth.set(b.month, []);
            byMonth.get(b.month).push(b);
        }
        return [...byMonth.entries()].map(([m, items]) => [MONTHS[m - 1], items, String(items.length)]);
    }

    function itemHtml(b) {
        const sub = [
            b.turns !== null ? (b.days_until === 0 ? `turns ${b.turns} today` : `turns ${b.turns}`) : null,
            b.relation || null,
            ...(b.tags || []).map((t) => '#' + t),
        ].filter(Boolean).join(' · ');
        const badgeClass = b.days_until === 0 ? 'today' : (b.days_until <= 7 ? 'soon' : '');
        return `
            <button class="item ${b.is_today ? 'today' : ''}" data-id="${esc(b.id)}" type="button">
                <span class="avatar" style="--h:${hue(b.name)}">${esc(initials(b.name))}</span>
                <span class="item-main">
                    <span class="item-name">${esc(b.name)}${b.is_today ? ' 🎉' : ''}</span>
                    <span class="item-sub">${esc(sub || (b.gift_ideas ? '🎁 ' + b.gift_ideas : ' '))}</span>
                </span>
                <span class="item-side">
                    <span class="item-date">${esc(shortDate(b))}</span><br>
                    <span class="badge ${badgeClass}">${esc(countdown(b.days_until))}</span>
                </span>
            </button>`;
    }

    function renderHero() {
        const hero = $('#hero');
        const all = state.birthdays;
        if (!all.length) {
            hero.innerHTML = `
                <div class="hero-card hero-empty">
                    <div class="hero-names">Let's start the list 🎈</div>
                    <p class="hero-meta">Add birthdays here, or connect Claude from Settings and ask it to add them.</p>
                    <button class="btn primary" type="button" data-action="add">Add a birthday</button>
                </div>`;
            return;
        }
        const nextDays = all[0].days_until;
        const next = all.filter((b) => b.days_until === nextDays);
        const names = next.map((b) => esc(b.name)).join(' & ');
        const meta = next.length === 1 && next[0].turns !== null
            ? `${nextDays === 0 ? 'Turns' : 'Turning'} ${next[0].turns} · ${next[0].next_weekday}, ${shortDate(next[0])}`
            : `${next[0].next_weekday}, ${shortDate(next[0])}`;

        if (nextDays === 0) {
            hero.innerHTML = `
                <div class="hero-card today">
                    <div class="hero-eyebrow">Today 🎉</div>
                    <div class="hero-names">${names}</div>
                    <div class="hero-meta">${esc(meta)}</div>
                </div>`;
        } else {
            hero.innerHTML = `
                <div class="hero-card has-count">
                    <div class="hero-eyebrow">Next up</div>
                    <div class="hero-names">${names}</div>
                    <div class="hero-meta">${esc(meta)}</div>
                    <div class="hero-count"><b>${nextDays}</b><span>${nextDays === 1 ? 'day' : 'days'}</span></div>
                </div>`;
        }
    }

    // ---------- Edit dialog ----------
    const editDialog = $('#edit-dialog');
    const editForm = $('#edit-form');

    (function fillSelects() {
        editForm.elements.day.innerHTML = Array.from({ length: 31 }, (_, i) => `<option value="${i + 1}">${i + 1}</option>`).join('');
        editForm.elements.month.innerHTML = MONTHS.map((m, i) => `<option value="${i + 1}">${m}</option>`).join('');
        editForm.elements.year.max = new Date().getFullYear();
    })();

    function openEdit(b = null) {
        state.editing = b;
        editForm.reset();
        $('#edit-error').hidden = true;
        $('#edit-title').textContent = b ? 'Edit birthday' : 'Add birthday';
        $('#delete-btn').hidden = !b;
        const now = new Date();
        editForm.elements.name.value = b?.name ?? '';
        editForm.elements.day.value = String(b?.day ?? now.getDate());
        editForm.elements.month.value = String(b?.month ?? now.getMonth() + 1);
        editForm.elements.year.value = b?.year ?? '';
        editForm.elements.relation.value = b?.relation ?? '';
        editForm.elements.tags.value = (b?.tags ?? []).join(', ');
        editForm.elements.gift_ideas.value = b?.gift_ideas ?? '';
        editForm.elements.notes.value = b?.notes ?? '';
        editDialog.showModal();
        if (!b) setTimeout(() => editForm.elements.name.focus(), 30);
    }

    editForm.addEventListener('submit', async (e) => {
        if (e.submitter && e.submitter.value === 'cancel') return; // let the dialog close
        e.preventDefault();
        const err = $('#edit-error');
        err.hidden = true;
        const payload = {
            name: editForm.elements.name.value.trim(),
            day: Number(editForm.elements.day.value),
            month: Number(editForm.elements.month.value),
            year: editForm.elements.year.value ? Number(editForm.elements.year.value) : null,
            relation: editForm.elements.relation.value.trim(),
            tags: editForm.elements.tags.value.split(',').map((t) => t.trim()).filter(Boolean),
            gift_ideas: editForm.elements.gift_ideas.value.trim(),
            notes: editForm.elements.notes.value.trim(),
        };
        if (!payload.name) {
            err.textContent = 'Name is required.';
            err.hidden = false;
            editForm.elements.name.focus();
            return;
        }
        const btn = $('#save-btn');
        btn.disabled = true;
        try {
            if (state.editing) {
                await api('birthdays/' + encodeURIComponent(state.editing.id), { method: 'POST', body: payload });
                toast('Saved');
            } else {
                await api('birthdays', { method: 'POST', body: payload });
                toast(`Added ${payload.name}`);
            }
            editDialog.close();
            await load();
        } catch (ex) {
            err.textContent = ex.message;
            err.hidden = false;
        } finally {
            btn.disabled = false;
        }
    });

    $('#delete-btn').addEventListener('click', async () => {
        const b = state.editing;
        if (!b || !confirm(`Delete ${b.name}'s birthday?`)) return;
        try {
            await api(`birthdays/${encodeURIComponent(b.id)}/delete`, { method: 'POST', body: {} });
            editDialog.close();
            toast(`Deleted ${b.name}`);
            await load();
        } catch (ex) {
            toast(ex.message);
        }
    });

    // ---------- Settings ----------
    const settingsDialog = $('#settings-dialog');

    async function openSettings() {
        const body = $('#settings-body');
        body.innerHTML = '<p class="muted">Loading…</p>';
        settingsDialog.showModal();
        try {
            const s = await api('settings');
            const conns = s.connections.length
                ? s.connections.map((c) => esc(c.client_name)).join(', ')
                : 'None yet';
            const days = s.reminder_days.slice().sort((a, b) => b - a)
                .map((d) => d === 0 ? 'on the day' : `${d} day${d === 1 ? '' : 's'} before`).join(' and ');
            body.innerHTML = `
                <div class="setting">
                    <h3>Connect Claude</h3>
                    <p>In Claude: Settings → Connectors → Add custom connector, paste this URL, then approve with your password.</p>
                    <div class="copy-row"><code id="mcp-url">${esc(s.mcp_url)}</code><button class="btn small" type="button" data-action="copy-mcp">Copy</button></div>
                    <p style="margin-top:10px">Connected: ${conns}</p>
                    ${s.connections.length ? '<div class="actions"><button class="btn small ghost" type="button" data-action="revoke">Disconnect all</button></div>' : ''}
                </div>
                <div class="setting">
                    <h3><span class="status-dot ${s.slack_configured ? 'on' : ''}"></span>Slack reminders</h3>
                    <p>${s.slack_configured ? `You'll get a DM ${esc(days)}, from the daily cron job.` : 'Not configured — add the Slack token and your member ID to config.php.'}</p>
                    <div class="actions">
                        <button class="btn small" type="button" data-action="slack-test" ${s.slack_configured ? '' : 'disabled'}>Send test message</button>
                        <button class="btn small ghost" type="button" data-action="preview">Preview today's reminder</button>
                    </div>
                    <div id="preview-out"></div>
                </div>
                <div class="setting">
                    <h3>Data</h3>
                    <p>${s.count} birthday${s.count === 1 ? '' : 's'} saved. Daily backups are kept on the server.</p>
                    <div class="actions">
                        <a class="btn small ghost" href="api/export" download>Export JSON</a>
                        <button class="btn small ghost" type="button" data-action="logout">Sign out</button>
                    </div>
                </div>
                <p class="muted" style="font-size:12px;margin:12px 0 8px">v${esc(s.version)} · ${esc(s.timezone)}</p>`;
        } catch (ex) {
            body.innerHTML = `<p class="form-error">${esc(ex.message)}</p>`;
        }
    }

    settingsDialog.addEventListener('click', async (e) => {
        if (e.target === settingsDialog || e.target.closest('[data-close]')) {
            settingsDialog.close();
            return;
        }
        const btn = e.target.closest('[data-action]');
        if (!btn) return;
        const action = btn.dataset.action;
        try {
            if (action === 'copy-mcp') {
                await navigator.clipboard.writeText($('#mcp-url').textContent);
                toast('Copied');
            } else if (action === 'slack-test') {
                btn.disabled = true;
                await api('slack/test', { method: 'POST', body: {} });
                toast('Test sent — check Slack');
                btn.disabled = false;
            } else if (action === 'preview') {
                const r = await api('reminders/preview');
                $('#preview-out').innerHTML = r.message
                    ? `<pre class="preview">${esc(r.message)}</pre>`
                    : '<pre class="preview">Nothing due today.</pre>';
            } else if (action === 'revoke') {
                if (!confirm('Disconnect every connected Claude client? You can reconnect any time.')) return;
                await api('connections/revoke', { method: 'POST', body: {} });
                toast('Disconnected');
                openSettings();
            } else if (action === 'logout') {
                await api('logout', { method: 'POST', body: {} });
                settingsDialog.close();
                showLogin();
            }
        } catch (ex) {
            btn.disabled = false;
            toast(ex.message);
        }
    });

    // Click outside a sheet closes it
    editDialog.addEventListener('click', (e) => { if (e.target === editDialog) editDialog.close(); });

    // ---------- Events ----------
    $('#open-add').addEventListener('click', () => openEdit());
    $('#open-settings').addEventListener('click', openSettings);
    $('#hero').addEventListener('click', (e) => { if (e.target.closest('[data-action=add]')) openEdit(); });

    $('#list').addEventListener('click', (e) => {
        const item = e.target.closest('.item');
        if (!item) return;
        const b = state.birthdays.find((x) => x.id === item.dataset.id);
        if (b) openEdit(b);
    });

    document.querySelectorAll('.tab').forEach((tab) => tab.addEventListener('click', () => {
        state.view = tab.dataset.view;
        document.querySelectorAll('.tab').forEach((t) => {
            t.classList.toggle('active', t === tab);
            t.setAttribute('aria-selected', String(t === tab));
        });
        render();
    }));

    $('#search').addEventListener('input', (e) => {
        state.query = e.target.value;
        render();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'n' && !e.metaKey && !e.ctrlKey && !document.querySelector('dialog[open]')
            && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) && !$('#app').hidden) {
            e.preventDefault();
            openEdit();
        }
    });

    // Refresh when the tab comes back into view (Claude may have added people meanwhile).
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !$('#app').hidden && !document.querySelector('dialog[open]')) {
            load().catch(() => {});
        }
    });

    // ---------- Boot ----------
    api('session')
        .then((s) => (s.authenticated ? showApp() : showLogin()))
        .catch(() => showLogin());
})();

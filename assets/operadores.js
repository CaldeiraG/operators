/*
 * Operator board (operadores.php) - front end.
 *
 * Groups the operators returned by api/operadores.php by plant shift, follows
 * the server clock and reloads as soon as the next shift starts. Written in
 * ES5 so older signage players keep working.
 */
(function (window, document) {
    'use strict';

    var cfg = window.OperadoresConfig || {};
    var query = parseQuery();
    var signage = query.display === '1' || query.display === 'signage'
        || query.display === 'kiosk' || query.signage === '1' || query.kiosk === '1';

    var state = {
        data: null,
        receivedAt: 0,
        serverEpochMs: 0,
        tzOffsetMs: 0,
        nextStartMs: 0,
        shiftStartMs: 0,
        shiftEndMs: 0,
        lastShift: null,
        lastLoadedAt: 0,
        filter: initialFilter(),
        followCurrent: !query.shift || query.shift === 'current' || query.shift === 'all',
        search: '',
        loading: false,
        needsReload: false,
        shiftRefreshPending: false,
        boundaryRetryAt: 0,
        noticeTimer: null
    };

    // A wall display always follows the shift in progress unless a shift was
    // explicitly pinned in the URL.
    if (signage && !query.shift) {
        state.followCurrent = true;
        state.filter = 'current';
    }

    var SHIFTS = ['4T', '1T', '2T'];
    var DEFAULT_DEFS = {
        '4T': { key: '4T', label: '4º Turno', start: '01:00', end: '07:59', hours: 7 },
        '1T': { key: '1T', label: '1º Turno', start: '08:00', end: '16:29', hours: 8.5 },
        '2T': { key: '2T', label: '2º Turno', start: '16:30', end: '00:59', hours: 8.5 }
    };

    // ---------------------------------------------------------------------
    // Boot
    // ---------------------------------------------------------------------

    if (signage) {
        document.body.className += ' op-signage';
    }

    bindEvents();
    load(true);

    setInterval(tick, 1000);

    var pollSeconds = parseInt(query.refresh, 10);
    if (isNaN(pollSeconds) || pollSeconds < 0) {
        pollSeconds = parseInt(cfg.pollSeconds, 10);
    }
    if (isNaN(pollSeconds) || pollSeconds < 0) {
        pollSeconds = 120;
    }
    if (pollSeconds > 0) {
        setInterval(function () {
            if (!state.loading && autoEnabled()) {
                load(false);
            }
        }, pollSeconds * 1000);
    }

    // ---------------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------------

    function bindEvents() {
        var search = byId('opSearch');
        if (search) {
            var searchTimer = null;
            search.oninput = function () {
                if (searchTimer) {
                    clearTimeout(searchTimer);
                }
                searchTimer = setTimeout(function () {
                    state.search = String(search.value || '').toLowerCase().replace(/^\s+|\s+$/g, '');
                    renderOperators();
                }, 120);
            };
        }

        var refresh = byId('opRefresh');
        if (refresh) {
            refresh.onclick = function () {
                load(true);
            };
        }

        var auto = byId('opAuto');
        if (auto) {
            auto.checked = true;
        }

        if (document.addEventListener) {
            document.addEventListener('visibilitychange', onVisible, false);
        }
        window.onfocus = onVisible;
    }

    function onVisible() {
        if (document.hidden) {
            return;
        }
        var now = serverNow();
        var stale = state.lastLoadedAt && (Date.now() - state.lastLoadedAt > 5 * 60 * 1000);
        if ((state.nextStartMs && now >= state.nextStartMs) || stale) {
            load(true);
        }
    }

    function autoEnabled() {
        var auto = byId('opAuto');
        return signage || !auto || auto.checked;
    }

    // ---------------------------------------------------------------------
    // API layer
    // ---------------------------------------------------------------------

    function apiGet(action, params, done) {
        var base = (cfg.apiBase || '').replace(/\/?$/, '/');
        if (!base) {
            done('apiBase não configurado.', null);
            return;
        }

        var url = base + action + '.php';
        var pairs = [];
        var key;
        for (key in params) {
            if (Object.prototype.hasOwnProperty.call(params, key)
                && params[key] !== null && params[key] !== '') {
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
            }
        }
        if (pairs.length) {
            url += '?' + pairs.join('&');
        }

        var xhr = new XMLHttpRequest();
        var finished = false;

        function finish(err, data) {
            if (finished) {
                return;
            }
            finished = true;
            done(err, data);
        }

        xhr.open('GET', url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = 15000;

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && data && !data.error) {
                finish(null, data);
            } else {
                finish((data && (data.message || data.error)) || ('HTTP ' + xhr.status), null);
            }
        };

        xhr.ontimeout = function () {
            finish('Tempo de resposta excedido.', null);
        };
        xhr.onerror = function () {
            finish('Falha de rede.', null);
        };

        xhr.send();
    }

    function load(force) {
        if (state.loading) {
            state.needsReload = true;
            return;
        }

        state.loading = true;
        setLoading(true);

        var params = {};
        if (query.line_id) {
            params.line_id = query.line_id;
        }
        if (query.area_id) {
            params.area_id = query.area_id;
        }
        if (query.date) {
            params.date = query.date;
        }
        if (query.__mode) {
            params.__mode = query.__mode;
        }
        if (force) {
            params.__refresh = '1';
        }

        apiGet('operadores', params, function (err, data) {
            state.loading = false;
            setLoading(false);

            if (err) {
                if (state.shiftRefreshPending) {
                    // Do not hammer the endpoint every tick while it is down:
                    // keep the countdown at zero and retry in a minute.
                    state.boundaryRetryAt = Date.now() + 60000;
                }
                state.shiftRefreshPending = false;
                state.lastLoadedAt = Date.now();
                hideNotice();
                showError(err);
                renderAfterError();
            } else {
                hideError();
                applyPayload(data);
            }

            if (state.needsReload) {
                state.needsReload = false;
                load(true);
            }
        });
    }

    function applyPayload(data) {
        var meta = data.meta || {};

        state.data = data;
        state.receivedAt = Date.now();
        state.serverEpochMs = numberOrNull(meta.server_epoch_ms) || state.receivedAt;
        state.tzOffsetMs = (numberOrNull(meta.timezone_offset) !== null
            ? numberOrNull(meta.timezone_offset)
            : (numberOrNull(meta.server_clock_offset) || 0)) * 1000;
        state.nextStartMs = numberOrNull(meta.next_shift_start_epoch_ms) || 0;
        state.shiftStartMs = numberOrNull(meta.shift_start_epoch_ms) || 0;
        state.shiftEndMs = numberOrNull(meta.shift_end_epoch_ms) || 0;
        state.lastLoadedAt = Date.now();
        state.shiftRefreshPending = false;
        state.boundaryRetryAt = 0;

        if (state.followCurrent && meta.current_shift) {
            state.filter = meta.current_shift;
        }

        if (state.lastShift && meta.current_shift && meta.current_shift !== state.lastShift) {
            var currentDef = shiftDefinition(meta.current_shift);
            var currentLabel = currentDef && currentDef.label ? currentDef.label : meta.current_shift;
            showNotice('Novo turno: ' + currentLabel, 'success', 8000);
        }
        state.lastShift = meta.current_shift || state.lastShift;

        renderAll();
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    function renderAll() {
        renderLive();
        renderChips();
        renderOperators();
        renderUpdated();
    }

    function renderLive() {
        var data = state.data || {};
        var meta = data.meta || {};
        var panel = byId('livePanel');
        var shift = meta.current_shift || '';
        var def = shiftDefinition(shift);

        if (panel) {
            panel.setAttribute('data-shift', shift);
        }

        setText('liveShift', def && def.label ? def.label : (shift || '--'));

        var range = '';
        if (def) {
            range = def.start + ' - ' + def.end
                + (def.key === '2T' ? ' (D+1)' : '')
                + ' · ' + formatHours(def.hours) + ' h';
        } else if (meta.current_shift_range) {
            range = meta.current_shift_range;
        }
        setText('liveRange', range || 'A carregar...');

        var date = formatIsoDate(meta.production_date);
        setText('liveDate', date);

        var badge = byId('liveCurrentBadge');
        if (badge) {
            badge.style.display = shift ? 'inline-block' : 'none';
        }

        setText('liveNextShift', meta.next_shift || '--');
        renderClock(serverNow());
        renderCountdown(serverNow());
        renderProgress(serverNow());
    }

    function renderChips() {
        var wrap = byId('shiftSummary');
        if (!wrap) {
            return;
        }

        var data = state.data || {};
        var meta = data.meta || {};
        var counts = data.counts || {};
        var defs = data.shifts || [];

        wrap.innerHTML = '';

        var i;
        for (i = 0; i < SHIFTS.length; i++) {
            var key = SHIFTS[i];
            var def = findShiftDef(defs, key) || DEFAULT_DEFS[key];
            var count = typeof counts[key] === 'number' ? counts[key] : 0;
            wrap.appendChild(buildChip(key, def.label || key, count, key === meta.current_shift));
        }

        if (counts.unassigned) {
            wrap.appendChild(buildChip('', 'Sem turno', counts.unassigned, false));
        }
    }

    function buildChip(key, label, count, isLive) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'op-chip' + (state.filter === key ? ' is-active' : '');
        button.setAttribute('data-shift', key);
        button.setAttribute('aria-pressed', state.filter === key ? 'true' : 'false');

        if (isLive) {
            var dot = document.createElement('span');
            dot.className = 'op-chip-live';
            button.appendChild(dot);
        }

        button.appendChild(document.createTextNode(label));

        var badge = document.createElement('span');
        badge.className = 'count';
        badge.appendChild(document.createTextNode(String(count)));
        button.appendChild(badge);

        button.onclick = function () {
            var data = state.data || {};
            var meta = data.meta || {};
            state.followCurrent = key === meta.current_shift;
            state.filter = key;
            renderChips();
            renderOperators();
        };

        return button;
    }

    function renderOperators() {
        var wrap = byId('operatorsWrap');
        if (!wrap) {
            return;
        }

        wrap.innerHTML = '';

        if (!state.data) {
            wrap.appendChild(buildMessage('A carregar operadores...'));
            return;
        }

        var data = state.data;
        var meta = data.meta || {};
        var operators = data.operators || [];
        var groups = { '4T': [], '1T': [], '2T': [], '': [] };
        var i;

        for (i = 0; i < operators.length; i++) {
            var op = operators[i];
            var key = op.shift && groups.hasOwnProperty(op.shift) ? op.shift : '';
            groups[key].push(op);
        }

        var order = SHIFTS.slice();
        if (groups[''].length || state.filter === '') {
            order.push('');
        }

        var shown = 0;
        for (i = 0; i < order.length; i++) {
            var shiftKey = order[i];
            if (state.filter !== 'all' && state.filter !== shiftKey) {
                continue;
            }

            var items = filterItems(groups[shiftKey]);
            wrap.appendChild(buildSection(shiftKey, items, shiftKey === meta.current_shift));
            shown++;
        }

        if (!shown) {
            wrap.appendChild(buildMessage(
                state.search
                    ? 'Nenhum operador corresponde à pesquisa.'
                    : 'Sem operadores neste turno.'
            ));
        }
    }

    function buildSection(key, items, isCurrent) {
        var section = document.createElement('section');
        section.className = 'card shift-section' + (isCurrent ? ' is-current' : '');
        section.setAttribute('data-shift', key || 'other');

        var body = document.createElement('div');
        body.className = 'card-body';

        var grid = document.createElement('div');
        grid.className = 'operator-grid';

        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'operator-empty';
            empty.appendChild(document.createTextNode(
                state.search
                    ? 'Nenhum operador corresponde à pesquisa.'
                    : 'Sem operadores neste turno.'
            ));
            grid.appendChild(empty);
        } else {
            for (var i = 0; i < items.length; i++) {
                grid.appendChild(buildCard(items[i]));
            }
        }

        body.appendChild(grid);
        section.appendChild(body);

        return section;
    }

    function buildCard(op) {
        var card = document.createElement('article');
        card.className = 'operator-card';

        var photo = document.createElement('div');
        photo.className = 'operator-photo';

        var initials = document.createElement('span');
        initials.className = 'operator-initials';
        initials.appendChild(document.createTextNode(getInitials(op.name)));
        photo.appendChild(initials);

        if (op.image) {
            var img = document.createElement('img');
            img.alt = op.name || 'Operador';
            img.loading = 'lazy';
            img.onerror = function () {
                img.style.display = 'none';
            };
            img.src = op.image;
            photo.appendChild(img);
        }

        card.appendChild(photo);

        var body = document.createElement('div');
        body.className = 'operator-body';

        var name = document.createElement('div');
        name.className = 'operator-name';
        name.appendChild(document.createTextNode(op.name || 'Sem nome'));
        body.appendChild(name);

        var metaParts = [];
        if (op.area) {
            metaParts.push(op.area);
        } else {
            if (op.line) {
                metaParts.push(op.line);
            }
            if (op.role) {
                metaParts.push(op.role);
            }
        }
        if (metaParts.length) {
            var meta = document.createElement('div');
            meta.className = 'operator-meta';
            meta.appendChild(document.createTextNode(metaParts.join(' · ')));
            body.appendChild(meta);
        }

        card.appendChild(body);
        card.title = (op.name || '') + (metaParts.length ? ' — ' + metaParts.join(' · ') : '');

        return card;
    }

    function renderUpdated() {
        var data = state.data || {};
        var meta = data.meta || {};
        var now = serverNow();
        var total = (data.counts && typeof data.counts.total === 'number') ? data.counts.total : 0;
        var text = 'Atualizado às ' + formatClock(now);
        if (total) {
            text += ' · ' + total + (total === 1 ? ' operador' : ' operadores');
        }
        setText('opUpdated', text);

        var source = meta.source || '';
        var sourceLabel = source === 'file-fallback'
            ? 'Dados de demonstração'
            : source === 'cache'
                ? 'API (cache)'
                : source === 'remote'
                    ? 'API'
                    : '';
        setText('opSource', sourceLabel);

        var demo = byId('opDemo');
        if (demo) {
            demo.style.display = source === 'file-fallback' ? 'block' : 'none';
        }
    }

    // ---------------------------------------------------------------------
    // Clock, countdown and progress
    // ---------------------------------------------------------------------

    function tick() {
        var now = serverNow();
        renderClock(now);
        renderCountdown(now);
        renderProgress(now);

        if (state.nextStartMs && now >= state.nextStartMs
            && !state.shiftRefreshPending && Date.now() >= state.boundaryRetryAt) {
            state.shiftRefreshPending = true;
            showNotice('Novo turno. A atualizar...', 'info', 0);
            load(true);
        }
    }

    function serverNow() {
        if (!state.serverEpochMs) {
            return Date.now();
        }
        return state.serverEpochMs + (Date.now() - state.receivedAt);
    }

    function renderClock(now) {
        setText('liveClock', formatClock(now));
    }

    function formatClock(now) {
        var date = new Date(now + state.tzOffsetMs);
        return pad2(date.getUTCHours()) + ':' + pad2(date.getUTCMinutes());
    }

    function renderCountdown(now) {
        var text = '--:--:--';
        if (state.nextStartMs) {
            var remaining = state.nextStartMs - now;
            text = remaining > 0 ? formatDuration(remaining) : 'a atualizar...';
        }
        setText('liveCountdown', text);
    }

    function renderProgress(now) {
        var bar = byId('liveProgressBar');
        if (!bar) {
            return;
        }

        var duration = state.shiftEndMs - state.shiftStartMs;
        if (duration <= 0) {
            bar.style.width = '0%';
            return;
        }

        var percent = ((now - state.shiftStartMs) / duration) * 100;
        percent = Math.max(0, Math.min(100, percent));
        bar.style.width = percent.toFixed(1) + '%';
    }

    // ---------------------------------------------------------------------
    // Feedback and loading state
    // ---------------------------------------------------------------------

    function setLoading(loading) {
        var button = byId('opRefresh');
        var waiting = byId('opWaiting');
        if (button) {
            button.disabled = !!loading;
        }
        if (waiting) {
            waiting.style.display = loading ? 'inline-block' : 'none';
        }
    }

    function hideNotice() {
        var box = byId('opNotice');
        if (box) {
            box.style.display = 'none';
        }
        if (state.noticeTimer) {
            clearTimeout(state.noticeTimer);
            state.noticeTimer = null;
        }
    }

    function showError(message) {
        var box = byId('opError');
        if (!box) {
            return;
        }
        box.textContent = 'Não foi possível carregar os operadores: ' + message;
        box.style.display = 'block';
    }

    function hideError() {
        var box = byId('opError');
        if (box) {
            box.style.display = 'none';
        }
    }

    function renderAfterError() {
        if (state.data) {
            return;
        }

        var wrap = byId('operatorsWrap');
        if (!wrap) {
            return;
        }
        wrap.innerHTML = '';
        wrap.appendChild(buildMessage('Sem dados para mostrar. A tentar novamente...'));
    }

    function showNotice(message, type, ms) {
        var box = byId('opNotice');
        if (!box) {
            return;
        }

        if (state.noticeTimer) {
            clearTimeout(state.noticeTimer);
            state.noticeTimer = null;
        }

        box.className = 'alert alert-' + (type || 'success') + ' op-notice';
        box.textContent = message;
        box.style.display = 'block';

        if (ms > 0) {
            state.noticeTimer = setTimeout(function () {
                box.style.display = 'none';
                state.noticeTimer = null;
            }, ms);
        }
    }

    function buildMessage(text) {
        var div = document.createElement('div');
        div.className = 'op-loading';
        div.appendChild(document.createTextNode(text));
        return div;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    function filterItems(items) {
        if (!state.search) {
            return items;
        }

        var out = [];
        for (var i = 0; i < items.length; i++) {
            var op = items[i];
            var haystack = [
                op.name || '',
                op.area || '',
                op.line || '',
                op.role || '',
                op.id || ''
            ].join(' ').toLowerCase();

            if (haystack.indexOf(state.search) !== -1) {
                out.push(op);
            }
        }
        return out;
    }

    function shiftDefinition(key) {
        if (!key) {
            return null;
        }

        var defs = (state.data && state.data.shifts) || [];
        return findShiftDef(defs, key) || DEFAULT_DEFS[key] || null;
    }

    function findShiftDef(defs, key) {
        for (var i = 0; i < defs.length; i++) {
            if (defs[i] && defs[i].key === key) {
                return defs[i];
            }
        }
        return null;
    }

    function initialFilter() {
        if (query.shift === '4T' || query.shift === '1T' || query.shift === '2T') {
            return query.shift;
        }
        return 'current';
    }

    function getInitials(name) {
        var parts = String(name || '').replace(/^\s+|\s+$/g, '').split(/\s+/);
        if (!parts.length || !parts[0]) {
            return '?';
        }
        var first = parts[0].charAt(0);
        var last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
        return (first + last).toUpperCase();
    }

    function formatDuration(ms) {
        var total = Math.max(0, Math.floor(ms / 1000));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var seconds = total % 60;
        return pad2(hours) + ':' + pad2(minutes) + ':' + pad2(seconds);
    }

    function formatHours(value) {
        var number = Number(value);
        if (!isFinite(number)) {
            return '';
        }
        return String(number).replace('.', ',');
    }

    function formatIsoDate(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '/' + match[2] + '/' + match[1] : '';
    }

    function numberOrNull(value) {
        var number = Number(value);
        return isFinite(number) && String(value) !== '' ? number : null;
    }

    function pad2(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        var el = byId(id);
        if (el) {
            el.textContent = value;
        }
    }

    function parseQuery() {
        var out = {};
        var qs = (window.location.search || '').replace(/^\?/, '');
        if (!qs) {
            return out;
        }

        qs.split('&').forEach(function (pair) {
            if (!pair) {
                return;
            }
            var eq = pair.indexOf('=');
            var key = eq < 0 ? pair : pair.slice(0, eq);
            var value = eq < 0 ? '' : pair.slice(eq + 1);
            try {
                key = decodeURIComponent(key.replace(/\+/g, ' '));
                value = decodeURIComponent(value.replace(/\+/g, ' '));
            } catch (e) {
                // Keep the raw values.
            }
            out[key] = value;
        });

        return out;
    }
})(window, document);

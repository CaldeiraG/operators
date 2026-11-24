/*
 * Operator management (operadores_admin.php).
 *
 * Lists operators, creates them with POST and removes them with DELETE. The
 * BFF (api/operadores.php) proxies the writes to the configured upstream.
 */
(function (window, document) {
    'use strict';

    var cfg = window.OperadoresAdminConfig || {};
    var SHIFT_FALLBACK = {
        '4T': '4º Turno',
        '1T': '1º Turno',
        '2T': '2º Turno'
    };

    var state = {
        data: null,
        loading: false,
        reloadAfterLoad: false,
        submitting: false,
        removing: false,
        photoUploading: false,
        photoTarget: null,
        search: '',
        shift: 'all',
        areaNames: {},
        areaIds: {}
    };

    var alertTimer = null;
    var searchTimer = null;

    init();

    // ---------------------------------------------------------------------
    // Boot / events
    // ---------------------------------------------------------------------

    function init() {
        var form = byId('operatorForm');
        if (form) {
            form.onsubmit = submitForm;
        }

        var search = byId('adminSearch');
        if (search) {
            search.oninput = function () {
                if (searchTimer) {
                    clearTimeout(searchTimer);
                }
                searchTimer = setTimeout(function () {
                    state.search = trim(search.value).toLowerCase();
                    renderTable();
                }, 120);
            };
        }

        var shift = byId('adminShift');
        if (shift) {
            shift.onchange = function () {
                state.shift = shift.value || 'all';
                renderTable();
            };
        }

        var refresh = byId('adminRefresh');
        if (refresh) {
            refresh.onclick = function () {
                load(true);
            };
        }

        var photoInput = byId('photoFileInput');
        if (photoInput) {
            photoInput.onchange = function () {
                var file = photoInput.files && photoInput.files[0];
                var target = state.photoTarget;
                photoInput.value = '';
                if (file && target) {
                    updatePhoto(target, file);
                }
            };
        }

        var imageFile = byId('opImageFile');
        if (imageFile) {
            imageFile.onchange = function () {
                var file = imageFile.files && imageFile.files[0];
                if (!file) {
                    previewImage('');
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    formError('A imagem excede 2 MB.');
                    imageFile.value = '';
                    previewImage('');
                    return;
                }
                readFile(file, function (err, dataUrl) {
                    if (err) {
                        formError(err);
                        return;
                    }
                    previewImage(dataUrl);
                });
            };
        }

        loadAreas();
        load(true);
    }

    // ---------------------------------------------------------------------
    // API
    // ---------------------------------------------------------------------

    function apiRequest(method, endpoint, params, body, done) {
        var base = (cfg.apiBase || '').replace(/\/?$/, '/');
        if (!base) {
            done('apiBase não configurado.', null);
            return;
        }

        var url = base + endpoint;
        var pairs = [];
        var key;
        params = params || {};

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

        xhr.open(method, url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        if (cfg.csrfToken) {
            xhr.setRequestHeader('X-CSRF-Token', cfg.csrfToken);
        }
        if (body !== null && typeof body !== 'undefined') {
            xhr.setRequestHeader('Content-Type', 'application/json');
        }
        xhr.timeout = 20000;

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            if (xhr.status === 401) {
                finish('Sessão expirada.', null);
                window.location.href = 'login.php?next=' + encodeURIComponent(
                    window.location.pathname + window.location.search
                );
                return;
            }

            var data = null;
            try {
                data = xhr.responseText ? JSON.parse(xhr.responseText) : null;
            } catch (e) {
                data = null;
            }

            if (xhr.status >= 200 && xhr.status < 300 && (!data || !data.error)) {
                finish(null, data || {});
            } else {
                finish(
                    (data && (data.message || data.error))
                        || (xhr.status ? 'HTTP ' + xhr.status : 'Falha de rede.'),
                    null
                );
            }
        };

        xhr.ontimeout = function () {
            finish('Tempo de resposta excedido.', null);
        };
        xhr.onerror = function () {
            finish('Falha de rede.', null);
        };

        xhr.send(body === null || typeof body === 'undefined' ? null : JSON.stringify(body));
    }

    function load(force) {
        if (state.loading) {
            state.reloadAfterLoad = true;
            return;
        }

        state.loading = true;
        setLoading(true);

        var params = force ? { '__refresh': '1' } : {};

        apiRequest('GET', 'operadores.php', params, null, function (err, data) {
            state.loading = false;
            setLoading(false);

            if (err) {
                state.reloadAfterLoad = false;
                showListError('Não foi possível carregar os operadores: ' + err);
                if (!state.data) {
                    renderLoadError();
                }
                return;
            }

            hideListError();
            state.data = data;
            renderShiftControls();
            renderTable();

            if (state.reloadAfterLoad) {
                state.reloadAfterLoad = false;
                load(true);
            }
        });
    }

    function loadAreas() {
        var select = byId('opArea');
        if (!select) {
            return;
        }

        select.disabled = true;
        select.innerHTML = '';
        var loading = document.createElement('option');
        loading.value = '';
        loading.text = 'A carregar áreas...';
        select.appendChild(loading);

        apiRequest('GET', 'areas.php', {}, null, function (err, data) {
            select.innerHTML = '';

            if (err) {
                var failed = document.createElement('option');
                failed.value = '';
                failed.text = 'Sem áreas disponíveis';
                select.appendChild(failed);
                select.disabled = true;
                setAreaError('Não foi possível carregar as áreas: ' + err);
                return;
            }

            var areas = data && data.areas ? data.areas : (Array.isArray(data) ? data : []);
            var names = {};
            var ids = {};
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.text = 'Selecione uma área';
            placeholder.disabled = true;
            placeholder.selected = true;
            select.appendChild(placeholder);

            for (var i = 0; i < areas.length; i++) {
                var area = areas[i] || {};
                var name = trim(area.name || area.nome || area.id || '');
                if (!name) {
                    continue;
                }
                var option = document.createElement('option');
                option.value = area.id !== null && typeof area.id !== 'undefined' && area.id !== ''
                    ? String(area.id)
                    : name;
                option.text = name;
                if (area.id !== null && typeof area.id !== 'undefined' && area.id !== '') {
                    option.setAttribute('data-area-id', area.id);
                    names[String(area.id)] = name;
                    ids[String(area.id)] = String(area.id);
                    ids[name] = String(area.id);
                }
                names[name] = name;
                select.appendChild(option);
            }

            state.areaNames = names;
            state.areaIds = ids;
            select.disabled = select.options.length <= 1;
            if (select.options.length <= 1) {
                setAreaError('Não existem áreas disponíveis.');
            } else {
                clearAreaError();
            }
            if (state.data) {
                renderTable();
            }
        });
    }

    // ---------------------------------------------------------------------
    // Create / remove
    // ---------------------------------------------------------------------

    function submitForm(event) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }

        clearFormError();

        if (state.submitting) {
            return;
        }

        var name = trim(valueOf('opName'));
        var shift = valueOf('opShift');
        var area = trim(valueOf('opArea'));

        if (!name) {
            formError('Indique o nome do operador.');
            focus('opName');
            return;
        }
        if (shift !== '4T' && shift !== '1T' && shift !== '2T') {
            formError('Escolha um turno válido.');
            return;
        }
        if (!area) {
            formError('Escolha uma área.');
            focus('opArea');
            return;
        }

        var file = null;
        var fileInput = byId('opImageFile');
        if (fileInput && fileInput.files && fileInput.files[0]) {
            file = fileInput.files[0];
            if (file.size > 2 * 1024 * 1024) {
                formError('A imagem excede 2 MB.');
                return;
            }
        }

        if (!file) {
            sendCreate(name, shift, area, '');
            return;
        }

        readFile(file, function (err, dataUrl) {
            if (err) {
                formError(err);
                return;
            }
            sendCreate(name, shift, area, dataUrl);
        });
    }

    function sendCreate(name, shift, area, image) {
        state.submitting = true;
        setSubmitting(true);

        var payload = {
            name: name,
            shift: shift,
            area: area,
            image: image
        };

        apiRequest('POST', 'operadores.php', {}, payload, function (err, data) {
            state.submitting = false;
            setSubmitting(false);

            if (err) {
                formError('Não foi possível adicionar: ' + err);
                return;
            }

            showAlert('Operador adicionado: ' + name, 'success', 5000);
            resetForm();
            load(true);
            focus('opName');
        });
    }

    function changePhoto(op) {
        if (!op.id || state.photoUploading) {
            return;
        }
        state.photoTarget = op;
        var input = byId('photoFileInput');
        if (input) {
            input.value = '';
            input.click();
        }
    }

    function updatePhoto(op, file) {
        if (!op.id || state.photoUploading) {
            return;
        }
        if (file.type && file.type.indexOf('image/') !== 0) {
            showAlert('O ficheiro selecionado não é uma imagem.', 'danger', 5000);
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showAlert('A imagem excede 2 MB.', 'danger', 5000);
            return;
        }

        state.photoUploading = true;
        showAlert('A atualizar a fotografia de ' + op.name + '...', 'info', 0);

        readFile(file, function (err, dataUrl) {
            if (err) {
                state.photoUploading = false;
                showAlert('Não foi possível ler a imagem: ' + err, 'danger', 0);
                return;
            }

            var payload = { id: op.id, image: dataUrl };
            var area = areaId(op.area);
            if (area) {
                payload.area = area;
            }

            apiRequest('PATCH', 'operadores.php', {}, payload, function (uploadErr) {
                state.photoUploading = false;

                if (uploadErr) {
                    showAlert('Não foi possível atualizar a fotografia de ' + op.name + ': ' + uploadErr, 'danger', 0);
                    return;
                }

                showAlert('Fotografia atualizada: ' + op.name, 'success', 5000);
                load(true);
            });
        });
    }

    function removeOperator(op, button) {
        if (state.removing || !op.id) {
            return;
        }
        if (!window.confirm('Remover ' + op.name + ' do quadro?')) {
            return;
        }

        state.removing = true;
        if (button) {
            button.disabled = true;
            button.textContent = 'A remover...';
        }

        var params = { id: op.id };
        var area = areaId(op.area);
        if (area) {
            params.area = area;
        }

        apiRequest('DELETE', 'operadores.php', params, null, function (err, data) {
            state.removing = false;

            if (err) {
                if (button) {
                    button.disabled = false;
                    button.textContent = 'Remover';
                }
                showAlert('Não foi possível remover ' + op.name + ': ' + err, 'danger', 0);
                return;
            }

            showAlert('Operador removido: ' + op.name, 'success', 5000);
            load(true);
        });
    }

    // ---------------------------------------------------------------------
    // Rendering
    // ---------------------------------------------------------------------

    function renderShiftControls() {
        var defs = (state.data && state.data.shifts) || [];
        var select = byId('adminShift');
        var formSelect = byId('opShift');
        var i;

        if (formSelect) {
            for (i = 0; i < formSelect.options.length; i++) {
                var option = formSelect.options[i];
                var def = findShift(defs, option.value);
                if (def) {
                    option.text = shiftText(def);
                }
            }
        }

        if (!select) {
            return;
        }

        var current = state.shift;
        select.innerHTML = '';

        var all = document.createElement('option');
        all.value = 'all';
        all.text = 'Todos';
        select.appendChild(all);

        var found = current === 'all';
        for (i = 0; i < defs.length; i++) {
            var option = document.createElement('option');
            option.value = defs[i].key;
            option.text = defs[i].label || defs[i].key;
            select.appendChild(option);
            if (defs[i].key === current) {
                found = true;
            }
        }

        if (!found) {
            state.shift = 'all';
        }
        select.value = state.shift;
    }

    function renderTable() {
        var body = byId('adminOperators');
        var empty = byId('adminEmpty');
        if (!body) {
            return;
        }

        var data = state.data || {};
        var operators = data.operators || [];
        var filtered = [];
        var i;

        for (i = 0; i < operators.length; i++) {
            var op = operators[i];
            if (state.shift !== 'all' && op.shift !== state.shift) {
                continue;
            }
            if (state.search) {
                var haystack = [
                    op.name || '',
                    op.area || '',
                    areaLabel(op.area),
                    op.line || '',
                    op.role || '',
                    op.id || ''
                ].join(' ').toLowerCase();
                if (haystack.indexOf(state.search) === -1) {
                    continue;
                }
            }
            filtered.push(op);
        }

        body.innerHTML = '';

        for (i = 0; i < filtered.length; i++) {
            body.appendChild(buildRow(filtered[i]));
        }

        if (!filtered.length) {
            if (empty) {
                empty.style.display = 'block';
                empty.textContent = state.search || state.shift !== 'all'
                    ? 'Nenhum operador corresponde aos filtros.'
                    : 'Sem operadores para mostrar.';
            }
        } else if (empty) {
            empty.style.display = 'none';
        }

        var countText = filtered.length + (filtered.length === 1 ? ' operador' : ' operadores');
        if (filtered.length !== operators.length) {
            countText += ' de ' + operators.length;
        }
        setText('adminCount', countText);
    }

    function buildRow(op) {
        var tr = document.createElement('tr');
        tr.className = 'admin-operator-row';

        if (op.id) {
            tr.ondragover = function (event) {
                event.preventDefault();
                tr.classList.add('is-dragover');
            };
            tr.ondragleave = function () {
                tr.classList.remove('is-dragover');
            };
            tr.ondrop = function (event) {
                event.preventDefault();
                tr.classList.remove('is-dragover');
                var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
                if (file) {
                    updatePhoto(op, file);
                }
            };
        }

        var photoCell = document.createElement('td');
        photoCell.style.width = '52px';
        photoCell.appendChild(buildAvatar(op));
        tr.appendChild(photoCell);

        var nameCell = document.createElement('td');
        nameCell.appendChild(document.createTextNode(op.name || 'Sem nome'));
        tr.appendChild(nameCell);

        var shiftCell = document.createElement('td');
        var badge = document.createElement('span');
        badge.className = 'admin-shift';
        badge.setAttribute('data-shift', op.shift || 'other');
        badge.appendChild(document.createTextNode(shiftLabel(op.shift)));
        shiftCell.appendChild(badge);
        tr.appendChild(shiftCell);

        tr.appendChild(textCell(areaLabel(op.area)));

        var actionCell = document.createElement('td');
        actionCell.className = 'text-right';

        if (op.id) {
            var photoButton = document.createElement('button');
            photoButton.type = 'button';
            photoButton.className = 'btn btn-sm btn-outline-secondary mr-1';
            photoButton.appendChild(document.createTextNode('Alterar foto'));
            photoButton.onclick = function () {
                changePhoto(op);
            };
            actionCell.appendChild(photoButton);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-danger';
            button.appendChild(document.createTextNode('Remover'));
            button.onclick = function () {
                removeOperator(op, button);
            };
            actionCell.appendChild(button);
        } else {
            var noId = document.createElement('span');
            noId.className = 'admin-muted small';
            noId.appendChild(document.createTextNode('Sem ID'));
            actionCell.appendChild(noId);
        }

        tr.appendChild(actionCell);

        return tr;
    }

    function buildAvatar(op) {
        var wrap = document.createElement('div');
        wrap.className = 'admin-avatar';

        var initials = document.createElement('span');
        initials.className = 'initials';
        initials.appendChild(document.createTextNode(getInitials(op.name)));
        wrap.appendChild(initials);

        if (op.image) {
            var img = document.createElement('img');
            img.alt = op.name || 'Operador';
            img.loading = 'lazy';
            img.onerror = function () {
                img.style.display = 'none';
            };
            img.src = op.image;
            wrap.appendChild(img);
        }

        return wrap;
    }

    function textCell(value) {
        var td = document.createElement('td');
        if (value) {
            td.appendChild(document.createTextNode(value));
        } else {
            td.className = 'admin-muted';
            td.appendChild(document.createTextNode('—'));
        }
        return td;
    }

    function renderLoadError() {
        var body = byId('adminOperators');
        if (!body) {
            return;
        }
        body.innerHTML = '';
        var tr = document.createElement('tr');
        var td = document.createElement('td');
        td.colSpan = 5;
        td.className = 'admin-empty';
        td.appendChild(document.createTextNode('Não foi possível carregar os operadores.'));
        tr.appendChild(td);
        body.appendChild(tr);
    }

    // ---------------------------------------------------------------------
    // Form helpers
    // ---------------------------------------------------------------------

    function resetForm() {
        var form = byId('operatorForm');
        if (form) {
            form.reset();
        }
        var file = byId('opImageFile');
        if (file) {
            file.value = '';
        }
        previewImage('');
        clearFormError();
    }

    function setLoading(loading) {
        var button = byId('adminRefresh');
        if (button) {
            button.disabled = !!loading;
            button.textContent = loading ? 'A atualizar...' : 'Atualizar';
        }
    }

    function setSubmitting(submitting) {
        var button = byId('opSubmit');
        if (button) {
            button.disabled = !!submitting;
            button.textContent = submitting ? 'A adicionar...' : 'Adicionar operador';
        }
    }

    function formError(message) {
        var box = byId('opFormError');
        if (box) {
            box.textContent = message;
            box.style.display = 'block';
        }
    }

    function clearFormError() {
        var box = byId('opFormError');
        if (box) {
            box.style.display = 'none';
            box.textContent = '';
        }
    }

    function setAreaError(message) {
        var box = byId('areaError');
        if (box) {
            box.textContent = message;
            box.style.display = 'block';
        }
    }

    function clearAreaError() {
        var box = byId('areaError');
        if (box) {
            box.style.display = 'none';
            box.textContent = '';
        }
    }

    function showListError(message) {
        var box = byId('adminError');
        if (box) {
            box.textContent = message;
            box.style.display = 'block';
        }
    }

    function hideListError() {
        var box = byId('adminError');
        if (box) {
            box.style.display = 'none';
            box.textContent = '';
        }
    }

    function showAlert(message, type, ms) {
        var box = byId('adminAlert');
        if (!box) {
            return;
        }

        if (alertTimer) {
            clearTimeout(alertTimer);
            alertTimer = null;
        }

        box.className = 'alert alert-' + (type || 'success');
        box.textContent = message;
        box.style.display = 'block';

        if (ms > 0) {
            alertTimer = setTimeout(function () {
                box.style.display = 'none';
                alertTimer = null;
            }, ms);
        }
    }

    function previewImage(src) {
        var wrap = byId('photoPreview');
        var img = byId('photoPreviewImg');
        if (!wrap || !img) {
            return;
        }

        src = trim(src);
        if (!src) {
            wrap.style.display = 'none';
            img.removeAttribute('src');
            return;
        }

        var probe = new Image();
        probe.onload = function () {
            img.src = src;
            wrap.style.display = 'block';
        };
        probe.onerror = function () {
            wrap.style.display = 'none';
        };
        probe.src = src;
    }

    function readFile(file, done) {
        var reader = new FileReader();
        reader.onload = function () {
            done(null, String(reader.result || ''));
        };
        reader.onerror = function () {
            done('Não foi possível ler o ficheiro.', null);
        };
        reader.readAsDataURL(file);
    }

    // ---------------------------------------------------------------------
    // Data helpers
    // ---------------------------------------------------------------------

    function findShift(defs, key) {
        for (var i = 0; i < defs.length; i++) {
            if (defs[i] && defs[i].key === key) {
                return defs[i];
            }
        }
        return null;
    }

    function areaLabel(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '';
        }
        var key = String(value);
        return state.areaNames[key] || key;
    }

    function areaId(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '';
        }
        var key = String(value);
        return state.areaIds[key] || key;
    }

    function shiftLabel(key) {
        if (!key) {
            return 'Sem turno';
        }
        var def = findShift((state.data && state.data.shifts) || [], key);
        return def && def.label ? def.label : (SHIFT_FALLBACK[key] || key);
    }

    function shiftText(def) {
        var text = def.label || def.key;
        if (def.start && def.end) {
            text += ' (' + def.start + ' - ' + def.end + ')';
        }
        return text;
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

    function valueOf(id) {
        var el = byId(id);
        return el ? String(el.value || '') : '';
    }

    function trim(value) {
        return String(value || '').replace(/^\s+|\s+$/g, '');
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

    function focus(id) {
        var el = byId(id);
        if (el && el.focus) {
            el.focus();
        }
    }
})(window, document);

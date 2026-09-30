/**
 * Drepturi de acces — comportamentul consolei ACL.
 *
 * Tot continutul (module, actiuni, grupuri) e randat de server din PermissionRegistry;
 * scriptul doar citeste checkbox-urile [data-perm="modul.actiune"], tine evidenta
 * modificarilor si le trimite la salvare. Nu cunoaste nicio pagina anume.
 */
(function () {
    'use strict';

    var root = document.getElementById('dax');
    var form = document.getElementById('daxForm');
    if (!root) {
        return;
    }

    var dataEl = document.getElementById('daxData');
    var data = {};
    try {
        data = JSON.parse(dataEl ? dataEl.textContent : '{}') || {};
    } catch (e) {
        data = {};
    }
    var roleDefaults = data.roleDefaults || {};
    var templates = Array.isArray(data.templates) ? data.templates : [];

    var userId = root.dataset.userId;
    var isAdminUser = root.dataset.adminUser === '1';
    var configured = root.dataset.configured === '1';
    var csrf = root.dataset.csrf;
    var roleLabel = root.dataset.roleLabel || 'rol';

    var modules = form ? Array.prototype.slice.call(form.querySelectorAll('.dax-module')) : [];
    var sections = form ? Array.prototype.slice.call(form.querySelectorAll('.dax-section')) : [];
    var inputs = form ? Array.prototype.slice.call(form.querySelectorAll('input[data-perm]')) : [];
    var savebar = document.getElementById('daxSavebar');
    var dirtyText = document.getElementById('daxDirtyText');
    var searchInput = document.getElementById('daxSearch');
    var noResults = document.getElementById('daxNoResults');
    var sourceChip = document.getElementById('daxSourceChip');

    var activeFilter = 'all';
    var searchQuery = '';
    var autoOpened = new Set();
    var busy = false;
    var leaving = false;
    var baseline = snapshot();

    // ------------------------------------------------------------ utilitare

    function snapshot() {
        var state = {};
        inputs.forEach(function (input) { state[input.dataset.perm] = input.checked; });
        return state;
    }

    function splitKey(key) {
        var dot = key.indexOf('.');
        return [key.slice(0, dot), key.slice(dot + 1)];
    }

    function inMap(map, key) {
        var parts = splitKey(key);
        return !!(map && map[parts[0]] && map[parts[0]][parts[1]]);
    }

    function applyMap(map) {
        inputs.forEach(function (input) { input.checked = inMap(map, input.dataset.perm); });
    }

    function dirtyCount() {
        return inputs.filter(function (input) { return input.checked !== baseline[input.dataset.perm]; }).length;
    }

    function normalize(text) {
        return (text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    function toast(message, type) {
        var el = document.getElementById('daxToast');
        if (!el) {
            return;
        }
        el.textContent = message;
        el.className = 'dax-toast' + (type ? ' is-' + type : '');
        el.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(function () { el.hidden = true; }, type === 'error' ? 7000 : 3500);
    }

    function confirmDialog(title, text, okLabel, danger) {
        var dialog = document.getElementById('daxConfirm');
        if (!dialog || typeof dialog.showModal !== 'function') {
            return Promise.resolve(window.confirm(text));
        }
        dialog.querySelector('[data-confirm-title]').textContent = title;
        dialog.querySelector('[data-confirm-text]').textContent = text;
        var ok = dialog.querySelector('[data-confirm-ok]');
        ok.textContent = okLabel || 'Confirmă';
        ok.className = 'dax-btn ' + (danger ? 'dax-btn-danger' : 'dax-btn-primary');
        dialog.returnValue = '';
        dialog.showModal();
        return new Promise(function (resolve) {
            dialog.addEventListener('close', function onClose() {
                dialog.removeEventListener('close', onClose);
                resolve(dialog.returnValue === 'ok');
            });
        });
    }

    function post(url, fields, withPerms) {
        var body = new FormData();
        body.append('_token', csrf);
        body.append('user_id', userId);
        Object.keys(fields || {}).forEach(function (name) { body.append(name, fields[name]); });
        if (withPerms) {
            // Toate cheile editabile, inclusiv actiunile unei pagini oprite (se pastreaza).
            inputs.forEach(function (input) {
                var parts = splitKey(input.dataset.perm);
                body.append('perm[' + parts[0] + '][' + parts[1] + ']', input.checked ? '1' : '0');
            });
        }
        return fetch(url, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, message: 'Răspuns neașteptat de la server (' + response.status + ').' };
            });
        }).catch(function () {
            return { success: false, message: 'Nu am putut contacta serverul. Verifică conexiunea.' };
        });
    }

    function setBusy(value) {
        busy = value;
        root.classList.toggle('is-busy', value);
    }

    // ------------------------------------------------------------ calcul stare

    function refresh() {
        var totals = { all: modules.length, allowed: 0, blocked: 0, custom: 0, admin: 0 };
        var kpiActions = 0;
        var kpiLocked = 0;

        modules.forEach(function (module) {
            var view = module.querySelector('input[data-view]');
            var isOn = !!(view && view.checked);
            module.classList.toggle('is-off', !isOn);
            module.dataset.state = isOn ? 'allowed' : 'blocked';

            var actions = module.querySelectorAll('input[data-action]');
            var checkedActions = 0;
            actions.forEach(function (input) {
                if (input.hasAttribute('data-locked')) {
                    kpiLocked++;
                } else if (!isAdminUser) {
                    input.disabled = !isOn;
                }
                if (input.checked) {
                    checkedActions++;
                }
            });
            if (view && view.hasAttribute('data-locked')) {
                kpiLocked++;
            }

            // diferente fata de rol (personalizari)
            var customCount = 0;
            module.querySelectorAll('input[data-perm]').forEach(function (input) {
                var differs = !isAdminUser && input.checked !== inMap(roleDefaults, input.dataset.perm);
                if (differs) {
                    customCount++;
                }
                var row = input.closest('[data-action-row]');
                var chip = row ? row.querySelector('[data-action-custom]') : null;
                if (chip) {
                    chip.hidden = !differs;
                    chip.textContent = input.checked ? 'Personalizat' : 'Retras față de rol';
                    chip.classList.toggle('is-removed', !input.checked);
                    chip.title = input.checked
                        ? 'Acordată explicit — rolul ' + roleLabel + ' nu o are implicit.'
                        : 'Retrasă explicit — rolul ' + roleLabel + ' o are implicit.';
                }
            });
            var moduleChip = module.querySelector('[data-module-custom]');
            if (moduleChip) {
                moduleChip.hidden = customCount === 0;
            }
            module.dataset.custom = customCount > 0 ? '1' : '0';

            var tally = module.querySelector('[data-module-tally]');
            if (tally) {
                tally.textContent = (isOn ? checkedActions : 0) + ' / ' + actions.length + ' acțiuni';
            }

            if (isOn) {
                totals.allowed++;
                kpiActions += checkedActions;
            } else {
                totals.blocked++;
            }
            if (customCount > 0) {
                totals.custom++;
            }
            if (module.dataset.hasAdmin === '1') {
                totals.admin++;
            }
        });

        setText('[data-kpi="pages"]', totals.allowed);
        setText('[data-kpi="actions"]', kpiActions);
        setText('[data-kpi="blocked"]', totals.blocked);
        setText('[data-kpi="admin"]', isAdminUser ? 0 : kpiLocked);
        Object.keys(totals).forEach(function (key) { setText('[data-count="' + key + '"]', totals[key]); });

        sections.forEach(function (section) {
            var list = section.querySelectorAll('.dax-module');
            var on = section.querySelectorAll('.dax-module[data-state="allowed"]').length;
            var pct = list.length ? Math.round((on / list.length) * 100) : 0;
            section.querySelector('[data-section-count]').textContent = on + ' / ' + list.length + ' pagini accesibile';
            section.querySelector('[data-section-bar]').style.width = pct + '%';
            section.querySelector('[data-section-pct]').textContent = pct + '%';
        });

        var dirty = dirtyCount();
        if (savebar) {
            savebar.hidden = dirty === 0;
        }
        if (dirtyText) {
            dirtyText.textContent = dirty === 1 ? 'Ai o modificare nesalvată' : 'Ai ' + dirty + ' modificări nesalvate';
        }
        if (sourceChip && !isAdminUser) {
            sourceChip.textContent = configured ? 'Drepturi personalizate' : 'Moștenește rolul';
            sourceChip.classList.toggle('is-custom', configured);
        }

        applyFilters();
    }

    function setText(selector, value) {
        root.querySelectorAll(selector).forEach(function (el) { el.textContent = String(value); });
    }

    // ------------------------------------------------------------ filtre + cautare

    function matchesFilter(module) {
        switch (activeFilter) {
            case 'allowed': return module.dataset.state === 'allowed';
            case 'blocked': return module.dataset.state === 'blocked';
            case 'custom': return module.dataset.custom === '1';
            case 'admin': return module.dataset.hasAdmin === '1';
            default: return true;
        }
    }

    function applyFilters() {
        var q = normalize(searchQuery);
        var anyVisible = false;

        modules.forEach(function (module) {
            var rows = module.querySelectorAll('[data-action-row]');
            var moduleHit = q === '' || normalize(module.dataset.search).indexOf(q) !== -1;
            var actionHit = false;
            rows.forEach(function (row) {
                var hit = q !== '' && normalize(row.dataset.search).indexOf(q) !== -1;
                row.classList.toggle('is-match', hit);
                actionHit = actionHit || hit;
            });

            var visible = matchesFilter(module) && (moduleHit || actionHit);
            module.hidden = !visible;
            anyVisible = anyVisible || visible;

            // o actiune gasita deschide modulul; se inchide la loc cand cautarea se goleste
            var key = module.dataset.module;
            if (visible && actionHit && !moduleHit && !module.classList.contains('is-open')) {
                setOpen(module, true);
                autoOpened.add(key);
            } else if ((!actionHit || moduleHit) && autoOpened.has(key)) {
                setOpen(module, false);
                autoOpened.delete(key);
            }
        });

        sections.forEach(function (section) {
            section.hidden = section.querySelectorAll('.dax-module:not([hidden])').length === 0;
            if (q !== '' && !section.hidden) {
                section.classList.remove('is-collapsed');
            }
        });
        if (noResults) {
            noResults.hidden = anyVisible || modules.length === 0;
        }
    }

    function setOpen(module, open) {
        var body = module.querySelector('.dax-module-body');
        var toggle = module.querySelector('[data-toggle-module]');
        if (!body || !toggle) {
            return;
        }
        module.classList.toggle('is-open', open);
        body.hidden = !open;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    // ------------------------------------------------------------ actiuni server

    function save() {
        if (busy || dirtyCount() === 0 || isAdminUser) {
            return;
        }
        setBusy(true);
        post(root.dataset.urlSave, {}, true).then(function (res) {
            setBusy(false);
            if (!res.success) {
                toast(res.message || 'Salvarea a eșuat. Modificările tale sunt încă pe ecran.', 'error');
                return;
            }
            if (res.granted) {
                applyMap(res.granted);
            }
            configured = true;
            baseline = snapshot();
            refresh();
            toast(res.message || 'Drepturile au fost salvate.', 'success');
        });
    }

    function resetToRole() {
        if (busy || isAdminUser) {
            return;
        }
        confirmDialog(
            'Resetezi la rol?',
            'Toate drepturile personalizate ale utilizatorului vor fi șterse și va moșteni din nou drepturile rolului ' + roleLabel + '. Rolul în sine nu se modifică.',
            'Resetează la rol',
            true
        ).then(function (ok) {
            if (!ok) {
                return;
            }
            setBusy(true);
            post(root.dataset.urlReset, {}, false).then(function (res) {
                setBusy(false);
                if (!res.success) {
                    toast(res.message || 'Resetarea a eșuat.', 'error');
                    return;
                }
                applyMap(res.granted || roleDefaults);
                configured = false;
                baseline = snapshot();
                refresh();
                toast(res.message || 'Utilizatorul a revenit la drepturile rolului.', 'success');
            });
        });
    }

    function discard() {
        inputs.forEach(function (input) { input.checked = !!baseline[input.dataset.perm]; });
        refresh();
    }

    function applyTemplate(id) {
        var template = templates.find(function (t) { return String(t.id) === String(id); });
        if (!template) {
            return;
        }
        applyMap(template.perms || {});
        refresh();
        closeMenu();
        toast('Șablonul „' + template.name + '” a fost aplicat. Salvează pentru a-l confirma.');
    }

    // ------------------------------------------------------------ meniul de sabloane

    var tplToggle = document.getElementById('daxTplToggle');
    var tplMenu = document.getElementById('daxTplMenu');

    function closeMenu() {
        if (tplMenu && !tplMenu.hidden) {
            tplMenu.hidden = true;
            tplToggle.setAttribute('aria-expanded', 'false');
        }
    }

    if (tplToggle && tplMenu) {
        tplToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            tplMenu.hidden = !tplMenu.hidden;
            tplToggle.setAttribute('aria-expanded', tplMenu.hidden ? 'false' : 'true');
        });
        document.addEventListener('click', function (event) {
            if (!tplMenu.contains(event.target)) {
                closeMenu();
            }
        });
        tplMenu.addEventListener('click', function (event) {
            var apply = event.target.closest('[data-apply-template]');
            if (apply) {
                applyTemplate(apply.dataset.applyTemplate);
                return;
            }
            var del = event.target.closest('[data-delete-template]');
            if (del) {
                closeMenu();
                confirmDialog('Ștergi șablonul?', 'Șablonul „' + del.dataset.name + '” va fi șters. Utilizatorii cărora le-a fost aplicat nu sunt afectați.', 'Șterge', true)
                    .then(function (ok) {
                        if (!ok) {
                            return;
                        }
                        post(root.dataset.urlDeleteTemplate, { template_id: del.dataset.deleteTemplate }, false).then(function (res) {
                            if (!res.success) {
                                toast(res.message || 'Nu am putut șterge șablonul.', 'error');
                                return;
                            }
                            templates = templates.filter(function (t) { return String(t.id) !== del.dataset.deleteTemplate; });
                            var row = del.closest('.dax-menu-row');
                            if (row) {
                                row.remove();
                            }
                            var option = document.querySelector('#daxTemplateForm option[value="' + del.dataset.deleteTemplate + '"]');
                            if (option) {
                                option.remove();
                            }
                            toast(res.message, 'success');
                        });
                    });
            }
        });
    }

    function addTemplateToMenu(id, name) {
        if (!tplMenu) {
            return;
        }
        var empty = tplMenu.querySelector('.dax-menu-empty');
        if (empty) {
            empty.remove();
        }
        var row = document.createElement('div');
        row.className = 'dax-menu-row';
        var apply = document.createElement('button');
        apply.type = 'button';
        apply.className = 'dax-menu-item';
        apply.setAttribute('role', 'menuitem');
        apply.dataset.applyTemplate = String(id);
        apply.innerHTML = '<i class="bi bi-person-check" aria-hidden="true"></i> ';
        apply.appendChild(document.createTextNode(name));
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'dax-menu-del';
        del.dataset.deleteTemplate = String(id);
        del.dataset.name = name;
        del.title = 'Șterge șablonul';
        del.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';
        row.appendChild(apply);
        row.appendChild(del);
        tplMenu.appendChild(row);

        var select = tplForm ? tplForm.elements.template_id : null;
        if (select) {
            var option = document.createElement('option');
            option.value = String(id);
            option.dataset.name = name;
            option.textContent = 'Suprascrie „' + name + '”';
            select.appendChild(option);
        }
    }

    // ------------------------------------------------------------ salvare ca sablon

    var tplDialog = document.getElementById('daxTemplateDialog');
    var tplForm = document.getElementById('daxTemplateForm');

    root.querySelectorAll('[data-open-save-template]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!tplDialog || typeof tplDialog.showModal !== 'function') {
                return;
            }
            tplForm.reset();
            tplDialog.returnValue = '';
            tplDialog.showModal();
            tplForm.elements.template_name.focus();
        });
    });

    if (tplForm) {
        tplForm.elements.template_id.addEventListener('change', function () {
            var option = this.options[this.selectedIndex];
            if (option && option.dataset.name) {
                tplForm.elements.template_name.value = option.dataset.name;
            }
        });
        tplDialog.addEventListener('close', function () {
            if (tplDialog.returnValue !== 'ok') {
                return;
            }
            var name = tplForm.elements.template_name.value.trim();
            var templateId = tplForm.elements.template_id.value;
            if (name === '') {
                return;
            }
            post(root.dataset.urlSaveTemplate, { template_name: name, template_id: templateId }, true).then(function (res) {
                if (!res.success) {
                    toast(res.message || 'Nu am putut salva șablonul.', 'error');
                    return;
                }
                toast(res.message, 'success');
                if (dirtyCount() === 0) {
                    leaving = true;
                    window.location.reload();
                    return;
                }
                // Cu modificari nesalvate nu reincarcam pagina; actualizam sablonul in memorie.
                var perms = {};
                inputs.forEach(function (input) {
                    if (input.checked) {
                        var parts = splitKey(input.dataset.perm);
                        (perms[parts[0]] = perms[parts[0]] || {})[parts[1]] = true;
                    }
                });
                var saved = res.template || { id: templateId, name: name };
                var existing = templates.find(function (t) { return String(t.id) === String(saved.id); });
                if (existing) {
                    existing.perms = perms;
                    existing.name = name;
                } else {
                    templates.push({ id: saved.id, name: name, system: false, perms: perms });
                    addTemplateToMenu(saved.id, name);
                }
            });
        });
    }

    // ------------------------------------------------------------ evenimente

    if (form) {
        form.addEventListener('change', function (event) {
            if (event.target.matches('input[data-perm]')) {
                refresh();
            }
        });

        form.addEventListener('click', function (event) {
            var head = event.target.closest('.dax-section-head');
            if (head) {
                var section = head.closest('.dax-section');
                var collapsed = section.classList.toggle('is-collapsed');
                head.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                return;
            }
            var toggle = event.target.closest('[data-toggle-module]');
            if (toggle && !event.target.closest('.dax-switch')) {
                var module = toggle.closest('.dax-module');
                setOpen(module, !module.classList.contains('is-open'));
                autoOpened.delete(module.dataset.module);
            }
        });

        form.addEventListener('keydown', function (event) {
            var toggle = event.target.closest('[data-toggle-module]');
            if (toggle && event.target === toggle && (event.key === 'Enter' || event.key === ' ')) {
                event.preventDefault();
                toggle.click();
            }
        });
    }

    root.querySelectorAll('[data-filter]').forEach(function (pill) {
        pill.addEventListener('click', function () {
            activeFilter = pill.dataset.filter;
            root.querySelectorAll('[data-filter]').forEach(function (p) {
                p.classList.toggle('is-active', p === pill);
                p.setAttribute('aria-pressed', p === pill ? 'true' : 'false');
            });
            applyFilters();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            searchQuery = searchInput.value;
            applyFilters();
        });
    }

    root.querySelectorAll('[data-save]').forEach(function (b) { b.addEventListener('click', save); });
    root.querySelectorAll('[data-discard]').forEach(function (b) { b.addEventListener('click', discard); });
    root.querySelectorAll('[data-reset-role]').forEach(function (b) { b.addEventListener('click', resetToRole); });

    document.addEventListener('keydown', function (event) {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && dirtyCount() > 0) {
            event.preventDefault();
            save();
        }
        if (event.key === 'Escape') {
            closeMenu();
        }
    });

    // ------------------------------------------------------------ lista de utilizatori

    var userSearch = document.getElementById('daxUserSearch');
    var userRole = '';
    var userLinks = Array.prototype.slice.call(root.querySelectorAll('.dax-user'));

    function filterUsers() {
        var q = normalize(userSearch ? userSearch.value : '');
        userLinks.forEach(function (link) {
            var ok = (userRole === '' || link.dataset.role === userRole) && (q === '' || normalize(link.dataset.search).indexOf(q) !== -1);
            link.hidden = !ok;
        });
    }

    if (userSearch) {
        userSearch.addEventListener('input', filterUsers);
    }
    root.querySelectorAll('[data-user-role]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            userRole = chip.dataset.userRole;
            root.querySelectorAll('[data-user-role]').forEach(function (c) { c.classList.toggle('is-active', c === chip); });
            filterUsers();
        });
    });

    // Schimbarea utilizatorului cu modificari nesalvate cere confirmare.
    userLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (dirtyCount() === 0 || link.classList.contains('is-selected')) {
                return;
            }
            event.preventDefault();
            confirmDialog('Modificări nesalvate', 'Ai ' + dirtyCount() + ' modificări nesalvate pentru utilizatorul curent. Le abandonezi?', 'Abandonează și continuă', true)
                .then(function (ok) {
                    if (ok) {
                        leaving = true;
                        window.location.href = link.href;
                    }
                });
        });
    });

    window.addEventListener('beforeunload', function (event) {
        if (!leaving && dirtyCount() > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    refresh();
})();

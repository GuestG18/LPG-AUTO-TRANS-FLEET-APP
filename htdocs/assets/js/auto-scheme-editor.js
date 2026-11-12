/*
 * Editorul schemei pe pagina Auto (views/maintenance/_scheme_editor.php).
 * - Configuratie generala: „Editează schema” pune schema in modul de editare (vehicle-xray.js:
 *   setEditing); punctele se trag, „Salvează pozițiile” trimite {loc: [x, y] | null} (op positions_save).
 *   Din bara se adauga sisteme pe schema (standard sau proprii); pozitiile nesalvate pleaca odata cu ele.
 * - Vehicul: pozitiile vin din configuratia generala, butonul duce acolo.
 * - Dialogul unui sistem propriu: nou („Sistem nou…”) sau editat (creionul din sectiunea lui).
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-scheme-editor]');
    if (!root) { return; }

    var holder = document.querySelector('[data-auto-xray]');
    var section = holder ? holder.closest('[data-auto-xray-section]') : null;
    var head = section ? section.querySelector('.repair-auto-xray-head') : null;
    var mode = root.dataset.mode;
    var bar = root.querySelector('[data-scheme-bar]');
    var form = root.querySelector('[data-scheme-form]');
    var dialog = root.querySelector('[data-scheme-dialog]');
    var customForm = root.querySelector('[data-scheme-custom-form]');
    var customs = {};
    try { customs = JSON.parse((document.getElementById('asSchemeCustom') || {}).textContent || '{}') || {}; } catch (error) { customs = {}; }

    var editing = false;
    var dirty = 0;
    var editBtn = null;
    var explorer = function () { return holder && window.FleetXray ? window.FleetXray.get(holder) : null; };

    /* ---------------------------------------------------------------- modul de editare */

    var countEl = bar ? bar.querySelector('[data-scheme-count]') : null;
    var onChange = function (count) {
        dirty = count;
        if (countEl) {
            countEl.hidden = count === 0;
            countEl.textContent = String(count);
        }
    };

    var syncButton = function () {
        if (!editBtn) { return; }
        editBtn.setAttribute('aria-pressed', editing ? 'true' : 'false');
        editBtn.classList.toggle('is-active', editing);
    };

    var startEditing = function () {
        if (editing || mode !== 'structure') { return; }
        var toggle = document.querySelector('[data-auto-xray-toggle]');
        if (holder.hidden && toggle) { toggle.click(); }
        var ex = explorer();
        if (!ex || typeof ex.setEditing !== 'function') { return; }
        ex.setEditing(true, onChange);
        editing = true;
        onChange(0);
        if (bar) {
            holder.parentNode.insertBefore(bar, holder);
            bar.hidden = false;
        }
        syncButton();
    };

    var stopEditing = function (discard) {
        var ex = explorer();
        if (ex) {
            if (discard) { ex.discardEdits(); }
            ex.setEditing(false);
        }
        editing = false;
        onChange(0);
        if (bar) { bar.hidden = true; }
        closeAddMenu();
        syncButton();
    };

    var pendingPositions = function () {
        var ex = explorer();
        return editing && ex ? JSON.stringify(ex.getEdits()) : '';
    };

    var submitScheme = function (op, category) {
        if (!form) { return; }
        dirty = 0; // pleaca acum: fara avertismentul de iesire
        form.elements.op.value = op;
        form.elements.positions.value = pendingPositions() || '{}';
        form.elements.category.value = category || '';
        form.elements.scheme_edit.value = op === 'positions_save' ? '' : '1';
        form.submit();
    };

    if (head) {
        editBtn = document.createElement(mode === 'structure' ? 'button' : 'a');
        editBtn.className = 'repair-auto-xray-toggle as-scheme-edit-btn';
        editBtn.innerHTML = '<i class="bi bi-arrows-move" aria-hidden="true"></i><span>Editează schema</span>';
        if (mode === 'structure') {
            editBtn.type = 'button';
            editBtn.setAttribute('aria-pressed', 'false');
            editBtn.title = 'Mută punctele de pe schemă și adaugă sisteme';
            editBtn.addEventListener('click', function () {
                if (!editing) { startEditing(); return; }
                if (dirty > 0 && !window.confirm('Renunți la pozițiile nesalvate?')) { return; }
                stopEditing(true);
            });
        } else if (root.dataset.editUrl) {
            editBtn.href = root.dataset.editUrl;
            editBtn.title = 'Pozițiile se editează pe configurația generală „' + (root.dataset.editLabel || '') + '” și se aplică tuturor vehiculelor ei';
        } else {
            editBtn = null;
        }
        if (editBtn) { head.insertBefore(editBtn, head.querySelector('[data-auto-xray-toggle]')); }
    }

    var addMenu = bar ? bar.querySelector('[data-scheme-add-menu]') : null;
    var addToggle = bar ? bar.querySelector('[data-scheme-add-toggle]') : null;
    var closeAddMenu = function () {
        if (addMenu) { addMenu.hidden = true; }
        if (addToggle) { addToggle.setAttribute('aria-expanded', 'false'); }
    };

    if (bar) {
        bar.addEventListener('click', function (event) {
            if (event.target.closest('[data-scheme-add-toggle]')) {
                var open = addMenu.hidden;
                addMenu.hidden = !open;
                addToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                return;
            }
            var add = event.target.closest('[data-scheme-system-add]');
            if (add) { submitScheme('system_add', add.dataset.schemeSystemAdd); return; }
            if (event.target.closest('[data-scheme-reset-all]')) {
                var ex = explorer();
                if (ex) { ex.resetAllMarkers(); }
                return;
            }
            if (event.target.closest('[data-scheme-cancel]')) {
                if (dirty > 0 && !window.confirm('Renunți la pozițiile nesalvate?')) { return; }
                stopEditing(true);
                return;
            }
            if (event.target.closest('[data-scheme-save]')) {
                if (dirty === 0) { stopEditing(false); return; }
                submitScheme('positions_save', '');
            }
        });
        document.addEventListener('click', function (event) {
            if (addMenu && !addMenu.hidden && !event.target.closest('.as-scheme-add')) { closeAddMenu(); }
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (editing && dirty > 0) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* ---------------------------------------------------------------- dialogul sistemului propriu */

    var preview = dialog ? dialog.querySelector('[data-scheme-preview]') : null;
    var pickedEl = dialog ? dialog.querySelector('[data-scheme-picked]') : null;
    var deleteBtn = dialog ? dialog.querySelector('[data-scheme-custom-delete]') : null;

    var updatePreview = function () {
        var icon = customForm.querySelector('input[name="custom_icon"]:checked');
        var color = customForm.elements.custom_color.value;
        preview.style.setProperty('--as-accent', color);
        preview.innerHTML = '<i class="bi ' + (icon ? icon.value : 'bi-gear') + '" aria-hidden="true"></i>';
        dialog.querySelectorAll('[data-scheme-swatch]').forEach(function (swatch) {
            swatch.classList.toggle('is-selected', swatch.dataset.schemeSwatch.toLowerCase() === color.toLowerCase());
        });
        var picked = customForm.querySelectorAll('input[name="custom_components[]"]:checked').length;
        pickedEl.textContent = picked ? '(' + picked + ' alese)' : '';
    };

    var filterComponents = function (text) {
        var tokens = text.toLowerCase().split(/\s+/).filter(Boolean);
        dialog.querySelectorAll('[data-scheme-group]').forEach(function (group) {
            var any = false;
            group.querySelectorAll('[data-scheme-component]').forEach(function (row) {
                var checked = row.querySelector('input').checked;
                var show = checked || tokens.every(function (token) { return row.dataset.name.indexOf(token) !== -1; });
                row.hidden = !show;
                any = any || show;
            });
            group.hidden = !any;
        });
    };

    var openDialog = function (key) {
        if (!dialog || !customForm) { return; }
        var data = key ? customs[key] : null;
        if (key && !data) { return; }
        customForm.reset();
        customForm.elements.op.value = 'custom_system_save';
        customForm.elements.custom_key.value = key || '';
        customForm.elements.custom_name.value = data ? data.label : '';
        customForm.elements.custom_color.value = data ? data.color : '#6366f1';
        var icon = customForm.querySelector('input[name="custom_icon"][value="' + (data ? data.icon : 'bi-gear') + '"]');
        if (icon) { icon.checked = true; }
        if (data) {
            customForm.querySelectorAll('input[name="custom_types[]"]').forEach(function (input) { input.checked = data.types.indexOf(input.value) !== -1; });
            var scope = customForm.querySelector('input[name="custom_scope"][value="' + data.scope + '"]');
            if (scope) { scope.checked = true; }
            customForm.querySelectorAll('input[name="custom_components[]"]').forEach(function (input) { input.checked = data.components.indexOf(input.value) !== -1; });
        }
        dialog.querySelector('[data-scheme-dialog-title]').textContent = data ? 'Editează sistemul „' + data.label + '”' : 'Sistem nou pe schemă';
        dialog.querySelector('[data-scheme-dialog-submit]').textContent = data ? 'Salvează' : 'Creează și pune pe schemă';
        deleteBtn.hidden = !data;
        deleteBtn.classList.remove('is-armed');
        deleteBtn.lastChild.textContent = ' Șterge sistemul';
        dialog.querySelector('[data-scheme-component-search]').value = '';
        filterComponents('');
        updatePreview();
        closeAddMenu();
        if (typeof dialog.showModal === 'function') { dialog.showModal(); } else { dialog.setAttribute('open', ''); }
        customForm.elements.custom_name.focus();
    };

    var closeDialog = function () {
        if (typeof dialog.close === 'function') { dialog.close(); } else { dialog.removeAttribute('open'); }
    };

    if (dialog && customForm) {
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog || event.target.closest('[data-scheme-dialog-close]')) { closeDialog(); return; }
            var swatch = event.target.closest('[data-scheme-swatch]');
            if (swatch) {
                customForm.elements.custom_color.value = swatch.dataset.schemeSwatch;
                updatePreview();
                return;
            }
            if (event.target.closest('[data-scheme-custom-delete]')) {
                if (!deleteBtn.classList.contains('is-armed')) {
                    deleteBtn.classList.add('is-armed');
                    deleteBtn.lastChild.textContent = ' ' + deleteBtn.dataset.asConfirmText;
                    return;
                }
                dirty = 0;
                customForm.elements.op.value = 'custom_system_delete';
                customForm.submit();
            }
        });
        customForm.addEventListener('change', updatePreview);
        dialog.querySelector('[data-scheme-component-search]').addEventListener('input', function (event) { filterComponents(event.target.value); });
        customForm.addEventListener('submit', function () {
            // Din modul de editare: pozitiile nesalvate pleaca impreuna, iar pagina revine in editare.
            customForm.elements.positions.value = pendingPositions();
            customForm.elements.scheme_edit.value = editing ? '1' : '';
            dirty = 0;
        });
    }

    /* ---------------------------------------------------------------- piesa pusa pe schema */

    var partDialog = root.querySelector('[data-scheme-part-dialog]');
    var partForm = root.querySelector('[data-scheme-part-form]');
    var partDefaults = {};
    try { partDefaults = JSON.parse((document.getElementById('asPartSystems') || {}).textContent || '{}') || {}; } catch (error) { partDefaults = {}; }

    var syncPartForm = function () {
        var mode = partForm.querySelector('input[name="part_mode"]:checked').value;
        partForm.querySelectorAll('[data-part-mode]').forEach(function (block) { block.hidden = block.dataset.partMode !== mode; });
        partForm.elements.part_key.disabled = mode !== 'catalog';
        partForm.elements.part_key.required = mode === 'catalog';
        partForm.elements.part_name.required = mode === 'new';
        partForm.elements.part_catalog_category.required = mode === 'new';
        var option = partForm.elements.part_system.selectedOptions[0];
        var preview = partForm.querySelector('[data-scheme-part-preview]');
        preview.style.setProperty('--as-accent', option && option.dataset.color ? option.dataset.color : '#6366f1');
        preview.innerHTML = '<i class="bi ' + (option && option.dataset.icon ? option.dataset.icon : 'bi-nut') + '" aria-hidden="true"></i>';
    };

    var openPartDialog = function () {
        if (!partDialog) { return; }
        partForm.reset();
        partForm.querySelector('[data-scheme-part-search]').value = '';
        partForm.querySelectorAll('[data-scheme-part-select] option').forEach(function (option) { option.hidden = false; });
        syncPartForm();
        closeAddMenu();
        if (typeof partDialog.showModal === 'function') { partDialog.showModal(); } else { partDialog.setAttribute('open', ''); }
        partForm.querySelector('[data-scheme-part-search]').focus();
    };

    if (partDialog && partForm) {
        partDialog.addEventListener('click', function (event) {
            if (event.target === partDialog || event.target.closest('[data-scheme-dialog-close]')) {
                if (typeof partDialog.close === 'function') { partDialog.close(); } else { partDialog.removeAttribute('open'); }
                return;
            }
            var unpin = event.target.closest('[data-scheme-part-unpin]');
            if (unpin && form) {
                dirty = 0;
                form.elements.op.value = 'part_unpin';
                form.elements.part_key.value = unpin.dataset.schemePartUnpin;
                form.elements.positions.value = pendingPositions() || '{}';
                form.elements.scheme_edit.value = '1';
                form.submit();
            }
        });
        partForm.addEventListener('change', function (event) {
            // Piesa aleasa din catalog: sistemul ei standard e propus (se poate schimba).
            if (event.target.name === 'part_key' && partDefaults[event.target.value]) {
                partForm.elements.part_system.value = partDefaults[event.target.value];
            }
            syncPartForm();
        });
        partForm.querySelector('[data-scheme-part-search]').addEventListener('input', function (event) {
            var tokens = event.target.value.toLowerCase().split(/\s+/).filter(Boolean);
            partForm.querySelectorAll('[data-scheme-part-select] optgroup').forEach(function (group) {
                var any = false;
                group.querySelectorAll('option').forEach(function (option) {
                    var show = tokens.every(function (token) { return option.dataset.name.indexOf(token) !== -1; });
                    option.hidden = !show;
                    any = any || show;
                });
                group.hidden = !any;
            });
        });
        partForm.addEventListener('submit', function () {
            partForm.elements.positions.value = pendingPositions();
            dirty = 0;
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-scheme-part-new]')) { openPartDialog(); return; }
        if (event.target.closest('[data-scheme-custom-new]')) { openDialog(''); return; }
        var edit = event.target.closest('[data-scheme-custom-edit]');
        if (edit) { openDialog(edit.dataset.schemeCustomEdit); }
    });

    /* ---------------------------------------------------------------- revenire dupa salvare */

    if (mode === 'structure' && window.location.hash === '#schema-editare') {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
        var resume = function () {
            startEditing();
            if (section) { section.scrollIntoView({ block: 'start' }); }
        };
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', resume); } else { resume(); }
    }
})();

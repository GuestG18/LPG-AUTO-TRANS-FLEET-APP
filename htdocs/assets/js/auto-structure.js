/*
 * Pagina Auto (views/maintenance/auto_structure.php): selectorul, panourile deschise la cerere,
 * cautarea si actiunile pe sisteme / componente. Stergerile se confirma cu al doilea click.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-as-page]');
    if (!page) { return; }

    var select = page.querySelector('[data-as-select]');
    if (select) {
        select.addEventListener('change', function () {
            var option = select.selectedOptions[0];
            if (option && option.dataset.url) { window.location.href = option.dataset.url; }
        });
    }

    var actionForm = page.querySelector('[data-as-action-form]');
    var setField = function (name, value) {
        var field = actionForm.querySelector('[name="' + name + '"]');
        if (field) { field.value = value || ''; }
    };
    var runAction = function (button) {
        setField('op', button.dataset.asOp);
        setField('category', button.dataset.category);
        setField('component_key', button.dataset.component);
        setField('tip', button.dataset.tip);
        setField('element', button.dataset.element);
        actionForm.submit();
    };

    page.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-as-toggle]');
        if (toggle) {
            var panel = page.querySelector('[data-as-panel="' + toggle.dataset.asToggle + '"]');
            if (!panel) {
                // Formularele de editare vin intr-un <template> (in afara documentului): montat la prima deschidere.
                var template = page.querySelector('template[data-as-panel-template="' + toggle.dataset.asToggle + '"]');
                if (template) {
                    template.replaceWith(template.content);
                    panel = page.querySelector('[data-as-panel="' + toggle.dataset.asToggle + '"]');
                }
            }
            if (panel) {
                panel.hidden = !panel.hidden;
                page.querySelectorAll('[data-as-toggle="' + toggle.dataset.asToggle + '"][aria-expanded]').forEach(function (item) {
                    item.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
                });
                if (!panel.hidden) {
                    var first = panel.querySelector('input:not([type="hidden"]), select');
                    if (first) { first.focus(); }
                }
            }
            return;
        }

        var action = event.target.closest('[data-as-op]');
        if (!action || !actionForm) { return; }
        var message = action.dataset.asConfirm;
        if (message && !action.classList.contains('is-armed')) {
            var title = action.title;
            action.classList.add('is-armed');
            action.title = message;
            window.setTimeout(function () { action.classList.remove('is-armed'); action.title = title; }, 3000);
            return;
        }
        runAction(action);
    });

    page.addEventListener('change', function (event) {
        var all = event.target.closest('[data-as-check-all]');
        if (all) {
            all.closest('form').querySelectorAll('input[name="keys[]"]').forEach(function (box) { box.checked = all.checked; });
            return;
        }
        var monitoring = event.target.closest('[data-as-monitoring]');
        if (monitoring) {
            var unit = monitoring.selectedOptions[0].dataset.unit;
            monitoring.closest('form').querySelectorAll('[data-as-unit]').forEach(function (node) { node.textContent = unit; });
            return;
        }
        var autosubmit = event.target.closest('[data-as-autosubmit]');
        if (autosubmit) { autosubmit.submit(); }
    });

    var search = page.querySelector('[data-as-search]');
    if (search) {
        search.addEventListener('input', function () {
            var query = search.value.trim().toLowerCase();
            var anyVisible = false;
            page.querySelectorAll('[data-as-section]').forEach(function (section) {
                var rows = section.querySelectorAll('[data-as-row]');
                var matches = 0;
                rows.forEach(function (row) {
                    var hit = query === '' || (row.dataset.name || '').indexOf(query) !== -1;
                    row.hidden = !hit;
                    matches += hit ? 1 : 0;
                });
                var visible = query === '' || matches > 0;
                section.hidden = !visible;
                anyVisible = anyVisible || visible;
            });
            // Tabelul pe tipuri de axa: randurile le filtreaza bucla de mai sus doar in sectiuni.
            page.querySelectorAll('[data-as-matrix-group]').forEach(function (group) {
                var shown = 0;
                group.querySelectorAll('[data-as-row]').forEach(function (row) {
                    var hit = query === '' || (row.dataset.name || '').indexOf(query) !== -1;
                    row.hidden = !hit;
                    shown += hit ? 1 : 0;
                });
                group.hidden = shown === 0;
            });
            var empty = page.querySelector('[data-as-no-results]');
            if (empty) { empty.hidden = anyVisible; }
        });
    }

    // Piese pe axă: bifa axei = toate rotile ei; o parte din roti = bifa pe jumatate.
    var matrixTable = page.querySelector('#as-axle-matrix');
    if (matrixTable) {
        var syncCell = function (cell) {
            var all = cell.querySelector('[data-as-axle-all]');
            var wheels = cell.querySelectorAll('[data-as-wheel]');
            var on = cell.querySelectorAll('[data-as-wheel]:checked').length;
            all.checked = on > 0 && on === wheels.length;
            all.indeterminate = on > 0 && on < wheels.length;
        };
        matrixTable.querySelectorAll('[data-as-cell]').forEach(syncCell);
        matrixTable.addEventListener('change', function (event) {
            var cell = event.target.closest('[data-as-cell]');
            if (!cell) { return; }
            if (event.target.matches('[data-as-axle-all]')) {
                var check = event.target.checked;
                cell.querySelectorAll('[data-as-wheel]').forEach(function (box) { box.checked = check; });
            }
            syncCell(cell);
        });
        matrixTable.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-as-wheels-toggle]');
            if (!toggle) { return; }
            var row = toggle.closest('tr');
            row.classList.toggle('is-wheels');
            toggle.setAttribute('aria-pressed', row.classList.contains('is-wheels') ? 'true' : 'false');
        });
        var wheelsAll = page.querySelector('[data-as-wheels-all]');
        if (wheelsAll) {
            wheelsAll.addEventListener('click', function (event) {
                event.preventDefault(); // butonul sta in <summary>: nu inchide panoul
                var show = wheelsAll.getAttribute('aria-pressed') !== 'true';
                wheelsAll.setAttribute('aria-pressed', show ? 'true' : 'false');
                matrixTable.classList.toggle('is-wheels-all', show);
            });
        }
    }

    // „Aplică pe alte vehicule” (Piese pe axă): tinta trebuie sa aiba axele alese.
    var copyForm = page.querySelector('[data-as-axle-copy]');
    var matrixForm = page.querySelector('#as-axle-matrix');
    if (copyForm) {
        var matrixDirty = false;
        if (matrixForm) {
            matrixForm.addEventListener('change', function () { matrixDirty = true; });
        }
        var targets = copyForm.querySelectorAll('[data-as-copy-target]');
        var chosenAxles = function () {
            return Array.prototype.map.call(copyForm.querySelectorAll('[data-as-copy-axle]:checked'), function (box) { return box.value; });
        };
        var refreshTargets = function () {
            var axles = chosenAxles();
            targets.forEach(function (label) {
                var own = (label.dataset.axles || '').split(',');
                var fits = axles.some(function (no) { return own.indexOf(no) !== -1; });
                var box = label.querySelector('input');
                box.disabled = !fits;
                if (!fits) { box.checked = false; }
                label.classList.toggle('is-disabled', !fits);
                label.title = fits ? '' : 'Vehiculul nu are axele alese';
            });
        };
        copyForm.addEventListener('change', function (event) {
            if (event.target.matches('[data-as-copy-axle]')) { refreshTargets(); }
        });
        var copySearch = copyForm.querySelector('[data-as-copy-search]');
        if (copySearch) {
            copySearch.addEventListener('input', function () {
                var query = copySearch.value.trim().toLowerCase();
                targets.forEach(function (label) {
                    label.hidden = query !== '' && (label.dataset.name || '').indexOf(query) === -1;
                });
            });
        }
        var allButton = copyForm.querySelector('[data-as-copy-all]');
        if (allButton) {
            allButton.addEventListener('click', function () {
                var boxes = Array.prototype.filter.call(targets, function (label) { return !label.hidden; })
                    .map(function (label) { return label.querySelector('input'); })
                    .filter(function (box) { return !box.disabled; });
                var check = boxes.some(function (box) { return !box.checked; });
                boxes.forEach(function (box) { box.checked = check; });
            });
        }
        copyForm.addEventListener('submit', function (event) {
            var problem = '';
            if (chosenAxles().length === 0) {
                problem = 'Bifează cel puțin o axă de copiat.';
            } else if (!copyForm.querySelector('[name="copy_targets[]"]:checked')) {
                problem = 'Alege cel puțin un vehicul.';
            } else if (matrixDirty) {
                problem = 'Ai modificări nesalvate în tabelul de piese. Salvează-le întâi, apoi aplică pe alte vehicule.';
            }
            if (problem !== '') {
                event.preventDefault();
                event.stopPropagation();
                window.alert(problem);
            }
        });
        refreshTargets();
    }
})();

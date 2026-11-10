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
})();

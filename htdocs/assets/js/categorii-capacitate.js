/**
 * Categorii capacitate: asignarea vehiculelor pe categorii direct din pagina.
 *
 * Filtrele ascund randuri; bifele + „Aplica pe selectate” schimba dropdown-ul
 * fiecarui rand bifat. La salvare se trimit DOAR randurile modificate.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-capacity-assign]');
    if (!root) {
        return;
    }

    var rows = Array.prototype.slice.call(root.querySelectorAll('[data-assign-row]'));
    var form = root.querySelector('[data-assign-form]');
    var emptyRow = root.querySelector('[data-assign-empty]');
    var selectAll = root.querySelector('[data-assign-select-all]');
    var selectedCount = root.querySelector('[data-assign-selected-count]');
    var bulkCategory = root.querySelector('[data-assign-bulk-category]');
    var bulkApply = root.querySelector('[data-assign-bulk-apply]');
    var clearSelection = root.querySelector('[data-assign-clear-selection]');
    var dirtyLabel = root.querySelector('[data-assign-dirty-label]');
    var resetButton = root.querySelector('[data-assign-reset]');
    var saveButton = root.querySelector('[data-assign-save]');
    var filters = {};
    Array.prototype.forEach.call(root.querySelectorAll('[data-assign-filter]'), function (el) {
        filters[el.getAttribute('data-assign-filter')] = el;
    });

    var submitting = false;

    function filterValue(name) {
        return filters[name] ? String(filters[name].value || '').trim() : '';
    }

    function rowCheck(row) {
        return row.querySelector('[data-assign-check]');
    }

    function rowSelect(row) {
        return row.querySelector('[data-assign-select]');
    }

    function isVisible(row) {
        return !row.classList.contains('d-none');
    }

    function applyFilters() {
        var search = filterValue('search').toLowerCase();
        var type = filterValue('type');
        var category = filterValue('category');
        var garage = filterValue('garage');
        var status = filterValue('status');
        var visible = 0;

        rows.forEach(function (row) {
            var show = (search === '' || row.getAttribute('data-search').indexOf(search) !== -1)
                && (type === '' || row.getAttribute('data-type') === type)
                && (category === '' || row.getAttribute('data-category') === category)
                && (garage === '' || row.getAttribute('data-garage') === garage)
                && (status === '' || row.getAttribute('data-status') === status);

            row.classList.toggle('d-none', !show);
            if (show) {
                visible++;
            } else {
                // Nu aplicam categoria pe vehicule pe care nu le vezi.
                var check = rowCheck(row);
                if (check) {
                    check.checked = false;
                }
            }
        });

        if (emptyRow) {
            emptyRow.classList.toggle('d-none', visible > 0);
        }
        refreshSelection();
    }

    function selectedRows() {
        return rows.filter(function (row) {
            var check = rowCheck(row);
            return check && check.checked && isVisible(row);
        });
    }

    function refreshSelection() {
        if (!selectAll) {
            return;
        }
        var visibleRows = rows.filter(isVisible);
        var selected = selectedRows().length;

        selectedCount.textContent = String(selected);
        selectAll.checked = visibleRows.length > 0 && selected === visibleRows.length;
        selectAll.indeterminate = selected > 0 && selected < visibleRows.length;
        bulkApply.disabled = selected === 0 || !bulkCategory.value;

        rows.forEach(function (row) {
            var check = rowCheck(row);
            row.classList.toggle('table-active', !!(check && check.checked));
        });
    }

    function refreshDirty() {
        if (!saveButton) {
            return;
        }
        var dirty = 0;
        rows.forEach(function (row) {
            var select = rowSelect(row);
            var changed = !!select && select.value !== select.getAttribute('data-original');
            row.classList.toggle('table-warning', changed);
            if (changed) {
                dirty++;
            }
        });

        saveButton.disabled = dirty === 0;
        resetButton.disabled = dirty === 0;
        dirtyLabel.textContent = dirty === 0
            ? 'Nicio modificare nesalvata.'
            : dirty + (dirty === 1 ? ' vehicul modificat, nesalvat.' : ' vehicule modificate, nesalvate.');
        dirtyLabel.classList.toggle('text-muted', dirty === 0);
        dirtyLabel.classList.toggle('fw-semibold', dirty > 0);
        root.setAttribute('data-dirty', String(dirty));
    }

    Object.keys(filters).forEach(function (name) {
        filters[name].addEventListener(name === 'search' ? 'input' : 'change', applyFilters);
    });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            rows.forEach(function (row) {
                var check = rowCheck(row);
                if (check && isVisible(row)) {
                    check.checked = selectAll.checked;
                }
            });
            refreshSelection();
        });

        root.addEventListener('change', function (event) {
            if (event.target.matches('[data-assign-check]')) {
                refreshSelection();
            } else if (event.target.matches('[data-assign-select]')) {
                refreshDirty();
            }
        });

        bulkCategory.addEventListener('change', refreshSelection);

        bulkApply.addEventListener('click', function () {
            var value = bulkCategory.value === 'none' ? '' : bulkCategory.value;
            selectedRows().forEach(function (row) {
                var select = rowSelect(row);
                if (select) {
                    select.value = value;
                }
                rowCheck(row).checked = false;
            });
            refreshSelection();
            refreshDirty();
        });

        clearSelection.addEventListener('click', function () {
            rows.forEach(function (row) {
                var check = rowCheck(row);
                if (check) {
                    check.checked = false;
                }
            });
            refreshSelection();
        });

        resetButton.addEventListener('click', function () {
            rows.forEach(function (row) {
                var select = rowSelect(row);
                if (select) {
                    select.value = select.getAttribute('data-original');
                }
            });
            refreshDirty();
        });

        form.addEventListener('submit', function (event) {
            var changed = 0;
            rows.forEach(function (row) {
                var select = rowSelect(row);
                if (!select) {
                    return;
                }
                // Trimitem doar ce s-a schimbat; restul randurilor raman neatinse.
                if (select.value === select.getAttribute('data-original')) {
                    select.disabled = true;
                } else {
                    changed++;
                }
            });
            if (changed === 0) {
                event.preventDefault();
                rows.forEach(function (row) {
                    var select = rowSelect(row);
                    if (select) {
                        select.disabled = false;
                    }
                });
                return;
            }
            submitting = true;
            saveButton.disabled = true;
        });

        window.addEventListener('beforeunload', function (event) {
            if (!submitting && root.getAttribute('data-dirty') !== '0' && root.getAttribute('data-dirty') !== null) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        // Dupa „Inapoi” browserul poate restaura selectii vechi in dropdown-uri.
        window.addEventListener('pageshow', function () {
            rows.forEach(function (row) {
                var select = rowSelect(row);
                if (select) {
                    select.disabled = false;
                }
            });
            submitting = false;
            refreshDirty();
        });
    }

    applyFilters();
    refreshDirty();
})();

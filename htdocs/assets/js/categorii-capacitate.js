/**
 * Management capacitati (?page=categorii_capacitate).
 *
 * Alocarea vehiculelor: dropdown-ul din fiecare rand al tabelului este SINGURA
 * sursa de adevar pentru modificarile nesalvate. Vederea "Grupare", paginarea si
 * bifele lucreaza peste aceleasi randuri, deci comutarea vederii nu pierde nimic.
 * La salvare se trimit DOAR randurile modificate (endpoint-ul existent action=assign).
 *
 * Bootstrap JS se incarca in footer, dupa acest script: modalele se cauta la click
 * sau la evenimentul `load`, niciodata la initializare.
 */
(function () {
    'use strict';

    var page = document.querySelector('[data-cmc]');
    if (!page) {
        return;
    }

    var slice = Array.prototype.slice;
    function all(selector, scope) {
        return slice.call((scope || document).querySelectorAll(selector));
    }
    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function plural(count, one, many) {
        return count + ' ' + (count === 1 ? one : many);
    }
    function modal(element) {
        return window.bootstrap && element ? window.bootstrap.Modal.getOrCreateInstance(element) : null;
    }
    function downloadCsv(filename, header, lines) {
        var escapeCell = function (cell) {
            var text = String(cell == null ? '' : cell);
            return /[";\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        };
        var content = [header].concat(lines).map(function (line) {
            return line.map(escapeCell).join(';');
        }).join('\r\n');
        var blob = new Blob(['﻿' + content], { type: 'text/csv;charset=utf-8' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename + '-' + new Date().toISOString().slice(0, 10) + '.csv';
        document.body.appendChild(link);
        link.click();
        setTimeout(function () {
            URL.revokeObjectURL(link.href);
            link.remove();
        }, 0);
    }

    // =================================================================
    // Navigatie: dalele de sus duc la sectiuni + scroll-spy
    // =================================================================
    var navTiles = all('[data-cmc-nav]', page);
    var sections = navTiles.map(function (tile) {
        return document.getElementById(tile.getAttribute('data-cmc-nav'));
    }).filter(Boolean);

    function setActiveTile(id) {
        navTiles.forEach(function (tile) {
            var active = tile.getAttribute('data-cmc-nav') === id;
            tile.classList.toggle('is-active', active);
            if (active) {
                tile.setAttribute('aria-current', 'true');
            } else {
                tile.removeAttribute('aria-current');
            }
        });
    }

    function scrollToSection(id) {
        var target = document.getElementById(id);
        if (!target) {
            return;
        }
        setActiveTile(id);
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    all('[data-cmc-nav], [data-cmc-nav-link]', page).forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            scrollToSection(link.getAttribute('data-cmc-nav') || link.getAttribute('data-cmc-nav-link'));
        });
    });

    var spyQueued = false;
    window.addEventListener('scroll', function () {
        if (spyQueued) {
            return;
        }
        spyQueued = true;
        window.requestAnimationFrame(function () {
            spyQueued = false;
            // Pana la prima sectiune ramane activa "Alocare vehicule" (ca in macheta).
            var current = 'asignare-vehicule';
            var best = -Infinity;
            var scroller = document.scrollingElement || document.documentElement;
            var atBottom = scroller.scrollTop + window.innerHeight >= scroller.scrollHeight - 4;
            sections.forEach(function (section) {
                var rect = section.getBoundingClientRect();
                // La capatul paginii ultima sectiune nu mai poate urca pana sus: conteaza daca e vizibila.
                if ((rect.top <= 140 || (atBottom && rect.top < window.innerHeight * 0.6)) && rect.top > best) {
                    best = rect.top;
                    current = section.id;
                }
            });
            setActiveTile(current);
        });
    }, { passive: true });

    // =================================================================
    // Categorii: "Vezi toate", modala de adaugare / editare, modala de stergere
    // =================================================================
    var categoriesToggle = page.querySelector('[data-cmc-categories-toggle]');
    if (categoriesToggle) {
        var toggleLabel = categoriesToggle.querySelector('[data-cmc-categories-toggle-label]');
        var toggleOriginal = toggleLabel.textContent;
        categoriesToggle.addEventListener('click', function () {
            var expand = categoriesToggle.getAttribute('aria-expanded') !== 'true';
            all('.cmc-row-extra', page).forEach(function (row) {
                row.hidden = !expand;
            });
            categoriesToggle.setAttribute('aria-expanded', expand ? 'true' : 'false');
            toggleLabel.textContent = expand ? 'Arată mai puține' : toggleOriginal;
        });
    }

    var categoryModalEl = document.querySelector('[data-cmc-category-modal]');
    if (categoryModalEl) {
        var categoryForm = categoryModalEl.querySelector('[data-cmc-category-form]');
        var categoryId = categoryModalEl.querySelector('[data-cmc-category-id]');
        var categoryTitle = categoryModalEl.querySelector('[data-cmc-category-title]');
        var categorySubmit = categoryModalEl.querySelector('[data-cmc-category-submit]');
        var nameInput = categoryForm.querySelector('#cap_cat_nume');
        var descriptionInput = categoryForm.querySelector('#cap_cat_descriere');
        var orderInput = categoryForm.querySelector('#cap_cat_ordine');
        var activeInput = categoryForm.querySelector('#cap_cat_activ');

        var openCategoryModal = function (data) {
            var editing = !!data;
            all('.is-invalid', categoryForm).forEach(function (el) {
                el.classList.remove('is-invalid');
            });
            categoryForm.action = categoryModalEl.getAttribute(editing ? 'data-update-url' : 'data-store-url');
            categoryId.disabled = !editing;
            categoryId.value = editing ? data.id : '';
            nameInput.value = editing ? data.name : '';
            descriptionInput.value = editing ? data.description : '';
            orderInput.value = editing ? data.order : '0';
            activeInput.checked = editing ? data.active === '1' : true;
            categoryTitle.textContent = editing ? 'Editează categoria „' + data.name + '”' : 'Adaugă categorie';
            categorySubmit.textContent = editing ? 'Salvează modificările' : 'Adaugă categoria';
            categorySubmit.disabled = false;
            delete categoryForm.dataset.submitting;
            var instance = modal(categoryModalEl);
            if (instance) {
                instance.show();
            }
        };

        all('[data-cmc-add-category]', page).forEach(function (button) {
            button.addEventListener('click', function () {
                openCategoryModal(null);
            });
        });
        all('[data-cmc-edit-category]', page).forEach(function (button) {
            button.addEventListener('click', function () {
                openCategoryModal({
                    id: button.getAttribute('data-id'),
                    name: button.getAttribute('data-name'),
                    description: button.getAttribute('data-description'),
                    order: button.getAttribute('data-order'),
                    active: button.getAttribute('data-active')
                });
            });
        });

        nameInput.addEventListener('input', function () {
            nameInput.classList.remove('is-invalid');
        });

        categoryModalEl.addEventListener('shown.bs.modal', function () {
            nameInput.focus();
        });

        categoryForm.addEventListener('submit', function (event) {
            if (nameInput.value.trim() === '') {
                // stopPropagation: garda globala anti-dublu-submit din app.js ar bloca altfel butonul.
                event.preventDefault();
                event.stopPropagation();
                nameInput.classList.add('is-invalid');
                nameInput.focus();
                return;
            }
            categorySubmit.disabled = true;
        });

        // Dupa o eroare de validare (sau un link vechi ?edit=) serverul randeaza
        // formularul completat: il redeschidem cand Bootstrap e disponibil.
        if (categoryModalEl.getAttribute('data-reopen') === '1') {
            window.addEventListener('load', function () {
                var instance = modal(categoryModalEl);
                if (instance) {
                    instance.show();
                }
            });
        }
    }

    var deleteModalEl = document.querySelector('[data-cmc-delete-modal]');
    if (deleteModalEl) {
        var deleteForm = deleteModalEl.querySelector('[data-cmc-delete-form]');
        var deleteId = deleteModalEl.querySelector('[data-cmc-delete-id]');
        var deleteName = deleteModalEl.querySelector('[data-cmc-delete-name]');
        var deleteReassign = deleteModalEl.querySelector('[data-cmc-delete-reassign]');
        var deleteCount = deleteModalEl.querySelector('[data-cmc-delete-count]');
        var deleteTarget = deleteModalEl.querySelector('[data-cmc-delete-target]');

        all('[data-cmc-delete-category]', page).forEach(function (button) {
            button.addEventListener('click', function () {
                var id = button.getAttribute('data-id');
                var vehicles = parseInt(button.getAttribute('data-vehicles'), 10) || 0;
                deleteId.value = id;
                deleteName.textContent = '„' + button.getAttribute('data-name') + '”';
                deleteTarget.classList.remove('is-invalid');
                deleteTarget.value = '';
                all('option', deleteTarget).forEach(function (option) {
                    var self = option.value === id;
                    option.hidden = self;
                    option.disabled = self;
                });
                deleteReassign.hidden = vehicles === 0;
                deleteTarget.disabled = vehicles === 0;
                deleteCount.textContent = plural(vehicles, 'vehicul asignat', 'vehicule asignate');
                var instance = modal(deleteModalEl);
                if (instance) {
                    instance.show();
                }
            });
        });

        deleteForm.addEventListener('submit', function (event) {
            // Aceeasi regula ca pe server: cu vehicule asignate trebuie aleasa o destinatie.
            if (!deleteTarget.disabled && deleteTarget.value === '') {
                event.preventDefault();
                event.stopPropagation();
                deleteTarget.classList.add('is-invalid');
                deleteTarget.focus();
            }
        });
    }

    // =================================================================
    // Previzualizarea selectorului (nu trimite nimic)
    // =================================================================
    var preview = page.querySelector('[data-cmc-preview]');
    if (preview) {
        var previewTrigger = preview.querySelector('[data-cmc-preview-trigger]');
        var previewPanel = preview.querySelector('[data-cmc-preview-panel]');
        var previewSummary = preview.querySelector('[data-cmc-preview-summary]');

        var refreshPreview = function () {
            all('[data-cmc-preview-group]', preview).forEach(function (group) {
                var checks = all('[data-cmc-preview-check]', group);
                var checked = checks.filter(function (check) { return check.checked; }).length;
                var groupCheck = group.querySelector('[data-cmc-preview-group-check]');
                groupCheck.checked = checks.length > 0 && checked === checks.length;
                groupCheck.indeterminate = checked > 0 && checked < checks.length;
            });
            var total = all('[data-cmc-preview-check]:checked', preview).length;
            previewSummary.textContent = total === 0
                ? 'Selectare vehicule după categorie'
                : plural(total, 'vehicul selectat', 'vehicule selectate');
        };

        previewTrigger.addEventListener('click', function () {
            var open = previewTrigger.getAttribute('aria-expanded') !== 'true';
            previewTrigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            previewPanel.hidden = !open;
        });

        preview.addEventListener('click', function (event) {
            var head = event.target.closest('[data-cmc-preview-head]');
            if (head && !event.target.matches('input')) {
                head.parentElement.classList.toggle('is-collapsed');
            }
        });

        preview.addEventListener('change', function (event) {
            if (event.target.matches('[data-cmc-preview-group-check]')) {
                var group = event.target.closest('[data-cmc-preview-group]');
                all('[data-cmc-preview-check]', group).forEach(function (check) {
                    check.checked = event.target.checked;
                });
                if (event.target.checked) {
                    group.classList.remove('is-collapsed');
                }
            }
            refreshPreview();
        });
    }

    // =================================================================
    // Raportul de verificare: filtre + export
    // =================================================================
    var verify = page.querySelector('[data-cmc-verify]');
    if (verify) {
        var verifyRows = all('[data-verify-row]', verify);
        var verifyEmpty = verify.querySelector('[data-verify-empty]');
        var verifyCount = verify.querySelector('[data-verify-count]');
        var contradictedButton = verify.querySelector('[data-verify-contradicted]');
        var verifyFilters = {};
        all('[data-verify-filter]', verify).forEach(function (el) {
            verifyFilters[el.getAttribute('data-verify-filter')] = el;
        });
        var onlyContradicted = false;

        var verifyValue = function (name) {
            return verifyFilters[name] ? String(verifyFilters[name].value || '').trim() : '';
        };

        var applyVerifyFilters = function () {
            var search = verifyValue('search').toLowerCase();
            var category = verifyValue('category');
            var type = verifyValue('type');
            var garage = verifyValue('garage');
            var visible = 0;
            verifyRows.forEach(function (row) {
                var show = (search === '' || row.getAttribute('data-search').indexOf(search) !== -1)
                    && (category === '' || row.getAttribute('data-category') === category)
                    && (type === '' || row.getAttribute('data-type') === type)
                    && (garage === '' || row.getAttribute('data-garage') === garage)
                    && (!onlyContradicted || row.getAttribute('data-priority') === 'contrazisa');
                row.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (verifyEmpty) {
                verifyEmpty.hidden = visible > 0 || verifyRows.length === 0;
            }
            if (verifyCount) {
                verifyCount.textContent = String(visible);
            }
            // Nu confirmam vehicule ascunse de filtre: le debifam.
            verifyRows.forEach(function (row) {
                var check = row.querySelector('[data-verify-check]');
                if (check && row.hidden) {
                    check.checked = false;
                }
            });
            refreshVerifySelection();
        };

        // Selectia pentru confirmarea in masa (doar cu dreptul manage).
        var verifySelectAll = verify.querySelector('[data-verify-select-all]');
        var verifySelectedCount = verify.querySelector('[data-verify-selected-count]');
        var verifySelectedHint = verify.querySelector('[data-verify-selected-hint]');
        var verifyConfirm = verify.querySelector('[data-verify-confirm]');
        var verifyClear = verify.querySelector('[data-verify-clear-selection]');

        var visibleVerifyChecks = function () {
            return verifyRows.filter(function (row) { return !row.hidden; })
                .map(function (row) { return row.querySelector('[data-verify-check]'); })
                .filter(Boolean);
        };

        var refreshVerifySelection = function () {
            var checks = visibleVerifyChecks();
            var selected = checks.filter(function (check) { return check.checked; });
            var contradicted = selected.filter(function (check) {
                return check.closest('[data-verify-row]').getAttribute('data-priority') === 'contrazisa';
            }).length;
            if (verifySelectedCount) {
                verifySelectedCount.textContent = String(selected.length);
            }
            if (verifySelectedHint) {
                verifySelectedHint.textContent = contradicted > 0
                    ? '(dintre care ' + contradicted + ' contrazise de curse)'
                    : '';
                verifySelectedHint.classList.toggle('cmc-text-danger', contradicted > 0);
            }
            if (verifyConfirm) {
                verifyConfirm.disabled = selected.length === 0;
            }
            if (verifySelectAll) {
                verifySelectAll.disabled = checks.length === 0;
                verifySelectAll.checked = checks.length > 0 && selected.length === checks.length;
                verifySelectAll.indeterminate = selected.length > 0 && selected.length < checks.length;
            }
        };

        if (verifySelectAll) {
            verifySelectAll.addEventListener('change', function () {
                var on = verifySelectAll.checked;
                visibleVerifyChecks().forEach(function (check) { check.checked = on; });
                refreshVerifySelection();
            });
        }
        if (verifyClear) {
            verifyClear.addEventListener('click', function () {
                all('[data-verify-check]', verify).forEach(function (check) { check.checked = false; });
                refreshVerifySelection();
            });
        }
        verify.addEventListener('change', function (event) {
            if (event.target.matches('[data-verify-check]')) {
                refreshVerifySelection();
            }
        });
        refreshVerifySelection();

        Object.keys(verifyFilters).forEach(function (name) {
            verifyFilters[name].addEventListener(name === 'search' ? 'input' : 'change', applyVerifyFilters);
        });

        if (contradictedButton) {
            contradictedButton.addEventListener('click', function () {
                onlyContradicted = !onlyContradicted;
                contradictedButton.classList.toggle('is-active', onlyContradicted);
                contradictedButton.setAttribute('aria-pressed', onlyContradicted ? 'true' : 'false');
                applyVerifyFilters();
            });
        }

        var verifyExport = verify.querySelector('[data-cmc-export="verify"]');
        if (verifyExport) {
            verifyExport.addEventListener('click', function () {
                var lines = verifyRows.filter(function (row) { return !row.hidden; }).map(function (row) {
                    return JSON.parse(row.getAttribute('data-export'));
                });
                downloadCsv('capacitati-de-verificat', [
                    'Nr. inmatriculare', 'Marca / model', 'Tip', 'Categorie',
                    'Capacitate stocata', 'Max. din curse', 'Curse', 'Prioritate'
                ], lines);
            });
        }
    }

    // =================================================================
    // Alocarea vehiculelor pe categorii
    // =================================================================
    var root = page.querySelector('[data-capacity-assign]');
    if (!root) {
        return;
    }

    var categories = [];
    try {
        categories = JSON.parse(root.querySelector('[data-cmc-categories-json]').textContent || '[]');
    } catch (error) {
        categories = [];
    }
    var categoryById = {};
    categories.forEach(function (category) {
        categoryById[String(category.id)] = category;
    });

    var form = root.querySelector('[data-assign-form]');
    var emptyRow = root.querySelector('[data-assign-empty]');
    var tableView = root.querySelector('[data-cmc-table-view]');
    var groupView = root.querySelector('[data-cmc-group-view]');
    var selectAll = root.querySelector('[data-assign-select-all]');
    var selectedCount = root.querySelector('[data-assign-selected-count]');
    var selectedHint = root.querySelector('[data-assign-selected-hint]');
    var bulkCategory = root.querySelector('[data-assign-bulk-category]');
    var bulkApply = root.querySelector('[data-assign-bulk-apply]');
    var clearSelection = root.querySelector('[data-assign-clear-selection]');
    var feedback = root.querySelector('[data-assign-feedback]');
    var saveBar = root.querySelector('[data-cmc-savebar]');
    var dirtyLabel = root.querySelector('[data-assign-dirty-label]');
    var resetButton = root.querySelector('[data-assign-reset]');
    var saveButton = root.querySelector('[data-assign-save]');
    var pagination = root.querySelector('[data-cmc-pagination]');
    var pageRange = root.querySelector('[data-cmc-page-range]');
    var pageSizeSelect = root.querySelector('[data-cmc-page-size]');
    var pager = root.querySelector('[data-cmc-pager]');
    var filters = {};
    all('[data-assign-filter]', root).forEach(function (el) {
        filters[el.getAttribute('data-assign-filter')] = el;
    });

    var rows = all('[data-assign-row]', root).map(function (el) {
        return {
            el: el,
            id: el.getAttribute('data-id'),
            check: el.querySelector('[data-assign-check]'),
            select: el.querySelector('[data-assign-select]'),
            plate: el.getAttribute('data-plate'),
            brand: el.getAttribute('data-brand'),
            typeLabel: el.getAttribute('data-type-label'),
            capacity: el.getAttribute('data-capacity'),
            search: el.getAttribute('data-search'),
            type: el.getAttribute('data-type'),
            garage: el.getAttribute('data-garage'),
            status: el.getAttribute('data-status'),
            category: el.getAttribute('data-category'),
            match: true
        };
    });
    var rowById = {};
    rows.forEach(function (row) {
        rowById[row.id] = row;
    });

    var state = { page: 1, pageSize: 25, view: 'table' };
    var collapsedGroups = {};
    var submitting = false;

    function filterValue(name) {
        return filters[name] ? String(filters[name].value || '').trim() : '';
    }
    function isDirty(row) {
        return !!row.select && row.select.value !== row.select.getAttribute('data-original');
    }
    function pendingCategoryKey(row) {
        if (row.select) {
            return row.select.value === '' ? 'none' : row.select.value;
        }
        return row.category;
    }
    function categoryLabel(key) {
        if (key === 'none' || key === '') {
            return 'Fără categorie';
        }
        return categoryById[key] ? categoryById[key].name : 'Categorie #' + key;
    }
    function matchingRows() {
        return rows.filter(function (row) { return row.match; });
    }
    function selectedRows() {
        return rows.filter(function (row) { return row.match && row.check && row.check.checked; });
    }

    function applyFilters() {
        var search = filterValue('search').toLowerCase();
        var type = filterValue('type');
        var category = filterValue('category');
        var garage = filterValue('garage');
        var status = filterValue('status');

        rows.forEach(function (row) {
            row.match = (search === '' || row.search.indexOf(search) !== -1)
                && (type === '' || row.type === type)
                && (category === '' || row.category === category)
                && (garage === '' || row.garage === garage)
                && (status === '' || row.status === status);
            // Nu aplicam categoria pe vehicule pe care operatorul nu le mai vede.
            if (!row.match && row.check) {
                row.check.checked = false;
            }
        });
        state.page = 1;
        render();
    }

    function pageCount(total) {
        return state.pageSize > 0 ? Math.max(1, Math.ceil(total / state.pageSize)) : 1;
    }

    function render() {
        var matching = matchingRows();
        var total = matching.length;
        var pages = pageCount(total);
        state.page = Math.min(Math.max(1, state.page), pages);
        var start = state.pageSize > 0 ? (state.page - 1) * state.pageSize : 0;
        var end = state.pageSize > 0 ? Math.min(total, start + state.pageSize) : total;
        var visibleIds = {};
        matching.slice(start, end).forEach(function (row) {
            visibleIds[row.id] = true;
        });

        rows.forEach(function (row) {
            row.el.hidden = !visibleIds[row.id];
        });
        if (emptyRow) {
            emptyRow.hidden = total > 0 || rows.length === 0;
        }

        var grouped = state.view === 'group';
        tableView.hidden = grouped;
        groupView.hidden = !grouped;

        if (grouped) {
            pageRange.textContent = plural(total, 'vehicul afișat', 'vehicule afișate') + ' pe categorii';
            pageSizeSelect.hidden = true;
            pager.innerHTML = '';
            renderGroups(matching);
        } else {
            pageSizeSelect.hidden = false;
            pageRange.textContent = total === 0
                ? '0 vehicule'
                : 'Afișez ' + (start + 1) + ' - ' + end + ' din ' + plural(total, 'vehicul', 'vehicule');
            renderPager(pages);
        }
        pagination.hidden = rows.length === 0;

        refreshSelection();
        refreshDirty();
    }

    function renderPager(pages) {
        var current = state.page;
        var html = [];
        var button = function (label, target, opts) {
            opts = opts || {};
            html.push('<button type="button" data-cmc-goto="' + target + '"'
                + (opts.current ? ' class="is-current" aria-current="page"' : '')
                + (opts.disabled ? ' disabled' : '')
                + (opts.title ? ' aria-label="' + opts.title + '" title="' + opts.title + '"' : '')
                + '>' + label + '</button>');
        };
        button('&laquo;', 1, { disabled: current === 1, title: 'Prima pagină' });
        button('&lsaquo;', current - 1, { disabled: current === 1, title: 'Pagina anterioară' });

        var numbers = [];
        for (var i = 1; i <= pages; i++) {
            if (i === 1 || i === pages || Math.abs(i - current) <= 1 || pages <= 7) {
                numbers.push(i);
            }
        }
        var previous = 0;
        numbers.forEach(function (number) {
            if (number - previous > 1) {
                html.push('<span class="cmc-pager-gap">&hellip;</span>');
            }
            button(String(number), number, { current: number === current });
            previous = number;
        });

        button('&rsaquo;', current + 1, { disabled: current === pages, title: 'Pagina următoare' });
        button('&raquo;', pages, { disabled: current === pages, title: 'Ultima pagină' });
        pager.innerHTML = html.join('');
    }

    function renderGroups(matching) {
        var groups = {};
        matching.forEach(function (row) {
            var key = pendingCategoryKey(row);
            if (!groups[key]) {
                groups[key] = [];
            }
            groups[key].push(row);
        });

        var keys = Object.keys(groups).sort(function (a, b) {
            var orderA = a === 'none' ? Infinity : (categoryById[a] ? categoryById[a].order : 0);
            var orderB = b === 'none' ? Infinity : (categoryById[b] ? categoryById[b].order : 0);
            if (orderA !== orderB) {
                return orderA < orderB ? -1 : 1;
            }
            return categoryLabel(a).localeCompare(categoryLabel(b), 'ro');
        });

        if (keys.length === 0) {
            groupView.innerHTML = '<p class="cmc-muted text-center py-3 mb-0">Niciun vehicul nu corespunde filtrelor.</p>';
            return;
        }

        var canManage = !!selectAll;
        groupView.innerHTML = keys.map(function (key) {
            var items = groups[key];
            var dirty = items.filter(isDirty).length;
            var head = '<div class="cmc-group-head" data-cmc-group-head="' + escapeHtml(key) + '">'
                + (canManage ? '<input class="form-check-input m-0" type="checkbox" data-cmc-group-check="' + escapeHtml(key) + '" aria-label="Selectează grupa ' + escapeHtml(categoryLabel(key)) + '">' : '')
                + '<span class="cmc-strong">' + escapeHtml(categoryLabel(key)) + '</span>'
                + '<span class="cmc-muted">(' + plural(items.length, 'vehicul', 'vehicule') + ')</span>'
                + (dirty > 0 ? '<span class="cmc-pill cmc-pill-warning">' + plural(dirty, 'modificare nesalvată', 'modificări nesalvate') + '</span>' : '')
                + '<i class="bi bi-chevron-down cmc-group-chevron" aria-hidden="true"></i>'
                + '</div>';
            var body = items.map(function (row) {
                var selected = row.check && row.check.checked;
                return '<div class="cmc-group-item' + (selected ? ' is-selected' : '') + (isDirty(row) ? ' is-dirty' : '') + '">'
                    + (canManage ? '<input class="form-check-input m-0" type="checkbox" data-cmc-group-item-check="' + row.id + '"' + (selected ? ' checked' : '') + ' aria-label="Selectează ' + escapeHtml(row.plate) + '">' : '')
                    + '<div class="cmc-group-item-main"><span class="cmc-plate">' + escapeHtml(row.plate) + '</span>'
                    + '<span class="cmc-sub">' + escapeHtml(row.brand || '-') + ' · ' + escapeHtml(row.typeLabel) + (row.status !== 'activ' ? ' · inactiv' : '') + '</span></div>'
                    + '<span class="cmc-group-item-cap" title="Capacitate reală">' + escapeHtml(row.capacity) + '</span>'
                    + (row.select ? '<span data-cmc-group-select-slot="' + row.id + '"></span>' : '')
                    + '</div>';
            }).join('');
            return '<div class="cmc-group' + (collapsedGroups[key] ? ' is-collapsed' : '') + '" data-cmc-group="' + escapeHtml(key) + '">'
                + head + '<div class="cmc-group-body">' + body + '</div></div>';
        }).join('');

        // Dropdown-urile din grupare sunt copii fara `name`: scriu inapoi in randul din tabel.
        all('[data-cmc-group-select-slot]', groupView).forEach(function (slot) {
            var row = rowById[slot.getAttribute('data-cmc-group-select-slot')];
            var copy = row.select.cloneNode(true);
            copy.removeAttribute('name');
            copy.removeAttribute('data-assign-select');
            copy.setAttribute('data-cmc-group-select', row.id);
            copy.value = row.select.value;
            copy.classList.toggle('is-dirty', isDirty(row));
            slot.replaceWith(copy);
        });

        keys.forEach(function (key) {
            var check = groupView.querySelector('[data-cmc-group-check="' + key + '"]');
            if (!check) {
                return;
            }
            var checks = groups[key].filter(function (row) { return row.check; });
            var checked = checks.filter(function (row) { return row.check.checked; }).length;
            check.checked = checks.length > 0 && checked === checks.length;
            check.indeterminate = checked > 0 && checked < checks.length;
        });
    }

    function refreshSelection() {
        if (!selectAll) {
            return;
        }
        var selected = selectedRows();
        var onPage = rows.filter(function (row) { return !row.el.hidden && row.check; });
        var onPageSelected = onPage.filter(function (row) { return row.check.checked; }).length;
        var elsewhere = selected.length - selected.filter(function (row) { return !row.el.hidden; }).length;

        selectedCount.textContent = String(selected.length);
        selectedHint.textContent = state.view === 'table' && elsewhere > 0
            ? '(' + elsewhere + ' pe alte pagini)'
            : '';
        selectAll.checked = onPage.length > 0 && onPageSelected === onPage.length;
        selectAll.indeterminate = onPageSelected > 0 && onPageSelected < onPage.length;
        bulkApply.disabled = selected.length === 0 || !bulkCategory.value;
        bulkApply.title = selected.length === 0
            ? 'Bifează cel puțin un vehicul'
            : (!bulkCategory.value ? 'Alege categoria de aplicat' : '');

        rows.forEach(function (row) {
            row.el.classList.toggle('is-selected', !!(row.check && row.check.checked));
        });
    }

    function refreshDirty() {
        if (!saveButton) {
            return;
        }
        var dirty = 0;
        rows.forEach(function (row) {
            var changed = isDirty(row);
            row.el.classList.toggle('is-dirty', changed);
            if (row.select) {
                row.select.classList.toggle('is-dirty', changed);
                row.select.title = changed
                    ? 'Nesalvat. Înainte: ' + categoryLabel(row.select.getAttribute('data-original') || 'none')
                    : '';
            }
            if (changed) {
                dirty++;
            }
        });

        saveButton.disabled = dirty === 0 || submitting;
        resetButton.disabled = dirty === 0 || submitting;
        saveBar.hidden = dirty === 0;
        dirtyLabel.textContent = dirty === 0
            ? 'Nicio modificare nesalvată.'
            : plural(dirty, 'vehicul modificat, nesalvat.', 'vehicule modificate, nesalvate.');
        root.setAttribute('data-dirty', String(dirty));
    }

    function setFeedback(message, isError) {
        if (!feedback) {
            return;
        }
        feedback.textContent = message;
        feedback.classList.toggle('is-error', !!isError);
    }

    // --- filtre, paginare, vedere ---
    Object.keys(filters).forEach(function (name) {
        filters[name].addEventListener(name === 'search' ? 'input' : 'change', applyFilters);
    });

    pageSizeSelect.addEventListener('change', function () {
        state.pageSize = parseInt(pageSizeSelect.value, 10) || 0;
        state.page = 1;
        render();
    });

    pager.addEventListener('click', function (event) {
        var button = event.target.closest('[data-cmc-goto]');
        if (!button || button.disabled) {
            return;
        }
        state.page = parseInt(button.getAttribute('data-cmc-goto'), 10) || 1;
        render();
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    all('[data-cmc-view]', root).forEach(function (button) {
        button.addEventListener('click', function () {
            state.view = button.getAttribute('data-cmc-view');
            all('[data-cmc-view]', root).forEach(function (other) {
                var active = other === button;
                other.classList.toggle('is-active', active);
                other.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            render();
        });
    });

    // Blocurile din "Distributie" si dalele din "Total vehicule" numara unitatile
    // ACTIVE: click = acelasi filtru in tabel, pe statusul "Active".
    function filterFromSummary(category, type) {
        if (filters.category) {
            filters.category.value = category;
        }
        if (filters.type) {
            filters.type.value = type;
        }
        if (filters.status) {
            filters.status.value = 'activ';
        }
        applyFilters();
        scrollToSection('asignare-vehicule');
    }
    all('[data-cmc-kpi-filter]', page).forEach(function (button) {
        button.addEventListener('click', function () {
            filterFromSummary(button.getAttribute('data-cmc-kpi-filter'), '');
        });
    });
    all('[data-cmc-type-filter]', page).forEach(function (button) {
        button.addEventListener('click', function () {
            filterFromSummary('', button.getAttribute('data-cmc-type-filter'));
        });
    });

    groupView.addEventListener('click', function (event) {
        var head = event.target.closest('[data-cmc-group-head]');
        if (head && !event.target.matches('input')) {
            var key = head.getAttribute('data-cmc-group-head');
            collapsedGroups[key] = !collapsedGroups[key];
            head.parentElement.classList.toggle('is-collapsed', collapsedGroups[key]);
        }
    });

    groupView.addEventListener('change', function (event) {
        var target = event.target;
        if (target.matches('[data-cmc-group-select]')) {
            rowById[target.getAttribute('data-cmc-group-select')].select.value = target.value;
            setFeedback('', false);
            render();
        } else if (target.matches('[data-cmc-group-item-check]')) {
            rowById[target.getAttribute('data-cmc-group-item-check')].check.checked = target.checked;
            target.closest('.cmc-group-item').classList.toggle('is-selected', target.checked);
            render();
        } else if (target.matches('[data-cmc-group-check]')) {
            var key = target.getAttribute('data-cmc-group-check');
            matchingRows().forEach(function (row) {
                if (row.check && pendingCategoryKey(row) === key) {
                    row.check.checked = target.checked;
                }
            });
            render();
        }
    });

    if (!selectAll) {
        // Fara drept de administrare: doar filtre, paginare si vederi.
        bindExport();
        applyFilters();
        return;
    }

    // --- selectie + aplicare in masa ---
    selectAll.addEventListener('change', function () {
        rows.forEach(function (row) {
            if (row.check && !row.el.hidden) {
                row.check.checked = selectAll.checked;
            }
        });
        refreshSelection();
    });

    root.addEventListener('change', function (event) {
        if (event.target.matches('[data-assign-check]')) {
            refreshSelection();
        } else if (event.target.matches('[data-assign-select]')) {
            setFeedback('', false);
            refreshDirty();
        }
    });

    bulkCategory.addEventListener('change', refreshSelection);

    bulkApply.addEventListener('click', function () {
        var value = bulkCategory.value === 'none' ? '' : bulkCategory.value;
        var targets = selectedRows();
        if (targets.length === 0 || bulkCategory.value === '') {
            return;
        }
        targets.forEach(function (row) {
            if (row.select) {
                row.select.value = value;
            }
            row.check.checked = false;
        });
        setFeedback('„' + categoryLabel(bulkCategory.value) + '” aplicată pe '
            + plural(targets.length, 'vehicul', 'vehicule') + '. Nesalvat — apasă „Salvează modificările”.', false);
        render();
    });

    clearSelection.addEventListener('click', function () {
        rows.forEach(function (row) {
            if (row.check) {
                row.check.checked = false;
            }
        });
        render();
    });

    resetButton.addEventListener('click', function () {
        rows.forEach(function (row) {
            if (row.select) {
                row.select.value = row.select.getAttribute('data-original');
            }
        });
        setFeedback('Modificările nesalvate au fost anulate.', false);
        render();
    });

    form.addEventListener('submit', function (event) {
        var changed = 0;
        rows.forEach(function (row) {
            if (!row.select) {
                return;
            }
            // Trimitem doar ce s-a schimbat (inclusiv de pe alte pagini); restul raman neatinse.
            if (isDirty(row)) {
                changed++;
                // Ansamblu al carui cap tractor are inca o categorie proprie: o aliniem,
                // altfel la "Fara categorie" ar reaparea categoria capului tractor.
                var tractorId = row.select.getAttribute('data-tractor-id');
                if (tractorId) {
                    var mirror = document.createElement('input');
                    mirror.type = 'hidden';
                    mirror.name = 'assignments[' + tractorId + ']';
                    mirror.value = row.select.value;
                    mirror.setAttribute('data-assign-mirror', '');
                    form.appendChild(mirror);
                }
            } else {
                row.select.disabled = true;
            }
        });
        if (changed === 0) {
            event.preventDefault();
            event.stopPropagation();
            rows.forEach(function (row) {
                if (row.select) {
                    row.select.disabled = false;
                }
            });
            return;
        }
        submitting = true;
        saveButton.disabled = true;
        resetButton.disabled = true;
        saveButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se salvează…';
    });

    window.addEventListener('beforeunload', function (event) {
        if (!submitting && root.getAttribute('data-dirty') !== '0' && root.getAttribute('data-dirty') !== null) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    // Dupa „Inapoi” browserul poate restaura selectii vechi in dropdown-uri.
    window.addEventListener('pageshow', function () {
        all('[data-assign-mirror]', form).forEach(function (input) {
            input.remove();
        });
        rows.forEach(function (row) {
            if (row.select) {
                row.select.disabled = false;
            }
        });
        submitting = false;
        saveButton.innerHTML = '<i class="bi bi-save" aria-hidden="true"></i> Salvează modificările';
        render();
    });

    bindExport();
    applyFilters();

    function bindExport() {
        var exportButton = root.querySelector('[data-cmc-export="assign"]');
        if (!exportButton) {
            return;
        }
        exportButton.addEventListener('click', function () {
            var lines = matchingRows().map(function (row) {
                var key = pendingCategoryKey(row);
                return [
                    row.plate, row.brand, row.typeLabel, row.garage, row.capacity,
                    categoryLabel(key), isDirty(row) ? 'da' : 'nu',
                    row.status === 'activ' ? 'Activ' : 'Inactiv'
                ];
            });
            downloadCsv('alocare-vehicule-categorii', [
                'Nr. inmatriculare', 'Marca / model', 'Tip', 'Garaj', 'Capacitate reala',
                'Categorie', 'Modificare nesalvata', 'Status'
            ], lines);
        });
    }
})();

/*
 * Sortare si filtrare din antet pentru orice tabel marcat <table data-column-filter>,
 * la fel ca in Desfasuratorul din Dispecer curse: click pe antet = sortare, palnia =
 * lista de valori cu cautare, in cascada (optiunile unei coloane tin cont de filtrele
 * celorlalte), Shift + click = interval. Un <th data-no-filter> este ignorat.
 * Randul "Total" din <tfoot> (celule cu data-total) se recalculeaza din randurile vizibile.
 *
 * Folosit de Istoric activitati sofer (comparatie) si de Istoric activitate (tabelul Curse).
 *
 * Pagina gazda poate ascunde ea insasi randuri (ex. filtrul pe vehicul din Istoric
 * activitate): pune data-external-hidden="1" pe rand si randul ramane ascuns indiferent
 * de filtrele din antet. Dupa fiecare aplicare, tabelul emite evenimentul
 * "columnfilter:applied", iar table.columnFilterRefresh() reaplica filtrele curente.
 */
(function () {
    var tables = Array.prototype.slice.call(document.querySelectorAll('table[data-column-filter]'));
    if (!tables.length) {
        return;
    }

    var dropdown = document.createElement('div');
    dropdown.className = 'dispatcher-races-filter-dropdown';
    dropdown.hidden = true;
    document.body.appendChild(dropdown);
    var openState = null; // { table: state, column: index }

    // Fiecare celula primeste data-col = pozitia initiala a coloanei, ca filtrele si
    // totalurile sa gaseasca aceeasi coloana si dupa ce panoul „Coloane” o muta.
    tables.forEach(function (table) {
        Array.prototype.forEach.call(table.rows, function (row) {
            if (row.cells.length === 1 && row.cells[0].colSpan > 1) {
                return;
            }
            Array.prototype.forEach.call(row.cells, function (cell, index) {
                cell.setAttribute('data-col', String(index));
            });
        });
    });
    var cellAt = function (row, column) {
        return row.querySelector(':scope > [data-col="' + column + '"]');
    };

    var cellText = function (cell) {
        return cell ? cell.textContent.replace(/\s+/g, ' ').trim() : '';
    };

    // "1.234,56 lei" -> 1234.56; "144h 53m" -> minute; "22.09.2026" -> data; "-" -> null.
    var parseValue = function (cell) {
        if (!cell) {
            return null;
        }
        if (cell.hasAttribute('data-value')) {
            var raw = parseFloat(cell.getAttribute('data-value'));
            return isNaN(raw) ? null : raw;
        }
        var text = cellText(cell);
        var duration = text.match(/^(\d+)h\s*(\d+)m$/);
        if (duration) {
            return parseInt(duration[1], 10) * 60 + parseInt(duration[2], 10);
        }
        var date = text.match(/^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/);
        if (date) {
            return Date.UTC(+date[3], +date[2] - 1, +date[1], +(date[4] || 0), +(date[5] || 0));
        }
        if (!/^-?[\d.]+(,\d+)?(\s|$)/.test(text)) {
            return null;
        }
        var number = parseFloat(text.split(' ')[0].replace(/\./g, '').replace(',', '.'));
        return isNaN(number) ? null : number;
    };

    var formatNumber = function (value, decimals) {
        var fixed = Math.abs(value).toFixed(decimals).split('.');
        var integer = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (value < 0 && Number(fixed.join('.')) !== 0 ? '-' : '') + integer + (decimals > 0 ? ',' + fixed[1] : '');
    };

    var formatTotal = function (value, format) {
        switch (format) {
            case 'int': return String(Math.round(value));
            case 'num0': return formatNumber(value, 0);
            case 'num2': return formatNumber(value, 2);
            case 't': return formatNumber(value, 2) + ' t';
            case 'L': return formatNumber(value, 0) + ' L';
            case 'money': return formatNumber(value, 2) + ' lei';
            case 'duration':
                var minutes = Math.max(0, Math.round(value));
                return Math.floor(minutes / 60) + 'h ' + String(minutes % 60).padStart(2, '0') + 'm';
            default: return formatNumber(value, 2);
        }
    };

    var closeDropdown = function () {
        dropdown.hidden = true;
        openState = null;
    };

    var initTable = function (table) {
        var headRow = table.tHead && table.tHead.rows[0];
        var body = table.tBodies[0];
        if (!headRow || !body) {
            return;
        }
        // Randul "Nu exista..." (o singura celula cu colspan) nu participa.
        var rows = Array.prototype.slice.call(body.rows).filter(function (row) {
            return !(row.cells.length === 1 && row.cells[0].colSpan > 1);
        });
        if (rows.length < 2) {
            return;
        }
        // Randul desfasurat cu cursele unui sofer ramane lipit de randul lui.
        rows.forEach(function (row) {
            var next = row.nextElementSibling;
            if (next && next.hasAttribute('data-trips-detail')) {
                row.detailRow = next;
            }
        });
        var state = { headRow: headRow, body: body, rows: rows, filters: {}, sortColumn: null, sortDir: 1 };
        var footRow = table.tFoot ? table.tFoot.rows[0] : null;
        var labelCell = footRow ? footRow.querySelector('[data-total-label]') : null;
        var originalLabel = labelCell ? labelCell.textContent : '';

        /*
         * Valorile filtrabile ale unei celule. Coloanele de sumar (Vehicul, Beneficiar)
         * arata doar un numar ("3 detalii"), dar filtreaza dupa lista din data-filter-values:
         * randul ramane daca are macar una dintre valorile bifate.
         */
        var cellValues = function (cell) {
            if (cell && cell.hasAttribute('data-filter-values')) {
                var raw = cell.getAttribute('data-filter-values');
                return raw === '' ? [] : raw.split('|');
            }

            return [cellText(cell)];
        };

        var rowMatches = function (row, exceptColumn) {
            return Object.keys(state.filters).every(function (key) {
                var column = parseInt(key, 10);
                if (column === exceptColumn) {
                    return true;
                }
                var allowed = state.filters[key];
                return cellValues(cellAt(row, column)).some(function (value) {
                    return allowed.has(value);
                });
            });
        };

        var updateTotals = function (visibleRows) {
            if (!footRow) {
                return;
            }
            Array.prototype.forEach.call(footRow.cells, function (cell) {
                var column = cell.getAttribute('data-col');
                var mode = cell.getAttribute('data-total');
                if (!mode) {
                    return;
                }
                var text;
                if (mode === 'distinct') {
                    // Vehicule / beneficiari distincti pe randurile vizibile, nu suma pe soferi.
                    var unique = {};
                    visibleRows.forEach(function (row) {
                        cellValues(cellAt(row, column)).forEach(function (value) {
                            if (value !== '') {
                                unique[value] = true;
                            }
                        });
                    });
                    text = String(Object.keys(unique).length);
                } else if (mode === 'ratio') {
                    var num = 0;
                    var den = 0;
                    visibleRows.forEach(function (row) {
                        var source = cellAt(row, column);
                        num += parseFloat(source.getAttribute('data-num')) || 0;
                        den += parseFloat(source.getAttribute('data-den')) || 0;
                    });
                    text = den > 0 ? formatTotal(num / den, cell.getAttribute('data-format')) : '-';
                } else {
                    var sum = 0;
                    visibleRows.forEach(function (row) {
                        sum += parseValue(cellAt(row, column)) || 0;
                    });
                    text = formatTotal(sum, cell.getAttribute('data-format'));
                    if (cell.hasAttribute('data-signed')) {
                        cell.classList.toggle('text-danger', sum < 0);
                        cell.classList.toggle('text-success', sum >= 0);
                    }
                }
                // Totalul de curse pastreaza linkul spre Desfasurator, doar cu cursele vizibile.
                var link = cell.querySelector('a');
                if (link) {
                    link.textContent = text;
                    var ids = [];
                    visibleRows.forEach(function (row) {
                        var list = cellAt(row, column).getAttribute('data-trip-ids');
                        if (list) {
                            ids = ids.concat(list.split(','));
                        }
                    });
                    try {
                        var url = new URL(link.href, window.location.href);
                        url.searchParams.set('ids', ids.join(','));
                        link.href = url.toString();
                    } catch (error) { /* linkul ramane neschimbat */ }
                } else {
                    cell.textContent = text;
                }
            });
            if (labelCell) {
                labelCell.textContent = visibleRows.length === state.rows.length
                    ? originalLabel
                    : originalLabel + ' (' + visibleRows.length + ' din ' + state.rows.length + ')';
            }
        };

        var apply = function () {
            var ordered = state.rows.slice();
            if (state.sortColumn !== null) {
                var column = state.sortColumn;
                ordered.sort(function (a, b) {
                    var av = parseValue(cellAt(a, column));
                    var bv = parseValue(cellAt(b, column));
                    if (av !== null && bv !== null) {
                        return (av - bv) * state.sortDir;
                    }
                    if (av === null && bv !== null) {
                        return 1; // valorile goale ("-") raman la final
                    }
                    if (bv === null && av !== null) {
                        return -1;
                    }
                    return cellText(cellAt(a, column)).localeCompare(cellText(cellAt(b, column)), 'ro') * state.sortDir;
                });
            }
            var visible = [];
            ordered.forEach(function (row) {
                // Randurile ascunse de pagina gazda raman ascunse si nu intra in totaluri.
                var show = rowMatches(row, -1) && row.getAttribute('data-external-hidden') !== '1';
                row.hidden = !show;
                if (show) {
                    visible.push(row);
                }
                state.body.appendChild(row);
                if (row.detailRow) {
                    var detailToggle = row.querySelector('[data-trips-toggle]');
                    state.body.appendChild(row.detailRow);
                    row.detailRow.hidden = !show || !detailToggle || detailToggle.getAttribute('aria-expanded') !== 'true';
                }
            });
            Array.prototype.forEach.call(state.headRow.cells, function (th) {
                var index = parseInt(th.getAttribute('data-col'), 10);
                th.classList.toggle('races-filtered', !!state.filters[index]);
                th.classList.toggle('races-sorted-asc', state.sortColumn === index && state.sortDir === 1);
                th.classList.toggle('races-sorted-desc', state.sortColumn === index && state.sortDir === -1);
                var arrow = th.querySelector('.races-sort-arrow');
                if (arrow) {
                    arrow.textContent = state.sortColumn === index ? (state.sortDir === 1 ? '▲' : '▼') : '↕';
                }
            });
            updateTotals(visible);
            table.dispatchEvent(new CustomEvent('columnfilter:applied', { detail: { visible: visible.length, total: state.rows.length } }));
        };
        table.columnFilterRefresh = apply;

        var setValue = function (column, value, checked) {
            var set = state.filters[column] || new Set();
            if (checked) {
                set.add(value);
            } else {
                set.delete(value);
            }
            if (set.size) {
                state.filters[column] = set;
            } else {
                delete state.filters[column];
            }
        };

        var openDropdown = function (column, anchor) {
            openState = { table: state, column: column };
            var active = state.filters[column] || new Set();
            var seen = {};
            var values = [];
            var numeric = {};
            state.rows.forEach(function (row) {
                var cell = cellAt(row, column);
                var rowValues = cellValues(cell);
                if (!cell.hasAttribute('data-filter-values')) {
                    numeric[rowValues[0]] = parseValue(cell);
                }
                if (!rowMatches(row, column)) {
                    return; // cascada: doar valorile ramase dupa filtrele celorlalte coloane
                }
                rowValues.forEach(function (value) {
                    if (value !== '' && !seen[value]) {
                        seen[value] = true;
                        values.push(value);
                    }
                });
            });
            // Valorile bifate raman in lista (ca sa poata fi debifate), primele.
            active.forEach(function (value) {
                if (!seen[value]) {
                    seen[value] = true;
                    values.push(value);
                }
            });
            values.sort(function (a, b) {
                if (active.has(a) !== active.has(b)) {
                    return active.has(a) ? -1 : 1;
                }
                var av = numeric[a];
                var bv = numeric[b];
                if (av !== null && av !== undefined && bv !== null && bv !== undefined) {
                    return av - bv;
                }
                return a.localeCompare(b, 'ro');
            });

            dropdown.replaceChildren();
            var head = document.createElement('div');
            head.className = 'races-filter-head';
            var title = document.createElement('strong');
            title.textContent = 'Filtru: ' + cellText(cellAt(state.headRow, column).querySelector('.races-head-label') || cellAt(state.headRow, column));
            var reset = document.createElement('a');
            reset.href = '#';
            reset.textContent = 'Toate';
            reset.addEventListener('click', function (event) {
                event.preventDefault();
                delete state.filters[column];
                apply();
                closeDropdown();
            });
            head.appendChild(title);
            head.appendChild(reset);
            dropdown.appendChild(head);

            var search = document.createElement('input');
            search.type = 'search';
            search.className = 'form-control form-control-sm mt-1';
            search.placeholder = 'Cauta valoare...';
            dropdown.appendChild(search);

            var list = document.createElement('div');
            list.className = 'races-filter-list';
            list.title = 'Shift + click pentru selectarea unui interval';
            var anchorOption = null;
            values.forEach(function (value) {
                var option = document.createElement('label');
                option.className = 'races-filter-option';
                option.setAttribute('data-value', value);
                var checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'form-check-input m-0 mt-1';
                checkbox.checked = active.has(value);
                var label = document.createElement('span');
                label.textContent = value;
                option.appendChild(checkbox);
                option.appendChild(label);
                option.addEventListener('click', function (event) {
                    event.preventDefault();
                    var checked = !checkbox.checked;
                    var options = Array.prototype.filter.call(list.children, function (item) {
                        return !item.classList.contains('d-none');
                    });
                    var from = options.indexOf(option);
                    var to = from;
                    if (event.shiftKey && anchorOption && options.indexOf(anchorOption) !== -1) {
                        from = Math.min(from, options.indexOf(anchorOption));
                        to = Math.max(to, options.indexOf(anchorOption));
                    }
                    for (var i = from; i <= to; i++) {
                        options[i].querySelector('input').checked = checked;
                        setValue(column, options[i].getAttribute('data-value'), checked);
                    }
                    anchorOption = option;
                    apply();
                });
                list.appendChild(option);
            });
            dropdown.appendChild(list);

            var resetAllWrap = document.createElement('div');
            resetAllWrap.className = 'races-filter-reset-all-wrap';
            var resetAll = document.createElement('a');
            resetAll.href = '#';
            resetAll.className = 'races-filter-reset-all';
            resetAll.innerHTML = '<i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reseteaza toate filtrele';
            resetAll.addEventListener('click', function (event) {
                event.preventDefault();
                state.filters = {};
                apply();
                closeDropdown();
            });
            resetAllWrap.appendChild(resetAll);
            dropdown.appendChild(resetAllWrap);

            search.addEventListener('input', function () {
                var needle = search.value.toLowerCase();
                Array.prototype.forEach.call(list.children, function (option) {
                    option.classList.toggle('d-none', option.textContent.toLowerCase().indexOf(needle) === -1);
                });
            });

            dropdown.hidden = false;
            var rect = anchor.getBoundingClientRect();
            var width = Math.min(320, Math.max(220, rect.width * 2));
            dropdown.style.width = width + 'px';
            dropdown.style.left = Math.max(8, Math.min(Math.round(rect.left), window.innerWidth - width - 8)) + 'px';
            dropdown.style.top = Math.round(rect.bottom + 4) + 'px';
            // Daca nu are loc sub palnie, se deschide deasupra ei.
            var box = dropdown.getBoundingClientRect();
            if (box.bottom > window.innerHeight - 8) {
                var above = Math.round(rect.top - box.height - 4);
                dropdown.style.top = (above >= 8 ? above : Math.max(8, window.innerHeight - box.height - 8)) + 'px';
            }
            search.focus();
        };

        table.addEventListener('columnhidden', function (event) {
            var column = event.detail.column;
            if (state.filters[column]) {
                delete state.filters[column];
                apply();
            }
            if (state.sortColumn === column) {
                state.sortColumn = null;
                apply();
            }
        });

        table.classList.add('has-column-filter');
        Array.prototype.forEach.call(headRow.cells, function (th, column) {
            if (th.hasAttribute('data-no-filter')) {
                return;
            }
            th.classList.add('races-sortable');
            th.title = (th.title ? th.title + '. ' : '') + 'Click: sorteaza. Palnie: filtreaza valorile coloanei.';
            var wrap = document.createElement('div');
            wrap.className = 'races-head-wrap';
            var label = document.createElement('span');
            label.className = 'races-head-label';
            while (th.firstChild) {
                label.appendChild(th.firstChild);
            }
            var controls = document.createElement('span');
            controls.className = 'races-head-controls';
            var arrow = document.createElement('span');
            arrow.className = 'races-sort-arrow';
            arrow.textContent = '↕';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'races-filter-btn';
            button.setAttribute('aria-label', 'Filtreaza coloana');
            button.innerHTML = '<i class="bi bi-funnel" aria-hidden="true"></i>';
            controls.appendChild(arrow);
            controls.appendChild(button);
            wrap.appendChild(label);
            wrap.appendChild(controls);
            th.appendChild(wrap);

            th.addEventListener('click', function (event) {
                if (event.target.closest('.races-filter-btn')) {
                    if (openState && openState.table === state && openState.column === column && !dropdown.hidden) {
                        closeDropdown();
                    } else {
                        openDropdown(column, button);
                    }
                    return;
                }
                // Bifa "selecteaza tot" si alte controale din antet nu declanseaza sortarea.
                if (event.target.closest('input, label, select, a')) {
                    return;
                }
                // Click: crescator, apoi descrescator, apoi ordinea initiala.
                if (state.sortColumn !== column) {
                    state.sortColumn = column;
                    state.sortDir = 1;
                } else if (state.sortDir === 1) {
                    state.sortDir = -1;
                } else {
                    state.sortColumn = null;
                }
                apply();
            });
        });
    };

    tables.forEach(initTable);

    document.addEventListener('click', function (event) {
        if (!dropdown.hidden && !dropdown.contains(event.target) && !event.target.closest('.races-filter-btn')) {
            closeDropdown();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeDropdown();
        }
    });
    // Derularea paginii inchide dropdown-ul; derularea in lista de valori nu.
    window.addEventListener('scroll', function (event) {
        if (!dropdown.contains(event.target)) {
            closeDropdown();
        }
    }, { passive: true, capture: true });
}());

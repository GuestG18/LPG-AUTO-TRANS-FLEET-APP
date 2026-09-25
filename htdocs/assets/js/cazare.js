/**
 * Pagina "Cazare".
 *
 * Doua responsabilitati:
 *   1. acelasi modal serveste si adaugarea, si editarea (comuta action + valorile);
 *   2. confirmarea stergerii, cu modal Bootstrap si fallback pe confirm().
 *
 * Bootstrap se incarca in footer, deci verificam disponibilitatea la click,
 * nu la initializare.
 */
(function () {
    'use strict';

    var urls = window.CAZARE_URLS || {};

    // -------------------------------------------------------------------------
    // Modal adaugare / editare
    // -------------------------------------------------------------------------

    var form = document.querySelector('[data-cazare-form]');

    function field(name) {
        return form ? form.querySelector('[data-cazare-field="' + name + '"]') : null;
    }

    function setValue(name, value) {
        var input = field(name);
        if (input) {
            input.value = value == null ? '' : String(value);
        }
    }

    /**
     * Listeaza facturile deja atasate, cu link de vizualizare si buton de stergere.
     * La adaugare lista este goala si zona ramane ascunsa.
     */
    function renderDocuments(raw) {
        var wrap = form ? form.querySelector('[data-cazare-docs-wrap]') : null;
        var list = form ? form.querySelector('[data-cazare-docs]') : null;
        if (!wrap || !list) {
            return;
        }

        var documents = [];
        try {
            documents = raw ? JSON.parse(raw) : [];
        } catch (error) {
            documents = [];
        }

        list.textContent = '';
        if (!documents.length) {
            wrap.classList.add('d-none');
            return;
        }

        documents.forEach(function (doc) {
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';

            var link = document.createElement('a');
            link.className = 'cazare-doc-link flex-grow-1 text-truncate';
            link.href = doc.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = doc.nume;

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.title = 'Șterge factura';
            remove.textContent = '×';
            remove.addEventListener('click', function () {
                var docForm = document.getElementById('cazareDeleteDocForm');
                var docInput = document.getElementById('cazareDeleteDocId');
                if (!docForm || !docInput) {
                    return;
                }
                docInput.value = String(doc.id);
                docForm.submit();
            });

            row.appendChild(link);
            row.appendChild(remove);
            list.appendChild(row);
        });

        wrap.classList.remove('d-none');
    }

    function applyMode(trigger) {
        if (!form) {
            return;
        }

        var title = form.querySelector('[data-cazare-title]');
        var isEdit = trigger != null && trigger.hasAttribute('data-cazare-edit');
        var fileInput = form.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.value = '';
        }

        if (isEdit) {
            form.setAttribute('action', urls.update || form.getAttribute('action'));
            if (title) {
                title.textContent = 'Editează cazarea';
            }
            setValue('id', trigger.getAttribute('data-id'));
            setValue('data', trigger.getAttribute('data-data'));
            setValue('sofer_id', trigger.getAttribute('data-sofer'));
            setValue('total', trigger.getAttribute('data-total'));
            setValue('total_cu_tva', trigger.getAttribute('data-total-tva'));
            setValue('observatii', trigger.getAttribute('data-observatii'));
            renderDocuments(trigger.getAttribute('data-documente'));
            return;
        }

        form.setAttribute('action', urls.store || form.getAttribute('action'));
        if (title) {
            title.textContent = 'Adaugă cazare';
        }
        ['id', 'data', 'sofer_id', 'total', 'total_cu_tva', 'observatii'].forEach(function (name) {
            setValue(name, '');
        });
        renderDocuments(null);
    }

    document.addEventListener('click', function (event) {
        var trigger = event.target.closest('[data-cazare-new], [data-cazare-edit]');
        if (trigger) {
            applyMode(trigger);
        }
    });

    // -------------------------------------------------------------------------
    // Confirmare stergere
    // -------------------------------------------------------------------------

    var pendingDeleteForm = null;
    var confirmModalEl = document.getElementById('cazareDeleteModal');
    var confirmButton = document.getElementById('cazareDeleteConfirm');
    var bulkForm = document.getElementById('cazareBulkDeleteForm');

    /** Textele modalului difera intre stergerea unui rand si stergerea multipla. */
    function deleteTexts(deleteForm) {
        if (deleteForm !== bulkForm) {
            return {
                title: 'Ștergi cazarea?',
                body: 'Înregistrarea și cheltuiala corespunzătoare de pe cursă vor fi șterse definitiv.',
                native: 'Ștergi definitiv această cazare?'
            };
        }

        var count = selectedIds().length;
        return {
            title: 'Ștergi ' + count + (count === 1 ? ' cazare?' : ' cazări?'),
            body: 'Înregistrările selectate și cheltuielile corespunzătoare de pe curse vor fi șterse definitiv.',
            native: 'Ștergi definitiv ' + count + (count === 1 ? ' cazare selectată?' : ' cazări selectate?')
        };
    }

    document.addEventListener('submit', function (event) {
        var deleteForm = event.target.closest ? event.target.closest('[data-cazare-delete]') : null;
        if (!deleteForm || deleteForm.dataset.cazareConfirmed === '1') {
            return;
        }

        event.preventDefault();

        var texts = deleteTexts(deleteForm);
        var modalApi = confirmModalEl && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(confirmModalEl)
            : null;

        if (confirmModalEl) {
            var titleEl = confirmModalEl.querySelector('[data-cazare-delete-title]');
            var textEl = confirmModalEl.querySelector('[data-cazare-delete-text]');
            if (titleEl) {
                titleEl.textContent = texts.title;
            }
            if (textEl) {
                textEl.textContent = texts.body;
            }
        }

        if (!modalApi) {
            // Fara Bootstrap disponibil: revenim la dialogul nativ.
            if (window.confirm(texts.native)) {
                deleteForm.dataset.cazareConfirmed = '1';
                deleteForm.submit();
            }
            return;
        }

        pendingDeleteForm = deleteForm;
        modalApi.show();
    });

    if (confirmButton) {
        confirmButton.addEventListener('click', function () {
            if (!pendingDeleteForm) {
                return;
            }

            var target = pendingDeleteForm;
            pendingDeleteForm = null;
            target.dataset.cazareConfirmed = '1';
            target.submit();
        });
    }

    if (confirmModalEl) {
        confirmModalEl.addEventListener('hidden.bs.modal', function () {
            pendingDeleteForm = null;
        });
    }

    // -------------------------------------------------------------------------
    // Selectie multipla + "Sterge selectate"
    // -------------------------------------------------------------------------

    var selectAll = document.querySelector('[data-cazare-select-all]');
    var bulkButton = document.querySelector('[data-cazare-bulk-delete]');
    var bulkCount = document.querySelector('[data-cazare-bulk-count]');

    function rowBoxes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-cazare-select]'));
    }

    function selectedIds() {
        return rowBoxes()
            .filter(function (box) { return box.checked; })
            .map(function (box) { return box.value; });
    }

    function refreshSelection() {
        var boxes = rowBoxes();
        var count = selectedIds().length;

        boxes.forEach(function (box) {
            var row = box.closest('tr');
            if (row) {
                row.classList.toggle('table-active', box.checked);
            }
        });

        if (selectAll) {
            selectAll.checked = boxes.length > 0 && count === boxes.length;
            selectAll.indeterminate = count > 0 && count < boxes.length;
            selectAll.disabled = boxes.length === 0;
        }
        if (bulkButton) {
            bulkButton.disabled = count === 0;
        }
        if (bulkCount) {
            bulkCount.textContent = String(count);
            bulkCount.classList.toggle('d-none', count === 0);
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target === selectAll) {
            rowBoxes().forEach(function (box) {
                box.checked = selectAll.checked;
            });
            refreshSelection();
            return;
        }
        if (event.target.matches && event.target.matches('[data-cazare-select]')) {
            refreshSelection();
        }
    });

    if (bulkButton && bulkForm) {
        bulkButton.addEventListener('click', function () {
            var ids = selectedIds();
            if (!ids.length) {
                return;
            }

            var holder = bulkForm.querySelector('[data-cazare-bulk-ids]');
            holder.textContent = '';
            ids.forEach(function (id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ids[]';
                input.value = id;
                holder.appendChild(input);
            });

            // requestSubmit declanseaza evenimentul submit, deci trece prin confirmarea de mai sus.
            delete bulkForm.dataset.cazareConfirmed;
            if (typeof bulkForm.requestSubmit === 'function') {
                bulkForm.requestSubmit();
            } else {
                bulkForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        });
    }

    refreshSelection();
}());

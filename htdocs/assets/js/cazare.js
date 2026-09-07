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

    document.addEventListener('submit', function (event) {
        var deleteForm = event.target.closest ? event.target.closest('[data-cazare-delete]') : null;
        if (!deleteForm || deleteForm.dataset.cazareConfirmed === '1') {
            return;
        }

        event.preventDefault();

        var modalApi = confirmModalEl && window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(confirmModalEl)
            : null;

        if (!modalApi) {
            // Fara Bootstrap disponibil: revenim la dialogul nativ.
            if (window.confirm('Ștergi definitiv această cazare?')) {
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
}());

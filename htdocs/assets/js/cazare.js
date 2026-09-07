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

    function applyMode(trigger) {
        if (!form) {
            return;
        }

        var title = form.querySelector('[data-cazare-title]');
        var isEdit = trigger != null && trigger.hasAttribute('data-cazare-edit');

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
            return;
        }

        form.setAttribute('action', urls.store || form.getAttribute('action'));
        if (title) {
            title.textContent = 'Adaugă cazare';
        }
        ['id', 'data', 'sofer_id', 'total', 'total_cu_tva', 'observatii'].forEach(function (name) {
            setValue(name, '');
        });
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

(function () {
    function closestInteractive(target) {
        if (!(target instanceof Element)) {
            return null;
        }

        return target.closest('a, button, input, select, textarea, label, .dropdown-menu');
    }

    function setExpanded(page, contractId) {
        page.querySelectorAll('[data-leasing-contract-row]').forEach(function (row) {
            var active = row.getAttribute('data-contract-id') === contractId;
            row.classList.toggle('is-selected', active);
            var icon = row.querySelector('.leasing-row-toggle i');
            var toggle = row.querySelector('.leasing-row-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', active ? 'true' : 'false');
            }
            if (icon) {
                icon.classList.toggle('bi-chevron-down', active);
                icon.classList.toggle('bi-chevron-right', !active);
            }
        });

        page.querySelectorAll('[data-leasing-expanded-row]').forEach(function (row) {
            row.classList.toggle('d-none', row.getAttribute('data-contract-id') !== contractId);
        });
    }

    function setTab(page, contractId, tabName) {
        setExpanded(page, contractId);

        page.querySelectorAll('[data-leasing-tab="' + contractId + '"]').forEach(function (tab) {
            tab.classList.toggle('is-active', tab.getAttribute('data-tab') === tabName);
        });

        page.querySelectorAll('[data-leasing-panel="' + contractId + '"]').forEach(function (panel) {
            panel.classList.toggle('d-none', panel.getAttribute('data-panel') !== tabName);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var page = document.querySelector('.leasing-page');
        if (!(page instanceof HTMLElement)) {
            return;
        }

        page.querySelectorAll('.leasing-filter-toolbar select, .leasing-filter-toolbar input[type="date"]').forEach(function (control) {
            control.addEventListener('change', function () {
                var form = control.closest('form');
                if (form instanceof HTMLFormElement) {
                    form.submit();
                }
            });
        });

        page.addEventListener('click', function (event) {
            var target = event.target;
            if (!(target instanceof Element)) {
                return;
            }

            var tab = target.closest('[data-leasing-tab]');
            if (tab) {
                event.preventDefault();
                setTab(page, tab.getAttribute('data-leasing-tab') || '', tab.getAttribute('data-tab') || 'details');
                return;
            }

            var openTab = target.closest('[data-leasing-open-tab]');
            if (openTab) {
                event.preventDefault();
                setTab(page, openTab.getAttribute('data-leasing-open-tab') || '', openTab.getAttribute('data-tab') || 'details');
                return;
            }

            var toggle = target.closest('[data-leasing-expand]');
            if (toggle) {
                event.preventDefault();
                setExpanded(page, toggle.getAttribute('data-leasing-expand') || '');
                return;
            }

            var row = target.closest('[data-leasing-contract-row]');
            if (row && !closestInteractive(target)) {
                setExpanded(page, row.getAttribute('data-contract-id') || '');
            }
        });

        document.querySelectorAll('.leasing-modal[id^="payLeasingInstallmentModal"]').forEach(function (modal) {
            modal.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget;
                if (!(trigger instanceof Element) || !trigger.hasAttribute('data-leasing-pay-installment')) {
                    return;
                }

                var installmentId = trigger.getAttribute('data-installment-id') || '';
                var amount = trigger.getAttribute('data-installment-amount') || '';
                var installmentNumber = trigger.getAttribute('data-installment-number') || '';
                var hiddenInput = modal.querySelector('input[name="installment_id"]');
                var amountInput = modal.querySelector('input[name="amount_paid"]');
                var title = modal.querySelector('[data-leasing-pay-title]');

                if (hiddenInput) {
                    hiddenInput.value = installmentId;
                }
                if (amountInput) {
                    amountInput.value = amount;
                }
                if (title) {
                    title.textContent = installmentNumber ? 'Marcheaz\u0103 rata #' + installmentNumber + ' ca pl\u0103tit\u0103' : 'Marcheaz\u0103 rata ca pl\u0103tit\u0103';
                }
            });
        });
    });
})();

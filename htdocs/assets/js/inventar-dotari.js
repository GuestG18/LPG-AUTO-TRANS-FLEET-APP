(function () {
    function escapeText(value) {
        var element = document.createElement('span');
        element.textContent = value || '';
        return element.innerHTML;
    }

    function emptyPreviewHtml() {
        return '<span class="inventory-equipment-thumb inventory-equipment-thumb-empty">Fără poză</span>';
    }

    function imagePreviewHtml(url, label) {
        if (!url) {
            return emptyPreviewHtml();
        }

        return '<a href="' + escapeText(url) + '" target="_blank" rel="noopener" class="inventory-equipment-thumb">'
            + '<img src="' + escapeText(url) + '" alt="' + escapeText(label || 'Poză produs') + '" loading="lazy">'
            + '</a>';
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function addMonths(dateValue, months) {
        if (!dateValue || !months || months <= 0) {
            return '';
        }

        var parts = dateValue.split('-').map(function (part) {
            return parseInt(part, 10);
        });

        if (parts.length !== 3 || parts.some(Number.isNaN)) {
            return '';
        }

        var year = parts[0];
        var monthIndex = parts[1] - 1;
        var day = parts[2];
        var targetMonthIndex = monthIndex + months;
        var targetYear = year + Math.floor(targetMonthIndex / 12);
        targetMonthIndex = ((targetMonthIndex % 12) + 12) % 12;
        var lastDay = new Date(targetYear, targetMonthIndex + 1, 0).getDate();
        var targetDay = Math.min(day, lastDay);

        return [
            targetYear,
            pad(targetMonthIndex + 1),
            pad(targetDay)
        ].join('-');
    }

    function updateNextInspection(form) {
        var lastInspection = form.querySelector('[data-inventory-last-inspection]');
        var interval = form.querySelector('[data-inventory-inspection-interval]');
        var nextInspection = form.querySelector('[data-inventory-next-inspection]');

        if (!lastInspection || !interval || !nextInspection) {
            return;
        }

        nextInspection.value = addMonths(lastInspection.value, parseInt(interval.value || '0', 10));
    }

    function syncCatalogSelection(form, options) {
        var select = form.querySelector('[data-inventory-catalog-select]');
        if (!select) {
            return;
        }

        var selected = select.options[select.selectedIndex];
        var category = form.querySelector('[data-inventory-category]');
        var cost = form.querySelector('[data-inventory-cost]');
        var interval = form.querySelector('[data-inventory-inspection-interval]');
        var preview = form.querySelector('[data-inventory-image-preview]');
        var onlyEmpty = options && options.onlyEmpty;
        var updatePreviewIfEmpty = options && options.updatePreviewIfEmpty;

        if (category) {
            category.value = selected ? (selected.dataset.category || '') : '';
        }

        if (cost && selected && (!onlyEmpty || cost.value === '')) {
            cost.value = selected.dataset.cost || cost.value;
        }

        if (interval && selected && (!onlyEmpty || interval.value === '')) {
            interval.value = selected.dataset.inspectionInterval || interval.value;
        }

        if (preview && selected) {
            var hasImage = !!preview.querySelector('img');
            if (!updatePreviewIfEmpty || !hasImage) {
                preview.innerHTML = imagePreviewHtml(selected.dataset.image || '', selected.dataset.imageLabel || selected.textContent.trim());
            }
        }

        updateNextInspection(form);
    }

    function attachImagePreview(form) {
        var input = form.querySelector('[data-inventory-image-input]');
        var preview = form.querySelector('[data-inventory-image-preview]');

        if (!input || !preview) {
            return;
        }

        input.addEventListener('change', function () {
            var file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) {
                syncCatalogSelection(form, { onlyEmpty: true, updatePreviewIfEmpty: false });
                return;
            }

            preview.innerHTML = imagePreviewHtml(URL.createObjectURL(file), file.name);
        });
    }

    function initInventoryRowActions() {
        var activeMenu = null;
        var activeTrigger = null;
        var pendingFrame = 0;

        function closeMenu(restoreFocus) {
            if (pendingFrame !== 0) {
                window.cancelAnimationFrame(pendingFrame);
                pendingFrame = 0;
            }

            if (!(activeMenu instanceof HTMLElement)) {
                activeMenu = null;
                activeTrigger = null;
                return;
            }

            activeMenu.hidden = true;
            activeMenu.classList.remove('is-open');
            activeMenu.style.left = '';
            activeMenu.style.top = '';

            if (activeTrigger instanceof HTMLButtonElement) {
                activeTrigger.setAttribute('aria-expanded', 'false');
                if (restoreFocus) {
                    activeTrigger.focus();
                }
            }

            activeMenu = null;
            activeTrigger = null;
        }

        function positionMenu() {
            if (!(activeMenu instanceof HTMLElement) || !(activeTrigger instanceof HTMLButtonElement)) {
                return;
            }

            var viewportPadding = 8;
            var gap = 8;
            var triggerRect = activeTrigger.getBoundingClientRect();

            activeMenu.hidden = false;
            activeMenu.classList.add('is-open');

            var menuRect = activeMenu.getBoundingClientRect();
            var menuWidth = menuRect.width || 232;
            var menuHeight = menuRect.height || 0;
            var left = triggerRect.left + (triggerRect.width / 2) - (menuWidth / 2);
            var top = triggerRect.bottom + gap;

            if (left + menuWidth > window.innerWidth - viewportPadding) {
                left = window.innerWidth - viewportPadding - menuWidth;
            }

            if (left < viewportPadding) {
                left = viewportPadding;
            }

            if (top + menuHeight > window.innerHeight - viewportPadding) {
                top = triggerRect.top - gap - menuHeight;
            }

            if (top < viewportPadding) {
                top = Math.max(viewportPadding, window.innerHeight - viewportPadding - menuHeight);
            }

            activeMenu.style.left = Math.round(left) + 'px';
            activeMenu.style.top = Math.round(top) + 'px';
        }

        function schedulePosition() {
            if (!(activeMenu instanceof HTMLElement) || pendingFrame !== 0) {
                return;
            }

            pendingFrame = window.requestAnimationFrame(function () {
                pendingFrame = 0;
                positionMenu();
            });
        }

        document.querySelectorAll('[data-inventory-row-actions]').forEach(function (actionsEl) {
            if (!(actionsEl instanceof HTMLElement)) {
                return;
            }

            var trigger = actionsEl.querySelector('.inventory-actions-trigger');
            var menu = actionsEl.querySelector('.inventory-actions-menu');

            if (!(trigger instanceof HTMLButtonElement) || !(menu instanceof HTMLElement)) {
                return;
            }

            trigger.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var shouldClose = activeMenu === menu && !menu.hidden;
                closeMenu(false);

                if (shouldClose) {
                    return;
                }

                activeMenu = menu;
                activeTrigger = trigger;
                trigger.setAttribute('aria-expanded', 'true');
                positionMenu();
            });

            menu.addEventListener('click', function (event) {
                var target = event.target;
                if (!(target instanceof Element) || target.closest('.inventory-actions-item') === null) {
                    return;
                }

                window.setTimeout(function () {
                    closeMenu(false);
                }, 0);
            });
        });

        document.addEventListener('click', function (event) {
            if (!(activeMenu instanceof HTMLElement)) {
                return;
            }

            var target = event.target;
            if (!(target instanceof Node)) {
                closeMenu(false);
                return;
            }

            if (activeMenu.contains(target) || (activeTrigger instanceof HTMLElement && activeTrigger.contains(target))) {
                return;
            }

            closeMenu(false);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && activeMenu instanceof HTMLElement) {
                event.preventDefault();
                closeMenu(true);
            }
        });

        window.addEventListener('resize', function () {
            closeMenu(false);
        });
        window.addEventListener('orientationchange', function () {
            closeMenu(false);
        });
        document.addEventListener('scroll', schedulePosition, true);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initInventoryRowActions();

        document.querySelectorAll('[data-inventory-assignment-form]').forEach(function (form) {
            var select = form.querySelector('[data-inventory-catalog-select]');
            var lastInspection = form.querySelector('[data-inventory-last-inspection]');
            var interval = form.querySelector('[data-inventory-inspection-interval]');

            if (select) {
                select.addEventListener('change', function () {
                    syncCatalogSelection(form, { onlyEmpty: false, updatePreviewIfEmpty: false });
                });
                syncCatalogSelection(form, { onlyEmpty: true, updatePreviewIfEmpty: true });
            }

            if (lastInspection) {
                lastInspection.addEventListener('change', function () {
                    updateNextInspection(form);
                });
            }

            if (interval) {
                interval.addEventListener('input', function () {
                    updateNextInspection(form);
                });
            }

            attachImagePreview(form);
            updateNextInspection(form);
        });
    });
}());

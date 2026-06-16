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

    document.addEventListener('DOMContentLoaded', function () {
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

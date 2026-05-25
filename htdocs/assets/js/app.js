document.addEventListener('click', function (event) {
    var target = event.target;

    if (!target.matches('[data-confirm]')) {
        return;
    }

    var message = target.getAttribute('data-confirm') || 'Sigur doresti sa continui?';
    var confirmed = window.confirm(message);

    if (!confirmed) {
        event.preventDefault();
        event.stopPropagation();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var toggleButton = document.querySelector('[data-sidebar-toggle]');
    var storageKey = 'fleet.sidebarCollapsed';

    if (!toggleButton) {
        return;
    }

    function isCollapsed() {
        return document.body.classList.contains('sidebar-collapsed');
    }

    function syncToggleState() {
        toggleButton.setAttribute('aria-expanded', isCollapsed() ? 'false' : 'true');
        toggleButton.classList.toggle('is-collapsed', isCollapsed());
    }

    syncToggleState();

    toggleButton.addEventListener('click', function () {
        document.body.classList.toggle('sidebar-collapsed');
        try {
            window.localStorage.setItem(storageKey, isCollapsed() ? '1' : '0');
        } catch (error) {
        }
        syncToggleState();
    });
});

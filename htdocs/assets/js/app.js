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

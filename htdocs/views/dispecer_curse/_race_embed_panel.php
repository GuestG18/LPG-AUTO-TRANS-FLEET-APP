<?php
/**
 * "Desfasurator curse" pe pagina de editare: acelasi tabel ca in Dispecer curse,
 * cu un singur rand - cursa editata. Tabelul traieste din scriptul paginii
 * Dispecer curse (popover-e "N detalii", diurna, coloane, fazele), asa ca il
 * incarcam intr-un iframe (?embed=race) in loc sa-l duplicam. Inaltimea iframe-ului
 * urmeaza continutul (si popover-ele / ferestrele deschise), iar clic pe rand
 * deschide fisa cursei peste pagina aceasta (race-view-overlay.js).
 */
$raceEmbedUrl = build_query_url([
    'page' => 'dispecer_curse',
    'action' => 'index',
    'ids' => (string) $raceId,
    'embed' => 'race',
]);
?>
<div class="dispatcher-races-embed mt-3">
    <iframe
        class="dispatcher-races-embed-frame"
        src="<?= e($raceEmbedUrl) ?>"
        title="<?= e('Desfasurator curse - cursa #' . $raceId) ?>"
        data-races-embed-frame
    ></iframe>
</div>
<script>
(function () {
    var frameEl = document.querySelector('[data-races-embed-frame]');
    if (!(frameEl instanceof HTMLIFrameElement)) {
        return;
    }
    // Ferestrele Bootstrap (ex. Modifica diurnele) sunt fixe in iframe: le facem loc.
    var MODAL_MIN_HEIGHT = 640;
    var OPEN_LAYERS = '[data-dispatcher-summary-popover]:not([hidden]), .dispatcher-column-panel:not([hidden]), .dropdown-menu.show';
    var pending = false;

    var fit = function () {
        pending = false;
        var doc;
        try {
            doc = frameEl.contentDocument;
        } catch (error) {
            return;
        }
        var cardEl = doc ? doc.querySelector('.dispatcher-races-card') : null;
        if (!cardEl) {
            return;
        }
        var bottom = cardEl.getBoundingClientRect().bottom;
        Array.prototype.forEach.call(doc.querySelectorAll(OPEN_LAYERS), function (layerEl) {
            // Inaltimea fireasca, nu cea taiata de fereastra mica a iframe-ului.
            bottom = Math.max(bottom, layerEl.getBoundingClientRect().top + layerEl.scrollHeight + 12);
        });
        if (doc.body.classList.contains('modal-open')) {
            bottom = Math.max(bottom, MODAL_MIN_HEIGHT);
        }
        var height = Math.ceil(bottom + 6);
        if (Math.abs(frameEl.offsetHeight - height) > 2) {
            frameEl.style.height = height + 'px';
        }
    };
    var scheduleFit = function () {
        if (!pending) {
            pending = true;
            window.requestAnimationFrame(fit);
        }
    };

    frameEl.addEventListener('load', function () {
        var doc;
        try {
            doc = frameEl.contentDocument;
        } catch (error) {
            return;
        }
        if (!doc || !doc.body) {
            return;
        }
        fit();
        new MutationObserver(scheduleFit).observe(doc.body, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'hidden', 'style']
        });
        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(scheduleFit).observe(doc.body);
        }
    });
})();
</script>

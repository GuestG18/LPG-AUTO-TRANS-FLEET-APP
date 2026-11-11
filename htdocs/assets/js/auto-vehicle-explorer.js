/*
 * Schema vehiculului pe pagina Reparatii Auto (views/maintenance/_vehicle_explorer.php).
 * Vehiculul vine din selectorul paginii; exploratorul propriu-zis e vehicle-xray.js. Anvelopele se
 * gestioneaza in Stoc Anvelope: aici se pot doar marca, cu link catre randul lor de acolo.
 * ?fx_slot=&fx_member= (din schema unui rand din Stoc Anvelope) deschide direct componenta.
 */
(function () {
    'use strict';

    var holder = document.querySelector('[data-auto-xray]');
    var dataEl = document.getElementById('ftUnitsData');
    if (!holder || !dataEl || !window.FleetXray) { return; }

    var unit = null;
    try { unit = JSON.parse(dataEl.textContent || '[]')[0] || null; } catch (error) { window.console.error(error); }
    if (!unit) { return; }

    var STORAGE_KEY = 'auto.xray.hidden';
    var readHidden = function () { try { return window.localStorage.getItem(STORAGE_KEY) === '1'; } catch (error) { return false; } };
    var saveHidden = function (value) { try { window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0'); } catch (error) { /* doar o preferinta */ } };

    var tireUrl = holder.dataset.tireUrl || '';
    var explorer = null;

    // Cu schema vizibila pagina arata doar schema (configuratie generala sau vehicul). Sistemul apasat pe schema
    // se deschide chiar sub ea (sectiunea e mutata temporar acolo, cu formularele si butoanele ei);
    // „Ascunde” (sau Esc / acelasi punct apasat din nou) o pune la loc. „Piese pe tip de axa” are
    // butonul ei in capul schemei. Cautarea arata din nou toata lista cat timp are text.
    var page = holder.closest('[data-as-page]');
    var focusMode = !!page;
    var xraySection = holder.closest('[data-auto-xray-section]') || holder;
    var dock = document.createElement('div');
    dock.className = 'repair-auto-xray-dock';
    dock.hidden = true;
    xraySection.insertAdjacentElement('afterend', dock);
    var docked = null;
    var dockedPlaceholder = null;
    var dockedCloseBtn = null;
    var matrix = null; // e randat dupa acest script: se cauta la DOMContentLoaded
    var matrixBtn = null;

    var syncMatrixBtn = function () {
        if (matrixBtn) { matrixBtn.setAttribute('aria-pressed', docked && docked === matrix ? 'true' : 'false'); }
    };

    var undock = function () {
        if (!docked) { return; }
        if (dockedCloseBtn) { dockedCloseBtn.remove(); }
        dockedPlaceholder.replaceWith(docked);
        docked.classList.remove('is-docked');
        docked = null;
        dockedPlaceholder = null;
        dockedCloseBtn = null;
        dock.hidden = true;
        syncMatrixBtn();
    };

    var dockSection = function (section) {
        if (docked === section) { undock(); return; }
        dockTo(section);
    };

    // Punctul apasat pe schema: acelasi punct inca o data inchide sistemul (doar la configuratia
    // generala; la vehicul click-ul redeschide si panoul pieselor montate), alt punct al aceluiasi
    // sistem il lasa deschis.
    var lastSlotId = null;
    var openFromSlot = function (slot, slotId, allowToggle) {
        if (!focusMode) { return; }
        var section = document.getElementById('sistem-' + slot.category);
        if (!section) { return; }
        if (docked === section && (!allowToggle || slotId !== lastSlotId)) { lastSlotId = slotId; return; }
        lastSlotId = slotId;
        dockSection(section);
    };

    var dockTo = function (section) {
        undock();
        dockedPlaceholder = document.createComment('sistem-dock');
        section.replaceWith(dockedPlaceholder);
        dock.appendChild(section);
        section.classList.add('is-docked');
        section.hidden = false;
        if (section.tagName === 'DETAILS') { section.open = true; }
        docked = section;

        var head = section.querySelector('.as-section-head');
        if (head) {
            dockedCloseBtn = document.createElement('button');
            dockedCloseBtn.type = 'button';
            dockedCloseBtn.className = 'as-btn is-small repair-auto-xray-dock-close';
            dockedCloseBtn.title = 'Ascunde (Esc)';
            dockedCloseBtn.innerHTML = '<i class="bi bi-chevron-up" aria-hidden="true"></i> Ascunde';
            dockedCloseBtn.addEventListener('click', undock);
            head.appendChild(dockedCloseBtn);
        }
        syncMatrixBtn();

        dock.hidden = false;
        // Derulare minima: doar cat sa se vada inceputul sistemului, schema ramane cat mai mult in ecran.
        var rect = dock.getBoundingClientRect();
        var visible = Math.min(Math.round(window.innerHeight * 0.45), Math.round(rect.height));
        var delta = rect.top + visible - window.innerHeight;
        if (rect.top < 0) { delta = rect.top - 90; }
        if (delta > 0 || rect.top < 0) { window.scrollBy({ top: delta, behavior: 'smooth' }); }
        section.classList.remove('is-flash');
        void section.offsetWidth;
        section.classList.add('is-flash');
    };

    var search = page ? page.querySelector('[data-as-search]') : null;
    if (focusMode && search) {
        search.addEventListener('input', function () {
            var searching = search.value.trim() !== '';
            if (searching) { undock(); }
            page.classList.toggle('is-schema-searching', searching);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || !docked) { return; }
        var target = event.target;
        if (target && target.closest && target.closest('input, select, textarea')) { return; }
        undock();
    });

    var mount = function () {
        if (explorer) { return; }
        explorer = window.FleetXray.mount(holder, unit, {
            onTire: unit.template ? null : function (positionId) { explorer.markTire(String(positionId)); },
            tireLink: tireUrl && !unit.template ? function (positionId) { return tireUrl + '#anvelopa-p' + positionId; } : null,
            // Configuratia generala nu are un vehicul real: punctul deschide doar sistemul lui sub schema.
            onSlot: unit.template ? function (slot) { openFromSlot(slot, slot.id, true); } : null,
            // La vehicul se incarca panoul pieselor montate, iar sistemul lui se deschide sub schema.
            afterSlot: unit.template ? null : function (slot, slotId) { openFromSlot(slot, slotId, false); }
        });
    };

    var toggle = document.querySelector('[data-auto-xray-toggle]');
    var setHidden = function (hidden) {
        holder.hidden = hidden;
        if (hidden) { undock(); }
        if (focusMode) { page.classList.toggle('is-schema-focus', !hidden); }
        if (matrixBtn) { matrixBtn.hidden = hidden; }
        if (toggle) {
            toggle.setAttribute('aria-expanded', hidden ? 'false' : 'true');
            toggle.querySelector('i').className = 'bi ' + (hidden ? 'bi-chevron-down' : 'bi-chevron-up');
            toggle.querySelector('[data-auto-xray-toggle-label]').textContent = hidden ? 'Arată schema' : 'Ascunde schema';
        }
        // Scena se masoara: exploratorul se creeaza abia cand e vizibil.
        if (!hidden) { mount(); }
    };
    if (toggle) {
        toggle.addEventListener('click', function () {
            var hidden = !holder.hidden;
            setHidden(hidden);
            saveHidden(hidden);
        });
    }

    var params = new URLSearchParams(window.location.search);
    var slot = params.get('fx_slot');
    var memberId = Number(params.get('fx_member'));
    setHidden(slot ? false : readHidden());

    // Tabelul pe tipuri de axa si sectiunile sistemelor sunt mai jos in pagina decat acest script.
    var whenReady = function () {
        matrix = document.getElementById('piese-pe-tip-axa');
        var head = xraySection.querySelector('.repair-auto-xray-head');
        if (focusMode && matrix && head) {
            matrixBtn = document.createElement('button');
            matrixBtn.type = 'button';
            matrixBtn.className = 'repair-auto-xray-toggle repair-auto-xray-matrix-btn';
            matrixBtn.setAttribute('aria-pressed', 'false');
            matrixBtn.hidden = holder.hidden;
            matrixBtn.innerHTML = '<i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span>Piese pe tip de axă</span>';
            matrixBtn.addEventListener('click', function () { dockSection(matrix); });
            head.insertBefore(matrixBtn, head.querySelector('[data-auto-xray-toggle]'));
        }

        // Dupa o salvare, pagina revine cu #sistem-... / #piese-pe-tip-axa: acel panou se redeschide sub schema.
        if (focusMode && !holder.hidden && window.location.hash) {
            var target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
            if (target && (target === matrix || target.matches('[data-as-section]'))) {
                dockSection(target);
            }
        }
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', whenReady);
    } else {
        whenReady();
    }

    if (slot && explorer) {
        explorer.selectSlot(slot, memberId);
        holder.scrollIntoView({ block: 'start', behavior: 'smooth' });
        params.delete('fx_slot');
        params.delete('fx_member');
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }
})();

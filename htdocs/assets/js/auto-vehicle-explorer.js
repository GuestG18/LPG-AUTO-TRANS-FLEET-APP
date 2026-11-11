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
    var mount = function () {
        if (explorer) { return; }
        explorer = window.FleetXray.mount(holder, unit, {
            onTire: unit.template ? null : function (positionId) { explorer.markTire(String(positionId)); },
            tireLink: tireUrl && !unit.template ? function (positionId) { return tireUrl + '#anvelopa-p' + positionId; } : null,
            // Configuratia generala nu are un vehicul real: punctul duce la sistemul lui din pagina.
            onSlot: unit.template ? function (slot) {
                var section = document.getElementById('sistem-' + slot.category);
                if (!section) { return; }
                section.scrollIntoView({ block: 'start', behavior: 'smooth' });
                section.classList.remove('is-flash');
                void section.offsetWidth;
                section.classList.add('is-flash');
            } : null
        });
    };

    var toggle = document.querySelector('[data-auto-xray-toggle]');
    var setHidden = function (hidden) {
        holder.hidden = hidden;
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

    if (slot && explorer) {
        explorer.selectSlot(slot, memberId);
        holder.scrollIntoView({ block: 'start', behavior: 'smooth' });
        params.delete('fx_slot');
        params.delete('fx_member');
        window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
    }
})();

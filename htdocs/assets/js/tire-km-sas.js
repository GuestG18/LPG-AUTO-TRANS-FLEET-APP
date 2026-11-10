/*
 * Pagina Anvelope: la deschidere actualizeaza km din SAS, cate cateva vehicule pe cerere, si spune
 * cand a citit ultima data si ce vehicule nu pot primi km. Nu reincarca singura pagina.
 */
(function () {
    'use strict';

    var el = document.querySelector('[data-ft-sas-status]');
    if (!el) { return; }

    var changed = 0;
    var show = function (text, isWarning) {
        el.textContent = text;
        el.classList.toggle('is-warning', !!isWarning);
    };
    var formatTime = function (value) {
        var match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(String(value || ''));
        return match ? match[3] + '.' + match[2] + '.' + match[1] + ' ' + match[4] + ':' + match[5] : '';
    };

    var finish = function (result) {
        var parts = ['Km anvelope din SAS'];
        if (result.last_synced) { parts[0] += ', actualizați la ' + formatTime(result.last_synced); }
        if (result.errors > 0) { parts.push('SAS nu a răspuns pentru ' + result.errors + ' vehicule (se reia)'); }
        if (result.no_sas.length > 0) { parts.push('Fără SAS, km nu cresc: ' + result.no_sas.join(', ')); }
        if (result.uncoupled.length > 0) { parts.push('Semiremorci necuplate, km nu cresc: ' + result.uncoupled.join(', ')); }
        show(parts.join(' · '), result.errors > 0 || result.no_sas.length > 0 || result.uncoupled.length > 0);

        if (changed > 0) {
            var reload = document.createElement('button');
            reload.type = 'button';
            reload.className = 'ft-sas-reload';
            reload.textContent = 'Reîncarcă pentru km noi';
            reload.addEventListener('click', function () { window.location.reload(); });
            el.appendChild(reload);
        }
    };

    var step = function () {
        var body = new FormData();
        body.append('_token', el.dataset.csrf || '');
        window.fetch(el.dataset.url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.ok) { throw new Error(result.error || 'Eroare'); }
                changed += result.changed;
                if (result.busy) { show('Km anvelope din SAS: actualizare în curs.'); return; }
                if (result.remaining > 0 && result.synced > 0) {
                    show('Se actualizează km din SAS… mai sunt ' + result.remaining + ' vehicule.');
                    step();
                    return;
                }
                finish(result);
            })
            .catch(function (error) {
                window.console.error(error);
                show('Km din SAS nu au putut fi actualizați acum.', true);
            });
    };
    step();
})();

/**
 * Explicit commercial recalculation for an existing trip.
 *
 * Editing a trip never reprices it silently. This control asks the backend
 * (TransportPricingService, via ?page=tarife_transport&action=preview) what the
 * trip WOULD cost under the tariff in force on its business date, shows the
 * operator a before/after diff, and only then arms the `recalculate_tariff`
 * flag that the server honours.
 *
 * No pricing formula lives here.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('edit_recalculate_tariff_btn');
        var flag = document.getElementById('edit_recalculate_tariff');
        if (!button || !flag) {
            return;
        }

        var form = button.closest('form');
        if (!form) {
            return;
        }

        function val(selector) {
            var el = form.querySelector(selector);
            if (!el) {
                return '';
            }
            return el.value === undefined ? '' : el.value;
        }

        function formatRo(value, decimals) {
            var n = Number(value);
            if (isNaN(n)) {
                return '—';
            }
            return n.toLocaleString('ro-RO', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            });
        }

        button.addEventListener('click', function () {
            var payload = new URLSearchParams();
            payload.set('beneficiar_id', val('[name="beneficiar_id"]'));
            payload.set('tip_transport', val('[name="tip_transport"]'));
            // The business date drives version resolution.
            payload.set('data_cursa', val('[name="data_cursa"]') || val('[name="data_inceput"]'));
            payload.set('vehicle_id', val('[name="vehicle_id"]'));
            payload.set('loc_incarcare_id', val('[name="loc_incarcare_id"]'));
            payload.set('zona_distributie_id', val('[name="zona_distributie_id"]'));
            payload.set('cantitate_incarcata', val('[name="cantitate_incarcata"]'));
            payload.set('km_cursa', val('[name="km_cursa"]'));
            payload.set('km_totali', val('[name="km_totali"]'));
            payload.set('ore_aspirare', val('[name="ore_aspirare"]'));
            payload.set('km_dislocare', val('[name="km_dislocare"]'));
            payload.set('tona_livrata', val('[name="tona_livrata"]'));
            payload.set('tona_aspirata_lichida', val('[name="tona_aspirata_lichida"]'));
            payload.set('tona_aspirata_gazoasa', val('[name="tona_aspirata_gazoasa"]'));

            button.disabled = true;
            var originalHtml = button.innerHTML;
            button.textContent = 'Se calculeaza...';

            fetch(button.getAttribute('data-preview-url'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: payload.toString(),
                credentials: 'same-origin'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    button.disabled = false;
                    button.innerHTML = originalHtml;

                    if (!data || !data.ok || !data.quote) {
                        window.alert('Nu s-a putut calcula tariful aplicabil. Incearca din nou.');
                        return;
                    }

                    var quote = data.quote;
                    var oldTotal = Number(button.getAttribute('data-race-total') || 0);
                    var oldPrice = Number(button.getAttribute('data-race-price') || 0);
                    var newTotal = Number(quote.total_facturare || 0);
                    var newPrice = Number(quote.pret_tarifare || 0);

                    var lines = [];
                    lines.push('Recalculare conform datei cursei (' + (quote.business_date || '—') + ')');
                    lines.push('');
                    lines.push('Tarif vechi:   ' + formatRo(oldPrice, 4));
                    lines.push('Total vechi:   ' + formatRo(oldTotal, 2) + ' lei');
                    lines.push('');
                    lines.push('Tarif aplicabil: ' + formatRo(newPrice, 4) + ' ' + (quote.unit || ''));
                    lines.push('Total recalculat: ' + formatRo(newTotal, 2) + ' lei');
                    lines.push('');
                    lines.push('Diferenta: ' + (newTotal - oldTotal >= 0 ? '+' : '') + formatRo(newTotal - oldTotal, 2) + ' lei');

                    if (Array.isArray(quote.warnings) && quote.warnings.length) {
                        lines.push('');
                        lines.push('Atentionari:');
                        quote.warnings.forEach(function (w) { lines.push(' - ' + w); });
                    }

                    lines.push('');
                    lines.push('Confirmi recalcularea? Valorile se salveaza doar la salvarea cursei.');

                    if (window.confirm(lines.join('\n'))) {
                        flag.value = '1';
                        button.classList.remove('btn-outline-primary');
                        button.classList.add('btn-success');
                        button.innerHTML = '<i class="bi bi-check-lg"></i> Recalculare armata — salveaza cursa';
                    }
                })
                .catch(function () {
                    button.disabled = false;
                    button.innerHTML = originalHtml;
                    window.alert('Eroare de retea la calcularea tarifului.');
                });
        });
    });
})();

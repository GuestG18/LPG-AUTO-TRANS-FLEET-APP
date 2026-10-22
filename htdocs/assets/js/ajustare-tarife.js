/*
 * Centru ajustare tarife combustibil & curs BNR (?page=ajustare_tarife).
 *
 * Desenează din #atPageData: tabelul de simulare (grupat pe tip, filtrabil),
 * detaliile pe tarif, graficele (Chart.js), comparația cu ultima aprobare,
 * istoricul aprobărilor și fluxul de aplicare manuală.
 * Serverul recalculează și validează tot la aplicare — selecția și valorile
 * de aici sunt doar ce a văzut utilizatorul.
 */
(function () {
    'use strict';

    const dataNode = document.getElementById('atPageData');
    if (!dataNode) {
        return;
    }
    const data = JSON.parse(dataNode.textContent || '{}');
    const rules = Array.isArray(data.rules) ? data.rules : [];
    const can = data.can || {};
    const urls = data.urls || {};

    const MONTHS_SHORT = ['ian', 'feb', 'mar', 'apr', 'mai', 'iun', 'iul', 'aug', 'sep', 'oct', 'noi', 'dec'];
    const UNIT_LABELS = { 'lei/km': 'lei/km', 'lei/tona': 'lei/tonă', 'lei/cursa': 'lei/cursă', 'lei/ora': 'lei/oră' };
    const STATUS_HINTS = {
        simulated: 'Prag atins — tariful se modifică cu același procent ca motorina; eligibil pentru aplicare.',
        within: 'Variația față de referință este sub prag — tariful rămâne neschimbat.',
        no_baseline: 'Nu există referință salvată pentru comparație.',
        insufficient: 'Date insuficiente pentru calcul.',
        stale: 'O sursă de date nu este actualizată — aplicarea este blocată.',
        scheduled: 'Există deja o versiune programată pentru acest tarif.'
    };

    const state = {
        filters: { beneficiar: '', tip: '' },
        currency: (() => {
            try {
                return window.localStorage.getItem('at-currency') === 'eur' ? 'eur' : 'lei';
            } catch (error) {
                return 'lei';
            }
        })(),
        selected: new Set(rules.filter((r) => r.eligible).map((r) => r.rule_signature)),
        detailsGroup: null
    };

    // ------------------------------------------------------------ format

    const fmt = (value, decimals) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '—';
        }
        return Number(value).toLocaleString('ro-RO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    };
    const rate = (value) => {
        if (value === null || value === undefined) {
            return '—';
        }
        const decimals = Math.abs(value - Math.round(value * 100) / 100) > 0.000001 ? 4 : 2;
        return fmt(value, decimals);
    };
    const pct = (value, decimals = 2) => {
        if (value === null || value === undefined) {
            return '—';
        }
        const sign = value > 0.000001 ? '+' : (value < -0.000001 ? '−' : '');
        return sign + fmt(Math.abs(value), decimals) + '%';
    };
    const toneClass = (value) => (value === null || value === undefined) ? 'at-muted-val' : (value > 0.000001 ? 'at-up' : (value < -0.000001 ? 'at-down' : 'at-flat'));
    const dateRo = (value) => {
        if (!value) {
            return '—';
        }
        const [y, m, d] = String(value).slice(0, 10).split('-');
        return d + '.' + m + '.' + y;
    };
    const dateTimeRo = (value) => value ? dateRo(value) + ', ' + String(value).slice(11, 16) : '—';
    const unitLabel = (unit) => UNIT_LABELS[unit] || unit;
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // Conversie lei → € la cursul mediu EUR/RON al ferestrei (BNR). Tariful contractual
    // rămâne în lei; € este doar echivalent afișat. Un tarif deja în EUR nu se convertește.
    const FX_AVG = Number((data.indicators || {}).fx_avg) || null;
    const toEur = (value, currency) => (value === null || value === undefined) ? null
        : (currency === 'EUR' ? Number(value) : (FX_AVG ? Number(value) / FX_AVG : null));
    const money = (value) => (value === null || value === undefined) ? '—' : fmt(value, Math.abs(value) < 10 ? 4 : 2);
    const eurUnit = (unit) => unitLabel(unit).replace(/^lei\//, '€/');
    const inEur = () => state.currency === 'eur' && FX_AVG !== null;
    const eurNote = (value, rule) => (FX_AVG && value !== null && value !== undefined)
        ? '≈ ' + money(toEur(value, rule.currency)) + ' ' + eurUnit(rule.unit) : '';
    const range = (min, max) => {
        if (min === null || min === undefined) {
            return '—';
        }
        const format = inEur() ? money : rate;
        return Math.abs(max - min) < 0.00005 ? format(min) : format(min) + ' – ' + format(max);
    };

    const toast = (message, isError) => {
        const node = document.querySelector('[data-at-toast]');
        if (!node) {
            return;
        }
        node.textContent = message;
        node.classList.toggle('is-error', !!isError);
        node.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => { node.hidden = true; }, isError ? 9000 : 5000);
    };

    const post = async (url, fields) => {
        const body = new FormData();
        body.append('_token', data.token || '');
        Object.entries(fields || {}).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => body.append(key + '[]', item));
            } else if (value && typeof value === 'object') {
                Object.entries(value).forEach(([k, v]) => body.append(key + '[' + k + ']', v));
            } else {
                body.append(key, value);
            }
        });
        const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await response.json().catch(() => ({ ok: false, message: 'Răspuns invalid de la server (' + response.status + ').' }));
        if (!response.ok && json.ok !== false) {
            json.ok = false;
        }
        return json;
    };
    const getJson = async (url) => {
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        return response.json();
    };

    const modal = (id) => {
        const node = document.getElementById(id);
        return node && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(node) : null;
    };

    // ------------------------------------------------------------ tabel

    const filteredRules = () => rules.filter((rule) =>
        (!state.filters.beneficiar || rule.beneficiar_nume === state.filters.beneficiar)
        && (!state.filters.tip || rule.transport_type === state.filters.tip));

    const STATUS_PRIORITY = ['simulated', 'scheduled', 'stale', 'within', 'no_baseline', 'insufficient'];

    const buildGroups = (list) => {
        const groups = new Map();
        list.forEach((rule) => {
            if (!groups.has(rule.group_key)) {
                groups.set(rule.group_key, { key: rule.group_key, label: rule.group_label, rules: [], units: new Set() });
            }
            const group = groups.get(rule.group_key);
            group.rules.push(rule);
            group.units.add(rule.unit);
        });
        const ORDER = ['primar_km', 'primar_cursa', 'primar_tona', 'dist_unic', 'dist_mic', 'dist_mare', 'dist_alt', 'dist_km', 'dist_cursa', 'pd_tona', 'pd_km', 'pd_cursa', 'comp_ora', 'comp_km', 'comp_tona'];
        return [...groups.values()].sort((a, b) => ORDER.indexOf(a.key) - ORDER.indexOf(b.key)).map((group) => {
            const shown = (r, v) => inEur() ? toEur(v, r.currency) : Number(v);
            const values = group.rules.map((r) => shown(r, r.value));
            const proposed = group.rules.filter((r) => r.proposed !== null).map((r) => shown(r, r.proposed));
            const changes = group.rules.filter((r) => r.change_pct !== null).map((r) => Number(r.change_pct));
            const statuses = group.rules.map((r) => r.status).sort((a, b) => STATUS_PRIORITY.indexOf(a) - STATUS_PRIORITY.indexOf(b));
            return {
                ...group,
                unit: [...group.units].map(inEur() ? eurUnit : unitLabel).join(', '),
                valueMin: Math.min(...values),
                valueMax: Math.max(...values),
                proposedMin: proposed.length ? Math.min(...proposed) : null,
                proposedMax: proposed.length ? Math.max(...proposed) : null,
                change: changes.length ? changes.reduce((s, v) => s + v, 0) / changes.length : null,
                status: statuses[0],
                statusLabel: group.rules.find((r) => r.status === statuses[0]).status_label,
                eligible: group.rules.filter((r) => r.eligible).length,
                beneficiaries: new Set(group.rules.map((r) => r.beneficiar_nume)).size
            };
        });
    };

    const renderTable = () => {
        const tbody = document.querySelector('#atSimTable tbody');
        if (!tbody) {
            return;
        }
        const groups = buildGroups(filteredRules());
        if (!groups.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="at-empty">Nu există tarife pentru filtrul selectat.</td></tr>';
        } else {
            tbody.innerHTML = groups.map((g) => {
                const sub = g.rules.length + (g.rules.length === 1 ? ' tarif' : ' tarife') + ' · ' + g.beneficiaries + (g.beneficiaries === 1 ? ' beneficiar' : ' beneficiari');
                const simulated = g.proposedMin === null ? '<span class="at-muted">—</span>' : range(g.proposedMin, g.proposedMax);
                return '<tr>'
                    + '<td class="at-group-name">' + esc(g.label) + '<small>' + esc(sub) + '</small></td>'
                    + '<td>' + esc(g.unit) + '</td>'
                    + '<td class="num">' + range(g.valueMin, g.valueMax) + '</td>'
                    + '<td class="num">' + simulated + '</td>'
                    + '<td class="num at-pct ' + toneClass(g.change) + '">' + pct(g.change) + '</td>'
                    + '<td class="ctr"><span class="at-pill at-pill-' + esc(g.status) + '" title="' + esc(STATUS_HINTS[g.status] || '') + '">' + esc(g.statusLabel) + '</span></td>'
                    + '<td class="ctr"><button type="button" class="at-view-btn" data-at-details="' + esc(g.key) + '"><i class="bi bi-eye" aria-hidden="true"></i>Vezi detalii</button></td>'
                    + '</tr>';
            }).join('');
        }

        const foot = document.querySelector('[data-at-sim-foot]');
        if (foot) {
            const eligible = rules.filter((r) => r.eligible).length;
            foot.textContent = (eligible > 0 ? eligible + ' tarife eligibile pentru aplicare. ' : '')
                + (inEur() ? 'Valori în € = lei ÷ ' + fmt(FX_AVG, 4) + ' (curs mediu EUR/RON BNR al ferestrei). ' : '');
        }
        renderSelectionInfo();
    };

    const renderSelectionInfo = () => {
        const node = document.querySelector('[data-at-selection]');
        if (!node) {
            return;
        }
        const count = rules.filter((r) => r.eligible && state.selected.has(r.rule_signature)).length;
        const eligible = rules.filter((r) => r.eligible).length;
        node.textContent = eligible === 0
            ? 'Niciun tarif eligibil pentru aplicare acum.'
            : count + ' din ' + eligible + ' tarife eligibile selectate (din „Vezi detalii”).';
    };

    // ------------------------------------------------------------ detalii

    const ruleRow = (rule) => {
        const ref = rule.reference;
        const ind = data.indicators || {};
        const checked = state.selected.has(rule.rule_signature) ? 'checked' : '';
        const selectable = can.apply;
        return '<tr class="at-rule-row' + (rule.eligible ? '' : ' is-ineligible') + '">'
            + (selectable ? '<td><input type="checkbox" class="form-check-input" data-at-pick="' + esc(rule.rule_signature) + '" ' + checked + ' aria-label="Selectează"></td>' : '')
            + '<td><strong>' + esc(rule.beneficiar_nume) + '</strong><small>' + esc(rule.transport_label) + ' · ' + esc(rule.component_label)
                + (rule.route_label ? ' · ' + esc(rule.route_label) : '') + '</small></td>'
            + '<td class="num">' + rate(rule.value) + '<small>' + esc(unitLabel(rule.unit)) + ' · ' + esc(rule.currency)
                + (eurNote(rule.value, rule) ? ' · ' + esc(eurNote(rule.value, rule)) : '')
                + (rule.value_source === 'version' ? ' · versiune din ' + dateRo(rule.version_valid_from) : ' · din Configurare transport') + '</small></td>'
            + '<td class="num">' + (ref ? fmt(ref.diesel_ron, 4) + ' lei/l<small>' + fmt(ref.eur_ron, 4) + ' EUR/RON · ' + fmt(ref.diesel_eur, 4) + ' €/l</small>' : '—') + '</td>'
            + '<td class="num">' + fmt(ind.diesel_ron, 4) + ' lei/l<small>' + fmt(ind.fx_avg, 4) + ' EUR/RON · ' + fmt(ind.diesel_eur, 4) + ' €/l</small></td>'
            + '<td class="num ' + toneClass(rule.variation_pct) + '" title="Valoare exactă: ' + (rule.variation_pct === null ? '—' : esc(Number(rule.variation_pct).toFixed(6))) + '%">' + pct(rule.variation_pct, 3) + '</td>'
            + '<td class="num"><strong>' + (rule.proposed === null ? '—' : rate(rule.proposed)) + '</strong><small>'
                + (rule.proposed !== null && eurNote(rule.proposed, rule) ? esc(eurNote(rule.proposed, rule)) + '<br>' : '') + (rule.formula ? esc(rule.formula) : '') + '</small></td>'
            + '<td><span class="at-pill at-pill-' + esc(rule.status) + '">' + esc(rule.status_label) + '</span>'
                + '<div class="at-reason">' + esc(STATUS_HINTS[rule.status] || '') + (rule.scheduled_from ? ' (de la ' + dateRo(rule.scheduled_from) + ')' : '')
                + '</div></td>'
            + '</tr>';
    };

    const openDetails = (groupKey) => {
        state.detailsGroup = groupKey;
        const list = filteredRules().filter((r) => r.group_key === groupKey);
        if (!list.length) {
            return;
        }
        const ind = data.indicators || {};
        const selectable = can.apply;
        document.querySelector('[data-at-details-title]').textContent = list[0].group_label + ' — ' + list.length + (list.length === 1 ? ' tarif' : ' tarife');
        document.querySelector('[data-at-details-body]').innerHTML =
            '<div class="at-kv">'
            + '<div><span>Fereastră analiză</span>' + dateRo(ind.window && ind.window.start) + ' – ' + dateRo(ind.window && ind.window.end) + '</div>'
            + '<div><span>Bază ajustare</span>' + (ind.basis === 'diesel_ron' ? 'Motorină în lei' : 'Motorină în EUR') + '</div>'
            + '<div><span>Prag</span>±' + fmt(ind.threshold, 2) + '%</div>'
            + '<div><span>Data intrării în vigoare propusă</span>' + dateRo(document.getElementById('atEffective')?.value) + '</div>'
            + '</div>'
            + '<div class="table-responsive"><table class="at-table"><thead><tr>'
            + (selectable ? '<th><input type="checkbox" class="form-check-input" data-at-pick-all aria-label="Selectează toate"></th>' : '')
            + '<th>Beneficiar / tarif</th><th class="num">Tarif contractual</th><th class="num">Referință</th><th class="num">Curent</th>'
            + '<th class="num">Variație</th><th class="num">Tarif propus</th><th>Eligibilitate</th>'
            + '</tr></thead><tbody>' + list.map(ruleRow).join('') + '</tbody></table></div>'
            + '<p class="at-muted mt-2 mb-0" style="font-size:11.5px">Formula: tarif × (1 + variație %), doar când variația atinge pragul. Variația se compară cu referința proprie a tarifului (după o aprobare) sau cu referința implicită. Bifele stabilesc ce tarife eligibile se aplică.</p>';
        modal('atDetailsModal')?.show();
    };

    // ------------------------------------------------------------ grafice

    const charts = {};
    const shortDate = (value) => {
        const [, m, d] = String(value).split('-');
        return Number(d) + ' ' + MONTHS_SHORT[Number(m) - 1];
    };

    const baseOptions = () => ({
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: { backgroundColor: '#0f172a', padding: 9, titleFont: { size: 11 }, bodyFont: { size: 12, weight: '600' } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 11 }, maxTicksLimit: 7, autoSkip: true, maxRotation: 0 } }
        }
    });

    const drawFx = (series) => {
        const canvas = document.getElementById('atFxChart');
        if (!canvas || !window.Chart) {
            return;
        }
        canvas.parentElement.querySelector('.at-chart-empty').hidden = series.length > 0;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight || 220);
        gradient.addColorStop(0, 'rgba(29,108,255,.22)');
        gradient.addColorStop(1, 'rgba(29,108,255,.02)');
        const avg = data.indicators && data.indicators.fx_avg;
        const options = baseOptions();
        options.scales.y = { grid: { color: '#eef1f6' }, ticks: { color: '#64748b', font: { size: 11 }, callback: (v) => fmt(v, 2) } };
        options.plugins.tooltip.callbacks = {
            title: (items) => dateRo(series[items[0].dataIndex].d),
            label: (item) => item.datasetIndex === 0 ? ' Curs EUR/RON: ' + fmt(item.parsed.y, 4) : ' Medie fereastră: ' + fmt(item.parsed.y, 4)
        };
        const config = {
            type: 'line',
            data: {
                labels: series.map((p) => shortDate(p.d)),
                datasets: [
                    {
                        data: series.map((p) => p.v), borderColor: '#1d6cff', backgroundColor: gradient, fill: true, borderWidth: 2, tension: .25,
                        pointRadius: series.map((_, i) => i === series.length - 1 ? 4 : 0), pointBackgroundColor: '#1d6cff', pointBorderColor: '#fff', pointBorderWidth: 2, pointHoverRadius: 4
                    },
                    { data: series.map(() => avg), borderColor: '#94a3b8', borderDash: [5, 4], borderWidth: 1.2, pointRadius: 0, fill: false }
                ]
            },
            options
        };
        charts.fx?.destroy();
        charts.fx = new window.Chart(ctx, config);
    };

    const drawDiesel = (series) => {
        const canvas = document.getElementById('atDieselChart');
        if (!canvas || !window.Chart) {
            return;
        }
        canvas.parentElement.querySelector('.at-chart-empty').hidden = series.length > 0;
        const ctx = canvas.getContext('2d');
        const gradient = ctx.createLinearGradient(0, 0, 0, canvas.parentElement.clientHeight || 220);
        gradient.addColorStop(0, 'rgba(22,163,74,.22)');
        gradient.addColorStop(1, 'rgba(22,163,74,.02)');
        const options = baseOptions();
        options.scales.y = { position: 'left', grid: { color: '#eef1f6' }, ticks: { color: '#64748b', font: { size: 11 }, callback: (v) => fmt(v, 2) } };
        options.scales.y1 = { position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#64748b', font: { size: 11 }, callback: (v) => fmt(v, 2) } };
        options.plugins.tooltip.callbacks = {
            title: (items) => dateRo(series[items[0].dataIndex].d) + ' · ' + fmt(series[items[0].dataIndex].l, 0) + ' l',
            label: (item) => item.datasetIndex === 0 ? ' Motorină: ' + fmt(item.parsed.y, 4) + ' lei/l' : ' Motorină: ' + fmt(item.parsed.y, 4) + ' €/l'
        };
        const config = {
            type: 'line',
            data: {
                labels: series.map((p) => shortDate(p.d)),
                datasets: [
                    { data: series.map((p) => p.ron), yAxisID: 'y', borderColor: '#16a34a', backgroundColor: gradient, fill: true, borderWidth: 2, tension: .25, pointRadius: 0, pointHoverRadius: 3 },
                    { data: series.map((p) => p.eur), yAxisID: 'y1', borderColor: '#f97316', backgroundColor: 'rgba(249,115,22,.08)', fill: true, borderWidth: 2, tension: .25, pointRadius: 0, pointHoverRadius: 3 }
                ]
            },
            options
        };
        // Modul test: prețul introdus pe lună, ca treaptă peste prețul real.
        if (data.testMode) {
            const testMonths = data.testMonths || {};
            config.data.datasets.push({
                data: series.map((p) => testMonths[p.d.slice(0, 7)] ?? null), yAxisID: 'y', borderColor: '#7c3aed',
                borderDash: [6, 4], borderWidth: 2, stepped: true, pointRadius: 0, fill: false, spanGaps: false
            });
            const label = options.plugins.tooltip.callbacks.label;
            options.plugins.tooltip.callbacks.label = (item) => item.datasetIndex === 2 ? ' Preț test: ' + fmt(item.parsed.y, 4) + ' lei/l' : label(item);
        }
        charts.diesel?.destroy();
        charts.diesel = new window.Chart(ctx, config);
    };

    const periodLabel = (months) => months === 1 ? 'ultima lună' : (months === 12 ? 'ultimele 12 luni' : 'ultimele ' + months + ' luni');

    document.querySelectorAll('[data-at-periods]').forEach((group) => {
        group.addEventListener('click', async (event) => {
            const button = event.target.closest('button[data-months]');
            if (!button || button.classList.contains('active')) {
                return;
            }
            const months = Number(button.dataset.months);
            const which = group.dataset.atPeriods;
            group.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === button));
            try {
                const json = await getJson(urls.series + '&luni=' + months);
                if (!json.ok) {
                    throw new Error(json.message || 'Eroare');
                }
                if (which === 'fx') {
                    drawFx(json.fx);
                    const label = document.querySelector('[data-at-period-label="fx"]');
                    if (label) {
                        label.textContent = periodLabel(months);
                    }
                } else {
                    drawDiesel(json.diesel);
                }
            } catch (error) {
                toast('Nu s-au putut încărca datele graficului: ' + error.message, true);
            }
        });
    });

    // ------------------------------------------------------------ aplicare

    const selectedEligible = () => rules.filter((r) => r.eligible && state.selected.has(r.rule_signature));

    const openApply = () => {
        const effective = document.getElementById('atEffective')?.value || '';
        const list = selectedEligible();
        const body = document.querySelector('[data-at-apply-body]');
        const today = new Date().toISOString().slice(0, 10);
        let html = '';
        if (!list.length) {
            const reasons = {};
            rules.forEach((r) => { reasons[r.status_label] = (reasons[r.status_label] || 0) + 1; });
            html = '<div class="at-empty at-empty-box">Nu există tarife eligibile selectate.<br><small>' + Object.entries(reasons).map(([k, v]) => esc(k) + ': ' + v).join(' · ')
                + '</small><br><small>Eligibil = prag atins, date actualizate și referință salvată.</small></div>';
        } else {
            html = '<div class="at-kv">'
                + '<div><span>Intră în vigoare</span><strong>' + dateRo(effective) + '</strong></div>'
                + '<div><span>Tarife</span><strong>' + list.length + '</strong></div>'
                + '<div><span>Variație referință</span><strong>' + pct(data.indicators.variation, 3) + '</strong></div>'
                + '<div><span>Notă</span>' + esc(document.getElementById('atNote')?.value || '—') + '</div></div>'
                + '<div class="table-responsive"><table class="at-table at-table-sm"><thead><tr><th>Beneficiar / tarif</th><th class="num">Actual</th><th class="num">Nou</th><th class="num">Dif.</th></tr></thead><tbody>'
                + list.map((r) => '<tr><td>' + esc(r.beneficiar_nume) + '<small>' + esc(r.group_label) + (r.route_label ? ' · ' + esc(r.route_label) : '') + '</small></td>'
                    + '<td class="num">' + rate(r.value) + ' <small>' + esc(unitLabel(r.unit)) + '<br>' + esc(eurNote(r.value, r)) + '</small></td>'
                    + '<td class="num"><strong>' + rate(r.proposed) + '</strong><small>' + esc(eurNote(r.proposed, r)) + '</small></td>'
                    + '<td class="num ' + toneClass(r.change_pct) + '">' + pct(r.change_pct) + '</td></tr>').join('')
                + '</tbody></table></div>'
                + '<p class="at-muted mt-2 mb-0" style="font-size:12px">Se creează versiuni noi de tarif de la data aleasă; versiunile anterioare rămân în istoric. Cursele și facturile existente nu se recalculează.</p>';
        }
        if (effective <= today) {
            html = '<div class="at-alert at-alert-warn">Data intrării în vigoare trebuie să fie în viitor.</div>' + html;
        }
        body.innerHTML = html;
        const confirmButton = document.querySelector('[data-at-apply-confirm]');
        confirmButton.dataset.blocked = (!list.length || effective <= today) ? '1' : '';
        document.querySelector('[data-at-apply-check]').checked = false;
        document.querySelector('[data-at-apply-word]').value = '';
        updateApplyGate();
        modal('atApplyModal')?.show();
    };

    const updateApplyGate = () => {
        const button = document.querySelector('[data-at-apply-confirm]');
        if (!button) {
            return;
        }
        const ok = !button.dataset.blocked
            && document.querySelector('[data-at-apply-check]').checked
            && document.querySelector('[data-at-apply-word]').value.trim().toUpperCase() === 'APLIC';
        button.disabled = !ok;
    };

    const confirmApply = async (button) => {
        const items = {};
        selectedEligible().forEach((r) => { items[r.rule_signature] = String(r.proposed); });
        button.disabled = true;
        button.textContent = 'Se aplică…';
        const json = await post(urls.apply, {
            items,
            effective_from: document.getElementById('atEffective')?.value || '',
            note: document.getElementById('atNote')?.value || '',
            set_default: document.querySelector('[data-at-apply-default]').checked ? '1' : '',
            confirm: 'APLIC'
        });
        button.textContent = 'Aplică tarifele';
        if (json.ok) {
            modal('atApplyModal')?.hide();
            toast(json.message);
            setTimeout(() => window.location.reload(), 1600);
        } else {
            updateApplyGate();
            toast(json.message || 'Aplicarea a eșuat.', true);
        }
    };

    // ------------------------------------------------------------ aprobări / comparare

    const showInfo = (title, html) => {
        document.querySelector('[data-at-info-title]').textContent = title;
        document.querySelector('[data-at-info-body]').innerHTML = html;
        modal('atInfoModal')?.show();
    };

    const showApproval = async (id) => {
        const json = await getJson(urls.approval + '&id=' + encodeURIComponent(id));
        if (!json.ok) {
            toast(json.message || 'Aprobarea nu a putut fi încărcată.', true);
            return;
        }
        const a = json.approval;
        // € la cursul mediu EUR/RON folosit la aprobare, nu la cel de azi.
        const approvalFx = Number(a.current_eur_ron) || null;
        const html = '<div class="at-kv">'
            + '<div><span>Aprobat la</span>' + dateTimeRo(a.approved_at) + '</div>'
            + '<div><span>Utilizator</span>' + esc(a.user_nume || a.approved_by_name || '—') + '</div>'
            + '<div><span>În vigoare de la</span>' + dateRo(a.effective_from) + '</div>'
            + '<div><span>Regulă</span>' + (a.adjust_basis === 'diesel_ron' ? 'motorină lei' : 'motorină EUR') + ' · același procent, prag ±' + fmt(a.threshold_percent, 2) + '%</div>'
            + '<div><span>Referință motorină / curs</span>' + fmt(a.reference_diesel_ron, 4) + ' lei/l · ' + fmt(a.reference_eur_ron, 4) + '</div>'
            + '<div><span>La aprobare</span>' + fmt(a.current_diesel_ron, 4) + ' lei/l · ' + fmt(a.current_eur_ron, 4) + '</div>'
            + '<div><span>Variație</span>' + pct(a.variation_percent === null ? null : Number(a.variation_percent), 3) + '</div>'
            + '<div><span>Notă</span>' + esc(a.note || '—') + '</div></div>'
            + '<div class="table-responsive"><table class="at-table at-table-sm"><thead><tr><th>Beneficiar / tarif</th><th class="num">Vechi</th><th class="num">Nou</th><th class="num">Ajustare</th></tr></thead><tbody>'
            + (a.items || []).map((it) => '<tr><td>' + esc(it.beneficiar_nume) + '<small>' + esc(it.group_label || '') + (it.route_label ? ' · ' + esc(it.route_label) : '') + '</small></td>'
                + '<td class="num">' + rate(Number(it.old_value)) + ' <small>' + esc(unitLabel(it.unit)) + (approvalFx ? '<br>≈ ' + money(Number(it.old_value) / approvalFx) + ' ' + esc(eurUnit(it.unit)) : '') + '</small></td>'
                + '<td class="num"><strong>' + rate(Number(it.new_value)) + '</strong>' + (approvalFx ? '<small>≈ ' + money(Number(it.new_value) / approvalFx) + ' ' + esc(eurUnit(it.unit)) + '</small>' : '') + '</td>'
                + '<td class="num ' + toneClass(Number(it.change_percent)) + '">' + pct(Number(it.change_percent)) + '</td></tr>').join('')
            + '</tbody></table></div>';
        showInfo('Aprobarea #' + a.id, html);
    };

    const showAllApprovals = async () => {
        const json = await getJson(urls.approvals);
        const list = (json && json.approvals) || [];
        const html = list.length
            ? '<table class="at-table at-table-sm"><thead><tr><th>Dată</th><th>Utilizator</th><th>În vigoare</th><th class="num">Tarife</th><th class="num">Variație</th><th>Notă</th><th></th></tr></thead><tbody>'
                + list.map((a) => '<tr><td>' + dateTimeRo(a.approved_at) + '</td><td>' + esc(a.user_nume || a.approved_by_name || '—') + '</td><td>' + dateRo(a.effective_from) + '</td>'
                    + '<td class="num">' + Number(a.items_count) + '</td><td class="num ' + toneClass(a.avg_change_percent === null ? null : Number(a.avg_change_percent)) + '">'
                    + pct(a.avg_change_percent === null ? null : Number(a.avg_change_percent)) + '</td><td class="at-ellipsis">' + esc(a.note || '') + '</td>'
                    + '<td><button type="button" class="at-view-btn" data-at-approval="' + Number(a.id) + '"><i class="bi bi-eye"></i>Vezi</button></td></tr>').join('')
                + '</tbody></table>'
            : '<div class="at-empty at-empty-box">Nicio aprobare înregistrată.</div>';
        showInfo('Toate aprobările', html);
    };

    const showCompare = async () => {
        const json = await getJson(urls.compare);
        const c = json && json.compare;
        if (!c) {
            showInfo('Comparație cu ultima aprobare', '<div class="at-empty at-empty-box">Nu există încă o aprobare anterioară. Comparația cu referința implicită este afișată în „Analiză impact”.</div>');
            return;
        }
        const row = (label, then, now, decimals, unit) => {
            const change = then && now ? (now / then - 1) * 100 : null;
            return '<tr><td>' + label + '</td><td class="num">' + fmt(then, decimals) + ' ' + unit + '</td><td class="num">' + fmt(now, decimals) + ' ' + unit
                + '</td><td class="num ' + toneClass(change) + '">' + pct(change, 3) + '</td></tr>';
        };
        const d = c.decomposition;
        showInfo('Comparație cu aprobarea #' + c.approval.id + ' (' + dateRo(c.approval.approved_at) + ')',
            '<table class="at-table at-table-sm"><thead><tr><th>Indicator</th><th class="num">La aprobare</th><th class="num">Acum</th><th class="num">Variație</th></tr></thead><tbody>'
            + row('Motorină medie', c.then.diesel_ron, c.now.diesel_ron, 4, 'lei/l')
            + row('Curs EUR/RON mediu', c.then.eur_ron, c.now.eur_ron, 4, '')
            + row('Motorină în EUR', c.then.diesel_eur, c.now.diesel_eur, 4, '€/l')
            + '</tbody></table>'
            + (d ? '<p class="at-muted mt-2 mb-0" style="font-size:12px">Efect motorină ' + pct(d.diesel_ron_pct, 3) + ' · efect curs ' + pct(d.fx_only_pct, 3) + ' · combinat ' + pct(d.combined_pct, 3) + '.</p>' : ''));
    };

    // ------------------------------------------------------------ evenimente

    document.addEventListener('click', async (event) => {
        const details = event.target.closest('[data-at-details]');
        if (details) {
            openDetails(details.dataset.atDetails);
            return;
        }
        const approval = event.target.closest('[data-at-approval]');
        if (approval) {
            showApproval(approval.dataset.atApproval);
            return;
        }
        if (event.target.closest('[data-at-all-approvals]')) {
            showAllApprovals();
            return;
        }
        if (event.target.closest('[data-at-compare]')) {
            showCompare();
            return;
        }
        const currencyButton = event.target.closest('[data-at-currency] button[data-cur]');
        if (currencyButton && !currencyButton.disabled) {
            state.currency = currencyButton.dataset.cur;
            try {
                window.localStorage.setItem('at-currency', state.currency);
            } catch (error) {
                // preferința e doar o comoditate locală
            }
            syncCurrencyButtons();
            renderTable();
            return;
        }
        if (event.target.closest('[data-at-test-clear]')) {
            document.querySelectorAll('.at-test-input').forEach((input) => { input.value = ''; });
            return;
        }
        if (event.target.closest('[data-at-apply]')) {
            if (data.testMode) {
                toast('Aplicarea tarifelor este dezactivată în modul test.', true);
                return;
            }
            openApply();
            return;
        }
        const confirmButton = event.target.closest('[data-at-apply-confirm]');
        if (confirmButton && !confirmButton.disabled) {
            confirmApply(confirmButton);
            return;
        }
        const snapshot = event.target.closest('[data-at-save-snapshot]');
        if (snapshot) {
            snapshot.disabled = true;
            const json = await post(urls.snapshot, { note: document.getElementById('atNote')?.value || '' });
            snapshot.disabled = false;
            toast(json.message || (json.ok ? 'Salvat.' : 'Eroare.'), !json.ok);
            return;
        }
        const sync = event.target.closest('[data-at-sync]');
        if (sync && !sync.disabled) {
            sync.disabled = true;
            sync.classList.add('is-busy');
            const json = await post(urls.sync, {});
            toast(json.message || 'Sincronizare încheiată.', !json.ok);
            setTimeout(() => window.location.reload(), json.ok ? 900 : 2500);
            return;
        }
        if (event.target.closest('[data-at-calc-run]')) {
            runCalculator();
            return;
        }
        const usePeriod = event.target.closest('[data-at-calc-baseline]');
        if (usePeriod && !usePeriod.disabled) {
            usePeriod.disabled = true;
            const json = await post(urls.period_baseline, { b: calcValue('b'), b_len: calcValue('b_len') });
            toast(json.message || 'Eroare.', !json.ok);
            if (json.ok) {
                setTimeout(() => window.location.reload(), 1200);
            } else {
                usePeriod.disabled = false;
            }
        }
    });

    // ------------------------------------------------------------ calculator perioade

    const calcValue = (name) => document.querySelector('[data-at-calc="' + name + '"]')?.value || '';

    const runCalculator = async () => {
        const box = document.querySelector('[data-at-calc-result]');
        if (!box) {
            return;
        }
        box.innerHTML = '<div class="at-empty">Se calculează…</div>';
        const query = '&a=' + encodeURIComponent(calcValue('a')) + '&a_len=' + encodeURIComponent(calcValue('a_len'))
            + '&b=' + encodeURIComponent(calcValue('b')) + '&b_len=' + encodeURIComponent(calcValue('b_len'));
        let json;
        try {
            json = await getJson(urls.compare_periods + query);
        } catch (error) {
            json = { ok: false, message: error.message };
        }
        if (!json || !json.ok) {
            box.innerHTML = '<div class="at-alert at-alert-warn"><span>' + esc((json && json.message) || 'Calculul a eșuat.') + '</span></div>';
            return;
        }
        const r = json.result;
        const a = r.compared;
        const b = r.reference;
        const d = r.decomposition;
        if (!d) {
            box.innerHTML = '<div class="at-alert at-alert-warn"><span>Nu există date suficiente (motorină sau curs BNR) pentru una dintre perioade.</span></div>';
            return;
        }
        const reached = r.threshold_state === 'up' || r.threshold_state === 'down';
        const row = (label, vb, va, decimals, unit, change) => '<tr><td>' + label + '</td><td class="num">' + fmt(vb, decimals) + ' ' + unit
            + '</td><td class="num">' + fmt(va, decimals) + ' ' + unit + '</td><td class="num ' + toneClass(change) + '">' + pct(change, 2) + '</td></tr>';
        const period = (p) => esc(p.label) + '<small>' + dateRo(p.start) + ' – ' + dateRo(p.end) + ' · ' + fmt(p.liters, 0) + ' l · ' + p.fillups + ' alim. · ' + p.fx_observations + ' cursuri BNR</small>';
        const basisLabel = r.basis === 'diesel_ron' ? 'motorina în lei' : 'motorina în euro';
        const example = reached ? 1.18 * (1 + r.tariff_change_pct / 100) : 1.18;
        box.innerHTML = '<div class="at-calc-grid">'
            + '<table class="at-table at-table-sm"><thead><tr><th></th><th class="num">Referință</th><th class="num">Comparată</th><th class="num">Variație</th></tr></thead><tbody>'
            + '<tr><td>Perioada</td><td class="num">' + period(b) + '</td><td class="num">' + period(a) + '</td><td></td></tr>'
            + row('Motorină medie', b.diesel_ron, a.diesel_ron, 4, 'lei/l', d.diesel_ron_pct)
            + row('Curs EUR/RON mediu', b.eur_ron, a.eur_ron, 4, '', d.fx_rate_pct)
            + row('Motorină în euro', b.diesel_eur, a.diesel_eur, 4, '€/l', d.combined_pct)
            + '</tbody></table>'
            + '<div class="at-calc-verdict ' + (reached ? (r.threshold_state === 'up' ? 'is-up' : 'is-down') : 'is-within') + '">'
            + '<span>Variație ' + esc(basisLabel) + '</span><strong>' + pct(r.variation_pct, 2) + '</strong>'
            + '<em>' + (reached ? 'Prag ±' + fmt(r.threshold, 2) + '% depășit' : 'Sub pragul de ±' + fmt(r.threshold, 2) + '%') + '</em>'
            + '<p>' + (reached
                ? 'Tarifele se modifică cu <b>' + pct(r.tariff_change_pct, 2) + '</b>: ex. 1,18 lei/km → <b>' + fmt(example, 4) + ' lei/km</b>.'
                : 'Tarifele rămân neschimbate.') + '</p>'
            + '<small>Motorină lei ' + pct(d.diesel_ron_pct, 2) + ' · efect curs ' + pct(d.fx_only_pct, 2) + ' · combinat ' + pct(d.combined_pct, 2) + '</small>'
            + (can.configure && !data.testMode
                ? '<button type="button" class="btn btn-sm btn-outline-primary" data-at-calc-baseline>Folosește ' + esc(b.label) + ' ca referință</button>'
                : '')
            + '</div></div>'
            + (r.test_mode ? '<p class="at-muted mt-2 mb-0" style="font-size:11.5px">Mod test: motorina folosește prețurile de test introduse pe lună (unde există).</p>' : '');
    };

    document.addEventListener('change', (event) => {
        const pick = event.target.closest('[data-at-pick]');
        if (pick) {
            if (pick.checked) {
                state.selected.add(pick.dataset.atPick);
            } else {
                state.selected.delete(pick.dataset.atPick);
            }
            renderSelectionInfo();
            return;
        }
        const all = event.target.closest('[data-at-pick-all]');
        if (all) {
            document.querySelectorAll('[data-at-pick]').forEach((box) => {
                box.checked = all.checked;
                if (all.checked) {
                    state.selected.add(box.dataset.atPick);
                } else {
                    state.selected.delete(box.dataset.atPick);
                }
            });
            renderSelectionInfo();
            return;
        }
        const filter = event.target.closest('[data-at-filter]');
        if (filter) {
            state.filters[filter.dataset.atFilter] = filter.value;
            renderTable();
            return;
        }
        if (event.target.matches('[data-at-apply-check]')) {
            updateApplyGate();
        }
    });

    document.addEventListener('input', (event) => {
        if (event.target.matches('[data-at-apply-word]')) {
            updateApplyGate();
        }
        if (event.target.id === 'atNote') {
            const counter = document.querySelector('[data-at-counter]');
            if (counter) {
                counter.textContent = String(event.target.value.length);
            }
        }
    });

    // ------------------------------------------------------------ pornire

    const syncCurrencyButtons = () => {
        document.querySelectorAll('[data-at-currency] button[data-cur]').forEach((button) => {
            button.disabled = button.dataset.cur === 'eur' && FX_AVG === null;
            button.classList.toggle('active', button.dataset.cur === (inEur() ? 'eur' : 'lei'));
        });
    };
    syncCurrencyButtons();
    renderTable();
    drawFx((data.series && data.series.fx) || []);
    drawDiesel((data.series && data.series.diesel) || []);

    // Cursul BNR mai vechi de o zi: încearcă o actualizare discretă (serverul o
    // limitează și o sare dacă datele sunt deja la zi).
    if (data.needsAutoSync) {
        post(urls.auto_sync, {}).then((json) => {
            if (json && json.ran && json.inserted > 0) {
                toast('Curs BNR actualizat (' + json.inserted + ' zile noi). Se reîncarcă…');
                setTimeout(() => window.location.reload(), 1200);
            }
        }).catch(() => {});
    }
}());

/**
 * Formularul de regula din page=notificari: mai multe evenimente / tipuri de document
 * intr-o singura salvare. Rezumatul de jos arata regulile care se vor crea, calculate
 * la fel ca in NotificationRuleController::validateRuleInput() (buildItems + numele generat).
 */
(() => {
    const form = document.querySelector('[data-nrf-form]');
    const modal = document.querySelector('[data-nrf-modal]');
    if (!form || !modal) {
        return;
    }

    let config = {};
    try {
        config = JSON.parse(form.querySelector('[data-nrf-config]')?.textContent || '{}');
    } catch (error) {
        config = {};
    }

    const conditions = config.conditions || {};
    const eventLabels = config.eventLabels || {};
    const entityLabels = config.entityLabels || {};
    const eventDisplayLabels = config.eventDisplay || {};
    const entityDisplayLabels = config.entityDisplay || {};
    const revizieStatus = config.revizieStatus || {};
    const eventEntity = config.eventEntity || {};
    const smsChannels = config.smsChannels || ['sms', 'email_sms'];
    const approvalEvent = config.approvalEvent || 'inactive_approval_pending';
    const maxRules = Number(config.maxRules || 60);
    const families = ['vehicle', 'driver', 'equipment'];
    const familyLabels = { vehicle: 'Vehicul', driver: 'Șofer', equipment: 'Dotări' };

    const $ = (selector, root = form) => root.querySelector(selector);
    const $$ = (selector, root = form) => Array.from(root.querySelectorAll(selector));

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));

    const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
    const selectedEvents = () => $$('[data-nrf-event]:checked').map((input) => input.value);
    const channel = () => ($('[data-nrf-channel]:checked') || {}).value || 'email';
    const recipientMode = () => ($('[data-nrf-recipient]:checked') || {}).value || 'admins';
    const familyAll = (family) => ($(`[data-nrf-doc-all="${family}"]`) || {}).value !== '0';
    const familyDocs = (family) => $$(`input[name="doc_types[${family}][]"]:checked`).map((input) => input.value);
    const familyUsed = (events, family) => events.some((event) => (conditions[event] || {}).docs === family);
    const usesCondition = (events, key) => events.filter((event) => conditions[event] && conditions[event][key]);

    // Etichetele serverului: numele generat trebuie sa fie identic cu cel salvat.
    const eventServerLabel = (event) => `${entityLabels[eventEntity[event]] || ''}: ${eventLabels[event] || event}`;
    const eventUiLabel = (event) => eventDisplayLabels[event] || eventLabels[event] || event;
    const entityUiLabel = (entity) => entityDisplayLabels[entity] || entityLabels[entity] || entity;

    // Aceeasi desfacere ca pe server: un eveniment x fiecare tip de document bifat.
    const buildItems = (events) => {
        const items = [];
        events.forEach((event) => {
            const family = (conditions[event] || {}).docs || '';
            const docs = family && !familyAll(family) ? familyDocs(family) : [];
            if (docs.length === 0) {
                items.push({ event, doc: '' });
                return;
            }
            docs.forEach((doc) => items.push({ event, doc }));
        });
        return items;
    };

    const suggestName = (events) => {
        if (events.length === 0) {
            return '';
        }
        return events.map((event) => {
            const family = (conditions[event] || {}).docs || '';
            const docs = family && !familyAll(family) ? familyDocs(family) : [];
            return docs.length > 0 && docs.length <= 3
                ? `${eventServerLabel(event)} ${docs.join(', ')}`
                : eventServerLabel(event);
        }).join(' + ').slice(0, 150);
    };

    const baseName = (events) => {
        const typed = (form.elements.name?.value || '').trim();
        return typed !== '' ? typed : (suggestName(events) || 'Regula notificare');
    };

    const itemName = (item, base, isGroup) => {
        if (!isGroup) {
            return base;
        }
        const isRevizie = Boolean((conditions[item.event] || {}).revizie);
        const doc = item.doc && !isRevizie ? ` · ${item.doc}` : '';
        return `${base} — ${eventServerLabel(item.event)}${doc}`.slice(0, 190);
    };

    const listLabels = (labels) => {
        if (labels.length <= 1) {
            return labels.join('');
        }
        return `${labels.slice(0, -1).join(', ')} și ${labels[labels.length - 1]}`;
    };

    /* ---------- Pasul 1: categorii + evenimente ---------- */

    const showCategory = (entity, focus = false) => {
        $$('[data-nrf-category]').forEach((card) => {
            const isCurrent = card.dataset.nrfCategory === entity;
            card.classList.toggle('is-current', isCurrent);
            card.setAttribute('aria-selected', isCurrent ? 'true' : 'false');
            card.tabIndex = isCurrent ? 0 : -1;
            if (isCurrent && focus) {
                card.focus();
            }
        });
        $$('[data-nrf-entity]').forEach((panel) => {
            panel.hidden = panel.dataset.nrfEntity !== entity;
        });
    };

    const syncCategories = () => {
        $$('[data-nrf-category]').forEach((card) => {
            const entity = card.dataset.nrfCategory;
            const panel = $(`[data-nrf-entity="${entity}"]`);
            const checked = panel ? $$('[data-nrf-event]:checked', panel).length : 0;
            const total = Number(card.dataset.total || 0);
            card.classList.toggle('is-selected', checked > 0);
            const counter = $('[data-nrf-entity-count]', card);
            if (counter) {
                counter.textContent = checked > 0
                    ? `${checked} din ${total} ${checked === 1 ? 'selectat' : 'selectate'}`
                    : plural(total, 'eveniment', 'evenimente');
            }
        });
    };

    /* ---------- Pasul 2: conditii ---------- */

    const syncConditions = (events, items) => {
        const visible = {
            days: usesCondition(events, 'days').length > 0,
            km: events.includes('tire_km_limit'),
            tread: events.includes('tire_tread_depth'),
            revizie: usesCondition(events, 'revizie').length > 0,
            report: usesCondition(events, 'report').length > 0,
            approval: events.includes(approvalEvent),
        };
        families.forEach((family) => {
            visible[`docs-${family}`] = familyUsed(events, family);
        });

        let anyVisible = false;
        Object.entries(visible).forEach(([key, isVisible]) => {
            const block = $(`[data-nrf-cond="${key}"]`);
            if (block) {
                block.hidden = !isVisible;
                anyVisible = anyVisible || isVisible;
            }
        });
        families.forEach((family) => {
            const picker = $(`[data-nrf-doc-picker="${family}"]`);
            if (picker) {
                picker.hidden = !visible[`docs-${family}`] || familyAll(family);
            }
        });

        const empty = $('[data-nrf-conditions-empty]');
        if (empty) {
            empty.hidden = anyVisible;
            empty.textContent = events.length === 0
                ? 'Bifează un eveniment la pasul 1 ca să vezi condițiile lui.'
                : 'Evenimentele alese nu au condiții suplimentare.';
        }

        const title = $('[data-nrf-cond-title]');
        if (title) {
            const labels = events.map(eventUiLabel);
            title.textContent = labels.length === 0
                ? 'Condiții'
                : `Condiții ${labels.length <= 2 ? labels.join(' și ') : `pentru ${labels.length} evenimente`}`;
        }

        const badge = $('[data-nrf-cond-badge]');
        if (badge) {
            badge.hidden = items.length === 0;
            badge.textContent = items.length === 1
                ? 'Se va crea 1 regulă din selecțiile curente'
                : `Se vor crea ${items.length} reguli din selecțiile curente`;
        }

        const daysFor = $('[data-nrf-days-for]');
        if (daysFor) {
            const labels = usesCondition(events, 'days').map(eventServerLabel);
            daysFor.textContent = labels.length > 1 ? `Se aplică pentru: ${labels.join(', ')}.` : '';
        }

        const days = String(form.elements.days_before?.value || '');
        $$('[data-nrf-days-preset]').forEach((chip) => {
            chip.classList.toggle('is-active', chip.dataset.nrfDaysPreset === days);
            chip.setAttribute('aria-pressed', chip.dataset.nrfDaysPreset === days ? 'true' : 'false');
        });
    };

    /* ---------- Pasul 3: canal + destinatari ---------- */

    const syncRecipients = (events) => {
        const currentChannel = channel();
        const isSms = smsChannels.includes(currentChannel);
        const driverOption = $('[data-nrf-driver-option]');
        if (driverOption) {
            const onlyApproval = events.length > 0 && events.every((event) => event === approvalEvent);
            driverOption.hidden = !isSms || onlyApproval || recipientMode() === 'drivers';
        }

        const users = $('[data-nrf-users]');
        if (users) {
            users.hidden = recipientMode() !== 'specific_users';
            users.classList.toggle('needs-phone', isSms);
            users.classList.toggle('needs-email', currentChannel !== 'sms');
        }

        const count = $('[data-nrf-user-count]');
        if (count) {
            const checked = $$('[data-nrf-user] input:checked').length;
            count.textContent = `${checked} ${checked === 1 ? 'selectat' : 'selectați'}`;
        }

        const driverPanel = $('[data-nrf-drivers]');
        if (driverPanel) {
            driverPanel.hidden = recipientMode() !== 'drivers';
            const picked = selectedDriverNames().length;
            const driverCount = $('[data-nrf-driver-count]');
            if (driverCount) {
                driverCount.textContent = `${picked} ${picked === 1 ? 'ales' : 'aleși'}`;
            }
            const intro = $('[data-nrf-driver-intro]');
            if (intro) {
                intro.textContent = picked === 0
                    ? 'Niciun șofer ales: SMS-ul merge la șoferii alocați vehiculului (sau șoferului vizat). Alege șoferi ca să primească ei mereu mesajul, indiferent de vehicul.'
                    : `Doar ${picked === 1 ? 'șoferul ales primește' : `cei ${picked} șoferi aleși primesc`} SMS-ul, pentru fiecare vehicul / document care declanșează regula.`;
            }
        }
    };

    const selectedDriverNames = () => $$('[data-nrf-driver]')
        .filter((row) => $('input', row).checked)
        .map((row) => row.dataset.name || '');

    const selectedUserNames = () => $$('[data-nrf-user]')
        .filter((row) => $('input', row).checked)
        .map((row) => row.dataset.name || '');

    const recipientsText = () => {
        const mode = recipientMode();
        let text = 'Administratori';
        if (mode === 'specific_users') {
            const names = selectedUserNames();
            text = names.length === 0
                ? 'Utilizatori selectați (niciunul încă)'
                : (names.length <= 3 ? listLabels(names) : `${names.slice(0, 2).join(', ')} și încă ${names.length - 2}`);
        } else if (mode === 'drivers') {
            const names = selectedDriverNames();
            text = names.length === 0
                ? 'Doar șoferul (SMS) — șoferii alocați vehiculului'
                : `Șoferii aleși (SMS): ${names.length <= 3 ? listLabels(names) : `${names.slice(0, 2).join(', ')} și încă ${names.length - 2}`}`;
        } else if (mode === 'vehicle_responsible') {
            text = 'Responsabil vehicul';
        }
        const driverOption = $('[data-nrf-driver-option]');
        if (driverOption && !driverOption.hidden && form.elements.notify_driver?.checked) {
            text += ' + șoferul (SMS)';
        }
        return text;
    };

    /* ---------- Rezumat ---------- */

    const warningsFor = (events, items) => {
        const warnings = [];
        const currentChannel = channel();
        const mode = recipientMode();

        if (items.length > maxRules) {
            warnings.push(`Selecția ar crea ${items.length} reguli (maxim ${maxRules}).`);
        }
        if (mode === 'drivers' && currentChannel !== 'sms') {
            warnings.push('„Doar șoferul” funcționează doar pe SMS.');
        }
        if (mode === 'drivers' && events.includes(approvalEvent)) {
            warnings.push('Cererile de aprobare nu au un șofer destinatar.');
        }
        const hasReport = usesCondition(events, 'report').length > 0;
        if (hasReport && mode === 'drivers') {
            warnings.push('Raportul săptămânal se trimite pe email: alege Administratori sau Utilizatori selectați.');
        }
        if (hasReport && currentChannel === 'sms') {
            warnings.push('Raportul săptămânal pleacă doar pe email (canalul SMS se aplică celorlalte evenimente).');
        }
        if (mode === 'drivers') {
            const noPhone = $$('[data-nrf-driver]').filter((row) => $('input', row).checked && row.dataset.hasPhone !== '1').length;
            if (noPhone > 0) {
                warnings.push(`${noPhone} ${noPhone === 1 ? 'șofer ales nu are' : 'șoferi aleși nu au'} telefon și nu vor primi SMS.`);
            }
        }
        if (mode === 'specific_users') {
            const rows = $$('[data-nrf-user]').filter((row) => $('input', row).checked);
            if (rows.length === 0) {
                warnings.push('Bifează cel puțin un utilizator.');
            }
            if (smsChannels.includes(currentChannel)) {
                const noPhone = rows.filter((row) => row.dataset.hasPhone !== '1').length;
                if (noPhone > 0) {
                    warnings.push(`${noPhone} ${noPhone === 1 ? 'utilizator' : 'utilizatori'} fără telefon nu vor primi SMS.`);
                }
            }
            if (currentChannel !== 'sms') {
                const noEmail = rows.filter((row) => row.dataset.hasEmail !== '1').length;
                if (noEmail > 0) {
                    warnings.push(`${noEmail} ${noEmail === 1 ? 'utilizator' : 'utilizatori'} fără email nu vor primi email.`);
                }
            }
        }
        families.forEach((family) => {
            if (familyUsed(events, family) && !familyAll(family) && familyDocs(family).length === 0) {
                warnings.push(`${familyLabels[family]}: ai ales „Doar anumite tipuri”, dar n-ai bifat niciunul.`);
            }
        });
        if (events.includes('tire_km_limit') && !(Number(form.elements.threshold_km?.value) > 0)) {
            warnings.push('Completează pragul de kilometri pentru anvelope.');
        }
        if (events.includes('tire_tread_depth') && !(Number(String(form.elements.threshold_tread_depth?.value || '').replace(',', '.')) > 0)) {
            warnings.push('Completează pragul de profil pentru anvelope.');
        }
        if (usesCondition(events, 'days').length > 0 && Number(form.elements.days_before?.value) > 365) {
            warnings.push('Alege cel mult 365 de zile.');
        }

        return warnings;
    };

    const conditionText = (events) => {
        const parts = [];
        if (usesCondition(events, 'days').length > 0) {
            parts.push(`cu ${form.elements.days_before?.value || 0} zile înainte`);
        }
        if (events.includes('tire_km_limit')) {
            parts.push(`prag ${form.elements.threshold_km?.value || '—'} km`);
        }
        if (events.includes('tire_tread_depth')) {
            parts.push(`profil ≤ ${form.elements.threshold_tread_depth?.value || '—'} mm`);
        }
        if (usesCondition(events, 'revizie').length > 0) {
            const value = form.elements.revizie_status?.value || '';
            parts.push(`Revizii: ${revizieStatus[value] || value}`);
        }
        if (usesCondition(events, 'report').length > 0) {
            const day = form.elements.report_day;
            const hour = form.elements.report_hour;
            parts.push(`raport săptămânal ${day?.selectedOptions[0]?.textContent.trim() || ''}, ora ${hour?.selectedOptions[0]?.textContent.trim() || ''}`);
        }
        if (events.includes(approvalEvent)) {
            const types = $$('input[name="approval_resource_types[]"]:checked').map((input) => input.nextElementSibling?.textContent.trim());
            parts.push(`aprobări: ${types.length ? types.join(', ') : 'toate tipurile'}`);
        }
        return parts.join('; ');
    };

    const documentsText = (events) => families
        .filter((family) => familyUsed(events, family))
        .map((family) => {
            const docs = familyAll(family) ? [] : familyDocs(family);
            return `${familyLabels[family]}: ${docs.length ? docs.join(', ') : 'toate tipurile'} (doar active)`;
        })
        .join('; ');

    const renderChecklist = (events) => {
        const list = $('[data-nrf-checklist]');
        if (!list) {
            return;
        }
        const multiCategory = new Set(events.map((event) => eventEntity[event])).size > 1;
        const rows = [];
        if (events.length === 0) {
            rows.push(['is-muted', 'Evenimente', 'niciunul selectat']);
        } else {
            rows.push(['', 'Categorie', [...new Set(events.map((event) => entityUiLabel(eventEntity[event])))].join(', ')]);
            rows.push(['', 'Evenimente', events.map((event) => (multiCategory
                ? `${eventUiLabel(event)} (${entityUiLabel(eventEntity[event])})`
                : eventUiLabel(event))).join(', ')]);
            const condition = conditionText(events);
            if (condition) {
                rows.push(['', 'Condiție', condition]);
            }
            const documents = documentsText(events);
            if (documents) {
                rows.push(['', 'Documente', documents]);
            }
        }
        rows.push(['', 'Canal', ($('[data-nrf-channel]:checked + span') || {}).textContent?.trim() || 'Email']);
        rows.push([recipientMode() === 'specific_users' && selectedUserNames().length === 0 ? 'is-warn' : '', 'Destinatari', recipientsText()]);
        const options = $$('[data-nrf-option]').filter((input) => input.checked).map((input) => input.dataset.nrfOption);
        rows.push([form.elements.enabled?.checked ? '' : 'is-warn', 'Opțiuni', options.length ? options.join(', ') : 'inactivă, fără repetare']);

        list.innerHTML = rows.map(([state, label, value]) => {
            const icon = state === 'is-muted' ? 'bi-dash-circle' : (state === 'is-warn' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill');
            return `<li class="${state}"><i class="bi ${icon}" aria-hidden="true"></i><span><b>${escapeHtml(label)}:</b> ${escapeHtml(value)}</span></li>`;
        }).join('');
    };

    const countText = (items) => {
        if (config.isEditing && Number(config.groupSize) > 0 && Number(config.groupSize) !== items.length) {
            return `Grupul va avea ${plural(items.length, 'regulă', 'reguli')} (acum ${config.groupSize}). Regulile debifate se șterg.`;
        }
        if (config.isEditing) {
            return items.length === 1 ? 'Se va salva 1 regulă din selecțiile curente.' : `Se vor salva ${items.length} reguli din selecțiile curente.`;
        }
        return items.length === 1 ? 'Se va crea 1 regulă din selecțiile curente.' : `Se vor crea ${items.length} reguli din selecțiile curente.`;
    };

    const renderSummary = (events, items) => {
        const nameInput = $('[data-nrf-name]');
        const suggestion = suggestName(events);
        if (nameInput) {
            nameInput.placeholder = suggestion || 'Ex: Documente vehicul';
        }

        const badge = $('[data-nrf-count-badge]');
        if (badge) {
            badge.textContent = plural(items.length, 'regulă', 'reguli');
            badge.classList.toggle('is-empty', items.length === 0);
        }

        renderChecklist(events);

        const warnings = warningsFor(events, items);
        const status = $('[data-nrf-status]');
        const state = events.length === 0 ? 'is-neutral' : (warnings.length > 0 ? 'is-warning' : 'is-valid');
        if (status) {
            status.classList.remove('is-neutral', 'is-warning', 'is-valid');
            status.classList.add(state);
            const icon = $('[data-nrf-status-icon]', status);
            if (icon) {
                icon.className = `bi ${state === 'is-valid' ? 'bi-check-circle-fill' : (state === 'is-warning' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill')}`;
            }
            const titleEl = $('[data-nrf-status-title]', status);
            if (titleEl) {
                titleEl.textContent = state === 'is-valid'
                    ? (items.length === 1 ? 'Regulă validă' : 'Reguli valide')
                    : (state === 'is-warning' ? 'Verifică selecția' : 'Selectează un eveniment');
            }
            const count = $('[data-nrf-count]', status);
            if (count) {
                count.textContent = events.length === 0 ? 'Bifează cel puțin un eveniment la pasul 1.' : countText(items);
            }
            const warningList = $('[data-nrf-warnings]', status);
            if (warningList) {
                warningList.innerHTML = warnings.map((text) => `<li>${escapeHtml(text)}</li>`).join('');
            }
        }

        const base = baseName(events);
        const isGroup = items.length > 1;
        const preview = $('[data-nrf-name-preview]');
        if (preview) {
            preview.textContent = events.length === 0 ? '—' : itemName(items[0], base, isGroup) + (isGroup ? ' …' : '');
        }

        const previewList = $('[data-nrf-preview]');
        if (previewList) {
            previewList.innerHTML = items.map((item) => `<li>${escapeHtml(itemName(item, base, isGroup))}</li>`).join('');
        }
        const toggle = $('[data-nrf-preview-toggle]');
        if (toggle) {
            toggle.hidden = !isGroup;
            toggle.textContent = `Vezi toate cele ${items.length} reguli`;
            if (!isGroup) {
                setPreviewOpen(false);
            }
        }

        const submitLabel = $('[data-nrf-submit-label]');
        if (submitLabel) {
            submitLabel.textContent = items.length > 1 ? `Salvează ${items.length} reguli` : 'Salvează';
        }
    };

    const update = () => {
        const events = selectedEvents();
        const items = buildItems(events);
        syncCategories();
        syncConditions(events, items);
        syncRecipients(events);
        renderSummary(events, items);
    };

    /* ---------- Interactiuni ---------- */

    let dirty = false;

    const setPreviewOpen = (open) => {
        const pop = $('[data-nrf-preview-pop]');
        const toggle = $('[data-nrf-preview-toggle]');
        if (pop) {
            pop.hidden = !open;
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    };

    // Trimite raportul acum (setarile din formular, fara salvare) si arata rezultatul pe loc.
    const sendReportTest = async (button) => {
        const box = $('[data-nrf-report-result]');
        const showResult = (state, html) => {
            if (box) {
                box.hidden = false;
                box.className = `nrf-report-result ${state}`;
                box.innerHTML = html;
            }
        };
        button.disabled = true;
        const label = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Se trimite…';
        try {
            const response = await fetch(button.dataset.url, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.sent && data.sent.length) {
                showResult(data.ok ? 'is-ok' : 'is-warn',
                    `<i class="bi bi-check-circle-fill" aria-hidden="true"></i> Raport trimis către ${escapeHtml(data.sent.join(', '))}.`
                    + (data.failed && data.failed.length ? `<br>Eșuat: ${escapeHtml(data.failed.join('; '))}` : ''));
            } else {
                showResult('is-error', `<i class="bi bi-x-circle-fill" aria-hidden="true"></i> ${escapeHtml(data.error || (data.failed || []).join('; ') || 'Trimiterea a eșuat.')}`);
            }
        } catch (error) {
            showResult('is-error', '<i class="bi bi-x-circle-fill" aria-hidden="true"></i> Trimiterea a eșuat (eroare de rețea sau server).');
        } finally {
            button.disabled = false;
            button.innerHTML = label;
        }
    };

    const addDocType = (family) => {
        const input = $(`[data-nrf-doc-new="${family}"]`);
        const list = $(`[data-nrf-doc-list="${family}"]`);
        const value = (input?.value || '').trim();
        if (!input || !list || value === '') {
            return;
        }
        const existing = $$(`input[name="doc_types[${family}][]"]`, list)
            .find((checkbox) => checkbox.value.toLowerCase() === value.toLowerCase());
        if (existing) {
            existing.checked = true;
        } else {
            const label = document.createElement('label');
            label.className = 'nrf-chip';
            label.innerHTML = `<input type="checkbox" name="doc_types[${family}][]" value="${escapeHtml(value)}" checked><span>${escapeHtml(value)}</span>`;
            list.appendChild(label);
        }
        input.value = '';
        dirty = true;
        update();
    };

    form.addEventListener('change', (event) => {
        dirty = true;
        // „Doar soferul” are sens doar pe SMS: comuta canalul automat.
        if (event.target.matches('[data-nrf-recipient]') && event.target.value === 'drivers') {
            const sms = $('[data-nrf-channel][value="sms"]');
            if (sms) {
                sms.checked = true;
            }
        }
        update();
    });

    form.addEventListener('input', (event) => {
        dirty = true;
        if (event.target.matches('[data-nrf-user-search], [data-nrf-driver-search]')) {
            const query = event.target.value.trim().toLowerCase();
            const rows = event.target.matches('[data-nrf-driver-search]') ? '[data-nrf-driver]' : '[data-nrf-user]';
            $$(rows).forEach((row) => {
                row.hidden = query !== '' && !(row.dataset.search || '').includes(query);
            });
            return;
        }
        update();
    });

    form.addEventListener('click', (event) => {
        const category = event.target.closest('[data-nrf-category]');
        if (category) {
            showCategory(category.dataset.nrfCategory);
            return;
        }

        const preset = event.target.closest('[data-nrf-days-preset]');
        if (preset) {
            form.elements.days_before.value = preset.dataset.nrfDaysPreset;
            dirty = true;
            update();
            return;
        }

        if (event.target.closest('[data-nrf-generate-name]')) {
            const suggestion = suggestName(selectedEvents());
            if (suggestion && form.elements.name) {
                form.elements.name.value = suggestion;
                dirty = true;
                update();
            }
            form.elements.name?.focus();
            return;
        }

        // Excel cu setarile din formular (acelasi link ca butonul din email); descarcarea nu paraseste pagina.
        const reportExcel = event.target.closest('[data-nrf-report-excel]');
        if (reportExcel) {
            const url = new URL(reportExcel.dataset.url, window.location.href);
            const categories = $$('input[name="report_categories[]"]:checked').map((input) => input.value);
            if (categories.length) {
                url.searchParams.set('cat', categories.join(','));
            }
            if ((form.elements.revizie_status?.value || '') === 'necesita') {
                url.searchParams.set('status', 'necesita');
            }
            if (form.elements.report_history?.checked) {
                url.searchParams.set('history', '1');
            }
            window.location.href = url.toString();
            return;
        }

        const reportSend = event.target.closest('[data-nrf-report-send]');
        if (reportSend) {
            sendReportTest(reportSend);
            return;
        }

        const addButton = event.target.closest('[data-nrf-doc-add]');
        if (addButton) {
            addDocType(addButton.dataset.nrfDocAdd);
            return;
        }

        const condToggle = event.target.closest('[data-nrf-cond-toggle]');
        if (condToggle) {
            const body = $('[data-nrf-cond-body]');
            const open = condToggle.getAttribute('aria-expanded') !== 'true';
            condToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            condToggle.setAttribute('aria-label', open ? 'Restrânge condițiile' : 'Extinde condițiile');
            $('[data-nrf-cond-card]')?.classList.toggle('is-collapsed', !open);
            if (body) {
                body.hidden = !open;
            }
            return;
        }

        const detailsToggle = event.target.closest('[data-nrf-details-toggle]');
        if (detailsToggle) {
            const summary = $('[data-nrf-summary]');
            const open = !summary.classList.contains('is-details-open');
            summary.classList.toggle('is-details-open', open);
            detailsToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            return;
        }

        if (event.target.closest('[data-nrf-preview-toggle]')) {
            setPreviewOpen($('[data-nrf-preview-pop]')?.hidden !== false);
            return;
        }

        const all = event.target.closest('[data-nrf-users-all], [data-nrf-users-none], [data-nrf-drivers-all], [data-nrf-drivers-none]');
        if (all) {
            const check = all.hasAttribute('data-nrf-users-all') || all.hasAttribute('data-nrf-drivers-all');
            const rows = all.closest('[data-nrf-drivers]') ? '[data-nrf-driver]' : '[data-nrf-user]';
            $$(rows).filter((row) => !row.hidden).forEach((row) => {
                $('input', row).checked = check;
            });
            dirty = true;
            update();
        }

        if (!event.target.closest('[data-nrf-preview-pop], [data-nrf-preview-toggle]')) {
            setPreviewOpen(false);
        }
    });

    // Sagetile stanga/dreapta muta selectia intre categorii (tablist).
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('[data-nrf-doc-new]')) {
            event.preventDefault();
            addDocType(event.target.dataset.nrfDocNew);
            return;
        }
        const category = event.target.closest('[data-nrf-category]');
        if (category && ['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const cards = $$('[data-nrf-category]');
            const index = cards.indexOf(category);
            let next = index;
            if (event.key === 'ArrowRight') next = (index + 1) % cards.length;
            if (event.key === 'ArrowLeft') next = (index - 1 + cards.length) % cards.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = cards.length - 1;
            showCategory(cards[next].dataset.nrfCategory, true);
        }
    });

    const flash = (element) => {
        if (!element) {
            return;
        }
        element.classList.remove('is-shake');
        void element.offsetWidth;
        element.classList.add('is-shake');
        window.setTimeout(() => element.classList.remove('is-shake'), 600);
    };

    form.addEventListener('submit', (event) => {
        // Previzualizarea raportului se deschide in tab nou: formularul ramane deschis, deci
        // garda globala anti-dublu-submit din app.js nu trebuie sa blocheze butoanele.
        if (event.submitter && event.submitter.hasAttribute('data-nrf-report-preview')) {
            event.stopPropagation();
            return;
        }
        if (selectedEvents().length > 0) {
            dirty = false;
            return;
        }
        // stopPropagation: altfel garda globala anti-dublu-submit din app.js blocheaza butonul Salveaza.
        event.preventDefault();
        event.stopPropagation();
        const current = $('[data-nrf-category].is-current') || $('[data-nrf-category]');
        current?.closest('.nrf-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        current?.focus({ preventScroll: true });
        flash($('[data-nrf-status]'));
    });

    /* ---------- Modal: scroll lock, focus, inchidere ---------- */

    document.documentElement.classList.add('nrf-open');
    window.addEventListener('pagehide', () => document.documentElement.classList.remove('nrf-open'));

    const hint = document.createElement('div');
    hint.className = 'nrf-hint';
    hint.setAttribute('role', 'status');
    hint.hidden = true;
    modal.appendChild(hint);
    let closeArmedUntil = 0;
    let hintTimer = 0;

    const close = () => {
        dirty = false;
        if (config.closeUrl) {
            window.location.href = config.closeUrl;
        }
    };

    // Modificari nesalvate: Esc / click pe fundal cer o a doua confirmare (fara dialog blocant).
    const requestClose = () => {
        if (!dirty || Date.now() < closeArmedUntil) {
            close();
            return;
        }
        closeArmedUntil = Date.now() + 3000;
        hint.textContent = 'Ai modificări nesalvate. Apasă din nou pentru a închide fără salvare.';
        hint.hidden = false;
        window.clearTimeout(hintTimer);
        hintTimer = window.setTimeout(() => {
            hint.hidden = true;
        }, 3000);
    };

    document.querySelector('[data-nrf-backdrop]')?.addEventListener('click', requestClose);

    const focusables = () => Array.from(modal.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )).filter((element) => element.getClientRects().length > 0 && !element.closest('[hidden]'));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Tab') {
            const items = focusables();
            if (items.length === 0) {
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (!modal.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
            return;
        }

        if (event.key !== 'Escape') {
            return;
        }
        if ($('[data-nrf-preview-pop]') && !$('[data-nrf-preview-pop]').hidden) {
            setPreviewOpen(false);
            return;
        }
        // In campurile de text, Esc doar paraseste campul (nu inchide formularul).
        const active = document.activeElement;
        if (active && modal.contains(active) && active.matches('input[type="text"], input[type="search"], input[type="number"], input:not([type]), textarea, select')) {
            active.blur();
            modal.querySelector('#nrfTitle')?.focus({ preventScroll: true });
            return;
        }
        requestClose();
    });

    window.addEventListener('beforeunload', () => {
        document.documentElement.classList.remove('nrf-open');
    });

    update();

    const firstError = $('.nrf-error');
    if (firstError) {
        // Daca eroarea e intr-o categorie ascunsa, nu e cazul: erorile de eveniment sunt sub taburi.
        firstError.scrollIntoView({ block: 'center' });
        const field = firstError.closest('.nrf-field, .nrf-section, .nrf-name, .nrf-delivery-col, .nrf-users');
        const control = field?.querySelector('input:not([type="hidden"]):not([disabled]), select');
        (control || modal.querySelector('#nrfTitle'))?.focus({ preventScroll: true });
    } else if (config.isEditing) {
        modal.querySelector('#nrfTitle')?.focus({ preventScroll: true });
    } else {
        form.elements.name?.focus({ preventScroll: true });
    }
})();

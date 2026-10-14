/**
 * Formularul de regula din page=notificari: mai multe evenimente / tipuri de document
 * intr-o singura salvare. Rezumatul din dreapta arata regulile care se vor crea,
 * calculate la fel ca in NotificationRuleController::validateRuleInput().
 */
(() => {
    const form = document.querySelector('[data-nrf-form]');
    if (!form) {
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
    const eventEntity = config.eventEntity || {};
    const smsChannels = config.smsChannels || ['sms', 'email_sms'];
    const approvalEvent = config.approvalEvent || 'inactive_approval_pending';
    const maxRules = Number(config.maxRules || 60);
    const families = ['vehicle', 'driver', 'equipment'];

    const $ = (selector, root = form) => root.querySelector(selector);
    const $$ = (selector, root = form) => Array.from(root.querySelectorAll(selector));

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[ch]));

    const selectedEvents = () => $$('[data-nrf-event]:checked').map((input) => input.value);
    const channel = () => ($('[data-nrf-channel]:checked') || {}).value || 'email';
    const recipientMode = () => ($('[data-nrf-recipient]:checked') || {}).value || 'admins';
    const familyAll = (family) => ($(`[data-nrf-doc-all="${family}"]:checked`) || {}).value !== '0';
    const familyDocs = (family) => $$(`input[name="doc_types[${family}][]"]:checked`).map((input) => input.value);
    const eventDisplay = (event) => `${entityLabels[eventEntity[event]] || ''}: ${eventLabels[event] || event}`;
    const usesCondition = (events, key) => events.filter((event) => conditions[event] && conditions[event][key]);

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
            return 'Ex: Documente vehicul';
        }
        return events.map((event) => {
            const family = (conditions[event] || {}).docs || '';
            const docs = family && !familyAll(family) ? familyDocs(family) : [];
            return docs.length > 0 && docs.length <= 3
                ? `${eventDisplay(event)} ${docs.join(', ')}`
                : eventDisplay(event);
        }).join(' + ').slice(0, 150);
    };

    const listLabels = (labels) => {
        if (labels.length <= 1) {
            return labels.join('');
        }
        return `${labels.slice(0, -1).join(', ')} și ${labels[labels.length - 1]}`;
    };

    const syncEntities = () => {
        $$('[data-nrf-entity]').forEach((block) => {
            const count = $$('[data-nrf-event]:checked', block).length;
            block.classList.toggle('is-active', count > 0);
            const badge = $('[data-nrf-entity-count]', block);
            if (badge) {
                badge.textContent = count > 0 ? `${count} bifat${count === 1 ? '' : 'e'}` : '';
            }
        });
    };

    const syncConditions = (events) => {
        const visible = {
            days: usesCondition(events, 'days').length > 0,
            km: events.includes('tire_km_limit'),
            tread: events.includes('tire_tread_depth'),
            revizie: usesCondition(events, 'revizie').length > 0,
            approval: events.includes(approvalEvent),
        };
        families.forEach((family) => {
            visible[`docs-${family}`] = events.some((event) => (conditions[event] || {}).docs === family);
        });

        let anyVisible = false;
        Object.entries(visible).forEach(([key, isVisible]) => {
            const block = $(`[data-nrf-cond="${key}"]`);
            if (block) {
                block.hidden = !isVisible;
                anyVisible = anyVisible || isVisible;
            }
        });

        const empty = $('[data-nrf-conditions-empty]');
        if (empty) {
            empty.hidden = anyVisible;
            empty.textContent = events.length === 0
                ? 'Bifează un eveniment mai sus ca să vezi condițiile lui.'
                : 'Evenimentele alese nu au condiții suplimentare.';
        }

        const daysFor = $('[data-nrf-days-for]');
        if (daysFor) {
            const labels = usesCondition(events, 'days').map(eventDisplay);
            daysFor.textContent = labels.length > 1 ? `Se aplică pentru: ${labels.join(', ')}.` : '';
        }

        const days = String(($('[data-nrf-days]') || {}).value || '');
        $$('[data-nrf-days-preset]').forEach((chip) => {
            chip.classList.toggle('is-active', chip.dataset.nrfDaysPreset === days);
        });

        families.forEach((family) => {
            const picker = $(`[data-nrf-doc-picker="${family}"]`);
            if (picker) {
                picker.hidden = familyAll(family);
            }
        });
    };

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
            count.textContent = `${checked} selectat${checked === 1 ? '' : 'i'}`;
        }
    };

    const recipientsText = () => {
        const mode = recipientMode();
        if (mode === 'specific_users') {
            const names = $$('[data-nrf-user]').filter((row) => $('input', row).checked)
                .map((row) => ($('.nrf-user-text strong', row)?.firstChild?.textContent || '').trim());
            if (names.length === 0) {
                return 'utilizatorii selectați';
            }
            return names.length <= 3 ? listLabels(names) : `${names.slice(0, 2).join(', ')} și încă ${names.length - 2}`;
        }
        if (mode === 'drivers') {
            return 'șoferul vizat';
        }
        return 'administratori';
    };

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
        if (mode === 'specific_users') {
            const rows = $$('[data-nrf-user]').filter((row) => $('input', row).checked);
            if (rows.length === 0) {
                warnings.push('Bifează cel puțin un utilizator.');
            }
            if (smsChannels.includes(currentChannel)) {
                const noPhone = rows.filter((row) => row.dataset.hasPhone !== '1').length;
                if (noPhone > 0) {
                    warnings.push(`${noPhone} utilizator${noPhone === 1 ? '' : 'i'} fără telefon nu vor primi SMS.`);
                }
            }
            if (currentChannel !== 'sms') {
                const noEmail = rows.filter((row) => row.dataset.hasEmail !== '1').length;
                if (noEmail > 0) {
                    warnings.push(`${noEmail} utilizator${noEmail === 1 ? '' : 'i'} fără email nu vor primi email.`);
                }
            }
        }
        families.forEach((family) => {
            const used = events.some((event) => (conditions[event] || {}).docs === family);
            if (used && !familyAll(family) && familyDocs(family).length === 0) {
                warnings.push('Ai ales „Doar anumite” tipuri de document, dar n-ai bifat niciunul.');
            }
        });
        if (events.includes('tire_km_limit') && !(Number(form.elements.threshold_km?.value) > 0)) {
            warnings.push('Completează pragul de kilometri pentru anvelope.');
        }
        if (events.includes('tire_tread_depth') && !(Number(String(form.elements.threshold_tread_depth?.value || '').replace(',', '.')) > 0)) {
            warnings.push('Completează pragul de profil pentru anvelope.');
        }

        return warnings;
    };

    const syncSummary = (events) => {
        const items = buildItems(events);
        const channelLabel = ($('[data-nrf-channel]:checked + span') || {}).textContent || 'Email';

        const nameInput = $('[data-nrf-name]');
        if (nameInput) {
            nameInput.placeholder = suggestName(events);
        }

        const sentence = $('[data-nrf-sentence]');
        if (sentence) {
            if (events.length === 0) {
                sentence.innerHTML = 'Bifează cel puțin un eveniment ca să construiești regula.';
            } else {
                const parts = [];
                const daysEvents = usesCondition(events, 'days');
                if (daysEvents.length > 0) {
                    parts.push(`cu <strong>${escapeHtml(form.elements.days_before?.value || 0)} zile</strong> înainte`);
                }
                sentence.innerHTML = `Trimite <strong>${escapeHtml(channelLabel.trim())}</strong> către <strong>${escapeHtml(recipientsText())}</strong>`
                    + ` pentru <strong>${events.length} eveniment${events.length === 1 ? '' : 'e'}</strong>`
                    + (parts.length > 0 ? `, ${parts.join(', ')}` : '') + '.';
            }
        }

        const count = $('[data-nrf-count]');
        if (count) {
            if (items.length === 0) {
                count.textContent = '';
            } else if (config.isEditing && Number(config.groupSize) > 0 && Number(config.groupSize) !== items.length) {
                count.textContent = `Grupul va avea ${items.length} reguli (acum ${config.groupSize}). Regulile debifate se șterg.`;
            } else {
                count.textContent = items.length === 1 ? 'Se salvează 1 regulă:' : `Se salvează ${items.length} reguli:`;
            }
        }

        const preview = $('[data-nrf-preview]');
        if (preview) {
            const shown = items.slice(0, 12);
            preview.innerHTML = shown.map((item) => {
                const isRevizie = Boolean((conditions[item.event] || {}).revizie);
                const doc = item.doc && !isRevizie ? ` <span>· ${escapeHtml(item.doc)}</span>` : '';
                return `<li><em>${escapeHtml(entityLabels[eventEntity[item.event]] || '')}</em>${escapeHtml(eventLabels[item.event] || item.event)}${doc}</li>`;
            }).join('') + (items.length > shown.length ? `<li class="is-more">+ încă ${items.length - shown.length}</li>` : '');
        }

        const warnings = warningsFor(events, items);
        const warningBox = $('[data-nrf-warnings]');
        if (warningBox) {
            warningBox.innerHTML = warnings.map((text) => `<div><i class="bi bi-exclamation-triangle"></i> ${escapeHtml(text)}</div>`).join('');
        }

        const submitLabel = $('[data-nrf-submit-label]');
        if (submitLabel) {
            submitLabel.textContent = items.length > 1 ? `Salvează ${items.length} reguli` : 'Salvează';
        }
    };

    const update = () => {
        const events = selectedEvents();
        syncEntities();
        syncConditions(events);
        syncRecipients(events);
        syncSummary(events);
    };

    form.addEventListener('change', (event) => {
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
        if (event.target.matches('[data-nrf-user-search]')) {
            const query = event.target.value.trim().toLowerCase();
            $$('[data-nrf-user]').forEach((row) => {
                row.hidden = query !== '' && !(row.dataset.search || '').includes(query);
            });
            return;
        }
        update();
    });

    form.addEventListener('click', (event) => {
        const preset = event.target.closest('[data-nrf-days-preset]');
        if (preset) {
            form.elements.days_before.value = preset.dataset.nrfDaysPreset;
            update();
            return;
        }

        const addButton = event.target.closest('[data-nrf-doc-add]');
        if (addButton) {
            addDocType(addButton.dataset.nrfDocAdd);
            return;
        }

        const all = event.target.closest('[data-nrf-users-all], [data-nrf-users-none]');
        if (all) {
            const check = all.hasAttribute('data-nrf-users-all');
            $$('[data-nrf-user]').filter((row) => !row.hidden).forEach((row) => {
                $('input', row).checked = check;
            });
            update();
        }
    });

    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target.matches('[data-nrf-doc-new]')) {
            event.preventDefault();
            addDocType(event.target.dataset.nrfDocNew);
        }
    });

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
            label.className = 'nrf-chip-check';
            label.innerHTML = `<input type="checkbox" name="doc_types[${family}][]" value="${escapeHtml(value)}" checked><span>${escapeHtml(value)}</span>`;
            list.appendChild(label);
        }
        input.value = '';
        update();
    };

    form.addEventListener('submit', (event) => {
        if (selectedEvents().length === 0) {
            event.preventDefault();
            const first = $('[data-nrf-event]');
            first?.closest('.nrf-section')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            const sentence = $('[data-nrf-sentence]');
            sentence?.classList.add('is-shake');
            window.setTimeout(() => sentence?.classList.remove('is-shake'), 600);
        }
    });

    const close = () => {
        if (config.closeUrl) {
            window.location.href = config.closeUrl;
        }
    };
    document.querySelectorAll('[data-nrf-close]').forEach((element) => element.addEventListener('click', close));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });

    update();
    const firstError = $('.nrf-error');
    if (firstError) {
        firstError.scrollIntoView({ block: 'center' });
    }
})();

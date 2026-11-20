/*
 * Galeria pe categorii — clic pe un punct (sau o bulă) de pe siluetă deschide,
 * în aceeași secțiune, un panou cu toate produsele categoriei: fotografia mare,
 * denumirea, prețul, stocul și o bandă de miniaturi. Comună pentru Catalog
 * echipamente și pentru siluetele de persoană (unde produsele pe care persoana
 * le are primesc eticheta „La persoană”).
 *
 *   EquipmentGallery.open(host, catKey, { role, owned: Set<id>, accent })
 *   EquipmentGallery.close(host)
 *
 * Datele vin din #des-gallery-data (_gallery_data.php). Fără cereri la server.
 */
(function () {
    'use strict';

    const data = (function () {
        try {
            return JSON.parse((document.getElementById('des-gallery-data') || {}).textContent || '{}');
        } catch (error) {
            return {};
        }
    })();
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const nf2 = new Intl.NumberFormat('ro-RO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const nf0 = new Intl.NumberFormat('ro-RO', { maximumFractionDigits: 0 });
    let current = null; // { host, panel, items, index, owned, returnFocus }

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function close(host, immediate) {
        if (!current || (host && current.host !== host)) {
            return;
        }
        const state = current;
        current = null;
        const finish = function () {
            state.panel.remove();
            state.host.classList.remove('has-gallery');
            if (state.returnFocus && document.contains(state.returnFocus)) {
                state.returnFocus.focus({ preventScroll: true });
            }
        };
        if (immediate || reduceMotion.matches) {
            finish();
            return;
        }
        state.panel.classList.add('is-closing');
        window.setTimeout(finish, 180);
    }

    /** Caruselul: slide-ul activ e centrat, vecinii se văd mai mici pe margini. */
    function positionTrack() {
        if (!current) {
            return;
        }
        const viewport = current.panel.querySelector('.dgl-carousel');
        const track = current.panel.querySelector('.dgl-track');
        if (!viewport || !track) {
            return;
        }
        const slides = track.children;
        if (!slides.length) {
            return;
        }
        // Fereastra nu derulează niciodată singură (clic pe un slide pe jumătate vizibil).
        viewport.scrollLeft = 0;
        const slideW = slides[0].offsetWidth;
        const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
        const offset = viewport.clientWidth / 2 - (current.index * (slideW + gap) + slideW / 2);
        track.style.transform = 'translateX(' + offset + 'px)';
        Array.prototype.forEach.call(slides, function (slide, i) {
            const d = Math.abs(i - current.index);
            slide.classList.toggle('is-active', d === 0);
            slide.classList.toggle('is-near', d === 1);
            slide.setAttribute('aria-hidden', d === 0 ? 'false' : 'true');
        });
    }

    function show(index) {
        if (!current) {
            return;
        }
        const items = current.items;
        current.index = (index + items.length) % items.length;
        const item = items[current.index];
        const panel = current.panel;
        const owned = current.owned.has(item.id);

        positionTrack();

        const badges = [];
        if (owned) {
            badges.push('<span class="dgl-badge is-owned"><i class="bi bi-person-check" aria-hidden="true"></i>La persoană</span>');
        }
        if (!item.activ) {
            badges.push('<span class="dgl-badge is-muted">Inactiv</span>');
        }
        if (item.marime) {
            badges.push('<span class="dgl-badge">Pe mărimi</span>');
        }
        panel.querySelector('.dgl-info').innerHTML =
            '<strong>' + esc(item.nume) + '</strong>'
            + '<span class="dgl-meta">'
            + (item.pret !== null ? '<b>' + nf2.format(item.pret) + ' lei</b> / buc.' : '<em>preț nesetat</em>')
            + ' · <span class="' + (item.stoc > 0 ? 'is-ok' : 'is-out') + '">' + (item.stoc > 0 ? 'în stoc: ' + nf0.format(item.stoc) + ' buc.' : 'fără stoc') + '</span>'
            + '</span>'
            + (badges.length ? '<span class="dgl-badges">' + badges.join('') + '</span>' : '')
            + (current.actions.length
                ? '<span class="dgl-actions">' + current.actions.filter(function (a) { return !a.when || a.when(item); }).map(function (a) {
                    return '<button type="button" class="dgl-action' + (a.primary ? ' is-primary' : '') + '" data-dgl-action="' + esc(a.key) + '">'
                        + '<i class="bi ' + esc(a.icon) + '" aria-hidden="true"></i>' + esc(a.label) + '</button>';
                }).join('') + '</span>'
                : '');

        panel.querySelector('.dgl-count').textContent = (current.index + 1) + ' / ' + items.length;
        panel.querySelectorAll('.dgl-thumb').forEach(function (thumb, i) {
            const active = i === current.index;
            thumb.classList.toggle('is-active', active);
            thumb.setAttribute('aria-selected', active ? 'true' : 'false');
            if (active) {
                thumb.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
            }
        });
        panel.querySelectorAll('.dgl-nav').forEach(function (nav) { nav.hidden = items.length < 2; });
    }

    function open(host, catKey, opts) {
        opts = opts || {};
        const category = data[catKey];
        if (!host || !category) {
            return false;
        }
        close(null, true);

        const role = opts.role || '';
        const owned = opts.owned instanceof Set ? opts.owned : new Set();
        let items = category.items.filter(function (item) {
            return role === '' || item.dest === 'ambele' || item.dest === role || owned.has(item.id);
        });
        // Ce are persoana vine primul, apoi cele cu fotografie.
        items = items.slice().sort(function (a, b) {
            return (owned.has(b.id) - owned.has(a.id)) || ((b.url ? 1 : 0) - (a.url ? 1 : 0));
        });

        const panel = document.createElement('section');
        panel.className = 'dgl' + (opts.accent ? ' is-' + opts.accent : '');
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Produse: ' + category.name);
        panel.tabIndex = -1;
        const ownedCount = items.filter(function (i) { return owned.has(i.id); }).length;
        panel.innerHTML =
            '<header class="dgl-head">'
            + '<span class="dgl-ico"><i class="bi ' + esc(category.icon) + '" aria-hidden="true"></i></span>'
            + '<span class="dgl-title"><strong>' + esc(category.name) + '</strong>'
            + '<small>' + items.length + (items.length === 1 ? ' produs' : ' produse') + ' în catalog'
            + (ownedCount ? ' · ' + ownedCount + ' la persoană' : '') + '</small></span>'
            + '<span class="dgl-count"></span>'
            + '<button type="button" class="dgl-close" aria-label="Închide galeria"><i class="bi bi-x-lg" aria-hidden="true"></i></button>'
            + '</header>'
            + (items.length
                ? '<div class="dgl-view">'
                    + '<button type="button" class="dgl-nav is-prev" aria-label="Produsul anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>'
                    + '<div class="dgl-carousel"><div class="dgl-track">'
                    + items.map(function (item, i) {
                        return '<div class="dgl-slide" data-index="' + i + '">'
                            + (item.url
                                ? '<img src="' + esc(item.url) + '" alt="' + esc(item.nume) + '" draggable="false">'
                                : '<span class="dgl-noimg"><i class="bi ' + esc(category.icon) + '" aria-hidden="true"></i><small>Fără fotografie</small></span>')
                            + (owned.has(item.id) ? '<span class="dgl-slide-flag" title="La persoană"><i class="bi bi-person-check-fill" aria-hidden="true"></i></span>' : '')
                            + '</div>';
                    }).join('')
                    + '</div></div>'
                    + '<button type="button" class="dgl-nav is-next" aria-label="Produsul următor"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>'
                    + '</div>'
                    + '<div class="dgl-info"></div>'
                    + '<div class="dgl-thumbs" role="listbox" aria-label="Produse">'
                    + items.map(function (item, i) {
                        return '<button type="button" class="dgl-thumb' + (owned.has(item.id) ? ' is-owned' : '') + '" role="option" data-index="' + i + '" title="' + esc(item.nume) + '">'
                            + (item.url ? '<img src="' + esc(item.url) + '" alt="" loading="lazy">' : '<i class="bi ' + esc(category.icon) + '" aria-hidden="true"></i>')
                            + '</button>';
                    }).join('')
                    + '</div>'
                : '<p class="dgl-empty">Niciun produs din această categorie pentru '
                    + (role === 'tesa' ? 'TESA' : (role === 'sofer' ? 'șoferi' : 'acest filtru')) + '.</p>');

        panel.addEventListener('click', function (event) {
            event.stopPropagation();
            if (event.target.closest('.dgl-close')) {
                close(host);
                return;
            }
            const nav = event.target.closest('.dgl-nav');
            if (nav) {
                show(current.index + (nav.classList.contains('is-prev') ? -1 : 1));
                return;
            }
            const thumb = event.target.closest('.dgl-thumb, .dgl-slide');
            if (thumb) {
                if (Date.now() - lastSwipe < 300) {
                    return;
                }
                show(Number(thumb.getAttribute('data-index')));
                return;
            }
            const action = event.target.closest('[data-dgl-action]');
            if (action && typeof opts.onAction === 'function') {
                const item = current.items[current.index];
                const key = action.getAttribute('data-dgl-action');
                close(host, true);
                opts.onAction(key, item);
            }
        });

        // Glisare (deget / mouse) pe carusel.
        let startX = null;
        let lastSwipe = 0;
        panel.addEventListener('pointerdown', function (event) {
            if (event.target.closest('.dgl-carousel')) {
                startX = event.clientX;
            }
        });
        panel.addEventListener('pointerup', function (event) {
            if (startX === null || !current) {
                return;
            }
            const dx = event.clientX - startX;
            startX = null;
            if (Math.abs(dx) > 40 && current.items.length > 1) {
                lastSwipe = Date.now();
                show(current.index + (dx < 0 ? 1 : -1));
            }
        });

        host.classList.add('has-gallery');
        host.appendChild(panel);
        current = { host: host, panel: panel, items: items, index: 0, owned: owned, icon: category.icon, returnFocus: opts.returnFocus || null, actions: opts.actions || [] };
        if (items.length) {
            const start = opts.startId ? items.findIndex(function (i) { return i.id === opts.startId; }) : 0;
            show(start > 0 ? start : 0);
        }
        panel.focus({ preventScroll: true });
        // Pe ecrane mici panoul poate porni deasupra etichetei apăsate: îl aducem în vedere.
        const r = panel.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) {
            panel.scrollIntoView({ block: 'nearest', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
        }
        return true;
    }

    // Tastatură: ← / → între produse, Esc închide doar galeria (nu și cardul).
    document.addEventListener('keydown', function (event) {
        if (!current) {
            return;
        }
        if (event.key === 'Escape') {
            event.stopImmediatePropagation();
            event.preventDefault();
            close(current.host);
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            if (current.items.length > 1 && (current.panel.contains(document.activeElement) || document.activeElement === document.body)) {
                event.preventDefault();
                show(current.index + (event.key === 'ArrowLeft' ? -1 : 1));
            }
        }
    }, true);

    // Clic în afara panoului (în altă parte a paginii) îl închide.
    document.addEventListener('pointerdown', function (event) {
        if (current && !current.panel.contains(event.target) && !event.target.closest('.dcv-dot, .dcv-bubble, .dcx-dot')) {
            close(current.host);
        }
    });

    document.addEventListener('scroll', function (event) {
        if (event.target instanceof Element && event.target.classList.contains('dgl-carousel')) {
            event.target.scrollLeft = 0;
        }
    }, true);

    window.addEventListener('resize', function () {
        window.requestAnimationFrame(positionTrack);
    });

    window.EquipmentGallery = {
        open: open,
        close: close,
        has: function (key) { return Object.prototype.hasOwnProperty.call(data, key); },
        isOpen: function (host) { return !!current && (!host || current.host === host); },
    };
})();

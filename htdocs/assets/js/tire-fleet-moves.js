/*
 * Pagina Anvelope, schema anvelopelor: mutarea unei anvelope prin drag & drop si schimbul a doua
 * anvelope selectate. Aici sunt doar interactiunea si animatiile:
 *  - pozitiile verzi / rosii vin din server (action=tire_fleet_targets), sunt doar ajutor vizual;
 *  - mutarea / schimbul se verifica din nou si se salveaza in server (action=tire_fleet_move,
 *    TireModel::fleetMoveTire / fleetSwapTires, o singura tranzactie);
 *  - schema si tabelul se redeseneaza din datele serverului doar dupa o salvare reusita.
 * Pornit din tire-fleet.js prin window.FleetTireMoves.create(api).
 */
(function () {
    'use strict';

    var DRAG_THRESHOLD = 6; // px: sub prag e click (selectie), peste prag e drag
    var TARGETS_TTL = 60000;
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    var create = function (api) {
        var listEl = api.listEl;
        var busy = false;
        var press = null; // anvelopa apasata, inca sub prag
        var drag = null; // drag in curs
        var suppressClick = false;
        var targetsCache = {};
        var toastStack = null;

        /* ---------------------------------------------------------- Server */

        var readJson = function (response) {
            return response.json().catch(function () { return { ok: false, error: 'Răspuns invalid de la server (' + response.status + ').' }; });
        };
        var getJson = function (url) {
            return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(readJson);
        };
        var postJson = function (fields) {
            var body = new FormData();
            body.append('_token', api.csrf);
            Object.keys(fields).forEach(function (key) { body.append(key, String(fields[key])); });
            return window.fetch(api.moveUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(readJson)
                .then(function (data) {
                    if (!data || !data.ok) {
                        var error = new Error(data && data.error ? data.error : 'Operația nu a putut fi salvată.');
                        error.stale = !!(data && data.stale); // datele s-au schimbat intre timp: schema se reciteste
                        throw error;
                    }
                    return data;
                });
        };
        var withQuery = function (url, params) {
            return url + (url.indexOf('?') === -1 ? '?' : '&') + Object.keys(params).map(function (key) {
                return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            }).join('&');
        };

        // Pozitiile compatibile ale unei anvelope (cerute la apasare, inainte de prag).
        var loadTargets = function (unitKey, tireId) {
            var cacheKey = unitKey + '|' + tireId;
            var cached = targetsCache[cacheKey];
            if (cached && Date.now() - cached.at < TARGETS_TTL) { return cached.promise; }
            var promise = getJson(withQuery(api.targetsUrl, { key: unitKey, tire_id: tireId })).then(function (data) {
                if (!data || !data.ok) { throw new Error(data && data.error ? data.error : 'Pozițiile compatibile nu au putut fi verificate.'); }
                return data.targets || {};
            });
            targetsCache[cacheKey] = { at: Date.now(), promise: promise };
            promise.catch(function () { if (targetsCache[cacheKey] && targetsCache[cacheKey].promise === promise) { delete targetsCache[cacheKey]; } });
            return promise;
        };
        var forgetTargets = function (unitKey) {
            Object.keys(targetsCache).forEach(function (key) { if (key.indexOf(unitKey + '|') === 0) { delete targetsCache[key]; } });
        };

        /* ---------------------------------------------------------- Mesaje */

        var notify = function (message, kind) {
            if (!toastStack) {
                toastStack = document.createElement('div');
                toastStack.className = 'ft-toast-stack';
                document.body.appendChild(toastStack);
            }
            var item = document.createElement('div');
            item.className = 'alert alert-' + (kind || 'danger') + ' ft-toast';
            item.setAttribute('role', kind === 'success' ? 'status' : 'alert');
            var text = document.createElement('span');
            text.textContent = message;
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close';
            close.setAttribute('aria-label', 'Închide');
            item.appendChild(text);
            item.appendChild(close);
            var remove = function () { if (item.parentNode) { item.parentNode.removeChild(item); } };
            close.addEventListener('click', remove);
            toastStack.appendChild(item);
            window.setTimeout(remove, kind === 'success' ? 4000 : 9000);
        };

        /* ---------------------------------------------------------- Geometrie, fantome, animatii */

        var svgPoint = function (svg, clientX, clientY) {
            var matrix = svg.getScreenCTM();
            if (!matrix) { return { x: 0, y: 0 }; }
            var point = svg.createSVGPoint();
            point.x = clientX;
            point.y = clientY;
            point = point.matrixTransform(matrix.inverse());
            return { x: point.x, y: point.y };
        };
        var tireNodeOf = function (svg, positionId) { return svg ? svg.querySelector('.ft-tire[data-ft-pos="' + positionId + '"]') : null; };
        var nodesOf = function (svg, positionId) { return svg ? Array.prototype.slice.call(svg.querySelectorAll('.ft-tire[data-ft-pos="' + positionId + '"], .ft-pill[data-ft-pos="' + positionId + '"]')) : []; };
        var centerOf = function (node) {
            var body = node.querySelector('.ft-tire-body');
            return {
                x: Number(body.getAttribute('x')) + Number(body.getAttribute('width')) / 2,
                y: Number(body.getAttribute('y')) + Number(body.getAttribute('height')) / 2
            };
        };
        // Copia anvelopei care se misca peste schema (originalul ramane pe loc pana la salvare).
        var makeGhost = function (svg, node) {
            var ghost = node.cloneNode(true);
            ['tabindex', 'role', 'aria-label', 'data-ft-pos', 'data-ft-member'].forEach(function (name) { ghost.removeAttribute(name); });
            var title = ghost.querySelector('title');
            if (title) { title.parentNode.removeChild(title); }
            ghost.setAttribute('class', 'ft-tire-ghost');
            ghost.setAttribute('aria-hidden', 'true');
            svg.appendChild(ghost);
            place(ghost, 0, 0);
            return ghost;
        };
        var place = function (ghost, x, y) {
            ghost._ftAt = { x: x, y: y };
            ghost.setAttribute('transform', 'translate(' + x.toFixed(2) + ' ' + y.toFixed(2) + ')');
        };
        var removeNode = function (node) { if (node && node.parentNode) { node.parentNode.removeChild(node); } };
        // Deplasare lina (ease in-out) cu un arc optional perpendicular pe drum (la schimb, cele doua
        // anvelope trec una pe langa alta pe arce opuse, ca sa se vada ca sunt doua).
        var tween = function (ghost, to, duration, arc) {
            var from = ghost._ftAt || { x: 0, y: 0 };
            if (reducedMotion.matches || duration <= 0) {
                place(ghost, to.x, to.y);
                return Promise.resolve();
            }
            var dx = to.x - from.x;
            var dy = to.y - from.y;
            var length = Math.sqrt(dx * dx + dy * dy) || 1;
            var nx = -dy / length;
            var ny = dx / length;
            return new Promise(function (resolve) {
                var start = null;
                var step = function (now) {
                    if (start === null) { start = now; }
                    var t = Math.min(1, (now - start) / duration);
                    var e = t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
                    var lift = (arc || 0) * Math.sin(Math.PI * t);
                    place(ghost, from.x + dx * e + nx * lift, from.y + dy * e + ny * lift);
                    if (t < 1) { window.requestAnimationFrame(step); } else { resolve(); }
                };
                window.requestAnimationFrame(step);
            });
        };
        var flashReject = function (svg, positionIds) {
            positionIds.forEach(function (positionId) {
                var node = tireNodeOf(svg, positionId);
                if (!node) { return; }
                node.classList.remove('is-reject-flash');
                void node.getBoundingClientRect();
                node.classList.add('is-reject-flash');
                window.setTimeout(function () { node.classList.remove('is-reject-flash'); }, 800);
            });
        };
        var codeOf = function (unitKey, positionId) {
            var found = api.findPosition(unitKey, positionId);
            return found ? found.position.code : '';
        };

        /* ---------------------------------------------------------- Drag & drop */

        var DROP_CLASSES = ['is-drop-ok', 'is-drop-bad', 'is-drop-wait', 'is-drop-hover'];
        var markTargets = function (current) {
            current.svg.querySelectorAll('.ft-tire[data-ft-pos], .ft-pill[data-ft-pos]').forEach(function (node) {
                var positionId = node.getAttribute('data-ft-pos');
                DROP_CLASSES.forEach(function (name) { if (name !== 'is-drop-hover') { node.classList.remove(name); } });
                if (positionId === current.positionId) { return; }
                if (current.map === null) {
                    node.classList.add(current.error ? 'is-drop-bad' : 'is-drop-wait');
                } else {
                    node.classList.add(current.map[positionId] && current.map[positionId].ok ? 'is-drop-ok' : 'is-drop-bad');
                }
            });
        };
        var clearMarks = function (svg) {
            if (!svg) { return; }
            svg.querySelectorAll('.ft-tire, .ft-pill').forEach(function (node) {
                DROP_CLASSES.forEach(function (name) { node.classList.remove(name); });
            });
        };

        var dropInfo = function (current) {
            if (current.hover === null) { return null; }
            if (current.map === null) {
                return current.error ? { ok: false, reason: current.error } : { ok: null, reason: 'Se verifică compatibilitatea…' };
            }
            return current.map[current.hover] || { ok: false, reason: 'Poziție necunoscută sau nesincronizată (vezi Configurație Axe).' };
        };

        var updateHover = function (current, clientX, clientY) {
            var hit = document.elementFromPoint(clientX, clientY);
            var node = hit && hit.closest ? hit.closest('.ft-tire[data-ft-pos], .ft-pill[data-ft-pos]') : null;
            var hover = node && current.svg.contains(node) ? node.getAttribute('data-ft-pos') : null;
            if (hover === current.positionId) { hover = null; }
            if (hover !== current.hover) {
                nodesOf(current.svg, current.hover).forEach(function (item) { item.classList.remove('is-drop-hover'); });
                current.hover = hover;
                nodesOf(current.svg, hover).forEach(function (item) { item.classList.add('is-drop-hover'); });
            }
            var info = dropInfo(current);
            var tip = current.tip;
            if (!info) {
                tip.hidden = true;
                return;
            }
            tip.hidden = false;
            tip.className = 'ft-drag-tip' + (info.ok === true ? ' is-ok' : info.ok === false ? ' is-bad' : '');
            tip.textContent = info.ok === true ? '✓ Mută pe ' + codeOf(current.unitKey, current.hover) : (info.ok === false ? '✕ ' : '') + info.reason;
            var left = Math.min(clientX + 16, window.innerWidth - tip.offsetWidth - 8);
            tip.style.left = Math.max(8, left) + 'px';
            tip.style.top = Math.min(clientY + 18, window.innerHeight - tip.offsetHeight - 8) + 'px';
        };

        var startDrag = function (event) {
            var current = press;
            press = null;
            drag = current;
            current.hover = null;
            current.map = null;
            current.error = null;
            current.ghost = makeGhost(current.svg, current.node);
            current.origin = svgPoint(current.svg, current.x, current.y);
            current.tip = document.createElement('div');
            current.tip.className = 'ft-drag-tip';
            current.tip.hidden = true;
            document.body.appendChild(current.tip);
            current.node.classList.add('is-drag-source');
            current.unitEl.classList.add('ft-drag-active');
            document.body.classList.add('ft-is-dragging');
            removeSwapButton(current.unitEl);
            markTargets(current);
            current.targets.then(function (targets) {
                if (drag !== current) { return; }
                current.map = targets;
                markTargets(current);
                if (current.last) { updateHover(current, current.last.x, current.last.y); }
            }, function (error) {
                if (drag !== current) { return; }
                current.error = error.message;
                markTargets(current);
                if (current.last) { updateHover(current, current.last.x, current.last.y); }
            });
            moveDrag(event);
        };

        var moveDrag = function (event) {
            var current = drag;
            current.last = { x: event.clientX, y: event.clientY };
            var point = svgPoint(current.svg, event.clientX, event.clientY);
            place(current.ghost, point.x - current.origin.x, point.y - current.origin.y);
            updateHover(current, event.clientX, event.clientY);
        };

        var finishDrag = function (current) {
            removeNode(current.tip);
            current.unitEl.classList.remove('ft-drag-active');
            document.body.classList.remove('ft-is-dragging');
        };

        // Anvelopa revine pe pozitia ei (drop in afara, pe o pozitie incompatibila sau eroare la salvare).
        var returnGhost = function (current, rejected) {
            if (rejected) { current.ghost.classList.add('is-rejected'); }
            return tween(current.ghost, { x: 0, y: 0 }, rejected ? 320 : 220, 0).then(function () {
                removeNode(current.ghost);
                current.node.classList.remove('is-drag-source');
                clearMarks(current.svg);
                refresh(current.unitEl);
            });
        };

        var endDrag = function (event) {
            var current = drag;
            moveDrag(event);
            drag = null;
            finishDrag(current);
            suppressClick = true;
            window.setTimeout(function () { suppressClick = false; }, 0);

            var info = dropInfo(current);
            if (!info) {
                returnGhost(current, false);
                return;
            }
            if (info.ok === null) {
                // Drop rapid, inainte sa vina verificarea: anvelopa asteapta deasupra pozitiei.
                busy = true;
                current.ghost.classList.add('is-pending');
                var settle = function () {
                    busy = false;
                    decideDrop(current);
                };
                current.targets.then(function (targets) { current.map = targets; settle(); }, function (error) { current.error = error.message; settle(); });
                return;
            }
            decideDrop(current);
        };

        var decideDrop = function (current) {
            var info = dropInfo(current);
            if (info.ok !== true) {
                notify(codeOf(current.unitKey, current.positionId) + ' → ' + codeOf(current.unitKey, current.hover) + ': ' + info.reason);
                flashReject(current.svg, [current.hover]);
                returnGhost(current, true);
                return;
            }
            commitMove(current, current.hover);
        };

        var cancelDrag = function () {
            var current = drag;
            if (!current) { return; }
            drag = null;
            finishDrag(current);
            returnGhost(current, false);
        };

        // Drop pe o pozitie verde: anvelopa se aseaza pe ea si asteapta raspunsul serverului.
        // Schema se schimba abia dupa salvare (redesenata din datele serverului).
        var commitMove = function (current, targetId) {
            busy = true;
            var targetNode = tireNodeOf(current.svg, targetId);
            var from = centerOf(current.node);
            var to = targetNode ? centerOf(targetNode) : from;
            clearMarks(current.svg);
            nodesOf(current.svg, targetId).forEach(function (node) { node.classList.add('is-drop-pending'); });
            current.ghost.classList.add('is-pending');
            var label = current.tire.label + ' (' + codeOf(current.unitKey, current.positionId) + ')';
            var targetCode = codeOf(current.unitKey, targetId);
            var snapped = tween(current.ghost, { x: to.x - from.x, y: to.y - from.y }, 200, 0);
            var saved = postJson({ op: 'move', key: current.unitKey, tire_id: current.tire.id, source_position_id: current.positionId, target_position_id: targetId });

            Promise.all([saved, snapped]).then(function () {
                forgetTargets(current.unitKey);
                return api.reloadUnit(current.unitEl, { from: current.positionId, to: targetId }).then(function () {
                    notify(label + ' a fost mutată pe ' + targetCode + '.', 'success');
                }, function () {
                    notify('Mutarea s-a salvat, dar schema nu s-a putut reîncărca. Reîncarcă pagina.', 'warning');
                });
            }, function (error) {
                notify(error.message || 'Mutarea nu a putut fi salvată.');
                nodesOf(current.svg, targetId).forEach(function (node) { node.classList.remove('is-drop-pending'); });
                return snapped.then(function () { return returnGhost(current, true); }).then(function () {
                    // Pozitiile s-au schimbat intre timp (alt utilizator / alta fereastra): schema se reciteste.
                    forgetTargets(current.unitKey);
                    if (error.stale) { return api.reloadUnit(current.unitEl).catch(function () {}); }
                });
            }).then(function () {
                busy = false;
                refresh(current.unitEl);
            });
        };

        listEl.addEventListener('pointerdown', function (event) {
            if (busy || drag || !event.isPrimary || (event.pointerType === 'mouse' && event.button !== 0)) { return; }
            var node = event.target.closest('.ft-svg .ft-tire.is-mounted');
            if (!node) { return; }
            var unitEl = node.closest('[data-ft-unit]');
            var found = unitEl ? api.findPosition(unitEl.dataset.ftUnit, node.getAttribute('data-ft-pos')) : null;
            if (!found || !found.position.tire || Number(found.position.id) <= 0) { return; }
            press = {
                unitEl: unitEl,
                unitKey: unitEl.dataset.ftUnit,
                svg: node.ownerSVGElement,
                node: node,
                positionId: String(found.position.id),
                tire: found.position.tire,
                pointerId: event.pointerId,
                x: event.clientX,
                y: event.clientY
            };
            press.targets = loadTargets(press.unitKey, found.position.tire.id);
            press.targets.catch(function () { /* se arata la drop */ });
        });
        window.addEventListener('pointermove', function (event) {
            if (drag) {
                if (event.pointerId === drag.pointerId) { event.preventDefault(); moveDrag(event); }
                return;
            }
            if (!press || event.pointerId !== press.pointerId) { return; }
            var dx = event.clientX - press.x;
            var dy = event.clientY - press.y;
            if (dx * dx + dy * dy < DRAG_THRESHOLD * DRAG_THRESHOLD) { return; }
            event.preventDefault();
            startDrag(event);
        }, { passive: false });
        window.addEventListener('pointerup', function (event) {
            if (drag && event.pointerId === drag.pointerId) { endDrag(event); }
            if (press && event.pointerId === press.pointerId) { press = null; }
        });
        window.addEventListener('pointercancel', function (event) {
            if (drag && event.pointerId === drag.pointerId) { cancelDrag(); }
            if (press && event.pointerId === press.pointerId) { press = null; }
        });
        window.addEventListener('blur', function () { cancelDrag(); press = null; });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drag) {
                event.stopPropagation();
                cancelDrag();
            }
        }, true);
        // Dupa un drag nu urmeaza si un click (care ar selecta anvelopa).
        listEl.addEventListener('click', function (event) {
            if (!suppressClick) { return; }
            suppressClick = false;
            event.preventDefault();
            event.stopPropagation();
        }, true);

        /* ---------------------------------------------------------- Schimbul a doua anvelope */

        var removeSwapButton = function (unitEl) {
            removeNode(unitEl.querySelector('[data-ft-swap]'));
        };

        // Butonul „Schimbă pozițiile” apare langa cele doua anvelope selectate (ambele montate).
        var refresh = function (unitEl) {
            if (!unitEl) { return; }
            var button = unitEl.querySelector('[data-ft-swap]');
            var selected = api.selectedIn(unitEl);
            var container = unitEl.querySelector('[data-ft-schema]');
            var svg = container ? container.querySelector('.ft-svg') : null;
            var pair = selected.length === 2 ? selected.map(function (id) { return api.findPosition(unitEl.dataset.ftUnit, id); }) : [];
            var ready = pair.length === 2 && pair.every(function (found) { return found && found.position.tire && Number(found.position.id) > 0; });
            if (!ready || !svg || (drag && drag.unitEl === unitEl)) {
                removeNode(button);
                return;
            }
            if (!button) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-primary btn-sm ft-swap-btn';
                button.setAttribute('data-ft-swap', '');
                button.innerHTML = '<i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Schimbă pozițiile';
                button.addEventListener('click', function (event) {
                    event.stopPropagation();
                    swap(unitEl);
                });
                container.appendChild(button);
            }
            button.disabled = busy;
            button.setAttribute('aria-label', 'Schimbă pozițiile ' + pair[0].position.code + ' și ' + pair[1].position.code);
            var box = container.getBoundingClientRect();
            var rects = selected.map(function (id) {
                var node = tireNodeOf(svg, id);
                return node ? node.querySelector('.ft-tire-body').getBoundingClientRect() : null;
            });
            if (!rects[0] || !rects[1] || box.width === 0) { button.hidden = true; return; }
            button.hidden = false;
            var ax = (rects[0].left + rects[0].right) / 2;
            var ay = (rects[0].top + rects[0].bottom) / 2;
            var bx = (rects[1].left + rects[1].right) / 2;
            var by = (rects[1].top + rects[1].bottom) / 2;
            var close = Math.abs(ax - bx) < 90 && Math.abs(ay - by) < 40;
            // Anvelope apropiate (ex. roti duble): butonul sta deasupra lor, nu peste ele.
            button.style.left = ((ax + bx) / 2 - box.left) + 'px';
            button.style.top = (close ? Math.min(rects[0].top, rects[1].top) - box.top - 6 : (ay + by) / 2 - box.top) + 'px';
            button.classList.toggle('is-above', close);
        };

        var swap = function (unitEl) {
            if (busy || drag) { return; }
            var unitKey = unitEl.dataset.ftUnit;
            var selected = api.selectedIn(unitEl);
            var a = api.findPosition(unitKey, selected[0]);
            var b = api.findPosition(unitKey, selected[1]);
            if (!a || !b || !a.position.tire || !b.position.tire) { return; }
            busy = true;
            var button = unitEl.querySelector('[data-ft-swap]');
            if (button) {
                button.disabled = true;
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Se verifică…';
            }
            var codeA = a.position.code;
            var codeB = b.position.code;

            postJson({ op: 'swap', key: unitKey, tire_a: a.position.tire.id, position_a: a.position.id, tire_b: b.position.tire.id, position_b: b.position.id })
                .then(function () {
                    forgetTargets(unitKey);
                    removeSwapButton(unitEl);
                    var svg = unitEl.querySelector('.ft-svg');
                    var nodeA = tireNodeOf(svg, a.position.id);
                    var nodeB = tireNodeOf(svg, b.position.id);
                    var animation = Promise.resolve();
                    if (nodeA && nodeB) {
                        var ghostA = makeGhost(svg, nodeA);
                        var ghostB = makeGhost(svg, nodeB);
                        ghostA.classList.add('is-swap-a');
                        ghostB.classList.add('is-swap-b');
                        nodeA.classList.add('is-swap-hidden');
                        nodeB.classList.add('is-swap-hidden');
                        var pa = centerOf(nodeA);
                        var pb = centerOf(nodeB);
                        var distance = Math.sqrt(Math.pow(pb.x - pa.x, 2) + Math.pow(pb.y - pa.y, 2));
                        var arc = Math.max(7, Math.min(26, distance * 0.28));
                        animation = Promise.all([
                            tween(ghostA, { x: pb.x - pa.x, y: pb.y - pa.y }, 400, arc),
                            tween(ghostB, { x: pa.x - pb.x, y: pa.y - pb.y }, 400, arc)
                        ]);
                    }
                    // Fantomele raman pe pozitiile noi pana cand schema se redeseneaza din server.
                    return animation.then(function () { return api.reloadUnit(unitEl); }).then(function () {
                        notify('Pozițiile ' + codeA + ' și ' + codeB + ' au fost schimbate.', 'success');
                    }, function () {
                        notify('Schimbul s-a salvat, dar schema nu s-a putut reîncărca. Reîncarcă pagina.', 'warning');
                    });
                }, function (error) {
                    notify(error.message || 'Schimbul nu a putut fi salvat.');
                    flashReject(unitEl.querySelector('.ft-svg'), [a.position.id, b.position.id]);
                    if (button) { button.innerHTML = '<i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Schimbă pozițiile'; }
                    if (error.stale) {
                        forgetTargets(unitKey);
                        return api.reloadUnit(unitEl).catch(function () {});
                    }
                })
                .then(function () {
                    busy = false;
                    refresh(unitEl);
                });
        };

        var resizeTimer = 0;
        window.addEventListener('resize', function () {
            window.clearTimeout(resizeTimer);
            resizeTimer = window.setTimeout(function () {
                listEl.querySelectorAll('[data-ft-swap]').forEach(function (button) { refresh(button.closest('[data-ft-unit]')); });
            }, 120);
        });

        return {
            refresh: refresh,
            isBusy: function () { return busy || drag !== null; }
        };
    };

    window.FleetTireMoves = { create: create };
})();

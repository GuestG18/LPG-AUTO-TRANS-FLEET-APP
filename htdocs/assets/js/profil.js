/**
 * Profilul meu — personalizare avatar, decupare imagine, status de prezenta.
 *
 * Decuparea este implementata nativ pe <canvas>, fara nicio dependenta externa.
 * Rezultatul trimis la server este DEJA decupat si redimensionat; serverul il
 * valideaza si il re-encodeaza oricum, deci nu se acorda incredere clientului.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var page = document.querySelector('.pf-page');
        if (!page) {
            return;
        }

        var config = window.FleetProfileConfig || {};
        var OUTPUT = Number(config.outputSize) > 0 ? Number(config.outputSize) : 512;
        var MAX_BYTES = Number(config.maxBytes) > 0 ? Number(config.maxBytes) : 2 * 1024 * 1024;
        var ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

        // Fractiunea din latura scenei ocupata de cercul de decupare (vezi CSS: 86%).
        var CIRCLE_RATIO = 0.86;

        // -------------------------------------------------------------
        // Password show / hide
        // -------------------------------------------------------------
        page.querySelectorAll('[data-pf-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-pf-toggle'));
                if (!input) {
                    return;
                }
                var icon = button.querySelector('i');
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.setAttribute('aria-label', show ? 'Ascunde parola' : 'Arată parola');
                if (icon) {
                    icon.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
                }
            });
        });

        // -------------------------------------------------------------
        // Status de prezenta — previzualizare live (persistat la submit)
        // -------------------------------------------------------------
        var statusSelect = document.getElementById('pf-status-select');
        var statusInput = document.getElementById('pf-status-input');
        var statusMessage = document.getElementById('pf-status-message');
        var statusMessageInput = document.getElementById('pf-status-message-input');
        var selectDot = document.getElementById('pf-select-dot');
        var avatarDot = document.getElementById('pf-avatar-dot');
        var pillLabel = document.getElementById('pf-status-pill-label');
        var pillDot = document.querySelector('#pf-status-pill .pf-status-dot');
        var statusNote = document.getElementById('pf-status-note');
        var panel = document.getElementById('pf-status-panel');
        var panelTitle = document.getElementById('pf-status-panel-title');
        var panelText = document.getElementById('pf-status-panel-text');

        function syncStatus() {
            if (!statusSelect) {
                return;
            }
            var option = statusSelect.options[statusSelect.selectedIndex];
            if (!option) {
                return;
            }
            var dot = option.getAttribute('data-dot') || '#22c55e';
            var tone = option.getAttribute('data-tone') || 'ok';
            var title = option.getAttribute('data-title') || '';
            var description = option.getAttribute('data-description') || '';

            if (statusInput) { statusInput.value = option.value; }
            if (selectDot) { selectDot.style.background = dot; }

            // Punctul de pe avatar si pilula reflecta starea doar daca contul
            // este activ din punct de vedere al securitatii (marcat server-side).
            if (avatarDot && avatarDot.dataset.locked !== '1') { avatarDot.style.background = dot; }
            if (pillDot && !pillDot.closest('.is-muted')) { pillDot.style.background = dot; }
            if (pillLabel && !pillLabel.closest('.is-muted')) { pillLabel.textContent = option.textContent.trim(); }
            if (statusNote) { statusNote.textContent = description; }

            if (panel && !panel.classList.contains('is-muted-locked')) {
                panel.classList.remove('is-ok', 'is-warn', 'is-muted');
                panel.classList.add('is-' + tone);
                if (panelTitle) { panelTitle.textContent = title; }
                if (panelText) { panelText.textContent = description; }
            }
        }

        if (statusSelect) {
            statusSelect.addEventListener('change', syncStatus);
        }
        if (statusMessage && statusMessageInput) {
            var syncMessage = function () { statusMessageInput.value = statusMessage.value; };
            statusMessage.addEventListener('input', syncMessage);
            syncMessage();
        }

        // -------------------------------------------------------------
        // Emoji avatar
        // -------------------------------------------------------------
        var emojiRow = document.getElementById('pf-emoji-row');
        var emojiTrigger = document.getElementById('pf-emoji-trigger');
        var avatarError = document.getElementById('pf-avatar-error');

        function showAvatarError(message) {
            if (!avatarError) {
                return;
            }
            if (!message) {
                avatarError.hidden = true;
                avatarError.textContent = '';
                return;
            }
            avatarError.hidden = false;
            avatarError.textContent = message;
        }

        if (emojiTrigger && emojiRow) {
            emojiTrigger.addEventListener('click', function () {
                emojiRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var first = emojiRow.querySelector('.pf-emoji');
                if (first) { first.focus(); }
            });
        }

        if (emojiRow) {
            emojiRow.addEventListener('click', function (event) {
                var button = event.target.closest('.pf-emoji');
                if (!button) {
                    return;
                }
                showAvatarError('');

                // Click pe emoji-ul deja selectat => se elimina badge-ul.
                var isSelected = button.classList.contains('is-selected');
                var emoji = isSelected ? '' : (button.getAttribute('data-emoji') || '');
                var color = isSelected ? '' : (button.getAttribute('data-color') || '');

                var body = new FormData();
                body.append('_token', config.csrf || '');
                body.append('emoji', emoji);
                body.append('color', color);

                emojiRow.querySelectorAll('.pf-emoji').forEach(function (el) {
                    el.classList.remove('is-selected');
                    el.setAttribute('aria-pressed', 'false');
                });
                if (!isSelected) {
                    button.classList.add('is-selected');
                    button.setAttribute('aria-pressed', 'true');
                }

                fetch(config.emojiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) {
                            showAvatarError((data && data.error) || 'Emoji-ul nu a putut fi salvat.');
                            return;
                        }
                        applyEmojiBadge(data.emoji, data.color);
                    })
                    .catch(function () {
                        showAvatarError('Eroare de rețea la salvarea emoji-ului.');
                    });
            });
        }

        /**
         * Adauga / actualizeaza / elimina badge-ul emoji peste avatarul de baza.
         * Poza nu este atinsa.
         */
        function applyEmojiBadge(emoji, color) {
            document.querySelectorAll('.profile-avatar-stack').forEach(function (stack) {
                var badge = stack.querySelector('.profile-avatar-badge');

                if (!emoji) {
                    if (badge) { badge.remove(); }
                    return;
                }

                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'profile-avatar-badge';
                    var inner = document.createElement('span');
                    inner.className = 'profile-avatar-badge-emoji';
                    badge.appendChild(inner);
                    stack.appendChild(badge);
                }
                badge.style.background = color || '#dbeafe';
                badge.querySelector('.profile-avatar-badge-emoji').textContent = emoji;
            });
        }

        /** Inlocuieste avatarul de baza cu poza noua, pastrand badge-ul emoji. */
        function applyAvatarImage(url) {
            document.querySelectorAll('.profile-avatar-stack').forEach(function (stack) {
                var base = stack.querySelector('.profile-avatar');
                if (!base) { return; }
                base.classList.remove('is-fallback', 'is-initials');
                base.style.background = '';
                var img = document.createElement('img');
                img.src = url;
                img.alt = 'Avatar';
                base.innerHTML = '';
                base.appendChild(img);
            });
        }

        // =============================================================
        // Crop modal
        // =============================================================
        var fileInput = document.getElementById('pf-file-input');
        var uploadTrigger = document.getElementById('pf-upload-trigger');
        var modal = document.getElementById('pf-crop-modal');
        var canvas = document.getElementById('pf-crop-canvas');
        var stage = document.getElementById('pf-crop-stage');
        var zoomRange = document.getElementById('pf-zoom-range');
        var zoomIn = document.getElementById('pf-zoom-in');
        var zoomOut = document.getElementById('pf-zoom-out');
        var rotateBtn = document.getElementById('pf-rotate');
        var closeBtn = document.getElementById('pf-crop-close');
        var cancelBtn = document.getElementById('pf-crop-cancel');
        var saveBtn = document.getElementById('pf-crop-save');
        var cropError = document.getElementById('pf-crop-error');

        if (!fileInput || !modal || !canvas) {
            return;
        }

        var ctx = canvas.getContext('2d');
        var state = {
            image: null,
            objectUrl: null,
            scale: 1,
            baseScale: 1,
            rotation: 0,
            offsetX: 0,
            offsetY: 0,
            dragging: false,
            lastX: 0,
            lastY: 0
        };

        function showCropError(message) {
            if (!cropError) { return; }
            if (!message) {
                cropError.hidden = true;
                cropError.textContent = '';
                return;
            }
            cropError.hidden = false;
            cropError.textContent = message;
        }

        function openModal() {
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = '';
            releaseImage();
            // Permite reselectarea aceluiasi fisier.
            fileInput.value = '';
            showCropError('');
        }

        /** Elibereaza obiectul temporar: nimic nu ajunge pe server la anulare. */
        function releaseImage() {
            if (state.objectUrl) {
                URL.revokeObjectURL(state.objectUrl);
                state.objectUrl = null;
            }
            state.image = null;
        }

        function circleSize() {
            return canvas.width * CIRCLE_RATIO;
        }

        /** Scala minima la care imaginea acopera complet cercul de decupare. */
        function computeBaseScale() {
            if (!state.image) { return 1; }
            var rotated = state.rotation % 180 !== 0;
            var w = rotated ? state.image.naturalHeight : state.image.naturalWidth;
            var h = rotated ? state.image.naturalWidth : state.image.naturalHeight;
            var target = circleSize();

            return Math.max(target / w, target / h);
        }

        function clampOffsets() {
            if (!state.image) { return; }
            var rotated = state.rotation % 180 !== 0;
            var w = (rotated ? state.image.naturalHeight : state.image.naturalWidth) * state.baseScale * state.scale;
            var h = (rotated ? state.image.naturalWidth : state.image.naturalHeight) * state.baseScale * state.scale;
            var half = circleSize() / 2;

            var maxX = Math.max(0, w / 2 - half);
            var maxY = Math.max(0, h / 2 - half);

            state.offsetX = Math.min(maxX, Math.max(-maxX, state.offsetX));
            state.offsetY = Math.min(maxY, Math.max(-maxY, state.offsetY));
        }

        function draw() {
            ctx.save();
            ctx.fillStyle = '#0f172a';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            if (state.image) {
                clampOffsets();
                ctx.translate(canvas.width / 2 + state.offsetX, canvas.height / 2 + state.offsetY);
                ctx.rotate((state.rotation * Math.PI) / 180);
                var s = state.baseScale * state.scale;
                var w = state.image.naturalWidth * s;
                var h = state.image.naturalHeight * s;
                ctx.imageSmoothingQuality = 'high';
                ctx.drawImage(state.image, -w / 2, -h / 2, w, h);
            }
            ctx.restore();
        }

        // ---- file selection ----------------------------------------
        if (uploadTrigger) {
            uploadTrigger.addEventListener('click', function () {
                showAvatarError('');
                fileInput.click();
            });
        }

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) {
                return;
            }

            if (ACCEPTED.indexOf(file.type) === -1) {
                showAvatarError('Format neacceptat. Alege un fișier JPG, PNG sau WEBP.');
                fileInput.value = '';
                return;
            }
            if (file.size > MAX_BYTES) {
                showAvatarError('Imaginea depășește limita de 2 MB.');
                fileInput.value = '';
                return;
            }

            releaseImage();
            state.objectUrl = URL.createObjectURL(file);

            var img = new Image();
            img.onload = function () {
                state.image = img;
                state.rotation = 0;
                state.scale = 1;
                state.offsetX = 0;
                state.offsetY = 0;
                state.baseScale = computeBaseScale();
                if (zoomRange) { zoomRange.value = '100'; }
                draw();
                openModal();
            };
            img.onerror = function () {
                showAvatarError('Imaginea nu a putut fi citită.');
                releaseImage();
                fileInput.value = '';
            };
            img.src = state.objectUrl;
        });

        // ---- drag ---------------------------------------------------
        stage.addEventListener('pointerdown', function (event) {
            if (!state.image) { return; }
            state.dragging = true;
            state.lastX = event.clientX;
            state.lastY = event.clientY;
            stage.classList.add('is-dragging');
            stage.setPointerCapture(event.pointerId);
        });

        stage.addEventListener('pointermove', function (event) {
            if (!state.dragging || !state.image) { return; }
            // Coordonatele CSS trebuie convertite in coordonate de canvas.
            var rect = stage.getBoundingClientRect();
            var ratio = canvas.width / rect.width;
            state.offsetX += (event.clientX - state.lastX) * ratio;
            state.offsetY += (event.clientY - state.lastY) * ratio;
            state.lastX = event.clientX;
            state.lastY = event.clientY;
            draw();
        });

        function endDrag(event) {
            if (!state.dragging) { return; }
            state.dragging = false;
            stage.classList.remove('is-dragging');
            if (event && event.pointerId !== undefined && stage.hasPointerCapture(event.pointerId)) {
                stage.releasePointerCapture(event.pointerId);
            }
        }
        stage.addEventListener('pointerup', endDrag);
        stage.addEventListener('pointercancel', endDrag);
        stage.addEventListener('pointerleave', endDrag);

        // ---- zoom ---------------------------------------------------
        function setZoom(percent) {
            var value = Math.min(400, Math.max(100, percent));
            state.scale = value / 100;
            if (zoomRange) { zoomRange.value = String(value); }
            draw();
        }

        if (zoomRange) {
            zoomRange.addEventListener('input', function () { setZoom(Number(zoomRange.value)); });
        }
        if (zoomIn) {
            zoomIn.addEventListener('click', function () { setZoom(Number(zoomRange.value) + 20); });
        }
        if (zoomOut) {
            zoomOut.addEventListener('click', function () { setZoom(Number(zoomRange.value) - 20); });
        }

        stage.addEventListener('wheel', function (event) {
            if (!state.image) { return; }
            event.preventDefault();
            setZoom(Number(zoomRange.value) + (event.deltaY < 0 ? 8 : -8));
        }, { passive: false });

        // ---- rotate -------------------------------------------------
        if (rotateBtn) {
            rotateBtn.addEventListener('click', function () {
                if (!state.image) { return; }
                state.rotation = (state.rotation + 90) % 360;
                state.baseScale = computeBaseScale();
                state.offsetX = 0;
                state.offsetY = 0;
                draw();
            });
        }

        // ---- close / cancel ----------------------------------------
        [closeBtn, cancelBtn].forEach(function (button) {
            if (button) {
                button.addEventListener('click', closeModal);
            }
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                closeModal();
            }
        });

        // ---- save ---------------------------------------------------
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                if (!state.image) {
                    showCropError('Nu există nicio imagine de salvat.');
                    return;
                }

                var out = document.createElement('canvas');
                out.width = OUTPUT;
                out.height = OUTPUT;
                var outCtx = out.getContext('2d');
                outCtx.fillStyle = '#ffffff';
                outCtx.fillRect(0, 0, OUTPUT, OUTPUT);
                outCtx.imageSmoothingQuality = 'high';

                // Decupam exact patratul care contine cercul vizibil.
                var size = circleSize();
                var sx = (canvas.width - size) / 2;
                var sy = (canvas.height - size) / 2;
                outCtx.drawImage(canvas, sx, sy, size, size, 0, 0, OUTPUT, OUTPUT);

                saveBtn.disabled = true;
                var originalText = saveBtn.textContent;
                saveBtn.textContent = 'Se salvează...';
                showCropError('');

                out.toBlob(function (blob) {
                    if (!blob) {
                        saveBtn.disabled = false;
                        saveBtn.textContent = originalText;
                        showCropError('Imaginea nu a putut fi procesată.');
                        return;
                    }

                    var body = new FormData();
                    body.append('_token', config.csrf || '');
                    body.append('avatar', blob, 'avatar.jpg');

                    fetch(config.uploadUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            saveBtn.disabled = false;
                            saveBtn.textContent = originalText;
                            if (!data || !data.ok) {
                                showCropError((data && data.error) || 'Imaginea nu a putut fi salvată.');
                                return;
                            }
                            // Badge-ul emoji ramane neschimbat: poza si emoji coexista.
                            applyAvatarImage(data.url + '?t=' + Date.now());
                            closeModal();
                        })
                        .catch(function () {
                            saveBtn.disabled = false;
                            saveBtn.textContent = originalText;
                            showCropError('Eroare de rețea la salvarea imaginii.');
                        });
                }, 'image/jpeg', 0.92);
            });
        }
    });
})();

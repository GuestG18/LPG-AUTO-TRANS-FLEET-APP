/* Client passkey (WebAuthn) helper pentru Fleet Management. */
(function () {
    'use strict';

    function b64urlToBuffer(value) {
        var s = String(value).replace(/-/g, '+').replace(/_/g, '/');
        var pad = s.length % 4;
        if (pad) { s += '='.repeat(4 - pad); }
        var bin = atob(s);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes.buffer;
    }

    function bufferToB64url(buffer) {
        var bytes = new Uint8Array(buffer);
        var bin = '';
        for (var i = 0; i < bytes.length; i++) { bin += String.fromCharCode(bytes[i]); }
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function prepareCreate(publicKey) {
        publicKey.challenge = b64urlToBuffer(publicKey.challenge);
        publicKey.user.id = b64urlToBuffer(publicKey.user.id);
        (publicKey.excludeCredentials || []).forEach(function (c) { c.id = b64urlToBuffer(c.id); });
        return publicKey;
    }

    function prepareGet(publicKey) {
        publicKey.challenge = b64urlToBuffer(publicKey.challenge);
        (publicKey.allowCredentials || []).forEach(function (c) { c.id = b64urlToBuffer(c.id); });
        return publicKey;
    }

    async function getJson(url) {
        var r = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
        return r.json();
    }

    async function postJson(url, data) {
        var r = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        });
        var body = {};
        try { body = await r.json(); } catch (e) {}
        return { ok: r.ok, status: r.status, data: body };
    }

    window.FleetPasskey = {
        supported: function () {
            return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
        },

        // Passkey-urile functioneaza doar pe "localhost" sau pe un domeniu real.
        // Adresele IP (ex: 127.0.0.1) sunt respinse de browser.
        hostAllowed: function () {
            var h = location.hostname;
            if (h === 'localhost' || h.endsWith('.localhost')) { return true; }
            if (/^\d{1,3}(\.\d{1,3}){3}$/.test(h)) { return false; } // IPv4
            if (h.indexOf(':') !== -1) { return false; }             // IPv6
            return h.indexOf('.') !== -1;                            // domeniu real
        },

        localhostUrl: function () {
            try {
                var u = new URL(location.href);
                u.hostname = 'localhost';
                return u.href;
            } catch (e) {
                return 'http://localhost:' + (location.port || '80') + location.pathname + location.search;
            }
        },

        register: async function (optionsUrl, verifyUrl, csrf, label) {
            var opt = await getJson(optionsUrl);
            if (opt.error) { throw new Error(opt.error); }
            var cred = await navigator.credentials.create({ publicKey: prepareCreate(opt.publicKey) });
            var transports = [];
            try { transports = cred.response.getTransports ? cred.response.getTransports() : []; } catch (e) {}
            var res = await postJson(verifyUrl, {
                csrf: csrf,
                label: label || '',
                id: cred.id,
                rawId: bufferToB64url(cred.rawId),
                type: cred.type,
                transports: transports,
                response: {
                    clientDataJSON: bufferToB64url(cred.response.clientDataJSON),
                    attestationObject: bufferToB64url(cred.response.attestationObject)
                }
            });
            if (!res.ok || res.data.error) { throw new Error(res.data.error || 'Inregistrarea passkey a esuat.'); }
            return res.data;
        },

        login: async function (optionsUrl, verifyUrl) {
            var opt = await getJson(optionsUrl);
            if (opt.error) { throw new Error(opt.error); }
            var cred = await navigator.credentials.get({ publicKey: prepareGet(opt.publicKey) });
            var res = await postJson(verifyUrl, {
                id: cred.id,
                rawId: bufferToB64url(cred.rawId),
                type: cred.type,
                response: {
                    clientDataJSON: bufferToB64url(cred.response.clientDataJSON),
                    authenticatorData: bufferToB64url(cred.response.authenticatorData),
                    signature: bufferToB64url(cred.response.signature),
                    userHandle: cred.response.userHandle ? bufferToB64url(cred.response.userHandle) : null
                }
            });
            if (!res.ok || res.data.error) { throw new Error(res.data.error || 'Autentificarea cu passkey a esuat.'); }
            return res.data;
        }
    };
})();

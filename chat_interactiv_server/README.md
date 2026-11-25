# LPG Connect — server multiplayer (Chat Interactiv)

Server WebSocket autoritar, ușor (Node ≥ 18 + `ws`), pentru pagina `?page=chat_interactiv`.
Randarea 3D se face integral în browser; serverul nu are nevoie de GPU și nu accesează baza de date.

```
browser ──(sesiune PHP)──> index.php?page=chat_interactiv&action=bootstrap   → token semnat HMAC (2 min, unică folosință)
browser ──ws──> server.js  {t:"hello", token}                                → verifică semnătura, expirarea, camera, avatarul
server.js ──POST /api/chat-interactiv/v1/messages (X-Chat-Token)──> PHP     → mesajul se salvează în MySQL, apoi se difuzează
```

## Pornire locală

```powershell
cd chat_interactiv_server
npm install
npm start
```

Configurare în `.env` (rădăcina proiectului):

| cheie | rol |
|---|---|
| `CHAT_INTERACTIV_SECRET` | secret HMAC comun PHP ↔ server (obligatoriu; ex. `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`) |
| `CHAT_INTERACTIV_WS_PORT` | portul serverului (implicit 3011) |
| `CHAT_INTERACTIV_WS_URL` | adresa WS văzută de browser; gol = `ws(s)://<host-ul paginii>:<port>` |
| `CHAT_INTERACTIV_PHP_URL` | adresa aplicației PHP pentru apelurile server → PHP (ex. `http://aplicatie_fleet.test/htdocs`) |

Verificare: `http://localhost:3011/health` → `{"ok":true,"players":0,...}`.

## Ce validează serverul

- identitatea și avatarul (din tokenul semnat; un utilizator conectat a doua oară o înlocuiește pe prima conexiune);
- viteza (≤ `player.maxServerSpeed` din `world.json`), limitele camerei și obstacolele (aceleași date ca clientul) —
  o poziție invalidă e respinsă cu `{t:"fix"}`, ceilalți nu o văd;
- chat: max 6 mesaje / 10 s, max 1000 caractere, caractere de control eliminate; textul e escapat la afișare;
- reconectare: poziția anterioară se păstrează 15 min.

## Producție (VPS)

1. `npm ci --omit=dev` în `chat_interactiv_server/` și pornire ca serviciu (systemd / pm2):
   `node /cale/proiect/chat_interactiv_server/server.js`.
2. Recomandat: WebSocket pe aceeași origine prin reverse proxy (TLS = `wss://`), apoi
   `CHAT_INTERACTIV_WS_URL=wss://domeniu/lpg-connect-ws`.

   Nginx:
   ```nginx
   location /lpg-connect-ws {
       proxy_pass http://127.0.0.1:3011;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "upgrade";
       proxy_read_timeout 120s;
   }
   ```
   Apache (mod_proxy + mod_proxy_wstunnel):
   ```apache
   ProxyPass        /lpg-connect-ws ws://127.0.0.1:3011/
   ProxyPassReverse /lpg-connect-ws ws://127.0.0.1:3011/
   ```
3. Portul 3011 nu trebuie expus public când se folosește proxy-ul.

## Asset-uri 3D

Originalele (Sketchfab „XR test office room”, Avaturn A/B) sunt păstrate local în
`storage/chat-interactiv/originals/` (ignorat de git). Versiunile optimizate pentru web se generează cu:

```powershell
npm run assets
```

→ `htdocs/assets/models/chat-interactiv/` (texturi WebP redimensionate; avatarul B fără blendshape-urile
faciale; animația Idle a lui A extrasă separat). Geometria camerei nu e decimată.

## Test de acceptanță (2 conturi reale, 2 browsere izolate)

```powershell
php tests/make-test-sessions.php 3 2 C:/laragon/tmp     # -> {"a": "...", "b": "..."}
$env:CI_SID_A="..."; $env:CI_SID_B="..."; node tests/acceptance.cjs
php tests/make-test-sessions.php --clean C:/laragon/tmp
```

Necesită Playwright (`PW=<cale către modulul playwright>` dacă nu e instalat global). Contul A trebuie să poată
publica noutăți (`chat_interactiv.publish_news`), B poate fi un utilizator obișnuit. Capturile și
`results.json` se scriu în `tests/out/` (sau `CI_OUT`).

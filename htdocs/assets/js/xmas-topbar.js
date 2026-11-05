/* LPG Christmas topbar: a truck with Santa drives to a Christmas tree, delivers a gift and drives home.
   Usage: <header class="topbar ..." data-xmas-topbar="assets/xmas/lpg-logo.png"> ... </header>
          <link rel="stylesheet" href="assets/xmas/xmas-topbar.css">
          <script src="assets/xmas/xmas-topbar.js" defer></script>
   The scene is drawn behind the topbar's own content and never blocks clicks. */
(function () {
  "use strict";

  var MARKUP = `
<div class="xt-stage" id="xtStage">
  <canvas class="xt-cv" id="xtSkyCanvas"></canvas>
  <div class="xt-layer" id="xtSky"></div>
  <svg class="xt-ground" id="xtGround" preserveAspectRatio="none"></svg>
  <div class="xt-layer" id="xtFarLights"></div>
  <canvas class="xt-cv" id="xtFarCanvas"></canvas>

  <!-- Christmas tree (destination) -->
  <!-- static art lives in the SVG; anything that animates (rays, lights) is a separate HTML layer
       so the browser can animate it on the GPU without repainting the tree -->
  <div class="xt-tree" id="xtTree">
  <svg class="xt-rays" viewBox="-50 -50 100 100" fill="#ffe680">
    <polygon points="0,0 -4,-44 4,-44" opacity=".35"/>
    <polygon points="0,0 44,-4 44,4" opacity=".35"/>
    <polygon points="0,0 -44,-4 -44,4" opacity=".35"/>
    <polygon points="0,0 34,-32 39,-26" opacity=".25"/>
    <polygon points="0,0 -34,-32 -39,-26" opacity=".25"/>
    <polygon points="0,0 -4,44 4,44" opacity=".2"/>
  </svg>
  <svg class="xt-tree-art" viewBox="0 0 132 176">
    <rect x="58" y="150" width="16" height="22" fill="#5a3a22"/>
    <path d="M66 22 L102 70 L86 68 L114 110 L94 108 L126 154 L6 154 L38 108 L18 110 L46 68 L30 70 Z" fill="#1f6b3a"/>
    <path d="M66 22 L84 46 L66 44 L48 46 Z M38 108 L18 110 L46 68 L58 70 Z M6 154 L38 108 L52 110 L30 154 Z" fill="#185a30"/>
    <path d="M30 70 L46 68 L58 72 L72 68 L86 68 L102 70 L86 74 L66 72 L46 74 Z M18 110 L38 108 L58 112 L76 108 L94 108 L114 110 L94 115 L66 112 L38 115 Z M6 154 L126 154 L118 158 L14 158 Z" fill="#eef6ff"/>
    <path d="M44 56 Q66 66 90 54 M30 92 Q66 106 104 90 M18 136 Q66 152 116 134" stroke="#c9a64a" stroke-width="1.4" fill="none" opacity=".8"/>
  </svg>
  <canvas class="xt-cv" id="xtTreeCanvas"></canvas>
  <svg class="xt-tree-art" viewBox="0 0 132 176">
    <path class="xt-treestar" d="M66 1 L70 10 L80 11 L72.5 17.5 L75 27 L66 22 L57 27 L59.5 17.5 L52 11 L62 10 Z" stroke="#fff3b0" stroke-width=".8"/>
  </svg>
  </div>
  <div class="xt-flash" id="xtFlash"></div>
  <div class="xt-layer" id="xtGifts"></div>

  <!-- Truck: driver + the original logo (unchanged, transparent) + real rolling wheels inside its wheel rings -->
  <div class="xt-truck" id="xtTruck">
    <div class="xt-cab" id="xtCab"></div>
    <img class="xt-logo-body" alt="LPG auto-trans">
    <img class="xt-logo-text" alt="">
    <div class="xt-wheels" id="xtWheels"></div>
    <svg width="0" height="0" style="position:absolute">
      <defs>
        <radialGradient id="xtTire" cx="50%" cy="50%" r="50%">
          <stop offset=".62" stop-color="#3a3d44"/><stop offset=".85" stop-color="#1b1d22"/><stop offset="1" stop-color="#0e0f12"/>
        </radialGradient>
        <radialGradient id="xtRim" cx="40%" cy="35%" r="70%">
          <stop offset="0" stop-color="#ffffff"/><stop offset=".55" stop-color="#c3c9d4"/><stop offset="1" stop-color="#7c8494"/>
        </radialGradient>
      </defs>
    </svg>
  </div>

  <div class="xt-layer" id="xtNearLights" style="z-index:20"></div>
  <canvas class="xt-cv" id="xtNearCanvas" style="z-index:21"></canvas>
  <div class="xt-santa" id="xtSanta"></div>
  <canvas class="xt-snow" id="xtSnow"></canvas>
</div>
`;

  function init(header) {

    /* ---------- config ---------- */
    var STAGE_H = 230;
    var TRUCK_W = 320;
    var TRUCK_H = TRUCK_W * 453 / 1200;
    var ROAD_Y = 206;                  // where the truck's wheels touch the road
    var LOOP = true;
    var LIGHT_COLORS = [
      ["#ff3b3b", "rgba(255,59,59,.45)"],
      ["#3dff7a", "rgba(61,255,122,.4)"],
      ["#ffd23b", "rgba(255,210,59,.45)"],
      ["#4aa8ff", "rgba(74,168,255,.45)"],
      ["#ff7be0", "rgba(255,123,224,.4)"]
    ];

    // timeline (seconds)
    var T = {
      driveIn: 0, arrive: 6.2, turnStart: 6.5, turnEnd: 7.9, exitCab: 8.3, reachTree: 12.0, dropDone: 13.2,
      backAtTruck: 16.9, driveOut: 17.6, gone: 23.4, reset: 24.8, end: 27.3
    };

    var logo = header.getAttribute("data-xmas-topbar") || "lpg-logo.png";
    var root = document.createElement("div");
    root.className = "xt-scene";
    root.setAttribute("aria-hidden", "true");
    root.innerHTML = MARKUP;
    root.querySelectorAll(".xt-truck img").forEach(function (img) { img.src = logo; });
    header.insertBefore(root, header.firstChild);
    header.classList.add("xt-festive");
    if (getComputedStyle(header).position === "static") header.style.position = "relative";
    var stage = document.getElementById("xtStage");
    var truck = document.getElementById("xtTruck");
    var cab = document.getElementById("xtCab");
    var santaEl = document.getElementById("xtSanta");
    var tree = document.getElementById("xtTree");
    var flash = document.getElementById("xtFlash");
    var giftsLayer = document.getElementById("xtGifts");
    var SW = 1000, scale = 1, treeCenter = 80;
    var bulbs = [];
    var newGift = null;

    /* ---------- Santa SVG ---------- */
    function santaSVG(id) {
      return '<svg viewBox="0 0 60 92" id="' + id + '">' +
        '<g class="leg-l"><rect x="20" y="64" width="8" height="18" fill="#b3121f"/><rect x="17" y="80" width="12" height="7" rx="3" fill="#1d1d1d"/></g>' +
        '<g class="leg-r"><rect x="32" y="64" width="8" height="18" fill="#b3121f"/><rect x="31" y="80" width="12" height="7" rx="3" fill="#1d1d1d"/></g>' +
        '<g class="arm-l"><path d="M17 45 Q11 53 12 61" stroke="#d6202f" stroke-width="7" stroke-linecap="round" fill="none"/><rect x="8" y="60" width="8" height="3" rx="1.5" fill="#fff"/><circle cx="12" cy="65" r="3.8" fill="#1d1d1d"/></g>' +
        '<path d="M14 67 Q11 44 30 42 Q49 44 46 67 Z" fill="#d6202f"/>' +
        '<rect x="28" y="44" width="4" height="22" fill="#fff"/>' +
        '<rect x="13" y="65" width="34" height="5" rx="2.5" fill="#fff"/>' +
        '<rect x="14" y="55" width="32" height="5" fill="#1d1d1d"/>' +
        '<rect x="26.5" y="54" width="7" height="7" fill="none" stroke="#f5c542" stroke-width="1.6"/>' +
        '<g class="gift" style="display:none"><rect x="20" y="49" width="20" height="15" rx="1.5" fill="#2a9d4b"/><rect x="28.5" y="49" width="3" height="15" fill="#f5c542"/><rect x="20" y="55" width="20" height="3" fill="#f5c542"/><ellipse cx="27" cy="48" rx="3" ry="2" fill="none" stroke="#f5c542" stroke-width="1.5"/><ellipse cx="33" cy="48" rx="3" ry="2" fill="none" stroke="#f5c542" stroke-width="1.5"/></g>' +
        '<g class="arm-r"><path d="M43 45 Q49 53 48 61" stroke="#d6202f" stroke-width="7" stroke-linecap="round" fill="none"/><rect x="44" y="60" width="8" height="3" rx="1.5" fill="#fff"/><circle cx="48" cy="65" r="3.8" fill="#1d1d1d"/></g>' +
        '<path d="M19 29 Q18 50 30 53 Q42 50 41 29 Z" fill="#fff"/>' +
        '<circle cx="30" cy="29" r="9" fill="#f6c6a3"/>' +
        '<circle cx="24.6" cy="31.5" r="2" fill="#f08a8a" opacity=".55"/><circle cx="35.4" cy="31.5" r="2" fill="#f08a8a" opacity=".55"/>' +
        '<circle cx="26.6" cy="27.5" r="1.2" fill="#2b1a12"/><circle cx="33.4" cy="27.5" r="1.2" fill="#2b1a12"/>' +
        '<path d="M30 33.5 Q25 31.5 22.5 35.5 Q27 37.5 30 35 Q33 37.5 37.5 35.5 Q35 31.5 30 33.5 Z" fill="#fff"/>' +
        '<circle cx="30" cy="31.3" r="1.8" fill="#e99a86"/>' +
        '<path d="M20 23 Q21 8 34 6 Q45 5 49 15 L43 17 Q39 12 37 22 Z" fill="#d6202f"/>' +
        '<circle cx="48" cy="16" r="3.6" fill="#fff"/>' +
        '<rect x="18.5" y="19.5" width="23" height="6" rx="3" fill="#fff"/>' +
        '</svg>';
    }
    cab.innerHTML = santaSVG("xtCabSanta");
    santaEl.innerHTML = santaSVG("xtWalkSanta");

    function parts(svg) {
      return {
        armL: svg.querySelector(".arm-l"), armR: svg.querySelector(".arm-r"),
        legL: svg.querySelector(".leg-l"), legR: svg.querySelector(".leg-r"),
        gift: svg.querySelector(".gift")
      };
    }
    var cabSanta = parts(document.getElementById("xtCabSanta"));
    var walkSanta = parts(document.getElementById("xtWalkSanta"));

    // write an SVG attribute only when it changes: every setAttribute invalidates the SVG
    // and makes the browser lay it out and repaint it again
    function setAttr(el, v) { if (el._xv !== v) { el._xv = v; el.setAttribute("transform", v); } }
    function setStyle(el, prop, v) { var k = "_xs" + prop; if (el[k] !== v) { el[k] = v; el.style[prop] = v; } }

    function poseSanta(p, o, t) {
      var wave = -150 + Math.sin(t * 9) * 22;
      setAttr(p.armR, o.wave ? "rotate(" + wave.toFixed(1) + " 43 45)" : (o.carry ? "rotate(28 43 45)" : ""));
      setAttr(p.armL, o.carry ? "rotate(-28 17 45)" : "");
      setStyle(p.gift, "display", o.carry ? "" : "none");
      var s = o.walk ? Math.sin(t * 11) : 0;
      setAttr(p.legL, "translate(0 " + (-Math.max(0, s) * 3.5).toFixed(2) + ")");
      setAttr(p.legR, "translate(0 " + (-Math.max(0, -s) * 3.5).toFixed(2) + ")");
    }

    /* ---------- wheels ---------- */
    var WHEELS = [[109, 346], [257, 346], [990, 346]];
    var wheelG = document.getElementById("xtWheels");
    // each wheel sits inside the logo's blue ring (inner radius ~57) so the logo itself is untouched
    // each wheel is its own small layer rotated with a CSS transform, so spinning it costs no repaint
    var spokeGroups = WHEELS.map(function (w) {
      var cx = 56, cy = 56, i, a;
      function pt(r, ang) { return (cx + Math.cos(ang) * r).toFixed(1) + " " + (cy + Math.sin(ang) * r).toFixed(1); }
      // tire (radially symmetric, so it can spin with the rest)
      var spin = '<circle cx="56" cy="56" r="56" fill="url(#xtTire)"/>';
      // tread blocks on the tire's edge
      for (i = 0; i < 18; i++) {
        a = i * Math.PI * 2 / 18;
        spin += '<path d="M' + pt(49, a - 0.07) + ' L' + pt(55, a - 0.06) + ' L' + pt(55, a + 0.06) + ' L' + pt(49, a + 0.07) + ' Z" fill="#4a4e57"/>';
      }
      // alloy rim with 6 spokes cut out between them
      spin += '<circle r="37" cx="' + cx + '" cy="' + cy + '" fill="url(#xtRim)" stroke="#5d6473" stroke-width="2.5"/>';
      for (i = 0; i < 6; i++) {
        a = i * Math.PI / 3 + Math.PI / 6;
        spin += '<path d="M' + pt(14, a - 0.32) + ' L' + pt(31, a - 0.36) + ' A31 31 0 0 1 ' + pt(31, a + 0.36) + ' L' + pt(14, a + 0.32) + ' Z" fill="#2a2d35"/>';
      }
      // lug nuts + centre cap
      for (i = 0; i < 6; i++) {
        a = i * Math.PI / 3;
        spin += '<circle cx="' + (cx + Math.cos(a) * 9).toFixed(1) + '" cy="' + (cy + Math.sin(a) * 9).toFixed(1) + '" r="2.4" fill="#8a91a0"/>';
      }
      spin += '<circle cx="' + cx + '" cy="' + cy + '" r="5" fill="#d6202f" stroke="#f2f4f8" stroke-width="1.5"/>';
      var d = document.createElement("div");
      d.className = "xt-wheel";
      d.style.left = ((w[0] - 56) / 12) + "%";
      d.style.top = ((w[1] - 56) / 4.53) + "%";
      d.innerHTML = '<svg viewBox="0 0 112 112">' + spin + '</svg>';
      wheelG.appendChild(d);
      return d;
    });
    var wheelRadiusPx = 82 * TRUCK_W / 1200;

    /* ---------- gifts around the tree ---------- */
    var GIFTS = [
      // x, bottomY, w, h, box, ribbon
      [4, 182, 26, 20, "#d6202f", "#f5c542"],
      [28, 186, 20, 16, "#2f6fd6", "#e8eef8"],
      [50, 184, 30, 22, "#f5c542", "#d6202f"],
      [86, 187, 22, 18, "#8e3fd0", "#ffd84a"],
      [110, 183, 26, 19, "#1f9d55", "#ff4a6b"],
      [134, 186, 16, 14, "#ff7a1a", "#ffffff"]
    ];
    var NEW_GIFT = [150, 190, 22, 17, "#2a9d4b", "#f5c542"];

    // gift x values are drawn for a tree centred at x=80; mirror them around the tree's real centre
    function giftX(g) { return treeCenter - (g[0] + g[2] - 80); }

    function makeGift(g, extra) {
      var d = document.createElement("div");
      d.className = "xt-gift" + (extra ? " " + extra : "");
      d.style.left = giftX(g) + "px";
      d.style.top = (g[1] - g[3]) + "px";
      d.style.width = g[2] + "px";
      d.style.height = g[3] + "px";
      d.style.setProperty("--b", g[4]);
      d.style.setProperty("--r", g[5]);
      giftsLayer.appendChild(d);
      return d;
    }
    function buildGifts() {
      var shown = newGift && newGift.classList.contains("show");
      giftsLayer.innerHTML = "";
      GIFTS.forEach(function (g) { makeGift(g); });
      newGift = makeGift(NEW_GIFT, "new" + (shown ? " show" : ""));
    }

    /* ---------- tree lights ---------- */
    var TREE_LIGHTS = [[52,52],[66,58],[80,50],[40,86],[56,96],[72,98],[90,92],[102,86],[28,126],[44,136],[60,142],[76,142],[92,138],[108,130],[60,74],[74,120]];

    /* ---------- scenery built per width ---------- */
    function buildScene() {
      // the whole scene is scaled to the topbar's height
      var w = root.clientWidth || header.clientWidth, h = root.clientHeight || 104;
      scale = h / STAGE_H;
      SW = Math.ceil(w / scale);
      stage.style.width = SW + "px";
      stage.style.transform = "scale(" + scale + ")";

      // the tree stands just left of the bell/profile area; truck parks to its left with the cab facing it
      var user = header.querySelector(".topbar-user-area");
      var inset = user ? (user.offsetWidth + 24) / scale : 0;
      if (SW - inset < 640) inset = 0;                 // narrow screens: let the tree sit behind the profile pill
      treeCenter = SW - inset - 80;
      tree.style.left = (treeCenter - 66) + "px";
      flash.style.left = treeCenter + "px";
      stopX = treeCenter - 95 - TRUCK_W;               // front bumper stops right at the gifts
      dropX = treeCenter - 116;
      buildGifts();

      // sky
      var sky = document.getElementById("xtSky");
      sky.innerHTML = '<div class="xt-moon" style="left:' + Math.round(SW * 0.3) + 'px"></div>';
      var rnd = seeded(7);
      stars = [];
      for (var i = 0; i < SW / 14; i++) stars.push({ x: rnd() * SW, y: rnd() * 100, ph: rnd() * 6.28 });

      // distant hills, pines, snow banks and road
      var g = document.getElementById("xtGround");
      g.setAttribute("viewBox", "0 0 " + SW + " " + STAGE_H);
      g.setAttribute("width", SW);
      var hills = "M0 130";
      for (var x = 0; x <= SW + 120; x += 120) hills += " Q" + (x + 60) + " " + (112 + (x / 120 % 2) * 10) + " " + (x + 120) + " 130";
      hills += " L" + (SW + 120) + " 230 L0 230 Z";
      var pines = "";
      rnd = seeded(3);
      for (x = 10; x < treeCenter - 100; x += 40 + rnd() * 70) {
        var h = 18 + rnd() * 18, b = 142;
        pines += '<path d="M' + x + ' ' + (b - h) + ' L' + (x + h * 0.38) + ' ' + b + ' L' + (x - h * 0.38) + ' ' + b + ' Z" fill="#4d6ba3"/>';
      }
      var dashes = "";
      for (x = 10; x < SW; x += 46) dashes += '<rect x="' + x + '" y="183" width="22" height="3" rx="1.5" fill="#e8d27a" opacity=".75"/>';
      g.innerHTML =
        '<path d="' + hills + '" fill="#7f9ccc"/>' + pines +
        '<path d="M0 140 Q' + SW * 0.25 + ' 134 ' + SW * 0.5 + ' 141 T' + SW + ' 139 L' + SW + ' 162 L0 162 Z" fill="#e9f1fb"/>' +
        '<rect x="0" y="160" width="' + SW + '" height="50" fill="#5d6577"/>' +
        '<rect x="0" y="160" width="' + SW + '" height="3" fill="#c6d3e6"/>' +
        dashes +
        '<rect x="0" y="208" width="' + SW + '" height="22" fill="#e9f1fb"/>' +
        '<rect x="0" y="207" width="' + SW + '" height="3" fill="#c6d3e6"/>';

      // light strings: far side (behind truck, hanging) and near side (front)
      bulbs = [];
      buildString(document.getElementById("xtFarLights"), -20, treeCenter - 90, 96, 120, 11, 9, false, 0);
      buildString(document.getElementById("xtNearLights"), -20, SW + 40, 110, 211, 7, 7, true, 2);

      // canvases (sharp on retina, but capped at 2x to keep them cheap).
      // The stars and the two light strings only cover a thin band of the scene, so each of
      // those canvases is cut to its band: same drawing, a fraction of the pixels to upload per frame.
      var k = Math.min(2, (window.devicePixelRatio || 1) * scale);
      sizeCanvas(skyCv, SW, STAGE_H, k, 0, 103);                 // stars: y 0..102
      sizeCanvas(farCv, SW, STAGE_H, k, bandOf(false));
      sizeCanvas(nearCv, SW, STAGE_H, k, bandOf(true));
      sizeCanvas(snow.cv, SW, STAGE_H, k);
      sizeCanvas(treeCv, 132, 176, k);
      snow.resize(SW, STAGE_H);
      farCv._dark = nearCv._dark = false;                         // force one redraw after a rebuild
    }

    // vertical extent of what drawBulbs paints for one string: socket (y-3) to the glow (y+5±14)
    function bandOf(near) {
      var lo = STAGE_H, hi = 0;
      for (var i = 0; i < bulbs.length; i++) {
        if (bulbs[i].near !== near) continue;
        lo = Math.min(lo, bulbs[i].y - 10); hi = Math.max(hi, bulbs[i].y + 20);
      }
      return hi > lo ? [Math.max(0, lo - 1), Math.min(STAGE_H, hi + 1)] : [0, 1];
    }

    function buildString(layer, x0, x1, gap, topY, sag, step, near, colorShift) {
      var html = "", wires = "";
      var posts = [];
      for (var x = x0; x <= x1; x += gap) posts.push(x);
      var bulbDefs = [];
      for (var i = 0; i < posts.length - 1; i++) {
        var a = posts[i], b = posts[i + 1];
        wires += "M" + a + " " + topY + " Q" + ((a + b) / 2) + " " + (topY + sag * 2) + " " + b + " " + topY + " ";
        var n = Math.max(2, Math.round(gap / (step * 2.2)));
        for (var k = 1; k < n; k++) {
          var u = k / n;
          var bx = a + (b - a) * u;
          var by = topY + 2 * sag * 2 * u * (1 - u);   // point on the quadratic curve
          bulbDefs.push([bx, by]);
        }
      }
      posts.forEach(function (p) {
        var postTop = topY - 2, postH = near ? 22 : 46;
        html += '<div style="position:absolute;left:' + (p - 1.5) + 'px;top:' + postTop + 'px;width:3px;height:' + postH + 'px;background:#5b4a3a;border-radius:1px"></div>' +
                '<div style="position:absolute;left:' + (p - 4) + 'px;top:' + (postTop - 4) + 'px;width:8px;height:4px;background:#f2f7ff;border-radius:4px 4px 1px 1px"></div>';
      });
      html += '<svg style="position:absolute;left:0;top:0;overflow:visible" width="1" height="1"><path d="' + wires + '" stroke="#1c2230" stroke-width="1.4" fill="none"/></svg>';
      layer.innerHTML = html;
      bulbDefs.forEach(function (d, i) {
        bulbs.push({ x: d[0], y: d[1] + 1, c: (i + colorShift) % LIGHT_COLORS.length, near: near, ph: i * 1.13, on: false, lvl: 0 });
      });
    }

    function seeded(s) { return function () { s = (s * 9301 + 49297) % 233280; return s / 233280; }; }

    /* ---------- snow ---------- */
    var snow = (function () {
      var c = document.getElementById("xtSnow"), ctx = c.getContext("2d"), flakes = [], w = 0, h = 0;
      return {
        cv: c,
        resize: function (W, H) {
          w = W; h = H;
          flakes = [];
          for (var i = 0; i < Math.min(220, W / 7); i++) flakes.push({ x: Math.random() * W, y: Math.random() * H, r: 0.6 + Math.random() * 1.6, v: 8 + Math.random() * 18, p: Math.random() * 6 });
        },
        step: function (dt, t) {
          ctx.clearRect(0, 0, w, h);
          ctx.fillStyle = "rgba(255,255,255,.85)";
          ctx.beginPath();
          for (var i = 0; i < flakes.length; i++) {
            var f = flakes[i];
            f.y += f.v * dt;
            f.x += Math.sin(t * 0.8 + f.p) * 6 * dt;
            if (f.y > h + 3) { f.y = -3; f.x = Math.random() * w; }
            ctx.moveTo(f.x + f.r, f.y);
            ctx.arc(f.x, f.y, f.r, 0, Math.PI * 2);
          }
          ctx.fill();
        }
      };
    })();

    /* ---------- light drawing ---------- */
    var skyCv = document.getElementById("xtSkyCanvas"), farCv = document.getElementById("xtFarCanvas");
    var nearCv = document.getElementById("xtNearCanvas"), treeCv = document.getElementById("xtTreeCanvas");
    var stars = [], twinkle = true, skyTick = 0;

    // band = [top, bottom] in stage px (or top, bottom as two numbers); omitted = the full height.
    // The band's top is snapped to the canvas pixel grid so the crop resamples exactly like the full canvas.
    function sizeCanvas(c, w, h, k, band, bottom) {
      if (typeof band === "number") band = [band, bottom];
      var y0 = band ? Math.floor(band[0] * k) / k : 0;
      var y1 = band ? Math.min(h, Math.ceil(band[1] * k) / k) : h;
      c.width = Math.round(w * k); c.height = Math.max(1, Math.round((y1 - y0) * k));
      c.style.width = w + "px"; c.style.height = (c.height / k) + "px";
      c.style.top = y0 + "px";
      c.getContext("2d").setTransform(k, 0, 0, k, 0, -y0 * k);   // keep drawing in stage coordinates
      c._w = w; c._h = h; c._y0 = y0;
    }

    // one pre-rendered soft glow per colour, stamped with drawImage (much cheaper than shadows)
    var glows = LIGHT_COLORS.map(function (c) {
      var g = document.createElement("canvas"), x = g.getContext("2d");
      var n = parseInt(c[0].slice(1), 16), rgb = (n >> 16) + "," + ((n >> 8) & 255) + "," + (n & 255);
      g.width = g.height = 48;
      var grad = x.createRadialGradient(24, 24, 0, 24, 24, 24);
      grad.addColorStop(0, "rgba(" + rgb + ",.9)");
      grad.addColorStop(.3, "rgba(" + rgb + ",.35)");
      grad.addColorStop(1, "rgba(" + rgb + ",0)");
      x.fillStyle = grad; x.fillRect(0, 0, 48, 48);
      return g;
    });

    function wave(t, w, ph) { return twinkle ? Math.sin(t * w + ph) : 1; }

    function drawBulbs(c, near, t) {
      // a string that is completely dark doesn't change from frame to frame: draw it once and leave it
      var lit = false;
      for (var j = 0; j < bulbs.length; j++) if (bulbs[j].near === near && bulbs[j].lvl > 0) { lit = true; break; }
      if (!lit && c._dark) return;
      c._dark = !lit;
      var ctx = c.getContext("2d");
      ctx.clearRect(0, c._y0, c._w, c._h);
      // sockets + unlit glass never change: blit them from a buffer drawn once per size
      var base = darkBase(c, near);
      ctx.save(); ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.drawImage(base, 0, 0); ctx.restore();
      if (!lit) return;
      var i, b, full = [[], [], [], [], []];
      for (i = 0; i < bulbs.length; i++) {
        b = bulbs[i];
        if (b.near !== near || b.lvl <= 0) continue;
        ctx.globalAlpha = b.lvl * (0.8 + 0.2 * wave(t, 3.93, b.ph));     // ~1.6s twinkle
        ctx.drawImage(glows[b.c], b.x - 14, b.y + 5 - 14, 28, 28);
        if (b.lvl >= 1) { full[b.c].push(b); continue; }                 // fully lit: filled together below
        ctx.globalAlpha = b.lvl;                                          // fading in/out: on its own
        ctx.fillStyle = LIGHT_COLORS[b.c][0];
        ctx.beginPath(); ctx.ellipse(b.x, b.y + 5, 3.5, 5, 0, 0, 6.2832); ctx.fill();
      }
      // fully lit glass: one path per colour instead of one fill per bulb (the bulbs never overlap)
      ctx.globalAlpha = 1;
      for (var ci = 0; ci < full.length; ci++) {
        if (!full[ci].length) continue;
        ctx.fillStyle = LIGHT_COLORS[ci][0];
        ctx.beginPath();
        for (i = 0; i < full[ci].length; i++) {
          b = full[ci][i];
          ctx.moveTo(b.x + 3.5, b.y + 5);
          ctx.ellipse(b.x, b.y + 5, 3.5, 5, 0, 0, 6.2832);
        }
        ctx.fill();
      }
    }

    function darkBase(c, near) {
      if (c._base && c._base.width === c.width && c._base.height === c.height && c._baseFor === bulbs) return c._base;
      var d = c._base || document.createElement("canvas"), x = d.getContext("2d");
      d.width = c.width; d.height = c.height;
      x.setTransform(c.getContext("2d").getTransform());
      for (var i = 0; i < bulbs.length; i++) {
        var b = bulbs[i];
        if (b.near !== near) continue;
        x.fillStyle = "#2a2d36";
        x.fillRect(b.x - 2, b.y - 3, 4, 4);
        x.fillStyle = "#3b3f52";
        x.beginPath(); x.ellipse(b.x, b.y + 5, 3.5, 5, 0, 0, 6.2832); x.fill();
      }
      c._base = d; c._baseFor = bulbs;
      return d;
    }

    function drawTree(t) {
      var ctx = treeCv.getContext("2d");
      ctx.clearRect(0, 0, 132, 176);
      for (var i = 0; i < TREE_LIGHTS.length; i++) {
        var p = TREE_LIGHTS[i], ci = i % LIGHT_COLORS.length;
        ctx.globalAlpha = 0.625 + 0.275 * wave(t, 4.49, i * 2.3);    // ~1.4s twinkle
        ctx.drawImage(glows[ci], p[0] - 7, p[1] - 7, 14, 14);
        ctx.fillStyle = LIGHT_COLORS[ci][0];
        ctx.beginPath(); ctx.arc(p[0], p[1], 2.5, 0, 6.2832); ctx.fill();
      }
      ctx.globalAlpha = 1;
    }

    function drawSky(t) {
      var ctx = skyCv.getContext("2d");
      ctx.clearRect(0, 0, skyCv._w, 103);
      ctx.fillStyle = "#fff";
      for (var i = 0; i < stars.length; i++) {
        ctx.globalAlpha = 0.625 + 0.275 * wave(t, 2.09, stars[i].ph); // ~3s twinkle
        ctx.fillRect(stars[i].x, stars[i].y, 2, 2);
      }
      ctx.globalAlpha = 1;
    }

    // dt undefined = one-off still frame (reduced motion / resize while paused)
    function drawLights(t, dt) {
      for (var i = 0; i < bulbs.length; i++) {
        var b = bulbs[i];
        b.lvl = dt === undefined ? (b.on ? 1 : 0) : clamp(b.lvl + (b.on ? dt : -dt) / 0.35, 0, 1);
      }
      drawBulbs(farCv, false, t);
      drawBulbs(nearCv, true, t);
      drawTree(t);
      if (dt === undefined || ++skyTick % 2 === 0) drawSky(t);    // stars twinkle slowly (3s): 15fps is plenty
    }

    /* ---------- helpers ---------- */
    function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
    function seg(t, a, b) { return clamp((t - a) / (b - a), 0, 1); }
    function easeOut(u) { return 1 - Math.pow(1 - u, 3); }
    function easeIn(u) { return u * u * u; }
    function easeInOut(u) { return u < .5 ? 2 * u * u : 1 - Math.pow(-2 * u + 2, 2) / 2; }

    function sparkle(x, y) {
      for (var i = 0; i < 10; i++) {
        var s = document.createElement("div");
        var a = Math.random() * Math.PI * 2, d = 14 + Math.random() * 18;
        s.className = "xt-spark";
        s.style.left = x + "px"; s.style.top = y + "px";
        s.style.setProperty("--x", Math.cos(a) * d + "px");
        s.style.setProperty("--y", Math.sin(a) * d - 8 + "px");
        stage.appendChild(s);
        setTimeout(s.remove.bind(s), 900);
      }
    }

    /* ---------- main timeline ---------- */
    var time = parseFloat(new URLSearchParams(location.search).get("xmas_t")) || 0;   // ?xmas_t=10 jumps ahead (for testing)
    var last = null, fired = {}, running = false, rafId = 0;
    var stopX, dropX, dropY = 194;                     // truck's parked left edge / where Santa leaves the gift (set in buildScene)

    function frame(now) {
      var dt = last === null ? 0 : Math.min(0.1, (now - last) / 1000);   // real time, so a slow frame doesn't slow the story
      last = now;
      time += dt;
      var t = time;
      var startX = -TRUCK_W - 40;                     // enters from the left, cab first

      // --- truck position
      var tx;
      if (t < T.arrive) tx = startX + (stopX - startX) * easeOut(seg(t, T.driveIn, T.arrive));
      else if (t < T.driveOut) tx = stopX;
      else tx = stopX + (startX - stopX) * easeIn(seg(t, T.driveOut, T.gone));
      var moving = (t < T.arrive - 0.15) || (t > T.driveOut && t < T.gone);
      var bounce = moving ? Math.sin(t * 22) * 0.8 : 0;

      // --- turnaround at the tree: squash to nothing, swap facing, grow back (cab now points home)
      var tu = seg(t, T.turnStart, T.turnEnd);
      var flipped = tu >= 0.5 && t < T.end;
      if (flipped !== isFlipped) { isFlipped = flipped; truck.classList.toggle("flip", flipped); }
      var squash = Math.max(0.03, Math.abs(Math.cos(Math.PI * tu)));
      var lift = Math.sin(Math.PI * tu) * 6;
      // transforms only (composited layers); unchanged values are not rewritten while the truck is parked
      setStyle(truck, "transform", "translate3d(" + tx.toFixed(2) + "px," + (ROAD_Y - TRUCK_H * 428 / 453 + bounce - lift).toFixed(2) + "px,0) scaleX(" + squash.toFixed(3) + ")");
      var ang = (tx / wheelRadiusPx) * 180 / Math.PI * (flipped ? -1 : 1);   // mirrored wheels spin the other way
      var spinCss = "rotate(" + ang.toFixed(1) + "deg)";
      for (var wi = 0; wi < spokeGroups.length; wi++) setStyle(spokeGroups[wi], "transform", spinCss);

      // --- road lights wake up as the truck gets close
      for (var i = 0; i < bulbs.length; i++) {
        var b = bulbs[i];
        if (!b.on && t < T.reset && b.x > tx - 70 && b.x < tx + TRUCK_W + 30) {
          b.on = true;
        }
      }

      // --- star lights up on arrival
      if (t >= T.arrive && !fired.star) {
        fired.star = true;
        tree.classList.add("lit");
        flash.classList.remove("go"); void flash.offsetWidth; flash.classList.add("go");
      }

      // --- Santa in the cab (waves while driving)
      var inCab = t < T.exitCab || t >= T.backAtTruck;
      setStyle(cab, "opacity", inCab ? "1" : "0");
      var waving = (t < T.arrive + 0.4) || (t > T.backAtTruck + 0.3);
      if (inCab) poseSanta(cabSanta, { wave: waving }, t);

      // --- Santa on foot
      var doorX = stopX + TRUCK_W * 0.12, doorY = 224;   // cab is on the left once the truck has turned
      if (!inCab) {
        setStyle(santaEl, "display", "block");
        var sx, sy, o = {}, lean = 0;
        if (t < T.reachTree) {
          var u = easeInOut(seg(t, T.exitCab, T.reachTree));
          sx = doorX + (dropX - doorX) * u; sy = doorY + (dropY - doorY) * u;
          o = { walk: true, carry: true };
        } else if (t < T.dropDone) {
          var k = seg(t, T.reachTree, T.dropDone);
          lean = Math.sin(k * Math.PI) * 14;                // bend down toward the tree on the right
          sx = dropX; sy = dropY;
          o = { carry: k < 0.5 };
          if (k >= 0.5 && !fired.gift) {
            fired.gift = true;
            newGift.classList.add("show");
            sparkle(giftX(NEW_GIFT) + NEW_GIFT[2] / 2, NEW_GIFT[1] - NEW_GIFT[3]);
          }
        } else {
          var v = easeInOut(seg(t, T.dropDone, T.backAtTruck));
          sx = dropX + (doorX - dropX) * v; sy = dropY + (doorY - dropY) * v;
          o = { walk: true, wave: true };
        }
        // lights near Santa's path wake up too
        for (var j = 0; j < bulbs.length; j++) {
          if (!bulbs[j].on && Math.abs(bulbs[j].x - sx) < 90) bulbs[j].on = true;
        }
        var hop = o.walk ? Math.abs(Math.sin(t * 11)) * 2 : 0;
        setStyle(santaEl, "transform", "translate(" + (sx - 23).toFixed(1) + "px," + (sy - 70 - hop).toFixed(1) + "px) rotate(" + lean.toFixed(2) + "deg)");
        poseSanta(walkSanta, o, t);
      } else {
        setStyle(santaEl, "display", "none");
      }

      // --- reset for the next loop: lights go out, star dims, new gift fades
      if (t >= T.reset && !fired.reset) {
        fired.reset = true;
        bulbs.forEach(function (b) {
          setTimeout(function () { b.on = false; }, Math.random() * 1500);
        });
        tree.classList.remove("lit");
        newGift.classList.remove("show");
      }

      if (t >= T.end && LOOP) { time = 0; fired = {}; }

      // The canvases (twinkle, fades, slow snow) are redrawn at ~30fps whatever the display rate;
      // only the truck, wheels and Santa above move every frame. Each canvas redraw has to be
      // re-uploaded to the compositor, which is what made the scene expensive on heavy pages.
      canvasDt += dt;
      if (now - lastCanvas >= CANVAS_MS || lastCanvas === 0) {
        lastCanvas = now;
        drawLights(now / 1000, canvasDt);
        snow.step(canvasDt, now / 1000);
        canvasDt = 0;
      }
      rafId = running ? requestAnimationFrame(frame) : 0;
    }
    var CANVAS_MS = 1000 / 30 - 4, lastCanvas = 0, canvasDt = 0, isFlipped = false;

    /* ---------- static scene for reduced-motion users ---------- */
    function staticScene() {
      truck.style.transform = "translate(" + stopX + "px," + (ROAD_Y - TRUCK_H * 428 / 453) + "px)";
      twinkle = false;
      bulbs.forEach(function (b) { b.on = true; });
      drawLights(0);
      snow.step(0, 0);
      tree.classList.add("lit");
      newGift.classList.add("show");
      poseSanta(cabSanta, { wave: true }, 0.2);
    }

    truck.style.width = TRUCK_W + "px";
    truck.style.height = TRUCK_H + "px";
    buildScene();

    // rebuild when the topbar changes size (window resize, sidebar collapse, ...)
    var resizeTimer, lastSize = root.clientWidth + "x" + root.clientHeight;
    var onResize = function () {
      var size = root.clientWidth + "x" + root.clientHeight;
      if (size === lastSize) return;
      lastSize = size;
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        var lit = bulbs.length && bulbs.every(function (b) { return b.on; });
        buildScene();
        if (reduced || lit) bulbs.forEach(function (b) { b.on = true; });
        if (!running) drawLights(0);
      }, 150);
    };
    if ("ResizeObserver" in window) new ResizeObserver(onResize).observe(root);
    else window.addEventListener("resize", onResize);

    var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduced) staticScene();
    else {
      // only animate while the topbar is on screen and the tab is visible
      var onScreen = true;
      var update = function () {
        var want = onScreen && !document.hidden;
        if (want && !running) { running = true; last = null; rafId = requestAnimationFrame(frame); }
        else if (!want && running) { running = false; cancelAnimationFrame(rafId); }
      };
      if ("IntersectionObserver" in window) {
        new IntersectionObserver(function (e) { onScreen = e[0].isIntersecting; update(); }).observe(root);
      }
      document.addEventListener("visibilitychange", update);
      update();
    }
  }

  function boot() {
    var header = document.querySelector("[data-xmas-topbar]");
    if (header && !header.classList.contains("xt-festive")) init(header);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();

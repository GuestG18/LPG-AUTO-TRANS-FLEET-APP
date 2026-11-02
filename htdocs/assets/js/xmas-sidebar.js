/* Christmas lights down the right edge of the sidebar.
   A string of bulbs lying sideways on a wavy wire; they switch on top-to-bottom each time the
   sidebar slides in, then twinkle. Drawn on one small canvas and paused while the sidebar is hidden. */
(function () {
  "use strict";

  var COLORS = ["#ff3b3b", "#3dff7a", "#ffd23b", "#4aa8ff", "#ff7be0"];
  var STRIP_W = 16;           // the sidebar's right padding
  var GAP = 30;               // px between bulbs
  var LIGHT_UP = 1.2;         // seconds for the top-to-bottom switch-on

  function init(sidebar) {
    var strip = document.createElement("div");
    strip.className = "xs-lights";
    strip.setAttribute("aria-hidden", "true");
    var cv = document.createElement("canvas");
    strip.appendChild(cv);
    sidebar.insertBefore(strip, sidebar.firstChild);
    var ctx = cv.getContext("2d");

    // one pre-rendered glow per colour
    var glows = COLORS.map(function (c) {
      var g = document.createElement("canvas"), x = g.getContext("2d");
      var n = parseInt(c.slice(1), 16), rgb = (n >> 16) + "," + ((n >> 8) & 255) + "," + (n & 255);
      g.width = g.height = 48;
      var grad = x.createRadialGradient(24, 24, 0, 24, 24, 24);
      grad.addColorStop(0, "rgba(" + rgb + ",.9)");
      grad.addColorStop(.3, "rgba(" + rgb + ",.35)");
      grad.addColorStop(1, "rgba(" + rgb + ",0)");
      x.fillStyle = grad; x.fillRect(0, 0, 48, 48);
      return g;
    });

    var H = 0, bulbs = [];
    var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    function wireX(y) { return STRIP_W / 2 + Math.sin(y / 38) * 2.5; }

    function size() {
      H = sidebar.clientHeight || window.innerHeight;
      var k = Math.min(2, window.devicePixelRatio || 1);
      cv.width = Math.round(STRIP_W * k); cv.height = Math.round(H * k);
      cv.style.width = STRIP_W + "px"; cv.style.height = H + "px";
      ctx.setTransform(k, 0, 0, k, 0, 0);
      bulbs = [];
      for (var y = 22, i = 0; y < H - 10; y += GAP, i++) {
        bulbs.push({ y: y, side: i % 2 ? 1 : -1, c: i % COLORS.length, ph: i * 1.37 });
      }
    }

    function draw(t, since) {
      ctx.clearRect(0, 0, STRIP_W, H);
      // wire
      ctx.strokeStyle = "#0b0f19";
      ctx.lineWidth = 1.4;
      ctx.beginPath();
      for (var y = 0; y <= H; y += 6) {
        if (y === 0) ctx.moveTo(wireX(y), y); else ctx.lineTo(wireX(y), y);
      }
      ctx.stroke();

      for (var i = 0; i < bulbs.length; i++) {
        var b = bulbs[i], wx = wireX(b.y);
        var bx = wx + b.side * 5.5;          // bulb lies sideways, pointing away from the wire
        // switch-on sweeps down the sidebar
        var lvl = reduced ? 1 : Math.max(0, Math.min(1, (since - (b.y / H) * LIGHT_UP) / 0.25));
        ctx.globalAlpha = 1;
        ctx.fillStyle = "#2a2d36";
        ctx.fillRect(wx + (b.side > 0 ? 0 : -3), b.y - 2, 3, 4);         // socket
        ctx.fillStyle = "#3b3f52";
        ctx.beginPath(); ctx.ellipse(bx, b.y, 4.2, 3, 0, 0, 6.2832); ctx.fill();
        if (lvl > 0) {
          var tw = reduced ? 1 : 0.8 + 0.2 * Math.sin(t * 3.93 + b.ph);
          ctx.globalAlpha = lvl * tw;
          ctx.drawImage(glows[b.c], bx - 12, b.y - 12, 24, 24);
          ctx.globalAlpha = lvl;
          ctx.fillStyle = COLORS[b.c];
          ctx.beginPath(); ctx.ellipse(bx, b.y, 4.2, 3, 0, 0, 6.2832); ctx.fill();
        }
      }
      ctx.globalAlpha = 1;
    }

    size();

    if (reduced) {
      draw(0, 99);
      window.addEventListener("resize", function () { size(); draw(0, 99); });
      return;
    }

    // animate only while the sidebar is actually on screen
    var running = false, raf = 0, shownAt = 0, tick = 0;
    function frame(now) {
      raf = requestAnimationFrame(frame);
      if (++tick % 2) return;                          // 30fps is plenty for a twinkle
      draw(now / 1000, (now - shownAt) / 1000);
    }
    function setVisible(v) {
      if (v && !running) {
        running = true; shownAt = performance.now(); raf = requestAnimationFrame(frame);
      } else if (!v && running) {
        running = false; cancelAnimationFrame(raf);
        draw(0, -1);                                   // reset to dark so they light up again next time
      }
    }
    var onScreen = true;
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(function (e) { onScreen = e[0].isIntersecting; setVisible(onScreen && !document.hidden); }).observe(cv);
    }
    document.addEventListener("visibilitychange", function () { setVisible(onScreen && !document.hidden); });
    if ("ResizeObserver" in window) new ResizeObserver(function () { size(); }).observe(sidebar);
    setVisible(!document.hidden);
  }

  function boot() {
    var sidebar = document.querySelector(".sidebar");
    if (sidebar && !sidebar.querySelector(".xs-lights")) init(sidebar);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();

/*!
 * Darčekový poradca – widget
 *
 * Vloženie na web (jeden riadok, napr. do hlavičky alebo pätičky šablóny):
 *   <script src="https://poradca.kliky.sk/poradca/widget/poradca.js" async></script>
 *
 * Všetko ostatné (umiestnenie, farby, na ktorých stránkach sa zobrazí, texty) sa nastavuje v admine.
 * Voliteľné atribúty (prepíšu admin): data-cart="real|demo|off", data-api="https://…/poradca/api"
 *
 * JavaScript API: DarcekovyPoradca.open(), .close(), .ask("darček pre 8-ročné dievča"), .reset()
 */
(function () {
  "use strict";
  if (window.DarcekovyPoradca) return;

  var script = document.currentScript || document.querySelector('script[src*="poradca.js"]');
  var src = script ? script.src : "";
  var API = (script && script.getAttribute("data-api")) || (src ? src.replace(/\/widget\/poradca\.js.*$/, "/api") : "/poradca/api");
  API = API.replace(/\/$/, "");
  var STORE_KEY = "dp_conv_v1", TEASER_KEY = "dp_teaser_v1";

  var cfg = null, D = null;
  var state = { id: null, items: [] };
  var busy = false, opened = false, rated = false, cartMode = "off";
  var fab, pn, log, quick, form, input, sendBtn;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function save() { try { sessionStorage.setItem(STORE_KEY, JSON.stringify(state)); } catch (e) {} }
  function load() { try { var s = JSON.parse(sessionStorage.getItem(STORE_KEY) || "null"); if (s && s.items) state = s; } catch (e) {} }
  function post(path, body) { return fetch(API + path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }); }
  function track(type, productId) {
    (window.dataLayer = window.dataLayer || []).push({ event: "poradca_" + type, poradca_produkt: productId || "" });
    if (!state.id) return;
    post("/event.php", { conversation_id: state.id, type: type, product_id: productId || "" }).catch(function () {});
  }
  function fmt(p) { return Number(p).toFixed(2).replace(".", ",") + " €"; }
  function safeColor(c, d) { return /^#[0-9a-f]{3,8}$/i.test(c || "") ? c : d; }

  /* ---------- na ktorých stránkach sa má zobraziť ---------- */
  function pathMatches(path, patterns) {
    return (patterns || []).some(function (p) {
      p = String(p).trim(); if (!p) return false;
      try { p = new URL(p, location.origin).pathname + (p.indexOf("?") > -1 ? p.slice(p.indexOf("?")) : ""); } catch (e) {}
      var re = new RegExp("^" + p.replace(/[.+^${}()|[\]\\]/g, "\\$&").replace(/\*/g, ".*").replace(/\?/g, "\\?") + "/?$", "i");
      return re.test(path) || re.test(path + location.search);
    });
  }
  function pageAllowed(mode, list) {
    var path = location.pathname || "/";
    if (mode === "home") return path === "/" || /^\/(index\.(php|html?))?$/.test(path);
    if (mode === "list") return pathMatches(path, list);
    return true;
  }
  function deviceAllowed(dev) {
    var mobile = window.matchMedia && window.matchMedia("(max-width: 768px)").matches;
    return dev === "mobile" ? mobile : dev === "desktop" ? !mobile : true;
  }

  /* ---------- štýly ---------- */
  function css() {
    var pos = D.position, ox = Math.max(0, D.offsetX | 0), oy = Math.max(0, D.offsetY | 0);
    var top = pos.indexOf("top") > -1, left = pos.indexOf("left") > -1;
    var h = left ? "left:" + ox + "px;" : "right:" + ox + "px;";
    var fabV = top ? "top:" + oy + "px;" : "bottom:" + oy + "px;";
    var pnV = top ? "top:" + (oy + 64) + "px;" : "bottom:" + (oy + 68) + "px;";
    return '' +
      '.dp-root{--dpm:' + safeColor(D.colorMain, "#22c4b2") + ';--dpa:' + safeColor(D.colorAccent, "#ef7b84") + ';font-family:Poppins,system-ui,-apple-system,"Segoe UI",sans-serif}' +
      '.dp-root *{box-sizing:border-box}' +
      '.dp-fab{position:fixed;' + h + fabV + 'z-index:2147483000;display:flex;align-items:center;gap:9px;background:var(--dpa);color:#fff;border:0;border-radius:999px;padding:13px 20px 13px 15px;font:600 14.5px Poppins,system-ui,sans-serif;box-shadow:0 12px 30px -8px rgba(0,0,0,.35);cursor:pointer;transition:transform .15s}' +
      '.dp-fab:hover{transform:translateY(-2px)}.dp-fab .e{font-size:20px;line-height:1}' +
      '.dp-teaser{position:fixed;' + h + (top ? "top:" + (oy + 62) : "bottom:" + (oy + 64)) + 'px;z-index:2147483000;background:#fff;color:#4f473d;border-radius:14px;padding:10px 32px 10px 14px;font:500 13.5px Poppins,system-ui,sans-serif;box-shadow:0 12px 30px -10px rgba(0,0,0,.35);max-width:260px;cursor:pointer;animation:dpup .25s ease-out}' +
      '.dp-teaser button{position:absolute;right:6px;top:4px;border:0;background:none;color:#a59c90;font-size:16px;cursor:pointer}' +
      '.dp-pn{position:fixed;' + h + pnV + 'z-index:2147483001;width:400px;max-width:calc(100vw - 24px);height:640px;max-height:calc(100vh - ' + (oy + 90) + 'px);background:#fff;border-radius:18px;box-shadow:0 30px 70px -20px rgba(60,40,30,.45);display:none;flex-direction:column;overflow:hidden;border:1px solid #eee9e3;font-size:14px;line-height:1.5;color:#685f52}' +
      '.dp-pn.open{display:flex;animation:dpup .2s ease-out}@keyframes dpup{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}' +
      '.dp-h{background:var(--dpm);color:#fff;padding:14px 16px;display:flex;align-items:center;gap:12px}.dp-h .av{width:40px;height:40px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:21px;flex:none}' +
      '.dp-h b{font-weight:600;font-size:15.5px;display:block}.dp-h small{font-size:12px;opacity:.9}.dp-h .acts{margin-left:auto;display:flex;gap:2px}' +
      '.dp-h button{background:none;border:0;color:#fff;cursor:pointer;font:inherit;font-size:13px;padding:6px 8px;border-radius:6px}.dp-h button:hover{background:rgba(255,255,255,.18)}' +
      '.dp-log{flex:1;overflow-y:auto;padding:16px 14px;display:flex;flex-direction:column;gap:10px;background:#fdfbf9}' +
      '.dp-m{max-width:86%;padding:10px 14px;border-radius:14px;font-size:13.5px;white-space:pre-wrap;word-wrap:break-word}' +
      '.dp-bot{background:#fff;border:1px solid #eee9e3;align-self:flex-start;border-bottom-left-radius:4px}.dp-user{background:var(--dpa);color:#fff;align-self:flex-end;border-bottom-right-radius:4px}.dp-err{background:#fdeceb;color:#a33c37;align-self:flex-start}' +
      '.dp-dots{align-self:flex-start;background:#fff;border:1px solid #eee9e3;border-radius:14px;padding:12px 16px;display:flex;gap:4px}.dp-dots i{width:6px;height:6px;border-radius:50%;background:#c9bfb5;animation:dpb 1s infinite}.dp-dots i:nth-child(2){animation-delay:.15s}.dp-dots i:nth-child(3){animation-delay:.3s}@keyframes dpb{0%,60%,100%{opacity:.35}30%{opacity:1}}' +
      '.dp-rc{display:flex;gap:12px;background:#fff;border:1px solid #eee9e3;border-radius:12px;padding:10px;text-decoration:none;color:inherit}.dp-rc:hover{border-color:var(--dpm)}' +
      '.dp-rc img{width:84px;height:84px;object-fit:contain;flex:none;border-radius:8px;background:#fff;overflow:hidden;color:transparent}.dp-rc .t{font-weight:600;font-size:13px;line-height:1.35;color:#4f473d}.dp-rc .w{font-size:12.5px;line-height:1.45;margin:4px 0 8px}' +
      '.dp-rc .row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.dp-rc .p{color:var(--dpa);font-weight:700;font-size:14px}.dp-rc .av2{font-size:11.5px;color:var(--dpm)}' +
      '.dp-rc .kup{background:var(--dpa);color:#fff;border:0;border-radius:4px;padding:4px 9px;font:500 12px Poppins,system-ui,sans-serif;cursor:pointer}.dp-rc .kup.ok{background:var(--dpm)}' +
      '.dp-q{display:flex;flex-wrap:wrap;gap:6px;padding:8px 14px 4px;background:#fdfbf9}.dp-chip{background:#fff;border:1px solid var(--dpm);color:var(--dpm);border-radius:999px;padding:5px 12px;font:500 12.5px Poppins,system-ui,sans-serif;cursor:pointer}.dp-chip:hover{background:var(--dpm);color:#fff}' +
      '.dp-rate{align-self:flex-start;font-size:12px;color:#8a8175;display:flex;gap:6px;align-items:center}.dp-rate button{border:1px solid #eee9e3;background:#fff;border-radius:999px;padding:2px 9px;cursor:pointer;font-size:13px}' +
      '.dp-f{display:flex;gap:8px;padding:10px 12px 12px;border-top:1px solid #eee9e3;background:#fff}.dp-f input{flex:1;min-width:0;border:1px solid #e6d9d2;border-radius:999px;padding:10px 14px;font:13.5px Poppins,system-ui,sans-serif;color:#685f52;outline:none}.dp-f input:focus{border-color:var(--dpa)}' +
      '.dp-f button{flex:none;background:var(--dpa);color:#fff;border:0;border-radius:999px;padding:0 16px;font:600 13px Poppins,system-ui,sans-serif;cursor:pointer}.dp-f button:disabled{opacity:.5}' +
      '.dp-note{font-size:11px;color:#a59c90;text-align:center;padding:0 12px 8px;background:#fff}' +
      /* vložený blok „Neviete, čo darovať?“ */
      '.dp-inline{max-width:1040px;margin:40px auto 10px;border-radius:18px;background:linear-gradient(105deg,#fff4f4 0%,#ecfbf9 100%);border:1px solid #f4dcdc;padding:28px 34px;display:grid;grid-template-columns:auto 1fr;gap:26px;align-items:center;color:#685f52}' +
      '.dp-inline .ico{width:88px;height:88px;border-radius:50%;background:#fff;display:grid;place-items:center;font-size:44px;box-shadow:0 8px 24px -10px rgba(0,0,0,.25)}' +
      '.dp-inline h2{margin:0;color:var(--dpm);font-weight:600;font-size:24px;line-height:1.3}.dp-inline p{margin:4px 0 14px;font-size:14px}' +
      '.dp-inline form{display:flex;gap:10px;max-width:620px}.dp-inline input{flex:1;min-width:0;border:1px solid #e6d9d2;background:#fff;border-radius:6px;padding:12px 14px;font:14px Poppins,system-ui,sans-serif;color:#685f52;outline:none}' +
      '.dp-inline .go{background:var(--dpa);color:#fff;border:0;border-radius:6px;padding:0 20px;font:600 14px Poppins,system-ui,sans-serif;cursor:pointer}.dp-inline .chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}' +
      '@media(max-width:760px){.dp-inline{grid-template-columns:1fr;padding:22px;margin:24px 12px}.dp-inline .ico{width:64px;height:64px;font-size:32px}}' +
      '@media(max-width:480px){.dp-pn{left:0!important;right:0!important;top:0!important;bottom:0!important;width:100vw;max-width:100vw;height:100%;max-height:100%;border-radius:0}.dp-teaser{max-width:calc(100vw - 30px)}}';
  }

  /* ---------- chat ---------- */
  function scroll() { log.scrollTop = log.scrollHeight; }
  function chips(target, items, cls) {
    target.innerHTML = "";
    (items || []).slice(0, 4).forEach(function (t) { var b = el("button", cls || "dp-chip", t); b.type = "button"; b.onclick = function () { ask(t); }; target.appendChild(b); });
  }
  function renderPicks(picks) {
    picks.forEach(function (p) {
      var a = el("a", "dp-rc"); a.href = p.url; a.target = "_blank"; a.rel = "noopener";
      if (p.image) { var img = el("img"); img.src = p.image; img.alt = p.name; img.loading = "lazy"; a.appendChild(img); }
      var d = el("div"); d.style.minWidth = "0";
      d.appendChild(el("div", "t", p.name)); if (p.why) d.appendChild(el("div", "w", p.why));
      var row = el("div", "row"); row.appendChild(el("span", "p", fmt(p.price)));
      if (cartMode !== "off") {
        var k = el("button", "kup", "🛍 do košíka"); k.type = "button";
        k.onclick = function (e) { e.preventDefault(); e.stopPropagation(); addToCart(p, k); };
        row.appendChild(k);
      }
      if (p.availability) row.appendChild(el("span", "av2", p.availability));
      d.appendChild(row); a.appendChild(d);
      a.addEventListener("click", function () { track("click", p.id); });
      log.appendChild(a);
    });
  }
  function addToCart(p, btn) {
    if (cartMode === "demo") {
      btn.textContent = "✓ v košíku"; btn.className = "kup ok"; track("add_to_cart", p.id);
      document.dispatchEvent(new CustomEvent("poradca:cart", { detail: p })); return;
    }
    // Skutočný košík e-shopu – rovnaký formulár ako tlačidlo „kúpiť“ na webe
    var fd = new FormData(); fd.append("add-to-basket", "kúpiť"); fd.append("quantity", "1"); fd.append("product-id", p.id);
    btn.disabled = true;
    fetch("/kosik", { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) {
      if (!r.ok) throw new Error();
      btn.textContent = "✓ v košíku"; btn.className = "kup ok"; track("add_to_cart", p.id);
      document.dispatchEvent(new CustomEvent("poradca:cart", { detail: p }));
    }).catch(function () { btn.disabled = false; window.location.href = p.url; });
  }
  function renderAll() {
    log.innerHTML = "";
    log.appendChild(el("div", "dp-m dp-bot", cfg.greeting));
    state.items.forEach(function (it) {
      if (it.t === "user") log.appendChild(el("div", "dp-m dp-user", it.text));
      if (it.t === "bot") log.appendChild(el("div", "dp-m dp-bot", it.text));
      if (it.t === "err") log.appendChild(el("div", "dp-m dp-err", it.text));
      if (it.t === "picks") renderPicks(it.picks);
    });
    var last = state.items[state.items.length - 1];
    chips(quick, last ? last.quick : cfg.quick);
    if (!rated && state.items.some(function (i) { return i.t === "picks"; })) addRating();
    scroll();
  }
  function addRating() {
    var r = el("div", "dp-rate"); r.appendChild(document.createTextNode("Pomohol som vám?"));
    var up = el("button", "", "👍"), down = el("button", "", "👎"); up.type = down.type = "button";
    up.onclick = function () { rated = true; track("rating_up"); r.textContent = "Ďakujem! 🎄"; };
    down.onclick = function () { rated = true; track("rating_down"); r.textContent = "Ďakujem, budeme sa zlepšovať."; };
    r.appendChild(up); r.appendChild(down); log.appendChild(r);
  }
  function ask(text) {
    text = (text || "").trim(); if (!text || busy || !pn) return;
    openPanel();
    busy = true; sendBtn.disabled = true; input.value = ""; quick.innerHTML = "";
    state.items.push({ t: "user", text: text }); save();
    log.appendChild(el("div", "dp-m dp-user", text));
    var dots = el("div", "dp-dots"); dots.innerHTML = "<i></i><i></i><i></i>"; log.appendChild(dots); scroll();
    post("/chat.php", { conversation_id: state.id, message: text, page: location.href })
      .then(function (r) { if (r.status === 429) throw new Error("rate"); if (!r.ok) throw new Error("http"); return r.json(); })
      .then(function (d) {
        state.id = d.conversation_id;
        state.items.push({ t: "bot", text: d.reply, quick: d.quick });
        if (d.picks && d.picks.length) state.items.push({ t: "picks", picks: d.picks, quick: d.quick });
        save(); renderAll();
      })
      .catch(function (e) {
        state.items.push({ t: "err", text: e.message === "rate" ? "Veľa otázok naraz, skúste to prosím o chvíľu." : "Ospravedlňujem sa, niečo sa pokazilo. Skúste to prosím znova.", quick: [text] });
        save(); renderAll();
      })
      .then(function () { busy = false; sendBtn.disabled = false; input.focus(); });
  }
  function openPanel() {
    hideTeaser();
    if (!pn.classList.contains("open")) {
      pn.classList.add("open");
      if (!opened) { opened = true; renderAll(); track("open"); }
    }
    setTimeout(function () { input.focus(); }, 50);
  }
  function closePanel() { pn.classList.remove("open"); }
  function reset() { state = { id: null, items: [] }; rated = false; save(); renderAll(); }

  var teaser = null;
  function hideTeaser() { if (teaser) { teaser.remove(); teaser = null; } try { sessionStorage.setItem(TEASER_KEY, "1"); } catch (e) {} }
  function showTeaser(root) {
    try { if (sessionStorage.getItem(TEASER_KEY)) return; } catch (e) {}
    if (pn.classList.contains("open") || state.items.length) return;
    teaser = el("div", "dp-teaser", D.teaserText);
    var x = el("button", "", "×"); x.type = "button"; x.setAttribute("aria-label", "Zavrieť");
    x.onclick = function (e) { e.stopPropagation(); hideTeaser(); };
    teaser.appendChild(x); teaser.onclick = openPanel; root.appendChild(teaser);
  }

  /* ---------- vložený blok do stránky ---------- */
  function mountInline(root) {
    var I = D.inline;
    if (!I || !I.enabled || !pageAllowed(I.pagesMode, I.pages)) return;
    var anchor = null;
    try { anchor = I.selector ? document.querySelector(I.selector) : null; } catch (e) { anchor = null; }
    if (!anchor) return;
    var box = el("section", "dp-root dp-inline");
    box.innerHTML = '<div class="ico" aria-hidden="true">🎁</div><div><h2></h2><p></p><form autocomplete="off"><input aria-label="Pre koho hľadáte darček" placeholder="Napr. darček pre 8-ročné dievča, ktoré rado tvorí…"><button class="go">Poradiť</button></form><div class="chips"></div></div>';
    box.querySelector("h2").textContent = I.title; box.querySelector("p").textContent = I.text;
    chips(box.querySelector(".chips"), cfg.quick);
    box.querySelector("form").addEventListener("submit", function (e) { e.preventDefault(); var i = box.querySelector("input"); var v = i.value; i.value = ""; ask(v); });
    var where = { before: "beforebegin", after: "afterend", prepend: "afterbegin", append: "beforeend" }[I.where] || "beforebegin";
    anchor.insertAdjacentElement(where, box);
  }

  function mount() {
    var root = el("div", "dp-root");
    var st = el("style"); st.textContent = css(); document.head.appendChild(st);
    pn = el("div", "dp-pn"); pn.setAttribute("role", "dialog"); pn.setAttribute("aria-label", cfg.name);
    pn.innerHTML = '<div class="dp-h"><div class="av" aria-hidden="true">🎁</div><div><b class="nm"></b><small>Vyberiem darček z našej ponuky</small></div>' +
      '<div class="acts"><button type="button" class="rs" title="Začať odznova">↺</button><button type="button" class="cl" title="Zavrieť" style="font-size:18px">×</button></div></div>' +
      '<div class="dp-log" aria-live="polite"></div><div class="dp-q"></div>' +
      '<form class="dp-f" autocomplete="off"><input placeholder="Pre koho hľadáte darček?" aria-label="Správa" maxlength="500"><button>Poslať</button></form>' +
      '<div class="dp-note">Odpovede pripravuje umelá inteligencia. Konverzácie ukladáme anonymne na zlepšovanie poradcu.</div>';
    pn.querySelector(".nm").textContent = cfg.name;
    log = pn.querySelector(".dp-log"); quick = pn.querySelector(".dp-q"); form = pn.querySelector("form"); input = form.querySelector("input"); sendBtn = form.querySelector("button");
    pn.querySelector(".cl").onclick = closePanel;
    pn.querySelector(".rs").onclick = reset;
    form.addEventListener("submit", function (e) { e.preventDefault(); ask(input.value); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && pn.classList.contains("open")) closePanel(); });
    root.appendChild(pn);
    if (D.position !== "none") {
      fab = el("button", "dp-fab"); fab.type = "button"; fab.setAttribute("aria-label", cfg.name);
      fab.innerHTML = '<span class="e">🎁</span><span class="l"></span>'; fab.querySelector(".l").textContent = D.buttonText || cfg.name;
      fab.onclick = function () { pn.classList.contains("open") ? closePanel() : openPanel(); };
      root.appendChild(fab);
      if (D.teaserDelay > 0 && D.teaserText) setTimeout(function () { showTeaser(root); }, D.teaserDelay * 1000);
    }
    document.body.appendChild(root);
    mountInline(root);
  }

  window.DarcekovyPoradca = { open: function () { if (pn) openPanel(); }, close: function () { if (pn) closePanel(); }, ask: ask, reset: function () { if (pn) reset(); } };

  function start() {
    fetch(API + "/config.php").then(function (r) { return r.json(); }).then(function (c) {
      if (!c || !c.enabled) return;
      cfg = c; D = c.display || {};
      var attrCart = script && script.getAttribute("data-cart");
      cartMode = attrCart || D.cart || "off";
      if (!deviceAllowed(D.device)) return;
      var onPage = pageAllowed(D.pagesMode, D.pages) && !pathMatches(location.pathname || "/", D.exclude);
      var inlineHere = D.inline && D.inline.enabled && pageAllowed(D.inline.pagesMode, D.inline.pages);
      if (!onPage && !inlineHere) return;
      if (!onPage) D.position = "none"; // na tejto stránke len vložený blok, bez plávajúceho tlačidla
      load();
      mount();
    }).catch(function () {});
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start); else start();
})();

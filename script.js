(function () {
  "use strict";

  var DATA_URL = "api.php";
  var QR_ICON = "M3 11h8V3H3v8zm2-6h4v4H5V5zM3 21h8v-8H3v8zm2-6h4v4H5v-4zm8-12v8h8V3h-8zm6 6h-4V5h4v4zm0 10h2v2h-2zM13 13h2v2h-2zm2 2h2v2h-2zm-2 2h2v2h-2zm2 2h2v2h-2zm2-2h2v2h-2zm0-4h2v2h-2zm2 2h2v2h-2z";
  var SVG_NS = "http://www.w3.org/2000/svg";

  var $ = function (id) { return document.getElementById(id); };

  /* ---------- Theme ---------- */
  var root = document.documentElement;
  var toggle = $("themeToggle");

  function syncToggleLabel() {
    var dark = root.dataset.theme === "dark";
    toggle.setAttribute("aria-label", dark ? "Switch to light mode" : "Switch to dark mode");
  }

  toggle.addEventListener("click", function () {
    var next = root.dataset.theme === "dark" ? "light" : "dark";
    root.dataset.theme = next;
    try { localStorage.setItem("theme", next); } catch (e) {}
    syncToggleLabel();
  });
  syncToggleLabel();

  /* ---------- Helpers ---------- */
  function icon(path) {
    var svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    var p = document.createElementNS(SVG_NS, "path");
    p.setAttribute("d", path);
    svg.appendChild(p);
    return svg;
  }

  function subtitle(link) {
    if (link.description) return link.description;
    try {
      var u = new URL(link.url);
      if (u.protocol === "mailto:") return u.pathname;
      return (u.hostname + u.pathname).replace(/^www\./, "").replace(/\/$/, "");
    } catch (e) {
      return link.url;
    }
  }

  function isSafeUrl(url) {
    return /^(https?:|mailto:|tel:)/i.test(url);
  }

  /* ---------- Page ---------- */
  function render(data) {
    var name = data.name || "";
    document.title = data.title || name || "Links";
    $("name").textContent = name;
    $("bio").textContent = data.description || "";
    $("bio").hidden = !data.description;

    var avatar = $("avatar");
    if (data.image) {
      avatar.src = data.image;
      avatar.alt = name ? "Photo of " + name : "Profile photo";
      $("imgFull").src = data.image;
      $("imgFull").alt = avatar.alt;
    } else {
      $("avatarBtn").hidden = true;
    }

    var list = $("links");
    list.textContent = "";

    (data.links || []).forEach(function (link) {
      if (!link || !link.url || !isSafeUrl(link.url)) return;

      // Phone links: keep only the + and digits, so hidden characters can't end up in the dialer.
      if (/^tel:/i.test(link.url)) {
        link = Object.assign({}, link, { url: "tel:" + link.url.slice(4).replace(/[^\d+]/g, "") });
      }

      var li = document.createElement("li");
      li.className = "row";

      var a = document.createElement("a");
      a.className = "link";
      a.href = link.url;
      if (/^https?:/i.test(link.url)) {
        a.target = "_blank";
        a.rel = "noopener noreferrer";
      }

      var key = (link.platform || "").toLowerCase();
      a.appendChild(icon(ICONS[key] || ICONS.link));

      var text = document.createElement("span");
      text.className = "link-text";
      var label = document.createElement("span");
      label.className = "link-label";
      label.textContent = link.label || link.platform || link.url;
      var sub = document.createElement("span");
      sub.className = "link-sub";
      sub.textContent = subtitle(link);
      text.appendChild(label);
      text.appendChild(sub);
      a.appendChild(text);

      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "qr-btn";
      btn.setAttribute("aria-label", "Show QR code for " + label.textContent);
      btn.appendChild(icon(QR_ICON));
      btn.addEventListener("click", function () {
        openQr(label.textContent, link.url);
      });

      li.appendChild(a);
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  /* ---------- QR dialog ---------- */
  var dialog = $("qrDialog");
  var copyBtn = $("qrCopy");
  var currentUrl = "";

  function qrSvg(text) {
    var qr = qrcode(0, "M");
    qr.addData(text);
    qr.make();
    var n = qr.getModuleCount();
    var d = "";
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c)) d += "M" + c + " " + r + "h1v1h-1z";
      }
    }
    var margin = 0;
    return '<svg xmlns="' + SVG_NS + '" viewBox="' + margin + " " + margin + " " + n + " " + n +
      '" shape-rendering="crispEdges" role="img" aria-label="QR code"><path d="' + d + '" fill="#000"/></svg>';
  }

  function openQr(title, url) {
    currentUrl = url;
    $("qrTitle").textContent = title;
    $("qrUrl").textContent = url.replace(/^(mailto|tel):/i, "");
    $("qrCode").innerHTML = qrSvg(url);
    copyBtn.textContent = "Copy link";
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  $("qrClose").addEventListener("click", function () { dialog.close(); });

  dialog.addEventListener("click", function (e) {
    if (e.target === dialog) dialog.close();
  });

  copyBtn.addEventListener("click", function () {
    function done() {
      copyBtn.textContent = "Copied";
      setTimeout(function () { copyBtn.textContent = "Copy link"; }, 1600);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(currentUrl).then(done, function () {});
    }
  });

  /* ---------- Image viewer ---------- */
  var imgDialog = $("imgDialog");

  $("avatarBtn").addEventListener("click", function () {
    if (typeof imgDialog.showModal === "function") imgDialog.showModal();
    else imgDialog.setAttribute("open", "");
  });

  $("imgClose").addEventListener("click", function () { imgDialog.close(); });

  // Click anywhere outside the photo to close.
  imgDialog.addEventListener("click", function (e) {
    if (e.target !== $("imgFull") && e.target !== $("imgClose")) imgDialog.close();
  });

  /* ---------- Load ---------- */
  fetch(DATA_URL, { cache: "no-cache" })
    .then(function (res) {
      if (!res.ok) throw new Error("HTTP " + res.status);
      return res.json();
    })
    .then(render)
    .catch(function () {
      var err = $("error");
      err.hidden = false;
      err.textContent = "Could not load " + DATA_URL + ". If you opened this file directly, serve the folder through a local web server (for example XAMPP) instead.";
      $("avatarBtn").hidden = true;
    });
})();
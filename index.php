<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Links</title>
  <meta name="color-scheme" content="light dark">
  <link rel="stylesheet" href="style.css">
  <script>
    // Apply the saved theme before first paint to avoid a flash.
    (function () {
      var saved = null;
      try { saved = localStorage.getItem("theme"); } catch (e) {}
      var dark = saved ? saved === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
      document.documentElement.dataset.theme = dark ? "dark" : "light";
    })();
  </script>
</head>
<body>
  <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode">
    <svg class="icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.6M12 19.4V22M2 12h2.6M19.4 12H22M4.9 4.9l1.9 1.9M17.2 17.2l1.9 1.9M4.9 19.1l1.9-1.9M17.2 6.8l1.9-1.9" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
  </button>

  <main class="page">
    <header class="profile">
      <button class="avatar-btn" id="avatarBtn" type="button" aria-label="View profile photo">
        <img class="avatar" id="avatar" alt="" width="104" height="104">
      </button>
      <h1 id="name"></h1>
      <p class="bio" id="bio"></p>
    </header>

    <ul class="links" id="links"></ul>
    <p class="error" id="error" role="alert" hidden></p>

    <footer class="footer">
      Built by
      <a href="https://github.com/yasserbdj96" target="_blank" rel="noopener noreferrer">yasserbdj96</a>
    </footer>
  </main>

  <dialog class="qr-dialog" id="qrDialog" aria-labelledby="qrTitle">
    <div class="qr-head">
      <h2 id="qrTitle"></h2>
      <button class="qr-close" id="qrClose" type="button" aria-label="Close">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="qr-code" id="qrCode"></div>
    <p class="qr-url" id="qrUrl"></p>
    <button class="qr-copy" id="qrCopy" type="button">Copy link</button>
  </dialog>

  <dialog class="img-dialog" id="imgDialog" aria-label="Profile photo">
    <button class="img-close" id="imgClose" type="button" aria-label="Close">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
    <img class="img-full" id="imgFull" alt="">
  </dialog>

  <script src="qrcode.js"></script>
  <script src="icons.js"></script>
  <script src="script.js"></script>
</body>
</html>
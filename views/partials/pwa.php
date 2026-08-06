<?php // PWA head block: manifest link, app meta tags, service-worker registration.
// Shared by layout.php (standard pages) and chat/app.php (the standalone chat).
// The service worker is always on — it adds installability plus offline reading
// of previously viewed channels/DMs, and it wipes user-specific caches on logout. ?>
<link rel="manifest" href="/manifest">
<meta name="theme-color" content="<?= h(ThemeService::manifestColors()['theme_color']) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= h(mb_substr(config_get('site_name', 'LVChat') ?: 'LVChat', 0, 30)) ?>">
<meta name="description" content="<?= h(config_get('site_tagline', 'IRC-style web chat') ?: 'IRC-style web chat') ?>">
<link rel="icon" href="/favicon.ico" type="image/x-icon">
<link rel="icon" href="/assets/pwa/icon-192.png" type="image/png">
<link rel="apple-touch-icon" href="/assets/pwa/apple-touch-icon.png">
<script>
(function () {
  // Register the service worker once the page is fully loaded. Failure is
  // silent: the app works exactly the same without a service worker.
  if (!('serviceWorker' in navigator)) return;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  });
  // Logging out must never leave a previous user's cached chat view behind
  // on a shared device — tell the worker to wipe its page + data caches.
  // Delegated, because logout forms may not exist yet when this runs (it
  // lives in <head>) and some are injected dynamically.
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f && f.matches && f.matches('form[action="/logout"]')) {
      navigator.serviceWorker.getRegistration().then(function (r) {
        if (r && r.active) r.active.postMessage({ type: 'CLEAR_CACHES' });
      }).catch(function () {});
    }
  }, true);
})();
</script>

<?php $pageTitle = 'Dashboard'; ?>
<div class="row" style="justify-content:space-between">
  <h2 style="margin:0">Releases</h2>
  <div class="row">
    <form method="post" action="/admin/check"><?= Csrf::field() ?><button class="btn ghost" type="submit">Check all URLs</button></form>
    <button class="btn ghost" type="button" onclick="window.location.hash='feeds'">View feeds</button>
  </div>
</div>
<p class="muted">Every change below rewrites <code>data/releases.json</code>. Artifacts stay where they are hosted (GitHub Releases, FTP, your web server) — this server only records their URLs and hashes.</p>

<?php if ($errors !== []): ?>
<div class="flash warn">Validation issues: <?= h(implode('; ', $errors)) ?></div>
<?php endif; ?>

<form method="post" action="/admin/save" id="manifest-form">
<?= Csrf::field() ?>

<?php foreach (Manifest::APPS as $app):
    $entry = $data['apps'][$app] ?? [];
?>
<div class="card">
  <h2><?= h($app) ?></h2>
  <div class="grid">
    <div>
      <label>Version</label>
      <input class="mono" type="text" name="<?= h($app) ?>_version" value="<?= h((string) ($entry['version'] ?? '')) ?>" placeholder="1.2.3">
    </div>
    <div>
      <label>Released</label>
      <input class="mono" type="text" name="<?= h($app) ?>_released_at" value="<?= h((string) ($entry['released_at'] ?? '')) ?>" placeholder="2026-08-01T00:00:00Z">
    </div>
  </div>
  <div>
    <label>Release notes URL</label>
    <input class="mono" type="url" name="<?= h($app) ?>_notes" value="<?= h((string) ($entry['notes'] ?? '')) ?>" placeholder="https://github.com/…/releases/tag/v1.2.3">
  </div>

  <?php if ($app === 'web'): ?>
  <div>
    <label>Download URL (tarball/zip of the app folder)</label>
    <div class="row">
      <input class="mono" style="flex:1;min-width:240px" type="url" name="web_url" value="<?= h((string) ($entry['url'] ?? '')) ?>" placeholder="https://github.com/…/releases/download/v1.2.3/lvchat.zip">
      <button class="btn ghost" type="button" data-fetch-hash data-url="#web_url" data-sha="#web_sha256">Fetch &amp; hash</button>
    </div>
    <label>sha256 (hex)</label>
    <input class="mono" type="text" name="web_sha256" id="web_sha256" value="<?= h((string) ($entry['sha256'] ?? '')) ?>" placeholder="64 hex characters">
  </div>
  <?php else: ?>
    <?php foreach (Manifest::PLATFORMS as $plat):
        $p = $entry['platforms'][$plat] ?? [];
    ?>
    <div style="margin-top:14px;border-top:1px solid #3f4147;padding-top:10px">
      <div class="muted" style="font-weight:700;text-transform:uppercase;font-size:12px;letter-spacing:.04em"><?= h(Manifest::platformLabel($plat)) ?></div>
      <div class="row">
        <input class="mono" style="flex:1;min-width:240px" type="url" name="<?= h($app) ?>_<?= h($plat) ?>_url" id="<?= h($app) ?>_<?= h($plat) ?>_url" value="<?= h((string) ($p['url'] ?? '')) ?>" placeholder="https://example.com/…<?= h($plat === 'win' ? '.exe' : ($plat === 'mac' ? '.dmg' : '.deb')) ?>">
        <button class="btn ghost" type="button" data-fetch-hash data-url="#<?= h($app) ?>_<?= h($plat) ?>_url" data-sha="#<?= h($app) ?>_<?= h($plat) ?>_sha256" data-sha512="#<?= h($app) ?>_<?= h($plat) ?>_sha512" data-size="#<?= h($app) ?>_<?= h($plat) ?>_size">Fetch &amp; hash</button>
      </div>
      <div class="grid" style="grid-template-columns:1.4fr 1.4fr 1fr .6fr">
        <div><label>sha256 (hex)</label><input class="mono" type="text" name="<?= h($app) ?>_<?= h($plat) ?>_sha256" id="<?= h($app) ?>_<?= h($plat) ?>_sha256" value="<?= h((string) ($p['sha256'] ?? '')) ?>" placeholder="64 hex characters"></div>
        <div><label>sha512 (base64)</label><input class="mono" type="text" name="<?= h($app) ?>_<?= h($plat) ?>_sha512" id="<?= h($app) ?>_<?= h($plat) ?>_sha512" value="<?= h((string) ($p['sha512'] ?? '')) ?>" placeholder="electron-updater feed"></div>
        <div><label>Size (bytes)</label><input class="mono" type="text" name="<?= h($app) ?>_<?= h($plat) ?>_size" id="<?= h($app) ?>_<?= h($plat) ?>_size" value="<?= h((string) ($p['size'] ?? '')) ?>" placeholder="0"></div>
        <div style="display:flex;align-items:flex-end;padding-bottom:2px"><a class="btn ghost" style="padding:6px 10px" href="/downloads/<?= h($app) ?>/<?= h($plat) ?>" target="_blank" rel="noopener">Test</a></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="row" style="justify-content:flex-end">
  <button class="btn" type="submit">Save manifest</button>
</div>
</form>

<div class="card" id="feeds">
  <h2>electron-updater feeds</h2>
  <p class="muted">Generated on the fly from this manifest. Point the apps' <code>publish.url</code> (generic provider) at <code>&lt;this server&gt;/desktop</code> and <code>&lt;this server&gt;/messenger</code>.</p>
  <?php if ($feeds === []): ?>
    <p class="muted">Publish a desktop/messenger version to generate feeds.</p>
  <?php else: foreach ($feeds as $file => $body): ?>
    <div class="section-label"><?= h($file) ?></div>
    <pre style="background:#1e1f22;padding:10px 12px;border-radius:8px;overflow-x:auto"><code><?= h($body) ?></code></pre>
  <?php endforeach; endif; ?>
</div>

<script>
document.querySelectorAll('[data-fetch-hash]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var urlEl = document.querySelector(btn.dataset.url)
    if (!urlEl || !urlEl.value.trim()) { alert('Enter a URL first.'); return }
    btn.disabled = true
    btn.textContent = 'Hashing…'
    var body = new URLSearchParams(new FormData())
    body.set('csrf', document.querySelector('#manifest-form input[name=csrf]').value)
    body.set('url', urlEl.value.trim())
    fetch('/admin/fetch-hash', { method: 'POST', headers: { 'X-CSRF': document.querySelector('#manifest-form input[name=csrf]').value }, body: body })
      .then(function (r) { return r.json() })
      .then(function (j) {
        if (!j.ok) { alert(j.error || 'Hash failed'); return }
        if (btn.dataset.sha) document.querySelector(btn.dataset.sha).value = j.sha256
        if (btn.dataset.sha512) document.querySelector(btn.dataset.sha512).value = j.sha512
        if (btn.dataset.size) document.querySelector(btn.dataset.size).value = j.size
      })
      .catch(function () { alert('Hash request failed') })
      .finally(function () { btn.disabled = false; btn.textContent = 'Fetch & hash' })
  })
})
</script>

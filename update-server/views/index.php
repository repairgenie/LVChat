<?php $pageTitle = 'Status'; ?>
<div class="card">
  <h2>Update feed</h2>
  <p class="muted">This server publishes the current versions of the LVChat apps and where each one can be downloaded. Community server admins point their <code>updater_url</code> here (or at their own mirror) to resolve versions and download links for their installs.</p>
  <table>
    <tr><th>App</th><th>Version</th><th>Validation</th></tr>
    <?php foreach (Manifest::APPS as $app): $entry = Manifest::app($app); $ver = trim((string) ($entry['version'] ?? '')); ?>
    <tr>
      <td><?= h($app) ?></td>
      <td class="mono"><?= $ver !== '' ? 'v' . h($ver) : '<span class="muted">not published</span>' ?></td>
      <td>
        <?php $has = array_filter($errors, fn($e) => str_starts_with($e, $app . ':')); ?>
        <?= $has === [] ? '<span class="tag ok">OK</span>' : '<span class="tag err">Issues</span>' ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($errors !== []): ?>
    <ul class="muted" style="margin-top:10px">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Endpoints</h2>
  <table>
    <tr><th>URL</th><th>Purpose</th></tr>
    <tr><td class="mono"><a href="/manifest.json">/manifest.json</a></td><td>Full machine-readable manifest (all apps, all platforms).</td></tr>
    <tr><td class="mono">/api/latest/&lt;app&gt;</td><td>Latest version for web | desktop | messenger.</td></tr>
    <tr><td class="mono">/api/latest/&lt;app&gt;/&lt;platform&gt;</td><td>Latest info for one platform (win, mac, linux_deb, linux_rpm, linux_appimage).</td></tr>
    <tr><td class="mono">/downloads/&lt;app&gt;/&lt;platform&gt;</td><td>302 redirect to the artifact — stable URL that survives link changes.</td></tr>
    <tr><td class="mono">/desktop/latest.yml, /latest-mac.yml, /latest-linux.yml</td><td>electron-updater generic feeds (same under /messenger/).</td></tr>
  </table>
</div>

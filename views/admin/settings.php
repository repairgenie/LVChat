<?php $title = 'Settings'; $active = 'settings'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Server settings</h1>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-5">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/settings">
  <div>
    <label class="label">Site name</label>
    <input class="input" name="site_name" value="<?= h($settings['site_name']) ?>">
  </div>
  <div>
    <label class="label">Site tagline</label>
    <input class="input" name="site_tagline" value="<?= h($settings['site_tagline'] ?: 'IRC-style web chat') ?>" placeholder="IRC-style web chat">
    <p class="text-xs text-discord-400 mt-1">Short subtitle shown in the header and PWA description.</p>
  </div>
  <div>
    <label class="label">Site logo URL</label>
    <input class="input" name="logo_url" value="<?= h($settings['logo_url']) ?>" placeholder="https://example.com/logo.png">
    <p class="text-xs text-discord-400 mt-1">Shown in place of the site name in the header and login pages. Leave empty to use the default mark.</p>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Registration</div>
      <div class="text-xs text-discord-400">Allow new accounts to be created</div>
    </div>
    <input type="checkbox" name="registration_enabled" value="1" class="w-5 h-5 accent-blurple" <?= $settings['registration_enabled'] === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Require admin approval for new registrations</div>
      <div class="text-xs text-discord-400">New accounts are created as "pending" and cannot chat until an admin approves them (they can still log in and browse).</div>
    </div>
    <input type="checkbox" name="registration_requires_approval" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['registration_requires_approval'] ?? '0') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Spam filters</div>
      <div class="text-xs text-discord-400">Apply active spam filters to messages</div>
    </div>
    <input type="checkbox" name="spamfilter_enabled" value="1" class="w-5 h-5 accent-blurple" <?= $settings['spamfilter_enabled'] === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Image uploads</div>
      <div class="text-xs text-discord-400">Allow members to upload and post images in channels</div>
    </div>
    <input type="checkbox" name="uploads_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['uploads_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Emoji reactions</div>
      <div class="text-xs text-discord-400">Allow reactions on messages</div>
    </div>
    <input type="checkbox" name="reactions_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['reactions_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">GIF search (Giphy)</div>
      <div class="text-xs text-discord-400">Allow members to search and post GIFs in channels and DMs</div>
    </div>
    <input type="checkbox" name="gifs_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['gifs_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Chat logging</div>
      <div class="text-xs text-discord-400">Record all channel and DM messages in the append-only archive (Admin → Logs). When off, no new messages are logged. Individual channels can also disable logging with /mode +L (opers only).</div>
    </div>
    <input type="checkbox" name="chat_logging_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['chat_logging_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Webhooks</div>
      <div class="text-xs text-discord-400">Allow external services to post into channels via webhook URLs</div>
    </div>
    <input type="checkbox" name="webhooks_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['webhooks_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div>
    <label class="label">Giphy API key</label>
    <input class="input font-mono" name="giphy_api_key" value="<?= h($settings['giphy_api_key'] ?? '') ?>" placeholder="Get a free key at developers.giphy.com" autocomplete="off" spellcheck="false">
    <p class="text-xs text-discord-400 mt-1">Required for GIF search. Search/trending is proxied through this server, so the key is never exposed to browsers.</p>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Realtime mode</div>
      <div class="text-xs text-discord-400">SSE streams live updates but holds a PHP worker per client — best on a VPS/php-fpm. Polling is the shared-hosting default.</div>
    </div>
    <select name="realtime" class="input w-36 !py-1.5">
      <option value="poll" <?= ($settings['realtime'] ?? 'poll') === 'poll' ? 'selected' : '' ?>>Polling</option>
      <option value="sse" <?= ($settings['realtime'] ?? 'poll') === 'sse' ? 'selected' : '' ?>>SSE</option>
    </select>
  </div>
  <div>
    <label class="label">Max channels per user</label>
    <input class="input" type="number" min="1" name="max_channels_per_user" value="<?= (int) $settings['max_channels_per_user'] ?>">
  </div>
  <div>
    <label class="label">Server timezone</label>
    <?php $tz = (string) ($settings['timezone'] ?? 'UTC'); ?>
    <select name="timezone" class="input !py-1.5">
      <?php
      $regions = [];
      foreach (DateTimeZone::listIdentifiers() as $id) {
          $parts = explode('/', $id, 2);
          $regions[$parts[0]][$id] = isset($parts[1]) ? str_replace(['_', '/'], [' ', ' / '], $parts[1]) : $parts[0];
      }
      foreach ($regions as $region => $zones): ?>
        <optgroup label="<?= h($region) ?>">
          <?php foreach ($zones as $id => $label): ?>
            <option value="<?= h($id) ?>" <?= $tz === $id ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <p class="text-xs text-discord-400 mt-1">Used for timestamps shown to users in chat, logs and the admin dashboard. Server storage remains UTC.</p>
  </div>
  <div id="poll-settings" class="grid grid-cols-2 gap-4" <?= ($settings['realtime'] ?? 'poll') === 'sse' ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>
    <div>
      <label class="label">Poll interval (seconds)</label>
      <input class="input" type="number" min="1" name="poll_interval" value="<?= (int) $settings['poll_interval'] ?>">
      <p class="text-xs text-discord-400 mt-1">How often clients fetch new messages. Higher = far fewer requests on shared hosting.</p>
    </div>
    <div>
      <label class="label">Presence throttle (seconds)</label>
      <input class="input" type="number" min="5" name="presence_throttle" value="<?= (int) $settings['presence_throttle'] ?>">
      <p class="text-xs text-discord-400 mt-1">How often the server writes "last seen" per user. Most polls become pure reads.</p>
    </div>
  </div>
  <script>
  document.querySelector('select[name="realtime"]').addEventListener('change', function() {
    var ps = document.getElementById('poll-settings');
    if (this.value === 'sse') { ps.style.opacity = '0.4'; ps.style.pointerEvents = 'none'; }
    else { ps.style.opacity = '1'; ps.style.pointerEvents = 'auto'; }
  });
  </script>

  <div class="pt-4 border-t border-discord-700">
    <div class="flex items-center justify-between mb-3">
      <div>
        <div class="text-sm font-medium text-white">Email (SMTP)</div>
        <div class="text-xs text-discord-400">Used to send account invitations and welcome emails</div>
      </div>
      <input type="checkbox" name="smtp_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
    <div id="smtp-fields" class="grid grid-cols-2 gap-4" <?= ($settings['smtp_enabled'] ?? '0') !== '1' ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>
      <div>
        <label class="label">SMTP host</label>
        <input class="input" name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
      </div>
      <div class="flex gap-3">
        <div>
          <label class="label">Port</label>
          <input class="input" type="number" min="1" name="smtp_port" value="<?= (int) ($settings['smtp_port'] ?? 587) ?>">
        </div>
        <div class="flex-1">
          <label class="label">Encryption</label>
          <select name="smtp_encryption" class="input !py-1.5">
            <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
            <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
          </select>
        </div>
      </div>
      <div>
        <label class="label">Username (optional)</label>
        <input class="input" name="smtp_username" value="<?= h($settings['smtp_username'] ?? '') ?>" autocomplete="off">
      </div>
      <div>
        <label class="label">Password</label>
        <input class="input" type="password" name="smtp_password" value="" placeholder="<?= !empty($settings['smtp_has_password']) ? '•••••••• (kept)' : '…' ?>" autocomplete="new-password">
        <p class="text-xs text-discord-400 mt-1">Leave blank to keep the stored password.</p>
      </div>
      <div>
        <label class="label">From email</label>
        <input class="input" type="email" name="smtp_from_email" value="<?= h($settings['smtp_from_email'] ?? '') ?>" placeholder="noreply@example.com">
      </div>
      <div>
        <label class="label">From name (optional)</label>
        <input class="input" name="smtp_from_name" value="<?= h($settings['smtp_from_name'] ?? '') ?>" placeholder="LVChat">
      </div>
    </div>
  </div>

  <script>
  document.querySelector('input[name="smtp_enabled"]').addEventListener('change', function() {
    var f = document.getElementById('smtp-fields');
    if (!this.checked) { f.style.opacity = '0.4'; f.style.pointerEvents = 'none'; }
    else { f.style.opacity = '1'; f.style.pointerEvents = 'auto'; }
  });
  </script>
  <button name="action" value="settings_save" class="btn-primary">Save settings</button>
</form>

<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-3">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/settings">
  <h2 class="font-semibold text-white">Test SMTP</h2>
  <p class="text-xs text-discord-400">Send a test email to verify the current settings. Save first — the test uses whatever is stored.</p>
  <div class="flex gap-3">
    <input class="input flex-1" type="email" name="email" placeholder="recipient@example.com (blank = your email)">
    <button name="action" value="smtp_test" class="btn-ghost">Send test email</button>
  </div>
</form>

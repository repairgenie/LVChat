<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */

 $title = 'Settings'; $active = 'settings';
$pageTitle = 'Server settings';
// SMTP fields are genuinely disabled when SMTP is off: they are neither
// constraint-validated (a hidden port must not block saving) nor submitted,
// so the stored config survives toggling SMTP off and back on.
$smtpOn = ($settings['smtp_enabled'] ?? '0') === '1';
$smtpDisabled = $smtpOn ? '' : ' disabled';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>
<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-5">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/settings">
  <div>
    <label class="label">Site name</label>
    <input class="input" name="site_name" value="<?= h($settings['site_name']) ?>">
  </div>
  <div>
    <label class="label">Site tagline</label>
    <input class="input" name="site_tagline" value="<?= h($settings['site_tagline'] ?: 'Discord-style web chat') ?>" placeholder="Discord-style web chat">
    <p class="text-xs text-discord-400 mt-1">Short subtitle shown in the header and PWA description.</p>
  </div>
  <div>
    <label class="label">Site logo URL</label>
    <input class="input" name="logo_url" value="<?= h($settings['logo_url']) ?>" placeholder="https://example.com/logo.png">
    <p class="text-xs text-discord-400 mt-1">Shown in place of the site name in the header and login pages. Leave empty to use the default mark.</p>
  </div>
  <div class="pt-4 border-t border-discord-700">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">Updates</div>
    <p class="text-xs text-discord-400 mb-3">Point this server at an LVChat Update Server (or your own mirror) so the <a href="/admin/updates" class="text-blurple-300 hover:underline">Updates page</a>, your chat's download modal and the desktop clients can resolve the latest versions. Leave disabled to keep today's fully-manual behavior.</p>
    <div class="flex items-center justify-between card p-4">
      <div>
        <div class="text-sm font-medium text-white">Enable update feed</div>
        <div class="text-xs text-discord-400">Check upstream for newer versions of the web app and desktop clients</div>
      </div>
      <input type="checkbox" name="updater_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['updater_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
    <div class="mt-3">
      <label class="label">Update server URL</label>
      <input class="input font-mono text-xs" name="updater_url" value="<?= h($settings['updater_url'] ?? '') ?>" placeholder="https://updates.example.com" autocomplete="off" spellcheck="false">
      <p class="text-xs text-discord-400 mt-1">Base URL of the update server. It must serve <code>/manifest.json</code> (the LVChat Update Server does).</p>
    </div>
  </div>
  <div class="pt-4 border-t border-discord-700">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">Licensing</div>
    <p class="text-xs text-discord-400 mb-3">Paid modules (those with <code class="text-discord-300">"license": true</code> in their manifest) validate their key <strong>offline first</strong> (Ed25519 signature), then ask your license server to confirm the key exists and is active. See <code class="text-discord-300">docs/protocol/licensing.md</code>.</p>
    <div class="mt-3">
      <label class="label">License server URL</label>
      <input class="input font-mono text-xs" name="license_url" value="<?= h($settings['license_url'] ?? '') ?>" placeholder="https://licenses.example.com" autocomplete="off" spellcheck="false">
      <p class="text-xs text-discord-400 mt-1">Base URL of the LVChat License Server. Empty = no external checks (the internal key check still applies).</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
      <div>
        <label class="label">Offline policy</label>
        <select name="license_policy" class="input !py-1.5">
          <?php foreach (['grace' => 'Grace (cache + trial window)', 'strict' => 'Strict (refuse when unreachable)', 'offline' => 'Offline (internal check only)'] as $pv => $pl): ?>
          <option value="<?= $pv ?>" <?= ($settings['license_policy'] ?? 'grace') === $pv ? 'selected' : '' ?>><?= $pl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Grace days (never-confirmed keys)</label>
        <input class="input" type="number" min="0" name="license_grace_days" value="<?= h($settings['license_grace_days'] ?? '7') ?>">
      </div>
      <div>
        <label class="label">Re-check interval (hours)</label>
        <input class="input" type="number" min="1" name="license_recheck_hours" value="<?= h($settings['license_recheck_hours'] ?? '24') ?>">
      </div>
    </div>
    <p class="text-xs text-discord-400 mt-2">Grace: a key that once validated keeps working when the server is unreachable; a key that never confirmed gets the grace window above, then stops. Strict refuses immediately when unreachable.</p>
  </div>
  <div class="pt-4 border-t border-discord-700">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">Desktop apps &amp; downloads</div>
    <p class="text-xs text-discord-400 mb-3">Custom download links shown in the chat's "Download the desktop app" modal. These <strong>override the upstream feed</strong> — ideal for white-labelled builds tailored to this community. Leave a URL empty to fall back to the upstream link (or hide the button when no feed is configured); the version shown comes from the feed unless you type one.</p>
    <?php
    $dlApps = [
        'desktop' => ['name' => 'LVChat Desktop', 'desc' => 'A full desktop version of the regular LVChat experience — the whole web chat in its own window with native notifications.'],
        'messenger' => ['name' => 'LVChat Messenger', 'desc' => 'A streamlined, instant-messaging-first client with a simpler layout, designed for fast everyday use — a good fit for business settings.'],
    ];
    $dlPlatforms = [
        'win' => ['label' => 'Windows', 'ext' => '.exe'],
        'mac' => ['label' => 'macOS', 'ext' => '.dmg'],
        'linux_rpm' => ['label' => 'Linux (RPM)', 'ext' => '.rpm'],
        'linux_deb' => ['label' => 'Linux (DEB)', 'ext' => '.deb'],
        'linux_appimage' => ['label' => 'Linux (AppImage)', 'ext' => '.AppImage'],
    ];
    foreach ($dlApps as $dlApp => $dlInfo):
    ?>
    <div class="card p-4 mb-4">
      <div class="text-sm font-semibold text-white tracking-wide mb-2"><?= h($dlInfo['name']) ?></div>
      <p class="text-xs text-discord-400 mb-3"><?= h($dlInfo['desc']) ?></p>
      <div class="space-y-3">
        <?php foreach ($dlPlatforms as $dlPlat => $dlPlatInfo): ?>
        <div>
          <div class="text-xs font-medium text-discord-300 mb-1"><?= h($dlPlatInfo['label']) ?></div>
          <div class="flex gap-3">
            <input class="input font-mono text-xs flex-1 min-w-0" name="download_<?= $dlApp ?>_<?= $dlPlat ?>_url" value="<?= h($settings["download_{$dlApp}_{$dlPlat}_url"] ?? '') ?>" placeholder="https://example.com/…<?= h($dlPlatInfo['ext']) ?>" autocomplete="off" spellcheck="false">
            <input class="input font-mono text-xs w-28 shrink-0" name="download_<?= $dlApp ?>_<?= $dlPlat ?>_version" value="<?= h($settings["download_{$dlApp}_{$dlPlat}_version"] ?? '') ?>" placeholder="1.0.0" autocomplete="off" spellcheck="false" title="Version">
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <div>
      <label class="label">Update link</label>
      <input class="input" name="download_update_url" value="<?= h($settings['download_update_url'] ?? '') ?>" placeholder="https://example.com/downloads" autocomplete="off" spellcheck="false">
      <p class="text-xs text-discord-400 mt-1">Where existing users are pointed to fetch the latest version — shown at the bottom of the download modal.</p>
    </div>
  </div>
  <div class="pt-4 border-t border-discord-700">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">Web messenger clients</div>
    <p class="text-xs text-discord-400 mb-3">Origins allowed to use this server's Messenger API cross-origin — the LVChat Messenger web app / PWA and any other browser or mobile client you host. <code class="font-mono">http://127.0.0.1:*</code> and <code class="font-mono">null</code> (file://) are always allowed, so the local Electron messenger works out of the box.</p>
    <label class="label">Allowed origins</label>
    <input class="input font-mono text-xs" name="app_origins" value="<?= h($settings['app_origins'] ?? '') ?>" placeholder="https://msg.example.com, https://app.example.com" autocomplete="off" spellcheck="false">
    <p class="text-xs text-discord-400 mt-1">Comma-separated origins (scheme + host, no path), e.g. <code class="font-mono">https://georgethegeek.com</code>. CORS headers are only emitted when a request carries an allowlisted <code>Origin</code> header, so your normal web-app traffic is unaffected. Both sites must be HTTPS for cross-site session cookies. Trailing slashes, blank entries and duplicates are cleaned up on save.</p>
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
  <div>
    <label class="label">Registration rate limit</label>
    <input class="input" type="number" min="0" name="registration_rate_limit" value="<?= (int) ($settings['registration_rate_limit'] ?? 20) ?>">
    <p class="text-xs text-discord-400 mt-1">Max new accounts per IP per 10 minutes. Set to 0 for unlimited. Invite links are exempt.</p>
  </div>
  <div class="pt-4 border-t border-discord-700">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">Require two-factor authentication</div>
    <p class="text-xs text-discord-400 mb-3">Accounts in a checked class must enroll MFA (authenticator app) before they can use the site. Users not yet enrolled are walked through setup right after signing in with their password. Admins can reset a user's MFA from their profile page if they lose their authenticator.</p>
    <div class="flex items-center justify-between card p-4">
      <div>
        <div class="text-sm font-medium text-white">Admins</div>
        <div class="text-xs text-discord-400">IRC operators</div>
      </div>
      <input type="checkbox" name="mfa_require_admin" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['mfa_require_admin'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
    <div class="flex items-center justify-between card p-4 mt-2">
      <div>
        <div class="text-sm font-medium text-white">Staff</div>
        <div class="text-xs text-discord-400">Support staff</div>
      </div>
      <input type="checkbox" name="mfa_require_staff" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['mfa_require_staff'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
    <div class="flex items-center justify-between card p-4 mt-2">
      <div>
        <div class="text-sm font-medium text-white">Registered users</div>
        <div class="text-xs text-discord-400">All regular accounts</div>
      </div>
      <input type="checkbox" name="mfa_require_user" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['mfa_require_user'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
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
      <div class="text-xs text-discord-400">Polling is the shared-hosting default. SSE streams live updates but holds a PHP worker per client. WebSocket needs the realtime gateway daemon running. Open chat tabs report which transport they actually landed on, so the gateway status below shows real WebSocket usage — a browser that falls back to polling (bad proxy/mixed content) is visible instead of looking like a healthy connection.</div>
      <label class="flex items-center gap-2 mt-2 cursor-pointer" id="rt-force-row">
        <input type="checkbox" name="realtime_force" value="1" class="w-4 h-4 accent-blurple" <?= ($settings['realtime_force'] ?? '0') === '1' ? 'checked' : '' ?>>
        <span class="text-xs text-discord-300">Force WebSocket — never fall back to SSE/polling. If the gateway is unreachable, clients show an <em>offline</em> badge and keep retrying instead of silently degrading.</span>
      </label>
    </div>
    <select name="realtime" class="input w-36 !py-1.5">
      <option value="poll" <?= ($settings['realtime'] ?? 'poll') === 'poll' ? 'selected' : '' ?>>Polling</option>
      <option value="sse" <?= ($settings['realtime'] ?? 'poll') === 'sse' ? 'selected' : '' ?>>SSE</option>
      <option value="ws" <?= ($settings['realtime'] ?? 'poll') === 'ws' ? 'selected' : '' ?>>WebSocket</option>
    </select>
  </div>
  <?php
  // WebSocket gateway manual fallback (Admin → Settings → Realtime mode → WebSocket).
  // deploy.sh normally auto-selects the first free port in the range; these fields
  // let an admin override the bind address/port by hand when auto-detection isn't
  // enough (e.g. a specific local IP or a custom port).
  $wsIp = (string) ($settings['ws_ip'] ?? '0.0.0.0') ?: '0.0.0.0';
  $wsPort = (int) ($settings['ws_port'] ?? 8080) ?: 8080;
  $wsFree = [];
  for ($p = 8080; $p <= 8089; $p++) {
      $sock = @stream_socket_server("tcp://0.0.0.0:$p", $e, $s, STREAM_SERVER_BIND);
      if ($sock) {
          $wsFree[] = $p;
          fclose($sock);
      }
  }
  ?>
  <div id="ws-settings" class="card p-4">
    <div class="text-sm font-semibold text-white tracking-wide mb-2">WebSocket gateway</div>
    <p class="text-xs text-discord-400 mb-3">Manual override for the gateway bind address — a last resort. deploy.sh normally auto-selects the first free port (8080–8089).</p>
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="label">Bind IP</label>
        <input class="input font-mono" name="ws_ip" value="<?= h($wsIp) ?>" placeholder="0.0.0.0" autocomplete="off" spellcheck="false">
        <p class="text-xs text-discord-400 mt-1">0.0.0.0 = all interfaces. Set a specific local IP only if your host requires it.</p>
      </div>
      <div>
        <label class="label">Port</label>
        <input class="input font-mono" name="ws_port" list="ws-port-options" value="<?= (int) $wsPort ?>" inputmode="numeric" autocomplete="off">
        <datalist id="ws-port-options">
          <?php foreach (range(8080, 8089) as $p): ?>
            <option value="<?= $p ?>"><?= $p === $wsPort ? 'current' : (in_array($p, $wsFree, true) ? 'free' : 'in use') ?></option>
          <?php endforeach; ?>
        </datalist>
        <p class="text-xs text-discord-400 mt-1">Type any port, or pick from the list. Free in 8080–8089: <?= $wsFree ? implode(', ', $wsFree) : 'none' ?>.</p>
      </div>
    </div>
    <div class="grid grid-cols-2 gap-4 mt-3">
      <div>
        <label class="label">TLS certificate path</label>
        <input class="input font-mono" name="ws_ssl_cert" value="<?= h($settings['ws_ssl_cert'] ?? '') ?>" placeholder="/etc/letsencrypt/live/chat.example.com/fullchain.pem" autocomplete="off" spellcheck="false">
      </div>
      <div>
        <label class="label">TLS private key path</label>
        <input class="input font-mono" name="ws_ssl_key" value="<?= h($settings['ws_ssl_key'] ?? '') ?>" placeholder="/etc/letsencrypt/live/chat.example.com/privkey.pem" autocomplete="off" spellcheck="false">
      </div>
    </div>
    <p class="text-xs text-discord-400 mt-1">To serve <code class="font-mono">wss://</code> directly, give absolute paths to a TLS certificate + key valid for this hostname (e.g. Let's Encrypt); leave both blank for plain <code class="font-mono">ws://</code>. Clients are told to use <code class="font-mono">wss://</code> automatically, and saving restarts the gateway. Alternative: terminate TLS on a reverse proxy and set <code class="font-mono">ws_url</code> to the proxied <code class="font-mono">wss://…</code> URL instead.</p>
    <div class="border-t border-discord-700 mt-3 pt-3">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm font-medium text-white">Gateway status</div>
          <div id="ws-status" class="text-xs text-discord-400">Checking…</div>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" id="ws-btn-start" class="btn bg-green-600/80 hover:bg-green-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Start</button>
          <button type="button" id="ws-btn-restart" class="btn bg-blurple hover:bg-blurple-dark text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Restart</button>
          <button type="button" id="ws-btn-stop" class="btn bg-red-600/80 hover:bg-red-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Stop</button>
          <button type="button" id="ws-btn-reconnect" class="btn bg-amber-600/80 hover:bg-amber-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" title="Make every open chat tab reload so it reconnects with the current gateway config" disabled>Reconnect clients</button>
        </div>
      </div>
      <pre id="ws-output" class="hidden mt-2 p-2 rounded bg-black/40 text-[11px] text-discord-300 leading-relaxed max-h-48 overflow-auto whitespace-pre-wrap font-mono"></pre>
    </div>
    <p class="text-xs text-discord-400 mt-2">Start/stop/restart control the daemon directly — no SSH needed. "Reconnect clients" makes every open chat tab reload so it picks up the current gateway config (use after changing the port/IP). Status refreshes automatically.</p>
  </div>
  <div class="card p-4 mt-4">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-sm font-medium text-white">Deploy script</div>
        <div class="text-xs text-discord-400">Runs <code class="font-mono">bin/deploy.sh</code> — restores .htaccess, migrates/backs up the database, health-checks the app, and (re)installs the gateway. Output streams live in a modal.</div>
      </div>
      <button type="button" id="deploy-run" class="btn bg-discord-600 hover:bg-discord-500 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40">Run deploy.sh</button>
    </div>
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
  <div id="poll-settings" class="grid grid-cols-2 gap-4" <?= (($settings['realtime'] ?? 'poll') === 'sse' || ($settings['realtime'] ?? 'poll') === 'ws') ? 'style="opacity:0.4;pointer-events:none"' : '' ?>>
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
  document.querySelector('select[name="realtime"]').addEventListener('change', function() { syncRtUi(this.value); });
  // The WebSocket gateway panel, the force checkbox and the poll section are all
  // toggled from the live dropdown value on load (not baked in server-side), so
  // they can never be hidden by a stale/mismatched stored config.
  function syncRtUi(value) {
    var ps = document.getElementById('poll-settings');
    var ws = document.getElementById('ws-settings');
    var rf = document.getElementById('rt-force-row');
    var on = value === 'ws';
    if (ps) {
      ps.style.opacity = (value === 'sse' || on) ? '0.4' : '1';
      ps.style.pointerEvents = (value === 'sse' || on) ? 'none' : 'auto';
    }
    if (ws) ws.classList.toggle('hidden', !on);
    if (rf) {
      rf.style.opacity = on ? '1' : '0.5';
      rf.style.pointerEvents = on ? 'auto' : 'none';
    }
  }
  syncRtUi(document.querySelector('select[name="realtime"]').value);
  </script>

  <div class="pt-4 border-t border-discord-700">
    <div class="flex items-center justify-between mb-3">
      <div>
        <div class="text-sm font-medium text-white">Email (SMTP)</div>
        <div class="text-xs text-discord-400">Used to send account invitations and welcome emails</div>
      </div>
      <input type="checkbox" name="smtp_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['smtp_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>
    <div id="smtp-fields" class="grid grid-cols-2 gap-4" <?= $smtpOn ? '' : 'style="opacity:0.4;pointer-events:none"' ?>>
      <div>
        <label class="label">SMTP host</label>
        <input class="input" name="smtp_host" value="<?= h($settings['smtp_host'] ?? '') ?>" placeholder="smtp.example.com"<?= $smtpDisabled ?>>
      </div>
      <div class="flex gap-3">
        <div>
          <label class="label">Port</label>
          <input class="input" type="number" min="1" name="smtp_port" value="<?= (int) ($settings['smtp_port'] !== '' ? $settings['smtp_port'] : 587) ?>"<?= $smtpDisabled ?>>
        </div>
        <div class="flex-1">
          <label class="label">Encryption</label>
          <select name="smtp_encryption" class="input !py-1.5"<?= $smtpDisabled ?>>
            <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS</option>
            <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
            <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
          </select>
        </div>
      </div>
      <div>
        <label class="label">Username (optional)</label>
        <input class="input" name="smtp_username" value="<?= h($settings['smtp_username'] ?? '') ?>" autocomplete="off"<?= $smtpDisabled ?>>
      </div>
      <div>
        <label class="label">Password</label>
        <input class="input" type="password" name="smtp_password" value="" placeholder="<?= !empty($settings['smtp_has_password']) ? '•••••••• (kept)' : '…' ?>" autocomplete="new-password"<?= $smtpDisabled ?>>
        <p class="text-xs text-discord-400 mt-1">Leave blank to keep the stored password.</p>
      </div>
      <div>
        <label class="label">From email</label>
        <input class="input" type="email" name="smtp_from_email" value="<?= h($settings['smtp_from_email'] ?? '') ?>" placeholder="noreply@example.com"<?= $smtpDisabled ?>>
      </div>
      <div>
        <label class="label">From name (optional)</label>
        <input class="input" name="smtp_from_name" value="<?= h($settings['smtp_from_name'] ?? '') ?>" placeholder="LVChat"<?= $smtpDisabled ?>>
      </div>
    </div>
  </div>

  <script>
  document.querySelector('input[name="smtp_enabled"]').addEventListener('change', function() {
    var f = document.getElementById('smtp-fields');
    var controls = f.querySelectorAll('input, select');
    if (!this.checked) {
      f.style.opacity = '0.4';
      f.style.pointerEvents = 'none';
      for (var i = 0; i < controls.length; i++) controls[i].disabled = true;
    } else {
      f.style.opacity = '1';
      f.style.pointerEvents = 'auto';
      for (var i = 0; i < controls.length; i++) controls[i].disabled = false;
    }
  });
  </script>
  <script>
  (function () {
    var csrf = document.body.dataset.csrf || '';
    var statusEl = document.getElementById('ws-status');
    var outEl = document.getElementById('ws-output');
    var startBtn = document.getElementById('ws-btn-start');
    var stopBtn = document.getElementById('ws-btn-stop');
    var restartBtn = document.getElementById('ws-btn-restart');
    var reconnectBtn = document.getElementById('ws-btn-reconnect');
    var deployBtn = document.getElementById('deploy-run');
    if (!statusEl) return;

    function post(url, data, onOk) {
      var fd = new FormData();
      fd.append('csrf', csrf);
      Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
      fetch(url, { method: 'POST', body: fd })
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .catch(function () { return { error: 'Request failed' }; })
        .then(onOk);
    }

    function renderStatus(j) {
      if (!j || typeof j.running !== 'boolean') { statusEl.textContent = 'Unknown'; return; }
      if (j.running) {
        var s = '<span class="text-green-400 font-semibold">● Running</span> — '
          + (j.connections || 0) + ' connection' + (j.connections === 1 ? '' : 's');
        // Flag a stale daemon: the port it actually bound differs from the
        // ws_port config, so every browser connects to the wrong port and
        // silently falls back to polling (the classic "0 connections" trap).
        var cfgPort = parseInt(document.querySelector('input[name="ws_port"]').value, 10) || 0;
        if (j.ws_port && cfgPort && j.ws_port !== cfgPort) {
          s += '<span class="text-amber-400"> — ⚠ daemon is on port ' + j.ws_port + ' but config says ' + cfgPort + '. Restart the gateway to apply, or a WS_PORT env override is winning.</span>';
        }
        var tr = j.transports || {};
        var parts = [];
        if (tr.ws) parts.push(tr.ws + ' on websocket');
        if (tr.sse) parts.push(tr.sse + ' on sse');
        if (tr.poll) parts.push(tr.poll + ' on polling');
        if (tr.none) parts.push('<span class="text-red-400">' + tr.none + ' offline (websocket forced but unreachable)</span>');
        if (parts.length) s += ' — clients: ' + parts.join(', ');
        statusEl.innerHTML = s
          + (j.pid ? ' (pid ' + j.pid + ')' : '');
        startBtn.disabled = true;
        stopBtn.disabled = false;
        restartBtn.disabled = false;
        if (reconnectBtn) reconnectBtn.disabled = false;
      } else {
        statusEl.innerHTML = '<span class="text-red-400 font-semibold">● Stopped</span>';
        startBtn.disabled = false;
        stopBtn.disabled = true;
        restartBtn.disabled = true;
        if (reconnectBtn) reconnectBtn.disabled = true;
      }
    }

    function refreshStatus() {
      fetch('/admin/ws/status')
        .then(function (r) { return r.json().catch(function () { return {}; }); })
        .catch(function () { return {}; })
        .then(renderStatus);
    }

    function showOutput(el, text) {
      el.textContent = text || '(no output)';
      el.classList.remove('hidden');
    }

    function wsControl(action, btn) {
      if (btn) btn.disabled = true;
      showOutput(outEl, 'Running: php bin/ws-server.php ' + action + (action === 'stop' ? '' : ' -d') + '\n…');
      post('/admin/ws/control', { action: action }, function (j) {
        if (j.error) { showOutput(outEl, 'Error: ' + j.error); }
        else {
          showOutput(outEl, j.output || (j.ok ? 'Done.' : 'Command failed.'));
          if (j.status) renderStatus(j.status);
        }
        refreshStatus();
        if (btn) btn.disabled = false;
      });
    }

    if (startBtn) startBtn.addEventListener('click', function () { wsControl('start', this); });
    if (stopBtn) stopBtn.addEventListener('click', function () { wsControl('stop', this); });
    if (restartBtn) restartBtn.addEventListener('click', function () { wsControl('restart', this); });
    if (reconnectBtn) reconnectBtn.addEventListener('click', function () {
      if (reconnectBtn.disabled) return;
      reconnectBtn.disabled = true;
      showOutput(outEl, 'Sending reconnect signal to every open chat tab…\n');
      post('/admin/ws/reconnect', {}, function (j) {
        showOutput(outEl, j && j.ok ? 'Done. Tabs are reloading with the current gateway config.'
          : 'Error: ' + ((j && j.error) || 'request failed'));
        if (j && j.status) renderStatus(j.status);
        refreshStatus();
        reconnectBtn.disabled = false;
      });
    });

    // ── Deploy modal: streams bin/deploy.sh like a terminal ────────────────────
    function openDeployModal() {
      var modal = document.getElementById('deploy-modal');
      var out = document.getElementById('deploy-modal-output');
      if (!modal || !out) return null;
      modal.classList.remove('hidden');
      modal.classList.add('flex');
      out.textContent = '';
      out.scrollTop = out.scrollHeight;
      return out;
    }
    function closeDeployModal() {
      var modal = document.getElementById('deploy-modal');
      if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
    }

    if (deployBtn) deployBtn.addEventListener('click', function () {
      if (deployBtn.disabled) return;
      deployBtn.disabled = true;
      var out = openDeployModal();
      if (!out) { deployBtn.disabled = false; return; }
      out.textContent += '$ bash bin/deploy.sh\n';
      var fd = new FormData();
      fd.append('csrf', csrf);
      fetch('/admin/deploy/stream', { method: 'POST', body: fd })
        .then(function (r) {
          if (!r.ok || !r.body) throw new Error('HTTP ' + r.status);
          return r.body.getReader();
        })
        .then(function (reader) {
          var decoder = new TextDecoder();
          function pump() {
            return reader.read().then(function (res) {
              if (res.done) { deployBtn.disabled = false; refreshStatus(); return; }
              out.textContent += decoder.decode(res.value, { stream: true });
              out.scrollTop = out.scrollHeight;
              return pump();
            });
          }
          return pump();
        })
        .catch(function (err) {
          out.textContent += '\n[error: ' + (err && err.message ? err.message : 'request failed') + ']';
          deployBtn.disabled = false;
        });
      refreshStatus();
    });

    // The modal markup lives after this script block, so bind the X button via
    // event delegation rather than a direct element lookup.
    document.addEventListener('click', function (e) {
      if (e.target && e.target.closest && e.target.closest('#deploy-close')) closeDeployModal();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeDeployModal();
    });
    document.addEventListener('click', function (e) {
      var modal = document.getElementById('deploy-modal');
      if (modal && !modal.classList.contains('hidden') && e.target === modal) closeDeployModal();
    });

    refreshStatus();
    // Keep the status live every 15s while the WebSocket panel is visible.
    setInterval(function () {
      var panel = document.getElementById('ws-settings');
      if (panel && !panel.classList.contains('hidden')) refreshStatus();
    }, 15000);
  })();
  </script>
  <button name="action" value="settings_save" class="btn-primary">Save settings</button>
  <p class="text-xs text-discord-500 pt-2">Build <?= h(LVC_VERSION) ?> · settings view mtime <?= gmdate('Y-m-d H:i:s', (int) @filemtime(__FILE__)) ?> UTC — if this date doesn't match what you uploaded, the server is serving a stale cached copy (restart PHP-FPM / clear OPcache, then re-check).</p>
</form>

<!-- Deploy output modal: streams bin/deploy.sh like a terminal -->
<div id="deploy-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60 p-4">
  <div class="w-full max-w-2xl rounded-xl bg-discord-800 border border-discord-700 shadow-2xl flex flex-col max-h-[80vh]">
    <div class="flex items-center justify-between px-4 py-3 border-b border-discord-700">
      <div class="text-sm font-semibold text-white font-mono">bash bin/deploy.sh</div>
      <button type="button" id="deploy-close" class="text-discord-400 hover:text-white text-xl leading-none px-1" title="Close">&times;</button>
    </div>
    <pre id="deploy-modal-output" class="flex-1 overflow-auto p-3 text-[12px] font-mono text-green-300 bg-black/50 leading-relaxed whitespace-pre-wrap min-h-[200px]"></pre>
  </div>
</div>

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

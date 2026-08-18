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

 $title = $user['username'];
$pMode = (string) ($user['status_mode'] ?? '');
if (!in_array($pMode, ['online', 'away', 'dnd', 'invisible', 'custom'], true)) { $pMode = !empty($user['away']) ? 'away' : 'online'; }
$pLabels = ['online' => 'Online', 'away' => 'Away', 'dnd' => 'Do Not Disturb', 'invisible' => 'Appear Offline', 'custom' => 'Custom status'];
$stLabel = $pLabels[$pMode] ?? 'Online';
$stText = trim((string) ($user['custom_status'] ?? ''));
if ($stText === '' && $pMode === 'away') { $stText = trim((string) ($user['away'] ?? '')); }
$stDot = match ($pMode) { 'dnd' => 'bg-red-500', 'away', 'custom' => 'bg-amber-400', 'invisible' => 'bg-discord-500', default => 'bg-green-500' };
?>
<!-- X button: return to the chat -->
<div class="flex justify-end mb-2">
  <button type="button" onclick="if (history.length > 1) history.back(); else location='/app';" class="btn-ghost !p-2" title="Back to chat" aria-label="Close profile"><?= icon('x', 'w-4 h-4') ?></button>
</div>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
  <div class="card p-6 text-center md:col-span-1">
    <div class="mx-auto w-20 h-20">
      <?php if (!empty($user['avatar'])): ?>
      <img src="<?= h(url((string) $user['avatar'])) ?>" alt="<?= h($user['username']) ?>" class="w-20 h-20 rounded-full object-cover">
      <?php else: ?>
      <div class="w-20 h-20 rounded-full bg-blurple flex items-center justify-center text-3xl font-bold text-white"><?= h(strtoupper(mb_substr($user['username'], 0, 1))) ?></div>
      <?php endif; ?>
    </div>
    <?php if ($isSelf && !(int) ($user['guest'] ?? 0)): ?>
    <div class="mt-2 flex items-center justify-center gap-2 text-xs">
      <label class="btn-ghost !py-1 cursor-pointer">Change<input type="file" id="avatar-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"></label>
      <?php if (!empty($user['avatar'])): ?><button id="avatar-remove" class="btn-ghost !py-1 text-red-400">Remove</button><?php endif; ?>
    </div>
    <?php endif; ?>
    <h1 class="mt-3 text-xl font-bold text-white"><?= h($user['username']) ?></h1>
    <div class="mt-1 text-sm">
      <?php if ((int) ($user['guest'] ?? 0)): ?>
      <span class="text-green-400 font-medium">Guest</span>
      <?php elseif ($user['role'] === 'admin'): ?>
      <span class="text-amber-400 font-medium">IRC Operator</span>
      <?php elseif ($user['role'] === 'staff'): ?>
      <span class="text-blurple font-medium">Staff</span>
      <?php else: ?>
      <span class="text-discord-400 font-medium">Registered</span>
      <?php endif; ?>
      <span class="text-discord-400"> ·
        <span class="inline-block w-2 h-2 rounded-full align-middle <?= $stDot ?>"></span>
        <?= h($stLabel) ?><?= $stText !== '' ? ' — ' . h($stText) : '' ?>
      </span>
    </div>
    <?php if ($user['bot']): ?><div class="mt-2 text-sm text-blurple">Bot</div><?php endif; ?>
    <div class="mt-4 text-xs text-discord-400">Registered <?= date('M j, Y', strtotime($user['registered_at'] . ' UTC')) ?></div>

    <?php if ($isSelf && $themeCustomizationEnabled): ?>
    <button id="profile-theme-toggle" class="btn-ghost w-full justify-center mt-4 text-sm" data-theme-btn></button>
    <?php endif; ?>

    <?php if (!$isSelf): ?>
    <a href="/app?dm=<?= h(rawurlencode($user['username'])) ?>" class="btn-primary w-full justify-center mt-5">Send message</a>
    <?php if (!(int) ($viewer['guest'] ?? 0) && !(int) ($user['guest'] ?? 0)): ?>
    <?php
    $fs = $friendStatus ?? 'none';
    ?>
    <div class="mt-2 space-y-2" id="friend-actions" data-friend-status="<?= h($fs) ?>" data-friend-username="<?= h($user['username']) ?>">
      <?php if ($fs === 'none'): ?>
      <button type="button" id="friend-add" class="btn-primary w-full justify-center !bg-green-600 hover:!bg-green-500">Add Friend</button>
      <?php elseif ($fs === 'outgoing'): ?>
      <button type="button" id="friend-cancel" class="btn-ghost w-full justify-center">Cancel Request</button>
      <?php elseif ($fs === 'incoming'): ?>
      <div class="flex gap-2">
        <button type="button" id="friend-accept" class="btn-primary flex-1 justify-center !bg-green-600 hover:!bg-green-500">Accept</button>
        <button type="button" id="friend-decline" class="btn-ghost flex-1">Decline</button>
      </div>
      <?php elseif ($fs === 'friend'): ?>
      <button type="button" id="friend-remove" class="btn-ghost w-full justify-center text-red-400">Remove Friend</button>
      <?php endif; ?>
      <?php if ($fs !== 'blocked_by_me'): ?>
      <button type="button" id="friend-block" class="btn-ghost w-full justify-center text-red-400">Block User</button>
      <?php else: ?>
      <button type="button" id="friend-unblock" class="btn-ghost w-full justify-center">Unblock</button>
      <?php endif; ?>
      <div id="friend-msg" class="text-sm text-green-400 hidden"></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="md:col-span-2 space-y-6">
    <div class="card p-6">
      <h2 class="font-semibold text-white mb-4">Channels — <?= count($channels) ?></h2>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($channels as $c): ?>
        <a href="/app?channel=<?= h(rawurlencode($c['slug'])) ?>" class="px-3 py-1.5 rounded-md bg-discord-750 hover:bg-discord-700 text-sm"><?= h($c['name']) ?></a>
        <?php endforeach; ?>
        <?php if (!$channels): ?><span class="text-sm text-discord-500">Not in any channels.</span><?php endif; ?>
      </div>
    </div>

    <?php if ($isSelf && !(int) ($user['guest'] ?? 0)): ?>
    <?php
    $soundSelect = function (?int $selected, string $name) use ($sounds): string {
        $html = '<select class="input !py-1.5" name="' . h($name) . '">';
        $html .= '<option value="0"' . ($selected === null ? ' selected' : '') . '>Off (muted)</option>';
        foreach ($sounds as $s) {
            $html .= '<option value="' . (int) $s['id'] . '"' . ($selected !== null && (int) $s['id'] === $selected ? ' selected' : '') . '>' . h($s['name']) . '</option>';
        }
        return $html . '</select>';
    };
    ?>

    <?php
    // ── My theme ───────────────────────────────────────────────────────────
    $ut = $userThemeJson;
    $uo = $ut['overrides'];
    $umode = $ut['mode'] !== '' ? $ut['mode'] : ($effectiveTheme['mode'] === 'light' ? 'light' : 'dark');
    $ufont = $uo['font'] ?? 'default';
    $ufit = $uo['chat_bg_fit'] ?? 'contain';
    $ubgColor = $uo['chat_bg_color'] ?? '';
    $ubgImage = $uo['chat_bg_image'] ?? '';
    $uOverlay = isset($uo['chat_bg_overlay']) ? (int) $uo['chat_bg_overlay'] : ThemeService::CHAT_BG_OVERLAY_DEFAULT;
    ?>
    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">My theme</h2>
      <?php if (!$themeCustomizationEnabled): ?>
      <p class="text-xs text-discord-400">Theme customization has been disabled by the administrator. You're using the server theme.</p>
      <?php else: ?>
      <p class="text-xs text-discord-400 mb-4">Pick a preset and fine-tune it to override the server theme. Changes preview instantly on this page.</p>

      <div class="flex flex-wrap items-center gap-3 mb-3">
        <div class="flex items-center gap-2 text-sm">
          <span class="text-xs font-semibold uppercase tracking-wide text-discord-400">Mode</span>
          <button type="button" data-mode="dark" class="p-mode-btn px-3 py-1 rounded-md text-sm font-medium border <?= $umode === 'dark' ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700' ?>">Dark</button>
          <button type="button" data-mode="light" class="p-mode-btn px-3 py-1 rounded-md text-sm font-medium border <?= $umode === 'light' ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700' ?>">Light</button>
        </div>
        <div class="flex items-center gap-2 text-sm ml-auto">
          <span class="text-xs font-semibold uppercase tracking-wide text-discord-400">Font</span>
          <select id="p-theme-font" class="input !w-40 !py-1.5">
            <?php foreach (array_keys(ThemeService::FONTS) as $f): ?>
            <option value="<?= h($f) ?>" <?= $ufont === $f ? 'selected' : '' ?>><?= h(ucfirst($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 max-h-56 overflow-y-auto scrollbar-thin pr-1 mb-4" id="p-preset-grid">
        <?php foreach ($themePresets as $p): ?>
        <button type="button" data-preset="<?= h($p['id']) ?>"
                class="p-preset-card text-left rounded-lg border p-2 transition-colors <?= $ut['preset'] === $p['id'] ? 'border-blurple/70 bg-blurple/10' : 'border-discord-600 hover:border-discord-400 hover:bg-discord-700' ?>">
          <div class="flex gap-1 mb-1">
            <span class="w-3.5 h-3.5 rounded-full border border-black/30" style="background:<?= h($p['swatch']['accent']) ?>" title="Accent"></span>
            <span class="w-3.5 h-3.5 rounded-full border border-black/30" style="background:<?= h($p['swatch']['sidebar']) ?>" title="Sidebar"></span>
            <span class="w-3.5 h-3.5 rounded-md border border-black/30" style="background:<?= h($p['swatch']['surface']) ?>" title="Surface"></span>
          </div>
          <div class="text-[11px] text-discord-200 truncate"><?= h($p['name']) ?></div>
        </button>
        <?php endforeach; ?>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
        <div>
          <label class="label">Accent colour <button type="button" data-clear="accent" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">use preset</button></label>
          <div class="flex items-center gap-2">
            <input type="color" id="p-theme-accent" value="<?= h($uo['accent'] ?? ($themePresets[0]['accent'] ?? '#5865f2')) ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
            <span class="text-xs text-discord-400 font-mono" id="p-accent-label"><?= !empty($uo['accent']) ? h(strtoupper(ltrim($uo['accent'], '#'))) : 'preset' ?></span>
          </div>
        </div>
        <div>
          <label class="label">Sidebar colour <button type="button" data-clear="sidebar" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">use preset</button></label>
          <div class="flex items-center gap-2">
            <input type="color" id="p-theme-sidebar" value="<?= h($uo['sidebar'] ?? ($themePresets[0]['sidebar'] ?? '#2b2d31')) ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
            <span class="text-xs text-discord-400 font-mono" id="p-sidebar-label"><?= !empty($uo['sidebar']) ? h(strtoupper(ltrim($uo['sidebar'], '#'))) : 'preset' ?></span>
          </div>
        </div>
        <div>
          <label class="label">Chat background colour <button type="button" data-clear="chat_bg_color" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">none</button></label>
          <div class="flex items-center gap-2">
            <input type="color" id="p-theme-bg-color" value="<?= h($ubgColor !== '' ? $ubgColor : '#313338') ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
            <span class="text-xs text-discord-400 font-mono" id="p-bg-color-label"><?= $ubgColor !== '' ? h(strtoupper(ltrim($ubgColor, '#'))) : 'none' ?></span>
          </div>
        </div>
        <div>
          <label class="label">Chat background image</label>
          <div class="flex flex-wrap items-center gap-2">
            <label class="btn-ghost !py-1.5 text-xs cursor-pointer">Upload<input type="file" id="p-theme-bg-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"></label>
            <?php if ($ubgImage !== ''): ?>
            <button type="button" id="p-theme-bg-remove" class="btn-ghost !py-1.5 text-xs text-red-400">Remove</button>
            <?php endif; ?>
          </div>
          <?php if ($ubgImage !== ''): ?>
          <img src="<?= h(url($ubgImage)) ?>" alt="Your chat background" class="mt-2 h-12 w-24 object-cover rounded border border-discord-600">
          <?php endif; ?>
        </div>
        <div>
          <label class="label">Image fit</label>
          <select id="p-theme-bg-fit" class="input !py-1.5">
            <?php foreach (ThemeService::CHAT_BG_FITS as $f): ?>
            <option value="<?= h($f) ?>" <?= $ufit === $f ? 'selected' : '' ?>><?= h(ucfirst($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-4 max-w-md">
        <label class="label">Overlay opacity <span id="p-bg-overlay-label" class="text-discord-400 normal-case"><?= (int) $uOverlay ?>%</span></label>
        <input type="range" id="p-theme-bg-overlay" min="0" max="100" step="5" value="<?= (int) $uOverlay ?>" class="w-full accent-blurple cursor-pointer">
        <p class="text-xs text-discord-400 mt-1">A translucent layer between the text and the image — raise it when a busy image makes chat hard to read.</p>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button class="btn-primary" id="p-theme-save">Save theme</button>
        <button class="btn-ghost" id="p-theme-reset">Reset to server default</button>
        <span id="p-theme-msg" class="text-sm text-green-400 hidden">Saved.</span>
      </div>
      <?php endif; ?>
    </div>

    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">Notification sounds</h2>
      <p class="text-xs text-discord-400 mb-4">Sounds play when a DM arrives or a message lands in a channel you're not viewing. Pick an alert per context, or mute each entirely.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="label">Direct messages</label>
          <div class="flex gap-2">
            <?= $soundSelect($soundPrefs['dm_sound_id'], 'dm') ?>
            <button type="button" class="btn-ghost shrink-0" data-test-sound="dm">Play</button>
          </div>
        </div>
        <div>
          <label class="label">Channel messages</label>
          <div class="flex gap-2">
            <?= $soundSelect($soundPrefs['channel_sound_id'], 'channel') ?>
            <button type="button" class="btn-ghost shrink-0" data-test-sound="channel">Play</button>
          </div>
        </div>
      </div>
      <div id="sounds-msg" class="mt-3 text-sm text-green-400 hidden">Saved.</div>

      <div class="mt-6 pt-5 border-t border-discord-700">
        <div class="text-sm font-medium text-white mb-1">Per-user overrides</div>
        <p class="text-xs text-discord-400 mb-3">Give a specific person their own sound (or mute them) everywhere — DMs and channel messages.</p>
        <?php if ($soundOverrides): ?>
        <div class="space-y-2 mb-4">
          <?php foreach ($soundOverrides as $uid => $o): ?>
          <div class="flex items-center gap-2" data-override="<?= (int) $uid ?>">
            <span class="text-sm text-discord-200 w-40 truncate"><?= h($o['username']) ?></span>
            <?= $soundSelect($o['sound_id'], 'sound') ?>
            <button type="button" class="btn-ghost text-xs text-red-400 !py-1 shrink-0" data-remove-override="<?= (int) $uid ?>">Remove</button>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form id="override-form" class="flex flex-wrap items-end gap-2">
          <?= Csrf::field() ?>
          <div class="min-w-44 flex-1">
            <label class="label">User</label>
            <select name="target_user_id" class="input !py-1.5" required>
              <?php foreach ($allUsers as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= h($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="min-w-40">
            <label class="label">Sound</label>
            <?= $soundSelect(null, 'sound') ?>
          </div>
          <button class="btn-ghost">Add override</button>
        </form>
      </div>
    </div>

    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">Push notifications</h2>
      <p class="text-xs text-discord-400 mb-4">OS/browser notifications for new channel messages, direct messages, and channel invites — even when the chat is in the background. Requires a secure (HTTPS) connection and a one-time permission.</p>
      <div class="flex flex-wrap items-center gap-2 mb-4">
        <button type="button" id="push-enable" class="btn-primary">Enable push notifications</button>
        <button type="button" id="push-disable" class="btn-ghost hidden">Disable</button>
        <span id="push-status" class="text-sm text-discord-300"></span>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="push-prefs">
        <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
          <input type="checkbox" data-pref="channels" class="w-4 h-4 accent-blurple" <?= $pushPrefs['channels'] ? 'checked' : '' ?>> Channel messages
        </label>
        <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
          <input type="checkbox" data-pref="dms" class="w-4 h-4 accent-blurple" <?= $pushPrefs['dms'] ? 'checked' : '' ?>> Direct messages
        </label>
        <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
          <input type="checkbox" data-pref="invites" class="w-4 h-4 accent-blurple" <?= $pushPrefs['invites'] ? 'checked' : '' ?>> Channel invites
        </label>
      </div>

      <div class="mt-6 pt-5 border-t border-discord-700">
        <div class="text-sm font-medium text-white mb-1">Alert preferences</div>
        <p class="text-xs text-discord-400 mb-4">Applied to every surface — sounds, in-app toasts, and OS notifications.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
          <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
            <input type="checkbox" data-notify="sound_master" class="w-4 h-4 accent-blurple" <?= $notifyPrefs['sound_master'] ? 'checked' : '' ?>> Play sounds
          </label>
          <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
            <input type="checkbox" data-notify="os_master" class="w-4 h-4 accent-blurple" <?= $notifyPrefs['os_master'] ? 'checked' : '' ?>> OS &amp; in-app alerts
          </label>
          <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer">
            <input type="checkbox" data-notify="previews" class="w-4 h-4 accent-blurple" <?= $notifyPrefs['previews'] ? 'checked' : '' ?>> Show message previews
          </label>
        </div>
        <button type="button" id="notify-test" class="btn-ghost text-xs !py-1.5">Send test notification</button>

        <div class="mt-5 pt-5 border-t border-discord-700">
          <div class="text-sm font-medium text-white mb-1">Quiet hours</div>
          <p class="text-xs text-discord-400 mb-3">Suppress sounds and alerts between a start and end time. Evaluated in your local time; server push uses your saved UTC offset.</p>
          <label class="flex items-center gap-2 text-sm text-discord-200 cursor-pointer mb-3">
            <input type="checkbox" id="qh-enabled" class="w-4 h-4 accent-blurple" <?= $notifyPrefs['quiet_hours_enabled'] ? 'checked' : '' ?>> Enable quiet hours
          </label>
          <div class="flex flex-wrap items-center gap-3">
            <label class="label mb-0">From
              <input type="time" id="qh-start" value="<?= h($notifyPrefs['quiet_hours_start']) ?>" class="input !py-1.5 w-32">
            </label>
            <label class="label mb-0">To
              <input type="time" id="qh-end" value="<?= h($notifyPrefs['quiet_hours_end']) ?>" class="input !py-1.5 w-32">
            </label>
          </div>
          <div class="mt-3">
            <div class="text-xs text-discord-400 mb-1">Apply on these days <span class="text-discord-500">(none = every day)</span></div>
            <div class="flex flex-wrap gap-1.5" id="qh-days">
              <?php $qhDays = array_map('intval', (array) $notifyPrefs['quiet_hours_days']); foreach ([['Sun', 0], ['Mon', 1], ['Tue', 2], ['Wed', 3], ['Thu', 4], ['Fri', 5], ['Sat', 6]] as [$dl, $dv]): ?>
              <button type="button" data-day="<?= $dv ?>" class="qh-day px-2.5 py-1 rounded-md text-xs font-medium border transition-colors <?= in_array($dv, $qhDays, true) ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-400 hover:text-discord-200' ?>"><?= $dl ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="mt-5 pt-5 border-t border-discord-700">
          <div class="text-sm font-medium text-white mb-1">Highlight keywords</div>
          <p class="text-xs text-discord-400 mb-3">Messages containing these words alert you like an @mention — even in channels set to "mentions only". One per line, up to 25.</p>
          <textarea id="kw-input" rows="3" class="input font-mono text-xs" placeholder="release&#10;deploy&#10;urgent"><?= h(implode("\n", array_map('strval', (array) $notifyPrefs['highlight_keywords']))) ?></textarea>
        </div>
        <p id="notify-prefs-msg" class="mt-3 text-sm text-green-400 hidden">Saved.</p>
      </div>

      <div class="mt-6 pt-5 border-t border-discord-700">
        <div class="text-sm font-medium text-white mb-1">Per-channel notification modes</div>
        <p class="text-xs text-discord-400 mb-3">All messages, mentions only, or nothing for each channel you've joined. The same control as the 🔔 button above each channel.</p>
        <div class="space-y-1.5 max-h-64 overflow-y-auto scrollbar-thin">
          <?php foreach ($chanModes as $cm): ?>
          <div class="flex items-center gap-3">
            <span class="text-sm text-discord-200 w-40 truncate"><?= h($cm['name']) ?></span>
            <select class="input !py-1 !text-xs chan-mode" data-slug="<?= h($cm['slug']) ?>">
              <option value="all" <?= $cm['mode'] === 'all' ? 'selected' : '' ?>>All messages</option>
              <option value="mentions" <?= $cm['mode'] === 'mentions' ? 'selected' : '' ?>>Mentions only</option>
              <option value="muted" <?= $cm['mode'] === 'muted' ? 'selected' : '' ?>>Nothing</option>
            </select>
          </div>
          <?php endforeach; ?>
          <?php if (!$chanModes): ?><div class="text-xs text-discord-500">No channels joined yet.</div><?php endif; ?>
        </div>
      </div>

      <div class="mt-6 pt-5 border-t border-discord-700">
        <div class="text-sm font-medium text-white mb-1">Muted users</div>
        <p class="text-xs text-discord-400 mb-3">Muting someone silences every notification from them — push, the notification bell, sounds, and in-app toasts. They can still message you.</p>
        <div id="mute-list" class="space-y-2 mb-4">
          <?php foreach ($pushMutedUsers as $m): ?>
          <div class="flex items-center gap-2" data-mute="<?= (int) $m['muted_user_id'] ?>">
            <span class="text-sm text-discord-200 w-40 truncate"><?= h($m['username']) ?></span>
            <button type="button" class="btn-ghost text-xs text-red-400 !py-1 shrink-0" data-unmute="<?= (int) $m['muted_user_id'] ?>">Unmute</button>
          </div>
          <?php endforeach; ?>
        </div>
        <form id="push-mute-form" class="flex flex-wrap items-end gap-2">
          <?= Csrf::field() ?>
          <div class="min-w-44 flex-1">
            <label class="label">User</label>
            <select name="user_id" class="input !py-1.5" required>
              <?php foreach ($allUsers as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= h($u['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn-ghost">Mute user</button>
        </form>
      </div>
    </div>

    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">Privacy</h2>
      <p class="text-xs text-discord-400 mb-4">Control whether other users can find you in search results.</p>
      <form id="privacy-form" class="space-y-3">
        <?= Csrf::field() ?>
        <label class="flex items-center gap-3 cursor-pointer">
          <input type="checkbox" name="searchable" value="1" class="w-4 h-4 accent-blurple" <?= (int) ($user['searchable'] ?? 1) ? 'checked' : '' ?>>
          <div>
            <span class="text-sm text-discord-200">Allow others to find me in search</span>
            <p class="text-xs text-discord-400">When disabled, your profile won't appear in the user directory or search results.</p>
          </div>
        </label>
      </form>
    </div>

    <div class="card p-6">
      <h2 class="font-semibold text-white mb-4">Account settings</h2>
      <form id="pw-form" class="space-y-4">
        <?= Csrf::field() ?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <input class="input" type="password" name="current" placeholder="Current password" required>
          <input class="input" type="password" name="new" placeholder="New password (8+ chars)" required minlength="8">
          <button class="btn-primary justify-center">Change password</button>
        </div>
      </form>
      <form id="vhost-form" class="mt-5 pt-5 border-t border-discord-700 space-y-4">
        <?= Csrf::field() ?>
        <div class="flex flex-wrap gap-3 items-end">
          <div class="flex-1 min-w-40">
            <label class="label">Virtual host (vhost)</label>
            <input class="input" name="vhost" placeholder="user.chat.example" value="<?= h(str_replace('|hide', '', (string) $user['vhost'])) ?>">
          </div>
          <button class="btn-primary">Save vhost</button>
        </div>
      </form>
      <div id="profile-msg" class="mt-3 text-sm text-green-400 hidden">Saved.</div>
    </div>

    <div class="card p-6">
      <h2 class="font-semibold text-white mb-1">Two-factor authentication</h2>
      <p class="text-xs text-discord-400 mb-4">Require a 6-digit code from an authenticator app (Aegis, Google Authenticator, 1Password, …) every time you sign in.</p>
      <?php if (TotpService::enabled($user)): ?>
      <div class="flex items-center gap-2 mb-4">
        <span class="px-2 py-0.5 rounded text-[11px] font-semibold bg-green-500/20 text-green-400">Enabled</span>
        <span class="text-xs text-discord-400">since <?= h(date('M j, Y', strtotime($user['totp_enabled_at'] . ' UTC'))) ?></span>
      </div>
      <?php if (TotpService::requiredFor($user)): ?>
      <p class="text-xs text-amber-300">MFA is required for your account class and cannot be disabled.</p>
      <?php else: ?>
      <form id="mfa-disable-form" class="flex flex-wrap gap-3 items-end">
        <?= Csrf::field() ?>
        <div class="flex-1 min-w-40">
          <label class="label">Confirm password to disable</label>
          <input class="input" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn-ghost text-red-400">Disable MFA</button>
      </form>
      <?php endif; ?>
      <?php else: ?>
      <div id="mfa-enroll">
        <button type="button" id="mfa-begin" class="btn-primary">Enable two-factor authentication</button>
      </div>
      <div id="mfa-setup" class="hidden">
        <div class="flex flex-col sm:flex-row gap-5 items-start">
          <div id="mfa-qr" class="bg-white rounded-lg p-3 shrink-0"></div>
          <div class="flex-1 min-w-0">
            <p class="text-xs text-discord-400 mb-2">Scan the QR code with your authenticator app, or enter this key manually:</p>
            <div id="mfa-secret" class="font-mono text-sm text-discord-200 bg-discord-850 border border-discord-700 rounded px-3 py-2 select-all break-all mb-4"></div>
            <form id="mfa-enable-form" class="flex flex-wrap gap-2 items-end">
              <?= Csrf::field() ?>
              <div class="flex-1 min-w-32">
                <label class="label">6-digit code</label>
                <input class="input font-mono tracking-[0.3em]" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code" placeholder="000000">
              </div>
              <button class="btn-primary">Verify &amp; enable</button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($isSelf && !(int) ($user['guest'] ?? 0) && !TotpService::enabled($user)): ?>
<script src="/assets/js/qrcode.min.js"></script>
<?php endif; ?>
<script>
(() => {
  const csrf = document.querySelector('input[name=csrf]')?.value || '';
  const mfaBegin = document.getElementById('mfa-begin');
  if (mfaBegin) mfaBegin.addEventListener('click', () => {
    const fd = new FormData();
    fd.append('csrf', csrf);
    fetch('/api/mfa/begin', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(r => r.json()).then(j => {
        if (j.error) { LVCDialog.alert(j.error); return; }
        document.getElementById('mfa-enroll').classList.add('hidden');
        document.getElementById('mfa-setup').classList.remove('hidden');
        document.getElementById('mfa-secret').textContent = j.secret;
        const qrBox = document.getElementById('mfa-qr');
        if (typeof qrcode !== 'undefined') {
          const qr = qrcode(0, 'M');
          qr.addData(j.uri);
          qr.make();
          qrBox.innerHTML = qr.createImgTag(4, 0);
        } else {
          qrBox.classList.add('hidden');
        }
      });
  });
  const mfaEnable = document.getElementById('mfa-enable-form');
  if (mfaEnable) mfaEnable.addEventListener('submit', e => {
    e.preventDefault();
    post('/api/mfa/enable', new FormData(mfaEnable), () => location.reload());
  });
  const mfaDisable = document.getElementById('mfa-disable-form');
  if (mfaDisable) mfaDisable.addEventListener('submit', e => {
    e.preventDefault();
    LVCDialog.confirm('Disable two-factor authentication?').then(ok => {
      if (ok) post('/api/mfa/disable', new FormData(mfaDisable), () => location.reload());
    });
  });
  const msg = document.getElementById('profile-msg');
  function post(url, fd, ok) {
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(r => r.json()).then(j => { if (j.error) { LVCDialog.alert(j.error); return; } if (ok) ok(); });
  }
  const pw = document.getElementById('pw-form');
  if (pw) pw.addEventListener('submit', e => {
    e.preventDefault();
    const fd = new FormData(pw);
    post('/api/password', fd, () => { pw.reset(); LVCDialog.alert('Password changed.'); });
  });
  const vh = document.getElementById('vhost-form');
  if (vh) vh.addEventListener('submit', e => {
    e.preventDefault();
    post('/api/profile', new FormData(vh), () => {
      msg.classList.remove('hidden');
      setTimeout(() => msg.classList.add('hidden'), 2000);
    });
  });
  const pf = document.getElementById('privacy-form');
  if (pf) pf.addEventListener('change', e => {
    const fd = new FormData(pf);
    if (!fd.has('searchable')) fd.set('searchable', '0');
    post('/api/profile', fd, () => {
      msg.classList.remove('hidden');
      setTimeout(() => msg.classList.add('hidden'), 2000);
    });
  });
  const avFile = document.getElementById('avatar-file');
  if (avFile) avFile.addEventListener('change', () => {
    const f = avFile.files && avFile.files[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('avatar', f);
    post('/api/avatar', fd, () => location.reload());
  });
  const avRemove = document.getElementById('avatar-remove');
  if (avRemove) avRemove.addEventListener('click', () => post('/api/avatar/remove', new FormData(), () => location.reload()));

  const SOUND_URLS = <?= json_encode(array_combine(array_map('intval', array_column($sounds, 'id')), array_map(fn ($s) => url($s['file']), $sounds))) ?>;
  const sndMsg = document.getElementById('sounds-msg');
  function sndPost(url, fd, ok) {
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(r => r.json()).then(j => { if (j.error) { LVCDialog.alert(j.error); return; } if (ok) ok(); });
  }
  function playSound(id) {
    const u = SOUND_URLS[id] || '';
    if (!u) return;
    try { new Audio(u).play().catch(() => {}); } catch (e) {}
  }
  document.querySelectorAll('[data-test-sound]').forEach(btn => {
    btn.addEventListener('click', () => {
      const sel = document.querySelector('select[name="' + btn.dataset.testSound + '"]');
      playSound(sel ? parseInt(sel.value, 10) || 0 : 0);
    });
  });
  ['dm', 'channel'].forEach(name => {
    const sel = document.querySelector('select[name="' + name + '"]');
    if (!sel) return;
    sel.addEventListener('change', () => {
      const fd = new FormData();
      fd.append('dm_sound', document.querySelector('select[name="dm"]').value);
      fd.append('channel_sound', document.querySelector('select[name="channel"]').value);
      sndPost('/api/sound/prefs', fd, () => {
        sndMsg.classList.remove('hidden');
        setTimeout(() => sndMsg.classList.add('hidden'), 2000);
      });
    });
  });
  document.querySelectorAll('[data-override] select[name="sound"]').forEach(sel => {
    sel.addEventListener('change', () => {
      const row = sel.closest('[data-override]');
      const fd = new FormData();
      fd.append('target_user_id', row.dataset.override);
      fd.append('sound', sel.value);
      sndPost('/api/sound/override', fd);
    });
  });
  document.querySelectorAll('[data-remove-override]').forEach(btn => {
    btn.addEventListener('click', () => {
      const fd = new FormData();
      fd.append('target_user_id', btn.dataset.removeOverride);
      sndPost('/api/sound/override/remove', fd, () => {
        const row = btn.closest('[data-override]');
        if (row) row.remove();
      });
    });
  });
  const ovForm = document.getElementById('override-form');
  if (ovForm) ovForm.addEventListener('submit', e => {
    e.preventDefault();
    sndPost('/api/sound/override', new FormData(ovForm), () => location.reload());
  });

  // ── Push notifications ─────────────────────────────────────────────────────
  const VAPID_KEY = <?= json_encode($vapidPublicKey) ?>;
  const pushSupported = !!VAPID_KEY && ('serviceWorker' in navigator)
    && ('PushManager' in window) && ('Notification' in window) && window.isSecureContext;
  const pushEnable = document.getElementById('push-enable');
  const pushDisable = document.getElementById('push-disable');
  const pushStatus = document.getElementById('push-status');
  function b64uToBytes(b64) {
    const bin = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
    const bytes = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    return bytes;
  }
  function b64uFromBytes(bytes) {
    let bin = '';
    bytes.forEach((b) => { bin += String.fromCharCode(b); });
    return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function pushPayload(sub) {
    const p256 = sub.getKey ? sub.getKey('p256dh') : null;
    const auth = sub.getKey ? sub.getKey('auth') : null;
    if (!p256 || !auth) return null;
    return { endpoint: sub.endpoint, p256dh: b64uFromBytes(new Uint8Array(p256)), auth: b64uFromBytes(new Uint8Array(auth)) };
  }
  function renderPushState() {
    if (!pushSupported || !pushEnable || !pushStatus) return;
    if (Notification.permission === 'granted') {
      navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => {
        const on = !!sub;
        pushEnable.classList.toggle('hidden', on);
        pushDisable.classList.toggle('hidden', !on);
        pushStatus.textContent = on ? '✓ Notifications on' : 'Not subscribed';
      }).catch(() => {});
    } else {
      pushEnable.classList.remove('hidden');
      pushDisable.classList.add('hidden');
      const denied = Notification.permission === 'denied';
      pushStatus.textContent = denied ? 'Blocked in browser settings' : 'Click enable to allow notifications';
    }
  }
  function subscribePush() {
    return Notification.requestPermission().then((perm) => {
      if (perm !== 'granted') { renderPushState(); return; }
      return navigator.serviceWorker.ready
        .then((reg) => reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(VAPID_KEY) }))
        .then((sub) => {
          const payload = pushPayload(sub);
          if (!payload) return;
          return fetch('/api/push/subscribe', { method: 'POST', body: (() => {
            const fd = new FormData(); fd.append('csrf', csrf); fd.append('endpoint', payload.endpoint);
            fd.append('p256dh', payload.p256dh); fd.append('auth', payload.auth); return fd;
          })(), headers: { 'X-CSRF': csrf } });
        })
        .catch(() => {})
        .then(() => renderPushState());
    });
  }
  function unsubscribePush() {
    const done = () => post('/api/push/unsubscribe', new FormData(), () => renderPushState());
    if (!('serviceWorker' in navigator)) { done(); return; }
    navigator.serviceWorker.ready
      .then((reg) => reg.pushManager.getSubscription())
      .then((sub) => (sub ? sub.unsubscribe() : Promise.resolve()))
      .catch(() => {})
      .then(done);
  }
  if (pushEnable) pushEnable.addEventListener('click', subscribePush);
  if (pushDisable) pushDisable.addEventListener('click', unsubscribePush);
  renderPushState();

  const pushPrefs = document.getElementById('push-prefs');
  if (pushPrefs) pushPrefs.addEventListener('change', () => {
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('channels', document.querySelector('[data-pref="channels"]').checked ? '1' : '0');
    fd.append('dms', document.querySelector('[data-pref="dms"]').checked ? '1' : '0');
    fd.append('invites', document.querySelector('[data-pref="invites"]').checked ? '1' : '0');
    fetch('/api/push/prefs', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } }).catch(() => {});
  });
  document.querySelectorAll('[data-unmute]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const fd = new FormData();
      fd.append('user_id', btn.dataset.unmute);
      post('/api/push/unmute', fd, () => {
        const row = btn.closest('[data-mute]');
        if (row) row.remove();
      });
    });
  });
  const pushMuteForm = document.getElementById('push-mute-form');
  if (pushMuteForm) pushMuteForm.addEventListener('submit', e => {
    e.preventDefault();
    post('/api/push/mute', new FormData(pushMuteForm), () => location.reload());
  });

  // ── Unified notification preferences (masters / quiet hours / keywords) ────
  const notifyMsg = document.getElementById('notify-prefs-msg');
  let notifyTimer = 0;
  function saveNotifyPrefs() {
    clearTimeout(notifyTimer);
    notifyTimer = setTimeout(() => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      document.querySelectorAll('[data-notify]').forEach((el) => {
        fd.append(el.dataset.notify, el.checked ? '1' : '0');
      });
      fd.append('quiet_hours_enabled', document.getElementById('qh-enabled').checked ? '1' : '0');
      fd.append('quiet_hours_start', document.getElementById('qh-start').value || '22:00');
      fd.append('quiet_hours_end', document.getElementById('qh-end').value || '08:00');
      fd.append('quiet_hours_days', JSON.stringify([...document.querySelectorAll('.qh-day.on')].map((b) => parseInt(b.dataset.day, 10))));
      const kws = (document.getElementById('kw-input').value || '').split(/\n/).map((s) => s.trim()).filter(Boolean).slice(0, 25);
      fd.append('highlight_keywords', JSON.stringify(kws));
      const off = -new Date().getTimezoneOffset();
      fd.append('tz_offset_minutes', String(off));
      fetch('/api/notify/prefs', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
        .then((r) => r.json())
        .then((j) => {
          if (notifyMsg) { notifyMsg.classList.remove('hidden'); setTimeout(() => notifyMsg.classList.add('hidden'), 2000); }
          const en = (j.prefs && j.prefs.notify && j.prefs.notify.quiet_hours_enabled);
          ['qh-start', 'qh-end', '#qh-days', '#kw-input'].forEach(() => {});
        })
        .catch(() => {});
    }, 400);
  }
  document.querySelectorAll('[data-notify]').forEach((el) => el.addEventListener('change', saveNotifyPrefs));
  ['qh-enabled', 'qh-start', 'qh-end'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', saveNotifyPrefs);
  });
  document.querySelectorAll('.qh-day').forEach((b) => {
    b.classList.toggle('on', b.classList.contains('bg-blurple/20'));
    b.addEventListener('click', () => {
      if (b.classList.contains('on')) {
        b.classList.remove('on');
        b.className = 'qh-day px-2.5 py-1 rounded-md text-xs font-medium border transition-colors border-discord-600 text-discord-400 hover:text-discord-200';
      } else {
        b.className = 'qh-day on px-2.5 py-1 rounded-md text-xs font-medium border transition-colors bg-blurple/20 border-blurple/50 text-white';
      }
      saveNotifyPrefs();
    });
  });
  const kwInput = document.getElementById('kw-input');
  if (kwInput) kwInput.addEventListener('change', saveNotifyPrefs);
  const notifyTest = document.getElementById('notify-test');
  if (notifyTest) notifyTest.addEventListener('click', () => {
    const fd = new FormData();
    fetch('/api/push/test', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
      .then(() => { notifyTest.textContent = 'Test sent'; setTimeout(() => { notifyTest.textContent = 'Send test notification'; }, 2000); })
      .catch(() => { notifyTest.textContent = 'Test failed'; });
  });
  // Per-channel notification modes (mirrors the 🔔 button in the chat header).
  document.querySelectorAll('.chan-mode').forEach((sel) => {
    sel.addEventListener('change', () => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('channel', sel.dataset.slug);
      fd.append('mode', sel.value);
      fetch('/api/channel/notify', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } }).catch(() => {});
    });
  });

  const themeBtn = document.getElementById('profile-theme-toggle');
  if (themeBtn) {
    function setIcon() { themeBtn.innerHTML = (document.documentElement.classList.contains('light') ? window.icon('sun', 'w-4 h-4') : window.icon('moon', 'w-4 h-4')) + ' ' + (document.documentElement.classList.contains('light') ? 'Light mode' : 'Dark mode'); }
    setIcon();
    themeBtn.addEventListener('click', () => {
      const light = document.documentElement.classList.toggle('light');
      const theme = light ? 'light' : 'dark';
      try { localStorage.setItem('lvc.theme', theme); } catch (e) {}
      setIcon();
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('ajax', '1');
      fd.append('theme', theme);
      fetch('/api/profile', { method: 'POST', body: fd }).catch(() => {});
    });
  }

  const fa = document.getElementById('friend-actions');
  if (fa) {
    const uname = fa.dataset.friendUsername;
    const fmsg = document.getElementById('friend-msg');
    function friendPost(url, ok) {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('username', uname);
      fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
        .then(r => r.json()).then(j => {
          if (j.error) { LVCDialog.alert(j.error); return; }
          if (fmsg) { fmsg.textContent = j.message || 'Done.'; fmsg.classList.remove('hidden'); }
          if (ok) ok();
          setTimeout(() => location.reload(), 800);
        });
    }
    const addBtn = document.getElementById('friend-add');
    if (addBtn) addBtn.addEventListener('click', () => friendPost('/api/friend/request'));
    const cancelBtn = document.getElementById('friend-cancel');
    if (cancelBtn) cancelBtn.addEventListener('click', () => friendPost('/api/friend/cancel'));
    const acceptBtn = document.getElementById('friend-accept');
    if (acceptBtn) acceptBtn.addEventListener('click', () => friendPost('/api/friend/accept'));
    const declineBtn = document.getElementById('friend-decline');
    if (declineBtn) declineBtn.addEventListener('click', () => friendPost('/api/friend/decline'));
    const removeBtn = document.getElementById('friend-remove');
    if (removeBtn) removeBtn.addEventListener('click', () => LVCDialog.confirm('Remove this friend?').then(ok => { if (ok) friendPost('/api/friend/remove'); }));
    const blockBtn = document.getElementById('friend-block');
    if (blockBtn) blockBtn.addEventListener('click', () => LVCDialog.confirm('Block this user?').then(ok => { if (ok) friendPost('/api/friend/block'); }));
    const unblockBtn = document.getElementById('friend-unblock');
    if (unblockBtn) unblockBtn.addEventListener('click', () => friendPost('/api/friend/unblock'));
  }

  // ── My theme editor (self-view only: it embeds the owner's personal theme
  //     incl. their uploaded background image path, which must not be sent to
  //     other viewers) ───────────────────────────────────────────────────────
  <?php if ($isSelf && !(int) ($user['guest'] ?? 0)): ?>
  const pStyleEl = document.getElementById('theme-css');
  const pPresets = <?= json_encode(array_column($themePresets, null, 'id')) ?>;
  <?php
  $pUt = $userThemeJson;
  $pUo = $pUt['overrides'];
  $pUmode = $pUt['mode'] !== '' ? $pUt['mode'] : ($effectiveTheme['mode'] === 'light' ? 'light' : 'dark');
  $pUfont = $pUo['font'] ?? 'default';
  $pUfit = $pUo['chat_bg_fit'] ?? 'contain';
  $pUbgColor = $pUo['chat_bg_color'] ?? '';
  $pUbgImage = $pUo['chat_bg_image'] ?? '';
  $pUoverlay = isset($pUo['chat_bg_overlay']) ? (int) $pUo['chat_bg_overlay'] : ThemeService::CHAT_BG_OVERLAY_DEFAULT;
  ?>
  const pState = {
    preset: <?= json_encode($pUt['preset']) ?>,
    mode: <?= json_encode($pUmode) ?>,
    accent: <?= json_encode($pUo['accent'] ?? '') ?>,
    sidebar: <?= json_encode($pUo['sidebar'] ?? '') ?>,
    font: <?= json_encode($pUfont) ?>,
    chat_bg_color: <?= json_encode($pUbgColor) ?>,
    chat_bg_fit: <?= json_encode($pUfit) ?>,
    chat_bg_overlay: <?= (int) $pUoverlay ?>,
    chat_bg_image: <?= json_encode($pUbgImage) ?>,
  };
  if (pStyleEl && document.querySelector('.p-preset-card')) {
    let pTimer = null;
    function pRender() {
      clearTimeout(pTimer);
      pTimer = setTimeout(() => {
        const p = new URLSearchParams();
        p.set('preset', pState.preset);
        p.set('mode', pState.mode);
        if (pState.accent) p.set('accent', pState.accent);
        if (pState.sidebar) p.set('sidebar', pState.sidebar);
        p.set('font', pState.font);
        if (pState.chat_bg_color) p.set('chat_bg_color', pState.chat_bg_color);
        if (pState.chat_bg_image) p.set('chat_bg_image', pState.chat_bg_image);
        p.set('chat_bg_fit', pState.chat_bg_fit);
        p.set('chat_bg_overlay', pState.chat_bg_overlay);
        fetch('/api/theme/css?' + p.toString(), { cache: 'no-store' })
          .then(r => r.text())
          .then(css => { pStyleEl.textContent = css; document.documentElement.classList.toggle('light', pState.mode === 'light'); })
          .catch(() => {});
      }, 120);
    }
    function pMarkSelected() {
      document.querySelectorAll('.p-preset-card').forEach(el => {
        const on = el.dataset.preset === pState.preset;
        el.classList.toggle('border-blurple/70', on);
        el.classList.toggle('bg-blurple/10', on);
        el.classList.toggle('border-discord-600', !on);
        el.classList.toggle('hover:border-discord-400', !on);
        el.classList.toggle('hover:bg-discord-700', !on);
      });
    }
    document.querySelectorAll('.p-preset-card').forEach(el => {
      el.addEventListener('click', () => {
        pState.preset = el.dataset.preset;
        if (!pState.accent) document.getElementById('p-theme-accent').value = pPresets[pState.preset].accent;
        if (!pState.sidebar) document.getElementById('p-theme-sidebar').value = pPresets[pState.preset].sidebar;
        pMarkSelected();
        pRender();
      });
    });
    pMarkSelected();
    document.querySelectorAll('.p-mode-btn').forEach(el => {
      el.addEventListener('click', () => {
        pState.mode = el.dataset.mode;
        document.querySelectorAll('.p-mode-btn').forEach(b => {
          const on = b.dataset.mode === pState.mode;
          b.className = 'p-mode-btn px-3 py-1 rounded-md text-sm font-medium border ' + (on ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700');
        });
        pRender();
      });
    });
    document.getElementById('p-theme-font').addEventListener('change', (e) => { pState.font = e.target.value; pRender(); });
    document.getElementById('p-theme-accent').addEventListener('input', (e) => { pState.accent = e.target.value; pRender(); });
    document.getElementById('p-theme-sidebar').addEventListener('input', (e) => { pState.sidebar = e.target.value; pRender(); });
    document.getElementById('p-theme-bg-color').addEventListener('input', (e) => { pState.chat_bg_color = e.target.value; pRender(); });
    document.getElementById('p-theme-bg-fit').addEventListener('change', (e) => { pState.chat_bg_fit = e.target.value; pRender(); });
    document.getElementById('p-theme-bg-overlay').addEventListener('input', (e) => {
      pState.chat_bg_overlay = parseInt(e.target.value, 10) || 0;
      document.getElementById('p-bg-overlay-label').textContent = pState.chat_bg_overlay + '%';
      pRender();
    });
    document.querySelectorAll('.clear-btn').forEach(el => {
      el.addEventListener('click', () => {
        const key = el.dataset.clear;
        pState[key] = '';
        if (key === 'accent') { document.getElementById('p-theme-accent').value = pPresets[pState.preset].accent; document.getElementById('p-accent-label').textContent = 'preset'; }
        if (key === 'sidebar') { document.getElementById('p-theme-sidebar').value = pPresets[pState.preset].sidebar; document.getElementById('p-sidebar-label').textContent = 'preset'; }
        if (key === 'chat_bg_color') { document.getElementById('p-theme-bg-color').value = '#313338'; document.getElementById('p-bg-color-label').textContent = 'none'; }
        pRender();
      });
    });
    const pSave = document.getElementById('p-theme-save');
    if (pSave) pSave.addEventListener('click', () => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('preset', pState.preset);
      fd.append('mode', pState.mode);
      fd.append('accent', pState.accent);
      fd.append('sidebar', pState.sidebar);
      fd.append('font', pState.font);
      fd.append('chat_bg_color', pState.chat_bg_color);
      fd.append('chat_bg_fit', pState.chat_bg_fit);
      fd.append('chat_bg_overlay', pState.chat_bg_overlay);
      fd.append('chat_bg_image', pState.chat_bg_image);
      fetch('/api/theme', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
        .then(r => r.json()).then(j => { if (j.error) { LVCDialog.alert(j.error); return; } location.reload(); });
    });
    const pReset = document.getElementById('p-theme-reset');
    if (pReset) pReset.addEventListener('click', () => {
      LVCDialog.confirm('Reset your theme to the server default?').then(ok => {
        if (!ok) return;
        const fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('preset', '');
        fetch('/api/theme', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
          .then(r => r.json()).then(j => { if (!j.error) location.reload(); });
      });
    });
    const pBg = document.getElementById('p-theme-bg-file');
    if (pBg) pBg.addEventListener('change', () => {
      if (!pBg.files || !pBg.files[0]) return;
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('file', pBg.files[0]);
      fetch('/api/theme/bg', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
        .then(r => r.json()).then(j => { if (!j.error) location.reload(); });
    });
    const pBgRm = document.getElementById('p-theme-bg-remove');
    if (pBgRm) pBgRm.addEventListener('click', () => {
      const fd = new FormData();
      fd.append('csrf', csrf);
      fetch('/api/theme/bg/remove', { method: 'POST', body: fd, headers: { 'X-CSRF': csrf } })
        .then(r => r.json()).then(j => { if (!j.error) location.reload(); });
    });
    pRender();
  }
  <?php endif; ?>
})();
</script>

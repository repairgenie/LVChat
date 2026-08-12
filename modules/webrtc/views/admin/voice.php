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


$title = 'Voice (LiveKit)';
$active = 'voice';
$s = $settings;
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Voice — LiveKit</h1>
  <span class="text-xs text-discord-400 font-mono">module <?= h($module['version'] ?? '') ?> · webrtc</span>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-5 max-w-6xl">
  <form method="post" action="/admin/voice/save" class="card p-6 space-y-5 xl:col-span-2">
    <?= Csrf::field() ?>
    <input type="hidden" name="back" value="/admin/voice">

    <div class="flex items-center justify-between card p-4">
      <div>
        <div class="text-sm font-medium text-white">Enable voice</div>
        <div class="text-xs text-discord-400 mt-0.5">Master switch — hides all voice/call UI when off</div>
      </div>
      <input type="checkbox" name="voice_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($s['voice_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
    </div>

    <div>
      <label class="label">LiveKit URL</label>
      <input class="input font-mono text-xs" name="livekit_url" value="<?= h($s['livekit_url'] ?? '') ?>" placeholder="ws://127.0.0.1:7880" spellcheck="false" autocomplete="off">
      <p class="text-xs text-discord-400 mt-1">Where clients connect (use <code>wss://</code> in production). LiveKit's /health is probed on this host/port.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="label">API key</label>
        <input class="input font-mono text-xs" name="livekit_api_key" value="<?= h($s['livekit_api_key'] ?? '') ?>" spellcheck="false" autocomplete="off">
      </div>
      <div>
        <label class="label">API secret</label>
        <input class="input font-mono text-xs" name="livekit_api_secret" type="password" placeholder="<?= !empty($s['livekit_has_secret']) ? '•••••• (stored — leave blank to keep)' : '' ?>" autocomplete="new-password">
        <p class="text-xs text-discord-400 mt-1">Write-only. Used to sign join tokens; never sent to the browser.</p>
      </div>
    </div>

    <div class="flex items-center gap-3 pt-1">
      <button type="submit" name="autoconfigure" value="1" class="btn-secondary">Generate &amp; autoconfigure keys</button>
      <p class="text-xs text-discord-400">Generates a strong API key + secret, writes them to the LiveKit config under this app's <code>data/</code>, and starts the <code>livekit-server</code> binary as the site user (no root needed). Requires <code>livekit-server</code> to be installed somewhere in the user's path.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div>
        <label class="label">Max concurrent voice users</label>
        <input class="input" type="number" min="1" max="200" name="voice_max_users" value="<?= h($s['voice_max_users'] ?? '50') ?>">
        <p class="text-xs text-discord-400 mt-1">Hard ceiling for voice connections. LiveKit rooms get this as max_participants and LVChat refuses joins past it.</p>
      </div>
      <div>
        <label class="label">Talkers per listener</label>
        <input class="input" type="number" min="1" max="50" name="voice_talker_cap" value="<?= h($s['voice_talker_cap'] ?? '8') ?>">
        <p class="text-xs text-discord-400 mt-1">Each listener hears at most this many active speakers (Discord-style). Protects downlink bandwidth.</p>
      </div>
      <div>
        <label class="label">Quality preset</label>
        <select class="input" name="voice_quality_preset">
          <?php
          $cur = $s['voice_quality_preset'] ?? 'moderate';
          foreach (['high' => 'High (~72 kbit/s)', 'moderate' => 'Moderate (~51 kbit/s)', 'minimum' => 'Minimum (~27 kbit/s)'] as $val => $label) {
              echo '<option value="' . h($val) . '"' . ($cur === $val ? ' selected' : '') . '>' . h($label) . '</option>';
          }
          ?>
        </select>
        <p class="text-xs text-discord-400 mt-1">Sets the Opus bitrate. The exact value is stored in voice_bitrate below.</p>
      </div>
      <div>
        <label class="label">Ring timeout (seconds)</label>
        <input class="input" type="number" min="10" max="120" name="call_ring_seconds" value="<?= h($s['call_ring_seconds'] ?? '20') ?>">
        <p class="text-xs text-discord-400 mt-1">Unanswered calls auto-fail as "no answer" after this long (default 20s ≈ 4 rings).</p>
      </div>
    </div>

    <div>
      <label class="label">Bitrate (bits/s, optional override)</label>
      <input class="input font-mono text-xs" type="number" min="16000" max="64000" name="voice_bitrate" value="<?= h($s['voice_bitrate'] ?? '40000') ?>" placeholder="40000">
    </div>

    <div class="pt-2">
      <button class="btn-primary">Save voice settings</button>
    </div>
  </form>

  <div class="space-y-5">
    <div class="card p-5">
      <div class="text-sm font-medium text-white mb-3">Status</div>
      <dl class="space-y-2 text-sm">
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">LiveKit server</dt>
          <dd>
            <?php if ($health['running']): ?>
              <span class="inline-flex items-center gap-1.5 text-green-400"><span class="w-2 h-2 rounded-full bg-green-400"></span> running</span>
            <?php else: ?>
              <span class="inline-flex items-center gap-1.5 text-red-400"><span class="w-2 h-2 rounded-full bg-red-400"></span> down</span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Connected voice users</dt>
          <dd class="font-mono"><?= (int) $status['active'] ?> / <?= (int) $status['max'] ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Voice enabled</dt>
          <dd class="font-mono"><?= $status['enabled'] ? 'yes' : 'no' ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Managed process</dt>
          <dd class="font-mono">
            <?php if ((int) $daemon['pid'] > 0): ?>
              <span class="text-green-400">running (pid <?= (int) $daemon['pid'] ?>)</span>
            <?php else: ?>
              <span class="text-red-400">not running</span>
            <?php endif; ?>
          </dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Config</dt>
          <dd class="font-mono text-xs truncate max-w-[14rem]" title="<?= h($daemon['config']) ?>"><?= h($daemon['config']) ?></dd>
        </div>
        <div class="flex items-center justify-between gap-3">
          <dt class="text-discord-400">Binary</dt>
          <dd class="font-mono text-xs truncate max-w-[14rem]" title="<?= h($daemon['binary']) ?>"><?= h($daemon['binary'] ?: 'not installed') ?></dd>
        </div>
      </dl>
      <?php if (!$health['running'] && $health['error']): ?>
        <p class="text-xs text-discord-400 mt-3"><?= h($health['error']) ?></p>
      <?php endif; ?>
    </div>

    <div class="card p-5 text-sm">
      <div class="text-xs text-discord-400 leading-relaxed">
        Voice is delivered by a self-hosted <strong class="text-discord-200">LiveKit</strong> SFU. Rooms map 1:1 to
        channels (<code class="text-sky-300">chan:&lt;slug&gt;</code>) and calls
        (<code class="text-sky-300">call_&lt;id&gt;</code>). The client connects with a short-lived token minted by
        this server — no media passes through the web app. See
        <code class="text-discord-300">docs/webrtc-implementation.md</code> and the module README for deployment
        (LiveKit + coturn install, ports, wss).
      </div>
    </div>
  </div>
</div>

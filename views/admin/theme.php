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

 $title = 'Appearance'; $active = 'theme';
$gt = $globalTheme;
$ov = $gt['overrides'];
$mode = $gt['mode'] !== '' ? $gt['mode'] : 'dark';
$font = $ov['font'] ?? 'default';
$fit = $ov['chat_bg_fit'] ?? 'contain';
$overlay = isset($ov['chat_bg_overlay']) ? (int) $ov['chat_bg_overlay'] : ThemeService::CHAT_BG_OVERLAY_DEFAULT;
$bgColor = $ov['chat_bg_color'] ?? '';
$bgImage = $ov['chat_bg_image'] ?? '';
?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Appearance</h1>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<form method="post" action="/admin/action" id="theme-form" class="space-y-5 max-w-4xl">
  <?= Csrf::field() ?>
  <input type="hidden" name="action" value="theme_save">

  <div class="card p-4">
    <div class="flex items-center justify-between gap-4">
      <div>
        <div class="text-sm font-medium text-white">Allow users to customize their theme</div>
        <div class="text-xs text-discord-400">When on, every user can pick their own preset, colours, font, and chat background from their profile. When off, everyone is forced onto the server theme below (personal choices are kept but ignored).</div>
      </div>
      <input type="checkbox" name="theme_user_customization" value="1" class="w-5 h-5 accent-blurple shrink-0" <?= $customizationEnabled ? 'checked' : '' ?>>
    </div>
  </div>

  <div class="card p-6 space-y-6">
    <div>
      <h2 class="font-semibold text-white mb-1">Server theme</h2>
      <p class="text-xs text-discord-400 mb-4">Pick a preset from the library of <?= count($presets) ?> complementing combinations, then fine-tune the accent, sidebar, font, and chat background. Every change previews instantly on this page.</p>

      <div class="flex flex-wrap items-center gap-2 mb-3">
        <div class="flex items-center gap-2 text-sm">
          <span class="text-xs font-semibold uppercase tracking-wide text-discord-400">Mode</span>
          <button type="button" data-mode="dark" class="mode-btn px-3 py-1 rounded-md text-sm font-medium border <?= $mode === 'dark' ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700' ?>">Dark</button>
          <button type="button" data-mode="light" class="mode-btn px-3 py-1 rounded-md text-sm font-medium border <?= $mode === 'light' ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700' ?>">Light</button>
        </div>
        <div class="flex items-center gap-2 text-sm ml-auto">
          <span class="text-xs font-semibold uppercase tracking-wide text-discord-400">Font</span>
          <select id="theme-font" class="input !w-44 !py-1.5">
            <?php foreach (array_keys(ThemeService::FONTS) as $f): ?>
            <option value="<?= h($f) ?>" <?= $font === $f ? 'selected' : '' ?>><?= h(ucfirst($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-2 preset-grid">
      <?php foreach ($presets as $p): ?>
      <button type="button" data-preset="<?= h($p['id']) ?>"
              class="preset-card text-left rounded-lg border p-2 transition-colors <?= $gt['preset'] === $p['id'] ? 'border-blurple/70 bg-blurple/10' : 'border-discord-600 hover:border-discord-400 hover:bg-discord-700' ?>">
        <div class="flex gap-1 mb-1.5">
          <span class="w-4 h-4 rounded-full border border-black/30" style="background:<?= h($p['swatch']['accent']) ?>" title="Accent"></span>
          <span class="w-4 h-4 rounded-full border border-black/30" style="background:<?= h($p['swatch']['sidebar']) ?>" title="Sidebar"></span>
          <span class="w-4 h-4 rounded-md border border-black/30" style="background:<?= h($p['swatch']['surface']) ?>" title="Surface"></span>
          <span class="w-4 h-4 rounded-md border border-black/30" style="background:<?= h($p['swatch']['text']) ?>" title="Text"></span>
        </div>
        <div class="text-xs text-discord-200 truncate"><?= h($p['name']) ?></div>
        <div class="text-[10px] text-discord-500 font-mono truncate"><?= h($p['id']) ?></div>
      </button>
      <?php endforeach; ?>
    </div>

    <div class="pt-5 border-t border-discord-700 grid grid-cols-1 md:grid-cols-2 gap-5">
      <div>
        <label class="label">Accent colour <button type="button" data-clear="accent" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">use preset</button></label>
        <div class="flex items-center gap-2">
          <input type="color" id="theme-accent" value="<?= h($ov['accent'] ?? ($presets[0]['accent'] ?? '#5865f2')) ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
          <span class="text-xs text-discord-400 font-mono" id="accent-label"><?= h(strtoupper(ltrim($ov['accent'] ?? '', '#'))) ?: 'preset' ?></span>
        </div>
      </div>
      <div>
        <label class="label">Sidebar colour <button type="button" data-clear="sidebar" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">use preset</button></label>
        <div class="flex items-center gap-2">
          <input type="color" id="theme-sidebar" value="<?= h($ov['sidebar'] ?? ($presets[0]['sidebar'] ?? '#2b2d31')) ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
          <span class="text-xs text-discord-400 font-mono" id="sidebar-label"><?= h(strtoupper(ltrim($ov['sidebar'] ?? '', '#'))) ?: 'preset' ?></span>
        </div>
      </div>
    </div>

    <div class="pt-5 border-t border-discord-700">
      <div class="text-sm font-medium text-white mb-1">Chat background</div>
      <p class="text-xs text-discord-400 mb-3">A colour and/or image behind the message list. Channel owners can set their own per-channel background; users can override this with a personal one.</p>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div>
          <label class="label">Background colour <button type="button" data-clear="chat_bg_color" class="text-[10px] text-blurple hover:underline ml-1 clear-btn">none</button></label>
          <div class="flex items-center gap-2">
            <input type="color" id="theme-bg-color" value="<?= h($bgColor !== '' ? $bgColor : '#313338') ?>" class="w-10 h-9 rounded cursor-pointer bg-discord-750 border border-discord-600">
            <span class="text-xs text-discord-400 font-mono" id="bg-color-label"><?= $bgColor !== '' ? h(strtoupper(ltrim($bgColor, '#'))) : 'none' ?></span>
          </div>
        </div>
        <div>
          <label class="label">Background image</label>
          <div class="flex flex-wrap items-center gap-2">
            <label class="btn-ghost !py-1.5 text-xs cursor-pointer">Upload image<input type="file" id="theme-bg-file" accept="image/jpeg,image/png,image/webp,image/gif" class="hidden"></label>
            <?php if ($bgImage !== ''): ?>
            <button type="button" id="theme-bg-remove" class="btn-ghost !py-1.5 text-xs text-red-400">Remove</button>
            <?php endif; ?>
          </div>
          <p class="text-xs text-discord-400 mt-1">PNG/JPG/WebP/GIF up to 5&nbsp;MB. Best as a wide, muted image so text stays readable.</p>
        </div>
        <div>
          <label class="label">Image fit</label>
          <select id="theme-bg-fit" class="input !py-1.5">
            <?php foreach (ThemeService::CHAT_BG_FITS as $f): ?>
            <option value="<?= h($f) ?>" <?= $fit === $f ? 'selected' : '' ?>><?= h(ucfirst($f)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-4 max-w-md">
        <label class="label">Overlay opacity <span id="bg-overlay-label" class="text-discord-400 normal-case"><?= (int) $overlay ?>%</span></label>
        <input type="range" id="theme-bg-overlay" min="0" max="100" step="5" value="<?= (int) $overlay ?>" class="w-full accent-blurple cursor-pointer">
        <p class="text-xs text-discord-400 mt-1">A translucent layer between the text and the image — raise it when a busy image makes chat hard to read.</p>
      </div>
      <?php if ($bgImage !== ''): ?>
      <div class="mt-3 flex items-center gap-3">
        <img src="<?= h(url($bgImage)) ?>" alt="Current chat background" class="h-16 w-32 object-cover rounded-lg border border-discord-600">
        <span class="text-xs text-discord-400 font-mono break-all"><?= h($bgImage) ?></span>
      </div>
      <?php endif; ?>
    </div>

    <div class="flex items-center gap-3 pt-2">
      <button class="btn-primary">Save theme</button>
      <button type="button" id="theme-reset" class="btn-ghost text-red-400">Reset to default</button>
      <span id="theme-msg" class="text-sm text-green-400 hidden">Saved.</span>
    </div>
  </div>
</form>

<script>
(function () {
  const csrf = document.body.dataset.csrf || '';
  const presets = <?= json_encode(array_column($presets, null, 'id')) ?>;
  const styleEl = document.getElementById('theme-css');
  const form = document.getElementById('theme-form');
  if (!form || !styleEl) return;

  const state = {
    preset: <?= json_encode($gt['preset']) ?>,
    mode: <?= json_encode($mode) ?>,
    accent: <?= json_encode($ov['accent'] ?? '') ?>,
    sidebar: <?= json_encode($ov['sidebar'] ?? '') ?>,
    font: <?= json_encode($font) ?>,
    chat_bg_color: <?= json_encode($bgColor) ?>,
    chat_bg_fit: <?= json_encode($fit) ?>,
    chat_bg_overlay: <?= (int) $overlay ?>,
    chat_bg_image: <?= json_encode($bgImage) ?>,
  };
  const emptyToNull = (v) => v === '' ? '' : v;

  function labelFor(input, labelEl, presetKey) {
    const overridden = state[input] !== '';
    labelEl.textContent = overridden ? state[input].replace('#', '').toUpperCase() : 'preset';
  }

  let previewTimer = null;
  function renderPreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => {
      const p = new URLSearchParams();
      p.set('preset', state.preset);
      p.set('mode', state.mode);
      if (state.accent) p.set('accent', state.accent);
      if (state.sidebar) p.set('sidebar', state.sidebar);
      p.set('font', state.font);
      if (state.chat_bg_color) p.set('chat_bg_color', state.chat_bg_color);
      if (state.chat_bg_image) p.set('chat_bg_image', state.chat_bg_image);
      p.set('chat_bg_fit', state.chat_bg_fit);
      p.set('chat_bg_overlay', state.chat_bg_overlay);
      fetch('/api/theme/css?' + p.toString(), { cache: 'no-store' })
        .then(r => r.text())
        .then(css => {
          styleEl.textContent = css;
          document.documentElement.classList.toggle('light', state.mode === 'light');
        })
        .catch(() => {});
    }, 120);
  }

  // ── Preset gallery ──────────────────────────────────────────────────────────
  function markSelected() {
    document.querySelectorAll('.preset-card').forEach(el => {
      const on = el.dataset.preset === state.preset;
      el.classList.toggle('border-blurple/70', on);
      el.classList.toggle('bg-blurple/10', on);
      el.classList.toggle('border-discord-600', !on);
      el.classList.toggle('hover:border-discord-400', !on);
      el.classList.toggle('hover:bg-discord-700', !on);
    });
  }
  document.querySelectorAll('.preset-card').forEach(el => {
    el.addEventListener('click', () => {
      state.preset = el.dataset.preset;
      if (!state.accent) document.getElementById('theme-accent').value = presets[state.preset].accent;
      if (!state.sidebar) document.getElementById('theme-sidebar').value = presets[state.preset].sidebar;
      markSelected();
      renderPreview();
    });
  });
  markSelected();

  // ── Mode / font / colours / fit ────────────────────────────────────────────
  document.querySelectorAll('.mode-btn').forEach(el => {
    el.addEventListener('click', () => {
      state.mode = el.dataset.mode;
      document.querySelectorAll('.mode-btn').forEach(b => {
        const on = b.dataset.mode === state.mode;
        b.className = 'mode-btn px-3 py-1 rounded-md text-sm font-medium border ' + (on ? 'bg-blurple/20 border-blurple/50 text-white' : 'border-discord-600 text-discord-300 hover:bg-discord-700');
      });
      renderPreview();
    });
  });
  document.getElementById('theme-font').addEventListener('change', (e) => { state.font = e.target.value; renderPreview(); });
  document.getElementById('theme-accent').addEventListener('input', (e) => { state.accent = e.target.value; renderPreview(); });
  document.getElementById('theme-sidebar').addEventListener('input', (e) => { state.sidebar = e.target.value; renderPreview(); });
  document.getElementById('theme-bg-color').addEventListener('input', (e) => { state.chat_bg_color = e.target.value; renderPreview(); });
  document.getElementById('theme-bg-fit').addEventListener('change', (e) => { state.chat_bg_fit = e.target.value; renderPreview(); });
  document.getElementById('theme-bg-overlay').addEventListener('input', (e) => {
    state.chat_bg_overlay = parseInt(e.target.value, 10) || 0;
    document.getElementById('bg-overlay-label').textContent = state.chat_bg_overlay + '%';
    renderPreview();
  });

  document.querySelectorAll('.clear-btn').forEach(el => {
    el.addEventListener('click', () => {
      const key = el.dataset.clear;
      state[key] = '';
      if (key === 'accent') { document.getElementById('theme-accent').value = presets[state.preset].accent; document.getElementById('accent-label').textContent = 'preset'; }
      if (key === 'sidebar') { document.getElementById('theme-sidebar').value = presets[state.preset].sidebar; document.getElementById('sidebar-label').textContent = 'preset'; }
      if (key === 'chat_bg_color') { document.getElementById('theme-bg-color').value = '#313338'; document.getElementById('bg-color-label').textContent = 'none'; }
      renderPreview();
    });
  });

  // ── Save (single form, hidden inputs kept in sync) ─────────────────────────
  const fields = ['preset', 'mode', 'accent', 'sidebar', 'font', 'chat_bg_color', 'chat_bg_fit', 'chat_bg_overlay', 'chat_bg_image'];
  fields.forEach(k => {
    const h = document.createElement('input');
    h.type = 'hidden';
    h.name = k;
    form.appendChild(h);
  });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    fields.forEach(k => {
      form.querySelector('input[name="' + k + '"]').value = state[k];
    });
    const fd = new FormData(form);
    fetch('/admin/action', { method: 'POST', body: fd })
      .then(r => r.text())
      .then(() => {
        const msg = document.getElementById('theme-msg');
        msg.classList.remove('hidden');
        setTimeout(() => msg.classList.add('hidden'), 2000);
      });
  });

  // ── Background upload / remove / reset ─────────────────────────────────────
  const bgFile = document.getElementById('theme-bg-file');
  if (bgFile) bgFile.addEventListener('change', () => {
    if (!bgFile.files || !bgFile.files[0]) return;
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'theme_bg_upload');
    fd.append('file', bgFile.files[0]);
    fetch('/admin/action', { method: 'POST', body: fd }).then(() => location.reload());
  });
  const bgRemove = document.getElementById('theme-bg-remove');
  if (bgRemove) bgRemove.addEventListener('click', () => {
    if (!confirm('Remove the chat background image?')) return;
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'theme_bg_remove');
    fetch('/admin/action', { method: 'POST', body: fd }).then(() => location.reload());
  });
  const resetBtn = document.getElementById('theme-reset');
  if (resetBtn) resetBtn.addEventListener('click', () => {
    if (!confirm('Reset the server theme to the default preset?')) return;
    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('action', 'theme_reset');
    fetch('/admin/action', { method: 'POST', body: fd }).then(() => location.reload());
  });

  renderPreview();
})();
</script>

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

 $title = 'Voice & Speech'; $active = 'voice';
$pageTitle = 'Voice & Speech';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>
<form method="post" action="/admin/voice/save" class="card p-6 max-w-2xl space-y-5">
  <?= Csrf::field() ?>

  <h3 class="text-sm font-bold text-white">Feature toggles</h3>

  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Speech-to-text dictation</div>
      <div class="text-xs text-discord-400">Show a microphone button in the chat composer for voice dictation.</div>
    </div>
    <input type="checkbox" name="voice_stt_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['voice_stt_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
  </div>

  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Text-to-speech read-aloud</div>
      <div class="text-xs text-discord-400">Show a speaker button on messages to read them aloud.</div>
    </div>
    <input type="checkbox" name="voice_tts_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['voice_tts_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
  </div>

  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Force local models</div>
      <div class="text-xs text-discord-400">When enabled, users must use the server-side STT/TTS models. Browser built-in speech services will not be used as a fallback.</div>
    </div>
    <input type="checkbox" name="voice_force_local" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['voice_force_local'] ?? '0') === '1' ? 'checked' : '' ?>>
  </div>

  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="label">STT sidecar URL</label>
      <input class="input font-mono text-xs" name="voice_stt_sidecar_url" value="<?= h($settings['voice_stt_sidecar_url'] ?? 'http://127.0.0.1:8787') ?>" placeholder="http://127.0.0.1:8787">
    </div>
    <div>
      <label class="label">TTS sidecar URL</label>
      <input class="input font-mono text-xs" name="voice_tts_sidecar_url" value="<?= h($settings['voice_tts_sidecar_url'] ?? 'http://127.0.0.1:8788') ?>" placeholder="http://127.0.0.1:8788">
    </div>
  </div>

  <button name="action" value="save" class="btn-primary">Save settings</button>
</form>

<!-- ── Sidecar control panel ────────────────────────────────────────────── -->
<div class="card p-6 max-w-2xl mt-6 space-y-4">
  <h3 class="text-sm font-bold text-white">Sidecar processes</h3>
  <p class="text-xs text-discord-400">The STT and TTS sidecars are Python services that run locally. Start them here, or run <code class="font-mono">bash bin/start-sidecars.sh</code> from SSH.</p>

  <div class="grid grid-cols-2 gap-4">
    <!-- STT -->
    <div class="rounded-lg border border-discord-700 bg-discord-850 p-4">
      <div class="flex items-center justify-between mb-2">
        <div>
          <div class="text-sm font-medium text-white">STT (speech-to-text)</div>
          <div class="text-xs text-discord-400">faster-whisper · <span id="stt-port">8787</span></div>
        </div>
        <span id="stt-badge" class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-discord-700 text-discord-400">
          <span class="w-2 h-2 rounded-full bg-discord-500" id="stt-dot"></span>
          <span id="stt-status-text">Checking…</span>
        </span>
      </div>
      <div id="stt-model" class="text-[11px] text-discord-500 mb-3"></div>
      <div class="flex items-center gap-2">
        <button type="button" id="stt-btn-start" class="btn bg-green-600/80 hover:bg-green-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Start</button>
        <button type="button" id="stt-btn-stop" class="btn bg-red-600/80 hover:bg-red-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Stop</button>
      </div>
    </div>

    <!-- TTS -->
    <div class="rounded-lg border border-discord-700 bg-discord-850 p-4">
      <div class="flex items-center justify-between mb-2">
        <div>
          <div class="text-sm font-medium text-white">TTS (text-to-speech)</div>
          <div class="text-xs text-discord-400">piper-tts · <span id="tts-port">8788</span></div>
        </div>
        <span id="tts-badge" class="inline-flex items-center gap-1 text-xs font-semibold px-2 py-0.5 rounded-full bg-discord-700 text-discord-400">
          <span class="w-2 h-2 rounded-full bg-discord-500" id="tts-dot"></span>
          <span id="tts-status-text">Checking…</span>
        </span>
      </div>
      <div id="tts-model" class="text-[11px] text-discord-500 mb-3"></div>
      <div class="flex items-center gap-2">
        <button type="button" id="tts-btn-start" class="btn bg-green-600/80 hover:bg-green-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Start</button>
        <button type="button" id="tts-btn-stop" class="btn bg-red-600/80 hover:bg-red-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40" disabled>Stop</button>
      </div>
    </div>
  </div>

  <div class="flex items-center gap-2 pt-2">
    <button type="button" id="voice-btn-start-all" class="btn bg-green-600/80 hover:bg-green-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40">Start all</button>
    <button type="button" id="voice-btn-stop-all" class="btn bg-red-600/80 hover:bg-red-600 text-white rounded-lg px-3 py-1.5 text-xs font-semibold disabled:opacity-40">Stop all</button>
    <button type="button" id="voice-btn-refresh" class="btn bg-discord-600 hover:bg-discord-500 text-white rounded-lg px-3 py-1.5 text-xs font-semibold" title="Refresh status">Refresh</button>
  </div>

  <pre id="voice-output" class="hidden mt-2 p-2 rounded bg-black/40 text-[11px] text-discord-300 leading-relaxed max-h-48 overflow-auto whitespace-pre-wrap font-mono"></pre>
</div>

<!-- Sidecar startup modal -->
<div id="voice-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-black/60 p-4">
  <div class="w-full max-w-2xl rounded-xl bg-discord-800 border border-discord-700 shadow-2xl flex flex-col max-h-[80vh]">
    <div class="flex items-center justify-between px-4 py-3 border-b border-discord-700">
      <div id="voice-modal-title" class="text-sm font-semibold text-white font-mono">Starting sidecar…</div>
      <button type="button" id="voice-modal-close" class="text-discord-400 hover:text-white text-xl leading-none px-1" title="Close">&times;</button>
    </div>
    <pre id="voice-modal-output" class="flex-1 overflow-auto p-3 text-[12px] font-mono text-green-300 bg-black/50 leading-relaxed whitespace-pre-wrap min-h-[200px]"></pre>
  </div>
</div>

<script>
(function () {
  var csrf = '<?= Csrf::token() ?>';
  var sttUrl = <?= json_encode($settings['voice_stt_sidecar_url'] ?? 'http://127.0.0.1:8787') ?>;
  var ttsUrl = <?= json_encode($settings['voice_tts_sidecar_url'] ?? 'http://127.0.0.1:8788') ?>;

  // Parse ports from URLs
  function portFromUrl(u) { var m = u.match(/:(\d+)$/); return m ? m[1] : ''; }
  document.getElementById('stt-port').textContent = portFromUrl(sttUrl) || '8787';
  document.getElementById('tts-port').textContent = portFromUrl(ttsUrl) || '8788';

  var sttStart = document.getElementById('stt-btn-start');
  var sttStop  = document.getElementById('stt-btn-stop');
  var ttsStart = document.getElementById('tts-btn-start');
  var ttsStop  = document.getElementById('tts-btn-stop');
  var startAll = document.getElementById('voice-btn-start-all');
  var stopAll  = document.getElementById('voice-btn-stop-all');
  var refreshBtn = document.getElementById('voice-btn-refresh');
  var outEl    = document.getElementById('voice-output');

  function showOutput(text) {
    outEl.classList.remove('hidden');
    outEl.textContent = text;
    outEl.scrollTop = outEl.scrollHeight;
  }

  function post(url, data, onOk) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    fetch(url, { method: 'POST', body: fd })
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .catch(function () { return { error: 'Request failed' }; })
      .then(onOk);
  }

  function renderSidecar(which, s) {
    var dot = document.getElementById(which + '-dot');
    var txt = document.getElementById(which + '-status-text');
    var mdl = document.getElementById(which + '-model');
    var startBtn = document.getElementById(which + '-btn-start');
    var stopBtn  = document.getElementById(which + '-btn-stop');
    if (s.running) {
      dot.className = 'w-2 h-2 rounded-full bg-green-500';
      txt.textContent = 'Running';
      txt.className = 'text-green-400 font-semibold';
      var parts = [];
      if (s.model) parts.push(s.model);
      if (s.device) parts.push(s.device);
      mdl.textContent = parts.length ? parts.join(' · ') : '';
      startBtn.disabled = true;
      stopBtn.disabled = false;
    } else {
      dot.className = 'w-2 h-2 rounded-full bg-red-500';
      txt.textContent = 'Stopped';
      txt.className = 'text-red-400 font-semibold';
      mdl.textContent = '';
      startBtn.disabled = false;
      stopBtn.disabled = true;
    }
  }

  function refreshStatus() {
    fetch('/admin/voice/status')
      .then(function (r) { return r.json().catch(function () { return {}; }); })
      .catch(function () { return {}; })
      .then(function (j) {
        if (j.stt) renderSidecar('stt', j.stt);
        if (j.tts) renderSidecar('tts', j.tts);
      });
  }

  function openModal(title) {
    var modal = document.getElementById('voice-modal');
    var out   = document.getElementById('voice-modal-output');
    var ttl   = document.getElementById('voice-modal-title');
    if (!modal || !out) return null;
    ttl.textContent = title;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    out.textContent = '';
    return out;
  }
  function closeModal() {
    var modal = document.getElementById('voice-modal');
    if (modal) { modal.classList.add('hidden'); modal.classList.remove('flex'); }
  }

  function streamStart(which) {
    var label = which === 'stt' ? 'STT (speech-to-text)' : 'TTS (text-to-speech)';
    var out = openModal('Starting ' + label + '…');
    if (!out) return;
    out.textContent += '$ Starting ' + label + ' sidecar…\n';
    var fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('which', which);
    fetch('/admin/voice/start-stream', { method: 'POST', body: fd })
      .then(function (r) {
        if (!r.ok || !r.body) throw new Error('HTTP ' + r.status);
        return r.body.getReader();
      })
      .then(function (reader) {
        var decoder = new TextDecoder();
        function pump() {
          return reader.read().then(function (res) {
            if (res.done) {
              out.textContent += '\nDone. Checking status…\n';
              setTimeout(refreshStatus, 1500);
              return;
            }
            out.textContent += decoder.decode(res.value, { stream: true });
            out.scrollTop = out.scrollHeight;
            return pump();
          });
        }
        return pump();
      })
      .catch(function (err) {
        out.textContent += '\n[error: ' + (err && err.message ? err.message : 'request failed') + ']';
      });
  }

  sttStart.addEventListener('click', function () { streamStart('stt'); });
  ttsStart.addEventListener('click', function () { streamStart('tts'); });

  sttStop.addEventListener('click', function () {
    showOutput('Stopping STT sidecar…');
    post('/admin/voice/control', { which: 'stt', action: 'stop' }, function (j) {
      showOutput(j.ok ? 'STT sidecar stopped.' : ('Error: ' + (j.error || 'unknown')));
      refreshStatus();
    });
  });
  ttsStop.addEventListener('click', function () {
    showOutput('Stopping TTS sidecar…');
    post('/admin/voice/control', { which: 'tts', action: 'stop' }, function (j) {
      showOutput(j.ok ? 'TTS sidecar stopped.' : ('Error: ' + (j.error || 'unknown')));
      refreshStatus();
    });
  });

  startAll.addEventListener('click', function () {
    showOutput('Starting all sidecars…');
    post('/admin/voice/control', { which: 'all', action: 'start' }, function (j) {
      showOutput(j.ok ? 'Sidecar start commands sent.' : ('Error: ' + (j.error || 'unknown')));
      refreshStatus();
    });
  });
  stopAll.addEventListener('click', function () {
    showOutput('Stopping all sidecars…');
    post('/admin/voice/control', { which: 'all', action: 'stop' }, function (j) {
      showOutput(j.ok ? 'All sidecars stopped.' : ('Error: ' + (j.error || 'unknown')));
      refreshStatus();
    });
  });

  refreshBtn.addEventListener('click', refreshStatus);

  // Modal close handlers
  document.addEventListener('click', function (e) {
    if (e.target && e.target.closest && e.target.closest('#voice-modal-close')) closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });
  document.addEventListener('click', function (e) {
    var modal = document.getElementById('voice-modal');
    if (modal && !modal.classList.contains('hidden') && e.target === modal) closeModal();
  });

  refreshStatus();
  setInterval(refreshStatus, 15000);
})();
</script>

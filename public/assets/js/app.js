(() => {
  'use strict';

  try { document.body.dataset.jsstarted = '1'; } catch (e) {}
  const body = document.body;
  const CSRF = window.CHAT ? window.CHAT.csrf : (body.dataset.csrf || '');
  const CHANNEL = body.dataset.channel || '';
  const DM = body.dataset.dm || '';
  const MY_ID = parseInt(body.dataset.myId || '0', 10);
  const MY_NICK = (body.dataset.myNick || '').toLowerCase();
  const MY_LEVEL = body.dataset.myLevel || 'normal';
  const CAN_OP = body.dataset.canOp === '1';
  const CAN_ADMIN = body.dataset.canAdmin === '1';
  const COMMANDS = JSON.parse(body.dataset.commands || '[]');
  const LEVELS = { normal: 0, voice: 1, halfop: 2, op: 3, admin: 4, founder: 5 };
  const SYMBOL = { normal: '', voice: '+', halfop: '%', op: '@', admin: '&', founder: '~' };
  const COLORS = {
    normal: 'text-discord-300', voice: 'text-green-400', halfop: 'text-cyan-400',
    op: 'text-orange-400', admin: 'text-red-400', founder: 'text-amber-400',
  };

  const msgsEl = document.getElementById('messages');
  const input = document.getElementById('chat-input');
  const form = document.getElementById('send-form');

  let lastId = lastMsgId();
  const SYSTEM = ['join', 'part', 'quit', 'kick', 'ban', 'topic', 'mode', 'nick', 'system', 'notice'];
  let lastMsg = null;
  let lastDate = null;
  try { lastMsg = JSON.parse(msgsEl ? (msgsEl.dataset.lastMsg || 'null') : 'null') || null; } catch (e) { lastMsg = null; }
  if (lastMsg && lastMsg.created_at) lastDate = String(lastMsg.created_at).slice(0, 10);

  function lastMsgId() {
    const els = msgsEl ? msgsEl.querySelectorAll('.msg[data-id]') : [];
    let max = 0;
    els.forEach((el) => { const id = parseInt(el.dataset.id, 10); if (id > max) max = id; });
    return max;
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function linkify(text) {
    text = esc(text);
    text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/`(.+?)`/g, '<code class="bg-discord-800 px-1 rounded">$1</code>');
    text = text.replace(/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/g, '<span class="mention text-blurple font-semibold">@$1</span>');
    text = text.replace(/(https?:\/\/[^\s<]+)/gi, '<a class="text-sky-400 hover:underline" target="_blank" rel="noopener" href="$1">$1</a>');
    return text;
  }

  function timeStr(ts) {
    const d = new Date((ts || '').replace(' ', 'T') + 'Z');
    if (isNaN(d)) return '';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  function timeTs(ts) {
    const d = new Date((ts || '').replace(' ', 'T') + 'Z');
    return isNaN(d) ? 0 : d.getTime();
  }

  function dateLabel(date) {
    const today = new Date().toISOString().slice(0, 10);
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    if (date === today) return 'Today';
    if (date === yesterday) return 'Yesterday';
    const d = new Date(date + 'T00:00:00Z');
    return d.toLocaleDateString([], { year: 'numeric', month: 'long', day: 'numeric' });
  }

  function dividerHtml(date) {
    return `<div class="divider flex items-center gap-4 px-4 py-3 select-none">
      <div class="h-px flex-1 bg-discord-800"></div>
      <span class="text-xs font-semibold text-discord-400 uppercase tracking-wide">${dateLabel(date)}</span>
      <div class="h-px flex-1 bg-discord-800"></div>
    </div>`;
  }

  function msgHtml(m, grouped) {
    if (SYSTEM.includes(m.kind)) {
      return `<div class="msg-system px-4 py-1.5 text-xs text-discord-400 italic text-center select-none" data-kind="${esc(m.kind)}">${linkify(m.content)}</div>`;
    }
    const isAdmin = m.role === 'admin';
    const guestTag = m.guest ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
    const roleStyle = (!isAdmin && m.role_color) ? ' style="color:' + esc(m.role_color) + '"' : '';
    const nameColor = isAdmin ? 'text-red-400' : (COLORS[m.level || 'normal'] || COLORS.normal);
    const contentColor = isAdmin ? 'text-red-400' : 'text-discord-200';
    if (m.kind === 'action') {
      return `<div class="msg group px-4 py-0.5 flex gap-4 hover:bg-white/[0.03]" data-id="${m.id}" data-kind="action" data-author="${esc(m.username)}">
        <div class="w-10 shrink-0"></div>
        <div class="text-sm ${contentColor}"${roleStyle}><span class="italic">* <span class="font-medium ${nameColor}"${roleStyle}>${esc(m.username)}</span>${guestTag} ${linkify(m.content)}</span></div>
      </div>`;
    }
    const initial = (m.username || '?').charAt(0).toUpperCase();
    const sym = SYMBOL[m.level || 'normal'] || '';
    if (grouped) {
      return `<div class="msg group px-4 py-0.5 hover:bg-white/[0.03] flex gap-4" data-id="${m.id}" data-kind="message" data-author="${esc(m.username)}">
        <div class="w-10 shrink-0"></div>
        <div class="min-w-0 flex-1">
          <div class="msg-content text-[15px] leading-[1.4] ${contentColor} break-words"${roleStyle}>${linkify(m.content)}</div>
        </div>
      </div>`;
    }
    let actions = '';
    const mine = String(m.sender_id) === String(MY_ID) || (m.username && m.username.toLowerCase() === MY_NICK);
    if (CAN_ADMIN) {
      actions = '<button class="msg-edit text-[12px] opacity-60 hover:opacity-100" title="Edit">✏️</button>'
        + '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    } else if (mine) {
      actions = '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    }
    return `<div class="msg group px-4 pt-[17px] pb-0.5 hover:bg-white/[0.03] flex gap-4" data-id="${m.id}" data-kind="message" data-author="${esc(m.username)}">
      <div class="w-10 h-10 shrink-0 rounded-full bg-discord-500 flex items-center justify-center text-sm font-bold text-white border border-discord-600">${esc(initial)}</div>
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2 h-[22px]">
          <span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ${nameColor}"${roleStyle} data-nick="${esc(m.username)}">${sym}${esc(m.username)}${guestTag}</span>
          <span class="time text-[11px] text-discord-400 hidden group-hover:inline">${timeStr(m.created_at)}</span>
          ${m.edited_at ? '<span class="text-[10px] text-discord-400">(edited)</span>' : ''}
        </div>
        <div class="msg-content text-[15px] leading-[1.4] ${contentColor} break-words"${roleStyle}>${linkify(m.content)}</div>
      </div>
      <div class="actions ml-auto opacity-0 group-hover:opacity-100 flex gap-1 pt-0.5">${actions}</div>
    </div>`;
  }

  function shouldGroup(prev, m) {
    if (!prev) return false;
    if (prev.kind !== 'message' || m.kind !== 'message') return false;
    if (String(prev.sender_id) !== String(m.sender_id)) return false;
    return (timeTs(m.created_at) - timeTs(prev.created_at)) < 300000;
  }

  function appendMsg(m) {
    if (!msgsEl) return;
    // Dedupe by id: a poll that was already in flight can echo a message the
    // sender just appended optimistically (this used to duplicate emojis/text).
    if (m.id && msgsEl.querySelector('.msg[data-id="' + m.id + '"]')) return;
    const date = String(m.created_at || '').slice(0, 10);
    let html = '';
    if (date && date !== lastDate) {
      html += dividerHtml(date);
      lastDate = date;
      lastMsg = null;
    }
    const grouped = shouldGroup(lastMsg, m);
    html += msgHtml(m, grouped);
    const last = msgsEl.lastElementChild;
    if (last) last.insertAdjacentHTML('afterend', html);
    else msgsEl.insertAdjacentHTML('beforeend', html);
    lastMsg = m.kind === 'message' ? m : null;
    if (parseInt(m.id, 10) > lastId) lastId = parseInt(m.id, 10);
    maybeScroll();
    bindMessageActions();
  }

  function maybeScroll() {
    const nearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 120;
    if (nearBottom) msgsEl.scrollTop = msgsEl.scrollHeight;
  }

  function scrollBottom() { msgsEl.scrollTop = msgsEl.scrollHeight; }

  function bindMessageActions() {
    msgsEl.querySelectorAll('.msg-del').forEach((btn) => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const msg = btn.closest('.msg');
        const id = msg.dataset.id;
        if (!confirm('Delete this message?')) return;
        post('/api/message/delete', { id }, () => msg.remove());
      });
    });
    msgsEl.querySelectorAll('.msg-edit').forEach((btn) => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const msg = btn.closest('.msg');
        const contentEl = msg.querySelector('.msg-content');
        const old = contentEl ? contentEl.textContent : '';
        const next = prompt('Edit message:', old);
        if (next === null || next === old) return;
        post('/api/message/edit', { id: msg.dataset.id, content: next }, () => {
          contentEl.innerHTML = linkify(next);
          if (!msg.querySelector('[data-id]')) msg.dataset.edited = '1';
        });
      });
    });
    msgsEl.querySelectorAll('.username[data-nick]').forEach((el) => {
      if (el.dataset.bound) return;
      el.dataset.bound = '1';
      el.addEventListener('click', () => { window.location = '/u/' + encodeURIComponent(el.dataset.nick); });
    });
  }

  function post(url, data, onOk) {
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('ajax', '1');
    Object.keys(data).forEach((k) => fd.append(k, data[k]));
    fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
      .then((r) => r.json().catch(() => ({ error: 'Server error' })))
      .then((j) => {
        if (j.error) { alert(j.error); return; }
        if (j.redirect) { window.location = j.redirect; return; }
        if (onOk) onOk(j);
      });
  }

  // ── Polling ────────────────────────────────────────────────────────────────
  let pollFails = 0;
  function poll() {
    const q = new URLSearchParams({ since: lastId });
    if (CHANNEL) q.set('channel', CHANNEL);
    if (DM) q.set('dm', DM);
    fetch('/api/poll?' + q.toString())
      .then((r) => r.json())
      .then((j) => {
        pollFails = 0;
        if (j.redirect) { window.location = j.redirect; return; }
        if (j.error) return;
        if (j.messages && j.messages.length) {
          const atBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 160;
          j.messages.forEach(appendMsg);
          if (atBottom) scrollBottom();
        }
        if (j.presence && CHANNEL) applyPresence(j.presence);
        if (typeof j.notify_count === 'number') setBell(j.notify_count);
        if (j.dm_list) handleDmList(j.dm_list);
      })
      .catch(() => {
        // Never silently drop a poll forever: keep retrying, but log once and
        // back off the scheduler so a dead server doesn't hammer the worker pool.
        pollFails++;
        if (pollFails === 1 || pollFails % 5 === 0) {
          console.warn('poll failed (' + pollFails + ' consecutive) — will keep retrying');
        }
      });
  }

  // ── Direct messages: live sidebar + arrival toast ─────────────────────────
  const dmSection = document.getElementById('dm-section');
  let dmSig = '';
  let dmSeen = {};
  let dmInit = false;
  let dmToastTimer = null;

  function dmItemHtml(d) {
    const cur = DM && d.username && d.username.toLowerCase() === DM.toLowerCase();
    const isAdmin = d.role === 'admin';
    const guestTag = d.guest ? ' <span class="text-[10px] text-discord-500">(guest)</span>' : '';
    const online = !!d.last_seen && !d.away && (Date.now() - timeTs(d.last_seen)) < 90000;
    const dot = d.away ? 'bg-amber-400' : (online ? 'bg-green-500' : 'bg-discord-500');
    const nameCls = isAdmin ? 'text-red-400' : '';
    return `<a href="/app?dm=${encodeURIComponent(d.username)}"
         data-ctx-user="${esc(d.username)}"
         class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm ${cur ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white'} ${online ? '' : 'italic opacity-70'}">
      <span class="w-2 h-2 rounded-full ${dot}"></span>
      <span class="truncate ${nameCls}">${esc(d.username)}${guestTag}</span>
      ${d.unread ? `<span class="ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center">${d.unread > 99 ? '99+' : d.unread}</span>` : ''}
    </a>`;
  }

  function renderDmSidebar(list) {
    if (!dmSection) return;
    const sig = JSON.stringify(list.map((d) => [d.user_id, d.username, d.unread, d.last_id]));
    if (sig === dmSig) return;
    dmSig = sig;
    dmSection.innerHTML = list.length
      ? list.map(dmItemHtml).join('')
      : '<div class="px-2 py-1 text-xs text-discord-500">No conversations yet</div>';
  }

  function handleDmList(list) {
    if (!dmInit) {
      // Seed toast-dedupe state without toasting for pre-existing unread mail.
      list.forEach((d) => { dmSeen[d.user_id] = d.last_id; });
      dmInit = true;
    } else {
      list.forEach((d) => {
        const prev = dmSeen[d.user_id] || 0;
        if (d.last_id > prev && d.unread > 0 && DM !== d.username) {
          showDmToast(d);
        }
        dmSeen[d.user_id] = d.last_id;
      });
      Object.keys(dmSeen).forEach((id) => {
        if (!list.some((d) => String(d.user_id) === id)) delete dmSeen[id];
      });
    }
    renderDmSidebar(list);
  }

  function showDmToast(d) {
    const t = document.getElementById('dm-toast');
    if (!t) return;
    const raw = d.last_content || '';
    const preview = raw.length > 80 ? raw.slice(0, 80) + '…' : raw;
    t.innerHTML = `<a href="/app?dm=${encodeURIComponent(d.username)}" class="flex items-start gap-3 px-4 py-3 hover:bg-discord-750 transition-colors">
      <span class="text-lg leading-none">💬</span>
      <span class="min-w-0">
        <span class="block text-sm font-semibold text-white truncate">New message from ${esc(d.username)}</span>
        ${preview ? `<span class="block text-xs text-discord-300 truncate">${esc(preview)}</span>` : ''}
      </span>
    </a>`;
    t.classList.remove('hidden');
    clearTimeout(dmToastTimer);
    dmToastTimer = setTimeout(() => t.classList.add('hidden'), 5000);
  }
  const dmToastEl = document.getElementById('dm-toast');
  if (dmToastEl) dmToastEl.addEventListener('click', () => dmToastEl.classList.add('hidden'));

  function applyPresence(list) {
    const el = document.getElementById('member-list');
    if (!el) return;
    const online = list.filter((m) => m.is_online);
    // Offline list only shows registered users — anonymous guests aren't listed when away.
    const offline = list.filter((m) => !m.is_online && !m.guest);
    const mk = (arr, on) => arr.map((m) => {
      const rs = (m.role !== 'admin' && m.role_color) ? ' style="color:' + esc(m.role_color) + '"' : '';
      const cc = m.role === 'admin' ? 'text-red-400' : (on ? (COLORS[m.level] || COLORS.normal) : 'text-discord-400');
      return `<a href="/app?dm=${encodeURIComponent(m.username)}" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ${cc}"${rs} data-username="${esc(m.username)}" data-level="${esc(m.level || 'normal')}">
        <span class="text-[10px] font-bold w-3">${SYMBOL[m.level] || ''}</span>
        <span class="truncate">${esc(m.username)}</span>${m.away ? '<span class="text-xs">💤</span>' : ''}${m.role === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>' : (m.role === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '')}</a>`;
    }).join('');
    el.innerHTML =
      `<div class="px-2 py-2"><div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Online — ${online.length}</div>${mk(online, true)}</div>` +
      `<div class="px-2 pb-2"><div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Offline — ${offline.length}</div>${mk(offline, false)}</div>`;
    const count = document.getElementById('member-count');
    if (count) count.textContent = String(list.filter((m) => m.is_online && !m.guest).length);
  }

  // ── Bell / notifications ───────────────────────────────────────────────────
  const bell = document.getElementById('bell');
  const bellDot = document.getElementById('bell-dot');
  const notifPanel = document.getElementById('notif-panel');
  function setBell(n) {
    if (!bellDot) return;
    if (n > 0) { bellDot.classList.remove('hidden'); bellDot.textContent = n > 99 ? '99+' : n; }
    else bellDot.classList.add('hidden');
  }
  if (bell) bell.addEventListener('click', () => {
    notifPanel.classList.toggle('hidden');
    if (!notifPanel.classList.contains('hidden')) {
      fetch('/api/notifications').then((r) => r.json()).then((j) => {
        const list = document.getElementById('notif-list');
        if (!j.notifications || !j.notifications.length) {
          list.innerHTML = '<div class="px-2 py-3 text-discord-500 text-center">Nothing new</div>';
          return;
        }
        list.innerHTML = j.notifications.map((n) => {
          if (n.kind === 'dm') {
            return `<div class="px-2 py-1.5 rounded hover:bg-discord-750 text-discord-300">
              <span class="text-discord-400">dm</span> from <a class="text-blurple hover:underline" href="/app?dm=${encodeURIComponent(n.sender || '')}">${esc(n.sender || 'system')}</a>
            </div>`;
          }
          return `<div class="px-2 py-1.5 rounded hover:bg-discord-750 text-discord-300">
            <span class="text-discord-400">${esc(n.kind)}</span>
            ${n.channel_name ? `→ <a class="text-blurple hover:underline" href="/app?channel=${encodeURIComponent(n.channel_name.replace(/^#/, ''))}">${esc(n.channel_name)}</a>` : ''}
            <span class="text-discord-400">from</span> ${esc(n.sender || 'system')}
          </div>`;
        }).join('');
      });
    }
  });
  const notifClear = document.getElementById('notif-clear');
  if (notifClear) notifClear.addEventListener('click', () => {
    post('/api/notifications/read', {}, () => { setBell(0); });
  });

  // ── Sending / commands ─────────────────────────────────────────────────────
  if (form) form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    hideAutocomplete();
    if (text[0] === '/') {
      post('/api/command', { channel: CHANNEL, text }, (j) => {
        if (j.redirect) { window.location = j.redirect; return; }
        if (j.action === 'clear') { msgsEl.innerHTML = ''; return; }
        if (j.copy) copyText(j.copy);
        (j.replies || []).forEach((r) => showReply(r));
      });
    } else if (CHANNEL || DM) {
      const payload = DM ? { recipient: DM, content: text } : { channel: CHANNEL, content: text };
      post('/api/send', payload, (j) => {
        if (j.message) {
          if (j.blocked) {
            // Let the message flash for a moment, then remove it and show the ChanServ notice.
            appendMsg(j.message);
            setTimeout(() => {
              const el = msgsEl.querySelector('.msg[data-id="' + j.message.id + '"]');
              if (el) el.remove();
              showReply(j.notice || 'Message removed by ChanServ');
            }, 900);
          } else {
            appendMsg(j.message);
          }
        }
      });
    }
  });

  function showReply(text) {
    if (!msgsEl) return;
    msgsEl.insertAdjacentHTML('beforeend',
      `<div class="msg-system px-4 py-1 text-xs text-sky-400 italic text-center select-none">${linkify(text)}</div>`);
    scrollBottom();
  }

  // ── Slash autocomplete ─────────────────────────────────────────────────────
  const ac = document.getElementById('autocomplete');
  let acIndex = 0;
  function showAutocomplete(filter) {
    const matches = COMMANDS.filter((c) => c.startsWith(filter) && c !== 'help');
    if (!matches.length) { hideAutocomplete(); return; }
    acIndex = 0;
    ac.innerHTML = matches.slice(0, 8).map((c, i) =>
      `<button type="button" data-ac="${i}" class="w-full text-left px-3 py-1.5 text-sm ${i === 0 ? 'bg-blurple/20 text-white' : 'text-discord-300'} hover:bg-blurple/20">/${c}</button>`).join('');
    ac.classList.remove('hidden');
    ac.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
      input.value = '/' + matches[parseInt(b.dataset.ac, 10)];
      input.focus();
      hideAutocomplete();
    }));
  }
  function hideAutocomplete() { ac.classList.add('hidden'); }
  if (input) input.addEventListener('input', () => {
    const v = input.value;
    const slash = v.indexOf('/');
    if (slash === 0) showAutocomplete(v.slice(1).split(/\s/)[0].toLowerCase());
    else hideAutocomplete();
  });
  if (input) input.addEventListener('keydown', (e) => {
    if (ac.classList.contains('hidden')) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); moveAc(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); moveAc(-1); }
    else if (e.key === 'Tab') { e.preventDefault(); pickAc(); }
    else if (e.key === 'Escape') hideAutocomplete();
  });
  function moveAc(d) {
    const btns = ac.querySelectorAll('button');
    acIndex = (acIndex + d + btns.length) % btns.length;
    btns.forEach((b, i) => { b.classList.toggle('bg-blurple/20', i === acIndex); b.classList.toggle('text-white', i === acIndex); b.classList.toggle('text-discord-300', i !== acIndex); });
  }
  function pickAc() {
    const btns = ac.querySelectorAll('button');
    if (btns[acIndex]) btns[acIndex].click();
  }

  // ── Channel mode bar (toggles above the chat) ──────────────────────────────
  function setModeChip(btn, on) {
    const flag = btn.dataset.mode;
    const short = btn.dataset.short || '';
    btn.textContent = (on ? '+' : '-') + flag + ' ' + short;
    btn.classList.toggle('bg-blurple/20', on);
    btn.classList.toggle('border-blurple/40', on);
    btn.classList.toggle('text-white', on);
    btn.classList.toggle('bg-discord-800', !on);
    btn.classList.toggle('border-discord-700', !on);
    btn.classList.toggle('text-discord-400', !on);
  }

  document.querySelectorAll('.mode-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const flag = btn.dataset.mode;
      const slug = btn.dataset.channel;
      const wasOn = btn.textContent.trim().startsWith('+');
      const turnOn = !wasOn;
      let cmd = '/mode #' + slug + (turnOn ? '+' : '-') + flag;
      if (flag === 'k' && turnOn) {
        const key = prompt('Set a channel key (leave empty to remove it):');
        if (key === null) return;
        cmd = key === '' ? '/mode #' + slug + '-k' : '/mode #' + slug + '+k ' + key;
      } else if (flag === 'l' && turnOn) {
        const lim = prompt('Max members:');
        if (lim === null) return;
        const n = parseInt(lim, 10);
        if (!n || n < 1) return;
        cmd = '/mode #' + slug + '+l ' + n;
      }
      post('/api/command', { channel: slug, text: cmd }, (j) => {
        if (j.redirect) { window.location = j.redirect; return; }
        if (j.mode_ok !== true) return; // keep the chip as-is when the server rejected it
        setModeChip(btn, turnOn);
        if (flag === 'p' || flag === 's') {
          const other = document.querySelector('.mode-toggle[data-mode="' + (flag === 'p' ? 's' : 'p') + '"][data-channel="' + slug + '"]');
          if (other) setModeChip(other, false);
        }
      });
    });
  });

  const modeHelpBtn = document.querySelector('[data-help-mode]');
  if (modeHelpBtn) modeHelpBtn.addEventListener('click', () => runCommand('/mode ' + modeHelpBtn.dataset.helpMode));

  // ── Emoji picker ────────────────────────────────────────────────────────────
  const EMOJIS = ('😀 😃 😄 😁 😆 😅 😂 🤣 😊 😇 🙂 🙃 😉 😌 😍 🥰 😘 😋 😛 😝 😜 🤪 🤨 🧐 🤓 😎 🤩 🥳 😏 😒 😞 😔 😟 😕 🙁 ☹ 😣 😖 😫 😩 🥺 😢 😭 😤 😠 😡 🤬 🤯 😳 🥵 🥶 😱 😨 😰 😥 😓 🤗 🤔 🤭 🤫 🤥 😶 😐 😑 😬 🙄 😯 😦 😧 😮 😲 🥱 😴 🤤 😪 😵 🤐 🥴 🤢 🤮 🤧 😷 🤒 🤕 🤑 🤠 😈 👿 👹 👺 🤡 💩 👻 💀 👽 👾 🤖 🎃 😺 😸 😹 😻 😼 😽 🙀 😿 😾' +
  ' 👋 🤚 🖐 ✋ 🖖 👌 🤌 🤏 ✌ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍ 💅 🤳 💪 🦾 🖕' +
  ' ❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 💕 💞 💓 💗 💖 💘 💝 💯 🔥 ⚡ ✨ 🌟 🎉 🎊 🎈 🎁 🏆 🥇 🥈 🥉 ⭐ 🌈 ☀️ 🌙 ⭐ 💫' +
  ' 🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🐔 🐧 🐦 🐤 🦆 🦅 🦉 🐺 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐢 🐍 🦎 🐙 🦑 🐠 🐟 🐬 🐳 🦈 🐊 🐅 🐆 🐘 🦏 🐫 🦒 🐕 🐈' +
  ' 🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🥑 🥦 🥬 🥕 🌽 🥔 🍞 🥖 🥨 🧀 🥚 🍳 🥞 🥓 🍔 🍟 🍕 🌮 🌯 🥗 🍜 🍲 🍣 🍱 🍝 🍦 🍰 🎂 🧁 🍪 🍩 🍫 🍿 🍯 ☕ 🍵 🍺 🍻 🥂 🍷 🍸' +
  ' ❤️ 💔 💯 💢 💥 💫 💦 💨 🕳 💬 💭 👁 👀 🧠 🦴 🦷 👅 👄 💋' +
  ' 🚗 🚕 🚙 🚌 🏎 🚓 🚑 🚒 🚐 🚚 🚛 🚜 🛴 🚲 🛵 🏍 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚟 🚃 🚋 🚝 🚄 🚅 🚈 🚞 🚂 🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 🛩 💺 🛰 🚀 🛸 🚁 🛶 ⛵ 🚤 🛥 🛳 ⛴ 🚢 ⚓' +
  ' ⌚ 📱 💻 ⌨️ 🖥 🖨 🖱 💽 💾 💿 📀 📼 📷 📸 📹 🎥 📽 🎞 📞 ☎️ 📟 📠 📺 📻 🎙 🎚 🎛 🧭 ⏱ ⏲ ⏰ 🕰 ⌛ ⏳ 📡 🔋 🔌 💡 🔦 🕯 🪔 🧯 🛢 💸 💵 💴 💶 💷 💰 💳' +
  ' 🗿 🗽 🗼 🏰 🏯 🏟 🎡 🎢 🎠 ⛲ ⛱ 🏖 🏝 🏜 🌋 ⛰ 🏔 🗻 🏕 ⛺ 🏠 🏡 🏘 🏚 🏗 🏭 🏢 🏬 🏣 🏤 🏥 🏦 🏨 🏪 🏫 🏩 💒 🏛 ⛪ 🕌 🕍 🕋 ⛩').split(/\s+/).filter(Boolean);
  const emojiPanel = document.getElementById('emoji-panel');
  const emojiBtn = document.getElementById('emoji-btn');
  function populateEmojis() {
    if (!emojiPanel) return;
    emojiPanel.innerHTML = EMOJIS.map((e) => '<button type="button" class="emoji-btn text-xl hover:bg-discord-700 rounded p-1" data-emoji="' + e + '">' + e + '</button>').join('');
    emojiPanel.querySelectorAll('.emoji-btn').forEach((b) => b.addEventListener('click', () => insertEmoji(b.dataset.emoji)));
  }
  function insertEmoji(emoji) {
    const i = document.getElementById('chat-input');
    if (!i) return;
    const start = i.selectionStart ?? i.value.length;
    const end = i.selectionEnd ?? i.value.length;
    i.value = i.value.slice(0, start) + emoji + i.value.slice(end);
    const pos = start + emoji.length;
    i.setSelectionRange(pos, pos);
    i.focus();
    hideEmojiPanel();
  }
  function hideEmojiPanel() { if (emojiPanel) emojiPanel.classList.add('hidden'); }
  if (emojiBtn) emojiBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (emojiPanel.classList.contains('hidden')) { populateEmojis(); emojiPanel.classList.remove('hidden'); }
    else hideEmojiPanel();
  });
  document.addEventListener('click', (e) => {
    if (e.target.closest('#emoji-panel') || e.target.closest('#emoji-btn')) return;
    hideEmojiPanel();
  });

  // ── Sidebar toggle (desktop collapse + mobile drawer) ─────────────────────
  (function () {
    const btn = document.getElementById('sidebar-toggle');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (!btn) return;
    const isMobile = () => window.innerWidth < 768;
    function closeSidebar() {
      document.body.classList.remove('sidebar-open');
      if (backdrop) backdrop.classList.add('hidden');
    }
    function openSidebar() {
      document.body.classList.add('sidebar-open');
      if (backdrop) backdrop.classList.remove('hidden');
    }
    btn.addEventListener('click', () => {
      if (isMobile()) {
        if (document.body.classList.contains('sidebar-open')) closeSidebar();
        else openSidebar();
      } else {
        const collapsed = document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('lvc.sidebar', collapsed ? '0' : '1'); } catch (e) {}
      }
    });
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeSidebar(); });
    try {
      if (!isMobile() && localStorage.getItem('lvc.sidebar') === '0') {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
  })();

  // ── Share / part / create ──────────────────────────────────────────────────
  const shareBtn = document.getElementById('share-btn');
  if (shareBtn) shareBtn.addEventListener('click', () => {
    const url = window.location.origin + '/c/' + encodeURIComponent(CHANNEL);
    copyText(url, (ok) => {
      shareBtn.textContent = ok ? '✓ Copied' : 'Copy failed';
      shareBtn.classList.toggle('text-red-400', !ok);
      setTimeout(() => { shareBtn.textContent = '🔗 Share'; shareBtn.classList.remove('text-red-400'); }, 1500);
    });
  });
  const partBtn = document.getElementById('part-btn');
  if (partBtn) partBtn.addEventListener('click', () => {
    if (!confirm('Leave this channel?')) return;
    post('/api/part', { channel: CHANNEL }, () => { window.location = '/app'; });
  });
  function createChannel() {
    const name = prompt('Channel name (e.g. #gaming):');
    if (!name) return;
    post('/api/channels', { name }, () => {});
  }
  const cc1 = document.getElementById('create-channel');
  const cc2 = document.getElementById('create-channel-2');
  if (cc1) cc1.addEventListener('click', createChannel);
  if (cc2) cc2.addEventListener('click', createChannel);

  // ── User menu / away ───────────────────────────────────────────────────────
  const menuBtn = document.getElementById('user-menu-btn');
  const menu = document.getElementById('user-menu');
  if (menuBtn) menuBtn.addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('hidden'); });
  document.addEventListener('click', () => { if (menu) menu.classList.add('hidden'); });
  const awayBtn = document.getElementById('set-away-btn');
  if (awayBtn) awayBtn.addEventListener('click', () => {
    const msg = prompt('Away message (leave empty to come back):', '');
    if (msg === null) return;
    post('/api/profile', { away: msg.trim() }, () => { window.location.reload(); });
  });

  // ── Right-click context menus ──────────────────────────────────────────────
  const ctxMenu = document.getElementById('ctx-menu');

  function copyText(t, onDone) {
    const finish = (ok) => { if (onDone) onDone(!!ok); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(t).then(() => finish(true)).catch(() => finish(fallbackCopy(t)));
    } else {
      finish(fallbackCopy(t));
    }
  }
  function fallbackCopy(t) {
    const ta = document.createElement('textarea');
    ta.value = t;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    return ok;
  }

  function runCommand(text) {
    post('/api/command', { channel: CHANNEL, text }, (j) => {
      if (j.redirect) { window.location = j.redirect; return; }
      if (j.replies) j.replies.forEach((r) => showReply(r));
    });
  }

  function ctxShow(x, y, items) {
    if (!ctxMenu) return;
    ctxMenu.innerHTML = items.map((it, i) =>
      it.div
        ? '<div class="h-px bg-discord-700 my-1"></div>'
        : `<button type="button" data-i="${i}" class="w-full text-left px-2 py-1.5 rounded-md hover:bg-discord-600/50 ${it.danger ? 'text-red-400' : 'text-discord-200'}">${esc(it.label)}</button>`
    ).join('');
    ctxMenu.classList.remove('hidden');
    const r = ctxMenu.getBoundingClientRect();
    const vw = window.innerWidth, vh = window.innerHeight;
    if (x + r.width > vw - 4) x = vw - r.width - 4;
    if (y + r.height > vh - 4) y = vh - r.height - 4;
    ctxMenu.style.left = Math.max(0, x) + 'px';
    ctxMenu.style.top = Math.max(0, y) + 'px';
    ctxMenu.querySelectorAll('button').forEach((b) => {
      b.addEventListener('click', () => { const it = items[parseInt(b.dataset.i, 10)]; if (it.onClick) it.onClick(); ctxHide(); });
    });
  }

  function ctxHide() { if (ctxMenu) ctxMenu.classList.add('hidden'); }
  document.addEventListener('click', ctxHide);
  document.addEventListener('scroll', ctxHide, true);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') ctxHide(); });

  function channelMenu(x, y, el) {
    const slug = el.dataset.ctxChannel;
    const name = el.dataset.ctxChannelName || '#' + slug;
    const items = [
      { label: 'Open channel', onClick: () => { window.location = '/c/' + encodeURIComponent(slug); } },
      { label: 'Copy share link', onClick: () => copyText(window.location.origin + '/c/' + encodeURIComponent(slug)) },
      { label: 'Get embed code', onClick: () => openEmbed(slug) },
      { label: 'Channel info', onClick: () => runCommand('/chaninfo ' + name) },
    ];
    if (CHANNEL === slug && (CAN_OP || CAN_ADMIN)) {
      items.push({ label: 'Set topic', onClick: () => { const t = prompt('New topic:', ''); if (t !== null) runCommand('/topic ' + name + (t ? ' ' + t : '')); } });
      items.push({ label: 'Invite', onClick: () => { const n = prompt('Invite user:', ''); if (n) runCommand('/invite ' + n + ' ' + name); } });
    }
    if (el.dataset.owned === '1') {
      items.push({ div: true });
      items.push({ label: 'Delete channel', danger: true, onClick: () => {
        if (!confirm('Delete ' + name + '? The channel will be closed, but its chat history is preserved for admins.')) return;
        post('/api/channel/delete', { channel: slug }, () => { window.location = '/app'; });
      } });
    }
    items.push({ div: true });
    items.push({ label: 'Leave channel', danger: true, onClick: () => post('/api/part', { channel: slug }, () => { window.location = '/app'; }) });
    ctxShow(x, y, items);
  }

  function userMenu(x, y, el) {
    const nick = el.dataset.ctxUser || el.dataset.username || el.dataset.nick;
    if (!nick) return;
    const isSelf = nick.toLowerCase() === MY_NICK;
    const level = el.dataset.level || '';
    const items = [
      { label: 'Message ' + nick, onClick: () => { window.location = '/app?dm=' + encodeURIComponent(nick); } },
      { label: 'View profile', onClick: () => { window.location = '/u/' + encodeURIComponent(nick); } },
      { label: 'Whois', onClick: () => runCommand('/whois ' + nick) },
      { label: 'Copy username', onClick: () => copyText(nick) },
    ];
    if (!isSelf && CHANNEL && (CAN_OP || CAN_ADMIN)) {
      items.push({ div: true });
      items.push(
        level === 'voice'
          ? { label: 'Devoice', onClick: () => runCommand('/devoice ' + nick) }
          : { label: 'Voice', onClick: () => runCommand('/voice ' + nick) }
      );
      items.push(
        level === 'halfop'
          ? { label: 'De-half-op', onClick: () => runCommand('/dehalfop ' + nick) }
          : { label: 'Half-op', onClick: () => runCommand('/halfop ' + nick) }
      );
      items.push(
        (level === 'op' || level === 'admin' || level === 'founder')
          ? { label: 'Deop', onClick: () => runCommand('/deop ' + nick) }
          : { label: 'Op', onClick: () => runCommand('/op ' + nick) }
      );
      items.push({ label: 'Mute', danger: true, onClick: () => runCommand('/quiet ' + nick) });
      items.push({ label: 'Kick', danger: true, onClick: () => { const r = prompt('Reason (optional):', ''); if (r !== null) runCommand('/kick ' + nick + (r ? ' ' + r : '')); } });
      items.push({ label: 'Ban', danger: true, onClick: () => { const r = prompt('Reason (optional):', ''); if (r !== null) runCommand('/ban ' + nick + (r ? ' ' + r : '')); } });
    }
    if (!isSelf) {
      items.push({ div: true });
      items.push({ label: 'Ignore ' + nick, onClick: () => runCommand('/ignore ' + nick) });
    }
    ctxShow(x, y, items);
  }

  function msgMenu(x, y, el) {
    const id = el.dataset.id;
    const author = el.dataset.author;
    const contentEl = el.querySelector('.msg-content');
    const content = contentEl ? contentEl.textContent : '';
    const items = [];
    if (author && author.toLowerCase() !== MY_NICK) {
      items.push({ label: 'Reply', onClick: () => { input.value = '@' + author + ' '; input.focus(); hideAutocomplete(); } });
    }
    if (content) items.push({ label: 'Copy text', onClick: () => copyText(content) });
    const mine = author && author.toLowerCase() === MY_NICK;
    if (CAN_ADMIN) {
      items.push({ label: 'Edit', onClick: () => {
        const next = prompt('Edit message:', content);
        if (next === null || next === content) return;
        post('/api/message/edit', { id, content: next }, () => {
          if (contentEl) contentEl.innerHTML = linkify(next);
        });
      } });
    }
    if (mine || CAN_ADMIN || CAN_OP) {
      items.push({ label: 'Delete', danger: true, onClick: () => post('/api/message/delete', { id }, () => el.remove()) });
    }
    if (author && author.toLowerCase() !== MY_NICK) {
      items.push({ label: 'Message ' + author, onClick: () => { window.location = '/app?dm=' + encodeURIComponent(author); } });
      items.push({ label: 'Copy username', onClick: () => copyText(author) });
    }
    if (!items.length) return;
    ctxShow(x, y, items);
  }

  document.addEventListener('contextmenu', (e) => {
    if (e.target.closest('#ctx-menu')) return;
    const ch = e.target.closest('[data-ctx-channel]');
    if (ch) { e.preventDefault(); channelMenu(e.clientX, e.clientY, ch); return; }
    const u = e.target.closest('[data-ctx-user], .member[data-username], .username[data-nick]');
    if (u) { e.preventDefault(); userMenu(e.clientX, e.clientY, u); return; }
    const m = e.target.closest('.msg[data-id]');
    if (m) { e.preventDefault(); msgMenu(e.clientX, e.clientY, m); }
  });

  // ── Theme toggle (light/dark, sticky per browser + per account) ────────────
  const themeBtn = document.getElementById('theme-toggle');
  function setThemeIcon() {
    if (!themeBtn) return;
    themeBtn.textContent = document.documentElement.classList.contains('light') ? '☀️' : '🌙';
    themeBtn.title = document.documentElement.classList.contains('light') ? 'Switch to dark mode' : 'Switch to light mode';
  }
  if (themeBtn) themeBtn.addEventListener('click', () => {
    const light = document.documentElement.classList.toggle('light');
    const theme = light ? 'light' : 'dark';
    try { localStorage.setItem('lvc.theme', theme); } catch (e) {}
    setThemeIcon();
    post('/api/profile', { theme }, () => {});
  });
  setThemeIcon();

  // ── Embed code ──────────────────────────────────────────────────────────────
  const embedModal = document.getElementById('embed-modal');
  const embedCode = document.getElementById('embed-code');
  function openEmbed(slug) {
    if (!embedModal || !embedCode) return;
    const s = slug || CHANNEL || 'general';
    const url = window.location.origin + '/embed/' + encodeURIComponent(s);
    const site = body.dataset.siteName || 'LVChat';
    embedCode.value = '<iframe src="' + url + '" style="width:100%;height:600px;border:0;border-radius:8px" title="' + esc(site) + '" allowfullscreen></iframe>';
    embedModal.classList.remove('hidden');
  }
  document.querySelectorAll('[data-embed]').forEach((el) => {
    el.addEventListener('click', (e) => { e.preventDefault(); openEmbed(el.dataset.embed); });
  });
  if (embedModal) {
    embedModal.querySelectorAll('[data-embed-close]').forEach((el) => el.addEventListener('click', () => embedModal.classList.add('hidden')));
    const embedCopy = document.getElementById('embed-copy');
    if (embedCopy) embedCopy.addEventListener('click', () => {
      copyText(embedCode.value, (ok) => {
        embedCopy.textContent = ok ? '✓ Copied' : 'Copy failed';
        embedCopy.classList.toggle('text-red-400', !ok);
        setTimeout(() => { embedCopy.textContent = 'Copy code'; embedCopy.classList.remove('text-red-400'); }, 1500);
      });
    });
  }

  // ── Boot ───────────────────────────────────────────────────────────────────
  scrollBottom();
  bindMessageActions();
  // Runtime diagnostic: confirm the chat fills the viewport (body rect vs window).
  (() => {
    const r = document.body.getBoundingClientRect();
    document.body.dataset.viewport = window.innerWidth + 'x' + window.innerHeight;
    document.body.dataset.bodyRect = Math.round(r.width) + 'x' + Math.round(r.height);
  })();
  document.body.dataset.jsok = '1';
  // Jittered scheduling spreads client requests so they don't burst together
  // (protects the shared-hosting PHP worker pool). Interval comes from config.
  const pollMs = Math.max(1000, parseInt(body.dataset.pollMs || '2000', 10));
  const jitter = (base) => base + Math.floor(Math.random() * base * 0.25);
  function schedulePoll() {
    // Back off when the server keeps failing, then recover automatically.
    const base = pollFails >= 8 ? pollMs * 5 : pollFails >= 3 ? pollMs * 2 : pollMs;
    setTimeout(() => { poll(); schedulePoll(); }, jitter(base));
  }
  setTimeout(poll, Math.floor(Math.random() * pollMs));
  schedulePoll();
})();

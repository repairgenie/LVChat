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

  function linkifyInline(text) {
    text = esc(text);
    text = text.replace(/`([^`\n]+)`/g, '<code class="bg-discord-800 px-1 rounded">$1</code>');
    text = text.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
    text = text.replace(/~~([^~\n]+)~~/g, '<s>$1</s>');
    text = text.replace(/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/g, '<span class="mention text-blurple font-semibold">@$1</span>');
    text = text.replace(/(https?:\/\/[^\s<]+)/gi, '<a class="text-sky-400 hover:underline" target="_blank" rel="noopener" href="$1">$1</a>');
    return text;
  }

  function linkify(text) {
    text = String(text == null ? '' : text);
    // Extract fenced code blocks first so inner newlines survive.
    const blocks = [];
    text = text.replace(/```([a-z0-9]*)\s*\n([\s\S]*?)```/g, (m, lang, code) => {
      const i = blocks.length;
      const langTag = lang ? `<span class="text-[10px] text-discord-400 font-mono">${esc(lang)}</span>` : '';
      blocks[i] = `<div class="code-block bg-discord-900/70 border border-discord-700 rounded-lg p-3 my-1.5 text-[13px] leading-relaxed overflow-x-auto">${langTag}<pre class="whitespace-pre-wrap font-mono text-discord-200"><code>${esc(code.replace(/\n$/, ''))}</code></pre></div>`;
      return '\x02BLK' + i + '\x03';
    });
    let html = '';
    let listBuf = [];
    let listType = null;
    let quoteBuf = [];
    const flushList = () => {
      if (!listBuf.length) return;
      const tag = listType === 'ol' ? 'ol' : 'ul';
      const cls = listType === 'ol' ? 'list-decimal' : 'list-disc';
      html += `<${tag} class="${cls} list-outside pl-5 my-1.5 space-y-0.5">` + listBuf.map((item) => `<li class="leading-relaxed">${linkifyInline(item)}</li>`).join('') + `</${tag}>`;
      listBuf = [];
      listType = null;
    };
    const flushQuote = () => {
      if (!quoteBuf.length) return;
      html += `<blockquote class="border-l-2 border-blurple/50 pl-2 my-1.5 text-discord-300">` + quoteBuf.map((q) => linkifyInline(q)).join('<br>') + `</blockquote>`;
      quoteBuf = [];
    };
    text.split('\n').forEach((line) => {
      if (/^&gt; ?(.*)$/.test(line) || /^> ?(.*)$/.test(line)) {
        flushList();
        quoteBuf.push(line.replace(/^&gt; ?/, '').replace(/^> ?/, ''));
        return;
      }
      if (/^[-*] (.*)$/.test(line)) {
        flushQuote();
        if (listType !== 'ul') { flushList(); listType = 'ul'; }
        listBuf.push(line.replace(/^[-*] /, ''));
        return;
      }
      if (/^(\d+)\. (.*)$/.test(line)) {
        flushQuote();
        if (listType !== 'ol') { flushList(); listType = 'ol'; }
        listBuf.push(line.replace(/^\d+\. /, ''));
        return;
      }
      flushList();
      flushQuote();
      if (!line.trim()) return;
      if (/^\x02BLK\d+\x03$/.test(line)) { html += line; return; }
      html += `<div class="leading-relaxed">${linkifyInline(line)}</div>`;
    });
    flushList();
    flushQuote();
    blocks.forEach((b, i) => { html = html.replace('\x02BLK' + i + '\x03', b); });
    return html;
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

  function msgContentHtml(m) {
    if (m.kind === 'gif') {
      const parts = String(m.content || '').split('\n');
      const url = (parts.shift() || '').trim();
      const caption = parts.join('\n').trim();
      let html = '';
      if (url) {
        html += `<a href="${esc(url)}" class="inline-block mt-1" target="_blank" rel="noopener"><img src="${esc(url)}" alt="${esc(caption || 'GIF')}" loading="lazy" class="max-h-72 max-w-full rounded-lg border border-discord-700 object-contain hover:opacity-90 transition-opacity"></a>`;
      }
      if (caption) html += `<div class="mt-1">${linkify(caption)}</div>`;
      return html;
    }
    if (m.kind === 'image') {
      const parts = String(m.content || '').split('\n');
      const path = (parts.shift() || '').trim();
      const caption = parts.join('\n').trim();
      let html = '';
      if (path) {
        html += `<a href="${esc(path)}" class="inline-block mt-1" data-lightbox="${esc(path)}"><img src="${esc(path)}" alt="${esc(caption)}" loading="lazy" class="max-h-72 max-w-full rounded-lg border border-discord-700 object-contain hover:opacity-90 transition-opacity"></a>`;
      }
      if (caption) html += `<div class="mt-1">${linkify(caption)}</div>`;
      return html;
    }
    return linkify(m.content);
  }

  function avatarHtml(m, cls) {
    if (m.avatar) return `<img src="${esc(m.avatar)}" alt="${esc(m.username || '')}" loading="lazy" class="${cls} object-cover">`;
    const initial = (m.username || '?').charAt(0).toUpperCase();
    return `<div class="${cls} bg-discord-500 flex items-center justify-center text-sm font-bold text-white border border-discord-600 shrink-0">${esc(initial)}</div>`;
  }

  function msgReactionsHtml(m) {
    const rows = m.reactions || [];
    if (!rows.length) return '';
    const mine = (m.my_reactions || []).map(String);
    const chips = rows.map((r) => {
      const isMine = mine.indexOf(String(r.emoji)) !== -1;
      return `<button type="button" class="reaction-btn flex items-center gap-1 px-2 py-0.5 rounded-md text-xs border transition-colors hover:border-blurple/60 hover:bg-blurple/20 ${isMine ? 'bg-blurple/30 border-blurple/60' : 'bg-discord-800 border-discord-700'}" data-emoji="${esc(r.emoji)}" title="Toggle reaction"><span class="text-sm leading-none">${esc(r.emoji)}</span><span class="text-discord-300 font-medium">${parseInt(r.count, 10)}</span></button>`;
    }).join('');
    return `<div class="msg-reactions flex flex-wrap gap-1.5 mt-1.5">${chips}<button type="button" class="reaction-add px-2 py-0.5 rounded-md text-xs bg-discord-800 border border-discord-700 text-discord-400 hover:text-white hover:border-blurple/60" title="Add a reaction">+</button></div>`;
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
      return `<div class="msg group px-4 py-0.5 flex gap-4 hover:bg-white/[0.03]" data-id="${m.id}" data-kind="action" data-is-pm="${m.is_pm ? '1' : '0'}" data-author="${esc(m.username)}" data-guest="${m.guest ? '1' : '0'}">
        <div class="w-10 shrink-0"></div>
        <div class="text-sm ${contentColor}"${roleStyle}><span class="italic">* <span class="font-medium ${nameColor}"${roleStyle}>${esc(m.username)}</span>${guestTag} ${linkify(m.content)}</span></div>
        <button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-0.5" title="More">⋮</button>
      </div>`;
    }
    const sym = SYMBOL[m.level || 'normal'] || '';
    const replyLine = m.reply_to_id
      ? `<a class="reply-line block text-xs text-discord-400 italic hover:text-discord-300 mt-0.5 break-all" href="#msg-${parseInt(m.reply_to_id, 10)}" data-reply-scroll="${parseInt(m.reply_to_id, 10)}">↪ <span class="font-semibold">${esc(m.reply_to_username || '')}</span>: ${esc(m.reply_to_excerpt || '')}</a>`
      : '';
    if (grouped) {
      return `<div class="msg group px-4 py-0.5 hover:bg-white/[0.03] flex gap-4" data-id="${m.id}" data-kind="${esc(m.kind)}" data-is-pm="${m.is_pm ? '1' : '0'}" data-author="${esc(m.username)}" data-guest="${m.guest ? '1' : '0'}">
        <div class="w-10 shrink-0"></div>
        <div class="min-w-0 flex-1">
          ${replyLine}
          <div class="msg-content text-[15px] leading-[1.4] ${contentColor} break-words"${roleStyle}>${msgContentHtml(m)}</div>
          ${msgReactionsHtml(m)}
        </div>
        <button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-0.5" title="More">⋮</button>
      </div>`;
    }
    let actions = '';
    const mine = String(m.sender_id) === String(MY_ID) || (m.username && m.username.toLowerCase() === MY_NICK);
    if (CAN_ADMIN || mine) {
      actions = '<button class="msg-edit text-[12px] opacity-60 hover:opacity-100" title="Edit">✏️</button>'
        + '<button class="msg-del text-[12px] opacity-60 hover:opacity-100 hover:text-red-400" title="Delete">🗑</button>';
    }
    return `<div class="msg group px-4 pt-[17px] pb-0.5 hover:bg-white/[0.03] flex gap-4" data-id="${m.id}" data-kind="${esc(m.kind)}" data-is-pm="${m.is_pm ? '1' : '0'}" data-author="${esc(m.username)}" data-guest="${m.guest ? '1' : '0'}">
      <div class="w-10 h-10 shrink-0">${avatarHtml(m, 'w-10 h-10 rounded-full')}</div>
      <div class="min-w-0 flex-1">
        <div class="flex items-baseline gap-2 h-[22px]">
          <span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ${nameColor}"${roleStyle} data-nick="${esc(m.username)}">${sym}${esc(m.username)}${guestTag}</span>
          <span class="time text-[11px] text-discord-400 hidden group-hover:inline">${timeStr(m.created_at)}</span>
          ${m.edited_at ? '<span class="text-[10px] text-discord-400">(edited)</span>' : ''}
        </div>
        ${replyLine}
        <div class="msg-content text-[15px] leading-[1.4] ${contentColor} break-words"${roleStyle}>${msgContentHtml(m)}</div>
        ${msgReactionsHtml(m)}
      </div>
      <div class="actions ml-auto opacity-0 group-hover:opacity-100 flex gap-1 pt-0.5">${actions}</div>
      <button type="button" class="msg-ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 self-start mt-1" title="More">⋮</button>
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
    scrollBottomWhenImagesLoad(msgsEl.lastElementChild);
    bindMessageActions();
  }

  // ── Stick-to-bottom ─────────────────────────────────────────────────────────
  // New content auto-scrolls into view by default. The moment the reader scrolls
  // up (off the very end), the view holds its position for new messages instead;
  // scrolling back to the end resumes auto-scroll. This also covers images, which
  // are lazy-loaded and grow after the initial scroll — the view re-pins to the
  // bottom as they finish loading, but only while still stuck to the bottom.
  let stickToBottom = true;
  const STICK_THRESHOLD = 40;
  function isAtBottom() {
    return msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < STICK_THRESHOLD;
  }
  if (msgsEl) msgsEl.addEventListener('scroll', () => { stickToBottom = isAtBottom(); }, { passive: true });

  function maybeScroll() {
    if (stickToBottom) scrollBottom();
  }

  function scrollBottom() { msgsEl.scrollTop = msgsEl.scrollHeight; }

  // Images (uploads) are lazy-loaded, so they expand after the initial scroll —
  // pin the view to the bottom again as each one loads so a just-posted image is
  // fully visible. Re-scrolls only while the reader is still stuck to the bottom,
  // so it never yanks someone who scrolled up.
  function scrollBottomWhenImagesLoad(el) {
    if (!el || !el.querySelector('img')) return;
    const imgs = el.querySelectorAll('img');
    let pending = imgs.length;
    const done = () => { if (--pending <= 0) maybeScroll(); };
    imgs.forEach((img) => {
      if (img.complete) done();
      else { img.addEventListener('load', done); img.addEventListener('error', done); }
    });
  }

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
      el.addEventListener('click', () => {
        const msg = el.closest('.msg');
        if (msg && msg.dataset.guest === '1') {
          showGuestProfileModal(el.dataset.nick);
        } else {
          window.location = '/u/' + encodeURIComponent(el.dataset.nick);
        }
      });
    });

    // Reactions: click a chip to toggle, "+" to add from the quick picker.
    const react = (btn, emoji) => {
      const msg = btn.closest('.msg');
      if (!msg) return;
      post('/api/message/reaction', { id: msg.dataset.id, emoji }, (j) => {
        if (!j.reactions) return;
        renderReactions(msg, j.reactions);
      });
    };
    msgsEl.querySelectorAll('.reaction-btn').forEach((btn) => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => react(btn, btn.dataset.emoji));
    });
    msgsEl.querySelectorAll('.reaction-add').forEach((btn) => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const msg = btn.closest('.msg');
        if (!msg) return;
        const emoji = prompt('Emoji to add:', '👍');
        if (!emoji) return;
        react(btn, emoji.trim());
      });
    });
  }

  function renderReactions(msgEl, reactions) {
    if (!msgEl) return;
    let el = msgEl.querySelector('.msg-reactions');
    const html = reactions.rows && reactions.rows.length
      ? reactions.rows.map((r) => {
          const isMine = (reactions.mine || []).indexOf(String(r.emoji)) !== -1;
          return `<button type="button" class="reaction-btn flex items-center gap-1 px-2 py-0.5 rounded-md text-xs border transition-colors hover:border-blurple/60 hover:bg-blurple/20 ${isMine ? 'bg-blurple/30 border-blurple/60' : 'bg-discord-800 border-discord-700'}" data-emoji="${esc(r.emoji)}" title="Toggle reaction"><span class="text-sm leading-none">${esc(r.emoji)}</span><span class="text-discord-300 font-medium">${parseInt(r.count, 10)}</span></button>`;
        }).join('')
      : '';
    if (html) {
      const wrap = `<div class="msg-reactions flex flex-wrap gap-1.5 mt-1.5">${html}<button type="button" class="reaction-add px-2 py-0.5 rounded-md text-xs bg-discord-800 border border-discord-700 text-discord-400 hover:text-white hover:border-blurple/60" title="Add a reaction">+</button></div>`;
      if (el) el.outerHTML = wrap;
      else msgEl.querySelector('.msg-content')?.insertAdjacentHTML('afterend', wrap);
    } else if (el) {
      el.remove();
    }
    bindMessageActions();
  }

  function post(url, data, onOk, onFail) {
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
      })
      .catch(() => { if (onFail) onFail(); });
  }

  // ── Offline detection + offline send queue (PWA) ─────────────────────────
  // While disconnected the page keeps showing the last rendered messages, and
  // anything the user types is queued (localStorage) and delivered in order as
  // soon as the connection returns. The service worker handles serving the
  // cached shell/history; this side just flips the banner and the queue.
  const offlineBanner = document.getElementById('offline-banner');
  let sendQueue = [];
  try { sendQueue = JSON.parse(localStorage.getItem('lvc.offline.queue') || '[]'); } catch (e) { sendQueue = []; }
  function persistQueue() {
    try { localStorage.setItem('lvc.offline.queue', JSON.stringify(sendQueue)); } catch (e) {}
  }
  function enqueueSend(payload) {
    sendQueue.push({ at: Date.now(), payload });
    persistQueue();
    showReply('📡 Offline — message queued. It will be delivered when you reconnect.');
  }
  function appendQueuedSent(item, j) {
    // Only render a flushed message into the view it was sent to (the user may
    // have navigated to another channel while the message sat in the queue).
    if (!j.message) return;
    const sentTo = item.payload.recipient || item.payload.channel;
    if (item.payload.channel) {
      if (CHANNEL === sentTo) appendMsg(j.message);
    } else if (item.payload.recipient) {
      if (DM && DM.toLowerCase() === String(sentTo).toLowerCase()) appendMsg(j.message);
    }
  }
  function flushSendQueue() {
    if (!sendQueue.length || !navigator.onLine) return;
    const item = sendQueue[0];
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('ajax', '1');
    Object.keys(item.payload).forEach((k) => fd.append(k, item.payload[k]));
    fetch('/api/send', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
      .then((r) => r.json().catch(() => ({ error: 'Server error' })))
      .then((j) => {
        if (j.redirect) { window.location = j.redirect; return; }
        // Only drop the message once the server actually answered — a network
        // blip keeps it queued, a server error surfaces it as undeliverable.
        sendQueue.shift();
        persistQueue();
        if (j.error) {
          showReply('⚠ A queued offline message could not be delivered: ' + j.error);
          flushSendQueue();
          return;
        }
        appendQueuedSent(item, j);
        flushSendQueue();
      })
      .catch(() => {
        // Still offline — the item stays at the front and retries when online.
      });
  }
  function setOfflineUI(offline) {
    document.body.classList.toggle('offline', offline);
    if (offlineBanner) offlineBanner.classList.toggle('hidden', !offline);
  }
  function updateOnline() {
    const off = !navigator.onLine;
    setOfflineUI(off);
    if (!off) flushSendQueue();
  }
  window.addEventListener('online', updateOnline);
  window.addEventListener('offline', updateOnline);
  updateOnline();

  // ── Sound alerts (channel messages + DMs) ────────────────────────────────
  // Data comes from data-sounds / data-sound-prefs / data-sound-overrides:
  //   sounds[id]    {name, url}
  //   dm/channel    sound id for that context (null = muted)
  //   overrides[uid] sound id for that sender (null = muted, absent = default)
  const SOUND_DATA = { sounds: {}, dm: null, channel: null, overrides: {} };
  try {
    SOUND_DATA.sounds = JSON.parse(body.dataset.sounds || '{}');
    const sp = JSON.parse(body.dataset.soundPrefs || '{}');
    SOUND_DATA.dm = sp.dm_sound_id || null;
    SOUND_DATA.channel = sp.channel_sound_id || null;
    SOUND_DATA.overrides = JSON.parse(body.dataset.soundOverrides || '{}');
  } catch (e) {}

  const audioCache = {};
  // Sounds play through the Web Audio API: HTMLMediaElement output is
  // throttled/suspended in background tabs, while AudioContext keeps playing
  // once it has been unlocked by a user gesture. Decoded buffers are cached.
  const bufferCache = {};
  let audioCtx = null;
  function ensureCtx() {
    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (Ctor) {
      if (!audioCtx) {
        try { audioCtx = new Ctor(); } catch (e) { audioCtx = null; }
      }
      if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume().catch(() => {});
    }
    return audioCtx;
  }
  function startBuffer(buffer) {
    const ctx = ensureCtx();
    if (!ctx) return;
    try {
      const src = ctx.createBufferSource();
      src.buffer = buffer;
      src.connect(ctx.destination);
      src.start(0);
    } catch (e) {}
  }
  function playSound(id) {
    const s = SOUND_DATA.sounds[id];
    if (!s) return;
    const ctx = ensureCtx();
    if (!ctx) { htmlPlaySound(s.url); return; }
    if (bufferCache[s.url]) { startBuffer(bufferCache[s.url]); return; }
    fetch(s.url)
      .then((r) => { if (!r.ok) throw new Error('load'); return r.arrayBuffer(); })
      .then((buf) => ctx.decodeAudioData(buf))
      .then((buffer) => { bufferCache[s.url] = buffer; startBuffer(buffer); })
      .catch(() => htmlPlaySound(s.url));
  }
  // Fallback for browsers without the Web Audio API (or undecodable files).
  function htmlPlaySound(url) {
    let a = audioCache[url];
    if (!a) {
      a = audioCache[url] = new Audio(url);
      a.preload = 'auto';
    }
    a.currentTime = 0;
    const p = a.play();
    if (p) p.catch(() => {});
  }
  // A sender's override wins; otherwise use the context default (null = off).
  function resolveSound(senderUserId, contextDefault) {
    if (senderUserId && Object.prototype.hasOwnProperty.call(SOUND_DATA.overrides, senderUserId)) {
      return SOUND_DATA.overrides[senderUserId];
    }
    return contextDefault;
  }
  // Browsers only allow audio after a user gesture; prime the pipeline once so
  // poll-driven plays work the moment the user clicks/types anywhere.
  function primeAudio() {
    try { ensureCtx(); } catch (e) {}
    // Also unlock the HTML-audio fallback path.
    try { const a = new Audio(); a.volume = 0; a.play().catch(() => {}); } catch (e) {}
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => {
    document.addEventListener(ev, primeAudio, { once: true, passive: true });
  });
  // Gestures only fire while the tab is focused, so re-arm whenever the tab
  // comes back into view — otherwise a backgrounded tab never unlocks audio.
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) primeAudio();
  });

  // Background-channel messages: the server returns new real messages in every
  // member channel except the one being viewed, using our global watermark.
  // The first payload seeds the watermark silently (no sounds for pre-existing
  // unread mail); `bgSeen` dedupes against SSE, whose query string is fixed.
  let bgLast = parseInt(body.dataset.bgLast || '0', 10) || 0;
  let bgSeeded = false;
  const bgSeen = new Set();
  function handleBgMessages(list) {
    if (!Array.isArray(list)) return;
    let maxId = bgLast;
    list.forEach((m) => {
      const id = parseInt(m.id, 10);
      if (!id || bgSeen.has(id)) return;
      bgSeen.add(id);
      if (id > maxId) maxId = id;
      if (!bgSeeded) return;
      if (parseInt(m.sender_id, 10) === MY_ID) return;
      if (m.username && m.username.toLowerCase() === MY_NICK) return;
      const sid = resolveSound(parseInt(m.sender_id, 10) || null, SOUND_DATA.channel);
      if (sid) playSound(sid);
    });
    if (maxId > bgLast) bgLast = maxId;
    bgSeeded = true;
  }

  // @mention pings in the currently-open channel (same dedupe/seed pattern).
  let mentionSeeded = false;
  const mentionSeen = new Set();
  function handleMentions(list) {
    if (!Array.isArray(list)) return;
    list.forEach((n) => {
      const id = parseInt(n.message_id, 10);
      if (!id || mentionSeen.has(id)) return;
      mentionSeen.add(id);
      if (!mentionSeeded || n.kind !== 'mention') return;
      const sid = resolveSound(parseInt(n.sender_id, 10) || null, SOUND_DATA.channel);
      if (sid) playSound(sid);
    });
    mentionSeeded = true;
  }

  // ── Polling ────────────────────────────────────────────────────────────────
  let pollFails = 0;
  // Shared handler for a realtime payload (poll response or SSE message).
  function handleRealtime(j) {
    if (!j) return;
    if (j.redirect) { window.location = j.redirect; return; }
    if (j.error) return;
    if (j.messages && j.messages.length) {
      j.messages.forEach(appendMsg);
    }
    if (j.bg_messages) handleBgMessages(j.bg_messages);
    if (j.mentions) handleMentions(j.mentions);
    if (j.presence && CHANNEL) applyPresence(j.presence);
    if (typeof j.notify_count === 'number') setBell(j.notify_count);
    if (j.dm_list) handleDmList(j.dm_list);
    if (j.channel_unread) updateChannelUnread(j.channel_unread);
    if (j.channel_presence) updateChannelPresence(j.channel_presence);
    if (j.friends) updateFriendsSidebar(j.friends, j.friend_requests || []);
  }
  function poll() {
    const q = new URLSearchParams({ since: lastId });
    if (CHANNEL) q.set('channel', CHANNEL);
    if (DM) q.set('dm', DM);
    q.set('bg_since', bgLast);
    fetch('/api/poll?' + q.toString())
      .then((r) => r.json())
      .then((j) => {
        pollFails = 0;
        handleRealtime(j);
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
    const unreadCls = d.unread ? ' font-semibold' + (isAdmin ? '' : ' text-white') : '';
    return `<a href="/app?dm=${encodeURIComponent(d.username)}"
         data-ctx-user="${esc(d.username)}"
         data-user-id="${d.user_id || d.id || ''}"
         data-guest="${d.guest ? '1' : '0'}"
         class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm ${cur ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white'} ${online ? '' : 'italic opacity-70'}">
      <span class="w-2 h-2 rounded-full ${dot}"></span>
      <span class="truncate ${nameCls}${unreadCls}">${esc(d.username)}${guestTag}</span>
      ${d.unread ? `<span class="ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center">${d.unread > 99 ? '99+' : d.unread}</span>` : ''}
      <button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button>
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
          const sid = resolveSound(parseInt(d.user_id, 10) || null, SOUND_DATA.dm);
          if (sid) playSound(sid);
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

  // ── Live channel unread badges (sidebar) ───────────────────────────────────
  let chanUnreadSig = '';
  function updateChannelUnread(list) {
    const map = {};
    list.forEach((c) => { map[c.slug] = c.unread; });
    const sig = JSON.stringify(map);
    if (sig === chanUnreadSig) return;
    chanUnreadSig = sig;
    document.querySelectorAll('a[data-ctx-channel]').forEach((a) => {
      const slug = a.dataset.ctxChannel;
      const n = map[slug] || 0;
      const isActive = a.classList.contains('bg-discord-600/50');
      const nameEl = a.querySelector('span.truncate');
      const visEl = a.querySelector('.chan-vis');
      let badge = a.querySelector('.unread-badge');
      if (n > 0) {
        if (nameEl) nameEl.classList.add('font-semibold', 'text-white');
        if (visEl) visEl.classList.remove('ml-auto');
        if (!badge) {
          badge = document.createElement('span');
          badge.className = 'unread-badge ml-auto min-w-5 h-5 px-1 rounded-full bg-red-500 text-white text-[10px] flex items-center justify-center';
          a.appendChild(badge);
        }
        badge.textContent = n > 99 ? '99+' : n;
      } else {
        if (nameEl) {
          nameEl.classList.remove('font-semibold');
          // Only drop the brightened color we added; the active link keeps its own.
          if (!isActive) nameEl.classList.remove('text-white');
        }
        if (visEl) visEl.classList.add('ml-auto');
        if (badge) badge.remove();
      }
    });
  }

  // ── Live "active chatters" count per sidebar channel ───────────────────────
  let chanPresenceSig = '';
  function updateChannelPresence(list) {
    const map = {};
    list.forEach((c) => { map[c.slug] = c.online; });
    const sig = JSON.stringify(map);
    if (sig === chanPresenceSig) return;
    chanPresenceSig = sig;
    document.querySelectorAll('a[data-ctx-channel]').forEach((a) => {
      const n = map[a.dataset.ctxChannel] || 0;
      const nameEl = a.querySelector('span.truncate');
      let el = a.querySelector('.chan-online');
      if (n > 0) {
        if (!el) {
          el = document.createElement('span');
          el.className = 'chan-online text-[10px] text-discord-400 shrink-0';
          if (nameEl) nameEl.insertAdjacentElement('afterend', el);
          else a.insertBefore(el, a.firstChild);
        }
        el.textContent = '(' + n + ')';
      } else if (el) {
        el.remove();
      }
    });
  }

  function applyPresence(list) {
    const el = document.getElementById('member-list');
    if (!el) return;
    // Group by user type. Offline guests are skipped entirely (anonymous).
    const groups = [];
    const admins = [], staff = [], helpers = [], registered = [], guests = [];
    list.forEach((m) => {
      if (m.role === 'admin') admins.push(m);
      else if (m.role === 'staff') staff.push(m);
      else if (m.role_helper) helpers.push(m);
      else if (m.guest) { if (m.is_online) guests.push(m); }
      else registered.push(m);
    });
    [['Admins', admins], ['Staff', staff], ['Helpers', helpers], ['Registered', registered], ['Guests', guests]].forEach(([label, arr]) => {
      if (arr.length) groups.push([label, arr]);
    });
    const mk = (arr) => arr.map((m) => {
      const on = !!m.is_online;
      const rs = (m.role !== 'admin' && m.role_color) ? ' style="color:' + esc(m.role_color) + '"' : '';
      const cc = m.role === 'admin' ? 'text-red-400' : (on ? (COLORS[m.level] || COLORS.normal) : 'text-discord-400');
      return `<a href="/app?dm=${encodeURIComponent(m.username)}" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ${cc}"${rs} data-username="${esc(m.username)}" data-level="${esc(m.level || 'normal')}">
        <span class="text-[10px] font-bold w-3">${SYMBOL[m.level] || ''}</span>
        ${m.avatar ? `<img src="${esc(m.avatar)}" alt="" loading="lazy" class="w-6 h-6 rounded-full object-cover">` : ''}
        <span class="truncate">${esc(m.username)}</span>${m.away ? '<span class="text-xs">💤</span>' : ''}${m.role === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>' : (m.role === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '')}</a>`;
    }).join('');
    let shown = 0;
    groups.forEach((g) => { shown += g[1].length; });
    el.innerHTML = groups.map(([label, arr], i) =>
      `<div class="px-2 ${i === 0 ? 'pt-2' : 'pt-3'} pb-2">
        <div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">${label} — ${arr.length}</div>
        ${mk(arr)}
      </div>`).join('');
    const count = document.getElementById('member-count');
    if (count) count.textContent = String(shown);
  }

  // ── Friends sidebar ───────────────────────────────────────────────────────
  const friendsSection = document.getElementById('friends-section');
  const friendCount = document.getElementById('friend-count');
  const friendBadge = document.getElementById('friend-badge');
  let friendsSig = '';

  function friendAvatar(f) {
    if (f.avatar) return '<img src="' + esc(f.avatar) + '" alt="" loading="lazy" class="w-6 h-6 rounded-full object-cover">';
    const initial = (f.username || '?').charAt(0).toUpperCase();
    return '<div class="w-6 h-6 rounded-full bg-discord-500 flex items-center justify-center text-sm font-bold text-white border border-discord-600 shrink-0">' + esc(initial) + '</div>';
  }

  function updateFriendsSidebar(friends, requests) {
    if (!friendsSection) return;
    const online = friends.filter(f => f.is_online);
    const offline = friends.filter(f => !f.is_online);
    const sig = JSON.stringify([friends.map(f => [f.id, f.is_online]), requests.map(r => r.id)]);
    if (sig === friendsSig) return;
    friendsSig = sig;
    if (friendCount) friendCount.textContent = String(friends.length);
    if (friendBadge) {
      if (requests.length) {
        friendBadge.textContent = String(requests.length);
        friendBadge.classList.remove('hidden');
      } else {
        friendBadge.classList.add('hidden');
      }
    }
    let html = '';
    if (requests.length) {
      html += '<div class="px-2 pt-2 pb-1"><div class="px-2 text-xs font-semibold text-blurple uppercase tracking-wide mb-1">Requests — ' + requests.length + '</div>';
      requests.forEach(r => {
        html += '<div class="friend-request flex items-center gap-2 px-2 py-1.5 rounded hover:bg-discord-600/40 text-sm" data-username="' + esc(r.username) + '">';
        html += friendAvatar(r);
        html += '<span class="truncate text-discord-200">' + esc(r.username) + '</span>';
        html += '<div class="ml-auto flex gap-1">';
        html += '<button type="button" class="friend-accept text-[10px] px-1.5 py-0.5 rounded bg-green-600 hover:bg-green-500 text-white">Accept</button>';
        html += '<button type="button" class="friend-decline text-[10px] px-1.5 py-0.5 rounded bg-discord-700 hover:bg-discord-600 text-discord-300">Decline</button>';
        html += '</div></div>';
      });
      html += '</div>';
    }
    if (online.length) {
      html += '<div class="px-2 pt-2 pb-1"><div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Online — ' + online.length + '</div>';
      online.forEach(f => {
        const dot = f.away ? 'bg-amber-400' : 'bg-green-500';
        html += '<a href="/app?dm=' + encodeURIComponent(f.username) + '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-200" data-ctx-user="' + esc(f.username) + '" data-user-id="' + (f.id || '') + '" data-friend="1">';
        html += '<span class="w-2 h-2 rounded-full ' + dot + '"></span>';
        html += friendAvatar(f);
        html += '<span class="truncate">' + esc(f.username) + '</span>';
        html += '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button></a>';
      });
      html += '</div>';
    }
    if (offline.length) {
      html += '<div class="px-2 pt-2 pb-1"><div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Offline — ' + offline.length + '</div>';
      offline.forEach(f => {
        html += '<a href="/app?dm=' + encodeURIComponent(f.username) + '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-400 italic opacity-70" data-ctx-user="' + esc(f.username) + '" data-user-id="' + (f.id || '') + '" data-friend="1">';
        html += '<span class="w-2 h-2 rounded-full bg-discord-500"></span>';
        html += friendAvatar(f);
        html += '<span class="truncate">' + esc(f.username) + '</span>';
        html += '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button></a>';
      });
      html += '</div>';
    }
    if (!html) html = '<div class="p-4 text-xs text-discord-500">No friends yet.</div>';
    friendsSection.innerHTML = html;
    bindFriendActions();
  }

  function bindFriendActions() {
    document.querySelectorAll('.friend-accept').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const row = btn.closest('.friend-request');
        post('/api/friend/accept', { username: row.dataset.username }, () => { friendsSig = ''; });
      });
    });
    document.querySelectorAll('.friend-decline').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const row = btn.closest('.friend-request');
        post('/api/friend/decline', { username: row.dataset.username }, () => {
          row.remove();
          friendsSig = '';
        });
      });
    });
  }

  bindFriendActions();

  // ── Bell / notifications ───────────────────────────────────────────────────
  const bell = document.getElementById('bell');
  const bellDot = document.getElementById('bell-dot');
  const notifPanel = document.getElementById('notif-panel');
  function setBell(n) {
    if (!bellDot) return;
    if (n > 0) { bellDot.classList.remove('hidden'); bellDot.textContent = n > 99 ? '99+' : n; }
    else bellDot.classList.add('hidden');
  }
  function renderNotifItem(n) {
    let link = '';
    let label = '';
    if (n.kind === 'dm') {
      link = '/app?dm=' + encodeURIComponent(n.sender || '');
      label = '<span class="text-discord-400">dm</span> from <span class="text-blurple">' + esc(n.sender || 'system') + '</span>';
    } else if (n.kind === 'friend_request') {
      link = '/u/' + encodeURIComponent(n.sender || '');
      label = '<span class="text-green-400">friend request</span> from <span class="text-blurple">' + esc(n.sender || 'someone') + '</span>';
    } else if (n.kind === 'friend_accepted') {
      link = '/u/' + encodeURIComponent(n.sender || '');
      label = '<span class="text-green-400">friend accepted</span> — <span class="text-blurple">' + esc(n.sender || 'someone') + '</span> is now your friend';
    } else {
      if (n.channel_name) {
        link = '/app?channel=' + encodeURIComponent(n.channel_name.replace(/^#/, ''));
        label = '<span class="text-discord-400">' + esc(n.kind) + '</span> → <span class="text-blurple">' + esc(n.channel_name) + '</span>';
      } else {
        label = '<span class="text-discord-400">' + esc(n.kind) + '</span>';
      }
      if (n.sender) label += ' <span class="text-discord-400">from</span> ' + esc(n.sender);
    }
    const time = n.created_at ? '<span class="text-[10px] text-discord-500 ml-auto shrink-0">' + esc(n.created_at) + '</span>' : '';
    return `<div class="notif-item group flex items-center gap-2 px-2 py-2 rounded hover:bg-discord-750 text-discord-300 cursor-pointer" data-id="${n.id}" data-link="${esc(link)}">
      <div class="flex-1 min-w-0 truncate">${label}</div>
      ${time}
      <button class="notif-dismiss opacity-0 group-hover:opacity-100 text-discord-500 hover:text-red-400 text-xs px-1 shrink-0" data-id="${n.id}" title="Dismiss">&times;</button>
    </div>`;
  }
  function loadNotifications() {
    const list = document.getElementById('notif-list');
    if (!list) return;
    fetch('/api/notifications').then((r) => r.json()).then((j) => {
      if (!j.notifications || !j.notifications.length) {
        list.innerHTML = '<div class="px-2 py-3 text-discord-500 text-center">Nothing new</div>';
        return;
      }
      list.innerHTML = j.notifications.map(renderNotifItem).join('');
      list.querySelectorAll('.notif-item').forEach((el) => {
        el.addEventListener('click', (e) => {
          if (e.target.closest('.notif-dismiss')) return;
          const link = el.dataset.link;
          if (link) window.location.href = link;
        });
      });
      list.querySelectorAll('.notif-dismiss').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          const id = btn.dataset.id;
          post('/api/notifications/dismiss', { id }, (j) => {
            const item = btn.closest('.notif-item');
            if (item) item.remove();
            setBell(j.notify_count || 0);
            const remaining = document.querySelectorAll('.notif-item');
            if (!remaining.length) {
              document.getElementById('notif-list').innerHTML = '<div class="px-2 py-3 text-discord-500 text-center">Nothing new</div>';
            }
          });
        });
      });
    });
  }
  function positionNotifPanel() {
    if (!bell || !notifPanel) return;
    const r = bell.getBoundingClientRect();
    const pw = Math.min(384, window.innerWidth - 32);
    let left = r.right - pw;
    if (left < 8) left = 8;
    if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
    const top = r.bottom + 4;
    notifPanel.style.top = top + 'px';
    notifPanel.style.left = left + 'px';
    notifPanel.style.width = pw + 'px';
  }
  if (bell) bell.addEventListener('click', () => {
    const opening = notifPanel.classList.contains('hidden');
    notifPanel.classList.toggle('hidden');
    if (opening) {
      positionNotifPanel();
      loadNotifications();
    }
  });
  window.addEventListener('resize', () => {
    if (notifPanel && !notifPanel.classList.contains('hidden')) positionNotifPanel();
  });
  document.addEventListener('click', (e) => {
    if (!notifPanel || notifPanel.classList.contains('hidden')) return;
    if (notifPanel.contains(e.target) || (bell && bell.contains(e.target))) return;
    notifPanel.classList.add('hidden');
  });
  const notifClear = document.getElementById('notif-clear');
  if (notifClear) notifClear.addEventListener('click', () => {
    post('/api/notifications/read', {}, () => {
      setBell(0);
      const list = document.getElementById('notif-list');
      if (list) list.innerHTML = '<div class="px-2 py-3 text-discord-500 text-center">Nothing new</div>';
    });
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
      const payload = DM
        ? { recipient: DM, content: text }
        : { channel: CHANNEL, content: text, reply_to: pendingReplyId || '' };
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
        clearPendingReply();
      }, () => {
        clearPendingReply();
        enqueueSend(payload);
      });
    }
  });

  // Multi-line composer: Enter sends, Shift+Enter inserts a newline, and the
  // box auto-grows up to a few lines then scrolls.
  function autosizeInput() {
    if (!input) return;
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  }
  if (input && input.tagName === 'TEXTAREA') {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        form.requestSubmit();
      }
    });
    input.addEventListener('input', autosizeInput);
    autosizeInput();
  }

  function showReply(text) {
    if (!msgsEl) return;
    msgsEl.insertAdjacentHTML('beforeend',
      `<div class="msg-system px-4 py-1 text-xs text-sky-400 italic text-center select-none">${linkify(text)}</div>`);
    maybeScroll();
  }

  // ── Reply-to chip (set from the message context menu) ─────────────────────
  const replyChip = document.getElementById('reply-chip');
  const replyChipName = document.getElementById('reply-chip-name');
  const replyChipExcerpt = document.getElementById('reply-chip-excerpt');
  const replyToInput = document.getElementById('reply-to-input');
  let pendingReplyId = 0;
  function setPendingReply(id, username, excerpt) {
    pendingReplyId = parseInt(id, 10) || 0;
    if (!replyChip || !replyChipName) return;
    if (!pendingReplyId) { replyChip.classList.add('hidden'); if (replyToInput) replyToInput.value = ''; return; }
    replyChipName.textContent = username || '';
    replyChipExcerpt.textContent = excerpt || '';
    replyChip.classList.remove('hidden');
    if (replyToInput) replyToInput.value = String(pendingReplyId);
    input.focus();
  }
  function clearPendingReply() {
    pendingReplyId = 0;
    if (replyChip) replyChip.classList.add('hidden');
    if (replyToInput) replyToInput.value = '';
  }
  const replyCancel = document.getElementById('reply-cancel');
  if (replyCancel) replyCancel.addEventListener('click', clearPendingReply);
  document.addEventListener('click', (e) => {
    const link = e.target.closest('[data-reply-scroll]');
    if (!link) return;
    e.preventDefault();
    const target = document.querySelector('.msg[data-id="' + link.dataset.replyScroll + '"]');
    if (target) {
      target.scrollIntoView({ block: 'center' });
      target.classList.add('reply-highlight');
      setTimeout(() => target.classList.remove('reply-highlight'), 2000);
    }
  });

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

  // ── GIF picker (Giphy, proxied through the server so the key stays private) ─
  const gifBtn = document.getElementById('gif-btn');
  const gifPanel = document.getElementById('gif-panel');
  const gifGrid = document.getElementById('gif-grid');
  const gifSearch = document.getElementById('gif-search');
  const gifMore = document.getElementById('gif-more');
  const gifStatus = document.getElementById('gif-status');
  let gifOffset = 0;
  let gifQuery = '';
  let gifLoading = false;

  function gifSetStatus(t) {
    if (!gifStatus) return;
    if (t) { gifStatus.textContent = t; gifStatus.classList.remove('hidden'); }
    else gifStatus.classList.add('hidden');
  }
  function gifClose() { if (gifPanel) gifPanel.classList.add('hidden'); }
  function loadGifs(reset) {
    if (!gifGrid || gifLoading) return;
    gifLoading = true;
    const q = new URLSearchParams({ limit: '24' });
    if (gifQuery) q.set('q', gifQuery);
    if (!reset && gifOffset) q.set('offset', gifOffset);
    if (gifMore) gifMore.classList.add('hidden');
    gifSetStatus(gifQuery ? 'Searching…' : 'Loading…');
    fetch('/api/gifs?' + q.toString())
      .then((r) => r.json().catch(() => ({ error: 'Server error' })))
      .then((j) => {
        gifLoading = false;
        if (!j.ok) {
          if (gifGrid) gifGrid.innerHTML = '';
          gifSetStatus(j.error || 'GIF search unavailable.');
          return;
        }
        const items = j.gifs || [];
        if (gifGrid) {
          // Uniform square tiles, guaranteed in every browser: the button's
          // height collapses to 0 and a bottom padding of 100% of its width
          // stretches it into an exact square (box-sizing:border-box). The
          // preview is absolutely positioned to fill it. No aspect-ratio or
          // parent-height chains involved, and images load eagerly so every
          // tile fills in immediately.
          const html = items.map((g) =>
            `<button type="button" class="gif-item w-full relative rounded-md overflow-hidden border border-discord-700 hover:border-blurple/60 transition-colors bg-discord-850" style="box-sizing:border-box;height:0;padding:0 0 100% 0" data-url="${esc(g.url)}" data-title="${esc(g.title)}" title="${esc(g.title || 'GIF')}">
              <img src="${esc(g.preview)}" alt="${esc(g.title || 'GIF')}" class="absolute inset-0 w-full h-full object-cover">
            </button>`).join('');
          if (reset) gifGrid.innerHTML = html;
          else gifGrid.insertAdjacentHTML('beforeend', html);
        }
        gifOffset = parseInt(j.next || '0', 10) || 0;
        if (gifMore) {
          if (items.length && gifOffset) gifMore.classList.remove('hidden');
          else gifMore.classList.add('hidden');
        }
        gifSetStatus(items.length ? '' : (gifQuery ? 'No GIFs found for “' + gifQuery + '”.' : 'No trending GIFs right now.'));
        bindGifItems();
      })
      .catch(() => {
        gifLoading = false;
        gifSetStatus('GIF search failed. Try again.');
      });
  }
  function bindGifItems() {
    if (!gifGrid) return;
    gifGrid.querySelectorAll('.gif-item').forEach((b) => {
      if (b.dataset.bound) return;
      b.dataset.bound = '1';
      b.addEventListener('click', () => {
        const url = b.dataset.url;
        if (!url) return;
        const payload = DM
          ? { recipient: DM, gif_url: url, gif_title: b.dataset.title || '' }
          : { channel: CHANNEL, gif_url: url, gif_title: b.dataset.title || '' };
        post('/api/send', payload, (j) => {
          if (j.message) appendMsg(j.message);
          gifClose();
        }, () => {
          gifClose();
          enqueueSend(payload);
        });
      });
    });
  }
  if (gifBtn) gifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (gifPanel.classList.contains('hidden')) {
      gifPanel.classList.remove('hidden');
      if (!gifGrid.children.length) loadGifs(true);
      if (gifSearch) gifSearch.focus();
    } else {
      gifClose();
    }
  });
  const gifCloseBtn = document.getElementById('gif-close');
  if (gifCloseBtn) gifCloseBtn.addEventListener('click', gifClose);
  if (gifMore) gifMore.addEventListener('click', () => loadGifs(false));
  if (gifSearch) {
    let gifTimer = null;
    gifSearch.addEventListener('input', () => {
      clearTimeout(gifTimer);
      gifTimer = setTimeout(() => { gifQuery = gifSearch.value.trim(); loadGifs(true); }, 350);
    });
    gifSearch.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); gifQuery = gifSearch.value.trim(); loadGifs(true); }
      else if (e.key === 'Escape') gifClose();
    });
  }
  document.addEventListener('click', (e) => {
    if (e.target.closest('#gif-panel') || e.target.closest('#gif-btn')) return;
    gifClose();
  });

  // ── Image upload (composer 📎) ─────────────────────────────────────────────
  const uploadBtn = document.getElementById('upload-btn');
  const uploadFile = document.getElementById('upload-file');
  if (uploadBtn && uploadFile && (CHANNEL || DM)) {
    uploadBtn.addEventListener('click', () => uploadFile.click());
    uploadFile.addEventListener('change', () => {
      const file = uploadFile.files && uploadFile.files[0];
      if (!file) return;
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('ajax', '1');
      if (DM) fd.append('dm', DM); else fd.append('channel', CHANNEL);
      fd.append('file', file);
      uploadBtn.textContent = '⏳';
      fetch('/api/upload', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
        .then((r) => r.json().catch(() => ({ error: 'Server error' })))
        .then((j) => {
          uploadBtn.textContent = '📎';
          uploadFile.value = '';
          if (j.error) { alert(j.error); return; }
          if (j.message) appendMsg(j.message);
        })
        .catch(() => { uploadBtn.textContent = '📎'; alert('Upload failed.'); });
    });
    // Drag & drop an image onto the timeline.
    document.addEventListener('dragover', (e) => { if (e.dataTransfer && e.dataTransfer.types.indexOf('Files') !== -1) e.preventDefault(); });
    document.addEventListener('drop', (e) => {
      if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
      const f = e.dataTransfer.files[0];
      if (f.type && f.type.indexOf('image/') === -1) return;
      e.preventDefault();
      const dt = new DataTransfer();
      dt.items.add(f);
      uploadFile.files = dt.files;
      uploadFile.dispatchEvent(new Event('change'));
    });
    // Paste an image from the clipboard (screenshots, copied images).
    input.addEventListener('paste', (e) => {
      const items = e.clipboardData && e.clipboardData.items;
      if (!items) return;
      for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type && items[i].type.indexOf('image/') === 0) {
          const f = items[i].getAsFile();
          if (!f) continue;
          e.preventDefault();
          const dt = new DataTransfer();
          dt.items.add(f);
          uploadFile.files = dt.files;
          uploadFile.dispatchEvent(new Event('change'));
          break;
        }
      }
    });
  }

  // ── Image lightbox ─────────────────────────────────────────────────────────
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightbox-img');
  function openLightbox(src) {
    if (!lightbox || !lightboxImg) return;
    lightboxImg.src = src;
    lightbox.classList.remove('hidden');
  }
  function closeLightbox() { if (lightbox) lightbox.classList.add('hidden'); }
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-lightbox]');
    if (t) { e.preventDefault(); openLightbox(t.getAttribute('href') || t.dataset.lightbox); }
  });
  if (lightbox) {
    lightbox.querySelectorAll('[data-lightbox-close]').forEach((el) => el.addEventListener('click', closeLightbox));
    lightbox.addEventListener('click', (e) => { if (e.target === lightbox) closeLightbox(); });
  }

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
    try {
      if (!isMobile() && localStorage.getItem('lvc.sidebar') === '0') {
        document.body.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
  })();

  // ── Right panel toggle (desktop collapse + mobile drawer) ─────────────────
  (function () {
    const btn = document.getElementById('right-panel-toggle');
    const mobBtn = document.getElementById('right-panel-btn-m');
    const backdrop = document.getElementById('right-panel-backdrop');
    const isMobile = () => window.innerWidth < 768;
    function closeRight() {
      document.body.classList.remove('right-open');
      if (backdrop) backdrop.classList.add('hidden');
    }
    function openRight() {
      document.body.classList.add('right-open');
      if (backdrop) backdrop.classList.remove('hidden');
    }
    if (btn) btn.addEventListener('click', () => {
      if (isMobile()) {
        if (document.body.classList.contains('right-open')) closeRight();
        else openRight();
      } else {
        const collapsed = document.body.classList.toggle('right-collapsed');
        try { localStorage.setItem('lvc.rightPanel', collapsed ? '0' : '1'); } catch (e) {}
      }
    });
    if (mobBtn) mobBtn.addEventListener('click', () => {
      if (document.body.classList.contains('right-open')) closeRight();
      else openRight();
    });
    if (backdrop) backdrop.addEventListener('click', closeRight);
    try {
      if (!isMobile() && localStorage.getItem('lvc.rightPanel') === '0') {
        document.body.classList.add('right-collapsed');
      }
    } catch (e) {}
  })();

  // ── Mobile context menu buttons on messages ────────────────────────────────
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.msg-ctx-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const msg = btn.closest('.msg[data-id]');
    if (msg) {
      const rect = btn.getBoundingClientRect();
      msgMenu(rect.left, rect.bottom + 4, msg);
    }
  });

  // ── Mobile context menu buttons on sidebar items ───────────────────────────
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('.ctx-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    const parent = btn.closest('[data-ctx-channel],[data-ctx-user],.member[data-username]');
    if (!parent) return;
    const rect = btn.getBoundingClientRect();
    const x = rect.left;
    const y = rect.bottom + 4;
    const ch = parent.closest('[data-ctx-channel]');
    if (ch) { channelMenu(x, y, ch); return; }
    const u = parent.closest('[data-ctx-user],.member[data-username]');
    if (u) { userMenu(x, y, u); }
  });

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
  const muteBtn = document.getElementById('mute-btn');
  if (muteBtn) {
    muteBtn.addEventListener('click', () => {
      const order = ['all', 'mentions', 'muted'];
      const cur = muteBtn.dataset.mode || 'all';
      const next = order[(order.indexOf(cur) + 1) % order.length];
      post('/api/channel/notify', { channel: CHANNEL, mode: next }, (j) => {
        if (j.mode) {
          muteBtn.dataset.mode = j.mode;
          if (j.mode === 'muted') { muteBtn.textContent = '🔕 Muted'; muteBtn.title = 'Unmute channel'; }
          else if (j.mode === 'mentions') { muteBtn.textContent = '🔔 Mentions'; muteBtn.title = 'Notification mode: mentions only'; }
          else { muteBtn.textContent = '🔔'; muteBtn.title = 'Mute channel'; }
        }
      });
    });
  }
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
    const isGuest = el.dataset.guest === '1';
    const level = el.dataset.level || '';
    const items = [
      { label: 'Message ' + nick, onClick: () => { window.location = '/app?dm=' + encodeURIComponent(nick); } },
      { label: 'View profile', onClick: () => {
        if (isGuest) {
          showGuestProfileModal(nick);
        } else {
          window.location = '/u/' + encodeURIComponent(nick);
        }
      } },
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
      const isFriend = el.dataset.friend === '1';
      if (!isGuest && !isFriend) {
        items.push({ label: 'Add friend', onClick: () => post('/api/friend/request', { username: nick }, () => showReply('Friend request sent.')) });
      }
      items.push({ label: 'Mute ' + nick, onClick: () => {
        const uid = el.dataset.userId || el.closest('[data-user-id]')?.dataset.userId || '';
        if (uid && parseInt(uid, 10) > 0) {
          post('/api/sound/override', { target_user_id: uid, sound: '0' }, () => showReply('Muted ' + nick + '.'));
        } else {
          runCommand('/ignore ' + nick);
        }
      } });
      items.push({ label: 'Block ' + nick, danger: true, onClick: () => post('/api/friend/block', { username: nick }, () => showReply(nick + ' has been blocked.')) });
    }
    ctxShow(x, y, items);
  }

  function msgMenu(x, y, el) {
    const id = el.dataset.id;
    const author = el.dataset.author;
    const isGuestMsg = el.dataset.guest === '1';
    const contentEl = el.querySelector('.msg-content');
    const content = contentEl ? contentEl.textContent : '';
    const contentHtml = contentEl ? contentEl.innerHTML : '';
    const items = [];
    if (author) {
      items.push({ label: 'Reply', onClick: () => { setPendingReply(id, author, content.slice(0, 80)); if (!CHANNEL) { input.value = '@' + author + ' '; } } });
    }
    if (content) items.push({ label: 'Copy text', onClick: () => copyText(content) });
    const kind = el.dataset.kind || '';
    if (kind === 'message' || kind === 'image' || kind === 'gif') {
      items.push({ label: 'Report message', onClick: () => openReport(id, el.dataset.isPm === '1', contentHtml) });
    }
    const mine = author && author.toLowerCase() === MY_NICK;
    if (CAN_ADMIN || mine) {
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
      if (!isGuestMsg) {
        items.push({ div: true });
        items.push({ label: 'Add friend', onClick: () => post('/api/friend/request', { username: author }, () => showReply('Friend request sent.')) });
      }
      items.push({ label: 'Block ' + author, danger: true, onClick: () => post('/api/friend/block', { username: author }, () => showReply(author + ' has been blocked.')) });
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

  // ── Guest profile modal ───────────────────────────────────────────────────
  const guestModal = document.getElementById('guest-profile-modal');
  const guestModalAvatar = document.getElementById('guest-profile-avatar');
  const guestModalName = document.getElementById('guest-profile-name');
  function showGuestProfileModal(nick) {
    if (!guestModal) return;
    const initial = (nick || '?').charAt(0).toUpperCase();
    if (guestModalAvatar) guestModalAvatar.textContent = initial;
    if (guestModalName) guestModalName.textContent = nick;
    guestModal.classList.remove('hidden');
  }
  function closeGuestModal() { if (guestModal) guestModal.classList.add('hidden'); }
  if (guestModal) {
    guestModal.querySelectorAll('[data-guest-modal-close]').forEach((el) => el.addEventListener('click', closeGuestModal));
    guestModal.addEventListener('click', (e) => { if (e.target === guestModal) closeGuestModal(); });
  }

  // ── Report message modal ───────────────────────────────────────────────────
  const reportModal = document.getElementById('report-modal');
  const reportQuote = document.getElementById('report-quote');
  const reportOther = document.getElementById('report-other');
  const reportError = document.getElementById('report-error');
  const reportSubmit = document.getElementById('report-submit');
  let reportTarget = null;

  function openReport(id, isPm, html) {
    if (!reportModal) return;
    reportTarget = { id: parseInt(id, 10) || 0, pm: !!isPm };
    // The quote reuses the message's already-escaped rendered markup, so an
    // image/GIF report shows the picture inline instead of its raw URL.
    if (reportQuote) reportQuote.innerHTML = html || '(no text)';
    if (reportOther) { reportOther.value = ''; reportOther.classList.add('hidden'); }
    if (reportError) reportError.classList.add('hidden');
    document.querySelectorAll('#report-reasons input[name="report_reason"]').forEach((r) => {
      r.checked = r.value === 'Harassment / Bullying';
    });
    reportModal.classList.remove('hidden');
  }
  function closeReport() { if (reportModal) reportModal.classList.add('hidden'); }

  document.querySelectorAll('#report-reasons input[name="report_reason"]').forEach((r) => {
    r.addEventListener('change', () => {
      if (reportOther) reportOther.classList.toggle('hidden', r.value !== 'Other');
      if (reportError) reportError.classList.add('hidden');
    });
  });
  if (reportModal) {
    reportModal.querySelectorAll('[data-report-close]').forEach((el) => el.addEventListener('click', closeReport));
    reportModal.addEventListener('click', (e) => { if (e.target === reportModal) closeReport(); });
  }
  if (reportSubmit) reportSubmit.addEventListener('click', () => {
    if (!reportTarget || !reportTarget.id) return;
    const checked = document.querySelector('#report-reasons input[name="report_reason"]:checked');
    const reason = checked ? checked.value : '';
    const other = reportOther ? reportOther.value.trim() : '';
    if (reason === 'Other' && !other) {
      if (reportError) { reportError.textContent = 'Please describe the issue.'; reportError.classList.remove('hidden'); }
      return;
    }
    post('/api/report', { id: reportTarget.id, pm: reportTarget.pm ? '1' : '0', reason, other }, () => {
      closeReport();
      showReply('Report submitted. Thanks — staff will review it.');
    });
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

  // ── How to install modal (PWA) ─────────────────────────────────────────────
  // Always offers the step-by-step guide; additionally, when the browser is
  // installable (Chrome/Edge fire `beforeinstallprompt`) an "Install now" CTA
  // appears at the top that triggers the native install flow.
  const installModal = document.getElementById('install-modal');
  const installNow = document.getElementById('install-now');
  let deferredInstall = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredInstall = e;
    if (installNow) installNow.classList.remove('hidden');
  });
  if (installNow) installNow.addEventListener('click', () => {
    if (!deferredInstall) return;
    deferredInstall.prompt();
    deferredInstall.userChoice.then((choice) => {
      if (choice.outcome === 'accepted' && installModal) installModal.classList.add('hidden');
      deferredInstall = null;
      if (installNow) installNow.classList.add('hidden');
    }).catch(() => {});
  });
  window.addEventListener('appinstalled', () => {
    deferredInstall = null;
    if (installNow) installNow.classList.add('hidden');
    if (installModal) installModal.classList.add('hidden');
  });
  const installBtn = document.getElementById('install-btn');
  if (installBtn) installBtn.addEventListener('click', () => { if (installModal) installModal.classList.remove('hidden'); });
  if (installModal) {
    installModal.querySelectorAll('[data-install-close]').forEach((el) => el.addEventListener('click', () => installModal.classList.add('hidden')));
    installModal.addEventListener('click', (e) => { if (e.target === installModal) installModal.classList.add('hidden'); });
  }

  // ── Header overflow dropdown (mobile) ───────────────────────────────────────
  // On small screens the individual header buttons (Share, Mute, Embed, etc.)
  // are hidden and replaced by a ⋮ dropdown that holds them plus the theme
  // toggle (which is also hidden on mobile in the sidebar).
  const hdrBtn = document.getElementById('header-menu-btn');
  const hdrMenu = document.getElementById('header-menu');
  function closeHdr() { if (hdrMenu) hdrMenu.classList.add('hidden'); }
  if (hdrBtn) hdrBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    if (hdrMenu) hdrMenu.classList.toggle('hidden');
  });
  document.addEventListener('click', (e) => { if (!e.target.closest('#header-menu-wrap')) closeHdr(); });
  // Every item in the menu closes the dropdown once acted on.
  if (hdrMenu) hdrMenu.addEventListener('click', (e) => {
    if (e.target.closest('.dropdown-close')) closeHdr();
  });

  // Mobile search: reveal the (hidden) search input inside the header and
  // focus it so the user can start typing immediately.
  const mobileSearchBtn = document.getElementById('mobile-search');
  if (mobileSearchBtn) mobileSearchBtn.addEventListener('click', () => {
    const si = document.getElementById('search-input');
    if (si) { si.classList.add('search-open'); si.focus(); }
  });
  // Close the mobile search on Escape or when the search-results panel closes.
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    const si = document.getElementById('search-input');
    if (si && si.classList.contains('search-open')) si.classList.remove('search-open');
  });

  // Header theme toggle (mobile dropdown).
  const hdrTheme = document.getElementById('header-theme-toggle');
  if (hdrTheme) hdrTheme.addEventListener('click', () => {
    const light = document.documentElement.classList.toggle('light');
    const theme = light ? 'light' : 'dark';
    try { localStorage.setItem('lvc.theme', theme); } catch (e) {}
    setThemeIcon();
    post('/api/profile', { theme }, () => {});
  });

  // ── Mute button (mobile menu copy) ────────────────────────────────────────
  const muteBtnM = document.getElementById('mute-btn-m');
  if (muteBtnM) {
    // Sync state from the main mute button.
    const syncMuteM = () => {
      const m = muteBtn && muteBtn.dataset.mode;
      if (m) muteBtnM.dataset.mode = m;
      muteBtnM.textContent = m === 'muted' ? '🔕 Unmute' : m === 'mentions' ? '🔔 Mentions only' : '🔕 Mute';
    };
    syncMuteM();
    muteBtnM.addEventListener('click', () => {
      const order = ['all', 'mentions', 'muted'];
      const cur = muteBtnM.dataset.mode || 'all';
      const next = order[(order.indexOf(cur) + 1) % order.length];
      if (CHANNEL) {
        post('/api/channel/notify', { channel: CHANNEL, mode: next }, (j) => {
          if (j.mode) {
            muteBtnM.dataset.mode = j.mode;
            if (muteBtn) { muteBtn.dataset.mode = j.mode; syncMuteM(); }
          }
        });
      }
    });
  }

  // Share / Part / Install / Create / Browse (mobile menu mirrors).
  const shareBtnM = document.getElementById('share-btn-m');
  if (shareBtnM) shareBtnM.addEventListener('click', () => {
    if (shareBtn) shareBtn.click();
    else if (CHANNEL) {
      const url = window.location.origin + '/c/' + encodeURIComponent(CHANNEL);
      copyText(url, (ok) => { showReply(ok ? 'Link copied.' : 'Copy failed.'); });
    }
  });
  const partBtnM = document.getElementById('part-btn-m');
  if (partBtnM) partBtnM.addEventListener('click', () => { if (partBtn) partBtn.click(); });
  const installBtnM = document.getElementById('install-btn-m');
  if (installBtnM) installBtnM.addEventListener('click', () => { if (installModal) installModal.classList.remove('hidden'); });

  // ── DM header menu: profile, mute, block ──────────────────────────────────
  const dmProfileBtn = document.getElementById('dm-profile-btn');
  if (dmProfileBtn) dmProfileBtn.addEventListener('click', () => {
    const nick = dmProfileBtn.dataset.username;
    if (nick) window.location = '/u/' + encodeURIComponent(nick);
  });
  const dmMuteBtn = document.getElementById('dm-mute-btn');
  if (dmMuteBtn) dmMuteBtn.addEventListener('click', () => {
    const uid = parseInt(dmMuteBtn.dataset.userId || '0', 10);
    if (!uid) return;
    post('/api/sound/override', { target_user_id: uid, sound: '0' }, () => showReply('Muted.'));
  });
  const dmBlockBtn = document.getElementById('dm-block-btn');
  if (dmBlockBtn) dmBlockBtn.addEventListener('click', () => {
    const nick = dmBlockBtn.dataset.username;
    if (nick && confirm('Block ' + nick + '?')) {
      post('/api/friend/block', { username: nick }, () => showReply(nick + ' has been blocked.'));
    }
  });

  // ── Load earlier messages (pagination) ───────────────────────────────────
  // Shared: render a page of OLDER messages (ascending id order) as one block
  // and insert it above the first visible message, anchored so the newest row
  // stays put on screen.
  function prependOlder(rows) {
    const anchorRel = msgsEl.scrollHeight - msgsEl.scrollTop;
    let html = '';
    let prev = null;
    let prevDate = '';
    rows.forEach((m) => {
      const date = String(m.created_at || '').slice(0, 10);
      if (date && date !== prevDate) {
        html += dividerHtml(date);
        prevDate = date;
        prev = null;
      }
      html += msgHtml(m, shouldGroup(prev, m));
      prev = m;
    });
    const anchor = msgsEl.querySelector('.msg[data-id]');
    if (anchor) anchor.insertAdjacentHTML('beforebegin', html);
    else msgsEl.insertAdjacentHTML('afterbegin', html);
    bindMessageActions();
    msgsEl.scrollTop = msgsEl.scrollHeight - anchorRel;
  }

  const loadBtn = document.getElementById('load-earlier');
  if (loadBtn && (CHANNEL || DM)) {
    let oldestId = 0;
    const first = msgsEl ? msgsEl.querySelector('.msg[data-id]') : null;
    if (first) oldestId = parseInt(first.dataset.id, 10);
    // If the server-rendered history is a full page, offer more.
    if (oldestId > 0) loadBtn.classList.remove('hidden');

    loadBtn.addEventListener('click', () => {
      if (loadBtn.dataset.loading) return;
      loadBtn.dataset.loading = '1';
      const q = new URLSearchParams({ before: oldestId });
      if (CHANNEL) q.set('channel', CHANNEL);
      if (DM) q.set('dm', DM);
      fetch('/api/history?' + q.toString())
        .then((r) => r.json())
        .then((j) => {
          loadBtn.dataset.loading = '';
          if (!j.messages || !j.messages.length) {
            loadBtn.textContent = 'No earlier messages';
            loadBtn.classList.add('opacity-40');
            return;
          }
          prependOlder(j.messages);
          oldestId = j.messages[0].id;
          if (j.messages.length < 50) {
            loadBtn.textContent = 'No earlier messages';
            loadBtn.classList.add('opacity-40');
          }
        })
        .catch(() => { loadBtn.dataset.loading = ''; });
    });
  }

  // ── Search box (header) ────────────────────────────────────────────────────
  const searchInput = document.getElementById('search-input');
  const searchResults = document.getElementById('search-results');
  let searchTimer = null;
  function showSearchResults(j) {
    if (!searchResults) return;
    const chan = (j && j.results && j.results.channels) || [];
    const dms = (j && j.results && j.results.dms) || [];
    const renderRow = (r, isDm) => {
      const where = isDm ? ('/app?dm=' + encodeURIComponent(r.username)) : ('/app?channel=' + encodeURIComponent(r.channel_slug) + '&jump=' + r.id);
      const label = isDm ? ('💬 ' + esc(r.username)) : ('#' + esc(r.channel_slug || '?'));
      return `<a href="${where}" class="block px-3 py-2 hover:bg-discord-750 text-sm">
        <span class="font-semibold text-blurple">${label}</span>
        <span class="text-discord-400">· ${esc(r.username)}</span>
        <div class="text-discord-300 text-xs mt-0.5 line-clamp-2 break-words">${linkify(esc(r.snippet || ''))}</div>
      </a>`;
    };
    let html = '';
    if (chan.length) html += `<div class="px-3 pt-2 text-[10px] font-bold uppercase tracking-wide text-discord-400">Channels</div>` + chan.map((r) => renderRow(r, false)).join('');
    if (dms.length) html += `<div class="px-3 pt-2 text-[10px] font-bold uppercase tracking-wide text-discord-400">Private messages</div>` + dms.map((r) => renderRow(r, true)).join('');
    if (!html) html = `<div class="px-3 py-3 text-xs text-discord-500">No matches.</div>`;
    searchResults.innerHTML = html;
    searchResults.classList.remove('hidden');
  }
  function hideSearchResults() {
    if (searchResults) searchResults.classList.add('hidden');
    // On mobile the search input itself was revealed via the search-open class;
    // close it again once the results panel is dismissed.
    if (searchInput) searchInput.classList.remove('search-open');
  }
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const term = searchInput.value.trim();
      if (!term) { hideSearchResults(); return; }
      searchTimer = setTimeout(() => {
        fetch('/api/search?q=' + encodeURIComponent(term))
          .then((r) => r.json())
          .then((j) => { if (searchInput.value.trim()) showSearchResults(j); })
          .catch(() => {});
      }, 250);
    });
    document.addEventListener('click', (e) => {
      if (!e.target.closest('#search-input') && !e.target.closest('#search-results')) hideSearchResults();
    });
  }

  // ── Jump to a message (?jump=id) ───────────────────────────────────────────
  (function () {
    const params = new URLSearchParams(window.location.search);
    const jump = params.get('jump');
    if (!jump) return;
    const anchor = () => {
      const el = document.querySelector('.msg[data-id="' + parseInt(jump, 10) + '"]');
      if (!el) return;
      el.scrollIntoView({ block: 'center' });
      el.classList.add('reply-highlight');
      setTimeout(() => el.classList.remove('reply-highlight'), 2500);
      history.replaceState(null, '', window.location.pathname);
    };
    if (msgsEl.querySelector('.msg[data-id="' + parseInt(jump, 10) + '"]')) {
      anchor();
    } else {
      // Load older history until the target appears (bounded attempts).
      let oldest = 0;
      const first = msgsEl.querySelector('.msg[data-id]');
      if (first) oldest = parseInt(first.dataset.id, 10);
      const params2 = new URLSearchParams({ before: oldest || 1e12 });
      if (CHANNEL) params2.set('channel', CHANNEL);
      const tryLoad = () => {
        fetch('/api/history?' + params2.toString())
          .then((r) => r.json())
          .then((j) => {
            if (!j.messages || !j.messages.length) { hideSearchResults(); return; }
            const target = j.messages.find((m) => m.id === parseInt(jump, 10));
            prependOlder(j.messages);
            if (target) { anchor(); return; }
            oldest = j.messages[0].id;
            params2.set('before', oldest);
            if (j.messages.length >= 50) tryLoad();
          })
          .catch(() => {});
      };
      tryLoad();
    }
  })();

  // ── Unified Escape handler ─────────────────────────────────────────────────
  // A single listener replaces the five individual document-level Escape
  // handlers (lightbox, sidebar, context menu, install modal, search results)
  // so that only the most relevant open panel is dismissed per keypress:
  //   1. search-results dropdown  →  2. header dropdown  →  3. lightbox
  //   4. right panel  →  5. sidebar backdrop  →  6. context menu  →  7. install modal
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (searchResults && !searchResults.classList.contains('hidden')) {
      hideSearchResults();
      if (searchInput) searchInput.blur();
    } else if (hdrMenu && !hdrMenu.classList.contains('hidden')) {
      closeHdr();
    } else if (lightbox && !lightbox.classList.contains('hidden')) {
      closeLightbox();
    } else if (document.body.classList.contains('right-open')) {
      document.body.classList.remove('right-open');
      const rpb = document.getElementById('right-panel-backdrop');
      if (rpb) rpb.classList.add('hidden');
    } else if (document.body.classList.contains('sidebar-open')) {
      closeSidebar();
    } else if (guestModal && !guestModal.classList.contains('hidden')) {
      closeGuestModal();
    } else if (ctxMenu && !ctxMenu.classList.contains('hidden')) {
      ctxHide();
    } else if (installModal && !installModal.classList.contains('hidden')) {
      installModal.classList.add('hidden');
    }
  });

  // ── Boot ───────────────────────────────────────────────────────────────────
  scrollBottom();
  // Also re-pin as any initially-rendered images load (they start lazy/0-height).
  scrollBottomWhenImagesLoad(msgsEl);
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

  // Realtime transport: SSE when the admin enabled it, else jittered polling.
  // On any SSE error we fall back to polling so the chat never goes quiet.
  if (body.dataset.rt === 'sse' && window.EventSource) {
    let es = null;
    function openStream() {
      const q = new URLSearchParams({ since: lastId });
      if (CHANNEL) q.set('channel', CHANNEL);
      if (DM) q.set('dm', DM);
      q.set('bg_since', bgLast);
      es = new EventSource('/api/stream?' + q.toString());
      es.onmessage = (e) => {
        if (e.data === ': keepalive') return;
        try { handleRealtime(JSON.parse(e.data)); } catch (err) {}
      };
      es.onerror = () => {
        if (es) { es.close(); es = null; }
        setTimeout(() => { if (!es) openStream(); }, pollMs * 3);
      };
    }
    openStream();
  } else {
    setTimeout(poll, Math.floor(Math.random() * pollMs));
    schedulePoll();
  }
})();

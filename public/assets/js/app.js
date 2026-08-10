(() => {
  'use strict';

  try { document.body.dataset.jsstarted = '1'; } catch (e) {}
  const body = document.body;
  const CSRF = window.CHAT ? window.CHAT.csrf : (body.dataset.csrf || '');
  const CHANNEL = body.dataset.channel || '';
  const DM = body.dataset.dm || '';
  const MY_ID = parseInt(body.dataset.myId || '0', 10);
  const MY_NICK = (body.dataset.myNick || '').toLowerCase();
  const IS_GUEST = body.dataset.myGuest === '1';
  // Do Not Disturb: the caller chose to silence alerts (sound + OS + toasts).
  const ME_DND = (body.dataset.meStatus || 'online') === 'dnd';
  const VAPID_KEY = body.dataset.vapidKey || '';
  const MY_LEVEL = body.dataset.myLevel || 'normal';
  const CAN_OP = body.dataset.canOp === '1';

  // Presence rendering helpers for the rich statuses (online/away/dnd/invisible/custom).
  function presenceDot(u) {
    if (!u) return 'bg-discord-500';
    if (u.invisible || !u.is_online) return 'bg-discord-500';
    if (u.status_mode === 'dnd') return 'bg-red-500';
    if (u.status_mode === 'away' || u.away) return 'bg-amber-400';
    if (u.status_mode === 'custom') return 'bg-amber-400';
    return 'bg-green-500';
  }
  function presenceStatus(u) {
    if (!u) return '';
    const t = String((u.custom_status != null ? u.custom_status : u.away) || '').trim();
    return t.length > 60 ? t.slice(0, 59) + '…' : t;
  }
  const STATUS_LABELS = { online: 'Online', away: 'Away', dnd: 'Do Not Disturb', invisible: 'Appear Offline', custom: 'Custom status' };
  // Native-title tooltip for a contact: nick + status + status text.
  function contactTitle(u) {
    const name = u && u.username ? u.username : '?';
    if (u && u.invisible) return name + ' — Appear Offline';
    if (u && !u.is_online && !u.away) return name + ' — Offline';
    const mode = (u && u.status_mode) || (u && u.away ? 'away' : 'online');
    let t = name + ' — ' + (STATUS_LABELS[mode] || 'Online');
    const st = presenceStatus(u);
    if (st) t += ' — ' + st;
    return t;
  }
  const CAN_ADMIN = body.dataset.canAdmin === '1';
  const COMMANDS = JSON.parse(body.dataset.commands || '[]');
  let CHANNEL_SLUGS = {};
  try { CHANNEL_SLUGS = JSON.parse(body.dataset.channels || '{}'); } catch (e) { CHANNEL_SLUGS = {}; }
  let ALL_USERS = [];
  try { ALL_USERS = JSON.parse(body.dataset.users || '[]'); } catch (e) { ALL_USERS = []; }
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
    const els = msgsEl ? msgsEl.querySelectorAll('.msg[data-id], .msg-system[data-id]') : [];
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
    text = text.replace(/(^|[\s(>])#([A-Za-z0-9_\-\[\]{}^`|\\]+)/g, (m, pre, name) => {
      const slug = CHANNEL_SLUGS['#' + name];
      if (!slug) return m;
      return pre + `<a class="text-sky-400 hover:underline" href="/c/${encodeURIComponent(slug)}">#${name}</a>`;
    });
    text = text.replace(/(^|[\s(>])&amp;([A-Za-z0-9_\-\[\]{}^`|\\]+)/g, (m, pre, name) => {
      const slug = CHANNEL_SLUGS['&' + name];
      if (!slug) return m;
      return pre + `<a class="text-sky-400 hover:underline" href="/c/${encodeURIComponent(slug)}">&amp;${name}</a>`;
    });
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
    if (m.kind === 'ai_response' || m.bot === 1) {
      return renderAiContent(m.content);
    }
    return linkify(m.content);
  }

  function initAiStyles() {
    if (document.getElementById('ai-chat-styles')) return;
    const style = document.createElement('style');
    style.id = 'ai-chat-styles';
    style.textContent = `
      .msg[data-kind="ai_response"] .msg-content,
      .msg[data-bot="1"] .msg-content { background: linear-gradient(135deg, rgba(88,101,242,0.04), rgba(88,101,242,0.01)); border-radius: 8px; padding: 4px 8px; margin: -2px -8px; }
      .msg[data-kind="ai_response"] .msg-content h1,
      .msg[data-kind="ai_response"] .msg-content h2,
      .msg[data-kind="ai_response"] .msg-content h3 { font-weight: 700; margin: 0.5em 0 0.25em; color: #e2e8f0; }
      .msg[data-kind="ai_response"] .msg-content h1 { font-size: 1.25em; }
      .msg[data-kind="ai_response"] .msg-content h2 { font-size: 1.1em; }
      .msg[data-kind="ai_response"] .msg-content h3 { font-size: 1em; }
      .msg[data-kind="ai_response"] .msg-content p { margin: 0.35em 0; }
      .msg[data-kind="ai_response"] .msg-content table { border-collapse: collapse; margin: 0.5em 0; width: 100%; font-size: 13px; }
      .msg[data-kind="ai_response"] .msg-content th,
      .msg[data-kind="ai_response"] .msg-content td { border: 1px solid #383a40; padding: 4px 8px; text-align: left; }
      .msg[data-kind="ai_response"] .msg-content th { background: rgba(30,31,34,0.7); font-weight: 600; color: #b5bac1; }
      .msg[data-kind="ai_response"] .msg-content pre { background: #1e1f22; border: 1px solid #383a40; border-radius: 8px; padding: 12px; margin: 0.5em 0; overflow-x: auto; font-size: 13px; }
      .msg[data-kind="ai_response"] .msg-content code { font-family: "JetBrains Mono", "Fira Code", monospace; font-size: 0.9em; }
      .msg[data-kind="ai_response"] .msg-content :not(pre) > code { background: #2b2d31; padding: 1px 5px; border-radius: 4px; }
      .msg[data-kind="ai_response"] .msg-content blockquote { border-left: 3px solid #5865f2; padding-left: 10px; margin: 0.4em 0; color: #949ba4; }
      .msg[data-kind="ai_response"] .msg-content ul,
      .msg[data-kind="ai_response"] .msg-content ol { padding-left: 1.5em; margin: 0.3em 0; }
      .msg[data-kind="ai_response"] .msg-content img { max-width: 100%; border-radius: 8px; margin: 0.5em 0; }
      .msg[data-kind="ai_response"] .msg-content a { color: #38bdf8; text-decoration: underline; }
      .msg[data-kind="ai_response"] .msg-content hr { border: none; border-top: 1px solid #383a40; margin: 0.75em 0; }
      .ai-thinking summary::-webkit-details-marker { display: none; }
      .ai-tool-card summary::-webkit-details-marker { display: none; }
      .username .bot-badge { display: inline-block; font-size: 9px; padding: 0 4px; border-radius: 3px; background: #5865f2; color: white; font-weight: 700; text-transform: uppercase; vertical-align: middle; margin-left: 4px; letter-spacing: 0.5px; }
    `;
    document.head.appendChild(style);
  }

  function renderAiContent(text) {
    if (typeof marked === 'undefined' || typeof DOMPurify === 'undefined') {
      return linkify(text);
    }
    let raw = String(text || '');
    const thinkingBlocks = [];
    raw = raw.replace(/:::thinking\n([\s\S]*?):::/g, (_, content) => {
      const idx = thinkingBlocks.length;
      thinkingBlocks.push(content.trim());
      return `\x01THINK${idx}\x02`;
    });
    const toolBlocks = [];
    raw = raw.replace(/:::tool\n([\s\S]*?):::/g, (_, content) => {
      const idx = toolBlocks.length;
      toolBlocks.push(content.trim());
      return `\x01TOOL${idx}\x02`;
    });
    let html = '';
    try {
      html = marked.parse(raw, { breaks: true, gfm: true });
    } catch (e) {
      html = linkify(raw);
    }
    html = DOMPurify.sanitize(html, {
      ADD_TAGS: ['details', 'summary'],
      ADD_ATTR: ['target', 'rel', 'class', 'data-copy'],
      ALLOW_DATA_ATTR: false,
    });
    thinkingBlocks.forEach((content, i) => {
      const safe = esc(content);
      const block = `<details class="ai-thinking my-1.5 rounded-lg border border-discord-700/50 bg-discord-900/40"><summary class="px-3 py-1.5 text-xs text-discord-400 cursor-pointer select-none hover:text-discord-300">Thinking...</summary><div class="px-3 py-2 text-sm text-discord-400 italic whitespace-pre-wrap">${safe}</div></details>`;
      html = html.replace(`\x01THINK${i}\x02`, block);
    });
    toolBlocks.forEach((content, i) => {
      const lines = content.split('\n');
      const name = esc(lines.shift() || 'Tool');
      const output = esc(lines.join('\n'));
      const block = `<details class="ai-tool-card my-1.5 rounded-lg border border-discord-700 bg-discord-900/60"><summary class="px-3 py-1.5 text-xs font-mono text-sky-400 cursor-pointer select-none hover:text-sky-300">🔧 ${name}</summary><pre class="px-3 py-2 text-xs font-mono text-discord-300 whitespace-pre-wrap overflow-x-auto">${output}</pre></details>`;
      html = html.replace(`\x01TOOL${i}\x02`, block);
    });
    return html;
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
      return `<div class="msg-system px-4 py-1.5 text-xs text-discord-400 italic text-center select-none" data-id="${m.id}" data-kind="${esc(m.kind)}">${linkify(m.content)}</div>`;
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
          <span class="username font-medium text-[15px] leading-5 hover:underline cursor-pointer ${nameColor}"${roleStyle} data-nick="${esc(m.username)}">${sym}${esc(m.username)}${m.bot ? '<span class="bot-badge">BOT</span>' : ''}${guestTag}</span>
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
    // System messages carry a data-id too so a trailing join/topic/part isn't
    // re-appended by the first poll after the initial render.
    if (m.id && msgsEl.querySelector('.msg[data-id="' + m.id + '"], .msg-system[data-id="' + m.id + '"]')) return;
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
    // A "… set the topic to: X" system message updates the header topic too,
    // so every viewer sees the change without a refresh.
    if (m.kind === 'topic' && m.content) {
      const match = /set the topic to: (.+)$/.exec(String(m.content));
      if (match) {
        const hdr = document.getElementById('header-topic');
        if (hdr) hdr.innerHTML = match[1] ? linkifyInline(match[1]) : '';
      }
    }
    if ((m.kind === 'ai_response' || m.bot === 1) && typeof hljs !== 'undefined') {
      const el = msgsEl.lastElementChild;
      if (el) el.querySelectorAll('pre code').forEach((block) => { try { hljs.highlightElement(block); } catch (e) {} });
    }
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

  // Apply a WebSocket "msg_update" event (edit/delete/reaction) to the message
  // currently in view, mirroring what the acting user's own screen already did.
  function applyMsgUpdate(u) {
    if (!u || !u.message_id) return;
    const el = msgsEl ? msgsEl.querySelector('.msg[data-id="' + u.message_id + '"]') : null;
    if (!el) return;
    if (u.action === 'delete') { el.remove(); return; }
    if (u.action === 'edit' && typeof u.content === 'string') {
      el.dataset.edited = '1';
      const contentEl = el.querySelector('.msg-content');
      if (contentEl) {
        const kind = el.dataset.kind || 'message';
        contentEl.innerHTML = msgContentHtml({ kind: kind, content: u.content, bot: parseInt(el.dataset.bot || '0', 10) || 0 });
      }
      return;
    }
    if (u.action === 'reaction' && u.reactions) {
      renderReactions(el, { rows: u.reactions });
    }
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

  // ── Native OS notifications (Electron desktop client) ──────────────────────
  // The web app's OS notifications normally ride Web Push, which Electron can't
  // receive, so the desktop app listens for `lvchat:notify` events and shows a
  // real OS notification itself. Browsers have no listener, so these events are
  // no-ops there. The decision below mirrors the user's per-context push
  // preferences (Profile → Push notifications) and their mutes, so opting out
  // works the same on every platform.
  const PUSH_PREFS = (() => {
    try {
      const p = JSON.parse(body.dataset.pushPrefs || '{}');
      return {
        channels: p.channels === 0 ? 0 : 1,
        dms: p.dms === 0 ? 0 : 1,
        invites: p.invites === 0 ? 0 : 1
      };
    } catch (e) { return { channels: 1, dms: 1, invites: 1 }; }
  })();
  function stripHtml(s) {
    return String(s || '')
      .replace(/<[^>]*>/g, '')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
      .replace(/&#39;/g, "'").replace(/&quot;/g, '"').trim();
  }
  function notifyOS(title, body) {
    try {
      window.dispatchEvent(new CustomEvent('lvchat:notify', { detail: { title, body } }));
    } catch (e) {}
  }

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
      if (ME_DND) return;
      const sid = resolveSound(parseInt(m.sender_id, 10) || null, SOUND_DATA.channel);
      if (sid) playSound(sid);
      if (PUSH_PREFS.channels) {
        notifyOS(m.channel_slug ? '#' + m.channel_slug : 'New message', (m.username ? m.username + ': ' : '') + stripHtml(m.content));
      }
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
      if (ME_DND) return;
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
    // Admin-forced "reconnect all clients": reload so the page re-renders with
    // the current gateway config (fresh ticket + URL). Delivered via poll, SSE
    // and WS frames alike.
    if (j.reconnect) { window.location.reload(); return; }
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
    if (j.channel_invites) updateChannelInvites(j.channel_invites);
    if (j.msg_update) applyMsgUpdate(j.msg_update);
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
    const dot = presenceDot(d);
    const nameCls = isAdmin ? 'text-red-400' : '';
    const unreadCls = d.unread ? ' font-semibold' + (isAdmin ? '' : ' text-white') : '';
    const online = presenceDot(d) !== 'bg-discord-500';
    const st = presenceStatus(d);
    return `<a href="/app?dm=${encodeURIComponent(d.username)}"
         data-ctx-user="${esc(d.username)}"
         data-user-id="${d.user_id || d.id || ''}"
         data-guest="${d.guest ? '1' : '0'}"
         title="${esc(contactTitle(d))}"
         class="flex items-center gap-2 px-2 py-1.5 rounded-md text-sm ${cur ? 'bg-discord-600/50 text-white' : 'text-discord-300 hover:bg-discord-600/40 hover:text-white'} ${online ? '' : 'italic opacity-70'}">
      <span class="w-2 h-2 rounded-full ${dot}"></span>
      <span class="min-w-0">
        <span class="block truncate ${nameCls}${unreadCls}">${esc(d.username)}${guestTag}</span>
        ${st ? `<span class="block truncate text-[11px] text-discord-500">${esc(st)}</span>` : ''}
      </span>
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
        if (d.last_id > prev && d.unread > 0 && DM !== d.username && !d.muted && !ME_DND) {
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

  let channelMembers = [];
  function applyPresence(list) {
    channelMembers = Array.isArray(list) ? list.slice() : [];
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
      const st = presenceStatus(m);
      return `<a href="/app?dm=${encodeURIComponent(m.username)}" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm ${cc}"${rs} data-username="${esc(m.username)}" data-level="${esc(m.level || 'normal')}" title="${esc(contactTitle(m))}">
        <span class="text-[10px] font-bold w-3">${SYMBOL[m.level] || ''}</span>
        ${m.avatar ? `<img src="${esc(m.avatar)}" alt="" loading="lazy" class="w-6 h-6 rounded-full object-cover">` : ''}
        <span class="w-2 h-2 rounded-full shrink-0 ${presenceDot(m)}"></span>
        <span class="min-w-0"><span class="block truncate">${esc(m.username)}</span>${st ? `<span class="block truncate text-[11px] text-discord-500">${esc(st)}</span>` : ''}</span>${m.role === 'admin' ? '<span class="text-[9px] px-1 rounded bg-amber-500/20 text-amber-400">admin</span>' : (m.role === 'staff' ? '<span class="text-[9px] px-1 rounded bg-blurple/20 text-blurple">staff</span>' : '')}</a>`;
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
        const dot = presenceDot(f);
        const st = presenceStatus(f);
        html += '<a href="/app?dm=' + encodeURIComponent(f.username) + '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-200" data-ctx-user="' + esc(f.username) + '" data-user-id="' + (f.id || '') + '" data-friend="1" title="' + esc(contactTitle(f)) + '">';
        html += '<span class="w-2 h-2 rounded-full ' + dot + '"></span>';
        html += friendAvatar(f);
        html += '<span class="min-w-0"><span class="block truncate">' + esc(f.username) + '</span>' + (st ? '<span class="block truncate text-[11px] text-discord-500">' + esc(st) + '</span>' : '') + '</span>';
        html += '<button type="button" class="ctx-btn md:hidden text-discord-400 hover:text-white text-xs px-1.5 py-0.5 ml-auto shrink-0" title="More">⋮</button></a>';
      });
      html += '</div>';
    }
    if (offline.length) {
      html += '<div class="px-2 pt-2 pb-1"><div class="px-2 text-xs font-semibold text-discord-400 uppercase tracking-wide mb-1">Offline — ' + offline.length + '</div>';
      offline.forEach(f => {
        html += '<a href="/app?dm=' + encodeURIComponent(f.username) + '" class="member flex items-center gap-2 px-2 py-1 rounded hover:bg-discord-600/40 text-sm text-discord-400 italic opacity-70" data-ctx-user="' + esc(f.username) + '" data-user-id="' + (f.id || '') + '" data-friend="1" title="' + esc(contactTitle(f)) + '">';
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

  // ── Right sidebar collapsible sections (Friends / Members) ───────────────
  function wireSidebarSection(toggleId, sectionId, arrowId, storageKey, defaultOpen) {
    const toggle = document.getElementById(toggleId);
    const section = document.getElementById(sectionId);
    const arrow = document.getElementById(arrowId);
    if (!toggle || !section) return;
    const stored = localStorage.getItem(storageKey);
    let open = stored === null ? defaultOpen : stored === '1';
    if (!open) {
      section.classList.add('hidden');
      if (arrow) arrow.textContent = '▸';
    }
    toggle.addEventListener('click', () => {
      open = !open;
      localStorage.setItem(storageKey, open ? '1' : '0');
      section.classList.toggle('hidden', !open);
      if (arrow) arrow.textContent = open ? '▾' : '▸';
    });
  }

  wireSidebarSection('friends-toggle', 'friends-section', 'friends-arrow', 'lvc.friendsOpen', true);
  wireSidebarSection('members-toggle', 'member-list', 'members-arrow', 'lvc.membersOpen', true);

  // ── Channel Invites sidebar ───────────────────────────────────────────────
  const channelInvitesSection = document.getElementById('channel-invites-section');
  const channelInvitesToggle = document.getElementById('channel-invites-toggle');
  const channelInvitesArrow = document.getElementById('channel-invites-arrow');
  const channelInvitesCount = document.getElementById('channel-invites-count');
  let channelInvitesSig = '';
  let channelInvitesOpen = localStorage.getItem('lvc.channelInvitesOpen') === '1';

  if (channelInvitesToggle && channelInvitesSection) {
    if (channelInvitesOpen) {
      channelInvitesSection.classList.remove('hidden');
      if (channelInvitesArrow) channelInvitesArrow.textContent = '▾';
    }
    channelInvitesToggle.addEventListener('click', () => {
      channelInvitesOpen = !channelInvitesOpen;
      localStorage.setItem('lvc.channelInvitesOpen', channelInvitesOpen ? '1' : '0');
      if (channelInvitesOpen) {
        channelInvitesSection.classList.remove('hidden');
        if (channelInvitesArrow) channelInvitesArrow.textContent = '▾';
      } else {
        channelInvitesSection.classList.add('hidden');
        if (channelInvitesArrow) channelInvitesArrow.textContent = '▸';
      }
    });
  }

  function updateChannelInvites(invites) {
    if (!channelInvitesSection) return;
    const sig = JSON.stringify(invites.map(i => [i.id, i.channel_id]));
    if (sig === channelInvitesSig) return;
    channelInvitesSig = sig;
    if (channelInvitesCount) channelInvitesCount.textContent = String(invites.length);
    const toggleHeader = channelInvitesToggle ? channelInvitesToggle.closest('.h-12') : null;
    if (!invites.length) {
      channelInvitesSection.innerHTML = '<div class="p-4 text-xs text-discord-500">No pending invites.</div>';
      return;
    }
    let html = '<div class="px-2 pt-2 pb-1">';
    invites.forEach(ci => {
      html += '<div class="channel-invite flex items-center gap-2 px-2 py-1.5 rounded hover:bg-discord-600/40 text-sm" data-channel="' + esc(ci.slug) + '">';
      html += '<span class="truncate text-discord-200">#' + esc(ci.channel_name) + '</span>';
      html += '<span class="text-xs text-discord-500 truncate">by ' + esc(ci.inviter || 'unknown') + '</span>';
      html += '<div class="ml-auto flex gap-1">';
      html += '<button type="button" class="channel-invite-accept text-[10px] px-1.5 py-0.5 rounded bg-green-600 hover:bg-green-500 text-white">Accept</button>';
      html += '<button type="button" class="channel-invite-decline text-[10px] px-1.5 py-0.5 rounded bg-discord-700 hover:bg-discord-600 text-discord-300">Reject</button>';
      html += '</div></div>';
    });
    html += '</div>';
    channelInvitesSection.innerHTML = html;
    bindChannelInviteActions();
    if (!channelInvitesOpen && channelInvitesToggle) {
      channelInvitesOpen = true;
      localStorage.setItem('lvc.channelInvitesOpen', '1');
      channelInvitesSection.classList.remove('hidden');
      if (channelInvitesArrow) channelInvitesArrow.textContent = '▾';
    }
  }

  function bindChannelInviteActions() {
    document.querySelectorAll('.channel-invite-accept').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const row = btn.closest('.channel-invite');
        post('/api/channel/invite/accept', { channel: row.dataset.channel }, (j) => {
          if (j.redirect) window.location = j.redirect;
        });
      });
    });
    document.querySelectorAll('.channel-invite-decline').forEach(btn => {
      if (btn.dataset.bound) return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', () => {
        const row = btn.closest('.channel-invite');
        post('/api/channel/invite/decline', { channel: row.dataset.channel }, () => {
          row.remove();
          channelInvitesSig = '';
        });
      });
    });
  }

  bindChannelInviteActions();

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

  // ── Push notifications (Web Push) ──────────────────────────────────────────
  // Real OS/browser notifications delivered by the service worker. Only
  // registered users can subscribe (guests have no account), and the browser
  // must be a secure context with PushManager support.
  const pushSupported = !IS_GUEST && !!VAPID_KEY && ('serviceWorker' in navigator)
    && ('PushManager' in window) && ('Notification' in window) && window.isSecureContext;
  // True when the user explicitly turned every push category off in settings —
  // in that case we never prompt for permission on their behalf.
  const PUSH_ALL_OFF = body.dataset.pushAllOff === '1';

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
  function pushSubPayload(sub) {
    const p256 = sub.getKey ? sub.getKey('p256dh') : null;
    const auth = sub.getKey ? sub.getKey('auth') : null;
    if (!p256 || !auth) return null;
    return { endpoint: sub.endpoint, p256dh: b64uFromBytes(new Uint8Array(p256)), auth: b64uFromBytes(new Uint8Array(auth)) };
  }
  function subscribePush() {
    return Notification.requestPermission().then((perm) => {
      if (perm !== 'granted') return false;
      return navigator.serviceWorker.ready
        .then((reg) => reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uToBytes(VAPID_KEY) }))
        .then((sub) => {
          const payload = pushSubPayload(sub);
          if (!payload) return false;
          return new Promise((resolve) => {
            post('/api/push/subscribe', payload, () => resolve(true), () => resolve(false));
          });
        })
        .catch(() => false);
    });
  }
  function unsubscribePush() {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.ready
      .then((reg) => reg.pushManager.getSubscription())
      .then((sub) => { if (sub) return sub.unsubscribe(); })
      .catch(() => {})
      .then(() => post('/api/push/unsubscribe', {}, () => {}));
  }
  // If the browser holds a subscription the server doesn't know about (a fresh
  // DB, or cleared rows), re-register it so pushes keep flowing.
  function syncPush() {
    if (!pushSupported || Notification.permission !== 'granted') return;
    navigator.serviceWorker.ready
      .then((reg) => reg.pushManager.getSubscription())
      .then((sub) => {
        const payload = sub ? pushSubPayload(sub) : null;
        if (payload) post('/api/push/subscribe', payload, () => {});
      })
      .catch(() => {});
  }
  const pushRow = document.getElementById('push-row');
  const pushEnable = document.getElementById('push-enable');
  function renderPushRow() {
    if (!pushRow) return;
    if (!pushSupported) { pushRow.classList.add('hidden'); return; }
    if (Notification.permission === 'granted') {
      navigator.serviceWorker.ready
        .then((reg) => reg.pushManager.getSubscription())
        .then((sub) => {
          if (sub) pushRow.classList.add('hidden');
          else { pushRow.classList.remove('hidden'); pushEnable.textContent = 'Enable'; pushEnable.disabled = false; }
        })
        .catch(() => pushRow.classList.add('hidden'));
    } else {
      pushRow.classList.remove('hidden');
      const denied = Notification.permission === 'denied';
      pushEnable.textContent = denied ? 'Blocked' : 'Enable';
      pushEnable.disabled = denied;
      pushEnable.title = denied ? 'Push is blocked in your browser settings' : 'Get OS notifications for new messages, DMs, and invites';
    }
  }
  let pushAutoPrompted = false;
  function subscribePushFromButton() {
    // The button's pointerdown already started the flow via auto-prompt.
    if (pushAutoPrompted) return;
    subscribePush().then((ok) => { if (ok) { showReply('🔔 Push notifications enabled.'); renderPushRow(); } });
  }
  if (pushEnable) pushEnable.addEventListener('click', subscribePushFromButton);
  // Default behaviour is ON: the first click/keystroke/tap anywhere in the chat
  // triggers the browser's permission prompt, so users get push notifications
  // without having to find the Enable button. Respecting a previous "deny" and
  // an explicit all-off preference; only undecided ("default") permission prompts.
  function maybeAutoEnablePush() {
    if (!pushSupported || pushAutoPrompted || PUSH_ALL_OFF) return;
    if (Notification.permission !== 'default') return;
    pushAutoPrompted = true;
    subscribePush().then((ok) => { if (ok) { showReply('🔔 Push notifications enabled.'); renderPushRow(); } });
  }
  ['pointerdown', 'keydown', 'touchstart'].forEach((ev) => {
    document.addEventListener(ev, maybeAutoEnablePush, { once: true, passive: true });
  });
  syncPush();
  renderPushRow();

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
        if (j.action === 'browse') { openBrowse(); (j.replies || []).forEach((r) => showReply(r)); return; }
        if (j.copy) copyText(j.copy);
        (j.replies || []).forEach((r) => showReply(r));
        refreshHeaderTopic(j);
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
      `<div class="msg-system px-4 py-1 text-xs text-sky-400 italic text-center select-none whitespace-pre-wrap break-words">${linkify(text)}</div>`);
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

  // ── Slash + @mention autocomplete ─────────────────────────────────────────
  const ac = document.getElementById('autocomplete');
  let acIndex = 0;
  let mentionAcIndex = 0;
  let acMode = null; // 'slash' | 'mention' | null
  function showAutocomplete(filter) {
    const matches = COMMANDS.filter((c) => c.startsWith(filter) && c !== 'help');
    if (!matches.length) { hideAutocomplete(); return; }
    acMode = 'slash';
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
  function showMentionAutocomplete(query) {
    const q = query.toLowerCase();
    const seen = new Set();
    const online = [];
    const offline = [];
    const addUser = (name, isOnline) => {
      const key = String(name == null ? '' : name).toLowerCase();
      if (!key || seen.has(key)) return;
      seen.add(key);
      if (isOnline) { if (online.length < 25) online.push({ name: String(name), online: true }); }
      else if (offline.length < 50) offline.push({ name: String(name), online: false });
    };
    // Registered users embedded by the server, already ordered online-first.
    ALL_USERS.forEach((u) => {
      if (!q || String(u.u).toLowerCase().startsWith(q)) addUser(u.u, !!u.on);
    });
    // Current channel members (covers guests and members not in the embedded list).
    (channelMembers || []).forEach((m) => {
      if (!q || String(m.username).toLowerCase().startsWith(q)) addUser(m.username, !!m.is_online);
    });
    // Always include yourself so the box never comes up empty.
    addUser(MY_NICK, true);
    const items = online.concat(offline).slice(0, 50);
    if (!items.length) { hideAutocomplete(); return; }
    acMode = 'mention';
    mentionAcIndex = 0;
    ac.innerHTML = items.slice(0, 50).map((it, i) =>
      `<button type="button" data-ac="${i}" data-name="${esc(it.name)}" class="w-full text-left px-3 py-1.5 text-sm flex items-center gap-2 ${i === 0 ? 'bg-blurple/20 text-white' : 'text-discord-300'} hover:bg-blurple/20">
        <span class="w-2 h-2 rounded-full shrink-0 ${it.online ? 'bg-green-500' : 'bg-discord-500'}"></span>
        <span>@${esc(it.name)}</span>
        <span class="ml-auto text-[10px] ${it.online ? 'text-green-400' : 'text-discord-500'}">${it.online ? 'online' : 'offline'}</span>
      </button>`).join('');
    ac.classList.remove('hidden');
    ac.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
      insertMention(b.dataset.name);
    }));
  }
  function insertMention(name) {
    const v = input.value;
    const sel = input.selectionStart;
    const before = v.slice(0, sel);
    const m = before.match(/(?:^|\s)@([^\s]*)$/);
    if (m) {
      const atPos = sel - m[1].length - 1;
      input.value = v.slice(0, atPos) + '@' + name + ' ' + v.slice(sel);
      const pos = atPos + name.length + 2;
      input.setSelectionRange(pos, pos);
      autosizeInput();
    }
    hideAutocomplete();
    input.focus();
  }
  function hideAutocomplete() { ac.classList.add('hidden'); acMode = null; }
  if (input) input.addEventListener('input', () => {
    const v = input.value;
    const sel = input.selectionStart;
    const m = v.slice(0, sel).match(/(?:^|\s)@([^\s]*)$/);
    if (m) { showMentionAutocomplete(m[1]); return; }
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
    if (acMode === 'mention') mentionAcIndex = (mentionAcIndex + d + btns.length) % btns.length;
    else acIndex = (acIndex + d + btns.length) % btns.length;
    const idx = acMode === 'mention' ? mentionAcIndex : acIndex;
    btns.forEach((b, i) => { b.classList.toggle('bg-blurple/20', i === idx); b.classList.toggle('text-white', i === idx); b.classList.toggle('text-discord-300', i !== idx); });
  }
  function pickAc() {
    const btns = ac.querySelectorAll('button');
    const idx = acMode === 'mention' ? mentionAcIndex : acIndex;
    if (btns[idx]) btns[idx].click();
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
  // ── Create channel modal ───────────────────────────────────────────────────
  // A real modal instead of window.prompt(): prompt() is unsupported in the
  // desktop client (it silently returns null), so the create box never showed
  // there. The modal also collects the topic, registration and privacy options.
  const createModal = document.getElementById('create-channel-modal');
  const createForm = document.getElementById('create-form');
  const createName = document.getElementById('create-name');
  const createTopic = document.getElementById('create-topic');
  const createInvite = document.getElementById('create-invite');
  const createRegister = document.getElementById('create-register');
  const createError = document.getElementById('create-error');
  const createSubmit = document.getElementById('create-submit');

  function openCreateChannel() {
    if (!createModal) return;
    createModal.classList.remove('hidden');
    if (createName) { createName.value = ''; createName.focus(); }
    if (createTopic) createTopic.value = '';
    if (createInvite) createInvite.checked = false;
    if (createRegister) createRegister.checked = true;
    document.querySelectorAll('#create-form input[name="visibility"]').forEach((r) => { r.checked = r.value === 'public'; });
    if (createError) { createError.textContent = ''; createError.classList.add('hidden'); }
    if (createSubmit) createSubmit.disabled = false;
  }
  function closeCreateChannel() { if (createModal) createModal.classList.add('hidden'); }
  if (createModal) {
    createModal.querySelectorAll('[data-create-close]').forEach((el) => el.addEventListener('click', closeCreateChannel));
    createModal.addEventListener('click', (e) => { if (e.target === createModal) closeCreateChannel(); });
  }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && createModal && !createModal.classList.contains('hidden')) closeCreateChannel(); });
  if (createForm) createForm.addEventListener('submit', (e) => {
    e.preventDefault();
    if (createError) createError.classList.add('hidden');
    let name = createName ? createName.value.trim() : '';
    if (!name) { if (createError) { createError.textContent = 'Please enter a channel name.'; createError.classList.remove('hidden'); } createName.focus(); return; }
    // A bare name gets the normal # prefix; an explicit local (&) prefix is kept.
    const local = /^&/.test(name);
    name = name.replace(/^[#&!]+/, '');
    const visibility = (document.querySelector('#create-form input[name="visibility"]:checked') || {}).value || 'public';
    const payload = {
      name: (local ? '&' : '#') + name,
      topic: createTopic ? createTopic.value.trim() : '',
      register: (createRegister && createRegister.checked) ? '1' : '0',
      visibility,
      invite_only: (createInvite && createInvite.checked) ? '1' : '0',
    };
    if (createSubmit) createSubmit.disabled = true;
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('ajax', '1');
    Object.keys(payload).forEach((k) => fd.append(k, payload[k]));
    fetch('/api/channels', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
      .then((r) => r.json().catch(() => ({ error: 'Server error' })))
      .then((j) => {
        if (j.redirect) { window.location = j.redirect; return; }
        if (j.error) {
          if (createSubmit) createSubmit.disabled = false;
          if (createError) { createError.textContent = j.error; createError.classList.remove('hidden'); }
          return;
        }
        if (createSubmit) createSubmit.disabled = false;
      })
      .catch(() => {
        if (createSubmit) createSubmit.disabled = false;
        if (createError) { createError.textContent = 'Unable to create the channel. Please try again.'; createError.classList.remove('hidden'); }
      });
  });
  const cc1 = document.getElementById('create-channel');
  const cc2 = document.getElementById('create-channel-2');
  if (cc1) cc1.addEventListener('click', openCreateChannel);
  if (cc2) cc2.addEventListener('click', openCreateChannel);

  // ── User menu / away ───────────────────────────────────────────────────────
  const menuBtn = document.getElementById('user-menu-btn');
  const menu = document.getElementById('user-menu');
  if (menuBtn) menuBtn.addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('hidden'); });
  // The header avatar and underlined status line open the same status menu.
  ['me-header-avatar', 'me-status-line'].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', (e) => { e.stopPropagation(); if (menu) menu.classList.toggle('hidden'); });
  });
  document.addEventListener('click', () => { if (menu) menu.classList.add('hidden'); });
  const awayBtn = document.getElementById('set-away-btn');
  if (awayBtn) awayBtn.addEventListener('click', () => {
    const msg = prompt('Away message (leave empty to come back):', '');
    if (msg === null) return;
    post('/api/profile', { away: msg.trim() }, () => { window.location.reload(); });
  });
  // Rich status picker (Online / Away / DND / Appear Offline / Custom).
  document.querySelectorAll('[data-status]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const body = { status_mode: btn.dataset.status };
      if (btn.dataset.status === 'custom') {
        const text = prompt('Custom status:', '');
        if (text === null) return;
        body.custom_status = text.trim();
      }
      post('/api/status', body, () => { window.location.reload(); });
    });
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

  // After a command that changed the topic, update the header line next to the
  // channel name right away (only for the channel currently being viewed).
  function refreshHeaderTopic(j) {
    if (j.topic_set === undefined || j.topic_channel !== CHANNEL) return;
    const hdr = document.getElementById('header-topic');
    if (!hdr) return;
    hdr.innerHTML = j.topic_set ? linkifyInline(j.topic_set) : '';
  }

  function runCommand(text) {
    post('/api/command', { channel: CHANNEL, text }, (j) => {
      if (j.redirect) { window.location = j.redirect; return; }
      if (j.replies) j.replies.forEach((r) => showReply(r));
      refreshHeaderTopic(j);
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
    if (el.dataset.owned === '1' || CAN_ADMIN) {
      items.push({ div: true });
      items.push({ label: 'Channel background', onClick: () => openChanBg(slug, el) });
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
          post('/api/push/mute', { user_id: uid }, () => showReply('Muted ' + nick + ' — no notifications from them.'));
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

  // ── Channel background (owner/admin sets the chat background) ──────────────
  const chanBgModal = document.getElementById('chan-bg-modal');
  const chanBgColor = document.getElementById('chan-bg-color');
  const chanBgFile = document.getElementById('chan-bg-file');
  const chanBgCurrent = document.getElementById('chan-bg-current');
  const chanBgFit = document.getElementById('chan-bg-fit');
  const chanBgOverlay = document.getElementById('chan-bg-overlay');
  const chanBgOverlayLabel = document.getElementById('chan-bg-overlay-label');
  const chanBgMsg = document.getElementById('chan-bg-msg');
  let chanBgSlug = '';
  function openChanBg(slug, el) {
    if (!chanBgModal) return;
    chanBgSlug = slug || '';
    // The channel you clicked may not be the one you're currently viewing, so
    // pull its current background from the link itself (falls back to the open
    // channel's, then to nothing).
    const bgColor = (el && el.dataset.bgColor) || body.dataset.chanBgColor || '';
    const bgImage = (el && el.dataset.bgImage) || body.dataset.chanBgImage || '';
    const bgFit = (el && el.dataset.bgFit) || body.dataset.chanBgFit || 'contain';
    const bgOverlay = parseInt((el && el.dataset.bgOverlay) || body.dataset.chanBgOverlay || '55', 10) || 55;
    if (chanBgColor) chanBgColor.value = bgColor || '#2b2d31';
    if (chanBgFit) chanBgFit.value = bgFit;
    if (chanBgOverlay) chanBgOverlay.value = bgOverlay;
    if (chanBgOverlayLabel) chanBgOverlayLabel.textContent = bgOverlay + '%';
    if (chanBgCurrent) {
      chanBgCurrent.classList.toggle('hidden', !bgImage);
      chanBgCurrent.innerHTML = bgImage
        ? '<img src="' + esc(bgImage) + '" alt="Current background" class="h-12 w-24 object-cover rounded border border-discord-600">'
        : '';
    }
    if (chanBgMsg) chanBgMsg.classList.add('hidden');
    chanBgModal.classList.remove('hidden');
  }
  function closeChanBg() { if (chanBgModal) chanBgModal.classList.add('hidden'); }
  if (chanBgModal) {
    chanBgModal.querySelectorAll('[data-chan-bg-close]').forEach((el) => el.addEventListener('click', closeChanBg));
    chanBgModal.addEventListener('click', (e) => { if (e.target === chanBgModal) closeChanBg(); });
  }
  const chanBgClear = document.getElementById('chan-bg-color-clear');
  if (chanBgClear) chanBgClear.addEventListener('click', () => { if (chanBgColor) chanBgColor.value = '#000000'; });
  if (chanBgOverlay) chanBgOverlay.addEventListener('input', () => {
    if (chanBgOverlayLabel) chanBgOverlayLabel.textContent = chanBgOverlay.value + '%';
  });
  const chanBgSave = document.getElementById('chan-bg-save');
  if (chanBgSave) chanBgSave.addEventListener('click', () => {
    if (!chanBgSlug) return;
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('channel', chanBgSlug);
    fd.append('bg_color', chanBgColor ? chanBgColor.value : '');
    fd.append('bg_fit', chanBgFit ? chanBgFit.value : 'contain');
    fd.append('bg_overlay', chanBgOverlay ? chanBgOverlay.value : '55');
    if (chanBgFile && chanBgFile.files && chanBgFile.files[0]) fd.append('file', chanBgFile.files[0]);
    fetch('/api/channel/bg', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
      .then((r) => r.json().catch(() => ({ error: 'Server error (' + r.status + ')' })))
      .then((j) => {
        if (j.error) { alert(j.error); return; }
        if (chanBgMsg) { chanBgMsg.classList.remove('hidden'); setTimeout(() => chanBgMsg.classList.add('hidden'), 1500); }
        window.location.reload();
      })
      .catch(() => alert('Request failed. Please try again.'));
  });
  const chanBgRemove = document.getElementById('chan-bg-remove');
  if (chanBgRemove) chanBgRemove.addEventListener('click', () => {
    if (!chanBgSlug) return;
    if (!confirm('Remove this channel\'s background?')) return;
    const fd = new FormData();
    fd.append('csrf', CSRF);
    fd.append('channel', chanBgSlug);
    fetch('/api/channel/bg/remove', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
      .then((r) => r.json().catch(() => ({ error: 'Server error (' + r.status + ')' })))
      .then((j) => { if (j.error) { alert(j.error); return; } window.location.reload(); })
      .catch(() => alert('Request failed. Please try again.'));
  });

  // ── Theme toggle (light/dark, sticky per browser + per account) ────────────
  const themeBtn = document.getElementById('theme-toggle');
  function setThemeIcon() {
    if (!themeBtn) return;
    themeBtn.textContent = document.documentElement.classList.contains('light') ? '☀️' : '🌙';
    themeBtn.title = document.documentElement.classList.contains('light') ? 'Switch to dark mode' : 'Switch to light mode';
  }
  // The admin kill-switch hides the theme controls for everyone — they are
  // forced onto the server theme.
  const themeCustom = body.dataset.themeCustom === '1';
  if (!themeCustom) {
    document.querySelectorAll('#theme-toggle, #header-theme-toggle').forEach((b) => { b.classList.add('hidden'); });
  }
  if (themeBtn && themeCustom) themeBtn.addEventListener('click', () => {
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

  // ── App install modal (PWA) ────────────────────────────────────────────────
  // Three states for the install buttons (#install-btn desktop, #install-btn-m
  // mobile header menu — each repeated per header variant):
  //   · running inside the installed PWA  → buttons are hidden entirely
  //   · installable browser (Chrome/Edge) → "How to install" opens the
  //     step-by-step guide + an "Install now" CTA when `beforeinstallprompt` fires
  //   · browser that can't install PWAs (desktop Firefox/Safari) → relabelled
  //     "App install unsupported", opening a modal that lists supported browsers.
  const installModal = document.getElementById('install-modal');
  const unsupportedModal = document.getElementById('install-unsupported-modal');
  const installNow = document.getElementById('install-now');
  let deferredInstall = null;
  const isStandalone = () => navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
  const isIOS = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  // Chromium-based browsers (Chrome, Edge, Brave, Opera, Vivaldi, …) can install
  // the PWA. Brave is Chromium but does NOT reliably fire `beforeinstallprompt`
  // (Shields / engagement heuristics), so relying on that event alone would
  // wrongly flag Brave as unable to install.
  const isChromium = () => {
    try {
      if (navigator.userAgentData && navigator.userAgentData.brands) {
        if (navigator.userAgentData.brands.some((b) => String(b.brand || '').toLowerCase().includes('chromium'))) return true;
      }
    } catch (e) {}
    return /Chrome\/|Chromium|CriOS|Edg\/|EdgA|OPR\/|Opera|Vivaldi|Brave/i.test(navigator.userAgent);
  };
  const installBtns = () => document.querySelectorAll('#install-btn, #install-btn-m');
  const hideInstallButtons = () => installBtns().forEach((el) => { el.style.display = 'none'; });
  const labelInstallButtons = (unsupported) => installBtns().forEach((el) => {
    el.textContent = unsupported ? '⚠ App install unsupported' : '⬇ How to install';
  });

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredInstall = e;
    labelInstallButtons(false);
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
    if (unsupportedModal) unsupportedModal.classList.add('hidden');
    hideInstallButtons();
  });

  installBtns().forEach((el) => el.addEventListener('click', () => {
    if (isStandalone()) return;
    // No beforeinstallprompt (and not iOS where "Add to Home Screen" works,
    // and not a Chromium browser that may simply fire the event later): the
    // browser can't install the app, so explain which ones can.
    if (deferredInstall === null && !isIOS() && !isChromium()) {
      labelInstallButtons(true);
      if (unsupportedModal) unsupportedModal.classList.remove('hidden');
      return;
    }
    if (installModal) installModal.classList.remove('hidden');
  }));

  if (unsupportedModal) {
    unsupportedModal.querySelectorAll('[data-install-unsupported-close]').forEach((el) => el.addEventListener('click', () => unsupportedModal.classList.add('hidden')));
    unsupportedModal.addEventListener('click', (e) => { if (e.target === unsupportedModal) unsupportedModal.classList.add('hidden'); });
  }
  if (installModal) {
    installModal.querySelectorAll('[data-install-close]').forEach((el) => el.addEventListener('click', () => installModal.classList.add('hidden')));
    installModal.addEventListener('click', (e) => { if (e.target === installModal) installModal.classList.add('hidden'); });
  }

  // ── Download the desktop app modal (native clients) ────────────────────────
  // Reached from the "Download the desktop app" button inside the install modal.
  // Tabs (Desktop / Messenger) swap the visible panel; the download links are
  // server-rendered from Admin → Settings → Desktop apps & downloads.
  const downloadModal = document.getElementById('download-modal');
  const downloadOpenBtn = document.getElementById('download-open-btn');
  if (downloadOpenBtn) downloadOpenBtn.addEventListener('click', () => {
    if (installModal) installModal.classList.add('hidden');
    if (downloadModal) downloadModal.classList.remove('hidden');
  });
  if (downloadModal) {
    downloadModal.querySelectorAll('[data-download-close]').forEach((el) => el.addEventListener('click', () => downloadModal.classList.add('hidden')));
    downloadModal.addEventListener('click', (e) => { if (e.target === downloadModal) downloadModal.classList.add('hidden'); });
    const panels = [...downloadModal.querySelectorAll('[data-download-panel]')];
    downloadModal.querySelectorAll('[data-download-tab]').forEach((tab) => tab.addEventListener('click', () => {
      const app = tab.dataset.downloadTab;
      downloadModal.querySelectorAll('[data-download-tab]').forEach((t) => {
        t.classList.toggle('bg-blurple', t === tab);
        t.classList.toggle('text-white', t === tab);
        t.classList.toggle('text-discord-300', t !== tab);
        t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
      });
      panels.forEach((p) => p.classList.toggle('hidden', p.dataset.downloadPanel !== app));
    }));
  }

  if (isStandalone()) {
    hideInstallButtons();
  } else if (!isIOS() && !isChromium()) {
    // No `beforeinstallprompt` by now usually means the browser can't install
    // the app. Chromium browsers (including Brave) are never relabelled here —
    // the event may simply fire later once the user engages with the page.
    window.setTimeout(() => {
      if (deferredInstall === null && !isStandalone()) labelInstallButtons(true);
    }, 5000);
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
  if (hdrTheme && themeCustom) hdrTheme.addEventListener('click', () => {
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
    post('/api/push/mute', { user_id: uid }, () => showReply('Muted — no notifications from them.'));
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
  //   8. download modal
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
    } else if (downloadModal && !downloadModal.classList.contains('hidden')) {
      downloadModal.classList.add('hidden');
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

  // Realtime transport: WebSocket gateway when the admin enabled it, else SSE,
  // else jittered polling. Each transport degrades to the next on failure so
  // the chat never goes quiet. WebSocket runs a slow HTTP reconcile on top so
  // the sidebar summaries (DM list, unread badges, presence counts, friends,
  // bell) stay fresh without a per-event push for each of them.
  const rtMode = body.dataset.rt || 'poll';
  // "Force WebSocket": when enabled the browser must never fall back to
  // SSE/polling. A broken gateway becomes a loud red badge + quiet retries,
  // not a silent downgrade (which reads as a phantom 0 in the counter).
  // Only meaningful when WebSocket mode is actually selected.
  const RT_FORCE = rtMode === 'ws' && body.dataset.rtForce === '1';

  // Tell the server — and show the user — which transport actually won. This
  // surfaces silent fallbacks: "websockets configured but everyone is on
  // polling" becomes visible instead of a phantom 0 in the gateway counter.
  let liveTransport = '';
  const RT_BADGE_TEXT = { ws: 'websocket', sse: 'sse', poll: 'polling', none: 'websocket offline', connecting: 'connecting…' };
  const RT_DOT_COLOR = { ws: 'bg-green-500', sse: 'bg-sky-500', poll: 'bg-amber-400', none: 'bg-red-500', connecting: 'bg-discord-400' };
  const RT_LABEL_COLOR = { ws: 'text-green-400', sse: 'text-sky-400', poll: 'text-amber-400', none: 'text-red-400', connecting: 'text-discord-300' };
  function showTransport(t, report) {
    if (t === liveTransport) return;
    liveTransport = t;
    const status = document.getElementById('rt-status');
    if (status) {
      const label = document.getElementById('rt-label');
      const dot = document.getElementById('rt-dot');
      if (label) label.textContent = RT_BADGE_TEXT[t] || 'polling';
      if (label) label.className = 'truncate ' + (RT_LABEL_COLOR[t] || RT_LABEL_COLOR.poll);
      if (dot) dot.className = 'w-2 h-2 rounded-full shrink-0 ' + (RT_DOT_COLOR[t] || RT_DOT_COLOR.poll);
      status.classList.remove('hidden');
      status.hidden = false;
    }
    if (report === false) return;
    fetch('/api/rt/report', {
      method: 'POST',
      headers: { 'X-CSRF': CSRF },
      body: new URLSearchParams({ transport: t }),
    }).catch(() => {});
  }

  function startPolling() {
    showTransport('poll');
    setTimeout(poll, Math.floor(Math.random() * pollMs));
    schedulePoll();
  }

  // Shared WebSocket machinery. `onUnrecoverable` decides what a broken gateway
  // means: normal mode falls back to polling, force mode shows the offline badge
  // (and never downgrades — messages simply stop until the socket returns).
  function setupWs(onUnrecoverable) {
    let ws = null;
    let wsFails = 0;
    let wsGone = false;
    let reconcileTimer = null;
    let wsRetryTimer = null;
    let wsBase = body.dataset.wsUrl || '';
    let wsTicket = body.dataset.rtTicket || '';
    // Keep a plain ws:// variant of the configured wss:// URL to try once if the
    // secure handshake fails. It only stands a chance on a non-secure page
    // (http://) — on an https page the browser hard-blocks ws:// as mixed
    // content, so the offline state is the honest outcome there. Re-prefer
    // wss:// on each retry cycle.
    let plainTried = false;
    const pageSecure = typeof window.isSecureContext === 'boolean' ? window.isSecureContext : true;
    function plainBase() {
      return wsBase.indexOf('wss://') === 0 ? 'ws://' + wsBase.slice(6) : '';
    }

    function wsSend(obj) {
      if (ws && ws.readyState === WebSocket.OPEN) ws.send(JSON.stringify(obj));
    }
    function wsSubscribe() {
      if (CHANNEL) wsSend({ action: 'subscribe', channel: CHANNEL });
      else if (DM) wsSend({ action: 'subscribe', dm: DM });
    }
    function refreshTicket(done) {
      fetch('/api/ws/ticket')
        .then((r) => r.json())
        .then((j) => {
          if (j && j.ticket) { wsTicket = j.ticket; wsBase = j.url || wsBase; }
          done();
        })
        .catch(() => done());
    }
    function giveUp() {
      if (reconcileTimer) { clearInterval(reconcileTimer); reconcileTimer = null; }
      onUnrecoverable();
      scheduleWsRetry();
    }
    // A failed handshake used to stop retrying forever. Re-probe quietly every
    // few minutes so a later proxy fix/daemon restart re-enables realtime.
    function scheduleWsRetry() {
      if (wsRetryTimer) return;
      wsRetryTimer = setInterval(() => {
        if (wsGone || ws || document.hidden) return;
        wsFails = 0;
        plainTried = false; // prefer the secure URL again next cycle
        refreshTicket(() => { if (!ws) openWs(); });
      }, 5 * 60 * 1000);
    }
    function openWs() {
      if (wsGone) return;
      const base = (plainTried && plainBase()) ? plainBase() : wsBase;
      const sep = base.indexOf('?') >= 0 ? '&' : '?';
      try { ws = new WebSocket(base + sep + 'ticket=' + encodeURIComponent(wsTicket)); }
      catch (e) { ws = null; }
      if (!ws) { giveUp(); return; }
      ws.onopen = () => { wsFails = 0; showTransport('ws'); wsSubscribe(); };
      ws.onmessage = (ev) => {
        let j = null;
        try { j = JSON.parse(ev.data); } catch (err) { return; }
        if (j.pong) return;
        handleRealtime(j);
      };
      // In force mode the very first failure is already "no fallback, offline" —
      // don't wait for three slow timeouts to admit the socket is down.
      ws.onerror = () => { if (RT_FORCE) showTransport('none'); try { ws.close(); } catch (e) {} };
      ws.onclose = () => {
        ws = null;
        if (wsGone) return;
        if (RT_FORCE) showTransport('none');
        wsFails++;
        // Secure failed three times: on a non-secure page try the plain ws://
        // variant once (it's blocked as mixed content on https, so skip there).
        if (wsFails >= 3 && !plainTried && !pageSecure && plainBase()) {
          wsFails = 0;
          plainTried = true;
          refreshTicket(() => setTimeout(openWs, pollMs));
          return;
        }
        if (wsFails >= 3) { giveUp(); return; }
        // A ticket only lives 60s; mint a fresh one before reconnecting.
        refreshTicket(() => setTimeout(openWs, pollMs * (wsFails + 1)));
      };
    }
    showTransport('connecting', false); // badge is visible from boot, not just after a fallback
    openWs();
    // Presence heartbeat keeps last_seen fresh server-side and the connection
    // alive past idle timeouts.
    setInterval(() => wsSend({ action: 'ping' }), 30000);
    // Hybrid reconcile: WS carries messages instantly; this cheap HTTP call
    // refreshes the sidebar summaries every 30s (only while WS is actually open,
    // so force mode never becomes polling by another name).
    reconcileTimer = setInterval(() => { if (ws && ws.readyState === WebSocket.OPEN) poll(); }, 30000);
  }

  if (rtMode === 'ws' && window.WebSocket) {
    if (body.dataset.rtTicket) {
      // Normal mode: fall back to polling. Force mode: go offline + keep retrying.
      setupWs(RT_FORCE
        ? () => showTransport('none')
        : () => { showTransport('poll'); startPolling(); });
    } else if (RT_FORCE) {
      // Forced but no handshake ticket — never downgrade; re-probe for one.
      showTransport('none');
      setupWs(() => showTransport('none'));
    } else {
      startPolling();
    }
  } else if (rtMode === 'sse' && window.EventSource && !RT_FORCE) {
    let es = null;
    function openStream() {
      const q = new URLSearchParams({ since: lastId });
      if (CHANNEL) q.set('channel', CHANNEL);
      if (DM) q.set('dm', DM);
      q.set('bg_since', bgLast);
      es = new EventSource('/api/stream?' + q.toString());
      es.onopen = () => showTransport('sse');
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
  } else if (RT_FORCE) {
    // WS is selected + forced but the API is missing — loud offline state.
    showTransport('none');
  } else {
    startPolling();
  }

  // ── Channel browser modal ──────────────────────────────────────────────────
  const browseModal = document.getElementById('browse-modal');
  let browseData = null;
  let browseSortKey = 'members';
  let browseSortDir = -1;

  function openBrowse() {
    if (!browseModal) return;
    browseModal.classList.remove('hidden');
    if (!browseData) {
      fetch('/api/browse').then(r => r.json()).then(j => {
        if (j.ok) { browseData = j; renderBrowse(); }
      });
    } else {
      renderBrowse();
    }
  }
  function closeBrowse() {
    if (browseModal) browseModal.classList.add('hidden');
  }

  ['browse-btn-sidebar', 'browse-btn-header'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', openBrowse);
  });
  if (browseModal) {
    browseModal.querySelectorAll('[data-browse-close]').forEach(el => {
      el.addEventListener('click', closeBrowse);
    });
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !browseModal.classList.contains('hidden')) closeBrowse();
    });
  }

  function browseAvatar(name) {
    let h = 0;
    for (let i = 0; i < name.length; i++) h = ((h << 5) - h + name.charCodeAt(i)) | 0;
    const hue = Math.abs(h) % 360;
    const letter = (name[0] || '#').toUpperCase();
    return '<div class="w-10 h-10 rounded-lg shrink-0 flex items-center justify-center text-base font-bold text-white shadow-inner" style="background:linear-gradient(135deg,hsl(' + hue + ',55%,45%),hsl(' + ((hue + 40) % 360) + ',50%,35%))">' + esc(letter) + '</div>';
  }

  function browseRow(c) {
    const vis = c.visibility !== 'public' ? '<span class="ml-1.5 text-[10px] px-1.5 py-0.5 rounded bg-discord-700/80 text-discord-400 border border-discord-600/50" title="Restricted">🔒</span>' : '';
    const topicText = (c.topic || c.description || '').slice(0, 120);
    const topic = topicText ? esc(topicText) : '<span class="text-discord-500 italic">No topic set</span>';
    const online = c.online != null ? c.online : c.members;
    const action = c.joined
      ? '<button class="browse-open px-5 py-2 rounded-lg text-sm font-semibold bg-discord-700 hover:bg-discord-600 text-white transition-all border border-discord-600 hover:border-discord-500" data-slug="' + esc(c.slug) + '">Open</button>'
      : '<button class="browse-join px-5 py-2 rounded-lg text-sm font-semibold bg-blurple hover:bg-blurple-dark text-white transition-all shadow-md shadow-blurple/20 hover:shadow-blurple/30" data-name="' + esc(c.name) + '" data-slug="' + esc(c.slug) + '">Join</button>';
    return '<div class="browse-item browse-row-premium group flex items-center gap-4 p-4 rounded-xl bg-discord-800/80 border border-discord-700/80 cursor-pointer" data-name="' + esc(c.name.toLowerCase()) + '" data-topic="' + esc((c.topic || c.description || '').toLowerCase()) + '" data-members="' + c.members + '" data-joined="' + (c.joined ? '1' : '0') + '">' +
      browseAvatar(c.name) +
      '<div class="flex-1 min-w-0">' +
        '<div class="flex items-center gap-1.5 mb-0.5">' +
          '<span class="font-bold text-[15px] text-white group-hover:text-blurple transition-colors">' + esc(c.name) + '</span>' + vis +
        '</div>' +
        '<div class="text-[13px] text-discord-300 line-clamp-2 leading-relaxed">' + topic + '</div>' +
      '</div>' +
      '<div class="flex items-center gap-4 shrink-0">' +
        '<div class="text-right">' +
          '<div class="flex items-center justify-end gap-1.5 text-xs">' +
            '<span class="w-1.5 h-1.5 rounded-full bg-green-500 pulse-dot"></span>' +
            '<span class="text-discord-200 font-semibold">' + online + '</span>' +
          '</div>' +
          '<div class="text-[10px] text-discord-500 mt-0.5">' + c.members + ' member' + (c.members === 1 ? '' : 's') + '</div>' +
        '</div>' +
        action +
      '</div>' +
    '</div>';
  }

  function renderBrowse() {
    if (!browseData) return;
    const q = ((document.getElementById('browse-search') || {}).value || '').trim().toLowerCase();
    const f = (document.getElementById('browse-filter') || {}).value || 'all';

    const filterFn = c => {
      if (f === 'joined' && !c.joined) return false;
      if (f === 'open' && c.joined) return false;
      if (q && c.name.toLowerCase().indexOf(q) === -1 && (c.topic || c.description || '').toLowerCase().indexOf(q) === -1) return false;
      return true;
    };
    const sortFn = (a, b) => {
      let va = a[browseSortKey], vb = b[browseSortKey];
      if (browseSortKey === 'members') return ((parseInt(va) || 0) - (parseInt(vb) || 0)) * browseSortDir;
      return String(va || '').localeCompare(String(vb || '')) * browseSortDir;
    };

    const myFiltered = browseData.myChannels.filter(filterFn).sort(sortFn);
    const allFiltered = browseData.channels.filter(filterFn).sort(sortFn);

    const mySection = document.getElementById('browse-my-section');
    const myList = document.getElementById('browse-my-list');
    if (mySection && myList) {
      if (myFiltered.length) {
        mySection.classList.remove('hidden');
        myList.innerHTML = myFiltered.map(browseRow).join('');
      } else {
        mySection.classList.add('hidden');
      }
    }

    const list = document.getElementById('browse-list');
    const empty = document.getElementById('browse-empty');
    const countEl = document.getElementById('browse-count');
    if (list) {
      if (allFiltered.length) {
        list.innerHTML = allFiltered.map(browseRow).join('');
        if (empty) empty.classList.add('hidden');
      } else {
        list.innerHTML = '';
        if (empty) empty.classList.remove('hidden');
      }
    }
    if (countEl) countEl.textContent = allFiltered.length + ' channel' + (allFiltered.length === 1 ? '' : 's');

    const onlineEl = document.getElementById('browse-online');
    const peakEl = document.getElementById('browse-peak');
    if (onlineEl) onlineEl.textContent = browseData.online;
    if (peakEl) peakEl.textContent = browseData.peak;

    browseModal.querySelectorAll('.browse-item').forEach(item => {
      item.addEventListener('click', (e) => {
        if (e.target.closest('.browse-join')) return;
        const openBtn = item.querySelector('.browse-open');
        if (openBtn) window.location.href = '/app?channel=' + encodeURIComponent(openBtn.dataset.slug);
      });
    });
    browseModal.querySelectorAll('.browse-join').forEach(btn => {
      btn.addEventListener('click', () => {
        btn.disabled = true;
        btn.textContent = 'Joining…';
        const fd = new FormData();
        fd.append('csrf', CSRF);
        fd.append('ajax', '1');
        fd.append('name', btn.dataset.name);
        fetch('/api/join', { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } })
          .then(r => r.json().catch(() => ({ error: 'Server error' })))
          .then(j => {
            if (j.redirect) { window.location.href = j.redirect; return; }
            if (j.error === 'need_key' || (j.error || '').toLowerCase().indexOf('password') !== -1 || (j.error || '').toLowerCase().indexOf('key') !== -1) {
              window.location.href = '/app?join=' + encodeURIComponent(btn.dataset.slug);
              return;
            }
            btn.disabled = false;
            btn.textContent = 'Join';
            if (j.error) alert(j.error);
          })
          .catch(() => {
            btn.disabled = false;
            btn.textContent = 'Join';
          });
      });
    });

  }

  if (browseModal) {
    const searchEl = document.getElementById('browse-search');
    const filterEl = document.getElementById('browse-filter');
    if (searchEl) searchEl.addEventListener('input', renderBrowse);
    if (filterEl) filterEl.addEventListener('change', renderBrowse);

  }

  initAiStyles();
})();

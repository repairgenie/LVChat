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


$isStaff = ModerationService::isStaff($user);
$isOwner = $ticket['user_id'] !== null && (int) $ticket['user_id'] === (int) $user['id'];
$back = $isStaff ? '/admin/support' : '/support';
$imageExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
?>
<style>
  .rt-shell { border: 1px solid var(--c-d-700); border-radius: 8px; background: var(--c-d-800); }
  .rt-toolbar { display: flex; flex-wrap: wrap; gap: 2px; padding: 6px 8px; border-bottom: 1px solid var(--c-d-700); background: var(--c-d-850); border-radius: 8px 8px 0 0; }
  .rt-toolbar .rt-group { display: flex; gap: 2px; }
  .rt-toolbar .rt-group + .rt-group { margin-left: 6px; padding-left: 6px; border-left: 1px solid var(--c-d-700); }
  .rt-btn { padding: 3px 7px; border-radius: 5px; font-size: 12px; line-height: 1.4; color: var(--c-d-300); background: transparent; cursor: pointer; white-space: nowrap; }
  .rt-btn:hover { background: var(--c-d-750); color: #fff; }
  .rt-btn.is-active { background: rgb(88 101 242 / .25); color: #fff; }
  .rt-content { padding: 12px 14px; min-height: 120px; max-height: 300px; overflow-y: auto; }
  .rt-content .tiptap { outline: none; }
  .rt-content .tiptap > *:first-child { margin-top: 0; }
  .rt-content .tiptap ul[data-type="taskList"] { list-style: none; padding-left: 0; }
  .rt-content .tiptap ul[data-type="taskList"] li { display: flex; gap: 8px; align-items: flex-start; }
  .rt-content .tiptap blockquote { border-left: 3px solid rgb(88 101 242 / .6); margin: 8px 0; padding: 2px 12px; color: var(--c-d-300); }
  .rt-content .tiptap hr { border: none; border-top: 1px solid var(--c-d-600); margin: 12px 0; }
  .rt-content .tiptap pre { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 6px; padding: 10px 12px; overflow-x: auto; }
  .rt-content .tiptap code { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 4px; padding: 1px 4px; }
  .rt-content .tiptap pre code { background: none; border: none; padding: 0; }
  .att-img { max-width: 200px; max-height: 200px; border-radius: 6px; border: 1px solid var(--c-d-600); cursor: pointer; transition: opacity .15s; }
  .att-img:hover { opacity: .85; }
  .lightbox { position: fixed; inset: 0; z-index: 500; background: rgba(0,0,0,.85); display: none; align-items: center; justify-content: center; cursor: zoom-out; }
  .lightbox.open { display: flex; }
  .lightbox img { max-width: 92vw; max-height: 92vh; border-radius: 8px; }
  .prose-ticket { line-height: 1.7; }
  .prose-ticket p { margin: 0.5em 0; }
  .prose-ticket p:first-child { margin-top: 0; }
  .prose-ticket p:last-child { margin-bottom: 0; }
  .prose-ticket ul, .prose-ticket ol { margin: 0.5em 0; padding-left: 1.5em; }
  .prose-ticket li { margin: 0.25em 0; }
  .prose-ticket blockquote { border-left: 3px solid rgb(88 101 242 / .6); margin: 0.5em 0; padding: 0.25em 0.75em; color: var(--c-d-300); }
  .prose-ticket pre { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 6px; padding: 0.5em 0.75em; overflow-x: auto; margin: 0.5em 0; }
  .prose-ticket code { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 4px; padding: 0.1em 0.3em; font-size: 0.9em; }
  .prose-ticket pre code { background: none; border: none; padding: 0; }
  .prose-ticket hr { border: none; border-top: 1px solid var(--c-d-600); margin: 1em 0; }
  .prose-ticket a { color: var(--c-blurple); text-decoration: underline; }
  .prose-ticket strong, .prose-ticket b { font-weight: 600; color: var(--c-d-100); }
</style>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold text-white">Ticket #<?= (int) $ticket['id'] ?> — <?= h($ticket['subject']) ?></h1>
    <a href="<?= h($back) ?>" class="btn-ghost text-xs">← Back</a>
  </div>

  <?php if ($error): ?>
  <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-2.5 text-sm"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card p-4 mb-4 text-sm flex flex-wrap gap-x-4 gap-y-2 items-center">
    <span>Status:
      <span class="px-1.5 py-0.5 rounded text-[11px] <?= $ticket['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($ticket['status'] === 'answered' ? 'bg-amber-500/20 text-amber-300' : 'bg-discord-700 text-discord-400') ?>"><?= h($ticket['status']) ?></span>
    </span>
    <?php if ($isStaff): ?>
    <span>
      Contact:
      <?php if ($ticket['user_id'] !== null): ?>
      <a class="text-blurple hover:underline" href="/admin/users/<?= (int) $ticket['user_id'] ?>"><?= h($ticket['username']) ?></a>
      <?php else: ?>
      <span class="text-blurple"><?= h($ticket['email']) ?></span>
      <?php endif; ?>
      <?php if ($contactEmail && ($ticket['user_id'] === null || ($ticket['email'] ?? '') !== '')): ?>
      <span class="text-discord-500">· emails to <?= h($contactEmail) ?></span>
      <?php endif; ?>
    </span>
    <span class="text-discord-400"><?= h(gmdate('M j Y H:i', strtotime($ticket['created_at'] . ' UTC'))) ?></span>
    <?php if ($isStaff && $staff): ?>
    <form method="post" action="/admin/support/<?= (int) $ticket['id'] ?>/assign" class="flex items-center gap-1 ml-auto">
      <?= Csrf::field() ?>
      <label class="text-discord-400 text-xs">Assignee</label>
      <select name="assigned_to" class="text-xs bg-discord-750 border border-discord-600 rounded px-1.5 py-0.5" onchange="this.form.submit()">
        <option value="">Unassigned</option>
        <?php foreach ($staff as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= (int) $ticket['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php if ($ticket['closed_at']): ?><span class="text-discord-400">Closed <?= h(gmdate('M j Y H:i', strtotime($ticket['closed_at'] . ' UTC'))) ?></span><?php endif; ?>
  </div>

  <div class="space-y-4 mb-6">
    <?php foreach ($replies as $r): $staff = (int) $r['is_staff'] === 1; ?>
    <div class="card p-4 <?= $staff ? 'border-blurple/40' : '' ?>">
      <div class="flex items-baseline gap-2 mb-2 text-xs">
        <span class="font-semibold <?= $staff ? 'text-blurple' : 'text-white' ?>"><?= h($r['username'] ?? 'deleted user') ?><?= $staff ? ' (staff)' : '' ?></span>
        <span class="text-discord-500"><?= h(gmdate('M j Y H:i', strtotime($r['created_at'] . ' UTC'))) ?></span>
      </div>
      <div class="prose-ticket text-[15px] leading-relaxed text-discord-200"><?= $r['content'] ?></div>
      <?php
      $atts = json_decode((string) ($r['attachments'] ?? ''), true) ?: [];
      if (!empty($atts)): ?>
      <div class="mt-3 flex flex-wrap gap-3 items-start">
        <?php foreach ($atts as $att):
          $ext = strtolower($att['ext'] ?? pathinfo($att['url'], PATHINFO_EXTENSION));
          $isImg = in_array($ext, $imageExts, true);
        ?>
          <?php if ($isImg): ?>
          <img src="<?= h($att['url']) ?>" alt="<?= h($att['name']) ?>" class="att-img" onclick="openLightbox(this.src)">
          <?php else: ?>
          <a href="<?= h($att['url']) ?>" download="<?= h($att['name']) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md bg-discord-750 border border-discord-600 text-xs text-discord-200 hover:text-white hover:border-discord-500">
            📎 <?= h($att['name']) ?>
          </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php if ($ticket['status'] !== 'closed'): ?>
  <form method="post" action="/support/<?= (int) $ticket['id'] ?>/reply" enctype="multipart/form-data" class="card p-5 space-y-3">
    <?= Csrf::field() ?>
    <label class="label"><?= $isStaff ? 'Reply (the user is emailed on your reply)' : 'Add a reply' ?></label>
    <div class="rt-shell" data-editor="ticket-reply">
      <div class="rt-toolbar" data-toolbar="ticket-reply"></div>
      <div class="rt-content" data-content="ticket-reply"></div>
    </div>
    <textarea name="content" id="ticket-reply-content" class="hidden"></textarea>
    <div>
      <label class="label">Attachments (optional)</label>
      <input type="file" name="attachments[]" multiple accept="image/*,.txt,.pdf,.docx,.odt" class="input !p-1.5">
      <p class="text-xs text-discord-400 mt-1">Up to 5 files, max 25 MB each.</p>
    </div>
    <div class="flex gap-2">
      <button class="btn-primary justify-center flex-1">Send reply</button>
      <?php if ($isOwner || $isStaff): ?>
      <button class="btn-ghost" formaction="/support/<?= (int) $ticket['id'] ?>/close">Close ticket</button>
      <?php endif; ?>
    </div>
  </form>
  <?php else: ?>
  <?php if ($isStaff): ?>
  <form method="post" action="/support/<?= (int) $ticket['id'] ?>/reopen" class="card p-5">
    <?= Csrf::field() ?>
    <button class="btn-ghost justify-center">Reopen ticket</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>
<div id="lightbox" class="lightbox" onclick="this.classList.remove('open')"><img id="lightbox-img" src="" alt=""></div>
<script src="/assets/vendor/tiptap/tiptap-bundle.js"></script>
<script>
function openLightbox(src) {
  document.getElementById('lightbox-img').src = src;
  document.getElementById('lightbox').classList.add('open');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') document.getElementById('lightbox').classList.remove('open'); });
(function () {
  var bundle = window.TiptapBundle;
  if (!bundle) {
    var ta = document.getElementById('ticket-reply-content');
    if (ta) { ta.classList.remove('hidden'); ta.rows = 4; ta.className += ' input'; ta.required = true; var shell = document.querySelector('[data-editor="ticket-reply"]'); if (shell) shell.style.display = 'none'; }
    return;
  }
  function btn(label, title) { var b = document.createElement('button'); b.type = 'button'; b.className = 'rt-btn'; b.innerHTML = label; b.title = title || ''; return b; }
  function group() { var g = document.createElement('div'); g.className = 'rt-group'; return g; }
  var bar = document.querySelector('[data-toolbar="ticket-reply"]');
  if (!bar) return;
  var g1 = group();
  var bold = btn('<b>B</b>', 'Bold'); bold.dataset.a = 'bold'; g1.appendChild(bold);
  var ital = btn('<i>I</i>', 'Italic'); ital.dataset.a = 'italic'; g1.appendChild(ital);
  var und = btn('<u>U</u>', 'Underline'); und.dataset.a = 'underline'; g1.appendChild(und);
  var str = btn('<s>S</s>', 'Strikethrough'); str.dataset.a = 'strike'; g1.appendChild(str);
  bar.appendChild(g1);
  var g2 = group();
  var ul = btn('• List', 'Bulleted list'); ul.dataset.a = 'bulletList'; g2.appendChild(ul);
  var ol = btn('1. List', 'Numbered list'); ol.dataset.a = 'orderedList'; g2.appendChild(ol);
  bar.appendChild(g2);
  var g3 = group();
  var q = btn('❝', 'Blockquote'); q.dataset.a = 'blockquote'; g3.appendChild(q);
  var code = btn('</>', 'Code'); code.dataset.a = 'code'; g3.appendChild(code);
  var hr = btn('—', 'HR'); hr.dataset.a = 'horizontalRule'; g3.appendChild(hr);
  bar.appendChild(g3);
  var contentEl = document.querySelector('[data-content="ticket-reply"]');
  var ta = document.getElementById('ticket-reply-content');
  var editor = new bundle.Editor({
    element: contentEl,
    content: '',
    extensions: [bundle.StarterKit.configure({ heading: false }), bundle.Underline],
    editorProps: { attributes: { class: 'tiptap' } },
    onUpdate: function () { ta.value = editor.getHTML(); },
  });
  bar.querySelectorAll('.rt-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      switch (b.dataset.a) {
        case 'bold': editor.chain().focus().toggleBold().run(); break;
        case 'italic': editor.chain().focus().toggleItalic().run(); break;
        case 'underline': editor.chain().focus().toggleUnderline().run(); break;
        case 'strike': editor.chain().focus().toggleStrike().run(); break;
        case 'bulletList': editor.chain().focus().toggleBulletList().run(); break;
        case 'orderedList': editor.chain().focus().toggleOrderedList().run(); break;
        case 'blockquote': editor.chain().focus().toggleBlockquote().run(); break;
        case 'code': editor.chain().focus().toggleCode().run(); break;
        case 'horizontalRule': editor.chain().focus().setHorizontalRule().run(); break;
      }
    });
  });
  editor.on('transaction', function () {
    bar.querySelectorAll('.rt-btn').forEach(function (b) {
      var on = false;
      if (b.dataset.a === 'bold') on = editor.isActive('bold');
      else if (b.dataset.a === 'italic') on = editor.isActive('italic');
      else if (b.dataset.a === 'underline') on = editor.isActive('underline');
      else if (b.dataset.a === 'strike') on = editor.isActive('strike');
      else if (b.dataset.a === 'bulletList') on = editor.isActive('bulletList');
      else if (b.dataset.a === 'orderedList') on = editor.isActive('orderedList');
      else if (b.dataset.a === 'blockquote') on = editor.isActive('blockquote');
      else if (b.dataset.a === 'code') on = editor.isActive('code');
      b.classList.toggle('is-active', on);
    });
  });
})();
</script>

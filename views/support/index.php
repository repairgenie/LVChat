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
?>

<style>
  .rt-shell { border: 1px solid var(--c-d-700); border-radius: 8px; background: var(--c-d-800); }
  .rt-toolbar { display: flex; flex-wrap: wrap; gap: 2px; padding: 6px 8px; border-bottom: 1px solid var(--c-d-700); background: var(--c-d-850); border-radius: 8px 8px 0 0; }
  .rt-toolbar .rt-group { display: flex; gap: 2px; }
  .rt-toolbar .rt-group + .rt-group { margin-left: 6px; padding-left: 6px; border-left: 1px solid var(--c-d-700); }
  .rt-btn { padding: 3px 7px; border-radius: 5px; font-size: 12px; line-height: 1.4; color: var(--c-d-300); background: transparent; cursor: pointer; white-space: nowrap; }
  .rt-btn:hover { background: var(--c-d-750); color: #fff; }
  .rt-btn.is-active { background: rgb(88 101 242 / .25); color: #fff; }
  .rt-content { padding: 12px 14px; min-height: 160px; max-height: 360px; overflow-y: auto; }
  .rt-content .tiptap { outline: none; }
  .rt-content .tiptap > *:first-child { margin-top: 0; }
  .rt-content .tiptap ul[data-type="taskList"] { list-style: none; padding-left: 0; }
  .rt-content .tiptap ul[data-type="taskList"] li { display: flex; gap: 8px; align-items: flex-start; }
  .rt-content .tiptap blockquote { border-left: 3px solid rgb(88 101 242 / .6); margin: 8px 0; padding: 2px 12px; color: var(--c-d-300); }
  .rt-content .tiptap hr { border: none; border-top: 1px solid var(--c-d-600); margin: 12px 0; }
  .rt-content .tiptap pre { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 6px; padding: 10px 12px; overflow-x: auto; }
  .rt-content .tiptap code { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 4px; padding: 1px 4px; }
  .rt-content .tiptap pre code { background: none; border: none; padding: 0; }
</style>
<div class="max-w-3xl mx-auto">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold text-white">Support</h1>
    <a href="/app" class="btn-ghost text-xs">← Back to chat</a>
  </div>

  <?php if ($error): ?>
  <div class="mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-2.5 text-sm"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="card p-6 mb-6">
    <h2 class="font-semibold text-white mb-3">Open a ticket</h2>
    <form method="post" action="/support" enctype="multipart/form-data" class="space-y-3">
      <?= Csrf::field() ?>
      <div>
        <label class="label">Subject</label>
        <input class="input" name="subject" required maxlength="120" value="<?= h($old['subject'] ?? '') ?>" placeholder="Brief summary of your issue">
      </div>
      <div>
        <label class="label">Description</label>
        <div class="rt-shell" data-editor="ticket-create">
          <div class="rt-toolbar" data-toolbar="ticket-create"></div>
          <div class="rt-content" data-content="ticket-create"><?= $old['content'] ?? '' ?></div>
        </div>
        <textarea name="content" id="ticket-create-content" class="hidden" required><?= h($old['content'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="label">Attachments (optional)</label>
        <input type="file" name="attachments[]" multiple accept="image/*,.txt,.pdf,.docx,.odt" class="input !p-1.5" max="5">
        <p class="text-xs text-discord-400 mt-1">Up to 5 files, max 25 MB each. Images, TXT, PDF, DOCX, ODT.</p>
      </div>
      <button class="btn-primary justify-center">Submit ticket</button>
    </form>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-discord-700 text-sm font-semibold text-white">My tickets</div>
    <?php if (!$tickets): ?>
    <div class="px-4 py-4 text-sm text-discord-500">No tickets yet.</div>
    <?php else: ?>
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
          <th class="px-4 py-2">#</th>
          <th class="px-4 py-2">Subject</th>
          <th class="px-4 py-2">Status</th>
          <th class="px-4 py-2">Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tickets as $t): ?>
        <tr class="border-b border-discord-800">
          <td class="px-4 py-2"><a href="/support/<?= (int) $t['id'] ?>" class="text-blurple hover:underline">#<?= (int) $t['id'] ?></a></td>
          <td class="px-4 py-2 text-white"><?= h($t['subject']) ?></td>
          <td class="px-4 py-2">
            <span class="px-1.5 py-0.5 rounded text-[11px] <?= $t['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($t['status'] === 'answered' ? 'bg-amber-500/20 text-amber-300' : 'bg-discord-700 text-discord-400') ?>"><?= h($t['status']) ?></span>
          </td>
          <td class="px-4 py-2 text-discord-400"><?= h(relative_time($t['updated_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<script src="/assets/vendor/tiptap/tiptap-bundle.js"></script>
<script>
(function () {
  var bundle = window.TiptapBundle;
  if (!bundle) {
    var ta = document.getElementById('ticket-create-content');
    if (ta) { ta.classList.remove('hidden'); ta.rows = 5; ta.className += ' input'; var shell = document.querySelector('[data-editor="ticket-create"]'); if (shell) shell.style.display = 'none'; }
    return;
  }
  function btn(label, title) { var b = document.createElement('button'); b.type = 'button'; b.className = 'rt-btn'; b.innerHTML = label; b.title = title || ''; return b; }
  function group() { var g = document.createElement('div'); g.className = 'rt-group'; return g; }
  var bar = document.querySelector('[data-toolbar="ticket-create"]');
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
  var hr = btn('—', 'Horizontal rule'); hr.dataset.a = 'horizontalRule'; g3.appendChild(hr);
  bar.appendChild(g3);
  var contentEl = document.querySelector('[data-content="ticket-create"]');
  var ta = document.getElementById('ticket-create-content');
  var editor = new bundle.Editor({
    element: contentEl,
    content: contentEl.innerHTML,
    extensions: [
      bundle.StarterKit.configure({ heading: false }),
      bundle.Underline,
    ],
    editorProps: { attributes: { class: 'tiptap' } },
    onUpdate: function () { ta.value = editor.getHTML(); },
  });
  ta.value = editor.getHTML();
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

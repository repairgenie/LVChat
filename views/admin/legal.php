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

 $title = 'Terms & Privacy'; $active = 'legal';
$pageTitle = 'Terms of Service &amp; Privacy Policy';
$pageActions = '<a href="/terms" target="_blank" class="btn-ghost text-xs !py-1.5">Preview /terms</a>';
require ROOT . '/views/admin/_nav.php';
require ROOT . '/views/admin/_page_header.php';
?>
<style>
  .rt-shell { border: 1px solid var(--c-d-700); border-radius: 8px; background: var(--c-d-800); }
  .rt-toolbar { display: flex; flex-wrap: wrap; gap: 2px; padding: 6px 8px; border-bottom: 1px solid var(--c-d-700); background: var(--c-d-850); border-radius: 8px 8px 0 0; }
  .rt-toolbar .rt-group { display: flex; gap: 2px; }
  .rt-toolbar .rt-group + .rt-group { margin-left: 6px; padding-left: 6px; border-left: 1px solid var(--c-d-700); }
  .rt-btn { padding: 3px 7px; border-radius: 5px; font-size: 12px; line-height: 1.4; color: var(--c-d-300); background: transparent; cursor: pointer; white-space: nowrap; }
  .rt-btn:hover { background: var(--c-d-750); color: #fff; }
  .rt-btn.is-active { background: rgb(88 101 242 / .25); color: #fff; }
  .rt-btn.rt-cmd-label { font-weight: 700; }
  .rt-select, .rt-color { background: var(--c-d-750); color: var(--c-d-200); border: 1px solid var(--c-d-600); border-radius: 5px; font-size: 12px; padding: 2px 4px; }
  .rt-color { padding: 0 2px; height: 24px; width: 30px; cursor: pointer; }
  .rt-content { padding: 12px 14px; min-height: 240px; max-height: 460px; overflow-y: auto; }
  .rt-content .tiptap { outline: none; }
  .rt-content .tiptap > *:first-child { margin-top: 0; }
  .rt-content .tiptap ul[data-type="taskList"] { list-style: none; padding-left: 0; }
  .rt-content .tiptap ul[data-type="taskList"] li { display: flex; gap: 8px; align-items: flex-start; }
  .rt-content .tiptap table { border-collapse: collapse; width: 100%; margin: 8px 0; }
  .rt-content .tiptap th, .rt-content .tiptap td { border: 1px solid var(--c-d-600); padding: 6px 10px; }
  .rt-content .tiptap th { background: var(--c-d-750); }
  .rt-content .tiptap img { max-width: 100%; border-radius: 6px; }
  .rt-content .tiptap pre { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 6px; padding: 10px 12px; overflow-x: auto; }
  .rt-content .tiptap code { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 4px; padding: 1px 4px; }
  .rt-content .tiptap pre code { background: none; border: none; padding: 0; }
  .rt-content .tiptap blockquote { border-left: 3px solid rgb(88 101 242 / .6); margin: 8px 0; padding: 2px 12px; color: var(--c-d-300); }
  .rt-content .tiptap hr { border: none; border-top: 1px solid var(--c-d-600); margin: 12px 0; }
  .rt-content .tiptap mark { background-color: #fef08a; color: #000; padding: 0 2px; border-radius: 2px; }
</style>

<div class="card p-5 mb-5 max-w-3xl">
  <p class="text-sm text-discord-400">Edit the site's legal pages with the full rich-text editor (headings, formatting, colours, tables, task lists, images, and more). They are linked from the account menu in the chat sidebar and from the login/register pages. Boilerplate is based on US federal law and Nevada statutes (NRS 597.790 / NRS 597.795).</p>
</div>

<form method="post" action="/admin/action" id="legal-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/legal">

  <div class="card p-5 mb-6 max-w-5xl">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-white">Terms of Service</h2>
      <button type="button" class="btn-ghost text-xs !py-1" data-reset="terms">Reset to boilerplate</button>
    </div>
    <div class="rt-shell" data-editor="terms">
      <div class="rt-toolbar" data-toolbar="terms"></div>
      <div class="rt-content" data-content="terms"><?= $terms ?></div>
    </div>
    <textarea name="terms" id="legal-terms" class="hidden"></textarea>
  </div>

  <div class="card p-5 mb-6 max-w-5xl">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-white">Privacy Policy</h2>
      <button type="button" class="btn-ghost text-xs !py-1" data-reset="privacy">Reset to boilerplate</button>
    </div>
    <div class="rt-shell" data-editor="privacy">
      <div class="rt-toolbar" data-toolbar="privacy"></div>
      <div class="rt-content" data-content="privacy"><?= $privacy ?></div>
    </div>
    <textarea name="privacy" id="legal-privacy" class="hidden"></textarea>
  </div>

  <button name="action" value="legal_save" class="btn-primary">Save legal pages</button>
</form>

<form method="post" action="/admin/action" id="legal-reset-form" class="hidden">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/legal">
  <input type="hidden" name="action" value="legal_reset">
  <input type="hidden" name="which" id="legal-reset-which" value="terms">
</form>

<script src="/assets/vendor/tiptap/tiptap-bundle.js"></script>
<script>
(function () {
  var bundle = window.TiptapBundle;
  if (!bundle) {
    document.getElementById('legal-form').insertAdjacentHTML('afterbegin',
      '<div class="max-w-3xl mb-4 rounded-md bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-2.5 text-sm">The tiptap editor failed to load (missing /assets/vendor/tiptap/tiptap-bundle.js). The textareas below can still be edited directly.</div>');
    document.querySelectorAll('[data-editor]').forEach(function (shell) {
      var ta = document.getElementById('legal-' + shell.dataset.editor);
      ta.classList.remove('hidden');
      ta.rows = 24;
      ta.className += ' input font-mono mt-2';
      ta.value = shell.querySelector('[data-content]').innerHTML;
      shell.style.display = 'none';
    });
    return;
  }

  var S = bundle.StarterKit;
  var LINK = bundle.Link;
  var UNDERLINE = bundle.Underline;
  var TEXT_STYLE = bundle.TextStyle;
  var COLOR = bundle.Color;
  var HIGHLIGHT = bundle.Highlight;
  var TEXT_ALIGN = bundle.TextAlign;
  var SUB = bundle.Subscript;
  var SUP = bundle.Superscript;
  var TASK_LIST = bundle.TaskList;
  var TASK_ITEM = bundle.TaskItem;
  var IMAGE = bundle.Image;
  var TABLE = bundle.Table;
  var TABLE_ROW = bundle.TableRow;
  var TABLE_HEADER = bundle.TableHeader;
  var TABLE_CELL = bundle.TableCell;

  var SIZES = ['sm', 'base', 'lg', 'xl'];

  function btn(label, title) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'rt-btn';
    b.innerHTML = label;
    b.title = title || label;
    return b;
  }
  function group() {
    var g = document.createElement('div');
    g.className = 'rt-group';
    return g;
  }

  // Toolbar definition: [action, label, title, opts]
  var ACTIONS = [
    { a: 'undo', l: '↺', t: 'Undo' },
    { a: 'redo', l: '↻', t: 'Redo' },
  ];
  var HEADING_OPTS = { a: 'heading', l: 'H1', t: 'Heading 1', v: 1 };
  var PARAGRAPH_OPTS = { a: 'paragraph', l: '¶', t: 'Paragraph' };

  function buildToolbar(name) {
    var bar = document.querySelector('[data-toolbar="' + name + '"]');
    if (!bar) return;

    // Row 1: history + blocks + lists + align
    var g1 = group();
    g1.appendChild(btn('↺', 'Undo')).dataset.a = 'undo';
    g1.appendChild(btn('↻', 'Redo')).dataset.a = 'redo';
    g1.appendChild(btn('¶', 'Paragraph')).dataset.a = 'paragraph';
    for (var h = 1; h <= 6; h++) {
      var hb = btn('H' + h, 'Heading ' + h);
      hb.dataset.a = 'heading'; hb.dataset.v = String(h); hb.dataset.cmd = 'heading';
      g1.appendChild(hb);
    }
    bar.appendChild(g1);

    var g2 = group();
    var bold = btn('<b>B</b>', 'Bold'); bold.dataset.a = 'bold'; bold.dataset.cmd = 'bold'; g2.appendChild(bold);
    var ital = btn('<i>I</i>', 'Italic'); ital.dataset.a = 'italic'; ital.dataset.cmd = 'italic'; g2.appendChild(ital);
    var und = btn('<u>U</u>', 'Underline'); und.dataset.a = 'underline'; und.dataset.cmd = 'underline'; g2.appendChild(und);
    var str = btn('<s>S</s>', 'Strikethrough'); str.dataset.a = 'strike'; str.dataset.cmd = 'strike'; g2.appendChild(str);
    var sub = btn('x₂', 'Subscript'); sub.dataset.a = 'subscript'; sub.dataset.cmd = 'subscript'; g2.appendChild(sub);
    var sup = btn('x²', 'Superscript'); sup.dataset.a = 'superscript'; sup.dataset.cmd = 'superscript'; g2.appendChild(sup);
    bar.appendChild(g2);

    var g3 = group();
    var colorWrap = document.createElement('span'); colorWrap.className = 'rt-btn'; colorWrap.title = 'Text colour';
    var colorInput = document.createElement('input'); colorInput.type = 'color'; colorInput.className = 'rt-color'; colorInput.value = '#5865f2';
    colorInput.dataset.role = 'color';
    colorWrap.appendChild(colorInput); g3.appendChild(colorWrap);
    var hlWrap = document.createElement('span'); hlWrap.className = 'rt-btn'; hlWrap.title = 'Highlight colour';
    var hlInput = document.createElement('input'); hlInput.type = 'color'; hlInput.className = 'rt-color'; hlInput.value = '#fef08a';
    hlInput.dataset.role = 'highlight';
    hlWrap.appendChild(hlInput); g3.appendChild(hlWrap);
    var unhl = btn('∅', 'Remove highlight'); unhl.dataset.a = 'unhighlight'; g3.appendChild(unhl);
    bar.appendChild(g3);

    var g4 = group();
    var ul = btn('• List', 'Bulleted list'); ul.dataset.a = 'bulletList'; ul.dataset.cmd = 'bulletList'; g4.appendChild(ul);
    var ol = btn('1. List', 'Numbered list'); ol.dataset.a = 'orderedList'; ol.dataset.cmd = 'orderedList'; g4.appendChild(ol);
    var task = btn(window.icon('check-circle', 'w-4 h-4') + ' Tasks', 'Task list'); task.dataset.a = 'taskList'; task.dataset.cmd = 'taskList'; g4.appendChild(task);
    bar.appendChild(g4);

    var g5 = group();
    var q = btn('❝', 'Blockquote'); q.dataset.a = 'blockquote'; q.dataset.cmd = 'blockquote'; g5.appendChild(q);
    var code = btn('</>', 'Inline code'); code.dataset.a = 'code'; code.dataset.cmd = 'code'; g5.appendChild(code);
    var pre = btn('{ }', 'Code block'); pre.dataset.a = 'codeBlock'; pre.dataset.cmd = 'codeBlock'; g5.appendChild(pre);
    var hr = btn('—', 'Horizontal rule'); hr.dataset.a = 'horizontalRule'; g5.appendChild(hr);
    bar.appendChild(g5);

    var g6 = group();
    ['left', 'center', 'right', 'justify'].forEach(function (dir) {
      var ab = btn(dir[0].toUpperCase(), 'Align ' + dir);
      ab.dataset.a = 'align'; ab.dataset.v = dir; ab.dataset.cmd = 'align:' + dir;
      g6.appendChild(ab);
    });
    bar.appendChild(g6);

    var g7 = group();
    var link = btn(window.icon('link','w-4 h-4'), 'Add/edit link'); link.dataset.a = 'link'; g7.appendChild(link);
    var unlink = btn(window.icon('unlink','w-4 h-4') || '🔓', 'Remove link'); unlink.dataset.a = 'unlink'; g7.appendChild(unlink);
    var img = btn(window.icon('image','w-4 h-4'), 'Insert image'); img.dataset.a = 'image'; g7.appendChild(img);
    bar.appendChild(g7);

    var g8 = group();
    var tbl = btn('⊞', 'Insert table'); tbl.dataset.a = 'table'; g8.appendChild(tbl);
    var trow = btn('+↕', 'Add row'); trow.dataset.a = 'tableAddRow'; g8.appendChild(trow);
    var tdelrow = btn('−↕', 'Delete row'); tdelrow.dataset.a = 'tableDeleteRow'; g8.appendChild(tdelrow);
    var tcol = btn('+↔', 'Add column'); tcol.dataset.a = 'tableAddCol'; g8.appendChild(tcol);
    var tdelcol = btn('−↔', 'Delete column'); tdelcol.dataset.a = 'tableDeleteCol'; g8.appendChild(tdelcol);
    var tdel = btn(window.icon('trash','w-4 h-4'), 'Delete table'); tdel.dataset.a = 'tableDelete'; g8.appendChild(tdel);
    bar.appendChild(g8);

    var g9 = group();
    var clear = btn(window.icon('x','w-4 h-4'), 'Clear formatting'); clear.dataset.a = 'clear'; g9.appendChild(clear);
    bar.appendChild(g9);
  }

  function initEditor(name) {
    buildToolbar(name);
    var shell = document.querySelector('[data-editor="' + name + '"]');
    var contentEl = shell.querySelector('[data-content]');
    var ta = document.getElementById('legal-' + name);

    var editor = new bundle.Editor({
      element: contentEl,
      content: contentEl.innerHTML,
      extensions: [
        S.configure({ codeBlock: {}, heading: { levels: [1, 2, 3, 4, 5, 6] } }),
        LINK.configure({ openOnClick: false, autolink: true, HTMLAttributes: { target: '_blank', rel: 'noopener' } }),
        UNDERLINE,
        TEXT_STYLE,
        COLOR,
        HIGHLIGHT.configure({ multicolor: true }),
        TEXT_ALIGN.configure({ types: ['heading', 'paragraph'] }),
        SUB,
        SUP,
        TASK_LIST,
        TASK_ITEM.configure({ nested: true }),
        IMAGE.configure({ inline: false, allowBase64: false }),
        TABLE.configure({ resizable: true }),
        TABLE_ROW,
        TABLE_HEADER,
        TABLE_CELL,
      ],
      editorProps: { attributes: { class: 'tiptap' } },
      onUpdate: function () { ta.value = editor.getHTML(); },
    });

    ta.value = editor.getHTML();

    // Wire toolbar buttons.
    shell.querySelectorAll('.rt-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        var a = b.dataset.a;
        var v = b.dataset.v;
        switch (a) {
          case 'undo': editor.chain().focus().undo().run(); break;
          case 'redo': editor.chain().focus().redo().run(); break;
          case 'paragraph': editor.chain().focus().setParagraph().run(); break;
          case 'heading': editor.chain().focus().toggleHeading({ level: parseInt(v, 10) }).run(); break;
          case 'bold': editor.chain().focus().toggleBold().run(); break;
          case 'italic': editor.chain().focus().toggleItalic().run(); break;
          case 'underline': editor.chain().focus().toggleUnderline().run(); break;
          case 'strike': editor.chain().focus().toggleStrike().run(); break;
          case 'subscript': editor.chain().focus().toggleSubscript().run(); break;
          case 'superscript': editor.chain().focus().toggleSuperscript().run(); break;
          case 'unhighlight': editor.chain().focus().unsetHighlight().run(); break;
          case 'bulletList': editor.chain().focus().toggleBulletList().run(); break;
          case 'orderedList': editor.chain().focus().toggleOrderedList().run(); break;
          case 'taskList': editor.chain().focus().toggleTaskList().run(); break;
          case 'blockquote': editor.chain().focus().toggleBlockquote().run(); break;
          case 'code': editor.chain().focus().toggleCode().run(); break;
          case 'codeBlock': editor.chain().focus().toggleCodeBlock().run(); break;
          case 'horizontalRule': editor.chain().focus().setHorizontalRule().run(); break;
          case 'align': editor.chain().focus().setTextAlign(v).run(); break;
          case 'link': {
            LVCDialog.prompt('Link URL (https://…):', editor.getAttributes('link').href || 'https://').then(function (url) {
              if (url === null) return;
              if (url === '') { editor.chain().focus().unsetLink().run(); }
              else { editor.chain().focus().setLink({ href: url }).run(); }
            });
            break;
          }
          case 'unlink': editor.chain().focus().unsetLink().run(); break;
          case 'image': {
            LVCDialog.prompt('Image URL (https://…):', 'https://').then(function (src) {
              if (src && /^https?:\/\//i.test(src)) { editor.chain().focus().setImage({ src: src }).run(); }
            });
            break;
          }
          case 'table': editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); break;
          case 'tableAddRow': editor.chain().focus().addRowAfter().run(); break;
          case 'tableDeleteRow': editor.chain().focus().deleteRow().run(); break;
          case 'tableAddCol': editor.chain().focus().addColumnAfter().run(); break;
          case 'tableDeleteCol': editor.chain().focus().deleteColumn().run(); break;
          case 'tableDelete': editor.chain().focus().deleteTable().run(); break;
          case 'clear': editor.chain().focus().clearNodes().unsetAllMarks().run(); break;
        }
      });
    });

    // Colour / highlight inputs.
    shell.querySelectorAll('input[data-role=color]').forEach(function (i) {
      i.addEventListener('input', function () { editor.chain().focus().setColor(i.value).run(); });
    });
    shell.querySelectorAll('input[data-role=highlight]').forEach(function (i) {
      i.addEventListener('input', function () { editor.chain().focus().toggleHighlight({ color: i.value }).run(); });
    });

    // Reflect active state on buttons.
    editor.on('transaction', function () {
      shell.querySelectorAll('.rt-btn[data-a]').forEach(function (b) {
        var a = b.dataset.a; var v = b.dataset.v;
        var on = false;
        if (a === 'bold') on = editor.isActive('bold');
        else if (a === 'italic') on = editor.isActive('italic');
        else if (a === 'underline') on = editor.isActive('underline');
        else if (a === 'strike') on = editor.isActive('strike');
        else if (a === 'subscript') on = editor.isActive('subscript');
        else if (a === 'superscript') on = editor.isActive('superscript');
        else if (a === 'code') on = editor.isActive('code');
        else if (a === 'codeBlock') on = editor.isActive('codeBlock');
        else if (a === 'blockquote') on = editor.isActive('blockquote');
        else if (a === 'bulletList') on = editor.isActive('bulletList');
        else if (a === 'orderedList') on = editor.isActive('orderedList');
        else if (a === 'taskList') on = editor.isActive('taskList');
        else if (a === 'heading') on = editor.isActive('heading', { level: parseInt(v, 10) });
        else if (a === 'paragraph') on = editor.isActive('paragraph');
        else if (a === 'align') on = editor.isActive({ textAlign: v });
        b.classList.toggle('is-active', on);
      });
    });
  }

  initEditor('terms');
  initEditor('privacy');

  document.querySelectorAll('[data-reset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      LVCDialog.confirm('Replace the current ' + btn.dataset.reset.replace('-', ' ') + ' with the US/Nevada boilerplate?').then(function (ok) {
        if (!ok) return;
        document.getElementById('legal-reset-which').value = btn.dataset.reset;
        document.getElementById('legal-reset-form').submit();
      });
    });
  });
})();
</script>

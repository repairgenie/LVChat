<?php $title = 'Support'; $active = 'support'; ?>
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
  .rt-content .tiptap blockquote { border-left: 3px solid rgb(88 101 242 / .6); margin: 8px 0; padding: 2px 12px; color: var(--c-d-300); }
  .rt-content .tiptap hr { border: none; border-top: 1px solid var(--c-d-600); margin: 12px 0; }
  .rt-content .tiptap pre { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 6px; padding: 10px 12px; overflow-x: auto; }
  .rt-content .tiptap code { background: var(--c-d-900); border: 1px solid var(--c-d-700); border-radius: 4px; padding: 1px 4px; }
  .rt-content .tiptap pre code { background: none; border: none; padding: 0; }
</style>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Support tickets</h1>
  <button id="new-ticket-toggle" class="btn-primary text-xs">＋ Open a ticket</button>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div id="new-ticket-form" class="hidden card p-5 mb-5 max-w-3xl">
  <h2 class="font-semibold text-white mb-3">Open a ticket</h2>
  <form method="post" action="/admin/support/create" enctype="multipart/form-data" class="space-y-4">
    <?= Csrf::field() ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div>
        <label class="label">Linked user (optional)</label>
        <select name="user_id" id="ticket-user" class="input !py-1.5">
          <option value="">— none (use email instead) —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>"><?= h($u['username']) ?><?= $u['email'] ? ' · ' . h($u['email']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="label">Or contact email</label>
        <input class="input" type="email" name="email" placeholder="customer@example.com" autocomplete="off">
      </div>
    </div>
    <div>
      <label class="label">Subject</label>
      <input class="input" name="subject" required maxlength="120" placeholder="Brief summary">
    </div>
    <div>
      <label class="label">Description</label>
      <div class="rt-shell" data-editor="admin-ticket">
        <div class="rt-toolbar" data-toolbar="admin-ticket"></div>
        <div class="rt-content" data-content="admin-ticket"></div>
      </div>
      <textarea name="content" id="admin-ticket-content" class="hidden" required></textarea>
    </div>
    <div>
      <label class="label">Attachments (optional)</label>
      <input type="file" name="attachments[]" multiple accept="image/*,.txt,.pdf,.docx,.odt" class="input !p-1.5">
      <p class="text-xs text-discord-400 mt-1">Up to 5 files, max 25 MB each. Images, TXT, PDF, DOCX, ODT.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-end">
      <div>
        <label class="label">Assign to</label>
        <select name="assigned_to" class="input !py-1.5">
          <option value="">— unassigned —</option>
          <?php foreach ($staff as $s): ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === (int) $admin['id'] ? 'selected' : '' ?>><?= h($s['username']) ?> (<?= h($s['role']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn-primary justify-center">Create ticket</button>
    </div>
    <p class="text-xs text-discord-500">Provide a linked user <em>or</em> an email. If you type an email that belongs to a registered account, it links automatically. Replies to the ticket are emailed to the contact when staff respond.</p>
  </form>
</div>

<div class="flex flex-wrap gap-1 mb-4 items-center">
  <?php $filters = ['' => 'All', 'open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed']; ?>
  <?php foreach ($filters as $s => $label): ?>
  <a href="/admin/support<?= $s !== '' ? '?status=' . $s . ($assignee !== '' ? '&assignee=' . $assignee : '') : ($assignee !== '' ? '?assignee=' . $assignee : '') ?>" class="px-2.5 py-1 rounded-md text-sm <?= ($status === $s) ? 'bg-blurple text-white' : 'bg-discord-750 text-discord-300 hover:text-white' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span class="mx-2 text-discord-600">|</span>
  <?php $aFilters = ['' => 'All', 'mine' => 'Mine', 'unassigned' => 'Unassigned']; ?>
  <?php foreach ($aFilters as $a => $label): ?>
  <a href="/admin/support<?= $a !== '' ? '?assignee=' . $a . ($status !== '' ? '&status=' . $status : '') : ($status !== '' ? '?status=' . $status : '') ?>" class="px-2.5 py-1 rounded-md text-sm <?= ($assignee === $a) ? 'bg-blurple text-white' : 'bg-discord-750 text-discord-300 hover:text-white' ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$tickets): ?>
<div class="card p-6 text-sm text-discord-400">No tickets<?= $status !== '' ? ' with status ' . h($status) : '' ?><?= $assignee !== '' ? ' (assignee: ' . h($assignee) . ')' : '' ?>.</div>
<?php endif; ?>

<div class="card overflow-x-auto">
  <table class="w-full text-sm">
    <thead>
      <tr class="text-left text-xs text-discord-400 border-b border-discord-700">
        <th class="px-4 py-2">Ticket</th>
        <th class="px-4 py-2">Subject</th>
        <th class="px-4 py-2">Contact</th>
        <th class="px-4 py-2">Status</th>
        <th class="px-4 py-2">Assignee</th>
        <th class="px-4 py-2">Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tickets as $t): ?>
      <tr class="border-b border-discord-800">
        <td class="px-4 py-2"><a href="/admin/support/<?= (int) $t['id'] ?>" class="text-blurple hover:underline">#<?= (int) $t['id'] ?></a></td>
        <td class="px-4 py-2 text-white"><?= h($t['subject']) ?></td>
        <td class="px-4 py-2">
          <?php if ($t['user_id'] !== null): ?>
          <a href="/admin/users/<?= (int) $t['user_id'] ?>" class="hover:underline"><?= h($t['username']) ?></a>
          <?php else: ?>
          <span class="text-discord-300"><?= h($t['email']) ?: '<em class="text-discord-500">email only</em>' ?></span>
          <?php endif; ?>
        </td>
        <td class="px-4 py-2">
          <span class="px-1.5 py-0.5 rounded text-[11px] <?= $t['status'] === 'open' ? 'bg-red-500/20 text-red-400' : ($t['status'] === 'answered' ? 'bg-amber-500/20 text-amber-300' : 'bg-discord-700 text-discord-400') ?>"><?= h($t['status']) ?></span>
        </td>
        <td class="px-4 py-2">
          <form method="post" action="/admin/support/<?= (int) $t['id'] ?>/assign" class="flex items-center gap-1">
            <?= Csrf::field() ?>
            <select name="assigned_to" class="assign-select text-xs bg-discord-750 border border-discord-600 rounded px-1.5 py-0.5" onchange="this.form.submit()">
              <option value="">Unassigned</option>
              <?php foreach ($staff as $s): ?>
              <option value="<?= (int) $s['id'] ?>" <?= (int) $t['assigned_to'] === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
        </td>
        <td class="px-4 py-2 text-discord-400"><?= h(relative_time($t['updated_at'])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script src="/assets/vendor/tiptap/tiptap-bundle.js"></script>
<script>
(function () {
  var toggle = document.getElementById('new-ticket-toggle');
  var form = document.getElementById('new-ticket-form');
  if (toggle && form) toggle.addEventListener('click', function () { var h = form.classList.toggle('hidden'); toggle.textContent = (h ? '＋ Open a ticket' : '✕ Cancel'); });

  var bundle = window.TiptapBundle;
  if (!bundle) {
    var ta = document.getElementById('admin-ticket-content');
    if (ta) { ta.classList.remove('hidden'); ta.rows = 5; ta.className += ' input'; var shell = document.querySelector('[data-editor="admin-ticket"]'); if (shell) shell.style.display = 'none'; }
    return;
  }
  function btn(label, title) { var b = document.createElement('button'); b.type = 'button'; b.className = 'rt-btn'; b.innerHTML = label; b.title = title || ''; return b; }
  function group() { var g = document.createElement('div'); g.className = 'rt-group'; return g; }
  var bar = document.querySelector('[data-toolbar="admin-ticket"]');
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
  var contentEl = document.querySelector('[data-content="admin-ticket"]');
  var ta = document.getElementById('admin-ticket-content');
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

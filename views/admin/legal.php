<?php $title = 'Terms & Privacy'; $active = 'legal'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Terms of Service &amp; Privacy Policy</h1>
  <a href="/terms" target="_blank" class="btn-ghost text-xs">Preview /terms</a>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div class="card p-5 mb-5 max-w-3xl">
  <p class="text-sm text-discord-400">Edit the site's legal pages with the rich-text editor. They are linked from the account menu in the chat sidebar and from the login/register pages. Boilerplate is based on US federal law and Nevada statutes (NRS 597.790 / NRS 597.795).</p>
</div>

<form method="post" action="/admin/action" id="legal-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/legal">

  <div class="card p-5 mb-6 max-w-4xl">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-white">Terms of Service</h2>
      <div class="flex gap-2">
        <button type="button" class="btn-ghost text-xs !py-1" data-reset="terms">Reset to boilerplate</button>
      </div>
    </div>
    <div class="tiptap-shell" data-target="terms"><?= $terms ?></div>
    <textarea name="terms" id="legal-terms" class="hidden"></textarea>
  </div>

  <div class="card p-5 mb-6 max-w-4xl">
    <div class="flex items-center justify-between mb-3">
      <h2 class="font-semibold text-white">Privacy Policy</h2>
      <button type="button" class="btn-ghost text-xs !py-1" data-reset="privacy">Reset to boilerplate</button>
    </div>
    <div class="tiptap-shell" data-target="privacy"><?= $privacy ?></div>
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
    document.querySelectorAll('.tiptap-shell').forEach(function (shell) {
      var ta = document.getElementById('legal-' + shell.dataset.target);
      ta.classList.remove('hidden');
      ta.rows = 20;
      ta.className += ' input font-mono mt-2';
      ta.value = shell.innerHTML;
      shell.style.display = 'none';
    });
    return;
  }
  var editors = {};
  document.querySelectorAll('.tiptap-shell').forEach(function (shell) {
    var which = shell.dataset.target;
    var ta = document.getElementById('legal-' + which);
    var editor = new bundle.Editor({
      element: shell,
      content: shell.innerHTML,
      extensions: [
        bundle.StarterKit,
        bundle.Link.configure({ openOnClick: false, autolink: true }),
        bundle.Underline,
        bundle.Placeholder.configure({ placeholder: 'Write ' + which.replace('-', ' ') + '…' })
      ],
      editorProps: {
        attributes: { class: 'tiptap' }
      },
      onUpdate: function () { ta.value = editor.getHTML(); }
    });
    editors[which] = editor;
    ta.value = editor.getHTML();
  });
  document.querySelectorAll('[data-reset]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('Replace the current ' + btn.dataset.reset.replace('-', ' ') + ' with the US/Nevada boilerplate?')) return;
      document.getElementById('legal-reset-which').value = btn.dataset.reset;
      document.getElementById('legal-reset-form').submit();
    });
  });
})();
</script>

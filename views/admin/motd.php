<?php $title = 'MOTD'; $active = 'motd'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Message of the day</h1>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-4">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/motd">
  <div>
    <label class="label">MOTD text</label>
    <textarea name="motd" rows="8" class="input font-mono" placeholder="Welcome…"><?= h($motd) ?></textarea>
    <p class="text-xs text-discord-400 mt-1">Shown at the top of every chat window. Blank lines are fine.</p>
  </div>
  <button name="action" value="motd_save" class="btn-primary">Save MOTD</button>
</form>

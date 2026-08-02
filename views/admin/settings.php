<?php $title = 'Settings'; $active = 'settings'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Server settings</h1>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>
<form method="post" action="/admin/action" class="card p-6 max-w-2xl space-y-5">
  <?= Csrf::field() ?>
  <input type="hidden" name="back" value="/admin/settings">
  <div>
    <label class="label">Site name</label>
    <input class="input" name="site_name" value="<?= h($settings['site_name']) ?>">
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Registration</div>
      <div class="text-xs text-discord-400">Allow new accounts to be created</div>
    </div>
    <input type="checkbox" name="registration_enabled" value="1" class="w-5 h-5 accent-blurple" <?= $settings['registration_enabled'] === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Spam filters</div>
      <div class="text-xs text-discord-400">Apply active spam filters to messages</div>
    </div>
    <input type="checkbox" name="spamfilter_enabled" value="1" class="w-5 h-5 accent-blurple" <?= $settings['spamfilter_enabled'] === '1' ? 'checked' : '' ?>>
  </div>
  <div>
    <label class="label">Max channels per user</label>
    <input class="input" type="number" min="1" name="max_channels_per_user" value="<?= (int) $settings['max_channels_per_user'] ?>">
  </div>
  <button name="action" value="settings_save" class="btn-primary">Save settings</button>
</form>

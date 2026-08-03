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
  <div>
    <label class="label">Site logo URL</label>
    <input class="input" name="logo_url" value="<?= h($settings['logo_url']) ?>" placeholder="https://example.com/logo.png">
    <p class="text-xs text-discord-400 mt-1">Shown in place of the site name in the header and login pages. Leave empty to use the default mark.</p>
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
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Image uploads</div>
      <div class="text-xs text-discord-400">Allow members to upload and post images in channels</div>
    </div>
    <input type="checkbox" name="uploads_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['uploads_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Emoji reactions</div>
      <div class="text-xs text-discord-400">Allow reactions on messages</div>
    </div>
    <input type="checkbox" name="reactions_enabled" value="1" class="w-5 h-5 accent-blurple" <?= ($settings['reactions_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
  </div>
  <div class="flex items-center justify-between card p-4">
    <div>
      <div class="text-sm font-medium text-white">Realtime mode</div>
      <div class="text-xs text-discord-400">SSE streams live updates but holds a PHP worker per client — best on a VPS/php-fpm. Polling is the shared-hosting default.</div>
    </div>
    <select name="realtime" class="input w-36 !py-1.5">
      <option value="poll" <?= ($settings['realtime'] ?? 'poll') === 'poll' ? 'selected' : '' ?>>Polling</option>
      <option value="sse" <?= ($settings['realtime'] ?? 'poll') === 'sse' ? 'selected' : '' ?>>SSE</option>
    </select>
  </div>
  <div>
    <label class="label">Max channels per user</label>
    <input class="input" type="number" min="1" name="max_channels_per_user" value="<?= (int) $settings['max_channels_per_user'] ?>">
  </div>
  <div class="grid grid-cols-2 gap-4">
    <div>
      <label class="label">Poll interval (seconds)</label>
      <input class="input" type="number" min="1" name="poll_interval" value="<?= (int) $settings['poll_interval'] ?>">
      <p class="text-xs text-discord-400 mt-1">How often clients fetch new messages. Higher = far fewer requests on shared hosting.</p>
    </div>
    <div>
      <label class="label">Presence throttle (seconds)</label>
      <input class="input" type="number" min="5" name="presence_throttle" value="<?= (int) $settings['presence_throttle'] ?>">
      <p class="text-xs text-discord-400 mt-1">How often the server writes "last seen" per user. Most polls become pure reads.</p>
    </div>
  </div>
  <button name="action" value="settings_save" class="btn-primary">Save settings</button>
</form>

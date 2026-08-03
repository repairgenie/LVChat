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
    <form method="post" action="/support" class="space-y-3">
      <?= Csrf::field() ?>
      <div>
        <label class="label">Subject</label>
        <input class="input" name="subject" required maxlength="120" value="<?= h($old['subject'] ?? '') ?>" placeholder="Brief summary of your issue">
      </div>
      <div>
        <label class="label">Description</label>
        <textarea class="input" name="content" rows="5" required placeholder="Tell us what happened. Include any relevant usernames or channel names."><?= h($old['content'] ?? '') ?></textarea>
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

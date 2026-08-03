<?php $title = 'Support'; $active = 'support'; ?>
<div class="flex items-center justify-between mb-4">
  <h1 class="text-2xl font-bold text-white">Support tickets</h1>
  <button id="new-ticket-toggle" class="btn-primary text-xs">＋ Open a ticket</button>
</div>
<?php require ROOT . '/views/admin/_nav.php'; ?>

<div id="new-ticket-form" class="hidden card p-5 mb-5 max-w-3xl">
  <h2 class="font-semibold text-white mb-3">Open a ticket</h2>
  <form method="post" action="/admin/support/create" class="space-y-4">
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
      <textarea class="input" name="content" rows="5" required placeholder="What is this about?"></textarea>
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

<script>
(function () {
  var toggle = document.getElementById('new-ticket-toggle');
  var form = document.getElementById('new-ticket-form');
  if (toggle && form) toggle.addEventListener('click', function () { form.classList.toggle('hidden'); });
})();
</script>

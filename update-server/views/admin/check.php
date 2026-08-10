<?php $pageTitle = 'URL check'; ?>
<div class="card">
  <h2>Download URL check</h2>
  <table>
    <tr><th>Entry</th><th>Status</th><th>URL</th></tr>
    <?php foreach ($results as $r): ?>
    <tr>
      <td class="mono"><?= h($r['name']) ?></td>
      <td><?= $r['ok'] ? '<span class="tag ok">reachable</span>' : '<span class="tag err">unreachable</span>' ?></td>
      <td class="mono" style="word-break:break-all"><?= h($r['url']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div style="margin-top:14px"><a class="btn ghost" href="/admin">Back</a></div>
</div>

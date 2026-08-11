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

 $pageTitle = 'URL check'; ?>
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

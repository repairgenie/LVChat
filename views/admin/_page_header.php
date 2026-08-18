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


// Standardized admin page header. Pages set $active (via admin/_nav.php),
// $pageTitle (falls back to $title), $pageSubtitle and $pageActions (raw HTML).
?>
<div class="admin-page-head">
  <div class="min-w-0">
    <h1 class="admin-page-title"><?= h($pageTitle ?? $title ?? 'Admin dashboard') ?></h1>
    <?php if (!empty($pageSubtitle)): ?><p class="admin-page-subtitle"><?= h($pageSubtitle) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($pageActions)): ?>
  <div class="flex items-center gap-2 flex-wrap shrink-0"><?= $pageActions ?></div>
  <?php endif; ?>
</div>
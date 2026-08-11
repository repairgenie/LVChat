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

 $pageTitle = 'Sign in'; ?>
<div class="card" style="max-width:420px;margin:40px auto">
  <h2>Update server admin</h2>
  <?php if (!admin_configured()): ?>
    <p class="muted">No admin password is configured yet.</p>
  <?php else: ?>
    <form method="post" action="/admin">
      <?= Csrf::field() ?>
      <label>Password</label>
      <input type="password" name="password" autocomplete="current-password" autofocus>
      <div style="margin-top:14px"><button class="btn" type="submit">Sign in</button></div>
    </form>
  <?php endif; ?>
</div>

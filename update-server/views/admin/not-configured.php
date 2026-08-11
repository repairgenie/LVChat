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

 $pageTitle = 'Not configured'; ?>
<div class="card" style="max-width:560px;margin:40px auto">
  <h2>Update server not configured</h2>
  <p>Copy <code>config.sample.php</code> to <code>config.php</code> and set an admin password:</p>
  <pre style="background:#1e1f22;padding:12px;border-radius:8px;overflow-x:auto"><code>php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), PHP_EOL;'</code></pre>
  <p class="muted">Put the output in <code>admin_pass_hash</code> (or set <code>admin_pass</code> directly for a plaintext password).</p>
</div>

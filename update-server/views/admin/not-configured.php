<?php $pageTitle = 'Not configured'; ?>
<div class="card" style="max-width:560px;margin:40px auto">
  <h2>Update server not configured</h2>
  <p>Copy <code>config.sample.php</code> to <code>config.php</code> and set an admin password:</p>
  <pre style="background:#1e1f22;padding:12px;border-radius:8px;overflow-x:auto"><code>php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), PHP_EOL;'</code></pre>
  <p class="muted">Put the output in <code>admin_pass_hash</code> (or set <code>admin_pass</code> directly for a plaintext password).</p>
</div>

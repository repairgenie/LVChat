<?php $pageTitle = 'Sign in'; ?>
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

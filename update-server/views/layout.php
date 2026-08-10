<?php $siteName = config_value('site_name', 'LVChat Updates'); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($siteName) ?><?= isset($pageTitle) ? ' — ' . h($pageTitle) : '' ?></title>
<style>
  :root { color-scheme: dark; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #1e1f22; color: #e6e6e6; font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  a { color: #5865f2; text-decoration: none; }
  a:hover { text-decoration: underline; }
  header { background: #2b2d31; border-bottom: 1px solid #1a1b1e; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  header .brand { font-weight: 700; font-size: 16px; color: #fff; }
  main { max-width: 980px; margin: 24px auto; padding: 0 20px 60px; }
  .card { background: #2b2d31; border: 1px solid #3f4147; border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; }
  .card h2 { margin: 0 0 10px; font-size: 17px; color: #fff; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #3f4147; vertical-align: top; }
  th { color: #949ba4; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
  code, .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; }
  .muted { color: #949ba4; font-size: 13px; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .tag.ok { background: #134d2a; color: #7ef0a2; }
  .tag.warn { background: #4d3b12; color: #f5c26b; }
  .tag.err { background: #4d1a1a; color: #f99; }
  .btn { display: inline-block; background: #5865f2; color: #fff; border: 0; border-radius: 6px; padding: 8px 14px; font-size: 14px; cursor: pointer; }
  .btn:hover { background: #4752c4; text-decoration: none; }
  .btn.ghost { background: transparent; color: #b5bac1; border: 1px solid #3f4147; }
  .btn.ghost:hover { background: #3f4147; }
  .btn.danger { background: #da373c; }
  .btn.danger:hover { background: #b02a2f; }
  form label { display: block; font-size: 12px; font-weight: 600; color: #b5bac1; margin: 10px 0 4px; text-transform: uppercase; letter-spacing: .04em; }
  input[type=text], input[type=url], input[type=password], textarea { width: 100%; background: #1e1f22; border: 1px solid #3f4147; border-radius: 6px; color: #e6e6e6; padding: 8px 10px; font-size: 14px; }
  input.mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 13px; }
  .row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .flash { background: #134d2a; border: 1px solid #2a8a4a; color: #b8f7cd; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
  .flash.warn { background: #4d3b12; border-color: #8a6d2a; color: #fbe3b8; }
  footer { text-align: center; color: #6d7178; font-size: 12px; padding: 20px; }
  .section-label { font-size: 13px; font-weight: 700; color: #949ba4; text-transform: uppercase; letter-spacing: .06em; margin: 22px 0 8px; }
</style>
</head>
<body>
<header>
  <div class="brand"><?= h($siteName) ?></div>
  <nav class="row">
    <?php if (!empty($_SESSION['update_admin'])): ?>
      <a href="/admin">Dashboard</a>
      <form method="post" action="/admin/logout" style="display:inline"><?= Csrf::field() ?><button class="btn ghost" style="padding:4px 10px">Sign out</button></form>
    <?php else: ?>
      <a href="/manifest.json">manifest.json</a>
      <a href="/admin">Admin</a>
    <?php endif; ?>
  </nav>
</header>
<main>
  <?php $__flash = flash(); if ($__flash !== null): ?>
    <div class="flash"><?= h($__flash) ?></div>
  <?php endif; ?>
  <?php if (isset($content)): ?>
    <?= $content ?>
  <?php endif; ?>
</main>
<footer>
  LVChat Update Server — version manifest + electron-updater feeds for LVChat Web, Desktop &amp; Messenger.
</footer>
</body>
</html>

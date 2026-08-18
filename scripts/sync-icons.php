<?php
/* One-off sync script: regenerate public/assets/js/icons.js et al. from src/Icons.php.
 * Keeps the JS icon map byte-identical to the PHP one. Run: php scripts/sync-icons.php */

$src = file_get_contents(__DIR__ . '/../src/Icons.php');
preg_match('/const ICON_SVG = \[(.*?)\];/s', $src, $m);
if (!$m) {
    fwrite(STDERR, "ICON_SVG block not found\n");
    exit(1);
}
$body = $m[1];

$js = <<<'JS'
/* Auto-mirrored from src/Icons.php — edit icons there, then run scripts/sync-icons.php. */
window.LVC_ICONS = {
__BODY__
};

window.icon = function (name, cls) {
  cls = cls || '';
  var body = window.LVC_ICONS[name] || '';
  if (!body) return '';
  return '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' +
    (cls ? ' class="' + cls + '"' : '') + ' aria-hidden="true">' + body + '</svg>';
};

JS;

/* PHP array syntax ('k' => 'v') → JS object syntax ('k': 'v'). Values are
   single-quoted and never contain unescaped quotes, so a plain swap is safe. */
$jsBody = preg_replace("/=>/", ':', $body);
$js = str_replace('__BODY__', $jsBody, $js);

$targets = [
    __DIR__ . '/../public/assets/js/icons.js',
    __DIR__ . '/../messenger-web/src/icons.js',
    __DIR__ . '/../lvchat-messenger/renderer/icons.js',
];
foreach ($targets as $t) {
    file_put_contents($t, $js);
    echo "wrote $t\n";
}

/* Desktop launcher is CSP-env (style-src only, no external js besides launcher.js):
   also emit the map for inlining into launcher.js if it ever needs icons. */
$ts = file_get_contents($targets[0]);
echo "OK (" . substr_count($body, "'") . " quotes in body)\n";
<?php
// Emits the effective theme's CSS into <head> — palettes (dark `:root` + light
// `html.light`), the chosen font stack, and the chat message-area background.
// Must be included AFTER the Tailwind stylesheet so the var redefinitions win.
// The `$channel` (if present, from the chat app) supplies the owner-set channel
// background, which overrides the theme's chat background while viewing it.
$themeViewUser = isset($user) && is_array($user) ? $user : null;
$themeRendered = ThemeService::effectiveForView($themeViewUser);
$themeCss = ThemeService::cssVars($themeRendered) . "\n" . ThemeService::chatBgCss($themeRendered, $channel ?? null);
?>
<style id="theme-css"><?= $themeCss ?></style>

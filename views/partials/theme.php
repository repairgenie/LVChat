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


// Emits the effective theme's CSS into <head> — palettes (dark `:root` + light
// `html.light`), the chosen font stack, and the chat message-area background.
// Must be included AFTER the Tailwind stylesheet so the var redefinitions win.
// The `$channel` (if present, from the chat app) supplies the owner-set channel
// background, which overrides the theme's chat background while viewing it.
$themeViewUser = isset($user) && is_array($user) ? $user : null;
$themeRendered = ThemeService::effectiveForView($themeViewUser);
$themeChannel = isset($channel) && is_array($channel) ? $channel : null;
$themeCss = ThemeService::cssVars($themeRendered) . "\n" . ThemeService::chatBgCss($themeRendered, $themeChannel);
?>
<style id="theme-css"><?= $themeCss ?></style>

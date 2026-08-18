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

 // Compiled Tailwind CSS. No CDN, no server-side build — the file ships with the app.
// Regenerate locally with `npm run build` whenever views change. Cache-busted by mtime. ?>
<link rel="preload" as="font" type="font/woff2" crossorigin href="/assets/fonts/InterVariable.woff2">
<link rel="preload" as="font" type="font/woff2" crossorigin href="/assets/fonts/InterVariable-Italic.woff2">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= (int) @filemtime(ROOT . '/public/assets/css/app.css') ?>">
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


// Shared header overflow dropdown for the chat app.
// Renders a ⋮ button + floating menu that is visible only on small screens.
// Available on all three header states (channel, DM, empty); the "Part"
// button is only meaningful when $channel is set.
?>
<div class="relative shrink-0" id="header-menu-wrap">
  <button id="header-menu-btn" class="btn-ghost !p-1.5 md:hidden" title="Channel options" aria-label="Open channel menu"><?= icon('more-v', 'w-4 h-4') ?></button>
  <div id="header-menu" class="hidden absolute top-10 right-0 w-60 card p-1.5 shadow-2xl z-[65] space-y-0.5">
    <!-- Search (mobile) — reveals the existing search input in-place -->
    <?php if ($channel): ?>
    <button class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2" id="mobile-search"><?= icon('search', 'w-4 h-4 text-discord-400') ?> Search</button>
    <?php endif; ?>
    <!-- Theme toggle -->
    <button id="header-theme-toggle" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2" data-theme-label><?= icon('moon', 'w-4 h-4 text-discord-400') ?> <span>Dark mode</span></button>
    <!-- Mobile: show friends & members panel -->
    <button id="right-panel-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 md:hidden flex items-center gap-2"><?= icon('users', 'w-4 h-4 text-discord-400') ?> Friends &amp; Members</button>
    <?php if ($channel): ?>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="share-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('link', 'w-4 h-4 text-discord-400') ?> Share link</button>
    <button id="pin-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 inline-flex items-center gap-2" title="Pinned messages"><?= icon('pin', 'w-4 h-4 text-discord-400') ?> Pinned messages</button>
    <?php if ($canManageSettings): ?>
    <button id="chan-settings-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('gear', 'w-4 h-4 text-discord-400') ?> Channel settings</button>
    <?php endif; ?>
    <button id="mute-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2" data-mode="<?= h($notifyMode) ?>"><?= icon('bell', 'w-4 h-4 text-discord-400') ?> Mute</button>
    <button type="button" data-embed class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('code', 'w-4 h-4 text-discord-400') ?> Embed</button>
    <button id="install-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('download', 'w-4 h-4 text-discord-400') ?> How to install</button>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="part-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-red-400 flex items-center gap-2"><?= icon('log-out', 'w-4 h-4') ?> Leave channel</button>
    <?php elseif ($dm): ?>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="dm-profile-btn" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2" data-username="<?= h($dm['username']) ?>"><?= icon('user', 'w-4 h-4 text-discord-400') ?> View profile</button>
    <button id="dm-mute-btn" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2" data-user-id="<?= (int) $dm['id'] ?>"><?= icon('bell-off', 'w-4 h-4 text-discord-400') ?> Mute</button>
    <button id="dm-block-btn" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-red-400 flex items-center gap-2" data-username="<?= h($dm['username']) ?>"><?= icon('eye-off', 'w-4 h-4') ?> Block user</button>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="install-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('download', 'w-4 h-4 text-discord-400') ?> How to install</button>
    <?php else: ?>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="install-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 flex items-center gap-2"><?= icon('download', 'w-4 h-4 text-discord-400') ?> How to install</button>
    <?php endif; ?>
  </div>
</div>

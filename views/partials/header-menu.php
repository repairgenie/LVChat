<?php
// Shared header overflow dropdown for the chat app.
// Renders a ⋮ button + floating menu that is visible only on small screens.
// Available on all three header states (channel, DM, empty); the "Part"
// button is only meaningful when $channel is set.
?>
<div class="relative shrink-0" id="header-menu-wrap">
  <button id="header-menu-btn" class="btn-ghost !p-1.5 text-lg leading-none md:hidden" title="Channel options" aria-label="Open channel menu">⋮</button>
  <div id="header-menu" class="hidden absolute top-10 right-0 w-60 card p-1.5 shadow-2xl z-50 space-y-0.5">
    <!-- Search (mobile) — reveals the existing search input in-place -->
    <?php if ($channel): ?>
    <button class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200" id="mobile-search">🔍 Search</button>
    <?php endif; ?>
    <!-- Theme toggle -->
    <button id="header-theme-toggle" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200">🌙 Dark mode</button>
    <!-- Mobile: show friends & members panel -->
    <button id="right-panel-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200 md:hidden">👥 Friends &amp; Members</button>
    <?php if ($channel): ?>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="share-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200">🔗 Share link</button>
    <button id="mute-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200" data-mode="<?= h($notifyMode) ?>">🔔 Mute</button>
    <button type="button" data-embed class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200">&lt;/&gt; Embed</button>
    <button id="install-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200">⬇ How to install</button>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="part-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-red-400">✕ Leave channel</button>
    <?php else: ?>
    <div class="h-px bg-discord-700 my-1"></div>
    <button id="install-btn-m" class="dropdown-close w-full text-left px-2.5 py-1.5 rounded-md hover:bg-discord-600/50 text-sm text-discord-200">⬇ How to install</button>
    <?php endif; ?>
  </div>
</div>

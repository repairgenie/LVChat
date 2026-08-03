<?php $title = $title; ?>
<div class="card p-8 max-w-3xl mx-auto">
  <div class="prose-legal"><?= $body ?></div>
</div>
<style>
.prose-legal { line-height: 1.7; color: var(--c-d-200); font-size: 15px; }
.prose-legal h1 { font-size: 24px; font-weight: 700; color: var(--c-d-100); margin: 0 0 8px; }
.prose-legal h2 { font-size: 17px; font-weight: 600; color: var(--c-d-100); margin: 28px 0 8px; }
.prose-legal h3 { font-size: 15px; font-weight: 600; color: var(--c-d-100); margin: 20px 0 6px; }
.prose-legal p { margin: 10px 0; }
.prose-legal ul, .prose-legal ol { margin: 10px 0; padding-left: 24px; }
.prose-legal li { margin: 4px 0; }
.prose-legal a { color: var(--c-blurple); text-decoration: underline; }
.prose-legal code { background: var(--c-d-850); padding: 1px 5px; border-radius: 4px; font-size: 13px; }
.prose-legal pre { background: var(--c-d-850); padding: 12px; border-radius: 6px; overflow-x: auto; }
.prose-legal blockquote { border-left: 3px solid var(--c-blurple); padding-left: 12px; color: var(--c-d-300); margin: 10px 0; }
</style>

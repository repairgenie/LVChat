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

 $title = $title; ?>
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
.prose-legal table { border-collapse: collapse; width: 100%; margin: 12px 0; }
.prose-legal th, .prose-legal td { border: 1px solid var(--c-d-600); padding: 6px 10px; text-align: left; }
.prose-legal th { background: var(--c-d-850); }
.prose-legal img { max-width: 100%; border-radius: 6px; }
.prose-legal hr { border: none; border-top: 1px solid var(--c-d-600); margin: 16px 0; }
.prose-legal ul[data-type="taskList"] { list-style: none; padding-left: 0; }
.prose-legal ul[data-type="taskList"] li { display: flex; gap: 8px; align-items: flex-start; }
.prose-legal ul[data-type="taskList"] li[data-checked="true"] { text-decoration: line-through; opacity: .7; }
.prose-legal h5, .prose-legal h6 { font-size: 14px; font-weight: 600; color: var(--c-d-100); margin: 16px 0 6px; }
.prose-legal s { text-decoration: line-through; }
.prose-legal mark { background-color: #fef08a; color: #000; padding: 0 2px; border-radius: 2px; }
.prose-legal input[type="checkbox"] { accent-color: var(--c-blurple); }
</style>

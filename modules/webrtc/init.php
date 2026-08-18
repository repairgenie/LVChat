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



/**
 * WebRTC Voice — boot hook. Must stay side-effect-free (no output/headers/exit):
 * it only loads the module's classes and registers its slash commands. Runs on
 * every request including the Workerman daemon. See docs/modules.md.
 */

// Guarded against re-boots (the test suite re-boots modules; a production
// module runs init.php once per process, so plain require is fine — but the
// guard keeps both safe).
if (!class_exists('LiveKitService')) {
    require __DIR__ . '/LiveKitService.php';
}
if (!class_exists('VoiceController')) {
    require __DIR__ . '/VoiceController.php';
}
if (!class_exists('CallController')) {
    require __DIR__ . '/CallController.php';
}
if (!class_exists('ModerationController')) {
    require __DIR__ . '/ModerationController.php';
}
if (!class_exists('RecordingController')) {
    require __DIR__ . '/RecordingController.php';
}
if (!class_exists('EventController')) {
    require __DIR__ . '/EventController.php';
}
if (!class_exists('AdminVoiceController')) {
    require __DIR__ . '/AdminVoiceController.php';
}

// Note: no slash commands are registered here — the core `/voice` command
// (IRC +v channel mode) owns that name, and joining voice is driven by the
// module's header button (assets/js/voice.js) and the /api/webrtc/* endpoints.

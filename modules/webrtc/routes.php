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
 * WebRTC Voice — module routes. Wired by ModuleLoader::wireRoutes() after the
 * core routes (core always wins on a path collision). See docs/modules.md.
 */

return static function (Router $router): void {
    // Channel voice + shared status.
    $router->get('/api/webrtc/voice/status', [VoiceController::class, 'status']);
    $router->post('/api/webrtc/voice/join', [VoiceController::class, 'join']);
    $router->post('/api/webrtc/voice/leave', [VoiceController::class, 'leave']);
    $router->post('/api/webrtc/voice/channel-voice', [VoiceController::class, 'channelVoice']);

    // One-on-one calls.
    $router->post('/api/webrtc/call/initiate', [CallController::class, 'initiate']);
    $router->post('/api/webrtc/call/accept', [CallController::class, 'accept']);
    $router->post('/api/webrtc/call/decline', [CallController::class, 'decline']);
    $router->post('/api/webrtc/call/join', [CallController::class, 'join']);
    $router->post('/api/webrtc/call/end', [CallController::class, 'end']);

    // Meeting rooms (#mtg-XXXXXX).
    $router->post('/api/webrtc/mtg/create', [MtgController::class, 'create']);
    $router->post('/api/webrtc/mtg/invite', [MtgController::class, 'invite']);
    $router->get('/mtg/{slug}', [MtgController::class, 'landing']);

    // Admin.
    $router->get('/admin/voice', [AdminVoiceController::class, 'admin']);
    $router->post('/admin/voice/save', [AdminVoiceController::class, 'save']);
};

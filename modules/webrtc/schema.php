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
 * WebRTC Voice — idempotent schema migrations (module-owned tables).
 * Runs at every boot; every statement below is safe to run repeatedly.
 *
 * The core never migrates or drops these tables — see docs/modules.md.
 */

return static function (PDO $pdo): void {
    $tables = array_column(
        $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );

    if (!in_array('voice_sessions', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE voice_sessions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
              guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
              room TEXT NOT NULL,
              kind TEXT NOT NULL DEFAULT 'channel',
              token TEXT NOT NULL,
              joined_at TEXT NOT NULL DEFAULT (datetime('now')),
              last_seen TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE(user_id, guest_id)
            )"
        );
    }

    if (!in_array('call_sessions', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE call_sessions (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              room TEXT NOT NULL UNIQUE,
              caller_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
              caller_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
              callee_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
              callee_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
              status TEXT NOT NULL DEFAULT 'ringing',
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              answered_at TEXT
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_sessions_callee ON call_sessions(callee_user_id, callee_guest_id, status)');
    }

    // Per-channel voice opt-in ("channels can be enabled as voice").
    $channels = array_column(
        $pdo->query("SELECT name FROM pragma_table_info('channels')")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    if (!in_array('voice_enabled', $channels, true)) {
        $pdo->exec('ALTER TABLE channels ADD COLUMN voice_enabled INTEGER NOT NULL DEFAULT 0');
    }

    // Meeting rooms (#mtg-XXXXXX): module-owned bookkeeping for the plaintext
    // invite key (the channels.key_hash stays the authority for verification;
    // this row lets the module rebuild invite URLs and list created meetings).
    if (!in_array('mtg_channels', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE mtg_channels (
              channel_id INTEGER PRIMARY KEY REFERENCES channels(id) ON DELETE CASCADE,
              key TEXT NOT NULL,
              created_by INTEGER,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }
};

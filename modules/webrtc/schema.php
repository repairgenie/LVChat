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

    // Per-channel waiting room (Zoom-style lobby before entering voice).
    if (!in_array('voice_waiting_room', $channels, true)) {
        $pdo->exec('ALTER TABLE channels ADD COLUMN voice_waiting_room INTEGER NOT NULL DEFAULT 0');
    }

    // Voice sessions gain waiting-room + admission-mint state.
    $vsCols = array_column($pdo->query("PRAGMA table_info('voice_sessions')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('waiting', $vsCols, true)) {
        $pdo->exec('ALTER TABLE voice_sessions ADD COLUMN waiting INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('mint', $vsCols, true)) {
        $pdo->exec('ALTER TABLE voice_sessions ADD COLUMN mint TEXT');
    }

    // Per-room lock + waiting-room state (join gate + host controls).
    if (!in_array('voice_room_flags', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE voice_room_flags (
              room TEXT PRIMARY KEY,
              locked INTEGER NOT NULL DEFAULT 0,
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }

    // Finished calls are pushed once ("missed call" nudges) then purged.
    $callCols = array_column($pdo->query("PRAGMA table_info('call_sessions')")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('missed_pushed', $callCols, true)) {
        $pdo->exec('ALTER TABLE call_sessions ADD COLUMN missed_pushed INTEGER NOT NULL DEFAULT 0');
    }

    // Group call label (1:1 calls leave it null).
    if (!in_array('title', $callCols, true)) {
        $pdo->exec("ALTER TABLE call_sessions ADD COLUMN title TEXT");
    }

    // Per-actor rate-limit buckets for voice/call/event spam guards.
    if (!in_array('rate_limits', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE rate_limits (
              bucket TEXT PRIMARY KEY,
              hits INTEGER NOT NULL DEFAULT 1,
              window_start INTEGER NOT NULL
            )"
        );
    }

    // Call recordings (LiveKit egress). One row per started recording.
    if (!in_array('recordings', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE recordings (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              room TEXT NOT NULL,
              kind TEXT NOT NULL DEFAULT 'call',          -- 'call' | 'channel' | 'event'
              egress_id TEXT,
              filename TEXT,
              size_bytes INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'starting',    -- starting|active|stopped|missing
              started_by_user_id INTEGER REFERENCES users(id),
              started_at TEXT NOT NULL DEFAULT (datetime('now')),
              stopped_at TEXT
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_recordings_room ON recordings(room)');
    }

    // Group call members: a call can grow beyond 1:1 (Discord-style invites).
    if (!in_array('call_participants', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE call_participants (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              call_id INTEGER NOT NULL REFERENCES call_sessions(id) ON DELETE CASCADE,
              user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
              guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
              status TEXT NOT NULL DEFAULT 'invited',     -- invited|joined|declined|left
              role TEXT NOT NULL DEFAULT 'member',        -- host|member
              invited_at TEXT NOT NULL DEFAULT (datetime('now')),
              joined_at TEXT,
              UNIQUE(call_id, user_id, guest_id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_participants_call ON call_participants(call_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_participants_actor ON call_participants(user_id, guest_id, status)');
    }

    // ── Events system (replaces legacy #mtg meeting rooms) ──────────────

    if (!in_array('events', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              channel_id INTEGER REFERENCES channels(id) ON DELETE SET NULL,
              founder_id INTEGER NOT NULL REFERENCES users(id),
              title TEXT NOT NULL,
              description TEXT NOT NULL DEFAULT '',
              is_public INTEGER NOT NULL DEFAULT 0,
              event_type TEXT NOT NULL DEFAULT 'webrtc',
              stream_url TEXT,
              invite_code TEXT,
              scheduled_at TEXT,
              duration_minutes INTEGER,
              started_at TEXT,
              ended_at TEXT,
              reminder_sent INTEGER NOT NULL DEFAULT 0,
              log_sent INTEGER NOT NULL DEFAULT 0,
              status TEXT NOT NULL DEFAULT 'draft',
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_status ON events(status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_scheduled ON events(scheduled_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_founder ON events(founder_id)');
    }

    // Existing installs: the end-of-event log email must be sent at most once
    // (see EventSchedulerJob::endEvents). Guarded migration, runs every boot.
    $eventCols = array_column($pdo->query("PRAGMA table_info(events)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('log_sent', $eventCols, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN log_sent INTEGER NOT NULL DEFAULT 0');
    }

    // Zoom-style waiting room flag for event meetings (host admits attendees).
    if (!in_array('waiting_room', $eventCols, true)) {
        $pdo->exec('ALTER TABLE events ADD COLUMN waiting_room INTEGER NOT NULL DEFAULT 0');
    }

    if (!in_array('event_invites', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE event_invites (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
              email TEXT NOT NULL,
              token TEXT NOT NULL UNIQUE,
              invited_by INTEGER REFERENCES users(id),
              accepted_at TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_event_invites_token ON event_invites(token)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_event_invites_event ON event_invites(event_id)');
    }

    // Per-IP landing rate limit for /event/{slug} (the slug is the private
    // event's access credential; enumeration must be throttled by IP).
    if (!in_array('event_land_limits', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE event_land_limits (
              ip TEXT PRIMARY KEY,
              hits INTEGER NOT NULL DEFAULT 1,
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }

    // Add event_id column to channels (links a channel to its event).
    if (!in_array('event_id', $channels, true)) {
        $pdo->exec('ALTER TABLE channels ADD COLUMN event_id INTEGER REFERENCES events(id)');
    }

    // Migrate legacy mtg_channels data into events table if it exists.
    if (in_array('mtg_channels', $tables, true)) {
        $pdo->exec(
            "INSERT OR IGNORE INTO events (channel_id, founder_id, title, event_type, status, invite_code, started_at, created_at)
             SELECT m.channel_id, COALESCE(m.created_by, c.owner_id, 0), c.name, 'webrtc', 'active', m.key, m.created_at, m.created_at
             FROM mtg_channels m
             LEFT JOIN channels c ON c.id = m.channel_id
             WHERE c.id IS NOT NULL"
        );
        // Link channels back to events.
        $pdo->exec(
            "UPDATE channels SET event_id = (
                SELECT e.id FROM events e WHERE e.channel_id = channels.id
            ) WHERE id IN (SELECT channel_id FROM mtg_channels)"
        );
        $pdo->exec('DROP TABLE IF EXISTS mtg_channels');
    }
};

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
 * SaaS — idempotent schema migrations (module-owned tables).
 * Runs at every boot; every statement below is safe to run repeatedly.
 * The core never migrates or drops these tables — see docs/modules.md.
 */

return static function (PDO $pdo): void {
    $tables = array_column(
        $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );

    if (!in_array('saas_plans', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_plans (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              name TEXT NOT NULL,
              slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
              description TEXT NOT NULL DEFAULT '',
              is_free INTEGER NOT NULL DEFAULT 0,
              active INTEGER NOT NULL DEFAULT 1,
              sort_order INTEGER NOT NULL DEFAULT 0,
              price_amount INTEGER NOT NULL DEFAULT 0,
              price_currency TEXT NOT NULL DEFAULT 'usd',
              billing_cycle TEXT NOT NULL DEFAULT 'monthly',
              trial_days INTEGER NOT NULL DEFAULT 0,
              features TEXT NOT NULL DEFAULT '{}',
              limits TEXT NOT NULL DEFAULT '{}',
              qos TEXT NOT NULL DEFAULT '{}',
              provider_ids TEXT NOT NULL DEFAULT '{}',
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }

    if (!in_array('saas_user_plans', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_user_plans (
              user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
              plan_id INTEGER NOT NULL REFERENCES saas_plans(id) ON DELETE CASCADE,
              status TEXT NOT NULL DEFAULT 'active',
              source TEXT NOT NULL DEFAULT 'admin',
              provider TEXT,
              provider_sub_id TEXT,
              period_start TEXT,
              period_end TEXT,
              grace_until TEXT,
              auto_renew INTEGER NOT NULL DEFAULT 1,
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
    }

    if (!in_array('saas_overrides', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_overrides (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              key TEXT NOT NULL,
              value TEXT NOT NULL DEFAULT '',
              note TEXT NOT NULL DEFAULT '',
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE (user_id, key)
            )"
        );
    }

    if (!in_array('saas_checkouts', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_checkouts (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              plan_id INTEGER NOT NULL REFERENCES saas_plans(id) ON DELETE CASCADE,
              provider TEXT NOT NULL,
              provider_session_id TEXT,
              status TEXT NOT NULL DEFAULT 'pending',
              created_at TEXT NOT NULL DEFAULT (datetime('now')),
              expires_at TEXT,
              completed_at TEXT
            )"
        );
    }

    if (!in_array('saas_payments', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_payments (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
              plan_id INTEGER REFERENCES saas_plans(id) ON DELETE SET NULL,
              provider TEXT,
              provider_payment_id TEXT,
              kind TEXT NOT NULL DEFAULT 'payment',
              amount INTEGER,
              currency TEXT,
              status TEXT NOT NULL DEFAULT 'pending',
              raw TEXT,
              created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_saas_payments_user ON saas_payments(user_id, id)');
    } else {
        $payCols = array_column($pdo->query('PRAGMA table_info(saas_payments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('kind', $payCols, true)) {
            $pdo->exec("ALTER TABLE saas_payments ADD COLUMN kind TEXT NOT NULL DEFAULT 'payment'");
        }
    }

    if (!in_array('saas_events', $tables, true)) {
        $pdo->exec(
            "CREATE TABLE saas_events (
              id INTEGER PRIMARY KEY AUTOINCREMENT,
              provider TEXT NOT NULL,
              event_id TEXT NOT NULL,
              action TEXT NOT NULL DEFAULT '',
              handled_at TEXT NOT NULL DEFAULT (datetime('now')),
              UNIQUE (provider, event_id)
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_saas_events_provider ON saas_events(provider, event_id)');
    }

    // Seed the default free plan exactly once (the free plan is editable but
    // never deletable — see SaaSService::deletePlan()).
    $free = (int) $pdo->query('SELECT COUNT(*) FROM saas_plans WHERE is_free = 1')->fetchColumn();
    if ($free === 0) {
        $pdo->exec(
            "INSERT INTO saas_plans (name, slug, description, is_free, active, sort_order, features, limits, qos, provider_ids)
             VALUES ('Free', 'free', 'The default tier for every new account.', 1, 1, 0,
               '{\"meetings\":false,\"voice\":false,\"openclaw_bots\":false}',
               '{\"connections\":3,\"owned_channels\":10,\"memberships\":100,\"upload_max_bytes\":5242880,\"meetings_concurrent\":1,\"openclaw_bot_count\":0,\"open_tickets\":3,\"reg_invites\":2,\"history_messages\":null}',
               '{\"voice_talker_cap\":null,\"voice_bitrate\":null}',
               '{}')"
        );
    }
};

PRAGMA foreign_keys = ON;
PRAGMA journal_mode = WAL;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user',
  role_id INTEGER REFERENCES roles(id) ON DELETE SET NULL,
  guest INTEGER NOT NULL DEFAULT 0,
  vhost TEXT,
  away TEXT,
  away_at TEXT,
  bot INTEGER NOT NULL DEFAULT 0,
  banned INTEGER NOT NULL DEFAULT 0,
  ban_reason TEXT,
  theme TEXT NOT NULL DEFAULT '',
  registered_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen TEXT,
  last_ip TEXT,
  notify TEXT NOT NULL DEFAULT 'all',
  avatar TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT NOT NULL
);

-- Anonymous guests live here, never in `users` (a guest is not an account).
CREATE TABLE IF NOT EXISTS guests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nick TEXT NOT NULL UNIQUE COLLATE NOCASE,
  ip TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_seen TEXT
);

CREATE TABLE IF NOT EXISTS guest_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  guest_id INTEGER NOT NULL REFERENCES guests(id) ON DELETE CASCADE,
  token TEXT NOT NULL UNIQUE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS channels (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE COLLATE NOCASE,
  slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
  topic TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  key_hash TEXT,
  visibility TEXT NOT NULL DEFAULT 'public',
  invite_only INTEGER NOT NULL DEFAULT 0,
  moderated INTEGER NOT NULL DEFAULT 0,
  member_limit INTEGER,
  mlock TEXT NOT NULL DEFAULT '',
  topic_locked INTEGER NOT NULL DEFAULT 1,
  forbidden INTEGER NOT NULL DEFAULT 0,
  censor INTEGER NOT NULL DEFAULT 0,
  successor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  registered_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS channel_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  level TEXT NOT NULL DEFAULT 'normal',
  joined_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_read_id INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS channel_access (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  level TEXT NOT NULL,
  added_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  added_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (channel_id, user_id)
);

CREATE TABLE IF NOT EXISTS akick (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  target TEXT NOT NULL,
  target_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  reason TEXT NOT NULL DEFAULT '',
  added_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  added_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS bans (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,
  channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
  mask TEXT NOT NULL,
  target_user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  reason TEXT NOT NULL DEFAULT '',
  set_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  set_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT,
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS invites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (channel_id, user_id)
);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
  sender_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL,
  kind TEXT NOT NULL DEFAULT 'message',
  content TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  edited_at TEXT,
  reply_to_id INTEGER,
  deleted INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS private_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sender_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  recipient_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  sender_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  recipient_guest_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  content TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  read_at TEXT
);

CREATE TABLE IF NOT EXISTS memos (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sender_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  read_at TEXT
);

CREATE TABLE IF NOT EXISTS spamfilters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  match_type TEXT NOT NULL DEFAULT 'simple',
  targets TEXT NOT NULL DEFAULT 'cp',
  action TEXT NOT NULL DEFAULT 'block',
  ban_time TEXT NOT NULL DEFAULT '',
  reason TEXT NOT NULL DEFAULT '',
  match TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS badwords (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  word TEXT NOT NULL,
  action TEXT NOT NULL DEFAULT 'censor',
  enabled INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS roles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  color TEXT NOT NULL DEFAULT '#5865f2',
  perms TEXT NOT NULL DEFAULT '[]'
);

-- IRC-style operator classes (permission bundles) and o:lines (per-user oper accounts).
CREATE TABLE IF NOT EXISTS operclasses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  color TEXT NOT NULL DEFAULT '#ffd700',
  perms TEXT NOT NULL DEFAULT '[]',
  is_default INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS opers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT NOT NULL,
  operclass_id INTEGER NOT NULL REFERENCES operclasses(id) ON DELETE CASCADE,
  enabled INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Append-only archive. Every channel message/action/system event (and PMs) is
-- written here with a denormalized channel name, so logs survive even when an
-- unregistered channel is deleted. Admins have full visibility into this table.
CREATE TABLE IF NOT EXISTS chat_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_name TEXT,
  user_id INTEGER,
  username TEXT,
  kind TEXT NOT NULL DEFAULT 'message',
  content TEXT NOT NULL DEFAULT '',
  guest INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ignores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  ignored_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE (user_id, ignored_user_id)
);

-- Emoji reactions on messages. A user (or guest) may react once per emoji.
-- actor_type is 'user' or 'guest'; actor_id is the matching id.
CREATE TABLE IF NOT EXISTS reactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
  actor_type TEXT NOT NULL DEFAULT 'user',
  actor_id INTEGER NOT NULL,
  emoji TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (message_id, actor_type, actor_id, emoji)
);
CREATE INDEX IF NOT EXISTS idx_reactions_msg ON reactions(message_id, emoji);

-- Per-user channel notification preference: 'all' (default), 'mentions' (only
-- @mention alerts), or 'muted' (no channel alerts at all).
CREATE TABLE IF NOT EXISTS channel_notify (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  mode TEXT NOT NULL DEFAULT 'all',
  UNIQUE (channel_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_channel_notify_user ON channel_notify(user_id);

-- Incoming webhooks: POST /api/webhooks/<token> posts into a channel as a bot.
-- token_hash stores the SHA-256 of the secret (never the raw token).
CREATE TABLE IF NOT EXISTS webhooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  avatar TEXT NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_used TEXT
);
CREATE INDEX IF NOT EXISTS idx_webhooks_channel ON webhooks(channel_id);

CREATE TABLE IF NOT EXISTS server_config (
  key TEXT PRIMARY KEY,
  value TEXT
);

-- Rolling window of failed login attempts, keyed by client IP (throttle stays
-- per-IP; rows are pruned opportunistically by login_attempt_gate()).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  action TEXT NOT NULL,
  target TEXT,
  detail TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  guest_user_id INTEGER REFERENCES guests(id) ON DELETE CASCADE,
  kind TEXT NOT NULL,
  channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
  sender_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL,
  message_id INTEGER,
  read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_messages_channel ON messages(channel_id, id);
CREATE INDEX IF NOT EXISTS idx_pm_pair ON private_messages(sender_id, recipient_id, id);
CREATE INDEX IF NOT EXISTS idx_pm_guest_pair ON private_messages(sender_guest_id, recipient_guest_id, id);
CREATE INDEX IF NOT EXISTS idx_members_user ON channel_members(user_id);
CREATE INDEX IF NOT EXISTS idx_members_guest ON channel_members(guest_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_member_actor ON channel_members(channel_id, COALESCE(user_id, 0), COALESCE(guest_id, 0));
CREATE INDEX IF NOT EXISTS idx_bans_active ON bans(active, expires_at);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, read);
CREATE INDEX IF NOT EXISTS idx_notif_guest_user ON notifications(guest_user_id, read);

-- Full-text search index over channel messages (external-content FTS5). The
-- triggers keep it in sync with INSERT/UPDATE/DELETE on `messages`. The
-- service-layer search falls back to LIKE when the PHP sqlite build lacks FTS5.
CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
  content,
  content='messages',
  content_rowid='id'
);
CREATE TRIGGER IF NOT EXISTS messages_fts_ai AFTER INSERT ON messages BEGIN
  INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
END;
CREATE TRIGGER IF NOT EXISTS messages_fts_ad AFTER DELETE ON messages BEGIN
  INSERT INTO messages_fts(messages_fts, rowid, content) VALUES('delete', old.id, old.content);
END;
CREATE TRIGGER IF NOT EXISTS messages_fts_au AFTER UPDATE OF content ON messages BEGIN
  INSERT INTO messages_fts(messages_fts, rowid, content) VALUES('delete', old.id, old.content);
  INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
END;

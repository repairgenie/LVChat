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
  avatar TEXT,
  status TEXT NOT NULL DEFAULT 'active',
  status_reason TEXT,
  age_verified_at TEXT
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
  last_seen TEXT,
  age_verified_at TEXT
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

-- Account invites: an email-based invitation to create a real account. The
-- recipient opens /register?invite=<token>; the token proves access even when
-- open registration is disabled. Distinct from `invites` (channel invites).
CREATE TABLE IF NOT EXISTS registration_invites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL COLLATE NOCASE,
  token TEXT NOT NULL UNIQUE,
  invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  message TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT NOT NULL,
  used_at TEXT,
  used_by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_registration_invites_email ON registration_invites(email);

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
  kind TEXT NOT NULL DEFAULT 'message',
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
  perms TEXT NOT NULL DEFAULT '[]',
  helper INTEGER NOT NULL DEFAULT 0
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

-- Sound alerts: audio files uploaded by admins and offered to every user for
-- channel-message / DM notifications. Users cannot upload their own.
CREATE TABLE IF NOT EXISTS sound_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  file TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Per-user sound preferences. Absence of a row means "use the default sound"
-- (channel/DM sounds are on by default). A NULL sound id means that context is
-- explicitly muted; a deleted sound falls back to muted via ON DELETE SET NULL.
CREATE TABLE IF NOT EXISTS user_sound_prefs (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  dm_sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE SET NULL,
  channel_sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE SET NULL
);

-- Per-user override for a specific sender: a NULL sound id mutes that person
-- entirely (both their DMs and their channel messages). No row = follow the
-- user's default channel/DM choices. Deleting a sound removes the override, so
-- it reverts to the default rather than accidentally muting the person.
CREATE TABLE IF NOT EXISTS user_sound_overrides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  target_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  sound_id INTEGER REFERENCES sound_alerts(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, target_user_id)
);
CREATE INDEX IF NOT EXISTS idx_sound_overrides_user ON user_sound_overrides(user_id);

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

-- Moderation queue: records every time a user trips a filter/bad-word, or is
-- the target of a moderation action (kick, channel ban, kline/gline/zline/shun).
CREATE TABLE IF NOT EXISTS moderation_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL,
  kind TEXT NOT NULL,
  action TEXT NOT NULL DEFAULT 'applied',
  match TEXT NOT NULL DEFAULT '',
  content TEXT NOT NULL DEFAULT '',
  target TEXT NOT NULL DEFAULT '',
  channel_id INTEGER REFERENCES channels(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_moderation_events_user ON moderation_events(user_id, id);
CREATE INDEX IF NOT EXISTS idx_moderation_events_guest ON moderation_events(guest_id, id);

-- User-submitted message reports (right-click -> report). Content and sender are
-- snapshotted so reports survive edits/deletes.
CREATE TABLE IF NOT EXISTS reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER REFERENCES messages(id) ON DELETE SET NULL,
  pm INTEGER NOT NULL DEFAULT 0,
  channel_id INTEGER REFERENCES channels(id) ON DELETE SET NULL,
  reporter_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  reporter_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL,
  sender_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sender_guest_id INTEGER REFERENCES guests(id) ON DELETE SET NULL,
  sender_name TEXT NOT NULL DEFAULT '',
  content TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'message',
  reason TEXT NOT NULL DEFAULT '',
  reason_other TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open',
  handled_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  handled_at TEXT,
  resolution TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reports_status ON reports(status, id);

-- Staff-only timeline of moderation actions and notes against an account.
CREATE TABLE IF NOT EXISTS user_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  action TEXT NOT NULL DEFAULT 'note',
  reason TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_user_notes_user ON user_notes(user_id, id);

-- Ticket-based support system.
CREATE TABLE IF NOT EXISTS support_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  email TEXT,
  subject TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  opened_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  closed_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_support_tickets_status ON support_tickets(status, id);

CREATE TABLE IF NOT EXISTS support_ticket_replies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  is_staff INTEGER NOT NULL DEFAULT 0,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_support_replies_ticket ON support_ticket_replies(ticket_id, id);

CREATE INDEX IF NOT EXISTS idx_messages_channel ON messages(channel_id, id);
CREATE INDEX IF NOT EXISTS idx_messages_created ON messages(created_at);
CREATE INDEX IF NOT EXISTS idx_chat_logs_created ON chat_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_pm_pair ON private_messages(sender_id, recipient_id, id);
CREATE INDEX IF NOT EXISTS idx_pm_created ON private_messages(created_at);
CREATE INDEX IF NOT EXISTS idx_moderation_events_created ON moderation_events(created_at);
CREATE INDEX IF NOT EXISTS idx_reports_created ON reports(created_at);
CREATE INDEX IF NOT EXISTS idx_pm_guest_pair ON private_messages(sender_guest_id, recipient_guest_id, id);
CREATE INDEX IF NOT EXISTS idx_members_user ON channel_members(user_id);
CREATE INDEX IF NOT EXISTS idx_members_guest ON channel_members(guest_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_member_actor ON channel_members(channel_id, COALESCE(user_id, 0), COALESCE(guest_id, 0));
CREATE INDEX IF NOT EXISTS idx_bans_active ON bans(active, expires_at);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id, read);
CREATE INDEX IF NOT EXISTS idx_notif_guest_user ON notifications(guest_user_id, read);

CREATE TABLE IF NOT EXISTS friendships (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, friend_id)
);
CREATE INDEX IF NOT EXISTS idx_friendships_user ON friendships(user_id, status);
CREATE INDEX IF NOT EXISTS idx_friendships_friend ON friendships(friend_id, status);

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

CREATE TABLE IF NOT EXISTS friendships (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  friend_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, friend_id)
);
CREATE INDEX IF NOT EXISTS idx_friendships_user ON friendships(user_id, status);
CREATE INDEX IF NOT EXISTS idx_friendships_friend ON friendships(friend_id, status);

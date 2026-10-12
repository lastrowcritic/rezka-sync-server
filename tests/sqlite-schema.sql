PRAGMA foreign_keys = ON;

CREATE TABLE users (
  id TEXT PRIMARY KEY,
  email TEXT NOT NULL UNIQUE,
  password_salt TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  history_cleared_at INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE sessions (
  token_hash TEXT PRIMARY KEY,
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE TABLE watch_records (
  user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  record_key TEXT NOT NULL,
  media_id TEXT NOT NULL,
  updated_at INTEGER NOT NULL,
  record_json TEXT NOT NULL,
  PRIMARY KEY (user_id, record_key)
);
CREATE TABLE auth_limits (
  key_hash TEXT PRIMARY KEY,
  window_started_at INTEGER NOT NULL,
  attempts INTEGER NOT NULL
);

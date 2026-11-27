BEGIN IMMEDIATE;

CREATE TABLE IF NOT EXISTS tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body TEXT NOT NULL,
    attachment_notice INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'Offen',
    priority TEXT NOT NULL DEFAULT 'Normal',
    due TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    received_at INTEGER NOT NULL,
    revision INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS tickets_email ON tickets(email);
CREATE TABLE IF NOT EXISTS imported_mail (
    fingerprint TEXT PRIMARY KEY
);
CREATE TABLE IF NOT EXISTS mailbox (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    identity TEXT NOT NULL,
    validity TEXT NOT NULL,
    last_uid INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS links (
    digest TEXT PRIMARY KEY,
    email TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('customer', 'admin')),
    expires_at INTEGER NOT NULL,
    used_at INTEGER
);
CREATE TABLE IF NOT EXISTS outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('customer', 'admin')),
    ticket_id INTEGER REFERENCES tickets(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    next_attempt INTEGER NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    link_expires INTEGER
);
CREATE TABLE IF NOT EXISTS rate_limits (
    key TEXT PRIMARY KEY,
    hits INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS ticket_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('customer', 'admin')),
    body TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ticket_comments_ticket ON ticket_comments(ticket_id, id);
CREATE TABLE IF NOT EXISTS ticket_closures (
    ticket_id INTEGER PRIMARY KEY REFERENCES tickets(id) ON DELETE CASCADE,
    closed_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS ticket_closures_closed_at ON ticket_closures(closed_at, ticket_id);
CREATE TRIGGER IF NOT EXISTS ticket_closed_on_insert
AFTER INSERT ON tickets
WHEN NEW.status = 'Erledigt'
BEGIN
    INSERT INTO ticket_closures (ticket_id, closed_at) VALUES (NEW.id, NEW.received_at);
END;
CREATE TRIGGER IF NOT EXISTS ticket_closed_on_update
AFTER UPDATE OF status ON tickets
WHEN OLD.status <> 'Erledigt' AND NEW.status = 'Erledigt'
BEGIN
    INSERT INTO ticket_closures (ticket_id, closed_at) VALUES (NEW.id, CAST(strftime('%s', 'now') AS INTEGER));
END;
CREATE TRIGGER IF NOT EXISTS ticket_reopened
AFTER UPDATE OF status ON tickets
WHEN OLD.status = 'Erledigt' AND NEW.status <> 'Erledigt'
BEGIN
    DELETE FROM ticket_closures WHERE ticket_id = NEW.id;
END;

CREATE TABLE IF NOT EXISTS customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE
);
CREATE TABLE IF NOT EXISTS ticket_customers (
    ticket_id INTEGER PRIMARY KEY REFERENCES tickets(id) ON DELETE CASCADE,
    customer_id INTEGER NOT NULL REFERENCES customers(id)
);
CREATE INDEX IF NOT EXISTS ticket_customers_customer ON ticket_customers(customer_id, ticket_id);

CREATE TABLE IF NOT EXISTS comment_notifications (
    outbox_id INTEGER PRIMARY KEY REFERENCES outbox(id) ON DELETE CASCADE,
    comment_id INTEGER NOT NULL REFERENCES ticket_comments(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS ticket_mail_references (
    message_id TEXT PRIMARY KEY,
    ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ticket_mail_references_ticket ON ticket_mail_references(ticket_id);

PRAGMA user_version = 3;
COMMIT;

<?php
declare(strict_types=1);

const STATUSES = ['Offen', 'In Arbeit', 'Wartet', 'Erledigt'];
const PRIORITIES = ['Niedrig', 'Normal', 'Hoch', 'Dringend'];
const LINK_SECONDS = 86400;
const MAX_TICKET_LENGTH = 24000;
const MAX_COMMENT_LENGTH = 4000;
const MAX_CUSTOMER_NAME_LENGTH = 120;
const SCHEMA_VERSION = 4;

function emailAddress(string $address): ?string
{
    $address = trim($address);
    if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $at = strrpos($address, '@');
    return substr($address, 0, $at) . strtolower(substr($address, $at));
}

function emailDomain(string $address): string
{
    return strtolower(substr(strrchr($address, '@') ?: '', 1));
}

function appConfig(): array
{
    $filename = __DIR__ . '/config.php';
    if (!is_file($filename)) {
        throw new RuntimeException('Bitte private/config.php anlegen.');
    }
    $config = require $filename;
    if (!preg_match('/\A[0-9a-f]{64}\z/i', $config['secret'] ?? '')) {
        throw new RuntimeException('Ein eigener 64-stelliger Hex-Schlüssel wird benötigt.');
    }
    foreach (['admin_email', 'mail_email'] as $key) {
        $config[$key] = emailAddress($config[$key] ?? '') ?? throw new RuntimeException('Ungültige E-Mail-Adresse in der Konfiguration.');
    }
    if ($config['admin_email'] === $config['mail_email']) {
        throw new RuntimeException('Für die Verwaltung eine separate E-Mail-Adresse eintragen.');
    }
    $base = parse_url($config['base_url'] ?? '');
    if (!$base || ($base['scheme'] ?? '') !== 'https' || empty($base['host']) ||
        isset($base['user']) || isset($base['pass']) || isset($base['query']) || isset($base['fragment']) ||
        !preg_match('~\A/[a-zA-Z0-9/._-]*\z~', $base['path'] ?? '/')) {
        throw new RuntimeException('base_url muss die HTTPS-Adresse des Portals sein.');
    }
    $config['base_url'] = rtrim($config['base_url'], '/') . '/';
    $config['cookie_path'] = rtrim($base['path'] ?? '/', '/') . '/';
    return $config;
}

function database(): PDO
{
    $db = new PDO('sqlite:' . __DIR__ . '/data/tickets.sqlite3', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() < SCHEMA_VERSION) {
        $db->exec(file_get_contents(__DIR__ . '/schema.sql'));
    }
    return $db;
}

function query(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function ticketCode(int $id): string
{
    return 'T-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function assignTicketCustomer(PDO $db, int $ticket, int $revision, int $customer, string $name): bool
{
    $db->beginTransaction();
    try {
        $updated = query($db, 'UPDATE tickets SET revision = revision + 1 WHERE id = ? AND revision = ?', [$ticket, $revision]);
        if ($updated->rowCount() === 0) {
            $db->rollBack();
            return false;
        }
        if ($name !== '') {
            query($db, 'INSERT INTO customers (name) VALUES (?) ON CONFLICT(name) DO NOTHING', [$name]);
            $customer = (int) query($db, 'SELECT id FROM customers WHERE name = ?', [$name])->fetchColumn();
        }
        if ($customer === 0) {
            query($db, 'DELETE FROM ticket_customers WHERE ticket_id = ?', [$ticket]);
        } else {
            query($db, 'INSERT INTO ticket_customers (ticket_id, customer_id) VALUES (?, ?)
                ON CONFLICT(ticket_id) DO UPDATE SET customer_id = excluded.customer_id', [$ticket, $customer]);
        }
        $db->commit();
        return true;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function queueMail(PDO $db, string $email, string $role, ?int $ticket = null): int
{
    $now = time();
    query($db, 'INSERT INTO outbox (email, role, ticket_id, created_at, next_attempt) VALUES (?, ?, ?, ?, ?)',
        [$email, $role, $ticket, $now, $now]);
    return (int) $db->lastInsertId();
}

function addPortalComment(PDO $db, int $ticket, array $user, string $body): bool
{
    $admin = $user['role'] === 'admin';
    $params = [$user['role'], $body, time(), $ticket];
    if (!$admin) {
        $params[] = $user['email'];
    }
    $db->beginTransaction();
    try {
        $comment = query($db, "INSERT INTO ticket_comments (ticket_id, role, body, created_at)
            SELECT id, ?, ?, ? FROM tickets WHERE id = ? AND status <> 'Erledigt'" .
            ($admin ? '' : ' AND email = ?') . ' RETURNING id', $params)->fetchColumn();
        if ($comment !== false && $admin) {
            $recipient = query($db, 'SELECT email FROM tickets WHERE id = ?', [$ticket])->fetchColumn();
            $outboxId = queueMail($db, $recipient, 'customer', $ticket);
            query($db, 'INSERT INTO comment_notifications (outbox_id, comment_id) VALUES (?, ?)', [$outboxId, $comment]);
        }
        $db->commit();
        return $comment !== false;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function redeem(PDO $db, string $token): ?array
{
    if (!preg_match('/\A[0-9a-f]{64}\z/', $token)) {
        return null;
    }
    $now = time();
    $result = query($db, 'UPDATE links SET used_at = ? WHERE digest = ? AND used_at IS NULL AND expires_at > ? RETURNING email, role',
        [$now, hash('sha256', $token), $now])->fetch();
    return $result ?: null;
}

function allowRequest(PDO $db, string $key, int $limit, int $seconds): bool
{
    $now = time();
    $count = query($db, 'INSERT INTO rate_limits (key, hits, expires_at) VALUES (?, 1, ?)
        ON CONFLICT(key) DO UPDATE SET hits = CASE WHEN expires_at <= ? THEN 1 ELSE hits + 1 END,
        expires_at = CASE WHEN expires_at <= ? THEN excluded.expires_at ELSE expires_at END RETURNING hits',
        [$key, $now + $seconds, $now, $now])->fetchColumn();
    return (int) $count <= $limit;
}

function storeImportedMessage(PDO $db, ?array $mail, string $mailbox, string $validity, int $uid, string $adminEmail): void
{
    $db->beginTransaction();
    try {
        if ($mail !== null) {
            $identity = $mail['message_id'] !== ''
                ? 'message-id:' . $mail['email'] . ':' . $mail['message_id']
                : 'uid:' . $mailbox . ':' . $validity . ':' . $uid;
            $new = query($db, 'INSERT OR IGNORE INTO imported_mail (fingerprint) VALUES (?)', [hash('sha256', $identity)]);
            if ($new->rowCount() > 0) {
                $ticket = referencedTicket($db, $mail['email'], $mail['references'] ?? []);
                if ($ticket !== null) {
                    query($db, "INSERT INTO ticket_comments (ticket_id, role, body, created_at) VALUES (?, 'customer', ?, ?)",
                        [$ticket, replyComment($mail), time()]);
                } else {
                    query($db, 'INSERT INTO tickets (email, subject, body, attachment_notice, received_at) VALUES (?, ?, ?, ?, ?)',
                        [$mail['email'], $mail['subject'], $mail['body'], $mail['attachments'], time()]);
                    $ticket = (int) $db->lastInsertId();
                    queueMail($db, $mail['email'], 'customer', $ticket);
                    queueMail($db, $adminEmail, 'admin', $ticket);
                }
            }
        }
        query($db, 'UPDATE mailbox SET last_uid = ? WHERE id = 1', [$uid]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

function referencedTicket(PDO $db, string $sender, array $references): ?int
{
    if (!$references) {
        return null;
    }
    $placeholders = implode(', ', array_fill(0, count($references), '?'));
    $threads = query($db, 'SELECT r.message_id, r.ticket_id, t.email FROM ticket_mail_references r
        JOIN tickets t ON t.id = r.ticket_id WHERE r.message_id IN (' . $placeholders . ')', $references)->fetchAll();
    $matches = array_column($threads, null, 'message_id');
    foreach ($references as $reference) {
        if (isset($matches[$reference])) {
            $thread = $matches[$reference];
            return $thread['email'] === $sender ? (int) $thread['ticket_id'] : null;
        }
    }
    return null;
}

function replyComment(array $mail): string
{
    $parts = preg_split('/^\h*-{2,}\h*(?:Original Message|Ursprüngliche Nachricht)\h*-{2,}\h*$/imu', $mail['body'], 2);
    $body = preg_replace('/^\h*(?:>[^\r\n]*|On\h+[^\r\n]+\h+wrote:\h*|Am\h+[^\r\n]+\h+schrieb[^\r\n]*:\h*)(?:\R|$)/imu',
        '', $parts[0]);
    $body = trim(preg_replace('/(\?link=1)#[a-f0-9]{64}\b/i', '$1', $body));
    if ($body === '') {
        $body = '[Die E-Mail enthält keinen übernommenen Antworttext.]';
    }
    if ($mail['attachments']) {
        $body .= "\n\n[Anhänge liegen in der Antwort-E-Mail im Ticketpostfach.]";
    }
    return $body;
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/app.php';
require __DIR__ . '/mail.php';
require __DIR__ . '/imap-client.php';

function checkRuntime(): bool
{
    echo 'PHP-CLI: ' . PHP_VERSION . PHP_EOL;
    $ok = PHP_VERSION_ID >= 80200;
    foreach (['pdo_sqlite', 'openssl', 'mbstring', 'curl'] as $name) {
        $loaded = extension_loaded($name);
        printf("%s: %s\n", $name, $loaded ? 'OK' : 'FEHLT');
        $ok = $loaded && $ok;
    }
    if (extension_loaded('pdo_sqlite')) {
        $local = new PDO('sqlite::memory:');
        $version = $local->query('SELECT sqlite_version()')->fetchColumn();
        echo 'SQLite: ' . $version . ' (ab 3.35.0 erforderlich)' . PHP_EOL;
        $ok = version_compare($version, '3.35.0', '>=') && $ok;
    }
    if (extension_loaded('curl')) {
        $protocols = curl_version()['protocols'];
        $smtp = in_array('smtp', $protocols, true) && in_array('smtps', $protocols, true);
        printf("SMTP/SMTPS in cURL: %s\n", $smtp ? 'OK' : 'FEHLT');
        $ok = $smtp && $ok;
    }
    $parser = is_file(__DIR__ . '/vendor/autoload.php');
    printf("E-Mail-Parser: %s\n", $parser ? 'OK' : 'FEHLT');
    $ok = $parser && $ok;
    return $ok;
}

function importMailbox(PDO $db, array $config, string $mode): void
{
    if (!extension_loaded('openssl') || !extension_loaded('mbstring') || !is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('Bitte OpenSSL, mbstring und den mitgelieferten E-Mail-Parser prüfen.');
    }
    require_once __DIR__ . '/vendor/autoload.php';
    // Keep the mailbox identity compatible with installations using ext-imap.
    $mailbox = '{' . $config['imap_host'] . ':' . (int) $config['imap_port'] . '/imap/ssl/validate-cert}INBOX';
    $mailboxIdentity = hash('sha256', $mailbox . '|' . $config['imap_user']);
    $connection = new TicketMailbox($config);
    try {
        [$validity, $next] = $connection->state();
        $maximum = $next - 1;
        $old = query($db, 'SELECT * FROM mailbox WHERE id = 1')->fetch();
        if ($mode === '--init' || $mode === '--import-existing') {
            if ($old && $old['identity'] === $mailboxIdentity && $old['validity'] === $validity) {
                throw new RuntimeException('Dieses Postfach ist bereits initialisiert.');
            }
            if ($old && $old['identity'] !== $mailboxIdentity) {
                throw new RuntimeException('Die Konfiguration verweist auf ein anderes Postfach. Bitte separat einrichten.');
            }
            query($db, 'INSERT INTO mailbox (id, identity, validity, last_uid) VALUES (1, ?, ?, ?)
                ON CONFLICT(id) DO UPDATE SET validity = excluded.validity, last_uid = excluded.last_uid',
                [$mailboxIdentity, $validity, $mode === '--init' ? $maximum : 0]);
            echo "Postfach initialisiert. Der nächste reguläre Lauf verarbeitet neue Nachrichten.\n";
            return;
        }
        if (!$old || $old['identity'] !== $mailboxIdentity || $old['validity'] !== $validity) {
            throw new RuntimeException('Postfach zuerst mit --init oder --import-existing initialisieren. Dies gilt auch nach Änderung der UIDVALIDITY.');
        }
        $last = (int) $old['last_uid'];
        if ($last >= $maximum) {
            return;
        }
        $first = $last + 1;
        $through = min($maximum, $last + 10000);
        $uids = $connection->search($first, $through);
        foreach (array_slice($uids, 0, 40) as $uid) {
            [$raw, $limited] = $connection->fetch($uid);
            $mail = parseTicketMail($raw, $limited, $config);
            storeImportedMessage($db, $mail, $mailboxIdentity, $validity, $uid, $config['admin_email']);
        }
        if (count($uids) <= 40) {
            query($db, 'UPDATE mailbox SET last_uid = ? WHERE id = 1', [$through]);
        }
    } finally {
        $connection->close();
    }
}

function printMailStatus(PDO $db): void
{
    $stats = query($db, 'SELECT
        (SELECT COUNT(*) FROM mailbox) AS initialized,
        (SELECT COUNT(*) FROM imported_mail) AS imported,
        COUNT(*) AS queued,
        COUNT(CASE WHEN role = \'customer\' AND ticket_id IS NOT NULL AND n.outbox_id IS NULL AND s.outbox_id IS NULL THEN 1 END) AS confirmations,
        COUNT(CASE WHEN role = \'customer\' THEN n.outbox_id END) AS comment_notifications,
        COUNT(CASE WHEN role = \'customer\' THEN s.outbox_id END) AS status_notifications,
        COUNT(CASE WHEN role = \'admin\' AND ticket_id IS NOT NULL AND n.outbox_id IS NULL AND s.outbox_id IS NULL THEN 1 END) AS admin_alerts,
        COUNT(CASE WHEN role = \'admin\' AND (n.outbox_id IS NOT NULL OR s.outbox_id IS NOT NULL) THEN 1 END) AS admin_updates,
        COUNT(CASE WHEN role = \'admin\' AND ticket_id IS NULL THEN 1 END) AS admin_links,
        COUNT(CASE WHEN attempts > 0 THEN 1 END) AS retries,
        COUNT(CASE WHEN next_attempt <= ? THEN 1 END) AS ready
        FROM outbox o LEFT JOIN comment_notifications n ON n.outbox_id = o.id
        LEFT JOIN status_notifications s ON s.outbox_id = o.id', [time()])->fetch();
    echo 'Postfach initialisiert: ' . ($stats['initialized'] ? 'ja' : 'nein') . PHP_EOL;
    echo 'Importierte E-Mails: ' . $stats['imported'] . PHP_EOL;
    echo 'E-Mails in der Warteschlange: ' . $stats['queued'] . ' (davon Eingangsbestätigungen: ' . $stats['confirmations'] . ')' . PHP_EOL;
    echo 'Davon Adminbenachrichtigungen: ' . $stats['admin_alerts'] . PHP_EOL;
    echo 'Davon Adminbenachrichtigungen zu Kundenaktionen: ' . $stats['admin_updates'] . PHP_EOL;
    echo 'Davon Kundenbenachrichtigungen zu Kommentaren: ' . $stats['comment_notifications'] . PHP_EOL;
    echo 'Davon Kundenbenachrichtigungen zu Statusänderungen: ' . $stats['status_notifications'] . PHP_EOL;
    echo 'Davon Admin-Anmeldelinks: ' . $stats['admin_links'] . PHP_EOL;
    echo 'Davon jetzt versandbereit: ' . $stats['ready'] . PHP_EOL;
    echo 'Davon mit vorherigem Versandfehler: ' . $stats['retries'] . PHP_EOL;
}

try {
    umask(0077);
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['', '--check', '--setup', '--init', '--import-existing', '--send-only', '--mail-status', '--test-mail'], true)) {
        throw new RuntimeException('Aufruf: worker.php [--check | --setup | --init | --import-existing | --send-only | --mail-status | --test-mail]');
    }
    if ($mode === '--check') {
        exit(checkRuntime() ? 0 : 1);
    }
    $config = appConfig();
    if ($mode === '--test-mail') {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Bitte cURL für PHP-CLI aktivieren.');
        }
        if (extension_loaded('pdo_sqlite') && is_file(__DIR__ . '/data/tickets.sqlite3')) {
            $config['mail_signature'] = mailSignature(database());
        }
        $id = 'test:' . bin2hex(random_bytes(16));
        $started = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        smtpSend($config, $config['admin_email'], 'SMTP-Test · Ticketportal',
            "Das Ticketportal hat diese Testnachricht über den konfigurierten SMTP-Server gesendet.\n",
            $id);
        echo "SMTP-Server hat die Testmail an die konfigurierte Admin-Adresse angenommen.\n";
        echo 'Versandbeginn: ' . $started->format('d.m.Y H:i:s T (P)') . PHP_EOL;
        echo 'Absender: ' . $config['mail_email'] . PHP_EOL;
        echo 'Empfänger: ' . $config['admin_email'] . PHP_EOL;
        echo 'Message-ID: ' . smtpMessageId($config, $id) . PHP_EOL;
        exit;
    }
    if ($mode === '--setup') {
        if (!is_dir(__DIR__ . '/data') && !mkdir(__DIR__ . '/data', 0700)) {
            throw new RuntimeException('Datenordner konnte nicht angelegt werden.');
        }
        $db = database();
        $version = $db->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.35.0', '<')) {
            throw new RuntimeException('SQLite ab Version 3.35 wird benötigt.');
        }
        echo "Datenbank eingerichtet.\n";
        exit;
    }
    if (!is_file(__DIR__ . '/data/tickets.sqlite3')) {
        throw new RuntimeException('Datenbank zuerst mit --setup einrichten.');
    }
    if ($mode === '--mail-status') {
        printMailStatus(database());
        exit;
    }
    $lock = fopen(__DIR__ . '/data/worker.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Sperrdatei konnte nicht geöffnet werden.');
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        if ($mode === '--send-only') {
            echo "Die Versandaufgabe läuft bereits. Bitte erneut versuchen.\n";
        }
        exit;
    }
    $db = database();
    if (in_array($mode, ['--init', '--import-existing'], true)) {
        importMailbox($db, $config, $mode);
        exit;
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Bitte cURL für PHP-CLI aktivieren.');
    }
    $errors = 0;
    if ($mode !== '--send-only') {
        try {
            importMailbox($db, $config, '');
        } catch (RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            $errors++;
        }
    }
    $delivery = deliverOutbox($db, $config);
    if ($mode === '--send-only') {
        echo 'Vom SMTP-Server angenommen: ' . $delivery['sent'] . PHP_EOL;
        echo 'Versandfehler in diesem Lauf: ' . $delivery['failures'] . PHP_EOL;
    }
    exit($errors + $delivery['failures'] > 0 ? 1 : 0);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

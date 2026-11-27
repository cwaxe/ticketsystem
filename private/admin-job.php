<?php
declare(strict_types=1);

require __DIR__ . '/app.php';
require __DIR__ . '/mail.php';

try {
    umask(0077);
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['--status', '--send'], true)) {
        throw new RuntimeException('Aufruf: admin-login.php --status oder --send');
    }
    $config = appConfig();
    if (!is_file(portalPrivatePath('data/tickets.sqlite3'))) {
        throw new RuntimeException('Die Ticketsystem-Datenbank fehlt neben diesem Skript.');
    }
    $db = database();
    $address = $config['admin_email'];
    echo 'Admin-Adresse: ' . $address . PHP_EOL;
    echo 'Portal: ' . $config['base_url'] . '?admin=1' . PHP_EOL;

    if ($mode === '--status') {
        $limit = query($db, 'SELECT hits, expires_at FROM rate_limits WHERE key = ? AND expires_at > ?',
            ['address:' . hash('sha256', $address), time()])->fetch();
        echo 'Webanfragen an diese Adresse im laufenden Stundenfenster: ' . ($limit['hits'] ?? 0) . PHP_EOL;
        if ($limit) {
            $expires = (new DateTimeImmutable('@' . $limit['expires_at']))->setTimezone(new DateTimeZone('Europe/Berlin'));
            echo 'Ende des Stundenfensters: ' . $expires->format('d.m.Y H:i:s T') . PHP_EOL;
        }
        exit;
    }

    if (!extension_loaded('curl')) {
        throw new RuntimeException('Bitte cURL für PHP-CLI aktivieren.');
    }
    $lock = fopen(portalPrivatePath('data/worker.lock'), 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Die reguläre Aufgabe läuft gerade oder die Sperrdatei ist nicht verfügbar.');
    }

    queueMail($db, $address, 'admin');
    $id = (int) $db->lastInsertId();
    $row = query($db, 'SELECT * FROM outbox WHERE id = ?', [$id])->fetch();
    [$subject, $body] = outboxMessage($db, $config, $row);
    $config['mail_signature'] = mailSignature($db);
    $started = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    echo 'Versandbeginn: ' . $started->format('d.m.Y H:i:s T (P)') . PHP_EOL;
    echo 'Absender: ' . $config['mail_email'] . PHP_EOL;
    echo 'Message-ID: ' . smtpMessageId($config, $id) . PHP_EOL;
    try {
        smtpSend($config, $address, $subject, $body, $id);
    } catch (RuntimeException $error) {
        query($db, 'UPDATE outbox SET attempts = 1, next_attempt = ? WHERE id = ?', [time() + 60, $id]);
        throw new RuntimeException($error->getMessage() . ' Die reguläre Aufgabe versucht den Versand erneut.', 0, $error);
    }
    query($db, 'DELETE FROM outbox WHERE id = ?', [$id]);
    echo "Der SMTP-Server hat den persönlichen Admin-Anmeldelink angenommen.\n";
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

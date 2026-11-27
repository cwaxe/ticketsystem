<?php
declare(strict_types=1);

const BACKUP_FORMAT = 'waxenberger-ticketportal';
const BACKUP_MAX_BYTES = 512 * 1024 * 1024;
const BACKUP_MAX_UPLOAD_BYTES = BACKUP_MAX_BYTES + 1024 * 1024;
const BACKUP_DATABASE = 'tickets.sqlite3';

function backupTempFile(): string
{
    $file = tempnam(__DIR__ . '/data', 'backup-');
    if ($file === false) {
        throw new RuntimeException('Es konnte keine temporäre Datei angelegt werden.');
    }
    return $file;
}

function exportBackup(PDO $db): never
{
    $snapshot = backupTempFile();
    $archive = null;
    try {
        $archive = backupTempFile();
        $db->exec('VACUUM INTO ' . $db->quote($snapshot));
        clearstatcache(true, $snapshot);
        if (filesize($snapshot) > BACKUP_MAX_BYTES) {
            throw new InvalidArgumentException('Die Datenbank überschreitet die Sicherungsgrenze von 512 MiB.');
        }
        $manifest = json_encode([
            'format' => BACKUP_FORMAT,
            'schema' => SCHEMA_VERSION,
            'created_at' => gmdate(DATE_ATOM),
            'sha256' => hash_file('sha256', $snapshot),
        ], JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht angelegt werden.');
        }
        $added = $zip->addFromString('manifest.json', $manifest) && $zip->addFile($snapshot, BACKUP_DATABASE);
        if (!$zip->close() || !$added) {
            throw new RuntimeException('Die ZIP-Datei konnte nicht geschrieben werden.');
        }
        session_write_close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="Ticketportal-Sicherung-' . date('Y-m-d-His') . '.zip"');
        header('Content-Length: ' . filesize($archive));
        readfile($archive);
    } finally {
        if ($archive !== null) {
            unlink($archive);
        }
        unlink($snapshot);
    }
    exit;
}

function unpackBackup(string $archive, string $snapshot): void
{
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
        throw new InvalidArgumentException('Die Datei ist keine lesbare Sicherungs-ZIP.');
    }
    try {
        $manifestInfo = $zip->statName('manifest.json');
        $databaseInfo = $zip->statName(BACKUP_DATABASE);
        if ($zip->numFiles !== 2 || !$manifestInfo || $manifestInfo['size'] > 4096 ||
            !$databaseInfo || $databaseInfo['size'] < 512 || $databaseInfo['size'] > BACKUP_MAX_BYTES) {
            throw new InvalidArgumentException('Die ZIP-Datei enthält keine gültige Ticketportal-Sicherung.');
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json', 4096), true);
        if (($manifest['format'] ?? null) !== BACKUP_FORMAT || ($manifest['schema'] ?? null) !== SCHEMA_VERSION ||
            !is_string($manifest['sha256'] ?? null)) {
            throw new InvalidArgumentException('Die Sicherung passt nicht zu dieser Version des Ticketportals.');
        }
        $input = $zip->getStream(BACKUP_DATABASE);
        if ($input === false) {
            throw new InvalidArgumentException('Die gesicherte Datenbank kann nicht gelesen werden.');
        }
        $output = fopen($snapshot, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Die gesicherte Datenbank kann nicht entpackt werden.');
        }
        try {
            $size = stream_copy_to_stream($input, $output, $databaseInfo['size'] + 1);
        } finally {
            fclose($output);
            fclose($input);
        }
        $checksum = hash_file('sha256', $snapshot);
        if ($size !== $databaseInfo['size'] || $checksum === false || !hash_equals($manifest['sha256'], $checksum)) {
            throw new InvalidArgumentException('Die gesicherte Datenbank ist beschädigt.');
        }
    } finally {
        $zip->close();
    }
}

function restoreBackup(PDO $db, string $archive): void
{
    $snapshot = backupTempFile();
    $lock = null;
    $attached = false;
    try {
        unpackBackup($archive, $snapshot);
        $lock = fopen(__DIR__ . '/data/worker.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new InvalidArgumentException('Die E-Mail-Aufgabe läuft gerade. Bitte versuchen Sie es erneut.');
        }
        // Never execute schema or trigger definitions supplied by a backup.
        $db->exec('PRAGMA trusted_schema = OFF');
        $db->exec('ATTACH DATABASE ' . $db->quote($snapshot) . ' AS backup');
        $attached = true;
        $schema = 'sqlite_schema WHERE sql IS NOT NULL ORDER BY type, name';
        if ((int) $db->query('PRAGMA backup.user_version')->fetchColumn() !== SCHEMA_VERSION ||
            $db->query('SELECT type, name, tbl_name, sql FROM backup.' . $schema)->fetchAll() !==
                $db->query('SELECT type, name, tbl_name, sql FROM main.' . $schema)->fetchAll() ||
            $db->query('PRAGMA backup.integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok'] ||
            $db->query('PRAGMA backup.foreign_key_check')->fetch() !== false) {
            throw new InvalidArgumentException('Struktur oder Integrität der gesicherten Datenbank ist ungültig.');
        }

        $tables = [
            'tickets', 'customers', 'imported_mail', 'mailbox', 'links', 'outbox', 'rate_limits',
            'ticket_comments', 'ticket_closures', 'ticket_customers', 'comment_notifications', 'ticket_mail_references',
            'status_notifications', 'mail_settings', 'customer_email_assignments', 'unread_tickets',
            'ticket_status_history', 'ticket_numbers', 'ticket_archive', 'ticket_attachments', 'ticket_activity',
        ];
        $db->exec('BEGIN IMMEDIATE');
        try {
            $triggers = $db->query("SELECT name, sql FROM main.sqlite_schema WHERE type = 'trigger'")->fetchAll(PDO::FETCH_KEY_PAIR);
            $sequences = $db->query('SELECT name, MAX(seq) FROM (
                SELECT name, seq FROM main.sqlite_sequence UNION ALL SELECT name, seq FROM backup.sqlite_sequence
                ) GROUP BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach (array_keys($triggers) as $name) {
                $db->exec('DROP TRIGGER main."' . str_replace('"', '""', $name) . '"');
            }
            foreach (array_reverse($tables) as $table) {
                $db->exec('DELETE FROM main.' . $table);
            }
            foreach ($tables as $table) {
                $db->exec('INSERT INTO main.' . $table . ' SELECT * FROM backup.' . $table);
            }
            // Old sign-in links must not become usable again.
            $db->exec('DELETE FROM main.links; UPDATE main.outbox SET link_expires = NULL WHERE link_expires IS NOT NULL');
            $db->exec('DELETE FROM main.sqlite_sequence');
            foreach ($sequences as $table => $sequence) {
                if (in_array($table, $tables, true)) {
                    query($db, 'INSERT INTO main.sqlite_sequence (name, seq) VALUES (?, CAST(? AS INTEGER))', [$table, $sequence]);
                }
            }
            foreach ($triggers as $sql) {
                $db->exec($sql);
            }
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            $db->exec('ROLLBACK');
            throw $error;
        }
    } finally {
        try {
            if ($attached) {
                $db->exec('DETACH DATABASE backup');
            }
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
            unlink($snapshot);
        }
    }
}

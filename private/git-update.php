<?php
declare(strict_types=1);

require __DIR__ . '/update-state.php';

const GIT_UPDATE_REMOTE = 'git@github.com:cwaxe/ticketsystem.git';
const GIT_UPDATE_BRANCH = 'refs/heads/release';

function runUpdateCommand(array $arguments, ?string $outputFile = null): string
{
    $log = tmpfile();
    if ($log === false) {
        throw new RuntimeException('Das temporäre Git-Protokoll kann nicht angelegt werden.');
    }
    $output = $outputFile === null ? tmpfile() : fopen($outputFile, 'wb');
    if ($output === false) {
        fclose($log);
        throw new RuntimeException('Die Programmdaten können nicht geschrieben werden.');
    }
    $null = fopen('/dev/null', 'r');
    if ($null === false) {
        fclose($output);
        fclose($log);
        throw new RuntimeException('Die Standardeingabe für Git kann nicht geöffnet werden.');
    }
    try {
        $process = @proc_open($arguments, [$null, $output, $log], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            rewind($log);
            $detail = trim((string) stream_get_contents($log, 8192));
            if ($detail === '' && $outputFile === null) {
                rewind($output);
                $detail = trim((string) stream_get_contents($output, 8192));
            }
            if ($detail !== '') {
                fwrite(STDERR, $detail . PHP_EOL);
            }
            throw new RuntimeException('Der Befehl ' . basename($arguments[0]) . ' ist fehlgeschlagen. Details stehen im PHP-Fehlerprotokoll oder in der CLI-Ausgabe.');
        }
        if ($outputFile !== null) {
            return '';
        }
        rewind($output);
        return (string) stream_get_contents($output);
    } finally {
        fclose($null);
        fclose($output);
        fclose($log);
    }
}

function gitUpdateCandidate(string $mirror): array
{
    if (!is_file($mirror . '/HEAD')) {
        runUpdateCommand(['git', 'init', '--bare', $mirror]);
    }
    $git = ['git', '--git-dir=' . $mirror];
    runUpdateCommand([...$git, '-c', 'core.sshCommand=ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=15 -o ServerAliveInterval=15 -o ServerAliveCountMax=2',
        'fetch', '--no-tags', '--no-recurse-submodules', GIT_UPDATE_REMOTE, GIT_UPDATE_BRANCH . ':' . GIT_UPDATE_BRANCH]);
    $commit = trim(runUpdateCommand([...$git, 'rev-parse', '--verify', GIT_UPDATE_BRANCH . '^{commit}']));
    $version = trim(runUpdateCommand([...$git, 'show', $commit . ':private/version.txt']));
    if (!preg_match('/\A[a-f0-9]{40}\z/', $commit) ||
        !validPortalVersion($version)) {
        throw new RuntimeException('Der Git-Stand enthält keine gültige Programmversion.');
    }
    return ['version' => $version, 'commit' => $commit];
}

function removeUpdateStage(string $stage): void
{
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($stage);
}

function installGitCandidate(string $mirror, array $candidate, string $active): void
{
    $name = $candidate['version'] . '-' . $candidate['commit'];
    $stage = updateStatePath('stage-' . bin2hex(random_bytes(8)));
    $release = $stage . '/release';
    if (!mkdir($release, 0700, true)) {
        throw new RuntimeException('Die neue Version kann nicht vorbereitet werden.');
    }
    try {
        $git = ['git', '--git-dir=' . $mirror];
        $files = runUpdateCommand([...$git, 'ls-tree', '-r', $candidate['commit'], '--', 'public', 'private']);
        foreach (explode("\n", trim($files)) as $file) {
            if (!preg_match('~\A100(?:644|755) blob [0-9a-f]{40}\t((?:public|private)/[A-Za-z0-9_./-]+)\z~', $file, $match) ||
                preg_match('~\Aprivate/(?:data|vendor|config\.php)(?:/|\z)~', $match[1])) {
                throw new RuntimeException('Die Programmversion enthält keine gültige Installationsstruktur.');
            }
        }
        $archive = $stage . '/release.tar';
        runUpdateCommand([...$git, 'archive', '--format=tar', $candidate['commit'], 'private', 'public'], $archive);
        runUpdateCommand(['tar', '-xf', $archive, '-C', $release]);
        foreach (['private/app.php', 'private/schema.sql', 'private/portal.php', 'private/mail-job.php',
            'private/admin-job.php', 'private/git-update.php', 'private/update-state.php', 'private/composer.lock',
            'public/index.php', 'public/style.css', 'public/logo.png', 'public/favicon-150.png',
            'public/favicon-300.png', 'public/signin.js'] as $required) {
            if (!is_file($release . '/' . $required)) {
                throw new RuntimeException('Die neue Version enthält nicht alle Programmdateien.');
            }
        }
        if (portalVersion($release) !== $candidate['version']) {
            throw new RuntimeException('Die Versionsnummer der vorbereiteten Dateien stimmt nicht überein.');
        }
        $oldVendor = $active . '/private/vendor';
        if (is_file($oldVendor . '/autoload.php') &&
            hash_file('sha256', $active . '/private/composer.lock') === hash_file('sha256', $release . '/private/composer.lock')) {
            runUpdateCommand(['cp', '-a', $oldVendor, $release . '/private/vendor']);
        } else {
            runUpdateCommand(['composer', 'install', '--working-dir=' . $release . '/private',
                '--no-dev', '--prefer-dist', '--no-interaction', '--no-scripts', '--no-plugins']);
        }
        if (!is_file($release . '/private/vendor/autoload.php')) {
            throw new RuntimeException('Die PHP-Abhängigkeiten sind unvollständig.');
        }
        // Parse the staged PHP files using the same interpreter as the updater.
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($release, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && !str_starts_with($file->getPathname(), $release . '/private/vendor/')) {
                runUpdateCommand([PHP_BINARY, '-n', '-l', $file->getPathname()]);
            }
        }
        require $release . '/private/vendor/autoload.php';
        require $release . '/private/app.php';
        appConfig();

        $lock = fopen(portalPrivatePath('data/program.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Das Ticketportal oder der Mailjob ist gerade aktiv. Bitte starten Sie das Update erneut.');
        }
        try {
            // Validate migrations on a consistent copy before switching the program.
            $database = new PDO('sqlite:' . portalPrivatePath('data/tickets.sqlite3'), null, null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $previousSchema = (int) $database->query('PRAGMA user_version')->fetchColumn();
            if ($previousSchema > SCHEMA_VERSION) {
                throw new RuntimeException('Die neue Programmversion unterstützt das vorhandene Datenbankschema nicht.');
            }
            $database->exec('PRAGMA busy_timeout = 5000');
            $copyPath = $stage . '/check.sqlite3';
            $database->exec('VACUUM INTO ' . $database->quote($copyPath));
            $copy = database($copyPath);
            if ((int) $copy->query('PRAGMA user_version')->fetchColumn() !== SCHEMA_VERSION ||
                $copy->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' ||
                $copy->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new RuntimeException('Die neue Programmversion konnte die Datenbankkopie nicht fehlerfrei öffnen.');
            }
            $copy = null;
            $destination = updateStatePath($name);
            if (is_dir($destination)) {
                // A failed activation can leave this immutable release ready for the next attempt.
                if (portalVersion($destination) !== $candidate['version']) {
                    throw new RuntimeException('Die vorbereitete Programmversion ist ungültig.');
                }
            } elseif (!rename($release, $destination)) {
                throw new RuntimeException('Die vorbereitete Version kann nicht übernommen werden.');
            }
            // The live database is migrated by the first request using the new program.
            writeUpdateFile('current', $name . "\n");
        } finally {
            fclose($lock);
        }
    } finally {
        removeUpdateStage($stage);
    }
}

umask(0077);
$mode = $argv[1] ?? '';
// An old scheduled task must no longer process queued updates.
if ($mode === '--pending') {
    exit;
}
if (!in_array($mode, ['--check', '--apply'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Aufruf: update.php --check | --apply\n");
    exit(1);
}
if (!is_file(portalPrivatePath('data/tickets.sqlite3'))) {
    echo "Bitte zuerst die Datenbank des Ticketportals einrichten.\n";
    exit(1);
}
$lock = fopen(portalPrivatePath('data/update-job.lock'), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Eine Git-Update-Aufgabe läuft bereits.\n";
    exit(1);
}
$status = ['checked_at' => time()];
$exitCode = 0;
try {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Der PHP-CLI-Prozess benötigt proc_open, um Git aufzurufen.');
    }
    putenv('GIT_TERMINAL_PROMPT=0');
    $active = portalActiveRelease();
    $installed = portalVersion($active);
    $mirror = updateStatePath('git');
    $candidate = gitUpdateCandidate($mirror);
    $status += $candidate + ['available' => version_compare($candidate['version'], $installed, '>')];
    if (!$status['available']) {
        $status['message'] = 'Das Ticketportal ist auf dem aktuellen freigegebenen Stand.';
    } elseif ($mode === '--check') {
        $status['message'] = 'Version ' . $candidate['version'] . ' ist zur Installation verfügbar.';
    } else {
        installGitCandidate($mirror, $candidate, $active);
        $status['available'] = false;
        $status['message'] = 'Version ' . $candidate['version'] . ' wurde installiert.';
    }
    writeUpdateFile('status.json', json_encode($status, JSON_THROW_ON_ERROR));
    echo $status['message'] . PHP_EOL;
} catch (Throwable $error) {
    $message = $error instanceof RuntimeException ? $error->getMessage() : 'Die neue Version konnte nicht geprüft oder installiert werden.';
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    echo $message . PHP_EOL;
    writeUpdateFile('status.json', json_encode(['checked_at' => time(), 'message' => $message], JSON_THROW_ON_ERROR));
    $exitCode = 1;
} finally {
    fclose($lock);
}
exit($exitCode);

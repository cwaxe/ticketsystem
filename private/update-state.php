<?php
declare(strict_types=1);

function updateStatePath(string $name): string
{
    return portalPrivatePath('data/updates/' . $name);
}

function readUpdateState(string $name): array
{
    $path = updateStatePath($name);
    clearstatcache(true, $path);
    $state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($state) ? $state : [];
}

function writeUpdateFile(string $name, string $contents): void
{
    $directory = updateStatePath('');
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Das private Updateverzeichnis kann nicht angelegt werden.');
    }
    $temp = tempnam($directory, 'update-');
    if ($temp === false) {
        throw new RuntimeException('Die Update-Datei kann nicht angelegt werden.');
    }
    try {
        if (dirname($temp) !== realpath($directory) ||
            file_put_contents($temp, $contents) !== strlen($contents) || !rename($temp, updateStatePath($name))) {
            throw new RuntimeException('Die Update-Datei kann nicht gespeichert werden.');
        }
    } finally {
        if (is_file($temp)) {
            unlink($temp);
        }
    }
}

function releaseProgramLockForUpdate(): void
{
    if (function_exists('portalProgramLock')) {
        portalProgramLock(true);
        return;
    }

    // Installations updated from 1.0.x may still use the original entry point.
    $entry = new ReflectionFunction('portalEntry');
    $lock = $entry->getStaticVariables()['lock'] ?? null;
    if (is_resource($lock)) {
        fclose($lock);
    }
}

function applyGitUpdateFromPortal(array $config): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Web-PHP muss proc_open zulassen, damit das Portal Git-Updates starten kann.');
    }
    $php = $config['update_php'] ?? 'php';
    if (!is_string($php) || $php === '' || str_contains($php, "\0")) {
        throw new RuntimeException('Der PHP-CLI-Pfad in private/config.php ist ungültig.');
    }
    $output = tmpfile();
    if ($output === false) {
        throw new RuntimeException('Das temporäre Updateprotokoll konnte nicht angelegt werden.');
    }
    $errors = tmpfile();
    if ($errors === false) {
        fclose($output);
        throw new RuntimeException('Das temporäre Updateprotokoll konnte nicht angelegt werden.');
    }

    try {
        $command = [$php, portalPrivatePath('update.php'), '--apply'];
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => $output, 2 => $errors], $pipes, TICKET_PORTAL_HOME);
        if (!is_resource($process)) {
            throw new RuntimeException('Der PHP-CLI-Prozess konnte nicht gestartet werden.');
        }
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            rewind($errors);
            $details = trim((string) stream_get_contents($errors, 8192));
            if ($details !== '') {
                error_log('Ticketportal Git-Update: ' . $details);
            }
            rewind($output);
            $message = trim((string) stream_get_contents($output, 1024));
            if (!preg_match('/\A[^\r\n]{1,400}\z/u', $message)) {
                $message = 'Das Update konnte nicht gestartet werden. Prüfen Sie den PHP-CLI-Pfad in private/config.php und das PHP-Fehlerprotokoll.';
            }
            throw new RuntimeException($message);
        }
    } finally {
        fclose($errors);
        fclose($output);
    }
}

<?php
declare(strict_types=1);

const TICKET_PORTAL_HOME = __DIR__ . '/..';

function portalPrivatePath(string $file): string
{
    return TICKET_PORTAL_HOME . '/private/' . $file;
}

function validPortalVersion(string $version): bool
{
    return preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $version) === 1;
}

function portalVersion(string $release): string
{
    $file = $release . '/private/version.txt';
    $version = is_file($file) ? trim((string) file_get_contents($file)) : '';
    if (!validPortalVersion($version)) {
        throw new RuntimeException('Die Programmversion ist ungültig.');
    }
    return $version;
}

function portalActiveRelease(): string
{
    $pointer = portalPrivatePath('data/updates/current');
    clearstatcache(true, $pointer);
    if (!is_file($pointer)) {
        return TICKET_PORTAL_HOME;
    }
    $name = trim((string) file_get_contents($pointer));
    if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+-[a-f0-9]{40}\z/', $name)) {
        throw new RuntimeException('Die aktive Programmversion ist ungültig.');
    }
    $path = portalPrivatePath('data/updates/' . $name);
    if (!is_dir($path)) {
        throw new RuntimeException('Die aktive Programmversion fehlt.');
    }
    return $path;
}

function portalProgramLock(bool $release = false): void
{
    static $lock;
    if ($release) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return;
    }
    if (is_dir(portalPrivatePath('data')) && !is_resource($lock)) {
        $lock = @fopen(portalPrivatePath('data/program.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_SH | LOCK_NB)) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Das Ticketportal wird gerade aktualisiert. Bitte erneut versuchen.\n");
                exit(1);
            }
            http_response_code(503);
            header('Retry-After: 15');
            exit('Das Ticketportal wird gerade aktualisiert. Bitte laden Sie die Seite in Kürze erneut.');
        }
    }
}

function portalEntry(string $file): string
{
    portalProgramLock();
    $entry = realpath(portalActiveRelease() . '/' . $file);
    if ($entry === false) {
        throw new RuntimeException('Die aktive Programmdatei fehlt.');
    }
    return $entry;
}

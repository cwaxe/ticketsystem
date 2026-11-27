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

function queueGitUpdate(string $action, string $installed): void
{
    $lock = fopen(portalPrivatePath('data/update-queue.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Die Update-Anfrage kann nicht gespeichert werden.');
    }
    try {
        if (readUpdateState('request.json')) {
            throw new InvalidArgumentException('Es ist bereits eine Update-Anfrage vorgemerkt.');
        }
        $request = ['action' => $action];
        if ($action === 'install') {
            $candidate = readUpdateState('status.json');
            if (empty($candidate['available']) || !version_compare($candidate['version'], $installed, '>')) {
                throw new InvalidArgumentException('Prüfen Sie zuerst, ob eine neuere Version verfügbar ist.');
            }
            $request['commit'] = $candidate['commit'];
        }
        writeUpdateFile('request.json', json_encode($request, JSON_THROW_ON_ERROR));
    } finally {
        fclose($lock);
    }
}

<?php
declare(strict_types=1);

const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;
const MAX_ATTACHMENT_TOTAL = 16 * 1024 * 1024;
const MAX_ATTACHMENTS = 10;

function makeAttachment(string $filename, string $contents): array
{
    $filename = basename(str_replace('\\', '/', $filename));
    $filename = preg_replace('/[\p{C}]/u', '', $filename) ?? '';
    $filename = trim(preg_replace('/\A(.{0,180}).*\z/su', '$1', $filename));
    $type = @getimagesizefromstring($contents === '' ? ' ' : $contents)[2] ?? null;
    return [
        'filename' => $filename === '' || $filename === '.' || $filename === '..' ? 'Anhang' : $filename,
        'media_type' => match ($type) {
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            default => 'application/octet-stream',
        },
        'contents' => $contents,
    ];
}

function uploadedAttachments(array $upload): array
{
    $files = [];
    $total = 0;
    foreach ($upload['error'] ?? [] as $index => $error) {
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $size = $upload['size'][$index] ?? 0;
        $path = $upload['tmp_name'][$index] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path) ||
            count($files) >= MAX_ATTACHMENTS || $size > MAX_ATTACHMENT_BYTES || $total + $size > MAX_ATTACHMENT_TOTAL) {
            throw new InvalidArgumentException('Bitte laden Sie höchstens 10 Anhänge mit je 8 MiB und insgesamt höchstens 16 MiB hoch.');
        }
        $contents = file_get_contents($path, false, null, 0, MAX_ATTACHMENT_BYTES + 1);
        if ($contents === false || strlen($contents) !== $size) {
            throw new InvalidArgumentException('Ein Anhang konnte nicht gelesen werden. Bitte versuchen Sie es erneut.');
        }
        $files[] = makeAttachment($upload['name'][$index], $contents);
        $total += $size;
    }
    return $files;
}

function storeAttachments(PDO $db, int $ticket, ?int $comment, array $files): void
{
    if (!$files) {
        return;
    }
    $insert = $db->prepare('INSERT INTO ticket_attachments (ticket_id, comment_id, filename, media_type, contents)
        VALUES (?, ?, ?, ?, ?)');
    foreach ($files as $file) {
        $insert->bindValue(1, $ticket, PDO::PARAM_INT);
        $insert->bindValue(2, $comment, $comment === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insert->bindValue(3, $file['filename']);
        $insert->bindValue(4, $file['media_type']);
        $insert->bindValue(5, $file['contents'], PDO::PARAM_LOB);
        $insert->execute();
    }
}

function previewableAttachment(string $type): bool
{
    return in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
}

function sendTicketAttachment(PDO $db, int $id, ?array $user): never
{
    $file = $user ? query($db, 'SELECT a.filename, a.media_type, a.contents
        FROM ticket_attachments a JOIN tickets t ON t.id = a.ticket_id
        WHERE a.id = ? AND (? = ? OR t.email = ?)',
        [$id, $user['role'], 'admin', $user['email']])->fetch() : false;
    if (!$file) {
        http_response_code(404);
        exit('Anhang nicht gefunden.');
    }
    session_write_close();
    $inline = previewableAttachment($file['media_type']);
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Type: ' . ($inline ? $file['media_type'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') .
        '; filename="Anhang"; filename*=UTF-8\'\'' . rawurlencode($file['filename']));
    header('Content-Length: ' . strlen($file['contents']));
    exit($file['contents']);
}

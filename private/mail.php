<?php
declare(strict_types=1);

use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\IdHeader;
use ZBateson\MailMimeParser\Message;

function smtpMessageId(array $config, int|string $id): string
{
    $domain = substr(strrchr($config['mail_email'], '@'), 1);
    $digest = substr(hash_hmac('sha256', 'mail:' . $id, $config['secret']), 0, 40);
    return '<' . $digest . '@' . $domain . '>';
}

function encodeMailPart(string $content): string
{
    return quoted_printable_encode(preg_replace("/\r\n|\r|\n/", "\r\n", $content));
}

function renderHtmlMail(string $baseUrl, string $subject, string $body, string $signature, ?string $logoCid = null, ?string $status = null): string
{
    $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = preg_replace("/\r\n|\r/", "\n", $body);
    $paragraphs = preg_split('/\n{2,}/', $body, -1, PREG_SPLIT_NO_EMPTY);
    $sections = '';
    foreach ($paragraphs as $paragraph) {
        $lines = explode("\n", $paragraph);
        $content = [];
        foreach ($lines as $line) {
            $safe = $escape($line);
            if (str_starts_with($line, $baseUrl) && preg_match('~\Ahttps://[^\s<>"\']+\z~u', $line)) {
                $label = str_starts_with($line, $baseUrl . '?link=1#') ? 'Anmeldelink öffnen' : 'Ticketportal öffnen';
                $safe = '<a href="' . $safe . '" style="display:inline-block;margin-top:8px;padding:10px 16px;'
                    . 'border-radius:5px;background:#0d3082;color:#ffffff;font-weight:bold;text-decoration:underline">'
                    . $label . '</a>';
            }
            $content[] = $safe;
        }
        $sections .= '<p style="margin:0 0 16px">' . implode('<br>', $content) . '</p>';
    }
    $heading = $escape($subject);
    $statusColors = match ($status) {
        'Offen' => ['#2756a8', '#e8efff', '#183a76'],
        'Wieder geöffnet' => ['#0d766d', '#def5ef', '#08544b'],
        'In Arbeit' => ['#6847a5', '#f0eafa', '#462c73'],
        'Wartet' => ['#a06609', '#fff2d6', '#744500'],
        'Erledigt' => ['#288250', '#e0f3e7', '#145535'],
        default => null,
    };
    $accent = $statusColors[0] ?? '#0d3082';
    $statusBadge = '';
    if ($statusColors !== null) {
        [, $background, $foreground] = $statusColors;
        $check = $status === 'Erledigt' ? '&#10003;&nbsp; ' : '';
        $statusBadge = '<div style="margin:0 0 16px"><span style="display:inline-block;padding:4px 11px;border:1px solid '
            . $accent . ';border-radius:16px;background-color:' . $background . ';color:' . $foreground . ';font-weight:bold">'
            . $check . $escape($status) . '</span></div>';
    }
    $header = $logoCid === null
        ? '<td style="padding:18px 24px;background:' . $accent . ';color:#ffffff;font-size:16px;font-weight:bold">Ticketportal</td>'
        : '<td style="padding:12px 24px;border-bottom:3px solid ' . $accent . '"><img src="cid:' . $escape($logoCid)
            . '" width="175" height="60" alt="IT-Service Waxenberger" style="display:block;border:0"></td>';
    $footer = $signature === '' ? '' : '<div style="border-top:1px solid #d3def3;padding-top:16px;margin-top:24px;color:#294475">'
        . nl2br($escape($signature), false) . '</div>';
    return <<<HTML
<!doctype html>
<html lang="de">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$heading}</title></head>
<body style="margin:0;background:#f4f7fc;color:#18284b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7fc">
  <tr><td align="center" style="padding:24px 12px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;font-family:Arial,sans-serif">
      <tr>{$header}</tr>
      <tr><td style="padding:24px;font-size:16px;line-height:1.55">
        <h1 style="margin:0 0 20px;font-size:22px;line-height:1.3;color:#0d3082">{$heading}</h1>
        {$statusBadge}{$sections}{$footer}
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

function smtpSend(array $config, string $address, string $subject, string $body, int|string $id): void
{
    $host = $config['smtp_host'];
    $mode = $config['smtp_mode'];
    $port = (int) $config['smtp_port'];
    if (!preg_match('/\A[a-z0-9.-]+\z/i', $host) || $port < 1 || $port > 65535 ||
        !in_array($mode, ['implicit', 'starttls'], true)) {
        throw new RuntimeException('Ungültige SMTP-Konfiguration.');
    }
    if (($config['smtp_user'] ?? '') === '' || ($config['smtp_password'] ?? '') === '') {
        throw new RuntimeException('SMTP-Benutzername oder Passwort fehlt in der Konfiguration.');
    }
    $scheme = $mode === 'implicit' ? 'smtps' : 'smtp';
    $sender = $config['mail_email'];
    $boundary = 'ticket_' . bin2hex(random_bytes(16));
    $signature = $config['mail_signature'] ?? DEFAULT_MAIL_SIGNATURE;
    $logo = ($config['show_company_logo'] ?? false) ? @file_get_contents(dirname(__DIR__) . '/public/logo.png') : false;
    $logoCid = $logo === false ? null : $boundary . '@' . emailDomain($sender);
    $body = trim($body);
    $html = renderHtmlMail($config['base_url'], $subject, $body, $signature, $logoCid, $config['notification_status'] ?? null);
    $plain = $signature === '' ? $body : $body . "\n\n" . $signature;
    if (preg_match('/[^\x20-\x7e]/', $subject)) {
        $subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
    $htmlPart = ['Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: quoted-printable', '', encodeMailPart($html)];
    if ($logoCid !== null) {
        $related = $boundary . '_related';
        $htmlPart = [
            'Content-Type: multipart/related; type="text/html"; boundary="' . $related . '"',
            '',
            '--' . $related,
            ...$htmlPart,
            '--' . $related,
            'Content-Type: image/png',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <' . $logoCid . '>',
            'Content-Disposition: inline; filename="logo.png"',
            '',
            rtrim(chunk_split(base64_encode($logo))),
            '--' . $related . '--',
        ];
    }
    $message = implode("\r\n", [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: IT-Service Waxenberger <' . $sender . '>',
        'To: <' . $address . '>',
        'Subject: ' . $subject,
        'Message-ID: ' . smtpMessageId($config, $id),
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        '',
        '--' . $boundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        encodeMailPart($plain),
        '--' . $boundary,
        ...$htmlPart,
        '--' . $boundary . '--',
        '',
    ]);
    $stream = fopen('php://temp', 'w+b');
    if ($stream === false) {
        throw new RuntimeException('Ausgehende Nachricht konnte nicht vorbereitet werden.');
    }
    try {
        fwrite($stream, $message);
        rewind($stream);
        $curl = curl_init($scheme . '://' . $host . ':' . $port);
        curl_setopt_array($curl, [
            CURLOPT_USERNAME => $config['smtp_user'],
            CURLOPT_PASSWORD => $config['smtp_password'],
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAIL_FROM => '<' . $sender . '>',
            CURLOPT_MAIL_RCPT => ['<' . $address . '>'],
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $stream,
            CURLOPT_INFILESIZE => strlen($message),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        if (curl_exec($curl) === false) {
            $code = curl_errno($curl);
            $reply = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $cause = match ($code) {
                6 => 'Servername konnte nicht aufgelöst werden.',
                7 => 'Server oder Port ist nicht erreichbar.',
                28 => 'Zeitüberschreitung beim Verbindungsaufbau oder Versand.',
                35, 51, 60 => 'TLS-Verbindung oder Zertifikat konnte nicht geprüft werden.',
                67 => 'Anmeldung abgelehnt. SMTP-Benutzername und Postfachpasswort prüfen.',
                default => 'Verbindung oder Nachrichtenannahme fehlgeschlagen.',
            };
            $codes = 'cURL ' . $code . ($reply >= 400 ? ', SMTP ' . $reply : '');
            throw new RuntimeException('SMTP-Test oder Versand fehlgeschlagen (' . $codes . '). ' . $cause);
        }
    } finally {
        fclose($stream);
    }
}

function deliverOutbox(PDO $db, array $config, ?callable $send = null): array
{
    $send ??= 'smtpSend';
    $config['mail_signature'] = mailSignature($db);
    $now = time();
    query($db, 'DELETE FROM links WHERE expires_at <= ?', [$now]);
    query($db, 'DELETE FROM rate_limits WHERE expires_at <= ?', [$now]);
    query($db, 'DELETE FROM outbox WHERE ticket_id IS NULL AND created_at <= ?', [$now - LINK_SECONDS]);
    $rows = query($db, 'SELECT o.*, c.body AS comment_body, c.role AS comment_role, s.old_status, s.new_status, tn.number AS ticket_number,
        t.email AS ticket_sender, t.subject AS ticket_subject, t.body AS ticket_body, t.attachment_notice,
        (SELECT COUNT(*) FROM ticket_attachments a WHERE a.ticket_id = o.ticket_id AND a.comment_id IS c.id) AS attachment_count
        FROM outbox o
        LEFT JOIN comment_notifications n ON n.outbox_id = o.id LEFT JOIN ticket_comments c ON c.id = n.comment_id
        LEFT JOIN status_notifications s ON s.outbox_id = o.id
        LEFT JOIN ticket_numbers tn ON tn.ticket_id = o.ticket_id
        LEFT JOIN tickets t ON t.id = o.ticket_id AND o.role = \'admin\' AND n.outbox_id IS NULL AND s.outbox_id IS NULL
        WHERE o.next_attempt <= ? ORDER BY o.next_attempt, o.id LIMIT 15', [$now])->fetchAll();
    $failures = 0;
    $sent = 0;
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if ($row['role'] === 'admin' && $row['email'] !== $config['admin_email']) {
            query($db, 'DELETE FROM outbox WHERE id = ?', [$id]);
            continue;
        }
        if ($row['role'] === 'customer' && $row['ticket_id'] !== null) {
            $reference = query($db, 'INSERT INTO ticket_mail_references (message_id, ticket_id)
                SELECT ?, ticket_id FROM outbox WHERE id = ?
                ON CONFLICT(message_id) DO UPDATE SET ticket_id = excluded.ticket_id', [smtpMessageId($config, $id), $id]);
            if ($reference->rowCount() === 0) {
                continue;
            }
        }
        [$subject, $body] = outboxMessage($db, $config, $row);
        try {
            $send(['show_company_logo' => $row['new_status'] !== null, 'notification_status' => $row['new_status']] + $config,
                $row['email'], $subject, $body, $id);
            query($db, 'DELETE FROM outbox WHERE id = ?', [$id]);
            $sent++;
        } catch (RuntimeException $error) {
            $failures++;
            $delay = min(3600, 60 * (2 ** min(6, (int) $row['attempts'])));
            query($db, 'UPDATE outbox SET attempts = attempts + 1, next_attempt = ? WHERE id = ?', [time() + $delay, $id]);
            fwrite(STDERR, 'Versandauftrag ' . $id . ': ' . $error->getMessage() . PHP_EOL);
        }
    }
    return ['sent' => $sent, 'failures' => $failures];
}

function outboxMessage(PDO $db, array $config, array $row): array
{
    $comment = $row['comment_body'] ?? null;
    $newStatus = $row['new_status'] ?? null;
    $filesInPortal = !empty($row['attachment_count']) ? "\n\nDateianhänge finden Sie im Ticketportal." : '';
    if ($comment !== null || $newStatus !== null) {
        $ticket = (int) $row['ticket_id'];
        $code = ticketCode($ticket, $row['ticket_number'] ?? null);
        $admin = $row['role'] === 'admin';
        if ($newStatus === null) {
            if ($admin) {
                [$comment] = splitQuotedReply($comment);
                $comment = $comment !== '' || $filesInPortal !== '' ? $comment : 'Die E-Mail enthält keinen übernommenen Antworttext.';
            }
            $subject = 'Kommentar zu Ticket ' . $code;
            $intro = ($admin ? 'zu Ticket ' . $code . ' ist ein neuer Kundenkommentar eingegangen.'
                : 'zu Ihrem Ticket ' . $code . ' gibt es einen neuen Kommentar vom IT-Service:') . "\n\n" . $comment;
        } else {
            $subject = 'Statusänderung zu Ticket ' . $code;
            $change = 'von „' . $row['old_status'] . '“ auf „' . $newStatus . '“';
            $reopened = $newStatus === 'Wieder geöffnet';
            $intro = $admin
                ? 'der Kunde hat Ticket ' . $code . ($reopened ? ' wieder geöffnet.' : ' geschlossen.') . ' Der Status wechselte ' . $change . '.'
                : 'der Status Ihres Tickets ' . $code . ' wurde ' . $change . ' geändert.';
            if ($comment !== null) {
                $label = $reopened && ($row['comment_role'] ?? '') === 'customer'
                    ? 'Begründung zum Wiederöffnen' : 'Kommentar zur Statusänderung';
                $intro .= "\n\n" . $label . ":\n" . $comment;
            }
        }
        $reply = $admin ? '' : "\n\nSie können direkt auf diese E-Mail antworten. Ihre Antwort wird dem Ticket zugeordnet.";
        return [
            $subject,
            "Guten Tag,\n\n" . $intro . $filesInPortal . $reply . "\n\nZum Ticketportal:\n" . $config['base_url'] . '?id=' . $ticket . ($admin ? '&admin=1' : ''),
        ];
    }
    if ($row['role'] === 'admin' && $row['ticket_id'] !== null) {
        $ticket = (int) $row['ticket_id'];
        $code = ticketCode($ticket, $row['ticket_number'] ?? null);
        $text = $row['ticket_body'] !== '' ? $row['ticket_body'] : 'Die E-Mail enthält keinen übernommenen Nachrichtentext.';
        $attachments = $row['attachment_notice'] ? "\n\nWeitere Anhänge können in der Original-E-Mail im Ticketpostfach liegen." : '';
        return [
            'Neues Ticket ' . $code,
            "Guten Tag,\n\nim Ticketportal wurde das Ticket " . $code . " erfasst.\n\n"
                . 'Absender: ' . $row['ticket_sender'] . "\nBetreff: " . $row['ticket_subject'] . "\n\n"
                . $text . $filesInPortal . $attachments . "\n\nZum Ticket:\n" . $config['base_url'] . '?id=' . $ticket . '&admin=1',
        ];
    }

    $id = (int) $row['id'];
    $expires = (int) $row['link_expires'];
    if ($expires <= time() + 300) {
        $expires = time() + LINK_SECONDS;
        query($db, 'UPDATE outbox SET link_expires = ? WHERE id = ?', [$expires, $id]);
    }
    $token = hash_hmac('sha256', 'login:' . $id . ':' . $expires . ':' . $row['role'] . ':' . $row['email'], $config['secret']);
    query($db, 'INSERT OR IGNORE INTO links (digest, email, role, expires_at) VALUES (?, ?, ?, ?)',
        [hash('sha256', $token), $row['email'], $row['role'], $expires]);

    // A fragment stays out of normal HTTP request URLs and access logs.
    $url = $config['base_url'] . '?link=1#' . $token;
    $intro = "für das Ticketportal haben Sie einen Anmeldelink angefordert.";
    $subject = 'Ihr Anmeldelink zum Ticketportal';
    if ($row['ticket_id'] !== null) {
        $code = ticketCode((int) $row['ticket_id'], $row['ticket_number'] ?? null);
        $intro = "vielen Dank für Ihre Nachricht. Wir haben Ihr Anliegen als Ticket " . $code . " erfasst.\n"
            . "Sie können direkt auf diese E-Mail antworten. Ihre Antwort wird dem Ticket zugeordnet.";
        $subject = 'Eingangsbestätigung ' . $code;
    }
    $body = "Guten Tag,\n\n" . $intro . "\n\nÜber diesen persönlichen Link gelangen Sie zu Ihrem Ticketportal:\n\n"
        . $url . "\n\nDer Link ist einmal verwendbar und läuft nach spätestens 24 Stunden ab. "
        . "Auf der Startseite des Portals können Sie jederzeit einen neuen Link anfordern.";
    return [$subject, $body];
}

function parseTicketMail(string $raw, bool $limited, array $config): ?array
{
    if ($limited) {
        $raw = preg_split('/\r?\n\r?\n/', $raw, 2)[0] . "\r\n\r\n";
    }
    $message = Message::from($raw, true);
    $auto = trim($message->getHeaderValue('Auto-Submitted') ?? '');
    $precedence = trim($message->getHeaderValue('Precedence') ?? '');
    if (($auto !== '' && !preg_match('/\Ano(?:\s*;|\z)/i', $auto)) ||
        $message->getHeader('List-Id') !== null ||
        preg_match('/\A(?:bulk|list|junk)\b/i', $precedence) ||
        preg_match('/^Return-Path:[ \t]*<>/im', preg_split('/\r?\n\r?\n/', $raw, 2)[0]) ||
        strtolower($message->getContentType()) === 'multipart/report') {
        return null;
    }
    $from = $message->getHeaderAs('From', AddressHeader::class);
    if (!$from || count($from->getAddresses()) !== 1 || $message->getHeader('From', 1) !== null) {
        return null;
    }
    $sender = emailAddress($from->getEmail() ?? '');
    if ($sender === null || strcasecmp($sender, $config['mail_email']) === 0) {
        return null;
    }

    $body = $limited ? '' : trim($message->getTextContent() ?? '');
    if (!$limited && $body === '') {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $message->getHtmlContent() ?? '');
        $html = preg_replace('~<br\s*/?>|</(?:p|div|li|h[1-6])\s*>~i', "\n", $html);
        $body = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    $body = str_replace("\0", '', mb_scrub($body, 'UTF-8'));
    if ($limited || mb_strlen($body) > MAX_TICKET_LENGTH) {
        $body = trim(mb_substr($body, 0, MAX_TICKET_LENGTH) .
            "\n\n[Der Nachrichtentext ist gekürzt. Das Original liegt im Postfach.]");
    }
    $subject = trim(preg_replace('/\s+/u', ' ', mb_scrub($message->getSubject() ?? '', 'UTF-8')));
    $messageId = trim($message->getMessageId() ?? '', " \t\r\n<>");
    $directReferences = $message->getHeaderAs('In-Reply-To', IdHeader::class)?->getIds() ?? [];
    $ancestors = $message->getHeaderAs('References', IdHeader::class)?->getIds() ?? [];
    $references = array_merge(array_slice($directReferences, 0, 20), array_reverse(array_slice($ancestors, -50)));
    $references = array_values(array_unique(array_map(
        static fn (string $reference): string => '<' . trim($reference, " \t\r\n<>") . '>',
        $references
    )));
    [$files, $omitted] = $limited ? [[], true] : parseMailAttachments($message);
    return [
        'email' => $sender,
        'subject' => $subject !== '' ? mb_substr($subject, 0, 180) : '(Ohne Betreff)',
        'body' => $body,
        'attachments' => (int) $omitted,
        'files' => $files,
        'message_id' => $messageId !== '' ? '<' . $messageId . '>' : '',
        'references' => $references,
    ];
}

function parseMailAttachments(Message $message): array
{
    $files = [];
    $omitted = false;
    $total = 0;
    foreach ($message->getAllAttachmentParts() as $part) {
        $filename = $part->getFilename();
        $type = strtolower($part->getContentType());
        $disposition = strtolower($part->getContentDisposition() ?? '');
        if ($filename === null && $disposition !== 'attachment' && in_array($type, ['text/plain', 'text/html'], true)) {
            continue;
        }
        if (count($files) >= MAX_ATTACHMENTS) {
            $omitted = true;
            break;
        }
        $contents = '';
        try {
            $stream = $part->getBinaryContentStream();
            if ($stream === null) {
                $omitted = true;
                continue;
            }
            $limit = min(MAX_ATTACHMENT_BYTES, MAX_ATTACHMENT_TOTAL - $total);
            while (!$stream->eof() && strlen($contents) <= $limit) {
                $chunk = $stream->read(65536);
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException('Anhang konnte nicht vollständig gelesen werden.');
                }
                $contents .= $chunk;
            }
        } catch (RuntimeException) {
            $omitted = true;
            continue;
        }
        if (strlen($contents) > $limit) {
            $omitted = true;
            continue;
        }
        $defaultName = match ($type) {
            'image/png' => 'Bild.png',
            'image/jpeg' => 'Bild.jpg',
            'image/gif' => 'Bild.gif',
            'image/webp' => 'Bild.webp',
            'message/rfc822' => 'Nachricht.eml',
            default => 'Anhang',
        };
        $files[] = makeAttachment($filename ?? $defaultName, $contents);
        $total += strlen($contents);
    }
    return [$files, $omitted];
}

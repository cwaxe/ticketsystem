<?php
declare(strict_types=1);

final class TicketMailbox
{
    private const MESSAGE_LIMIT = 8388608;
    private const LINE_LIMIT = 262144;

    /** @var resource */
    private $stream;
    private int $sequence = 0;

    public function __construct(array $config)
    {
        $host = $config['imap_host'] ?? '';
        $port = (int) ($config['imap_port'] ?? 0);
        if (!preg_match('/\A[a-z0-9.-]+\z/i', $host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Ungültige IMAP-Konfiguration.');
        }
        $user = self::quoted($config['imap_user'] ?? '');
        $password = self::quoted($config['imap_password'] ?? '');
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);
        $stream = @stream_socket_client('tls://' . $host . ':' . $port, $errno, $error, 12, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new RuntimeException('IMAP-Verbindung fehlgeschlagen. Server, Port und TLS prüfen.');
        }
        $this->stream = $stream;
        stream_set_timeout($this->stream, 20);
        try {
            if (!preg_match('/\A\* OK(?: |\r?\n)/i', $this->line())) {
                throw new RuntimeException('IMAP-Server hat die Verbindung abgelehnt.');
            }
            $this->command('LOGIN ' . $user . ' ' . $password, 'IMAP-Anmeldung fehlgeschlagen. Postfachadresse und Passwort prüfen.');
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private static function quoted(string $value): string
    {
        if ($value === '' || strlen($value) > 1024 || preg_match('/[\x00\r\n]/', $value)) {
            throw new RuntimeException('Ungültige IMAP-Zugangsdaten.');
        }
        return '"' . addcslashes($value, "\\\"") . '"';
    }

    private function line(): string
    {
        $line = fgets($this->stream, self::LINE_LIMIT);
        if ($line === false || !str_ends_with($line, "\r\n")) {
            throw new RuntimeException('IMAP-Antwort ist unvollständig oder zu lang.');
        }
        return $line;
    }

    private function literal(int $size): string
    {
        if ($size > self::MESSAGE_LIMIT + 1) {
            throw new RuntimeException('IMAP hat mehr Daten geliefert als angefordert.');
        }
        $result = '';
        while (strlen($result) < $size) {
            $chunk = fread($this->stream, min(65536, $size - strlen($result)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('IMAP-Nachricht wurde nicht vollständig übertragen.');
            }
            $result .= $chunk;
        }
        return $result;
    }

    private function command(string $command, string $failure, bool $expectLiteral = false): array
    {
        $tag = 'T' . ++$this->sequence;
        $request = $tag . ' ' . $command . "\r\n";
        if (fwrite($this->stream, $request) !== strlen($request)) {
            throw new RuntimeException('IMAP-Anfrage konnte nicht gesendet werden.');
        }
        $lines = [];
        $message = null;
        for ($count = 0; $count < 500; $count++) {
            $line = $this->line();
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/\A' . $tag . ' OK(?: |\r?\n)/i', $line)) {
                    throw new RuntimeException($failure);
                }
                return [$lines, $message];
            }
            if (str_starts_with($line, '+') || preg_match('/\A\* BYE(?: |\r?\n)/i', $line)) {
                throw new RuntimeException($failure);
            }
            $lines[] = $line;
            if (preg_match('/\{([0-9]+)\}\r\n\z/', $line, $size)) {
                if (!$expectLiteral || $message !== null || !preg_match('/\A\* \d+ FETCH \(/i', $line)) {
                    throw new RuntimeException('Unerwartete IMAP-Daten.');
                }
                $message = $this->literal((int) $size[1]);
                $lines[] = $this->line();
            }
        }
        throw new RuntimeException('Zu viele IMAP-Antworten für eine Anfrage.');
    }

    public function state(): array
    {
        [$lines] = $this->command('EXAMINE INBOX', 'IMAP konnte den Posteingang nicht öffnen.');
        $fields = [];
        foreach ($lines as $line) {
            if (preg_match('/\A\* OK \[(UIDVALIDITY|UIDNEXT) ([1-9][0-9]*)\]/i', $line, $match)) {
                $fields[strtoupper($match[1])] = $match[2];
            }
        }
        if (!isset($fields['UIDVALIDITY'], $fields['UIDNEXT'])) {
            throw new RuntimeException('IMAP konnte UIDVALIDITY oder UIDNEXT nicht lesen.');
        }
        return [$fields['UIDVALIDITY'], (int) $fields['UIDNEXT']];
    }

    public function search(int $first, int $last): array
    {
        [$lines] = $this->command("UID SEARCH UID $first:$last", 'IMAP-Suche fehlgeschlagen.');
        foreach ($lines as $line) {
            if (preg_match('/\A\* SEARCH(?: ([0-9 ]+))?\r\n\z/i', $line, $match)) {
                $list = trim($match[1] ?? '');
                $ids = $list === '' ? [] : array_map('intval', preg_split('/ +/', $list));
                $ids = array_filter(array_unique($ids), static fn (int $id): bool => $id >= $first && $id <= $last);
                sort($ids, SORT_NUMERIC);
                return $ids;
            }
        }
        throw new RuntimeException('IMAP hat kein Suchergebnis geliefert.');
    }

    public function fetch(int $uid): array
    {
        $limit = self::MESSAGE_LIMIT + 1;
        [$lines, $message] = $this->command("UID FETCH $uid (UID BODY.PEEK[]<0.$limit>)", 'IMAP konnte die Nachricht nicht lesen.', true);
        if ($message === null || !preg_match('/\bUID ' . $uid . '(?!\d)/i', implode(' ', $lines))) {
            throw new RuntimeException('IMAP hat die angeforderte Nachricht nicht vollständig geliefert.');
        }
        return [$message, strlen($message) > self::MESSAGE_LIMIT];
    }

    public function close(): void
    {
        fclose($this->stream);
    }
}

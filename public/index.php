<?php
declare(strict_types=1);

require dirname(__DIR__) . '/private/app.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header('X-Robots-Tag: noindex, nofollow');
date_default_timezone_set('Europe/Berlin');

function h(string|int $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(array $data, string $key): string
{
    return is_string($data[$key] ?? null) ? $data[$key] : '';
}

function go(array $config, string $query = ''): never
{
    header('Location: ' . $config['base_url'] . $query, true, 303);
    exit;
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function enumSelect(string $name, array $choices, string $current): void
{
    echo '<select name="' . h($name) . '">';
    foreach ($choices as $choice) {
        echo '<option' . ($choice === $current ? ' selected' : '') . '>' . h($choice) . '</option>';
    }
    echo '</select>';
}

function formatDuration(float $seconds): string
{
    $minutes = intdiv(max(0, (int) $seconds), 60);
    if ($minutes === 0) {
        return 'Unter 1 Min.';
    }
    $days = intdiv($minutes, 1440);
    $hours = intdiv($minutes % 1440, 60);
    return ($days ? $days . ' T. ' : '') . ($hours ? $hours . ' Std. ' : '') . ($minutes % 60) . ' Min.';
}

try {
    $config = appConfig();
    if (!is_file(dirname(__DIR__) . '/private/data/tickets.sqlite3')) {
        throw new RuntimeException('Bitte die Datenbank einrichten.');
    }
    if (!ini_set('session.use_strict_mode', '1') && ini_get('session.use_strict_mode') !== '1') {
        throw new RuntimeException('PHP-Sitzungsschutz steht nicht zur Verfügung.');
    }
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_save_path(dirname(__DIR__) . '/private/data');
    session_name('WAXTICKETS');
    session_set_cookie_params(['path' => $config['cookie_path'], 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    if (!session_start()) {
        throw new RuntimeException('PHP-Sitzung konnte nicht gestartet werden.');
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $user = $_SESSION['user'] ?? null;
    if ($user && (time() >= $user['until'] || ($user['role'] === 'admin' && $user['email'] !== $config['admin_email']))) {
        unset($_SESSION['user']);
        $user = null;
    }
    $admin = ($user['role'] ?? '') === 'admin';
    $report = field($_GET, 'auswertung') === '1';
    $signatureSettings = field($_GET, 'signatur') === '1';
    if (($report || $signatureSettings) && !$admin) {
        http_response_code(403);
        exit('Dieser Bereich ist für die Verwaltung vorgesehen.');
    }
    $id = (int) field($_GET, 'id');
    $search = trim(substr(field($_GET, 'q'), 0, 250));
    $filter = field($_GET, 'status');
    $page = max(1, min(100000, (int) field($_GET, 'seite')));
    $grouped = $admin && field($_GET, 'gruppe') === 'kunden';
    $onlyNew = $admin && !$grouped && field($_GET, 'neu') === '1';
    $customerParam = field($_GET, 'kunde');
    $customerFilter = $grouped && preg_match('/\A[0-9]{1,18}\z/', $customerParam) ? (int) $customerParam : null;
    $customerOverview = $grouped && $customerFilter === null;
    $filters = array_filter(['q' => $search, 'status' => $filter], static fn (string $value): bool => $value !== '');
    $listParams = $filters + ($grouped ? ['gruppe' => 'kunden'] : []) + ($customerFilter !== null ? ['kunde' => $customerFilter] : [])
        + ($onlyNew ? ['neu' => 1] : []);
    $groupContext = $grouped || $onlyNew ? $listParams + ($page > 1 ? ['seite' => $page] : []) : [];
    $db = database();
    $notice = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($_SESSION['csrf'], field($_POST, 'csrf'))) {
            http_response_code(403);
            exit('Die Seite ist abgelaufen. Bitte öffnen Sie das Ticketportal erneut.');
        }
        $action = field($_POST, 'action');
        if ($action === 'redeem') {
            $grant = redeem($db, field($_POST, 'token'));
            if ($grant && ($grant['role'] !== 'admin' || $grant['email'] === $config['admin_email'])) {
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'user' => $grant + ['until' => time() + 28800]];
                go($config);
            }
            $notice = 'Dieser Anmeldelink ist ungültig, abgelaufen oder wurde bereits benutzt. Bitte fordern Sie einen neuen Link an.';
        }
        if ($action === 'request' && !$user) {
            $address = emailAddress(field($_POST, 'email'));
            $role = field($_POST, 'role') === 'admin' ? 'admin' : 'customer';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            if ($address && allowRequest($db, 'ip:' . hash('sha256', $ip), 20, 3600) &&
                allowRequest($db, 'address:' . hash('sha256', $address), 5, 3600)) {
                $known = $role === 'admin'
                    ? hash_equals($config['admin_email'], $address)
                    : (bool) query($db, 'SELECT 1 FROM tickets WHERE email = ? LIMIT 1', [$address])->fetchColumn();
                if ($known) {
                    queueMail($db, $address, $role);
                }
            }
            $notice = 'Wenn zu dieser Adresse ein Zugang besteht, erhalten Sie in Kürze eine E-Mail mit einem Anmeldelink.';
        }
        if ($action === 'logout') {
            unset($_SESSION['user']);
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            go($config);
        }
        if ($action === 'save-signature') {
            if (!$signatureSettings) {
                http_response_code(403);
                exit('Dieser Bereich ist für die Verwaltung vorgesehen.');
            }
            $signature = trim(preg_replace("/\r\n|\r/", "\n", field($_POST, 'signature')));
            if (strlen($signature) > MAX_MAIL_SIGNATURE_LENGTH * 4 ||
                !preg_match('/\A[^\x00]{0,' . MAX_MAIL_SIGNATURE_LENGTH . '}\z/u', $signature)) {
                $notice = 'Bitte geben Sie eine Signatur mit höchstens ' . MAX_MAIL_SIGNATURE_LENGTH . ' Zeichen ein.';
            } else {
                query($db, 'INSERT INTO mail_settings (id, signature) VALUES (1, ?)
                    ON CONFLICT(id) DO UPDATE SET signature = excluded.signature', [$signature]);
                go($config, '?signatur=1&saved=1');
            }
        }
        if ($action === 'comment' || $action === 'close') {
            if (!$user || ($action === 'close' && $admin)) {
                http_response_code(403);
                exit('Diese Aktion ist für Ihr Konto nicht verfügbar.');
            }
            if ($action === 'close') {
                if (closeCustomerTicket($db, $id, $user['email'], $config['admin_email'])) {
                    go($config, '?id=' . $id . '&closed=1');
                }
                $notice = 'Dieses Ticket ist bereits geschlossen.';
            } else {
                $comment = trim(field($_POST, 'comment'));
                if (strlen($comment) > MAX_COMMENT_LENGTH * 4 ||
                    !preg_match('/\A.{1,' . MAX_COMMENT_LENGTH . '}\z/su', $comment)) {
                    $notice = 'Bitte geben Sie einen Kommentar mit höchstens ' . MAX_COMMENT_LENGTH . ' Zeichen ein.';
                } elseif (addPortalComment($db, $id, $user, $comment, $config['admin_email'])) {
                    go($config, '?' . http_build_query(['id' => $id] + $groupContext) . '#antwort');
                } else {
                    $notice = 'Dieses Ticket ist bereits geschlossen.';
                }
            }
        }
        if ($action === 'assign-customer') {
            if (!$admin) {
                http_response_code(403);
                exit('Dieser Bereich ist für die Verwaltung vorgesehen.');
            }
            $newCustomer = trim(field($_POST, 'new_customer'));
            $customerChoice = field($_POST, 'customer_id');
            $customerId = (int) $customerChoice;
            if ($newCustomer !== '' && (strlen($newCustomer) > MAX_CUSTOMER_NAME_LENGTH * 4 ||
                !preg_match('/\A.{1,' . MAX_CUSTOMER_NAME_LENGTH . '}\z/u', $newCustomer))) {
                $notice = 'Bitte geben Sie einen Kundennamen mit höchstens ' . MAX_CUSTOMER_NAME_LENGTH . ' Zeichen ein.';
            } elseif ($newCustomer === '' && (!preg_match('/\A[0-9]{1,18}\z/', $customerChoice) ||
                ($customerId > 0 && !query($db, 'SELECT 1 FROM customers WHERE id = ?', [$customerId])->fetchColumn()))) {
                $notice = 'Bitte wählen Sie einen vorhandenen Kunden aus.';
            } elseif (assignTicketCustomer($db, $id, (int) field($_POST, 'revision'), $customerId, $newCustomer)) {
                go($config, '?' . http_build_query(['id' => $id, 'zugeordnet' => 1] + $groupContext));
            } else {
                $notice = 'Das Ticket wurde zwischenzeitlich geändert oder gelöscht. Die aktuellen Daten wurden geladen.';
            }
        }
        if ($action === 'save' || $action === 'delete') {
            if (!$admin) {
                http_response_code(403);
                exit('Dieser Bereich ist für die Verwaltung vorgesehen.');
            }
            $id = (int) field($_POST, 'id');
            $revision = (int) field($_POST, 'revision');
            if ($action === 'delete') {
                $deleted = query($db, 'DELETE FROM tickets WHERE id = ? AND revision = ?', [$id, $revision]);
                if ($deleted->rowCount() > 0) {
                    go($config, $groupContext ? '?' . http_build_query($groupContext) : '');
                }
                $notice = 'Das Ticket wurde zwischenzeitlich geändert oder gelöscht.';
            } else {
                $status = field($_POST, 'status');
                $priority = field($_POST, 'priority');
                $due = field($_POST, 'due');
                $notes = trim(field($_POST, 'notes'));
                $validDue = $due === '' || (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $due) &&
                    checkdate((int) substr($due, 5, 2), (int) substr($due, 8, 2), (int) substr($due, 0, 4)));
                if (!in_array($status, STATUSES, true) || !in_array($priority, PRIORITIES, true) ||
                    !$validDue || strlen($notes) > MAX_TICKET_LENGTH * 4) {
                    $notice = 'Bitte prüfen Sie Status, Priorität, Datum und Notizen.';
                } elseif ($id > 0) {
                    $update = query($db, 'UPDATE tickets SET status = ?, priority = ?, due = ?, notes = ?, revision = revision + 1
                        WHERE id = ? AND revision = ?', [$status, $priority, $due, $notes, $id, $revision]);
                    if ($update->rowCount() > 0) {
                        go($config, '?' . http_build_query(['id' => $id, 'saved' => 1] + $groupContext));
                    }
                    $notice = 'Das Ticket wurde zwischenzeitlich geändert oder gelöscht. Die aktuellen Daten wurden geladen.';
                } else {
                    $email = emailAddress(field($_POST, 'email'));
                    $subject = trim(field($_POST, 'subject'));
                    $body = trim(field($_POST, 'body'));
                    if (!$email || $subject === '' || strlen($subject) > 720 || strlen($body) > MAX_TICKET_LENGTH * 4) {
                        $notice = 'Bitte prüfen Sie E-Mail-Adresse, Betreff und Nachricht.';
                    } else {
                        query($db, 'INSERT INTO tickets (email, subject, body, status, priority, due, notes, received_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$email, $subject, $body, $status, $priority, $due, $notes, time()]);
                        go($config, '?id=' . (int) $db->lastInsertId() . '&saved=1');
                    }
                }
            }
        }
    }

    $adminLogin = !$user && field($_GET, 'admin') === '1';
    $showLink = field($_GET, 'link') === '1' && $notice === '';
    $id = (int) field($_GET, 'id');
    $new = $admin && field($_GET, 'new') === '1';
    $detail = null;
    $comments = [];
    $rows = [];
    $statusGroups = [];
    if ($signatureSettings) {
        $signature = ($action ?? '') === 'save-signature' ? field($_POST, 'signature') : mailSignature($db);
    } elseif ($report) {
        $stats = query($db, "SELECT
            COUNT(CASE WHEN t.status <> 'Erledigt' THEN 1 END) AS open_count,
            COUNT(c.ticket_id) AS measured_count,
            COUNT(CASE WHEN t.status = 'Erledigt' AND c.ticket_id IS NULL THEN 1 END) AS unknown_count,
            AVG(MAX(0, c.closed_at - t.received_at)) AS average_seconds
            FROM tickets t LEFT JOIN ticket_closures c ON c.ticket_id = t.id")->fetch();
        $recent = query($db, 'SELECT t.id, t.subject, t.received_at, c.closed_at FROM ticket_closures c
            JOIN tickets t ON t.id = c.ticket_id ORDER BY c.closed_at DESC, c.ticket_id DESC LIMIT 25')->fetchAll();
    } elseif ($user && ($id > 0 || in_array($action ?? '', ['comment', 'close'], true))) {
        $parameters = $admin ? [$id] : [$id, $user['email']];
        $detail = query($db, 'SELECT * FROM tickets WHERE id = ?' . ($admin ? '' : ' AND email = ?'), $parameters)->fetch();
        if ($detail) {
            $comments = query($db, 'SELECT role, body, created_at FROM ticket_comments WHERE ticket_id = ? ORDER BY id', [$id])->fetchAll();
            if ($admin) {
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
                    query($db, 'DELETE FROM unread_tickets WHERE ticket_id = ?', [$id]);
                }
                $assignedCustomer = (int) query($db, 'SELECT customer_id FROM ticket_customers WHERE ticket_id = ?', [$id])->fetchColumn();
                $customers = query($db, 'SELECT id, name FROM customers ORDER BY name, id')->fetchAll();
                $domainHint = emailDomain($detail['email']);
                $suggestions = [];
                if ($domainHint !== '') {
                    $db->sqliteCreateFunction('ticket_domain', 'emailDomain', 1);
                    $suggestions = query($db, 'SELECT DISTINCT c.name FROM customers c
                        JOIN ticket_customers tc ON tc.customer_id = c.id JOIN tickets t ON t.id = tc.ticket_id
                        WHERE t.id <> ? AND ticket_domain(t.email) = ? ORDER BY c.name', [$id, $domainHint])->fetchAll(PDO::FETCH_COLUMN);
                }
            }
        } else {
            http_response_code(404);
            $notice = 'Dieses Ticket konnte nicht gefunden werden.';
        }
    }
    if ($user && !$report && !$signatureSettings && !$new && !$detail) {
        $where = $admin ? [] : ['t.email = ?'];
        $parameters = $admin ? [] : [$user['email']];
        if ($search !== '') {
            $where[] = "(instr(lower(t.subject), lower(?)) > 0 OR instr(lower(t.email), lower(?)) > 0 OR printf('T-%04d', t.id) = upper(?)" .
                ($admin ? " OR instr(lower(c.name), lower(?)) > 0" : '') . ')';
            array_push($parameters, $search, $search, $search);
            if ($admin) {
                $parameters[] = $search;
            }
        }
        if (in_array($filter, STATUSES, true)) {
            $where[] = 't.status = ?';
            $parameters[] = $filter;
        }
        if ($admin) {
            $unreadCount = (int) query($db, 'SELECT COUNT(*) FROM unread_tickets')->fetchColumn();
        }
        if ($onlyNew) {
            $where[] = 'u.ticket_id IS NOT NULL';
        }
        if ($customerFilter === 0) {
            $where[] = 'tc.customer_id IS NULL';
            $customerName = 'Ohne Kundenzuordnung';
        } elseif ($customerFilter !== null) {
            $where[] = 'tc.customer_id = ?';
            $parameters[] = $customerFilter;
            $customerName = query($db, 'SELECT name FROM customers WHERE id = ?', [$customerFilter])->fetchColumn();
            if ($customerName === false) {
                $notice = 'Dieser Kunde konnte nicht gefunden werden.';
                http_response_code(404);
            }
        }
        $offset = ($page - 1) * 50;
        $selection = ' FROM tickets t';
        if ($admin) {
            $selection .= ' LEFT JOIN ticket_customers tc ON tc.ticket_id = t.id LEFT JOIN customers c ON c.id = tc.customer_id
                LEFT JOIN unread_tickets u ON u.ticket_id = t.id';
        }
        $selection .= $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $listing = $customerOverview
            ? "SELECT COALESCE(tc.customer_id, 0) AS customer_id, c.name AS customer_name, COUNT(*) AS total,
                COUNT(CASE WHEN t.status <> 'Erledigt' THEN 1 END) AS active, MAX(t.received_at) AS latest" .
                $selection . ' GROUP BY tc.customer_id, c.name ORDER BY latest DESC, c.name, customer_id'
            : 'SELECT t.id, t.subject, t.email, t.status, t.priority, t.due, t.received_at' .
                ($admin ? ', c.name AS customer_name, u.ticket_id IS NOT NULL AS is_new' : '') . $selection .
                " ORDER BY CASE t.status WHEN 'Offen' THEN 0 WHEN 'In Arbeit' THEN 1 WHEN 'Wartet' THEN 2 WHEN 'Erledigt' THEN 3 ELSE 4 END, t.status" .
                ($admin ? ', is_new DESC' : '') . ', t.id DESC';
        $rows = query($db, $listing . ' LIMIT 51 OFFSET ' . $offset, $parameters)->fetchAll();
        if (!$customerOverview) {
            foreach (array_slice($rows, 0, 50) as $row) {
                $statusGroups[$row['status']][] = $row;
            }
        }
    }
} catch (RuntimeException $error) {
    http_response_code(503);
    error_log('Ticketportal: ' . $error->getMessage());
    exit('Das Ticketportal ist momentan nicht verfügbar.');
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ticketportal · IT-Service Waxenberger</title>
  <link rel="icon" type="image/png" sizes="150x150" href="favicon-150.png">
  <link rel="icon" type="image/png" sizes="300x300" href="favicon-300.png">
  <link rel="apple-touch-icon" href="favicon-300.png">
  <link rel="stylesheet" href="style.css?v=7">
  <?php if ($showLink): ?><script src="signin.js" defer></script><?php endif ?>
</head>
<body>
<header class="site-header">
  <div class="header-content">
    <a href="./" class="logo"><img src="logo.png" width="877" height="300" alt="IT-Service Waxenberger"><span>Ticketportal</span></a>
  </div>
</header>
<main>
<?php if ($notice !== ''): ?><p class="notice" role="status"><?= h($notice) ?></p><?php endif ?>
<?php if ($showLink): ?>
  <section class="signin panel">
    <h1>Zum Ticketportal anmelden</h1>
    <p>Öffnen Sie Ihre Ticketübersicht mit diesem persönlichen Anmeldelink.</p>
    <p id="missing-link">Öffnen Sie den vollständigen Link aus Ihrer E-Mail in einem Browser mit JavaScript oder <a href="./">fordern Sie einen neuen Link an</a>.</p>
    <form method="post" id="redeem" hidden>
      <?= csrfInput() ?><input type="hidden" name="action" value="redeem"><input type="hidden" name="token">
      <button>Anmelden</button>
    </form>
  </section>
<?php elseif (!$user): ?>
  <?php if ($adminLogin): ?>
    <section class="signin panel">
      <h1>Verwaltung anmelden</h1>
      <p>Fordern Sie einen Link für den Zugang zur Ticketverwaltung an.</p>
      <form method="post" action="?admin=1">
        <?= csrfInput() ?><input type="hidden" name="action" value="request"><input type="hidden" name="role" value="admin">
        <label>E-Mail-Adresse<input required name="email" type="email" autocomplete="email" maxlength="254"></label>
        <button>Anmeldelink senden</button>
      </form>
    </section>
  <?php else: ?>
    <div class="portal-intro">
      <h1>Ticketportal</h1>
      <p>IT-Anfragen stellen und den Bearbeitungsstand einsehen.</p>
    </div>
    <div class="portal-options">
      <section class="panel portal-card new-request" aria-labelledby="new-request">
        <h2 id="new-request">Neue Anfrage</h2>
        <p>Schildern Sie Ihr Anliegen per E-Mail. Sie erhalten eine Eingangsbestätigung mit Ihrer Ticketnummer und einem Anmeldelink.</p>
        <a class="button" href="mailto:<?= h($config['mail_email']) ?>">Anfrage per E-Mail senden</a>
        <p class="mail-address"><?= h($config['mail_email']) ?></p>
      </section>
      <section class="panel portal-card" aria-labelledby="my-tickets">
        <h2 id="my-tickets">Meine Tickets</h2>
        <p>Geben Sie die E-Mail-Adresse ein, von der Sie Ihre Anfrage gesendet haben. Wir schicken Ihnen einen Anmeldelink.</p>
        <form method="post" action="./">
          <?= csrfInput() ?><input type="hidden" name="action" value="request"><input type="hidden" name="role" value="customer">
          <label>E-Mail-Adresse<input required name="email" type="email" autocomplete="email" maxlength="254"></label>
          <button>Anmeldelink senden</button>
        </form>
      </section>
    </div>
  <?php endif ?>
<?php else: ?>
  <div class="page-title">
    <h1><?= $signatureSettings ? 'E-Mail-Signatur' : ($report ? 'Bearbeitungszeiten' : ($admin ? 'Ticketverwaltung' : 'Meine Tickets')) ?></h1>
    <div class="page-actions">
      <?php if ($admin && !$report): ?><a class="button secondary" href="?auswertung=1">Bearbeitungszeiten</a><?php endif ?>
      <?php if ($admin && !$signatureSettings): ?><a class="button secondary" href="?signatur=1">E-Mail-Signatur</a><?php endif ?>
      <form method="post" action="./"><?= csrfInput() ?><input type="hidden" name="action" value="logout"><button class="secondary">Abmelden</button></form>
    </div>
  </div>
  <?php if ($signatureSettings): ?>
    <a class="back" href="./">← Zur Ticketübersicht</a>
    <?php if (field($_GET, 'saved') === '1'): ?><p class="notice" role="status">Signatur gespeichert.</p><?php endif ?>
    <section class="panel detail">
      <form class="editor" method="post" action="?signatur=1">
        <?= csrfInput() ?>
        <label>Signaturtext<textarea name="signature" rows="7" maxlength="<?= MAX_MAIL_SIGNATURE_LENGTH ?>" aria-describedby="signature-help"><?= h($signature) ?></textarea></label>
        <p id="signature-help" class="meta">Die Signatur erscheint in der HTML- und der Textfassung der E-Mails. Mit einem leeren Feld versendest du E-Mails ohne Signatur.</p>
        <button name="action" value="save-signature">Signatur speichern</button>
      </form>
    </section>
  <?php elseif ($report): ?>
    <a class="back" href="./">← Zur Ticketübersicht</a>
    <section class="panel detail">
      <h2>Vom Ticketeingang bis zum Schließen</h2>
      <dl class="report-stats">
        <div><dt>Durchschnittliche Zeit</dt><dd><?= $stats['average_seconds'] === null ? '–' : h(formatDuration((float) $stats['average_seconds'])) ?></dd></div>
        <div><dt>Erledigt mit Messung</dt><dd><?= h($stats['measured_count']) ?></dd></div>
        <div><dt>Noch nicht erledigt</dt><dd><?= h($stats['open_count']) ?></dd></div>
        <div><dt>Erledigt ohne Schließzeitpunkt</dt><dd><?= h($stats['unknown_count']) ?></dd></div>
      </dl>
      <p class="meta">Gemessen wird die verstrichene Zeit einschließlich Wartezeiten. Bei wieder geöffneten Tickets zählt das erneute Schließen.</p>
      <?php if ($recent): ?>
        <h2>Zuletzt geschlossene Tickets</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Ticket</th><th>Geschlossen am</th><th>Dauer</th></tr></thead>
            <tbody>
              <?php foreach ($recent as $row): ?>
                <tr>
                  <td><a href="?id=<?= h($row['id']) ?>"><?= h($row['subject']) ?></a><small><?= h(ticketCode((int) $row['id'])) ?></small></td>
                  <td><?= h(date('d.m.Y, H:i', (int) $row['closed_at'])) ?></td>
                  <td><?= h(formatDuration((int) $row['closed_at'] - (int) $row['received_at'])) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
    </section>
  <?php elseif ($detail || $new): ?>
    <a class="back" href="<?= h($groupContext ? '?' . http_build_query($groupContext) : './') ?>">← Zur Ticketübersicht</a>
    <?php if ($detail && field($_GET, 'saved') === '1'): ?><p class="notice" role="status">Ticket gespeichert.</p><?php endif ?>
    <?php if ($admin && $detail && field($_GET, 'zugeordnet') === '1'): ?><p class="notice" role="status">Kundenzuordnung gespeichert.</p><?php endif ?>
    <?php if ($detail && field($_GET, 'closed') === '1' && $detail['status'] === 'Erledigt'): ?><p class="notice" role="status">Ticket geschlossen.</p><?php endif ?>
    <?php if ($detail): ?>
      <header class="panel detail ticket-heading">
        <div>
          <p class="meta ticket-reference"><?= h(ticketCode((int) $detail['id'])) ?> · <span class="badge"><?= h($detail['status']) ?></span></p>
          <h2><?= h($detail['subject']) ?></h2>
          <?php if ($admin): ?><p class="meta"><?= h($detail['email']) ?></p><?php endif ?>
        </div>
        <?php if (!$admin && $detail['status'] !== 'Erledigt'): ?>
          <form method="post" action="?id=<?= h($detail['id']) ?>">
            <?= csrfInput() ?><button class="secondary" name="action" value="close">Ticket schließen</button>
          </form>
        <?php endif ?>
      </header>
      <section id="kommentare" class="panel detail conversation" aria-labelledby="conversation-title">
        <h2 id="conversation-title">Konversation</h2>
        <?php $entries = array_merge([[
            'role' => 'customer',
            'created_at' => $detail['received_at'],
            'body' => $detail['body'] !== '' ? $detail['body'] : 'Die E-Mail enthält keinen übernommenen Nachrichtentext.',
        ]], $comments); ?>
        <ol class="conversation-list">
          <?php foreach ($entries as $position => $entry): ?>
            <?php
            $fromService = $entry['role'] === 'admin';
            [$messageText, $mailQuote] = !$fromService && $position > 0
                ? splitQuotedReply($entry['body'])
                : [$entry['body'], ''];
            $timestamp = (int) $entry['created_at'];
            ?>
            <li class="conversation-entry<?= $fromService ? ' conversation-entry-service' : '' ?>">
              <p class="conversation-byline"><strong><?= $fromService ? 'IT-Service' : 'Kunde' ?></strong>
                <time datetime="<?= h(date(DATE_ATOM, $timestamp)) ?>"><?= h(date('d.m.Y, H:i', $timestamp)) ?> Uhr</time></p>
              <div class="conversation-bubble">
                <div class="conversation-text"><?= h($messageText !== '' ? $messageText : 'Die E-Mail enthält keinen übernommenen Antworttext.') ?></div>
                <?php if ($position === 0 && $detail['attachment_notice']): ?>
                  <p class="conversation-attachment"><?= $admin ? 'Anhänge liegen in der Original-E-Mail im Ticketpostfach.' : 'Anhänge wurden mit der Original-E-Mail übermittelt.' ?></p>
                <?php endif ?>
                <?php if ($mailQuote !== ''): ?>
                  <details class="conversation-quote">
                    <summary>Zitierten E-Mail-Verlauf anzeigen</summary>
                    <div class="conversation-text"><?= h(compactEmailQuote($mailQuote)) ?></div>
                  </details>
                <?php endif ?>
              </div>
            </li>
          <?php endforeach ?>
        </ol>
        <?php if ($detail['status'] !== 'Erledigt'): ?>
          <form id="antwort" class="editor conversation-reply" method="post" action="?<?= h(http_build_query(['id' => $detail['id']] + $groupContext)) ?>#antwort">
            <?= csrfInput() ?>
            <label>Antwort<textarea name="comment" rows="4" maxlength="<?= MAX_COMMENT_LENGTH ?>" required><?= h(field($_POST, 'comment')) ?></textarea></label>
            <button name="action" value="comment">Antwort senden</button>
          </form>
        <?php endif ?>
      </section>
    <?php endif ?>
    <?php if ($admin): ?>
      <?php if ($detail): ?>
        <details class="panel detail ticket-tools"<?= $notice !== '' && in_array($action ?? '', ['assign-customer', 'save', 'delete'], true) ? ' open' : '' ?>>
          <summary>Ticket bearbeiten</summary>
      <?php else: ?>
        <article class="panel detail">
          <h2>Neues Ticket</h2>
      <?php endif ?>
        <?php if ($detail): ?>
          <section class="customer-assignment" aria-labelledby="customer-assignment-title">
            <h2 id="customer-assignment-title">Kundenzuordnung</h2>
            <?php if ($domainHint !== ''): ?>
              <p class="meta">Domain-Hinweis: @<?= h($domainHint) ?><?= $suggestions ? ' · Passende Kunden aus früheren Zuordnungen: ' . h(implode(', ', $suggestions)) : '' ?></p>
            <?php endif ?>
            <form class="editor" method="post">
              <?= csrfInput() ?><input type="hidden" name="revision" value="<?= h($detail['revision']) ?>">
              <div class="form-grid">
                <label>Kunde<select name="customer_id">
                  <option value="0">Ohne Kundenzuordnung</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= h($customer['id']) ?>"<?= (int) $customer['id'] === $assignedCustomer ? ' selected' : '' ?>><?= h($customer['name']) ?></option>
                  <?php endforeach ?>
                </select></label>
                <label>Oder neuen Kunden anlegen und zuordnen<input name="new_customer" maxlength="<?= MAX_CUSTOMER_NAME_LENGTH ?>" value="<?= h(field($_POST, 'new_customer')) ?>"></label>
              </div>
              <button class="secondary" name="action" value="assign-customer">Zuordnung speichern</button>
            </form>
          </section>
        <?php endif ?>
        <form class="editor" method="post">
          <?= csrfInput() ?><input type="hidden" name="id" value="<?= h($detail['id'] ?? 0) ?>"><input type="hidden" name="revision" value="<?= h($detail['revision'] ?? 0) ?>">
          <?php if ($new): ?>
            <label>E-Mail-Adresse des Kunden<input required type="email" name="email" maxlength="254" value="<?= h(field($_POST, 'email')) ?>"></label>
            <label>Betreff<input required name="subject" maxlength="180" value="<?= h(field($_POST, 'subject')) ?>"></label>
            <label>Beschreibung<textarea rows="5" name="body" maxlength="24000"><?= h(field($_POST, 'body')) ?></textarea></label>
          <?php endif ?>
          <div class="form-grid">
            <label>Status<?php enumSelect('status', STATUSES, $detail['status'] ?? field($_POST, 'status')) ?></label>
            <label>Priorität<?php enumSelect('priority', PRIORITIES, $detail['priority'] ?? (field($_POST, 'priority') ?: 'Normal')) ?></label>
            <label>Fällig am<input type="date" name="due" value="<?= h($detail['due'] ?? field($_POST, 'due')) ?>"></label>
          </div>
          <label>Interne Notizen<textarea rows="4" name="notes" maxlength="24000"><?= h($detail['notes'] ?? field($_POST, 'notes')) ?></textarea></label>
          <button name="action" value="save"><?= $new ? 'Ticket erstellen' : 'Änderungen speichern' ?></button>
        </form>
        <?php if ($detail): ?>
          <details class="delete">
            <summary>Ticket löschen</summary>
            <form method="post">
              <?= csrfInput() ?><input type="hidden" name="id" value="<?= h($detail['id']) ?>"><input type="hidden" name="revision" value="<?= h($detail['revision']) ?>">
              <p>Das Ticket wird endgültig aus dem Portal gelöscht.</p><button class="danger" name="action" value="delete">Endgültig löschen</button>
            </form>
          </details>
        <?php endif ?>
      <?php if ($detail): ?>
        </details>
      <?php else: ?>
        </article>
      <?php endif ?>
    <?php endif ?>
  <?php else: ?>
    <?php if ($admin): ?>
      <nav class="list-views" aria-label="Ansicht der Ticketübersicht">
        <a class="button<?= $grouped || $onlyNew ? ' secondary' : '' ?>" href="?<?= h(http_build_query($filters)) ?>"<?= !$grouped && !$onlyNew ? ' aria-current="page"' : '' ?>>Alle Tickets</a>
        <a class="button<?= $onlyNew ? '' : ' secondary' ?>" href="?neu=1"<?= $onlyNew ? ' aria-current="page"' : '' ?>>Neue Tickets (<?= h($unreadCount) ?>)</a>
        <a class="button<?= $grouped ? '' : ' secondary' ?>" href="?<?= h(http_build_query($filters + ['gruppe' => 'kunden'])) ?>"<?= $customerOverview ? ' aria-current="page"' : '' ?>>Nach Kunden gruppieren</a>
      </nav>
    <?php endif ?>
    <?php if (!empty($customerName)): ?>
      <h2><?= $customerFilter === 0 ? h($customerName) : 'Tickets für ' . h($customerName) ?></h2>
    <?php endif ?>
    <section class="panel listing">
      <form class="filters" method="get">
        <label>Suche<input type="search" name="q" value="<?= h($search) ?>" placeholder="<?= $admin ? 'Betreff, Kunde, E-Mail oder Ticketnummer' : 'Betreff oder Ticketnummer' ?>"></label>
        <label>Status<select name="status"><option value="">Alle Status</option>
          <?php foreach (STATUSES as $status): ?><option<?= $status === $filter ? ' selected' : '' ?>><?= h($status) ?></option><?php endforeach ?>
        </select></label>
        <button>Filtern</button>
        <?php if ($admin): ?><a class="button secondary" href="?new=1">Neues Ticket</a><?php endif ?>
        <?php if ($grouped): ?><input type="hidden" name="gruppe" value="kunden"><?php endif ?>
        <?php if ($customerFilter !== null): ?><input type="hidden" name="kunde" value="<?= h($customerFilter) ?>"><?php endif ?>
        <?php if ($onlyNew): ?><input type="hidden" name="neu" value="1"><?php endif ?>
      </form>
      <?php if (!$rows): ?>
        <p class="empty"><?= $onlyNew ? 'Keine passenden neuen Tickets vorhanden.' : 'Keine passenden Tickets vorhanden.' ?></p>
      <?php elseif ($customerOverview): ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">Kunde</th><th scope="col">Tickets</th><th scope="col">Nicht erledigt</th><th scope="col">Letzter Eingang</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($rows, 0, 50) as $row): ?>
                <tr>
                  <td><a href="?<?= h(http_build_query($listParams + ['kunde' => $row['customer_id']])) ?>"><?= h($row['customer_name'] ?? 'Ohne Kundenzuordnung') ?></a></td>
                  <td><?= h($row['total']) ?></td>
                  <td><?= h($row['active']) ?></td>
                  <td><?= h(date('d.m.Y', (int) $row['latest'])) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th scope="col">Ticket</th><?php if ($admin): ?><th scope="col">Priorität</th><th scope="col">Fällig am</th><?php endif ?><th scope="col">Eingang</th></tr></thead>
            <?php foreach ($statusGroups as $status => $tickets): ?>
            <tbody>
              <tr class="ticket-status-group"><th scope="rowgroup" colspan="<?= $admin ? 4 : 2 ?>"><?= h($status) ?></th></tr>
            <?php foreach ($tickets as $row): ?>
              <tr>
                <td><a href="?<?= h(http_build_query(['id' => $row['id']] + $groupContext)) ?>"><?= h($row['subject']) ?></a>
                  <?php if ($admin && $row['is_new']): ?><span class="badge ticket-new">Neu</span><?php endif ?>
                  <small><?= h(ticketCode((int) $row['id'])) ?><?= $admin ? ' · ' . h($row['email']) : '' ?></small>
                  <?php if ($admin && $row['customer_name'] !== null): ?><small><?= h($row['customer_name']) ?></small><?php endif ?>
                </td>
                <?php if ($admin): ?>
                  <td><?= h($row['priority']) ?></td>
                  <td class="<?= $row['due'] !== '' && $row['due'] < date('Y-m-d') && $row['status'] !== 'Erledigt' ? 'overdue' : '' ?>"><?= $row['due'] ? h(date('d.m.Y', strtotime($row['due']))) : '–' ?></td>
                <?php endif ?>
                <td><?= h(date('d.m.Y', (int) $row['received_at'])) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
            <?php endforeach ?>
          </table>
        </div>
      <?php endif ?>
      <?php if ($page > 1 || count($rows) > 50): ?>
        <div class="pagination">
          <?php if ($page > 1): ?><a href="?<?= h(http_build_query($listParams + ['seite' => $page - 1])) ?>">← Zurück</a><?php endif ?>
          <?php if (count($rows) > 50): ?><a href="?<?= h(http_build_query($listParams + ['seite' => $page + 1])) ?>">Weiter →</a><?php endif ?>
        </div>
      <?php endif ?>
    </section>
  <?php endif ?>
<?php endif ?>
</main>
<footer class="site-footer">
  <div class="footer-content">
    <span>© <?= h(date('Y')) ?> IT-Service Christian Waxenberger</span>
    <nav aria-label="Weitere Links">
      <?php if (!$user && !$adminLogin): ?><a href="?admin=1">Verwaltung</a><?php endif ?>
      <a href="https://edv-waxenberger.de/impressum/">Impressum</a>
      <a href="https://edv-waxenberger.de/datenschutz/">Datenschutz</a>
    </nav>
  </div>
</footer>
</body>
</html>

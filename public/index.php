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
    if ($report && !$admin) {
        http_response_code(403);
        exit('Dieser Bereich ist für die Verwaltung vorgesehen.');
    }
    $id = (int) field($_GET, 'id');
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
        if ($action === 'comment' || $action === 'close') {
            if (!$user || ($action === 'close' && $admin)) {
                http_response_code(403);
                exit('Diese Aktion ist für Ihr Konto nicht verfügbar.');
            }
            if ($action === 'close') {
                $closed = query($db, "UPDATE tickets SET status = 'Erledigt', revision = revision + 1
                    WHERE id = ? AND email = ? AND status <> 'Erledigt'", [$id, $user['email']]);
                if ($closed->rowCount() > 0) {
                    go($config, '?id=' . $id . '&closed=1');
                }
                $notice = 'Dieses Ticket ist bereits geschlossen.';
            } else {
                $comment = trim(field($_POST, 'comment'));
                if (strlen($comment) > MAX_COMMENT_LENGTH * 4 ||
                    !preg_match('/\A.{1,' . MAX_COMMENT_LENGTH . '}\z/su', $comment)) {
                    $notice = 'Bitte geben Sie einen Kommentar mit höchstens ' . MAX_COMMENT_LENGTH . ' Zeichen ein.';
                } else {
                    $params = [$user['role'], $comment, time(), $id];
                    if (!$admin) {
                        $params[] = $user['email'];
                    }
                    $added = query($db, "INSERT INTO ticket_comments (ticket_id, role, body, created_at)
                        SELECT id, ?, ?, ? FROM tickets WHERE id = ? AND status <> 'Erledigt'" .
                        ($admin ? '' : ' AND email = ?'), $params);
                    if ($added->rowCount() > 0) {
                        go($config, '?id=' . $id . '#kommentare');
                    }
                    $notice = 'Dieses Ticket ist bereits geschlossen.';
                }
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
                    go($config);
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
                        go($config, '?id=' . $id . '&saved=1');
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
    $search = trim(substr(field($_GET, 'q'), 0, 250));
    $filter = field($_GET, 'status');
    if ($report) {
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
        } else {
            http_response_code(404);
            $notice = 'Dieses Ticket konnte nicht gefunden werden.';
        }
    }
    if ($user && !$report && !$new && !$detail) {
        $where = $admin ? [] : ['email = ?'];
        $parameters = $admin ? [] : [$user['email']];
        if ($search !== '') {
            $where[] = "(instr(lower(subject), lower(?)) > 0 OR instr(lower(email), lower(?)) > 0 OR printf('T-%04d', id) = upper(?))";
            array_push($parameters, $search, $search, $search);
        }
        if (in_array($filter, STATUSES, true)) {
            $where[] = 'status = ?';
            $parameters[] = $filter;
        }
        $page = max(1, min(100000, (int) field($_GET, 'seite')));
        $offset = ($page - 1) * 50;
        $rows = query($db, 'SELECT id, subject, email, status, priority, due, received_at FROM tickets' .
            ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 51 OFFSET ' . $offset,
            $parameters)->fetchAll();
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
  <link rel="stylesheet" href="style.css?v=3">
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
    <h1><?= $report ? 'Bearbeitungszeiten' : ($admin ? 'Ticketverwaltung' : 'Meine Tickets') ?></h1>
    <div class="page-actions">
      <?php if ($admin && !$report): ?><a class="button secondary" href="?auswertung=1">Bearbeitungszeiten</a><?php endif ?>
      <form method="post" action="./"><?= csrfInput() ?><input type="hidden" name="action" value="logout"><button class="secondary">Abmelden</button></form>
    </div>
  </div>
  <?php if ($report): ?>
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
    <a class="back" href="./">← Zur Ticketübersicht</a>
    <?php if ($detail && field($_GET, 'saved') === '1'): ?><p class="notice" role="status">Ticket gespeichert.</p><?php endif ?>
    <?php if ($detail && field($_GET, 'closed') === '1' && $detail['status'] === 'Erledigt'): ?><p class="notice" role="status">Ticket geschlossen.</p><?php endif ?>
    <article class="panel detail">
      <h2><?= $detail ? h(ticketCode((int) $detail['id']) . ' · ' . $detail['subject']) : 'Neues Ticket' ?></h2>
      <?php if ($detail): ?>
        <p class="meta"><span class="badge"><?= h($detail['status']) ?></span> Eingegangen am <?= h(date('d.m.Y, H:i', (int) $detail['received_at'])) ?> Uhr</p>
        <?php if ($admin): ?><p>Absender: <?= h($detail['email']) ?></p><?php endif ?>
        <div class="message"><?= h($detail['body'] !== '' ? $detail['body'] : 'Die E-Mail enthält keinen übernommenen Nachrichtentext.') ?></div>
        <?php if ($detail['attachment_notice']): ?><p class="meta"><?= $admin ? 'Anhänge liegen in der Original-E-Mail im Ticketpostfach.' : 'Anhänge wurden mit der Original-E-Mail übermittelt.' ?></p><?php endif ?>
        <?php if (!$admin && $detail['status'] !== 'Erledigt'): ?>
          <form method="post" action="?id=<?= h($detail['id']) ?>">
            <?= csrfInput() ?><button class="secondary" name="action" value="close">Ticket schließen</button>
          </form>
        <?php endif ?>
      <?php endif ?>
      <?php if ($admin): ?>
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
      <?php endif ?>
    </article>
    <?php if ($detail): ?>
      <section id="kommentare" class="panel detail comments">
        <h2>Kommentare</h2>
        <?php if (!$comments): ?><p class="meta">Noch keine Kommentare.</p><?php endif ?>
        <?php foreach ($comments as $comment): ?>
          <article class="ticket-comment">
            <p class="meta"><strong><?= $comment['role'] === 'admin' ? 'IT-Service' : 'Kunde' ?></strong> · <?= h(date('d.m.Y, H:i', (int) $comment['created_at'])) ?> Uhr</p>
            <div class="message"><?= h($comment['body']) ?></div>
          </article>
        <?php endforeach ?>
        <?php if ($detail['status'] !== 'Erledigt'): ?>
          <form class="editor" method="post" action="?id=<?= h($detail['id']) ?>#kommentare">
            <?= csrfInput() ?>
            <label>Kommentar für Kunde und IT-Service<textarea name="comment" rows="4" maxlength="<?= MAX_COMMENT_LENGTH ?>" required><?= h(field($_POST, 'comment')) ?></textarea></label>
            <button name="action" value="comment">Kommentar hinzufügen</button>
          </form>
        <?php endif ?>
      </section>
    <?php endif ?>
  <?php else: ?>
    <section class="panel listing">
      <form class="filters" method="get">
        <label>Suche<input type="search" name="q" value="<?= h($search) ?>" placeholder="Betreff oder Ticketnummer"></label>
        <label>Status<select name="status"><option value="">Alle Status</option>
          <?php foreach (STATUSES as $status): ?><option<?= $status === $filter ? ' selected' : '' ?>><?= h($status) ?></option><?php endforeach ?>
        </select></label>
        <button>Filtern</button>
        <?php if ($admin): ?><a class="button secondary" href="?new=1">Neues Ticket</a><?php endif ?>
      </form>
      <?php if (!$rows): ?>
        <p class="empty">Keine passenden Tickets vorhanden.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Ticket</th><th>Status</th><?php if ($admin): ?><th>Priorität</th><th>Fällig am</th><?php endif ?><th>Eingang</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, 50) as $row): ?>
              <tr>
                <td><a href="?id=<?= h($row['id']) ?>"><?= h($row['subject']) ?></a>
                  <small><?= h(ticketCode((int) $row['id'])) ?><?= $admin ? ' · ' . h($row['email']) : '' ?></small></td>
                <td><span class="badge"><?= h($row['status']) ?></span></td>
                <?php if ($admin): ?>
                  <td><?= h($row['priority']) ?></td>
                  <td class="<?= $row['due'] !== '' && $row['due'] < date('Y-m-d') && $row['status'] !== 'Erledigt' ? 'overdue' : '' ?>"><?= $row['due'] ? h(date('d.m.Y', strtotime($row['due']))) : '–' ?></td>
                <?php endif ?>
                <td><?= h(date('d.m.Y', (int) $row['received_at'])) ?></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
      <?php if ($page > 1 || count($rows) > 50): ?>
        <div class="pagination">
          <?php if ($page > 1): ?><a href="?<?= h(http_build_query(['q' => $search, 'status' => $filter, 'seite' => $page - 1])) ?>">← Zurück</a><?php endif ?>
          <?php if (count($rows) > 50): ?><a href="?<?= h(http_build_query(['q' => $search, 'status' => $filter, 'seite' => $page + 1])) ?>">Weiter →</a><?php endif ?>
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

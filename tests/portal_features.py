"""Integration test: python3 tests/portal_features.py [PHP executable]."""
import base64
import email.policy
import fcntl
import hashlib
import io
import json
import re
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
import zipfile
from email.message import EmailMessage
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = sys.argv.pop(1) if len(sys.argv) > 1 else 'php'
PNG = base64.b64decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8'
    '/x8AAwMCAO+jRcsAAAAASUVORK5CYII='
)
BINARY = b'\x00\xff\x00Test\r\n'
REASON = 'Der Fehler tritt nach einem Neustart wieder auf.'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None


class PortalFeatures(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.site = Path(temporary.name)
        for directory in ('private', 'public'):
            shutil.copytree(ROOT / directory, self.site / directory,
                            ignore=shutil.ignore_patterns('config.php', 'data'))
        self.private = self.site / 'private'
        (self.private / 'config.php').write_text("""<?php return [
            'base_url' => 'https://tickets.example.com/',
            'admin_email' => 'admin@example.com',
            'mail_email' => 'ticket@example.com', 'secret' => str_repeat('a', 64),
        ];""")
        self.php('worker.php', '--setup')
        self.db_path = self.private / 'data/tickets.sqlite3'
        self.tokens = {}
        with self.database() as db:
            db.execute("INSERT INTO tickets(email,subject,body,status,received_at) "
                       "VALUES('kunde@example.com','Drucker','Anfrage','Erledigt',100)")
            db.execute('INSERT INTO ticket_archive VALUES(1,100)')
            db.execute("INSERT INTO mail_settings VALUES(1,'Testsignatur')")
            db.execute("INSERT INTO customers VALUES(1,'Beispiel GmbH')")
            db.execute('INSERT INTO ticket_customers VALUES(1,1)')
            db.execute("INSERT INTO ticket_mail_references VALUES('<reply@example.com>',1)")
            db.execute("INSERT INTO mailbox VALUES(1,'test','7',0)")
            db.execute('DROP TRIGGER ticket_activity_comment')
            db.execute('DROP TRIGGER ticket_activity_status_change')
            db.execute('DROP TABLE ticket_activity')
            db.execute('DROP TABLE ticket_attachments')
            db.execute('DROP INDEX ticket_comments_attachment_parent')
            db.execute('PRAGMA user_version=8')
            for index, (role, address) in enumerate(
                [('admin', 'admin@example.com'), ('owner', 'kunde@example.com'), ('other', 'other@example.com')]
            ):
                token = str(index) * 64
                self.tokens[role] = token
                db.execute('INSERT INTO links VALUES(?,?,?,?,NULL)', (
                    hashlib.sha256(token.encode()).hexdigest(), address,
                    'admin' if role == 'admin' else 'customer', int(time.time()) + 600,
                ))
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        self.url = f'http://127.0.0.1:{port}/'
        self.opener = urllib.request.build_opener(NoRedirect())
        log_path = self.site / 'server.log'
        with log_path.open('w') as log:
            server = subprocess.Popen([PHP, '-d', 'upload_max_filesize=20M',
                '-d', 'post_max_size=24M', '-d', f'upload_tmp_dir={self.site}', '-S', f'127.0.0.1:{port}', '-t', self.site / 'public'],
                stdout=log, stderr=log)
        self.addCleanup(server.wait, 5)
        self.addCleanup(server.terminate)
        for _ in range(50):
            try:
                self.http('')
                break
            except urllib.error.URLError:
                time.sleep(.05)
        else:
            self.fail(log_path.read_text())
        self.admin = self.login('admin')
        self.owner = self.login('owner')
        self.other = self.login('other')

    def php(self, *args):
        result = subprocess.run([PHP, *args], cwd=self.private, check=False, capture_output=True)
        self.assertEqual(result.returncode, 0, (result.stdout, result.stderr))
        return result.stdout

    def database(self):
        db = sqlite3.connect(self.db_path)
        db.execute('PRAGMA foreign_keys=ON')
        self.addCleanup(db.close)
        return db

    def http(self, path, cookie=None, data=None, files=()):
        headers = {} if cookie is None else {'Cookie': cookie}
        body = None
        if data is not None and files:
            parts = []
            boundary = 'fixture-boundary-57'
            for key, value in data.items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
            for key, name, content in files:
                parts.append(
                    f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="{name}"\r\n'
                    .encode() + b'Content-Type: application/octet-stream\r\n\r\n' + content + b'\r\n'
                )
            body = b''.join(parts) + f'--{boundary}--\r\n'.encode()
            headers['Content-Type'] = f'multipart/form-data; boundary={boundary}'
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
        request = urllib.request.Request(self.url + path, body, headers)
        try:
            response = self.opener.open(request, timeout=8)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.code, response.headers, response.read()

    def csrf(self, html):
        return re.search(rb'name="csrf" value="([^"]+)', html).group(1).decode()

    def login(self, role):
        status, headers, body = self.http('?link=1')
        self.assertEqual(status, 200)
        cookie = headers['Set-Cookie'].split(';')[0]
        status, headers, _ = self.http('?link=1', cookie,
            dict(csrf=self.csrf(body), action='redeem', token=self.tokens[role]))
        self.assertEqual(status, 303)
        return headers['Set-Cookie'].split(';')[0]

    def page(self, path, cookie):
        status, _, body = self.http(path, cookie)
        self.assertEqual(status, 200, (body, (self.site / "server.log").read_text()[-1200:]))
        return body.decode()

    def post(self, path, cookie, files=(), **data):
        status, _, body = self.http(path, cookie)
        if status != 200:  # A non-owner can submit a valid token from their own page.
            _, _, body = self.http('', cookie)
        return self.http(path, cookie, dict(data, csrf=self.csrf(body)), files)

    def test_end_to_end(self):
        self.assertIn('Begründung zum Wiederöffnen', self.page('?id=1', self.owner))
        self.assertNotIn('Ticket wieder öffnen', self.page('?id=1', self.admin))
        self.assertEqual(self.post('?id=1', self.admin, action='reopen', comment=REASON)[0], 403)
        self.assertEqual(self.post('?id=1', self.other, action='reopen', comment=REASON)[0], 404)
        self.assertIn('Bitte geben Sie einen Grund', self.post('?id=1', self.owner, action='reopen', comment=' ')[2].decode())
        status, _, page = self.post('?id=1', self.owner, action='reopen', comment=REASON,
                                    files=[('attachments[]', 'Drucker.png', PNG)])
        self.assertEqual(status, 303, (page[:1200], (self.site / "server.log").read_text()[-3000:]))
        self.assertEqual(self.post('?id=1', self.owner, action='reopen', comment=REASON)[0], 200)
        self.assertIn('Kunde: Erledigt → <strong>Wieder geöffnet</strong>', self.page('?id=1', self.admin))
        self.assertIn('status-group-reopened', self.page('', self.admin))
        self.assertIn(REASON, self.page('?id=1', self.owner))
        with self.database() as db:
            self.assertEqual(db.execute('PRAGMA user_version').fetchone(), (9,))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ticket_archive').fetchone(), (0,))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ticket_closures').fetchone(), (0,))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM status_notifications').fetchone(), (2,))
        sent = json.loads(self.php('-r', """
            require 'app.php'; require 'mail.php';
            $messages = [];
            deliverOutbox(database(), appConfig(), function ($config,$recipient,$subject,$body) use (&$messages) {
                $messages[$recipient] = [$subject,$body,renderHtmlMail($config['base_url'], $subject, $body, '', null,
                    $config['notification_status'])];
            });
            echo json_encode($messages, JSON_THROW_ON_ERROR);
        """))
        self.assertEqual(set(sent), {'admin@example.com', 'kunde@example.com'})
        for subject, body, html in sent.values():
            self.assertIn('Statusänderung', subject)
            self.assertIn(REASON, body)
            self.assertIn('Begründung zum Wiederöffnen', body)
            self.assertIn('Dateianhänge finden Sie im Ticketportal', body)
            self.assertIn('#0d766d', html)

        self.assertEqual(self.post('?id=1', self.admin, action='comment', comment='',
                                  files=[('attachments[]', 'Bericht.bin', BINARY)])[0], 303)
        self.assertEqual(self.post('?new=1', self.admin, action='save', id=0, status='Offen', priority='Normal',
                                  email='other@example.com', subject='Angelegt', body='Test',
                                  files=[('attachments[]', 'Einrichtung.txt', BINARY)])[0], 303)
        self.assertIn('Bericht.bin', self.page('?id=1', self.owner))
        self.assertIn('Einrichtung.txt', self.page('?id=2', self.other))
        self.assertEqual(self.http('?anhang=1', self.other)[0], 404)
        self.assertEqual(self.http('?anhang=1')[0], 404)
        status, headers, body = self.http('?anhang=1&vorschau=1', self.owner)
        self.assertEqual((status, headers['Content-Type'], body), (200, 'image/png', PNG))
        self.assertIn('inline', headers['Content-Disposition'])
        status, headers, body = self.http('?anhang=2&vorschau=1', self.owner)
        self.assertEqual((status, headers['Content-Type'], body), (200, 'application/octet-stream', BINARY))
        self.assertIn('attachment', headers['Content-Disposition'])
        self.assertIn('höchstens 10 Anhänge', self.post(
            '?id=1', self.owner, action='comment', comment='',
            files=[('attachments[]', 'gross.bin', b'x' * (8 * 1024 * 1024 + 1))])[2].decode())

        self.import_mails()
        self.assertIn('aus-mail.png', self.page('?id=1', self.owner))
        self.assertIn('aus-mail.bin', self.page('?id=3', self.admin))
        with self.database() as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ticket_attachments').fetchone(), (7,))
            self.assertEqual(db.execute('SELECT last_uid FROM mailbox').fetchone(), (3,))
        self.check_backup()

    def import_mails(self):
        for uid, reference in enumerate(('', '<reply@example.com>'), 1):
            mail = EmailMessage(policy=email.policy.SMTP)
            mail['From'] = 'kunde@example.com'
            mail['To'] = 'ticket@example.com'
            mail['Subject'] = 'Test-Import'
            mail['Message-ID'] = f'<import-{uid}@example.com>'
            if reference:
                mail['In-Reply-To'] = reference
            mail.set_content('Hier sind beide Dateien.')
            mail.add_alternative('<p>Hallo</p><img src="cid:test">', subtype='html')
            mail.get_payload()[1].add_related(PNG, 'image', 'png', cid='<test>', filename='aus-mail.png')
            mail.add_attachment(BINARY, 'application', 'octet-stream', filename='aus-mail.bin')
            (self.private / f'mail-{uid}.eml').write_bytes(mail.as_bytes())
        self.php('-r', """
            require 'vendor/autoload.php'; require 'app.php'; require 'mail.php';
            $db = database(); $config = appConfig();
            foreach ([1 => 1, 2 => 2, 3 => 2] as $uid => $source) {
                $mail = parseTicketMail(file_get_contents("mail-$source.eml"), false, $config);
                if (count($mail['files']) !== 2 || $mail['attachments'] !== 0) { exit(2); }
                storeImportedMessage($db, $mail, 'test', '7', $uid, $config['admin_email']);
            }
        """)

    def check_backup(self):
        self.assertEqual(self.http('?sicherung=1', self.owner)[0], 403)
        self.assertEqual(self.post('', self.owner, action='backup-export')[0], 403)
        self.assertEqual(self.http('?sicherung=1', self.admin, {'action': 'backup-export'})[0], 403)
        status, headers, archive = self.post('?sicherung=1', self.admin, action='backup-export')
        self.assertEqual((status, headers['Content-Type']), (200, 'application/zip'), archive[:200])
        with zipfile.ZipFile(io.BytesIO(archive)) as backup:
            original = backup.read('tickets.sqlite3')
            manifest = json.loads(backup.read('manifest.json'))
            self.assertEqual(manifest['sha256'], hashlib.sha256(original).hexdigest())
        with self.database() as db:
            db.execute('DELETE FROM tickets WHERE id=1')
            db.execute('DELETE FROM mail_settings')
            db.execute("INSERT INTO tickets(email,subject,body,received_at) VALUES('kunde@example.com','Nach Sicherung','',1)")
        corrupt = io.BytesIO()
        with zipfile.ZipFile(corrupt, 'w') as backup:
            backup.writestr('manifest.json', json.dumps(manifest))
            backup.writestr('tickets.sqlite3', original[:-1] + bytes([original[-1] ^ 1]))
        self.assertIn('beschädigt', self.post('?sicherung=1', self.admin, action='backup-restore', replace_data=1,
            files=[('backup', 'bad.zip', corrupt.getvalue())])[2].decode())
        valid_file = [('backup', 'sicherung.zip', archive)]
        self.assertIn('Bitte bestätigen', self.post('?sicherung=1', self.admin, action='backup-restore',
                                                    files=valid_file)[2].decode())
        altered = self.site / 'altered.sqlite3'
        altered.write_bytes(original)
        with sqlite3.connect(altered) as db:
            db.execute('CREATE TABLE another_application(id INTEGER PRIMARY KEY)')
        wrong_data = altered.read_bytes()
        manifest['sha256'] = hashlib.sha256(wrong_data).hexdigest()
        wrong_schema = io.BytesIO()
        with zipfile.ZipFile(wrong_schema, 'w') as backup:
            backup.writestr('manifest.json', json.dumps(manifest))
            backup.writestr('tickets.sqlite3', wrong_data)
        self.assertIn('Integrität', self.post('?sicherung=1', self.admin, action='backup-restore', replace_data=1,
            files=[('backup', 'falsches-schema.zip', wrong_schema.getvalue())])[2].decode())
        with (self.private / 'data/worker.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertIn('E-Mail-Aufgabe läuft gerade', self.post(
                '?sicherung=1', self.admin, action='backup-restore', replace_data=1, files=valid_file)[2].decode())
        self.assertIn('Nach Sicherung', self.page('', self.owner))
        status, _, body = self.post('?sicherung=1', self.admin, action='backup-restore', replace_data=1, files=valid_file)
        self.assertEqual(status, 303, body.decode())
        self.assertNotIn('Nach Sicherung', self.page('', self.owner))
        self.assertEqual(self.http('?anhang=1&vorschau=1', self.owner)[2], PNG)
        self.assertEqual(self.http('?anhang=2', self.owner)[2], BINARY)
        with self.database() as db:
            self.assertEqual(db.execute('PRAGMA integrity_check').fetchone(), ('ok',))
            self.assertEqual(db.execute('PRAGMA foreign_key_check').fetchall(), [])
            self.assertEqual(db.execute('SELECT signature FROM mail_settings').fetchone(), ('Testsignatur',))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM links').fetchone(), (0,))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ticket_customers').fetchone(), (1,))
            self.assertEqual(db.execute('SELECT COUNT(*) FROM ticket_attachments').fetchone(), (7,))
            self.assertGreaterEqual(db.execute("SELECT seq FROM sqlite_sequence WHERE name='tickets'").fetchone()[0], 4)
        self.assertEqual(self.post('?id=1', self.owner, action='close')[0], 303)
        with self.database() as db:
            db.execute('UPDATE ticket_status_history SET created_at=100 WHERE ticket_id=1')
            db.execute('UPDATE ticket_comments SET created_at=100 WHERE ticket_id=1')
        timeline = self.page('?id=1', self.owner)
        reopened = timeline.index('Kunde: Erledigt → <strong>Wieder geöffnet</strong>')
        closed = timeline.index('Kunde: Wieder geöffnet → <strong>Erledigt</strong>')
        self.assertLess(reopened, timeline.index(REASON))
        self.assertLess(timeline.index(REASON), closed)


if __name__ == '__main__':
    unittest.main()

# Ticketportal bei netcup einrichten

Die Schritte beziehen sich auf netcup Webhosting mit dem Webhosting Control Panel (WCP). Die angebotenen PHP-Versionen und Module hängen vom konkreten Webspace ab. Das Installationspaket enthält einen Test für den PHP-Job, der ohne Postfachzugang und ohne Datenbankdatei läuft.

## 1. Subdomain und HTTPS

- Im Customer Control Panel (CCP) **Produkte → Lupe beim Webhosting** öffnen. Im WCP unter **Websites & Domains** die Subdomain `ticket.edv-waxenberger.de` anlegen.
- Das Paket in einen eigenen Ordner entpacken, z. B. `waxenberger-tickets` neben `httpdocs`, sofern `httpdocs` der Dokumentenstamm der Firmenwebsite ist. Als Dokumentenstamm der Subdomain `waxenberger-tickets/public` einstellen. Der Ordner `waxenberger-tickets/private` darf unter keiner Domain öffentlich erreichbar sein. Den tatsächlichen Dokumentenstamm der Hauptwebsite vor dem Upload prüfen.
- Unter **SSL/TLS-Zertifikate** ein Let's-Encrypt-Zertifikat für die Ticket-Subdomain ausstellen und in den Hosting-Einstellungen die Weiterleitung zu HTTPS aktivieren. Falls der Name noch nicht aufgelöst wird, im CCP den DNS-Eintrag der Subdomain prüfen. Die Webserver-Adressen stehen im WCP.
- Unter **Entwicklertools → PHP** für die Ticket-Subdomain eine PHP-Version ab 8.2 mit `pdo_sqlite` wählen. Bei `open_basedir` den von netcup angebotenen Eintrag mit `{WEBSPACEROOT}` als Basis wählen. Damit kann PHP die private Konfiguration außerhalb des Dokumentenstamms laden. Diese Einstellung nur für die Ticket-Subdomain setzen. Über **Die phpinfo() Seite ansehen** lässt sich `pdo_sqlite` für die Website prüfen.

[Hosting-Einstellungen](https://www.netcup.com/de/helpcenter/dokumentation/webhosting/hosting-einstellungen-verwalten) · [SSL/TLS](https://www.netcup.com/de/helpcenter/dokumentation/webhosting/ssl-verschluesselung-webhosting) · [PHP-Einstellungen](https://www.netcup.com/de/helpcenter/dokumentation/webhosting/php-einstellungen)

## 2. Mailpostfach konfigurieren

Im E-Mail-Panel unter **E-Mail → ticket@edv-waxenberger.de → Mailclients konfigurieren** die tatsächlichen Verbindungsdaten ablesen. Falls die Adresse noch nicht besteht, zuerst als Postfach anlegen.

| Schlüssel in `private/config.php` | Einstellung |
| --- | --- |
| `base_url` | `https://ticket.edv-waxenberger.de/` |
| `imap_host`, `smtp_host` | Jeweils der im E-Mail-Panel angezeigte Servername |
| `imap_port` | Üblicherweise `993` mit SSL/TLS |
| `smtp_port`, `smtp_mode` | Üblicherweise `465` und `implicit`. Bei `587` mit STARTTLS lautet der Modus `starttls`. |
| `imap_user`, `smtp_user`, `mail_email` | `ticket@edv-waxenberger.de` |
| `imap_password`, `smtp_password` | Das im E-Mail-Panel gesetzte Postfachpasswort |
| `admin_email` | Deine eigene, vom Ticketpostfach getrennte Mailadresse |
| `secret` | 64 zufällige Hex-Zeichen, erzeugt z. B. mit `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` |

`private/config.example.php` nach `private/config.php` kopieren. Das Postfachpasswort und den Schlüssel nur in der privaten Konfiguration auf dem Webspace hinterlegen. Die Angaben aus dem E-Mail-Panel haben Vorrang.

[netcup Webhosting- und Mailserver-Übersicht](https://www.netcup.com/de/helpcenter/dokumentation/webhosting/interface) · [Mailclient-Einstellungen](https://netcupwiki.com/en/web-hosting/operations/external-mailclients/)

## 3. PHP-Aufgabe einrichten und testen

Im CCP **Produkte → Lupe beim Webhosting → Plesk Panel-Links → Geplante Aufgaben** öffnen. Aufgabe vom Typ **PHP-Skript ausführen** anlegen. Über die Dateiauswahl `waxenberger-tickets/private/worker.php` auswählen. Wenn der Netcup-Dateidialog nur Pfade anzeigt, den genauen Pfad aus dem Dateimanager verwenden.

1. In der Aufgabe PHP 8.3 wählen, falls angeboten, und das Argument `--check` eintragen. **Jetzt ausführen**. Erwartet werden PHP ab 8.2, `pdo_sqlite`, `openssl`, `mbstring`, `curl` mit SMTP/SMTPS, der mitgelieferte E-Mail-Parser und SQLite ab 3.35.
2. Nach erfolgreicher Prüfung einmal mit `--setup` ausführen. Der private Datenordner und die SQLite-Datenbank werden angelegt.
3. Danach einmal mit `--init` ausführen. Damit beginnt der automatische Import bei Nachrichten, die nach der Initialisierung eintreffen.
4. Das Argument entfernen, die Aufgabe beispielsweise alle zwei Minuten einplanen und Benachrichtigungen bei Fehlern aktivieren. Für den laufenden Betrieb braucht das Portal eine geplante Aufgabe.
5. Von einer separaten Testadresse an das Ticketpostfach schreiben. Nach dem nächsten Lauf die Bestätigung öffnen, auf **Anmelden** klicken und die Ticketübersicht prüfen. Danach den Zugang für **Verwaltung** ebenfalls per E-Mail-Link testen.

Alternativ die ersten drei Schritte per SSH mit demselben PHP-CLI ausführen, sofern verfügbar. Die Administration läuft über das Webportal, der Mailabruf und Versand über die PHP-Aufgabe.

[Geplante Aufgaben bei netcup](https://www.netcup.com/de/helpcenter/dokumentation/webhosting/cron-jobs)


## 4. Anhänge und Datensicherung im Webportal

Die Verwaltung zeigt unter **Systemeinstellungen → Datensicherung** den ZIP-Download und die Wiederherstellung an. Für diese Ansichten muss `zip` in der **Web-PHP-Version der Subdomain** verfügbar sein. Prüfe dies bei Bedarf auf der im WCP verlinkten PHP-Infoseite.

Für die vollständigen Portal-Uploadlimits müssen `upload_max_filesize` mindestens 8 MiB, `max_file_uploads` mindestens 10 und `post_max_size` mehr als 16 MiB erlauben. Für die Wiederherstellung einer größeren Sicherung müssen `upload_max_filesize`, `post_max_size` und das HTTP-Anfragelimit die jeweilige ZIP-Größe einschließlich Formular-Overhead erlauben. Der private Datenordner braucht Platz für temporäre Dateien und ein Transaktionsjournal. Die Anwendung erlaubt SQLite-Sicherungen bis 512 MiB.

Bei einem Update Konfiguration und bisherigen Datenordner behalten. Die Migration auf das neue Schema erfolgt beim ersten regulären Portal- oder Worker-Aufruf. Für die bestehende geplante Aufgabe ist keine Neuinitialisierung des Postfachs nötig.


## 5. Updates über GitHub einrichten

Ab Version 1.0.2 startet **Systemeinstellungen → Updates → Auf Updates prüfen** die Prüfung und Installation direkt. Das Menü zeigt nach dem Vorgang die installierte Version oder die Fehlermeldung. Eine gegebenenfalls eingerichtete Aufgabe `private/update.php --pending` kann deaktiviert werden. Dokumentenstamm und Mail-Aufgabe bleiben auf `public/` und `private/worker.php`.

1. Beim ersten Wechsel auf Version 1.0.2 das Vollpaket bei Installationen ohne bereits per Git aktivierte Version einspielen. Bei Installationen mit aktivierter Git-Version kann der Hosting-Benutzer `php private/update.php --apply` einmal per SSH aufrufen.
2. Im Hosting-Benutzerkonto einen SSH-Schlüssel für den Git-Zugriff einrichten und den öffentlichen Schlüssel im Repository `cwaxe/ticketsystem` unter **Settings → Deploy keys** als Leseschlüssel hinterlegen. Den privaten Schlüssel im Hosting-Konto belassen und der SSH-Konfiguration für `github.com` zuordnen. Den GitHub-Hostschlüssel nach Abgleich mit den offiziellen Fingerabdrücken in `known_hosts` hinterlegen. Der Prozess benötigt Zugriff auf diese SSH-Konfiguration und fragt kein Passwort ab.
3. Der Web-PHP-Prozess benötigt `proc_open` und einen erreichbaren PHP-CLI-Interpreter mit den PHP-Modulen des Portals. Standardmäßig wird `php` aus dem Suchpfad verwendet. Bei Bedarf in `private/config.php` unter `update_php` den absoluten CLI-Pfad eintragen. Der CLI-Prozess benötigt ebenfalls `proc_open` sowie Git, SSH, `tar` und `cp`. Bei geänderten Abhängigkeiten benötigt er zusätzlich Composer. Bei Fehlern stehen technische Details im PHP-Fehlerprotokoll.

Wer bereits SSH nutzt, kann vom Installationsordner aus direkt `php private/update.php --check` ausführen. `php private/update.php --apply` installiert den zuletzt veröffentlichten Stand aus dem Branch `release`, wenn seine Version höher ist als die installierte.

[GitHub Deploy Keys](https://docs.github.com/en/authentication/connecting-to-github-with-ssh/managing-deploy-keys) · [GitHub SSH-Fingerabdrücke](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints)

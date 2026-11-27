# Ticketportal · IT-Service Waxenberger

Quellcode für eigenen PHP-Webspace. Kunden schicken ihre Anfrage an **ticket@edv-waxenberger.de**. Der regelmäßige PHP-Job liest das Postfach direkt per verschlüsseltem IMAP. Das Portal legt ein Ticket an und verschickt die Eingangsbestätigung sowie eine Adminbenachrichtigung per authentifiziertem SMTP.

Für netcup Webhosting siehe [NETCUP.md](NETCUP.md) mit den Schritten im WCP und dem PHP-Kompatibilitätstest.

## Voraussetzungen

- PHP ab 8.2, mit `pdo_sqlite` und SQLite ab 3.35. Für den Mailjob braucht PHP-CLI zusätzlich OpenSSL, `mbstring` und `curl` mit SMTP-Unterstützung. Die PHP-Abhängigkeiten werden mit Composer installiert.
- Eigene HTTPS-Adresse, zum Beispiel `tickets.edv-waxenberger.de`, mit einem einstellbaren Dokumentenstamm. Ein Unterverzeichnis ist möglich, wenn sein Inhalt auf `public/` abgebildet wird und `private/` außerhalb aller öffentlich erreichbaren Verzeichnisse liegt.
- Cronjob beziehungsweise geplante CLI-Aufgabe auf dem Webspace. PHP-Webprozess und Cron müssen auf dieselbe private SQLite-Datei und das Sitzungsverzeichnis schreiben können. Das Beispiel setzt voraus, dass beide unter dem eigenen Hosting-Benutzer laufen.
- Das Postfach muss IMAP mit implizitem TLS und SMTP mit SMTP-Authentifizierung über TLS unterstützen. Bei einem Anbieter mit reinem OAuth-Zugang muss die Mailanbindung angepasst werden.

## Installation

1. Abhängigkeiten installieren und `public/` sowie `private/` auf den Webspace übertragen. Composer kann vor dem Upload lokal ausgeführt werden.

   ```sh
   composer install --working-dir=private --no-dev --prefer-dist
   php private/worker.php --check
   ```

   Als Dokumentenstamm der Ticket-Subdomain den Ordner `public/` einstellen. `private/` bleibt ein Geschwisterordner außerhalb des Dokumentenstamms. Auch die Hauptwebsite darf ihn nicht ausliefern.
2. `private/config.example.php` als `private/config.php` kopieren. Produktions-URL, eine eigene vom Ticketpostfach getrennte Admin-Adresse, IMAP- und SMTP-Server sowie Zugangsdaten eintragen. Schlüssel beispielsweise mit `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` erzeugen. Konfiguration nur dem Hosting-Benutzer zugänglich machen, üblicherweise mit `chmod 600 private/config.php`. Zugangsdaten bleiben auf dem Webspace.
3. Datenbank einrichten und das Postfach initialisieren. `--init` merkt sich den aktuellen Stand. Erst danach eingegangene Nachrichten werden importiert. Für einen bewussten Import des gesamten bisherigen Posteingangs stattdessen `--import-existing` verwenden. Dabei werden auch für bestehende Nachrichten Bestätigungen verschickt.

   ```sh
   php private/worker.php --setup
   php private/worker.php --init
   ```
4. Im Hosting-Panel einen Cronjob im 2-Minuten-Takt einrichten. Dateipfade und PHP-Version an das Hosting anpassen. Fehler gehen zur Ausgabe des Cronjobs.

   ```cron
   */2 * * * * /usr/bin/php /ABSOLUTER/PFAD/private/worker.php
   ```
5. Nach der Initialisierung von einer separaten Testadresse eine Testmail ans Ticketpostfach schicken. Nach dem Cronlauf die Bestätigung öffnen und auf **Anmelden** klicken. In der Verwaltung einen Status ändern und mit der Testadresse prüfen, ob der neue Status sichtbar ist. Eine zweite Absenderadresse sollte nur ihre eigenen Tickets sehen.

## Bedienung

- Kunden sehen die Tickets ihrer jeweiligen Absenderadresse und deren Status. Sie können Kommentare hinzufügen und eigene Tickets auf **Erledigt** setzen. Der Anmeldelink gilt höchstens 24 Stunden und kann einmal verwendet werden. Die Anmeldung wird im Portal bestätigt, damit ein bloßer Linkabruf durch einen E-Mail-Scanner den Link nicht verbraucht. Im Kundenportal lassen sich neue Links anfordern.
- Dein Zugang liegt unter **Verwaltung** im Fußbereich. Der Verwaltungslink wird ausschließlich an die konfigurierte Admin-Adresse geschickt. Im Arbeitsbereich kannst du Tickets anlegen, suchen, nach Status filtern, bearbeiten und löschen sowie Kundenkommentare beantworten. Unter **Bearbeitungszeiten** siehst du die verstrichene Zeit vom Eingang bis zum Schließen. Interne Notizen, Prioritäten und Fälligkeiten stehen nur in der Verwaltung.
- Die E-Mails bleiben im Ticketpostfach. Der Import übernimmt den Text und kennzeichnet Anhänge, die im Originalpostfach bleiben. Jede neue, reguläre E-Mail wird als eigenes Ticket erfasst. Automatische Antworten und viele Verteiler- oder Unzustellbarkeitsnachrichten werden übersprungen.
- Wenn der SMTP-Server einen Versand ablehnt, bleibt dieser in der Warteschlange und wird erneut versucht. `php private/worker.php --send-only` verarbeitet testweise nur den Versand. Bei einer Verbindungstrennung nach Annahme durch den SMTP-Server kann eine Bestätigung erneut versandt werden.
- Nach Änderung der IMAP-UIDVALIDITY hält der Import an. Dann bewusst neu initialisieren. Eine vorhandene Message-ID je Absender dient der Deduplizierung. Für Nachrichten ohne Message-ID steht nach einem UID-Reset keine zuverlässige Zuordnung zu vorher importierten Nachrichten zur Verfügung.
- Sichere die SQLite-Datenbank konsistent, etwa mit der SQLite-Backup-Funktion, sowie die Konfiguration. Bei einer bestehenden Installation wird die SQLite-Datenbank beim nächsten Aufruf automatisch erweitert.

Logo und Gestaltung orientieren sich an der Firmenwebsite. Die Links für Impressum und Datenschutz führen zu den bestehenden Seiten. Prüfe vor Freigabe, ob die Datenschutzhinweise die Verarbeitung im neuen Ticketportal abdecken.

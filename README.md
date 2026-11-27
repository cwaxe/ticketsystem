# Ticketportal · IT-Service Waxenberger

Quellcode für das Ticketportal auf eigenem PHP-Webspace. Kunden schicken ihre Anfrage an **ticket@edv-waxenberger.de**. Der PHP-Job liest das Postfach per verschlüsseltem IMAP und legt Tickets an. Die Eingangsbestätigung und die Benachrichtigung für die Verwaltung gehen per authentifiziertem SMTP raus. Die vollständige netcup-ZIP enthält die PHP-Abhängigkeiten. Im Git-Checkout werden sie mit Composer installiert.

Für netcup Webhosting siehe [NETCUP.md](NETCUP.md). Die Entwicklung und die Änderungen pro Paket sind in [VERLAUF.md](VERLAUF.md) dokumentiert.

## Voraussetzungen

- PHP ab 8.2, mit `pdo_sqlite` und SQLite ab 3.35. PHP-CLI für den Mailjob benötigt zusätzlich OpenSSL, `mbstring` und `curl` mit SMTP/SMTPS.
- Eigene HTTPS-Adresse, zum Beispiel `ticket.edv-waxenberger.de`, mit einem einstellbaren Dokumentenstamm. Ein Unterverzeichnis ist möglich, wenn sein Inhalt auf `public/` abgebildet wird und `private/` außerhalb aller öffentlich erreichbaren Verzeichnisse liegt.
- Cronjob beziehungsweise geplante CLI-Aufgabe auf dem Webspace. PHP-Webprozess und Cron müssen auf dieselbe private SQLite-Datei und das Sitzungsverzeichnis schreiben können. Das Beispiel setzt voraus, dass beide unter dem eigenen Hosting-Benutzer laufen.
- Das Postfach muss IMAP mit implizitem TLS und SMTP mit SMTP-Authentifizierung über TLS unterstützen. Bei einem Anbieter mit reinem OAuth-Zugang muss die Mailanbindung angepasst werden.

## Installation

1. ZIP auf dem Webspace entpacken. Wenn du aus diesem Git-Repository installierst, führe vorher `composer install --working-dir=private --no-dev --prefer-dist` aus und übertrage auch `private/vendor/`. Als Dokumentenstamm der Ticket-Subdomain den Ordner `public/` einstellen. `private/` bleibt ein Geschwisterordner außerhalb des Dokumentenstamms. Auch die Hauptwebsite darf ihn nicht ausliefern.
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

## Bestehende Installation aktualisieren

Sichere die SQLite-Datenbank und die Konfiguration. Entpacke die ZIP dort, wo `public/` und `private/` bereits liegen, und ersetze die enthaltenen Dateien. `private/config.php` und `private/data/` gehören zur bestehenden Installation. Beim nächsten Aufruf wird die Datenbank automatisch erweitert. Die laufende Aufgabe `private/worker.php` verwendet weiterhin ein leeres Argumentfeld.

## Bedienung

- Kunden sehen die Tickets ihrer jeweiligen Absenderadresse und deren Status. Sie können Kommentare hinzufügen und eigene Tickets schließen. Der Anmeldelink gilt höchstens 24 Stunden und kann einmal verwendet werden. Die Anmeldung wird im Portal bestätigt, damit ein bloßer Linkabruf durch einen E-Mail-Scanner den Link nicht verbraucht. Im Kundenportal lassen sich neue Links anfordern.
- Erledigte Tickets haben einen grünen Ticketkopf mit Haken und Abzeichen. Ein gespeicherter Abschlusszeitpunkt erscheint unter dem Betreff. In der Verwaltung stehen Status, Priorität und Fälligkeit oberhalb der Konversation. Kundenzuordnung und interne Notiz lassen sich aufklappen.
- In der Konversation stehen Nachrichten und Statuswechsel in zeitlicher Reihenfolge. Neue Statuswechsel zeigen Zeitpunkt, Rolle sowie alten und neuen Status. Gespeicherte frühere Abschlüsse erscheinen mit ihrem Datum.
- Die Ticketdetailseite zeigt die erste Anfrage und alle Antworten chronologisch als Konversation. Im aufgeklappten E-Mail-Zitat stehen die Mail-Kopfzeilen zusammen und zwischen Absätzen bleibt höchstens eine Leerzeile.
- Ein Verwaltungskommentar löst eine E-Mail an die Absenderadresse des Tickets aus. Kunden können von dieser Adresse direkt auf die Benachrichtigung oder eine mit diesem Stand verschickte Eingangsbestätigung antworten. Der Mailjob erkennt die Antwort über die Nachrichtenreferenz und fügt sie als Kundenkommentar in den Ticketverlauf ein. Bei Antworten auf erledigte Tickets bleibt der Status erhalten.
- Dein Zugang liegt unter **Verwaltung** im Fußbereich. Der Verwaltungslink wird ausschließlich an die konfigurierte Admin-Adresse geschickt. Im Arbeitsbereich kannst du Tickets anlegen, suchen, nach Status filtern, bearbeiten und löschen. Interne Notizen, Prioritäten und Fälligkeiten stehen nur in der Verwaltung.
- Bei einem Statuswechsel erhält der Kunde eine E-Mail mit dem vorherigen und dem neuen Status. Das gilt auch beim Schließen durch den Kunden und beim Wiederöffnen durch die Verwaltung. Antworten auf die Statusmail werden demselben Ticket zugeordnet. In der HTML-Fassung der Statusmail ist das Firmenlogo eingebettet.
- Die Verwaltung erhält eine E-Mail bei neuen Kundenkommentaren aus dem Portal oder per E-Mail sowie beim Schließen durch den Kunden. Bei neuen Tickets enthält die Admin-Mail Absender, Betreff und übernommenen Nachrichtentext. Bei Kommentaren enthält sie den neuen Beitrag. Eigene Aktionen der Verwaltung lösen die jeweils vorgesehenen Kundenmails aus.
- Unter **E-Mail-Signatur** kann die Verwaltung den mehrzeiligen Signaturtext ändern. Automatische E-Mails enthalten eine gestaltete HTML-Fassung und eine Textfassung. Beide verwenden die gespeicherte Signatur. Bei leerem Signaturfeld entfällt der Signaturblock.
- Im Ticket kannst du unter **Kundenzuordnung** einen vorhandenen Kunden auswählen oder mit einem Namen neu anlegen. Weitere bereits zugeordnete Tickets mit derselben Absenderdomain liefern Vorschläge. **Nach Kunden gruppieren** verwendet deine gespeicherten Zuordnungen. Tickets ohne Zuordnung findest du unter **Ohne Kundenzuordnung**.
- Nach einer manuellen Kundenzuordnung ordnet das Portal weitere Tickets derselben E-Mail-Adresse automatisch zu. Existierende eindeutige Zuordnungen werden übernommen. Gleiche Domains liefern weiterhin einen Hinweis.
- Die Ticketlisten gliedern sich nach Status. Unter **Neue Tickets** sieht die Verwaltung die seit diesem Update importierten, ungelesenen Tickets. Beim Öffnen eines Tickets in der Verwaltung verschwindet dessen Neu-Markierung. Antworten auf bestehende Tickets bleiben Kommentare.
- Unter **Bearbeitungszeiten** siehst du die verstrichene Zeit vom Eingang bis zum Schließen. Mit `php private/admin-login.php --send` kannst du als Hosting-Administrator einen Admin-Einmal-Link anfordern, falls das Webformular sein Limit erreicht hat.
- Die E-Mails bleiben im Ticketpostfach. Der Import übernimmt den Text und kennzeichnet Anhänge, die im Originalpostfach bleiben. Reguläre Nachrichten ohne passende Nachrichtenreferenz und Absenderadresse werden als neue Tickets erfasst. Automatische Antworten und viele Verteiler- oder Unzustellbarkeitsnachrichten werden übersprungen.
- Wenn der SMTP-Server einen Versand ablehnt, bleibt dieser in der Warteschlange und wird erneut versucht. `php private/worker.php --send-only` verarbeitet testweise nur den Versand. Bei einer Verbindungstrennung nach Annahme durch den SMTP-Server kann eine Bestätigung erneut versandt werden.
- Nach Änderung der IMAP-UIDVALIDITY hält der Import an. Dann bewusst neu initialisieren. Eine vorhandene Message-ID je Absender dient der Deduplizierung. Für Nachrichten ohne Message-ID steht nach einem UID-Reset keine zuverlässige Zuordnung zu vorher importierten Nachrichten zur Verfügung.
- Sichere die SQLite-Datenbank regelmäßig konsistent, etwa mit der SQLite-Backup-Funktion, sowie die Konfiguration.

Logo und Favicon stammen von der Firmenwebsite. Die Links für Impressum und Datenschutz führen zu den bestehenden Seiten.

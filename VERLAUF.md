# Projektverlauf des Ticketsystems

**Stand 27. November 2026.** Dieser Verlauf dokumentiert die bereitgestellten Pakete und die Entscheidungen für das PHP-Ticketportal von IT-Service Waxenberger. Die Git-Historie enthält die erhaltenen Quellstände. Frühere Pakete und Tests sind hier zusammengefasst.

## 1. Vom ersten ServiceDesk zum eigenen PHP-Portal

Das erste ServiceDesk entstand als gehostete Anwendung mit eigener Datenhaltung. Für den Betrieb auf dem eigenen netcup-Webspace entstand anschließend ein separates PHP-Portal mit SQLite. Kunden schicken Anfragen per E-Mail an das Ticketpostfach. Der geplante PHP-Job importiert neue Nachrichten per verschlüsseltem IMAP und sendet per authentifiziertem SMTP eine Eingangsbestätigung mit Ticketnummer und persönlichem Anmeldelink. Ticketnummern haben das Format `T-0001`.

Der Webroot zeigt auf `public/`. Konfiguration, Mailjob und Datenbank liegen in `private/`. Die Kunden sehen anhand ihrer Absenderadresse nur eigene Tickets. Über **Verwaltung** fordert der Admin seinen Einmal-Link an und kann Tickets anlegen, suchen, filtern, bearbeiten oder löschen. Priorität, Fälligkeit und interne Notizen sind der Verwaltung vorbehalten.

Erste Pakete waren `Waxenberger-Ticketportal.zip` und `Waxenberger-Ticketportal-Netcup.zip`.

## 2. Anpassungen für netcup

Die geplante Aufgabe wurde zunächst mit `--check` geprüft. Damit ließen sich die PHP-Version der Aufgabe und fehlende Module feststellen. Die PHP-IMAP-Erweiterung stand bei der gewählten Hosting-Konfiguration nicht zur Verfügung. Das IMAP-Update ersetzte sie durch einen direkten IMAP/TLS-Client. Der mitgelieferte MIME-Parser übernimmt Nachrichtentext und kennzeichnet Anhänge, die im Mailpostfach bleiben.

Die überarbeitete Startseite führt getrennt zu neuen Anfragen und bestehenden Tickets. `--setup` richtet die Datenbank ein. `--init` merkt sich bei einer Neuinstallation den aktuellen Stand des Postfachs. Die laufende Aufgabe `private/worker.php` verwendet ein leeres Argumentfeld.

Pakete dieser Phase waren `Waxenberger-Netcup-IMAP-Update.zip` und `Waxenberger-Ticketportal-Update.zip`.

## 3. Adminbenachrichtigungen, Ticketverlauf und Auswertung

Das Portal verschickte zuerst nur die Eingangsbestätigung. Das nächste Update ergänzte beim Import einer neuen Anfrage eine separate Benachrichtigung an die konfigurierte Admin-Adresse.

Kunden und Verwaltung bekamen einen gemeinsamen Kommentarverlauf. Kunden können eigene Tickets auf **Erledigt** setzen. Die Verwaltung kann sie wieder öffnen. Die Adminansicht **Bearbeitungszeiten** misst die verstrichene Zeit vom Eingang bis zum Schließen und zeigt den Durchschnitt sowie die letzten 25 Messungen. Bei Wiedereröffnung zählt das erneute Schließen. Bereits erledigte Tickets ohne gespeicherten Schließzeitpunkt werden separat gezählt.

Das Paket `Waxenberger-Ticketportal-Ticketverlauf-und-Auswertung.zip` erweiterte die bestehende Datenbank beim nächsten Aufruf automatisch.

## 4. Fehlersuche beim Adminzugang

Bei der SMTP-Diagnose wurden zwei Abschnitte getrennt geprüft. Die lokale Kopie im netcup-Postfach bestätigte die Zustellung bis zur Admin-Adresse. Für die Weiterleitung an das externe Postfach lieferte `--test-mail` die Versandzeit, Absender, Empfänger und Message-ID. Die SMTP-Annahme zeigte, dass der Server die Nachricht zur Zustellung übernommen hatte.

Der Test mit einer angehaltenen Versandwarteschlange zeigte, dass das Formular keinen weiteren Admin-Link angelegt hatte. Die Diagnose bestätigte sieben Anfragen im aktiven Stundenfenster bei einem Limit von fünf pro Adresse. Das Limit schützt das öffentliche Formular vor Serienanfragen. Das Portal zeigt aus Datenschutzgründen bei gültigen und abgelehnten Anfragen dieselbe Meldung.

Für den Hosting-Administrator kam `private/admin-login.php` hinzu:

- `--status` zeigt die konfigurierte Portal- und Admin-Adresse sowie das aktuelle Anfragelimit.
- `--send` sendet über die geplante PHP-Aufgabe einen persönlichen Admin-Einmal-Link an die konfigurierte Adresse und gibt die Message-ID aus. Dieser Wiederherstellungsweg wurde auf dem Webspace erfolgreich verwendet.

Das Hilfsskript wurde als `Waxenberger-Admin-Anmeldelink.zip` bereitgestellt und später in das Vollpaket übernommen.

## 5. Von der Domainansicht zur manuellen Kundenzuordnung

Das erste Admin-Update zur Gruppierung fasste Tickets nach exakter Absenderdomain zusammen. Im Paket `Waxenberger-Ticketportal-Kundendomains.zip` führte die Liste über die Domain zu den passenden Tickets.

Die Anforderung wurde anschließend konkretisiert. Die Domain soll eine mögliche Zuordnung vorschlagen, während die Verwaltung den tatsächlichen Kunden auswählt. Im Ticket kann der Admin unter **Kundenzuordnung** einen vorhandenen Kunden auswählen, einen neuen anlegen oder die Zuordnung entfernen. Die Domain liefert Vorschläge aus früheren Zuordnungen. **Nach Kunden gruppieren** verwendet die gespeicherte Auswahl. Bestehende Tickets erscheinen zunächst unter **Ohne Kundenzuordnung**.

Ab diesem Stand werden vollständige netcup-ZIPs für Neuinstallation und Update mit PHP-Abhängigkeiten erstellt. Das Vollpaket enthält auch das Original-Favicon der Firmenwebsite. Die bestehende Konfiguration und Ticketdaten bleiben beim Update auf dem Webspace.

Paket: `Waxenberger-Ticketportal-Vollpaket.zip`.

## 6. Kommentarbenachrichtigungen und direkte E-Mail-Antworten

Ein neuer Verwaltungskommentar löst eine E-Mail an die ursprüngliche Absenderadresse des Tickets aus. Sie enthält die Ticketnummer, den Kommentar und den Link zum Portal. Antwortet der Kunde von derselben Adresse mit **Antworten** im Mailprogramm, ordnet der Mailjob die E-Mail anhand der Nachrichtenreferenz dem Ticket zu. Die Antwort erscheint genau einmal im Kommentarverlauf. Bei erledigten Tickets bleibt der Status erhalten.

Die Zuordnung gilt auch für Eingangsbestätigungen, die ab diesem Update verschickt werden. Ein neuer Verwaltungskommentar ermöglicht bei älteren Tickets eine Antwort im bestehenden Verlauf. Reguläre Nachrichten ohne passende Referenz und Absenderadresse werden als neue Tickets angelegt.

Lokal geprüft wurden Datenbankmigration, Versand über SMTP, Import über IMAP, Zuordnung und Deduplizierung, automatische Antworten, Absendertrennung sowie Antworten auf erledigte Tickets.

Paket: `Waxenberger-Ticketportal-Vollpaket-Mailantworten.zip`.

## 7. E-Mail bei Statusänderungen

Bei einem tatsächlichen Wechsel erhält der Kunde eine E-Mail mit Ticketnummer, bisherigem und neuem Status sowie Portal-Link. Ein reines Speichern ohne Statuswechsel und die erste Statusvergabe beim Erstellen eines Tickets lösen keine Statusmail aus. Der Versand erfasst auch das Schließen durch den Kunden und das Wiederöffnen durch die Verwaltung.

Die Statusmail verwendet dieselbe Warteschlange und Nachrichtenreferenz wie Kommentarbenachrichtigungen. Antworten von der ursprünglichen Absenderadresse landen als Kommentar im selben Ticket. Der lokale Test deckte die Migration, die Statuswechsel in beiden Portalrollen, den SMTP-Versand und den Rückimport über IMAP ab.

Paket: `Waxenberger-Ticketportal-Vollpaket-Statusmeldungen.zip`.

## 8. HTML-Mails, anpassbare Signatur und Adminbenachrichtigungen

Automatische E-Mails werden mit einer gestalteten HTML-Fassung und einer Textfassung verschickt. Links zum Portal und persönliche Anmeldelinks erscheinen im HTML als Schaltflächen. Im Adminbereich **E-Mail-Signatur** lässt sich der mehrzeilige Signaturtext für beide Fassungen pflegen. Die Signatur wird in der vorhandenen SQLite-Datenbank gespeichert.

Der Admin erhält zusätzlich Nachrichten bei Kundenkommentaren aus dem Portal oder per E-Mail und beim Schließen eines Tickets durch den Kunden. Der Versand läuft weiter über die gemeinsame Warteschlange.

Lokal geprüft wurden Datenbankmigration, geschütztes Speichern der Signatur, beide MIME-Fassungen, der klickbare Anmeldelink und die unterschiedlichen Empfänger bei Kundenaktionen.

Paket: `Waxenberger-Ticketportal-Vollpaket-HTML-Mails.zip`.

## 9. Ticketverlauf als Konversation

Die Ticketdetailseite zeigt die erste Anfrage und die Antworten chronologisch als Konversation. Beiträge von Kunde und IT-Service lassen sich an der Position und Kennzeichnung erkennen. Das Antwortfeld schließt sich an die Nachrichten an. Die Verwaltung öffnet die Ticketfelder über **Ticket bearbeiten**.

Typische Zitatblöcke aus E-Mail-Antworten erscheinen eingeklappt, auch bei bereits gespeicherten Beiträgen. Dazu gehören Blöcke mit Absender, Versanddatum, Empfänger und Betreff. Adminbenachrichtigungen zu Kundenantworten enthalten den Antworttext.

Das Folgeupdate reduziert Leerzeilen im aufgeklappten E-Mail-Zitat. Zusammengehörige Mail-Kopfzeilen stehen direkt untereinander. Absätze bleiben erhalten. Die Formatierung wird für die Anzeige berechnet und die gespeicherten Kommentare bleiben erhalten.

Paket: `Waxenberger-Ticketportal-Vollpaket-Kompakter-Mailverlauf.zip`.

## 10. Bekannte Absender, Statusgruppen und neue Tickets

Eine manuelle Kundenzuordnung merkt sich die genaue Absenderadresse für künftige Tickets. Die Datenbank übernimmt eindeutige frühere Zuordnungen. Bei widersprüchlichen Zuordnungen gilt die nächste manuelle Entscheidung. Ein neuer Kontakt derselben Domain erhält weiterhin nur einen Domain-Hinweis.

Die Ticketlisten sortieren nach Status. Die Verwaltung kann zwischen allen Tickets, der Kundengruppierung und der Ansicht **Neue Tickets** wechseln. Seit dem Update per E-Mail importierte Tickets zählen als neu, bis sie in der Verwaltung geöffnet werden. Antworten in bestehenden Tickets ergänzen die Konversation.

Der lokale Test deckte die Migration, uneindeutige Altzuordnungen, importierte und manuell angelegte Tickets sowie Statusgruppen, Filter und Neu-Markierungen ab.

Die HTML-Fassung von Statusmails enthält das eingebettete Firmenlogo. Die Textfassung bleibt verfügbar.

Aktuelles Vollpaket: `Waxenberger-Ticketportal-Vollpaket-Kunden-Status-Logo.zip`.

## Installation und Betrieb

- [README.md](README.md) beschreibt Voraussetzungen, Neuinstallation, Update und Bedienung.
- [NETCUP.md](NETCUP.md) enthält die Einrichtung im Webhosting Control Panel.
- Die PHP-Abhängigkeiten sind im netcup-Vollpaket enthalten. Für einen Git-Checkout liefert `private/composer.lock` die Versionen.
- Die Dateien `private/config.php` und `private/data/` gehören zur laufenden Installation. Die Git-Fassung enthält eine Konfigurationsvorlage und das Datenbankschema.

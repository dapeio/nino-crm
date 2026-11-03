# Crm - das Projektmodul für Kunden und Kundenportal

`\Crm\Crm` ist das Modul dieses Projekts (`dapeio/nino-crm`). Es liegt in `app/Crm/` und gehört zum Projekt, nicht zu Nino und nicht zum Feature-Katalog. Es bringt:

- eine SQLite-Datenbank `data/crm.sqlite` mit Migrationen (`PRAGMA user_version`),
- die Kunden (Nummer `K-0001`, Firma, Name, Anschrift, E-Mail, Telefon, USt-IdNr., Notizen, aktiv) mit ihren Konten des Features Mitglieder,
- das Panel **Kunden** (Liste mit Suche, Karte, anlegen, ändern, deaktivieren, Konto einladen),
- die Portalseiten hinter `/portal` und die Anmeldeseite `/login` als Installationseinheit, die das Panel auf einen Knopf einspielt,
- den Shortcode `[crm-client]`, der im Portal die Firma des Kunden des angemeldeten Mitglieds schreibt,
- die **Positionen** (der Katalog dessen, was Du verkaufst) und die **Belege**: Angebote, Rechnungen und Stornorechnungen mit Nummernkreisen, Festschreiben und einer PDF-Datei aus einer Vorlage des Features PDF - die Panels **Belege** und **Positionen** und die Route `/.crm/pdf/<id>`.

## Einschalten

1. Die Features **Mitglieder**, **Dateien**, **Review**, **Sign** und **Pdf** liegen in `features/` und sind im Panel Features eingeschaltet (Mitglieder ist die Voraussetzung, die anderen füllen die Portalseiten).
2. Das Modul steht in `private/config.php` unter `/nino/modules` - eine Zeile, von Hand:

   ```php
   '/nino/modules' => [
       // ...die vorhandenen Module...
       '\\Crm\\Crm',
   ],
   ```

   Das Modul schreibt beim Start nichts. Erst wenn es dort steht, erscheint das Panel **Kunden**.
3. Im Panel Kunden zeigt die Statuszeile, was noch fehlt (ein Feature, das nicht läuft, das Modul, das Verzeichnis `data/`). Der Knopf **Portal einrichten** spielt die Installationseinheit ein.

## Was „Portal einrichten“ tut

Es ergänzt nur, was das Projekt nicht hat (add-only); was Du geändert hast, bleibt, und ein zweiter Druck auf den Knopf ändert nichts:

- die Routen `/portal`, `/portal/dateien`, `/portal/review`, `/portal/unterschriften`, `/portal/konto` und `/login`,
- die Vorlagen `page-portal*.tpl` und `page-login.tpl` in `private/templates/` und ihre Worte in `private/text/` (im Panel Texte änderbar; der Builder liest die Seiten),
- den Pfad `/portal` in der Einstellung *Geschützte Pfade* des Features Mitglieder - **nur wenn dort noch kein Pfad steht**. Die Einheit selbst kann die Einstellung eines Features nicht schreiben; das Panel setzt sie mit `Features::saveSettings()`. Schützt das Projekt schon andere Pfade, bleiben sie, und `/portal` trägst Du im Panel Features unter Mitglieder selbst ein.
- `/portal` als *Startseite* des Features Mitglieder (wohin ein Mitglied nach der Anmeldung kommt, wenn keine Seite die Anmeldung verlangt hat - etwa von `/login` aus) - **nur wenn dort noch die Startseite der Website (`/`) steht**; eine Startseite, die Du gesetzt hast, bleibt.

Die Einheit liegt in `app/Crm/unit/` und ist keine Einheit des Einrichtungsassistenten (der Assistent bietet jede `install/manifest.php` unter `app/` an und verlangt dazu ein Modul des Kernels). Nur der Knopf spielt sie ein.

## Die Konten der Kunden

Jeder Kunde hat eine Gruppe in Mitglieder: seine Nummer als Slug (`k-0001`). **Konto einladen** legt ein Konto in dieser Gruppe an, verknüpft es mit dem Kunden und schickt die Einladung (der Link führt auf `/portal`). Ein Kunde mit Konten wird nie gelöscht, nur deaktiviert; ein deaktivierter Kunde bekommt keine neuen Konten. Das Konto selbst (sperren, löschen, neuen Link senden) verwaltest Du im Panel Mitglieder.

## Daten und Sicherung

`data/crm.sqlite` (mit den Dateien `-wal` und `-shm` daneben, solange sie offen ist) gehört zum Projekt. **Die tägliche Sicherung von Nino enthält sie nicht**: keine Manifestdatei nennt sie. Sichere die Datei zusammen mit dem Server; ein späterer Patch kann sie in die Sicherung aufnehmen. Die Migrationen laufen beim ersten Zugriff eines Aufrufs; `PRAGMA user_version` sagt, wie weit die Datei ist (2 seit den Belegen).

**Die PDF-Dateien der Belege liegen in `data/crm/pdf/<Jahr>/<Nummer>.pdf` und sind ebenfalls nicht in der Sicherung von Nino.** Sie gehören zu den Belegen: Die Datenbank kennt von jeder Datei den Pfad und den SHA-256. Sichere das Verzeichnis `data/crm/` zusammen mit `crm.sqlite`; eine Datei ohne ihre Zeile und eine Zeile ohne ihre Datei sind beide ein Fehler, den Du nicht mehr aus der Datenbank allein beheben kannst. Eine PDF-Datei wird einmal geschrieben und nie überschrieben.

**Stelle `crm.sqlite` und `data/crm/pdf/` immer zusammen wieder her.** Spielst Du nur die Datenbank zurück, liegen Dateien für Nummern im Verzeichnis, die die Datenbank noch nicht vergeben hat: Jedes Festschreiben antwortet dann *Die PDF-Datei der Nummer ... liegt schon unter ...*, weil die nächste Nummer nicht überschreiben darf, was dort liegt. Die Meldung nennt die Nummer und den Pfad der Datei. Gehört sie zu keinem Beleg Deiner Datenbank, verschiebe sie an einen anderen Ort; **lösche nie die Datei eines festgeschriebenen Belegs** - ohne sie kann der Beleg nicht mehr nachgewiesen werden. Danach kannst Du festschreiben.

## Positionen

Das Panel **Positionen** ist der Katalog: Bezeichnung (bis 200 Zeichen), Beschreibung (bis 2000), Einheit (bis 20), Nettopreis, Steuersatz (nur bei Regelbesteuerung), aktiv und die Reihenfolge (die Pfeile in der Liste). Eine Position ist eine Vorlage für eine Zeile: Eine Zeile übernimmt Bezeichnung, Beschreibung, Einheit, Preis und Satz, wenn Du sie hinzufügst, und folgt der Position danach nie. Eine Position wird nicht gelöscht, nur deaktiviert; sie erscheint dann nicht mehr in der Auswahl der Belege.

Preise schreibst Du als `1.234,56`, `1234,56` oder `1234.56`; ein Punkt ohne Komma dahinter ist ein Dezimalpunkt (`1.234` ist darum **kein** Preis, sondern wird abgelehnt). Mengen haben bis zu drei Nachkommastellen (`1,5`, `0,25`, `1.25`); `1.000` und `1.500` werden abgelehnt, weil sie auf Deutsch tausend und auf Englisch eins bedeuten - schreibe `1000` oder `1,5`. Intern sind Preise Cent, Mengen Tausendstel und Sätze Hundertstel Prozent.

## Belege

Das Panel **Belege** hat drei Arten: Angebot, Rechnung und Stornorechnung (Gutschrift). Jeder Beleg gehört zu einem aktiven Kunden und hat ein Datum, Zeilen und Summen.

**Ein Entwurf** hat keine Nummer, lässt sich ändern und löschen. Du wählst Kunde, Datum, Zahlungsziel (nur Rechnung; eine Stornorechnung hat keines) oder Gültigkeit (Angebot), eine Leistungszeit (beide Tage oder keiner), eine Vorlage aus dem Feature PDF und fügst Zeilen hinzu (bis 200). In der Bezeichnung einer Zeile bietet der Browser die aktiven Positionen an; wählst Du eine, füllt sie die Zeile. Die Summen unter den Zeilen rechnet das Panel beim Tippen mit denselben Regeln wie der Server.

**Festschreiben** ist ein Schritt in einer Transaktion: Die Nummer der Folge wird vergeben, die Anschrift des Kunden in den Beleg eingefroren, die Summen werden noch einmal gerechnet, und die PDF-Datei wird aus der gewählten Vorlage erzeugt und mit ihrem SHA-256 abgelegt. Scheitert etwas davon, wird nichts davon behalten, auch die Nummer nicht: Die Nummern einer Art und eines Jahres haben keine Lücke. Danach ändert sich am Beleg nichts mehr außer seinem Zustand und den internen Notizen. Ein festgeschriebener Beleg wird nie gelöscht.

**Zustände** gehen nur vorwärts: Eine Rechnung geht von offen über versendet zu bezahlt - oder wird storniert, wenn eine festgeschriebene Stornorechnung über den ganzen Betrag auf sie verweist. Ein Angebot geht von offen über versendet zu angenommen oder abgelehnt; ein offenes oder versendetes Angebot, dessen Gültigkeit abgelaufen ist, wird als *abgelaufen* angezeigt (eine Ansicht, kein Zustand). Eine Stornorechnung geht von offen zu versendet. Aus einem Angebot (offen, versendet, angenommen) machst Du mit einem Knopf einen Rechnungsentwurf mit seinen Zeilen und seiner Leistungszeit - der Entwurf nimmt die Umsatzsteuer-Art der Einstellung von jetzt, jeder Satz wird daran gemessen (bei Kleinunternehmern 0, bei Regelbesteuerung ein Satz der Liste; sonst lehnt die App ab und macht nichts - nimm den Satz dann in den Einstellungen in die Liste auf, denn ein festgeschriebenes Angebot lässt sich nicht ändern), und ein offenes oder versendetes Angebot gilt damit als angenommen; aus einer Rechnung (offen, versendet, bezahlt) einen Stornorechnungsentwurf mit den negierten Zeilen und der Leistungszeit der Rechnung; er behält Art und Sätze der Rechnung (er gibt zurück, was berechnet wurde). Eine Teil-Stornorechnung lässt die Rechnung, wie sie ist, und steht unter ihr.

**Nummern** sind Einstellungen: `A-{YYYY}-{NNNN}` für Angebote, `R-{YYYY}-{NNNN}` für Rechnungen, `G-{YYYY}-{NNNN}` für Stornorechnungen. Ein Format hat `{YYYY}` oder `{YY}` höchstens einmal, `{N...}` genau einmal (die Zahl der N ist die Breite, mit Nullen aufgefüllt, nie abgeschnitten) und sonst nur Buchstaben, Ziffern, `-`, `_` und `.` - ein Schrägstrich wäre ein Pfad. Gezählt wird je Art und Jahr des Belegdatums. **Stelle das Format vor dem ersten Beleg eines Jahres ein.** Änderst Du es später, gilt es für die nächste Nummer, die Zählung läuft weiter, und die Nummern einer Folge sehen unterschiedlich aus - das verantwortest Du.

**Umsatzsteuer:** Die Einstellung `vat_mode` ist `small_business` (Kleinunternehmer nach § 19 UStG, keine Umsatzsteuer, der Hinweis steht auf dem Beleg) oder `regular` (Umsatzsteuer je Zeile mit einem Satz aus der Liste `vat_rates`, gespeichert in Hundertstel Prozent, standardmäßig `1900,700,0`; im Panel schreibst Du sie als Prozent, getrennt durch Semikolon oder Leerzeichen: `19; 7; 0` oder `19; 7; 5,5; 0` - das Komma ist das Dezimalkomma, `19,7,0` wird abgelehnt). Die Art gilt für **neue Entwürfe**; ein Entwurf und ein festgeschriebener Beleg behalten die ihre. Bei Regelbesteuerung gibt es die Option *Reverse-Charge*: Sie setzt jeden Satz auf 0 und schreibt den Hinweis auf den Beleg. Die Umsatzsteuer wird je Satz aus der Summe seiner Zeilen gerechnet und kaufmännisch gerundet (ab 0,5 weg von null), nicht je Zeile.

**Die PDF-Datei** öffnet und lädt Dir der Knopf auf der Seite des Belegs über `/.crm/pdf/<id>` (nur für ein Konto der Workbench mit der Berechtigung `/_admin/crm-documents/manage`; ein Besucher, ein Entwurf und eine unbekannte Nummer bekommen dieselbe 404). Die Route prüft vor jeder Antwort den SHA-256 der Datei: Stimmt er nicht mit dem gespeicherten, ist die Antwort 409 und das Protokoll nennt die Nummer. Mit `?download=1` wird die Datei als Anhang unter `<Nummer>.pdf` gespeichert.

## Vorlagen im Feature PDF

Die Daten des Inhabers (Name, Anschrift, Steuernummer, Bankverbindung, Logo, Unterschrift) stehen in seinen Vorlagen, in Kopf und Fuß des Features PDF - die App gibt der Vorlage, was nur sie weiß: den Kunden, den Beleg, die Zeilen, die Summen und den Steuerhinweis. Eine Vorlage mit dem Tag `offer` gehört zu Angeboten, eine mit dem Tag `invoice` zu Rechnungen und Stornorechnungen. Die Eingabefelder (Blöcke vom Typ *Eingabe*) der gewählten Vorlage fragt das Panel im Editor ab und setzt sie dort ein, wo die Vorlage sie hat; ein Pflichtfeld muss beim Festschreiben gefüllt sein.

**Platzhalter (Tokens)** in den Textblöcken einer Vorlage:

| Token | Inhalt |
| --- | --- |
| `[[document.title]]` | Angebot, Rechnung oder Stornorechnung |
| `[[document.number]]` | die Nummer |
| `[[document.date]]` | das Datum, `TT.MM.JJJJ` |
| `[[document.due]]` | Zahlungsziel (nur Rechnung), sonst leer |
| `[[document.valid]]` | Gültig bis (Angebot), sonst leer |
| `[[document.period]]` | Leistungszeit `TT.MM.JJJJ - TT.MM.JJJJ`; ohne Angabe bei Rechnung und Stornorechnung der Satz *Leistungsdatum entspricht dem Rechnungsdatum*, beim Angebot leer |
| `[[document.parent]]` | die Nummer des Belegs, auf den dieser sich bezieht (das Angebot einer Rechnung, die Rechnung einer Stornorechnung), sonst leer |
| `[[document.vatnote]]` | der Satz zu § 19 UStG bei Kleinunternehmern, der Satz zur Steuerschuldnerschaft des Leistungsempfängers bei Reverse-Charge, sonst leer |
| `[[document.net]]`, `[[document.vat]]`, `[[document.gross]]` | Netto, Umsatzsteuer und Brutto mit der Währung |
| `[[client.number]]`, `[[client.company]]`, `[[client.name]]`, `[[client.street]]`, `[[client.zip]]`, `[[client.city]]`, `[[client.country]]`, `[[client.vat_id]]`, `[[client.email]]` | der Kunde, wie er beim Festschreiben war |

**Platzhalter-Blöcke (Slots)** der Vorlage: `address` (der Block des Kunden: Firma, Name, Straße, PLZ und Ort, das Land mit seinem deutschen Namen, wenn es nicht DE ist: ist die PHP-Erweiterung intl geladen, steht dort *Österreich*, sonst das Kürzel `AT`; für Deutschland steht nie eine Landzeile), `lines` (die Tabelle der Zeilen: Position, Bezeichnung mit Beschreibung darunter, Menge mit Einheit, Einzelpreis, bei Regelbesteuerung der Satz, Betrag) und `totals` (bei Regelbesteuerung Netto, die Umsatzsteuer je Satz auf ihrer Basis und Brutto; bei Kleinunternehmern nur der Gesamtbetrag, der zugleich das Netto ist). Der Aufbau der Slots steht in `Documents/templates/*.tpl`.

**Eine Vorlage, die eine vollständige Rechnung ergibt,** braucht die Slots `address`, `lines` und `totals` und den Token `document.vatnote`. Die Angaben nach § 14 UStG (vollständiger Name und Anschrift beider Seiten, Steuernummer oder USt-IdNr. des Inhabers, Ausstellungsdatum, fortlaufende Rechnungsnummer, Leistungszeit, Entgelt, Steuersatz und Steuerbetrag oder der Hinweis auf die Befreiung) sind Deine Verantwortung; die App gibt Dir jede Angabe, die nur sie kennt.

Die Worte in der PDF-Datei (Angebot, Rechnung, Spaltenköpfe, die Hinweise) sind deutsch und stehen in `Documents/Render/text/de_DE.php`.

## Test

```bash
php app/Crm/tests/crm-smoke.php
php app/Crm/tests/documents-smoke.php
node app/Crm/tests/documents-js-smoke.js
node app/Crm/tests/items-js-smoke.js
```

Die PHP-Tests laufen über `tests/harness.php` des Checkouts, legen ihr eigenes Projektverzeichnis an und räumen es auf. `documents-smoke.php` braucht das Feature PDF in `features/` (es legt Vorlagen an und lässt acht Prozesse zugleich festschreiben) und ruft die beiden JavaScript-Tests auf, wo `node` im Pfad liegt; liegt `pdftotext` im Pfad, prüft es auch die Worte in den PDF-Dateien. Die Fälle der Geldarithmetik stehen einmal in `tests/money-cases.json`: der PHP-Test prüft `Money.php` damit, der JavaScript-Test `money.js`.

## Patches einspielen

Die Patches kommen als eine Datei je Änderung und enthalten nur `app/Crm/**`. In einer Kopie Deines Repositories:

```bash
git apply --check C01.patch   # prüft, ohne etwas zu ändern
git apply --index C01.patch   # spielt sie ein, für den Commit vorgemerkt
git commit
```

Die erste Zeile der Datei (`# ...`) ist der Titel des Commits. Die Reihenfolge der Patches ist ihre Nummer (C01, C02, ...); spätere bauen auf früheren auf: C02 Positionen, Angebote, Rechnungen und Stornorechnungen, C03 Dokumente, Kategorien und Freigabe samt Seite Dokumente im Portal, C04 Importe.

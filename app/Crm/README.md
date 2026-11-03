# Crm - das Projektmodul für Kunden und Kundenportal

`\Crm\Crm` ist das Modul dieses Projekts (`dapeio/nino-crm`). Es liegt in `app/Crm/` und gehört zum Projekt, nicht zu Nino und nicht zum Feature-Katalog. Es bringt:

- eine SQLite-Datenbank `data/crm.sqlite` mit Migrationen (`PRAGMA user_version`),
- die Kunden (Nummer `K-0001`, Firma, Name, Anschrift, E-Mail, Telefon, USt-IdNr., Notizen, aktiv) mit ihren Konten des Features Mitglieder,
- das Panel **Kunden** (die Liste in der Tabelle der Workbench, Karte, anlegen, ändern, deaktivieren) - und in ihm die Logins der Kunden: Konten anlegen, einladen, verknüpfen, Link neu senden, ausschalten, umbenennen, löschen,
- die Portalseiten hinter `/portal` und die Anmeldeseite `/login` als Installationseinheit, die das Panel auf einen Knopf einspielt,
- den Shortcode `[crm-client]`, der im Portal die Firma des Kunden des angemeldeten Mitglieds schreibt,
- die **Positionen** (der Katalog dessen, was Du verkaufst) und die **Belege**: Angebote, Rechnungen und Stornorechnungen mit Nummernkreisen, Festschreiben und einer PDF-Datei aus einer Vorlage des Features PDF - die Panels **Belege** und **Positionen** und die Route `/.crm/pdf/<id>`,
- **Verträge, Datenschutz- und Informationsdokumente** ohne Positionen und ohne Nummer, die **Freigabe** jedes Belegs ins Portal (mit einer Mail an die Konten des Kunden), die **Unterschrift** eines Vertrags oder Datenschutzdokuments über das Feature Sign und die Portalseite **Dokumente** mit dem Shortcode `[crm-documents]`.

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

- die Routen `/portal`, `/portal/dateien`, `/portal/review`, `/portal/unterschriften`, `/portal/dokumente`, `/portal/konto` und `/login`,
- die Vorlagen `page-portal*.tpl` und `page-login.tpl` in `private/templates/` und ihre Worte in `private/text/` (im Panel Texte änderbar; der Builder liest die Seiten),
- den Pfad `/portal` in der Einstellung *Geschützte Pfade* des Features Mitglieder - **nur wenn dort noch kein Pfad steht**. Die Einheit selbst kann die Einstellung eines Features nicht schreiben; das Panel setzt sie mit `Features::saveSettings()`. Schützt das Projekt schon andere Pfade, bleiben sie, und `/portal` trägst Du im Panel Features unter Mitglieder selbst ein.
- `/portal` als *Startseite* des Features Mitglieder (wohin ein Mitglied nach der Anmeldung kommt, wenn keine Seite die Anmeldung verlangt hat - etwa von `/login` aus) - **nur wenn dort noch die Startseite der Website (`/`) steht**; eine Startseite, die Du gesetzt hast, bleibt.

Die Einheit liegt in `app/Crm/unit/` und ist keine Einheit des Einrichtungsassistenten (der Assistent bietet jede `install/manifest.php` unter `app/` an und verlangt dazu ein Modul des Kernels). Nur der Knopf spielt sie ein.

## Die Konten der Kunden

Die Logins Deiner Kunden legst und verwaltest Du an **einer** Stelle: auf der Karte des Kunden im Panel Kunden. Das Panel Mitglieder ist das des Features und bleibt, wie es ist; Du musst es dafür nicht öffnen. Das Panel Kunden ruft nur die PHP-Schnittstelle von Mitglieder auf (`add`, `change`, `remove`, `link`, `account`, `accounts`) und schreibt nie in dessen Dateien; die Berechtigung `/_admin/crm-clients/manage` genügt, eine von Mitglieder braucht es nicht.

Jeder Kunde hat eine Gruppe in Mitglieder: seine Nummer als Slug (`k-0001`). Ein Konto gehört zu **höchstens einem** Kunden (ein eindeutiger Index auf der Kennung des Kontos) und liegt in dessen Gruppe.

- **Neuer Kunde mit erstem Konto:** Auf der Karte eines neuen Kunden stehen die Felder *E-Mail-Adresse des ersten Kontos* und *Name des ersten Kontos*. Mit Adresse legt *Speichern* den Kunden an, das Konto in seiner Gruppe, verknüpft es und schickt die Einladung (der Link führt auf `/portal`). Ohne Adresse entsteht der Kunde allein, wie bisher. Ist die Adresse ungültig oder schon ein Konto, oder der Name zu lang, ist der **Kunde trotzdem angelegt** und nur das Konto abgelehnt: Die Antwort trägt den Code der Ablehnung, die Karte sagt es in einem Satz, und das Konto lädst Du danach in der Karte ein. Ist das Feature Mitglieder nicht aktiv, entsteht mit einer Adresse nichts (409).
- **Konto einladen** legt auf der Karte eines bestehenden Kunden ein weiteres Konto an (eine Firma, mehrere Personen). Ein deaktivierter Kunde bekommt kein Konto.
- **Vorhandenes Konto verknüpfen:** Der Dialog bietet die Konten von Mitglieder an, die **kein Kunde** hat, nach Name und Adresse. Das Verknüpfen legt die Zeile an und setzt die Gruppe des Kontos auf die des Kunden (`Members::change`). Ein Konto, das schon ein Kunde hat, ist 409 (`crm_clients_held`).
- **Jede Zeile** zeigt den Zustand des Kontos - nie angemeldet, zuletzt angemeldet am, ausgeschaltet, gelöscht - und die Knöpfe: **Link senden** (ein Konto, das sich noch nie angemeldet hat, bekommt die Einladung noch einmal, eines, das sich schon angemeldet hat, einen Link zum Anmelden; beide führen auf `/portal`; ein ausgeschaltetes Konto bekommt keinen, 409), **Ausschalten/Einschalten**, **Name ändern** und **Konto löschen** nach einer Rückfrage.
- **Löschen** löscht das Konto in Mitglieder und seine Zeile. Die **Dokumente des Kunden und die Anfragen von Sign, die das Konto nennen, bleiben, wie sie sind** - sie gehören den Büchern, nicht dem Login. Ist das Feature Mitglieder nicht aktiv, sagt die Karte das in einem Satz statt Zeilen. Ein Konto vom Kunden zu lösen, ohne es zu löschen, gibt es nicht: ein Konto wird gelöscht oder ausgeschaltet, nie verwaist.
- **Deaktivieren** eines Kunden schaltet auch **seine Konten aus**, damit er das Portal nicht mehr erreicht; die Antwort und die Karte sagen, wie viele. Aktivierst Du den Kunden wieder, bleiben die Konten aus - Du schaltest sie einzeln ein. Solange der Kunde aus ist, lassen sich seine Konten nicht einschalten und bekommen keinen Link (409 `crm_clients_inactive`); die Karte bietet es dann nicht an und sagt es in einem Satz. **Schaltest Du im Panel Mitglieder das Konto eines deaktivierten Kunden wieder ein, kommt es ins Portal:** Das Portal prüft das Aktiv-Kennzeichen des Kunden nicht (die Regel von C01).
- **Die Liste** zeigt je Kunde, wie viele Konten er hat und wie viele davon eingeschaltet sind.

Die Antworten sind Codes mit Sätzen: `crm_clients_email`, `crm_clients_long`, `crm_clients_taken` (die Antworten von Mitglieder für ungültige Adresse, zu langen Namen und vergebene Adresse), `crm_clients_write` (die Datei von Mitglieder war nicht zu schreiben), `crm_clients_member` (das Konto gehört nicht zu diesem Kunden), `crm_clients_off`, `crm_clients_mail` (die Mail wurde nicht angenommen). Die Obergrenze des Kernels für Mails je Stunde und Adresse ist ein 429 `crm_clients_ratelimit`. Ins Protokoll kommen die Nummer des Kunden und die Kennung des Kontos, nie eine Adresse.

**Was im Panel Mitglieder geschieht.** Du kannst dort weiter alles tun; das Panel Kunden liest ein Konto, wie es ist. Die **Gruppe** sagt, zu welchem Kunden es gehört: Ein Konto, das Du dort in eine andere Gruppe verschiebst, ist **nicht mehr das des Kunden** - es steht nicht mehr auf dessen Karte, zählt nicht mehr mit, das Portal kennt es für keinen Kunden (`Clients::ofMember()`), und es wird in der Auswahl der Konten angeboten, die kein Kunde hat; ein anderer Kunde kann es verknüpfen, dann ersetzt die neue Zeile die alte. Ein Konto, das Du dort löschst, bleibt auf der Karte als *Konto gelöscht* stehen, bis Du die Zeile im Panel Kunden entfernst (*Zeile entfernen*; sie löscht nur die Zeile). Ein Konto, das Du dort anlegst, gehört keinem Kunden, bis Du es verknüpfst - auch wenn es in der Gruppe `k-0001` liegt.

Die Migration `0004` legt den eindeutigen Index an. Hat eine ältere Datenbank ein Konto bei zwei Kunden (das Panel hat nie eines angelegt), bleibt die erste Verknüpfung und die spätere geht.

## Daten und Sicherung

`data/crm.sqlite` (mit den Dateien `-wal` und `-shm` daneben, solange sie offen ist) gehört zum Projekt. **Die tägliche Sicherung von Nino enthält sie nicht**: keine Manifestdatei nennt sie. Sichere die Datei zusammen mit dem Server; ein späterer Patch kann sie in die Sicherung aufnehmen. Die Migrationen laufen beim ersten Zugriff eines Aufrufs; `PRAGMA user_version` sagt, wie weit die Datei ist (4 seit den Konten der Kunden).

**Die PDF-Dateien der Belege liegen in `data/crm/pdf/<Jahr>/<Nummer>.pdf` (ein Vertrag, ein Datenschutz- und ein Informationsdokument haben keine Nummer: ihre Datei heißt `contract-<id>.pdf`, `privacy-<id>.pdf`, `information-<id>.pdf`) und sind ebenfalls nicht in der Sicherung von Nino.** Sie gehören zu den Belegen: Die Datenbank kennt von jeder Datei den Pfad und den SHA-256. Sichere das Verzeichnis `data/crm/` zusammen mit `crm.sqlite`; eine Datei ohne ihre Zeile und eine Zeile ohne ihre Datei sind beide ein Fehler, den Du nicht mehr aus der Datenbank allein beheben kannst. Eine PDF-Datei wird einmal geschrieben und nie überschrieben.

**Stelle `crm.sqlite` und `data/crm/pdf/` immer zusammen wieder her.** Spielst Du nur die Datenbank zurück, liegen Dateien für Nummern im Verzeichnis, die die Datenbank noch nicht vergeben hat: Jedes Festschreiben antwortet dann *Die PDF-Datei der Nummer ... liegt schon unter ...*, weil die nächste Nummer nicht überschreiben darf, was dort liegt. Die Meldung nennt die Nummer und den Pfad der Datei. Gehört sie zu keinem Beleg Deiner Datenbank, verschiebe sie an einen anderen Ort; **lösche nie die Datei eines festgeschriebenen Belegs** - ohne sie kann der Beleg nicht mehr nachgewiesen werden. Danach kannst Du festschreiben.

## Positionen

Das Panel **Positionen** ist der Katalog: Bezeichnung (bis 200 Zeichen), Beschreibung (bis 2000), Einheit (bis 20), Nettopreis, Steuersatz (nur bei Regelbesteuerung), aktiv und die Reihenfolge (die Pfeile in der Liste). Eine Position ist eine Vorlage für eine Zeile: Eine Zeile übernimmt Bezeichnung, Beschreibung, Einheit, Preis und Satz, wenn Du sie hinzufügst, und folgt der Position danach nie. Eine Position wird nicht gelöscht, nur deaktiviert; sie erscheint dann nicht mehr in der Auswahl der Belege.

Preise schreibst Du als `1.234,56`, `1234,56` oder `1234.56`; ein Punkt ohne Komma dahinter ist ein Dezimalpunkt (`1.234` ist darum **kein** Preis, sondern wird abgelehnt). Mengen haben bis zu drei Nachkommastellen (`1,5`, `0,25`, `1.25`); `1.000` und `1.500` werden abgelehnt, weil sie auf Deutsch tausend und auf Englisch eins bedeuten - schreibe `1000` oder `1,5`. Intern sind Preise Cent, Mengen Tausendstel und Sätze Hundertstel Prozent.

## Belege

Die Liste der Belege ist ebenfalls die Tabelle der Workbench. Die Auswahlfelder *Art*, *Status*, *Jahr* und *Kunde* stehen darüber und gelten sofort beim Ändern (es gibt keinen Knopf *Filtern*); die Aktion `crm-documents/list` antwortet mit den Belegen, die die Filter durchlassen, und die Tabelle sucht, sortiert und blättert darin im Browser - die Spalte *Brutto* nach dem Betrag in Cent. Die Aktion kennt keinen Suchbegriff mehr. Die Tabelle sucht in den gespeicherten Werten, nicht im gezeigten Text: "119000" (Cent) findet einen Betrag von 1.190,00 €, "1.190" nicht; das gilt für beide Tabellen. Die Spalten sind Nummer (ohne Nummer der Titel, ohne Titel das Wort *Entwurf*), Art, Kunde, Datum, Brutto und Status (mit der Marke der Freigabe und dem Zustand der Unterschriftsanfrage); ein Klick auf eine Zeile öffnet den Beleg.

Das Panel **Belege** hat sechs Arten: Angebot, Rechnung und Stornorechnung (Gutschrift) mit Zeilen und Summen - und Vertrag, Datenschutz und Information ohne beides (siehe unten). Jeder Beleg gehört zu einem aktiven Kunden und hat ein Datum. Unter *Neu* stehen Rechnung, Angebot, Vertrag, Datenschutz und Information; eine Stornorechnung entsteht nur aus einer Rechnung.

**Ein Entwurf** hat keine Nummer, lässt sich ändern und löschen. Du wählst Kunde, Datum, Zahlungsziel (nur Rechnung; eine Stornorechnung hat keines) oder Gültigkeit (Angebot), eine Leistungszeit (beide Tage oder keiner), eine Vorlage aus dem Feature PDF und fügst Zeilen hinzu (bis 200). In der Bezeichnung einer Zeile bietet der Browser die aktiven Positionen an; wählst Du eine, füllt sie die Zeile. Die Summen unter den Zeilen rechnet das Panel beim Tippen mit denselben Regeln wie der Server.

**Festschreiben** ist ein Schritt in einer Transaktion: Die Nummer der Folge wird vergeben, die Anschrift des Kunden in den Beleg eingefroren, die Summen werden noch einmal gerechnet, und die PDF-Datei wird aus der gewählten Vorlage erzeugt und mit ihrem SHA-256 abgelegt. Scheitert etwas davon, wird nichts davon behalten, auch die Nummer nicht: Die Nummern einer Art und eines Jahres haben keine Lücke. Danach ändert sich am Beleg nichts mehr außer seinem Zustand und den internen Notizen. Ein festgeschriebener Beleg wird nie gelöscht.

**Zustände** gehen nur vorwärts: Eine Rechnung geht von offen über versendet zu bezahlt - oder wird storniert, wenn eine festgeschriebene Stornorechnung über den ganzen Betrag auf sie verweist. Ein Angebot geht von offen über versendet zu angenommen oder abgelehnt; ein offenes oder versendetes Angebot, dessen Gültigkeit abgelaufen ist, wird als *abgelaufen* angezeigt (eine Ansicht, kein Zustand). Eine Stornorechnung geht von offen zu versendet. Aus einem Angebot (offen, versendet, angenommen) machst Du mit einem Knopf einen Rechnungsentwurf mit seinen Zeilen und seiner Leistungszeit - der Entwurf nimmt die Umsatzsteuer-Art der Einstellung von jetzt, jeder Satz wird daran gemessen (bei Kleinunternehmern 0, bei Regelbesteuerung ein Satz der Liste; sonst lehnt die App ab und macht nichts - nimm den Satz dann in den Einstellungen in die Liste auf, denn ein festgeschriebenes Angebot lässt sich nicht ändern), und ein offenes oder versendetes Angebot gilt damit als angenommen; aus einer Rechnung (offen, versendet, bezahlt) einen Stornorechnungsentwurf mit den negierten Zeilen und der Leistungszeit der Rechnung; er behält Art und Sätze der Rechnung (er gibt zurück, was berechnet wurde). Eine Teil-Stornorechnung lässt die Rechnung, wie sie ist, und steht unter ihr.

**Nummern** sind Einstellungen: `A-{YYYY}-{NNNN}` für Angebote, `R-{YYYY}-{NNNN}` für Rechnungen, `G-{YYYY}-{NNNN}` für Stornorechnungen. Ein Format hat `{YYYY}` oder `{YY}` höchstens einmal, `{N...}` genau einmal (die Zahl der N ist die Breite, mit Nullen aufgefüllt, nie abgeschnitten) und sonst nur Buchstaben, Ziffern, `-`, `_` und `.` - ein Schrägstrich wäre ein Pfad. Gezählt wird je Art und Jahr des Belegdatums. **Stelle das Format vor dem ersten Beleg eines Jahres ein.** Änderst Du es später, gilt es für die nächste Nummer, die Zählung läuft weiter, und die Nummern einer Folge sehen unterschiedlich aus - das verantwortest Du.

**Umsatzsteuer:** Die Einstellung `vat_mode` ist `small_business` (Kleinunternehmer nach § 19 UStG, keine Umsatzsteuer, der Hinweis steht auf dem Beleg) oder `regular` (Umsatzsteuer je Zeile mit einem Satz aus der Liste `vat_rates`, gespeichert in Hundertstel Prozent, standardmäßig `1900,700,0`; im Panel schreibst Du sie als Prozent, getrennt durch Semikolon oder Leerzeichen: `19; 7; 0` oder `19; 7; 5,5; 0` - das Komma ist das Dezimalkomma, `19,7,0` wird abgelehnt). Die Art gilt für **neue Entwürfe**; ein Entwurf und ein festgeschriebener Beleg behalten die ihre. Bei Regelbesteuerung gibt es die Option *Reverse-Charge*: Sie setzt jeden Satz auf 0 und schreibt den Hinweis auf den Beleg. Die Umsatzsteuer wird je Satz aus der Summe seiner Zeilen gerechnet und kaufmännisch gerundet (ab 0,5 weg von null), nicht je Zeile.

**Die PDF-Datei** öffnet und lädt Dir der Knopf auf der Seite des Belegs über `/.crm/pdf/<id>` (für ein Konto der Workbench mit der Berechtigung `/_admin/crm-documents/manage` und für ein angemeldetes Mitglied, dessen Kunde den Beleg **freigegeben** bekommen hat; ein Besucher, ein Entwurf, ein Beleg eines anderen Kunden, ein Beleg, der nicht freigegeben ist, und eine unbekannte Nummer bekommen dieselbe 404). Die Antwort ist nie für einen Cache bestimmt (`Cache-Control: private, no-store`). Die Route prüft vor jeder Antwort den SHA-256 der Datei: Stimmt er nicht mit dem gespeicherten, ist die Antwort 409 und das Protokoll nennt die Nummer. Mit `?download=1` wird die Datei als Anhang unter `<Nummer>.pdf` gespeichert.

## Vertrag, Datenschutz und Information

Drei weitere Arten von Belegen haben **keine Positionen, keine Summen und keine Nummer**:

| Art | hängt an | Tag der Vorlage |
| --- | --- | --- |
| Vertrag (`contract`) | einem Angebot des Kunden in jedem Zustand außer Entwurf - der Kunde ist der des Angebots | `contract` |
| Datenschutz (`privacy`) | dem Kunden | `privacy` |
| Information (`information`) | dem Kunden | `information` |

Ein Entwurf hat einen **Titel** (1 bis 120 Zeichen, eine Zeile; das Panel schlägt den Namen der Vorlage vor), ein Datum, eine Vorlage aus PDF mit dem Tag der Art, deren Eingabefelder und interne Notizen. Fälligkeit, Gültigkeit und Leistungszeit gibt es nicht. Ein Vertrag wählt zuerst sein Angebot im Panel; wechselst Du das Angebot, folgt der Kunde.

**Festschreiben** geht wie bei den anderen Arten, nur ohne Nummer: Die PDF-Datei wird aus der Vorlage erzeugt und mit ihrem SHA-256 einmal abgelegt (`contract-<id>.pdf`), der Kunde wird eingefroren, der Zustand ist *offen*. Danach ändert sich nur noch der Zustand und die Notiz. Ein solcher Beleg geht von *offen* zu *versendet* (das markierst Du) und - über Sign - zu *unterschrieben* oder *abgelehnt*.

## Freigabe ins Portal

Jeder festgeschriebene Beleg jeder Art kann **freigegeben** werden: Der Kunde sieht ihn dann auf der Seite Dokumente im Portal und kann ihn von dort laden. **Freigabe zurücknehmen** nimmt ihn wieder weg; die Datei und ihr SHA-256 bleiben, wie die Buchführung es verlangt.

Ist **Mail an die Konten des Kunden** angehakt (der Standard), geht **eine Mail an jedes aktive Konto** des Kunden, mit einem Link auf `/portal/dokumente` (absolut, aus der Adresse der Website) und **ohne Anhang** - die Datei holt sich das Mitglied nach der Anmeldung im Portal. Die Mail ist deutsch (ein Konto von Mitglieder hat keine eigene Sprache), ihre Worte stehen in `Documents/Mail/text/de_DE.php`, ihr Aufbau in `Documents/Mail/templates/release.tpl`. Alle Mails einer Freigabe sind **ein** Aufruf der Obergrenze des Kernels für Mails je Stunde und Adresse; ist sie erreicht, antwortet das Panel 429 und **nichts** ist geschehen (nicht freigegeben, nichts versendet). Hat die Website keine Adresse (der Text `/project/website/general/url`), gibt es keinen Link: Die Freigabe mit Mail ist dann 409.

**Versenden** im Panel ist genau die Freigabe mit Mail für Angebote, Rechnungen und Stornorechnungen: Ist der Beleg noch *offen* und ging mindestens eine Mail hinaus, wird er *versendet*. Hatte der Kunde kein aktives Konto oder kam keine Mail an, bleibt der Zustand und das Panel sagt es. Ein Vertrag und die anderen werden durch die Mail nicht *versendet*; ihren Schritt setzt Du selbst.

## Unterschrift über Sign

Ein **Vertrag** oder ein **Datenschutzdokument** (festgeschrieben, offen oder versendet, ohne wartende Anfrage) bekommt im Panel **Unterschrift anfordern**: Du wählst ein aktives Konto des Kunden und die Tage, die der Link gilt (1 bis 90; vorbelegt mit der Einstellung `days` von Sign). Sign bekommt die Vorlage des Belegs mit **denselben Daten, mit denen die PDF-Datei erzeugt wurde**, und schickt dem Konto den Link; nach dem Unterschreiben führt Sign das Mitglied auf `/portal/dokumente` zurück. Die unterschriebene PDF-Datei gehört Sign (`Sign::path()`), das Modul kopiert sie nie; das Portal verlinkt sie, und im Panel öffnet sie das Konto der Workbench über denselben Link von Sign.

**Die Vorlage braucht ein Unterschriftsfeld für ein Mitglied** (Block *Unterschrift*, Partei *Mitglied*, im Panel PDF); sonst antwortet das Panel 409 mit dem Hinweis darauf. Ein Vertrag, den ein Mitglied unterschreiben soll, braucht dazu die Eingabefelder, die er hat, gefüllt: Sign verlangt dieselben Pflichtfelder wie das Festschreiben.

Der Beleg folgt der Anfrage: Unterschreibt das Mitglied oder lehnt es ab, ruft Sign `/sign/signed` und `/sign/declined` auf, und der Beleg wird *unterschrieben* (mit dem Tag der Unterschrift) oder *abgelehnt*. Was Sign sonst mit der Anfrage macht - Du ziehst sie im Panel Unterschriften zurück, ihre Zeit läuft ab, das Dokument hat sich seit der Anfrage geändert - liest das Modul, wenn es den Beleg liest (Panel, Liste, Portal), und speichert es als Zustand der Anfrage; der Beleg bleibt dann *offen* oder *versendet* und kann **neu angefordert** werden. Eine Anfrage, die Sign nicht mehr kennt, gilt als zurückgezogen. Ein Aufruf von Sign, den die Datenbank nicht annimmt, bricht die Unterschrift nicht ab: Er steht als Warnung im Protokoll, und das Lesen des Belegs holt ihn nach.

## Die Seite Dokumente im Portal

`/portal/dokumente` (der Knopf **Portal einrichten** legt sie an) zeigt mit `[crm-documents]` **alle freigegebenen Belege des Kunden des angemeldeten Mitglieds**, jeder Art, die neuesten zuerst: die Art, den Titel (ohne Titel die Nummer), das Datum, den Zustand in Worten (offen, versendet, bezahlt, storniert, angenommen, abgelehnt, unterschrieben, abgelaufen), den Link zum Laden und - wo eine Unterschrift angefordert ist - den Link zur Seite von Sign, bei einem unterschriebenen Beleg den Link zur unterschriebenen Datei. Ein Mitglied ohne Kunden und ein Kunde ohne freigegebene Belege lesen den Satz, dass noch nichts bereitliegt. Die Liste braucht kein JavaScript; ihr Aufbau steht in `Portal/templates/*.tpl`, ihr Aussehen in `Portal/assets/portal.css` (die Datei kommt über `\Nino\Html::addAsset()` in das Stylesheet der Website; die Eigenschaften `--crm-documents-space`, `--crm-documents-corners` und `--crm-documents-border` kannst Du in Deinem Stylesheet verschieben), ihre Worte in `Portal/text/*.php`.

**Auf einem Portal, das schon eingerichtet ist:** Der Knopf **Portal einrichten** ergänzt die Route `/portal/dokumente`, die Vorlage `page-portal-documents.tpl` und die Worte der Seite - und nichts, was Du geändert hast. Deine Vorlage `page-portal.tpl` bleibt, wie sie ist: Den Link auf die neue Seite trägst Du dort von Hand ein (`[button /template/page-portal/link-documents/label href="/template/page-portal/link-documents/href" style="primary"]`, die beiden Texte legt der Knopf an) oder Du spielst die Vorlage im Builder neu ein.

## Das Panel Kunden

Die Liste der Kunden ist die **Tabelle der Workbench** (`Nino.adminUi.table()`): Suche, Sortierung nach Spalte und Seiten über alle Kunden laufen im Browser; die Aktion `crm-clients/list` antwortet mit allen Kunden und kennt keinen Suchbegriff. Die Spalten sind Nummer, Firma, Name, E-Mail, Ort, Konten, davon aktiv und aktiv; ein Klick auf eine Zeile öffnet die Karte, *Neuer Kunde* steht unter der Tabelle.

Die Karte eines Kunden zeigt, wie viele Belege er hat und wie viele davon im Portal freigegeben sind. Der Link darunter öffnet das Panel Belege mit dem Filter *Kunde* auf diesen Kunden (die Adresse `#crm-documents/client/<id>`; das Panel liest sie beim Öffnen und setzt den Filter einmal).

## Vorlagen im Feature PDF

Die Daten des Inhabers (Name, Anschrift, Steuernummer, Bankverbindung, Logo, Unterschrift) stehen in seinen Vorlagen, in Kopf und Fuß des Features PDF - die App gibt der Vorlage, was nur sie weiß: den Kunden, den Beleg, die Zeilen, die Summen und den Steuerhinweis. Eine Vorlage mit dem Tag `offer` gehört zu Angeboten, eine mit dem Tag `invoice` zu Rechnungen und Stornorechnungen, eine mit dem Tag `contract` zu Verträgen, `privacy` zu Datenschutz- und `information` zu Informationsdokumenten. Die Eingabefelder (Blöcke vom Typ *Eingabe*) der gewählten Vorlage fragt das Panel im Editor ab und setzt sie dort ein, wo die Vorlage sie hat; ein Pflichtfeld muss beim Festschreiben gefüllt sein.

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
| `[[site.portal]]`, `[[site.login]]` | die absoluten Adressen von `/portal` und `/login` der Website (leer, wo die Website keine Adresse hat) - damit eine Information der Brief mit den Zugangsdaten sein kann; bei jeder Art |
| `[[client.number]]`, `[[client.company]]`, `[[client.name]]`, `[[client.street]]`, `[[client.zip]]`, `[[client.city]]`, `[[client.country]]`, `[[client.vat_id]]`, `[[client.email]]` | der Kunde, wie er beim Festschreiben war |

**Platzhalter-Blöcke (Slots)** der Vorlage: `address` (der Block des Kunden: Firma, Name, Straße, PLZ und Ort, das Land mit seinem deutschen Namen, wenn es nicht DE ist: ist die PHP-Erweiterung intl geladen, steht dort *Österreich*, sonst das Kürzel `AT`; für Deutschland steht nie eine Landzeile), `lines` (die Tabelle der Zeilen: Position, Bezeichnung mit Beschreibung darunter, Menge mit Einheit, Einzelpreis, bei Regelbesteuerung der Satz, Betrag) und `totals` (bei Regelbesteuerung Netto, die Umsatzsteuer je Satz auf ihrer Basis und Brutto; bei Kleinunternehmern nur der Gesamtbetrag, der zugleich das Netto ist). Der Aufbau der Slots steht in `Documents/templates/*.tpl`.

**Vertrag, Datenschutz und Information** kennen `document.title` (der Titel des Dokuments selbst), `document.date`, `document.parent` (beim Vertrag die Nummer seines Angebots), `client.*`, `site.*` und den Slot `address`. Ein **Vertrag** kennt dazu die Tokens seines Angebots - `offer.number`, `offer.date`, `offer.valid`, `offer.net`, `offer.vat` und `offer.gross` - und die Slots `lines` (die Zeilen des Angebots) und `totals` (seine Summen), damit er zitieren kann, worum es geht. Die Nummer, die Fälligkeit, die Leistungszeit, der Steuerhinweis und die Summen des Belegs selbst gibt es bei diesen drei Arten nicht.

**Eine Vorlage, die eine vollständige Rechnung ergibt,** braucht die Slots `address`, `lines` und `totals` und den Token `document.vatnote`. Die Angaben nach § 14 UStG (vollständiger Name und Anschrift beider Seiten, Steuernummer oder USt-IdNr. des Inhabers, Ausstellungsdatum, fortlaufende Rechnungsnummer, Leistungszeit, Entgelt, Steuersatz und Steuerbetrag oder der Hinweis auf die Befreiung) sind Deine Verantwortung; die App gibt Dir jede Angabe, die nur sie kennt.

Die Worte in der PDF-Datei (Angebot, Rechnung, Spaltenköpfe, die Hinweise) sind deutsch und stehen in `Documents/Render/text/de_DE.php`.

## Test

```bash
php app/Crm/tests/crm-smoke.php
php app/Crm/tests/documents-smoke.php
php app/Crm/tests/portal-smoke.php
node app/Crm/tests/crm-js-smoke.js
node app/Crm/tests/documents-js-smoke.js
node app/Crm/tests/items-js-smoke.js
```

Die PHP-Tests laufen über `tests/harness.php` des Checkouts, legen ihr eigenes Projektverzeichnis an und räumen es auf. `documents-smoke.php` braucht das Feature PDF in `features/` (es legt Vorlagen an und lässt acht Prozesse zugleich festschreiben) und ruft die beiden JavaScript-Tests auf, wo `node` im Pfad liegt; `portal-smoke.php` braucht dazu Mitglieder, Dateien und Sign (Freigabe und Mail, Anfrage und Aufrufe von Sign, die Liste im Portal, die Route für ein Mitglied); liegt `pdftotext` im Pfad, prüft es auch die Worte in den PDF-Dateien. Die Fälle der Geldarithmetik stehen einmal in `tests/money-cases.json`: der PHP-Test prüft `Money.php` damit, der JavaScript-Test `money.js`.

## Patches einspielen

Die Patches kommen als eine Datei je Änderung und enthalten nur `app/Crm/**`. In einer Kopie Deines Repositories:

```bash
git apply --check C01.patch   # prüft, ohne etwas zu ändern
git apply --index C01.patch   # spielt sie ein, für den Commit vorgemerkt
git commit
```

Die erste Zeile der Datei (`# ...`) ist der Titel des Commits. Die Reihenfolge der Patches ist ihre Nummer (C01, C02, ...); spätere bauen auf früheren auf: C02 Positionen, Angebote, Rechnungen und Stornorechnungen, C03 Verträge, Datenschutz- und Informationsdokumente, Freigabe und Unterschrift samt Seite Dokumente im Portal, C04 Importe.

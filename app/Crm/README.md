# Crm - das Projektmodul für Kunden und Kundenportal

`\Crm\Crm` ist das Modul dieses Projekts (`dapeio/nino-crm`). Es liegt in `app/Crm/` und gehört zum Projekt, nicht zu Nino und nicht zum Feature-Katalog. Es bringt:

- eine SQLite-Datenbank `data/crm.sqlite` mit Migrationen (`PRAGMA user_version`),
- die Kunden (Nummer `K-0001`, Firma, Name, Anschrift, E-Mail, Telefon, USt-IdNr., Notizen, aktiv) mit ihren Konten des Features Mitglieder,
- das Panel **Kunden** (Liste mit Suche, Karte, anlegen, ändern, deaktivieren, Konto einladen),
- die Portalseiten hinter `/portal` und die Anmeldeseite `/login` als Installationseinheit, die das Panel auf einen Knopf einspielt,
- den Shortcode `[crm-client]`, der im Portal die Firma des Kunden des angemeldeten Mitglieds schreibt.

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

`data/crm.sqlite` (mit den Dateien `-wal` und `-shm` daneben, solange sie offen ist) gehört zum Projekt. **Die tägliche Sicherung von Nino enthält sie nicht**: keine Manifestdatei nennt sie. Sichere die Datei zusammen mit dem Server; ein späterer Patch kann sie in die Sicherung aufnehmen. Die Migrationen laufen beim ersten Zugriff eines Aufrufs; `PRAGMA user_version` sagt, wie weit die Datei ist.

## Test

```bash
php app/Crm/tests/crm-smoke.php
```

Der Test läuft über `tests/harness.php` des Checkouts, legt sein eigenes Projektverzeichnis an und räumt es auf.

## Patches einspielen

Die Patches kommen als eine Datei je Änderung und enthalten nur `app/Crm/**`. In einer Kopie Deines Repositories:

```bash
git apply --check C01.patch   # prüft, ohne etwas zu ändern
git apply --index C01.patch   # spielt sie ein, für den Commit vorgemerkt
git commit
```

Die erste Zeile der Datei (`# ...`) ist der Titel des Commits. Die Reihenfolge der Patches ist ihre Nummer (C01, C02, ...); spätere bauen auf früheren auf: C02 Positionen, Angebote und Rechnungen, C03 Dokumente, Kategorien und Freigabe samt Seite Dokumente im Portal, C04 Importe.

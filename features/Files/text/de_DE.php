<?php
declare(strict_types=1);

// Die eigenen Worte des Panels, eine Datei je Oberflächensprache (siehe Admin::text()): in die Fills der Workbench
// gemischt, solange das Feature aktiv ist, nie in die Textdateien des Projekts. Die Worte der Website sind der
// andere Satz, in features/Files/install/text/
return [

	// Der Eintrag in der Navigation, benannt von Admin::nav() - und der Name, den der Kopf über dem Panel zeigt;
	// deshalb zeichnet der Schirm keine eigene Überschrift
	'[[/_admin/files/nav]]'							=> 'Dateien',

	// Die Kachel des Dashboards (Admin::summary())
	'[[/_admin/files/label/count]]'					=> 'Dateien',

	// Die Einheiten einer Größe und das Zeichen, das die Dezimalstellen trennt
	'[[/_admin/files/unit/byte]]'					=> 'B',
	'[[/_admin/files/unit/kilobyte]]'				=> 'KB',
	'[[/_admin/files/unit/megabyte]]'				=> 'MB',
	'[[/_admin/files/unit/gigabyte]]'				=> 'GB',
	'[[/_admin/files/unit/separator]]'				=> ',',

	// Was der Schirm über sich und die Grenzen sagt, in denen er arbeitet
	'[[/_admin/files/hint/intro]]'					=> 'Ordner und Dateien für die Gruppen des Features Mitglieder. Ein Mitglied sieht die Ordner seiner Gruppe und lädt herunter, was für es sichtbar ist; in den Ordner, den Du als Upload-Ordner markierst, kann ein Mitglied hochladen.',
	'[[/_admin/files/hint/limits]]'					=> 'Der Server nimmt %s in einer Anfrage. Eine Datei wird in Teile von %s geschnitten; die größte Datei ist %s. Eine Grenze des Webservers (etwa client_max_body_size) steht hier nicht: Ein Upload findet sie selbst, indem er seine Teile halbiert, wenn der Server sie mit 413 abweist.',
	'[[/_admin/files/hint/quota]]'					=> 'Die Gruppe belegt %s von %s.',
	'[[/_admin/files/hint/backup]]'					=> 'Die tägliche Sicherung enthält den Index der Dateien, nicht die Dateien selbst – sie liegen in data/files/ und werden mit dem Server gesichert.',
	'[[/_admin/files/hint/nogroup]]'				=> 'Das Feature Mitglieder hat noch keine Gruppe. Gib einem Mitglied im Panel Mitglieder eine Gruppe, dann lege hier Ordner dafür an.',
	'[[/_admin/files/label/no-limit]]'				=> 'jede Größe',

	// Die Gruppe und die Ordner
	'[[/_admin/files/label/group]]'					=> 'Gruppe',
	'[[/_admin/files/label/folders]]'				=> 'Ordner',
	'[[/_admin/files/label/folder-new]]'			=> 'Neuer Ordner',
	'[[/_admin/files/label/name]]'					=> 'Name',
	'[[/_admin/files/label/add]]'					=> 'Ordner anlegen',
	'[[/_admin/files/label/open]]'					=> 'Öffnen',
	'[[/_admin/files/label/rename]]'				=> 'Umbenennen',
	'[[/_admin/files/label/mark]]'					=> 'Mitglieder laden hier hoch',
	'[[/_admin/files/label/unmark]]'				=> 'Hochladen hier beenden',
	'[[/_admin/files/label/save]]'					=> 'Speichern',
	'[[/_admin/files/label/cancel]]'				=> 'Abbrechen',
	'[[/_admin/files/label/delete]]'				=> 'Löschen',
	'[[/_admin/files/label/count-file]]'			=> '%s Datei',
	'[[/_admin/files/label/count-files]]'			=> '%s Dateien',
	'[[/_admin/files/state/upload]]'				=> 'Upload-Ordner',
	'[[/_admin/files/empty/folders]]'				=> 'Für diese Gruppe gibt es noch keine Ordner.',
	'[[/_admin/files/title/folder]]'				=> 'Den Ordner löschen',
	'[[/_admin/files/confirm/folder]]'				=> 'Den Ordner %s löschen?',
	'[[/_admin/files/confirm/folder-file]]'			=> 'Den Ordner %s löschen? Seine Datei wird mit ihm gelöscht, endgültig.',
	'[[/_admin/files/confirm/folder-files]]'		=> 'Den Ordner %s löschen? Seine %s Dateien werden mit ihm gelöscht, endgültig.',
	'[[/_admin/files/confirm/discard]]'				=> 'Was Du getippt hast, ist nicht gespeichert. Verwerfen?',
	'[[/_admin/files/error/name]]'					=> 'Gib einen Namen für den Ordner ein.',
	'[[/_admin/files/error/folder-add]]'			=> 'Der Ordner konnte nicht angelegt werden.',
	'[[/_admin/files/error/folder-save]]'			=> 'Der Ordner konnte nicht umbenannt werden.',
	'[[/_admin/files/error/folder-delete]]'			=> 'Der Ordner konnte nicht gelöscht werden.',
	'[[/_admin/files/error/target]]'				=> 'Die Markierung konnte nicht geändert werden.',

	// Die Dateien des geöffneten Ordners
	'[[/_admin/files/label/files-in]]'				=> 'Dateien in %s',
	'[[/_admin/files/empty/files]]'					=> 'In diesem Ordner liegen noch keine Dateien.',
	'[[/_admin/files/label/by-owner]]'				=> 'Betreiber',
	'[[/_admin/files/label/by-gone]]'				=> 'Ein Mitglied, das es nicht mehr gibt',
	'[[/_admin/files/label/visible]]'				=> 'Für die Mitglieder sichtbar',
	'[[/_admin/files/label/download]]'				=> 'Herunterladen',
	'[[/_admin/files/label/move]]'					=> 'Verschieben nach',
	'[[/_admin/files/title/file]]'					=> 'Die Datei löschen',
	'[[/_admin/files/confirm/file]]'				=> '%s löschen? Die Datei ist endgültig weg.',
	'[[/_admin/files/error/save]]'					=> 'Die Datei konnte nicht geändert werden.',
	'[[/_admin/files/error/delete]]'				=> 'Die Datei konnte nicht gelöscht werden.',
	'[[/_admin/files/label/remove]]'				=> 'Vom Server entfernen',
	'[[/_admin/files/label/removed]]'			=> 'Entfernt am %s',
	'[[/_admin/files/title/remove]]'				=> 'Die Datei vom Server entfernen',
	'[[/_admin/files/confirm/remove]]'			=> '%s vom Server entfernen? Die Bytes sind danach weg; der Eintrag mit Namen, Größe und Datum bleibt, und die Mitglieder lesen, dass die Datei entfernt wurde.',
	'[[/_admin/files/error/remove]]'			=> 'Die Datei konnte nicht entfernt werden.',

	// Der Upload des Betreibers
	'[[/_admin/files/label/upload-to]]'				=> 'Hochladen in %s',
	'[[/_admin/files/label/choose]]'				=> 'Dateien',
	'[[/_admin/files/label/upload-visible]]'		=> 'Für die Mitglieder sichtbar',
	'[[/_admin/files/hint/upload-visible]]'			=> 'Ausgeschaltet bleiben die Dateien verborgen, bis Du sie sichtbar machst.',
	'[[/_admin/files/label/notify]]'				=> 'Mitglieder per E-Mail informieren',
	'[[/_admin/files/hint/notify]]'				=> 'Gilt für jeden Upload und jeden Schalter „sichtbar“ dieser Seite, solange das Häkchen steht. Voreingestellt ist die Einstellung der Funktion.',
	'[[/_admin/files/label/upload]]'				=> 'Hochladen',
	'[[/_admin/files/label/waiting]]'				=> 'Wartet',
	'[[/_admin/files/label/sending]]'				=> 'Lädt hoch',
	'[[/_admin/files/label/smaller]]'				=> 'Der Server nimmt so große Anfragen nicht an. Die Datei wird in kleineren Teilen noch einmal gesendet.',
	'[[/_admin/files/label/done]]'					=> 'Hochgeladen',
	'[[/_admin/files/msg/uploaded-file]]'			=> 'Die Datei ist hochgeladen.',
	'[[/_admin/files/msg/uploaded]]'				=> '%s Dateien hochgeladen.',
	'[[/_admin/files/error/choose]]'				=> 'Wähle zuerst die Dateien zum Hochladen.',
	'[[/_admin/files/error/empty]]'					=> '%s ist leer.',
	'[[/_admin/files/error/toolarge]]'				=> '%s ist größer als die größte Datei (%s).',
	'[[/_admin/files/error/upload]]'				=> 'Die Datei konnte nicht hochgeladen werden.',
	'[[/_admin/files/error/floor]]'					=> 'Der Server nimmt auch Anfragen dieser kleinen Größe nicht an. Frage Deinen Hoster, wie groß eine Anfrage sein darf.',

	// Was das Panel zu einer Liste sagt, die nicht geladen werden konnte: der Schlüssel, den Nino.adminUi.panel() für einen Fehler beim Laden nimmt
	'[[/_admin/files/error/load]]'					=> 'Die Dateien konnten nicht geladen werden.',

	// Die Sätze der Codes, mit denen das Panel und die Route antworten
	'[[/_admin/error/files_group]]'					=> 'Das Feature Mitglieder hat keine solche Gruppe.',
	'[[/_admin/error/files_name]]'					=> 'Der Name ist leer.',
	'[[/_admin/error/files_long]]'					=> 'Der Name darf bis zu %s Zeichen lang sein.',
	'[[/_admin/error/files_unknown]]'				=> 'Diesen Ordner oder diese Datei gibt es nicht (mehr).',
	'[[/_admin/error/files_target]]'				=> 'Eine Datei lässt sich nur in einen anderen Ordner derselben Gruppe verschieben.',
	'[[/_admin/error/files_folder]]'				=> 'Diesen Ordner gibt es nicht (mehr).',
	'[[/_admin/error/files_type]]'					=> 'Diese Art von Datei wird nicht angenommen: Die Endung steht nicht in der Liste der Typen, oder die Bytes sind nicht das, was der Name sagt.',
	'[[/_admin/error/files_size]]'					=> 'Die Datei darf bis zu %s MB groß sein.',
	'[[/_admin/error/files_quota]]'					=> 'Die Gruppe darf bis zu %s MB belegen; die Datei würde darüber hinausgehen.',
	'[[/_admin/error/files_chunk]]'					=> 'Der Upload wurde unterbrochen oder ist nicht vollständig angekommen. Versuche es noch einmal.',
	'[[/_admin/error/files_limit]]'					=> 'Zu viele Anfragen. Versuche es in einer Stunde noch einmal.',
	'[[/_admin/error/files_write]]'					=> 'Die Datei konnte auf dem Server nicht gespeichert werden.',
];

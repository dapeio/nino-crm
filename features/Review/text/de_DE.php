<?php
declare(strict_types=1);

// Die eigenen Worte des Panels, eine Datei je Oberflächensprache (siehe Admin::text()): Sie werden in die Texte der
// Workbench gemischt, solange das Feature aktiv ist, und nie in die Textdateien des Projekts. Die Worte der Website
// sind der andere Satz, in features/Review/install/text/
return [

	// Der Eintrag in der Leiste, von Admin::nav() genannt – und der Name, den der Kopf über dem Panel zeigt, weshalb der
	// Bildschirm keine eigene Überschrift zeichnet
	'[[/_admin/review/nav]]'						=> 'Review',

	// Die Kachel des Dashboards (Admin::summary())
	'[[/_admin/review/label/open]]'					=> 'Offene Kommentare',

	// Was der Bildschirm über sich sagt
	'[[/_admin/review/hint/intro]]'					=> 'Die Audio- und Videodateien, zu denen Mitglieder Kommentare geschrieben haben, nach Gruppe und Ordner. Öffne eine, um sie abzuspielen, die Kommentare in der Reihenfolge des Stücks zu lesen, sie zu beantworten, abzuhaken oder zu löschen. Die Kommentare eines Mitglieds sind offen, bis Du sie abhakst.',
	'[[/_admin/review/empty/files]]'				=> 'Es wurden noch keine Kommentare geschrieben.',
	'[[/_admin/review/label/nogroup]]'				=> 'Ohne Gruppe',
	'[[/_admin/review/label/count-comment]]'		=> '%s Kommentar',
	'[[/_admin/review/label/count-comments]]'		=> '%s Kommentare',
	'[[/_admin/review/label/count-open]]'			=> '%s offen',
	'[[/_admin/review/label/open-file]]'			=> 'Öffnen',
	'[[/_admin/review/kind/audio]]'					=> 'Audio',
	'[[/_admin/review/kind/video]]'					=> 'Video',

	// Die Kommentare zu einer Datei
	'[[/_admin/review/label/back]]'					=> 'Zurück zu den Dateien',
	'[[/_admin/review/label/comments-on]]'			=> 'Kommentare zu %s',
	'[[/_admin/review/empty/comments]]'				=> 'Zu dieser Datei gibt es noch keine Kommentare.',
	'[[/_admin/review/hint/unplayable]]'			=> 'Die Bytes dieser Datei sind weg, sie lässt sich also nicht abspielen.',
	'[[/_admin/review/hint/files-permission]]'		=> 'Dieses Konto darf keine Dateien abspielen: Dazu braucht es die Berechtigung des Panels Dateien.',
	'[[/_admin/review/label/owner]]'				=> 'Betreiber',
	'[[/_admin/review/label/gone]]'					=> 'Ein Mitglied, das es nicht mehr gibt',
	'[[/_admin/review/state/done]]'					=> 'Erledigt',
	'[[/_admin/review/state/open]]'					=> 'Offen',
	'[[/_admin/review/label/reply]]'				=> 'Antworten',
	'[[/_admin/review/label/mark-done]]'			=> 'Als erledigt abhaken',
	'[[/_admin/review/label/reopen]]'				=> 'Wieder öffnen',
	'[[/_admin/review/label/delete]]'				=> 'Löschen',
	'[[/_admin/review/label/cancel]]'				=> 'Abbrechen',
	'[[/_admin/review/title/comment]]'				=> 'Den Kommentar löschen',
	'[[/_admin/review/confirm/comment]]'			=> 'Diesen Kommentar löschen? Er ist dann endgültig weg.',
	'[[/_admin/review/confirm/comment-answer]]'		=> 'Diesen Kommentar löschen? Seine Antwort wird mit ihm endgültig gelöscht.',
	'[[/_admin/review/confirm/comment-answers]]'	=> 'Diesen Kommentar löschen? Seine %s Antworten werden mit ihm endgültig gelöscht.',
	'[[/_admin/review/confirm/discard]]'			=> 'Was Du getippt hast, ist nicht gespeichert. Verwerfen?',

	// Der Kommentar des Betreibers
	'[[/_admin/review/label/write]]'				=> 'Einen Kommentar schreiben',
	'[[/_admin/review/label/answering]]'			=> 'Antwort an %s',
	'[[/_admin/review/label/text]]'					=> 'Kommentar',
	'[[/_admin/review/label/at]]'					=> 'Zeit im Stück (m:ss, darf leer bleiben)',
	'[[/_admin/review/label/now]]'					=> 'An der Stelle des Players',
	'[[/_admin/review/label/notify]]'				=> 'Mitglied per E-Mail informieren',
	'[[/_admin/review/label/send]]'					=> 'Senden',
	'[[/_admin/review/error/empty]]'				=> 'Schreibe zuerst einen Kommentar.',

	// Was das Panel zu einer Anfrage sagt, die fehlschlug: der Schlüssel, den Nino.adminUi.panel() für ein fehlgeschlagenes Laden nimmt, und die Sätze der anderen Anfragen
	'[[/_admin/review/error/load]]'					=> 'Die Kommentare konnten nicht geladen werden.',
	'[[/_admin/review/error/comments]]'				=> 'Die Kommentare zur Datei konnten nicht geladen werden.',
	'[[/_admin/review/error/answer]]'				=> 'Der Kommentar konnte nicht gespeichert werden.',
	'[[/_admin/review/error/done]]'					=> 'Der Kommentar konnte nicht geändert werden.',
	'[[/_admin/review/error/delete]]'				=> 'Der Kommentar konnte nicht gelöscht werden.',

	// Die Sätze der Codes, mit denen das Panel antwortet
	'[[/_admin/error/review_unknown]]'				=> 'Diese Datei oder dieser Kommentar existiert nicht (mehr).',
	'[[/_admin/error/review_empty]]'					=> 'Der Kommentar ist leer.',
	'[[/_admin/error/review_long]]'					=> 'Der Kommentar darf bis zu %s Zeichen haben.',
	'[[/_admin/error/review_time]]'					=> 'Das ist keine Zeit. Schreibe sie als m:ss, h:mm:ss oder in Sekunden, oder lass das Feld leer.',
];

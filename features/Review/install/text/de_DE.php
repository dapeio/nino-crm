<?php
declare(strict_types=1);

// Die Worte, die die Website sagt – bei jeder Aktivierung in die eigene text/de_DE.php des Projekts gemischt,
// ergänzend: Ein Schlüssel, den das Projekt schon hat, bleibt. Danach pflegt sie ein Redakteur im Panel Texte
// und öffnet nie ein Feature-Verzeichnis. Die Worte des Panels sind ein anderer Satz, in features/Review/text/.
return [

	// Die Liste der Dateien, [review-player] ohne gewählte Datei
	'[[/feature/review/list/empty]]'			=> 'Für Deine Gruppe gibt es noch keine Audio- oder Videodateien.',
	'[[/feature/review/kind/audio]]'			=> 'Audio',
	'[[/feature/review/kind/video]]'			=> 'Video',

	// Der Player einer Datei
	'[[/feature/review/player/back]]'			=> 'Zurück zu den Dateien',
	'[[/feature/review/player/comments]]'		=> 'Kommentare',
	'[[/feature/review/player/none]]'			=> 'Zu dieser Datei gibt es noch keine Kommentare.',

	// Ein Kommentar, und wer ihn schrieb
	'[[/feature/review/comment/owner]]'			=> 'Betreiber',
	'[[/feature/review/comment/member]]'		=> 'Ein Mitglied',
	'[[/feature/review/comment/gone]]'			=> 'Ein Mitglied, das es nicht mehr gibt',
	'[[/feature/review/comment/done]]'			=> 'Erledigt',
	'[[/feature/review/comment/reply]]'			=> 'Antworten',
	'[[/feature/review/comment/play]]'			=> 'Ab hier abspielen',

	// Das Formular
	'[[/feature/review/form/at]]'				=> 'Zeit im Stück (m:ss, darf leer bleiben)',
	'[[/feature/review/form/follow]]'			=> 'Zeit aktualisieren',
	'[[/feature/review/form/text]]'				=> 'Dein Kommentar',
	'[[/feature/review/form/button]]'			=> 'Senden',

	// Was das Formular über seinem Knopf sagt: die Zustände von ?review-state=
	'[[/feature/review/state/sent]]'			=> 'Dein Kommentar ist gespeichert.',
	'[[/feature/review/state/long]]'			=> 'Dein Kommentar ist zu lang. Bitte kürze ihn.',
	'[[/feature/review/state/limit]]'			=> 'Zu viele Kommentare. Bitte versuche es in einer Stunde noch einmal.',
	'[[/feature/review/state/expired]]'			=> 'Deine Sitzung ist abgelaufen. Bitte schreibe Deinen Kommentar noch einmal.',
	'[[/feature/review/state/invalid]]'			=> 'Das hat nicht geklappt: Die Datei ist für Dich nicht da, oder die Zeit oder der Text stimmt nicht.',

	// Was das Skript sagt, wenn es eine Antwort vorbereitet
	'[[/feature/review/script/answering]]'		=> 'Antwort an %s',
	'[[/feature/review/script/cancel]]'			=> 'Abbrechen',

	// Die Mail an den Betreiber, wenn ein Mitglied einen Kommentar geschrieben hat, und die Mail an ein Mitglied oder eine Gruppe zu einem Kommentar des Betreibers
	'[[/feature/review/mail/comment-subject]]'	=> 'Ein Mitglied hat einen Kommentar geschrieben',
	'[[/feature/review/mail/comment-title]]'	=> 'Ein neuer Kommentar',
	'[[/feature/review/mail/comment-intro]]'	=> 'Ein Mitglied hat einen Kommentar geschrieben:',
	'[[/feature/review/mail/comment-button]]'	=> 'Das Panel Review öffnen',
	'[[/feature/review/mail/comment-about-subject]]'	=> 'Ein Mitglied hat einen Kommentar geschrieben: [[about]]',
	'[[/feature/review/mail/comment-button-card]]'	=> 'Die Projektkarte öffnen',
	'[[/feature/review/mail/label-time]]'		=> 'Zeit im Stück:',
	'[[/feature/review/mail/label-by]]'			=> 'Geschrieben von:',
	'[[/feature/review/mail/no-time]]'			=> 'keine',
	'[[/feature/review/mail/answer-subject]]'	=> 'Eine Antwort auf Deinen Kommentar',
	'[[/feature/review/mail/answer-title]]'		=> 'Ein neuer Kommentar bei',
	'[[/feature/review/mail/answer-greeting]]'	=> 'Hallo',
	'[[/feature/review/mail/answer-intro]]'		=> 'Der Betreiber hat auf Deinen Kommentar zur Datei geantwortet',
	'[[/feature/review/mail/note-subject]]'		=> 'Ein neuer Kommentar zu einer Datei Deiner Gruppe',
	'[[/feature/review/mail/note-intro]]'		=> 'Der Betreiber hat einen Kommentar zur Datei geschrieben',
	'[[/feature/review/mail/answer-button]]'	=> 'Zur Website',
	'[[/feature/review/mail/answer-notice]]'	=> 'Um ihn zu lesen, melde Dich mit einem Link an, der an Deine E-Mail-Adresse geschickt wird.',
	'[[/feature/review/mail/answer-notice-code]]'	=> 'Zum Lesen melde Dich an: Der Code für die Anmeldung kommt an Deine E-Mail-Adresse.',
	'[[/feature/review/mail/closing]]'			=> 'Viele Grüße',
];

<?php
declare(strict_types=1);

// Die Worte, die die Website sagt – bei jeder Aktivierung in die eigene text/de_DE.php des Projekts gemischt,
// ergänzend: Ein Schlüssel, den das Projekt schon hat, bleibt. Danach pflegt sie ein Redakteur im Panel Texte
// und öffnet nie ein Feature-Verzeichnis. Die Worte des Panels sind ein anderer Satz, in features/Files/text/.
return [

	// Die Einheiten einer Größe und das Zeichen, das die Dezimalstellen trennt: 1,5 MB
	'[[/feature/files/unit/byte]]'			=> 'B',
	'[[/feature/files/unit/kilobyte]]'		=> 'KB',
	'[[/feature/files/unit/megabyte]]'		=> 'MB',
	'[[/feature/files/unit/gigabyte]]'		=> 'GB',
	'[[/feature/files/unit/separator]]'		=> ',',

	// Der Browser, [files-browser]
	'[[/feature/files/browser/download]]'	=> 'Speichern',
	'[[/feature/files/browser/empty]]'		=> 'In diesem Ordner liegen noch keine Dateien.',
	'[[/feature/files/browser/none]]'			=> 'Für Deine Gruppe gibt es noch keine Ordner.',

	// Was die Adresse einer Datei sagt, deren Bytes vom Server entfernt wurden (Status 410)
	'[[/feature/files/download/removed]]'	=> 'Die Datei wurde bereits entfernt. Bitte schreib uns an, wenn Du sie nochmal benötigst.',

	// Das Formular, [files-upload]
	'[[/feature/files/upload/label]]'		=> 'Datei wählen',
	'[[/feature/files/upload/hint]]'		=> 'Größte Datei:',
	'[[/feature/files/upload/types]]'		=> 'Erlaubte Typen:',
	'[[/feature/files/upload/button]]'		=> 'Hochladen',

	// Was das Formular über seinem Knopf sagt: die Zustände von ?files= – und was das Skript zu einer Ablehnung sagt, unter denselben Namen
	'[[/feature/files/state/sent]]'			=> 'Die Datei ist hochgeladen.',
	'[[/feature/files/state/folder]]'		=> 'Dieser Ordner ist für Dich nicht mehr da. Bitte lade die Seite neu.',
	'[[/feature/files/state/type]]'			=> 'Diese Art von Datei wird hier nicht angenommen.',
	'[[/feature/files/state/size]]'			=> 'Die Datei ist größer als erlaubt.',
	'[[/feature/files/state/quota]]'		=> 'Der Platz Deiner Gruppe ist aufgebraucht.',
	'[[/feature/files/state/chunk]]'		=> 'Der Upload wurde unterbrochen oder ist nicht vollständig angekommen. Bitte versuche es noch einmal.',
	'[[/feature/files/state/limit]]'		=> 'Zu viele Anfragen. Bitte versuche es in einer Stunde noch einmal.',
	'[[/feature/files/state/write]]'		=> 'Die Datei konnte nicht gespeichert werden. Bitte versuche es später noch einmal.',

	// Der Rest von dem, was das Skript zu einer Datei in seiner Liste sagt
	'[[/feature/files/script/waiting]]'	=> 'Wartet',
	'[[/feature/files/script/sending]]'	=> 'Lädt hoch',
	'[[/feature/files/script/smaller]]'	=> 'Der Server nimmt so große Anfragen nicht an. Die Datei wird in kleineren Teilen noch einmal gesendet.',
	'[[/feature/files/script/floor]]'	=> 'Der Server nimmt auch Anfragen dieser kleinen Größe nicht an. Bitte frage den Betreiber, wie groß eine Anfrage sein darf.',
	'[[/feature/files/script/done]]'		=> 'Hochgeladen',
	'[[/feature/files/script/expired]]'	=> 'Deine Sitzung ist abgelaufen. Bitte lade die Seite neu.',
	'[[/feature/files/script/offline]]'	=> 'Die Verbindung ist fehlgeschlagen. Bitte versuche es noch einmal.',

	// Die Mail an den Betreiber, wenn ein Mitglied eine Datei hochgeladen hat, und die Mail an eine Gruppe, wenn Dateien für sie da sind
	'[[/feature/files/mail/upload-subject]]'	=> 'Ein Mitglied hat eine Datei hochgeladen',
	'[[/feature/files/mail/upload-title]]'		=> 'Eine neue Datei',
	'[[/feature/files/mail/upload-intro]]'		=> 'Ein Mitglied hat eine Datei hochgeladen:',
	'[[/feature/files/mail/upload-button]]'		=> 'Das Panel Dateien öffnen',
	'[[/feature/files/mail/label-folder]]'		=> 'Ordner:',
	'[[/feature/files/mail/label-by]]'				=> 'Hochgeladen von:',
	'[[/feature/files/mail/new-subject]]'			=> 'Neue Dateien für Dich',
	'[[/feature/files/mail/new-title]]'				=> 'Neue Dateien bei',
	'[[/feature/files/mail/new-greeting]]'		=> 'Hallo',
	'[[/feature/files/mail/new-intro]]'				=> 'Diese Dateien liegen für Dich im Ordner',
	'[[/feature/files/mail/new-button]]'			=> 'Zur Website',
	'[[/feature/files/mail/new-notice]]'			=> 'Um sie herunterzuladen, melde Dich mit einem Link an, der an Deine E-Mail-Adresse geschickt wird.',
	'[[/feature/files/mail/new-notice-code]]'	=> 'Zum Anhören melde Dich an: Der Code für die Anmeldung kommt an Deine E-Mail-Adresse.',
	'[[/feature/files/mail/closing]]'					=> 'Viele Grüße',
];

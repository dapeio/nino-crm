<?php
declare(strict_types=1);

// Die eigenen Worte des Panels, eine Datei je Oberflächensprache (siehe
// Admin::text()): in die Fills der Workbench gemischt, solange das Feature
// aktiv ist, nie in die Textdateien des Projekts. Die Worte der Website sind
// der andere Satz, in features/Members/install/text/
return [

	// Der Eintrag in der Navigation, benannt von Admin::nav() - und der Name,
	// den der Kopf über dem Panel zeigt; deshalb zeichnet der Schirm keine
	// eigene Überschrift
	'[[/_admin/members/nav]]'					=> 'Mitglieder',

	// Die Kachel des Dashboards (Admin::summary())
	'[[/_admin/members/label/count]]'			=> 'Mitglieder',

	'[[/_admin/members/hint/intro]]'			=> 'Die Konten des Mitgliederbereichs. Ein Mitglied hat kein Passwort: Es meldet sich mit einem Link an, der an seine E-Mail-Adresse geschickt wird. Welche Seiten Mitgliedern vorbehalten sind und wie lange ein Link funktioniert, stellst Du im Panel Features ein.',

	// Alle abmelden
	'[[/_admin/members/label/signout]]'			=> 'Alle abmelden',
	'[[/_admin/members/confirm/signout]]'		=> 'Alle Mitglieder werden abgemeldet und müssen einen neuen Link anfordern. Fortfahren?',
	'[[/_admin/members/msg/signedout]]'			=> 'Alle sind abgemeldet.',
	'[[/_admin/members/error/signout]]'			=> 'Es konnte niemand abgemeldet werden.',

	// Das Formular für ein neues Mitglied
	'[[/_admin/members/label/new]]'				=> 'Neues Mitglied',
	'[[/_admin/members/label/email]]'			=> 'E-Mail-Adresse',
	'[[/_admin/members/label/name]]'			=> 'Name',
	'[[/_admin/members/label/group]]'			=> 'Gruppe',
	'[[/_admin/members/hint/group]]'			=> 'Ein Wort, zB premium. Leer lassen für keine Gruppe.',
	'[[/_admin/members/label/invite]]'			=> 'Einladung senden',
	'[[/_admin/members/hint/invite]]'			=> 'Das Mitglied bekommt einen Link per E-Mail, um sich zum ersten Mal anzumelden.',
	'[[/_admin/members/label/add]]'				=> 'Mitglied anlegen',
	'[[/_admin/members/msg/added]]'				=> 'Mitglied angelegt.',
	'[[/_admin/members/msg/invited]]'			=> 'Mitglied angelegt, Einladung gesendet.',
	'[[/_admin/members/error/email]]'			=> 'Gib die E-Mail-Adresse des neuen Mitglieds ein.',
	'[[/_admin/members/error/add]]'				=> 'Das Mitglied konnte nicht angelegt werden.',

	// Die Liste
	'[[/_admin/members/label/filter]]'			=> 'Gruppe',
	'[[/_admin/members/filter/all]]'			=> 'Alle Gruppen',
	'[[/_admin/members/filter/none]]'			=> 'Keine Gruppe',
	'[[/_admin/members/empty]]'					=> 'Noch keine Mitglieder.',
	'[[/_admin/members/empty/filter]]'			=> 'Kein Mitglied in dieser Gruppe.',
	'[[/_admin/members/state/active]]'			=> 'Aktiv',
	'[[/_admin/members/state/inactive]]'		=> 'Inaktiv',
	'[[/_admin/members/label/login]]'			=> 'Letzte Anmeldung',
	'[[/_admin/members/label/never]]'			=> 'Noch nie angemeldet',

	// Was mit einem Konto geschieht
	'[[/_admin/members/label/edit]]'			=> 'Bearbeiten',
	'[[/_admin/members/label/save]]'			=> 'Speichern',
	'[[/_admin/members/label/cancel]]'			=> 'Abbrechen',
	'[[/_admin/members/label/active]]'			=> 'Aktiv',
	'[[/_admin/members/hint/active]]'			=> 'Ein Mitglied, das nicht aktiv ist, kann sich nicht anmelden und ist sofort abgemeldet.',
	'[[/_admin/members/confirm/discard]]'		=> 'Was Du eingegeben hast, ist nicht gespeichert. Verwerfen?',
	'[[/_admin/members/error/save]]'			=> 'Die Änderung konnte nicht gespeichert werden.',
	'[[/_admin/members/label/link]]'			=> 'Login-Link senden',
	'[[/_admin/members/msg/sent]]'				=> 'Link gesendet.',
	'[[/_admin/members/label/notify]]'				=> 'Link per E-Mail senden',
	'[[/_admin/members/hint/notify]]'				=> 'Gilt für die Einladung eines neuen Mitglieds und für „Login-Link senden“. Ohne Häkchen wird nichts gesendet; der Link wird Dir einmal angezeigt.',
	'[[/_admin/members/label/the-link]]'				=> 'Link zum Anmelden',
	'[[/_admin/members/hint/link-once]]'				=> 'Dieser Link wird nur jetzt angezeigt und nirgends gespeichert. Wer ihn hat, meldet sich als dieses Mitglied an – gib ihn selbst weiter. Er gilt so lange wie ein gesendeter Link.',
	'[[/_admin/members/label/copy]]'				=> 'Kopieren',
	'[[/_admin/members/label/close-link]]'				=> 'Schließen',
	'[[/_admin/members/msg/copied]]'				=> 'Kopiert.',
	'[[/_admin/members/msg/selected]]'				=> 'Markiert – kopiere ihn mit Strg+C.',
	'[[/_admin/members/msg/invited-quiet]]'				=> 'Mitglied angelegt, Einladung gemacht, ohne E-Mail. Der Link steht oben.',
	'[[/_admin/members/msg/sent-quiet]]'				=> 'Link gemacht, ohne E-Mail. Er steht oben.',
	'[[/_admin/members/error/link]]'			=> 'Der Link konnte nicht gesendet werden.',
	'[[/_admin/members/label/delete]]'			=> 'Löschen',
	'[[/_admin/members/confirm/delete]]'		=> '%s löschen? Das Konto ist dann endgültig weg.',
	'[[/_admin/members/error/delete]]'			=> 'Das Mitglied konnte nicht gelöscht werden.',

	// Was das Panel zu einer Liste sagt, die nicht geladen werden konnte: der
	// Schlüssel, den Nino.adminUi.panel() für ein Laden nimmt, das scheitert
	'[[/_admin/members/error/load]]'			=> 'Die Mitglieder konnten nicht geladen werden.',

	// Die Sätze zu den Codes, mit denen das Panel antwortet (Admin::_failed(), apiAdd(), apiLink())
	'[[/_admin/error/members_invalid]]'			=> 'Das ist keine gültige E-Mail-Adresse.',
	'[[/_admin/error/members_taken]]'			=> 'Ein Mitglied mit dieser Adresse gibt es schon.',
	'[[/_admin/error/members_long]]'			=> 'Der Name darf bis zu %s Zeichen haben.',
	'[[/_admin/error/members_unknown]]'			=> 'Dieses Mitglied gibt es nicht (mehr).',
	'[[/_admin/error/members_inactive]]'		=> 'Einem Mitglied, das nicht aktiv ist, kann kein Link gesendet werden.',
	'[[/_admin/error/members_mail]]'			=> 'Die E-Mail konnte nicht gesendet werden. Prüfe die Mail-Einstellungen und die Adresse der Website und versuche es dann noch einmal.',
	'[[/_admin/error/members_ratelimit]]'		=> 'Die Obergrenze für E-Mails pro Stunde von dieser Adresse aus ist erreicht. Versuche es später noch einmal.',
];

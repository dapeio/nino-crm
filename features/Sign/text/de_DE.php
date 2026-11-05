<?php
declare(strict_types=1);

// Die eigenen Worte des Panels, eine Datei je Oberflächensprache (siehe Admin::text()): in die Fills der Workbench
// gemischt, solange das Feature aktiv ist, nie in die Textdateien des Projekts. Die Worte der Website sind der
// andere Satz, in features/Sign/install/text/
return [

	// Der Eintrag in der Navigation, benannt von Admin::nav() - und der Name, den der Kopf über dem Panel zeigt;
	// deshalb zeichnet der Schirm keine eigene Überschrift
	'[[/_admin/sign/nav]]'						=> 'Unterschriften',

	// Die Kachel des Dashboards (Admin::summary())
	'[[/_admin/sign/label/open]]'				=> 'Warten auf eine Unterschrift',

	// Was der Schirm über sich sagt
	'[[/_admin/sign/hint/intro]]'				=> 'Bitte ein Mitglied, ein Dokument des Features PDF zu unterschreiben. Das Mitglied bekommt einen Link per E-Mail, liest das Dokument und unterschreibt es auf einer Seite der Website; beide PDFs bleiben hier erhalten, mit einem Beleg der Unterzeichnung.',
	'[[/_admin/sign/hint/none]]'				=> 'Es gibt noch keine Vorlage, für die sich eine Unterschrift anfragen lässt: Sie braucht ein Feld für ein Mitglied und eines der Tags der Einstellung Tags der Vorlagen. Lege eine im Panel PDF an.',
	'[[/_admin/sign/hint/members]]'			=> 'Es gibt noch kein aktives Mitglied, das Du fragen könntest. Lege eines im Panel Mitglieder an.',
	'[[/_admin/sign/hint/title]]'				=> 'Bleibt er leer, ist der Name der Vorlage der Titel.',
	'[[/_admin/sign/hint/days]]'				=> 'Der Link ist %s Tage gültig.',

	// Das Formular für eine neue Anfrage
	'[[/_admin/sign/label/new]]'				=> 'Unterschrift anfragen',
	'[[/_admin/sign/label/account]]'			=> 'Mitglied',
	'[[/_admin/sign/label/template]]'			=> 'Vorlage',
	'[[/_admin/sign/label/choose]]'				=> 'Auswählen',
	'[[/_admin/sign/label/title]]'				=> 'Titel',
	'[[/_admin/sign/label/ask]]'				=> 'Unterschrift anfragen',
	'[[/_admin/sign/msg/asked]]'				=> 'Die Anfrage ist gestellt, die Mail ist unterwegs.',
	'[[/_admin/sign/error/account]]'			=> 'Wähle das Mitglied, das Du fragen willst.',
	'[[/_admin/sign/error/template]]'			=> 'Wähle eine Vorlage.',
	'[[/_admin/sign/error/input]]'				=> 'Fülle %s aus.',
	'[[/_admin/sign/error/ask]]'				=> 'Die Anfrage konnte nicht gestellt werden.',

	// Die Liste
	'[[/_admin/sign/empty]]'					=> 'Noch niemand wurde um eine Unterschrift gebeten.',
	'[[/_admin/sign/state/requested]]'			=> 'Wartet',
	'[[/_admin/sign/state/viewed]]'				=> 'Geöffnet',
	'[[/_admin/sign/state/signed]]'				=> 'Unterschrieben',
	'[[/_admin/sign/state/declined]]'			=> 'Abgelehnt',
	'[[/_admin/sign/state/cancelled]]'			=> 'Zurückgezogen',
	'[[/_admin/sign/state/expired]]'			=> 'Abgelaufen',
	'[[/_admin/sign/state/changed]]'			=> 'Geändert',
	'[[/_admin/sign/label/created]]'			=> 'Gefragt',
	'[[/_admin/sign/label/viewed]]'				=> 'Geöffnet',
	'[[/_admin/sign/label/signed]]'				=> 'Unterschrieben',
	'[[/_admin/sign/label/declined]]'			=> 'Abgelehnt',
	'[[/_admin/sign/label/changed]]'			=> 'Geändert',
	'[[/_admin/sign/label/again]]'			=> 'Erneut anfragen',
	'[[/_admin/sign/label/expires]]'			=> 'Link gültig bis',
	'[[/_admin/sign/label/member-gone]]'		=> 'Ein Mitglied, das es nicht mehr gibt',

	// Was eine Zeile tut
	'[[/_admin/sign/label/shown]]'				=> 'Dokument öffnen',
	'[[/_admin/sign/label/signed-document]]'	=> 'Unterschriebenes Dokument öffnen',
	'[[/_admin/sign/label/remind]]'				=> 'Erinnern',
	'[[/_admin/sign/label/cancel]]'				=> 'Zurückziehen',
	'[[/_admin/sign/label/delete]]'				=> 'Löschen',
	'[[/_admin/sign/label/details]]'			=> 'Der Beleg',
	'[[/_admin/sign/label/hide]]'				=> 'Beleg verbergen',
	'[[/_admin/sign/msg/reminded]]'				=> 'Der Link ist noch einmal gesendet, mit neuer Zeit.',
	'[[/_admin/sign/msg/again]]'				=> 'Eine neue Anfrage ist gestellt, die Mail ist unterwegs.',
	'[[/_admin/sign/label/notify]]'				=> 'Link per E-Mail an das Mitglied senden',
	'[[/_admin/sign/hint/notify]]'				=> 'Gilt für das Anfragen, das Erinnern und das erneute Anfragen. Ohne Häkchen wird nichts gesendet; der Link wird Dir einmal angezeigt.',
	'[[/_admin/sign/label/link]]'				=> 'Link zum Unterschreiben',
	'[[/_admin/sign/hint/link-once]]'				=> 'Dieser Link wird nur jetzt angezeigt und nirgends gespeichert. Wer ihn hat, unterschreibt als dieses Mitglied – gib ihn selbst weiter. Er gilt so lange wie ein gesendeter Link; bei einer Erinnerung ist der alte Link tot.',
	'[[/_admin/sign/label/copy]]'				=> 'Kopieren',
	'[[/_admin/sign/label/close-link]]'				=> 'Schließen',
	'[[/_admin/sign/msg/copied]]'				=> 'Kopiert.',
	'[[/_admin/sign/msg/selected]]'				=> 'Markiert – kopiere ihn mit Strg+C.',
	'[[/_admin/sign/msg/asked-quiet]]'				=> 'Die Anfrage ist gestellt, ohne E-Mail. Der Link steht unten.',
	'[[/_admin/sign/msg/reminded-quiet]]'				=> 'Der Link ist erneuert, ohne E-Mail, mit neuer Zeit. Der alte Link ist tot, der neue steht unten.',
	'[[/_admin/sign/msg/again-quiet]]'				=> 'Eine neue Anfrage ist gestellt, ohne E-Mail. Der Link steht unten.',
	'[[/_admin/sign/confirm/cancel]]'			=> 'Die Anfrage an %s zurückziehen? Der Link funktioniert dann nicht mehr.',
	'[[/_admin/sign/confirm/delete]]'			=> 'Die Anfrage an %s mit ihren Dateien löschen? Das lässt sich nicht rückgängig machen.',
	'[[/_admin/sign/error/remind]]'				=> 'Die Erinnerung konnte nicht gesendet werden.',
	'[[/_admin/sign/error/cancel]]'				=> 'Die Anfrage konnte nicht zurückgezogen werden.',
	'[[/_admin/sign/error/delete]]'				=> 'Die Anfrage konnte nicht gelöscht werden.',
	'[[/_admin/sign/error/again]]'			=> 'Die Anfrage konnte nicht noch einmal gestellt werden.',

	// Der Beleg einer Unterzeichnung, in einer Zeile
	'[[/_admin/sign/label/address]]'			=> 'Adresse',
	'[[/_admin/sign/label/agent]]'				=> 'Browser',
	'[[/_admin/sign/label/method]]'				=> 'Unterschrift',
	'[[/_admin/sign/method/drawn]]'				=> 'Auf der Seite gezeichnet',
	'[[/_admin/sign/method/typed]]'				=> 'Getippter Name',
	'[[/_admin/sign/label/hash-shown]]'			=> 'SHA-256 des gezeigten Dokuments',
	'[[/_admin/sign/label/hash-signed]]'		=> 'SHA-256 des unterschriebenen Dokuments',
	'[[/_admin/sign/label/consent]]'			=> 'Zugestimmt',

	// Was das Panel zu einer Liste sagt, die nicht geladen werden konnte: der Schlüssel, den Nino.adminUi.panel() für ein misslungenes Laden nimmt
	'[[/_admin/sign/error/load]]'				=> 'Die Anfragen konnten nicht geladen werden.',

	// Die Sätze der Codes, mit denen das Panel antwortet
	'[[/_admin/error/sign_template]]'			=> 'Es gibt diese Vorlage nicht (mehr).',
	'[[/_admin/error/sign_member]]'				=> 'Diese Vorlage hat kein Feld, auf dem ein Mitglied unterschreiben könnte.',
	'[[/_admin/error/sign_account]]'			=> 'Es gibt dieses Mitglied nicht, oder das Konto ist ausgeschaltet.',
	'[[/_admin/error/sign_input]]'				=> '%s ist leer oder länger als %s Zeichen.',
	'[[/_admin/error/sign_long]]'				=> 'Der Titel darf bis zu %s Zeichen haben.',
	'[[/_admin/error/sign_unknown]]'			=> 'Es gibt diese Anfrage nicht (mehr).',
	'[[/_admin/error/sign_state]]'				=> 'Die Anfrage ist vorbei - sie wurde unterschrieben, abgelehnt, zurückgezogen oder geändert.',
	'[[/_admin/error/sign_mail]]'				=> 'Die Mail konnte nicht gesendet werden. Die Anfrage ist da; drücke Erinnern, um sie noch einmal zu senden.',
	'[[/_admin/error/sign_ratelimit]]'			=> 'Die Obergrenze für Mails pro Stunde ist erreicht. Die Anfrage ist da; drücke später Erinnern.',
];

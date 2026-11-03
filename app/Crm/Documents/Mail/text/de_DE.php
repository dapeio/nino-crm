<?php
declare(strict_types=1);

// Die Worte der Mail, die ein freigegebenes Dokument ankündigt (siehe Mail::text()): einfache Schlüssel, deutsch - die Mail ist ein
// Brief des Inhabers an seinen Kunden, und ein Konto des Features Mitglieder hat keine eigene Sprache. Ein %s ist eine Stelle für einen Wert
return [

	'subject' => 'Neues Dokument für Dich: %s',
	'greeting' => 'Hallo %s,',
	'title' => 'Ein Dokument liegt für Dich bereit',
	'intro' => 'im Kundenportal liegt ein neues Dokument für Dich:',
	'button' => 'Dokumente ansehen',
	'notice' => 'Das Dokument hängt dieser Mail nicht an. Melde Dich im Kundenportal an, dort kannst Du es ansehen und herunterladen.',
	'closing' => 'Viele Grüße',
	'kind.offer' => 'Angebot',
	'kind.invoice' => 'Rechnung',
	'kind.credit' => 'Stornorechnung',
	'kind.contract' => 'Vertrag',
	'kind.privacy' => 'Datenschutz',
	'kind.information' => 'Information',
	'line' => '%s: %s',
	'remind.subject' => 'Zahlungserinnerung: %s',
	'remind.title' => 'Eine Rechnung ist noch offen',
	'remind.intro' => 'für diese Rechnung haben wir noch keine Zahlung gesehen. Fällig war sie am %s:',
	'remind.button' => 'Rechnung ansehen',
	'remind.notice' => 'Die Rechnung hängt dieser Mail nicht an. Melde Dich im Kundenportal an, dort kannst Du sie ansehen und herunterladen. Ist die Zahlung schon unterwegs, kannst Du diese Mail vergessen.',
];

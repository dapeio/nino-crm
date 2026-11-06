<?php
declare(strict_types=1);

// The sentences of the codes the API of the clients answers a refusal with (see Surface::texts(), which hands them to the surface as
// error.<code>), one file per language. The words of the screens are the surface's own, in Surface/text/de_DE.php
return [

	'[[/_admin/error/crm_clients_database]]' => 'Die Datenbank hat die Anfrage nicht angenommen.',
	'[[/_admin/error/crm_clients_email]]' => 'Die E-Mail-Adresse ist nicht gültig.',
	'[[/_admin/error/crm_clients_empty]]' => 'Ein Kunde braucht eine Firma, einen Namen oder die E-Mail-Adresse seines ersten Kontos.',
	'[[/_admin/error/crm_clients_format]]' => 'Der Wert hat nicht das richtige Format.',
	'[[/_admin/error/crm_clients_id]]' => 'Die Nummer des Kunden ist nicht gültig.',
	'[[/_admin/error/crm_clients_inactive]]' => 'Der Kunde ist deaktiviert. Aktiviere ihn, um Konten einzuladen, zu verknüpfen oder einzuschalten.',
	'[[/_admin/error/crm_clients_long]]' => 'Der Wert darf höchstens %s Zeichen haben.',
	'[[/_admin/error/crm_clients_members]]' => 'Das Feature Mitglieder ist nicht aktiv. Schalte es im Panel Features ein.',
	'[[/_admin/error/crm_clients_account]]' => 'Die Kennung des Kontos ist nicht gültig.',
	'[[/_admin/error/crm_clients_held]]' => 'Dieses Konto gehört schon einem Kunden.',
	'[[/_admin/error/crm_clients_mail]]' => 'Die E-Mail konnte nicht gesendet werden. Prüfe die Mail-Einstellungen und die Adresse der Website und versuche es dann noch einmal.',
	'[[/_admin/error/crm_clients_member]]' => 'Dieses Konto gehört nicht (mehr) zu diesem Kunden.',
	'[[/_admin/error/crm_clients_off]]' => 'Das Konto ist ausgeschaltet. Schalte es ein, um ihm einen Link zu senden.',
	'[[/_admin/error/crm_clients_ratelimit]]' => 'Von dieser IP-Adresse aus ist die Grenze für E-Mails pro Stunde erreicht, die der Kern setzt. Versuche es später noch einmal.',
	'[[/_admin/error/crm_clients_write]]' => 'Das Konto konnte nicht geschrieben werden.',
	'[[/_admin/error/crm_clients_taken]]' => 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.',
	'[[/_admin/error/crm_clients_unknown]]' => 'Diesen Kunden gibt es nicht.',
	'[[/_admin/error/crm_clients_link_perm]]' => 'Einen Link ohne Mail darf nur sehen, wer das Panel Mitglieder verwalten darf. Sende die Mail oder bitte jemanden mit dieser Berechtigung.',
	'[[/_admin/error/crm_clients_link]]' => 'Der Link konnte nicht gemacht werden. Das Konto ist da; frage den Link über das Menü des Kontos noch einmal an.',
];

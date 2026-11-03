<?php
declare(strict_types=1);

// Die eigenen Worte des Panels Positionen, eine Datei je Oberflächensprache (siehe Admin::text()): in die Fills der Workbench
// gemischt, solange das Modul aktiv ist, nie in die Textdateien des Projekts.
return [

	'[[/_admin/crm-items/nav]]' => 'Positionen',
	'[[/_admin/crm-items/label/search]]' => 'Suche',
	'[[/_admin/crm-items/label/find]]' => 'Suchen',
	'[[/_admin/crm-items/label/new]]' => 'Neue Position',
	'[[/_admin/crm-items/label/back]]' => 'Zurück zur Liste',
	'[[/_admin/crm-items/label/save]]' => 'Speichern',
	'[[/_admin/crm-items/label/deactivate]]' => 'Deaktivieren',
	'[[/_admin/crm-items/label/activate]]' => 'Aktivieren',
	'[[/_admin/crm-items/label/up]]' => 'Nach oben',
	'[[/_admin/crm-items/label/down]]' => 'Nach unten',
	'[[/_admin/crm-items/field/title]]' => 'Bezeichnung',
	'[[/_admin/crm-items/field/description]]' => 'Beschreibung',
	'[[/_admin/crm-items/field/unit]]' => 'Einheit',
	'[[/_admin/crm-items/field/price]]' => 'Nettopreis',
	'[[/_admin/crm-items/field/vat-rate]]' => 'Umsatzsteuer',
	'[[/_admin/crm-items/empty/items]]' => 'Es gibt noch keine Positionen. Lege die erste an.',
	'[[/_admin/crm-items/empty/found]]' => 'Keine Position passt zu Deiner Suche.',
	'[[/_admin/crm-items/state/inactive]]' => 'inaktiv',
	'[[/_admin/crm-items/error/load]]' => 'Die Positionen konnten nicht geladen werden.',
	'[[/_admin/crm-items/error/save]]' => 'Die Position konnte nicht gespeichert werden.',
	'[[/_admin/crm-items/error/active]]' => 'Die Position konnte nicht umgeschaltet werden.',
	'[[/_admin/crm-items/error/order]]' => 'Die Reihenfolge konnte nicht gespeichert werden.',

	'[[/_admin/error/crm_items_database]]' => 'Die Datenbank hat die Anfrage nicht angenommen.',
	'[[/_admin/error/crm_items_amount]]' => 'Der Preis ist keine Zahl, zum Beispiel 1.234,56.',
	'[[/_admin/error/crm_items_empty]]' => 'Eine Position braucht eine Bezeichnung.',
	'[[/_admin/error/crm_items_format]]' => 'Der Wert ist kein Text.',
	'[[/_admin/error/crm_items_id]]' => 'Die Nummer der Position ist nicht gültig.',
	'[[/_admin/error/crm_items_long]]' => 'Der Wert darf höchstens %s Zeichen haben.',
	'[[/_admin/error/crm_items_rate]]' => 'Der Steuersatz gehört nicht zu den Sätzen in den Einstellungen.',
	'[[/_admin/error/crm_items_unknown]]' => 'Diese Position gibt es nicht.',
];

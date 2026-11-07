<?php
declare(strict_types=1);

// Die Worte der Portalseiten - bei der Einrichtung add-only in die text/de_DE.php des Projekts gemischt; ab dann
// pflegen sie Redakteure im Panel Texte
return [

	// Die Angaben der Seiten: Name, Titel und Beschreibung, die der Kopf jeder Seite liest
	'[[/_nino/webpage/portal/name]]' => 'Portal',
	'[[/_nino/webpage/portal/title]]' => 'Kundenportal',
	'[[/_nino/webpage/portal/description]]' => 'Deine Projekte, Belege, Termine und Daten an einem Ort.',
	'[[/_nino/webpage/portal/meine-daten/name]]' => 'Meine Daten',
	'[[/_nino/webpage/portal/meine-daten/title]]' => 'Meine Daten',
	'[[/_nino/webpage/portal/meine-daten/description]]' => 'Deine Angaben als Kunde. Sie stehen auf Deinen nächsten Belegen; Belege, die schon fertig sind, ändern sich nicht mehr.',
	'[[/_nino/webpage/portal/projekte/name]]' => 'Projekte',
	'[[/_nino/webpage/portal/projekte/title]]' => 'Deine Projekte',
	'[[/_nino/webpage/portal/projekte/description]]' => 'Woran wir für Dich arbeiten: das Angebot, die Dateien zum Anhören und Laden und die Belege eines Projekts.',
	'[[/_nino/webpage/portal/upload/name]]' => 'Upload',
	'[[/_nino/webpage/portal/upload/title]]' => 'Dateien hochladen',
	'[[/_nino/webpage/portal/upload/description]]' => 'Was Du hier hochlädst, kommt bei uns für alle Deine Projekte gemeinsam an – schreib uns kurz, wozu es gehört.',
	'[[/_nino/webpage/portal/belege/name]]' => 'Belege',
	'[[/_nino/webpage/portal/belege/title]]' => 'Deine Belege',
	'[[/_nino/webpage/portal/belege/description]]' => 'Alles, was für Dich freigegeben ist, mit Betrag und Stand: Angebote, Rechnungen, Verträge und Datenschutzdokumente. Was Deine Unterschrift braucht, unterschreibst Du hier.',
	'[[/_nino/webpage/portal/termine/name]]' => 'Termine',
	'[[/_nino/webpage/portal/termine/title]]' => 'Deine Termine',
	'[[/_nino/webpage/portal/termine/description]]' => 'Die Termine, die für Dich anstehen – mit dem Abo für Deinen Kalender.',
	'[[/_nino/webpage/portal/datenschutz/name]]' => 'Datenschutzerklärung',
	'[[/template/page-portal-privacy/head/name]]' => 'Datenschutzerklärung',
	'[[/_nino/webpage/portal/datenschutz/title]]' => 'Datenschutzerklärung',
	'[[/template/page-portal-privacy/head/title]]' => 'Datenschutzerklärung',
	'[[/_nino/webpage/portal/datenschutz/description]]' => 'Die Datenschutzerklärung des Kundenportals. Wir halten fest, wann Du sie zur Kenntnis genommen hast; ändert sich der Text, fragen wir noch einmal.',
	'[[/template/page-portal-privacy/head/description]]' => 'Die Datenschutzerklärung des Kundenportals. Wir halten fest, wann Du sie zur Kenntnis genommen hast; ändert sich der Text, fragen wir noch einmal.',

	// Die Angaben der Startseite, des Tors (der Element-URI ist '/', wie ihn der Assistent für die Startseite schreibt): der Kopf liest sie
	'[[/_nino/webpage//name]]' => 'Kundenportal',
	'[[/_nino/webpage//title]]' => 'Kundenportal',
	'[[/_nino/webpage//description]]' => 'Dein Zugang kommt von uns per E-Mail – melde Dich mit dem Code an, den wir Dir schicken.',

	// Das Tor des Portals - die Startseite der Website, page-door.tpl
	'[[/template/page-door/intro/title]]'               => 'Kundenportal',
	'[[/template/page-door/intro/lead]]'                => 'Dein Zugang kommt von uns per E-Mail. Hast Du keinen, schreib uns. Gib hier die E-Mail-Adresse Deines Kontos ein – wir schicken Dir einen Code, mit dem Du Dich ohne Passwort anmeldest.',

	// Die Worte des Elemente-Panels für den Typ Positionen - die der Workbench, nicht der Website (in der Blacklist der Einheit)
	'[[/_admin/elements/field/positionen/title]]' => 'Titel',
	'[[/_admin/elements/field/positionen/description]]' => 'Beschreibung',
	'[[/_admin/elements/field/positionen/unit]]' => 'Einheit',
	'[[/_admin/elements/field/positionen/unit_code]]' => 'Einheit der E-Rechnung',
	'[[/_admin/elements/field/positionen/price]]' => 'Preis (netto)',
	'[[/_admin/elements/field/positionen/vat]]' => 'Steuersatz',
	'[[/_admin/elements/field/positionen/hidden]]' => 'Ausgeblendet',
	'[[/_admin/elements/field/positionen/sort]]' => 'Reihenfolge',
	'[[/_admin/elements/type/positionen/hint]]' => 'Was Du verkaufst: Die Texte gelten je Sprache, Preis und Steuersatz für alle. Die Einheit der E-Rechnung ist eines der Kürzel H87 Stück, HUR Stunde, MIN Minute, DAY Tag, WEE Woche, MON Monat, LS Pauschale, C62 Einheit, KMT Kilometer. Ausgeblendet nimmt eine Position aus dem Editor der Belege und von der Seite; steht Ausgeblendet auf Nein, ist sie angeboten.',

	// The Elements panel's own words for the type Projekte - the workbench's, not the site's (listed in the unit's blacklist)
	'[[/_admin/elements/field/projekte/title]]' => 'Titel',
	'[[/_admin/elements/field/projekte/client]]' => 'Kunde',
	'[[/_admin/elements/field/projekte/description]]' => 'Beschreibung',
	'[[/_admin/elements/field/projekte/started]]' => 'Beginn',
	'[[/_admin/elements/field/projekte/done]]' => 'Abgeschlossen',
	'[[/_admin/elements/field/projekte/folder]]' => 'Ordner',
	'[[/_admin/elements/type/projekte/hint]]' => 'Ein Projekt gehört zu einem Kunden. Der Kunde ist seine Nummer (K-0001), der Ordner die Kennung seines Ordners im Panel Dateien. Abgeschlossen auf Nein heißt: das Projekt läuft. Belege nennen ein Projekt; solange das so ist, lässt es sich nicht löschen.',
];

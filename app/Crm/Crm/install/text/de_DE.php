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
	'[[/_nino/webpage/portal/meine-daten/description]]' => 'Deine Angaben als Kunde.',
	'[[/_nino/webpage/portal/projekte/name]]' => 'Projekte',
	'[[/_nino/webpage/portal/projekte/title]]' => 'Deine Projekte',
	'[[/_nino/webpage/portal/projekte/description]]' => 'Deine Projekte mit Angebot, Dateien und Belegen.',
	'[[/_nino/webpage/portal/upload/name]]' => 'Upload',
	'[[/_nino/webpage/portal/upload/title]]' => 'Dateien hochladen',
	'[[/_nino/webpage/portal/upload/description]]' => 'Lade Dateien für Deine Projekte hoch.',
	'[[/_nino/webpage/portal/belege/name]]' => 'Belege',
	'[[/_nino/webpage/portal/belege/title]]' => 'Deine Belege',
	'[[/_nino/webpage/portal/belege/description]]' => 'Angebote, Rechnungen, Verträge und mehr, die für Dich freigegeben sind.',
	'[[/_nino/webpage/portal/termine/name]]' => 'Termine',
	'[[/_nino/webpage/portal/termine/title]]' => 'Deine Termine',
	'[[/_nino/webpage/portal/termine/description]]' => 'Die Termine, die für Dich anstehen.',

	// Die Angaben der Startseite, des Tors (der Element-URI ist '/', wie ihn der Assistent für die Startseite schreibt): der Kopf liest sie
	'[[/_nino/webpage//name]]' => 'Anmelden',
	'[[/_nino/webpage//title]]' => 'Anmelden',
	'[[/_nino/webpage//description]]' => 'Melde Dich mit dem Link an, den wir Dir per E-Mail schicken.',

	// Start, page-portal.tpl
	'[[/template/page-portal/intro/title]]' => 'Willkommen im Kundenportal',
	'[[/template/page-portal/intro/text]]' => 'Hier findest Du Deine Projekte, Belege und Termine und kannst Dateien hochladen.',
	'[[/template/page-portal/link-projekte/label]]' => 'Projekte',
	'[[/template/page-portal/link-projekte/href]]' => '/portal/projekte',
	'[[/template/page-portal/link-upload/label]]' => 'Upload',
	'[[/template/page-portal/link-upload/href]]' => '/portal/upload',
	'[[/template/page-portal/link-belege/label]]' => 'Belege',
	'[[/template/page-portal/link-belege/href]]' => '/portal/belege',
	'[[/template/page-portal/link-termine/label]]' => 'Termine',
	'[[/template/page-portal/link-termine/href]]' => '/portal/termine',
	'[[/template/page-portal/link-meine-daten/label]]' => 'Meine Daten',
	'[[/template/page-portal/link-meine-daten/href]]' => '/portal/meine-daten',

	// Meine Daten, page-portal-meine-daten.tpl
	'[[/template/page-portal-meine-daten/intro/title]]' => 'Meine Daten',
	'[[/template/page-portal-meine-daten/intro/text]]' => 'Das sind Deine Angaben als Kunde. Sie stehen auf Deinen nächsten Belegen; Belege, die schon fertig sind, ändern sich nicht mehr.',

	// Projekte, page-portal-projekte.tpl
	'[[/template/page-portal-projekte/intro/title]]' => 'Deine Projekte',
	'[[/template/page-portal-projekte/intro/text]]' => 'Hier siehst Du, woran wir für Dich arbeiten: das Angebot, die Dateien zum Anhören und Laden und die Belege eines Projekts.',

	// Upload, page-portal-upload.tpl
	'[[/template/page-portal-upload/intro/title]]' => 'Dateien hochladen',
	'[[/template/page-portal-upload/intro/text]]' => 'Lade hier Dateien für uns hoch.',

	// Belege, page-portal-belege.tpl
	'[[/template/page-portal-belege/intro/title]]' => 'Deine Belege',
	'[[/template/page-portal-belege/intro/text]]' => 'Alles, was für Dich freigegeben ist, mit seinem Stand: Angebote, Rechnungen, Verträge und Datenschutzdokumente. Was Deine Unterschrift braucht, unterschreibst Du hier.',

	// Termine, page-portal-termine.tpl
	'[[/template/page-portal-termine/intro/title]]' => 'Deine Termine',
	'[[/template/page-portal-termine/intro/text]]' => 'Die Termine, die für Dich anstehen.',

	// Das Tor des Portals - die Startseite der Website, page-door.tpl
	'[[/template/page-door/intro/title]]'               => 'Anmelden',
	'[[/template/page-door/intro/text]]'                => 'Gib die E-Mail-Adresse Deines Kontos ein. Wir schicken Dir einen Link per E-Mail - damit meldest Du Dich an, ganz ohne Passwort.',

	// Die Worte des Elemente-Panels für den Typ Positionen - die der Workbench, nicht der Website (in der Blacklist der Einheit)
	'[[/_admin/elements/field/positionen/title]]' => 'Titel',
	'[[/_admin/elements/field/positionen/description]]' => 'Beschreibung',
	'[[/_admin/elements/field/positionen/unit]]' => 'Einheit',
	'[[/_admin/elements/field/positionen/price]]' => 'Preis (netto)',
	'[[/_admin/elements/field/positionen/vat]]' => 'Steuersatz',
	'[[/_admin/elements/field/positionen/hidden]]' => 'Ausgeblendet',
	'[[/_admin/elements/field/positionen/sort]]' => 'Reihenfolge',
	'[[/_admin/elements/type/positionen/hint]]' => 'Was Du verkaufst: Die Texte gelten je Sprache, Preis und Steuersatz für alle. Ausgeblendet nimmt eine Position aus dem Editor der Belege und von der Seite; steht Ausgeblendet auf Nein, ist sie angeboten.',

	// The Elements panel's own words for the type Projekte - the workbench's, not the site's (listed in the unit's blacklist)
	'[[/_admin/elements/field/projekte/title]]' => 'Titel',
	'[[/_admin/elements/field/projekte/client]]' => 'Kunde',
	'[[/_admin/elements/field/projekte/description]]' => 'Beschreibung',
	'[[/_admin/elements/field/projekte/started]]' => 'Beginn',
	'[[/_admin/elements/field/projekte/done]]' => 'Abgeschlossen',
	'[[/_admin/elements/field/projekte/folder]]' => 'Ordner',
	'[[/_admin/elements/type/projekte/hint]]' => 'Ein Projekt gehört zu einem Kunden. Der Kunde ist seine Nummer (K-0001), der Ordner die Kennung seines Ordners im Panel Dateien. Abgeschlossen auf Nein heißt: das Projekt läuft. Belege nennen ein Projekt; solange das so ist, lässt es sich nicht löschen.',
];

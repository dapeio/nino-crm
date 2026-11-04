<?php
declare(strict_types=1);

// Die Worte der Portalseiten - bei der Einrichtung add-only in die text/de_DE.php des Projekts gemischt; ab dann
// pflegen sie Redakteure im Panel Texte
return [

	// Die Angaben der Seiten: Name, Titel und Beschreibung, die der Kopf jeder Seite liest
	'[[/_nino/webpage/portal/name]]' => 'Portal',
	'[[/_nino/webpage/portal/title]]' => 'Kundenportal',
	'[[/_nino/webpage/portal/description]]' => 'Deine Dateien, Aufnahmen und Unterschriften an einem Ort.',
	'[[/_nino/webpage/portal/dateien/name]]' => 'Dateien',
	'[[/_nino/webpage/portal/dateien/title]]' => 'Deine Dateien',
	'[[/_nino/webpage/portal/dateien/description]]' => 'Die Dateien, die für Dich freigegeben sind.',
	'[[/_nino/webpage/portal/review/name]]' => 'Aufnahmen',
	'[[/_nino/webpage/portal/review/title]]' => 'Aufnahmen anhören',
	'[[/_nino/webpage/portal/review/description]]' => 'Die Aufnahmen, die für Dich bereitliegen.',
	'[[/_nino/webpage/portal/unterschriften/name]]' => 'Unterschriften',
	'[[/_nino/webpage/portal/unterschriften/title]]' => 'Unterschriften',
	'[[/_nino/webpage/portal/unterschriften/description]]' => 'Die Dokumente, die auf Deine Unterschrift warten.',
	'[[/_nino/webpage/portal/dokumente/name]]' => 'Dokumente',
	'[[/_nino/webpage/portal/dokumente/title]]' => 'Deine Dokumente',
	'[[/_nino/webpage/portal/dokumente/description]]' => 'Die Dokumente, die für Dich freigegeben sind: Angebote, Rechnungen, Verträge und mehr.',
	'[[/_nino/webpage/portal/konto/name]]' => 'Mein Konto',
	'[[/_nino/webpage/portal/konto/title]]' => 'Mein Konto',
	'[[/_nino/webpage/portal/konto/description]]' => 'Dein Konto im Kundenportal.',
	// Die Angaben der Startseite, des Tors (der Element-URI ist '/', wie ihn der Assistent für die Startseite schreibt): der Kopf liest sie
	'[[/_nino/webpage//name]]' => 'Anmelden',
	'[[/_nino/webpage//title]]' => 'Anmelden',
	'[[/_nino/webpage//description]]' => 'Melde Dich mit dem Link an, den wir Dir per E-Mail schicken.',

	// Die Startseite des Portals, page-portal.tpl
	'[[/template/page-portal/intro/title]]'              => 'Willkommen im Kundenportal',
	'[[/template/page-portal/intro/text]]'               => 'Hier findest Du Deine Dateien, Aufnahmen und Unterschriften.',
	'[[/template/page-portal/link-files/label]]'         => 'Dateien',
	'[[/template/page-portal/link-files/href]]'          => '/portal/dateien',
	'[[/template/page-portal/link-review/label]]'        => 'Aufnahmen anhören',
	'[[/template/page-portal/link-review/href]]'         => '/portal/review',
	'[[/template/page-portal/link-documents/label]]'      => 'Dokumente',
	'[[/template/page-portal/link-documents/href]]'       => '/portal/dokumente',
	'[[/template/page-portal/link-sign/label]]'          => 'Unterschriften',
	'[[/template/page-portal/link-sign/href]]'           => '/portal/unterschriften',
	'[[/template/page-portal/link-account/label]]'       => 'Mein Konto',
	'[[/template/page-portal/link-account/href]]'        => '/portal/konto',

	// Dateien, page-portal-files.tpl
	'[[/template/page-portal-files/intro/title]]'        => 'Dateien',
	'[[/template/page-portal-files/intro/text]]'         => 'Die Dateien, die für Dich freigegeben sind. Hochladen kannst Du, wenn für Deine Gruppe ein Upload-Ordner da ist.',
	'[[/template/page-portal-files/back/label]]'         => 'Zurück zum Portal',
	'[[/template/page-portal-files/back/href]]'          => '/portal',

	// Review, page-portal-review.tpl
	'[[/template/page-portal-review/intro/title]]'       => 'Aufnahmen anhören',
	'[[/template/page-portal-review/intro/text]]'        => 'Hör Dir die Aufnahmen an, die für Dich bereitliegen.',
	'[[/template/page-portal-review/back/label]]'        => 'Zurück zum Portal',
	'[[/template/page-portal-review/back/href]]'         => '/portal',

	// Unterschriften, page-portal-sign.tpl
	'[[/template/page-portal-sign/intro/title]]'         => 'Unterschriften',
	'[[/template/page-portal-sign/intro/text]]'          => 'Die Dokumente, die auf Deine Unterschrift warten.',
	'[[/template/page-portal-sign/back/label]]'          => 'Zurück zum Portal',
	'[[/template/page-portal-sign/back/href]]'           => '/portal',

	// Dokumente, page-portal-documents.tpl
	'[[/template/page-portal-documents/intro/title]]' => 'Deine Dokumente',
	'[[/template/page-portal-documents/intro/text]]' => 'Alles, was für Dich freigegeben ist, mit seinem Stand: Angebote, Rechnungen, Verträge und Datenschutzdokumente. Verträge und Datenschutzdokumente, die Deine Unterschrift brauchen, unterschreibst Du hier.',
	'[[/template/page-portal-documents/back/label]]' => 'Zurück zum Portal',
	'[[/template/page-portal-documents/back/href]]' => '/portal',

	// Konto, page-portal-account.tpl
	'[[/template/page-portal-account/intro/title]]'      => 'Mein Konto',
	'[[/template/page-portal-account/intro/text]]'       => 'Du bist angemeldet als:',
	'[[/template/page-portal-account/back/label]]'       => 'Zurück zum Portal',
	'[[/template/page-portal-account/back/href]]'        => '/portal',

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

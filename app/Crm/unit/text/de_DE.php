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
	'[[/_nino/webpage/portal/konto/name]]' => 'Mein Konto',
	'[[/_nino/webpage/portal/konto/title]]' => 'Mein Konto',
	'[[/_nino/webpage/portal/konto/description]]' => 'Dein Konto im Kundenportal.',
	'[[/_nino/webpage/login/name]]' => 'Anmelden',
	'[[/_nino/webpage/login/title]]' => 'Anmelden',
	'[[/_nino/webpage/login/description]]' => 'Melde Dich mit dem Link an, den wir Dir per E-Mail schicken.',

	// Die Startseite des Portals, page-portal.tpl
	'[[/template/page-portal/intro/title]]'              => 'Willkommen im Kundenportal',
	'[[/template/page-portal/intro/text]]'               => 'Hier findest Du Deine Dateien, Aufnahmen und Unterschriften.',
	'[[/template/page-portal/link-files/label]]'         => 'Dateien',
	'[[/template/page-portal/link-files/href]]'          => '/portal/dateien',
	'[[/template/page-portal/link-review/label]]'        => 'Aufnahmen anhören',
	'[[/template/page-portal/link-review/href]]'         => '/portal/review',
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

	// Konto, page-portal-account.tpl
	'[[/template/page-portal-account/intro/title]]'      => 'Mein Konto',
	'[[/template/page-portal-account/intro/text]]'       => 'Du bist angemeldet als:',
	'[[/template/page-portal-account/back/label]]'       => 'Zurück zum Portal',
	'[[/template/page-portal-account/back/href]]'        => '/portal',

	// Die Anmeldeseite, page-login.tpl
	'[[/template/page-login/intro/title]]'               => 'Anmelden',
	'[[/template/page-login/intro/text]]'                => 'Gib die E-Mail-Adresse Deines Kontos ein. Wir schicken Dir einen Link per E-Mail - damit meldest Du Dich an, ganz ohne Passwort.',
];

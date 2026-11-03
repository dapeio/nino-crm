<?php
declare(strict_types=1);

// Die Worte der Komponenten und des Feeds - bei jeder Aktivierung in die text/de_DE.php des Projekts gemischt
// (nur Hinzufügen: ein Schlüssel, den das Projekt schon hat, bleibt, einer, der fehlt, kommt dazu); danach pflegen
// die Redakteure sie im Panel Texte. Monat und Wochentag eines Termins sind keine Worte des Features: sie kommen
// aus dem Locale (siehe Events::pattern())
return [

	'[[/feature/events/when/today]]'		=> 'Heute',
	'[[/feature/events/when/tomorrow]]'	=> 'Morgen',
	'[[/feature/events/when/until]]'		=> 'bis',

	'[[/feature/events/ics/label]]'			=> 'Zum Kalender hinzufügen',
	'[[/feature/events/feed/label]]'		=> 'Kalender abonnieren',
	'[[/feature/events/feed/member]]'		=> 'Deinen Kalender abonnieren',

	'[[/feature/events/feed/name]]'			=> 'Termine',

	'[[/feature/events/state/upcoming]]'	=> 'Es stehen keine Termine an.',
	'[[/feature/events/state/past]]'			=> 'Es hat noch nichts stattgefunden.',
	'[[/feature/events/state/all]]'			=> 'Es gibt keine Termine.',
];

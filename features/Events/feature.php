<?php
// The feature manifest - what the Features panel reads to list, activate and update this feature (see \Nino\Features
// and docs/features.md, "The manifest"). The class is not declared here: features/Events/ can only ever serve
// \Nino\Modules\Events.
return [
	'key'					=> 'events',
	'name'				=> [ 'en_US' => 'Events', 'de_DE' => 'Termine' ],
	'description'	=> [
		'en_US' => 'Dates as elements of the project: a stack for the Builder that lists what is coming, a chip that writes a date in the language of the page, a link that adds one to a calendar app and a feed of the public dates - for everyone or for a group of Members. Dates are shown, never booked.',
		'de_DE' => 'Termine als Elemente des Projekts: ein Stapel für den Builder, der zeigt, was ansteht, ein Chip, der ein Datum in der Sprache der Seite schreibt, ein Link, der einen Termin in eine Kalender-App bringt, und ein Feed der öffentlichen Termine - für alle oder für eine Gruppe von Mitgliedern. Termine werden angezeigt, nie gebucht.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[events]' => [
				'en_US' => 'The dates that are coming, soonest first, one cell each in a grid - what a cell holds are the components the Builder puts in it: [title title], [event-when start end="end"], [text place], [event-ics .slug]. A date that has begun and not ended is still coming; a day-long date (a midnight start, no end) is over when its day ends, and one with a time and no end is over when it begins. Nothing is listed past the setting ahead. A date with a group is listed for the members of that group only, and for nobody where the Members feature is not active. Where there is nothing to list, one line says so.',
				'de_DE' => 'Die Termine, die anstehen, der nächste zuerst, je eine Zelle in einem Raster - was in einer Zelle steht, sind die Komponenten, die der Builder hineinsetzt: [title title], [event-when start end="end"], [text place], [event-ics .slug]. Ein Termin, der begonnen und nicht geendet hat, steht noch an; ein ganztägiger Termin (Beginn um Mitternacht, kein Ende) ist vorbei, wenn sein Tag endet, einer mit Uhrzeit und ohne Ende, wenn er beginnt. Weiter als die Einstellung ahead wird nichts gelistet. Ein Termin mit Gruppe wird nur den Mitgliedern dieser Gruppe gelistet und niemandem, wo das Feature Mitglieder nicht aktiv ist. Wo nichts zu listen ist, sagt es eine Zeile.',
			],
			'[events scope="past"]' => [
				'en_US' => 'scope is upcoming (the default), past or all. Past are the dates that are over, as far back as the setting past says (0 is all of them); all is both, from there to the setting ahead.',
				'de_DE' => 'scope ist upcoming (Standard), past oder all. Vergangen sind die Termine, die vorbei sind, so weit zurück, wie die Einstellung past sagt (0 ist alle); all ist beides, von dort bis zur Einstellung ahead.',
			],
			'[events order="desc"]' => [
				'en_US' => 'The dates are ordered by their start. asc (the default) starts with the date nearest to now: the soonest of the dates that are coming and the latest of the past ones. desc turns it around. For all, asc is the earliest first.',
				'de_DE' => 'Die Termine sind nach ihrem Beginn geordnet. asc (Standard) beginnt mit dem Termin, der Jetzt am nächsten liegt: dem nächsten der anstehenden und dem spätesten der vergangenen. desc dreht es um. Bei all ist asc der früheste zuerst.',
			],
			'[events limit="3" cols="100 50 33" gap="2" id="next" class="my-class"]' => [
				'en_US' => 'The loop and the grid of every stack: limit is the number of dates (0 is all of them) and counts the dates in the scope that this visitor sees, offset skips some, query narrows by a field, cols, gap and autoheight are the cells, id and class are the stack\'s. sort and callback are not read: the order is the start.',
				'de_DE' => 'Die Schleife und das Raster jedes Stapels: limit ist die Zahl der Termine (0 sind alle) und zählt die Termine im Scope, die dieser Besucher sieht, offset überspringt einige, query grenzt über ein Feld ein, cols, gap und autoheight sind die Zellen, id und class die des Stapels. sort und callback werden nicht gelesen: Die Reihenfolge ist der Beginn.',
			],
			'[elements /events]' => [
				'en_US' => 'Another loop over /events - [elements], [list], [slider], [filter] - shows every date, the dates of a group too: it knows nothing of groups. Only [events] keeps them for their members.',
				'de_DE' => 'Eine andere Schleife über /events - [elements], [list], [slider], [filter] - zeigt jeden Termin, auch die einer Gruppe: Sie weiß nichts von Gruppen. Nur [events] hält sie für ihre Mitglieder zurück.',
			],
			'[event-when start end="end"]' => [
				'en_US' => 'In a cell, the start of the date and, with end naming the field that holds the end, its range: a <time> that writes the day and the time in the language of the page (Today and Tomorrow in words), "14:00-16:00" when the end is on the same day and both days when it is not. A date at midnight with no end is a day and has no time. The datetime attribute is the start, 2026-12-24T18:00. The text is written by the intl extension where it is loaded - the locale\'s own medium date and short time, with its month names - and by a fixed pattern of the language where it is not: 24.12.2026 and 18:00 in German, 12/24/2026 and 6:00 PM in en_US, 24/12/2026 and 18:00 in other English, 2026-12-24 and 18:00 elsewhere, with no names of months.',
				'de_DE' => 'In einer Zelle der Beginn des Termins und, wenn end das Feld mit dem Ende nennt, seine Spanne: ein <time>, das den Tag und die Uhrzeit in der Sprache der Seite schreibt (Heute und Morgen in Worten), "14:00-16:00", wenn das Ende am selben Tag liegt, und beide Tage, wenn nicht. Ein Termin um Mitternacht ohne Ende ist ein Tag und hat keine Uhrzeit. Das Attribut datetime ist der Beginn, 2026-12-24T18:00. Den Text schreibt die Erweiterung intl, wo sie geladen ist - das mittlere Datum und die kurze Uhrzeit des Locales, mit seinen Monatsnamen -, und ein festes Muster der Sprache, wo nicht: 24.12.2026 und 18:00 auf Deutsch, 12/24/2026 und 6:00 PM in en_US, 24/12/2026 und 18:00 im übrigen Englisch, 2026-12-24 und 18:00 sonst, ohne Monatsnamen.',
			],
			'[event-when start format="date"]' => [
				'en_US' => 'format is datetime (the default), date (the day alone) or time (the time alone). The classes events-when--today (the date takes place today) and events-when--past (it is over) are on the chip whatever the format.',
				'de_DE' => 'format ist datetime (Standard), date (nur der Tag) oder time (nur die Uhrzeit). Die Klassen events-when--today (der Termin ist heute) und events-when--past (er ist vorbei) stehen am Chip, welches Format auch gilt.',
			],
			'[event-ics .slug]' => [
				'en_US' => 'A link that adds one date to a calendar app: /.events/<slug>.ics, with the word "Add to calendar". The source is the name of the element in a cell (.slug, or .uri), or text="summer-party" anywhere. Nothing for a date the visitor may not see, for one that does not exist and for a name that is not made of lower-case letters, digits and hyphens.',
				'de_DE' => 'Ein Link, der einen Termin in eine Kalender-App bringt: /.events/<slug>.ics, mit dem Wort "Zum Kalender hinzufügen". Die Quelle ist der Name des Elements in einer Zelle (.slug oder .uri) oder text="sommerfest" an beliebiger Stelle. Nichts für einen Termin, den der Besucher nicht sehen darf, für einen, den es nicht gibt, und für einen Namen, der nicht aus Kleinbuchstaben, Ziffern und Bindestrichen besteht.',
			],
			'[event-feed]' => [
				'en_US' => 'A link to the feed. A visitor gets the public feed, /.events.ics. A member who is signed in gets a link with their token, /.events.ics?k=..., which adds the dates of their group: the token is made when the link is first drawn and is the same every time after, so that a calendar app can be subscribed to it. It lives as long as the account does - an account that is deleted or switched off has the public feed - and deleting /data/events.php ends every link. Nothing while the setting feed is off.',
				'de_DE' => 'Ein Link zum Feed. Ein Besucher bekommt den öffentlichen Feed, /.events.ics. Ein angemeldetes Mitglied bekommt einen Link mit seinem Token, /.events.ics?k=..., der die Termine seiner Gruppe hinzufügt: Der Token wird beim ersten Zeichnen des Links gemacht und ist danach jedes Mal derselbe, damit eine Kalender-App ihn abonnieren kann. Er lebt so lange wie das Konto - ein Konto, das gelöscht oder abgeschaltet ist, bekommt den öffentlichen Feed - und das Löschen von /data/events.php beendet jeden Link. Nichts, solange die Einstellung feed aus ist.',
			],
			'[event-when ... class="my-class"]' => [
				'en_US' => 'A class of your own, added to those of the chip - [event-ics] and [event-feed] take it too. What the Builder writes for its custom classes.',
				'de_DE' => 'Eine eigene Klasse, zusätzlich zu denen des Chips - [event-ics] und [event-feed] nehmen sie auch. Das schreibt der Builder für seine eigenen Klassen.',
			],
		],
		'markup' => [
			'<time class="events-when">' => [
				'en_US' => 'The chip, with events-when--today and events-when--past, a datetime attribute and the text. The link of [event-ics] is a.events-ics, the one of [event-feed] a.events-feed, the line a stack writes for nothing to list p.events-empty.',
				'de_DE' => 'Der Chip, mit events-when--today und events-when--past, einem Attribut datetime und dem Text. Der Link von [event-ics] ist a.events-ics, der von [event-feed] a.events-feed, die Zeile, die ein Stapel für nichts zu Listendes schreibt, p.events-empty.',
			],
			'Custom properties' => [
				'en_US' => 'events.css declares the properties the chip and the links are drawn from - --events-ground with its parts (-on, -link, -border, -focus), --events-space and --events-corners - in :where(:root), with the tokens of Nino.css. The Design feature moves them.',
				'de_DE' => 'events.css deklariert die Properties, aus denen Chip und Links gezeichnet werden - --events-ground mit seinen Teilen (-on, -link, -border, -focus), --events-space und --events-corners - in :where(:root), mit den Tokens von Nino.css. Das Feature Design bewegt sie.',
			],
			'Timezone' => [
				'en_US' => 'The times of a date are wall-clock times of the site, read in the zone of the setting timezone - As the server is set, the default, is the one php runs in (UTC unless the host says otherwise) -, written by the chip in that zone and converted from it to UTC in the calendar files.',
				'de_DE' => 'Die Zeiten eines Termins sind Uhrzeiten der Site, gelesen in der Zone der Einstellung Zeitzone - Wie der Server eingestellt ist, der Standard, ist die, in der php läuft (UTC, wenn der Host nichts anderes sagt) -, vom Chip in dieser Zone geschrieben und in den Kalenderdateien von ihr in UTC umgerechnet.',
			],
			'Restore' => [
				'en_US' => 'No callback of /nino/admin/restore: the index of the feed tokens in /data/events.php is regenerable. A member who draws the page again gets a link, and an old link that a restored file does not know opens the public feed - so a restored backup ends no feed but the links a member has made since it was taken.',
				'de_DE' => 'Kein Callback von /nino/admin/restore: Der Index der Feed-Tokens in /data/events.php lässt sich neu erzeugen. Ein Mitglied, das die Seite wieder zeichnet, bekommt einen Link, und ein alter Link, den eine wiederhergestellte Datei nicht kennt, öffnet den öffentlichen Feed - eine Wiederherstellung beendet also keinen Feed, nur die Links, die ein Mitglied seit der Sicherung gemacht hat.',
			],
			'Page cache' => [
				'en_US' => 'A page with a stack, a chip or a link is kept by the page cache of the kernel, where it is switched on, for as long as the cache keeps a page (an hour unless the config says otherwise): the words Today and Tomorrow and the classes events-when--today and events-when--past are as they were when the page was drawn. A page that is drawn for a signed-in member is never kept.',
				'de_DE' => 'Eine Seite mit einem Stapel, einem Chip oder einem Link wird vom Seiten-Cache des Kernels, wo er an ist, so lange gehalten, wie der Cache eine Seite hält (eine Stunde, wenn die Config nichts anderes sagt): Die Worte Heute und Morgen und die Klassen events-when--today und events-when--past stehen so da, wie sie beim Zeichnen der Seite waren. Eine Seite, die für ein angemeldetes Mitglied gezeichnet wird, wird nie gehalten.',
			],
			'No personal data' => [
				'en_US' => 'Nothing is stored about a visitor: no cookie, no count, no address. The one thing the feature writes is the token of a member\'s feed link, a hash tied to the account, in /data/events.php.',
				'de_DE' => 'Über einen Besucher wird nichts gespeichert: kein Cookie, keine Zählung, keine Adresse. Das Einzige, was das Feature schreibt, ist der Token des Feed-Links eines Mitglieds, ein Hash, der an das Konto gebunden ist, in /data/events.php.',
			],
		],
		'routes' => [
			'GET /.events.ics' => [
				'en_US' => 'The feed for a calendar app, text/calendar, cached for an hour: one VEVENT for each public date within the settings ahead (days into the future) and past (days back; the feed reads 0 as 365 days). The UID is <slug>@<host of the website>, the stamp is the start of the date (Nino keeps no time of the last change), the moments are in UTC and a date at midnight with no end is a day (DATE). Lines are folded at 75 octets and the text is escaped as RFC 5545 asks. The name of the calendar is the setting name, else the name of the company. A 404 while the setting feed is off.',
				'de_DE' => 'Der Feed für eine Kalender-App, text/calendar, eine Stunde gecacht: ein VEVENT je öffentlichem Termin innerhalb der Einstellungen ahead (Tage in die Zukunft) und past (Tage zurück; der Feed liest 0 als 365 Tage). Die UID ist <Slug>@<Host der Website>, der Zeitstempel ist der Beginn des Termins (Nino hält keine Zeit der letzten Änderung), die Zeitpunkte stehen in UTC und ein Termin um Mitternacht ohne Ende ist ein Tag (DATE). Zeilen werden bei 75 Oktetten umbrochen und der Text so maskiert, wie RFC 5545 es verlangt. Der Name des Kalenders ist die Einstellung name, sonst der Name der Firma. Ein 404, solange die Einstellung feed aus ist.',
			],
			'GET /.events.ics?k=<token>' => [
				'en_US' => 'The feed of a member: the public dates and those of the group of the account the token was made for, not cached by a shared cache. A token that is none, that nobody holds, whose account is gone or switched off, or while the Members feature is not active, is the public feed - no word says that it was wrong.',
				'de_DE' => 'Der Feed eines Mitglieds: die öffentlichen Termine und die der Gruppe des Kontos, für das der Token gemacht wurde, nicht von einem gemeinsamen Cache gespeichert. Ein Token, der keiner ist, den niemand hält, dessen Konto weg oder abgeschaltet ist, oder solange das Feature Mitglieder nicht aktiv ist, ist der öffentliche Feed - kein Wort sagt, dass er falsch war.',
			],
			'GET /.events/<slug>.ics' => [
				'en_US' => 'One date as a calendar file with one VEVENT. The slug is the name of the element, made of lower-case letters, digits and hyphens. A date with a group is answered to a member who is signed in and in that group, and is a 404 for everybody else - a visitor, a member of another group and, where the Members feature is not active, everyone - the same 404 as for a date that is not there.',
				'de_DE' => 'Ein Termin als Kalenderdatei mit einem VEVENT. Der Slug ist der Name des Elements, aus Kleinbuchstaben, Ziffern und Bindestrichen. Ein Termin mit Gruppe wird einem angemeldeten Mitglied dieser Gruppe beantwortet und ist für alle anderen ein 404 - einen Besucher, ein Mitglied einer anderen Gruppe und, wo das Feature Mitglieder nicht aktiv ist, jeden - derselbe 404 wie für einen Termin, den es nicht gibt.',
			],
		],
		'panel' => [
			'Elements: Events' => [
				'en_US' => 'The feature has no panel of its own: the dates are elements of the type Events and are entered in the Elements panel - a title, a start and an end (a date and a time), a place, a text, a link and a group. The group is the slug of a group of the Members feature, as the Members panel shows it, and empty for a date that is for everyone.',
				'de_DE' => 'Das Feature hat kein eigenes Panel: Die Termine sind Elemente des Typs Events und werden im Panel Elemente erfasst - ein Titel, ein Beginn und ein Ende (ein Datum und eine Uhrzeit), ein Ort, ein Text, ein Link und eine Gruppe. Die Gruppe ist der Slug einer Gruppe des Features Mitglieder, wie das Panel Mitglieder ihn zeigt, und leer für einen Termin, der für alle ist.',
			],
			'Design: the look of the chip' => [
				'en_US' => 'With the Design feature on, the tab Events of the Design panel offers one look, Regular, with the ground of the chip, the space inside it and its corners. The block design/preview.tpl shows a row of three dates in the preview; Apply to website puts the look on the site.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Termine des Design-Panels einen Look, Regulär, mit dem Grund des Chips, dem Abstand darin und seinen Ecken. Der Block design/preview.tpl zeigt eine Reihe aus drei Terminen in der Vorschau; Auf Website anwenden bringt den Look auf die Site.',
			],
		],
		'callbacks' => [
			'/events/stack' => [
				'en_US' => 'The hook the stack filters its loop through - the scope, the order and what this visitor sees. It does nothing outside a stack and is no extension point.',
				'de_DE' => 'Der Haken, über den der Stapel seine Schleife filtert - Scope, Reihenfolge und was dieser Besucher sieht. Außerhalb eines Stapels tut er nichts und ist kein Erweiterungspunkt.',
			],
		],
		'install' => [
			'events.php' => [
				'en_US' => 'The element type /events, if the project has none: title, start (required) and end (datetime), place, text (paragraphs and lists), link and group. Add-only, as every unit.',
				'de_DE' => 'Der Elementtyp /events, falls das Projekt keinen hat: title, start (Pflicht) und end (datetime), place, text (Absätze und Listen), link und group. Nur hinzufügend, wie jede Einheit.',
			],
			'text/<locale>.php' => [
				'en_US' => 'The words of the chip (today, tomorrow, until), of the links, of the name of the calendar and of the lines a stack writes for nothing to list, into the Text panel.',
				'de_DE' => 'Die Worte des Chips (heute, morgen, bis), der Links, des Kalendernamens und der Zeilen, die ein Stapel für nichts zu Listendes schreibt, ins Panel Texte.',
			],
			'No privacy section' => [
				'en_US' => 'Not shipped: the feature stores nothing about a visitor.',
				'de_DE' => 'Wird nicht mitgeliefert: Das Feature speichert nichts über einen Besucher.',
			],
		],
	],
	'category'		=> 'content',
	'version'			=> '1.0.0',
	// 1.9, because the cells of the stack read the name of an element alone (.slug) and fill the fields of an element
	// into the content of a cell
	'nino'				=> '^1.9',
	// Members is optional: a date with a group needs it to be seen by anyone, a date without one never does
	'requires'		=> [],
	// The feed tokens of members. The dates are ordinary elements and live in /elements/, where a backup finds them
	'data'				=> [ '/data/events.php' ],
	'assets'			=> [ '/features/Events/assets/events.css' ],
	'settings'		=> [
		'ahead' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Days ahead', 'de_DE' => 'Tage voraus' ],
			'hint'		=> [
				'en_US' => 'How far into the future the feed and the upcoming dates of a stack look.',
				'de_DE' => 'Wie weit in die Zukunft der Feed und die anstehenden Termine eines Stapels sehen.',
			],
			'min'			=> 1,
			'max'			=> 730,
			'unit'		=> 'days',
			'default'	=> 365,
		],
		'past' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Days back', 'de_DE' => 'Tage zurück' ],
			'hint'		=> [
				'en_US' => 'How far back the past dates of a stack and the feed reach, counted from the end of a date. 0 is as far as there are any for a stack; the feed reads 0 as 365 days, so that it does not grow for ever.',
				'de_DE' => 'Wie weit die vergangenen Termine eines Stapels und der Feed zurückreichen, vom Ende eines Termins gerechnet. 0 ist bei einem Stapel so weit, wie es welche gibt; der Feed liest 0 als 365 Tage, damit er nicht für immer wächst.',
			],
			'min'			=> 0,
			'max'			=> 3650,
			'unit'		=> 'days',
			'default'	=> 0,
		],
		'feed' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Feed', 'de_DE' => 'Feed' ],
			'hint'		=> [
				'en_US' => 'Whether /.events.ics answers, for the public and for the members. Off, it is a 404 and [event-feed] shows nothing; the file of a single date, /.events/<slug>.ics, stays.',
				'de_DE' => 'Ob /.events.ics antwortet, für die Öffentlichkeit und für die Mitglieder. Aus, ist es ein 404 und [event-feed] zeigt nichts; die Datei eines einzelnen Termins, /.events/<slug>.ics, bleibt.',
			],
			'default'	=> true,
		],
		'name' => [
			'type'		=> 'string',
			'label'		=> [ 'en_US' => 'Name of the calendar', 'de_DE' => 'Name des Kalenders' ],
			'hint'		=> [
				'en_US' => 'What a calendar app calls the feed. Empty takes the name of the company.',
				'de_DE' => 'Wie eine Kalender-App den Feed nennt. Leer nimmt den Namen der Firma.',
			],
			'maxlength'	=> 80,
			'default'		=> '',
		],
		/*	The zone the dates are read in. 'server' is a value of its own rather than '', as Countdown has it: a select the
			Features panel has once filled cannot be emptied again. The map is built here without a variable, because
			Features::manifest(), bin/build.php and the tests all include() this file	*/
		'timezone' => [
			'type'			=> 'select',
			'label'			=> [ 'en_US' => 'Timezone', 'de_DE' => 'Zeitzone' ],
			'hint'			=> [
				'en_US' => 'The zone the dates of the Elements panel are read in: the feed converts from it to UTC and the chip writes in it. "As the server is set" is the zone php runs in.',
				'de_DE' => 'Die Zone, in der die Termine aus dem Panel Elemente gelesen werden: Der Feed rechnet von ihr in UTC um und der Chip schreibt in ihr. „Wie der Server eingestellt ist“ ist die Zone, in der php läuft.',
			],
			'options'		=> [ 'server' => [ 'en_US' => 'As the server is set (PHP default)', 'de_DE' => 'Wie der Server eingestellt ist (PHP-Standard)' ] ]
				+ ( static fn( array $ids ): array => array_combine( $ids, $ids ) )( \DateTimeZone::listIdentifiers() ),
			'required'	=> true,
			'default'		=> 'server',
		],
	],
	// The three components of a cell: the chip reads the start from the field it is given and the end from the field
	// its attribute names, the two links read the name of the date. Every attribute reaches the renderer as a string
	'components'	=> [
		'event-when' => [
			'label'			=> [ 'en_US' => 'Date', 'de_DE' => 'Datum' ],
			'source'		=> 'text',
			'attributes'	=> [
				'end' => [
					'type'		=> 'string',
					'default'	=> '',
					'label'		=> [ 'en_US' => 'Field of the end', 'de_DE' => 'Feld des Endes' ],
					'hint'		=> [
						'en_US' => 'The name of the field that holds the end, read from the date in the cell: end. Empty shows the start alone.',
						'de_DE' => 'Der Name des Feldes mit dem Ende, gelesen aus dem Termin in der Zelle: end. Leer zeigt nur den Beginn.',
					],
				],
				'format' => [
					'type'		=> 'select',
					'options'	=> [ 'datetime', 'date', 'time' ],
					'default'	=> 'datetime',
					'input'		=> 'segment',
					'label'		=> [ 'en_US' => 'Shows', 'de_DE' => 'Zeigt' ],
				],
			],
			'preview'		=> 'text',
			'draws'			=> 'time .events-when',
			'in'			=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M8 2v4' ] ],
				[ 'path', [ 'd' => 'M16 2v4' ] ],
				[ 'rect', [ 'width' => '18', 'height' => '18', 'x' => '3', 'y' => '4', 'rx' => '2' ] ],
				[ 'path', [ 'd' => 'M3 10h18' ] ],
			],
		],
		'event-ics' => [
			'label'			=> [ 'en_US' => 'Add to calendar', 'de_DE' => 'Zum Kalender hinzufügen' ],
			'source'		=> 'text',
			'attributes'	=> [],
			'preview'		=> 'button',
			'draws'			=> 'a .events-ics',
			'in'			=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M8 2v4' ] ],
				[ 'path', [ 'd' => 'M16 2v4' ] ],
				[ 'path', [ 'd' => 'M21 12.598V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.5' ] ],
				[ 'path', [ 'd' => 'M3 10h18' ] ],
				[ 'path', [ 'd' => 'M16 19h6' ] ],
				[ 'path', [ 'd' => 'M19 16v6' ] ],
			],
		],
		'event-feed' => [
			'label'			=> [ 'en_US' => 'Calendar feed', 'de_DE' => 'Kalender-Feed' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [],
			'preview'		=> 'button',
			'draws'			=> 'a .events-feed',
			'in'			=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M4 11a9 9 0 0 1 9 9' ] ],
				[ 'path', [ 'd' => 'M4 4a16 16 0 0 1 16 16' ] ],
				[ 'circle', [ 'cx' => '5', 'cy' => '19', 'r' => '1' ] ],
			],
		],
	],
	// The dates, drawn by the components the Builder puts in each cell. The scope and the order are the stack's own; the
	// loop (offset, limit, query) and the grid (cols, gap, autoheight) are the kernel's, a stack of the kernel has them too
	'stacks'			=> [
		'events' => [
			'label'			=> [ 'en_US' => 'Events', 'de_DE' => 'Termine' ],
			'grid'			=> true,
			'attributes'	=> [
				'scope' => [
					'type'		=> 'select',
					'options'	=> [ 'upcoming', 'past', 'all' ],
					'default'	=> 'upcoming',
					'input'		=> 'segment',
					'label'		=> [ 'en_US' => 'Dates', 'de_DE' => 'Termine' ],
					'hint'		=> [
						'en_US' => 'Upcoming: what is not over. Past: what is over. All: both.',
						'de_DE' => 'Anstehend: was nicht vorbei ist. Vergangen: was vorbei ist. Alle: beides.',
					],
				],
				'order' => [
					'type'		=> 'select',
					'options'	=> [ 'asc', 'desc' ],
					'default'	=> 'asc',
					'input'		=> 'segment',
					'label'		=> [ 'en_US' => 'Order', 'de_DE' => 'Reihenfolge' ],
					'hint'		=> [
						'en_US' => 'Asc starts with the date nearest to now: the next one that is coming, the latest of the past ones. For all dates it is the earliest first.',
						'de_DE' => 'Asc beginnt mit dem Termin, der Jetzt am nächsten liegt: dem nächsten anstehenden, dem spätesten der vergangenen. Bei allen Terminen ist es der früheste zuerst.',
					],
				],
			],
			'preview'		=> 'cells',
		],
	],
];

<?php
// The feature manifest - what the Features panel reads to list, activate
// and update this feature (see \Nino\Features and docs/features.md, "The
// manifest"). The class is not declared here: features/Members/ can only ever
// serve \Nino\Modules\Members.
return [
	'key'					=> 'members',
	'name'				=> [ 'en_US' => 'Members', 'de_DE' => 'Mitglieder' ],
	'description'	=> [
		'en_US' => 'Member accounts for the website without passwords: a member signs in with a link sent by mail, pages can be put behind the sign-in, and a panel manages the accounts.',
		'de_DE' => 'Mitgliederkonten für die Website ohne Passwörter: Wer Mitglied ist, meldet sich mit einem Link an, der per E-Mail kommt; Seiten lassen sich hinter die Anmeldung legen, und ein Panel verwaltet die Konten.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[members-login]' => [
				'en_US' => 'The login form: a field for the email address and a button that mails a link to it - there is no password. A member who is signed in sees their name and a link to sign out in its place. It is also the component Member login of the Builder, in a section, a card and a hero; the Builder and the shortcode are one renderer. The words are the project\'s, in the Text panel: /feature/members/login/label, /feature/members/login/button and the five texts under /feature/members/state/.',
				'de_DE' => 'Das Anmeldeformular: ein Feld für die E-Mail-Adresse und ein Knopf, der einen Link dorthin schickt – ein Passwort gibt es nicht. Wer angemeldet ist, sieht an seiner Stelle den eigenen Namen und einen Link zum Abmelden. Es ist auch die Komponente Mitglieder-Anmeldung des Builders, in einer Sektion, einer Karte und einem Hero; Builder und Shortcode sind ein Renderer. Die Worte gehören dem Projekt, im Panel Texte: /feature/members/login/label, /feature/members/login/button und die fünf Texte unter /feature/members/state/.',
			],
			'[members-login inline="1"]' => [
				'en_US' => 'The field and the button on one line (the kernel\'s nino-form--inline); the label is then read by screen readers only (nino-sr-only).',
				'de_DE' => 'Feld und Knopf in einer Zeile (nino-form--inline des Kernels); die Beschriftung lesen dann nur noch Screenreader (nino-sr-only).',
			],
			'[members-logout]' => [
				'en_US' => 'A link that signs the member out. Shows nothing to a visitor, so a frame can carry it for everybody.',
				'de_DE' => 'Ein Link, der das Mitglied abmeldet. Zeigt einem Besucher nichts, ein Seitengerüst kann ihn also für alle tragen.',
			],
			'[members-name]' => [
				'en_US' => 'The name of the signed-in member, or their email address where the account has no name. Shows nothing to a visitor.',
				'de_DE' => 'Der Name des angemeldeten Mitglieds, oder seine E-Mail-Adresse, wo das Konto keinen Namen hat. Zeigt einem Besucher nichts.',
			],
			'[members-confirm]' => [
				'en_US' => 'The button of the page a link of the mail opens: the name of the account the link signs in, and one button that does it. It belongs to the page page-members-confirm of the install unit and shows nothing on any other page.',
				'de_DE' => 'Der Button der Seite, die ein Link der Mail öffnet: der Name des Kontos, das der Link anmeldet, und ein Button, der es tut. Er gehört zur Seite page-members-confirm der Installationseinheit und zeigt auf keiner anderen Seite etwas.',
			],
			'[members-login ... class="my-class"]' => [
				'en_US' => 'A class of your own, added to those of the form, the link or the name - all four components take it. What the Builder writes for its custom classes.',
				'de_DE' => 'Eine eigene Klasse, zusätzlich zu denen des Formulars, des Links oder des Namens – alle vier Komponenten nehmen sie. Das schreibt der Builder für seine eigenen Klassen.',
			],
		],
		'markup' => [
			'Custom properties' => [
				'en_US' => 'members.css declares the properties the form and the line of a signed-in member are drawn from - --members-ground with its parts (-on, -link, -border, -focus), --members-space and --members-corners - with the page\'s own roles as their defaults, in :where(), so that a look of the Design feature or a rule of the project moves them.',
				'de_DE' => 'members.css deklariert die Properties, aus denen das Formular und die Zeile eines angemeldeten Mitglieds gezeichnet werden – --members-ground mit seinen Teilen (-on, -link, -border, -focus), --members-space und --members-corners –, mit den Rollen der Seite als Standard, in :where(), damit ein Look des Features Design oder eine Regel des Projekts sie verschiebt.',
			],
			'.members-login, .members-confirm, .members-signed, .members-logout, .members-name' => [
				'en_US' => 'The classes of the markup: the form with its message (.members-login__state), the form of the page a link opens (.members-confirm), the line of a signed-in member, the link that signs out and the name. The forms are no .nino-form on purpose: the kernel\'s script posts such a form by XHR, and these are answered by a redirect. The field of the login form is a .nino-form-input and the buttons are .nino-btn.',
				'de_DE' => 'Die Klassen des Markups: das Formular mit seiner Meldung (.members-login__state), das Formular der Seite, die ein Link öffnet (.members-confirm), die Zeile eines angemeldeten Mitglieds, der Link zum Abmelden und der Name. Die Formulare sind absichtlich kein .nino-form: Das Skript des Kernels sendet ein solches Formular per XHR ab, diese werden mit einer Umleitung beantwortet. Das Feld des Anmeldeformulars ist ein .nino-form-input, die Buttons sind .nino-btn.',
			],
		],
		'routes' => [
			'POST /.members/login' => [
				'en_US' => 'The request for a link: email and return, with [csrf] in the form. It counts the visitor (the setting attempts per hour) and the address (3 per hour) and, over either, redirects (303) to the page the form was on with ?members=limit. Otherwise it mails a link to an address that belongs to an active member and nothing to any other - and answers both alike: the redirect (303) to that page with ?members=sent, so that nothing tells whether an address is a member\'s. A post without the csrf token is answered with a redirect (303) to that page with ?members=expired, and counts for nothing.',
				'de_DE' => 'Die Anforderung eines Links: email und return, mit [csrf] im Formular. Sie zählt den Besucher (die Einstellung Versuche je Stunde) und die Adresse (3 je Stunde) und leitet darüber (303) auf die Seite des Formulars mit ?members=limit um. Sonst schickt sie einer Adresse, die zu einem aktiven Mitglied gehört, einen Link und jeder anderen nichts – und antwortet beiden gleich: mit der Umleitung (303) auf diese Seite mit ?members=sent, damit nichts verrät, ob eine Adresse zu einem Mitglied gehört. Ein Post ohne den csrf-Token wird mit einer Umleitung (303) auf diese Seite mit ?members=expired beantwortet und zählt nicht.',
			],
			'/.members/login?t=<token>' => [
				'en_US' => 'The link of the mail. It changes nothing: for a valid token it answers (200) a page that names the account and holds one button, for an unknown, expired or spent token the login page in place, with the status 400 and the message that the link is not valid. A mail scanner or a prefetch that opens the link uses nobody\'s link up.',
				'de_DE' => 'Der Link der Mail. Er ändert nichts: Für einen gültigen Token antwortet er (200) mit einer Seite, die das Konto nennt und einen Button hat, für einen unbekannten, abgelaufenen oder verbrauchten Token mit der Anmeldeseite an Ort und Stelle, mit dem Status 400 und der Meldung, dass der Link nicht gültig ist. Ein Mail-Scanner oder ein Vorabruf, der den Link öffnet, verbraucht niemandes Link.',
			],
			'POST /.members/confirm' => [
				'en_US' => 'The button of that page: the token and [csrf]. The token is spent on the spot, the member is signed in and sent on (303) to the page that asked for the login, else to the start page - where to is kept with the token on the server and never in the form. A post without the csrf token answers the page again with the message that the session has run out and uses nothing up; a token that is spent, has run out or belongs to an account that is gone answers the login page in place, with the status 400.',
				'de_DE' => 'Der Button dieser Seite: der Token und [csrf]. Der Token wird sofort verbraucht, das Mitglied ist angemeldet und wird (303) auf die Seite weitergeleitet, die die Anmeldung verlangt hat, sonst auf die Startseite – wohin, steht beim Token auf dem Server und nie im Formular. Ein Post ohne den csrf-Token beantwortet die Seite noch einmal mit der Meldung, dass die Sitzung abgelaufen ist, und verbraucht nichts; ein Token, der verbraucht oder abgelaufen ist oder zu einem Konto gehört, das es nicht mehr gibt, beantwortet die Anmeldeseite an Ort und Stelle mit dem Status 400.',
			],
			'/.members/logout' => [
				'en_US' => 'Signs the member out and goes (303) to the start page with ?members=out. A plain GET link, which is what [members-logout] writes.',
				'de_DE' => 'Meldet das Mitglied ab und geht (303) zur Startseite mit ?members=out. Ein einfacher GET-Link, wie ihn [members-logout] schreibt.',
			],
		],
		'panel' => [
			'Members' => [
				'en_US' => 'The accounts - name, email address, group, whether the account is active and when the member last signed in -, sorted by name, with a filter by group; a form to add one, with the invitation ticked by default; and for each account: change name, group and active, send a login link, delete. A button signs every member out at once.',
				'de_DE' => 'Die Konten – Name, E-Mail-Adresse, Gruppe, ob das Konto aktiv ist und wann sich das Mitglied zuletzt angemeldet hat –, nach Name sortiert, mit einem Filter nach Gruppe; ein Formular, um eines anzulegen, mit der Einladung vorangekreuzt; und je Konto: Name, Gruppe und aktiv ändern, einen Login-Link senden, löschen. Ein Knopf meldet alle Mitglieder auf einmal ab.',
			],
			'Design: the look of the login form' => [
				'en_US' => 'With the Design feature on, the tab Members of the Design panel offers under Base the look Regular, the form as the feature draws it without a theme, and its knobs stand under Surface (the space inside the form and its corners) and Colour (the ground it is painted on). The block design/preview.tpl shows the form in the preview; Apply to website puts the look on the site.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Mitglieder des Design-Panels unter Grundlage den Look Regulär an, das Formular, wie das Feature es ohne ein Theme zeichnet, und seine Regler stehen unter Fläche (der Innenabstand des Formulars und seine Ecken) und Farbe (der Grund, auf den es gemalt ist). Der Block design/preview.tpl zeigt das Formular in der Vorschau; Auf Website anwenden bringt den Look auf die Site.',
			],
		],
		'callbacks' => [
			'/nino/http/response' => [
				'en_US' => 'The gate: puts the login page in place of every page under a protected path, for a visitor who is not signed in (status 401, and never stored by the cache). A page with a route for each language is shut at every address: the gate looks at the uri of the route as well as at the path. Nothing under /_ or /. is ever protected.',
				'de_DE' => 'Das Tor: stellt die Anmeldeseite an die Stelle jeder Seite unter einem geschützten Pfad, für einen Besucher, der nicht angemeldet ist (Status 401, und vom Cache nie gespeichert). Eine Seite mit einer Route je Sprache ist an jeder Adresse zu: Das Tor sieht auf die Uri der Route und auf den Pfad. Unter /_ und /. wird nie etwas geschützt.',
			],
			'/seo/exclude' => [
				'en_US' => 'Tells the SEO feature, where it is installed, to keep the protected paths out of sitemap.xml and llms.txt - and the address in every language of a page that lies under one.',
				'de_DE' => 'Sagt dem Feature SEO, wo es installiert ist, die geschützten Pfade aus sitemap.xml und llms.txt herauszuhalten – und die Adresse in jeder Sprache einer Seite, die unter einem liegt.',
			],
			'/members/login' => [
				'en_US' => 'Fired by this feature when a member has signed in, with the account as the argument - an array of id, email, name, group, active, created and login - for the features that build on it.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn sich ein Mitglied angemeldet hat, mit dem Konto als Argument – einem Array aus id, email, name, group, active, created und login –, für die Features, die darauf aufbauen.',
			],
			'/members/logout' => [
				'en_US' => 'Fired when a signed-in member signs out through /.members/logout, with the same account as the argument.',
				'de_DE' => 'Wird ausgelöst, wenn sich ein angemeldetes Mitglied über /.members/logout abmeldet, mit demselben Konto als Argument.',
			],
		],
		'install' => [
			'templates/page-members-login.tpl' => [
				'en_US' => 'The login page: the site\'s frames, a title, a short text and [members-login]. The gate renders it in place of a protected page and the link renders it for a token that is not valid; a project that wants an address of its own for it gives it a route in the Routes panel.',
				'de_DE' => 'Die Anmeldeseite: die Rahmen der Website, ein Titel, ein kurzer Text und [members-login]. Das Tor rendert sie an Stelle einer geschützten Seite, und der Link rendert sie für einen Token, der nicht gültig ist; ein Projekt, das eine eigene Adresse dafür will, gibt ihr im Panel Routen eine Route.',
			],
			'templates/page-members-confirm.tpl' => [
				'en_US' => 'The page a link of the mail opens: the site\'s frames, a title, a short text and [members-confirm], the button that signs the member in. Nothing is used up until the button is pressed, which is what keeps a mail scanner from using a link up. The link renders it; a project that wants an address of its own for it gives it a route in the Routes panel.',
				'de_DE' => 'Die Seite, die ein Link der Mail öffnet: die Rahmen der Website, ein Titel, ein kurzer Text und [members-confirm], der Button, der das Mitglied anmeldet. Verbraucht wird erst, wenn der Button gedrückt ist – so verbraucht kein Mail-Scanner einen Link. Der Link rendert sie; ein Projekt, das eine eigene Adresse dafür will, gibt ihr im Panel Routen eine Route.',
			],
			'text/<locale>.php' => [
				'en_US' => 'Its words, into the Text panel - those of the two pages, the forms, their messages and the two mails.',
				'de_DE' => 'Seine Worte, ins Panel Texte – die der beiden Seiten, der Formulare, ihrer Meldungen und der beiden Mails.',
			],
			'elements/privacy.php' => [
				'en_US' => 'Its section of the privacy policy, added to the Legal module\'s type - never replacing a section. Where there is no such module, nothing happens. A starting point, no legal advice. It names fifteen minutes and seven days, the defaults of the settings linkMinutes and inviteDays: whoever changes a setting changes the section in the Elements panel too.',
				'de_DE' => 'Sein Abschnitt der Datenschutzerklärung, dem Typ des Moduls Legal hinzugefügt – ohne einen Abschnitt zu ersetzen. Wo es das Modul nicht gibt, passiert nichts. Ein Ausgangspunkt, keine Rechtsberatung. Er nennt fünfzehn Minuten und sieben Tage, die Standards der Einstellungen linkMinutes und inviteDays: Wer eine Einstellung ändert, passt den Abschnitt im Panel Elemente mit an.',
			],
		],
	],
	'category'		=> 'security',
	'version'			=> '1.0.0',
	'nino'				=> '^1.8',
	'requires'		=> [],
	// The accounts, the links that are out and the session epoch - what a
	// backup carries. A restore replaces the three whole, and an epoch from
	// the backup ends every session of the day, which is right. The counter of
	// the login requests lives beside them (members-tries.php) and stays out
	// of a backup: it is an hour long
	'data'				=> [ '/data/members.php', '/data/members-tokens.php', '/data/members-session.php' ],
	// The site's stylesheet of the components, into the style bundle
	'assets'			=> [ '/features/Members/assets/members.css' ],
	'settings'		=> [
		'paths' => [
			'type'	=> 'lines',
			'label'	=> [ 'en_US' => 'Protected paths', 'de_DE' => 'Geschützte Pfade' ],
			'hint'	=> [
				'en_US' => 'One uri prefix per line, eg. /club - protects that page and everything below it: only a signed-in member sees it, everybody else is shown the login page. A page that has a route for each language is shut at all of them when its uri is the one that is written here. Leave empty to protect nothing.',
				'de_DE' => 'Ein Uri-Präfix pro Zeile, zB /club - schützt diese Seite und alles darunter: Nur ein angemeldetes Mitglied sieht sie, allen anderen wird die Anmeldeseite gezeigt. Eine Seite mit einer Route je Sprache ist an allen zu, wenn hier ihre Uri steht. Leer lassen, um nichts zu schützen.',
			],
		],
		'start' => [
			'type'			=> 'string',
			'label'			=> [ 'en_US' => 'Start page', 'de_DE' => 'Startseite' ],
			'hint'			=> [
				'en_US' => 'The page a member is sent to after signing in when no page asked for the login (a link requested from a public page), and after signing out. A path of this site, eg. /club.',
				'de_DE' => 'Die Seite, auf die ein Mitglied nach der Anmeldung geschickt wird, wenn keine Seite die Anmeldung verlangt hat (ein Link, der von einer öffentlichen Seite angefordert wurde), und nach dem Abmelden. Ein Pfad dieser Website, zB /club.',
			],
			'default'		=> '/',
			'maxlength'	=> 200,
			'pattern'		=> '~^/(?![/\\\\])(?![^/]*:)[^\s\x00-\x1f\x7f?#]*$~D',
		],
		'linkMinutes' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Login link valid for', 'de_DE' => 'Login-Link gültig für' ],
			'hint'		=> [
				'en_US' => 'How long the link a member asks for can be used, once. The section of the privacy policy names fifteen minutes - change it with this.',
				'de_DE' => 'Wie lange der Link, den ein Mitglied anfordert, benutzt werden kann, einmal. Der Abschnitt der Datenschutzerklärung nennt fünfzehn Minuten – ändere ihn mit.',
			],
			'min'			=> 5,
			'max'			=> 1440,
			'unit'		=> 'minutes',
			'default'	=> 15,
		],
		'inviteDays' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Invitation valid for', 'de_DE' => 'Einladung gültig für' ],
			'hint'		=> [
				'en_US' => 'How long the link of an invitation can be used, once. The section of the privacy policy names seven days - change it with this.',
				'de_DE' => 'Wie lange der Link einer Einladung benutzt werden kann, einmal. Der Abschnitt der Datenschutzerklärung nennt sieben Tage – ändere ihn mit.',
			],
			'min'			=> 1,
			'max'			=> 30,
			'unit'		=> 'days',
			'default'	=> 7,
		],
		'attempts' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Requests', 'de_DE' => 'Anforderungen' ],
			'hint'		=> [
				'en_US' => 'Links one visitor may ask for per hour before the form refuses for the rest of that hour. Beside it, one address gets at most 3 links per hour, whoever asks.',
				'de_DE' => 'Links, die ein Besucher pro Stunde anfordern darf, bevor das Formular für den Rest der Stunde verweigert. Daneben bekommt eine Adresse höchstens 3 Links pro Stunde, wer auch fragt.',
			],
			'min'			=> 1,
			'max'			=> 50,
			'unit'		=> 'per-hour',
			'default'	=> 5,
		],
	],
	/*	Three components for the Builder, whose renderers are componentMembersLogin(),
		componentMembersLogout() and componentMembersName() of the class: nothing of them
		is registered by init(). None reads a source - the signed-in member is the
		session's - and only the form has a choice, whether it stands on one line	*/
	'components'	=> [
		'members-login' => [
			'label'			=> [ 'en_US' => 'Member login', 'de_DE' => 'Mitglieder-Anmeldung' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [
				'inline' => [
					'type'		=> 'bool',
					'default'	=> false,
					'label'		=> [ 'en_US' => 'In one line', 'de_DE' => 'In einer Zeile' ],
					'hint'		=> [
						'en_US' => 'The field and the button side by side. The label is then read by screen readers only.',
						'de_DE' => 'Feld und Knopf nebeneinander. Die Beschriftung lesen dann nur noch Screenreader.',
					],
				],
			],
			'preview'		=> 'block',
			'draws'			=> 'form .members-login',
			'in'				=> [ 'section', 'card', 'hero' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'm10 17 5-5-5-5' ] ],
				[ 'path', [ 'd' => 'M15 12H3' ] ],
				[ 'path', [ 'd' => 'M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4' ] ],
			],
		],
		'members-logout' => [
			'label'			=> [ 'en_US' => 'Member logout', 'de_DE' => 'Mitglieder-Abmeldung' ],
			'source'		=> 'none',
			'loop'			=> false,
			'preview'		=> 'button',
			'draws'			=> 'a .members-logout',
			'in'				=> [ 'section', 'card', 'hero' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'm16 17 5-5-5-5' ] ],
				[ 'path', [ 'd' => 'M21 12H9' ] ],
				[ 'path', [ 'd' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4' ] ],
			],
		],
		'members-name' => [
			'label'			=> [ 'en_US' => 'Member name', 'de_DE' => 'Name des Mitglieds' ],
			'source'		=> 'none',
			'loop'			=> false,
			'preview'		=> 'text',
			'draws'			=> 'span .members-name',
			'in'				=> [ 'section', 'card', 'hero' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2' ] ],
				[ 'circle', [ 'cx' => '12', 'cy' => '7', 'r' => '4' ] ],
			],
		],
		// The button of the page a link opens (page-members-confirm.tpl of the install
		// unit, which the Builder reads because it knows this component). It shows
		// something only while a link is being confirmed, and nothing on any other
		// page, so a section is the one place it is offered
		'members-confirm' => [
			'label'			=> [ 'en_US' => 'Member sign-in confirmation', 'de_DE' => 'Bestätigung der Anmeldung' ],
			'source'		=> 'none',
			'loop'			=> false,
			'preview'		=> 'block',
			'draws'			=> 'form .members-confirm',
			'in'				=> [ 'section' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z' ] ],
				[ 'path', [ 'd' => 'm9 12 2 2 4-4' ] ],
			],
		],
	],
	'stacks'			=> [],
];

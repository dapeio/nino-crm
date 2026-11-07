<?php
// The feature manifest - what the Features panel reads to list, activate
// and update this feature (see \Nino\Features and docs/features.md, "The
// manifest"). The class is not declared here: features/Members/ can only ever
// serve \Nino\Modules\Members.
return [
	'key'					=> 'members',
	'name'				=> [ 'en_US' => 'Members', 'de_DE' => 'Mitglieder' ],
	'description'	=> [
		'en_US' => 'Member accounts for the website without passwords: a member signs in with a code sent by mail, pages can be put behind the sign-in, and a panel manages the accounts.',
		'de_DE' => 'Mitgliederkonten für die Website ohne Passwörter: Wer Mitglied ist, meldet sich mit einem Code an, der per E-Mail kommt; Seiten lassen sich hinter die Anmeldung legen, und ein Panel verwaltet die Konten.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[members-login]' => [
				'en_US' => 'The login form, in two steps - there is no password. The first is a field for the email address and a button that mails a code to it. The second, which the form shows in the same place once a code is out in the visitor\'s session, names the address and holds a field for the six digits (a space in the middle is fine), a button, and a link to ask for a new code. A member who is signed in sees their name and a link to sign out in its place. It is also the component Member login of the Builder, in a section, a card and a hero; the Builder and the shortcode are one renderer. The words are the project\'s, in the Text panel: /feature/members/login/label, /feature/members/login/request, the four texts under /feature/members/code/ and the seven texts under /feature/members/state/.',
				'de_DE' => 'Das Anmeldeformular in zwei Schritten – ein Passwort gibt es nicht. Der erste ist ein Feld für die E-Mail-Adresse und ein Knopf, der einen Code dorthin schickt. Der zweite, den das Formular an derselben Stelle zeigt, sobald in der Sitzung des Besuchers ein Code unterwegs ist, nennt die Adresse und hat ein Feld für die sechs Ziffern (ein Leerzeichen in der Mitte schadet nicht), einen Knopf und einen Link, um einen neuen Code anzufordern. Wer angemeldet ist, sieht an seiner Stelle den eigenen Namen und einen Link zum Abmelden. Es ist auch die Komponente Mitglieder-Anmeldung des Builders, in einer Sektion, einer Karte und einem Hero; Builder und Shortcode sind ein Renderer. Die Worte gehören dem Projekt, im Panel Texte: /feature/members/login/label, /feature/members/login/request, die vier Texte unter /feature/members/code/ und die sieben Texte unter /feature/members/state/.',
			],
			'[members-login inline="1"]' => [
				'en_US' => 'The field and the button of the first step on one line (the kernel\'s nino-form--inline); the label is then read by screen readers only (nino-sr-only). The second step is never in one line.',
				'de_DE' => 'Feld und Knopf des ersten Schritts in einer Zeile (nino-form--inline des Kernels); die Beschriftung lesen dann nur noch Screenreader (nino-sr-only). Der zweite Schritt steht nie in einer Zeile.',
			],
			'[members-logout]' => [
				'en_US' => 'A button, in a form with the csrf token, that signs the member out. Shows nothing to a visitor, so a frame can carry it for everybody.',
				'de_DE' => 'Ein Knopf in einem Formular mit dem csrf-Token, der das Mitglied abmeldet. Zeigt einem Besucher nichts, ein Seitengerüst kann ihn also für alle tragen.',
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
				'en_US' => 'members.css declares the properties the forms and the line of a signed-in member are drawn from - --members-ground with its parts (-on, -link, -border, -focus), --members-space and --members-corners - with the page\'s own roles as their defaults, in :where(), so that a look of the Design feature or a rule of the project moves them.',
				'de_DE' => 'members.css deklariert die Properties, aus denen die Formulare und die Zeile eines angemeldeten Mitglieds gezeichnet werden – --members-ground mit seinen Teilen (-on, -link, -border, -focus), --members-space und --members-corners –, mit den Rollen der Seite als Standard, in :where(), damit ein Look des Features Design oder eine Regel des Projekts sie verschiebt.',
			],
			'.members-login, .members-code, .members-confirm, .members-signed, .members-logout, .members-name' => [
				'en_US' => 'The classes of the markup: the form with its message (.members-login__state), its second step with the field for the code (.members-code, .members-code__input) the box that keeps the device signed in (.members-code__remember) and the link that asks for a new code (.members-code__again), the form of the page a link opens (.members-confirm), the line of a signed-in member, the link that signs out and the name. The forms are no .nino-form on purpose: the kernel\'s script posts such a form by XHR, and these are answered by a redirect. The fields of the login forms are .nino-form-input and the buttons are .nino-btn.',
				'de_DE' => 'Die Klassen des Markups: das Formular mit seiner Meldung (.members-login__state), sein zweiter Schritt mit dem Feld für den Code (.members-code, .members-code__input) dem Kästchen, das das Gerät angemeldet lässt (.members-code__remember), und dem Link, der einen neuen Code anfordert (.members-code__again), das Formular der Seite, die ein Link öffnet (.members-confirm), die Zeile eines angemeldeten Mitglieds, der Link zum Abmelden und der Name. Die Formulare sind absichtlich kein .nino-form: Das Skript des Kernels sendet ein solches Formular per XHR ab, diese werden mit einer Umleitung beantwortet. Die Felder der Anmeldeformulare sind .nino-form-input, die Buttons sind .nino-btn.',
			],
		],
		'routes' => [
			'POST /.members/login' => [
				'en_US' => 'The request for a code: email and return, with [csrf] in the form. It counts the visitor (the setting attempts per hour) and the address (3 per hour) and, over either, redirects (303) to the page the form was on with ?members=limit. Otherwise it keeps the address, a random value and the time the code is good until in the visitor\'s session - for every address that is one, a member\'s or not - and mails six digits to an address that belongs to an active member, nothing to any other: the code is kept only as a hash of that random value and the code, so it is worth nothing outside the session that asked. It answers all alike: the redirect (303) to that page with ?members=sent, so that nothing tells whether an address is a member\'s. A post without the csrf token is answered with a redirect (303) to that page with ?members=expired, and counts for nothing.',
				'de_DE' => 'Die Anforderung eines Codes: email und return, mit [csrf] im Formular. Sie zählt den Besucher (die Einstellung Versuche je Stunde) und die Adresse (3 je Stunde) und leitet darüber (303) auf die Seite des Formulars mit ?members=limit um. Sonst hält sie die Adresse, einen Zufallswert und die Zeit, bis zu der der Code gilt, in der Sitzung des Besuchers fest – für jede Adresse, die eine ist, gleich ob ein Mitglied sie hat – und schickt einer Adresse, die zu einem aktiven Mitglied gehört, sechs Ziffern, jeder anderen nichts: Der Code wird nur als Hashwert aus diesem Zufallswert und dem Code gespeichert, ist also außerhalb der Sitzung, die ihn angefordert hat, nichts wert. Sie antwortet allen gleich: mit der Umleitung (303) auf diese Seite mit ?members=sent, damit nichts verrät, ob eine Adresse zu einem Mitglied gehört. Ein Post ohne den csrf-Token wird mit einer Umleitung (303) auf diese Seite mit ?members=expired beantwortet und zählt nicht.',
			],
			'POST /.members/code' => [
				'en_US' => 'The code of the mail: code and return, with [csrf]. Without a request in the session, or after its time, the answer is the redirect (303) to the page of the form with ?members=gone; a post without the csrf token is ?members=expired and uses nothing up. Otherwise a try is counted and the code - six digits, spaces left out - is looked up and spent. A good one signs the member in and sends them on (303) to the page that asked for the login, else to the start page; with the box \'stay signed in on this device\' ticked it also sets the cookie nino_remember (HttpOnly, SameSite=Lax, Secure on https, for the setting rememberDays) with a token of 32 random bytes, of which the account keeps only the sha256. A wrong one - or one that is not six digits - is ?members=wrong, and the fifth is ?members=gone: the request is over and a new code has to be asked for.',
				'de_DE' => 'Der Code der Mail: code und return, mit [csrf]. Ohne eine Anforderung in der Sitzung oder nach ihrer Zeit ist die Antwort die Umleitung (303) auf die Seite des Formulars mit ?members=gone; ein Post ohne den csrf-Token ist ?members=expired und verbraucht nichts. Sonst wird ein Versuch gezählt und der Code – sechs Ziffern, Leerzeichen bleiben außen vor – gesucht und verbraucht. Ein guter meldet das Mitglied an und leitet (303) auf die Seite weiter, die die Anmeldung verlangt hat, sonst auf die Startseite; mit angekreuztem Kästchen „Auf diesem Gerät angemeldet bleiben“ setzt er auch das Cookie nino_remember (HttpOnly, SameSite=Lax, Secure bei https, für die Einstellung rememberDays) mit einem Token aus 32 Zufallsbytes, von dem das Konto nur den sha256 behält. Ein falscher – oder einer, der keine sechs Ziffern hat – ist ?members=wrong, und der fünfte ist ?members=gone: Die Anforderung ist zu Ende, ein neuer Code muss angefordert werden.',
			],
			'/.members/login?t=<token>' => [
				'en_US' => 'The link of an invitation, of the login link the owner sends from the panel and of the link the owner sends to an account that is a member already (kind send: the days of an invitation, a mail of its own). It changes nothing: for a valid token it answers (200) a page that names the account and holds one button, for an unknown, expired or spent token the login page in place, with the status 400 and the message that the link is not valid; where the server still keeps the record of an expired link (30 days after its end), the page that asks for the code leads to the page the link led to. A mail scanner or a prefetch that opens the link uses nobody\'s link up.',
				'de_DE' => 'Der Link einer Einladung, des Anmeldelinks, den der Inhaber im Panel schickt, und des Links, den der Inhaber an ein Konto schickt, das schon Mitglied ist (Art send: die Tage einer Einladung, eine eigene Mail). Er ändert nichts: Für einen gültigen Token antwortet er (200) mit einer Seite, die das Konto nennt und einen Button hat, für einen unbekannten, abgelaufenen oder verbrauchten Token mit der Anmeldeseite an Ort und Stelle, mit dem Status 400 und der Meldung, dass der Link nicht gültig ist; hält der Server den Eintrag eines abgelaufenen Links noch (30 Tage nach seinem Ende), führt der Code, den die Seite anfordert, auf die Seite, zu der der Link führte. Ein Mail-Scanner oder ein Vorabruf, der den Link öffnet, verbraucht niemandes Link.',
			],
			'POST /.members/confirm' => [
				'en_US' => 'The button of that page: the token and [csrf]. The token is spent on the spot, the member is signed in and sent on (303) to the page that asked for the login, else to the start page - where to is kept with the token on the server and never in the form. A post without the csrf token answers the page again with the message that the session has run out and uses nothing up; a token that is spent, has run out or belongs to an account that is gone answers the login page in place, with the status 400.',
				'de_DE' => 'Der Button dieser Seite: der Token und [csrf]. Der Token wird sofort verbraucht, das Mitglied ist angemeldet und wird (303) auf die Seite weitergeleitet, die die Anmeldung verlangt hat, sonst auf die Startseite – wohin, steht beim Token auf dem Server und nie im Formular. Ein Post ohne den csrf-Token beantwortet die Seite noch einmal mit der Meldung, dass die Sitzung abgelaufen ist, und verbraucht nichts; ein Token, der verbraucht oder abgelaufen ist oder zu einem Konto gehört, das es nicht mehr gibt, beantwortet die Anmeldeseite an Ort und Stelle mit dem Status 400.',
			],
			'/.members/logout' => [
				'en_US' => 'POST: signs the member out and goes (303) to the start page with ?members=out. The post carries the csrf token, which [members-logout] and [members-login] write as a form with a button; a post without it is the 403 and signs nobody out. A GET is answered with 405 and a page that links to the start page. The token of this device is taken out of the account and the cookie nino_remember deleted. Members::forgetAll( $id ) signs an account out of every device at once: it empties the list of its devices and gives the account an epoch of its own, which ends every session of it but the one that asks.',
				'de_DE' => 'POST: Meldet das Mitglied ab und geht (303) zur Startseite mit ?members=out. Der Post trägt den csrf-Token, den [members-logout] und [members-login] als Formular mit einem Knopf schreiben; ein Post ohne ihn ist die 403 und meldet niemanden ab. Ein GET beantwortet 405 mit einer Seite, die auf die Startseite verlinkt. Das Token dieses Geräts wird aus dem Konto genommen und das Cookie nino_remember gelöscht. Members::forgetAll( $id ) meldet ein Konto auf einmal von jedem Gerät ab: Es leert die Liste seiner Geräte und gibt dem Konto eine eigene Epoche, die jede Sitzung von ihm beendet, außer der, die fragt.',
			],
		],
		'panel' => [
			'Members' => [
				'en_US' => 'The accounts - name, email address, group, whether the account is active and when the member last signed in -, sorted by name, with a filter by group; a form to add one, with the invitation ticked by default; and for each account: change name, group and active, send a login link, delete. A button signs every member out at once - the devices that stay signed in with them.',
				'de_DE' => 'Die Konten – Name, E-Mail-Adresse, Gruppe, ob das Konto aktiv ist und wann sich das Mitglied zuletzt angemeldet hat –, nach Name sortiert, mit einem Filter nach Gruppe; ein Formular, um eines anzulegen, mit der Einladung vorangekreuzt; und je Konto: Name, Gruppe und aktiv ändern, einen Login-Link senden, löschen. Ein Knopf meldet alle Mitglieder auf einmal ab – die Geräte, die angemeldet bleiben, mit ihnen.',
			],
			'Design: the look of the login form' => [
				'en_US' => 'With the Design feature on, the tab Members of the Design panel offers under Base the look Regular, the form as the feature draws it without a theme, and its knobs stand under Surface (the space inside the form and its corners) and Colour (the ground it is painted on). The block design/preview.tpl shows the form in the preview; Apply to website puts the look on the site.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Mitglieder des Design-Panels unter Grundlage den Look Regulär an, das Formular, wie das Feature es ohne ein Theme zeichnet, und seine Regler stehen unter Fläche (der Innenabstand des Formulars und seine Ecken) und Farbe (der Grund, auf den es gemalt ist). Der Block design/preview.tpl zeigt das Formular in der Vorschau; Auf Website anwenden bringt den Look auf die Site.',
			],
		],
		'callbacks' => [
			'/nino/http/response' => [
				'en_US' => 'The gate: puts the login page in place of every page under a protected path, for a visitor who is not signed in (status 401, and never stored by the cache). A page with a route for each language is shut at every address: the gate looks at the uri of the route as well as at the path. Nothing under /_ or /. is ever protected. The path of the page is kept in the session with its query, as it came; a query with a control character or a blank, and an address over 2000 characters, leave the path alone. Before the gate looks, a visitor who has no session and brings a good cookie nino_remember is signed in (a GET of a page that is html, no endpoint of this feature): the token is replaced with every use (the one just replaced signs in for 30 seconds more, for two tabs that open at once), a cookie that is no good is deleted and counted - the count never stands in the way of a good token - and a token never reaches a log or a mail.',
				'de_DE' => 'Das Tor: stellt die Anmeldeseite an die Stelle jeder Seite unter einem geschützten Pfad, für einen Besucher, der nicht angemeldet ist (Status 401, und vom Cache nie gespeichert). Eine Seite mit einer Route je Sprache ist an jeder Adresse zu: Das Tor sieht auf die Uri der Route und auf den Pfad. Unter /_ und /. wird nie etwas geschützt. Der Pfad der Seite wird mit ihrer Query, wie sie kam, in der Sitzung gemerkt; eine Query mit Steuerzeichen oder Leerzeichen und eine Adresse über 2000 Zeichen lassen nur den Pfad. Bevor das Tor schaut, wird ein Besucher ohne Sitzung, der ein gutes Cookie nino_remember mitbringt, angemeldet (ein GET einer html-Seite, kein Endpunkt dieses Features): Das Token wird bei jeder Nutzung ersetzt (das gerade ersetzte meldet 30 Sekunden lang noch an, für zwei Tabs, die zugleich öffnen), ein Cookie, das nichts taugt, wird gelöscht und gezählt – der Zähler steht einem guten Token nie im Weg –, und ein Token gelangt nie in ein Protokoll oder eine Mail.',
			],
			'/seo/exclude' => [
				'en_US' => 'Tells the SEO feature, where it is installed, to keep the protected paths out of sitemap.xml and llms.txt - and the address in every language of a page that lies under one.',
				'de_DE' => 'Sagt dem Feature SEO, wo es installiert ist, die geschützten Pfade aus sitemap.xml und llms.txt herauszuhalten – und die Adresse in jeder Sprache einer Seite, die unter einem liegt.',
			],
			'/members/login' => [
				'en_US' => 'Fired by this feature when a member has signed in - by a code, a link or the cookie of a device that stays signed in -, with the account as the argument - an array of id, email, name, group, active, created and login - for the features that build on it.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn sich ein Mitglied angemeldet hat – mit einem Code, einem Link oder dem Cookie eines Geräts, das angemeldet bleibt –, mit dem Konto als Argument – einem Array aus id, email, name, group, active, created und login –, für die Features, die darauf aufbauen.',
			],
			'/members/start' => [
				'en_US' => 'Fired by this feature when a sign-in - a code, or the button of a link - has signed the member in and is about to lead on, after /members/login, with the argument account (the account as above), target (the page the sign-in was asked from or the link carries, with its query; empty for none) and to (where it leads: the target, else the start page). A callback may set to to a path of this site - one that starts with a single slash, holds no blank or control character, no colon before the next slash and is no endpoint of a module (/.) - to lead the member there; any other value is the start page. The feature changes nothing about where it leads by itself.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn eine Anmeldung – ein Code oder der Button eines Links – das Mitglied angemeldet hat und weiterleiten will, nach /members/login, mit dem Argument account (das Konto wie oben), target (die Seite, von der die Anmeldung verlangt wurde oder die der Link trägt, mit ihrer Query; leer für keine) und to (wohin es geht: das Ziel, sonst die Startseite). Ein Callback darf to auf einen Pfad dieser Website setzen – einen, der mit einem einzelnen Schrägstrich beginnt, kein Leerzeichen und kein Steuerzeichen enthält, keinen Doppelpunkt vor dem nächsten Schrägstrich hat und kein Endpunkt eines Moduls (/.) ist –, um das Mitglied dorthin zu führen; jeder andere Wert ist die Startseite. Von sich aus ändert das Feature nichts daran, wohin es geht.',
			],
			'/members/login-form' => [
				'en_US' => 'Fired by this feature when the form that asks for a code is drawn for a visitor (the first step), with the arguments return (the path of the page, without its query) and note (empty). A callback may set note to markup: it stands over the field and is put in as it is, so the callback escapes every value it took from the request. The feature changes nothing about the form by itself.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn das Formular, das nach einem Code fragt, für einen Besucher gezeichnet wird (der erste Schritt), mit den Argumenten return (der Pfad der Seite, ohne ihre Query) und note (leer). Ein Callback darf note auf Markup setzen: Es steht über dem Feld und wird so eingesetzt, wie es ist, der Callback maskiert also jeden Wert, den er der Anfrage entnommen hat. Von sich aus ändert das Feature nichts am Formular.',
			],
			'/members/logout' => [
				'en_US' => 'Fired when a signed-in member signs out through /.members/logout, with the same account as the argument.',
				'de_DE' => 'Wird ausgelöst, wenn sich ein angemeldetes Mitglied über /.members/logout abmeldet, mit demselben Konto als Argument.',
			],
			'/members/open' => [
				'en_US' => 'Asked by this feature for the paths a feature opens under a protected prefix - the manifest and the icon of a web app, which a browser fetches without a session -: the callback adds the uris of its routes to the list it is handed. A path that is an endpoint of a module (/., /_) is never opened.',
				'de_DE' => 'Fragt dieses Feature nach den Pfaden, die ein Feature unter einem geschützten Präfix öffnet – das Manifest und das Icon einer Web-App, die ein Browser ohne Sitzung holt –: Der Callback fügt der Liste, die er bekommt, die Uris seiner Routen hinzu. Ein Pfad, der ein Endpunkt eines Moduls ist (/., /_), wird nie geöffnet.',
			],
			'/members/changed' => [
				'en_US' => 'Fired when the address of an account was changed - by Members::change() with \'email\' (the owner, by \'owner\') or by Members::confirmChange() (the member, with the code of the mail the new address got, by \'member\'; Members::askChange() asks for it) -, with an array of account (as account() has it, with the new address), old (the address it had) and by. The old address is mailed the notice, every link and code of the account is deleted, every device of it that stays signed in is forgotten and every other session of it ends before this is fired.',
				'de_DE' => 'Wird ausgelöst, wenn die Adresse eines Kontos geändert wurde – durch Members::change() mit \'email\' (der Inhaber, by \'owner\') oder durch Members::confirmChange() (das Mitglied, mit dem Code der Mail, die die neue Adresse bekam, by \'member\'; Members::askChange() fordert ihn an) –, mit einem Array aus account (wie account() es hat, mit der neuen Adresse), old (die bisherige Adresse) und by. Die alte Adresse hat den Hinweis bekommen, jeder Link und Code des Kontos ist gelöscht, jedes Gerät, das angemeldet bleibt, vergessen und jede andere Sitzung des Kontos beendet, bevor dies ausgelöst wird.',
			],
		],
		'install' => [
			'templates/page-members-login.tpl' => [
				'en_US' => 'The login page: the site\'s frames, a title, a short text and [members-login]. The gate renders it in place of a protected page and a link renders it for a token that is not valid; a project that wants an address of its own for it gives it a route in the Routes panel. A site that has the page already keeps its copy - the unit only adds what is missing -: put the template in again in the Builder to get the wording of the code sign-in.',
				'de_DE' => 'Die Anmeldeseite: die Rahmen der Website, ein Titel, ein kurzer Text und [members-login]. Das Tor rendert sie an Stelle einer geschützten Seite, und ein Link rendert sie für einen Token, der nicht gültig ist; ein Projekt, das eine eigene Adresse dafür will, gibt ihr im Panel Routen eine Route. Hat die Site die Seite schon, behält sie ihre Kopie – die Einheit ergänzt nur, was fehlt –: Spiele die Vorlage im Builder neu ein, um die Worte des Code-Logins zu bekommen.',
			],
			'templates/page-members-confirm.tpl' => [
				'en_US' => 'The page a link of the mail opens: the site\'s frames, a title, a short text and [members-confirm], the button that signs the member in. Nothing is used up until the button is pressed, which is what keeps a mail scanner from using a link up. The link renders it; a project that wants an address of its own for it gives it a route in the Routes panel.',
				'de_DE' => 'Die Seite, die ein Link der Mail öffnet: die Rahmen der Website, ein Titel, ein kurzer Text und [members-confirm], der Button, der das Mitglied anmeldet. Verbraucht wird erst, wenn der Button gedrückt ist – so verbraucht kein Mail-Scanner einen Link. Der Link rendert sie; ein Projekt, das eine eigene Adresse dafür will, gibt ihr im Panel Routen eine Route.',
			],
			'text/<locale>.php' => [
				'en_US' => 'Its words, into the Text panel - those of the two pages, the forms, their messages and the six mails: the code, the login link, the invitation, the link of the owner, the code for a new sign-in address and the notice to the old one that it was changed.',
				'de_DE' => 'Seine Worte, ins Panel Texte – die der beiden Seiten, der Formulare, ihrer Meldungen und der sechs Mails: der Code, der Anmeldelink, die Einladung, der Link des Inhabers, der Code für eine neue Anmeldeadresse und der Hinweis an die alte, dass sie geändert wurde.',
			],
			'elements/privacy.php' => [
				'en_US' => 'Its section of the privacy policy, added to the Legal module\'s type - never replacing a section. Where there is no such module, nothing happens. A starting point, no legal advice. It names fifteen minutes, five tries, seven days and ninety days, the defaults of the settings linkMinutes, inviteDays and rememberDays and of the tries of a code, and the cookie nino_remember: whoever changes a setting changes the section in the Elements panel too.',
				'de_DE' => 'Sein Abschnitt der Datenschutzerklärung, dem Typ des Moduls Legal hinzugefügt – ohne einen Abschnitt zu ersetzen. Wo es das Modul nicht gibt, passiert nichts. Ein Ausgangspunkt, keine Rechtsberatung. Er nennt fünfzehn Minuten, fünf Versuche, sieben Tage und neunzig Tage, die Standards der Einstellungen linkMinutes, inviteDays und rememberDays und der Versuche eines Codes, und das Cookie nino_remember: Wer eine Einstellung ändert, passt den Abschnitt im Panel Elemente mit an.',
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
			'label'		=> [ 'en_US' => 'Login code valid for', 'de_DE' => 'Login-Code gültig für' ],
			'hint'		=> [
				'en_US' => 'How long the code a member asks for can be used, once - the code to sign in and the code for a new sign-in address alike. The login link the owner sends from the panel is valid just as long. The section of the privacy policy names fifteen minutes - change it with this.',
				'de_DE' => 'Wie lange der Code, den ein Mitglied anfordert, benutzt werden kann, einmal – der Code zum Anmelden ebenso wie der für eine neue Anmeldeadresse. Der Anmeldelink, den der Inhaber im Panel schickt, gilt ebenso lange. Der Abschnitt der Datenschutzerklärung nennt fünfzehn Minuten – ändere ihn mit.',
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
				'en_US' => 'Codes one visitor may ask for per hour before the form refuses for the rest of that hour - for a sign-in and for a new sign-in address alike. Beside it, one address gets at most 3 codes per hour, whoever asks.',
				'de_DE' => 'Codes, die ein Besucher pro Stunde anfordern darf, bevor das Formular für den Rest der Stunde verweigert – zum Anmelden ebenso wie für eine neue Anmeldeadresse. Daneben bekommt eine Adresse höchstens 3 Codes pro Stunde, wer auch fragt.',
			],
			'min'			=> 1,
			'max'			=> 50,
			'unit'		=> 'per-hour',
			'default'	=> 5,
		],
		'rememberDays' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Stay signed in for', 'de_DE' => 'Angemeldet bleiben für' ],
			'hint'		=> [
				'en_US' => 'How long a device stays signed in after a member ticked the box of the code form - counted from its last visit, the cookie nino_remember being renewed with every one. Changing the email address of an account, switching it off or signing it out of all devices ends every one of its devices at once.',
				'de_DE' => 'Wie lange ein Gerät angemeldet bleibt, nachdem ein Mitglied das Kästchen des Code-Formulars angekreuzt hat – gezählt ab dem letzten Besuch, das Cookie nino_remember wird bei jedem erneuert. Die Änderung der E-Mail-Adresse eines Kontos, sein Abschalten und das Abmelden von allen Geräten beenden alle seine Geräte auf einmal.',
			],
			'min'			=> 1,
			'max'			=> 365,
			'unit'		=> 'days',
			'default'	=> 90,
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
			'draws'			=> 'form .members-logout-form',
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

<?php
// The feature manifest - what the Features panel reads to list, activate
// and update this feature (see \Nino\Features and docs/features.md, "The
// manifest"). The class is not declared here: features/Sign/ can only ever
// serve \Nino\Modules\Sign.
return [
	'key'					=> 'sign',
	'name'				=> [ 'en_US' => 'Sign', 'de_DE' => 'Unterschrift' ],
	'description'	=> [
		'en_US' => 'A member signs a document of the PDF feature on a page of the website: the owner asks - in the panel or from code -, the member gets a link by mail, reads the document, ticks the sentence they agree to and signs by drawing on a pad or by typing their name. The document is made again with the signature on the fields of the member and a last page that records the signing - who, when, from where, which document by its hash, how, and the sentence agreed to -; both PDFs are kept and hashed, and the owner asks, reminds, cancels and reads in a panel. A simple electronic signature, no more.',
		'de_DE' => 'Ein Mitglied unterschreibt ein Dokument des Features PDF auf einer Seite der Website: Der Inhaber fragt an - im Panel oder aus dem Code -, das Mitglied bekommt einen Link per E-Mail, liest das Dokument, hakt den Satz an, dem es zustimmt, und unterschreibt, indem es auf einem Feld zeichnet oder seinen Namen tippt. Das Dokument wird noch einmal erzeugt, mit der Unterschrift auf den Feldern des Mitglieds und einer letzten Seite, die die Unterzeichnung festhält - wer, wann, von wo, welches Dokument anhand seines Hashs, wie, und der Satz, dem zugestimmt wurde -; beide PDFs werden aufbewahrt und mit einem Hash versehen, und der Inhaber fragt an, erinnert, zieht zurück und liest in einem Panel. Eine einfache elektronische Signatur, nicht mehr.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[sign-form]' => [
				'en_US' => 'The form of the signing page, which the route /.sign fills: the document in a frame with a link to open it, the sentence the member agrees to with a box to tick, a pad to draw the signature on - and under it a field to type a name, for a browser without the script or a pen -, and the buttons Sign and Decline. For a request that is over it shows its state and, once it is signed, the link to the signed document. It shows nothing on any other page. It is also the component Signature form of the Builder (icon: Lucide\'s pen-line), in a section; the Builder and the shortcode are one renderer. The words are the project\'s, in the Text panel: /feature/sign/form/ and /feature/sign/state/.',
				'de_DE' => 'Das Formular der Unterschriftsseite, die die Route /.sign füllt: das Dokument in einem Rahmen mit einem Link zum Öffnen, der Satz, dem das Mitglied zustimmt, mit einem Kästchen zum Anhaken, ein Feld zum Zeichnen der Unterschrift - und darunter ein Feld zum Tippen eines Namens, für einen Browser ohne das Skript oder einen Stift - und die Knöpfe Unterschreiben und Ablehnen. Für eine Anfrage, die vorbei ist, zeigt es ihren Zustand und, ist sie unterschrieben, den Link zum unterschriebenen Dokument. Auf jeder anderen Seite zeigt es nichts. Es ist auch die Komponente Unterschriftsformular des Builders (Icon: pen-line von Lucide), in einer Sektion; Builder und Shortcode sind ein Renderer. Die Worte gehören dem Projekt, im Panel Texte: /feature/sign/form/ und /feature/sign/state/.',
			],
			'[sign-list]' => [
				'en_US' => 'For the member who is signed in, the requests that were made of them, the newest first: the title, how it stands, the day, a link to the page of the request and, once it is signed, a link to the signed document. A line says so where there are none. Nothing for a visitor. It is also the component Signature list of the Builder (icon: Lucide\'s file-pen-line), in a section and a card.',
				'de_DE' => 'Für das angemeldete Mitglied die Anfragen, die an es gestellt wurden, die neueste zuerst: der Titel, wie sie steht, der Tag, ein Link zur Seite der Anfrage und, ist sie unterschrieben, ein Link zum unterschriebenen Dokument. Gibt es keine, sagt eine Zeile es. Für einen Besucher nichts. Es ist auch die Komponente Unterschriftenliste des Builders (Icon: file-pen-line von Lucide), in einer Sektion und einer Karte.',
			],
			'[sign-list open="0"]' => [
				'en_US' => 'With open off the signed requests are listed as well; with it on, which is the default, they are left out and the list is what still waits - or was declined, cancelled or ran out.',
				'de_DE' => 'Ist open aus, werden auch die unterschriebenen Anfragen aufgelistet; ist es an, der Standard, bleiben sie weg, und die Liste zeigt, was noch wartet - oder abgelehnt, zurückgezogen oder abgelaufen ist.',
			],
			'[sign-form ... class="my-class"]' => [
				'en_US' => 'A class of your own, added to those of the form or the list - both components take it. What the Builder writes for its custom classes.',
				'de_DE' => 'Eine eigene Klasse, zusätzlich zu denen des Formulars oder der Liste - beide Komponenten nehmen sie. Das schreibt der Builder für seine eigenen Klassen.',
			],
		],
		'markup' => [
			'Custom properties' => [
				'en_US' => 'sign.css declares the properties the form and the list are drawn from - --sign-ground with its parts (-on, -link, -border, -focus), --sign-space and --sign-corners - with the page\'s own roles as their defaults, in :where(), so that a look of the Design feature or a rule of the project moves them. The ground is the one the pad is painted on.',
				'de_DE' => 'sign.css deklariert die Properties, aus denen das Formular und die Liste gezeichnet werden - --sign-ground mit seinen Teilen (-on, -link, -border, -focus), --sign-space und --sign-corners -, mit den Rollen der Seite als Standard, in :where(), damit ein Look des Features Design oder eine Regel des Projekts sie verschiebt. Der Grund ist der, auf den das Feld zum Zeichnen gemalt ist.',
			],
			'.sign-form, .sign-pad, .sign-list' => [
				'en_US' => 'The classes of the markup: the form with its document frame, its sentence and its state line (.sign-form__state), the pad with its canvas and its typed-name field (.sign-pad), and the list with its rows (.sign-list__row, with data-sign-id and data-sign-state). The form is no .nino-form on purpose: the kernel\'s script posts such a form by XHR, and this one is answered by a redirect. The buttons are .nino-btn.',
				'de_DE' => 'Die Klassen des Markups: das Formular mit seinem Dokumentrahmen, seinem Satz und seiner Meldung (.sign-form__state), das Feld zum Zeichnen mit seinem Canvas und seinem Feld für den getippten Namen (.sign-pad) und die Liste mit ihren Zeilen (.sign-list__row, mit data-sign-id und data-sign-state). Das Formular ist absichtlich kein .nino-form: Das Skript des Kernels sendet ein solches Formular per XHR ab, dieses wird mit einer Umleitung beantwortet. Die Knöpfe sind .nino-btn.',
			],
			'data-sign-pad, data-sign-clear, data-sign-name' => [
				'en_US' => 'What sign.js looks for in the form: the canvas that is drawn on, the button that clears it and the field of the typed name, which the script hides while the pad holds a stroke. On submit the script writes the picture, a PNG data URL, into the hidden field image. Without the script the typed name signs.',
				'de_DE' => 'Wonach sich sign.js im Formular umsieht: der Canvas, auf dem gezeichnet wird, der Knopf, der ihn leert, und das Feld des getippten Namens, das das Skript versteckt, solange das Feld einen Strich hält. Beim Absenden schreibt das Skript das Bild, eine PNG-Data-URL, in das versteckte Feld image. Ohne das Skript unterschreibt der getippte Name.',
			],
			'templates/record.tpl' => [
				'en_US' => 'The record of a signing, the page that is appended to the signed PDF (Pdf\'s option append): the title, the number of the request, who signed, when (UTC), from which address and with which browser, the hash of the document as it was shown, how - drawn or typed - and the sentence the member agreed to. Its labels are /feature/sign/record/ and /feature/sign/method/ in the Text panel; it holds only what the engine of the Pdf feature draws.',
				'de_DE' => 'Der Beleg einer Unterzeichnung, die Seite, die an das unterschriebene PDF angehängt wird (die Option append von Pdf): der Titel, die Nummer der Anfrage, wer unterschrieben hat, wann (UTC), von welcher Adresse und mit welchem Browser, der Hash des Dokuments, wie es gezeigt wurde, wie - gezeichnet oder getippt - und der Satz, dem das Mitglied zugestimmt hat. Seine Beschriftungen sind /feature/sign/record/ und /feature/sign/method/ im Panel Texte; er enthält nur, was die Engine des Features PDF zeichnet.',
			],
		],
		'routes' => [
			'GET /.sign?t=<token>' => [
				'en_US' => 'The link of the mail. It changes nothing but the mark that the request was viewed (a HEAD marks nothing): a request that waits answers the page with the form (200, never stored, no referrer); one that was signed, declined, cancelled, changed or ran out answers it with that state and no form; a token nobody has answers it with the state invalid, a 404. ?return= names a path of the site the form takes the member back to.',
				'de_DE' => 'Der Link der Mail. Er ändert nichts außer der Markierung, dass die Anfrage angesehen wurde (ein HEAD markiert nichts): Eine Anfrage, die wartet, beantwortet die Seite mit dem Formular (200, nie gespeichert, ohne Referrer); eine unterschriebene, abgelehnte, zurückgezogene, geänderte oder abgelaufene beantwortet sie mit diesem Zustand und ohne Formular; ein Token, den niemand hat, mit dem Zustand invalid, einer 404. ?return= nennt einen Pfad der Website, auf den das Formular das Mitglied zurückbringt.',
			],
			'GET /.sign?r=<id>' => [
				'en_US' => 'The same page for a member who is signed in and the request is of. An id nobody has, one of another member and one a visitor asks for are all the same state invalid - nothing tells whether an id exists.',
				'de_DE' => 'Dieselbe Seite für ein angemeldetes Mitglied, dessen Anfrage es ist. Eine Id, die niemand hat, die eines anderen Mitglieds und eine, nach der ein Besucher fragt, sind alle derselbe Zustand invalid - nichts verrät, ob es eine Id gibt.',
			],
			'POST /.sign' => [
				'en_US' => 'The buttons of the form: t or r, action (sign or decline), consent, image (the PNG of the pad as a data URL) or name (typed), return and [csrf]. The visitor is counted (the setting attempts per hour). To sign, the box has to be ticked and a signature given; the document is made again and has to be the one that was shown; then signature.png and signed.pdf are written once and the status turns to signed. The answer is a redirect (303) to the return page, else to /.sign, with ?sign=<id>&sign-state=signed or declined - nothing in it carries the token. A refusal answers the page in place with its state: again (no csrf token, the session ran out, 403), limit (429), consent (400), empty (400), changed (409), write (500); expired is a link that ran out. A second signing changes nothing.',
				'de_DE' => 'Die Knöpfe des Formulars: t oder r, action (sign oder decline), consent, image (das PNG des Feldes als Data-URL) oder name (getippt), return und [csrf]. Der Besucher wird gezählt (die Einstellung Versuche pro Stunde). Zum Unterschreiben muss das Kästchen angehakt und eine Unterschrift gegeben sein; das Dokument wird noch einmal erzeugt und muss das sein, das gezeigt wurde; dann werden signature.png und signed.pdf einmal geschrieben, und der Status wird signed. Die Antwort ist eine Umleitung (303) auf die Rückkehrseite, sonst auf /.sign, mit ?sign=<id>&sign-state=signed oder declined - nichts darin trägt den Token. Eine Ablehnung beantwortet die Seite an Ort und Stelle mit ihrem Zustand: again (kein csrf-Token, die Sitzung ist abgelaufen, 403), limit (429), consent (400), empty (400), changed (409), write (500); expired ist ein Link, der abgelaufen ist. Eine zweite Unterschrift ändert nichts.',
			],
			'POST /.sign - the state changed' => [
				'en_US' => 'The document made again has to be the one that was shown. The PDF file carries no version of the PDF feature, so an update of that feature which leaves the layout of a document as it was does not end a pending request; a change to the template, a block, the layout (header, footer, logo, the owner\'s picture) or a site text the document reads does. The request is then over: status changed, with its time, nothing signed, the owner mailed (setting notifyOwner), the page says so with no form - not Decline either - and the panel row has Ask again.',
				'de_DE' => 'Das erneut erzeugte Dokument muss das sein, das gezeigt wurde. Die PDF-Datei trägt keine Version des Features PDF, ein Update davon, das das Layout eines Dokuments lässt, wie es war, beendet also keine offene Anfrage; eine Änderung an der Vorlage, einem Baustein, dem Layout (Kopf, Fuß, Logo, Bild des Inhabers) oder einem Text der Website, den das Dokument liest, schon. Die Anfrage ist dann vorbei: Status changed, mit Zeit, nichts unterschrieben, der Inhaber bekommt eine Mail (Einstellung notifyOwner), die Seite sagt es ohne Formular - auch ohne Ablehnen - und die Zeile im Panel hat Erneut anfragen.',
			],
			'GET /.sign/pdf?t=|r=&kind=shown|signed' => [
				'en_US' => 'A PDF of a request, shown in the browser; the kernel streams it. The holder of the token and the member it is of get shown while the request waits and both once it is signed; a workbench session that holds the permission of the panel Signatures (or one above it, such as \'/_admin/*\') gets any file there is, in any state, and the panel links its files here. A stranger, a token nobody has, a request that was declined, cancelled or ran out, and signed before the signing are all the same 404 with a plain body.',
				'de_DE' => 'Ein PDF einer Anfrage, im Browser angezeigt; der Kernel streamt es. Wer den Token hat, und das Mitglied, dem sie gilt, bekommen shown, solange die Anfrage wartet, und beide, sobald sie unterschrieben ist; eine Workbench-Sitzung mit der Berechtigung des Panels Unterschriften (oder einer darüber, etwa \'/_admin/*\') bekommt jede Datei, die es gibt, in jedem Zustand, und das Panel verlinkt seine Dateien hierher. Ein Fremder, ein Token, den niemand hat, eine Anfrage, die abgelehnt, zurückgezogen oder abgelaufen ist, und signed vor der Unterschrift sind alle dieselbe 404 mit schlichtem Text.',
			],
		],
		'panel' => [
			'Signatures' => [
				'en_US' => 'The requests, the newest first - the title, the member, how it stands, when it was asked, viewed, signed or declined - and on a row the record: the address, the browser, the hashes of the two PDFs and how it was signed; the two PDFs open from the row. A form asks a member to sign: the member, a template of the tags the setting lists, its free texts, a title. A request that waits can be reminded - a new link, a new time, the mail again - or cancelled; any request can be deleted, with its files. The Dashboard tile counts the requests that wait and the changed ones.',
				'de_DE' => 'Die Anfragen, die neueste zuerst - der Titel, das Mitglied, wie sie steht, wann sie gestellt, angesehen, unterschrieben oder abgelehnt wurde - und in einer Zeile der Beleg: die Adresse, der Browser, die Hashes der beiden PDFs und wie unterschrieben wurde; die beiden PDFs öffnen sich aus der Zeile. Ein Formular bittet ein Mitglied um eine Unterschrift: das Mitglied, eine Vorlage der Tags, die die Einstellung nennt, ihre Freitexte, ein Titel. Eine Anfrage, die wartet, kann erinnert - neuer Link, neue Zeit, die Mail noch einmal - oder zurückgezogen werden; jede Anfrage kann samt ihren Dateien gelöscht werden. Die Kachel des Dashboards zählt die Anfragen, die warten, und die geänderten.',
			],
			'Ask again, and the mail cap' => [
				'en_US' => 'A reminder goes to the page the request named. A request that is changed - its document was no longer the one shown when the member signed - has the button Ask again: a new request of the same template, data, member, title and page in one click, the old one staying as it is. The kernel\'s cap of mails - five an hour from one address - bounds how many requests the panel can send in an hour.',
				'de_DE' => 'Eine Erinnerung geht auf die Seite, die die Anfrage nannte. Eine geänderte Anfrage - ihr Dokument war beim Unterschreiben nicht mehr das gezeigte - hat den Knopf Erneut anfragen: eine neue Anfrage mit derselben Vorlage, denselben Daten, demselben Mitglied, demselben Titel und derselben Seite in einem Klick, die alte bleibt, wie sie ist. Die Obergrenze der Mails im Kernel - fünf pro Stunde von einer Adresse - begrenzt, wie viele Anfragen das Panel in einer Stunde senden kann.',
			],
			'Design: the look of the form' => [
				'en_US' => 'With the Design feature on, the tab Sign of the Design panel offers under Base the look Regular, the form and the list as the feature draws them without a theme, and its knobs stand under Surface (the space inside and the corners) and Colour (the ground the pad is painted on). The block design/preview.tpl shows the form and a row of the list in the preview.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Unterschrift des Design-Panels unter Grundlage den Look Regulär an, das Formular und die Liste, wie das Feature sie ohne ein Theme zeichnet, und seine Regler stehen unter Fläche (der Innenabstand und die Ecken) und Farbe (der Grund, auf den das Feld zum Zeichnen gemalt ist). Der Block design/preview.tpl zeigt in der Vorschau das Formular und eine Zeile der Liste.',
			],
		],
		'callbacks' => [
			'/sign/signed' => [
				'en_US' => 'Fired by this feature when a member has signed, with the request as the argument - an array of id, template, data, title, account, status, expires, created, viewed, signed, declined, changed, hashShown, hashSigned, method, record (ip, agent, consent) and page - for the features that build on it.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn ein Mitglied unterschrieben hat, mit der Anfrage als Argument - einem Array aus id, template, data, title, account, status, expires, created, viewed, signed, declined, changed, hashShown, hashSigned, method, record (ip, agent, consent) und page -, für die Features, die darauf aufbauen.',
			],
			'/sign/declined' => [
				'en_US' => 'Fired when a member declined, with the same request as the argument.',
				'de_DE' => 'Wird ausgelöst, wenn ein Mitglied abgelehnt hat, mit derselben Anfrage als Argument.',
			],
			'/nino/admin/restore' => [
				'en_US' => 'A restore of the daily backup: the backup carries the directory of the requests whole. The requests of the index whose shown.pdf is not there - in the backup or on the disk - are dropped, so that nobody is offered a document that is not there. Requests made after the day of the backup have no entry in it; their files stay where they are.',
				'de_DE' => 'Eine Wiederherstellung der täglichen Sicherung: Die Sicherung nimmt das Verzeichnis der Anfragen ganz mit. Die Anfragen des Index, deren shown.pdf nicht da ist - in der Sicherung oder auf der Platte -, fallen weg, damit niemandem ein Dokument angeboten wird, das nicht da ist. Anfragen nach dem Tag der Sicherung haben keinen Eintrag darin; ihre Dateien bleiben, wo sie sind.',
			],
		],
		'install' => [
			'templates/page-sign.tpl' => [
				'en_US' => 'The signing page: the site\'s frames, a title and [sign-form], which says what to do while there is something to sign. The route /.sign renders it in place, for the link of a mail, and the unit brings the details of that page - /_nino/webpage/.sign/name, /title and /description - so that its title is its own; a project that wants an address of its own for it gives it a route in the Routes panel.',
				'de_DE' => 'Die Unterschriftsseite: die Rahmen der Website, ein Titel und [sign-form], das sagt, was zu tun ist, solange es etwas zu unterschreiben gibt. Die Route /.sign rendert sie an Ort und Stelle, für den Link einer Mail, und die Einheit bringt die Angaben dieser Seite - /_nino/webpage/.sign/name, /title und /description -, damit ihr Titel ihr eigener ist; ein Projekt, das eine eigene Adresse dafür will, gibt ihr im Panel Routen eine Route.',
			],
			'text/<locale>.php' => [
				'en_US' => 'Its words, into the Text panel - those of the page, the form and its states, the list, the record and the three mails, with the sentence the member agrees to, which is the project\'s to change.',
				'de_DE' => 'Seine Worte, ins Panel Texte - die der Seite, des Formulars und seiner Zustände, der Liste, des Belegs und der drei Mails, mit dem Satz, dem das Mitglied zustimmt und den das Projekt ändern darf.',
			],
			'elements/privacy.php' => [
				'en_US' => 'Its section of the privacy policy, added to the Legal module\'s type - never replacing a section. Where there is no such module, nothing happens. A starting point, no legal advice. It names what is stored of a request - the document, the picture of the signature, the time, the address and the browser, the account, the hashes and the sentence agreed to -, until when (until the owner deletes the request), the mails, and fourteen days, the default of the setting days: whoever changes the setting changes the section in the Elements panel too.',
				'de_DE' => 'Sein Abschnitt der Datenschutzerklärung, dem Typ des Moduls Legal hinzugefügt - ohne einen Abschnitt zu ersetzen. Wo es das Modul nicht gibt, passiert nichts. Ein Ausgangspunkt, keine Rechtsberatung. Er nennt, was von einer Anfrage gespeichert wird - das Dokument, das Bild der Unterschrift, die Zeit, die Adresse und der Browser, das Konto, die Hashes und der Satz, dem zugestimmt wurde -, bis wann (bis der Inhaber die Anfrage löscht), die Mails und vierzehn Tage, den Standard der Einstellung days: Wer die Einstellung ändert, passt den Abschnitt im Panel Elemente mit an.',
			],
			'What the signature is' => [
				'en_US' => 'A simple electronic signature (eIDAS, Art. 25): a picture or a typed name, tied to a document by its hash and to its circumstances by the record - the account the link was sent to, the time, the address, the browser. It is not an advanced or a qualified signature and proves no more than that record does. The sentence the member agrees to and the section of the privacy policy are starting points, no legal advice. The backup carries the requests whole - the index and the files - in /data/sign.',
				'de_DE' => 'Eine einfache elektronische Signatur (eIDAS, Art. 25): ein Bild oder ein getippter Name, durch seinen Hash an ein Dokument und durch den Beleg an seine Umstände gebunden - das Konto, an das der Link ging, die Zeit, die Adresse, der Browser. Sie ist keine fortgeschrittene und keine qualifizierte Signatur und beweist nicht mehr, als dieser Beleg es tut. Der Satz, dem das Mitglied zustimmt, und der Abschnitt der Datenschutzerklärung sind Ausgangspunkte, keine Rechtsberatung. Die Sicherung nimmt die Anfragen ganz mit - den Index und die Dateien - in /data/sign.',
			],
		],
	],
	'category'		=> 'security',
	'version'			=> '1.0.0',
	'nino'				=> '^1.9.1',
	// gd draws the typed name into a picture and reads the picture of the pad; the kernel names it too, so that a reader sees what the writer needs
	'php'					=> [ 'ext' => [ 'gd' ] ],
	'requires'		=> [ 'pdf', 'members' ],
	// The index and one directory for each request, with its PDFs and the picture of the signature. The counter of the posts
	// lives beside them (sign-tries.php) and stays out of a backup: it is an hour long
	'data'				=> [ '/data/sign' ],
	// The site's stylesheet and script of the components, into the style and script bundles
	'assets'			=> [ '/features/Sign/assets/sign.css', '/features/Sign/assets/sign.js' ],
	'settings'		=> [
		'days' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Link valid for', 'de_DE' => 'Link gültig für' ],
			'hint'		=> [
				'en_US' => 'How long the link of a request can be used, in days; the member can read and sign until then, and a reminder gives it that time again. The section of the privacy policy names fourteen days - change it with this.',
				'de_DE' => 'Wie lange der Link einer Anfrage benutzt werden kann, in Tagen; bis dahin kann das Mitglied lesen und unterschreiben, und eine Erinnerung gibt ihm diese Zeit noch einmal. Der Abschnitt der Datenschutzerklärung nennt vierzehn Tage - ändere ihn mit.',
			],
			'min'			=> 1,
			'max'			=> 90,
			'unit'		=> 'days',
			'default'	=> 14,
		],
		'notifyOwner' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Mail the owner', 'de_DE' => 'Betreiber benachrichtigen' ],
			'hint'		=> [
				'en_US' => 'Mail the contact address of the website when a member signs or declines - one mail for each.',
				'de_DE' => 'Der Kontaktadresse der Website eine Mail schicken, wenn ein Mitglied unterschreibt oder ablehnt - eine Mail für jedes.',
			],
			'default'	=> true,
		],
		'attempts' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Posts', 'de_DE' => 'Absendungen' ],
			'hint'		=> [
				'en_US' => 'Posts of the signing page one visitor may send per hour - signing and declining, the refused ones too - before the page refuses for the rest of that hour. The address of the visitor is counted as a hash.',
				'de_DE' => 'Absendungen der Unterschriftsseite, die ein Besucher pro Stunde senden darf - Unterschreiben und Ablehnen, auch die abgewiesenen -, bevor die Seite für den Rest dieser Stunde verweigert. Die Adresse des Besuchers wird als Hash gezählt.',
			],
			'min'			=> 1,
			'max'			=> 100,
			'unit'		=> 'per-hour',
			'default'	=> 20,
		],
		'tags' => [
			'type'		=> 'lines',
			'label'		=> [ 'en_US' => 'Tags of the templates', 'de_DE' => 'Tags der Vorlagen' ],
			'hint'		=> [
				'en_US' => 'The tags of the PDF feature whose templates the panel offers to ask a signature for, one per line - lower case letters, digits and hyphens. A template of another tag can still be asked for from code, with Sign::request().',
				'de_DE' => 'Die Tags des Features PDF, deren Vorlagen das Panel für eine Unterschriftsanfrage anbietet, ein Tag pro Zeile - Kleinbuchstaben, Ziffern und Bindestriche. Eine Vorlage eines anderen Tags lässt sich weiter aus dem Code anfragen, mit Sign::request().',
			],
			'default'	=> [ 'contract', 'privacy' ],
		],
	],
	/*	Two components for the Builder, whose renderers are componentSignForm() and componentSignList() of the class: nothing of them
		is registered by init(). Neither reads a source - the request is the route's and the member the session's - and only the list
		has a choice, whether the signed requests are left out	*/
	'components'	=> [
		'sign-form' => [
			'label'			=> [ 'en_US' => 'Signature form', 'de_DE' => 'Unterschriftsformular' ],
			'source'		=> 'none',
			'loop'			=> false,
			'preview'		=> 'block',
			'draws'			=> 'div .sign-form',
			'in'				=> [ 'section' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M13 21h8' ] ],
				[ 'path', [ 'd' => 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' ] ],
			],
		],
		'sign-list' => [
			'label'			=> [ 'en_US' => 'Signature list', 'de_DE' => 'Unterschriftenliste' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [
				'open' => [
					'type'		=> 'bool',
					'default'	=> true,
					'label'		=> [ 'en_US' => 'Only what is not signed', 'de_DE' => 'Nur was nicht unterschrieben ist' ],
					'hint'		=> [
						'en_US' => 'Leave the signed requests out of the list.',
						'de_DE' => 'Die unterschriebenen Anfragen aus der Liste weglassen.',
					],
				],
			],
			'preview'		=> 'block',
			'draws'			=> 'div .sign-list',
			'in'				=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M14.364 13.634a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506l4.013-4.009a1 1 0 0 0-3.004-3.004z' ] ],
				[ 'path', [ 'd' => 'M14.487 7.858A1 1 0 0 1 14 7V2' ] ],
				[ 'path', [ 'd' => 'M20 19.645V20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l2.516 2.516' ] ],
				[ 'path', [ 'd' => 'M8 18h1' ] ],
			],
		],
	],
	'stacks'			=> [],
];

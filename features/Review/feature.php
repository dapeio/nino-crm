<?php
// The feature manifest - what the Features panel reads to list, activate
// and update this feature (see \Nino\Features and docs/features.md, "The
// manifest"). The class is not declared here: features/Review/ can only ever
// serve \Nino\Modules\Review.
return [
	'key'					=> 'review',
	'name'				=> [ 'en_US' => 'Review', 'de_DE' => 'Review' ],
	'description'	=> [
		'en_US' => 'A player for the audio and video files that the Files feature holds for the groups of the Members feature, with comments at a time in the track: a member plays a file, writes a comment at the second the player is at, answers other comments, and the owner reads, answers, marks as done and deletes in a panel - mails tell either side where the settings say so.',
		'de_DE' => 'Ein Player für die Audio- und Videodateien, die das Feature Dateien für die Gruppen des Features Mitglieder bereithält, mit Kommentaren an einer Stelle im Stück: Ein Mitglied spielt eine Datei ab, schreibt einen Kommentar an der Sekunde, bei der der Player steht, und antwortet auf andere Kommentare; der Betreiber liest, antwortet, hakt ab und löscht in einem Panel – Mails sagen beiden Seiten Bescheid, wo die Einstellungen es wollen.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[review-player]' => [
				'en_US' => 'For a member who is signed in. Without ?review= in the address: the audio and video files of their group that they may see (what the Files feature lets them), each a link to the same page with ?review=<id>. With it: the player of that file - the audio or video element of the browser with its controls, the source the route of the Files feature, which the kernel streams with its ranges, so that the player can seek -, the comments under it in the order of their time in the track, and the form to write one. A file the member may not see, one that is no audio or video and one whose bytes are gone show the list with a line that says so. Nothing for a visitor. It is also the component Review player of the Builder, in a section; the Builder and the shortcode are one renderer.',
				'de_DE' => 'Für ein angemeldetes Mitglied. Ohne ?review= in der Adresse: die Audio- und Videodateien seiner Gruppe, die es sehen darf (was das Feature Dateien ihm erlaubt), jede ein Link auf dieselbe Seite mit ?review=<Id>. Mit: der Player dieser Datei – das Audio- oder Video-Element des Browsers mit seinen Bedienelementen, als Quelle die Route des Features Dateien, die der Kernel mit ihren Ranges streamt, damit der Player springen kann –, darunter die Kommentare in der Reihenfolge ihrer Zeit im Stück und das Formular, um einen zu schreiben. Eine Datei, die das Mitglied nicht sehen darf, eine, die kein Audio oder Video ist, und eine, deren Bytes weg sind, zeigen die Liste mit einer Zeile, die das sagt. Für einen Besucher nichts. Es ist auch die Komponente Review-Player des Builders, in einer Sektion; Builder und Shortcode sind ein Renderer.',
			],
			'[review-player kind="audio"]' => [
				'en_US' => 'The list offers the audio files only (kind="video": the video files only; the default, all, offers both). It narrows the list and nothing else: a file chosen with ?review= is played whatever it is.',
				'de_DE' => 'Die Liste bietet nur die Audiodateien an (kind="video": nur die Videodateien; der Standard, all, bietet beide). Das engt die Liste ein und sonst nichts: Eine Datei, die mit ?review= gewählt ist, wird abgespielt, was auch immer sie ist.',
			],
			'[review-player ... class="my-class"]' => [
				'en_US' => 'A class of your own, added to those of the player and the list. What the Builder writes for its custom classes.',
				'de_DE' => 'Eine eigene Klasse, zusätzlich zu denen des Players und der Liste. Das schreibt der Builder für seine eigenen Klassen.',
			],
		],
		'markup' => [
			'Custom properties' => [
				'en_US' => 'review.css declares the properties the player, the list and the form are drawn from - --review-ground with its parts (-on, -link, -border, -focus), --review-space and --review-corners - with the page\'s own roles as their defaults, in :where(), so that a look of the Design feature or a rule of the project moves them.',
				'de_DE' => 'review.css deklariert die Properties, aus denen der Player, die Liste und das Formular gezeichnet werden – --review-ground mit seinen Teilen (-on, -link, -border, -focus), --review-space und --review-corners –, mit den Rollen der Seite als Standard, in :where(), damit ein Look des Features Design oder eine Regel des Projekts sie verschiebt.',
			],
			'.review-player, .review-list, .review-item, .review-comment, .review-answer, .review-form' => [
				'en_US' => 'The classes of the markup: the player (or the list, which is a .review-player as well), a file of the list, a comment with its answers, and the form with its state line (.review-state) and the switch of its time (.review-form__follow, drawn hidden: it does nothing without the script). The form is no .nino-form on purpose: the kernel\'s script posts such a form by XHR, and this one is a post of its own that is answered by a redirect.',
				'de_DE' => 'Die Klassen des Markups: der Player (oder die Liste, die auch ein .review-player ist), eine Datei der Liste, ein Kommentar mit seinen Antworten und das Formular mit seiner Meldung (.review-state) und dem Schalter seiner Zeit (.review-form__follow, verborgen gezeichnet: Er tut ohne das Skript nichts). Das Formular ist absichtlich kein .nino-form: Das Skript des Kernels sendet ein solches Formular per XHR ab, dieses hier ist ein Post für sich, der mit einer Umleitung beantwortet wird.',
			],
			'data-review-at, data-review-id' => [
				'en_US' => 'On a comment or an answer: its id, and - for one with a time - the second in the track, a number of at most one decimal, which review.js seeks the player to when the time is clicked. Without the script the time is a link to the file at that second (#t=), which the browser opens in its own player.',
				'de_DE' => 'An einem Kommentar oder einer Antwort: seine Id und – bei einem mit einer Zeit – die Sekunde im Stück, eine Zahl mit höchstens einer Nachkommastelle, auf die review.js den Player springen lässt, wenn die Zeit angeklickt wird. Ohne das Skript ist die Zeit ein Link auf die Datei an dieser Sekunde (#t=), den der Browser im eigenen Player öffnet.',
			],
		],
		'routes' => [
			'POST /.review/comment' => [
				'en_US' => 'A comment: file, text, at, parent and return, with [csrf] in the form - a form post. Without the script it is a plain post that reloads the page; with it, review.js sends the form with fetch() and takes only the list of the comments and the line of the state from the page the redirect leads to, so that the player plays on, and the switch Update the time writes the position of the player into the time field at the focus of the comment and when it is set on - a post it cannot read is sent the plain way. A player a page puts in later is bound with Nino.review.init( root ). A post without the csrf token is answered with a redirect (303) to the page the form was on, with ?review=<id>&review-state=expired, and counts for nothing. The visitor is counted (the setting attempts per hour); over it the state is limit. The member has to be signed in and the file one they may see: a visitor, a member of another group and an id nobody has are all the same 404 with a plain body.',
				'de_DE' => 'Ein Kommentar: file, text, at, parent und return, mit [csrf] im Formular. Ohne das Skript ist er ein einfacher Post, der die Seite neu lädt; mit ihm sendet review.js das Formular mit fetch() und übernimmt aus der Seite der Umleitung nur die Liste der Kommentare und die Meldung, damit der Player weiterläuft, und der Schalter Zeit aktualisieren schreibt die Position des Players ins Zeitfeld beim Fokus des Kommentars und beim Einschalten – eine Antwort, die es nicht lesen kann, sendet es auf dem einfachen Weg. Einen später eingesetzten Player bindet Nino.review.init( root ). Ein Post ohne den csrf-Token wird mit einer Umleitung (303) zur Seite des Formulars (?review=<Id>&review-state=expired) beantwortet und zählt nicht. Der Besucher wird gezählt (Versuche pro Stunde); darüber ist der Zustand limit. Das Mitglied muss angemeldet und die Datei eine sein, die es sehen darf: Besucher, Mitglied einer anderen Gruppe und unbekannte Id sind dieselbe 404 mit schlichtem Text.',
			],
			'POST /.review/comment (the answer)' => [
				'en_US' => 'A file that is no audio or video, an empty text, a time that is typed and is none (at is empty, seconds, m:ss or h:mm:ss) and a comment that is not there to answer are invalid; a text over the setting length is long. Else the comment is stored - with the account that wrote it, the second, the time of writing and the page - and the answer is the redirect (303) with ?review=<id>&review-state=sent. An answer to an answer is an answer to the comment that one answers.',
				'de_DE' => 'Eine Datei, die kein Audio oder Video ist, ein leerer Text, eine Zeit, die getippt ist und keine ist (at ist leer, Sekunden, m:ss oder h:mm:ss), und ein Kommentar, den es nicht mehr zu beantworten gibt, sind invalid; ein Text über der Einstellung Länge ist long. Sonst wird der Kommentar gespeichert – mit dem Konto, das ihn schrieb, der Sekunde, der Zeit des Schreibens und der Seite – und die Antwort ist die Umleitung (303) mit ?review=<Id>&review-state=sent. Eine Antwort auf eine Antwort ist eine Antwort auf den Kommentar, den diese beantwortet.',
			],
			'?review=<id>' => [
				'en_US' => 'The address of a page that carries [review-player]: the player of that file, as the shortcode says. ?review-state=sent, long, limit, expired or invalid is the line the form (or the list) says; any other word says nothing.',
				'de_DE' => 'Die Adresse einer Seite, die [review-player] trägt: der Player dieser Datei, wie der Shortcode es sagt. ?review-state=sent, long, limit, expired oder invalid ist die Zeile, die das Formular (oder die Liste) sagt; jedes andere Wort sagt nichts.',
			],
		],
		'panel' => [
			'Review' => [
				'en_US' => 'The files that have comments, by group and folder, with the number of comments and of the open ones - those of members that are not marked as done. A file opens to its player, which plays it through the route of the Files feature (that answers the workbench session of an account that holds the permission of the Files panel; without it the panel says so and draws no player), and the comments in the order of their time: click a time and the player goes there. For each comment: answer (with a time, if you want one, or at the position of the player), mark as done or open again, and delete - a comment goes with its answers. A comment of a file that Files no longer has is not listed.',
				'de_DE' => 'Die Dateien, die Kommentare haben, nach Gruppe und Ordner, mit der Zahl der Kommentare und der offenen – das sind die von Mitgliedern, die nicht abgehakt sind. Eine Datei öffnet sich zu ihrem Player, der sie über die Route des Features Dateien abspielt (die beantwortet die Workbench-Sitzung eines Kontos, das die Berechtigung des Panels Dateien hat; ohne sie sagt das Panel es und zeichnet keinen Player), und den Kommentaren in der Reihenfolge ihrer Zeit: Ein Klick auf eine Zeit, und der Player geht dorthin. Zu jedem Kommentar: antworten (mit einer Zeit, wenn Du eine willst, oder an der Stelle des Players), als erledigt abhaken oder wieder öffnen und löschen – ein Kommentar geht mit seinen Antworten. Ein Kommentar zu einer Datei, die es bei Dateien nicht mehr gibt, wird nicht aufgeführt.',
			],
			'Design: the look of the player' => [
				'en_US' => 'With the Design feature on, the tab Review of the Design panel offers under Base the look Regular, the player, the list and the form as the feature draws them without a theme, and its knobs stand under Surface (the space inside and the corners) and Colour (the ground they are painted on). The block design/preview.tpl shows a player with two comments and the form in the preview.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Review des Design-Panels unter Grundlage den Look Regulär an, den Player, die Liste und das Formular, wie das Feature sie ohne ein Theme zeichnet, und seine Regler stehen unter Fläche (der Innenabstand und die Ecken) und Farbe (der Grund, auf den sie gemalt sind). Der Block design/preview.tpl zeigt in der Vorschau einen Player mit zwei Kommentaren und das Formular.',
			],
		],
		'callbacks' => [
			'/review/comment' => [
				'en_US' => 'Fired by this feature when a member\'s comment is stored through the route, and when the owner\'s is stored in the panel, with the comment as the argument - an array of id, file, by (kind and id), at, text, parent, done, created and page - for the features that build on it.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn der Kommentar eines Mitglieds über die Route gespeichert wird und wenn der des Betreibers im Panel gespeichert wird, mit dem Kommentar als Argument – einem Array aus id, file, by (kind und id), at, text, parent, done, created und page –, für die Features, die darauf aufbauen.',
			],
			'/review/owner-mail' => [
				'en_US' => 'Fired by this feature before it mails the owner of a member\'s comment, with an array of file (the entry of Files), comment, link and about. A site that keeps the files in a place of its own sets link to the address of the comment there and about to one line that names what it is about: the mail then has the line in its subject and a button that says so. Left as it is, the mail links the panel Review.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, bevor es dem Betreiber den Kommentar eines Mitglieds mailt, mit einem Array aus file (der Eintrag von Dateien), comment, link und about. Eine Website, die die Dateien an einem eigenen Ort führt, setzt link auf die Adresse des Kommentars dort und about auf eine Zeile, die nennt, worum es geht: Die Mail trägt die Zeile dann im Betreff und hat einen Knopf, der das sagt. Bleibt es, wie es ist, verlinkt die Mail das Panel Review.',
			],
			'/files/delete' => [
				'en_US' => 'Hooked by this feature: the Files feature fires it for each file it deletes - in its panel, or with its folder - and the comments on that file are dropped with it.',
				'de_DE' => 'Von diesem Feature eingehängt: Das Feature Dateien löst es für jede Datei aus, die es löscht – in seinem Panel oder mit ihrem Ordner –, und die Kommentare zu dieser Datei fallen mit ihr weg.',
			],
			'/nino/admin/restore' => [
				'en_US' => 'A restore of the daily backup: the comments of the backup are kept for the files its index of the Files feature still has with their bytes - and for those removed from the server whose entry stays -, and the rest dropped. Where the backup carries no index of the files, the files the live Files feature has are the measure.',
				'de_DE' => 'Eine Wiederherstellung der täglichen Sicherung: Die Kommentare der Sicherung bleiben für die Dateien, die ihr Index des Features Dateien noch mit ihren Bytes hat – und für die, die vom Server entfernt wurden und deren Eintrag bleibt –, der Rest fällt weg. Wo die Sicherung keinen Index der Dateien enthält, sind die Dateien, die das Feature Dateien live hat, der Maßstab.',
			],
		],
		'install' => [
			'text/<locale>.php' => [
				'en_US' => 'Its words, into the Text panel - the list, the player, the form and its states, the words of the script, and the two mails.',
				'de_DE' => 'Seine Worte, ins Panel Texte – die Liste, der Player, das Formular und seine Meldungen, die Worte des Skripts und die beiden Mails.',
			],
			'elements/privacy.php' => [
				'en_US' => 'Its section of the privacy policy, added to the Legal module\'s type - never replacing a section. Where there is no such module, nothing happens. A starting point, no legal advice. It names what is stored of a comment - the account of the member, the second in the track, the time of writing and the page -, the mails the settings switch on and that the address of the visitor is counted, hashed, for an hour.',
				'de_DE' => 'Sein Abschnitt der Datenschutzerklärung, dem Typ des Moduls Legal hinzugefügt – ohne einen Abschnitt zu ersetzen. Wo es das Modul nicht gibt, passiert nichts. Ein Ausgangspunkt, keine Rechtsberatung. Er nennt, was von einem Kommentar gespeichert wird – das Konto des Mitglieds, die Sekunde im Stück, die Zeit des Schreibens und die Seite –, die Mails, die die Einstellungen einschalten, und dass die Adresse des Besuchers eine Stunde lang gezählt wird, als Hash.',
			],
			'page' => [
				'en_US' => 'The feature brings no page template: put [review-player] on a page behind a protected path of the Members feature - the page the Files browser stands on, or another. The comments (/data/review.php) are in the daily backup.',
				'de_DE' => 'Das Feature bringt keine Seitenvorlage mit: Setze [review-player] auf eine Seite hinter einem geschützten Pfad des Features Mitglieder – die Seite, auf der der Dateibrowser steht, oder eine andere. Die Kommentare (/data/review.php) gehören zur täglichen Sicherung.',
			],
		],
	],
	'category'		=> 'communication',
	'version'			=> '1.0.0',
	'nino'				=> '^1.9.1',
	'requires'		=> [ 'files' ],
	// The comments and nothing else: the counter of the posts lives beside them (review-tries.php) and is an hour long
	'data'				=> [ '/data/review.php' ],
	// The site's stylesheet and script of the player, into the style and script bundles
	'assets'			=> [ '/features/Review/assets/review.css', '/features/Review/assets/review.js' ],
	'settings'		=> [
		'notifyOwner' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Mail the owner', 'de_DE' => 'Betreiber benachrichtigen' ],
			'hint'		=> [
				'en_US' => 'Mail the contact address of the website when a member writes a comment - one mail for each comment.',
				'de_DE' => 'Der Kontaktadresse der Website eine Mail schicken, wenn ein Mitglied einen Kommentar schreibt – eine Mail für jeden Kommentar.',
			],
			'default'	=> false,
		],
		'notifyMembers' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Mail the members', 'de_DE' => 'Mitglieder benachrichtigen' ],
			'hint'		=> [
				'en_US' => 'Mail the member whose comment the owner answers in the panel, and every active member of the group for a comment of the owner on a file that is visible to them.',
				'de_DE' => 'Dem Mitglied eine Mail schicken, auf dessen Kommentar der Betreiber im Panel antwortet, und jedem aktiven Mitglied der Gruppe bei einem Kommentar des Betreibers zu einer Datei, die für sie sichtbar ist.',
			],
			'default'	=> false,
		],
		'length' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Longest comment', 'de_DE' => 'Längster Kommentar' ],
			'hint'		=> [
				'en_US' => 'How long a comment may be, in characters, line breaks counted. It holds for the members and for the owner.',
				'de_DE' => 'Wie lang ein Kommentar sein darf, in Zeichen, Zeilenumbrüche mitgezählt. Das gilt für die Mitglieder und für den Betreiber.',
			],
			'min'			=> 100,
			'max'			=> 5000,
			'unit'		=> 'characters',
			'default'	=> 2000,
		],
		'attempts' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Comments', 'de_DE' => 'Kommentare' ],
			'hint'		=> [
				'en_US' => 'Comments one visitor may send per hour before the form refuses for the rest of that hour. The address of the visitor is counted as a hash.',
				'de_DE' => 'Kommentare, die ein Besucher pro Stunde senden darf, bevor das Formular für den Rest der Stunde verweigert. Die Adresse des Besuchers wird als Hash gezählt.',
			],
			'min'			=> 1,
			'max'			=> 300,
			'unit'		=> 'per-hour',
			'default'	=> 60,
		],
	],
	/*	One component for the Builder, whose renderer is componentReviewPlayer() of the class: nothing of it is registered by
		init(). It reads no source - the signed-in member is the session's - and has one choice, which files the list offers	*/
	'components'	=> [
		'review-player' => [
			'label'			=> [ 'en_US' => 'Review player', 'de_DE' => 'Review-Player' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [
				'kind' => [
					'type'		=> 'select',
					'options'	=> [ 'all', 'audio', 'video' ],
					'default'	=> 'all',
					'input'		=> 'segment',
					'label'		=> [ 'en_US' => 'Files in the list', 'de_DE' => 'Dateien in der Liste' ],
					'hint'		=> [
						'en_US' => 'Which files the list offers: all, the audio files or the video files.',
						'de_DE' => 'Welche Dateien die Liste anbietet: alle, die Audiodateien oder die Videodateien.',
					],
				],
			],
			'preview'		=> 'block',
			'draws'			=> 'div .review-player',
			'in'				=> [ 'section' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z' ] ],
			],
		],
	],
	'stacks'			=> [],
];

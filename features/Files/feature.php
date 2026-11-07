<?php
// The feature manifest - what the Features panel reads to list, activate
// and update this feature (see \Nino\Features and docs/features.md, "The
// manifest"). The class is not declared here: features/Files/ can only ever
// serve \Nino\Modules\Files.
return [
	'key'					=> 'files',
	'name'				=> [ 'en_US' => 'Files', 'de_DE' => 'Dateien' ],
	'description'	=> [
		'en_US' => 'Folders and files for the groups of the Members feature: the owner uploads in the panel, a member uploads into the one folder of their group that is marked for it, and every member of the group downloads what is visible to them - uploads in chunks, downloads the kernel streams, audio and video included.',
		'de_DE' => 'Ordner und Dateien für die Gruppen des Features Mitglieder: Der Betreiber lädt im Panel hoch, ein Mitglied lädt in den einen Ordner seiner Gruppe hoch, der dafür markiert ist, und jedes Mitglied der Gruppe lädt herunter, was für es sichtbar ist – Uploads in Teilen, Downloads, die der Kernel streamt, Audio und Video eingeschlossen.',
	],
	'manual'			=> [
		'shortcodes' => [
			'[files-browser]' => [
				'en_US' => 'The folders of the group of the member who is signed in, each with the files that are visible to them: the name as a link to the file, its size, the day it was put there and a link that saves it. Nothing for a visitor. It is also the component File browser of the Builder, in a section and a card; the Builder and the shortcode are one renderer.',
				'de_DE' => 'Die Ordner der Gruppe des angemeldeten Mitglieds, jeder mit den Dateien, die für es sichtbar sind: der Name als Link zur Datei, ihre Größe, der Tag, an dem sie hinzukam, und ein Link, der sie speichert. Für einen Besucher nichts. Es ist auch die Komponente Dateibrowser des Builders, in einer Sektion und einer Karte; Builder und Shortcode sind ein Renderer.',
			],
			'[files-browser downloads="0"]' => [
				'en_US' => 'Without the link that saves a file beside the name; the name is a link to the file all the same.',
				'de_DE' => 'Ohne den Link zum Speichern neben dem Namen; der Name bleibt trotzdem ein Link zur Datei.',
			],
			'[files-upload]' => [
				'en_US' => 'The form a member uploads with, for a member whose group has an upload folder: a file input, a button, and a list in which a script shows the progress of each file. Nothing for a visitor and nothing for a group without an upload folder. It is also the component File upload of the Builder, in a section and a card.',
				'de_DE' => 'Das Formular, mit dem ein Mitglied hochlädt, für ein Mitglied, dessen Gruppe einen Upload-Ordner hat: ein Dateifeld, ein Knopf und eine Liste, in der ein Skript den Fortschritt jeder Datei zeigt. Für einen Besucher nichts und nichts für eine Gruppe ohne Upload-Ordner. Es ist auch die Komponente Datei-Upload des Builders, in einer Sektion und einer Karte.',
			],
			'[files-upload multiple="0"]' => [
				'en_US' => 'One file at a time: the script does not let a member choose several at once. A form without the script takes one file at a time anyway.',
				'de_DE' => 'Eine Datei nach der anderen: Das Skript lässt kein Mitglied mehrere auf einmal wählen. Ein Formular ohne das Skript nimmt ohnehin eine Datei nach der anderen.',
			],
			'[files-browser ... class="my-class"]' => [
				'en_US' => 'A class of your own, added to those of the browser or the form - both components take it. What the Builder writes for its custom classes.',
				'de_DE' => 'Eine eigene Klasse, zusätzlich zu denen des Browsers oder des Formulars – beide Komponenten nehmen sie. Das schreibt der Builder für seine eigenen Klassen.',
			],
		],
		'markup' => [
			'Custom properties' => [
				'en_US' => 'files.css declares the properties the two components are drawn from - --files-ground with its parts (-on, -link, -border, -focus), --files-space and --files-corners - with the page\'s own roles as their defaults, in :where(), so that a look of the Design feature or a rule of the project moves them.',
				'de_DE' => 'files.css deklariert die Properties, aus denen die beiden Komponenten gezeichnet werden – --files-ground mit seinen Teilen (-on, -link, -border, -focus), --files-space und --files-corners –, mit den Rollen der Seite als Standard, in :where(), damit ein Look des Features Design oder eine Regel des Projekts sie verschiebt.',
			],
			'.files-browser, .files-folder, .files-row, .files-upload' => [
				'en_US' => 'The classes of the markup: the browser, a folder with its title and its list, a row, and the form with its state line (.files-upload__state), its list of progress (.files-upload__list) and its hint. A row carries data-files-id and data-files-mime, the id and the MIME type of its file, for the features and scripts that build on it. The form is no .nino-form on purpose: the kernel\'s script posts such a form by XHR.',
				'de_DE' => 'Die Klassen des Markups: der Browser, ein Ordner mit Titel und Liste, eine Zeile und das Formular mit seiner Meldung (.files-upload__state), seiner Fortschrittsliste (.files-upload__list) und seinem Hinweis. Eine Zeile trägt data-files-id und data-files-mime, die Id und den MIME-Typ ihrer Datei, für die Features und Skripte, die darauf aufbauen. Das Formular ist absichtlich kein .nino-form: Das Skript des Kernels sendet ein solches Formular per XHR ab.',
			],
			'Title.03.ext' => [
				'en_US' => 'A name that says a version: the title, a dot, one to three digits, a dot and the extension (TobiaRegner-Istillburn.03.wav). Files of one folder with the same title - what stands before the version, dots among it, in the case it stands in - and the same extension are the versions of one title; Song.03.wav and Song.03.mp3 are two titles, a name that says no version is a title of its own, and one that belongs to a title with versions is the oldest of them. Nothing is stored for it: Files::version( $name ) reads a name and Files::grouped( $files ) puts the files of one folder together - the titles by the day of the version they show, the versions from the highest number down - for the features that list them, which a title spanning two folders never does.',
				'de_DE' => 'Ein Name, der eine Version sagt: der Titel, ein Punkt, ein bis drei Ziffern, ein Punkt und die Endung (TobiaRegner-Istillburn.03.wav). Dateien eines Ordners mit demselben Titel - was vor der Version steht, Punkte darin eingeschlossen, in der Schreibweise, wie es dasteht - und derselben Endung sind die Versionen eines Titels; Song.03.wav und Song.03.mp3 sind zwei Titel, ein Name, der keine Version sagt, ist ein Titel für sich, und gehört er zu einem Titel mit Versionen, ist er dessen älteste. Dafür wird nichts gespeichert: Files::version( $name ) liest einen Namen, und Files::grouped( $files ) bringt die Dateien eines Ordners zusammen - die Titel nach dem Tag der Version, die sie zeigen, die Versionen von der höchsten Nummer abwärts - für die Features, die sie auflisten; ein Titel über zwei Ordner kommt dabei nie vor.',
			],
			'data-files-chunk, data-files-max' => [
				'en_US' => 'On the form: the size of one chunk of an upload and of the largest file, in bytes, which files.js reads. The chunk is the setting chunk, held under what php takes in one request; it is where an upload starts, and a web server that takes less makes the script halve it.',
				'de_DE' => 'Am Formular: die Größe eines Teils eines Uploads und der größten Datei, in Byte, die files.js liest. Der Teil ist die Einstellung chunk, unter dem gehalten, was php in einer Anfrage nimmt; damit beginnt ein Upload, und nimmt der Webserver weniger, halbiert das Skript ihn.',
			],
		],
		'routes' => [
			'GET /.files/<id>' => [
				'en_US' => 'A file, for a member of the group of its folder if the file is visible, and for the member who uploaded it. The kernel streams it - the length and the ranges are its, so that a browser can play a long audio file - with the type of the index, shown for audio, video, images and pdf and saved for the rest, and always saved with ?download=1, under the cleaned name. A file removed from the server is answered 410 with a sentence where it would be served. A visitor, a member of another group and an id nobody has are all the same 404 with a plain body. The workbench session of the owner is answered as well, with any file that has bytes, a hidden one and one of any group included, if the account holds the permission of the panel Files (or a permission above it, such as \'/_admin/*\'); the panel links its files to this route. An account at the workbench without that permission is a stranger.',
				'de_DE' => 'Eine Datei, für ein Mitglied der Gruppe ihres Ordners, wenn die Datei sichtbar ist, und für das Mitglied, das sie hochgeladen hat. Der Kernel streamt sie – Länge und Ranges sind seine, damit ein Browser eine lange Audiodatei abspielen kann – mit dem Typ aus dem Index, angezeigt bei Audio, Video, Bildern und PDF und gespeichert bei allem anderen, und mit ?download=1 immer gespeichert, unter dem bereinigten Namen. Eine vom Server entfernte Datei: 410 mit einem Satz. Ein Besucher, ein Mitglied einer anderen Gruppe und eine Id, die niemand hat, sind alle dieselbe 404 mit schlichtem Text. Die Workbench-Sitzung des Betreibers wird ebenfalls beantwortet, mit jeder Datei, die Bytes hat, auch einer versteckten und einer aus jeder Gruppe, wenn das Konto die Berechtigung des Panels Dateien hat (oder eine darüber, etwa \'/_admin/*\'); das Panel verlinkt seine Dateien auf diese Route. Ein Konto an der Workbench ohne diese Berechtigung ist ein Fremder.',
			],
			'POST /.files/upload' => [
				'en_US' => 'One chunk of a member\'s upload: folder, name, size, upload, index and count, the bytes as the file part chunk, and [csrf]. The member has to be signed in and the folder the one their group uploads into; the types, the size and the quota of the group are checked, and the visitor is counted (attempts per hour). The first chunk sets the chunk size of the upload (up to the setting chunk), every chunk but the last is exactly that long, count is the number it makes, and they come in order (a script whose chunk the web server refuses with 413 starts a new upload in half the size); the last assembles: length, the type finfo reads and the sha256 are checked. Answers json - the upload id, then id, name and size - or a code: files_folder (404), files_type, files_size, files_quota, files_chunk (400), files_limit (429), files_write (500).',
				'de_DE' => 'Ein Teil des Uploads eines Mitglieds: folder, name, size, upload, index und count, die Bytes als Dateiteil chunk und [csrf]. Das Mitglied muss angemeldet und der Ordner der sein, in den seine Gruppe hochlädt; Typen, Größe und Kontingent der Gruppe werden geprüft, der Besucher wird gezählt (Versuche pro Stunde). Das erste Teil legt die Teilgröße des Uploads fest (bis zur Einstellung chunk), jedes Teil außer dem letzten ist genau so lang, count ist die Zahl, die das ergibt, und sie kommen der Reihe nach (weist der Webserver ein Teil mit 413 ab, beginnt das Skript einen neuen Upload in halber Größe); das letzte setzt zusammen: Länge, der Typ, den finfo liest, und die sha256 werden geprüft. Antwortet mit json – der Upload-Id, dann id, name und size – oder mit einem Code: files_folder (404), files_type, files_size, files_quota, files_chunk (400), files_limit (429), files_write (500).',
			],
			'POST /.files/upload (a plain form)' => [
				'en_US' => 'The same request without the script: one chunk that is the whole file, taken with its own name and size, count 1. A refusal or the success is answered with a redirect (303) to the page the form was on, with ?files=sent or ?files=<code>, which the form shows as a state line. A post without the csrf token is a 403 as everywhere.',
				'de_DE' => 'Dieselbe Anfrage ohne das Skript: ein Teil, das die ganze Datei ist, mit eigenem Namen und eigener Größe, count 1. Eine Ablehnung oder der Erfolg wird mit einer Umleitung (303) auf die Seite des Formulars beantwortet, mit ?files=sent oder ?files=<Code>, was das Formular als Meldung zeigt. Ein Post ohne den csrf-Token ist wie überall eine 403.',
			],
		],
		'panel' => [
			'Files' => [
				'en_US' => 'A select of the groups of the Members feature; the folders of the group (add, rename, delete - a folder with files asks first and deletes its files -, mark as the upload folder or take the mark off); the files of a folder (name, size, who, day, whether visible to the members, a link that downloads it, move to another folder of the group, remove from the server, delete - a removed file keeps its row with the day its bytes went, files/remove, and has no download, no switch and no move any more -); and the owner\'s upload, in the same chunks, with the limits of the server in a line of its own. The bytes are not in the daily backup, the index is: the panel says so.',
				'de_DE' => 'Ein Auswahlfeld der Gruppen des Features Mitglieder; die Ordner der Gruppe (anlegen, umbenennen, löschen – ein Ordner mit Dateien fragt vorher und löscht seine Dateien –, als Upload-Ordner markieren oder die Markierung abnehmen); die Dateien eines Ordners (Name, Größe, wer, Tag, ob für die Mitglieder sichtbar, ein Link, der sie herunterlädt, in einen anderen Ordner der Gruppe verschieben, vom Server entfernen, löschen – eine entfernte Datei behält ihre Zeile mit dem Tag, an dem ihre Bytes gingen, files/remove, und hat keinen Download, keinen Schalter und kein Verschieben mehr –); und der Upload des Betreibers, in denselben Teilen, mit den Grenzen des Servers in einer eigenen Zeile. Die Bytes gehören nicht zur täglichen Sicherung, der Index schon: Das Panel sagt es.',
			],
			'The mail to the group: the page it links' => [
				'en_US' => 'The mail that tells a group of new files links the page members land on after they sign in. A caller that knows a better page names it: the actions files/upload, files/save and files/notify of the panel take the field page, and notifyMembers() of the class takes it as its fifth argument, a path of this site - a single slash first, no blank or control character, no colon before the next slash, no endpoint of a module (/.), at most 500 characters, its query included. A value that is none of that is the page members land on.',
				'de_DE' => 'Die Mail, die einer Gruppe neue Dateien meldet, verlinkt die Seite, auf der die Mitglieder nach der Anmeldung landen. Ein Aufrufer, der eine bessere Seite kennt, nennt sie: Die Aktionen files/upload, files/save und files/notify des Panels nehmen das Feld page, und notifyMembers() der Klasse nimmt es als fünftes Argument, einen Pfad dieser Website – ein einzelner Schrägstrich zuerst, kein Leerzeichen und kein Steuerzeichen, kein Doppelpunkt vor dem nächsten Schrägstrich, kein Endpunkt eines Moduls (/.), höchstens 500 Zeichen, die Query eingeschlossen. Ein Wert, auf den nichts davon zutrifft, ist die Seite, auf der die Mitglieder landen.',
			],
			'The mail to the group: one mail for an upload' => [
				'en_US' => 'The files of one upload of the owner are put in one after the other, each with notify false, and the action files/notify tells the group of all of them in one mail: ids is the list of their ids (at most 200, all kept, visible and in one folder - anything else is 404 files_unknown, and nobody is mailed), notify and page as in files/upload. The answer is mailed, the number of mails delivered.',
				'de_DE' => 'Die Dateien eines Uploads des Betreibers werden nacheinander eingestellt, jede mit notify false, und die Aktion files/notify meldet der Gruppe alle in einer Mail: ids ist die Liste ihrer Kennungen (höchstens 200, alle vorhanden, sichtbar und in einem Ordner – alles andere ist 404 files_unknown, und niemand bekommt eine Mail), notify und page wie bei files/upload. Die Antwort ist mailed, die Zahl der zugestellten Mails.',
			],
			'Design: the look of the rows and the form' => [
				'en_US' => 'With the Design feature on, the tab Files of the Design panel offers under Base the look Regular, the browser and the form as the feature draws them without a theme, and its knobs stand under Surface (the space inside and the corners) and Colour (the ground they are painted on). The block design/preview.tpl shows a folder with three rows and the form in the preview.',
				'de_DE' => 'Ist das Feature Design an, bietet der Tab Dateien des Design-Panels unter Grundlage den Look Regulär an, den Browser und das Formular, wie das Feature sie ohne ein Theme zeichnet, und seine Regler stehen unter Fläche (der Innenabstand und die Ecken) und Farbe (der Grund, auf den sie gemalt sind). Der Block design/preview.tpl zeigt in der Vorschau einen Ordner mit drei Zeilen und das Formular.',
			],
		],
		'callbacks' => [
			'/files/upload' => [
				'en_US' => 'Fired by this feature when a member\'s upload is complete, with the entry of the file as the argument - an array of id, folder, name, ext, size, mime, sha256, by (kind and id), visible and created - for the features that build on it.',
				'de_DE' => 'Wird von diesem Feature ausgelöst, wenn der Upload eines Mitglieds vollständig ist, mit dem Eintrag der Datei als Argument – einem Array aus id, folder, name, ext, size, mime, sha256, by (kind und id), visible und created –, für die Features, die darauf aufbauen.',
			],
			'/files/add' => [
				'en_US' => 'Fired when the owner\'s upload in the panel is complete, with the same entry as the argument.',
				'de_DE' => 'Wird ausgelöst, wenn der Upload des Betreibers im Panel vollständig ist, mit demselben Eintrag als Argument.',
			],
			'/files/delete' => [
				'en_US' => 'Fired when a file is deleted in the panel - and for each file of a folder that is deleted with its files - with the entry as it was.',
				'de_DE' => 'Wird ausgelöst, wenn im Panel eine Datei gelöscht wird – und für jede Datei eines Ordners, der mit seinen Dateien gelöscht wird –, mit dem Eintrag, wie er war.',
			],
			'/files/allowed' => [
				'en_US' => 'Fired by allowed() - the rule for the member who is signed in, which the routes, the browser component and the features that build on this one ask - when the rule would say yes, with the argument file (the entry), member (the account), download (whether the file is asked for to be saved, ?download=1) and allowed (true). A callback may set allowed to false to withhold the file from this member; it can never set it to true, and a no of the rule is not asked about. The owner\'s access by the permission of the panel does not go through it.',
				'de_DE' => 'Wird von allowed() ausgelöst – der Regel für das angemeldete Mitglied, die die Routen, die Browser-Komponente und die Features, die darauf aufbauen, abfragen –, wenn die Regel Ja sagen würde, mit dem Argument file (der Eintrag), member (das Konto), download (ob die Datei zum Speichern abgerufen wird, ?download=1) und allowed (true). Ein Callback darf allowed auf false setzen, um dem Mitglied die Datei vorzuenthalten; auf true setzen kann er es nie, und ein Nein der Regel wird nicht erst gefragt. Der Zugriff des Betreibers über die Berechtigung des Panels läuft nicht darüber.',
			],
			'/nino/admin/restore' => [
				'en_US' => 'A restore of the daily backup: the bytes are not in it, so the index of a day may name files whose bytes are gone. The entries whose bytes are there are kept and the rest dropped, so that no member is offered a file that is not there; an entry that was removed from the server has no bytes and stays as removed, and one the live index has removed since the backup is taken over as removed. Files put there after the day of the backup have no entry in it; their bytes stay where they are.',
				'de_DE' => 'Eine Wiederherstellung der täglichen Sicherung: Die Bytes sind nicht darin, der Index eines Tages kann also Dateien nennen, deren Bytes weg sind. Die Einträge, deren Bytes da sind, bleiben, der Rest fällt weg, damit keinem Mitglied eine Datei angeboten wird, die nicht da ist; ein Eintrag, der vom Server entfernt wurde, hat keine Bytes und bleibt als entfernt, und einer, den der aktuelle Index seit der Sicherung entfernt hat, wird als entfernt übernommen. Dateien, die nach dem Tag der Sicherung hinzukamen, haben keinen Eintrag darin; ihre Bytes bleiben, wo sie sind.',
			],
		],
		'install' => [
			'text/<locale>.php' => [
				'en_US' => 'Its words, into the Text panel - the units of a size, the states of the form and the refusals of an upload, the words of the script, and the two mails.',
				'de_DE' => 'Seine Worte, ins Panel Texte – die Einheiten einer Größe, die Meldungen des Formulars und die Ablehnungen eines Uploads, die Worte des Skripts und die beiden Mails.',
			],
			'elements/privacy.php' => [
				'en_US' => 'Its section of the privacy policy, added to the Legal module\'s type - never replacing a section. Where there is no such module, nothing happens. A starting point, no legal advice. It names what is stored of a file, who sees it, that downloads are not logged and that the address of the visitor is counted, hashed, for an hour.',
				'de_DE' => 'Sein Abschnitt der Datenschutzerklärung, dem Typ des Moduls Legal hinzugefügt – ohne einen Abschnitt zu ersetzen. Wo es das Modul nicht gibt, passiert nichts. Ein Ausgangspunkt, keine Rechtsberatung. Er nennt, was von einer Datei gespeichert wird, wer sie sieht, dass Downloads nicht protokolliert werden und dass die Adresse des Besuchers eine Stunde lang gezählt wird, als Hash.',
			],
			'backup' => [
				'en_US' => 'The index (/data/files.php) is in the daily backup; the files themselves (/data/files/) are not - they are backed up with the server. A page with the two components stands where the Builder puts it, behind the protected paths of the Members feature; the feature brings no page of its own.',
				'de_DE' => 'Der Index (/data/files.php) gehört zur täglichen Sicherung; die Dateien selbst (/data/files/) nicht – sie werden mit dem Server gesichert. Eine Seite mit den beiden Komponenten steht, wo der Builder sie hinsetzt, hinter den geschützten Pfaden des Features Mitglieder; das Feature bringt keine Seite mit.',
			],
		],
	],
	'category'		=> 'content',
	'version'			=> '1.0.0',
	'nino'				=> '^1.9.1',
	'php'					=> [ 'ext' => [ 'fileinfo' ] ],
	'requires'		=> [ 'members' ],
	// The index and nothing else: the files themselves (/data/files/) are far too large for a daily backup and are backed
	// up with the server, and the counter of the chunk requests lives beside the index (files-tries.php) and is an hour long
	'data'				=> [ '/data/files.php' ],
	// The site's stylesheet and script of the components, into the style and script bundles
	'assets'			=> [ '/features/Files/assets/files.css', '/features/Files/assets/files.js' ],
	'settings'		=> [
		'types' => [
			'type'	=> 'lines',
			'label'	=> [ 'en_US' => 'File types', 'de_DE' => 'Dateitypen' ],
			'hint'	=> [
				'en_US' => 'The extensions that may be uploaded, one per line, without the dot, in lower case. Only extensions the feature knows are taken, and the bytes of a file have to be what its extension says: mp3 wav flac aiff aif m4a ogg opus aac mp4 m4v mov webm zip pdf jpg jpeg png gif webp txt md csv.',
				'de_DE' => 'Die Endungen, die hochgeladen werden dürfen, eine pro Zeile, ohne Punkt, klein geschrieben. Es werden nur Endungen genommen, die das Feature kennt, und die Bytes einer Datei müssen sein, was ihre Endung sagt: mp3 wav flac aiff aif m4a ogg opus aac mp4 m4v mov webm zip pdf jpg jpeg png gif webp txt md csv.',
			],
			'default'	=> [ 'mp3', 'wav', 'flac', 'aiff', 'aif', 'm4a', 'ogg', 'mp4', 'mov', 'zip', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt' ],
		],
		'maxSize' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Largest file', 'de_DE' => 'Größte Datei' ],
			'hint'		=> [
				'en_US' => 'How large one file may be, in megabytes. A file is cut into chunks, so it is not bound by the post size of the server.',
				'de_DE' => 'Wie groß eine Datei sein darf, in Megabyte. Eine Datei wird in Teile geschnitten, die Post-Größe des Servers bindet sie also nicht.',
			],
			'min'			=> 1,
			'max'			=> 4096,
			'unit'		=> 'megabytes',
			'default'	=> 500,
		],
		'chunk' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Size of a chunk', 'de_DE' => 'Größe eines Teils' ],
			'hint'		=> [
				'en_US' => 'How much of a file one request carries, in megabytes. It has to stay below upload_max_filesize and post_max_size of the server, which the panel shows; a larger value is held under them. A limit of the web server below the chunk (client_max_body_size of nginx, LimitRequestBody of Apache, a proxy of the host) is invisible to php: the upload finds it itself and halves its chunks when the server answers 413 - every halving multiplies the requests, which count against the setting for the requests per hour. A file of the largest size in chunks of this size takes as many requests as the setting for the requests per hour allows.',
				'de_DE' => 'Wie viel von einer Datei eine Anfrage trägt, in Megabyte. Der Wert muss unter upload_max_filesize und post_max_size des Servers bleiben, die das Panel zeigt; ein größerer Wert wird darunter gehalten. Eine Grenze des Webservers unterhalb des Teils (client_max_body_size von nginx, LimitRequestBody von Apache, ein Proxy des Hosters) sieht php nicht: Der Upload findet sie selbst und halbiert seine Teile, wenn der Server mit 413 antwortet – jede Halbierung vervielfacht die Anfragen, die auf die Einstellung für die Anfragen pro Stunde zählen. Eine Datei der größten Größe in Teilen dieser Größe braucht so viele Anfragen, wie die Einstellung für die Anfragen pro Stunde erlaubt.',
			],
			'min'			=> 1,
			'max'			=> 32,
			'unit'		=> 'megabytes',
			'default'	=> 4,
		],
		'quota' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Quota per group', 'de_DE' => 'Kontingent je Gruppe' ],
			'hint'		=> [
				'en_US' => 'How much the files of one group may take together, in megabytes. 0 is no limit.',
				'de_DE' => 'Wie viel die Dateien einer Gruppe zusammen belegen dürfen, in Megabyte. 0 heißt unbegrenzt.',
			],
			'min'			=> 0,
			'max'			=> 100000,
			'unit'		=> 'megabytes',
			'default'	=> 0,
		],
		'attempts' => [
			'type'		=> 'int',
			'label'		=> [ 'en_US' => 'Requests', 'de_DE' => 'Anfragen' ],
			'hint'		=> [
				'en_US' => 'Chunk requests one visitor may send per hour before the upload refuses for the rest of that hour. The address of the visitor is counted as a hash.',
				'de_DE' => 'Teile-Anfragen, die ein Besucher pro Stunde senden darf, bevor der Upload für den Rest dieser Stunde verweigert. Die Adresse des Besuchers wird als Hash gezählt.',
			],
			'min'			=> 1,
			'max'			=> 1000,
			'unit'		=> 'per-hour',
			'default'	=> 300,
		],
		'notifyMembers' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Mail the group', 'de_DE' => 'Gruppe benachrichtigen' ],
			'hint'		=> [
				'en_US' => 'Mail every active member of a group when the owner uploads a file that is visible or makes one visible. The files of one request in the panel are one mail.',
				'de_DE' => 'Jedem aktiven Mitglied einer Gruppe eine Mail schicken, wenn der Betreiber eine sichtbare Datei hochlädt oder eine sichtbar macht. Die Dateien einer Anfrage im Panel sind eine Mail.',
			],
			'default'	=> false,
		],
		'notifyOwner' => [
			'type'		=> 'bool',
			'label'		=> [ 'en_US' => 'Mail the owner', 'de_DE' => 'Betreiber benachrichtigen' ],
			'hint'		=> [
				'en_US' => 'Mail the contact address of the website when a member uploads a file - one mail for each upload.',
				'de_DE' => 'Der Kontaktadresse der Website eine Mail schicken, wenn ein Mitglied eine Datei hochlädt – eine Mail für jeden Upload.',
			],
			'default'	=> false,
		],
	],
	/*	Two components for the Builder, whose renderers are componentFilesBrowser() and componentFilesUpload() of the class:
		nothing of them is registered by init(). Neither reads a source - the signed-in member is the session's - and each
		has one choice, whether the browser has the link that saves a file and whether the form takes several files at once	*/
	'components'	=> [
		'files-browser' => [
			'label'			=> [ 'en_US' => 'File browser', 'de_DE' => 'Dateibrowser' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [
				'downloads' => [
					'type'		=> 'bool',
					'default'	=> true,
					'label'		=> [ 'en_US' => 'Link to save', 'de_DE' => 'Link zum Speichern' ],
					'hint'		=> [
						'en_US' => 'A link beside the name that saves the file instead of opening it.',
						'de_DE' => 'Ein Link neben dem Namen, der die Datei speichert, statt sie zu öffnen.',
					],
				],
			],
			'preview'		=> 'block',
			'draws'			=> 'div .files-browser',
			'in'				=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z' ] ],
			],
		],
		'files-upload' => [
			'label'			=> [ 'en_US' => 'File upload', 'de_DE' => 'Datei-Upload' ],
			'source'		=> 'none',
			'loop'			=> false,
			'attributes'	=> [
				'multiple' => [
					'type'		=> 'bool',
					'default'	=> true,
					'label'		=> [ 'en_US' => 'Several files', 'de_DE' => 'Mehrere Dateien' ],
					'hint'		=> [
						'en_US' => 'The script lets a member choose several files at once.',
						'de_DE' => 'Das Skript lässt ein Mitglied mehrere Dateien auf einmal wählen.',
					],
				],
			],
			'preview'		=> 'block',
			'draws'			=> 'form .files-upload',
			'in'				=> [ 'section', 'card' ],
			'icon'			=> [
				[ 'path', [ 'd' => 'M12 3v12' ] ],
				[ 'path', [ 'd' => 'm17 8-5-5-5 5' ] ],
				[ 'path', [ 'd' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' ] ],
			],
		],
	],
	'stacks'			=> [],
];

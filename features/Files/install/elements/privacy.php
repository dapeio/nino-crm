<?php
// features/Files/install/elements/privacy.php - this feature's section of the privacy
// policy, in German and English, written for it. Added to the type 'privacy' of Nino's
// Legal module by \Nino\Features::activate() through the unit's 'elements' key, which
// only ever adds (see \Nino\Elements::seed()): a section an editor changed or deleted is
// not touched again, and a Nino without the module ignores the key. There is no 'model'
// on purpose - the type is the module's and never created from here.
//
// 'order' is the position among the module's sections, further features stand in
// 800-899. The text is in the form the sanitizer gives a field with 'blocks' and carries
// no '&', no entity and no '['. It names what the code does: the file with its name, its
// size, its type and its sha256, the account of the member who uploaded it (the owner's
// uploads name none) and the time; the members who see it - the group of the folder, and
// the member who uploaded it; that the website writes no log of a download; the counter of
// the chunk requests per visitor, kept for an hour (Files::WINDOW) as a hash; and the mails
// the settings notifyOwner and notifyMembers switch on, both off by default.
return [
	'*' => [
		'files' => [
			'order' => 840,
		],
	],
	'de_DE' => [
		'files' => [
			'title' => 'Dateien für Mitglieder',
			'text' 	=> '<p>Für Mitglieder stellt diese Website Dateien bereit, die in Ordnern der Mitgliedergruppen liegen. Zu jeder Datei speichern wir die Datei selbst, ihren Namen, ihre Größe, ihren Typ, einen Hashwert (SHA-256) ihres Inhalts, den Zeitpunkt, zu dem sie hinzukam, und - wenn ein Mitglied sie hochgeladen hat - das Konto dieses Mitglieds. Die Dateien liegen in einem Verzeichnis des Servers, das nicht ausgeliefert wird; abgerufen werden sie nur über die Website.</p><p>Sehen und herunterladen kann eine Datei, wer als Mitglied der Gruppe des Ordners angemeldet ist, solange die Datei sichtbar geschaltet ist, und das Mitglied, das sie hochgeladen hat. Alle anderen erhalten keinen Hinweis darauf, dass es sie gibt. Ein Download wird von der Website nicht protokolliert. Damit der Upload nicht missbraucht wird, zählen wir die Anfragen eines Besuchers eine Stunde lang; seine IP-Adresse wird dafür nur als Hashwert gespeichert.</p><p>Wenn der Betreiber es eingestellt hat, schicken wir eine E-Mail an unsere Kontaktadresse, sobald ein Mitglied eine Datei hochlädt; sie nennt den Namen der Datei und den Namen oder die E-Mail-Adresse des Mitglieds. Ebenso können wir allen aktiven Mitgliedern einer Gruppe eine E-Mail schicken, wenn für sie neue Dateien bereitliegen.</p><p>Rechtsgrundlage ist Deine Mitgliedschaft (Art. 6 Abs. 1 lit. b DSGVO) und unser berechtigtes Interesse, Dateien nur Mitgliedern zugänglich zu machen und den Upload vor Missbrauch zu schützen (Art. 6 Abs. 1 lit. f DSGVO). Möchtest Du eine Datei löschen lassen, die Du hochgeladen hast, schreib uns: #/project/company/contact/email#</p>',
		],
	],
	'en_US' => [
		'files' => [
			'title' => 'Files for members',
			'text' 	=> '<p>This website offers files to members, kept in folders of the member groups. For each file we store the file itself, its name, its size, its type, a hash (SHA-256) of its content, the time it was added and - if a member uploaded it - the account of that member. The files lie in a directory of the server that is not served; they can be fetched only through the website.</p><p>A file can be seen and downloaded by whoever is signed in as a member of the group of the folder, for as long as the file is visible, and by the member who uploaded it. Everybody else is given no sign that it exists. The website does not log a download. To keep the upload from being misused, we count the requests of a visitor for one hour; the IP address of the visitor is stored for that only as a hash.</p><p>If the operator has chosen so, we send an email to our contact address as soon as a member uploads a file; it names the file and the name or email address of the member. Likewise we may send an email to all active members of a group when new files are there for them.</p><p>The legal basis is your membership (Art. 6(1)(b) GDPR) and our legitimate interest in making files available to members only and protecting the upload against misuse (Art. 6(1)(f) GDPR). If you want a file you uploaded deleted, write to us: #/project/company/contact/email#</p>',
		],
	],
];

<?php
// features/Review/install/elements/privacy.php - this feature's section of the privacy
// policy, in German and English, written for it. Added to the type 'privacy' of Nino's
// Legal module by \Nino\Features::activate() through the unit's 'elements' key, which
// only ever adds (see \Nino\Elements::seed()): a section an editor changed or deleted is
// not touched again, and a Nino without the module ignores the key. There is no 'model'
// on purpose - the type is the module's and never created from here.
//
// 'order' is the position among the module's sections, further features stand in
// 800-899. The text is in the form the sanitizer gives a field with 'blocks' and carries
// no '&', no entity and no '['. It names what the code does: the comment with the account
// of the member who wrote it, the second in the track it was written at, the time of
// writing and the page that showed the player; the members who see it - those who may see
// the file - and the owner; the counter of the posts per visitor, kept for an hour
// (Review::WINDOW) as a hash; and the mails the settings notifyOwner and notifyMembers
// switch on, both off by default.
return [
	'*' => [
		'review' => [
			'order' => 850,
		],
	],
	'de_DE' => [
		'review' => [
			'title' => 'Kommentare zu Audio- und Videodateien',
			'text' 	=> '<p>Mitglieder können zu den Audio- und Videodateien, die sie auf dieser Website sehen dürfen, Kommentare schreiben, an einer Stelle im Stück oder ohne eine, und auf Kommentare antworten. Zu jedem Kommentar speichern wir den Text, das Konto des Mitglieds, das ihn geschrieben hat, die Sekunde im Stück, den Zeitpunkt des Schreibens und die Seite, auf der der Player stand. Für den Betreiber kommt hinzu, ob er den Kommentar abgehakt hat.</p><p>Einen Kommentar sehen die Mitglieder, die die Datei sehen dürfen, mit dem Namen des Mitglieds, das ihn geschrieben hat, und wir als Betreiber, die ihn lesen, beantworten, abhaken und löschen können. Ein Mitglied löscht seine Kommentare nicht selbst. Damit der Dienst nicht missbraucht wird, zählen wir die Kommentare eines Besuchers eine Stunde lang; seine IP-Adresse wird dafür nur als Hashwert gespeichert.</p><p>Wenn der Betreiber es eingestellt hat, schicken wir eine E-Mail an unsere Kontaktadresse, sobald ein Mitglied einen Kommentar schreibt; sie nennt die Datei, die Stelle, den Text und den Namen oder die E-Mail-Adresse des Mitglieds. Ebenso können wir dem Mitglied eine E-Mail schicken, wenn wir auf seinen Kommentar antworten, und allen aktiven Mitgliedern einer Gruppe, wenn wir zu einer Datei für sie sichtbar einen Kommentar schreiben.</p><p>Rechtsgrundlage ist Deine Mitgliedschaft (Art. 6 Abs. 1 lit. b DSGVO) und unser berechtigtes Interesse, die Kommentare nur Mitgliedern zugänglich zu machen und den Dienst vor Missbrauch zu schützen (Art. 6 Abs. 1 lit. f DSGVO). Möchtest Du einen Kommentar löschen lassen, schreib uns: #/project/company/contact/email#</p>',
		],
	],
	'en_US' => [
		'review' => [
			'title' => 'Comments on audio and video files',
			'text' 	=> '<p>Members can write comments on the audio and video files they may see on this website, at a point in the track or without one, and answer comments. For each comment we store the text, the account of the member who wrote it, the second in the track, the time it was written and the page the player stood on. For the operator it is also kept whether the comment has been marked as done.</p><p>A comment is seen by the members who may see the file, with the name of the member who wrote it, and by us as the operator, who can read, answer, mark and delete it. A member does not delete their own comments. To keep the service from being misused, we count the comments of a visitor for one hour; the IP address of the visitor is stored for that only as a hash.</p><p>If the operator has chosen so, we send an email to our contact address as soon as a member writes a comment; it names the file, the point in the track, the text and the name or email address of the member. Likewise we may send an email to the member when we answer their comment, and to all active members of a group when we write a comment on a file that is visible to them.</p><p>The legal basis is your membership (Art. 6(1)(b) GDPR) and our legitimate interest in making the comments available to members only and protecting the service against misuse (Art. 6(1)(f) GDPR). If you want a comment deleted, write to us: #/project/company/contact/email#</p>',
		],
	],
];

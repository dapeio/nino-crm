<?php
// features/Sign/install/elements/privacy.php - this feature's section of the privacy
// policy, in German and English, written for it. Added to the type 'privacy' of Nino's
// Legal module by \Nino\Features::activate() through the unit's 'elements' key, which
// only ever adds (see \Nino\Elements::seed()): a section an editor changed or deleted is
// not touched again, and a Nino without the module ignores the key. There is no 'model'
// on purpose - the type is the module's and never created from here.
//
// 'order' is the position among the module's sections, further features stand in
// 800-899 (Hello holds 860, so this one takes the slot between Files and Hello). The text
// is in the form the sanitizer gives a field with 'blocks' and carries no '&', no entity
// and no '['. It names what the code does: the document as it was shown and the signed
// one, the picture of the signature, the title, the account, the times of the request and
// of the signing, and with a signature the address, the browser, the sentence agreed to,
// how it was signed and the hashes - which are the record on the last page of the signed
// document -; that all of it stays until the operator deletes the request; the mails (to
// the account, and to the contact address where the operator chose so) and the link's
// lifetime the setting days gives by default (fourteen days - whoever changes it changes
// the section with it); and the counter of the posts per visitor, kept for an hour
// (Sign::WINDOW) as a hash.
return [
	'*' => [
		'sign' => [
			'order' => 870,
		],
	],
	'de_DE' => [
		'sign' => [
			'title' => 'Elektronische Unterschrift',
			'text' 	=> '<p>Auf dieser Website können Mitglieder Dokumente elektronisch unterschreiben. Zu jeder Anfrage speichern wir das Dokument, wie es gezeigt wurde, und das unterschriebene Dokument, das Bild der Unterschrift (auf der Seite gezeichnet oder aus dem getippten Namen gemacht), den Titel, das Konto des Mitglieds und die Zeitpunkte, zu denen die Anfrage gestellt, geöffnet, unterschrieben, abgelehnt oder als geändert erkannt wurde. Zu einer Unterschrift speichern wir außerdem den Zeitpunkt, die IP-Adresse und den Browser des Mitglieds, den Satz, dem es zugestimmt hat, wie unterschrieben wurde und einen Hashwert (SHA-256) der beiden Dokumente. Auf der letzten Seite des unterschriebenen Dokuments steht als Beleg der Unterzeichnung der Hashwert des gezeigten Dokuments, dazu der Name und die E-Mail-Adresse des Kontos, der Zeitpunkt, die IP-Adresse, der Browser, der Satz und die Art der Unterschrift; der Hashwert des unterschriebenen Dokuments wird nur in der Verwaltung der Website aufbewahrt und angezeigt. Das alles bleibt gespeichert, bis der Betreiber die Anfrage löscht.</p><p>Der Link zur Unterschrift geht per E-Mail an die Adresse Deines Kontos, ist 14 Tage gültig und kann noch einmal geschickt werden. Das Formular der Seite hält einen Schutz-Token in der Sitzung der Website, einem Sitzungs-Cookie, das beim Schließen des Browsers gelöscht wird, damit niemand es für Dich absenden kann. Wenn der Betreiber es eingestellt hat, schicken wir eine E-Mail an unsere Kontaktadresse, sobald ein Mitglied unterschreibt oder ablehnt oder ein Dokument sich vor der Unterschrift geändert hat. Damit die Seite nicht missbraucht wird, zählen wir die Absendungen eines Besuchers eine Stunde lang; seine IP-Adresse wird dafür nur als Hashwert gespeichert.</p><p>Eine Unterschrift auf dieser Website ist eine einfache elektronische Signatur. Rechtsgrundlage ist der Vertrag oder die Mitgliedschaft, zu der das Dokument gehört (Art. 6 Abs. 1 lit. b DSGVO), und unser berechtigtes Interesse, nachzuweisen, wer wem zu welchem Dokument zugestimmt hat (Art. 6 Abs. 1 lit. f DSGVO), für das Cookie § 25 Abs. 2 Nr. 2 TDDDG. Möchtest Du wissen, was wir zu einer Unterzeichnung gespeichert haben, oder eine Anfrage löschen lassen, schreib uns: #/project/company/contact/email#</p>',
		],
	],
	'en_US' => [
		'sign' => [
			'title' => 'Electronic signature',
			'text' 	=> '<p>On this website members can sign documents electronically. For each request we store the document as it was shown and the signed document, the picture of the signature (drawn on the page or made from the typed name), the title, the account of the member and the times the request was made, opened, signed, declined or found changed. With a signature we also store the time, the IP address and the browser of the member, the sentence they agreed to, how it was signed and a hash (SHA-256) of each of the two documents. The last page of the signed document carries the record of the signing: the hash of the document as it was shown, with the name and the email address of the account, the time, the IP address, the browser, the sentence and how it was signed; the hash of the signed document is kept in the administration of the website and shown there only. All of it stays until the operator deletes the request.</p><p>The link to sign goes by email to the address of your account, is valid for 14 days and can be sent again. The form of the page keeps a protective token in the session of the website, a session cookie that is deleted when you close your browser, so that nobody can send it for you. If the operator has chosen so, we send an email to our contact address as soon as a member signs or declines, or a document changed before the signing. To keep the page from being misused, we count the posts of a visitor for one hour; the IP address of the visitor is stored for that only as a hash.</p><p>A signature on this website is a simple electronic signature. The legal basis is the contract or the membership the document belongs to (Art. 6(1)(b) GDPR) and our legitimate interest in proving who agreed to which document (Art. 6(1)(f) GDPR), and for the cookie Section 25(2) no. 2 TDDDG. If you want to know what we stored of a signing, or want a request deleted, write to us: #/project/company/contact/email#</p>',
		],
	],
];

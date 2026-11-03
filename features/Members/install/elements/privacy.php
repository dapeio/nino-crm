<?php
// features/Members/install/elements/privacy.php - this feature's section of
// the privacy policy, in German and English, written for it. Added to the type
// 'privacy' of Nino's Legal module by \Nino\Features::activate() through the
// unit's 'elements' key, which only ever adds (see \Nino\Elements::seed()): a
// section an editor changed or deleted is not touched again, and a Nino
// without the module ignores the key. There is no 'model' on purpose - the
// type is the module's and never created from here.
//
// 'order' is the position among the module's sections, further features stand
// in 800-899. The text is in the form the sanitizer gives a field with
// 'blocks' and carries no '&', no entity and no '['. It names what the code
// does: the account, a link by mail and the lifetime the settings give it by
// default (linkMinutes, fifteen minutes, and inviteDays, seven days - whoever
// changes them changes the section with them), the page that asks the link to
// be confirmed, the session cookie of the kernel and the protected page it
// keeps until a link is requested, the path a link's record keeps while the link
// is valid, and the counter of the requests for a link per visitor and per
// address, kept for an hour (Members::WINDOW) as a hash.
return [
	'*' => [
		'members' => [
			'order' => 830,
		],
	],
	'de_DE' => [
		'members' => [
			'title' => 'Mitglieder',
			'text' 	=> '<p>Einige Bereiche dieser Website sind Mitgliedern vorbehalten. Zu jedem Konto speichern wir die E-Mail-Adresse, einen Namen, eine Gruppe, den Zeitpunkt, zu dem das Konto angelegt wurde, und den Zeitpunkt der letzten Anmeldung. Ein Passwort gibt es nicht: Zum Anmelden forderst Du einen Link an, wir schicken ihn an Deine E-Mail-Adresse, und er funktioniert einmal und 15 Minuten lang, der Link einer Einladung 7 Tage lang. Der Link öffnet eine Seite, auf der Du die Anmeldung mit einem Klick bestätigst; erst damit ist er verbraucht.</p><p>Die Seite, die der Link öffnet, setzt ein Sitzungs-Cookie, das beim Schließen des Browsers gelöscht wird; darin merkt sich die Website Deine Anmeldung, sobald Du sie bestätigst. Die Sitzung merkt sich außerdem die geschützte Seite, die Du aufgerufen hast, bis Du einen Link anforderst, und der Eintrag des Links hält diesen Pfad fest, solange der Link gültig ist. Sonst wird nichts über Deinen Besuch festgehalten. Damit das Formular nicht missbraucht wird, zählen wir die Anforderungen eines Links je Besucher und je E-Mail-Adresse eine Stunde lang; die IP-Adresse des Besuchers und die E-Mail-Adresse werden dafür nur als Hashwert gespeichert.</p><p>Rechtsgrundlage ist Deine Mitgliedschaft (Art. 6 Abs. 1 lit. b DSGVO) und unser berechtigtes Interesse, den Bereich nur Mitgliedern zu öffnen und vor Missbrauch zu schützen (Art. 6 Abs. 1 lit. f DSGVO), für das Cookie § 25 Abs. 2 Nr. 2 TDDDG. Möchtest Du Dein Konto löschen lassen, schreib uns: #/project/company/contact/email#</p>',
		],
	],
	'en_US' => [
		'members' => [
			'title' => 'Members',
			'text' 	=> '<p>Some parts of this website are for members. For each account we store the email address, a name, a group, the time the account was created and the time of the last sign-in. There is no password: to sign in you ask for a link, we send it to your email address, and it works once and for 15 minutes, the link of an invitation for 7 days. The link opens a page where you confirm the sign-in with a click; only then is it used up.</p><p>The page the link opens sets a session cookie that is deleted when you close your browser; in it the website remembers your sign-in as soon as you confirm it. The session also keeps the protected page you asked for until you request a link, and the record of the link keeps that path for as long as the link is valid. Nothing else about your visit is recorded. To keep the form from being misused, we count the requests for a link per visitor and per email address for one hour; the IP address of the visitor and the email address are stored for that only as a hash.</p><p>The legal basis is your membership (Art. 6(1)(b) GDPR) and our legitimate interest in opening the area to members only and protecting it against misuse (Art. 6(1)(f) GDPR), and for the cookie Section 25(2) no. 2 TDDDG. If you want your account deleted, write to us: #/project/company/contact/email#</p>',
		],
	],
];

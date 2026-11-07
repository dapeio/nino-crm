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
// does: the account, a code of six digits by mail and the lifetime the settings
// give it by default (linkMinutes, fifteen minutes, five tries; inviteDays,
// seven days, for the link of an invitation - whoever changes them changes the
// section with them), the page that asks that link to be confirmed, the session
// cookie of the kernel and what the session keeps until the sign-in - the
// address that asked for a code, a random value the code is bound to and the
// protected page asked for -, the path a code's record keeps while the code is
// valid, the cookie nino_remember of a device that stays signed in (the setting rememberDays, ninety days by default - whoever changes it changes the section with it) with the hash, the time, the end and the user agent the account keeps of it, the new address of a change of the sign-in address, which the record of its
// code keeps until the code is spent or has run out, and the counter of the requests for a code per visitor and per address,
// kept for an hour (Members::WINDOW) as a hash.
return [
	'*' => [
		'members' => [
			'order' => 830,
		],
	],
	'de_DE' => [
		'members' => [
			'title' => 'Mitglieder',
			'text' 	=> '<p>Einige Bereiche dieser Website sind Mitgliedern vorbehalten. Zu jedem Konto speichern wir die E-Mail-Adresse, einen Namen, eine Gruppe, den Zeitpunkt, zu dem das Konto angelegt wurde, und den Zeitpunkt der letzten Anmeldung. Ein Passwort gibt es nicht: Zum Anmelden gibst Du Deine E-Mail-Adresse ein, wir schicken Dir einen Code aus sechs Ziffern an diese Adresse, und Du gibst ihn auf der Seite ein, auf der Du ihn angefordert hast. Der Code funktioniert einmal, 15 Minuten lang und für höchstens 5 Versuche; wir speichern ihn nur als Hashwert, verbunden mit einem Zufallswert, den nur die Sitzung Deines Browsers hält. Die Einladung ist ein Link, der 7 Tage lang funktioniert; er öffnet eine Seite, auf der Du die Anmeldung mit einem Klick bestätigst, erst damit ist er verbraucht. Schicken wir Dir von Hand einen Link zum Anmelden, gilt er ebenso: einmal und 15 Minuten lang. Änderst Du die Adresse, mit der Du Dich anmeldest, schicken wir einen Code aus sechs Ziffern an die neue Adresse, der ebenso einmal, 15 Minuten lang und für höchstens 5 Versuche gilt, und einen Hinweis an die alte Adresse; die neue Adresse speichern wir bis zur Bestätigung mit dem Code, und alle Links und Codes, die für die alte noch offen waren, verfallen.</p><p>Die Website setzt ein Sitzungs-Cookie, das beim Schließen des Browsers gelöscht wird; darin merkt sie sich Deine Anmeldung, sobald Du den Code eingegeben oder die Anmeldung per Link bestätigt hast. Die Sitzung merkt sich außerdem die E-Mail-Adresse, für die Du einen Code angefordert hast, und den Zufallswert dazu, bis Du Dich anmeldest oder der Code verfällt, und die geschützte Seite, die Du aufgerufen hast, bis Du einen Code anforderst; der Eintrag des Codes hält diesen Pfad fest, solange der Code gültig ist. Hakst Du bei der Anmeldung "Auf diesem Gerät angemeldet bleiben" an, setzt die Website zusätzlich das Cookie nino_remember, das 90 Tage lang gilt und mit jedem Besuch erneuert wird; es enthält die Kennung Deines Kontos und ein zufälliges Token, und in Deinem Konto speichern wir davon nur den Hashwert, den Zeitpunkt, zu dem das Gerät angemeldet wurde, das Ende seiner Zeit und den User-Agent Deines Browsers, für höchstens 10 Geräte. Das Abmelden löscht das Gerät, und Du kannst Dich auf der Seite Deiner Daten von allen Geräten abmelden. Sonst wird nichts über Deinen Besuch festgehalten. Damit das Formular nicht missbraucht wird, zählen wir die Anforderungen eines Codes je Besucher und je E-Mail-Adresse eine Stunde lang; die IP-Adresse des Besuchers und die E-Mail-Adresse werden dafür nur als Hashwert gespeichert.</p><p>Rechtsgrundlage ist Deine Mitgliedschaft (Art. 6 Abs. 1 lit. b DSGVO) und unser berechtigtes Interesse, den Bereich nur Mitgliedern zu öffnen und vor Missbrauch zu schützen (Art. 6 Abs. 1 lit. f DSGVO), für das Cookie § 25 Abs. 2 Nr. 2 TDDDG. Möchtest Du Dein Konto löschen lassen, schreib uns: #/project/company/contact/email#</p>',
		],
	],
	'en_US' => [
		'members' => [
			'title' => 'Members',
			'text' 	=> '<p>Some parts of this website are for members. For each account we store the email address, a name, a group, the time the account was created and the time of the last sign-in. There is no password: to sign in you enter your email address, we send a code of six digits to it, and you enter the code on the page where you asked for it. The code works once, for 15 minutes and for at most 5 tries; we store it only as a hash, tied to a random value that only your browser\'s session holds. The link of an invitation works for 7 days; it opens a page where you confirm the sign-in with a click, and only then is it used up. If we send you a link to sign in by hand, it works the same way: once and for 15 minutes. If you change the address you sign in with, we send a code of six digits to the new address, which works the same way - once, for 15 minutes and for at most 5 tries - and a notice to the old address; we store the new address with the code until you confirm it, and every link and code that was still out for the old one expires.</p><p>The website sets a session cookie that is deleted when you close your browser; in it the website remembers your sign-in as soon as you have entered the code or confirmed the sign-in by link. The session also keeps the email address you asked a code for, and the random value that belongs to it, until you sign in or the code runs out, and the protected page you asked for until you request a code; the record of the code keeps that path for as long as the code is valid. If you tick "Stay signed in on this device" when you sign in, the website also sets the cookie nino_remember, which is valid for 90 days and renewed with every visit; it holds the id of your account and a random token, and in your account we store of it only the hash, the time the device was signed in, the end of its time and the user agent of your browser, for at most 10 devices. Signing out deletes the device, and you can sign out of every device on the page of your data. Nothing else about your visit is recorded. To keep the form from being misused, we count the requests for a code per visitor and per email address for one hour; the IP address of the visitor and the email address are stored for that only as a hash.</p><p>The legal basis is your membership (Art. 6(1)(b) GDPR) and our legitimate interest in opening the area to members only and protecting it against misuse (Art. 6(1)(f) GDPR), and for the cookie Section 25(2) no. 2 TDDDG. If you want your account deleted, write to us: #/project/company/contact/email#</p>',
		],
	],
];

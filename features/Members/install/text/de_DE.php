<?php
declare(strict_types=1);

// Die Worte, die die Website sagt - bei jeder Aktivierung in die text/de_DE.php
// des Projekts gemischt, add-only: ein Schlüssel, den das Projekt schon hat,
// bleibt. Ab dann pflegen Redakteure sie im Panel Texte und öffnen nie ein
// Feature-Verzeichnis. Die Worte des Panels sind ein anderer Satz, in
// features/Members/text/.
return [

	// Die Anmeldeseite, page-members-login.tpl
	'[[/template/page-members-login/intro/title]]'	=> 'Anmelden',
	'[[/template/page-members-login/intro/text]]'	=> 'Gib die E-Mail-Adresse Deines Kontos ein. Wir schicken Dir einen Link per E-Mail - damit meldest Du Dich an, ganz ohne Passwort.',

	// Die Seite, die ein Link öffnet, page-members-confirm.tpl
	'[[/template/page-members-confirm/intro/title]]'	=> 'Anmeldung bestätigen',
	'[[/template/page-members-confirm/intro/text]]'	=> 'Klick auf den Button, um die Anmeldung abzuschließen. Noch ist nichts geschehen - der Link, dem Du gefolgt bist, hat Dich nur hierher gebracht.',

	// Das Formular, [members-login]
	'[[/feature/members/login/label]]'	=> 'E-Mail-Adresse',
	'[[/feature/members/login/button]]'	=> 'Login-Link senden',

	// Der Knopf dieser Seite, [members-confirm]
	'[[/feature/members/confirm/intro]]'	=> 'Du meldest Dich an als',
	'[[/feature/members/confirm/button]]'	=> 'Jetzt anmelden',

	// Was die Formulare über ihrem Knopf sagen: die Zustände von ?members=
	'[[/feature/members/state/sent]]'		=> 'Gehört diese Adresse zu einem Mitglied, ist ein Link zum Anmelden unterwegs. Schau auch in Deinen Spam-Ordner.',
	'[[/feature/members/state/limit]]'		=> 'Zu viele Anfragen. Bitte versuche es in einer Stunde noch einmal.',
	'[[/feature/members/state/invalid]]'	=> 'Dieser Link funktioniert nicht mehr. Fordere unten einen neuen an.',
	'[[/feature/members/state/out]]'		=> 'Du bist abgemeldet.',
	'[[/feature/members/state/expired]]'	=> 'Deine Sitzung ist abgelaufen. Bitte versuche es noch einmal.',

	// Ein angemeldetes Mitglied: die Zeile von [members-login] und der Link von [members-logout]
	'[[/feature/members/signed/label]]'	=> 'Angemeldet als',
	'[[/feature/members/logout/label]]'	=> 'Abmelden',

	// Die Mail mit dem Link zum Anmelden und die Einladung. Die Zahl der Minuten
	// und der Tage steht zwischen zwei dieser Worte
	'[[/feature/members/mail/login-subject]]'	=> 'Dein Login-Link',
	'[[/feature/members/mail/login-title]]'		=> 'Anmelden',
	'[[/feature/members/mail/login-intro]]'		=> 'Du hast einen Link zum Anmelden angefordert. Der Button öffnet eine Seite, auf der Du die Anmeldung mit einem weiteren Klick bestätigst - ein Passwort brauchst Du nicht.',
	'[[/feature/members/mail/login-button]]'	=> 'Anmelden',
	'[[/feature/members/mail/login-valid]]'		=> 'Der Link funktioniert einmal und ist gültig für',
	'[[/feature/members/mail/login-minutes]]'	=> 'Minuten.',
	'[[/feature/members/mail/login-notice]]'	=> 'Wenn Du ihn nicht angefordert hast, ignoriere diese E-Mail: Ohne den Link kann sich niemand mit Deiner Adresse anmelden.',
	'[[/feature/members/mail/invite-subject]]'	=> 'Deine Einladung',
	'[[/feature/members/mail/invite-title]]'	=> 'Du bist eingeladen zu',
	'[[/feature/members/mail/invite-intro]]'	=> 'Für Dich wurde ein Konto angelegt. Der Button öffnet eine Seite, auf der Du Deine erste Anmeldung mit einem weiteren Klick bestätigst - ein Passwort brauchst Du nicht. Später forderst Du auf der Anmeldeseite einen neuen Link an.',
	'[[/feature/members/mail/invite-button]]'	=> 'Einladung annehmen',
	'[[/feature/members/mail/invite-valid]]'	=> 'Die Einladung funktioniert einmal und ist gültig für',
	'[[/feature/members/mail/invite-days]]'		=> 'Tage.',
	'[[/feature/members/mail/invite-notice]]'	=> 'Wenn Du diese Einladung nicht erwartet hast, ignoriere diese E-Mail.',
	'[[/feature/members/mail/closing]]'			=> 'Viele Grüße',
];

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
	'[[/template/page-members-login/intro/lead]]'	=> 'Gib die E-Mail-Adresse Deines Kontos ein. Wir schicken Dir einen Code per E-Mail – damit meldest Du Dich an, ganz ohne Passwort.',

	// Die Seite, die ein Link öffnet, page-members-confirm.tpl
	'[[/template/page-members-confirm/intro/title]]'	=> 'Anmeldung bestätigen',
	'[[/template/page-members-confirm/intro/text]]'	=> 'Klick auf den Button, um die Anmeldung abzuschließen. Noch ist nichts geschehen – der Link, dem Du gefolgt bist, hat Dich nur hierher gebracht.',

	// Das Formular, [members-login]: der erste Schritt fragt nach der Adresse, der zweite nach dem Code
	'[[/feature/members/login/label]]'	=> 'E-Mail-Adresse',
	'[[/feature/members/login/request]]'	=> 'Code senden',
	'[[/feature/members/code/intro]]'	=> 'Wir haben einen Code geschickt an',
	'[[/feature/members/code/label]]'	=> 'Dein Code',
	'[[/feature/members/code/button]]'	=> 'Anmelden',
	'[[/feature/members/code/again]]'	=> 'Neuen Code anfordern',

	// Der Knopf dieser Seite, [members-confirm]
	'[[/feature/members/confirm/intro]]'	=> 'Du meldest Dich an als',
	'[[/feature/members/confirm/button]]'	=> 'Jetzt anmelden',

	// Was die Formulare über ihrem Knopf sagen: die Zustände von ?members=
	'[[/feature/members/state/code]]'		=> 'Gehört diese Adresse zu einem Konto, ist ein Code unterwegs. Schau auch in Deinen Spam-Ordner.',
	'[[/feature/members/state/limit]]'		=> 'Zu viele Anfragen. Bitte versuche es in einer Stunde noch einmal.',
	'[[/feature/members/state/invalid]]'	=> 'Dieser Link funktioniert nicht mehr. Fordere unten einen neuen an.',
	'[[/feature/members/state/out]]'		=> 'Du bist abgemeldet.',
	'[[/feature/members/state/expired]]'	=> 'Deine Sitzung ist abgelaufen. Bitte versuche es noch einmal.',
	'[[/feature/members/state/wrong]]'		=> 'Der Code stimmt nicht. Versuch es noch einmal.',
	'[[/feature/members/state/gone]]'		=> 'Dieser Code gilt nicht mehr. Fordere einen neuen an.',

	// Ein angemeldetes Mitglied: die Zeile von [members-login] und der Link von [members-logout]
	'[[/feature/members/signed/label]]'	=> 'Angemeldet als',
	'[[/feature/members/logout/label]]'	=> 'Abmelden',

	// Die Mail mit dem Code zum Anmelden, die mit dem Link zum Anmelden, den der Inhaber schickt, und die
	// Einladung. Die Zahl der Minuten und der Tage steht zwischen zwei dieser Worte
	'[[/feature/members/mail/code-subject]]'	=> 'Dein Login-Code',
	'[[/feature/members/mail/code-title]]'		=> 'Anmelden',
	'[[/feature/members/mail/code-intro]]'		=> 'Du hast einen Code zum Anmelden angefordert. Gib ihn auf der Seite ein, auf der Du ihn angefordert hast – ein Passwort brauchst Du nicht.',
	'[[/feature/members/mail/code-valid]]'		=> 'Der Code funktioniert einmal und ist gültig für',
	'[[/feature/members/mail/code-minutes]]'	=> 'Minuten.',
	'[[/feature/members/mail/code-notice]]'		=> 'Wenn Du ihn nicht angefordert hast, ignoriere diese E-Mail: Ohne den Code kann sich niemand mit Deiner Adresse anmelden.',
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

	// Die Mail mit dem Code für eine neue Anmeldeadresse (an die neue Adresse) und der Hinweis an die alte Adresse, dass sie geändert wurde
	'[[/feature/members/mail/change-subject]]'	=> 'Dein Code für die neue Anmeldeadresse',
	'[[/feature/members/mail/change-title]]'	=> 'Neue Anmeldeadresse bestätigen',
	'[[/feature/members/mail/change-intro]]'	=> 'Jemand möchte diese E-Mail-Adresse als Anmeldeadresse eines Kontos eintragen. Gib den Code auf der Seite ein, auf der Du ihn angefordert hast, um die Adresse zu bestätigen.',
	'[[/feature/members/mail/change-valid]]'	=> 'Der Code funktioniert einmal und ist gültig für',
	'[[/feature/members/mail/change-minutes]]'	=> 'Minuten.',
	'[[/feature/members/mail/change-notice]]'	=> 'Wenn Du das nicht warst, ignoriere diese E-Mail: Ohne den Code geschieht nichts.',
	'[[/feature/members/mail/changed-subject]]'	=> 'Deine Anmeldeadresse wurde geändert',
	'[[/feature/members/mail/changed-title]]'	=> 'Anmeldeadresse geändert',
	'[[/feature/members/mail/changed-intro]]'	=> 'Die Anmeldeadresse Deines Kontos wurde geändert. Von nun an meldest Du Dich mit einer anderen Adresse an; Links und Codes, die für Dein Konto noch offen waren, gelten nicht mehr.',
	'[[/feature/members/mail/changed-when]]'	=> 'Geändert am',
	'[[/feature/members/mail/changed-notice]]'	=> 'Wenn Du das nicht warst, schreib uns:',
	'[[/feature/members/mail/closing]]'			=> 'Viele Grüße',
];

<?php
declare(strict_types=1);

// Die Worte, die die Website sagt - bei jeder Aktivierung in die text/de_DE.php des Projekts gemischt, add-only:
// ein Schlüssel, den das Projekt schon hat, bleibt. Ab dann pflegen Redakteure sie im Panel Texte und öffnen nie
// ein Feature-Verzeichnis, den Satz, dem das Mitglied zustimmt, eingeschlossen. Die Worte des Panels sind ein
// anderer Satz, in features/Sign/text/
return [

	// Die Unterschriftsseite, page-sign.tpl
	'[[/template/page-sign/intro/title]]'		=> 'Deine Unterschrift',
	'[[/_nino/webpage/.sign/name]]'		=> 'Unterschrift',
	'[[/_nino/webpage/.sign/title]]'		=> 'Deine Unterschrift',
	'[[/_nino/webpage/.sign/description]]'	=> 'Ein Dokument lesen, zustimmen und unterschreiben.',

	// Das Formular, [sign-form]
	'[[/feature/sign/form/document]]'			=> 'Das Dokument zum Unterschreiben',
	'[[/feature/sign/form/open]]'				=> 'Das Dokument in einem eigenen Fenster öffnen',
	'[[/feature/sign/form/intro]]'			=> 'Lies das Dokument unten. Wenn Du einverstanden bist, hake das Kästchen an, unterschreibe mit dem Finger, einem Stift oder der Maus - oder tippe Deinen Namen - und drücke Unterschreiben.',
	'[[/feature/sign/form/consent]]'			=> 'Ich habe das Dokument gelesen und bin damit einverstanden, es elektronisch zu unterschreiben. Mir ist bekannt, dass der Zeitpunkt, meine Adresse und mein Browser mit meiner Unterschrift festgehalten werden.',
	'[[/feature/sign/form/pad]]'				=> 'Unterschreibe im Feld',
	'[[/feature/sign/form/pad-fallback]]'		=> 'Dieser Browser kann hier nicht zeichnen. Tippe stattdessen unten Deinen Namen.',
	'[[/feature/sign/form/clear]]'				=> 'Leeren',
	'[[/feature/sign/form/name]]'				=> 'Oder tippe Deinen vollen Namen',
	'[[/feature/sign/form/sign]]'				=> 'Unterschreiben',
	'[[/feature/sign/form/decline]]'			=> 'Ablehnen',
	'[[/feature/sign/form/signed-link]]'		=> 'Das unterschriebene Dokument öffnen',

	// Was die Seite über ihren Knöpfen oder an der Stelle ihres Formulars sagt: die Zustände einer Anfrage und eines Posts
	'[[/feature/sign/state/signed]]'			=> 'Dieses Dokument ist unterschrieben. Danke. Das unterschriebene Dokument liegt in Deinem Bereich der Website, ein Link dorthin wurde Dir per E-Mail geschickt.',
	'[[/feature/sign/state/declined]]'			=> 'Du hast abgelehnt, dieses Dokument zu unterschreiben. Diese Anfrage ist abgeschlossen.',
	'[[/feature/sign/state/cancelled]]'			=> 'Diese Anfrage wurde zurückgezogen. Es gibt nichts zu unterschreiben.',
	'[[/feature/sign/state/expired]]'			=> 'Dieser Link ist abgelaufen. Bitte frage einen neuen an.',
	'[[/feature/sign/state/invalid]]'			=> 'Dieser Link führt zu keinem Dokument - er ist falsch, oder das Dokument gehört nicht Dir. Gehört es Dir, melde Dich als Mitglied an und öffne es aus Deiner Liste.',
	'[[/feature/sign/state/consent]]'			=> 'Bitte hake das Kästchen an, bevor Du unterschreibst.',
	'[[/feature/sign/state/empty]]'				=> 'Bitte unterschreibe zuerst: zeichne Deine Unterschrift oder tippe Deinen Namen.',
	'[[/feature/sign/state/limit]]'				=> 'Zu viele Versuche. Bitte versuche es in einer Stunde noch einmal.',
	'[[/feature/sign/state/changed]]'			=> 'Das Dokument wurde geändert, nachdem es Dir geschickt wurde. Es ist nichts unterschrieben, und diese Anfrage ist abgeschlossen: Der Inhaber wird informiert und fragt Dich noch einmal an.',
	'[[/feature/sign/state/again]]'			=> 'Deine Sitzung ist abgelaufen, während die Seite offen war. Bitte öffne die Seite noch einmal und unterschreibe noch einmal.',
	'[[/feature/sign/state/write]]'				=> 'Deine Unterschrift konnte nicht gespeichert werden. Bitte versuche es später noch einmal.',

	// Die Liste, [sign-list]: wie eine Anfrage steht, und die Links einer Zeile
	'[[/feature/sign/status/requested]]'		=> 'Wartet auf Deine Unterschrift',
	'[[/feature/sign/status/viewed]]'			=> 'Geöffnet, noch nicht unterschrieben',
	'[[/feature/sign/status/signed]]'			=> 'Unterschrieben',
	'[[/feature/sign/status/declined]]'			=> 'Abgelehnt',
	'[[/feature/sign/status/cancelled]]'		=> 'Zurückgezogen',
	'[[/feature/sign/status/expired]]'			=> 'Abgelaufen',
	'[[/feature/sign/list/none]]'				=> 'Es gibt keine Dokumente, die Du unterschreiben müsstest.',
	'[[/feature/sign/list/page]]'				=> 'Öffnen',
	'[[/feature/sign/list/signed]]'				=> 'Unterschriebenes Dokument',

	// Der Beleg, die Seite, die an das unterschriebene PDF angehängt wird
	'[[/feature/sign/record/title]]'			=> 'Beleg der Unterzeichnung',
	'[[/feature/sign/record/intro]]'			=> 'Diese Seite wurde angehängt, als das Dokument unterschrieben wurde. Sie hält die Umstände der Unterzeichnung fest. Der Hash ist der des Dokuments, wie es gezeigt wurde, bevor die Unterschrift und diese Seite hinzukamen.',
	'[[/feature/sign/record/label-document]]'	=> 'Dokument',
	'[[/feature/sign/record/label-request]]'	=> 'Anfrage',
	'[[/feature/sign/record/label-signer]]'		=> 'Unterschrieben von',
	'[[/feature/sign/record/label-time]]'		=> 'Zeit (UTC)',
	'[[/feature/sign/record/label-address]]'	=> 'Adresse',
	'[[/feature/sign/record/label-agent]]'		=> 'Browser',
	'[[/feature/sign/record/label-method]]'		=> 'Unterschrift',
	'[[/feature/sign/record/label-hash]]'		=> 'SHA-256 des gezeigten Dokuments',
	'[[/feature/sign/record/label-consent]]'	=> 'Zugestimmt',
	'[[/feature/sign/record/note]]'				=> 'Eine einfache elektronische Signatur. Zeit, Adresse und Browser sind das, was die Website sah, als die Unterschrift gesendet wurde.',
	'[[/feature/sign/method/drawn]]'			=> 'Auf der Seite gezeichnet',
	'[[/feature/sign/method/typed]]'			=> 'Getippter Name',
	'[[/feature/sign/outcome/signed]]'			=> 'hat unterschrieben',
	'[[/feature/sign/outcome/declined]]'		=> 'hat die Unterschrift abgelehnt',
	'[[/feature/sign/outcome/changed]]'		=> 'wollte unterschreiben, aber das Dokument ist nicht das, das gezeigt wurde - es ist nichts unterschrieben',

	// Die drei Mails: die Anfrage, das unterschriebene Dokument und was der Inhaber erfährt
	'[[/feature/sign/mail/request-subject]]'	=> 'Ein Dokument wartet auf Deine Unterschrift',
	'[[/feature/sign/mail/request-title]]'		=> 'Bitte unterschreibe ein Dokument',
	'[[/feature/sign/mail/greeting]]'			=> 'Hallo',
	'[[/feature/sign/mail/request-intro]]'		=> 'Du wirst gebeten, dieses Dokument zu unterschreiben. Der Button öffnet eine Seite, die es zeigt und um eine Unterschrift bittet - ein Passwort brauchst Du nicht.',
	'[[/feature/sign/mail/request-button]]'		=> 'Lesen und unterschreiben',
	'[[/feature/sign/mail/request-valid]]'		=> 'Der Link ist gültig für',
	'[[/feature/sign/mail/request-days]]'		=> 'Tage.',
	'[[/feature/sign/mail/request-notice]]'		=> 'Wenn Du diese E-Mail nicht erwartet hast, ignoriere sie: Ohne den Link kann niemand für Dich unterschreiben.',
	'[[/feature/sign/mail/signed-subject]]'		=> 'Dein unterschriebenes Dokument',
	'[[/feature/sign/mail/signed-title]]'		=> 'Danke für Deine Unterschrift',
	'[[/feature/sign/mail/signed-intro]]'		=> 'Du hast dieses Dokument unterschrieben. Die unterschriebene Kopie mit einer Seite, die die Unterzeichnung festhält, liegt für Dich auf der Website: Melde Dich als Mitglied an, um sie zu öffnen.',
	'[[/feature/sign/mail/signed-button]]'		=> 'Das unterschriebene Dokument öffnen',
	'[[/feature/sign/mail/signed-notice]]'		=> 'Wenn Du nicht unterschrieben hast, schreib uns bitte sofort.',
	'[[/feature/sign/mail/owner-signed-subject]]'	=> 'Ein Mitglied hat ein Dokument unterschrieben',
	'[[/feature/sign/mail/owner-declined-subject]]'	=> 'Ein Mitglied hat die Unterschrift eines Dokuments abgelehnt',
	'[[/feature/sign/mail/owner-changed-subject]]'	=> 'Ein Dokument hat sich geändert, bevor ein Mitglied es unterschreiben konnte',
	'[[/feature/sign/title/signed]]'		=> 'Ein Mitglied hat unterschrieben',
	'[[/feature/sign/title/declined]]'		=> 'Ein Mitglied hat abgelehnt',
	'[[/feature/sign/title/changed]]'		=> 'Ein Dokument hat sich geändert',
	'[[/feature/sign/mail/owner-document]]'		=> 'Das Dokument:',
	'[[/feature/sign/mail/owner-button]]'		=> 'Das Panel Unterschriften öffnen',
	'[[/feature/sign/mail/closing]]'			=> 'Viele Grüße',
];

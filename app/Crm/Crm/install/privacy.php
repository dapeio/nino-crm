<?php
declare(strict_types=1);

// install/privacy.php - the Datenschutzerklärung the screen Einrichtung enters as the setting privacy_text where the project has none. The file is part
// of the unit and no text of the website: the text is a setting, which the owner reads through and adapts in the settings of the CRM (the hosting, the
// supervisory authority and the periods are his statements). It returns a function that takes the particulars of the company - name, address, email and
// phone as plain text - and answers the text, plain text with line breaks as the portal shows it; a particular that is empty is "…", so that the owner
// sees what is missing. The line Stand carries the month and the year of the day the text is made, in the time zone of the site.
return static function( array $company ): string {

	$text = <<<'TEXT'
Datenschutzerklärung nach DSGVO
Datenschutzerklärung für die Audio-Dienstleistungen und das Kundenportal
Stand: <Monat.Jahr>

Verantwortlicher (Art. 4 Nr. 7 DSGVO):
<name>, <address>
E-Mail: <email>, Telefon: <phone>

1. Verarbeitete Datenkategorien
Vertrags- und Kontaktdaten: Name, Anschrift, E-Mail-Adresse, Telefonnummer, Rechnungs- und Zahlungsdaten.
Projektbezogene Daten: technische Anforderungen, Song- und Projektnamen, Audioaufnahmen, Session-Files, Notizen, Kommentare zu Dateien und die Kommunikation im Rahmen der Projektabwicklung.
Daten des Kundenportals: die E-Mail-Adresse des Zugangs, der Anmeldecode, ein Sitzungs-Cookie sowie als Nachweis Zeitpunkt, IP-Adresse und Browser bei der Kenntnisnahme dieser Erklärung, bei einem Auftrag, bei einer Abnahme und bei einer Einwilligung.

2. Zwecke und Rechtsgrundlagen
Durchführung der vereinbarten Audio-Dienstleistungen, Terminplanung, Bereitstellung von Dateien im Kundenportal und Abrechnung – Art. 6 Abs. 1 lit. b DSGVO (Vertrag und Vertragsanbahnung), ergänzend Art. 6 Abs. 1 lit. f DSGVO (Kommunikation und Organisation).
Nachweis von Kenntnisnahme, Auftrag, Abnahme und Einwilligung – Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an der Nachweisbarkeit) und Art. 7 Abs. 1 DSGVO.
Erfüllung steuer- und handelsrechtlicher Pflichten – Art. 6 Abs. 1 lit. c DSGVO in Verbindung mit § 147 AO und § 257 HGB.
Betrieb und Sicherheit des Portals und der Website, Server-Protokolle – Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an einer stabilen und sicheren Bereitstellung).

3. Empfänger und Auftragsverarbeitung
Hosting, Server und E-Mail: netcup GmbH, 76131 Karlsruhe, Deutschland. Es besteht ein Auftragsverarbeitungsvertrag nach Art. 28 DSGVO; netcup verarbeitet Daten ausschließlich nach Weisung.
Steuerberatung und Finanzbehörden: soweit zur Buchführung und Besteuerung nötig (gesetzliche Pflicht).
Zahlungsdienstleister: Zahlst Du über den PayPal-Link einer Rechnung, verarbeitet PayPal (Europe) S.à r.l. et Cie, S.C.A., Luxemburg, die Zahlungsdaten in eigener Verantwortung nach seiner Datenschutzerklärung. Die Überweisung auf das genannte Konto läuft über Deine Bank.
Eine Übermittlung in Drittländer findet durch mich nicht statt.

4. Speicherung und Löschung
Buchführungs- und Rechnungsdaten: 10 Jahre (§ 147 AO, § 257 HGB).
Projektbezogene Daten (Audio- und Session-Files, Notizen, Kommentare) und die Nachweise des Portals: in der Regel 3 Jahre nach Projektabschluss (regelmäßige Verjährung; Aufbewahrung zur Abwehr oder Geltendmachung von Ansprüchen). Eine Verlängerung nur, solange sie erforderlich ist, oder mit Deiner Einwilligung (Punkt 9).
Zugangsdaten des Portals: bis zur Löschung des Zugangs. Kontaktformulardaten und E-Mails: für die Bearbeitung und zu Nachweiszwecken, anschließend Löschung nach den obigen Fristen.

5. Technische und organisatorische Maßnahmen
Physisch: Eventuelle physische Datenträger werden sicher verschlossen aufbewahrt.
Digital: Speicherung passwortgeschützt in einem Docker-Container auf einem eigenen vServer (netcup, Deutschland), regelmäßige Updates und Sicherungen, Zugriff ausschließlich durch den Verantwortlichen. Das Kundenportal ist nur mit einem Anmeldecode erreichbar, der an die E-Mail-Adresse des Zugangs geht; Dateien und Belege sieht nur der Kunde, dem sie gehören.

6. Website, Cookies, Kontaktformular, Kundenportal
Cookies: Es werden nur technisch notwendige Cookies eingesetzt (das Sitzungs-Cookie des Portals, Session-Cookies der Website). Es gibt keine Analyse- oder Werbe-Cookies.
Kontaktformular und E-Mail: Verarbeitung der übermittelten Angaben zur Beantwortung von Anfragen; Rechtsgrundlage Art. 6 Abs. 1 lit. b DSGVO (Anbahnung) beziehungsweise Art. 6 Abs. 1 lit. f DSGVO (Kommunikation).
Kundenportal: Was Du im Portal bestätigst (diese Erklärung, ein Auftrag, eine Abnahme, eine Einwilligung), wird mit dem gezeigten Text, Zeitpunkt, IP-Adresse und Browser festgehalten und nicht verändert.

7. Rechte der Betroffenen
Auskunft, Berichtigung, Löschung, Einschränkung der Verarbeitung und Datenübertragbarkeit (Art. 15 bis 20 DSGVO).
Widerspruch gegen Verarbeitungen auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (Art. 21 DSGVO).
Widerruf erteilter Einwilligungen jederzeit mit Wirkung für die Zukunft (Art. 7 Abs. 3 DSGVO), im Portal unter Meine Daten.
Beschwerderecht bei der Aufsichtsbehörde: Bayerisches Landesamt für Datenschutzaufsicht (BayLDA), Promenade 18, 91522 Ansbach, www.lda.bayern.de (auch online möglich).

8. Pflicht zur Bereitstellung und Folgen der Nichtbereitstellung
Die Bereitstellung von Kontakt- und Vertragsdaten ist für die Durchführung der Audio-Dienstleistungen und die Rechnungsstellung erforderlich. Ohne diese Angaben kann kein Vertrag geschlossen und kein Auftrag durchgeführt werden.

9. Einwilligungen: Langzeitarchivierung und Social-Media- und Referenznutzung
Langzeitarchivierung: Auf Wunsch archiviere ich Audio-Projekte (Session-Files, finale Mixe, Master-Files) über die oben genannten Fristen hinaus, um spätere Bearbeitungen, Remaster oder Nachbestellungen zu ermöglichen.
Social-Media- und Referenznutzung: Mit gesonderter Zustimmung verwende ich Auszüge (kurze Audio-Snippets, Screenshots von Projekten, Fotos aus Studiosessions) für die eigene Werbung, etwa auf meiner Website, in Social-Media-Kanälen (Instagram, Facebook, YouTube oder ähnliche) oder in meinem Portfolio.
Beides geschieht ausschließlich auf Grundlage Deiner freiwilligen Einwilligung nach Art. 6 Abs. 1 lit. a und Art. 7 DSGVO, die Du im Portal beim Auftrag eines Angebots als eigenes Kästchen gibst – kein Kästchen ist Pflicht, der Auftrag gilt auch ohne – und jederzeit unter Meine Daten mit Wirkung für die Zukunft widerrufen kannst. Ein Widerruf berührt die Rechtmäßigkeit der bis dahin erfolgten Verarbeitung nicht.
TEXT;

	$fill = [ '<Monat.Jahr>' => date( 'm.Y' ) ];

	foreach( [ 'name', 'address', 'email', 'phone' ] as $field ) {

		$value = $company[$field] ?? '';
		$fill['<'. $field. '>'] = is_string( $value ) === true && trim( $value ) !== '' ? trim( $value ) : '…';
	}

	return strtr( $text, $fill );
};

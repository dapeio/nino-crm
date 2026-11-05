<?php
declare(strict_types=1);

// Die Worte der PDF-Dateien der Belege (siehe Render::text()): einfache Schlüssel, deutsch - ein Beleg ist ein Dokument des
// Inhabers an seinen Kunden und nicht Teil der Oberfläche. Ein %s ist eine Stelle für einen Wert
return [

	'title.offer' => 'Angebot',
	'title.invoice' => 'Rechnung',
	'title.credit' => 'Stornorechnung',
	'due.sentence' => 'Zahlbar ohne Abzug bis zum %s unter Angabe der Rechnungsnummer %s.',
	'due.sentence-none' => 'Zahlbar sofort ohne Abzug unter Angabe der Rechnungsnummer %s.',
	'valid.sentence' => 'Dieses Angebot ist gültig bis zum %s.',
	'valid.sentence-none' => 'Dieses Angebot ist freibleibend.',
	'period.none' => 'Leistungsdatum entspricht dem Rechnungsdatum',
	'period.none-offer' => 'nach Absprache',
	'vatnote.small_business' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
	'vatnote.reverse' => 'Steuerschuldnerschaft des Leistungsempfängers.',
	'head.pos' => 'Pos.',
	'head.title' => 'Bezeichnung',
	'head.qty' => 'Menge',
	'head.price' => 'Einzelpreis',
	'head.rate' => 'USt.',
	'head.amount' => 'Betrag',
	'totals.net' => 'Nettobetrag',
	'totals.vat' => 'zzgl. %s USt. auf %s',
	'totals.gross' => 'Gesamtbetrag',
];

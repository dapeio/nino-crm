<?php
declare(strict_types=1);

// Die Worte der PDF-Dateien der Belege (siehe Render::text()): einfache Schlüssel, deutsch - ein Beleg ist ein Dokument des
// Inhabers an seinen Kunden und nicht Teil der Oberfläche. Ein %s ist eine Stelle für einen Wert
return [

	'title.offer' => 'Angebot',
	'title.invoice' => 'Rechnung',
	'title.credit' => 'Stornorechnung',
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

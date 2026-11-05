<?php
declare(strict_types=1);

// Die Sätze der Liste, die sagt, was einer E-Rechnung noch fehlt (siehe XRechnung::text()): ein Schlüssel je Punkt, deutsch, so geschrieben, dass
// sie in der Oberfläche unter der Summe stehen und auf der Karte eines festgeschriebenen Belegs, der ohne E-Rechnung blieb
return [

	'lack.seller_name' => 'In den Einstellungen fehlt Dein Name oder der Name Deiner Firma (Rechnungsaussteller).',
	'lack.seller_street' => 'In den Einstellungen fehlt Deine Straße (Rechnungsaussteller).',
	'lack.seller_zip' => 'In den Einstellungen fehlt Deine Postleitzahl (Rechnungsaussteller).',
	'lack.seller_city' => 'In den Einstellungen fehlt Dein Ort (Rechnungsaussteller).',
	'lack.seller_country' => 'In den Einstellungen fehlt Dein Land (Rechnungsaussteller).',
	'lack.seller_contact' => 'In den Einstellungen fehlt der Name der Ansprechperson (Rechnungsaussteller).',
	'lack.seller_phone' => 'In den Einstellungen fehlt Deine Telefonnummer (Rechnungsaussteller).',
	'lack.seller_email' => 'In den Einstellungen fehlt Deine E-Mail-Adresse (Rechnungsaussteller).',
	'lack.seller_vat_id' => 'In den Einstellungen fehlt Deine USt-IdNr. (Rechnungsangaben). Die Steuernummer allein genügt für eine E-Rechnung nicht (Regel BR-CO-26).',
	'lack.iban' => 'In den Einstellungen fehlt Deine IBAN (Rechnungsangaben).',
	'lack.currency' => 'Die Währung in den Einstellungen ist keine, die eine E-Rechnung kennt (€, CHF, $ oder £).',
	'lack.exempt_text' => 'In den Einstellungen fehlt der Hinweis, warum keine Umsatzsteuer berechnet wird (Rechnungsaussteller).',
	'lack.buyer_name' => 'Beim Kunden fehlen Firma und Name.',
	'lack.buyer_street' => 'Beim Kunden fehlt die Straße.',
	'lack.buyer_zip' => 'Beim Kunden fehlt die Postleitzahl.',
	'lack.buyer_city' => 'Beim Kunden fehlt der Ort.',
	'lack.buyer_country' => 'Beim Kunden fehlt das Land.',
	'lack.buyer_reference' => 'Es fehlt die Käuferreferenz: Trage sie hier am Beleg oder beim Kunden ein.',
	'lack.buyer_email' => 'Beim Kunden fehlt die E-Mail-Adresse.',
	'lack.lines' => 'Der Beleg hat keine Position.',
	'lack.line_unit' => 'Mindestens eine Position hat keine Einheit: Wähle sie in der Zeile aus.',
	'lack.line_rate' => 'Mindestens eine Position hat den Steuersatz 0 %: Das kann eine E-Rechnung nicht abbilden.',
	'lack.reverse_charge' => 'Reverse-Charge kann eine E-Rechnung nicht abbilden.',
];

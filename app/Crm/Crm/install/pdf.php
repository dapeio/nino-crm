<?php
declare(strict_types=1);

// install/pdf.php - what the screen Einrichtung seeds into the Pdf feature through \Nino\Modules\Pdf::install(), add-only: the
// templates of the documents, the text blocks they share and the header and the footer of the layout. The wizard does not read this
// file (the manifest does not name it); \Crm\Crm\Admin::apiSetup() does.
//
// The tokens are the ones \Crm\Documents\Render::data() hands a template ([[/document/number]], [[/customer/company]], ...) and the
// site's own texts ([[/project/company/general/name]], ... - the words of the company step of the wizard), so a document speaks
// with the company of the site and the client of the document. A template of the tags contract and privacy carries a field of the
// owner and one of the member, which is what Sign needs to ask a member for a signature. The texts of a document are the owner's
// letter to his client: no pronoun, so that the owner's tone is the owner's to set in the panel Pdf
return [

	'blocks' => [
		'zahlungsbedingungen' => [ 'title' => 'Zahlungsbedingungen', 'html' => '<p>Zahlbar ohne Abzug bis zum [[/invoice/due]] unter Angabe der Rechnungsnummer [[/document/number]].</p>' ],
		'gueltigkeit'         => [ 'title' => 'Gültigkeit', 'html' => '<p>Dieses Angebot ist gültig bis zum [[/offer/valid]].</p>' ],
		'unterschrift'        => [ 'title' => 'Unterschrift', 'html' => '<p>Mit ihrer Unterschrift bestätigen die Parteien den Inhalt dieses Dokuments.</p>' ],
		'kontakt'             => [ 'title' => 'Kontakt', 'html' => '<p><strong>[[/project/company/general/name]]</strong><br>[[/project/company/contact/address]]<br>[[/project/company/contact/email]] · [[/project/company/contact/phone]]</p>' ],
	],

	'layout' => [
		'header' => '<p><strong>[[/project/company/general/name]]</strong><br>[[/project/company/contact/address]]<br>[[/project/company/contact/email]] · [[/project/company/contact/phone]]</p>',
		'footer' => '<p align="center">[[/project/company/general/name]] · [[/project/company/contact/email]] · [[/project/company/contact/phone]]</p>',
	],

	'templates' => [

		'angebot' => [ 'name' => 'Angebot', 'tag' => 'offer', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1><p>Datum: [[/document/date]]<br>Leistungszeitraum: [[/offer/period]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'einleitung', 'label' => 'Einleitung', 'multiline' => true, 'required' => false ],
			[ 'type' => 'slot', 'name' => 'lines' ],
			[ 'type' => 'slot', 'name' => 'totals' ],
			[ 'type' => 'html', 'html' => '<p>[[/document/vatnote]]</p>' ],
			[ 'type' => 'html', 'block' => 'gueltigkeit' ],
			[ 'type' => 'html', 'block' => 'kontakt' ],
		] ],

		'rechnung' => [ 'name' => 'Rechnung', 'tag' => 'invoice', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1><p>Datum: [[/document/date]]<br>Leistungszeitraum: [[/invoice/period]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'einleitung', 'label' => 'Einleitung', 'multiline' => true, 'required' => false ],
			[ 'type' => 'slot', 'name' => 'lines' ],
			[ 'type' => 'slot', 'name' => 'totals' ],
			[ 'type' => 'html', 'html' => '<p>[[/document/vatnote]]</p>' ],
			[ 'type' => 'html', 'block' => 'zahlungsbedingungen' ],
			[ 'type' => 'html', 'block' => 'kontakt' ],
		] ],

		'stornorechnung' => [ 'name' => 'Stornorechnung', 'tag' => 'invoice', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1><p>Datum: [[/document/date]]<br>Zur Rechnung: [[/document/parent]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'grund', 'label' => 'Grund der Stornierung', 'multiline' => true, 'required' => false ],
			[ 'type' => 'slot', 'name' => 'lines' ],
			[ 'type' => 'slot', 'name' => 'totals' ],
			[ 'type' => 'html', 'html' => '<p>[[/document/vatnote]]</p>' ],
			[ 'type' => 'html', 'block' => 'kontakt' ],
		] ],

		'vertrag' => [ 'name' => 'Vertrag', 'tag' => 'contract', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Datum: [[/document/date]]<br>Zum Angebot [[/offer/number]] vom [[/offer/date]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'inhalt', 'label' => 'Inhalt des Vertrags', 'multiline' => true, 'required' => false ],
			[ 'type' => 'slot', 'name' => 'lines' ],
			[ 'type' => 'slot', 'name' => 'totals' ],
			[ 'type' => 'html', 'block' => 'unterschrift' ],
			[ 'type' => 'signature', 'name' => 'inhaber', 'party' => 'owner', 'label' => 'Inhaber', 'page' => 'last', 'x' => 20, 'y' => 230, 'w' => 70, 'h' => 20, 'line' => true ],
			[ 'type' => 'signature', 'name' => 'kunde', 'party' => 'member', 'label' => 'Kunde', 'page' => 'last', 'x' => 110, 'y' => 230, 'w' => 70, 'h' => 20, 'line' => true ],
		] ],

		'datenschutz' => [ 'name' => 'Datenschutz', 'tag' => 'privacy', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Datum: [[/document/date]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'inhalt', 'label' => 'Hinweise zum Datenschutz', 'multiline' => true, 'required' => false ],
			[ 'type' => 'html', 'block' => 'unterschrift' ],
			[ 'type' => 'signature', 'name' => 'inhaber', 'party' => 'owner', 'label' => 'Inhaber', 'page' => 'last', 'x' => 20, 'y' => 230, 'w' => 70, 'h' => 20, 'line' => true ],
			[ 'type' => 'signature', 'name' => 'kunde', 'party' => 'member', 'label' => 'Kunde', 'page' => 'last', 'x' => 110, 'y' => 230, 'w' => 70, 'h' => 20, 'line' => true ],
		] ],

		'information' => [ 'name' => 'Information', 'tag' => 'information', 'blocks' => [
			[ 'type' => 'slot', 'name' => 'address' ],
			[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Datum: [[/document/date]]<br>Kundennummer: [[/customer/number]]</p>' ],
			[ 'type' => 'input', 'name' => 'text', 'label' => 'Text', 'multiline' => true, 'required' => false ],
			[ 'type' => 'html', 'html' => '<p>Das Kundenportal: [[/site/portal]]</p>' ],
			[ 'type' => 'html', 'block' => 'kontakt' ],
		] ],
	],
];

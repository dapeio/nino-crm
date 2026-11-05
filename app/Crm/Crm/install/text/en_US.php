<?php
declare(strict_types=1);

// The words of the portal pages - merged add-only into the project's text/en_US.php when the unit is applied; from
// then on editors keep them in the Text panel
return [

	// The details of the pages: name, title and description, read by the head of every page
	'[[/_nino/webpage/portal/name]]' => 'Portal',
	'[[/_nino/webpage/portal/title]]' => 'Customer portal',
	'[[/_nino/webpage/portal/description]]' => 'Your projects, documents, dates and details in one place.',
	'[[/_nino/webpage/portal/meine-daten/name]]' => 'My data',
	'[[/_nino/webpage/portal/meine-daten/title]]' => 'My data',
	'[[/_nino/webpage/portal/meine-daten/description]]' => 'Your details as a client. They go on your next documents; documents that are finished do not change any more.',
	'[[/_nino/webpage/portal/projekte/name]]' => 'Projects',
	'[[/_nino/webpage/portal/projekte/title]]' => 'Your projects',
	'[[/_nino/webpage/portal/projekte/description]]' => 'What we are working on for you: the offer, the files to listen to and download and the documents of a project.',
	'[[/_nino/webpage/portal/upload/name]]' => 'Upload',
	'[[/_nino/webpage/portal/upload/title]]' => 'Upload files',
	'[[/_nino/webpage/portal/upload/description]]' => 'What you upload here reaches us for all your projects together – drop us a line about what it belongs to.',
	'[[/_nino/webpage/portal/belege/name]]' => 'Documents',
	'[[/_nino/webpage/portal/belege/title]]' => 'Your documents',
	'[[/_nino/webpage/portal/belege/description]]' => 'Everything released to you, with its amount and its state: offers, invoices, contracts and privacy documents. What needs your signature, you sign here.',
	'[[/_nino/webpage/portal/termine/name]]' => 'Dates',
	'[[/_nino/webpage/portal/termine/title]]' => 'Your dates',
	'[[/_nino/webpage/portal/termine/description]]' => 'The dates coming up for you – with the subscription for your calendar.',
	'[[/_nino/webpage/portal/datenschutz/name]]' => 'Privacy consent',
	'[[/_nino/webpage/portal/datenschutz/title]]' => 'Privacy consent',
	'[[/_nino/webpage/portal/datenschutz/description]]' => 'Your consent to the privacy policy of the customer portal. We record when you agreed; if the text changes, we ask again.',

	// The details of the start page, the door (its Element-URI is '/', as the wizard writes it for a start page): the head reads them
	'[[/_nino/webpage//name]]' => 'Customer portal',
	'[[/_nino/webpage//title]]' => 'Customer portal',
	'[[/_nino/webpage//description]]' => 'Your access comes from us by e-mail – sign in with the link we send you.',

	// The portal's door - the site's start page, page-door.tpl
	'[[/template/page-door/intro/title]]'               => 'Customer portal',
	'[[/template/page-door/intro/text]]'                => 'Your access comes from us by e-mail. If you have none, write to us. Enter the e-mail address of your account – we send you a link that signs you in, no password needed.',

	// The Elements panel's own words for the type Positionen - the workbench's, not the site's (listed in the unit's blacklist)
	'[[/_admin/elements/field/positionen/title]]' => 'Title',
	'[[/_admin/elements/field/positionen/description]]' => 'Description',
	'[[/_admin/elements/field/positionen/unit]]' => 'Unit',
	'[[/_admin/elements/field/positionen/unit_code]]' => 'Unit of the electronic invoice',
	'[[/_admin/elements/field/positionen/price]]' => 'Price (net)',
	'[[/_admin/elements/field/positionen/vat]]' => 'VAT rate',
	'[[/_admin/elements/field/positionen/hidden]]' => 'Hidden',
	'[[/_admin/elements/field/positionen/sort]]' => 'Order',
	'[[/_admin/elements/type/positionen/hint]]' => 'What you sell: the texts are per language, price and VAT rate are the same for all. The unit of the electronic invoice is one of the codes H87 piece, HUR hour, MIN minute, DAY day, WEE week, MON month, LS lump sum, C62 one, KMT kilometre. Hidden takes an item out of the editor of the documents and off the page; with Hidden at No it is offered.',

	// The Elements panel's own words for the type Projekte - the workbench's, not the site's (listed in the unit's blacklist)
	'[[/_admin/elements/field/projekte/title]]' => 'Title',
	'[[/_admin/elements/field/projekte/client]]' => 'Client',
	'[[/_admin/elements/field/projekte/description]]' => 'Description',
	'[[/_admin/elements/field/projekte/started]]' => 'Start',
	'[[/_admin/elements/field/projekte/done]]' => 'Done',
	'[[/_admin/elements/field/projekte/folder]]' => 'Folder',
	'[[/_admin/elements/type/projekte/hint]]' => 'A project belongs to a client. The client is its number (K-0001), the folder the id of its folder in the Files panel. Done at No means the project is under way. Documents name a project; while one does, it cannot be deleted.',
];

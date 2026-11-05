<?php
declare(strict_types=1);

// The words of the portal pages - merged add-only into the project's text/en_US.php when the unit is applied; from
// then on editors keep them in the Text panel
return [

	// The details of the pages: name, title and description, read by the head of every page
	'[[/_nino/webpage/portal/name]]' => 'Portal',
	'[[/_nino/webpage/portal/title]]' => 'Client portal',
	'[[/_nino/webpage/portal/description]]' => 'Your projects, documents, dates and data in one place.',
	'[[/_nino/webpage/portal/meine-daten/name]]' => 'My data',
	'[[/_nino/webpage/portal/meine-daten/title]]' => 'My data',
	'[[/_nino/webpage/portal/meine-daten/description]]' => 'Your details as a client.',
	'[[/_nino/webpage/portal/projekte/name]]' => 'Projects',
	'[[/_nino/webpage/portal/projekte/title]]' => 'Your projects',
	'[[/_nino/webpage/portal/projekte/description]]' => 'Your projects with offer, files and documents.',
	'[[/_nino/webpage/portal/upload/name]]' => 'Upload',
	'[[/_nino/webpage/portal/upload/title]]' => 'Upload files',
	'[[/_nino/webpage/portal/upload/description]]' => 'Upload files for your projects.',
	'[[/_nino/webpage/portal/belege/name]]' => 'Documents',
	'[[/_nino/webpage/portal/belege/title]]' => 'Your documents',
	'[[/_nino/webpage/portal/belege/description]]' => 'Offers, invoices, contracts and more that are shared with you.',
	'[[/_nino/webpage/portal/termine/name]]' => 'Dates',
	'[[/_nino/webpage/portal/termine/title]]' => 'Your dates',
	'[[/_nino/webpage/portal/termine/description]]' => 'The dates that are coming up for you.',
	'[[/_nino/webpage/portal/datenschutz/name]]' => 'Privacy',
	'[[/_nino/webpage/portal/datenschutz/title]]' => 'Privacy',
	'[[/_nino/webpage/portal/datenschutz/description]]' => 'The privacy policy you agree to.',

	// The details of the start page, the door (its Element-URI is '/', as the wizard writes it for a start page): the head reads them
	'[[/_nino/webpage//name]]' => 'Sign in',
	'[[/_nino/webpage//title]]' => 'Sign in',
	'[[/_nino/webpage//description]]' => 'Sign in with the link we send you by email.',

	// Start, page-portal.tpl
	'[[/template/page-portal/intro/title]]' => 'Welcome to the client portal',
	'[[/template/page-portal/intro/text]]' => 'Here you find your projects, files, documents and dates.',

	// My data, page-portal-meine-daten.tpl
	'[[/template/page-portal-meine-daten/intro/title]]' => 'My data',
	'[[/template/page-portal-meine-daten/intro/text]]' => 'These are your details as a client. They go on your next documents; documents that are already final do not change.',

	// Projects, page-portal-projekte.tpl
	'[[/template/page-portal-projekte/intro/title]]' => 'Your projects',
	'[[/template/page-portal-projekte/intro/text]]' => 'Here you see what we are working on for you: the offer, the files to listen to and download, and the documents of a project.',

	// Upload, page-portal-upload.tpl
	'[[/template/page-portal-upload/intro/title]]' => 'Upload files',
	'[[/template/page-portal-upload/intro/text]]' => 'Upload files for us here.',

	// Documents, page-portal-belege.tpl
	'[[/template/page-portal-belege/intro/title]]' => 'Your documents',
	'[[/template/page-portal-belege/intro/text]]' => 'Everything shared with you, with where it stands: offers, invoices, contracts and privacy documents. You sign what needs your signature here.',

	// Dates, page-portal-termine.tpl
	'[[/template/page-portal-termine/intro/title]]' => 'Your dates',
	'[[/template/page-portal-termine/intro/text]]' => 'The dates that are coming up for you.',

	// Privacy, page-portal-privacy.tpl
	'[[/template/page-portal-privacy/intro/title]]' => 'Privacy',
	'[[/template/page-portal-privacy/intro/text]]' => 'Read the privacy policy and agree to it. We keep a note of when you agreed; if the text changes, we ask again.',

	// The portal's door - the site's start page, page-door.tpl
	'[[/template/page-door/intro/title]]'               => 'Sign in',
	'[[/template/page-door/intro/text]]'                => 'Enter the email address of your account. We send you a link by email - that signs you in, no password needed.',

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

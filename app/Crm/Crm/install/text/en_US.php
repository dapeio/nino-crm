<?php
declare(strict_types=1);

// The words of the portal pages - merged add-only into the project's text/en_US.php when the unit is applied; from
// then on editors keep them in the Text panel
return [

	// The details of the pages: name, title and description, read by the head of every page
	'[[/_nino/webpage/portal/name]]' => 'Portal',
	'[[/_nino/webpage/portal/title]]' => 'Client portal',
	'[[/_nino/webpage/portal/description]]' => 'Your files, recordings and signatures in one place.',
	'[[/_nino/webpage/portal/dateien/name]]' => 'Files',
	'[[/_nino/webpage/portal/dateien/title]]' => 'Your files',
	'[[/_nino/webpage/portal/dateien/description]]' => 'The files shared with you.',
	'[[/_nino/webpage/portal/review/name]]' => 'Recordings',
	'[[/_nino/webpage/portal/review/title]]' => 'Listen to recordings',
	'[[/_nino/webpage/portal/review/description]]' => 'The recordings that are ready for you.',
	'[[/_nino/webpage/portal/unterschriften/name]]' => 'Signatures',
	'[[/_nino/webpage/portal/unterschriften/title]]' => 'Signatures',
	'[[/_nino/webpage/portal/unterschriften/description]]' => 'The documents that wait for your signature.',
	'[[/_nino/webpage/portal/dokumente/name]]' => 'Documents',
	'[[/_nino/webpage/portal/dokumente/title]]' => 'Your documents',
	'[[/_nino/webpage/portal/dokumente/description]]' => 'The documents shared with you: offers, invoices, contracts and more.',
	'[[/_nino/webpage/portal/konto/name]]' => 'My account',
	'[[/_nino/webpage/portal/konto/title]]' => 'My account',
	'[[/_nino/webpage/portal/konto/description]]' => 'Your account in the client portal.',
	// The details of the start page, the door (its Element-URI is '/', as the wizard writes it for a start page): the head reads them
	'[[/_nino/webpage//name]]' => 'Sign in',
	'[[/_nino/webpage//title]]' => 'Sign in',
	'[[/_nino/webpage//description]]' => 'Sign in with the link we send you by email.',

	// The portal's start page, page-portal.tpl
	'[[/template/page-portal/intro/title]]'              => 'Welcome to the client portal',
	'[[/template/page-portal/intro/text]]'               => 'Here you find your files, recordings and signatures.',
	'[[/template/page-portal/link-files/label]]'         => 'Files',
	'[[/template/page-portal/link-files/href]]'          => '/portal/dateien',
	'[[/template/page-portal/link-review/label]]'        => 'Listen to recordings',
	'[[/template/page-portal/link-review/href]]'         => '/portal/review',
	'[[/template/page-portal/link-documents/label]]'      => 'Documents',
	'[[/template/page-portal/link-documents/href]]'       => '/portal/dokumente',
	'[[/template/page-portal/link-sign/label]]'          => 'Signatures',
	'[[/template/page-portal/link-sign/href]]'           => '/portal/unterschriften',
	'[[/template/page-portal/link-account/label]]'       => 'My account',
	'[[/template/page-portal/link-account/href]]'        => '/portal/konto',

	// Files, page-portal-files.tpl
	'[[/template/page-portal-files/intro/title]]'        => 'Files',
	'[[/template/page-portal-files/intro/text]]'         => 'The files shared with you. You can upload where your group has an upload folder.',
	'[[/template/page-portal-files/back/label]]'         => 'Back to the portal',
	'[[/template/page-portal-files/back/href]]'          => '/portal',

	// Review, page-portal-review.tpl
	'[[/template/page-portal-review/intro/title]]'       => 'Listen to recordings',
	'[[/template/page-portal-review/intro/text]]'        => 'Listen to the recordings that are ready for you.',
	'[[/template/page-portal-review/back/label]]'        => 'Back to the portal',
	'[[/template/page-portal-review/back/href]]'         => '/portal',

	// Signatures, page-portal-sign.tpl
	'[[/template/page-portal-sign/intro/title]]'         => 'Signatures',
	'[[/template/page-portal-sign/intro/text]]'          => 'The documents that wait for your signature.',
	'[[/template/page-portal-sign/back/label]]'          => 'Back to the portal',
	'[[/template/page-portal-sign/back/href]]'           => '/portal',

	// Documents, page-portal-documents.tpl
	'[[/template/page-portal-documents/intro/title]]' => 'Your documents',
	'[[/template/page-portal-documents/intro/text]]' => 'Everything shared with you, with where it stands: offers, invoices, contracts and privacy documents. You sign the contracts and privacy documents that need your signature here.',
	'[[/template/page-portal-documents/back/label]]' => 'Back to the portal',
	'[[/template/page-portal-documents/back/href]]' => '/portal',

	// Account, page-portal-account.tpl
	'[[/template/page-portal-account/intro/title]]'      => 'My account',
	'[[/template/page-portal-account/intro/text]]'       => 'You are signed in as:',
	'[[/template/page-portal-account/back/label]]'       => 'Back to the portal',
	'[[/template/page-portal-account/back/href]]'        => '/portal',

	// The portal's door - the site's start page, page-door.tpl
	'[[/template/page-door/intro/title]]'               => 'Sign in',
	'[[/template/page-door/intro/text]]'                => 'Enter the email address of your account. We send you a link by email - that signs you in, no password needed.',

	// The Elements panel's own words for the type Positionen - the workbench's, not the site's (listed in the unit's blacklist)
	'[[/_admin/elements/field/positionen/title]]' => 'Title',
	'[[/_admin/elements/field/positionen/description]]' => 'Description',
	'[[/_admin/elements/field/positionen/unit]]' => 'Unit',
	'[[/_admin/elements/field/positionen/price]]' => 'Price (net)',
	'[[/_admin/elements/field/positionen/vat]]' => 'VAT rate',
	'[[/_admin/elements/field/positionen/hidden]]' => 'Hidden',
	'[[/_admin/elements/field/positionen/sort]]' => 'Order',
	'[[/_admin/elements/type/positionen/hint]]' => 'What you sell: the texts are per language, price and VAT rate are the same for all. Hidden takes an item out of the editor of the documents and off the page; with Hidden at No it is offered.',
];

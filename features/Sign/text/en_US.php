<?php
declare(strict_types=1);

// The panel's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while
// the feature is active, never into the project's text files. The site's words are the other set, in
// features/Sign/install/text/
return [

	// The rail entry, named by Admin::nav() - and the name the head over the panel's screen shows, which is why the
	// screen draws no heading of its own
	'[[/_admin/sign/nav]]'						=> 'Signatures',

	// The Dashboard's tile (Admin::summary())
	'[[/_admin/sign/label/open]]'				=> 'Waiting for a signature',

	// What the screen says of itself
	'[[/_admin/sign/hint/intro]]'				=> 'Ask a member to sign a document of the PDF feature. The member gets a link by mail, reads the document and signs it on a page of the website; both PDFs are kept here, with a record of the signing.',
	'[[/_admin/sign/hint/none]]'				=> 'There is no template to ask for a signature yet: it needs a field for a member and one of the tags of the setting Tags of the templates. Make one in the panel PDF.',
	'[[/_admin/sign/hint/members]]'			=> 'There is no active member to ask yet. Add one in the panel Members.',
	'[[/_admin/sign/hint/title]]'				=> 'Left empty, the name of the template is the title.',
	'[[/_admin/sign/hint/days]]'				=> 'The link is valid for %s days.',

	// The form for a new request
	'[[/_admin/sign/label/new]]'				=> 'Ask for a signature',
	'[[/_admin/sign/label/account]]'			=> 'Member',
	'[[/_admin/sign/label/template]]'			=> 'Template',
	'[[/_admin/sign/label/choose]]'				=> 'Choose',
	'[[/_admin/sign/label/title]]'				=> 'Title',
	'[[/_admin/sign/label/ask]]'				=> 'Ask for the signature',
	'[[/_admin/sign/msg/asked]]'				=> 'The request is made and the mail is on its way.',
	'[[/_admin/sign/error/account]]'			=> 'Choose the member to ask.',
	'[[/_admin/sign/error/template]]'			=> 'Choose a template.',
	'[[/_admin/sign/error/input]]'				=> 'Fill in %s.',
	'[[/_admin/sign/error/ask]]'				=> 'The request could not be made.',

	// The list
	'[[/_admin/sign/empty]]'					=> 'No one has been asked for a signature yet.',
	'[[/_admin/sign/state/requested]]'			=> 'Waiting',
	'[[/_admin/sign/state/viewed]]'				=> 'Opened',
	'[[/_admin/sign/state/signed]]'				=> 'Signed',
	'[[/_admin/sign/state/declined]]'			=> 'Declined',
	'[[/_admin/sign/state/cancelled]]'			=> 'Cancelled',
	'[[/_admin/sign/state/expired]]'			=> 'Run out',
	'[[/_admin/sign/state/changed]]'			=> 'Changed',
	'[[/_admin/sign/label/created]]'			=> 'Asked',
	'[[/_admin/sign/label/viewed]]'				=> 'Opened',
	'[[/_admin/sign/label/signed]]'				=> 'Signed',
	'[[/_admin/sign/label/declined]]'			=> 'Declined',
	'[[/_admin/sign/label/changed]]'			=> 'Changed',
	'[[/_admin/sign/label/again]]'			=> 'Ask again',
	'[[/_admin/sign/label/expires]]'			=> 'Link valid until',
	'[[/_admin/sign/label/member-gone]]'		=> 'A member who is gone',

	// What a row does
	'[[/_admin/sign/label/shown]]'				=> 'Open the document',
	'[[/_admin/sign/label/signed-document]]'	=> 'Open the signed document',
	'[[/_admin/sign/label/remind]]'				=> 'Remind',
	'[[/_admin/sign/label/cancel]]'				=> 'Cancel',
	'[[/_admin/sign/label/delete]]'				=> 'Delete',
	'[[/_admin/sign/label/details]]'			=> 'The record',
	'[[/_admin/sign/label/hide]]'				=> 'Hide the record',
	'[[/_admin/sign/msg/reminded]]'				=> 'The link is sent again, with a new time.',
	'[[/_admin/sign/msg/again]]'				=> 'A new request is made and the mail is on its way.',
	'[[/_admin/sign/confirm/cancel]]'			=> 'Cancel the request for %s? The link stops working.',
	'[[/_admin/sign/confirm/delete]]'			=> 'Delete the request for %s with its files? This cannot be undone.',
	'[[/_admin/sign/error/remind]]'				=> 'The reminder could not be sent.',
	'[[/_admin/sign/error/cancel]]'				=> 'The request could not be cancelled.',
	'[[/_admin/sign/error/delete]]'				=> 'The request could not be deleted.',
	'[[/_admin/sign/error/again]]'			=> 'The request could not be asked again.',

	// The record of a signing, on a row
	'[[/_admin/sign/label/address]]'			=> 'Address',
	'[[/_admin/sign/label/agent]]'				=> 'Browser',
	'[[/_admin/sign/label/method]]'				=> 'Signature',
	'[[/_admin/sign/method/drawn]]'				=> 'Drawn on the page',
	'[[/_admin/sign/method/typed]]'				=> 'Typed name',
	'[[/_admin/sign/label/hash-shown]]'			=> 'SHA-256 of the document shown',
	'[[/_admin/sign/label/hash-signed]]'		=> 'SHA-256 of the signed document',
	'[[/_admin/sign/label/consent]]'			=> 'Agreed to',

	// What the panel says of a list that could not be loaded: the key Nino.adminUi.panel() takes for a failed load
	'[[/_admin/sign/error/load]]'				=> 'The requests could not be loaded.',

	// The sentences of the codes the panel answers with
	'[[/_admin/error/sign_template]]'			=> 'There is no such template (any more).',
	'[[/_admin/error/sign_member]]'				=> 'This template has no field for a member to sign.',
	'[[/_admin/error/sign_account]]'			=> 'There is no such member, or the account is switched off.',
	'[[/_admin/error/sign_input]]'				=> '%s is empty or longer than %s characters.',
	'[[/_admin/error/sign_long]]'				=> 'The title may have up to %s characters.',
	'[[/_admin/error/sign_unknown]]'			=> 'There is no such request (any more).',
	'[[/_admin/error/sign_state]]'				=> 'The request is over - it was signed, declined, cancelled or changed.',
	'[[/_admin/error/sign_mail]]'				=> 'The mail could not be sent. The request is there; press Remind to send it again.',
	'[[/_admin/error/sign_ratelimit]]'			=> 'The cap of mails an hour is reached. The request is there; press Remind later.',
];

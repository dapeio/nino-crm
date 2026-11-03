<?php
declare(strict_types=1);

// The words the site says - merged into the project's own text/en_US.php on every activation, add-only: a key
// the project already has stays. From then on an editor keeps them current in the Text panel and never opens a
// feature directory, the sentence the member agrees to included. The panel's own words are a different set, in
// features/Sign/text/
return [

	// The signing page, page-sign.tpl
	'[[/template/page-sign/intro/title]]'		=> 'Your signature',
	'[[/_nino/webpage/.sign/name]]'		=> 'Signature',
	'[[/_nino/webpage/.sign/title]]'		=> 'Your signature',
	'[[/_nino/webpage/.sign/description]]'	=> 'Read a document, agree and sign it.',

	// The form, [sign-form]
	'[[/feature/sign/form/document]]'			=> 'The document to sign',
	'[[/feature/sign/form/open]]'				=> 'Open the document in a window of its own',
	'[[/feature/sign/form/intro]]'			=> 'Read the document below. If you agree, tick the box, sign with your finger, a pen or the mouse - or type your name - and press Sign.',
	'[[/feature/sign/form/consent]]'			=> 'I have read the document and I agree to sign it electronically. I know that the time, my address and my browser are recorded with my signature.',
	'[[/feature/sign/form/pad]]'				=> 'Sign in the box',
	'[[/feature/sign/form/pad-fallback]]'		=> 'This browser cannot draw here. Type your name below instead.',
	'[[/feature/sign/form/clear]]'				=> 'Clear',
	'[[/feature/sign/form/name]]'				=> 'Or type your full name',
	'[[/feature/sign/form/sign]]'				=> 'Sign',
	'[[/feature/sign/form/decline]]'			=> 'Decline',
	'[[/feature/sign/form/signed-link]]'		=> 'Open the signed document',

	// What the page says above its buttons or instead of its form: the states of a request and of a post
	'[[/feature/sign/state/signed]]'			=> 'This document is signed. Thank you. The signed document is in your area of the website, and a link to it was sent to you by mail.',
	'[[/feature/sign/state/declined]]'			=> 'You declined to sign this document. This request is closed.',
	'[[/feature/sign/state/cancelled]]'			=> 'This request was taken back. There is nothing to sign.',
	'[[/feature/sign/state/expired]]'			=> 'This link has run out. Please ask for a new one.',
	'[[/feature/sign/state/invalid]]'			=> 'This link does not lead to a document - it is wrong, or the document is not yours. If it is yours, sign in as a member and open it from your list.',
	'[[/feature/sign/state/consent]]'			=> 'Please tick the box before you sign.',
	'[[/feature/sign/state/empty]]'				=> 'Please sign first: draw your signature, or type your name.',
	'[[/feature/sign/state/limit]]'				=> 'Too many tries. Please try again in an hour.',
	'[[/feature/sign/state/changed]]'			=> 'The document was changed after it was sent to you. Nothing is signed, and this request is closed: the owner is told and will ask you again.',
	'[[/feature/sign/state/again]]'			=> 'Your session ran out while the page was open. Please open the page again and sign once more.',
	'[[/feature/sign/state/write]]'				=> 'Your signature could not be stored. Please try again later.',

	// The list, [sign-list]: how a request stands, and the links of a row
	'[[/feature/sign/status/requested]]'		=> 'Waiting for your signature',
	'[[/feature/sign/status/viewed]]'			=> 'Opened, not signed yet',
	'[[/feature/sign/status/signed]]'			=> 'Signed',
	'[[/feature/sign/status/declined]]'			=> 'Declined',
	'[[/feature/sign/status/cancelled]]'		=> 'Taken back',
	'[[/feature/sign/status/expired]]'			=> 'Run out',
	'[[/feature/sign/list/none]]'				=> 'There are no documents for you to sign.',
	'[[/feature/sign/list/page]]'				=> 'Open',
	'[[/feature/sign/list/signed]]'				=> 'Signed document',

	// The record, the page that is added to the signed PDF
	'[[/feature/sign/record/title]]'			=> 'Record of the signing',
	'[[/feature/sign/record/intro]]'			=> 'This page was added when the document was signed. It records the circumstances of the signing. The hash is that of the document as it was shown, before the signature and this page were added.',
	'[[/feature/sign/record/label-document]]'	=> 'Document',
	'[[/feature/sign/record/label-request]]'	=> 'Request',
	'[[/feature/sign/record/label-signer]]'		=> 'Signed by',
	'[[/feature/sign/record/label-time]]'		=> 'Time (UTC)',
	'[[/feature/sign/record/label-address]]'	=> 'Address',
	'[[/feature/sign/record/label-agent]]'		=> 'Browser',
	'[[/feature/sign/record/label-method]]'		=> 'Signature',
	'[[/feature/sign/record/label-hash]]'		=> 'SHA-256 of the document shown',
	'[[/feature/sign/record/label-consent]]'	=> 'Agreed to',
	'[[/feature/sign/record/note]]'				=> 'A simple electronic signature. The time, the address and the browser are what the website saw when the signature was sent.',
	'[[/feature/sign/method/drawn]]'			=> 'Drawn on the page',
	'[[/feature/sign/method/typed]]'			=> 'Typed name',
	'[[/feature/sign/outcome/signed]]'			=> 'has signed',
	'[[/feature/sign/outcome/declined]]'		=> 'has declined to sign',
	'[[/feature/sign/outcome/changed]]'		=> 'tried to sign, but the document is not the one that was shown - nothing is signed',

	// The three mails: the request, the signed document, and what the owner is told
	'[[/feature/sign/mail/request-subject]]'	=> 'A document is waiting for your signature',
	'[[/feature/sign/mail/request-title]]'		=> 'Please sign a document',
	'[[/feature/sign/mail/greeting]]'			=> 'Hello',
	'[[/feature/sign/mail/request-intro]]'		=> 'You are asked to sign this document. The button opens a page that shows it and asks for one signature - you need no password.',
	'[[/feature/sign/mail/request-button]]'		=> 'Read and sign',
	'[[/feature/sign/mail/request-valid]]'		=> 'The link is valid for',
	'[[/feature/sign/mail/request-days]]'		=> 'days.',
	'[[/feature/sign/mail/request-notice]]'		=> 'If you did not expect this email, ignore it: without the link nobody can sign for you.',
	'[[/feature/sign/mail/signed-subject]]'		=> 'Your signed document',
	'[[/feature/sign/mail/signed-title]]'		=> 'Thank you for signing',
	'[[/feature/sign/mail/signed-intro]]'		=> 'You have signed this document. The signed copy, with a page that records the signing, is kept for you on the website: sign in as a member to open it.',
	'[[/feature/sign/mail/signed-button]]'		=> 'Open the signed document',
	'[[/feature/sign/mail/signed-notice]]'		=> 'If you did not sign it, write to us at once.',
	'[[/feature/sign/mail/owner-signed-subject]]'	=> 'A member signed a document',
	'[[/feature/sign/mail/owner-declined-subject]]'	=> 'A member declined to sign a document',
	'[[/feature/sign/mail/owner-changed-subject]]'	=> 'A document changed before a member could sign it',
	'[[/feature/sign/title/signed]]'		=> 'A member signed',
	'[[/feature/sign/title/declined]]'		=> 'A member declined',
	'[[/feature/sign/title/changed]]'		=> 'A document changed',
	'[[/feature/sign/mail/owner-document]]'		=> 'The document:',
	'[[/feature/sign/mail/owner-button]]'		=> 'Open the panel Signatures',
	'[[/feature/sign/mail/closing]]'			=> 'Kind regards',
];

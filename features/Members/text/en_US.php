<?php
declare(strict_types=1);

// The panel's own words, one file per interface language (see Admin::text()):
// merged into the workbench's fills while the feature is active, never into the
// project's text files. The site's words are the other set, in
// features/Members/install/text/
return [

	// The rail entry, named by Admin::nav() - and the name the head over the
	// panel's screen shows, which is why the screen draws no heading of its own
	'[[/_admin/members/nav]]'					=> 'Members',

	// The Dashboard's tile (Admin::summary())
	'[[/_admin/members/label/count]]'			=> 'Members',

	'[[/_admin/members/hint/intro]]'			=> 'The accounts of the members\' area. A member has no password: they sign in with a link that is sent to their email address. Which pages are for members, and how long a link works, is set in the Features panel.',

	// Signing everybody out
	'[[/_admin/members/label/signout]]'			=> 'Sign everybody out',
	'[[/_admin/members/confirm/signout]]'		=> 'Every member is signed out and has to ask for a new link. Continue?',
	'[[/_admin/members/msg/signedout]]'			=> 'Everybody is signed out.',
	'[[/_admin/members/error/signout]]'			=> 'Nobody could be signed out.',

	// The form for a new member
	'[[/_admin/members/label/new]]'				=> 'New member',
	'[[/_admin/members/label/email]]'			=> 'Email address',
	'[[/_admin/members/label/name]]'			=> 'Name',
	'[[/_admin/members/label/group]]'			=> 'Group',
	'[[/_admin/members/hint/group]]'			=> 'One word, eg. premium. Leave empty for no group.',
	'[[/_admin/members/label/invite]]'			=> 'Send an invitation',
	'[[/_admin/members/hint/invite]]'			=> 'The member gets a link by email to sign in for the first time.',
	'[[/_admin/members/label/add]]'				=> 'Add member',
	'[[/_admin/members/msg/added]]'				=> 'Member added.',
	'[[/_admin/members/msg/invited]]'			=> 'Member added, invitation sent.',
	'[[/_admin/members/error/email]]'			=> 'Enter the email address of the new member.',
	'[[/_admin/members/error/add]]'				=> 'The member could not be added.',

	// The list
	'[[/_admin/members/label/filter]]'			=> 'Group',
	'[[/_admin/members/filter/all]]'			=> 'All groups',
	'[[/_admin/members/filter/none]]'			=> 'No group',
	'[[/_admin/members/empty]]'					=> 'No members yet.',
	'[[/_admin/members/empty/filter]]'			=> 'No member in this group.',
	'[[/_admin/members/state/active]]'			=> 'Active',
	'[[/_admin/members/state/inactive]]'		=> 'Inactive',
	'[[/_admin/members/label/login]]'			=> 'Last sign-in',
	'[[/_admin/members/label/never]]'			=> 'Never signed in',

	// What is done to an account
	'[[/_admin/members/label/edit]]'			=> 'Edit',
	'[[/_admin/members/label/save]]'			=> 'Save',
	'[[/_admin/members/label/cancel]]'			=> 'Cancel',
	'[[/_admin/members/label/active]]'			=> 'Active',
	'[[/_admin/members/hint/active]]'			=> 'A member who is not active cannot sign in, and is signed out at once.',
	'[[/_admin/members/confirm/discard]]'		=> 'What you typed is not saved. Discard it?',
	'[[/_admin/members/error/save]]'			=> 'The change could not be saved.',
	'[[/_admin/members/label/link]]'			=> 'Send login link',
	'[[/_admin/members/msg/sent]]'				=> 'Link sent.',
	'[[/_admin/members/label/notify]]'				=> 'Send the link by e-mail',
	'[[/_admin/members/hint/notify]]'				=> 'Applies to the invitation of a new member and to "Send login link". Unticked, nothing is sent; the link is shown to you once.',
	'[[/_admin/members/label/the-link]]'				=> 'Link to sign in',
	'[[/_admin/members/hint/link-once]]'				=> 'This link is shown now only and kept nowhere. Whoever has it signs in as this member - hand it over yourself. It is good as long as a sent link.',
	'[[/_admin/members/label/copy]]'				=> 'Copy',
	'[[/_admin/members/label/close-link]]'				=> 'Close',
	'[[/_admin/members/msg/copied]]'				=> 'Copied.',
	'[[/_admin/members/msg/selected]]'				=> 'Selected - copy it with Ctrl+C.',
	'[[/_admin/members/msg/invited-quiet]]'				=> 'Member added, invitation made, without a mail. The link is above.',
	'[[/_admin/members/msg/sent-quiet]]'				=> 'Link made, without a mail. It is above.',
	'[[/_admin/members/error/link]]'			=> 'The link could not be sent.',
	'[[/_admin/members/label/delete]]'			=> 'Delete',
	'[[/_admin/members/confirm/delete]]'		=> 'Delete %s? The account is gone for good.',
	'[[/_admin/members/error/delete]]'			=> 'The member could not be deleted.',

	// What the panel says of a list that could not be loaded: the key
	// Nino.adminUi.panel() takes for a failed load
	'[[/_admin/members/error/load]]'			=> 'The members could not be loaded.',

	// The sentences of the codes the panel answers with (Admin::_failed(), apiAdd(), apiLink())
	'[[/_admin/error/members_invalid]]'			=> 'That is not a valid email address.',
	'[[/_admin/error/members_taken]]'			=> 'A member with this address exists already.',
	'[[/_admin/error/members_long]]'			=> 'The name may have up to %s characters.',
	'[[/_admin/error/members_unknown]]'			=> 'There is no such member (any more).',
	'[[/_admin/error/members_inactive]]'		=> 'A member who is not active cannot be sent a link.',
	'[[/_admin/error/members_mail]]'			=> 'The mail could not be sent. Check the mail settings and the address of the website, then try again.',
	'[[/_admin/error/members_ratelimit]]'		=> 'The cap of mails an hour from this address is reached. Try again later.',
];

<?php
declare(strict_types=1);

// The words the site says - merged into the project's own text/en_US.php on
// every activation, add-only: a key the project already has stays. From then on
// an editor keeps them current in the Text panel and never opens a feature
// directory. The panel's own words are a different set, in features/Members/text/.
return [

	// The login page, page-members-login.tpl
	'[[/template/page-members-login/intro/title]]'	=> 'Sign in',
	'[[/template/page-members-login/intro/text]]'	=> 'Enter the email address of your account. We send you a link by email - with it you sign in, without a password.',

	// The page a link opens, page-members-confirm.tpl
	'[[/template/page-members-confirm/intro/title]]'	=> 'Confirm your sign-in',
	'[[/template/page-members-confirm/intro/text]]'	=> 'Press the button to finish signing in. Nothing has happened yet - the link you followed only brought you here.',

	// The form, [members-login]
	'[[/feature/members/login/label]]'	=> 'Email address',
	'[[/feature/members/login/button]]'	=> 'Send login link',

	// The button of that page, [members-confirm]
	'[[/feature/members/confirm/intro]]'	=> 'You are signing in as',
	'[[/feature/members/confirm/button]]'	=> 'Sign in now',

	// What the forms say above their button: the states of ?members=
	'[[/feature/members/state/sent]]'		=> 'If this address belongs to a member, a link to sign in is on its way. Look in your spam folder as well.',
	'[[/feature/members/state/limit]]'		=> 'Too many requests. Please try again in an hour.',
	'[[/feature/members/state/invalid]]'	=> 'This link does not work any more. Ask for a new one below.',
	'[[/feature/members/state/out]]'		=> 'You are signed out.',
	'[[/feature/members/state/expired]]'	=> 'Your session has run out. Please try again.',

	// A signed-in member: the line of [members-login], and the link of [members-logout]
	'[[/feature/members/signed/label]]'	=> 'Signed in as',
	'[[/feature/members/logout/label]]'	=> 'Sign out',

	// The mail with a link to sign in, and the invitation. The number of minutes
	// and of days stands between two of these words
	'[[/feature/members/mail/login-subject]]'	=> 'Your login link',
	'[[/feature/members/mail/login-title]]'		=> 'Sign in',
	'[[/feature/members/mail/login-intro]]'		=> 'You asked for a link to sign in. The button opens a page where you confirm your sign-in with one more click - you need no password.',
	'[[/feature/members/mail/login-button]]'	=> 'Sign in',
	'[[/feature/members/mail/login-valid]]'		=> 'The link works once and is valid for',
	'[[/feature/members/mail/login-minutes]]'	=> 'minutes.',
	'[[/feature/members/mail/login-notice]]'	=> 'If you did not ask for it, ignore this email: without the link nobody can sign in with your address.',
	'[[/feature/members/mail/invite-subject]]'	=> 'Your invitation',
	'[[/feature/members/mail/invite-title]]'	=> 'You are invited to',
	'[[/feature/members/mail/invite-intro]]'	=> 'An account has been set up for you. The button opens a page where you confirm your first sign-in with one more click - you need no password. Later you ask for a new link on the login page.',
	'[[/feature/members/mail/invite-button]]'	=> 'Accept the invitation',
	'[[/feature/members/mail/invite-valid]]'	=> 'The invitation works once and is valid for',
	'[[/feature/members/mail/invite-days]]'		=> 'days.',
	'[[/feature/members/mail/invite-notice]]'	=> 'If you did not expect this invitation, ignore this email.',
	'[[/feature/members/mail/closing]]'			=> 'Kind regards',
];

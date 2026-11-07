<?php
declare(strict_types=1);

// The words the site says - merged into the project's own text/en_US.php on
// every activation, add-only: a key the project already has stays. From then on
// an editor keeps them current in the Text panel and never opens a feature
// directory. The panel's own words are a different set, in features/Members/text/.
return [

	// The login page, page-members-login.tpl
	'[[/template/page-members-login/intro/title]]'	=> 'Sign in',
	'[[/template/page-members-login/intro/lead]]'	=> 'Enter the email address of your account. We send you a code by email - with it you sign in, without a password.',

	// The page a link opens, page-members-confirm.tpl
	'[[/template/page-members-confirm/intro/title]]'	=> 'Confirm your sign-in',
	'[[/template/page-members-confirm/intro/text]]'	=> 'Press the button to finish signing in. Nothing has happened yet - the link you followed only brought you here.',

	// The form, [members-login]: the first step asks for the address, the second for the code
	'[[/feature/members/login/label]]'	=> 'Email address',
	'[[/feature/members/login/request]]'	=> 'Send code',
	'[[/feature/members/code/intro]]'	=> 'We sent a code to',
	'[[/feature/members/code/label]]'	=> 'Your code',
	'[[/feature/members/code/button]]'	=> 'Sign in',
	'[[/feature/members/code/again]]'	=> 'Request a new code',
	'[[/feature/members/code/remember]]'	=> 'Stay signed in on this device',

	// The button of that page, [members-confirm]
	'[[/feature/members/confirm/intro]]'	=> 'You are signing in as',
	'[[/feature/members/confirm/button]]'	=> 'Sign in now',

	// What the forms say above their button: the states of ?members=
	'[[/feature/members/state/code]]'		=> 'If this address belongs to an account, a code is on its way. Look in your spam folder as well.',
	'[[/feature/members/state/limit]]'		=> 'Too many requests. Please try again in an hour.',
	'[[/feature/members/state/invalid]]'	=> 'This link does not work any more. Ask for a new one below.',
	'[[/feature/members/state/out]]'		=> 'You are signed out.',
	'[[/feature/members/state/expired]]'	=> 'Your session has run out. Please try again.',
	'[[/feature/members/state/wrong]]'		=> 'The code is not right. Try again.',
	'[[/feature/members/state/gone]]'		=> 'This code is no longer valid. Request a new one.',

	// A signed-in member: the line of [members-login], and the link of [members-logout]
	'[[/feature/members/signed/label]]'	=> 'Signed in as',
	'[[/feature/members/logout/label]]'	=> 'Sign out',

	// The mail with a code to sign in, the one with the link to sign in that the owner sends, and the
	// invitation. The number of minutes and of days stands between two of these words
	'[[/feature/members/mail/code-subject]]'	=> 'Your login code',
	'[[/feature/members/mail/code-title]]'		=> 'Sign in',
	'[[/feature/members/mail/code-intro]]'		=> 'You asked for a code to sign in. Enter it on the page where you asked for it - you need no password.',
	'[[/feature/members/mail/code-valid]]'		=> 'The code works once and is valid for',
	'[[/feature/members/mail/code-minutes]]'	=> 'minutes.',
	'[[/feature/members/mail/code-notice]]'		=> 'If you did not ask for it, ignore this email: without the code nobody can sign in with your address.',
	'[[/feature/members/mail/login-subject]]'	=> 'Your login link',
	'[[/feature/members/mail/login-title]]'		=> 'Sign in',
	'[[/feature/members/mail/login-intro]]'		=> 'You asked for a link to sign in. The button opens a page where you confirm your sign-in with one more click - you need no password.',
	'[[/feature/members/mail/login-button]]'	=> 'Sign in',
	'[[/feature/members/mail/login-valid]]'		=> 'The link works once and is valid for',
	'[[/feature/members/mail/login-minutes]]'	=> 'minutes.',
	'[[/feature/members/mail/login-notice]]'	=> 'If you did not ask for it, ignore this email: without the link nobody can sign in with your address.',
	'[[/feature/members/mail/invite-subject]]'	=> 'Your invitation',
	'[[/feature/members/mail/invite-title]]'	=> 'You are invited to',
	'[[/feature/members/mail/invite-intro-code]]'	=> 'An account has been set up for you. The button opens a page where you confirm your first sign-in with one more click - you need no password. Later you enter your address on the login page and get a code by e-mail.',
	'[[/feature/members/mail/invite-button]]'	=> 'Accept the invitation',
	'[[/feature/members/mail/invite-valid]]'	=> 'The invitation works once and is valid for',
	'[[/feature/members/mail/invite-days]]'		=> 'days.',
	'[[/feature/members/mail/invite-notice]]'	=> 'If you did not expect this invitation, ignore this email.',

	// The mail with a code for a new sign-in address (to the new address) and the notice to the old address that it was changed
	'[[/feature/members/mail/change-subject]]'	=> 'Your code for the new sign-in address',
	'[[/feature/members/mail/change-title]]'	=> 'Confirm the new sign-in address',
	'[[/feature/members/mail/change-intro]]'	=> 'Somebody wants to enter this email address as the sign-in address of an account. Enter the code on the page where it was asked for, to confirm the address.',
	'[[/feature/members/mail/change-valid]]'	=> 'The code works once and is valid for',
	'[[/feature/members/mail/change-minutes]]'	=> 'minutes.',
	'[[/feature/members/mail/change-notice]]'	=> 'If that was not you, ignore this email: nothing happens without the code.',
	'[[/feature/members/mail/changed-subject]]'	=> 'Your sign-in address was changed',
	'[[/feature/members/mail/changed-title]]'	=> 'Sign-in address changed',
	'[[/feature/members/mail/changed-intro]]'	=> 'The sign-in address of your account was changed. From now on you sign in with another address; links and codes that were still out for your account no longer work.',
	'[[/feature/members/mail/changed-when]]'	=> 'Changed on',
	'[[/feature/members/mail/changed-notice]]'	=> 'If that was not you, write to us:',
	'[[/feature/members/mail/closing]]'			=> 'Kind regards',
];

<?php
declare(strict_types=1);

// The sentences of the codes the API of the clients answers a refusal with (see Surface::texts(), which hands them to the surface as
// error.<code>), one file per language. The words of the screens are the surface's own, in Surface/text/de_DE.php
return [

	'[[/_admin/error/crm_clients_database]]' => 'The database did not take the request.',
	'[[/_admin/error/crm_clients_email]]' => 'The email address is not valid.',
	'[[/_admin/error/crm_clients_empty]]' => 'A client needs a company or a name.',
	'[[/_admin/error/crm_clients_format]]' => 'The value does not have the right format.',
	'[[/_admin/error/crm_clients_id]]' => 'The number of the client is not valid.',
	'[[/_admin/error/crm_clients_inactive]]' => 'The client is deactivated. Activate it to invite, link or switch on accounts.',
	'[[/_admin/error/crm_clients_long]]' => 'The value may have up to %s characters.',
	'[[/_admin/error/crm_clients_members]]' => 'The Members feature is not active. Switch it on in the Features panel.',
	'[[/_admin/error/crm_clients_account]]' => 'The id of the account is not valid.',
	'[[/_admin/error/crm_clients_held]]' => 'This account belongs to a client already.',
	'[[/_admin/error/crm_clients_mail]]' => 'The email could not be sent. Check the mail settings and the address of the website, then try again.',
	'[[/_admin/error/crm_clients_member]]' => 'This account does not (any more) belong to this client.',
	'[[/_admin/error/crm_clients_off]]' => 'The account is switched off. Switch it on to send it a link.',
	'[[/_admin/error/crm_clients_ratelimit]]' => 'The limit of emails an hour that the kernel sets has been reached for this IP address. Try again later.',
	'[[/_admin/error/crm_clients_write]]' => 'The account could not be written.',
	'[[/_admin/error/crm_clients_taken]]' => 'There is an account for this email address already.',
	'[[/_admin/error/crm_clients_unknown]]' => 'There is no such client.',
	'[[/_admin/error/crm_clients_link_perm]]' => 'Only an account that may manage the Members panel can have a link shown without the mail. Send the mail or ask somebody who holds that permission.',
	'[[/_admin/error/crm_clients_link]]' => 'The link could not be made. The account is there; ask for the link again from the menu of the account.',
];

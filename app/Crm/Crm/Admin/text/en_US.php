<?php
declare(strict_types=1);

// The panel CRM's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while the
// module is active, never into the project's text files. The surface at /crm reads the sentences of the status and of the setup
// from here as well (Surface::texts()). The words of the portal pages are the other set, in unit/text/
return [

	'[[/_admin/crm/nav]]' => 'CRM',
	'[[/_admin/crm/label/open]]' => 'Open the CRM',
	'[[/_admin/crm/label/setup]]' => 'Set up the portal',
	'[[/_admin/crm/error/load]]' => 'The status of the module could not be read.',
	'[[/_admin/crm/error/status]]' => 'The state of the module could not be read.',
	'[[/_admin/crm/error/setup]]' => 'The portal could not be set up.',
	'[[/_admin/crm/manual/intro]]' => 'The CRM works in a surface of its own at /crm: clients, documents, items and settings. Here in the workbench there is only the way there, the state of the module and the button for the portal.',

	'[[/_admin/crm/setup/done]]' => 'Set up. New: routes %s, templates %s, element types %s, words %s. There already: %s.',
	'[[/_admin/crm/status/allok]]' => 'All is ready: the portal is set up and the features run.',
	'[[/_admin/crm/status/module-off]]' => 'The module is not in /nino/modules. Add \\Crm\\Crm to private/config.php.',
	'[[/_admin/crm/status/data-unwritable]]' => 'The directory data/ is not writable, the database cannot be created.',
	'[[/_admin/crm/status/portal-none]]' => 'The portal is not set up yet. Press Set up the portal.',
	'[[/_admin/crm/status/lacks-routes]]' => 'routes',
	'[[/_admin/crm/status/lacks-templates]]' => 'templates',
	'[[/_admin/crm/status/lacks-types]]' => 'the element type Positionen',
	'[[/_admin/crm/status/lacks-paths]]' => 'the protected path /portal',
	'[[/_admin/crm/status/portal-partial]]' => 'The portal is only partly set up, it lacks: %s. Press Set up the portal, it adds what is missing.',
	'[[/_admin/crm/status/members-missing]]' => 'The feature Members is not installed.',
	'[[/_admin/crm/status/members-off]]' => 'The feature Members is installed but not active. Switch it on in the Features panel.',
	'[[/_admin/crm/status/members-problem]]' => 'The feature Members has a problem, see the Features panel.',
	'[[/_admin/crm/status/files-missing]]' => 'The feature Files is not installed.',
	'[[/_admin/crm/status/files-off]]' => 'The feature Files is installed but not active. Switch it on in the Features panel.',
	'[[/_admin/crm/status/files-problem]]' => 'The feature Files has a problem, see the Features panel.',
	'[[/_admin/crm/status/review-missing]]' => 'The feature Review is not installed.',
	'[[/_admin/crm/status/review-off]]' => 'The feature Review is installed but not active. Switch it on in the Features panel.',
	'[[/_admin/crm/status/review-problem]]' => 'The feature Review has a problem, see the Features panel.',
	'[[/_admin/crm/status/sign-missing]]' => 'The feature Sign is not installed.',
	'[[/_admin/crm/status/sign-off]]' => 'The feature Sign is installed but not active. Switch it on in the Features panel.',
	'[[/_admin/crm/status/sign-problem]]' => 'The feature Sign has a problem, see the Features panel.',
	'[[/_admin/crm/status/pdf-missing]]' => 'The feature PDF is not installed.',
	'[[/_admin/crm/status/pdf-off]]' => 'The feature PDF is installed but not active. Switch it on in the Features panel.',
	'[[/_admin/crm/status/pdf-problem]]' => 'The feature PDF has a problem, see the Features panel.',

	'[[/_admin/error/crm_setup_members]]' => 'The Members feature is not active. Switch it on in the Features panel.',
];

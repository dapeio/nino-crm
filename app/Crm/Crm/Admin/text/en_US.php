<?php
declare(strict_types=1);

// The panel CRM's own words, one file per interface language (see Admin::text()): merged into the workbench's fills while the
// module is active, never into the project's text files. The surface at /crm reads the sentences of the status and of the setup
// from here as well (Surface::texts()). The words of the portal pages are the other set, in install/text/
return [

	'[[/_admin/crm/nav]]' => 'CRM',
	'[[/_admin/crm/label/open]]' => 'Open the CRM',
	'[[/_admin/crm/label/setup]]' => 'Set up the portal',
	'[[/_admin/crm/error/load]]' => 'The status of the module could not be read.',
	'[[/_admin/crm/error/status]]' => 'The state of the module could not be read.',
	'[[/_admin/crm/error/setup]]' => 'The portal could not be set up.',
	'[[/_admin/crm/manual/intro]]' => 'The CRM works in a surface of its own at /crm: clients, documents, items and settings. Here in the workbench there is only the way there, the state of the module and the button for the portal.',

	'[[/_admin/crm/setup/done]]' => 'Set up. New: features %s, routes %s, templates %s, element types %s, words %s, PDF templates %s. There already: %s.',
	'[[/_admin/crm/setup/home-new]]' => 'The start page is now the door of the portal.',
	'[[/_admin/crm/setup/home-starter]]' => 'The start page is now the door of the portal; the wizard\'s page /home is no longer linked.',
	'[[/_admin/crm/setup/home-own]]' => 'Your start page stays; the door of the portal is at /portal.',
	'[[/_admin/crm/status/allok]]' => 'All is ready: the portal is set up and the features run.',
	'[[/_admin/crm/status/module-off]]' => 'The module is not in /nino/modules. Add \\Crm\\Crm to private/config.php.',
	'[[/_admin/crm/status/data-unwritable]]' => 'The directory data/ is not writable, the database cannot be created.',
	'[[/_admin/crm/status/portal-none]]' => 'The portal is not set up yet. Setting up creates it.',
	'[[/_admin/crm/status/lacks-routes]]' => 'routes',
	'[[/_admin/crm/status/lacks-templates]]' => 'templates',
	'[[/_admin/crm/status/lacks-types]]' => 'the element type Positionen',
	'[[/_admin/crm/status/lacks-texts]]' => 'the words of the pages',
	'[[/_admin/crm/status/lacks-start]]' => 'the start page of the Members feature',
	'[[/_admin/crm/status/pdf-templates-none]]' => 'The PDF templates are missing. Setting up creates them.',
	'[[/_admin/crm/status/pdf-templates-partial]]' => 'PDF templates are missing: %s. Setting up creates them, yours stay.',
	'[[/_admin/crm/status/home-other]]' => 'The start page of the site is not the door of the portal yet. Setting up makes it so.',
	'[[/_admin/crm/status/home-own]]' => 'Start page: your own page, not the door.',
	'[[/_admin/crm/status/lacks-paths]]' => 'the protected path /portal',
	'[[/_admin/crm/status/portal-partial]]' => 'The portal is only partly set up, it lacks: %s. Setting up adds what is missing.',
	'[[/_admin/crm/status/members-missing]]' => 'The feature Members is not installed.',
	'[[/_admin/crm/status/members-off]]' => 'The feature Members is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/members-problem]]' => 'The feature Members has a problem, see the Features panel.',
	'[[/_admin/crm/status/files-missing]]' => 'The feature Files is not installed.',
	'[[/_admin/crm/status/files-off]]' => 'The feature Files is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/files-problem]]' => 'The feature Files has a problem, see the Features panel.',
	'[[/_admin/crm/status/review-missing]]' => 'The feature Review is not installed.',
	'[[/_admin/crm/status/review-off]]' => 'The feature Review is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/review-problem]]' => 'The feature Review has a problem, see the Features panel.',
	'[[/_admin/crm/status/sign-missing]]' => 'The feature Sign is not installed.',
	'[[/_admin/crm/status/sign-off]]' => 'The feature Sign is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/sign-problem]]' => 'The feature Sign has a problem, see the Features panel.',
	'[[/_admin/crm/status/pdf-missing]]' => 'The feature PDF is not installed.',
	'[[/_admin/crm/status/pdf-off]]' => 'The feature PDF is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/pdf-problem]]' => 'The feature PDF has a problem, see the Features panel.',
	'[[/_admin/crm/status/events-missing]]' => 'The feature Events is not installed.',
	'[[/_admin/crm/status/events-off]]' => 'The feature Events is installed but not active. Setting up switches it on.',
	'[[/_admin/crm/status/events-problem]]' => 'The feature Events has a problem, see the Features panel.',

	'[[/_admin/error/crm_setup_feature]]' => 'The feature %s could not be switched on. The Features panel names the reason.',
	'[[/_admin/error/crm_setup_pdf]]' => 'The PDF templates could not be created: %s.',
];

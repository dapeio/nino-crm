<?php
declare(strict_types=1);

// The install unit of the CRM module, found by the kernel's setup wizard (\Nino\Install\Setup::units() scans app/ for
// install/manifest.php; tests/install-smoke.php of the kernel holds it to that): the wizard offers it pre-checked, lists the
// module class in /nino/modules and applies the unit with overwrite on - the pages of the customer portal, their words, the
// element type of the items. The door of the portal (the start page of the site) is no part of it: the screen Einrichtung of the
// surface writes the route GET:// when the owner presses Einrichten, and the same step applies this unit again, add-only (the
// panel CRM and the settings of the surface keep the button Portal einrichten, through \Nino\Features::applyUnit(): a route,
// a template or a text the project has stays as it is). No config: a unit's config writes top-level keys of config.php and cannot
// write the setting paths of the Members feature - the Einrichtung sets it with Features::saveSettings(). The blacklist is the
// Elements panel's own words for the type (workbench, not site), so the Text panel does not offer them; the words of the pages are
// the site's and stay editable
return [
	'key'             => 'crm',
	'label'           => 'CRM & Kundenportal',
	'preset'          => true,
	'moduleClass'     => '\\Crm\\Crm',
	'routes'          => [
		'GET://portal'               => [ 'uri' => '/portal', 'body' => '[template /templates/page-portal]' ],
		'GET://portal/dateien'       => [ 'uri' => '/portal/dateien', 'body' => '[template /templates/page-portal-files]' ],
		'GET://portal/review'        => [ 'uri' => '/portal/review', 'body' => '[template /templates/page-portal-review]' ],
		'GET://portal/unterschriften' => [ 'uri' => '/portal/unterschriften', 'body' => '[template /templates/page-portal-sign]' ],
		'GET://portal/dokumente'     => [ 'uri' => '/portal/dokumente', 'body' => '[template /templates/page-portal-documents]' ],
		'GET://portal/konto'         => [ 'uri' => '/portal/konto', 'body' => '[template /templates/page-portal-account]' ],
	],
	'templates'       => [
		'page-portal.tpl',
		'page-portal-files.tpl',
		'page-portal-review.tpl',
		'page-portal-sign.tpl',
		'page-portal-documents.tpl',
		'page-portal-account.tpl',
		'page-door.tpl',
	],
	'elementTypes'    => [ 'positionen.php' ],
	'blacklist'       => [
		'/_admin/elements/field/positionen/title',
		'/_admin/elements/field/positionen/description',
		'/_admin/elements/field/positionen/unit',
		'/_admin/elements/field/positionen/price',
		'/_admin/elements/field/positionen/vat',
		'/_admin/elements/field/positionen/hidden',
		'/_admin/elements/field/positionen/sort',
		'/_admin/elements/type/positionen/hint',
	],
];

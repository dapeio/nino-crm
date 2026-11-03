<?php
declare(strict_types=1);

// The install unit of the CRM module: the pages of the customer portal and the login page. The panel Kunden applies it
// with the button Portal einrichten (Clients\Admin::apiSetup(), through \Nino\Features::applyUnit(), add-only: a
// route, a template or a text the project has stays as it is). It lies in unit/ and not in install/ on purpose: the
// kernel's wizard offers every install/manifest.php below app/ and expects the module it names to be a \Nino\Modules
// class, which a project module is not (tests/install-smoke.php holds the kernel to that). No config: a unit's config
// writes top-level keys of config.php and cannot write the setting paths of the Members feature - the panel sets it
// with Features::saveSettings(), where the project protects no path yet. No blacklist: nothing here is filled at
// request time as a text key
return [
	'routes'          => [
		'GET://portal'               => [ 'uri' => '/portal', 'body' => '[template /templates/page-portal]' ],
		'GET://portal/dateien'       => [ 'uri' => '/portal/dateien', 'body' => '[template /templates/page-portal-files]' ],
		'GET://portal/review'        => [ 'uri' => '/portal/review', 'body' => '[template /templates/page-portal-review]' ],
		'GET://portal/unterschriften' => [ 'uri' => '/portal/unterschriften', 'body' => '[template /templates/page-portal-sign]' ],
		'GET://portal/konto'         => [ 'uri' => '/portal/konto', 'body' => '[template /templates/page-portal-account]' ],
		'GET://login'                => [ 'uri' => '/login', 'body' => '[template /templates/page-login]' ],
	],
	'templates'       => [
		'page-portal.tpl',
		'page-portal-files.tpl',
		'page-portal-review.tpl',
		'page-portal-sign.tpl',
		'page-portal-account.tpl',
		'page-login.tpl',
	],
];

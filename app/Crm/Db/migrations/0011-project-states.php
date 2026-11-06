<?php
declare(strict_types=1);

// The eleventh schema of the CRM: what the owner has decided about a project for the portal. A project is an element of the type /projekte, which a
// site gets once and keeps as it was installed, so the decision lives here and not in a field of the type: one row for a project that has one, the
// id of the element as the key, whether its client may download the files (1) or only listen (0, which a project without a row is), the day it
// was switched on and the account of the workbench that did it. The row goes when the project does. Returns the statements in the order they run,
// one string each
return [

	'CREATE TABLE project_states (
		project TEXT PRIMARY KEY,
		download INTEGER NOT NULL DEFAULT 0,
		download_at TEXT NOT NULL DEFAULT \'\',
		download_by TEXT NOT NULL DEFAULT \'\'
	)',
];

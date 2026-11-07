<?php
declare(strict_types=1);

// The fifteenth schema of the CRM: the phase a project is in, beside what the owner has decided about it for the portal. The column is added to the
// table project_states (a project is an element of a type that a site keeps as it was installed, so the phase lives here and not in a field of the
// type): one of recording, mix, master and review, or empty for a project that has none - which is what a project without a row has as well.
// Returns the statements in the order they run, one string each
return [

	'ALTER TABLE project_states ADD COLUMN phase TEXT NOT NULL DEFAULT \'\'',
];

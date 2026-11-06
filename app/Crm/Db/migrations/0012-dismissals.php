<?php
declare(strict_types=1);

// The twelfth schema of the CRM: the messages a member has dismissed. A row says who (the id of the member) dismissed which text (the SHA-256 of the text of a
// message, to every client or to one) and when (UTC); a message whose text the owner changes has another sum, so it is shown again. Returns the statements in the
// order they run, one string each
return [

	'CREATE TABLE dismissals (
		member TEXT NOT NULL,
		sha256 TEXT NOT NULL,
		at TEXT NOT NULL,
		PRIMARY KEY ( member, sha256 )
	)',
];

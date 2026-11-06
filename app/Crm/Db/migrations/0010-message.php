<?php
declare(strict_types=1);

// The tenth schema of the CRM: the message a client reads in the portal. message is the text the owner wrote to this client (empty for none) and message_at the
// time it was written (UTC, empty for none); a client made before this schema has neither. The message to every client is a setting (Settings::DEFAULTS).
// Nothing else changes. Returns the statements in the order they run, one string each
return [

	'ALTER TABLE clients ADD COLUMN message TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE clients ADD COLUMN message_at TEXT NOT NULL DEFAULT \'\'',
];

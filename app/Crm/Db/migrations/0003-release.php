<?php
declare(strict_types=1);

// The third schema of the CRM: what the three documents without lines (contract, privacy, information) and the release to the
// portal need on a document - the title of its own, whether it is released and since when, and the request for a signature in
// Sign with its state and the day it was signed. Nothing else changes: the categories and the states are lists in PHP, as
// migration 0002 says. Returns the statements in the order they run, one string each
return [

	'ALTER TABLE documents ADD COLUMN title TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN released INTEGER NOT NULL DEFAULT 0',
	'ALTER TABLE documents ADD COLUMN released_at TEXT',
	'ALTER TABLE documents ADD COLUMN sign_id TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN sign_state TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN signed_at TEXT',

	// The portal asks for the released documents of one client
	'CREATE INDEX documents_client_released ON documents ( client_id, released )',
];

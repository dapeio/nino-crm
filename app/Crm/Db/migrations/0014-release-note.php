<?php
declare(strict_types=1);

// The fourteenth schema of the CRM: the sentence the owner adds to the mail of a release is kept with the document, so that the history says what the customer was told.
// release_note is the text of the last release that went out by mail with a sentence, empty for none; release_note_at is the time of that release. Nothing else changes.
// Returns the statements in the order they run, one string each
return [

	'ALTER TABLE documents ADD COLUMN release_note TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN release_note_at TEXT',
];

<?php
declare(strict_types=1);

// The ninth schema of the CRM: what an electronic invoice (XRechnung) needs beyond what a PDF does. A client says whether it is a business
// (only a business gets the file) and which buyer reference it quotes; a document keeps the reference of its own, the path and hash of the
// file written when it was finalised, and - for a business whose file could not be written - the list of what was missing then; a line carries the unit
// code of the position it comes from. Existing rows keep the empty values: a document finalised before this schema never gets a file. The
// seller's particulars are settings (Settings::DEFAULTS); the two with a preset are written here where they are missing. Nothing else changes.
// Returns the statements in the order they run, one string each
return [

	'ALTER TABLE clients ADD COLUMN business INTEGER NOT NULL DEFAULT 0',
	'ALTER TABLE clients ADD COLUMN buyer_reference TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN buyer_reference TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN xml_path TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN xml_sha256 TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE documents ADD COLUMN xml_lacks TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE document_lines ADD COLUMN unit_code TEXT NOT NULL DEFAULT \'\'',

	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'seller_country\', \'DE\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'vat_exempt_text\', \'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG\' )',
];

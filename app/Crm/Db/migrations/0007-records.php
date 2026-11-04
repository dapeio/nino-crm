<?php
declare(strict_types=1);

// The seventh schema of the CRM: the records of what a customer agreed to in the portal. A record is the evidence: the text or the offer the
// customer was shown (the Datenschutz as it stood, the JSON an offer's page was drawn from), its hash, the account that agreed, the time and the
// address and the browser the request came from. kind is privacy or order. A client with records cannot be deleted and neither can a document that
// an order names (ON DELETE RESTRICT), and the module has no statement that changes or deletes a record. Returns the statements in the order they run,
// one string each
return [

	'CREATE TABLE records (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		kind TEXT NOT NULL,
		client_id INTEGER NOT NULL REFERENCES clients( id ) ON DELETE RESTRICT,
		member_id TEXT NOT NULL,
		member_email TEXT NOT NULL DEFAULT \'\',
		member_name TEXT NOT NULL DEFAULT \'\',
		document_id INTEGER REFERENCES documents( id ) ON DELETE RESTRICT,
		project TEXT NOT NULL DEFAULT \'\',
		sha256 TEXT NOT NULL,
		shown TEXT NOT NULL,
		at TEXT NOT NULL,
		ip TEXT NOT NULL DEFAULT \'\',
		agent TEXT NOT NULL DEFAULT \'\'
	)',

	'CREATE INDEX records_client_kind ON records ( client_id, kind )',
	'CREATE INDEX records_document ON records ( document_id )',
];

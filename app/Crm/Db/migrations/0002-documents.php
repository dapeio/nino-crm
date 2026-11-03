<?php
declare(strict_types=1);

// The second schema of the CRM: the items, the number sequences, the documents and their lines, and the settings the
// documents read. A setting that is there stays as it is (vat_mode is C01's). Returns the statements in the order they run
// The ids of the three tables count on (AUTOINCREMENT): the id of a deleted draft is never given to the next document, so a screen
// that still shows the old one cannot write into the new one
// No CHECK on category and state: the model lists them (Documents::CATEGORIES, TRANSITIONS) and a later patch adds kinds and states,
// which SQLite cannot alter in a CHECK without rebuilding the table
return [

	'CREATE TABLE items (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		title TEXT NOT NULL,
		description TEXT NOT NULL DEFAULT \'\',
		unit TEXT NOT NULL DEFAULT \'\',
		price_net INTEGER NOT NULL DEFAULT 0,
		vat_rate INTEGER NOT NULL DEFAULT 0,
		active INTEGER NOT NULL DEFAULT 1,
		sort INTEGER NOT NULL DEFAULT 0,
		created TEXT,
		updated TEXT
	)',

	'CREATE TABLE sequences (
		category TEXT NOT NULL,
		year INTEGER NOT NULL,
		next INTEGER NOT NULL,
		PRIMARY KEY ( category, year )
	)',

	'CREATE TABLE documents (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		category TEXT NOT NULL,
		client_id INTEGER NOT NULL REFERENCES clients( id ) ON DELETE RESTRICT,
		parent_id INTEGER REFERENCES documents( id ) ON DELETE RESTRICT,
		number TEXT UNIQUE,
		state TEXT NOT NULL,
		date TEXT NOT NULL,
		due_date TEXT,
		valid_until TEXT,
		period_from TEXT,
		period_to TEXT,
		vat_mode TEXT NOT NULL,
		reverse_charge INTEGER NOT NULL DEFAULT 0,
		net INTEGER NOT NULL DEFAULT 0,
		vat INTEGER NOT NULL DEFAULT 0,
		gross INTEGER NOT NULL DEFAULT 0,
		template_key TEXT NOT NULL DEFAULT \'\',
		inputs_json TEXT NOT NULL DEFAULT \'{}\',
		client_json TEXT NOT NULL DEFAULT \'{}\',
		notes TEXT NOT NULL DEFAULT \'\',
		pdf_path TEXT NOT NULL DEFAULT \'\',
		pdf_sha256 TEXT NOT NULL DEFAULT \'\',
		finalised TEXT,
		sent_at TEXT,
		paid_at TEXT,
		created TEXT,
		updated TEXT
	)',

	'CREATE INDEX documents_category_state ON documents ( category, state )',
	'CREATE INDEX documents_client ON documents ( client_id )',
	'CREATE INDEX documents_date ON documents ( date )',

	// Lines go with their draft and with nothing else: a finalised document is never deleted
	'CREATE TABLE document_lines (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		document_id INTEGER NOT NULL REFERENCES documents( id ) ON DELETE CASCADE,
		sort INTEGER NOT NULL,
		title TEXT NOT NULL,
		description TEXT NOT NULL DEFAULT \'\',
		qty INTEGER NOT NULL,
		unit TEXT NOT NULL DEFAULT \'\',
		price_net INTEGER NOT NULL,
		vat_rate INTEGER NOT NULL DEFAULT 0,
		amount_net INTEGER NOT NULL
	)',

	'CREATE INDEX document_lines_document ON document_lines ( document_id, sort )',

	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'vat_mode\', \'small_business\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'vat_rates\', \'1900,700,0\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'payment_days\', \'14\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'offer_days\', \'30\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'currency\', \'€\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'number_offer\', \'A-{YYYY}-{NNNN}\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'number_invoice\', \'R-{YYYY}-{NNNN}\' )',
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'number_credit\', \'G-{YYYY}-{NNNN}\' )',
];

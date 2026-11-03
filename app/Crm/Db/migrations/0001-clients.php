<?php
declare(strict_types=1);

// The first schema of the CRM: the clients, the accounts of the Members feature that belong to them, and the
// settings of the module. Returns the statements in the order they run, one string each
return [

	'CREATE TABLE clients (
		id INTEGER PRIMARY KEY,
		number TEXT NOT NULL UNIQUE,
		company TEXT NOT NULL DEFAULT \'\',
		name TEXT NOT NULL DEFAULT \'\',
		street TEXT NOT NULL DEFAULT \'\',
		zip TEXT NOT NULL DEFAULT \'\',
		city TEXT NOT NULL DEFAULT \'\',
		country TEXT NOT NULL DEFAULT \'DE\',
		email TEXT NOT NULL DEFAULT \'\',
		phone TEXT NOT NULL DEFAULT \'\',
		vat_id TEXT NOT NULL DEFAULT \'\',
		notes TEXT NOT NULL DEFAULT \'\',
		active INTEGER NOT NULL DEFAULT 1,
		created TEXT NOT NULL,
		updated TEXT NOT NULL
	)',

	'CREATE TABLE client_accounts (
		client_id INTEGER NOT NULL REFERENCES clients( id ) ON DELETE RESTRICT,
		member_id TEXT NOT NULL,
		PRIMARY KEY ( client_id, member_id )
	)',

	'CREATE INDEX client_accounts_member ON client_accounts ( member_id )',

	'CREATE TABLE settings (
		key TEXT PRIMARY KEY,
		value TEXT NOT NULL
	)',

	// The owner is a small business (Kleinunternehmer); later patches read the mode
	'INSERT OR IGNORE INTO settings ( key, value ) VALUES ( \'vat_mode\', \'small_business\' )',
];

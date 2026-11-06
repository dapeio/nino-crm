<?php
declare(strict_types=1);

// The thirteenth schema of the CRM: what a customer consented to, beside what the evidence already holds. A consent is a record of kind consent and
// names its purpose (social or archive); its revocation is a record of kind revocation that names the consent in parent. Both columns are added to
// the table of the records, which keeps its rules: a record is written once and never changed or deleted, and the table refuses the deletion of the
// record a revocation names, and a consent has one revocation at most. The first index finds the consents of a client by purpose. Returns the statements in the order they run, one string each
return [

	'ALTER TABLE records ADD COLUMN purpose TEXT NOT NULL DEFAULT \'\'',
	'ALTER TABLE records ADD COLUMN parent INTEGER REFERENCES records( id ) ON DELETE RESTRICT',
	'CREATE INDEX records_client_purpose ON records ( client_id, purpose )',
	'CREATE UNIQUE INDEX records_parent ON records ( parent ) WHERE parent IS NOT NULL',
];

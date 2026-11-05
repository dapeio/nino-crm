<?php
declare(strict_types=1);

// The eighth schema of the CRM: the payment reminder is noted on the invoice whether the owner sent the mail or not - he may have phoned.
// reminded_at is the time of the last reminder, empty for an invoice that was never reminded; reminded_mail says whether that reminder went out
// by mail (1) or was noted without one (0). Nothing else changes. Returns the statements in the order they run, one string each
return [

	'ALTER TABLE documents ADD COLUMN reminded_at TEXT',
	'ALTER TABLE documents ADD COLUMN reminded_mail INTEGER NOT NULL DEFAULT 0',
];

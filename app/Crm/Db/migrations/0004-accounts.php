<?php
declare(strict_types=1);

// The fourth schema of the CRM: an account of Members belongs to at most one client. Until now the key (client_id, member_id)
// let one account sit on two clients; no panel made such a row, but Clients::link() could. The first link of an account is the
// one that stays (the lowest rowid), the later ones go, and a unique index keeps it so. Returns the statements in the order
// they run, one string each
return [

	'DELETE FROM client_accounts WHERE rowid NOT IN ( SELECT MIN( rowid ) FROM client_accounts GROUP BY member_id )',

	'CREATE UNIQUE INDEX client_accounts_member_unique ON client_accounts ( member_id )',
];

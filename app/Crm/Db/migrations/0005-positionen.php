<?php
declare(strict_types=1);

// The fifth schema of the CRM: the items are elements of the type Positionen (the Elements panel keeps them, the install unit brings
// the type), so the table that held them goes. Nothing is carried over - the rows were the owner's test data. The lines of the
// documents keep the values they copied, and document_lines is not touched. Returns the statements in the order they run, one string each
return [

	'DROP TABLE IF EXISTS items',
];

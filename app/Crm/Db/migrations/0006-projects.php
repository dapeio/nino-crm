<?php
declare(strict_types=1);

// The sixth schema of the CRM: a document may belong to a project. The project is an element of the type /projekte (the Elements panel keeps
// them, the install unit brings the type), so the column holds the id of the element - the last part of its uri, 00001 - and no foreign
// key can point at it; '' is no project. The column is no part of the finalised content (the PDF and its hash do not read it), which is why a
// finalised document may be moved to another project. Returns the statements in the order they run, one string each
return [

	'ALTER TABLE documents ADD COLUMN project TEXT NOT NULL DEFAULT \'\'',

	'CREATE INDEX documents_project ON documents ( project )',
];

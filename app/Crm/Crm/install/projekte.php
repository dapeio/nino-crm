<?php
declare(strict_types=1);

// unit/projekte.php - the element type the projects are kept in, copied to /elements/projekte.php where the project has none (the
// manifest's elementTypes, add-only: a type of this name that the project has stays as it is, whatever fields it changed). A project
// is an element of this type, entered in the screen Projekte of the surface or in the Elements panel; \Crm\Projekte reads them, a document
// names one by its id (the last part of its uri) and the files of a project lie in a folder of the feature Files.
//
// The shape is the one \Nino\Elements::insertElementType() writes: the model, then one bucket per locale plus '*' for what is not
// translated, and the counter of a numbered type. A project has no name of its own that a person would have to invent: it is numbered
// (00001, 00002 ...) as the Elements panel numbers the elements of a type that says so, and the title is a field. Empty - a project
// that was seeded is a project somebody has to find and delete.
//
// Nothing of a project is per language: it has one name, one note and one day, whatever language the site is shown in. The client is the number as
// \Crm\Clients writes it (K-0001), the folder the id of a folder of Files, empty where the project has none
return [
	'title' => 'Projekte',
	'autoincrement' => 1,
	'model' => [
		'title' => [
			'type'			=> 'string',
			'required'	=> true,
			'maxlength'	=> 200,
		],
		// The number of the client the project is for, K-0001
		'client' => [
			'type'			=> 'string',
			'required'	=> true,
			'maxlength'	=> 10,
		],
		'description' => [
			'type'			=> 'string',
			'breaks'		=> true,
			'inputsize'	=> 4,
			'maxlength'	=> 2000,
		],
		// The day the project begins
		'started' => [
			'type'			=> 'date',
		],
		// Abgeschlossen. Off is the usual case: a new project is under way without anybody touching the switch
		'done' => [
			'type'			=> 'boolean',
		],
		// The id of the project's folder in Files; empty where there is none
		'folder' => [
			'type'			=> 'string',
			'maxlength'	=> 40,
		],
	],
	'*' => [
		'*' => [ 'done' => false ],
	],
];

<?php
// features/Events/install/events.php - the element type the dates are kept in, copied to /elements/events.php where a
// project has none. A date is an element of this type, entered in the Elements panel: there is no panel of the
// feature's own.
//
// The shape is the one \Nino\Elements::insertElementType() writes: the model, then one bucket per locale plus '*' for
// what is not translated. Empty - a calendar starts empty, and a date that was seeded is a date somebody has to find
// and delete.
//
// The moments are global: a start and an end are the same in every language, and a date exists in every language that
// way. The words - title, place, text - are per language, and a language that has none shows the native one's, as the
// feed does. A start and an end are datetime fields (the form writes Y-m-d\TH:i), read as the wall-clock time of the
// site
return [
	'title' => 'Events',
	'model' => [
		'title' => [
			'type'			=> 'string',
			'locale'		=> true,
			'required'	=> true,
			'maxlength'	=> 200,
		],
		'start' => [
			'type'			=> 'datetime',
			'required'	=> true,
		],
		// Optional: a date that ends is shown as a range, and is upcoming until it has
		'end' => [
			'type'			=> 'datetime',
		],
		'place' => [
			'type'			=> 'string',
			'locale'		=> true,
			'maxlength'	=> 200,
		],
		// Paragraphs and lists; the feed carries it as plain text
		'text' => [
			'type'			=> 'string',
			'locale'		=> true,
			'html'			=> true,
			'blocks'		=> true,
			'inputsize'	=> 6,
		],
		// An address with a scheme (https://...) or one of this site (/contact)
		'link' => [
			'type'			=> 'string',
			'maxlength'	=> 500,
		],
		// The slug of a group of the Members feature, or nothing for a date that is for everyone
		'group' => [
			'type'			=> 'string',
			'maxlength'	=> 60,
		],
	],
	'*' => [
		'*' => [],
	],
];

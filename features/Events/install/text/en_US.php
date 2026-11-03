<?php
declare(strict_types=1);

// The words the components and the feed say - merged into the project's text/en_US.php on every activation (add-only:
// a key the project already has stays, one it lacks is added); editors keep them current in the Text panel from then on.
// The month and the day of a date are no words of the feature's: they come from the locale (see Events::pattern())
return [

	// The chip: a day in words, and the word between the two ends of a range that spans days
	'[[/feature/events/when/today]]'		=> 'Today',
	'[[/feature/events/when/tomorrow]]'	=> 'Tomorrow',
	'[[/feature/events/when/until]]'		=> 'until',

	// The link to one date, and the two links to the feed - the member's carries their dates too
	'[[/feature/events/ics/label]]'			=> 'Add to calendar',
	'[[/feature/events/feed/label]]'		=> 'Subscribe to the calendar',
	'[[/feature/events/feed/member]]'		=> 'Subscribe to your calendar',

	// What the calendar is called where neither the setting nor the company has a name
	'[[/feature/events/feed/name]]'			=> 'Events',

	// What a stack says where it has nothing to list
	'[[/feature/events/state/upcoming]]'	=> 'There is nothing coming up.',
	'[[/feature/events/state/past]]'			=> 'Nothing has taken place yet.',
	'[[/feature/events/state/all]]'			=> 'There are no dates.',
];

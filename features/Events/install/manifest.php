<?php
// features/Events/install/manifest.php - what a project gets when the feature is activated: the element type the
// dates are kept in, and the words (install/text/<locale>.php). A unit is applied without overwriting (see
// \Nino\Features::activate()), so a project that has an element type called events already keeps it.
//
// No page template: the Builder composes the page a date list stands on from the stack. No route: the two routes
// are registered by Events::init(), so they follow the feature and go with it. No section of the privacy policy:
// nothing is stored about a visitor. No blacklist: every word is a stored one
return [
	'elementTypes' => [ 'events.php' ],
];

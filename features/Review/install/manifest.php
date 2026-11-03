<?php
// features/Review/install/manifest.php - the feature's section of the privacy policy and, by the files beside it, its
// words. No page template on purpose: the player stands where the Builder puts it, on a page behind a protected path of
// the Members feature (the manual says so), and the feature has no page of its own. No route either - the one endpoint is
// registered by Review::init() - and no blacklist: every word is a stored one, the request-time values of the player and
// the mails are bare tokens the class fills
return [
	// The feature's section of the privacy policy, added to the Legal module's type and never replacing a
	// section - see \Nino\Elements::seed(). A Nino without the module reads no such key
	'elements' => [ 'privacy' => 'elements/privacy.php' ],
];

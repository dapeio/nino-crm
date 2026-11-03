<?php
// features/Files/install/manifest.php - the feature's section of the privacy policy and, by the files beside it,
// its words. No page template on purpose: the two components stand where the Builder puts them, on a page behind
// the protected paths of the Members feature (the manual says so), and the feature has no page of its own. No
// route either - the two endpoints are registered by Files::init() - and no blacklist: every word is a stored one,
// the request-time values of the components and the mails are bare tokens the class fills
return [
	// The feature's section of the privacy policy, added to the Legal module's type and never replacing a
	// section - see \Nino\Elements::seed(). A Nino without the module reads no such key
	'elements' => [ 'privacy' => 'elements/privacy.php' ],
];

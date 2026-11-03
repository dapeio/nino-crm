<?php
// features/Sign/install/manifest.php - the signing page of the feature and its section of the privacy policy. No
// route: the route /.sign, which Sign::init() registers, renders the page in place (see Sign::_page()); a project that
// wants an address of its own for it gives it a route in the Routes panel. Nothing here is filled at request time as a
// text key - the request-time values of the components and of the mails are bare tokens the class fills, and the words
// they choose between are all in the text files - so there is no blacklist
return [
	'templates' => [ 'page-sign.tpl' ],
	// The feature's section of the privacy policy, added to the Legal module's type and never replacing a section - see
	// \Nino\Elements::seed(). A Nino without the module reads no such key
	'elements' => [ 'privacy' => 'elements/privacy.php' ],
];

<?php
declare(strict_types=1);

/**
 *	Nino
 *	sign-smoke.php	Contract test for the Sign feature (Modules\Sign): the manifest and the activation
 *									through \Nino\Features, the request and the data it leaves - the PDF as it is shown,
 *									its hash, the token that is mailed and kept as a hash -, the page the link opens and
 *									what a GET changes, the PDFs the kernel streams to the holder of the token, the member
 *									and the owner, the post that signs and the post that declines with every refusal, the
 *									record on the last page of the signed document, the reminder, the cancel and the
 *									delete, the two components, the panel and its permission with the codes of its
 *									refusals, the mails, the restore, deactivation, the look for the Design feature, and -
 *									where node is on the path - the scripts' own test. Travels with the feature and runs
 *									against the checkout three levels up, or the one NINO_ROOT names (see tests/harness.php
 *									there). The PDFs are Pdf's: its option append is pinned in features/Pdf/tests/.
 *
 *									The feature asks for a kernel that carries the file response, which its manifest names
 *									as the floor. On a kernel below that floor the activation is refused, as it has to be:
 *									the test says so, and does by hand what an activation does (the unit, the classes in
 *									/nino/modules, the version), so that everything else is still looked at.
 *
 *	Usage: php features/Sign/tests/sign-smoke.php
 *	       NINO_ROOT=../nino php features/Sign/tests/sign-smoke.php
 */

// The checkout this test runs against: three levels up when the feature sits in a project's features/, else the
// one NINO_ROOT names (a checkout beside the catalogue, say). The features root is this feature's own parent either
// way, so the kernel's autoloader serves the class from here
$root = getenv( 'NINO_ROOT' ) ?: dirname( __DIR__, 3 );
defined( 'NINO_FEATURES_DIR' ) === true || define( 'NINO_FEATURES_DIR', dirname( __DIR__, 2 ) );
require $root. '/tests/harness.php';

$appData = ninoSandbox( 'sign' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$sandbox = ninoSandboxDir( $appData );

// The project directory and its fill, which \Nino\request() registers from the same key on every request - empty here,
// the way a site at the domain root has it - and the facts of the website the mails are built on: its address, which
// every link of a mail starts with (\Nino\Html::absoluteUrl()), and the contact address
$appData['/nino/dir'] = '';

// The look of the site's mails: the base unit's words, which a project has from its wizard
if( is_file( $root. '/_admin/install/library/base/text/global.php' ) === true )
	\Nino\Html::addFills( $appData, (array) include $root. '/_admin/install/library/base/text/global.php', '*' );

\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club', '[[/project/company/contact/email]]' => 'owner@example.org', '[[/project/company/general/name]]' => 'The Club' ], '*' );

// What the kernel boots with, as far as the feature touches it: the components, the [csrf] field, the [template]
// shortcode and the guard that refuses a post without its token
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Csrf::init( $appData );

// The workbench's entry point, which ninoCall() hands a panel action
$panel = [ \Nino\Admin\Admin::class, 'handlePost' ];

/*	A transport takes the mail so nothing is actually sent - the same \Nino\Mail::TRANSPORT callback the Mailer feature
	registers. $delivery is what the stub says about the mail, so that a refusal is as easy to make as a delivery	*/
$mails = [];
$delivery = true;
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails, &$delivery ): void {
	$mails[] = $mail;
	$mail['sent'] = $delivery;
} );

// What the features that build on this one are told: the request that was signed or declined
$told = [];
foreach( [ '/sign/signed', '/sign/declined' ] as $event )
	\Nino\Callbacks::registerCallback( $appData, $event, static function( array &$appData, array &$entry ) use ( &$told, $event ): void {
		$told[] = [ $event, $entry ];
	} );

/**
 *	The index as it is stored, read fresh
 *
 *	@param		array 		&$appData
 *
 *	@return 	array
 */
function signIndex( array &$appData ): array {

	$state = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );

	return is_array( $state ) === true ? $state : [];
}

/**
 *	Switch the feature on - the way a project does, and by hand where the kernel is below its floor. Below the floor the
 *	activation is refused with the reason, and what an activation does besides is done here: the required features are
 *	activated, the unit is applied add-only, the class is listed in /nino/modules and the version is recorded
 *
 *	@param		array 		&$appData
 *
 *	@return 	true|string							True, or why not
 */
function signActivate( array &$appData ): true|string {

	if( ( \Nino\Features::get( $appData, 'sign' )['problems'] ?? [] ) === [] )
		return \Nino\Features::activate( $appData, 'sign' );

	foreach( [ 'pdf', 'members' ] as $required ) {

		$activated = \Nino\Features::activate( $appData, $required );

		if( $activated !== true )
			return $activated;
	}

	$routes = [];
	$blacklist = [];
	$config = [];
	$applied = \Nino\Features::applyUnit( $appData, dirname( __DIR__ ). '/install', \Nino\Locales::getAvailableLocales( $appData ), $routes, $blacklist, $config, false );

	if( $applied !== true )
		return $applied;

	\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {

		$content['/nino/modules'] = array_values( array_unique( array_merge( (array) ( $content['/nino/modules'] ?? [] ), [ '\\Nino\\Modules\\Sign' ] ) ) );
		$content['/nino/features']['sign'] = [ 'version' => '1.0.0', 'settings' => (array) ( $content['/nino/features']['sign']['settings'] ?? [] ) ];

		return $content;
	} );

	$persisted = \Nino\Filesystem::getFileContent( $appData, '/config.php', [] );
	$appData['/nino/modules'] = array_values( (array) ( $persisted['/nino/modules'] ?? [] ) );
	$appData['/nino/features'] = (array) ( $persisted['/nino/features'] ?? [] );
	unset( $appData['./nino/features/all'] );

	return true;
}

/**
 *	The elements of a rendered piece of html by xpath, as a document reads them
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	array										The DOMElements
 */
function signFind( string $html, string $query ): array {

	$document = new DOMDocument();
	libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="UTF-8"><body>'. $html. '</body>' );
	libxml_clear_errors();

	return iterator_to_array( ( new DOMXPath( $document ) )->query( $query ) ?: [] );
}

/**
 *	An attribute of the first of the elements signFind() found - '' where there is none, so that a check on a page that lacks
 *	the element fails and does not stop the run
 *
 *	@param		array 		$nodes
 *	@param		string		$name
 *
 *	@return 	string
 */
function signAttr( array $nodes, string $name ): string {

	return isset( $nodes[0] ) === true ? $nodes[0]->getAttribute( $name ) : '';
}

/**
 *	Whether the first of the elements found has an attribute - false where there is none
 *
 *	@param		array 		$nodes
 *	@param		string		$name
 *
 *	@return 	bool
 */
function signHas( array $nodes, string $name ): bool {

	return isset( $nodes[0] ) === true && $nodes[0]->hasAttribute( $name ) === true;
}

/**
 *	The text of the first of the elements found - '' where there is none
 *
 *	@param		array 		$nodes
 *
 *	@return 	string
 */
function signContent( array $nodes ): string {

	return isset( $nodes[0] ) === true ? (string) $nodes[0]->textContent : '';
}

/**
 *	A request of the site the way the web server hands it over, answered by the routes and the callbacks that are registered:
 *	the request built, the global callbacks of the response run - the csrf guard among them - and then the route's own.
 *	A post carries the csrf token of the session unless $csrf is false. Nothing is rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$method
 *	@param		array 		$post
 *	@param		bool			$csrf					Whether a post carries the token
 *	@param		array 		$server				More of $_SERVER: the address of the client, the browser as HTTP_USER_AGENT
 *
 *	@return 	array										The request, response and all
 */
function signServe( array &$appData, string $uri, string $method = 'GET', array $post = [], bool $csrf = true, array $server = [] ): array {

	// What a request keeps for itself alone is gone before the next one begins
	unset( $appData['./sign/request'], $appData['./sign/forms'] );

	$_POST = $post + ( $method === 'POST' && $csrf === true ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method, $server );
	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return $request;
}

/**
 *	A request of the site rendered the way a visitor is sent it: the response as the kernel makes it, the body through the
 *	fills and shortcodes
 *
 *	@param		array 		&$appData
 *	@param		array 		$request			As signServe() answers it
 *
 *	@return 	string										The body, rendered
 */
function signBody( array &$appData, array $request ): string {

	return is_string( $request['/nino/http/response']['body'] ?? null ) === true ? \Nino\Html::renderHtml( $appData, $request['/nino/http/response']['body'] ) : '';
}

/**
 *	A new window for every cap: the counter of the feature, the one of the login links and the mail cap of the kernel, which
 *	counts the same client
 *
 *	@param		array 		&$appData
 *
 *	@return 	void
 */
function signFresh( array &$appData ): void {

	foreach( [ 'sign-tries', 'members-tries', 'ratelimit' ] as $counter )
		\Nino\Filesystem::putFileContent( $appData, '/data/'. $counter. '.php', [] );
}

/**
 *	A visitor who has never been here: no session, nobody signed in
 *
 *	@return 	void
 */
function signVisitor(): void {

	$_SESSION = [];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

/**
 *	Who is at the workbench: nobody, the plain account (which may do nothing there) or the full-access one
 *
 *	@param		string		$who					'', 'plain' or 'admin'
 *
 *	@return 	void
 */
function signWorkbench( string $who ): void {

	global $appData;

	signVisitor();

	if( $who === 'admin' )
		\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );
	else if( $who === 'plain' )
		\Nino\Auth::loginUser( $appData, 'plain@example.com', 'plain password' );
}

/**
 *	Sign an account in the way a member does: a link is mailed, the page it opens asks, the button of that page signs in
 *
 *	@param		array 		&$appData
 *	@param		array 		$account
 *
 *	@return 	bool											Whether the member is signed in afterwards
 */
function signSignIn( array &$appData, array $account ): bool {

	global $mails;

	signFresh( $appData );
	signVisitor();

	$count = count( $mails );

	if( \Nino\Modules\Members::link( $appData, $account, 'login' ) !== true || preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( $mails[$count]['body'] ?? '' ), $found ) !== 1 )
		return false;

	signServe( $appData, '/.members/login?t='. $found[1] );
	signServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] );

	return ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

/**
 *	Speak as somebody: the session of the browser they signed in with, or none
 *
 *	@param		string		$who					A key of $GLOBALS['sessions'], or '' for a visitor
 *
 *	@return 	void
 */
function signAs( string $who ): void {

	$_SESSION = $who === '' ? [] : $GLOBALS['sessions'][$who];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

/**
 *	A picture a member drew: a transparent canvas with strokes on it, as the pad posts it
 *
 *	@param		int				$width
 *	@param		int				$height
 *	@param		bool			$strokes			Whether anything is drawn on it
 *
 *	@return 	string										The PNG bytes
 */
function signPng( int $width = 600, int $height = 200, bool $strokes = true ): string {

	$image = imagecreatetruecolor( $width, $height );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefill( $image, 0, 0, (int) imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

	if( $strokes === true ) {
		imagesetthickness( $image, 3 );
		imageline( $image, 30, 120, 200, 40, (int) imagecolorallocate( $image, 16, 22, 47 ) );
		imageline( $image, 200, 40, 380, 150, (int) imagecolorallocate( $image, 16, 22, 47 ) );
		imageline( $image, 380, 150, 560, 60, (int) imagecolorallocate( $image, 16, 22, 47 ) );
	}

	ob_start();
	imagepng( $image );

	return (string) ob_get_clean();
}

/**
 *	The data URL of picture bytes, as the canvas makes it
 *
 *	@param		string		$bytes
 *
 *	@return 	string
 */
function signUrl( string $bytes ): string {

	return 'data:image/png;base64,'. base64_encode( $bytes );
}

/**
 *	The number of pages of a PDF, and the content of one, inflated: the operators a layout wrote
 *
 *	@param		string		$pdf
 *
 *	@return 	int
 */
function signPages( string $pdf ): int {

	return preg_match_all( '#/Type /Page\b#', $pdf );
}

/**
 *	The text of a PDF as pdftotext reads it
 *
 *	@param		string		$pdf
 *	@param		string		$options
 *
 *	@return 	string
 */
function signText( string $pdf, string $options = '-layout' ): string {

	$file = ninoSandboxDir( $GLOBALS['appData'] ). '/read.pdf';
	file_put_contents( $file, $pdf );

	return (string) shell_exec( 'pdftotext '. $options. ' '. escapeshellarg( $file ). ' - 2>/dev/null' );
}

// --- The feature - manifest and activation -------------------------------------

echo "The feature - manifest and activation\n";

$dir = dirname( __DIR__ );
$manifest = \Nino\Features::manifest( $dir );
check( 'the manifest validates, key "sign", without a warning', is_array( $manifest ) && $manifest['key'] === 'sign' && ninoWarnings() === [] );
check( 'class, version and category are what the plan says: a security feature, 1.0.0, that requires the PDF feature and the Members', is_array( $manifest ) && $manifest['module'] === '\\Nino\\Modules\\Sign' && $manifest['version'] === '1.0.0'
	&& $manifest['category'] === 'security' && $manifest['requires'] === [ 'pdf', 'members' ] && $manifest['php'] === [ 'ext' => [ 'gd' ] ] && $manifest['maturity'] === '' );
check( 'it names itself in both interface languages', is_array( $manifest ) && $manifest['name'] === [ 'en_US' => 'Sign', 'de_DE' => 'Unterschrift' ]
	&& \Nino\Features::localized( $manifest['description'], 'de_DE' ) !== \Nino\Features::localized( $manifest['description'], 'en_US' ) );
check( 'its floor is above the kernel that has no file response (1.9.0), and a constraint of the same major version', is_array( $manifest ) && \Nino\Features::satisfies( $manifest['nino'], '1.9.0' ) === false
	&& \Nino\Features::satisfies( $manifest['nino'], '1.9.1' ) === true && \Nino\Features::satisfies( $manifest['nino'], '1.99.0' ) === true && \Nino\Features::satisfies( $manifest['nino'], '2.0.0' ) === false );
check( 'it owns the directory of the requests and nothing else - not the counter, which is an hour long - and brings its stylesheet and its script', is_array( $manifest ) && $manifest['data'] === [ '/data/sign' ]
	&& $manifest['assets'] === [ '/features/Sign/assets/sign.css', '/features/Sign/assets/sign.js' ] );
check( 'the settings are the four of the plan, each with its type, its default and its bounds', is_array( $manifest ) && array_keys( $manifest['settings'] ) === [ 'days', 'notifyOwner', 'attempts', 'tags' ]
	&& [ $manifest['settings']['days']['type'], $manifest['settings']['days']['default'], $manifest['settings']['days']['min'], $manifest['settings']['days']['max'], $manifest['settings']['days']['unit'] ] === [ 'int', 14, 1, 90, 'days' ]
	&& $manifest['settings']['notifyOwner']['type'] === 'bool' && $manifest['settings']['notifyOwner']['default'] === true
	&& [ $manifest['settings']['attempts']['type'], $manifest['settings']['attempts']['default'], $manifest['settings']['attempts']['min'], $manifest['settings']['attempts']['max'], $manifest['settings']['attempts']['unit'] ] === [ 'int', 20, 1, 100, 'per-hour' ]
	&& $manifest['settings']['tags']['type'] === 'lines' && $manifest['settings']['tags']['default'] === [ 'contract', 'privacy' ] );
check( 'it declares the two components, sign-form and sign-list - no stack - each with the picture, the tag and the places the Builder shows it by', is_array( $manifest ) && array_keys( $manifest['components'] ) === [ 'sign-form', 'sign-list' ] && $manifest['stacks'] === []
	&& $manifest['components']['sign-form']['draws'] === 'div .sign-form' && $manifest['components']['sign-form']['source'] === 'none' && $manifest['components']['sign-form']['in'] === [ 'section' ]
	&& $manifest['components']['sign-list']['draws'] === 'div .sign-list' && $manifest['components']['sign-list']['in'] === [ 'section', 'card' ]
	&& $manifest['components']['sign-list']['attributes']['open']['type'] === 'bool' && $manifest['components']['sign-list']['attributes']['open']['default'] === true );
$iconPaths = static fn( string $component ): array => array_map( static fn( array $part ): string => $part[0]. ':'. $part[1]['d'], $GLOBALS['manifest']['components'][$component]['icon'] ?? [] );
$penLine = [ 'M13 21h8', 'M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z' ];
check( 'the icons are Lucide\'s current ones: pen-line for the form - in the manifest and on the panel\'s rail - and file-pen-line, whose paths the old name file-signature no longer has, for the list',
	$iconPaths( 'sign-form' ) === [ 'path:'. $penLine[0], 'path:'. $penLine[1] ] && array_filter( $penLine, static fn( string $path ): bool => str_contains( \Nino\Modules\Sign\Admin::icon(), '<path d="'. $path. '"/>' ) === false ) === []
	&& $iconPaths( 'sign-list' ) === [ 'path:M14.364 13.634a2 2 0 0 0-.506.854l-.837 2.87a.5.5 0 0 0 .62.62l2.87-.837a2 2 0 0 0 .854-.506l4.013-4.009a1 1 0 0 0-3.004-3.004z', 'path:M14.487 7.858A1 1 0 0 1 14 7V2',
		'path:M20 19.645V20a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l2.516 2.516', 'path:M8 18h1' ] );
check( 'it says all six sections of its manual in both languages, and its section of the privacy policy as a line of the install section', is_array( $manifest ) && (function() use ( $manifest ): bool {
	foreach( \Nino\Features::MANUAL_SECTIONS as $section )
		foreach( (array) ( $manifest['manual'][$section] ?? [] ) as $line )
			if( is_array( $line ) === false || trim( (string) ( $line['en_US'] ?? '' ) ) === '' || trim( (string) ( $line['de_DE'] ?? '' ) ) === '' )
				return false;
	return isset( $manifest['manual']['install']['elements/privacy.php'], $manifest['manual']['install']['templates/page-sign.tpl'] ) === true && count( $manifest['manual']['shortcodes'] ) >= 3
		&& count( $manifest['manual']['routes'] ) === 5 && count( $manifest['manual']['callbacks'] ) === 3 && count( $manifest['manual']['panel'] ) >= 1;
} )() );

// The catalogue's preview reads every manifest through the kernel, and is a script of the catalogue: it is there when this test
// runs in the repository, not when the feature has been dropped into a project
$preview = dirname( __DIR__, 3 ). '/bin/catalogue.php';

if( is_file( $preview ) === false )
	echo "  note - no bin/catalogue.php beside this feature: the catalogue's preview was not asked\n";
else {
	$listed = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( $preview ). ' '. escapeshellarg( $root ). ' 2>/dev/null' ), true );
	check( 'the catalogue preview lists it, with its category and its version', is_array( $listed ) && count( array_filter( (array) ( $listed['features'] ?? [] ), static fn( mixed $entry ): bool => is_array( $entry ) === true
		&& ( $entry['key'] ?? '' ) === 'sign' && ( $entry['category'] ?? '' ) === 'security' && ( $entry['version'] ?? '' ) === '1.0.0' ) ) === 1 );
}

// The Legal module's type is what the feature's section is added to: put there first, where the kernel has the module
if( class_exists( '\\Nino\\Modules\\Legal' ) === true ) {
	$routes = [];
	$blacklist = [];
	$config = [];
	\Nino\Features::applyUnit( $appData, $root. '/_nino/Nino/Modules/Legal/install', [ 'de_DE', 'en_US' ], $routes, $blacklist, $config, true );
}

ninoWarnings();
$floorHolds = ( \Nino\Features::get( $appData, 'sign' )['problems'] ?? null ) === [];

if( $floorHolds === true )
	check( 'the registry lists it, inactive, with nothing in the way', ( \Nino\Features::get( $appData, 'sign' )['active'] ?? null ) === false );
else {
	$problems = (array) ( \Nino\Features::get( $appData, 'sign' )['problems'] ?? [] );
	echo "  note - this Nino (". \Nino\VERSION. ") is below the floor of the feature (". $manifest['nino']. "): the registry lists it with the reason, and the activation below is done by hand\n";
	check( 'a kernel below the floor lists the feature with one problem, the floor, and refuses to activate it', count( $problems ) === 1 && str_contains( $problems[0], $manifest['nino'] ) === true
		&& is_string( \Nino\Features::activate( $appData, 'sign' ) ) === true && in_array( '\\Nino\\Modules\\Sign', (array) ( \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] ?? [] ), true ) === false );
}

check( 'activation succeeds', signActivate( $appData ) === true );
$modules = \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'];
check( 'the class is listed in /nino/modules after the two it requires, and the version is recorded', array_search( '\\Nino\\Modules\\Pdf', $modules, true ) < array_search( '\\Nino\\Modules\\Sign', $modules, true )
	&& array_search( '\\Nino\\Modules\\Members', $modules, true ) < array_search( '\\Nino\\Modules\\Sign', $modules, true ) && \Nino\Features::get( $appData, 'sign' )['installed'] === '1.0.0' );
$unitWords = [ 'en_US' => (array) include $dir. '/install/text/en_US.php', 'de_DE' => (array) include $dir. '/install/text/de_DE.php' ];
check( 'the words of the unit are the same keys in both languages, none of them empty, and the states a page can say - one for each - are among them',
	array_keys( $unitWords['en_US'] ) === array_keys( $unitWords['de_DE'] ) && array_filter( array_merge( $unitWords['en_US'], $unitWords['de_DE'] ), static fn( mixed $word ): bool => is_string( $word ) === false || trim( $word ) === '' ) === []
	&& array_filter( [ 'signed', 'declined', 'cancelled', 'expired', 'invalid', 'consent', 'empty', 'limit', 'changed', 'write' ], static fn( string $state ): bool => isset( $GLOBALS['unitWords']['de_DE']['[[/feature/sign/state/'. $state. ']]'], $GLOBALS['unitWords']['en_US']['[[/feature/sign/state/'. $state. ']]'] ) === false ) === [] );
$stateWord = static fn( string $locale, string $state ): string => (string) $GLOBALS['unitWords'][$locale]['[[/feature/sign/state/'. $state. ']]'];
check( 'the states are told apart in both languages: again - the session ran out under the form - is not expired - the link ran out -, all eleven say their own thing, a signed document does not promise a signed copy by mail - it is in the member\'s area, and a link was mailed - and a declined request does not promise that the owner is told',
	array_filter( [ 'en_US', 'de_DE' ], static fn( string $locale ): bool => $GLOBALS['stateWord']( $locale, 'again' ) === $GLOBALS['stateWord']( $locale, 'expired' ) ) === []
	&& count( array_unique( array_map( static fn( string $state ): string => $GLOBALS['stateWord']( 'en_US', $state ), [ 'signed', 'declined', 'cancelled', 'expired', 'again', 'changed', 'invalid', 'consent', 'empty', 'limit', 'write' ] ) ) ) === 11
	&& count( array_unique( array_map( static fn( string $state ): string => $GLOBALS['stateWord']( 'de_DE', $state ), [ 'signed', 'declined', 'cancelled', 'expired', 'again', 'changed', 'invalid', 'consent', 'empty', 'limit', 'write' ] ) ) ) === 11
	&& preg_match( '/\bcopy\b/i', $stateWord( 'en_US', 'signed' ) ) !== 1 && preg_match( '/Kopie/', $stateWord( 'de_DE', 'signed' ) ) !== 1 && str_contains( $stateWord( 'en_US', 'signed' ), 'area' ) === true && str_contains( $stateWord( 'de_DE', 'signed' ), 'Bereich' ) === true
	&& preg_match( '/\btold\b|inform/i', $stateWord( 'en_US', 'declined' ) ) !== 1 && preg_match( '/informiert/', $stateWord( 'de_DE', 'declined' ) ) !== 1 );
check( 'the unit copied the page and merged its words into both languages', is_file( \Nino\Filesystem::path( $appData, '/templates/page-sign.tpl' ) ) === true
	&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $GLOBALS['appData'], '/text/'. $locale. '.php', [] )['[[/feature/sign/form/consent]]'] ?? '' ) ) === '' ) === [] );

if( class_exists( '\\Nino\\Modules\\Legal' ) === false )
	echo "  note - this Nino has no \\Nino\\Modules\\Legal: the section of the privacy policy was not looked for\n";
else {
	$privacy = \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] );
	check( 'the section of the privacy policy is there in both languages, at its position', isset( $privacy['de_DE']['sign']['title'], $privacy['en_US']['sign']['text'] ) === true && ( $privacy['*']['sign']['order'] ?? null ) === 870
		&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( $privacy[$locale]['sign']['title'] ?? '' ) ) === '' || trim( strip_tags( (string) ( $privacy[$locale]['sign']['text'] ?? '' ) ) ) === '' ) === [] );

	// What the section tells is what the feature does: the record of a signing, how long it stays, the mails, the days of the link,
	// the cookie of the form and the count of the posts - in both languages
	$privacyWords = [ 'de_DE' => [ 'IP-Adresse', 'Browser', 'Hashwert', 'gelöscht', 'E-Mail', '14 Tage', 'Sitzungs-Cookie', 'eine Stunde', 'einfache elektronische Signatur', 'Hashwert des gezeigten Dokuments', 'Name und die E-Mail-Adresse des Kontos', 'Hashwert des unterschriebenen Dokuments wird nur in der Verwaltung' ],
		'en_US' => [ 'IP address', 'browser', 'hash', 'deletes', 'email', '14 days', 'session cookie', 'one hour', 'simple electronic signature', 'hash of the document as it was shown', 'name and the email address of the account', 'hash of the signed document is kept in the administration' ] ];
	check( '...and it says what the feature keeps and sets: the address and the browser with a signature, the hashes, that it stays until the operator deletes it, the mails, the days of the link, the cookie of the form, the hour of the count, what kind of signature this is - and what the record page carries: the hash of the document shown, the name and the address of the account, while the hash of the signed one stays in the panel',
		array_filter( $privacyWords, static fn( array $words, string $locale ): bool => array_filter( $words, static fn( string $word ): bool => str_contains( (string) ( $GLOBALS['privacy'][$locale]['sign']['text'] ?? '' ), $word ) === false ) !== [], ARRAY_FILTER_USE_BOTH ) === [] );

	$privacy['en_US']['sign']['text'] = '<p>The editor changed this.</p>';
	\Nino\Filesystem::putFileContent( $appData, '/elements/privacy.php', $privacy );
	unset( $appData['./nino/features/all'] );
	signActivate( $appData );
	check( 'a second activation changes nothing of it - a section the editor changed stays', \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] ) === $privacy );
}

\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

check( 'init registers the three routes with their handlers, and the merge of a restore - and no shortcode of its own beside the components of the manifest',
	( $appData['/nino/http/routes']['GET://.sign']['uri'] ?? null ) === '/.sign' && ( $appData['/nino/http/routes']['POST://.sign']['uri'] ?? null ) === '/.sign' && ( $appData['/nino/http/routes']['GET://.sign/pdf']['uri'] ?? null ) === '/.sign/pdf'
	&& isset( $appData['./nino/callbacks']['/nino/http/response/GET://.sign'], $appData['./nino/callbacks']['/nino/http/response/POST://.sign'], $appData['./nino/callbacks']['/nino/http/response/GET://.sign/pdf'], $appData['./nino/callbacks']['/nino/admin/restore'] ) === true
	&& count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/sign-form' ) ) === 1 && count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/sign-list' ) ) === 1 );
check( '...and the routes keep the csrf guard: none of them says csrf false', array_filter( [ 'GET://.sign', 'POST://.sign', 'GET://.sign/pdf' ], static fn( string $route ): bool => ( $GLOBALS['appData']['/nino/http/routes'][$route]['csrf'] ?? true ) === false ) === [] );

echo "\n";

// --- What the tests draw on ---------------------------------------------------------------------------------

// The owner: a full-access account at the workbench and one that may do nothing there
ninoUser( $appData, 'admin@example.com' );
ninoUser( $appData, 'plain@example.com', [], 'plain password' );
signVisitor();

// The members: two who are active, one who is not
$ada = \Nino\Modules\Members::add( $appData, 'ada@example.org', 'Ada Lovelace', 'staff' );
$bob = \Nino\Modules\Members::add( $appData, 'bob@example.org', '', 'guests' );
$cyd = \Nino\Modules\Members::add( $appData, 'cyd@example.org', 'Cyd', 'staff' );
\Nino\Modules\Members::change( $appData, $cyd['id'], [ 'active' => false ] );

// The documents: the layout of the PDF feature with the owner's own signature, and the templates saved through its Store -
// one with a text, a free text, a field for the owner and a field for the member, one that has no field for a member
$ownerPng = signPng( 80, 30 );
$layout = \Nino\Modules\Pdf\Store::saveLayout( $appData, [ 'format' => 'a4', 'margins' => [ 20, 20, 20, 20 ], 'font' => 'helvetica', 'size' => 10, 'header' => '', 'footer' => '<p align="center">Page [[page]] / [[pages]]</p>' ] );
$picture = \Nino\Modules\Pdf\Store::saveImage( $appData, 'signature', $ownerPng );
$field = static fn( string $name, string $party, int $x ): array => [ 'type' => 'signature', 'name' => $name, 'party' => $party, 'label' => $party === 'owner' ? 'The owner' : 'The member', 'page' => 'last', 'x' => $x, 'y' => 230, 'w' => 60, 'h' => 20, 'line' => true ];
$saved = [
	\Nino\Modules\Pdf\Store::saveTemplate( $appData, [ 'key' => 'contract-one', 'isNew' => true, 'name' => 'Service contract', 'tag' => 'contract', 'blocks' => [
		[ 'type' => 'html', 'html' => '<h1>Service contract</h1><p>Between the club and [[client]].</p>' ],
		[ 'type' => 'input', 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ],
		$field( 'seller', 'owner', 20 ), $field( 'buyer', 'member', 110 ),
	] ] ),
	\Nino\Modules\Pdf\Store::saveTemplate( $appData, [ 'key' => 'two-fields', 'isNew' => true, 'name' => 'Two fields', 'tag' => 'privacy', 'blocks' => [
		[ 'type' => 'html', 'html' => '<p>Two places to sign.</p>' ], $field( 'first', 'member', 20 ), $field( 'second', 'member', 110 ),
	] ] ),
	\Nino\Modules\Pdf\Store::saveTemplate( $appData, [ 'key' => 'owner-only', 'isNew' => true, 'name' => 'Owner only', 'tag' => 'contract', 'blocks' => [
		[ 'type' => 'html', 'html' => '<p>Only the owner signs.</p>' ], $field( 'seller', 'owner', 20 ),
	] ] ),
	\Nino\Modules\Pdf\Store::saveTemplate( $appData, [ 'key' => 'other-tag', 'isNew' => true, 'name' => 'Other tag', 'tag' => 'offer', 'blocks' => [
		[ 'type' => 'html', 'html' => '<p>An offer.</p>' ], $field( 'buyer', 'member', 20 ),
	] ] ),
];
check( 'the layout, the owner\'s signature and four templates are saved through the Store of the PDF feature', ( $layout['ok'] ?? false ) === true && ( $picture['ok'] ?? false ) === true
	&& array_filter( $saved, static fn( array $answer ): bool => ( $answer['ok'] ?? false ) !== true ) === [] && array_keys( \Nino\Modules\Pdf::templates( $appData ) ) === [ 'other-tag', 'owner-only', 'contract-one', 'two-fields' ] );
$input = [ 'tokens' => [ 'client' => 'Ada Lovelace' ], 'inputs' => [ 'note' => "first line\nsecond line" ] ];


// --- The data layer and request() -----------------------------------------------------------------------------

echo "Modules\\Sign - request(), and what it leaves\n";

signFresh( $appData );
$before = count( $mails );
$refusals = [
	'a template nobody has' => \Nino\Modules\Sign::request( $appData, 'nowhere', $input, $ada['id'] ),
	'something that is no template key' => \Nino\Modules\Sign::request( $appData, '../contract-one', $input, $ada['id'] ),
	'a template with no field for a member' => \Nino\Modules\Sign::request( $appData, 'owner-only', $input, $ada['id'] ),
	'an account nobody has' => \Nino\Modules\Sign::request( $appData, 'contract-one', $input, 'ffffffffffff' ),
	'something that is no account' => \Nino\Modules\Sign::request( $appData, 'contract-one', $input, '../ada' ),
	'an account that is switched off' => \Nino\Modules\Sign::request( $appData, 'contract-one', $input, $cyd['id'] ),
	'no free text where one is asked for' => \Nino\Modules\Sign::request( $appData, 'contract-one', [ 'tokens' => [ 'client' => 'x' ] ], $ada['id'] ),
	'a free text of blanks' => \Nino\Modules\Sign::request( $appData, 'contract-one', [ 'inputs' => [ 'note' => " \n\t " ] ], $ada['id'] ),
];
check( 'a request is refused by name: template, member (the template has no field for one), account (nobody, none, or switched off) and input (a free text it asks for is not given)',
	$refusals === [ 'a template nobody has' => 'template', 'something that is no template key' => 'template', 'a template with no field for a member' => 'member', 'an account nobody has' => 'account',
		'something that is no account' => 'account', 'an account that is switched off' => 'account', 'no free text where one is asked for' => 'input', 'a free text of blanks' => 'input' ] );
check( '...and a refusal leaves nothing behind: no index, no directory, no mail, no warning', signIndex( $appData ) === [] && glob( \Nino\Filesystem::path( $appData, '/data/sign' ). '/*' ) === [] && count( $mails ) === $before && ninoWarnings() === [] );

$today = gmdate( 'Y-m-d' );
$made = \Nino\Modules\Sign::request( $appData, 'contract-one', $input, $ada['id'], [ 'title' => "  Service & \x07contract\n2026 " ] );
$id = (string) ( $made['id'] ?? '' );
$stored = signIndex( $appData )[$id] ?? [];
$pdfPath = (string) \Nino\Filesystem::path( $appData, '/data/sign/'. $id. '/shown.pdf' );
$pdf = is_file( $pdfPath ) === true ? (string) file_get_contents( $pdfPath ) : '';
$mail = end( $mails ) ?: [];
preg_match( '~href="https://example\.org/club/\.sign\?t=([A-Za-z0-9_-]{43})"~', (string) ( $mail['body'] ?? '' ), $link );
$token = $link[1] ?? '';

check( 'a request is made: the answer is the request as get() has it - an id of 12 hex characters, the title cleaned, status requested, the account - and says the mail was delivered', is_array( $made ) && preg_match( '/^[a-f0-9]{12}$/D', $id ) === 1
	&& $made['title'] === 'Service & contract 2026' && $made['status'] === 'requested' && $made['account'] === $ada['id'] && $made['template'] === 'contract-one' && $made['mailed'] === true
	&& array_diff_key( $made, \Nino\Modules\Sign::get( $appData, $id ) ) === [ 'mailed' => true ] );
check( '...the index holds it in the shape of the plan: the template, the data as given, the title, the account, the status, the hash of the token, the time it is good until, the times, the hash of the PDF, the record of a signing that is not there yet, and the page it came from',
	array_keys( $stored ) === [ 'template', 'data', 'title', 'account', 'status', 'token', 'expires', 'created', 'viewed', 'signed', 'declined', 'changed', 'hashShown', 'hashSigned', 'method', 'record', 'page' ]
	&& $stored['data'] === $input && $stored['viewed'] === null && $stored['signed'] === null && $stored['declined'] === null && $stored['changed'] === null && $stored['page'] === '' && $stored['hashSigned'] === '' && $stored['method'] === ''
	&& $stored['record'] === [ 'ip' => '', 'agent' => '', 'consent' => '' ] && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $stored['created'] ) === 1 && abs( $stored['expires'] - ( time() + 14 * 86400 ) ) < 5 );
check( '...the PDF is kept as shown.pdf and nothing else of the request yet: it is a PDF, its sha256 is the hashShown the index holds, and signed.pdf and the picture of the signature are not there',
	str_starts_with( $pdf, '%PDF-1.4' ) === true && $stored['hashShown'] === hash( 'sha256', $pdf ) && is_file( dirname( $pdfPath ). '/signed.pdf' ) === false && is_file( dirname( $pdfPath ). '/signature.png' ) === false
	&& \Nino\Modules\Sign::path( $appData, $id, 'shown' ) === $pdfPath && \Nino\Modules\Sign::path( $appData, $id, 'signed' ) === null );
check( '...the document is the template as the PDF feature makes it, with the day and the title: the text, the free text, the day of the request and the title in the file',
	signPages( $pdf ) === 1 && str_contains( $pdf, '/CreationDate (D:'. str_replace( '-', '', $today ). '000000Z)' ) === true && str_contains( $pdf, '/Title (Service & contract 2026)' ) === true );

if( shell_exec( 'command -v pdftotext 2>/dev/null' ) !== null && trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) !== '' )
	check( '...and pdftotext reads the text of it', preg_match( '/Between the club and Ada Lovelace/', signText( $pdf ) ) === 1 && preg_match( '/first line\s+second line/', signText( $pdf ) ) === 1 );
else
	echo "  note - pdftotext is not on the path: the pin on the words of the document was NOT run\n";

check( '...the owner\'s own signature is drawn on its field (a picture and its mask on the page) and the member\'s field shows its label alone: one picture in the file', substr_count( $pdf, '/Subtype /Image' ) === 2 );
check( 'the member is mailed once, to the address of the account, in the site\'s native language, with the link - the token is 43 characters of base64url - the title escaped and the days the link is valid for',
	count( $mails ) === $before + 1 && $mail['to'] === 'ada@example.org' && $mail['subject'] === 'Ein Dokument wartet auf Deine Unterschrift' && $token !== '' && str_contains( $mail['body'], 'Service &amp; contract 2026' ) === true
	&& str_contains( $mail['body'], 'Hallo Ada Lovelace,' ) === true && str_contains( $mail['body'], 'gültig für 14 Tage.' ) === true && $mail['replyTo'] === 'owner@example.org' );
check( '...the token is kept as its sha256 and as nothing else: not in the index, not on the disk', $stored['token'] === hash( 'sha256', $token ) && preg_match( '/^[a-f0-9]{64}$/D', $stored['token'] ) === 1
	&& str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign/requests.php' ) ), $token ) === false
	&& str_contains( (string) shell_exec( 'grep -rl '. escapeshellarg( $token ). ' '. escapeshellarg( \Nino\Filesystem::path( $appData, '/data' ) ). ' 2>/dev/null' ), $token ) === false );
check( '...and the API never gives the hash of the token out: get(), requests() and the answer of request() have none', array_key_exists( 'token', \Nino\Modules\Sign::get( $appData, $id ) ) === false
	&& array_key_exists( 'token', \Nino\Modules\Sign::requests( $appData )[0] ) === false && array_key_exists( 'token', $made ) === false );
echo "\n";

// The options: how long the link is good for, the page it returns to, the title
signFresh( $appData );
$withDays = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'days' => 3 ] );
$tooFew = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'days' => 0 ] );
$tooMany = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'days' => 200 ] );
$expires = static fn( array $request ): int => signIndex( $GLOBALS['appData'] )[ $request['id'] ]['expires'];
check( 'days sets how long the link is good for and is held to 1 to 90; the setting days (14) is the default, and a request with no title has the name of the template for one',
	abs( $expires( $withDays ) - ( time() + 3 * 86400 ) ) < 5 && abs( $expires( $tooFew ) - ( time() + 86400 ) ) < 5 && abs( $expires( $tooMany ) - ( time() + 90 * 86400 ) ) < 5 && $withDays['title'] === 'Two fields' );
\Nino\Features::saveSettings( $appData, 'sign', [ 'days' => '5' ] );
$bySetting = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
check( '...and the setting is what a request without days takes', abs( $expires( $bySetting ) - ( time() + 5 * 86400 ) ) < 5 );
\Nino\Features::saveSettings( $appData, 'sign', [ 'days' => '14' ] );

$linkOf = static function( array $mail ): string {
	preg_match( '~href="(https://example\.org/club/\.sign\?[^"]+)"~', (string) $mail['body'], $found );
	return html_entity_decode( $found[1] ?? '' );
};
$returns = [];
foreach( [ '/club/documents?x=1#top' => '/club/documents', '/' => '/', '//evil.example' => '', '/.sign' => '', '/.members/login' => '', 'club' => '', 'https://evil.example/' => '', "/a b" => '', '/\\evil' => '', '/javascript:alert(1)' => '', '' => '' ] as $page => $expected ) {
	signFresh( $appData );
	$request = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'page' => (string) $page ] );
	$returns[$page] = preg_match( '~&return=([^&]*)$~', $linkOf( end( $mails ) ), $found ) === 1 ? rawurldecode( $found[1] ) : '';
	\Nino\Modules\Sign::remove( $appData, $request['id'] );
}
check( 'page names the path the link takes the member back to, held to the rules of a return path of the Members feature: a path of the site without its query, no endpoint of a module, no address and no path with a space or a scheme - any other is left out of the link',
	$returns === [ '/club/documents?x=1#top' => '/club/documents', '/' => '/', '//evil.example' => '', '/.sign' => '', '/.members/login' => '', 'club' => '', 'https://evil.example/' => '', "/a b" => '', '/\\evil' => '', '/javascript:alert(1)' => '', '' => '' ] );
signFresh( $appData );
$paged = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'page' => '/club/documents?x=1' ] );
$plain = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
$pagedMails = count( $mails );
signFresh( $appData );
$remindedPaged = \Nino\Modules\Sign::remind( $appData, $paged['id'] );
$remindedPlain = \Nino\Modules\Sign::remind( $appData, $plain['id'] );
check( 'the page is kept on the request - the path without its query, \'\' for none - and a reminder takes the member to it again: the new link returns to the page request() named, and none where there was none',
	signIndex( $appData )[ $paged['id'] ]['page'] === '/club/documents' && signIndex( $appData )[ $plain['id'] ]['page'] === '' && $paged['page'] === '/club/documents' && $remindedPaged === true && $remindedPlain === true
	&& str_ends_with( $linkOf( $mails[$pagedMails] ), '&return=%2Fclub%2Fdocuments' ) === true && str_contains( $linkOf( $mails[$pagedMails + 1] ), 'return' ) === false );
\Nino\Modules\Sign::remove( $appData, $paged['id'] );
\Nino\Modules\Sign::remove( $appData, $plain['id'] );

$long = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'title' => str_repeat( 'ä', 150 ) ] );
check( 'a title is cut at 120 characters, counted as characters', mb_strlen( $long['title'] ) === 120 );
\Nino\Modules\Sign::remove( $appData, $long['id'] );
$messy = \Nino\Modules\Sign::request( $appData, 'contract-one', [ 'tokens' => [ 'client' => [ 'name' => 'Ada', 'object' => new stdClass() ], 'a' => [ 'b' => [ 'c' => [ 'd' => 'kept', 'e' => [ 'too deep' ] ] ] ], 'n' => 5, 'f' => 1.5, 'b' => true ],
	'slots' => [ 'a' => '<p>x</p>', 'b' => [ 1 ], 'c' => 5 ], 'inputs' => [ 'note' => 'n', 'other' => [ 'x' ] ], 'extra' => 'dropped' ], $ada['id'] );
check( 'what a caller hands to the PDF feature is kept as it is given, cleaned: strings and numbers in the tokens, down to four keys, and strings alone in the slots and the inputs - no object, no flag, nothing the PDF feature does not read',
	is_array( $messy ) && signIndex( $appData )[ $messy['id'] ]['data'] === [ 'tokens' => [ 'client' => [ 'name' => 'Ada' ], 'a' => [ 'b' => [ 'c' => [ 'd' => 'kept' ] ] ], 'n' => 5, 'f' => 1.5 ], 'slots' => [ 'a' => '<p>x</p>' ], 'inputs' => [ 'note' => 'n' ] ] );
\Nino\Modules\Sign::remove( $appData, $messy['id'] );

// The mail that is not delivered leaves the request, and a warning that names the request and no address
signFresh( $appData );
$delivery = false;
ninoWarnings();
$unsent = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
$warned = ninoWarnings();
$delivery = true;
check( 'a mail that is not delivered leaves the request there, says so in "mailed", and warns - naming the request by its id and no address', is_array( $unsent ) && $unsent['mailed'] === false && isset( signIndex( $appData )[ $unsent['id'] ] ) === true
	&& count( $warned ) === 1 && str_contains( $warned[0], $unsent['id'] ) === true && str_contains( $warned[0], 'bob@example.org' ) === false && str_contains( $warned[0], 'example.org' ) === false );
\Nino\Modules\Sign::remove( $appData, $unsent['id'] );

\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
$mailsBefore = count( $mails );
$noAddress = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
$warned = ninoWarnings();
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
check( 'a site with no address of its own has no link to mail: the request is there, no mail is sent, and the warning says why', is_array( $noAddress ) && $noAddress['mailed'] === false && count( $mails ) === $mailsBefore
	&& count( $warned ) === 1 && str_contains( $warned[0], 'has no address' ) === true );
\Nino\Modules\Sign::remove( $appData, $noAddress['id'] );

// A write that cannot be made is the request that is not made: 'write', no directory left behind, no mail
signFresh( $appData );
$indexPath = \Nino\Filesystem::path( $appData, '/data/sign/requests.php' );
$indexBytes = (string) file_get_contents( $indexPath );
unlink( $indexPath );
mkdir( $indexPath );
$mailsBefore = count( $mails );
$notWritten = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
$directories = array_values( array_diff( array_map( 'basename', glob( \Nino\Filesystem::path( $appData, '/data/sign' ). '/*', GLOB_ONLYDIR ) ?: [] ), [ 'requests.php' ] ) );
rmdir( $indexPath );
file_put_contents( $indexPath, $indexBytes );
ninoWarnings();
check( 'a request that cannot be written is "write": the index as it was, no directory of the request left, no mail', $notWritten === 'write' && file_get_contents( $indexPath ) === $indexBytes && count( $mails ) === $mailsBefore
	&& $directories === array_values( array_filter( $directories, static fn( string $name ): bool => isset( signIndex( $GLOBALS['appData'] )[$name] ) ) ) );

// get(), requests(), path(), and the reading of a file that anybody can edit
$second = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'title' => 'Second' ] );
$all = \Nino\Modules\Sign::requests( $appData );
check( 'requests() lists the requests, the newest first, and those of one account when asked; get() answers one by its id, null for an id nobody has or that is none',
	$all[0]['id'] === $second['id'] && count( $all ) === count( signIndex( $appData ) ) && count( array_filter( $all, static fn( array $request ): bool => $request['account'] === $GLOBALS['ada']['id'] ) ) === 1
	&& array_column( \Nino\Modules\Sign::requests( $appData, $ada['id'] ), 'id' ) === [ $id ] && \Nino\Modules\Sign::requests( $appData, 'ffffffffffff' ) === []
	&& \Nino\Modules\Sign::get( $appData, $id )['id'] === $id && \Nino\Modules\Sign::get( $appData, 'ffffffffffff' ) === null && \Nino\Modules\Sign::get( $appData, '../'. $id ) === null );
check( '...path() answers the absolute path of a PDF that is there - shown or signed, nothing else - and null for the rest: signed before the signing, a kind that is none, an id nobody has',
	\Nino\Modules\Sign::path( $appData, $id, 'shown' ) === $pdfPath && \Nino\Modules\Sign::path( $appData, $id, 'signed' ) === null && \Nino\Modules\Sign::path( $appData, $id, 'signature' ) === null
	&& \Nino\Modules\Sign::path( $appData, 'ffffffffffff', 'shown' ) === null && \Nino\Modules\Sign::path( $appData, '../x', '../x' ) === null );

// A link in the directory of a request is no file of it: it could point anywhere
$linked = dirname( $pdfPath ). '/signed.pdf';
$linkMade = symlink( $pdfPath, $linked );
check( '...a file that is a link is no file of the request: signed.pdf as a link to shown.pdf is not given out', $linkMade === true && is_file( $linked ) === true && \Nino\Modules\Sign::path( $appData, $id, 'signed' ) === null );
@unlink( $linked );

$edited = signIndex( $appData );
$edited['junk'] = [ 'template' => 'x' ];
$edited[$second['id']]['status'] = 'nonsense';

// Entries that are good in all but one thing: a key that is no id, an account, a status, a token, a time, a hash, a template
$flawed = [ 'not-an-id' => [], 'aaaaaaaaaaa1' => [ 'account' => 'not-an-id' ], 'aaaaaaaaaaa2' => [ 'status' => 'nonsense' ], 'aaaaaaaaaaa3' => [ 'token' => 'plain' ],
	'aaaaaaaaaaa4' => [ 'expires' => 'soon' ], 'aaaaaaaaaaa5' => [ 'hashShown' => 'x' ], 'aaaaaaaaaaa6' => [ 'template' => 'Not a template' ] ];

foreach( $flawed as $key => $flaw )
	$edited[$key] = $flaw + $stored;

\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $edited );
check( 'the index is plain php anybody can edit: an entry that is not what it has to be - a key that is no id, an account, a status, a token, a time, a hash or a template that is none - is left out of what the API reads, each on its own',
	array_column( \Nino\Modules\Sign::requests( $appData ), 'id' ) === array_values( array_diff( array_column( $all, 'id' ), [ $second['id'] ] ) )
	&& array_intersect( array_column( \Nino\Modules\Sign::requests( $appData ), 'id' ), array_keys( $flawed ) ) === [] );
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', array_diff_key( $edited, $flawed + [ 'junk' => 1 ] ) + [ $second['id'] => signIndex( $appData )[$second['id']] ] );
$restored = signIndex( $appData );
$restored[$second['id']]['status'] = 'requested';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $restored );

check( 'a request is put in the index once: the same id again is refused, and so is an entry that is not one', \Nino\Modules\Sign\Store::add( $appData, $id, $stored ) === false
	&& \Nino\Modules\Sign\Store::add( $appData, 'bbbbbbbbbbbb', [ 'template' => 'x' ] ) === false && isset( signIndex( $appData )['bbbbbbbbbbbb'] ) === false );
echo "\n";

// --- GET /.sign - the page of a request ------------------------------------------------------------------------

echo "GET /.sign - the page the link opens, and what a GET changes\n";

// Two members, each signed in once and keeping the session of the browser they signed in with, to be put back when the test speaks as them
$sessions = [];

foreach( [ 'ada' => $ada, 'bob' => $bob ] as $who => $account ) {
	signSignIn( $appData, $account );
	$sessions[$who] = $_SESSION;
}

// Each session, put back, is its member's and nobody else's
$whose = [];

foreach( [ 'ada' => $ada, 'bob' => $bob ] as $who => $account ) {
	signAs( $who );
	$whose[$who] = ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

signVisitor();
check( 'the two members are signed in, each in a session of their own: put back, each is its member and nobody else, and a visitor is nobody', \Nino\Modules\Members::current( $appData ) === null && count( $sessions ) === 2 && $sessions['ada'] !== $sessions['bob'] && $whose === [ 'ada' => true, 'bob' => true ] );

// The page template of the project and the frames it stands in: stand-ins of the base unit's, which a project has from its wizard
\Nino\Filesystem::putFileContent( $appData, '/templates/html-header.tpl', '<main>' );
\Nino\Filesystem::putFileContent( $appData, '/templates/html-footer.tpl', '</main>' );

$viewed = static fn( string $of ): ?string => signIndex( $GLOBALS['appData'] )[$of]['viewed'] ?? null;
check( 'a request nobody has opened has no time of viewing, and waits', $viewed( $id ) === null && signIndex( $appData )[$id]['status'] === 'requested' );

$head = signServe( $appData, '/.sign?t='. $token, 'HEAD' );
check( 'a HEAD marks nothing: the same page answers, the request is as it was', $head['/nino/http/response']['statusCode'] === 200 && $viewed( $id ) === null && signIndex( $appData )[$id]['status'] === 'requested' );

$page = signServe( $appData, '/.sign?t='. $token );
$response = $page['/nino/http/response'];
$form = \Nino\Html::renderHtml( $appData, '[sign-form]' );
check( 'the link answers the page in place: 200, the page template of the unit as the body, kept out of every cache, and no referrer - the address carries the token', $response['statusCode'] === 200 && $response['body'] === '[template /templates/page-sign]'
	&& $response['header']['Cache-Control'] === 'no-store' && $response['header']['Referrer-Policy'] === 'no-referrer' && str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/templates/page-sign.tpl' ) ), '[sign-form]' ) === true );
$localeNow = $appData['./nino/locales/current'] ?? null;
$ownWords = [];
foreach( [ 'en_US', 'de_DE' ] as $locale )
	foreach( [ 'name', 'title', 'description' ] as $detail )
		$ownWords[$locale][$detail] = trim( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/_nino/webpage/.sign/'. $detail. ']]'] ?? '' ) );
check( 'the page has details of its own: it is headed as the route /.sign - not as the start page of the members - and the unit brings the name, the title and the description of that page in both languages, which the head reads as its title',
	$response['uri'] === '/.sign' && array_filter( array_merge( $ownWords['en_US'], $ownWords['de_DE'] ), static fn( string $word ): bool => $word === '' ) === [] && $ownWords['en_US']['title'] !== $ownWords['de_DE']['title']
	&& \Nino\Html::renderHtml( $appData, '[[/_nino/webpage'. $response['uri']. '/title]]' ) === $ownWords[ $appData['./nino/locales/current'] ?? 'de_DE' ]['title'] );
check( '...it marks the request as viewed - the one write a GET makes: a time, the status viewed, and nothing else of the request changed', $viewed( $id ) !== null && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $viewed( $id ) ) === 1 && signIndex( $appData )[$id]['status'] === 'viewed'
	&& array_diff_assoc( signIndex( $appData )[$id], $stored ) === [ 'status' => 'viewed', 'viewed' => $viewed( $id ) ] );
// The time of the first visit is set back, so that a visit that wrote it again - in the same second or not - would show
\Nino\Filesystem::mutate( $appData, '/data/sign/requests.php', static function( mixed $state ) use ( $id ): array {
	$state[$id]['viewed'] = '2020-01-01T00:00:00Z';
	return $state;
}, [] );
$firstView = $viewed( $id );
signServe( $appData, '/.sign?t='. $token );
check( '...and the first time stays: a second visit writes nothing', $firstView === '2020-01-01T00:00:00Z' && $viewed( $id ) === $firstView );

$dom = static fn( string $query ): array => signFind( $GLOBALS['form'], $query );
check( 'the form is the component: the title, the document in a frame that asks for the PDF with the token, a link that opens it, the sentence with a box that has to be ticked, the pad and the field of the typed name',
	signContent( $dom( '//h2[@class="sign-form__title"]' ) ) === 'Service & contract 2026' && signAttr( $dom( '//iframe' ), 'src' ) === '/.sign/pdf?t='. $token. '&kind=shown'
	&& signAttr( $dom( '//a[@target="_blank"]' ), 'href' ) === '/.sign/pdf?t='. $token. '&kind=shown' && signHas( $dom( '//input[@type="checkbox"][@name="consent"]' ), 'required' ) === true
	&& str_contains( signContent( $dom( '//label[@for="'. signAttr( $dom( '//input[@name="consent"]' ), 'id' ). '"]' ) ), 'Ich habe das Dokument gelesen' ) === true
	&& count( $dom( '//canvas[@data-sign-pad]' ) ) === 1 && signHas( $dom( '//div[@data-sign-draw]' ), 'hidden' ) === true && count( $dom( '//button[@data-sign-clear]' ) ) === 1
	&& signAttr( $dom( '//input[@name="name"]' ), 'maxlength' ) === '60' && signHas( $dom( '//div[@data-sign-name]' ), 'hidden' ) === false );
check( '...the words that ask for the box and the signature stand in the form, which shows them only while there is something to sign - not in the page of the unit, which a request that is over shares',
	count( $dom( '//p[@class="sign-form__intro"]' ) ) === 1 && signContent( $dom( '//p[@class="sign-form__intro"]' ) ) === \Nino\Html::renderHtml( $appData, '[[/feature/sign/form/intro]]' ) && signContent( $dom( '//p[@class="sign-form__intro"]' ) ) !== ''
	&& str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/templates/page-sign.tpl' ) ), 'intro/text' ) === false && str_contains( (string) file_get_contents( $dir. '/install/templates/page-sign.tpl' ), '[[/feature/sign/form/intro]]' ) === false );
check( '...it posts to /.sign with the token, the return page, the csrf token of the session and a picture field the script fills; the buttons are sign and decline, and decline needs no box ticked',
	signAttr( $dom( '//form' ), 'method' ) === 'post' && signAttr( $dom( '//form' ), 'action' ) === '/.sign' && signAttr( $dom( '//input[@name="t"]' ), 'value' ) === $token
	&& signAttr( $dom( '//input[@name="return"]' ), 'value' ) === '' && signAttr( $dom( '//input[@name="_csrf"]' ), 'value' ) === \Nino\Csrf::getToken( $appData )
	&& signAttr( $dom( '//input[@name="image"]' ), 'type' ) === 'hidden' && signHas( $dom( '//button[@name="action"][@value="sign"]' ), 'formnovalidate' ) === false
	&& signHas( $dom( '//button[@name="action"][@value="decline"]' ), 'formnovalidate' ) === true && count( $dom( '//input[@name="r"]' ) ) === 0 && str_contains( signAttr( $dom( '//form' ), 'class' ), 'nino-form' ) === false );
check( '...the form works without the script: the typed name is a field of its own with a label, and the markup holds no inline handler and no script', count( $dom( '//*[@onclick or @onchange or @onsubmit]' ) ) === 0 && count( $dom( '//script' ) ) === 0
	&& count( $dom( '//label[@for="'. signAttr( $dom( '//input[@name="name"]' ), 'id' ). '"]' ) ) === 1 );
$whole = \Nino\Html::renderHtml( $appData, '[template /templates/page-sign]' );
check( '...and the page of the unit has it between the frames, with the title and the words of the page', str_contains( $whole, '<main>' ) === true && str_ends_with( trim( $whole ), '</main>' ) === true && str_contains( $whole, 'Deine Unterschrift' ) === true && str_contains( $whole, '<form' ) === true );

// A page is the form with the return page the link names, and none that is not one
$returned = signServe( $appData, '/.sign?t='. $token. '&return=%2Fclub%2Fdocuments%3Fx%3D1' );
$formReturned = \Nino\Html::renderHtml( $appData, '[sign-form]' );
$returnedBad = signServe( $appData, '/.sign?t='. $token. '&return=%2F%2Fevil.example' );
check( 'a return page the link names is in the form, as a path of the site without its query; one that is none is left out', signAttr( signFind( $formReturned, '//input[@name="return"]' ), 'value' ) === '/club/documents'
	&& signAttr( signFind( \Nino\Html::renderHtml( $appData, '[sign-form]' ), '//input[@name="return"]' ), 'value' ) === '' && $returnedBad['/nino/http/response']['statusCode'] === 200 );

// The member the request is of, signed in, opens it by its id
signAs( 'ada' );
$byId = signServe( $appData, '/.sign?r='. $id );
$formById = \Nino\Html::renderHtml( $appData, '[sign-form]' );
check( 'a member who is signed in opens their own request by its id: the same page, the key in the form and in the frame is r', $byId['/nino/http/response']['statusCode'] === 200 && signAttr( signFind( $formById, '//input[@name="r"]' ), 'value' ) === $id
	&& count( signFind( $formById, '//input[@name="t"]' ) ) === 0 && signAttr( signFind( $formById, '//iframe' ), 'src' ) === '/.sign/pdf?r='. $id. '&kind=shown' );

// Nothing says whether an id or a token exists: a stranger, another member, a visitor and a token nobody has are the same page
$strangers = [];
$strangerAnswer = static function( string $uri ) use ( &$appData ): array {
	$request = signServe( $appData, $uri );
	return [ $request['/nino/http/response']['statusCode'], $request['/nino/http/response']['header']['Cache-Control'], \Nino\Html::renderHtml( $appData, '[sign-form]' ) ];
};
signAs( 'bob' );
$strangers['another member by the id'] = $strangerAnswer( '/.sign?r='. $id );
signVisitor();
$strangers['a visitor by the id'] = $strangerAnswer( '/.sign?r='. $id );
$strangers['an id nobody has'] = $strangerAnswer( '/.sign?r=ffffffffffff' );
$strangers['something that is no id'] = $strangerAnswer( '/.sign?r=..%2F'. $id );
$strangers['a token nobody has'] = $strangerAnswer( '/.sign?t='. str_repeat( 'A', 43 ) );
$strangers['something that is no token'] = $strangerAnswer( '/.sign?t=x' );
$strangers['no key at all'] = $strangerAnswer( '/.sign' );
$strangers['keys that are lists'] = $strangerAnswer( '/.sign?t[]=x&r[]=y' );
$same = array_filter( $strangers, static fn( array $answer ): bool => $answer === $GLOBALS['strangers']['an id nobody has'] );
check( 'another member, a visitor, an id nobody has, something that is no id, a token nobody has, no token and no key, and keys that are lists are all the same page - invalid, a 404, no form, no frame, no title',
	count( $same ) === count( $strangers ) && $strangers['an id nobody has'][0] === 404 && $strangers['an id nobody has'][1] === 'no-store' && str_contains( $strangers['an id nobody has'][2], 'sign-form--closed' ) === true
	&& str_contains( $strangers['an id nobody has'][2], 'data-sign-state="invalid"' ) === true && str_contains( $strangers['an id nobody has'][2], '<form' ) === false && str_contains( $strangers['an id nobody has'][2], '<iframe' ) === false
	&& str_contains( $strangers['an id nobody has'][2], 'Service' ) === false && str_contains( $strangers['an id nobody has'][2], 'Dieser Link führt zu keinem Dokument' ) === true );
check( '...and none of them marked a request as viewed or wrote anything', $viewed( $id ) === $firstView && count( signIndex( $appData ) ) === count( array_filter( signIndex( $appData ) ) ) );

// The states a request is in: the page says it, there is no form, and the request is not touched
$base = signIndex( $appData );
$states = [];
foreach( [ 'expired' => static fn( array $e ): array => [ 'expires' => time() - 10 ] + $e, 'cancelled' => static fn( array $e ): array => [ 'status' => 'cancelled' ] + $e, 'declined' => static fn( array $e ): array => [ 'status' => 'declined', 'declined' => '2026-01-01T00:00:00Z' ] + $e ] as $name => $change ) {
	$changed = $base;
	$changed[$id] = $change( $base[$id] );
	\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
	$request = signServe( $appData, '/.sign?t='. $token );
	$states[$name] = [ $request['/nino/http/response']['statusCode'], \Nino\Html::renderHtml( $appData, '[sign-form]' ) ];
}
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $base );
check( 'a request that ran out, was cancelled or declined answers the page with that state and no form (200) - the title is shown, the frame and the buttons are not', array_keys( $states ) === [ 'expired', 'cancelled', 'declined' ]
	&& array_filter( $states, static fn( array $each, string $name ): bool => $each[0] !== 200 || str_contains( $each[1], 'data-sign-state="'. $name. '"' ) === false || str_contains( $each[1], '<form' ) === true || str_contains( $each[1], '<iframe' ) === true
		|| str_contains( $each[1], 'Service &amp; contract 2026' ) === false || str_contains( $each[1], 'sign-form__link' ) === true, ARRAY_FILTER_USE_BOTH ) === [] && signIndex( $appData ) === $base );

// A member who is gone, or switched off: the live request is not a page any more
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => false ] );
$inactive = signServe( $appData, '/.sign?t='. $token );
$inactiveForm = \Nino\Html::renderHtml( $appData, '[sign-form]' );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => true ] );
check( 'a request whose account is switched off is no page to sign on: invalid, a 404, no form - and it is a page again when the account is on', $inactive['/nino/http/response']['statusCode'] === 404 && str_contains( $inactiveForm, '<form' ) === false
	&& signServe( $appData, '/.sign?t='. $token )['/nino/http/response']['statusCode'] === 200 );

// The component shows nothing where no route filled it
unset( $appData['./sign/request'] );
check( '[sign-form] on any other page shows nothing', \Nino\Html::renderHtml( $appData, '[sign-form]' ) === '' );
echo "\n";

// --- GET /.sign/pdf - the PDFs the kernel streams -----------------------------------------------------------------

echo "GET /.sign/pdf - the file the kernel streams, and who gets it\n";

$byToken = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown' )['/nino/http/response'];
check( 'the holder of the token is answered with the PDF named for the kernel: its absolute path, which this feature resolved, the type, shown in the browser under the cleaned title, and kept out of every cache but the browser\'s own',
	$byToken['statusCode'] === 200 && $byToken['file'] === $pdfPath && $byToken['header']['Content-Type'] === 'application/pdf' && $byToken['header']['Cache-Control'] === 'private, no-store' && $byToken['header']['X-Content-Type-Options'] === 'nosniff'
	&& $byToken['header']['Referrer-Policy'] === 'no-referrer' && $byToken['header']['Content-Disposition'] === 'inline; filename="Service _ contract 2026.pdf"; filename*=UTF-8\'\'Service%20%26%20contract%202026.pdf' );
check( '...the bytes are the kernel\'s to send: the handler reads none and sets no length, no range and no body', array_key_exists( 'Content-Length', $byToken['header'] ) === false && array_key_exists( 'Accept-Ranges', $byToken['header'] ) === false && ( $byToken['body'] ?? '' ) === '' );

$headPdf = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown', 'HEAD' );
$finalize = new ReflectionMethod( '\Nino\Http', '_finalizeResponse' );
$whole = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown' );
$finalize->invokeArgs( null, [ &$whole ] );
$ranged = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown', 'GET', [], true, [ 'HTTP_RANGE' => 'bytes=0-99' ] );
$finalize->invokeArgs( null, [ &$ranged ] );
check( 'the kernel answers the file named: the whole of it with its length and Accept-Ranges, a 206 for one range, and the headers of the handler stand; a HEAD is the headers of the GET',
	$whole['/nino/http/response']['statusCode'] === 200 && $whole['/nino/http/response']['header']['Content-Length'] === (string) strlen( $pdf ) && $whole['/nino/http/response']['header']['Accept-Ranges'] === 'bytes' && $whole['/nino/http/response']['header']['Content-Type'] === 'application/pdf'
	&& $whole['./nino/http/stream'] === [ 'path' => $pdfPath, 'start' => 0, 'length' => strlen( $pdf ) ] && $ranged['/nino/http/response']['statusCode'] === 206 && $ranged['/nino/http/response']['header']['Content-Range'] === 'bytes 0-99/'. strlen( $pdf )
	&& $headPdf['/nino/http/response']['file'] === $pdfPath );

$notFound = [];
$deny = static function( string $uri ) use ( &$appData ): array {
	$answer = signServe( $appData, $uri )['/nino/http/response'];
	return [ $answer['statusCode'], $answer['body'], $answer['header']['Content-Type'], $answer['header']['Cache-Control'], array_key_exists( 'file', $answer ), array_key_exists( 'Content-Disposition', $answer['header'] ) ];
};
$notFound['signed before the signing, by the token'] = $deny( '/.sign/pdf?t='. $token. '&kind=signed' );
$notFound['a kind that is none'] = $deny( '/.sign/pdf?t='. $token. '&kind=signature' );
$notFound['no kind'] = $deny( '/.sign/pdf?t='. $token );
$notFound['a token nobody has'] = $deny( '/.sign/pdf?t='. str_repeat( 'B', 43 ). '&kind=shown' );
$notFound['something that is no token'] = $deny( '/.sign/pdf?t=..%2F..%2Fetc&kind=shown' );
$notFound['an id, as a visitor'] = $deny( '/.sign/pdf?r='. $id. '&kind=shown' );
$notFound['no key'] = $deny( '/.sign/pdf?kind=shown' );
$notFound['keys that are lists'] = $deny( '/.sign/pdf?t[]=x&kind[]=shown' );
signAs( 'bob' );
$notFound['an id, as another member'] = $deny( '/.sign/pdf?r='. $id. '&kind=shown' );
signWorkbench( 'plain' );
$notFound['an id, as an account at the workbench without the permission'] = $deny( '/.sign/pdf?r='. $id. '&kind=shown' );
signVisitor();
$visitorAnswer = $notFound['no key'];
check( 'a visitor, a token nobody has, another member, an account at the workbench without the permission, a kind that is none and signed.pdf before the signing are all the same 404 with a plain body - nothing tells that an id exists',
	$visitorAnswer[0] === 404 && $visitorAnswer[1] === 'Not Found' && str_starts_with( $visitorAnswer[2], 'text/plain' ) && $visitorAnswer[3] === 'no-store' && $visitorAnswer[4] === false && $visitorAnswer[5] === false
	&& count( array_filter( $notFound, static fn( array $answer ): bool => $answer === $GLOBALS['visitorAnswer'] ) ) === count( $notFound ) );

signAs( 'ada' );
$byMember = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=shown' )['/nino/http/response'];
signVisitor();
check( 'the member the request is of, signed in, is answered with the PDF by its id', $byMember['statusCode'] === 200 && $byMember['file'] === $pdfPath );

// The owner: the permission of the panel opens any file there is, in any state
$owner = [];
foreach( [ 'admin' => 'admin', 'plain' => 'plain' ] as $name => $who ) {
	signWorkbench( $who );
	$owner[$name] = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=shown' )['/nino/http/response']['statusCode'];
}
$changed = $base;
$changed[$id]['status'] = 'cancelled';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
signWorkbench( 'admin' );
$ownerCancelled = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=shown' )['/nino/http/response']['statusCode'];
$ownerNoSigned = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=signed' )['/nino/http/response']['statusCode'];
signVisitor();
$tokenCancelled = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown' )['/nino/http/response']['statusCode'];
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $base );
check( 'a workbench session that holds the permission of the panel is answered with the file - in any state, a cancelled request included, and the holder of the token is not any more; an account without the permission is not; a file that is not there is a 404 for the owner too',
	$owner === [ 'admin' => 200, 'plain' => 404 ] && $ownerCancelled === 200 && $tokenCancelled === 404 && $ownerNoSigned === 404 );
$byPermission = [];

foreach( [ 'sign-manage@example.com' => [ '/_admin/sign/manage' ], 'sign-all@example.com' => [ '/_admin/sign/*' ], 'admin-all@example.com' => [ '/_admin/*' ], 'hello-only@example.com' => [ '/_admin/hello/manage' ] ] as $mail => $held ) {
	\Nino\Auth::insertUser( $appData, $mail, 'a long password', $held );
	signVisitor();
	\Nino\Auth::loginUser( $appData, $mail, 'a long password' );
	$byPermission[$mail] = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=shown' )['/nino/http/response']['statusCode'];
}

signVisitor();
check( 'the permission is the panel\'s own - held exactly, by the parent \'/_admin/sign/*\' or from above by \'/_admin/*\' - and the permission of another panel is none',
	$byPermission === [ 'sign-manage@example.com' => 200, 'sign-all@example.com' => 200, 'admin-all@example.com' => 200, 'hello-only@example.com' => 404 ] );

// The states in which the holder of the token is not answered: the request is over
$over = [];
foreach( [ 'expired' => static fn( array $e ): array => [ 'expires' => time() - 10 ] + $e, 'cancelled' => static fn( array $e ): array => [ 'status' => 'cancelled' ] + $e, 'declined' => static fn( array $e ): array => [ 'status' => 'declined' ] + $e ] as $name => $change ) {
	$changed = $base;
	$changed[$id] = $change( $base[$id] );
	\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
	$over[$name] = signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown' )['/nino/http/response']['statusCode'];
}
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $base );
check( 'a request that ran out, was cancelled or declined gives the holder of its token nothing: the same 404', $over === [ 'expired' => 404, 'cancelled' => 404, 'declined' => 404 ] );

// A file that is in the index and not on the disk is the 404 of the others
$gonePath = \Nino\Modules\Sign::path( $appData, $second['id'], 'shown' );
$secondToken = '';
unlink( (string) $gonePath );
signWorkbench( 'admin' );
$goneForOwner = signServe( $appData, '/.sign/pdf?r='. $second['id']. '&kind=shown' )['/nino/http/response'];
signVisitor();
check( 'a file whose bytes are gone is the 404 of the others, not an error - for the owner too', $goneForOwner['statusCode'] === 404 && $goneForOwner['body'] === 'Not Found' && array_key_exists( 'file', $goneForOwner ) === false );
echo "\n";

// --- POST /.sign - signing and declining --------------------------------------------------------------------------

echo "POST /.sign - the buttons of the form: sign, decline, and every way they are refused\n";

$agent = "Mozilla/5.0 (Test)\x07 Browser/1.0";
$post = static function( array $fields, bool $csrf = true, array $server = [] ) use ( &$appData ): array {
	return signServe( $appData, '/.sign', 'POST', $fields + ( isset( $fields['r'] ) === true ? [] : [ 't' => $GLOBALS['token'] ] ), $csrf, $server + [ 'HTTP_USER_AGENT' => $GLOBALS['agent'] ] );
};
$answer = static function( array $request ) use ( &$appData ): array {
	$response = $request['/nino/http/response'];
	return [ $response['statusCode'], (string) ( $response['header']['Location'] ?? '' ), \Nino\Html::renderHtml( $appData, '[sign-form]' ) ];
};
$flash = static function( string $html ): string {
	$found = signFind( $html, '//p[@class="sign-form__state"]' );
	return $found === [] ? '' : $found[0]->getAttribute( 'data-sign-state' );
};
$drawn = signUrl( signPng() );
$counter = static fn(): array => \Nino\Filesystem::getFileContent( $GLOBALS['appData'], '/data/sign-tries.php', [] );
$files = static fn( string $of ): array => array_map( 'basename', glob( \Nino\Filesystem::path( $GLOBALS['appData'], '/data/sign/'. $of ). '/*' ) ?: [] );
$untouched = static fn(): bool => signIndex( $GLOBALS['appData'] ) === $GLOBALS['base'] && $GLOBALS['files']( $GLOBALS['id'] ) === [ 'shown.pdf' ];
$mailsBefore = count( $mails );
$told = [];
signFresh( $appData );

[ $status, , $html ] = $answer( $post( [ 'action' => 'sign', 'consent' => '1', 'image' => $drawn ], false ) );
check( 'a post without the csrf token is the page again with the state again (403 - the session ran out, which is no link that ran out) and a form that carries the token of the session as it is now; nothing is used up, nothing is counted',
	$status === 403 && $flash( $html ) === 'again' && signAttr( signFind( $html, '//input[@name="t"]' ), 'value' ) === $token && signAttr( signFind( $html, '//input[@name="_csrf"]' ), 'value' ) === \Nino\Csrf::getToken( $appData )
	&& $untouched() && $counter() === [] && count( $mails ) === $mailsBefore && $told === [] );

[ $status, $location, $html ] = $answer( $post( [ 'action' => 'sign', 'image' => $drawn ] ) );
check( 'the box is not ticked: the page again with the state consent (400), and the request is as it was - the signature drawn is not kept', $status === 400 && $location === '' && $flash( $html ) === 'consent' && $untouched() );
check( '...the state is said where a reader of the screen hears it: a paragraph with the role status, and the word of the state in it', signAttr( signFind( $html, '//p[@class="sign-form__state"]' ), 'role' ) === 'status' && signContent( signFind( $html, '//p[@class="sign-form__state"]' ) ) !== '' );

$nothing = [
	'no picture and no name' => [],
	'a name of blanks' => [ 'name' => " \t\n " ],
	'a name of control characters' => [ 'name' => "\x01\x02\x03" ],
	'a name of 61 characters' => [ 'name' => str_repeat( 'x', 61 ) ],
	'a picture that is no data url' => [ 'image' => 'not a picture' ],
	'a data url that is no picture' => [ 'image' => signUrl( 'this is no picture' ) ],
	'a jpeg' => [ 'image' => 'data:image/jpeg;base64,'. base64_encode( (string) ( function() { $image = imagecreatetruecolor( 20, 20 ); ob_start(); imagejpeg( $image ); return ob_get_clean(); } )() ) ],
	'a gif with the type of a png' => [ 'image' => signUrl( (string) ( function() { $image = imagecreatetruecolor( 20, 20 ); ob_start(); imagegif( $image ); return ob_get_clean(); } )() ) ],
	'a canvas nobody drew on' => [ 'image' => signUrl( signPng( 600, 200, false ) ) ],
	'a picture wider than 2000 pixels' => [ 'image' => signUrl( signPng( 2001, 100 ) ) ],
	'a picture higher than 1000 pixels' => [ 'image' => signUrl( signPng( 100, 1001 ) ) ],
	'a picture and a name beside it that is good, when the picture is none' => [ 'image' => 'data:image/png;base64,!!!!', 'name' => 'Ada Lovelace' ],
];
$empty = [];
foreach( $nothing as $label => $fields ) {
	[ $status, $location, $html ] = $answer( $post( [ 'action' => 'sign', 'consent' => '1' ] + $fields ) );
	$empty[$label] = [ $status, $location, $flash( $html ) ];
}
check( 'no signature is the state empty (400): no picture and no name, a name of blanks or control characters or of more than 60 characters, a picture that is none, a jpeg, a gif, a canvas nobody drew on, one wider than 2000 pixels or higher than 1000 - and a bad picture is not saved by the name beside it',
	array_filter( $empty, static fn( array $each ): bool => $each !== [ 400, '', 'empty' ] ) === [] && count( $empty ) === count( $nothing ) && $untouched() );

[ $status, , $html ] = $answer( $post( [ 'action' => 'launch', 'consent' => '1', 'image' => $drawn ] ) );
[ $statusNone, , ] = $answer( $post( [ 'consent' => '1', 'image' => $drawn ] ) );
check( 'an action that is neither sign nor decline, and none, is the page again with no word (400)', $status === 400 && $flash( $html ) === '' && signFind( $html, '//form' ) !== [] && $statusNone === 400 && $untouched() );
check( '...and every post that got as far as the counter was counted for the visitor, whatever came of it', count( $counter() ) === 1 && array_values( $counter() )[0]['tries'] === count( $nothing ) + 3 );
echo "\n";

// --- Signing: a drawn picture ----------------------------------------------------------------------------------------

$before = signIndex( $appData )[$id];
$shownHash = hash_file( 'sha256', $pdfPath );
$signedDay = [ gmdate( 'Y-m-d' ) ];
$told = [];
signFresh( $appData );
$mailsBefore = count( $mails );
$posted = $post( [ 'action' => 'sign', 'consent' => '1', 'image' => $drawn, 'return' => '/club/documents' ] );
$signedDay[] = gmdate( 'Y-m-d' );
[ $status, $location, ] = $answer( $posted );
$after = signIndex( $appData )[$id];
$signedPath = \Nino\Modules\Sign::path( $appData, $id, 'signed' );
$signed = (string) file_get_contents( (string) $signedPath );
$sentence = (string) \Nino\Html::resolveTextfill( $appData, '/feature/sign/form/consent' );

check( 'signing answers a redirect (303) to the return page with the id and the state in the query - and nothing of the token in it', $status === 303 && $location === '/club/documents?sign='. $id. '&sign-state=signed' && str_contains( $location, $token ) === false );
check( '...the request is signed in one write: status signed, the time, the hash of the signed PDF, how it was signed - and nothing else of it changed',
	$after['status'] === 'signed' && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $after['signed'] ) === 1 && in_array( substr( (string) $after['signed'], 0, 10 ), $signedDay, true ) === true
	&& $after['hashSigned'] === hash( 'sha256', $signed ) && $after['method'] === 'drawn' && array_diff_key( $after, array_flip( [ 'status', 'signed', 'hashSigned', 'method', 'record' ] ) ) === array_diff_key( $before, array_flip( [ 'status', 'signed', 'hashSigned', 'method', 'record' ] ) ) );
check( '...with the record of the signing: the address, the browser as it was sent but for its control characters, and the sentence the member agreed to - as the page showed it, in the language of the signing',
	$after['record'] === [ 'ip' => '127.0.0.1', 'agent' => 'Mozilla/5.0 (Test) Browser/1.0', 'consent' => $sentence ] && str_contains( $sentence, 'Ich habe das Dokument gelesen' ) === true );
check( '...both PDFs and the picture are kept, each once: shown.pdf as it was, the picture as it was posted, signed.pdf - and path() gives out the two PDFs and not the picture, which is there now', $files( $id ) === [ 'shown.pdf', 'signature.png', 'signed.pdf' ] && hash_file( 'sha256', $pdfPath ) === $shownHash
	&& file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign/'. $id. '/signature.png' ) ) === signPng() && $after['hashShown'] === $shownHash
	&& \Nino\Modules\Sign::path( $appData, $id, 'signed' ) === dirname( $pdfPath ). '/signed.pdf' && \Nino\Modules\Sign::path( $appData, $id, 'shown' ) === $pdfPath && \Nino\Modules\Sign::path( $appData, $id, 'signature' ) === null );
check( '...the signed document is the document again with one page more - the record -, the owner\'s signature and the member\'s on the page of their fields, and the day of the signing and the title in the file',
	signPages( $signed ) === signPages( $pdf ) + 1 && signPages( $signed ) === 2 && substr_count( $signed, '/Subtype /Image' ) === 4
	&& str_contains( $signed, '/CreationDate (D:'. str_replace( '-', '', substr( (string) $after['signed'], 0, 10 ) ). '000000Z)' ) === true && str_contains( $signed, '/Title (Service & contract 2026)' ) === true );

$pageOf = static function( string $pdf, int $number ): string {

	$object = static fn( int $at ): string => preg_match( '/\n'. $at. " 0 obj\n(.*?)\nendobj\n/s", $pdf, $found ) === 1 ? $found[1] : '';

	preg_match_all( '/\n(\d+) 0 obj\n<< \/Type \/Page \/Parent[^\n]*\n/', $pdf, $pages );
	preg_match( '#/Contents (\d+) 0 R#', $object( (int) $pages[1][$number] ), $contents );

	$stream = $object( (int) $contents[1] );
	$start = (int) strpos( $stream, "stream\n" );

	return (string) gzuncompress( substr( $stream, $start + 7, (int) strrpos( $stream, "\nendstream" ) - $start - 7 ) );
};
check( '...the first page draws two pictures - the owner\'s and the member\'s - each in the box of its field, and the record page none', substr_count( $pageOf( $signed, 0 ), ' Do Q' ) === 2 && substr_count( $pageOf( $signed, 1 ), ' Do Q' ) === 0 );
check( 'the callback /sign/signed is told once, with the request as get() has it - the id, the status, the record - and no hash of the token', count( $told ) === 1 && $told[0][0] === '/sign/signed' && $told[0][1] === \Nino\Modules\Sign::get( $appData, $id )
	&& $told[0][1]['status'] === 'signed' && array_key_exists( 'token', $told[0][1] ) === false );
$sentMails = array_slice( $mails, $mailsBefore );
check( 'two mails go out for the signing, in one call: the member gets the link to the signed document on the site - by the id - in the language of the page, the owner the title, who signed and a link to the panel in the native one',
	count( $sentMails ) === 2 && $sentMails[0]['to'] === 'ada@example.org' && $sentMails[0]['subject'] === 'Dein unterschriebenes Dokument' && str_contains( $sentMails[0]['body'], 'href="https://example.org/club/.sign?r='. $id. '"' ) === true
	&& str_contains( $sentMails[0]['body'], 'Service &amp; contract 2026' ) === true && $sentMails[1]['to'] === 'owner@example.org' && $sentMails[1]['subject'] === 'Ein Mitglied hat ein Dokument unterschrieben'
	&& str_contains( $sentMails[1]['body'], '<strong>Ada Lovelace</strong> hat unterschrieben' ) === true && str_contains( $sentMails[1]['body'], 'href="https://example.org/club/_admin#sign"' ) === true
	&& str_contains( $sentMails[1]['body'], $token ) === false && str_contains( $sentMails[0]['body'], $token ) === false && str_contains( $sentMails[1]['body'], '127.0.0.1' ) === false );

if( trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) !== '' ) {
	$record = preg_replace( '/\s+/', ' ', signText( $signed, '-f 2 -l 2' ) );
	$first = preg_replace( '/\s+/', ' ', signText( $signed, '-f 1 -l 1' ) );
	check( 'the record page says what the plan asks of it: who (the name and the address of the account), when, from where, which document by its hash, how, the sentence agreed to - and the first page is the document',
		str_contains( $record, 'Beleg der Unterzeichnung' ) === true && str_contains( $record, 'Eine einfache elektronische Signatur' ) === true && str_contains( $record, 'Service & contract 2026' ) === true && str_contains( $record, $id ) === true && str_contains( $record, 'Ada Lovelace (ada@example.org)' ) === true
		&& preg_match( '/Zeit \(UTC\) (\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2} UTC/', $record, $time ) === 1 && in_array( $time[1], $signedDay, true ) === true && str_contains( $record, 'Adresse 127.0.0.1' ) === true
		&& str_contains( $record, 'Browser Mozilla/5.0 (Test) Browser/1.0' ) === true && str_contains( $record, substr( $shownHash, 0, 32 ) ) === true && str_contains( $record, substr( $shownHash, 32 ) ) === true
		&& str_contains( $record, 'Auf der Seite gezeichnet' ) === true && str_contains( $record, $sentence ) === true && str_contains( $first, 'Between the club and Ada Lovelace' ) === true && str_contains( $first, 'Beleg' ) === false );
	check( '...and the footer of the layout counts the record page: page 1 of 2 and page 2 of 2', str_contains( $first, 'Page 1 / 2' ) === true && str_contains( $record, 'Page 2 / 2' ) === true );
}
else
	echo "  note - pdftotext is not on the path: the pins on the record page were NOT run\n";

// A second signing of a signed request: the page with its state, nothing rewritten
$signedMtime = filemtime( (string) $signedPath );
$indexNow = signIndex( $appData );
$filesNow = [ hash_file( 'sha256', (string) $signedPath ), hash_file( 'sha256', $pdfPath ), hash_file( 'sha256', \Nino\Filesystem::path( $appData, '/data/sign/'. $id. '/signature.png' ) ) ];
$toldBefore = count( $told );
$mailsBefore = count( $mails );
[ $status, $location, $html ] = $answer( $post( [ 'action' => 'sign', 'consent' => '1', 'image' => signUrl( signPng( 300, 100 ) ) ] ) );
[ $declineStatus, , $declineHtml ] = $answer( $post( [ 'action' => 'decline' ] ) );
check( 'a second post of a signed request is the page with its state signed (200) and the link to the signed document: nothing is rewritten - the index, the three files and their hashes are as they were - and nobody is told or mailed',
	$status === 200 && $location === '' && $flash( $html ) === 'signed' && str_contains( $html, 'kind=signed' ) === true && signIndex( $appData ) === $indexNow && filemtime( (string) $signedPath ) === $signedMtime
	&& $filesNow === [ hash_file( 'sha256', (string) $signedPath ), hash_file( 'sha256', $pdfPath ), hash_file( 'sha256', \Nino\Filesystem::path( $appData, '/data/sign/'. $id. '/signature.png' ) ) ]
	&& count( $told ) === $toldBefore && count( $mails ) === $mailsBefore && $declineStatus === 200 && $flash( $declineHtml ) === 'signed' && signIndex( $appData )[$id]['status'] === 'signed' );

// What the link shows now, and who gets the signed document
$afterGet = signServe( $appData, '/.sign?t='. $token );
$afterForm = \Nino\Html::renderHtml( $appData, '[sign-form]' );
check( 'the link of the mail shows the request as signed: the state, the title and the link to the signed document - by the token - and no form', $afterGet['/nino/http/response']['statusCode'] === 200 && str_contains( $afterForm, 'data-sign-state="signed"' ) === true
	&& signAttr( signFind( $afterForm, '//p[@class="sign-form__link"]/a' ), 'href' ) === '/.sign/pdf?t='. $token. '&kind=signed' && str_contains( $afterForm, '<form' ) === false && str_contains( $afterForm, 'Service &amp; contract 2026' ) === true );
$both = [ 'the token, shown' => signServe( $appData, '/.sign/pdf?t='. $token. '&kind=shown' ), 'the token, signed' => signServe( $appData, '/.sign/pdf?t='. $token. '&kind=signed' ) ];
signAs( 'ada' );
$both['the member, signed'] = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=signed' );
$memberForm = signServe( $appData, '/.sign?r='. $id );
$memberLink = signFind( \Nino\Html::renderHtml( $appData, '[sign-form]' ), '//p[@class="sign-form__link"]/a' );
signAs( 'bob' );
$both['another member, signed'] = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=signed' );
signWorkbench( 'admin' );
$both['the owner, signed'] = signServe( $appData, '/.sign/pdf?r='. $id. '&kind=signed' );
signVisitor();
$statuses = array_map( static fn( array $each ): int => $each['/nino/http/response']['statusCode'], $both );
check( 'the signed document is the holder\'s, the member\'s and the owner\'s: signed.pdf is the file the kernel is told to stream, under the cleaned title with -signed, and shown.pdf is still there to be had; another member gets the 404',
	$statuses === [ 'the token, shown' => 200, 'the token, signed' => 200, 'the member, signed' => 200, 'another member, signed' => 404, 'the owner, signed' => 200 ] && $both['the token, signed']['/nino/http/response']['file'] === $signedPath
	&& $both['the token, shown']['/nino/http/response']['file'] === $pdfPath && $both['the token, signed']['/nino/http/response']['header']['Content-Disposition'] === 'inline; filename="Service _ contract 2026-signed.pdf"; filename*=UTF-8\'\'Service%20%26%20contract%202026-signed.pdf'
	&& $both['the owner, signed']['/nino/http/response']['file'] === $signedPath && $memberLink !== [] && $memberLink[0]->getAttribute( 'href' ) === '/.sign/pdf?r='. $id. '&kind=signed' );

// The page the redirect lands on: with no key it says the state, and gives the link to the member it is of
$landing = [];
$landed = static function( string $query ) use ( &$appData ): array {
	$request = signServe( $appData, '/.sign?'. $query );
	return [ $request['/nino/http/response']['statusCode'], \Nino\Html::renderHtml( $appData, '[sign-form]' ) ];
};
$landing['visitor'] = $landed( 'sign='. $id. '&sign-state=signed' );
signAs( 'ada' );
$landing['the member'] = $landed( 'sign='. $id. '&sign-state=signed' );
$landing['the member, an id that is none'] = $landed( 'sign=nonsense&sign-state=signed' );
signAs( 'bob' );
$landing['another member'] = $landed( 'sign='. $id. '&sign-state=signed' );
signVisitor();
$landing['declined'] = $landed( 'sign='. $id. '&sign-state=declined' );
$landing['a state that is none'] = $landed( 'sign='. $id. '&sign-state=consent' );
check( 'with no key the page says the state ?sign-state= names - signed or declined - and nothing of any request: the same for a visitor and for another member; only to the member it is of it gives the link to the signed document, and a state that is none is invalid',
	$landing['visitor'][0] === 200 && str_contains( $landing['visitor'][1], 'data-sign-state="signed"' ) === true && str_contains( $landing['visitor'][1], 'kind=signed' ) === false && $landing['visitor'] === $landing['another member']
	&& str_contains( $landing['the member'][1], '/.sign/pdf?r='. $id. '&amp;kind=signed' ) === true && str_contains( $landing['the member'][1], 'Service &amp; contract 2026' ) === true && str_contains( $landing['the member, an id that is none'][1], 'kind=signed' ) === false
	&& str_contains( $landing['declined'][1], 'data-sign-state="declined"' ) === true && $landing['a state that is none'][0] === 404 );
echo "\n";

// --- Signing: a typed name, and the picture made of it --------------------------------------------------------------

$ask = static function( string $template, array $data, array $account, array $options = [] ) use ( &$appData, &$mails ): array {

	signFresh( $appData );
	$made = \Nino\Modules\Sign::request( $appData, $template, $data, $account['id'], $options );
	preg_match( '~\.sign\?t=([A-Za-z0-9_-]{43})~', (string) ( end( $mails )['body'] ?? '' ), $found );

	return [ $made, $found[1] ?? '' ];
};
$stateOf = static fn( string $of ): string => signIndex( $GLOBALS['appData'] )[$of]['status'] ?? '';

[ $typed, $typedToken ] = $ask( 'two-fields', [], $bob, [ 'title' => 'Privacy form' ] );
$typedId = $typed['id'];
signServe( $appData, '/.sign?t='. $typedToken );
$mailsBefore = count( $mails );
$told = [];
[ $status, $location, ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $typedToken, 'action' => 'sign', 'consent' => 'on', 'name' => "  Zoë\tMüller  ", 'return' => '//evil.example' ] ) );
$typedStored = signIndex( $appData )[$typedId];
$typedSigned = (string) file_get_contents( (string) \Nino\Modules\Sign::path( $appData, $typedId, 'signed' ) );
$typedPng = (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign/'. $typedId. '/signature.png' ) );
$typedSize = \Nino\Images::size( $typedPng );
check( 'a typed name signs when nothing is drawn: the redirect goes to the signing page when the return page is none, the status is signed and the method typed, and the box may be ticked as a browser ticks it ("on")',
	$status === 303 && $location === '/.sign?sign='. $typedId. '&sign-state=signed' && $typedStored['status'] === 'signed' && $typedStored['method'] === 'typed' && $typedStored['hashSigned'] === hash( 'sha256', $typedSigned ) && count( $told ) === 1 );
check( '...the picture is the name drawn with the built-in font of GD, three times as large, on a transparent ground: a PNG as wide as ten letters of nine pixels three times over and as high as one of fifteen, and it is the same picture every time',
	$typedSize !== false && $typedSize['type'] === IMAGETYPE_PNG && $typedSize['width'] === 270 && $typedSize['height'] === 45 && $typedPng === \Nino\Modules\Sign\Signature::typed( 'Zoë Müller' ) && $typedPng !== \Nino\Modules\Sign\Signature::typed( 'Zoe Muller' ) );
$typedImage = imagecreatefromstring( $typedPng );
$inked = 0;
for( $y = 0; $y < 45; $y++ )
	for( $x = 0; $x < 270; $x++ )
		if( ( ( imagecolorat( $typedImage, $x, $y ) >> 24 ) & 0x7F ) < 127 )
			$inked++;
check( '...most of it is ground - the corners are fully transparent - and some of it is ink', ( ( imagecolorat( $typedImage, 0, 0 ) >> 24 ) & 0x7F ) === 127 && ( ( imagecolorat( $typedImage, 269, 44 ) >> 24 ) & 0x7F ) === 127 && $inked > 100 && $inked < 270 * 45 / 2 );
check( '...it is drawn on every field of the member the template has: two fields, two pictures on the first page, and the record on the second - which names the account by its address where it has no name',
	signPages( $typedSigned ) === 2 && substr_count( $pageOf( $typedSigned, 0 ), ' Do Q' ) === 2 && count( array_slice( $mails, $mailsBefore ) ) === 2
	&& ( trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) === '' || str_contains( preg_replace( '/\s+/', ' ', signText( $typedSigned, '-f 2 -l 2' ) ), 'Unterschrieben von bob@example.org (bob@example.org)' ) === true ) );
check( 'the typed name is cleaned before it is drawn: control characters and runs of blanks are one space, the letters the font has stay (the umlauts), a letter it lacks is a question mark, and a name is 60 characters at most',
	\Nino\Modules\Sign\Signature::clean( "  Zoë\t\tMüller\x07\n  " ) === 'Zoë Müller' && \Nino\Modules\Sign\Signature::clean( "\x01\x02" ) === '' && \Nino\Modules\Sign\Signature::clean( "a\xFFb" ) === "a\u{FFFD}b"
	&& \Nino\Images::size( (string) \Nino\Modules\Sign\Signature::typed( 'Łukasz Kowalski' ) )['width'] === 15 * 27 && \Nino\Modules\Sign\Signature::typed( '' ) === null && \Nino\Modules\Sign\Signature::typed( str_repeat( 'x', 61 ) ) === null
	&& \Nino\Images::size( (string) \Nino\Modules\Sign\Signature::typed( str_repeat( 'x', 60 ) ) )['width'] === 60 * 27 );
check( 'a picture the pad drew is taken as it is, when it is a PNG of the kind the page makes - and for a tampered one the answer is null', \Nino\Modules\Sign\Signature::drawn( $drawn ) === signPng() && \Nino\Modules\Sign\Signature::drawn( substr( $drawn, 0, -12 ). '!!!' ) === null
	&& \Nino\Modules\Sign\Signature::drawn( '' ) === null && \Nino\Modules\Sign\Signature::drawn( 'data:image/png;base64,' ) === null && \Nino\Modules\Sign\Signature::drawn( ' '. $drawn ) === null
	&& \Nino\Modules\Sign\Signature::drawn( signUrl( signPng( 2000, 1000 ) ) ) !== null && \Nino\Modules\Sign\Signature::drawn( signUrl( signPng( 1, 1 ) ) ) === null );

// --- What a person typed is text ------------------------------------------------------------------------------------------------

// A name, a title and a browser that hold markup, a fill and a shortcode: the record page and the mails write them as they were
// typed, and the browser is cut to what the record keeps of it
$eve = \Nino\Modules\Members::add( $appData, 'eve@example.org', 'Eve <b>X</b> [[/project/company/general/name]] [sign-list]', 'staff' );
$eveName = (string) ( \Nino\Modules\Members::account( $appData, $eve['id'] )['name'] ?? '' );
[ $texty, $textyToken ] = $ask( 'two-fields', [], $eve, [ 'title' => 'Title <i>y</i> [[/project/company/general/name]]' ] );
$textyMails = count( $mails );
signServe( $appData, '/.sign?t='. $textyToken );
signServe( $appData, '/.sign', 'POST', [ 't' => $textyToken, 'action' => 'sign', 'consent' => '1', 'name' => 'Eve' ], true, [ 'HTTP_USER_AGENT' => str_repeat( 'A', 300 ). " <i>z</i>\x07" ] );
$textySigned = (string) file_get_contents( (string) \Nino\Modules\Sign::path( $appData, $texty['id'], 'signed' ) );
$textyRecord = signIndex( $appData )[$texty['id']]['record'] ?? [ 'agent' => '' ];
$textyMail = array_slice( $mails, $textyMails );
check( 'what a person typed is text: a name, a title and a browser with markup, a fill and a shortcode in them stand on the record page as they were typed - not run, not made bold - and in the mails; the browser is cut to 255 characters and loses its control characters',
	str_contains( $eveName, '[[/project/company/general/name]]' ) === true && mb_strlen( $textyRecord['agent'] ) === 255 && str_contains( $textyRecord['agent'], "\x07" ) === false
	&& ( trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) === '' || ( str_contains( preg_replace( '/\s+/', ' ', signText( $textySigned, '-f 2 -l 2' ) ), $eveName. ' (eve@example.org)' ) === true
		&& str_contains( preg_replace( '/\s+/', ' ', signText( $textySigned, '-f 2 -l 2' ) ), 'Title <i>y</i> [[/project/company/general/name]]' ) === true ) )
	&& count( $textyMail ) === 2 && str_contains( (string) $textyMail[0]['body'], 'Title &lt;i&gt;y&lt;/i&gt; &#91;&#91;/project/company/general/name]]' ) === true
	&& str_contains( (string) $textyMail[0]['body'], 'Hallo Eve &lt;b&gt;X&lt;/b&gt; &#91;&#91;/project/company/general/name]] &#91;sign-list],' ) === true && str_contains( (string) $textyMail[0]['body'], '<i>y</i>' ) === false );
$told = [];
echo "\n";

// --- Two languages: the site's own and the one a person reads it in -------------------------------------------------------------

// The document and the mail of a request are the site's, in its native language, whoever asks and in whichever language the
// site is read; what the member is shown and what is recorded of the signing are in the language the member reads it in
\Nino\Html::addFills( $appData, [ '[[/test/word]]' => 'Wort' ], 'de_DE' );
\Nino\Html::addFills( $appData, [ '[[/test/word]]' => 'word' ], 'en_US' );
\Nino\Modules\Pdf\Store::saveTemplate( $appData, [ 'key' => 'native-word', 'isNew' => true, 'name' => 'Native word', 'tag' => 'contract', 'blocks' => [
	[ 'type' => 'html', 'html' => '<p>Word: [[/test/word]]</p>' ], $field( 'buyer', 'member', 20 ),
] ] );

$appData['./nino/locales/current'] = 'en_US';
$mailsBefore = count( $mails );
[ $worded, $wordedToken ] = $ask( 'native-word', [], $ada, [ 'title' => 'Wording' ] );
$requestMail = $mails[$mailsBefore] ?? [];
$wordedShown = (string) file_get_contents( (string) \Nino\Modules\Sign::path( $appData, $worded['id'], 'shown' ) );
$told = [];
signServe( $appData, '/.sign?t='. $wordedToken );
$formIn = \Nino\Html::renderHtml( $appData, '[sign-form]' );
$mailsBefore = count( $mails );
[ $status, , ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $wordedToken, 'action' => 'sign', 'consent' => '1', 'name' => 'Ada' ] ) );
$wordedSigned = (string) file_get_contents( (string) \Nino\Modules\Sign::path( $appData, $worded['id'], 'signed' ) );
$wordedMails = array_slice( $mails, $mailsBefore );
$appData['./nino/locales/current'] = 'de_DE';

check( 'a request is made in the site\'s native language whatever the language it is read in: the mail to the member and the document are German while English is the language in use',
	$requestMail['subject'] === 'Ein Dokument wartet auf Deine Unterschrift' && str_contains( (string) $requestMail['body'], 'Hallo Ada Lovelace,' ) === true && str_contains( $formIn, 'I have read the document' ) === true
	&& ( trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) === '' || ( str_contains( signText( $wordedShown ), 'Word: Wort' ) === true && str_contains( signText( $wordedShown ), 'Word: word' ) === false ) ) );
check( '...and the member signs it in the language of the page without the document being "changed": the same document is made again in the native language - signed, German - while the member\'s mail is in the language of the page and the owner\'s in the native one',
	$status === 303 && signIndex( $appData )[$worded['id']]['status'] === 'signed' && count( $wordedMails ) === 2 && $wordedMails[0]['to'] === 'ada@example.org' && $wordedMails[0]['subject'] === 'Your signed document'
	&& $wordedMails[1]['to'] === 'owner@example.org' && $wordedMails[1]['subject'] === 'Ein Mitglied hat ein Dokument unterschrieben'
	&& ( trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) === '' || ( str_contains( signText( $wordedSigned, '-f 1 -l 1' ), 'Word: Wort' ) === true && str_contains( signText( $wordedSigned, '-f 1 -l 1' ), 'Word: word' ) === false ) ) );
check( '...the record of the signing is in the language the member signed in, with the sentence the page showed: English words, and the sentence of the English page',
	trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) ) === '' || ( str_contains( signText( $wordedSigned, '-f 2 -l 2' ), 'Record of the signing' ) === true && str_contains( signText( $wordedSigned, '-f 2 -l 2' ), 'Beleg der Unterzeichnung' ) === false
		&& str_contains( preg_replace( '/\s+/', ' ', signText( $wordedSigned, '-f 2 -l 2' ) ), 'I have read the document' ) === true ) );
\Nino\Modules\Pdf\Store::deleteTemplate( $appData, 'native-word' );
$told = [];
echo "\n";

// --- The document that was shown is the one that is signed ----------------------------------------------------------------

$saveTemplate = static fn( string $text ): array => \Nino\Modules\Pdf\Store::saveTemplate( $GLOBALS['appData'], [ 'key' => 'contract-one', 'isNew' => false, 'name' => 'Service contract', 'tag' => 'contract', 'blocks' => [
	[ 'type' => 'html', 'html' => $text ], [ 'type' => 'input', 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ],
	$GLOBALS['field']( 'seller', 'owner', 20 ), $GLOBALS['field']( 'buyer', 'member', 110 ) ] ] );
$plainText = '<h1>Service contract</h1><p>Between the club and [[client]].</p>';
$layoutNow = \Nino\Filesystem::getFileContent( $appData, '/data/pdf/layout.php', [] );
$spoilt = [];
$mailsBefore = count( $mails );
$told = [];

/*	One request of its own for each way a document can stop being the one that was shown: it is asked, the document is spoiled, the member signs, and the
	document is mended again. What the page says, what the index holds and what was mailed is kept for the pins	*/
$tryToSign = static function( string $why, callable $spoil, callable $mend, array $options = [] ) use ( &$spoilt, &$mails, $ask, $post, $answer, $drawn, $ada, $input, $flash ): void {

	[ $made, $changeToken ] = $ask( 'contract-one', $input, $ada, $options );
	$spoil( $made['id'] );
	signFresh( $GLOBALS['appData'] );
	$before = count( $mails );
	[ $status, , $html ] = $answer( signServe( $GLOBALS['appData'], '/.sign', 'POST', [ 't' => $changeToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn, '_csrf' => \Nino\Csrf::getToken( $GLOBALS['appData'] ) ], true, [ 'HTTP_USER_AGENT' => $GLOBALS['agent'] ] ) );
	$mend( $made['id'] );
	$entry = signIndex( $GLOBALS['appData'] )[$made['id']];
	$spoilt[$why] = [ 'id' => $made['id'], 'token' => $changeToken, 'status' => $status, 'flash' => $flash( $html ), 'stored' => $entry['status'], 'time' => $entry['changed'],
		'files' => array_map( 'basename', glob( \Nino\Filesystem::path( $GLOBALS['appData'], '/data/sign/'. $made['id'] ). '/*' ) ?: [] ), 'mails' => array_slice( $mails, $before ),
		'form' => count( signFind( $html, '//form' ) ), 'buttons' => count( signFind( $html, '//button' ) ), 'html' => $html, 'told' => $GLOBALS['told'] ];
};
$tryToSign( 'a template the owner changed', fn() => $saveTemplate( '<h1>Service contract</h1><p>Between the club and [[client]]. Changed.</p>' ), fn() => $saveTemplate( $plainText ) );
$tryToSign( 'a layout the owner changed', fn() => \Nino\Filesystem::putFileContent( $GLOBALS['appData'], '/data/pdf/layout.php', [ 'footer' => '<p>Another footer</p>' ] + $layoutNow ), fn() => \Nino\Filesystem::putFileContent( $GLOBALS['appData'], '/data/pdf/layout.php', $layoutNow ) );
$shownBytes = [];
$tryToSign( 'a shown.pdf that is not what it was', function( string $id ) use ( &$shownBytes ): void {
	$file = (string) \Nino\Modules\Sign::path( $GLOBALS['appData'], $id, 'shown' );
	$shownBytes[$id] = (string) file_get_contents( $file );
	file_put_contents( $file, $shownBytes[$id]. ' ' );
}, fn( string $id ) => file_put_contents( (string) \Nino\Modules\Sign::path( $GLOBALS['appData'], $id, 'shown' ), $shownBytes[$id] ) );
$firstChanged = $spoilt['a template the owner changed'];

check( 'a document that is not the one that was shown is not signed: the template changed, the layout changed or shown.pdf changed on the disk is the state changed (409), written with its time - nothing is signed, no file is added, nobody is told',
	array_map( static fn( array $one ): array => [ $one['status'], $one['flash'], $one['stored'], $one['files'], preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $one['time'] ) === 1, $one['told'] ], $spoilt )
		=== array_fill_keys( array_keys( $spoilt ), [ 409, 'changed', 'changed', [ 'shown.pdf' ], true, [] ] ) && count( $spoilt ) === 3 );
check( '...the page says it and has no form: no frame, no box, no pad, no button - Decline is not offered either, the document is not the one that was shown - and the title is shown',
	$firstChanged['form'] === 0 && $firstChanged['buttons'] === 0 && signFind( $firstChanged['html'], '//iframe' ) === [] && signFind( $firstChanged['html'], '//p[@class="sign-form__intro"]' ) === [] && str_contains( $firstChanged['html'], 'sign-form__title' ) === true
	&& signContent( signFind( $firstChanged['html'], '//p[@class="sign-form__state"]' ) ) === \Nino\Html::renderHtml( $appData, '[[/feature/sign/state/changed]]' ) );
check( '...the owner is mailed, once, in the native language, with the title and who - the member gets no mail - and nothing in it is the address of the client or the token',
	array_map( static fn( array $one ): int => count( $one['mails'] ), $spoilt ) === array_fill_keys( array_keys( $spoilt ), 1 ) && $firstChanged['mails'][0]['to'] === 'owner@example.org'
	&& $firstChanged['mails'][0]['subject'] === 'Ein Dokument hat sich geändert, bevor ein Mitglied es unterschreiben konnte' && str_contains( $firstChanged['mails'][0]['body'], '<strong>Ada Lovelace</strong> wollte unterschreiben' ) === true
	&& str_contains( $firstChanged['mails'][0]['body'], 'Ein Dokument hat sich geändert</h1>' ) === true && str_contains( $firstChanged['mails'][0]['body'], $firstChanged['token'] ) === false && str_contains( $firstChanged['mails'][0]['body'], '127.0.0.1' ) === false );

$mailsHeld = count( $mails );
signServe( $appData, '/.sign?t='. $firstChanged['token'] );
$again = \Nino\Html::renderHtml( $appData, '[sign-form]' );
[ $status, , $html ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $firstChanged['token'], 'action' => 'sign', 'consent' => '1', 'image' => $drawn, '_csrf' => \Nino\Csrf::getToken( $appData ) ], true, [ 'HTTP_USER_AGENT' => $agent ] ) );
check( '...a link that is opened again, or a post again, shows the same state with no form (200 and nothing written): a changed request is over, and the files, the index and the mails stay as they were',
	$flash( $again ) === 'changed' && signFind( $again, '//form' ) === [] && $status === 200 && $flash( $html ) === 'changed' && signFind( $html, '//form' ) === [] && signIndex( $appData )[$firstChanged['id']]['status'] === 'changed'
	&& array_map( 'basename', glob( \Nino\Filesystem::path( $appData, '/data/sign/'. $firstChanged['id'] ). '/*' ) ?: [] ) === [ 'shown.pdf' ] && count( $mails ) === $mailsHeld && $told === [] );

$remindMails = count( $mails );
check( '...it is not reminded, and not cancelled: false, and no mail', \Nino\Modules\Sign::remind( $appData, $firstChanged['id'] ) === false && \Nino\Modules\Sign::cancel( $appData, $firstChanged['id'] ) === false && count( $mails ) === $remindMails
	&& \Nino\Modules\Sign::get( $appData, $firstChanged['id'] )['status'] === 'changed' );

// A mail that is not delivered leaves a warning that names the request - and the request is changed all the same
$delivery = false;
ninoWarnings();
$tryToSign( 'a template changed, and no mail taken', fn() => $saveTemplate( '<h1>Service contract</h1><p>Between the club and [[client]]. Changed again.</p>' ), fn() => $saveTemplate( $plainText ) );
$delivery = true;
$warned = implode( ' ', ninoWarnings() );
check( '...a mail of the owner that is not delivered is a warning that names the request by its id and no address - and the request is changed all the same',
	$spoilt['a template changed, and no mail taken']['stored'] === 'changed' && $spoilt['a template changed, and no mail taken']['flash'] === 'changed' && str_contains( $warned, $spoilt['a template changed, and no mail taken']['id'] ) === true
	&& str_contains( $warned, 'owner@example.org' ) === false && str_contains( $warned, 'ada@example.org' ) === false );

// ...with the setting notifyOwner off nobody is mailed
\Nino\Features::saveSettings( $appData, 'sign', [ 'notifyOwner' => '0' ] );
$tryToSign( 'a template changed, the owner not told', fn() => $saveTemplate( '<h1>Service contract</h1><p>Between the club and [[client]]. Changed once more.</p>' ), fn() => $saveTemplate( $plainText ) );
\Nino\Features::saveSettings( $appData, 'sign', [ 'notifyOwner' => '1' ] );
check( '...with the setting notifyOwner off the owner is not mailed, the request is changed all the same', $spoilt['a template changed, the owner not told']['mails'] === [] && $spoilt['a template changed, the owner not told']['stored'] === 'changed' );

// An update of the PDF feature that leaves the document as it was ends nothing: the file carries no version of it
[ $updated, $updatedToken ] = $ask( 'contract-one', $input, $ada );
\Nino\Features::all( $appData );
$appData['./nino/features/all']['pdf']['version'] = '9.9.9';
signServe( $appData, '/.sign?t='. $updatedToken );
$signedAfter = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $updatedToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn, '_csrf' => \Nino\Csrf::getToken( $appData ) ], true, [ 'HTTP_USER_AGENT' => $agent ] ) );
$bumped = \Nino\Features::get( $appData, 'pdf' )['version'];
unset( $appData['./nino/features/all'] );
check( 'a pending request is signed after the PDF feature was updated - the document made again is the one that was shown, its file names no version - and is not changed', $signedAfter[0] === 303 && ( signIndex( $appData )[$updated['id']]['status'] ?? '' ) === 'signed' && $bumped === '9.9.9' );
$changingId = $firstChanged['id'];
$mailsBefore = count( $mails );
$told = [];
echo "\n";
echo "\n";

// --- Declining, running out, the cap, the mails, the writes -------------------------------------------------------------

[ $declining, $decliningToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'To decline' ] );
$decliningId = $declining['id'];
signServe( $appData, '/.sign?t='. $decliningToken );
$mailsBefore = count( $mails );
$told = [];
[ $status, $location, ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $decliningToken, 'action' => 'decline' ] ) );
$declined = signIndex( $appData )[$decliningId];
$sent = array_slice( $mails, $mailsBefore );
check( 'declining needs no box ticked and no signature: the redirect says declined, the status turns to declined with the time, nothing else of the request changes, and no file is written',
	$status === 303 && $location === '/.sign?sign='. $decliningId. '&sign-state=declined' && $declined['status'] === 'declined' && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $declined['declined'] ) === 1 && $declined['signed'] === null
	&& $declined['hashSigned'] === '' && $declined['record'] === [ 'ip' => '', 'agent' => '', 'consent' => '' ] && $files( $decliningId ) === [ 'shown.pdf' ] );
check( '...the callback /sign/declined is told with the request, and only the owner is mailed - in the native language, that the member declined; the member gets none', count( $told ) === 1 && $told[0][0] === '/sign/declined' && $told[0][1]['status'] === 'declined'
	&& count( $sent ) === 1 && $sent[0]['to'] === 'owner@example.org' && $sent[0]['subject'] === 'Ein Mitglied hat die Unterschrift eines Dokuments abgelehnt' && str_contains( $sent[0]['body'], '<strong>Ada Lovelace</strong> hat die Unterschrift abgelehnt' ) === true && str_contains( $sent[0]['body'], 'To decline' ) === true );
$afterDecline = signServe( $appData, '/.sign?t='. $decliningToken );
$stateHtml = \Nino\Html::renderHtml( $appData, '[sign-form]' );
$mailsBefore = count( $mails );
$told = [];
[ $againStatus, , $againHtml ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $decliningToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
check( 'a declined request shows its state on the page and cannot be signed after: the page again with the state declined, nothing is written, nobody is told', $afterDecline['/nino/http/response']['statusCode'] === 200 && str_contains( $stateHtml, 'data-sign-state="declined"' ) === true
	&& $againStatus === 200 && $flash( $againHtml ) === 'declined' && signIndex( $appData )[$decliningId] === $declined && $files( $decliningId ) === [ 'shown.pdf' ] && $told === [] && count( $mails ) === $mailsBefore );

// A request whose time is up: expired is computed from the time and never written
[ $running, $runningToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Running out' ] );
$runningId = $running['id'];
$changed = signIndex( $appData );
$changed[$runningId]['expires'] = time() - 5;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
$mailsBefore = count( $mails );
$told = [];
[ $status, $location, $html ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $runningToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
check( 'a request whose time is up answers a post with the page and the state expired (200), writes nothing and tells nobody; the status in the file stays what it was, and the API says expired',
	$status === 200 && $location === '' && $flash( $html ) === 'expired' && signIndex( $appData )[$runningId]['status'] === 'requested' && $files( $runningId ) === [ 'shown.pdf' ] && $told === [] && count( $mails ) === $mailsBefore
	&& \Nino\Modules\Sign::get( $appData, $runningId )['status'] === 'expired' && signServe( $appData, '/.sign?t='. $runningToken )['/nino/http/response']['statusCode'] === 200 && str_contains( \Nino\Html::renderHtml( $appData, '[sign-form]' ), 'data-sign-state="expired"' ) === true );

// The cap: posts per visitor and hour, counted before anything is looked at
\Nino\Features::saveSettings( $appData, 'sign', [ 'attempts' => '3' ] );
[ $capped, $cappedToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Capped' ] );
$cappedId = $capped['id'];
$limits = [];
for( $try = 1; $try <= 5; $try++ ) {
	[ $status, , $html ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $cappedToken, 'action' => 'sign', 'consent' => '0' ] ) );
	$limits[] = $status. ' '. $flash( $html );
}
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
[ $otherStatus, , $otherHtml ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $cappedToken, 'action' => 'sign', 'consent' => '0' ] ) );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
[ $goodStatus, $goodLocation, ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $cappedToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
check( 'the setting attempts caps the posts of a visitor in an hour: the first three are answered, the next ones are the state limit (429) - even a good one, which signs nothing - and another visitor is not held back',
	$limits === [ '400 consent', '400 consent', '400 consent', '429 limit', '429 limit' ] && $otherStatus === 400 && $flash( $otherHtml ) === 'consent' && $goodStatus === 429 && $goodLocation === '' && $stateOf( $cappedId ) !== 'signed' );
check( '...the visitor is counted by a hash and for an hour: the address is not in the file of the counter, and the window of each count closes an hour from the first try', count( $counter() ) === 2 && array_filter( $counter(), static fn( array $entry ): bool => abs( (int) $entry['reset'] - ( time() + 3600 ) ) > 5 ) === [] && str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign-tries.php' ) ), '127.0.0.1' ) === false
	&& str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign-tries.php' ) ), '10.9.9.9' ) === false );
signFresh( $appData );
[ $afterReset, , ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $cappedToken, 'action' => 'decline' ] ) );
check( '...and a new hour is a new count', $afterReset === 303 && $stateOf( $cappedId ) === 'declined' );
\Nino\Features::saveSettings( $appData, 'sign', [ 'attempts' => '20' ] );

// The mails of a signing: the owner's can be switched off and has to have an address; a mail that is not delivered does not undo the signing
$mailing = [];
foreach( [ 'the owner is not told when the setting says so' => 'off', 'no contact address' => 'nocontact', 'a mail that is not delivered' => 'undelivered' ] as $label => $how ) {

	[ $each, $eachToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Mails' ] );
	$mailsBefore = count( $mails );
	ninoWarnings();

	if( $how === 'off' )
		\Nino\Features::saveSettings( $appData, 'sign', [ 'notifyOwner' => 'false' ] );
	else if( $how === 'nocontact' )
		\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => '' ], '*' );
	else
		$delivery = false;

	[ $status, , ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $eachToken, 'action' => 'sign', 'consent' => '1', 'name' => 'Ada Lovelace' ] ) );
	$mailing[$label] = [ $status, $stateOf( $each['id'] ), array_column( array_slice( $mails, $mailsBefore ), 'to' ), ninoWarnings() ];

	\Nino\Features::saveSettings( $appData, 'sign', [ 'notifyOwner' => 'true' ] );
	\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
	$delivery = true;
	$mailing[$label][] = $each['id'];
}
check( 'with the setting notifyOwner off only the member is mailed', $mailing['the owner is not told when the setting says so'][0] === 303 && $mailing['the owner is not told when the setting says so'][1] === 'signed' && $mailing['the owner is not told when the setting says so'][2] === [ 'ada@example.org' ]
	&& $mailing['the owner is not told when the setting says so'][3] === [] );
check( '...with no contact address only the member is, and a warning says why without naming an address', $mailing['no contact address'][1] === 'signed' && $mailing['no contact address'][2] === [ 'ada@example.org' ] && count( $mailing['no contact address'][3] ) === 1
	&& str_contains( $mailing['no contact address'][3][0], 'no contact address' ) === true && str_contains( $mailing['no contact address'][3][0], '@' ) === false );
check( '...a mail that is not delivered is a warning that names the request by its id and no address - and the signing stands', $mailing['a mail that is not delivered'][1] === 'signed' && $mailing['a mail that is not delivered'][0] === 303 && count( $mailing['a mail that is not delivered'][3] ) === 1
	&& str_contains( $mailing['a mail that is not delivered'][3][0], $mailing['a mail that is not delivered'][4] ) === true && str_contains( $mailing['a mail that is not delivered'][3][0], '@' ) === false );

// A write that cannot be made at the signing is the state write (500): the request waits, and no file of the signing is left
[ $unwritable, $unwritableToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Unwritable' ] );
$unwritableId = $unwritable['id'];
$blocker = \Nino\Filesystem::path( $appData, '/data/sign/'. $unwritableId. '/signed.pdf' );
mkdir( $blocker );
$mailsBefore = count( $mails );
$told = [];
[ $status, $location, $html ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $unwritableToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
$leftOver = $files( $unwritableId );
rmdir( $blocker );
check( 'a signing that cannot be written is the state write (500): the request still waits, the picture that was written is taken away again, nobody is told or mailed', $status === 500 && $location === '' && $flash( $html ) === 'write' && $stateOf( $unwritableId ) === 'requested'
	&& $leftOver === [ 'shown.pdf', 'signed.pdf' ] && $told === [] && count( $mails ) === $mailsBefore );
[ $status, , ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $unwritableToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
check( '...and once it can be written the same request is signed', $status === 303 && $stateOf( $unwritableId ) === 'signed' && $files( $unwritableId ) === [ 'shown.pdf', 'signature.png', 'signed.pdf' ] );

// The key r: the signed-in member signs by the id, and nobody else can
[ $byKey, ] = $ask( 'two-fields', [], $ada, [ 'title' => 'By the id' ] );
signAs( 'bob' );
[ $strangerStatus, , $strangerHtml ] = $answer( signServe( $appData, '/.sign', 'POST', [ 'r' => $byKey['id'], 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
signAs( 'ada' );
[ $memberStatus, $memberLocation, ] = $answer( signServe( $appData, '/.sign', 'POST', [ 'r' => $byKey['id'], 'action' => 'sign', 'consent' => '1', 'image' => $drawn, 'return' => '/club/documents' ] ) );
signVisitor();
check( 'the member signs by the id when signed in: the redirect goes to the return page; another member and a visitor get the invalid page (404) and sign nothing', $strangerStatus === 404 && $flash( $strangerHtml ) === 'invalid' && $memberStatus === 303 && $memberLocation === '/club/documents?sign='. $byKey['id']. '&sign-state=signed'
	&& $stateOf( $byKey['id'] ) === 'signed' );

// An account that is switched off between the mail and the post
[ $gone, $goneToken ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Account off' ] );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => false ] );
[ $status, , $html ] = $answer( signServe( $appData, '/.sign', 'POST', [ 't' => $goneToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ] ) );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => true ] );
check( 'an account that is switched off signs nothing: the page again, invalid (404)', $status === 404 && $flash( $html ) === 'invalid' && $stateOf( $gone['id'] ) === 'requested' && $files( $gone['id'] ) === [ 'shown.pdf' ] );
echo "\n";

// --- remind(), cancel() and remove() -------------------------------------------------------------------------------------

echo "Modules\\Sign - remind(), cancel() and remove()\n";

$tokenOf = static function( int $at ) use ( &$mails ): string {
	preg_match( '~\.sign\?t=([A-Za-z0-9_-]{43})~', (string) ( $mails[$at]['body'] ?? '' ), $found );
	return $found[1] ?? '';
};
[ $asked, $askedToken ] = $ask( 'contract-one', $input, $ada, [ 'title' => 'To remind' ] );
$askedId = $asked['id'];
signServe( $appData, '/.sign?t='. $askedToken );
$viewedAt = signIndex( $appData )[$askedId]['viewed'];
$was = signIndex( $appData )[$askedId];
$changed = signIndex( $appData );
$changed[$askedId]['expires'] = time() + 3600;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
signFresh( $appData );
$mailsBefore = count( $mails );
$reminded = \Nino\Modules\Sign::remind( $appData, $askedId );
$newToken = $tokenOf( $mailsBefore );
$now = signIndex( $appData )[$askedId];
check( 'remind() mails the link again, in the native language, with a new token and the days of the setting from now - the status and the time of the viewing are as they were, and nothing else of the request changed',
	$reminded === true && count( $mails ) === $mailsBefore + 1 && $mails[$mailsBefore]['to'] === 'ada@example.org' && $mails[$mailsBefore]['subject'] === 'Ein Dokument wartet auf Deine Unterschrift' && preg_match( '/^[A-Za-z0-9_-]{43}$/D', $newToken ) === 1 && $newToken !== $askedToken
	&& $now['token'] === hash( 'sha256', $newToken ) && abs( $now['expires'] - ( time() + 14 * 86400 ) ) < 5 && $now['viewed'] === $viewedAt && $now['status'] === 'viewed' && array_diff_key( $now, [ 'token' => 1, 'expires' => 1 ] ) === array_diff_key( $was, [ 'token' => 1, 'expires' => 1 ] ) );
check( '...the old link is dead - the page, the PDF - and the new one opens', signServe( $appData, '/.sign?t='. $askedToken )['/nino/http/response']['statusCode'] === 404 && signServe( $appData, '/.sign/pdf?t='. $askedToken. '&kind=shown' )['/nino/http/response']['statusCode'] === 404
	&& signServe( $appData, '/.sign?t='. $newToken )['/nino/http/response']['statusCode'] === 200 && signServe( $appData, '/.sign/pdf?t='. $newToken. '&kind=shown' )['/nino/http/response']['statusCode'] === 200 );

$changed = signIndex( $appData );
$changed[$askedId]['expires'] = time() - 5;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
$expiredBefore = \Nino\Modules\Sign::get( $appData, $askedId )['status'];
signFresh( $appData );
$remindedExpired = \Nino\Modules\Sign::remind( $appData, $askedId );
check( 'a request whose time is up is reminded, and is good again', $expiredBefore === 'expired' && $remindedExpired === true && \Nino\Modules\Sign::get( $appData, $askedId )['status'] === 'viewed' );

$delivery = false;
signFresh( $appData );
ninoWarnings();
$tokenKept = signIndex( $appData )[$askedId]['token'];
$expiresKept = signIndex( $appData )[$askedId]['expires'];
$remindedUnsent = \Nino\Modules\Sign::remind( $appData, $askedId );
$warned = ninoWarnings();
$delivery = true;
check( 'a reminder whose mail is not delivered changes nothing - the link the member has still works - and warns, naming the request and no address', $remindedUnsent === false && signIndex( $appData )[$askedId]['token'] === $tokenKept && signIndex( $appData )[$askedId]['expires'] === $expiresKept
	&& count( $warned ) === 1 && str_contains( $warned[0], $askedId ) === true && str_contains( $warned[0], '@' ) === false );

$mailsBefore = count( $mails );
$refused = [
	'a request that was signed' => \Nino\Modules\Sign::remind( $appData, $id ),
	'one that was declined' => \Nino\Modules\Sign::remind( $appData, $decliningId ),
	'an id nobody has' => \Nino\Modules\Sign::remind( $appData, 'ffffffffffff' ),
	'something that is no id' => \Nino\Modules\Sign::remind( $appData, '../'. $askedId ),
];
check( 'a request that is over - signed or declined - is not reminded, nor is an id nobody has: false, and no mail', array_filter( $refused ) === [] && count( $mails ) === $mailsBefore );

$cancelled = \Nino\Modules\Sign::cancel( $appData, $askedId );
$afterCancel = signServe( $appData, '/.sign?t='. $tokenOf( $mailsBefore - 1 ) );
signFresh( $appData );
check( 'cancel() takes a request back: true, the status is cancelled and nothing else of it changed; the link shows the state, the PDF is gone for the holder, and it is not reminded or cancelled again',
	$cancelled === true && signIndex( $appData )[$askedId]['status'] === 'cancelled' && \Nino\Modules\Sign::get( $appData, $askedId )['status'] === 'cancelled' && \Nino\Modules\Sign::remind( $appData, $askedId ) === false && \Nino\Modules\Sign::cancel( $appData, $askedId ) === false );
$cancelList = [ 'a request that was signed' => \Nino\Modules\Sign::cancel( $appData, $id ), 'one that was declined' => \Nino\Modules\Sign::cancel( $appData, $decliningId ), 'an id nobody has' => \Nino\Modules\Sign::cancel( $appData, 'ffffffffffff' ) ];
check( '...a request that was signed or declined cannot be taken back, nor an id nobody has: false, and they are as they were', array_filter( $cancelList ) === [] && signIndex( $appData )[$id]['status'] === 'signed' && signIndex( $appData )[$decliningId]['status'] === 'declined' );

[ $running, ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Ran out' ] );
$changed = signIndex( $appData );
$changed[$running['id']]['expires'] = time() - 5;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $changed );
check( '...but one whose time is up can', \Nino\Modules\Sign::cancel( $appData, $running['id'] ) === true && signIndex( $appData )[ $running['id'] ]['status'] === 'cancelled' );

// remove(): the entry first, then the directory with the files - that request and no other
\Nino\Filesystem::putFileContent( $appData, '/data/sign/keep.txt', 'stays' );
$others = array_diff_key( signIndex( $appData ), [ $id => 1 ] );
$directory = \Nino\Filesystem::path( $appData, '/data/sign/'. $id );
$removed = \Nino\Modules\Sign::remove( $appData, $id );
check( 'remove() deletes a request whatever its state - a signed one with its three files -, the entry and the directory, that request and no other, and a file beside them stays',
	$removed === true && isset( signIndex( $appData )[$id] ) === false && is_dir( $directory ) === false && signIndex( $appData ) === $others && is_file( \Nino\Filesystem::path( $appData, '/data/sign/keep.txt' ) ) === true
	&& \Nino\Modules\Sign::get( $appData, $id ) === null && signServe( $appData, '/.sign?t='. $token )['/nino/http/response']['statusCode'] === 404 );
check( '...an id nobody has, or that is none, is false and removes nothing', \Nino\Modules\Sign::remove( $appData, $id ) === false && \Nino\Modules\Sign::remove( $appData, '../data' ) === false && \Nino\Modules\Sign::remove( $appData, '' ) === false
	&& signIndex( $appData ) === $others && is_dir( \Nino\Filesystem::path( $appData, '/data/sign' ) ) === true );
$removedHere = \Nino\Modules\Sign::requests( $appData );
echo "\n";

// --- The components -------------------------------------------------------------------------------------------------------------

echo "The components - what a member is shown\n";

$mine = \Nino\Modules\Sign::requests( $appData, $ada['id'] );
[ $titled, ] = $ask( 'two-fields', [], $ada, [ 'title' => '<b>Bold</b> [[/project/company/general/name]] [button] "quoted"' ] );
signAs( 'ada' );
signServe( $appData, '/.sign?r=ffffffffffff' );
$list = \Nino\Html::renderHtml( $appData, '[sign-list]' );
$rows = signFind( $list, '//li[contains(@class,"sign-list__row")]' );
$listed = array_map( static fn( DOMElement $row ): string => $row->getAttribute( 'data-sign-id' ), $rows );
$openOnes = array_values( array_filter( \Nino\Modules\Sign::requests( $appData, $ada['id'] ), static fn( array $request ): bool => $request['status'] !== 'signed' ) );
check( '[sign-list] shows the signed-in member the requests that were made of them and are not signed, the newest first - one row each, with the id and the state it is in',
	$listed === array_column( $openOnes, 'id' ) && $listed[0] === $titled['id'] && count( $rows ) >= 4 && array_filter( $rows, static fn( DOMElement $row ): bool => $row->getAttribute( 'data-sign-state' ) === 'signed' ) === []
	&& array_diff( [ 'declined', 'expired', 'cancelled', 'requested' ], array_map( static fn( DOMElement $row ): string => $row->getAttribute( 'data-sign-state' ), $rows ) ) === [] );
check( '...a row has the title, how it stands in words, the day and a link to the page of the request - and for a request that is not signed no link to the signed document',
	signContent( signFind( $list, '//li[@data-sign-id="'. $decliningId. '"]/span[@class="sign-list__title"]' ) ) === 'To decline' && signContent( signFind( $list, '//li[@data-sign-id="'. $decliningId. '"]/span[@class="sign-list__state"]' ) ) === 'Abgelehnt'
	&& signAttr( signFind( $list, '//li[@data-sign-id="'. $decliningId. '"]/a[@class="sign-list__page"]' ), 'href' ) === '/.sign?r='. $decliningId && signContent( signFind( $list, '//li[@data-sign-id="'. $decliningId. '"]/time' ) ) === substr( signIndex( $appData )[$decliningId]['created'], 0, 10 )
	&& count( signFind( $list, '//a[@class="sign-list__signed"]' ) ) === 0 );
check( '...a title is text and nothing else: markup, a fill and a shortcode in it are written out as they were typed, and are not run', signContent( signFind( $list, '//li[@data-sign-id="'. $titled['id']. '"]/span[@class="sign-list__title"]' ) ) === '<b>Bold</b> [[/project/company/general/name]] [button] "quoted"'
	&& count( signFind( $list, '//li//b' ) ) === 0 && str_contains( $list, 'The Club' ) === false );

$all = \Nino\Html::renderHtml( $appData, '[sign-list open="0" class="my-list"]' );
$signedRow = signFind( $all, '//li[@data-sign-state="signed"]' );
check( 'with open off the signed requests are listed too, each with the link to the signed document - by the id - and the class of the call is on the list', count( signFind( $all, '//div[contains(@class,"sign-list")][contains(@class,"my-list")]' ) ) === 1
	&& count( signFind( $all, '//li[contains(@class,"sign-list__row")]' ) ) === count( \Nino\Modules\Sign::requests( $appData, $ada['id'] ) ) && $signedRow !== []
	&& signFind( $all, '//li[@data-sign-id="'. $typedId. '"]' ) === [] && signAttr( signFind( $all, '//li[@data-sign-state="signed"]/a[@class="sign-list__signed"]' ), 'href' ) === '/.sign/pdf?r='. $signedRow[0]->getAttribute( 'data-sign-id' ). '&kind=signed' );
signAs( 'bob' );
$bobs = signFind( \Nino\Html::renderHtml( $appData, '[sign-list open="0"]' ), '//li[contains(@class,"sign-list__row")]' );
check( '...another member sees theirs only', array_filter( $bobs, static fn( DOMElement $row ): bool => in_array( $row->getAttribute( 'data-sign-id' ), array_column( \Nino\Modules\Sign::requests( $GLOBALS['appData'], $GLOBALS['bob']['id'] ), 'id' ), true ) === false ) === [] && count( $bobs ) === count( \Nino\Modules\Sign::requests( $appData, $bob['id'] ) ) );
signVisitor();
check( '...a visitor sees nothing', \Nino\Html::renderHtml( $appData, '[sign-list]' ) === '' && \Nino\Html::renderHtml( $appData, '[sign-list open="0"]' ) === '' );

signAs( 'ada' );
foreach( \Nino\Modules\Sign::requests( $appData, $ada['id'] ) as $each )
	\Nino\Modules\Sign::remove( $appData, $each['id'] );
signServe( $appData, '/.sign?sign=x&sign-state=signed' );
$empty = \Nino\Html::renderHtml( $appData, '[sign-list]' );
check( 'a member with no requests is told so in a line, and the list has nothing else', signContent( signFind( $empty, '//li[@class="sign-list__none"]' ) ) === 'Es gibt keine Dokumente, die Du unterschreiben müsstest.' && count( signFind( $empty, '//li[contains(@class,"sign-list__row")]' ) ) === 0 );
$landed = signServe( $appData, '/.sign?sign=x&sign-state=declined' );
$withState = \Nino\Html::renderHtml( $appData, '[sign-list]' );
$landedBad = signServe( $appData, '/.sign?sign-state=%3Cb%3E' );
$stateless = \Nino\Html::renderHtml( $appData, '[sign-list]' );
signVisitor();
check( 'the state a signing was redirected with, ?sign-state=, is said above the list to the member - signed or declined and nothing else - and not to a visitor', str_contains( $withState, 'data-sign-state="declined"' ) === true && str_contains( $stateless, 'sign-form__state' ) === false
	&& str_contains( \Nino\Html::renderHtml( $appData, '[sign-list]' ), 'sign-form__state' ) === false );
echo "\n";

// --- The panel ------------------------------------------------------------------------------------------------------------------

echo "Modules\\Sign\\Admin - asking, reminding, cancelling and reading, and what the panel says\n";

signWorkbench( 'admin' );
$call = static fn( string $action, array $data = [] ): array => ninoCall( $GLOBALS['appData'], $GLOBALS['panel'], $data, $action );
$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the panel is in the registry while the feature is active, in the rail\'s group of the features, with a permission of its own and a script and a stylesheet that are there',
	isset( $panels['sign'] ) === true && $panels['sign']['group'] === 'features' && $panels['sign']['perm'] === '/_admin/sign/manage' && \Nino\Modules\Sign\Admin::MANAGE_PERM === '/_admin/sign/manage'
	&& array_filter( \Nino\Modules\Sign\Admin::assets(), static fn( string $asset ): bool => is_file( \Nino\Filesystem::path( $GLOBALS['appData'], $asset ) ) === false ) === [] && count( \Nino\Modules\Sign\Admin::assets() ) === 2
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Sign\Admin::text(). '/en_US.php' ) ) === true && is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Sign\Admin::text(). '/de_DE.php' ) ) === true
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Sign\Admin::template(). '.tpl' ) ) === true );
check( '...the template has the one element the script mounts into, and the panel talks to the server through the panel() helper alone', preg_match( '/id="sign-app"/', (string) file_get_contents( $dir. '/templates/panel.tpl' ) ) === 1
	&& preg_match( "/mount\\s*:\\s*'sign-app'/", (string) file_get_contents( $dir. '/assets/admin.js' ) ) === 1 && str_contains( (string) file_get_contents( $dir. '/assets/admin.js' ), 'Nino.http.sendRequest' ) === false );

$denied = [];
signWorkbench( 'plain' );
foreach( array_keys( \Nino\Modules\Sign\Admin::actions() ) as $action )
	if( $call( $action )[0] !== 403 )
		$denied[] = $action;
signVisitor();
$unsigned = $call( 'sign/list' );
signWorkbench( 'admin' );
check( 'every panel action is 403 for an account without the permission'. ( $denied === [] ? '' : ' - '. implode( ', ', $denied ) ). ' and 401 with the code session for nobody', $denied === [] && count( \Nino\Modules\Sign\Admin::actions() ) === 7
	&& $unsigned[0] === 401 && ( $unsigned[1]['code'] ?? '' ) === 'session' );

// What the form offers
[ $status, $options ] = $call( 'sign/options' );
check( 'sign/options offers the active members - not the one that is switched off - and the templates that carry a tag of the setting and have a field for a member, each with the free texts it asks for; and the limits',
	$status === 200 && array_column( $options['accounts'], 'id' ) === array_column( array_filter( \Nino\Modules\Members::accounts( $appData ), static fn( array $account ): bool => $account['active'] === true ), 'id' ) && in_array( $cyd['id'], array_column( $options['accounts'], 'id' ), true ) === false
	&& array_column( $options['templates'], 'key' ) === [ 'contract-one', 'two-fields' ] && $options['templates'][0]['inputs'] === [ [ 'name' => 'note', 'label' => 'A note', 'multiline' => true, 'required' => true ] ] && $options['templates'][1]['inputs'] === []
	&& $options['templates'][0]['name'] === 'Service contract' && $options['templates'][0]['tag'] === 'contract' && $options['limits'] === [ 'title' => 120, 'input' => 5000 ] );
\Nino\Features::saveSettings( $appData, 'sign', [ 'tags' => "offer\nBad Tag\noffer" ] );
check( '...the tags are the lines of the setting that are slugs, each once: another tag offers its templates and drops the others', array_column( $call( 'sign/options' )[1]['templates'], 'key' ) === [ 'other-tag' ] );
\Nino\Features::saveSettings( $appData, 'sign', [ 'tags' => "contract\nprivacy" ] );

// Ask for a signature
signFresh( $appData );
$mailsBefore = count( $mails );
$requestsBefore = count( \Nino\Modules\Sign::requests( $appData ) );
$asking = $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'contract-one', 'inputs' => [ 'note' => 'From the panel', 'other' => 'dropped' ], 'title' => ' From the panel ' ] );
$askedHere = \Nino\Modules\Sign::get( $appData, (string) ( $asking[1]['id'] ?? '' ) );
check( 'sign/request makes the request: the answer has its id, the request is as Sign::request() makes it - the title cleaned, only the free texts the template has - and the member is mailed in the native language',
	$asking[0] === 200 && $askedHere !== null && $askedHere['title'] === 'From the panel' && $askedHere['account'] === $ada['id'] && $askedHere['data'] === [ 'inputs' => [ 'note' => 'From the panel' ] ] && count( $mails ) === $mailsBefore + 1
	&& $mails[$mailsBefore]['to'] === 'ada@example.org' && $mails[$mailsBefore]['subject'] === 'Ein Dokument wartet auf Deine Unterschrift' );
$noTitle = $call( 'sign/request', [ 'account' => $bob['id'], 'template' => 'two-fields' ] );
check( '...with no title it is the name of the template, and a template with no free text needs none', $noTitle[0] === 200 && \Nino\Modules\Sign::get( $appData, $noTitle[1]['id'] )['title'] === 'Two fields' );

$refusals = [
	'a template nobody has' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'nowhere', 'inputs' => [ 'note' => 'x' ] ] ),
	'a template with no field for a member' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'owner-only' ] ),
	'an account nobody has' => $call( 'sign/request', [ 'account' => 'ffffffffffff', 'template' => 'two-fields' ] ),
	'an account that is switched off' => $call( 'sign/request', [ 'account' => $cyd['id'], 'template' => 'two-fields' ] ),
	'an account that is a list' => $call( 'sign/request', [ 'account' => [ $ada['id'] ], 'template' => 'two-fields' ] ),
	'a free text that is missing' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'contract-one', 'inputs' => [] ] ),
	'a free text that is a list' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'contract-one', 'inputs' => [ 'note' => [ 'x' ] ] ] ),
	'a free text of 5001 characters' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'contract-one', 'inputs' => [ 'note' => str_repeat( 'x', 5001 ) ] ] ),
	'a title of 121 characters' => $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'title' => str_repeat( 'x', 121 ) ] ),
];
$told = array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ). ' '. ( $answer[1]['field'] ?? '' ), $refusals );
check( 'the refusals a person can cause carry their codes, the status and the field: a template nobody has sign_template, one with no field for a member sign_member, an account nobody has or that is off sign_account, a free text sign_input with the label and the limit, a long title sign_long',
	$told === [ 'a template nobody has' => '400 sign_template template', 'a template with no field for a member' => '400 sign_member template', 'an account nobody has' => '400 sign_account account', 'an account that is switched off' => '400 sign_account account',
		'an account that is a list' => '400 sign_account account', 'a free text that is missing' => '400 sign_input input-note', 'a free text that is a list' => '400 sign_input input-note', 'a free text of 5001 characters' => '400 sign_input input-note',
		'a title of 121 characters' => '400 sign_long title' ] && $refusals['a free text that is missing'][1]['params'] === [ 'A note', 5000 ] && $refusals['a title of 121 characters'][1]['params'] === [ 120 ] );
check( '...and a refusal makes no request and sends no mail', count( \Nino\Modules\Sign::requests( $appData ) ) === $requestsBefore + 2 && count( $mails ) === $mailsBefore + 2 );

$delivery = false;
signFresh( $appData );
$unsentAsk = $call( 'sign/request', [ 'account' => $bob['id'], 'template' => 'two-fields', 'title' => 'No mail' ] );
$delivery = true;
signFresh( $appData );
for( $each = 0; $each < 5; $each++ )
	\Nino\Feature::count( $appData, 'ratelimit', '127.0.0.1', 5, 3600 );
$limitedAsk = $call( 'sign/request', [ 'account' => $bob['id'], 'template' => 'two-fields', 'title' => 'Capped mail' ] );
signFresh( $appData );
ninoWarnings();
$byTitle = static fn( string $title ): array => array_values( array_filter( \Nino\Modules\Sign::requests( $GLOBALS['appData'] ), static fn( array $request ): bool => $request['title'] === $title ) );
check( 'a mail that could not be sent leaves the request there and is a 500 with the code sign_mail; the kernel\'s cap of mails is a 429 with sign_ratelimit - the request is there, and the row has Remind',
	$unsentAsk[0] === 500 && $unsentAsk[1]['code'] === 'sign_mail' && count( $byTitle( 'No mail' ) ) === 1 && $limitedAsk[0] === 429 && $limitedAsk[1]['code'] === 'sign_ratelimit' && count( $byTitle( 'Capped mail' ) ) === 1 );
$logged = json_encode( \Nino\Modules\Logs\Admin::recentLines( $appData, 100 ) );
check( '...and the request that was made is in the activity log all the same, by its id and with nothing else of it: for the mail that failed and for the one the cap refused', str_contains( (string) $logged, $byTitle( 'No mail' )[0]['id'] ) === true
	&& str_contains( (string) $logged, $byTitle( 'Capped mail' )[0]['id'] ) === true && str_contains( (string) $logged, 'No mail' ) === false && str_contains( (string) $logged, 'bob@example.org' ) === false );

// The list
$listing = $call( 'sign/list' );
$listed = $listing[1]['requests'];
$ofAsking = array_values( array_filter( $listed, static fn( array $request ): bool => $request['id'] === $asking[1]['id'] ) )[0];
$appData['/nino/dir'] = '/club';
$inDirectory = $call( 'sign/list' )[1]['requests'][0]['urls'];
$appData['/nino/dir'] = '';
check( 'sign/list answers every request, the newest first, with the member it is of, the name of the template, how it stands, the times, the record and the hashes - never the hash of the token - and the days of a link',
	$listing[0] === 200 && array_column( $listed, 'id' ) === array_column( \Nino\Modules\Sign::requests( $appData ), 'id' ) && $listing[1]['days'] === 14 && array_keys( $ofAsking ) === [ 'id', 'title', 'template', 'templateName', 'account', 'member', 'status', 'created', 'viewed', 'signed', 'declined', 'changed', 'expires', 'hashShown', 'hashSigned', 'method', 'record', 'files', 'urls' ]
	&& $ofAsking['member'] === [ 'name' => 'Ada Lovelace', 'email' => 'ada@example.org', 'label' => 'Ada Lovelace' ] && $ofAsking['templateName'] === 'Service contract' && $ofAsking['status'] === 'requested' && $ofAsking['files'] === [ 'shown' => true, 'signed' => false ]
	&& str_contains( json_encode( $listed ), 'token' ) === false );
check( '...with the addresses its two PDFs are opened at - the route\'s, below the directory the project is served from', $ofAsking['urls'] === [ 'shown' => '/.sign/pdf?r='. $ofAsking['id']. '&kind=shown', 'signed' => '/.sign/pdf?r='. $ofAsking['id']. '&kind=signed' ]
	&& str_starts_with( $inDirectory['shown'], '/club/.sign/pdf?r=' ) === true );

// A signed one carries its record
[ $forRecord, $forRecordToken ] = $ask( 'two-fields', [], $bob, [ 'title' => 'For the record' ] );
signServe( $appData, '/.sign', 'POST', [ 't' => $forRecordToken, 'action' => 'sign', 'consent' => '1', 'image' => $drawn ], true, [ 'HTTP_USER_AGENT' => 'Agent/1.0' ] );
signWorkbench( 'admin' );
$recorded = array_values( array_filter( $call( 'sign/list' )[1]['requests'], static fn( array $request ): bool => $request['id'] === $forRecord['id'] ) )[0];
check( '...and once signed the row has the record: the address, the browser, the sentence, how it was signed, both hashes and both PDFs', $recorded['status'] === 'signed' && $recorded['record'] === [ 'ip' => '127.0.0.1', 'agent' => 'Agent/1.0', 'consent' => (string) \Nino\Html::resolveTextfill( $appData, '/feature/sign/form/consent' ) ]
	&& $recorded['method'] === 'drawn' && preg_match( '/^[a-f0-9]{64}$/D', $recorded['hashSigned'] ) === 1 && $recorded['files'] === [ 'shown' => true, 'signed' => true ] );

// Remind, cancel, delete
signFresh( $appData );
$remindCall = $call( 'sign/remind', [ 'id' => $asking[1]['id'] ] );
$cancelCall = $call( 'sign/cancel', [ 'id' => $asking[1]['id'] ] );
$remindOver = $call( 'sign/remind', [ 'id' => $asking[1]['id'] ] );
$cancelOver = $call( 'sign/cancel', [ 'id' => $asking[1]['id'] ] );
$signedOver = [ $call( 'sign/remind', [ 'id' => $forRecord['id'] ] ), $call( 'sign/cancel', [ 'id' => $forRecord['id'] ] ) ];
$unknown = [ $call( 'sign/remind', [ 'id' => 'ffffffffffff' ] ), $call( 'sign/cancel', [ 'id' => '../x' ] ), $call( 'sign/delete', [ 'id' => [ 'x' ] ] ), $call( 'sign/delete', [ 'id' => 'ffffffffffff' ] ) ];
check( 'sign/remind sends the link again, sign/cancel takes the request back; a request that is over is 409 sign_state for both - and an id nobody has, or that is none, is 404 sign_unknown',
	$remindCall[0] === 200 && $remindCall[1] === [ 'sent' => true ] && $cancelCall[0] === 200 && \Nino\Modules\Sign::get( $appData, $asking[1]['id'] )['status'] === 'cancelled' && $remindOver[0] === 409 && $remindOver[1]['code'] === 'sign_state'
	&& $cancelOver[0] === 409 && $signedOver[0][0] === 409 && $signedOver[1][0] === 409 && array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1]['code'], $unknown ) === [ '404 sign_unknown', '404 sign_unknown', '404 sign_unknown', '404 sign_unknown' ] );

$delivery = false;
signFresh( $appData );
$unsentRemind = $call( 'sign/remind', [ 'id' => $unsentAsk[1]['id'] ?? $byTitle( 'No mail' )[0]['id'] ] );
$delivery = true;
signFresh( $appData );
for( $each = 0; $each < 5; $each++ )
	\Nino\Feature::count( $appData, 'ratelimit', '127.0.0.1', 5, 3600 );
$limitedRemind = $call( 'sign/remind', [ 'id' => $byTitle( 'Capped mail' )[0]['id'] ] );
signFresh( $appData );
ninoWarnings();
check( 'a reminder whose mail could not be sent is a 500 with the code sign_mail, or a 429 with sign_ratelimit where the kernel\'s cap of mails refused it', $unsentRemind[0] === 500 && $unsentRemind[1]['code'] === 'sign_mail' && $limitedRemind[0] === 429 && $limitedRemind[1]['code'] === 'sign_ratelimit' );

$neighbours = array_diff_key( signIndex( $appData ), [ $forRecord['id'] => 1 ] );
$directoryOf = \Nino\Filesystem::path( $appData, '/data/sign/'. $forRecord['id'] );
$deleteCall = $call( 'sign/delete', [ 'id' => $forRecord['id'] ] );
check( 'sign/delete removes a request with its files - that one and no other', $deleteCall[0] === 200 && isset( signIndex( $appData )[ $forRecord['id'] ] ) === false && is_dir( $directoryOf ) === false && signIndex( $appData ) === $neighbours );

// The log, the tile
$log = static fn( string $action, array $data = [] ): string => \Nino\Modules\Sign\Admin::log( $action, $data );
$identified = [ 'id' => $asking[1]['id'], 'account' => $ada['id'], 'title' => 'Secret title', 'inputs' => [ 'note' => 'secret' ] ];
check( 'the activity log has a line for each action that writes - the id of the request, never the title, the member or a text - and none for an action that reads',
	array_sum( array_map( static fn( string $action ): int => $log( $action, $identified ) !== '' ? 1 : 0, [ 'sign/request', 'sign/remind', 'sign/cancel', 'sign/delete' ] ) ) === 4 && $log( 'sign/list', $identified ) === '' && $log( 'sign/options', $identified ) === ''
	&& str_contains( $log( 'sign/remind', $identified ), $asking[1]['id'] ) === true && str_contains( $log( 'sign/delete', $identified ), $asking[1]['id'] ) === true && str_contains( $log( 'sign/cancel', $identified ), $asking[1]['id'] ) === true
	&& array_filter( [ 'sign/request', 'sign/remind', 'sign/cancel', 'sign/delete' ], static fn( string $action ): bool => preg_match( '/Secret|secret|Ada|ada@/', $GLOBALS['log']( $action, $GLOBALS['identified'] ) ) === 1 ) === []
	&& count( array_unique( array_map( static fn( string $action ): string => $GLOBALS['log']( $action, $GLOBALS['identified'] ), [ 'sign/request', 'sign/remind', 'sign/cancel', 'sign/delete' ] ) ) ) === 4 );
$tell = static fn(): int => count( array_filter( \Nino\Modules\Sign::requests( $GLOBALS['appData'] ), static fn( array $request ): bool => in_array( $request['status'], [ 'requested', 'viewed' ], true ) ) );
$tryToSign( 'for the panel', fn() => $saveTemplate( '<h1>Service contract</h1><p>Between the club and [[client]]. Changed for the panel.</p>' ), fn() => $saveTemplate( $plainText ), [ 'page' => '/club/documents?x=1', 'title' => 'Asked again' ] );
$forAgain = $spoilt['for the panel'];
$waiting = $tell() + count( array_filter( \Nino\Modules\Sign::requests( $appData ), static fn( array $request ): bool => $request['status'] === 'changed' ) );
check( 'the Dashboard tile counts the requests that wait for a signature and the changed ones, which wait for the owner to ask again', \Nino\Modules\Sign\Admin::summary( $appData )['value'] === $waiting && $tell() < $waiting && $waiting > 0 && \Nino\Modules\Sign\Admin::summary( $appData )['label'] === '/_admin/sign/label/open' );

$row = array_column( $call( 'sign/list' )[1]['requests'], null, 'id' )[ $forAgain['id'] ] ?? [];
check( 'sign/list shows a changed request as changed, with the time it was found changed', ( $row['status'] ?? '' ) === 'changed' && ( $row['changed'] ?? '' ) === $forAgain['time'] && ( $row['files'] ?? [] ) === [ 'shown' => true, 'signed' => false ] );
$remindRefused = $call( 'sign/remind', [ 'id' => $forAgain['id'] ] );
check( '...it is not reminded (409, sign_state) and not cancelled', $remindRefused[0] === 409 && $remindRefused[1]['code'] === 'sign_state' && $call( 'sign/cancel', [ 'id' => $forAgain['id'] ] )[0] === 409 );

$before = array_keys( signIndex( $appData ) );
$oldEntry = signIndex( $appData )[ $forAgain['id'] ];
$mailsBefore = count( $mails );
signFresh( $appData );
$asked = $call( 'sign/again', [ 'id' => $forAgain['id'] ] );
$made = \Nino\Modules\Sign::get( $appData, (string) ( $asked[1]['id'] ?? '' ) );
$madeMail = $mails[$mailsBefore] ?? [ 'to' => '', 'body' => '' ];
check( 'sign/again makes a new request of the same template, data, member, title and page in one call - it waits, its member is mailed the link, which returns to the page the old one named - and the changed one stays as it is',
	$asked[0] === 200 && $made !== null && $made['id'] !== $forAgain['id'] && $made['status'] === 'requested' && [ $made['template'], $made['data'], $made['account'], $made['title'] ] === [ $oldEntry['template'], $oldEntry['data'], $oldEntry['account'], $oldEntry['title'] ]
	&& count( $mails ) === $mailsBefore + 1 && $madeMail['to'] === 'ada@example.org' && str_contains( $madeMail['body'], '&amp;return=%2Fclub%2Fdocuments' ) === true && count( array_diff( array_keys( signIndex( $appData ) ), $before ) ) === 1
	&& signIndex( $appData )[ $forAgain['id'] ] === $oldEntry && \Nino\Modules\Sign\Store::read( $appData )[ $made['id'] ]['page'] === '/club/documents' );
$notChanged = $call( 'sign/again', [ 'id' => $made['id'] ?? '' ] );
$unknownAgain = $call( 'sign/again', [ 'id' => 'ffffffffffff' ] );
check( '...only a changed request is asked again (409, sign_state), and an id nobody has is 404 sign_unknown', $notChanged[0] === 409 && $notChanged[1]['code'] === 'sign_state' && $unknownAgain[0] === 404 && $unknownAgain[1]['code'] === 'sign_unknown' && count( array_diff( array_keys( signIndex( $appData ) ), $before ) ) === 1 );
check( '...and its line in the activity log names the old request and the new one by their ids', str_contains( \Nino\Modules\Sign\Admin::log( 'sign/again', [ 'id' => $forAgain['id'], 'account' => $ada['id'] ] ), $forAgain['id'] ) === true && str_contains( \Nino\Modules\Sign\Admin::log( 'sign/again', [ 'id' => $forAgain['id'] ] ), $made['id'] ) === true
	&& str_contains( \Nino\Modules\Sign\Admin::log( 'sign/again', [ 'id' => $forAgain['id'], 'account' => $ada['id'] ] ), $ada['id'] ) === false );
\Nino\Modules\Sign::remove( $appData, $made['id'] );

// Asked not to mail: the request is made, nobody is mailed, the link is the answer - once
signFresh( $appData );
$mailsBefore = count( $mails );
$quiet = $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'title' => 'Quiet ask', 'notify' => false ] );
$quietLink = (string) ( $quiet[1]['link'] ?? '' );
$quietToken = preg_match( '~^https://example\.org/club/\.sign\?t=([A-Za-z0-9_-]{43})$~D', $quietLink, $quietFound ) === 1 ? $quietFound[1] : '';
$quietEntry = signIndex( $appData )[ $quiet[1]['id'] ?? '' ] ?? [];
check( 'sign/request with notify false makes the request, mails nobody and answers the id with the link as the mail would have carried it: the address of the site, /.sign and a token of 43 characters',
	$quiet[0] === 200 && array_keys( $quiet[1] ) === [ 'id', 'link' ] && $quietToken !== '' && count( $mails ) === $mailsBefore && ( $quietEntry['status'] ?? '' ) === 'requested' && ( $quietEntry['title'] ?? '' ) === 'Quiet ask' );
check( '...the index keeps the sha256 of that token and not the token, and the link opens the page for the same days as a mailed one',
	( $quietEntry['token'] ?? '' ) === hash( 'sha256', $quietToken ) && str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/sign/requests.php' ) ), $quietToken ) === false
	&& abs( ( $quietEntry['expires'] ?? 0 ) - ( time() + 14 * 86400 ) ) < 5 && signServe( $appData, '/.sign?t='. $quietToken )['/nino/http/response']['statusCode'] === 200 );
$listed = $call( 'sign/list' );
$logged = \Nino\Modules\Sign\Admin::log( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'notify' => false ] );
check( '...the link is in that answer and nowhere else: not in the list of requests, not in the line of the activity log - which says that no mail was sent', str_contains( json_encode( $listed ), $quietToken ) === false && str_contains( $logged, $quietToken ) === false
	&& str_contains( $logged, 'without mail' ) === true && str_contains( \Nino\Modules\Sign\Admin::log( 'sign/request', [ 'account' => $ada['id'] ] ), 'without mail' ) === false );

$loud = [ $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'notify' => true ] ), $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'notify' => 'false' ] ), $call( 'sign/request', [ 'account' => $ada['id'], 'template' => 'two-fields', 'notify' => 0 ] ) ];
check( '...while true, absent, and anything that is no false mail as before and answer no link', array_map( static fn( array $answer ): bool => $answer[0] === 200 && isset( $answer[1]['link'] ) === false, $loud ) === [ true, true, true ] && count( $mails ) === $mailsBefore + 3 );

$mailsBefore = count( $mails );
$quietRemind = $call( 'sign/remind', [ 'id' => $quiet[1]['id'], 'notify' => false ] );
$remindToken = preg_match( '~^https://example\.org/club/\.sign\?t=([A-Za-z0-9_-]{43})$~D', (string) ( $quietRemind[1]['link'] ?? '' ), $remindFound ) === 1 ? $remindFound[1] : '';
check( 'sign/remind with notify false mails nobody and answers the new link: the request holds the new token, whose sha256 is in the index, the old link is dead and the new one opens',
	$quietRemind[0] === 200 && $quietRemind[1]['sent'] === false && $remindToken !== '' && $remindToken !== $quietToken && count( $mails ) === $mailsBefore && ( signIndex( $appData )[ $quiet[1]['id'] ]['token'] ?? '' ) === hash( 'sha256', $remindToken )
	&& signServe( $appData, '/.sign?t='. $quietToken )['/nino/http/response']['statusCode'] === 404 && signServe( $appData, '/.sign?t='. $remindToken )['/nino/http/response']['statusCode'] === 200 );
$quietGone = $call( 'sign/cancel', [ 'id' => $quiet[1]['id'] ] );
check( '...a request that is over is refused without a link (409, sign_state), and the line of the log says that no mail was sent', $quietGone[0] === 200 && $call( 'sign/remind', [ 'id' => $quiet[1]['id'], 'notify' => false ] )[0] === 409
	&& str_contains( \Nino\Modules\Sign\Admin::log( 'sign/remind', [ 'id' => $quiet[1]['id'], 'notify' => false ] ), 'without mail' ) === true && str_contains( \Nino\Modules\Sign\Admin::log( 'sign/again', [ 'id' => $quiet[1]['id'], 'notify' => false ] ), 'without mail' ) === true );

signFresh( $appData );
$mailsBefore = count( $mails );
$viaClass = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'notify' => false, 'page' => '/club/documents' ] );
$classToken = preg_match( '~^https://example\.org/club/\.sign\?t=([A-Za-z0-9_-]{43})&return=%2Fclub%2Fdocuments$~D', (string) ( $viaClass['link'] ?? '' ), $classFound ) === 1 ? $classFound[1] : '';
$linkOut = \Nino\Modules\Sign::remind( $appData, $viaClass['id'], false );
$classLoud = \Nino\Modules\Sign::remind( $appData, $viaClass['id'], true );
check( 'Sign::request() with the option notify false is the request, mailed false and the link - with the page to return to, as the mail has it; Sign::remind() with false answers the new link, the same way, and with true answers true',
	is_array( $viaClass ) && $viaClass['mailed'] === false && $classToken !== '' && is_string( $linkOut ) === true && str_ends_with( $linkOut, '&return=%2Fclub%2Fdocuments' ) === true && str_contains( $linkOut, $classToken ) === false
	&& $classLoud === true && count( $mails ) === $mailsBefore + 1 );
\Nino\Modules\Sign::remove( $appData, $viaClass['id'] );

// ...a link without the mail is charged to the cap of mails an hour as a mail is: the sixth is refused, the flag of a refused mail is set, and nothing is made
signFresh( $appData );
$requestsBefore = count( signIndex( $appData ) );
$quiets = [];
for( $each = 0; $each < 6; $each++ )
	$quiets[] = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'notify' => false ] );
check( 'the sixth request in an hour whose link is not mailed is refused with "ratelimit" as the sixth mail is, makes no request, and flags the refusal like a refused mail', array_map( static fn( array|string $each ): string => is_array( $each ) === true ? 'made' : $each, $quiets ) === [ 'made', 'made', 'made', 'made', 'made', 'ratelimit' ]
	&& count( signIndex( $appData ) ) === $requestsBefore + 5 && \Nino\Mail::rateLimited( $appData ) === true );
$mailsBefore = count( $mails );
$mailedAfterFive = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
check( '...the same counter: a request that is mailed after five links is refused by it too', is_array( $mailedAfterFive ) && $mailedAfterFive['mailed'] === false && count( $mails ) === $mailsBefore );
signFresh( $appData );
foreach( $quiets as $each )
	if( is_array( $each ) === true )
		\Nino\Modules\Sign::remove( $appData, $each['id'] );
$kept = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
signFresh( $appData );
$reminds = [];
for( $each = 0; $each < 6; $each++ )
	$reminds[] = \Nino\Modules\Sign::remind( $appData, $kept['id'], false );
check( '...and the sixth link made again without a mail is false and the request keeps the token of the fifth', array_map( static fn( bool|string $each ): string => is_string( $each ) === true ? 'link' : 'false', $reminds ) === [ 'link', 'link', 'link', 'link', 'link', 'false' ] && \Nino\Mail::rateLimited( $appData ) === true
	&& \Nino\Modules\Sign\Store::read( $appData )[ $kept['id'] ]['token'] === hash( 'sha256', preg_match( '~t=([A-Za-z0-9_-]{43})~', $reminds[4], $found ) === 1 ? $found[1] : '' ) );
$panelQuiet = [];
signFresh( $appData );
signWorkbench( 'admin' );
for( $each = 0; $each < 6; $each++ )
	$panelQuiet[] = ninoCall( $appData, $panel, [ 'account' => $bob['id'], 'template' => 'two-fields', 'notify' => false ], 'sign/request' );
check( '...through the panel the sixth is a 429 with sign_ratelimit', array_column( $panelQuiet, 0 ) === [ 200, 200, 200, 200, 200, 429 ] && ( $panelQuiet[5][1]['code'] ?? '' ) === 'sign_ratelimit' );
$remindQuiet = ninoCall( $appData, $panel, [ 'id' => $kept['id'], 'notify' => false ], 'sign/remind' );
check( '...and the link made again through the panel is refused by the cap the same way', $remindQuiet[0] === 429 && ( $remindQuiet[1]['code'] ?? '' ) === 'sign_ratelimit' );
signFresh( $appData );

// ...the cap is the configured one: at 2 the third request is refused, at 100 and at 0 (no cap) the sixth goes through
$signCaps = [];
foreach( [ 2 => 3, 100 => 6, 0 => 6 ] as $cap => $times ) {
	signFresh( $appData );
	$appData['/nino/mail/cap'] = $cap;
	$made = [];
	for( $each = 0; $each < $times; $each++ )
		$made[] = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'], [ 'notify' => false ] );
	$signCaps[$cap] = array_map( static fn( array|string $one ): string => is_array( $one ) === true ? 'made' : $one, $made );
	foreach( $made as $one )
		if( is_array( $one ) === true )
			\Nino\Modules\Sign::remove( $appData, $one['id'] );
}
unset( $appData['/nino/mail/cap'] );
signFresh( $appData );
check( 'a request whose link is not mailed is charged against the configured cap: at 2 the third is refused with "ratelimit", at 100 and at 0 (no cap) six are made',
	$signCaps === [ 2 => [ 'made', 'made', 'ratelimit' ], 100 => array_fill( 0, 6, 'made' ), 0 => array_fill( 0, 6, 'made' ) ] );
$forRemind = \Nino\Modules\Sign::request( $appData, 'two-fields', [], $bob['id'] );
$appData['/nino/mail/cap'] = 2;
signFresh( $appData );
$remindRuns = array_map( static fn( bool|string $one ): string => is_string( $one ) === true ? 'link' : 'false', [ \Nino\Modules\Sign::remind( $appData, $forRemind['id'], false ), \Nino\Modules\Sign::remind( $appData, $forRemind['id'], false ), \Nino\Modules\Sign::remind( $appData, $forRemind['id'], false ) ] );
unset( $appData['/nino/mail/cap'] );
signFresh( $appData );
check( '...and so is the link made again: at cap 2 the third is false', $remindRuns === [ 'link', 'link', 'false' ] );
\Nino\Modules\Sign::remove( $appData, $forRemind['id'] );

// ...and a request asked again: the same option
signFresh( $appData );
$tryToSign( 'for the quiet again', fn() => $saveTemplate( '<h1>Service contract</h1><p>Between the club and [[client]]. Changed quietly.</p>' ), fn() => $saveTemplate( $plainText ), [ 'title' => 'Asked again quietly' ] );
$forQuiet = $spoilt['for the quiet again'];
$mailsBefore = count( $mails );
signFresh( $appData );
$quietAgain = $call( 'sign/again', [ 'id' => $forQuiet['id'], 'notify' => false ] );
check( 'sign/again takes notify the same way: a new request, no mail, and its link in the answer', $quietAgain[0] === 200 && preg_match( '~^https://example\.org/club/\.sign\?t=[A-Za-z0-9_-]{43}~', (string) ( $quietAgain[1]['link'] ?? '' ) ) === 1 && count( $mails ) === $mailsBefore
	&& ( \Nino\Modules\Sign::get( $appData, (string) ( $quietAgain[1]['id'] ?? '' ) )['status'] ?? '' ) === 'requested' );

// The sentences of the codes, and the words of the script, in both languages
$codes = [];
preg_match_all( "/'(sign_[a-z]+)'/", (string) file_get_contents( $dir. '/Admin/Admin.php' ), $inCode );
$codes = array_values( array_unique( $inCode[1] ) );
$missingCode = [];
foreach( [ 'en_US', 'de_DE' ] as $locale ) {
	$fills = \Nino\Admin\Admin::textFills( $appData, '/features/Sign/text', $locale );
	foreach( $codes as $code )
		if( trim( (string) ( $fills['[[/_admin/error/'. $code. ']]'] ?? '' ) ) === '' )
			$missingCode[] = $locale. ' '. $code;
}
$enKeys = array_keys( \Nino\Admin\Admin::textFills( $appData, '/features/Sign/text', 'en_US' ) );
$deKeys = array_keys( \Nino\Admin\Admin::textFills( $appData, '/features/Sign/text', 'de_DE' ) );
check( 'every code the panel answers with has its sentence in both interface languages ('. count( $codes ). ' of them), and the two languages have the same keys',
	$missingCode === [] && count( $codes ) === 9 && $enKeys === $deKeys && count( $enKeys ) > 60 && str_contains( \Nino\Admin\Admin::textFills( $appData, '/features/Sign/text', 'en_US' )['[[/_admin/error/sign_input]]'], '%s' ) === true );
foreach( [ 'en_US', 'de_DE' ] as $locale )
	check( "...the sentence of sign_ratelimit in $locale is not empty and names the hour", preg_match( '/Stunde|hour/', trim( \Nino\Admin\Admin::textFills( $appData, '/features/Sign/text', $locale )['[[/_admin/error/sign_ratelimit]]'] ?? '' ) ) === 1 );
echo "\n";

// --- The restore of a backup, and deactivation ----------------------------------------------------------------------------------

echo "The restore of a backup, and deactivation\n";

[ $keptA, ] = $ask( 'two-fields', [], $ada, [ 'title' => 'In the backup' ] );
[ $keptB, ] = $ask( 'two-fields', [], $ada, [ 'title' => 'On the disk' ] );
[ $lostC, ] = $ask( 'two-fields', [], $ada, [ 'title' => 'Nowhere' ] );
$staging = ninoSandboxDir( $appData ). '/staging';
$dataDir = \Nino\Filesystem::path( $appData, '/data' );
mkdir( $staging. '/data/sign/'. $keptA['id'], 0755, true );
copy( $dataDir. '/sign/'. $keptA['id']. '/shown.pdf', $staging. '/data/sign/'. $keptA['id']. '/shown.pdf' );
unlink( $dataDir. '/sign/'. $keptA['id']. '/shown.pdf' );
unlink( $dataDir. '/sign/'. $lostC['id']. '/shown.pdf' );
$stagedIndex = array_intersect_key( \Nino\Modules\Sign\Store::read( $appData ), array_flip( [ $keptA['id'], $keptB['id'], $lostC['id'] ] ) );
file_put_contents( $staging. '/data/sign/requests.php', '<?php return '. var_export( $stagedIndex, true ). ';' );
$liveIndexBytes = (string) file_get_contents( $dataDir. '/sign/requests.php' );
$restoreArgs = [ 'dataDir' => $dataDir, 'staging' => $staging ];
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $restoreArgs );
$restoredIndex = include $staging. '/data/sign/requests.php';
check( 'a restore keeps the requests of the staged index whose shown.pdf is in the backup or on the disk, and drops those whose is nowhere; the live index is not touched, the callback is the one init() registered',
	array_keys( $restoredIndex ) === [ $keptA['id'], $keptB['id'] ] && $restoredIndex[ $keptA['id'] ] === $stagedIndex[ $keptA['id'] ] && file_get_contents( $dataDir. '/sign/requests.php' ) === $liveIndexBytes );

unlink( $staging. '/data/sign/requests.php' );
$noIndexArgs = [ 'dataDir' => $dataDir, 'staging' => $staging ];
\Nino\Modules\Sign::callbackRestore( $appData, $noIndexArgs );
$emptyArgs = [ 'dataDir' => '', 'staging' => '' ];
\Nino\Modules\Sign::callbackRestore( $appData, $emptyArgs );
check( '...a backup that carries no index is left as it is, and a call with nothing to go by does nothing', is_file( $staging. '/data/sign/requests.php' ) === false );

$kept = [ 'data' => glob( $dataDir. '/sign/*' ), 'index' => signIndex( $appData ) ];
check( 'the features it requires cannot be switched off while it is on', is_string( \Nino\Features::deactivate( $appData, 'pdf' ) ) === true && is_string( \Nino\Features::deactivate( $appData, 'members' ) ) === true );
check( 'deactivation takes the panel away and keeps /data/sign: the requests, their PDFs and their pictures', \Nino\Features::deactivate( $appData, 'sign' ) === true && isset( \Nino\Admin\Admin::panels( $appData )['sign'] ) === false
	&& glob( $dataDir. '/sign/*' ) === $kept['data'] && signIndex( $appData ) === $kept['index'] && in_array( '\\Nino\\Modules\\Sign', (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === false );
check( '...and switched on again it finds them as they were', \Nino\Features::activate( $appData, 'sign' ) === true && signIndex( $appData ) === $kept['index'] && isset( \Nino\Admin\Admin::panels( $appData )['sign'] ) === true );
echo "\n";


// --- The look for the Design feature ------------------------------------------------------------------------------------------------

echo "The look - the files in design/, read by their grammar\n";

/*	One stylesheet in design/, a variant of the feature for the Design feature (its manual, under Base, says what a variant is),
	and the block it shows on the specimen. A head of lines "@tag words", the ladders of the knobs and nothing else: the layout is
	sign.css's own. Read here by its grammar - which tags, which fields, which names. What a head says is never compared	*/
$designDir = $dir. '/design';
$look = (string) file_get_contents( $designDir. '/regular.css' );
preg_match( '#^\s*/\*(.*?)\*/#s', $look, $comment );
$head = [];

foreach( (array) preg_split( '/\R/', $comment[1] ?? '' ) as $line )
	if( preg_match( '/^\s*\*?\s*(@[a-z]+(?:-de)?)\s+(\S.*?)\s*$/', (string) $line, $found ) === 1 )
		$head[ $found[1] ][] = $found[2];

$ruleOf = static function( string $css ): array {
	$rules = [];
	preg_match_all( '/([^{}]+)\{([^{}]*)\}/', (string) preg_replace( '#/\*.*?\*/#s', '', $css ), $found, PREG_SET_ORDER );
	foreach( $found as $rule ) {
		preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $rule[2], $declared, PREG_SET_ORDER );
		$rules[ trim( $rule[1] ) ] = array_column( $declared, 2, 1 ) + ( $rules[ trim( $rule[1] ) ] ?? [] );
	}
	return $rules;
};
$ladders = $ruleOf( $look );
$defaults = $ruleOf( (string) file_get_contents( $dir. '/assets/sign.css' ) )[':where(:root)'] ?? [];
$knobs = [];

foreach( $head['@knob'] ?? [] as $text ) {
	$fields = (array) preg_split( '/\s+/', $text, 4 );
	preg_match( '/^(.*?)(?:\s+=\s+(\S+))?$/', (string) ( $fields[3] ?? '' ), $label );
	$knobs[ (string) $fields[0] ] = [ (string) ( $fields[1] ?? '' ), (string) ( $fields[2] ?? '' ), $label[2] ?? null, trim( (string) ( $label[1] ?? '' ) ) ];
}

check( 'the feature brings one look, regular, and the block for the specimen beside it', array_map( 'basename', glob( $designDir. '/*' ) ?: [] ) === [ 'preview.tpl', 'regular.css' ] );
check( 'its head names and describes it in both languages, once each, and says the German in other words than the English',
	array_filter( [ '@name', '@name-de', '@description', '@description-de' ], static fn( string $tag ): bool => count( $head[$tag] ?? [] ) !== 1 ) === [] && $head['@name'] !== $head['@name-de'] && $head['@description'] !== $head['@description-de'] );
check( 'it declares the three knobs, once each and in order, with their kind and tab - the ground of the pad with the surface it stands at, the two scales without - each with its words in both languages',
	$knobs === [ 'ground' => [ 'ground', 'colours', 'default', 'Ground of the pad' ], 'space' => [ 'scale', 'surfaces', null, 'Space inside the form, the pad and the list' ], 'corners' => [ 'scale', 'surfaces', null, 'Corners of the form, the pad and the list' ] ]
	&& count( $head['@knob'] ) === 3 && array_map( static fn( string $text ): string => (string) preg_split( '/\s+/', $text, 2 )[0], $head['@knob-de'] ?? [] ) === [ 'ground', 'space', 'corners' ]
	&& array_filter( $head['@knob-de'], static fn( string $text ): bool => count( (array) preg_split( '/\s+/', $text, 2 ) ) < 2 ) === [] );

$rungs = [];

foreach( [ 'space', 'corners' ] as $scale )
	foreach( [ 'least', 'less', 'default', 'more', 'most' ] as $step )
		$rungs[] = '--sign-'. $scale. '--'. $step;

check( 'each scale declares its five rungs, once each, on :where(:root) - and nothing else is declared, no rule is written and no colour is a literal',
	array_keys( $ladders ) === [ ':where(:root)' ] && array_keys( $ladders[':where(:root)'] ) === $rungs && array_sum( array_map( static fn( string $rung ): int => substr_count( $look, $rung. ':' ), $rungs ) ) === 10
	&& preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|oklch|oklab)\(|!important/i', (string) preg_replace( '#/\*.*?\*/#s', '', $look ) ) !== 1 );
check( 'the middle of each is the value sign.css has as its default, so the form under it looks as it does without the Design feature',
	( $ladders[':where(:root)']['--sign-space--default'] ?? null ) === ( $defaults['--sign-space'] ?? false ) && ( $ladders[':where(:root)']['--sign-corners--default'] ?? null ) === ( $defaults['--sign-corners'] ?? false )
	&& isset( $defaults['--sign-ground'], $defaults['--sign-ground-on'], $defaults['--sign-ground-link'], $defaults['--sign-ground-border'], $defaults['--sign-ground-focus'] ) === true );
check( 'the stylesheet of the site holds rules for the form, the pad and the list only - every selector begins with one of the three classes - and no colour is a literal',
	(function() use ( $dir ): bool {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $dir. '/assets/sign.css' ) );
		preg_match_all( '/(?:^|})\s*([^{}@][^{}]*)\{/', $css, $selectors );
		foreach( $selectors[1] as $list )
			foreach( explode( ',', $list ) as $selector )
				if( trim( $selector ) !== ':where(:root)' && preg_match( '/^\.sign-(?:form|pad|list)(?![a-z0-9-])/', trim( $selector ) ) !== 1 )
					return false;
		return preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?)\(/i', $css ) !== 1;
	})() );

$block = (string) file_get_contents( $designDir. '/preview.tpl' );
preg_match_all( '/\sid="([^"]*)"/', $block, $blockIds );
check( 'the block for the specimen: one grid row with the form, the pad and the list written out and no shortcode, every id sign- something and none twice, no section of its own, no inline handler and no script',
	str_starts_with( $block, '<div class="nino-grid-row">' ) === true && count( signFind( $block, '//div[@class="sign-form"]' ) ) === 1 && count( signFind( $block, '//canvas[@class="sign-pad__canvas"]' ) ) === 1 && count( signFind( $block, '//li[@class="sign-list__row"]' ) ) === 2
	&& preg_match( '/\[sign-[a-z]+/', $block ) !== 1 && $blockIds[1] !== [] && count( array_unique( $blockIds[1] ) ) === count( $blockIds[1] ) && array_filter( $blockIds[1], static fn( string $each ): bool => str_starts_with( $each, 'sign-' ) === false ) === []
	&& stripos( $block, '<section' ) === false && preg_match( '/\son[a-z]+\s*=|<script/i', $block ) !== 1 );

$designSetup = '\\Nino\\Modules\\Design\\Setup';
$designPreview = '\\Nino\\Modules\\Design\\Preview';
$designCompiler = '\\Nino\\Modules\\Design\\Compiler';

if( class_exists( $designSetup ) === false || class_exists( $designCompiler ) === false )
	echo "  note - there is no Design feature beside this one: the look is not run through it here, bin/check.sh runs it with it in the checkout\n";
else {
	$library = \Nino\Features::dir(). '/Design/library';
	$modulesKept = (array) ( $appData['/nino/modules'] ?? [] );
	$localeKept = $appData['./nino/locales/current'];

	/** Which of the two features are on: their classes are listed in /nino/modules, and what the kernel and the Design feature have read of the features is read again */
	$featuresOn = static function( array $names ) use ( &$appData, $modulesKept ): void {
		$appData['/nino/modules'] = array_merge( array_values( array_diff( $modulesKept, [ '\\Nino\\Modules\\Sign', '\\Nino\\Modules\\Design' ] ) ), array_map( static fn( string $name ): string => '\\Nino\\Modules\\'. $name, $names ) );
		unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
	};

	ninoWarnings();
	$featuresOn( [ 'Sign', 'Design' ] );

	$scanNotes = [];
	$scan = $designSetup::features( $appData, $scanNotes );
	$feature = $scan['sign'] ?? [];
	$variants = $feature['catalogue'] ?? [];
	$knobTold = [ 'ground' => [ 'ground', 'colours' ], 'space' => [ 'scale', 'surfaces' ], 'corners' => [ 'scale', 'surfaces' ] ];

	check( 'with the Design feature on, the scan lists this feature by its one look, which it has before anything is chosen, and has nothing to say of it',
		isset( $scan['sign'] ) === true && realpath( (string) $feature['dir'] ) === realpath( $dir ) && array_keys( $variants ) === [ 'regular' ] && $feature['default'] === 'regular' && $scanNotes === [] );

	$appData['./nino/locales/current'] = 'en_US';
	$english = $designSetup::features( $appData )['sign']['catalogue']['regular'] ?? [];
	$appData['./nino/locales/current'] = 'de_DE';
	$german = $designSetup::features( $appData )['sign']['catalogue']['regular'] ?? [];
	$answers = [];

	foreach( $variants['regular']['knobs'] ?? [] as $name => $knob )
		$answers[$name] = [ $knob['kind'], $knob['tab'] ];

	check( '...described in the language of the workbench by the lines of its head, and answering to the three knobs with the kind and the tab the head names, each scale published by its ladder',
		( $english['name'] ?? null ) === $head['@name'][0] && ( $english['description'] ?? null ) === $head['@description'][0] && ( $german['name'] ?? null ) === $head['@name-de'][0] && ( $german['description'] ?? null ) === $head['@description-de'][0]
		&& $answers === $knobTold && ( $variants['regular']['knobs']['space']['ladder'] ?? [] ) === [ 'space' ] && ( $variants['regular']['knobs']['corners']['ladder'] ?? [] ) === [ 'corners' ] );

	$blockTold = [];
	$inside = $designSetup::previewOf( (string) $feature['dir'], 'sign', $blockTold );
	$blocks = $designPreview::blocks( $appData );
	check( 'the block stands the check Design holds it to, the scan publishes its anchor, feature-sign, and the preview makes the section of it', $inside === trim( $block ) && $blockTold === [] && ( $feature['anchor'] ?? null ) === 'feature-sign'
		&& isset( $blocks['sign'] ) === true && preg_match( '~^<section id="feature-sign" class="nino-section"[^>]*>\s*(.*?)\s*</section>$~s', $blocks['sign'], $section ) === 1 && str_contains( $section[1] ?? '', 'id="sign-form"' ) === true );

	$featuresOn( [ 'Sign', 'Design' ] );
	$notes = [];
	$sheet = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'sign' => [ 'set' => 'regular' ] ] ], $library, $notes ), $library, $notes );
	$selected = [];

	foreach( [ 'space', 'corners' ] as $scale )
		if( str_contains( $sheet, "\n\t--sign-". $scale. ': var('. sprintf( $designSetup::KNOB_TOKEN, 'sign', $scale, 'default' ). ");\n" ) === false )
			$selected[] = $scale;

	foreach( \Nino\Modules\Design\Colours::parts() as $part )
		if( str_contains( $sheet, "\n\t--sign-ground". ( $part === 'bg' ? '' : '-'. $part ). ': var('. \Nino\Modules\Design\Colours::token( 'default', $part ). ");\n" ) === false )
			$selected[] = 'ground '. $part;

	check( 'with the look chosen the sheet carries its stylesheet as the file has it, a line for the ladder of both scales and every part of the ground the palette solved, and compiles without a note',
		$selected === [] && $notes === [] && str_contains( $sheet, rtrim( $look, "\n" ) ) === true );

	$moved = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'sign' => [ 'set' => 'regular', 'knobs' => [ 'space' => -2, 'corners' => 2, 'ground' => 'dark' ] ] ] ], $library, $notes ), $library, $notes );
	check( '...a position that is moved reaches the sheet as the rung it stands for, and a ground that is chosen as the palette\'s token of that surface',
		str_contains( $moved, "\n\t--sign-space: var(". sprintf( $designSetup::KNOB_TOKEN, 'sign', 'space', 'least' ). ");\n" ) === true && str_contains( $moved, "\n\t--sign-corners: var(". sprintf( $designSetup::KNOB_TOKEN, 'sign', 'corners', 'most' ). ");\n" ) === true
		&& str_contains( $moved, "\n\t--sign-ground: var(". \Nino\Modules\Design\Colours::token( 'dark', 'bg' ). ");\n" ) === true );

	// The bundle of a project the wizard has set up lists the sheet of the feature after the theme the Design feature writes
	$bundled = $appData;
	$bundled['/nino/html/assets'] = [ '/.cache/style.css' => array_merge( (array) ( \Nino\AppData::DEFAULTS['/nino/html/assets']['/.cache/style.css'] ?? [] ), [ $designCompiler::TARGET, '/assets/style.css' ] ) ];
	\Nino\Features::registerAssets( $bundled );
	$bundle = $bundled['/nino/html/assets']['/.cache/style.css'];
	check( 'in a booted project the bundle lists sign.css after the theme.css the Design feature writes its sheet to - the order the :where() of its defaults is for - and the script of the pad in the script bundle',
		in_array( '/features/Sign/assets/sign.css', $bundle, true ) === true && array_search( '/features/Sign/assets/sign.css', $bundle, true ) > array_search( $designCompiler::TARGET, $bundle, true )
		&& in_array( '/features/Sign/assets/sign.js', (array) ( $bundled['/nino/html/assets']['/.cache/script.js'] ?? [] ), true ) === true );

	$appData['/nino/modules'] = $modulesKept;
	$appData['./nino/locales/current'] = $localeKept;
	unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
}

echo "\n";


// --- The scripts, where node is on the path ------------------------------------------------------------------------------------

// sign-js-smoke.js beside this file draws the panel over the kernel's own Nino.admin.js and a stand-in for the rest of Nino - the
// checkout this suite runs against, which is handed on - and runs sign.js, the pad, over a stand-in canvas; this suite runs it too
// where node is on the path, so that bin/check.sh and CI cover both halves in one go
$node = function_exists( 'shell_exec' ) === true ? trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) ) : '';

if( $node === '' || function_exists( 'exec' ) === false )
	echo "  --  - node is not available here: sign-js-smoke.js was NOT run\n";
else {
	$output = [];
	$exit = 1;
	exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/sign-js-smoke.js' ). ' 2>&1', $output, $exit );
	check( 'sign-js-smoke.js passes - '. ( $output === [] ? 'no output' : end( $output ) ), $exit === 0 );
}

echo "\n";
ninoDone( $appData );

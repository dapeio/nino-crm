<?php
declare(strict_types=1);

/**
 *	Nino
 *	review-smoke.php	Contract test for the Review feature (Modules\Review): the manifest and the
 *										activation through \Nino\Features - Members and Files are brought along -, the
 *										reading of a time, the data layer and the API other features read, the route
 *										a comment is posted to with its states and its caps, the component that lists
 *										and plays, the panel and its permission with the codes of its refusals, the
 *										mails, the restore, deactivation, the looks for the Design feature, and - where
 *										node is on the path - the scripts' own test. Travels with the feature and runs
 *										against the checkout three levels up, or the one NINO_ROOT names (see
 *										tests/harness.php there).
 *
 *										The files the comments are on are made the way the owner makes them: a folder
 *										through the panel of the Files feature, and a file through its upload action,
 *										whose bytes the feature sniffs - so the MIME types in the index are the ones
 *										finfo reads of the fixtures, not the ones this test claims.
 *
 *	Usage: php features/Review/tests/review-smoke.php
 *	       NINO_ROOT=../nino php features/Review/tests/review-smoke.php
 */

// The checkout this test runs against: three levels up when the feature sits
// in a project's features/, else the one NINO_ROOT names (a checkout beside
// the catalogue, say). The features root is this feature's own parent either
// way, so the kernel's autoloader serves the class from here
$root = getenv( 'NINO_ROOT' ) ?: dirname( __DIR__, 3 );
defined( 'NINO_FEATURES_DIR' ) === true || define( 'NINO_FEATURES_DIR', dirname( __DIR__, 2 ) );
require $root. '/tests/harness.php';

$appData = ninoSandbox( 'review' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$sandbox = ninoSandboxDir( $appData );

// The project directory and its fill, empty here, the way a site at the domain root has it - and the facts of the website the
// mails are built on: its address, which every link of a mail starts with, and the contact address
$appData['/nino/dir'] = '';

// The look of the site's mails: the base unit's words, which a project has from its wizard
if( is_file( $root. '/_admin/install/library/base/text/global.php' ) === true )
	\Nino\Html::addFills( $appData, (array) include $root. '/_admin/install/library/base/text/global.php', '*' );

\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club', '[[/project/company/contact/email]]' => 'owner@example.org', '[[/project/company/general/name]]' => 'The Club' ], '*' );

// What the kernel boots with, as far as the feature touches it: the components, the [csrf] field, the [template] shortcode and
// the guard that refuses a post without its token
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

// What the features that build on this one are told: the entry of a comment
$told = [];
\Nino\Callbacks::registerCallback( $appData, '/review/comment', static function( array &$appData, array &$entry ) use ( &$told ): void {
	$told[] = $entry;
} );

/**
 *	The data file of the feature as it is on disk, read fresh
 *
 *	@param		array 		&$appData
 *
 *	@return 	array										The comments as stored: file id => comment id => comment
 */
function reviewData( array &$appData ): array {

	$state = \Nino\Filesystem::getFileContent( $appData, '/data/review.php', [] );

	return is_array( $state ) === true ? $state : [];
}

/**
 *	The elements of a rendered piece of html by xpath, as a document reads them
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	array										The DOMElements
 */
function reviewFind( string $html, string $query ): array {

	$document = new DOMDocument();
	libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="UTF-8"><body>'. $html. '</body>' );
	libxml_clear_errors();

	return iterator_to_array( ( new DOMXPath( $document ) )->query( $query ) ?: [] );
}

/**
 *	@param		DOMElement	$element
 *
 *	@return 	array										The classes of the element
 */
function reviewClasses( DOMElement $element ): array {

	return array_values( array_filter( preg_split( '/\s+/', $element->getAttribute( 'class' ) ) ?: [] ) );
}

/**
 *	A request of the site the way the web server hands it over, answered by the routes and the callbacks that are registered:
 *	the request built, the global callbacks of the response run - the csrf guard and the gate of Members among them - and then
 *	the route's own. A post carries the csrf token of the session unless $csrf is false. Nothing is rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$method
 *	@param		array 		$post
 *	@param		bool			$csrf					Whether a post carries the token
 *	@param		array 		$server				More of $_SERVER: the address of the client
 *
 *	@return 	array										The request, response and all
 */
function reviewServe( array &$appData, string $uri, string $method = 'GET', array $post = [], bool $csrf = true, array $server = [] ): array {

	// What a request keeps for itself alone is gone before the next one begins
	unset( $appData['./review/forms'] );

	$_POST = $post + ( $method === 'POST' && $csrf === true ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method, $server );
	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return $request;
}

/**
 *	What a response says in the places a visitor and a test read it
 *
 *	@param		array 		$request
 *
 *	@return 	array										[ status, Location, Content-Type, body ]
 */
function reviewAnswer( array $request ): array {

	$response = $request['/nino/http/response'];

	return [ $response['statusCode'], (string) ( $response['header']['Location'] ?? '' ), (string) ( $response['header']['Content-Type'] ?? '' ), $response['body'] ?? '' ];
}

/**
 *	A call whose failure is a failed pin and not the end of the run
 *
 *	@param		callable	$call
 *
 *	@return 	mixed											What it returns; null where it threw
 */
function reviewTry( callable $call ): mixed {

	try {
		return $call();
	}
	catch( \Throwable ) {
		return null;
	}
}

/**
 *	A new window for every cap: the counters of the features and the mail cap of the kernel, which counts the same client
 *
 *	@param		array 		&$appData
 *
 *	@return 	void
 */
function reviewFresh( array &$appData ): void {

	foreach( [ 'review-tries', 'members-tries', 'files-tries', 'ratelimit' ] as $counter )
		\Nino\Filesystem::putFileContent( $appData, '/data/'. $counter. '.php', [] );
}

/**
 *	A visitor who has never been here: no session, nobody signed in
 *
 *	@return 	void
 */
function reviewVisitor(): void {

	$_SESSION = [];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

/**
 *	Who is at the workbench: nobody, the plain account (which may do nothing there), the full-access one, the one with the
 *	permission of this panel alone, the one with the Files panel's beside it, or the one with the permission of every panel
 *
 *	@param		string		$who					'', 'plain', 'admin', 'reviewer', 'both' or 'tools'
 *
 *	@return 	void
 */
function reviewWorkbench( string $who ): void {

	global $appData;

	reviewVisitor();

	$accounts = [ 'admin' => [ 'admin@example.com', 'correct horse battery staple' ], 'plain' => [ 'plain@example.com', 'plain password' ], 'reviewer' => [ 'reviewer@example.com', 'reviewer password' ],
		'both' => [ 'both@example.com', 'both password' ], 'tools' => [ 'tools@example.com', 'tools password' ] ];

	if( isset( $accounts[$who] ) === true )
		\Nino\Auth::loginUser( $appData, $accounts[$who][0], $accounts[$who][1] );
}

/**
 *	Sign an account in the way a member does: a link is mailed, the page it opens asks, the button of that page signs in
 *
 *	@param		array 		&$appData
 *	@param		array 		$account
 *
 *	@return 	bool											Whether the member is signed in afterwards
 */
function reviewSignIn( array &$appData, array $account ): bool {

	global $mails;

	reviewFresh( $appData );
	reviewVisitor();

	$count = count( $mails );

	if( \Nino\Modules\Members::link( $appData, $account, 'login' ) !== true || preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( $mails[$count]['body'] ?? '' ), $found ) !== 1 )
		return false;

	reviewServe( $appData, '/.members/login?t='. $found[1] );
	reviewServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] );

	return ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

/**
 *	Speak as somebody: the session of the browser they signed in with, or none
 *
 *	@param		string		$who					ada, bob, cyd or '' for a visitor
 *
 *	@return 	void
 */
function reviewAs( string $who ): void {

	$_SESSION = $who === '' ? [] : $GLOBALS['sessions'][$who];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

/**
 *	A file the way the owner puts it there: one chunk through the upload action of the panel of the Files feature, which sniffs
 *	the bytes - the entry has the MIME type finfo reads of them
 *
 *	@param		array 		&$appData
 *	@param		string		$folder				The id of a folder
 *	@param		string		$name
 *	@param		string		$bytes
 *	@param		bool			$visible
 *
 *	@return 	array										The entry as Files::file() has it; [] where the upload was refused
 */
function reviewUpload( array &$appData, string $folder, string $name, string $bytes, bool $visible = true ): array {

	global $panel;

	reviewWorkbench( 'admin' );

	$path = ninoSandboxDir( $appData ). '/chunk-'. bin2hex( random_bytes( 4 ) );
	file_put_contents( $path, $bytes );
	$_FILES = [ 'chunk' => [ 'name' => 'blob', 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $bytes ) ] ];
	$answer = ninoCall( $appData, $panel, [ 'folder' => $folder, 'name' => $name, 'size' => strlen( $bytes ), 'upload' => '', 'index' => 0, 'count' => 1, 'visible' => $visible ], 'files/upload' );
	$_FILES = [];
	reviewVisitor();

	return $answer[0] === 200 ? (array) \Nino\Modules\Files::file( $appData, (string) $answer[1]['id'] ) : [];
}

// The fixtures: bytes the Files feature takes for what their names say - an mp3, an mp4 and a zip
$mp3 = "\xFF\xFB\x90\x00". substr( str_repeat( hash( 'sha512', 'review', true ), 80 ), 0, 4000 );
$mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom". str_repeat( 'v', 4000 );
$zip = "PK\x03\x04\x0a\x00\x00\x00\x00\x00\x00\x00\x00\x00". pack( 'V', 0 ). pack( 'V', 0 ). pack( 'V', 0 ). pack( 'v', 5 ). pack( 'v', 0 ). 'a.txt'
	. "PK\x01\x02". str_repeat( "\0", 20 ). pack( 'v', 5 ). str_repeat( "\0", 16 ). 'a.txt'. "PK\x05\x06". str_repeat( "\0", 4 ). pack( 'v', 1 ). pack( 'v', 1 ). pack( 'V', 51 ). pack( 'V', 31 ). pack( 'v', 0 );


// --- The feature - manifest and activation -------------------------------------

echo "The feature - manifest and activation\n";

$dir = dirname( __DIR__ );
$manifest = \Nino\Features::manifest( $dir );
check( 'the manifest validates, key "review", without a warning', is_array( $manifest ) && $manifest['key'] === 'review' && ninoWarnings() === [] );
check( 'class, version, category and requirement are what the plan says: it requires Files (which brings Members), has no maturity and needs nothing of php beyond the kernel\'s',
	is_array( $manifest ) && $manifest['module'] === '\\Nino\\Modules\\Review' && $manifest['version'] === '1.0.0' && $manifest['category'] === 'communication' && $manifest['requires'] === [ 'files' ]
	&& $manifest['maturity'] === '' && $manifest['php'] === [ 'ext' => [] ] );
check( 'it names itself in both interface languages', is_array( $manifest ) && \Nino\Features::localized( $manifest['description'], 'de_DE' ) !== \Nino\Features::localized( $manifest['description'], 'en_US' )
	&& \Nino\Features::localized( $manifest['name'], 'en_US' ) === 'Review' && \Nino\Features::localized( $manifest['name'], 'de_DE' ) === 'Review' );
check( 'its floor is the kernel that carries the file response: above 1.9.0, a constraint of the same major version', is_array( $manifest ) && $manifest['nino'] === '^1.9.1' && \Nino\Features::satisfies( $manifest['nino'], '1.9.0' ) === false
	&& \Nino\Features::satisfies( $manifest['nino'], '1.9.1' ) === true && \Nino\Features::satisfies( $manifest['nino'], '1.99.0' ) === true && \Nino\Features::satisfies( $manifest['nino'], '2.0.0' ) === false );
check( 'it owns the comments and nothing else - not the counter, which is an hour long - and brings its stylesheet and its script', is_array( $manifest ) && $manifest['data'] === [ '/data/review.php' ]
	&& $manifest['assets'] === [ '/features/Review/assets/review.css', '/features/Review/assets/review.js' ] );
check( 'the settings are the four of the plan, each with its type, its default and its bounds', is_array( $manifest ) && array_keys( $manifest['settings'] ) === [ 'notifyOwner', 'notifyMembers', 'length', 'attempts' ]
	&& $manifest['settings']['notifyOwner']['type'] === 'bool' && $manifest['settings']['notifyOwner']['default'] === false && $manifest['settings']['notifyMembers']['type'] === 'bool' && $manifest['settings']['notifyMembers']['default'] === false
	&& [ $manifest['settings']['length']['type'], $manifest['settings']['length']['default'], $manifest['settings']['length']['min'], $manifest['settings']['length']['max'], $manifest['settings']['length']['unit'] ] === [ 'int', 2000, 100, 5000, 'characters' ]
	&& [ $manifest['settings']['attempts']['type'], $manifest['settings']['attempts']['default'], $manifest['settings']['attempts']['min'], $manifest['settings']['attempts']['max'], $manifest['settings']['attempts']['unit'] ] === [ 'int', 60, 1, 300, 'per-hour' ] );
check( 'it declares the one component, with the attribute kind (all, audio, video), what it draws, where it may stand and a picture of its own - and no stack', is_array( $manifest ) && array_keys( $manifest['components'] ) === [ 'review-player' ]
	&& $manifest['components']['review-player']['source'] === 'none' && $manifest['components']['review-player']['loop'] === false && $manifest['components']['review-player']['draws'] === 'div .review-player'
	&& $manifest['components']['review-player']['in'] === [ 'section' ] && $manifest['components']['review-player']['preview'] === 'block' && count( $manifest['components']['review-player']['icon'] ) >= 1
	&& $manifest['components']['review-player']['attributes']['kind']['options'] === [ 'all', 'audio', 'video' ] && $manifest['components']['review-player']['attributes']['kind']['default'] === 'all' && $manifest['stacks'] === [] );
check( 'it says all six sections of its manual in both languages, and its section of the privacy policy and the page as lines of the install section', is_array( $manifest ) && (function() use ( $manifest ): bool {
	foreach( \Nino\Features::MANUAL_SECTIONS as $section )
		foreach( (array) ( $manifest['manual'][$section] ?? [] ) as $line )
			if( is_array( $line ) === false || trim( (string) ( $line['en_US'] ?? '' ) ) === '' || trim( (string) ( $line['de_DE'] ?? '' ) ) === '' )
				return false;
	return isset( $manifest['manual']['install']['elements/privacy.php'], $manifest['manual']['install']['page'], $manifest['manual']['install']['text/<locale>.php'] ) === true && count( $manifest['manual']['shortcodes'] ) >= 2
		&& count( $manifest['manual']['routes'] ) === 3 && count( $manifest['manual']['callbacks'] ) === 4 && count( $manifest['manual']['panel'] ) >= 1 && count( $manifest['manual']['markup'] ) >= 2;
} )() );
check( '...and the line of its panel names the permission of the Files panel, which the player of the panel needs, in both languages', is_array( $manifest ) && str_contains( (string) ( $manifest['manual']['panel']['Review']['en_US'] ?? '' ), 'permission of the Files panel' ) === true
	&& str_contains( (string) ( $manifest['manual']['panel']['Review']['de_DE'] ?? '' ), 'Berechtigung des Panels Dateien' ) === true );

// The catalogue's preview reads every manifest through the kernel, and is a script of the catalogue: it is there when this test
// runs in the repository, not when the feature has been dropped into a project
$preview = dirname( __DIR__, 3 ). '/bin/catalogue.php';

if( is_file( $preview ) === false )
	echo "  note - no bin/catalogue.php beside this feature: the catalogue's preview was not asked\n";
else {
	$listed = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( $preview ). ' '. escapeshellarg( $root ). ' 2>/dev/null' ), true );
	check( 'the catalogue preview lists it, with its category, its version and its requirement', is_array( $listed ) && count( array_filter( (array) ( $listed['features'] ?? [] ), static fn( mixed $entry ): bool => is_array( $entry ) === true
		&& ( $entry['key'] ?? '' ) === 'review' && ( $entry['category'] ?? '' ) === 'communication' && ( $entry['version'] ?? '' ) === '1.0.0' && ( $entry['requires'] ?? [] ) === [ 'files' ] ) ) === 1 );
}

// The Legal module's type is what the feature's section is added to: put there first, where the kernel has the module
if( class_exists( '\\Nino\\Modules\\Legal' ) === true ) {
	$routes = [];
	$blacklist = [];
	$config = [];
	\Nino\Features::applyUnit( $appData, $root. '/_nino/Nino/Modules/Legal/install', [ 'de_DE', 'en_US' ], $routes, $blacklist, $config, true );
}

ninoWarnings();
check( 'the registry lists it, inactive, with nothing in the way - the floor holds on this kernel', ( \Nino\Features::get( $appData, 'review' )['active'] ?? null ) === false && ( \Nino\Features::get( $appData, 'review' )['problems'] ?? null ) === [] );
check( 'it cannot be activated without Files: the activation brings the required features along, Files and Members first', \Nino\Features::activate( $appData, 'review' ) === true
	&& \Nino\Features::get( $appData, 'review' )['installed'] === '1.0.0' && \Nino\Features::get( $appData, 'files' )['active'] === true && \Nino\Features::get( $appData, 'members' )['active'] === true );

// Whatever that check found, the rest of the suite runs on a project that has Members and Files on
foreach( [ 'members', 'files' ] as $needed )
	if( ( \Nino\Features::get( $appData, $needed )['active'] ?? false ) !== true )
		\Nino\Features::activate( $appData, $needed );

$modules = \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'];
check( 'the class is listed in /nino/modules after Files and Members, and the unit merged its words into both languages', in_array( '\\Nino\\Modules\\Review', $modules, true ) === true
	&& array_search( '\\Nino\\Modules\\Review', $modules, true ) > array_search( '\\Nino\\Modules\\Files', $modules, true ) && array_search( '\\Nino\\Modules\\Files', $modules, true ) > array_search( '\\Nino\\Modules\\Members', $modules, true )
	&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $GLOBALS['appData'], '/text/'. $locale. '.php', [] )['[[/feature/review/state/sent]]'] ?? '' ) ) === '' ) === [] );

if( class_exists( '\\Nino\\Modules\\Legal' ) === false )
	echo "  note - this Nino has no \\Nino\\Modules\\Legal: the section of the privacy policy was not looked for\n";
else {
	$privacy = \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] );
	check( 'the section of the privacy policy is there in both languages, at its position', isset( $privacy['de_DE']['review']['title'], $privacy['en_US']['review']['text'] ) === true && ( $privacy['*']['review']['order'] ?? null ) === 850 );

	$privacy['en_US']['review']['text'] = '<p>The editor changed this.</p>';
	\Nino\Filesystem::putFileContent( $appData, '/elements/privacy.php', $privacy );
	unset( $appData['./nino/features/all'] );
	\Nino\Features::activate( $appData, 'review' );
	check( 'a second activation changes nothing of it - a section the editor changed stays', \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] ) === $privacy );
}

\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

check( 'init registers the route with its handler, the hook on the deletion of a file and the merge of a restore - and no shortcode of its own beside the component of the manifest',
	( $appData['/nino/http/routes']['POST://.review/comment']['uri'] ?? null ) === '/.review/comment' && isset( $appData['./nino/callbacks']['/nino/http/response/POST://.review/comment'], $appData['./nino/callbacks']['/nino/admin/restore'], $appData['./nino/callbacks']['/files/delete'] ) === true
	&& count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/review-player' ) ) === 1 );
check( '...and publishes the words of the script to the Jstext module - the prefix of the script\'s words, not the mails\' and not the panel\'s', class_exists( '\\Nino\\Modules\\Jstext' ) === false
	|| ( in_array( '/feature/review/script/', \Nino\Modules\Jstext::keys( $appData ), true ) === true && array_filter( \Nino\Modules\Jstext::keys( $appData ), static fn( string $key ): bool => str_starts_with( $key, '/feature/review/' ) && $key !== '/feature/review/script/' ) === [] ) );

echo "\n";


// --- A time ----------------------------------------------------------------------

echo "Review::seconds() and clock() - a time as a person types it\n";

$table = [ '1:05' => 65.0, '0:03.5' => 3.5, '0:02.5' => 2.5, '1:05.7' => 65.7, '75' => 75.0, '1:02:03' => 3723.0, ' 1:05 ' => 65.0, '62:03' => 3723.0, '0:00' => 0.0, '3,5' => 3.5, '1:05,5' => 65.5, '1:5' => 65.0, '0' => 0.0, '12.34' => 12.3, '99:59:59.9' => 359999.9,
	'x' => null, '' => null, ' ' => null, '-5' => null, '-1:05' => null, '1:75' => null, '1:60' => null, '1:02:60' => null, '1:2:3' => null, '1e3' => null, '.5' => null, '5.' => null, '1:05:' => null, ':05' => null, '1::05' => null, '1.5.2' => null,
	'100:00:00' => null, '99999999999999999999' => null, "1:05\n:3" => null, 'NaN' => null, '0x10' => null, '１:０５' => null ];
$wrong = [];

foreach( $table as $input => $expected )
	if( \Nino\Modules\Review::seconds( (string) $input ) !== $expected )
		$wrong[] = json_encode( (string) $input ). ' is '. var_export( \Nino\Modules\Review::seconds( (string) $input ), true ). ', not '. var_export( $expected, true );

check( 'seconds() reads m:ss, h:mm:ss and a number - a comma will do for the point - round to one decimal, and nothing, a negative, a clock past 59 seconds, a day and more than 99:59:59 and anything that is no time as null ('. count( $table ). ' cases)'
	. ( $wrong === [] ? '' : ' - '. implode( '; ', array_slice( $wrong, 0, 4 ) ) ), $wrong === [] );
check( '...the table of the plan: 1:05 is 65, 0:03.5 is 3.5, 75 is 75, 1:02:03 is 3723, x, nothing and a negative number are null', \Nino\Modules\Review::seconds( '1:05' ) === 65.0 && \Nino\Modules\Review::seconds( '0:03.5' ) === 3.5
	&& \Nino\Modules\Review::seconds( '75' ) === 75.0 && \Nino\Modules\Review::seconds( '1:02:03' ) === 3723.0 && \Nino\Modules\Review::seconds( 'x' ) === null && \Nino\Modules\Review::seconds( '' ) === null && \Nino\Modules\Review::seconds( '-3' ) === null );
check( 'clock() writes m:ss with the minutes as many as there are, the decimals left out and the seconds below 60 - and what it writes seconds() reads back',
	\Nino\Modules\Review::clock( 65.0 ) === '1:05' && \Nino\Modules\Review::clock( 3.5 ) === '0:03' && \Nino\Modules\Review::clock( 0.0 ) === '0:00' && \Nino\Modules\Review::clock( 3723.0 ) === '62:03' && \Nino\Modules\Review::clock( 59.99 ) === '0:59'
	&& \Nino\Modules\Review::clock( 359999.9 ) === '5999:59' && \Nino\Modules\Review::clock( -4.0 ) === '0:00' && \Nino\Modules\Review::seconds( \Nino\Modules\Review::clock( 3723.0 ) ) === 3723.0 );
check( 'kind() calls a MIME type audio or video by its first part, and anything else nothing', \Nino\Modules\Review::kind( 'audio/mpeg' ) === 'audio' && \Nino\Modules\Review::kind( 'video/mp4' ) === 'video' && \Nino\Modules\Review::kind( 'application/zip' ) === ''
	&& \Nino\Modules\Review::kind( 'audiobook' ) === '' && \Nino\Modules\Review::kind( 'videos/x' ) === '' && \Nino\Modules\Review::kind( 'application/ogg' ) === '' && \Nino\Modules\Review::kind( 'image/png' ) === '' && \Nino\Modules\Review::kind( '' ) === '' && \Nino\Modules\Review::kind( 'xaudio/mpeg' ) === '' );

echo "\n";


// --- The people and the files ------------------------------------------------------

echo "The fixtures - three members, two groups, the files the owner put there\n";

// The workbench: the full-access account and one that may do nothing there. Both are made here, the panel's tests use them below
$appData['/nino/install/completed'] = true;
ninoUser( $appData, 'admin@example.com' );
ninoUser( $appData, 'plain@example.com', [], 'plain password' );
// The permission of this panel alone, with the one of the Files panel beside it, and the one of every panel from above: whether the panel may play a file is where they differ
ninoUser( $appData, 'reviewer@example.com', [ '/_admin/review/manage' ], 'reviewer password' );
ninoUser( $appData, 'both@example.com', [ '/_admin/review/manage', '/_admin/files/manage' ], 'both password' );
ninoUser( $appData, 'tools@example.com', [ '/_admin/*' ], 'tools password' );
reviewVisitor();

$ada = \Nino\Modules\Members::add( $appData, 'ada@example.org', 'Ada Lovelace', 'staff' );
$bob = \Nino\Modules\Members::add( $appData, 'bob@example.org', 'Bob', 'guests' );
$cyd = \Nino\Modules\Members::add( $appData, 'cyd@example.org', '', 'staff' );
$sessions = [];

foreach( [ 'ada' => $ada, 'bob' => $bob, 'cyd' => $cyd ] as $who => $account ) {
	reviewSignIn( $appData, $account );
	$sessions[$who] = $_SESSION;
}

$signedIn = [];

foreach( [ 'ada' => $ada, 'bob' => $bob, 'cyd' => $cyd ] as $who => $account ) {
	reviewAs( $who );
	$signedIn[$who] = ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

reviewVisitor();
check( 'the three members are signed in, each in a session of their own, and a visitor is nobody', array_filter( $signedIn ) === $signedIn && count( $signedIn ) === 3 && \Nino\Modules\Members::current( $appData ) === null );

reviewWorkbench( 'admin' );
$room = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => 'Room' ], 'files/folder/add' )[1]['folder'];
$lobby = ninoCall( $appData, $panel, [ 'group' => 'guests', 'name' => 'Lobby' ], 'files/folder/add' )[1]['folder'];
reviewVisitor();

$track = reviewUpload( $appData, $room['id'], 'Demo.mp3', $mp3 );
$clip = reviewUpload( $appData, $room['id'], 'Clip.mp4', $mp4 );
$pack = reviewUpload( $appData, $room['id'], 'Pack.zip', $zip );
$secret = reviewUpload( $appData, $room['id'], 'Secret.mp3', $mp3, false );
$guestTrack = reviewUpload( $appData, $lobby['id'], 'Guest.mp3', $mp3 );

check( 'the files are in the index with the type finfo read of their bytes: an mp3 is audio/mpeg, an mp4 video/mp4, a zip application/zip - and one of them is hidden from the members', ( $track['mime'] ?? '' ) === 'audio/mpeg'
	&& ( $clip['mime'] ?? '' ) === 'video/mp4' && ( $pack['mime'] ?? '' ) === 'application/zip' && ( $secret['visible'] ?? true ) === false && ( $guestTrack['folder'] ?? '' ) === $lobby['id'] );
ninoWarnings();

echo "\n";


// --- The data layer and the API ----------------------------------------------------

echo "Modules\\Review\\Store and the API - comments(), add() and media()\n";

check( 'a project with no comment lists none for any file, and reading them does not make the data file: it is not there yet', \Nino\Modules\Review::comments( $appData, $track['id'] ) === [] && \Nino\Modules\Review::comments( $appData, 'ffffffffffff' ) === []
	&& \Nino\Modules\Review::comments( $appData, '../../etc/passwd' ) === [] && reviewData( $appData ) === [] && is_file( \Nino\Filesystem::path( $appData, '/data/review.php' ) ) === false );

$owner = [ 'kind' => 'owner', 'id' => '' ];
$byAda = [ 'kind' => 'member', 'id' => $ada['id'] ];
$byCyd = [ 'kind' => 'member', 'id' => $cyd['id'] ];

$late = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 125.0, 'Late one' );
$early = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, 12.34, "First\r\nline\r\n\r\nsecond  " );
$plain = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'No time at all' );
$middle = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 65.0, 'In the middle', '', '/club/review?x=1#top' );
$stored = reviewData( $appData )[$track['id']] ?? [];

check( 'add() answers the comment with its id (12 hex characters), the file, the author, the second rounded to one decimal, the text, no parent, not done, the time of writing and the page without its query',
	is_array( $early ) && preg_match( '/^[a-f0-9]{12}$/D', $early['id'] ) === 1 && $early['file'] === $track['id'] && $early['by'] === $byCyd && $early['at'] === 12.3 && $early['parent'] === '' && $early['done'] === false
	&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $early['created'] ) === 1 && $early['page'] === '' && is_array( $middle ) && $middle['page'] === '/club/review' );
check( '...and it is in the data file after a fresh read, exactly with the keys of the plan, the text as text with its line breaks (the carriage returns gone) and the owner without an id',
	count( $stored ) === 4 && array_keys( $stored[$early['id']] ) === [ 'by', 'at', 'text', 'parent', 'done', 'created', 'page' ] && $stored[$early['id']]['text'] === "First\nline\n\nsecond" && $stored[$plain['id']]['by'] === $owner
	&& $stored[$plain['id']]['at'] === null && $stored[$early['id']]['at'] === 12.3 );

$answerOne = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'An answer', $middle['id'] );
$answerTwo = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 70.0, 'An answer to the answer', $answerOne['id'] );
$answerThree = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, null, 'Another answer', $middle['id'] );
check( 'an answer names its parent; an answer to an answer is an answer to the comment that one answers - one level, whatever the time of either',
	$answerOne['parent'] === $middle['id'] && $answerTwo['parent'] === $middle['id'] && $answerThree['parent'] === $middle['id'] && $answerTwo['at'] === 70.0 && reviewData( $appData )[$track['id']][$answerTwo['id']]['parent'] === $middle['id'] );

$list = \Nino\Modules\Review::comments( $appData, $track['id'] );
check( 'comments() orders the track: the ones with a time by that time, then the ones without in the order they were written - 12.3, 65, 125, then the one without',
	array_column( $list, 'id' ) === [ $early['id'], $middle['id'], $late['id'], $plain['id'] ] && array_column( $list, 'at' ) === [ 12.3, 65.0, 125.0, null ] );
check( '...with the answers under their parent, in the order they were written, and each comment with the keys of the plan and an id', array_column( $list[1]['answers'], 'id' ) === [ $answerOne['id'], $answerTwo['id'], $answerThree['id'] ]
	&& $list[0]['answers'] === [] && array_keys( $list[0] ) === [ 'id', 'by', 'at', 'text', 'parent', 'done', 'created', 'page', 'answers' ] && array_keys( $list[1]['answers'][0] ) === [ 'id', 'by', 'at', 'text', 'parent', 'done', 'created', 'page' ]
	&& array_filter( $list, static fn( array $comment ): bool => $comment['parent'] !== '' ) === [] );

// The order of the file is the order of writing, but the time of writing is what decides: a file edited by hand, or restored, can list them otherwise
$stamp = static fn( string $text, ?float $at, string $parent, string $created ): array => [ 'by' => $owner, 'at' => $at, 'text' => $text, 'parent' => $parent, 'done' => false, 'created' => $created, 'page' => '' ];
$jumbled = \Nino\Modules\Review\Store::parse( [ $guestTrack['id'] => [ 'aaaaaaaaaaa1' => $stamp( 'Second answer', null, 'aaaaaaaaaaa3', '2026-02-02T00:00:00Z' ), 'aaaaaaaaaaa2' => $stamp( 'Later', 9.0, '', '2026-02-02T00:00:00Z' ),
	'aaaaaaaaaaa3' => $stamp( 'Parent', 9.0, '', '2026-01-01T00:00:00Z' ), 'aaaaaaaaaaa4' => $stamp( 'First answer', null, 'aaaaaaaaaaa3', '2026-01-02T00:00:00Z' ), 'aaaaaaaaaaa5' => $stamp( 'Untimed, early', null, '', '2026-01-01T00:00:00Z' ),
	'aaaaaaaaaaa6' => $stamp( 'Untimed, late', null, '', '2026-03-01T00:00:00Z' ) ] ] );
\Nino\Filesystem::putFileContent( $appData, '/data/review.php', $jumbled + reviewData( $appData ) );
$ordered = \Nino\Modules\Review::comments( $appData, $guestTrack['id'] );
check( 'comments of the same time stand in the order of the time they were written, the ones without a time too, and the answers under a comment likewise - whatever order the file lists them in',
	array_column( $ordered, 'text' ) === [ 'Parent', 'Later', 'Untimed, early', 'Untimed, late' ] && array_column( $ordered[0]['answers'], 'text' ) === [ 'First answer', 'Second answer' ] );
\Nino\Modules\Review\Store::purge( $appData, $guestTrack['id'] );

$sameTime = [ \Nino\Modules\Review::add( $appData, $clip['id'], $owner, 5.0, 'One' ), \Nino\Modules\Review::add( $appData, $clip['id'], $owner, 5.0, 'Two' ), \Nino\Modules\Review::add( $appData, $clip['id'], $owner, 5.0, 'Three' ) ];
check( 'comments of the same time keep the order they were written in', array_column( \Nino\Modules\Review::comments( $appData, $clip['id'] ), 'text' ) === [ 'One', 'Two', 'Three' ] );
check( 'a comment is on its own file: the comments of one file are not the comments of another', array_column( \Nino\Modules\Review::comments( $appData, $clip['id'] ), 'id' ) === array_column( $sameTime, 'id' ) && \Nino\Modules\Review::comments( $appData, $guestTrack['id'] ) === [] );

// counts(): the comments of files and the open ones, read once - a member's comment that is not done is open, the owner's is never
$ownerComments = array_filter( array_column( \Nino\Modules\Review::comments( $appData, $track['id'] ), null, 'id' ), static fn( array $comment ): bool => $comment['by']['kind'] === 'owner' );
check( 'counts() gives each file its comments and the open ones: the comments of the owner count as comments and are never open',
	\Nino\Modules\Review::counts( $appData, [ $track['id'], $clip['id'] ] ) === [ $track['id'] => [ 'total' => 7, 'open' => 5 ], $clip['id'] => [ 'total' => 3, 'open' => 0 ] ] && $ownerComments !== [] );
\Nino\Modules\Review\Store::toggle( $appData, $track['id'], $early['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $track['id'], $plain['id'] );
check( '...a member\'s comment marked as done is not open any more, and the owner\'s marked as done changes nothing; marked open again it is counted again',
	\Nino\Modules\Review::counts( $appData, [ $track['id'] ] ) === [ $track['id'] => [ 'total' => 7, 'open' => 4 ] ] );
\Nino\Modules\Review\Store::toggle( $appData, $track['id'], $early['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $track['id'], $plain['id'] );
check( '...and the toggles back leave the numbers where they were',
	\Nino\Modules\Review::counts( $appData, [ $track['id'] ] )[$track['id']] === [ 'total' => 7, 'open' => 5 ] );
check( '...a file without comments and an id that is none have zeros - nothing is looked up that is not asked - and what is no string is left out',
	\Nino\Modules\Review::counts( $appData, [ 'ffffffffffff', '../../etc/passwd', 5, null ] ) === [ 'ffffffffffff' => [ 'total' => 0, 'open' => 0 ], '../../etc/passwd' => [ 'total' => 0, 'open' => 0 ] ] && \Nino\Modules\Review::counts( $appData, [] ) === [] );

$refused = [
	'a file nobody has'			=> \Nino\Modules\Review::add( $appData, 'ffffffffffff', $owner, null, 'x' ),
	'something that is no id'	=> \Nino\Modules\Review::add( $appData, '../x', $owner, null, 'x' ),
	'a file that is a zip'		=> \Nino\Modules\Review::add( $appData, $pack['id'], $owner, null, 'x' ),
	'an author that is none'	=> \Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'robot', 'id' => '' ], null, 'x' ),
	'a member without an id'	=> \Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => '../x' ], null, 'x' ),
	'an empty text'				=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, "  \n\t " ),
	'a text of control characters' => \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, "\x00\x07\x1b" ),
	'a text over the length'	=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, str_repeat( 'x', 2001 ) ),
	'a negative time'			=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, -1.0, 'x' ),
	'a time past 99:59:59'		=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, 360000.0, 'x' ),
	'a time that is not a number' => \Nino\Modules\Review::add( $appData, $track['id'], $owner, NAN, 'x' ),
	'a parent nobody has'		=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'x', 'ffffffffffff' ),
	'a parent of another file'	=> \Nino\Modules\Review::add( $appData, $clip['id'], $owner, null, 'x', $middle['id'] ),
	'a parent that is none'		=> \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'x', '../x' ),
];
check( 'add() refuses with the reason: file for one nobody has, for one that is no id and for a zip; by for an author that is none; empty; long; time; parent - and writes nothing', array_map( static fn( mixed $answer ): string => (string) $answer, $refused ) === [
	'a file nobody has' => 'file', 'something that is no id' => 'file', 'a file that is a zip' => 'file', 'an author that is none' => 'by', 'a member without an id' => 'by', 'an empty text' => 'empty',
	'a text of control characters' => 'empty', 'a text over the length' => 'long', 'a negative time' => 'time', 'a time past 99:59:59' => 'time', 'a time that is not a number' => 'time', 'a parent nobody has' => 'parent',
	'a parent of another file' => 'parent', 'a parent that is none' => 'parent' ] && count( reviewData( $appData )[$track['id']] ?? [] ) === 7 && count( reviewData( $appData ) ) === 2 );

$edge = \Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, 0.0, str_repeat( 'ü', 2000 ) );
$over = \Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, null, str_repeat( 'ü', 2001 ) );
check( 'the text may be as long as the setting says - 2000 characters, counted as characters and not as bytes - and a time of 0 is a time', is_array( $edge ) && $edge['at'] === 0.0 && mb_strlen( $edge['text'] ) === 2000 && $over === 'long' );
\Nino\Features::saveSettings( $appData, 'review', [ 'length' => 100 ] );
$short = [ \Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, null, str_repeat( 'x', 100 ) ), \Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, null, str_repeat( 'x', 101 ) ) ];
\Nino\Features::saveSettings( $appData, 'review', [ 'length' => 2000 ] );
check( '...and the setting length is what it is held to, for the owner as for a member', is_array( $short[0] ) && $short[1] === 'long' && \Nino\Modules\Review::length( $appData ) === 2000 );

$hostile = \Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, null, "Bad \xC3\x28 bytes <b>and</b> [[/project/company/general/name]] [review-player]" );
check( 'a text with a byte that is no UTF-8 is cleaned of it, and one with markup or a key in it is kept as the text it is', is_array( $hostile ) && str_contains( $hostile['text'], "\xC3\x28" ) === false && mb_check_encoding( $hostile['text'], 'UTF-8' ) === true
	&& str_contains( $hostile['text'], '<b>and</b> [[/project/company/general/name]] [review-player]' ) === true );

// A write that cannot be made is the server's failure: add() says write, and the file is what it was
$dataPath = \Nino\Filesystem::path( $appData, '/data/review.php' );
$dataBytes = (string) file_get_contents( $dataPath );
unlink( $dataPath );
mkdir( $dataPath );
ninoErrors( static function() use ( &$appData, &$unwritten, $track, $owner ): void {
	$unwritten = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'Cannot be written' );
} );
rmdir( $dataPath );
file_put_contents( $dataPath, $dataBytes );
check( 'a write that cannot be made is "write", and nothing of it is in the file after', $unwritten === 'write' && file_get_contents( $dataPath ) === $dataBytes );

// Two writers at once: each change is one lock, so neither is lost
$beforeTwice = reviewData( $appData );
$twice = [ \Nino\Modules\Review::add( $appData, $clip['id'], $byAda, 9.0, 'Writer one' ), \Nino\Modules\Review::add( $appData, $clip['id'], $byCyd, 9.0, 'Writer two' ), \Nino\Modules\Review::add( $appData, $guestTrack['id'], $byCyd, 9.0, 'Writer three' ) ];
$afterTwice = reviewData( $appData );
\Nino\Modules\Review\Store::purge( $appData, $guestTrack['id'] );
check( 'comments written one after the other are all in the file, two on one file and one on another: a write keeps what the others wrote', is_array( $twice[0] ) && is_array( $twice[1] ) && is_array( $twice[2] )
	&& isset( $afterTwice[$clip['id']][ $twice[0]['id'] ], $afterTwice[$clip['id']][ $twice[1]['id'] ], $afterTwice[$guestTrack['id']][ $twice[2]['id'] ] ) === true && $afterTwice[$track['id']] === $beforeTwice[$track['id']] );

// The file is plain php anybody can edit: what is not a comment is left out when it is read
$edited = [ $track['id'] => [
	'aaaaaaaaaaaa' => [ 'by' => [ 'kind' => 'owner', 'id' => 'whatever' ], 'at' => 5, 'text' => 'Fine', 'parent' => '', 'done' => 'yes', 'created' => 7, 'page' => [] ],
	'bbbbbbbbbbbb' => [ 'by' => [ 'kind' => 'member', 'id' => '../x' ], 'at' => 5, 'text' => 'Bad member' ],
	'cccccccccccc' => [ 'by' => [ 'kind' => 'robot', 'id' => '' ], 'at' => 5, 'text' => 'Bad kind' ],
	'dddddddddddd' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => 5, 'text' => '   ' ],
	'eeeeeeeeeeee' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => -3, 'text' => 'Negative time' ],
	'ffffffffffff' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => '12', 'text' => 'Time as a string', 'parent' => 'aaaaaaaaaaaa' ],
	'111111111111' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Answer to nobody', 'parent' => '999999999999' ],
	'222222222222' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Answer to an answer', 'parent' => 'ffffffffffff' ],
	'bad' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Bad id' ],
	'333333333333' => 'not a record',
	'a4a4a4a4a4a4' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => 12.34, 'text' => 'Rounded', 'parent' => 'not an id' ],
], $clip['id'] => [ 'bad' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Nothing valid in this file' ] ], 'not-an-id' => [ 'aaaaaaaaaaaa' => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Bad file' ] ], 'eeeeeeeeeeee' => 'not a list' ];
$read = reviewTry( static fn(): array => \Nino\Modules\Review\Store::parse( $edited ) );
check( 'a data file that was edited by hand is read for what it has to be: a bad id, a bad author, an empty text, an answer to nobody, an answer to an answer and a file with no valid comment are left out - and a time that is none, a flag that is no bool, a time of writing that is no string and a parent that is no id are the empty ones, a time is rounded to a decimal',
	is_array( $read ) === true && array_map( 'strval', array_keys( $read ) ) === [ $track['id'] ] && array_keys( $read[$track['id']] ) === [ 'aaaaaaaaaaaa', 'eeeeeeeeeeee', 'ffffffffffff', 'a4a4a4a4a4a4' ] && $read[$track['id']]['a4a4a4a4a4a4']['at'] === 12.3 && $read[$track['id']]['a4a4a4a4a4a4']['parent'] === '' && $read[$track['id']]['aaaaaaaaaaaa'] === [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => 5.0, 'text' => 'Fine', 'parent' => '', 'done' => false, 'created' => '', 'page' => '' ]
	&& $read[$track['id']]['eeeeeeeeeeee']['at'] === null && $read[$track['id']]['ffffffffffff']['at'] === null && $read[$track['id']]['ffffffffffff']['parent'] === 'aaaaaaaaaaaa'
	&& \Nino\Modules\Review\Store::parse( 'nonsense' ) === [] && \Nino\Modules\Review\Store::parse( null ) === [] );

// An id of twelve digits is a whole number as a php array key: everything that walks the data has to read it back as the id it is
$digitFile = '345678901234';
$digitComment = '456789012345';
$digitAnswer = '567890123456';
$digits = reviewTry( static fn(): array => \Nino\Modules\Review\Store::parse( [ $digitFile => [
	$digitComment => [ 'by' => [ 'kind' => 'member', 'id' => '123456789012' ], 'at' => 1.5, 'text' => 'Digits' ],
	$digitAnswer => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'text' => 'Answer to digits', 'parent' => $digitComment ],
] ] ) );
check( 'an id of twelve digits, which php makes a whole number of as an array key, is read back as the id it is: a comment, its answer and the author', is_array( $digits ) === true && array_map( 'strval', array_keys( $digits ) ) === [ $digitFile ]
	&& array_map( 'strval', array_keys( $digits[$digitFile] ) ) === [ $digitComment, $digitAnswer ] && $digits[$digitFile][$digitAnswer]['parent'] === $digitComment && $digits[$digitFile][$digitComment]['by']['id'] === '123456789012' );

// media(): the audio and video files of the member who is signed in
$names = static fn( array $files ): array => array_column( $files, 'name' );
reviewAs( 'ada' );
$adas = \Nino\Modules\Review::media( $appData );
reviewAs( 'bob' );
$bobs = \Nino\Modules\Review::media( $appData );
reviewAs( '' );
$visitors = \Nino\Modules\Review::media( $appData );
$kinds = array_column( $adas, 'kind', 'name' );
ksort( $kinds );
check( 'media() lists the audio and video files of the folders of the member\'s group that Files lets them see - not the zip, not the hidden file, not the files of another group - each with its kind and the name of its folder',
	count( $adas ) === 2 && $kinds === [ 'Clip.mp4' => 'video', 'Demo.mp3' => 'audio' ] && array_unique( array_column( $adas, 'folderName' ) ) === [ 'Room' ] && $names( $bobs ) === [ 'Guest.mp3' ] && $visitors === [] );
check( '...the entries are those of Files, with their id, their type and their folder', $adas[0]['id'] !== '' && array_diff_key( \Nino\Modules\Files::file( $appData, $adas[0]['id'] ) ?? [], $adas[0] ) === [] && $adas[0]['mime'] !== '' && $adas[0]['folder'] === $room['id'] );

// A member without a group has no folders, and Files lets them see nothing
\Nino\Modules\Members::change( $appData, $cyd['id'], [ 'group' => '' ] );
reviewAs( 'cyd' );
$loner = \Nino\Modules\Review::media( $appData );
\Nino\Modules\Members::change( $appData, $cyd['id'], [ 'group' => 'staff' ] );
check( 'a member without a group has no files to review', $loner === [] );

echo "\n";


// --- The route ---------------------------------------------------------------------

echo "POST /.review/comment - a member's comment, its states and its caps\n";

/**
 *	A comment posted the way the form posts it
 *
 *	@param		string		$who					ada, bob, cyd or '' for a visitor
 *	@param		array 		$fields				What the post carries besides the defaults
 *	@param		bool			$csrf					Whether the post carries the token
 *
 *	@return 	array										[ status, Location, body, request ]
 */
function reviewPost( string $who, array $fields, bool $csrf = true ): array {

	global $appData;

	reviewAs( $who );
	$request = reviewServe( $appData, '/.review/comment', 'POST', $fields + [ 'return' => '/club/review', 'at' => '', 'text' => 'A comment', 'parent' => '' ], $csrf );
	[ $status, $location, , $body ] = reviewAnswer( $request );

	return [ $status, $location, $body, $request ];
}

reviewFresh( $appData );
$told = [];
$before = reviewData( $appData );
$count = count( $before[$track['id']] );
$sent = reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '1:05', 'text' => "Tighten the snare\r\nat the second bar" ] );
$after = reviewData( $appData );
$new = array_values( array_diff_key( $after[$track['id']], $before[$track['id']] ) )[0] ?? [];
$newId = (string) array_key_last( $after[$track['id']] );

check( 'a member\'s comment is stored and answered with the redirect (303) to the page the form was on, with the file and the state sent', $sent[0] === 303 && $sent[1] === '/club/review?review='. $track['id']. '&review-state=sent' && $sent[2] === ''
	&& count( $after[$track['id']] ) === $count + 1 );
check( '...with the account that wrote it, the second 65.0, no parent, not done, the text as text and the page - and nobody else\'s comment changed', ( $new['by'] ?? null ) === $byAda && ( $new['at'] ?? null ) === 65.0 && ( $new['parent'] ?? null ) === ''
	&& ( $new['done'] ?? null ) === false && ( $new['text'] ?? null ) === "Tighten the snare\nat the second bar" && ( $new['page'] ?? null ) === '/club/review' && array_diff_key( $before[$track['id']], $after[$track['id']] ) === []
	&& array_filter( $before[$track['id']], static fn( array $comment, string|int $id ): bool => $GLOBALS['after'][$GLOBALS['track']['id']][$id] !== $comment, ARRAY_FILTER_USE_BOTH ) === [] && $after[$clip['id']] === $before[$clip['id']] );
check( '...and the callback /review/comment is told once, with the entry: the id, the file and what is stored', count( $told ) === 1 && $told[0]['id'] === $newId && $told[0]['file'] === $track['id'] && $told[0]['by'] === $byAda && $told[0]['at'] === 65.0 && $told[0]['text'] === $new['text'] && $told[0]['page'] === '/club/review' );

$answered = reviewPost( 'cyd', [ 'file' => $track['id'], 'text' => 'Agreed', 'parent' => $newId ] );
$answerEntry = array_values( array_diff_key( reviewData( $appData )[$track['id']], $after[$track['id']] ) )[0] ?? [];
$deep = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Thanks', 'parent' => (string) array_key_last( reviewData( $appData )[$track['id']] ) ] );
$deepEntry = array_values( array_diff_key( reviewData( $appData )[$track['id']], $after[$track['id']] ) )[1] ?? [];
check( 'an answer is posted with the parent, has no time unless one is typed, and an answer to an answer is an answer to the comment that one answers', $answered[0] === 303 && ( $answerEntry['parent'] ?? '' ) === $newId && array_key_exists( 'at', $answerEntry ) === true && $answerEntry['at'] === null
	&& ( $answerEntry['by'] ?? null ) === $byCyd && $deep[0] === 303 && ( $deepEntry['parent'] ?? '' ) === $newId && $deepEntry['by'] === $byAda );

$noTime = reviewPost( 'ada', [ 'file' => $track['id'], 'at' => ' ', 'text' => 'Anywhere' ] );
$decimal = reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '0:03,5', 'text' => 'Decimal comma' ] );
$hours = reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '1:02:03', 'text' => 'Late' ] );
$tenths = [ reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '0:02.5', 'text' => 'A tenth' ] ), reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '1:05.7', 'text' => 'A tenth later' ] ) ];
$stored = array_column( reviewData( $appData )[$track['id']], null, 'text' );
check( 'the time is empty, m:ss, a number or h:mm:ss: a comment without a time has null, 0:03,5 is 3.5 and 1:02:03 is 3723.0', [ $noTime[0], $decimal[0], $hours[0] ] === [ 303, 303, 303 ] && $stored['Anywhere']['at'] === null && $stored['Decimal comma']['at'] === 3.5 && $stored['Late']['at'] === 3723.0 );
check( '...and a time as the script writes the position of the player - m:ss and the tenth after a point - keeps its tenth: 0:02.5 is 2.5, 1:05.7 is 65.7', [ $tenths[0][0], $tenths[1][0] ] === [ 303, 303 ] && $stored['A tenth']['at'] === 2.5 && $stored['A tenth later']['at'] === 65.7 );
check( '...the callback is told of each of the eight comments of this section, and never of a refusal', count( $told ) === 8 );

// The states: what is refused is a redirect with a word, and nothing is stored
$told = [];
$stateBefore = reviewData( $appData );
$refusals = [
	'a zip'						=> reviewPost( 'ada', [ 'file' => $pack['id'] ] ),
	'an empty text'				=> reviewPost( 'ada', [ 'file' => $track['id'], 'text' => " \n " ] ),
	'a missing text'			=> reviewPost( 'ada', [ 'file' => $track['id'], 'text' => [ 'x' ] ] ),
	'a time that is none'		=> reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '1m05' ] ),
	'a negative time'			=> reviewPost( 'ada', [ 'file' => $track['id'], 'at' => '-5' ] ),
	'a parent nobody has'		=> reviewPost( 'ada', [ 'file' => $track['id'], 'parent' => 'ffffffffffff' ] ),
	'a parent that is none'		=> reviewPost( 'ada', [ 'file' => $track['id'], 'parent' => '../x' ] ),
	'a parent of another file' => reviewPost( 'ada', [ 'file' => $track['id'], 'parent' => (string) array_key_first( $stateBefore[$clip['id']] ) ] ),
	'a text over the length'	=> reviewPost( 'ada', [ 'file' => $track['id'], 'text' => str_repeat( 'x', 2001 ) ] ),
];
$locations = array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1], $refusals );
check( 'a zip, an empty text, a text that is a list, a time that is typed and is none, a negative one, a parent nobody has, one that is none and one of another file are refused as the state invalid',
	array_diff_key( $locations, [ 'a text over the length' => 1 ] ) === [
		'a zip' => '303 /club/review?review='. $pack['id']. '&review-state=invalid', 'an empty text' => '303 /club/review?review='. $track['id']. '&review-state=invalid', 'a missing text' => '303 /club/review?review='. $track['id']. '&review-state=invalid',
		'a time that is none' => '303 /club/review?review='. $track['id']. '&review-state=invalid', 'a negative time' => '303 /club/review?review='. $track['id']. '&review-state=invalid',
		'a parent nobody has' => '303 /club/review?review='. $track['id']. '&review-state=invalid', 'a parent that is none' => '303 /club/review?review='. $track['id']. '&review-state=invalid', 'a parent of another file' => '303 /club/review?review='. $track['id']. '&review-state=invalid' ] );
check( '...a text over the setting length is the state long - and none of them stored anything or told anybody', $locations['a text over the length'] === '303 /club/review?review='. $track['id']. '&review-state=long' && reviewData( $appData ) === $stateBefore && $told === [] );

// The endpoint takes a post and nothing else: the address read with a GET is the site's 404, whoever asks, and stores nothing
$dataBefore = reviewData( $appData );
reviewAs( 'ada' );
$get = reviewServe( $appData, '/.review/comment?file='. $track['id']. '&text=Hello' );
reviewAs( '' );
$getVisitor = reviewServe( $appData, '/.review/comment' );
$nowhere = reviewServe( $appData, '/.review/nowhere' );
check( 'the endpoint answers a post only: a GET of its address is the 404 of the site - the page a path nobody has gets, not the plain body of the endpoint -, for a member and for a visitor, and stores nothing', $get['/nino/http/response']['statusCode'] === 404 && $getVisitor['/nino/http/response']['statusCode'] === 404
	&& $nowhere['/nino/http/response']['statusCode'] === 404 && $get['/nino/http/response']['body'] === $nowhere['/nino/http/response']['body'] && $getVisitor['/nino/http/response']['body'] === $nowhere['/nino/http/response']['body']
	&& $get['/nino/http/response']['header']['Content-Type'] === $nowhere['/nino/http/response']['header']['Content-Type'] && reviewData( $appData ) === $dataBefore );

// A time that is a list is no time that was typed: it is read as nothing, as every field of a post is that is no string
$listTime = reviewPost( 'ada', [ 'file' => $track['id'], 'at' => [ '1:05' ], 'text' => 'A list for a time' ] );
$stateBefore = reviewData( $appData );
check( 'a time that is a list is read as no time at all, as is every field of a post that is no string: the comment is stored without one', $listTime[1] === '/club/review?review='. $track['id']. '&review-state=sent'
	&& array_column( $stateBefore[$track['id']], 'at', 'text' )['A list for a time'] === null && count( $told ) === 1 );
$told = [];

// Who may: a visitor, a member who may not see the file, an id nobody has - all the same 404
$stranger = [ 'a visitor' => reviewPost( '', [ 'file' => $track['id'] ] ), 'a member of another group' => reviewPost( 'bob', [ 'file' => $track['id'] ] ), 'the hidden file of the owner' => reviewPost( 'ada', [ 'file' => $secret['id'] ] ),
	'an id nobody has' => reviewPost( 'ada', [ 'file' => 'ffffffffffff' ] ), 'something that is no id' => reviewPost( 'ada', [ 'file' => '../../etc/passwd' ] ), 'a file that is a list' => reviewPost( 'ada', [ 'file' => [ $track['id'] ] ] ),
	'no file at all' => reviewPost( 'ada', [] ), 'a visitor at a zip' => reviewPost( '', [ 'file' => $pack['id'] ] ) ];
$sameAs = $stranger['a visitor'];
$same = array_filter( $stranger, static fn( array $answer ): bool => $answer[0] === $GLOBALS['sameAs'][0] && $answer[2] === $GLOBALS['sameAs'][2] && $answer[1] === '' && $answer[3]['/nino/http/response']['header']['Content-Type'] === 'text/plain; charset=utf-8'
	&& $answer[3]['/nino/http/response']['header']['Cache-Control'] === 'no-store' );
check( 'a visitor, a member of another group, the hidden file of the owner, an id nobody has, something that is no id, a list for an id and no file at all are all the same 404 with a plain body - nothing tells that an id exists, and nothing is stored',
	$sameAs[0] === 404 && $sameAs[2] === 'Not Found' && count( $same ) === count( $stranger ) && reviewData( $appData ) === $stateBefore && $told === [] );

// The owner at the workbench is no member
$told = [];
check( 'the owner\'s workbench session is no member: a post from it is the 404 of a visitor, whatever permission it holds', ( function() use ( $track ): bool {
	reviewWorkbench( 'admin' );
	$request = reviewServe( $GLOBALS['appData'], '/.review/comment', 'POST', [ 'file' => $track['id'], 'text' => 'From the workbench', 'return' => '/club/review' ] );
	reviewVisitor();
	return reviewAnswer( $request )[0] === 404;
} )() && reviewData( $appData ) === $stateBefore );

// The page the answer goes back to is a path of this site and no endpoint of a module - the start page of the Members feature for anything else
$told = [];
$returns = [];
foreach( [ '//evil.example/x', '/\\evil.example', 'https://evil.example', '/.members/login', '/a b', '', 'relative/path', '/a/b:c', '/javascript:alert(1)', "/x\r\nSet-Cookie: a=b", '/club/review?a=b#c', '/' ] as $return )
	$returns[$return] = reviewPost( 'ada', [ 'file' => $track['id'], 'return' => $return, 'text' => 'Return '. md5( $return ) ] )[1];
check( 'the page it goes back to is a path of this site without its query and no endpoint of a module - the start page for anything else; the page kept with the comment is the same path, or none',
	$returns === [ '//evil.example/x' => '/?review='. $track['id']. '&review-state=sent', '/\\evil.example' => '/?review='. $track['id']. '&review-state=sent', 'https://evil.example' => '/?review='. $track['id']. '&review-state=sent',
	'/.members/login' => '/?review='. $track['id']. '&review-state=sent', '/a b' => '/?review='. $track['id']. '&review-state=sent', '' => '/?review='. $track['id']. '&review-state=sent', 'relative/path' => '/?review='. $track['id']. '&review-state=sent',
	'/a/b:c' => '/a/b:c?review='. $track['id']. '&review-state=sent', '/javascript:alert(1)' => '/?review='. $track['id']. '&review-state=sent', "/x\r\nSet-Cookie: a=b" => '/?review='. $track['id']. '&review-state=sent',
	'/club/review?a=b#c' => '/club/review?review='. $track['id']. '&review-state=sent', '/' => '/?review='. $track['id']. '&review-state=sent' ] );
$pages = array_column( reviewData( $appData )[$track['id']], 'page', 'text' );
check( '...and a comment kept the path it came from only where it was one', $pages['Return '. md5( '//evil.example/x' )] === '' && $pages['Return '. md5( '/a/b:c' )] === '/a/b:c' && $pages['Return '. md5( '/club/review?a=b#c' )] === '/club/review' && $pages['Return '. md5( '/' )] === '/' );

\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/members-area' ] );
$start = reviewPost( 'ada', [ 'file' => 'not an id', 'return' => 'nonsense', 'text' => 'To the start' ] );
$startOk = reviewPost( 'ada', [ 'file' => $track['id'], 'return' => 'nonsense', 'text' => 'To the start page' ] );
\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/' ] );
check( 'a post that names no page goes back to the start page of the Members feature, with the file in the query only where it is the shape of an id', $start[0] === 404 && $startOk[1] === '/members-area?review='. $track['id']. '&review-state=sent' );

$appData['/nino/dir'] = '/sub';
$underDirectory = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Under a directory' ] )[1];
$appData['/nino/dir'] = '';
check( 'the redirect starts with the directory the project is served from', $underDirectory === '/sub/club/review?review='. $track['id']. '&review-state=sent' );

// A write that cannot be made is the server's failure: a 500 and no redirect, and nothing in the file
$dataPath = \Nino\Filesystem::path( $appData, '/data/review.php' );
$dataBytes = (string) file_get_contents( $dataPath );
reviewFresh( $appData );
$told = [];
unlink( $dataPath );
mkdir( $dataPath );
$unwritten = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Cannot be written' ] );
rmdir( $dataPath );
file_put_contents( $dataPath, $dataBytes );
check( 'a comment that cannot be written is a 500 with no redirect, and is not told to anybody', $unwritten[0] === 500 && $unwritten[1] === '' && file_get_contents( $dataPath ) === $dataBytes && $told === [] );

// The csrf token
reviewFresh( $appData );
$told = [];
$counterBefore = \Nino\Filesystem::getFileContent( $appData, '/data/review-tries.php', [] );
$dataBefore = reviewData( $appData );
$noToken = reviewPost( 'ada', [ 'file' => $track['id'] ], false );
$wrongToken = reviewPost( 'ada', [ 'file' => $track['id'], '_csrf' => 'not-the-token' ], false );
$noTokenVisitor = reviewPost( '', [ 'file' => $track['id'] ], false );
check( 'a post without the csrf token - or with another - is the redirect with the state expired, to the page the form was on, and counts for nothing, tells nobody and writes nothing', $noToken[1] === '/club/review?review='. $track['id']. '&review-state=expired'
	&& $wrongToken[1] === $noToken[1] && $noToken[0] === 303 && $wrongToken[0] === 303 && $noTokenVisitor[1] === $noToken[1] && \Nino\Filesystem::getFileContent( $appData, '/data/review-tries.php', [] ) === $counterBefore
	&& reviewData( $appData ) === $dataBefore && $told === [] );

// The file the post names is in the address of a refusal only where it is the shape of an id - the refusals decided before it is looked at, the csrf and the cap, give no text of the post back
$garbage = [ "x\r\nSet-Cookie: a=b", '../x', str_repeat( 'f', 13 ), '' ];
$expiredGarbage = array_map( static fn( string $file ): string => reviewPost( 'ada', [ 'file' => $file ], false )[1], $garbage );
\Nino\Features::saveSettings( $appData, 'review', [ 'attempts' => 1 ] );
reviewFresh( $appData );
reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Uses the one try' ] );
$limitGarbage = array_map( static fn( string $file ): string => reviewPost( 'ada', [ 'file' => $file ] )[1], $garbage );
\Nino\Features::saveSettings( $appData, 'review', [ 'attempts' => 60 ] );
reviewFresh( $appData );
check( 'the file a post names is in the address of a refusal only where it has the shape of an id: the csrf and the cap decide before it is looked at, and give no text of the post back', array_unique( $expiredGarbage ) === [ '/club/review?review-state=expired' ]
	&& array_unique( $limitGarbage ) === [ '/club/review?review-state=limit' ] );

// The cap: comments per visitor and hour
\Nino\Features::saveSettings( $appData, 'review', [ 'attempts' => 3 ] );
reviewFresh( $appData );
$statuses = [];
for( $try = 1; $try <= 5; $try++ )
	$statuses[] = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Cap '. $try ] )[1];
check( 'the fourth post of a visitor in an hour - the setting attempts says three - is the state limit, a member counted like the rest', array_map( static fn( string $location ): string => substr( $location, (int) strrpos( $location, '=' ) + 1 ), $statuses ) === [ 'sent', 'sent', 'sent', 'limit', 'limit' ] );
reviewFresh( $appData );
$visitors = [];
for( $try = 1; $try <= 5; $try++ )
	$visitors[] = reviewPost( '', [ 'file' => $track['id'] ] )[0] === 404 ? '404' : 'other';
$limited = reviewPost( '', [ 'file' => $track['id'] ] );
check( 'a visitor who is no member is counted as well: the refusals of the 404 use the same hour, and over the cap the answer is the state limit and not the 404', $visitors === [ '404', '404', '404', 'other', 'other' ] && $limited[1] === '/club/review?review='. $track['id']. '&review-state=limit' );

reviewFresh( $appData );
$across = [];
foreach( [ $track['id'], $clip['id'], $track['id'], $clip['id'] ] as $each )
	$across[] = substr( ( $line = reviewPost( 'ada', [ 'file' => $each, 'text' => 'Across' ] )[1] ), (int) strrpos( $line, '=' ) + 1 );
check( 'the cap is the visitor\'s and not the file\'s: three comments on two files in an hour are three tries, and the fourth is the state limit on either', $across === [ 'sent', 'sent', 'sent', 'limit' ] );

\Nino\Features::saveSettings( $appData, 'review', [ 'attempts' => 60 ] );
reviewFresh( $appData );
$results = [];
for( $try = 1; $try <= 61; $try++ )
	$results[] = substr( ( $line = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Many '. $try ] )[1] ), (int) strrpos( $line, '=' ) + 1 );
$tries = \Nino\Filesystem::getFileContent( $appData, '/data/review-tries.php', [] );
check( 'with the default of 60 the 61st comment of a visitor in an hour is the state limit, and the 60 before it were stored', count( array_unique( array_slice( $results, 0, 60 ) ) ) === 1 && $results[59] === 'sent' && $results[60] === 'limit'
	&& count( array_filter( reviewData( $appData )[$track['id']], static fn( array $comment ): bool => str_starts_with( $comment['text'], 'Many ' ) ) ) === 60 );
check( '...the counter has a file of its own - not the comments - hashed: neither the address of the visitor nor a name is written in it, and the comments are what they were', is_file( \Nino\Filesystem::path( $appData, '/data/review-tries.php' ) ) === true
	&& count( $tries ) === 1 && str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/review-tries.php' ) ), '127.0.0.1' ) === false && count( reviewData( $appData )[$track['id']] ) > 60 );
reviewFresh( $appData );

// A hostile post: lists where strings belong are no warning, a fatal or a comment
ninoWarnings();
$told = [];
$dataBefore = reviewData( $appData );
$lists = reviewPost( 'ada', [ 'file' => [ 'a' ], 'text' => [ 'b' ], 'at' => [ 'c' ], 'parent' => [ 'd' ], 'return' => [ 'e' ] ] );
check( 'a post whose fields are lists is a refusal like any other, not a warning or an error - and stores nothing', $lists[0] === 404 && ninoWarnings() === [] && reviewData( $appData ) === $dataBefore && $told === [] );

reviewVisitor();
echo "\n";


// --- The component -----------------------------------------------------------------

echo "The component [review-player] - the list, the player and the form\n";

$registry = \Nino\Modules\Components::components( $appData );
$schema = $registry['review-player'] ?? [];

check( 'the registry knows it as this feature\'s own, with what it draws, where it may stand and a picture of its own - it reads no source and does not loop; the one attribute has the three words and the default all',
	( $schema['by'] ?? '' ) === 'review' && ( $schema['draws'] ?? '' ) === 'div .review-player' && ( $schema['in'] ?? [] ) === [ 'section' ] && ( $schema['preview'] ?? '' ) === 'block' && ( $schema['source'] ?? '' ) === 'none'
	&& ( $schema['loop'] ?? true ) === false && count( $schema['icon'] ?? [] ) >= 1 && array_keys( $schema['attributes'] ?? [] ) === [ 'kind' ] && ( $schema['attributes']['kind']['options'] ?? [] ) === [ 'all', 'audio', 'video' ]
	&& \Nino\Modules\Components::defaults( $appData, 'review-player' ) === [ 'kind' => 'all', 'class' => '' ] );
check( '...the Builder\'s component and the shortcode are one renderer: the class has the method the manifest derives its name to, and registers no shortcode of its own beside it', is_callable( [ '\\Nino\\Modules\\Review', 'componentReviewPlayer' ] ) === true );

// ...for a visitor, nothing at all
reviewVisitor();
ninoVisit( $appData, '/club/review' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
check( 'a visitor is shown nothing - not an empty frame, not a form, not the list, not the player of a file they ask for', \Nino\Html::renderHtml( $appData, '[review-player]' ) === '' && \Nino\Html::renderHtml( $appData, '[review-player kind="audio" class="x"]' ) === '' );
reviewWorkbench( 'admin' );
check( '...and the owner\'s workbench session is no member either: nothing', \Nino\Html::renderHtml( $appData, '[review-player]' ) === '' );
reviewVisitor();

// ...the list, for a member of the group
reviewAs( 'ada' );
ninoVisit( $appData, '/club/review' );
$html = \Nino\Html::renderHtml( $appData, '[review-player class="quiet"]' );
$boxes = reviewFind( $html, '//div[contains(concat(" ", normalize-space(@class), " "), " review-player ")]' );
$items = reviewFind( $html, '//li[contains(@class, "review-item")]' );
$ids = array_map( static fn( DOMElement $item ): string => $item->getAttribute( 'data-review-id' ), $items );
sort( $ids );
$expected = [ $track['id'], $clip['id'] ];
sort( $expected );

check( 'a member is shown one box with the classes of the component and the call, and the list in it', count( $boxes ) === 1 && reviewClasses( $boxes[0] ) === [ 'review-player', 'review-player--list', 'quiet' ] && count( reviewFind( $html, '//ul[@class="review-list"]' ) ) === 1
	&& reviewFind( $html, '//video | //audio | //form' ) === [] && str_contains( $html, '[[' ) === false );
check( '...in it the audio and video files of their group that Files lets them see - the mp3 and the mp4, no zip, no hidden file, no file of another group', $ids === $expected && str_contains( $html, $pack['id'] ) === false && str_contains( $html, $secret['id'] ) === false
	&& str_contains( $html, $guestTrack['id'] ) === false );

$byId = array_column( array_map( static fn( DOMElement $item ): array => [ $item->getAttribute( 'data-review-id' ), $item ], $items ), 1, 0 );
$cell = static function( DOMElement $row, string $class ): ?DOMElement {
	foreach( $row->getElementsByTagName( '*' ) as $child )
		if( in_array( $class, reviewClasses( $child ), true ) === true )
			return $child;
	return null;
};
check( '...each a link to this page with ?review=<id>, with its name and the name of its folder and the word for its kind', $cell( $byId[$track['id']], 'review-item__name' )->getAttribute( 'href' ) === '/club/review?review='. $track['id']
	&& $cell( $byId[$track['id']], 'review-item__name' )->textContent === 'Demo.mp3' && $cell( $byId[$clip['id']], 'review-item__name' )->textContent === 'Clip.mp4' && $cell( $byId[$track['id']], 'review-item__folder' )->textContent === 'Room'
	&& $byId[$track['id']]->getAttribute( 'data-review-kind' ) === 'audio' && $byId[$clip['id']]->getAttribute( 'data-review-kind' ) === 'video'
	&& trim( $cell( $byId[$track['id']], 'review-item__kind' )->textContent ) === (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/kind/audio' ) && trim( $cell( $byId[$clip['id']], 'review-item__kind' )->textContent ) === (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/kind/video' )
	&& trim( $cell( $byId[$clip['id']], 'review-item__kind' )->textContent ) !== trim( $cell( $byId[$track['id']], 'review-item__kind' )->textContent ) );

$audioOnly = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player kind="audio"]' ), '//li[contains(@class, "review-item")]' );
$videoOnly = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player kind="video"]' ), '//li[contains(@class, "review-item")]' );
$other = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player kind="nonsense"]' ), '//li[contains(@class, "review-item")]' );
check( 'the attribute kind narrows the list to the audio files or the video files - a word outside the options is the default, all', array_map( static fn( DOMElement $item ): string => $item->getAttribute( 'data-review-id' ), $audioOnly ) === [ $track['id'] ]
	&& array_map( static fn( DOMElement $item ): string => $item->getAttribute( 'data-review-id' ), $videoOnly ) === [ $clip['id'] ] && count( $other ) === 2 );

$byHand = [ 'a word outside the options' => [ 'kind' => 'nonsense' ], 'no attribute at all' => [], 'a list' => [ 'kind' => [ 'audio' ] ] ];
$byHandCounts = array_map( static fn( array $args ): int => count( reviewFind( \Nino\Modules\Review::componentReviewPlayer( $GLOBALS['appData'], $args ), '//li[contains(@class, "review-item")]' ) ), $byHand );
check( 'called by hand with a kind outside its words, with none, or with a list for it, the component lists all the files and not none', array_values( $byHandCounts ) === [ 2, 2, 2 ] );

reviewAs( 'bob' );
$bobsList = \Nino\Html::renderHtml( $appData, '[review-player]' );
check( 'a member of another group is shown the files of theirs and nothing of the staff\'s', array_map( static fn( DOMElement $item ): string => $item->getAttribute( 'data-review-id' ), reviewFind( $bobsList, '//li[contains(@class, "review-item")]' ) ) === [ $guestTrack['id'] ]
	&& str_contains( $bobsList, $track['id'] ) === false && str_contains( $bobsList, $room['id'] ) === false );

$dan = \Nino\Modules\Members::add( $appData, 'dan@example.org', 'Dan', 'tourists' );
reviewSignIn( $appData, $dan );
$noFiles = \Nino\Html::renderHtml( $appData, '[review-player]' );
\Nino\Modules\Members::change( $appData, $dan['id'], [ 'group' => '' ] );
$noGroup = \Nino\Html::renderHtml( $appData, '[review-player]' );
\Nino\Modules\Members::remove( $appData, $dan['id'] );
check( 'a member whose group has no audio or video file, and one without a group, are shown the list with the line that says there is none', count( reviewFind( $noFiles, '//li[contains(@class, "review-list__empty")]' ) ) === 1
	&& count( reviewFind( $noGroup, '//li[contains(@class, "review-list__empty")]' ) ) === 1 && reviewFind( $noFiles. $noGroup, '//li[contains(@class, "review-item")]' ) === [] && trim( reviewFind( $noFiles, '//li[contains(@class, "review-list__empty")]' )[0]->textContent ) !== '' );
reviewVisitor();

// ...the player, for a file the member may see
reviewAs( 'ada' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
$html = \Nino\Html::renderHtml( $appData, '[review-player]' );
$boxes = reviewFind( $html, '//div[contains(concat(" ", normalize-space(@class), " "), " review-player ")]' );
$media = reviewFind( $html, '//audio' );

check( 'with ?review=<id> of an audio file the box is the player of it: no list, an audio element - the controls of the browser, metadata only - whose source is the address of the Files feature, the name of the file and the way back to the list',
	count( $boxes ) === 1 && reviewClasses( $boxes[0] ) === [ 'review-player' ] && $boxes[0]->getAttribute( 'data-review-file' ) === $track['id'] && $boxes[0]->getAttribute( 'data-review-kind' ) === 'audio' && count( $media ) === 1 && reviewFind( $html, '//video | //li[contains(@class, "review-item")]' ) === []
	&& $media[0]->getAttribute( 'src' ) === \Nino\Modules\Files::url( $appData, $track['id'] ) && $media[0]->getAttribute( 'src' ) === '/.files/'. $track['id'] && $media[0]->hasAttribute( 'controls' ) === true && $media[0]->getAttribute( 'preload' ) === 'metadata'
	&& reviewFind( $html, '//h3' )[0]->textContent === 'Demo.mp3' && reviewFind( $html, '//p[contains(@class, "review-player__back")]/a' )[0]->getAttribute( 'href' ) === '/club/review' && str_contains( $html, '[[' ) === false );

// ...a caller in php may put its own markup between the title and the medium; a shortcode cannot
$withVersions = \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'versions' => '<p class="review-test-versions">V</p>' ] );
$order = array_map( static fn( DOMElement $node ): string => $node->nodeName. '.'. $node->getAttribute( 'class' ), reviewFind( $withVersions, '//h3 | //p[@class="review-test-versions"] | //audio' ) );
check( 'the player has a place for markup of its caller between the title and the medium - given in php as versions, and nowhere but there',
	$order === [ 'h3.review-player__title', 'p.review-test-versions', 'audio.review-player__media' ] && substr_count( $withVersions, 'review-test-versions' ) === 1 && str_contains( $withVersions, '[[versions]]' ) === false );
check( '...a shortcode cannot hand it markup - the attribute is no attribute of the schema -, and a player without it has no trace of the place',
	str_contains( \Nino\Html::renderHtml( $appData, '[review-player versions="<i class=\'x\'>V</i>"]' ), "class='x'" ) === false && str_contains( $html, '[[versions]]' ) === false && count( reviewFind( $html, '//p[@class="review-test-versions"]' ) ) === 0 );

ninoVisit( $appData, '/club/review?review='. $clip['id'] );
$videoHtml = \Nino\Html::renderHtml( $appData, '[review-player]' );
reviewPlayerChecks( $appData, $html, $videoHtml );
$video = reviewFind( $videoHtml, '//video' );
check( 'a video file is a video element, with the same source rule and the same controls', count( $video ) === 1 && reviewFind( $videoHtml, '//audio' ) === [] && $video[0]->getAttribute( 'src' ) === '/.files/'. $clip['id'] && $video[0]->hasAttribute( 'controls' ) === true
	&& $video[0]->getAttribute( 'preload' ) === 'metadata' && $boxes !== [] && reviewFind( $videoHtml, '//div[@data-review-kind="video"]' ) !== [] );

$appData['/nino/dir'] = '/club';
ninoVisit( $appData, '/review?review='. $track['id'] );
$underDirectory = \Nino\Html::renderHtml( $appData, '[review-player]' );
$appData['/nino/dir'] = '';
check( 'under a directory the source and the way back start with it, like every address of the Files feature', reviewFind( $underDirectory, '//audio' )[0]->getAttribute( 'src' ) === '/club/.files/'. $track['id']
	&& reviewFind( $underDirectory, '//p[contains(@class, "review-player__back")]/a' )[0]->getAttribute( 'href' ) === '/club/review' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );

$appData['/nino/dir'] = '/club';
ninoVisit( $appData, '/review' );
$linksUnder = array_map( static fn( DOMElement $link ): string => $link->getAttribute( 'href' ), reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//li[contains(@class, "review-item")]//a[contains(@class, "review-item__name")]' ) );
$appData['/nino/dir'] = '';
ninoVisit( $appData, '/club/review?review='. $track['id'] );
sort( $linksUnder );
$linksExpected = [ '/club/review?review='. $track['id'], '/club/review?review='. $clip['id'] ];
sort( $linksExpected );
check( '...and the links of the list start with the directory as well', $linksUnder === $linksExpected );

// The comments of the file, as the track orders them: a set of comments of known ids, times and authors
\Nino\Filesystem::putFileContent( $appData, '/data/review.php', [] );
$k1 = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 65.0, "Snare\nis late <b>x</b> [[/project/company/general/name]]" );
$k2 = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, 12.0, 'Intro is long' );
$k3 = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'General note' );
$k4 = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 65.0, 'Same second' );
$r1 = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'Will do', $k1['id'] );
$r2 = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, 70.0, 'Great', $k1['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $track['id'], $k2['id'] );
$html = \Nino\Html::renderHtml( $appData, '[review-player]' );
$comments = reviewFind( $html, '//ol[@class="review-comments"]/li' );
$byComment = array_column( array_map( static fn( DOMElement $comment ): array => [ $comment->getAttribute( 'data-review-id' ), $comment ], $comments ), 1, 0 );

check( 'the comments are an ordered list under the player in the order of the track: a list item of its own for each with its id - 12 s, 1:05 and 1:05 in the order they were written, then the one without a time - and the number of comments and answers in the heading',
	array_map( 'strval', array_keys( $byComment ) ) === [ $k2['id'], $k1['id'], $k4['id'], $k3['id'] ] && count( reviewFind( $html, '//ol[@class="review-comments"]' ) ) === 1 && reviewFind( $html, '//p[contains(@class, "review-comments--none")]' ) === [] && trim( $cell( reviewFind( $html, '//h4' )[0], 'review-player__count' )->textContent ) === '6' );
check( '...a comment with a time carries it in data-review-at, as the number it is stored as, and one without carries none', array_map( static fn( DOMElement $comment ): ?string => $comment->hasAttribute( 'data-review-at' ) ? $comment->getAttribute( 'data-review-at' ) : null, array_values( $byComment ) ) === [ '12', '65', '65', null ] );
$timeLink = static function( DOMElement $item ) use ( $cell ): ?DOMElement {
	foreach( $item->getElementsByTagName( 'a' ) as $link )
		if( in_array( 'review-comment__time', reviewClasses( $link ), true ) === true )
			return $link;
	return null;
};
check( '...the time of a comment is written m:ss and is a link to the file at that second - which the script turns into a click on the player -, and a comment without a time has none', $timeLink( $byComment[$k2['id']] )?->textContent === '0:12' && $timeLink( $byComment[$k1['id']] )?->textContent === '1:05'
	&& $timeLink( $byComment[$k1['id']] )?->getAttribute( 'href' ) === '/.files/'. $track['id']. '#t=65' && $timeLink( $byComment[$k2['id']] )?->getAttribute( 'href' ) === '/.files/'. $track['id']. '#t=12' && $timeLink( $byComment[$k3['id']] ) === null );
$who = static fn( DOMElement $item ): string => trim( $cell( $item, 'review-comment__who' )->textContent );
check( '...who wrote it: the name of the member, a word for a member without a name, the word for the owner - never an address', $who( $byComment[$k1['id']] ) === 'Ada Lovelace' && $who( $byComment[$k2['id']] ) === (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/comment/member' )
	&& $who( $byComment[$k3['id']] ) === (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/comment/owner' ) && str_contains( $html, 'example.org' ) === false && str_contains( $html, 'cyd@' ) === false
	&& $who( $byComment[$k2['id']] ) !== $who( $byComment[$k3['id']] ) );
$text = $cell( $byComment[$k1['id']], 'review-comment__text' );
check( '...the text is text: a line break is a break, markup in it is not an element and a key or a shortcode in it is neither filled in nor run', $text->getElementsByTagName( 'br' )->length === 1 && $text->textContent === 'Snareis late <b>x</b> [[/project/company/general/name]]'
	&& reviewFind( $html, '//b' ) === [] && str_contains( $html, '[[/project/company' ) === false && count( reviewFind( $html, '//form' ) ) === 1 );
check( '...a comment that is done carries the mark and the class of it, the others do not', count( reviewFind( $html, '//li[contains(@class, "review-comment--done")]' ) ) === 1 && reviewFind( $html, '//li[contains(@class, "review-comment--done")]' )[0]->getAttribute( 'data-review-id' ) === $k2['id']
	&& count( reviewFind( $html, '//span[contains(@class, "review-comment__done")]' ) ) === 1 && trim( reviewFind( $html, '//span[contains(@class, "review-comment__done")]' )[0]->textContent ) === (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/comment/done' ) );
$answers = reviewFind( $html, '//li[@data-review-id="'. $k1['id']. '"]/ul[@class="review-answers"]/li' );
check( '...the answers stand under the comment they answer in the order they were written, as items of their own with their id, a time where they have one and no list where there are none', array_map( static fn( DOMElement $answer ): string => $answer->getAttribute( 'data-review-id' ), $answers ) === [ $r1['id'], $r2['id'] ]
	&& $answers[0]->hasAttribute( 'data-review-at' ) === false && $answers[1]->getAttribute( 'data-review-at' ) === '70' && $timeLink( $answers[1] )?->textContent === '1:10' && reviewClasses( $answers[0] ) === [ 'review-answer' ]
	&& count( reviewFind( $html, '//ul[@class="review-answers"]' ) ) === 1 && $who( $answers[1] ) === $who( $byComment[$k2['id']] ) );
$replies = reviewFind( $html, '//button[@data-review-reply]' );
check( '...every comment and every answer has a button to answer it - drawn hidden, since it does nothing without the script - and so is the form\'s switch of the time, a checked checkbox in a label beside the time field', count( $replies ) === 6
	&& array_filter( $replies, static fn( DOMElement $button ): bool => $button->hasAttribute( 'hidden' ) === false || $button->getAttribute( 'type' ) !== 'button' ) === [] && count( reviewFind( $html, '//form//p[input[@name="at"]]/label[contains(@class, "review-form__follow")][@hidden]/input[contains(@class, "review-form__follow-input")][@type="checkbox"][@checked]' ) ) === 1 );

// The form
$form = reviewFind( $html, '//form' );
$fields = [];
foreach( reviewFind( $html, '//form//input | //form//textarea' ) as $field )
	$fields[ $field->getAttribute( 'name' ) ] = $field;
check( 'the form posts to /.review/comment: the hidden file, the page it stands on and an empty parent, the csrf field of the session, the time as text, the switch of the time as a checkbox that has no name and so posts nothing, the comment as a textarea that is required, and one button that submits',
	count( $form ) === 1 && strtolower( $form[0]->getAttribute( 'method' ) ) === 'post' && $form[0]->getAttribute( 'action' ) === '/.review/comment' && array_keys( $fields ) === [ 'file', 'return', 'parent', '_csrf', 'at', '', 'text' ] && $fields['']->getAttribute( 'type' ) === 'checkbox'
	&& $fields['file']->getAttribute( 'value' ) === $track['id'] && $fields['file']->getAttribute( 'type' ) === 'hidden' && $fields['return']->getAttribute( 'value' ) === '/club/review' && $fields['return']->getAttribute( 'type' ) === 'hidden'
	&& $fields['parent']->getAttribute( 'value' ) === '' && $fields['parent']->getAttribute( 'type' ) === 'hidden' && $fields['_csrf']->getAttribute( 'value' ) === \Nino\Csrf::getToken( $appData ) && $fields['at']->getAttribute( 'type' ) === 'text'
	&& $fields['at']->getAttribute( 'value' ) === '' && $fields['text']->tagName === 'textarea' && $fields['text']->hasAttribute( 'required' ) === true && count( reviewFind( $html, '//form//button[@type="submit"]' ) ) === 1 );
check( '...it is no .nino-form (the kernel\'s script would post it by XHR), each field has the label that belongs to it, and the comment may be as long as the setting says', in_array( 'nino-form', reviewClasses( $form[0] ), true ) === false && in_array( 'review-form', reviewClasses( $form[0] ), true ) === true
	&& $fields['at']->getAttribute( 'id' ) !== '' && count( reviewFind( $html, '//label[@for="'. $fields['at']->getAttribute( 'id' ). '"]' ) ) === 1 && count( reviewFind( $html, '//label[@for="'. $fields['text']->getAttribute( 'id' ). '"]' ) ) === 1
	&& $fields['text']->getAttribute( 'maxlength' ) === '2000' );
$classed = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player class="wide"]' ), '//div[contains(concat(" ", normalize-space(@class), " "), " review-player ")]' );
ninoVisit( $appData, '/club/review"onfocus="x?review='. $track['id'] );
$hostileReturn = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//form//input[@name="return"]' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
check( '...the player takes the class of the call, and the address of the page is text in an attribute: whatever it holds, it does not end the attribute or add one', count( $classed ) === 1 && reviewClasses( $classed[0] ) === [ 'review-player', 'wide' ]
	&& count( $hostileReturn ) === 1 && str_contains( $hostileReturn[0]->getAttribute( 'value' ), '"' ) === false && str_starts_with( $hostileReturn[0]->getAttribute( 'value' ), '/club/review' ) === true && $hostileReturn[0]->hasAttribute( 'onfocus' ) === false );
\Nino\Features::saveSettings( $appData, 'review', [ 'length' => 500 ] );
$short = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//textarea' )[0]->getAttribute( 'maxlength' );
\Nino\Features::saveSettings( $appData, 'review', [ 'length' => 2000 ] );
check( '...and the length of the setting is the length of the field', $short === '500' );

$appData['./nino/http/requests'][0]['/nino/http/request']['uri'] = '/club/review"><b>x</b>';
$rawPath = \Nino\Html::renderHtml( $appData, '[review-player]' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
check( '...and an address that did hold markup - the kernel strips it before it gets here, the component does not rely on that - is text in the field and in the way back: no element comes of it', reviewFind( $rawPath, '//b' ) === []
	&& reviewFind( $rawPath, '//form//input[@name="return"]' )[0]->getAttribute( 'value' ) === '/club/review"><b>x</b>' && reviewFind( $rawPath, '//p[contains(@class, "review-player__back")]/a' )[0]->getAttribute( 'href' ) === '/club/review"><b>x</b>' );

reviewAs( 'bob' );
ninoVisit( $appData, '/club/review?review='. $guestTrack['id'] );
$quietHtml = \Nino\Html::renderHtml( $appData, '[review-player]' );
reviewAs( 'ada' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
$quietLine = reviewFind( $quietHtml, '//p[contains(@class, "review-comments--none")]' );
check( 'a file nobody has written on has the line that says so in place of the list of comments, a count of none, and the form', count( $quietLine ) === 1 && trim( $quietLine[0]->textContent ) !== '' && reviewFind( $quietHtml, '//ol' ) === []
	&& count( reviewFind( $quietHtml, '//form' ) ) === 1 && trim( $cell( reviewFind( $quietHtml, '//h4' )[0], 'review-player__count' )->textContent ) === '0' );

$twice = \Nino\Html::renderHtml( $appData, '[review-player][review-player]' );
$labels = array_map( static fn( DOMElement $label ): string => $label->getAttribute( 'for' ), reviewFind( $twice, '//label[@for]' ) );
check( 'two players on a page do not share an id', count( reviewFind( $twice, '//form' ) ) === 2 && count( array_unique( $labels ) ) === 4 && count( reviewFind( $twice, '//input[@id="'. $labels[0]. '"]' ) ) === 1 );
check( 'a hand-written call with an attribute nobody declared still renders', count( reviewFind( \Nino\Html::renderHtml( $appData, '[review-player nonsense="1"]' ), '//form' ) ) === 1 );

// The states: the query says what the form says, of the player and the list and of nothing else
$states = [];
$english = [];
$language = \Nino\Locales::getCurrentLocale( $appData );

foreach( [ 'sent', 'long', 'limit', 'expired', 'invalid' ] as $state ) {
	ninoVisit( $appData, '/club/review?review='. $track['id']. '&review-state='. $state );
	$states[$state] = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//form/p[contains(@class, "review-state")]' );
	\Nino\Locales::useLocale( $appData, 'en_US' );
	$english[$state] = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//form/p[contains(@class, "review-state")]' );
	\Nino\Locales::useLocale( $appData, $language );
}

check( 'the five states have a paragraph of their own in the form, that is not empty and holds no key left unresolved, said in the language of the page - each a different one',
	array_filter( $states, static fn( array $found ): bool => count( $found ) !== 1 || trim( $found[0]->textContent ) === '' || str_contains( $found[0]->textContent, '[[' ) || $found[0]->getAttribute( 'role' ) !== 'status' ) === [] && count( array_unique( array_map( static fn( array $found ): string => trim( $found[0]->textContent ), $states ) ) ) === 5
	&& $language !== 'en_US' && array_filter( $states, static fn( array $found, string $state ): bool => count( $english[$state] ) !== 1 || trim( $english[$state][0]->textContent ) === trim( $found[0]->textContent ), ARRAY_FILTER_USE_BOTH ) === [] );

$none = [];
foreach( [ '', '&review-state=', '&review-state=SENT', '&review-state=other', '&review-state[]=sent', '&review-state=sent%20', '&review-state=<b>x</b>', '&review-state=review-sent' ] as $query ) {
	ninoVisit( $appData, '/club/review?review='. $track['id']. $query );
	$none[$query] = count( reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//p[contains(@class, "review-state")]' ) );
}
check( 'anything else in the query - an array, a made-up word, another case - is no state: there is no paragraph', array_filter( $none ) === [] && count( $none ) === 8 );

ninoVisit( $appData, '/club/review?review-state=limit' );
$listState = reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//div[contains(@class, "review-player--list")]/p[contains(@class, "review-state")]' );
check( 'the list says the state as well, when the query has one and no file', count( $listState ) === 1 && trim( $listState[0]->textContent ) === trim( $states['limit'][0]->textContent ) );

// A file the member may not see, one that is no audio or video, one whose bytes are gone, an id that is none: the list, with the state invalid
$lost = reviewUpload( $appData, $room['id'], 'Lost.mp3', $mp3 );
$lostPath = (string) \Nino\Modules\Files::path( $appData, $lost['id'] );
$lostBytes = (string) file_get_contents( $lostPath );
unlink( $lostPath );
reviewAs( 'ada' );
$refused = [];
foreach( [ 'the hidden file of the owner' => 'review='. $secret['id'], 'a file of another group' => 'review='. $guestTrack['id'], 'a zip' => 'review='. $pack['id'], 'an id nobody has' => 'review=ffffffffffff', 'something that is no id' => 'review=..%2F..%2Fetc%2Fpasswd',
	'a file whose bytes are gone' => 'review='. $lost['id'] ] as $kind => $query ) {
	ninoVisit( $appData, '/club/review?'. $query );
	$answer = \Nino\Html::renderHtml( $appData, '[review-player]' );
	$refused[$kind] = count( reviewFind( $answer, '//div[contains(@class, "review-player--list")]/p[contains(@class, "review-state")]' ) ) === 1 && trim( reviewFind( $answer, '//p[contains(@class, "review-state")]' )[0]->textContent ) === trim( (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/state/invalid' ) )
		&& reviewFind( $answer, '//audio | //video | //form' ) === [] && count( reviewFind( $answer, '//li[contains(@class, "review-item")]' ) ) === count( \Nino\Modules\Review::media( $appData ) ) && str_contains( $answer, $secret['id'] ) === false && str_contains( $answer, $pack['id'] ) === false;
}
file_put_contents( $lostPath, $lostBytes );
check( 'the hidden file of the owner, a file of another group, a zip, an id nobody has, something that is no id and a file whose bytes are gone show the list with the state invalid - never a player, and nothing of the file', array_filter( $refused, static fn( bool $same ): bool => $same === false ) === [] && count( $refused ) === 6 );
ninoWarnings();
ninoVisit( $appData, '/club/review?review[]='. $track['id']. '&review-state[]=sent' );
$asList = \Nino\Html::renderHtml( $appData, '[review-player]' );
check( 'a list where an id is asked for is no id asked for: the plain list, with no state and no warning', reviewFind( $asList, '//p[contains(@class, "review-state")] | //audio | //video | //form' ) === [] && count( reviewFind( $asList, '//li[contains(@class, "review-item")]' ) ) === count( \Nino\Modules\Review::media( $appData ) ) && ninoWarnings() === [] );

ninoVisit( $appData, '/club/review?review='. $secret['id'] );
reviewAs( 'bob' );
$bobRefused = \Nino\Html::renderHtml( $appData, '[review-player]' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
$bobRefused .= \Nino\Html::renderHtml( $appData, '[review-player]' );
check( 'a member of another group is shown the list with invalid for the staff\'s file, and nothing of it', reviewFind( $bobRefused, '//audio | //video | //form' ) === [] && substr_count( $bobRefused, 'review-state' ) >= 2 && str_contains( $bobRefused, $track['id'] ) === false || str_contains( $bobRefused, 'review?review='. $guestTrack['id'] ) );

// A file that was removed from the server: no medium any more - the comments stay, to be read, with no form to add to them
reviewVisitor();
$vanished = reviewUpload( $appData, $room['id'], 'Vanished.mp3', $mp3 );
$vanishedFirst = \Nino\Modules\Review::add( $appData, $vanished['id'], $byAda, 12.0, 'Intro is long' );
$vanishedAnswer = \Nino\Modules\Review::add( $appData, $vanished['id'], $owner, null, 'Noted', $vanishedFirst['id'] );
reviewAs( 'ada' );
$mediaBefore = array_column( \Nino\Modules\Review::media( $appData ), 'id' );
$vanishedRemoved = \Nino\Modules\Files\Store::removeBytes( $appData, $vanished['id'] );
$mediaAfter = array_column( \Nino\Modules\Review::media( $appData ), 'id' );
ninoVisit( $appData, '/club/review' );
$listAfter = \Nino\Html::renderHtml( $appData, '[review-player]' );
check( 'a file that was removed from the server is no medium: media() and the list of the player leave it out, and every other file of the group stays',
	in_array( $vanished['id'], $mediaBefore, true ) === true && is_array( $vanishedRemoved ) === true && in_array( $vanished['id'], $mediaAfter, true ) === false && array_values( array_diff( $mediaBefore, [ $vanished['id'] ] ) ) === $mediaAfter
	&& str_contains( $listAfter, $vanished['id'] ) === false && count( reviewFind( $listAfter, '//li[contains(@class, "review-item")]' ) ) === count( $mediaAfter ) );
check( '...its comments are still in the store, and the panel\'s are those of Files: the file has an entry, so they are not dropped', count( \Nino\Modules\Review::comments( $appData, $vanished['id'] ) ) === 1 && count( \Nino\Modules\Review::comments( $appData, $vanished['id'] )[0]['answers'] ) === 1 );

ninoVisit( $appData, '/club/review?review='. $vanished['id'] );
$goneHtml = \Nino\Html::renderHtml( $appData, '[review-player]' );
$goneBoxes = reviewFind( $goneHtml, '//div[contains(concat(" ", normalize-space(@class), " "), " review-player ")]' );
$goneItems = reviewFind( $goneHtml, '//li[@data-review-id]' );
check( 'asking for it shows its player with the comments and no medium - no audio, no video, no form - marked as removed, with its name, the way back and the count of the comments and answers, and no state: it is no refusal',
	count( $goneBoxes ) === 1 && $goneBoxes[0]->getAttribute( 'data-review-file' ) === $vanished['id'] && in_array( 'review-player--removed', reviewClasses( $goneBoxes[0] ), true ) === true && reviewFind( $goneHtml, '//audio | //video | //form' ) === []
	&& reviewFind( $goneHtml, '//h3' )[0]->textContent === 'Vanished.mp3' && reviewFind( $goneHtml, '//p[contains(@class, "review-player__back")]/a' )[0]->getAttribute( 'href' ) === '/club/review' && reviewFind( $goneHtml, '//p[contains(@class, "review-state")]' ) === []
	&& array_map( static fn( DOMElement $item ): string => $item->getAttribute( 'data-review-id' ), $goneItems ) === [ $vanishedFirst['id'], $vanishedAnswer['id'] ] && str_contains( $goneHtml, 'Intro is long' ) === true && str_contains( $goneHtml, '/.files/'. $vanished['id'] ) === false );
check( '...and it has no button to answer a comment with - there is no form for the answer - where a player with its medium has one for each',
	reviewFind( $goneHtml, '//button[@data-review-reply]' ) === [] && count( reviewFind( $html, '//button[@data-review-reply]' ) ) > 0 );
check( '...the time of a comment is the time and no link: there is no file to play it in', reviewFind( $goneHtml, '//a[contains(@class, "review-comment__time")]' ) === [] && count( reviewFind( $goneHtml, '//span[contains(@class, "review-comment__time")]' ) ) === 1
	&& trim( reviewFind( $goneHtml, '//span[contains(@class, "review-comment__time")]' )[0]->textContent ) === \Nino\Modules\Review::clock( 12.0 ) );

// ...and nobody adds to them: a post for a file that was removed is the state invalid, and nothing is stored
reviewFresh( $appData );
$told = [];
$goneBefore = reviewData( $appData );
$goneCommented = reviewPost( 'ada', [ 'file' => $vanished['id'], 'text' => 'Too late' ] );
check( 'a comment on a file that was removed is refused as invalid - 303 back to the page with the state - and nothing is stored or told', $goneCommented[0] === 303 && $goneCommented[1] === '/club/review?review='. $vanished['id']. '&review-state=invalid' && reviewData( $appData ) === $goneBefore && $told === [] );
$strangerAnswer = reviewPost( 'bob', [ 'file' => $vanished['id'], 'text' => 'Hello' ] );
check( '...and a member who may not see the file is the 404 of every file: nothing tells them it ever was', $strangerAnswer[0] === 404 && $strangerAnswer[2] === 'Not Found' );
reviewVisitor();

// Names are text wherever they are written
$eve = \Nino\Modules\Members::add( $appData, 'eve@example.org', '<img src=x onerror=alert(1)> [[/project/company/general/name]] [review-player]', 'staff' );
\Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => $eve['id'] ], 5.0, 'From eve' );
$goner = \Nino\Modules\Members::add( $appData, 'goner@example.org', 'Goner', 'staff' );
\Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => $goner['id'] ], 6.0, 'From a member who left' );
\Nino\Modules\Members::remove( $appData, $goner['id'] );
reviewWorkbench( 'admin' );
$hostileFolder = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => '<i>folder</i> [[x]] [review-player]' ], 'files/folder/add' )[1]['folder'];
reviewVisitor();
$hostileFile = reviewUpload( $appData, $hostileFolder['id'], '<u>under<u> [[x]] [review-player].mp3', $mp3 );
reviewAs( 'ada' );
ninoVisit( $appData, '/club/review' );
$hostileList = \Nino\Html::renderHtml( $appData, '[review-player]' );
ninoVisit( $appData, '/club/review?review='. $hostileFile['id'] );
$hostilePlayer = \Nino\Html::renderHtml( $appData, '[review-player]' );
ninoVisit( $appData, '/club/review?review='. $track['id'] );
$hostileComments = \Nino\Html::renderHtml( $appData, '[review-player]' );
$hostileNames = array_map( static fn( DOMElement $name ): string => $name->textContent, reviewFind( $hostileComments, '//strong[contains(@class, "review-comment__who")]' ) );
check( 'the name of a file, a folder and a member is text: markup in it is not an element, and a key or a shortcode in it is neither filled in nor run', reviewFind( $hostileList. $hostilePlayer. $hostileComments, '//img | //u | //@onerror' ) === [] && str_contains( $hostileList. $hostilePlayer. $hostileComments, '[[/project' ) === false && count( reviewFind( $hostileList, '//form' ) ) === 0
	&& in_array( '<img src=x onerror=alert(1)> [[/project/company/general/name]] [review-player]', $hostileNames, true ) === true && count( reviewFind( $hostileComments, '//form' ) ) === 1 && count( reviewFind( $hostilePlayer, '//form' ) ) === 1
	&& reviewFind( $hostilePlayer, '//h3' )[0]->textContent === '<u>under<u> [[x]] [review-player].mp3'
	&& in_array( '<i>folder</i> [[x]] [review-player]', array_map( static fn( DOMElement $folder ): string => $folder->textContent, reviewFind( $hostileList, '//span[contains(@class, "review-item__folder")]' ) ), true ) === true );
check( '...a comment of an account that is gone is said so, not named', in_array( (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/comment/gone' ), $hostileNames, true ) === true && in_array( 'Goner', $hostileNames, true ) === false );

reviewVisitor();
echo "\n";


// --- The panel ---------------------------------------------------------------------

echo "Modules\\Review\\Admin - the comments, and what the panel says\n";

reviewWorkbench( 'admin' );
\Nino\Filesystem::putFileContent( $appData, '/data/review.php', [] );
reviewFresh( $appData );
$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the panel is in the registry while the feature is active, in the rail\'s group of the features, with a permission of its own and a script and a stylesheet that are there',
	isset( $panels['review'] ) === true && $panels['review']['group'] === 'features' && $panels['review']['perm'] === '/_admin/review/manage'
	&& array_filter( \Nino\Modules\Review\Admin::assets(), static fn( string $asset ): bool => is_file( \Nino\Filesystem::path( $GLOBALS['appData'], $asset ) ) === false ) === [] && count( \Nino\Modules\Review\Admin::assets() ) === 2
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Review\Admin::text(). '/en_US.php' ) ) === true && is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Review\Admin::text(). '/de_DE.php' ) ) === true
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Review\Admin::template(). '.tpl' ) ) === true );
check( '...the template has the one element the script mounts into, and the namespace of the script is the uri of the panel', preg_match( '/id="review-app"/', (string) file_get_contents( $dir. '/templates/panel.tpl' ) ) === 1
	&& preg_match( "/mount\\s*:\\s*'review-app'/", (string) file_get_contents( $dir. '/assets/admin.js' ) ) === 1 && preg_match( "/uri\\s*:\\s*'review'/", (string) file_get_contents( $dir. '/assets/admin.js' ) ) === 1
	&& str_contains( (string) file_get_contents( $dir. '/assets/admin.js' ), 'Nino.admin.review = Nino.adminUi.panel(' ) === true );
check( '...the actions are the five there are, all under the slug of the panel', array_keys( array_filter( \Nino\Admin\Admin::actions( $appData ), static fn( string $action ): bool => str_starts_with( $action, 'review/' ), ARRAY_FILTER_USE_KEY ) )
	=== [ 'review/list', 'review/comments', 'review/answer', 'review/done', 'review/delete' ] );

// Comments to work with: two members' comments with an answer of the owner, one on the clip, and a file whose folder no group has
$c1 = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 65.0, "Snare\nis late", '', '/club/review' );
$c2 = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, null, 'General praise' );
$a1 = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'Fixed in the next mix', $c1['id'] );
$c3 = \Nino\Modules\Review::add( $appData, $clip['id'], $byAda, 5.0, 'Too dark' );
$tourist = \Nino\Modules\Members::add( $appData, 'tourist@example.org', 'Tourist', 'tourists' );
reviewWorkbench( 'admin' );
$ghosts = ninoCall( $appData, $panel, [ 'group' => 'tourists', 'name' => 'Ghost folder' ], 'files/folder/add' )[1]['folder'];
reviewVisitor();
$ghostTrack = reviewUpload( $appData, $ghosts['id'], 'Ghost.mp3', $mp3 );
\Nino\Modules\Review::add( $appData, $ghostTrack['id'], $owner, 1.0, 'Where nobody is' );
\Nino\Modules\Members::remove( $appData, $tourist['id'] );

// Denied: the actions are the panel's
$posts = [ 'review/list' => [], 'review/comments' => [ 'file' => $track['id'] ], 'review/answer' => [ 'file' => $track['id'], 'text' => 'x' ], 'review/done' => [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/delete' => [ 'file' => $track['id'], 'id' => $c1['id'] ] ];
$refused = [ 'signed out' => [], 'without the permission' => [] ];
$before = reviewData( $appData );

foreach( $posts as $action => $data ) {
	reviewWorkbench( '' );
	$refused['signed out'][$action] = ninoCall( $appData, $panel, $data, $action );
	reviewWorkbench( 'plain' );
	$refused['without the permission'][$action] = ninoCall( $appData, $panel, $data, $action );
}

reviewWorkbench( 'admin' );
check( 'every action refuses a visitor with 401 and the code session and an account without the permission with 403, and writes nothing',
	array_values( array_unique( array_column( $refused['signed out'], 0 ) ) ) === [ 401 ] && array_values( array_unique( array_map( static fn( array $answer ): string => (string) $answer[1]['code'], $refused['signed out'] ) ) ) === [ 'session' ]
	&& array_values( array_unique( array_column( $refused['without the permission'], 0 ) ) ) === [ 403 ] && count( $refused['signed out'] ) === 5 && reviewData( $appData ) === $before && $told === [] );

// The list
$list = ninoCall( $appData, $panel, [], 'review/list' );
$rows = array_column( (array) ( $list[1]['files'] ?? [] ), null, 'name' );
check( 'the list is the files that have comments - and that Files has as audio or video -, by group, folder and name, each with its id, name, kind, folder, group and the number of its comments and of the open ones',
	$list[0] === 200 && array_keys( $list[1] ) === [ 'files' ] && array_column( $list[1]['files'], 'name' ) === [ 'Clip.mp4', 'Demo.mp3', 'Ghost.mp3' ] && array_keys( $rows['Demo.mp3'] ) === [ 'id', 'name', 'kind', 'folder', 'folderName', 'group', 'count', 'open' ]
	&& $rows['Demo.mp3']['id'] === $track['id'] && $rows['Demo.mp3']['kind'] === 'audio' && $rows['Clip.mp4']['kind'] === 'video' && $rows['Demo.mp3']['folder'] === $room['id'] && $rows['Demo.mp3']['folderName'] === 'Room' && $rows['Demo.mp3']['group'] === 'staff' );
check( '...the count is all the comments of the file, its answers among them, and the open ones are those of members that are not marked as done - the owner\'s answer is no work for the owner', $rows['Demo.mp3']['count'] === 3 && $rows['Demo.mp3']['open'] === 2 && $rows['Clip.mp4']['count'] === 1 && $rows['Clip.mp4']['open'] === 1
	&& $rows['Ghost.mp3']['count'] === 1 && $rows['Ghost.mp3']['open'] === 0 );
check( '...a file whose folder belongs to a group with no member is listed too, with no group and no folder name - and last', $rows['Ghost.mp3']['group'] === '' && $rows['Ghost.mp3']['folderName'] === '' && $rows['Ghost.mp3']['folder'] === $ghosts['id'] );

// Comments on one file
$view = ninoCall( $appData, $panel, [ 'file' => $track['id'] ], 'review/comments' );
$body = (array) $view[1];
check( 'the comments on a file come with the file - its id, name, kind, the address the Files feature plays it at and whether its bytes are there -, the comments in the order of the track with their answers, the longest comment and whether the account may play files',
	$view[0] === 200 && array_keys( $body ) === [ 'file', 'comments', 'length', 'notify', 'files' ] && $body['files'] === true && $body['file'] === [ 'id' => $track['id'], 'name' => 'Demo.mp3', 'kind' => 'audio', 'url' => '/.files/'. $track['id'], 'playable' => true ] && $body['length'] === 2000
	&& array_column( $body['comments'], 'id' ) === [ $c1['id'], $c2['id'] ] && array_column( $body['comments'][0]['answers'], 'id' ) === [ $a1['id'] ] && $body['comments'][0]['text'] === "Snare\nis late" );
$clipView = ninoCall( $appData, $panel, [ 'file' => $clip['id'] ], 'review/comments' );

// Whether the account may play files is what the route of Files will say to it: that answers a workbench session by the permission of its own panel
$mayPlay = [];

foreach( [ 'admin', 'reviewer', 'both', 'tools' ] as $who ) {
	reviewWorkbench( $who );
	$asked = ninoCall( $appData, $panel, [ 'file' => $track['id'] ], 'review/comments' );
	$mayPlay[$who] = [ $asked[0], $asked[1]['files'] ?? null, reviewServe( $appData, '/.files/'. $track['id'] )['/nino/http/response']['statusCode'] ];
}

reviewWorkbench( 'admin' );
check( 'the answer says whether the account may play files, which is what the route of Files does with it: the permission of this panel alone does not - the route is the 404 of a stranger -, with the permission of the Files panel beside it, from above (/_admin/*) or with full access it does',
	$mayPlay === [ 'admin' => [ 200, true, 200 ], 'reviewer' => [ 200, false, 404 ], 'both' => [ 200, true, 200 ], 'tools' => [ 200, true, 200 ] ] );
check( '...a video file says it is one, with its own address', $clipView[0] === 200 && $clipView[1]['file'] === [ 'id' => $clip['id'], 'name' => 'Clip.mp4', 'kind' => 'video', 'url' => '/.files/'. $clip['id'], 'playable' => true ] );
check( '...each with the name of its author: the name of the account, its address where it has no name, nothing for the owner - and for an account that is gone', $body['comments'][0]['by']['label'] === 'Ada Lovelace' && $body['comments'][1]['by']['label'] === 'cyd@example.org'
	&& $body['comments'][0]['answers'][0]['by']['label'] === '' && ( function() use ( &$appData, $panel, $track, $ada, $c1 ): bool {
		$gone = \Nino\Modules\Members::add( $appData, 'gone@example.org', 'Gone', 'staff' );
		$made = \Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => $gone['id'] ], 1.0, 'From a member who leaves' );
		\Nino\Modules\Members::remove( $appData, $gone['id'] );
		$labels = array_column( array_column( (array) ninoCall( $appData, $panel, [ 'file' => $track['id'] ], 'review/comments' )[1]['comments'], 'by', 'id' ), 'label', null );
		\Nino\Modules\Review\Store::remove( $appData, $track['id'], $made['id'] );
		return in_array( '', $labels, true ) === true;
	} )() );

$unknown = [ 'a file nobody has' => ninoCall( $appData, $panel, [ 'file' => 'ffffffffffff' ], 'review/comments' ), 'a zip' => ninoCall( $appData, $panel, [ 'file' => $pack['id'] ], 'review/comments' ),
	'something that is no id' => ninoCall( $appData, $panel, [ 'file' => '../x' ], 'review/comments' ), 'a list' => ninoCall( $appData, $panel, [ 'file' => [ $track['id'] ] ], 'review/comments' ), 'nothing' => ninoCall( $appData, $panel, [], 'review/comments' ) ];
check( 'a file nobody has, a zip, something that is no id, a list and nothing are 404 review_unknown', array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), $unknown ) ) ) === [ '404 review_unknown' ] );

$bytes = (string) \Nino\Modules\Files::path( $appData, $guestTrack['id'] );
$kept = (string) file_get_contents( $bytes );
\Nino\Modules\Review::add( $appData, $guestTrack['id'], $owner, 3.0, 'Bytes will go' );
unlink( $bytes );
$gone = ninoCall( $appData, $panel, [ 'file' => $guestTrack['id'] ], 'review/comments' );
file_put_contents( $bytes, $kept );
check( 'a file whose bytes are gone has its comments, and says it cannot be played', $gone[0] === 200 && $gone[1]['file']['playable'] === false && count( $gone[1]['comments'] ) >= 1 );

// A file that Files deletes takes its comments with it - in the panel, or with its folder; the comments of the files that stay are what they were
reviewWorkbench( 'admin' );
$doomed = reviewUpload( $appData, $room['id'], 'Doomed.mp3', $mp3 );
$other = reviewUpload( $appData, $room['id'], 'Other.mp3', $mp3 );
\Nino\Modules\Review::add( $appData, $doomed['id'], $byAda, 1.0, 'On a file that goes' );
\Nino\Modules\Review::add( $appData, $other['id'], $byAda, 2.0, 'On a file that stays' );
reviewWorkbench( 'admin' );
$listedBefore = array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'name' );
$dataBefore = reviewData( $appData );
ninoCall( $appData, $panel, [ 'id' => $doomed['id'] ], 'files/delete' );
$listedAfter = array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'name' );
check( 'a file deleted in the panel of the Files feature takes its comments with it: they are gone from the data, the others are what they were, and the list and the comments of the panel do not know the file any more',
	in_array( 'Doomed.mp3', $listedBefore, true ) === true && in_array( 'Doomed.mp3', $listedAfter, true ) === false && isset( reviewData( $appData )[$doomed['id']] ) === false && reviewData( $appData ) === array_diff_key( $dataBefore, [ $doomed['id'] => 1 ] )
	&& ninoCall( $appData, $panel, [ 'file' => $doomed['id'] ], 'review/comments' )[0] === 404 );
$boundFolder = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => 'Short lived' ], 'files/folder/add' )[1]['folder'];
reviewVisitor();
$inFolder = [ reviewUpload( $appData, $boundFolder['id'], 'One.mp3', $mp3 ), reviewUpload( $appData, $boundFolder['id'], 'Two.mp4', $mp4 ) ];
foreach( $inFolder as $each )
	\Nino\Modules\Review::add( $appData, $each['id'], $owner, 1.0, 'In a folder that goes' );
reviewWorkbench( 'admin' );
$withFolder = reviewData( $appData );
ninoCall( $appData, $panel, [ 'id' => $boundFolder['id'] ], 'files/folder/delete' );
check( '...and so do the files of a folder that is deleted with them', count( $withFolder ) === count( reviewData( $appData ) ) + 2 && isset( reviewData( $appData )[$inFolder[0]['id']], reviewData( $appData )[$inFolder[1]['id']] ) === false
	&& array_diff_key( $withFolder, reviewData( $appData ) ) !== [] && reviewData( $appData ) === array_diff_key( $withFolder, [ $inFolder[0]['id'] => 1, $inFolder[1]['id'] => 1 ] ) );
// A comment that is there for a file Files does not have - after a restore of a backup, or written by hand - is not listed either
$strayed = \Nino\Modules\Review\Store::insert( $appData, $doomed['id'], [ 'by' => $owner, 'at' => null, 'text' => 'Left over', 'done' => false, 'created' => '2026-01-01T00:00:00Z', 'page' => '' ], '' );
$listedStray = array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'id' );
check( '...a comment that is there all the same for a file Files does not have - a backup brought it back, or a hand wrote it - is not listed, and its comments are not asked for', is_array( $strayed ) && in_array( $doomed['id'], $listedStray, true ) === false
	&& ninoCall( $appData, $panel, [ 'file' => $doomed['id'] ], 'review/comments' )[0] === 404 && isset( reviewData( $appData )[$doomed['id']] ) === true );
\Nino\Modules\Review\Store::purge( $appData, $doomed['id'] );
\Nino\Modules\Review\Store::insert( $appData, $pack['id'], [ 'by' => $owner, 'at' => null, 'text' => 'On a zip', 'done' => false, 'created' => '2026-01-01T00:00:00Z', 'page' => '' ], '' );
check( '...and nor is one on a file that is no audio or video', in_array( $pack['id'], array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'id' ), true ) === false && ninoCall( $appData, $panel, [ 'file' => $pack['id'] ], 'review/comments' )[0] === 404 );
\Nino\Modules\Review\Store::purge( $appData, $pack['id'] );

// A purge that cannot be made is a warning that names the file by its id and no address, and the comments are what they were: the lock cannot be taken
\Nino\Modules\Review::add( $appData, $other['id'], $byAda, 3.0, 'Still there' );
$locks = \Nino\Filesystem::path( $appData, '/data' ). '/.locks';
$kept = reviewData( $appData );
rename( $locks, $locks. '-kept' );
file_put_contents( $locks, 'not a directory' );
ninoWarnings();
$entry = [ 'id' => $other['id'], 'name' => 'Private@example.org.mp3', 'by' => [ 'kind' => 'member', 'id' => $ada['id'] ] ];
$errors = ninoErrors( static function() use ( &$appData, &$entry ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/files/delete', $entry );
} );
unlink( $locks );
rename( $locks. '-kept', $locks );
check( 'a file whose comments cannot be dropped leaves one warning that names it by its id and no address - and the comments are what they were', count( $errors ) === 1 && $errors[0][0] === E_USER_WARNING && str_contains( $errors[0][1], $other['id'] ) === true && str_contains( $errors[0][1], '@' ) === false
	&& str_contains( $errors[0][1], 'Private' ) === false && str_contains( $errors[0][1], $ada['id'] ) === false && reviewData( $appData ) === $kept );
$nothing = [ 'id' => 'ffffffffffff' ];
$none = ninoErrors( static function() use ( &$appData, &$nothing ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/files/delete', $nothing );
} );
$badEntry = [ 'id' => '../x' ];
$badOnes = ninoErrors( static function() use ( &$appData, &$badEntry ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/files/delete', $badEntry );
} );
check( '...a file that had no comments, and an entry that has no id of a file, are no failure and no warning, and touch nothing', $none === [] && $badOnes === [] && reviewData( $appData ) === $kept );

// The owner's comment
$told = [];
$one = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => "Thanks.\r\nI will look into it", 'at' => '2:30' ], 'review/answer' );
$two = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Answer', 'parent' => $c2['id'] ], 'review/answer' );
$three = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Answer to the answer', 'parent' => $a1['id'], 'at' => ' 0:07 ' ], 'review/answer' );
$data = reviewData( $appData )[$track['id']];
$made = $one[1]['comment'] ?? [];
check( 'the owner\'s comment is stored with by owner, the time typed, the text as text - and answered with the comment, the callback told once with the same', $one[0] === 200 && array_keys( $one[1] ) === [ 'comment' ] && ( $made['by'] ?? null ) === $owner && ( $made['at'] ?? null ) === 150.0
	&& ( $made['text'] ?? null ) === "Thanks.\nI will look into it" && ( $made['parent'] ?? 'x' ) === '' && ( $data[$made['id']] ?? [] )['at'] === 150.0 && ( $data[$made['id']] ?? [] )['text'] === $made['text'] && ( $data[$made['id']] ?? [] )['page'] === '' );
check( '...an answer names its parent, has no time unless one is typed, and an answer to an answer is an answer to the comment that one answers', $two[0] === 200 && $two[1]['comment']['parent'] === $c2['id'] && $two[1]['comment']['at'] === null
	&& $three[0] === 200 && $three[1]['comment']['parent'] === $c1['id'] && $three[1]['comment']['at'] === 7.0 && $three[1]['comment']['by'] === $owner );
check( '...and the callback /review/comment is told of each, with the entry', count( $told ) === 3 && $told[0]['id'] === $made['id'] && $told[0]['file'] === $track['id'] && $told[0]['by'] === $owner && $told[1]['parent'] === $c2['id'] );

$told = [];
$before = reviewData( $appData );
$bad = [
	'an empty text'			=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => "  \n " ], 'review/answer' ),
	'a text that is a list'	=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => [ 'x' ] ], 'review/answer' ),
	'a text over the length' => ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => str_repeat( 'x', 2001 ) ], 'review/answer' ),
	'a time that is none'	=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'x', 'at' => '1m05' ] , 'review/answer' ),
	'a negative time'		=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'x', 'at' => '-5' ] , 'review/answer' ),
	'a parent nobody has'	=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'x', 'parent' => 'ffffffffffff' ], 'review/answer' ),
	'a parent that is none'	=> ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'x', 'parent' => '../x' ], 'review/answer' ),
	'a parent of another file' => ninoCall( $appData, $panel, [ 'file' => $clip['id'], 'text' => 'x', 'parent' => $c1['id'] ], 'review/answer' ),
	'a file nobody has'		=> ninoCall( $appData, $panel, [ 'file' => 'ffffffffffff', 'text' => 'x' ], 'review/answer' ),
	'a zip'					=> ninoCall( $appData, $panel, [ 'file' => $pack['id'], 'text' => 'x' ], 'review/answer' ),
];
check( 'an empty text and a text that is a list are 400 review_empty with the field text, one over the length 400 review_long with the limit and the field, a time that is none or negative 400 review_time with the field at, a parent nobody has, one that is none, one of another file, a file nobody has and a zip 404 review_unknown - and nothing is stored or told',
	array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1]['code']. ' '. ( $answer[1]['field'] ?? '' ), $bad ) === [ 'an empty text' => '400 review_empty text', 'a text that is a list' => '400 review_empty text', 'a text over the length' => '400 review_long text',
		'a time that is none' => '400 review_time at', 'a negative time' => '400 review_time at', 'a parent nobody has' => '404 review_unknown ', 'a parent that is none' => '404 review_unknown ', 'a parent of another file' => '404 review_unknown ',
		'a file nobody has' => '404 review_unknown ', 'a zip' => '404 review_unknown ' ] && $bad['a text over the length'][1]['params'] === [ 2000 ] && reviewData( $appData ) === $before && $told === [] );

// A time of blanks is nothing typed: the comment has none, and the data is what it was
$blankTime = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'A blank time', 'at' => " \t " ], 'review/answer' );
$blankMade = $blankTime[1]['comment'] ?? [];
$tenthTime = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'A tenth', 'at' => '0:02.5' ], 'review/answer' );
$tenthMade = $tenthTime[1]['comment'] ?? [];
\Nino\Modules\Review\Store::remove( $appData, $track['id'], (string) ( $blankMade['id'] ?? '' ) );
\Nino\Modules\Review\Store::remove( $appData, $track['id'], (string) ( $tenthMade['id'] ?? '' ) );
$told = [];
check( 'a time of blanks is no time that was typed: the comment is made without one', $blankTime[0] === 200 && array_key_exists( 'at', $blankMade ) === true && $blankMade['at'] === null && reviewData( $appData ) === $before );
check( '...and a time as the script writes the position of the player keeps its tenth: 0:02.5 is 2.5', $tenthTime[0] === 200 && ( $tenthMade['at'] ?? null ) === 2.5 );

// A write that cannot be made is the server's failure and no refusal of the owner's
$dataPath = \Nino\Filesystem::path( $appData, '/data/review.php' );
$dataBytes = (string) file_get_contents( $dataPath );
unlink( $dataPath );
mkdir( $dataPath );
$writeFail = [];
ninoErrors( static function() use ( &$appData, $panel, $track, &$writeFail ): void {
	$writeFail = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Cannot be written' ], 'review/answer' );
} );
rmdir( $dataPath );
file_put_contents( $dataPath, $dataBytes );
check( 'a write that cannot be made is a 500 without the code of a refusal - nothing told, the data what it was', ( $writeFail[0] ?? 0 ) === 500 && isset( $writeFail[1]['code'] ) === false && reviewData( $appData ) === $before && $told === [] );

// Done
$toggled = [ ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/done' ) ];
$afterOn = reviewData( $appData )[$track['id']][$c1['id']]['done'];
$toggled[] = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/done' );
$afterOff = reviewData( $appData )[$track['id']][$c1['id']]['done'];
check( 'done turns around: done, then open again - the answer has the id and the new state, the data after a fresh read says the same, and nothing else of the comment changes', $toggled[0][0] === 200 && $toggled[0][1] === [ 'comment' => [ 'id' => $c1['id'], 'done' => true ] ]
	&& $toggled[1][1] === [ 'comment' => [ 'id' => $c1['id'], 'done' => false ] ] && $afterOn === true && $afterOff === false && array_diff_assoc( reviewData( $appData )[$track['id']][$c1['id']], $before[$track['id']][$c1['id']] ) === [] );
$notDone = [ ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => 'ffffffffffff' ], 'review/done' ), ninoCall( $appData, $panel, [ 'file' => $clip['id'], 'id' => $c1['id'] ], 'review/done' ), ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => '../x' ], 'review/done' ),
	ninoCall( $appData, $panel, [ 'file' => 'ffffffffffff', 'id' => $c1['id'] ], 'review/done' ), ninoCall( $appData, $panel, [ 'id' => [ $c1['id'] ] ], 'review/done' ) ];
check( 'a comment nobody has, one of another file, something that is no id, a file nobody has and a list are 404 review_unknown - and change nothing', array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1]['code'], $notDone ) ) ) === [ '404 review_unknown' ]
	&& reviewData( $appData ) === $before );
ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/done' );
$openRows = array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'open', 'name' );
check( 'a comment that is done is no longer open in the list: the file has one open comment of a member left', $openRows['Demo.mp3'] === 1 );

// Delete
$mark = reviewData( $appData );
$deleted = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/delete' );
$left = reviewData( $appData )[$track['id']];
check( 'a comment is deleted with its answers - the one the owner wrote and the one that was flattened under it -, that comment and no other, and the answer says how many went',
	$deleted[0] === 200 && $deleted[1] === [ 'deleted' => 3 ] && isset( $left[$c1['id']], $left[$a1['id']], $left[$three[1]['comment']['id']] ) === false && isset( $left[$c2['id']], $left[$made['id']], $left[$two[1]['comment']['id']] ) === true
	&& count( $left ) === count( $mark[$track['id']] ) - 3 && reviewData( $appData )[$clip['id']] === $mark[$clip['id']] );
$again = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $c1['id'] ], 'review/delete' );
$alone = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => $two[1]['comment']['id'] ], 'review/delete' );
check( 'the same comment again and one of another file are 404 review_unknown, an answer on its own is deleted on its own, and a file that has no comment left is not in the data any more', [ $again[0], $again[1]['code'] ] === [ 404, 'review_unknown' ] && $alone[1] === [ 'deleted' => 1 ]
	&& ninoCall( $appData, $panel, [ 'file' => $clip['id'], 'id' => $c2['id'] ], 'review/delete' )[0] === 404 && ninoCall( $appData, $panel, [ 'file' => $track['id'], 'id' => '../x' ], 'review/delete' )[0] === 404
	&& ( function() use ( &$appData, $panel, $clip, $c3 ): bool { ninoCall( $appData, $panel, [ 'file' => $clip['id'], 'id' => $c3['id'] ], 'review/delete' ); return isset( reviewData( $appData )[$clip['id']] ) === false; } )() );

// The log and the tile
$lines = [];
foreach( [ 'review/answer', 'review/done', 'review/delete', 'review/list', 'review/comments' ] as $action )
	$lines[$action] = \Nino\Modules\Review\Admin::log( $action, [ 'file' => $track['id'], 'id' => $c2['id'], 'text' => 'secret text', 'name' => 'ada@example.org', 'email' => 'ada@example.org' ] );
ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Logged one' ], 'review/answer' );
$answerLine = \Nino\Modules\Review\Admin::log( 'review/answer', [ 'file' => $track['id'], 'text' => 'Logged one' ] );
check( 'the activity log has a line for every action that changes something - none for the lists - and neither the address nor the name of a member nor a text in any of them, only the ids of the file and the comment',
	$lines['review/list'] === '' && $lines['review/comments'] === '' && $lines['review/delete'] !== '' && $lines['review/done'] !== '' && str_contains( $lines['review/delete'], $track['id'] ) === true && str_contains( $lines['review/delete'], $c2['id'] ) === true
	&& str_contains( $lines['review/done'], $c2['id'] ) === true && array_filter( $lines, static fn( string $line ): bool => str_contains( $line, 'ada@example.org' ) || str_contains( $line, 'secret' ) ) === []
	&& \Nino\Modules\Review\Admin::log( 'review/delete', [ 'file' => '<script>', 'id' => '../x' ] ) === \Nino\Modules\Review\Admin::log( 'review/delete', [] ) );
check( '...an answer is logged with the id of the comment it made and of its file, and no text', str_contains( $answerLine, $track['id'] ) === true && preg_match( '/Wrote comment [a-f0-9]{12} of file '. $track['id']. '/', $answerLine ) === 1 && str_contains( $answerLine, 'Logged' ) === false );
$tile = \Nino\Modules\Review\Admin::summary( $appData );
$openNow = array_sum( array_column( (array) ninoCall( $appData, $panel, [], 'review/list' )[1]['files'], 'open' ) );
check( 'the Dashboard\'s tile counts the open comments of members, with a label that has its sentence in both languages', $tile['value'] === $openNow && $openNow >= 1
	&& array_filter( [ 'en_US', 'de_DE' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Admin\Admin::textFills( $GLOBALS['appData'], '/features/Review/text', $locale )['[['. \Nino\Modules\Review\Admin::summary( $GLOBALS['appData'] )['label']. ']]'] ?? '' ) ) === '' ) === [] );

// Every code has its sentence in both languages, every word the panel asks for is a fill of both, and the two files declare the same keys
$sources = (string) file_get_contents( $dir. '/Admin/Admin.php' );
preg_match_all( "/'(review_[a-z]+)'/", $sources, $codes );
$codes = array_values( array_unique( $codes[1] ) );
sort( $codes );
$fills = [ 'en_US' => \Nino\Admin\Admin::textFills( $appData, '/features/Review/text', 'en_US' ), 'de_DE' => \Nino\Admin\Admin::textFills( $appData, '/features/Review/text', 'de_DE' ) ];
$missingCodes = [];
foreach( $codes as $code )
	foreach( $fills as $locale => $each )
		if( trim( (string) ( $each['[[/_admin/error/'. $code. ']]'] ?? '' ) ) === '' )
			$missingCodes[] = $code. ' '. $locale;
check( 'the code of every refusal - '. implode( ', ', $codes ). ' - has its sentence in both languages', $codes === [ 'review_empty', 'review_long', 'review_time', 'review_unknown' ] && $missingCodes === [] );
foreach( $fills as $locale => $each )
	check( "...the sentence of review_long in $locale takes its number", str_contains( $each['[[/_admin/error/review_long]]'], '%s' ) === true );

preg_match_all( "~'(/_admin/review/[a-z0-9/-]+)'~", (string) file_get_contents( $dir. '/assets/admin.js' ). $sources, $asked );
$asked = array_values( array_unique( array_diff( $asked[1], [ '/_admin/review/kind/', '/_admin/review/manage' ] ) ) );
$notThere = [];
foreach( array_merge( $asked, [ '/_admin/review/nav', '/_admin/review/kind/audio', '/_admin/review/kind/video' ] ) as $key )
	foreach( $fills as $locale => $each )
		if( isset( $each['[['. $key. ']]'] ) === false || trim( (string) $each['[['. $key. ']]'] ) === '' )
			$notThere[] = $key. ' '. $locale;
check( 'every word the panel\'s script and class ask for ('. count( $asked ) + 3 .') is a fill both languages define, and both files declare the same keys', $notThere === [] && count( $asked ) > 25
	&& array_keys( (array) include $dir. '/text/en_US.php' ) === array_keys( (array) include $dir. '/text/de_DE.php' ) );

$composedPanelWords = [ '/_admin/review/kind/audio', '/_admin/review/kind/video', '/_admin/review/error/load' ];
$panelWords = array_values( array_filter( array_map( static fn( string $key ): string => substr( $key, 2, -2 ), array_keys( (array) include $dir. '/text/en_US.php' ) ), static fn( string $key ): bool => str_starts_with( $key, '/_admin/review/' ) ) );
check( '...and no word is in the panel\'s text files that nothing asks for - the two kinds the script puts together and the line of a failed load, which the shell asks for, apart', count( $panelWords ) > 40 && array_diff( $panelWords, $asked, $composedPanelWords ) === [] );

// The words of the site: the same keys in both languages, every full key the class, the templates and the script name is among them, the
// words that are put together from a state, a kind or a kind of mail are, and no word is left that nothing asks for
$siteEn = array_keys( (array) include $dir. '/install/text/en_US.php' );
$siteDe = array_keys( (array) include $dir. '/install/text/de_DE.php' );
$named = [];
foreach( array_merge( glob( $dir. '/templates/*.tpl' ) ?: [], [ $dir. '/Review.php', $dir. '/assets/review.js' ] ) as $source )
	if( preg_match_all( "~/feature/review/([a-z0-9-]+/[a-z0-9-]+)(?![a-z0-9/\[-])~", (string) file_get_contents( $source ), $keys ) > 0 )
		$named = array_merge( $named, $keys[1] );
$named = array_map( static fn( string $key ): string => '[[/feature/review/'. $key. ']]', array_values( array_unique( $named ) ) );
$composed = array_map( static fn( string $key ): string => '[[/feature/review/'. $key. ']]', [ 'state/sent', 'state/long', 'state/limit', 'state/expired', 'state/invalid', 'kind/audio', 'kind/video',
	'mail/comment-subject', 'mail/comment-about-subject', 'mail/comment-button', 'mail/comment-button-card', 'mail/answer-subject', 'mail/note-subject', 'script/answering', 'script/cancel' ] );
// The words a unit keeps for the sites that took them before: nothing asks for them any more, and a site that has them keeps them
$retired = [ '[[/feature/review/mail/answer-notice]]' ];
check( 'the unit brings the words of the site in both languages with the same keys; every word the class, the templates and the script name in full is among them, and so are the five states, the two kinds, the three subjects of the mails and the two words of the script',
	$siteEn === $siteDe && count( $named ) > 20 && array_diff( array_merge( $named, $composed ), $siteEn ) === [] );
check( '...and no word is in the unit that nothing asks for, but the ones it keeps for the sites that have them', array_diff( $siteEn, $named, $composed, $retired ) === [] && array_diff( $retired, $siteEn ) === [] );

reviewVisitor();
echo "\n";


// --- The mails -----------------------------------------------------------------------

echo "The mails - to the owner when a member writes, to a member or a group when the owner does\n";

\Nino\Filesystem::putFileContent( $appData, '/data/review.php', [] );

/**
 *	The owner's comment through the panel's action
 *
 *	@param		array 		$fields				file, text, parent, at
 *
 *	@return 	array										[ status, body ]
 */
function reviewOwnerWrites( array $fields ): array {

	global $appData, $panel;

	reviewWorkbench( 'admin' );
	reviewFresh( $appData );

	return ninoCall( $appData, $panel, $fields, 'review/answer' );
}

/**
 *	The text of a mail: the body without its tags
 *
 *	@param		array 		$mail
 *
 *	@return 	string
 */
function reviewMailText( array $mail ): string {

	return html_entity_decode( strip_tags( (string) ( $mail['body'] ?? '' ) ) );
}

$mails = [];
reviewFresh( $appData );
$off = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Silent', 'at' => '0:10' ] );
check( 'with the setting notifyOwner off - the default - a member\'s comment sends no mail', $off[1] === '/club/review?review='. $track['id']. '&review-state=sent' && $mails === [] );

\Nino\Features::saveSettings( $appData, 'review', [ 'notifyOwner' => true ] );
$mails = [];
reviewFresh( $appData );
$on = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => "Snare is late\nat 1:05", 'at' => '1:05' ] );
$mail = $mails[0] ?? [];
$text = reviewMailText( $mail );
check( 'with it on one mail goes to the contact address of the website after a member\'s comment: the file named, the time, the text, the member, a link to the panel on the website\'s address, no key or token left in it',
	$on[0] === 303 && count( $mails ) === 1 && $mail['to'] === 'owner@example.org' && trim( (string) $mail['subject'] ) !== '' && str_contains( (string) $mail['subject'], '[[' ) === false && str_contains( (string) $mail['body'], '[[' ) === false
	&& str_contains( $text, 'Demo.mp3' ) === true && str_contains( $text, '1:05' ) === true && str_contains( $text, 'Snare is late' ) === true && str_contains( $text, 'Ada Lovelace' ) === true
	&& str_contains( (string) $mail['body'], 'href="https://example.org/club/_admin#review"' ) === true && count( reviewFind( (string) $mail['body'], '//br' ) ) >= 1 );
$mails = [];
reviewFresh( $appData );
reviewPost( 'cyd', [ 'file' => $track['id'], 'text' => 'No time here' ] );
check( '...a comment without a time says so, and a member without a name is told by the address to the owner, who knows it', str_contains( reviewMailText( $mails[0] ?? [] ), (string) \Nino\Html::resolveTextfill( $appData, '/feature/review/mail/no-time' ) ) === true && str_contains( reviewMailText( $mails[0] ?? [] ), 'cyd@example.org' ) === true );
check( '...and the subject is the one of the unit, the mail is in the site\'s language (the native one, German here)', ( $mails[0]['subject'] ?? '' ) === (string) \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/comment-subject]]'] );

$mails = [];
reviewFresh( $appData );
reviewPost( 'ada', [ 'file' => $track['id'], 'text' => '<b>bold<b> [[company]] [review-player]', 'at' => '0:01' ] );
check( 'a text is text in the mail: markup in it is not an element, and a key or a shortcode in it is neither filled in nor run', count( $mails ) === 1 && reviewFind( (string) $mails[0]['body'], '//b' ) === [] && str_contains( (string) $mails[0]['body'], '[[company]]' ) === false
	&& str_contains( (string) $mails[0]['body'], '&lt;b&gt;bold' ) === true && str_contains( reviewMailText( $mails[0] ), '[[company]] [review-player]' ) === true && count( reviewFind( (string) $mails[0]['body'], '//form' ) ) === 0 );

$mails = [];
$delivery = false;
reviewFresh( $appData );
ninoWarnings();
$unsent = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Unsent one' ] );
$delivery = true;
$unsentWarnings = ninoWarnings();
$unsentId = (string) array_key_last( reviewData( $appData )[$track['id']] );
check( 'a mail no transport takes does not undo the comment: it is stored, the answer is the same', $unsent[1] === '/club/review?review='. $track['id']. '&review-state=sent' && count( $mails ) === 1 && reviewData( $appData )[$track['id']][$unsentId]['text'] === 'Unsent one' );
check( '...and it leaves a trace: one warning that names the comment and the file by their ids and holds no address', count( $unsentWarnings ) === 1 && str_contains( $unsentWarnings[0], $unsentId ) === true && str_contains( $unsentWarnings[0], $track['id'] ) === true && str_contains( $unsentWarnings[0], '@' ) === false );

// ...and so does a mail the kernel's cap of mails an hour refuses: nothing is sent, the comment is in, one warning
reviewFresh( $appData );

for( $filler = 0; $filler < 5; $filler++ )
	\Nino\Mail::send( $appData, 'filler@example.org', 'Filler', 'Filler', '' );

$mails = [];
ninoWarnings();
$capped = reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Capped one' ] );
$cappedWarnings = ninoWarnings();
check( '...a mail the cap of mails an hour refuses is told the same way: nothing was sent, the comment is in, and one warning names it by its ids without an address', $capped[0] === 303 && $mails === [] && \Nino\Mail::rateLimited( $appData ) === true
	&& count( $cappedWarnings ) === 1 && str_contains( $cappedWarnings[0], (string) array_key_last( reviewData( $appData )[$track['id']] ) ) === true && str_contains( $cappedWarnings[0], '@' ) === false );
reviewFresh( $appData );

ninoWarnings();
$mails = [];
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => '' ], '*' );
reviewPost( 'ada', [ 'file' => $track['id'], 'text' => 'Nobody to tell' ] );
$noContact = $mails;
$warned = ninoWarnings();
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
check( 'a website with no contact address sends none, and says so in a warning for the log that names no address - and no comment: no mail was tried, so it is not the warning of a mail that failed',
	$noContact === [] && count( $warned ) >= 1 && array_filter( $warned, static fn( string $warning ): bool => str_contains( $warning, '@' ) || str_contains( $warning, (string) array_key_last( reviewData( $GLOBALS['appData'] )[$GLOBALS['track']['id']] ) ) ) === [] );
\Nino\Features::saveSettings( $appData, 'review', [ 'notifyOwner' => false ] );

// To a member, to a group
$active = [ 'eve' => $eve, 'dan' => \Nino\Modules\Members::add( $appData, 'dan2@example.org', 'Dan', 'staff' ), 'fay' => \Nino\Modules\Members::add( $appData, 'fay@example.org', 'Fay', 'staff' ), 'gus' => \Nino\Modules\Members::add( $appData, 'gus@example.org', 'Gus', 'staff' ),
	'hal' => \Nino\Modules\Members::add( $appData, 'hal@example.org', 'Hal', 'staff' ), 'ivy' => \Nino\Modules\Members::add( $appData, 'ivy@example.org', 'Ivy', 'staff' ) ];
$off = \Nino\Modules\Members::add( $appData, 'off@example.org', 'Off', 'staff' );
\Nino\Modules\Members::change( $appData, $off['id'], [ 'active' => false ] );
\Nino\Filesystem::putFileContent( $appData, '/data/review.php', [] );
$m1 = \Nino\Modules\Review::add( $appData, $track['id'], $byAda, 65.0, 'Needs work', '', '/club/review' );
$m2 = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, null, 'No page on this one' );
$o1 = \Nino\Modules\Review::add( $appData, $track['id'], $owner, null, 'My own note' );
$m3 = \Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => $active['dan']['id'] ], 5.0, 'From Dan', '', '/club/other' );
$mails = [];
$silent = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Quiet answer', 'parent' => $m1['id'] ] );
check( 'with the setting notifyMembers off - the default - the owner\'s answer sends no mail', $silent[0] === 200 && $mails === [] );

$mails = [];
check( '...nothing to send is no failure: with the setting off, and with no member to tell, the answer is true, and so for the owner with the setting off no mail was delivered: false',
	\Nino\Modules\Review::notifyMembers( $appData, $track, $o1, $m1 ) === true && \Nino\Modules\Review::notifyOwner( $appData, $track, $m1, $ada ) === false && $mails === [] );

\Nino\Features::saveSettings( $appData, 'review', [ 'notifyMembers' => true ] );
$mails = [];
$answered = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => "Fixed now.\nThanks!", 'parent' => $m1['id'], 'at' => '1:06' ] );
$forAda = $mails[0] ?? [];
$adaText = reviewMailText( $forAda );
check( 'with it on the owner\'s answer sends one mail to the member whose comment it answers - and to nobody else: the file named, the time, the text of the answer, the member greeted by name, a link to the website, the reply address of the contact',
	$answered[0] === 200 && count( $mails ) === 1 && ( $forAda['to'] ?? '' ) === 'ada@example.org' && trim( (string) $forAda['subject'] ) !== '' && str_contains( (string) $forAda['body'], '[[' ) === false && str_contains( $adaText, 'Demo.mp3' ) === true && str_contains( $adaText, '1:06' ) === true
	&& str_contains( $adaText, 'Fixed now.' ) === true && str_contains( $adaText, 'Ada Lovelace' ) === true && ( $forAda['replyTo'] ?? '' ) === 'owner@example.org' && count( reviewFind( (string) $forAda['body'], '//br' ) ) >= 1 );
check( '...the link leads to the page that showed the player when the comment answered was written, with the file chosen', str_contains( (string) $forAda['body'], 'href="https://example.org/club/club/review?review='. $track['id']. '"' ) === true );
check( '...and the subject is that of an answer, in the native language of the site', ( $forAda['subject'] ?? '' ) === (string) \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/answer-subject]]'] );
$introAnswer = (string) \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/answer-intro]]'];
$introNote = (string) \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/note-intro]]'];
reviewMailWordsChecks( $appData, $track, $m1, $ada, $adaText );
check( '...it opens with the sentence for an answer and not with the one for a comment of the owner\'s own', $introAnswer !== $introNote && trim( $introAnswer ) !== '' && str_contains( $adaText, $introAnswer ) === true && str_contains( $adaText, $introNote ) === false );

$mails = [];
$deeper = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'To Dan', 'parent' => $m3['id'] ] );
check( 'the link of an answer to another comment is the page that one was written on', $deeper[0] === 200 && count( $mails ) === 1 && $mails[0]['to'] === 'dan2@example.org' && str_contains( (string) $mails[0]['body'], 'href="https://example.org/club/club/other?review='. $track['id']. '"' ) === true );

// An answer to an answer is mailed to the author of the answer it answers, though it is filed under the comment
$memberAnswer = \Nino\Modules\Review::add( $appData, $track['id'], $byCyd, null, 'A member answers', $m1['id'], '/club/review' );
$mails = [];
$toAnswer = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'To the answer', 'parent' => $memberAnswer['id'] ] );
check( 'an answer to the answer of a member is mailed to that member and not to the author of the comment it is filed under', $toAnswer[0] === 200 && count( $mails ) === 1 && $mails[0]['to'] === 'cyd@example.org'
	&& $toAnswer[1]['comment']['parent'] === $m1['id'] && str_contains( (string) $mails[0]['body'], 'href="https://example.org/club/club/review?review='. $track['id']. '"' ) === true );

$mails = [];
$toOwner = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'To myself', 'parent' => $o1['id'] ] );
check( 'an answer to a comment of the owner\'s own has nobody to tell, and is no failure', $toOwner[0] === 200 && $mails === [] );

$mails = [];
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => false ] );
$toInactive = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'To an account that is off', 'parent' => $m1['id'] ] );
$inactiveMails = $mails;
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'active' => true ] );
$goneMember = \Nino\Modules\Members::add( $appData, 'leaver@example.org', 'Leaver', 'staff' );
$leaversComment = \Nino\Modules\Review::add( $appData, $track['id'], [ 'kind' => 'member', 'id' => $goneMember['id'] ], null, 'I leave' );
\Nino\Modules\Members::remove( $appData, $goneMember['id'] );
$mails = [];
$toGone = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'To an account that is gone', 'parent' => $leaversComment['id'] ] );
check( 'an answer to a member whose account is switched off, or is gone, tells nobody - and is stored all the same', $toInactive[0] === 200 && $toGone[0] === 200 && $inactiveMails === [] && $mails === [] && count( array_filter( reviewData( $appData )[$track['id']], static fn( array $comment ): bool => $comment['text'] === 'To an account that is gone' ) ) === 1 );

// A comment of the owner's own on the file: the group
$mails = [];
$note = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'A word for everybody', 'at' => '0:30' ] );
$recipients = array_column( $mails, 'to' );
sort( $recipients );
$forDan = array_values( array_filter( $mails, static fn( array $each ): bool => $each['to'] === 'dan2@example.org' ) )[0] ?? [];
check( 'the owner\'s comment of its own on a visible file is mailed to each active member of the group of its folder - not to the members of other groups, not to an account that is switched off',
	$note[0] === 200 && $recipients === [ 'ada@example.org', 'cyd@example.org', 'dan2@example.org', 'eve@example.org', 'fay@example.org', 'gus@example.org', 'hal@example.org', 'ivy@example.org' ] && count( $mails ) === 8 );
check( '...each names the file, the time and the text, greets the member by name, opens with the sentence for a comment of its own, links the start page of the Members feature and carries the reply address - with the subject of a comment on a file', str_contains( reviewMailText( $forDan ), $introNote ) === true && str_contains( reviewMailText( $forDan ), $introAnswer ) === false && str_contains( reviewMailText( $forDan ), 'Demo.mp3' ) === true && str_contains( reviewMailText( $forDan ), 'A word for everybody' ) === true
	&& str_contains( reviewMailText( $forDan ), 'Dan' ) === true && str_contains( (string) $forDan['body'], 'href="https://example.org/club/"' ) === true && $forDan['replyTo'] === 'owner@example.org' && str_contains( (string) $forDan['body'], '[[' ) === false
	&& $forDan['subject'] === (string) \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/note-subject]]'] );

\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/members-area' ] );
$mails = [];
reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Start page' ] );
\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/' ] );
check( '...and the link is the start page of the Members feature, the setting start', count( $mails ) === 8 && str_contains( (string) $mails[0]['body'], 'href="https://example.org/club/members-area"' ) === true );

$mails = [];
$onHidden = reviewOwnerWrites( [ 'file' => $secret['id'], 'text' => 'On a file nobody sees' ] );
check( 'a comment of its own on a file that is hidden from the group tells nobody: the members cannot open it', $onHidden[0] === 200 && $mails === [] );

$appData['./nino/locales/current'] = 'en_US';
$mails = [];
reviewWorkbench( 'admin' );
reviewFresh( $appData );
ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Language check', 'parent' => $m1['id'] ], 'review/answer' );
$native = \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/review/mail/answer-subject]]'];
$other = \Nino\Html::getFills( $appData, 'en_US' )['[[/feature/review/mail/answer-subject]]'];
$currentAfter = $appData['./nino/locales/current'];
$appData['./nino/locales/current'] = 'de_DE';
check( 'the mail goes out in the native language of the site, and the language of the request is back afterwards', $native !== $other && ( $mails[0]['subject'] ?? '' ) === $native && $currentAfter === 'en_US' );

$mails = [];
reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'The whole group' ] );
check( 'a group of eight is mailed in full, though the default cap of the kernel is five mails an hour from one address: one comment is one hit of the cap', count( $mails ) === 8 && \Nino\Mail::rateLimited( $appData ) === false );

$mails = [];
\Nino\Modules\Members::change( $appData, $eve['id'], [ 'name' => '<u>under<u> [[company]]' ] );
reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Names are text' ] );
$forEve = array_values( array_filter( $mails, static fn( array $each ): bool => $each['to'] === 'eve@example.org' ) )[0] ?? [];
check( 'the name of a member is text in the mail to them: markup in it is not an element, and a key in it is not filled in', $forEve !== [] && reviewFind( (string) $forEve['body'], '//u' ) === [] && str_contains( (string) $forEve['body'], '[[company]]' ) === false && str_contains( (string) $forEve['body'], '&lt;u&gt;under' ) === true );

$mails = [];
reviewFresh( $appData );
$delivery = false;
ninoWarnings();
$undelivered = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Undelivered' ] );
$delivery = true;
$undeliveredWarnings = ninoWarnings();
check( 'mails no transport takes do not undo the comment either - and they leave one warning for the lot, which names the comment and the file by their ids and holds no address', $undelivered[0] === 200 && count( $undeliveredWarnings ) === 1
	&& str_contains( $undeliveredWarnings[0], (string) $undelivered[1]['comment']['id'] ) === true && str_contains( $undeliveredWarnings[0], $track['id'] ) === true && str_contains( $undeliveredWarnings[0], '@' ) === false );

// ...and when the cap of mails an hour refuses the whole call, the same
reviewWorkbench( 'admin' );
reviewFresh( $appData );

for( $filler = 0; $filler < 5; $filler++ )
	\Nino\Mail::send( $appData, 'filler@example.org', 'Filler', 'Filler', '' );

$mails = [];
ninoWarnings();
$cappedGroup = ninoCall( $appData, $panel, [ 'file' => $track['id'], 'text' => 'Capped group' ], 'review/answer' );
$cappedGroupWarnings = ninoWarnings();
check( '...a call the cap refuses sends none of the mails and leaves one warning with the ids and no address', $cappedGroup[0] === 200 && $mails === [] && \Nino\Mail::rateLimited( $appData ) === true && count( $cappedGroupWarnings ) === 1
	&& str_contains( $cappedGroupWarnings[0], (string) $cappedGroup[1]['comment']['id'] ) === true && str_contains( $cappedGroupWarnings[0], '@' ) === false );
reviewFresh( $appData );

ninoWarnings();
$mails = [];
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
$noAddress = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'No address' ] );
$warnedNoAddress = ninoWarnings();
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
check( 'a site without an address has no link to put in a mail: none is sent, the comment is in, and a warning says so without naming a member', $noAddress[0] === 200 && $mails === [] && count( $warnedNoAddress ) >= 1 && array_filter( $warnedNoAddress, static fn( string $warning ): bool => str_contains( $warning, '@' ) ) === [] );
\Nino\Features::saveSettings( $appData, 'review', [ 'notifyMembers' => false ] );

// The owner's choice for one answer: notify true mails whatever the setting says, false mails nobody whatever it says, absent - or anything but a bool - is the setting
$choice = static function( bool $setting, array $extra ) use ( $track, $m1, &$mails ): int {

	\Nino\Features::saveSettings( $GLOBALS['appData'], 'review', [ 'notifyMembers' => $setting ] );
	$mails = [];
	reviewOwnerWrites( $extra + [ 'file' => $track['id'], 'text' => 'Choice', 'parent' => $m1['id'] ] );

	return count( $mails );
};
check( 'the answer takes notify: true mails the member with the setting off, false mails nobody with it on, absent is the setting either way',
	$choice( false, [ 'notify' => true ] ) === 1 && $choice( true, [ 'notify' => false ] ) === 0 && $choice( false, [] ) === 0 && $choice( true, [] ) === 1 );
check( '...and a notify that is no bool is the setting', $choice( false, [ 'notify' => 'true' ] ) === 0 && $choice( true, [ 'notify' => 'false' ] ) === 1 && $choice( true, [ 'notify' => 0 ] ) === 1 );
$groupMails = $choice( false, [ 'notify' => true, 'parent' => '' ] );
check( '...a comment of its own for the group takes it the same way', $groupMails >= 7 && $choice( true, [ 'notify' => false, 'parent' => '' ] ) === 0 );
check( '...and the answer is stored whether the member is mailed or not', count( array_filter( reviewData( $GLOBALS['appData'] )[$track['id']], static fn( array $comment ): bool => $comment['text'] === 'Choice' ) ) >= 8 );

$quietOne = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Logged quietly', 'parent' => $m1['id'], 'notify' => false ] );
$quietLine = \Nino\Modules\Review\Admin::log( 'review/answer', [ 'file' => $track['id'], 'text' => 'x', 'notify' => false ] );
$loudOne = reviewOwnerWrites( [ 'file' => $track['id'], 'text' => 'Logged loudly', 'parent' => $m1['id'] ] );
$loudLine = \Nino\Modules\Review\Admin::log( 'review/answer', [ 'file' => $track['id'], 'text' => 'x' ] );
check( 'the line of the log for an answer that was not mailed says so, and holds no address; one that was left to the setting does not', $quietOne[0] === 200 && $loudOne[0] === 200 && str_contains( $quietLine, 'not mailed' ) === true && str_contains( $loudLine, 'not mailed' ) === false && str_contains( $quietLine, '@' ) === false );

\Nino\Features::saveSettings( $appData, 'review', [ 'notifyMembers' => true ] );
reviewWorkbench( 'admin' );
reviewFresh( $appData );
$seen = ninoCall( $appData, $panel, [ 'file' => $track['id'] ], 'review/comments' );
\Nino\Features::saveSettings( $appData, 'review', [ 'notifyMembers' => false ] );
reviewFresh( $appData );
$seenOff = ninoCall( $appData, $panel, [ 'file' => $track['id'] ], 'review/comments' );
check( 'the comments of a file say the setting as notify, for the box of the form to start from', ( $seen[1]['notify'] ?? null ) === true && ( $seenOff[1]['notify'] ?? null ) === false );
reviewVisitor();

echo "\n";


// --- Restore, deactivation ------------------------------------------------------------

echo "The restore of a backup, and deactivation\n";

/**
 *	A staging directory that can be read and not written, for the restore that cannot put its filtered comments back: the stream
 *	wrapper "reviewblock" answers the staged file and refuses every open for writing. (A permission cannot do this here - the
 *	suite may run as the account that owns every file)
 */
class ReviewBlockedStage {

	public mixed $context = null;
	public static string $data = '';
	private int $at = 0;

	public function url_stat( string $path, int $flags ): array|false {
		return str_ends_with( $path, '/data/review.php' ) === true ? [ 'mode' => 0100644, 'size' => strlen( self::$data ) ] : false;
	}

	public function stream_open( string $path, string $mode, int $options, ?string &$opened ): bool {
		$this->at = 0;
		return str_starts_with( $mode, 'r' ) === true;
	}

	public function stream_read( int $count ): string|false {
		$chunk = substr( self::$data, $this->at, $count );
		$this->at += strlen( $chunk );
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->at >= strlen( self::$data );
	}

	public function stream_stat(): array|false {
		return [ 'mode' => 0100644, 'size' => strlen( self::$data ) ];
	}

	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}
}

stream_wrapper_register( 'reviewblock', 'ReviewBlockedStage' );

$comment = static fn( string $text ): array => [ 'by' => [ 'kind' => 'owner', 'id' => '' ], 'at' => 1.5, 'text' => $text, 'parent' => '', 'done' => false, 'created' => '2026-01-01T00:00:00Z', 'page' => '' ];
$dataDir = \Nino\Filesystem::path( $appData, '/data' );
$gone = 'aaaaaaaaaaaa';
$bytesGone = 'bbbbbbbbbbbb';
$digits = '567890123456';
file_put_contents( $dataDir. '/files/'. $digits. '.mp3', 'abc' );

// A backup brings the comments and the index of its day, and not the bytes: what a restore keeps is what the index has with bytes
$staging = $sandbox. '/restore-staging';
mkdir( $staging. '/data', 0755, true );
$stagedIndex = [ 'folders' => [ $room['id'] => [ 'group' => 'staff', 'name' => 'Room', 'upload' => false, 'created' => '2026-01-01T00:00:00Z' ] ], 'files' => [
	$track['id'] => [ 'folder' => $room['id'], 'name' => 'Demo.mp3', 'ext' => 'mp3', 'size' => 4004, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
	$bytesGone => [ 'folder' => $room['id'], 'name' => 'Gone.mp3', 'ext' => 'mp3', 'size' => 5, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
	'cccccccccccc' => [ 'folder' => $room['id'], 'name' => 'Hostile.mp3', 'ext' => '../x', 'size' => 5, 'mime' => '', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
	// An id of digits only, which php reads back as a whole number: its bytes are there, so it stays
	$digits => [ 'folder' => $room['id'], 'name' => 'Digits.mp3', 'ext' => 'mp3', 'size' => 3, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
] ];
$stagedComments = [ $track['id'] => [ 'dddddddddddd' => $comment( 'Kept' ), 'eeeeeeeeeeee' => $comment( 'Kept too' ) ], $gone => [ 'dddddddddddd' => $comment( 'File not in the index' ) ], $bytesGone => [ 'dddddddddddd' => $comment( 'Bytes gone' ) ],
	'cccccccccccc' => [ 'dddddddddddd' => $comment( 'Hostile extension' ) ], $digits => [ 'dddddddddddd' => $comment( 'Digits' ) ] ];
file_put_contents( $staging. '/data/files.php', '<?php return '. var_export( $stagedIndex, true ). ';' );
file_put_contents( $staging. '/data/review.php', '<?php return '. var_export( $stagedComments, true ). ';' );
$args = [ 'dataDir' => $dataDir, 'staging' => $staging ];
reviewTry( static function() use ( &$appData, &$args ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $args );
} );
$restored = (array) include $staging. '/data/review.php';
check( 'a restore keeps the comments of the backup on the files its index has with their bytes in the live directory (an id of digits among them) and drops the rest - a file the index lacks, one whose bytes are gone and one with an extension that is none',
	array_map( 'strval', array_keys( $restored ) ) === [ $track['id'], $digits ] && $restored[$track['id']] === $stagedComments[$track['id']] && $restored[$digits] === $stagedComments[$digits] );
$indexAfter = (array) include $staging. '/data/files.php';
check( '...and the index of the backup is the Files feature\'s to rewrite, which it does for the files whose bytes are gone: the entries whose bytes are there stay in it', isset( $indexAfter['files'][$track['id']], $indexAfter['files'][$digits] ) === true && isset( $indexAfter['files'][$bytesGone] ) === false );

// Review's half of it on its own: Files rewrites the staged index in its callback before Review reads it, so the rules Review applies itself - the
// bytes in the live directory, an extension that is one, what it writes back - are pinned on the index as the backup brought it
$trap = $dataDir. '/files/cccccccccccc.mp3';
mkdir( $trap );
$sneaky = $stagedIndex;
$sneaky['files']['cccccccccccc']['ext'] = 'mp3/../'. $track['id']. '.mp3';
$alone = $sandbox. '/restore-alone';
mkdir( $alone. '/data', 0755, true );
$withBad = $stagedComments;
$withBad[$track['id']]['bad'] = $comment( 'Not an id' );
file_put_contents( $alone. '/data/files.php', '<?php return '. var_export( $sneaky, true ). ';' );
file_put_contents( $alone. '/data/review.php', '<?php return '. var_export( $withBad, true ). ';' );
$aloneArgs = [ 'dataDir' => $dataDir, 'staging' => $alone ];
file_put_contents( $dataDir. '/files/'. $digits. '.mp3', 'abc' );
reviewTry( static function() use ( &$appData, &$aloneArgs ): void {
	\Nino\Modules\Review::callbackRestore( $appData, $aloneArgs );
} );
rmdir( $trap );
unlink( $dataDir. '/files/'. $digits. '.mp3' );
$aloneRestored = (array) include $alone. '/data/review.php';
$aloneIndex = (array) include $alone. '/data/files.php';
check( 'on the index as the backup brought it Review keeps the comments of the files whose bytes are in the live directory and drops the rest: a file the index lacks, one whose bytes are gone and one whose extension is a way out of the directory - and what it writes back is read for what it is: an entry that is no comment is not carried along',
	array_map( 'strval', array_keys( $aloneRestored ) ) === [ $track['id'], $digits ] && $aloneRestored[$track['id']] === $stagedComments[$track['id']] && $aloneRestored[$digits] === $stagedComments[$digits] );
check( '...and the index of the backup is not Review\'s to touch', $aloneIndex === $sneaky );

// A file that was removed from the server has no bytes and keeps its comments: its entry says so in the backup - or, for a file removed since the day of the backup, the live index does
$removedStage = $sandbox. '/restore-removed';
mkdir( $removedStage. '/data', 0755, true );
$removedIndex = $stagedIndex;
$removedIndex['files']['eeeeeeeeeeee'] = [ 'folder' => $room['id'], 'name' => 'Removed.mp3', 'ext' => 'mp3', 'size' => 5, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '', 'gone' => '2026-02-02T02:02:02Z' ];
$removedIndex['files'][$vanished['id']] = array_diff_key( $vanished, [ 'id' => 1, 'gone' => 1 ] );
$removedComments = [ 'eeeeeeeeeeee' => [ 'dddddddddddd' => $comment( 'Removed then' ) ], $vanished['id'] => [ 'dddddddddddd' => $comment( 'Removed since' ) ], $bytesGone => [ 'dddddddddddd' => $comment( 'Bytes gone' ) ] ];
file_put_contents( $removedStage. '/data/files.php', '<?php return '. var_export( $removedIndex, true ). ';' );
file_put_contents( $removedStage. '/data/review.php', '<?php return '. var_export( $removedComments, true ). ';' );
$removedArgs = [ 'dataDir' => $dataDir, 'staging' => $removedStage ];
reviewTry( static function() use ( &$appData, &$removedArgs ): void {
	\Nino\Modules\Review::callbackRestore( $appData, $removedArgs );
} );
$removedRestored = (array) include $removedStage. '/data/review.php';
check( 'a restore keeps the comments on a file that was removed from the server - the entry of the backup says so, or the live index does for a file removed since - and drops those on a file whose bytes are gone with no removal behind it',
	array_map( 'strval', array_keys( $removedRestored ) ) === [ 'eeeeeeeeeeee', $vanished['id'] ] && $removedRestored[$vanished['id']] === $removedComments[$vanished['id']] );
unlink( $removedStage. '/data/files.php' );
file_put_contents( $removedStage. '/data/review.php', '<?php return '. var_export( $removedComments + [ 'ffffffffffff' => [ 'dddddddddddd' => $comment( 'Nobody has it' ) ] ], true ). ';' );
reviewTry( static function() use ( &$appData, &$removedArgs ): void {
	\Nino\Modules\Review::callbackRestore( $appData, $removedArgs );
} );
check( '...without an index in the backup the live one is the measure for it as well: the file removed there keeps its comments', array_map( 'strval', array_keys( (array) include $removedStage. '/data/review.php' ) ) === [ $vanished['id'] ] );

// A backup that carries no index of the files: the files the live Files feature has are the measure
file_put_contents( $staging. '/data/review.php', '<?php return '. var_export( [ $track['id'] => [ 'dddddddddddd' => $comment( 'Live' ) ], 'ffffffffffff' => [ 'dddddddddddd' => $comment( 'Nobody has it' ) ], $pack['id'] => [ 'dddddddddddd' => $comment( 'A zip is a file too' ) ], $lost['id'] => [ 'dddddddddddd' => $comment( 'Bytes gone live' ) ] ], true ). ';' );
unlink( $staging. '/data/files.php' );
$lostPath = (string) \Nino\Modules\Files::path( $appData, $lost['id'] );
unlink( $lostPath );
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $args );
file_put_contents( $lostPath, $lostBytes );
$restored = (array) include $staging. '/data/review.php';
check( 'without an index in the backup, the live Files feature decides: the comment on a file it has stays, one on a file nobody has goes, one whose bytes are gone goes', array_map( 'strval', array_keys( $restored ) ) === [ $track['id'], $pack['id'] ] && is_file( $staging. '/data/files.php' ) === false );

// Nothing to drop: the staged file is not touched
$same = "<?php\n// edited by hand\nreturn ". var_export( [ $track['id'] => [ 'dddddddddddd' => $comment( 'Fine' ) ] ], true ). ";\n";
file_put_contents( $staging. '/data/review.php', $same );
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $args );
check( 'a backup whose comments are all on files that are there is left byte for byte as it was - in whatever form it was written', file_get_contents( $staging. '/data/review.php' ) === $same );

// A staged file that can be read and not written: the filtered comments cannot be put back. The write is not silenced - php says so in a warning - and the staged file is what it was
ReviewBlockedStage::$data = '<?php return '. var_export( [ 'ffffffffffff' => [ 'dddddddddddd' => $comment( 'Nobody has it' ) ] ], true ). ';';
$blockedBefore = ReviewBlockedStage::$data;
$blockedArgs = [ 'dataDir' => $dataDir, 'staging' => 'reviewblock://stage' ];
$blockedErrors = ninoErrors( static function() use ( &$appData, &$blockedArgs ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $blockedArgs );
} );
check( 'a restore that cannot write the comments it filtered says so - php\'s own warning, not silence - and the staged file stays as it was', count( $blockedErrors ) === 1 && $blockedErrors[0][0] === E_WARNING && str_contains( $blockedErrors[0][1], 'file_put_contents' ) === true
	&& ReviewBlockedStage::$data === $blockedBefore );

$untouched = $sandbox. '/restore-empty';
mkdir( $untouched. '/data', 0755, true );
$noArgs = [ 'dataDir' => $dataDir, 'staging' => $untouched ];
$noStaging = [ 'dataDir' => $dataDir, 'staging' => '' ];
$quiet = ninoErrors( static function() use ( &$appData, &$noArgs, &$noStaging ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $noArgs );
	\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $noStaging );
} );
check( 'a backup without comments, and a call without a staging directory, change nothing, create nothing and say nothing: no warning of a file that is not there', scandir( $untouched ) === [ '.', '..', 'data' ] && scandir( $untouched. '/data' ) === [ '.', '..' ] && $quiet === [] );
unlink( $dataDir. '/files/'. $digits. '.mp3' );

// Deactivation takes the panel away and keeps what the project has
$kept = [ 'comments' => reviewData( $appData ), 'counter' => is_file( $dataDir. '/review-tries.php' ) ];
reviewWorkbench( 'admin' );
check( 'the features Review needs cannot be switched off from under it: Files and Members are refused while Review is active', is_string( \Nino\Features::deactivate( $appData, 'files' ) ) === true && is_string( \Nino\Features::deactivate( $appData, 'members' ) ) === true );
check( 'deactivation succeeds', \Nino\Features::deactivate( $appData, 'review' ) === true );
check( '...the panel is not in the registry any more, and its actions are unknown', isset( \Nino\Admin\Admin::panels( $appData )['review'] ) === false && ninoCall( $appData, $panel, [], 'review/list' )[0] === 404 );
check( '...the comments and the counter stay as they were', $kept['comments'] === reviewData( $appData ) && $kept['comments'] !== [] && $kept['counter'] === is_file( $dataDir. '/review-tries.php' ) );
check( '...and the class is out of /nino/modules, while Files is still in', in_array( '\\Nino\\Modules\\Review', \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === false
	&& in_array( '\\Nino\\Modules\\Files', \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === true );
check( '...and Files can be switched off now that nothing needs it', \Nino\Features::deactivate( $appData, 'files' ) === true );
reviewVisitor();

echo "\n";


// --- The look for the Design feature -----------------------------------------------

echo "The look - the files in design/, read by their grammar\n";

/*	One stylesheet in design/, a variant of the feature for the Design feature (its manual, under Base, says what a variant
	is), and the block it shows on the specimen. A head of lines "@tag words", the ladders of the knobs and nothing else: the
	layout is review.css's own. Read here by its grammar - which tags, which fields, which names. What a head says is never
	compared	*/
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
$defaults = $ruleOf( (string) file_get_contents( $dir. '/assets/review.css' ) )[':where(:root)'] ?? [];
$knobs = [];

foreach( $head['@knob'] ?? [] as $text ) {
	$fields = (array) preg_split( '/\s+/', $text, 4 );
	preg_match( '/^(.*?)(?:\s+=\s+(\S+))?$/', (string) ( $fields[3] ?? '' ), $label );
	$knobs[ (string) $fields[0] ] = [ (string) ( $fields[1] ?? '' ), (string) ( $fields[2] ?? '' ), $label[2] ?? null, trim( (string) ( $label[1] ?? '' ) ) ];
}

check( 'the feature brings one look, regular, and the block for the specimen beside it', array_map( 'basename', glob( $designDir. '/*' ) ?: [] ) === [ 'preview.tpl', 'regular.css' ] );
check( 'its head names and describes it in both languages, once each, and says the German in other words than the English',
	array_filter( [ '@name', '@name-de', '@description', '@description-de' ], static fn( string $tag ): bool => count( $head[$tag] ?? [] ) !== 1 ) === [] && $head['@name'] !== $head['@name-de'] && $head['@description'] !== $head['@description-de'] );
check( 'it declares the three knobs, once each and in order, with their kind and tab - the ground with the surface it stands at, the two scales without - each with its words in both languages',
	$knobs === [ 'ground' => [ 'ground', 'colours', 'default', 'Ground of the comments' ], 'space' => [ 'scale', 'surfaces', null, 'Space inside the player and the form' ], 'corners' => [ 'scale', 'surfaces', null, 'Corners of the player and the form' ] ]
	&& count( $head['@knob'] ) === 3 && array_map( static fn( string $text ): string => (string) preg_split( '/\s+/', $text, 2 )[0], $head['@knob-de'] ?? [] ) === [ 'ground', 'space', 'corners' ]
	&& array_filter( $head['@knob-de'], static fn( string $text ): bool => count( (array) preg_split( '/\s+/', $text, 2 ) ) < 2 ) === [] );

$rungs = [];

foreach( [ 'space', 'corners' ] as $scale )
	foreach( [ 'least', 'less', 'default', 'more', 'most' ] as $step )
		$rungs[] = '--review-'. $scale. '--'. $step;

check( 'each scale declares its five rungs, once each, on :where(:root) - and nothing else is declared, no rule is written and no colour is a literal',
	array_keys( $ladders ) === [ ':where(:root)' ] && array_keys( $ladders[':where(:root)'] ) === $rungs && array_sum( array_map( static fn( string $rung ): int => substr_count( $look, $rung. ':' ), $rungs ) ) === 10
	&& preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|oklch|oklab)\(|!important/i', (string) preg_replace( '#/\*.*?\*/#s', '', $look ) ) !== 1 );
check( 'the middle of each is the value review.css has as its default, so the player under it looks as it does without the Design feature',
	( $ladders[':where(:root)']['--review-space--default'] ?? null ) === ( $defaults['--review-space'] ?? false ) && ( $ladders[':where(:root)']['--review-corners--default'] ?? null ) === ( $defaults['--review-corners'] ?? false )
	&& isset( $defaults['--review-ground'], $defaults['--review-ground-on'], $defaults['--review-ground-link'], $defaults['--review-ground-border'], $defaults['--review-ground-focus'] ) === true );

$siteCss = (string) file_get_contents( $dir. '/assets/review.css' );
$panelCss = (string) file_get_contents( $dir. '/assets/admin.css' );
check( 'what the scripts hide with the attribute stays hidden: a display above is an author\'s declaration, which beats the browser\'s [hidden], so both stylesheets end it - .review-player [hidden] on the site, #review-app [hidden] in the panel',
	preg_match( '/\.review-player\s+\[hidden\]\s*\{[^{}]*\bdisplay\s*:\s*none\b/', $siteCss ) === 1 && preg_match( '/#review-app\s+\[hidden\]\s*\{[^{}]*\bdisplay\s*:\s*none\b/', $panelCss ) === 1 );

$block = (string) file_get_contents( $designDir. '/preview.tpl' );
preg_match_all( '/\sid="([^"]*)"/', $block, $blockIds );
check( 'the block for the specimen: one grid row with a player, two comments and the form written out and no shortcode, every id review- something and none twice, no section of its own, no inline handler and no script',
	str_starts_with( $block, '<div class="nino-grid-row">' ) === true && count( reviewFind( $block, '//div[contains(@class, "review-player")]' ) ) === 1 && count( reviewFind( $block, '//li[contains(@class, "review-comment")]' ) ) === 2
	&& count( reviewFind( $block, '//form[contains(@class, "review-form")]' ) ) === 1 && count( reviewFind( $block, '//audio[contains(@class, "review-player__media")]' ) ) === 1 && preg_match( '/\[review-[a-z]+/', $block ) !== 1
	&& $blockIds[1] !== [] && count( array_unique( $blockIds[1] ) ) === count( $blockIds[1] ) && array_filter( $blockIds[1], static fn( string $each ): bool => str_starts_with( $each, 'review-' ) === false ) === []
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

	/** Which of the features are on: their classes are listed in /nino/modules, and what the kernel and the Design feature have read of the features is read again */
	$featuresOn = static function( array $names ) use ( &$appData, $modulesKept ): void {
		$appData['/nino/modules'] = array_merge( array_values( array_diff( $modulesKept, [ '\\Nino\\Modules\\Review', '\\Nino\\Modules\\Design', '\\Nino\\Modules\\Files', '\\Nino\\Modules\\Members' ] ) ), array_map( static fn( string $name ): string => '\\Nino\\Modules\\'. $name, $names ) );
		unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
	};

	ninoWarnings();
	$featuresOn( [ 'Members', 'Files', 'Review', 'Design' ] );

	$scanNotes = [];
	$scan = $designSetup::features( $appData, $scanNotes );
	$feature = $scan['review'] ?? [];
	$variants = $feature['catalogue'] ?? [];
	$knobTold = [ 'ground' => [ 'ground', 'colours' ], 'space' => [ 'scale', 'surfaces' ], 'corners' => [ 'scale', 'surfaces' ] ];

	check( 'with the Design feature on, the scan lists this feature by its one look, which it has before anything is chosen, and has nothing to say of it',
		isset( $scan['review'] ) === true && realpath( (string) $feature['dir'] ) === realpath( $dir ) && array_keys( $variants ) === [ 'regular' ] && $feature['default'] === 'regular' && $scanNotes === [] );

	$appData['./nino/locales/current'] = 'en_US';
	$english = $designSetup::features( $appData )['review']['catalogue']['regular'] ?? [];
	$appData['./nino/locales/current'] = 'de_DE';
	$german = $designSetup::features( $appData )['review']['catalogue']['regular'] ?? [];
	$answers = [];

	foreach( $variants['regular']['knobs'] ?? [] as $name => $knob )
		$answers[$name] = [ $knob['kind'], $knob['tab'] ];

	check( '...described in the language of the workbench by the lines of its head, and answering to the three knobs with the kind and the tab the head names, each scale published by its ladder',
		( $english['name'] ?? null ) === $head['@name'][0] && ( $english['description'] ?? null ) === $head['@description'][0] && ( $german['name'] ?? null ) === $head['@name-de'][0] && ( $german['description'] ?? null ) === $head['@description-de'][0]
		&& $answers === $knobTold && ( $variants['regular']['knobs']['space']['ladder'] ?? [] ) === [ 'space' ] && ( $variants['regular']['knobs']['corners']['ladder'] ?? [] ) === [ 'corners' ] );

	$blockTold = [];
	$inside = $designSetup::previewOf( (string) $feature['dir'], 'review', $blockTold );
	$blocks = $designPreview::blocks( $appData );
	check( 'the block stands the check Design holds it to, the scan publishes its anchor, feature-review, and the preview makes the section of it', $inside === trim( $block ) && $blockTold === [] && ( $feature['anchor'] ?? null ) === 'feature-review'
		&& isset( $blocks['review'] ) === true && preg_match( '~^<section id="feature-review" class="nino-section"[^>]*>\s*(.*?)\s*</section>$~s', $blocks['review'], $section ) === 1 && str_contains( $section[1] ?? '', 'id="review-form"' ) === true );

	$featuresOn( [ 'Members', 'Files', 'Review', 'Design' ] );
	$notes = [];
	$sheet = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'review' => [ 'set' => 'regular' ] ] ], $library, $notes ), $library, $notes );
	$selected = [];

	foreach( [ 'space', 'corners' ] as $scale )
		if( str_contains( $sheet, "\n\t--review-". $scale. ': var('. sprintf( $designSetup::KNOB_TOKEN, 'review', $scale, 'default' ). ");\n" ) === false )
			$selected[] = $scale;

	foreach( \Nino\Modules\Design\Colours::parts() as $part )
		if( str_contains( $sheet, "\n\t--review-ground". ( $part === 'bg' ? '' : '-'. $part ). ': var('. \Nino\Modules\Design\Colours::token( 'default', $part ). ");\n" ) === false )
			$selected[] = 'ground '. $part;

	check( 'with the look chosen the sheet carries its stylesheet as the file has it, a line for the ladder of both scales and every part of the ground the palette solved, and compiles without a note',
		$selected === [] && $notes === [] && str_contains( $sheet, rtrim( $look, "\n" ) ) === true );

	$moved = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'review' => [ 'set' => 'regular', 'knobs' => [ 'space' => -2, 'corners' => 2, 'ground' => 'dark' ] ] ] ], $library, $notes ), $library, $notes );
	check( '...a position that is moved reaches the sheet as the rung it stands for, and a ground that is chosen as the palette\'s token of that surface',
		str_contains( $moved, "\n\t--review-space: var(". sprintf( $designSetup::KNOB_TOKEN, 'review', 'space', 'least' ). ");\n" ) === true && str_contains( $moved, "\n\t--review-corners: var(". sprintf( $designSetup::KNOB_TOKEN, 'review', 'corners', 'most' ). ");\n" ) === true
		&& str_contains( $moved, "\n\t--review-ground: var(". \Nino\Modules\Design\Colours::token( 'dark', 'bg' ). ");\n" ) === true );

	// The bundle of a project the wizard has set up lists the sheet of the feature after the theme the Design feature writes
	$bundled = $appData;
	$bundled['/nino/html/assets'] = [ '/.cache/style.css' => array_merge( (array) ( \Nino\AppData::DEFAULTS['/nino/html/assets']['/.cache/style.css'] ?? [] ), [ $designCompiler::TARGET, '/assets/style.css' ] ) ];
	\Nino\Features::registerAssets( $bundled );
	$bundle = $bundled['/nino/html/assets']['/.cache/style.css'];
	check( 'in a booted project the bundle lists review.css after the theme.css the Design feature writes its sheet to - the order the :where() of its defaults is for',
		in_array( '/features/Review/assets/review.css', $bundle, true ) === true && array_search( '/features/Review/assets/review.css', $bundle, true ) > array_search( $designCompiler::TARGET, $bundle, true ) );

	$appData['/nino/modules'] = $modulesKept;
	$appData['./nino/locales/current'] = $localeKept;
	unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
}

echo "\n";


// --- The scripts, where node is on the path ---------------------------------------------
//
// review-js-smoke.js beside this file runs the site script over a stand-in for the page and the panel's script over the kernel's own
// Nino.admin.js and a stand-in for the rest of Nino - the checkout this suite runs against, which is handed on; this suite runs it
// where node is on the path, so that bin/check.sh and CI cover both halves in one go
$node = function_exists( 'shell_exec' ) === true ? trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) ) : '';

if( $node === '' || function_exists( 'exec' ) === false )
	echo "  --  - node is not available here: review-js-smoke.js was NOT run\n";
else {
	$output = [];
	$exit = 1;
	exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/review-js-smoke.js' ). ' 2>&1', $output, $exit );
	check( 'review-js-smoke.js passes - '. ( $output === [] ? 'no output' : end( $output ) ), $exit === 0 );
}

/**
 *	The player says what the browser's own controls are not to offer - a download of the file: audio and video both carry controlsList="nodownload" -, and the day of a comment is written
 *	the way the language of the page writes a day (06.11.2026 in German, 2026-11-06 in the others) while the attribute of the element holds the time as the store has it
 *
 *	@param		array 		&$appData
 *	@param		string		$html						The player of an audio file with its comments
 *	@param		string		$videoHtml			The player of a video file
 *
 *	@return 	void
 */
function reviewPlayerChecks( array &$appData, string $html, string $videoHtml ): void {

	check( 'the audio and the video element of the player carry controlsList="nodownload": the browser\'s own controls do not offer to save the file', reviewFind( $html, '//audio[@controlslist="nodownload"]' ) !== [] && reviewFind( $videoHtml, '//video[@controlslist="nodownload"]' ) !== [] );

	$dates = reviewFind( $html, '//time[contains(@class, "review-comment__date")]' );
	$german = array_map( static fn( DOMElement $time ): array => [ $time->getAttribute( 'datetime' ), $time->textContent ], $dates );
	$locale = \Nino\Locales::getCurrentLocale( $appData );

	\Nino\Locales::useLocale( $appData, 'en_US' );
	ninoVisit( $appData, '/club/review?review='. $GLOBALS['track']['id'] );
	$english = array_map( static fn( DOMElement $time ): string => $time->textContent, reviewFind( \Nino\Html::renderHtml( $appData, '[review-player]' ), '//time[contains(@class, "review-comment__date")]' ) );
	\Nino\Locales::useLocale( $appData, $locale );

	check( '...the day of a comment is d.m.Y in German - the time attribute stays the stamp of the store - and Y-m-d in the language that writes it so', $dates !== [] && array_filter( $german, static fn( array $pair ): bool => preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $pair[0] ) !== 1 || $pair[1] !== substr( $pair[0], 8, 2 ). '.'. substr( $pair[0], 5, 2 ). '.'. substr( $pair[0], 0, 4 ) ) === []
		&& $english !== [] && array_filter( $english, static fn( string $day ): bool => preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) !== 1 ) === [] );
}

/**
 *	The mails of the feature tell the member to sign in - with the code the sign-in sends - and the mail to the owner can be sent by a site that keeps the files in a place of its own:
 *	the callback /review/owner-mail may set the link and one line about it, and the mail has the line in its subject, the link in its button and the word for that button; a line is a line
 *	whatever it holds
 *
 *	@param		array 		&$appData
 *	@param		array 		$track					The file
 *	@param		array 		$comment				A comment of a member on it
 *	@param		array 		$member					The account of that member
 *	@param		string		$answerText			The text of the mail of an answer to a member
 *
 *	@return 	void
 */
function reviewMailWordsChecks( array &$appData, array $track, array $comment, array $member, string $answerText ): void {

	global $mails;

	$words = \Nino\Html::getFills( $appData, 'de_DE' );

	check( 'the mail of an answer to a member says to sign in with the code, in the word of the new key - and not with the one that spoke of a link', str_contains( $answerText, (string) $words['[[/feature/review/mail/answer-notice-code]]'] ) === true && str_contains( $answerText, (string) $words['[[/feature/review/mail/answer-notice]]'] ) === false
		&& trim( (string) $words['[[/feature/review/mail/answer-notice-code]]'] ) !== '' );

	$saved = [ $mails ];
	\Nino\Features::saveSettings( $appData, 'review', [ 'notifyOwner' => true ] );
	$mails = [];
	reviewFresh( $appData );
	\Nino\Callbacks::registerCallback( $appData, '/review/owner-mail', static function( array &$appData, array &$args ): void {
		$args['link'] = 'https://example.org/crm#projekt-00007';
		$args['about'] = "Kunde\r\nBcc: x@example.org <b>Projekt</b> [[company]]";
	} );
	$told = \Nino\Modules\Review::notifyOwner( $appData, $track, $comment, $member );
	$mail = $mails[0] ?? [];
	\Nino\Callbacks::removeCallbacks( $appData, '/review/owner-mail' );

	check( 'a callback on /review/owner-mail sets the link and the line about it: the button links the address it gave and says the word of the card, the subject has the line - one line, no key left in it, the markup of it text',
		$told === true && count( $mails ) === 1 && str_contains( (string) $mail['body'], 'href="https://example.org/crm#projekt-00007"' ) === true && str_contains( (string) $mail['body'], '#review' ) === false && str_contains( (string) $mail['body'], (string) $words['[[/feature/review/mail/comment-button-card]]'] ) === true
		&& str_contains( (string) $mail['body'], (string) $words['[[/feature/review/mail/comment-button]]'] ) === false && preg_match( '/[\r\n]/', (string) $mail['subject'] ) !== 1 && str_contains( (string) $mail['subject'], 'Kunde Bcc: x@example.org <b>Projekt</b> [[company]]' ) === true );

	$mails = [];
	reviewFresh( $appData );
	\Nino\Callbacks::registerCallback( $appData, '/review/owner-mail', static function( array &$appData, array &$args ): void {
		$args['link'] = [ 'no text' ];
		$args['about'] = 42;
	} );
	\Nino\Modules\Review::notifyOwner( $appData, $track, $comment, $member );
	$stray = $mails[0] ?? [];
	\Nino\Callbacks::removeCallbacks( $appData, '/review/owner-mail' );

	check( '...a callback that answers a link or a line that is no text leaves no mail with them: no mail is sent without a link, and a line that is none is no line', $stray === [] || ( str_contains( (string) $stray['subject'], '[[about]]' ) === false && str_contains( (string) $stray['body'], 'href=""' ) === false ) );

	$mails = [];
	reviewFresh( $appData );
	\Nino\Modules\Review::notifyOwner( $appData, $track, $comment, $member );
	$plain = $mails[0] ?? [];

	check( '...with no callback the mail is the plain one: the panel is the link, the subject is the one of the unit and the button says the panel', str_contains( (string) ( $plain['body'] ?? '' ), '/_admin#review' ) === true && ( $plain['subject'] ?? '' ) === (string) $words['[[/feature/review/mail/comment-subject]]'] && str_contains( (string) $plain['body'], (string) $words['[[/feature/review/mail/comment-button]]'] ) === true );

	\Nino\Features::saveSettings( $appData, 'review', [ 'notifyOwner' => false ] );
	$mails = $saved[0];
}

echo "\n";
ninoDone( $appData );

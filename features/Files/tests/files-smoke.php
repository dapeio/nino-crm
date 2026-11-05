<?php
declare(strict_types=1);

/**
 *	Nino
 *	files-smoke.php	Contract test for the Files feature (Modules\Files): the manifest and the
 *									activation through \Nino\Features, the data layer and the API other
 *									features read, the download route over the file response of the
 *									kernel, the upload in chunks - the owner's through the panel, a
 *									member's through the public route, and the plain post without script -,
 *									the two components, the panel and its permission with the codes of its
 *									refusals, the mails, the restore, deactivation, the looks for the Design
 *									feature, and - where node is on the path - the panel script's own test.
 *									Travels with the feature and runs against the checkout three levels up,
 *									or the one NINO_ROOT names (see tests/harness.php there).
 *
 *									The feature asks for a kernel that carries the file response, which its
 *									manifest names as the floor. On a kernel below that floor the activation
 *									is refused, as it has to be: the test says so, and does by hand what an
 *									activation does (the unit, the class in /nino/modules, the version), so
 *									that everything else is still looked at.
 *
 *	Usage: php features/Files/tests/files-smoke.php
 *	       NINO_ROOT=../nino php features/Files/tests/files-smoke.php
 */

// The checkout this test runs against: three levels up when the feature sits
// in a project's features/, else the one NINO_ROOT names (a checkout beside
// the catalogue, say). The features root is this feature's own parent either
// way, so the kernel's autoloader serves the class from here
$root = getenv( 'NINO_ROOT' ) ?: dirname( __DIR__, 3 );
defined( 'NINO_FEATURES_DIR' ) === true || define( 'NINO_FEATURES_DIR', dirname( __DIR__, 2 ) );
require $root. '/tests/harness.php';

$appData = ninoSandbox( 'files' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$sandbox = ninoSandboxDir( $appData );

// The project directory and its fill, which \Nino\request() registers from the same key on every request - empty
// here, the way a site at the domain root has it - and the facts of the website the mails are built on: its
// address, which every link of a mail starts with (\Nino\Html::absoluteUrl()), and the contact address
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

/*	A transport takes the mail so nothing is actually sent - the same \Nino\Mail::TRANSPORT callback the Mailer
	feature registers. $delivery is what the stub says about the mail, so that a refusal is as easy to make as a delivery	*/
$mails = [];
$delivery = true;
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails, &$delivery ): void {
	$mails[] = $mail;
	$mail['sent'] = $delivery;
} );

// What the features that build on this one are told: the entry of a file that came in or went
$told = [];
foreach( [ '/files/upload', '/files/add', '/files/delete' ] as $event )
	\Nino\Callbacks::registerCallback( $appData, $event, static function( array &$appData, array &$entry ) use ( &$told, $event ): void {
		$told[] = [ $event, $entry ];
	} );

/**
 *	The data files of the feature as they are on disk, read fresh
 *
 *	@param		array 		&$appData
 *
 *	@return 	array										The index as it is stored
 */
function filesIndex( array &$appData ): array {

	$state = \Nino\Filesystem::getFileContent( $appData, '/data/files.php', [] );

	return is_array( $state ) === true ? $state : [];
}

/**
 *	Switch the feature on - the way a project does, and by hand where the kernel is below its floor. Below the
 *	floor the activation is refused with the reason, and what an activation does besides is done here: the
 *	required feature is activated, the unit is applied add-only, the class is listed in /nino/modules and the
 *	version is recorded
 *
 *	@param		array 		&$appData
 *
 *	@return 	true|string							True, or why not
 */
function filesActivate( array &$appData ): true|string {

	if( ( \Nino\Features::get( $appData, 'files' )['problems'] ?? [] ) === [] )
		return \Nino\Features::activate( $appData, 'files' );

	$members = \Nino\Features::activate( $appData, 'members' );

	if( $members !== true )
		return $members;

	$routes = [];
	$blacklist = [];
	$config = [];
	$applied = \Nino\Features::applyUnit( $appData, dirname( __DIR__ ). '/install', \Nino\Locales::getAvailableLocales( $appData ), $routes, $blacklist, $config, false );

	if( $applied !== true )
		return $applied;

	\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {

		$content['/nino/modules'] = array_values( array_unique( array_merge( (array) ( $content['/nino/modules'] ?? [] ), [ '\\Nino\\Modules\\Files' ] ) ) );
		$content['/nino/features']['files'] = [ 'version' => '1.0.0', 'settings' => (array) ( $content['/nino/features']['files']['settings'] ?? [] ) ];

		return $content;
	} );

	$persisted = \Nino\Filesystem::getFileContent( $appData, '/config.php', [] );
	$appData['/nino/modules'] = array_values( (array) ( $persisted['/nino/modules'] ?? [] ) );
	$appData['/nino/features'] = (array) ( $persisted['/nino/features'] ?? [] );
	unset( $appData['./nino/features/all'] );

	return true;
}

/**
 *	A file part as php hands it over: a stored chunk the test made
 *
 *	@param		array 		&$appData
 *	@param		string		$bytes
 *
 *	@return 	array										The entry of $_FILES
 */
function filesPart( array &$appData, string $bytes ): array {

	$path = ninoSandboxDir( $appData ). '/chunk-'. bin2hex( random_bytes( 4 ) );
	file_put_contents( $path, $bytes );

	return [ 'name' => 'blob', 'type' => 'application/octet-stream', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $bytes ) ];
}

/**
 *	The elements of a rendered piece of html by xpath, as a document reads them
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	array										The DOMElements
 */
function filesFind( string $html, string $query ): array {

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
function filesClasses( DOMElement $element ): array {

	return array_values( array_filter( preg_split( '/\s+/', $element->getAttribute( 'class' ) ) ?: [] ) );
}


/**
 *	A request of the site the way the web server hands it over, answered by the routes and the callbacks that are
 *	registered: the request built, the global callbacks of the response run - the csrf guard and the gate of Members
 *	among them - and then the route's own. A post carries the csrf token of the session unless $csrf is false.
 *	Nothing is rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$method
 *	@param		array 		$post
 *	@param		bool			$csrf					Whether a post carries the token
 *	@param		array 		$files				What php makes of the file parts, $_FILES
 *	@param		array 		$server				More of $_SERVER: a Range as HTTP_RANGE, the address of the client
 *
 *	@return 	array										The request, response and all
 */
function filesServe( array &$appData, string $uri, string $method = 'GET', array $post = [], bool $csrf = true, array $files = [], array $server = [] ): array {

	// What a request keeps for itself alone is gone before the next one begins
	unset( $appData['./files/forms'] );

	$_POST = $post + ( $method === 'POST' && $csrf === true ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$_FILES = $files;
	$request = ninoVisit( $appData, $uri, $method, $server );
	\Nino\Http::response( $appData, $request );
	$_POST = [];
	$_FILES = [];

	return $request;
}

/**
 *	What a response says in the places a visitor and a test read it
 *
 *	@param		array 		$request
 *
 *	@return 	array										[ status, Location, Content-Type, body ]
 */
function filesAnswer( array $request ): array {

	$response = $request['/nino/http/response'];

	return [ $response['statusCode'], (string) ( $response['header']['Location'] ?? '' ), (string) ( $response['header']['Content-Type'] ?? '' ), $response['body'] ?? '' ];
}

/**
 *	A new window for every cap: the counter of the feature and the mail cap of the kernel, which counts the same client
 *
 *	@param		array 		&$appData
 *
 *	@return 	void
 */
function filesFresh( array &$appData ): void {

	\Nino\Filesystem::putFileContent( $appData, '/data/files-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
}

/**
 *	A visitor who has never been here: no session, nobody signed in
 *
 *	@return 	void
 */
function filesVisitor(): void {

	$_SESSION = [];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

/**
 *	Who is at the workbench: nobody, the plain account (which may do nothing there) or the full-access one. The kernel
 *	keeps who is signed in for the request, so a change of person drops that first
 *
 *	@param		string		$who					'', 'plain' or 'admin'
 *
 *	@return 	void
 */
function filesWorkbench( string $who ): void {

	global $appData;

	filesVisitor();

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
function filesSignIn( array &$appData, array $account ): bool {

	global $mails;

	filesFresh( $appData );
	filesVisitor();

	$count = count( $mails );

	if( \Nino\Modules\Members::link( $appData, $account, 'login' ) !== true || preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( $mails[$count]['body'] ?? '' ), $found ) !== 1 )
		return false;

	filesServe( $appData, '/.members/login?t='. $found[1] );
	filesServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] );

	return ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

// --- The feature - manifest and activation -------------------------------------

echo "The feature - manifest and activation\n";

$dir = dirname( __DIR__ );
$manifest = \Nino\Features::manifest( $dir );
check( 'the manifest validates, key "files", without a warning', is_array( $manifest ) && $manifest['key'] === 'files' && ninoWarnings() === [] );
check( 'class, version and category are what the plan says, and it requires Members and the extension that sniffs the bytes', is_array( $manifest ) && $manifest['module'] === '\\Nino\\Modules\\Files' && $manifest['version'] === '1.0.0'
	&& $manifest['category'] === 'content' && $manifest['requires'] === [ 'members' ] && $manifest['php'] === [ 'ext' => [ 'fileinfo' ] ] && $manifest['maturity'] === '' );
check( 'it names itself in both interface languages', is_array( $manifest ) && \Nino\Features::localized( $manifest['name'], 'de_DE' ) !== \Nino\Features::localized( $manifest['name'], 'en_US' )
	&& \Nino\Features::localized( $manifest['description'], 'de_DE' ) !== \Nino\Features::localized( $manifest['description'], 'en_US' ) );
check( 'its floor is above the kernel that has no file response (1.9.0), and a constraint of the same major version', is_array( $manifest ) && \Nino\Features::satisfies( $manifest['nino'], '1.9.0' ) === false
	&& \Nino\Features::satisfies( $manifest['nino'], '1.99.0' ) === true && \Nino\Features::satisfies( $manifest['nino'], '2.0.0' ) === false );
check( 'it owns the index and nothing else - not the files and not the counter, which are far too large and an hour long', is_array( $manifest ) && $manifest['data'] === [ '/data/files.php' ]
	&& $manifest['assets'] === [ '/features/Files/assets/files.css', '/features/Files/assets/files.js' ] );
check( 'the settings are the seven of the plan, each with its type, its default and its bounds', is_array( $manifest ) && array_keys( $manifest['settings'] ) === [ 'types', 'maxSize', 'chunk', 'quota', 'attempts', 'notifyMembers', 'notifyOwner' ]
	&& $manifest['settings']['types']['type'] === 'lines' && $manifest['settings']['types']['default'] === [ 'mp3', 'wav', 'flac', 'aiff', 'aif', 'm4a', 'ogg', 'mp4', 'mov', 'zip', 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'txt' ]
	&& [ $manifest['settings']['maxSize']['default'], $manifest['settings']['maxSize']['min'], $manifest['settings']['maxSize']['max'], $manifest['settings']['maxSize']['unit'] ] === [ 500, 1, 4096, 'megabytes' ]
	&& [ $manifest['settings']['chunk']['default'], $manifest['settings']['chunk']['min'], $manifest['settings']['chunk']['max'], $manifest['settings']['chunk']['unit'] ] === [ 4, 1, 32, 'megabytes' ]
	&& [ $manifest['settings']['quota']['default'], $manifest['settings']['quota']['min'], $manifest['settings']['quota']['max'] ] === [ 0, 0, 100000 ]
	&& [ $manifest['settings']['attempts']['default'], $manifest['settings']['attempts']['min'], $manifest['settings']['attempts']['max'], $manifest['settings']['attempts']['unit'] ] === [ 300, 1, 1000, 'per-hour' ]
	&& $manifest['settings']['notifyMembers']['type'] === 'bool' && $manifest['settings']['notifyMembers']['default'] === false && $manifest['settings']['notifyOwner']['type'] === 'bool' && $manifest['settings']['notifyOwner']['default'] === false );
check( 'it says all six sections of its manual in both languages, and its section of the privacy policy and the backup as lines of the install section', is_array( $manifest ) && (function() use ( $manifest ): bool {
	foreach( \Nino\Features::MANUAL_SECTIONS as $section )
		foreach( (array) ( $manifest['manual'][$section] ?? [] ) as $line )
			if( is_array( $line ) === false || trim( (string) ( $line['en_US'] ?? '' ) ) === '' || trim( (string) ( $line['de_DE'] ?? '' ) ) === '' )
				return false;
	return isset( $manifest['manual']['install']['elements/privacy.php'], $manifest['manual']['install']['backup'] ) === true && count( $manifest['manual']['shortcodes'] ) >= 2
		&& count( $manifest['manual']['routes'] ) === 3 && count( $manifest['manual']['callbacks'] ) === 4 && count( $manifest['manual']['panel'] ) >= 1;
} )() );

// The catalogue's preview reads every manifest through the kernel, and is a script of the catalogue: it is there when
// this test runs in the repository, not when the feature has been dropped into a project
$preview = dirname( __DIR__, 3 ). '/bin/catalogue.php';

if( is_file( $preview ) === false )
	echo "  note - no bin/catalogue.php beside this feature: the catalogue's preview was not asked\n";
else {
	$listed = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( $preview ). ' '. escapeshellarg( $root ). ' 2>/dev/null' ), true );
	check( 'the catalogue preview lists it, with its category and its version', is_array( $listed ) && count( array_filter( (array) ( $listed['features'] ?? [] ), static fn( mixed $entry ): bool => is_array( $entry ) === true
		&& ( $entry['key'] ?? '' ) === 'files' && ( $entry['category'] ?? '' ) === 'content' && ( $entry['version'] ?? '' ) === '1.0.0' ) ) === 1 );
}

$privacyType = $root. '/_nino/Nino/Modules/Legal';

// The Legal module's type is what the feature's section is added to: put there first, where the kernel has the module
if( class_exists( '\\Nino\\Modules\\Legal' ) === true ) {
	$routes = [];
	$blacklist = [];
	$config = [];
	\Nino\Features::applyUnit( $appData, $privacyType. '/install', [ 'de_DE', 'en_US' ], $routes, $blacklist, $config, true );
}

ninoWarnings();
$floorHolds = ( \Nino\Features::get( $appData, 'files' )['problems'] ?? null ) === [];

if( $floorHolds === true )
	check( 'the registry lists it, inactive, with nothing in the way', ( \Nino\Features::get( $appData, 'files' )['active'] ?? null ) === false );
else {
	$problems = (array) ( \Nino\Features::get( $appData, 'files' )['problems'] ?? [] );
	echo "  note - this Nino (". \Nino\VERSION. ") is below the floor of the feature (". $manifest['nino']. "): the registry lists it with the reason, and the activation below is done by hand\n";
	check( 'a kernel below the floor lists the feature with one problem, the floor, and refuses to activate it', count( $problems ) === 1 && str_contains( $problems[0], $manifest['nino'] ) === true
		&& is_string( \Nino\Features::activate( $appData, 'files' ) ) === true && in_array( '\\Nino\\Modules\\Files', (array) ( \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] ?? [] ), true ) === false );
}

check( 'activation succeeds', filesActivate( $appData ) === true );
check( 'the class is listed in /nino/modules, and the version is recorded', in_array( '\\Nino\\Modules\\Files', \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === true
	&& \Nino\Features::get( $appData, 'files' )['installed'] === '1.0.0' && in_array( '\\Nino\\Modules\\Members', \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === true );
check( 'the unit merged its words into both languages', array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $GLOBALS['appData'], '/text/'. $locale. '.php', [] )['[[/feature/files/state/sent]]'] ?? '' ) ) === '' ) === [] );

if( class_exists( '\\Nino\\Modules\\Legal' ) === false )
	echo "  note - this Nino has no \\Nino\\Modules\\Legal: the section of the privacy policy was not looked for\n";
else {
	$privacy = \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] );
	check( 'the section of the privacy policy is there in both languages, at its position', isset( $privacy['de_DE']['files']['title'], $privacy['en_US']['files']['text'] ) === true && ( $privacy['*']['files']['order'] ?? null ) === 840 );

	$privacy['en_US']['files']['text'] = '<p>The editor changed this.</p>';
	\Nino\Filesystem::putFileContent( $appData, '/elements/privacy.php', $privacy );
	unset( $appData['./nino/features/all'] );
	filesActivate( $appData );
	check( 'a second activation changes nothing of it - a section the editor changed stays', \Nino\Filesystem::getFileContent( $appData, '/elements/privacy.php', [] ) === $privacy );
}

\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

check( 'init registers the two routes with their handlers, and the merge of a restore - and no shortcode of its own beside the components of the manifest',
	( $appData['/nino/http/routes']['GET://.files/*']['uri'] ?? null ) === '/.files/*' && ( $appData['/nino/http/routes']['POST://.files/upload']['uri'] ?? null ) === '/.files/upload'
	&& isset( $appData['./nino/callbacks']['/nino/http/response/GET://.files/*'], $appData['./nino/callbacks']['/nino/http/response/POST://.files/upload'], $appData['./nino/callbacks']['/nino/admin/restore'] ) === true
	&& count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/files-browser' ) ) === 1 );

echo "\n";


// --- The data layer and the API -------------------------------------------------

echo "Modules\\Files\\Store and the API - folders, files and what a caller reads\n";

check( 'a project with no folder lists none, for any group - and the index is not there yet', \Nino\Modules\Files::folders( $appData, 'staff' ) === [] && \Nino\Modules\Files::files( $appData, 'aaaaaaaaaaaa' ) === []
	&& \Nino\Modules\Files::file( $appData, 'aaaaaaaaaaaa' ) === null && \Nino\Modules\Files::path( $appData, 'aaaaaaaaaaaa' ) === null && filesIndex( $appData ) === [] );

$staff = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', "  Mixes\x07 and masters  " );
$guests = \Nino\Modules\Files\Store::addFolder( $appData, 'guests', 'Guest folder' );
$stored = filesIndex( $appData );

check( 'a folder is an id of 12 hex characters with its group, its cleaned name, no upload mark yet and the time it was made',
	is_array( $staff ) && preg_match( '/^[a-f0-9]{12}$/D', $staff['id'] ) === 1 && array_keys( $stored['folders'][$staff['id']] ) === [ 'group', 'name', 'upload', 'created' ]
	&& $stored['folders'][$staff['id']]['group'] === 'staff' && $stored['folders'][$staff['id']]['name'] === 'Mixes and masters' && $stored['folders'][$staff['id']]['upload'] === false
	&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $stored['folders'][$staff['id']]['created'] ) === 1 && count( $stored['folders'] ) === 2 );
check( 'a name of more than 80 characters, an empty one and a group that is no slug are refused - 80 of them, counted as characters, are fine', \Nino\Modules\Files\Store::addFolder( $appData, 'staff', str_repeat( 'x', 81 ) ) === 'long'
	&& \Nino\Modules\Files\Store::addFolder( $appData, 'staff', " \t " ) === 'empty' && \Nino\Modules\Files\Store::addFolder( $appData, 'Not A Slug', 'x' ) === 'group'
	&& is_array( \Nino\Modules\Files\Store::addFolder( $appData, 'edge', str_repeat( 'ü', 80 ) ) ) && count( filesIndex( $appData )['folders'] ) === 3 );

$more = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', 'Alpha' );
check( 'the folders of a group are listed with their ids, sorted by name without regard to case, and a folder of one group is not listed for another',
	array_column( \Nino\Modules\Files::folders( $appData, 'staff' ), 'name' ) === [ 'Alpha', 'Mixes and masters' ] && array_column( \Nino\Modules\Files::folders( $appData, 'guests' ), 'id' ) === [ $guests['id'] ]
	&& \Nino\Modules\Files::folders( $appData, 'nobody' ) === [] && array_keys( \Nino\Modules\Files::folders( $appData, 'staff' )[0] ) === [ 'id', 'group', 'name', 'upload', 'created' ] );

// The order is the order of the letters, whatever the case of the first one
$sorting = [];
foreach( [ 'Banana', 'apple', 'Cherry' ] as $each )
	$sorting[] = \Nino\Modules\Files\Store::addFolder( $appData, 'sorting', $each );
$sorted = array_column( \Nino\Modules\Files::folders( $appData, 'sorting' ), 'name' );
foreach( $sorting as $each )
	\Nino\Modules\Files\Store::deleteFolder( $appData, $each['id'] );
check( '...whatever the case the names begin with: apple comes before Banana, which comes before Cherry', $sorted === [ 'apple', 'Banana', 'Cherry' ] && \Nino\Modules\Files::folders( $appData, 'sorting' ) === [] );

// The mark: one folder of a group carries it, and marking another moves it - in one write
$first = \Nino\Modules\Files\Store::markUpload( $appData, $staff['id'], true );
$moved = \Nino\Modules\Files\Store::markUpload( $appData, $more['id'], true );
$marks = array_map( static fn( array $folder ): bool => $folder['upload'], array_column( filesIndex( $appData )['folders'], null ) );
check( 'exactly one folder of the group carries the upload mark after another is marked - the mark moves, nothing is refused - and the folders of other groups keep theirs',
	$first['upload'] === true && $moved['upload'] === true && filesIndex( $appData )['folders'][$staff['id']]['upload'] === false && filesIndex( $appData )['folders'][$more['id']]['upload'] === true
	&& array_sum( array_map( 'intval', $marks ) ) === 1 );
\Nino\Modules\Files\Store::markUpload( $appData, $guests['id'], true );
\Nino\Modules\Files\Store::markUpload( $appData, $more['id'], false );
check( '...the mark can be taken off, and a mark on another group stays', filesIndex( $appData )['folders'][$more['id']]['upload'] === false && filesIndex( $appData )['folders'][$guests['id']]['upload'] === true
	&& \Nino\Modules\Files\Store::markUpload( $appData, 'ffffffffffff', true ) === 'unknown' );

$renamed = \Nino\Modules\Files\Store::renameFolder( $appData, $more['id'], ' Beta ' );
check( 'a folder is renamed, with the rules of a name', $renamed['name'] === 'Beta' && \Nino\Modules\Files\Store::renameFolder( $appData, $more['id'], '' ) === 'empty' && \Nino\Modules\Files\Store::renameFolder( $appData, $more['id'], str_repeat( 'x', 81 ) ) === 'long'
	&& \Nino\Modules\Files\Store::renameFolder( $appData, 'ffffffffffff', 'x' ) === 'unknown' && filesIndex( $appData )['folders'][$more['id']]['name'] === 'Beta' );

// Files: put straight into the index with bytes beside them, as the upload leaves them
$bytesDir = \Nino\Filesystem::path( $appData, '/data/files' );
\Nino\Filesystem::forceDir( $appData, '/data/files' );

/**
 *	A file in the index with its bytes on the disk
 *
 *	@param		array 		&$appData
 *	@param		string		$folder
 *	@param		string		$name
 *	@param		string		$bytes
 *	@param		array 		$by
 *	@param		bool			$visible
 *	@param		string		$mime
 *	@param		int				$age					Seconds ago the file was put there
 *
 *	@return 	array										The entry with its id
 */
function filesMake( array &$appData, string $folder, string $name, string $bytes, array $by = [ 'kind' => 'owner', 'id' => '' ], bool $visible = true, string $mime = 'audio/mpeg', int $age = 5 ): array {

	[ $clean, $ext ] = \Nino\Modules\Files\Store::cleanName( $name );
	$id = bin2hex( random_bytes( 6 ) );

	file_put_contents( (string) \Nino\Modules\Files\Store::bytes( $appData, $id, $ext ), $bytes );

	$entry = \Nino\Modules\Files\Store::addFile( $appData, $id, [ 'folder' => $folder, 'name' => $clean, 'ext' => $ext, 'size' => strlen( $bytes ), 'mime' => $mime, 'sha256' => hash( 'sha256', $bytes ),
		'by' => $by, 'visible' => $visible, 'created' => gmdate( \Nino\Modules\Files\Store::TIME, time() - $age ) ], 0 );

	return is_array( $entry ) === true ? $entry : [];
}

$demo = filesMake( $appData, $staff['id'], 'Demo.mp3', 'DEMO-BYTES' );
$hidden = filesMake( $appData, $staff['id'], 'Secret.mp3', 'HIDDEN-BYTES', [ 'kind' => 'owner', 'id' => '' ], false );

check( 'a file is an id of 12 hex characters in its folder, with its name, extension, size, MIME type, sha256, who put it there, whether it is visible and when',
	array_keys( filesIndex( $appData )['files'][$demo['id']] ) === [ 'folder', 'name', 'ext', 'size', 'mime', 'sha256', 'by', 'visible', 'created' ] && filesIndex( $appData )['files'][$demo['id']]['by'] === [ 'kind' => 'owner', 'id' => '' ]
	&& filesIndex( $appData )['files'][$demo['id']]['size'] === 10 && filesIndex( $appData )['files'][$demo['id']]['sha256'] === hash( 'sha256', 'DEMO-BYTES' ) );
check( 'files() lists the files of a folder, the hidden ones too, each with its id - file() answers one, path() the absolute path of its bytes, url() the address it is downloaded at',
	count( \Nino\Modules\Files::files( $appData, $staff['id'] ) ) === 2 && array_diff( array_column( [ $demo, $hidden ], 'id' ), array_column( \Nino\Modules\Files::files( $appData, $staff['id'] ), 'id' ) ) === [] && array_keys( \Nino\Modules\Files::files( $appData, $staff['id'] )[0] ) === [ 'id', 'folder', 'name', 'ext', 'size', 'mime', 'sha256', 'by', 'visible', 'created' ] );
check( '...one file by its id, its bytes, its address', \Nino\Modules\Files::file( $appData, $demo['id'] ) === $demo && \Nino\Modules\Files::path( $appData, $demo['id'] ) === $bytesDir. '/'. $demo['id']. '.mp3'
	&& file_get_contents( (string) \Nino\Modules\Files::path( $appData, $demo['id'] ) ) === 'DEMO-BYTES' && \Nino\Modules\Files::url( $appData, $demo['id'] ) === '/.files/'. $demo['id']
	&& \Nino\Modules\Files::files( $appData, $guests['id'] ) === [] );
// The newest first, whatever the names
$aged = \Nino\Modules\Files\Store::addFolder( $appData, 'aged', 'Aged' );
filesMake( $appData, $aged['id'], 'Older.mp3', 'OLD', [ 'kind' => 'owner', 'id' => '' ], true, 'audio/mpeg', 86400 );
filesMake( $appData, $aged['id'], 'Newer.mp3', 'NEW' );
filesMake( $appData, $aged['id'], 'Middle.mp3', 'MID', [ 'kind' => 'owner', 'id' => '' ], true, 'audio/mpeg', 3600 );
$newestFirst = array_column( \Nino\Modules\Files::files( $appData, $aged['id'] ), 'name' );
\Nino\Modules\Files\Store::deleteFolder( $appData, $aged['id'] );
check( '...the newest first: a file of yesterday comes after one of an hour ago, whatever the names', $newestFirst === [ 'Newer.mp3', 'Middle.mp3', 'Older.mp3' ] );

// The address starts with the directory the project is served from
$appData['/nino/dir'] = '/club';
$underDirectory = \Nino\Modules\Files::url( $appData, $demo['id'] );
$appData['/nino/dir'] = '';
check( '...an address that starts with the directory the project is served from', $underDirectory === '/club/.files/'. $demo['id'] );

check( '...and an id that is none, or that nobody has, is nothing - not a path, not an address', \Nino\Modules\Files::file( $appData, '../../etc/passwd' ) === null && \Nino\Modules\Files::path( $appData, '../../etc/passwd' ) === null
	&& \Nino\Modules\Files::path( $appData, 'ffffffffffff' ) === null && \Nino\Modules\Files::url( $appData, 'not-an-id' ) === '' && \Nino\Modules\Files::url( $appData, "{$demo['id']}/../x" ) === ''
	&& \Nino\Modules\Files\Store::bytes( $appData, $demo['id'], '../x' ) === null && \Nino\Modules\Files\Store::bytes( $appData, 'zz', 'mp3' ) === null );

// A file the index names and the disk has not
unlink( $bytesDir. '/'. $demo['id']. '.mp3' );
check( 'a file whose bytes are gone has an entry and no path', \Nino\Modules\Files::file( $appData, $demo['id'] ) === $demo && \Nino\Modules\Files::path( $appData, $demo['id'] ) === null );
file_put_contents( $bytesDir. '/'. $demo['id']. '.mp3', 'DEMO-BYTES' );

// The index is plain php anybody can edit: what is not an entry is left out when it is read
\Nino\Filesystem::putFileContent( $appData, '/data/files.php', [ 'folders' => [ 'aaaaaaaaaaaa' => [ 'group' => 'x', 'name' => 'Ok', 'upload' => 'yes', 'created' => 5 ], 'bad' => [ 'group' => 'x' ], 'bbbbbbbbbbbb' => [ 'group' => 'Not A Slug' ] ],
	'files' => [ 'cccccccccccc' => [ 'folder' => 'aaaaaaaaaaaa', 'name' => 'a.mp3', 'ext' => 'mp3', 'size' => 3, 'mime' => 'audio/mpeg', 'sha256' => 'x', 'by' => [ 'kind' => 'member', 'id' => 'dddddddddddd' ], 'visible' => 1 ],
		'eeeeeeeeeeee' => [ 'folder' => 'zzzzzzzzzzzz', 'name' => 'b.mp3', 'ext' => 'mp3', 'size' => 3, 'by' => [ 'kind' => 'owner' ] ], 'ffffffffffff' => [ 'folder' => 'aaaaaaaaaaaa', 'name' => 'c.mp3', 'ext' => '../x', 'size' => 3, 'by' => [ 'kind' => 'owner' ] ],
		'111111111111' => [ 'folder' => 'aaaaaaaaaaaa', 'name' => 'd.mp3', 'ext' => 'mp3', 'size' => '3', 'by' => [ 'kind' => 'owner' ] ], '222222222222' => [ 'folder' => 'aaaaaaaaaaaa', 'name' => 'e.mp3', 'ext' => 'mp3', 'size' => 3, 'by' => [ 'kind' => 'robot' ] ] ] ] );
$read = \Nino\Modules\Files\Store::read( $appData );
check( 'an index that was edited by hand is read for what it has to be: an entry with a bad id, a bad group, a missing folder, a bad extension, a size that is no whole number or a maker that is none is left out - and what is left reads as strings, bools and ints',
	array_keys( $read['folders'] ) === [ 'aaaaaaaaaaaa' ] && $read['folders']['aaaaaaaaaaaa'] === [ 'group' => 'x', 'name' => 'Ok', 'upload' => false, 'created' => '' ]
	&& array_keys( $read['files'] ) === [ 'cccccccccccc' ] && $read['files']['cccccccccccc']['visible'] === false && $read['files']['cccccccccccc']['by'] === [ 'kind' => 'member', 'id' => 'dddddddddddd' ] && $read['files']['cccccccccccc']['mime'] === 'audio/mpeg' );
\Nino\Filesystem::putFileContent( $appData, '/data/files.php', [] );

// An id of twelve digits is a whole number as a php array key: everything that walks the index has to read it back as the id it is
$digitFolder = '123456789012';
$digitOther = '234567890123';
$digitFile = '345678901234';
$digitRecord = [ 'folder' => $digitFolder, 'name' => 'x.mp3', 'ext' => 'mp3', 'size' => 3, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '2026-01-01T00:00:00Z' ];
\Nino\Filesystem::putFileContent( $appData, '/data/files.php', [ 'folders' => [ $digitFolder => [ 'group' => 'digits', 'name' => 'A', 'upload' => false, 'created' => '' ], $digitOther => [ 'group' => 'digits', 'name' => 'B', 'upload' => true, 'created' => '' ] ],
	'files' => [ $digitFile => $digitRecord ] ] );
file_put_contents( (string) \Nino\Modules\Files\Store::bytes( $appData, $digitFile, 'mp3' ), 'abc' );
$digitFolders = array_column( \Nino\Modules\Files::folders( $appData, 'digits' ), 'id' );
$digitFiles = array_column( \Nino\Modules\Files::files( $appData, $digitFolder ), 'id' );
$digitMark = \Nino\Modules\Files\Store::markUpload( $appData, $digitFolder, true );
$digitMoved = \Nino\Modules\Files\Store::changeFile( $appData, $digitFile, [ 'folder' => $digitOther, 'visible' => false ] );
$digitAdded = \Nino\Modules\Files\Store::addFile( $appData, '456789012345', [ 'folder' => $digitOther ] + $digitRecord, 0 );
$digitFolderGone = \Nino\Modules\Files\Store::deleteFolder( $appData, $digitOther );
check( 'an id of twelve digits, which php makes a whole number of as an array key, is read back as the id it is: listed, marked, moved, added and deleted with its files',
	$digitFolders === [ $digitFolder, $digitOther ] && $digitFiles === [ $digitFile ] && is_array( $digitMark ) && $digitMark['id'] === $digitFolder && $digitMark['upload'] === true
	&& is_array( $digitMoved ) && $digitMoved['file']['folder'] === $digitOther && $digitMoved['before']['folder'] === $digitFolder && is_array( $digitAdded ) && $digitAdded['id'] === '456789012345'
	&& is_array( $digitFolderGone ) && $digitFolderGone['folder']['id'] === $digitOther && array_column( $digitFolderGone['files'], 'id' ) === [ $digitFile, '456789012345' ] && is_file( (string) \Nino\Modules\Files\Store::bytes( $appData, $digitFile, 'mp3' ) ) === false );
\Nino\Filesystem::putFileContent( $appData, '/data/files.php', [] );

echo "\n";


// --- The download ---------------------------------------------------------------

echo "GET /.files/<id> - the file the kernel streams\n";

// Three members: two of the group the folder belongs to, one of another. Each is signed in once and keeps the
// session of the browser they signed in with, to be put back when the test speaks as them
$ada = \Nino\Modules\Members::add( $appData, 'ada@example.org', 'Ada Lovelace', 'staff' );
$bob = \Nino\Modules\Members::add( $appData, 'bob@example.org', 'Bob', 'guests' );
$cyd = \Nino\Modules\Members::add( $appData, 'cyd@example.org', 'Cyd', 'staff' );
$sessions = [];

foreach( [ 'ada' => $ada, 'bob' => $bob, 'cyd' => $cyd ] as $who => $account ) {
	filesSignIn( $appData, $account );
	$sessions[$who] = $_SESSION;
}

/**
 *	Speak as somebody: the session of the browser they signed in with, or none
 *
 *	@param		string		$who					ada, bob, cyd or '' for a visitor
 *
 *	@return 	void
 */
function filesAs( string $who ): void {

	$_SESSION = $who === '' ? [] : $GLOBALS['sessions'][$who];
	unset( $GLOBALS['appData']['./nino/auth/current'] );
}

$signedIn = [];

foreach( [ 'ada' => $ada, 'bob' => $bob, 'cyd' => $cyd ] as $who => $account ) {
	filesAs( $who );
	$signedIn[$who] = ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

filesVisitor();
check( 'the three members are signed in, each in a session of their own', array_filter( $signedIn ) === $signedIn && count( $signedIn ) === 3 && \Nino\Modules\Members::current( $appData ) === null );

$song = \Nino\Modules\Files\Store::folder( $appData, $staff['id'] );
$content = str_repeat( 'ABCDEFGHIJ', 300 );
\Nino\Filesystem::putFileContent( $appData, '/data/files.php', [] );
$room = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', 'Room' );
$lobby = \Nino\Modules\Files\Store::addFolder( $appData, 'guests', 'Lobby' );
$track = filesMake( $appData, $room['id'], "M\u{00fc}ller\\..\\Demo \"One\".mp3", $content );
$secret = filesMake( $appData, $room['id'], 'Secret.mp3', 'HIDDEN-BYTES', [ 'kind' => 'owner', 'id' => '' ], false );
$cydsOwn = filesMake( $appData, $room['id'], 'Cyds.mp3', 'CYD-BYTES', [ 'kind' => 'member', 'id' => $cyd['id'] ], false );
$pack = filesMake( $appData, $room['id'], 'Pack.zip', 'PK-BYTES', [ 'kind' => 'owner', 'id' => '' ], true, 'application/zip' );
$guestFile = filesMake( $appData, $lobby['id'], 'Guest.mp3', 'GUEST-BYTES' );

check( 'a name is cleaned when a file is made: no path, no quote that was in front of a separator, the extension in lower case', $track['name'] === 'Demo "One".mp3' && \Nino\Modules\Files\Store::cleanName( 'a.MP3' ) === [ 'a.mp3', 'mp3' ] );

$notFound = [];
$answers = [];

foreach( [ 'a visitor' => '', 'a member of another group' => 'bob' ] as $kind => $who ) {
	filesAs( $who );
	$request = filesServe( $appData, '/.files/'. $track['id'] );
	$answers[$kind] = $request['/nino/http/response'];
}

filesAs( 'ada' );

foreach( [ 'an id nobody has' => '/.files/ffffffffffff', 'something that is no id' => '/.files/..%2f..%2fetc%2fpasswd', 'an id with a path behind it' => '/.files/'. $track['id']. '/x', 'the id of a file in the index whose bytes are gone' => '/.files/'. $guestFile['id'] ] as $kind => $uri )
	$answers[$kind] = filesServe( $appData, $uri )['/nino/http/response'];

$visitorAnswer = $answers['a visitor'];
$same = array_filter( $answers, static fn( array $answer ): bool => $answer['statusCode'] === $visitorAnswer['statusCode'] && $answer['body'] === $visitorAnswer['body'] && $answer['header']['Content-Type'] === $visitorAnswer['header']['Content-Type']
	&& array_key_exists( 'file', $answer ) === false && array_key_exists( 'Content-Disposition', $answer['header'] ) === false );
check( 'a visitor, a member of another group, an id nobody has, something that is no id, an id with a path behind it and a file whose bytes are gone are the same 404 with a plain body - nothing tells that an id exists',
	$visitorAnswer['statusCode'] === 404 && $visitorAnswer['body'] === 'Not Found' && str_starts_with( $visitorAnswer['header']['Content-Type'], 'text/plain' ) && $visitorAnswer['header']['Cache-Control'] === 'no-store' && count( $same ) === count( $answers ) );

// The owner's workbench session: with the permission of the panel it is answered with any file that has bytes, a hidden one and one of
// another group included - without it, an account at the workbench is a stranger. Both accounts are made here, the panel's tests use them below
filesVisitor();
ninoUser( $appData, 'admin@example.com' );
ninoUser( $appData, 'plain@example.com', [], 'plain password' );
filesWorkbench( 'admin' );
$ownerAnswers = [];

foreach( [ 'a visible file of a group' => $track, 'a hidden file of the owner' => $secret, 'a hidden file of a member' => $cydsOwn, 'a file of another group' => $guestFile ] as $kind => $each )
	$ownerAnswers[$kind] = filesServe( $appData, '/.files/'. $each['id'] )['/nino/http/response'];

$ownerSaved = filesServe( $appData, '/.files/'. $track['id']. '?download=1' )['/nino/http/response'];
$ownerNothing = [ 'an id nobody has' => filesServe( $appData, '/.files/ffffffffffff' )['/nino/http/response'], 'something that is no id' => filesServe( $appData, '/.files/..%2f..%2fetc%2fpasswd' )['/nino/http/response'] ];
$ownerAllowed = array_map( static fn( array $each ): bool => \Nino\Modules\Files::allowed( $GLOBALS['appData'], $each ), [ $track, $secret, $guestFile ] );
$ownerBrowser = \Nino\Html::renderHtml( $appData, '[files-browser]' );
filesWorkbench( 'plain' );
$plainAnswers = [ 'a visible file of a group' => filesServe( $appData, '/.files/'. $track['id'] )['/nino/http/response'], 'a hidden file of the owner' => filesServe( $appData, '/.files/'. $secret['id'] )['/nino/http/response'] ];
filesVisitor();
$expected = [ 'a visible file of a group' => $track, 'a hidden file of the owner' => $secret, 'a hidden file of a member' => $cydsOwn, 'a file of another group' => $guestFile ];
check( 'the workbench session of an account that holds the permission of the panel is answered with the file named for the kernel - a hidden file of the owner, a hidden file of a member and a file of another group among them',
	array_keys( $ownerAnswers ) === array_keys( $expected ) && array_filter( $ownerAnswers, static fn( array $answer, string $kind ): bool => $answer['statusCode'] !== 200 || ( $answer['file'] ?? '' ) !== \Nino\Modules\Files::path( $GLOBALS['appData'], $GLOBALS['expected'][$kind]['id'] )
		|| $answer['header']['Content-Type'] !== 'audio/mpeg' || $answer['header']['Cache-Control'] !== 'private, no-store' || str_starts_with( $answer['header']['Content-Disposition'], 'inline;' ) === false, ARRAY_FILTER_USE_BOTH ) === [] );
check( '...with ?download=1 it is saved, as for a member - and an id nobody has, or that is none, is the 404 of the others',
	$ownerSaved['statusCode'] === 200 && str_starts_with( $ownerSaved['header']['Content-Disposition'], 'attachment; filename="Demo _One_.mp3"; filename*=' ) === true && $ownerSaved['file'] === $ownerAnswers['a visible file of a group']['file']
	&& array_filter( $ownerNothing, static fn( array $answer ): bool => $answer['statusCode'] !== 404 || $answer['body'] !== 'Not Found' || array_key_exists( 'file', $answer ) === true ) === [] );
check( 'an account at the workbench without the permission of the panel is a stranger: the same 404 as a visitor gets, for a visible file and for a hidden one',
	array_filter( $plainAnswers, static fn( array $answer ): bool => $answer['statusCode'] !== $GLOBALS['visitorAnswer']['statusCode'] || $answer['body'] !== $GLOBALS['visitorAnswer']['body'] || $answer['header']['Content-Type'] !== $GLOBALS['visitorAnswer']['header']['Content-Type']
		|| $answer['header']['Cache-Control'] !== $GLOBALS['visitorAnswer']['header']['Cache-Control'] || array_key_exists( 'file', $answer ) === true || array_key_exists( 'Content-Disposition', $answer['header'] ) === true ) === [] && count( $plainAnswers ) === 2 );
// The permission is the panel's own: held exactly, or by the parent of it ('/_admin/files/*') or from above ('/_admin/*') - another panel's is none
$byPermission = [];

foreach( [ 'files-manage@example.com' => [ '/_admin/files/manage' ], 'files-all@example.com' => [ '/_admin/files/*' ], 'admin-all@example.com' => [ '/_admin/*' ], 'hello-only@example.com' => [ '/_admin/hello/manage' ] ] as $mail => $held ) {
	\Nino\Auth::insertUser( $appData, $mail, 'a long password', $held );
	filesVisitor();
	\Nino\Auth::loginUser( $appData, $mail, 'a long password' );
	$byPermission[$mail] = filesServe( $appData, '/.files/'. $secret['id'] )['/nino/http/response']['statusCode'];
}

filesVisitor();
check( 'the permission is the panel\'s own - held exactly, by the parent \'/_admin/files/*\' or from above by \'/_admin/*\' - and the permission of another panel is none',
	$byPermission === [ 'files-manage@example.com' => 200, 'files-all@example.com' => 200, 'admin-all@example.com' => 200, 'hello-only@example.com' => 404 ] );
check( 'allowed() stays the rule for a member: the owner\'s workbench session is allowed nothing by it, and the browser component shows that session nothing - the route answers the owner on its own', $ownerAllowed === [ false, false, false ] && $ownerBrowser === '' );

filesAs( 'ada' );
$streamed = filesServe( $appData, '/.files/'. $track['id'] );
$response = $streamed['/nino/http/response'];
check( 'a member of the group is answered with the file named for the kernel: its absolute path, the type of the index, shown in the browser for audio, the name twice, kept out of every cache but the browser\'s',
	$response['statusCode'] === 200 && $response['file'] === \Nino\Modules\Files::path( $appData, $track['id'] ) && $response['header']['Content-Type'] === 'audio/mpeg'
	&& $response['header']['Content-Disposition'] === 'inline; filename="Demo _One_.mp3"; filename*=UTF-8\'\'Demo%20%22One%22.mp3' && $response['header']['Cache-Control'] === 'private, no-store' && $response['header']['X-Content-Type-Options'] === 'nosniff' );
check( '...a name with nothing in it is given a plain name of its own, and the type decides what is shown', str_starts_with( \Nino\Modules\Files::disposition( '', true ), 'inline; filename="file";' ) === true && str_starts_with( \Nino\Modules\Files::disposition( ' ', false ), 'attachment; filename="file";' ) === true );
check( '...the bytes are the kernel\'s to send: the handler reads none and sets no length, no range and no body', array_key_exists( 'Content-Length', $response['header'] ) === false && array_key_exists( 'Accept-Ranges', $response['header'] ) === false && ( $response['body'] ?? '' ) === '' );

$saved = filesServe( $appData, '/.files/'. $track['id']. '?download=1' )['/nino/http/response'];
$other = filesServe( $appData, '/.files/'. $track['id']. '?download=0' )['/nino/http/response'];
$zip = filesServe( $appData, '/.files/'. $pack['id'] )['/nino/http/response'];
check( 'with ?download=1 the file is always saved, and a type that is not audio, video, an image or pdf is saved without asking - no other value of download is read', str_starts_with( $saved['header']['Content-Disposition'], 'attachment; filename="Demo _One_.mp3"; filename*=' ) === true
	&& $saved['file'] === $response['file'] && str_starts_with( $zip['header']['Content-Disposition'], 'attachment;' ) === true && $zip['header']['Content-Type'] === 'application/zip' && str_starts_with( $other['header']['Content-Disposition'], 'inline;' ) === true );

$umlaut = filesMake( $appData, $room['id'], "M\u{00fc}ller \u{2013} Mix.mp3", 'UMLAUT' );
$umlautResponse = filesServe( $appData, '/.files/'. $umlaut['id'] )['/nino/http/response'];
check( 'a name with letters outside ASCII has its plain form cut down and its UTF-8 form percent-encoded (filename*, RFC 5987)', str_contains( $umlautResponse['header']['Content-Disposition'], 'filename="M_ller _ Mix.mp3"' ) === true
	&& str_contains( $umlautResponse['header']['Content-Disposition'], "filename*=UTF-8''M%C3%BCller%20%E2%80%93%20Mix.mp3" ) === true && preg_match( '/[^\x20-\x7e]/', $umlautResponse['header']['Content-Disposition'] ) !== 1 );

foreach( [ 'image/svg+xml' => false, 'image/png' => true, 'video/mp4' => true, 'application/pdf' => true, 'text/plain' => false, 'application/zip' => false, 'audio/ogg' => true ] as $type => $inline )
	$shown[$type] = \Nino\Modules\Files\Store::inline( $type ) === $inline;

check( 'audio, video, images and pdf are shown, everything else is saved - and a vector image is saved, since it runs scripts of its own', array_filter( $shown, static fn( bool $same ): bool => $same === false ) === [] && count( $shown ) === 7 );

// Hidden: nobody sees it but the member who put it there
filesAs( 'ada' );
$hiddenForAda = filesServe( $appData, '/.files/'. $secret['id'] )['/nino/http/response']['statusCode'];
$cydsForAda = filesServe( $appData, '/.files/'. $cydsOwn['id'] )['/nino/http/response']['statusCode'];
filesAs( 'cyd' );
$cydsForCyd = filesServe( $appData, '/.files/'. $cydsOwn['id'] );
$secretForCyd = filesServe( $appData, '/.files/'. $secret['id'] )['/nino/http/response']['statusCode'];
check( 'a hidden file is a 404 for a member of the group - and answered for the member who uploaded it, who always sees their own; the owner\'s hidden file is nobody\'s',
	$hiddenForAda === 404 && $cydsForAda === 404 && $cydsForCyd['/nino/http/response']['statusCode'] === 200 && $cydsForCyd['/nino/http/response']['file'] === \Nino\Modules\Files::path( $appData, $cydsOwn['id'] ) && $secretForCyd === 404 );

// ...and the uploader keeps it when the group changes
\Nino\Modules\Members::change( $appData, $cyd['id'], [ 'group' => 'guests' ] );
$afterMove = filesServe( $appData, '/.files/'. $cydsOwn['id'] )['/nino/http/response']['statusCode'];
$afterMoveOther = filesServe( $appData, '/.files/'. $track['id'] )['/nino/http/response']['statusCode'];
\Nino\Modules\Members::change( $appData, $cyd['id'], [ 'group' => 'staff' ] );
check( 'the uploader keeps their own file when they have left the group, and loses the files of the group', $afterMove === 200 && $afterMoveOther === 404 );

// The API says the same as the route
$rule = [];
foreach( [ 'ada' => 'ada', 'bob' => 'bob', 'cyd' => 'cyd', 'a visitor' => '' ] as $kind => $who ) {
	filesAs( $who );
	$rule[$kind] = array_map( static fn( array $file ): bool => \Nino\Modules\Files::allowed( $GLOBALS['appData'], $file ), [ $track, $secret, $cydsOwn, $guestFile ] );
}
check( 'allowed() is the rule of the route for the member who is signed in: the group, the visibility, the uploader - and nobody at all for a visitor',
	$rule === [ 'ada' => [ true, false, false, false ], 'bob' => [ false, false, false, true ], 'cyd' => [ true, false, true, false ], 'a visitor' => [ false, false, false, false ] ] );

// The kernel's part, pinned once: a regression of the file response shows here
filesAs( 'ada' );
$finalize = new ReflectionMethod( '\Nino\Http', '_finalizeResponse' );
$whole = filesServe( $appData, '/.files/'. $track['id'] );
$finalize->invokeArgs( null, [ &$whole ] );
$ranged = filesServe( $appData, '/.files/'. $track['id'], 'GET', [], true, [], [ 'HTTP_RANGE' => 'bytes=1000-1999' ] );
$finalize->invokeArgs( null, [ &$ranged ] );
$headed = filesServe( $appData, '/.files/'. $track['id'], 'HEAD' );
$finalize->invokeArgs( null, [ &$headed ] );
check( 'the kernel answers the file named: the whole of it with its length and Accept-Ranges, a 206 for one range with the Content-Range of the part, and the headers of the handler stand',
	$whole['/nino/http/response']['statusCode'] === 200 && $whole['/nino/http/response']['header']['Content-Length'] === (string) strlen( $content ) && $whole['/nino/http/response']['header']['Accept-Ranges'] === 'bytes'
	&& $whole['/nino/http/response']['header']['Content-Type'] === 'audio/mpeg' && str_starts_with( $whole['/nino/http/response']['header']['Content-Disposition'], 'inline;' ) === true
	&& $whole['./nino/http/stream'] === [ 'path' => $response['file'], 'start' => 0, 'length' => strlen( $content ) ]
	&& $ranged['/nino/http/response']['statusCode'] === 206 && $ranged['/nino/http/response']['header']['Content-Range'] === 'bytes 1000-1999/'. strlen( $content ) && $ranged['/nino/http/response']['header']['Content-Length'] === '1000'
	&& $ranged['./nino/http/stream'] === [ 'path' => $response['file'], 'start' => 1000, 'length' => 1000 ] && $headed['/nino/http/response']['statusCode'] === 200 );

// A file that is in the index and not on the disk is a 404, the same one
unlink( (string) \Nino\Modules\Files::path( $appData, $guestFile['id'] ) );
filesAs( 'bob' );
$gone = filesServe( $appData, '/.files/'. $guestFile['id'] )['/nino/http/response'];
filesWorkbench( 'admin' );
$goneForOwner = filesServe( $appData, '/.files/'. $guestFile['id'] )['/nino/http/response'];
check( 'a file whose bytes are gone is the 404 of the others, not an error - for the owner too', $gone['statusCode'] === 404 && $gone['body'] === 'Not Found' && array_key_exists( 'file', $gone ) === false
	&& $goneForOwner['statusCode'] === 404 && $goneForOwner['body'] === 'Not Found' && array_key_exists( 'file', $goneForOwner ) === false );
filesVisitor();

echo "\n";

// --- The upload - the owner's, through the panel ----------------------------------

echo "Modules\\Files\\Upload - the owner's upload in chunks, through the panel action files/upload\n";

// A set-up project with a plain account and a full-access one, the way the wizard leaves them
$appData['/nino/install/completed'] = true;
filesVisitor();
filesWorkbench( 'admin' );

/**
 *	One chunk through the panel: the file part is set the way php sets it and the action is called as the workbench calls it
 *
 *	@param		array 		&$appData
 *	@param		array 		$fields				folder, name, size, upload, index, count and, for the owner, visible
 *	@param		array 		$part					The entry of $_FILES
 *
 *	@return 	array										[ status, body, request ]
 */
function filesOwnerSend( array &$appData, array $fields, array $part ): array {

	$_FILES = [ 'chunk' => $part ];
	$answer = ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], $fields, 'files/upload' );
	$_FILES = [];

	return $answer;
}

/**
 *	One chunk through the route of the members, as the script posts it
 *
 *	@param		array 		&$appData
 *	@param		array 		$fields
 *	@param		array 		$part
 *
 *	@return 	array										[ status, body, request ]
 */
function filesMemberSend( array &$appData, array $fields, array $part ): array {

	// A post carries strings, whatever the script means by them
	$request = filesServe( $appData, '/.files/upload', 'POST', array_map( static fn( mixed $value ): mixed => is_int( $value ) === true ? (string) $value : $value, $fields ), true, [ 'chunk' => $part ] );

	return [ $request['/nino/http/response']['statusCode'], $request['/nino/http/response']['body'] ?? null, $request ];
}

/**
 *	A file posted in chunks, in order, the way the script does it: the answer of one carries the upload id of the next
 *
 *	@param		array 		&$appData
 *	@param		string		$send					The function that posts a chunk: filesOwnerSend or filesMemberSend
 *	@param		string		$name
 *	@param		string		$bytes
 *	@param		array 		$extra				More fields, folder among them
 *
 *	@return 	array										The answers [ status, body, request ] of the chunks that were posted - it stops at the first that is not a 200
 */
function filesUpload( array &$appData, string $send, string $name, string $bytes, array $extra = [] ): array {

	$chunk = \Nino\Modules\Files\Store::chunkBytes( $appData );
	$count = max( 1, (int) ceil( strlen( $bytes ) / $chunk ) );
	$upload = '';
	$answers = [];

	for( $index = 0; $index < $count; $index++ ) {

		$answer = $send( $appData, $extra + [ 'name' => $name, 'size' => strlen( $bytes ), 'upload' => $upload, 'index' => $index, 'count' => $count ], filesPart( $appData, substr( $bytes, $index * $chunk, $chunk ) ) );
		$answers[] = $answer;

		if( $answer[0] !== 200 )
			break;

		$upload = (string) ( $answer[1]['upload'] ?? '' );
	}

	return $answers;
}

/**
 *	A file posted in chunks of a size of the script's choosing (a script that found the web server takes less than the setting): the first chunk sets
 *	the chunk of the upload
 *
 *	@param		array 		&$appData
 *	@param		string		$send					filesOwnerSend or filesMemberSend
 *	@param		string		$name
 *	@param		string		$bytes
 *	@param		int				$chunk				The bytes of a chunk
 *	@param		array 		$extra				More fields, folder among them
 *
 *	@return 	array										The answers [ status, body, request ] - it stops at the first that is not a 200
 */
function filesUploadIn( array &$appData, string $send, string $name, string $bytes, int $chunk, array $extra = [] ): array {

	$count = max( 1, (int) ceil( strlen( $bytes ) / $chunk ) );
	$upload = '';
	$answers = [];

	for( $index = 0; $index < $count; $index++ ) {

		$answer = $send( $appData, $extra + [ 'name' => $name, 'size' => strlen( $bytes ), 'upload' => $upload, 'index' => $index, 'count' => $count ], filesPart( $appData, substr( $bytes, $index * $chunk, $chunk ) ) );
		$answers[] = $answer;

		if( $answer[0] !== 200 )
			break;

		$upload = (string) ( $answer[1]['upload'] ?? '' );
	}

	return $answers;
}

/**
 *	@param		array 		&$appData
 *
 *
 *	@return 	array										The upload parts that lie in the directory, by name (the .chunk files beside them are filesKept()'s)
 */
function filesParts( array &$appData ): array {

	return array_map( 'basename', glob( \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/*.part' ) ?: [] );
}

/**
 *	@param		array 		&$appData
 *
 *	@return 	array										The files that keep the length of a first chunk beside the parts, by name
 */
function filesKept( array &$appData ): array {

	return array_map( 'basename', glob( \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/*.chunk' ) ?: [] );
}

/**
 *	@param		string		$path
 *
 *	@return 	int											The size of a file as it is now - php keeps the size it saw last, and a part grows between two looks
 */
function filesSize( string $path ): int {

	clearstatcache( true, $path );

	return (int) @filesize( $path );
}

// The setting chunk is in megabytes: one, which php takes here (upload_max_filesize is 2M in a bare cli)
$block = '';
for( $each = 0; $each < 64; $each++ )
	$block .= hash( 'sha512', (string) $each, true );
$mp3 = "\xFF\xFB\x90\x00". substr( str_repeat( $block, 700 ), 0, 2499996 );
$chunkBytes = 1048576;

check( 'the settings of the test are the ones the plan names, changed through the kernel: a chunk of one megabyte, a file of at most five',
	\Nino\Features::saveSettings( $appData, 'files', [ 'chunk' => 1, 'maxSize' => 5 ] ) === [] && \Nino\Modules\Files\Store::chunkBytes( $appData ) === $chunkBytes && \Nino\Modules\Files\Store::maxBytes( $appData ) === 5 * $chunkBytes
	&& \Nino\Modules\Files\Store::quotaBytes( $appData ) === 0 && strlen( $mp3 ) === 2500000 );

// Denied: the action is the panel's
filesWorkbench( '' );
$denied = [ 'signed out' => ninoCall( $appData, $panel, [], 'files/list' ), 'without the permission' => null ];
filesWorkbench( 'plain' );
$denied['without the permission'] = ninoCall( $appData, $panel, [], 'files/list' );
filesWorkbench( 'admin' );

$posts = [ 'files/list' => [], 'files/folder/add' => [ 'group' => 'staff', 'name' => 'x' ], 'files/folder/save' => [ 'id' => 'ffffffffffff', 'name' => 'x' ], 'files/folder/delete' => [ 'id' => 'ffffffffffff' ],
	'files/folder/upload-target' => [ 'id' => 'ffffffffffff' ], 'files/upload' => [ 'folder' => $room['id'], 'name' => 'a.mp3', 'size' => 1, 'upload' => '', 'index' => 0, 'count' => 1 ], 'files/save' => [ 'id' => 'ffffffffffff', 'visible' => true ],
	'files/delete' => [ 'id' => 'ffffffffffff' ] ];
$refused = [ 'signed out' => [], 'without the permission' => [] ];
$before = filesIndex( $appData );

foreach( $posts as $action => $data ) {
	filesWorkbench( '' );
	$refused['signed out'][$action] = ninoCall( $appData, $panel, $data, $action )[0];
	filesWorkbench( 'plain' );
	$refused['without the permission'][$action] = ninoCall( $appData, $panel, $data, $action )[0];
}

filesWorkbench( 'admin' );
check( 'every action refuses a visitor with 401 and an account without the permission with 403, and writes nothing', array_values( array_unique( $refused['signed out'] ) ) === [ 401 ] && array_values( array_unique( $refused['without the permission'] ) ) === [ 403 ]
	&& count( $refused['signed out'] ) === 8 && $denied['signed out'][1]['code'] === 'session' && filesIndex( $appData ) === $before && filesParts( $appData ) === [] );
check( '...and the actions are the eight there are - none of them downloads, that is the route\'s - all under the slug of the panel', array_keys( array_filter( \Nino\Admin\Admin::actions( $appData ), static fn( string $action ): bool => str_starts_with( $action, 'files/' ), ARRAY_FILTER_USE_KEY ) )
	=== [ 'files/list', 'files/folder/add', 'files/folder/save', 'files/folder/delete', 'files/folder/upload-target', 'files/upload', 'files/save', 'files/delete' ] );

// Three chunks of a known file give one entry
$told = [];
$owner = filesUpload( $appData, 'filesOwnerSend', 'Take One.MP3', $mp3, [ 'folder' => $room['id'] ] );
$uploaded = $owner[2][1] ?? [];
$entry = \Nino\Modules\Files::file( $appData, (string) ( $uploaded['id'] ?? '' ) );
check( 'three chunks of a known file are answered in order - the first with the upload id (16 hex characters), the second with the same, the last with the id, the name and the size of the file',
	array_column( $owner, 0 ) === [ 200, 200, 200 ] && preg_match( '/^[a-f0-9]{16}$/D', $owner[0][1]['upload'] ) === 1 && $owner[0][1] === [ 'upload' => $owner[0][1]['upload'], 'index' => 0 ] && $owner[1][1] === [ 'upload' => $owner[0][1]['upload'], 'index' => 1 ]
	&& array_keys( $uploaded ) === [ 'id', 'name', 'size', 'mailed' ] && $uploaded['name'] === 'Take One.mp3' && $uploaded['size'] === 2500000 && preg_match( '/^[a-f0-9]{12}$/D', $uploaded['id'] ) === 1 );
check( '...the entry has the right size, sha256, MIME type and name, is the owner\'s, visible and in the folder, and the bytes on disk are the fixture',
	$entry !== null && $entry['size'] === 2500000 && $entry['sha256'] === hash( 'sha256', $mp3 ) && $entry['mime'] === 'audio/mpeg' && $entry['name'] === 'Take One.mp3' && $entry['ext'] === 'mp3'
	&& $entry['by'] === [ 'kind' => 'owner', 'id' => '' ] && $entry['visible'] === true && $entry['folder'] === $room['id'] && hash_file( 'sha256', (string) \Nino\Modules\Files::path( $appData, $entry['id'] ) ) === hash( 'sha256', $mp3 )
	&& file_get_contents( (string) \Nino\Modules\Files::path( $appData, $entry['id'] ) ) === $mp3 );
check( '...the upload leaves no part behind, the callback /files/add is told once with the entry, and nothing is told as a member\'s upload', filesParts( $appData ) === [] && $told === [ [ '/files/add', $entry ] ]
	&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $entry['created'] ) === 1 );

// While it is under way the part is on the disk and as long as the chunks that came
$told = [];
$first = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
$partFile = \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/'. ( $first[1]['upload'] ?? 'x' ). '.part';
check( 'while an upload is under way, its part lies in /data/files/.upload and is as long as the chunks that came', $first[0] === 200 && is_file( $partFile ) === true && filesSize( $partFile ) === $chunkBytes && $told === [] );

// Out of order, twice, short, long and gone
$upload = $first[1]['upload'];
$outOfOrder = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => 2, 'count' => 3 ], filesPart( $appData, substr( $mp3, 2 * $chunkBytes ) ) );
$twice = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => 0, 'count' => 3 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
$long = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes + 1 ) ) );
$short = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, 1000 ) ) );
$unknownUpload = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => 'aaaaaaaaaaaaaaaa', 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes ) ) );
$badUpload = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => '../../x', 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes ) ) );
check( 'a chunk out of order, a first chunk that names an upload, a chunk that is longer or shorter than its place asks, one of an upload that is not there and one that names a path are all 400 files_chunk - and the part stays as it was',
	array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), [ $outOfOrder, $twice, $long, $short, $unknownUpload, $badUpload ] ) ) ) === [ '400 files_chunk' ] && filesSize( $partFile ) === $chunkBytes && filesParts( $appData ) === [ basename( $partFile ) ] );

// A part that was cut on the disk between two chunks
$filesBefore = count( filesIndex( $appData )['files'] );
file_put_contents( $partFile, 'cut', LOCK_EX );
$cut = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Second.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes ) ) );
check( 'a part that is not as long as the chunks before it should have made it - cut on the disk - is 400 files_chunk as well, and no file comes of it', $cut[0] === 400 && $cut[1]['code'] === 'files_chunk' && count( filesIndex( $appData )['files'] ) === $filesBefore );
unlink( $partFile );

// The stale parts: older than a day they are gone when the next upload starts
$stale = \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/0123456789abcdef.part';
$fresh = \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/fedcba9876543210.part';
file_put_contents( $stale, 'old' );
file_put_contents( $fresh, 'new' );
touch( $stale, time() - 86400 - 60 );
touch( $fresh, time() - 86400 + 600 );
$starting = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Third.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
check( 'the next upload that starts removes the parts that are older than 24 hours and leaves the others', is_file( $stale ) === false && is_file( $fresh ) === true && $starting[0] === 200 && count( filesParts( $appData ) ) === 2 );
unlink( $fresh );
unlink( \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/'. $starting[1]['upload']. '.part' );

// The rest of the protocol: the numbers
$numbers = [];
foreach( [ 'a count that does not fit the size' => [ 'size' => strlen( $mp3 ), 'count' => 2, 'index' => 0 ], 'an index past the count' => [ 'size' => strlen( $mp3 ), 'count' => 3, 'index' => 3 ],
	'a size of nothing' => [ 'size' => 0, 'count' => 1, 'index' => 0 ], 'a size that is no number' => [ 'size' => 'a lot', 'count' => 1, 'index' => 0 ], 'a negative index' => [ 'size' => 5, 'count' => 1, 'index' => -1 ],
	'a list for the count' => [ 'size' => 5, 'count' => [ 3 ], 'index' => 0 ] ] as $kind => $fields ) {
	$answer = filesOwnerSend( $appData, $fields + [ 'folder' => $room['id'], 'name' => 'Numbers.mp3', 'upload' => '' ], filesPart( $appData, 'abcde' ) );
	$numbers[$kind] = $answer[0]. ' '. ( $answer[1]['code'] ?? '' );
}
check( 'numbers that do not fit - a count that is not what the size asks for, an index past the count, a size of nothing or of a word, a negative index, a list - are 400 files_chunk, and no part is made', array_values( array_unique( $numbers ) ) === [ '400 files_chunk' ] && filesParts( $appData ) === [] );

// ...a size of thirty digits is no number, and a count that would let the last chunk grow past the chunk of the setting is refused though the first chunk is the length the count asks for
$digits = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Digits.mp3', 'size' => str_repeat( '9', 30 ), 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
$growing = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Growing.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 2 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
check( '...a size of thirty digits is no number, and a count that lets the last chunk grow past the chunk of the setting is refused though the first chunk fits it - both 400 files_chunk, no part made', $digits[0] === 400 && $digits[1]['code'] === 'files_chunk'
	&& $growing[0] === 400 && $growing[1]['code'] === 'files_chunk' && filesParts( $appData ) === [] );

// An upload id that is a path to a file that is there: that file is no part, and it is left as it is
$stray = \Nino\Filesystem::path( $appData, '/data/files' ). '/stray.part';
file_put_contents( $stray, str_repeat( 's', $chunkBytes ) );
$viaPath = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Path.mp3', 'size' => strlen( $mp3 ), 'upload' => '../stray', 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes ) ) );
check( 'an upload id that is a path to a file that is there is refused all the same - 400 files_chunk - and the file is not touched', $viaPath[0] === 400 && $viaPath[1]['code'] === 'files_chunk' && filesSize( $stray ) === $chunkBytes && file_get_contents( $stray ) === str_repeat( 's', $chunkBytes ) );
unlink( $stray );

// A name that is a list is no name, and raises no warning
ninoWarnings();
$listName = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => [ 'x.mp3' ], 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
check( 'a name that is a list is no name: 400 files_type, and no warning', $listName[0] === 400 && $listName[1]['code'] === 'files_type' && ninoWarnings() === [] && filesParts( $appData ) === [] );

// A chunk whose bytes are fewer than its part says (the part php stored is cut short): a first one leaves no part, a later one leaves the part as long as it was
$partsDir = \Nino\Filesystem::path( $appData, '/data/files/.upload' );
$lying = filesPart( $appData, 'only ten b' );
$lying['size'] = 100;
$lieFirst = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Lie.txt', 'size' => 100, 'upload' => '', 'index' => 0, 'count' => 1 ], $lying );
check( 'a chunk with fewer bytes than its part says is a 500 files_write, and a first chunk leaves no part behind', $lieFirst[0] === 500 && $lieFirst[1]['code'] === 'files_write' && filesParts( $appData ) === [] );

$honest = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Lie.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
$lyingSecond = filesPart( $appData, 'only ten b' );
$lyingSecond['size'] = $chunkBytes;
$lieSecond = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Lie.mp3', 'size' => strlen( $mp3 ), 'upload' => $honest[1]['upload'], 'index' => 1, 'count' => 3 ], $lyingSecond );
$lieFile = $partsDir. '/'. $honest[1]['upload']. '.part';
check( '...a later chunk like that leaves the part as long as it was, so that the same chunk can be sent again', $lieSecond[0] === 500 && $lieSecond[1]['code'] === 'files_write' && filesSize( $lieFile ) === $chunkBytes );
$again = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Lie.mp3', 'size' => strlen( $mp3 ), 'upload' => $honest[1]['upload'], 'index' => 1, 'count' => 3 ], filesPart( $appData, substr( $mp3, $chunkBytes, $chunkBytes ) ) );
check( '...and it is taken when it comes whole', $again[0] === 200 && filesSize( $lieFile ) === 2 * $chunkBytes );
unlink( $lieFile );
unlink( $partsDir. '/'. $honest[1]['upload']. '.chunk' );

// The directory of the parts is a file: nothing can be put there, and that is a 500 files_write - the directory is made again when the file is gone
rmdir( $partsDir );
file_put_contents( $partsDir, 'in the way' );
$blocked = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Blocked.txt', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
unlink( $partsDir );
$unblocked = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Unblocked.txt', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
check( 'where the directory of the parts cannot be made the upload is a 500 files_write - and it works again once the way is free', $blocked[0] === 500 && $blocked[1]['code'] === 'files_write' && $unblocked[0] === 200 );
\Nino\Modules\Files\Store::deleteFile( $appData, (string) ( $unblocked[1]['id'] ?? '' ) );

// The last step does not trust the size it was told: a part that is not as long as the file says is refused and removed
$assemble = new ReflectionMethod( '\Nino\Modules\Files\Upload', '_assemble' );
$oddPart = $partsDir. '/0123456789abcdef.part';
file_put_contents( $oddPart, 'ten bytes!' );
$filesNow = count( filesIndex( $appData )['files'] );
$assembled = $assemble->invokeArgs( null, [ &$appData, $room, $oddPart, [ 'name' => 'odd.txt', 'ext' => 'txt', 'size' => 20 ], [ 'kind' => 'owner', 'id' => '' ], true, 0 ] );
check( 'the last step of an upload does not trust the size it was told: a part that is not as long as the file says is 400 files_chunk, the part is removed and no file comes of it', ( $assembled['fail'][0] ?? 0 ) === 400 && ( $assembled['fail'][2] ?? '' ) === 'files_chunk'
	&& is_file( $oddPart ) === false && count( filesIndex( $appData )['files'] ) === $filesNow );

// A folder that is deleted while the last chunk is on its way: the file is not kept
$bytesNow = count( glob( $bytesDir. '/*' ) ?: [] );
$shortLived = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', 'Short-lived' );
$staleFolder = \Nino\Modules\Files\Store::folder( $appData, $shortLived['id'] );
\Nino\Modules\Files\Store::deleteFolder( $appData, $shortLived['id'] );
$lateAnswer = \Nino\Modules\Files\Upload::receive( $appData, $staleFolder, [ 'name' => 'late.txt', 'size' => '6', 'upload' => '', 'index' => '0', 'count' => '1' ], filesPart( $appData, "Notes\n" ), [ 'kind' => 'owner', 'id' => '' ], true );
check( 'a folder that is deleted while the last chunk is on its way: the file is refused as a 404 files_folder, and neither its bytes nor its part are kept', ( $lateAnswer['fail'][0] ?? 0 ) === 404 && ( $lateAnswer['fail'][2] ?? '' ) === 'files_folder'
	&& count( glob( $bytesDir. '/*' ) ?: [] ) === $bytesNow && filesParts( $appData ) === [] );

// What the name says
$filesBefore = count( filesIndex( $appData )['files'] );
$names = [];
foreach( [ 'virus.exe', 'noextension', '.mp3', 'photo.mp3.exe', '', 'archive.tar.gz', 'double..' ] as $name ) {
	$answer = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => $name, 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
	$names[$name] = $answer[0]. ' '. ( $answer[1]['code'] ?? '' ). ' '. ( $answer[1]['field'] ?? '' );
}
check( 'an extension outside the types, a name with none, one that is only an extension, and a name that is empty are 400 files_type with the field name - and nothing is made', array_values( array_unique( $names ) ) === [ '400 files_type name' ] && filesParts( $appData ) === []
	&& count( filesIndex( $appData )['files'] ) === $filesBefore );

// The setting types: a dot, blanks and capitals are forgiven, an extension the feature has no rule for is not taken, and none is taken twice
$defaultTypes = $manifest['settings']['types']['default'];
\Nino\Features::saveSettings( $appData, 'files', [ 'types' => [ '.MP3', ' wav ', 'exe', 'mp3', 'Flac', 'x y' ] ] );
$typesRead = \Nino\Modules\Files\Store::types( $appData );
check( 'the types are read as the setting says: a dot, blanks and capitals forgiven, an extension the feature has no rule for left out, and none twice', $typesRead === [ 'mp3', 'wav', 'flac' ] );

// ...and an extension the feature knows but the setting does not list is refused at the first chunk, before a part is made
\Nino\Features::saveSettings( $appData, 'files', [ 'types' => [ 'mp3' ] ] );
$notListed = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Song.wav', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, substr( $mp3, 0, $chunkBytes ) ) );
\Nino\Features::saveSettings( $appData, 'files', [ 'types' => $defaultTypes ] );
check( '...an extension the feature knows but the setting types does not list is 400 files_type at the first chunk, with the field name - and no part is made', $notListed[0] === 400 && $notListed[1]['code'] === 'files_type' && $notListed[1]['field'] === 'name' && filesParts( $appData ) === [] );

$dirty = filesUpload( $appData, 'filesOwnerSend', "..\\..\\evil\\sub/dir/Track\x07\x00 Two.MP3", "\xFF\xFB\x90\x00". str_repeat( 'z', 50 ), [ 'folder' => $room['id'] ] );
$dirtyEntry = \Nino\Modules\Files::file( $appData, (string) ( $dirty[0][1]['id'] ?? '' ) );
check( 'a name with a path and control characters is cleaned: the last segment, without the characters, the extension in lower case - and the file is kept under the id, not under the name', $dirty[0][0] === 200 && ( $dirtyEntry['name'] ?? '' ) === 'Track Two.mp3' && $dirtyEntry['ext'] === 'mp3'
	&& basename( (string) \Nino\Modules\Files::path( $appData, $dirtyEntry['id'] ) ) === $dirtyEntry['id']. '.mp3' );

$longName = filesUpload( $appData, 'filesOwnerSend', str_repeat( 'n', 300 ). '.MP3', "\xFF\xFB\x90\x00". str_repeat( 'z', 50 ), [ 'folder' => $room['id'] ] );
$longEntry = \Nino\Modules\Files::file( $appData, (string) ( $longName[0][1]['id'] ?? '' ) );
check( 'a name of more than 160 characters is cut to 160 with the extension kept whole', mb_strlen( $longEntry['name'] ?? '' ) === 160 && str_ends_with( $longEntry['name'] ?? '', 'nnn.mp3' ) === true );
check( 'the cleaning, on its own: nothing but the last segment, no control or format character that turns a name around, an extension of one to eight letters and digits', \Nino\Modules\Files\Store::cleanName( "a/b\\c.PDF" ) === [ 'c.pdf', 'pdf' ]
	&& \Nino\Modules\Files\Store::cleanName( "evil\u{202e}fdp.exe" ) === [ 'evilfdp.exe', 'exe' ] && \Nino\Modules\Files\Store::cleanName( 'x.verylongext' ) === [ '', '' ] && \Nino\Modules\Files\Store::cleanName( '...' ) === [ '', '' ]
	&& \Nino\Modules\Files\Store::cleanName( "bad\xffbyte.mp3" )[1] === 'mp3' && mb_check_encoding( \Nino\Modules\Files\Store::cleanName( "bad\xffbyte.mp3" )[0], 'UTF-8' ) === true
	&& \Nino\Modules\Files\Store::cleanName( '..hidden.mp3' ) === [ 'hidden.mp3', 'mp3' ] && \Nino\Modules\Files\Store::cleanName( '. .mp3' ) === [ '', '' ] );

// Sniffed: what the bytes are decides
$filesBefore = count( filesIndex( $appData )['files'] );
$bytesBefore = count( glob( $bytesDir. '/*' ) ?: [] );
$fake = filesUpload( $appData, 'filesOwnerSend', 'archive.zip', "this is plain text, no zip\n", [ 'folder' => $room['id'] ] );
$php = filesUpload( $appData, 'filesOwnerSend', 'photo.jpg', "<?php echo 'x';", [ 'folder' => $room['id'] ] );
$html = filesUpload( $appData, 'filesOwnerSend', 'song.mp3', '<!DOCTYPE html><html><body>x</body></html>', [ 'folder' => $room['id'] ] );
check( 'a .zip whose bytes are a text file, a .jpg that is php and an .mp3 that is a page are 400 files_type - sniffed by finfo from the bytes - the part is removed and no file, no bytes and no entry is left',
	array_values( array_unique( array_map( static fn( array $answers ): string => $answers[0][0]. ' '. $answers[0][1]['code'], [ $fake, $php, $html ] ) ) ) === [ '400 files_type' ] && filesParts( $appData ) === [] && count( filesIndex( $appData )['files'] ) === $filesBefore && count( glob( $bytesDir. '/*' ) ?: [] ) === $bytesBefore );

$text = filesUpload( $appData, 'filesOwnerSend', 'notes.txt', "Notes\nof the day\n", [ 'folder' => $room['id'] ] );
$textEntry = \Nino\Modules\Files::file( $appData, (string) ( $text[0][1]['id'] ?? '' ) );
check( 'a file of one chunk is one request, and a text file is text/plain', count( $text ) === 1 && $text[0][0] === 200 && $textEntry['mime'] === 'text/plain' && $textEntry['size'] === strlen( "Notes\nof the day\n" ) );

// Lines with commas in them: libmagic calls such a text text/csv whatever the name says, and a .txt or an .md of it is what it says
$comma = "a,b,c\n1,2,3\n4,5,6\n";
\Nino\Features::saveSettings( $appData, 'files', [ 'types' => array_merge( $defaultTypes, [ 'md' ] ) ] );
$csvTxt = filesUpload( $appData, 'filesOwnerSend', 'Lines.txt', $comma, [ 'folder' => $room['id'] ] );
$csvMd = filesUpload( $appData, 'filesOwnerSend', 'Lines.md', $comma, [ 'folder' => $room['id'] ] );
\Nino\Features::saveSettings( $appData, 'files', [ 'types' => $defaultTypes ] );
$csvTxtEntry = \Nino\Modules\Files::file( $appData, (string) ( $csvTxt[0][1]['id'] ?? '' ) );
$csvMdEntry = \Nino\Modules\Files::file( $appData, (string) ( $csvMd[0][1]['id'] ?? '' ) );
$commaPath = \Nino\Filesystem::path( $appData, '/data/files' ). '/comma-test.txt';
file_put_contents( $commaPath, $comma );
$commaTypes = [ 'txt' => \Nino\Modules\Files\Store::sniff( $commaPath, 'txt' ), 'md' => \Nino\Modules\Files\Store::sniff( $commaPath, 'md' ) ];
unlink( $commaPath );
check( 'a .txt and an .md of comma-separated lines are taken though finfo may call them text/csv - the type of the entry is the one finfo gave, one the map lists for the extension',
	$csvTxt[0][0] === 200 && $csvMd[0][0] === 200 && in_array( $csvTxtEntry['mime'] ?? '', \Nino\Modules\Files\Store::MIME['txt'], true ) === true && in_array( $csvMdEntry['mime'] ?? '', \Nino\Modules\Files\Store::MIME['md'], true ) === true
	&& $commaTypes['txt'] !== '' && $commaTypes['md'] !== '' && in_array( 'text/csv', \Nino\Modules\Files\Store::MIME['txt'], true ) === true && in_array( 'text/csv', \Nino\Modules\Files\Store::MIME['md'], true ) === true );

// The limits
$tooBig = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Big.mp3', 'size' => 5 * $chunkBytes + 1, 'upload' => '', 'index' => 0, 'count' => 6 ], filesPart( $appData, str_repeat( 'a', $chunkBytes ) ) );
check( 'a file over the largest size is 400 files_size, with the limit in megabytes and the field size, before a part is made', $tooBig[0] === 400 && $tooBig[1]['code'] === 'files_size' && $tooBig[1]['params'] === [ 5 ] && $tooBig[1]['field'] === 'size' && filesParts( $appData ) === [] );
\Nino\Features::saveSettings( $appData, 'files', [ 'maxSize' => 4 ] );
$edge = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Edge.mp3', 'size' => 4 * $chunkBytes, 'upload' => '', 'index' => 0, 'count' => 4 ], filesPart( $appData, str_repeat( 'a', $chunkBytes ) ) );
$over = filesOwnerSend( $appData, [ 'folder' => $room['id'], 'name' => 'Edge.mp3', 'size' => 4 * $chunkBytes + 1, 'upload' => '', 'index' => 0, 'count' => 5 ], filesPart( $appData, str_repeat( 'a', $chunkBytes ) ) );
unlink( \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/'. $edge[1]['upload']. '.part' );
check( '...a file of exactly the largest size is taken and one byte more is not - the number is the setting\'s', $edge[0] === 200 && $over[0] === 400 && $over[1]['code'] === 'files_size' && $over[1]['params'] === [ 4 ] );

\Nino\Features::saveSettings( $appData, 'files', [ 'maxSize' => 5, 'quota' => 3 ] );
$small = "\xFF\xFB\x90\x00". str_repeat( 'f', 400000 );
$fitting = filesUpload( $appData, 'filesOwnerSend', 'Fits.mp3', $small, [ 'folder' => $room['id'] ] );
$crossing = filesUpload( $appData, 'filesOwnerSend', 'Crosses.mp3', $mp3, [ 'folder' => $room['id'] ] );
$another = filesUpload( $appData, 'filesOwnerSend', 'Another.mp3', $small, [ 'folder' => $room['id'] ] );
$elsewhere = filesUpload( $appData, 'filesOwnerSend', 'Other.mp3', $small, [ 'folder' => $lobby['id'] ] );
check( 'the quota of the group refuses the file that would cross it - 400 files_quota with the quota in megabytes, before a part is made - and takes the one that fits; the other group has its own room',
	$fitting[0][0] === 200 && $crossing[0][0] === 400 && $crossing[0][1]['code'] === 'files_quota' && $crossing[0][1]['params'] === [ 3 ] && $another[0][0] === 400 && $another[0][1]['code'] === 'files_quota' && $elsewhere[0][0] === 200 && filesParts( $appData ) === [] );

// ...and inside the write: the quota is decided where two uploads at once cannot both fit
$members = \Nino\Modules\Files\Store::addFile( $appData, 'abababababab', [ 'folder' => $room['id'], 'name' => 'x.mp3', 'ext' => 'mp3', 'size' => 500000, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ], 3 * $chunkBytes );
$nowhere = \Nino\Modules\Files\Store::addFile( $appData, 'acacacacacac', [ 'folder' => 'ffffffffffff', 'name' => 'x.mp3', 'ext' => 'mp3', 'size' => 1, 'mime' => '', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ], 0 );
$taken = \Nino\Modules\Files\Store::addFile( $appData, $entry['id'], [ 'folder' => $room['id'], 'name' => 'x.mp3', 'ext' => 'mp3', 'size' => 1, 'mime' => '', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ], 0 );
check( 'the store itself refuses a file that crosses the quota, one for a folder that is gone and one under an id in use - and writes none', $members === 'quota' && $nowhere === 'folder' && $taken === 'taken' && isset( filesIndex( $appData )['files']['abababababab'] ) === false );
$usedNow = \Nino\Modules\Files\Store::used( $appData, 'staff' );
$exactFit = \Nino\Modules\Files\Store::addFile( $appData, 'adadadadadad', [ 'folder' => $room['id'], 'name' => 'x.mp3', 'ext' => 'mp3', 'size' => 1, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ], $usedNow + 1 );
\Nino\Modules\Files\Store::deleteFile( $appData, 'adadadadadad' );
$room_left = 3 * 1048576 - $usedNow;
$exact = filesUpload( $appData, 'filesOwnerSend', 'Exact.txt', str_repeat( 'x', $room_left ), [ 'folder' => $room['id'] ] );
$oneMore = filesUpload( $appData, 'filesOwnerSend', 'One more.txt', 'x', [ 'folder' => $room['id'] ] );
$exactFull = \Nino\Modules\Files\Store::used( $appData, 'staff' );
\Nino\Modules\Files\Store::deleteFile( $appData, (string) ( end( $exact )[1]['id'] ?? '' ) );
check( 'the quota is a limit one may reach: a file that fills the room of the group exactly is taken - by the upload and by the store - and one byte more is not', is_array( $exactFit ) === true && $room_left > 0 && end( $exact )[0] === 200 && $exactFull === 3 * 1048576
	&& $oneMore[0][0] === 400 && $oneMore[0][1]['code'] === 'files_quota' && \Nino\Modules\Files\Store::used( $appData, 'staff' ) === $usedNow );
\Nino\Features::saveSettings( $appData, 'files', [ 'quota' => 0 ] );

// A part that did not arrive is the shell's refusal
$missing = ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'a.mp3', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], 'files/upload' );
$_FILES = [ 'chunk' => [ 'name' => 'blob', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 ] ];
$iniSize = ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'a.mp3', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], 'files/upload' );
$_FILES = [ 'chunk' => [ 'name' => 'blob', 'tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0 ] ];
$partial = ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'a.mp3', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], 'files/upload' );
$_FILES = [];
check( 'a chunk that did not arrive is answered with the shell\'s codes - none received 400 upload_missing, over the limit of php 413 upload_too_large with the limit, cut off 400 upload_partial - and the field chunk',
	[ $missing[0], $missing[1]['code'], $missing[1]['field'] ] === [ 400, 'upload_missing', 'chunk' ] && [ $iniSize[0], $iniSize[1]['code'] ] === [ 413, 'upload_too_large' ] && $iniSize[1]['params'] === [ \Nino\Admin\Admin::megabytes( \Nino\Images::limits()['php'] ) ]
	&& [ $partial[0], $partial[1]['code'] ] === [ 400, 'upload_partial' ] );

$noFolder = [];
foreach( [ 'a folder nobody has' => 'ffffffffffff', 'something that is no id' => '../x', 'nothing' => '' ] as $kind => $folder ) {
	$answer = filesOwnerSend( $appData, [ 'folder' => $folder, 'name' => 'a.mp3', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 1 ], filesPart( $appData, 'abcde' ) );
	$noFolder[$kind] = $answer[0]. ' '. ( $answer[1]['code'] ?? '' );
}
check( 'a folder nobody has - or an id that is none - is 404 files_folder', array_values( array_unique( $noFolder ) ) === [ '404 files_folder' ] && filesParts( $appData ) === [] );

// The owner may leave the file hidden
$hiddenUpload = filesUpload( $appData, 'filesOwnerSend', 'Draft.mp3', $small, [ 'folder' => $room['id'], 'visible' => false ] );
check( 'with visible false the owner\'s upload is kept from the members: the entry is hidden', \Nino\Modules\Files::file( $appData, (string) ( $hiddenUpload[0][1]['id'] ?? '' ) )['visible'] === false );

echo "\n";


// --- The upload - a member's, through the route -----------------------------------

echo "POST /.files/upload - a member's upload, the plain post and the caps\n";

ninoWarnings();
$before = filesIndex( $appData );
$fields = [ 'name' => 'Member Take.mp3', 'size' => 100, 'upload' => '', 'index' => 0, 'count' => 1 ];
$aimed = [];

// ...the room is not the upload folder of any group yet: nobody gets in
foreach( [ 'a visitor' => '', 'a member of the group before the folder is marked' => 'ada', 'a member of another group' => 'bob' ] as $kind => $who ) {
	filesAs( $who );
	filesFresh( $appData );
	$answer = filesMemberSend( $appData, $fields + [ 'folder' => $room['id'] ], filesPart( $appData, str_repeat( 'a', 100 ) ) );
	$aimed[$kind] = $answer[0]. ' '. ( $answer[1]['code'] ?? '' );
}
check( 'a visitor, a member of the group at a folder that is not marked as the upload folder, and a member of another group are all the same 404 files_folder', array_values( array_unique( $aimed ) ) === [ '404 files_folder' ] && filesIndex( $appData ) === $before && filesParts( $appData ) === [] );

\Nino\Modules\Files\Store::markUpload( $appData, $room['id'], true );
$before = filesIndex( $appData );
$aimed = [];
foreach( [ 'a member of another group' => [ 'bob', $room['id'] ], 'their own group\'s folder that is not the upload folder' => [ 'ada', $staff['id'] ], 'a folder of another group' => [ 'ada', $lobby['id'] ], 'a folder nobody has' => [ 'ada', 'ffffffffffff' ], 'no folder' => [ 'ada', '' ] ] as $kind => [ $who, $folder ] ) {
	filesAs( $who );
	filesFresh( $appData );
	$answer = filesMemberSend( $appData, $fields + [ 'folder' => $folder ], filesPart( $appData, str_repeat( 'a', 100 ) ) );
	$aimed[$kind] = $answer[0]. ' '. ( $answer[1]['code'] ?? '' );
}
filesFresh( $appData );
filesAs( 'ada' );
$list = filesServe( $appData, '/.files/upload', 'POST', [ 'folder' => [ $room['id'] ] ] + array_map( 'strval', $fields ), true, [ 'chunk' => filesPart( $appData, str_repeat( 'a', 100 ) ) ] )['/nino/http/response'];
check( 'once the room is the upload folder: a member of another group, a folder of their own group that is not marked, a folder of another group, one nobody has and none are 404 files_folder - and a list for the folder is no warning but the same answer',
	array_values( array_unique( $aimed ) ) === [ '404 files_folder' ] && $list['statusCode'] === 404 && $list['body']['code'] === 'files_folder' && filesIndex( $appData ) === $before && ninoWarnings() === [] );

// The right member's three chunks
filesAs( 'ada' );
filesFresh( $appData );
$told = [];
$member = filesUpload( $appData, 'filesMemberSend', 'Ada Take.mp3', $mp3, [ 'folder' => $room['id'] ] );
$memberDone = $member[2][1] ?? [];
$memberEntry = \Nino\Modules\Files::file( $appData, (string) ( $memberDone['id'] ?? '' ) );
check( 'the right member\'s three chunks give the entry: the upload id first, with the index of the chunk, then the id, the name and the size', array_column( $member, 0 ) === [ 200, 200, 200 ] && preg_match( '/^[a-f0-9]{16}$/D', $member[0][1]['upload'] ) === 1 && $member[0][1] === [ 'upload' => $member[0][1]['upload'], 'index' => 0 ]
	&& $member[1][1] === [ 'upload' => $member[0][1]['upload'], 'index' => 1 ] && array_keys( $memberDone ) === [ 'id', 'name', 'size' ]
	&& $memberDone['name'] === 'Ada Take.mp3' && $memberDone['size'] === 2500000 );
check( '...by that member, visible, in the folder, with the sha256, the MIME type and the bytes of the fixture - and the callback /files/upload is told once, /files/add not at all',
	$memberEntry !== null && $memberEntry['by'] === [ 'kind' => 'member', 'id' => $ada['id'] ] && $memberEntry['visible'] === true && $memberEntry['folder'] === $room['id'] && $memberEntry['sha256'] === hash( 'sha256', $mp3 ) && $memberEntry['mime'] === 'audio/mpeg'
	&& hash_file( 'sha256', (string) \Nino\Modules\Files::path( $appData, $memberEntry['id'] ) ) === hash( 'sha256', $mp3 ) && $told === [ [ '/files/upload', $memberEntry ] ] && filesParts( $appData ) === [] );
check( '...the member sees it in the route of the download at once, and a member of another group does not', ( function() use ( $memberEntry, &$appData ): bool {
	filesAs( 'ada' );
	$mine = filesServe( $appData, '/.files/'. $memberEntry['id'] )['/nino/http/response']['statusCode'];
	filesAs( 'bob' );
	$theirs = filesServe( $appData, '/.files/'. $memberEntry['id'] )['/nino/http/response']['statusCode'];
	filesAs( 'ada' );
	return $mine === 200 && $theirs === 404;
} )() );

// The rules of the upload hold for a member as they do for the owner
filesAs( 'ada' );
filesFresh( $appData );
$memberRules = [
	'the type' => filesUpload( $appData, 'filesMemberSend', 'virus.exe', 'MZ', [ 'folder' => $room['id'] ] ),
	'the sniffed type' => filesUpload( $appData, 'filesMemberSend', 'fake.zip', 'only text', [ 'folder' => $room['id'] ] ),
	'the size' => filesUpload( $appData, 'filesMemberSend', 'Huge.mp3', str_repeat( 'a', 5 * $chunkBytes + 1 ), [ 'folder' => $room['id'] ] ),
];
check( 'the types, the sniffing and the largest size are held for a member as for the owner: files_type, files_type, files_size', array_map( static fn( array $answers ): string => $answers[0][1]['code'], $memberRules ) === [ 'the type' => 'files_type', 'the sniffed type' => 'files_type', 'the size' => 'files_size' ]
	&& filesParts( $appData ) === [] );

// A part php did not take whole is no chunk, though its size is the size the chunk has to have
$partial = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Partial.txt', 'size' => '5', 'upload' => '', 'index' => '0', 'count' => '1' ], array_replace( filesPart( $appData, 'abcde' ), [ 'error' => UPLOAD_ERR_PARTIAL ] ) );
check( 'a part with an error on it (php took it partly) is 400 files_chunk though its size is the size of the chunk - and nothing is made', $partial[0] === 400 && ( $partial[1]['code'] ?? '' ) === 'files_chunk' && filesParts( $appData ) === [] );

// The csrf token
filesFresh( $appData );
$counterBefore = \Nino\Filesystem::getFileContent( $appData, '/data/files-tries.php', [] );
$indexBefore = filesIndex( $appData );
$noToken = filesServe( $appData, '/.files/upload', 'POST', $fields + [ 'folder' => $room['id'] ], false, [ 'chunk' => filesPart( $appData, str_repeat( 'a', 100 ) ) ] )['/nino/http/response'];
$wrongToken = filesServe( $appData, '/.files/upload', 'POST', $fields + [ 'folder' => $room['id'], '_csrf' => 'not-the-token' ], false, [ 'chunk' => filesPart( $appData, str_repeat( 'a', 100 ) ) ] )['/nino/http/response'];
check( 'a request without the csrf token - or with another - is refused with the 403 and the empty body the kernel answers, counts for nothing and writes nothing', [ $noToken['statusCode'], $noToken['body'] ] === [ 403, false ] && [ $wrongToken['statusCode'], $wrongToken['body'] ] === [ 403, false ]
	&& \Nino\Filesystem::getFileContent( $appData, '/data/files-tries.php', [] ) === $counterBefore && filesIndex( $appData ) === $indexBefore && filesParts( $appData ) === [] );

// The cap: chunk requests per visitor and hour
\Nino\Features::saveSettings( $appData, 'files', [ 'attempts' => 3 ] );
filesFresh( $appData );
filesVisitor();
$answers = [];
for( $try = 1; $try <= 5; $try++ )
	$answers[] = filesMemberSend( $appData, $fields + [ 'folder' => $room['id'] ], filesPart( $appData, str_repeat( 'a', 100 ) ) );
check( 'the fourth request of a visitor in an hour - the setting attempts says three - is 429 files_limit, a visitor who is no member counted like the rest', array_map( static fn( array $answer ): int => $answer[0], $answers ) === [ 404, 404, 404, 429, 429 ] && $answers[3][1]['code'] === 'files_limit' );

\Nino\Features::saveSettings( $appData, 'files', [ 'attempts' => 300 ] );
filesFresh( $appData );
$statuses = [];
for( $try = 1; $try <= 301; $try++ )
	$statuses[] = filesMemberSend( $appData, $fields + [ 'folder' => $room['id'] ], filesPart( $appData, 'a' ) )[0];
$tries = \Nino\Filesystem::getFileContent( $appData, '/data/files-tries.php', [] );
check( 'with the default of 300 the 301st chunk request of a visitor in an hour is 429 files_limit, and the 300 before it were answered', count( array_unique( array_slice( $statuses, 0, 300 ) ) ) === 1 && $statuses[299] === 404 && $statuses[300] === 429 );
check( '...the counter has a file of its own - not the index, which a counter would have emptied - hashed: neither the address of the visitor nor a name is written in it, and the index is what it was', is_file( \Nino\Filesystem::path( $appData, '/data/files-tries.php' ) ) === true
	&& count( $tries ) === 1 && str_contains( (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/files-tries.php' ) ), '127.0.0.1' ) === false && filesIndex( $appData ) === $indexBefore && count( filesIndex( $appData )['files'] ) > 5 );
filesFresh( $appData );

// The chunk of an upload is the one its first chunk had: a script that found the web server takes less than the setting sends smaller chunks
filesAs( 'ada' );
filesFresh( $appData );
$told = [];
$small = filesUploadIn( $appData, 'filesMemberSend', 'Small Parts.mp3', $mp3, 300000, [ 'folder' => $room['id'] ] );
$smallDone = $small[8][1] ?? [];
$smallEntry = \Nino\Modules\Files::file( $appData, (string) ( $smallDone['id'] ?? '' ) );
check( 'an upload in chunks smaller than the setting is taken and assembled: 2500000 bytes in chunks of 300000 are nine requests, the last has what is left, and the entry has the size, the sha256 and the bytes of the file',
	array_column( $small, 0 ) === array_fill( 0, 9, 200 ) && $smallEntry !== null && $smallEntry['size'] === strlen( $mp3 ) && $smallEntry['sha256'] === hash( 'sha256', $mp3 ) && $smallEntry['mime'] === 'audio/mpeg'
	&& file_get_contents( (string) \Nino\Modules\Files::path( $appData, $smallEntry['id'] ) ) === $mp3 && filesParts( $appData ) === [] );

filesWorkbench( 'admin' );
$ownerSmall = filesUploadIn( $appData, 'filesOwnerSend', 'Owner Small.mp3', $mp3, 700000, [ 'folder' => $room['id'] ] );
$ownerSmallEntry = \Nino\Modules\Files::file( $appData, (string) ( $ownerSmall[3][1]['id'] ?? '' ) );
check( '...the owner\'s upload through the panel is the same protocol: chunks of 700000 are four requests and the file is whole', array_column( $ownerSmall, 0 ) === [ 200, 200, 200, 200 ] && $ownerSmallEntry !== null && $ownerSmallEntry['sha256'] === hash( 'sha256', $mp3 ) && filesParts( $appData ) === [] );

filesAs( 'ada' );
filesFresh( $appData );
$tiny = filesUploadIn( $appData, 'filesMemberSend', 'Tiny.txt', 'abc', 1, [ 'folder' => $room['id'] ] );
$tinyEntry = \Nino\Modules\Files::file( $appData, (string) ( $tiny[2][1]['id'] ?? '' ) );
check( '...a chunk can be one byte long: three requests make a file of three bytes', array_column( $tiny, 0 ) === [ 200, 200, 200 ] && $tinyEntry !== null && $tinyEntry['size'] === 3 && $tinyEntry['sha256'] === hash( 'sha256', 'abc' ) );

// The first chunk sets the size: every later one but the last has to be as long
$first = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => 9 ], filesPart( $appData, substr( $mp3, 0, 300000 ) ) );
$partId = (string) ( $first[1]['upload'] ?? '' );
$partFile = \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/'. $partId. '.part';
$other = [];

foreach( [ 'shorter' => 299999, 'longer' => 300001, 'the setting' => 1048576 ] as $kind => $length )
	$other[$kind] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 1, 'count' => 9 ], filesPart( $appData, substr( $mp3, 300000, $length ) ) );

$other['count up'] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 1, 'count' => 10 ], filesPart( $appData, substr( $mp3, 300000, 300000 ) ) );
$other['count down'] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 1, 'count' => 8 ], filesPart( $appData, substr( $mp3, 300000, 300000 ) ) );
$other['last too long'] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 8, 'count' => 9 ], filesPart( $appData, substr( $mp3, 2400000, 300000 ) ) );
check( 'a later chunk of another length than the first (shorter, longer, the setting\'s), a count that does not fit the chunk (one more, one less) and a last chunk that is not what is left are all 400 files_chunk - and the part stays as the first chunk made it',
	array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), $other ) ) ) === [ '400 files_chunk' ] && count( $other ) === 6 && filesSize( $partFile ) === 300000 );

check( '...the length of the first chunk is kept beside the part for as long as the upload is under way', filesKept( $appData ) === [ $partId. '.chunk' ] && file_get_contents( dirname( $partFile ). '/'. $partId. '.chunk' ) === '300000' );

// The chunk of the upload is the first chunk's, not what the part makes of it: a skipped index with a new count is refused
$skip = [
	'an index skipped, with the count of another chunk' => [ 'index' => 2, 'count' => 17, 'bytes' => substr( $mp3, 300000, 150000 ) ],
	'the next index with that count' => [ 'index' => 1, 'count' => 17, 'bytes' => substr( $mp3, 300000, 150000 ) ],
	'an index skipped, as long as the first' => [ 'index' => 2, 'count' => 9, 'bytes' => substr( $mp3, 600000, 300000 ) ],
];
$skipped = [];

foreach( $skip as $kind => $each )
	$skipped[$kind] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => $each['index'], 'count' => $each['count'] ], filesPart( $appData, $each['bytes'] ) );

check( 'after a first chunk of 300000 bytes an index that is skipped is 400 files_chunk whatever the count and the length it brings (150000 and 17 chunks included) - the part stays 300000 bytes',
	array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), $skipped ) ) ) === [ '400 files_chunk' ] && filesSize( $partFile ) === 300000 && filesKept( $appData ) === [ $partId. '.chunk' ] );

// Each refusal says what is wrong
$tail = static fn( int $index, int $count, string $bytes, string $upload = '' ): array => filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $upload, 'index' => $index, 'count' => $count ], filesPart( $appData, $bytes ) );
$said = [
	'over the setting' => $tail( 0, 3, substr( $mp3, 0, 1048577 ) ),
	'another size' => $tail( 1, 9, substr( $mp3, 300000, 299999 ), $partId ),
	'out of order' => $tail( 2, 9, substr( $mp3, 600000, 300000 ), $partId ),
	'a count that does not fit' => $tail( 1, 10, substr( $mp3, 300000, 300000 ), $partId ),
	'no such upload' => $tail( 1, 9, substr( $mp3, 300000, 300000 ), 'aaaaaaaaaaaaaaaa' ),
	'a chunk that did not arrive' => filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 1, 'count' => 9 ], array_replace( filesPart( $appData, substr( $mp3, 300000, 300000 ) ), [ 'error' => UPLOAD_ERR_PARTIAL ] ) ),
];
$errors = array_map( static fn( array $answer ): string => ( $answer[0] === 400 && ( $answer[1]['code'] ?? '' ) === 'files_chunk' ) ? (string) ( $answer[1]['error'] ?? '' ) : '', $said );
check( '...and every refusal has its own sentence, the code staying files_chunk: a first chunk over the setting, a chunk of another size, one out of order, a count that does not fit, an upload that is not there and a chunk that did not arrive whole are six different words',
	count( array_unique( $errors ) ) === 6 && in_array( '', $errors, true ) === false );
$goes = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => $partId, 'index' => 1, 'count' => 9 ], filesPart( $appData, substr( $mp3, 300000, 300000 ) ) );
check( '...and the chunk that fits is taken after all of them', $goes[0] === 200 && filesSize( $partFile ) === 600000 );
@unlink( $partFile );
@unlink( dirname( $partFile ). '/'. $partId. '.chunk' );

// The last chunk longer than what is left has its own word as well, and the part stays
$lastA = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Last.txt', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, 'ab' ) );
filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Last.txt', 'size' => 5, 'upload' => $lastA[1]['upload'] ?? '', 'index' => 1, 'count' => 3 ], filesPart( $appData, 'cd' ) );
$lastLong = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Last.txt', 'size' => 5, 'upload' => $lastA[1]['upload'] ?? '', 'index' => 2, 'count' => 3 ], filesPart( $appData, 'efg' ) );
check( '...the last chunk that is longer than what is left has a word of its own, other than the six', $lastLong[0] === 400 && ( $lastLong[1]['code'] ?? '' ) === 'files_chunk' && in_array( (string) ( $lastLong[1]['error'] ?? '' ), $errors, true ) === false && $lastLong[1]['error'] !== '' );
filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Last.txt', 'size' => 5, 'upload' => $lastA[1]['upload'] ?? '', 'index' => 2, 'count' => 3 ], filesPart( $appData, 'e' ) );
check( '...and nothing is kept once an upload is whole: no part and no kept length', filesParts( $appData ) === [] && filesKept( $appData ) === [] );

// A part without the length of its first chunk is no upload of this protocol; a length without a part is swept
$bare = dirname( $partFile ). '/bbbbbbbbbbbbbbbb.part';
file_put_contents( $bare, str_repeat( 'x', 300000 ) );
file_put_contents( dirname( $partFile ). '/cccccccccccccccc.chunk', '300000' );
$noKept = $tail( 1, 9, substr( $mp3, 300000, 300000 ), 'bbbbbbbbbbbbbbbb' );
unlink( $bare );
$tail( 0, 9, substr( $mp3, 0, 300000 ) );
check( 'a part with no length kept beside it is refused as an upload that is not there, and a length without its part is swept when the next upload starts', $noKept[0] === 400 && $noKept[1]['code'] === 'files_chunk' && is_file( dirname( $partFile ). '/cccccccccccccccc.chunk' ) === false && count( filesKept( $appData ) ) === 1 );
$stalePair = dirname( $partFile ). '/dddddddddddddddd';
file_put_contents( $stalePair. '.part', 'x' );
file_put_contents( $stalePair. '.chunk', '1' );
touch( $stalePair. '.part', time() - 90000 );
$tail( 0, 9, substr( $mp3, 0, 300000 ) );
check( '...a stale part goes with the length kept beside it', is_file( $stalePair. '.part' ) === false && is_file( $stalePair. '.chunk' ) === false );

foreach( glob( dirname( $partFile ). '/*' ) ?: [] as $left )
	@unlink( $left );

// A first chunk that does not fit the file or the setting
$firsts = [
	'a count that does not fit' => [ 'count' => 8, 'length' => 300000 ],
	'a count that is one too many' => [ 'count' => 10, 'length' => 300000 ],
	'longer than the setting' => [ 'count' => 3, 'length' => 1048577 ],
	'one chunk that is not the file' => [ 'count' => 1, 'length' => 300000 ],
];
$refusedFirst = [];

foreach( $firsts as $kind => $each )
	$refusedFirst[$kind] = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Parts.mp3', 'size' => strlen( $mp3 ), 'upload' => '', 'index' => 0, 'count' => $each['count'] ], filesPart( $appData, substr( $mp3, 0, $each['length'] ) ) );

// A last chunk longer than what is left is refused at once, and the part stays so that the right one can follow
$tail = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Tail.txt', 'size' => 5, 'upload' => '', 'index' => 0, 'count' => 3 ], filesPart( $appData, 'ab' ) );
$tailPart = \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/'. ( $tail[1]['upload'] ?? 'x' ). '.part';
$tailMid = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Tail.txt', 'size' => 5, 'upload' => $tail[1]['upload'] ?? '', 'index' => 1, 'count' => 3 ], filesPart( $appData, 'cd' ) );
$tailLong = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Tail.txt', 'size' => 5, 'upload' => $tail[1]['upload'] ?? '', 'index' => 2, 'count' => 3 ], filesPart( $appData, 'efg' ) );
$tailState = [ is_file( $tailPart ), filesSize( $tailPart ) ];
$tailEnd = filesMemberSend( $appData, [ 'folder' => $room['id'], 'name' => 'Tail.txt', 'size' => 5, 'upload' => $tail[1]['upload'] ?? '', 'index' => 2, 'count' => 3 ], filesPart( $appData, 'e' ) );
check( '...a last chunk longer than what is left is 400 files_chunk before it is written - the part stays as long as it was and the right last chunk is taken after it', [ $tail[0], $tailMid[0], $tailLong[0], $tailLong[1]['code'] ?? '' ] === [ 200, 200, 400, 'files_chunk' ] && $tailState === [ true, 4 ] && $tailEnd[0] === 200 && ( $tailEnd[1]['size'] ?? 0 ) === 5 );

check( 'a first chunk is checked against the file it starts: a count that does not fit its length, a chunk longer than the setting and a single chunk that is not the whole file are 400 files_chunk, and no part is made',
	array_values( array_unique( array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), $refusedFirst ) ) ) === [ '400 files_chunk' ] && filesParts( $appData ) === [] );
filesFresh( $appData );

// The one-step post without script
filesAs( 'ada' );
filesFresh( $appData );
$plain = static function( string $name, string $bytes, array $post = [], array $part = [] ) use ( &$appData, $room ): array {
	$part = array_replace( filesPart( $appData, $bytes ), [ 'name' => $name ], $part );
	$request = filesServe( $appData, '/.files/upload', 'POST', $post + [ 'folder' => $room['id'], 'count' => '1', 'return' => '/club/files?x=1#top' ], true, [ 'chunk' => $part ] );
	return [ $request['/nino/http/response']['statusCode'], (string) ( $request['/nino/http/response']['header']['Location'] ?? '' ), $request['/nino/http/response']['body'] ?? '' ];
};
$told = [];
$sent = $plain( 'plain.txt', "Plain notes\n" );
$plainEntry = array_values( array_filter( \Nino\Modules\Files::files( $appData, $room['id'] ), static fn( array $file ): bool => $file['name'] === 'plain.txt' ) )[0] ?? [];
check( 'the plain post - one chunk that is the whole file, count 1, no name and no size of its own - is taken with the name and the size the file carries and answers a redirect (303) to the page it was posted from with ?files=sent',
	$sent === [ 303, '/club/files?files=sent', '' ] && ( $plainEntry['by'] ?? null ) === [ 'kind' => 'member', 'id' => $ada['id'] ] && ( $plainEntry['size'] ?? 0 ) === 12 && ( $plainEntry['mime'] ?? '' ) === 'text/plain' && count( $told ) === 1 && $told[0][0] === '/files/upload' );

$refusals = [
	'files_type' => $plain( 'virus.exe', 'MZ' ), 'files_folder' => $plain( 'a.txt', 'abc', [ 'folder' => $lobby['id'] ] ), 'files_size' => $plain( 'big.txt', str_repeat( 'a', $chunkBytes + 1 ) ),
];
// A file php refused for its size comes with an error on its part and no size and no bytes
$phpRefused = [
	'upload_max_filesize' => $plain( 'huge.txt', '', [], [ 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '' ] ),
	'the size field of the form' => $plain( 'huge.txt', '', [], [ 'error' => UPLOAD_ERR_FORM_SIZE, 'size' => 0, 'tmp_name' => '' ] ),
	'a part that came partly' => $plain( 'cut.txt', '', [], [ 'error' => UPLOAD_ERR_PARTIAL, 'size' => 0, 'tmp_name' => '' ] ),
];
check( 'a plain post that php refused for its size - upload_max_filesize or the size field of the form - is a file that is too large, ?files=files_size; a part that came partly is still a chunk that did not arrive, ?files=files_chunk',
	array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1], $phpRefused ) === [ 'upload_max_filesize' => '303 /club/files?files=files_size', 'the size field of the form' => '303 /club/files?files=files_size', 'a part that came partly' => '303 /club/files?files=files_chunk' ] );
check( 'a plain post that is refused is a redirect (303) to the same page with ?files=<code> - the type, a folder that is not the group\'s, a file longer than one post takes', $refusals['files_type'][1] === '/club/files?files=files_type' && $refusals['files_folder'][1] === '/club/files?files=files_folder'
	&& $refusals['files_size'][1] === '/club/files?files=files_size' && array_values( array_unique( array_column( $refusals, 0 ) ) ) === [ 303 ] );

$returns = [];
foreach( [ '//evil.example/x', '/\\evil.example', 'https://evil.example', '/.members/login', "/a b", '', 'relative/path', '/a/b:c', '/javascript:alert(1)' ] as $return )
	$returns[$return] = $plain( 'r.txt', 'abc', [ 'return' => $return ] )[1];
check( 'the page the plain post goes back to is a path of this site and no endpoint of a module - the front page for anything else', $returns === [ '//evil.example/x' => '/?files=sent', '/\\evil.example' => '/?files=sent', 'https://evil.example' => '/?files=sent', '/.members/login' => '/?files=sent',
	'/a b' => '/?files=sent', '' => '/?files=sent', 'relative/path' => '/?files=sent', '/a/b:c' => '/a/b:c?files=sent', '/javascript:alert(1)' => '/?files=sent' ] );

$noFile = filesServe( $appData, '/.files/upload', 'POST', [ 'folder' => $room['id'], 'count' => '1', 'return' => '/club/files' ] )['/nino/http/response'];
check( 'a plain post with no file in it is a refusal like the others, not an error', $noFile['statusCode'] === 303 && str_starts_with( $noFile['header']['Location'], '/club/files?files=files_' ) === true && ninoWarnings() === [] );
filesVisitor();

echo "\n";

// --- The components --------------------------------------------------------------

echo "The components - registry, and what a visitor and a member are shown\n";

$registry = \Nino\Modules\Components::components( $appData );
$schemas = array_intersect_key( $registry, array_flip( [ 'files-browser', 'files-upload' ] ) );

check( 'the registry knows the two as this feature\'s own, each with what it draws, where it may stand and a picture of its own - neither reads a source or loops',
	array_keys( $schemas ) === [ 'files-browser', 'files-upload' ] && array_values( array_unique( array_column( $schemas, 'by' ) ) ) === [ 'files' ] && array_values( array_column( $schemas, 'draws' ) ) === [ 'div .files-browser', 'form .files-upload' ]
	&& array_values( array_column( $schemas, 'preview' ) ) === [ 'block', 'block' ] && array_values( array_map( 'json_encode', array_column( $schemas, 'in' ) ) ) === [ '["section","card"]', '["section","card"]' ]
	&& array_filter( $schemas, static fn( array $schema ): bool => $schema['source'] !== 'none' || $schema['loop'] !== false || count( $schema['icon'] ) < 1 ) === [] );
check( '...one attribute each, a flag that is on by default - the link that saves, several files at once', array_keys( $schemas['files-browser']['attributes'] ) === [ 'downloads' ] && array_keys( $schemas['files-upload']['attributes'] ) === [ 'multiple' ]
	&& \Nino\Modules\Components::defaults( $appData, 'files-browser' ) === [ 'downloads' => '1', 'class' => '' ] && \Nino\Modules\Components::defaults( $appData, 'files-upload' ) === [ 'multiple' => '1', 'class' => '' ] );
check( '...and the Builder\'s two components and the shortcodes are one renderer: the class has the methods the manifest derives their names to, and registers no shortcode of its own beside them',
	is_callable( [ '\\Nino\\Modules\\Files', 'componentFilesBrowser' ] ) === true && is_callable( [ '\\Nino\\Modules\\Files', 'componentFilesUpload' ] ) === true && count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/files-upload' ) ) === 1 );

// The words of the sizes are the unit's: 1.5 KB in the language of the page
$words = [ 'b' => 'B', 'kb' => 'KB', 'mb' => 'MB', 'gb' => 'GB', 'separator' => ',' ];
check( 'a size is told in the largest unit that leaves a number of one digit or more - one decimal under ten, none above - with the unit and the separator of the language',
	\Nino\Modules\Files\Store::size( 0, $words ) === '0 B' && \Nino\Modules\Files\Store::size( 1023, $words ) === '1023 B' && \Nino\Modules\Files\Store::size( 1536, $words ) === '1,5 KB' && \Nino\Modules\Files\Store::size( 1024, $words ) === '1 KB'
	&& \Nino\Modules\Files\Store::size( 3000, $words ) === '2,9 KB' && \Nino\Modules\Files\Store::size( 10 * 1024, $words ) === '10 KB' && \Nino\Modules\Files\Store::size( 500 * 1048576, [ 'mb' => 'MB', 'separator' => '.' ] ) === '500 MB'
	&& \Nino\Modules\Files\Store::size( 5 * 1073741824 + 1, [ 'gb' => 'GB', 'separator' => '.' ] ) === '5 GB' && \Nino\Modules\Files\Store::size( 2500000, [ 'mb' => 'MB', 'separator' => '.' ] ) === '2.4 MB'
	&& \Nino\Modules\Files\Store::size( 12900000, [ 'mb' => 'MB', 'separator' => '.' ] ) === '12 MB' );

check( 'the size of a chunk is the setting in megabytes, held under what php takes in a request less the room for the fields - and a limit of nothing is no limit',
	\Nino\Modules\Files\Store::clamp( 4, 0 ) === 4194304 && \Nino\Modules\Files\Store::clamp( 32, 2097152 ) === 2097152 - 65536 && \Nino\Modules\Files\Store::clamp( 1, 10485760 ) === 1048576 && \Nino\Modules\Files\Store::clamp( 4, 1000 ) === 65536 && \Nino\Modules\Files\Store::clamp( 0, 0 ) === 1048576 );

$group = static fn( string $group ): string => $group;
$staffFolders = array_column( \Nino\Modules\Files::folders( $appData, 'staff' ), 'id' );

// ...for a visitor, nothing at all
filesVisitor();
ninoVisit( $appData, '/club/files' );
check( 'a visitor is shown nothing of either - not an empty frame, not a form', \Nino\Html::renderHtml( $appData, '[files-browser]' ) === '' && \Nino\Html::renderHtml( $appData, '[files-upload]' ) === '' && \Nino\Html::renderHtml( $appData, '[files-browser class="x"][files-upload multiple="0"]' ) === '' );

// ...the browser, for a member of the group
filesAs( 'ada' );
ninoVisit( $appData, '/club/files' );
$html = \Nino\Html::renderHtml( $appData, '[files-browser class="quiet"]' );
$browsers = filesFind( $html, '//div[contains(concat(" ", normalize-space(@class), " "), " files-browser ")]' );
$sections = filesFind( $html, '//section[contains(@class, "files-folder")]' );
$rows = filesFind( $html, '//li[contains(@class, "files-row")]' );
$ids = array_map( static fn( DOMElement $row ): string => $row->getAttribute( 'data-files-id' ), $rows );
check( 'a member is shown one browser with the class of the call and one section for each folder of their group - in the order of the names, and no folder of another group',
	count( $browsers ) === 1 && filesClasses( $browsers[0] ) === [ 'files-browser', 'quiet' ] && array_map( static fn( DOMElement $section ): string => $section->getAttribute( 'data-files-folder' ), $sections ) === $staffFolders
	&& in_array( $lobby['id'], $staffFolders, true ) === false && str_contains( $html, $lobby['id'] ) === false && str_contains( $html, '[[' ) === false );

$seen = [];

foreach( $staffFolders as $folderId )
	foreach( \Nino\Modules\Files::files( $appData, $folderId ) as $file )
		if( \Nino\Modules\Files::allowed( $appData, $file ) === true )
			$seen[] = $file['id'];

check( '...in each the files that are visible to the member - the ones the rule of the download allows, no hidden file and no file of the owner that is not shown - each row with the id and the MIME type of its file',
	$ids !== [] && in_array( $secret['id'], $ids, true ) === false && in_array( $cydsOwn['id'], $ids, true ) === false && in_array( $track['id'], $ids, true ) === true && in_array( $pack['id'], $ids, true ) === true
	&& array_diff( $seen, $ids ) === [] && array_diff( $ids, $seen ) === [] && count( $ids ) === count( $seen )
	&& array_filter( $rows, static fn( DOMElement $row ): bool => preg_match( '/^[a-f0-9]{12}$/D', $row->getAttribute( 'data-files-id' ) ) !== 1 || $row->getAttribute( 'data-files-mime' ) === '' ) === [] );

$trackRow = array_values( array_filter( $rows, static fn( DOMElement $row ): bool => $row->getAttribute( 'data-files-id' ) === $track['id'] ) )[0];
$packRow = array_values( array_filter( $rows, static fn( DOMElement $row ): bool => $row->getAttribute( 'data-files-id' ) === $pack['id'] ) )[0];
$cell = static function( DOMElement $row, string $class ): ?DOMElement {
	foreach( $row->getElementsByTagName( '*' ) as $child )
		if( in_array( $class, filesClasses( $child ), true ) === true )
			return $child;
	return null;
};
check( '...a row has the name as a link to the file, its size in the unit of the page, the day it was put there as a time, and the link that saves it - the MIME type on the row',
	$trackRow->getAttribute( 'data-files-mime' ) === 'audio/mpeg' && $packRow->getAttribute( 'data-files-mime' ) === 'application/zip' && $cell( $trackRow, 'files-row__name' )->getAttribute( 'href' ) === '/.files/'. $track['id']
	&& $cell( $trackRow, 'files-row__name' )->textContent === 'Demo "One".mp3' && $cell( $trackRow, 'files-row__size' )->textContent === '2,9 KB' && $cell( $trackRow, 'files-row__date' )->tagName === 'time'
	&& $cell( $trackRow, 'files-row__date' )->getAttribute( 'datetime' ) === \Nino\Modules\Files::file( $appData, $track['id'] )['created'] && $cell( $trackRow, 'files-row__date' )->textContent === substr( \Nino\Modules\Files::file( $appData, $track['id'] )['created'], 0, 10 )
	&& $cell( $trackRow, 'files-row__download' )->getAttribute( 'href' ) === '/.files/'. $track['id']. '?download=1' );
$counts = array_map( static fn( DOMElement $section ): string => trim( $cell( $section, 'files-folder__count' )->textContent ), $sections );
check( '...each folder says its name and the number of files the member sees in it', array_map( static fn( DOMElement $section ): string => trim( $cell( $section, 'files-folder__name' )->textContent ), $sections ) === array_column( \Nino\Modules\Files::folders( $appData, 'staff' ), 'name' )
	&& $counts === array_map( static fn( string $folder ): string => (string) count( array_filter( \Nino\Modules\Files::files( $GLOBALS['appData'], $folder ), static fn( array $file ): bool => \Nino\Modules\Files::allowed( $GLOBALS['appData'], $file ) ) ), $staffFolders ) );

$plainBrowser = \Nino\Html::renderHtml( $appData, '[files-browser downloads="0"]' );
check( 'without the attribute downloads there is no link that saves, the name stays a link', filesFind( $plainBrowser, '//a[contains(@class, "files-row__download")]' ) === [] && count( filesFind( $plainBrowser, '//a[contains(@class, "files-row__name")]' ) ) === count( $rows ) );

// A folder without a visible file, a member without a group, a group without a folder
$emptyFolder = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', 'Empty one' );
$emptyHtml = \Nino\Html::renderHtml( $appData, '[files-browser]' );
$emptySection = array_values( array_filter( filesFind( $emptyHtml, '//section[contains(@class, "files-folder")]' ), static fn( DOMElement $section ): bool => $section->getAttribute( 'data-files-folder' ) === $emptyFolder['id'] ) )[0];
\Nino\Modules\Files\Store::deleteFolder( $appData, $emptyFolder['id'] );
check( 'a folder without a file the member may see says so, and counts none', trim( $cell( $emptySection, 'files-folder__count' )->textContent ) === '0' && $cell( $emptySection, 'files-folder__empty' ) !== null );

\Nino\Modules\Members::change( $appData, $ada['id'], [ 'group' => '' ] );
$loner = \Nino\Html::renderHtml( $appData, '[files-browser][files-upload]' );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'group' => 'tourists' ] );
$nowhere = \Nino\Html::renderHtml( $appData, '[files-browser][files-upload]' );
\Nino\Modules\Members::change( $appData, $ada['id'], [ 'group' => 'staff' ] );
check( 'a member with no group, and one whose group has no folder, are shown the browser with a line that says there is nothing - and no form', count( filesFind( $loner, '//p[contains(@class, "files-browser__none")]' ) ) === 1
	&& count( filesFind( $nowhere, '//p[contains(@class, "files-browser__none")]' ) ) === 1 && filesFind( $loner. $nowhere, '//form' ) === [] && filesFind( $loner. $nowhere, '//section' ) === [] );

filesAs( 'bob' );
$bobHtml = \Nino\Html::renderHtml( $appData, '[files-browser]' );
check( 'a member of another group is shown the folders of theirs and nothing of the staff\'s', array_map( static fn( DOMElement $section ): string => $section->getAttribute( 'data-files-folder' ), filesFind( $bobHtml, '//section' ) ) === [ $lobby['id'] ]
	&& str_contains( $bobHtml, $track['id'] ) === false && str_contains( $bobHtml, $room['id'] ) === false );

// Names are text wherever they are written
filesAs( 'ada' );
$hostile = [];
foreach( [ '<img src=x onerror=alert(1)>', '[[/project/company/general/name]]', '[files-upload]' ] as $name ) {
	$folderHostile = \Nino\Modules\Files\Store::addFolder( $appData, 'staff', $name );
	filesMake( $appData, $folderHostile['id'], $name. '.mp3', 'x' );
	$markup = \Nino\Html::renderHtml( $appData, '[files-browser]' );
	$texts = array_map( static fn( DOMElement $each ): string => $each->textContent, filesFind( $markup, '//*[contains(@class, "files-folder__name")]' ) );
	$hostile[$name] = filesFind( $markup, '//img' ) === [] && filesFind( $markup, '//@onerror' ) === [] && str_contains( $markup, '[[/project' ) === false && filesFind( $markup, '//form' ) === [] && in_array( $name, $texts, true ) === true
		&& in_array( \Nino\Modules\Files\Store::cleanName( $name. '.mp3' )[0], array_map( static fn( DOMElement $each ): string => $each->textContent, filesFind( $markup, '//a[contains(@class, "files-row__name")]' ) ), true ) === true;
	\Nino\Modules\Files\Store::deleteFolder( $appData, $folderHostile['id'] );
}
check( 'the name of a folder and of a file is text: markup in it is not an element, and a key or a shortcode in it is neither filled in nor run', array_filter( $hostile, static fn( bool $safe ): bool => $safe === false ) === [] && count( $hostile ) === 3 );

echo "\n";

echo "The component [files-upload] - the form, and the states\n";

ninoVisit( $appData, '/join?files=sent&x=1' );
$form = \Nino\Html::renderHtml( $appData, '[files-upload]' );
$forms = filesFind( $form, '//form' );
$fields = [];
foreach( filesFind( $form, '//form//input' ) as $field )
	$fields[ $field->getAttribute( 'name' ) ] = $field;
check( 'for a member whose group has an upload folder [files-upload] is one form that posts to /.files/upload as multipart, with the hidden folder, count 1 and the page it is on, the csrf field of the session, a file input and a button',
	count( $forms ) === 1 && strtolower( $forms[0]->getAttribute( 'method' ) ) === 'post' && $forms[0]->getAttribute( 'action' ) === '/.files/upload' && strtolower( $forms[0]->getAttribute( 'enctype' ) ) === 'multipart/form-data'
	&& array_keys( $fields ) === [ 'folder', 'count', 'return', '_csrf', 'chunk' ] && $fields['folder']->getAttribute( 'value' ) === $room['id'] && $fields['count']->getAttribute( 'value' ) === '1' && $fields['return']->getAttribute( 'value' ) === '/join'
	&& $fields['_csrf']->getAttribute( 'value' ) === \Nino\Csrf::getToken( $appData ) && $fields['chunk']->getAttribute( 'type' ) === 'file' && $fields['chunk']->hasAttribute( 'required' ) === true
	&& count( filesFind( $form, '//form//button[@type="submit"]' ) ) === 1 );
check( '...it is no .nino-form (the kernel\'s script would post it by XHR), its label belongs to its file input, and the list the script fills is there and hidden', in_array( 'nino-form', filesClasses( $forms[0] ), true ) === false && in_array( 'files-upload', filesClasses( $forms[0] ), true ) === true
	&& filesFind( $form, '//label' )[0]->getAttribute( 'for' ) === $fields['chunk']->getAttribute( 'id' ) && $fields['chunk']->getAttribute( 'id' ) !== '' && count( filesFind( $form, '//ul[contains(@class, "files-upload__list")][@hidden]' ) ) === 1 );
check( '...it carries the size of a chunk - the setting - and of the largest file in bytes for the script, and it asks for several files by a data attribute and not by the attribute multiple, which php would keep one part of',
	$forms[0]->getAttribute( 'data-files-chunk' ) === (string) $chunkBytes && $forms[0]->getAttribute( 'data-files-max' ) === (string) ( 5 * $chunkBytes ) && $forms[0]->getAttribute( 'data-files-multiple' ) === '1' && $fields['chunk']->hasAttribute( 'multiple' ) === false );

$hints = filesFind( $form, '//form/p[contains(@class, "files-upload__hint")]' );
check( '...it tells the largest file and the types that are taken - two lines of hint, the second naming each type of the setting', count( $hints ) === 2 && array_diff( \Nino\Modules\Files\Store::types( $appData ), preg_split( '/[\s,:]+/', $hints[1]->textContent ) ?: [] ) === []
	&& \Nino\Modules\Files\Store::types( $appData ) !== [] && trim( $hints[0]->textContent ) !== '' );

\Nino\Features::saveSettings( $appData, 'files', [ 'chunk' => 32 ] );
$clamped = filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form' )[0]->getAttribute( 'data-files-chunk' );
\Nino\Features::saveSettings( $appData, 'files', [ 'chunk' => 1 ] );
check( '...and a setting that php would not take in one request is held under it, which is the number the script cuts by', $clamped === (string) \Nino\Modules\Files\Store::clamp( 32, \Nino\Images::limits()['php'] ) && (int) $clamped <= \Nino\Images::limits()['php'] );

ninoVisit( $appData, '/join' );
$twice = \Nino\Html::renderHtml( $appData, '[files-upload multiple="0" class="wide"][files-upload]' );
$both = filesFind( $twice, '//form' );
check( 'multiple off, a class of the call - and two forms on a page do not share an id', count( $both ) === 2 && $both[0]->getAttribute( 'data-files-multiple' ) === '0' && $both[1]->getAttribute( 'data-files-multiple' ) === '1' && in_array( 'wide', filesClasses( $both[0] ), true ) === true
	&& in_array( 'wide', filesClasses( $both[1] ), true ) === false && count( array_unique( array_map( static fn( DOMElement $label ): string => $label->getAttribute( 'for' ), filesFind( $twice, '//label' ) ) ) ) === 2
	&& count( filesFind( $twice, '//input[@id="'. filesFind( $twice, '//label' )[0]->getAttribute( 'for' ). '"]' ) ) === 1 );
ninoVisit( $appData, '/join"onfocus="x' );
$hostile = filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form//input[@name="return"]' );
ninoVisit( $appData, '/join' );
check( 'the page the form stands on is text in an attribute: whatever the address holds, it does not end the attribute or add one', count( $hostile ) === 1 && str_contains( $hostile[0]->getAttribute( 'value' ), '"' ) === false && str_starts_with( $hostile[0]->getAttribute( 'value' ), '/join' ) === true
	&& $hostile[0]->hasAttribute( 'onfocus' ) === false );
check( 'a hand-written call with an attribute nobody declared still renders', count( filesFind( \Nino\Html::renderHtml( $appData, '[files-upload nonsense="1"]' ), '//form' ) ) === 1 );

// The states: the query says what the form says, of the list and of nothing else
$states = [];
$english = [];
$language = \Nino\Locales::getCurrentLocale( $appData );

foreach( [ 'sent', 'files_folder', 'files_type', 'files_size', 'files_quota', 'files_chunk', 'files_limit', 'files_write' ] as $state ) {
	ninoVisit( $appData, '/join?files='. $state );
	$states[$state] = filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form/p[contains(@class, "files-upload__state")]' );
	\Nino\Locales::useLocale( $appData, 'en_US' );
	$english[$state] = filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form/p[contains(@class, "files-upload__state")]' );
	\Nino\Locales::useLocale( $appData, $language );
}

check( 'the eight states - sent, and each code of a refusal - have a paragraph of their own that is not empty and holds no key left unresolved, in the language of the page',
	array_filter( $states, static fn( array $found ): bool => count( $found ) !== 1 || trim( $found[0]->textContent ) === '' || str_contains( $found[0]->textContent, '[[' ) || $found[0]->getAttribute( 'role' ) !== 'status' ) === [] && count( array_unique( array_map( static fn( array $found ): string => trim( $found[0]->textContent ), $states ) ) ) === 8
	&& $language !== 'en_US' && array_filter( $states, static fn( array $found, string $state ): bool => count( $english[$state] ) !== 1 || trim( $english[$state][0]->textContent ) === trim( $found[0]->textContent ), ARRAY_FILTER_USE_BOTH ) === [] );

$none = [];
foreach( [ '', '?files=', '?files=SENT', '?files=other', '?files[]=sent', '?files=sent%20', '?files=<b>x</b>', '?files=type', '?files=files_' ] as $query ) {
	ninoVisit( $appData, '/join'. $query );
	$none[$query] = count( filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form/p[contains(@class, "files-upload__state")]' ) );
}
check( 'anything else in the query - an array, a made-up word, a code without its prefix - is no state: there is no paragraph', array_filter( $none ) === [] && count( $none ) === 9 );

// Nothing where there is nowhere to upload to
filesAs( 'bob' );
ninoVisit( $appData, '/join' );
$bobForm = \Nino\Html::renderHtml( $appData, '[files-upload]' );
\Nino\Modules\Files\Store::markUpload( $appData, $room['id'], false );
filesAs( 'ada' );
$unmarked = \Nino\Html::renderHtml( $appData, '[files-upload]' );
\Nino\Modules\Files\Store::markUpload( $appData, $room['id'], true );
check( 'a member whose group has no upload folder is shown no form - one of another group does not get the form of the staff\'s, and a folder that loses its mark takes the form away', $bobForm === '' && $unmarked === '' && count( filesFind( \Nino\Html::renderHtml( $appData, '[files-upload]' ), '//form' ) ) === 1 );
filesVisitor();

echo "\n";

// --- The panel -------------------------------------------------------------------

echo "Modules\\Files\\Admin - the folders and the files, and what the panel says\n";

filesWorkbench( 'admin' );
$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the panel is in the registry while the feature is active, in the rail\'s group of the features, with a permission of its own and a script and a stylesheet that are there',
	isset( $panels['files'] ) === true && $panels['files']['group'] === 'features' && $panels['files']['perm'] === '/_admin/files/manage'
	&& array_filter( \Nino\Modules\Files\Admin::assets(), static fn( string $asset ): bool => is_file( \Nino\Filesystem::path( $GLOBALS['appData'], $asset ) ) === false ) === [] && count( \Nino\Modules\Files\Admin::assets() ) === 2
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Files\Admin::text(). '/en_US.php' ) ) === true && is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Files\Admin::text(). '/de_DE.php' ) ) === true
	&& is_file( \Nino\Filesystem::path( $appData, \Nino\Modules\Files\Admin::template(). '.tpl' ) ) === true );
check( '...the template has the one element the script mounts into', preg_match( '/id="files-app"/', (string) file_get_contents( $dir. '/templates/panel.tpl' ) ) === 1 && preg_match( "/mount\\s*:\\s*'files-app'/", (string) file_get_contents( $dir. '/assets/admin.js' ) ) === 1 );

// The list: the groups of the members and of the folders, the folders of one group with their counts, its files, the limits
$leftBehind = \Nino\Modules\Files\Store::addFolder( $appData, 'tourists', 'Left behind' );
$firstGroup = \Nino\Modules\Files\Store::addFolder( $appData, 'aardvark', 'First of the alphabet' );
$list = ninoCall( $appData, $panel, [ 'group' => 'staff' ], 'files/list' );
$listed = $list[1];
check( 'the list is the groups (those the members have and those that only a folder has), the group asked for, its folders with the number of their files, their files with who put them there, the types and the limits',
	$list[0] === 200 && $listed['groups'] === [ 'aardvark', 'guests', 'staff', 'tourists' ] && $listed['group'] === 'staff' && array_column( $listed['folders'], 'id' ) === array_column( \Nino\Modules\Files::folders( $appData, 'staff' ), 'id' )
	&& array_keys( $listed['folders'][0] ) === [ 'id', 'group', 'name', 'upload', 'created', 'count' ]
	&& array_column( $listed['folders'], 'count' ) === array_map( static fn( array $folder ): int => count( \Nino\Modules\Files::files( $GLOBALS['appData'], $folder['id'] ) ), $listed['folders'] ) && array_sum( array_column( $listed['folders'], 'count' ) ) > 3 && $listed['types'] === \Nino\Modules\Files\Store::types( $appData )
	&& $listed['limits'] === [ 'chunk' => $chunkBytes, 'max' => 5 * $chunkBytes, 'php' => \Nino\Images::limits()['php'], 'quota' => 0, 'used' => \Nino\Modules\Files\Store::used( $appData, 'staff' ) ] );
$byAda = array_values( array_filter( $listed['files'], static fn( array $file ): bool => $file['by']['kind'] === 'member' && $file['by']['id'] === $GLOBALS['ada']['id'] ) )[0];
$appData['/nino/dir'] = '/club';
$inDirectory = ninoCall( $appData, $panel, [ 'group' => 'staff' ], 'files/list' )[1]['files'];
$appData['/nino/dir'] = '';
check( '...each file comes with the address it is downloaded at - the one of the API, below the directory the project is served from - and the screen links it', $listed['files'] !== [] && array_filter( $listed['files'], static fn( array $file ): bool => $file['url'] !== \Nino\Modules\Files::url( $GLOBALS['appData'], $file['id'] ) ) === []
	&& array_filter( $inDirectory, static fn( array $file ): bool => $file['url'] !== '/club/.files/'. $file['id'] ) === [] && $inDirectory !== [] );
check( '...a file put there by a member carries the name of that member for the screen, one put there by the owner none, and a file is listed in its own folder', $byAda['by']['label'] === 'Ada Lovelace' && array_filter( $listed['files'], static fn( array $file ): bool => $file['by']['kind'] === 'owner' && $file['by']['label'] !== '' ) === []
	&& array_diff( array_unique( array_column( $listed['files'], 'folder' ) ), array_column( $listed['folders'], 'id' ) ) === [] );
$other = ninoCall( $appData, $panel, [ 'group' => 'nobody' ], 'files/list' )[1];
$first = ninoCall( $appData, $panel, [], 'files/list' )[1];
$tourists = ninoCall( $appData, $panel, [ 'group' => 'tourists' ], 'files/list' )[1];
check( 'a group that is not among the groups, and none, is the first of them - and a group that only a folder has is listed, so that its folder can still be seen and deleted', $other['group'] === 'aardvark' && $first['group'] === 'aardvark' && array_column( $first['folders'], 'group' ) === [ 'aardvark' ]
	&& $tourists['group'] === 'tourists' && array_column( $tourists['folders'], 'id' ) === [ $leftBehind['id'] ] );
ninoCall( $appData, $panel, [ 'id' => $leftBehind['id'] ], 'files/folder/delete' );
ninoCall( $appData, $panel, [ 'id' => $firstGroup['id'] ], 'files/folder/delete' );

// Folders: add
$added = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => "  Concert\x07 tapes " ], 'files/folder/add' );
$newId = (string) ( $added[1]['folder']['id'] ?? '' );
check( 'a folder is added to a group of the members: the answer has it with its id, the name cleaned, no upload mark, and it is in the index after a fresh read', $added[0] === 200 && preg_match( '/^[a-f0-9]{12}$/D', $newId ) === 1 && $added[1]['folder']['name'] === 'Concert tapes' && $added[1]['folder']['upload'] === false
	&& filesIndex( $appData )['folders'][$newId]['group'] === 'staff' && filesIndex( $appData )['folders'][$newId]['name'] === 'Concert tapes' );

$refusals = [
	'a group the members do not have' => ninoCall( $appData, $panel, [ 'group' => 'tourists', 'name' => 'x' ], 'files/folder/add' ),
	'a group that is a list' => ninoCall( $appData, $panel, [ 'group' => [ 'staff' ], 'name' => 'x' ], 'files/folder/add' ),
	'no name' => ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => " \t " ], 'files/folder/add' ),
	'a name that is a list' => ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => [ 'x' ] ], 'files/folder/add' ),
	'a name of 81 characters' => ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => str_repeat( 'x', 81 ) ], 'files/folder/add' ),
];
$edgeFolder = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => str_repeat( 'ü', 80 ) ], 'files/folder/add' );
check( 'a group the members do not have is 400 files_group with the field group, a name that is empty 400 files_name, one of 81 characters 400 files_long with the limit and the field name - 80 characters, counted as characters, are fine',
	array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ). ' '. ( $answer[1]['field'] ?? '' ), $refusals ) === [ 'a group the members do not have' => '400 files_group group', 'a group that is a list' => '400 files_group group',
		'no name' => '400 files_name name', 'a name that is a list' => '400 files_name name', 'a name of 81 characters' => '400 files_long name' ] && $refusals['a name of 81 characters'][1]['params'] === [ 80 ] && $edgeFolder[0] === 200 );
ninoCall( $appData, $panel, [ 'id' => $edgeFolder[1]['folder']['id'] ], 'files/folder/delete' );

// A write that cannot be made is the server's failure, not a person's: a 500 with no code, and the index as it was
$indexPath = \Nino\Filesystem::path( $appData, '/data/files.php' );
$indexBytes = (string) file_get_contents( $indexPath );
unlink( $indexPath );
mkdir( $indexPath );
$writeFailed = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => 'Cannot be written' ], 'files/folder/add' );
rmdir( $indexPath );
file_put_contents( $indexPath, $indexBytes );
check( 'a write that cannot be made is a 500 with no code - and nothing of it is in the index after', $writeFailed[0] === 500 && isset( $writeFailed[1]['code'] ) === false && trim( (string) ( $writeFailed[1]['error'] ?? '' ) ) !== ''
	&& file_get_contents( $indexPath ) === $indexBytes && array_filter( filesIndex( $appData )['folders'], static fn( array $folder ): bool => $folder['name'] === 'Cannot be written' ) === [] );

// Rename
$renamed = ninoCall( $appData, $panel, [ 'id' => $newId, 'name' => ' Tapes of the concert ' ], 'files/folder/save' );
$renameRefused = [ ninoCall( $appData, $panel, [ 'id' => $newId, 'name' => '' ], 'files/folder/save' ), ninoCall( $appData, $panel, [ 'id' => $newId, 'name' => str_repeat( 'x', 81 ) ], 'files/folder/save' ),
	ninoCall( $appData, $panel, [ 'id' => 'ffffffffffff', 'name' => 'x' ], 'files/folder/save' ), ninoCall( $appData, $panel, [ 'id' => '../x', 'name' => 'x' ], 'files/folder/save' ) ];
check( 'a folder is renamed, with the rules of a name; an id nobody has, or that is none, is 404 files_unknown - and the folder keeps its name through the refusals',
	$renamed[0] === 200 && $renamed[1]['folder']['name'] === 'Tapes of the concert' && array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1]['code'], $renameRefused ) === [ '400 files_name', '400 files_long', '404 files_unknown', '404 files_unknown' ]
	&& filesIndex( $appData )['folders'][$newId]['name'] === 'Tapes of the concert' );

// The mark: exactly one folder of a group has it after another is marked
$secondId = (string) ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => 'Second' ], 'files/folder/add' )[1]['folder']['id'];
ninoCall( $appData, $panel, [ 'id' => $newId ], 'files/folder/upload-target' );
$moved = ninoCall( $appData, $panel, [ 'id' => $secondId ], 'files/folder/upload-target' );
$staffMarks = array_filter( filesIndex( $appData )['folders'], static fn( array $folder ): bool => $folder['group'] === 'staff' && $folder['upload'] === true );
check( 'marking a second folder of a group does not refuse and does not leave two: exactly one folder of the group carries the mark, the one marked last, and the answer says so - the folders of other groups keep theirs',
	$moved[0] === 200 && $moved[1]['folder']['upload'] === true && array_map( 'strval', array_keys( $staffMarks ) ) === [ $secondId ] && filesIndex( $appData )['folders'][$lobby['id']]['upload'] === false );
$unmarked = ninoCall( $appData, $panel, [ 'id' => $secondId, 'on' => false ], 'files/folder/upload-target' );
$unknownMark = ninoCall( $appData, $panel, [ 'id' => 'ffffffffffff' ], 'files/folder/upload-target' );
check( '...the mark is taken off with on false, and a folder nobody has is 404 files_unknown', $unmarked[0] === 200 && $unmarked[1]['folder']['upload'] === false && $unknownMark[0] === 404 && $unknownMark[1]['code'] === 'files_unknown'
	&& array_filter( filesIndex( $appData )['folders'], static fn( array $folder ): bool => $folder['group'] === 'staff' && $folder['upload'] === true ) === [] );
ninoCall( $appData, $panel, [ 'id' => $room['id'] ], 'files/folder/upload-target' );

// Files: change
$toggle = filesMake( $appData, $secondId, 'Toggle.mp3', 'TOGGLE-BYTES', [ 'kind' => 'owner', 'id' => '' ], false );
$otherFile = filesMake( $appData, $newId, 'Other.mp3', 'OTHER-BYTES' );
$shown = ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'visible' => true ], 'files/save' );
check( 'a file is made visible: the answer has it, the index says so after a fresh read, and nothing else of it changed', $shown[0] === 200 && $shown[1]['file']['visible'] === true && filesIndex( $appData )['files'][$toggle['id']]['visible'] === true
	&& array_diff_assoc( filesIndex( $appData )['files'][$toggle['id']], $toggle ) === [ 'visible' => true ] );
ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'visible' => false ], 'files/save' );
$ignored = ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'visible' => 'yes', 'name' => 'Renamed.mp3', 'size' => 1, 'by' => [ 'kind' => 'member', 'id' => $ada['id'] ] ], 'files/save' );
check( '...and hidden again; what is posted as something else than a flag changes nothing, and neither does anything but the flag and the folder - the name, the size and who put it there are not the panel\'s to change',
	filesIndex( $appData )['files'][$toggle['id']]['visible'] === false && $ignored[0] === 200 && filesIndex( $appData )['files'][$toggle['id']] === array_diff_key( $toggle, [ 'id' => 1 ] ) );

$movedFile = ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'folder' => $newId ], 'files/save' );
$moveRefused = [ 'a folder of another group' => ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'folder' => $lobby['id'] ], 'files/save' ), 'a folder nobody has' => ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'folder' => 'ffffffffffff' ], 'files/save' ),
	'something that is no folder' => ninoCall( $appData, $panel, [ 'id' => $toggle['id'], 'folder' => '../x' ], 'files/save' ), 'a file nobody has' => ninoCall( $appData, $panel, [ 'id' => 'ffffffffffff', 'visible' => true ], 'files/save' ) ];
check( 'a file moves to another folder of its group; a folder of another group, one nobody has and one that is none are 400 files_target with the field folder, a file nobody has 404 files_unknown - and the file stays where it was',
	$movedFile[0] === 200 && $movedFile[1]['file']['folder'] === $newId && array_map( static fn( array $answer ): string => $answer[0]. ' '. $answer[1]['code'], $moveRefused ) === [ 'a folder of another group' => '400 files_target', 'a folder nobody has' => '400 files_target',
		'something that is no folder' => '400 files_target', 'a file nobody has' => '404 files_unknown' ] && $moveRefused['a folder nobody has'][1]['field'] === 'folder' && filesIndex( $appData )['files'][$toggle['id']]['folder'] === $newId );

// Delete: a file, then a folder with files
$told = [];
$before = filesIndex( $appData );
$path = (string) \Nino\Modules\Files::path( $appData, $otherFile['id'] );
$deleted = ninoCall( $appData, $panel, [ 'id' => $otherFile['id'] ], 'files/delete' );
check( 'delete removes the entry and the bytes - that file and no other - and tells /files/delete with the entry as it was', $deleted[0] === 200 && isset( filesIndex( $appData )['files'][$otherFile['id']] ) === false && is_file( $path ) === false
	&& filesIndex( $appData )['files'] === array_diff_key( $before['files'], [ $otherFile['id'] => 1 ] ) && filesIndex( $appData )['folders'] === $before['folders'] && $told === [ [ '/files/delete', $otherFile ] ] );
$deleteAgain = ninoCall( $appData, $panel, [ 'id' => $otherFile['id'] ], 'files/delete' );
$deleteBad = ninoCall( $appData, $panel, [ 'id' => '../etc/passwd' ], 'files/delete' );
check( '...the same file again, and an id that is none, are 404 files_unknown and change nothing', [ $deleteAgain[0], $deleteAgain[1]['code'], $deleteBad[0], $deleteBad[1]['code'] ] === [ 404, 'files_unknown', 404, 'files_unknown' ] );

$kept = filesMake( $appData, $room['id'], 'Survivor.mp3', 'SURVIVOR' );
$told = [];
$inFolder = \Nino\Modules\Files::files( $appData, $newId );
$bytesOfFolder = array_map( static fn( array $file ): string => (string) \Nino\Modules\Files::path( $GLOBALS['appData'], $file['id'] ), $inFolder );
$deletedFolder = ninoCall( $appData, $panel, [ 'id' => $newId ], 'files/folder/delete' );
check( 'a folder is deleted with its files, entries and bytes - and /files/delete is told of each; the files of other folders and the other folders survive',
	$deletedFolder[0] === 200 && $deletedFolder[1] === [ 'deleted' => count( $inFolder ) ] && $inFolder !== [] && isset( filesIndex( $appData )['folders'][$newId] ) === false && array_diff( array_column( $inFolder, 'id' ), array_keys( filesIndex( $appData )['files'] ) ) === array_column( $inFolder, 'id' )
	&& array_filter( $bytesOfFolder, 'is_file' ) === [] && count( $told ) === count( $inFolder ) && \Nino\Modules\Files::file( $appData, $kept['id'] ) === $kept && is_file( (string) \Nino\Modules\Files::path( $appData, $kept['id'] ) ) === true );
$folderAgain = ninoCall( $appData, $panel, [ 'id' => $newId ], 'files/folder/delete' );
check( '...and the same folder again is 404 files_unknown', [ $folderAgain[0], $folderAgain[1]['code'] ] === [ 404, 'files_unknown' ] );

// The log and the tile
$lines = [];
foreach( [ 'files/folder/add', 'files/folder/save', 'files/folder/delete', 'files/folder/upload-target', 'files/save', 'files/delete', 'files/download', 'files/list' ] as $action )
	$lines[$action] = \Nino\Modules\Files\Admin::log( $action, [ 'id' => $kept['id'], 'name' => 'ada@example.org', 'email' => 'ada@example.org' ] );
$lines['files/upload'] = \Nino\Modules\Files\Admin::log( 'files/upload', [ 'id' => $kept['id'], 'name' => 'ada@example.org' ] );
check( 'the activity log has a line for every action that changes something - none for the list - and neither the address nor the name of a member nor the name of a file in any of them, only the id',
	$lines['files/list'] === '' && $lines['files/download'] === '' && array_filter( array_diff_key( $lines, [ 'files/list' => 1, 'files/download' => 1, 'files/upload' => 1 ] ), static fn( string $line ): bool => $line === '' ) === [] && array_filter( $lines, static fn( string $line ): bool => str_contains( $line, 'ada@example.org' ) ) === []
	&& str_contains( $lines['files/delete'], $kept['id'] ) === true && \Nino\Modules\Files\Admin::log( 'files/delete', [ 'id' => '<script>' ] ) === \Nino\Modules\Files\Admin::log( 'files/delete', [] ) );

$loggedFolder = ninoCall( $appData, $panel, [ 'group' => 'staff', 'name' => 'Logged folder' ], 'files/folder/add' );
$addLine = \Nino\Modules\Files\Admin::log( 'files/folder/add', [ 'group' => 'staff', 'name' => 'Logged folder' ] );
$markOn = \Nino\Modules\Files\Admin::log( 'files/folder/upload-target', [ 'id' => $kept['id'], 'on' => true ] );
$markOff = \Nino\Modules\Files\Admin::log( 'files/folder/upload-target', [ 'id' => $kept['id'], 'on' => false ] );
ninoCall( $appData, $panel, [ 'id' => $loggedFolder[1]['folder']['id'] ], 'files/folder/delete' );
check( '...a new folder is logged with its id, and marking a folder and taking the mark off are two lines that name the folder', $addLine !== '' && str_ends_with( $addLine, $loggedFolder[1]['folder']['id'] ) === true && $markOn !== $markOff && str_contains( $markOn, $kept['id'] ) === true && str_contains( $markOff, $kept['id'] ) === true );

$afterEnd = ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'Logged.mp3', 'size' => 54, 'upload' => '', 'index' => 0, 'count' => 1, 'visible' => true ], 'files/upload' );
$_FILES = [ 'chunk' => filesPart( $appData, "\xFF\xFB\x90\x00". str_repeat( 'z', 50 ) ) ];
$loggedDone = ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'Logged.mp3', 'size' => 54, 'upload' => '', 'index' => 0, 'count' => 1 ], 'files/upload' );
$_FILES = [];
$doneLine = \Nino\Modules\Files\Admin::log( 'files/upload', [ 'folder' => $room['id'], 'name' => 'Logged.mp3' ] );
$_FILES = [ 'chunk' => filesPart( $appData, str_repeat( 'a', $chunkBytes ) ) ];
ninoCall( $appData, $panel, [ 'folder' => $room['id'], 'name' => 'Partial.mp3', 'size' => 2 * $chunkBytes, 'upload' => '', 'index' => 0, 'count' => 2 ], 'files/upload' );
$_FILES = [];
$partLine = \Nino\Modules\Files\Admin::log( 'files/upload', [ 'folder' => $room['id'], 'name' => 'Partial.mp3' ] );
foreach( glob( \Nino\Filesystem::path( $appData, '/data/files/.upload' ). '/*.part' ) ?: [] as $leftover )
	unlink( $leftover );
check( 'an upload writes its line once, when the last chunk is in, and none for the chunks before it', $loggedDone[0] === 200 && $doneLine !== '' && str_ends_with( $doneLine, $loggedDone[1]['id'] ) === true && $partLine === '' && $afterEnd[0] === 400 );

$tile = \Nino\Modules\Files\Admin::summary( $appData );
check( 'the Dashboard\'s tile counts the files and tells how much room they take, with a label that has its sentence in both languages', preg_match( '/^(\d+) \((.+ (B|KB|MB|GB))\)$/D', $tile['value'], $found ) === 1 && (int) $found[1] === count( filesIndex( $appData )['files'] )
	&& array_filter( [ 'en_US', 'de_DE' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Admin\Admin::textFills( $GLOBALS['appData'], '/features/Files/text', $locale )['[['. \Nino\Modules\Files\Admin::summary( $GLOBALS['appData'] )['label']. ']]'] ?? '' ) ) === '' ) === [] );

// Every code has its sentence in both languages, every word the panel asks for is a fill of both, and the two files declare the same keys
$sources = (string) file_get_contents( $dir. '/Files.php' ). (string) file_get_contents( $dir. '/Upload/Upload.php' ). (string) file_get_contents( $dir. '/Admin/Admin.php' );
preg_match_all( "/'(files_[a-z]+)'/", $sources, $codes );
$codes = array_values( array_unique( $codes[1] ) );
sort( $codes );
$fills = [ 'en_US' => \Nino\Admin\Admin::textFills( $appData, '/features/Files/text', 'en_US' ), 'de_DE' => \Nino\Admin\Admin::textFills( $appData, '/features/Files/text', 'de_DE' ) ];
$missingCodes = [];
foreach( $codes as $code )
	foreach( $fills as $locale => $each )
		if( trim( (string) ( $each['[[/_admin/error/'. $code. ']]'] ?? '' ) ) === '' )
			$missingCodes[] = $code. ' '. $locale;
check( 'the code of every refusal - '. implode( ', ', $codes ). ' - has its sentence in both languages', $codes === [ 'files_chunk', 'files_folder', 'files_group', 'files_limit', 'files_long', 'files_name', 'files_quota', 'files_size', 'files_target', 'files_type', 'files_unknown', 'files_write' ] && $missingCodes === [] );
foreach( [ 'files_long', 'files_size', 'files_quota' ] as $code )
	foreach( $fills as $locale => $each )
		check( "...the sentence of $code in $locale takes its number", str_contains( $each['[[/_admin/error/'. $code. ']]'], '%s' ) === true );

preg_match_all( "~'(/_admin/files/[a-z0-9/-]+)'~", (string) file_get_contents( $dir. '/assets/admin.js' ). (string) file_get_contents( $dir. '/Admin/Admin.php' ), $asked );
$asked = array_values( array_unique( array_diff( $asked[1], [ '/_admin/files/unit/', '/_admin/files/manage' ] ) ) );
$askedUnits = [ '/_admin/files/unit/byte', '/_admin/files/unit/kilobyte', '/_admin/files/unit/megabyte', '/_admin/files/unit/gigabyte', '/_admin/files/unit/separator' ];
$notThere = [];
foreach( array_merge( $asked, $askedUnits, [ '/_admin/files/nav' ] ) as $key )
	foreach( $fills as $locale => $each )
		if( isset( $each['[['. $key. ']]'] ) === false || trim( (string) $each['[['. $key. ']]'] ) === '' )
			$notThere[] = $key. ' '. $locale;
check( 'every word the panel\'s script and class ask for ('. count( $asked ) + count( $askedUnits ) + 1 .') is a fill both languages define, and both files declare the same keys', $notThere === [] && count( $asked ) > 50
	&& array_keys( (array) include $dir. '/text/en_US.php' ) === array_keys( (array) include $dir. '/text/de_DE.php' ) );

// The words of the site: the same keys in both languages, and the ones the code and the templates ask for are there
$siteEn = array_keys( (array) include $dir. '/install/text/en_US.php' );
$siteDe = array_keys( (array) include $dir. '/install/text/de_DE.php' );
$siteAsked = [];
foreach( array_merge( glob( $dir. '/templates/*.tpl' ) ?: [], [ $dir. '/Files.php', $dir. '/assets/files.js' ] ) as $source )
	preg_match_all( "~[\[(']/feature/files/([a-z0-9-]+/[a-z0-9-]+)~", (string) file_get_contents( $source ), $keys ) && $siteAsked = array_merge( $siteAsked, $keys[1] );
$siteAsked = array_values( array_unique( $siteAsked ) );
$wanted = array_map( static fn( string $key ): string => '[[/feature/files/'. $key. ']]', $siteAsked );
$units = [ '[[/feature/files/unit/byte]]', '[[/feature/files/unit/kilobyte]]', '[[/feature/files/unit/megabyte]]', '[[/feature/files/unit/gigabyte]]', '[[/feature/files/unit/separator]]' ];
$states = array_map( static fn( string $name ): string => '[[/feature/files/state/'. $name. ']]', [ 'sent', 'folder', 'type', 'size', 'quota', 'chunk', 'limit', 'write' ] );
check( 'the unit brings the words of the site in both languages with the same keys; the units, the eight states and every word a template, the class or the script names are among them', $siteEn === $siteDe && array_diff( array_merge( $units, $states ), $siteEn ) === []
	&& array_diff( array_filter( $wanted, static fn( string $key ): bool => str_contains( $key, '[[' ) === true && preg_match( '~/mail/|/state/|/unit/|/script/|/upload/|/browser/~', $key ) === 1 && preg_match( '~/mail/(upload|new)-subject~', $key ) !== 1 ), $siteEn ) === [] );

echo "\n";

// --- The mails ---------------------------------------------------------------------

echo "The mails - to the owner when a member uploads, to the group when files are there\n";

filesWorkbench( 'admin' );
$smallFile = "\xFF\xFB\x90\x00". str_repeat( 'm', 100 );

/**
 *	A member's upload of one small file through the route
 *
 *	@param		array 		&$appData
 *	@param		string		$who					ada or cyd
 *	@param		string		$name
 *	@param		string		$folder
 *	@param		string		$bytes
 *
 *	@return 	int											The status of the answer
 */
function filesMemberUpload( array &$appData, string $who, string $name, string $folder, string $bytes ): int {

	filesAs( $who );
	filesFresh( $appData );

	return filesUpload( $appData, 'filesMemberSend', $name, $bytes, [ 'folder' => $folder ] )[0][0];
}

/**
 *	One small file through the panel's action
 *
 *	@param		array 		&$appData
 *	@param		string		$name
 *	@param		string		$folder
 *	@param		array 		$extra				visible
 *
 *	@return 	array										The answer [ status, body ]
 */
function filesOwnerUpload( array &$appData, string $name, string $folder, array $extra = [] ): array {

	filesWorkbench( 'admin' );
	filesFresh( $appData );

	return filesUpload( $appData, 'filesOwnerSend', $name, "\xFF\xFB\x90\x00". str_repeat( 'm', 100 ), [ 'folder' => $folder ] + $extra )[0];
}

$mails = [];
$offStatus = filesMemberUpload( $appData, 'ada', 'Silent.mp3', $room['id'], $smallFile );
check( 'with the setting notifyOwner off - the default - a member\'s upload sends no mail', $offStatus === 200 && $mails === [] );

\Nino\Features::saveSettings( $appData, 'files', [ 'notifyOwner' => true ] );
$mails = [];
$onStatus = filesMemberUpload( $appData, 'ada', 'Mail Take.mp3', $room['id'], $smallFile );
$mail = $mails[0] ?? [];
$text = strip_tags( (string) ( $mail['body'] ?? '' ) );
check( 'with it on one mail goes to the contact address of the website after a member\'s upload: the file named, its folder, the member, a link to the panel on the website\'s address, no key or token left in it',
	$onStatus === 200 && count( $mails ) === 1 && $mail['to'] === 'owner@example.org' && trim( (string) $mail['subject'] ) !== '' && str_contains( (string) $mail['subject'], '[[' ) === false && str_contains( (string) $mail['body'], '[[' ) === false
	&& str_contains( $text, 'Mail Take.mp3' ) === true && str_contains( $text, 'Room' ) === true && str_contains( $text, 'Ada Lovelace' ) === true && str_contains( (string) $mail['body'], 'href="https://example.org/club/_admin#files"' ) === true );

$mails = [];
filesAs( 'ada' );
filesFresh( $appData );
$threeChunks = filesUpload( $appData, 'filesMemberSend', 'Three.mp3', $mp3, [ 'folder' => $room['id'] ] );
check( '...one mail for each upload, and not for each chunk of it', array_column( $threeChunks, 0 ) === [ 200, 200, 200 ] && count( $mails ) === 1 );

$mails = [];
filesMemberUpload( $appData, 'ada', '<b>bold<b> [[company]].mp3', $room['id'], $smallFile );
check( 'a name is text in the mail: markup in it is not an element, and a key in it is not filled in', count( $mails ) === 1 && filesFind( (string) $mails[0]['body'], '//b' ) === [] && str_contains( (string) $mails[0]['body'], '[[company]]' ) === false
	&& str_contains( (string) $mails[0]['body'], '&lt;b&gt;bold&lt;b&gt;' ) === true );

$mails = [];
$delivery = false;
ninoWarnings();
$refusedMail = filesMemberUpload( $appData, 'ada', 'Unsent.mp3', $room['id'], $smallFile );
$delivery = true;
$unsentWarnings = ninoWarnings();
$unsentId = array_values( array_filter( \Nino\Modules\Files::files( $appData, $room['id'] ), static fn( array $file ): bool => $file['name'] === 'Unsent.mp3' ) )[0]['id'] ?? '';
check( 'a mail no transport takes does not undo the upload: the file is in, the answer is the same', $refusedMail === 200 && count( $mails ) === 1 && \Nino\Modules\Files::files( $appData, $room['id'] ) !== [] );
check( '...and it leaves a trace: one warning that names the file by its id and holds no address', $unsentId !== '' && count( $unsentWarnings ) === 1 && str_contains( $unsentWarnings[0], $unsentId ) === true && str_contains( $unsentWarnings[0], '@' ) === false );

// ...and so does a mail the kernel's cap of mails an hour refuses: nothing is sent, the upload is in, one warning
filesAs( 'ada' );
filesFresh( $appData );

for( $filler = 0; $filler < 5; $filler++ )
	\Nino\Mail::send( $appData, 'filler@example.org', 'Filler', 'Filler', '' );

$mails = [];
ninoWarnings();
$capped = filesUpload( $appData, 'filesMemberSend', 'Capped.mp3', $smallFile, [ 'folder' => $room['id'] ] )[0];
$cappedWarnings = ninoWarnings();
$cappedId = array_values( array_filter( \Nino\Modules\Files::files( $appData, $room['id'] ), static fn( array $file ): bool => $file['name'] === 'Capped.mp3' ) )[0]['id'] ?? '';
check( '...a mail the cap of mails an hour refuses is told the same way: nothing was sent, the upload is in, and one warning names the file by its id without an address', $capped[0] === 200 && $mails === [] && \Nino\Mail::rateLimited( $appData ) === true
	&& $cappedId !== '' && count( $cappedWarnings ) === 1 && str_contains( $cappedWarnings[0], $cappedId ) === true && str_contains( $cappedWarnings[0], '@' ) === false );
filesFresh( $appData );

ninoWarnings();
$mails = [];
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => '' ], '*' );
filesMemberUpload( $appData, 'ada', 'Nobody.mp3', $room['id'], $smallFile );
$noContact = $mails;
$warned = ninoWarnings();
$nobodyId = array_values( array_filter( \Nino\Modules\Files::files( $appData, $room['id'] ), static fn( array $file ): bool => $file['name'] === 'Nobody.mp3' ) )[0]['id'] ?? '';
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
check( 'a website with no contact address sends none, and says so in a warning for the log that names no address - and no file: no mail was tried, so it is not the warning of a mail that failed',
	$noContact === [] && $nobodyId !== '' && count( $warned ) >= 1 && array_filter( $warned, static fn( string $warning ): bool => str_contains( $warning, '@' ) || str_contains( $warning, $GLOBALS['nobodyId'] ) ) === [] );
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyOwner' => false ] );

// To the group
$members = [ $ada, $cyd ];
foreach( [ 'dan' => false, 'eve' => true, 'fay' => true, 'gus' => true, 'hal' => true, 'ivy' => true ] as $name => $active ) {
	$account = \Nino\Modules\Members::add( $appData, $name. '@example.org', ucfirst( $name ), 'staff' );
	\Nino\Modules\Members::change( $appData, $account['id'], [ 'active' => $active ] );
}

$mails = [];
$silent = filesOwnerUpload( $appData, 'Quiet.mp3', $room['id'] );
check( 'with the setting notifyMembers off - the default - the owner\'s upload sends no mail', $silent[0] === 200 && $mails === [] );

$mails = [];
check( '...nothing to send is no failure: with the setting off the group is told nothing and that is true, and so with no file - while for the owner, with the setting off, no mail was delivered: false',
	\Nino\Modules\Files::notifyMembers( $appData, $room, [ $kept ] ) === true && \Nino\Modules\Files::notifyMembers( $appData, $room, [] ) === true && \Nino\Modules\Files::notifyOwner( $appData, $kept, $room, $ada ) === false && $mails === [] );

\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => true ] );
$mails = [];
$notified = filesOwnerUpload( $appData, 'Fresh Mix.mp3', $room['id'] );
$recipients = array_column( $mails, 'to' );
sort( $recipients );
$forAda = array_values( array_filter( $mails, static fn( array $each ): bool => $each['to'] === 'ada@example.org' ) )[0] ?? [];
check( 'with it on the owner\'s visible upload sends one mail to each active member of the group - not to the members of other groups, not to one whose account is switched off',
	$notified[0] === 200 && $recipients === [ 'ada@example.org', 'cyd@example.org', 'eve@example.org', 'fay@example.org', 'gus@example.org', 'hal@example.org', 'ivy@example.org' ] && count( $mails ) === 7 );
check( '...each names the file and the folder, greets the member by name, links the website and carries the reply address of the contact - and is in the site\'s language, whatever language the request came in',
	str_contains( strip_tags( (string) $forAda['body'] ), 'Fresh Mix.mp3' ) === true && str_contains( strip_tags( (string) $forAda['body'] ), 'Room' ) === true && str_contains( strip_tags( (string) $forAda['body'] ), 'Ada Lovelace' ) === true
	&& str_contains( (string) $forAda['body'], 'href="https://example.org/club/"' ) === true && $forAda['replyTo'] === 'owner@example.org' && str_contains( (string) $forAda['body'], '[[' ) === false );
$mails = [];
filesOwnerUpload( $appData, '<u>under<u> [[x]].mp3', $room['id'] );
check( '...a name is text in the mail to the group as well: markup in it is not an element, and a key in it is not filled in', count( $mails ) === 7 && filesFind( (string) $mails[0]['body'], '//u' ) === [] && str_contains( (string) $mails[0]['body'], '&lt;u&gt;under' ) === true
	&& str_contains( (string) $mails[0]['body'], '[[x]]' ) === false );
check( '...and the size of each file stands beside its name', preg_match( '/Fresh Mix\.mp3\s*\(\d+ B\)/', strip_tags( (string) $forAda['body'] ) ) === 1 );

\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/members-area' ] );
$mails = [];
filesOwnerUpload( $appData, 'Start.mp3', $room['id'] );
\Nino\Features::saveSettings( $appData, 'members', [ 'start' => '/' ] );
check( '...and the link is the page the members land on after they sign in, the setting start of the Members feature', count( $mails ) === 7 && str_contains( (string) $mails[0]['body'], 'href="https://example.org/club/members-area"' ) === true );

$mails = [];
$noFiles = \Nino\Modules\Files::notifyMembers( $appData, $room, [] );
$nobodyThere = \Nino\Modules\Files::notifyMembers( $appData, [ 'id' => 'ffffffffffff', 'group' => 'nogroup', 'name' => 'Empty' ], [ $kept ] );
check( '...a group with no active member is mailed nothing, which is no failure - and so is nothing to tell', $nobodyThere === true && $noFiles === true && $mails === [] );

$appData['./nino/locales/current'] = 'en_US';
$mails = [];
filesOwnerUpload( $appData, 'Language.mp3', $room['id'] );
$native = \Nino\Html::getFills( $appData, 'de_DE' )['[[/feature/files/mail/new-subject]]'];
$other = \Nino\Html::getFills( $appData, 'en_US' )['[[/feature/files/mail/new-subject]]'];
$currentAfter = $appData['./nino/locales/current'];
$appData['./nino/locales/current'] = 'de_DE';
check( 'the mail goes out in the native language of the site, and the language of the request is back afterwards', $native !== $other && ( $mails[0]['subject'] ?? '' ) === $native && $currentAfter === 'en_US' );

check( 'a group of seven is mailed in full, though the default cap of the kernel is five mails an hour from one address: one upload is one hit of the cap', count( $mails ) === 7 && \Nino\Mail::rateLimited( $appData ) === false );

$mails = [];
$hiddenOne = filesOwnerUpload( $appData, 'Hidden Mix.mp3', $room['id'], [ 'visible' => false ] );
check( 'a hidden upload sends none', $hiddenOne[0] === 200 && $mails === [] );

$mails = [];
filesWorkbench( 'admin' );
filesFresh( $appData );
ninoCall( $appData, $panel, [ 'id' => $hiddenOne[1]['id'], 'visible' => true ], 'files/save' );
$madeVisible = count( $mails );
$mails = [];
filesFresh( $appData );
ninoCall( $appData, $panel, [ 'id' => $hiddenOne[1]['id'], 'visible' => true ], 'files/save' );
ninoCall( $appData, $panel, [ 'id' => $hiddenOne[1]['id'], 'visible' => false ], 'files/save' );
check( 'making a file visible sends one mail to each active member - once; a file that is visible already, and one that is hidden again, send none', $madeVisible === 7 && $mails === [] );

$mails = [];
filesFresh( $appData );
$delivery = false;
ninoWarnings();
$undelivered = filesOwnerUpload( $appData, 'Unsent Mix.mp3', $room['id'] );
$delivery = true;
$undeliveredWarnings = ninoWarnings();
check( 'mails no transport takes do not undo the upload either', $undelivered[0] === 200 && \Nino\Modules\Files::file( $appData, (string) $undelivered[1]['id'] ) !== null );
check( '...they leave one warning for the lot, which names the file by its id and holds no address', count( $undeliveredWarnings ) === 1 && str_contains( $undeliveredWarnings[0], (string) $undelivered[1]['id'] ) === true && str_contains( $undeliveredWarnings[0], '@' ) === false );

// ...and when the cap of mails an hour refuses the whole call, the same
filesWorkbench( 'admin' );
filesFresh( $appData );

for( $filler = 0; $filler < 5; $filler++ )
	\Nino\Mail::send( $appData, 'filler@example.org', 'Filler', 'Filler', '' );

$mails = [];
ninoWarnings();
$cappedGroup = filesUpload( $appData, 'filesOwnerSend', 'Capped Mix.mp3', "\xFF\xFB\x90\x00". str_repeat( 'm', 100 ), [ 'folder' => $room['id'] ] )[0];
$cappedGroupWarnings = ninoWarnings();
check( '...a call the cap refuses sends none of the seven mails and leaves one warning with the id of the file and no address', $cappedGroup[0] === 200 && $mails === [] && \Nino\Mail::rateLimited( $appData ) === true && count( $cappedGroupWarnings ) === 1
	&& str_contains( $cappedGroupWarnings[0], (string) $cappedGroup[1]['id'] ) === true && str_contains( $cappedGroupWarnings[0], '@' ) === false );
filesFresh( $appData );

ninoWarnings();
$mails = [];
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
$noAddress = filesOwnerUpload( $appData, 'No Address.mp3', $room['id'] );
$warnedNoAddress = ninoWarnings();
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
check( 'a site without an address has no link to put in a mail: none is sent, the upload is in, and a warning says so without naming a member', $noAddress[0] === 200 && $mails === [] && count( $warnedNoAddress ) >= 1
	&& array_filter( $warnedNoAddress, static fn( string $warning ): bool => str_contains( $warning, '@' ) ) === [] );
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => false ] );

// The owner's choice for one action: notify true mails whatever the setting says, false mails nobody whatever it says, absent - or anything but a bool - is the setting
$mails = [];
filesOwnerUpload( $appData, 'Choice Yes.mp3', $room['id'], [ 'notify' => true ] );
$yesWithSettingOff = count( $mails );
$mails = [];
filesOwnerUpload( $appData, 'Choice Absent Off.mp3', $room['id'] );
$absentWithSettingOff = count( $mails );
$mails = [];
filesOwnerUpload( $appData, 'Choice Text Off.mp3', $room['id'], [ 'notify' => 'true' ] );
$textWithSettingOff = count( $mails );
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => true ] );
$mails = [];
filesOwnerUpload( $appData, 'Choice No.mp3', $room['id'], [ 'notify' => false ] );
$noWithSettingOn = count( $mails );
$mails = [];
filesOwnerUpload( $appData, 'Choice Absent On.mp3', $room['id'] );
$absentWithSettingOn = count( $mails );
$mails = [];
filesOwnerUpload( $appData, 'Choice Text On.mp3', $room['id'], [ 'notify' => 'false' ] );
$textWithSettingOn = count( $mails );
check( 'the upload takes notify: true mails the group with the setting off, false mails nobody with the setting on, and absent is the setting either way - as is a notify that is no bool',
	$yesWithSettingOff === 7 && $absentWithSettingOff === 0 && $textWithSettingOff === 0 && $noWithSettingOn === 0 && $absentWithSettingOn === 7 && $textWithSettingOn === 7 );

$mails = [];
$silentOne = filesOwnerUpload( $appData, 'Choice Chunked.mp3', $room['id'], [ 'notify' => false ] );
check( '...a file that is not mailed is in all the same, visible', $silentOne[0] === 200 && ( \Nino\Modules\Files::file( $appData, (string) $silentOne[1]['id'] )['visible'] ?? null ) === true );

$hiddenTwo = filesOwnerUpload( $appData, 'Choice Hidden.mp3', $room['id'], [ 'visible' => false, 'notify' => true ] );
check( '...and a hidden file sends none whatever notify says - there is nothing for the members to see', $hiddenTwo[0] === 200 && $mails === [] );

$swapped = [];
foreach( [ [ true, false ], [ false, true ], [ null, true ] ] as [ $notify, $setting ] ) {
	\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => $setting ] );
	$again = filesOwnerUpload( $appData, 'Choice Swap '. count( $swapped ). '.mp3', $room['id'], [ 'visible' => false ] );
	$mails = [];
	filesWorkbench( 'admin' );
	filesFresh( $appData );
	ninoCall( $appData, $panel, [ 'id' => $again[1]['id'], 'visible' => true ] + ( $notify === null ? [] : [ 'notify' => $notify ] ), 'files/save' );
	$swapped[] = count( $mails );
}
check( 'making a file visible takes notify the same way: true mails with the setting off, false mails nobody with it on, absent is the setting', $swapped === [ 7, 0, 7 ] );

// What the panel answers says whether the members were told: mailed, the number of mails delivered
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => false ] );
$told = filesOwnerUpload( $appData, 'Told.mp3', $room['id'], [ 'notify' => true ] );
$toldLog = \Nino\Modules\Files\Admin::log( 'files/upload', [ 'notify' => true ] );
$untold = filesOwnerUpload( $appData, 'Untold.mp3', $room['id'], [ 'notify' => false ] );
$untoldLog = \Nino\Modules\Files\Admin::log( 'files/upload', [ 'notify' => false ] );
$noMail = filesOwnerUpload( $appData, 'No Mail.mp3', $room['id'] );
$hiddenAgain = filesOwnerUpload( $appData, 'Hidden Again.mp3', $room['id'], [ 'visible' => false, 'notify' => true ] );
filesWorkbench( 'admin' );
filesFresh( $appData );
$savedTold = ninoCall( $appData, $panel, [ 'id' => $hiddenAgain[1]['id'], 'visible' => true, 'notify' => true ], 'files/save' );
$savedPlain = ninoCall( $appData, $panel, [ 'id' => $hiddenAgain[1]['id'], 'folder' => $room['id'] ], 'files/save' );
check( 'the answer of the last chunk and of a switch to visible says how many mails were delivered - 7 where the group was told, 0 where nobody was, whatever the box said', ( $told[1]['mailed'] ?? -1 ) === 7 && ( $untold[1]['mailed'] ?? -1 ) === 0 && ( $noMail[1]['mailed'] ?? -1 ) === 0
	&& ( $savedTold[1]['mailed'] ?? -1 ) === 7 && ( $savedPlain[1]['mailed'] ?? -1 ) === 0 );
check( '...and the line of the log for an upload that was not mailed says so, where a mailed one does not', str_contains( $untoldLog, 'not mailed' ) === true && str_contains( $toldLog, 'not mailed' ) === false );
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => true ] );

$mails = [];
filesFresh( $appData );
$moved = ninoCall( $appData, $panel, [ 'id' => $hiddenTwo[1]['id'], 'visible' => true, 'notify' => 1 ], 'files/save' );
check( '...and a notify that is no bool is the setting there too', count( $mails ) === 7 && $moved[0] === 200 );

\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => true ] );
filesFresh( $appData );
$listed = ninoCall( $appData, $panel, [ 'group' => 'staff' ], 'files/list' );
\Nino\Features::saveSettings( $appData, 'files', [ 'notifyMembers' => false ] );
filesFresh( $appData );
$listedOff = ninoCall( $appData, $panel, [ 'group' => 'staff' ], 'files/list' );
check( 'the list says the setting as notify, for the box of the screen to start from', ( $listed[1]['notify'] ?? null ) === true && ( $listedOff[1]['notify'] ?? null ) === false );

echo "\n";


// --- Delete, restore, deactivation --------------------------------------------------

echo "The restore of a backup, and deactivation\n";

/**
 *	A staging directory that can be read and not written, for the restore that cannot put its filtered index back: the stream
 *	wrapper "filesblock" answers the staged index and refuses every open for writing. (A permission cannot do this here -
 *	the suite may run as the account that owns every file)
 */
class FilesBlockedStage {

	public mixed $context = null;
	public static string $index = '';
	private int $at = 0;

	public function url_stat( string $path, int $flags ): array|false {
		return str_ends_with( $path, '/data/files.php' ) === true ? [ 'mode' => 0100644, 'size' => strlen( self::$index ) ] : false;
	}

	public function stream_open( string $path, string $mode, int $options, ?string &$opened ): bool {
		$this->at = 0;
		return str_starts_with( $mode, 'r' ) === true;
	}

	public function stream_read( int $count ): string|false {
		$chunk = substr( self::$index, $this->at, $count );
		$this->at += strlen( $chunk );
		return $chunk;
	}

	public function stream_eof(): bool {
		return $this->at >= strlen( self::$index );
	}

	public function stream_stat(): array|false {
		return [ 'mode' => 0100644, 'size' => strlen( self::$index ) ];
	}

	public function stream_set_option( int $option, int $arg1, ?int $arg2 ): bool {
		return false;
	}
}

stream_wrapper_register( 'filesblock', 'FilesBlockedStage' );

// A backup brings the index of its day and not the bytes: what a restore keeps is what has bytes
$staging = $sandbox. '/restore-staging';
mkdir( $staging. '/data', 0755, true );
$dataDir = \Nino\Filesystem::path( $appData, '/data' );
$live = \Nino\Modules\Files::files( $appData, $room['id'] )[0];
$staged = [ 'folders' => [ $room['id'] => [ 'group' => 'staff', 'name' => 'Room', 'upload' => true, 'created' => '2026-01-01T00:00:00Z' ] ], 'files' => [
	$live['id'] => array_diff_key( $live, [ 'id' => 1 ] ),
	'aaaaaaaaaaaa' => [ 'folder' => $room['id'], 'name' => 'Gone.mp3', 'ext' => 'mp3', 'size' => 5, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '2026-01-01T00:00:00Z' ],
	'bbbbbbbbbbbb' => [ 'folder' => $room['id'], 'name' => 'Hostile.mp3', 'ext' => '../x', 'size' => 5, 'mime' => '', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
	// An id of digits only, which php reads back as a whole number: its bytes are there, so it stays
	'567890123456' => [ 'folder' => $room['id'], 'name' => 'Digits.mp3', 'ext' => 'mp3', 'size' => 3, 'mime' => 'audio/mpeg', 'sha256' => '', 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => '' ],
] ];
file_put_contents( $dataDir. '/files/567890123456.mp3', 'abc' );
file_put_contents( $staging. '/data/files.php', '<?php return '. var_export( $staged, true ). ';' );
$args = [ 'dataDir' => $dataDir, 'staging' => $staging ];
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $args );
$restored = (array) include $staging. '/data/files.php';
unlink( $dataDir. '/files/567890123456.mp3' );
check( 'a restore keeps the entries of the backup whose bytes are there (an id of digits among them) and drops the rest - the one that has none, and one that names an extension that is none - and leaves the folders', array_map( 'strval', array_keys( $restored['files'] ) ) === [ $live['id'], '567890123456' ] && $restored['folders'] === $staged['folders']
	&& $restored['files'][$live['id']] === array_diff_key( $live, [ 'id' => 1 ] ) );
// A staged index that can be read and not written: the filtered index cannot be put back. The write is not silenced - php says so in a warning -
// and the staged index is what it was, since a failed open does not touch it
FilesBlockedStage::$index = '<?php return '. var_export( $staged, true ). ';';
$blockedBefore = FilesBlockedStage::$index;
$blockedArgs = [ 'dataDir' => $dataDir, 'staging' => 'filesblock://stage' ];
$blockedErrors = ninoErrors( static function() use ( &$appData, &$blockedArgs ): void {
	\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $blockedArgs );
} );
check( 'a restore that cannot write the index it filtered says so - php\'s own warning, not silence - and the staged index stays as it was', count( $blockedErrors ) === 1 && $blockedErrors[0][0] === E_WARNING && str_contains( $blockedErrors[0][1], 'file_put_contents' ) === true
	&& FilesBlockedStage::$index === $blockedBefore );

$untouched = $sandbox. '/restore-empty';
mkdir( $untouched. '/data', 0755, true );
$noArgs = [ 'dataDir' => $dataDir, 'staging' => $untouched ];
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $noArgs );
$noStaging = [ 'dataDir' => $dataDir, 'staging' => '' ];
\Nino\Callbacks::doCallbacks( $appData, '/nino/admin/restore', $noStaging );
check( 'a backup without an index, and a call without a staging directory, change nothing and create nothing', scandir( $untouched ) === [ '.', '..', 'data' ] && scandir( $untouched. '/data' ) === [ '.', '..' ] );

// Deactivation takes the panel away and keeps what the project has
$kept = [ 'index' => filesIndex( $appData ), 'bytes' => count( glob( $dataDir. '/files/*.*' ) ?: [] ), 'counter' => is_file( $dataDir. '/files-tries.php' ) ];
filesWorkbench( 'admin' );
check( 'the feature the Members feature\'s sibling needs cannot be switched off from under it: Members is refused while Files is active', is_string( \Nino\Features::deactivate( $appData, 'members' ) ) === true );
check( 'deactivation succeeds', \Nino\Features::deactivate( $appData, 'files' ) === true );
check( '...the panel is not in the registry any more, and its actions are unknown', isset( \Nino\Admin\Admin::panels( $appData )['files'] ) === false && ninoCall( $appData, $panel, [], 'files/list' )[0] === 404 );
check( '...the index, the bytes and the counter stay as they were', $kept['index'] === filesIndex( $appData ) && $kept['index']['files'] !== [] && $kept['bytes'] === count( glob( $dataDir. '/files/*.*' ) ?: [] ) && $kept['bytes'] > 0 && $kept['counter'] === is_file( $dataDir. '/files-tries.php' ) );
check( '...and the class is out of /nino/modules', in_array( '\\Nino\\Modules\\Files', \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'], true ) === false );

echo "\n";


// --- The look for the Design feature -----------------------------------------------

echo "The look - the files in design/, read by their grammar\n";

/*	One stylesheet in design/, a variant of the feature for the Design feature (its manual, under Base, says what a variant
	is), and the block it shows on the specimen. A head of lines "@tag words", the ladders of the knobs and nothing else: the
	layout is files.css's own. Read here by its grammar - which tags, which fields, which names. What a head says is never
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
$defaults = $ruleOf( (string) file_get_contents( $dir. '/assets/files.css' ) )[':where(:root)'] ?? [];
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
	$knobs === [ 'ground' => [ 'ground', 'colours', 'default', 'Ground of the rows' ], 'space' => [ 'scale', 'surfaces', null, 'Space inside the browser and the form' ], 'corners' => [ 'scale', 'surfaces', null, 'Corners of the browser and the form' ] ]
	&& count( $head['@knob'] ) === 3 && array_map( static fn( string $text ): string => (string) preg_split( '/\s+/', $text, 2 )[0], $head['@knob-de'] ?? [] ) === [ 'ground', 'space', 'corners' ]
	&& array_filter( $head['@knob-de'], static fn( string $text ): bool => count( (array) preg_split( '/\s+/', $text, 2 ) ) < 2 ) === [] );

$rungs = [];

foreach( [ 'space', 'corners' ] as $scale )
	foreach( [ 'least', 'less', 'default', 'more', 'most' ] as $step )
		$rungs[] = '--files-'. $scale. '--'. $step;

check( 'each scale declares its five rungs, once each, on :where(:root) - and nothing else is declared, no rule is written and no colour is a literal',
	array_keys( $ladders ) === [ ':where(:root)' ] && array_keys( $ladders[':where(:root)'] ) === $rungs && array_sum( array_map( static fn( string $rung ): int => substr_count( $look, $rung. ':' ), $rungs ) ) === 10
	&& preg_match( '/#[0-9a-f]{3,8}\b|\b(?:rgba?|hsla?|hwb|oklch|oklab)\(|!important/i', (string) preg_replace( '#/\*.*?\*/#s', '', $look ) ) !== 1 );
check( 'the middle of each is the value files.css has as its default, so the form under it looks as it does without the Design feature',
	( $ladders[':where(:root)']['--files-space--default'] ?? null ) === ( $defaults['--files-space'] ?? false ) && ( $ladders[':where(:root)']['--files-corners--default'] ?? null ) === ( $defaults['--files-corners'] ?? false )
	&& isset( $defaults['--files-ground'], $defaults['--files-ground-on'], $defaults['--files-ground-link'], $defaults['--files-ground-border'], $defaults['--files-ground-focus'] ) === true );

$block = (string) file_get_contents( $designDir. '/preview.tpl' );
preg_match_all( '/\sid="([^"]*)"/', $block, $blockIds );
check( 'the block for the specimen: one grid row with the form written out and no shortcode, every id files- something and none twice, no section of its own, no inline handler and no script',
	str_starts_with( $block, '<div class="nino-grid-row">' ) === true && count( filesFind( $block, '//form[contains(@class, "files-upload")]' ) ) === 1 && count( filesFind( $block, '//li[contains(@class, "files-row")]' ) ) === 3 && count( filesFind( $block, '//div[contains(@class, "files-folder")]' ) ) === 1 && preg_match( '/\[files-[a-z]+/', $block ) !== 1
	&& $blockIds[1] !== [] && count( array_unique( $blockIds[1] ) ) === count( $blockIds[1] ) && array_filter( $blockIds[1], static fn( string $each ): bool => str_starts_with( $each, 'files-' ) === false ) === []
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
		$appData['/nino/modules'] = array_merge( array_values( array_diff( $modulesKept, [ '\\Nino\\Modules\\Files', '\\Nino\\Modules\\Design' ] ) ), array_map( static fn( string $name ): string => '\\Nino\\Modules\\'. $name, $names ) );
		unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
	};

	ninoWarnings();
	$featuresOn( [ 'Files', 'Design' ] );

	$scanNotes = [];
	$scan = $designSetup::features( $appData, $scanNotes );
	$feature = $scan['files'] ?? [];
	$variants = $feature['catalogue'] ?? [];
	$knobTold = [ 'ground' => [ 'ground', 'colours' ], 'space' => [ 'scale', 'surfaces' ], 'corners' => [ 'scale', 'surfaces' ] ];

	check( 'with the Design feature on, the scan lists this feature by its one look, which it has before anything is chosen, and has nothing to say of it',
		isset( $scan['files'] ) === true && realpath( (string) $feature['dir'] ) === realpath( $dir ) && array_keys( $variants ) === [ 'regular' ] && $feature['default'] === 'regular' && $scanNotes === [] );

	$appData['./nino/locales/current'] = 'en_US';
	$english = $designSetup::features( $appData )['files']['catalogue']['regular'] ?? [];
	$appData['./nino/locales/current'] = 'de_DE';
	$german = $designSetup::features( $appData )['files']['catalogue']['regular'] ?? [];
	$answers = [];

	foreach( $variants['regular']['knobs'] ?? [] as $name => $knob )
		$answers[$name] = [ $knob['kind'], $knob['tab'] ];

	check( '...described in the language of the workbench by the lines of its head, and answering to the three knobs with the kind and the tab the head names, each scale published by its ladder',
		( $english['name'] ?? null ) === $head['@name'][0] && ( $english['description'] ?? null ) === $head['@description'][0] && ( $german['name'] ?? null ) === $head['@name-de'][0] && ( $german['description'] ?? null ) === $head['@description-de'][0]
		&& $answers === $knobTold && ( $variants['regular']['knobs']['space']['ladder'] ?? [] ) === [ 'space' ] && ( $variants['regular']['knobs']['corners']['ladder'] ?? [] ) === [ 'corners' ] );

	$blockTold = [];
	$inside = $designSetup::previewOf( (string) $feature['dir'], 'files', $blockTold );
	$blocks = $designPreview::blocks( $appData );
	check( 'the block stands the check Design holds it to, the scan publishes its anchor, feature-files, and the preview makes the section of it', $inside === trim( $block ) && $blockTold === [] && ( $feature['anchor'] ?? null ) === 'feature-files'
		&& isset( $blocks['files'] ) === true && preg_match( '~^<section id="feature-files" class="nino-section"[^>]*>\s*(.*?)\s*</section>$~s', $blocks['files'], $section ) === 1 && str_contains( $section[1] ?? '', 'id="files-form"' ) === true );

	$featuresOn( [ 'Files', 'Design' ] );
	$notes = [];
	$sheet = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'files' => [ 'set' => 'regular' ] ] ], $library, $notes ), $library, $notes );
	$selected = [];

	foreach( [ 'space', 'corners' ] as $scale )
		if( str_contains( $sheet, "\n\t--files-". $scale. ': var('. sprintf( $designSetup::KNOB_TOKEN, 'files', $scale, 'default' ). ");\n" ) === false )
			$selected[] = $scale;

	foreach( \Nino\Modules\Design\Colours::parts() as $part )
		if( str_contains( $sheet, "\n\t--files-ground". ( $part === 'bg' ? '' : '-'. $part ). ': var('. \Nino\Modules\Design\Colours::token( 'default', $part ). ");\n" ) === false )
			$selected[] = 'ground '. $part;

	check( 'with the look chosen the sheet carries its stylesheet as the file has it, a line for the ladder of both scales and every part of the ground the palette solved, and compiles without a note',
		$selected === [] && $notes === [] && str_contains( $sheet, rtrim( $look, "\n" ) ) === true );

	$moved = $designCompiler::compile( $appData, $designSetup::normalize( $appData, [ 'features' => [ 'files' => [ 'set' => 'regular', 'knobs' => [ 'space' => -2, 'corners' => 2, 'ground' => 'dark' ] ] ] ], $library, $notes ), $library, $notes );
	check( '...a position that is moved reaches the sheet as the rung it stands for, and a ground that is chosen as the palette\'s token of that surface',
		str_contains( $moved, "\n\t--files-space: var(". sprintf( $designSetup::KNOB_TOKEN, 'files', 'space', 'least' ). ");\n" ) === true && str_contains( $moved, "\n\t--files-corners: var(". sprintf( $designSetup::KNOB_TOKEN, 'files', 'corners', 'most' ). ");\n" ) === true
		&& str_contains( $moved, "\n\t--files-ground: var(". \Nino\Modules\Design\Colours::token( 'dark', 'bg' ). ");\n" ) === true );

	// The bundle of a project the wizard has set up lists the sheet of the feature after the theme the Design feature writes
	$bundled = $appData;
	$bundled['/nino/html/assets'] = [ '/.cache/style.css' => array_merge( (array) ( \Nino\AppData::DEFAULTS['/nino/html/assets']['/.cache/style.css'] ?? [] ), [ $designCompiler::TARGET, '/assets/style.css' ] ) ];
	\Nino\Features::registerAssets( $bundled );
	$bundle = $bundled['/nino/html/assets']['/.cache/style.css'];
	check( 'in a booted project the bundle lists files.css after the theme.css the Design feature writes its sheet to - the order the :where() of its defaults is for',
		in_array( '/features/Files/assets/files.css', $bundle, true ) === true && array_search( '/features/Files/assets/files.css', $bundle, true ) > array_search( $designCompiler::TARGET, $bundle, true ) );

	$appData['/nino/modules'] = $modulesKept;
	$appData['./nino/locales/current'] = $localeKept;
	unset( $appData['./nino/features/all'], $appData['./nino/features/listed'], $appData['./design/features'] );
}

echo "\n";



// --- The panel's script, where node is on the path -----------------------------------
//
// files-js-smoke.js beside this file draws the screen over the kernel's own Nino.admin.js and a stand-in for the rest
// of Nino - the checkout this suite runs against, which is handed on - and runs files.js the same way; this suite
// runs it too where node is on the path, so that bin/check.sh and CI cover both halves in one go
$node = function_exists( 'shell_exec' ) === true ? trim( (string) @shell_exec( 'command -v node 2>/dev/null' ) ) : '';

if( $node === '' || function_exists( 'exec' ) === false )
	echo "  --  - node is not available here: files-js-smoke.js was NOT run\n";
else {
	$output = [];
	$exit = 1;
	exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/files-js-smoke.js' ). ' 2>&1', $output, $exit );
	check( 'files-js-smoke.js passes - '. ( $output === [] ? 'no output' : end( $output ) ), $exit === 0 );
}

echo "\n";
ninoDone( $appData );

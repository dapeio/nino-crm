<?php
declare(strict_types=1);

/**
 *	surface-smoke.php		Contract test for the surface of the project module \Crm\Crm at /crm: the routes it registers, the gate of the
 *											page (a visitor is sent to the workbench's login, an account without the permission is told so, an account
 *											with it gets the page), the page itself (its headers, its policy without an inline script, the JSON block
 *											with the token, the user and the words and no address of a client, no token left over), the assets (a hash in the name, the long
 *											cache, a 404 for a wrong hash and a name outside the list), the endpoint (401 without a session, 403 csrf
 *											without the token, 403 without the permission, 404 for an action the table does not hold, the answer of a
 *											panel's method exactly as the workbench gives it, a refusal with its code, params and field, the line of
 *											the activity log), the preview of a draft, the one permission that replaced two, and the new methods of the
 *											model: overdue(), needs(), recent(), summary(), copy(), remind(), the signature again and taken back, and the
 *											PDF of a draft. Features Members, Files, Pdf and Sign are active; the templates of Pdf are seeded the way its
 *											panel makes them. Runs over the kernel's tests/harness.php three levels up.
 *
 *	Usage: php app/Crm/tests/surface-smoke.php
 */

$root = dirname( __DIR__, 3 );
require $root. '/tests/harness.php';

use Crm\Db;
use Crm\Documents;
use Crm\Surface;
use Crm\Settings;

$appData = ninoSandbox( 'crm-surface' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$today = date( 'Y-m-d' );

/**
 *	A request as the output answers it: served, the response returned
 *
 *	@param		array 		&$appData
 *	@param		string		$method
 *	@param		string		$uri
 *	@param		string|null	$token				The token sent in X-CSRF-Token; none where null
 *	@param		string|null	$body					The body of the request
 *
 *	@return 	array										The response of the request
 */
function surfaceServe( array &$appData, string $method, string $uri, ?string $token = null, ?string $body = null ): array {

	$request = ninoVisit( $appData, $uri, $method, $token === null ? [] : [ 'HTTP_X_CSRF_TOKEN' => $token ] );

	if( $body !== null )
		$request['/nino/http/request']['body'] = $body;

	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return $request['/nino/http/response'];
}

/**
 *	One action through the endpoint, with the token of the session
 *
 *	@param		array 		&$appData
 *	@param		string		$action
 *	@param		array 		$data
 *
 *	@return 	array										[ status, body ]
 */
function surfaceApi( array &$appData, string $action, array $data = [] ): array {

	$response = surfaceServe( $appData, 'POST', '/crm/api', \Nino\Csrf::getToken( $appData ), (string) json_encode( [ 'action' => $action, 'data' => $data ] ) );

	return [ $response['statusCode'], $response['body'] ];
}

/**
 *	The JSON block of a page, decoded
 *
 *	@param		string		$page
 *
 *	@return 	array
 */
function surfaceData( string $page ): array {

	return preg_match( '~<script type="application/json" id="crm-data">(.*?)</script>~s', $page, $found ) === 1 ? (array) json_decode( $found[1], true ) : [];
}

/**
 *	The mails the transport took from a position on
 *
 *	@param		int				$from
 *
 *	@return 	array										A list of mails
 */
function surfaceMailsFrom( int $from ): array {

	global $mails;

	$taken = [];

	for( $index = $from; $index < count( $mails ); $index++ ) {
		$mail = $mails->offsetGet( $index );
		$taken[] = is_array( $mail ) === true ? $mail : [];
	}

	return $taken;
}

// --- The site, the features, the module ------------------------------------------------------------------------------

echo "The site\n";

$appData['/nino/dir'] = '';
\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club', '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Csrf::init( $appData );

$mails = new \ArrayObject();
$delivery = true;
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails, &$delivery ): void {
	$mails->append( $mail );
	$mail['sent'] = $delivery;
} );
$reset = static function() use ( &$appData ): void {
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
};

foreach( [ 'members', 'files', 'pdf', 'sign' ] as $key )
	check( 'the feature '. $key. ' is activated', \Nino\Features::activate( $appData, $key ) === true );

$listed = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] );
$listed[] = '\\Crm\\Crm';
$appData['/nino/modules'] = $listed;
\Nino\AppData::writeContentData( $appData, [ '/nino/modules' ] );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

$baseRoutes = [];
$baseBlacklist = [];
$baseConfig = [];
\Nino\Features::applyUnit( $appData, $root. '/_admin/install/library/base', [ 'de_DE', 'en_US' ], $baseRoutes, $baseBlacklist, $baseConfig, true );
$ignored = ninoWarnings();

$asVisitor = static function() use ( &$appData ): void {
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
};
$asUser = static function( string $mail, string $password ) use ( &$appData ): void {
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	\Nino\Auth::loginUser( $appData, $mail, $password );
};

ninoUser( $appData, 'admin@example.com' );
\Nino\Auth::insertUser( $appData, 'plain@example.com', 'plain password', [] );
\Nino\Auth::insertUser( $appData, 'crm@example.com', 'crm password', [ '/_admin/crm/manage' ] );
\Nino\Auth::insertUser( $appData, 'old@example.com', 'old password', [ '/_admin/crm-clients/manage', '/_admin/crm-documents/manage' ] );
$asAdmin = static function() use ( $asUser ): void { $asUser( 'admin@example.com', 'correct horse battery staple' ); };
$asCrm = static function() use ( $asUser ): void { $asUser( 'crm@example.com', 'crm password' ); };

// --- The routes ------------------------------------------------------------------------------------------------------

echo "\nThe routes\n";

$routes = $appData['/nino/http/routes'];
check( 'the module registers GET /crm, POST /crm/api, GET /crm/assets/* and GET /crm/preview/*', ( $routes['GET://crm']['uri'] ?? '' ) === '/crm' && ( $routes['POST://crm/api']['uri'] ?? '' ) === '/crm/api'
	&& ( $routes['GET://crm/assets/*']['uri'] ?? '' ) === '/crm/assets/*' && ( $routes['GET://crm/preview/*']['uri'] ?? '' ) === '/crm/preview/*' );
check( '...the endpoint carries csrf, so that the kernel checks the token of every post', ( $routes['POST://crm/api']['csrf'] ?? null ) === true );
check( '...each has its callback, and none is a reserved address of the kernel', array_filter( [ 'GET://crm', 'POST://crm/api', 'GET://crm/assets/*', 'GET://crm/preview/*' ], static fn( string $route ): bool => isset( $appData['./nino/callbacks']['/nino/http/response/'. $route] ) === false ) === []
	&& \Nino\Http::isReservedUri( '/crm' ) === false );

// --- The gate and the page -------------------------------------------------------------------------------------------

echo "\nThe page\n";

$asVisitor();
$gone = surfaceServe( $appData, 'GET', '/crm' );
$login = (string) $gone['body'];
$loginData = surfaceData( $login );
check( 'a visitor gets the login of the surface: 200 and a document of its own - no redirect to the workbench, whose login would end in the workbench', $gone['statusCode'] === 200 && isset( $gone['header']['Location'] ) === false && str_starts_with( $login, '<!doctype html>' ) === true && str_contains( $login, '<html lang="de">' ) === true && str_contains( $login, 'id="crm-data"' ) === true && str_contains( $login, 'id="main"' ) === false );
check( '...a form with a field for the mail (type email, autocomplete username) and one for the password (autocomplete current-password), a submit button, a status line that a screen reader hears and the way back to the site', preg_match( '~<form[^>]*id="login"~', $login ) === 1
	&& preg_match( '~<input[^>]*type="email"[^>]*name="user"[^>]*autocomplete="username"|<input[^>]*name="user"[^>]*type="email"[^>]*autocomplete="username"|<input[^>]*autocomplete="username"[^>]*type="email"[^>]*name="user"~', $login ) === 1
	&& preg_match( '~<input[^>]*type="password"[^>]*autocomplete="current-password"~', $login ) === 1 && preg_match( '~<button[^>]*type="submit"~', $login ) === 1 && preg_match( '~<[a-z]+[^>]*id="login-msg"[^>]*role="status"[^>]*aria-live="polite"|<[a-z]+[^>]*role="status"[^>]*aria-live="polite"[^>]*id="login-msg"~', $login ) === 1
	&& str_contains( $login, 'href="/"' ) === true );
check( '...its data block holds the base, the token of the visitor\'s session and the words - and no user, no link, no name of anybody', array_keys( $loginData ) === [ 'base', 'csrf', 'texts' ] && $loginData['csrf'] === \Nino\Csrf::getToken( $appData ) && $loginData['csrf'] !== '' && $loginData['base'] === ''
	&& $loginData['texts']['login.wrong'] === Surface::texts()['login.wrong'] && str_contains( $login, 'crm@example.com' ) === false );
check( '...nothing left unfilled, no inline script but the JSON block, no style, no handler attribute; the script is a file by the hash of its content, next to the stylesheet', str_contains( $login, '[[' ) === false && preg_match_all( '~<script(?![^>]*\ssrc=)[^>]*>~', $login, $inlineLogin ) === 1 && $inlineLogin[0] === [ '<script type="application/json" id="crm-data">' ]
	&& stripos( $login, '<style' ) === false && preg_match( '~\sstyle="|\son[a-z]+="~i', $login ) !== 1 && preg_match_all( '~(?:href|src)="(/crm/assets/[^"]+)"~', $login, $linkedLogin ) >= 2 && $linkedLogin[1] === [ '/crm/assets/'. Surface::assetName( 'crm.css' ), '/crm/assets/'. Surface::assetName( 'login.js' ) ] );
check( '...no favicon is asked for: the markup carries an icon of nothing', str_contains( $login, '<link rel="icon" href="data:,">' ) === true );

$asUser( 'plain@example.com', 'plain password' );
$forbidden = surfaceServe( $appData, 'GET', '/crm' );
check( 'an account without the permission gets 403 and the page of the surface that says so, in one sentence and with the way back to the workbench', $forbidden['statusCode'] === 403 && str_contains( $forbidden['body'], 'Für das CRM fehlt Deinem Konto die Berechtigung.' ) === true
	&& str_contains( $forbidden['body'], 'href="/_admin/"' ) === true && str_contains( $forbidden['body'], 'id="crm-data"' ) === false );
check( '...with the headers of the page: never cached, never framed, its own policy', $forbidden['header']['Cache-Control'] === 'private, no-store' && $forbidden['header']['X-Frame-Options'] === 'DENY' && str_contains( $forbidden['header']['Content-Security-Policy'], "default-src 'self'" ) === true );

$asUser( 'old@example.com', 'old password' );
check( 'the two permissions the panels had are no permission of the CRM: an account with them is told so', surfaceServe( $appData, 'GET', '/crm' )['statusCode'] === 403 );

$asCrm();
$page = surfaceServe( $appData, 'GET', '/crm' );
$html = (string) $page['body'];
$data = surfaceData( $html );
check( 'an account with the permission gets 200 and the document: a standalone page in German whose title is CRM', $page['statusCode'] === 200 && str_starts_with( $html, '<!doctype html>' ) === true && str_contains( $html, '<html lang="de">' ) === true && str_contains( $html, '<title>CRM</title>' ) === true
	&& str_contains( $html, 'id="main"' ) === true );
check( '...headers: never cached, never framed, nothing shared with another origin', $page['header']['Cache-Control'] === 'private, no-store' && $page['header']['X-Frame-Options'] === 'DENY' && $page['header']['Referrer-Policy'] === 'same-origin' && $page['header']['Content-Type'] === 'text/html; charset=utf-8' );
check( '...the login has the headers of the page, the policy as well: never cached, never framed, the same Content-Security-Policy', array_map( static fn( string $name ): string => (string) ( $gone['header'][$name] ?? '' ), [ 'Cache-Control', 'X-Frame-Options', 'Referrer-Policy', 'Content-Security-Policy', 'Content-Type' ] )
	=== array_map( static fn( string $name ): string => (string) ( $page['header'][$name] ?? '' ), [ 'Cache-Control', 'X-Frame-Options', 'Referrer-Policy', 'Content-Security-Policy', 'Content-Type' ] ) && $gone['header']['Content-Security-Policy'] !== '' );
check( '...and the page carries the icon of nothing too, and the 403 page', str_contains( $html, '<link rel="icon" href="data:,">' ) === true && str_contains( (string) $forbidden['body'], '<link rel="icon" href="data:,">' ) === true );
$policy = $page['header']['Content-Security-Policy'];
check( '...the policy is the surface\'s own: self for everything, scripts and styles from self alone, connect to self, no framing - and no inline anything', str_contains( $policy, "default-src 'self'" ) === true && str_contains( $policy, "script-src 'self'" ) === true && str_contains( $policy, "style-src 'self'" ) === true
	&& str_contains( $policy, "connect-src 'self'" ) === true && str_contains( $policy, "frame-ancestors 'none'" ) === true && str_contains( $policy, 'unsafe-inline' ) === false && str_contains( $policy, 'unsafe-eval' ) === false && str_contains( $policy, 'nonce' ) === false );
check( '...the page has no inline script (the only script without a src is the JSON block), no style element, no style attribute and no handler attribute', preg_match_all( '~<script(?![^>]*\ssrc=)[^>]*>~', $html, $inline ) === 1 && $inline[0] === [ '<script type="application/json" id="crm-data">' ]
	&& stripos( $html, '<style' ) === false && preg_match( '~\sstyle="~', $html ) !== 1 && preg_match( '~\son[a-z]+="~i', $html ) !== 1 );
check( '...every token is filled and no word is missing: no [[ is left in the page', str_contains( $html, '[[' ) === false && str_contains( $html, '&#91;' ) === false );
check( '...the JSON block holds the token of the session, the user, the words, the links and the directory', $data['csrf'] === \Nino\Csrf::getToken( $appData ) && $data['user'] === [ 'name' => 'crm@example.com' ] && $data['base'] === '' && $data['texts']['nav.overview'] === 'Übersicht'
	&& $data['links']['workbench'] === '/_admin/' && $data['links']['site'] === '/' && $data['links']['clients'] === '/_admin/#crm-clients' && $data['links']['items'] === '/_admin/#elements/positionen' && $data['links']['settings'] === '/_admin/#crm-documents' );
check( '...the words are the surface\'s and the sentences of the codes of both panels, as error.<code>', $data['texts']['error.crm_documents_fixed'] === Surface::texts()['error.crm_documents_fixed'] && isset( $data['texts']['error.crm_clients_email'] ) === true
	&& str_starts_with( $data['texts']['error.crm_documents_state'], 'Dieser Schritt' ) === true && count( array_filter( array_keys( $data['texts'] ), static fn( string $key ): bool => str_starts_with( $key, 'error.crm_' ) ) ) > 60 );

// the hashed names the page links are the ones the assets answer
$linked = [];
preg_match_all( '~(?:href|src)="(/crm/assets/[^"]+)"~', $html, $found );
$linked = $found[1];
check( '...it links the three files by a name with the hash of their content: css, money.js and then crm.js', count( $linked ) === 3 && $linked === [ '/crm/assets/'. Surface::assetName( 'crm.css' ), '/crm/assets/'. Surface::assetName( 'money.js' ), '/crm/assets/'. Surface::assetName( 'crm.js' ) ]
	&& preg_match( '~^[0-9a-f]{12}-crm\.css$~D', Surface::assetName( 'crm.css' ) ) === 1 );
check( '...the hash is the start of the sha-256 of the file, so that a change of the file is a new address', Surface::assetName( 'crm.js' ) === substr( (string) hash_file( 'sha256', $root. '/app/Crm/Surface/assets/crm.js' ), 0, 12 ). '-crm.js'
	&& Surface::assetName( 'money.js' ) === substr( (string) hash_file( 'sha256', $root. '/app/Crm/Documents/Money/assets/money.js' ), 0, 12 ). '-money.js' && Surface::assetName( 'evil.js' ) === '' );

// --- A client's address never enters the page ------------------------------------------------------------------------

$a = \Crm\Clients::add( $appData, [ 'company' => 'Studio Nord GmbH', 'name' => 'Anna Nord', 'street' => 'Hafenstr. 1', 'zip' => '20095', 'city' => 'Hamburg', 'email' => 'anna@studio-nord.example' ] );
$b = \Crm\Clients::add( $appData, [ 'name' => 'Ben Süd', 'street' => 'Lindenweg 3', 'zip' => '10115', 'city' => 'Berlin', 'email' => 'ben@example.org' ] );
$c = \Crm\Clients::add( $appData, [ 'company' => 'Ohne Konto AG', 'name' => 'Carla Ohne', 'street' => 'Leerweg 7', 'zip' => '50667', 'city' => 'Köln' ] );
$d = \Crm\Clients::add( $appData, [ 'company' => 'Alt AG', 'name' => 'Dora Alt', 'street' => 'Altweg 1', 'zip' => '01067', 'city' => 'Dresden' ] );
\Crm\Clients::setActive( $appData, $d['id'], false );
$accounts = [];

foreach( [ 'anna' => [ $a, 'Anna Nord' ], 'carl' => [ $a, 'Carl Nord' ], 'ben' => [ $b, 'Ben Süd' ] ] as $name => [ $client, $label ] ) {
	$account = \Nino\Modules\Members::add( $appData, $name. '@example.org', $label, $client['group'] );
	\Crm\Clients::link( $appData, $client['id'], $account['id'] );
	$accounts[$name] = $account;
}

$asCrm();
$withClients = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
check( 'no address and no name of a client, no street, no city, no mail address is in the page - the clients come through the endpoint, when a screen asks', array_filter( [ 'Hafenstr', 'Hamburg', '20095', 'anna@studio-nord.example', 'Lindenweg', 'Ben Süd', 'Studio Nord', 'Leerweg', 'Köln', 'anna@example.org', 'Carl Nord' ], static fn( string $needle ): bool => str_contains( $withClients, $needle ) === true ) === [] );
check( '...the one address the page does have of a person is the mail of the account that is signed in, escaped, in the rail and as the user', str_contains( $withClients, 'crm@example.com' ) === true && str_contains( $withClients, '<span class="who">crm@example.com</span>' ) === true );

\Nino\Auth::insertUser( $appData, 'a"<b>@example.com', 'weird password', [ '/_admin/crm/manage' ] );
$asUser( 'a"<b>@example.com', 'weird password' );
$weirdPage = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
check( 'a mail with markup in it is text in the rail and a string in the JSON block - it ends no element', str_contains( $weirdPage, '<span class="who">a&quot;&lt;b&gt;@example.com</span>' ) === true && str_contains( $weirdPage, '<b>@example.com' ) === false && surfaceData( $weirdPage )['user']['name'] === 'a"<b>@example.com' );
$asCrm();

// a member of the portal is signed in at the portal, not at the workbench: here nobody
$asVisitor();
$member = \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] );
( new \ReflectionMethod( \Nino\Modules\Members::class, '_signIn' ) )->invokeArgs( null, [ &$appData, &$member ] );
$asMember = surfaceServe( $appData, 'GET', '/crm' );
check( 'a member of the portal who is signed in is a visitor here: the login, 200, and nothing of the member in it', \Nino\Modules\Members::current( $appData ) !== null && \Nino\Auth::getCurrentUser( $appData ) === false && $asMember['statusCode'] === 200 && array_keys( surfaceData( (string) $asMember['body'] ) ) === [ 'base', 'csrf', 'texts' ]
	&& str_contains( (string) $asMember['body'], 'id="login"' ) === true && str_contains( (string) $asMember['body'], 'anna@example.org' ) === false && $asMember['header']['Content-Security-Policy'] === $gone['header']['Content-Security-Policy'] );
$asVisitor();
$asCrm();

// the brand is the name of the company of the site, escaped; where there is none the page is just CRM
\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => 'Studio <Nord> & Söhne' ], '*' );
$branded = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
$asVisitor();
$brandedLogin = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
$asCrm();
check( 'the brand of the page and of the login is the name of the company of the site, escaped, with CRM beside it', str_contains( $branded, '<div class="brand"><b>Studio &lt;Nord&gt; &amp; Söhne</b><span>CRM</span></div>' ) === true && str_contains( $brandedLogin, '<div class="brand"><b>Studio &lt;Nord&gt; &amp; Söhne</b><span>CRM</span></div>' ) === true && str_contains( $branded, 'Studio <Nord>' ) === false );
\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => '' ], '*' );
$unbranded = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
$asVisitor();
$unbrandedLogin = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
$asCrm();
check( '...an empty name shows CRM alone, on both', str_contains( $unbranded, '<div class="brand"><b>CRM</b><span></span></div>' ) === true && str_contains( $unbrandedLogin, '<div class="brand"><b>CRM</b><span></span></div>' ) === true );

// --- The assets ------------------------------------------------------------------------------------------------------

echo "\nThe assets\n";

check( 'the module serves four files and no other: the stylesheet, the script, money.js and the script of the login', array_keys( (array) ( new \ReflectionClass( Surface::class ) )->getConstant( 'ASSETS' ) ) === [ 'crm.css', 'crm.js', 'money.js', 'login.js' ] );

foreach( [ 'crm.css' => [ 'Surface/assets/crm.css', 'text/css; charset=utf-8' ], 'crm.js' => [ 'Surface/assets/crm.js', 'text/javascript; charset=utf-8' ], 'money.js' => [ 'Documents/Money/assets/money.js', 'text/javascript; charset=utf-8' ], 'login.js' => [ 'Surface/assets/login.js', 'text/javascript; charset=utf-8' ] ] as $name => [ $path, $type ] ) {
	$asset = surfaceServe( $appData, 'GET', '/crm/assets/'. Surface::assetName( $name ) );
	check( $name. ' is answered as a file: 200, the file of the module, '. $type. ', cached for a year', $asset['statusCode'] === 200 && realpath( (string) $asset['file'] ) === realpath( $root. '/app/Crm/'. $path ) && $asset['header']['Content-Type'] === $type && $asset['header']['Cache-Control'] === 'public, max-age=31536000' );
}

$asVisitor();
check( '...for a visitor as well: the files hold no data of the CRM', surfaceServe( $appData, 'GET', '/crm/assets/'. Surface::assetName( 'crm.css' ) )['statusCode'] === 200 );
$stale = '/crm/assets/'. str_repeat( '0', 12 ). '-crm.css';
$refused = [
	'a hash that is not the file\'s'      => $stale,
	'a name outside the list'            => '/crm/assets/'. substr( Surface::assetName( 'crm.js' ), 0, 12 ). '-evil.js',
	'no hash'                            => '/crm/assets/crm.css',
	'a path in the name'                 => '/crm/assets/'. substr( Surface::assetName( 'crm.js' ), 0, 12 ). '-../../../_nino/Nino.php',
	'the name of a php file'             => '/crm/assets/'. substr( Surface::assetName( 'crm.js' ), 0, 12 ). '-Surface.php',
	'an upper case hash'                 => '/crm/assets/'. strtoupper( substr( Surface::assetName( 'crm.js' ), 0, 12 ) ). '-crm.js',
];

$refused['a file of the module that is no asset, with the hash it has'] = '/crm/assets/'. substr( (string) hash_file( 'sha256', $root. '/app/Crm/Surface/templates/page.tpl' ), 0, 12 ). '-page.tpl';

foreach( $refused as $why => $uri ) {
	$answer = surfaceServe( $appData, 'GET', $uri );
	check( 'it is a 404, with no file named - '. $why, $answer['statusCode'] === 404 && isset( $answer['file'] ) === false );
}

$asCrm();

// --- The shell of the workbench is loaded where the entry point is the public one -----------------------------------------------

echo "\nThe public entry point\n";

$child = ninoSandboxDir( $appData ). '/child.php';
file_put_contents( $child, '<?php
require $argv[1]. "/_nino/Nino.php";
$appData = [ "./nino/uid" => "x", "./nino/filesystem/path" => $argv[2] ];
$before = class_exists( "\\Nino\\Admin\\Admin", false );
$ok = ( new ReflectionMethod( \\Crm\\Surface::class, "workbench" ) )->invokeArgs( null, [ &$appData ] );
echo json_encode( [ $before, $ok, class_exists( "\\Nino\\Admin\\Admin", false ) ] );' );
$php = escapeshellarg( PHP_BINARY );
$with = (string) shell_exec( $php. ' '. escapeshellarg( $child ). ' '. escapeshellarg( $root ). ' '. escapeshellarg( $root ). ' 2>&1' );
$without = (string) shell_exec( $php. ' '. escapeshellarg( $child ). ' '. escapeshellarg( $root ). ' '. escapeshellarg( ninoSandboxDir( $appData ) ). ' 2>&1' );
check( 'a request to /crm is served by index.php, which does not load the shell of the workbench: the endpoint loads _admin/Admin.php itself before the methods of the panels need guardPerm() and postData()', $with === '[false,true,true]' );
check( '...and where the workbench is not there it says so instead of calling a class that does not exist: the endpoint answers 500 with the code workbench', $without === '[false,false,false]' && isset( Surface::texts()['error.workbench'] ) === true );
unlink( $child );

// --- The look -----------------------------------------------------------------------------------------------------------

echo "\nThe look\n";

$css = (string) file_get_contents( $root. '/app/Crm/Surface/assets/crm.css' );
check( 'the stylesheet has the tokens of the approved look in :root, and the dark ones twice - by the setting of the system and by the choice of the page', str_contains( $css, '--accent: #1f5f8b' ) === true && str_contains( $css, '--radius: 6px' ) === true && str_contains( $css, '@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {' ) === true && str_contains( $css, ':root[data-theme="dark"] {' ) === true );
check( '...the components of the mock: rail, head, btn, menu, tabs, table, badge, tiles, kv, totals, editor rows, toast - and the dialog', array_filter( [ '.rail {', '.head {', '.btn {', '.menu .list {', '.tabs {', '.tbl {', '.badge {', '.tiles {', '.kv {', '.totals {', '.ed input {', '.toast {', 'dialog.dlg {' ], static fn( string $needle ): bool => str_contains( $css, $needle ) === false ) === [] );
check( '...and the rules of the small screen and of motion: 760px, reduced motion; nothing from another site and no inline data', str_contains( $css, '@media (max-width: 760px)' ) === true && str_contains( $css, 'prefers-reduced-motion' ) === true && preg_match( '~@import|url\(|https?://~', $css ) !== 1 );

// --- The model: documents to look at ---------------------------------------------------------------------------------

echo "\nThe documents\n";

$blocksOf = static fn( array $extra ): array => array_merge( [ [ 'type' => 'html', 'html' => '<h1>[[document.title]] [[document.number]]</h1><p>[[client.company]]</p>' ], [ 'type' => 'slot', 'name' => 'lines' ] ], $extra );
$member = [ 'type' => 'signature', 'name' => 'kunde', 'party' => 'member', 'label' => 'Kunde', 'page' => 'last', 'x' => 20, 'y' => 230, 'w' => 60, 'h' => 20, 'line' => true ];
$asAdmin();

foreach( [ [ 'rechnung', 'invoice', $blocksOf( [] ) ], [ 'angebot', 'offer', $blocksOf( [] ) ], [ 'vertrag', 'contract', $blocksOf( [ $member ] ) ], [ 'datenschutz', 'privacy', $blocksOf( [ $member ] ) ], [ 'zugang', 'information', $blocksOf( [] ) ],
	[ 'pflicht', 'information', $blocksOf( [ [ 'type' => 'input', 'name' => 'zusatz', 'label' => 'Zusatz', 'multiline' => false, 'required' => true ] ] ) ] ] as [ $key, $tag, $blocks ] )
	ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [ 'key' => $key, 'isNew' => true, 'name' => ucfirst( $key ), 'tag' => $tag, 'blocks' => $blocks ], 'pdf/templates/save' );

$line = static fn( string $title, string $price = '50' ): array => [ 'title' => $title, 'description' => 'Zeile zu '. $title, 'unit' => 'Std.', 'qty' => '2', 'price' => $price, 'vat_rate' => 0 ];
$day = static fn( int $offset ): string => ( new \DateTimeImmutable( $today ) )->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
$final = static function( string $category, int $clientId, array $input ) use ( &$appData ): array {
	$draft = Documents::add( $appData, $category, $clientId, $input );
	$done = is_array( $draft ) ? Documents::finalise( $appData, $draft['id'] ) : $draft;

	return is_array( $done ) ? $done : [ 'id' => 0, 'error' => $done ];
};

// 100 each (2 x 50), the VAT mode is small_business: gross = net
$late1 = $final( 'invoice', $a['id'], [ 'date' => $day( -60 ), 'due_date' => $day( -46 ), 'period_from' => $day( -70 ), 'period_to' => $day( -62 ), 'lines' => [ $line( 'Alt' ) ], 'template_key' => 'rechnung' ] );
Documents::setNotes( $appData, $late1['id'], 'Interne Notiz' );
$late2 = $final( 'invoice', $b['id'], [ 'date' => $day( -30 ), 'due_date' => $day( -10 ), 'lines' => [ $line( 'Neuer', '100' ) ], 'template_key' => 'rechnung' ] );
$fresh = $final( 'invoice', $a['id'], [ 'date' => $day( -2 ), 'due_date' => $day( 12 ), 'lines' => [ $line( 'Frisch' ) ], 'template_key' => 'rechnung' ] );
$duetoday = $final( 'invoice', $a['id'], [ 'date' => $day( -14 ), 'due_date' => $today, 'lines' => [ $line( 'Heute' ) ], 'template_key' => 'rechnung' ] );
$paidThisYear = $final( 'invoice', $b['id'], [ 'date' => date( 'Y' ). '-01-05', 'due_date' => date( 'Y' ). '-01-19', 'lines' => [ $line( 'Bezahlt', '300' ) ], 'template_key' => 'rechnung' ] );
$paidLastYear = $final( 'invoice', $b['id'], [ 'date' => ( (int) date( 'Y' ) - 1 ). '-12-05', 'due_date' => ( (int) date( 'Y' ) - 1 ). '-12-19', 'lines' => [ $line( 'Alt bezahlt', '70' ) ], 'template_key' => 'rechnung' ] );
Documents::setState( $appData, $paidThisYear['id'], 'sent' );
Documents::setState( $appData, $paidThisYear['id'], 'paid', date( 'Y' ). '-01-20' );
Documents::setState( $appData, $paidLastYear['id'], 'sent' );
Documents::setState( $appData, $paidLastYear['id'], 'paid', ( (int) date( 'Y' ) - 1 ). '-12-20' );
$sentLate = $final( 'invoice', $c['id'], [ 'date' => $day( -40 ), 'due_date' => $day( -26 ), 'lines' => [ $line( 'Versendet' ) ], 'template_key' => 'rechnung' ] );
Documents::setState( $appData, $sentLate['id'], 'sent' );
$expiring = $final( 'offer', $a['id'], [ 'date' => $day( -20 ), 'valid_until' => $day( 5 ), 'lines' => [ $line( 'Läuft ab', '30' ) ], 'template_key' => 'angebot' ] );
$weekEdge = $final( 'offer', $b['id'], [ 'date' => $day( -20 ), 'valid_until' => $day( 7 ), 'lines' => [ $line( 'Genau sieben' ) ], 'template_key' => 'angebot' ] );
$farOffer = $final( 'offer', $b['id'], [ 'date' => $day( -2 ), 'valid_until' => $day( 8 ), 'lines' => [ $line( 'Spät' ) ], 'template_key' => 'angebot' ] );
$ranOut = $final( 'offer', $b['id'], [ 'date' => $day( -50 ), 'valid_until' => $day( -3 ), 'lines' => [ $line( 'Abgelaufen' ) ], 'template_key' => 'angebot' ] );
$acceptedOffer = $final( 'offer', $a['id'], [ 'date' => $day( -10 ), 'valid_until' => $day( 3 ), 'lines' => [ $line( 'Angenommen' ) ], 'template_key' => 'angebot' ] );
Documents::setState( $appData, $acceptedOffer['id'], 'accepted' );
$contractViewed = $final( 'contract', 0, [ 'parent_id' => $expiring['id'], 'date' => $day( -9 ), 'title' => 'Vertrag angesehen', 'template_key' => 'vertrag' ] );
$privacyExpired = $final( 'privacy', $a['id'], [ 'date' => $day( -8 ), 'title' => 'Datenschutz abgelaufen', 'template_key' => 'datenschutz' ] );
$contractOk = $final( 'contract', 0, [ 'parent_id' => $acceptedOffer['id'], 'date' => $day( -7 ), 'title' => 'Vertrag in Ordnung', 'template_key' => 'vertrag' ] );
$info = $final( 'information', $a['id'], [ 'date' => $day( -6 ), 'title' => 'Zugang', 'template_key' => 'zugang' ] );
$draft = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'date' => $day( -1 ), 'lines' => [ $line( 'Entwurf', '10' ) ], 'template_key' => 'rechnung' ] );
$draftPlain = (array) Documents::add( $appData, 'information', $a['id'], [ 'title' => 'Entwurf Info', 'template_key' => 'pflicht', 'inputs' => [ 'zusatz' => 'Pflicht' ] ] );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'aaaaaaaaaaaa\', sign_state = \'viewed\', updated = \'2026-01-02T10:00:00Z\' WHERE id = :id', [ 'id' => $contractViewed['id'] ] );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'bbbbbbbbbbbb\', sign_state = \'expired\' WHERE id = :id', [ 'id' => $privacyExpired['id'] ] );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'cccccccccccc\', sign_state = \'requested\' WHERE id = :id', [ 'id' => $contractOk['id'] ] );
check( 'the documents are made', array_filter( [ $late1, $late2, $fresh, $duetoday, $paidThisYear, $paidLastYear, $sentLate, $expiring, $weekEdge, $farOffer, $ranOut, $acceptedOffer, $contractViewed, $privacyExpired, $contractOk, $info ], static fn( array $made ): bool => isset( $made['error'] ) === true ) === []
	&& $draft['state'] === 'draft' && $draftPlain['state'] === 'draft' );

// overdue()
echo "\noverdue()\n";

check( 'an invoice that is open or sent and past its due date is overdue', Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => $day( -1 ) ] ) === true && Documents::overdue( [ 'category' => 'invoice', 'state' => 'sent', 'due_date' => '2000-01-01' ] ) === true );
check( '...the day it is due is not late, a day later is', Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => $today ] ) === false && Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => $day( -1 ) ], $today ) === true
	&& Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => '2026-05-10' ], '2026-05-10' ) === false && Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => '2026-05-10' ], '2026-05-11' ) === true );
check( '...a paid, cancelled or draft invoice is not, and neither is an offer, a credit note or a contract, whatever their dates, and an invoice with no due date', array_filter( [
	[ 'invoice', 'paid', '2000-01-01' ], [ 'invoice', 'cancelled', '2000-01-01' ], [ 'invoice', 'draft', '2000-01-01' ], [ 'offer', 'open', '2000-01-01' ], [ 'credit', 'open', '2000-01-01' ], [ 'contract', 'open', '2000-01-01' ], [ 'invoice', 'open', null ],
], static fn( array $row ): bool => Documents::overdue( [ 'category' => $row[0], 'state' => $row[1], 'due_date' => $row[2] ] ) === true ) === [] );
check( '...a document read says so: get() and list() carry it', Documents::get( $appData, $late1['id'] )['overdue'] === true && Documents::get( $appData, $fresh['id'] )['overdue'] === false && Documents::get( $appData, $duetoday['id'] )['overdue'] === false
	&& array_column( Documents::list( $appData, [ 'category' => 'invoice' ] ), 'overdue', 'id' ) === array_column( array_map( static fn( array $row ): array => [ 'id' => $row['id'], 'overdue' => Documents::overdue( [ 'category' => $row['category'], 'state' => $row['state'], 'due_date' => Db::row( $GLOBALS['appData'], 'SELECT due_date FROM documents WHERE id = :id', [ 'id' => $row['id'] ] )['due_date'] ] ) ], Documents::list( $appData, [ 'category' => 'invoice' ] ) ), 'overdue', 'id' ) );
$listed = array_column( Documents::list( $appData ), null, 'id' );
check( '...and the list has the due date and the validity date of each document', $listed[$late1['id']]['due_date'] === $day( -46 ) && $listed[$late1['id']]['valid_until'] === null && $listed[$expiring['id']]['valid_until'] === $day( 5 ) && $listed[$expiring['id']]['due_date'] === null && $listed[$expiring['id']]['overdue'] === false );

// summary()
echo "\nsummary()\n";

$sum = Documents::summary( $appData );
check( 'the open invoices are the ones open or sent: how many and what they come to (a paid one is not among them, nor a draft, nor an offer)', $sum['count'] === 5 && $sum['gross'] === 60000 );
check( '...what is overdue: how many, what it comes to and the oldest due date; an invoice due today is not', $sum['overdue_count'] === 3 && $sum['overdue_gross'] === 40000 && $sum['overdue_oldest'] === $day( -46 ) );
check( '...the offers that wait, open or sent - one that ran out is still one; an accepted one is not', $sum['offers_count'] === 4 && $sum['offers_gross'] === 6000 + 10000 + 10000 + 10000 );
check( '...what was paid this year and which year that is: the invoice of last year is not in it', $sum['paid_year'] === (int) date( 'Y' ) && $sum['paid_count'] === 1 && $sum['paid_gross'] === 60000 );
check( '...the drafts: how many, and the day of the newest', $sum['drafts_count'] === 2 && $sum['drafts_last'] === $today );
check( '...and the Dashboard tile of the panel is still the open invoices', \Crm\Documents\Admin::summary( $appData )['value'] === '5 · 600,00'. "\u{00A0}". '€' );
Db::exec( $appData, 'DELETE FROM documents WHERE state = \'draft\'' );
$empty = Documents::summary( $appData );
check( '...with no draft the day is empty', $empty['drafts_count'] === 0 && $empty['drafts_last'] === '' );
$draft = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'date' => $day( -1 ), 'lines' => [ $line( 'Entwurf', '10' ) ], 'template_key' => 'rechnung' ] );
$draftPlain = (array) Documents::add( $appData, 'information', $a['id'], [ 'title' => 'Entwurf Info', 'template_key' => 'pflicht', 'inputs' => [ 'zusatz' => 'Pflicht' ] ] );

// needs()
echo "\nneeds()\n";

$needs = Documents::needs( $appData );
$kinds = array_column( $needs, 'kind' );
check( 'what needs the owner comes in four kinds, in this order: overdue invoices, signatures that wait, offers that run out, clients with no account', $kinds === [ 'overdue', 'overdue', 'overdue', 'sign', 'sign', 'expiring', 'expiring', 'account' ] );
check( '...the overdue ones, the oldest due date first, with number, client and amount: late, sent late, late', array_column( array_slice( $needs, 0, 3 ), 'id' ) === [ $late1['id'], $sentLate['id'], $late2['id'] ] && $needs[0]['number'] === $late1['number'] && $needs[0]['client_title'] === 'Studio Nord GmbH' && $needs[0]['gross'] === 10000
	&& $needs[0]['date'] === $day( -46 ) && $needs[0]['client_id'] === $a['id'] );
check( '...a signature that was looked at and one that ran out, with the title, the state and the day it changed; one that was only asked for is not among them', array_column( array_slice( $needs, 3, 2 ), 'id' ) === [ $contractViewed['id'], $privacyExpired['id'] ] && $needs[3]['title'] === 'Vertrag angesehen'
	&& $needs[3]['sign_state'] === 'viewed' && $needs[3]['date'] === '2026-01-02' && $needs[4]['sign_state'] === 'expired' && in_array( $contractOk['id'], array_column( $needs, 'id' ), true ) === false );
check( '...the offers that run out within seven days, today and the seventh day included, the eighth not, one that ran out already not, one that is accepted not', array_column( array_slice( $needs, 5, 2 ), 'id' ) === [ $expiring['id'], $weekEdge['id'] ] && $needs[5]['date'] === $day( 5 ) && $needs[6]['date'] === $day( 7 )
	&& in_array( $farOffer['id'], array_column( $needs, 'id' ), true ) === false && in_array( $ranOut['id'], array_column( $needs, 'id' ), true ) === false && in_array( $acceptedOffer['id'], array_column( $needs, 'id' ), true ) === false );
check( '...the clients with no account, the active ones only', $needs[7] === [ 'kind' => 'account', 'id' => 0, 'client_id' => $c['id'], 'client_title' => 'Ohne Konto AG', 'number' => $c['number'], 'title' => '', 'date' => '', 'gross' => 0, 'sign_state' => '' ]
	&& in_array( $d['id'], array_column( $needs, 'client_id' ), true ) === false );
check( '...an invoice that is paid, a draft and a document that is cancelled are not asked for', in_array( $paidThisYear['id'], array_column( $needs, 'id' ), true ) === false && in_array( $draft['id'], array_column( $needs, 'id' ), true ) === false );

$snapshot = Db::rows( $appData, 'SELECT id, state, sign_state FROM documents' );
$restore = static function() use ( &$appData, $snapshot ): void {
	foreach( $snapshot as $row )
		Db::exec( $appData, 'UPDATE documents SET state = :state, sign_state = :sign WHERE id = :id', [ 'state' => $row['state'], 'sign' => $row['sign_state'], 'id' => $row['id'] ] );
};

Db::exec( $appData, 'UPDATE documents SET state = \'paid\' WHERE category = \'invoice\' AND state IN ( \'open\', \'sent\' )' );
Db::exec( $appData, 'UPDATE documents SET sign_state = \'\' WHERE sign_state IN ( \'viewed\', \'expired\' )' );
Db::exec( $appData, 'UPDATE documents SET state = \'accepted\' WHERE category = \'offer\' AND state IN ( \'open\', \'sent\' )' );
\Crm\Clients::setActive( $appData, $c['id'], false );
check( 'with nothing to do the list is empty', Documents::needs( $appData ) === [] );
\Crm\Clients::setActive( $appData, $c['id'], true );
$restore();
check( '...and it is full again when the books are as they were', count( Documents::needs( $appData ) ) === 8 );

// an offer that is valid until today runs out today, and is asked for; the day after it is gone
Db::exec( $appData, 'UPDATE documents SET valid_until = :day WHERE id = :id', [ 'day' => $today, 'id' => $farOffer['id'] ] );
$edge = array_values( array_filter( Documents::needs( $appData ), static fn( array $need ): bool => $need['id'] === $farOffer['id'] ) );
check( 'an offer that is valid until today is among the offers that run out: it is the first of them, dated today', count( $edge ) === 1 && $edge[0]['kind'] === 'expiring' && $edge[0]['date'] === $today && array_column( array_slice( Documents::needs( $appData ), 5, 3 ), 'id' ) === [ $farOffer['id'], $expiring['id'], $weekEdge['id'] ] );
Db::exec( $appData, 'UPDATE documents SET valid_until = :day WHERE id = :id', [ 'day' => $day( -1 ), 'id' => $farOffer['id'] ] );
check( '...and one that was valid until yesterday is not', in_array( $farOffer['id'], array_column( Documents::needs( $appData ), 'id' ), true ) === false );
Db::exec( $appData, 'UPDATE documents SET valid_until = :day WHERE id = :id', [ 'day' => $day( 8 ), 'id' => $farOffer['id'] ] );

// recent()
echo "\nrecent()\n";

$all = Documents::list( $appData, [], false );
$recent = Documents::recent( $appData, 8 );
check( 'the latest documents are the first of the list, drafts among them, as the list has them', count( $recent ) === 8 && $recent === array_slice( $all, 0, 8 ) && array_column( $recent, 'id' ) === array_column( array_slice( $all, 0, 8 ), 'id' ) );
$dates = array_column( $recent, 'date' );
$newestFirst = $dates;
rsort( $newestFirst );
check( '...by date, the newest first', $dates === $newestFirst );
check( '...a smaller number is the first of them, none is none, and eight is the default', count( Documents::recent( $appData, 3 ) ) === 3 && Documents::recent( $appData, 0 ) === [] && count( Documents::recent( $appData ) ) === 8 && Documents::recent( $appData, 500 ) === $all );

// copy()
echo "\ncopy()\n";

$fresh1 = (array) Documents::get( $appData, $late1['id'] );
$copy = Documents::copy( $appData, $late1['id'] );
$strip = static fn( array $lines ): array => array_map( static fn( array $line ): array => array_intersect_key( $line, array_flip( [ 'title', 'description', 'unit', 'qty', 'price_net', 'vat_rate' ] ) ), $lines );
check( 'an invoice is copied to a draft of the same kind and client, with no number, today as the date and the due day of the setting', is_array( $copy ) && $copy['state'] === 'draft' && $copy['category'] === 'invoice' && $copy['number'] === '' && $copy['client_id'] === $a['id']
	&& $copy['date'] === $today && $copy['due_date'] === $day( 14 ) && $copy['id'] !== $late1['id'] && $copy['released'] === false && $copy['pdf_path'] === '' && $copy['notes'] === '' );
check( '...with the lines of the original, as they are, and the template', $strip( $copy['lines'] ) === $strip( $fresh1['lines'] ) && $copy['template_key'] === 'rechnung' && $copy['net'] === $fresh1['net'] && $copy['gross'] === $fresh1['gross'] );
check( '...nothing links it to the original, the period and the notes of the original are not taken, and the original is as it was', $copy['parent_id'] === null && Documents::get( $appData, $late1['id'] ) === $fresh1 && $copy['period_from'] === null && $copy['period_to'] === null && $fresh1['period_from'] === $day( -70 ) && $fresh1['notes'] === 'Interne Notiz' );

$offerCopy = Documents::copy( $appData, $expiring['id'] );
check( 'an offer is copied to a draft that is valid for the days of the setting from today', is_array( $offerCopy ) && $offerCopy['category'] === 'offer' && $offerCopy['valid_until'] === $day( 30 ) && $offerCopy['due_date'] === null && $offerCopy['template_key'] === 'angebot' );
$draftCopy = Documents::copy( $appData, $draft['id'] );
check( '...a draft is copied as well', is_array( $draftCopy ) && $draftCopy['state'] === 'draft' && $draftCopy['id'] !== $draft['id'] && $strip( $draftCopy['lines'] ) === $strip( Documents::get( $appData, $draft['id'] )['lines'] ) );

$contractCopy = Documents::copy( $appData, $contractOk['id'] );
check( 'a contract is copied to a draft with the same title, that hangs on the offer of the original; the client is the offer\'s', is_array( $contractCopy ) && $contractCopy['category'] === 'contract' && $contractCopy['title'] === 'Vertrag in Ordnung' && $contractCopy['parent_id'] === $acceptedOffer['id']
	&& $contractCopy['client_id'] === $a['id'] && $contractCopy['lines'] === [] && $contractCopy['state'] === 'draft' && $contractCopy['sign_id'] === '' && $contractCopy['sign_state'] === '' );
$infoCopy = Documents::copy( $appData, $draftPlain['id'] );
check( 'a document without lines takes its title, its template and its inputs along', is_array( $infoCopy ) && $infoCopy['title'] === 'Entwurf Info' && $infoCopy['template_key'] === 'pflicht' && $infoCopy['inputs'] === [ 'zusatz' => 'Pflicht' ] );

Db::exec( $appData, 'UPDATE documents SET template_key = \'gone\' WHERE id = :id', [ 'id' => $late2['id'] ] );
$noTemplate = Documents::copy( $appData, $late2['id'] );
check( 'where the template of the original is not there any more the copy has none - a draft may have none - and is made all the same', is_array( $noTemplate ) && $noTemplate['template_key'] === '' && $noTemplate['inputs'] === [] );
Db::exec( $appData, 'UPDATE documents SET template_key = \'rechnung\' WHERE id = :id', [ 'id' => $late2['id'] ] );

check( 'a document nobody has is "unknown", the copy of one of an inactive client is "inactive" and makes none', Documents::copy( $appData, 99999 ) === 'unknown' && ( static function() use ( &$appData, $d, $final, $line ): bool {
	\Crm\Clients::setActive( $appData, $d['id'], true );
	$made = $final( 'invoice', $d['id'], [ 'date' => '2026-02-01', 'lines' => [ $line( 'Alt' ) ], 'template_key' => 'rechnung' ] );
	\Crm\Clients::setActive( $appData, $d['id'], false );
	$before = (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents' )['n'];

	return Documents::copy( $appData, $made['id'] ) === 'inactive' && (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents' )['n'] === $before;
} )() === true );

// the rates are held to the setting of now
\Crm\Settings::set( $appData, 'vat_mode', 'regular' );
$regular = $final( 'invoice', $b['id'], [ 'date' => $day( -1 ), 'lines' => [ [ 'vat_rate' => 1900 ] + $line( 'Mit USt' ) ], 'template_key' => 'rechnung', 'reverse_charge' => false ] );
$reverse = $final( 'invoice', $b['id'], [ 'date' => $day( -1 ), 'lines' => [ [ 'vat_rate' => 1900 ] + $line( 'Reverse' ) ], 'template_key' => 'rechnung', 'reverse_charge' => true ] );
$regularCopy = Documents::copy( $appData, $regular['id'] );
check( 'in regular mode the rates of the original stay and the reverse charge with them', is_array( $regularCopy ) && $regularCopy['vat_mode'] === 'regular' && $regularCopy['lines'][0]['vat_rate'] === 1900 && $regularCopy['gross'] === $regular['gross'] && Documents::copy( $appData, $reverse['id'] )['reverse_charge'] === true );
$mixed = $final( 'invoice', $b['id'], [ 'date' => $day( -1 ), 'lines' => [ [ 'vat_rate' => 700 ] + $line( 'Sieben' ), [ 'vat_rate' => 1900 ] + $line( 'Neunzehn' ), [ 'vat_rate' => 0 ] + $line( 'Null' ) ], 'template_key' => 'rechnung' ] );
$mixedCopy = Documents::copy( $appData, $mixed['id'] );
check( '...every line keeps its own rate: 7, 19 and 0 stay as they are, in their order', is_array( $mixedCopy ) && array_column( $mixedCopy['lines'], 'vat_rate' ) === [ 700, 1900, 0 ] && array_column( $mixedCopy['lines'], 'title' ) === [ 'Sieben', 'Neunzehn', 'Null' ] && $mixedCopy['gross'] === $mixed['gross'] );
\Crm\Settings::set( $appData, 'vat_rates', '700,0' );
check( '...a rate the setting does not list any more refuses the copy, as an invoice from an offer is refused: line:0:rate:vat_rate', Documents::copy( $appData, $regular['id'] ) === 'line:0:rate:vat_rate' );
\Crm\Settings::set( $appData, 'vat_rates', '1900,700,0' );
\Crm\Settings::set( $appData, 'vat_mode', 'small_business' );
$smallCopy = Documents::copy( $appData, $regular['id'] );
check( 'in small business mode every rate of the copy is 0 and the reverse charge is gone', is_array( $smallCopy ) && $smallCopy['vat_mode'] === 'small_business' && $smallCopy['lines'][0]['vat_rate'] === 0 && $smallCopy['reverse_charge'] === false && Documents::copy( $appData, $reverse['id'] )['reverse_charge'] === false );

$credit = Documents::creditOf( $appData, $duetoday['id'] );
$creditCopy = Documents::copy( $appData, $credit['id'] );
check( 'a credit note is copied to a credit note, its lines as negative as they are', is_array( $creditCopy ) && $creditCopy['category'] === 'credit' && $creditCopy['lines'][0]['qty'] === -2000 && $creditCopy['parent_id'] === null && $creditCopy['gross'] === $credit['gross'] );

// remind()
echo "\nremind()\n";

$reset();
Documents::release( $appData, $late1['id'], false );
$before = count( $mails );
$reminded = Documents::remind( $appData, $late1['id'] );
$sent = surfaceMailsFrom( $before );
$remindedBody = (string) ( $sent[0]['body'] ?? '' );
check( 'an overdue invoice that is released reminds the accounts of its client: one mail each, to the two active accounts, and the answer says how many', is_array( $reminded ) && $reminded['mailed'] === 2 && array_column( $sent, 'to' ) === [ 'anna@example.org', 'carl@example.org' ] && $reminded['document']['id'] === $late1['id'] );
check( '...in the words of a reminder: the subject, the due day, the link to the page Dokumente, no attachment, nothing left unfilled', $sent[0]['subject'] === 'Zahlungserinnerung: Rechnung: '. $late1['number'] && str_contains( $sent[0]['body'], 'Eine Rechnung ist noch offen' ) === true
	&& str_contains( $sent[0]['body'], 'Fällig war sie am '. \Crm\Documents\Render::day( $day( -46 ) ). ':' ) === true && str_contains( $sent[0]['body'], 'href="https://example.org/club/portal/dokumente"' ) === true
	&& str_contains( $sent[0]['body'], '>Rechnung ansehen</a>' ) === true && str_contains( $sent[0]['body'], '[[' ) === false && str_contains( $sent[0]['body'], '.pdf' ) === false && str_contains( $sent[0]['body'], 'Hallo Anna Nord,' ) === true );
check( '...it changes nothing of the invoice: not its state, not its release, not its dates', Documents::get( $appData, $late1['id'] ) === array_merge( $reminded['document'], [] ) && $reminded['document']['state'] === 'open' && $reminded['document']['released'] === true );
$releaseMail = \Crm\Documents\Mail::compose( $appData, (array) Documents::get( $appData, $late1['id'] ) );
check( 'the release mail is still the release mail: its words are not the reminder\'s, and its button has its words - a [[button]] in the template was read as the shortcode and left the text "[]"', str_contains( (string) $releaseMail[0]['body'], 'Ein Dokument liegt für Dich bereit' ) === true
	&& str_contains( (string) $releaseMail[0]['body'], '>Dokumente ansehen</a>' ) === true && str_contains( (string) $remindedBody, '>Rechnung ansehen</a>' ) === true && str_contains( (string) $releaseMail[0]['body'], '[]' ) === false );

check( 'an invoice that is not overdue is "state": one due today, one paid, an offer, a draft', Documents::remind( $appData, $duetoday['id'] ) === 'state' && Documents::remind( $appData, $paidThisYear['id'] ) === 'state' && Documents::remind( $appData, $expiring['id'] ) === 'state' && Documents::remind( $appData, $copy['id'] ) === 'state' );
check( '...one that is not released is "released", and nobody is mailed; a document nobody has is "unknown"', Documents::remind( $appData, $late2['id'] ) === 'released' && Documents::remind( $appData, 99999 ) === 'unknown' && count( $mails ) === $before + 2 );
Documents::release( $appData, $sentLate['id'], false );
$nobody = Documents::remind( $appData, $sentLate['id'] );
check( 'a client with no account is mailed to nobody: mailed 0, and the reminder still is an answer', is_array( $nobody ) && $nobody['mailed'] === 0 && count( $mails ) === $before + 2 );
$noAddress = $appData;
\Nino\Html::addFills( $noAddress, [ '[[/project/website/general/url]]' => '' ], '*' );
check( 'a site with no address cannot link: "address", nothing mailed', Documents::remind( $noAddress, $late1['id'] ) === 'address' && count( $mails ) === $before + 2 );
$reset();

for( $n = 0; $n < 4; $n++ )
	Documents::remind( $appData, $late1['id'] );

$fifth = Documents::remind( $appData, $late1['id'] );
$sixth = Documents::remind( $appData, $late1['id'] );
check( 'the mails of a reminder are one call of the cap of mails an hour: five fit, the sixth is "limit" and mails nobody', is_array( $fifth ) === true && $sixth === 'limit' );
$reset();
Documents::unrelease( $appData, $late1['id'] );
Documents::unrelease( $appData, $sentLate['id'] );

// the signature again and taken back
echo "\nThe signature again and taken back\n";

Db::exec( $appData, 'UPDATE documents SET sign_id = \'\', sign_state = \'\' WHERE id = :id', [ 'id' => $contractOk['id'] ] );
Documents::release( $appData, $contractOk['id'], false );
check( 'a document with no request is "state" for both: there is nothing to send again and nothing to take back', Documents::remindSignature( $appData, $contractOk['id'] ) === 'state' && Documents::cancelSignature( $appData, $contractOk['id'] ) === 'state' );
check( '...an invoice is "category", a document nobody has "unknown"', Documents::remindSignature( $appData, $late1['id'] ) === 'category' && Documents::cancelSignature( $appData, $late1['id'] ) === 'category' && Documents::remindSignature( $appData, 99999 ) === 'unknown' && Documents::cancelSignature( $appData, 99999 ) === 'unknown' );

$reset();
$asked = Documents::requestSignature( $appData, $contractOk['id'], $accounts['anna']['id'], 7 );
$oldToken = \Nino\Modules\Sign\Store::get( $appData, $asked['document']['sign_id'] );
$before = count( $mails );
$again = Documents::remindSignature( $appData, $contractOk['id'] );
$newToken = \Nino\Modules\Sign\Store::get( $appData, $asked['document']['sign_id'] );
check( 'the link of a request that waits is sent again: the member is mailed once, the document is answered, the request has a new token and a new time', is_array( $again ) && $again['sign_state'] === 'requested' && count( $mails ) === $before + 1 && ( surfaceMailsFrom( $before )[0]['to'] ?? '' ) === 'anna@example.org'
	&& $newToken['expires'] >= $oldToken['expires'] );
$delivery = false;
check( '...where the mail is refused the link is as it was: "mail"', Documents::remindSignature( $appData, $contractOk['id'] ) === 'mail' );
$delivery = true;
\Nino\Features::deactivate( $appData, 'sign' );
check( '...with Sign off both are "sign"', Documents::remindSignature( $appData, $contractOk['id'] ) === 'sign' && Documents::cancelSignature( $appData, $contractOk['id'] ) === 'sign' );
\Nino\Features::activate( $appData, 'sign' );
$cancelled = Documents::cancelSignature( $appData, $contractOk['id'] );
check( 'the request is taken back: Sign says cancelled, the document reads so and stays open, and it is asked for again afterwards', is_array( $cancelled ) && $cancelled['sign_state'] === 'cancelled' && $cancelled['state'] === 'open' && \Nino\Modules\Sign::get( $appData, $asked['document']['sign_id'] )['status'] === 'cancelled'
	&& Documents::cancelSignature( $appData, $contractOk['id'] ) === 'state' && Documents::remindSignature( $appData, $contractOk['id'] ) === 'state' && is_array( Documents::requestSignature( $appData, $contractOk['id'], $accounts['carl']['id'] ) ) );
\Nino\Modules\Sign::cancel( $appData, Documents::get( $appData, $contractOk['id'] )['sign_id'] );
Documents::get( $appData, $contractOk['id'] );

// preview()
echo "\npreview()\n";

$files = static fn(): array => glob( \Nino\Filesystem::path( $appData, \Crm\Documents\Render::DIR ). '/*/*' ) ?: [];
$sequences = static fn(): array => Db::rows( $appData, 'SELECT * FROM sequences ORDER BY 1, 2' );
$filesBefore = $files();
$sequencesBefore = $sequences();
$shown = Documents::preview( $appData, $draft['id'] );
check( 'the PDF of a draft is made from the draft as it is: bytes that begin as a PDF do, named by its category and id', is_array( $shown ) && str_starts_with( $shown['bytes'], '%PDF' ) === true && $shown['name'] === 'invoice-'. $draft['id'] );
$shownPlain = Documents::preview( $appData, $draftPlain['id'] );
check( '...a document without lines the same, its required input filled', is_array( $shownPlain ) && str_starts_with( $shownPlain['bytes'], '%PDF' ) === true && $shownPlain['name'] === 'information-'. $draftPlain['id'] );
check( '...nothing is stored by it and no number is taken: no file, no sequence moved, the draft is a draft without a number', $files() === $filesBefore && $sequences() === $sequencesBefore && Documents::get( $appData, $draft['id'] )['number'] === '' && Documents::get( $appData, $draft['id'] )['state'] === 'draft' );
Db::exec( $appData, 'UPDATE documents SET inputs_json = \'{}\' WHERE id = :id', [ 'id' => $draftPlain['id'] ] );
check( '...a required input that is missing is told by its name: inputs:zusatz', Documents::preview( $appData, $draftPlain['id'] ) === 'inputs:zusatz' );
$noTemplateDraft = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'lines' => [ $line( 'Ohne' ) ] ] );
check( '...a draft with no template is "template", a document that is finalised is "state", one nobody has is "unknown"', Documents::preview( $appData, $noTemplateDraft['id'] ) === 'template' && Documents::preview( $appData, $late1['id'] ) === 'state' && Documents::preview( $appData, 99999 ) === 'unknown' );
\Nino\Features::deactivate( $appData, 'sign' );
\Nino\Features::deactivate( $appData, 'pdf' );
check( '...with Pdf off it is "pdf"', Documents::preview( $appData, $draft['id'] ) === 'pdf' );
\Nino\Features::activate( $appData, 'pdf' );
\Nino\Features::activate( $appData, 'sign' );

// --- The preview route -----------------------------------------------------------------------------------------------

echo "\nThe preview\n";

$asVisitor();
$answer = surfaceServe( $appData, 'GET', '/crm/preview/'. $draft['id'] );
check( 'a visitor is told the session ended: 401, plain text, nothing cached', $answer['statusCode'] === 401 && $answer['body'] === 'Deine Sitzung ist abgelaufen.' && $answer['header']['Content-Type'] === 'text/plain; charset=utf-8' && $answer['header']['Cache-Control'] === 'no-store' );
$asUser( 'plain@example.com', 'plain password' );
check( 'an account without the permission: 403', surfaceServe( $appData, 'GET', '/crm/preview/'. $draft['id'] )['statusCode'] === 403 );
$asCrm();
$answer = surfaceServe( $appData, 'GET', '/crm/preview/'. $draft['id'] );
check( 'an account with it gets the PDF of the draft, inline, under a name that is the category and the id, kept out of every cache', $answer['statusCode'] === 200 && str_starts_with( (string) $answer['body'], '%PDF' ) === true && $answer['header']['Content-Type'] === 'application/pdf'
	&& $answer['header']['Content-Disposition'] === 'inline; filename="invoice-'. $draft['id']. '.pdf"' && $answer['header']['Cache-Control'] === 'private, no-store' );
$plainAnswer = surfaceServe( $appData, 'GET', '/crm/preview/'. $noTemplateDraft['id'] );
check( '...a draft the template of which is missing is 409 with the sentence that says what to do', $plainAnswer['statusCode'] === 409 && $plainAnswer['body'] === Surface::texts()['preview.template'] && $plainAnswer['header']['Content-Type'] === 'text/plain; charset=utf-8' );
check( '...a document that is finalised 409 with its sentence, one nobody has and an id that is none 404, a required input that is missing the sentence of the code', surfaceServe( $appData, 'GET', '/crm/preview/'. $late1['id'] )['body'] === Surface::texts()['preview.state']
	&& surfaceServe( $appData, 'GET', '/crm/preview/99999' )['statusCode'] === 404 && surfaceServe( $appData, 'GET', '/crm/preview/abc' )['statusCode'] === 404 && surfaceServe( $appData, 'GET', '/crm/preview/0' )['statusCode'] === 404
	&& surfaceServe( $appData, 'GET', '/crm/preview/'. $draftPlain['id'] )['body'] === Surface::texts()['error.crm_documents_inputs'] );
check( '...and the account of the workbench is served the same, as the permission of the CRM is the root\'s', ( static function() use ( $asAdmin, &$appData, $draft ): bool { $asAdmin(); return surfaceServe( $appData, 'GET', '/crm/preview/'. $draft['id'] )['statusCode'] === 200; } )() );
$asCrm();

// --- The endpoint ----------------------------------------------------------------------------------------------------

echo "\nThe endpoint\n";

$body = (string) json_encode( [ 'action' => 'crm-documents/list', 'data' => [] ] );
$asVisitor();
$noToken = surfaceServe( $appData, 'POST', '/crm/api', null, $body );
check( 'a post without the token is refused by the kernel: 403 with the code csrf, the one the page reloads on', $noToken['statusCode'] === 403 && $noToken['body']['code'] === 'csrf' && $noToken['header']['Cache-Control'] === 'no-store' );
$heavy = ninoVisit( $appData, '/crm/api', 'POST', [ 'CONTENT_LENGTH' => '999999999999', 'HTTP_CONTENT_LENGTH' => '999999999999' ] );
$heavy['/nino/http/request']['body'] = '';
\Nino\Http::response( $appData, $heavy );
check( 'a request larger than the server takes arrives without its token and is told what it is: 413 post_too_large, with the limit in megabytes, not 403', $heavy['/nino/http/response']['statusCode'] === 413 && $heavy['/nino/http/response']['body']['code'] === 'post_too_large' && $heavy['/nino/http/response']['body']['params'][0] >= 1 );
$wrongToken = surfaceServe( $appData, 'POST', '/crm/api', 'not the token', $body );
check( '...a token that is not the session\'s the same', $wrongToken['statusCode'] === 403 && $wrongToken['body']['code'] === 'csrf' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/list' );
check( 'with the token and no session: 401 with the code session, the one the page asks to log in on', $status === 401 && $answer['code'] === 'session' );

$asUser( 'plain@example.com', 'plain password' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/list' );
check( 'an account without the permission: 403, and nothing is read', $status === 403 && isset( $answer['documents'] ) === false );
check( '...it is told so before it is told which actions there are: an action nobody has is 403 for it as well, and the action of a panel it may not use too', surfaceApi( $appData, 'crm-documents/nope' )[0] === 403 && surfaceApi( $appData, '' )[0] === 403 && surfaceApi( $appData, 'users/list' )[0] === 403 );
$asUser( 'old@example.com', 'old password' );
check( '...the permissions the panels had before are none: 403 for both panels\' actions, and the panels themselves refuse them', surfaceApi( $appData, 'crm-documents/list' )[0] === 403 && surfaceApi( $appData, 'crm-clients/list' )[0] === 403
	&& ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [], 'crm-documents/list' )[0] === 403 && ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [], 'crm-clients/list' )[0] === 403 );

$asCrm();
$unknown = [ 'an action nobody has' => 'crm-documents/nope', 'no action' => '', 'an action of another panel' => 'elements/list', 'one of the workbench' => 'users/list', 'a path' => '../crm-documents/list', 'the name of a method' => 'apiList' ];

foreach( $unknown as $why => $action ) {
	[ $status, $answer ] = surfaceApi( $appData, $action );
	check( 'the table does not hold '. $why. ': 404 with the code action', $status === 404 && $answer['code'] === 'action' );
}

foreach( [ 'not json', '[]', '{"action":["crm-documents/list"]}', '{"action":1}' ] as $broken ) {
	$answer = surfaceServe( $appData, 'POST', '/crm/api', \Nino\Csrf::getToken( $appData ), $broken );
	check( 'a body that is no action ('. $broken. ') is 404 action as well', $answer['statusCode'] === 404 && $answer['body']['code'] === 'action' );
}

check( 'the table is the panels\' own: the actions of Kunden and of Belege by their names, and none else', array_keys( Surface::actions() ) === array_merge( array_keys( \Crm\Clients\Admin::actions() ), array_keys( \Crm\Documents\Admin::actions() ) )
	&& array_filter( array_keys( Surface::actions() ), static fn( string $action ): bool => preg_match( '~^crm-(clients|documents)/[a-z-]+$~D', $action ) !== 1 ) === [] );

$asAdmin();
[ $status, $direct ] = ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [ 'year' => (string) date( 'Y' ) ], 'crm-documents/list' );
$asCrm();
[ $viaStatus, $via ] = surfaceApi( $appData, 'crm-documents/list', [ 'year' => (string) date( 'Y' ) ] );
check( 'a list through the endpoint equals the answer of the panel, to the byte of its JSON', $viaStatus === $status && json_encode( $via ) === json_encode( $direct ) && count( $via['documents'] ) > 10 );
[ $viaStatus, $via ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $late1['id'] ] );
$asAdmin();
[ $status, $direct ] = ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [ 'id' => (string) $late1['id'] ], 'crm-documents/get' );
$asCrm();
check( '...so does a document, with its templates, clients and accounts - and the overview the screens start from has the books, what needs the owner and the latest documents', json_encode( $via ) === json_encode( $direct ) && $viaStatus === 200 && ( $overview = surfaceApi( $appData, 'crm-documents/overview' )[1] )
	&& array_keys( $overview ) === [ 'summary', 'needs', 'recent', 'settings' ] && $overview['summary'] === Documents::summary( $appData ) && count( $overview['needs'] ) === count( Documents::needs( $appData ) ) && count( $overview['recent'] ) === 8 && $overview['settings']['currency'] === '€' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-clients/list' );
check( '...and the actions of the other panel go through it, too', $status === 200 && count( $answer['clients'] ) === 4 );

[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => 'abc' ] );
check( 'a refusal keeps its status, its code and its field: 400 crm_documents_id, field id', $status === 400 && $answer['code'] === 'crm_documents_id' && $answer['field'] === 'id' && is_string( $answer['error'] ) === true );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => '99999' ] );
check( '...404 crm_documents_unknown', $status === 404 && $answer['code'] === 'crm_documents_unknown' && $answer['field'] === 'id' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/save', [ 'id' => (string) $draft['id'], 'lines' => [ $line( 'Gut' ), [ 'title' => '', 'qty' => '1', 'price' => '1', 'vat_rate' => 0 ] ] ] );
check( '...a line that is wrong has its params and its field: 400 crm_documents_line_empty, [2], lines.1.title', $status === 400 && $answer['code'] === 'crm_documents_line_empty' && $answer['params'] === [ 2 ] && $answer['field'] === 'lines.1.title' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/save', [ 'id' => (string) $late1['id'], 'title' => 'x' ] );
check( '...a finalised document is 409 crm_documents_fixed', $status === 409 && $answer['code'] === 'crm_documents_fixed' );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/remind', [ 'id' => (string) $late2['id'] ] );
check( '...and the new refusals: a reminder for a document that is not released is 409 crm_documents_released', $status === 409 && $answer['code'] === 'crm_documents_released' && isset( Surface::texts()['error.crm_documents_released'] ) === true );

$linesBefore = count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) );
surfaceApi( $appData, 'crm-documents/save', [ 'id' => (string) $late1['id'], 'title' => 'x' ] );
surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $late1['id'] ] );
check( 'a refusal and a read leave no line in the activity log', count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) ) === $linesBefore );

// what the methods read is what the endpoint was given
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $b['id'], 'date' => $day( -3 ), 'template_key' => 'rechnung', 'lines' => [ $line( 'Über die Oberfläche', '12,50' ) ] ] );
$made = $status === 200 ? Documents::get( $appData, $answer['document']['id'] ) : null;
check( 'a draft is saved through it: the fields arrive as the panel gets them (a price as text, the client as a string), and the answer is the document', $status === 200 && $made !== null && $made['client_id'] === $b['id'] && $made['date'] === $day( -3 ) && $made['lines'][0]['price_net'] === 1250 && $made['gross'] === 2500 && $answer['document']['id'] === $made['id'] );

[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/copy', [ 'id' => (string) $late1['id'] ] );
check( 'copy and remind go through it: a copy is a draft of the original, the answer is the document', $status === 200 && $answer['document']['state'] === 'draft' && $answer['document']['category'] === 'invoice' && $answer['document']['pdf_url'] === '' );
$reset();
Documents::release( $appData, $late1['id'], false );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/remind', [ 'id' => (string) $late1['id'] ] );
check( '...a reminder says how many accounts were mailed', $status === 200 && $answer['mailed'] === 2 && $answer['document']['id'] === $late1['id'] );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/sign-cancel', [ 'id' => (string) $late1['id'] ] );
check( '...and the signature actions refuse a document they do not fit: 409 crm_documents_state for a contract without request, 400 crm_documents_category for an invoice', $status === 400 && $answer['code'] === 'crm_documents_category'
	&& surfaceApi( $appData, 'crm-documents/sign-remind', [ 'id' => (string) $contractOk['id'] ] )[1]['code'] === 'crm_documents_state' );

// the activity log gets the line, with the account that did it and no address, no name and no amount
$lines = \Nino\Modules\Logs\Admin::recentLines( $appData, 12 );
$mine = array_values( array_filter( $lines, static fn( string $entry ): bool => str_contains( $entry, 'crm@example.com' ) === true ) );
check( 'the activity log has a line for what was written through the endpoint, by the account that did it: the draft saved, the copy, the reminder', count( array_filter( $mine, static fn( string $entry ): bool => str_contains( $entry, 'Added draft ' ) ) ) >= 1 && count( array_filter( $mine, static fn( string $entry ): bool => str_contains( $entry, 'Copied document '. $late1['number']. ' to a draft' ) ) ) === 1
	&& count( array_filter( $mine, static fn( string $entry ): bool => str_contains( $entry, 'Sent a payment reminder for document '. $late1['number'] ) ) ) === 1 );
check( '...a read leaves none, and no line holds an address, a name or an amount', count( array_filter( $lines, static fn( string $entry ): bool => str_contains( $entry, 'Listed' ) || str_contains( $entry, 'overview' ) ) ) === 0 && array_filter( $lines, static fn( string $entry ): bool => preg_match( '/@example\.org|Studio|Nord|Hafen|€|\d,\d\d/', $entry ) === 1 ) === [] );
check( '...the lines of the new actions name the number, or the category and the id', \Crm\Documents\Admin::log( 'crm-documents/sign-remind', [] ) !== '' && \Crm\Documents\Admin::log( 'crm-documents/overview', [] ) === '' );

// --- The one permission ----------------------------------------------------------------------------------------------

echo "\nThe permission\n";

check( 'the CRM has one permission: /_admin/crm/manage, which both panels and the surface ask for', ( new \ReflectionClass( \Crm\Crm::class ) )->getConstant( 'MANAGE_PERM' ) === '/_admin/crm/manage' && ( new \ReflectionClass( \Crm\Documents\Admin::class ) )->getConstant( 'MANAGE_PERM' ) === '/_admin/crm/manage' && ( new \ReflectionClass( \Crm\Clients\Admin::class ) )->getConstant( 'MANAGE_PERM' ) === '/_admin/crm/manage' );
check( '...the old names are gone from the code of the module (the README names them, once, to say what replaced them)', ( static function() use ( $root ): array {
	$found = [];
	$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root. '/app/Crm', \FilesystemIterator::SKIP_DOTS ) );

	foreach( $iterator as $file ) {

		if( $file->isFile() === false || str_contains( $file->getPathname(), '/tests/' ) === true || str_ends_with( $file->getPathname(), '.md' ) === true )
			continue;

		$text = (string) file_get_contents( $file->getPathname() );

		if( str_contains( $text, '/_admin/crm-clients/manage' ) === true || str_contains( $text, '/_admin/crm-documents/manage' ) === true )
			$found[] = $file->getPathname();
	}

	return $found;
} )() === [] );
$asAdmin();
check( '...the account of the workbench, which has every permission, is let in as before', surfaceServe( $appData, 'GET', '/crm' )['statusCode'] === 200 && surfaceApi( $appData, 'crm-documents/list' )[0] === 200 );

// --- The words -------------------------------------------------------------------------------------------------------

echo "\nThe words\n";

$texts = Surface::texts();
$source = (string) file_get_contents( $root. '/app/Crm/Documents/Admin/Admin.php' ). (string) file_get_contents( $root. '/app/Crm/Clients/Admin/Admin.php' );
preg_match_all( "~'(crm_(?:documents|clients)_[a-z_]+)'~", $source, $codes );
check( 'every code the panels answer with has its sentence in the dictionary, as error.<code>', array_filter( array_unique( $codes[1] ), static fn( string $code ): bool => str_ends_with( $code, '_' ) === false && ( isset( $texts['error.'. $code] ) === false || $texts['error.'. $code] === '' ) ) === [] && count( array_unique( $codes[1] ) ) > 40 );
check( '...and so has every code of the page itself', array_filter( [ 'session', 'csrf', 'action', 'post_too_large', 'offline', 'request', 'forbidden', 'workbench' ], static fn( string $code ): bool => ( $texts['error.'. $code] ?? '' ) === '' ) === [] );

$used = [];

foreach( [ 'page', '403', 'login' ] as $template ) {
	preg_match_all( '~\[\[t\.([a-z0-9._-]+)\]\]~', (string) file_get_contents( $root. '/app/Crm/Surface/templates/'. $template. '.tpl' ), $found );
	$used = array_merge( $used, $found[1] );
}

check( 'every word the templates ask for is there', array_filter( $used, static fn( string $key ): bool => isset( $texts[$key] ) === false || $texts[$key] === '' ) === [] && count( $used ) > 15 );
check( 'the surface is German with Du: no "Sie", "Ihr" or "Ihnen" among the words', array_filter( $texts, static fn( string $text ): bool => preg_match( '/\b(Sie|Ihr|Ihre|Ihren|Ihnen)\b/', $text ) === 1 ) === [] );

// --- A preview takes no number; a release without an account says so -------------------------------------------------------

echo "\nThe number and the release\n";

$year = (int) date( 'Y' );
$next = static fn(): int => (int) ( Db::rows( $appData, 'SELECT next FROM sequences WHERE category = \'invoice\' AND year = :year', [ 'year' => $year ] )[0]['next'] ?? 0 );
$previewed = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'date' => $today, 'lines' => [ $line( 'Vorschau' ) ], 'template_key' => 'rechnung' ] );
$numbered = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'date' => $today, 'lines' => [ $line( 'Nummer' ) ], 'template_key' => 'rechnung' ] );
$counter = $next();
$asCrm();
$shownTwice = [ Documents::preview( $appData, $previewed['id'] ), surfaceServe( $appData, 'GET', '/crm/preview/'. $previewed['id'] )['statusCode'] ];
check( 'a preview takes no number: the draft is shown twice, by the model and by the route, and the counter of the sequence has not moved', is_array( $shownTwice[0] ) && $shownTwice[1] === 200 && $next() === $counter );
$made = Documents::finalise( $appData, $numbered['id'] );
check( '...another draft that is finalised after it gets exactly the number that was next, and the counter moved by one', is_array( $made ) && $made['number'] === \Crm\Documents\Numbers::render( Settings::get( $appData, 'number_invoice' ), $year, $counter ) && $next() === $counter + 1 );

$bare = $final( 'invoice', $c['id'], [ 'date' => $today, 'lines' => [ $line( 'Ohne Konto' ) ], 'template_key' => 'rechnung' ] );
$withAccounts = $final( 'invoice', $a['id'], [ 'date' => $today, 'lines' => [ $line( 'Mit Konto' ) ], 'template_key' => 'rechnung' ] );
$mailsBefore = count( $mails );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/release', [ 'id' => (string) $bare['id'], 'mail' => true ] );
check( 'a release with the mail for a client with no account is a release and no mail: 200, mailed 0, mail true, released, still open, nothing sent', $status === 200 && $answer['mailed'] === 0 && $answer['mail'] === true && $answer['document']['released'] === true && $answer['document']['state'] === 'open' && count( $mails ) === $mailsBefore );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $bare['id'] ] );
[ , $other ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $withAccounts['id'] ] );
[ , $draftView ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $previewed['id'] ] );
check( '...the document comes with the accounts of its client, so that the screen knows who the mail reaches: none for this one, the active ones for an invoice of a client that has some - and a draft has none', $status === 200 && $answer['accounts'] === []
	&& array_column( $other['accounts'], 'email' ) === [ 'anna@example.org', 'carl@example.org' ] && array_column( $other['accounts'], 'active' ) === [ true, true ] && $draftView['accounts'] === [] );

// --- The script of the surface ---------------------------------------------------------------------------------------

echo "\nThe script of the surface\n";

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if( $node === '' )
	echo "  note - node is not on the path: the suite of the surface's script was not run\n";
else {
	$output = (string) shell_exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/surface-js-smoke.js' ). ' 2>&1' );
	$ran = preg_match( '/(\d+) checks, (\d+) failed/', $output, $counts ) === 1;

	check( 'surface-js-smoke.js passes ('. ( $ran ? $counts[1]. ' checks' : 'no summary' ). ')', $ran && $counts[2] === '0' && (int) $counts[1] >= 200 && str_contains( $output, 'FAIL' ) === false );
}

ninoDone( $appData );

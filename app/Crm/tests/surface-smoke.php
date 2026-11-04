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
use Crm\Positionen;
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
\Nino\Auth::insertUser( $appData, 'pdf@example.com', 'pdf password', [ '/_admin/crm/manage', '/_admin/pdf/manage' ] );
\Nino\Auth::insertUser( $appData, 'pdfonly@example.com', 'pdfonly password', [ '/_admin/pdf/manage' ] );
\Nino\Auth::insertUser( $appData, 'items@example.com', 'items password', [ '/_admin/crm/manage', '/_admin/elements/manage' ] );
\Nino\Auth::insertUser( $appData, 'itemsonly@example.com', 'itemsonly password', [ '/_admin/elements/manage' ] );
\Nino\Auth::insertUser( $appData, 'itemtitle@example.com', 'itemtitle password', [ '/_admin/crm/manage', '/_admin/elements/manage', '/_admin/elements/positionen/update/title' ] );
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
	&& str_contains( $policy, "connect-src 'self'" ) === true && str_contains( $policy, "frame-ancestors 'none'" ) === true && str_contains( $policy, 'unsafe-eval' ) === false && str_contains( $policy, 'nonce' ) === false
	&& str_contains( $policy, 'unsafe-inline' ) === false && str_contains( $policy, 'blob:' ) === false && str_contains( $policy, 'frame-src' ) === false && str_contains( $policy, 'style-src-attr' ) === false );
check( '...the page has no inline script (the only script without a src is the JSON block), no style element, no style attribute and no handler attribute', preg_match_all( '~<script(?![^>]*\ssrc=)[^>]*>~', $html, $inline ) === 1 && $inline[0] === [ '<script type="application/json" id="crm-data">' ]
	&& stripos( $html, '<style' ) === false && preg_match( '~\sstyle="~', $html ) !== 1 && preg_match( '~\son[a-z]+="~i', $html ) !== 1 );
check( '...every token is filled and no word is missing: no [[ is left in the page', str_contains( $html, '[[' ) === false && str_contains( $html, '&#91;' ) === false );
check( '...the JSON block holds the token of the session, the user, the permission, the words, the links and the directory', $data['csrf'] === \Nino\Csrf::getToken( $appData ) && $data['user'] === [ 'name' => 'crm@example.com' ] && $data['perm'] === '/_admin/crm/manage' && $data['base'] === '' && $data['texts']['nav.overview'] === 'Übersicht'
	&& $data['links']['workbench'] === '/_admin/' && $data['links']['site'] === '/' );
check( '...the links lead to the pages of the workbench the surface has no screen of: the Elements panel at the type of the items, the panels PDF, Members and Users - and none to a panel Kunden or Belege, which are gone', $data['links']['items'] === '/_admin/#elements/positionen' && $data['links']['projects'] === '/_admin/#elements/projekte' && $data['links']['files'] === '/_admin/#files' && $data['links']['pdf'] === '/_admin/#pdf'
	&& $data['links']['members'] === '/_admin/#members' && $data['links']['users'] === '/_admin/#users' && array_diff( array_keys( $data['links'] ), [ 'css', 'site', 'workbench', 'items', 'projects', 'files', 'pdf', 'members', 'users' ] ) === [] && str_contains( $html, 'crm-clients' ) === false && str_contains( $html, 'crm-documents' ) === false );
check( '...the rail has seven entries and each is a screen of the surface: Kunden, Projekte, Positionen, PDF-Vorlagen and Einstellungen are hashes with their own mark, the count of the clients has its place, nothing leaves the page but the two links of the foot', preg_match_all( '~<nav class="nav".*?</nav>~s', $html, $rail ) === 1
	&& preg_match_all( '~<a href="([^"]*)"(?: data-r="([a-z]+)")?~', $rail[0][0], $entries, PREG_SET_ORDER ) === 7 && array_map( static fn( array $entry ): string => $entry[1]. '|'. ( $entry[2] ?? '' ), $entries ) === [ '#uebersicht|uebersicht', '#belege|belege', '#kunden|kunden', '#projekte|projekte', '#positionen|positionen', '#pdf|pdf', '#einstellungen|einstellungen' ]
	&& str_contains( $rail[0][0], 'id="n-kunden"' ) === true && str_contains( $rail[0][0], 'id="n-belege"' ) === true );
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

check( '...a long value or a wide field of a card does not widen the page: the values of a list break anywhere and the children of the grids may be narrower than their content (768 px, the settings)', str_contains( $css, '.kv dd { overflow-wrap: anywhere }' ) === true && str_contains( $css, '.cols > *, .cols-3 > * { min-width: 0 }' ) === true );

// --- The model: documents to look at ---------------------------------------------------------------------------------

echo "\nThe documents\n";

$blocksOf = static fn( array $extra ): array => array_merge( [ [ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1><p>[[/customer/company]]</p>' ], [ 'type' => 'slot', 'name' => 'lines' ] ], $extra );
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
check( '...in the words of a reminder: the subject, the due day, the link to the page Belege, no attachment, nothing left unfilled', $sent[0]['subject'] === 'Zahlungserinnerung: Rechnung: '. $late1['number'] && str_contains( $sent[0]['body'], 'Eine Rechnung ist noch offen' ) === true
	&& str_contains( $sent[0]['body'], 'Fällig war sie am '. \Crm\Documents\Render::day( $day( -46 ) ). ':' ) === true && str_contains( $sent[0]['body'], 'href="https://example.org/club/portal/belege"' ) === true
	&& str_contains( $sent[0]['body'], '>Rechnung ansehen</a>' ) === true && str_contains( $sent[0]['body'], '[[' ) === false && str_contains( $sent[0]['body'], '.pdf' ) === false && str_contains( $sent[0]['body'], 'Hallo Anna Nord,' ) === true );
check( '...it changes nothing of the invoice: not its state, not its release, not its dates', Documents::get( $appData, $late1['id'] ) === array_merge( $reminded['document'], [] ) && $reminded['document']['state'] === 'open' && $reminded['document']['released'] === true );
$releaseMail = \Crm\Documents\Mail::compose( $appData, (array) Documents::get( $appData, $late1['id'] ) );
check( 'the release mail is still the release mail: its words are not the reminder\'s, and its button has its words - a [[button]] in the template was read as the shortcode and left the text "[]"', str_contains( (string) $releaseMail[0]['body'], 'Ein Dokument liegt für Dich bereit' ) === true
	&& str_contains( (string) $releaseMail[0]['body'], '>Belege ansehen</a>' ) === true && str_contains( (string) $remindedBody, '>Rechnung ansehen</a>' ) === true && str_contains( (string) $releaseMail[0]['body'], '[]' ) === false );

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
	&& ninoCall( $appData, [ \Crm\Documents\Admin::class, 'apiList' ], [], 'crm-documents/list' )[0] === 403 && ninoCall( $appData, [ \Crm\Clients\Admin::class, 'apiList' ], [], 'crm-clients/list' )[0] === 403 );
$asAdmin();
$stillThere = array_filter( [ 'crm-documents/list', 'crm-clients/list', 'crm-clients/setup', 'crm-clients/status', 'crm-documents/overview' ], static fn( string $action ): bool => ninoCall( $appData, [ \Nino\Admin\Admin::class, 'handlePost' ], [], $action )[0] !== 404 );
check( '...and the workbench does not have the actions of the old panels any more: they are no panel\'s, so POST /_admin answers 404 for them, to the account that has every permission as well', $stillThere === [] );

$asCrm();
$unknown = [ 'an action nobody has' => 'crm-documents/nope', 'no action' => '', 'an action of the Pdf panel that it has not' => 'pdf/nope', 'an action of the Elements panel that it has not' => 'elements/types', 'another one, for the pictures of a type' => 'elements/uploadimage', 'one of the workbench' => 'users/list', 'a path' => '../crm-documents/list', 'the name of a method' => 'apiList',
	'the setup before it moved' => 'crm-clients/setup', 'the status before it moved' => 'crm-clients/status', 'an action of the panel CRM that it has not' => 'crm/nope' ];

foreach( $unknown as $why => $action ) {
	[ $status, $answer ] = surfaceApi( $appData, $action );
	check( 'the table does not hold '. $why. ': 404 with the code action', $status === 404 && $answer['code'] === 'action' );
}

foreach( [ 'not json', '[]', '{"action":["crm-documents/list"]}', '{"action":1}' ] as $broken ) {
	$answer = surfaceServe( $appData, 'POST', '/crm/api', \Nino\Csrf::getToken( $appData ), $broken );
	check( 'a body that is no action ('. $broken. ') is 404 action as well', $answer['statusCode'] === 404 && $answer['body']['code'] === 'action' );
}

check( 'the table is the API of the clients and of the documents by their names, the two actions of the panel CRM, the actions of the Pdf panel, the context of the screens PDF-Vorlagen the two reads of the projects, the four actions of the Elements panel that edit the Positionen and the Projekte, the two of the Text panel that edit the company\'s texts and the six of the Files panel that keep the files of a project - not its delete of a folder and not its upload mark - and none else', array_keys( Surface::actions() ) === array_merge( array_keys( \Crm\Clients\Admin::actions() ), array_keys( \Crm\Documents\Admin::actions() ), [ 'crm/status', 'crm/setup' ], [ 'crm-projekte/list', 'crm-projekte/get' ], array_keys( \Nino\Modules\Pdf\Admin::actions() ), [ 'crm-pdf/context' ], [ 'elements/list', 'elements/get', 'elements/save', 'elements/delete' ], [ 'text/keys', 'text/savebatch' ], [ 'files/list', 'files/folder/add', 'files/folder/save', 'files/upload', 'files/save', 'files/delete' ] )
	&& array_filter( array_keys( Surface::actions() ), static fn( string $action ): bool => preg_match( '~^(crm-(clients|documents|projekte)/[a-z-]+|crm/(status|setup)|pdf/[a-z/]+|crm-pdf/context|elements/(list|get|save|delete)|text/(keys|savebatch)|files/(list|folder/add|folder/save|upload|save|delete))$~D', $action ) !== 1 ) === []
	&& in_array( 'crm-clients/setup', array_keys( Surface::actions() ), true ) === false && in_array( 'crm-clients/status', array_keys( Surface::actions() ), true ) === false );

[ $status, $direct ] = ninoCall( $appData, [ \Crm\Documents\Admin::class, 'apiList' ], [ 'year' => (string) date( 'Y' ) ], 'crm-documents/list' );
[ $viaStatus, $via ] = surfaceApi( $appData, 'crm-documents/list', [ 'year' => (string) date( 'Y' ) ] );
check( 'a list through the endpoint equals the answer of the panel, to the byte of its JSON', $viaStatus === $status && json_encode( $via ) === json_encode( $direct ) && count( $via['documents'] ) > 10 );
[ $viaStatus, $via ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $late1['id'] ] );
[ $status, $direct ] = ninoCall( $appData, [ \Crm\Documents\Admin::class, 'apiGet' ], [ 'id' => (string) $late1['id'] ], 'crm-documents/get' );
check( '...so does a document, with its templates, clients and accounts - and the overview the screens start from has the books, what needs the owner and the latest documents', json_encode( $via ) === json_encode( $direct ) && $viaStatus === 200 && ( $overview = surfaceApi( $appData, 'crm-documents/overview' )[1] )
	&& array_keys( $overview ) === [ 'summary', 'needs', 'recent', 'settings' ] && $overview['summary'] === Documents::summary( $appData ) + [ 'clients' => \Crm\Clients::countActive( $appData ) ] && $overview['summary']['clients'] === 3 && count( $overview['needs'] ) === count( Documents::needs( $appData ) ) && count( $overview['recent'] ) === 8 && $overview['settings']['currency'] === '€' );
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
$source = (string) file_get_contents( $root. '/app/Crm/Documents/Admin/Admin.php' ). (string) file_get_contents( $root. '/app/Crm/Clients/Admin/Admin.php' ). (string) file_get_contents( $root. '/app/Crm/Crm/Admin/Admin.php' );
preg_match_all( "~'(crm_(?:documents|clients|setup)_[a-z_]+)'~", $source, $codes );
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

// --- The one panel, and what the screens of the second part ask for --------------------------------------------------------

echo "\nThe one panel and the actions of the screens\n";

$registry = \Nino\Admin\Admin::panels( $appData );
check( 'the workbench has one panel of the module, CRM: in the content group, with the permission of the CRM, the link in the rail named by a word of its own - and no panel Kunden and no panel Belege', isset( $registry['crm'] ) === true && $registry['crm']['group'] === 'content' && $registry['crm']['perm'] === '/_admin/crm/manage' && ltrim( (string) $registry['crm']['class'], '\\' ) === \Crm\Crm\Admin::class
	&& isset( $registry['crm-clients'] ) === false && isset( $registry['crm-documents'] ) === false && array_keys( array_filter( $registry, static fn( array $entry ): bool => str_starts_with( ltrim( (string) $entry['class'], '\\' ), 'Crm\\' ) ) ) === [ 'crm' ] );
check( '...adminPanels() names it and nothing else, and its actions are the status and the setup', \Crm\Crm::adminPanels( $appData ) === [ \Crm\Crm\Admin::class ] && array_keys( \Crm\Crm\Admin::actions() ) === [ 'crm/status', 'crm/setup' ] && \Crm\Crm\Admin::nav()[0] === 'crm' );
check( '...the files of the panels Kunden and Belege are gone: no script, no style, no template of a panel - and the classes of the API have no panel in them, only the sentences of their codes are left of their words', array_filter( [ 'Clients/Admin/assets', 'Clients/Admin/templates', 'Documents/Admin/assets', 'Documents/Admin/templates', 'tests/crm-js-smoke.js', 'tests/documents-js-smoke.js' ], static fn( string $path ): bool => file_exists( $root. '/app/Crm/'. $path ) === true ) === []
	&& is_file( $root. '/app/Crm/Crm/Admin/assets/admin.js' ) === true && is_file( $root. '/app/Crm/Crm/Admin/assets/admin.css' ) === true && is_file( $root. '/app/Crm/Crm/Admin/templates/panel.tpl' ) === true && is_file( $root. '/app/Crm/Crm/Admin/text/de_DE.php' ) === true && is_file( $root. '/app/Crm/Crm/Admin/text/en_US.php' ) === true );
check( '...no word of the workbench is left for a panel that is not there: the texts of Kunden and Belege hold the sentences of their codes and nothing else', array_filter( [ 'Clients', 'Documents' ], static fn( string $dir ): bool => array_filter( array_keys( (array) require $root. '/app/Crm/'. $dir. '/Admin/text/de_DE.php' ), static fn( string $key ): bool => str_starts_with( $key, '[[/_admin/error/crm_' ) === false ) !== [] ) === [] );
$texts = Surface::texts();
check( 'the surface has the sentences of the status and of the setup from the panel CRM as status.<key> and setup.done, and the code of the setup as an error', ( $texts['status.allok'] ?? '' ) !== '' && ( $texts['status.portal-partial'] ?? '' ) !== '' && ( $texts['status.lacks-type-positionen'] ?? '' ) === 'der Elementtyp Positionen' && ( $texts['status.lacks-type-projekte'] ?? '' ) === 'der Elementtyp Projekte' && substr_count( $texts['setup.done'] ?? '', '%s' ) === 8 && ( $texts['setup.home-new'] ?? '' ) === 'Die Startseite ist jetzt das Tor des Portals.' && ( $texts['setup.home-starter'] ?? '' ) === 'Die Startseite ist jetzt das Tor des Portals; die Seite /home des Assistenten ist nicht mehr verlinkt.' && ( $texts['setup.home-own'] ?? '' ) === 'Deine Startseite bleibt; das Tor des Portals liegt unter /portal.' && ( $texts['status.home-own'] ?? '' ) === 'Startseite: eigene Seite, nicht das Tor.' && ( $texts['status.home-other'] ?? '' ) !== '' && ( $texts['status.pdf-templates-none'] ?? '' ) !== '' && ( $texts['error.crm_setup_feature'] ?? '' ) !== '' && ( $texts['error.crm_setup_pdf'] ?? '' ) !== '' && isset( $texts['error.crm_setup_members'] ) === false
	&& str_contains( (string) ( $texts['status.module-off'] ?? '' ), '\\Crm\\Crm' ) === true );

// status and setup through the endpoint
$asVisitor();
check( 'the actions of the panel CRM are the endpoint\'s too: 401 for a visitor, 403 for an account with no permission and for one with the permissions of before', surfaceApi( $appData, 'crm/status' )[0] === 401 && surfaceApi( $appData, 'crm/setup' )[0] === 401 && ( static function() use ( $asUser, &$appData ): bool {
	$asUser( 'plain@example.com', 'plain password' );
	$plain = [ surfaceApi( $appData, 'crm/status' )[0], surfaceApi( $appData, 'crm/setup' )[0] ];
	$asUser( 'old@example.com', 'old password' );
	return $plain === [ 403, 403 ] && surfaceApi( $appData, 'crm/status' )[0] === 403 && surfaceApi( $appData, 'crm/setup' )[0] === 403;
} )() === true );
$asCrm();
$routesBefore = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
[ $status, $statusAnswer ] = surfaceApi( $appData, 'crm/status' );
check( 'status through the endpoint is the answer of the panel: the module, the data directory, the six features, the portal, the PDF templates and the start page - which the surface says as sentences, and not whole yet', $status === 200 && array_column( $statusAnswer['items'], 'key' ) === [ 'module', 'data', 'members', 'files', 'review', 'sign', 'pdf', 'events', 'portal', 'pdf-templates', 'home' ] && array_column( $statusAnswer['items'], 'state', 'key' )['portal'] !== 'ok' && array_column( $statusAnswer['items'], 'state', 'key' )['members'] === 'ok' && $statusAnswer['whole'] === false );
$settingsView = surfaceApi( $appData, 'crm-documents/settings-get' )[1];
check( 'the portal as the settings tell it - read, written nowhere: the address of its start page from the address of the site, the pages the unit brings with the ones this site has no route for marked missing, the settings of Members with their German labels and the cap of mails of the kernel where the kernel has one', $settingsView['portal']['address'] === 'https://example.org/club/portal'
	&& array_column( $settingsView['portal']['pages'], 'uri' ) === [ '/portal', '/portal/meine-daten', '/portal/projekte', '/portal/upload', '/portal/belege', '/portal/termine', '/portal/datenschutz' ] && array_unique( array_column( $settingsView['portal']['pages'], 'present' ) ) === [ false ]
	&& in_array( 'Geschützte Pfade', array_column( $settingsView['portal']['members'], 'label' ), true ) === true && array_column( $settingsView['portal']['members'], 'unit', 'key' )['linkMinutes'] === 'minutes' && array_column( $settingsView['portal']['members'], 'unit', 'key' )['inviteDays'] === 'days' && array_column( $settingsView['portal']['members'], 'unit', 'key' )['paths'] === '' && array_column( $settingsView['portal']['members'], 'value', 'key' )['start'] === '/' && $settingsView['portal']['mail_cap'] === ( isset( $appData['/nino/mail/cap'] ) === true ? (int) $appData['/nino/mail/cap'] : null ) );
$appData['/nino/mail/cap'] = 50;
check( '...the cap is the key of config.php, read as it stands', surfaceApi( $appData, 'crm-documents/settings-get' )[1]['portal']['mail_cap'] === 50 );
unset( $appData['/nino/mail/cap'] );
$linesBefore = count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) );
[ $status, $setupAnswer ] = surfaceApi( $appData, 'crm/setup' );
$portalAfter = surfaceApi( $appData, 'crm-documents/settings-get' )[1]['portal'];
check( 'setup through the endpoint applies the unit: the routes, the templates and the element type are added and the answer says so; the status says the portal is ready, and the settings that all seven pages are there', $status === 200 && count( $setupAnswer['added']['routes'] ) === 7 && $setupAnswer['added']['types'] === [ 'positionen.php', 'projekte.php' ] && array_column( surfaceApi( $appData, 'crm/status' )[1]['items'], 'state', 'key' )['portal'] === 'ok'
	&& array_unique( array_column( $portalAfter['pages'], 'present' ) ) === [ true ] && \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) !== $routesBefore );
$setupLines = array_values( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 20 ), static fn( string $entry ): bool => str_contains( $entry, 'Set the CRM up' ) === true ) );
check( '...and the activity log has its line, by the account that did it, and the status a read leaves none', count( $setupLines ) === 1 && str_contains( $setupLines[0], 'crm@example.com' ) === true && count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) ) === $linesBefore + 1 );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/positionen' );
check( 'the items through the endpoint: the type is there now, empty - the locales of the site, the native one, the settings the prices are written by', $status === 200 && $answer['missing'] === false && $answer['items'] === [] && $answer['locales'] === [ 'de_DE', 'en_US' ] && $answer['native'] === 'de_DE' && $answer['settings']['currency'] === '€' );

// what the client sees in the portal
\Nino\Features::activate( $appData, 'review' );
$folder = \Nino\Modules\Files\Store::addFolder( $appData, $a['group'], 'Nordwind Album' );
$file = static function( string $name, string $mime, bool $visible, array $by = [ 'kind' => 'owner', 'id' => '' ] ) use ( &$appData, $folder ): string {
	$id = bin2hex( random_bytes( 6 ) );
	\Nino\Modules\Files\Store::addFile( $appData, $id, [ 'folder' => $folder['id'], 'name' => $name, 'ext' => substr( $name, -3 ), 'size' => 100, 'mime' => $mime, 'sha256' => str_repeat( 'a', 64 ), 'by' => $by, 'visible' => $visible, 'created' => '2026-10-01T10:00:00Z' ], 0 );
	return $id;
};
$mix = $file( 'mix1.mp3', 'audio/mpeg', true );
$hiddenMix = $file( 'mix2.mp3', 'audio/mpeg', false );
$cover = $file( 'cover.pdf', 'application/pdf', true );
$own = $file( 'own.mp3', 'audio/mpeg', false, [ 'kind' => 'member', 'id' => $accounts['anna']['id'] ] );
$foreign = $file( 'foreign.mp3', 'audio/mpeg', false, [ 'kind' => 'member', 'id' => $accounts['ben']['id'] ] );
\Nino\Modules\Review::add( $appData, $mix, [ 'kind' => 'owner', 'id' => '' ], 12.5, 'Mehr Bass' );
$first = \Nino\Modules\Review::add( $appData, $mix, [ 'kind' => 'owner', 'id' => '' ], null, 'Gut so' );
\Nino\Modules\Review::add( $appData, $mix, [ 'kind' => 'owner', 'id' => '' ], 40.0, 'Danke', is_array( $first ) === true ? $first['id'] : '' );
\Nino\Modules\Review::add( $appData, $hiddenMix, [ 'kind' => 'owner', 'id' => '' ], 1.0, 'Unsichtbar' );
Documents::release( $appData, $late2['id'], false );
$reset();
[ $status, $portal ] = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] );
check( 'the portal of a client through the endpoint: the documents released to it as the portal lists them, the folders of the client\'s group with the files it may see - the hidden one is not counted -, and what Review holds for the files that can be commented on, answers and all', $status === 200 && array_keys( $portal ) === [ 'documents', 'features', 'folders', 'review', 'records' ]
	&& array_column( $portal['documents'], 'id' ) === array_column( Documents::released( $appData, $a['id'] ), 'id' ) && in_array( $late1['id'], array_column( $portal['documents'], 'id' ), true ) === true && $portal['features'] === [ 'files' => true, 'review' => true ]
	&& $portal['folders'] === [ [ 'id' => $folder['id'], 'name' => 'Nordwind Album', 'upload' => false, 'files' => 3 ] ] && $portal['review'] === [ 'media' => 2, 'comments' => 3 ] );
$asVisitor();
$direct = [ ninoCall( $appData, [ \Crm\Clients\Admin::class, 'apiPortal' ], [ 'id' => (string) $a['id'] ], 'crm-clients/portal' ) ];
$asUser( 'plain@example.com', 'plain password' );
$direct[] = ninoCall( $appData, [ \Crm\Clients\Admin::class, 'apiPortal' ], [ 'id' => (string) $a['id'] ], 'crm-clients/portal' );
$asUser( 'old@example.com', 'old password' );
$direct[] = ninoCall( $appData, [ \Crm\Clients\Admin::class, 'apiPortal' ], [ 'id' => (string) $a['id'] ], 'crm-clients/portal' );
$asCrm();
check( '...the method asks for the permission itself, whatever reaches it: 401 session for nobody, 403 for an account with no permission and for one with the two of before - and nothing is read', array_map( static fn( array $answer ): string => $answer[0]. ( $answer[1]['code'] ?? '' ), $direct ) === [ '401session', '403', '403' ] && array_filter( $direct, static fn( array $answer ): bool => isset( $answer[1]['documents'] ) === true ) === [] );
[ $status, $other ] = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $b['id'] ] );
check( '...another client has none of it: another group, another folders, and documents of its own', $status === 200 && $other['folders'] === [] && $other['review'] === [ 'media' => 0, 'comments' => 0 ] && in_array( $late1['id'], array_column( $other['documents'], 'id' ), true ) === false );
\Nino\Features::deactivate( $appData, 'review' );
$noReview = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] )[1];
\Nino\Features::deactivate( $appData, 'files' );
$noFiles = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] )[1];
check( '...with Review off nothing is counted of it, with Files off there is no folder to list, and the answer says which are on', $noReview['features'] === [ 'files' => true, 'review' => false ] && $noReview['review'] === [ 'media' => 0, 'comments' => 0 ] && count( $noReview['folders'] ) === 1
	&& $noFiles['features'] === [ 'files' => false, 'review' => false ] && $noFiles['folders'] === [] && array_column( $noFiles['documents'], 'id' ) === array_column( $portal['documents'], 'id' ) );
\Nino\Features::activate( $appData, 'files' );
check( '...a bad id and a client nobody has are refused as everywhere: 400 crm_clients_id, 404 crm_clients_unknown, and an account with no permission is 403', surfaceApi( $appData, 'crm-clients/portal', [ 'id' => 'x' ] )[0] === 400 && surfaceApi( $appData, 'crm-clients/portal', [ 'id' => '999' ] )[1]['code'] === 'crm_clients_unknown' && ( static function() use ( $asUser, &$appData, $a ): bool {
	$asUser( 'plain@example.com', 'plain password' );
	$status = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] )[0];
	$asUser( 'crm@example.com', 'crm password' );
	return $status === 403;
} )() === true );

// the rail's count: the overview answers the active clients, once
[ , $overviewNow ] = surfaceApi( $appData, 'crm-documents/overview' );
check( 'the overview answers the count of the active clients in its summary - the rail has it without a second call: four clients, one of them off', $overviewNow['summary']['clients'] === 3 && $overviewNow['summary']['clients'] === \Crm\Clients::countActive( $appData ) );
\Crm\Clients::setActive( $appData, $c['id'], false );
check( '...and it follows a client that is switched off', surfaceApi( $appData, 'crm-documents/overview' )[1]['summary']['clients'] === 2 );
\Crm\Clients::setActive( $appData, $c['id'], true );

// --- Datenschutz and Auftrag: what /crm shows of the records ------------------------------------------------------------------

echo "\nDatenschutz and Auftrag\n";

check( 'the settings answer the text of the Datenschutz and the day it changed, both empty before the owner wrote one', surfaceApi( $appData, 'crm-documents/settings-get' )[1]['settings']['privacy_text'] === '' && surfaceApi( $appData, 'crm-documents/settings-get' )[1]['settings']['privacy_changed'] === '' );
$privacy = "Erste Zeile\nZweite Zeile";
[ $status, $saved ] = surfaceApi( $appData, 'crm-documents/settings-save', [ 'privacy_text' => $privacy, 'privacy_changed' => '2001-01-01' ] );
check( 'the block saves the text through settings-save: the text is kept with its breaks and the day is set by the module - a day in the post is ignored', $status === 200 && $saved['settings']['privacy_text'] === $privacy && $saved['settings']['privacy_changed'] === date( 'Y-m-d' ) );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/settings-save', [ 'privacy_text' => "a\x07" ] );
check( '...a text with a control character is refused at its field: 400 crm_documents_setting, field privacy_text, and the text is as it was', $status === 400 && $answer['code'] === 'crm_documents_setting' && $answer['field'] === 'privacy_text' && \Crm\Settings::get( $appData, 'privacy_text' ) === $privacy );
[ $status, $answer ] = surfaceApi( $appData, 'crm-documents/settings-save', [ 'currency' => '€' ] );
check( '...a save that does not carry the text leaves it alone', $status === 200 && \Crm\Settings::get( $appData, 'privacy_text' ) === $privacy );
[ , $client ] = surfaceApi( $appData, 'crm-clients/get', [ 'id' => (string) $a['id'] ] );
check( 'crm-clients/get and crm-clients/list carry the standing with the Datenschutz: asked, not agreed', $client['client']['privacy'] === [ 'asked' => true, 'agreed' => false, 'at' => '', 'member_name' => '' ] && array_column( surfaceApi( $appData, 'crm-clients/list' )[1]['clients'], 'privacy', 'id' )[$a['id']] === $client['client']['privacy'] );
$agreedRecord = \Crm\Records::add( $appData, [ 'kind' => 'privacy', 'client_id' => $a['id'], 'member' => [ 'id' => 'aaaaaaaaaaaa', 'email' => 'anna@studio-nord.example', 'name' => 'Anna Nord' ], 'shown' => $privacy, 'ip' => '203.0.113.5' ] );
[ , $client ] = surfaceApi( $appData, 'crm-clients/get', [ 'id' => (string) $a['id'] ] );
check( '...and once a record holds the hash of the text: agreed, with the time and the name', $client['client']['privacy'] === [ 'asked' => true, 'agreed' => true, 'at' => $agreedRecord['at'], 'member_name' => 'Anna Nord' ] );
[ , $portalOfA ] = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] );
check( 'the Portal tab of the client lists its records, the newest first, with the shown text and the hash', array_column( $portalOfA['records'], 'id' ) === [ $agreedRecord['id'] ] && $portalOfA['records'][0]['shown'] === $privacy && $portalOfA['records'][0]['sha256'] === hash( 'sha256', $privacy ) && $portalOfA['records'][0]['ip'] === '203.0.113.5' );

$ordered = $final( 'offer', $a['id'], [ 'date' => $day( -1 ), 'valid_until' => $day( 20 ), 'lines' => [ $line( 'Beauftragt' ) ], 'template_key' => 'angebot' ] );
$ownerAccepted = $final( 'offer', $a['id'], [ 'date' => $day( -1 ), 'valid_until' => $day( 20 ), 'lines' => [ $line( 'Vom Inhaber' ) ], 'template_key' => 'angebot' ] );
$olderOrdered = $final( 'offer', $a['id'], [ 'date' => $day( -40 ), 'valid_until' => $day( -10 ), 'lines' => [ $line( 'Vor langer Zeit' ) ], 'template_key' => 'angebot' ] );
\Crm\Documents::release( $appData, $ordered['id'], false );
$orderResult = \Crm\Documents::order( $appData, $ordered['id'], $a['id'], [ 'id' => 'aaaaaaaaaaaa', 'email' => 'anna@studio-nord.example', 'name' => 'Anna Nord' ], 'Ich beauftrage.', [ 'ip' => '203.0.113.5', 'agent' => 'Test' ] );
\Crm\Documents::setState( $appData, $ownerAccepted['id'], 'accepted' );
\Crm\Documents::setState( $appData, $olderOrdered['id'], 'accepted' );
\Crm\Db::exec( $appData, 'INSERT INTO records ( kind, client_id, member_id, document_id, sha256, shown, at ) VALUES ( \'order\', :c, \'x\', :d, \'h\', \'s\', :at )', [ 'c' => $a['id'], 'd' => $olderOrdered['id'], 'at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 20 * 86400 ) ] );
[ $status, $view ] = surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $ordered['id'] ] );
check( 'crm-documents/get carries the order of an offer that was ordered in the portal: the record with the account, the address, the hash and the JSON that was shown', is_array( $orderResult ) && $status === 200 && $view['order']['id'] === $orderResult['record']['id'] && $view['order']['member_name'] === 'Anna Nord' && $view['order']['ip'] === '203.0.113.5' && $view['order']['sha256'] === hash( 'sha256', $view['order']['shown'] ) && json_decode( $view['order']['shown'], true )['number'] === $ordered['number'] && $view['document']['state'] === 'accepted' );
[ , $portalAfter ] = surfaceApi( $appData, 'crm-clients/portal', [ 'id' => (string) $a['id'] ] );
check( '...the records of the client name the document of an order by its number, and a record of the Datenschutz has none', array_column( $portalAfter['records'], 'document_number', 'kind' )['order'] === $ordered['number'] && array_column( $portalAfter['records'], 'document_number', 'kind' )['privacy'] === '' );
check( '...an offer the owner accepted has none, and neither has a draft', surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $ownerAccepted['id'] ] )[1]['order'] === null && surfaceApi( $appData, 'crm-documents/get', [ 'id' => (string) $previewed['id'] ] )[1]['order'] === null );
$needs = surfaceApi( $appData, 'crm-documents/overview' )[1]['needs'];
$orders = array_values( array_filter( $needs, static fn( array $need ): bool => $need['kind'] === 'orders' ) );
check( 'the overview has one line for the orders of the last 14 days that no invoice has followed: how many and the sum - not the order from twenty days ago, not the offer the owner accepted', count( $orders ) === 1 && $orders[0]['count'] === 1 && $orders[0]['gross'] === $ordered['gross'] && $orders[0]['id'] === 0 );
check( '...it comes after the invoices that are overdue and before the rest', array_search( 'orders', array_column( $needs, 'kind' ), true ) === count( array_filter( $needs, static fn( array $need ): bool => $need['kind'] === 'overdue' ) ) );
\Crm\Documents::fromOffer( $appData, $ordered['id'] );
check( '...an invoice made of the offer takes it off the list', array_filter( surfaceApi( $appData, 'crm-documents/overview' )[1]['needs'], static fn( array $need ): bool => $need['kind'] === 'orders' ) === [] );
\Crm\Settings::set( $appData, 'privacy_text', '' );

// --- PDF-Vorlagen: the actions of the Pdf panel through the endpoint, the context, the picture, the rail -------------------

echo "\nPDF-Vorlagen\n";

$asPdf = static function() use ( $asUser ): void { $asUser( 'pdf@example.com', 'pdf password' ); };

$asVisitor();
check( 'the Pdf panel\'s actions are in the table by their names, and the context of the screens: 401 for nobody', surfaceApi( $appData, 'pdf/templates/list' )[0] === 401 && surfaceApi( $appData, 'crm-pdf/context' )[0] === 401 );
$asCrm();
check( '...403 for an account with the permission of the CRM only - the panel\'s own guard holds, the table does not widen it - for the lists, a save and the context', surfaceApi( $appData, 'pdf/templates/list' )[0] === 403 && surfaceApi( $appData, 'pdf/blocks/list' )[0] === 403
	&& surfaceApi( $appData, 'pdf/layout/get' )[0] === 403 && surfaceApi( $appData, 'pdf/templates/save', [ 'key' => 'nope', 'isNew' => true, 'name' => 'Nope', 'tag' => 'invoice', 'blocks' => [] ] )[0] === 403 && surfaceApi( $appData, 'crm-pdf/context' )[0] === 403
	&& \Nino\Modules\Pdf::template( $appData, 'nope' ) === null );
$asUser( 'pdfonly@example.com', 'pdfonly password' );
check( '...and 403 for an account with the permission of the Pdf panel only: the endpoint asks for the CRM\'s first, as for every action', surfaceApi( $appData, 'pdf/templates/list' )[0] === 403 );
$asPdf();
[ $status, $list ] = surfaceApi( $appData, 'pdf/templates/list' );
check( '...200 with both: the answer is the panel\'s - the templates with their blocks counted, the tags, the library, the paper', $status === 200 && array_column( $list['templates'], 'key' ) === array_keys( \Nino\Modules\Pdf::templates( $appData ) ) && count( $list['templates'] ) === count( \Nino\Modules\Pdf::keys( $appData ) )
	&& $list['paper'] == [ 210, 297 ] && is_array( $list['tags'] ) === true && array_keys( $list ) === array_keys( ninoCall( $appData, [ \Nino\Modules\Pdf\Admin::class, 'apiTemplatesList' ], [], 'pdf/templates/list' )[1] ) );

$perms = static function( string $mail, string $password ) use ( $asUser, &$appData ): array {
	$asUser( $mail, $password );
	return surfaceData( (string) surfaceServe( $appData, 'GET', '/crm' )['body'] )['perms'] ?? [];
};
check( 'the data block carries which permissions the account has: crm, pdf, elements, text and files - the account of the CRM alone has the first only, the account with both has both', $perms( 'crm@example.com', 'crm password' ) === [ 'crm' => true, 'pdf' => false, 'elements' => false, 'text' => false, 'files' => false ] && $perms( 'pdf@example.com', 'pdf password' ) === [ 'crm' => true, 'pdf' => true, 'elements' => false, 'text' => false, 'files' => false ] && $perms( 'admin@example.com', 'correct horse battery staple' )['pdf'] === true );
$asPdf();
$html = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
check( '...the rail has the entry PDF-Vorlagen at #pdf, hidden in the markup (the script shows it for the permission), between Positionen and Einstellungen', preg_match( '~<a href="#pdf" data-r="pdf" id="nav-pdf" hidden>PDF-Vorlagen</a>~', $html ) === 1
	&& strpos( $html, 'data-r="positionen"' ) < strpos( $html, 'data-r="pdf"' ) && strpos( $html, 'data-r="pdf"' ) < strpos( $html, 'data-r="einstellungen"' ) );
$data = surfaceData( $html );
check( '...and whether the feature is there is a flag of its own, so that a screen can tell a missing feature from a missing permission', $data['pdf_available'] === true && $data['perms']['pdf'] === true );
check( '...the words of the Pdf panel\'s codes are the page\'s as error.<code>: the sentences of a block, a signature box, the design with its line, an image', array_filter( [ 'pdf_block_html', 'pdf_signature_box', 'pdf_design_value', 'pdf_design_brace', 'pdf_key_taken', 'pdf_block_used', 'pdf_image_type', 'pdf_tag', 'pdf_title' ], static fn( string $code ): bool => ( $data['texts']['error.'. $code] ?? '' ) === '' ) === []
	&& str_contains( $data['texts']['error.pdf_design_value'], 'Zeile %s' ) === true && isset( $data['texts']['error.upload_missing'], $data['texts']['error.image_type'], $data['texts']['error.pdf_off'], $data['texts']['error.crm_pdf_template_used'] ) === true );

// What a person does: a template from scratch through the endpoint, with the blocks the screens make
$scratch = [ 'key' => 'neu-vorlage', 'isNew' => true, 'name' => 'Neue Vorlage', 'tag' => 'invoice', 'blocks' => [
	[ 'type' => 'html', 'html' => '<p>Steuernummer [[/site/tax]]</p>' ], [ 'type' => 'html', 'block' => 'zahlung' ], [ 'type' => 'input', 'name' => 'hinweis', 'label' => 'Hinweis', 'multiline' => true, 'required' => false ],
	[ 'type' => 'signature', 'name' => 'inhaber', 'party' => 'owner', 'label' => 'Inhaber', 'page' => 'last', 'x' => 20, 'y' => 230.5, 'w' => 70, 'h' => 20, 'line' => true ] ] ];
[ $status, $answer ] = surfaceApi( $appData, 'pdf/blocks/save', [ 'key' => 'zahlung', 'isNew' => true, 'title' => 'Zahlung', 'html' => '<p>Zahlbar sofort.</p>' ] );
check( 'a block is saved through the endpoint as the panel saves it, and the answer is the library; the same key again is 400 pdf_key_taken on the field key', $status === 200 && in_array( 'zahlung', array_column( $answer['blocks'], 'key' ), true ) === true
	&& ( $again = surfaceApi( $appData, 'pdf/blocks/save', [ 'key' => 'zahlung', 'isNew' => true, 'title' => 'Zahlung', 'html' => '<p>x</p>' ] ) )[0] === 400 && $again[1]['code'] === 'pdf_key_taken' && $again[1]['field'] === 'key' );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/save', $scratch );
$stored = \Nino\Modules\Pdf::template( $appData, 'neu-vorlage' );
check( '...a template from scratch with text, a block by reference, an input and a signature field in mm is saved: the answer has its key and the list, the store holds the blocks as they were posted', $status === 200 && $answer['key'] === 'neu-vorlage' && $stored !== null && $stored['blocks'] == $scratch['blocks'] && $stored['name'] === 'Neue Vorlage' && $stored['tag'] === 'invoice' );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/save', [ 'blocks' => [ [ 'type' => 'signature', 'name' => 'xx', 'party' => 'owner', 'label' => '', 'page' => 'last', 'x' => 200, 'y' => 10, 'w' => 70, 'h' => 20, 'line' => false ] ] ] + $scratch );
check( '...a signature box off the page is 400 pdf_signature_box on the field blocks with the number of the block in its params - the refusal the screen puts beside the card', $status === 400 && $answer['code'] === 'pdf_signature_box' && $answer['field'] === 'blocks' && $answer['params'] === [ 1 ]
	&& Surface::texts()['error.pdf_signature_box'] !== '' );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/save', [ 'blocks' => [ [ 'type' => 'html', 'html' => 'ok' ], [ 'type' => 'html', 'block' => 'es-gibt-ihn-nicht' ] ] ] + $scratch );
check( '...a block by reference to a block that is not there is 400 pdf_block_ref, number 2', $status === 400 && $answer['code'] === 'pdf_block_ref' && $answer['params'] === [ 2 ] );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/preview', [ 'name' => 'Neue Vorlage', 'tag' => 'invoice', 'blocks' => $scratch['blocks'] ] );
$pdf = $status === 200 ? base64_decode( (string) $answer['pdf'], true ) : false;
check( 'the preview is the panel\'s: a PDF of what the editor holds, as base64, written nowhere - and the template that was not saved yet previews as well', $status === 200 && is_string( $pdf ) === true && str_starts_with( $pdf, '%PDF-' ) === true && $answer['file'] === 'preview.pdf' && \Nino\Modules\Pdf::template( $appData, 'preview-only' ) === null
	&& surfaceApi( $appData, 'pdf/preview', [ 'name' => '', 'tag' => 'invoice', 'blocks' => [] ] )[1]['code'] === 'pdf_title' );

// The sample PDFs are served by the surface, with the headers of a PDF
$sample = static function( string $uri ) use ( &$appData ): array { return surfaceServe( $appData, 'GET', $uri ); };
$asVisitor();
check( 'GET /crm/pdf-preview/... is guarded like the endpoint: 401 for nobody, 403 for the CRM\'s permission alone and for the Pdf panel\'s alone - with no PDF in the body', $sample( '/crm/pdf-preview/layout' )['statusCode'] === 401 && $sample( '/crm/pdf-preview/template/rechnung' )['statusCode'] === 401 );
$asCrm();
$denied = $sample( '/crm/pdf-preview/template/rechnung' );
$asUser( 'pdfonly@example.com', 'pdfonly password' );
check( '...and the one of the Pdf panel alone is refused as well', $denied['statusCode'] === 403 && str_starts_with( (string) $denied['body'], '%PDF' ) === false && $sample( '/crm/pdf-preview/layout' )['statusCode'] === 403 );
$asPdf();
$served = $sample( '/crm/pdf-preview/template/neu-vorlage' );
[ , $viaPanel ] = surfaceApi( $appData, 'pdf/preview', [ 'name' => 'Neue Vorlage', 'tag' => 'invoice', 'blocks' => \Nino\Modules\Pdf::template( $appData, 'neu-vorlage' )['blocks'] ] );
check( 'a template is served as a PDF with its own headers - inline, no cache, nosniff - and the bytes are what the panel\'s preview action answers, decoded (no particulars set: the sample is the panel\'s but for the empty tokens it hands, which show nothing)', $served['statusCode'] === 200 && str_starts_with( (string) $served['body'], '%PDF-' ) === true && $served['header']['Content-Type'] === 'application/pdf' && str_starts_with( $served['header']['Content-Disposition'], 'inline;' ) === true
	&& $served['header']['Cache-Control'] === 'private, no-store' && $served['header']['X-Content-Type-Options'] === 'nosniff' && strlen( $served['body'] ) > 1000 && abs( strlen( $served['body'] ) - strlen( (string) base64_decode( (string) $viaPanel['pdf'], true ) ) ) < 200 );
$layoutServed = $sample( '/crm/pdf-preview/layout' );
check( '...the layout and the design as the panel\'s layout preview renders them', $layoutServed['statusCode'] === 200 && $layoutServed['header']['Content-Type'] === 'application/pdf' && str_starts_with( (string) $layoutServed['body'], '%PDF-' ) === true );
$setParticulars = [ 'tax_id' => '12/345/67890', 'bank_name' => 'Testbank', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX' ];
foreach( $setParticulars as $key => $value )
	Settings::set( $appData, $key, $value );
$tokens = [ '/site/tax' => '12/345/67890', '/site/bank' => 'Testbank', '/site/iban' => 'DE89 3704 0044 0532 0130 00', '/site/bic' => 'COBADEFFXXX' ];
$stored = \Nino\Modules\Pdf::template( $appData, 'neu-vorlage' );
$withTokens = $sample( '/crm/pdf-preview/template/neu-vorlage' )['body'];
check( 'the sample looks like a document: the invoice particulars of the settings are handed to it as the tokens a document hands - the bytes are the feature\'s compose with them, and not the sample without them', $withTokens === \Nino\Modules\Pdf::compose( $appData, $stored, [ 'tokens' => $tokens ], [ 'title' => $stored['name'] ], true )
	&& $withTokens !== \Nino\Modules\Pdf::compose( $appData, $stored, [], [ 'title' => $stored['name'] ], true ) && $sample( '/crm/pdf-preview/layout' )['body'] === \Nino\Modules\Pdf::compose( $appData, [ 'name' => 'Design', 'tag' => '', 'blocks' => [] ], [ 'tokens' => $tokens ], [ 'title' => 'Design' ], true )
	&& Surface\Pdf::particulars( $appData ) === $tokens );
foreach( array_keys( $setParticulars ) as $key )
	Settings::set( $appData, $key, '' );
check( '...a key that is none, a kind that is none, a path and a template of the wrong shape are 404 without a PDF', array_filter( [ '/crm/pdf-preview/template/gibt-es-nicht', '/crm/pdf-preview/nope', '/crm/pdf-preview/', '/crm/pdf-preview/template/../layout', '/crm/pdf-preview/template/Bad_Key', '/crm/pdf-preview/layout/x' ], static fn( string $uri ): bool => $sample( $uri )['statusCode'] !== 404 || str_starts_with( (string) $sample( $uri )['body'], '%PDF' ) === true ) === [] );

// The documents that name a template: "benutzt von", the context, and what the surface refuses
$named = (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE template_key = :k', [ 'k' => 'rechnung' ] )['n'];
[ $status, $context ] = surfaceApi( $appData, 'crm-pdf/context' );
check( 'the context counts the documents that name each template - drafts, finalised and cancelled alike - from the books, and only the templates a document names', $status === 200 && $named > 3 && ( $context['used']['rechnung'] ?? 0 ) === $named
	&& ( $context['used']['angebot'] ?? 0 ) === (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE template_key = :k', [ 'k' => 'angebot' ] )['n'] && isset( $context['used']['neu-vorlage'] ) === false && isset( $context['used'][''] ) === false );
check( '...the day each template was written, the templates that use each block of the library (by name), and the keys of the site\'s texts a template may place - the company\'s, no others', preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $context['changed']['rechnung'] ) === 1 && count( $context['changed'] ) === count( \Nino\Modules\Pdf::keys( $appData ) )
	&& $context['blocks']['zahlung'] === [ 'Neue Vorlage' ] && $context['blocks']['kontakt'] === [ 'Information', 'Stornorechnung' ] && in_array( '/project/company/contact/email', $context['site'], true ) === true
	&& array_filter( $context['site'], static fn( string $path ): bool => preg_match( '~^/project/company/[a-z]+/[a-z]+$~D', $path ) !== 1 ) === [] && $context['site'] === array_values( array_unique( $context['site'] ) ) );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/delete', [ 'key' => 'rechnung' ] );
check( 'a template that a document names is not deleted: 409 crm_pdf_template_used with the count, and the template is still there', $status === 409 && $answer['code'] === 'crm_pdf_template_used' && $answer['params'] === [ $named ] && \Nino\Modules\Pdf::template( $appData, 'rechnung' ) !== null );
$linesBefore = count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/duplicate', [ 'key' => 'neu-vorlage', 'name' => 'Kopie davon' ] );
check( '...a duplicate is made with the name it is asked for, and is no document\'s: it is deleted at once, 200, and the answer is the list without it', $status === 200 && $answer['key'] === 'neu-vorlage-copy' && \Nino\Modules\Pdf::template( $appData, 'neu-vorlage-copy' )['name'] === 'Kopie davon'
	&& ( $deleted = surfaceApi( $appData, 'pdf/templates/delete', [ 'key' => 'neu-vorlage-copy' ] ) )[0] === 200 && \Nino\Modules\Pdf::template( $appData, 'neu-vorlage-copy' ) === null && in_array( 'neu-vorlage-copy', array_column( $deleted[1]['templates'], 'key' ), true ) === false );
check( '...the activity log has the lines the panel phrases for them, by the account that did it', count( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 12 ), static fn( string $entry ): bool => str_contains( $entry, 'pdf@example.com' ) && ( str_contains( $entry, 'Duplicate PDF template "neu-vorlage"' ) || str_contains( $entry, 'Delete PDF template "neu-vorlage-copy"' ) ) ) ) === 2
	&& count( \Nino\Modules\Logs\Admin::recentLines( $appData, 200 ) ) === $linesBefore + 2 );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/blocks/delete', [ 'key' => 'zahlung' ] );
check( 'a block that a template references is not deleted: the store\'s rule, 409 pdf_block_used with the names of the templates; once it is out of the template it goes', $status === 409 && $answer['code'] === 'pdf_block_used' && $answer['params'] === [ 'Neue Vorlage' ] && isset( \Nino\Modules\Pdf::blocks( $appData )['zahlung'] ) === true
	&& surfaceApi( $appData, 'pdf/templates/save', [ 'blocks' => array_slice( $scratch['blocks'], 0, 1 ) ] + [ 'isNew' => false ] + $scratch )[0] === 200 && surfaceApi( $appData, 'pdf/blocks/delete', [ 'key' => 'zahlung' ] )[0] === 200 );

// The picture: a multipart post, the action and the data as fields and the file in $_FILES - the one shape a file takes
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
$tmp = tempnam( sys_get_temp_dir(), 'crm-up' );
file_put_contents( $tmp, $png );
$upload = static function( string $action, array $data, ?array $file ) use ( &$appData ): array {
	$_POST = [ 'action' => $action, 'data' => (string) json_encode( $data ) ];
	$_FILES = $file === null ? [] : [ 'file' => $file ];
	$request = ninoVisit( $appData, '/crm/api', 'POST', [ 'HTTP_X_CSRF_TOKEN' => \Nino\Csrf::getToken( $appData ) ] );
	\Nino\Http::response( $appData, $request );
	$_POST = [];
	$_FILES = [];

	return [ $request['/nino/http/response']['statusCode'], $request['/nino/http/response']['body'] ];
};
$file = [ 'name' => 'logo.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( (string) $png ) ];
[ $status, $answer ] = $upload( 'pdf/layout/image', [ 'kind' => 'logo' ], $file );
check( 'the upload of a picture goes through the endpoint as the panel sends it: 200, the layout names logo.png and the answer shows the picture as a data address', $status === 200 && $answer['layout']['logo'] === 'logo.png' && str_starts_with( $answer['images']['logo'], 'data:image/png;base64,' ) === true && \Nino\Modules\Pdf::picture( $appData, 'logo' ) === $png );
check( '...a refusal of the picture keeps its code: a file that is no picture is 400 with the code of the kernel and the field file; no file at all is 400 upload_missing; a kind that is none, 400 pdf_image_kind', ( $bad = $upload( 'pdf/layout/image', [ 'kind' => 'logo' ], [ 'name' => 'x.txt', 'type' => 'text/plain', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10 ] ) )[0] === 400
	&& $bad[1]['field'] === 'file' && in_array( $bad[1]['code'], [ 'image_type', 'pdf_image_type' ], true ) === true && $upload( 'pdf/layout/image', [ 'kind' => 'logo' ], null )[1]['code'] === 'upload_missing' && $upload( 'pdf/layout/image', [ 'kind' => 'sign' ], $file )[1]['code'] === 'pdf_image_kind' );
check( 'no other action takes a file: the same multipart post for an action of the clients, of the documents or of the setup is 404 action, and the picture is as it was', $upload( 'crm-documents/list', [], $file )[0] === 404 && $upload( 'crm-clients/list', [], $file )[1]['code'] === 'action' && $upload( 'crm/setup', [], $file )[0] === 404
	&& $upload( 'pdf/layout/image/delete', [ 'kind' => 'logo' ], $file )[0] === 404 && \Nino\Modules\Pdf::picture( $appData, 'logo' ) === $png );
check( '...and a post that is JSON is read as JSON: the picture\'s action with a JSON body and no file says upload_missing, and a visitor with a multipart post is 401, not served', surfaceApi( $appData, 'pdf/layout/image', [ 'kind' => 'logo' ] )[1]['code'] === 'upload_missing'
	&& ( function() use ( $asVisitor, $upload, $file ): bool { $asVisitor(); return $upload( 'pdf/layout/image', [ 'kind' => 'logo' ], $file )[0] === 401; } )() === true );
$asPdf();
$huge = ninoVisit( $appData, '/crm/api', 'POST', [ 'HTTP_X_CSRF_TOKEN' => \Nino\Csrf::getToken( $appData ), 'CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '999999999999', 'HTTP_CONTENT_LENGTH' => '999999999999' ] );
$_POST = [];
$_FILES = [];
\Nino\Http::response( $appData, $huge );
check( 'a picture larger than the server takes - the token in the header, so the kernel lets it pass, and PHP has dropped the form: no field, no action - is told what it is: 413 post_too_large with the limit in megabytes, not 404 action', $huge['/nino/http/response']['statusCode'] === 413 && $huge['/nino/http/response']['body']['code'] === 'post_too_large' && $huge['/nino/http/response']['body']['params'][0] >= 1 );
$smallEmpty = ninoVisit( $appData, '/crm/api', 'POST', [ 'HTTP_X_CSRF_TOKEN' => \Nino\Csrf::getToken( $appData ), 'CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'CONTENT_LENGTH' => '120', 'HTTP_CONTENT_LENGTH' => '120' ] );
\Nino\Http::response( $appData, $smallEmpty );
check( '...a form that is empty but small is still 404 action: only the size says too large', $smallEmpty['/nino/http/response']['statusCode'] === 404 && $smallEmpty['/nino/http/response']['body']['code'] === 'action' );
[ $status, $answer ] = surfaceApi( $appData, 'pdf/layout/image/delete', [ 'kind' => 'logo' ] );
check( 'the picture is taken away by the panel\'s action: the layout names none and the file is gone', $status === 200 && $answer['layout']['logo'] === '' && $answer['images']['logo'] === '' && \Nino\Modules\Pdf::picture( $appData, 'logo' ) === '' );
unlink( $tmp );

// The design: a text that is no sheet is refused with its line; a sheet is saved and previewed
$layoutNow = surfaceApi( $appData, 'pdf/layout/get' )[1];
$post = static fn( string $design ): array => surfaceApi( $appData, 'pdf/layout/save', [ 'format' => $layoutNow['layout']['format'], 'margins' => $layoutNow['layout']['margins'], 'font' => $layoutNow['layout']['font'], 'size' => $layoutNow['layout']['size'], 'header' => '', 'footer' => '', 'design' => $design ] );
[ $status, $answer ] = $post( "h1 { font-size: 20pt }\nbanane { color: red }" );
check( 'a design that is no sheet is 400 on the field design with the code of what is wrong and the line in its params, and the screen has the sentence of the panel for it', $status === 400 && $answer['field'] === 'design' && $answer['code'] === 'pdf_design_selector' && $answer['params'][0] === 2 && str_contains( Surface::texts()['error.pdf_design_selector'], 'Zeile %s' ) === true );
[ $status, $answer ] = $post( 'h1 { font-size: 20pt; color: #1f5f8b }' );
$layoutAfter = surfaceApi( $appData, 'pdf/layout/get' )[1];
check( '...a sheet is saved, and layout/get answers the rules of the feature as text, the layout with the design and no refusal; layout/preview is a PDF', $status === 200 && $layoutAfter['layout']['design'] === 'h1 { font-size: 20pt; color: #1f5f8b }' && $layoutAfter['refusal'] === null && str_contains( $layoutAfter['sheet'], 'font-size' ) === true
	&& str_starts_with( (string) base64_decode( (string) surfaceApi( $appData, 'pdf/layout/preview' )[1]['pdf'], true ), '%PDF-' ) === true );
$post( '' );

// The feature is off: the screens say so and the panel's methods are not asked
$asAdmin();
check( 'the feature Pdf is switched off (Sign, which needs it, first)', \Nino\Features::deactivate( $appData, 'sign' ) === true && \Nino\Features::deactivate( $appData, 'pdf' ) === true );
$asPdf();
[ $status, $answer ] = surfaceApi( $appData, 'pdf/templates/list' );
$offStatus = $sample( '/crm/pdf-preview/layout' )['statusCode'];
$asUser( 'crm@example.com', 'crm password' );
$offDenied = $sample( '/crm/pdf-preview/layout' )['statusCode'];
check( '...the sample route says so as well - 409 pdf_off, not 403 - and an account without the permission is still told 403', $offStatus === 409 && $offDenied === 403 );
$asPdf();
check( '...its actions are 409 pdf_off, the context as well, and the data block says the account may not use screens that would find nothing', $status === 409 && $answer['code'] === 'pdf_off' && surfaceApi( $appData, 'crm-pdf/context' )[1]['code'] === 'pdf_off' && surfaceApi( $appData, 'crm-documents/list' )[0] === 200 );
$asAdmin();
check( '...and it is switched on again, and Sign with it', \Nino\Features::activate( $appData, 'pdf' ) === true && \Nino\Features::activate( $appData, 'sign' ) === true );
$asPdf();

// The feature Pdf absent from the project: a process whose features directory is empty (the autoloader looks there for it)
$absentScript = tempnam( sys_get_temp_dir(), 'crm-absent' );
file_put_contents( $absentScript, <<<'PHPCODE'
<?php
$root = $argv[1];
$empty = sys_get_temp_dir(). '/nofeatures-'. getmypid();
mkdir( $empty );
define( 'NINO_FEATURES_DIR', $empty );
require $root. '/tests/harness.php';
$appData = ninoSandbox( 'absent' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$appData['/nino/dir'] = '';
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template', '\\Crm\\Crm' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules' ] );
\Nino\Csrf::init( $appData );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );
ninoUser( $appData, 'admin@example.com' );
\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );
$get = static function( string $uri ) use ( &$appData ): array { $r = ninoVisit( $appData, $uri ); \Nino\Http::response( $appData, $r ); return $r['/nino/http/response']; };
$page = $get( '/crm' );
preg_match( '~id="crm-data">(.*?)</script>~s', (string) $page['body'], $f );
$data = json_decode( $f[1] ?? '{}', true );
echo json_encode( [ 'available' => \Crm\Surface\Pdf::available(), 'actions' => \Crm\Surface\Pdf::actions(), 'text' => \Crm\Surface\Pdf::textFile(), 'allowed' => \Crm\Surface\Pdf::allowed( $appData ),
	'table' => count( array_filter( array_keys( \Crm\Surface::actions() ), static fn( string $a ): bool => str_starts_with( $a, 'pdf/' ) || str_starts_with( $a, 'crm-pdf/' ) ) ),
	'flag' => $data['pdf_available'] ?? 'none', 'perm' => $data['perms']['pdf'] ?? 'none', 'route' => $get( '/crm/pdf-preview/layout' )['statusCode'] ] );
rmdir( $empty );
\Nino\Filesystem::removeDir( ninoSandboxDir( $appData ) );
PHPCODE );
$absent = (array) json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ). ' '. escapeshellarg( $absentScript ). ' '. escapeshellarg( $root ). ' 2>/dev/null' ), true );
unlink( $absentScript );
check( 'with the feature Pdf absent the surface knows it: the class check says no, the table holds none of its actions and no sentences of its codes are read, the owner still holds the permission - the data block says perms.pdf true and pdf_available false - and the preview route answers 409, not 403', ( $absent['available'] ?? null ) === false && ( $absent['actions'] ?? null ) === [] && ( $absent['text'] ?? null ) === '' && ( $absent['table'] ?? null ) === 0
	&& ( $absent['allowed'] ?? null ) === true && ( $absent['perm'] ?? null ) === true && ( $absent['flag'] ?? null ) === false && ( $absent['route'] ?? null ) === 409 );
check( '...and with the feature there the same questions answer the other way', Surface\Pdf::available() === true && Surface\Pdf::actions() !== [] && Surface\Pdf::textFile() !== '' );

// --- Positionen: the actions of the Elements panel through the endpoint, for the type Positionen alone -------------------

echo "\nPositionen\n";

$asItems = static function() use ( $asUser ): void { $asUser( 'items@example.com', 'items password' ); };
$itemsLines = static fn( string $needle ): int => count( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ), static fn( string $entry ): bool => str_contains( $entry, $needle ) === true ) );
$four = [
	'elements/list'   => [ 'type' => 'positionen' ],
	'elements/get'    => [ 'type' => 'positionen', 'uri' => 'beratung' ],
	'elements/save'   => [ 'type' => 'positionen', 'uri' => 'beratung', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Beratung', 'price' => 90 ] ],
	'elements/delete' => [ 'type' => 'positionen', 'uri' => 'beratung' ],
];

check( 'before anything the type is there and empty', Positionen::missing( $appData ) === false && Positionen::all( $appData ) === [] );

$asVisitor();
check( 'the four actions of the Elements panel are in the table by their names - and not its types, its pictures: 401 for nobody', array_filter( $four, static fn( array $data, string $action ): bool => surfaceApi( $appData, $action, $data )[0] !== 401, ARRAY_FILTER_USE_BOTH ) === [] );
$asCrm();
check( '...403 for an account with the permission of the CRM only - the panel\'s own guard holds, the table does not widen it - for the list, the read, a save and a delete; nothing is made', array_filter( $four, static fn( array $data, string $action ): bool => surfaceApi( $appData, $action, $data )[0] !== 403, ARRAY_FILTER_USE_BOTH ) === [] && Positionen::all( $appData ) === [] );
$asUser( 'itemsonly@example.com', 'itemsonly password' );
check( '...and 403 for the permission of the Elements panel alone: the endpoint asks for the CRM\'s first, as for every action', array_filter( $four, static fn( array $data, string $action ): bool => surfaceApi( $appData, $action, $data )[0] !== 403, ARRAY_FILTER_USE_BOTH ) === [] );

$asItems();
[ $status, $list ] = surfaceApi( $appData, 'elements/list', [ 'type' => 'positionen' ] );
check( '...200 with both: the list is the panel\'s, to the byte of its JSON', $status === 200 && $list === [ 'columns' => [ 'title', 'description', 'unit', 'price', 'vat', 'hidden', 'sort' ], 'elements' => [] ]
	&& json_encode( $list ) === json_encode( ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiList' ], [ 'type' => 'positionen' ], 'elements/list' )[1] ) );

// The type restriction: a post that names another type, or none, is refused before the panel is asked
\Nino\Elements::insertElementType( $appData, '/notes', [ 'title' => [ 'type' => 'string', 'locale' => true ] ] );
\Nino\Elements::insertElement( $appData, '/notes/erste', [ 'title' => 'Erste' ], 'de_DE' );
$other = [];

foreach( [ 'legal', 'notes', '', 'Positionen', '/positionen', 'positionen/x', 'positionen ' ] as $type )
	foreach( [ 'elements/list' => [], 'elements/get' => [ 'uri' => 'erste' ], 'elements/save' => [ 'uri' => 'zweite', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Zweite' ] ], 'elements/delete' => [ 'uri' => 'erste' ] ] as $action => $data ) {
		[ $status, $answer ] = surfaceApi( $appData, $action, [ 'type' => $type ] + $data );

		if( $status !== 404 || ( $answer['code'] ?? '' ) !== 'crm_positionen_type' || array_keys( $answer ) !== [ 'error', 'code' ] )
			$other[] = $type. ' '. $action;
	}

[ $status, $answer ] = surfaceApi( $appData, 'elements/get', [ 'uri' => 'erste' ] );
check( 'a type that is not positionen - another type, none, the name in another spelling - is 404 with the code crm_positionen_type for each of the four actions, and the answer holds nothing of the panel', $other === [] && $status === 404 && $answer['code'] === 'crm_positionen_type' );
check( '...and nothing was asked of the panel: the element of the other type is still there, the one it would have made is not, and the type has none of it', \Nino\Elements::getElement( $appData, '/notes/erste', 'de_DE' ) !== false && \Nino\Elements::getElement( $appData, '/notes/zweite', 'de_DE' ) === false
	&& \Nino\Elements::getElement( $appData, '/legal/zweite', 'de_DE' ) === false && Positionen::all( $appData ) === [] );
$asCrm();
check( '...the permission is asked first: the account of the CRM alone is told 403 for another type as well, not what the type may be', surfaceApi( $appData, 'elements/get', [ 'type' => 'legal', 'uri' => 'x' ] )[0] === 403 );
$asItems();

// What a person does: an item in two languages, one post per language, the native one with the numbers first
$mixing = [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Mixing', 'description' => "Mischung eines Songs\nbis 48 Spuren", 'unit' => 'Std.', 'price' => 90.5, 'vat' => 19, 'hidden' => false, 'sort' => 5 ] ];
$linesBefore = count( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ) );
[ $status, $made ] = surfaceApi( $appData, 'elements/save', $mixing );
[ $statusEn, ] = surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'en_US', 'isNew' => false, 'fields' => [ 'title' => 'Mixing', 'description' => 'Mixing of one song', 'unit' => 'h' ] ] );
$item = Positionen::get( $appData, 'mixing' );
check( 'a save through the endpoint makes the element the panel makes: 200 and the element in the answer, the second language an update; Positionen::list() answers it in cents and hundredths, and the words of the other language are there', $status === 200 && $made['element']['.uri'] === '/positionen/mixing' && $statusEn === 200
	&& $item === [ 'id' => 'mixing', 'title' => 'Mixing', 'description' => "Mischung eines Songs\nbis 48 Spuren", 'unit' => 'Std.', 'price' => 9050, 'vat' => 1900, 'active' => true, 'sort' => 5 ] && Positionen::all( $appData )[0]['words']['en_US'] === [ 'title' => 'Mixing', 'description' => 'Mixing of one song', 'unit' => 'h' ] );
$loop = static fn(): array => array_map( static fn( array $one ): string => (string) $one['.uri'], \Nino\Modules\Elements::queryLoop( $appData, [ '/positionen', 'sort' => 'sort', 'query' => 'hidden=0' ], true ) );
check( '...and the loop of the Builder, [elements /positionen sort="sort" query="hidden=0"], reads it: the same element, in the order of its sort', $loop() === [ '/positionen/mixing' ] );
[ , $second ] = [ surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'beratung', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Beratung', 'unit' => 'Tag', 'price' => 120, 'vat' => 7.5, 'hidden' => false, 'sort' => 2 ] ] ), 0 ];
check( '...a second, with a lower sort, comes first on both: the list and the loop', array_column( Positionen::list( $appData ), 'id' ) === [ 'beratung', 'mixing' ] && $loop() === [ '/positionen/beratung', '/positionen/mixing' ] && Positionen::get( $appData, 'beratung' )['vat'] === 750 );

// The switch Angeboten off and on: the field hidden
surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'title' => 'Mixing', 'description' => "Mischung eines Songs\nbis 48 Spuren", 'unit' => 'Std.', 'price' => 90.5, 'vat' => 19, 'hidden' => true, 'sort' => 5 ] ] );
check( 'the switch off is hidden true: the item is left out of the editor\'s list and of the loop, and still there when it is asked for; on again, it is back', array_column( Positionen::list( $appData ), 'id' ) === [ 'beratung' ] && $loop() === [ '/positionen/beratung' ] && Positionen::get( $appData, 'mixing' )['active'] === false
	&& array_column( Positionen::all( $appData ), 'active', 'id' ) === [ 'beratung' => true, 'mixing' => false ]
	&& surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'hidden' => false ] ] )[0] === 200 && array_column( Positionen::list( $appData ), 'id' ) === [ 'beratung', 'mixing' ] );

// The same element, byte for byte, whichever place makes it
ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'uri' => 'durch-das-panel' ] + $mixing, 'elements/save' );
surfaceApi( $appData, 'elements/save', [ 'uri' => 'durch-die-oberflaeche' ] + $mixing );
$stored = (array) \Nino\Filesystem::getFileContent( $appData, '/elements/positionen.php', [] );
check( 'an item made through the endpoint and one made by the panel\'s own call are the same in the type file: the same global bucket and the same words', ( $stored['*']['durch-das-panel'] ?? null ) === ( $stored['*']['durch-die-oberflaeche'] ?? [] ) && ( $stored['de_DE']['durch-das-panel'] ?? null ) === ( $stored['de_DE']['durch-die-oberflaeche'] ?? [] ) && ( $stored['*']['durch-das-panel'] ?? [] ) !== [] );

// A refusal keeps its code and its field; the panel's sentences are the page's
$asUser( 'itemtitle@example.com', 'itemtitle password' );
[ $status, $answer ] = surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'title' => 'Mixing', 'price' => 99 ] ] );
check( 'a refusal of the panel comes through with its code, its params and the field it names: an account that may change the title alone, saving the price, is 403 elements_fields_not_allowed on the field price', $status === 403 && $answer['code'] === 'elements_fields_not_allowed' && $answer['params'] === [ 'price' ] && $answer['field'] === 'price' && Positionen::get( $appData, 'mixing' )['price'] === 9050 );
check( '...and the same account changes the title: the scoped permission is the panel\'s', surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'mixing', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'title' => 'Mixing Pro' ] ] )[0] === 200 && Positionen::get( $appData, 'mixing' )['title'] === 'Mixing Pro' );
$asItems();
[ $status, $answer ] = surfaceApi( $appData, 'elements/save', [ 'type' => 'positionen', 'uri' => 'ohne-titel', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'price' => 1 ] ] );
check( '...a title that is missing is the panel\'s 400 with the kernel\'s message and no code: the page says it at the field before it posts', $status === 400 && str_contains( $answer['error'], 'title' ) === true && isset( $answer['code'] ) === false && Positionen::get( $appData, 'ohne-titel' ) === null );
check( '...a name the panel refuses (a space in it) and one that is taken are 400 with no code as well, and the item that was there is as it was', surfaceApi( $appData, 'elements/save', [ 'uri' => 'mit leerzeichen' ] + $mixing )[0] === 400 && ( $taken = surfaceApi( $appData, 'elements/save', $mixing ) )[0] === 400 && isset( $taken[1]['code'] ) === false && Positionen::get( $appData, 'mixing' )['title'] === 'Mixing Pro' );
$texts = Surface::texts();
check( 'the sentences are the page\'s: the code of the restriction has its own, and the codes of the Elements panel come from its text file - so does the one with a place for the fields', ( $texts['error.crm_positionen_type'] ?? '' ) !== '' && str_contains( $texts['error.elements_fields_not_allowed'] ?? '', '%s' ) === true
	&& Surface\Elements::textFile() !== '' && str_ends_with( Surface\Elements::textFile(), '/Elements/text/de_DE.php' ) === true );

// The permission in the data block
$html = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
check( 'the data block says whether the account holds the permission of the Elements panel: perms.elements is true for it and for the owner, false for the account of the CRM alone', surfaceData( $html )['perms']['elements'] === true && $perms( 'items@example.com', 'items password' )['elements'] === true && $perms( 'crm@example.com', 'crm password' )['elements'] === false
	&& $perms( 'admin@example.com', 'correct horse battery staple' )['elements'] === true );
$asItems();

// The activity log: the panel's lines, by the account that did it, none for a read
$added = $itemsLines( 'Add Element /positionen/mixing' );
$edited = $itemsLines( 'Edit Element /positionen/mixing' );
surfaceApi( $appData, 'elements/list', [ 'type' => 'positionen' ] );
$readsBefore = count( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ) );
surfaceApi( $appData, 'elements/list', [ 'type' => 'positionen' ] );
surfaceApi( $appData, 'elements/get', [ 'type' => 'positionen', 'uri' => 'mixing' ] );
check( 'the activity log has the panel\'s lines for the saves: Add Element once for the first language, Edit Element for the others - by the account that did it - and none for a list or a read', $added === 1 && $edited >= 2 && count( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ), static fn( string $entry ): bool => str_contains( $entry, 'Add Element /positionen/mixing' ) && str_contains( $entry, 'items@example.com' ) ) ) === 1
	&& count( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ) ) === $readsBefore && $linesBefore < $readsBefore );

// Reading one item: the panel's answer, the numbers once and the words of each language
[ $status, $got ] = surfaceApi( $appData, 'elements/get', [ 'type' => 'positionen', 'uri' => 'mixing' ] );
check( 'elements/get answers the numbers once and the words of each language of the site, as the panel does; an item that is none is 404', $status === 200 && $got['global'] === [ 'price' => 90.5, 'vat' => 19.0, 'hidden' => false, 'sort' => 5 ] && array_keys( $got['locales'] ) === [ 'de_DE', 'en_US' ] && $got['locales']['en_US']['unit'] === 'h'
	&& surfaceApi( $appData, 'elements/get', [ 'type' => 'positionen', 'uri' => 'gibt-es-nicht' ] )[0] === 404 );

// An item made elsewhere with no order: all() says none (null), list() has 0 for the order of the lines and the editor of documents
\Nino\Elements::insertElement( $appData, '/positionen/ohne-ordnung', [ 'price' => 1, 'title' => 'Ohne Ordnung' ], 'de_DE' );
$noOrder = array_values( array_filter( Positionen::all( $appData ), static fn( array $one ): bool => $one['id'] === 'ohne-ordnung' ) );
check( 'an item with no order is told apart from one whose order is 0: all() has null for the first, the numbers for the others, and list() has 0 for it', count( $noOrder ) === 1 && $noOrder[0]['sort'] === null && array_unique( array_map( 'gettype', array_column( array_filter( Positionen::all( $appData ), static fn( array $one ): bool => $one['id'] !== 'ohne-ordnung' ), 'sort' ) ) ) === [ 'integer' ] && Positionen::get( $appData, 'ohne-ordnung' )['sort'] === 0 );
\Nino\Elements::deleteElement( $appData, '/positionen/ohne-ordnung', '*' );

// Delete: the item goes, the words a line copied stay
$copy = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'date' => $day( -1 ), 'lines' => [ [ 'title' => 'Mixing Pro', 'description' => "Mischung eines Songs\nbis 48 Spuren", 'unit' => 'Std.', 'qty' => '2', 'price' => '90,5', 'vat_rate' => 0 ] ], 'template_key' => 'rechnung' ] );
$lineBefore = Documents::get( $appData, $copy['id'] )['lines'][0];
[ $status, ] = surfaceApi( $appData, 'elements/delete', [ 'type' => 'positionen', 'uri' => 'mixing' ] );
$lineAfter = Documents::get( $appData, $copy['id'] )['lines'][0];
check( 'a delete through the endpoint removes the element in every language, and Positionen::list(), the loop and all() have nothing of it; the line of a document that copied its words keeps them', $status === 200 && Positionen::get( $appData, 'mixing' ) === null && in_array( 'mixing', array_column( Positionen::all( $appData ), 'id' ), true ) === false
	&& in_array( '/positionen/mixing', $loop(), true ) === false && \Nino\Elements::getElement( $appData, '/positionen/mixing', 'en_US' ) === false && $lineAfter['title'] === 'Mixing Pro' && $lineAfter['description'] === "Mischung eines Songs\nbis 48 Spuren" && $lineAfter === $lineBefore
	&& $itemsLines( 'Delete Element /positionen/mixing' ) === 1 );
$asCrm();

// --- Projekte: the Elements panel's actions for the type, the reads of the projects, the Files panel's actions for their folders -------------------

echo "\nProjekte\n";

\Nino\Auth::insertUser( $appData, 'files@example.com', 'files password', [ '/_admin/crm/manage', '/_admin/files/manage' ] );
\Nino\Auth::insertUser( $appData, 'filesonly@example.com', 'filesonly password', [ '/_admin/files/manage' ] );
\Nino\Auth::insertUser( $appData, 'projects@example.com', 'projects password', [ '/_admin/crm/manage', '/_admin/elements/manage', '/_admin/files/manage' ] );
$asFiles = static function() use ( $asUser ): void { $asUser( 'files@example.com', 'files password' ); };
$asProjects = static function() use ( $asUser ): void { $asUser( 'projects@example.com', 'projects password' ); };
$lines = static fn( string $needle ): int => count( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 600 ), static fn( string $entry ): bool => str_contains( $entry, $needle ) === true ) );
$group = \Crm\Clients::group( $a['number'] );
$accountA = \Nino\Modules\Members::add( $appData, 'anna@studio-nord.example', 'Anna Nord', $group );

check( 'the type of the projects is there, empty, and the client has an account in the group of its number - which is what makes the group one the Files panel knows', \Crm\Projekte::missing( $appData ) === false && \Crm\Projekte::list( $appData ) === [] && is_array( $accountA ) && in_array( $group, \Nino\Modules\Members::groups( $appData ), true ) === true && $group === strtolower( $a['number'] ) );

// --- the Elements panel's actions, for the type projekte
$project = [ 'type' => 'projekte', 'uri' => '', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Album 2027', 'client' => $a['number'], 'description' => "Aufnahme\nund Mix", 'started' => '2026-10-01' ] ];
$asVisitor();
check( 'the actions of the Elements panel take the type projekte as they take positionen: 401 for nobody', surfaceApi( $appData, 'elements/save', $project )[0] === 401 && surfaceApi( $appData, 'elements/delete', [ 'type' => 'projekte', 'uri' => '00001' ] )[0] === 401 );
$asCrm();
check( '...403 for the account of the CRM alone - the panel\'s guard comes first', surfaceApi( $appData, 'elements/save', $project )[0] === 403 && surfaceApi( $appData, 'elements/list', [ 'type' => 'projekte' ] )[0] === 403 && \Crm\Projekte::list( $appData ) === [] );
$asItems();

[ $status, $made ] = surfaceApi( $appData, 'elements/save', $project );
check( 'a save without a uri makes the next numbered element, which is the panel\'s own way for a type that numbers: 200 and the uri in the element; Projekte::get() reads it', $status === 200 && $made['element']['.uri'] === '/projekte/00001' && $made['nextUri'] === '00002'
	&& \Crm\Projekte::get( $appData, '00001' ) === [ 'id' => '00001', 'title' => 'Album 2027', 'client' => $a['number'], 'description' => "Aufnahme\nund Mix", 'started' => '2026-10-01', 'done' => false, 'folder' => '' ] );
[ $status, ] = surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'done' => true ] ] + $project );
check( '...a change names the number: done is the switch, the other fields stay; the folder is a field of its own', $status === 200 && \Crm\Projekte::get( $appData, '00001' )['done'] === true && \Crm\Projekte::get( $appData, '00001' )['title'] === 'Album 2027'
	&& surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'done' => false, 'folder' => 'aaaaaaaaaaaa' ] ] + $project )[0] === 200 && \Crm\Projekte::get( $appData, '00001' )['folder'] === 'aaaaaaaaaaaa' && \Crm\Projekte::get( $appData, '00001' )['done'] === false );
check( 'the list of the panel answers the project; elements/get answers its fields once, for all languages', surfaceApi( $appData, 'elements/list', [ 'type' => 'projekte' ] )[1]['elements'][0]['uri'] === '00001' && ( $got = surfaceApi( $appData, 'elements/get', [ 'type' => 'projekte', 'uri' => '00001' ] ) )[0] === 200
	&& $got[1]['global']['client'] === $a['number'] && $got[1]['global']['folder'] === 'aaaaaaaaaaaa' );
surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'folder' => '' ] ] + $project );
$other = [];

foreach( [ 'legal', 'notes', '', 'Projekte', '/projekte', 'projekte/x', 'projekte ' ] as $type )
	foreach( [ 'elements/list' => [], 'elements/get' => [ 'uri' => '00001' ], 'elements/save' => [ 'uri' => '', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Fremd', 'client' => $a['number'] ] ], 'elements/delete' => [ 'uri' => '00001' ] ] as $action => $data ) {
		[ $status, $answer ] = surfaceApi( $appData, $action, [ 'type' => $type ] + $data );

		if( $status !== 404 || ( $answer['code'] ?? '' ) !== 'crm_positionen_type' )
			$other[] = $type. ' '. $action;
	}

check( 'a third type - or the name of the second in another spelling - is still 404 crm_positionen_type for each of the four actions, and nothing was made or removed', $other === [] && array_column( \Crm\Projekte::list( $appData ), 'id' ) === [ '00001' ] && \Nino\Elements::getElement( $appData, '/notes/erste', 'de_DE' ) !== false );

// A client the project names is a client; and it is the client it was while a document names the project
[ $status, $answer ] = surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'Ohne Kunden', 'client' => 'K-9999' ] ] + $project );
check( 'the client of a new project must be a number a client has: 400 crm_project_client_unknown at the field client - nothing is made; no number at all is the same', $status === 400 && $answer['code'] === 'crm_project_client_unknown' && $answer['field'] === 'client' && array_column( \Crm\Projekte::list( $appData ), 'id' ) === [ '00001' ]
	&& surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'x', 'client' => '' ] ] + $project )[1]['code'] === 'crm_project_client_unknown' && surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'x', 'client' => [ 1 ] ] ] + $project )[1]['code'] === 'crm_project_client_unknown' );
check( '...a save that names no client (a new title, the switch) is not asked about it', surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'title' => 'Album 2027 neu' ] ] + $project )[0] === 200 && \Crm\Projekte::get( $appData, '00001' )['title'] === 'Album 2027 neu' );
$carried = (array) Documents::add( $appData, 'invoice', $a['id'], [ 'project' => '00001', 'lines' => [ $line( 'Aufnahme' ) ], 'template_key' => 'rechnung' ] );
check( 'a document names the project', ( $carried['project'] ?? '' ) === '00001' && \Crm\Projekte::documentsOf( $appData, '00001' ) === 1 );
[ $status, $answer ] = surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'client' => $b['number'] ] ] + $project );
check( '...and the client of the project does not change then: 409 crm_project_client_locked with the count, at the field client; the same client is no change', $status === 409 && $answer['code'] === 'crm_project_client_locked' && $answer['params'] === [ 1 ] && $answer['field'] === 'client' && \Crm\Projekte::get( $appData, '00001' )['client'] === $a['number']
	&& surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'client' => $a['number'] ] ] + $project )[0] === 200 );
[ $status, $answer ] = surfaceApi( $appData, 'elements/delete', [ 'type' => 'projekte', 'uri' => '00001' ] );
check( 'a project that a document names is not deleted: 409 crm_project_documents with the count - the element is there, and nothing was asked of the panel', $status === 409 && $answer['code'] === 'crm_project_documents' && $answer['params'] === [ 1 ] && array_keys( $answer ) === [ 'error', 'code', 'params' ] && \Crm\Projekte::get( $appData, '00001' ) !== null && $lines( 'Delete Element /projekte/00001' ) === 0 );
check( '...a client without a document of its own may change on a project that has none', surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'Zweites', 'client' => $b['number'] ] ] + $project )[0] === 200 && surfaceApi( $appData, 'elements/save', [ 'uri' => '00002', 'isNew' => false, 'fields' => [ 'client' => $a['number'] ] ] + $project )[0] === 200 && \Crm\Projekte::get( $appData, '00002' )['client'] === $a['number'] );
// The same three rules in the workbench's own panel: the kernel's vetoes, not the endpoint's check
[ $status, ] = ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiDelete' ], [ 'type' => 'projekte', 'uri' => '00001' ], 'elements/delete' );
check( 'the Elements panel of the workbench does not delete a project that a document names either: not 200, the project is there', $status !== 200 && $status !== 403 && \Crm\Projekte::get( $appData, '00001' ) !== null );
[ $statusMove, ] = ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'type' => 'projekte', 'uri' => '00001', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'client' => $b['number'] ] ], 'elements/save' );
[ $statusNone, ] = ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'type' => 'projekte', 'uri' => '00002', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'client' => 'K-9999' ] ], 'elements/save' );
[ $statusNew, ] = ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'type' => 'projekte', 'uri' => '', 'locale' => 'de_DE', 'isNew' => true, 'fields' => [ 'title' => 'Fremd', 'client' => 'K-9999' ] ], 'elements/save' );
check( '...nor changes its client while one does (not 200, the client is the old one), nor takes a client nobody has - for a change or for a new project; nothing is made', $statusMove !== 200 && $statusMove !== 403 && \Crm\Projekte::get( $appData, '00001' )['client'] === $a['number']
	&& $statusNone !== 200 && $statusNone !== 403 && \Crm\Projekte::get( $appData, '00002' )['client'] === $a['number'] && $statusNew !== 200 && $statusNew !== 403 && array_column( \Crm\Projekte::list( $appData ), 'title' ) === [ 'Zweites', 'Album 2027 neu' ] );
check( '...what the rules allow it still does: a change of the title, the same client again, a new project of a client that is there, and the client of a project that no document names', ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'type' => 'projekte', 'uri' => '00001', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'title' => 'Album 2027 neu', 'client' => $a['number'] ] ], 'elements/save' )[0] === 200
	&& ninoCall( $appData, [ \Nino\Modules\Elements\Admin::class, 'apiSave' ], [ 'type' => 'projekte', 'uri' => '00002', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'client' => $b['number'] ] ], 'elements/save' )[0] === 200 && \Crm\Projekte::get( $appData, '00002' )['client'] === $b['number'] );
surfaceApi( $appData, 'elements/save', [ 'type' => 'projekte', 'uri' => '00002', 'locale' => 'de_DE', 'isNew' => false, 'fields' => [ 'client' => $a['number'] ] ] );
Documents::setProject( $appData, $carried['id'], '' );
[ $status, ] = surfaceApi( $appData, 'elements/delete', [ 'type' => 'projekte', 'uri' => '00002' ] );
check( '...and with no document on it a project is deleted by the panel\'s own action: 200, gone, the log has the panel\'s line', $status === 200 && \Crm\Projekte::get( $appData, '00002' ) === null && $lines( 'Delete Element /projekte/00002' ) === 1 && $lines( 'Add Element /projekte/' ) >= 1 );
Documents::setProject( $appData, $carried['id'], '00001' );
$texts = Surface::texts();
check( 'the sentences of the refusals are the page\'s, each says what it counts', array_filter( [ 'crm_project_documents', 'crm_project_client_locked' ], static fn( string $code ): bool => str_contains( $texts['error.'. $code] ?? '', '%s' ) === false ) === [] && ( $texts['error.crm_project_client_unknown'] ?? '' ) !== '' && ( $texts['error.crm_project'] ?? '' ) !== '' && ( $texts['error.crm_project_client'] ?? '' ) !== '' );

// --- the reads of the projects
echo "\nThe reads of the projects\n";

$asVisitor();
check( 'crm-projekte/list and crm-projekte/get are in the table: 401 for nobody, and 403 for an account without the CRM\'s permission', surfaceApi( $appData, 'crm-projekte/list' )[0] === 401 && surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[0] === 401 );
$asUser( 'plain@example.com', 'plain password' );
check( '...403 for the account without the permission of the CRM', surfaceApi( $appData, 'crm-projekte/list' )[0] === 403 && surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[0] === 403 );
$asCrm();
[ $status, $list ] = surfaceApi( $appData, 'crm-projekte/list' );
check( 'the list answers for the permission of the CRM alone: whether the type is missing, the language the screens save in, whether Files is on, and the project with its client, the number of its documents and of its offers that wait', $status === 200 && $list['missing'] === false && $list['native'] === 'de_DE' && $list['files'] === true && count( $list['projects'] ) === 1
	&& array_intersect_key( $list['projects'][0], array_flip( [ 'id', 'title', 'client', 'client_id', 'client_title', 'documents', 'open', 'done', 'folder', 'files' ] ) ) === [ 'id' => '00001', 'title' => 'Album 2027 neu', 'client' => $a['number'], 'done' => false, 'folder' => '', 'documents' => 1, 'open' => 0, 'client_id' => $a['id'], 'client_title' => 'Studio Nord GmbH', 'files' => 0 ] );
$offer = $final( 'offer', $a['id'], [ 'lines' => [ $line( 'Mix' ) ], 'template_key' => 'angebot', 'project' => '00001' ] );
$decided = $final( 'offer', $a['id'], [ 'lines' => [ $line( 'Master' ) ], 'template_key' => 'angebot', 'project' => '00001' ] );
Documents::setState( $appData, $decided['id'], 'accepted' );
check( '...the offers that wait are the ones open or sent - an accepted one is a document and no waiting offer', surfaceApi( $appData, 'crm-projekte/list' )[1]['projects'][0]['documents'] === 3 && surfaceApi( $appData, 'crm-projekte/list' )[1]['projects'][0]['open'] === 1 );
$asItems();
surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'Podcast', 'client' => $b['number'] ] ] + $project );
surfaceApi( $appData, 'elements/save', [ 'fields' => [ 'title' => 'Fertig', 'client' => $a['number'], 'done' => true ] ] + $project );
$asCrm();
check( '...the filters: a client\'s projects by its id, the open ones, a search in the title - a client nobody has has none, a filter that is no id is 400', array_column( surfaceApi( $appData, 'crm-projekte/list', [ 'client' => (string) $b['id'] ] )[1]['projects'], 'title' ) === [ 'Podcast' ]
	&& array_column( surfaceApi( $appData, 'crm-projekte/list', [ 'open' => true ] )[1]['projects'], 'title' ) === [ 'Podcast', 'Album 2027 neu' ] && array_column( surfaceApi( $appData, 'crm-projekte/list', [ 'q' => 'fert' ] )[1]['projects'], 'title' ) === [ 'Fertig' ]
	&& surfaceApi( $appData, 'crm-projekte/list', [ 'client' => '99999' ] )[1]['projects'] === [] && surfaceApi( $appData, 'crm-projekte/list', [ 'client' => 'x' ] )[0] === 400 && array_column( surfaceApi( $appData, 'crm-projekte/list' )[1]['projects'], 'id' ) === [ '00004', '00003', '00001' ] );
[ $status, $got ] = surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] );
check( 'get answers one project the same way, with its client, the language, whether Files is on, no folder and no list of files where it has no folder', $status === 200 && $got['project']['id'] === '00001' && $got['project']['documents'] === 3 && $got['project']['open'] === 1 && $got['client'] === [ 'id' => $a['id'], 'number' => $a['number'], 'title' => 'Studio Nord GmbH', 'active' => true ]
	&& $got['native'] === 'de_DE' && $got['files'] === true && $got['folder'] === null && $got['list'] === [] && isset( $got['project']['file_list'] ) === false );
check( '...a project nobody has is 404 crm_projekte_unknown at the field id; an id that is no id as well', ( $r = surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00099' ] ) )[0] === 404 && $r[1]['code'] === 'crm_projekte_unknown' && $r[1]['field'] === 'id' && surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '../x' ] )[1]['code'] === 'crm_projekte_unknown' );

// --- the Files panel's actions, for the folders of the clients
echo "\nDateien\n";

$four = [ 'files/list' => [ 'group' => $group ], 'files/folder/add' => [ 'group' => $group, 'name' => 'Album' ], 'files/folder/save' => [ 'id' => 'aaaaaaaaaaaa', 'name' => 'x' ], 'files/upload' => [ 'folder' => 'aaaaaaaaaaaa', 'name' => 'a.txt', 'size' => 1, 'index' => 0, 'count' => 1 ], 'files/save' => [ 'id' => 'aaaaaaaaaaaa', 'visible' => true ], 'files/delete' => [ 'id' => 'aaaaaaaaaaaa' ] ];
$asVisitor();
check( 'the six actions of the Files panel are in the table by their names: 401 for nobody', array_filter( $four, static fn( array $data, string $action ): bool => surfaceApi( $appData, $action, $data )[0] !== 401, ARRAY_FILTER_USE_BOTH ) === [] );
$asCrm();
$denied = static function() use ( &$appData, $four ): array {
	return array_filter( $four, static function( array $data, string $action ) use ( &$appData ): bool { return surfaceApi( $appData, $action, $data )[0] !== 403; }, ARRAY_FILTER_USE_BOTH );
};
$crmOnly = $denied();
$asUser( 'filesonly@example.com', 'filesonly password' );
check( '...403 for the account of the CRM alone - the panel\'s guard holds - and for the permission of the Files panel alone: the endpoint asks for the CRM\'s first', $crmOnly === [] && $denied() === [] );
$asFiles();
check( '...a folder of the panel\'s other actions, the delete of a folder and the mark of the upload folder, is not offered: 404 action, with the permission, and the table does not hold them', surfaceApi( $appData, 'files/folder/delete', [ 'id' => 'aaaaaaaaaaaa' ] )[0] === 404 && surfaceApi( $appData, 'files/folder/delete', [ 'id' => 'aaaaaaaaaaaa' ] )[1]['code'] === 'action'
	&& surfaceApi( $appData, 'files/folder/upload-target', [ 'id' => 'aaaaaaaaaaaa', 'on' => true ] )[1]['code'] === 'action' && array_diff( array_keys( Surface::actions() ), array_keys( \Nino\Modules\Files\Admin::actions() ) ) !== array_keys( Surface::actions() )
	&& array_intersect( [ 'files/folder/delete', 'files/folder/upload-target' ], array_keys( Surface::actions() ) ) === [] );

$foldersBefore = array_keys( \Nino\Modules\Files\Store::read( $appData )['folders'] );
[ $status, $folder ] = surfaceApi( $appData, 'files/folder/add', [ 'group' => $group, 'name' => 'Album 2027 neu' ] );
check( 'files/folder/add in the group of a client makes the folder the panel makes: 200, the folder with its id, in the group', $status === 200 && preg_match( '/^[a-f0-9]{12}$/D', $folder['folder']['id'] ) === 1 && $folder['folder']['group'] === $group && $folder['folder']['name'] === 'Album 2027 neu'
	&& in_array( $folder['folder']['id'], array_column( \Nino\Modules\Files::folders( $appData, $group ), 'id' ), true ) === true );
$folderId = $folder['folder']['id'];
$stranger = \Nino\Modules\Files\Store::addFolder( $appData, 'allgemein', 'Fremd' );
$nobody = [];

foreach( [ 'allgemein', 'k-9999', 'K-0001', 'k-1', 'k-00011', 'k-0001x', '', 'k-0001 ', ' k-0001', $group. '/x', 'x' ] as $bad )
	foreach( [ 'files/list' => [ 'group' => $bad ], 'files/folder/add' => [ 'group' => $bad, 'name' => 'Eindringling' ] ] as $action => $data ) {
		[ $status, $answer ] = surfaceApi( $appData, $action, $data );

		if( $status !== 404 || ( $answer['code'] ?? '' ) !== 'crm_files_group' || array_keys( $answer ) !== [ 'error', 'code' ] )
			$nobody[] = $bad. ' '. $action;
	}

check( 'a group that is no client\'s - another group of Members, a number nobody has, a spelling of the group that is none - is 404 crm_files_group for the list and the add, and the answer holds nothing of the panel', $nobody === [] );
check( '...nothing was made: no folder in any of those groups, the folder of the stranger is as it was', \Nino\Modules\Files::folders( $appData, 'allgemein' ) === [ $stranger ] && \Nino\Modules\Files::folders( $appData, 'k-9999' ) === [] && array_keys( \Nino\Modules\Files\Store::read( $appData )['folders'] ) === array_merge( $foldersBefore, [ $folderId, $stranger['id'] ] ) );
check( '...the client without an account (a group the panel does not know yet) is a client\'s group all the same: the add is the panel\'s own refusal, 400 files_group, and the list shows nothing of any other group', ( $r = surfaceApi( $appData, 'files/folder/add', [ 'group' => \Crm\Clients::group( $c['number'] ), 'name' => 'Leer' ] ) )[0] === 400 && $r[1]['code'] === 'files_group'
	&& ( $l = surfaceApi( $appData, 'files/list', [ 'group' => \Crm\Clients::group( $c['number'] ) ] ) )[0] === 200 && $l[1]['group'] === \Crm\Clients::group( $c['number'] ) && $l[1]['folders'] === [] && $l[1]['files'] === [] );
[ $status, $l ] = surfaceApi( $appData, 'files/list', [ 'group' => $group ] );
check( '...the list of a client\'s group is the panel\'s: its folders, its files, the limits of the uploads (the chunk and the largest file) and the types', $status === 200 && $l['group'] === $group && in_array( $folderId, array_column( $l['folders'], 'id' ), true ) === true && array_column( $l['folders'], 'id' ) === array_column( \Nino\Modules\Files::folders( $appData, $group ), 'id' ) && $l['limits']['chunk'] > 0 && $l['limits']['max'] > 0 && in_array( 'txt', $l['types'], true ) === true );

[ $status, $renamed ] = surfaceApi( $appData, 'files/folder/save', [ 'id' => $folderId, 'name' => 'Album 2027' ] );
check( 'files/folder/save renames a folder of a client\'s group; a folder of another group is 404 crm_files_group and keeps its name, an id that is none the same', $status === 200 && $renamed['folder']['name'] === 'Album 2027' && ( $r = surfaceApi( $appData, 'files/folder/save', [ 'id' => $stranger['id'], 'name' => 'Umbenannt' ] ) )[0] === 404 && $r[1]['code'] === 'crm_files_group'
	&& \Nino\Modules\Files\Store::folder( $appData, $stranger['id'] )['name'] === 'Fremd' && surfaceApi( $appData, 'files/folder/save', [ 'id' => 'zzzz', 'name' => 'x' ] )[1]['code'] === 'crm_files_group' && surfaceApi( $appData, 'files/folder/save', [ 'id' => $folderId, 'name' => '' ] )[1]['code'] === 'files_name' );

// The upload: a multipart post, the action and the data as fields and the bytes as the part chunk - in two chunks
\Nino\Features::saveSettings( $appData, 'files', [ 'chunk' => 1 ] );
$bytes = str_repeat( "Zeile eines Textes\n", 60000 ). 'Ende';
$total = strlen( $bytes );
$chunkSize = \Nino\Modules\Files\Store::chunkBytes( $appData );
$pieces = str_split( $bytes, $chunkSize );
$sendChunk = static function( array $data, ?string $piece ) use ( &$appData ): array {
	$tmp = tempnam( sys_get_temp_dir(), 'crm-chunk' );
	file_put_contents( $tmp, (string) $piece );
	$_POST = [ 'action' => 'files/upload', 'data' => (string) json_encode( $data ) ];
	$_FILES = $piece === null ? [] : [ 'chunk' => [ 'name' => 'blob', 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen( $piece ) ] ];
	$request = ninoVisit( $appData, '/crm/api', 'POST', [ 'HTTP_X_CSRF_TOKEN' => \Nino\Csrf::getToken( $appData ) ] );
	\Nino\Http::response( $appData, $request );
	$_POST = [];
	$_FILES = [];
	@unlink( $tmp );

	return [ $request['/nino/http/response']['statusCode'], $request['/nino/http/response']['body'] ];
};
$meta = [ 'folder' => $folderId, 'name' => 'Notizen.txt', 'size' => $total, 'count' => count( $pieces ), 'visible' => true ];
[ $firstStatus, $firstAnswer ] = $sendChunk( $meta + [ 'upload' => '', 'index' => 0 ], $pieces[0] );
check( 'the upload goes through the endpoint as the panel\'s script sends it: the first chunk is 200 with the id of the upload and its index', count( $pieces ) === 2 && $firstStatus === 200 && preg_match( '/^[a-f0-9]{16}$/D', (string) ( $firstAnswer['upload'] ?? '' ) ) === 1 && $firstAnswer['index'] === 0 && \Nino\Modules\Files::files( $appData, $folderId ) === [] );
[ $lastStatus, $lastAnswer ] = $sendChunk( $meta + [ 'upload' => $firstAnswer['upload'], 'index' => 1 ], $pieces[1] );
$stored = \Nino\Modules\Files::files( $appData, $folderId );
check( '...the last chunk puts the file in the folder of the project: 200 with its id, name and size; the index has it, visible, put there by the owner, with the bytes it was sent', $lastStatus === 200 && $lastAnswer['name'] === 'Notizen.txt' && $lastAnswer['size'] === $total && count( $stored ) === 1 && $stored[0]['id'] === $lastAnswer['id'] && $stored[0]['folder'] === $folderId && $stored[0]['visible'] === true
	&& $stored[0]['by'] === [ 'kind' => 'owner', 'id' => '' ] && file_get_contents( (string) \Nino\Modules\Files::path( $appData, $stored[0]['id'] ) ) === $bytes );
check( '...the log has the panel\'s line, once, when the last chunk is in - and not for the first', $lines( 'Uploaded file '. $lastAnswer['id'] ) === 1 && $lines( 'Added folder '. $folderId ) === 1 && $lines( 'Renamed folder '. $folderId ) === 1 );
check( '...a chunk for a folder of another group is 404 crm_files_group and stores nothing; so is a chunk that names no folder; a chunk the panel refuses keeps its code (files_type for a name it does not take)', ( $r = $sendChunk( [ 'folder' => $stranger['id'], 'name' => 'a.txt', 'size' => 3, 'count' => 1, 'index' => 0, 'upload' => '' ], 'abc' ) )[0] === 404 && $r[1]['code'] === 'crm_files_group' && \Nino\Modules\Files::files( $appData, $stranger['id'] ) === []
	&& $sendChunk( [ 'name' => 'a.txt', 'size' => 3, 'count' => 1, 'index' => 0, 'upload' => '' ], 'abc' )[1]['code'] === 'crm_files_group' && ( $r = $sendChunk( [ 'folder' => $folderId, 'name' => 'bild.exe', 'size' => 3, 'count' => 1, 'index' => 0, 'upload' => '' ], 'abc' ) )[0] === 400 && $r[1]['code'] === 'files_type' );
$asVisitor();
$seen = $sendChunk( $meta + [ 'upload' => '', 'index' => 0 ], $pieces[0] )[0];
$asCrm();
check( '...a visitor with a multipart post is 401, and the account of the CRM alone 403 - the file is not looked at', $seen === 401 && $sendChunk( $meta + [ 'upload' => '', 'index' => 0 ], $pieces[0] )[0] === 403 );
$asFiles();

// The file: visible or not, and gone
$fileId = $stored[0]['id'];
[ $status, $saved ] = surfaceApi( $appData, 'files/save', [ 'id' => $fileId, 'visible' => false ] );
check( 'files/save switches a file of the project\'s folder: 200, the file as it is now; the files of a folder of another group are not touched - 404 crm_files_group', $status === 200 && $saved['file']['visible'] === false && \Nino\Modules\Files::file( $appData, $fileId )['visible'] === false );
$strangeFile = \Nino\Modules\Files\Store::addFile( $appData, 'bbbbbbbbbbbb', [ 'folder' => $stranger['id'], 'name' => 'f.txt', 'ext' => 'txt', 'size' => 1, 'mime' => 'text/plain', 'sha256' => str_repeat( 'a', 64 ), 'by' => [ 'kind' => 'owner', 'id' => '' ], 'visible' => true, 'created' => gmdate( 'Y-m-d\TH:i:s\Z' ) ], 0 );
check( '...a file of another group is neither switched nor deleted: 404 crm_files_group for both', is_array( $strangeFile ) && ( $r = surfaceApi( $appData, 'files/save', [ 'id' => 'bbbbbbbbbbbb', 'visible' => false ] ) )[0] === 404 && $r[1]['code'] === 'crm_files_group' && surfaceApi( $appData, 'files/delete', [ 'id' => 'bbbbbbbbbbbb' ] )[1]['code'] === 'crm_files_group'
	&& \Nino\Modules\Files::file( $appData, 'bbbbbbbbbbbb' ) !== null && \Nino\Modules\Files::file( $appData, 'bbbbbbbbbbbb' )['visible'] === true );
check( '...a file nobody has is the panel\'s own 404 files_unknown, which touches nothing', ( $r = surfaceApi( $appData, 'files/delete', [ 'id' => 'cccccccccccc' ] ) )[0] === 404 && $r[1]['code'] === 'files_unknown' );

// The reads of the projects, now with a folder and a file in it
$asItems();
surfaceApi( $appData, 'elements/save', [ 'uri' => '00001', 'isNew' => false, 'fields' => [ 'folder' => $folderId ] ] + $project );
$asCrm();
[ , $list ] = surfaceApi( $appData, 'crm-projekte/list' );
check( 'with a folder the list counts the files of the project and the card lists them with the address each is downloaded at, who put it there and whether the members see it - for the account of the CRM alone', array_column( $list['projects'], 'files', 'id' )['00001'] === 1 && array_column( $list['projects'], 'files', 'id' )['00003'] === 0
	&& ( $got = surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[1] )['folder'] === [ 'id' => $folderId, 'name' => 'Album 2027' ] && count( $got['list'] ) === 1 && $got['list'][0] === [ 'id' => $fileId, 'name' => 'Notizen.txt', 'ext' => 'txt', 'size' => $total, 'mime' => 'text/plain', 'created' => $stored[0]['created'], 'visible' => false, 'by' => [ 'kind' => 'owner', 'label' => '' ], 'url' => '/.files/'. $fileId ] );
$asItems();
surfaceApi( $appData, 'elements/save', [ 'uri' => '00004', 'isNew' => false, 'fields' => [ 'folder' => 'dddddddddddd' ] ] + $project );
$asCrm();
check( '...a folder id that Files does not have (deleted there) is no folder: the card says none, and the count is 0', surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00004' ] )[1]['folder'] === null && array_column( surfaceApi( $appData, 'crm-projekte/list' )[1]['projects'], 'files', 'id' )['00004'] === 0 );

// Whether the files can be changed is told by the answer, as of now
$allowedCrm = [ surfaceApi( $appData, 'crm-projekte/list' )[1]['allowed'], surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[1]['allowed'] ];
$asFiles();
$allowedFiles = [ surfaceApi( $appData, 'crm-projekte/list' )[1]['allowed'], surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[1]['allowed'] ];
\Nino\Features::deactivate( $appData, 'files' );
$allowedOff = surfaceApi( $appData, 'crm-projekte/list' )[1]['allowed'];
\Nino\Features::activate( $appData, 'files' );
check( 'the list and the card say whether the account may change the files now - not the page, whose data block is as old as its load, and a setup switches the feature on under it: false for the account of the CRM alone, true with the permission of the Files panel, false again where the feature is off', $allowedCrm === [ false, false ] && $allowedFiles === [ true, true ] && $allowedOff === false );
$asCrm();

// Files off: no files to count and the actions say so
\Nino\Features::deactivate( $appData, 'files' );
[ , $off ] = surfaceApi( $appData, 'crm-projekte/list' );
$cardOff = surfaceApi( $appData, 'crm-projekte/get', [ 'id' => '00001' ] )[1];
$asFiles();
$listOff = surfaceApi( $appData, 'files/list', [ 'group' => $group ] );
check( 'where the feature Files is off the list has no count of files (null) and says so, and the card has no list; the actions of the panel are 409 files_off, with the permission', $off['files'] === false && array_unique( array_column( $off['projects'], 'files' ) ) === [ null ] && $cardOff['files'] === false && $cardOff['list'] === null && $cardOff['folder'] === null
	&& $listOff[0] === 409 && $listOff[1]['code'] === 'files_off' );
\Nino\Features::activate( $appData, 'files' );

// The permission in the data block and the sentences
$html = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
$withFeature = [ $perms( 'files@example.com', 'files password' )['files'], $perms( 'crm@example.com', 'crm password' )['files'], $perms( 'admin@example.com', 'correct horse battery staple' )['files'] ];
\Nino\Features::deactivate( $appData, 'files' );
$featureOff = $perms( 'files@example.com', 'files password' )['files'];
\Nino\Features::activate( $appData, 'files' );
check( 'the data block says whether the account holds the permission of the Files panel with the feature on: perms.files is true for it and for the owner, false for the account of the CRM alone - and false for the one that holds it while the feature is off', surfaceData( $html )['perms']['files'] === true && $withFeature === [ true, false, true ] && $featureOff === false );
$texts = Surface::texts();
check( 'the sentences are the page\'s: the restriction has its own, the codes of the Files panel come from its text file, and the feature that is off has one', ( $texts['error.crm_files_group'] ?? '' ) !== '' && ( $texts['error.files_off'] ?? '' ) !== '' && str_contains( $texts['error.files_quota'] ?? '', '%s' ) === true && ( $texts['error.files_group'] ?? '' ) !== '' && Surface\Files::textFile() !== '' && str_ends_with( Surface\Files::textFile(), '/Files/text/de_DE.php' ) === true );
$asCrm();

// --- Firmendaten: the actions of the Text panel through the endpoint, for the keys below /project/company/ alone ------------

echo "\nFirmendaten\n";

\Nino\Auth::insertUser( $appData, 'texts@example.com', 'texts password', [ '/_admin/crm/manage', '/_admin/text/manage' ] );
\Nino\Auth::insertUser( $appData, 'textsonly@example.com', 'textsonly password', [ '/_admin/text/manage' ] );
\Nino\Auth::insertUser( $appData, 'textname@example.com', 'textname password', [ '/_admin/crm/manage', '/_admin/text/manage', '/_admin/text/update/project/company/general/name' ] );
$asTexts = static function() use ( $asUser ): void { $asUser( 'texts@example.com', 'texts password' ); };
$companyKeys = [ '/project/company/contact/address', '/project/company/contact/country', '/project/company/contact/email', '/project/company/contact/phone', '/project/company/general/description', '/project/company/general/name' ];
$textFiles = static fn(): array => [ \Nino\Filesystem::getFileContent( $appData, '/text/global.php', [] ), \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] ), \Nino\Filesystem::getFileContent( $appData, '/text/en_US.php', [] ) ];

$asVisitor();
check( 'text/keys and text/savebatch are in the table: nobody is told 401', surfaceApi( $appData, 'text/keys' )[0] === 401 && surfaceApi( $appData, 'text/savebatch', [ 'items' => [] ] )[0] === 401 );
$asCrm();
check( '...the account of the CRM alone is told 403 - the endpoint asks for the CRM\'s permission and the panel\'s', surfaceApi( $appData, 'text/keys' )[0] === 403 && surfaceApi( $appData, 'text/savebatch', [ 'items' => [ [ 'key' => $companyKeys[5], 'locale' => '*', 'value' => 'X' ] ] ] )[0] === 403 && $textFiles() === $textFiles() );
$asUser( 'textsonly@example.com', 'textsonly password' );
check( '...and so is the account of the Text panel alone: the endpoint is the CRM\'s', surfaceApi( $appData, 'text/keys' )[0] === 403 );

$asTexts();
[ $status, $keys ] = surfaceApi( $appData, 'text/keys' );
$panel = ninoCall( $appData, [ \Nino\Modules\Text\Admin::class, 'apiKeys' ], [], 'text/keys' )[1];
check( 'with both permissions text/keys is 200 and answers the six keys of the company and no other key of the site - the panel itself answers more', $status === 200 && array_column( $keys['keys'], 'key' ) === $companyKeys && count( $panel['keys'] ) > 6 && count( array_filter( $panel['keys'], static fn( array $one ): bool => str_starts_with( $one['key'], '/project/company/' ) === false ) ) > 0 );
check( '...each with its values (a text of all languages under *, the others per language) and whether the account may write it; beside them the languages with a text file and the native one - and nothing of what arranges the panel\'s own form', array_keys( $keys ) === [ 'keys', 'locales', 'native' ] && $keys['native'] === \Nino\Locales::getNativeLocale( $appData ) && array_column( $keys['locales'], 'locale' ) === array_column( $panel['locales'], 'locale' )
	&& array_filter( $keys['keys'], static fn( array $one ): bool => array_key_exists( 'writable', $one ) === false || array_key_exists( 'values', $one ) === false || array_key_exists( 'global', $one ) === false ) === [] && ( array_column( $keys['keys'], 'global', 'key' )['/project/company/general/name'] ?? null ) === true && isset( array_column( $keys['keys'], 'values', 'key' )['/project/company/general/name']['*'] )
	&& array_column( $keys['keys'], 'writable', 'key' )['/project/company/general/name'] === true );

$before = $textFiles();
$linesBefore = $itemsLines( 'Edit Text' );
foreach( [ 'a key of the site\'s other texts' => [ [ 'key' => '/project/website/general/url', 'locale' => '*', 'value' => 'https://evil.example' ] ],
	'a company key and another one in the same batch' => [ [ 'key' => $companyKeys[5], 'locale' => '*', 'value' => 'Nicht geschrieben' ], [ 'key' => '/template/page-home/hero/title', 'locale' => 'de_DE', 'value' => 'X' ] ],
	'a key with dots in it' => [ [ 'key' => '/project/company/../website/general/url', 'locale' => '*', 'value' => 'X' ] ],
	'a key that holds the prefix but does not start with it' => [ [ 'key' => '/x/project/company/general/name', 'locale' => '*', 'value' => 'X' ] ],
	'a key with a space at its end' => [ [ 'key' => '/project/company/general/name ', 'locale' => '*', 'value' => 'X' ] ],
	'the bare prefix' => [ [ 'key' => '/project/company/', 'locale' => '*', 'value' => 'X' ] ],
	'a key that only starts like the prefix' => [ [ 'key' => '/project/company', 'locale' => '*', 'value' => 'X' ] ],
	'an item without a key' => [ [ 'locale' => '*', 'value' => 'X' ] ],
	'an item that is no object' => [ 'x' ] ] as $what => $items ) {
	[ $status, $refused ] = surfaceApi( $appData, 'text/savebatch', [ 'items' => $items ] );
	check( 'a batch with '. $what. ' is refused as a whole: 404 crm_texts_key', $status === 404 && $refused['code'] === 'crm_texts_key' );
}
check( '...nothing was written to any text file, and the activity log has no line for them', $textFiles() === $before && $itemsLines( 'Edit Text' ) === $linesBefore );

[ $status, $saved ] = surfaceApi( $appData, 'text/savebatch', [ 'items' => [ [ 'key' => $companyKeys[5], 'locale' => '*', 'value' => 'Studio Nordlicht' ], [ 'key' => $companyKeys[4], 'locale' => 'de_DE', 'value' => 'Ein Tonstudio.' ] ] ] );
[ $statusEn, $savedEn ] = surfaceApi( $appData, 'text/savebatch', [ 'items' => [ [ 'key' => $companyKeys[4], 'locale' => 'en_US', 'value' => 'A recording studio.' ] ] ] );
$files = $textFiles();
// The next request reads the files again (the name that an earlier pin of this suite put in as a fill of the run is the files' to say now)
unset( $appData['./nino/html/filecache'], $appData['./nino/html/fills']['*']['[['. $companyKeys[5]. ']]'] );
check( 'a batch of company keys is the panel\'s: the text of all languages and one language in one batch, another language in the next - 200 and a result for each key', $status === 200 && $statusEn === 200 && $saved['results'][$companyKeys[5]] === [ 'ok' => true, 'value' => 'Studio Nordlicht' ] && $saved['results'][$companyKeys[4]]['ok'] === true && $savedEn['results'][$companyKeys[4]]['value'] === 'A recording studio.' );
check( '...it is in the text files, and the fills the PDFs, the mails and the brand read give it back in each language', $files[0]['[['. $companyKeys[5]. ']]'] === 'Studio Nordlicht' && $files[1]['[['. $companyKeys[4]. ']]'] === 'Ein Tonstudio.' && $files[2]['[['. $companyKeys[4]. ']]'] === 'A recording studio.'
	&& \Nino\Html::resolveTextfill( $appData, $companyKeys[5] ) === 'Studio Nordlicht' && \Nino\Html::getFills( $appData, 'de_DE' )['[['. $companyKeys[4]. ']]'] === 'Ein Tonstudio.' && \Nino\Html::getFills( $appData, 'en_US' )['[['. $companyKeys[4]. ']]'] === 'A recording studio.' );
[ , $again ] = surfaceApi( $appData, 'text/keys' );
check( '...and text/keys reads them back', array_column( $again['keys'], 'values', 'key' )[$companyKeys[4]] === [ 'de_DE' => 'Ein Tonstudio.', 'en_US' => 'A recording studio.' ] && array_column( $again['keys'], 'values', 'key' )[$companyKeys[5]] === [ '*' => 'Studio Nordlicht' ] );
check( '...the activity log has the panel\'s line for the saves - by the account that did it, naming the group of the keys - and none for the refusals or the reads', $itemsLines( 'Edit Text /project/company' ) === 2 && count( array_filter( \Nino\Modules\Logs\Admin::recentLines( $appData, 400 ), static fn( string $entry ): bool => str_contains( $entry, 'Edit Text /project/company' ) && str_contains( $entry, 'texts@example.com' ) ) ) === 2 );

// The brand of the page is the company's name, read when the page is made
$brandPage = (string) surfaceServe( $appData, 'GET', '/crm' )['body'];
check( 'the brand of the page is the saved name on the next load', str_contains( $brandPage, 'Studio Nordlicht' ) === true );

// The sentences
$texts = Surface::texts();
check( 'the sentences are the page\'s: the code of the restriction has its own, and the panel\'s failure sentences come from its text file', ( $texts['error.crm_texts_key'] ?? '' ) !== '' && ( $texts['text.error-save'] ?? '' ) !== '' && ( $texts['text.error-load'] ?? '' ) !== '' && Surface\Texts::textFile() !== '' && str_ends_with( Surface\Texts::textFile(), '/Text/text/de_DE.php' ) === true
	&& $texts['text.error-save'] === ( (array) require Surface\Texts::textFile() )['[[/_admin/text/error/save]]'] );

// The scoped permission of the panel is its own: a key the role may not update says so in its result
$asUser( 'textname@example.com', 'textname password' );
[ $status, $scoped ] = surfaceApi( $appData, 'text/savebatch', [ 'items' => [ [ 'key' => $companyKeys[5], 'locale' => '*', 'value' => 'Nur der Name' ], [ 'key' => $companyKeys[3], 'locale' => '*', 'value' => '999' ] ] ] );
check( 'a role that may update some keys only: the panel answers 200 with a result for each key - written for the one, "not allowed" for the other - and the other is not written', $status === 200 && $scoped['results'][$companyKeys[5]]['ok'] === true && $scoped['results'][$companyKeys[3]] === [ 'ok' => false, 'error' => 'not allowed' ] && ( $textFiles()[0]['[['. $companyKeys[3]. ']]'] ?? '' ) !== '999' );
[ , $scopedKeys ] = surfaceApi( $appData, 'text/keys' );
check( '...and text/keys says which keys it may write', array_column( $scopedKeys['keys'], 'writable', 'key' )[$companyKeys[5]] === true && array_column( $scopedKeys['keys'], 'writable', 'key' )[$companyKeys[3]] === false );

// The permission in the data block
check( 'the data block says whether the account holds the permission of the Text panel: perms.text is true for it and for the owner, false for the account of the CRM alone', $perms( 'texts@example.com', 'texts password' )['text'] === true && $perms( 'admin@example.com', 'correct horse battery staple' )['text'] === true && $perms( 'crm@example.com', 'crm password' )['text'] === false );

// The account of the CRM alone reads the company in settings-get
$asCrm();
[ $status, $view ] = surfaceApi( $appData, 'crm-documents/settings-get' );
check( 'an account of the CRM alone reads the company\'s texts in settings-get (the block shows them read-only): the six keys with their values, the languages and the native one - and cannot write them', $status === 200 && array_column( $view['company']['keys'], 'key' ) === $companyKeys && array_column( $view['company']['keys'], 'values', 'key' )[$companyKeys[5]] === [ '*' => 'Nur der Name' ]
	&& array_keys( $view['company'] ) === [ 'keys', 'locales', 'native' ] && surfaceApi( $appData, 'text/savebatch', [ 'items' => [ [ 'key' => $companyKeys[5], 'locale' => '*', 'value' => 'Y' ] ] ] )[0] === 403 );
$asCrm();

// The vocabulary the menu offers is the vocabulary the documents hand a template
$script = (string) file_get_contents( $root. '/app/Crm/Surface/assets/crm.js' );
preg_match( '~const PDF_TOKENS = \[(.*?)\n\t\];~s', $script, $tokenBlock );
preg_match_all( "~'(/[a-z]+/[a-z]+)'~", $tokenBlock[1] ?? '', $menuTokens );
$handed = [];
foreach( [ $late1, $sentLate ] as $document ) {
	$full = Documents::get( $appData, $document['id'] );
	$handed = array_merge( $handed, array_keys( \Crm\Documents\Render::data( $appData, $full, $full['lines'], $full['client'] ?? [] )['tokens'] ) );
}
$offerDocument = Documents::get( $appData, $paidThisYear['id'] );
$offerDoc = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => $day( 30 ), 'lines' => [ $line( 'Angebot' ) ], 'template_key' => 'angebot' ] );
$full = Documents::get( $appData, $offerDoc['id'] );
$handed = array_merge( $handed, array_keys( \Crm\Documents\Render::data( $appData, $full, $full['lines'], [] )['tokens'] ) );
$menu = array_values( array_unique( $menuTokens[1] ) );
sort( $menu );
$handed = array_values( array_unique( $handed ) );
sort( $handed );
check( 'the placeholders of the menu - Beleg, Rechnung, Angebot, Kunde, Portal - are exactly the tokens Render::data() hands a template for an invoice and an offer', $menu === $handed && count( $menu ) === 30 );


// --- The script of the surface ---------------------------------------------------------------------------------------

echo "\nThe script of the surface\n";

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if( $node === '' )
	echo "  note - node is not on the path: the suite of the surface's script was not run\n";
else {
	$output = (string) shell_exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/surface-js-smoke.js' ). ' 2>&1' );
	$ran = preg_match( '/(\d+) checks, (\d+) failed/', $output, $counts ) === 1;

	check( 'surface-js-smoke.js passes ('. ( $ran ? $counts[1]. ' checks' : 'no summary' ). ')', $ran && $counts[2] === '0' && (int) $counts[1] >= 400 && str_contains( $output, 'FAIL' ) === false );
}

ninoDone( $appData );

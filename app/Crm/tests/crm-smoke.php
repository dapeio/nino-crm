<?php
declare(strict_types=1);

/**
 *	crm-smoke.php		Contract test for the project module \Crm\Crm: the SQLite layer and its migrations, the clients,
 *									the panel Kunden with its actions, the invitation of an account of Members, the install unit
 *									the panel applies (the portal pages), the status, the component [crm-client] and the portal
 *									pages as a member and a visitor see them. Runs over the kernel's tests/harness.php three
 *									levels up.
 *
 *	Usage: php app/Crm/tests/crm-smoke.php
 */

$root = dirname( __DIR__, 3 );
require $root. '/tests/harness.php';

/**
 *	Boot an appData against a sandbox directory, the way a request would
 *
 *	@param		string		$sandbox
 *
 *	@return 	array
 */
function crmBoot( string $sandbox ): array {

	$appData = [ './nino/uid' => $sandbox ];
	\Nino\AppData::prepare( $appData );
	$appData['./nino/filesystem/path']				= $sandbox;
	$appData['./nino/filesystem/configpath']	= $sandbox. '/private';
	$appData['./nino/filesystem/contentpath']	= $sandbox. '/private';
	$appData['./nino/filesystem/publicpath']	= $sandbox. '/public';

	return $appData;
}

// A worker of the concurrency section below: its own process, its own request, adding clients to the shared file. The
// exit status is the number of adds that failed
if( ( $argv[1] ?? '' ) === 'worker' ) {

	$worker = crmBoot( (string) $argv[2] );
	$failed = 0;

	for( $round = 0; $round < (int) $argv[3]; $round++ ) {

		try {
			if( is_array( \Crm\Clients::add( $worker, [ 'name' => 'Worker '. $argv[4]. ' '. $round ] ) ) === false )
				$failed++;
		}
		catch( \PDOException $e ) {
			$failed++;
		}

		\Crm\Db::close();
	}

	exit( min( 255, $failed ) );
}

$appData = ninoSandbox( 'crm' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

/**
 *	The mail the transport took at a position
 *
 *	@param		\ArrayObject	$mails
 *	@param		int				$index
 *
 *	@return 	array
 */
function crmMail( \ArrayObject $mails, int $index ): array {

	$mail = $mails->offsetGet( $index );

	return is_array( $mail ) === true ? $mail : [];
}

// --- Db ------------------------------------------------------------------------------------------------------------

echo "Db\n";

$file = \Nino\Filesystem::path( $appData, '/data/crm.sqlite' );
check( 'there is no database before the first connection', is_file( $file ) === false );

$pdo = \Crm\Db::pdo( $appData );
check( 'the first connection creates /data/crm.sqlite', is_file( $file ) === true );
check( 'it ran every migration there is', \Crm\Db::migrated() === count( \Crm\Db::migrations() ) && count( \Crm\Db::migrations() ) >= 1 );
check( 'user_version is the number of the last migration', (int) $pdo->query( 'PRAGMA user_version' )->fetchColumn() === max( array_keys( \Crm\Db::migrations() ) ) );

$tables = array_column( \Crm\Db::rows( $appData, 'SELECT name FROM sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name' ), 'name' );
check( 'the tables of the first schema are there: clients, client_accounts and settings (later migrations add their own)', array_diff( [ 'client_accounts', 'clients', 'settings' ], $tables ) === [] );
check( 'the journal is WAL and foreign keys are on', strtolower( (string) $pdo->query( 'PRAGMA journal_mode' )->fetchColumn() ) === 'wal' && (int) $pdo->query( 'PRAGMA foreign_keys' )->fetchColumn() === 1 );
check( 'vat_mode is small_business', ( \Crm\Db::row( $appData, 'SELECT value FROM settings WHERE key = :k', [ 'k' => 'vat_mode' ] )['value'] ?? '' ) === 'small_business' );

\Crm\Db::close();
$boot = '';

try {
	$pdo = \Crm\Db::pdo( $appData );
}
catch( \PDOException $e ) {
	$boot = $e->getMessage();
}

check( 'a second boot runs no migration', $boot === '' && \Crm\Db::migrated() === 0 && (int) $pdo->query( 'PRAGMA user_version' )->fetchColumn() === max( array_keys( \Crm\Db::migrations() ) ) );
check( 'the busy timeout is 5 seconds', (int) $pdo->query( 'PRAGMA busy_timeout' )->fetchColumn() === 5000 );
check( 'the connection is one per request', \Crm\Db::pdo( $appData ) === $pdo );

$settingsBefore = \Crm\Db::row( $appData, 'SELECT COUNT(*) AS n FROM settings' )['n'];
\Crm\Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = :k', [ 'v' => 'regular', 'k' => 'vat_mode' ] );
\Crm\Db::close();
\Crm\Db::pdo( $appData );
check( 'a later boot leaves a changed setting alone', \Crm\Db::row( $appData, 'SELECT value FROM settings WHERE key = :k', [ 'k' => 'vat_mode' ] )['value'] === 'regular' && (int) \Crm\Db::row( $appData, 'SELECT COUNT(*) AS n FROM settings' )['n'] === (int) $settingsBefore );

$quote = "x'; DROP TABLE clients; --";
$probe = '';

try {
	\Crm\Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( :k, :v )', [ 'k' => 'probe', 'v' => $quote ] );
}
catch( \PDOException $e ) {
	$probe = $e->getMessage();
}

check( 'a value with SQL in it is data', $probe === '' && ( \Crm\Db::row( $appData, 'SELECT value FROM settings WHERE key = :k', [ 'k' => 'probe' ] )['value'] ?? '' ) === $quote && in_array( 'clients', array_column( \Crm\Db::rows( $appData, 'SELECT name FROM sqlite_master' ), 'name' ), true ) );
\Crm\Db::exec( $appData, 'DELETE FROM settings WHERE key = :k', [ 'k' => 'probe' ] );

// --- Clients -------------------------------------------------------------------------------------------------------

echo "\nClients\n";

$first = \Crm\Clients::add( $appData, [ 'company' => ' Studio Nord GmbH ', 'name' => 'Anna Nord', 'email' => 'anna@example.org', 'zip' => '20095', 'country' => 'de', 'vat_id' => 'de 123456789' ] );
check( 'the first client is K-0001, trimmed, upper-cased where it is a code, active', is_array( $first ) && $first['number'] === 'K-0001' && $first['company'] === 'Studio Nord GmbH'
	&& $first['country'] === 'DE' && $first['vat_id'] === 'DE123456789' && $first['active'] === true && $first['group'] === 'k-0001' );
$second = \Crm\Clients::add( $appData, [ 'name' => 'Ben Süd' ] );
check( 'the second is K-0002 and a client with a name alone is a client', is_array( $second ) && $second['number'] === 'K-0002' && $second['country'] === 'DE' && $second['company'] === '' );
$duplicate = '';

try {
	\Crm\Db::exec( $appData, 'INSERT INTO clients ( number, created, updated ) VALUES ( \'K-0001\', \'x\', \'x\' )' );
}
catch( \PDOException $e ) {
	$duplicate = 'refused';
}

check( 'a client number is unique', $duplicate === 'refused' );
check( 'the group of a client is its number as a slug', \Crm\Clients::group( 'K-0042' ) === 'k-0042' );

check( 'neither company nor name is refused', \Crm\Clients::add( $appData, [ 'email' => 'x@example.org' ] ) === 'empty' );
check( 'a bad email is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'email' => 'not-an-address' ] ) === 'email:email' );
check( 'a zip with a symbol is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'zip' => '2<0' ] ) === 'format:zip' );
check( 'a country of three letters is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'country' => 'DEU' ] ) === 'long:country' );
check( 'a country that is no code is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'country' => '1a' ] ) === 'format:country' );
check( 'a phone with letters is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'phone' => 'call me' ] ) === 'format:phone' );
check( 'a vat id that is none is refused', \Crm\Clients::add( $appData, [ 'name' => 'A', 'vat_id' => '12345' ] ) === 'format:vat_id' );
check( 'a name over its length is refused', \Crm\Clients::add( $appData, [ 'name' => str_repeat( 'x', 121 ) ] ) === 'long:name' );
check( 'a field that is no string is refused', \Crm\Clients::add( $appData, [ 'name' => [ 'x' ] ] ) === 'format:name' );
check( 'a refused client takes no number', \Crm\Clients::add( $appData, [ 'name' => 'Third' ] )['number'] === 'K-0003' );

$changed = \Crm\Clients::change( $appData, $first['id'], [ 'city' => 'Hamburg', 'company' => 'Studio Nord AG' ] );
check( 'change() changes what is given and keeps the rest', is_array( $changed ) && $changed['city'] === 'Hamburg' && $changed['company'] === 'Studio Nord AG' && $changed['email'] === 'anna@example.org' && $changed['number'] === 'K-0001' );
check( 'change() validates', \Crm\Clients::change( $appData, $first['id'], [ 'email' => 'nope' ] ) === 'email:email' && \Crm\Clients::get( $appData, $first['id'] )['email'] === 'anna@example.org' );
check( 'change() cannot empty a client', \Crm\Clients::change( $appData, $first['id'], [ 'company' => '', 'name' => '' ] ) === 'empty' );
check( 'change() of nobody is unknown', \Crm\Clients::change( $appData, 999, [ 'city' => 'X' ] ) === 'unknown' );

$off = \Crm\Clients::setActive( $appData, $second['id'], false );
check( 'a client is deactivated, not deleted', is_array( $off ) && $off['active'] === false && \Crm\Clients::get( $appData, $second['id'] ) !== null );
check( 'active clients are counted', \Crm\Clients::countActive( $appData ) === 2 );
check( 'deactivating nobody is unknown', \Crm\Clients::setActive( $appData, 999, false ) === 'unknown' );
check( 'the number of a deactivated client is not given again', \Crm\Clients::add( $appData, [ 'name' => 'Fourth' ] )['number'] === 'K-0004' );

check( 'list() has every client by number', array_column( \Crm\Clients::list( $appData ), 'number' ) === [ 'K-0001', 'K-0002', 'K-0003', 'K-0004' ] );
check( 'a search finds by company', array_column( \Crm\Clients::list( $appData, 'nord ag' ), 'number' ) === [ 'K-0001' ] );
check( 'a search finds by email', array_column( \Crm\Clients::list( $appData, 'anna@' ), 'number' ) === [ 'K-0001' ] );
check( 'a search finds by number', array_column( \Crm\Clients::list( $appData, 'K-0003' ), 'number' ) === [ 'K-0003' ] );
check( 'the wildcards of a search are taken literally', \Crm\Clients::list( $appData, '%' ) === [] && \Crm\Clients::list( $appData, '_' ) === [] );

$twice = '';

try {
	\Crm\Clients::link( $appData, $first['id'], 'aaaaaaaaaaaa' );
	\Crm\Clients::link( $appData, $first['id'], 'aaaaaaaaaaaa' );
}
catch( \PDOException $e ) {
	$twice = $e->getMessage();
}

\Crm\Clients::link( $appData, $first['id'], 'bbbbbbbbbbbb' );
\Crm\Clients::link( $appData, $second['id'], 'dddddddddddd' );
\Crm\Clients::link( $appData, \Crm\Clients::list( $appData, 'K-0003' )[0]['id'], 'dddddddddddd' );
check( 'an account of two clients belongs to the active one', ( \Crm\Clients::ofMember( $appData, 'dddddddddddd' )['number'] ?? '' ) === 'K-0003' );
check( 'an account is linked once', $twice === '' && \Crm\Clients::accounts( $appData, $first['id'] ) === [ 'aaaaaaaaaaaa', 'bbbbbbbbbbbb' ] && \Crm\Clients::get( $appData, $first['id'] )['accounts'] === 2 );
check( 'the client of an account', ( \Crm\Clients::ofMember( $appData, 'bbbbbbbbbbbb' )['number'] ?? '' ) === 'K-0001' && \Crm\Clients::ofMember( $appData, 'cccccccccccc' ) === null );

$restricted = false;
try {
	\Crm\Db::exec( $appData, 'DELETE FROM clients WHERE id = :id', [ 'id' => $first['id'] ] );
}
catch( \PDOException $e ) {
	$restricted = true;
}
check( 'a client with accounts cannot be deleted from under them', $restricted === true && \Crm\Clients::get( $appData, $first['id'] ) !== null );

// --- Numbers under real concurrency ------------------------------------------------------------------------------------

echo "\nNumbers under concurrency\n";

$parallel = ninoSandbox( 'crm-parallel' );
\Crm\Db::pdo( $parallel );
\Crm\Db::close();

$workers = [];

for( $worker = 0; $worker < 8; $worker++ )
	$workers[] = proc_open( [ PHP_BINARY, __FILE__, 'worker', ninoSandboxDir( $parallel ), '25', (string) $worker ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );

$failures = 0;

foreach( $workers as $process )
	if( is_resource( $process ) === true )
		$failures += proc_close( $process );

$numbers = array_column( \Crm\Clients::list( $parallel ), 'number' );
check( 'eight processes adding 25 clients each: every add succeeded', $failures === 0 );
check( '...and the numbers are unique and contiguous, K-0001 to K-0200', count( $numbers ) === 200 && count( array_unique( $numbers ) ) === 200 && $numbers === array_map( static fn( int $n ): string => 'K-'. sprintf( '%04d', $n ), range( 1, 200 ) ) );
\Crm\Db::close();
\Nino\Filesystem::removeDir( ninoSandboxDir( $parallel ) );

// --- The module, the features and the panel ------------------------------------------------------------------------

echo "\nThe module and its panel\n";

// The site the way the Members feature's own test builds it: a directory-less site with an address, the components, the
// csrf guard and the [template] shortcode, and a transport that takes the mails
$appData['/nino/dir'] = '';
\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Csrf::init( $appData );

$mails = new \ArrayObject();
$delivery = true;
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails, &$delivery ): void {
	$mails->append( $mail );
	$mail['sent'] = $delivery;
} );

$panel = [ \Nino\Admin\Admin::class, 'handlePost' ];

// Without the module there is no panel
check( 'the panel is not there while the module is not listed', isset( \Nino\Admin\Admin::panels( $appData )['crm-clients'] ) === false && ninoCall( $appData, $panel, [], 'crm-clients/list' )[0] === 404 );

foreach( [ 'members', 'files', 'review', 'pdf', 'sign' ] as $key )
	check( 'the feature '. $key. ' is activated', \Nino\Features::activate( $appData, $key ) === true );

$listed = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] );
$listed[] = '\\Crm\\Crm';
$appData['/nino/modules'] = $listed;
\Nino\AppData::writeContentData( $appData, [ '/nino/modules' ] );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );
$cleared = ninoWarnings();

$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the panel is in the registry while the module is listed, in the content group', isset( $panels['crm-clients'] ) && $panels['crm-clients']['group'] === 'content' && ltrim( (string) $panels['crm-clients']['class'], '\\' ) === \Crm\Clients\Admin::class );
check( 'its permission is /_admin/crm-clients/manage', \Crm\Clients\Admin::perm() === '/_admin/crm-clients/manage' && ( $panels['crm-clients']['perm'] ?? '' ) === '/_admin/crm-clients/manage' );
check( 'its actions are the eight of the plan', array_keys( \Crm\Clients\Admin::actions() ) === [ 'crm-clients/list', 'crm-clients/get', 'crm-clients/save', 'crm-clients/active', 'crm-clients/invite', 'crm-clients/accounts', 'crm-clients/setup', 'crm-clients/status' ] );

$assets = \Crm\Clients\Admin::assets();
check( 'its assets, template and text directory are files of the module', count( $assets ) === 2 && array_filter( $assets, static fn( string $asset ): bool => is_file( $root. $asset ) === false ) === []
	&& is_file( $root. \Crm\Clients\Admin::template(). '.tpl' ) === true && is_file( $root. \Crm\Clients\Admin::text(). '/de_DE.php' ) === true && is_file( $root. \Crm\Clients\Admin::text(). '/en_US.php' ) === true );

$words = [ 'de_DE' => require $root. \Crm\Clients\Admin::text(). '/de_DE.php', 'en_US' => require $root. \Crm\Clients\Admin::text(). '/en_US.php' ];
check( 'the words are the same keys in German and English, none empty', array_keys( $words['de_DE'] ) === array_keys( $words['en_US'] ) && array_filter( $words['de_DE'] + $words['en_US'], static fn( string $text ): bool => trim( $text ) === '' ) === [] );

$source = (string) file_get_contents( $root. '/app/Crm/Clients/Admin/Admin.php' ). (string) file_get_contents( $root. '/app/Crm/Clients/Admin/assets/admin.js' );
preg_match_all( "~'(crm_clients_[a-z]+)'~", $source, $codes );
preg_match_all( "~/_admin/crm-clients/[a-z/-]+~", (string) file_get_contents( $root. '/app/Crm/Clients/Admin/assets/admin.js' ), $keys );
check( 'every code the panel answers has its text in both languages', array_filter( array_unique( $codes[1] ), static fn( string $code ): bool => isset( $words['de_DE']['[[/_admin/error/'. $code. ']]'], $words['en_US']['[[/_admin/error/'. $code. ']]'] ) === false ) === [] && count( array_unique( $codes[1] ) ) >= 10 );
check( 'every fill key the script asks for whole has its text', array_filter( array_unique( $keys[0] ), static fn( string $key ): bool => str_ends_with( $key, '/' ) === false && isset( $words['de_DE']['[['. $key. ']]'] ) === false ) === [] );
$called = [];

foreach( [ 'members', 'files', 'review', 'sign', 'pdf' ] as $key ) {
	$name = \Nino\Features::localized( \Nino\Features::manifest( $root. '/features/'. ucfirst( $key ) )['name'] ?? [], 'de_DE' );
	$called[$key] = str_contains( $words['de_DE']['[[/_admin/crm-clients/status/'. $key. '-off]]'] ?? '', 'Feature '. $name. ' ' ) === true;
}

check( 'the German status calls each feature by the name the Features panel gives it', $called === [ 'members' => true, 'files' => true, 'review' => true, 'sign' => true, 'pdf' => true ] );
check( 'the German panel speaks Du', preg_match( '/\b(Sie|Ihre|Ihnen)\b/', implode( ' ', $words['de_DE'] ) ) !== 1 );

ninoUser( $appData, 'plain@example.com', [], 'plain password' );
ninoUser( $appData, 'admin@example.com' );

$posts = [ 'crm-clients/list' => [], 'crm-clients/get' => [ 'id' => '1' ], 'crm-clients/save' => [ 'name' => 'Nobody' ], 'crm-clients/active' => [ 'id' => '1' ],
	'crm-clients/invite' => [ 'id' => '1', 'email' => 'nobody@example.org' ], 'crm-clients/accounts' => [ 'id' => '1' ], 'crm-clients/setup' => [], 'crm-clients/status' => [] ];
$denied = [ 'signed out' => [], 'without the permission' => [] ];

unset( $appData['./nino/auth/current'] );
foreach( $posts as $action => $data ) {
	[ $status, $body ] = ninoCall( $appData, $panel, $data, $action );
	$denied['signed out'][$action] = [ $status, $body['code'] ?? '' ];
}

\Nino\Auth::loginUser( $appData, 'plain@example.com', 'plain password' );
foreach( $posts as $action => $data ) {
	[ $status, $body ] = ninoCall( $appData, $panel, $data, $action );
	$denied['without the permission'][$action] = [ $status, $body['code'] ?? '' ];
}

check( 'every action refuses a visitor with 401 and the code session', array_values( array_unique( array_map( 'json_encode', $denied['signed out'] ) ) ) === [ '[401,"session"]' ] && array_keys( $denied['signed out'] ) === array_keys( \Crm\Clients\Admin::actions() ) );
check( '...and an account without the permission with 403, writing nothing', array_values( array_unique( array_map( 'json_encode', $denied['without the permission'] ) ) ) === [ '[403,""]' ]
	&& count( \Crm\Clients::list( $appData ) ) === 4 && count( $mails ) === 0 && is_file( \Nino\Filesystem::path( $appData, '/templates/page-portal.tpl' ) ) === false );

\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );

// --- The actions -----------------------------------------------------------------------------------------------------

echo "\nThe actions\n";

[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/list' );
check( 'list answers the clients by number', $status === 200 && array_column( $body['clients'], 'number' ) === [ 'K-0001', 'K-0002', 'K-0003', 'K-0004' ] );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'search' => 'nord' ], 'crm-clients/list' );
check( 'list searches', $status === 200 && array_column( $body['clients'], 'number' ) === [ 'K-0001' ] );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'search' => str_repeat( 'x', 101 ) ], 'crm-clients/list' );
check( 'a search that is too long is 400 crm_clients_long', $status === 400 && $body['code'] === 'crm_clients_long' && $body['field'] === 'search' );

[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'] ], 'crm-clients/get' );
check( 'get answers the client with its accounts', $status === 200 && $body['client']['number'] === 'K-0001' && count( $body['accounts'] ) === 2 && $body['accounts'][0]['gone'] === true );
check( 'get of a bad id is 400 crm_clients_id', ninoCall( $appData, $panel, [ 'id' => '1; DROP' ], 'crm-clients/get' )[1]['code'] === 'crm_clients_id' && ninoCall( $appData, $panel, [ 'id' => '1; DROP' ], 'crm-clients/get' )[0] === 400 );
check( 'get of nobody is 404 crm_clients_unknown', ninoCall( $appData, $panel, [ 'id' => '999' ], 'crm-clients/get' )[0] === 404 && ninoCall( $appData, $panel, [ 'id' => '999' ], 'crm-clients/get' )[1]['code'] === 'crm_clients_unknown' );

[ $status, $body ] = ninoCall( $appData, $panel, [ 'company' => 'Musikhaus Mitte', 'email' => 'info@mitte.example', 'city' => 'Berlin' ], 'crm-clients/save' );
check( 'save without an id adds a client with the next number', $status === 200 && $body['client']['number'] === 'K-0005' && $body['client']['active'] === true );
$five = $body['client'];
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $five['id'], 'city' => 'Potsdam' ], 'crm-clients/save' );
check( 'save with an id changes it and keeps the rest', $status === 200 && $body['client']['city'] === 'Potsdam' && $body['client']['company'] === 'Musikhaus Mitte' );
check( 'save without company and name is 400 crm_clients_empty on company', ( $r = ninoCall( $appData, $panel, [ 'email' => 'a@example.org' ], 'crm-clients/save' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_empty' && $r[1]['field'] === 'company' );
check( 'a bad address is 400 crm_clients_email on email', ( $r = ninoCall( $appData, $panel, [ 'name' => 'A', 'email' => 'nope' ], 'crm-clients/save' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_email' && $r[1]['field'] === 'email' );
check( 'a zip with a symbol is 400 crm_clients_format on zip', ( $r = ninoCall( $appData, $panel, [ 'name' => 'A', 'zip' => '<' ], 'crm-clients/save' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_format' && $r[1]['field'] === 'zip' );
check( 'a long name is 400 crm_clients_long with its limit', ( $r = ninoCall( $appData, $panel, [ 'name' => str_repeat( 'x', 121 ) ], 'crm-clients/save' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_long' && $r[1]['params'] === [ 120 ] );
check( 'a value that is no text is 400 crm_clients_format', ( $r = ninoCall( $appData, $panel, [ 'name' => [ 'x' ] ], 'crm-clients/save' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_format' );
check( 'a refused save wrote nothing', count( \Crm\Clients::list( $appData ) ) === 5 );
check( 'save of an id nobody has is 404', ninoCall( $appData, $panel, [ 'id' => '999', 'city' => 'X' ], 'crm-clients/save' )[0] === 404 );

[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $five['id'] ], 'crm-clients/active' );
check( 'active without a value switches the client off', $status === 200 && $body['client']['active'] === false );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $five['id'], 'active' => true ], 'crm-clients/active' );
check( '...and active true switches it on', $status === 200 && $body['client']['active'] === true );
check( 'active of nobody is 404', ninoCall( $appData, $panel, [ 'id' => '999' ], 'crm-clients/active' )[0] === 404 );

check( 'the Dashboard tile counts the active clients', \Crm\Clients\Admin::summary( $appData )['value'] === \Crm\Clients::countActive( $appData ) && \Crm\Clients\Admin::summary( $appData )['label'] === '/_admin/crm-clients/label/count' );
check( 'the log line names the number and no address', \Crm\Clients\Admin::log( 'crm-clients/save', [ 'id' => '5', 'email' => 'info@mitte.example' ] ) !== '' && str_contains( \Crm\Clients\Admin::log( 'crm-clients/save', [ 'id' => '5', 'email' => 'info@mitte.example' ] ), '@' ) === false
	&& str_contains( \Crm\Clients\Admin::log( 'crm-clients/save', [ 'id' => '5' ] ), 'K-0005' ) === true && \Crm\Clients\Admin::log( 'crm-clients/list', [] ) === '' );

// --- Invite ----------------------------------------------------------------------------------------------------------

echo "\nInvite\n";

$mails->exchangeArray( [] );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'Anna@Example.org ', 'name' => 'Anna Nord' ], 'crm-clients/invite' );
$anna = \Nino\Modules\Members::accounts( $appData )[0] ?? [];
check( 'invite makes an account of Members in the group of the client, linked, and mails the invitation', $status === 200 && $body['mailed'] === true && ( $anna['group'] ?? '' ) === 'k-0001' && ( $anna['email'] ?? '' ) === 'anna@example.org'
	&& in_array( $anna['id'] ?? '', \Crm\Clients::accounts( $appData, $first['id'] ), true ) === true && count( $mails ) === 1 && ( crmMail( $mails, 0 )['to'] ?? '' ) === 'anna@example.org' );
check( '...the mail carries a link of the invitation', preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( crmMail( $mails, 0 )['body'] ?? '' ), $found ) === 1 );
$inviteToken = $found[1] ?? '';
$tokens = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/data/members-tokens.php', [] ) );
check( 'the link of the invitation leads to the portal', count( $tokens ) === 1 && ( $tokens[0]['target'] ?? '' ) === '/portal' && ( $tokens[0]['kind'] ?? '' ) === 'invite' );
check( 'the answer names the account and its state', ( $body['account']['email'] ?? '' ) === 'anna@example.org' && $body['account']['gone'] === false && $body['account']['active'] === true );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'] ], 'crm-clients/accounts' );
check( 'accounts lists it with its state in Members', $status === 200 && count( array_filter( $body['accounts'], static fn( array $a ): bool => ( $a['email'] ?? '' ) === 'anna@example.org' && $a['login'] === null ) ) === 1 );

$appDataSnapshot = count( \Nino\Modules\Members::accounts( $appData ) );
\Crm\Db::exec( $appData, 'CREATE TRIGGER no_links BEFORE INSERT ON client_accounts BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'retry@example.org', 'name' => 'Retry' ], 'crm-clients/invite' );
\Crm\Db::exec( $appData, 'DROP TRIGGER no_links' );
check( 'an account that cannot be linked is taken back: 500 crm_clients_database, no account left, no mail', $status === 500 && $body['code'] === 'crm_clients_database' && count( \Nino\Modules\Members::accounts( $appData ) ) === $appDataSnapshot && count( $mails ) === 1 );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'retry@example.org', 'name' => 'Retry' ], 'crm-clients/invite' );
check( '...so the retry goes through', $status === 200 && $body['mailed'] === true && count( $mails ) === 2 );
$mails->exchangeArray( array_slice( $mails->getArrayCopy(), 0, 1 ) );
$retry = array_values( array_filter( \Nino\Modules\Members::accounts( $appData ), static fn( array $a ): bool => $a['email'] === 'retry@example.org' ) )[0] ?? [];
\Nino\Modules\Members::remove( $appData, (string) ( $retry['id'] ?? '' ) );
\Crm\Db::exec( $appData, 'DELETE FROM client_accounts WHERE member_id = :m', [ 'm' => (string) ( $retry['id'] ?? '' ) ] );
check( 'a second invitation for the same address is 409 crm_clients_taken', ( $r = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'anna@example.org' ], 'crm-clients/invite' ) )[0] === 409 && $r[1]['code'] === 'crm_clients_taken' && count( $mails ) === 1 );
check( 'a bad address is 400 crm_clients_email', ( $r = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'nope' ], 'crm-clients/invite' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_email' && $r[1]['field'] === 'email' );
check( 'a name over 120 characters is 400 crm_clients_long', ( $r = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'long@example.org', 'name' => str_repeat( 'n', 121 ) ], 'crm-clients/invite' ) )[0] === 400 && $r[1]['code'] === 'crm_clients_long' );
check( 'a client nobody has is 404', ninoCall( $appData, $panel, [ 'id' => '999', 'email' => 'x@example.org' ], 'crm-clients/invite' )[0] === 404 );
check( 'a client that is not active is 409 crm_clients_inactive and gets no account', ( $r = ninoCall( $appData, $panel, [ 'id' => (string) $second['id'], 'email' => 'ben@example.org' ], 'crm-clients/invite' ) )[0] === 409 && $r[1]['code'] === 'crm_clients_inactive'
	&& count( \Nino\Modules\Members::accounts( $appData ) ) === 1 );

check( '...also the one of an invitation', \Crm\Clients\Admin::log( 'crm-clients/invite', [ 'id' => '1', 'email' => 'a@example.org' ] ) === 'Invited an account for client K-0001' );

// --- The status and the setup ----------------------------------------------------------------------------------------

echo "\nStatus and setup\n";

$stateOf = static function( array $body, string $key ): string {
	foreach( $body['items'] as $item )
		if( $item['key'] === $key )
			return $item['state'];
	return '';
};

[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/status' );
check( 'status names the module, the data directory, the five features and the portal', $status === 200 && array_column( $body['items'], 'key' ) === [ 'module', 'data', 'members', 'files', 'review', 'sign', 'pdf', 'portal' ] );
check( 'the module and the features are ok, the portal is not set up yet', $stateOf( $body, 'module' ) === 'ok' && $stateOf( $body, 'data' ) === 'ok' && $stateOf( $body, 'members' ) === 'ok' && $stateOf( $body, 'sign' ) === 'ok' && $stateOf( $body, 'portal' ) === 'none' );

$modulesNow = $appData['/nino/modules'];
$appData['/nino/modules'] = array_values( array_filter( $modulesNow, static fn( string $class ): bool => ltrim( $class, '\\' ) !== \Crm\Crm::class ) );
check( 'status says when the module is not listed in /nino/modules', $stateOf( ninoCall( $appData, [ \Crm\Clients\Admin::class, 'apiStatus' ] )[1], 'module' ) === 'off' );
$appData['/nino/modules'] = $modulesNow;

\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {
	$content['/nino/http/routes']['GET://own'] = [ 'uri' => '/own', 'body' => 'mine' ];
	return $content;
} );
$routesBefore = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
check( 'nothing of the portal exists before the button: no route, no template, no protected path', isset( $routesBefore['GET://portal'] ) === false && isset( $appData['/nino/http/routes']['GET://portal'] ) === false
	&& is_file( \Nino\Filesystem::path( $appData, '/templates/page-portal.tpl' ) ) === false && \Nino\Features::setting( $appData, 'members', 'paths', [] ) === [] );

[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
$manifest = \Nino\Features::readUnitManifest( $root. '/app/Crm/unit' );
check( 'setup applies the unit and says what it added', $status === 200 && count( $body['added']['routes'] ) === 6 && count( $body['added']['templates'] ) === 6 && $body['added']['texts'] > 20 && $body['added']['paths'] === true
	&& $body['existed']['routes'] === [] && $body['existed']['templates'] === [] );
check( 'the routes are in the persisted routes and in the live ones', array_diff( array_keys( $manifest['routes'] ), array_keys( \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) ) ) === [] && array_diff( array_keys( $manifest['routes'] ), array_keys( $appData['/nino/http/routes'] ) ) === [] );
check( 'the templates are copied byte for byte', array_filter( $manifest['templates'], static fn( string $file ): bool => file_get_contents( \Nino\Filesystem::path( $appData, '/templates/'. $file ) ) !== file_get_contents( $root. '/app/Crm/unit/templates/'. $file ) ) === [] );
check( 'the words are in both languages', array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/template/page-portal/intro/title]]'] ?? '' ) ) === '' ) === [] );
check( 'Members sends a member who signs in to /portal', $body['added']['start'] === true && \Nino\Features::setting( $appData, 'members', 'start', '' ) === '/portal' );
check( 'Members protects /portal afterwards', \Nino\Features::setting( $appData, 'members', 'paths', [] ) === [ '/portal' ] && \Nino\Modules\Members::protects( $appData, '/portal/dateien' ) === true );
check( 'the other routes of the project survive', isset( $routesBefore['GET://own'] ) && array_diff_key( $routesBefore, \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) ) === [] );
// The Builder reads every page of the unit: each is a list of sections, none an html block it cannot take apart
$registry = \Nino\Modules\Builder\Document::schemas( $appData );
$unread = [];

foreach( $manifest['templates'] as $file ) {
	$blocks = \Nino\Modules\Builder\Reader::read( (string) file_get_contents( $root. '/app/Crm/unit/templates/'. $file ), $registry )['blocks'];

	if( $blocks === [] || array_unique( array_column( $blocks, 'kind' ) ) !== [ 'section' ] )
		$unread[] = $file;
}

check( 'the Builder reads every page of the unit as sections: '. implode( ', ', $unread ), $unread === [] && count( $manifest['templates'] ) === 6 );
$styles = (string) file_get_contents( $root. '/_nino/Nino.css' );
$classes = [];

foreach( $manifest['templates'] as $file )
	if( preg_match_all( '~class="([^"]*)"~', (string) file_get_contents( $root. '/app/Crm/unit/templates/'. $file ), $found ) > 0 )
		foreach( $found[1] as $list )
			foreach( preg_split( '/\s+/', $list ) ?: [] as $class )
				if( $class !== '' && str_contains( $styles, '.'. $class ) === false )
					$classes[$class] = true;

check( 'the pages carry only classes the stylesheet of the kernel knows: '. implode( ', ', array_keys( $classes ) ), $classes === [] );
check( 'status now says the portal is ready', $stateOf( ninoCall( $appData, $panel, [], 'crm-clients/status' )[1], 'portal' ) === 'ok' );

// Add-only: what the project changed stays, a route is not written twice, a second press adds nothing
$pageFile = \Nino\Filesystem::path( $appData, '/templates/page-portal.tpl' );
file_put_contents( $pageFile, "<!-- the project's own -->\n". (string) file_get_contents( $pageFile ) );
$changed = (string) file_get_contents( $pageFile );
$textFile = \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] );
$textFile['[[/template/page-portal/intro/title]]'] = 'Mein eigener Titel';
\Nino\Filesystem::putFileContent( $appData, '/text/de_DE.php', $textFile );
\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {
	$content['/nino/http/routes']['GET://portal']['body'] = '[template /templates/page-own]';
	return $content;
} );
$routesAfter = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
check( 'a second setup adds nothing and says what was there', $status === 200 && $body['added']['routes'] === [] && $body['added']['templates'] === [] && $body['added']['texts'] === 0 && $body['added']['paths'] === false && $body['added']['start'] === false
	&& count( $body['existed']['routes'] ) === 6 && count( $body['existed']['templates'] ) === 6 );
check( '...a template the project changed survives it', (string) file_get_contents( $pageFile ) === $changed );
check( '...so does a word the project changed', \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] )['[[/template/page-portal/intro/title]]'] === 'Mein eigener Titel' );
check( '...and the routes are the same, a route the project changed is not written again', \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) === $routesAfter && \Nino\AppData::stored( $appData, '/nino/http/routes', [] )['GET://portal']['body'] === '[template /templates/page-own]' );
\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {
	$content['/nino/http/routes']['GET://portal']['body'] = '[template /templates/page-portal]';
	return $content;
} );

// A project that protects paths and has a start page already keeps them
\Nino\Features::saveSettings( $appData, 'members', [ 'paths' => [ '/club' ], 'start' => '/club' ] );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
check( 'where the project protects a path, setup leaves the paths as they are', $status === 200 && $body['added']['paths'] === false && $body['existed']['paths'] === true && $body['added']['start'] === false && $body['existed']['start'] === true && \Nino\Features::setting( $appData, 'members', 'paths', [] ) === [ '/club' ] && \Nino\Features::setting( $appData, 'members', 'start', '' ) === '/club' );
check( '...and status says the portal is only partly there', $stateOf( ninoCall( $appData, $panel, [], 'crm-clients/status' )[1], 'portal' ) === 'partial' );
\Nino\Features::saveSettings( $appData, 'members', [ 'paths' => [], 'start' => '/' ] );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
check( 'with no protected path and the default start page left, setup sets /portal again', $status === 200 && $body['added']['paths'] === true && $body['added']['start'] === true && \Nino\Features::setting( $appData, 'members', 'paths', [] ) === [ '/portal' ] );

// --- The portal ------------------------------------------------------------------------------------------------------

echo "\nThe portal\n";

function crmServe( array &$appData, string $uri, string $method = 'GET', array $post = [] ): array {

	$_POST = $post + ( $method === 'POST' ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method );
	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return [ $request['/nino/http/response']['statusCode'], (string) ( $request['/nino/http/response']['header']['Location'] ?? '' ), (string) ( $request['/nino/http/response']['body'] ?? '' ) ];
}

function crmConfirm( array &$appData, string $token ): array {

	crmServe( $appData, '/.members/login?t='. $token );

	return crmServe( $appData, '/.members/confirm', 'POST', [ 'token' => $token ] );
}

/**
 *	A page of the site as the output makes it: served, the fills of the request put in, the body rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *
 *	@return 	string
 */
function crmPage( array &$appData, string $uri ): string {

	$request = ninoVisit( $appData, $uri );
	\Nino\Http::response( $appData, $request );
	\Nino\Html::addFills( $appData, \Nino\Html::requestFills( $request, '' ), '*' );

	return \Nino\Html::renderHtml( $appData, (string) $request['/nino/http/response']['body'] );
}

// The frames of the site (the head the pages are rendered with) come from the wizard's base unit
$baseRoutes = [];
$baseBlacklist = [];
$baseConfig = [];
\Nino\Features::applyUnit( $appData, $root. '/_admin/install/library/base', [ 'de_DE', 'en_US' ], $baseRoutes, $baseBlacklist, $baseConfig, true );

unset( $appData['./nino/auth/current'] );
$_SESSION = [];

check( 'a visitor asking for the portal is shown the login page of Members: 401', crmServe( $appData, '/portal' )[0] === 401 && crmServe( $appData, '/portal' )[2] === '[template /templates/page-members-login]' );
check( '...and so on every page below it', array_unique( array_map( static fn( string $path ): int => crmServe( $appData, $path )[0], [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/konto' ] ) ) === [ 401 ] );
check( 'the login page has its own address', crmServe( $appData, '/login' )[0] === 200 && crmServe( $appData, '/login' )[2] === '[template /templates/page-login]' );
$loginHtml = \Nino\Html::renderHtml( $appData, crmServe( $appData, '/login' )[2] );
check( '...and carries the login form', str_contains( $loginHtml, '[members-login]' ) === false && str_contains( $loginHtml, '<form class="members-login' ) === true && str_contains( $loginHtml, 'Anmelden' ) === true );
$loginPage = crmPage( $appData, '/login' );
check( 'the head of /login holds its title and its description and no key of the page details', str_contains( $loginPage, '[[/_nino/' ) === false && str_contains( $loginPage, '<title>Anmelden | ' ) === true && str_contains( $loginPage, 'content="Melde Dich mit dem Link an' ) === true );
check( 'a visitor gets nothing from [crm-client]', trim( \Nino\Html::renderHtml( $appData, '[crm-client]' ) ) === '' );

// The invited member follows the link of the mail and presses the button
$answer = crmConfirm( $appData, $inviteToken );
check( 'the invited member signs in with the link of the invitation', $answer[0] === 303 && ( \Nino\Modules\Members::current( $appData )['email'] ?? '' ) === 'anna@example.org' );

$rendered = \Nino\Html::renderHtml( $appData, '[crm-client]' );
check( '[crm-client] answers the company of the client of the member', trim( $rendered ) === '<span class="crm-client">Studio Nord AG</span>' );
check( '...with a class of its own where the shortcode gives one', str_contains( \Nino\Html::renderHtml( $appData, '[crm-client class="big"]' ), 'class="crm-client big"' ) === true );

$page = crmServe( $appData, '/portal' );
check( 'the member is served the portal: 200', $page[0] === 200 && $page[2] === '[template /templates/page-portal]' );
$html = \Nino\Html::renderHtml( $appData, $page[2] );
check( '...with the name of the member, the company and the links to the pages', str_contains( $html, 'Anna Nord' ) === true && str_contains( $html, 'Studio Nord AG' ) === true
	&& substr_count( $html, 'href="/portal/' ) === 4 && str_contains( $html, 'Mein eigener Titel' ) === true );
$portalPage = crmPage( $appData, '/portal' );
check( 'the head of /portal holds its title and its description and no key of the page details', str_contains( $portalPage, '[[/_nino/' ) === false && str_contains( $portalPage, '<title>Kundenportal | ' ) === true );
check( 'no page of the portal shows a key of its details', array_filter( [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/konto' ], static fn( string $path ): bool => str_contains( crmPage( $appData, $path ), '[[/_nino/' ) === true ) === [] );
check( 'every page of the portal is served to the member', array_unique( array_map( static fn( string $path ): int => crmServe( $appData, $path )[0], [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/konto' ] ) ) === [ 200 ] );
check( 'the files page draws the browser of Files, the review page the player, the signatures page the list', str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/dateien' )[2] ), '[files-browser]' ) === false && str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/dateien' )[2] ), '<div class="files-browser' ) === true
	&& str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/review' )[2] ), '[review-player]' ) === false
	&& str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/unterschriften' )[2] ), '[sign-list]' ) === false );
check( 'the account page shows the name and the sign-out link', str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/konto' )[2] ), '[members-logout]' ) === false && str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/konto' )[2] ), '<a href="/.members/logout" class="members-logout' ) === true );

// A member whose account no client owns, and a client that is switched off
$stray = \Nino\Modules\Members::add( $appData, 'stray@example.org', 'Stray', 'nogroup' );
check( 'a member without a client gets nothing from [crm-client]', is_array( $stray ) && \Crm\Clients::ofMember( $appData, $stray['id'] ) === null );

\Crm\Db::close();
check( 'the lookup survives a new connection: the same client again', ( \Crm\Portal::client( $appData )['number'] ?? '' ) === 'K-0001' );

// The cache of the request is dropped when a member signs in: another member after this one is not answered for this one
$_SESSION = [];
$otherLink = (static function( array &$appData, array $account ): string {
	global $mails;
	$count = count( $mails );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	\Nino\Modules\Members::link( $appData, $account, 'login' );
	return count( $mails ) === $count + 1 && preg_match( '~t=([A-Za-z0-9_-]+)"~', (string) crmMail( $mails, $count )['body'], $found ) === 1 ? $found[1] : '';
})( $appData, $stray );
crmConfirm( $appData, $otherLink );
check( 'after another member signs in, [crm-client] is nothing for the member without a client', trim( \Nino\Html::renderHtml( $appData, '[crm-client]' ) ) === '' );

// Registered one way, with the schema the plan names
$schema = \Nino\Modules\Components::components( $appData )['crm-client'] ?? [];
check( 'the component is registered once: global, no source, drawn as a span', ( $schema['group'] ?? '' ) === 'global' && ( $schema['source'] ?? '' ) === 'none' && ( $schema['preview'] ?? '' ) === 'text' && ( $schema['draws'] ?? '' ) === 'span .crm-client'
	&& count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/crm-client' ) ) === 1 );

// The injection of a company name
\Crm\Clients::change( $appData, $first['id'], [ 'company' => '<b>[members-name]</b>' ] );
$_SESSION = [];
crmConfirm( $appData, (static function( array &$appData ): string {
	global $mails, $anna;
	$count = count( $mails );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	\Nino\Modules\Members::link( $appData, $anna, 'login' );
	return preg_match( '~t=([A-Za-z0-9_-]+)"~', (string) crmMail( $mails, $count )['body'], $found ) === 1 ? $found[1] : '';
})( $appData ) );
$evil = \Nino\Html::renderHtml( $appData, '[crm-client]' );
check( 'a company with markup and a shortcode in it is text, not markup and not a shortcode', str_contains( $evil, '<b>' ) === false && str_contains( $evil, '&lt;b&gt;' ) === true && str_contains( $evil, 'Anna Nord' ) === false );

// A status is a read: the kernel's guard makes its own data/.locks in any project, so the pin is on the method
// itself - it looks at the directory and makes none
preg_match( '~function apiStatus\(.*?\n\t}\n~s', (string) file_get_contents( $root. '/app/Crm/Clients/Admin/Admin.php' ), $statusSource );
check( 'status writes nothing: no directory made, no file put', $statusSource !== [] && preg_match( '~forceDir|mkdir|putFileContent|mutate\(|saveSettings~', $statusSource[0] ) !== 1 );

// --- What the module tells when something is missing ---------------------------------------------------------------

echo "\nA feature that is off\n";

\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );
$delivery = false;
$cleared = ninoWarnings();
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $five['id'], 'email' => 'mitte@example.org', 'name' => 'Mitte' ], 'crm-clients/invite' );
check( 'an invitation the mail transport refuses still makes the account, and says it was not mailed', $status === 200 && $body['mailed'] === false && count( \Crm\Clients::accounts( $appData, $five['id'] ) ) === 1 && ninoWarnings() !== [] );
$delivery = true;

foreach( [ 'sign', 'review', 'files' ] as $key )
	\Nino\Features::deactivate( $appData, $key );

[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/status' );
check( 'status names the features that are off, and the rest stays ok', $stateOf( $body, 'sign' ) === 'off' && $stateOf( $body, 'review' ) === 'off' && $stateOf( $body, 'files' ) === 'off' && $stateOf( $body, 'members' ) === 'ok' && $stateOf( $body, 'pdf' ) === 'ok' );

\Nino\Features::deactivate( $appData, 'members' );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/status' );
check( 'status names Members when it is off', $stateOf( $body, 'members' ) === 'off' );
check( 'with Members off, the portal knows no client, however the session stands', \Crm\Portal::client( $appData ) === null );
$mailsBefore = count( $mails );
$routesNow = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
check( 'setup without Members is 409 crm_clients_members and writes nothing', $status === 409 && $body['code'] === 'crm_clients_members' && \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) === $routesNow );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'id' => (string) $first['id'], 'email' => 'late@example.org' ], 'crm-clients/invite' );
$mailsAfter = count( $mails );
check( 'an invitation without Members is 409 crm_clients_members', $status === 409 && $body['code'] === 'crm_clients_members' && $mailsAfter === $mailsBefore && count( \Nino\Modules\Members::accounts( $appData ) ) === 3 );

// --- The script of the panel ---------------------------------------------------------------------------------------------

echo "\nThe script of the panel\n";

$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

if( $node === '' )
	echo "  note - node is not on the path: the suite of the panel's script was not run\n";
else {
	$output = (string) shell_exec( escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/crm-js-smoke.js' ). ' 2>&1' );
	$ran = preg_match( '/(\d+) checks, (\d+) failed/', $output, $counts ) === 1;

	check( 'crm-js-smoke.js passes ('. ( $ran ? $counts[1]. ' checks' : 'no summary' ). ')', $ran && $counts[2] === '0' && (int) $counts[1] >= 45 && str_contains( $output, 'FAIL' ) === false );
}

ninoDone( $appData );

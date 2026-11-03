<?php
declare(strict_types=1);

/**
 *	crm-smoke.php		Contract test for the project module \Crm\Crm: the SQLite layer and its migrations, the clients,
 *									the panel Kunden with its actions, the accounts of a client (made with it, invited, linked, sent a
 *									link again, switched, renamed, deleted; switched off with the client), the install unit
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

// Migration 0004: a database of the third schema whose account sits on two clients keeps the first link and gets the unique index
$third = ninoSandbox( 'crm-third' );
\Nino\Filesystem::forceDir( $third, '/data' );
$thirdFile = new \PDO( 'sqlite:'. \Nino\Filesystem::path( $third, \Crm\Db::FILE ) );

foreach( [ 1, 2, 3 ] as $number )
	foreach( (array) include \Crm\Db::migrations()[$number] as $statement )
		$thirdFile->exec( (string) $statement );

$thirdFile->exec( 'PRAGMA user_version = 3' );
$thirdFile->exec( 'INSERT INTO clients ( id, number, name, created, updated ) VALUES ( 1, \'K-0001\', \'A\', \'x\', \'x\' ), ( 2, \'K-0002\', \'B\', \'x\', \'x\' )' );
$thirdFile->exec( 'INSERT INTO client_accounts ( client_id, member_id ) VALUES ( 2, \'aaaaaaaaaaaa\' ), ( 1, \'aaaaaaaaaaaa\' ), ( 1, \'bbbbbbbbbbbb\' )' );
$thirdFile = null;
$migrated = \Crm\Db::pdo( $third );
$rows = \Crm\Db::rows( $third, 'SELECT client_id, member_id FROM client_accounts ORDER BY rowid' );
check( 'migrations 0004 and 0005 on a database of the third schema run once: version 5, the first link of an account stays, the later one goes', \Crm\Db::migrated() === 2 && (int) $migrated->query( 'PRAGMA user_version' )->fetchColumn() === 5
	&& array_map( 'json_encode', $rows ) === [ '{"client_id":2,"member_id":"aaaaaaaaaaaa"}', '{"client_id":1,"member_id":"bbbbbbbbbbbb"}' ] );
check( '...and the index that keeps an account on one client is unique', (int) \Crm\Db::row( $third, 'SELECT COUNT(*) AS n FROM sqlite_master WHERE type = \'index\' AND name = \'client_accounts_member_unique\' AND sql LIKE \'%UNIQUE%\'' )['n'] === 1 );
\Crm\Db::close();
\Crm\Db::pdo( $third );
check( '...a second boot runs nothing', \Crm\Db::migrated() === 0 );
\Crm\Db::close();

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
$thirdId = \Crm\Clients::list( $appData )[2]['id'];
check( 'an account of two clients belongs to the first: the link of the second is refused', \Crm\Clients::link( $appData, $thirdId, 'dddddddddddd' ) === false && ( \Crm\Clients::ofMember( $appData, 'dddddddddddd' )['number'] ?? '' ) === 'K-0002' && \Crm\Clients::accounts( $appData, $thirdId ) === [] );
$twoRows = '';

try {
	\Crm\Db::exec( $appData, 'INSERT INTO client_accounts ( client_id, member_id ) VALUES ( :c, \'dddddddddddd\' )', [ 'c' => $thirdId ] );
}
catch( \PDOException $e ) {
	$twoRows = 'refused';
}

check( '...the unique index refuses a second row of an account, whoever writes it', $twoRows === 'refused' && \Crm\Clients::link( $appData, $second['id'], 'dddddddddddd' ) === true );
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
check( 'its permission is /_admin/crm/manage, the one of the whole CRM', \Crm\Clients\Admin::perm() === '/_admin/crm/manage' && ( new \ReflectionClass( \Crm\Crm::class ) )->getConstant( 'MANAGE_PERM' ) === '/_admin/crm/manage' && ( $panels['crm-clients']['perm'] ?? '' ) === '/_admin/crm/manage' );
check( 'its actions are the fourteen of the plan', array_keys( \Crm\Clients\Admin::actions() ) === [ 'crm-clients/list', 'crm-clients/get', 'crm-clients/save', 'crm-clients/active', 'crm-clients/invite', 'crm-clients/accounts', 'crm-clients/unlinked',
	'crm-clients/account-link', 'crm-clients/account-resend', 'crm-clients/account-active', 'crm-clients/account-name', 'crm-clients/account-remove', 'crm-clients/setup', 'crm-clients/status' ] );

$assets = \Crm\Clients\Admin::assets();
check( 'its assets, template and text directory are files of the module', count( $assets ) === 2 && array_filter( $assets, static fn( string $asset ): bool => is_file( $root. $asset ) === false ) === []
	&& is_file( $root. \Crm\Clients\Admin::template(). '.tpl' ) === true && is_file( $root. \Crm\Clients\Admin::text(). '/de_DE.php' ) === true && is_file( $root. \Crm\Clients\Admin::text(). '/en_US.php' ) === true );

$words = [ 'de_DE' => require $root. \Crm\Clients\Admin::text(). '/de_DE.php', 'en_US' => require $root. \Crm\Clients\Admin::text(). '/en_US.php' ];
check( 'the words are the same keys in German and English, none empty', array_keys( $words['de_DE'] ) === array_keys( $words['en_US'] ) && array_filter( $words['de_DE'] + $words['en_US'], static fn( string $text ): bool => trim( $text ) === '' ) === [] );

$source = (string) file_get_contents( $root. '/app/Crm/Clients/Admin/Admin.php' ). (string) file_get_contents( $root. '/app/Crm/Clients/Admin/assets/admin.js' );
preg_match_all( "~'(crm_clients_[a-z]+)'~", $source, $codes );
preg_match_all( "~/_admin/crm-clients/[a-z/-]+~", (string) file_get_contents( $root. '/app/Crm/Clients/Admin/assets/admin.js' ), $keys );
check( 'every code the panel answers has its text in both languages', array_filter( array_unique( $codes[1] ), static fn( string $code ): bool => isset( $words['de_DE']['[[/_admin/error/'. $code. ']]'], $words['en_US']['[[/_admin/error/'. $code. ']]'] ) === false ) === [] && count( array_unique( $codes[1] ) ) >= 10 );
check( 'every fill key the script asks for whole has its text', array_filter( array_unique( $keys[0] ), static fn( string $key ): bool => str_ends_with( $key, '/' ) === false && str_ends_with( $key, '-' ) === false && isset( $words['de_DE']['[['. $key. ']]'] ) === false ) === []
	&& array_filter( [ 'routes', 'templates', 'types', 'paths' ], static fn( string $kind ): bool => isset( $words['de_DE']['[[/_admin/crm-clients/status/lacks-'. $kind. ']]'], $words['en_US']['[[/_admin/crm-clients/status/lacks-'. $kind. ']]'] ) === false ) === [] );
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
	'crm-clients/invite' => [ 'id' => '1', 'email' => 'nobody@example.org' ], 'crm-clients/accounts' => [ 'id' => '1' ], 'crm-clients/unlinked' => [],
	'crm-clients/account-link' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa' ], 'crm-clients/account-resend' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa' ], 'crm-clients/account-active' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa' ],
	'crm-clients/account-name' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa', 'name' => 'Nobody' ], 'crm-clients/account-remove' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa' ], 'crm-clients/setup' => [], 'crm-clients/status' => [] ];
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
check( 'list answers the whole set, whatever is posted with it: the table of the panel searches', array_column( ninoCall( $appData, $panel, [ 'search' => 'nord' ], 'crm-clients/list' )[1]['clients'], 'number' ) === [ 'K-0001', 'K-0002', 'K-0003', 'K-0004' ] );

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
	&& is_file( \Nino\Filesystem::path( $appData, '/templates/page-portal.tpl' ) ) === false && \Nino\Features::setting( $appData, 'members', 'paths', [] ) === [] && \Crm\Positionen::missing( $appData ) === true );

[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
$manifest = \Nino\Features::readUnitManifest( $root. '/app/Crm/unit' );
check( 'setup applies the unit and says what it added', $status === 200 && count( $body['added']['routes'] ) === 7 && count( $body['added']['templates'] ) === 7 && $body['added']['texts'] > 20 && $body['added']['paths'] === true
	&& $body['existed']['routes'] === [] && $body['existed']['templates'] === [] );
check( '...and the element type of the items: it is among what was added, the file is there, and the unit names it', $body['added']['types'] === [ 'positionen.php' ] && $body['existed']['types'] === [] && \Crm\Positionen::missing( $appData ) === false
	&& ( $manifest['elementTypes'] ?? [] ) === [ 'positionen.php' ] && is_file( $root. '/app/Crm/unit/positionen.php' ) === true );
check( 'the routes are in the persisted routes and in the live ones', array_diff( array_keys( $manifest['routes'] ), array_keys( \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) ) ) === [] && array_diff( array_keys( $manifest['routes'] ), array_keys( $appData['/nino/http/routes'] ) ) === [] );
check( 'the unit has the page /portal/dokumente: its route, its template and its words in both languages', ( $manifest['routes']['GET://portal/dokumente']['body'] ?? '' ) === '[template /templates/page-portal-documents]' && in_array( 'page-portal-documents.tpl', $manifest['templates'], true ) === true
	&& isset( \Nino\AppData::stored( $appData, '/nino/http/routes', [] )['GET://portal/dokumente'] ) === true
	&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/_nino/webpage/portal/dokumente/title]]'] ?? '' ) ) === '' || trim( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/template/page-portal-documents/intro/title]]'] ?? '' ) ) === '' ) === [] );
$introWords = [ 'de_DE' => 'Verträge', 'en_US' => 'contracts' ];
check( 'the intro of the page names the contracts and the privacy documents that are signed there, in both languages', array_filter( $introWords, static fn( string $word, string $locale ): bool => str_contains( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/template/page-portal-documents/intro/text]]'] ?? '' ), $word ) === false
	|| stripos( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/template/page-portal-documents/intro/text]]'] ?? '' ), 'privacy' ) === false && stripos( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/template/page-portal-documents/intro/text]]'] ?? '' ), 'Datenschutz' ) === false, ARRAY_FILTER_USE_BOTH ) === [] );
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

check( 'the Builder reads every page of the unit as sections: '. implode( ', ', $unread ), $unread === [] && count( $manifest['templates'] ) === 7 );
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
	&& $body['added']['types'] === [] && count( $body['existed']['routes'] ) === 7 && count( $body['existed']['templates'] ) === 7 && $body['existed']['types'] === [ 'positionen.php' ] );
check( '...a template the project changed survives it', (string) file_get_contents( $pageFile ) === $changed );
check( '...so does a word the project changed', \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] )['[[/template/page-portal/intro/title]]'] === 'Mein eigener Titel' );
check( '...and the routes are the same, a route the project changed is not written again', \Nino\AppData::stored( $appData, '/nino/http/routes', [] ) === $routesAfter && \Nino\AppData::stored( $appData, '/nino/http/routes', [] )['GET://portal']['body'] === '[template /templates/page-own]' );
\Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ): array {
	$content['/nino/http/routes']['GET://portal']['body'] = '[template /templates/page-portal]';
	return $content;
} );

// The element type is a part of the portal: without it the status says so, and the button brings it again
unlink( \Nino\Filesystem::path( $appData, '/elements/positionen.php' ) );
check( 'a portal whose element type is gone is only partly there and says what it lacks, and the button is offered', $stateOf( ninoCall( $appData, $panel, [], 'crm-clients/status' )[1], 'portal' ) === 'partial' && array_column( ninoCall( $appData, $panel, [], 'crm-clients/status' )[1]['items'], 'lacks', 'key' )['portal'] === [ 'types' ] && \Crm\Positionen::missing( $appData ) === true );
[ $status, $body ] = ninoCall( $appData, $panel, [], 'crm-clients/setup' );
check( '...the setup adds the type and nothing else, and the portal is ok again', $status === 200 && $body['added']['types'] === [ 'positionen.php' ] && $body['added']['routes'] === [] && $body['added']['templates'] === [] && $body['added']['texts'] === 0
	&& $stateOf( ninoCall( $appData, $panel, [], 'crm-clients/status' )[1], 'portal' ) === 'ok' );

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
check( '...and so on every page below it', array_unique( array_map( static fn( string $path ): int => crmServe( $appData, $path )[0], [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/dokumente', '/portal/konto' ] ) ) === [ 401 ] );
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
	&& substr_count( $html, 'href="/portal/' ) === 5 && str_contains( $html, 'Mein eigener Titel' ) === true );
$portalPage = crmPage( $appData, '/portal' );
check( 'the head of /portal holds its title and its description and no key of the page details', str_contains( $portalPage, '[[/_nino/' ) === false && str_contains( $portalPage, '<title>Kundenportal | ' ) === true );
check( 'no page of the portal shows a key of its details', array_filter( [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/dokumente', '/portal/konto' ], static fn( string $path ): bool => str_contains( crmPage( $appData, $path ), '[[/_nino/' ) === true ) === [] );
check( 'every page of the portal is served to the member', array_unique( array_map( static fn( string $path ): int => crmServe( $appData, $path )[0], [ '/portal/dateien', '/portal/review', '/portal/unterschriften', '/portal/dokumente', '/portal/konto' ] ) ) === [ 200 ] );
check( 'the files page draws the browser of Files, the review page the player, the signatures page the list', str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/dateien' )[2] ), '[files-browser]' ) === false && str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/dateien' )[2] ), '<div class="files-browser' ) === true
	&& str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/review' )[2] ), '[review-player]' ) === false
	&& str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/unterschriften' )[2] ), '[sign-list]' ) === false );
check( 'the account page shows the name and the sign-out link', str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/konto' )[2] ), '[members-logout]' ) === false && str_contains( \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/konto' )[2] ), '<a href="/.members/logout" class="members-logout' ) === true );

$documentsPage = \Nino\Html::renderHtml( $appData, crmServe( $appData, '/portal/dokumente' )[2] );
check( 'the documents page draws the list of Documents, which says there are none while nothing is released', str_contains( $documentsPage, '[crm-documents]' ) === false && str_contains( $documentsPage, 'class="crm-documents crm-documents--empty"' ) === true && str_contains( $documentsPage, 'Für Dich liegen noch keine Dokumente bereit.' ) === true );
check( '/portal links to it', str_contains( $html, 'href="/portal/dokumente"' ) === true );
check( 'the module puts the style sheet of the list into the bundle of the site, once', count( array_keys( $appData['/nino/html/assets']['/.cache/style.css'] ?? [], \Crm\Portal::STYLE, true ) ) === 1 && is_file( $root. \Crm\Portal::STYLE ) === true );

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

// --- The accounts of the clients -------------------------------------------------------------------------------------

echo "\nThe accounts of the clients\n";

\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );

$call = static function( string $action, array $data ) use ( &$appData, $panel ): array {
	return ninoCall( $appData, $panel, $data, $action );
};
$resetCap = static function() use ( &$appData ): void {
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
};
$lastToken = static function() use ( &$appData ): array {
	$tokens = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/data/members-tokens.php', [] ) );
	return $tokens === [] ? [] : (array) $tokens[count( $tokens ) - 1];
};
$memberOf = static function( string $id ) use ( &$appData ): array {
	return \Nino\Modules\Members::account( $appData, $id ) ?? [];
};
$isNull = static fn( array $from, string $key ): bool => array_key_exists( $key, $from ) === true && $from[$key] === null;
$membersBefore = count( \Nino\Modules\Members::accounts( $appData ) );
$delivery = true;
$resetCap();
$mails->exchangeArray( [] );
$cleared = ninoWarnings();

// Made with the first account
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Konto GmbH', 'account_email' => ' Neu@Example.org ', 'account_name' => 'Neu Person' ] );
$kg = (array) ( $body['client'] ?? [] );
$neu = (array) ( $body['first']['account'] ?? [] );
check( 'save without an id and with an address makes the client and its first account: in the group of the client, linked, active, with the invitation mailed', $status === 200 && ( $kg['number'] ?? '' ) === 'K-0006' && ( $body['first']['mailed'] ?? null ) === true && $isNull( (array) $body['first'], 'refused' )
	&& ( $neu['email'] ?? '' ) === 'neu@example.org' && ( $neu['name'] ?? '' ) === 'Neu Person' && ( $neu['state'] ?? '' ) === 'invited' && ( $memberOf( (string) $neu['id'] )['group'] ?? '' ) === 'k-0006'
	&& \Crm\Clients::accounts( $appData, (int) $kg['id'] ) === [ $neu['id'] ] && count( $mails ) === 1 && ( crmMail( $mails, 0 )['to'] ?? '' ) === 'neu@example.org' );
check( '...the link of the mail is an invitation that leads to the portal', ( $lastToken()['kind'] ?? '' ) === 'invite' && ( $lastToken()['target'] ?? '' ) === '/portal' && preg_match( '~href="https://example\.org/club/\.members/login\?t=([A-Za-z0-9_-]+)"~', (string) ( crmMail( $mails, 0 )['body'] ?? '' ), $found ) === 1 );
$neuToken = $found[1] ?? '';
check( '...the client is counted with one account, one of them active', ( \Crm\Clients::get( $appData, (int) $kg['id'] )['accounts'] ?? 0 ) === 1 && ( \Crm\Clients::get( $appData, (int) $kg['id'] )['active_accounts'] ?? 0 ) === 1 );
check( '...the log line names the client and no address', \Crm\Clients\Admin::log( 'crm-clients/save', [ 'account_email' => 'neu@example.org' ] ) === 'Added client K-0006' );

[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Allein GmbH' ] );
check( 'without an address the client is made alone: no first in the answer, no account, no mail', $status === 200 && array_key_exists( 'first', $body ) === false && \Crm\Clients::accounts( $appData, (int) $body['client']['id'] ) === [] && count( $mails ) === 1 );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Leer GmbH', 'account_email' => '  ', 'account_name' => 'Nur Name' ] );
check( 'a name without an address is no account either', $status === 200 && array_key_exists( 'first', $body ) === false && count( $mails ) === 1 && count( \Nino\Modules\Members::accounts( $appData ) ) === $membersBefore + 1 );
$alone = \Crm\Clients::add( $appData, [ 'name' => 'Model Alone' ], [ 'email' => '', 'name' => '' ] );
check( 'the model too: add() with an empty address makes the client alone', is_array( $alone ) && array_key_exists( 'first', $alone ) === false );

$clients = count( \Crm\Clients::list( $appData ) );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Falsch GmbH', 'account_email' => 'not-an-address', 'account_name' => 'X' ] );
check( 'an invalid address makes the client and refuses the account with its code: 200, the client, no account, crm_clients_email on email, no mail', $status === 200 && count( \Crm\Clients::list( $appData ) ) === $clients + 1 && $isNull( (array) $body['first'], 'account' ) && ( $body['first']['mailed'] ?? null ) === false
	&& ( $body['first']['refused'] ?? [] ) === [ 'code' => 'crm_clients_email', 'params' => [], 'field' => 'email' ] && \Crm\Clients::accounts( $appData, (int) $body['client']['id'] ) === [] && count( $mails ) === 1 );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Doppelt GmbH', 'account_email' => 'neu@example.org' ] );
check( 'an address that is an account already: the client is made, the account refused with crm_clients_taken', $status === 200 && ( $body['first']['refused']['code'] ?? '' ) === 'crm_clients_taken' && $isNull( (array) $body['first'], 'account' ) && count( \Nino\Modules\Members::accounts( $appData ) ) === $membersBefore + 1 );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Lang GmbH', 'account_email' => 'lang@example.org', 'account_name' => str_repeat( 'n', 121 ) ] );
check( 'a name over 120 characters: the client is made, the account refused with crm_clients_long and its limit', $status === 200 && ( $body['first']['refused'] ?? [] ) === [ 'code' => 'crm_clients_long', 'params' => [ 120 ], 'field' => 'name' ] && count( \Nino\Modules\Members::accounts( $appData ) ) === $membersBefore + 1 );
$clients = count( \Crm\Clients::list( $appData ) );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Kein Text GmbH', 'account_email' => [ 'x' ] ] );
check( 'an address that is no text is 400 crm_clients_format on account_email and makes nothing', $status === 400 && $body['code'] === 'crm_clients_format' && $body['field'] === 'account_email' && count( \Crm\Clients::list( $appData ) ) === $clients );
[ $status, $body ] = $call( 'crm-clients/save', [ 'id' => (string) $kg['id'], 'city' => 'Köln', 'account_email' => 'ignored@example.org' ] );
check( 'a client that exists is changed, and the first account is not made again', $status === 200 && array_key_exists( 'first', $body ) === false && $body['client']['city'] === 'Köln' && count( \Crm\Clients::accounts( $appData, (int) $kg['id'] ) ) === 1 );

$delivery = false;
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Ohne Post GmbH', 'account_email' => 'nopost@example.org' ] );
$delivery = true;
check( 'an invitation the transport refuses leaves the client and the account, and says it was not mailed', $status === 200 && ( $body['first']['mailed'] ?? null ) === false && ( $body['first']['account']['email'] ?? '' ) === 'nopost@example.org' && $isNull( (array) $body['first'], 'refused' ) );
$cleared = ninoWarnings();

\Crm\Db::exec( $appData, 'CREATE TRIGGER no_links_either BEFORE INSERT ON client_accounts BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
$members = count( \Nino\Modules\Members::accounts( $appData ) );
[ $status, $body ] = $call( 'crm-clients/save', [ 'company' => 'Kaputt GmbH', 'account_email' => 'kaputt@example.org' ] );
\Crm\Db::exec( $appData, 'DROP TRIGGER no_links_either' );
check( 'an account that cannot be linked is taken back; the client is made and the answer says crm_clients_database', $status === 200 && ( $body['first']['refused']['code'] ?? '' ) === 'crm_clients_database' && count( \Nino\Modules\Members::accounts( $appData ) ) === $members );

// Linking an account that exists
$freeA = \Nino\Modules\Members::add( $appData, 'free-a@example.org', 'Frieda Frei', '' );
$freeB = \Nino\Modules\Members::add( $appData, 'free-b@example.org', '', 'staff' );
$freeA = is_array( $freeA ) ? $freeA : [ 'id' => '' ];
$freeB = is_array( $freeB ) ? $freeB : [ 'id' => '' ];
[ $status, $body ] = $call( 'crm-clients/unlinked', [] );
$offered = array_column( (array) ( $body['accounts'] ?? [] ), null, 'id' );
check( 'unlinked lists the accounts of Members that no client holds, by id, address, name and group and nothing else', $status === 200 && isset( $offered[$freeA['id']], $offered[$freeB['id']], $offered[$stray['id']] ) && array_keys( $offered[$freeA['id']] ) === [ 'id', 'email', 'name', 'group' ]
	&& $offered[$freeB['id']]['group'] === 'staff' && $offered[$freeA['id']]['name'] === 'Frieda Frei' );
check( '...and none that a client holds', isset( $offered[$neu['id']] ) === false && isset( $offered[$anna['id']] ) === false && count( $offered ) === 3 );

$mailsNow = count( $mails );
[ $status, $body ] = $call( 'crm-clients/account-link', [ 'id' => (string) $kg['id'], 'account' => $freeA['id'] ] );
check( 'linking moves the account into the group of the client and links it: the answer is the account with its state', $status === 200 && ( $body['account']['id'] ?? '' ) === $freeA['id'] && ( $body['account']['state'] ?? '' ) === 'invited' && ( $memberOf( $freeA['id'] )['group'] ?? '' ) === 'k-0006'
	&& \Crm\Clients::accounts( $appData, (int) $kg['id'] ) === [ $neu['id'], $freeA['id'] ] && count( $mails ) === $mailsNow );
check( '...the log line names the client and the account', \Crm\Clients\Admin::log( 'crm-clients/account-link', [ 'id' => (string) $kg['id'], 'account' => $freeA['id'], 'email' => 'free-a@example.org' ] ) === 'Linked account '. $freeA['id']. ' to client K-0006' );
check( '...and it is not offered any more', isset( array_column( (array) $call( 'crm-clients/unlinked', [] )[1]['accounts'], null, 'id' )[$freeA['id']] ) === false );

$other = (array) \Crm\Clients::add( $appData, [ 'company' => 'Anderer GmbH' ] );
[ $status, $body ] = $call( 'crm-clients/account-link', [ 'id' => (string) $other['id'], 'account' => $freeA['id'] ] );
check( 'an account a client holds cannot be linked to another: 409 crm_clients_held, the group and the rows stay', $status === 409 && $body['code'] === 'crm_clients_held' && $memberOf( $freeA['id'] )['group'] === 'k-0006' && \Crm\Clients::accounts( $appData, (int) $other['id'] ) === [] );
check( '...nor again to the client that holds it', $call( 'crm-clients/account-link', [ 'id' => (string) $kg['id'], 'account' => $freeA['id'] ] )[1]['code'] === 'crm_clients_held' );
check( '...and the model says so too: link() is false', \Crm\Clients::link( $appData, (int) $other['id'], $freeA['id'] ) === false && \Crm\Clients::linkExisting( $appData, (int) $other['id'], $freeA['id'] ) === 'held' );
check( 'an account that Members has not got is 404 crm_clients_member', ( $r = $call( 'crm-clients/account-link', [ 'id' => (string) $kg['id'], 'account' => 'eeeeeeeeeeee' ] ) )[0] === 404 && $r[1]['code'] === 'crm_clients_member' );
check( 'an id that is no account id is 400 crm_clients_account on account', ( $r = $call( 'crm-clients/account-link', [ 'id' => (string) $kg['id'], 'account' => 'nope' ] ) )[0] === 400 && $r[1]['code'] === 'crm_clients_account' && $r[1]['field'] === 'account' );
check( 'a client nobody has is 404 crm_clients_unknown', ( $r = $call( 'crm-clients/account-link', [ 'id' => '999', 'account' => $freeB['id'] ] ) )[0] === 404 && $r[1]['code'] === 'crm_clients_unknown' );
[ $status, $body ] = $call( 'crm-clients/account-link', [ 'id' => (string) $second['id'], 'account' => $freeB['id'] ] );
check( 'a client that is not active takes no account: 409 crm_clients_inactive, the group stays', $status === 409 && $body['code'] === 'crm_clients_inactive' && $memberOf( $freeB['id'] )['group'] === 'staff' );

// An account moved to another group in the Members panel is no longer the client's
$kgBefore = \Crm\Clients::get( $appData, (int) $kg['id'] ) ?? [];
\Nino\Modules\Members::change( $appData, $freeA['id'], [ 'group' => 'anderswo' ] );
check( 'an account moved to another group in Members is no longer the client\'s: not among its accounts, not counted, and the portal knows it for no client', \Crm\Clients::accounts( $appData, (int) $kg['id'] ) === [ $neu['id'] ] && ( \Crm\Clients::get( $appData, (int) $kg['id'] )['accounts'] ?? 0 ) === ( $kgBefore['accounts'] ?? 0 ) - 1
	&& \Crm\Clients::ofMember( $appData, $freeA['id'] ) === null && \Crm\Clients::accountOf( $appData, (int) $kg['id'], $freeA['id'] ) === null );
check( '...it is offered again to be linked', isset( array_column( (array) $call( 'crm-clients/unlinked', [] )[1]['accounts'], null, 'id' )[$freeA['id']] ) === true );
check( '...an action on it through the card is 404 crm_clients_member', $call( 'crm-clients/account-remove', [ 'id' => (string) $kg['id'], 'account' => $freeA['id'] ] )[1]['code'] === 'crm_clients_member' && $memberOf( $freeA['id'] ) !== [] );
[ $status, $body ] = $call( 'crm-clients/account-link', [ 'id' => (string) $other['id'], 'account' => $freeA['id'] ] );
check( '...and another client can take it: the stale row goes, the group is the new client\'s', $status === 200 && $memberOf( $freeA['id'] )['group'] === $other['group'] && \Crm\Clients::accounts( $appData, (int) $other['id'] ) === [ $freeA['id'] ] && (int) \Crm\Db::row( $appData, 'SELECT COUNT(*) AS n FROM client_accounts WHERE member_id = :m', [ 'm' => $freeA['id'] ] )['n'] === 1 );
$unmoved = \Nino\Modules\Members::add( $appData, 'in-group@example.org', '', $kg['group'] );
check( 'an account that Members has in the group of a client without a row is no one\'s until it is linked: it is offered, and linking it moves nothing', is_array( $unmoved ) && isset( array_column( \Crm\Clients::unlinked( $appData ), null, 'id' )[$unmoved['id']] ) === true
	&& is_array( $linked = \Crm\Clients::linkExisting( $appData, (int) $kg['id'], $unmoved['id'] ) ) && $memberOf( $unmoved['id'] )['group'] === $kg['group'] );

// Sending the link again
$resetCap();
$mails->exchangeArray( [] );
[ $status, $body ] = $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );
check( 'an account that never signed in is sent the invitation again: kind invite, a mail to its address, a token of the invitation to the portal', $status === 200 && $body === [ 'sent' => true, 'kind' => 'invite' ] && count( $mails ) === 1 && crmMail( $mails, 0 )['to'] === 'neu@example.org'
	&& ( $lastToken()['kind'] ?? '' ) === 'invite' && ( $lastToken()['target'] ?? '' ) === '/portal' );
check( '...the log line names the client and the account', \Crm\Clients\Admin::log( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] ) === 'Sent a link to account '. $neu['id']. ' of client K-0006' );

crmConfirm( $appData, $neuToken );
\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );
check( 'the account signs in with the invitation', ( $memberOf( (string) $neu['id'] )['login'] ?? null ) !== null );
$resetCap();
[ $status, $body ] = $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );
check( 'one that did gets a link to sign in: kind login, to the portal', $status === 200 && $body === [ 'sent' => true, 'kind' => 'login' ] && count( $mails ) === 2 && ( $lastToken()['kind'] ?? '' ) === 'login' && ( $lastToken()['target'] ?? '' ) === '/portal' );
check( '...and the card of the client has it as signed in', ( \Crm\Clients::accountOf( $appData, (int) $kg['id'], (string) $neu['id'] )['state'] ?? '' ) === 'signed' );

$resetCap();
$delivery = false;
[ $status, $body ] = $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );
$delivery = true;
check( 'a mail the transport refuses is 500 crm_clients_mail', $status === 500 && $body['code'] === 'crm_clients_mail' );
$cleared = ninoWarnings();
$resetCap();
$sent = [];

for( $round = 0; $round < 6; $round++ )
	$sent[] = $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );

check( 'the cap of five mails an hour is a 429 crm_clients_ratelimit, and no mail goes out then', array_column( $sent, 0 ) === [ 200, 200, 200, 200, 200, 429 ] && $sent[5][1]['code'] === 'crm_clients_ratelimit' );
check( '...and the sentence of the 429, in both languages, and the internal message name the kernel\'s cap and not its number', $sent[5][1]['error'] !== '' && preg_match( '/\b(five|fünf|5)\b/iu', $sent[5][1]['error'] ) !== 1
	&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => preg_match( '/\b(five|fünf|5)\b/iu', (string) ( require $root. '/app/Crm/Clients/Admin/text/'. $locale. '.php' )['[[/_admin/error/crm_clients_ratelimit]]'] ) !== 0 ) === [] );
$resetCap();
$cleared = ninoWarnings();

$mailsNow = count( $mails );
$call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'active' => false ] );
[ $status, $body ] = $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );
check( 'an account that is switched off is sent nothing: 409 crm_clients_off', $status === 409 && $body['code'] === 'crm_clients_off' && count( $mails ) === $mailsNow );
check( 'an account of another client is 404 crm_clients_member', ( $r = $call( 'crm-clients/account-resend', [ 'id' => (string) $other['id'], 'account' => (string) $neu['id'] ] ) )[0] === 404 && $r[1]['code'] === 'crm_clients_member' );
check( 'a bad id of an account is 400 crm_clients_account', $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => 'XYZ' ] )[1]['code'] === 'crm_clients_account' );

// Switching on and off
[ $status, $body ] = $call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] );
check( 'without a value the switch turns the account on again: Members has it on, the answer is the account', $status === 200 && $body['account']['active'] === true && $body['account']['state'] === 'signed' && $memberOf( (string) $neu['id'] )['active'] === true );
check( '...a value off turns it off and the card counts it', $call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'active' => false ] )[1]['account']['state'] === 'off' && ( \Crm\Clients::get( $appData, (int) $kg['id'] )['active_accounts'] ?? 9 ) === 1 );
check( '...the log line names the client and the account', \Crm\Clients\Admin::log( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] ) === 'Switched account '. $neu['id']. ' of client K-0006' );
$call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'active' => true ] );
check( 'an account that is not there is 404 crm_clients_member', $call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => 'eeeeeeeeeeee' ] )[1]['code'] === 'crm_clients_member' );

// The name
[ $status, $body ] = $call( 'crm-clients/account-name', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'name' => '  Neue Person ' ] );
check( 'the name is changed in Members, trimmed, and the answer is the account', $status === 200 && $body['account']['name'] === 'Neue Person' && $memberOf( (string) $neu['id'] )['name'] === 'Neue Person' && $memberOf( (string) $neu['id'] )['email'] === 'neu@example.org' );
check( '...a name over 120 characters is 400 crm_clients_long with its limit, the name stays', ( $r = $call( 'crm-clients/account-name', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'name' => str_repeat( 'n', 121 ) ] ) )[0] === 400 && $r[1]['code'] === 'crm_clients_long' && $r[1]['params'] === [ 120 ] && $r[1]['field'] === 'name' && $memberOf( (string) $neu['id'] )['name'] === 'Neue Person' );
check( '...an account of another client is 404 crm_clients_member', $call( 'crm-clients/account-name', [ 'id' => (string) $other['id'], 'account' => (string) $neu['id'], 'name' => 'X' ] )[1]['code'] === 'crm_clients_member' && $memberOf( (string) $neu['id'] )['name'] === 'Neue Person' );
check( '...and the log line says renamed', \Crm\Clients\Admin::log( 'crm-clients/account-name', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'name' => 'Neue Person' ] ) === 'Renamed account '. $neu['id']. ' of client K-0006' );

// Deactivating the client switches its accounts off
$offOne = \Crm\Clients::invite( $appData, $kg, 'already-off@example.org', '' );
$offId = is_array( $offOne ) ? (string) $offOne['account']['id'] : '';
$call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => $offId, 'active' => false ] );
$ghost = 'aaaaaaaaaaaa';
\Crm\Clients::link( $appData, (int) $kg['id'], 'f0f0f0f0f0f0' );
\Crm\Clients::link( $appData, (int) $kg['id'], $ghost );
[ $status, $body ] = $call( 'crm-clients/active', [ 'id' => (string) $kg['id'] ] );
$states = array_column( (array) ( $body['accounts'] ?? [] ), 'state', 'id' );
check( 'switching a client off switches its accounts off and says how many it switched; the account that was off is not counted, the rows that are gone are left alone', $status === 200 && $body['client']['active'] === false && ( $body['switched'] ?? -1 ) === 2
	&& $states[(string) $neu['id']] === 'off' && $states[$offId] === 'off' && $states[$unmoved['id']] === 'off' && ( $states['f0f0f0f0f0f0'] ?? '' ) === 'gone' );
check( '...in Members they are off, and the account that was moved away is not touched', $memberOf( (string) $neu['id'] )['active'] === false && $memberOf( $unmoved['id'] )['active'] === false && $memberOf( $freeA['id'] )['active'] === true );
check( '...the client counts no active account', ( \Crm\Clients::get( $appData, (int) $kg['id'] )['active_accounts'] ?? 9 ) === 0 );
[ $status, $body ] = $call( 'crm-clients/active', [ 'id' => (string) $kg['id'], 'active' => true ] );
check( 'switching it on does not switch the accounts on: the owner does, one by one', $status === 200 && $body['client']['active'] === true && $body['switched'] === 0 && array_unique( array_column( array_filter( $body['accounts'], static fn( array $a ): bool => $a['gone'] === false ), 'state' ) ) === [ 'off' ] );
check( 'the model answers it too: setActive() carries how many accounts it switched', ( \Crm\Clients::setActive( $appData, (int) $kg['id'], true )['switched'] ?? -1 ) === 0 );

$call( 'crm-clients/active', [ 'id' => (string) $kg['id'], 'active' => false ] );
[ $status, $body ] = $call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'active' => true ] );
check( 'an account of a client that is off is not switched on: 409 crm_clients_inactive', $status === 409 && $body['code'] === 'crm_clients_inactive' && $memberOf( (string) $neu['id'] )['active'] === false );
check( '...nor sent a link', $call( 'crm-clients/account-resend', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'] ] )[1]['code'] === 'crm_clients_inactive' );
$call( 'crm-clients/active', [ 'id' => (string) $kg['id'], 'active' => true ] );
$call( 'crm-clients/account-active', [ 'id' => (string) $kg['id'], 'account' => (string) $neu['id'], 'active' => true ] );

// Deleting
$invoice = \Crm\Documents::add( $appData, 'invoice', (int) $kg['id'] );
$countsBefore = \Crm\Documents::counts( $appData, (int) $kg['id'] );
// A document carries its client as the client is now, with the counts of its accounts: those change, the rest of it does not
$withoutClient = static fn( ?array $document ): array => array_diff_key( (array) $document, [ 'client' => true ] );
$documentBefore = $withoutClient( \Crm\Documents::get( $appData, (int) ( is_array( $invoice ) ? $invoice['id'] : 0 ) ) );
$membersNow = count( \Nino\Modules\Members::accounts( $appData ) );
[ $status, $body ] = $call( 'crm-clients/account-remove', [ 'id' => (string) $kg['id'], 'account' => $offId ] );
check( 'deleting an account removes it in Members and its row: the answer says removed', $status === 200 && $body === [ 'removed' => true ] && $memberOf( $offId ) === [] && count( \Nino\Modules\Members::accounts( $appData ) ) === $membersNow - 1
	&& in_array( $offId, \Crm\Clients::accounts( $appData, (int) $kg['id'] ), true ) === false && \Crm\Db::row( $appData, 'SELECT member_id FROM client_accounts WHERE member_id = :m', [ 'm' => $offId ] ) === null );
check( '...the documents of the client are not touched', is_array( $invoice ) && \Crm\Documents::counts( $appData, (int) $kg['id'] ) === $countsBefore && $withoutClient( \Crm\Documents::get( $appData, $invoice['id'] ) ) === $documentBefore );
check( '...the log line names the client and the account and no address', \Crm\Clients\Admin::log( 'crm-clients/account-remove', [ 'id' => (string) $kg['id'], 'account' => $offId, 'email' => 'already-off@example.org' ] ) === 'Deleted account '. $offId. ' of client K-0006' );
check( '...deleting it again is 404 crm_clients_member', $call( 'crm-clients/account-remove', [ 'id' => (string) $kg['id'], 'account' => $offId ] )[1]['code'] === 'crm_clients_member' );
$goneAnswers = [];

foreach( [ 'account-resend' => [], 'account-active' => [ 'active' => true ], 'account-name' => [ 'name' => 'X' ] ] as $action => $more ) {
	[ $status, $body ] = $call( 'crm-clients/'. $action, [ 'id' => (string) $kg['id'], 'account' => 'f0f0f0f0f0f0' ] + $more );
	$goneAnswers[$action] = [ $status, $body['code'] ?? '' ];
}

check( 'a row whose account Members has not got any more takes no action but delete: the others are 404 crm_clients_member', array_values( array_unique( array_map( 'json_encode', $goneAnswers ) ) ) === [ '[404,"crm_clients_member"]' ] );
[ $status, $body ] = $call( 'crm-clients/account-remove', [ 'id' => (string) $kg['id'], 'account' => 'f0f0f0f0f0f0' ] );
check( 'a row of an account that Members has not got any more is removed with the same action', $status === 200 && in_array( 'f0f0f0f0f0f0', \Crm\Clients::accounts( $appData, (int) $kg['id'] ), true ) === false );
check( 'an account of another client is not deleted through this one: 404 crm_clients_member', $call( 'crm-clients/account-remove', [ 'id' => (string) $other['id'], 'account' => (string) $neu['id'] ] )[1]['code'] === 'crm_clients_member' && $memberOf( (string) $neu['id'] ) !== [] );

$byNumber = array_column( \Crm\Clients::list( $appData ), null, 'number' );
$active = count( array_filter( \Crm\Clients::accountsDetailed( $appData, (int) $kg['id'] ), static fn( array $a ): bool => $a['gone'] === false && $a['active'] === true ) );
check( 'the list has the counts: the accounts of each client, and how many of them are on', ( $byNumber['K-0006']['accounts'] ?? -1 ) === count( \Crm\Clients::accounts( $appData, (int) $kg['id'] ) ) && ( $byNumber['K-0006']['accounts'] ?? -1 ) >= 2 && ( $byNumber['K-0006']['active_accounts'] ?? -1 ) === $active && $active >= 1
	&& ( $byNumber['K-0001']['active_accounts'] ?? -1 ) === 1 && ( $byNumber['K-0004']['accounts'] ?? -1 ) === 0 );

// What this section made is taken away again, so the sections below find the project as they expect it
foreach( [ $neu['id'], $freeA['id'], $freeB['id'], $unmoved['id'] ?? '' ] as $gone )
	if( $gone !== '' )
		\Nino\Modules\Members::remove( $appData, (string) $gone );

foreach( \Nino\Modules\Members::accounts( $appData ) as $account )
	if( in_array( $account['email'], [ 'nopost@example.org' ], true ) === true )
		\Nino\Modules\Members::remove( $appData, $account['id'] );

\Crm\Db::exec( $appData, 'DELETE FROM client_accounts WHERE client_id IN ( SELECT id FROM clients WHERE number >= \'K-0006\' )' );
check( 'the accounts of the section are gone again', count( \Nino\Modules\Members::accounts( $appData ) ) === $membersBefore );
$mails->exchangeArray( [] );

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

$clientsNow = count( \Crm\Clients::list( $appData ) );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'company' => 'Ohne Mitglieder', 'account_email' => 'm@example.org' ], 'crm-clients/save' );
check( 'while Members is off the accounts of a client are rows that say so: state members-off, not gone, and the list still counts them', array_unique( array_column( \Crm\Clients::accountsDetailed( $appData, $first['id'] ), 'state' ) ) === [ 'members-off' ] && count( \Crm\Clients::accountsDetailed( $appData, $first['id'] ) ) >= 2 );
check( 'a new client with an address is 409 crm_clients_members while Members is off, and nothing is made', $status === 409 && $body['code'] === 'crm_clients_members' && count( \Crm\Clients::list( $appData ) ) === $clientsNow );
[ $status, $body ] = ninoCall( $appData, $panel, [ 'company' => 'Ohne Konto' ], 'crm-clients/save' );
check( '...a client without an address is made as ever', $status === 200 && array_key_exists( 'first', $body ) === false );
$offAnswers = [];

foreach( [ 'unlinked' => [], 'account-link' => [ 'id' => (string) $first['id'], 'account' => 'aaaaaaaaaaaa' ], 'account-resend' => [ 'id' => (string) $first['id'], 'account' => 'aaaaaaaaaaaa' ], 'account-active' => [ 'id' => (string) $first['id'], 'account' => 'aaaaaaaaaaaa' ],
	'account-name' => [ 'id' => (string) $first['id'], 'account' => 'aaaaaaaaaaaa', 'name' => 'X' ], 'account-remove' => [ 'id' => (string) $first['id'], 'account' => 'aaaaaaaaaaaa' ] ] as $action => $data ) {
	[ $status, $body ] = ninoCall( $appData, $panel, $data, 'crm-clients/'. $action );
	$offAnswers[$action] = [ $status, $body['code'] ?? '' ];
}

check( 'every action of an account is 409 crm_clients_members while Members is off', array_values( array_unique( array_map( 'json_encode', $offAnswers ) ) ) === [ '[409,"crm_clients_members"]' ] && array_map( static fn( string $action ): string => 'crm-clients/'. $action, array_keys( $offAnswers ) ) === array_values( array_filter( array_keys( \Crm\Clients\Admin::actions() ), static fn( string $action ): bool => $action === 'crm-clients/unlinked' || str_starts_with( $action, 'crm-clients/account-' ) ) ) );
$counted = \Crm\Clients::get( $appData, $first['id'] ) ?? [];
check( 'the list still counts: the rows, none of them active (nothing is known of the accounts)', ( $counted['accounts'] ?? 0 ) === count( \Crm\Clients::accounts( $appData, $first['id'] ) ) && ( $counted['accounts'] ?? 0 ) >= 2 && ( $counted['active_accounts'] ?? 1 ) === 0 );

// --- The templates of Members -----------------------------------------------------------------------------------------------

echo "\nThe templates of Members\n";

// Members is the project's since C05 and its two pages are drawn with the classes of the kernel's Nino.css. The check is the one of
// the kernel's tests/install-library-templates-js-smoke.js, run over the templates of this project: every nino-* class a template names
// has an owner in the kernel's style sheets and scripts or in the library of the installer
$owned = [];

foreach( [ $root. '/_nino', $root. '/_admin/install/library' ] as $base )
	foreach( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) ) as $source ) {

		$extension = $source->getExtension();

		if( $extension !== 'css' && $extension !== 'js' )
			continue;

		preg_match_all( $extension === 'css' ? '/\.(nino-[a-z0-9-]+)/' : '/\b(nino-[a-z0-9-]+)\b/', (string) file_get_contents( $source->getPathname() ), $found );
		$owned += array_fill_keys( $found[1], true );
	}

$classes = [];

foreach( [ 'page-members-login.tpl', 'page-members-confirm.tpl' ] as $name ) {

	$template = (string) file_get_contents( $root. '/features/Members/install/templates/'. $name );
	preg_match_all( '/\bclass\s*=\s*(["\'])(.*?)\1/s', $template, $attributes );

	foreach( $attributes[2] as $list )
		foreach( preg_split( '/\s+/', trim( $list ) ) ?: [] as $class )
			if( str_starts_with( $class, 'nino-' ) === true )
				$classes[$name][] = $class;
}

check( 'the two pages of Members name classes the kernel owns: the narrow row is a row, not a section', array_keys( $classes ) === [ 'page-members-login.tpl', 'page-members-confirm.tpl' ] && count( $owned ) > 500
	&& array_filter( array_merge( ...array_values( $classes ) ), static fn( string $class ): bool => isset( $owned[$class] ) === false ) === [] && in_array( 'nino-grid-row--narrow', $classes['page-members-login.tpl'], true ) === true && in_array( 'nino-grid-row--narrow', $classes['page-members-confirm.tpl'], true ) === true );

// --- The README -----------------------------------------------------------------------------------------------------------

echo "\nThe README\n";

$readme = (string) file_get_contents( $root. '/app/Crm/README.md' );
$german = require $root. '/app/Crm/Clients/Admin/text/de_DE.php';
check( 'the README calls the buttons of an account by the words they have: Einladung senden and Anmelde-Link senden, and says Status where the screens do', str_contains( $readme, '**'. $german['[[/_admin/crm-clients/label/resend-invite]]']. '**' ) === true
	&& str_contains( $readme, '**'. $german['[[/_admin/crm-clients/label/resend-login]]']. '**' ) === true && str_contains( $readme, '**Link senden**' ) === false && str_contains( $readme, 'Zustand' ) === false && str_contains( $readme, 'Zustände' ) === false );
check( '...the section on the items names the element type, the Elements panel, the loop of the Builder and the table that is gone', str_contains( $readme, '/positionen' ) === true && str_contains( $readme, 'Panel **Elemente**' ) === true && str_contains( $readme, '[elements /positionen sort="sort" query="hidden=0"]' ) === true
	&& str_contains( $readme, '`items` ist weg' ) === true && str_contains( $readme, 'nicht übernommen' ) === true && str_contains( $readme, 'noch einmal' ) === true && str_contains( $readme, 'schreibt die Zeile des Belegs 0' ) === true );

check( 'the screens say Status and not Zustand, in the words of the panels', array_filter( [ 'Clients', 'Documents' ], static fn( string $dir ): bool => str_contains( (string) file_get_contents( $root. '/app/Crm/'. $dir. '/Admin/text/de_DE.php' ), 'Zustand' ) === true ) === [] );

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

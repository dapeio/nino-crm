<?php
declare(strict_types=1);

/**
 *	documents-smoke.php		Contract test for the documents of the project module \Crm\Crm: migration 0002, the settings and their
 *												rules, the money arithmetic (the cases of money-cases.json, which the script of the panel runs as
 *												well), the items (the elements of the type Positionen), the drafts, finalising (number, frozen client, totals, PDF, hash, rollback, parallel
 *												numbers), the states, the credit note, the immutability of a finalised document, the route that
 *												answers the PDF, what the slots and tokens hold, and the panel Belege with its actions. Runs over the kernel's tests/harness.php three levels up.
 *
 *	Usage: php app/Crm/tests/documents-smoke.php
 */

$root = dirname( __DIR__, 3 );
require $root. '/tests/harness.php';

use Crm\Db;
use Crm\Documents;
use Crm\Documents\Money;
use Crm\Documents\Numbers;
use Crm\Documents\Render;
use Crm\Documents\XRechnung;
use Crm\Positionen;
use Crm\Settings;

/**
 *	Boot an appData against a sandbox directory, the way a request would, with the modules of its config
 *
 *	@param		string		$sandbox
 *
 *	@return 	array
 */
function docBoot( string $sandbox ): array {

	$appData = [ './nino/uid' => $sandbox ];
	\Nino\AppData::prepare( $appData );
	$appData['./nino/filesystem/path']				= $sandbox;
	$appData['./nino/filesystem/configpath']	= $sandbox. '/private';
	$appData['./nino/filesystem/contentpath']	= $sandbox. '/private';
	$appData['./nino/filesystem/publicpath']	= $sandbox. '/public';
	$config = (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] );
	$appData['/nino/modules'] = array_values( (array) ( $config['/nino/modules'] ?? [] ) );

	// The languages of the site, which a PDF reads the address of the website in (Render::data() asks for site.portal)
	$appData['/nino/locales/available'] = $config['/nino/locales/available'];
	$appData['/nino/locales/native'] = $config['/nino/locales/native'];
	$appData['./nino/locales/current'] = $config['/nino/locales/native'];

	return $appData;
}

/**
 *	The step of the Einrichtung that enters the Datenschutzerklärung: it writes where the setting privacy_text is empty and nowhere else
 *
 *	@param		array 		&$appData				The site, the setup pressed once
 *	@param		callable	$post						Posts an action of the panel
 *	@param		array 		$first					The answer of that first press
 *
 *	@return 	void
 */
function privacySetup( array &$appData, callable $post, array $first ): void {

	$text = Settings::get( $appData, 'privacy_text' );

	check( 'the first press on a site with no text enters the Datenschutzerklärung: the setting is not empty, privacy_changed has the day, and the answer says added.privacy', $first['added']['privacy'] === true && $first['existed']['privacy'] === false && $text !== '' && Settings::get( $appData, 'privacy_changed' ) === date( 'Y-m-d' ) );

	[ $status, $body ] = $post( 'crm/setup' );
	check( '...a second press says existed.privacy and leaves the text as it was', $status === 200 && $body['added']['privacy'] === false && $body['existed']['privacy'] === true && Settings::get( $appData, 'privacy_text' ) === $text );

	Db::exec( $appData, 'DELETE FROM settings WHERE key IN ( \'privacy_text\', \'privacy_changed\' )' );
	Settings::set( $appData, 'privacy_text', 'Der Text des Betreibers' );
	Db::exec( $appData, 'UPDATE settings SET value = \'2020-02-02\' WHERE key = \'privacy_changed\'' );
	[ $status, $body ] = $post( 'crm/setup' );
	check( '...a text the owner entered before stays - the text and its day - and the answer says existed.privacy', $status === 200 && $body['added']['privacy'] === false && $body['existed']['privacy'] === true && Settings::get( $appData, 'privacy_text' ) === 'Der Text des Betreibers' && Settings::get( $appData, 'privacy_changed' ) === '2020-02-02' );

	Db::exec( $appData, 'DELETE FROM settings WHERE key IN ( \'privacy_text\', \'privacy_changed\' )' );
}

// A worker of the concurrency section below: its own process, its own request, finalising the draft it is given. The exit
// status is 0 for a document that was finalised and 1 for any other way
if( ( $argv[1] ?? '' ) === 'worker' ) {

	$worker = docBoot( (string) $argv[2] );

	try {
		exit( is_array( \Crm\Documents::finalise( $worker, (int) $argv[3] ) ) ? 0 : 1 );
	}
	catch( \Throwable $e ) {
		exit( 1 );
	}
}

$appData = ninoSandbox( 'crm-documents' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$cases = json_decode( (string) file_get_contents( __DIR__. '/money-cases.json' ), true );

// --- Migration 0002 and the settings ---------------------------------------------------------------------------------

echo "Migration 0002\n";

$pdo = Db::pdo( $appData );
$tables = array_column( Db::rows( $appData, 'SELECT name FROM sqlite_master WHERE type = \'table\' AND name NOT LIKE \'sqlite_%\' ORDER BY name' ), 'name' );
check( 'the tables of C01 and of C02 are there - and the table of the items is not: migration 0005 dropped it', $tables === [ 'client_accounts', 'clients', 'dismissals', 'document_lines', 'documents', 'project_states', 'records', 'sequences', 'settings' ] );
check( 'user_version is 15 and the first boot ran the fifteen migrations', (int) $pdo->query( 'PRAGMA user_version' )->fetchColumn() === 15 && Db::migrated() === 15 );

Db::close();
Db::pdo( $appData );
check( 'a second boot runs no migration', Db::migrated() === 0 );

$settings = Settings::all( $appData );
check( 'the settings are written where they were missing: the defaults of the plan', $settings === [ 'vat_mode' => 'small_business', 'vat_rates' => '1900,700,0', 'payment_days' => '14', 'offer_days' => '30', 'currency' => '€',
	'number_offer' => 'A-{YYYY}-{NNNN}', 'number_invoice' => 'R-{YYYY}-{NNNN}', 'number_credit' => 'G-{YYYY}-{NNNN}', 'tax_id' => '', 'bank_name' => '', 'iban' => '', 'bic' => '', 'paypal' => '', 'seller_name' => '', 'seller_street' => '', 'seller_zip' => '', 'seller_city' => '', 'seller_country' => 'DE', 'seller_contact' => '', 'seller_phone' => '',
	'seller_email' => '', 'vat_id' => '', 'vat_exempt_text' => 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG', 'privacy_text' => '', 'privacy_changed' => '', 'portal_message' => '', 'portal_message_at' => '', 'consent_social' => '', 'consent_archive' => '', 'notify_files' => '1' ]);
check( 'the columns of documents are the ones of the plan', array_column( Db::rows( $appData, 'PRAGMA table_info( documents )' ), 'name' ) === [ 'id', 'category', 'client_id', 'parent_id', 'number', 'state', 'date', 'due_date', 'valid_until', 'period_from', 'period_to',
	'vat_mode', 'reverse_charge', 'net', 'vat', 'gross', 'template_key', 'inputs_json', 'client_json', 'notes', 'pdf_path', 'pdf_sha256', 'finalised', 'sent_at', 'paid_at', 'created', 'updated',
	'title', 'released', 'released_at', 'sign_id', 'sign_state', 'signed_at', 'project', 'reminded_at', 'reminded_mail', 'buyer_reference', 'xml_path', 'xml_sha256', 'xml_lacks', 'release_note', 'release_note_at' ] );

check( 'the table has no CHECK on category and state: the model lists them, a later patch adds to them', str_contains( (string) Db::row( $appData, 'SELECT sql FROM sqlite_master WHERE name = \'documents\'' )['sql'], 'CHECK' ) === false );

Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = :k', [ 'v' => 'regular', 'k' => 'vat_mode' ] );
Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = :k', [ 'v' => 'X-{N}', 'k' => 'number_offer' ] );
Db::close();
Db::pdo( $appData );
check( 'a later boot leaves a changed setting alone', Settings::get( $appData, 'vat_mode' ) === 'regular' && Settings::get( $appData, 'number_offer' ) === 'X-{N}' );
Settings::set( $appData, 'vat_mode', 'small_business' );
Settings::set( $appData, 'number_offer', 'A-{YYYY}-{NNNN}' );

echo "\nSettings\n";

check( 'set() writes a value that passes and answers true', Settings::set( $appData, 'payment_days', '21' ) === true && Settings::get( $appData, 'payment_days' ) === '21' );
Settings::set( $appData, 'payment_days', '14' );
check( 'a key nobody has is unknown, in set() and in get()', Settings::set( $appData, 'colour', 'red' ) === 'unknown' && ( static function() use ( &$appData ): bool {
	try {
		Settings::get( $appData, 'colour' );
	}
	catch( \InvalidArgumentException $e ) {
		return true;
	}
	return false;
} )() === true );

$valid = [ 'A-{YYYY}-{NNNN}', 'R{YY}{NNN}', '{N}', 'INV_{YYYY}.{NNNNNN}', 'x{NNNNNNNNN}', '{YYYY}-{NN}-x' ];
$invalid = [
	'no counter'               => 'R-{YYYY}',
	'two counters'             => 'R-{NNN}-{NN}',
	'both year forms'          => 'R-{YYYY}-{YY}-{NNN}',
	'the year twice'           => '{YYYY}{YYYY}{NNN}',
	'a slash'                  => 'R/{YYYY}/{NNN}',
	'a space'                  => 'R {NNN}',
	'a backslash'              => 'R\\{NNN}',
	'an unknown token'         => 'R-{MM}-{NNN}',
	'a lonely brace'           => 'R-{NNN}}',
	'a counter wider than 9'   => 'R-{NNNNNNNNNN}',
	'empty'                    => '',
	'longer than 40'           => str_repeat( 'x', 36 ). '{NNN}',
	'a non-ascii letter'       => 'Rü-{NNN}',
	'a dot dot'                => '..{NNN}/',
	'an angle bracket'         => '<R>{NNN}',
	'a percent sign'           => 'R%{NNN}',
];

check( 'a format with {YYYY} or {YY} at most once, {N...} exactly once and letters, digits, - _ . otherwise is valid', array_filter( $valid, static fn( string $format ): bool => Settings::validFormat( $format ) === false ) === [] );

foreach( $invalid as $why => $format )
	check( 'a number format is refused: '. $why, Settings::validFormat( $format ) === false && Settings::set( $appData, 'number_invoice', $format ) === 'format:number_invoice' && Settings::get( $appData, 'number_invoice' ) === 'R-{YYYY}-{NNNN}' );

check( 'a format is a string: an array or a boolean is refused', Settings::set( $appData, 'number_credit', [ 'x' ] ) === 'value:number_credit' && Settings::set( $appData, 'number_credit', true ) === 'value:number_credit' );
check( 'vat_mode is small_business or regular', Settings::set( $appData, 'vat_mode', 'both' ) === 'value:vat_mode' && Settings::set( $appData, 'vat_mode', 'regular' ) === true && Settings::set( $appData, 'vat_mode', 'small_business' ) === true );

foreach( [ 'a letter' => 'x', 'a negative' => '-1', 'a leading zero' => '014', 'a fraction' => '1.5', 'past a year' => '366', 'empty' => '' ] as $why => $days )
	check( 'the days of a payment term and of an offer are whole numbers up to 365: '. $why, Settings::set( $appData, 'payment_days', $days ) === 'value:payment_days' && Settings::set( $appData, 'offer_days', $days ) === 'value:offer_days' );

check( '...0 and 365 pass', Settings::set( $appData, 'payment_days', '0' ) === true && Settings::set( $appData, 'offer_days', '365' ) === true );
Settings::set( $appData, 'payment_days', '14' );
Settings::set( $appData, 'offer_days', '30' );

foreach( [ 'a letter' => '19,x', 'a duplicate' => '1900,1900', 'past 100 %' => '10001', 'a negative' => '-1', 'a fraction' => '19.5', 'empty' => '', 'a leading zero' => '0700' ] as $why => $rates )
	check( 'the rates are whole hundredths of a percent up to 10000, none twice: '. $why, Settings::set( $appData, 'vat_rates', $rates ) === 'value:vat_rates' );

check( '...a list is kept as it is spelled without spaces, and rates() reads it', Settings::set( $appData, 'vat_rates', ' 1900, 700 ,550,0 ' ) === true && Settings::get( $appData, 'vat_rates' ) === '1900,700,550,0' && Settings::rates( $appData ) === [ 1900, 700, 550, 0 ] );
check( 'rateAllowed(): 0 only in small_business, a rate of the list in regular', Settings::rateAllowed( $appData, 0, 'small_business' ) === true && Settings::rateAllowed( $appData, 1900, 'small_business' ) === false
	&& Settings::rateAllowed( $appData, 550, 'regular' ) === true && Settings::rateAllowed( $appData, 1600, 'regular' ) === false );
$percent = [];

foreach( [ '19; 7; 0' => '1900,700,0', '19 7 0' => '1900,700,0', '19;7;5,5;0' => '1900,700,550,0', '5.5' => '550', "19\n7\n0" => '1900,700,0', '19,25; 0,05' => '1925,5', ' 100 ' => '10000', '0' => '0' ] as $typed => $stored )
	$percent[$typed] = Settings::clean( 'vat_rates_percent', (string) $typed ) === $stored;

check( 'rates written in percent - apart by semicolons or white space, a comma or a point the decimal mark - become the hundredths that are stored: '. count( $percent ), array_filter( $percent, static fn( bool $ok ): bool => $ok === false ) === [] );

$refusedPercent = [];

foreach( [ '19,7,0', '19, 7, 0', '19;', ';19', 'neunzehn', '19;19', '', '   ', '19,555', '101', '1000', '-7', '19 %' ] as $typed )
	$refusedPercent[$typed] = Settings::clean( 'vat_rates_percent', $typed ) === [ 'value:vat_rates_percent' ];

check( '...and the lists that are none are refused as a whole: 19,7,0 (a comma is no separator), a trailing separator, a word, a rate twice, nothing, a third decimal, past 100 %', array_filter( $refusedPercent, static fn( bool $ok ): bool => $ok === false ) === [] && Settings::clean( 'vat_rates_percent', [ '19' ] ) === [ 'value:vat_rates_percent' ] );
check( 'set() takes no key vat_rates_percent: it is a way to write vat_rates, not a setting', Settings::set( $appData, 'vat_rates_percent', '19' ) === 'unknown' );
Settings::set( $appData, 'vat_rates', '1900,700,0' );

check( 'the currency is up to five letters or currency signs', Settings::set( $appData, 'currency', 'EUR' ) === true && Settings::set( $appData, 'currency', '' ) === 'value:currency' && Settings::set( $appData, 'currency', 'EUROPE' ) === 'value:currency'
	&& Settings::set( $appData, 'currency', '<b>' ) === 'value:currency' && Settings::set( $appData, 'currency', '€' ) === true );

// --- Money ----------------------------------------------------------------------------------------------------------------

echo "\nMoney\n";

$wrong = [];

foreach( $cases['lineAmount'] as [ $qty, $price, $expected ] )
	if( Money::lineAmount( $qty, $price ) !== $expected )
		$wrong[] = "lineAmount( $qty, $price )";

check( 'the amount of a line is qty x price / 1000 rounded half away from zero: '. count( $cases['lineAmount'] ). ' cases, both signs of the .5', $wrong === [] && count( $cases['lineAmount'] ) >= 18 );
$wrong = [];

foreach( $cases['vat'] as [ $net, $rate, $expected ] )
	if( Money::vat( $net, $rate ) !== $expected )
		$wrong[] = "vat( $net, $rate )";

check( 'the VAT is net x rate / 10000 rounded half away from zero: '. count( $cases['vat'] ). ' cases', $wrong === [] && count( $cases['vat'] ) >= 15 );
$wrong = [];

foreach( $cases['parse'] as [ $text, $expected ] )
	if( Money::parse( $text ) !== $expected )
		$wrong[] = $text;

check( 'a price is read from 1.234,56 and 1234,56 and 1234.56 and -12,5; anything else is none: '. count( $cases['parse'] ). ' cases', $wrong === [] && count( $cases['parse'] ) >= 28 );
$wrong = [];

foreach( $cases['parseQty'] as [ $text, $expected ] )
	if( Money::parseQty( $text ) !== $expected )
		$wrong[] = $text;

check( 'a quantity is read the same way with three decimals: '. count( $cases['parseQty'] ). ' cases', $wrong === [] && count( $cases['parseQty'] ) >= 12 );
$wrong = [];

foreach( $cases['format'] as [ $cents, $currency, $expected ] )
	if( Money::format( $cents, $currency ) !== $expected )
		$wrong[] = "format( $cents )";

check( 'cents are written 1.234,56 with the currency behind a non-breaking space', $wrong === [] );
$wrong = [];

foreach( $cases['formatQty'] as [ $qty, $expected ] )
	if( Money::formatQty( $qty ) !== $expected )
		$wrong[] = "formatQty( $qty )";

check( 'thousandths are written 1,5 and 2: the trailing zeros cut, three decimals at most', $wrong === [] );
$wrong = [];

foreach( $cases['formatRate'] as [ $rate, $expected ] )
	if( Money::formatRate( $rate ) !== $expected )
		$wrong[] = "formatRate( $rate )";

check( 'a rate is written 19 %, 7 % and 5,5 %', $wrong === [] );

foreach( $cases['totals'] as $case ) {

	$totals = Money::totals( $case['lines'], $case['mode'], $case['reverse'] );
	$rates = array_map( static fn( int $rate, array $sums ): array => [ $rate, $sums['net'], $sums['vat'] ], array_keys( $totals['rates'] ), array_values( $totals['rates'] ) );

	check( 'totals: '. $case['name'], [ $totals['net'], $totals['vat'], $totals['gross'], $rates, $totals['amounts'] ] === [ $case['expect']['net'], $case['expect']['vat'], $case['expect']['gross'], $case['expect']['rates'], $case['expect']['amounts'] ] );
}

echo "\nNumbers\n";

check( 'a format is filled with the year in four or two digits and the counter zero-padded', Numbers::render( 'R-{YYYY}-{NNNN}', 2026, 7 ) === 'R-2026-0007' && Numbers::render( 'R{YY}{NNN}', 2026, 42 ) === 'R26042' && Numbers::render( '{N}', 2026, 3 ) === '3' );
check( '...and a counter wider than its N\'s is never cut', Numbers::render( 'R-{NN}', 2026, 12345 ) === 'R-12345' && Numbers::render( 'R-{NNNN}', 2026, 10000 ) === 'R-10000' );
check( 'next() refuses a format that is none and spends nothing on it', Numbers::next( $pdo, 'invoice', 2026, 'R/{NNN}' ) === null && Db::rows( $appData, 'SELECT * FROM sequences' ) === [] );
check( 'upcoming() is the number a sequence gives next, read and never taken: a year with no document starts at 1, a format that is none is empty, and asking leaves the sequences as they were', Numbers::upcoming( $appData, 'invoice', 2026, 'R-{YYYY}-{NNNN}' ) === 'R-2026-0001' && Numbers::upcoming( $appData, 'invoice', 2026, 'R/{NNN}' ) === '' && Db::rows( $appData, 'SELECT * FROM sequences' ) === [] );
$taken = Numbers::next( $pdo, 'invoice', 2026, 'R-{YYYY}-{NNNN}' );
$asked = [ Numbers::upcoming( $appData, 'invoice', 2026, 'R-{YYYY}-{NNNN}' ), Numbers::upcoming( $appData, 'invoice', 2026, 'R-{YYYY}-{NNNN}' ) ];
check( '...after next() it is the number after the one taken, per category and per year, and still it takes nothing', $taken === 'R-2026-0001' && $asked === [ 'R-2026-0002', 'R-2026-0002' ]
	&& Numbers::upcoming( $appData, 'invoice', 2027, 'R-{YY}-{NN}' ) === 'R-27-01' && Numbers::upcoming( $appData, 'offer', 2026, 'A-{YYYY}-{NNNN}' ) === 'A-2026-0001' && (int) Db::row( $appData, 'SELECT next FROM sequences WHERE category = \'invoice\' AND year = 2026' )['next'] === 2 );
Db::exec( $appData, 'DELETE FROM sequences' );

// --- The module, the features and the panels ------------------------------------------------------------------------

echo "\nThe module, Pdf and the templates\n";

$appData['/nino/dir'] = '';
\Nino\Html::addFills( $appData, [ '[[/nino/dir]]' => '', '[[/project/website/general/url]]' => 'example.org' ], '*' );
$appData['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $appData, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Csrf::init( $appData );

foreach( [ 'members', 'pdf' ] as $key )
	\Nino\Features::activate( $appData, $key );

$listed = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] );
$listed[] = '\\Crm\\Crm';
$appData['/nino/modules'] = $listed;
\Nino\AppData::writeContentData( $appData, [ '/nino/modules' ] );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );
$ignored = ninoWarnings();

$panel = static function( array &$appData, array &$request ): void {

	// The API of the clients and the documents is reached the way the surface's endpoint reaches it, by the table; the actions of the features' panels (pdf/...) go through the workbench,
	// which does not know the documents: it deletes a template that a document names, which the surface's endpoint does not
	$table = \Crm\Surface::actions();
	$action = (string) ( $_POST['action'] ?? '' );

	if( isset( $table[$action] ) === true && str_starts_with( $action, 'pdf/' ) === false ) {
		[ $class, $method ] = $table[$action];
		$class::{$method}( $appData, $request );
		return;
	}

	\Nino\Admin\Admin::handlePost( $appData, $request );
};
$panels = \Nino\Admin\Admin::panels( $appData );
check( 'the module has one panel in the workbench, CRM, and the classes of the documents and of the clients are no panels: not in the registry, with none of the methods of a panel', array_keys( array_filter( $panels, static fn( string $uri ): bool => str_starts_with( $uri, 'crm' ), ARRAY_FILTER_USE_KEY ) ) === [ 'crm' ]
	&& \Crm\Crm::adminPanels( $appData ) === [ \Crm\Crm\Admin::class ] && array_filter( [ 'nav', 'icon', 'template', 'assets', 'text', 'summary', 'perm' ], static fn( string $method ): bool => method_exists( \Crm\Documents\Admin::class, $method ) === true ) === [] );
check( '...the API of the documents asks for the one permission of the CRM, /_admin/crm/manage, as the clients\' and the panel CRM do', ( new \ReflectionClass( \Crm\Documents\Admin::class ) )->getConstant( 'MANAGE_PERM' ) === \Crm\Crm::MANAGE_PERM && ( new \ReflectionClass( \Crm\Clients\Admin::class ) )->getConstant( 'MANAGE_PERM' ) === \Crm\Crm::MANAGE_PERM
	&& ( $panels['crm']['perm'] ?? '' ) === \Crm\Crm::MANAGE_PERM );
check( '...and its files are gone: no script, no style, no template of a panel Belege or Kunden - only the sentences of the codes remain in text/', array_filter( [ 'Documents/Admin/assets', 'Documents/Admin/templates', 'Clients/Admin/assets', 'Clients/Admin/templates' ], static fn( string $dir ): bool => file_exists( $root. '/app/Crm/'. $dir ) === true ) === []
	&& is_file( $root. '/app/Crm/Documents/Admin/text/de_DE.php' ) === true && is_file( $root. '/app/Crm/Documents/Admin/text/en_US.php' ) === true && array_filter( array_keys( require $root. '/app/Crm/Documents/Admin/text/de_DE.php' ), static fn( string $key ): bool => preg_match( '~^\[\[/_admin/error/crm_(?:documents_|project)~', $key ) !== 1 ) === [] );
check( 'the module registers the route GET /.crm/pdf/* and its callback', ( $appData['/nino/http/routes']['GET://.crm/pdf/*']['uri'] ?? '' ) === '/.crm/pdf/*' && isset( $appData['./nino/callbacks']['/nino/http/response/GET://.crm/pdf/*'] ) === true );

ninoUser( $appData, 'admin@example.com' );
$post = static function( string $action, array $data = [] ) use ( &$appData, $panel ): array {
	return ninoCall( $appData, $panel, $data, $action );
};

// The templates of Pdf, made the way its panel makes them: one of the tag invoice and one of the tag offer, with the slots
// address, lines and totals, a required input and an optional one, and the tokens
$blocks = static fn( string $second ): array => [
	[ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1>' ],
	[ 'type' => 'slot', 'name' => 'address' ],
	[ 'type' => 'html', 'html' => '<p>Datum [[/document/date]] Faellig [[/invoice/due]] Gueltig [[/offer/valid]] Zeitraum [[/invoice/period]] Bezug [[/document/parent]] Kunde [[/customer/number]] [[/customer/company]]</p>' ],
	[ 'type' => 'input', 'name' => 'intro', 'label' => 'Intro', 'multiline' => true, 'required' => true ],
	[ 'type' => 'slot', 'name' => 'lines' ],
	[ 'type' => 'slot', 'name' => 'totals' ],
	[ 'type' => 'html', 'html' => '<p>Hinweis [[/document/vatnote]] Summe [[/document/gross]]</p>' ],
	[ 'type' => 'input', 'name' => 'footnote', 'label' => 'Footnote', 'multiline' => false, 'required' => false ],
	[ 'type' => 'html', 'html' => '<p>'. $second. '</p>' ],
];
$post( 'pdf/templates/save', [ 'key' => 'rechnung', 'isNew' => true, 'name' => 'Rechnung', 'tag' => 'invoice', 'blocks' => $blocks( 'Ende der Rechnung' ) ] );
$post( 'pdf/templates/save', [ 'key' => 'angebot', 'isNew' => true, 'name' => 'Angebot', 'tag' => 'offer', 'blocks' => $blocks( 'Ende des Angebots' ) ] );
$post( 'pdf/templates/save', [ 'key' => 'vertrag', 'isNew' => true, 'name' => 'Vertrag', 'tag' => 'contract', 'blocks' => [] ] );
check( 'the tag of a category is offer for an offer and invoice for an invoice and a credit note', Render::tag( 'offer' ) === 'offer' && Render::tag( 'invoice' ) === 'invoice' && Render::tag( 'credit' ) === 'invoice' );

$first = \Crm\Clients::add( $appData, [ 'company' => 'Studio Nord GmbH', 'name' => 'Anna Nord', 'street' => 'Hafenstr. 1', 'zip' => '20095', 'city' => 'Hamburg', 'email' => 'anna@example.org', 'vat_id' => 'DE123456789' ] );
$second = \Crm\Clients::add( $appData, [ 'name' => 'Ben Süd', 'street' => 'Lindenweg 3', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT' ] );
$third = \Crm\Clients::add( $appData, [ 'company' => 'Dritte AG' ] );
$third = \Crm\Clients::setActive( $appData, $third['id'], false );

// --- Positionen --------------------------------------------------------------------------------------------------------

echo "\nPositionen\n";

$positionenState = static function() use ( $post ): string {

	foreach( $post( 'crm-documents/status' )[1]['items'] as $item )
		if( $item['key'] === 'positionen' )
			return $item['state'];

	return '';
};

check( 'before the setup the type is not there: missing() says so, and list(), all() and get() have nothing', Positionen::missing( $appData ) === true && Positionen::list( $appData ) === [] && Positionen::all( $appData ) === [] && Positionen::list( $appData, 'x', false ) === [] && Positionen::get( $appData, 'webdesign' ) === null );
check( '...the status of Belege names the type, and the editor has no items', $positionenState() === 'missing' && $post( 'crm-documents/list' )[1]['items'] === [] );

// The blacklist of the texts is the wizard's and other units' before the setup: a key that is not the unit's has to survive it
\Nino\Filesystem::mutate( $appData, '/text/blacklist.php', static fn( array $list ): array => array_values( array_unique( array_merge( $list, [ '/_admin/elements/field/other/title' ] ) ) ) );
$foreignBefore = count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/blacklist.php', [] ) );

// The setup switches on every feature of the product; the rest of this suite works with Members and Pdf alone
$restore = static function() use ( &$appData ): void {
	foreach( [ 'sign', 'review', 'files', 'events' ] as $key )
		\Nino\Features::deactivate( $appData, $key );

	// ...and with the templates it made itself: the ones the setup seeds besides them are taken away
	foreach( [ 'stornorechnung', 'datenschutz', 'information' ] as $key )
		@unlink( \Nino\Filesystem::path( $appData, '/data/pdf/templates/'. $key. '.php' ) );
};

[ $status, $body ] = $post( 'crm/setup' );
check( 'the setup of Kunden brings the type: it is among what was added, and the file is the unit\'s own', $status === 200 && $body['added']['types'] === [ 'positionen.php', 'projekte.php' ] && $body['existed']['types'] === [] && Positionen::missing( $appData ) === false && \Crm\Projekte::missing( $appData ) === false
	&& \Nino\Filesystem::getFileContent( $appData, '/elements/positionen.php', [] ) === include $root. '/app/Crm/Crm/install/positionen.php' );

privacySetup( $appData, $post, $body );

$model = \Nino\Elements::getElementModel( $appData, '/positionen' );
check( 'the model has the eight fields of the plan: the words per language, the unit code and the numbers for all', array_keys( $model ) === [ 'title', 'description', 'unit', 'unit_code', 'price', 'vat', 'hidden', 'sort' ]
	&& array_keys( array_filter( $model, static fn( array $field ): bool => ( $field['locale'] ?? false ) === true ) ) === [ 'title', 'description', 'unit' ]
	&& $model['title']['type'] === 'string' && $model['title']['required'] === true && $model['title']['maxlength'] === 200 && $model['description']['maxlength'] === 2000 && $model['description']['breaks'] === true && $model['description']['inputsize'] === 4 && $model['unit']['maxlength'] === 20 );
check( '...the price is a double with the euro sign, required; the rate a double with the percent sign; hidden a boolean without a default - off is the normal case, which the bucket for all says; sort an integer', $model['price']['type'] === 'double' && $model['price']['required'] === true && $model['price']['suffix'] === '€'
	&& $model['vat']['type'] === 'double' && $model['vat']['suffix'] === '%' && isset( $model['vat']['required'] ) === false && $model['hidden']['type'] === 'boolean' && isset( $model['hidden']['default'] ) === false && ( \Nino\Filesystem::getFileContent( $appData, '/elements/positionen.php', [] )['*']['*'] ?? null ) === [ 'hidden' => false ] && $model['sort']['type'] === 'integer' );
check( '...and the status of Belege no longer names it', $positionenState() === 'ok' );

$keys = [ 'field/positionen/title', 'field/positionen/description', 'field/positionen/unit', 'field/positionen/unit_code', 'field/positionen/price', 'field/positionen/vat', 'field/positionen/hidden', 'field/positionen/sort', 'type/positionen/hint',
	'field/projekte/title', 'field/projekte/client', 'field/projekte/description', 'field/projekte/started', 'field/projekte/done', 'field/projekte/folder', 'type/projekte/hint' ];
$blacklisted = (array) \Nino\Filesystem::getFileContent( $appData, '/text/blacklist.php', [] );
check( 'the setup brings the words of the Elements form for the types: the labels of the fields and the hint of each, in both languages, and not empty', array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => array_filter( $keys, static fn( string $key ): bool => trim( (string) ( \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] )['[[/_admin/elements/'. $key. ']]'] ?? '' ) ) === '' ) !== [] ) === []
	&& \Nino\Filesystem::getFileContent( $appData, '/text/de_DE.php', [] )['[[/_admin/elements/field/positionen/hidden]]'] === 'Ausgeblendet' && \Nino\Filesystem::getFileContent( $appData, '/text/en_US.php', [] )['[[/_admin/elements/field/positionen/hidden]]'] === 'Hidden' );
check( '...and they are on the blacklist of the texts, as the words of the workbench are, and the unit lists exactly those', array_diff( array_map( static fn( string $key ): string => '/_admin/elements/'. $key, $keys ), $blacklisted ) === []
	&& ( \Nino\Features::readUnitManifest( $root. '/app/Crm/Crm/install' )['blacklist'] ?? [] ) === array_map( static fn( string $key ): string => '/_admin/elements/'. $key, $keys ) );
check( '...added to the blacklist, not written over it: the key that was there before is still there, and the list grew by the unit\'s sixteen', in_array( '/_admin/elements/field/other/title', $blacklisted, true ) === true && count( $blacklisted ) === $foreignBefore + count( $keys ) );

$own = (array) \Nino\Filesystem::getFileContent( $appData, '/elements/positionen.php', [] );
$own['model']['price']['suffix'] = 'EUR';
\Nino\Filesystem::putFileContent( $appData, '/elements/positionen.php', $own );
[ $status, $body ] = $post( 'crm/setup' );
check( 'a type the project has is left as it is - add-only: a field it changed stays changed, and the setup says the type was there', $status === 200 && $body['added']['types'] === [] && $body['existed']['types'] === [ 'positionen.php', 'projekte.php' ]
	&& \Nino\Elements::getElementModel( $appData, '/positionen' )['price']['suffix'] === 'EUR' );
$blacklisted = (array) \Nino\Filesystem::getFileContent( $appData, '/text/blacklist.php', [] );
check( '...and the second press leaves the blacklist as it was: no key twice, the foreign one still there', count( $blacklisted ) === $foreignBefore + count( $keys ) && count( array_unique( $blacklisted ) ) === count( $blacklisted ) && in_array( '/_admin/elements/field/other/title', $blacklisted, true ) === true );
$own['model']['price']['suffix'] = '€';
\Nino\Filesystem::putFileContent( $appData, '/elements/positionen.php', $own );

// The type of the projects: the same unit, the same rules
$projectModel = \Nino\Elements::getElementModel( $appData, '/projekte' );
check( 'the setup brings the type of the projects: six fields, none per language - a project has one name, one note and one day - and a counter that numbers the elements', array_keys( $projectModel ) === [ 'title', 'client', 'description', 'started', 'done', 'folder' ]
	&& array_filter( $projectModel, static fn( array $field ): bool => ( $field['locale'] ?? false ) === true ) === [] && \Nino\Elements::getAutoincrement( $appData, '/projekte' ) === 1
	&& \Nino\Filesystem::getFileContent( $appData, '/elements/projekte.php', [] ) === include $root. '/app/Crm/Crm/install/projekte.php' );
check( '...the title and the client are required strings, the note a string with lines, the day a date, done a boolean that is off by default in the bucket for all, the folder a string', $projectModel['title']['required'] === true && $projectModel['title']['maxlength'] === 200 && $projectModel['client']['required'] === true && $projectModel['client']['maxlength'] === 10
	&& $projectModel['description']['breaks'] === true && $projectModel['description']['maxlength'] === 2000 && $projectModel['started']['type'] === 'date' && isset( $projectModel['started']['required'] ) === false && $projectModel['done']['type'] === 'boolean'
	&& ( \Nino\Filesystem::getFileContent( $appData, '/elements/projekte.php', [] )['*']['*'] ?? null ) === [ 'done' => false ] && $projectModel['folder']['type'] === 'string' && $projectModel['folder']['maxlength'] === 40 );
$ownProjects = (array) \Nino\Filesystem::getFileContent( $appData, '/elements/projekte.php', [] );
$ownProjects['model']['title']['maxlength'] = 150;
\Nino\Filesystem::putFileContent( $appData, '/elements/projekte.php', $ownProjects );
[ $status, $body ] = $post( 'crm/setup' );
check( 'the type of the projects is left as it is too - add-only: a field the project changed stays changed, and the setup says the type was there', $status === 200 && $body['added']['types'] === [] && in_array( 'projekte.php', $body['existed']['types'], true ) === true
	&& \Nino\Elements::getElementModel( $appData, '/projekte' )['title']['maxlength'] === 150 );
$ownProjects['model']['title']['maxlength'] = 200;
\Nino\Filesystem::putFileContent( $appData, '/elements/projekte.php', $ownProjects );
$restore();

// What the owner enters in the Elements panel: the words per language, the numbers for all. Workshop has a text in English only
$ignored = ninoWarnings();
$made = [
	\Nino\Elements::insertElement( $appData, '/positionen/hosting', [ 'price' => 90, 'vat' => 0, 'sort' => 1, 'title' => 'Hosting', 'unit' => 'Monat' ], 'de_DE' ),
	\Nino\Elements::updateElement( $appData, '/positionen/hosting', [ 'title' => 'Hosting plan', 'unit' => 'month' ], 'en_US' ),
	\Nino\Elements::insertElement( $appData, '/positionen/beratung', [ 'price' => 90.5, 'vat' => 19, 'sort' => 2, 'title' => 'Beratung', 'unit' => 'Tag' ], 'de_DE' ),
	\Nino\Elements::updateElement( $appData, '/positionen/beratung', [ 'title' => 'Consulting', 'description' => 'Advice for one day', 'unit' => 'day' ], 'en_US' ),
	\Nino\Elements::insertElement( $appData, '/positionen/webdesign', [ 'price' => 85.5, 'vat' => 0, 'sort' => 3, 'title' => ' Webdesign ', 'description' => 'Pro Stunde', 'unit' => 'Std.' ], 'de_DE' ),
	\Nino\Elements::updateElement( $appData, '/positionen/webdesign', [ 'title' => 'Web design', 'description' => 'Per hour', 'unit' => 'h' ], 'en_US' ),
	\Nino\Elements::insertElement( $appData, '/positionen/workshop', [ 'price' => 250, 'vat' => 7, 'sort' => 4, 'title' => 'Workshop', 'description' => 'A day with your team', 'unit' => 'day' ], 'en_US' ),
	\Nino\Elements::insertElement( $appData, '/positionen/cent', [ 'price' => 0.005, 'vat' => 7.5, 'sort' => 5, 'title' => 'Cent' ], 'de_DE' ),
	\Nino\Elements::insertElement( $appData, '/positionen/rabatt', [ 'price' => -0.005, 'vat' => 0, 'sort' => 6, 'title' => 'Rabatt' ], 'de_DE' ),
	\Nino\Elements::insertElement( $appData, '/positionen/altlast', [ 'price' => 1, 'hidden' => true, 'sort' => 7, 'title' => 'Altlast' ], 'de_DE' ),
];
check( 'the elements are made through the kernel\'s API, in German and in English', array_filter( $made, static fn( mixed $element ): bool => is_array( $element ) === false ) === [] && ninoWarnings() === [] );

$items = Positionen::list( $appData );
$byId = array_column( $items, null, 'id' );
check( 'list() answers the active items in the order of their field sort, by the name of the element', array_column( $items, 'id' ) === [ 'hosting', 'beratung', 'webdesign', 'workshop', 'cent', 'rabatt' ] && array_column( $items, 'sort' ) === [ 1, 2, 3, 4, 5, 6 ] );
check( '...with the words of the native language, trimmed - the site is German - and not those of the English version', $byId['webdesign']['title'] === 'Webdesign' && $byId['webdesign']['description'] === 'Pro Stunde' && $byId['webdesign']['unit'] === 'Std.' && $byId['hosting']['title'] === 'Hosting' && $byId['hosting']['unit'] === 'Monat' );
check( '...a field that is empty in German shows the text of another language: the description of Beratung, and every word of Workshop, which has English only', $byId['beratung']['title'] === 'Beratung' && $byId['beratung']['description'] === 'Advice for one day'
	&& $byId['workshop']['title'] === 'Workshop' && $byId['workshop']['description'] === 'A day with your team' && $byId['workshop']['unit'] === 'day' && $byId['cent']['description'] === '' && $byId['cent']['unit'] === '' );
check( '...the price in cents and the rate in hundredths: 90 is 9000, 90.5 is 9050, 85.5 is 8550, 19 is 1900, 7 is 700, 7.5 is 750, 0 is 0', array_column( $items, 'price' ) === [ 9000, 9050, 8550, 25000, 1, -1 ] && array_column( $items, 'vat' ) === [ 0, 1900, 0, 700, 750, 0 ] );
check( '...0.005 is rounded half away from zero, to 1 cent, and -0.005 to -1', $byId['cent']['price'] === 1 && $byId['rabatt']['price'] === -1 );
check( '...every answer has the nine keys, the id a string and the flag a bool', array_unique( array_map( static fn( array $item ): string => implode( ',', array_keys( $item ) ), $items ) ) === [ 'id,title,description,unit,unit_code,price,vat,active,sort' ] && array_filter( $items, static fn( array $item ): bool => is_string( $item['id'] ) === false || $item['active'] !== true ) === [] );
$fresh = \Nino\Elements::insertElement( $appData, '/positionen/frisch', [ 'price' => 5, 'title' => 'Frisch' ], 'de_DE' );
check( 'an item entered with a title and a price and nothing else - as the form of the Elements panel starts it - is offered in the list and in the editor', is_array( $fresh ) && in_array( 'frisch', array_column( Positionen::list( $appData ), 'id' ), true ) === true
	&& in_array( 'Frisch', array_column( $post( 'crm-documents/list' )[1]['items'], 'title' ), true ) === true && Positionen::get( $appData, 'frisch' )['active'] === true );
\Nino\Elements::deleteElement( $appData, '/positionen/frisch', 'de_DE' );
check( 'an item that is hidden is left out, and given when it is asked for', in_array( 'altlast', array_column( $items, 'id' ), true ) === false && array_column( Positionen::list( $appData, '', false ), 'id' ) === [ 'hosting', 'beratung', 'webdesign', 'workshop', 'cent', 'rabatt', 'altlast' ]
	&& array_column( Positionen::list( $appData, 'alt', false ), 'active' ) === [ false ] && Positionen::list( $appData, 'alt' ) === [] );
$all = Positionen::all( $appData );
$allById = array_column( $all, null, 'id' );
check( 'all() answers every item, the hidden one too, in the order of the field sort, with the numbers for all and a flag for the active', array_column( $all, 'id' ) === [ 'hosting', 'beratung', 'webdesign', 'workshop', 'cent', 'rabatt', 'altlast' ] && array_column( $all, 'sort' ) === [ 1, 2, 3, 4, 5, 6, 7 ]
	&& array_column( $all, 'price' ) === [ 9000, 9050, 8550, 25000, 1, -1, 100 ] && array_column( $all, 'vat' ) === [ 0, 1900, 0, 700, 750, 0, 0 ] && array_column( $all, 'active' ) === [ true, true, true, true, true, true, false ]
	&& array_unique( array_map( static fn( array $item ): string => implode( ',', array_keys( $item ) ), $all ) ) === [ 'id,sort,unit_code,price,vat,active,words' ] );
check( '...the words in every language of the site, as the element holds them in that language: German and English side by side', array_keys( $allById['webdesign']['words'] ) === [ 'de_DE', 'en_US' ] && $allById['webdesign']['words']['de_DE'] === [ 'title' => 'Webdesign', 'description' => 'Pro Stunde', 'unit' => 'Std.' ]
	&& $allById['webdesign']['words']['en_US'] === [ 'title' => 'Web design', 'description' => 'Per hour', 'unit' => 'h' ] );
check( '...a language that has no text shows empty and nothing of the other: no description in German for Beratung, and no word at all in German for Workshop, which has English only', $allById['beratung']['words']['de_DE']['description'] === '' && $allById['beratung']['words']['en_US']['description'] === 'Advice for one day'
	&& $allById['workshop']['words']['de_DE'] === [ 'title' => '', 'description' => '', 'unit' => '' ] && $allById['workshop']['words']['en_US']['title'] === 'Workshop' && $allById['cent']['words']['en_US'] === [ 'title' => '', 'description' => '', 'unit' => '' ] );
[ $status, $body ] = $post( 'crm-documents/positionen' );
check( 'the action positionen answers the items with the locales of the site, the native one, whether the type is missing and the settings the prices are written by', $status === 200 && $body['missing'] === false && $body['locales'] === [ 'de_DE', 'en_US' ] && $body['native'] === 'de_DE' && $body['items'] === $all
	&& $body['settings']['currency'] === '€' && $body['settings']['vat_mode'] === 'small_business' );
check( 'the search is over the title: case does not matter, blanks around it go, the wildcards are taken literally, and the words of the other language are not searched', array_column( Positionen::list( $appData, 'WEB' ), 'id' ) === [ 'webdesign' ] && array_column( Positionen::list( $appData, ' hosting ' ), 'id' ) === [ 'hosting' ]
	&& array_column( Positionen::list( $appData, 'work' ), 'id' ) === [ 'workshop' ] && Positionen::list( $appData, '%' ) === [] && Positionen::list( $appData, '_' ) === [] && Positionen::list( $appData, 'Consulting' ) === [] && Positionen::list( $appData, 'Stunde' ) === [] );
check( 'get() answers one item by the name of its element, as list() has it; a name nobody has, a name that is none and a uri are null', Positionen::get( $appData, 'webdesign' ) === $byId['webdesign'] && Positionen::get( $appData, 'altlast' )['active'] === false
	&& Positionen::get( $appData, 'nobody' ) === null && Positionen::get( $appData, '../x' ) === null && Positionen::get( $appData, '/positionen/webdesign' ) === null && Positionen::get( $appData, '' ) === null );

\Nino\Elements::updateElement( $appData, '/positionen/cent', [ 'sort' => 0 ], 'de_DE' );
$resorted = array_column( Positionen::list( $appData ), 'id' );
\Nino\Elements::updateElement( $appData, '/positionen/cent', [ 'sort' => 5 ], 'de_DE' );
check( 'the order is the field sort and not the order the elements were made in', $resorted === [ 'cent', 'hosting', 'beratung', 'webdesign', 'workshop', 'rabatt' ] && array_column( Positionen::list( $appData ), 'id' ) === [ 'hosting', 'beratung', 'webdesign', 'workshop', 'cent', 'rabatt' ] );

$catalogue = array_column( Positionen::list( $appData ), 'title' );

// --- Drafts -----------------------------------------------------------------------------------------------------------

echo "\nDrafts\n";

$today = date( 'Y-m-d' );
$year = (int) date( 'Y' );
$line = static fn( array $over = [] ): array => $over + [ 'title' => 'Webdesign', 'description' => '', 'unit' => 'Std.', 'qty' => '2,5', 'price' => '90', 'vat_rate' => 0 ];
$lines = [ $line(), $line( [ 'title' => 'Hosting', 'unit' => 'Monat', 'qty' => '1', 'price' => '12,00' ] ) ];

$bare = Documents::add( $appData, 'invoice', $first['id'] );
check( 'add() makes a draft: no number, today, the due date by the term of payment, the VAT mode of now, no line, no template', is_array( $bare ) && $bare['state'] === 'draft' && $bare['number'] === '' && $bare['date'] === $today && $bare['due_date'] === Documents::addDays( $today, 14 )
	&& $bare['valid_until'] === null && $bare['vat_mode'] === 'small_business' && $bare['lines'] === [] && $bare['template_key'] === '' && $bare['inputs'] === [] && $bare['net'] === 0 && $bare['pdf_path'] === '' && $bare['finalised'] === null && $bare['parent'] === null && $bare['children'] === [] );
$offer = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2026-03-10' ] );
check( 'an offer has a validity date by the days of an offer and no due date', is_array( $offer ) && $offer['date'] === '2026-03-10' && $offer['valid_until'] === '2026-04-09' && $offer['due_date'] === null );

check( 'a category the plan does not name is refused', Documents::add( $appData, 'letter', $first['id'] ) === 'category' );
check( 'a client nobody has is refused, an inactive one too, and nothing is stored', Documents::add( $appData, 'invoice', 999 ) === 'client' && Documents::add( $appData, 'invoice', $third['id'] ) === 'inactive' && count( Documents::list( $appData ) ) === 2 );
check( 'the client cannot be smuggled in with the input', ( Documents::add( $appData, 'invoice', $first['id'], [ 'client_id' => $second['id'] ] )['client_id'] ?? 0 ) === $first['id'] );
Documents::remove( $appData, (int) Db::row( $appData, 'SELECT MAX( id ) AS id FROM documents' )['id'] );

$draft = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines, 'notes' => 'intern', 'inputs' => [ 'intro' => 'Hallo', 'footnote' => 'Danke' ], 'template_key' => 'rechnung', 'period_from' => '2026-01-01', 'period_to' => '2026-01-31' ] );
check( 'a draft keeps its lines in order with the amounts of each, and the totals', is_array( $draft ) && array_column( $draft['lines'], 'title' ) === [ 'Webdesign', 'Hosting' ] && array_column( $draft['lines'], 'qty' ) === [ 2500, 1000 ] && array_column( $draft['lines'], 'price_net' ) === [ 9000, 1200 ]
	&& array_column( $draft['lines'], 'amount_net' ) === [ 22500, 1200 ] && array_column( $draft['lines'], 'sort' ) === [ 1, 2 ] && $draft['net'] === 23700 && $draft['vat'] === 0 && $draft['gross'] === 23700 );
check( '...with its template, its inputs, its period and its notes', $draft['template_key'] === 'rechnung' && $draft['inputs'] === [ 'intro' => 'Hallo', 'footnote' => 'Danke' ] && $draft['period_from'] === '2026-01-01' && $draft['period_to'] === '2026-01-31' && $draft['notes'] === 'intern' );

// every refusal of a line, with its place
$refusals = [
	'a title is needed'                 => [ [ 'title' => ' ' ], 'line:0:empty:title' ],
	'a title is at most 200 long'       => [ [ 'title' => str_repeat( 'x', 201 ) ], 'line:0:long:title' ],
	'a description is at most 2000 long' => [ [ 'description' => str_repeat( 'x', 2001 ) ], 'line:0:long:description' ],
	'a unit is at most 20 long'         => [ [ 'unit' => str_repeat( 'x', 21 ) ], 'line:0:long:unit' ],
	'a title is text'                   => [ [ 'title' => 5 ], 'line:0:format:title' ],
	'a quantity is a number'            => [ [ 'qty' => 'viele' ], 'line:0:amount:qty' ],
	'a quantity is not 0'               => [ [ 'qty' => '0' ], 'line:0:amount:qty' ],
	'a quantity is missing'             => [ [ 'qty' => null ], 'line:0:amount:qty' ],
	'a quantity is at most 10^7 thousandths' => [ [ 'qty' => '10000,001' ], 'line:0:amount:qty' ],
	'a price is a number'               => [ [ 'price' => '9,0,0' ], 'line:0:amount:price' ],
	'a price is at most 10^9 cents'     => [ [ 'price' => '10000000,01' ], 'line:0:amount:price' ],
	'an amount of a line is at most 10^11 cents' => [ [ 'qty' => '10000', 'price' => '10000000' ], 'line:0:amount:price' ],
	'a rate is none of the list'        => [ [ 'vat_rate' => 1900 ], 'line:0:rate:vat_rate' ],
	'a rate is a number'                => [ [ 'vat_rate' => '0' ], 'line:0:rate:vat_rate' ],
];

foreach( $refusals as $why => [ $over, $code ] ) {
	$bad = Documents::change( $appData, $draft['id'], [ 'lines' => [ $line( $over ) ] ] );
	check( 'a line is refused with its place: '. $why, $bad === $code );
}

check( 'the second line is counted from 0 in the place of the refusal', Documents::change( $appData, $draft['id'], [ 'lines' => [ $line(), $line( [ 'qty' => 'x' ] ) ] ] ) === 'line:1:amount:qty' );
check( 'a line that is no array is refused', Documents::change( $appData, $draft['id'], [ 'lines' => [ 'text' ] ] ) === 'line:0:format:' && Documents::change( $appData, $draft['id'], [ 'lines' => 'text' ] ) === 'format:lines' && Documents::change( $appData, $draft['id'], [ 'lines' => [ 'a' => $line() ] ] ) === 'format:lines' );
check( 'at most 200 lines', Documents::change( $appData, $draft['id'], [ 'lines' => array_fill( 0, 201, $line() ) ] ) === 'lines' && is_array( Documents::change( $appData, $draft['id'], [ 'lines' => array_fill( 0, 200, $line( [ 'qty' => '1', 'price' => '1' ] ) ) ] ) ) );
check( 'a line of exactly 10^11 cents is taken, ten of them are 10^12 and taken, eleven are past the total and refused', is_array( Documents::change( $appData, $draft['id'], [ 'lines' => array_fill( 0, 10, $line( [ 'qty' => '1000', 'price' => '1000000' ] ) ) ] ) )
	&& Documents::get( $appData, $draft['id'] )['net'] === 1000000000000 && Documents::change( $appData, $draft['id'], [ 'lines' => array_fill( 0, 11, $line( [ 'qty' => '1000', 'price' => '1000000' ] ) ) ] ) === 'total' );
check( 'a refused change stored nothing: the draft is as the last good change left it', count( Documents::get( $appData, $draft['id'] )['lines'] ) === 10 );
Documents::change( $appData, $draft['id'], [ 'lines' => $lines ] );

check( 'a line may carry its price as cents (an int), as a document gives its lines back', is_array( Documents::change( $appData, $draft['id'], [ 'lines' => Documents::get( $appData, $draft['id'] )['lines'] ] ) ) && Documents::get( $appData, $draft['id'] )['net'] === 23700 );

$bad = [
	'a date that is none'            => [ [ 'date' => '2026-02-30' ], 'date:date' ],
	'a date past the year 2999'      => [ [ 'date' => '3000-01-01' ], 'date:date' ],
	'a date before the year 2000'    => [ [ 'date' => '1999-12-31' ], 'date:date' ],
	'a date that is text'            => [ [ 'date' => 'morgen' ], 'date:date' ],
	'a date that is a list'          => [ [ 'date' => [ '2026-01-01' ] ], 'date:date' ],
	'a due date that is none'        => [ [ 'due_date' => '31.01.2026' ], 'date:due_date' ],
	'one day of a period'            => [ [ 'period_from' => '2026-01-01', 'period_to' => '' ], 'period' ],
	'the last day of a period alone' => [ [ 'period_from' => '', 'period_to' => '2026-01-31' ], 'period' ],
	'a period that ends before it begins' => [ [ 'period_from' => '2026-02-01', 'period_to' => '2026-01-01' ], 'period' ],
	'a reverse charge without VAT'   => [ [ 'reverse_charge' => true ], 'reverse' ],
	'a reverse charge that is no boolean' => [ [ 'reverse_charge' => 'yes' ], 'format:reverse_charge' ],
	'notes past 5000'                => [ [ 'notes' => str_repeat( 'x', 5001 ) ], 'long:notes' ],
	'notes that are no text'         => [ [ 'notes' => [ 'x' ] ], 'format:notes' ],
	'a template that is not there'   => [ [ 'template_key' => 'weg' ], 'template' ],
	'a template of another tag'      => [ [ 'template_key' => 'angebot' ], 'template' ],
	'a template of the tag of another category' => [ [ 'template_key' => 'vertrag' ], 'template' ],
	'inputs that are no map'         => [ [ 'inputs' => 'x' ], 'format:inputs' ],
	'an input with a name that is none' => [ [ 'inputs' => [ 'Bad Name' => 'x' ] ], 'format:inputs' ],
	'an input that is no text'       => [ [ 'inputs' => [ 'intro' => [ 'x' ] ] ], 'format:inputs' ],
	'an input past 20000'            => [ [ 'inputs' => [ 'intro' => str_repeat( 'x', 20001 ) ] ], 'format:inputs' ],
];

foreach( $bad as $why => [ $input, $code ] )
	check( 'a draft is refused: '. $why, Documents::change( $appData, $draft['id'], $input ) === $code );

check( 'nothing of the refusals reached the draft', ( static function() use ( &$appData, $draft ): bool {
	$now = Documents::get( $appData, $draft['id'] );
	return $now['date'] === $draft['date'] && $now['period_from'] === '2026-01-01' && $now['template_key'] === 'rechnung' && $now['notes'] === 'intern' && $now['inputs'] === [ 'intro' => 'Hallo', 'footnote' => 'Danke' ];
} )() );
check( 'a client that is inactive, or nobody, cannot be put on a draft; an active one can', Documents::change( $appData, $draft['id'], [ 'client_id' => $third['id'] ] ) === 'inactive' && Documents::change( $appData, $draft['id'], [ 'client_id' => 999 ] ) === 'client'
	&& Documents::change( $appData, $draft['id'], [ 'client_id' => 'x' ] ) === 'client' && ( Documents::change( $appData, $draft['id'], [ 'client_id' => $second['id'] ] )['client_id'] ?? 0 ) === $second['id'] );
Documents::change( $appData, $draft['id'], [ 'client_id' => $first['id'] ] );
check( 'a change of nothing in particular keeps everything, the lines too', ( static function() use ( &$appData, $draft ): bool {
	$before = Documents::get( $appData, $draft['id'] );
	$after = Documents::change( $appData, $draft['id'], [] );
	return is_array( $after ) && array_map( static fn( array $l ): array => array_diff_key( $l, [ 'id' => 0 ] ), $after['lines'] ) === array_map( static fn( array $l ): array => array_diff_key( $l, [ 'id' => 0 ] ), $before['lines'] ) && $after['inputs'] === $before['inputs'] && $after['net'] === $before['net'];
} )() );
check( 'change() of a document nobody has is unknown', Documents::change( $appData, 999, [] ) === 'unknown' && Documents::remove( $appData, 999 ) === 'unknown' && Documents::get( $appData, 999 ) === null );
check( 'an empty period and an empty date of the other kind are cleared; the date of the other kind of document is not kept', ( static function() use ( &$appData, $draft ): bool {
	$changed = Documents::change( $appData, $draft['id'], [ 'period_from' => '', 'period_to' => '', 'valid_until' => '2026-12-31' ] );
	return is_array( $changed ) && $changed['period_from'] === null && $changed['period_to'] === null && $changed['valid_until'] === null;
} )() );
Documents::change( $appData, $draft['id'], [ 'period_from' => '2026-01-01', 'period_to' => '2026-01-31' ] );

Settings::set( $appData, 'vat_mode', 'regular' );
$regular = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => [ $line( [ 'vat_rate' => 1900 ] ), $line( [ 'title' => 'Buch', 'qty' => '1', 'price' => '20', 'vat_rate' => 700 ] ) ] ] );
check( 'a draft takes the VAT mode of the setting at the time; a rate of the list is valid in regular', is_array( $regular ) && $regular['vat_mode'] === 'regular' && array_column( $regular['lines'], 'vat_rate' ) === [ 1900, 700 ] );
check( '...its totals have the VAT per rate: 225,00 at 19 % and 20,00 at 7 %', $regular['net'] === 24500 && $regular['vat'] === 4275 + 140 && $regular['gross'] === 24500 + 4415 );
Settings::set( $appData, 'vat_mode', 'small_business' );
check( 'the VAT mode of a draft stays when the setting changes: its rates are still held to the mode it has', ( static function() use ( &$appData, $regular, $line ): bool {
	$kept = Documents::change( $appData, $regular['id'], [ 'notes' => 'x' ] );
	return is_array( $kept ) && $kept['vat_mode'] === 'regular' && $kept['vat'] === 4415 && Documents::change( $appData, $regular['id'], [ 'lines' => [ $line( [ 'vat_rate' => 1600 ] ) ] ] ) === 'line:0:rate:vat_rate';
} )() );
Settings::set( $appData, 'vat_rates', '1900,700' );
Settings::set( $appData, 'vat_mode', 'regular' );
$noZero = Documents::add( $appData, 'invoice', $first['id'], [ 'reverse_charge' => true, 'lines' => [ $line( [ 'vat_rate' => 1900 ] ) ] ] );
check( 'a reverse charge needs no 0 among the rates of the settings: its rate is 0 whatever the list says', is_array( $noZero ) && $noZero['vat_mode'] === 'regular' && $noZero['lines'][0]['vat_rate'] === 0 && Documents::change( $appData, $noZero['id'], [ 'reverse_charge' => false ] ) === 'line:0:rate:vat_rate' );
Settings::set( $appData, 'vat_rates', '1900,700,0' );
Settings::set( $appData, 'vat_mode', 'small_business' );
Documents::remove( $appData, $noZero['id'] );
$reverse = Documents::change( $appData, $regular['id'], [ 'reverse_charge' => true ] );
check( 'a reverse charge (regular only) sets the rate of every line to 0 and the VAT to 0', is_array( $reverse ) && $reverse['reverse_charge'] === true && array_unique( array_column( $reverse['lines'], 'vat_rate' ) ) === [ 0 ] && $reverse['vat'] === 0 && $reverse['gross'] === 24500 );
$unreversed = Documents::change( $appData, $regular['id'], [ 'reverse_charge' => false, 'lines' => [ $line( [ 'vat_rate' => 1900 ] ) ] ] );
check( '...and without it the rates are the lines\' own again', is_array( $unreversed ) && $unreversed['reverse_charge'] === false && $unreversed['vat'] === 4275 );

echo "\nThe list\n";

$ids = array_column( Documents::list( $appData ), 'id' );
check( 'list() has every document, the latest date first', count( $ids ) === 4 && Documents::list( $appData )[count( $ids ) - 1]['id'] === $offer['id'] );
check( 'list() filters by category, by client, by year and by state', count( Documents::list( $appData, [ 'category' => 'invoice' ] ) ) === 3 && count( Documents::list( $appData, [ 'client_id' => $second['id'] ] ) ) === 0 && count( Documents::list( $appData, [ 'year' => 2026 ] ) ) >= 1
	&& array_column( Documents::list( $appData, [ 'year' => 2026 ] ), 'id' ) === array_column( array_filter( Documents::list( $appData ), static fn( array $row ): bool => str_starts_with( $row['date'], '2026' ) ), 'id' ) && count( Documents::list( $appData, [ 'state' => 'draft' ] ) ) === 4 && Documents::list( $appData, [ 'state' => 'paid' ] ) === [] );
check( 'a draft shows the live name of its client', Documents::list( $appData )[0]['client_title'] === 'Studio Nord GmbH' );

echo "\nRemoving a draft\n";

$gone = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines ] );
check( 'remove() deletes a draft with its lines', Documents::remove( $appData, $gone['id'] ) === true && Documents::get( $appData, $gone['id'] ) === null && (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM document_lines WHERE document_id = :id', [ 'id' => $gone['id'] ] )['n'] === 0 );
$next = Documents::add( $appData, 'invoice', $first['id'] );
check( 'the id of a deleted draft is not given to the next document: the ids count on', $next['id'] > $gone['id'] && str_contains( (string) Db::row( $appData, 'SELECT sql FROM sqlite_master WHERE name = \'documents\'' )['sql'], 'AUTOINCREMENT' ) === true
	&& str_contains( (string) Db::row( $appData, 'SELECT sql FROM sqlite_master WHERE name = \'document_lines\'' )['sql'], 'AUTOINCREMENT' ) === true );
Documents::remove( $appData, $next['id'] );

// --- Finalising -------------------------------------------------------------------------------------------------------

echo "\nFinalising\n";

$number = static fn( string $prefix, int $y, int $n ): string => sprintf( '%s-%04d-%04d', $prefix, $y, $n );
$pdfDir = \Nino\Filesystem::path( $appData, Render::DIR );
$sequences = static fn(): array => Db::rows( $appData, 'SELECT category, year, next FROM sequences ORDER BY category, year' );
$files = static fn(): array => is_dir( $pdfDir ) === true ? array_map( static fn( string $path ): string => substr( $path, strlen( $pdfDir ) ), glob( $pdfDir. '/*/*.pdf' ) ?: [] ) : [];

// Everything that stops a finalising stops it before a number is spent and before a file is written
$stopped = static function( int $id, string $code, string $why ) use ( &$appData, $sequences, $files ): void {
	$before = Documents::get( $appData, $id );
	check( 'finalising is refused - '. $why. ' ('. $code. '): no number spent, no file, the draft as it was', Documents::finalise( $appData, $id ) === $code && $sequences() === [] && $files() === [] && Documents::get( $appData, $id ) === $before );
};

$stopped( 999, 'unknown', 'no such document' );
$stopped( $gone['id'], 'unknown', 'a draft that was deleted' );
$plain = Documents::add( $appData, 'invoice', $first['id'] );
$stopped( $plain['id'], 'empty', 'no line' );
Documents::change( $appData, $plain['id'], [ 'lines' => $lines ] );
$stopped( $plain['id'], 'template', 'no template chosen' );
Documents::change( $appData, $plain['id'], [ 'template_key' => 'rechnung' ] );
$stopped( $plain['id'], 'inputs:intro', 'a required input of the template is empty' );
Documents::change( $appData, $plain['id'], [ 'inputs' => [ 'intro' => '   ' ] ] );
$stopped( $plain['id'], 'inputs:intro', 'a required input of only blanks' );
Documents::change( $appData, $plain['id'], [ 'inputs' => [ 'intro' => 'Guten Tag' ] ] );

$idle = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
\Crm\Clients::setActive( $appData, $first['id'], false );
$stopped( $idle['id'], 'inactive', 'the client was switched off after the draft was made' );
\Crm\Clients::setActive( $appData, $first['id'], true );
Documents::remove( $appData, $idle['id'] );

// A client that has not given what an invoice names it by cannot be written an offer, an invoice or a credit note: the draft is made, saved and seen, finalising stops before anything is spent
$sparse = (array) \Crm\Clients::get( $appData, $second['id'] );
Db::exec( $appData, 'UPDATE clients SET street = \'\', zip = \'\', city = \'\', country = \'\' WHERE id = :id', [ 'id' => $sparse['id'] ] );
$thin = Documents::add( $appData, 'invoice', $sparse['id'], [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
check( 'a draft is made, saved and seen as a preview for a client with no address: the client is not ready for an invoice, and nothing of that is in the way of the draft', is_array( $thin ) && $thin['client']['ready'] === false && is_array( Documents::change( $appData, $thin['id'], [ 'notes' => 'ein Satz' ] ) ) && is_array( Documents::preview( $appData, $thin['id'] ) ) );
$stopped( $thin['id'], 'unready', 'the client has a name and no address at all' );
\Crm\Clients::change( $appData, $sparse['id'], [ 'street' => 'Kaistr. 9', 'zip' => '24103' ] );
$stopped( $thin['id'], 'unready', 'the client has a street and a postal code and no city' );

foreach( [ 'street', 'zip', 'city', 'country' ] as $missing ) {
	Db::exec( $appData, 'UPDATE clients SET city = \'Kiel\', street = \'Kaistr. 9\', zip = \'24103\', country = \'DE\' WHERE id = :id', [ 'id' => $sparse['id'] ] );
	Db::exec( $appData, 'UPDATE clients SET '. $missing. ' = \'\' WHERE id = :id', [ 'id' => $sparse['id'] ] );
	$offer = Documents::add( $appData, 'offer', $sparse['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
	$stopped( $offer['id'], 'unready', 'an offer, '. $missing. ' missing' );
	$stopped( $thin['id'], 'unready', 'an invoice, '. $missing. ' missing' );
	Documents::remove( $appData, $offer['id'] );
}

Db::exec( $appData, 'UPDATE clients SET city = \'Kiel\', street = \'Kaistr. 9\', zip = \'24103\', country = \'DE\', company = \'\', name = \'\' WHERE id = :id', [ 'id' => $sparse['id'] ] );
$stopped( $thin['id'], 'unready', 'an address and neither a company nor a name' );
Documents::remove( $appData, $thin['id'] );
Db::exec( $appData, 'UPDATE clients SET company = :company, name = :name, street = :street, zip = :zip, city = :city, country = :country WHERE id = :id', array_intersect_key( $sparse, array_flip( [ 'id', 'company', 'name', 'street', 'zip', 'city', 'country' ] ) ) );

$gone = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines ] );
$post( 'pdf/templates/save', [ 'key' => 'zeitweise', 'isNew' => true, 'name' => 'Zeitweise', 'tag' => 'invoice', 'blocks' => [] ] );
Documents::change( $appData, $gone['id'], [ 'template_key' => 'zeitweise' ] );
$post( 'pdf/templates/delete', [ 'key' => 'zeitweise' ] );
$stopped( $gone['id'], 'template', 'the template was deleted after the draft was made' );
check( '...and a draft with that key can be saved no more, the document keeps the key it has', Documents::change( $appData, $gone['id'], [ 'template_key' => 'zeitweise' ] ) === 'template' && Documents::get( $appData, $gone['id'] )['template_key'] === 'zeitweise' );
Documents::remove( $appData, $gone['id'] );

\Nino\Features::deactivate( $appData, 'pdf' );
$stopped( $plain['id'], 'pdf', 'Pdf is not active' );
check( '...a template cannot be chosen either', Documents::change( $appData, $plain['id'], [ 'template_key' => 'rechnung' ] ) === 'template' );
\Nino\Features::activate( $appData, 'pdf' );
\Nino\Html::init( $appData );
$ignored = ninoWarnings();

$finalised = Documents::finalise( $appData, $plain['id'] );
$expectedNumber = $number( 'R', $year, 1 );
check( 'the first invoice of a year is R-<year>-0001, open, with the time it was finalised', is_array( $finalised ) && $finalised['number'] === $expectedNumber && $finalised['state'] === 'open' && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $finalised['finalised'] ) === 1 );
check( '...the totals are the ones of the lines', $finalised['net'] === 23700 && $finalised['vat'] === 0 && $finalised['gross'] === 23700 && array_column( $finalised['lines'], 'amount_net' ) === [ 22500, 1200 ] );
check( '...the sequence of the category and year counts on', $sequences() === [ [ 'category' => 'invoice', 'year' => (string) $year, 'next' => '2' ] ] || $sequences() === [ [ 'category' => 'invoice', 'year' => $year, 'next' => 2 ] ] );

$file = \Nino\Filesystem::path( $appData, $finalised['pdf_path'] );
$bytes = (string) file_get_contents( $file );
check( '...the PDF is below /data/crm/pdf/<year>/<number>.pdf, starts with %PDF and has the hash that is stored', $finalised['pdf_path'] === Render::DIR. '/'. $year. '/'. $expectedNumber. '.pdf' && is_file( $file ) === true && str_starts_with( $bytes, '%PDF' ) === true && hash( 'sha256', $bytes ) === $finalised['pdf_sha256'] && preg_match( '/^[0-9a-f]{64}$/D', $finalised['pdf_sha256'] ) === 1 );
check( '...no temporary file is left beside it', glob( dirname( $file ). '/*' ) === [ $file ] );

$data = Render::data( $appData, $finalised, $finalised['lines'], $finalised['client_frozen'] );
$again = \Nino\Modules\Pdf::render( $appData, 'rechnung', $data, [ 'title' => 'Rechnung '. $expectedNumber, 'date' => $finalised['date'] ] );
check( '...Pdf renders the same bytes again from the same data: a PDF can be made again and compared', $again === $bytes );
check( '...the title of the file is the kind of document and its number', str_contains( $bytes, '/Title (Rechnung '. $expectedNumber. ')' ) === true );

check( '...the client is frozen into the document: company, name, address, number, VAT id, email', $finalised['client_frozen'] === [ 'company' => 'Studio Nord GmbH', 'name' => 'Anna Nord', 'street' => 'Hafenstr. 1', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE', 'number' => 'K-0001', 'vat_id' => 'DE123456789', 'email' => 'anna@example.org', 'business' => '0', 'buyer_reference' => '' ] && $finalised['client'] === $finalised['client_frozen'] );
\Crm\Clients::change( $appData, $first['id'], [ 'company' => 'Studio Nord AG', 'city' => 'Bremen' ] );
check( '...a change of the client afterwards leaves the address of the document as it was', Documents::get( $appData, $finalised['id'] )['client']['company'] === 'Studio Nord GmbH' && Documents::get( $appData, $finalised['id'] )['client']['city'] === 'Hamburg' );
check( 'a draft shows the live client in the list, a finalised document its frozen one', in_array( 'Studio Nord GmbH', array_column( Documents::list( $appData ), 'client_title' ), true ) === true && in_array( 'Studio Nord AG', array_column( Documents::list( $appData ), 'client_title' ), true ) === true );
\Crm\Clients::change( $appData, $first['id'], [ 'company' => 'Studio Nord GmbH', 'city' => 'Hamburg' ] );

$secondInvoice = Documents::finalise( $appData, $draft['id'] );
check( 'the second invoice of the year takes the next number', is_array( $secondInvoice ) && $secondInvoice['number'] === $number( 'R', $year, 2 ) );
check( 'a document that is finalised cannot be finalised again, and takes no number', Documents::finalise( $appData, $plain['id'] ) === 'fixed' && ( $sequences()[0]['next'] ?? 0 ) == 3 );

$old = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2025-12-31', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$oldFinal = Documents::finalise( $appData, $old['id'] );
check( 'the number is counted per year of the date of the document: an invoice of 2025 is R-2025-0001', is_array( $oldFinal ) && $oldFinal['number'] === 'R-2025-0001' && $oldFinal['pdf_path'] === Render::DIR. '/2025/R-2025-0001.pdf' );

$offerDraft = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2026-03-10', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'Angebot' ] ] );
$offerFinal = Documents::finalise( $appData, $offerDraft['id'] );
check( 'an offer has its own sequence: A-2026-0001', is_array( $offerFinal ) && $offerFinal['number'] === 'A-2026-0001' && $offerFinal['state'] === 'open' );
check( '...and so has a credit note: G-<year>-0001', ( static function() use ( &$appData, $oldFinal, $year, $number ): bool {
	$credit = documentsPartialCredit( $appData, $oldFinal['id'], 'Hosting', '-1', '12,00' );
	$final = Documents::finalise( $appData, $credit['id'] );
	return is_array( $final ) && $final['number'] === $number( 'G', $year, 1 );
} )() );

Settings::set( $appData, 'number_invoice', 'RE{YY}-{NNN}' );
$formatted = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$formattedFinal = Documents::finalise( $appData, $formatted['id'] );
check( 'a changed number format applies to the next number, the sequence counts on', is_array( $formattedFinal ) && $formattedFinal['number'] === sprintf( 'RE%02d-%03d', $year % 100, 3 ) );
Settings::set( $appData, 'number_invoice', 'R-{YYYY}-{NNNN}' );

// A failure after the number was taken gives the number back: the numbers of a category and year have no gap
echo "\nA finalising that fails after the number was taken\n";

$blocked = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2024-05-05', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
\Nino\Filesystem::forceDir( $appData, Render::DIR );
file_put_contents( $pdfDir. '/2024', 'a file where the directory of the year should be' );
$before = $sequences();
$refused = Documents::finalise( $appData, $blocked['id'] );
$warnings = ninoWarnings();
check( 'a PDF that cannot be written is the code write, with one log line that names the number and no path', $refused === 'write' && $warnings === [ 'Crm: the PDF of document R-2024-0001 could not be written' ] );
check( '...the transaction is rolled back, the number is not spent, the draft is a draft', $refused === 'write' && $sequences() === $before && Documents::get( $appData, $blocked['id'] )['state'] === 'draft' && Documents::get( $appData, $blocked['id'] )['number'] === '' && Documents::get( $appData, $blocked['id'] )['pdf_path'] === '' );
unlink( $pdfDir. '/2024' );
$retried = Documents::finalise( $appData, $blocked['id'] );
check( '...so the retry takes the number the failure would have: R-2024-0001', is_array( $retried ) && $retried['number'] === 'R-2024-0001' );

$taken = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2023-05-05', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
\Nino\Filesystem::forceDir( $appData, Render::DIR. '/2023' );
file_put_contents( $pdfDir. '/2023/R-2023-0001.pdf', 'somebody else\'s file' );
$before = $sequences();
check( 'a PDF that is there already is the code exists: it is not overwritten, nothing is spent, nothing is stored', Documents::finalise( $appData, $taken['id'] ) === 'exists:'. Render::DIR. '/2023/R-2023-0001.pdf' && file_get_contents( $pdfDir. '/2023/R-2023-0001.pdf' ) === 'somebody else\'s file' && $sequences() === $before && Documents::get( $appData, $taken['id'] )['state'] === 'draft' );
unlink( $pdfDir. '/2023/R-2023-0001.pdf' );

$collide = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2022-05-05', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
Db::exec( $appData, 'UPDATE documents SET number = :n WHERE id = :id', [ 'n' => 'R-2022-0001', 'id' => $taken['id'] ] );
$before = $sequences();
check( 'a number a document has already is the code number: nothing is spent', Documents::finalise( $appData, $collide['id'] ) === 'number' && $sequences() === $before && Documents::get( $appData, $collide['id'] )['state'] === 'draft' );
Db::exec( $appData, 'UPDATE documents SET number = NULL WHERE id = :id', [ 'id' => $taken['id'] ] );
Settings::set( $appData, 'number_invoice', 'R-{YYYY}-{NNNN}' );
Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = \'number_invoice\'', [ 'v' => 'R/{YYYY}' ] );
check( 'a number format that was broken behind the back of the settings is the code number, too', Documents::finalise( $appData, $collide['id'] ) === 'number' && $sequences() === $before );
Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = \'number_invoice\'', [ 'v' => 'R-{YYYY}-{NNNN}' ] );

$ghost = Render::make( $appData, [ 'category' => 'invoice', 'number' => 'R-2018-0001', 'date' => '2018-01-01', 'template_key' => 'weg', 'vat_mode' => 'small_business', 'reverse_charge' => false, 'net' => 0, 'vat' => 0, 'gross' => 0 ] , [], [] );
$warnings = ninoWarnings();
check( 'a template Pdf has not is the code render, with the warning of Pdf as the log line and no file', $ghost === 'render' && count( $warnings ) === 1 && str_contains( $warnings[0], 'there is no template' ) === true && is_file( $pdfDir. '/2018/R-2018-0001.pdf' ) === false );

$stale = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2019-04-04', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
Db::exec( $appData, 'UPDATE documents SET net = 1, vat = 1, gross = 1 WHERE id = :id', [ 'id' => $stale['id'] ] );
Db::exec( $appData, 'UPDATE document_lines SET amount_net = 1 WHERE document_id = :id', [ 'id' => $stale['id'] ] );
$staleFinal = Documents::finalise( $appData, $stale['id'] );
check( 'finalising computes the totals and the amounts once more, from the lines: totals and amounts a draft had gone wrong with are right afterwards', is_array( $staleFinal ) && $staleFinal['net'] === 23700 && $staleFinal['vat'] === 0 && $staleFinal['gross'] === 23700 && array_column( $staleFinal['lines'], 'amount_net' ) === [ 22500, 1200 ] );

$late = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2021-02-02', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
Db::exec( $appData, 'CREATE TRIGGER no_number BEFORE UPDATE OF number ON documents BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
$before = $sequences();
$thrown = '';

try {
	Documents::finalise( $appData, $late['id'] );
}
catch( \PDOException $e ) {
	$thrown = 'thrown';
}

Db::exec( $appData, 'DROP TRIGGER no_number' );
check( 'a database failure after the PDF was written is thrown for the caller to answer, and takes the file back: no number spent, the draft a draft', $thrown === 'thrown' && is_file( $pdfDir. '/2021/R-2021-0001.pdf' ) === false && $sequences() === $before && Documents::get( $appData, $late['id'] )['state'] === 'draft' );
check( '...and the retry takes the number the failure would have: R-2021-0001', ( Documents::finalise( $appData, $late['id'] )['number'] ?? '' ) === 'R-2021-0001' );
$expectedYears = array_values( array_unique( array_merge( [ $year ], [ 2026, 2025, 2024, 2023, 2022, 2021, 2019 ] ) ) );
rsort( $expectedYears );
check( 'years() lists the years of the dates, the latest first', Documents::years( $appData ) === $expectedYears );

// --- Rendering: tokens and slots ---------------------------------------------------------------------------------------

echo "\nTokens and slots\n";

$allow = static fn( string $html ): string => \Nino\Modules\Pdf\Layout\Allow::html( $html );
$data = Render::data( $appData, $secondInvoice, $secondInvoice['lines'], $secondInvoice['client_frozen'] );
$tokens = $data['tokens'];
check( 'the tokens of the document: title, number, dates as d.m.Y, the period, no validity on an invoice', $tokens['/document/title'] === 'Rechnung' && $tokens['/document/number'] === $secondInvoice['number'] && $tokens['/document/date'] === Render::day( $secondInvoice['date'] )
	&& $tokens['/invoice/due'] === Render::day( $secondInvoice['due_date'] ) && isset( $tokens['/offer/valid'] ) === false && $tokens['/invoice/period'] === '01.01.2026 - 31.01.2026' && $tokens['/document/parent'] === '' );
check( '...the amounts with the currency behind a non-breaking space', $tokens['/document/net'] === "237,00\u{00A0}€" && $tokens['/document/vat'] === "0,00\u{00A0}€" && $tokens['/document/gross'] === "237,00\u{00A0}€" );
check( '...the client: number, company, name, street, zip, city, country, VAT id, email', array_intersect_key( $tokens, array_flip( preg_grep( '~^/customer/~', array_keys( $tokens ) ) ) ) === [ '/customer/number' => 'K-0001', '/customer/company' => 'Studio Nord GmbH', '/customer/name' => 'Anna Nord', '/customer/street' => 'Hafenstr. 1', '/customer/zip' => '20095', '/customer/city' => 'Hamburg', '/customer/country' => 'DE', '/customer/email' => 'anna@example.org', '/customer/vat' => 'DE123456789' ] );
check( 'in small_business the VAT note is the sentence of § 19 UStG', $tokens['/document/vatnote'] === 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' );
check( 'a document with no period says that the service date is the date of the invoice; an offer says nothing of it', Render::data( $appData, $finalised, $finalised['lines'], $finalised['client_frozen'] )['tokens']['/invoice/period'] === 'Leistungsdatum entspricht dem Rechnungsdatum' && array_key_exists( '/invoice/period', Render::data( $appData, $offerFinal, $offerFinal['lines'], $offerFinal['client_frozen'] )['tokens'] ) === false );
$offerTokens = Render::data( $appData, $offerFinal, $offerFinal['lines'], $offerFinal['client_frozen'] )['tokens'];
check( 'an offer has its own number, day, validity and sum under /offer and no due date: an invoice has no validity', $offerTokens['/document/title'] === 'Angebot' && $offerTokens['/offer/valid'] === '09.04.2026' && $offerTokens['/offer/number'] === $offerTokens['/document/number'] && $offerTokens['/offer/gross'] === $offerTokens['/document/gross']
	&& $offerTokens['/offer/date'] === $offerTokens['/document/date'] && isset( $offerTokens['/invoice/due'] ) === false && isset( $tokens['/offer/valid'] ) === false );
check( 'the vocabulary is the slash form and nothing else: an invoice has the document, invoice, customer and site keys - and none of the dotted names of before', array_keys( $tokens ) === [ '/site/portal', '/site/login', '/site/tax', '/site/bank', '/site/iban', '/site/bic', '/customer/number', '/customer/company', '/customer/name', '/customer/street', '/customer/zip', '/customer/city', '/customer/country', '/customer/email', '/customer/vat',
	'/document/title', '/document/number', '/document/date', '/document/parent', '/document/vatnote', '/document/net', '/document/vat', '/document/gross', '/invoice/due', '/invoice/due-sentence', '/invoice/period' ] );
check( '...and the old dotted names fill nothing any more, while the slash keys fill what they stand for', \Nino\Modules\Pdf\Layout\Tokens::fill( $appData, '[[document.number]]|[[client.company]]|[[site.portal]]|[[/document/number]]|[[/customer/company]]', $tokens ) === '|||'. $secondInvoice['number']. '|Studio Nord GmbH' );
check( 'the inputs are the document\'s own', $data['inputs'] === $secondInvoice['inputs'] );

// The particulars of the owner's invoices: the settings tax_id, bank_name, iban, bic, and the tokens a template reads them as
check( 'the four particulars are tokens of every document, empty where the settings are not set: /site/tax, /site/bank, /site/iban, /site/bic', array_intersect_key( $tokens, array_flip( [ '/site/tax', '/site/bank', '/site/iban', '/site/bic' ] ) ) === [ '/site/tax' => '', '/site/bank' => '', '/site/iban' => '', '/site/bic' => '' ]
	&& array_intersect_key( $offerTokens, array_flip( [ '/site/tax', '/site/iban' ] ) ) === [ '/site/tax' => '', '/site/iban' => '' ] );
check( '...the settings are keys with an empty default: all() has them, get() reads them, and a key that is none is still refused', Settings::get( $appData, 'iban' ) === '' && array_slice( array_keys( Settings::all( $appData ) ), -22 ) === [ 'tax_id', 'bank_name', 'iban', 'bic', 'paypal', 'seller_name', 'seller_street', 'seller_zip', 'seller_city', 'seller_country', 'seller_contact', 'seller_phone', 'seller_email', 'vat_id', 'vat_exempt_text', 'privacy_text', 'privacy_changed', 'portal_message', 'portal_message_at', 'consent_social', 'consent_archive', 'notify_files' ] && Settings::set( $appData, 'swift', 'x' ) === 'unknown' );
$particulars = [ 'tax_id' => '12/345/67890', 'bank_name' => 'Sparkasse Hamburg', 'iban' => 'de89 3704 0044 0532 0130 00', 'bic' => 'cobadeffxxx' ];
check( '...they are written where they pass their rule: an IBAN in capitals in groups of four, a BIC in capitals; set() answers true', array_filter( $particulars, static fn( string $value, string $key ): bool => Settings::set( $appData, $key, $value ) !== true, ARRAY_FILTER_USE_BOTH ) === []
	&& Settings::get( $appData, 'iban' ) === 'DE89 3704 0044 0532 0130 00' && Settings::get( $appData, 'bic' ) === 'COBADEFFXXX' && Settings::get( $appData, 'tax_id' ) === '12/345/67890' && Settings::get( $appData, 'bank_name' ) === 'Sparkasse Hamburg' );
$wrong = [ 'an IBAN with a wrong check digit' => [ 'iban', 'DE89 3704 0044 0532 0130 01' ], 'an IBAN that is too short' => [ 'iban', 'DE89 3704' ], 'an IBAN with a symbol' => [ 'iban', 'DE89 3704 0044 0532 0130 0!' ], 'a BIC of nine characters' => [ 'bic', 'COBADEFFX' ], 'a BIC with a digit in its bank' => [ 'bic', 'C0BADEFF' ],
	'a tax number with a tag' => [ 'tax_id', '12<b>' ], 'a tax number past forty characters' => [ 'tax_id', str_repeat( '1', 41 ) ], 'a bank with a line break' => [ 'bank_name', "Bank\nName" ], 'a bank past eighty characters' => [ 'bank_name', str_repeat( 'B', 81 ) ], 'an array' => [ 'iban', [ 'DE89' ] ] ];
check( '...and refused where they do not: '. implode( ', ', array_keys( $wrong ) ), array_filter( $wrong, static fn( array $pair ): bool => Settings::clean( $pair[0], $pair[1] ) !== [ 'value:'. $pair[0] ] ) === [] && Settings::get( $appData, 'iban' ) === 'DE89 3704 0044 0532 0130 00' );
check( '...each may be emptied again: nothing is a value too', Settings::clean( 'iban', '' ) === '' && Settings::clean( 'bic', '  ' ) === '' && Settings::clean( 'tax_id', '' ) === '' && Settings::clean( 'bank_name', '' ) === '' && Settings::iban( '' ) === '' && Settings::iban( 'GB82 WEST 1234 5698 7654 32' ) === 'GB82 WEST 1234 5698 7654 32' );
$again = Render::data( $appData, $secondInvoice, $secondInvoice['lines'], $secondInvoice['client_frozen'] )['tokens'];
check( 'a document hands the settings to its template under /site/*: the footer of a layout reads them as it reads the document\'s numbers', $again['/site/tax'] === '12/345/67890' && $again['/site/bank'] === 'Sparkasse Hamburg' && $again['/site/iban'] === 'DE89 3704 0044 0532 0130 00' && $again['/site/bic'] === 'COBADEFFXXX'
	&& \Nino\Modules\Pdf\Layout\Tokens::fill( $appData, '<p>Steuernummer [[/site/tax]] · IBAN [[/site/iban]]</p>', $again, false, true ) === '<p>Steuernummer 12/345/67890 · IBAN DE89 3704 0044 0532 0130 00</p>' );
foreach( array_keys( $particulars ) as $key )
	Settings::set( $appData, $key, '' );

check( 'the address block: company, name, street, zip and city - and no country for DE', $data['slots']['address'] === '<p>Studio Nord GmbH<br>Anna Nord<br>Hafenstr. 1<br>20095 Hamburg</p>' );
check( '...a client abroad has the country on a line of its own; without a company the name leads', Render::address( [ 'name' => 'Ben Süd', 'street' => 'Lindenweg 3', 'zip' => '1010', 'city' => 'Wien', 'country' => 'AT' ] ) === '<p>Ben Süd<br>Lindenweg 3<br>1010 Wien<br>'. ( extension_loaded( 'intl' ) === true ? 'Österreich' : 'AT' ). '</p>' && Render::address( [ 'name' => 'X', 'country' => 'DE' ] ) === '<p>X</p>' && Render::country( 'ZZ' ) !== '' );
check( '...every line of it is escaped, not only the first', Render::address( [ 'company' => 'A', 'street' => '<i>[x]</i>', 'city' => '&', 'country' => 'DE' ] ) === '<p>A<br>&lt;i&gt;&#91;x]&lt;/i&gt;<br>&amp;</p>' );
check( '...a value that is markup or a shortcode is text and not a fill: the brackets are written as references', Render::address( [ 'company' => '<b>[[x]]</b> & Co', 'country' => 'DE' ] ) === '<p>&lt;b&gt;&#91;&#91;x]]&lt;/b&gt; &amp; Co</p>' );

$linesSlot = $data['slots']['lines'];
check( 'the lines slot of small_business has no column of VAT: five columns, a row for each line', preg_match_all( '/<th[ >]/', $linesSlot ) === 5 && str_contains( $linesSlot, 'USt.' ) === false && substr_count( $linesSlot, '<tr>' ) === 3 );
check( '...position, title, quantity with its unit, unit price and amount in German form', str_contains( $linesSlot, '<td width="8%">1</td>' ) === true && str_contains( $linesSlot, '<td width="8%">2</td>' ) === true && str_contains( $linesSlot, '<td width="8%">0</td>' ) === false && str_contains( $linesSlot, 'width="48%"' ) === true && str_contains( $linesSlot, 'Webdesign' ) === true && str_contains( $linesSlot, '2,5 Std.' ) === true && str_contains( $linesSlot, '90,00&nbsp;€' ) === true && str_contains( $linesSlot, '225,00&nbsp;€' ) === true && str_contains( $linesSlot, '12,00&nbsp;€' ) === true );
check( '...and the slot passes the list of Pdf unchanged: the slot after Allow is the slot', $allow( $linesSlot ) === $linesSlot );
$totalsSlot = $data['slots']['totals'];
check( 'the totals slot of small_business is the gross line alone, which is the net', substr_count( $totalsSlot, '<tr>' ) === 1 && str_contains( $totalsSlot, 'Gesamtbetrag' ) === true && str_contains( $totalsSlot, '237,00&nbsp;€' ) === true && str_contains( $totalsSlot, 'zzgl.' ) === false && $allow( $totalsSlot ) === $totalsSlot );
check( 'the address slot passes the list of Pdf unchanged', $allow( $data['slots']['address'] ) === $data['slots']['address'] );

$regularFinal = Documents::change( $appData, $regular['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'Regelsteuer' ], 'lines' => [ $line( [ 'vat_rate' => 1900, 'description' => "Zeile eins\nZeile <zwei>" ] ), $line( [ 'title' => '[[/document/number]] Buch', 'qty' => '1', 'price' => '20', 'vat_rate' => 700 ] ) ] ] );
$regularFinal = Documents::finalise( $appData, $regular['id'] );
check( 'a regular document is finalised with its VAT per rate: 19 % of 225,00 and 7 % of 20,00', is_array( $regularFinal ) && $regularFinal['vat_mode'] === 'regular' && $regularFinal['net'] === 24500 && $regularFinal['vat'] === 4275 + 140 && $regularFinal['gross'] === 24500 + 4415 );
$regularData = Render::data( $appData, $regularFinal, $regularFinal['lines'], $regularFinal['client_frozen'] );
check( 'in regular the VAT note is empty', $regularData['tokens']['/document/vatnote'] === '' );
check( '...the lines slot has the column of the rate: six columns and the rate of each line', preg_match_all( '/<th[ >]/', $regularData['slots']['lines'] ) === 6 && str_contains( $regularData['slots']['lines'], 'USt.' ) === true && str_contains( $regularData['slots']['lines'], '19 %' ) === true && str_contains( $regularData['slots']['lines'], 'width="40%"' ) === true && str_contains( $regularData['slots']['lines'], 'width="48%"' ) === false && str_contains( $regularData['slots']['lines'], '7 %' ) === true );
check( '...a description is under its title, a line break in it is a break, a tag in it is text, a token in a title is not filled', str_contains( $regularData['slots']['lines'], 'Webdesign<br>Zeile eins<br>Zeile &lt;zwei&gt;' ) === true && str_contains( $regularData['slots']['lines'], '&#91;&#91;/document/number]] Buch' ) === true && str_contains( $regularData['slots']['lines'], '<zwei>' ) === false );
$unitSlot = Render::lines( [ [ 'title' => 'T', 'description' => '', 'qty' => 1000, 'unit' => '<b>[[x]]', 'price_net' => 100, 'vat_rate' => 0 ] ], Money::totals( [ [ 'qty' => 1000, 'price_net' => 100, 'vat_rate' => 0 ] ], 'small_business' ), false, '€' );
check( '...a unit is escaped as the rest of a line is: markup and brackets in it are characters', str_contains( $unitSlot, '1 &lt;b&gt;&#91;&#91;x]]' ) === true && str_contains( $unitSlot, '<b>' ) === false );
$regularLines = str_replace( '&#91;', '[', $regularData['slots']['lines'] );
check( '...the slot passes the list of Pdf unchanged (but for the brackets, which Pdf reads as the characters they are)', $allow( $regularLines ) === $regularLines );
check( '...the totals slot has the net, the VAT of each rate on its base, and the gross', substr_count( $regularData['slots']['totals'], '<tr>' ) === 4 && str_contains( $regularData['slots']['totals'], 'Nettobetrag' ) === true && str_contains( $regularData['slots']['totals'], 'zzgl. 19 % USt. auf 225,00&nbsp;€' ) === true
	&& str_contains( $regularData['slots']['totals'], 'zzgl. 7 % USt. auf 20,00&nbsp;€' ) === true && str_contains( $regularData['slots']['totals'], '42,75&nbsp;€' ) === true && str_contains( $regularData['slots']['totals'], '1,40&nbsp;€' ) === true && str_contains( $regularData['slots']['totals'], '289,15&nbsp;€' ) === true && $allow( $regularData['slots']['totals'] ) === $regularData['slots']['totals'] );

Settings::set( $appData, 'vat_mode', 'regular' );
$reverseDraft = Documents::add( $appData, 'invoice', $first['id'], [ 'reverse_charge' => true, 'lines' => [ $line( [ 'vat_rate' => 1900 ] ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'RC' ] ] );
Settings::set( $appData, 'vat_mode', 'small_business' );
$reverseFinal = Documents::finalise( $appData, $reverseDraft['id'] );
$reverseData = Render::data( $appData, $reverseFinal, $reverseFinal['lines'], $reverseFinal['client_frozen'] );
check( 'a reverse charge: the rates are 0, the VAT is 0, and the note is the sentence of the reverse charge', is_array( $reverseFinal ) && $reverseFinal['vat'] === 0 && $reverseFinal['gross'] === 22500 && array_unique( array_column( $reverseFinal['lines'], 'vat_rate' ) ) === [ 0 ]
	&& $reverseData['tokens']['/document/vatnote'] === 'Steuerschuldnerschaft des Leistungsempfängers.' && str_contains( $reverseData['slots']['lines'], '0 %' ) === true && substr_count( $reverseData['slots']['totals'], '<tr>' ) === 3 );

$words = static function( string $file ): string {
	return (string) shell_exec( 'pdftotext -layout '. escapeshellarg( $file ). ' - 2>/dev/null' );
};

if( trim( (string) @shell_exec( 'command -v pdftotext 2>/dev/null' ) ) !== '' ) {

	$text = $words( \Nino\Filesystem::path( $appData, $secondInvoice['pdf_path'] ) );
	check( 'the PDF of the invoice says its title and number, the client, the lines, the totals and the note of § 19', str_contains( $text, 'Rechnung '. $secondInvoice['number'] ) === true && str_contains( $text, 'Studio Nord GmbH' ) === true && str_contains( $text, 'Hafenstr. 1' ) === true
		&& str_contains( $text, 'Webdesign' ) === true && str_contains( $text, '225,00' ) === true && str_contains( $text, 'Gesamtbetrag' ) === true && str_contains( $text, '237,00' ) === true && str_contains( $text, '§ 19 UStG' ) === true );
	check( '...and the required input, the optional one and the date', str_contains( $text, 'Hallo' ) === true && str_contains( $text, 'Danke' ) === true && str_contains( $text, 'Ende der Rechnung' ) === true && str_contains( $text, Render::day( $secondInvoice['date'] ) ) === true );
	$regularText = $words( \Nino\Filesystem::path( $appData, $regularFinal['pdf_path'] ) );
	check( 'the PDF of a regular invoice has the rate column and the VAT per rate; a token in a title stays the characters it is', str_contains( $regularText, 'zzgl. 19 % USt.' ) === true && str_contains( $regularText, 'USt.' ) === true && str_contains( $regularText, '[[/document/number]] Buch' ) === true && str_contains( $regularText, '§ 19' ) === false );
	check( 'the PDF of a reverse charge says so', str_contains( $words( \Nino\Filesystem::path( $appData, $reverseFinal['pdf_path'] ) ), 'Steuerschuldnerschaft' ) === true );
}
else
	echo "note - pdftotext is not on the path: the pins on the words of the PDF were NOT run\n";

// --- States -------------------------------------------------------------------------------------------------------------

echo "\nStates\n";

$state = static fn( int $id ): string => (string) Documents::get( $appData, $id )['state'];
$openInvoice = Documents::get( $appData, $finalised['id'] );

$unsent = Documents::add( $appData, 'invoice', $first['id'] );
check( 'a draft has no step: 409 state, whatever the step', Documents::setState( $appData, $unsent['id'], 'sent' ) === 'state' && Documents::setState( $appData, $unsent['id'], 'open' ) === 'state' );
Documents::remove( $appData, $unsent['id'] );
check( 'an invoice goes open to sent to paid and nowhere else: open to paid is 409', Documents::setState( $appData, $finalised['id'], 'paid' ) === 'state' && $state( $finalised['id'] ) === 'open' );
check( 'sent takes the day it is given as sent_at', ( Documents::setState( $appData, $finalised['id'], 'sent', $today )['sent_at'] ?? '' ) === $today. 'T00:00:00Z' && $state( $finalised['id'] ) === 'sent' );
check( 'sent after sent is 409, and so is a step back or to open', Documents::setState( $appData, $finalised['id'], 'sent' ) === 'state' && Documents::setState( $appData, $finalised['id'], 'open' ) === 'state' && Documents::setState( $appData, $finalised['id'], 'draft' ) === 'state' );
check( 'a day that is none is refused and the state stays', Documents::setState( $appData, $finalised['id'], 'paid', '2026-13-01' ) === 'date:date' && Documents::setState( $appData, $finalised['id'], 'paid', 'heute' ) === 'date:date' && $state( $finalised['id'] ) === 'sent' );
check( 'paid takes the day as paid_at', ( Documents::setState( $appData, $finalised['id'], 'paid', $today )['paid_at'] ?? '' ) === $today. 'T00:00:00Z' && $state( $finalised['id'] ) === 'paid' );
check( 'a paid invoice moves no more; cancelled is not a step; a state nobody has is none', Documents::setState( $appData, $finalised['id'], 'sent' ) === 'state' && Documents::setState( $appData, $finalised['id'], 'cancelled' ) === 'state' && Documents::setState( $appData, $finalised['id'], 'paid' ) === 'state' && Documents::setState( $appData, $finalised['id'], 'archived' ) === 'state' && Documents::setState( $appData, 999, 'sent' ) === 'unknown' );
$sentNow = Documents::setState( $appData, $secondInvoice['id'], 'sent' );
check( 'sent without a day is the time of the step', preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $sentNow['sent_at'] ) === 1 && $sentNow['paid_at'] === null );

$matrix = [];

foreach( [ 'offer' => [ 'sent', 'accepted', 'declined', 'paid' ], 'credit' => [ 'sent', 'paid', 'accepted' ] ] as $category => $targets ) {

	foreach( $targets as $target ) {
		$d = $category === 'credit' ? documentsPartialCredit( $appData, $oldFinal['id'], 'Hosting', '-0,1', '12,00' )
			: Documents::add( $appData, $category, $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
		Documents::finalise( $appData, $d['id'] );
		$matrix[$category. ' open to '. $target] = Documents::setState( $appData, $d['id'], $target ) === 'state' ? 'refused' : 'taken';
	}
}

check( 'an offer goes from open to sent, accepted or declined and not to paid; a credit note from open to sent and nowhere else', $matrix === [ 'offer open to sent' => 'taken', 'offer open to accepted' => 'taken', 'offer open to declined' => 'taken', 'offer open to paid' => 'refused',
	'credit open to sent' => 'taken', 'credit open to paid' => 'refused', 'credit open to accepted' => 'refused' ] );
$o = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $o['id'] );
Documents::setState( $appData, $o['id'], 'sent' );
check( 'a sent offer is accepted or declined; once accepted it is done', Documents::setState( $appData, $o['id'], 'sent' ) === 'state' && ( Documents::setState( $appData, $o['id'], 'accepted' )['state'] ?? '' ) === 'accepted' && Documents::setState( $appData, $o['id'], 'declined' ) === 'state' && Documents::setState( $appData, $o['id'], 'accepted' ) === 'state' );

echo "\nAn offer past its validity\n";

$lapsed = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2020-01-01', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
$lapsedFinal = Documents::finalise( $appData, $lapsed['id'] );
check( 'an open offer whose validity lies in the past is shown as expired - and its state is still open', $lapsedFinal['number'] === 'A-2020-0001' && $lapsedFinal['valid_until'] === '2020-01-31' && $lapsedFinal['state'] === 'open' && $lapsedFinal['expired'] === true );
$future = Documents::add( $appData, 'offer', $first['id'], [ 'valid_until' => '2999-06-01', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
$futureFinal = Documents::finalise( $appData, $future['id'] );
$expiredIds = array_map( 'intval', array_column( Db::rows( $appData, 'SELECT id FROM documents WHERE category = \'offer\' AND state IN ( \'open\', \'sent\' ) AND valid_until < :today ORDER BY id', [ 'today' => date( 'Y-m-d' ) ] ), 'id' ) );
$listedIds = array_column( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['expired'] === true ), 'id' );
sort( $listedIds );
check( '...the list says expired for exactly those, and filters by it; an offer of the far future, a draft and an invoice never are', in_array( $lapsed['id'], $expiredIds, true ) === true && in_array( $future['id'], $expiredIds, true ) === false && $listedIds === $expiredIds
	&& ( static function() use ( &$appData, $expiredIds ): bool {
		$filtered = array_map( static fn( array $row ): int => $row['id'], Documents::list( $appData, [ 'state' => 'expired' ] ) );
		sort( $filtered );
		return $filtered === $expiredIds;
	} )() === true && $futureFinal['expired'] === false && count( Documents::list( $appData, [ 'state' => 'open', 'category' => 'offer' ] ) ) >= 1 );
check( '...it can still be accepted, and then it is no longer shown as expired', ( Documents::setState( $appData, $lapsed['id'], 'accepted' )['expired'] ?? true ) === false && in_array( $lapsed['id'], array_column( Documents::list( $appData, [ 'state' => 'expired' ] ), 'id' ), true ) === false );
check( 'an offer within its validity is not expired', Documents::get( $appData, $offerFinal['id'] )['expired'] === ( Documents::get( $appData, $offerFinal['id'] )['valid_until'] < date( 'Y-m-d' ) ) && Documents::expired( [ 'category' => 'offer', 'state' => 'sent', 'valid_until' => '2026-01-01' ], '2026-01-02' ) === true
	&& Documents::expired( [ 'category' => 'offer', 'state' => 'sent', 'valid_until' => '2026-01-02' ], '2026-01-02' ) === false && Documents::expired( [ 'category' => 'invoice', 'state' => 'sent', 'valid_until' => '2020-01-01' ], '2026-01-02' ) === false
	&& Documents::expired( [ 'category' => 'offer', 'state' => 'accepted', 'valid_until' => '2020-01-01' ], '2026-01-02' ) === false );

// --- An invoice from an offer ------------------------------------------------------------------------------------------

echo "\nAn invoice from an offer\n";

$fromOffer = Documents::fromOffer( $appData, $offerFinal['id'] );
check( 'fromOffer() makes an invoice draft for the same client with the lines of the offer and the offer as its parent', is_array( $fromOffer ) && $fromOffer['category'] === 'invoice' && $fromOffer['state'] === 'draft' && $fromOffer['client_id'] === $offerFinal['client_id'] && $fromOffer['parent_id'] === $offerFinal['id']
	&& array_map( static fn( array $l ): array => [ $l['title'], $l['qty'], $l['price_net'], $l['vat_rate'], $l['unit'] ], $fromOffer['lines'] ) === array_map( static fn( array $l ): array => [ $l['title'], $l['qty'], $l['price_net'], $l['vat_rate'], $l['unit'] ], $offerFinal['lines'] )
	&& $fromOffer['net'] === $offerFinal['net'] && $fromOffer['parent']['number'] === 'A-2026-0001' && $fromOffer['template_key'] === '' && $fromOffer['date'] === $today );
check( '...the offer lists its invoice as a child, and an invoice may be made from the same offer again', Documents::get( $appData, $offerFinal['id'] )['children'][0]['id'] === $fromOffer['id'] && is_array( Documents::fromOffer( $appData, $offerFinal['id'] ) ) );
check( '...a line of the invoice is its own: it does not follow the offer', ( static function() use ( &$appData, $fromOffer ): bool {
	$edited = Documents::change( $appData, $fromOffer['id'], [ 'lines' => [ [ 'title' => 'Nur eine', 'qty' => '1', 'price' => '1', 'vat_rate' => 0 ] ] ] );
	return is_array( $edited ) && count( Documents::get( $appData, $edited['parent_id'] )['lines'] ) === 2;
} )() );
check( 'an offer in the draft, or an invoice, or a credit note, gives no invoice; neither does a document nobody has', Documents::fromOffer( $appData, Documents::add( $appData, 'offer', $first['id'] )['id'] ) === 'state' && Documents::fromOffer( $appData, $finalised['id'] ) === 'state' && Documents::fromOffer( $appData, 999 ) === 'unknown' );
$declined = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $declined['id'] );
Documents::setState( $appData, $declined['id'], 'declined' );
check( 'a declined offer gives no invoice; an accepted one does', Documents::fromOffer( $appData, $declined['id'] ) === 'state' && is_array( Documents::fromOffer( $appData, $lapsed['id'] ) ) );
\Crm\Clients::setActive( $appData, $first['id'], false );
check( 'the client must be active', Documents::fromOffer( $appData, $offerFinal['id'] ) === 'inactive' && Documents::creditOf( $appData, $secondInvoice['id'] ) === 'inactive' );
\Crm\Clients::setActive( $appData, $first['id'], true );

// --- The second date is optional ---------------------------------------------------------------------------------------

echo "\nThe second date is optional\n";

$dated = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2026-03-01', 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$datedOffer = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2026-03-01', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
check( 'a new invoice and a new offer carry the day of the settings: 14 days and 30', $dated['due_date'] === '2026-03-15' && $datedOffer['valid_until'] === '2026-03-31' );
$clearedInvoice = Documents::change( $appData, $dated['id'], [ 'due_date' => '' ] );
$clearedOffer = Documents::change( $appData, $datedOffer['id'], [ 'valid_until' => '' ] );
check( 'an empty second date is saved as none, in an invoice and in an offer - and read back as none', is_array( $clearedInvoice ) && $clearedInvoice['due_date'] === null && Documents::get( $appData, $dated['id'] )['due_date'] === null
	&& is_array( $clearedOffer ) && $clearedOffer['valid_until'] === null && Documents::get( $appData, $datedOffer['id'] )['valid_until'] === null );
check( '...a change that does not name the date keeps it none, and a date can be given again', Documents::change( $appData, $dated['id'], [ 'notes' => 'n' ] )['due_date'] === null && Documents::change( $appData, $dated['id'], [ 'due_date' => '2026-03-20' ] )['due_date'] === '2026-03-20'
	&& Documents::change( $appData, $dated['id'], [ 'due_date' => '' ] )['due_date'] === null );
check( '...the first date stays required, whatever the second says', Documents::change( $appData, $dated['id'], [ 'date' => '', 'due_date' => '' ] ) === 'date:date' );

Settings::set( $appData, 'payment_days', '0' );
Settings::set( $appData, 'offer_days', '0' );
$noPay = Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2026-03-01' ] );
$noOffer = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2026-03-01' ] );
check( 'a setting of 0 days prefills nothing: a new invoice has no due date and a new offer no validity', $noPay['due_date'] === null && $noOffer['valid_until'] === null );
check( '...a date that is given with the draft is kept all the same', Documents::add( $appData, 'invoice', $first['id'], [ 'date' => '2026-03-01', 'due_date' => '2026-04-01' ] )['due_date'] === '2026-04-01' );
$offerForInvoice = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2026-03-01', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $offerForInvoice['id'] );
$derived = Documents::fromOffer( $appData, $offerForInvoice['id'] );
check( '...an invoice made from an offer has none either, where the setting is 0', is_array( $derived ) && $derived['due_date'] === null );
Settings::set( $appData, 'payment_days', '14' );
Settings::set( $appData, 'offer_days', '30' );
$derivedDated = Documents::fromOffer( $appData, $offerForInvoice['id'] );
check( '...and the day of the setting where it is not', is_array( $derivedDated ) && $derivedDated['due_date'] === date( 'Y-m-d', strtotime( '+14 days' ) ) );

check( 'an offer without a validity day is never expired, an invoice without a due day never overdue - whatever the day is', Documents::expired( [ 'category' => 'offer', 'state' => 'open', 'valid_until' => null ], '2099-01-01' ) === false
	&& Documents::expired( [ 'category' => 'offer', 'state' => 'sent' ], '2099-01-01' ) === false && Documents::overdue( [ 'category' => 'invoice', 'state' => 'open', 'due_date' => null ], '2099-01-01' ) === false && Documents::overdue( [ 'category' => 'invoice', 'state' => 'sent' ], '2099-01-01' ) === false );

Documents::finalise( $appData, $dated['id'] );
Documents::finalise( $appData, $datedOffer['id'] );
$undatedInvoice = Documents::get( $appData, $dated['id'] );
$undatedOffer = Documents::get( $appData, $datedOffer['id'] );
check( 'a finalised document without a second date is read as neither overdue nor expired, and the offer can be ordered while it is open', $undatedInvoice['due_date'] === null && $undatedInvoice['overdue'] === false && $undatedOffer['valid_until'] === null && $undatedOffer['expired'] === false && Documents::orderable( $undatedOffer ) === true );
$noDay = Render::data( $appData, $undatedInvoice, $undatedInvoice['lines'], $undatedInvoice['client_frozen'] )['tokens'];
$noValid = Render::data( $appData, $undatedOffer, $undatedOffer['lines'], $undatedOffer['client_frozen'] )['tokens'];
check( 'without a date the tokens of the day are empty strings and the sentences say the other thing: payment at once with the number of the invoice, an offer that is freibleibend', $noDay['/invoice/due'] === '' && $noValid['/offer/valid'] === ''
	&& $noDay['/invoice/due-sentence'] === Render::text( 'due.sentence-none', $undatedInvoice['number'] ) && $noValid['/offer/valid-sentence'] === Render::text( 'valid.sentence-none' ) && str_contains( $noDay['/invoice/due-sentence'], $undatedInvoice['number'] ) === true );
Documents::change( $appData, $derivedDated['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ], 'lines' => $lines ] );
$datedFinal = Documents::finalise( $appData, $derivedDated['id'] );
$withDay = Render::data( $appData, $datedFinal, $datedFinal['lines'], $datedFinal['client_frozen'] )['tokens'];
check( 'with a date the sentence names the day and the number, and the token of the day is the day', $withDay['/invoice/due'] === Render::day( $datedFinal['due_date'] ) && $withDay['/invoice/due-sentence'] === Render::text( 'due.sentence', Render::day( $datedFinal['due_date'] ), $datedFinal['number'] )
	&& str_contains( $withDay['/invoice/due-sentence'], Render::day( $datedFinal['due_date'] ) ) === true && $withDay['/invoice/due-sentence'] !== $noDay['/invoice/due-sentence'] );
check( '...an offer with a day: its sentence names the day, and is none of the sentences without one', $offerTokens['/offer/valid-sentence'] === Render::text( 'valid.sentence', '09.04.2026' ) && $offerTokens['/offer/valid-sentence'] !== $noValid['/offer/valid-sentence'] );
check( '...the two sentences are tokens of the invoice and of the offer only: an invoice has no validity sentence, an offer no payment sentence', isset( $withDay['/offer/valid-sentence'] ) === false && isset( $offerTokens['/invoice/due-sentence'] ) === false );
$filled = \Nino\Modules\Pdf\Layout\Tokens::fill( $appData, '[[/invoice/due-sentence]]|[[/offer/valid-sentence]]', $noDay + $noValid );
check( '...a template that writes them gets the words and nothing of the brackets', $filled === $noDay['/invoice/due-sentence']. '|'. $noValid['/offer/valid-sentence'] );

// --- A credit note -----------------------------------------------------------------------------------------------------

echo "\nA credit note\n";

$credit = Documents::creditOf( $appData, $secondInvoice['id'] );
check( 'creditOf() makes a credit note draft with the lines of the invoice negated and the invoice as its parent', is_array( $credit ) && $credit['category'] === 'credit' && $credit['state'] === 'draft' && $credit['parent_id'] === $secondInvoice['id'] && array_column( $credit['lines'], 'qty' ) === [ -2500, -1000 ]
	&& array_column( $credit['lines'], 'price_net' ) === [ 9000, 1200 ] && array_column( $credit['lines'], 'amount_net' ) === [ -22500, -1200 ] && $credit['net'] === -23700 && $credit['gross'] === -23700 && $credit['vat_mode'] === $secondInvoice['vat_mode'] );
check( '...it is listed under the invoice', array_column( Documents::get( $appData, $secondInvoice['id'] )['children'], 'id' ) === [ $credit['id'] ] );
check( '...it has the period of the invoice and no due date', $credit['period_from'] === '2026-01-01' && $credit['period_to'] === '2026-01-31' && $credit['due_date'] === null );
check( 'a credit note has neither a due date nor a validity date: it returns money', $credit['valid_until'] === null && Documents::change( $appData, $credit['id'], [ 'due_date' => '2026-12-01' ] )['due_date'] === null );
check( 'a credit note of a credit note, of a draft invoice and of an offer is 409 state', Documents::creditOf( $appData, $credit['id'] ) === 'state' && Documents::creditOf( $appData, $offerFinal['id'] ) === 'state' && Documents::creditOf( $appData, Documents::add( $appData, 'invoice', $first['id'] )['id'] ) === 'state' );
check( '...a document nobody has gives none', Documents::creditOf( $appData, 999 ) === 'unknown' );

$partial = Documents::change( $appData, $credit['id'], [ 'lines' => [ [ 'title' => 'Webdesign', 'unit' => 'Std.', 'qty' => '-1', 'price' => '90', 'vat_rate' => 0 ] ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'Teilgutschrift' ] ] );
$partialFinal = Documents::finalise( $appData, $credit['id'] );
check( 'a credit note that takes a part back leaves the state of the invoice, and has its own number G-<year>-...', is_array( $partialFinal ) && $partialFinal['gross'] === -9000 && str_starts_with( $partialFinal['number'], 'G-' ) && $state( $secondInvoice['id'] ) === 'sent' );
$creditTokens = Render::data( $appData, $partialFinal, $partialFinal['lines'], $partialFinal['client_frozen'] )['tokens'];
check( 'the tokens of a credit note: its title, the number of the invoice it refers to, no due date, the period of that invoice', $creditTokens['/document/title'] === 'Stornorechnung' && $creditTokens['/document/parent'] === $secondInvoice['number'] && $creditTokens['/invoice/due'] === '' && isset( $creditTokens['/offer/valid'] ) === false && $creditTokens['/invoice/period'] === '01.01.2026 - 31.01.2026' );
check( '...and the sentence of the payment terms too, without a day (the README promises it): its own number, no day', ( $creditTokens['/invoice/due-sentence'] ?? null) === Render::text( 'due.sentence-none', $partialFinal['number'] ) );
$over = Documents::creditOf( $appData, $secondInvoice['id'] );
Documents::change( $appData, $over['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$before = $sequences();
check( 'a credit note that takes back more than is left of the invoice (90 are taken back already) is refused when it is finalised: nothing is spent, it stays a draft, and the invoice keeps its state', Documents::finalise( $appData, $over['id'] ) === 'credit_over' && $sequences() === $before && $state( $over['id'] ) === 'draft' && $state( $secondInvoice['id'] ) === 'sent' );
$full = Documents::creditOf( $appData, $secondInvoice['id'] );
Documents::change( $appData, $full['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'Storno' ], 'lines' => [ [ 'title' => 'Webdesign', 'unit' => 'Std.', 'qty' => '-1,5', 'price' => '90', 'vat_rate' => 0 ], [ 'title' => 'Hosting', 'unit' => 'Monat', 'qty' => '-1', 'price' => '12,00', 'vat_rate' => 0 ] ] ] );
$stateBefore = $state( $secondInvoice['id'] );
$fullFinal = Documents::finalise( $appData, $full['id'] );
check( 'the credit notes that together take the whole of the invoice back cancel it in the step of the last one', $stateBefore === 'sent' && is_array( $fullFinal ) && $state( $secondInvoice['id'] ) === 'cancelled' );
check( 'a cancelled invoice has no step and gives no credit note; it lists both of its credit notes', Documents::setState( $appData, $secondInvoice['id'], 'paid' ) === 'state' && Documents::creditOf( $appData, $secondInvoice['id'] ) === 'state' && count( Documents::get( $appData, $secondInvoice['id'] )['children'] ) === 3 );
$dup = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$dupFinal = Documents::finalise( $appData, $dup['id'] );
check( 'an invoice that is open gives no invoice from an offer (it is no offer)', Documents::fromOffer( $appData, $dup['id'] ) === 'state' );
$c1 = Documents::creditOf( $appData, $dup['id'] );
$c2 = Documents::creditOf( $appData, $dup['id'] );
Documents::change( $appData, $c1['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'Storno' ] ] );
Documents::change( $appData, $c2['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'Storno' ] ] );
$cancelled = Documents::finalise( $appData, $c1['id'] );
$before = $sequences();
check( 'an invoice that was cancelled by one credit note is not taken back by a second: the second is 409 state, nothing is spent and it stays a draft', is_array( $cancelled ) && $state( $dup['id'] ) === 'cancelled' && Documents::finalise( $appData, $c2['id'] ) === 'state' && $sequences() === $before && $state( $c2['id'] ) === 'draft' );

$smallOffer = Documents::add( $appData, 'offer', $first['id'], [ 'period_from' => '2026-02-01', 'period_to' => '2026-02-28', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $smallOffer['id'] );
Settings::set( $appData, 'vat_mode', 'regular' );
$derived = Documents::fromOffer( $appData, $smallOffer['id'] );
Settings::set( $appData, 'vat_mode', 'small_business' );
check( 'an invoice made from an offer takes the VAT mode of the setting now, with every rate held to it: a small business offer gives a regular invoice with rates of the list', is_array( $derived ) && $derived['vat_mode'] === 'regular' && array_unique( array_column( $derived['lines'], 'vat_rate' ) ) === [ 0 ] && $derived['vat'] === 0 );
check( '...and the period of the offer', $derived['period_from'] === '2026-02-01' && $derived['period_to'] === '2026-02-28' && $derived['due_date'] === Documents::addDays( $today, 14 ) );
$periodOffer = Documents::dataOf( $appData, (array) Documents::get( $appData, $smallOffer['id'] ) )['tokens'];
$periodContract = Documents::add( $appData, 'contract', 0, [ 'parent_id' => $smallOffer['id'], 'title' => 'Vertrag zum Angebot', 'template_key' => 'vertrag' ] );
$periodInvoice = Documents::dataOf( $appData, (array) Documents::get( $appData, $derived['id'] ) )['tokens'];
$periodContractPeriod = Documents::dataOf( $appData, (array) Documents::get( $appData, $periodContract['id'] ) )['tokens']['/offer/period'] ?? null;
Documents::remove( $appData, $periodContract['id'] );
check( 'an offer with a period carries it as /offer/period, formatted as the invoice\'s is, and no /invoice/period; a contract on that offer carries the offer\'s; an invoice carries no /offer/period, and an offer with none has an empty one',
	$periodOffer['/offer/period'] === '01.02.2026 - 28.02.2026' && array_key_exists( '/invoice/period', $periodOffer ) === false && is_array( $periodContract )
	&& $periodContractPeriod === '01.02.2026 - 28.02.2026' && array_key_exists( '/offer/period', $periodInvoice ) === false && $periodInvoice['/invoice/period'] === '01.02.2026 - 28.02.2026'
	&& Render::data( $appData, $offerFinal, $offerFinal['lines'], $offerFinal['client_frozen'] )['tokens']['/offer/period'] === Render::text( 'period.none-offer' ) && Render::text( 'period.none-offer' ) !== '' );
check( '...making it accepts the offer that was open', $state( $smallOffer['id'] ) === 'accepted' && $derived['parent_id'] === $smallOffer['id'] );
Documents::fromOffer( $appData, $smallOffer['id'] );
check( '...an offer that is accepted stays accepted', $state( $smallOffer['id'] ) === 'accepted' );
$sentOffer = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $sentOffer['id'] );
Documents::setState( $appData, $sentOffer['id'], 'sent' );
Documents::fromOffer( $appData, $sentOffer['id'] );
check( '...so does an offer that was sent become accepted', $state( $sentOffer['id'] ) === 'accepted' );

Settings::set( $appData, 'vat_mode', 'regular' );
$taxed = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => [ $line( [ 'vat_rate' => 1900 ] ), $line( [ 'title' => 'Buch', 'qty' => '1', 'price' => '20', 'vat_rate' => 700 ] ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $taxed['id'] );
$kept = Documents::fromOffer( $appData, $taxed['id'] );
check( 'in regular an invoice keeps the rates of the offer that the list still has', is_array( $kept ) && $kept['vat_mode'] === 'regular' && array_column( $kept['lines'], 'vat_rate' ) === [ 1900, 700 ] );
Settings::set( $appData, 'vat_rates', '700,0' );
$taxed2 = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => [ $line( [ 'vat_rate' => 700 ] ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $taxed2['id'] );
Settings::set( $appData, 'vat_rates', '1900,0' );
$before = [ count( Documents::list( $appData ) ), $state( $taxed2['id'] ) ];
check( '...a rate the list does not have any more refuses the invoice with the code of the rate on the line; the offer stays as it was and nothing is made', Documents::fromOffer( $appData, $taxed2['id'] ) === 'line:0:rate:vat_rate' && $before === [ count( Documents::list( $appData ) ), $state( $taxed2['id'] ) ] && $state( $taxed2['id'] ) === 'open' );
Settings::set( $appData, 'vat_rates', '1900,700,0' );
Settings::set( $appData, 'vat_mode', 'small_business' );
$smallFromTaxed = Documents::fromOffer( $appData, $taxed['id'] );
check( 'in small_business every rate of the offer becomes 0', is_array( $smallFromTaxed ) && $smallFromTaxed['vat_mode'] === 'small_business' && array_unique( array_column( $smallFromTaxed['lines'], 'vat_rate' ) ) === [ 0 ] && $smallFromTaxed['vat'] === 0 );
$racing = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $racing['id'] );
$invoicesBefore = count( Documents::list( $appData, [ 'category' => 'invoice' ] ) );
Db::exec( $appData, 'CREATE TRIGGER decline_midway BEFORE INSERT ON documents WHEN NEW.category = \'invoice\' BEGIN UPDATE documents SET state = \'declined\' WHERE id = '. (int) $racing['id']. '; END' );
$raced = Documents::fromOffer( $appData, $racing['id'] );
Db::exec( $appData, 'DROP TRIGGER decline_midway' );
check( 'an offer that is declined between the check and the acceptance is not accepted: 409 state, no invoice is made, and the offer is left as the rolled back transaction found it', $raced === 'state' && count( Documents::list( $appData, [ 'category' => 'invoice' ] ) ) === $invoicesBefore && $state( $racing['id'] ) === 'open' );
Documents::setState( $appData, $racing['id'], 'accepted' );
$declinedNow = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $declinedNow['id'] );
Documents::setState( $appData, $declinedNow['id'], 'declined' );
check( '...and an offer that is declined when it is asked is the same answer', Documents::fromOffer( $appData, $declinedNow['id'] ) === 'state' && Documents::fromOffer( $appData, 99999 ) === 'unknown' );
$regularInvoice = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
Db::exec( $appData, 'UPDATE documents SET vat_mode = \'regular\' WHERE id = :id', [ 'id' => $regularInvoice['id'] ] );
Db::exec( $appData, 'UPDATE document_lines SET vat_rate = 1900 WHERE document_id = :id', [ 'id' => $regularInvoice['id'] ] );
Documents::finalise( $appData, $regularInvoice['id'] );
Settings::set( $appData, 'vat_rates', '700,0' );
$creditOfTaxed = Documents::creditOf( $appData, $regularInvoice['id'] );
Settings::set( $appData, 'vat_rates', '1900,700,0' );
check( 'a credit note keeps the mode and the rates of its invoice, whatever the setting and the list say now: it returns what was charged', is_array( $creditOfTaxed ) && $creditOfTaxed['vat_mode'] === 'regular' && array_unique( array_column( $creditOfTaxed['lines'], 'vat_rate' ) ) === [ 1900 ] && $creditOfTaxed['vat'] < 0 );

$partialOnly = Documents::get( $appData, $finalised['id'] );
check( 'a paid invoice can be taken back by a credit note', is_array( Documents::creditOf( $appData, $finalised['id'] ) ) && $partialOnly['state'] === 'paid' );

echo "\nA finalised document is fixed\n";

$fixed = Documents::get( $appData, $secondInvoice['id'] );
check( 'change() of a finalised document is 409 fixed, whatever it changes, and nothing is written', Documents::change( $appData, $fixed['id'], [ 'notes' => 'x' ] ) === 'fixed' && Documents::change( $appData, $fixed['id'], [ 'lines' => [] ] ) === 'fixed' && Documents::change( $appData, $fixed['id'], [ 'client_id' => $first['id'] ] ) === 'fixed' && Documents::get( $appData, $fixed['id'] ) === $fixed );
check( '...even where the input is wrong too: the document being fixed is the first answer', Documents::change( $appData, $fixed['id'], [ 'date' => 'x', 'lines' => 'x' ] ) === 'fixed' );
check( 'remove() of a finalised document is 409 fixed: it is never deleted, and its lines stay', Documents::remove( $appData, $fixed['id'] ) === 'fixed' && Documents::get( $appData, $fixed['id'] ) !== null && count( Documents::get( $appData, $fixed['id'] )['lines'] ) === 2 );
$deleted = '';

try {
	Db::exec( $appData, 'DELETE FROM documents WHERE id = :id', [ 'id' => $fixed['id'] ] );
}
catch( \PDOException $e ) {
	$deleted = 'restricted';
}

check( 'a document with a credit note under it cannot be deleted from under it (ON DELETE RESTRICT)', $deleted === 'restricted' );
$noted = Documents::setNotes( $appData, $fixed['id'], 'Gemahnt am 3.' );
check( 'the notes of a finalised document can be changed, and nothing else with them', ( $noted['notes'] ?? '' ) === 'Gemahnt am 3.' && Documents::setNotes( $appData, $fixed['id'], str_repeat( 'x', 5001 ) ) === 'long:notes' && Documents::setNotes( $appData, 999, 'x' ) === 'unknown'
	&& array_diff_key( $noted, [ 'notes' => 0, 'updated' => 0 ] ) === array_diff_key( $fixed, [ 'notes' => 0, 'updated' => 0 ] ) );

// A column changed behind the back of the model: the document still answers, and says what the column holds
$original = Db::row( $appData, 'SELECT gross, client_json FROM documents WHERE id = :id', [ 'id' => $fixed['id'] ] );
Db::exec( $appData, 'UPDATE documents SET gross = 1, client_json = :junk WHERE id = :id', [ 'junk' => 'not json', 'id' => $fixed['id'] ] );
$tampered = Documents::get( $appData, $fixed['id'] );
check( 'a column changed behind the back of the model: get() still answers, with what the column holds; a client_json that is none is no client', is_array( $tampered ) && $tampered['gross'] === 1 && $tampered['client'] === [] && count( $tampered['lines'] ) === 2 );
Db::exec( $appData, 'UPDATE documents SET gross = :gross, client_json = :json WHERE id = :id', [ 'gross' => $original['gross'], 'json' => $original['client_json'], 'id' => $fixed['id'] ] );
check( '...and nothing in the model writes the column again: finalising, changing and the steps leave a changed gross as it is', ( static function() use ( &$appData, $fixed ): bool {
	Db::exec( $appData, 'UPDATE documents SET gross = 7 WHERE id = :id', [ 'id' => $fixed['id'] ] );
	Documents::change( $appData, $fixed['id'], [ 'lines' => [] ] );
	Documents::finalise( $appData, $fixed['id'] );
	Documents::setNotes( $appData, $fixed['id'], 'again' );
	$kept = Documents::get( $appData, $fixed['id'] )['gross'] === 7;
	Db::exec( $appData, 'UPDATE documents SET gross = -23700 WHERE id = :id', [ 'id' => $fixed['id'] ] );
	return $kept;
} )() );

// --- The route that answers the PDF ---------------------------------------------------------------------------------------

echo "\nThe route /.crm/pdf/<id>\n";

function docServe( array &$appData, string $uri ): array {

	$request = ninoVisit( $appData, $uri );
	\Nino\Http::response( $appData, $request );

	return $request['/nino/http/response'];
}

\Nino\Auth::insertUser( $appData, 'plain@example.com', 'plain password', [] );
\Nino\Auth::insertUser( $appData, 'docs@example.com', 'docs password', [ '/_admin/crm/manage' ] );
\Nino\Auth::insertUser( $appData, 'other@example.com', 'other password', [ '/_admin/elements/manage' ] );
\Nino\Auth::insertUser( $appData, 'old@example.com', 'old password', [ '/_admin/crm-documents/manage', '/_admin/crm-clients/manage' ] );
$passwords = [ 'admin@example.com' => 'correct horse battery staple', 'plain@example.com' => 'plain password', 'docs@example.com' => 'docs password', 'other@example.com' => 'other password', 'old@example.com' => 'old password' ];
$as = static function( string $who ) use ( &$appData, $passwords ): void {
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );

	if( $who !== '' )
		\Nino\Auth::loginUser( $appData, $who, $passwords[$who] );
};

$target = Documents::get( $appData, $secondInvoice['id'] );
$uri = '/.crm/pdf/'. $target['id'];
$nothing = static fn( array $response ): bool => $response['statusCode'] === 404 && ( $response['file'] ?? null ) === null && $response['body'] === 'Not Found';

$as( '' );
check( 'a visitor is told 404, with a plain body and no file', $nothing( docServe( $appData, $uri ) ) === true && str_starts_with( docServe( $appData, $uri )['header']['Content-Type'], 'text/plain' ) );
$as( 'plain@example.com' );
check( 'an account without the permission is told the same 404', $nothing( docServe( $appData, $uri ) ) === true );
$as( 'other@example.com' );
check( '...the permission of another panel does not open it', $nothing( docServe( $appData, $uri ) ) === true );

$as( 'docs@example.com' );
$answer = docServe( $appData, $uri );
check( 'an account with the permission of the panel Belege gets the file: 200, application/pdf, shown (inline) under the number', $answer['statusCode'] === 200 && ( $answer['file'] ?? '' ) === \Nino\Filesystem::path( $appData, $target['pdf_path'] ) && $answer['header']['Content-Type'] === 'application/pdf'
	&& $answer['header']['Content-Disposition'] === 'inline; filename="'. $target['number']. '.pdf"' );
check( '...kept out of every cache, and not sniffed', $answer['header']['Cache-Control'] === 'private, no-store' && $answer['header']['X-Content-Type-Options'] === 'nosniff' );
$saved = docServe( $appData, $uri. '?download=1' );
check( '...with ?download=1 it is saved as an attachment under the number', $saved['statusCode'] === 200 && $saved['header']['Content-Disposition'] === 'attachment; filename="'. $target['number']. '.pdf"' && docServe( $appData, $uri. '?download=0' )['header']['Content-Disposition'] === $answer['header']['Content-Disposition'] );
$as( 'admin@example.com' );
check( 'the owner with every permission gets it as well', docServe( $appData, $uri )['statusCode'] === 200 );

$answers = [];

$draftOnly = Documents::add( $appData, 'invoice', $first['id'] )['id'];

foreach( [ 'a draft' => '/.crm/pdf/'. $draftOnly = Documents::add( $appData, 'invoice', $first['id'] )['id'], 'an id nobody has' => '/.crm/pdf/99999', 'a word' => '/.crm/pdf/abc', 'zero' => '/.crm/pdf/0', 'a leading zero' => '/.crm/pdf/01',
	'a path behind the id' => $uri. '/x', 'a traversal' => '/.crm/pdf/..%2f..%2fetc%2fpasswd', 'a sum' => '/.crm/pdf/1%2b1', 'a long number' => '/.crm/pdf/12345678901' ] as $kind => $path )
	$answers[$kind] = $nothing( docServe( $appData, $path ) );

check( 'a draft, an id nobody has and everything that is no id are the same 404', array_filter( $answers, static fn( bool $ok ): bool => $ok === false ) === [] );
check( '...and a draft is no file that is gone: it writes no log line', ninoWarnings() === [] );
Documents::remove( $appData, $draftOnly );

$file = \Nino\Filesystem::path( $appData, $target['pdf_path'] );
$bytes = (string) file_get_contents( $file );
file_put_contents( $file, $bytes. ' ' );
$ignored = ninoWarnings();
$conflict = docServe( $appData, $uri );
$warnings = ninoWarnings();
check( 'a PDF whose hash is not the stored one is 409: no file is answered, and one log line names the number and nothing else', $conflict['statusCode'] === 409 && ( $conflict['file'] ?? null ) === null && $conflict['body'] === 'Conflict' && $warnings === [ 'Crm: the PDF of document '. $target['number']. ' does not match its hash' ] );
file_put_contents( $file, $bytes );
check( '...the file as it was is answered again', docServe( $appData, $uri )['statusCode'] === 200 );

rename( $file, $file. '.away' );
$missing = docServe( $appData, $uri );
$warnings = ninoWarnings();
check( 'a PDF that is gone is 404 with one log line that names the number', $nothing( $missing ) === true && $warnings === [ 'Crm: the PDF of document '. $target['number']. ' is gone' ] );
rename( $file. '.away', $file );

foreach( [ '../../../etc/passwd', '/etc/passwd', '/data/crm/pdf/../../../x.pdf', '/data/crm/pdf/2026/x.txt', '/data/crm/pdf/2026/a/b.pdf' ] as $path ) {
	Db::exec( $appData, 'UPDATE documents SET pdf_path = :p WHERE id = :id', [ 'p' => $path, 'id' => $target['id'] ] );
	$bad = docServe( $appData, $uri );
	check( 'a stored path the module did not write is not followed: '. $path, $nothing( $bad ) === true );
}

// A real PDF with a hash that matches, outside the directory the module writes to, and a link inside it
$foreign = \Nino\Filesystem::path( $appData, '/data/foreign.pdf' );
file_put_contents( $foreign, '%PDF-foreign' );
$link = $pdfDir. '/'. substr( $target['date'], 0, 4 ). '/link.pdf';
symlink( $file, $link );
Db::exec( $appData, 'UPDATE documents SET pdf_path = :p, pdf_sha256 = :s WHERE id = :id', [ 'p' => '/data/foreign.pdf', 's' => hash( 'sha256', '%PDF-foreign' ), 'id' => $target['id'] ] );
check( 'a file outside the directory of the module is not answered, though its hash is the stored one', $nothing( docServe( $appData, $uri ) ) === true );
Db::exec( $appData, 'UPDATE documents SET pdf_path = :p WHERE id = :id', [ 'p' => Render::DIR. '/'. substr( $target['date'], 0, 4 ). '/link.pdf', 'id' => $target['id'] ] );
check( 'a link inside it is not followed either', $nothing( docServe( $appData, $uri ) ) === true );
unlink( $link );
unlink( $foreign );
Db::exec( $appData, 'UPDATE documents SET pdf_path = :p, pdf_sha256 = :s WHERE id = :id', [ 'p' => $target['pdf_path'], 's' => $target['pdf_sha256'], 'id' => $target['id'] ] );
$ignored = ninoWarnings();
check( 'the route and the callback are those the module registered, and the PDF directory is the module\'s: no file below it but the PDFs of finalised documents', array_diff( $files(), array_map( static fn( array $row ): string => substr( $row['pdf_path'], strlen( Render::DIR ) ), Db::rows( $appData, 'SELECT pdf_path FROM documents WHERE pdf_path != \'\'' ) ) ) === [] );
$as( 'admin@example.com' );

// --- The panel Belege -----------------------------------------------------------------------------------------------

echo "\nThe API of Belege: words and permission\n";

$words = [];
$sources = [];

foreach( [ 'documents' => [ \Crm\Documents\Admin::class, 'Documents', 'crm-documents' ] ] as $name => [ $class, $dir, $uri ] ) {

	$words[$name] = [ 'de_DE' => require $root. '/app/Crm/'. $dir. '/Admin/text/de_DE.php', 'en_US' => require $root. '/app/Crm/'. $dir. '/Admin/text/en_US.php' ];
	$sources[$name] = (string) file_get_contents( $root. '/app/Crm/'. $dir. '/Admin/Admin.php' );

	check( 'the words of '. $uri. ' are the same keys in German and English, none empty', array_keys( $words[$name]['de_DE'] ) === array_keys( $words[$name]['en_US'] ) && array_filter( $words[$name]['de_DE'] + $words[$name]['en_US'], static fn( string $text ): bool => trim( $text ) === '' ) === [] && count( $words[$name]['de_DE'] ) > 25 && array_filter( array_keys( $words[$name]['de_DE'] ), static fn( string $key ): bool => str_starts_with( $key, '[[/_admin/error/' ) === false ) === [] );
	check( '...the German ones speak Du', preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', $words[$name]['de_DE'] ) ) !== 1 );
	check( '...and a %s of the German is one of the English, in the same number', array_filter( array_keys( $words[$name]['de_DE'] ), static fn( string $key ): bool => substr_count( $words[$name]['de_DE'][$key], '%s' ) !== substr_count( $words[$name]['en_US'][$key] ?? '', '%s' ) ) === [] );

	preg_match_all( "~'(crm_documents_[a-z_]+|crm_project(?:_client)?)'~", $sources[$name], $codes );
	$codes = array_filter( array_unique( $codes[1] ), static fn( string $code ): bool => str_ends_with( $code, '_' ) === false );

	$codes = array_unique( array_merge( $codes, array_map( static fn( string $kind ): string => 'crm_documents_line_'. $kind, [ 'empty', 'long', 'format', 'amount', 'rate' ] ) ) );

	check( 'every code '. $uri. ' answers has its text in both languages', array_filter( $codes, static fn( string $code ): bool => isset( $words[$name]['de_DE']['[[/_admin/error/'. $code. ']]'], $words[$name]['en_US']['[[/_admin/error/'. $code. ']]'] ) === false ) === [] && count( $codes ) >= 25 );
	check( '...and every text of an error is a code the panel answers', array_filter( array_keys( $words[$name]['de_DE'] ), static fn( string $key ): bool => str_starts_with( $key, '[[/_admin/error/' ) === true && in_array( substr( $key, 16, -2 ), $codes, true ) === false ) === [] );
}

$mine = [ 'crm-documents/list', 'crm-documents/get', 'crm-documents/save', 'crm-documents/remove', 'crm-documents/project', 'crm-documents/finalise', 'crm-documents/state', 'crm-documents/from-offer', 'crm-documents/credit', 'crm-documents/release', 'crm-documents/unrelease', 'crm-documents/sign', 'crm-documents/settings-get', 'crm-documents/settings-save', 'crm-documents/templates', 'crm-documents/positionen', 'crm-documents/status', 'crm-documents/overview', 'crm-documents/figures', 'crm-documents/copy', 'crm-documents/remind', 'crm-documents/sign-remind', 'crm-documents/sign-cancel' ];
check( 'its actions are the fifteen of the plan, the six of the surface (overview, figures, copy, remind, and the signature again and taken back) and positionen, which shows the items', array_keys( \Crm\Documents\Admin::actions() ) === $mine );

$posts = [
	'crm-documents/list' => [], 'crm-documents/get' => [ 'id' => '1' ], 'crm-documents/save' => [ 'category' => 'invoice', 'client_id' => '1' ], 'crm-documents/remove' => [ 'id' => '1' ], 'crm-documents/project' => [ 'id' => '1', 'project' => '00001' ], 'crm-documents/finalise' => [ 'id' => '1' ],
	'crm-documents/state' => [ 'id' => '1', 'state' => 'sent' ], 'crm-documents/from-offer' => [ 'id' => '1' ], 'crm-documents/credit' => [ 'id' => '1' ], 'crm-documents/release' => [ 'id' => '1' ], 'crm-documents/unrelease' => [ 'id' => '1' ], 'crm-documents/sign' => [ 'id' => '1', 'account' => 'aaaaaaaaaaaa' ], 'crm-documents/settings-get' => [], 'crm-documents/settings-save' => [ 'payment_days' => '99' ],
	'crm-documents/templates' => [ 'category' => 'invoice' ], 'crm-documents/positionen' => [], 'crm-documents/status' => [],
	'crm-documents/overview' => [], 'crm-documents/figures' => [], 'crm-documents/copy' => [ 'id' => '1' ], 'crm-documents/remind' => [ 'id' => '1' ], 'crm-documents/sign-remind' => [ 'id' => '1' ], 'crm-documents/sign-cancel' => [ 'id' => '1' ],
];
$before = [ count( Documents::list( $appData ) ), count( Positionen::list( $appData, '', false ) ), Settings::all( $appData ), $sequences() ];
$denied = [ 'signed out' => [], 'without the permission' => [], 'with another panel\'s permission' => [], 'with the two permissions of before' => [] ];
$mailed = new \ArrayObject();
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( $mailed ): void {
	$mailed->append( $mail );
	$mail['sent'] = true;
} );

$as( '' );
foreach( $posts as $action => $data )
	$denied['signed out'][$action] = array_values( array_intersect_key( $post( $action, $data ), [ 0 => 1 ] ) )[0]. ( $post( $action, $data )[1]['code'] ?? '' );

$as( 'plain@example.com' );
foreach( $posts as $action => $data )
	$denied['without the permission'][$action] = $post( $action, $data )[0]. ( $post( $action, $data )[1]['code'] ?? '' );

$as( 'other@example.com' );
foreach( $posts as $action => $data )
	if( str_starts_with( $action, 'crm-documents/' ) === true )
		$denied['with another panel\'s permission'][$action] = $post( $action, $data )[0];

$as( 'old@example.com' );
foreach( $posts as $action => $data )
	if( str_starts_with( $action, 'crm-documents/' ) === true )
		$denied['with the two permissions of before'][$action] = $post( $action, $data )[0];

check( 'every action refuses a visitor with 401 and the code session', array_values( array_unique( $denied['signed out'] ) ) === [ '401session' ] && array_diff( $mine, array_keys( $denied['signed out'] ) ) === [] );
check( '...an account without the permission with 403', array_values( array_unique( $denied['without the permission'] ) ) === [ '403' ] );
check( '...so does one with the permission of another panel; and nothing was written by any of them', array_values( array_unique( $denied['with another panel\'s permission'] ) ) === [ 403 ]
	&& $before === [ count( Documents::list( $appData ) ), count( Positionen::list( $appData, '', false ) ), Settings::all( $appData ), $sequences() ] );
$fiveNew = [ 'crm-documents/overview', 'crm-documents/copy', 'crm-documents/remind', 'crm-documents/sign-remind', 'crm-documents/sign-cancel' ];
check( '...and the five actions of the surface - overview, copy, remind, sign-remind, sign-cancel - are 401 session for a visitor, 403 for an account without a permission, for one with the permission of another panel and for one with the two permissions the panels had before', array_diff( $fiveNew, array_keys( $posts ) ) === []
	&& array_intersect_key( $denied['signed out'], array_flip( $fiveNew ) ) === array_fill_keys( $fiveNew, '401session' ) && array_intersect_key( $denied['without the permission'], array_flip( $fiveNew ) ) === array_fill_keys( $fiveNew, '403' )
	&& array_intersect_key( $denied['with another panel\'s permission'], array_flip( $fiveNew ) ) === array_fill_keys( $fiveNew, 403 ) && array_intersect_key( $denied['with the two permissions of before'], array_flip( $fiveNew ) ) === array_fill_keys( $fiveNew, 403 ) );
check( '...every action of Belege is refused to the account with the two old permissions, nothing is written and no mail is sent by any refusal', array_values( array_unique( $denied['with the two permissions of before'] ) ) === [ 403 ] && array_keys( $denied['with the two permissions of before'] ) === $mine
	&& $before === [ count( Documents::list( $appData ) ), count( Positionen::list( $appData, '', false ) ), Settings::all( $appData ), $sequences() ] && count( $mailed ) === 0 );

$as( 'admin@example.com' );

echo "\nThe actions of Belege\n";

[ $status, $body ] = $post( 'crm-documents/list' );
check( 'list answers the documents, the years, the clients to filter by, the settings with the rates, and the active items', $status === 200 && count( $body['documents'] ) === count( Documents::list( $appData ) ) && $body['years'] === Documents::years( $appData ) && array_column( $body['clients'], 'number' ) === [ 'K-0001', 'K-0002', 'K-0003' ]
	&& $body['clients'][2]['active'] === false && $body['settings']['vat_mode'] === 'small_business' && $body['settings']['rates'] === [ 1900, 700, 0 ] && $body['settings']['currency'] === '€' && array_column( $body['items'], 'title' ) === $catalogue );
$stillLapsed = Documents::add( $appData, 'offer', $first['id'], [ 'date' => '2021-03-03', 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
Documents::finalise( $appData, $stillLapsed['id'] );
check( 'list filters by category, state, year and client', array_unique( array_column( $post( 'crm-documents/list', [ 'category' => 'offer' ] )[1]['documents'], 'category' ) ) === [ 'offer' ] && array_unique( array_column( $post( 'crm-documents/list', [ 'state' => 'draft' ] )[1]['documents'], 'state' ) ) === [ 'draft' ]
	&& count( $post( 'crm-documents/list', [ 'year' => '2020' ] )[1]['documents'] ) === 1 && $post( 'crm-documents/list', [ 'client' => (string) $second['id'] ] )[1]['documents'] === [] && count( $post( 'crm-documents/list', [ 'search' => 'A-2020' ] )[1]['documents'] ) === count( $post( 'crm-documents/list', [] )[1]['documents'] )
	&& array_column( $post( 'crm-documents/list', [ 'state' => 'expired' ] )[1]['documents'], 'id' ) !== [] );
check( 'a year or a client that is no number is 400 crm_documents_format; a search is not a parameter of the list any more, whatever it holds', ( $r = $post( 'crm-documents/list', [ 'year' => '20x' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_format' && $r[1]['field'] === 'year'
	&& ( $r = $post( 'crm-documents/list', [ 'client' => '1; DROP' ] ) )[0] === 400 && $post( 'crm-documents/list', [ 'search' => str_repeat( 'x', 101 ) ] )[0] === 200 );

[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $target['id'] ] );
check( 'get answers the document with its lines and its client, the templates of its tag with their inputs, the clients, the settings and the items', $status === 200 && $body['document']['number'] === $target['number'] && count( $body['document']['lines'] ) === 2 && $body['document']['client']['company'] === 'Studio Nord GmbH'
	&& isset( $body['document']['client_frozen'] ) === false && array_column( $body['templates'], 'key' ) === [ 'rechnung' ] && array_column( $body['templates'][0]['inputs'], 'name' ) === [ 'intro', 'footnote' ] && $body['templates'][0]['inputs'][0]['required'] === true && count( $body['clients'] ) === 3 && array_column( $body['items'], 'title' ) === $catalogue );
check( '...the address of its PDF: the route of the module below the directory of the project', $body['document']['pdf_url'] === '/.crm/pdf/'. $target['id'] );
$appData['/nino/dir'] = '/club';
check( '...below the directory the project is served from', $post( 'crm-documents/get', [ 'id' => (string) $target['id'] ] )[1]['document']['pdf_url'] === '/club/.crm/pdf/'. $target['id'] );
$appData['/nino/dir'] = '';
$newDraft = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'] ] )[1]['document'];
check( 'a draft\'s pdf_url is empty', $post( 'crm-documents/get', [ 'id' => (string) $newDraft['id'] ] )[1]['document']['pdf_url'] === '' );
check( 'get of a bad id is 400 crm_documents_id, of nobody 404 crm_documents_unknown', ( $r = $post( 'crm-documents/get', [ 'id' => '1; DROP' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_id' && ( $r = $post( 'crm-documents/get', [ 'id' => '99999' ] ) )[0] === 404 && $r[1]['code'] === 'crm_documents_unknown' );

[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'offer', 'client_id' => (string) $first['id'], 'date' => '2026-05-05', 'lines' => $lines, 'notes' => 'n' ] );
check( 'save without an id adds a draft of the category for the client', $status === 200 && $body['document']['category'] === 'offer' && $body['document']['state'] === 'draft' && $body['document']['valid_until'] === '2026-06-04' && $body['document']['net'] === 23700 && $body['document']['notes'] === 'n' );
$saved = $body['document'];
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $saved['id'], 'notes' => 'changed', 'lines' => [ [ 'title' => 'Eine', 'qty' => '1', 'price' => '5', 'vat_rate' => 0 ] ] ] );
check( 'save with an id changes the draft: what is given, the rest stays', $status === 200 && $body['document']['notes'] === 'changed' && count( $body['document']['lines'] ) === 1 && $body['document']['date'] === '2026-05-05' && $body['document']['net'] === 500 );

$refusals = [
	'no client'                       => [ [ 'category' => 'invoice' ], 400, 'crm_documents_client', 'client_id' ],
	'a client that is no number'      => [ [ 'category' => 'invoice', 'client_id' => 'x' ], 400, 'crm_documents_client', 'client_id' ],
	'a client nobody has'             => [ [ 'category' => 'invoice', 'client_id' => '9999' ], 400, 'crm_documents_client', 'client_id' ],
	'an inactive client'              => [ [ 'category' => 'invoice', 'client_id' => (string) $third['id'] ], 409, 'crm_documents_inactive', 'client_id' ],
	'a category nobody has'           => [ [ 'category' => 'letter', 'client_id' => (string) $first['id'] ], 400, 'crm_documents_category', 'category' ],
	'a date that is none'             => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'date' => '31.02.2026' ], 400, 'crm_documents_date', 'date' ],
	'a due date that is none'         => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'due_date' => 'bald' ], 400, 'crm_documents_date', 'due_date' ],
	'a period of one day'             => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'period_from' => '2026-01-01' ], 400, 'crm_documents_period', 'period_from' ],
	'a reverse charge without VAT'    => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'reverse_charge' => true ], 409, 'crm_documents_reverse', 'reverse_charge' ],
	'notes that are too long'         => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'notes' => str_repeat( 'x', 5001 ) ], 400, 'crm_documents_long', 'notes' ],
	'a template that is none'         => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'template_key' => 'angebot' ], 409, 'crm_documents_template', 'template_key' ],
	'inputs that are no map'          => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'inputs' => 'x' ], 400, 'crm_documents_format', 'inputs' ],
	'a title missing in a line'       => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ $line(), $line( [ 'title' => '' ] ) ] ], 400, 'crm_documents_line_empty', 'lines.1.title' ],
	'a title too long in a line'      => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ $line( [ 'title' => str_repeat( 'x', 201 ) ] ) ] ], 400, 'crm_documents_line_long', 'lines.0.title' ],
	'a quantity that is no number'    => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ $line( [ 'qty' => 'viel' ] ) ] ], 400, 'crm_documents_line_amount', 'lines.0.qty' ],
	'a price that is no number'       => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ $line( [ 'price' => '1,2,3' ] ) ] ], 400, 'crm_documents_line_amount', 'lines.0.price' ],
	'a rate that is none of the list' => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ $line( [ 'vat_rate' => 1900 ] ) ] ], 400, 'crm_documents_line_rate', 'lines.0.vat_rate' ],
	'a line that is no map'           => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => [ 'x' ] ], 400, 'crm_documents_line_format', 'lines.0' ],
	'too many lines'                  => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => array_fill( 0, 201, $line() ) ], 400, 'crm_documents_lines', 'lines' ],
	'a net total that is too large'   => [ [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => array_fill( 0, 11, $line( [ 'qty' => '1000', 'price' => '1000000' ] ) ) ], 400, 'crm_documents_total', 'lines' ],
];

$count = count( Documents::list( $appData ) );

foreach( $refusals as $why => [ $data, $code, $text, $field ] ) {
	[ $status, $body ] = $post( 'crm-documents/save', $data );
	check( 'save: '. $why. ' is '. $code. ' '. $text. ' on '. $field, $status === $code && ( $body['code'] ?? '' ) === $text && ( $body['field'] ?? '' ) === $field );
}

check( '...the place of a line is its row counted from 1, and the limits are params', $post( 'crm-documents/save', $refusals['a title missing in a line'][0] )[1]['params'] === [ 2 ] && $post( 'crm-documents/save', $refusals['too many lines'][0] )[1]['params'] === [ 200 ] && count( Documents::list( $appData ) ) === $count );
check( 'save with an id nobody has is 404; a bad id 400', $post( 'crm-documents/save', [ 'id' => '99999', 'notes' => 'x' ] )[0] === 404 && $post( 'crm-documents/save', [ 'id' => 'x', 'notes' => 'x' ] )[0] === 400 );
check( 'save of a finalised document with its notes alone changes them; with any other field it is 409 crm_documents_fixed and writes nothing', ( $r = $post( 'crm-documents/save', [ 'id' => (string) $target['id'], 'notes' => 'Notiz' ] ) )[0] === 200 && $r[1]['document']['notes'] === 'Notiz' && $r[1]['document']['number'] === $target['number']
	&& ( $r = $post( 'crm-documents/save', [ 'id' => (string) $target['id'], 'notes' => 'x', 'date' => '2026-01-01' ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_fixed' && ( $r = $post( 'crm-documents/save', [ 'id' => (string) $target['id'], 'lines' => [] ] ) )[0] === 409
	&& Documents::get( $appData, $target['id'] )['notes'] === 'Notiz' && Documents::get( $appData, $target['id'] )['date'] === $target['date'] && count( Documents::get( $appData, $target['id'] )['lines'] ) === 2 );
check( '...its notes are held to their length: 400 crm_documents_long', ( $r = $post( 'crm-documents/save', [ 'id' => (string) $target['id'], 'notes' => str_repeat( 'x', 5001 ) ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_long' );

[ $status, $body ] = $post( 'crm-documents/remove', [ 'id' => (string) $saved['id'] ] );
check( 'remove deletes a draft', $status === 200 && $body['removed'] === true && Documents::get( $appData, $saved['id'] ) === null );
check( '...a finalised document is 409 crm_documents_fixed, one nobody has 404', ( $r = $post( 'crm-documents/remove', [ 'id' => (string) $target['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_fixed' && $post( 'crm-documents/remove', [ 'id' => '99999' ] )[0] === 404 && Documents::get( $appData, $target['id'] ) !== null );
$stale = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => $lines ] )[1]['document'];
$post( 'crm-documents/remove', [ 'id' => (string) $stale['id'] ] );
$fresh = $post( 'crm-documents/save', [ 'category' => 'offer', 'client_id' => (string) $first['id'], 'notes' => 'new' ] )[1]['document'];
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $stale['id'], 'notes' => 'from a stale screen', 'lines' => $lines ] );
check( 'a save of the id of a draft that was deleted is 404 crm_documents_unknown - the next document does not take the id - and the next document is as it was', $fresh['id'] !== $stale['id'] && $status === 404 && $body['code'] === 'crm_documents_unknown' && Documents::get( $appData, $fresh['id'] )['notes'] === 'new' && Documents::get( $appData, $fresh['id'] )['lines'] === [] );
$post( 'crm-documents/remove', [ 'id' => (string) $fresh['id'] ] );

echo "\nFinalise, state, offer, credit\n";

$fin = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'lines' => $lines ] )[1]['document'];
$stops = [
	'no template'      => [ 409, 'crm_documents_template', 'template_key' ],
	'a required input' => [ 409, 'crm_documents_inputs', 'inputs.intro' ],
];
[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] );
check( 'finalise: no template is 409 crm_documents_template', $status === 409 && $body['code'] === 'crm_documents_template' && $body['field'] === 'template_key' );
$post( 'crm-documents/save', [ 'id' => (string) $fin['id'], 'template_key' => 'rechnung' ] );
[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] );
check( '...a required input missing is 409 crm_documents_inputs on inputs.<name>', $status === 409 && $body['code'] === 'crm_documents_inputs' && $body['field'] === 'inputs.intro' );
$empty = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] )[1]['document'];
check( '...no line is 409 crm_documents_empty', ( $r = $post( 'crm-documents/finalise', [ 'id' => (string) $empty['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_empty' );
$post( 'crm-documents/save', [ 'id' => (string) $fin['id'], 'inputs' => [ 'intro' => 'Hallo' ] ] );
$post( 'crm-documents/finalise', [ 'id' => '99999' ] );
check( '...a document nobody has is 404', $post( 'crm-documents/finalise', [ 'id' => '99999' ] )[0] === 404 );
\Nino\Features::deactivate( $appData, 'pdf' );
check( '...Pdf off is 409 crm_documents_pdf', ( $r = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_pdf' );
\Nino\Features::activate( $appData, 'pdf' );
\Nino\Html::init( $appData );
file_put_contents( $pdfDir. '/'. $year. '.blocked', 'x' );
$futureYear = (int) substr( (string) Documents::get( $appData, $fin['id'] )['date'], 0, 4 );
rename( $pdfDir. '/'. $futureYear, $pdfDir. '/'. $futureYear. '.away' );
file_put_contents( $pdfDir. '/'. $futureYear, 'a file where the directory should be' );
check( '...a PDF that cannot be written is 500 crm_documents_write', ( $r = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] ) )[0] === 500 && $r[1]['code'] === 'crm_documents_write' );
unlink( $pdfDir. '/'. $futureYear );
rename( $pdfDir. '/'. $futureYear. '.away', $pdfDir. '/'. $futureYear );
unlink( $pdfDir. '/'. $year. '.blocked' );
$ignored = ninoWarnings();
$nextNumber = Numbers::render( 'R-{YYYY}-{NNNN}', $futureYear, 1 + (int) Db::row( $appData, 'SELECT next - 1 AS n FROM sequences WHERE category = \'invoice\' AND year = :y', [ 'y' => $futureYear ] )['n'] );
[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] );
check( 'finalise answers the document with its number, its state, its PDF address and the hash; the number was not spent by the failure before', $status === 200 && $body['document']['number'] === $nextNumber && $body['document']['state'] === 'open' && $body['document']['pdf_url'] === '/.crm/pdf/'. $fin['id'] && strlen( $body['document']['pdf_sha256'] ) === 64 );
check( 'finalise of a finalised document is 409 crm_documents_fixed', ( $r = $post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_fixed' );

[ $status, $body ] = $post( 'crm-documents/state', [ 'id' => (string) $fin['id'], 'state' => 'sent', 'date' => $today ] );
check( 'state moves the document and takes a day', $status === 200 && $body['document']['state'] === 'sent' && $body['document']['sent_at'] === $today. 'T00:00:00Z' );
check( '...a step the state does not allow is 409 crm_documents_state; a day that is none 400 crm_documents_date; a document nobody has 404', ( $r = $post( 'crm-documents/state', [ 'id' => (string) $fin['id'], 'state' => 'sent' ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_state'
	&& ( $r = $post( 'crm-documents/state', [ 'id' => (string) $fin['id'], 'state' => 'paid', 'date' => 'x' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_date' && $r[1]['field'] === 'date' && $post( 'crm-documents/state', [ 'id' => '99999', 'state' => 'paid' ] )[0] === 404 && $state( $fin['id'] ) === 'sent' );
check( '...paid without a day is the time of the step', ( $r = $post( 'crm-documents/state', [ 'id' => (string) $fin['id'], 'state' => 'paid' ] ) )[0] === 200 && $r[1]['document']['state'] === 'paid' && str_ends_with( (string) $r[1]['document']['paid_at'], 'Z' ) );

[ $status, $body ] = $post( 'crm-documents/credit', [ 'id' => (string) $fin['id'] ] );
check( 'credit makes a credit note draft with the lines negated', $status === 200 && $body['document']['category'] === 'credit' && $body['document']['state'] === 'draft' && $body['document']['parent']['id'] === $fin['id'] && $body['document']['net'] === -23700 );
check( '...of a credit note or a draft it is 409 crm_documents_state', ( $r = $post( 'crm-documents/credit', [ 'id' => (string) $body['document']['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_state' && $post( 'crm-documents/credit', [ 'id' => (string) $empty['id'] ] )[0] === 409 && $post( 'crm-documents/credit', [ 'id' => '99999' ] )[0] === 404 );
[ $status, $body ] = $post( 'crm-documents/from-offer', [ 'id' => (string) $offerFinal['id'] ] );
check( 'from-offer makes an invoice draft with the lines of the offer', $status === 200 && $body['document']['category'] === 'invoice' && $body['document']['parent']['number'] === 'A-2026-0001' && count( $body['document']['lines'] ) === 2 );
check( '...of an invoice it is 409 crm_documents_state', ( $r = $post( 'crm-documents/from-offer', [ 'id' => (string) $fin['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_state' );
\Crm\Clients::setActive( $appData, $first['id'], false );
check( '...a client that is not active is 409 crm_documents_inactive', ( $r = $post( 'crm-documents/from-offer', [ 'id' => (string) $offerFinal['id'] ] ) )[0] === 409 && $r[1]['code'] === 'crm_documents_inactive' );
\Crm\Clients::setActive( $appData, $first['id'], true );

echo "\nSettings, templates, status\n";

[ $status, $body ] = $post( 'crm-documents/settings-get' );
check( 'settings-get answers the eight settings', $status === 200 && array_keys( $body['settings'] ) === array_keys( Settings::DEFAULTS ) && $body['settings']['vat_mode'] === 'small_business' );
check( '...and what the screen shows beside them: the next number of each sequence in the year now - read, never taken -, the templates of Pdf by tag, whether Pdf is on, the portal and the company\'s texts (the block Firmendaten of the Einstellungen reads them for an account without the Text panel)', array_keys( $body ) === [ 'settings', 'next', 'templates', 'pdf', 'portal', 'company' ]
	&& array_keys( $body['next'] ) === [ 'offer', 'invoice', 'credit' ] && preg_match( '~^G-'. date( 'Y' ). '-\d{4}$~D', $body['next']['credit'] ) === 1 && preg_match( '~^R-'. date( 'Y' ). '-\d{4}$~D', $body['next']['invoice'] ) === 1
	&& array_keys( $body['templates'] ) === [ 'offer', 'invoice', 'contract', 'privacy', 'information' ] && $body['templates']['invoice'] === [ 'Rechnung' ] && $body['templates']['offer'] === [ 'Angebot' ] && $body['templates']['contract'] === [ 'Vertrag' ] && $body['templates']['privacy'] === []
	&& $body['pdf'] === true && array_keys( $body['portal'] ) === [ 'address', 'pages', 'members', 'mail_cap' ] && array_keys( $body['company'] ) === [ 'keys', 'locales', 'native' ] && array_filter( $body['company']['keys'], static fn( array $one ): bool => str_starts_with( $one['key'], '/project/company/' ) === false ) === [] );
$rowsBefore = $sequences();
$counter = (int) ( Db::row( $appData, 'SELECT next FROM sequences WHERE category = \'invoice\' AND year = :year', [ 'year' => (int) date( 'Y' ) ] )['next'] ?? 1 );
$again = $post( 'crm-documents/settings-get' )[1]['next'];
check( '...the next number is the one the next finalising gets, and asking for it moves nothing: the sequences stand where they stood', $again['invoice'] === Numbers::render( Settings::get( $appData, 'number_invoice' ), (int) date( 'Y' ), $counter ) && $sequences() === $rowsBefore );
[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'payment_days' => '21', 'vat_mode' => 'regular', 'currency' => 'EUR', 'number_invoice' => 'RE-{YYYY}-{NNN}', 'vat_rates' => '1900, 700', 'colour' => 'red' ] );
check( 'settings-save writes the keys it is given and answers all of them; a key nobody has is ignored', $status === 200 && $body['settings']['payment_days'] === '21' && $body['settings']['vat_mode'] === 'regular' && $body['settings']['currency'] === 'EUR' && $body['settings']['number_invoice'] === 'RE-{YYYY}-{NNN}'
	&& $body['settings']['vat_rates'] === '1900,700' && array_key_exists( 'colour', $body['settings'] ) === false );
$kept = Settings::all( $appData );
check( 'a value that is none is 400 crm_documents_setting on its key, and nothing of the request is written', ( $r = $post( 'crm-documents/settings-save', [ 'payment_days' => '30', 'offer_days' => '9999' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_setting' && $r[1]['field'] === 'offer_days' && Settings::all( $appData ) === $kept );
check( 'a number format that is none is 400 crm_documents_numberformat on its key, and nothing of the request is written', ( $r = $post( 'crm-documents/settings-save', [ 'payment_days' => '30', 'number_credit' => 'G/{NNNN}' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_numberformat' && $r[1]['field'] === 'number_credit' && Settings::all( $appData ) === $kept );
check( 'rates in percent are written by settings-save as hundredths: 19; 7,5 is 1900,750', ( $r = $post( 'crm-documents/settings-save', [ 'vat_rates_percent' => '19; 7,5' ] ) )[0] === 200 && $r[1]['settings']['vat_rates'] === '1900,750' && Settings::get( $appData, 'vat_rates' ) === '1900,750' );
check( '...19,7,0 is refused as a whole with the code of a setting on the field of the percentages, nothing is written - and nothing of the same request', ( $r = $post( 'crm-documents/settings-save', [ 'payment_days' => '30', 'vat_rates_percent' => '19,7,0' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_setting' && $r[1]['field'] === 'vat_rates_percent' && Settings::get( $appData, 'vat_rates' ) === '1900,750' && Settings::get( $appData, 'payment_days' ) === $kept['payment_days'] );
[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'portal_message' => "  Zu bis Montag.\r\nDanke!  ", 'portal_message_at' => '2020-01-01T00:00:00Z' ] );
check( 'settings-save writes the message to every client with its line breaks, answers it with the time the module set - and a time that is posted is not written', $status === 200 && $body['settings']['portal_message'] === "Zu bis Montag.\nDanke!" && $body['settings']['portal_message_at'] !== '' && $body['settings']['portal_message_at'] !== '2020-01-01T00:00:00Z'
	&& Settings::get( $appData, 'portal_message' ) === "Zu bis Montag.\nDanke!" );
$wrongMessages = [];

foreach( [ 'too long' => str_repeat( 'x', 2001 ), 'a control character' => "a\x07b", 'a null byte' => "a\0b", 'an array' => [ 'x' ] ] as $label => $value ) {
	[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'portal_message' => $value ] );
	$wrongMessages[$label] = $status === 400 && $body['code'] === 'crm_documents_portal_message' && $body['field'] === 'portal_message' && Settings::get( $appData, 'portal_message' ) === "Zu bis Montag.\nDanke!";
}

check( '...a message that is none is 400 crm_documents_portal_message at the field, with a sentence in both languages, and the message stays: too long, a control character, a null byte, an array', array_keys( array_filter( $wrongMessages, static fn( bool $same ): bool => $same === false ) ) === []
	&& array_filter( [ 'de_DE', 'en_US' ], static fn( string $locale ): bool => trim( (string) ( require $root. '/app/Crm/Documents/Admin/text/'. $locale. '.php' )['[[/_admin/error/crm_documents_portal_message]]'] ) === '' ) === [] );
[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'portal_message' => '' ] );
check( '...an empty text removes the message and its time', $status === 200 && $body['settings']['portal_message'] === '' && $body['settings']['portal_message_at'] === '' );
check( 'the particulars are saved with the others, and a wrong one is refused as a whole on its field with a sentence of its own - nothing of the same request is written', ( $r = $post( 'crm-documents/settings-save', [ 'tax_id' => 'DE123456789', 'bank_name' => 'GLS Bank', 'iban' => 'DE89370400440532013000', 'bic' => 'GENODEM1GLS' ] ) )[0] === 200
	&& $r[1]['settings']['iban'] === 'DE89 3704 0044 0532 0130 00' && $r[1]['settings']['tax_id'] === 'DE123456789' && ( $bad = $post( 'crm-documents/settings-save', [ 'payment_days' => '99', 'bank_name' => 'Nope', 'iban' => 'DE00 0000' ] ) )[0] === 400 && $bad[1]['code'] === 'crm_documents_particular_iban'
	&& $bad[1]['field'] === 'iban' && Settings::get( $appData, 'bank_name' ) === 'GLS Bank' && Settings::get( $appData, 'payment_days' ) === $kept['payment_days'] && ( $bic = $post( 'crm-documents/settings-save', [ 'bic' => 'XX' ] ) )[1]['code'] === 'crm_documents_particular_bic' );
$post( 'crm-documents/settings-save', [ 'tax_id' => '', 'bank_name' => '', 'iban' => '', 'bic' => '' ] );
check( '...the sentence of that code names the rule, in both languages', str_contains( $words['documents']['de_DE']['[[/_admin/error/crm_documents_setting]]'], 'Semikolon' ) === true && str_contains( $words['documents']['en_US']['[[/_admin/error/crm_documents_setting]]'], 'semicolon' ) === true );
$post( 'crm-documents/settings-save', [ 'vat_rates_percent' => '19; 7; 0' ] );
$post( 'crm-documents/settings-save', [ 'payment_days' => '14', 'vat_mode' => 'small_business', 'currency' => '€', 'number_invoice' => 'R-{YYYY}-{NNNN}', 'vat_rates' => '1900,700,0' ] );
// The setup of Kunden above entered the Datenschutzerklärung
Db::exec( $appData, 'DELETE FROM settings WHERE key IN ( \'privacy_text\', \'privacy_changed\' )' );
check( '...and the settings are the defaults again', Settings::all( $appData ) === Settings::DEFAULTS );

[ $status, $body ] = $post( 'crm-documents/templates', [ 'category' => 'credit' ] );
check( 'templates answers the templates of the tag of a category with their inputs', $status === 200 && array_column( $body['templates'], 'key' ) === [ 'rechnung' ] && $body['templates'][0]['name'] === 'Rechnung' && array_column( $body['templates'][0]['inputs'], 'multiline', 'name' ) === [ 'intro' => true, 'footnote' => false ]
	&& array_column( $post( 'crm-documents/templates', [ 'category' => 'offer' ] )[1]['templates'], 'key' ) === [ 'angebot' ] );
check( '...a category nobody has is 400 crm_documents_category', ( $r = $post( 'crm-documents/templates', [ 'category' => 'letter' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_category' );

$stateOf = static function( array $body, string $key ): string {
	foreach( $body['items'] as $item )
		if( $item['key'] === $key )
			return $item['state'];
	return '';
};
[ $status, $body ] = $post( 'crm-documents/status' );
check( 'status names the module, the data directory, Pdf, the templates of both tags, the directory of the PDFs and the element type of the items', $status === 200 && array_column( $body['items'], 'key' ) === [ 'module', 'data', 'pdf', 'templates-offer', 'templates-invoice', 'pdf-dir', 'positionen', 'projekte' ] );
check( '...all of it is ok here', array_unique( array_column( $body['items'], 'state' ) ) === [ 'ok' ] );
\Nino\Features::deactivate( $appData, 'pdf' );
check( 'with Pdf off no template is offered, by templates and by get', $post( 'crm-documents/templates', [ 'category' => 'invoice' ] )[1]['templates'] === [] && $post( 'crm-documents/get', [ 'id' => (string) $target['id'] ] )[1]['templates'] === [] );
$body = $post( 'crm-documents/status' )[1];
check( 'Pdf off is told alone: the line names Pdf and no template, the other lines of the module stay', $stateOf( $body, 'pdf' ) === 'off' && array_column( $body['items'], 'key' ) === [ 'module', 'data', 'pdf', 'pdf-dir', 'positionen', 'projekte' ] );
\Nino\Features::activate( $appData, 'pdf' );
\Nino\Html::init( $appData );
$post( 'pdf/templates/delete', [ 'key' => 'angebot' ] );
check( 'no template of the tag offer is told alone', $stateOf( $post( 'crm-documents/status' )[1], 'templates-offer' ) === 'none' && $stateOf( $post( 'crm-documents/status' )[1], 'templates-invoice' ) === 'ok' );
$post( 'pdf/templates/save', [ 'key' => 'angebot', 'isNew' => true, 'name' => 'Angebot', 'tag' => 'offer', 'blocks' => $blocks( 'Ende des Angebots' ) ] );
$modulesNow = $appData['/nino/modules'];
$appData['/nino/modules'] = array_values( array_filter( $modulesNow, static fn( string $class ): bool => ltrim( $class, '\\' ) !== \Crm\Crm::class ) );
check( 'status says when the module is not listed in /nino/modules', $stateOf( ninoCall( $appData, [ \Crm\Documents\Admin::class, 'apiStatus' ] )[1], 'module' ) === 'off' );
$appData['/nino/modules'] = $modulesNow;
rename( $pdfDir, $pdfDir. '.away' );
file_put_contents( $pdfDir, 'a file' );
check( 'a PDF directory that is a file is told - and the status makes no directory', $stateOf( $post( 'crm-documents/status' )[1], 'pdf-dir' ) === 'unwritable' && is_file( $pdfDir ) === true );
unlink( $pdfDir );
rename( $pdfDir. '.away', $pdfDir );
preg_match( '~function apiStatus\(.*?\n\t}\n~s', (string) file_get_contents( $root. '/app/Crm/Documents/Admin/Admin.php' ), $statusSource );
check( 'status writes nothing: no directory made, no file put', $statusSource !== [] && preg_match( '~forceDir|mkdir|putFileContent|mutate\(|saveSettings~', $statusSource[0] ) !== 1 );

echo "\nThe log\n";

check( 'the log line of an action names the number or the id and no address and no amount', \Crm\Documents\Admin::log( 'crm-documents/list', [] ) === '' && \Crm\Documents\Admin::log( 'crm-documents/get', [ 'id' => '1' ] ) === '' );

$logs = [];

foreach( [ 'crm-documents/save', 'crm-documents/remove', 'crm-documents/finalise', 'crm-documents/state', 'crm-documents/from-offer', 'crm-documents/credit', 'crm-documents/release', 'crm-documents/unrelease', 'crm-documents/sign', 'crm-documents/settings-save' ] as $action )
	$logs[$action] = \Crm\Documents\Admin::log( $action, [ 'id' => '1', 'email' => 'a@example.org', 'street' => 'Hafenstr.', 'price' => '99,99', 'lines' => [ [ 'price' => '12,00' ] ] ] );

check( '...every writing action has a line, which holds no address, no amount and no name', array_filter( $logs, static fn( string $line ): bool => $line === '' ) === [] && array_filter( $logs, static fn( string $line ): bool => preg_match( '/[@€]|Hafen|\d,\d\d|Nord/', $line ) === 1 ) === [] );

$called = [];
$post( 'crm-documents/save', [ 'category' => 'offer', 'client_id' => (string) $first['id'] ] );
$called['save'] = \Crm\Documents\Admin::log( 'crm-documents/save', [ 'category' => 'offer' ] );
$post( 'crm-documents/state', [ 'id' => (string) $secondInvoice['id'], 'state' => 'paid' ] );
$post( 'crm-documents/finalise', [ 'id' => (string) $fin['id'] ] );
check( 'a line of a finalised document names its number: "Finalised document R-..."', preg_match( '/^Added draft \d+$/D', $called['save'] ) === 1 && \Crm\Documents\Admin::log( 'crm-documents/save', [ 'id' => '3' ] ) !== '' );

echo "\nEvery refusal of the model has its answer\n";

$refuse = new \ReflectionMethod( \Crm\Documents\Admin::class, '_refused' );
$answers = [];

foreach( [ 'unknown', 'fixed', 'state', 'category', 'client', 'inactive', 'unready', 'period', 'reverse', 'lines', 'total', 'pdf', 'template', 'empty', 'number', 'exists:/data/crm/pdf/2026/R-2026-0001.pdf', 'render', 'write',
	'date:due_date', 'long:notes', 'format:inputs', 'format:number_offer', 'inputs:intro', 'line:2:amount:qty', 'line:0:empty:title', 'line:0:format:', 'value:currency', 'surprise',
	'offer', 'empty:title', 'long:title', 'format:title', 'address', 'limit', 'pending', 'days', 'field', 'account', 'sign', 'sign:template', 'sign:input', 'sign:render', 'sign:write', 'sign:surprise' ] as $why ) {
	$request = ninoVisit( $appData, '/refused' );
	$refuse->invokeArgs( null, [ &$request, $why ] );
	$answers[$why] = $request['/nino/http/response']['statusCode']. ' '. ( $request['/nino/http/response']['body']['code'] ?? '' ). ' '. ( $request['/nino/http/response']['body']['field'] ?? '' ). ' '. implode( ',', $request['/nino/http/response']['body']['params'] ?? [] );
}

check( 'the kinds of refusal are answered with their status, code, field and params: not found, conflicts, bad requests and the two that are the server\'s', $answers === [
	'unknown' => '404 crm_documents_unknown id ', 'fixed' => '409 crm_documents_fixed  ', 'state' => '409 crm_documents_state  ', 'category' => '400 crm_documents_category category ', 'client' => '400 crm_documents_client client_id ',
	'inactive' => '409 crm_documents_inactive client_id ', 'unready' => '409 crm_documents_unready client_id ', 'period' => '400 crm_documents_period period_from ', 'reverse' => '409 crm_documents_reverse reverse_charge ', 'lines' => '400 crm_documents_lines lines 200', 'total' => '400 crm_documents_total lines ',
	'pdf' => '409 crm_documents_pdf  ', 'template' => '409 crm_documents_template template_key ', 'empty' => '409 crm_documents_empty lines ', 'number' => '409 crm_documents_number  ', 'exists:/data/crm/pdf/2026/R-2026-0001.pdf' => '409 crm_documents_exists  R-2026-0001,data/crm/pdf/2026/R-2026-0001.pdf',
	'render' => '500 crm_documents_render  ', 'write' => '500 crm_documents_write  ', 'date:due_date' => '400 crm_documents_date due_date ', 'long:notes' => '400 crm_documents_long notes 5000', 'format:inputs' => '400 crm_documents_format inputs ',
	'format:number_offer' => '400 crm_documents_numberformat number_offer ', 'inputs:intro' => '409 crm_documents_inputs inputs.intro ', 'line:2:amount:qty' => '400 crm_documents_line_amount lines.2.qty 3', 'line:0:empty:title' => '400 crm_documents_line_empty lines.0.title 1',
	'line:0:format:' => '400 crm_documents_line_format lines.0 1', 'value:currency' => '400 crm_documents_setting currency ', 'surprise' => '500   ',
	'offer' => '400 crm_documents_offer parent_id ', 'empty:title' => '400 crm_documents_title title ', 'long:title' => '400 crm_documents_long title 120', 'format:title' => '400 crm_documents_format title ', 'address' => '409 crm_documents_address  ', 'limit' => '429 crm_documents_limit  ',
	'pending' => '409 crm_documents_pending  ', 'days' => '400 crm_documents_days days ', 'field' => '409 crm_documents_field template_key ', 'account' => '400 crm_documents_account account ', 'sign' => '409 crm_documents_sign  ',
	'sign:template' => '409 crm_documents_sign_template  ', 'sign:input' => '409 crm_documents_sign_input  ', 'sign:render' => '500 crm_documents_sign_render  ', 'sign:write' => '500 crm_documents_sign_write  ', 'sign:surprise' => '500   ' ] );

echo "\nThe database refuses\n";

Db::exec( $appData, 'CREATE TRIGGER no_documents BEFORE INSERT ON documents BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
check( 'a failure of the database is 500 with the code of the panel', ( $r = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'] ] ) )[0] === 500 && $r[1]['code'] === 'crm_documents_database' );
Db::exec( $appData, 'DROP TRIGGER no_documents' );
Db::exec( $appData, 'ALTER TABLE settings RENAME TO settings_away' );
$readAnswers = [ $post( 'crm-documents/positionen' ), $post( 'crm-documents/settings-get' ) ];
Db::exec( $appData, 'ALTER TABLE settings_away RENAME TO settings' );
check( 'a database that cannot be read is 500 with the code of the panel for the items as for the settings, and not an empty answer', array_map( static fn( array $answer ): string => $answer[0]. ' '. ( $answer[1]['code'] ?? '' ), $readAnswers ) === [ '500 crm_documents_database', '500 crm_documents_database' ] && $post( 'crm-documents/positionen' )[0] === 200 );
check( 'an id with a leading zero, or none, is 400 with the code of the panel on the field id', ( $r = $post( 'crm-documents/get', [ 'id' => '01' ] ) )[0] === 400 && $r[1]['code'] === 'crm_documents_id' && $r[1]['field'] === 'id' && $post( 'crm-documents/get', [ 'id' => '' ] )[0] === 400 );

echo "\nThe schema\n";

check( 'the indexes of the plan are there: category and state, client, date, and the lines by document', array_column( Db::rows( $appData, 'SELECT name FROM sqlite_master WHERE type = \'index\' AND name NOT LIKE \'sqlite_%\' AND tbl_name IN ( \'documents\', \'document_lines\' ) ORDER BY name' ), 'name' ) === [ 'document_lines_document', 'documents_category_state', 'documents_client', 'documents_client_released', 'documents_date', 'documents_project' ]
	&& array_column( Db::rows( $appData, 'PRAGMA index_info( documents_category_state )' ), 'name' ) === [ 'category', 'state' ] && array_column( Db::rows( $appData, 'PRAGMA index_info( documents_date )' ), 'name' ) === [ 'date' ] && array_column( Db::rows( $appData, 'PRAGMA index_info( documents_client )' ), 'name' ) === [ 'client_id' ] );
$restricted = '';

$lonely = (array) \Crm\Clients::add( $appData, [ 'name' => 'Lonely' ] );

try {
	Documents::add( $appData, 'invoice', $lonely['id'] );
	Db::exec( $appData, 'DELETE FROM clients WHERE id = :id', [ 'id' => $lonely['id'] ] );
}
catch( \PDOException $e ) {
	$restricted = 'restricted';
}

check( 'a client with a document cannot be deleted from under it', $restricted === 'restricted' && \Crm\Clients::get( $appData, (int) $lonely['id'] ) !== null );
$twice = '';

try {
	Db::exec( $appData, 'UPDATE documents SET number = :n WHERE id = :id', [ 'n' => $finalised['number'], 'id' => $secondInvoice['id'] ] );
}
catch( \PDOException $e ) {
	$twice = 'unique';
}

check( 'a number is unique', $twice === 'unique' );

// A project that ran only the first migration keeps what it set: the second writes settings where they are missing
$old = ninoSandbox( 'crm-documents-old' );
\Nino\Filesystem::forceDir( $old, '/data' );
$file = new \PDO( 'sqlite:'. \Nino\Filesystem::path( $old, \Crm\Db::FILE ) );

foreach( (array) include \Crm\Db::migrations()[1] as $statement )
	$file->exec( (string) $statement );

$file->exec( 'PRAGMA user_version = 1' );
$file->exec( 'UPDATE settings SET value = \'regular\' WHERE key = \'vat_mode\'' );
$file = null;
check( 'migration 0002 on a database of the first schema leaves the setting that is there, and adds the ones that are not', Settings::get( $old, 'vat_mode' ) === 'regular' && Settings::get( $old, 'payment_days' ) === '14' && Db::migrated() === 14 );
Db::close();
\Nino\Filesystem::removeDir( ninoSandboxDir( $old ) );

// --- The items in the editor ---------------------------------------------------------------------------------------------

echo "\nThe items in the editor\n";

[ $status, $body ] = $post( 'crm-documents/list' );
$editor = $body['items'];
check( 'list gives the editor the active items in the shape it reads: price_net in cents, vat_rate 0 in small_business', $status === 200 && array_column( $editor, 'title' ) === $catalogue && array_unique( array_map( static fn( array $item ): string => implode( ',', array_keys( $item ) ), $editor ) ) === [ 'id,title,description,unit,unit_code,price_net,vat_rate,active,sort' ]
	&& array_column( $editor, 'price_net' ) === [ 9000, 9050, 8550, 25000, 1, -1 ] && array_column( $editor, 'vat_rate' ) === [ 0, 0, 0, 0, 0, 0 ] && $editor[2]['description'] === 'Pro Stunde' && $editor[2]['unit'] === 'Std.' );
check( '...the templates of a new draft come with the items as they are now, so that an item entered meanwhile is offered', $post( 'crm-documents/templates', [ 'category' => 'invoice' ] )[1]['items'] === $editor );
check( '...get gives it the same', $post( 'crm-documents/get', [ 'id' => (string) $target['id'] ] )[1]['items'] === $editor );

Settings::set( $appData, 'vat_mode', 'regular' );
$editorRegular = $post( 'crm-documents/list' )[1]['items'];
check( '...in regular the rate of the item, in hundredths', array_column( $editorRegular, 'vat_rate' ) === [ 0, 1900, 0, 700, 750, 0 ] && array_column( $editorRegular, 'price_net' ) === array_column( $editor, 'price_net' ) );

// The editor copies the item into the line when it is chosen, and posts the line
$lineOf = static fn( array $item ): array => [ 'title' => $item['title'], 'description' => $item['description'], 'unit' => $item['unit'], 'qty' => '2', 'price' => $item['price_net'], 'vat_rate' => $item['vat_rate'] ];
$copied = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => [ $lineOf( $editorRegular[1] ), $lineOf( $editorRegular[2] ) ] ] );
check( 'a line made from an item carries its native words, its price in cents and its rate', is_array( $copied ) && count( $copied['lines'] ) === 2 && $copied['lines'][0]['title'] === 'Beratung' && $copied['lines'][0]['description'] === 'Advice for one day' && $copied['lines'][0]['unit'] === 'Tag'
	&& $copied['lines'][0]['price_net'] === 9050 && $copied['lines'][0]['vat_rate'] === 1900 && $copied['lines'][1]['title'] === 'Webdesign' && $copied['lines'][1]['unit'] === 'Std.' && $copied['lines'][1]['price_net'] === 8550 && $copied['net'] === 2 * ( 9050 + 8550 ) );

\Nino\Elements::updateElement( $appData, '/positionen/webdesign', [ 'price' => 120, 'title' => 'Webdesign neu' ], 'de_DE' );
\Nino\Elements::updateElement( $appData, '/positionen/beratung', [ 'description' => 'Neu', 'price' => 100 ], 'de_DE' );
$after = Documents::get( $appData, $copied['id'] );
check( '...and never follows a change of the element afterwards: the line has what it copied', $after['lines'] === $copied['lines'] && $after['net'] === $copied['net'] && Positionen::get( $appData, 'webdesign' )['price'] === 12000 && Positionen::get( $appData, 'webdesign' )['title'] === 'Webdesign neu' && Positionen::get( $appData, 'beratung' )['price'] === 10000 && Positionen::get( $appData, 'beratung' )['description'] === 'Neu' );
\Nino\Elements::updateElement( $appData, '/positionen/webdesign', [ 'price' => 85.5, 'title' => 'Webdesign' ], 'de_DE' );
\Nino\Elements::updateElement( $appData, '/positionen/beratung', [ 'description' => '', 'price' => 90.5 ], 'de_DE' );

check( 'a rate that is not in the list of the project is refused at the creation of the line as it was: the item Cent has 7.5 %', Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => [ $lineOf( $editorRegular[4] ) ] ] ) === 'line:0:rate:vat_rate'
	&& is_array( Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => [ $lineOf( $editorRegular[3] ) ] ] ) ) );
Settings::set( $appData, 'vat_mode', 'small_business' );
check( 'a title that is longer than a line takes is refused by the line, whatever the element says', Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => [ [ 'title' => str_repeat( 'x', 201 ), 'qty' => '1', 'price' => 100, 'vat_rate' => 0 ] ] ] ) === 'line:0:long:title' );

foreach( Db::rows( $appData, 'SELECT id FROM documents WHERE state = \'draft\' AND id >= :id', [ 'id' => $copied['id'] ] ) as $row )
	Documents::remove( $appData, (int) $row['id'] );

// --- Migration 0005 on a database that has items ---------------------------------------------------------------------------

echo "\nMigration 0005\n";

$dropped = ninoSandbox( 'crm-documents-dropped' );
\Nino\Filesystem::forceDir( $dropped, '/data' );
$file = new \PDO( 'sqlite:'. \Nino\Filesystem::path( $dropped, \Crm\Db::FILE ) );

foreach( [ 1, 2, 3, 4 ] as $number )
	foreach( (array) include \Crm\Db::migrations()[$number] as $statement )
		$file->exec( (string) $statement );

$file->exec( 'PRAGMA user_version = 4' );
$file->exec( 'INSERT INTO items ( title, price_net, created, updated ) VALUES ( \'Alt\', 100, \'x\', \'x\' ), ( \'Auch alt\', 200, \'x\', \'x\' )' );
$file->exec( 'INSERT INTO clients ( id, number, company, created, updated ) VALUES ( 1, \'K-0001\', \'Alt GmbH\', \'x\', \'x\' )' );
$file->exec( 'INSERT INTO documents ( category, client_id, number, state, date, vat_mode, template_key, created, updated ) VALUES ( \'invoice\', 1, \'R-2025-0001\', \'paid\', \'2025-05-05\', \'small_business\', \'rechnung\', \'x\', \'x\' )' );
$file->exec( 'INSERT INTO document_lines ( document_id, sort, title, qty, price_net, vat_rate, amount_net ) VALUES ( 1, 0, \'Alt\', 1000, 100, 0, 100 )' );
check( 'the database before has the table of the items with its rows', (int) $file->query( 'SELECT COUNT(*) FROM items' )->fetchColumn() === 2 );
$file = null;
$kept = Documents::get( $dropped, 1 );
check( 'migrations 0005 to 0015 on a database of the fourth schema run once: version 15, the table of the items is gone', (int) Db::pdo( $dropped )->query( 'PRAGMA user_version' )->fetchColumn() === 15 && Db::migrated() === 11
	&& Db::rows( $dropped, 'SELECT name FROM sqlite_master WHERE name = \'items\'' ) === [] && Db::rows( $dropped, 'SELECT name FROM sqlite_sequence WHERE name = \'items\'' ) === [] );
check( '...the document and its line are as they were: the line has the words it copied', is_array( $kept ) && $kept['number'] === 'R-2025-0001' && count( $kept['lines'] ) === 1 && $kept['lines'][0]['title'] === 'Alt' && $kept['lines'][0]['price_net'] === 100 );
check( '...it is one statement and nothing else, and document_lines is not in it', ( include $root. '/app/Crm/Db/migrations/0005-positionen.php' ) === [ 'DROP TABLE IF EXISTS items' ] && str_contains( implode( ' ', (array) include $root. '/app/Crm/Db/migrations/0005-positionen.php' ), 'document_lines' ) === false );
Db::close();
Db::pdo( $dropped );
check( '...and the next boot runs nothing', Db::migrated() === 0 && Documents::get( $dropped, 1 ) === $kept );
Db::close();
\Nino\Filesystem::removeDir( ninoSandboxDir( $dropped ) );

// --- Migration 0003 on a database that has documents -----------------------------------------------------------------

echo "\nMigration 0003\n";

$upgraded = ninoSandbox( 'crm-documents-upgraded' );
\Nino\Filesystem::forceDir( $upgraded, '/data' );
$file = new \PDO( 'sqlite:'. \Nino\Filesystem::path( $upgraded, \Crm\Db::FILE ) );

foreach( [ 1, 2 ] as $number )
	foreach( (array) include \Crm\Db::migrations()[$number] as $statement )
		$file->exec( (string) $statement );

$file->exec( 'PRAGMA user_version = 2' );
$file->exec( 'INSERT INTO clients ( id, number, company, created, updated ) VALUES ( 1, \'K-0001\', \'Alt GmbH\', \'x\', \'x\' )' );
$file->exec( 'INSERT INTO documents ( category, client_id, number, state, date, vat_mode, template_key, created, updated ) VALUES ( \'invoice\', 1, \'R-2025-0001\', \'paid\', \'2025-05-05\', \'small_business\', \'rechnung\', \'x\', \'x\' )' );
$file = null;
$old = Documents::get( $upgraded, 1 );
check( 'a database of the second schema with a document is migrated once: version 15, the thirteen migrations that were missing', (int) Db::pdo( $upgraded )->query( 'PRAGMA user_version' )->fetchColumn() === 15 && Db::migrated() === 13 );
check( '...the document is as it was, with the new columns at their defaults: no title, not released, no request, no signing day', is_array( $old ) === false ? false : $old['number'] === 'R-2025-0001' && $old['state'] === 'paid' && $old['title'] === '' && $old['released'] === false && $old['released_at'] === null
	&& $old['sign_id'] === '' && $old['sign_state'] === '' && $old['signed_at'] === null );
Db::close();
Db::pdo( $upgraded );
check( '...and the next boot runs nothing and leaves it so', Db::migrated() === 0 && Documents::get( $upgraded, 1 ) === $old );
check( 'migration 0003 adds the six columns and the index of the client and the release, and nothing else', preg_match_all( '/ALTER TABLE documents ADD COLUMN (\w+)/', (string) file_get_contents( $root. '/app/Crm/Db/migrations/0003-release.php' ), $columns ) === 6 && $columns[1] === [ 'title', 'released', 'released_at', 'sign_id', 'sign_state', 'signed_at' ]
	&& substr_count( (string) file_get_contents( $root. '/app/Crm/Db/migrations/0003-release.php' ), 'CREATE ' ) === 1 && array_column( Db::rows( $upgraded, 'PRAGMA index_info( documents_client_released )' ), 'name' ) === [ 'client_id', 'released' ] );
Db::close();
\Nino\Filesystem::removeDir( ninoSandboxDir( $upgraded ) );
Db::close();

// --- Contract, privacy and information: no lines, no number ------------------------------------------------------------

echo "\nThree documents without lines\n";

$post( 'pdf/templates/save', [ 'key' => 'dienstleistung', 'isNew' => true, 'name' => 'Dienstleistungsvertrag', 'tag' => 'contract', 'blocks' => [
	[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Vom [[/document/date]] zu [[/document/parent]] ([[/offer/number]] vom [[/offer/date]], gueltig bis [[/offer/valid]]): netto [[/offer/net]], USt [[/offer/vat]], brutto [[/offer/gross]]. Portal [[/site/portal]] Login [[/site/login]]</p>' ],
	[ 'type' => 'slot', 'name' => 'address' ],
	[ 'type' => 'slot', 'name' => 'lines' ],
	[ 'type' => 'slot', 'name' => 'totals' ],
	[ 'type' => 'input', 'name' => 'zusatz', 'label' => 'Zusatz', 'multiline' => true, 'required' => true ],
] ] );
$post( 'pdf/templates/save', [ 'key' => 'datenschutz', 'isNew' => true, 'name' => 'Datenschutzerklaerung', 'tag' => 'privacy', 'blocks' => [
	[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Kunde [[/customer/company]], Stand [[/document/date]], Offer [[/offer/number]]/[[/document/number]]</p>' ],
	[ 'type' => 'slot', 'name' => 'address' ],
	[ 'type' => 'slot', 'name' => 'lines' ],
] ] );
$post( 'pdf/templates/save', [ 'key' => 'zugang', 'isNew' => true, 'name' => 'Zugangsdaten', 'tag' => 'information', 'blocks' => [
	[ 'type' => 'html', 'html' => '<h1>[[/document/title]]</h1><p>Hallo [[/customer/name]]: [[/site/portal]] und [[/site/login]]</p>' ],
] ] );

check( 'the tag of a contract, a privacy and an information document is the name of the category', array_map( [ Render::class, 'tag' ], Documents::PLAIN ) === [ 'contract', 'privacy', 'information' ] );
$constants = ( new \ReflectionClass( Documents::class ) )->getConstants();
check( 'the categories are the six, three of them without lines, two that can be signed', $constants['CATEGORIES'] === [ 'offer', 'invoice', 'credit', 'contract', 'privacy', 'information' ] && $constants['PLAIN'] === [ 'contract', 'privacy', 'information' ] && $constants['SIGNABLE'] === [ 'contract', 'privacy' ] );

$offerNow = (array) Documents::get( $appData, $offerFinal['id'] );
$contract = Documents::add( $appData, 'contract', 0, [ 'parent_id' => $offerFinal['id'], 'title' => '  Dienstleistungsvertrag ', 'template_key' => 'dienstleistung', 'inputs' => [ 'zusatz' => 'Laufzeit ein Jahr' ] ] );
check( 'a contract hangs on an offer and its client is the offer\'s: a draft with the title trimmed, no number, no line, the date today, no due date, validity or period', is_array( $contract ) && $contract['category'] === 'contract' && $contract['state'] === 'draft' && $contract['parent_id'] === $offerFinal['id']
	&& $contract['client_id'] === $offerFinal['client_id'] && $contract['title'] === 'Dienstleistungsvertrag' && $contract['number'] === '' && $contract['lines'] === [] && $contract['date'] === $today && $contract['due_date'] === null && $contract['valid_until'] === null
	&& $contract['period_from'] === null && $contract['released'] === false && $contract['sign_state'] === '' && $contract['parent'] === [ 'id' => $offerFinal['id'], 'number' => $offerFinal['number'], 'date' => $offerFinal['date'], 'title' => '', 'category' => 'offer', 'state' => $offerNow['state'] ] );
check( '...the client may be named when it is the offer\'s', is_array( Documents::add( $appData, 'contract', $offerFinal['client_id'], [ 'parent_id' => $offerFinal['id'], 'title' => 'Zwei' ] ) ) );
Documents::remove( $appData, (int) Db::row( $appData, 'SELECT MAX( id ) AS id FROM documents' )['id'] );

$offerDraft2 = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
$refusedContract = [
	'no offer'                                   => [ [ 'title' => 'T' ], 0, 'offer' ],
	'an offer that is none'                      => [ [ 'title' => 'T', 'parent_id' => 99999 ], 0, 'offer' ],
	'an offer id that is text'                   => [ [ 'title' => 'T', 'parent_id' => (string) $offerFinal['id'] ], 0, 'offer' ],
	'an invoice instead of an offer'             => [ [ 'title' => 'T', 'parent_id' => $secondInvoice['id'] ], 0, 'offer' ],
	'an offer that is still a draft'             => [ [ 'title' => 'T', 'parent_id' => $offerDraft2['id'] ], 0, 'offer' ],
	'the client of another client than the offer' => [ [ 'title' => 'T', 'parent_id' => $offerFinal['id'] ], $second['id'], 'client' ],
	'no title'                                   => [ [ 'parent_id' => $offerFinal['id'] ], 0, 'empty:title' ],
	'a title of blanks'                          => [ [ 'parent_id' => $offerFinal['id'], 'title' => " \t " ], 0, 'empty:title' ],
	'a title of 121 characters'                  => [ [ 'parent_id' => $offerFinal['id'], 'title' => str_repeat( 'ä', 121 ) ], 0, 'long:title' ],
	'a title with a line break'                  => [ [ 'parent_id' => $offerFinal['id'], 'title' => "Zwei\nZeilen" ], 0, 'format:title' ],
	'a title that is no text'                    => [ [ 'parent_id' => $offerFinal['id'], 'title' => [ 'x' ] ], 0, 'format:title' ],
	'a title that is no UTF-8'                   => [ [ 'parent_id' => $offerFinal['id'], 'title' => "\xC3\x28" ], 0, 'format:title' ],
	'a line'                                     => [ [ 'parent_id' => $offerFinal['id'], 'title' => 'T', 'lines' => [ $line() ] ], 0, 'format:lines' ],
	'a reverse charge'                           => [ [ 'parent_id' => $offerFinal['id'], 'title' => 'T', 'reverse_charge' => true ], 0, 'format:reverse_charge' ],
	'a template of the tag of an invoice'        => [ [ 'parent_id' => $offerFinal['id'], 'title' => 'T', 'template_key' => 'rechnung' ], 0, 'template' ],
];

foreach( $refusedContract as $why => [ $input, $clientId, $code ] )
	check( 'a contract is refused - '. $why. ' ('. $code. ')', Documents::add( $appData, 'contract', $clientId, $input ) === $code );

check( '...a title of 120 characters passes, 120 letters with umlauts too', is_array( $long = Documents::add( $appData, 'contract', 0, [ 'parent_id' => $offerFinal['id'], 'title' => str_repeat( 'ä', 120 ) ] ) ) && mb_strlen( $long['title'], 'UTF-8' ) === 120 );
Documents::remove( $appData, $long['id'] );
Documents::remove( $appData, $offerDraft2['id'] );
check( '...and nothing of the refused was stored', count( Db::rows( $appData, 'SELECT id FROM documents WHERE category = \'contract\'' ) ) === 1 );

$privacy = Documents::add( $appData, 'privacy', $first['id'], [ 'title' => 'Datenschutz', 'template_key' => 'datenschutz', 'parent_id' => $offerFinal['id'], 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'due_date' => '2026-12-01' ] );
check( 'a privacy document is of a client; an offer named for it, a period and a due date are not kept', is_array( $privacy ) && $privacy['client_id'] === $first['id'] && $privacy['parent_id'] === null && $privacy['period_from'] === null && $privacy['due_date'] === null && $privacy['title'] === 'Datenschutz' );
check( '...it needs a client that is there and active, and a title', Documents::add( $appData, 'privacy', 0, [ 'title' => 'T' ] ) === 'client' && Documents::add( $appData, 'information', $third['id'], [ 'title' => 'T' ] ) === 'inactive' && Documents::add( $appData, 'information', $first['id'] ) === 'empty:title' );
$info = Documents::add( $appData, 'information', $second['id'], [ 'title' => 'Zugang', 'template_key' => 'zugang' ] );
check( 'an information document is the same', is_array( $info ) && $info['category'] === 'information' && $info['client_id'] === $second['id'] && $info['lines'] === [] );

$invoiceWithTitle = Documents::add( $appData, 'invoice', $first['id'], [ 'title' => 'Nicht meins', 'parent_id' => $offerFinal['id'], 'lines' => $lines ] );
check( 'a title or an offer named for an invoice is not kept: the title is of the documents without lines, the parent of the contract', $invoiceWithTitle['title'] === '' && $invoiceWithTitle['parent_id'] === null );
Documents::remove( $appData, $invoiceWithTitle['id'] );

// change(): the title, the template, the offer - and the client follows it
$changedTitle = Documents::change( $appData, $contract['id'], [ 'title' => 'Vertrag neu', 'notes' => 'n' ] );
check( 'a draft without lines changes its title, and keeps the rest', is_array( $changedTitle ) && $changedTitle['title'] === 'Vertrag neu' && $changedTitle['parent_id'] === $offerFinal['id'] && $changedTitle['template_key'] === 'dienstleistung' && $changedTitle['inputs'] === [ 'zusatz' => 'Laufzeit ein Jahr' ] );
check( '...a title that is wrong is refused as it is in add(), and the draft stays', Documents::change( $appData, $contract['id'], [ 'title' => '' ] ) === 'empty:title' && Documents::change( $appData, $contract['id'], [ 'title' => str_repeat( 'x', 121 ) ] ) === 'long:title' && Documents::get( $appData, $contract['id'] )['title'] === 'Vertrag neu' );
check( '...a line is refused', Documents::change( $appData, $contract['id'], [ 'lines' => [ $line() ] ] ) === 'format:lines' && Documents::change( $appData, $contract['id'], [ 'lines' => [] ] ) !== 'format:lines' );

$otherOffer = Documents::add( $appData, 'offer', $second['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
check( 'a contract cannot hang on an offer that is a draft', Documents::change( $appData, $contract['id'], [ 'parent_id' => $otherOffer['id'] ] ) === 'offer' );
$otherFinal = Documents::finalise( $appData, $otherOffer['id'] );
$moved = Documents::change( $appData, $contract['id'], [ 'parent_id' => $otherFinal['id'] ] );
$movedBack = is_array( $moved ) ? Documents::change( $appData, $contract['id'], [ 'parent_id' => $offerFinal['id'] ] ) : [];
check( '...but on another offer: its client follows the offer, there and back', is_array( $moved ) && $moved['parent_id'] === $otherFinal['id'] && $moved['client_id'] === $second['id'] && is_array( $movedBack ) && $movedBack['client_id'] === $first['id'] );
Documents::change( $appData, $contract['id'], [ 'parent_id' => $otherFinal['id'] ] );
check( '...a client named for it that is not the offer\'s is refused', Documents::change( $appData, $contract['id'], [ 'client_id' => $first['id'] ] ) === 'client' && Documents::get( $appData, $contract['id'] )['client_id'] === $second['id'] );
Documents::change( $appData, $contract['id'], [ 'parent_id' => $offerFinal['id'] ] );

$movedPrivacy = Documents::change( $appData, $privacy['id'], [ 'client_id' => $second['id'], 'parent_id' => $offerFinal['id'] ] );
check( 'a privacy document changes its client; an offer named for it is not kept', is_array( $movedPrivacy ) && $movedPrivacy['client_id'] === $second['id'] && $movedPrivacy['parent_id'] === null );
Documents::change( $appData, $privacy['id'], [ 'client_id' => $first['id'] ] );
$invoiceDraft = Documents::add( $appData, 'invoice', $first['id'], [ 'lines' => $lines ] );
check( '...nor is an invoice\'s parent changed by it', Documents::change( $appData, $invoiceDraft['id'], [ 'parent_id' => $offerFinal['id'], 'title' => 'T' ] )['parent_id'] === null );
Documents::remove( $appData, $invoiceDraft['id'] );

// finalising
$sequencesBefore = $sequences();
$filesBefore = $files();
$plainStops = static function( int $id, string $code, string $why ) use ( &$appData ): void {
	check( 'finalising is refused - '. $why. ' ('. $code. '): the draft as it was, no file', Documents::finalise( $appData, $id ) === $code && Documents::get( $appData, $id )['state'] === 'draft' );
};
$noInput = Documents::add( $appData, 'contract', 0, [ 'parent_id' => $offerFinal['id'], 'title' => 'Ohne' ] );
$plainStops( $noInput['id'], 'template', 'no template chosen' );
Documents::change( $appData, $noInput['id'], [ 'template_key' => 'dienstleistung' ] );
$plainStops( $noInput['id'], 'inputs:zusatz', 'a required input is empty' );
Documents::change( $appData, $noInput['id'], [ 'inputs' => [ 'zusatz' => 'x' ] ] );
$loose = Documents::add( $appData, 'offer', $first['id'] );
Db::exec( $appData, 'UPDATE documents SET parent_id = :offer WHERE id = :id', [ 'offer' => $loose['id'], 'id' => $noInput['id'] ] );
$plainStops( $noInput['id'], 'offer', 'its offer is a draft (made so behind the back of the model)' );
Documents::remove( $appData, $noInput['id'] );
Documents::remove( $appData, $loose['id'] );
check( '...and nothing was spent: the sequences and the files are as they were', $sequences() === $sequencesBefore && $files() === $filesBefore );

$sealed = Documents::finalise( $appData, $contract['id'] );
check( 'a contract is finalised without a number: the state is open, the number stays empty and is NULL in the table, the totals stay 0', is_array( $sealed ) && $sealed['state'] === 'open' && $sealed['number'] === '' && Db::row( $appData, 'SELECT number FROM documents WHERE id = :id', [ 'id' => $contract['id'] ] )['number'] === null
	&& $sealed['net'] === 0 && $sealed['gross'] === 0 && $sealed['finalised'] !== null );
check( '...no sequence moved', $sequences() === $sequencesBefore );
check( '...its PDF is /data/crm/pdf/<year>/contract-<id>.pdf, written once, with the hash the table holds', $sealed['pdf_path'] === '/data/crm/pdf/'. substr( $today, 0, 4 ). '/contract-'. $contract['id']. '.pdf' && is_file( \Nino\Filesystem::path( $appData, $sealed['pdf_path'] ) )
	&& hash_file( 'sha256', \Nino\Filesystem::path( $appData, $sealed['pdf_path'] ) ) === $sealed['pdf_sha256'] && count( $files() ) === count( $filesBefore ) + 1 );
check( '...the client is frozen in it: a change of the client does not reach the document', ( \Crm\Clients::change( $appData, $first['id'], [ 'city' => 'Kiel' ] ) && Documents::get( $appData, $contract['id'] )['client']['city'] === 'Hamburg' ) && ( \Crm\Clients::change( $appData, $first['id'], [ 'city' => 'Hamburg' ] ) !== '' ) );
check( '...it is fixed: nothing but the notes changes, a finalised document is not deleted, and finalising again is refused', Documents::change( $appData, $contract['id'], [ 'title' => 'x' ] ) === 'fixed' && Documents::remove( $appData, $contract['id'] ) === 'fixed' && Documents::finalise( $appData, $contract['id'] ) === 'fixed'
	&& is_array( Documents::setNotes( $appData, $contract['id'], 'neu' ) ) );
check( '...and appears in the list under its title, with no amount worth a line', ( array_values( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['id'] === $contract['id'] ) )[0] ?? [] ) === [
	'id' => $contract['id'], 'category' => 'contract', 'number' => '', 'title' => 'Vertrag neu', 'state' => 'open', 'released' => false, 'reminded_at' => '', 'reminded_mail' => false, 'sign_state' => '', 'expired' => false, 'overdue' => false, 'date' => $today, 'due_date' => null, 'valid_until' => null, 'gross' => 0, 'client_id' => $first['id'], 'client_title' => 'Studio Nord GmbH', 'parent_id' => $offerFinal['id'], 'project' => '', 'project_title' => '', 'template_key' => 'dienstleistung', 'paid_notice' => '' ] );
\Crm\Clients::change( $appData, $first['id'], [ 'company' => 'Studio Neu GmbH' ] );
check( '...the client it shows is the one frozen into it, not the one the client is now', array_column( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['id'] === $contract['id'] ), 'client_title' ) === [ 'Studio Nord GmbH' ] );
\Crm\Clients::change( $appData, $first['id'], [ 'company' => 'Studio Nord GmbH' ] );
check( '...the filter by its kind finds it', array_column( Documents::list( $appData, [ 'category' => 'contract' ] ), 'id' ) === [ $contract['id'] ] );
check( '...and appears under its offer, which names it as a child with the title', in_array( [ 'id' => $contract['id'], 'number' => '', 'title' => 'Vertrag neu', 'category' => 'contract', 'state' => 'open', 'gross' => 0 ], Documents::get( $appData, $offerFinal['id'] )['children'], true ) );

$privacyFinal = Documents::finalise( $appData, Documents::add( $appData, 'privacy', $first['id'], [ 'title' => 'Datenschutz', 'template_key' => 'datenschutz' ] )['id'] );
$infoFinal = Documents::finalise( $appData, $info['id'] );
check( 'a privacy and an information document are finalised the same: their files are privacy-<id>.pdf and information-<id>.pdf', is_array( $privacyFinal ) && is_array( $infoFinal ) && basename( $privacyFinal['pdf_path'] ) === 'privacy-'. $privacyFinal['id']. '.pdf' && basename( $infoFinal['pdf_path'] ) === 'information-'. $infoFinal['id']. '.pdf'
	&& $privacyFinal['number'] === '' && $infoFinal['state'] === 'open' && $sequences() === $sequencesBefore );

check( 'a document without a number moves one step: sent; paid, accepted and declined are none of its steps', is_array( Documents::setState( $appData, $infoFinal['id'], 'sent' ) ) && Documents::setState( $appData, $infoFinal['id'], 'paid' ) === 'state' && Documents::setState( $appData, $privacyFinal['id'], 'accepted' ) === 'state'
	&& Documents::setState( $appData, $privacyFinal['id'], 'signed' ) === 'state' && Documents::setState( $appData, $contract['id'], 'paid' ) === 'state' && Documents::setState( $appData, $privacyFinal['id'], 'declined' ) === 'state' && Documents::get( $appData, $infoFinal['id'] )['state'] === 'sent' );

// the tokens, the slots and the words of the PDF
$contractFull = (array) Documents::get( $appData, $contract['id'] );
$contractData = Documents::dataOf( $appData, $contractFull );
check( 'the tokens of a contract: its own title, date and the number of its offer; the client as it was frozen', array_intersect_key( $contractData['tokens'], array_flip( preg_grep( '~^/document/~', array_keys( $contractData['tokens'] ) ) ) ) === [ '/document/title' => 'Vertrag neu', '/document/date' => Render::day( $today ), '/document/parent' => $offerFinal['number'] ] && $contractData['tokens']['/customer/company'] === 'Studio Nord GmbH' );
check( '...and the offer\'s number, day, validity and totals with the currency', array_intersect_key( $contractData['tokens'], array_flip( preg_grep( '~^/offer/~', array_keys( $contractData['tokens'] ) ) ) ) === [ '/offer/number' => $offerNow['number'], '/offer/date' => Render::day( $offerNow['date'] ), '/offer/valid' => Render::day( (string) $offerNow['valid_until'] ), '/offer/valid-sentence' => Render::validSentence( (string) $offerNow['valid_until'] ),
	'/offer/gross' => Money::format( $offerNow['gross'], '€' ), '/offer/period' => Render::text( 'period.none-offer' ) ] && $offerNow['gross'] > 0 );
check( '...the slots address, lines (the offer\'s lines) and totals (the offer\'s) so that a contract can quote them', array_keys( $contractData['slots'] ) === [ 'address', 'lines', 'totals' ] && $contractData['slots']['lines'] === Render::lines( $offerNow['lines'], Money::totals( $offerNow['lines'], $offerNow['vat_mode'], $offerNow['reverse_charge'] ), $offerNow['vat_mode'] === 'regular', '€' )
	&& str_contains( $contractData['slots']['lines'], 'Webdesign' ) === true && str_contains( $contractData['slots']['totals'], 'Gesamtbetrag' ) === true && $contractData['inputs'] === [ 'zusatz' => 'Laufzeit ein Jahr' ] );
check( '.../site/portal and /site/login are the absolute addresses of the portal and of the site (whose start page is the login)', array_intersect_key( $contractData['tokens'], [ '/site/portal' => 0, '/site/login' => 0 ] ) === [ '/site/portal' => 'https://example.org/portal', '/site/login' => 'https://example.org' ] );
$privacyData = Documents::dataOf( $appData, (array) Documents::get( $appData, $privacyFinal['id'] ) );
check( 'a privacy document has no offer: the tokens /document/title, /date and /parent (empty), /customer/* and /site/*, and the slot address alone', array_intersect_key( $privacyData['tokens'], array_flip( preg_grep( '~^/document/~', array_keys( $privacyData['tokens'] ) ) ) ) === [ '/document/title' => 'Datenschutz', '/document/date' => Render::day( $today ), '/document/parent' => '' ] && preg_grep( '~^/offer/~', array_keys( $privacyData['tokens'] ) ) === []
	&& array_keys( $privacyData['slots'] ) === [ 'address' ] && $privacyData['tokens']['/site/portal'] === 'https://example.org/portal' );
Db::exec( $appData, 'UPDATE documents SET parent_id = :offer WHERE id = :id', [ 'offer' => $offerFinal['id'], 'id' => $privacyFinal['id'] ] );
check( 'only a contract quotes an offer: a privacy document that hangs on one by accident has no offer tokens', preg_grep( '~^/offer/~', array_keys( Documents::dataOf( $appData, (array) Documents::get( $appData, $privacyFinal['id'] ) )['tokens'] ) ) === [] );
Db::exec( $appData, 'UPDATE documents SET parent_id = NULL WHERE id = :id', [ 'id' => $privacyFinal['id'] ] );
check( 'an invoice has the site tokens too, for a letter that quotes the portal', array_intersect_key( Render::data( $appData, $secondInvoice, $secondInvoice['lines'], $secondInvoice['client_frozen'] )['tokens'], [ '/site/portal' => 0, '/site/login' => 0 ] ) === [ '/site/portal' => 'https://example.org/portal', '/site/login' => 'https://example.org' ] );
$addressless = $appData;
\Nino\Html::addFills( $addressless, [ '[[/project/website/general/url]]' => '' ], '*' );
check( 'a site with no address has empty site tokens', array_intersect_key( Render::data( $addressless, $secondInvoice, [], [] )['tokens'], [ '/site/portal' => 0, '/site/login' => 0 ] ) === [ '/site/portal' => '', '/site/login' => '' ] );

$pdfText = static fn( string $file ): string => (string) shell_exec( 'pdftotext -layout '. escapeshellarg( $file ). ' - 2>/dev/null' );

if( trim( (string) @shell_exec( 'command -v pdftotext 2>/dev/null' ) ) !== '' ) {

	$contractText = $pdfText( \Nino\Filesystem::path( $appData, $sealed['pdf_path'] ) );
	check( 'the PDF of the contract says its title, the offer\'s number and totals, the lines of the offer, the free field and the addresses of the site', str_contains( $contractText, 'Vertrag neu' ) === true && str_contains( $contractText, $offerNow['number'] ) === true && str_contains( $contractText, '237,00' ) === true
		&& str_contains( $contractText, 'Webdesign' ) === true && str_contains( $contractText, 'Gesamtbetrag' ) === true && str_contains( $contractText, 'Laufzeit ein Jahr' ) === true && str_contains( $contractText, 'https://example.org/portal' ) === true && str_contains( $contractText, 'Studio Nord GmbH' ) === true );
	check( '...the PDF of an information document says the addresses to the portal and the login, for the name of the client', str_contains( $pdfText( \Nino\Filesystem::path( $appData, $infoFinal['pdf_path'] ) ), 'Ben Süd: https://example.org/portal und https://example.org' ) === true );
}

// the route for the workbench: the name of the file is the one of the file
$as( 'admin@example.com' );
$served = docServe( $appData, '/.crm/pdf/'. $contract['id'] );
check( 'the workbench is served the PDF of a document without a number under the name of its file', ( $served['file'] ?? '' ) === \Nino\Filesystem::path( $appData, $sealed['pdf_path'] ) && $served['header']['Content-Disposition'] === 'inline; filename="contract-'. $contract['id']. '.pdf"'
	&& docServe( $appData, '/.crm/pdf/'. $contract['id']. '?download=1' )['header']['Content-Type'] === 'application/pdf' );
unlink( \Nino\Filesystem::path( $appData, $infoFinal['pdf_path'] ) );
$ignored = ninoWarnings();
$goneResponse = docServe( $appData, '/.crm/pdf/'. $infoFinal['id'] );
check( '...a file that is gone is a 404 and the log names the document by its category and id', $nothing( $goneResponse ) === true && str_contains( implode( ' ', ninoWarnings() ), 'information-'. $infoFinal['id'] ) === true );
file_put_contents( \Nino\Filesystem::path( $appData, $infoFinal['pdf_path'] ), 'bytes' );
check( '...and a file that is not the one that was written is a 409', docServe( $appData, '/.crm/pdf/'. $infoFinal['id'] )['statusCode'] === 409 );

// release and take back, without a mail
echo "\nRelease\n";

check( 'a draft is not released, nobody is', Documents::release( $appData, $noInput['id'], false ) === 'unknown' && Documents::release( $appData, 99999, false ) === 'unknown' && Documents::release( $appData, (int) Documents::add( $appData, 'information', $first['id'], [ 'title' => 'E' ] )['id'], false ) === 'state' );
Documents::remove( $appData, (int) Db::row( $appData, 'SELECT MAX( id ) AS id FROM documents' )['id'] );
$released = Documents::release( $appData, $contract['id'], false );
check( 'releasing a finalised document sets the flag and the day, mails nobody, and changes no state', is_array( $released ) && $released['document']['released'] === true && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string) $released['document']['released_at'] ) === 1 && $released['mailed'] === 0 && $released['document']['state'] === 'open' );
Db::exec( $appData, 'UPDATE documents SET released_at = \'2026-01-01T00:00:00Z\' WHERE id = :id', [ 'id' => $contract['id'] ] );
check( '...releasing again keeps the day of the first release', Documents::release( $appData, $contract['id'], false )['document']['released_at'] === '2026-01-01T00:00:00Z' );
$taken = Documents::unrelease( $appData, $contract['id'] );
check( 'taking it back clears the flag and the day; the file stays', is_array( $taken ) && $taken['released'] === false && $taken['released_at'] === null && is_file( \Nino\Filesystem::path( $appData, $taken['pdf_path'] ) ) === true && $taken['pdf_sha256'] === $sealed['pdf_sha256'] );
check( '...a document nobody has is unknown, a draft is not taken back', Documents::unrelease( $appData, 99999 ) === 'unknown' && Documents::unrelease( $appData, $privacy['id'] ) === 'state' );
$sentOffer = Documents::release( $appData, $otherFinal['id'], false );
check( 'an offer that is open and released without a mail stays open: the mail is the sending', $otherFinal['state'] === 'open' && is_array( $sentOffer ) && $sentOffer['document']['state'] === 'open' && $sentOffer['document']['released'] === true );
Documents::unrelease( $appData, $otherFinal['id'] );
$counts = Documents::counts( $appData, $first['id'] );
check( 'a client has its documents counted: all, and the ones released', $counts === [ 'total' => (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE client_id = :c', [ 'c' => $first['id'] ] )['n'], 'released' => 0 ] && $counts['total'] > 5 );
Documents::release( $appData, $privacyFinal['id'], false );
check( '...released and listed for the portal: the newest first, with the title, and only the released ones', array_column( Documents::released( $appData, $first['id'] ), 'id' ) === [ $privacyFinal['id'] ] && Documents::released( $appData, $first['id'] )[0]['title'] === 'Datenschutz' && Documents::released( $appData, $second['id'] ) === [] );
Documents::unrelease( $appData, $privacyFinal['id'] );

// the panel
echo "\nThe panel: the three documents, the release, the signature\n";

[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'contract', 'parent_id' => (string) $offerFinal['id'], 'title' => 'Per Panel', 'template_key' => 'dienstleistung', 'inputs' => [ 'zusatz' => 'x' ], 'date' => '2026-06-01' ] );
check( 'save adds a contract from the offer: the client follows, the title is kept, no client is needed', $status === 200 && $body['document']['category'] === 'contract' && $body['document']['client_id'] === $first['id'] && $body['document']['title'] === 'Per Panel' && $body['document']['parent_id'] === $offerFinal['id'] );
$panelContract = $body['document'];
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $panelContract['id'], 'title' => 'Per Panel 2', 'parent_id' => (string) $otherFinal['id'] ] );
check( '...and changes it, the client following the offer', $status === 200 && $body['document']['title'] === 'Per Panel 2' && $body['document']['client_id'] === $second['id'] );
$panelRefusals = [
	'a contract without an offer'  => [ [ 'category' => 'contract', 'title' => 'T' ], 400, 'crm_documents_offer', 'parent_id' ],
	'an offer that is a draft'     => [ [ 'category' => 'contract', 'title' => 'T', 'parent_id' => (string) Documents::add( $appData, 'offer', $first['id'] )['id'] ], 400, 'crm_documents_offer', 'parent_id' ],
	'no title'                     => [ [ 'category' => 'privacy', 'client_id' => (string) $first['id'] ], 400, 'crm_documents_title', 'title' ],
	'a title that is too long'     => [ [ 'category' => 'privacy', 'client_id' => (string) $first['id'], 'title' => str_repeat( 'x', 121 ) ], 400, 'crm_documents_long', 'title' ],
	'a title with a line break'    => [ [ 'category' => 'privacy', 'client_id' => (string) $first['id'], 'title' => "a\nb" ], 400, 'crm_documents_format', 'title' ],
	'no client for a privacy one'  => [ [ 'category' => 'information', 'title' => 'T' ], 400, 'crm_documents_client', 'client_id' ],
];

foreach( $panelRefusals as $why => [ $input, $expectedStatus, $code, $field ] ) {
	[ $status, $body ] = $post( 'crm-documents/save', $input );
	check( 'save is refused - '. $why. ': '. $expectedStatus. ' '. $code. ' on '. $field, $status === $expectedStatus && $body['code'] === $code && $body['field'] === $field );
}

check( '...the limit of a title is told in the sentence: 120', $post( 'crm-documents/save', [ 'category' => 'privacy', 'client_id' => (string) $first['id'], 'title' => str_repeat( 'x', 121 ) ] )[1]['params'] === [ 120 ] );
Documents::remove( $appData, (int) Db::row( $appData, 'SELECT id FROM documents WHERE category = \'offer\' AND state = \'draft\'' )['id'] );

[ $status, $body ] = $post( 'crm-documents/templates', [ 'category' => 'contract' ] );
check( 'templates answers the templates of the tag contract with their inputs, and the offers a contract can hang on: finalised ones, the newest first, with their client', $status === 200 && array_column( $body['templates'], 'key' ) === [ 'dienstleistung', 'vertrag' ] && $body['templates'][0]['inputs'][0]['name'] === 'zusatz'
	&& array_column( $body['offers'], 'number' ) === array_column( array_filter( Documents::list( $appData, [ 'category' => 'offer' ] ), static fn( array $row ): bool => $row['state'] !== 'draft' ), 'number' ) && array_keys( $body['offers'][0] ) === [ 'id', 'number', 'date', 'client_id', 'client_title' ] && count( $body['offers'] ) >= 2 );
check( '...list answers the offers as well, and the rows with the title, the mark of the release and the state of the request', array_keys( $post( 'crm-documents/list' )[1] ) === [ 'documents', 'years', 'clients', 'settings', 'items', 'offers', 'export' ] && array_diff( [ 'title', 'released', 'sign_state' ], array_keys( $post( 'crm-documents/list' )[1]['documents'][0] ) ) === [] );

[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $contract['id'] ] );
check( 'get answers a contract with the accounts of its client (none yet), the days of Sign\'s setting and the offers', $status === 200 && $body['document']['title'] === 'Vertrag neu' && $body['accounts'] === [] && $body['sign_days'] === 14 && is_array( $body['offers'] ) && $body['document']['sign_shown_url'] === '' && $body['document']['sign_signed_url'] === '' );
check( '...a draft or an invoice has no accounts to ask', $post( 'crm-documents/get', [ 'id' => (string) $panelContract['id'] ] )[1]['accounts'] === [] && $post( 'crm-documents/get', [ 'id' => (string) $secondInvoice['id'] ] )[1]['accounts'] === [] );

[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $panelContract['id'] ] );
check( 'finalise makes the PDF of the contract; the document the screen gets has the address of it', $status === 200 && $body['document']['state'] === 'open' && $body['document']['number'] === '' && $body['document']['pdf_url'] === '/.crm/pdf/'. $panelContract['id'] );
check( '...and answers the view of the document, as get does: the accounts of its client and the days of Sign come with it, so that the page can offer the signature at once', array_diff( [ 'document', 'templates', 'clients', 'settings', 'items', 'offers', 'accounts', 'sign_days' ], array_keys( $body ) ) === [] && $body['sign_days'] === 14 );
check( '...the log line names a document without a number by its category and id', \Crm\Documents\Admin::log( 'crm-documents/finalise', [] ) === 'Finalised document contract-'. $panelContract['id'] );
$post( 'crm-documents/state', [ 'id' => (string) $privacyFinal['id'], 'state' => 'sent' ] );
check( '...so does the one of a step', \Crm\Documents\Admin::log( 'crm-documents/state', [] ) === 'Moved document privacy-'. $privacyFinal['id']. ' to sent' );

[ $status, $body ] = $post( 'crm-documents/release', [ 'id' => (string) $contract['id'] ] );
check( 'release without the tick posts no mail: the document is released, mailed is 0 and mail false', $status === 200 && $body['mailed'] === 0 && $body['mail'] === false && $body['document']['released'] === true );
check( '...the log names the document by its category and id', \Crm\Documents\Admin::log( 'crm-documents/release', [ 'id' => (string) $contract['id'] ] ) === 'Released document contract-'. $contract['id'] );
$textMails = [];

foreach( [ '1', 'yes', 'true', 1 ] as $text )
	$textMails[] = $post( 'crm-documents/release', [ 'id' => (string) $contract['id'], 'mail' => $text ] )[1]['mail'];

check( 'only a true mail is a mail: "1", "yes", "true" and 1 are none (the screen posts a boolean)', $textMails === [ false, false, false, false ] );
[ $status, $body ] = $post( 'crm-documents/release', [ 'id' => (string) $contract['id'], 'mail' => true ] );
check( '...a true one is: nobody is mailed here, the client has no account, and the answer says the mail was asked for', $status === 200 && $body['mail'] === true && $body['mailed'] === 0 );
[ $status, $body ] = $post( 'crm-documents/unrelease', [ 'id' => (string) $contract['id'] ] );
check( 'unrelease takes it back', $status === 200 && $body['document']['released'] === false && \Crm\Documents\Admin::log( 'crm-documents/unrelease', [] ) === 'Took back document contract-'. $contract['id'] );

$bad = [
	'release of nothing'         => [ 'crm-documents/release', [ 'id' => '99999' ], 404, 'crm_documents_unknown' ],
	'release with an id that is none' => [ 'crm-documents/release', [ 'id' => '0x1' ], 400, 'crm_documents_id' ],
	'release of a draft'         => [ 'crm-documents/release', [ 'id' => (string) $privacy['id'] ], 409, 'crm_documents_state' ],
	'unrelease of nothing'       => [ 'crm-documents/unrelease', [ 'id' => '99999' ], 404, 'crm_documents_unknown' ],
	'unrelease of a draft'       => [ 'crm-documents/unrelease', [ 'id' => (string) $privacy['id'] ], 409, 'crm_documents_state' ],
	'sign of nothing'            => [ 'crm-documents/sign', [ 'id' => '99999', 'account' => 'aaaaaaaaaaaa' ], 404, 'crm_documents_unknown' ],
	'sign with an account that is none' => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'nobody' ], 400, 'crm_documents_account' ],
	'sign with days that are none' => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'aaaaaaaaaaaa', 'days' => 'x' ], 400, 'crm_documents_days' ],
	'sign with days that are a number and a word' => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'aaaaaaaaaaaa', 'days' => '7d' ], 400, 'crm_documents_days' ],
	'sign with an account that is a number and a word' => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'aaaaaaaaaaaa!', 'days' => '7' ], 400, 'crm_documents_account' ],
	'sign of an invoice'         => [ 'crm-documents/sign', [ 'id' => (string) $secondInvoice['id'], 'account' => 'aaaaaaaaaaaa' ], 400, 'crm_documents_category' ],
	'sign of an information document' => [ 'crm-documents/sign', [ 'id' => (string) $infoFinal['id'], 'account' => 'aaaaaaaaaaaa' ], 400, 'crm_documents_category' ],
	'sign of a draft'            => [ 'crm-documents/sign', [ 'id' => (string) $privacy['id'], 'account' => 'aaaaaaaaaaaa' ], 409, 'crm_documents_state' ],
	'sign for days past 90'      => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'aaaaaaaaaaaa', 'days' => '91' ], 400, 'crm_documents_days' ],
	'sign with Sign off'         => [ 'crm-documents/sign', [ 'id' => (string) $contract['id'], 'account' => 'aaaaaaaaaaaa' ], 409, 'crm_documents_sign' ],
];

foreach( $bad as $why => [ $action, $input, $expectedStatus, $code ] ) {
	[ $status, $body ] = $post( $action, $input );
	check( $why. ' is '. $expectedStatus. ' '. $code, $status === $expectedStatus && ( $body['code'] ?? '' ) === $code );
}

check( '...none of them wrote anything: the contract is open, not released, and has no request', ( $row = Documents::get( $appData, $contract['id'] ) ) !== null && $row['released'] === false && $row['sign_id'] === '' && $row['sign_state'] === '' );

// --- Projekte -------------------------------------------------------------------------------------------------------------

echo "\nProjekte\n";

check( 'the column of the project: text, not null, empty by default - no project; and an index on it', ( $column = array_values( array_filter( Db::rows( $appData, 'PRAGMA table_info( documents )' ), static fn( array $row ): bool => $row['name'] === 'project' ) )[0] ?? [] ) !== []
	&& $column['type'] === 'TEXT' && (int) $column['notnull'] === 1 && $column['dflt_value'] === "''" && array_column( Db::rows( $appData, 'PRAGMA index_info( documents_project )' ), 'name' ) === [ 'project' ] );

$made = static function( string $title, string $client, array $over = [] ) use ( &$appData ): string {
	$element = \Nino\Elements::insertElement( $appData, '/projekte/', [ 'title' => $title, 'client' => $client ] + $over, '*' );
	return is_array( $element ) === true ? \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ) : '';
};
$albumId = $made( 'Album 2027', $first['number'], [ 'description' => "Aufnahme\nund Mix", 'started' => '2026-10-01' ] );
$websiteId = $made( 'Webseite', $first['number'], [ 'done' => true ] );
$podcastId = $made( 'Podcast', $second['number'], [ 'folder' => 'abcdef012345' ] );

check( 'a project is numbered by the type: 00001, 00002, 00003', [ $albumId, $websiteId, $podcastId ] === [ '00001', '00002', '00003' ] && \Crm\Projekte::missing( $appData ) === false );
check( 'get() answers the fields and the id and nothing else; a project nobody has, an id that is no id and the id of a path are none', array_keys( (array) \Crm\Projekte::get( $appData, $albumId ) ) === [ 'id', 'title', 'client', 'description', 'started', 'done', 'folder', 'download', 'phase' ]
	&& \Crm\Projekte::get( $appData, $albumId ) === [ 'id' => '00001', 'title' => 'Album 2027', 'client' => 'K-0001', 'description' => "Aufnahme\nund Mix", 'started' => '2026-10-01', 'done' => false, 'folder' => '', 'download' => false, 'phase' => '' ]
	&& \Crm\Projekte::get( $appData, $websiteId )['done'] === true && \Crm\Projekte::get( $appData, $podcastId )['folder'] === 'abcdef012345'
	&& \Crm\Projekte::get( $appData, '00099' ) === null && \Crm\Projekte::get( $appData, '../x' ) === null && \Crm\Projekte::get( $appData, '' ) === null );
$odd = \Nino\Elements::insertElement( $appData, '/projekte/mit leerzeichen', [ 'title' => 'Seltsam', 'client' => $first['number'] ], '*' );
$oddRead = \Crm\Projekte::get( $appData, 'mit leerzeichen' );
\Nino\Elements::deleteElement( $appData, '/projekte/mit leerzeichen', '*' );
check( 'get() takes the id of an element a screen could not name: an element whose uri has a space in it is there for the kernel and none for get()', is_array( $odd ) === true && $oddRead === null && \Crm\Projekte::get( $appData, 'mit leerzeichen' ) === null );
check( 'list() has the newest first, each with its documents and the offers that wait; ofClient() the client\'s, the done ones among them; openOnly leaves the done ones out; a search finds by the title', array_column( \Crm\Projekte::list( $appData ), 'id' ) === [ '00003', '00002', '00001' ]
	&& array_column( \Crm\Projekte::ofClient( $appData, $first['number'] ), 'id' ) === [ '00002', '00001' ] && array_column( \Crm\Projekte::list( $appData, $first['number'], true ), 'id' ) === [ '00001' ]
	&& array_column( \Crm\Projekte::list( $appData, null, false, 'POD' ), 'id' ) === [ '00003' ] && array_column( \Crm\Projekte::list( $appData, null, false, 'nothing like it' ), 'id' ) === []
	&& array_keys( \Crm\Projekte::list( $appData )[0] ) === [ 'id', 'title', 'client', 'description', 'started', 'done', 'folder', 'documents', 'open', 'download', 'phase' ] && array_sum( array_column( \Crm\Projekte::list( $appData ), 'documents' ) ) === 0 );

$withProject = Documents::add( $appData, 'offer', $first['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ], 'project' => $albumId ] );
check( 'a draft names a project of its client: get() has the id and the title; the list too; a draft without one has none', is_array( $withProject ) && $withProject['project'] === $albumId && $withProject['project_title'] === 'Album 2027' && Documents::get( $appData, $bare['id'] )['project'] === '' && Documents::get( $appData, $bare['id'] )['project_title'] === ''
	&& ( array_values( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['id'] === $withProject['id'] ) )[0] ?? [] )['project_title'] === 'Album 2027' );
$count = count( Documents::list( $appData ) );
check( 'the project of another client is refused with project_client, one that does not exist with project, one that is no id with format:project - and nothing is stored', Documents::add( $appData, 'invoice', $first['id'], [ 'project' => $podcastId ] ) === 'project_client'
	&& Documents::add( $appData, 'invoice', $first['id'], [ 'project' => '00099' ] ) === 'project' && Documents::add( $appData, 'invoice', $first['id'], [ 'project' => '../etc' ] ) === 'format:project' && Documents::add( $appData, 'invoice', $first['id'], [ 'project' => 5 ] ) === 'format:project'
	&& count( Documents::list( $appData ) ) === $count );
check( '...a project of a client that is done is still one the document may name', ( Documents::add( $appData, 'invoice', $first['id'], [ 'project' => $websiteId ] )['project'] ?? '' ) === $websiteId );
Documents::remove( $appData, (int) Db::row( $appData, 'SELECT MAX( id ) AS id FROM documents' )['id'] );

$changed = Documents::change( $appData, $withProject['id'], [ 'notes' => 'x' ] );
check( 'change() leaves the project as it is when the input has none, sets another of the client\'s, takes it away with an empty one', $changed['project'] === $albumId && Documents::change( $appData, $withProject['id'], [ 'project' => $websiteId ] )['project'] === $websiteId
	&& Documents::change( $appData, $withProject['id'], [ 'project' => '' ] )['project'] === '' && Documents::change( $appData, $withProject['id'], [ 'project' => $albumId ] )['project'] === $albumId );
check( '...the project of another client is refused, and so is a change of the client that leaves the project with the old one; with an empty project the client may change', Documents::change( $appData, $withProject['id'], [ 'project' => $podcastId ] ) === 'project_client'
	&& Documents::change( $appData, $withProject['id'], [ 'client_id' => $second['id'] ] ) === 'project_client' && Documents::get( $appData, $withProject['id'] )['client_id'] === $first['id'] && Documents::get( $appData, $withProject['id'] )['project'] === $albumId
	&& ( Documents::change( $appData, $withProject['id'], [ 'client_id' => $second['id'], 'project' => $podcastId ] )['project'] ?? '' ) === $podcastId && ( Documents::change( $appData, $withProject['id'], [ 'client_id' => $first['id'], 'project' => $albumId ] )['project'] ?? '' ) === $albumId );
check( 'list() filters by the project; a project with no document has an empty list; the counts of the projects follow the documents', array_column( Documents::list( $appData, [ 'project' => $albumId ] ), 'id' ) === [ $withProject['id'] ] && Documents::list( $appData, [ 'project' => $websiteId ] ) === [] && Documents::list( $appData, [ 'project' => '' ] ) === Documents::list( $appData )
	&& \Crm\Projekte::documentsOf( $appData, $albumId ) === 1 && \Crm\Projekte::documentsOf( $appData, $websiteId ) === 0 && array_column( \Crm\Projekte::list( $appData, $first['number'] ), 'documents', 'id' ) === [ '00002' => 0, '00001' => 1 ] );

// A finalised document keeps its PDF and its hash when its project moves
$sealed = Documents::finalise( $appData, $withProject['id'] );
check( 'the offer is finalised with its project', is_array( $sealed ) && $sealed['state'] === 'open' && $sealed['project'] === $albumId && $sealed['pdf_sha256'] !== '' );
check( 'a finalised document is fixed to change() - the project is not part of its content', Documents::change( $appData, $sealed['id'], [ 'project' => $websiteId ] ) === 'fixed' );
$moved = Documents::setProject( $appData, $sealed['id'], $websiteId );
check( 'setProject() moves a finalised document to another project of its client: the PDF, its hash, its number and its lines stay', is_array( $moved ) && $moved['project'] === $websiteId && $moved['project_title'] === 'Webseite' && $moved['pdf_path'] === $sealed['pdf_path'] && $moved['pdf_sha256'] === $sealed['pdf_sha256']
	&& $moved['number'] === $sealed['number'] && $moved['gross'] === $sealed['gross'] && $moved['lines'] === $sealed['lines'] && $moved['finalised'] === $sealed['finalised'] && hash_file( 'sha256', \Nino\Filesystem::path( $appData, $moved['pdf_path'] ) ) === $sealed['pdf_sha256'] );
check( '...takes it out of its project with an empty one; refuses another client\'s (project_client), one that is none (project), a bad id (format:project) and a document nobody has (unknown)', Documents::setProject( $appData, $sealed['id'], '' )['project'] === '' && Documents::setProject( $appData, $sealed['id'], $podcastId ) === 'project_client'
	&& Documents::setProject( $appData, $sealed['id'], '00099' ) === 'project' && Documents::setProject( $appData, $sealed['id'], 'a/b' ) === 'format:project' && Documents::setProject( $appData, 999999, $albumId ) === 'unknown' && Documents::get( $appData, $sealed['id'] )['project'] === '' );
Documents::setProject( $appData, $sealed['id'], $albumId );

// The derived documents take the project along
$invoiceDraft = Documents::fromOffer( $appData, $sealed['id'] );
check( 'an invoice made from the offer is in the offer\'s project', is_array( $invoiceDraft ) && $invoiceDraft['project'] === $albumId && $invoiceDraft['project_title'] === 'Album 2027' );
Documents::change( $appData, $invoiceDraft['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
$invoiceDraft2 = Documents::finalise( $appData, $invoiceDraft['id'] );
$creditDraft = Documents::creditOf( $appData, $invoiceDraft2['id'] );
check( '...a credit note made from the invoice too, and a copy of any of them', is_array( $creditDraft ) && $creditDraft['project'] === $albumId && ( Documents::copy( $appData, $invoiceDraft2['id'] )['project'] ?? '' ) === $albumId && ( Documents::copy( $appData, $sealed['id'] )['project'] ?? '' ) === $albumId );
check( '...a copy of a contract (which hangs on the offer) is in the project of the contract', is_array( $contractCopy = Documents::copy( $appData, $contract['id'] ) ) && $contractCopy['project'] === Documents::get( $appData, $contract['id'] )['project'] );
// Behind the model's back: the veto of the kernel is taken away for the moment, as a project made by hand would not have it
$vetoes = $appData['./nino/callbacks']['/nino/elements/delete/projekte'] ?? null;
unset( $appData['./nino/callbacks']['/nino/elements/delete/projekte'] );
\Nino\Elements::deleteElement( $appData, '/projekte/'. $websiteId, '*' );
$orphan = Documents::add( $appData, 'invoice', $first['id'], [ 'project' => $albumId ] );
\Nino\Elements::deleteElement( $appData, '/projekte/'. $albumId, '*' );
$appData['./nino/callbacks']['/nino/elements/delete/projekte'] = $vetoes;
check( 'a project that is gone (deleted behind the model\'s back): the document shows no title for it, a copy of it has no project, and the list still filters by the id', Documents::get( $appData, $orphan['id'] )['project'] === $albumId && Documents::get( $appData, $orphan['id'] )['project_title'] === ''
	&& ( Documents::copy( $appData, $orphan['id'] )['project'] ?? 'x' ) === '' && ( Documents::copy( $appData, $invoiceDraft2['id'] )['project'] ?? 'x' ) === '' && array_column( Documents::list( $appData, [ 'project' => $albumId ] ), 'project_title' ) !== [] && array_unique( array_column( Documents::list( $appData, [ 'project' => $albumId ] ), 'project_title' ) ) === [ '' ] );
$albumId = $made( 'Album 2027', $first['number'] );
$websiteId = $made( 'Webseite', $first['number'] );
Documents::setProject( $appData, $sealed['id'], $albumId );
Documents::setProject( $appData, $invoiceDraft2['id'], $albumId );

// The actions
echo "\nThe actions of the projects\n";

[ $status, $body ] = $post( 'crm-documents/project', [ 'id' => (string) $sealed['id'], 'project' => $websiteId ] );
check( 'crm-documents/project moves a finalised document and answers it as save does: the project and its title, the address of the PDF unchanged', $status === 200 && $body['document']['project'] === $websiteId && $body['document']['project_title'] === 'Webseite' && $body['document']['pdf_url'] === '/.crm/pdf/'. $sealed['id'] && $body['document']['pdf_sha256'] === $sealed['pdf_sha256'] );
check( '...its log line names the document and the project, no address and no amount', Documents::get( $appData, $sealed['id'] )['project'] === $websiteId && preg_match( '~^Changed the project of document '. preg_quote( $sealed['number'], '~' ). ' to project '. $websiteId. '$~', \Crm\Documents\Admin::log( 'crm-documents/project', [ 'id' => (string) $sealed['id'] ] ) ) === 1 );
$other = $post( 'crm-documents/project', [ 'id' => (string) $sealed['id'], 'project' => $podcastId ] );
$none = $post( 'crm-documents/project', [ 'id' => (string) $sealed['id'], 'project' => '00099' ] );
$wrong = $post( 'crm-documents/project', [ 'id' => (string) $sealed['id'], 'project' => 5 ] );
check( '...the project of another client is 409 crm_project_client at the field project, one that does not exist 400 crm_project at the field, a project that is no string 400 crm_documents_format, a document nobody has 404 - and the document stays', $other[0] === 409 && $other[1]['code'] === 'crm_project_client' && $other[1]['field'] === 'project'
	&& $none[0] === 400 && $none[1]['code'] === 'crm_project' && $none[1]['field'] === 'project' && $wrong[0] === 400 && $wrong[1]['code'] === 'crm_documents_format'
	&& $post( 'crm-documents/project', [ 'id' => '999999', 'project' => '' ] )[0] === 404 && Documents::get( $appData, $sealed['id'] )['project'] === $websiteId );
check( '...an empty project takes the document out', $post( 'crm-documents/project', [ 'id' => (string) $sealed['id'], 'project' => '' ] )[1]['document']['project'] === '' );
Documents::setProject( $appData, $sealed['id'], $albumId );

[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'project' => $albumId ] );
check( 'crm-documents/save takes the project of a draft - made with one, changed to another, and the refusals are at the field project', $status === 200 && $body['document']['project'] === $albumId && $post( 'crm-documents/save', [ 'id' => (string) $body['document']['id'], 'project' => $websiteId ] )[1]['document']['project'] === $websiteId
	&& ( $r = $post( 'crm-documents/save', [ 'id' => (string) $body['document']['id'], 'project' => $podcastId ] ) )[0] === 409 && $r[1]['field'] === 'project' && ( $r = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $first['id'], 'project' => '00099' ] ) )[0] === 400 && $r[1]['code'] === 'crm_project' );
check( 'crm-documents/list filters by the project and takes nothing odd for one; the rows carry the project and its title; crm-documents/get has the projects of the client for the dialog', array_column( $post( 'crm-documents/list', [ 'project' => $albumId ] )[1]['documents'], 'id' ) === array_column( Documents::list( $appData, [ 'project' => $albumId ] ), 'id' ) && $post( 'crm-documents/list', [ 'project' => '../x' ] )[0] === 400
	&& array_column( $post( 'crm-documents/list', [ 'project' => $albumId ] )[1]['documents'], 'project_title' ) !== [] && array_column( $post( 'crm-documents/get', [ 'id' => (string) $sealed['id'] ] )[1]['projects'], 'id' ) === [ $websiteId, $albumId ] );
check( 'crm-documents/status names the type of the projects as it names the one of the items', $stateOf( $post( 'crm-documents/status' )[1], 'projekte' ) === 'ok' );

// --- E-Rechnung (XRechnung) --------------------------------------------------------------------------------------------

echo "\nE-Rechnung (XRechnung)\n";

\Nino\Features::activate( $appData, 'pdf' );
$as( 'admin@example.com' );
$post( 'pdf/templates/save', [ 'key' => 'erechnung', 'isNew' => true, 'name' => 'E-Rechnung', 'tag' => 'invoice', 'blocks' => [
	[ 'type' => 'slot', 'name' => 'address' ], [ 'type' => 'input', 'name' => 'einleitung', 'label' => 'Einleitung', 'multiline' => true, 'required' => false ], [ 'type' => 'slot', 'name' => 'lines' ], [ 'type' => 'slot', 'name' => 'totals' ],
] ] );

foreach( [ 'seller_name' => 'Studio Beispiel', 'seller_street' => 'Weg 1', 'seller_zip' => '10115', 'seller_city' => 'Berlin', 'seller_country' => 'DE', 'seller_contact' => 'Jan Beispiel', 'seller_phone' => '+49 30 123456', 'seller_email' => 'jan@beispiel.example',
	'tax_id' => '12/345/67890', 'vat_id' => 'DE111222333', 'iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX', 'currency' => '€', 'payment_days' => '14', 'vat_mode' => 'small_business' ] as $key => $value )
	Settings::set( $appData, $key, $value );

$biz = \Crm\Clients::add( $appData, [ 'company' => 'Muster & Söhne <KG>', 'name' => 'Mia Muster', 'street' => 'Hauptstr. 5', 'zip' => '50667', 'city' => 'Köln', 'email' => 'rechnung@muster.example', 'vat_id' => 'DE987654321', 'buyer_reference' => 'PO-4711', 'business' => true ] );
$private = \Crm\Clients::add( $appData, [ 'name' => 'Paula Privat', 'street' => 'Gasse 2', 'zip' => '80331', 'city' => 'München', 'email' => 'paula@example.org' ] );
$bare = \Crm\Clients::add( $appData, [ 'company' => 'Ohne Referenz GmbH', 'street' => 'Allee 9', 'zip' => '70173', 'city' => 'Stuttgart', 'email' => 'bar@example.org', 'business' => true ] );

check( 'a client is a business or it is not: the flag is stored, the buyer reference is a text of up to 100 characters, and a flag that is no boolean is refused', $biz['business'] === true && $biz['buyer_reference'] === 'PO-4711' && $private['business'] === false && $private['buyer_reference'] === ''
	&& \Crm\Clients::change( $appData, $private['id'], [ 'business' => 'yes' ] ) === 'format:business' && \Crm\Clients::change( $appData, $private['id'], [ 'buyer_reference' => str_repeat( 'x', 101 ) ] ) === 'long:buyer_reference'
	&& \Crm\Clients::get( $appData, $private['id'] )['business'] === false && \Crm\Clients::change( $appData, $biz['id'], [ 'city' => 'Köln' ] )['business'] === true );

$hour = $line( [ 'title' => 'Webdesign <b>&</b> Beratung', 'description' => "Seiten für\n\"Muster\" & Co", 'unit_code' => 'HUR' ] );
$month = $line( [ 'title' => 'Hosting', 'unit' => 'Monat', 'qty' => '1', 'price' => '12,00', 'unit_code' => 'MON' ] );
$mk = static function( string $category, int $clientId, array $input ) use ( &$appData ): array {
	$draft = Documents::add( $appData, $category, $clientId, $input + [ 'template_key' => 'erechnung' ] );
	$done = is_array( $draft ) === true ? Documents::finalise( $appData, $draft['id'] ) : $draft;

	return is_array( $done ) === true ? $done : [ 'id' => 0, 'error' => $done ];
};
$xmlDir = static fn( array $document ): string => \Nino\Filesystem::path( $appData, (string) $document['xml_path'] );
$dom = static function( array $document ) use ( &$appData ): \DOMXPath {
	$doc = new \DOMDocument();
	$doc->load( \Nino\Filesystem::path( $appData, (string) $document['xml_path'] ) );
	$x = new \DOMXPath( $doc );
	$x->registerNamespace( 'rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' );
	$x->registerNamespace( 'ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100' );
	$x->registerNamespace( 'udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100' );
	$x->registerNamespace( 'qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100' );

	return $x;
};
$one = static function( \DOMXPath $x, string $query ): ?string {
	$nodes = $x->query( $query );

	return $nodes === false || $nodes->length === 0 ? null : $nodes->item( 0 )->nodeValue;
};
$all = static function( \DOMXPath $x, string $query ): array {
	$nodes = $x->query( $query );
	$values = [];

	foreach( $nodes === false ? [] : $nodes as $node )
		$values[] = (string) $node->nodeValue;

	return $values;
};
$cents = static fn( string $amount ): int => (int) round( (float) $amount * 100 );
$tr = '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction';
$hd = '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument';
$ag = $tr. '/ram:ApplicableHeaderTradeAgreement';
$st = $tr. '/ram:ApplicableHeaderTradeSettlement';
$li = $tr. '/ram:IncludedSupplyChainTradeLineItem';
$bg23 = $st. '/ram:ApplicableTradeTax';
$sum = $st. '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
$lineTotal = $li. '/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount';

// (a) a small business invoice of a business client, with its note and its due day
$a = $mk( 'invoice', $biz['id'], [ 'date' => '2026-03-10', 'lines' => [ $hour, $month ], 'inputs' => [ 'einleitung' => 'Vielen Dank für den Auftrag.' ] ] );
check( '(a) a small business invoice of a business client is finalised with a file beside the PDF: <number>.xml in the same directory, its path and SHA-256 stored, nothing missing', $a['state'] === 'open' && $a['xml_path'] === substr( $a['pdf_path'], 0, -4 ). '.xml' && basename( $a['xml_path'] ) === $a['number']. '.xml'
	&& is_file( $xmlDir( $a ) ) === true && $a['xml_sha256'] === hash_file( 'sha256', $xmlDir( $a ) ) && $a['xml_lacks'] === [] && is_file( \Nino\Filesystem::path( $appData, $a['pdf_path'] ) ) === true );
$doc = new \DOMDocument();
check( '...the file is well-formed XML in UTF-8 with the root of the Cross Industry Invoice, indented', $doc->load( $xmlDir( $a ) ) === true && $doc->documentElement->namespaceURI === 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' && $doc->documentElement->localName === 'CrossIndustryInvoice'
	&& $doc->encoding === 'UTF-8' && str_contains( (string) file_get_contents( $xmlDir( $a ) ), "\n  <rsm:ExchangedDocumentContext>" ) === true );
$x = $dom( $a );
$ctx = '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext';
check( '...the guideline and the business process (BT-24, BT-23) are the two constants - XRechnung 3.0, and nothing else', $one( $x, $ctx. '/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ) === 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0'
	&& $one( $x, $ctx. '/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ) === ( new \ReflectionClass( XRechnung::class ) )->getConstant( 'GUIDELINE' ) && $one( $x, $ctx. '/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID' ) === 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0' && ( new \ReflectionClass( XRechnung::class ) )->getConstant( 'PROCESS' ) === 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0' );
check( '...BT-1 the number, BT-2 the date in format 102, BT-3 380 for an invoice, BT-22 the note of the document', $one( $x, $hd. '/ram:ID' ) === $a['number'] && $one( $x, $hd. '/ram:IssueDateTime/udt:DateTimeString' ) === '20260310' && $one( $x, $hd. '/ram:IssueDateTime/udt:DateTimeString/@format' ) === '102'
	&& $one( $x, $hd. '/ram:TypeCode' ) === '380' && $one( $x, $hd. '/ram:IncludedNote/ram:Content' ) === 'Vielen Dank für den Auftrag.' );
check( '...BT-10 the buyer reference is the client\'s (BR-DE-15), BT-27 the seller\'s name, BT-35..40 its address with the country', $one( $x, $ag. '/ram:BuyerReference' ) === 'PO-4711' && $one( $x, $ag. '/ram:SellerTradeParty/ram:Name' ) === 'Studio Beispiel'
	&& $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ) === '10115' && $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne' ) === 'Weg 1'
	&& $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName' ) === 'Berlin' && $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID' ) === 'DE' );
check( '...BG-6 the contact (BR-DE-2, -5, -6, -7): name, phone, e-mail; BT-34 the electronic address, scheme EM; BT-31 the VAT id (scheme VA, BR-CO-26) and BT-32 the tax number (scheme FC)', $one( $x, $ag. '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:PersonName' ) === 'Jan Beispiel'
	&& $one( $x, $ag. '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber' ) === '+49 30 123456' && $one( $x, $ag. '/ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID' ) === 'jan@beispiel.example'
	&& $one( $x, $ag. '/ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID' ) === 'jan@beispiel.example' && $one( $x, $ag. '/ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID' ) === 'EM'
	&& $all( $x, $ag. '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' ) === [ 'VA', 'FC' ] && $all( $x, $ag. '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID' ) === [ 'DE111222333', '12/345/67890' ] );
check( '...BG-7 the buyer: the company before the name (escaped), the address, BT-48 the VAT id with the scheme VA, BT-49 the e-mail with the scheme EM', $one( $x, $ag. '/ram:BuyerTradeParty/ram:Name' ) === 'Muster & Söhne <KG>' && str_contains( (string) file_get_contents( $xmlDir( $a ) ), 'Muster &amp; Söhne &lt;KG&gt;' ) === true
	&& $one( $x, $ag. '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ) === '50667' && $one( $x, $ag. '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName' ) === 'Köln' && $one( $x, $ag. '/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID' ) === 'DE'
	&& $one( $x, $ag. '/ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID' ) === 'DE987654321' && $one( $x, $ag. '/ram:BuyerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' ) === 'VA'
	&& $one( $x, $ag. '/ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID' ) === 'rechnung@muster.example' && $one( $x, $ag. '/ram:BuyerTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID' ) === 'EM' );
check( '...a document without a period has BT-72 = the date of the document and no BG-14', $one( $x, $tr. '/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString' ) === '20260310'
	&& $one( $x, $tr. '/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString/@format' ) === '102' && $x->query( $st. '/ram:BillingSpecifiedPeriod' )->length === 0 );
check( '...BT-5 the currency code of the symbol, BG-16 the payment means 58 (BR-DE-1) with the IBAN without blanks, the BIC and the name of the account, BT-83 the number', $one( $x, $st. '/ram:InvoiceCurrencyCode' ) === 'EUR' && $one( $x, $st. '/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode' ) === '58'
	&& $one( $x, $st. '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID' ) === 'DE89370400440532013000' && $one( $x, $st. '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:AccountName' ) === 'Studio Beispiel'
	&& $one( $x, $st. '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID' ) === 'COBADEFFXXX' && $one( $x, $st. '/ram:PaymentReference' ) === $a['number'] );
check( '...BT-9 the due day in format 102 and BT-20 always the sentence of the PDF (BR-CO-25)', $one( $x, $st. '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString' ) === '20260324' && $one( $x, $st. '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString/@format' ) === '102'
	&& $one( $x, $st. '/ram:SpecifiedTradePaymentTerms/ram:Description' ) === Render::dueSentence( '2026-03-24', $a['number'] ) );
check( 'BG-25 the lines: the position, the name (escaped), the description, the quantity with up to three decimals and its unit code, the net price and the amount of the line with two decimals', $all( $x, $li. '/ram:AssociatedDocumentLineDocument/ram:LineID' ) === [ '1', '2' ]
	&& $all( $x, $li. '/ram:SpecifiedTradeProduct/ram:Name' ) === [ 'Webdesign <b>&</b> Beratung', 'Hosting' ] && $all( $x, $li. '/ram:SpecifiedTradeProduct/ram:Description' ) === [ "Seiten für\n\"Muster\" & Co" ]
	&& $all( $x, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity' ) === [ '2.5', '1' ] && $all( $x, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode' ) === [ 'HUR', 'MON' ]
	&& $all( $x, $li. '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount' ) === [ '90.00', '12.00' ] && $all( $x, $lineTotal ) === [ '225.00', '12.00' ] );
check( '...every line of a small business invoice has the category E with the rate 0 (BR-E-05)', $all( $x, $li. '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ) === [ 'E', 'E' ] && $all( $x, $li. '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' ) === [ '0.00', '0.00' ]
	&& $all( $x, $li. '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:TypeCode' ) === [ 'VAT', 'VAT' ] );
check( 'BG-23 one breakdown E with the exemption text of the settings (BR-E-10, BT-120), the basis equal to the net, the tax 0.00, the rate 0.00', $x->query( $bg23 )->length === 1 && $one( $x, $bg23. '/ram:CategoryCode' ) === 'E' && $one( $x, $bg23. '/ram:ExemptionReason' ) === Settings::get( $appData, 'vat_exempt_text' )
	&& $one( $x, $bg23. '/ram:BasisAmount' ) === '237.00' && $one( $x, $bg23. '/ram:CalculatedAmount' ) === '0.00' && $one( $x, $bg23. '/ram:RateApplicablePercent' ) === '0.00' && $a['net'] === 23700 );
check( 'BG-22 the totals are those of Money::totals(): BT-106 = BT-109 = the net, BT-110 the VAT with the currency, BT-112 = BT-115 the gross, BT-113 0.00', $one( $x, $sum. '/ram:LineTotalAmount' ) === '237.00' && $one( $x, $sum. '/ram:TaxBasisTotalAmount' ) === '237.00' && $one( $x, $sum. '/ram:TaxTotalAmount' ) === '0.00'
	&& $one( $x, $sum. '/ram:TaxTotalAmount/@currencyID' ) === 'EUR' && $one( $x, $sum. '/ram:GrandTotalAmount' ) === '237.00' && $one( $x, $sum. '/ram:TotalPrepaidAmount' ) === '0.00' && $one( $x, $sum. '/ram:DuePayableAmount' ) === '237.00' );

// The business rules every file is held to, by structure and by value
$rules = static function( \DOMXPath $x, string $label ) use ( $one, $all, $cents, $st, $ag, $li, $sum, $bg23, $lineTotal ): bool {
	$count = static fn( string $query ): int => $x->query( $query )->length;
	$ok = $count( $st. '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID' ) === 1;
	$ok = $ok && $count( $ag. '/ram:SellerTradeParty/ram:DefinedTradeContact' ) === 1;
	$ok = $ok && trim( (string) $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName' ) ) !== '' && trim( (string) $one( $x, $ag. '/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode' ) ) !== '';
	foreach( [ 'ram:PersonName', 'ram:TelephoneUniversalCommunication/ram:CompleteNumber', 'ram:EmailURIUniversalCommunication/ram:URIID' ] as $part )
		$ok = $ok && trim( (string) $one( $x, $ag. '/ram:SellerTradeParty/ram:DefinedTradeContact/'. $part ) ) !== '';
	$ok = $ok && trim( (string) $one( $x, $ag. '/ram:BuyerReference' ) ) !== '';
	$ok = $ok && $one( $x, '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' ) === XRechnung::GUIDELINE;
	$due = $one( $x, $st. '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString' );
	$ok = $ok && ( $due !== null || trim( (string) $one( $x, $st. '/ram:SpecifiedTradePaymentTerms/ram:Description' ) ) !== '' );
	$ok = $ok && array_filter( $all( $x, $li. '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount' ), static fn( string $price ): bool => $cents( $price ) < 0 ) === [];
	$ok = $ok && array_filter( $all( $x, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode' ), static fn( string $code ): bool => $code === '' ) === [];
	$lines = array_sum( array_map( $cents, $all( $x, $lineTotal ) ) );
	$ok = $ok && $cents( (string) $one( $x, $sum. '/ram:LineTotalAmount' ) ) === $lines && $cents( (string) $one( $x, $sum. '/ram:TaxBasisTotalAmount' ) ) === $lines;
	$bases = array_map( $cents, $all( $x, $bg23. '/ram:BasisAmount' ) );
	$rates = array_map( $cents, $all( $x, $bg23. '/ram:RateApplicablePercent' ) );
	$taxes = array_map( $cents, $all( $x, $bg23. '/ram:CalculatedAmount' ) );
	$total = 0;
	foreach( $bases as $index => $basis ) {
		$ok = $ok && $taxes[$index] === Money::vat( $basis, $rates[$index] );
		$total += $taxes[$index];
	}
	$ok = $ok && $total === $cents( (string) $one( $x, $sum. '/ram:TaxTotalAmount' ) ) && array_sum( $bases ) === $lines;
	$ok = $ok && $cents( (string) $one( $x, $sum. '/ram:GrandTotalAmount' ) ) === $lines + $total && $cents( (string) $one( $x, $sum. '/ram:DuePayableAmount' ) ) === $cents( (string) $one( $x, $sum. '/ram:GrandTotalAmount' ) ) - $cents( (string) $one( $x, $sum. '/ram:TotalPrepaidAmount' ) );

	foreach( $all( $x, $bg23. '/ram:CategoryCode' ) as $index => $category )
		$ok = $ok && ( $category === 'E' ? trim( (string) $one( $x, '('. $bg23. ')['. ( $index + 1 ). ']/ram:ExemptionReason' ) ) !== '' && $rates[$index] === 0 : ( $category === 'S' && $rates[$index] > 0 ) );

	check( 'the business rules of '. $label. ' hold: BR-DE-1, -2, -3, -4, -5, -6, -7, -15, -21, BR-CO-25, BR-27, BR-CO-10, -13, -14, -15, -17, BR-E-10, BR-S and the unit code of every line', $ok === true );

	return $ok;
};
$rules( $x, 'fixture (a)' );
$sameBytes = XRechnung::build( $appData, Documents::get( $appData, $a['id'] ) );
check( 'the file is deterministic: made again from the finalised document it is the same bytes, with the same hash', $sameBytes === file_get_contents( $xmlDir( $a ) ) && hash( 'sha256', $sameBytes ) === $a['xml_sha256'] && XRechnung::build( $appData, Documents::get( $appData, $a['id'] ) ) === $sameBytes );

// (b) a regular invoice with 19 % and 7 %, a period, a reference of its own, the VAT id of the seller
foreach( [ 'vat_mode' => 'regular', 'vat_id' => 'DE111222333' ] as $key => $value )
	Settings::set( $appData, $key, $value );

$full = $line( [ 'title' => 'Mixing', 'qty' => '3', 'price' => '33,33', 'vat_rate' => 1900, 'unit_code' => 'H87' ] );
$book = $line( [ 'title' => 'Buch', 'qty' => '2', 'price' => '17,95', 'vat_rate' => 700, 'unit_code' => 'H87' ] );
$more = $line( [ 'title' => 'Master', 'qty' => '1', 'price' => '100', 'vat_rate' => 1900, 'unit_code' => 'LS' ] );
$b = $mk( 'invoice', $biz['id'], [ 'date' => '2026-04-02', 'lines' => [ $full, $book, $more ], 'period_from' => '2026-04-01', 'period_to' => '2026-04-30', 'buyer_reference' => 'Bestellung 99' ] );
$xb = $dom( $b );
$totalsB = Money::totals( $b['lines'], 'regular', false );
check( '(b) a regular invoice is finalised with a file as well; BT-10 is the reference of the document, which comes before the client\'s; BT-31 the VAT id and BT-32 the tax number', $b['xml_path'] !== '' && $b['xml_lacks'] === [] && $one( $xb, $ag. '/ram:BuyerReference' ) === 'Bestellung 99'
	&& $all( $xb, $ag. '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID/@schemeID' ) === [ 'VA', 'FC' ] && $all( $xb, $ag. '/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID' ) === [ 'DE111222333', '12/345/67890' ] && $b['buyer_reference'] === 'Bestellung 99' );
check( '...BG-14 the period in format 102, and then no BT-72', $one( $xb, $st. '/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString' ) === '20260401' && $one( $xb, $st. '/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString' ) === '20260430'
	&& $one( $xb, $st. '/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString/@format' ) === '102' && $xb->query( $tr. '/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent' )->length === 0 );
check( 'BG-23 is grouped by category and rate: S 19.00 and S 7.00, each with the basis and the tax Money::totals() has for the rate, and no exemption text', $all( $xb, $bg23. '/ram:CategoryCode' ) === [ 'S', 'S' ] && $all( $xb, $bg23. '/ram:RateApplicablePercent' ) === [ '19.00', '7.00' ]
	&& array_map( $cents, $all( $xb, $bg23. '/ram:BasisAmount' ) ) === [ $totalsB['rates'][1900]['net'], $totalsB['rates'][700]['net'] ] && array_map( $cents, $all( $xb, $bg23. '/ram:CalculatedAmount' ) ) === [ $totalsB['rates'][1900]['vat'], $totalsB['rates'][700]['vat'] ]
	&& $xb->query( $bg23. '/ram:ExemptionReason' )->length === 0 && $all( $xb, $li. '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode' ) === [ 'S', 'S', 'S' ]
	&& $all( $xb, $li. '/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent' ) === [ '19.00', '7.00', '19.00' ] );
check( '...the totals are those of Money::totals(): net, VAT, gross', $cents( (string) $one( $xb, $sum. '/ram:LineTotalAmount' ) ) === $totalsB['net'] && $cents( (string) $one( $xb, $sum. '/ram:TaxTotalAmount' ) ) === $totalsB['vat'] && $cents( (string) $one( $xb, $sum. '/ram:GrandTotalAmount' ) ) === $totalsB['gross']
	&& $totalsB['net'] === $b['net'] && $totalsB['vat'] === $b['vat'] && $totalsB['gross'] === $b['gross'] );
$rules( $xb, 'fixture (b)' );

// (c) the credit note of (b)
$draftC = Documents::creditOf( $appData, $b['id'] );
Documents::change( $appData, $draftC['id'], [ 'template_key' => 'erechnung' ] );
$c = Documents::finalise( $appData, $draftC['id'] );
$xc = $dom( $c );
check( '(c) a credit note (Storno) is finalised with a file: BT-3 is 381, the buyer reference is the invoice\'s, and it names the invoice it takes back (BG-3: BT-25 the number, BT-26 the date in format 102)', is_array( $c ) === true && $c['xml_path'] !== '' && $one( $xc, $hd. '/ram:TypeCode' ) === '381' && $one( $xc, $ag. '/ram:BuyerReference' ) === 'Bestellung 99'
	&& $one( $xc, $st. '/ram:InvoiceReferencedDocument/ram:IssuerAssignedID' ) === $b['number'] && $one( $xc, $st. '/ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString' ) === '20260402' && $one( $xc, $st. '/ram:InvoiceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString/@format' ) === '102' );
check( '...its amounts are positive, though the document stores them negated: lines, breakdowns and totals are those of the invoice, the quantities positive', $c['gross'] === -$b['gross'] && $c['net'] === -$b['net']
	&& str_contains( implode( '', $all( $xc, '//ram:ChargeAmount | //ram:LineTotalAmount | //ram:BasisAmount | //ram:CalculatedAmount | //ram:TaxBasisTotalAmount | //ram:TaxTotalAmount | //ram:GrandTotalAmount | //ram:DuePayableAmount | //ram:BilledQuantity' ) ), '-' ) === false
	&& $all( $xc, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity' ) === [ '3', '2', '1' ] && $all( $xc, $bg23. '/ram:BasisAmount' ) === $all( $xb, $bg23. '/ram:BasisAmount' ) && $all( $xc, $bg23. '/ram:CalculatedAmount' ) === $all( $xb, $bg23. '/ram:CalculatedAmount' )
	&& $one( $xc, $sum. '/ram:GrandTotalAmount' ) === $one( $xb, $sum. '/ram:GrandTotalAmount' ) && $one( $xc, $sum. '/ram:TaxTotalAmount' ) === $one( $xb, $sum. '/ram:TaxTotalAmount' ) );
check( '...the file and the database agree on the amount of a credit note: the grand total of the file is the stored gross with its sign taken off, to the cent', $c['gross'] < 0 && $cents( (string) $one( $xc, $sum. '/ram:GrandTotalAmount' ) ) === -$c['gross'] );
check( '...a credit note has no due day, and BT-20 all the same (BR-CO-25)', $xc->query( $st. '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime' )->length === 0 && $one( $xc, $st. '/ram:SpecifiedTradePaymentTerms/ram:Description' ) === Render::dueSentence( '', $c['number'] ) );
$rules( $xc, 'fixture (c)' );

// (d) the credit note that closes an invoice whose tax the lines alone would round the other way: the file and the view carry the tax of the document
$pairLine = static fn( string $qty ): array => $line( [ 'title' => 'Paar', 'qty' => $qty, 'price' => '10,50', 'vat_rate' => 1900, 'unit_code' => 'H87' ] );
$pairInvoice = $mk( 'invoice', $biz['id'], [ 'date' => '2026-04-03', 'lines' => [ $pairLine( '2' ) ], 'buyer_reference' => 'Bestellung 98' ] );
$halves = [];

foreach( [ 0, 1 ] as $round ) {
	$halfDraft = (array) Documents::creditOf( $appData, $pairInvoice['id'] );
	Documents::change( $appData, $halfDraft['id'], [ 'template_key' => 'erechnung', 'lines' => [ $pairLine( '-1' ) ] ] );
	$halves[] = (array) Documents::finalise( $appData, $halfDraft['id'] );
}

$xd = $dom( $halves[1] );
$pdfTotals = (string) Render::data( $appData, $halves[1], $halves[1]['lines'], $halves[1]['client_frozen'] )['slots']['totals'];
$currency = Settings::get( $appData, 'currency' );
check( '(d) the electronic invoice of the credit note that closes 2 x 10,50 at 19 % has the tax of the document: the total tax and the tax of the rate are 1,99 (the lines alone round to 2,00) and the grand total is the gross with its sign taken off', $halves[1]['vat'] === -199 && $halves[0]['vat'] === -200 && $cents( (string) $one( $xd, $sum. '/ram:TaxTotalAmount' ) ) === 199
	&& array_map( $cents, $all( $xd, $bg23. '/ram:CalculatedAmount' ) ) === [ 199 ] && $cents( (string) $one( $xd, $sum. '/ram:GrandTotalAmount' ) ) === -$halves[1]['gross'] && $halves[1]['xml_path'] !== '' );
check( '...so has the data the PDF is drawn from: the tax of the document, not the tax of its lines', str_contains( $pdfTotals, str_replace( "\u{00A0}", '&nbsp;', Money::format( $halves[1]['vat'], $currency ) ) ) === true && str_contains( $pdfTotals, str_replace( "\u{00A0}", '&nbsp;', Money::format( -200, $currency ) ) ) === false );
$shownRates = Documents::get( $appData, $halves[1]['id'] )['rates'];
check( '...and the document as get() has it: the rates carry the net and the tax of each rate and add up to the net and the tax of the document - a draft and what is no credit note have none', $shownRates === [ [ 'rate' => 1900, 'net' => -1050, 'vat' => -199 ] ] && array_sum( array_column( $shownRates, 'net' ) ) === $halves[1]['net'] && array_sum( array_column( $shownRates, 'vat' ) ) === $halves[1]['vat']
	&& Documents::get( $appData, $pairInvoice['id'] )['rates'] === [] );

// (f) a negative price: a discount line
Settings::set( $appData, 'vat_mode', 'small_business' );
$f = $mk( 'invoice', $biz['id'], [ 'date' => '2026-05-04', 'lines' => [ $line( [ 'title' => 'Konzept', 'qty' => '1', 'price' => '100', 'unit_code' => 'LS' ] ), $line( [ 'title' => 'Rabatt', 'qty' => '2', 'price' => '-10', 'unit_code' => 'C62' ] ) ] ] );
$xf = $dom( $f );
check( '(f) a line with a negative price becomes a negated quantity with a positive price (BR-27), and its amount stays negative: -2 x 10.00 = -20.00', $all( $xf, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity' ) === [ '1', '-2' ] && $all( $xf, $li. '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount' ) === [ '100.00', '10.00' ]
	&& $all( $xf, $lineTotal ) === [ '100.00', '-20.00' ] && $one( $xf, $sum. '/ram:LineTotalAmount' ) === '80.00' && $f['net'] === 8000 );
$rules( $xf, 'fixture (f)' );
$draftFC = Documents::creditOf( $appData, $f['id'] );
Documents::change( $appData, $draftFC['id'], [ 'template_key' => 'erechnung' ] );
$fc = Documents::finalise( $appData, $draftFC['id'] );
$xfc = $dom( $fc );
check( '...the credit note of it has the same lines and the totals positive: the signs of the credit note and of the discount cancel each other where the quantity is concerned', $one( $xfc, $hd. '/ram:TypeCode' ) === '381' && $all( $xfc, $li. '/ram:SpecifiedLineTradeDelivery/ram:BilledQuantity' ) === [ '1', '-2' ]
	&& $all( $xfc, $li. '/ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount' ) === [ '100.00', '10.00' ] && $all( $xfc, $lineTotal ) === [ '100.00', '-20.00' ] && $one( $xfc, $sum. '/ram:GrandTotalAmount' ) === '80.00' && $fc['gross'] === -8000 );
$rules( $xfc, 'the credit note of fixture (f)' );

// (g) an invoice without a due day: BT-20 alone
$g = $mk( 'invoice', $biz['id'], [ 'date' => '2026-06-01', 'due_date' => '', 'lines' => [ $hour, $line( [ 'title' => 'Bell', 'description' => "Zeile\x07 mit\x0B Steuerzeichen", 'unit_code' => 'H87' ] ) ] ] );
$xg = $dom( $g );
check( '(g) an invoice without a due day has no BT-9, and BT-20 is the sentence "sofort": BR-CO-25 is kept by the terms alone', $g['due_date'] === null && $xg->query( $st. '/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime' )->length === 0
	&& $one( $xg, $st. '/ram:SpecifiedTradePaymentTerms/ram:Description' ) === Render::dueSentence( '', $g['number'] ) && str_contains( (string) $one( $xg, $st. '/ram:SpecifiedTradePaymentTerms/ram:Description' ), $g['number'] ) === true );
check( '...a control character a description may hold is left out of the file, which stays well-formed: XML 1.0 cannot carry it', $xg->document->documentElement !== null && $all( $xg, $li. '/ram:SpecifiedTradeProduct/ram:Description' )[1] === 'Zeile mit Steuerzeichen' );
$rules( $xg, 'fixture (g)' );
Settings::set( $appData, 'bic', '' );
$noBic = new \DOMDocument();
$noBic->loadXML( XRechnung::build( $appData, (array) Documents::get( $appData, $g['id'] ) ) );
Settings::set( $appData, 'bic', 'COBADEFFXXX' );
check( 'the BIC is optional: an invoice made while the setting is empty has the IBAN and no institution at all (no empty BT-86)', $noBic->getElementsByTagName( 'IBANID' )->length === 1 && $noBic->getElementsByTagName( 'BICID' )->length === 0 && $noBic->getElementsByTagName( 'PayeeSpecifiedCreditorFinancialInstitution' )->length === 0 );

// A file of an electronic invoice that is in the way (a database restored without its files): nothing is finalised, and the PDF that was written for it is taken back
$wayDraft = Documents::add( $appData, 'invoice', $biz['id'], [ 'date' => '2026-06-15', 'lines' => [ $hour ], 'template_key' => 'erechnung' ] );
$wayNumber = Numbers::upcoming( $appData, 'invoice', 2026, Settings::get( $appData, 'number_invoice' ) );
$wayPath = Render::DIR. '/2026/'. $wayNumber. '.xml';
file_put_contents( \Nino\Filesystem::path( $appData, $wayPath ), 'fremd' );
$sequenceBefore = Db::rows( $appData, 'SELECT category, year, next FROM sequences ORDER BY category, year' );
check( 'a file of an electronic invoice that is there already stops the finalising: exists:<path> with the .xml path, the PDF written before it is taken back, no number is spent, the draft is as it was - and the foreign file is left alone', Documents::finalise( $appData, $wayDraft['id'] ) === 'exists:'. $wayPath
	&& is_file( \Nino\Filesystem::path( $appData, Render::DIR. '/2026/'. $wayNumber. '.pdf' ) ) === false && Db::rows( $appData, 'SELECT category, year, next FROM sequences ORDER BY category, year' ) === $sequenceBefore && Documents::get( $appData, $wayDraft['id'] )['state'] === 'draft'
	&& file_get_contents( \Nino\Filesystem::path( $appData, $wayPath ) ) === 'fremd' );
[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $wayDraft['id'] ] );
check( '...the API says it in its own sentence, with the number and the path: 409 crm_documents_exists_xml', $status === 409 && $body['code'] === 'crm_documents_exists_xml' && $body['params'] === [ $wayNumber, ltrim( $wayPath, '/' ) ] );
unlink( \Nino\Filesystem::path( $appData, $wayPath ) );
$wayDone = Documents::finalise( $appData, $wayDraft['id'] );
check( '...and with the file out of the way the same draft is finalised, with the number it would have had', is_array( $wayDone ) === true && $wayDone['number'] === $wayNumber && $wayDone['xml_path'] === $wayPath );

// (d) an invoice of a private client: no file, no list
$d = $mk( 'invoice', $private['id'], [ 'date' => '2026-03-11', 'lines' => [ $hour ] ] );
check( '(d) an invoice of a private client is finalised without a file and without a list: nothing is written beside the PDF, nothing is said to be missing', $d['state'] === 'open' && $d['xml_path'] === '' && $d['xml_sha256'] === '' && $d['xml_lacks'] === [] && $d['client_frozen']['business'] === '0'
	&& glob( dirname( \Nino\Filesystem::path( $appData, $d['pdf_path'] ) ). '/'. $d['number']. '.xml' ) === [] && XRechnung::applies( $d ) === false && XRechnung::lacks( $appData, $d ) === [] );

// (e) a business with no buyer reference and a line without a unit code
$e = $mk( 'invoice', $bare['id'], [ 'date' => '2026-03-12', 'lines' => [ $hour, $line( [ 'title' => 'Ohne Einheit' ] ) ] ] );
check( '(e) a business client with no buyer reference and a line without a unit code: the document is finalised all the same - number, PDF - with no file, and the list of what was missing is stored', $e['state'] === 'open' && $e['number'] !== '' && $e['pdf_path'] !== '' && $e['xml_path'] === '' && $e['xml_sha256'] === ''
	&& $e['xml_lacks'] === [ 'buyer_reference', 'line_unit' ] && glob( dirname( \Nino\Filesystem::path( $appData, $e['pdf_path'] ) ). '/'. $e['number']. '.xml' ) === [] );
$ignored = ninoWarnings();

// A finalised document never gets a file later
Db::exec( $appData, 'UPDATE clients SET business = 1, buyer_reference = \'X-1\' WHERE id = :id', [ 'id' => $private['id'] ] );
Db::exec( $appData, 'UPDATE document_lines SET unit_code = \'HUR\' WHERE document_id = :id', [ 'id' => $e['id'] ] );
Db::exec( $appData, 'UPDATE clients SET buyer_reference = \'Z-9\' WHERE id = :id', [ 'id' => $bare['id'] ] );
$eSent = Documents::setState( $appData, $e['id'], 'sent' );
$eReleased = Documents::release( $appData, $e['id'], false );
$eNotes = Documents::setNotes( $appData, $e['id'], 'x' );
check( 'a finalised document never gets a file later: the client became a business, the missing pieces were filled in - the documents keep what they were finalised with, whatever is done with them (sent, released, noted)', Documents::get( $appData, $d['id'] )['xml_path'] === '' && $eSent['xml_path'] === '' && $eReleased['document']['xml_path'] === ''
	&& $eNotes['xml_path'] === '' && Documents::get( $appData, $e['id'] )['xml_lacks'] === [ 'buyer_reference', 'line_unit' ] && glob( dirname( \Nino\Filesystem::path( $appData, $e['pdf_path'] ) ). '/'. $e['number']. '.xml' ) === [] );
Db::exec( $appData, 'UPDATE clients SET business = 0, buyer_reference = \'\' WHERE id = :id', [ 'id' => $private['id'] ] );
Documents::unrelease( $appData, $e['id'] );

// An offer of a business client gets nothing
$offerX = Documents::add( $appData, 'offer', $biz['id'], [ 'lines' => [ $hour ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
$offerX = Documents::finalise( $appData, $offerX['id'] );
check( 'an offer of a business client gets no file and no list: it is no invoice, and its buyer reference stays empty', $offerX['xml_path'] === '' && $offerX['xml_lacks'] === [] && $offerX['buyer_reference'] === '' && XRechnung::applies( $offerX ) === false && XRechnung::lacks( $appData, $offerX ) === [] );

// The checklist: each item by itself
Settings::set( $appData, 'vat_mode', 'small_business' );
Settings::set( $appData, 'vat_id', 'DE111222333' );
$probe = Documents::add( $appData, 'invoice', $biz['id'], [ 'date' => '2026-07-01', 'lines' => [ $hour, $month ], 'template_key' => 'erechnung' ] );
check( 'the checklist of a complete draft is empty - and a draft of a business client is checked the way a finalised one is', XRechnung::lacks( $appData, $probe ) === [] && XRechnung::applies( $probe ) === true && $probe['buyer_reference'] === 'PO-4711' );
$settingsBefore = Settings::all( $appData );
$wrong = [];

foreach( [ 'seller_name' => 'seller_name', 'seller_street' => 'seller_street', 'seller_zip' => 'seller_zip', 'seller_city' => 'seller_city', 'seller_country' => 'seller_country', 'seller_contact' => 'seller_contact', 'seller_phone' => 'seller_phone',
	'seller_email' => 'seller_email', 'iban' => 'iban', 'exempt_text' => 'vat_exempt_text' ] as $key => $setting ) {
	Settings::set( $appData, $setting, '' );
	$wrong[$key] = XRechnung::lacks( $appData, $probe ) === [ $key ];
	Settings::set( $appData, $setting, $settingsBefore[$setting] );
}

check( 'each setting the file needs is an item of the checklist when it is empty, alone: the name, the street, the postcode, the town, the country, the contact, the phone, the e-mail, the IBAN and the text of the exemption: '. count( $wrong ), array_filter( $wrong, static fn( bool $ok ): bool => $ok === false ) === [] );
Settings::set( $appData, 'vat_id', '' );
$noVat = XRechnung::lacks( $appData, $probe );
Settings::set( $appData, 'vat_id', 'DE111222333' );
Settings::set( $appData, 'tax_id', '' );
$noTax = XRechnung::lacks( $appData, $probe );
Settings::set( $appData, 'tax_id', '12/345/67890' );
$noTaxXml = XRechnung::build( $appData, (array) Documents::get( $appData, $probe['id'] ) );
check( 'the VAT id is required (BR-CO-26: the tax number alone is no seller identifier): without it the checklist has seller_vat_id, alone; with it the item is gone - and the tax number is optional', $noVat === [ 'seller_vat_id' ] && $noTax === [] && XRechnung::lacks( $appData, $probe ) === [] && str_contains( XRechnung::text( 'seller_vat_id' ), 'BR-CO-26' ) === true );
Settings::set( $appData, 'currency', 'kr' );
$kr = XRechnung::lacks( $appData, $probe );
$codes = [];

foreach( [ '€' => 'EUR', 'CHF' => 'CHF', '$' => 'USD', '£' => 'GBP' ] as $symbol => $code ) {
	Settings::set( $appData, 'currency', $symbol );
	$codes[$symbol] = XRechnung::CURRENCIES[$symbol] === $code && XRechnung::lacks( $appData, $probe ) === [];
}

Settings::set( $appData, 'currency', '€' );
check( 'the currency is mapped from its symbol to the code of ISO 4217 - € EUR, CHF CHF, $ USD, £ GBP - and another symbol is an item of the checklist', $kr === [ 'currency' ] && array_filter( $codes, static fn( bool $ok ): bool => $ok === false ) === [] );
$flip = static function( array $over ) use ( &$appData, $probe, $biz ): array {
	Db::exec( $appData, 'UPDATE clients SET company = :company, name = :name, street = :street, zip = :zip, city = :city, country = :country, email = :email, buyer_reference = :ref WHERE id = :id', [
		'company' => $over['company'] ?? 'Muster & Söhne <KG>', 'name' => $over['name'] ?? 'Mia Muster', 'street' => $over['street'] ?? 'Hauptstr. 5', 'zip' => $over['zip'] ?? '50667', 'city' => $over['city'] ?? 'Köln', 'country' => $over['country'] ?? 'DE',
		'email' => $over['email'] ?? 'rechnung@muster.example', 'ref' => $over['ref'] ?? 'PO-4711', 'id' => $biz['id'] ] );
	Db::exec( $appData, 'UPDATE documents SET buyer_reference = :ref WHERE id = :id', [ 'ref' => $over['docref'] ?? '', 'id' => $probe['id'] ] );

	return XRechnung::lacks( $appData, (array) Documents::get( $appData, $probe['id'] ) );
};
$wrong = [];

foreach( [ 'buyer_name' => [ 'company' => '', 'name' => '' ], 'buyer_street' => [ 'street' => '' ], 'buyer_zip' => [ 'zip' => '' ], 'buyer_city' => [ 'city' => '' ], 'buyer_country' => [ 'country' => '' ], 'buyer_reference' => [ 'ref' => '' ], 'buyer_email' => [ 'email' => '' ] ] as $key => $over )
	$wrong[$key] = $flip( $over ) === [ $key ];

check( 'each piece of the client the file needs is an item when it is empty, alone: the name (company and name both), the street, the postcode, the town, the country, the e-mail address and the buyer reference: '. count( $wrong ), array_filter( $wrong, static fn( bool $ok ): bool => $ok === false ) === [] );
check( 'the buyer reference is the document\'s, else the client\'s: either one suffices, and the name is the company or the person', $flip( [ 'ref' => '', 'docref' => 'Nur am Beleg' ] ) === [] && $flip( [ 'ref' => 'Nur am Kunden' ] ) === [] && $flip( [ 'company' => '' ] ) === [] && $flip( [ 'name' => '' ] ) === [] );
$flip( [] );
Db::exec( $appData, 'UPDATE documents SET buyer_reference = \'PO-4711\' WHERE id = :id', [ 'id' => $probe['id'] ] );
$withoutUnit = (array) Documents::change( $appData, $probe['id'], [ 'lines' => [ $hour, $month, $line( [ 'title' => 'Neu' ] ) ] ] );
check( 'a line without a unit code is an item: line_unit', XRechnung::lacks( $appData, $withoutUnit ) === [ 'line_unit' ] );
$noLines = (array) Documents::change( $appData, $probe['id'], [ 'lines' => [] ] );
check( 'a document without lines is an item: lines', XRechnung::lacks( $appData, $noLines ) === [ 'lines' ] );
Settings::set( $appData, 'vat_mode', 'regular' );
$regularProbe = (array) Documents::add( $appData, 'invoice', $biz['id'], [ 'date' => '2026-07-02', 'lines' => [ $hour, $line( [ 'title' => 'Null', 'vat_rate' => 0, 'unit_code' => 'H87' ] ) ] ] );
$withRate = (array) Documents::change( $appData, $regularProbe['id'], [ 'lines' => [ $line( [ 'title' => 'A', 'vat_rate' => 1900, 'unit_code' => 'H87' ] ), $line( [ 'title' => 'B', 'vat_rate' => 700, 'unit_code' => 'H87' ] ) ] ] );
$withReverse = (array) Documents::change( $appData, $regularProbe['id'], [ 'reverse_charge' => true ] );
check( 'under regular taxation a line with the rate 0 is an item (line_rate) and a reverse charge is an item (reverse_charge), alone each', XRechnung::lacks( $appData, $regularProbe ) === [ 'line_rate' ] && XRechnung::lacks( $appData, $withRate ) === [] && XRechnung::lacks( $appData, $withReverse ) === [ 'reverse_charge' ] );
Settings::set( $appData, 'vat_exempt_text', '' );
check( '...the text of the exemption is asked for under small_business only', XRechnung::lacks( $appData, $withRate ) === [] );
Settings::set( $appData, 'vat_exempt_text', $settingsBefore['vat_exempt_text'] );
Settings::set( $appData, 'vat_mode', 'small_business' );
$smallZero = (array) Documents::add( $appData, 'invoice', $biz['id'], [ 'date' => '2026-07-03', 'lines' => [ $hour ] ] );
check( 'under small_business the rate 0 is the rule and no item', XRechnung::lacks( $appData, $smallZero ) === [] );
$private2 = (array) Documents::add( $appData, 'invoice', $private['id'], [ 'date' => '2026-07-04', 'lines' => [ $line() ] ] );
check( 'a draft of a private client has no checklist, though it has a line without a unit code', XRechnung::lacks( $appData, $private2 ) === [] && XRechnung::applies( $private2 ) === false );
check( 'every key of the checklist has its sentence, and the sentences are the keys of the text file: '. count( XRechnung::KEYS ), array_map( static fn( array $one ): string => $one['key'], XRechnung::sentences( XRechnung::KEYS ) ) === XRechnung::KEYS
	&& array_filter( XRechnung::sentences( XRechnung::KEYS ), static fn( array $one ): bool => trim( $one['text'] ) === '' ) === [] && XRechnung::sentences( [ 'unbekannt', 'iban' ] ) === [ [ 'key' => 'iban', 'text' => XRechnung::text( 'iban' ) ] ]
	&& array_keys( (array) require $root. '/app/Crm/Documents/XRechnung/text/de_DE.php' ) === array_map( static fn( string $key ): string => 'lack.'. $key, XRechnung::KEYS ) );

foreach( [ $probe, $regularProbe, $smallZero, $private2 ] as $over )
	Documents::remove( $appData, $over['id'] );

// The route /.crm/xml/<id>, with the permission and the checks of the route of the PDF
echo "\nThe route /.crm/xml/<id>\n";

$xmlUri = '/.crm/xml/'. $a['id'];
$as( '' );
check( 'a visitor is told 404, with a plain body and no file', $nothing( docServe( $appData, $xmlUri ) ) === true && str_starts_with( docServe( $appData, $xmlUri )['header']['Content-Type'], 'text/plain' ) );
$as( 'plain@example.com' );
check( 'an account without the permission is told the same 404', $nothing( docServe( $appData, $xmlUri ) ) === true );
$as( 'other@example.com' );
check( '...and so is one with the permission of another panel', $nothing( docServe( $appData, $xmlUri ) ) === true );
$as( 'docs@example.com' );
$answer = docServe( $appData, $xmlUri );
check( 'an account with the permission of the panel Belege gets the file: 200, application/xml, saved as an attachment under the number', $answer['statusCode'] === 200 && ( $answer['file'] ?? '' ) === $xmlDir( $a ) && $answer['header']['Content-Type'] === 'application/xml'
	&& $answer['header']['Content-Disposition'] === 'attachment; filename="'. $a['number']. '.xml"' && $answer['header']['Cache-Control'] === 'private, no-store' && $answer['header']['X-Content-Type-Options'] === 'nosniff' );
check( '...also without a query: it is never shown, always saved', docServe( $appData, $xmlUri. '?download=0' )['header']['Content-Disposition'] === $answer['header']['Content-Disposition'] );
$as( 'admin@example.com' );
check( 'the owner with every permission gets it as well', docServe( $appData, $xmlUri )['statusCode'] === 200 && hash_file( 'sha256', (string) docServe( $appData, $xmlUri )['file'] ) === $a['xml_sha256'] );
$answers = [];
$draftX = Documents::add( $appData, 'invoice', $biz['id'] )['id'];

foreach( [ 'a document without a file (a private client\'s)' => '/.crm/xml/'. $d['id'], 'a document whose file was not written (a list of what was missing)' => '/.crm/xml/'. $e['id'], 'a draft' => '/.crm/xml/'. $draftX, 'an id nobody has' => '/.crm/xml/99999', 'a word' => '/.crm/xml/abc', 'zero' => '/.crm/xml/0',
	'a leading zero' => '/.crm/xml/01', 'a path behind the id' => $xmlUri. '/x', 'a traversal' => '/.crm/xml/..%2f..%2fetc%2fpasswd', 'a long number' => '/.crm/xml/12345678901' ] as $kind => $path )
	$answers[$kind] = $nothing( docServe( $appData, $path ) );

check( 'a document without a file, a draft, an id nobody has and everything that is no id are the same 404, with no line in the log', array_filter( $answers, static fn( bool $ok ): bool => $ok === false ) === [] && ninoWarnings() === [] );
Documents::remove( $appData, $draftX );

$xmlFile = $xmlDir( $a );
$xmlBytes = (string) file_get_contents( $xmlFile );
file_put_contents( $xmlFile, $xmlBytes. ' ' );
$ignored = ninoWarnings();
$conflict = docServe( $appData, $xmlUri );
check( 'a file whose hash is not the stored one is 409, no file is answered, and one log line names the number', $conflict['statusCode'] === 409 && ( $conflict['file'] ?? null ) === null && $conflict['body'] === 'Conflict' && ninoWarnings() === [ 'Crm: the electronic invoice of document '. $a['number']. ' does not match its hash' ] );
file_put_contents( $xmlFile, $xmlBytes );
check( '...the file as it was is answered again', docServe( $appData, $xmlUri )['statusCode'] === 200 );
rename( $xmlFile, $xmlFile. '.away' );
$missing = docServe( $appData, $xmlUri );
check( 'a file that is gone is 404 with one log line that names the number', $nothing( $missing ) === true && ninoWarnings() === [ 'Crm: the electronic invoice of document '. $a['number']. ' is gone' ] );
rename( $xmlFile. '.away', $xmlFile );

foreach( [ '../../../etc/passwd', '/etc/passwd', '/data/crm/pdf/../../../x.xml', '/data/crm/pdf/2026/x.txt', '/data/crm/pdf/2026/'. $a['number']. '.pdf', '/data/crm/pdf/2026/a/b.xml' ] as $path ) {
	Db::exec( $appData, 'UPDATE documents SET xml_path = :p WHERE id = :id', [ 'p' => $path, 'id' => $a['id'] ] );
	check( 'a stored path the module did not write is not followed: '. $path, $nothing( docServe( $appData, $xmlUri ) ) === true );
}

Db::exec( $appData, 'UPDATE documents SET xml_path = :p WHERE id = :id', [ 'p' => $a['xml_path'], 'id' => $a['id'] ] );
$linkXml = dirname( $xmlFile ). '/link.xml';
symlink( $xmlFile, $linkXml );
Db::exec( $appData, 'UPDATE documents SET xml_path = :p WHERE id = :id', [ 'p' => dirname( $a['xml_path'] ). '/link.xml', 'id' => $a['id'] ] );
check( 'a link inside the directory is not followed either', $nothing( docServe( $appData, $xmlUri ) ) === true );
unlink( $linkXml );
Db::exec( $appData, 'UPDATE documents SET xml_path = :p WHERE id = :id', [ 'p' => $a['xml_path'], 'id' => $a['id'] ] );
$ignored = ninoWarnings();
check( 'the route and the callback are those the module registered', ( $appData['/nino/http/routes']['GET://.crm/xml/*']['uri'] ?? '' ) === '/.crm/xml/*' && isset( $appData['./nino/callbacks']['/nino/http/response/GET://.crm/xml/*'] ) === true );

// The API of Belege says what the screens draw
echo "\nThe API: the checklist, the card, the lines\n";

$as( 'admin@example.com' );
[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $a['id'] ] );
check( 'a finalised document with a file: its address is /.crm/xml/<id> and the card says the file is there, nothing missing', $status === 200 && $body['document']['xml_url'] === '/.crm/xml/'. $a['id'] && $body['document']['erechnung'] === [ 'xml' => true, 'lacks' => [] ] );
$listed = array_column( $post( 'crm-documents/list' )[1]['clients'], null, 'id' );
check( 'the list of the clients the editor chooses from carries the flag and the buyer reference of each, which the draft takes when another client is chosen', $listed[$biz['id']]['business'] === true && $listed[$biz['id']]['buyer_reference'] === 'PO-4711' && $listed[$private['id']]['business'] === false && $listed[$private['id']]['buyer_reference'] === '' );
[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $e['id'] ] );
check( 'a finalised document that got none: no address, and the card says what was missing - each with its key and its sentence, in the order of the list', $body['document']['xml_url'] === '' && $body['document']['erechnung']['xml'] === false
	&& array_column( $body['document']['erechnung']['lacks'], 'key' ) === [ 'buyer_reference', 'line_unit' ] && $body['document']['erechnung']['lacks'][0]['text'] === XRechnung::text( 'buyer_reference' ) && $body['document']['xml_lacks'] === [ 'buyer_reference', 'line_unit' ] );
[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $d['id'] ] );
check( 'a document of a private client has no card of it, and neither has an offer: null', $body['document']['erechnung'] === null && $post( 'crm-documents/get', [ 'id' => (string) $offerX['id'] ] )[1]['document']['erechnung'] === null );
[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $biz['id'], 'date' => '2026-08-01', 'template_key' => 'erechnung', 'lines' => [ [ 'title' => 'Stunden', 'description' => '', 'unit' => 'Std.', 'unit_code' => 'HUR', 'qty' => '1', 'price' => '50', 'vat_rate' => 0 ], [ 'title' => 'Ohne', 'description' => '', 'unit' => '', 'qty' => '1', 'price' => '5', 'vat_rate' => 0 ] ] ] );
check( 'a draft of a business client is saved with the unit code of its lines and the buyer reference of the client; the answer carries the checklist as it stands: the line without a code is missing', $status === 200 && array_column( $body['document']['lines'], 'unit_code' ) === [ 'HUR', '' ] && $body['document']['buyer_reference'] === 'PO-4711'
	&& $body['document']['erechnung']['xml'] === false && array_column( $body['document']['erechnung']['lacks'], 'key' ) === [ 'line_unit' ] );
$draftId = $body['document']['id'];
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $draftId, 'buyer_reference' => 'Meine Nummer', 'lines' => [ [ 'title' => 'Stunden', 'description' => '', 'unit' => 'Std.', 'unit_code' => 'HUR', 'qty' => '1', 'price' => '50', 'vat_rate' => 0 ], [ 'title' => 'Ohne', 'description' => '', 'unit' => '', 'unit_code' => 'C62', 'qty' => '1', 'price' => '5', 'vat_rate' => 0 ] ] ] );
check( '...the buyer reference of the draft is its own and is saved; with the code on every line nothing is missing', $status === 200 && $body['document']['buyer_reference'] === 'Meine Nummer' && $body['document']['erechnung'] === [ 'xml' => false, 'lacks' => [] ] );
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $draftId, 'lines' => [ [ 'title' => 'Stunden', 'description' => '', 'unit' => 'Std.', 'unit_code' => 'XX', 'qty' => '1', 'price' => '50', 'vat_rate' => 0 ] ] ] );
check( '...a unit code that is none of the list is refused at the line and its field: 400 crm_documents_line_format, lines.0.unit_code', $status === 400 && $body['code'] === 'crm_documents_line_format' && $body['field'] === 'lines.0.unit_code' );
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $draftId, 'buyer_reference' => str_repeat( 'x', 101 ) ] );
check( '...a buyer reference over 100 characters is refused at its field, with the limit', $status === 400 && $body['code'] === 'crm_documents_long' && $body['field'] === 'buyer_reference' && $body['params'] === [ 100 ] );
[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $draftId, 'buyer_reference' => 5 ] );
check( '...and one that is no text, too: 400 crm_documents_format', $status === 400 && $body['code'] === 'crm_documents_format' );
[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'offer', 'client_id' => (string) $biz['id'], 'buyer_reference' => 'Angebot', 'lines' => [ $line( [ 'unit_code' => 'H87' ] ) ] ] );
check( 'an offer keeps no buyer reference and has no card of the electronic invoice', $status === 200 && $body['document']['buyer_reference'] === '' && $body['document']['erechnung'] === null );
Documents::remove( $appData, $body['document']['id'] );
$other = Documents::add( $appData, 'invoice', $bare['id'] );
$moved = Documents::change( $appData, $other['id'], [ 'client_id' => $biz['id'] ] );
$back = Documents::change( $appData, $other['id'], [ 'client_id' => $bare['id'] ] );
check( 'the buyer reference of a draft follows the client it is changed to - and goes back with it - as long as nobody typed another', $other['buyer_reference'] === 'Z-9' && $moved['buyer_reference'] === 'PO-4711' && $back['buyer_reference'] === 'Z-9' && Documents::change( $appData, $other['id'], [ 'buyer_reference' => 'eigene', 'client_id' => $biz['id'] ] )['buyer_reference'] === 'eigene'
	&& Documents::change( $appData, $other['id'], [ 'client_id' => $bare['id'] ] )['buyer_reference'] === 'eigene' );
$copied = Documents::copy( $appData, $a['id'] );
$fromOffer = Documents::fromOffer( $appData, $offerX['id'] );
$creditG = Documents::creditOf( $appData, $g['id'] );
check( 'a copy takes the unit codes and the buyer reference of its source, an invoice made of an offer the reference of the client, a credit note that of its invoice', array_column( $copied['lines'], 'unit_code' ) === [ 'HUR', 'MON' ] && $copied['buyer_reference'] === 'PO-4711' && $fromOffer['buyer_reference'] === 'PO-4711'
	&& $creditG['buyer_reference'] === 'PO-4711' && array_column( $creditG['lines'], 'unit_code' ) === [ 'HUR', 'H87' ] );
foreach( [ $draftId, $other['id'], $copied['id'], $fromOffer['id'], $creditG['id'] ] as $drop )
	Documents::remove( $appData, $drop );

// The settings of the seller
echo "\nRechnungsaussteller\n";

$bad = [];

foreach( [ 'seller_name' => str_repeat( 'x', 121 ), 'seller_street' => "Weg\n1", 'seller_zip' => '10 115!', 'seller_city' => str_repeat( 'x', 81 ), 'seller_country' => 'DEU', 'seller_contact' => "Jan\x07", 'seller_phone' => 'abc', 'seller_email' => 'kein-mail', 'vat_id' => 'D1', 'vat_exempt_text' => str_repeat( 'x', 201 ) ] as $key => $value )
	$bad[$key] = Settings::set( $appData, $key, $value ) === 'value:'. $key && Settings::get( $appData, $key ) === $settingsBefore[$key];

check( 'the seller\'s particulars are checked at the setting, each refused with its own code and left as it was: '. count( $bad ), array_filter( $bad, static fn( bool $ok ): bool => $ok === false ) === [] );
check( '...a country is two capitals (lower case is made upper case), a VAT id is the pattern of the clients\' and is written without blanks in capitals, and all of them may be empty', Settings::set( $appData, 'seller_country', 'at' ) === true && Settings::get( $appData, 'seller_country' ) === 'AT' && Settings::set( $appData, 'vat_id', 'de 123 456 789' ) === true && Settings::get( $appData, 'vat_id' ) === 'DE123456789'
	&& Settings::set( $appData, 'seller_phone', '0049 (0)30 / 12-34' ) === true && Settings::set( $appData, 'seller_zip', 'A-1010' ) === true && Settings::set( $appData, 'seller_email', '' ) === true && Settings::set( $appData, 'seller_email', $settingsBefore['seller_email'] ) === true );

foreach( [ 'seller_country' => 'DE', 'vat_id' => 'DE111222333', 'seller_phone' => '+49 30 123456', 'seller_zip' => '10115' ] as $key => $value )
	Settings::set( $appData, $key, $value );

[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'seller_email' => 'kein-mail' ] );
check( 'the API refuses a seller\'s value at its field with a sentence of its own: 400 crm_documents_particular_seller_email', $status === 400 && $body['code'] === 'crm_documents_particular_seller_email' && $body['field'] === 'seller_email' );
[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'seller_name' => 'Studio Beispiel', 'vat_exempt_text' => 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG' ] );
check( '...and saves the good ones: the answer has them, the exemption text with its preset', $status === 200 && $body['settings']['seller_name'] === 'Studio Beispiel' && $body['settings']['vat_exempt_text'] === 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG' && $body['settings']['seller_country'] === 'DE' );

// The positions carry the unit code
echo "\nPositionen: the unit code\n";

$coded = \Nino\Elements::insertElement( $appData, '/positionen/mit-code', [ 'price' => 10, 'vat' => 0, 'sort' => 90, 'title' => 'Mit Code', 'unit' => 'Std.', 'unit_code' => 'HUR' ], 'de_DE' );
$weird = \Nino\Elements::insertElement( $appData, '/positionen/schief', [ 'price' => 10, 'vat' => 0, 'sort' => 91, 'title' => 'Schief', 'unit_code' => 'Stunde' ], 'de_DE' );
$items = array_column( Positionen::list( $appData ), null, 'id' );
check( 'an item has its unit code (one of the list), as the editor reads it; one that is none of the list is none', Positionen::hasUnitCode( $appData ) === true && $items['mit-code']['unit_code'] === 'HUR' && $items['schief']['unit_code'] === '' && $items['hosting']['unit_code'] === '' && array_column( Positionen::all( $appData ), 'unit_code', 'id' )['mit-code'] === 'HUR' );
$catalogue = array_column( $post( 'crm-documents/list' )[1]['items'], null, 'id' );
check( '...the catalogue of the editor carries it, and the element type has the field with the nine codes and the empty one', $catalogue['mit-code']['unit_code'] === 'HUR' && $catalogue['hosting']['unit_code'] === '' && ( $model = \Nino\Elements::getElementModel( $appData, '/positionen' ) ) && $model['unit_code']['options'] === array_merge( [ '' ], \Crm\Documents\Lines::UNIT_CODES )
	&& $model['unit_code']['type'] === 'string' && ( $model['unit_code']['locale'] ?? false ) === false && $post( 'crm-documents/positionen' )[1]['unit_code'] === true );
check( '...the codes are the nine of the plan: H87 HUR MIN DAY WEE MON LS C62 KMT', implode( ' ', (array) ( new \ReflectionClass( \Crm\Documents\Lines::class ) )->getConstant( 'UNIT_CODES' ) ) === 'H87 HUR MIN DAY WEE MON LS C62 KMT' );
\Nino\Elements::deleteElement( $appData, '/positionen/mit-code' );
\Nino\Elements::deleteElement( $appData, '/positionen/schief' );

// The three files a person validates with the official validator on his own machine are left where the environment says (CRM_FIXTURES); the suite runs no validator
$fixtures = getenv( 'CRM_FIXTURES' );

if( is_string( $fixtures ) === true && is_dir( $fixtures ) === true )
	foreach( [ 'a-kleinunternehmer-rechnung' => $a, 'b-regelbesteuert-rechnung' => $b, 'c-stornorechnung' => $c ] as $name => $fixture )
		copy( $xmlDir( $fixture ), $fixtures. '/'. $name. '.xml' );


// --- Eight processes finalising at once -------------------------------------------------------------------------------

echo "\nThe documents that do not wait for the address\n";

// A contract, the Datenschutz and an information name the client by nothing an invoice needs: a client with no address has them finalised, an offer stops - the rule is for the three with lines
Db::exec( $appData, 'UPDATE clients SET street = \'\', zip = \'\', city = \'\', country = \'\' WHERE id = :id', [ 'id' => $second['id'] ] );
$noAddress = (array) \Crm\Clients::get( $appData, $second['id'] );
$waiting = Documents::add( $appData, 'offer', $second['id'], [ 'lines' => $lines, 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
$plainOnes = [
	'information' => Documents::add( $appData, 'information', $second['id'], [ 'title' => 'Ohne Anschrift', 'template_key' => 'zugang' ] ),
	'privacy'     => Documents::add( $appData, 'privacy', $second['id'], [ 'title' => 'Ohne Anschrift', 'template_key' => 'datenschutz' ] ),
	'contract'    => Documents::add( $appData, 'contract', 0, [ 'parent_id' => $otherFinal['id'], 'title' => 'Ohne Anschrift', 'template_key' => 'dienstleistung', 'inputs' => [ 'zusatz' => 'x' ] ] ),
];
$plainDone = array_map( static fn( mixed $draft ): mixed => is_array( $draft ) === true ? Documents::finalise( $appData, $draft['id'] ) : $draft, $plainOnes );
check( 'a client with no address is not ready, and has its contract, its Datenschutz and its information finalised all the same - they carry no line and no number and name it by nothing an invoice needs', $noAddress['ready'] === false && array_map( static fn( mixed $done ): string => is_array( $done ) === true ? $done['state'] : (string) $done, $plainDone ) === [ 'information' => 'open', 'privacy' => 'open', 'contract' => 'open' ]
	&& is_array( $waiting ) === true && Documents::finalise( $appData, $waiting['id'] ) === 'unready' && Documents::get( $appData, $waiting['id'] )['state'] === 'draft' );
Documents::remove( $appData, $waiting['id'] );

echo "\nNumbers under concurrency\n";

$parallel = ninoSandbox( 'crm-documents-parallel' );
$parallel['/nino/modules'] = [ '\\Nino\\Modules\\Components', '\\Nino\\Modules\\Csrf', '\\Nino\\Modules\\Template' ];
\Nino\AppData::writeContentData( $parallel, [ '/nino/modules', '/nino/locales/available', '/nino/locales/native' ] );
\Nino\Features::activate( $parallel, 'pdf' );
\Nino\Html::init( $parallel );
\Nino\Modules::callModules( $parallel, 'init' );
\Nino\Filesystem::putFileContent( $parallel, '/config.php', [ '/nino/modules' => array_merge( (array) $parallel['/nino/modules'], [ '\\Crm\\Crm' ] ) ] + (array) \Nino\Filesystem::getFileContent( $parallel, '/config.php', [] ) );
ninoUser( $parallel, 'admin@example.com' );
ninoCall( $parallel, $panel, [ 'key' => 'rechnung', 'isNew' => true, 'name' => 'Rechnung', 'tag' => 'invoice', 'blocks' => $blocks( 'x' ) ], 'pdf/templates/save' );
$owner = \Crm\Clients::add( $parallel, [ 'company' => 'Parallel GmbH', 'street' => 'Weg 1', 'zip' => '10115', 'city' => 'Berlin' ] );
$draftIds = [];

for( $n = 0; $n < 8; $n++ )
	$draftIds[] = Documents::add( $parallel, 'invoice', $owner['id'], [ 'date' => '2026-06-01', 'lines' => [ $line( [ 'title' => 'Posten '. $n ] ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] )['id'];

Db::close();
$workers = [];

foreach( $draftIds as $draftId )
	$workers[] = proc_open( [ PHP_BINARY, __FILE__, 'worker', ninoSandboxDir( $parallel ), (string) $draftId ], [ 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );

$failures = 0;

foreach( $workers as $process )
	if( is_resource( $process ) === true )
		$failures += proc_close( $process );

$numbers = array_column( Db::rows( $parallel, 'SELECT number FROM documents ORDER BY number' ), 'number' );
check( 'eight processes finalising eight drafts of one category: every one succeeded', $failures === 0 );
check( '...with eight different, contiguous numbers: R-2026-0001 to R-2026-0008', $numbers === array_map( static fn( int $n ): string => sprintf( 'R-2026-%04d', $n ), range( 1, 8 ) ) );
check( '...and eight files, each with the hash its document stores', count( glob( \Nino\Filesystem::path( $parallel, Render::DIR ). '/2026/*.pdf' ) ?: [] ) === 8 && array_filter( Db::rows( $parallel, 'SELECT pdf_path, pdf_sha256 FROM documents' ), static fn( array $row ): bool => hash_file( 'sha256', \Nino\Filesystem::path( $parallel, $row['pdf_path'] ) ) !== $row['pdf_sha256'] ) === [] );
check( '...the sequence stands at 9', (int) Db::row( $parallel, 'SELECT next FROM sequences WHERE category = \'invoice\' AND year = 2026' )['next'] === 9 );
Db::close();
\Nino\Filesystem::removeDir( ninoSandboxDir( $parallel ) );

// --- Einwilligungen: the sentences of the boxes in the settings -----------------------------------------------------------------------------

/**
 *	The checks of the units of the features the Einrichtung applies: the words and the page templates they miss are entered add-only, the status counts them
 *	before, and a second press adds nothing
 *
 *	@param		array 		&$appData			A site the setup has been pressed on
 *	@param		\Closure	$post					Posts an action of the panel
 *	@param		string		$root
 *
 *	@return 	void
 */
function documentsFeatureUnitChecks( array &$appData, \Closure $post, string $root ): void {

	[ $status, $body ] = $post( 'crm/setup' );
	check( 'a press on a site that has the units of the features whole adds nothing from them: features_units is empty and says which ones were there - Pdf has no unit', $status === 200 && $body['added']['features_units'] === [] && $body['existed']['features_units'] === [ 'members', 'files', 'review', 'sign', 'events' ] );

	$locales = \Nino\Locales::getAvailableLocales( $appData );
	$native = (string) $appData['/nino/locales/native'];
	$gone = [];

	// A site that was set up before the words came: the keys of Members, Files and Review are not in its text files, a word of the owner is, and so is a template that is missing
	foreach( [ 'members', 'files', 'review' ] as $key )
		foreach( $locales as $locale ) {

			$file = \Nino\Features::get( $appData, $key )['dir']. '/install/text/'. $locale. '.php';
			$unit = is_file( $file ) === true ? array_keys( (array) include $file ) : [];

			if( $locale === $native )
				$gone[$key] = $unit;

			\Nino\Filesystem::mutate( $appData, '/text/'. $locale. '.php', static fn( array $texts ): array => array_diff_key( $texts, array_flip( $unit ) ) );
		}

	$own = '[[/feature/members/login/label]]';
	\Nino\Filesystem::mutate( $appData, '/text/'. $native. '.php', static function( array $texts ) use ( $own ): array {
		$texts[$own] = 'Meine Beschriftung';
		return $texts;
	} );
	@unlink( \Nino\Filesystem::path( $appData, '/templates/page-members-confirm.tpl' ) );

	// The word of the owner is there, the template is not: one word less, one template more
	$lacking = array_sum( array_map( 'count', $gone ) );
	[ $status, $body ] = $post( 'crm/status' );
	$item = array_values( array_filter( $body['items'], static fn( array $one ): bool => $one['key'] === 'features-units' ) )[0] ?? [];
	check( 'the status counts what the units of the features miss before the press: partial, the number of words and templates, and a line for each feature that misses some', $status === 200 && ( $item['state'] ?? '' ) === 'partial' && ( $item['count'] ?? 0 ) === $lacking && count( $item['lacks'] ?? [] ) === 3 && $body['whole'] === false );

	[ $status, $body ] = $post( 'crm/setup' );
	$added = $body['added']['features_units'] ?? [];
	check( 'the press enters them: the words of each feature by number, the template by its file - and the answer says which features were there already', $status === 200 && array_keys( $added ) === [ 'members', 'files', 'review' ] && $added['members']['words'] === count( $gone['members'] ) - 1 && $added['files']['words'] === count( $gone['files'] ) && $added['review']['words'] === count( $gone['review'] )
		&& $added['members']['templates'] === [ 'page-members-confirm.tpl' ] && $added['files']['templates'] === [] && $body['existed']['features_units'] === [ 'sign', 'events' ] );

	$missing = [];

	foreach( $locales as $locale ) {

		$texts = (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $locale. '.php', [] );

		foreach( $gone as $keys )
			foreach( $keys as $key )
				if( array_key_exists( $key, $texts ) === false )
					$missing[] = $locale. ' '. $key;
	}

	$texts = (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] );
	check( 'every key of the three units is in the text file of every language again, and the word the owner had is his own still; the template is there', $missing === [] && ( $texts[$own] ?? '' ) === 'Meine Beschriftung' && is_file( \Nino\Filesystem::path( $appData, '/templates/page-members-confirm.tpl' ) ) === true );

	[ $status, $body ] = $post( 'crm/setup' );
	check( 'a second press adds nothing from the units and the status is whole again', $status === 200 && $body['added']['features_units'] === [] && $body['existed']['features_units'] === [ 'members', 'files', 'review', 'sign', 'events' ] && $post( 'crm/status' )[1]['whole'] === true );
}

/**
 *	The checks of the settings of the consents
 */
function documentsConsentChecks( array &$appData, \Closure $post, string $root ): void {

	echo "\nThe sentences of the consents\n";

	[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'consent_social' => "  Ich willige ein,\r\nfreiwillig.  ", 'consent_archive' => 'Archiv' ] );
	check( 'settings-save writes the sentences of the two boxes with their line breaks and answers them: no time is set for them', $status === 200 && $body['settings']['consent_social'] === "Ich willige ein,\nfreiwillig." && $body['settings']['consent_archive'] === 'Archiv' && Settings::get( $appData, 'consent_social' ) === "Ich willige ein,\nfreiwillig."
		&& Settings::get( $appData, 'consent_archive' ) === 'Archiv' && array_key_exists( 'consent_social_at', $body['settings'] ) === false );
	[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'consent_social' => 'Nur der erste' ] );
	check( '...a save that names one of them leaves the other as it is', $status === 200 && Settings::get( $appData, 'consent_archive' ) === 'Archiv' && Settings::get( $appData, 'consent_social' ) === 'Nur der erste' );
	$wrong = [];

	foreach( [ 'consent_social', 'consent_archive' ] as $key )
		foreach( [ 'too long' => str_repeat( 'x', 2001 ), 'a control character' => "a\x07b", 'a null byte' => "a\0b", 'an array' => [ 'x' ] ] as $label => $value ) {
			[ $status, $body ] = $post( 'crm-documents/settings-save', [ $key => $value, 'payment_days' => '31' ] );
			$wrong[$key. ' '. $label] = $status === 400 && $body['code'] === 'crm_documents_'. $key && $body['field'] === $key && Settings::get( $appData, $key ) !== $value && Settings::get( $appData, 'payment_days' ) !== '31';
		}

	check( '...a sentence that is none is 400 at its field with a code of its own - crm_documents_consent_social, crm_documents_consent_archive -, and nothing of the request is written: too long, a control character, a null byte, an array', array_keys( array_filter( $wrong, static fn( bool $same ): bool => $same === false ) ) === [] );
	check( '...each code has a sentence in both languages', array_filter( [ 'de_DE', 'en_US' ], static function( string $locale ) use ( $root ): bool {

		$texts = (array) require $root. '/app/Crm/Documents/Admin/text/'. $locale. '.php';

		return trim( (string) ( $texts['[[/_admin/error/crm_documents_consent_social]]'] ?? '' ) ) === '' || trim( (string) ( $texts['[[/_admin/error/crm_documents_consent_archive]]'] ?? '' ) ) === '';
	} ) === [] );
	[ $status, $body ] = $post( 'crm-documents/settings-save', [ 'consent_social' => '', 'consent_archive' => '' ] );
	check( '...an empty sentence removes the box', $status === 200 && $body['settings']['consent_social'] === '' && $body['settings']['consent_archive'] === '' && \Crm\Records::sentence( $appData, 'social' ) === '' && \Crm\Records::sentence( $appData, 'archive' ) === '' );
	$list = $post( 'crm-documents/settings-get' );
	check( '...settings-get has them, empty, beside the other settings', $list[0] === 200 && array_key_exists( 'consent_social', $list[1]['settings'] ?? [] ) === true && array_key_exists( 'consent_archive', $list[1]['settings'] ?? [] ) === true );
}

documentsConsentChecks( $appData, $post, $root );
documentsFeatureUnitChecks( $appData, $post, $root );
documentsTemplateKeyChecks( $appData, $post );
documentsBooksChecks( $appData, $post, $first['id'] );
documentsCreditRateChecks( $appData, $post, $first['id'] );
documentsNoteChecks( $appData, $root );

/**
 *	The columns that keep the sentence of a release: what migration 0014 adds, and what a document finalised before it has in them
 *
 *	@param		array 		&$appData
 *	@param		string		$root
 *
 *	@return 	void
 */
function documentsNoteChecks( array &$appData, string $root ): void {

	echo "\nThe sentence of a release\n";

	$statements = (array) include $root. '/app/Crm/Db/migrations/0014-release-note.php';
	$columns = array_column( Db::rows( $appData, 'PRAGMA table_info( documents )' ), null, 'name' );

	check( 'migration 0014 adds the two columns of the sentence and nothing else: the text, empty at first and not null, and the time, which a document that was never released with one does not have', count( $statements ) === 2 && str_contains( $statements[0], 'ADD COLUMN release_note ' ) === true && str_contains( $statements[1], 'ADD COLUMN release_note_at ' ) === true
		&& (int) $columns['release_note']['notnull'] === 1 && $columns['release_note']['dflt_value'] === "''" && (int) $columns['release_note_at']['notnull'] === 0 );
	check( '...a document that is there has them empty, and get() says so with texts', ( $row = Db::row( $appData, 'SELECT id FROM documents ORDER BY id LIMIT 1' ) ) !== null && ( $document = Documents::get( $appData, (int) $row['id'] ) ) !== null && $document['release_note'] === '' && $document['release_note_at'] === '' );
}

ninoDone( $appData );

/**
 *	The credit notes of an invoice together, in every rate: what they may take back, how the last one closes the invoice to the cent, and the days a document may carry
 *
 *	@param		array 		&$appData
 *	@param		\Closure	$post						Runs an action of the panel
 *	@param		int				$clientId				A client that is ready for invoices
 *
 *	@return 	void
 */
function documentsCreditRateChecks( array &$appData, \Closure $post, int $clientId ): void {

	echo "\nCredit notes together, and the days\n";

	$line = static fn( string $qty, string $price, int $rate = 0, string $title = 'Posten' ): array => [ 'title' => $title, 'unit' => 'Std.', 'qty' => $qty, 'price' => $price, 'vat_rate' => $rate ];
	$invoice = static function( array $lines, array $extra = [] ) use ( &$appData, $clientId ): array {
		$draft = (array) Documents::add( $appData, 'invoice', $clientId, [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] + $extra );

		return (array) Documents::finalise( $appData, $draft['id'] );
	};
	$state = static fn( int $id ): string => (string) Db::row( $appData, 'SELECT state FROM documents WHERE id = :id', [ 'id' => $id ] )['state'];
	$day = static fn( int $offset ): string => date( 'Y-m-d', strtotime( sprintf( '%+d days', $offset ) ) );
	$mode = Settings::get( $appData, 'vat_mode' );
	Settings::set( $appData, 'vat_mode', 'regular' );

	// The limit of a rate holds for all the credit notes of the invoice together, not for each of them
	$two = $invoice( [ $line( '1', '100', 1900, 'Neunzehn' ), $line( '1', '100', 700, 'Sieben' ) ] );
	$one = documentsPartialCredit( $appData, $two['id'], 'Neunzehn', '-1', '100', 1900 );
	$more = documentsPartialCredit( $appData, $two['id'], 'Neunzehn', '-1', '89', 1900 );
	$rest = documentsPartialCredit( $appData, $two['id'], 'Sieben', '-1', '1,02', 700 );
	$first = Documents::finalise( $appData, $one['id'] );
	[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $more['id'] ] );
	check( 'the credit notes of an invoice together take back no more of a rate than the invoice charged: 100 of 100 at 19 %, then 89 more at 19 % is 409 crm_documents_credit_over at the field lines, however small the third at 7 % is', is_array( $first ) && $status === 409 && $body['code'] === 'crm_documents_credit_over' && $body['field'] === 'lines'
		&& Documents::finalise( $appData, $more['id'] ) === 'credit_over' && is_array( Documents::finalise( $appData, $rest['id'] ) ) && $state( $two['id'] ) === 'open' && $state( $more['id'] ) === 'draft' );
	$gone = documentsPartialCredit( $appData, $two['id'], 'Sieben', '-1', '98,98', 700 );
	check( '...what is left of the rate is taken, and the invoice is cancelled once every rate is back in full', is_array( Documents::finalise( $appData, $gone['id'] ) ) && $state( $two['id'] ) === 'cancelled' );

	// Net, per rate: a credit note may not take back a rate from the charge of another
	$mixed = $invoice( [ $line( '1', '100', 1900, 'Neunzehn' ), $line( '1', '-20', 1900, 'Rabatt' ) ] );
	$wholeCharge = (array) Documents::creditOf( $appData, $mixed['id'] );
	$taken = Documents::change( $appData, $wholeCharge['id'], [ 'lines' => [ $line( '-1', '100', 1900, 'Neunzehn' ) ] ] );
	check( 'a rate whose charge is 100 and whose discount is 20 is taken back by 80 net at most: a credit note of the whole charge alone is credit_lines', $taken === 'credit_lines' && count( Documents::get( $appData, $wholeCharge['id'] )['lines'] ) === 2 );

	// The tax of each rate is rounded on what the invoice has given back so far: 2 x 10,50 at 19 % is 24,99, and two credit notes of one piece each are 12,50 and 12,49
	$pair = $invoice( [ $line( '2', '10,50', 1900, 'Paar' ) ] );
	$halfA = documentsPartialCredit( $appData, $pair['id'], 'Paar', '-1', '10,50', 1900 );
	$halfB = documentsPartialCredit( $appData, $pair['id'], 'Paar', '-1', '10,50', 1900 );
	$doneA = (array) Documents::finalise( $appData, $halfA['id'] );
	$stateBetween = $state( $pair['id'] );
	$doneB = (array) Documents::finalise( $appData, $halfB['id'] );
	$pdf = (string) file_get_contents( \Nino\Filesystem::path( $appData, $doneB['pdf_path'] ) );
	check( '2 x 10,50 at 19 % (24,99 gross) taken back by two credit notes of one piece: the first is 12,50 and leaves the invoice, the second is 12,49 and cancels it', $pair['gross'] === 2499 && $doneA['gross'] === -1250 && $stateBetween === 'open' && $doneB['gross'] === -1249
		&& $doneB['net'] + $doneB['vat'] === $doneB['gross'] && $state( $pair['id'] ) === 'cancelled' && str_starts_with( $pdf, '%PDF' ) === true );
	$together = $doneA['gross'] + $doneB['gross'];
	check( '...the two come to the gross of the invoice to the cent', $together === -$pair['gross'] );
	$ratesOf = static fn( array $done ): array => array_map( static fn( array $part ): int => $part['vat'], Money::totals( $done['lines'], $done['vat_mode'], $done['reverse_charge'], Documents::creditVat( $appData, $done, $done['lines'], array_column( $done['lines'], 'amount_net' ) ) )['rates'] );
	check( '...the totals the file is drawn from carry the tax of the document, not the tax of its lines taken alone: the tax of each rate adds up to the tax stored, and the lines alone would round the second the same way as the first', $ratesOf( $doneB ) === [ 1900 => -199 ] && array_sum( $ratesOf( $doneB ) ) === $doneB['vat']
		&& Money::totals( $doneB['lines'], $doneB['vat_mode'], false )['vat'] === -200 );

	// Two rates: the cent of one rate stays in that rate
	$mixedPair = $invoice( [ $line( '2', '10,50', 1900, 'Neunzehn' ), $line( '2', '10,50', 700, 'Sieben' ) ] );
	$notes = [];

	for( $round = 0; $round < 2; $round++ ) {
		$draft = (array) Documents::creditOf( $appData, $mixedPair['id'] );
		Documents::change( $appData, $draft['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ], 'lines' => [ $line( '-1', '10,50', 1900, 'Neunzehn' ), $line( '-1', '10,50', 700, 'Sieben' ) ] ] );
		$notes[] = (array) Documents::finalise( $appData, $draft['id'] );
	}

	check( '2 x 10,50 at 19 % and at 7 % taken back twice, one piece of each: the second credit note has 1,99 at 19 % and 0,73 at 7 % - a cent does not move from one rate to the other - and the two come to the gross of the invoice', $mixedPair['gross'] === 4746 && $ratesOf( $notes[0] ) === [ 1900 => -200, 700 => -74 ] && $ratesOf( $notes[1] ) === [ 1900 => -199, 700 => -73 ]
		&& $notes[0]['gross'] + $notes[1]['gross'] === -4746 && $state( $mixedPair['id'] ) === 'cancelled' );

	// Two credit notes finalised in the same second: the one that was finalised first has the first number, whatever its id is
	$tied = $invoice( [ $line( '2', '10,50', 1900, 'Paar' ) ] );
	$lowId = documentsPartialCredit( $appData, $tied['id'], 'Paar', '-1', '10,50', 1900 );
	$highId = documentsPartialCredit( $appData, $tied['id'], 'Paar', '-1', '10,50', 1900 );
	$firstDone = (array) Documents::finalise( $appData, $highId['id'] );
	$secondDone = (array) Documents::finalise( $appData, $lowId['id'] );
	Db::exec( $appData, 'UPDATE documents SET finalised = :at WHERE id IN ( :one, :two )', [ 'at' => $firstDone['finalised'], 'one' => $lowId['id'], 'two' => $highId['id'] ] );
	$ratesFirst = Documents::get( $appData, $highId['id'] )['rates'];
	$ratesSecond = Documents::get( $appData, $lowId['id'] )['rates'];
	check( 'two credit notes finalised in the same second are taken in the order of their numbers, not of their ids: the one finalised first (the higher id) has the tax -2,00, the other -1,99, and what get() shows is what each was finalised with', $lowId['id'] < $highId['id'] && $firstDone['vat'] === -200 && $secondDone['vat'] === -199 && $ratesFirst[0]['vat'] === -200 && $ratesSecond[0]['vat'] === -199 );

	// Four pieces, four credit notes: each is within a cent of its own net times the rate, and the four come to the tax of the invoice
	$four = $invoice( [ $line( '4', '10,50', 1900, 'Vier' ) ] );
	$sum = 0;
	$near = true;

	for( $round = 0; $round < 4; $round++ ) {
		$note = (array) Documents::finalise( $appData, documentsPartialCredit( $appData, $four['id'], 'Vier', '-1', '10,50', 1900 )['id'] );
		$sum += $note['vat'];
		$near = $near && abs( $note['vat'] + 199.5 ) <= 1;
	}

	check( '4 x 10,50 at 19 % taken back by four credit notes of one piece: each has its tax within a cent of its net times the rate, the four together the tax of the invoice, and the invoice is cancelled', $near === true && $sum === -$four['vat'] && $state( $four['id'] ) === 'cancelled' );

	// The limit of a kind holds for the credit notes together: a discount given back twice
	$discounted = $invoice( [ $line( '1', '100', 0, 'Posten' ), $line( '1', '-20', 0, 'Rabatt' ) ] );
	$twice = [];

	foreach( [ '60', '40' ] as $charge ) {
		$draft = (array) Documents::creditOf( $appData, $discounted['id'] );
		Documents::change( $appData, $draft['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ], 'lines' => [ $line( '-1', $charge, 0, 'Posten' ), $line( '1', '20', 0, 'Rabatt' ) ] ] );
		$twice[] = $draft['id'];
	}

	check( 'a discount of the invoice is given back once: the first credit note of 60 and the discount is taken, the second of 40 and the same discount again is credit_over, and the invoice is open', is_array( Documents::finalise( $appData, $twice[0] ) ) && Documents::finalise( $appData, $twice[1] ) === 'credit_over' && $state( $discounted['id'] ) === 'open' );

	// A credit note is dated no earlier than its invoice
	$dated = $invoice( [ $line( '1', '10' ) ], [ 'date' => $day( -3 ) ] );
	$early = (array) Documents::creditOf( $appData, $dated['id'] );
	Documents::change( $appData, $early['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	$earlier = Documents::change( $appData, $early['id'], [ 'date' => $day( -4 ) ] );
	[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $early['id'], 'date' => $day( -4 ) ] );
	Db::exec( $appData, 'UPDATE documents SET date = :date WHERE id = :id', [ 'date' => $day( -4 ), 'id' => $early['id'] ] );
	check( 'a credit note dated before its invoice is refused: date:date, and 400 crm_documents_date at the field date - and when it is finalised, if it was dated so behind the model\'s back', $earlier === 'date:date' && $status === 400 && $body['code'] === 'crm_documents_date' && $body['field'] === 'date' && Documents::finalise( $appData, $early['id'] ) === 'date:date' && $state( $early['id'] ) === 'draft' );
	Documents::remove( $appData, $early['id'] );

	// A document carries no day after today
	$late = (array) Documents::add( $appData, 'invoice', $clientId, [ 'date' => $day( 1 ), 'lines' => [ $line( '1', '10' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	$lateOffer = (array) Documents::add( $appData, 'offer', $clientId, [ 'date' => $day( 1 ), 'lines' => [ $line( '1', '10' ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
	$lateCredit = documentsPartialCredit( $appData, $dated['id'], 'Posten', '-1', '5' );
	Db::exec( $appData, 'UPDATE documents SET date = :date WHERE id = :id', [ 'date' => $day( 1 ), 'id' => $lateCredit['id'] ] );
	[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $late['id'] ] );
	check( 'an invoice, an offer and a credit note dated after today are not finalised, tomorrow included: 400 crm_documents_date at the field date, no number spent, still drafts', Documents::finalise( $appData, $late['id'] ) === 'date:date' && Documents::finalise( $appData, $lateOffer['id'] ) === 'date:date' && Documents::finalise( $appData, $lateCredit['id'] ) === 'date:date'
		&& $status === 400 && $body['code'] === 'crm_documents_date' && $body['field'] === 'date' && $state( $late['id'] ) === 'draft' && $state( $lateOffer['id'] ) === 'draft' && $state( $lateCredit['id'] ) === 'draft' );
	Documents::remove( $appData, $lateCredit['id'] );
	Documents::remove( $appData, $lateOffer['id'] );
	Documents::change( $appData, $late['id'], [ 'date' => $day( 0 ) ] );
	$today = (array) Documents::finalise( $appData, $late['id'] );
	check( '...today is the last day: the invoice is finalised, and a day to come is no day of sending or payment', Documents::setState( $appData, $today['id'], 'sent', $day( 1 ) ) === 'daterange' && $state( $today['id'] ) === 'open' && ( Documents::setState( $appData, $today['id'], 'sent' )['state'] ?? '' ) === 'sent'
		&& Documents::setState( $appData, $today['id'], 'paid', $day( 1 ) ) === 'daterange' && is_array( Documents::setState( $appData, $today['id'], 'paid', $day( 0 ) ) ) );
	$behind = $invoice( [ $line( '1', '10' ) ], [ 'date' => $day( 0 ) ] );
	Db::exec( $appData, 'UPDATE documents SET date = :date WHERE id = :id', [ 'date' => $day( 1 ), 'id' => $behind['id'] ] );
	check( '...a day that is left out is today, and today is held to the day of the document as a given day is: a document dated later (behind the model\'s back) is not sent', Documents::setState( $appData, $behind['id'], 'sent' ) === 'daterange' && $state( $behind['id'] ) === 'open' );

	// An offer is no claim below 0
	$below = (array) Documents::add( $appData, 'offer', $clientId, [ 'lines' => [ $line( '1', '-5' ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
	$null = (array) Documents::add( $appData, 'offer', $clientId, [ 'lines' => [ $line( '1', '0' ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] );
	check( 'an offer below 0 is not finalised (409 gross), an offer of 0 is', Documents::finalise( $appData, $below['id'] ) === 'gross' && is_array( Documents::finalise( $appData, $null['id'] ) ) && $state( $below['id'] ) === 'draft' );
	Documents::remove( $appData, $below['id'] );

	// Smaller lines, one for each position
	$sixty = $invoice( [ $line( '1', '60', 0, 'A' ), $line( '1', '40', 0, 'B' ) ] );
	$wide = (array) Documents::creditOf( $appData, $sixty['id'] );
	$lone = Documents::change( $appData, $wide['id'], [ 'lines' => [ $line( '-1', '70', 0, 'Beide' ) ] ] );
	$cut = Documents::change( $appData, $wide['id'], [ 'lines' => [ $line( '-1', '60', 0, 'A' ), $line( '-1', '40', 0, 'B' ) ] ] );
	check( 'a line is no more than the largest line of the invoice in its rate: 70 on an invoice of 60 and 40 is credit_lines, the two lines of the invoice are taken, one for each position', $lone === 'credit_lines' && is_array( $cut ) );
	$reverse = Documents::change( $appData, $wide['id'], [ 'reverse_charge' => true ] );
	[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $wide['id'], 'reverse_charge' => true ] );
	check( 'a credit note takes the reverse charge of its invoice and no other: switching it on for the credit note of an invoice that has none is credit_lines, 400 crm_documents_credit_lines at the field lines, and the draft is as it was', $reverse === 'credit_lines' && $status === 400 && $body['code'] === 'crm_documents_credit_lines' && $body['field'] === 'lines' && Documents::get( $appData, $wide['id'] )['reverse_charge'] === false );
	Settings::set( $appData, 'vat_mode', $mode );
}

/**
 *	A credit note draft for a part of an invoice: one line of the invoice's, taken back by the quantity given
 *
 *	@param		array 		&$appData
 *	@param		int				$invoiceId
 *	@param		string		$title
 *	@param		string		$qty
 *	@param		string		$price
 *	@param		int				$rate
 *
 *	@return 	array											The draft, with the template of invoices
 */
function documentsPartialCredit( array &$appData, int $invoiceId, string $title, string $qty, string $price, int $rate = 0 ): array {

	$draft = (array) Documents::creditOf( $appData, $invoiceId );

	return (array) Documents::change( $appData, $draft['id'], [ 'lines' => [ [ 'title' => $title, 'unit' => 'Monat', 'qty' => $qty, 'price' => $price, 'vat_rate' => $rate ] ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
}

/**
 *	The books hold: the rules a credit note, a date, an amount and a reminder keep whatever is posted
 *
 *	@param		array 		&$appData
 *	@param		\Closure	$post						Runs an action of the panel
 *	@param		int				$clientId				A client that is ready for invoices
 *
 *	@return 	void
 */
function documentsBooksChecks( array &$appData, \Closure $post, int $clientId ): void {

	echo "\nThe books hold\n";

	$today = date( 'Y-m-d' );
	$line = static fn( string $qty, string $price, int $rate = 0, string $title = 'Posten' ): array => [ 'title' => $title, 'unit' => 'Std.', 'qty' => $qty, 'price' => $price, 'vat_rate' => $rate ];
	$invoice = static function( array $lines, array $extra = [] ) use ( &$appData, $clientId ): array {
		$draft = (array) Documents::add( $appData, 'invoice', $clientId, [ 'lines' => $lines, 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] + $extra );

		return (array) Documents::finalise( $appData, $draft['id'] );
	};
	$sequences = static fn(): array => Db::rows( $appData, 'SELECT category, year, next FROM sequences ORDER BY category, year' );
	$state = static fn( int $id ): string => (string) Db::row( $appData, 'SELECT state FROM documents WHERE id = :id', [ 'id' => $id ] )['state'];

	// a credit note comes from an invoice
	$hundred = $invoice( [ $line( '1', '100' ) ] );
	[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'credit', 'client_id' => (string) $clientId, 'template_key' => 'stornorechnung', 'lines' => [ $line( '1', '-50' ) ] ] );
	check( 'a credit note cannot be saved on its own: add() says category, the action 400 crm_documents_category at the field category, and nothing is stored', Documents::add( $appData, 'credit', $clientId ) === 'category' && $status === 400 && $body['code'] === 'crm_documents_category' && $body['field'] === 'category'
		&& (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE category = \'credit\' AND parent_id IS NULL' )['n'] === 0 );
	$orphan = (array) Documents::creditOf( $appData, $hundred['id'] );
	Documents::change( $appData, $orphan['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	Db::exec( $appData, 'UPDATE documents SET parent_id = NULL WHERE id = :id', [ 'id' => $orphan['id'] ] );
	$before = $sequences();
	[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $orphan['id'] ] );
	check( 'a credit note draft with no invoice is not finalised: 409 crm_documents_credit_parent, no number spent, still a draft - and it is not saved either', Documents::finalise( $appData, $orphan['id'] ) === 'credit_parent' && $status === 409 && $body['code'] === 'crm_documents_credit_parent'
		&& $sequences() === $before && $state( $orphan['id'] ) === 'draft' && Documents::change( $appData, $orphan['id'], [ 'notes' => 'x' ] ) === 'credit_parent' );
	Documents::remove( $appData, $orphan['id'] );

	// the lines of a credit note give back what the invoice charged
	$draft = (array) Documents::creditOf( $appData, $hundred['id'] );
	$wrong = [ 'positive' => [ $line( '1', '10' ) ], 'larger than the line' => [ $line( '-2', '100' ) ], 'a line more than the invoice has' => [ $line( '-1', '60' ), $line( '-1', '60' ) ] ];
	$answers = [];

	foreach( $wrong as $what => $lines )
		$answers[$what] = Documents::change( $appData, $draft['id'], [ 'lines' => $lines ] );

	[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $draft['id'], 'lines' => $wrong['positive'] ] );
	check( 'the lines of a credit note are refused when they are positive, larger than the line of the invoice, or together more than the invoice in their rate: credit_lines, and 400 crm_documents_credit_lines at the field lines', array_values( $answers ) === [ 'credit_lines', 'credit_lines', 'credit_lines' ] && $status === 400 && $body['code'] === 'crm_documents_credit_lines' && $body['field'] === 'lines'
		&& array_column( Documents::get( $appData, $draft['id'] )['lines'], 'qty' ) === [ -1000 ] );
	check( '...a line that gives back a part is taken, at the price of the invoice or at a smaller one', is_array( Documents::change( $appData, $draft['id'], [ 'lines' => [ $line( '-1', '40', 0, 'Teil' ) ] ] ) ) && Documents::change( $appData, $draft['id'], [ 'lines' => [ $line( '-0,5', '100' ) ] ] )['gross'] === -5000 );

	$mode = Settings::get( $appData, 'vat_mode' );
	Settings::set( $appData, 'vat_mode', 'regular' );
	$taxed = $invoice( [ $line( '1', '100', 1900 ) ] );
	$taxedCredit = (array) Documents::creditOf( $appData, $taxed['id'] );
	$otherRate = Documents::change( $appData, $taxedCredit['id'], [ 'lines' => [ $line( '-1', '100', 700 ) ] ] );
	$zeroRate = Documents::change( $appData, $taxedCredit['id'], [ 'lines' => [ $line( '-1', '100', 1900 ), $line( '-1', '0', 700, 'Null' ) ] ] );
	$sameRate = Documents::change( $appData, $taxedCredit['id'], [ 'lines' => [ $line( '-1', '100', 1900 ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	$cancelsAll = Documents::finalise( $appData, $taxedCredit['id'] );
	Settings::set( $appData, 'vat_mode', $mode );
	check( 'a credit note is held to the rates of its invoice: 700 on an invoice of 1900 is credit_lines, also on a line of 0, 1900 is taken and, taking all of the invoice, cancels it', $otherRate === 'credit_lines' && $zeroRate === 'credit_lines' && is_array( $sameRate ) && is_array( $cancelsAll ) && $cancelsAll['gross'] === -$taxed['gross'] && $state( $taxed['id'] ) === 'cancelled' );

	// what is finalised is held to the invoice, and the credit notes together to its gross
	$base = $invoice( [ $line( '1', '100' ) ] );
	$first = (array) documentsPartialCredit( $appData, $base['id'], 'Teil', '-1', '60' );
	$second = (array) documentsPartialCredit( $appData, $base['id'], 'Teil', '-1', '60' );
	$firstDone = Documents::finalise( $appData, $first['id'] );
	$before = $sequences();
	[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $second['id'] ] );
	check( 'two credit notes of 60 on an invoice of 100: the first is finalised and leaves the invoice as it is, the second is 409 crm_documents_credit_over at the field lines, spends no number and stays a draft', is_array( $firstDone ) && $state( $base['id'] ) === 'open' && $status === 409 && $body['code'] === 'crm_documents_credit_over' && $body['field'] === 'lines'
		&& Documents::finalise( $appData, $second['id'] ) === 'credit_over' && $sequences() === $before && $state( $second['id'] ) === 'draft' );
	$tail = documentsPartialCredit( $appData, $base['id'], 'Rest', '-1', '40' );
	check( '...a credit note of the 40 that are left is taken, and then the invoice is cancelled: the credit notes together took all of it back', is_array( Documents::finalise( $appData, $tail['id'] ) ) && $state( $base['id'] ) === 'cancelled' );
	$fifty = $invoice( [ $line( '1', '100' ) ] );
	$halves = [ documentsPartialCredit( $appData, $fifty['id'], 'Hälfte', '-1', '50' ), documentsPartialCredit( $appData, $fifty['id'], 'Hälfte', '-1', '50' ) ];
	Documents::finalise( $appData, $halves[0]['id'] );
	$afterFirst = $state( $fifty['id'] );
	Documents::finalise( $appData, $halves[1]['id'] );
	check( 'two credit notes of 50 on an invoice of 100 cancel it with the second, not with the first; a cancelled invoice is neither sent nor paid', $afterFirst === 'open' && $state( $fifty['id'] ) === 'cancelled' && Documents::setState( $appData, $fifty['id'], 'sent' ) === 'state' && Documents::setState( $appData, $fifty['id'], 'paid' ) === 'state' );

	// A credit note that is positive when it is finalised (a draft saved under another rule) is no credit note
	$positive = (array) Documents::creditOf( $appData, $hundred['id'] );
	Documents::change( $appData, $positive['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	Db::exec( $appData, 'UPDATE document_lines SET qty = 1000 WHERE document_id = :id', [ 'id' => $positive['id'] ] );
	check( '...and one whose stored lines are positive is refused when it is finalised, whatever was saved: credit_lines', Documents::finalise( $appData, $positive['id'] ) === 'credit_lines' && $state( $positive['id'] ) === 'draft' );
	Documents::remove( $appData, $positive['id'] );
	$discounted = $invoice( [ $line( '1', '100' ), $line( '1', '-20', 0, 'Rabatt' ) ] );
	$discountOnly = (array) Documents::creditOf( $appData, $discounted['id'] );
	$giveBack = Documents::change( $appData, $discountOnly['id'], [ 'lines' => [ $line( '1', '20', 0, 'Rabatt' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	check( '...a discount of the invoice can be given back as a positive line, but a credit note that comes to 0 or more is no credit note: credit_lines when it is finalised', is_array( $giveBack ) && Documents::finalise( $appData, $discountOnly['id'] ) === 'credit_lines' && $state( $discountOnly['id'] ) === 'draft' );
	Documents::remove( $appData, $discountOnly['id'] );

	// the client of a credit note is its invoice's
	$other = \Crm\Clients::add( $appData, [ 'company' => 'Andere GmbH', 'name' => 'Ana Andere', 'street' => 'Weg 1', 'zip' => '20095', 'city' => 'Hamburg', 'email' => 'ana@books.example' ] );
	$bound = (array) Documents::creditOf( $appData, $hundred['id'] );
	Documents::change( $appData, $bound['id'], [ 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	[ $status, $body ] = $post( 'crm-documents/save', [ 'id' => (string) $bound['id'], 'client_id' => (string) $other['id'] ] );
	check( 'a credit note draft cannot be moved to another client: client, the action 400 crm_documents_client at the field client_id; its own client is accepted', Documents::change( $appData, $bound['id'], [ 'client_id' => $other['id'] ] ) === 'client' && $status === 400 && $body['code'] === 'crm_documents_client' && $body['field'] === 'client_id'
		&& Documents::get( $appData, $bound['id'] )['client_id'] === $clientId && is_array( Documents::change( $appData, $bound['id'], [ 'client_id' => $clientId ] ) ) );
	Db::exec( $appData, 'UPDATE documents SET client_id = :client WHERE id = :id', [ 'client' => $other['id'], 'id' => $bound['id'] ] );
	check( '...and one that was moved behind the model\'s back is not finalised: credit_parent', Documents::finalise( $appData, $bound['id'] ) === 'credit_parent' );
	Documents::remove( $appData, $bound['id'] );

	// a date is a date, to the last byte
	$dates = [];

	foreach( [ 'date', 'due_date', 'valid_until', 'period_from', 'period_to' ] as $field )
		foreach( [ "2026-01-01\0", "\0", "2026-01-01 ", "2026-01-011", "2026-1-01", "２０２６-01-01" ] as $text )
			$dates[] = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $clientId, $field => $text ] )[0] === 400;

	check( 'a date with a null byte, a stray byte or a different shape is 400 in each of the five date fields, not a failure of the server: '. count( $dates ). ' posts', array_unique( $dates ) === [ true ] && Documents::validDate( "2026-01-01\0" ) === false && Documents::validDate( "2026-01-01\n" ) === false && Documents::validDate( '2026-01-01' ) === true );
	check( '...and the days of the transition still hold: the years 2000 to 2999 and no 30th of February', Documents::validDate( '2000-01-01' ) === true && Documents::validDate( '2999-12-31' ) === true && Documents::validDate( '3000-01-01' ) === false && Documents::validDate( '2026-02-30' ) === false );

	// the days of sending and payment
	$sheet = $invoice( [ $line( '1', '10' ) ], [ 'date' => date( 'Y-m-d', strtotime( '-10 days' ) ) ] );
	$day = static fn( int $offset ): string => date( 'Y-m-d', strtotime( sprintf( '%+d days', $offset ) ) );
	[ $status, $body ] = $post( 'crm-documents/state', [ 'id' => (string) $sheet['id'], 'state' => 'sent', 'date' => $day( -11 ) ] );
	check( 'the day of sending is not before the document, not to come: 400 crm_documents_date_range at the field date, and the state stays', $status === 400 && $body['code'] === 'crm_documents_date_range' && $body['field'] === 'date' && Documents::setState( $appData, $sheet['id'], 'sent', $day( 2 ) ) === 'daterange'
		&& Documents::setState( $appData, $sheet['id'], 'sent', '2999-01-01' ) === 'daterange' && $state( $sheet['id'] ) === 'open' );
	Documents::setState( $appData, $sheet['id'], 'sent', $day( -5 ) );
	check( 'the day of payment is not before the day of sending, not before the document, not to come; a good day is taken', Documents::setState( $appData, $sheet['id'], 'paid', $day( -6 ) ) === 'daterange' && Documents::setState( $appData, $sheet['id'], 'paid', $day( -11 ) ) === 'daterange'
		&& Documents::setState( $appData, $sheet['id'], 'paid', '2999-12-31' ) === 'daterange' && $state( $sheet['id'] ) === 'sent' && ( Documents::setState( $appData, $sheet['id'], 'paid', $day( -5 ) )['paid_at'] ?? '' ) === $day( -5 ). 'T00:00:00Z' );

	// an invoice claims something
	$negative = (array) Documents::add( $appData, 'invoice', $clientId, [ 'lines' => [ $line( '1', '-100' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	$zero = (array) Documents::add( $appData, 'invoice', $clientId, [ 'lines' => [ $line( '1', '0' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] );
	$before = $sequences();
	[ $status, $body ] = $post( 'crm-documents/finalise', [ 'id' => (string) $negative['id'] ] );
	check( 'an invoice below 0 and one of 0 are not finalised: 409 crm_documents_gross at the field lines, no number spent, both stay drafts', Documents::finalise( $appData, $negative['id'] ) === 'gross' && Documents::finalise( $appData, $zero['id'] ) === 'gross' && $status === 409 && $body['code'] === 'crm_documents_gross' && $body['field'] === 'lines'
		&& $sequences() === $before && $state( $negative['id'] ) === 'draft' && $state( $zero['id'] ) === 'draft' );
	check( '...an offer of 0 is none of that business', is_array( Documents::finalise( $appData, (int) Documents::add( $appData, 'offer', $clientId, [ 'lines' => [ $line( '1', '0' ) ], 'template_key' => 'angebot', 'inputs' => [ 'intro' => 'x' ] ] )['id'] ) ) );
	Documents::remove( $appData, $negative['id'] );
	Documents::remove( $appData, $zero['id'] );

	// the export in the order of the numbers within a day
	$drafts = [ (array) Documents::add( $appData, 'invoice', $clientId, [ 'date' => '2025-06-15', 'lines' => [ $line( '1', '5' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] ), (array) Documents::add( $appData, 'invoice', $clientId, [ 'date' => '2025-06-15', 'lines' => [ $line( '1', '5' ) ], 'template_key' => 'rechnung', 'inputs' => [ 'intro' => 'x' ] ] ) ];
	$later = (array) Documents::finalise( $appData, $drafts[1]['id'] );
	$earlier = (array) Documents::finalise( $appData, $drafts[0]['id'] );
	$ledger = array_column( array_filter( Documents::ledger( $appData, 2025 ), static fn( array $row ): bool => $row['date'] === '2025-06-15' ), 'number' );
	check( 'the ledger of a year is by day and, within a day, by number, not by the order the drafts were made in: the draft made first has the higher number here and comes second', $drafts[0]['id'] < $drafts[1]['id'] && $later['number'] < $earlier['number'] && array_slice( $ledger, -2 ) === [ $later['number'], $earlier['number'] ] );

	// a day whose due day is past the years there are
	$settingDays = Settings::get( $appData, 'payment_days' );
	[ $status, $body ] = $post( 'crm-documents/save', [ 'category' => 'invoice', 'client_id' => (string) $clientId, 'date' => '2999-12-31' ] );
	check( 'a document dated 2999-12-31 is refused at the date, not at a due day nobody typed: 400 crm_documents_date at the field date - and with a due day typed it is taken', $status === 400 && $body['code'] === 'crm_documents_date' && $body['field'] === 'date' && Documents::add( $appData, 'invoice', $clientId, [ 'date' => '2999-12-31' ] ) === 'date:date'
		&& is_array( Documents::add( $appData, 'invoice', $clientId, [ 'date' => '2999-12-31', 'due_date' => '2999-12-31' ] ) ) && Settings::get( $appData, 'payment_days' ) === $settingDays );
	Db::exec( $appData, 'DELETE FROM documents WHERE date = \'2999-12-31\'' );

	// A copy of a credit note would hang on no invoice
	check( 'a credit note is copied to nothing: state', Documents::copy( $appData, $first['id'] ) === 'state' );
}

/**
 *	The rows of the list carry the key of the PDF template a document was made with - the editor starts a new draft with the one the newest document of its client used - and ''
 *	for a document that has none
 *
 *	@param		array 		&$appData
 *	@param		\Closure	$post						Runs an action of the panel
 *
 *	@return 	void
 */
function documentsTemplateKeyChecks( array &$appData, \Closure $post ): void {

	$rows = Documents::list( $appData );
	$keys = array_column( $rows, 'template_key', 'id' );
	$stored = array_column( \Crm\Db::rows( $appData, 'SELECT id, template_key FROM documents' ), 'template_key', 'id' );
	$through = $post( 'crm-documents/list' );

	check( 'the rows of the list carry template_key, the key stored with each document, as text, '. count( $rows ). ' of them - and the action has them too', $rows !== [] && array_filter( $rows, static fn( array $row ): bool => is_string( $row['template_key'] ) === false ) === []
		&& $keys === array_map( 'strval', array_combine( array_keys( $keys ), array_map( static fn( int $id ): string => (string) $stored[$id], array_keys( $keys ) ) ) )
		&& $through[0] === 200 && array_diff( [ 'template_key' ], array_keys( $through[1]['documents'][0] ) ) === [] );

	$one = $rows[0]['id'];
	\Crm\Db::exec( $appData, 'UPDATE documents SET template_key = :key WHERE id = :id', [ 'key' => 'rechnung', 'id' => $one ] );

	$invoice = $post( 'crm-documents/templates', [ 'category' => 'invoice' ] );
	$credit = $post( 'crm-documents/templates', [ 'category' => 'credit' ] );
	$have = array_column( $invoice[1]['templates'] ?? [], 'key' );

	$stored = (array) \Nino\Modules\Pdf::templates( $appData, 'invoice' );

	check( '...the templates of a kind come with standard, the template Einrichten makes for the kind where the site has it - rechnung for an invoice, stornorechnung for a credit note - and an empty text where it has not (the credit note once that template is gone)',
		$invoice[0] === 200 && ( $invoice[1]['standard'] ?? null ) === 'rechnung' && isset( $stored['stornorechnung'] ) === true && ( $credit[1]['standard'] ?? null ) === 'stornorechnung' && in_array( 'stornorechnung', array_column( $credit[1]['templates'] ?? [], 'key' ), true ) === true
		&& ( $post( 'crm-documents/templates', [ 'category' => 'information' ] )[1]['standard'] ?? null ) === ( isset( \Nino\Modules\Pdf::templates( $appData, 'information' )['information'] ) === true ? 'information' : '' ) );

	check( '...a key the document has is in its row', array_column( Documents::list( $appData ), 'template_key', 'id' )[$one] === 'rechnung' );
}

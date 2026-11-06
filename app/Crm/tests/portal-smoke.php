<?php
declare(strict_types=1);

/**
 *	portal-smoke.php		Contract test for what the third patch of the project module \Crm\Crm adds around the portal: the release of
 *											a document with its mail to the accounts of the client (one mail each, the link to the page, no attachment,
 *											the cap of mails, an offer or an invoice that is sent by it), the request for a signature through Sign (the
 *											codes, the request Sign keeps, the page the member comes back to, the callbacks signed and declined, the
 *											state read back from Sign, a callback that cannot write), the component [crm-documents] for a member, a member
 *											of no client and a visitor (only the released documents of the client, newest first, the links, every value
 *											escaped, both languages), the route /.crm/pdf/<id> for a member (the client's released documents and nobody
 *											else's), the count on the card of a client and the answers of the panel. Features Members, Files, Pdf and Sign
 *											are active; the templates of Pdf are seeded the way its panel makes them. Runs over the kernel's tests/harness.php
 *											three levels up.
 *
 *	Usage: php app/Crm/tests/portal-smoke.php
 */

$root = dirname( __DIR__, 3 );
require $root. '/tests/harness.php';

use Crm\Db;
use Crm\Documents;

$appData = ninoSandbox( 'crm-portal' );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$today = date( 'Y-m-d' );

/**
 *	A request as the output answers it: served, the answer returned
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$method
 *	@param		array 		$post
 *	@param		array 		$server				More of what the server hands the request (HTTP_USER_AGENT, ...)
 *
 *	@return 	array										The response of the request
 */
function portalServe( array &$appData, string $uri, string $method = 'GET', array $post = [], array $server = [] ): array {

	// The form of the Datenschutz carries the hash of the text it showed: a post that does not say otherwise is the post of a form that showed the text of now
	if( $method === 'POST' && $uri === '/.crm/datenschutz' && array_key_exists( 'shown', $post ) === false )
		$post['shown'] = hash( 'sha256', \Crm\Settings::get( $appData, 'privacy_text' ) );

	// 'shown' => null is a post that does not carry the field at all
	if( array_key_exists( 'shown', $post ) === true && $post['shown'] === null )
		unset( $post['shown'] );

	$_POST = $post + ( $method === 'POST' ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method, $server );
	\Nino\Http::response( $appData, $request );
	$_POST = [];

	return $request['/nino/http/response'];
}

/**
 *	A page of the site as the output makes it: served, the fills of the request put in, the body rendered
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *
 *	@return 	array										[ status, html ]
 */
function portalPage( array &$appData, string $uri ): array {

	$request = ninoVisit( $appData, $uri );
	\Nino\Http::response( $appData, $request );
	\Nino\Html::addFills( $appData, \Nino\Html::requestFills( $request, '' ), '*' );

	return [ $request['/nino/http/response']['statusCode'], \Nino\Html::renderHtml( $appData, (string) ( $request['/nino/http/response']['body'] ?? '' ) ) ];
}

/**
 *	The navigation as the request for a path draws it: no page of the unit carries it, so it is the component on its own
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *
 *	@return 	string
 */
function portalNavAt( array &$appData, string $uri ): string {

	ninoVisit( $appData, $uri );

	return \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' );
}

/**
 *	The head of a page as the request for a path draws it: the component on its own, the bar of the application, the navigation and the head of the page
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$arguments			The arguments of the shortcode, with the space before the first
 *
 *	@return 	string
 */
function portalHeadAt( array &$appData, string $uri, string $arguments = '' ): string {

	ninoVisit( $appData, $uri );

	return \Nino\Html::renderHtml( $appData, '[crm-portal-head'. $arguments. ']' );
}

/**
 *	Sign a member in the way a member does: the link of a mail, then the button
 *
 *	@param		array 		&$appData
 *	@param		array 		$account				As Members has it
 *
 *	@return 	bool
 */
function portalSignIn( array &$appData, array $account ): bool {

	global $mails;

	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	$count = count( $mails );

	if( \Nino\Modules\Members::link( $appData, $account, 'login' ) !== true || count( $mails ) !== $count + 1 || preg_match( '~t=([A-Za-z0-9_-]+)"~', (string) portalMail( $count )['body'], $found ) !== 1 )
		return false;

	portalServe( $appData, '/.members/login?t='. $found[1] );

	return portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] )['statusCode'] === 303 && ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];
}

/**
 *	Whether a page still holds a fill of the portal that was not put in (the head and the footer of the sandbox's site have fills of their own that are not the module's)
 *
 *	@param		string		$html
 *
 *	@return 	bool
 */
function portalLeft( string $html ): bool {

	return preg_match( '~\[\[/(project/crm-|template/page-portal|_nino/webpage/portal)~', $html ) === 1;
}

/**
 *	The nodes of a page that an xpath finds
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	array										A list of \DOMElement
 */
function portalFind( string $html, string $query ): array {

	$document = new \DOMDocument();
	$was = libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="utf-8"?><body>'. $html. '</body>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $was );
	$nodes = ( new \DOMXPath( $document ) )->query( $query );

	return $nodes === false ? [] : iterator_to_array( $nodes );
}

/**
 *	The text of the first node an xpath finds, white space folded
 *
 *	@param		string		$html
 *	@param		string		$query
 *
 *	@return 	string										'' for none
 */
function portalText( string $html, string $query ): string {

	$nodes = portalFind( $html, $query );

	return $nodes === [] ? '' : trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $nodes[0]->textContent ) );
}

/**
 *	The mail the transport took at a position
 *
 *	@param		int				$index
 *
 *	@return 	array
 */
function portalMail( int $index ): array {

	global $mails;

	$mail = $mails->offsetGet( $index );

	return is_array( $mail ) === true ? $mail : [];
}

/**
 *	The mails the transport took from a position on
 *
 *	@param		int				$from
 *
 *	@return 	array										A list of mails
 */
function portalMailsFrom( int $from ): array {

	global $mails;

	$taken = [];

	for( $index = $from; $index < count( $mails ); $index++ )
		$taken[] = portalMail( $index );

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
$onMail = new \ArrayObject();
\Nino\Callbacks::registerCallback( $appData, \Nino\Mail::TRANSPORT, static function( array &$appData, array &$mail ) use ( &$mails, &$delivery, $onMail ): void {
	$mails->append( $mail );
	$mail['sent'] = $delivery;

	// What happens at the same time as a mail goes out: a second request, say
	foreach( $onMail as $run )
		$run();
} );

foreach( [ 'members', 'files', 'pdf', 'sign', 'review', 'events' ] as $key )
	check( 'the feature '. $key. ' is activated', \Nino\Features::activate( $appData, $key ) === true );

$listed = array_values( (array) \Nino\Filesystem::getFileContent( $appData, '/config.php', [] )['/nino/modules'] );
$listed[] = '\\Crm\\Crm';
$appData['/nino/modules'] = $listed;
\Nino\AppData::writeContentData( $appData, [ '/nino/modules' ] );
\Nino\Html::init( $appData );
\Nino\Modules::callModules( $appData, 'init' );

// The frames of the site and the look of its mails come from the wizard's base unit
$baseRoutes = [];
$baseBlacklist = [];
$baseConfig = [];
\Nino\Features::applyUnit( $appData, $root. '/_admin/install/library/base', [ 'de_DE', 'en_US' ], $baseRoutes, $baseBlacklist, $baseConfig, true );
$ignored = ninoWarnings();

$panel = static function( array &$appData, array &$request ): void {

	// The API of the clients and the documents is reached the way the surface's endpoint reaches it, by the table; the actions of the features' panels (pdf/...) go through the workbench
	$table = \Crm\Surface::actions();
	$action = (string) ( $_POST['action'] ?? '' );

	if( isset( $table[$action] ) === true ) {
		[ $class, $method ] = $table[$action];
		$class::{$method}( $appData, $request );
		return;
	}

	\Nino\Admin\Admin::handlePost( $appData, $request );
};
ninoUser( $appData, 'admin@example.com' );
$asAdmin = static function() use ( &$appData ): void {
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	\Nino\Auth::loginUser( $appData, 'admin@example.com', 'correct horse battery staple' );
};
$asVisitor = static function() use ( &$appData ): void {
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
};
$post = static function( string $action, array $data = [] ) use ( &$appData, $panel ): array {
	return ninoCall( $appData, $panel, $data, $action );
};
$reset = static function() use ( &$appData ): void {
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
};

[ $status, $body ] = $post( 'crm/setup' );
check( 'the portal pages are set up, the page Belege among them', $status === 200 && in_array( 'GET://portal/belege', $body['added']['routes'], true ) === true && in_array( 'page-portal-belege.tpl', $body['added']['templates'], true ) === true );
check( '...and the cap of mails is 100 then, in config.php and in the live data; the suite goes on at the kernel\'s default of 5, where the cap is tested', $body['added']['cap'] === 100 && \Nino\AppData::stored( $appData, '/nino/mail/cap', null ) === 100 && $appData['/nino/mail/cap'] === 100 );
$appData['/nino/mail/cap'] = \Nino\AppData::DEFAULTS['/nino/mail/cap'];

// The setup switches on every feature of the product and seeds the templates of Pdf; this suite starts with Review and Events off and makes its own templates
foreach( [ 'review', 'events' ] as $key )
	\Nino\Features::deactivate( $appData, $key );

foreach( [ 'angebot', 'rechnung', 'stornorechnung', 'vertrag', 'datenschutz', 'information' ] as $key )
	@unlink( \Nino\Filesystem::path( $appData, '/data/pdf/templates/'. $key. '.php' ) );

// --- Clients, accounts, templates, documents -----------------------------------------------------------------------------

$blocksOf = static fn( array $extra ): array => array_merge( [ [ 'type' => 'html', 'html' => '<h1>[[/document/title]] [[/document/number]]</h1><p>[[/customer/company]] [[/site/portal]]</p>' ], [ 'type' => 'slot', 'name' => 'lines' ] ], $extra );
$member = [ 'type' => 'signature', 'name' => 'kunde', 'party' => 'member', 'label' => 'Kunde', 'page' => 'last', 'x' => 20, 'y' => 230, 'w' => 60, 'h' => 20, 'line' => true ];
$input = [ 'type' => 'input', 'name' => 'zusatz', 'label' => 'Zusatz', 'multiline' => false, 'required' => true ];

foreach( [
	[ 'rechnung', 'invoice', $blocksOf( [] ) ], [ 'angebot', 'offer', $blocksOf( [] ) ], [ 'vertrag', 'contract', $blocksOf( [ $input, $member ] ) ], [ 'datenschutz', 'privacy', $blocksOf( [ $member ] ) ],
	[ 'datenschutz-ohne', 'privacy', $blocksOf( [] ) ], [ 'zugang', 'information', $blocksOf( [] ) ],
] as [ $key, $tag, $blocks ] )
	$post( 'pdf/templates/save', [ 'key' => $key, 'isNew' => true, 'name' => ucfirst( $key ), 'tag' => $tag, 'blocks' => $blocks ] );

$a = \Crm\Clients::add( $appData, [ 'company' => 'Studio Nord GmbH', 'name' => 'Anna Nord', 'street' => 'Hafenstr. 1', 'zip' => '20095', 'city' => 'Hamburg' ] );
$b = \Crm\Clients::add( $appData, [ 'name' => 'Ben Süd', 'street' => 'Lindenweg 3', 'zip' => '10115', 'city' => 'Berlin' ] );
$c = \Crm\Clients::add( $appData, [ 'company' => 'Ohne Konto AG' ] );
$accounts = [];

foreach( [ 'anna' => [ $a, 'Anna Nord' ], 'carl' => [ $a, 'Carl Nord' ], 'olga' => [ $a, 'Olga Alt' ], 'ben' => [ $b, 'Ben Süd' ] ] as $name => [ $client, $label ] ) {
	$account = \Nino\Modules\Members::add( $appData, $name. '@example.org', $label, $client['group'] );
	\Crm\Clients::link( $appData, $client['id'], $account['id'] );
	$accounts[$name] = $account;
}

\Nino\Modules\Members::change( $appData, $accounts['olga']['id'], [ 'active' => false ] );
$stray = \Nino\Modules\Members::add( $appData, 'stray@example.org', 'Stray', 'nogroup' );
check( 'the clients and their accounts: three for Studio Nord (one of them switched off), one for Ben, a member of no client', count( \Crm\Clients::accounts( $appData, $a['id'] ) ) === 3 && count( \Crm\Clients::accounts( $appData, $b['id'] ) ) === 1
	&& \Nino\Modules\Members::account( $appData, $accounts['olga']['id'] )['active'] === false && \Crm\Clients::ofMember( $appData, $stray['id'] ) === null );

$line = static fn( string $title ): array => [ 'title' => $title, 'description' => '', 'unit' => 'Std.', 'qty' => '2', 'price' => '50', 'vat_rate' => 0 ];
$final = static function( string $category, int $clientId, array $input ) use ( &$appData ): array {
	$draft = Documents::add( $appData, $category, $clientId, $input );
	$done = is_array( $draft ) ? Documents::finalise( $appData, $draft['id'] ) : $draft;

	return is_array( $done ) ? $done : [ 'id' => 0, 'error' => $done ];
};

$offerA = $final( 'offer', $a['id'], [ 'date' => '2026-03-10', 'lines' => [ $line( 'Beratung' ) ], 'template_key' => 'angebot' ] );
$invoiceA = $final( 'invoice', $a['id'], [ 'date' => '2026-10-01', 'lines' => [ $line( 'Webdesign' ) ], 'template_key' => 'rechnung' ] );
$invoiceA2 = $final( 'invoice', $a['id'], [ 'date' => '2026-10-15', 'lines' => [ $line( 'Hosting' ) ], 'template_key' => 'rechnung' ] );
$contractA = $final( 'contract', 0, [ 'parent_id' => $offerA['id'], 'date' => '2026-10-21', 'title' => 'Dienstleistungsvertrag', 'template_key' => 'vertrag', 'inputs' => [ 'zusatz' => 'Laufzeit ein Jahr' ] ] );
$contractA2 = $final( 'contract', 0, [ 'parent_id' => $offerA['id'], 'date' => '2026-10-20', 'title' => 'Zusatzvertrag', 'template_key' => 'vertrag', 'inputs' => [ 'zusatz' => 'Zusatz' ] ] );
$privacyA = $final( 'privacy', $a['id'], [ 'date' => '2026-10-22', 'title' => '<b>[members-name]</b> & "Datenschutz"', 'template_key' => 'datenschutz' ] );
$privacyPlain = $final( 'privacy', $a['id'], [ 'date' => '2026-10-19', 'title' => 'Datenschutz ohne Feld', 'template_key' => 'datenschutz-ohne' ] );
$privacyT = $final( 'privacy', $a['id'], [ 'date' => '2026-10-18', 'title' => 'Datenschutz Zwei', 'template_key' => 'datenschutz' ] );
$contractA3 = $final( 'contract', 0, [ 'parent_id' => $offerA['id'], 'date' => '2026-10-17', 'title' => 'Dritter Vertrag', 'template_key' => 'vertrag', 'inputs' => [ 'zusatz' => 'Drei' ] ] );
$infoA = $final( 'information', $a['id'], [ 'date' => '2026-10-23', 'title' => 'Zugang', 'template_key' => 'zugang' ] );
$draftA = (array) Documents::add( $appData, 'information', $a['id'], [ 'title' => 'Entwurf', 'template_key' => 'zugang' ] );
$invoiceB = $final( 'invoice', $b['id'], [ 'date' => '2026-10-02', 'lines' => [ $line( 'Anderes' ) ], 'template_key' => 'rechnung' ] );
$infoB = $final( 'information', $b['id'], [ 'date' => '2026-10-24', 'title' => 'Zugang Ben', 'template_key' => 'zugang' ] );
$invoiceC = $final( 'invoice', $c['id'], [ 'date' => '2026-10-03', 'lines' => [ $line( 'Nichts' ) ], 'template_key' => 'rechnung' ] );

check( 'the documents are finalised: an offer, two invoices, two contracts, two privacy documents, an information one - of Studio Nord - and the ones of the others', array_filter( [ $offerA, $invoiceA, $invoiceA2, $contractA, $contractA2, $contractA3, $privacyA, $privacyPlain, $privacyT, $infoA, $invoiceB, $infoB, $invoiceC ], static fn( array $doc ): bool => isset( $doc['error'] ) === true ) === []
	&& $contractA['client_id'] === $a['id'] && $contractA['number'] === '' && $invoiceA['number'] === 'R-2026-0001' );

// --- Release and the mail ------------------------------------------------------------------------------------------------

echo "\nRelease with a mail\n";

$reset();
$before = count( $mails );
check( 'a draft is not released with a mail either: "state", and nobody is mailed about it', Documents::release( $appData, $draftA['id'], true ) === 'state' && count( $mails ) === $before && Documents::get( $appData, $draftA['id'] )['released'] === false );
$released = Documents::release( $appData, $invoiceA['id'], true );
$sent = portalMailsFrom( $before );

check( 'releasing an invoice with the mail mails each active account of its client once: two mails, not the account that is switched off, not the accounts of another client', is_array( $released ) && $released['mailed'] === 2 && array_column( $sent, 'to' ) === [ 'anna@example.org', 'carl@example.org' ] );
check( '...the link in each is the absolute address of the page Belege, and the greeting names the account', str_contains( $sent[0]['body'], 'href="https://example.org/club/portal/belege"' ) === true && str_contains( $sent[0]['body'], 'Hallo Anna Nord,' ) === true && str_contains( $sent[1]['body'], 'Hallo Carl Nord,' ) === true );
check( '...the subject names the kind and the number, the body the same, and nothing is attached: the body holds no file name, the mail no attachment', $sent[0]['subject'] === 'Neues Dokument für Dich: Rechnung: '. $invoiceA['number'] && str_contains( $sent[0]['body'], 'Rechnung: '. $invoiceA['number'] ) === true
	&& str_contains( $sent[0]['body'], '.pdf' ) === false && array_diff( array_keys( $sent[0] ), [ 'to', 'subject', 'body', 'replyTo', 'sender', 'headers', 'sent' ] ) === [] );
check( '...in German with Du, from the template: no token and no fill of it is left in the body, the reply goes to the owner', str_contains( $sent[0]['body'], '[[' ) === false && str_contains( $sent[0]['body'], 'Melde Dich im Kundenportal an' ) === true && str_contains( $sent[0]['body'], 'Viele Grüße' ) === true && $sent[0]['replyTo'] === 'owner@example.org' );
check( 'the mail is the sending: the invoice that was open is sent, with the day, and is released with its day', $released['document']['state'] === 'sent' && $released['document']['sent_at'] !== null && $released['document']['released'] === true && $released['document']['released_at'] !== null );

$reset();
$before = count( $mails );
$offerReleased = Documents::release( $appData, $offerA['id'], true );
check( 'an offer is sent by it, too', is_array( $offerReleased ) && $offerReleased['document']['state'] === 'sent' && count( $mails ) === $before + 2 );
$reset();
$contractReleased = Documents::release( $appData, $contractA['id'], true );
check( 'a contract is not sent by it: it has the steps of its own; it is released and mailed', $contractReleased['mailed'] === 2 && $contractReleased['document']['state'] === 'open' && $contractReleased['document']['released'] === true );
check( '...the mail names the contract by its title and its kind', str_contains( portalMail( count( $mails ) - 1 )['subject'], 'Vertrag: Dienstleistungsvertrag' ) === true );

$reset();
$before = count( $mails );
$silent = Documents::release( $appData, $invoiceA2['id'], false );
check( 'without the tick nobody is mailed, and an invoice that is open stays open', is_array( $silent ) && $silent['mailed'] === 0 && count( $mails ) === $before && $silent['document']['state'] === 'open' && $silent['document']['released'] === true );
$reset();
Db::exec( $appData, 'UPDATE documents SET released_at = \'2026-01-01T00:00:00Z\' WHERE id = :id', [ 'id' => $invoiceA2['id'] ] );
$sendNow = Documents::release( $appData, $invoiceA2['id'], true );
check( '...and Versenden afterwards, which is the release with the mail, sends it', $sendNow['mailed'] === 2 && $sendNow['document']['state'] === 'sent' && $sendNow['document']['released_at'] === '2026-01-01T00:00:00Z' );
Documents::unrelease( $appData, $invoiceA2['id'] );

$reset();
$before = count( $mails );
$nobody = Documents::release( $appData, $invoiceC['id'], true );
check( 'a client with no account is mailed to nobody: released, mailed 0, the invoice stays open - nothing was sent', is_array( $nobody ) && $nobody['mailed'] === 0 && count( $mails ) === $before && $nobody['document']['released'] === true && $nobody['document']['state'] === 'open' );

$reset();
$delivery = false;
$ignored = ninoWarnings();
$refusing = Documents::release( $appData, $invoiceB['id'], true );
$delivery = true;
check( 'where the transport refuses the mails the document is released all the same, nothing counts as delivered, the invoice stays open and the log says so', is_array( $refusing ) && $refusing['mailed'] === 0 && $refusing['document']['released'] === true && $refusing['document']['state'] === 'open'
	&& str_contains( implode( ' ', ninoWarnings() ), 'were not all delivered' ) === true );
Documents::unrelease( $appData, $invoiceB['id'] );

// the cap of mails: five calls an hour for one address, one call for all the mails of a release
$reset();
$before = count( $mails );

for( $n = 0; $n < 5; $n++ )
	$fine = Documents::release( $appData, $invoiceA['id'], true );

check( 'the mails of a release are one call of the cap: five releases with two mails each fit in an hour', $fine['mailed'] === 2 && count( $mails ) === $before + 10 );
$limited = Documents::release( $appData, $infoA['id'], true );
check( '...the sixth is refused by the cap: "limit", nothing mailed, and nothing changed - the document is not released', $limited === 'limit' && count( $mails ) === $before + 10 && Documents::get( $appData, $infoA['id'] )['released'] === false );

$asAdmin();
[ $status, $body ] = $post( 'crm-documents/release', [ 'id' => (string) $infoA['id'], 'mail' => true ] );
check( 'the panel answers it 429 crm_documents_limit, and the document is not released', $status === 429 && $body['code'] === 'crm_documents_limit' && Documents::get( $appData, $infoA['id'] )['released'] === false );

$reset();
$noAddress = $appData;
\Nino\Html::addFills( $noAddress, [ '[[/project/website/general/url]]' => '' ], '*' );
check( 'a site with no address cannot link: "address", nothing changed, no mail', Documents::release( $noAddress, $infoA['id'], true ) === 'address' && Documents::get( $appData, $infoA['id'] )['released'] === false );
[ $status, $body ] = $post( 'crm-documents/release', [ 'id' => (string) $infoA['id'], 'mail' => true ] );
check( '...and with an address the panel releases it and says how many were mailed', $status === 200 && $body['mailed'] === 2 && $body['mail'] === true && $body['document']['released'] === true );

// the title of a document is text in the mail
$reset();
$before = count( $mails );
Documents::release( $appData, $privacyA['id'], true );
$privacyMail = portalMail( $before );
check( 'a title with markup and a shortcode in it is text in the mail', str_contains( $privacyMail['body'], '<b>' ) === false && str_contains( $privacyMail['body'], '&lt;b&gt;&#91;members-name]&lt;/b&gt; &amp; &quot;Datenschutz&quot;' ) === true );

// --- The request for a signature ---------------------------------------------------------------------------------------------

echo "\nA request for a signature\n";

$reset();
$before = count( $mails );
$asked = Documents::requestSignature( $appData, $contractA['id'], $accounts['anna']['id'], 7 );
check( 'a contract asks an account of its client: the document keeps the request and says it is asked, and the account is mailed by Sign', is_array( $asked ) && $asked['mailed'] === true && preg_match( '/^[a-f0-9]{12}$/D', $asked['document']['sign_id'] ) === 1 && $asked['document']['sign_state'] === 'requested'
	&& $asked['document']['state'] === 'open' && count( $mails ) === $before + 1 && portalMail( $before )['to'] === 'anna@example.org' );
$request = \Nino\Modules\Sign::get( $appData, $asked['document']['sign_id'] );
check( '...Sign keeps the request of this document\'s template with the title, the account, the days and the page the member comes back to: /portal/belege', is_array( $request ) && $request['template'] === 'vertrag' && $request['title'] === 'Dienstleistungsvertrag' && $request['account'] === $accounts['anna']['id']
	&& $request['page'] === '/portal/belege' && $request['status'] === 'requested' && abs( $request['expires'] - ( time() + 7 * 86400 ) ) < 10 );
check( '...with the data the PDF was rendered with: the same tokens, slots and inputs', $request['data'] === \Nino\Modules\Sign\Store::data( Documents::dataOf( $appData, (array) Documents::get( $appData, $contractA['id'] ) ) ) && $request['data']['inputs'] === [ 'zusatz' => 'Laufzeit ein Jahr' ] );
check( '...and its mail leads back to the page', str_contains( portalMail( $before )['body'], 'return=%2Fportal%2Fbelege' ) === true );
check( 'the document that waits for a signature is not asked again: "pending", and Sign holds one request', Documents::requestSignature( $appData, $contractA['id'], $accounts['carl']['id'] ) === 'pending' && count( \Nino\Modules\Sign::requests( $appData ) ) === 1 );

$delivery = false;
$unsent = Documents::requestSignature( $appData, $contractA3['id'], $accounts['anna']['id'] );
$delivery = true;
check( 'a request whose mail the transport refused is made all the same, and the answer says it was not mailed', is_array( $unsent ) && $unsent['mailed'] === false && $unsent['document']['sign_state'] === 'requested' && \Nino\Modules\Sign::get( $appData, $unsent['document']['sign_id'] ) !== null );
\Nino\Modules\Sign::cancel( $appData, $unsent['document']['sign_id'] );

// two requests made at the same time: the one that writes its id first is the document's, the other is taken back
$before = count( \Nino\Modules\Sign::requests( $appData ) );
$onMail->append( static function() use ( &$appData, $contractA3 ): void {
	Db::exec( $appData, 'UPDATE documents SET sign_id = \'abcabcabcabc\', sign_state = \'viewed\' WHERE id = :id', [ 'id' => $contractA3['id'] ] );
} );
$raced = Documents::requestSignature( $appData, $contractA3['id'], $accounts['carl']['id'] );
$onMail->exchangeArray( [] );
$mine = array_values( array_filter( \Nino\Modules\Sign::requests( $appData ), static fn( array $request ): bool => $request['account'] === $accounts['carl']['id'] && $request['title'] === 'Dritter Vertrag' ) );
check( 'where another request got in while this one was made, the answer is "pending", the other request stays the document\'s and this one is cancelled in Sign', $raced === 'pending' && count( \Nino\Modules\Sign::requests( $appData ) ) === $before + 1 && count( $mine ) === 1 && $mine[0]['status'] === 'cancelled'
	&& Db::row( $appData, 'SELECT sign_id FROM documents WHERE id = :id', [ 'id' => $contractA3['id'] ] )['sign_id'] === 'abcabcabcabc' );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'\', sign_state = \'\' WHERE id = :id', [ 'id' => $contractA3['id'] ] );

$privacyAsked = Documents::requestSignature( $appData, $privacyA['id'], $accounts['carl']['id'] );
check( 'a privacy document asks the same, with the default days of Sign', is_array( $privacyAsked ) && abs( \Nino\Modules\Sign::get( $appData, $privacyAsked['document']['sign_id'] )['expires'] - ( time() + 14 * 86400 ) ) < 10 );

$refusals = [
	'a document nobody has'                 => [ 99999, $accounts['anna']['id'], null, 'unknown' ],
	'an invoice'                            => [ $invoiceA['id'], $accounts['anna']['id'], null, 'category' ],
	'an information document'               => [ $infoA['id'], $accounts['anna']['id'], null, 'category' ],
	'a draft'                               => [ $draftA['id'], $accounts['anna']['id'], null, 'category' ],
	'an account of another client'          => [ $contractA2['id'], $accounts['ben']['id'], null, 'account' ],
	'an account nobody has'                 => [ $contractA2['id'], 'aaaaaaaaaaaa', null, 'account' ],
	'an account that is switched off'       => [ $contractA2['id'], $accounts['olga']['id'], null, 'account' ],
	'days past 90'                          => [ $contractA2['id'], $accounts['anna']['id'], 91, 'days' ],
	'0 days'                                => [ $contractA2['id'], $accounts['anna']['id'], 0, 'days' ],
	'a template with no field for a member' => [ $privacyPlain['id'], $accounts['anna']['id'], null, 'field' ],
];

$requestsAtRefusals = count( \Nino\Modules\Sign::requests( $appData ) );

foreach( $refusals as $why => [ $id, $account, $days, $code ] )
	check( 'the request is refused - '. $why. ' ('. $code. ')', Documents::requestSignature( $appData, $id, $account, $days ) === $code );

Db::exec( $appData, 'UPDATE documents SET inputs_json = \'{}\' WHERE id = :id', [ 'id' => $contractA2['id'] ] );
check( 'a free field the template asks for that the document lacks is told by Sign: "sign:input"', Documents::requestSignature( $appData, $contractA2['id'], $accounts['anna']['id'] ) === 'sign:input' );
Db::exec( $appData, 'UPDATE documents SET inputs_json = :json WHERE id = :id', [ 'json' => '{"zusatz":"Zusatz"}', 'id' => $contractA2['id'] ] );
Db::exec( $appData, 'UPDATE documents SET state = \'draft\' WHERE id = :id', [ 'id' => $contractA2['id'] ] );
check( '...a document that is no longer open or sent is "state"', Documents::requestSignature( $appData, $contractA2['id'], $accounts['anna']['id'] ) === 'state' );
Db::exec( $appData, 'UPDATE documents SET state = \'open\' WHERE id = :id', [ 'id' => $contractA2['id'] ] );
check( '...and none of the refused requests made one in Sign or wrote on the document', count( \Nino\Modules\Sign::requests( $appData ) ) === $requestsAtRefusals && Documents::get( $appData, $contractA2['id'] )['sign_id'] === '' );

\Nino\Features::deactivate( $appData, 'sign' );
check( 'with Sign off a request is "sign" and the state of the documents is not read from it', Documents::requestSignature( $appData, $contractA2['id'], $accounts['anna']['id'] ) === 'sign' && Documents::get( $appData, $contractA['id'] )['sign_state'] === 'requested' );
\Nino\Features::activate( $appData, 'sign' );

// Sign is handed what the finalising rendered: the client as it was frozen, not as it is now
$contractF = $final( 'contract', 0, [ 'parent_id' => $offerA['id'], 'date' => '2026-10-16', 'title' => 'Eingefroren', 'template_key' => 'vertrag', 'inputs' => [ 'zusatz' => 'Frost' ] ] );
\Crm\Clients::change( $appData, $a['id'], [ 'city' => 'Kiel', 'street' => 'Neuer Weg 9' ] );
$frozenAsk = Documents::requestSignature( $appData, $contractF['id'], $accounts['anna']['id'] );
$frozenData = (array) \Nino\Modules\Sign::get( $appData, $frozenAsk['document']['sign_id'] )['data'];
$frozenRow = (array) Db::row( $appData, 'SELECT client_json FROM documents WHERE id = :id', [ 'id' => $contractF['id'] ] );
$frozenDocument = (array) Documents::get( $appData, $contractF['id'] );
$frozenDocument['offer'] = Documents::get( $appData, $offerA['id'] );
$madeWith = \Nino\Modules\Sign\Store::data( \Crm\Documents\Render::data( $appData, $frozenDocument, [], (array) json_decode( (string) $frozenRow['client_json'], true ) ) );
check( 'Sign is handed the client as it was frozen into the contract - Hamburg and Hafenstr. 1 - and not the client as it is now', is_array( $frozenAsk ) && $frozenData['tokens']['/customer/city'] === 'Hamburg' && $frozenData['tokens']['/customer/street'] === 'Hafenstr. 1'
	&& str_contains( $frozenData['slots']['address'], 'Hamburg' ) === true && str_contains( $frozenData['slots']['address'], 'Kiel' ) === false && str_contains( $frozenData['slots']['address'], 'Neuer Weg' ) === false );
check( '...all of it is what the finalising rendered with: the tokens, the slots and the inputs made again from the frozen client and the offer', $frozenData === $madeWith && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Kiel' );
\Crm\Clients::change( $appData, $a['id'], [ 'city' => 'Hamburg', 'street' => 'Hafenstr. 1' ] );

// Sign's index is read once for all the documents that wait, and a restored or unreadable index cancels nothing
$syncSource = (string) preg_replace( '~^.*?private static function syncSign\(~s', '', (string) file_get_contents( $root. '/app/Crm/Documents/Documents.php' ) );
$syncSource = (string) preg_replace( '~\n\t}\n.*$~s', '', $syncSource );
check( 'the state of the requests is read with one call of Sign::requests() and none of Sign::get(), whatever the number of documents that wait', substr_count( $syncSource, 'Sign::requests(' ) === 1 && substr_count( $syncSource, 'Sign::get(' ) === 0 );
\Nino\Modules\Sign::cancel( $appData, $frozenAsk['document']['sign_id'] );
$offersAsked = $post( 'crm-documents/templates', [ 'category' => 'contract' ] );
check( 'the offers of a contract are asked for without reading Sign: the state of a request that was cancelled is not stored by it', $offersAsked[0] === 200 && Db::row( $appData, 'SELECT sign_state FROM documents WHERE id = :id', [ 'id' => $contractF['id'] ] )['sign_state'] === 'requested' );
$post( 'crm-documents/list' );
check( '...the list reads it', Db::row( $appData, 'SELECT sign_state FROM documents WHERE id = :id', [ 'id' => $contractF['id'] ] )['sign_state'] === 'cancelled' );
$again2 = Documents::requestSignature( $appData, $contractF['id'], $accounts['carl']['id'] );
$saved = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', [] );
check( 'an index of Sign that holds no request at all - restored empty, or unreadable - cancels none of the documents that wait', Documents::get( $appData, $contractF['id'] )['sign_state'] === 'requested' );
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $saved );
check( '...an index that is back tells the truth again', is_array( $again2 ) && Documents::get( $appData, $contractF['id'] )['sign_state'] === 'requested' );
\Nino\Modules\Sign::cancel( $appData, $again2['document']['sign_id'] );

// the state is read back from Sign
echo "\nThe state of the request\n";

$signId = $asked['document']['sign_id'];
\Nino\Modules\Sign::cancel( $appData, $signId );
$cancelled = Documents::get( $appData, $contractA['id'] );
check( 'a request the owner cancelled in Sign is read when the document is read: stored as cancelled, the document stays open', $cancelled['sign_state'] === 'cancelled' && $cancelled['state'] === 'open' && Db::row( $appData, 'SELECT sign_state FROM documents WHERE id = :id', [ 'id' => $contractA['id'] ] )['sign_state'] === 'cancelled' );
check( '...the list shows it, too', array_column( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['id'] === $contractA['id'] ), 'sign_state' ) === [ 'cancelled' ] );

// the list is a reading, too: it shows what Sign says without the document having been read before
$listed = Documents::requestSignature( $appData, $contractA3['id'], $accounts['anna']['id'] );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$listed['document']['sign_id']]['status'] = 'viewed';
$index[$listed['document']['sign_id']]['viewed'] = '2026-10-20T07:00:00Z';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( 'a request the member has looked at reads as viewed, when the list is read and the document was not', array_column( array_filter( Documents::list( $appData ), static fn( array $row ): bool => $row['id'] === $contractA3['id'] ), 'sign_state' ) === [ 'viewed' ] );
\Nino\Modules\Sign::cancel( $appData, $listed['document']['sign_id'] );
Db::exec( $appData, 'UPDATE documents SET sign_state = \'requested\' WHERE id = :id', [ 'id' => $contractA3['id'] ] );
\Nino\Features::deactivate( $appData, 'sign' );
check( 'where Sign is off the stored state is shown as it is, however the request stands', Documents::get( $appData, $contractA3['id'] )['sign_state'] === 'requested' );
\Nino\Features::activate( $appData, 'sign' );
check( '...and once Sign is on, the next read says what it says', Documents::get( $appData, $contractA3['id'] )['sign_state'] === 'cancelled' );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'\', sign_state = \'\' WHERE id = :id', [ 'id' => $contractA3['id'] ] );

$again = Documents::requestSignature( $appData, $contractA['id'], $accounts['carl']['id'], 3 );
check( 'a document whose request was cancelled is asked again: a new request, of the other account', is_array( $again ) && $again['document']['sign_id'] !== $signId && $again['document']['sign_state'] === 'requested' && \Nino\Modules\Sign::get( $appData, $again['document']['sign_id'] )['account'] === $accounts['carl']['id']
	&& \Nino\Modules\Sign::get( $appData, $signId )['status'] === 'cancelled' );

$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$again['document']['sign_id']]['expires'] = time() - 5;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( 'a request whose time is up reads as expired, and is stored so', Documents::get( $appData, $contractA['id'] )['sign_state'] === 'expired' && Db::row( $appData, 'SELECT sign_state FROM documents WHERE id = :id', [ 'id' => $contractA['id'] ] )['sign_state'] === 'expired' );

$third = Documents::requestSignature( $appData, $contractA['id'], $accounts['anna']['id'] );
check( '...and the document is asked again', is_array( $third ) && $third['document']['sign_state'] === 'requested' );

// the owner reminds a request that ran out: it is good again, and the document reads so
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$third['document']['sign_id']]['expires'] = time() - 5;
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
$reset();
Documents::get( $appData, $contractA['id'] );
\Nino\Modules\Sign::remind( $appData, $third['document']['sign_id'] );
check( 'a request that ran out and was reminded in Sign reads as requested again', Documents::get( $appData, $contractA['id'] )['sign_state'] === 'requested' );

// a request Sign does not have any more is cancelled
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
unset( $index[$privacyAsked['document']['sign_id']] );
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( 'a request that is removed from Sign reads as cancelled', Documents::get( $appData, $privacyA['id'] )['sign_state'] === 'cancelled' );

// the callbacks
echo "\nThe callbacks of Sign\n";

$entry = (array) \Nino\Modules\Sign::get( $appData, $third['document']['sign_id'] );
check( 'the module listens to /sign/signed and /sign/declined', count( \Nino\Callbacks::registered( $appData, '/sign/signed' ) ) === 1 && count( \Nino\Callbacks::registered( $appData, '/sign/declined' ) ) === 1 );
$signedEntry = [ 'status' => 'signed', 'signed' => '2026-10-25T10:00:00Z' ] + $entry;
\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $signedEntry );
$signed = (array) Documents::get( $appData, $contractA['id'] );
check( 'a signing sets the document signed, with the day Sign gives, and the state of the request', $signed['state'] === 'signed' && $signed['sign_state'] === 'signed' && $signed['signed_at'] === '2026-10-25T10:00:00Z' && $signed['sign_id'] === $third['document']['sign_id'] );
check( '...a document that is signed is not asked again and takes no step', Documents::requestSignature( $appData, $contractA['id'], $accounts['anna']['id'] ) === 'state' && Documents::setState( $appData, $contractA['id'], 'sent' ) === 'state' );
\Nino\Callbacks::doCallbacks( $appData, '/sign/declined', $signedEntry );
check( '...and a later callback for the same request does not undo it', Documents::get( $appData, $contractA['id'] )['state'] === 'signed' );

$second = Documents::requestSignature( $appData, $contractA2['id'], $accounts['carl']['id'] );
$secondEntry = [ 'status' => 'declined', 'declined' => '2026-10-26T08:00:00Z' ] + (array) \Nino\Modules\Sign::get( $appData, $second['document']['sign_id'] );
\Nino\Callbacks::doCallbacks( $appData, '/sign/declined', $secondEntry );
$declined = (array) Documents::get( $appData, $contractA2['id'] );
check( 'a decline sets the document declined, with no signing day', $declined['state'] === 'declined' && $declined['sign_state'] === 'declined' && $declined['signed_at'] === null );

$strange = [ 'status' => 'signed', 'id' => 'not-an-id' ];
\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $strange );
$nameless = [ 'status' => 'signed', 'signed' => '2026-10-27T08:00:00Z' ];
\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $nameless );
$unknown = [ 'status' => 'signed', 'id' => 'ffffffffffff', 'signed' => '2026-10-27T08:00:00Z' ];
\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $unknown );
check( 'a callback with an id that is none, with no id at all or with one no document asked for changes nothing - the documents that never asked for a signature are not signed', Documents::get( $appData, $privacyA['id'] )['state'] === 'open' && Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE state = \'signed\'' )['n'] === 1 );

// a signing that Sign counted and this module did not hear of is made good by the next read
$healed = Documents::requestSignature( $appData, $privacyA['id'], $accounts['anna']['id'] );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$healed['document']['sign_id']]['status'] = 'signed';
$index[$healed['document']['sign_id']]['signed'] = '2026-10-28T09:00:00Z';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
$read = (array) Documents::get( $appData, $privacyA['id'] );
check( 'a request that Sign has signed while the callback was not heard makes the document signed when it is read', $read['state'] === 'signed' && $read['signed_at'] === '2026-10-28T09:00:00Z' );

// a callback that cannot write is a warning, not a failure of the signing
$tail = Documents::requestSignature( $appData, $privacyT['id'], $accounts['anna']['id'] );
Db::exec( $appData, 'CREATE TRIGGER no_follow BEFORE UPDATE ON documents BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
$ignored = ninoWarnings();
$tailEntry = [ 'status' => 'signed', 'signed' => '2026-10-29T09:00:00Z' ] + (array) \Nino\Modules\Sign::get( $appData, $tail['document']['sign_id'] );
$thrown = '';

try {
	\Nino\Callbacks::doCallbacks( $appData, '/sign/signed', $tailEntry );
}
catch( \Throwable $e ) {
	$thrown = $e->getMessage();
}

$warnings = implode( ' ', ninoWarnings() );
Db::exec( $appData, 'DROP TRIGGER no_follow' );
check( 'a database that refuses the write does not break the signing: a warning that names the request and no address, no exception, the document as it was', $thrown === '' && str_contains( $warnings, $tail['document']['sign_id'] ) === true && str_contains( $warnings, '@' ) === false
	&& Documents::get( $appData, $privacyT['id'] )['state'] === 'open' );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$tail['document']['sign_id']]['status'] = 'signed';
$index[$tail['document']['sign_id']]['signed'] = '2026-10-29T09:00:00Z';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( '...and the next read makes the document follow the request', Documents::get( $appData, $privacyT['id'] )['state'] === 'signed' );

// --- The component for a member ------------------------------------------------------------------------------------------

echo "\nThe component [crm-documents]\n";

$schema = \Nino\Modules\Components::components( $appData )['crm-documents'] ?? [];
check( 'the component is registered once: global, no source, a block, drawn as a div of the class crm-documents, in a section or a card, with an icon', ( $schema['group'] ?? '' ) === 'global' && ( $schema['source'] ?? '' ) === 'none' && ( $schema['preview'] ?? '' ) === 'block' && ( $schema['draws'] ?? '' ) === 'div .crm-documents'
	&& ( $schema['in'] ?? [] ) === [ 'section', 'card' ] && count( $schema['icon'] ?? [] ) === 5 && count( \Nino\Callbacks::registered( $appData, '/nino/html/shortcode/crm-documents' ) ) === 1 );
check( 'the style sheet of the portal is in the bundle of the site, once, and declares the type scale, the spacing and the surfaces in :where(:root)', count( array_keys( $appData['/nino/html/assets']['/.cache/style.css'] ?? [], '/app/Crm/Portal/assets/portal.css', true ) ) === 1
	&& preg_match( '/:where\(:root\)\s*\{[^}]*--crm-text-xs[^}]*--crm-text-xxl[^}]*--crm-1:[^}]*--crm-7:[^}]*--crm-surface:/s', (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' ) ) === 1 );
check( 'the script of the portal is in the bundle of the site, once, and the file is there', count( array_keys( $appData['/nino/html/assets']['/.cache/script.js'] ?? [], \Crm\Portal::SCRIPT, true ) ) === 1 && is_file( $root. \Crm\Portal::SCRIPT ) === true );
check( '...the list stands at the left of its column as the other lists of the page do: no auto margin on the sides', preg_match( '/\n\.crm-documents \{[^}]*margin: 0 0 var\(--crm-7\);/s', (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' ) ) === 1 );

$asVisitor();
$wordsDe = (array) require $root. '/app/Crm/Portal/text/de_DE.php';
$wordsEn = (array) require $root. '/app/Crm/Portal/text/en_US.php';
$emptyList = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'a visitor is told there are none: the empty state, an icon and the sentence', portalFind( $emptyList, '//div[contains(@class, "crm-documents--empty")]/div[contains(@class, "crm-empty")]/svg[@aria-hidden="true"]' ) !== []
	&& portalText( $emptyList, '//p[contains(@class, "crm-empty__text")]' ) === $wordsDe['[[/project/crm-documents/list/empty]]'] && portalFind( $emptyList, '//ul' ) === [] );
check( '...a visitor never reaches the page: it is behind the gate of Members', portalServe( $appData, '/portal/belege' )['statusCode'] === 401 );

check( 'a member of no client is told there are none, too', portalSignIn( $appData, $stray ) === true && str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'crm-documents--empty' ) === true );

// what is released to Studio Nord, and what is not
$asAdmin();
foreach( [ $offerA, $invoiceA, $contractA, $privacyA, $infoA, $contractA2 ] as $document )
	Documents::release( $appData, $document['id'], false );

Documents::unrelease( $appData, $contractA2['id'] );
Documents::release( $appData, $invoiceB['id'], false );
Documents::release( $appData, $infoB['id'], false );
Db::exec( $appData, 'UPDATE documents SET released = 1 WHERE id = :id', [ 'id' => $draftA['id'] ] );
$list = Documents::released( $appData, $a['id'] );
check( 'the released documents of a client: its own, no draft however it is flagged, no document of another client, no document that is not released - the newest first', array_column( $list, 'id' ) === [ $infoA['id'], $privacyA['id'], $contractA['id'], $invoiceA['id'], $offerA['id'] ] && array_column( Documents::released( $appData, $b['id'] ), 'id' ) === [ $infoB['id'], $invoiceB['id'] ] );

check( 'a member of the client is signed in', portalSignIn( $appData, $accounts['anna'] ) === true );
$html = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
$rows = preg_split( '/(?=<li class="crm-documents__row )/', $html );
array_shift( $rows );
$rowOf = static function( int $id ) use ( $rows ): string {
	foreach( $rows as $row )
		if( str_contains( $row, 'data-document="'. $id. '"' ) === true )
			return $row;
	return '';
};
$rowOfNow = static function( int $id ) use ( &$appData ): string {
	foreach( preg_split( '/(?=<li class="crm-documents__row )/', \Nino\Html::renderHtml( $appData, '[crm-documents]' ) ) ?: [] as $row )
		if( str_contains( $row, 'data-document="'. $id. '"' ) === true )
			return $row;
	return '';
};
check( 'the list has a row for each released document of the member\'s client, newest first, and none for anything else', count( $rows ) === 5 && array_map( static fn( string $row ): int => (int) preg_replace( '/^.*?data-document="(\d+)".*$/s', '$1', $row ), $rows ) === [ $infoA['id'], $privacyA['id'], $contractA['id'], $invoiceA['id'], $offerA['id'] ]
	&& str_contains( $html, 'data-document="'. $invoiceB['id']. '"' ) === false && str_contains( $html, 'data-document="'. $draftA['id']. '"' ) === false && str_contains( $html, 'data-document="'. $invoiceA2['id']. '"' ) === false );
$cellOf = static fn( string $row, string $class ): string => portalText( '<ul>'. $row. '</ul>', '//*[contains(concat(" ", normalize-space(@class), " "), " '. $class. ' ")]' );
$pillOf = static fn( string $row ): string => portalFind( '<ul>'. $row. '</ul>', '//span[contains(@class, "crm-documents__state")]/span[contains(@class, "crm-pill")]' )[0]->getAttribute( 'class' );
check( '...each says its title (its kind where it has none of its own), its number, its day as d.m.Y with the machine day, and its state as a pill', $cellOf( $rowOf( $invoiceA['id'] ), 'crm-documents__title' ) === $wordsDe['[[/project/crm-documents/category/invoice]]'] && $cellOf( $rowOf( $invoiceA['id'] ), 'crm-documents__number' ) === 'R-2026-0001'
	&& $cellOf( $rowOf( $invoiceA['id'] ), 'crm-documents__date' ) === '01.10.2026' && str_contains( $rowOf( $invoiceA['id'] ), 'datetime="2026-10-01"' ) === true
	&& $cellOf( $rowOf( $infoA['id'] ), 'crm-documents__title' ) === 'Zugang' && $cellOf( $rowOf( $infoA['id'] ), 'crm-documents__kind' ) === $wordsDe['[[/project/crm-documents/category/information]]'] && $cellOf( $rowOf( $infoA['id'] ), 'crm-pill' ) === $wordsDe['[[/project/crm-documents/state/open]]']
	&& $cellOf( $rowOf( $contractA['id'] ), 'crm-documents__title' ) === 'Dienstleistungsvertrag' && $cellOf( $rowOf( $contractA['id'] ), 'crm-documents__kind' ) === $wordsDe['[[/project/crm-documents/category/contract]]'] );
check( '...an offer past its day says it ran out, a document that is signed says it is signed - each in the tone of its state', $cellOf( $rowOf( $offerA['id'] ), 'crm-pill' ) === $wordsDe['[[/project/crm-documents/state/expired]]'] && str_contains( $pillOf( $rowOf( $offerA['id'] ) ), 'crm-pill--muted' ) === true
	&& $cellOf( $rowOf( $contractA['id'] ), 'crm-pill' ) === $wordsDe['[[/project/crm-documents/state/signed]]'] && str_contains( $pillOf( $rowOf( $contractA['id'] ) ), 'crm-pill--success' ) === true );
check( '...and has the download of the PDF: /.crm/pdf/<id>?download=1, a small secondary button with an icon that is hidden from a reader and the number for one', array_filter( [ $infoA, $privacyA, $contractA, $invoiceA, $offerA ], static fn( array $document ): bool => str_contains( $rowOf( $document['id'] ), 'href="/.crm/pdf/'. $document['id']. '?download=1"' ) === false
	|| portalFind( '<ul>'. $rowOf( $document['id'] ). '</ul>', '//a[contains(@class, "crm-documents__download") and contains(@class, "crm-btn--small")]/svg[@aria-hidden="true"]/following-sibling::span[contains(@class, "nino-sr-only")]' ) === [] ) === [] );
check( 'a document that is signed has the link to the signed file of Sign, by its request; the one that waits for the signature the link to the page of the request', str_contains( $rowOf( $contractA['id'] ), 'href="/.sign/pdf?r='. $signed['sign_id']. '&amp;kind=signed"' ) === true && str_contains( $rowOf( $contractA['id'] ), 'href="/.sign?r=' ) === false
	&& str_contains( $rowOf( $privacyA['id'] ), 'href="/.sign?r=' ) === false );

// what Sign said on the way back: ?sign=<request>&sign-state=signed|declined
$stateNote = static fn( string $page ): array => portalFind( $page, '//p[contains(@class, "crm-sign__state")]' );
$markedRows = static fn( string $page ): array => array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-document'). ':'. $row->getAttribute( 'data-sign-state' ), portalFind( $page, '//li[@data-sign-state]' ) );
$backSigned = portalPage( $appData, '/portal/belege?sign='. $signed['sign_id']. '&sign-state=signed' )[1];
$backDeclined = portalPage( $appData, '/portal/belege?sign='. $signed['sign_id']. '&sign-state=declined' )[1];
check( 'the way back from Sign says what happened, over the list and under the notes of the page: a note with role status for each of the two states, in the tone of it (success, muted), with an icon and its own words in the language of the page', count( $stateNote( $backSigned ) ) === 1 && count( $stateNote( $backDeclined ) ) === 1
	&& $stateNote( $backSigned )[0]->getAttribute( 'role' ) === 'status' && $stateNote( $backDeclined )[0]->getAttribute( 'role' ) === 'status' && str_contains( $stateNote( $backSigned )[0]->getAttribute( 'class' ), 'crm-note--success' ) === true && str_contains( $stateNote( $backDeclined )[0]->getAttribute( 'class' ), 'crm-note--muted' ) === true
	&& portalFind( $backSigned, '//p[contains(@class, "crm-sign__state")]/svg[@aria-hidden="true"]' ) !== [] && portalText( $backSigned, '//p[contains(@class, "crm-sign__state")]' ) === $wordsDe['[[/project/crm-portal/sign/state-signed]]'] && portalText( $backDeclined, '//p[contains(@class, "crm-sign__state")]' ) === $wordsDe['[[/project/crm-portal/sign/state-declined]]']
	&& $wordsDe['[[/project/crm-portal/sign/state-signed]]'] !== $wordsDe['[[/project/crm-portal/sign/state-declined]]'] && $wordsEn['[[/project/crm-portal/sign/state-signed]]'] !== $wordsDe['[[/project/crm-portal/sign/state-signed]]']
	&& strpos( $backSigned, 'crm-sign__state' ) < strpos( $backSigned, 'crm-documents__count' ) && portalLeft( $backSigned ) === false && $stateNote( $html ) === [] );
check( '...the row of that request is marked (data-sign-state) - the row of the document the request is of, and no other - and the rest of the list is as it was', $markedRows( $backSigned ) === [ $contractA['id']. ':signed' ] && $markedRows( $backDeclined ) === [ $contractA['id']. ':declined' ] && $markedRows( $html ) === []
	&& count( portalFind( $backSigned, '//li[contains(@class, "crm-documents__row")]' ) ) === count( portalFind( $html, '//li[contains(@class, "crm-documents__row")]' ) ) );
$alien = portalPage( $appData, '/portal/belege?sign='. str_repeat( 'a', 12 ). '&sign-state=signed' )[1];
check( '...a request that is none of this client\'s marks no row, and the state is still said once; a state without a request says it and marks nothing', $markedRows( $alien ) === [] && count( $stateNote( $alien ) ) === 1 && count( $stateNote( portalPage( $appData, '/portal/belege?sign-state=declined' )[1] ) ) === 1 && $markedRows( portalPage( $appData, '/portal/belege?sign-state=declined' )[1] ) === [] && $markedRows( portalPage( $appData, '/portal/belege?sign='. $signed['sign_id'] )[1] ) === [] );
$ignored = ninoWarnings();
$hostile = [
	'sign-state=unknown', 'sign-state=SIGNED', 'sign-state=', 'sign-state[]=signed', 'sign-state[a]=signed', 'sign-state=signed%00', 'sign-state=%3Cb%3Esigned%3C%2Fb%3E', 'sign-state=%5Bmembers-name%5D', 'sign-state=signed%20', 'sign-state=signed&sign=%3Cb%3Ex%3C%2Fb%3E', 'sign-state=signed&sign=%5Bmembers-name%5D', 'sign-state=signed&sign[]=x',
	'sign-state=signed&sign='. $signed['sign_id']. '%00', 'sign-state=signed&sign='. strtoupper( $signed['sign_id'] ), 'sign-state=signed&sign='. $signed['sign_id']. 'a', 'sign-state=signed&sign=..%2F..%2Fx',
];
$leaks = array_filter( $hostile, static function( string $query ) use ( &$appData, $stateNote, $markedRows ): bool {

	$page = portalPage( $appData, '/portal/belege?'. $query )[1];
	$said = count( $stateNote( $page ) );

	return $page === '' || str_contains( $page, '<b>' ) === true || str_contains( $page, '[members-name]' ) === true || str_contains( $page, '%00' ) === true || str_contains( $page, "\0" ) === true || $markedRows( $page ) !== [] || $said > 1 || ( $said === 1 && str_starts_with( $query, 'sign-state=signed' ) === false );
} );
check( '...nothing but the two words is a state, and nothing of the query is written into the page: an unknown word, another case, an empty one, a list, a null byte, markup and a bracket - as the state or as the request - say nothing, mark no row, and leave neither markup nor a shortcode behind', $leaks === [] && ninoWarnings() === [] );
check( '...what a hostile request names is not a row: an id that is the real one with a byte more, in upper case or with a null byte marks nothing, and the real one with a state that is none marks nothing', $markedRows( portalPage( $appData, '/portal/belege?sign-state=signed&sign='. $signed['sign_id']. 'a' )[1] ) === [] && $markedRows( portalPage( $appData, '/portal/belege?sign-state=signed&sign='. strtoupper( $signed['sign_id'] ) )[1] ) === []
	&& $markedRows( portalPage( $appData, '/portal/belege?sign-state=nothing&sign='. $signed['sign_id'] )[1] ) === [] && $stateNote( portalPage( $appData, '/portal/belege?sign-state=nothing&sign='. $signed['sign_id'] )[1] ) === [] );

// a request that waits for the signature
$waiting = Documents::requestSignature( $appData, $infoA['id'], $accounts['anna']['id'] );
check( 'a document that Sign takes no signature for has no link to it', $waiting === 'category' && str_contains( $rowOf( $infoA['id'] ), '/.sign' ) === false );
$waits = Documents::requestSignature( $appData, $contractA3['id'], $accounts['anna']['id'] );
Documents::release( $appData, $contractA3['id'], false );
$withLink = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'where a signature is asked for (requested, viewed) the row links the request of Sign for the member: /.sign?r=<id>', is_array( $waits ) && str_contains( $withLink, 'class="crm-documents__sign crm-btn crm-btn--primary crm-btn--small" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fbelege"' ) === true && str_contains( $withLink, '<span>'. $wordsDe['[[/project/crm-documents/list/sign]]']. '</span>' ) === true );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$waits['document']['sign_id']]['status'] = 'viewed';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( '...a request the member has looked at is still one that waits: the link stays', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'class="crm-documents__sign crm-btn crm-btn--primary crm-btn--small" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fbelege"' ) === true );
\Nino\Modules\Sign::cancel( $appData, $waits['document']['sign_id'] );
check( '...and a request that is cancelled in Sign is none, the first time the portal reads it', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'crm-documents__sign crm-btn' ) === false && array_column( Documents::released( $appData, $a['id'] ), 'sign_state', 'id' )[$contractA3['id']] === 'cancelled' );
Documents::unrelease( $appData, $contractA3['id'] );

// a request that Sign ended is no way to sign: no link, whatever the id says
Documents::release( $appData, $contractA2['id'], false );
Db::exec( $appData, 'UPDATE documents SET state = \'open\', sign_id = :sign, sign_state = \'cancelled\' WHERE id = :id', [ 'sign' => $again['document']['sign_id'], 'id' => $contractA2['id'] ] );
check( 'a document whose request is over (cancelled) has neither the link to sign nor the link to the signed file', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'data-document="'. $contractA2['id']. '"' ) === true && str_contains( $rowOfNow( $contractA2['id'] ), '/.sign' ) === false );
\Nino\Features::deactivate( $appData, 'sign' );
Db::exec( $appData, 'UPDATE documents SET sign_id = \'zzzzzzzzzzzz\', sign_state = \'requested\' WHERE id = :id', [ 'id' => $contractA2['id'] ] );
check( 'an id of a request that is no id (twelve characters, not hexadecimal) is no link, and no request is looked up while Sign is off', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'zzzzzzzzzzzz' ) === false );
\Nino\Features::activate( $appData, 'sign' );
Documents::unrelease( $appData, $contractA2['id'] );

// a name that is none of the names the module writes is left out, not put into a key
$weird = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
Db::exec( $appData, 'UPDATE documents SET category = \'Bad Category\' WHERE id = :id', [ 'id' => $infoA['id'] ] );
Db::exec( $appData, 'UPDATE documents SET state = \'bad state\' WHERE id = :id', [ 'id' => $privacyA['id'] ] );
$left = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'a document of a kind or in a state the module does not write is left out of the list, and nothing of it is in the markup', str_contains( $weird, 'data-document="'. $infoA['id']. '"' ) === true && str_contains( $left, 'data-document="'. $infoA['id']. '"' ) === false && str_contains( $left, 'data-document="'. $privacyA['id']. '"' ) === false
	&& str_contains( $left, 'Bad' ) === false && substr_count( $left, 'crm-documents__row' ) === substr_count( $weird, 'crm-documents__row' ) - 2 );
Db::exec( $appData, 'UPDATE documents SET category = \'information\' WHERE id = :id', [ 'id' => $infoA['id'] ] );
Db::exec( $appData, 'UPDATE documents SET state = \'signed\' WHERE id = :id', [ 'id' => $privacyA['id'] ] );

// the site in a directory: every address of the list has its prefix
$appData['/nino/dir'] = '/club';
$prefixed = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'on a site in a directory the download and the signature have the directory in front of their addresses', str_contains( $prefixed, 'href="/club/.crm/pdf/'. $invoiceA['id']. '?download=1"' ) === true && str_contains( $prefixed, 'href="/club/.sign/pdf?r='. $signed['sign_id']. '&amp;kind=signed"' ) === true && str_contains( $prefixed, 'href="/.crm' ) === false );
$appData['/nino/dir'] = '';

// every value is text
check( 'a title with markup and a shortcode in it is text in the list, and no fill of it is evaluated', str_contains( $rowOf( $privacyA['id'] ), '&lt;b&gt;&#91;members-name]&lt;/b&gt; &amp; &quot;Datenschutz&quot;' ) === true && str_contains( $html, '<b>' ) === false && str_contains( $html, 'Anna Nord' ) === false );
check( 'no word is left as a key and the list carries a class of its own where the shortcode gives one', str_contains( $html, '[[/project' ) === false && str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents class="wide"]' ), 'class="crm-documents wide"' ) === true );

// the page, in both languages
$page = portalPage( $appData, '/portal/belege' );
check( 'the page /portal/belege is served to the member with the list in it under the head of the page, and the navigation of the portal before the head', $page[0] === 200 && str_contains( $page[1], '[crm-documents]' ) === false && str_contains( $page[1], 'class="crm-documents"' ) === true && portalFind( $page[1], '//div[contains(@class, "crm-head")]/div/div/h2[contains(@class, "crm-head__title")]' ) !== []
	&& count( portalFind( $page[1], '//nav[contains(@class, "crm-nav")]' ) ) === 1 && strpos( $page[1], 'class="crm-nav' ) < strpos( $page[1], 'crm-head__title' ) && strpos( $page[1], 'crm-head__title' ) < strpos( $page[1], 'class="crm-documents"' ) );
$listed = portalFind( $page[1], '//li[contains(@class, "crm-documents__row")]' );
check( 'the number of documents stands over the list, as a number and the word for it - the count of the rows, once, before the table', preg_match( '/^(\d+) (\S+)$/u', portalText( $page[1], '//p[contains(@class, "crm-documents__count")]' ), $counted ) === 1 && (int) $counted[1] === count( $listed ) && count( $listed ) > 1 && $counted[2] === $wordsDe['[[/project/crm-portal/projects/document-many]]']
	&& count( portalFind( $page[1], '//p[contains(@class, "crm-documents__count")]' ) ) === 1 && strpos( $page[1], 'crm-documents__count' ) < strpos( $page[1], 'crm-documents__list' ) );
$single = \Nino\Html::renderHtml( $appData, \Crm\Portal::documentList( $appData, array_slice( Documents::released( $appData, $a['id'] ), 0, 1 ), '', true, null ) );
check( '...one document says it in the singular, and a list of documents of a project names its own count', portalText( $single, '//p[contains(@class, "crm-documents__count")]' ) === '1 '. $wordsDe['[[/project/crm-portal/projects/document-one]]'] && count( portalFind( $single, '//li[contains(@class, "crm-documents__row")]' ) ) === 1 );
$headCells = static fn( string $page ): array => array_map( static fn( \DOMElement $cell ): string => trim( $cell->textContent ), portalFind( $page, '//li[contains(@class, "crm-table__head")]/span' ) );
check( 'the list is a table of six columns of which the due day is no column: the head names the document, its project, the day, the amount and the state - no head cell for the due day or for the actions -, and a row has the due day as the cell after the amount, so that it stands in the line of the amount', $headCells( $page[1] ) === array_map( static fn( string $name ): string => $wordsDe['[[/project/crm-portal/column/'. $name. ']]'], [ 'document', 'project', 'date', 'amount', 'state' ] )
	&& array_diff( [ $wordsDe['[[/project/crm-portal/column/actions]]'] ], $headCells( $page[1] ) ) === [ $wordsDe['[[/project/crm-portal/column/actions]]'] ] && portalFind( $page[1], '//li[contains(@class, "crm-documents__row")][@data-category="invoice"]//span[contains(@class, "crm-documents__amount")]/following-sibling::span[1][contains(@class, "crm-documents__due")]' ) !== []
	&& portalFind( $page[1], '//li[contains(@class, "crm-documents__row")]//span[contains(@class, "crm-documents__due")]/preceding-sibling::span[1][not(contains(@class, "crm-documents__amount"))]' ) === [] );
check( '...the actions of a row that become icons from 1024px keep their words: the download, the e-invoice and the fold carry them as a title and as text for a reader, the signature stays a button with its words and no title', portalFind( $page[1], '//a[contains(@class, "crm-documents__download")][not(@title)]' ) === []
	&& portalFind( $page[1], '//a[contains(@class, "crm-documents__download")]/span[contains(@class, "nino-sr-only")]' ) !== [] && array_filter( portalFind( $page[1], '//a[contains(@class, "crm-documents__download")][contains(@href, "/.crm/pdf/")]' ), static fn( \DOMElement $link ): bool => $link->getAttribute( 'title' ) !== $wordsDe['[[/project/crm-documents/list/download]]'] ) === []
	&& array_filter( portalFind( $page[1], '//span[contains(@class, "crm-table__toggle")]' ), static fn( \DOMElement $toggle ): bool => in_array( $toggle->getAttribute( 'title' ), [ $wordsDe['[[/project/crm-portal/order/fold]]'], $wordsDe['[[/project/crm-portal/pay/fold]]'] ], true ) === false ) === []
	&& portalFind( $page[1], '//span[contains(@class, "crm-table__toggle")]/span[contains(@class, "nino-sr-only")]' ) !== [] && portalFind( $page[1], '//a[contains(@class, "crm-documents__sign")][@title]' ) === [] );
$appData['./nino/locales/current'] = 'en_US';
$english = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'in English the words are the English ones: the kind, the state as the customer has it (overdue for the invoice, signed), the download', portalText( $english, '//li[@data-document="'. $invoiceA['id']. '"]//span[contains(@class, "crm-documents__title")]' ) === $wordsEn['[[/project/crm-documents/category/invoice]]']
	&& str_starts_with( portalText( $english, '//li[@data-document="'. $invoiceA['id']. '"]//span[contains(@class, "crm-pill")]' ), $wordsEn['[[/project/crm-documents/state/overdue]]'] ) === true && portalText( $english, '//li[@data-document="'. $contractA['id']. '"]//span[contains(@class, "crm-pill")]' ) === $wordsEn['[[/project/crm-documents/state/signed]]']
	&& portalText( $english, '//li[@data-document="'. $invoiceA['id']. '"]//a[contains(@class, "crm-documents__download")]/span[1]' ) === $wordsEn['[[/project/crm-documents/list/download]]'] && $wordsEn['[[/project/crm-documents/list/download]]'] !== $wordsDe['[[/project/crm-documents/list/download]]'] && str_contains( $english, '[[/project' ) === false );
$appData['./nino/locales/current'] = 'de_DE';

// a client whose documents are all of kinds the module does not write is told there are none
Db::exec( $appData, 'UPDATE documents SET category = \'x y\' WHERE client_id = :c', [ 'c' => $b['id'] ] );
portalSignIn( $appData, $accounts['ben'] );
check( 'a list of which every row is left out is the sentence that there are none', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'crm-documents--empty' ) === true && str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), '<ul' ) === false );
Db::exec( $appData, 'UPDATE documents SET category = \'invoice\' WHERE id = :id', [ 'id' => $invoiceB['id'] ] );
Db::exec( $appData, 'UPDATE documents SET category = \'information\' WHERE id = :id', [ 'id' => $infoB['id'] ] );
$asAdmin();

// the words of the list
$used = [];

foreach( glob( $root. '/app/Crm/Portal/templates/*.tpl' ) ?: [] as $file )
	if( preg_match_all( '~\[\[(/project/crm-(?:documents|portal)/[a-z_/-]+)\]\]~', (string) file_get_contents( $file ), $found ) > 0 )
		$used = array_merge( $used, $found[1] );

check( 'the words of the list are the same keys in German and English, none empty, German with Du, none a key of a text of the project', array_keys( $wordsDe ) === array_keys( $wordsEn ) && array_filter( $wordsDe + $wordsEn, static fn( string $text ): bool => trim( $text ) === '' ) === []
	&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', $wordsDe ) ) !== 1 && count( $wordsDe ) === 155 && array_filter( array_keys( $wordsDe ), static fn( string $key ): bool => preg_match( '~^\[\[/project/crm-(documents/(category|state|list)|portal/[a-z]+(/[a-z]+)?(/[a-z_]+)?)/?[a-z-]*\]\]$~D', $key ) !== 1 ) === [] );
check( '...every word the templates ask for is there, and the kinds and the states are all there is', array_diff( array_map( static fn( string $key ): string => '[['. $key. ']]', $used ), array_keys( $wordsDe ) ) === []
	&& array_diff( array_map( static fn( string $category ): string => '[[/project/crm-documents/category/'. $category. ']]', Documents::CATEGORIES ), array_keys( $wordsDe ) ) === []
	&& array_diff( array_map( static fn( string $state ): string => '[[/project/crm-documents/state/'. $state. ']]', [ 'open', 'overdue', 'paid', 'cancelled', 'accepted', 'declined', 'signed', 'expired' ] ), array_keys( $wordsDe ) ) === [] );

// a client with nothing released
check( 'a member of a client with nothing released is told there are none', portalSignIn( $appData, $accounts['ben'] ) === true && ( Documents::unrelease( $appData, $invoiceB['id'] ) !== '' ) && ( Documents::unrelease( $appData, $infoB['id'] ) !== '' ) && str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'crm-documents--empty' ) === true );
$asAdmin();
Documents::release( $appData, $invoiceB['id'], false );
Documents::release( $appData, $infoB['id'], false );

// --- The route for a member ---------------------------------------------------------------------------------------------------

echo "\nThe route /.crm/pdf/<id> for a member\n";

$nothing = static fn( array $response ): bool => $response['statusCode'] === 404 && ( $response['file'] ?? null ) === null && $response['body'] === 'Not Found';

$signedIn = portalSignIn( $appData, $accounts['anna'] );
$served = portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] );
check( 'a member of the client is served a document released to it: 200, the file', $signedIn === true && $served['statusCode'] === 200 );
$invoiceFile = \Nino\Filesystem::path( $appData, (string) Documents::get( $appData, $invoiceA['id'] )['pdf_path'] );
check( '...it is the PDF written when the document was finalised, as a download under its number', ( $served['file'] ?? '' ) === $invoiceFile && $served['header']['Content-Type'] === 'application/pdf' && $served['header']['Content-Disposition'] === 'inline; filename="R-2026-0001.pdf"'
	&& $served['header']['Cache-Control'] === 'private, no-store' && portalServe( $appData, '/.crm/pdf/'. $invoiceA['id']. '?download=1' )['header']['Content-Disposition'] === 'attachment; filename="R-2026-0001.pdf"' );
$contractServed = portalServe( $appData, '/.crm/pdf/'. $contractA['id'] );
check( '...a document without a number is named by its file: contract-<id>.pdf', $contractServed['statusCode'] === 200 && $contractServed['header']['Content-Disposition'] === 'inline; filename="contract-'. $contractA['id']. '.pdf"' );

$others = [
	'a document of another client, released'  => $invoiceB['id'],
	'a document of the client that is not released' => $invoiceA2['id'],
	'a draft, however it is flagged'          => $draftA['id'],
	'a document nobody has'                   => 99999,
];

foreach( $others as $why => $id ) {
	$ignored = ninoWarnings();
	check( 'the same 404 for '. $why, $nothing( portalServe( $appData, '/.crm/pdf/'. $id ) ) === true && ninoWarnings() === [] );
}

check( '...and for an id that is no number, or a path past it', $nothing( portalServe( $appData, '/.crm/pdf/abc' ) ) === true && $nothing( portalServe( $appData, '/.crm/pdf/'. $invoiceA['id']. '/x' ) ) === true && $nothing( portalServe( $appData, '/.crm/pdf/01' ) ) === true );

Documents::release( $appData, $invoiceA2['id'], false );
check( 'releasing makes it reachable for the member at once', portalServe( $appData, '/.crm/pdf/'. $invoiceA2['id'] )['statusCode'] === 200 );
$asAdmin();
Documents::unrelease( $appData, $invoiceA2['id'] );
portalSignIn( $appData, $accounts['anna'] );
check( '...and taking it back makes it a 404 again', $nothing( portalServe( $appData, '/.crm/pdf/'. $invoiceA2['id'] ) ) === true );

$ignored = ninoWarnings();
file_put_contents( $invoiceFile, 'changed' );
check( 'a file that is not the one that was written is a 409 for a member as for the workbench, and the log names the number', portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] )['statusCode'] === 409 && str_contains( implode( ' ', ninoWarnings() ), 'R-2026-0001' ) === true );
$asAdmin();
$workbench = portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] );
check( '...the workbench is told the same', $workbench['statusCode'] === 409 );
Db::exec( $appData, 'UPDATE documents SET pdf_sha256 = :sha WHERE id = :id', [ 'sha' => hash_file( 'sha256', $invoiceFile ), 'id' => $invoiceA['id'] ] );

check( 'a member of no client is served nothing', portalSignIn( $appData, $stray ) === true && $nothing( portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] ) ) === true );
$asVisitor();
check( '...a visitor too', $nothing( portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] ) ) === true );
portalSignIn( $appData, $accounts['ben'] );
check( 'a member is served the documents of his own client and no other', portalServe( $appData, '/.crm/pdf/'. $invoiceB['id'] )['statusCode'] === 200 && $nothing( portalServe( $appData, '/.crm/pdf/'. $invoiceA['id'] ) ) === true );
$asAdmin();
check( 'the workbench keeps the route: any document with a file, released or not', portalServe( $appData, '/.crm/pdf/'. $invoiceA2['id'] )['statusCode'] === 200 && portalServe( $appData, '/.crm/pdf/'. $invoiceC['id'] )['statusCode'] === 200 && $nothing( portalServe( $appData, '/.crm/pdf/'. $draftA['id'] ) ) === true );

// --- The panel ------------------------------------------------------------------------------------------------------------------------

echo "\nThe panel\n";

\Nino\Features::saveSettings( $appData, 'sign', [ 'days' => 21 ] );
[ $status, $body ] = $post( 'crm-documents/get', [ 'id' => (string) $contractA['id'] ] );
check( 'get answers a contract with the accounts of its client with their state - the one that is switched off included, not active -, the days of Sign\'s setting and the addresses of the PDFs of the request', $status === 200
	&& array_column( $body['accounts'], 'active', 'email' ) === [ 'anna@example.org' => true, 'carl@example.org' => true, 'olga@example.org' => false ] && array_keys( $body['accounts'][0] ) === [ 'id', 'email', 'name', 'active' ] && $body['sign_days'] === 21
	&& $body['document']['sign_shown_url'] === '/.sign/pdf?r='. $signed['sign_id']. '&kind=shown' && $body['document']['sign_signed_url'] === '/.sign/pdf?r='. $signed['sign_id']. '&kind=signed' && $body['document']['sign_state'] === 'signed' );
$shown = portalServe( $appData, $body['document']['sign_shown_url'] );
check( '...the workbench account is served the PDF of the request by Sign (the permission of its panel)', $shown['statusCode'] === 200 && is_file( (string) ( $shown['file'] ?? '' ) ) === true && $shown['header']['Content-Type'] === 'application/pdf' );

[ $status, $body ] = $post( 'crm-documents/list' );
$byId = array_column( $body['documents'], null, 'id' );
check( 'list marks the documents: released, and the state of the request', $byId[$contractA['id']]['released'] === true && $byId[$contractA['id']]['sign_state'] === 'signed' && $byId[$invoiceA2['id']]['released'] === false && $byId[$invoiceA2['id']]['sign_state'] === '' && $byId[$infoA['id']]['title'] === 'Zugang' );

$reset();
Db::exec( $appData, 'UPDATE documents SET state = \'open\', sign_id = \'\', sign_state = \'\', signed_at = NULL WHERE id = :id', [ 'id' => $contractA2['id'] ] );
[ $status, $body ] = $post( 'crm-documents/sign', [ 'id' => (string) $contractA2['id'], 'account' => $accounts['carl']['id'], 'days' => '5' ] );
check( 'sign asks for the signature: 200, the document with its request, and whether Sign mailed the account - the request is of this document and this account', $status === 200 && $body['mailed'] === true && $body['document']['sign_state'] === 'requested'
	&& \Nino\Modules\Sign::get( $appData, $body['document']['sign_id'] )['account'] === $accounts['carl']['id'] && \Crm\Documents\Admin::log( 'crm-documents/sign', [] ) === 'Asked for a signature on document contract-'. $contractA2['id'] );
check( '...the PDFs of a request that waits are linked for the owner, the signed one is not there yet', ( $waitingView = $post( 'crm-documents/get', [ 'id' => (string) $contractA2['id'] ] )[1]['document'] )['sign_shown_url'] === '/.sign/pdf?r='. $waitingView['sign_id']. '&kind=shown' && $waitingView['sign_signed_url'] === '' );
[ $status, $body ] = $post( 'crm-documents/sign', [ 'id' => (string) $contractA2['id'], 'account' => $accounts['anna']['id'] ] );
check( '...a second request is 409 crm_documents_pending', $status === 409 && $body['code'] === 'crm_documents_pending' );
[ $status, $body ] = $post( 'crm-documents/sign', [ 'id' => (string) $privacyPlain['id'], 'account' => $accounts['anna']['id'] ] );
check( '...a template with no field for a member is 409 crm_documents_field, an account of another client 400 crm_documents_account, an information document 400 crm_documents_category, a signed document 409 crm_documents_state',
	$status === 409 && $body['code'] === 'crm_documents_field' && $post( 'crm-documents/sign', [ 'id' => (string) $privacyPlain['id'], 'account' => $accounts['ben']['id'] ] )[1]['code'] === 'crm_documents_account'
	&& $post( 'crm-documents/sign', [ 'id' => (string) $infoA['id'], 'account' => $accounts['anna']['id'] ] )[1]['code'] === 'crm_documents_category' && $post( 'crm-documents/sign', [ 'id' => (string) $contractA['id'], 'account' => $accounts['anna']['id'] ] )[1]['code'] === 'crm_documents_state' );

$delivery = false;
Db::exec( $appData, 'UPDATE documents SET sign_id = \'\', sign_state = \'\' WHERE id = :id', [ 'id' => $contractA3['id'] ] );
[ $status, $body ] = $post( 'crm-documents/sign', [ 'id' => (string) $contractA3['id'], 'account' => $accounts['anna']['id'] ] );
$delivery = true;
check( 'sign says when Sign could not mail the account: 200, mailed false - the request is made all the same', $status === 200 && $body['mailed'] === false && $body['document']['sign_state'] === 'requested' );
check( 'a document that is finalised comes with the accounts of its client - the signature asks one of them, the mail of a release reaches them - and a draft does not', count( $post( 'crm-documents/get', [ 'id' => (string) $invoiceA['id'] ] )[1]['accounts'] ) === 3 && $post( 'crm-documents/get', [ 'id' => (string) $draftA['id'] ] )[1]['accounts'] === []
	&& count( $post( 'crm-documents/get', [ 'id' => (string) $privacyA['id'] ] )[1]['accounts'] ) === 3 );

[ $status, $body ] = $post( 'crm-clients/get', [ 'id' => (string) $a['id'] ] );
$counts = Documents::counts( $appData, $a['id'] );
check( 'the card of a client counts its documents, all and released to the portal', $status === 200 && $body['client']['documents'] === $counts && $counts['total'] === (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE client_id = :c', [ 'c' => $a['id'] ] )['n']
	&& $counts['released'] === (int) Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE client_id = :c AND released = 1', [ 'c' => $a['id'] ] )['n'] && $counts['released'] >= 5 );
[ $status, $body ] = $post( 'crm-clients/save', [ 'id' => (string) $a['id'], 'city' => 'Kiel' ] );
check( '...a save answers the card with the count, a switch of active too; the list of clients does not carry it', $body['client']['documents'] === $counts && $post( 'crm-clients/active', [ 'id' => (string) $b['id'], 'active' => true ] )[1]['client']['documents']['total'] >= 2
	&& array_key_exists( 'documents', $post( 'crm-clients/list' )[1]['clients'][0] ) === false );
[ $status, $body ] = $post( 'crm-clients/get', [ 'id' => (string) $c['id'] ] );
check( '...a client with one document that is released says so', $body['client']['documents'] === [ 'total' => 1, 'released' => 1 ] );

// --- The areas of the portal ------------------------------------------------------------------------------------------------

echo "\nThe areas of the portal\n";

foreach( [ 'members', 'files', 'review', 'events', 'sign' ] as $key )
	\Nino\Features::activate( $appData, $key );

$asAdmin();
$store = '\Nino\Modules\Files\Store';
$made = static function( string $title, string $client, array $over = [] ) use ( &$appData ): string {
	$element = \Nino\Elements::insertElement( $appData, '/projekte/', [ 'title' => $title, 'client' => $client ] + $over, '*' );
	return is_array( $element ) === true ? \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ) : '';
};
$folderA = $store::addFolder( $appData, $a['group'], 'Album' );
$folderUp = $store::addFolder( $appData, $a['group'], 'Eingang' );
$folderB = $store::addFolder( $appData, $b['group'], 'Podcast' );
$put = static function( array $folder, string $name, string $ext, string $mime, bool $visible, array $by, string $created ) use ( &$appData, $store ): array {
	$id = bin2hex( random_bytes( 6 ) );
	@mkdir( dirname( (string) $store::bytes( $appData, $id, $ext ) ), 0775, true );
	file_put_contents( (string) $store::bytes( $appData, $id, $ext ), 'abcdef' );
	$entry = $store::addFile( $appData, $id, [ 'folder' => $folder['id'], 'name' => $name, 'ext' => $ext, 'size' => 6, 'mime' => $mime, 'sha256' => hash( 'sha256', 'abcdef' ), 'by' => $by, 'visible' => $visible, 'created' => $created ], 0 );

	return is_array( $entry ) === true ? $entry : [ 'id' => '' ];
};
$owner = [ 'kind' => 'owner', 'id' => '' ];
$byAnna = [ 'kind' => 'member', 'id' => $accounts['anna']['id'] ];
$byCarl = [ 'kind' => 'member', 'id' => $accounts['carl']['id'] ];
$ago = static fn( int $seconds ): string => gmdate( 'Y-m-d\TH:i:s\Z', time() - $seconds );
$song = $put( $folderA, 'Song.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 3600 ) );
$clip = $put( $folderA, 'Clip.mp4', 'mp4', 'video/mp4', true, $owner, $ago( 2 * 86400 ) );
$notes = $put( $folderA, 'Notizen.pdf', 'pdf', 'application/pdf', true, $owner, '2026-01-01T09:00:00Z' );
$secret = $put( $folderA, 'Geheim.mp3', 'mp3', 'audio/mpeg', false, $owner, $ago( 7200 ) );
$mine = $put( $folderA, 'Meins.wav', 'wav', 'audio/wav', false, $byAnna, $ago( 9000 ) );
$carls = $put( $folderA, 'Carl.mp3', 'mp3', 'audio/mpeg', false, $byCarl, $ago( 9100 ) );
$upVisible = $put( $folderUp, 'Eingang-sichtbar.txt', 'txt', 'text/plain', true, $owner, $ago( 600 ) );
$upOwn = $put( $folderUp, 'Eingang-eigen.txt', 'txt', 'text/plain', false, $byAnna, $ago( 300 ) );
$upCarl = $put( $folderUp, 'Eingang-carl.txt', 'txt', 'text/plain', false, $byCarl, $ago( 100 ) );
$bFile = $put( $folderB, 'Podcast.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 600 ) );

$p1 = $made( 'Album 2027', $a['number'], [ 'description' => "Aufnahme <b>und</b>\nMix [members-name]", 'started' => '2026-10-01', 'folder' => $folderA['id'] ] );
$p2 = $made( 'Webseite', $a['number'], [ 'done' => true ] );
$p4 = $made( 'Leer', $a['number'] );
$p3 = $made( 'Podcast B', $b['number'], [ 'folder' => $folderB['id'], 'started' => '2026-09-01' ] );
$ownProjects = (array) \Nino\Filesystem::getFileContent( $appData, '/elements/projekte.php', [] );

$regularLines = [
	[ 'title' => 'Mix <i>live</i>', 'description' => "Zwei Songs\nmit Master", 'unit' => 'Std.', 'qty' => '3', 'price' => '80', 'vat_rate' => 1900 ],
	[ 'title' => 'Booklet', 'description' => '', 'unit' => '', 'qty' => '1', 'price' => '100', 'vat_rate' => 700 ],
];
\Crm\Settings::set( $appData, 'vat_mode', 'regular' );
$offerRegular = $final( 'offer', $a['id'], [ 'date' => '2026-10-05', 'valid_until' => '2026-12-31', 'project' => $p1, 'template_key' => 'angebot', 'lines' => $regularLines ] );
$offerReverse = $final( 'offer', $a['id'], [ 'date' => '2026-10-04', 'valid_until' => '2099-12-31', 'project' => $p1, 'template_key' => 'angebot', 'reverse_charge' => true, 'lines' => [ $line( 'Beratung EU' ) ] ] );
$offerHidden = $final( 'offer', $a['id'], [ 'date' => '2026-10-06', 'project' => $p1, 'template_key' => 'angebot', 'lines' => [ $line( 'Nicht freigegeben' ) ] ] );
$offerOther = $final( 'offer', $a['id'], [ 'date' => '2026-10-07', 'project' => $p2, 'template_key' => 'angebot', 'lines' => [ $line( 'Andere Seite' ) ] ] );
$offerB = $final( 'offer', $b['id'], [ 'date' => '2026-10-08', 'project' => $p3, 'template_key' => 'angebot', 'lines' => [ $line( 'Fremdes Angebot' ) ] ] );
\Crm\Settings::set( $appData, 'vat_mode', 'small_business' );
$offerSmall = $final( 'offer', $a['id'], [ 'date' => '2026-10-03', 'valid_until' => '2099-12-31', 'project' => $p1, 'template_key' => 'angebot', 'lines' => [ $line( 'Kleinunternehmer' ) ] ] );
$invoiceP1 = $final( 'invoice', $a['id'], [ 'date' => '2026-10-10', 'project' => $p1, 'template_key' => 'rechnung', 'lines' => [ $line( 'Teil eins' ) ] ] );
$invoiceP2 = $final( 'invoice', $a['id'], [ 'date' => '2026-10-11', 'project' => $p2, 'template_key' => 'rechnung', 'lines' => [ $line( 'Teil zwei' ) ] ] );

foreach( [ $offerRegular, $offerReverse, $offerOther, $offerB, $offerSmall, $invoiceP1, $invoiceP2 ] as $document )
	Documents::release( $appData, $document['id'], false );

check( 'the fixtures: four projects (two open, one done, one of another client), a folder with six files, an upload folder, and offers - three released in the first project, one not - with an invoice', [ $p1, $p2, $p4, $p3 ] === [ '00001', '00002', '00003', '00004' ]
	&& array_filter( [ $song, $clip, $notes, $secret, $mine, $carls, $upVisible, $upOwn, $upCarl, $bFile ], static fn( array $file ): bool => $file['id'] === '' ) === []
	&& array_filter( [ $offerRegular, $offerReverse, $offerHidden, $offerOther, $offerB, $offerSmall, $invoiceP1, $invoiceP2 ], static fn( array $document ): bool => isset( $document['error'] ) === true ) === []
	&& Documents::get( $appData, $offerRegular['id'] )['vat_mode'] === 'regular' && Documents::get( $appData, $offerSmall['id'] )['vat_mode'] === 'small_business' && Documents::get( $appData, $offerReverse['id'] )['reverse_charge'] === true );

// --- The navigation ------------------------------------------------------------------------------------------------------------

echo "\n[crm-portal-nav]\n";

$unitDe = (array) require $root. '/app/Crm/Crm/install/text/de_DE.php';
$navLinks = '//nav[contains(@class, "crm-nav")]/ul[@class="crm-nav__list"]/li/a[@class="crm-nav__link"]';
$navAreas = [ '/portal', '/portal/projekte', '/portal/belege', '/portal/termine', '/portal/upload', '/portal/meine-daten' ];
$navHrefsOf = static fn( string $html ): array => array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( $html, $navLinks ) );
$asVisitor();
check( 'a visitor gets nothing of the navigation, and the head gives him nothing either', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ) ) === '' && trim( \Nino\Html::renderHtml( $appData, '[crm-portal-head]' ) ) === '' );
portalSignIn( $appData, $accounts['anna'] );
$nav = portalNavAt( $appData, '/portal/projekte' );
$links = portalFind( $nav, $navLinks );
check( 'a member gets the six areas as links, in the order Portal, Projekte, Belege, Termine, Upload, Meine Daten, each with an icon and the name of its page', count( $links ) === 6 && $navHrefsOf( $nav ) === $navAreas
	&& array_filter( $links, static fn( \DOMElement $link ): bool => trim( $link->textContent ) === '' || str_contains( $link->textContent, '[[' ) ) === []
	&& array_map( static fn( \DOMElement $link ): string => $link->getElementsByTagName( 'svg' )->item( 0 )?->getAttribute( 'class' ) ?? '', $links ) === [ 'crm-icon crm-icon--house', 'crm-icon crm-icon--folder', 'crm-icon crm-icon--file-text', 'crm-icon crm-icon--calendar', 'crm-icon crm-icon--upload', 'crm-icon crm-icon--user' ]
	&& count( portalFind( $nav, $navLinks. '/svg[@aria-hidden="true"]' ) ) === 6 );
check( '...the names are the names of the pages, the first the name of the page /portal', array_map( static fn( \DOMElement $link ): string => trim( $link->textContent ), $links ) === [ $unitDe['[[/_nino/webpage/portal/name]]'], $unitDe['[[/_nino/webpage/portal/projekte/name]]'], $unitDe['[[/_nino/webpage/portal/belege/name]]'], $unitDe['[[/_nino/webpage/portal/termine/name]]'], $unitDe['[[/_nino/webpage/portal/upload/name]]'], $unitDe['[[/_nino/webpage/portal/meine-daten/name]]'] ] );
check( '...the nav is a nav with a label, and the area the request is in is marked: aria-current="page" on that link and on no other', portalFind( $nav, '//nav[@aria-label]' ) !== [] && count( portalFind( $nav, '//a[@aria-current]' ) ) === 1
	&& portalFind( $nav, '//a[@aria-current]' )[0]->getAttribute( 'href' ) === '/portal/projekte' && portalFind( $nav, '//a[@aria-current]' )[0]->getAttribute( 'aria-current' ) === 'page' );
$marked = static fn( string $uri ): array => array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( portalNavAt( $appData, $uri ), '//nav//a[@aria-current="page"]' ) );
check( '...a prefix does it for the page of a project and for every query; the start marks itself on /portal alone; each area marks itself', $marked( '/portal/projekte?projekt='. $p1 ) === [ '/portal/projekte' ] && $marked( '/portal/projekte?review='. $song['id'] ) === [ '/portal/projekte' ] && $marked( '/portal' ) === [ '/portal' ]
	&& $marked( '/portal/meine-daten?state=saved' ) === [ '/portal/meine-daten' ] && $marked( '/portal/upload' ) === [ '/portal/upload' ] && $marked( '/portal/belege' ) === [ '/portal/belege' ] && $marked( '/portal/termine' ) === [ '/portal/termine' ] );
ninoVisit( $appData, '/portal/projekte/unterseite' );
check( '...a path below an area marks the area too, whatever the page is', array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ), '//a[@aria-current="page"]' ) ) === [ '/portal/projekte' ] );
check( '...a class of the shortcode is added to the nav', count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-nav class="wide"]' ), '//nav[@class="crm-nav wide"]' ) ) === 1 );
$bar = portalHeadAt( $appData, '/portal' );
$barNode = '//header[@class="crm-bar"]/div[@class="crm-bar__inner"]';
check( 'the head draws the bar of the application first, a header before the navigation: the name of the site as a link to the start page with the h1 of the page in it, the account as a chip that links to Meine Daten (the initials of the name - Anna Nord is AN - and the name), and Abmelden as an icon link to /.members/logout', count( portalFind( $bar, '//header[@class="crm-bar"]' ) ) === 1 && strpos( $bar, 'class="crm-bar"' ) < strpos( $bar, 'class="crm-nav' ) && strpos( $bar, 'class="crm-nav' ) < strpos( $bar, 'class="crm-head"' )
	&& count( portalFind( $bar, $barNode. '/a[@class="crm-bar__brand"][@href="/"]/h1[contains(@class, "nino-sr-only")]' ) ) === 1 && count( portalFind( $bar, '//h1' ) ) === 1 && count( portalFind( $bar, $barNode. '/a[contains(@class, "crm-account")][@href="/portal/meine-daten"]' ) ) === 1
	&& portalText( $bar, $barNode. '/a[contains(@class, "crm-account")]/span[@class="crm-account__avatar"][@aria-hidden="true"]' ) === 'AN' && portalText( $bar, $barNode. '/a[contains(@class, "crm-account")]//span[@class="crm-account__name"]' ) === 'Anna Nord'
	&& count( portalFind( $bar, $barNode. '/a[@class="crm-account"]/*' ) ) === 2 && portalFind( $bar, '//*[contains(@class, "crm-account__company") or contains(@class, "crm-account__text")]' ) === []
	&& count( portalFind( $bar, $barNode. '/a[@class="crm-account__logout"][@href="/.members/logout"]/svg[@aria-hidden="true"]' ) ) === 1 && portalText( $bar, $barNode. '/a[@class="crm-account__logout"]/span[contains(@class, "nino-sr-only")]' ) !== '' );
check( '...the navigation and the head of the page hold no chip and no Abmelden - the account is the bar\'s on every width', portalFind( $bar, '//nav[contains(@class, "crm-nav")]//a[contains(@class, "crm-account")] | //nav[contains(@class, "crm-nav")]//a[contains(@href, "logout")] | //div[@class="crm-head"]//a[contains(@class, "crm-account")] | //div[@class="crm-head"]//a[contains(@href, "logout")]' ) === []
	&& count( portalFind( $bar, '//a[contains(@href, "logout")]' ) ) === 1 && count( portalFind( $bar, '//a[@href="/portal/meine-daten"]' ) ) === 2 );
check( '...the bar is no one of the navigation: a visitor gets none, a template that sets the navigation itself ([crm-portal-head nav="0"]) gets none, the door has none', portalFind( portalHeadAt( $appData, '/portal', ' nav="0"' ), '//header | //nav' ) === [] && count( portalFind( portalHeadAt( $appData, '/portal', ' nav="0"' ), '//div[@class="crm-head"]' ) ) === 1
	&& str_contains( \Nino\Html::renderHtml( $appData, '[crm-portal-door]' ), 'crm-bar' ) === false && portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ), '//header' ) === [] );
check( '...there is no Datenschutz in it while nothing is asked, and no dot', in_array( '/portal/datenschutz', $navHrefsOf( portalNavAt( $appData, '/portal' ) ), true ) === false && portalFind( portalNavAt( $appData, '/portal' ), '//span[contains(@class, "crm-nav__dot")]' ) === [] );
check( 'initials: the first letter of the first and of the last word, in capitals; one word has one; an address has its first letter; what is no letter is not one; nothing is "?"', array_map( [ \Crm\Portal::class, 'initials' ], [ 'Anna Berger', 'anna@example.org', '  mia   de la cruz ', 'Éva', '<b>Zed</b> [x]', '[]', '', "e\u{301}mile Zöe", 'ßa Ünal', "Ab\xFFc Dd", "Anna B\xC3", "Anna Berger\xE2\x82" ] ) === [ 'AB', 'A', 'MC', 'É', 'BX', '?', '?', "E\u{301}Z", 'ßÜ', 'AD', 'AB', 'AB' ]
	&& \Crm\Portal::nameOf( [ 'name' => 'Anna Berger', 'email' => 'a@example.org' ] ) === 'Anna Berger' && \Crm\Portal::nameOf( [ 'name' => '', 'email' => 'a@example.org' ] ) === 'a@example.org' );
$brokenName = "<b>Zed</b>\0 [members-name]";
\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'name' => $brokenName ] );
$escaped = portalHeadAt( $appData, '/portal' );
check( 'the name of the account enters the bar as text - markup, a shortcode and a null byte in it are shown as typed (the byte not at all) - in the chip, and the initials are those of the letters', str_contains( $escaped, '<b>' ) === false && str_contains( $escaped, '[members-name]' ) === false && str_contains( $escaped, "\0" ) === false && str_contains( $escaped, '&lt;b&gt;Zed&lt;/b&gt; &#91;members-name]' ) === true
	&& portalText( $escaped, '//span[@class="crm-account__avatar"]' ) === 'BM' && str_contains( portalPage( $appData, '/portal' )[1], '<b>Zed' ) === false );
$siteName = '/project/company/general/name';
$siteWas = \Nino\Html::resolveTextfill( $appData, $siteName );
\Nino\Html::addFills( $appData, [ '[['. $siteName. ']]' => "Studio <b>Zed</b> [members-name] [[/nino/dir]] [x] &amp; \0Eins" ], '*' );
$named = portalHeadAt( $appData, '/portal' );
check( 'the name of the site - a text of the project, which the head of the site draws as markup - enters the bar as text: markup, a shortcode, a bracket and a null byte in it are shown as typed (an entity is the character it stands for, the byte not at all; a fill in it is resolved to its text, as the head of the site resolves it), as the name beside the logo and in the h1 of the page', str_contains( $named, '<b>' ) === false && str_contains( $named, '[members-name]' ) === false && str_contains( $named, "\0" ) === false && str_contains( $named, '[[' ) === false
	&& portalText( $named, '//span[@class="crm-bar__name"]' ) === 'Studio <b>Zed</b> [members-name] [x] & Eins' && str_starts_with( portalText( $named, '//header[@class="crm-bar"]//h1' ), 'Studio <b>Zed</b> [members-name] [x] & Eins | ' ) === true && str_contains( $named, '&lt;b&gt;Zed&lt;/b&gt; &#91;members-name]' ) === true );
\Nino\Html::addFills( $appData, [ '[['. $siteName. ']]' => '' ], '*' );
$unnamed = portalHeadAt( $appData, '/portal' );
check( '...a site without a name has a bar without one: the h1 is the title of the page alone, with no separator', portalText( $unnamed, '//span[@class="crm-bar__name"]' ) === '' && ! str_contains( portalText( $unnamed, '//header[@class="crm-bar"]//h1' ), '|' ) && portalText( $unnamed, '//header[@class="crm-bar"]//h1' ) !== '' );
\Nino\Html::addFills( $appData, [ '[['. $siteName. ']]' => (string) $siteWas ], '*' );
\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'name' => '' ] );
$bare = portalHeadAt( $appData, '/portal' );
check( 'a member with no name is shown by the address: its first letter as the initials, the address as the name', portalText( $bare, '//span[@class="crm-account__avatar"]' ) === 'A' && portalText( $bare, '//span[@class="crm-account__name"]' ) === $accounts['anna']['email'] );
\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'name' => 'Anna Nord' ] );
$dataHead = portalPage( $appData, '/portal/meine-daten' )[1];
check( 'the chip of the bar is marked as the current one on the page Meine Daten - and on no other', count( portalFind( $dataHead, '//header[@class="crm-bar"]//a[contains(@class, "crm-account")][@href="/portal/meine-daten"][@aria-current="page"]' ) ) === 1
	&& portalFind( portalPage( $appData, '/portal' )[1], '//header[@class="crm-bar"]//a[@aria-current]' ) === [] && portalFind( portalPage( $appData, '/portal/projekte' )[1], '//header[@class="crm-bar"]//a[@aria-current]' ) === [] );
$head = \Nino\Html::renderHtml( $appData, '[crm-portal-head]' );
check( 'the head draws the bar and the navigation before it - the navigation the same as the component on its own - and nav="0" leaves both out, for a template that sets the navigation itself', str_contains( $head, \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ) ) === true && count( portalFind( $head, '//header[@class="crm-bar"]/following-sibling::nav[contains(@class, "crm-nav")]/following-sibling::div[@class="crm-head"]' ) ) === 1
	&& portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-head nav="0"]' ), '//nav | //header' ) === [] && count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-head nav="0"]' ), '//div[@class="crm-head"]' ) ) === 1 );
check( '...the door has none, and no script makes it: the nav is in the markup the server sends and portal.js has no word of it', str_contains( \Nino\Html::renderHtml( $appData, '[crm-portal-door]' ), 'crm-nav' ) === false && str_contains( (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.js' ), 'crm-nav' ) === false && str_contains( (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.js' ), 'crm-account' ) === false );

// --- The projects --------------------------------------------------------------------------------------------------------------

echo "\n[crm-projects]\n";

$asVisitor();
check( 'a visitor is told there is no project', str_contains( \Nino\Html::renderHtml( $appData, '[crm-projects]' ), 'crm-projects--empty' ) === true );
portalSignIn( $appData, $stray );
check( 'a member of no client is told there is none', count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-projects]' ), '//section[contains(@class, "crm-projects--empty")]/div[contains(@class, "crm-empty")]/svg[@aria-hidden="true"]/following-sibling::p[contains(@class, "crm-empty__text")]' ) ) === 1 );
portalSignIn( $appData, $accounts['ben'] );
$benList = portalPage( $appData, '/portal/projekte' )[1];
check( 'a client with a project sees it, and only its own: the title as a link to the page of the project', portalFind( $benList, '//a[contains(@class, "crm-projects__link")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $p3 && count( portalFind( $benList, '//li[@class="crm-projects__row"]' ) ) === 1 && str_contains( $benList, 'Album 2027' ) === false );

portalSignIn( $appData, $accounts['anna'] );
$list = portalPage( $appData, '/portal/projekte' )[1];
$open = portalFind( $list, '//ul[contains(@class, "crm-projects__list--open")]/li' );
$done = portalFind( $list, '//ul[contains(@class, "crm-projects__list--done")]/li' );
check( 'the list: the open projects first, the one with the latest activity on top (the files and the documents count, not only the day it began), each a row with its id and state; the done ones in a list of their own under a heading; the project of another client is not there', count( $open ) === 2
	&& array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-project' ), $open ) === [ $p1, $p4 ] && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-state' ), $open ) === [ 'open', 'open' ]
	&& count( $done ) === 1 && $done[0]->getAttribute( 'data-project' ) === $p2 && $done[0]->getAttribute( 'data-state' ) === 'done' && count( portalFind( $list, '//h3[contains(@class, "crm-projects__heading")]' ) ) === 1
	&& str_contains( $list, 'Podcast B' ) === false && portalFind( $list, '//h3[contains(@class, "crm-projects__heading")]/following-sibling::ul[1]' )[0]->getAttribute( 'class' ) === 'crm-projects__list crm-projects__list--done' );
$row = $open[0];
$rowHtml = $row->ownerDocument->saveHTML( $row );
check( '...a row has the title as a link to the page, the day it began, the counts of files and of released documents - the files the member may see only (4 of the 6: the three visible ones and the member\'s own)', portalText( $rowHtml, '//span[@class="crm-projects__title"]' ) === 'Album 2027'
	&& portalFind( $rowHtml, '//a[contains(@class, "crm-projects__link")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $p1 && portalFind( $rowHtml, '//time/@datetime' )[0]->nodeValue === '2026-10-01'
	&& portalText( $rowHtml, '//span[contains(@class, "crm-projects__count--files")]/span[@class="crm-projects__number"]' ) === '4' && portalText( $rowHtml, '//span[contains(@class, "crm-projects__count--documents")]/span[@class="crm-projects__number"]' ) === '4' );
check( '...a project without a folder has no count of files, one without documents says 0', portalFind( $open[1]->ownerDocument->saveHTML( $open[1] ), '//span[contains(@class, "crm-projects__count--files")]' ) === [] && portalText( $open[1]->ownerDocument->saveHTML( $open[1] ), '//span[contains(@class, "crm-projects__count--documents")]/span[@class="crm-projects__number"]' ) === '0' );
$wordOf = static fn( string $html, string $kind ): string => trim( (string) preg_replace( '/^\d+/', '', portalText( $html, '//span[contains(@class, "crm-projects__count--'. $kind. '")]' ) ) );
$benRow = portalFind( $benList, '//li[@class="crm-projects__row"]' )[0];
check( '...the word of a count follows its number: one file and one document are said in the singular, four in the plural', portalText( $benRow->ownerDocument->saveHTML( $benRow ), '//span[contains(@class, "crm-projects__count--files")]/span[@class="crm-projects__number"]' ) === '1' && portalText( $benRow->ownerDocument->saveHTML( $benRow ), '//span[contains(@class, "crm-projects__count--documents")]/span[@class="crm-projects__number"]' ) === '1'
	&& $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) !== '' && $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) !== $wordOf( $rowHtml, 'files' ) && $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'documents' ) !== $wordOf( $rowHtml, 'documents' ) && portalLeft( $list ) === false );

$wordsOfPortal = (array) require $root. '/app/Crm/Portal/text/de_DE.php';
check( '...and the singular is the word of the one, the plural the word of the many, as the words file has them', $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) === $wordsOfPortal['[[/project/crm-portal/projects/file-one]]'] && $wordOf( $rowHtml, 'files' ) === $wordsOfPortal['[[/project/crm-portal/projects/file-many]]']
	&& $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'documents' ) === $wordsOfPortal['[[/project/crm-portal/projects/document-one]]'] );

// the start page: the open ones, at most five
$short = \Nino\Html::renderHtml( $appData, '[crm-projects open="1" short="1"]' );
check( 'the start page lists the open projects only: the done one is left out; the short list has its heading and the link to all of them, which the list of the page has not', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-project' ), portalFind( $short, '//li[@class="crm-projects__row"]' ) ) === [ $p1, $p4 ]
	&& count( portalFind( $short, '//div[contains(@class, "crm-section__head")]/h3[@id="crm-projects-heading"]' ) ) === 1 && portalFind( $short, '//section[@aria-labelledby="crm-projects-heading"]' ) !== [] && portalFind( $short, '//div[contains(@class, "crm-section__head")]/a[contains(@class, "crm-projects__all")]/@href' )[0]->nodeValue === '/portal/projekte'
	&& portalFind( $list, '//a[contains(@class, "crm-projects__all")]' ) === [] );
$many = $ownProjects;

for( $n = 0; $n < 6; $n++ )
	$made( 'Extra '. $n, $a['number'] );

check( '...and short cuts it at five, the newest', count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-projects open="1" short="1"]' ), '//li[@class="crm-projects__row"]' ) ) === 5
	&& count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-projects open="1"]' ), '//li[@class="crm-projects__row"]' ) ) === 8 && count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-projects short="1"]' ), '//li[@class="crm-projects__row"]' ) ) === 5 );
check( '...short and open show the list whatever the query says: the page of a project is not drawn', portalFind( portalPage( $appData, '/portal?projekt='. $p1 )[1], '//article[contains(@class, "crm-project")]' ) === [] && count( portalFind( portalPage( $appData, '/portal?projekt='. $p1 )[1], '//li[@class="crm-projects__row"]' ) ) === 5 );
\Nino\Filesystem::putFileContent( $appData, '/elements/projekte.php', $many );

// --- The page of a project -----------------------------------------------------------------------------------------------------

echo "\nThe page of a project\n";

$page = portalPage( $appData, '/portal/projekte?projekt='. $p1 );
$html = $page[1];
$head = portalFind( $html, '//article[contains(@class, "crm-project")]' );
check( 'the page of a project is the page, not the list: one article for the project, the list is gone', $page[0] === 200 && count( $head ) === 1 && $head[0]->getAttribute( 'data-project' ) === $p1 && $head[0]->getAttribute( 'data-state' ) === 'open' && portalFind( $html, '//ul[contains(concat(" ", normalize-space(@class), " "), " crm-projects__list ")]' ) === [] );
$pageHead = portalFind( $html, '//div[@class="crm-head"]' );
check( '...the head of the page: the title of the project is the page title, the description under it with its line breaks as <br> and every character escaped (markup and a shortcode in it are text), the day it began, its state as a pill, the way back as a breadcrumb, and no list heading or intro above', count( $pageHead ) === 1
	&& portalText( $html, '//div[@class="crm-head"]//h2[contains(@class, "crm-head__title")]/span[@class="crm-head__name"]' ) === 'Album 2027' && str_contains( $html, 'Aufnahme &lt;b&gt;und&lt;/b&gt;<br>Mix &#91;members-name]' ) === true
	&& portalFind( $html, '//p[@class="crm-head__lead"]' ) !== [] && portalFind( $html, '//nav[contains(@class, "crm-crumbs")]/a/@href' )[0]->nodeValue === '/portal/projekte' && portalText( $html, '//nav[contains(@class, "crm-crumbs")]/span[@aria-current="page"]' ) === 'Album 2027'
	&& portalFind( $html, '//span[contains(@class, "crm-head__since")]/time/@datetime' )[0]->nodeValue === '2026-10-01' && str_contains( portalFind( $html, '//span[contains(@class, "crm-head__state")]' )[0]->getAttribute( 'class' ), 'crm-pill--info' ) === true
	&& portalText( $html, '//span[contains(@class, "crm-head__state")]' ) !== portalText( portalPage( $appData, '/portal/projekte?projekt='. $p2 )[1], '//span[contains(@class, "crm-head__state")]' )
	&& portalFind( $html, '//h3[contains(@class, "crm-projects__heading")] | //h3[@class="crm-project__title"]' ) === [] && portalFind( $html, '//article//h2' ) === [] );
$headSource = (string) file_get_contents( $root. '/app/Crm/Portal/templates/head.tpl' );
check( '...in the source of the head the h2 closes right behind the title, and the state follows the heading: a parser that closes the h2 itself cannot tell, so the template is read', preg_match( '~<h2 class="crm-head__title"><span class="crm-head__name">\[\[title\]\]</span></h2>\s*\[\[badge\]\]~', $headSource ) === 1 );
check( '...the h2 holds the title and nothing else - the state and the start stand beside it, in a paragraph of their own, so that the name of the heading is the title - and the headings of the page follow each other without a jump: h2, then h3', portalText( $html, '//div[@class="crm-head"]//h2' ) === 'Album 2027'
	&& portalFind( $html, '//div[@class="crm-head"]//h2//*[contains(@class, "crm-pill") or contains(@class, "crm-head__since") or self::time]' ) === []
	&& portalFind( $html, '//div[@class="crm-head"]//h2/following-sibling::p[contains(@class, "crm-head__meta")][contains(@class, "crm-head__badge")]/span[contains(@class, "crm-head__state")][contains(@class, "crm-pill")]' ) !== []
	&& portalFind( $html, '//div[@class="crm-head"]//p[contains(@class, "crm-head__badge")]/span[contains(@class, "crm-head__since")]/time' ) !== []
	&& ( static function( array $headings ): bool {

		$last = 1;

		foreach( $headings as $heading ) {

			$level = (int) substr( $heading->nodeName, 1 );

			if( $level > $last + 1 )
				return false;

			$last = $level;
		}

		return $headings !== [] && $headings[0]->nodeName === 'h2';
	} )( portalFind( $html, '//div[@class="crm-head"]//h2 | //article//*[self::h2 or self::h3 or self::h4 or self::h5 or self::h6]' ) ) === true );
$sections = portalFind( $html, '//section[contains(@class, "crm-project__section")]' );
check( '...the head is no <header>: the stylesheet of the kernel draws every header of the page as the head of the site, so no component of the portal writes one (nor a footer or a main)', portalFind( $html, '//article//header | //article//footer | //article//main | //div[contains(@class, "crm-head")]//header' ) === [] && portalFind( $list, '//section[contains(@class, "crm-projects")]//header | //section[contains(@class, "crm-projects")]//footer' ) === [] );
check( '...then three tabs with their sections, each with a heading: Angebot, Dateien, Belege - and no Upload: the upload is global, there is no section, no tab and no link to it in the project', array_map( static fn( \DOMElement $section ): string => preg_replace( '/^.*crm-project__section--(\w+).*$/', '$1', $section->getAttribute( 'class' ) ), $sections ) === [ 'offer', 'files', 'documents' ]
	&& array_filter( $sections, static fn( \DOMElement $section ): bool => $section->getElementsByTagName( 'h3' )->length < 1 || trim( $section->getElementsByTagName( 'h3' )->item( 0 )->textContent ) === '' || $section->getElementsByTagName( 'h4' )->length !== 0 || $section->getElementsByTagName( 'h5' )->length !== 0 ) === [] && portalLeft( $html ) === false
	&& array_map( static fn( \DOMElement $tab ): string => $tab->getAttribute( 'data-tab' ), portalFind( $html, '//article//a[contains(@class, "crm-project__tab")]' ) ) === [ 'angebot', 'dateien', 'belege' ]
	&& portalFind( $html, '//article//*[contains(@class, "--upload")]' ) === [] && portalFind( $html, '//article//a[contains(@href, "/portal/upload")]' ) === [] );

// the offers: orderable ones open, the rest folded to a line
$offerQuery = '//section[contains(@class, "crm-project__section--offer")]//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")]';
$offers = portalFind( $html, $offerQuery );
check( 'Angebot: the released offers of the project, newest first - not the one that is not released, not the offer of another project, not another client\'s - each a card that folds', array_map( static fn( \DOMElement $offer ): string => $offer->getAttribute( 'data-document' ), $offers ) === [ (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ]
	&& array_filter( $offers, static fn( \DOMElement $offer ): bool => $offer->getElementsByTagName( 'summary' )->length !== 1 || $offer->getAttribute( 'id' ) !== 'angebot-'. $offer->getAttribute( 'data-document' ) ) === []
	&& str_contains( $html, 'Nicht freigegeben' ) === false && str_contains( $html, 'Andere Seite' ) === false && str_contains( $html, 'Fremdes Angebot' ) === false );
check( '...all three can be ordered here, so all three are expanded, and no heading of folded ones stands between them', array_filter( $offers, static fn( \DOMElement $offer ): bool => $offer->hasAttribute( 'open' ) === false ) === [] && portalFind( $html, '//h3[contains(@class, "crm-project__rest")]' ) === [] );
$validWas = Db::row( $appData, 'SELECT valid_until FROM documents WHERE id = :id', [ 'id' => $offerReverse['id'] ] )['valid_until'];
Db::exec( $appData, 'UPDATE documents SET valid_until = \'2020-01-01\' WHERE id = :id', [ 'id' => $offerReverse['id'] ] );
$afterExpiry = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$expiredOffers = portalFind( $afterExpiry, $offerQuery );
$expiredCard = portalFind( $afterExpiry, '//details[@data-document="'. $offerReverse['id']. '"]' )[0];
check( '...an offer that is open or sent and past its day says it is expired, as the list of documents does: its card is folded to its line, comes after the heading of the folded ones, and has no form', $expiredCard->getAttribute( 'data-state' ) === 'expired' && $expiredCard->hasAttribute( 'open' ) === false
	&& array_map( static fn( \DOMElement $offer ): string => $offer->getAttribute( 'data-document' ), $expiredOffers ) === [ (string) $offerRegular['id'], (string) $offerSmall['id'], (string) $offerReverse['id'] ]
	&& count( portalFind( $afterExpiry, '//h3[contains(@class, "crm-project__rest")]/following-sibling::details[@data-document="'. $offerReverse['id']. '"]' ) ) === 1 && portalFind( $afterExpiry, '//h3[contains(@class, "crm-project__rest")]/preceding-sibling::details[@data-document="'. $offerSmall['id']. '"]' ) !== []
	&& $expiredCard->getElementsByTagName( 'form' )->length === 0 && str_contains( portalFind( $afterExpiry, '//details[@data-document="'. $offerReverse['id']. '"]//*[contains(@class, "crm-offer__state")]' )[0]->getAttribute( 'class' ), 'crm-pill--muted' ) === true );
$named = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&beleg='. $offerReverse['id'] )[1];
check( '...a folded card that the address names (?beleg=<id>) is expanded, the other folded cards stay as they are', portalFind( $named, '//details[@data-document="'. $offerReverse['id']. '"]' )[0]->hasAttribute( 'open' ) === true );
Db::exec( $appData, 'UPDATE documents SET valid_until = :v WHERE id = :id', [ 'v' => $validWas, 'id' => $offerReverse['id'] ] );
$offerHtml = $offers[0]->ownerDocument->saveHTML( $offers[0] );
$lineRows = portalFind( $offerHtml, '//li[@class="crm-offer__line"]' );
check( '...an offer: its title (the kind and the project), its number, the day, "gültig bis" with its day, its gross amount, its state as a pill, and the download of the PDF in the summary', portalText( $offerHtml, '//summary//span[@class="crm-offer__title"]' ) === $wordsDe['[[/project/crm-documents/category/offer]]']. ' Album 2027'
	&& portalText( $offerHtml, '//summary//span[@class="crm-offer__number"]' ) === $offerRegular['number'] && portalFind( $offerHtml, '//time[@class="crm-offer__date"]/@datetime' )[0]->nodeValue === '2026-10-05'
	&& portalFind( $offerHtml, '//span[@class="crm-offer__valid"]/time/@datetime' )[0]->nodeValue === '2026-12-31' && portalText( $offerHtml, '//span[@class="crm-offer__valid"]/time' ) === '31.12.2026' && portalText( $offerHtml, '//summary//span[@class="crm-offer__gross"]' ) === '392,60 €'
	&& str_contains( portalFind( $offerHtml, '//summary//span[contains(@class, "crm-offer__state")]' )[0]->getAttribute( 'class' ), 'crm-pill--info' ) === true && portalText( $offerHtml, '//summary//span[contains(@class, "crm-offer__state")]' ) !== ''
	&& portalFind( $offerHtml, '//summary/a[contains(@class, "crm-offer__download")]/@href' )[0]->nodeValue === '/.crm/pdf/'. $offerRegular['id']. '?download=1' && count( portalFind( $html, '//span[@class="crm-offer__valid"]' ) ) === 3 );
check( '...its lines: title and description (line breaks as <br>, escaped), quantity with its unit, the net price, the VAT rate as the document carries it, the amount', count( $lineRows ) === 2
	&& portalText( $offerHtml, '//li[1]/span[@class="crm-offer__line-title"]' ) === 'Mix <i>live</i>' && str_contains( $offerHtml, 'Zwei Songs<br>mit Master' ) === true && str_contains( $offerHtml, '<i>' ) === false
	&& portalText( $offerHtml, '//li[1]//span[@class="crm-offer__line-qty"]' ) === '3 Std.' && str_contains( portalText( $offerHtml, '//li[1]//span[@class="crm-offer__line-price"]' ), '80,00' ) === true
	&& portalText( $offerHtml, '//li[1]//span[@class="crm-offer__line-rate"]' ) === '19 %' && portalText( $offerHtml, '//li[1]/span[@class="crm-offer__line-amount"]' ) === '240,00 €' && portalText( $offerHtml, '//li[2]//span[@class="crm-offer__line-qty"]' ) === '1'
	&& portalText( $offerHtml, '//li[2]//span[@class="crm-offer__line-rate"]' ) === '7 %' && portalFind( $offerHtml, '//li[2]/span[@class="crm-offer__line-description"]' ) === [] );
$sums = portalFind( $offerHtml, '//dl[@class="crm-offer__sums"]/div' );
check( '...its sums as the PDF has them: net, the VAT of each rate (the highest first), gross - 340,00 net, 45,60 and 7,00 of VAT, 392,60 gross', array_map( static fn( \DOMElement $sum ): string => preg_replace( '/^.*--/', '', $sum->getAttribute( 'class' ) ), $sums ) === [ 'net', 'vat', 'vat', 'gross' ]
	&& array_map( static fn( \DOMElement $sum ): string => portalText( $sum->ownerDocument->saveHTML( $sum ), '//dd' ), $sums ) === [ '340,00 €', '45,60 €', '7,00 €', '392,60 €' ] && portalFind( $offerHtml, '//p[@class="crm-offer__note"]' ) === [] );
$reverseHtml = $offers[1]->ownerDocument->saveHTML( $offers[1] );
$smallHtml = $offers[2]->ownerDocument->saveHTML( $offers[2] );
check( '...a reverse charge says so as the PDF says it and carries no VAT; a small business has the gross alone, with its note, and no VAT rate on its lines', portalText( $reverseHtml, '//p[@class="crm-offer__note"]' ) === \Crm\Documents\Render::text( 'vatnote.reverse' ) && portalText( $reverseHtml, '//div[contains(@class, "crm-offer__sum--vat")]/dd' ) === '0,00 €'
	&& count( portalFind( $smallHtml, '//dl/div' ) ) === 1 && portalFind( $smallHtml, '//div[contains(@class, "crm-offer__sum--gross")]' ) !== [] && portalText( $smallHtml, '//p[@class="crm-offer__note"]' ) === \Crm\Documents\Render::text( 'vatnote.small_business' ) && portalFind( $smallHtml, '//span[@class="crm-offer__line-rate"]' ) === [] );
check( '...the form of the order stands under the offers that can be ordered and under no other: as many forms in the section as there are offers open or sent that have not run out (the order itself is pinned further down)', count( portalFind( $html, '//section[contains(@class, "crm-project__section--offer")]//form' ) )
	=== count( array_filter( [ $offerRegular, $offerReverse, $offerSmall ], static fn( array $offer ): bool => Documents::orderable( (array) Documents::get( $appData, $offer['id'] ) ) === true ) ) );

// the files
$rows = portalFind( $html, '//section[contains(@class, "crm-project__section--files")]//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' );
$names = array_map( static fn( \DOMElement $row ): string => trim( portalText( $row->ownerDocument->saveHTML( $row ), '//a[contains(@class, "crm-files__name")]' ) ), $rows );
check( 'Dateien: the files the member may see (Files::allowed) - the visible ones and the member\'s own, not the hidden ones of the owner or of another account - the newest first', $names === [ 'Song.mp3', 'Meins.wav', 'Clip.mp4', 'Notizen.pdf' ] && str_contains( $html, 'Geheim.mp3' ) === false && str_contains( $html, 'Carl.mp3' ) === false );
$rowHtmls = array_map( static fn( \DOMElement $row ): string => $row->ownerDocument->saveHTML( $row ), $rows );
check( '...a row: the name as a link - to the player for an audio or video file, to the download for any other -, its size, its day, and the download as the Files browser has it (?download=1)', portalFind( $rowHtmls[0], '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $p1. '&review='. $song['id']. '#projekt-files'
	&& portalFind( $rowHtmls[3], '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/.files/'. $notes['id']. '?download=1' && portalFind( $rowHtmls[0], '//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $song['id']. '?download=1'
	&& portalText( $rowHtmls[0], '//span[contains(@class, "crm-files__size")]' ) !== '' && str_contains( portalText( $rowHtmls[0], '//span[contains(@class, "crm-files__size")]' ), '[[' ) === false && portalFind( $rowHtmls[3], '//time/@datetime' )[0]->nodeValue === '2026-01-01T09:00:00Z' && portalText( $rowHtmls[3], '//time' ) === '01.01.2026' && $rows[0]->getAttribute( 'data-files-mime' ) === 'audio/mpeg' );
check( '...a file younger than 14 days is marked "neu", an older one is not', array_map( static fn( string $row ): bool => portalFind( $row, '//span[contains(concat(" ", normalize-space(@class), " "), " crm-files__new ")]' ) !== [], $rowHtmls ) === [ true, true, true, false ] );
check( '...with Review on, "Anhören" is offered for audio and video only: for the mp3, the wav and the mp4, not for the pdf; it leads to the same page with &review=<file>', array_map( static fn( string $row ): string => portalFind( $row, '//a[contains(@class, "crm-files__listen")]' ) === [] ? '' : portalFind( $row, '//a[contains(@class, "crm-files__listen")]/@href' )[0]->nodeValue, $rowHtmls )
	=== [ '/portal/projekte?projekt='. $p1. '&review='. $song['id']. '#projekt-files', '/portal/projekte?projekt='. $p1. '&review='. $mine['id']. '#projekt-files', '/portal/projekte?projekt='. $p1. '&review='. $clip['id']. '#projekt-files', '' ] );
check( '...without ?review= there is no player on the page', portalFind( $html, '//div[contains(@class, "review-player")]' ) === [] );

\Nino\Features::deactivate( $appData, 'review' );
$noReview = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1];
check( 'with Review off there is no link that listens, and a file asked for with ?review= is not played', portalFind( $noReview, '//a[contains(@class, "crm-files__listen")]' ) === [] && portalFind( $noReview, '//div[contains(@class, "review-player")]' ) === [] && count( portalFind( $noReview, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === 4 );
\Nino\Features::activate( $appData, 'review' );

$played = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1];
check( 'with ?review=<file> the player of Review stands above the list: the audio element with the file as its source, and the list is still there', count( portalFind( $played, '//section[contains(@class, "crm-project__section--files")]//div[contains(@class, "review-player")]' ) ) === 1
	&& portalFind( $played, '//audio/@src | //audio/source/@src' )[0]->nodeValue === '/.files/'. $song['id'] && count( portalFind( $played, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === 4
	&& count( portalFind( $played, '//div[contains(@class, "review-player")]/following::ul[contains(concat(" ", normalize-space(@class), " "), " crm-files__list ")]' ) ) === 1 );
check( '...a video file gets the video element; a file that is not audio or video, a file the member may not see, one of another folder and an id that is none are not played - the page is the page of the project all the same, without a player',
	portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $clip['id'] )[1], '//video' ) !== []
	&& array_filter( [ $notes['id'], $secret['id'], $carls['id'], $bFile['id'], 'zzzzzzzzzzzz', '../x' ], static function( string $id ) use ( &$appData, $p1 ): bool {
		$other = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $id )[1];
		return portalFind( $other, '//div[contains(@class, "review-player")]' ) !== [] || count( portalFind( $other, '//article[contains(@class, "crm-project")]' ) ) !== 1;
	} ) === [] );

// the comment: posted from the player, back to the page, shown
$formAt = portalFind( $played, '//form[@class="review-form"]' );
check( 'the form of the player is the one of Review: it posts to /.review/comment with the file, and returns to this page', count( $formAt ) === 1 && portalFind( $played, '//form[@class="review-form"]/@action' )[0]->nodeValue === '/.review/comment'
	&& portalFind( $played, '//form[@class="review-form"]/input[@name="file"]/@value' )[0]->nodeValue === $song['id'] && portalFind( $played, '//form[@class="review-form"]/input[@name="return"]/@value' )[0]->nodeValue === '/portal/projekte' );
$sentComment = portalServe( $appData, '/.review/comment', 'POST', [ 'file' => $song['id'], 'return' => '/portal/projekte', 'at' => '0:12', 'text' => 'Der Einsatz <b>kommt</b> zu früh', 'parent' => '' ] );
check( 'a comment posted there is stored and answered with a redirect to the page with the file in the query - Review sends the path alone, not the query of the project', $sentComment['statusCode'] === 303 && ( $sentComment['header']['Location'] ?? '' ) === '/portal/projekte?review='. $song['id']. '&review-state=sent' );
$back = portalPage( $appData, '/portal/projekte?review='. $song['id']. '&review-state=sent' );
check( '...and that address is the page of the project: the project is found by the folder of the file, the player is there with the comment (escaped) and the state of the form', $back[0] === 200 && count( portalFind( $back[1], '//article[@data-project="'. $p1. '"]' ) ) === 1 && str_contains( $back[1], 'Der Einsatz &lt;b&gt;kommt&lt;/b&gt; zu früh' ) === true
	&& count( portalFind( $back[1], '//div[contains(@class, "review-player")]' ) ) === 1 && portalFind( $back[1], '//*[@role="status"]' ) !== [] );
$strange = portalPage( $appData, '/portal/projekte?review='. $bFile['id'] )[1];
$hiddenOne = portalPage( $appData, '/portal/projekte?review='. $carls['id'] )[1];
check( '...a file of a folder that no project of the client names is the list; a file the member may not see names the project of its folder all the same, and is not played', count( portalFind( $strange, '//li[@class="crm-projects__row"]' ) ) === 3 && portalFind( $strange, '//article[contains(@class, "crm-project")]' ) === []
	&& str_contains( $strange, 'Podcast B' ) === false && count( portalFind( $hiddenOne, '//article[@data-project="'. $p1. '"]' ) ) === 1 && portalFind( $hiddenOne, '//div[contains(@class, "review-player")]' ) === [] );

// a project that is not the client's, one that is none, a query that is no id: the list, with the sentence that the project is unknown
$unknownOf = static function( string $query ) use ( &$appData ): bool {
	$answer = portalPage( $appData, '/portal/projekte?'. $query )[1];

	return count( portalFind( $answer, '//p[contains(@class, "crm-projects__unknown")][@role="alert"]' ) ) === 1 && portalFind( $answer, '//article[contains(@class, "crm-project")]' ) === [] && count( portalFind( $answer, '//li[@class="crm-projects__row"]' ) ) === 3
		&& str_contains( $answer, 'Podcast B' ) === false && str_contains( $answer, 'Fremdes Angebot' ) === false;
};
check( 'the project of another client is the list with the sentence that the project is unknown - and nothing of that project', $unknownOf( 'projekt='. $p3 ) === true );
check( '...so is a project nobody has, an id with a path in it, one with markup in it and one longer than an id can be', $unknownOf( 'projekt=00099' ) === true && $unknownOf( 'projekt=..%2F'. $p1 ) === true && $unknownOf( 'projekt=%3Cb%3E' ) === true && $unknownOf( 'projekt='. str_repeat( 'a', 101 ) ) === true );
$ignored = ninoWarnings();
$arrayQuery = portalPage( $appData, '/portal/projekte?projekt[]='. $p1 )[1];
check( '...and a query that is not a string is no project: the plain list, no sentence, no error', $arrayQuery !== '' && portalFind( $arrayQuery, '//p[contains(@class, "crm-projects__unknown")]' ) === [] && count( portalFind( $arrayQuery, '//li[@class="crm-projects__row"]' ) ) === 3 && ninoWarnings() === [] );

// the files of a project that has no folder, and where Files is off
$noFolder = portalPage( $appData, '/portal/projekte?projekt='. $p4 )[1];
$filesOnly = static fn( string $html ): string => portalText( $html, '//section[contains(@class, "crm-project__section--files")]' );
check( 'a project without a folder says so in its Dateien section: a sentence and no list', portalFind( $noFolder, '//section[contains(@class, "crm-project__section--files")]/div[contains(@class, "crm-project__message")]' ) !== [] && portalFind( $noFolder, '//section[contains(@class, "crm-project__section--files")]//li' ) === [] );
$folderless = $filesOnly( $noFolder );
\Nino\Features::deactivate( $appData, 'review' );
\Nino\Features::deactivate( $appData, 'files' );
$filesOff = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
check( '...with Files off the section says so, in another sentence, and the page is still the page of the project', portalFind( $filesOff, '//section[contains(@class, "crm-project__section--files")]/div[contains(@class, "crm-project__message")]' ) !== [] && $filesOnly( $filesOff ) !== '' && $filesOnly( $filesOff ) !== $folderless
	&& portalFind( $filesOff, '//section[contains(@class, "crm-project__section--files")]//li' ) === [] && count( portalFind( $filesOff, '//article[@data-project="'. $p1. '"]' ) ) === 1 && portalFind( $filesOff, '//span[contains(@class, "crm-projects__count--files")]' ) === [] );
check( '...and the list has no count of files then', portalFind( portalPage( $appData, '/portal/projekte' )[1], '//span[contains(@class, "crm-projects__count--files")]' ) === [] && trim( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ) ) !== '' );
\Nino\Features::activate( $appData, 'files' );
\Nino\Features::activate( $appData, 'review' );
$emptyFolder = $store::addFolder( $appData, $a['group'], 'Leer' );
$p5 = $made( 'Mit leerem Ordner', $a['number'], [ 'folder' => $emptyFolder['id'] ] );
$p6 = $made( 'Fremder Ordner', $a['number'], [ 'folder' => $folderB['id'] ] );
check( 'a folder with nothing the member may see says so; a folder that is not one of the member\'s group is no folder of the project - the section says there is none, and no file of it is listed', $filesOnly( portalPage( $appData, '/portal/projekte?projekt='. $p5 )[1] ) !== '' && portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p5 )[1], '//section[contains(@class, "crm-project__section--files")]//li' ) === []
	&& $filesOnly( portalPage( $appData, '/portal/projekte?projekt='. $p6 )[1] ) === $folderless && str_contains( portalPage( $appData, '/portal/projekte?projekt='. $p6 )[1], 'Podcast.mp3' ) === false );
\Nino\Filesystem::putFileContent( $appData, '/elements/projekte.php', $many );

// the documents of the project, and the way to the upload
$documentRows = portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' );
check( 'Belege: the released documents of the project, of every category, the newest first - the rows of [crm-documents] - and none of another project or of none', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-document' ), $documentRows )
	=== [ (string) $invoiceP1['id'], (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ] && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-category' ), $documentRows ) === [ 'invoice', 'offer', 'offer', 'offer' ]
	&& portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//a[contains(@class, "crm-documents__download")]' )[0]->getAttribute( 'href' ) === '/.crm/pdf/'. $invoiceP1['id']. '?download=1' && portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//*[contains(@class, "crm-documents__project")]' ) === []
	&& array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//a[contains(@class, "crm-documents__offer")]' ) ) === [ '/portal/projekte?projekt='. $p1. '&tab=angebot#angebot-'. $offerRegular['id'], '/portal/projekte?projekt='. $p1. '&tab=angebot#angebot-'. $offerReverse['id'], '/portal/projekte?projekt='. $p1. '&tab=angebot#angebot-'. $offerSmall['id'] ] );
check( '...a project with no document says so with the sentence of the list', portalFind( $noFolder, '//section[contains(@class, "crm-project__section--documents")]//div[contains(@class, "crm-documents__empty")]/p[contains(@class, "crm-empty__text")]' ) !== [] );

// --- The tabs of the page of a project -------------------------------------------------------------------------------------------

echo "\nThe tabs of the page of a project\n";

$token = static fn( string $name ): string => 'contains(concat(" ", normalize-space(@class), " "), " '. $name. ' ")';
$tabButtons = static fn( string $page ): array => portalFind( $page, '//article[@data-project]/div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']/a['. $token( 'nino-tabs-tab' ). ']' );
$tabPanels = static fn( string $page ): array => portalFind( $page, '//div['. $token( 'nino-tabs' ). ']/section['. $token( 'nino-tabs-panel' ). ']' );
$openIds = static fn( string $page ): array => [
	array_values( array_map( static fn( \DOMElement $button ): string => $button->getAttribute( 'data-tabs-target' ), array_filter( $tabButtons( $page ), static fn( \DOMElement $button ): bool => str_contains( ' '. $button->getAttribute( 'class' ). ' ', ' nino-is-active ' ) ) ) ),
	array_values( array_map( static fn( \DOMElement $panel ): string => $panel->getAttribute( 'id' ), array_filter( $tabPanels( $page ), static fn( \DOMElement $panel ): bool => str_contains( ' '. $panel->getAttribute( 'class' ). ' ', ' nino-is-active ' ) ) ) ),
];
$withTabs = portalFind( $html, '//article[@data-project]/div['. $token( 'nino-tabs' ). ']' );
check( 'the page of a project has the kernel\'s tabs under the head: one .nino-tabs with a .nino-tabs-nav of three links - there from the server, so that nothing moves when the script of the kernel makes them tabs - and three .nino-tabs-panel sections', count( $withTabs ) === 1 && count( portalFind( $html, '//div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']' ) ) === 1
	&& count( $tabButtons( $html ) ) === 3 && count( $tabPanels( $html ) ) === 3 && count( portalFind( $html, '//div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']/*[not(self::a)]' ) ) === 0 );
check( '...each tab is a link to its panel (href #<id>, the jump list of a page without a script) with the id of the panel in data-tabs-target - Angebot, Dateien, Belege, in this order - and each panel has that id (the anchors of the start page stay: projekt-files)', array_map( static fn( \DOMElement $button ): string => $button->getAttribute( 'data-tabs-target' ), $tabButtons( $html ) ) === [ 'projekt-offer', 'projekt-files', 'projekt-documents' ]
	&& array_map( static fn( \DOMElement $panel ): string => $panel->getAttribute( 'id' ), $tabPanels( $html ) ) === [ 'projekt-offer', 'projekt-files', 'projekt-documents' ] && array_filter( $tabButtons( $html ), static fn( \DOMElement $button ): bool => $button->getAttribute( 'href' ) !== '#'. $button->getAttribute( 'data-tabs-target' ) ) === []
	&& array_map( static fn( \DOMElement $button ): string => preg_replace( '/,?\s*\d+$/', '', trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $button->textContent ) ) ), $tabButtons( $html ) ) === array_map( static fn( string $name ): string => $wordsOfPortal['[[/project/crm-portal/projects/section-'. $name. ']]'], [ 'offer', 'files', 'documents' ] ) );
check( '...the first stands open without a query: its button and its panel carry nino-is-active and no other does', $openIds( $html ) === [ [ 'projekt-offer' ], [ 'projekt-offer' ] ] );
check( '...?tab= chooses the tab on the server: angebot, dateien, belege - the button and the panel of it, and only those', array_map( static fn( string $tab ): array => $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab='. $tab )[1] ), [ 'angebot', 'dateien', 'belege' ] )
	=== [ [ [ 'projekt-offer' ], [ 'projekt-offer' ] ], [ [ 'projekt-files' ], [ 'projekt-files' ] ], [ [ 'projekt-documents' ], [ 'projekt-documents' ] ] ] );
$ignored = ninoWarnings();
check( '...a tab that is none, one with markup in it and one that is not a string leave the first open - and say nothing of it', array_map( static fn( string $query ): array => $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. $query )[1] ), [ '&tab=nothing', '&tab=%3Cb%3E', '&tab[]=belege', '&tab=', '&tab=files', '&tab=upload' ] )
	=== array_fill( 0, 6, [ [ 'projekt-offer' ], [ 'projekt-offer' ] ] ) && str_contains( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=%3Cb%3E' )[1], '<b>' ) === false && ninoWarnings() === [] );
check( '...a file asked to be played opens Dateien, whatever tab the query names', $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ]
	&& $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=belege&review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ] && $openIds( portalPage( $appData, '/portal/projekte?review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ] );
$countOf = static fn( string $page, string $name ): string => trim( ltrim( portalText( $page, '//a[@data-tabs-target="projekt-'. $name. '"]/span[@class="crm-project__count"]' ), ',' ) );
check( '...the label of a tab carries the count of what is in it: the offers, the files the member may see, the documents', $countOf( $html, 'offer' ) === (string) count( portalFind( $html, '//section[@id="projekt-offer"]//details['. $token( 'crm-offer' ). ']' ) ) && $countOf( $html, 'offer' ) === '3'
	&& $countOf( $html, 'files' ) === (string) count( portalFind( $html, '//section[@id="projekt-files"]//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) && $countOf( $html, 'files' ) === '4'
	&& $countOf( $html, 'documents' ) === (string) count( portalFind( $html, '//section[@id="projekt-documents"]//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' ) ) && $countOf( $html, 'documents' ) === '4' );
check( '...the count says its separator for a screen reader: a hidden comma before the number, so that the label is read "Angebot, 2" and not "Angebot2"', count( portalFind( $html, '//a['. $token( 'nino-tabs-tab' ). ']/span[@class="crm-project__count"]/span['. $token( 'nino-sr-only' ). ']' ) ) === 3
	&& array_filter( $tabButtons( $html ), static fn( \DOMElement $tab ): bool => preg_match( '/\S, \d+$/', trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $tab->textContent ) ) ) !== 1 ) === [] );
check( '...a project with nothing in a tab counts 0 for the offers and the documents and says its sentence in the panel; a project without a folder and a site without Files count no files - and the panels are all there', $countOf( $noFolder, 'offer' ) === '0' && $countOf( $noFolder, 'documents' ) === '0' && $countOf( $noFolder, 'files' ) === ''
	&& portalFind( $noFolder, '//section[@id="projekt-offer"]/div[contains(@class, "crm-project__message")]' ) !== [] && portalFind( $noFolder, '//section[@id="projekt-files"]/div[contains(@class, "crm-project__message")]' ) !== [] && $countOf( $filesOff, 'files' ) === '' && count( $tabPanels( $noFolder ) ) === 3 && count( $tabPanels( $filesOff ) ) === 3
	&& $countOf( portalPage( $appData, '/portal/projekte?projekt='. $p5 )[1], 'files' ) === '0' );
check( '...no panel is hidden in the markup (the script of the kernel hides them, and without it every panel stands open), and the page holds no script, no style and no handler of the module\'s own', portalFind( $html, '//section[@hidden or @style or @aria-hidden]' ) === [] && portalFind( $html, '//article//script | //article//style | //article//*[@style] | //article//*[@onclick]' ) === [] );

// the look: the classes the templates carry, and a sheet that takes every value from the kernel's roles or from its own scales
$cardsOf = static fn( string $page, string $query ): int => count( portalFind( $page, $query ) );
check( 'the cards are .crm-card: the offers (details), the lists of files and of documents (a table that is as long as it is), the projects (links)', $cardsOf( $html, '//details['. $token( 'crm-offer' ). '][not('. $token( 'crm-card' ). ')]' ) === 0 && $cardsOf( $html, '//details['. $token( 'crm-offer' ). ']['. $token( 'crm-card' ). ']' ) === 3
	&& $cardsOf( $html, '//div['. $token( 'crm-table' ). ']['. $token( 'crm-card' ). ']/ul['. $token( 'crm-files__list' ). ']' ) === 1 && $cardsOf( $html, '//div['. $token( 'crm-table' ). ']['. $token( 'crm-card' ). ']/ul['. $token( 'crm-documents__list' ). ']' ) === 1
	&& $cardsOf( $list, '//li['. $token( 'crm-projects__row' ). ']/a['. $token( 'crm-card' ). ']['. $token( 'crm-card--link' ). ']' ) === 3 && $cardsOf( $list, '//li['. $token( 'crm-projects__row' ). '][not(a)]' ) === 0 );
check( '...and the states are .crm-pill, with the tone of the state in the class: the state of a project, of an offer and of a document, and the mark "neu" of a file - and no .crm-chip is left anywhere', $cardsOf( $html, '//span['. $token( 'crm-head__state' ). ']['. $token( 'crm-pill' ). ']' ) === 1 && $cardsOf( $html, '//span['. $token( 'crm-offer__state' ). '][not('. $token( 'crm-pill' ). ')]' ) === 0 && $cardsOf( $html, '//span['. $token( 'crm-offer__state' ). ']['. $token( 'crm-pill' ). ']' ) === 3
	&& $cardsOf( $html, '//span['. $token( 'crm-documents__state' ). ']/span[not('. $token( 'crm-pill' ). ')]' ) === 0 && $cardsOf( $html, '//span['. $token( 'crm-files__new' ). '][not('. $token( 'crm-pill' ). ')]' ) === 0
	&& $cardsOf( $html, '//span[contains(@class, "crm-pill")][not(contains(@class, "crm-pill--"))]' ) === 0 && str_contains( $html, 'crm-chip' ) === false );
$sheet = (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' );
check( 'portal.css holds no colour of its own - no hex, no rgb(), hsl() or the like, no named colour on a property - and builds its tokens from the roles of the kernel: --color-ink, -ground, -border, -accent, -success, -warning, -error, -primary, -focus and --radius-surface-alt', preg_match( '/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(/', $sheet ) === 0
	&& preg_match( '/^\s*(?:color|background(?:-color)?|border(?:-[a-z]+)*|outline(?:-color)?|box-shadow|fill|stroke):[^;]*\b(?:white|black|red|green|blue|gray|grey|orange|yellow|purple|silver)\b/m', $sheet ) === 0
	&& array_diff( [ '--color-ink', '--color-ground', '--color-border', '--color-accent', '--color-success', '--color-warning', '--color-error', '--color-primary', '--color-focus', '--radius-surface-alt', '--radius' ], array_map( static fn( string $name ): string => $name, array_unique( (array) ( preg_match_all( '/var\(\s*(--(?:color|radius)[a-z-]*)/', $sheet, $roles ) > 0 ? $roles[1] : [] ) ) ) ) === [] );
$scale = preg_match_all( '/^\s*(--crm-text-(?:xxs|xs|s|m|l|xl|xxl)):\s*([.0-9]+rem);/m', $sheet, $steps ) === 7 && $steps[2] === [ '.6875rem', '.8125rem', '.875rem', '1rem', '1.125rem', '1.25rem', '1.5rem' ];
$spacing = preg_match_all( '/^\s*(--crm-[1-7]):\s*([.0-9]+rem);/m', $sheet, $gaps ) === 7 && $gaps[2] === [ '.25rem', '.5rem', '.75rem', '1rem', '1.5rem', '2rem', '3rem' ];
check( '...it has the type scale of seven steps and the spacing of seven, and uses the scale of type: every font-size of the sheet is one of the seven steps', $scale === true && $spacing === true
	&& preg_match_all( '/font-size:\s*([^;]+);/', $sheet, $sizes ) > 50 && array_filter( $sizes[1], static fn( string $size ): bool => preg_match( '/^var\(--crm-text-(?:xxs|xs|s|m|l|xl|xxl)\)$/', $size ) !== 1 ) === [] );
$shell = (string) substr( $sheet, (int) strpos( $sheet, '/*	13 Shell' ), (int) strpos( $sheet, '/*	14 Print' ) - (int) strpos( $sheet, '/*	13 Shell' ) );
$shellRules = (string) preg_replace( '~/\*.*?\*/~s', '', $shell );
check( 'the navigation has its chapter, the Shell, and it has the three shapes: a side bar from 1024px (a grid of 11rem and the rest, the column of the surface the height of the grid, its entries sticky under the bar of the application), pills from 768px that scroll with no bar, tabs below 768px (fixed, 56px and the room of the device, the words in the smallest step)', $shell !== ''
	&& preg_match( '/@media screen and \(min-width: 1024px\) \{.*?\.crm-page__inner:has\(> \.crm-nav\) \{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*11rem minmax\(0, 1fr\);.*?\.crm-page__inner > \.crm-nav \{[^}]*--crm-nav-top:[^;]*var\(--header-height, 0px\)[^;]*;[^}]*grid-row:\s*1 \/ span 99;[^}]*align-self:\s*stretch;.*?body:has\(\.crm-bar\) \.crm-page__inner > \.crm-nav \{\s*--crm-nav-top:\s*calc\( var\(--crm-bar\) \+ var\(--crm-5\) \);\s*\}\s*\.crm-nav__list \{[^}]*position:\s*sticky;[^}]*top:\s*var\(--crm-nav-top\);[^}]*max-height:\s*calc\( 100dvh - var\(--crm-nav-top\) - var\(--crm-5\) \);/s', $shell ) === 1 && preg_match( '/(?<!max-)height:\s*calc\( 100dvh/', $shellRules ) === 0 && preg_match( '/margin-top:\s*auto/', $shellRules ) === 0
	&& preg_match( '/@media screen and \(min-width: 768px\) and \(max-width: 1023\.98px\) \{.*?overflow-x:\s*auto;[^}]*scrollbar-width:\s*none;.*?::-webkit-scrollbar \{\s*display:\s*none;/s', $shell ) === 1
	&& preg_match( '/@media screen and \(max-width: 767\.98px\) \{.*?body:has\(\.crm-nav\) \{[^}]*padding-bottom:\s*calc\( 56px \+ env\(safe-area-inset-bottom\) \);.*?\.crm-nav \{[^}]*position:\s*fixed;[^}]*height:\s*calc\( 56px \+ env\(safe-area-inset-bottom\) \);.*?font-size:\s*var\(--crm-text-xxs\);/s', $shell ) === 1 );
check( '...the side bar is a surface of its own with a line at its right, as the rail of the workbench is: --crm-surface and --crm-edge, from the foot of the bar to the foot of the page and to the edge of the window at the left (a negative margin and the padding that gives it back), the page beside it on --crm-page; the pills are on the surface of the bar with a line under it', preg_match( '/@media screen and \(min-width: 1024px\) \{.*?\.crm-page__inner > \.crm-nav \{[^}]*margin:\s*calc\( -1 \* var\(--crm-5\) \) calc\( -1 \* var\(--crm-4\) \) calc\( -1 \* var\(--crm-7\) \) -100vw;\s*padding:\s*var\(--crm-5\) var\(--crm-4\) var\(--crm-7\) 100vw;\s*border-right:\s*1px solid var\(--crm-edge\);\s*background-color:\s*var\(--crm-surface\);/s', $shell ) === 1
	&& preg_match( '/@media screen and \(min-width: 768px\) and \(max-width: 1023\.98px\) \{.*?\.crm-nav \{[^}]*margin:\s*calc\( -1 \* var\(--crm-5\) \) -100vw var\(--crm-5\);\s*padding:\s*var\(--crm-2\) 100vw;\s*border-bottom:\s*1px solid var\(--crm-edge\);\s*background-color:\s*var\(--crm-surface\);/s', $shell ) === 1
	&& preg_match( '/--crm-edge:\s*var\(--crm-line-strong\);/', $sheet ) === 1 && preg_match( '/\.crm-page\.nino-section \{\s*padding:[^;]*;\s*background-color:\s*var\(--crm-page\);/', $sheet ) === 1 );
check( '...the bar is 64px (--crm-bar), as wide as the window, fixed at the top over the page (z-index above the tabs, below the skip link of the kernel), the surface with the line --crm-edge under it, and its content stands in the column of the page; the page starts under it, the bar\'s height and a step of space', preg_match( '/--crm-bar:\s*64px;/', $sheet ) === 1
	&& preg_match( '/header\.crm-bar \{\s*position:\s*fixed;\s*top:\s*0;\s*left:\s*0;\s*z-index:\s*100;\s*width:\s*100%;\s*height:\s*var\(--crm-bar\);\s*border-bottom:\s*1px solid var\(--crm-edge\);\s*background-color:\s*var\(--crm-surface\);/', $shell ) === 1
	&& preg_match( '/\.crm-bar__inner \{[^}]*max-width:\s*calc\( var\(--crm-page-max\) \+ 2 \* var\(--grid-gutter\) \);[^}]*margin:\s*0 auto;[^}]*padding:\s*0 var\(--grid-gutter\);/', $shell ) === 1
	&& preg_match( '/\.crm-page\.nino-section:has\(\.crm-bar\) \{\s*padding-top:\s*calc\( var\(--crm-bar\) \+ var\(--crm-5\) \);/', $shell ) === 1 && preg_match( '/\.crm-nav \{[^}]*position:\s*fixed;[^}]*z-index:\s*90;/', $shell ) === 1 );
check( '...the account of the bar: the initials 32px on the accent at 16%, the name, the current page marked; Abmelden an icon button of the touch size; below 768px the name is for a screen reader alone (clipped, not display: none) and the chip is a touch-sized circle; the pill of Meine Daten is not in the pills, the chip is that link', preg_match( '/\.crm-account__avatar \{[^}]*width:\s*32px;\s*height:\s*32px;[^}]*color-mix\(in srgb, var\(--crm-info\) 16%, var\(--crm-surface\)\)/', $shell ) === 1
	&& preg_match( '/a\.crm-account\[aria-current="page"\] \{/', $shell ) === 1 && preg_match( '/a\.crm-account__logout \{[^}]*width:\s*var\(--crm-touch\);\s*height:\s*var\(--crm-touch\);/', $shell ) === 1
	&& preg_match( '/@media screen and \(max-width: 767\.98px\) \{.*?\.crm-bar a\.crm-account \{[^}]*min-width:\s*var\(--crm-touch\);[^}]*border-radius:\s*var\(--crm-pill\);.*?\.crm-bar \.crm-account__name \{\s*position:\s*absolute;[^}]*clip:\s*rect\(0, 0, 0, 0\);/s', $shell ) === 1 && preg_match( '/\.crm-nav__item--meine-daten \{\s*display:\s*none;/', $shell ) === 1 && preg_match( '/@media screen and \(min-width: 1024px\) \{.*?\.crm-nav__item--meine-daten \{\s*display:\s*list-item;/s', $shell ) === 1
	&& preg_match( '/a\.crm-bar__brand:has\(img\) \.crm-bar__name \{\s*display:\s*none;/', $shell ) === 1 && preg_match( '/a\.crm-bar__brand img \{[^}]*height:\s*32px;/', $shell ) === 1 );
check( '...the one reach into the frame of the site is a few rules of the Shell, all under body:has(.crm-bar): the head of the site (a header child of the body) is not drawn, main keeps no room for it, and main and the footer do not begin after the rail of a header preset that stands beside the page (the one place the footer is named); the flex columns that carry the side bar to the foot name main; nothing else of the frame, its menu or its logo is named, and the door, the Datenschutz and the Impressum, which have no bar, are not matched (the portal\'s wrap alone is no condition)', preg_match_all( '/(?<![\w-])(?:header|footer|main)(?![\w-])|\.nino-nav|\.nino-frame|\.nino-logo/', (string) preg_replace( '/header\.crm-bar \{/', '{', $shellRules ) ) === 5
	&& preg_match( '/body:has\(\.crm-bar\) > :is\(main, footer\) \{\s*width:\s*auto;\s*margin-left:\s*0;\s*\}/', $shellRules ) === 1 && substr_count( $shellRules, 'footer' ) === 1
	&& preg_match( '/body:has\(\.crm-bar\) > header \{\s*display:\s*none;\s*\}\s*body:has\(\.crm-bar\) main \{\s*padding-top:\s*0;\s*\}/', $shellRules ) === 1 && preg_match( '/body:has\(\.crm-portal\) (?:header|footer|main)\b[^,{]*\{/', (string) substr( $sheet, 0, (int) strpos( $sheet, '/*	14 Print' ) ) ) === 0
	&& preg_match( '/html:has\(\.crm-bar\) \{\s*scroll-padding-top:\s*calc\( var\(--crm-bar\) \+ var\(--crm-4\) \);/', $shellRules ) === 1 && str_contains( substr( $sheet, 0, (int) strpos( $sheet, '/*	1 Tokens' ) ), 'reaches into the frame of the site' ) === true );
check( '...the surface of the side bar runs to the foot of the page where the page is short: from 1024px main, the wrap, the section and its row are columns of the flex kind, each but main taking the room that is left (flex: 1 0 auto), the column of the page the last of them', preg_match( '/@media screen and \(min-width: 1024px\) \{.*?body:has\(\.crm-bar\) main,\s*body:has\(\.crm-bar\) \.crm-portal,\s*body:has\(\.crm-bar\) \.crm-page,\s*body:has\(\.crm-bar\) \.crm-page > \.nino-grid-row \{\s*display:\s*flex;\s*flex-direction:\s*column;\s*\}\s*body:has\(\.crm-bar\) \.crm-portal,\s*body:has\(\.crm-bar\) \.crm-page,\s*body:has\(\.crm-bar\) \.crm-page > \.nino-grid-row,\s*body:has\(\.crm-bar\) \.crm-page__inner \{\s*flex:\s*1 0 auto;/s', $shell ) === 1 );
check( '...a jump to a row or a card ends under the bar once: the row and the offer ask for no scroll margin of their own where the bar is (the scroll padding of the page is the room), and keep theirs where it is not', preg_match( '/\.crm-documents__row,\s*\.crm-offer \{\s*scroll-margin-top:\s*calc\( 2 \* var\(--crm-7\) \);\s*\}\s*body:has\(\.crm-bar\) \.crm-documents__row,\s*body:has\(\.crm-bar\) \.crm-offer \{\s*scroll-margin-top:\s*0;/', $sheet ) === 1 );
check( '...the tab bar has the line of the bar, --crm-edge; the name of the chip is at most 16rem wide and ends in an ellipsis, and below 768px it is for a screen reader alone (clipped)', preg_match( '/@media screen and \(max-width: 767\.98px\) \{.*?\.crm-nav \{[^}]*border-top:\s*1px solid var\(--crm-edge\);/s', $shell ) === 1
	&& preg_match( '/\.crm-account__name \{\s*max-width:\s*16rem;\s*overflow:\s*hidden;\s*text-overflow:\s*ellipsis;\s*white-space:\s*nowrap;/', $shell ) === 1 && preg_match( '/\.crm-bar \.crm-account__name \{\s*position:\s*absolute;[^}]*clip:\s*rect\(0, 0, 0, 0\);/', $shell ) === 1 && str_contains( $shell, 'crm-account__company' ) === false );
check( '...every entry and Abmelden is at least the touch size high, the links of the tab bar fill the bar without a minimum of their own (the bar is 56px with its line), and on paper the bar and the navigation are left out, the page does not keep the room of the bar and the column of the page is no grid', preg_match( '/a\.crm-nav__link \{[^}]*min-height:\s*var\(--crm-touch\);/', $shell ) === 1 && preg_match( '/\.crm-nav__link \{[^}]*height:\s*100%;\s*min-height:\s*0;/s', $shell ) === 1
	&& preg_match( '/@media print \{[^@]*?\.crm-bar,\s*\.crm-nav,\s*\.crm-head__actions,/s', $sheet ) === 1 && preg_match( '/@media print \{.*?\.crm-page__inner:has\(> \.crm-nav\) \{\s*display:\s*block;/s', $sheet ) === 1 && preg_match( '/@media print \{.*?\.crm-page\.nino-section,\s*\.crm-page\.nino-section:has\(\.crm-bar\) \{\s*padding:\s*0;/s', $sheet ) === 1 && substr_count( $shell, '@media screen and' ) === 3 && substr_count( $shell, '@media (' ) === 0 );
check( '...the icon of the start is in Icons, and every icon the navigation asks for', array_diff( [ 'house', 'folder', 'file-text', 'calendar', 'upload', 'user', 'shield-check', 'log-out' ], array_keys( \Crm\Portal\Icons::ICONS ) ) === [] && str_contains( \Crm\Portal\Icons::svg( 'house' ), '<path' ) === true );
check( '...it has no @font-face, no @import, no url(), and !important only twice, both inside the block of reduced motion', preg_match( '/@font-face|@import|url\(/', $sheet ) === 0 && substr_count( $sheet, '!important' ) === 2
	&& preg_match( '/@media \(prefers-reduced-motion: reduce\)\s*\{[^@]*transition: none !important;[^@]*animation: none !important;[^@]*\}\s*\}/s', $sheet ) === 1 );
check( '...it styles the kernel\'s own tabs: a panel that is not the open one is hidden from the first paint and every panel stands open where there is no script (scripting: none), a hidden one is not shown, the active tab takes the link colour', preg_match( '/\.crm-project__section\.nino-tabs-panel\s*\{[^}]*display:\s*none/s', $sheet ) === 1 && preg_match( '/\.crm-project__section\.nino-tabs-panel\.nino-is-active\s*\{[^}]*display:\s*block/s', $sheet ) === 1
	&& preg_match( '/@media \(scripting: none\)\s*\{\s*\.crm-project__section\.nino-tabs-panel\s*\{[^}]*display:\s*block[^}]*\}\s*\}/s', $sheet ) === 1 && preg_match( '/\.crm-project__section\.nino-tabs-panel\[hidden\]\s*\{[^}]*display:\s*none/s', $sheet ) === 1
	&& preg_match( '/\.crm-project__nav [^{]*\.nino-is-active\s*\{[^}]*border-bottom-color:\s*var\(--crm-link\)/s', $sheet ) === 1 );
check( '...and draws the states it knows: each tone of a pill and of a note is a tone, every state of Portal::TONES has one, the overdue due day is marked', array_filter( [ 'info', 'success', 'warning', 'danger', 'muted' ], static fn( string $tone ): bool => preg_match( '/\.crm-pill--'. $tone. '\s*\{\s*--crm-tone:/', $sheet ) !== 1 ) === []
	&& array_filter( [ 'success', 'error', 'warning', 'info' ], static fn( string $tone ): bool => preg_match( '/\.crm-note--'. $tone. '\s*\{\s*--crm-tone:/', $sheet ) !== 1 ) === [] && array_diff( array_values( \Crm\Portal::TONES ), [ 'info', 'success', 'warning', 'danger', 'muted' ] ) === []
	&& array_diff( [ 'open', 'overdue', 'paid', 'accepted', 'signed', 'agreed', 'expired', 'declined', 'cancelled', 'done', 'pending' ], array_keys( \Crm\Portal::TONES ) ) === [] && str_contains( $sheet, '.crm-documents__row[data-state="overdue"] .crm-documents__due' ) );
$noForm = array_filter( (array) glob( $root. '/app/Crm/Portal/templates/*.tpl' ), static fn( string $file ): bool => preg_match( '/class="[^"]*\bnino-form(?![-_a-z])/', (string) file_get_contents( $file ) ) === 1 );
check( 'no template of the portal carries .nino-form: the script of the kernel binds every .nino-form as a contact form that posts by XHR and says the sentence of the Form module - the forms of the portal are plain posts with the answer of the server', $noForm === [] && count( (array) glob( $root. '/app/Crm/Portal/templates/*.tpl' ) ) > 50
	&& preg_match( '/<form [^>]*method="post"[^>]*action="[^"]*\/\.crm\/(daten|datenschutz|auftrag)"/', (string) file_get_contents( $root. '/app/Crm/Portal/templates/data.tpl' ). (string) file_get_contents( $root. '/app/Crm/Portal/templates/privacy-form.tpl' ). (string) file_get_contents( $root. '/app/Crm/Portal/templates/order-form.tpl' ) ) === 1 );
check( '...the corners follow the theme: the surfaces read --crm-radius, which is the theme\'s --radius-surface-alt, the controls --crm-radius-s, which is its --radius, no rule sets a border-radius on a kernel field (.nino-form-input) or on a kernel button (.nino-btn) outside the portal\'s own scopes, and .crm-btn has a border, a radius and the touch height of its own', str_contains( $sheet, '--crm-radius: var(--radius-surface-alt)' ) && str_contains( $sheet, '--crm-radius-s: var(--radius)' ) && str_contains( $sheet, 'var(--radius-large)' ) === false
	&& preg_match( '/\n\.crm-btn \{[^}]*\}/', $sheet, $btn ) === 1 && preg_match( '/border:\s*1px solid transparent;/', $btn[0] ) === 1 && str_contains( $btn[0], 'border-radius: var(--crm-radius-s)' ) && str_contains( $btn[0], 'min-height: var(--crm-touch)' ) && preg_match( '/--crm-touch:\s*44px;/', $sheet ) === 1 );
check( '...the focus ring of a tab is not cut by the scrolling row, the binding sentences keep their case, and the row of tabs is never hidden (it is there from the server, links to the sections, and nothing moves when the script wires it)', preg_match( '/\.crm-project__nav \.crm-project__tab\.nino-tabs-tab:focus-visible\s*\{[^}]*outline-offset:\s*-2px/s', $sheet ) === 1
	&& preg_match( '/\n\.crm-check \{[^}]*letter-spacing:\s*normal;\s*text-transform:\s*none/s', $sheet ) === 1 && preg_match( '/\n\.crm-project__nav[^{,]*\{[^}]*display:\s*none/', $sheet ) !== 1 && preg_match( '/\.crm-project__nav\.nino-tabs-nav\s*\{[^}]*display:\s*flex/s', $sheet ) === 1 );
check( '...the form of Files on the page Upload and the comment form of Review on a project get the look of the portal, scoped to the portal\'s own markup (.files-upload.crm-upload, .crm-project) - and the features\' files are not touched', preg_match( '/\n\.files-upload\.crm-upload \{[^}]*border-radius:\s*var\(--crm-radius\)/s', $sheet ) === 1 && str_contains( $sheet, '::file-selector-button' ) === false && str_contains( $sheet, ':has(~ .crm-uploads)' ) === false
	&& preg_match( '/\.crm-project \.review-form__at,\s*\.crm-project \.review-form__text\s*\{[^}]*border:\s*1px solid var\(--color-border\)/s', $sheet ) === 1 && preg_match( '/\.crm-project \.review-form \.nino-btn,[^{]*\{[^}]*min-height:\s*var\(--crm-touch\)/s', $sheet ) === 1 && preg_match( '/\.crm-project \.review-form__follow:not\(\[hidden\]\)\s*\{[^}]*display:\s*inline-flex;[^}]*min-height:\s*var\(--crm-touch\)/s', $sheet ) === 1
	&& array_filter( (array) preg_split( '/\R/', $sheet ), static fn( string $line ): bool => preg_match( '/^\.(?:files-upload__|review-)[^{]*\{/', $line ) === 1 ) === [] && str_contains( file_get_contents( $root. '/app/Crm/Portal/assets/portal.js' ), "'.files-upload.crm-upload'" ) === true );
check( '...the reply and cancel buttons of the player take the small quiet button, scoped to the project page', preg_match( '/\.crm-project \.review-comment__reply,\s*\.crm-project \.review-form__cancel\s*\{[^}]*background-color:\s*transparent;[^}]*color:\s*var\(--crm-link\)/s', $sheet ) === 1 );
check( '...the rows of the lists are one grid from 1024px (the body is the grid, a row and its line a subgrid), below it a row stacks as a card - no container query -, and the sheet keeps the one literal of the pill, said in a comment', preg_match( '/@media \(min-width: 1024px\)\s*\{\s*\.crm-table__body\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*var\(--crm-cols\)[^@]*\.crm-table__row,\s*\.crm-table__fold\s*\{[^}]*grid-template-columns:\s*subgrid/s', $sheet ) === 1 && str_contains( $sheet, '@container' ) === false
	&& preg_match( '/\.crm-documents \.crm-table\s*\{\s*--crm-cols:\s*minmax\(([0-9.]+)rem, ([0-9.]+)fr\)/s', $sheet, $cols ) === 1 && (float) $cols[1] >= 10
	&& count( portalFind( $html, '//li[contains(@class, "crm-documents__row")]//span[contains(@class, "crm-documents__links")]/a[contains(@class, "crm-documents__download")]' ) ) >= 1
	&& preg_match_all( '/[a-z-]+:\s*999px/', $sheet ) === 1 && preg_match_all( '/a literal on purpose/', $sheet ) === 1 );

check( 'a link is the accent only where it is no button: the rule for the links of the page is a:where(:not(.crm-btn)) - a link that is a primary or a secondary button had the colour of a link on the colour of its ground (1.25:1) - and a primary button reads the ink of its ground', str_contains( $sheet, '.crm-portal a:where(:not(.crm-btn)) {' ) === true
	&& preg_match( '/\n\.crm-btn--primary \{[^}]*color:\s*var\(--color-primary-text\)/s', $sheet ) === 1 && preg_match( '/\n\.crm-btn--secondary \{[^}]*color:\s*var\(--crm-ink\)/s', $sheet ) === 1 && preg_match( '/\n\.crm-btn--ghost \{[^}]*color:\s*var\(--crm-ink-2\)/s', $sheet ) === 1 );
check( 'the measure of the portal is its own: from 768px the six steps of type and the seven of spacing are divided by --crm-step (1.125, the factor with which the site lifts its rem) and the touch size is a pixel size that stays', preg_match( '/@media \(min-width: 768px\)\s*\{\s*:where\(:root\)\s*\{\s*--crm-step:\s*1\.125;/s', $sheet ) === 1
	&& preg_match_all( '/^\s*--crm-(?:text-(?:xs|s|m|l|xl|xxl)|[1-7]):\s*calc\( [.0-9]+rem \/ var\(--crm-step\) \);/m', $sheet ) === 13 && preg_match( '/--crm-touch:\s*44px;/', $sheet ) === 1 );
check( '...the lines are the ink in the ground and not the border role (the border role is the line of a field, 3:1), the dark scheme has its own surfaces and a light along the upper edge in place of a shadow - both in the one value that light-dark() takes - and the radii are not set here: they stay the theme\'s', preg_match( '/--crm-line:\s*light-dark\( color-mix\(in srgb, var\(--color-ink\) 9%, var\(--color-ground\)\), color-mix\(in srgb, var\(--color-ink\) 10%/', $sheet ) === 1
	&& preg_match( '/--crm-line-strong:\s*light-dark\( color-mix\(in srgb, var\(--color-ink\) 22%/', $sheet ) === 1 && preg_match( '/--crm-surface:\s*light-dark\( var\(--color-ground\), color-mix\(in srgb, var\(--color-ground\) 90%/', $sheet ) === 1 && preg_match( '/--crm-surface-2:\s*light-dark\([^;]*84%/', $sheet ) === 1
	&& preg_match( '/--crm-shadow-1:[^;]*inset 0 1px 0 light-dark\( transparent, color-mix\(in srgb, var\(--color-ink\) 6%/', $sheet ) === 1 && preg_match( '/--crm-link:\s*light-dark\( color-mix\(in srgb, var\(--color-accent\) 84%, var\(--color-ink\)\), color-mix\(in srgb, var\(--color-accent\) 70%/', $sheet ) === 1
	&& preg_match( '/^\s*--radius(?:-[a-z-]+)?\s*:/m', $sheet ) !== 1 && preg_match( '/^\s*--crm-radius(?:-s)?:\s*var\(--radius/m', $sheet ) === 1 );
check( '...the danger as text on a surface is a token of its own (a due day that is past, the error of a field) and the focus ring is the theme\'s focus role pulled towards the ink, so that it stands on the page, a card and a block: no rule but the token reads the role itself', preg_match( '/--crm-danger-ink:\s*light-dark\( color-mix\(in srgb, var\(--crm-danger\) 85%/', $sheet ) === 1 && preg_match( '/\.crm-field__error \{[^}]*color:\s*var\(--crm-danger-ink\)/s', $sheet ) === 1
	&& preg_match( '/\.crm-documents__row\[data-state="overdue"\] \.crm-documents__due \{[^}]*color:\s*var\(--crm-danger-ink\)/s', $sheet ) === 1 && preg_match( '/--crm-focus:\s*color-mix\(in srgb, var\(--color-focus\) 70%, var\(--crm-ink\)\)/', $sheet ) === 1
	&& preg_match( '/\.crm-portal :focus-visible \{[^}]*outline:\s*2px solid var\(--crm-focus\);[^}]*outline-offset:\s*2px/s', $sheet ) === 1 && substr_count( $sheet, 'var(--color-focus)' ) === 1 );
check( '...the list of documents is a page of its own length: a list is a card that clips its corners and has no height of its own (the rule of .crm-table sets overflow and no max-height) - six columns with the first for the title, the due day the second line of the column of the amount, the actions of a row quiet icons of the touch size from 1024px, and an invoice that is past its day is a row with a line of the danger', preg_match( '/\n\.crm-table \{(?:(?!max-height)[^}])*overflow:\s*hidden;(?:(?!max-height)[^}])*\}/', $sheet ) === 1
	&& preg_match( '/\.crm-documents \.crm-table\s*\{\s*--crm-cols:\s*minmax\(14rem, 2fr\) minmax\(6rem, 1fr\) 5\.5rem 7rem auto auto;/', $sheet ) === 1 && preg_match( '/\.crm-documents__due \{\s*grid-column:\s*4;\s*grid-row:\s*2;/', $sheet ) === 1
	&& preg_match( '/\.crm-documents__download,\s*\.crm-table__toggle,\s*\.crm-files__download \{[^}]*min-width:\s*var\(--crm-touch\);[^}]*background-color:\s*transparent/s', $sheet ) === 1 && preg_match( '/\.crm-documents__row\[data-state="overdue"\] \{\s*box-shadow:\s*inset 3px 0 0 var\(--crm-danger\)/', $sheet ) === 1
	&& preg_match( '/\.crm-documents__row\[data-sign-state="signed"\] \{\s*box-shadow:\s*inset 3px 0 0 var\(--crm-success\)/', $sheet ) === 1 && preg_match( '/\.crm-documents__row\[data-sign-state="declined"\] \{\s*box-shadow:\s*inset 3px 0 0 var\(--crm-muted\)/', $sheet ) === 1 && preg_match( '/\.crm-documents__row,\s*\.crm-offer \{\s*scroll-margin-top:\s*calc\( 2 \* var\(--crm-7\) \)/', $sheet ) === 1
	&& preg_match( '/\.crm-pay__fact--iban dd,[^{]*\{[^}]*font-family:\s*var\(--fontfamily-code\)/s', $sheet ) === 1 && preg_match( '/\.crm-documents__date:has\(~ \.crm-documents__amount:not\(:empty\)\)::after/', $sheet ) === 1 );
check( '...the start page is a dashboard: key figures in a grid of tiles of their own, the cards dense and stretched so that no card leaves a hole under it, the long card of the documents over two rows from 1024px, the date of a card under its title, and the projects tiles from 640px', preg_match( '/\.crm-kpis \{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(auto-fit, minmax\(min\(100%, 10rem\), 1fr\)\)/s', $sheet ) === 1
	&& preg_match( '/\.crm-start__grid \{[^}]*grid-auto-flow:\s*dense;\s*align-items:\s*stretch;/s', $sheet ) === 1 && preg_match( '/@media \(min-width: 1024px\)\s*\{\s*\.crm-start__card\[data-card="documents"\]\s*\{\s*grid-row:\s*span 2;/s', $sheet ) === 1
	&& preg_match( '/\.crm-start__card\[data-card="dates"\] \.crm-start__main \{\s*display:\s*grid;/', $sheet ) === 1 && preg_match( '/@media \(min-width: 640px\)\s*\{\s*\.crm-projects__list \{\s*grid-template-columns:\s*repeat\(auto-fill, minmax\(18rem, 1fr\)\)/s', $sheet ) === 1
	&& preg_match( '/\.crm-kpi--warn \.crm-kpi__value \{[^}]*color-mix\(in srgb, var\(--crm-warning\) 80%, var\(--crm-ink\)\)/s', $sheet ) === 1 );
check( '...the accent marks what is done: the title of a project, of a file and of a row of the start page are the ink and take the link colour when the pointer is over them, the project in a list of documents is underlined in the second ink, and the quiet button and the crumbs are the second and third ink', preg_match( '/\.crm-projects__title \{[^}]*color:\s*var\(--crm-ink\);/s', $sheet ) === 1 && preg_match( '/\.crm-start__text \{[^}]*color:\s*var\(--crm-ink\);/s', $sheet ) === 1 && preg_match( '/a\.crm-files__name \{[^}]*color:\s*var\(--crm-ink\);/s', $sheet ) === 1
	&& preg_match( '/a\.crm-documents__project \{[^}]*color:\s*var\(--crm-ink-2\);[^}]*text-decoration:\s*underline/s', $sheet ) === 1 && preg_match( '/a\.crm-crumbs__link \{[^}]*color:\s*var\(--crm-ink-3\)/s', $sheet ) === 1 && preg_match( '/\.crm-project__tab\.nino-is-active \.crm-project__count \{[^}]*color:\s*var\(--crm-ink-2\)/s', $sheet ) === 1
	&& preg_match( '/\.files-upload\.crm-upload \.files-upload__label \{[^}]*color:\s*var\(--crm-ink\);/s', $sheet ) === 1 );
check( '...on paper a card is not cut across a page, but the list that is a card may break: the rule of break-inside names the cards that are no table', preg_match( '/@media print\s*\{.*\.crm-card:not\(\.crm-table\),\s*\.crm-table__row,[^{]*\{\s*break-inside:\s*avoid;/s', $sheet ) === 1 );
check( '...a page on paper: the frame, the menu, the actions of the head, the folds\' buttons and the forms are left out, the fixed head of the site is static, a row is not cut across a page, and what stands folded is opened where the engine can', preg_match( '/@media print\s*\{[^@]*body:has\(\.crm-portal\) header,[^{]*\.crm-head__actions,[^{]*\{\s*display:\s*none;/s', $sheet ) === 1 && preg_match( '/@media print\s*\{.*body:has\(\.crm-portal\) \.nino-frame-header \{\s*position:\s*static;/s', $sheet ) === 1
	&& preg_match( '/@media print\s*\{.*\.crm-table__row,[^{]*\{\s*break-inside:\s*avoid;/s', $sheet ) === 1 && preg_match( '/@media print\s*\{.*details::details-content \{\s*content-visibility:\s*visible;/s', $sheet ) === 1 );
check( '...the small things: the box of a check is a line of the touch size with its box in the middle, the door speaks as the head does - at the left, in the ink, at the size of a page title - and a note can be muted', preg_match( '/\n\.crm-check \{[^}]*align-items:\s*center;[^}]*min-height:\s*var\(--crm-touch\)/s', $sheet ) === 1
	&& preg_match( '/\.crm-page--door \.nino-section-title \{[^}]*color:\s*var\(--crm-ink\);[^}]*font-size:\s*var\(--crm-text-xxl\);[^}]*text-align:\s*left;/s', $sheet ) === 1 && preg_match( '/\.crm-note--muted \{\s*--crm-tone:/', $sheet ) === 1 );
check( '...a pill says its state in lower case, in German and in English - the project that runs and the one that is done as the offer that is open - and an invoice that is overdue says only that', array_filter( [ $wordsDe, $wordsEn ], static fn( array $words ): bool => array_filter( [ 'projects/state-open', 'projects/state-done', 'files/new', 'files/playing' ], static fn( string $name ): bool => preg_match( '/^\p{Ll}/u', $words['[[/project/crm-portal/'. $name. ']]'] ) !== 1 ) !== [] || array_filter( [ 'open', 'overdue', 'paid', 'cancelled', 'accepted', 'declined', 'signed', 'expired' ], static fn( string $name ): bool => preg_match( '/^\p{Ll}+$/u', $words['[[/project/crm-documents/state/'. $name. ']]'] ) !== 1 ) !== [] ) === [] );
check( 'the way to the Datenschutz under an offer is a small button beside its sentence, of the touch size, and no link in the middle of it - in the list and on the page of a project', ( static function() use ( $token ): bool {

	$note = \Crm\Portal\Order::privacyHint( '' );
	$link = portalFind( $note, '//p[contains(@class, "crm-order--privacy")]/a['. $token( 'crm-btn' ). ']['. $token( 'crm-btn--small' ). ']['. $token( 'crm-btn--secondary' ). ']' );

	return count( $link ) === 1 && portalFind( $note, '//p[contains(@class, "crm-order--privacy")]/span[contains(@class, "crm-note__text")]//a' ) === [] && $link[0]->getAttribute( 'href' ) === '/portal/datenschutz';
} )() === true );

// --- [crm-documents]: the project -----------------------------------------------------------------------------------------------

echo "\n[crm-documents] and the project\n";

portalSignIn( $appData, $accounts['anna'] );
$all = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
$projectCell = static fn( string $html, int $id ): array => portalFind( $html, '//li[@data-document="'. $id. '"]//*[contains(@class, "crm-documents__project")]' );
check( 'every row has the cell of its project: a link to the page of the project, with its title, where the document has one - and an empty cell where it has none', $projectCell( $all, $invoiceP1['id'] )[0]->nodeName === 'a' && $projectCell( $all, $invoiceP1['id'] )[0]->getAttribute( 'href' ) === '/portal/projekte?projekt='. $p1. '&tab=belege'
	&& trim( $projectCell( $all, $invoiceP1['id'] )[0]->textContent ) === 'Album 2027' && $projectCell( $all, $invoiceP2['id'] )[0]->getAttribute( 'href' ) === '/portal/projekte?projekt='. $p2. '&tab=belege' && count( $projectCell( $all, $invoiceA['id'] ) ) === 1
	&& trim( $projectCell( $all, $invoiceA['id'] )[0]->textContent ) === '' && $projectCell( $all, $invoiceA['id'] )[0]->getAttribute( 'href' ) === '' && count( portalFind( $all, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' ) ) === count( portalFind( $all, '//li//*[contains(@class, "crm-documents__project")]' ) ) );
Db::exec( $appData, 'UPDATE documents SET project = \'00099\' WHERE id = :id', [ 'id' => $invoiceA['id'] ] );
$gone = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'a document whose project is gone from the type has the empty cell, not a link with no title', portalFind( $gone, '//li[@data-document="'. $invoiceA['id']. '"]//a[contains(@class, "crm-documents__project")]' ) === [] && count( $projectCell( $gone, $invoiceA['id'] ) ) === 1 );
Db::exec( $appData, "UPDATE documents SET project = '' WHERE id = :id", [ 'id' => $invoiceA['id'] ] );
$one = \Nino\Html::renderHtml( $appData, '[crm-documents project="'. $p1. '"]' );
check( 'project="<id>" lists the documents of that project and none other, and leaves the cell of the project out', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-document' ), portalFind( $one, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' ) ) === [ (string) $invoiceP1['id'], (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ]
	&& portalFind( $one, '//*[contains(@class, "crm-documents__project")]' ) === [] );
check( '...a project that has no document, one of another client, one that is none and one that is no id are the sentence that there are none', array_filter( [ $p4, $p3, '00099', '../x', "a'b" ], static fn( string $id ): bool => str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents project="'. htmlspecialchars( $id, ENT_QUOTES ). '"]' ), 'crm-documents--empty' ) === false ) === [] );
$byProject = Documents::released( $appData, $a['id'], $p1 );
check( 'Documents::released() takes the project: only that project\'s documents, each with its project and the title; without it every document, the project empty where there is none', array_column( $byProject, 'id' ) === [ $invoiceP1['id'], $offerRegular['id'], $offerReverse['id'], $offerSmall['id'] ] && array_unique( array_column( $byProject, 'project' ) ) === [ $p1 ] && array_unique( array_column( $byProject, 'project_title' ) ) === [ 'Album 2027' ]
	&& Documents::released( $appData, $a['id'], $p3 ) === [] && Documents::released( $appData, $a['id'], '00099' ) === []
	&& array_column( array_column( Documents::released( $appData, $a['id'] ), null, 'id' ), 'project', 'id' )[$invoiceA['id']] === '' && array_column( array_column( Documents::released( $appData, $a['id'] ), null, 'id' ), 'project_title', 'id' )[$invoiceP2['id']] === 'Webseite'
	&& array_column( Documents::released( $appData, $a['id'] ), 'valid_until', 'id' )[$offerRegular['id']] === '2026-12-31' );

// --- The uploads ------------------------------------------------------------------------------------------------------------------

echo "\n[crm-uploads] and the page Upload\n";

$asVisitor();
check( 'a visitor gets nothing of the uploads', trim( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ) ) === '' );
portalSignIn( $appData, $accounts['anna'] );
$noUpload = \Nino\Html::renderHtml( $appData, '[crm-uploads]' );
check( 'a group without an upload folder reads the sentence that the folder has to be made: a block of its own, no list, and no form of Files on the page', portalFind( $noUpload, '//section[contains(@class, "crm-uploads--empty")]/div[contains(@class, "crm-uploads__empty")]/svg[@aria-hidden="true"]/following-sibling::p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( $noUpload, '//ul' ) === []
	&& portalFind( portalPage( $appData, '/portal/upload' )[1], '//form[contains(@class, "files-upload")]' ) === [] && str_contains( $noUpload, '[[' ) === false );
$store::markUpload( $appData, $folderUp['id'], true );
$uploads = \Nino\Html::renderHtml( $appData, '[crm-uploads]' );
$uploadRows = portalFind( $uploads, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' );
check( 'a group with an upload folder: its files the member may see, the member\'s own among them, not another account\'s hidden ones, the newest first - name, size, day and the download', array_map( static fn( \DOMElement $row ): string => trim( portalText( $row->ownerDocument->saveHTML( $row ), '//a[contains(@class, "crm-files__name")]' ) ), $uploadRows ) === [ 'Eingang-eigen.txt', 'Eingang-sichtbar.txt' ]
	&& str_contains( $uploads, 'Eingang-carl.txt' ) === false && portalFind( $uploads, '//li[contains(@class, "crm-files__row")][1]//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $upOwn['id']. '?download=1' && portalText( $uploads, '//li[contains(@class, "crm-files__row")][1]//span[contains(@class, "crm-files__size")]' ) !== ''
	&& portalFind( $uploads, '//li[contains(@class, "crm-files__row")][1]//time/@datetime' )[0]->nodeValue === $upOwn['created'] && portalFind( $uploads, '//a[contains(@class, "crm-files__listen")]' ) === [] );
check( '...the list says that uploads are temporary (a note, in its block)', portalText( $uploads, '//p[contains(@class, "crm-uploads__note")]' ) !== '' && portalText( $uploads, '//h3[contains(@class, "crm-uploads__heading")]' ) !== '' );
$uploadPage = portalPage( $appData, '/portal/upload' )[1];
check( 'the page Upload has the form of Files for the folder of the group - posting to /.files/upload with the folder - and the list under it', count( portalFind( $uploadPage, '//form[contains(@class, "files-upload")][contains(@class, "crm-upload")]' ) ) === 1 && portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/@action' )[0]->nodeValue === '/.files/upload'
	&& portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/input[@name="folder"]/@value' )[0]->nodeValue === $folderUp['id'] && count( portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/following-sibling::section[contains(@class, "crm-uploads")]' ) ) === 1 && count( portalFind( $uploadPage, '//nav[contains(@class, "crm-nav")]' ) ) === 1 );
$noEmpty = $put( $folderUp, 'Zusatz.txt', 'txt', 'text/plain', true, $owner, $ago( 10 ) );
check( 'a file put there later is in the list at once, on top', trim( portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' )[0]->getElementsByTagName( 'a' )->item( 0 )->textContent ) === 'Zusatz.txt' );
portalSignIn( $appData, $accounts['ben'] );
check( 'a client whose group has no upload folder reads the sentence, whatever another group has', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//section[contains(@class, "crm-uploads--empty")]' ) !== [] );
portalSignIn( $appData, $stray );
check( 'a member of no group too', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//section[contains(@class, "crm-uploads--empty")]' ) !== [] );
portalSignIn( $appData, $accounts['anna'] );
\Nino\Features::deactivate( $appData, 'review' );
\Nino\Features::deactivate( $appData, 'files' );
check( 'with Files off the block says so', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//section[contains(@class, "crm-uploads--empty")]' ) !== [] );
\Nino\Features::activate( $appData, 'files' );
\Nino\Features::activate( $appData, 'review' );

// --- Removed files -----------------------------------------------------------------------------------------------------------------

echo "\n[removed files]\n";

$portalSheet = (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' );
check( 'the button Antworten of a comment, drawn hidden, stays hidden for as long as it is: the display the portal gives the buttons of Review does not override the attribute', preg_match( '/\.crm-project \.review-comment__reply\[hidden\]\s*\{\s*display:\s*none;/', $portalSheet ) === 1 );
$newOnStart = static function() use ( &$appData, $p1 ): int {
	foreach( portalFind( portalPage( $appData, '/portal' )[1], '//article[@data-card="files"]//li[contains(@class, "crm-start__row")]' ) as $row ) {
		$html = $row->ownerDocument->saveHTML( $row );

		if( str_contains( (string) ( portalFind( $html, '//a[contains(@class, "crm-start__link")]/@href' )[0]->nodeValue ?? '' ), 'projekt='. $p1. '&' ) === true )
			return (int) portalText( $html, '//span[@class="crm-start__extra"]' );
	}

	return 0;
};
portalSignIn( $appData, $accounts['anna'] );
$vanishedSong = $put( $folderA, 'Entfernt <b>Song</b>.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 120 ) );
$vanishedPdf = $put( $folderA, 'Entfernt.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 130 ) );
\Nino\Modules\Review::add( $appData, $vanishedSong['id'], [ 'kind' => 'member', 'id' => $accounts['anna']['id'] ], 12.0, 'Der Einsatz <i>kommt</i> zu früh' );
$newBefore = $newOnStart();
$rowsBefore = count( portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) );
\Nino\Modules\Files\Store::removeBytes( $appData, $vanishedSong['id'] );
\Nino\Modules\Files\Store::removeBytes( $appData, $vanishedPdf['id'] );
$goneHtml = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$goneRows = portalFind( $goneHtml, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row--gone ")]' );
$goneRow = $goneRows === [] ? '' : $goneRows[0]->ownerDocument->saveHTML( $goneRows[0] );
check( 'a file that was removed from the server stays in the list of the project, in its place among the others, as a row of its own: its name as text - no link to the player, none to the download -, its size and its day',
	count( $goneRows ) === 2 && count( portalFind( $goneHtml, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === $rowsBefore && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-files-id' ), $goneRows ) === [ $vanishedSong['id'], $vanishedPdf['id'] ]
	&& portalFind( $goneRow, '//a[contains(@class, "crm-files__name")]' ) === [] && trim( portalText( $goneRow, '//span[contains(@class, "crm-files__name")]' ) ) === 'Entfernt <b>Song</b>.mp3' && portalFind( $goneRow, '//b' ) === []
	&& portalText( $goneRow, '//span[contains(@class, "crm-files__size")]' ) !== '' && portalFind( $goneRow, '//time/@datetime' )[0]->nodeValue === $vanishedSong['created'] );
check( '...and no way to open it: no download, no "Anhören", no "neu" - nothing a link points at but the sentence',
	array_filter( $goneRows, static fn( \DOMElement $row ): bool => portalFind( $row->ownerDocument->saveHTML( $row ), '//a[contains(@class, "crm-files__download") or contains(@class, "crm-files__listen")] | //span[contains(@class, "crm-files__new")] | //a[contains(@href, ".files")] | //a[contains(@href, "review=")]' ) !== [] ) === []
	&& count( portalFind( $goneHtml, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")][not(contains(@class, "crm-files__row--gone"))]//a[contains(@class, "crm-files__download")]' ) ) === $rowsBefore - 2 );
check( '...the sentence stands in the place of the buttons: the words of the portal, muted, with the contact address of the site as the link that writes to it - and nothing in it is markup of the name',
	count( portalFind( $goneRow, '//p[contains(@class, "crm-files__gone")]' ) ) === 1 && portalText( $goneRow, '//p[contains(@class, "crm-files__gone")]' ) !== '' && str_contains( portalText( $goneRow, '//p[contains(@class, "crm-files__gone")]' ), '[[' ) === false
	&& portalFind( $goneRow, '//p[contains(@class, "crm-files__gone")]/a/@href' )[0]->nodeValue === 'mailto:owner@example.org' && portalText( $goneRow, '//p[contains(@class, "crm-files__gone")]/a' ) === \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/files/gone-ask' )
	&& str_starts_with( portalText( $goneRow, '//p[contains(@class, "crm-files__gone")]' ), (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/files/gone' ) ) === true );
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'no-address' ], '*' );
$noMail = portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], '//li[contains(@class, "crm-files__row--gone")]//p[contains(@class, "crm-files__gone")]' );
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
check( '...a site without a contact address has the sentence with no link', count( $noMail ) === 2 && portalFind( $noMail[0]->ownerDocument->saveHTML( $noMail[0] ), '//a' ) === [] && trim( $noMail[0]->textContent ) !== '' );
check( 'the card of the new files on the start page does not count it, and a file that was removed is no file the member may open from the page Upload either - the same row',
	$newBefore - $newOnStart() === 2 && $newOnStart() >= 0 );

$reviewGone = portalPage( $appData, '/portal/projekte?review='. $vanishedSong['id'] )[1];
$reviewBoxes = portalFind( $reviewGone, '//div[contains(@class, "review-player")]' );
check( '?review=<a file that was removed> shows the page of its project with the comments of the file and no medium: the sentence stands over them, the player has no audio, no video and no form, and the comment is text',
	count( $reviewBoxes ) === 1 && portalFind( $reviewGone, '//audio | //video | //form[contains(@class, "review-form")]' ) === [] && str_contains( $reviewGone, 'Der Einsatz &lt;i&gt;kommt&lt;/i&gt; zu früh' ) === true && portalFind( $reviewGone, '//div[contains(@class, "review-player")]//i' ) === []
	&& count( portalFind( $reviewGone, '//p[contains(@class, "crm-files__gone-note")]/following-sibling::div[contains(@class, "review-player")]' ) ) === 1 && portalFind( $reviewGone, '//p[contains(@class, "crm-files__gone-note")]//a/@href' )[0]->nodeValue === 'mailto:owner@example.org'
	&& $reviewBoxes[0]->getAttribute( 'data-review-file' ) === $vanishedSong['id'] && portalFind( $reviewGone, '//li[contains(@class, "crm-files__row--playing")]' ) === [] );
check( '...and no button Antworten on its comment - there is no form to answer in, and a button the script never shows would stand there dead', count( portalFind( $reviewGone, '//li[@data-review-id]' ) ) === 1 && portalFind( $reviewGone, '//*[@data-review-reply]' ) === [] );
$goneStranger = portalPage( $appData, '/portal/projekte?projekt='. $p3. '&review='. $vanishedSong['id'] )[1];
check( '...a file that was removed is no reason to show it in a project it is not in: the page of another client\'s project has no player and no sentence of it', portalFind( $goneStranger, '//div[contains(@class, "review-player")] | //p[contains(@class, "crm-files__gone-note")]' ) === [] );
$noReviewGone = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $vanishedSong['id'] );
check( 'a removed file that is no audio or video asked for with ?review= has no sentence over its row and no player: the list is the page', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $vanishedPdf['id'] )[1], '//p[contains(@class, "crm-files__gone-note")] | //div[contains(@class, "review-player")]' ) === []
	&& $noReviewGone[1] !== '' );
$goneRoute = portalServe( $appData, '/.files/'. $vanishedSong['id'] );
check( 'the address of it answers 410 with the sentence, to the member whose file it is', $goneRoute['statusCode'] === 410 && str_contains( (string) $goneRoute['body'], (string) \Nino\Html::resolveTextfill( $appData, '/feature/files/download/removed' ) ) === true );
$store::markUpload( $appData, $folderUp['id'], true );
$upGone = $put( $folderUp, 'Eingang-entfernt.txt', 'txt', 'text/plain', true, $owner, $ago( 20 ) );
\Nino\Modules\Files\Store::removeBytes( $appData, $upGone['id'] );
$upGoneHtml = \Nino\Html::renderHtml( $appData, '[crm-uploads]' );
check( 'the list of the uploads has the row too: a file of the upload folder that was removed, with the sentence and no download', count( portalFind( $upGoneHtml, '//li[contains(@class, "crm-files__row--gone")]' ) ) === 1 && portalFind( $upGoneHtml, '//li[contains(@class, "crm-files__row--gone")]//a[contains(@class, "crm-files__download")]' ) === []
	&& portalFind( $upGoneHtml, '//li[contains(@class, "crm-files__row--gone")]//p[contains(@class, "crm-files__gone")]/a' ) !== [] );
$store::markUpload( $appData, $folderUp['id'], false );
foreach( [ $vanishedSong, $vanishedPdf, $upGone ] as $removedFile )
	$store::deleteFile( $appData, $removedFile['id'] );

// --- The page Termine -------------------------------------------------------------------------------------------------------------

echo "\nThe page Termine\n";

$dates = portalPage( $appData, '/portal/termine' )[1];
check( 'while there is no date, the page says so in an empty state: an icon, the sentence and the studio\'s e-mail and phone as links', portalFind( $dates, '//div[contains(@class, "crm-dates--empty")]/div[contains(@class, "crm-empty")]/svg[@aria-hidden="true"]/following-sibling::p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( $dates, '//li[contains(@class, "crm-dates__row")]' ) === []
	&& portalFind( $dates, '//p[contains(@class, "crm-empty__contact")]/a[starts-with(@href, "mailto:owner@")]' ) !== [] && portalFind( $dates, '//p[contains(@class, "crm-empty__contact")]/a[starts-with(@href, "tel:")]' ) !== [] && str_contains( $dates, '[events' ) === false );
$soon = static fn( int $days ): string => date( 'Y-m-d\TH:i', strtotime( '+'. $days. ' days 10:00' ) );
\Nino\Elements::insertElement( $appData, '/events/aufnahme-a', [ 'title' => 'Aufnahme Gruppe A', 'start' => $soon( 12 ), 'end' => date( 'Y-m-d\TH:i', strtotime( '+12 days 12:00' ) ), 'place' => 'Studio 1', 'group' => $a['group'] ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/aufnahme-b', [ 'title' => 'Aufnahme Gruppe B', 'start' => $soon( 13 ), 'group' => $b['group'] ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/offen', [ 'title' => 'Offener Termin', 'start' => $soon( 20 ) ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/vorbei', [ 'title' => 'Vergangener Termin', 'start' => date( 'Y-m-d\TH:i', strtotime( '-30 days 10:00' ) ), 'group' => $a['group'] ], 'de_DE' );
$dates = portalPage( $appData, '/portal/termine' )[1];
$dateRows = portalFind( $dates, '//li[contains(@class, "crm-dates__row")]' );
check( 'the page lists the coming dates of the client\'s group and the dates for everyone - not those of another group, not the ones that are over: a card each, with an anchor by its name, the day as a time element, a title, the time of day, the place and the link to the calendar file', str_contains( $dates, 'Aufnahme Gruppe A' ) === true && str_contains( $dates, 'Offener Termin' ) === true
	&& str_contains( $dates, 'Aufnahme Gruppe B' ) === false && str_contains( $dates, 'Vergangener Termin' ) === false && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'id' ), $dateRows ) === [ 'termin-aufnahme-a', 'termin-offen' ] && array_filter( $dateRows, static fn( \DOMElement $row ): bool => str_contains( $row->getAttribute( 'class' ), 'crm-card' ) === false ) === []
	&& count( portalFind( $dates, '//li[contains(@class, "crm-dates__row")]/time[contains(@class, "crm-dates__when")]/@datetime' ) ) === 2 && portalText( $dates, '//li[@id="termin-aufnahme-a"]//span[contains(@class, "crm-dates__title")]' ) === 'Aufnahme Gruppe A' && portalText( $dates, '//li[@id="termin-aufnahme-a"]//span[contains(@class, "crm-dates__place")]' ) === 'Studio 1'
	&& str_ends_with( portalText( $dates, '//li[@id="termin-aufnahme-a"]//span[contains(@class, "crm-dates__time")]' ), '10:00–12:00' ) === true && str_ends_with( portalText( $dates, '//li[@id="termin-offen"]//span[contains(@class, "crm-dates__time")]' ), '10:00' ) === true
	&& array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( $dates, '//li[contains(@class, "crm-dates__row")]//span[contains(@class, "crm-dates__ics")]/a[contains(@class, "events-ics")]' ) ) === [ '/.events/aufnahme-a.ics', '/.events/offen.ics' ] );
check( '...with the link to the feed of the member (the token of the account is in it) as a button in the page head, and the navigation of the portal once before it', count( portalFind( $dates, '//a[contains(@class, "events-feed")]' ) ) === 1 && count( portalFind( $dates, '//div[contains(@class, "crm-head__actions")]//span[contains(@class, "crm-btn--holds")]/a[contains(@class, "events-feed")]' ) ) === 1
	&& preg_match( '~\?k=[A-Za-z0-9_-]+$~', portalFind( $dates, '//a[contains(@class, "events-feed")]/@href' )[0]->nodeValue ) === 1 && count( portalFind( $dates, '//nav[contains(@class, "crm-nav")]' ) ) === 1 && portalLeft( $dates ) === false );
portalSignIn( $appData, $accounts['ben'] );
$benDates = portalPage( $appData, '/portal/termine' )[1];
check( 'another client sees the dates of its own group and the open ones: not those of Studio Nord', str_contains( $benDates, 'Aufnahme Gruppe B' ) === true && str_contains( $benDates, 'Offener Termin' ) === true && str_contains( $benDates, 'Aufnahme Gruppe A' ) === false );
portalSignIn( $appData, $stray );
$strayDates = portalPage( $appData, '/portal/termine' )[1];
check( 'a member of no group sees the open dates alone', str_contains( $strayDates, 'Offener Termin' ) === true && str_contains( $strayDates, 'Aufnahme Gruppe' ) === false );
portalSignIn( $appData, $accounts['anna'] );

// --- Meine Daten ---------------------------------------------------------------------------------------------------------------------

echo "\n[crm-client-data] and POST /.crm/daten\n";

$asVisitor();
check( 'a visitor gets nothing of the form', trim( \Nino\Html::renderHtml( $appData, '[crm-client-data]' ) ) === '' );
portalSignIn( $appData, $stray );
check( 'a member of no client is told there is no data: a block without a form', portalFind( \Nino\Html::renderHtml( $appData, '[crm-client-data]' ), '//div[contains(@class, "crm-data--empty")]' ) !== [] && portalFind( \Nino\Html::renderHtml( $appData, '[crm-client-data]' ), '//form' ) === [] );
portalSignIn( $appData, $accounts['anna'] );
\Crm\Clients::change( $appData, $a['id'], [ 'company' => 'Nord "A&B" <x> [members-name]', 'city' => 'Kiel', 'phone' => '+49 40 123', 'vat_id' => 'DE123456789' ] );
$client = (array) \Crm\Clients::get( $appData, $a['id'] );
$data = portalPage( $appData, '/portal/meine-daten' )[1];
$inputs = portalFind( $data, '//form[contains(@class, "crm-data")]//input[@name]' );
$values = array_column( array_map( static fn( \DOMElement $input ): array => [ $input->getAttribute( 'name' ), $input->getAttribute( 'value' ) ], $inputs ), 1, 0 );
check( 'the form posts to /.crm/daten and has the fields Firma, Name, Straße, PLZ, Ort, Land (a select), Telefon, USt-IdNr. with the values of the client - and the csrf token', portalFind( $data, '//form[contains(@class, "crm-data")]/@action' )[0]->nodeValue === '/.crm/daten' && portalFind( $data, '//form[contains(@class, "crm-data")]/@method' )[0]->nodeValue === 'post'
	&& array_keys( $values ) === [ '_csrf', 'company', 'name', 'street', 'zip', 'city', 'phone', 'vat_id' ] && $values['_csrf'] === \Nino\Csrf::getToken( $appData )
	&& array_diff_key( $values, [ '_csrf' => 1 ] ) === array_intersect_key( $client, array_flip( [ 'company', 'name', 'street', 'zip', 'city', 'phone', 'vat_id' ] ) )
	&& portalFind( $data, '//form//select[@name="country"]/option[@selected]/@value' )[0]->nodeValue === $client['country'] && portalFind( $data, '//form//select[@name="country"]/option[@value="DE"]' ) !== [] );
check( '...every value is text: markup and a shortcode in the company are escaped, and each field has its label and the length the client takes', str_contains( $data, 'Nord &quot;A&amp;B&quot; &lt;x&gt; &#91;members-name]' ) === true && str_contains( $data, '<x>' ) === false
	&& count( portalFind( $data, '//form[contains(@class, "crm-data")]//label[@for]' ) ) === 9 &&  portalFind( $data, '//input[@name="company"]/@maxlength' )[0]->nodeValue === '120' && portalFind( $data, '//input[@name="phone"]/@type' )[0]->nodeValue === 'tel' );
$email = portalFind( $data, '//form[contains(@class, "crm-data")]//input[@readonly]' );
check( '...the e-mail of the account is shown and cannot be sent: readonly, no name, with its own sentence', count( $email ) === 1 && $email[0]->getAttribute( 'value' ) === 'anna@example.org' && $email[0]->getAttribute( 'name' ) === '' && portalFind( $data, '//*[@id="crm-data-email-hint"]' ) !== [] && portalFind( $data, '//form[contains(@class, "crm-data")]//button[@type="submit"]' ) !== [] );
check( '...the form is a plain form (no .nino-form: the kernel\'s script would post it as a contact form), every input of it - the e-mail among them - the kernel\'s .nino-form-input or .nino-form-select, the fields stand in one .crm-card, and the button is the portal\'s primary one (.crm-btn)', count( portalFind( $data, '//form[contains(concat(" ", normalize-space(@class), " "), " crm-data__form ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-form "))]' ) ) === 1
	&& count( portalFind( $data, '//form//input[not(@type="hidden")]' ) ) === 8 && count( portalFind( $data, '//form//input[not(@type="hidden")][contains(concat(" ", normalize-space(@class), " "), " nino-form-input ")]' ) ) === 8 && count( portalFind( $data, '//form//select[contains(concat(" ", normalize-space(@class), " "), " nino-form-select ")]' ) ) === 1
	&& count( portalFind( $data, '//form//div[contains(concat(" ", normalize-space(@class), " "), " crm-data__card ")][contains(concat(" ", normalize-space(@class), " "), " crm-card ")]/div[contains(concat(" ", normalize-space(@class), " "), " crm-fields ")]//input[contains(concat(" ", normalize-space(@class), " "), " nino-form-input ")]' ) ) === 8
	&& count( portalFind( $data, '//form//button[@type="submit"][contains(concat(" ", normalize-space(@class), " "), " crm-btn ")][contains(concat(" ", normalize-space(@class), " "), " crm-btn--primary ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-btn "))]' ) ) === 1 );
check( '...no state and no error before anything was posted', portalFind( $data, '//*[contains(@class, "crm-data__state") or contains(@class, "crm-data__error")]' ) === [] && portalFind( $data, '//*[@aria-invalid]' ) === [] );
$saved = portalPage( $appData, '/portal/meine-daten?state=saved' )[1];
$expired = portalPage( $appData, '/portal/meine-daten?state=expired' )[1];
$invalid = portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip' )[1];
check( 'the state of the query is said above the form: saved as a status in the success tone, expired and invalid as an alert in the error tone - the invalid one names the field - and the field says it beside itself, marked invalid and described by its sentence', portalFind( $saved, '//p[@role="status"][contains(@class, "crm-data__state--saved")][contains(@class, "crm-note--success")]' ) !== [] && portalFind( $expired, '//p[@role="alert"][contains(@class, "crm-data__state--expired")][contains(@class, "crm-note--error")]' ) !== []
	&& portalText( $saved, '//p[contains(@class, "crm-data__state")]' ) !== portalText( $expired, '//p[contains(@class, "crm-data__state")]' ) && count( portalFind( $invalid, '//p[@role="alert"][contains(@class, "crm-data__state--invalid")]' ) ) === 1
	&& str_contains( portalText( $invalid, '//p[@role="alert"]' ), portalText( $invalid, '//label[@for="crm-data-zip"]' ) ) === true && portalFind( $invalid, '//input[@name="zip"]/@aria-invalid' )[0]->nodeValue === 'true' && portalFind( $invalid, '//input[@name="zip"]/@aria-describedby' )[0]->nodeValue === 'crm-data-zip-error'
	&& portalText( $invalid, '//span[@id="crm-data-zip-error"]' ) !== '' && count( portalFind( $invalid, '//*[@aria-invalid]' ) ) === 1 && count( portalFind( $invalid, '//span[contains(@class, "crm-data__error")]' ) ) === 1 );
check( '...and the states are notes in the manner of the toast of the surface: .crm-note on the paragraph of saved, expired and invalid', count( portalFind( $saved . $expired . $invalid, '//p[contains(@class, "crm-data__state")][contains(concat(" ", normalize-space(@class), " "), " crm-note ")]' ) ) === 3 && portalFind( $saved . $expired . $invalid, '//p[contains(@class, "crm-data__state")][not(contains(concat(" ", normalize-space(@class), " "), " crm-note "))]' ) === [] );
check( '...a state that is none, an invalid state without a field and a field that is none say nothing; the typed values are not kept', array_filter( [ 'state=bogus', 'state=invalid', 'state=invalid&field=email', 'state=invalid&field=..', 'field=zip', 'state[]=saved' ], static fn( string $query ): bool => portalFind( portalPage( $appData, '/portal/meine-daten?'. $query )[1], '//*[contains(@class, "crm-data__state") or contains(@class, "crm-data__error")]' ) !== [] ) === []
	&& portalFind( portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip&zip=ZZZ' )[1], '//input[@name="zip"]/@value' )[0]->nodeValue === $client['zip'] );

$fields = static fn( array $over = [] ): array => $over + [ 'company' => (string) $client['company'], 'name' => (string) $client['name'], 'street' => (string) $client['street'], 'zip' => (string) $client['zip'], 'city' => (string) $client['city'], 'country' => (string) $client['country'], 'phone' => (string) $client['phone'], 'vat_id' => (string) $client['vat_id'] ];
$location = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
$reset();
$beforeMails = count( $mails );

$asVisitor();
$visitorPost = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Hacked' ] ) );
check( 'a visitor posting the form is answered 404, and nothing is changed', $visitorPost['statusCode'] === 404 && ( $visitorPost['body'] ?? '' ) === 'Not Found' && ( $visitorPost['file'] ?? null ) === null && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Kiel' );
check( '...also with a token that is wrong - a stranger is a stranger', portalServe( $appData, '/.crm/daten', 'POST', $fields() + [ '_csrf' => 'wrong' ] )['statusCode'] === 404 && portalServe( $appData, '/.crm/daten' )['statusCode'] === 404 );
portalSignIn( $appData, $stray );
check( 'a member of no client is answered 404 too', portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Hacked' ] ) )['statusCode'] === 404 && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Kiel' );
portalSignIn( $appData, $accounts['anna'] );
$beforeMails = count( $mails );

$expiredPost = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Hacked', '_csrf' => 'wrong' ] ) );
$emptyToken = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Hacked', '_csrf' => '' ] ) );
check( 'a token that is not the session\'s is the redirect with ?state=expired, and nothing is changed', $expiredPost['statusCode'] === 303 && $location( $expiredPost ) === '/portal/meine-daten?state=expired' && $emptyToken['statusCode'] === 303 && $location( $emptyToken ) === '/portal/meine-daten?state=expired'
	&& \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Kiel' && count( $mails ) === $beforeMails );

$refusals = [
	'a zip that is no zip'              => [ [ 'zip' => 'a!b' ], 'zip' ],
	'a city that is too long'           => [ [ 'city' => str_repeat( 'x', 81 ) ], 'city' ],
	'a country of three letters'        => [ [ 'country' => 'DEU' ], 'country' ],
	'a country that is no letters'      => [ [ 'country' => '1!' ], 'country' ],
	'a phone number that is none'       => [ [ 'phone' => 'abc' ], 'phone' ],
	'a VAT id that is none'             => [ [ 'vat_id' => 'x' ], 'vat_id' ],
	'a street that is a list'           => [ [ 'street' => [ 'a', 'b' ] ], 'street' ],
	'neither a company nor a name'      => [ [ 'company' => ' ', 'name' => '' ], 'name' ],
];

foreach( $refusals as $why => [ $over, $field ] ) {
	$answer = portalServe( $appData, '/.crm/daten', 'POST', $fields( $over ) );
	check( 'refused - '. $why. ': the redirect names the field ('. $field. '), nothing is saved, no mail', $answer['statusCode'] === 303 && $location( $answer ) === '/portal/meine-daten?state=invalid&field='. $field
		&& \Crm\Clients::get( $appData, $a['id'] ) === $client && count( $mails ) === $beforeMails );
}

$sameAgain = portalServe( $appData, '/.crm/daten', 'POST', $fields() );
check( 'a post that changes nothing is saved all the same - ?state=saved - and nobody is mailed', $sameAgain['statusCode'] === 303 && $location( $sameAgain ) === '/portal/meine-daten?state=saved' && count( $mails ) === $beforeMails
	&& array_diff_key( (array) \Crm\Clients::get( $appData, $a['id'] ), [ 'updated' => 1 ] ) === array_diff_key( $client, [ 'updated' => 1 ] ) );

$invoiceFrozen = Db::row( $appData, 'SELECT client_json FROM documents WHERE id = :id', [ 'id' => $invoiceA['id'] ] )['client_json'];
$changed = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'company' => 'Nord & Söhne <GmbH>', 'city' => ' Bremen ', 'country' => 'at', 'email' => 'other@example.org', 'notes' => 'injected', 'number' => 'K-9999', 'active' => '0', 'id' => '99' ] ) );
$after = (array) \Crm\Clients::get( $appData, $a['id'] );
check( 'a post that changes fields is saved: ?state=saved, the fields through the validation of the client (trimmed, the country in capitals) - and what the form does not have (e-mail, notes, number, active) is not touched', $changed['statusCode'] === 303 && $location( $changed ) === '/portal/meine-daten?state=saved'
	&& $after['company'] === 'Nord & Söhne <GmbH>' && $after['city'] === 'Bremen' && $after['country'] === 'AT' && $after['email'] === $client['email'] && $after['notes'] === $client['notes'] && $after['number'] === $client['number'] && $after['active'] === true && $after['id'] === $a['id']
	&& array_diff_key( array_diff_assoc( $after, $client ), [ 'updated' => 1 ] ) === [ 'company' => 'Nord & Söhne <GmbH>', 'city' => 'Bremen', 'country' => 'AT' ] );
$sentMails = portalMailsFrom( $beforeMails );
check( 'the owner is mailed once: to the contact address of the company, the reply to the account that changed it, the subject with the number and the company', count( $sentMails ) === 1 && $sentMails[0]['to'] === 'owner@example.org' && $sentMails[0]['replyTo'] === 'anna@example.org' && str_contains( $sentMails[0]['subject'], 'K-0001' ) === true && str_contains( $sentMails[0]['subject'], 'Nord &amp; Söhne' ) === false );
$mailRows = portalFind( $sentMails[0]['body'] ?? '', '//tbody/tr' );
check( '...the body: the number and the company, a row for each field that changed - three, in the order of the form - with what it was and what it is now, and the account; nothing for a field that did not change; every value is text', count( $mailRows ) === 3
	&& array_map( static fn( \DOMElement $row ): string => portalText( $row->ownerDocument->saveHTML( $row ), '//td[2]' ), $mailRows ) === [ 'Nord "A&B" <x> [members-name]', 'Kiel', 'DE' ] && array_map( static fn( \DOMElement $row ): string => portalText( $row->ownerDocument->saveHTML( $row ), '//td[3]' ), $mailRows ) === [ 'Nord & Söhne <GmbH>', 'Bremen', 'AT' ]
	&& str_contains( $sentMails[0]['body'], 'K-0001' ) === true && str_contains( $sentMails[0]['body'], 'anna@example.org' ) === true && str_contains( $sentMails[0]['body'], 'Nord &amp; Söhne &lt;GmbH&gt;' ) === true && str_contains( $sentMails[0]['body'], '<GmbH>' ) === false && str_contains( $sentMails[0]['body'], '[[' ) === false && str_contains( $sentMails[0]['body'], 'Hamburg' ) === false );
check( '...from its own template and its own words: nothing of the release mail is in it, no fill or shortcode is left, and where it speaks to anyone it is Du', str_contains( $sentMails[0]['body'], '[[' ) === false && str_contains( $sentMails[0]['body'], 'Dokument' ) === false && preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', strip_tags( $sentMails[0]['body'] ) ) !== 1 );
check( 'a document that is final keeps the client it was made with; the next one carries the new data', Db::row( $appData, 'SELECT client_json FROM documents WHERE id = :id', [ 'id' => $invoiceA['id'] ] )['client_json'] === $invoiceFrozen && (array) json_decode( $invoiceFrozen, true ) !== [] && ( (array) json_decode( $invoiceFrozen, true ) )['city'] !== 'Bremen'
	&& ( (array) Documents::get( $appData, (int) ( (array) Documents::add( $appData, 'information', $a['id'], [ 'title' => 'Neu', 'template_key' => 'zugang' ] ) )['id'] ) )['client']['city'] === 'Bremen' );

// the address of the owner is none: saved all the same, nobody mailed
$reset();
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'no-address' ], '*' );
$beforeMails = count( $mails );
$quiet = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Lübeck', 'company' => $after['company'], 'country' => 'AT', 'name' => $after['name'], 'street' => $after['street'], 'zip' => $after['zip'], 'phone' => $after['phone'], 'vat_id' => $after['vat_id'] ] ) );
check( 'where the company has no contact address that is one the data is saved and no mail is tried', $quiet['statusCode'] === 303 && $location( $quiet ) === '/portal/meine-daten?state=saved' && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Lübeck' && count( $mails ) === $beforeMails );
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
$delivery = false;
$ignored = ninoWarnings();
$undelivered = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'city' => 'Rostock', 'company' => $after['company'], 'country' => 'AT', 'name' => $after['name'], 'street' => $after['street'], 'zip' => $after['zip'], 'phone' => $after['phone'], 'vat_id' => $after['vat_id'] ] ) );
$delivery = true;
check( 'a mail the transport refuses does not undo the save: ?state=saved, the data is the new one, and the log has a line that names the client', $undelivered['statusCode'] === 303 && $location( $undelivered ) === '/portal/meine-daten?state=saved' && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Rostock' && str_contains( implode( ' ', ninoWarnings() ), 'K-0001' ) === true );
check( 'the page of the data shows the new values after the redirect, and says it is saved', portalFind( portalPage( $appData, '/portal/meine-daten?state=saved' )[1], '//input[@name="city"]/@value' )[0]->nodeValue === 'Rostock' );

// --- Markup and a shortcode in what a client or the owner typed: text everywhere it is drawn ---------------------------------------------

echo "\nEscaping\n";

$odd = 'Q <i>[members-name]</i> & "x"';
$oddFile = $put( $folderA, $odd. '.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 30 ) );
$pOdd = $made( $odd, $a['number'], [ 'folder' => $folderA['id'] ] );
$offerOdd = $final( 'offer', $a['id'], [ 'date' => '2026-10-09', 'project' => $pOdd, 'template_key' => 'angebot', 'lines' => [ $line( 'Nur ein Posten' ) ] ] );
Documents::release( $appData, $offerOdd['id'], false );
Db::exec( $appData, 'UPDATE documents SET title = :t WHERE id = :id', [ 't' => $odd, 'id' => $offerOdd['id'] ] );
$oddEmail = '"e<b>[members-name]"@example.org';
$oddAccount = \Nino\Modules\Members::add( $appData, $oddEmail, 'Eva Extra', $a['group'] );

if( is_array( $oddAccount ) === false ) {
	// the address a form would refuse: written as the account has it
	$members = (array) \Nino\Filesystem::getFileContent( $appData, \Nino\Modules\Members::ACCOUNTS, [] );
	$oddAccount = [ 'id' => 'abcabcabca12', 'email' => $oddEmail, 'name' => 'Eva Extra', 'group' => $a['group'], 'active' => true, 'created' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'login' => null ];
	$members['accounts']['abcabcabca12'] = array_diff_key( $oddAccount, [ 'id' => 1 ] );
	\Nino\Filesystem::putFileContent( $appData, \Nino\Modules\Members::ACCOUNTS, $members );
}

\Crm\Clients::link( $appData, $a['id'], $oddAccount['id'] );
portalSignIn( $appData, $accounts['anna'] );
$isText = static fn( string $html, string $query ): bool => portalText( $html, $query ) === $odd;
$oddList = portalPage( $appData, '/portal/projekte' )[1];
$oddPage = portalPage( $appData, '/portal/projekte?projekt='. $pOdd )[1];
$oddBelege = portalPage( $appData, '/portal/belege' )[1];
check( 'the title of a project is text in its row of the list and in the head of its page', $isText( $oddList, '//li[@data-project="'. $pOdd. '"]//span[@class="crm-projects__title"]' ) === true && $isText( $oddPage, '//div[@class="crm-head"]//span[@class="crm-head__name"]' ) === true
	&& portalFind( $oddList, '//li[@data-project="'. $pOdd. '"]//i' ) === [] && portalFind( $oddPage, '//div[@class="crm-head"]//i | //nav[contains(@class, "crm-crumbs")]//i' ) === [] && $isText( $oddPage, '//nav[contains(@class, "crm-crumbs")]/span[@aria-current="page"]' ) === true );
check( '...so is the title of an offer, the project in the cell of the documents and the name of a file in its row', $isText( $oddPage, '//details[@data-document="'. $offerOdd['id']. '"]//span[@class="crm-offer__title"]' ) === true && $isText( $oddBelege, '//li[@data-document="'. $offerOdd['id']. '"]//a[contains(@class, "crm-documents__project")]' ) === true
	&& portalText( $oddPage, '//li[@data-files-id="'. $oddFile['id']. '"]//a[contains(@class, "crm-files__name")]' ) === $odd. '.mp3' && portalFind( $oddPage, '//details[@data-document="'. $offerOdd['id']. '"]//i | //li[@data-files-id="'. $oddFile['id']. '"]//i' ) === [] && portalFind( $oddBelege, '//a[contains(@class, "crm-documents__project")]//i' ) === [] );
portalSignIn( $appData, $oddAccount );
$oddData = portalPage( $appData, '/portal/meine-daten' )[1];
check( '...and the e-mail of the account in Meine Daten is the value of its field, whatever it holds', portalFind( $oddData, '//input[@readonly]/@value' )[0]->nodeValue === $oddEmail && str_contains( $oddData, '&#91;members-name]' ) === true && str_contains( $oddData, '<b>' ) === false );

// the owner's mail is one call of the kernel's cap: at the cap the warning stands in the log
$reset();
$ignored = ninoWarnings();
$limit = [];

for( $n = 0; $n < 6; $n++ )
	$limit[] = Documents\Mail::dataChanged( $appData, (array) \Crm\Clients::get( $appData, $a['id'] ), [ 'city' => [ 'A', 'B' ] ], $accounts['anna'] );

$warned = implode( ' ', ninoWarnings() );
check( 'the mail about changed data is one call of the cap: five go, the sixth is "limit" and writes the warning that names the client and no address', array_slice( $limit, 0, 5 ) === [ 1, 1, 1, 1, 1 ] && $limit[5] === 'limit' && str_contains( $warned, 'K-0001' ) === true && str_contains( $warned, 'cap of mails' ) === true && str_contains( $warned, '@' ) === false );
$reset();
// --- The main menu ---------------------------------------------------------------------------------------------------------------

echo "\nThe main menu\n";

$menu = static function() use ( &$appData ): array {
	return \Nino\Modules\Navigation::routeLines( $appData, 'main' );
};
// a page of the site that is open to everybody and in the menu, to see that the callback takes out the protected pages and nothing else
$appData['/nino/http/routes']['GET://kontakt'] = [ 'uri' => '/kontakt', 'body' => 'x', 'navs' => [ 'main' => 9 ] ];
\Nino\Html::addFills( $appData, [ '[[/_nino/webpage/kontakt/name]]' => 'Kontakt' ], '*' );
$portalLines = [ '/portal:Portal', '/portal/meine-daten:Meine Daten', '/portal/projekte:Projekte', '/portal/upload:Upload', '/portal/belege:Belege', '/portal/termine:Termine' ];
$unitRoutes = (array) ( ( (array) require $root. '/app/Crm/Crm/install/manifest.php' )['routes'] ?? [] );
portalSignIn( $appData, $accounts['anna'] );
check( 'the pages of the portal are no entries of the main menu: no route of the unit has a menu, and the menu of a member lists the pages of the site alone', array_filter( $unitRoutes, static fn( array $route ): bool => isset( $route['navs'] ) === true ) === [] && array_intersect( $menu(), $portalLines ) === [] && in_array( '/kontakt:Kontakt', $menu(), true ) === true );
// a project that keeps one of them in its menu: the page Belege, after the others
$appData['/nino/http/routes']['GET://portal/belege']['navs'] = [ 'main' => 10 ];
$asVisitor();
$visitorMenu = $menu();
$visitorMenuHtml = \Nino\Modules\Navigation::doShortcode( $appData, [ 'nav' => 'main' ] );
portalSignIn( $appData, $accounts['anna'] );
$memberMenu = $menu();
$memberMenuHtml = \Nino\Modules\Navigation::doShortcode( $appData, [ 'nav' => 'main' ] );
check( 'a page the project keeps in its menu is there for a member, with the name of its page, after the pages of the site - and the page Datenschutz is in no menu', array_values( array_intersect( $memberMenu, $portalLines ) ) === [ '/portal/belege:Belege' ] && in_array( '/kontakt:Kontakt', $memberMenu, true ) === true
	&& array_search( '/portal/belege:Belege', $memberMenu, true ) > array_search( '/kontakt:Kontakt', $memberMenu, true ) && array_filter( $memberMenu, static fn( string $line ): bool => str_contains( $line, 'datenschutz' ) === true ) === [] );
check( '...drawn by the kernel\'s navigation, each page is a link with its name', array_filter( [ 'Belege' => '/portal/belege', 'Kontakt' => '/kontakt' ], static fn( string $href, string $name ): bool => count( portalFind( $memberMenuHtml, '//a[@href="'. $href. '"][normalize-space(.)="'. $name. '"]' ) ) !== 1, ARRAY_FILTER_USE_BOTH ) === [] );
check( 'the main menu of a visitor has no line of the portal - the callback of the project takes out every line whose path Members protects - and keeps the others, in their order', array_intersect( $visitorMenu, $portalLines ) === [] && $visitorMenu === array_values( array_diff( $memberMenu, $portalLines ) ) && in_array( '/kontakt:Kontakt', $visitorMenu, true ) === true
	&& portalFind( $visitorMenuHtml, '//a[starts-with(@href, "/portal")]' ) === [] && count( portalFind( $visitorMenuHtml, '//a[@href="/kontakt"]' ) ) === 1 );
check( '...the callback is registered once, for the callback of the kernel that fires it', count( \Nino\Callbacks::registered( $appData, '/nino/navigation/lines' ) ) === 1 );
unset( $appData['/nino/http/routes']['GET://portal/belege']['navs'] );
unset( $appData['/nino/http/routes']['GET://kontakt'] );
portalSignIn( $appData, $accounts['anna'] );

// --- The Datenschutz --------------------------------------------------------------------------------------------------------------

echo "\n[crm-privacy] and POST /.crm/datenschutz\n";

$privacyText = "Wir speichern Deine Daten <b>nur</b> für das Projekt.\r\nKontakt: [members-name] & Co\n\nStand 2026";
$navHrefs = static function( string $uri ) use ( &$appData ): array {
	return array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( portalNavAt( $appData, $uri ), '//nav//ul[@class="crm-nav__list"]/li/a[@class="crm-nav__link"]' ) );
};
$recordsOf = static function( int $clientId, ?string $kind = null ) use ( &$appData ): array {
	return \Crm\Records::of( $appData, $clientId, $kind );
};

$asVisitor();
check( 'while the owner keeps no text the portal asks for nothing: the client has not "agreed", the nav has six links, and the page says there is nothing to agree to', \Crm\Settings::get( $appData, 'privacy_text' ) === '' && \Crm\Records::privacy( $appData, $a['id'] ) === [ 'asked' => false, 'agreed' => false, 'at' => '', 'member_name' => '' ] );
portalSignIn( $appData, $accounts['anna'] );
check( '...Meine Daten has no line about it', portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//section[contains(@class, "crm-data__privacy")]' ) === [] );
check( '...the nav has the six areas and no link to the Datenschutz; the page shows the sentence and no form', count( $navHrefs( '/portal' ) ) === 6 && in_array( '/portal/datenschutz', $navHrefs( '/portal' ), true ) === false
	&& portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//div[contains(@class, "crm-privacy--empty")]/div[contains(@class, "crm-empty")]/p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//form[contains(@class, "crm-privacy__form")]' ) === [] );
$emptyPost = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
check( '...a post with no text to agree to is ?state=invalid and keeps nothing', $emptyPost['statusCode'] === 303 && ( $emptyPost['header']['Location'] ?? '' ) === '/portal/datenschutz?state=invalid' && $recordsOf( $a['id'] ) === [] );

check( 'the text is a setting of the CRM: it is kept with its line breaks as \\n, and the day it changed is set by the module', \Crm\Settings::set( $appData, 'privacy_text', $privacyText ) === true && \Crm\Settings::get( $appData, 'privacy_text' ) === str_replace( "\r\n", "\n", $privacyText )
	&& \Crm\Settings::get( $appData, 'privacy_changed' ) === $today && \Crm\Settings::set( $appData, 'privacy_changed', '2020-01-01' ) === 'value:privacy_changed' && \Crm\Settings::get( $appData, 'privacy_changed' ) === $today );
$text = \Crm\Settings::get( $appData, 'privacy_text' );

$asVisitor();
check( 'a visitor gets nothing of the component', trim( \Nino\Html::renderHtml( $appData, '[crm-privacy]' ) ) === '' );
portalSignIn( $appData, $stray );
$strayPage = portalPage( $appData, '/portal/datenschutz' )[1];
check( 'a member of no client reads the sentence: no text, no form, and no link in the nav', portalFind( $strayPage, '//div[contains(@class, "crm-privacy--empty")]/div[contains(@class, "crm-privacy__empty")]/p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( $strayPage, '//form' ) === [] && count( $navHrefs( '/portal' ) ) === 6 );
portalSignIn( $appData, $accounts['anna'] );
[ $status, $page ] = portalPage( $appData, '/portal/datenschutz' );
check( 'a client that has not agreed reads the text with its line breaks as <br>, every character escaped (markup and a shortcode in it are text), and the sentence that it has not agreed', $status === 200 && str_contains( $page, 'Wir speichern Deine Daten &lt;b&gt;nur&lt;/b&gt; für das Projekt.<br>Kontakt: &#91;members-name] &amp; Co<br><br>Stand 2026' ) === true
	&& portalFind( $page, '//p[contains(@class, "crm-privacy__standing--open")]' ) !== [] && portalFind( $page, '//p[contains(@class, "crm-privacy__standing--agreed")]' ) === [] && portalLeft( $page ) === false );
$form = portalFind( $page, '//form[contains(@class, "crm-privacy__form")]' );
check( '...and the form: it posts to /.crm/datenschutz, with the csrf token, a box that must be ticked - required, value 1 - and the button', count( $form ) === 1 && portalFind( $page, '//form[contains(@class, "crm-privacy__form")]/@action' )[0]->nodeValue === '/.crm/datenschutz' && portalFind( $page, '//form[contains(@class, "crm-privacy__form")]/@method' )[0]->nodeValue === 'post'
	&& portalFind( $page, '//form//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && portalFind( $page, '//form//input[@type="checkbox"][@name="agree"][@required][@value="1"]' ) !== [] && portalFind( $page, '//form//button[@type="submit"]' ) !== []
	&& portalText( $page, '//form//label' ) !== '' && portalFind( $page, '//form//label/@for' )[0]->nodeValue === portalFind( $page, '//form//input[@type="checkbox"]/@id' )[0]->nodeValue );
check( '...the form is a plain form with the button of the portal, and the text above it is a .crm-card', count( portalFind( $page, '//form[contains(concat(" ", normalize-space(@class), " "), " crm-privacy__form ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-form "))]//button[@type="submit"][contains(concat(" ", normalize-space(@class), " "), " crm-btn ")][contains(concat(" ", normalize-space(@class), " "), " crm-btn--primary ")]' ) ) === 1 && count( portalFind( $page, '//div[contains(concat(" ", normalize-space(@class), " "), " crm-privacy__text ")][contains(concat(" ", normalize-space(@class), " "), " crm-card ")]' ) ) === 1 );
$seventh = $navHrefs( '/portal' );
check( 'the nav has a seventh link, to the page, as long as the client has not agreed: after the six, marked on the page itself and on no other', count( $seventh ) === 7 && $seventh[6] === '/portal/datenschutz' && array_slice( $seventh, 0, 6 ) === [ '/portal', '/portal/projekte', '/portal/belege', '/portal/termine', '/portal/upload', '/portal/meine-daten' ]
	&& array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( portalNavAt( $appData, '/portal/datenschutz' ), '//nav//a[@aria-current="page"]' ) ) === [ '/portal/datenschutz' ] && array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( portalNavAt( $appData, '/portal' ), '//nav//a[@aria-current]' ) ) === [ '/portal' ] );
check( '...the link has the name of its page, in the words of the pages, and the dot that says it waits for the client: a mark in the markup and the word for a screen reader', portalText( portalNavAt( $appData, '/portal' ), '//nav//a[@href="/portal/datenschutz"]/span[@class="crm-nav__text"]' ) === $unitDe['[[/_nino/webpage/portal/datenschutz/name]]']
	&& count( portalFind( portalNavAt( $appData, '/portal' ), '//nav//a[@href="/portal/datenschutz"]/span[@class="crm-nav__dot"][@aria-hidden="true"]' ) ) === 1 && portalText( portalNavAt( $appData, '/portal' ), '//nav//a[@href="/portal/datenschutz"]/span[contains(@class, "nino-sr-only")]' ) === $wordsDe['[[/project/crm-portal/nav/pending]]']
	&& portalFind( portalNavAt( $appData, '/portal' ), '//nav//a[not(@href="/portal/datenschutz")]//span[contains(@class, "crm-nav__dot")]' ) === [] && $wordsEn['[[/project/crm-portal/nav/pending]]'] !== '' );
$data = portalPage( $appData, '/portal/meine-daten' )[1];
check( 'Meine Daten has a line with the state and the link to the page, while the owner asks for a text', portalFind( $data, '//section[contains(@class, "crm-data__privacy")][@data-agreed="0"]' ) !== [] && portalFind( $data, '//section[contains(@class, "crm-data__privacy")]//a[contains(@class, "crm-data__link")]/@href' )[0]->nodeValue === '/portal/datenschutz'
	&& str_contains( portalFind( $data, '//section[contains(@class, "crm-data__privacy")]//span[contains(@class, "crm-pill")]' )[0]->getAttribute( 'class' ), 'crm-pill--warning' ) === true );

// the post
$asVisitor();
check( 'a visitor posting the form is answered 404, and nothing is kept', portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] )['statusCode'] === 404 && $recordsOf( $a['id'] ) === [] );
portalSignIn( $appData, $stray );
check( 'a member of no client is answered 404 too', portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] )['statusCode'] === 404 );
portalSignIn( $appData, $accounts['anna'] );
$location = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
$expiredAgree = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', '_csrf' => 'wrong' ] );
$noBox = portalServe( $appData, '/.crm/datenschutz', 'POST' );
$otherBox = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => 'on' ] );
check( 'a token that is not the session\'s is the redirect with ?state=expired; a box that is not ticked - not sent, or sent as anything but 1 - is ?state=unchecked; nothing is kept', $expiredAgree['statusCode'] === 303 && $location( $expiredAgree ) === '/portal/datenschutz?state=expired'
	&& $location( $noBox ) === '/portal/datenschutz?state=unchecked' && $location( $otherBox ) === '/portal/datenschutz?state=unchecked' && $recordsOf( $a['id'] ) === [] );
check( '...the page says each state above the text: agreed as a status, the others as an alert', array_filter( [ 'expired' => 'alert', 'unchecked' => 'alert', 'changed' => 'alert', 'agreed' => 'status' ], static fn( string $role, string $state ): bool => portalFind( portalPage( $appData, '/portal/datenschutz?state='. $state )[1], '//p[@role="'. $role. '"][contains(@class, "crm-privacy__state--'. $state. '")]' ) === [], ARRAY_FILTER_USE_BOTH ) === []
	&& portalFind( portalPage( $appData, '/portal/datenschutz?state=bogus' )[1], '//*[contains(@class, "crm-privacy__state")]' ) === [] && portalFind( portalPage( $appData, '/portal/datenschutz?state=invalid' )[1], '//*[contains(@class, "crm-privacy__state")]' ) === [] );

$agent = "Mozilla/5.0 (X11;\n Linux) ". str_repeat( 'x', 400 );
$mailsBeforeAgree = count( $mails );
$agreed = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ], [ 'HTTP_USER_AGENT' => $agent ] );
$kept = $recordsOf( $a['id'] );
check( 'the box ticked: ?state=agreed and one record of kind privacy', $agreed['statusCode'] === 303 && $location( $agreed ) === '/portal/datenschutz?state=agreed' && count( $kept ) === 1 && $kept[0]['kind'] === 'privacy' && $kept[0]['document_id'] === null && $kept[0]['project'] === '' );
check( '...it holds the text as it was shown and the hash of that text, the account with its address and name, the client, the time and the address of the request', $kept[0]['shown'] === $text && $kept[0]['sha256'] === hash( 'sha256', $text ) && $kept[0]['client_id'] === $a['id'] && $kept[0]['member_id'] === $accounts['anna']['id'] && $kept[0]['member_email'] === 'anna@example.org' && $kept[0]['member_name'] === 'Anna Nord'
	&& preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $kept[0]['at'] ) === 1 && substr( $kept[0]['at'], 0, 10 ) === gmdate( 'Y-m-d' ) && $kept[0]['ip'] === '127.0.0.1' );
check( '...the browser is on one line and cut to 300 characters', mb_strlen( $kept[0]['agent'] ) === 300 && str_starts_with( $kept[0]['agent'], 'Mozilla/5.0 (X11; Linux) xxx' ) === true && preg_match( '/\s{2}|[\r\n]/', $kept[0]['agent'] ) !== 1 );
check( '...no mail goes out for it', count( $mails ) === $mailsBeforeAgree );
$againPost = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
check( 'a second post of the same text makes no second record: it is agreed to once', $location( $againPost ) === '/portal/datenschutz?state=agreed' && count( $recordsOf( $a['id'] ) ) === 1 );
$agreedPage = portalPage( $appData, '/portal/datenschutz' )[1];
check( 'the page now says when and by whom, with the day as a time element, and has no form; the nav is back to six links', portalFind( $agreedPage, '//form[contains(@class, "crm-privacy__form")]' ) === [] && portalFind( $agreedPage, '//p[contains(@class, "crm-privacy__standing--agreed")]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' )
	&& portalText( $agreedPage, '//p[contains(@class, "crm-privacy__standing--agreed")]//time' ) === \Crm\Documents\Render::day( gmdate( 'Y-m-d' ) ) && portalText( $agreedPage, '//span[@class="crm-privacy__who"]' ) === 'Anna Nord' && count( $navHrefs( '/portal' ) ) === 6 );
$privacyOfA = (array) \Crm\Clients::get( $appData, $a['id'] )['privacy'];
check( 'the client has agreed, not the account: Carl of the same client sees it as agreed; the standing is on the client as Clients::get() and list() have it; another client has not agreed', $privacyOfA === [ 'asked' => true, 'agreed' => true, 'at' => $kept[0]['at'], 'member_name' => 'Anna Nord' ]
	&& array_column( \Crm\Clients::list( $appData ), 'privacy', 'id' )[$a['id']] === $privacyOfA && array_column( \Crm\Clients::list( $appData ), 'privacy', 'id' )[$b['id']]['agreed'] === false && \Crm\Records::privacy( $appData, $b['id'] )['asked'] === true );
portalSignIn( $appData, $accounts['carl'] );
check( '...Carl sees the same page: agreed, by Anna, and the data page says it with the link', count( $navHrefs( '/portal' ) ) === 6 && portalText( portalPage( $appData, '/portal/datenschutz' )[1], '//span[@class="crm-privacy__who"]' ) === 'Anna Nord' && portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//section[contains(@class, "crm-data__privacy")][@data-agreed="1"]' ) !== [] );
portalSignIn( $appData, $accounts['ben'] );
check( 'Ben\'s client has not agreed: it is asked, whatever Studio Nord did', count( $navHrefs( '/portal' ) ) === 7 && portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//form[contains(@class, "crm-privacy__form")]' ) !== [] );
check( '...and no sentence says that the text changed: that is said only to a client that agreed to an earlier text', portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//p[contains(@class, "crm-privacy__standing--open")]//time' ) === [] && \Crm\Settings::get( $appData, 'privacy_changed' ) !== '' );
$staleText = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'shown' => hash( 'sha256', 'Eine frühere Fassung' ) ] );
$staleCheck = $recordsOf( $b['id'] ) === [] && \Crm\Records::privacy( $appData, $b['id'] )['agreed'] === false;
$changedPage = portalPage( $appData, '/portal/datenschutz?state=changed' )[1];
check( 'what the customer read is what is kept: a post that carries the hash of another text - the owner saved a new one while the form was open - keeps nothing and is ?state=changed; the page says so, above the text as it is now, with the form for it', $location( $staleText ) === '/portal/datenschutz?state=changed' && $staleCheck === true
	&& portalFind( $changedPage, '//p[@role="alert"][contains(@class, "crm-privacy__state--changed")]' ) !== [] && portalFind( $changedPage, '//form//input[@name="shown"]/@value' )[0]->nodeValue === hash( 'sha256', \Crm\Settings::get( $appData, 'privacy_text' ) ) && str_contains( $changedPage, 'Stand 2026' ) === true );
$noField = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'shown' => null ] );
check( '...a post that does not carry the field at all is not a match either: ?state=changed, nothing is kept', $location( $noField ) === '/portal/datenschutz?state=changed' && $recordsOf( $b['id'] ) === [] && \Crm\Records::privacy( $appData, $b['id'] )['agreed'] === false );
check( '...a post with an empty hash is the same: nothing is kept', $location( portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'shown' => '' ] ) ) === '/portal/datenschutz?state=changed' && $recordsOf( $b['id'] ) === [] );

// a text that is changed is asked again
$beforeChange = $recordsOf( $a['id'] );
\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
\Crm\Settings::set( $appData, 'privacy_text', $text. "\nNeu: Wir melden uns vor jeder Änderung." );
Db::exec( $appData, 'UPDATE settings SET value = \'2026-11-01\' WHERE key = \'privacy_changed\'' );
portalSignIn( $appData, $accounts['anna'] );
$changedPage = portalPage( $appData, '/portal/datenschutz' )[1];
check( 'a text that is changed asks again: the client has not agreed to it, the nav has its seventh link, the page has the form and says when the text changed - the old records stay', \Crm\Records::privacy( $appData, $a['id'] )['agreed'] === false && count( $navHrefs( '/portal' ) ) === 7 && portalFind( $changedPage, '//form[contains(@class, "crm-privacy__form")]' ) !== []
	&& portalFind( $changedPage, '//p[contains(@class, "crm-privacy__standing--open")]//time/@datetime' )[0]->nodeValue === '2026-11-01' && $recordsOf( $a['id'] ) === $beforeChange && \Crm\Records::latestPrivacy( $appData, $a['id'] )['sha256'] === hash( 'sha256', $text ) );
portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
$twoKept = $recordsOf( $a['id'], 'privacy' );
check( '...agreeing to the new text is a record of its own, with the hash of the new text; the first record is as it was', count( $twoKept ) === 2 && $twoKept[0]['sha256'] === hash( 'sha256', \Crm\Settings::get( $appData, 'privacy_text' ) ) && $twoKept[1] === $beforeChange[0] && \Crm\Records::privacy( $appData, $a['id'] )['agreed'] === true );
\Crm\Settings::set( $appData, 'privacy_text', \Crm\Settings::get( $appData, 'privacy_text' ) );
check( 'a save that leaves the text as it is changes no day', \Crm\Settings::get( $appData, 'privacy_changed' ) === '2026-11-01' && \Crm\Records::privacy( $appData, $a['id'] )['agreed'] === true );

// a client of its own: the name of the account that agreed is text on the page, and the address of the request is the peer's, whatever a header says
$e = \Crm\Clients::add( $appData, [ 'company' => 'Eins E GmbH', 'name' => 'Edda E' ] );
$edda = \Nino\Modules\Members::add( $appData, 'edda@example.org', '<i>Edda</i> [members-name]', $e['group'] );
\Crm\Clients::link( $appData, $e['id'], $edda['id'] );
portalSignIn( $appData, $edda );
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';
$_SERVER['HTTP_CLIENT_IP'] = '198.51.100.8';
$forged = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ], [ 'HTTP_X_FORWARDED_FOR' => '198.51.100.7', 'HTTP_CLIENT_IP' => '198.51.100.8' ] );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CLIENT_IP'] );
$eddaPage = portalPage( $appData, '/portal/datenschutz' )[1];
check( 'the address kept with a record is the peer\'s: a forged X-Forwarded-For or Client-IP header is not taken for it', $location( $forged ) === '/portal/datenschutz?state=agreed' && $recordsOf( $e['id'] )[0]['ip'] === '127.0.0.1' );
check( 'the name of the account that agreed is text where the page says who: markup and a shortcode in it are shown as typed, escaped', portalText( $eddaPage, '//span[@class="crm-privacy__who"]' ) === '<i>Edda</i> [members-name]' && str_contains( $eddaPage, '&lt;i&gt;Edda&lt;/i&gt; &#91;members-name]' ) === true && portalFind( $eddaPage, '//span[@class="crm-privacy__who"]/i' ) === []
	&& str_contains( portalText( portalPage( $appData, '/portal/meine-daten' )[1], '//section[contains(@class, "crm-data__privacy")]' ), '<i>Edda</i> [members-name]' ) === true );
portalSignIn( $appData, $accounts['anna'] );

// --- The order -------------------------------------------------------------------------------------------------------------------

echo "\nAuftrag erteilen and POST /.crm/auftrag\n";

\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => 'Studio &lt;Eins&gt; &amp; Co [members-name]' ], '*' );

// The offers of the sections before this one ran out in the past, whatever day the suite runs on: only the offers made here can be ordered
Db::exec( $appData, 'UPDATE documents SET valid_until = \'2020-01-01\' WHERE category = \'offer\'' );
$orderMails = static function(): array {
	return array_values( array_filter( portalMailsFrom( 0 ), static fn( array $mail ): bool => preg_match( '/^(Auftrag erteilt|Dein Auftrag):/', (string) ( $mail['subject'] ?? '' ) ) === 1 ) );
};
$valid = static fn( int $days ): string => date( 'Y-m-d', strtotime( ( $days >= 0 ? '+' : '' ). $days. ' days' ) );
$mkOffer = static function( string $title, array $over = [], bool $release = true ) use ( $final, $a, $today, $line, &$appData ): array {
	$offer = $final( 'offer', $a['id'], $over + [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( $title ) ] ] );

	if( $release === true )
		Documents::release( $appData, $offer['id'], false );

	return $offer;
};
$orderOne = $mkOffer( 'Auftrag eins', [ 'project' => $p1 ] );
$orderTwo = $mkOffer( 'Auftrag zwei', [] );
$orderThree = $mkOffer( 'Auftrag drei', [ 'project' => $p1 ] );
$orderExpired = $mkOffer( 'Abgelaufen', [ 'project' => $p1, 'valid_until' => $valid( -1 ) ] );
$orderUnreleased = $mkOffer( 'Nicht freigegeben', [ 'project' => $p1 ], false );
$orderDeclined = $mkOffer( 'Abgelehnt', [ 'project' => $p1 ] );
Documents::setState( $appData, $orderDeclined['id'], 'declined' );
$orderOwnerAccepted = $mkOffer( 'Vom Inhaber angenommen', [ 'project' => $p1 ] );
Documents::setState( $appData, $orderOwnerAccepted['id'], 'accepted' );
$orderToday = $mkOffer( 'Gilt heute noch', [ 'project' => $p1, 'valid_until' => $today ] );
$orderBen = $final( 'offer', $b['id'], [ 'date' => $today, 'valid_until' => $valid( 20 ), 'template_key' => 'angebot', 'lines' => [ $line( 'Bens Angebot' ) ] ] );
Documents::release( $appData, $orderBen['id'], false );
check( 'the fixtures: offers of Studio Nord that are open and run on, one that ran out yesterday, one that is not released, one declined, one the owner accepted, one valid until today - and one of another client', array_filter( [ $orderOne, $orderTwo, $orderThree, $orderExpired, $orderUnreleased, $orderDeclined, $orderOwnerAccepted, $orderToday, $orderBen ], static fn( array $offer ): bool => isset( $offer['error'] ) === true ) === []
	&& Documents::get( $appData, $orderExpired['id'] )['expired'] === true && Documents::get( $appData, $orderToday['id'] )['expired'] === false && Documents::get( $appData, $orderDeclined['id'] )['state'] === 'declined' && Documents::get( $appData, $orderOwnerAccepted['id'] )['state'] === 'accepted' );
check( 'which offers can be ordered is one rule: an offer open or sent that has not run out - the day it is valid until counts', array_map( static fn( int $id ): bool => Documents::orderable( (array) Documents::get( $appData, $id ) ), [ $orderOne['id'], $orderExpired['id'], $orderDeclined['id'], $orderOwnerAccepted['id'], $orderToday['id'], $invoiceA['id'] ] ) === [ true, false, false, false, true, false ] );

$toList = static fn( string $state, int $id ): string => '/portal/belege?state='. $state. '&beleg='. $id. '#beleg-'. $id;
$formsOf = static fn( string $html ): array => array_map( static fn( \DOMElement $form ): string => $form->getAttribute( 'data-document' ), portalFind( $html, '//form[contains(@class, "crm-order")]' ) );
$orderable = array_map( 'strval', [ $orderOne['id'], $orderThree['id'], $orderToday['id'] ] );

// the Datenschutz comes first
\Crm\Settings::set( $appData, 'privacy_text', \Crm\Settings::get( $appData, 'privacy_text' ). ' Und noch eins.' );
$projectPage = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$notes = portalFind( $projectPage, '//section[contains(@class, "crm-project__section--offer")]//p[contains(@class, "crm-order--privacy")]' );
check( 'while the client has not agreed to the Datenschutz there is no form under the offers that can be ordered; the sentence that the Datenschutz comes first, with the link to the page, stands once, above the offers and not under each of them', count( $notes ) === 1 && array_unique( array_map( static fn( \DOMElement $note ): string => portalFind( $note->ownerDocument->saveHTML( $note ), '//a/@href' )[0]->nodeValue, $notes ) ) === [ '/portal/datenschutz' ]
	&& portalFind( $projectPage, '//form[contains(@class, "crm-order")]' ) === [] && portalFind( $projectPage, '//p[contains(@class, "crm-order--privacy")]/following-sibling::details[contains(@class, "crm-offer")]' ) !== [] && portalFind( $projectPage, '//details[contains(@class, "crm-offer")]//p[contains(@class, "crm-order--privacy")]' ) === []
	&& count( portalFind( portalPage( $appData, '/portal/belege' )[1], '//p[contains(@class, "crm-order--privacy")]' ) ) === 1 );
$privacyFirst = portalServe( $appData, '/.crm/auftrag', 'POST', [ 'document' => (string) $orderOne['id'], 'from' => 'projekt', 'agree' => '1' ] );
check( '...a post is ?state=privacy, back to the page of the project, and nothing is kept: no record, the offer is as it was', $location( $privacyFirst ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=privacy&beleg='. $orderOne['id']. '#angebot-'. $orderOne['id'] && $recordsOf( $a['id'], 'order' ) === [] && in_array( Documents::get( $appData, $orderOne['id'] )['state'], [ 'open', 'sent' ], true ) === true );
portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );

// the form
$projectPage = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$sentence = 'Ich beauftrage Studio <Eins> & Co [members-name] verbindlich mit dem Angebot '. $orderOne['number']. ' zu den genannten Konditionen.';
$formOne = portalFind( $projectPage, '//form[contains(@class, "crm-order")][@data-document="'. $orderOne['id']. '"]' );
check( 'under each offer that can be ordered stands the form, in its section: the offers that ran out, the one not released, the declined one and the one the owner accepted have none - and no sentence about the Datenschutz is left', count( $formOne ) === 1 && $formsOf( $projectPage ) === array_map( 'strval', array_values( array_intersect( [ $orderToday['id'], $orderThree['id'], $orderOne['id'] ], array_map( 'intval', $formsOf( $projectPage ) ) ) ) )
	&& array_diff( $orderable, $formsOf( $projectPage ) ) === [] && portalFind( $projectPage, '//p[contains(@class, "crm-order--privacy")]' ) === [] && portalFind( $projectPage, '//section[contains(@class, "crm-project__section--offer")]//form' ) !== []
	&& array_filter( $formsOf( $projectPage ), static fn( string $id ): bool => in_array( $id, array_map( 'strval', [ $orderExpired['id'], $orderUnreleased['id'], $orderDeclined['id'], $orderOwnerAccepted['id'] ] ), true ) ) === [] );
check( '...the form is a plain form with the button of the portal', count( portalFind( $projectPage, '//form[contains(concat(" ", normalize-space(@class), " "), " crm-order ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-form "))]//button[@type="submit"][contains(concat(" ", normalize-space(@class), " "), " crm-btn ")][contains(concat(" ", normalize-space(@class), " "), " crm-btn--primary ")]' ) ) === count( $formsOf( $projectPage ) ) && count( $formsOf( $projectPage ) ) >= 1 );
check( '...it carries the hash of the offer as it shows it - the JSON and the sentence - in a hidden field, which the post is compared with', portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//input[@name="shown"]/@value' )[0]->nodeValue === hash( 'sha256', \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $orderOne['id'] ), $sentence ) ) );
check( '...it posts to /.crm/auftrag with the token, names the offer and the page it stands on, and has the box - required - with the sentence as its label: the company and the number of the offer, every character escaped (a shortcode in the name is text)', portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]/@action' )[0]->nodeValue === '/.crm/auftrag' && portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]/@method' )[0]->nodeValue === 'post'
	&& portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//input[@name="document"]/@value' )[0]->nodeValue === (string) $orderOne['id']
	&& portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'projekt' && portalFind( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//input[@type="checkbox"][@name="agree"][@required][@value="1"]' ) !== []
	&& portalText( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//label' ) === $sentence && str_contains( $projectPage, 'Studio &lt;Eins&gt; &amp; Co &#91;members-name]' ) === true && portalText( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//button[@type="submit"]' ) !== '' );
$belegePage = portalPage( $appData, '/portal/belege' )[1];
check( 'the list of documents has the form under the rows of offers that can be ordered - from=belege - and under no other row: not under the invoices, not under an offer that ran out', in_array( (string) $orderTwo['id'], $formsOf( $belegePage ), true ) === true && portalFind( $belegePage, '//li[@data-document="'. $orderTwo['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'belege'
	&& portalFind( $belegePage, '//li[@data-category="invoice"]//form' ) === [] && portalFind( $belegePage, '//li[@data-document="'. $orderExpired['id']. '"]//form' ) === [] && portalFind( $belegePage, '//li[@data-document="'. $orderUnreleased['id']. '"]' ) === [] && array_diff( $orderable, $formsOf( $belegePage ) ) === [] );
check( '...the project page lists the offer once more in its section Belege, and there is no second form: the form stands under the offer itself', count( array_filter( $formsOf( $projectPage ), static fn( string $id ): bool => $id === (string) $orderOne['id'] ) ) === 1 && portalFind( $projectPage, '//section[contains(@class, "crm-project__section--documents")]//form' ) === [] );
check( '...a state of the query is said above the offers, and a state that is none says nothing', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&state=unchecked' )[1], '//section[contains(@class, "crm-project__section--offer")]//p[@role="alert"][contains(@class, "crm-order__state--unchecked")]' ) !== []
	&& portalFind( portalPage( $appData, '/portal/belege?state=ordered' )[1], '//p[@role="status"][contains(@class, "crm-order__state--ordered")]' ) !== [] && portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&state=changed' )[1], '//section[contains(@class, "crm-project__section--offer")]//p[@role="alert"][contains(@class, "crm-order__state--changed")]' ) !== [] && portalFind( portalPage( $appData, '/portal/belege?state=bogus' )[1], '//*[contains(@class, "crm-order__state")]' ) === [] && portalLeft( $belegePage ) === false );

// the post that is refused
$orderPost = static function( array $over = [], array $server = [] ) use ( &$appData, $orderOne ): array {

	$post = $over + [ 'document' => (string) $orderOne['id'], 'from' => 'projekt', 'agree' => '1' ];

	// The form carries the hash of the offer as it showed it: a post that does not say otherwise is the post of a form that showed the offer of now
	if( array_key_exists( 'shown', $post ) === true && $post['shown'] === null ) {
		// a post that does not carry the field at all
	}
	elseif( array_key_exists( 'shown', $post ) === false ) {
		$shownOffer = is_string( $post['document'] ) === true ? Documents::get( $appData, (int) $post['document'] ) : null;
		$post['shown'] = $shownOffer === null ? '' : hash( 'sha256', \Crm\Records::orderShown( $appData, $shownOffer, \Crm\Portal\Order::sentence( $appData, $shownOffer ) ) );
	}

	return portalServe( $appData, '/.crm/auftrag', 'POST', $post, $server );
};
$untouched = static function() use ( &$appData, $recordsOf, $a, $orderMails, $orderOne, $orderTwo ): bool {
	return $recordsOf( $a['id'], 'order' ) === [] && $orderMails() === [] && Documents::get( $appData, $orderOne['id'] )['state'] !== 'accepted' && Documents::get( $appData, $orderTwo['id'] )['state'] !== 'accepted';
};
$asVisitor();
check( 'a visitor posting the form is answered 404', $orderPost()['statusCode'] === 404 && $untouched() );
portalSignIn( $appData, $stray );
check( '...so is a member of no client', $orderPost()['statusCode'] === 404 && $untouched() );
portalSignIn( $appData, $accounts['anna'] );
$refusals = [
	'a token that is not the session\'s'        => [ [ '_csrf' => 'wrong' ], 'expired' ],
	'a box that is not ticked'                  => [ [ 'agree' => '' ], 'unchecked' ],
	'a box sent as anything but 1'              => [ [ 'agree' => 'on' ], 'unchecked' ],
	'an offer of another client'                => [ [ 'document' => (string) $orderBen['id'] ], 'invalid' ],
	'an offer that was changed since the form showed it' => [ [ 'shown' => hash( 'sha256', 'ein anderes Angebot' ) ], 'changed' ],
	'a form without the hash of what it showed' => [ [ 'shown' => '' ], 'changed' ],
	'a post that does not carry the field at all' => [ [ 'shown' => null ], 'changed' ],
	'an offer that ran out yesterday'           => [ [ 'document' => (string) $orderExpired['id'] ], 'invalid' ],
	'an offer that is not released'             => [ [ 'document' => (string) $orderUnreleased['id'] ], 'invalid' ],
	'an offer that was declined'                => [ [ 'document' => (string) $orderDeclined['id'] ], 'invalid' ],
	'an offer the owner accepted'               => [ [ 'document' => (string) $orderOwnerAccepted['id'] ], 'invalid' ],
	'a finalised invoice'                       => [ [ 'document' => (string) $invoiceP1['id'] ], 'invalid' ],
	'a document that is not there'              => [ [ 'document' => '99999' ], 'invalid' ],
	'a document that is no number'              => [ [ 'document' => '1; DROP' ], 'invalid' ],
	'a list as the document'                    => [ [ 'document' => [ '1' ] ], 'invalid' ],
	'no document'                               => [ [ 'document' => '' ], 'invalid' ],
];

foreach( $refusals as $why => [ $over, $state ] ) {
	$answer = $orderPost( $over );
	check( 'refused - '. $why. ': ?state='. $state. ', back to a page of the portal, nothing is kept, nobody is mailed', $answer['statusCode'] === 303 && preg_match( '/[?&]state='. $state. '(?:&|#|$)/D', $location( $answer ) ) === 1 && str_starts_with( $location( $answer ), '/portal/' ) === true && $untouched() );
}

$orderBenProject = $final( 'offer', $b['id'], [ 'date' => $today, 'valid_until' => $valid( 20 ), 'project' => $p3, 'template_key' => 'angebot', 'lines' => [ $line( 'Bens Angebot im Projekt' ) ] ] );
Documents::release( $appData, $orderBenProject['id'], false );
check( '...an offer of another client that names a project is no reason to name that project: the redirect is the list of documents - whatever else is wrong with the post: a token that is not the session\'s, a box that is not ticked', $location( $orderPost( [ 'document' => (string) $orderBenProject['id'] ] ) ) === '/portal/belege?state=invalid'
	&& $location( $orderPost( [ 'document' => (string) $orderBenProject['id'], '_csrf' => 'wrong' ] ) ) === '/portal/belege?state=expired' && $location( $orderPost( [ 'document' => (string) $orderBenProject['id'], 'agree' => '' ] ) ) === '/portal/belege?state=invalid' );
check( '...the page the refusal goes back to is the tab Angebot of the project of an offer of the client, with the offer named and the anchor of its card - the list of documents for a document that is not the client\'s, and an unknown place is the list too; an offer that may not be shown any more is not named', $location( $orderPost( [ '_csrf' => 'wrong' ] ) ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=expired&beleg='. $orderOne['id']. '#angebot-'. $orderOne['id'] && $location( $orderPost( [ 'document' => (string) $orderBen['id'] ] ) ) === '/portal/belege?state=invalid'
	&& $location( $orderPost( [ 'from' => 'woanders', 'agree' => '' ] ) ) === '/portal/belege?state=unchecked&beleg='. $orderOne['id']. '#beleg-'. $orderOne['id'] && $location( $orderPost( [ 'document' => (string) $orderTwo['id'], 'agree' => '' ] ) ) === '/portal/belege?state=unchecked&beleg='. $orderTwo['id']. '#beleg-'. $orderTwo['id']
	&& $location( $orderPost( [ 'document' => (string) $orderExpired['id'] ] ) ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' );

// the order
\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
$ordered = $orderPost( [], [ 'HTTP_USER_AGENT' => 'Browser/1.0 (Test)' ] );
$orderRecords = $recordsOf( $a['id'], 'order' );
$mailsOut = $orderMails();
$orderDoc = (array) Documents::get( $appData, $orderOne['id'] );
$shown = (array) json_decode( $orderRecords[0]['shown'] ?? '', true );
check( 'the order: ?state=ordered on the page of the project, the offer is accepted and one record of kind order is kept', $ordered['statusCode'] === 303 && $location( $ordered ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=ordered&beleg='. $orderOne['id']. '#angebot-'. $orderOne['id'] && $orderDoc['state'] === 'accepted' && count( $orderRecords ) === 1 && $orderRecords[0]['kind'] === 'order' );
check( '...the record names the offer and its project, the account as it was (id, address, name), the client, the time, the address and the browser', $orderRecords[0]['document_id'] === $orderOne['id'] && $orderRecords[0]['project'] === $p1 && $orderRecords[0]['client_id'] === $a['id'] && $orderRecords[0]['member_id'] === $accounts['anna']['id'] && $orderRecords[0]['member_email'] === 'anna@example.org' && $orderRecords[0]['member_name'] === 'Anna Nord'
	&& substr( $orderRecords[0]['at'], 0, 10 ) === gmdate( 'Y-m-d' ) && $orderRecords[0]['ip'] === '127.0.0.1' && $orderRecords[0]['agent'] === 'Browser/1.0 (Test)' && \Crm\Records::forDocument( $appData, $orderOne['id'] ) === $orderRecords[0] );
check( '...what the customer was shown is kept with its hash: the JSON of the offer - number, title, day, validity, project, VAT mode, lines, sums and the particulars of the company - and the sentence of the box, as read', $orderRecords[0]['sha256'] === hash( 'sha256', $orderRecords[0]['shown'] ) && $shown['number'] === $orderOne['number'] && $shown['date'] === $today && $shown['valid_until'] === $valid( 20 ) && $shown['project'] === $p1 && $shown['vat_mode'] === 'small_business'
	&& $shown['lines'][0]['title'] === 'Auftrag eins' && $shown['lines'][0]['qty'] === 2000 && $shown['lines'][0]['price_net'] === 5000 && $shown['sums']['gross'] === 10000 && $shown['company']['name'] === 'Studio <Eins> & Co [members-name]' && $shown['sentence'] === $sentence && $shown['document'] === $orderOne['id'] );
check( '...the text can be made again: the same offer gives the same JSON, and the hash is the hash of what is kept', \Crm\Records::orderShown( $appData, $orderDoc, $sentence ) === $orderRecords[0]['shown'] );
$ownerMail = $mailsOut[0] ?? [];
$confirmMail = $mailsOut[1] ?? [];
$clientNow = (array) \Crm\Clients::get( $appData, $a['id'] );
check( 'two mails go out, in one call: the owner\'s first - to the contact address of the company, "Auftrag erteilt: Angebot <number> von Kunde <number and company> durch <account>", the reply to the account', count( $mailsOut ) === 2 && ( $ownerMail['to'] ?? '' ) === 'owner@example.org' && ( $ownerMail['replyTo'] ?? '' ) === 'anna@example.org'
	&& ( $ownerMail['subject'] ?? '' ) === 'Auftrag erteilt: Angebot '. $orderOne['number']. ' von Kunde K-0001 '. $clientNow['company']. ' durch Anna Nord' );
check( '...its body names the offer, the client and the sum, the account, and links to the document in the surface (#beleg-<id>); every value is text; nothing of a fill is left', str_contains( $ownerMail['body'] ?? '', 'href="https://example.org/club/crm#beleg-'. $orderOne['id']. '"' ) === true && preg_match( '/^\p{Lu}/u', portalText( $ownerMail['body'], '//p[1]' ) ) === 1 && str_contains( $ownerMail['body'], $orderOne['number'] ) === true && str_contains( $ownerMail['body'], 'K-0001' ) === true && str_contains( $ownerMail['body'], 'Nord &amp; Söhne &lt;GmbH&gt;' ) === true && str_contains( $ownerMail['body'], '<GmbH>' ) === false
	&& str_contains( $ownerMail['body'], 'anna@example.org' ) === true && str_contains( $ownerMail['body'], '[[' ) === false && str_contains( $ownerMail['body'], '100,00' ) === true );
check( '...the confirmation goes to the account that ordered, the reply to the owner: the offer, the day, the link to the page of the project and the address of the PDF - no attachment, in German with Du', ( $confirmMail['to'] ?? '' ) === 'anna@example.org' && ( $confirmMail['replyTo'] ?? '' ) === 'owner@example.org' && ( $confirmMail['subject'] ?? '' ) === 'Dein Auftrag: Angebot '. $orderOne['number']
	&& str_contains( $confirmMail['body'] ?? '', 'Hallo Anna Nord,' ) === true && str_contains( $confirmMail['body'], 'href="https://example.org/club/portal/projekte?projekt='. $p1. '"' ) === true && str_contains( $confirmMail['body'], 'href="https://example.org/club/.crm/pdf/'. $orderOne['id']. '?download=1"' ) === true
	&& str_contains( $confirmMail['body'], \Crm\Documents\Render::day( gmdate( 'Y-m-d' ) ) ) === true && str_contains( $confirmMail['body'], '[[' ) === false && array_diff( array_keys( $confirmMail ), [ 'to', 'subject', 'body', 'replyTo', 'sender', 'headers', 'sent' ] ) === [] && preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', strip_tags( $confirmMail['body'] ) ) !== 1 );

// after it
$afterPage = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=angebot&state=ordered&beleg='. $orderOne['id'] )[1];
$doneLine = portalFind( $afterPage, '//p[contains(@class, "crm-order--done")][@data-document="'. $orderOne['id']. '"]' );
$offerCard = static fn( int $id ): string => '//details['. $token( 'crm-offer' ). '][@data-document="'. $id. '"]';
check( 'the offer says it was ordered, when and by whom, instead of the form; its state is accepted; and the sentence of the order stands in the card of that offer (S5), once on the page, in a status, the card expanded', count( $doneLine ) === 1 && count( portalFind( $afterPage, $offerCard( $orderOne['id'] ). '//p[contains(@class, "crm-order--done")]' ) ) === 1 && portalFind( $afterPage, '//p[@data-document="'. $orderOne['id']. '"]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalText( $afterPage, '//p[@data-document="'. $orderOne['id']. '"]/span/span[@class="crm-order__who"]' ) === 'Anna Nord'
	&& portalFind( $afterPage, '//form[@data-document="'. $orderOne['id']. '"]' ) === [] && portalFind( $afterPage, $offerCard( $orderOne['id'] ). '/@data-state' )[0]->nodeValue === 'accepted' && portalFind( $afterPage, $offerCard( $orderOne['id'] ) )[0]->hasAttribute( 'open' ) === true
	&& count( portalFind( $afterPage, '//p[@role="status"][contains(@class, "crm-order__state--ordered")]' ) ) === 1 && count( portalFind( $afterPage, $offerCard( $orderOne['id'] ). '//p[@role="status"][contains(@class, "crm-order__state--ordered")]' ) ) === 1 );
check( '...an offer the owner accepted shows its state and no line about an order - there is no record of one; an offer that ran out says so, an offer that was declined says that too, neither with a form', portalFind( $afterPage, $offerCard( $orderOwnerAccepted['id'] ). '/@data-state' )[0]->nodeValue === 'accepted' && portalFind( $afterPage, '//*[@data-document="'. $orderOwnerAccepted['id']. '"][contains(@class, "crm-order")]' ) === []
	&& portalFind( $afterPage, $offerCard( $orderExpired['id'] ). '/@data-state' )[0]->nodeValue === 'expired' && portalFind( $afterPage, $offerCard( $orderDeclined['id'] ). '/@data-state' )[0]->nodeValue === 'declined'
	&& portalFind( $afterPage, '//*[@data-document="'. $orderExpired['id']. '" or @data-document="'. $orderDeclined['id']. '"][contains(@class, "crm-order")]' ) === [] && portalFind( $afterPage, $offerCard( $orderExpired['id'] ). '//form | '. $offerCard( $orderDeclined['id'] ). '//form' ) === [] );
$belegeAfter = portalPage( $appData, '/portal/belege' )[1];
check( '...the list of documents says the same under the row of the offer', portalFind( $belegeAfter, '//li[@data-document="'. $orderOne['id']. '"]//p[contains(@class, "crm-order--done")]' ) !== [] && portalFind( $belegeAfter, '//li[@data-document="'. $orderOne['id']. '"]//form' ) === [] && portalText( $belegeAfter, '//li[@data-document="'. $orderOne['id']. '"]//span[contains(concat(" ", normalize-space(@class), " "), " crm-documents__state ")]' ) === $wordsDe['[[/project/crm-documents/state/accepted]]'] );
portalSignIn( $appData, $accounts['carl'] );
check( '...Carl of the same client reads it as Anna\'s order; Ben cannot order Studio Nord\'s offer: it is invalid for his client', portalText( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], '//p[@data-document="'. $orderOne['id']. '"]/span/span[@class="crm-order__who"]' ) === 'Anna Nord' );
portalSignIn( $appData, $accounts['ben'] );
\Crm\Settings::set( $appData, 'privacy_text', '' );
$benPost = portalServe( $appData, '/.crm/auftrag', 'POST', [ 'document' => (string) $orderTwo['id'], 'from' => 'belege', 'agree' => '1' ] );
check( '...Ben posting the id of an offer of Studio Nord is ?state=invalid, nothing happens to it, and the list he is sent back to does not say whose offer it is', $location( $benPost ) === '/portal/belege?state=invalid' && Documents::get( $appData, $orderTwo['id'] )['state'] !== 'accepted' && count( $recordsOf( $a['id'], 'order' ) ) === 1 );
\Crm\Settings::set( $appData, 'privacy_text', $text );
portalSignIn( $appData, $accounts['anna'] );
$sentSoFar = count( $orderMails() );
$twice = $orderPost();
check( 'the same offer ordered again is ?state=invalid: no second record, no second step, no mail', $location( $twice ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' && count( $recordsOf( $a['id'], 'order' ) ) === 1 && count( $orderMails() ) === $sentSoFar );

// from the list of documents; an offer without a project; valid until today
$reset();
$fromList = $orderPost( [ 'document' => (string) $orderTwo['id'], 'from' => 'belege' ] );
check( 'ordered from the list of documents: the redirect goes back to the list; an offer without a project goes there from the page of a project too; the offer that is valid until today can be ordered', $location( $fromList ) === $toList( 'ordered', $orderTwo['id'] ) && Documents::get( $appData, $orderTwo['id'] )['state'] === 'accepted' );
$reset();
$noProject = $orderPost( [ 'document' => (string) $orderThree['id'], 'from' => 'belege' ] );
$reset();
$todayPost = $orderPost( [ 'document' => (string) $orderToday['id'] ] );
check( '...', $location( $noProject ) === $toList( 'ordered', $orderThree['id'] ) && $location( $todayPost ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=ordered&beleg='. $orderToday['id']. '#angebot-'. $orderToday['id'] && Documents::get( $appData, $orderToday['id'] )['state'] === 'accepted' && count( $recordsOf( $a['id'], 'order' ) ) === 4 );
check( 'the offers that were ordered are the orders of the client, the newest first', array_column( $recordsOf( $a['id'], 'order' ), 'document_id' ) === [ $orderToday['id'], $orderThree['id'], $orderTwo['id'], $orderOne['id'] ] && array_column( $recordsOf( $a['id'] ), 'kind' ) === [ 'order', 'order', 'order', 'order', 'privacy', 'privacy', 'privacy' ] && $recordsOf( $b['id'] ) === [] );

// one transaction: the record and the step
$orderAtomic = $mkOffer( 'Atomar' );
$stateBefore = Documents::get( $appData, $orderAtomic['id'] )['state'];
$noAccount = Documents::order( $appData, $orderAtomic['id'], $a['id'], [ 'id' => '', 'email' => '', 'name' => '' ], 'Satz', [ 'ip' => '', 'agent' => '' ] );
$otherClient = Documents::order( $appData, $orderAtomic['id'], $b['id'], [ 'id' => $accounts['ben']['id'], 'email' => 'ben@example.org', 'name' => 'Ben' ], 'Satz', [] );
$gone = Documents::order( $appData, 99999, $a['id'], [ 'id' => $accounts['anna']['id'], 'email' => '', 'name' => '' ], 'Satz', [] );
$noSentence = Documents::order( $appData, $orderExpired['id'], $a['id'], [ 'id' => $accounts['anna']['id'], 'email' => '', 'name' => '' ], 'Satz', [] );
check( 'the record and the step of the offer are one: a record that is refused leaves the offer as it was, an offer that cannot be ordered leaves no record - refused with the code of the cause', $noAccount === 'member' && $otherClient === 'unknown' && $gone === 'unknown' && $noSentence === 'state' && Documents::get( $appData, $orderAtomic['id'] )['state'] === $stateBefore
	&& \Crm\Records::forDocument( $appData, $orderAtomic['id'] ) === null && Db::row( $appData, 'SELECT COUNT(*) AS n FROM records WHERE kind = \'order\'' )['n'] === 4 );
check( '...Records::add refuses what is no record: a kind that is none, no account, nothing shown, an order without a document, a privacy record with one, a client that is not there', array_map( static fn( array $input ): string|array => \Crm\Records::add( $appData, $input + [ 'client_id' => $a['id'], 'member' => [ 'id' => 'x', 'email' => '', 'name' => '' ], 'shown' => 'x', 'kind' => 'privacy' ] ),
	[ [ 'kind' => 'bogus' ], [ 'member' => [ 'id' => '' ] ], [ 'shown' => '' ], [ 'kind' => 'order' ], [ 'document_id' => $orderOne['id'] ], [ 'client_id' => 9999 ], [ 'kind' => 'order', 'document_id' => $invoiceB['id'] ] ] ) === [ 'kind', 'member', 'shown', 'document', 'document', 'client', 'document' ] );

// a mail that does not go out does not undo the order
$orderFail = $mkOffer( 'Mail geht nicht' );
$reset();
$delivery = false;
$ignored = ninoWarnings();
$failed = $orderPost( [ 'document' => (string) $orderFail['id'], 'from' => 'belege' ] );
$delivery = true;
check( 'a mail the transport refuses does not undo the order: ?state=nomail - the page does not promise a confirmation -, the offer is accepted, the record is kept, and the log has a line that names the offer', $location( $failed ) === $toList( 'nomail', $orderFail['id'] ) && Documents::get( $appData, $orderFail['id'] )['state'] === 'accepted' && \Crm\Records::forDocument( $appData, $orderFail['id'] ) !== null && str_contains( implode( ' ', ninoWarnings() ), $orderFail['number'] ) === true );
$orderQuiet = $mkOffer( 'Ohne Adresse' );
$reset();
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
$ignored = ninoWarnings();
$sentSoFar = count( $orderMails() );
$quiet = $orderPost( [ 'document' => (string) $orderQuiet['id'], 'from' => 'belege' ] );
\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
check( 'a site with no address has no link to put into a mail: the order is made all the same, nobody is mailed, the log has the line and the page promises nothing', $location( $quiet ) === $toList( 'nomail', $orderQuiet['id'] ) && str_contains( implode( ' ', ninoWarnings() ), $orderQuiet['number'] ) === true && Documents::get( $appData, $orderQuiet['id'] )['state'] === 'accepted' && count( $orderMails() ) === $sentSoFar );

// the cap of mails of the kernel: the order is kept, the log says what was not sent and the page does not promise a confirmation
$orderCap = $mkOffer( 'Am Deckel' );
$reset();
$ignored = ninoWarnings();
$oneDoc = (array) Documents::get( $appData, $orderOne['id'] );
$oneRecord = (array) \Crm\Records::forDocument( $appData, $orderOne['id'] );
$capped = [];

for( $n = 0; $n < 6; $n++ )
	$capped[] = Documents\Mail::ordered( $appData, $oneDoc, $oneRecord, (array) \Crm\Clients::get( $appData, $a['id'] ) );

$warned = implode( ' ', ninoWarnings() );
check( 'the mails of an order are one call of the cap: five go (two mails each), the sixth is "limit" and writes the warning that names the offer and no address', array_slice( $capped, 0, 5 ) === [ 2, 2, 2, 2, 2 ] && $capped[5] === 'limit' && str_contains( $warned, $orderOne['number'] ) === true && str_contains( $warned, 'cap of mails' ) === true && str_contains( $warned, '@' ) === false );
$ignored = ninoWarnings();
$capOrder = $orderPost( [ 'document' => (string) $orderCap['id'], 'from' => 'belege' ] );
check( '...an order at the cap is kept all the same, ?state=nomail says so - and not that a confirmation was sent - and the log has the line', $location( $capOrder ) === $toList( 'nomail', $orderCap['id'] ) && Documents::get( $appData, $orderCap['id'] )['state'] === 'accepted' && \Crm\Records::forDocument( $appData, $orderCap['id'] ) !== null && str_contains( implode( ' ', ninoWarnings() ), 'cap of mails' ) === true
	&& portalFind( portalPage( $appData, '/portal/belege?state=nomail' )[1], '//p[@role="status"][contains(@class, "crm-order__state--nomail")]' ) !== [] && portalText( portalPage( $appData, '/portal/belege?state=nomail' )[1], '//p[contains(@class, "crm-order__state--nomail")]' ) !== portalText( portalPage( $appData, '/portal/belege?state=ordered' )[1], '//p[contains(@class, "crm-order__state--ordered")]' ) );
$reset();

// --- The start page ----------------------------------------------------------------------------------------------------------------

echo "\n[crm-portal-start]\n";

$cards = static fn( string $html ): array => array_map( static fn( \DOMElement $card ): string => $card->getAttribute( 'data-card' ), portalFind( $html, '//article[contains(concat(" ", normalize-space(@class), " "), " crm-start__card ")]' ) );
$rowsOf = static fn( string $html, string $card ): array => portalFind( $html, '//article[@data-card="'. $card. '"]//li[contains(@class, "crm-start__row")]' );
$partOf = static fn( \DOMElement $row, string $part ): string => portalText( $row->ownerDocument->saveHTML( $row ), '//span[@class="crm-start__'. $part. '"]' );
$hrefOf = static fn( \DOMElement $row ): string => portalFind( $row->ownerDocument->saveHTML( $row ), '//a[contains(@class, "crm-start__link")]/@href' )[0]->nodeValue;
$asVisitor();
check( 'a visitor gets nothing of the start page', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-start]' ) ) === '' );
portalSignIn( $appData, $stray );
check( '...nor does a member of no client', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-start]' ) ) === '' );
$d = \Crm\Clients::add( $appData, [ 'company' => 'Leer GmbH', 'name' => 'Dora Leer' ] );
$dora = \Nino\Modules\Members::add( $appData, 'dora@example.org', 'Dora Leer', $d['group'] );
\Crm\Clients::link( $appData, $d['id'], $dora['id'] );
\Crm\Settings::set( $appData, 'privacy_text', '' );
\Nino\Features::deactivate( $appData, 'events' );
portalSignIn( $appData, $dora );
$nothing = portalPage( $appData, '/portal' )[1];
check( 'a client for whom nothing applies reads one sentence: the heading of the section, no card, and an empty state in line with an icon', portalText( $nothing, '//section[@aria-labelledby="crm-start-heading"]/h3[@id="crm-start-heading"][contains(@class, "crm-section__title")]' ) === $wordsOfPortal['[[/project/crm-portal/start/heading]]'] && $cards( $nothing ) === []
	&& portalText( $nothing, '//div[contains(@class, "crm-start__nothing")][contains(@class, "crm-empty")]/svg[@aria-hidden="true"]/following-sibling::p[contains(@class, "crm-empty__text")]' ) === $wordsOfPortal['[[/project/crm-portal/start/nothing]]'] && portalLeft( $nothing ) === false );
check( '...the start page has the cards above the open projects: the heading comes before the list of projects', strpos( $nothing, 'crm-start' ) < strpos( $nothing, 'crm-projects' ) );
check( '...and no row of key figures: a client for whom every number is nothing reads no row of zeros', portalFind( $nothing, '//ul[contains(@class, "crm-kpis")] | //a[@data-kpi]' ) === [] );
\Nino\Features::activate( $appData, 'events' );
$onlyDate = portalPage( $appData, '/portal' )[1];
\Nino\Features::deactivate( $appData, 'events' );
check( '...a date alone is no figure: a client with nothing but a public date coming (the date is on the card of the dates) reads no row of key figures, with Events on', in_array( 'dates', $cards( $onlyDate ), true ) === true && $cards( $onlyDate ) === [ 'dates' ] && portalFind( $onlyDate, '//ul[contains(@class, "crm-kpis")] | //a[@data-kpi]' ) === [] );
\Crm\Settings::set( $appData, 'privacy_text', $text );
check( 'the card Datenschutz stands there while the owner asks for a text that the client has not agreed to, with the link - and alone it is the only card, and still no row of key figures', $cards( portalPage( $appData, '/portal' )[1] ) === [ 'privacy' ] && portalFind( portalPage( $appData, '/portal' )[1], '//article[@data-card="privacy"]//a/@href' )[0]->nodeValue === '/portal/datenschutz' && portalFind( portalPage( $appData, '/portal' )[1], '//ul[contains(@class, "crm-kpis")]' ) === [] );
\Nino\Features::activate( $appData, 'events' );
portalSignIn( $appData, $accounts['anna'] );
Db::exec( $appData, 'UPDATE documents SET released_at = :at, date = :day WHERE id = :id', [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 20 * 86400 ), 'day' => date( 'Y-m-d', strtotime( '+1 day' ) ), 'id' => $invoiceA['id'] ] );
\Nino\Elements::insertElement( $appData, '/events/markup', [ 'title' => '<b>x</b> [members-name]', 'start' => $soon( 13 ) ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/spaeter', [ 'title' => 'Zu spät für die Karte', 'start' => $soon( 45 ), 'group' => $a['group'] ], 'de_DE' );
$folderNew = $store::addFolder( $appData, $a['group'], 'Neu' );
$put( $folderNew, 'Von aussen.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 600 ) );
$put( $folderNew, 'Von mir.mp3', 'mp3', 'audio/mpeg', false, $byAnna, $ago( 500 ) );
$put( $folderNew, 'Alt.mp3', 'mp3', 'audio/mpeg', true, $owner, '2020-01-01T09:00:00Z' );
$put( $folderNew, 'Versteckt.mp3', 'mp3', 'audio/mpeg', false, $owner, $ago( 400 ) );
$pNew = $made( 'Dateien-Test', $a['number'], [ 'folder' => $folderNew['id'] ] );
$orderOpen = $mkOffer( 'Wartet', [ 'project' => $p1 ] );
$orderOpenPlain = $mkOffer( 'Wartet ohne Projekt', [] );
$start = portalPage( $appData, '/portal' )[1];
check( 'for Studio Nord the cards are there that apply - not the Datenschutz it has agreed to: the offers, the documents, the files and the dates', $cards( $start ) === [ 'offers', 'documents', 'files', 'dates' ] && portalFind( $start, '//article[@data-card="privacy"]' ) === [] );
$offerRows = $rowsOf( $start, 'offers' );
$offerLinks = array_map( $hrefOf, $offerRows );
$expectedNumbers = [ $orderAtomic['number'], $orderOpen['number'], $orderOpenPlain['number'] ];
sort( $expectedNumbers );
$offerNumbers = array_map( static fn( \DOMElement $row ) => explode( ' ', $partOf( $row, 'meta' ) )[0], $offerRows );
sort( $offerNumbers );
check( 'Angebote, die auf Deine Beauftragung warten: each offer that can be ordered - not those that were ordered, ran out or are not released - a row with its title and amount and its number as meta', count( $offerRows ) === 3 && $offerNumbers === $expectedNumbers && array_filter( $offerRows, static fn( \DOMElement $row ): bool => $partOf( $row, 'text' ) === '' || preg_match( '/\d,\d\d/', $partOf( $row, 'extra' ) ) !== 1 ) === [] );
$expectedLinks = [ '/portal/projekte?projekt='. $p1. '&tab=angebot#angebot-'. $orderOpen['id'], '/portal/belege#beleg-'. $orderAtomic['id'], '/portal/belege#beleg-'. $orderOpenPlain['id'] ];
$sortedLinks = $offerLinks;
sort( $expectedLinks );
sort( $sortedLinks );
check( '...the link leads to the offer itself - the project page on the tab Angebot at the anchor of its card, or the anchor of its row in the list of documents for an offer without a project - and the meta has the day it is valid until', $sortedLinks === $expectedLinks && str_contains( portalText( $start, '//article[@data-card="offers"]' ), \Crm\Documents\Render::day( $valid( 20 ) ) ) === true );

// an offer with no validity day: it never runs out, says no "gültig bis", is on the card with no day and can be ordered
$openValid = Db::row( $appData, 'SELECT valid_until FROM documents WHERE id = :id', [ 'id' => $orderOpen['id'] ] )['valid_until'];
Db::exec( $appData, 'UPDATE documents SET valid_until = NULL WHERE id = :id', [ 'id' => $orderOpen['id'] ] );
$undatedStart = portalPage( $appData, '/portal' )[1];
$undatedRows = $rowsOf( $undatedStart, 'offers' );
$undatedRow = array_values( array_filter( $undatedRows, static fn( \DOMElement $row ): bool => str_starts_with( $partOf( $row, 'meta' ), $orderOpen['number'] ) ) );
$undatedProject = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
check( 'an offer without a validity day can be ordered while it is open or sent, and is not expired', Documents::orderable( (array) Documents::get( $appData, $orderOpen['id'] ) ) === true && Documents::get( $appData, $orderOpen['id'] )['expired'] === false
	&& array_column( Documents::released( $appData, $a['id'] ), 'expired', 'id' )[$orderOpen['id']] === false );
check( '...the card of the start page has its row all the same, with the number and no day behind it - and the other rows keep theirs', count( $undatedRow ) === 1 && $partOf( $undatedRow[0], 'meta' ) === $orderOpen['number']
	&& count( $undatedRows ) === 3 && count( array_filter( $undatedRows, static fn( \DOMElement $row ): bool => str_contains( $partOf( $row, 'meta' ), '·' ) === true ) ) === 2 );
$undatedCard = '//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")][@data-document="'. $orderOpen['id']. '"]';
check( '...its offer on the page of the project has no line "gültig bis" and no time element for it, has the form of the order, and the others have their lines', portalFind( $undatedProject, $undatedCard. '//span[@class="crm-offer__valid"]' ) === [] && portalFind( $undatedProject, $undatedCard. '//summary//time[not(@class="crm-offer__date")]' ) === []
	&& portalFind( $undatedProject, $undatedCard. '//form[contains(@class, "crm-order")]' ) !== [] && portalFind( $undatedProject, '//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")][@data-document="'. $orderOne['id']. '"]//span[@class="crm-offer__valid"]' ) !== [] );
Db::exec( $appData, 'UPDATE documents SET valid_until = :v WHERE id = :id', [ 'v' => $openValid, 'id' => $orderOpen['id'] ] );
Db::exec( $appData, 'UPDATE documents SET title = :t WHERE id = :id', [ 't' => '<b>Neu</b> [members-name]', 'id' => $orderOpenPlain['id'] ] );
$markupStart = portalPage( $appData, '/portal' )[1];
check( 'the title of an offer in the card is text: markup and a shortcode in it are shown as typed', str_contains( $markupStart, '&lt;b&gt;Neu&lt;/b&gt; &#91;members-name]' ) === true && str_contains( $markupStart, '<b>Neu</b>' ) === false && portalFind( $markupStart, '//article[@data-card="offers"]//b' ) === [] );
check( 'the start page names the member once, in the account of the bar: the head of the page has no line for the member, and the greeting is no block of the page template', count( portalFind( $markupStart, '//header[@class="crm-bar"]//a[contains(@class, "crm-account")]//span[contains(@class, "crm-account__name")]' ) ) === 1 && portalText( $markupStart, '//span[contains(@class, "crm-account__name")]' ) === 'Anna Nord'
	&& portalFind( $markupStart, '//div[@class="crm-head"]//a[contains(@class, "crm-account")] | //div[@class="crm-head"]//a[contains(@href, "logout")] | //div[@class="crm-head"]//*[contains(@class, "members-name")]' ) === [] && portalFind( $markupStart, '//section[@id="portal"]//*[contains(@class, "members-name") or contains(@class, "crm-client")]' ) === [] );
$documentRows = $rowsOf( $start, 'documents' );
check( 'Neue Dokumente der letzten 14 Tage: what was released lately, at most five, each with its title and amount, its number and the day it was released as meta, and the anchor of its row - a document released twenty days ago is not there', count( $documentRows ) === 5 && str_contains( portalText( $start, '//article[@data-card="documents"]' ), $invoiceA['number'] ) === false
	&& array_filter( $documentRows, static fn( \DOMElement $row ) => preg_match( '~^(/portal/belege|/portal/projekte\?projekt=\d{5}&tab=belege)#beleg-\d+$~D', $hrefOf( $row ) ) !== 1 || str_ends_with( $partOf( $row, 'meta' ), \Crm\Documents\Render::day( gmdate( 'Y-m-d' ) ) ) === false ) === [] );
$fileRows = $rowsOf( $start, 'files' );
$fileRowOf = static fn( array $rows, string $project ): ?\DOMElement => array_values( array_filter( $rows, static fn( \DOMElement $row ): bool => $hrefOf( $row ) === '/portal/projekte?projekt='. $project. '&tab=dateien#projekt-files' ) )[0] ?? null;
check( '...a project whose folder holds one lately visible file of the owner, the member\'s own new upload, an old visible file and a hidden one counts exactly one new file', $fileRowOf( $fileRows, $pNew ) !== null && preg_match( '/^1 \S/', $partOf( $fileRowOf( $fileRows, $pNew ), 'extra' ) ) === 1 );
check( 'Neue Dateien der letzten 14 Tage: for each project with a folder the number of files that came lately and that the member may see - the member\'s own uploads are no news, the old file and the hidden ones are not counted - and the way to the files of the project', count( $fileRows ) >= 1 && $fileRowOf( $fileRows, $p1 ) !== null && $partOf( $fileRowOf( $fileRows, $p1 ), 'text' ) === 'Album 2027'
	&& preg_match( '/^[2-9] \S/', $partOf( $fileRowOf( $fileRows, $p1 ), 'extra' ) ) === 1 && $partOf( $fileRowOf( $fileRows, $p1 ), 'extra' ) !== $partOf( $fileRowOf( $fileRows, $pNew ), 'extra' ) );
check( 'the cards of the start page are .crm-card in one grid: no card without the class, and the links of the offers and of the files name their tab (angebot, dateien) and the anchor of their card', portalFind( $start, '//article[@data-card][not(contains(concat(" ", normalize-space(@class), " "), " crm-card "))]' ) === [] && count( portalFind( $start, '//div[contains(@class, "crm-start__grid")]/article[@data-card]' ) ) >= 3
	&& array_filter( array_map( $hrefOf, $fileRows ), static fn( string $href ): bool => str_contains( $href, '&tab=dateien#projekt-files' ) === false ) === []
	&& array_filter( $offerRows, static fn( \DOMElement $row ): bool => str_contains( $hrefOf( $row ), 'projekt=' ) === true && str_contains( $hrefOf( $row ), '&tab=angebot#angebot-' ) === false ) === [] );
$dateRows = $rowsOf( $start, 'dates' );
check( 'Termine der nächsten 30 Tage: the dates of the member\'s group and for everyone, the soonest first, with the day and the time - not another group\'s, not one that is over or further away than thirty days - and the way to the date itself on the page Termine', array_map( static fn( \DOMElement $row ): string => $partOf( $row, 'text' ), $dateRows ) === [ 'Aufnahme Gruppe A', '<b>x</b> [members-name]', 'Offener Termin' ]
	&& str_contains( $start, '&lt;b&gt;x&lt;/b&gt; &#91;members-name]' ) === true && str_contains( $partOf( $dateRows[0], 'extra' ), date( 'd.m.Y', strtotime( '+12 days' ) ). ', 10:00' ) === true && array_map( $hrefOf, $dateRows ) === [ '/portal/termine#termin-aufnahme-a', '/portal/termine#termin-markup', '/portal/termine#termin-offen' ] && str_contains( $start, 'Zu spät für die Karte' ) === false );

// the key figures over the cards: what waits, what is open, what is new, what comes next - four tiles, each a link
$figures = portalPage( $appData, '/portal' )[1];
$tilesOf = static fn( string $page ): array => array_map( static fn( \DOMElement $tile ): string => $tile->getAttribute( 'data-kpi' ), portalFind( $page, '//section[@aria-labelledby="crm-start-heading"]/ul[contains(@class, "crm-kpis")]/li/a[contains(@class, "crm-kpi")]' ) );
$tilePart = static fn( string $page, string $name, string $part ): string => portalText( $page, '//a[@data-kpi="'. $name. '"]/span[@class="crm-kpi__'. $part. '"]' );
$money = static fn( int $cents ): string => str_replace( "\u{00A0}", ' ', \Crm\Documents\Money::format( $cents, \Crm\Settings::get( $appData, 'currency' ) ) );
$sumOf = static fn( string $where ): array => Db::row( $appData, 'SELECT COUNT(*) AS n, COALESCE( SUM( gross ), 0 ) AS sum FROM documents WHERE client_id = :c AND released = 1 AND pdf_path != \'\' AND '. $where, [ 'c' => $a['id'] ] );
$waitingOffers = $sumOf( 'category = \'offer\' AND state IN ( \'open\', \'sent\' ) AND ( valid_until IS NULL OR valid_until >= \''. date( 'Y-m-d' ). '\' )' );
$openInvoices = $sumOf( 'category = \'invoice\' AND state IN ( \'open\', \'sent\' )' );
$newFiles = array_sum( array_map( static fn( \DOMElement $row ): int => (int) $partOf( $row, 'extra' ), $fileRows ) );
check( 'the key figures: four tiles over the cards, in a list under the heading of the section - the offers that wait for the order, the invoices that are open, the new files, the next date - each a link that goes to where it is, and the one that waits in the warning tone', $tilesOf( $figures ) === [ 'offers', 'invoices', 'files', 'date' ]
	&& array_map( static fn( \DOMElement $tile ): string => $tile->getAttribute( 'href' ), portalFind( $figures, '//ul[contains(@class, "crm-kpis")]/li/a' ) ) === [ '/portal/belege', '/portal/belege', '/portal/projekte', '/portal/termine' ] && strpos( $figures, 'crm-kpis' ) < strpos( $figures, 'crm-start__grid' ) && strpos( $figures, 'crm-start-heading' ) < strpos( $figures, 'crm-kpis' )
	&& portalFind( $figures, '//ul[contains(@class, "crm-kpis")][@aria-label]' ) !== [] && array_filter( portalFind( $figures, '//a[@data-kpi]' ), static fn( \DOMElement $tile ): bool => str_contains( ' '. $tile->getAttribute( 'class' ). ' ', ' crm-card ' ) === false || portalFind( $tile->ownerDocument->saveHTML( $tile ), '//span[@class="crm-kpi__label"]' ) === [] ) === []
	&& str_contains( portalFind( $figures, '//a[@data-kpi="offers"]' )[0]->getAttribute( 'class' ), 'crm-kpi--warn' ) === true && portalFind( $figures, '//a[@data-kpi][not(@data-kpi="offers")][contains(@class, "crm-kpi--warn")]' ) === [] && portalLeft( $figures ) === false );
check( '...the offers: the number of those that can be ordered, which is the number of rows of the card of the offers, and their gross sum', $tilePart( $figures, 'offers', 'value' ) === (string) $waitingOffers['n'] && (int) $waitingOffers['n'] === count( $offerRows ) && (int) $waitingOffers['n'] > 0
	&& $tilePart( $figures, 'offers', 'sub' ) === $money( (int) $waitingOffers['sum'] ). ' '. $wordsOfPortal['[[/project/crm-portal/start/kpi-gross]]'] );
check( '...the invoices: the number of those that are open or overdue - not the paid ones, not a cancelled one - and their gross sum', $tilePart( $figures, 'invoices', 'value' ) === (string) $openInvoices['n'] && (int) $openInvoices['n'] > 0 && $tilePart( $figures, 'invoices', 'sub' ) === $money( (int) $openInvoices['sum'] ). ' '. $wordsOfPortal['[[/project/crm-portal/start/kpi-gross]]'] );
$invoiceWas = (string) Db::row( $appData, 'SELECT state FROM documents WHERE id = :id', [ 'id' => $invoiceA['id'] ] )['state'];
Db::exec( $appData, 'UPDATE documents SET state = \'paid\' WHERE id = :id', [ 'id' => $invoiceA['id'] ] );
$afterPaid = portalPage( $appData, '/portal' )[1];
$paidGross = (int) Db::row( $appData, 'SELECT gross FROM documents WHERE id = :id', [ 'id' => $invoiceA['id'] ] )['gross'];
Db::exec( $appData, 'UPDATE documents SET state = :state WHERE id = :id', [ 'state' => $invoiceWas, 'id' => $invoiceA['id'] ] );
check( '...an invoice that is paid is no longer one that is open: the tile counts one less and its sum is less by the gross of that invoice', in_array( $invoiceWas, [ 'open', 'sent' ], true ) === true && $tilePart( $afterPaid, 'invoices', 'value' ) === (string) ( $openInvoices['n'] - 1 )
	&& $tilePart( $afterPaid, 'invoices', 'sub' ) === $money( (int) $openInvoices['sum'] - $paidGross ). ' '. $wordsOfPortal['[[/project/crm-portal/start/kpi-gross]]'] && $paidGross > 0 );
check( '...the files: the number of new files over all the projects, which is the sum of the card of the files, and the number of projects they are in', $tilePart( $figures, 'files', 'value' ) === (string) $newFiles && $newFiles > 1 && $tilePart( $figures, 'files', 'sub' ) === count( $fileRows ). ' '. $wordsOfPortal['[[/project/crm-portal/start/kpi-project-'. ( count( $fileRows ) === 1 ? 'one' : 'many' ). ']]'] );
check( '...the date: the day and month of the next date, and how many days it is away - the soonest of the dates of the member', $tilePart( $figures, 'date', 'value' ) === date( 'd.m.', strtotime( '+12 days' ) ) && $tilePart( $figures, 'date', 'sub' ) === str_replace( '{days}', '12', $wordsOfPortal['[[/project/crm-portal/start/kpi-days]]'] ) );
\Nino\Features::deactivate( $appData, 'events' );
$withoutEvents = portalPage( $appData, '/portal' )[1];
check( '...where Events is off the tile of the date stays and says a dash and that there is none - the row stands, as the other numbers are more than nothing', $tilesOf( $withoutEvents ) === [ 'offers', 'invoices', 'files', 'date' ] && $tilePart( $withoutEvents, 'date', 'value' ) === '–' && $tilePart( $withoutEvents, 'date', 'sub' ) === $wordsOfPortal['[[/project/crm-portal/start/kpi-date-none]]'] );
check( 'where Events is off there is no card of dates', in_array( 'dates', $cards( portalPage( $appData, '/portal' )[1] ), true ) === false );
\Nino\Features::activate( $appData, 'events' );
portalSignIn( $appData, $accounts['anna'] );

// --- The electronic invoice for a member ---------------------------------------------------------------------------------------------

echo "\nThe electronic invoice: the download of the portal\n";

$asAdmin();
$notFound = static fn( array $response ): bool => $response['statusCode'] === 404 && ( $response['file'] ?? null ) === null && $response['body'] === 'Not Found';
$seller = [ 'seller_name' => 'Studio Beispiel', 'seller_street' => 'Weg 1', 'seller_zip' => '10115', 'seller_city' => 'Berlin', 'seller_contact' => 'Jan Beispiel', 'seller_phone' => '+49 30 123456', 'seller_email' => 'jan@beispiel.example', 'tax_id' => '12/345/67890', 'vat_id' => 'DE111222333', 'iban' => 'DE89 3704 0044 0532 0130 00' ];

foreach( $seller as $key => $value )
	\Crm\Settings::set( $appData, $key, $value );

\Crm\Clients::change( $appData, $a['id'], [ 'business' => true, 'email' => 'buchhaltung@nord.example', 'buyer_reference' => 'N-100' ] );
\Crm\Clients::change( $appData, $b['id'], [ 'business' => false ] );
$coded = static fn( string $title ): array => $line( $title ) + [ 'unit_code' => 'HUR' ];
$xmlA = $final( 'invoice', $a['id'], [ 'date' => '2026-10-25', 'project' => $p1, 'template_key' => 'rechnung', 'lines' => [ $coded( 'Mit Datei' ) ] ] );
$xmlA2 = $final( 'invoice', $a['id'], [ 'date' => '2026-10-26', 'template_key' => 'rechnung', 'lines' => [ $coded( 'Nicht freigegeben' ) ] ] );
$noXml = $final( 'invoice', $a['id'], [ 'date' => '2026-10-27', 'template_key' => 'rechnung', 'lines' => [ $line( 'Ohne Einheit' ) ] ] );
$xmlB = $final( 'invoice', $b['id'], [ 'date' => '2026-10-28', 'template_key' => 'rechnung', 'lines' => [ $coded( 'Privat' ) ] ] );
check( 'a business client\'s invoice has a file, one with a line without a unit code has none, a private client\'s has none', $xmlA['xml_path'] !== '' && $xmlA2['xml_path'] !== '' && $noXml['xml_path'] === '' && $noXml['xml_lacks'] === [ 'line_unit' ] && $xmlB['xml_path'] === '' && $xmlB['xml_lacks'] === [] );

foreach( [ $xmlA, $noXml, $xmlB ] as $document )
	Documents::release( $appData, $document['id'], false );

$xmlRow = static function( int $id ) use ( &$appData ): string {
	foreach( preg_split( '/(?=<li class="crm-documents__row )/', \Nino\Html::renderHtml( $appData, '[crm-documents]' ) ) ?: [] as $row )
		if( str_contains( $row, 'data-document="'. $id. '"' ) === true )
			return $row;

	return '';
};
$linksOf = static fn( string $row ): array => array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( '<ul>'. $row. '</ul>', '//span[contains(@class, "crm-documents__links")]/a' ) );

check( 'the member of the client is served the file of a released invoice: 200, application/xml, an attachment under the number, kept out of every cache', portalSignIn( $appData, $accounts['anna'] ) === true && ( $served = portalServe( $appData, '/.crm/xml/'. $xmlA['id'] ) )['statusCode'] === 200
	&& ( $served['file'] ?? '' ) === \Nino\Filesystem::path( $appData, $xmlA['xml_path'] ) && $served['header']['Content-Type'] === 'application/xml' && $served['header']['Content-Disposition'] === 'attachment; filename="'. $xmlA['number']. '.xml"' && $served['header']['Cache-Control'] === 'private, no-store' );
$ignored = ninoWarnings();

foreach( [ 'a document of the client that is not released' => $xmlA2['id'], 'a released document that has no file (a line without a unit code)' => $noXml['id'], 'a document of another client' => $xmlB['id'], 'a document nobody has' => 99999 ] as $why => $id )
	check( 'the same 404 for '. $why, $notFound( portalServe( $appData, '/.crm/xml/'. $id ) ) === true && ninoWarnings() === [] );

check( '...for an id that is no number, or a path past it', $notFound( portalServe( $appData, '/.crm/xml/abc' ) ) === true && $notFound( portalServe( $appData, '/.crm/xml/'. $xmlA['id']. '/x' ) ) === true && $notFound( portalServe( $appData, '/.crm/xml/01' ) ) === true );
check( 'a member of another client is served nothing of it, and a member of no client nothing', portalSignIn( $appData, $accounts['ben'] ) === true && $notFound( portalServe( $appData, '/.crm/xml/'. $xmlA['id'] ) ) === true && portalSignIn( $appData, $stray ) === true
	&& $notFound( portalServe( $appData, '/.crm/xml/'. $xmlA['id'] ) ) === true );
$asVisitor();
check( '...a visitor too', $notFound( portalServe( $appData, '/.crm/xml/'. $xmlA['id'] ) ) === true );
portalSignIn( $appData, $accounts['anna'] );
Documents::release( $appData, $xmlA2['id'], false );
check( 'releasing makes it reachable at once', portalServe( $appData, '/.crm/xml/'. $xmlA2['id'] )['statusCode'] === 200 );
$asAdmin();
Documents::unrelease( $appData, $xmlA2['id'] );
portalSignIn( $appData, $accounts['anna'] );
check( '...and taking it back makes it a 404 again', $notFound( portalServe( $appData, '/.crm/xml/'. $xmlA2['id'] ) ) === true );
$xmlFile = \Nino\Filesystem::path( $appData, $xmlA['xml_path'] );
$xmlBytes = (string) file_get_contents( $xmlFile );
file_put_contents( $xmlFile, $xmlBytes. ' ' );
check( 'a file that is not the one that was written is a 409 for a member, and the log names the number', portalServe( $appData, '/.crm/xml/'. $xmlA['id'] )['statusCode'] === 409 && str_contains( implode( ' ', ninoWarnings() ), $xmlA['number'] ) === true );
file_put_contents( $xmlFile, $xmlBytes );
$asAdmin();
check( 'the workbench keeps the route: any document with a file, released or not - and none of a document without one', portalServe( $appData, '/.crm/xml/'. $xmlA2['id'] )['statusCode'] === 200 && $notFound( portalServe( $appData, '/.crm/xml/'. $noXml['id'] ) ) === true );

portalSignIn( $appData, $accounts['anna'] );
$withFile = $xmlRow( $xmlA['id'] );
check( 'the row of a document with a file has a second download in the action area of its row, next to the PDF: "E-Rechnung (XML)", the address of the route, no query - a small secondary button like the first, its icon hidden from a reader and the number for one', $linksOf( $withFile ) === [ '/.crm/pdf/'. $xmlA['id']. '?download=1', '/.crm/xml/'. $xmlA['id'] ]
	&& portalText( '<ul>'. $withFile. '</ul>', '//a[contains(@class, "crm-documents__xml")]/span[1]' ) === $wordsDe['[[/project/crm-documents/list/xml]]'] && portalFind( '<ul>'. $withFile. '</ul>', '//span[contains(@class, "crm-table__actions")]/a[contains(@class, "crm-documents__download")][contains(@class, "crm-documents__xml")][contains(@class, "crm-btn--small")][contains(@class, "crm-btn--secondary")]/svg[@aria-hidden="true"]/following-sibling::span[contains(@class, "nino-sr-only")]' ) !== [] );
check( '...a row of a document without a file has the PDF alone, and so has a row of the private client\'s', $linksOf( $xmlRow( $noXml['id'] ) ) === [ '/.crm/pdf/'. $noXml['id']. '?download=1' ] && str_contains( $xmlRow( $noXml['id'] ), 'crm-documents__xml' ) === false && str_contains( $xmlRow( $invoiceA['id'] ), '/.crm/xml/' ) === false );
$projectHtml = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=belege' )[1];
check( '...the Belege tab of a project shows it in the same row', array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( $projectHtml, '//section[contains(@class, "crm-project__section--documents")]//li[@data-document="'. $xmlA['id']. '"]//a' ) ) === [ '/.crm/pdf/'. $xmlA['id']. '?download=1', '/.crm/xml/'. $xmlA['id'] ] );
check( 'on a site in a directory the address of the file has the directory in front of it', str_contains( (string) ( function() use ( &$appData ): string {
	$appData['/nino/dir'] = '/club';
	$row = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
	$appData['/nino/dir'] = '';

	return $row;
} )(), 'href="/club/.crm/xml/'. $xmlA['id']. '"' ) === true );

// --- The design: head, pills, amounts, due days, how to pay, anchors, empty states, icons -------------------------------------------------------

echo "\nThe design of the portal\n";

$asAdmin();
$settingsWere = [ 'bank_name' => \Crm\Settings::get( $appData, 'bank_name' ), 'iban' => \Crm\Settings::get( $appData, 'iban' ), 'bic' => \Crm\Settings::get( $appData, 'bic' ) ];
$mkInvoice = static function( string $title, array $over = [] ) use ( $final, $a, $line, $today, &$appData ): array {
	$invoice = $final( 'invoice', $a['id'], $over + [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( $title ) ] ] );
	Documents::release( $appData, $invoice['id'], false );

	return $invoice;
};
$invFuture = $mkInvoice( 'Fällt später' );
$invPast = $mkInvoice( 'Lange her', [ 'date' => '2026-01-10' ] );
$invPaid = $mkInvoice( 'Bezahlt' );
$invCancelled = $mkInvoice( 'Storniert' );
$invNoDue = $mkInvoice( 'Ohne Tag' );
foreach( [ $invFuture, $invPast, $invPaid, $invNoDue ] as $sent )
	Documents::setState( $appData, $sent['id'], 'sent' );

Documents::setState( $appData, $invPaid['id'], 'paid' );
Db::exec( $appData, 'UPDATE documents SET state = \'cancelled\' WHERE id = :id', [ 'id' => $invCancelled['id'] ] );
Db::exec( $appData, 'UPDATE documents SET due_date = NULL WHERE id = :id', [ 'id' => $invNoDue['id'] ] );
\Crm\Settings::set( $appData, 'bank_name', 'Hamburger <b>Sparkasse</b>' );
\Crm\Settings::set( $appData, 'iban', 'DE02 1203 0000 0000 2020 51' );
\Crm\Settings::set( $appData, 'bic', 'BYLADEM1001' );
check( 'the fixtures: an invoice that is still sent and falls due later, one past its day, one paid, one cancelled, one with no day to pay by', Documents::get( $appData, $invFuture['id'] )['state'] === 'sent' && Documents::get( $appData, $invFuture['id'] )['due_date'] === date( 'Y-m-d', strtotime( '+14 days' ) ) && Documents::get( $appData, $invPast['id'] )['due_date'] === '2026-01-24'
	&& Documents::get( $appData, $invPaid['id'] )['state'] === 'paid' && Documents::get( $appData, $invCancelled['id'] )['state'] === 'cancelled' && Documents::get( $appData, $invPast['id'] )['state'] === 'sent' && (string) Documents::get( $appData, $invNoDue['id'] )['due_date'] === '' );
portalSignIn( $appData, $accounts['anna'] );
$docs = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
$pill = static fn( string $html, int $id ): ?\DOMElement => portalFind( $html, '//li[@data-document="'. $id. '"]//span[contains(@class, "crm-documents__state")]/span[contains(@class, "crm-pill")]' )[0] ?? null;
$toneOf = static fn( string $html, int $id ): string => $pill( $html, $id ) === null ? '' : preg_replace( '/^.*crm-pill--(\w+).*$/s', '$1', $pill( $html, $id )->getAttribute( 'class' ) );
check( 'the pill of a row has the tone of the state: an invoice that is open is info, one past its day danger, one paid success, a cancelled one muted - an offer that ran out muted, a signed contract success', $toneOf( $docs, $invFuture['id'] ) === 'info' && $toneOf( $docs, $invPast['id'] ) === 'danger' && $toneOf( $docs, $invPaid['id'] ) === 'success' && $toneOf( $docs, $invCancelled['id'] ) === 'muted'
	&& $toneOf( $docs, $offerA['id'] ) === 'muted' && $toneOf( $docs, $contractA['id'] ) === 'success' && $toneOf( $docs, $infoA['id'] ) === 'info' );
check( '...the row says the state as the customer has it in data-state - an invoice past its day is overdue, one that was sent is open, and neither "sent" nor "draft" is a state of any row', array_map( static fn( int $id ): string => portalFind( $docs, '//li[@data-document="'. $id. '"]/@data-state' )[0]->nodeValue, [ $invFuture['id'], $invPast['id'], $invPaid['id'], $invCancelled['id'] ] ) === [ 'open', 'overdue', 'paid', 'cancelled' ]
	&& portalFind( $docs, '//li[@data-state="sent" or @data-state="draft"]' ) === [] && portalText( $docs, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-pill")]' ) === $wordsDe['[[/project/crm-documents/state/open]]'] );
check( '...a customer never reads "versendet" or "Entwurf": the words have no key for either state, in German and in English, and no row carries one', array_filter( [ 'sent', 'draft' ], static fn( string $state ): bool => isset( $wordsDe['[[/project/crm-documents/state/'. $state. ']]'] ) || isset( $wordsEn['[[/project/crm-documents/state/'. $state. ']]'] ) ) === []
	&& array_filter( [ 'versendet', 'Entwurf' ], static fn( string $word ): bool => str_contains( $docs, $word ) || str_contains( portalPage( $appData, '/portal/belege' )[1], $word ) || str_contains( portalPage( $appData, '/portal' )[1], $word ) ) === [] );
check( '...an invoice past its day says that it is overdue in the pill and nothing else - the day stands once, in the due cell as a time element - and one that is open says nothing of it', portalFind( $docs, '//li[@data-document="'. $invPast['id']. '"]//span[contains(@class, "crm-pill--danger")]/time' ) === []
	&& portalText( $docs, '//li[@data-document="'. $invPast['id']. '"]//span[contains(@class, "crm-pill--danger")]' ) === $wordsDe['[[/project/crm-documents/state/overdue]]'] && portalFind( $docs, '//li[@data-document="'. $invPast['id']. '"]//span[contains(@class, "crm-documents__due")]/time/@datetime' )[0]->nodeValue === '2026-01-24'
	&& portalFind( $docs, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-pill")]/time' ) === [] && substr_count( $docs, '2026-01-24' ) === 1 );
$cell = static fn( string $html, int $id, string $name ): ?\DOMElement => portalFind( $html, '//li[@data-document="'. $id. '"]//*[contains(concat(" ", normalize-space(@class), " "), " crm-documents__'. $name. ' ")]' )[0] ?? null;
check( 'the amount cell: the gross amount of a document with lines, right-aligned as a number cell - empty for a contract, a privacy document and an information', portalText( $docs, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-documents__amount")]' ) === '100,00 €' && str_contains( $cell( $docs, $invFuture['id'], 'amount' )->getAttribute( 'class' ), 'crm-table__num' ) === true
	&& portalText( $docs, '//li[@data-document="'. $offerA['id']. '"]//span[contains(@class, "crm-documents__amount")]' ) === '100,00 €' && trim( $cell( $docs, $contractA['id'], 'amount' )->textContent ) === '' && trim( $cell( $docs, $infoA['id'], 'amount' )->textContent ) === '' && trim( $cell( $docs, $privacyA['id'], 'amount' )->textContent ) === '' );
check( 'the due cell: an invoice names its day with the machine day ("fällig am"), one with no day says it is payable at once, a paid one keeps its day, and no other kind has a due cell with anything in it', portalFind( $docs, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-documents__due")]/time/@datetime' )[0]->nodeValue === date( 'Y-m-d', strtotime( '+14 days' ) )
	&& portalFind( $docs, '//li[@data-document="'. $invNoDue['id']. '"]//span[contains(@class, "crm-documents__due-none")]' ) !== [] && portalText( $docs, '//li[@data-document="'. $invNoDue['id']. '"]//span[contains(@class, "crm-documents__due-none")]' ) === $wordsDe['[[/project/crm-documents/list/due-none]]']
	&& portalFind( $docs, '//li[@data-document="'. $invPaid['id']. '"]//span[contains(@class, "crm-documents__due")]/time' ) !== [] && trim( $cell( $docs, $offerA['id'], 'due' )->textContent ) === '' && trim( $cell( $docs, $contractA['id'], 'due' )->textContent ) === '' );

// how an open invoice is paid
$payOf = static fn( string $html, int $id ): array => portalFind( $html, '//li[@data-document="'. $id. '"]/details[contains(@class, "crm-documents__fold")]/div[contains(@class, "crm-fold__body")]/dl[contains(@class, "crm-pay")]' );
$pay = $payOf( $docs, $invFuture['id'] );
$facts = $pay === [] ? [] : portalFind( $pay[0]->ownerDocument->saveHTML( $pay[0] ), '//div[contains(@class, "crm-pay__fact")]' );
check( 'an open invoice folds out to how it is paid: the amount, the payee, the bank, the IBAN and the BIC of the settings, and the number as the purpose - in a nested card, every value escaped, collapsed until it is asked for', count( $pay ) === 1 && str_contains( $pay[0]->getAttribute( 'class' ), 'crm-card--nested' ) === true
	&& array_map( static fn( \DOMElement $fact ): string => preg_replace( '/^.*crm-pay__fact--/', '', $fact->getAttribute( 'class' ) ), $facts ) === [ 'amount', 'to', 'bank', 'iban', 'bic', 'purpose' ]
	&& array_map( static fn( \DOMElement $fact ): string => portalText( $fact->ownerDocument->saveHTML( $fact ), '//dd' ), $facts ) === [ '100,00 €', portalText( $pay[0]->ownerDocument->saveHTML( $pay[0] ), '//div[contains(@class, "crm-pay__fact--to")]/dd' ), 'Hamburger <b>Sparkasse</b>', 'DE02 1203 0000 0000 2020 51', 'BYLADEM1001', $invFuture['number'] ]
	&& portalFind( $docs, '//li[@data-document="'. $invFuture['id']. '"]/details/@open' ) === [] && portalFind( $docs, '//li[@data-document="'. $invFuture['id']. '"]//dl[contains(@class, "crm-pay")]//b' ) === [] );
check( '...the row has the button that unfolds it, a span in the summary with the word and the number for a screen reader; an invoice past its day has the same block', portalText( $docs, '//li[@data-document="'. $invFuture['id']. '"]/details/summary//span[contains(@class, "crm-table__toggle")]/span[1]' ) === $wordsDe['[[/project/crm-portal/pay/fold]]']
	&& portalText( $docs, '//li[@data-document="'. $invFuture['id']. '"]/details/summary//span[contains(@class, "crm-table__toggle")]/span[contains(@class, "nino-sr-only")]' ) === $invFuture['number'] && count( $payOf( $docs, $invPast['id'] ) ) === 1 && count( $payOf( $docs, $invNoDue['id'] ) ) === 1 );
check( '...a paid or a cancelled invoice has no block and no fold: the row is the plain line; nor have an offer\'s or a contract\'s rows one of these', array_filter( [ $invPaid['id'], $invCancelled['id'] ], static fn( int $id ): bool => $payOf( $docs, $id ) !== [] || portalFind( $docs, '//li[@data-document="'. $id. '"]/details' ) !== [] || portalFind( $docs, '//li[@data-document="'. $id. '"]/div[contains(@class, "crm-documents__line")]' ) === [] ) === []
	&& portalFind( $docs, '//li[@data-category="contract"]//dl[contains(@class, "crm-pay")] | //li[@data-category="offer"]//dl[contains(@class, "crm-pay")]' ) === [] );
\Crm\Settings::set( $appData, 'bic', '' );
$noBic = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( '...a BIC that the settings do not have is not a line of the block', array_map( static fn( \DOMElement $fact ): string => preg_replace( '/^.*crm-pay__fact--/', '', $fact->getAttribute( 'class' ) ), portalFind( $noBic, '//li[@data-document="'. $invFuture['id']. '"]//div[contains(@class, "crm-pay__fact")]' ) ) === [ 'amount', 'to', 'bank', 'iban', 'purpose' ] );
\Crm\Settings::set( $appData, 'bank_name', '' );
\Crm\Settings::set( $appData, 'iban', '' );
$noBank = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( '...and where the settings hold neither a bank nor an IBAN there is nothing to tell: no block, no fold and no button on the row of an open invoice', portalFind( $noBank, '//dl[contains(@class, "crm-pay")]' ) === [] && portalFind( $noBank, '//li[@data-document="'. $invFuture['id']. '"]/details' ) === [] && portalFind( $noBank, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-table__toggle")]' ) === [] );
foreach( $settingsWere as $key => $value )
	\Crm\Settings::set( $appData, $key, $value );

// the offers in the list of documents: a row that unfolds to its card, folded unless the address names it
$offerRowOf = static fn( string $html, int $id ): array => portalFind( $html, '//li[@data-document="'. $id. '"]/details[contains(@class, "crm-documents__fold")]' );
$beleg = portalPage( $appData, '/portal/belege' )[1];
check( 'an offer in the list of documents is a row that folds out to its card - collapsed to its line, with the button that unfolds it - and so are the orderable ones; no fold is open on the page', count( $offerRowOf( $beleg, $orderOpenPlain['id'] ) ) === 1 && $offerRowOf( $beleg, $orderOpenPlain['id'] )[0]->hasAttribute( 'open' ) === false && count( $offerRowOf( $beleg, $offerA['id'] ) ) === 1
	&& portalText( $beleg, '//li[@data-document="'. $orderOpenPlain['id']. '"]/details/summary//span[contains(@class, "crm-table__toggle")]/span[1]' ) === $wordsDe['[[/project/crm-portal/order/fold]]'] && portalFind( $beleg, '//li[@data-document="'. $orderOpenPlain['id']. '"]/details/div[contains(@class, "crm-fold__body")]//form[contains(@class, "crm-order")]' ) !== []
	&& portalFind( $beleg, '//li//details[@open]' ) === [] );
$named = portalPage( $appData, '/portal/belege?beleg='. $orderOpenPlain['id'] )[1];
check( '...the address that names an offer (?beleg=<id>) unfolds that row and no other', $offerRowOf( $named, $orderOpenPlain['id'] )[0]->hasAttribute( 'open' ) === true && count( portalFind( $named, '//li//details[@open]' ) ) === 1 );

// every row and every card has the anchor the start page and the redirects lead to
$projectDocs = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$idsOf = static fn( string $html ): array => array_map( static fn( \DOMNode $node ): string => (string) $node->nodeValue, portalFind( $html, '//@id' ) );
check( 'the anchors: each document row is #beleg-<id> and each offer card #angebot-<id>, and no id is given twice on a page', array_filter( portalFind( $beleg, '//li[@data-document]' ), static fn( \DOMElement $row ): bool => $row->getAttribute( 'id' ) !== 'beleg-'. $row->getAttribute( 'data-document' ) ) === []
	&& array_filter( portalFind( $projectDocs, '//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")]' ), static fn( \DOMElement $card ): bool => $card->getAttribute( 'id' ) !== 'angebot-'. $card->getAttribute( 'data-document' ) ) === [] && portalFind( $projectDocs, '//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")]' ) !== []
	&& array_unique( $idsOf( $beleg ) ) === $idsOf( $beleg ) && array_unique( $idsOf( $projectDocs ) ) === $idsOf( $projectDocs ) );
$startNow = portalPage( $appData, '/portal' )[1];
$targets = [];

foreach( [ 'offers', 'documents', 'files', 'dates' ] as $card )
	foreach( $rowsOf( $startNow, $card ) as $row )
		$targets[] = $hrefOf( $row );

check( '...every link on a card of the start page leads to an anchor that the page it names has', count( $targets ) >= 8 && array_filter( $targets, static function( string $href ) use ( &$appData ): bool {
	[ $address, $fragment ] = explode( '#', $href, 2 ) + [ '', '' ];

	return $fragment === '' || portalFind( portalPage( $appData, html_entity_decode( $address ) )[1], '//*[@id="'. $fragment. '"]' ) === [];
} ) === [] );

// the head of every page
$pagesOf = [ '/portal' => 'portal', '/portal/meine-daten' => 'meine-daten', '/portal/projekte' => 'projekte', '/portal/upload' => 'upload', '/portal/belege' => 'belege', '/portal/termine' => 'termine', '/portal/datenschutz' => 'datenschutz' ];
$headIssues = [];

foreach( $pagesOf as $uri => $name ) {
	$one = portalPage( $appData, $uri )[1];
	$headOk = count( portalFind( $one, '//div[@class="crm-head"]' ) ) === 1 && count( portalFind( $one, '//div[@class="crm-head"]//h2[contains(@class, "crm-head__title")]/span[@class="crm-head__name"]' ) ) === 1
		&& portalText( $one, '//span[@class="crm-head__name"]' ) === $unitDe['[[/_nino/webpage/portal'. ( $name === 'portal' ? '' : '/'. $name ). '/title]]'] && count( portalFind( $one, '//p[@class="crm-head__lead"]' ) ) === 1
		&& portalFind( $one, '//div[@class="crm-head"]//p[@class="crm-head__meta"]' ) === [] && count( portalFind( $one, '//nav[contains(@class, "crm-nav")]/following-sibling::div[@class="crm-head"]' ) ) === 1
		&& portalText( $one, '//header[@class="crm-bar"]/div/a[contains(@class, "crm-account")][@href="/portal/meine-daten"]/span[@class="crm-account__avatar"][@aria-hidden="true"]' ) === 'AN' && portalText( $one, '//header[@class="crm-bar"]/div/a[@href="/portal/meine-daten"]//span[@class="crm-account__name"]' ) === 'Anna Nord'
		&& portalFind( $one, '//header[@class="crm-bar"]/div/a[contains(@class, "crm-account__logout")][@href="/.members/logout"]/svg[@aria-hidden="true"]' ) !== [] && count( portalFind( $one, '//header[@class="crm-bar"]/following-sibling::nav[contains(@class, "crm-nav")]' ) ) === 1 && count( portalFind( $one, '//header[@class="crm-bar"]//h1' ) ) === 1 && portalFind( $one, '//div[@class="crm-head"]//a[contains(@class, "crm-account")]' ) === []
		&& portalFind( $one, '//div[@class="crm-head"]//header' ) === [] && strpos( $one, 'crm-head__title' ) < strpos( $one, 'class="crm-' . ( [ 'portal' => 'start', 'meine-daten' => 'data', 'projekte' => 'projects', 'upload' => 'upload', 'belege' => 'documents', 'termine' => 'dates', 'datenschutz' => 'privacy' ][$name] ). '' );

	if( $headOk === false )
		$headIssues[] = $uri;
}

check( 'every page of the portal opens with the bar of the application (the name of the site with the h1, the account of the member - the initials as a link to Meine Daten - and Abmelden as an icon link), the navigation and then the page head: a div (never a header), the page title as the h2 and the lead - and it is the first thing in the page, before the content', $headIssues === [] );
check( '...the calendar is in the head of Termine only, the breadcrumb in the head of a project only', array_filter( array_keys( $pagesOf ), static fn( string $uri ): bool => ( portalFind( portalPage( $appData, $uri )[1], '//div[@class="crm-head"]//a[contains(@class, "events-feed")]' ) !== [] ) !== ( $uri === '/portal/termine' ) ) === []
	&& array_filter( array_keys( $pagesOf ), static fn( string $uri ): bool => portalFind( portalPage( $appData, $uri )[1], '//div[@class="crm-head"]//nav[contains(@class, "crm-crumbs")]' ) !== [] ) === [] && portalFind( $projectDocs, '//div[@class="crm-head"]//nav[contains(@class, "crm-crumbs")]' ) !== [] );

// the empty states: an icon, the sentence, and the way to the studio
$strayState = portalSignIn( $appData, $stray ) === true;
$emptyOf = static fn( string $component ): string => \Nino\Html::renderHtml( $appData, '['. $component. ']' );
$emptyChecks = array_filter( [ 'crm-documents', 'crm-projects', 'crm-client-data', 'crm-privacy', 'crm-uploads' ], static function( string $component ) use ( $emptyOf ): bool {
	$html = $emptyOf( $component );

	return count( portalFind( $html, '//div[contains(@class, "crm-empty")]/svg[@aria-hidden="true"]/following-sibling::p[contains(@class, "crm-empty__text")]' ) ) !== 1
		|| portalFind( $html, '//p[contains(@class, "crm-empty__contact")]/a[contains(@class, "crm-empty__link")][starts-with(@href, "mailto:")]' ) === [] || portalFind( $html, '//p[contains(@class, "crm-empty__contact")]/a[contains(@class, "crm-empty__link")][starts-with(@href, "tel:")]' ) === []
		|| count( portalFind( $html, '//p[contains(@class, "crm-empty__contact")]/a[contains(@class, "crm-empty__link")]' ) ) !== count( portalFind( $html, '//p[contains(@class, "crm-empty__contact")]/a[contains(@class, "crm-empty__link")]/svg[@aria-hidden="true"]' ) );
} );
check( 'the empty states - documents, projects, data, Datenschutz, uploads - each have an icon, one sentence and the e-mail address and the phone number of the studio as links (mailto: and tel:)', $strayState === true && $emptyChecks === [] );
$mailWas = (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' );
$phoneWas = (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/phone' );
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'no-address', '[[/project/company/contact/phone]]' => 'keine' ], '*' );
$bare = portalPage( $appData, '/portal/belege' )[1];
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'no-address', '[[/project/company/contact/phone]]' => '+49 (0) 170 1234567' ], '*' );
$phoneOnly = portalPage( $appData, '/portal/belege' )[1];
\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => $mailWas, '[[/project/company/contact/phone]]' => $phoneWas ], '*' );
check( '...a studio without an address and a phone leaves the way out and keeps the sentence; with one of them it links that one', portalFind( $bare, '//p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( $bare, '//p[contains(@class, "crm-empty__contact")]' ) === []
	&& portalFind( $phoneOnly, '//p[contains(@class, "crm-empty__contact")]/a[starts-with(@href, "tel:")]' ) !== [] && portalFind( $phoneOnly, '//a[starts-with(@href, "mailto:")]' ) === [] );
portalSignIn( $appData, $accounts['anna'] );

// the icons: inline SVG, decoration hidden from a reader, a named one an image with its name
$decor = \Crm\Portal\Icons::svg( 'check' );
$named = \Crm\Portal\Icons::svg( 'check', 'Erledigt <b>' );
check( 'an icon is an inline svg: beside words it is hidden from a reader and cannot be focused, with a name it is an image carrying it, escaped - and a name that is no icon is nothing', str_starts_with( $decor, '<svg ' ) === true && str_contains( $decor, 'aria-hidden="true"' ) === true && str_contains( $decor, 'focusable="false"' ) === true && str_contains( $decor, 'role=' ) === false && str_contains( $decor, 'class="crm-icon crm-icon--check"' )
	&& str_contains( $named, 'role="img"' ) === true && str_contains( $named, 'aria-label="Erledigt &lt;b&gt;"' ) === true && str_contains( $named, 'aria-hidden' ) === false && \Crm\Portal\Icons::svg( 'no-such-icon' ) === '' && \Crm\Portal\Icons::fill( 'a[[icon:check]]b[[icon:nothing]]c' ) === 'a'. $decor. 'bc' );
$withButtons = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
check( '...in a button or a link made a button the icon is hidden: the sign-out, the download, the order and the data form each hold an svg with aria-hidden before their words - and no svg of a portal page is left to a reader without a name', portalFind( $withButtons, '//a[contains(@class, "crm-btn")]/svg[@aria-hidden="true"]/following-sibling::span' ) !== [] && portalFind( $withButtons, '//button[contains(@class, "crm-btn")][@type="submit"]/svg[@aria-hidden="true"]/following-sibling::span' ) !== []
	&& portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//button[contains(@class, "crm-btn--primary")]/svg[@aria-hidden="true"]' ) !== []
	&& array_filter( array_keys( $pagesOf ) + [ 'extra' => '/portal/projekte?projekt='. $p1 ], static fn( string $uri ): bool => portalFind( portalPage( $appData, str_starts_with( $uri, '/' ) ? $uri : '/portal' )[1], '//section[contains(@class, "crm-page")]//svg[not(@aria-hidden="true")][not(@role="img")]' ) !== [] ) === [] );

// no inline anything: markup is in the templates, the look in the sheet, the behaviour in the script file
$inline = [];

foreach( array_merge( (array) glob( $root. '/app/Crm/Portal/templates/*.tpl' ), (array) glob( $root. '/app/Crm/Crm/install/templates/*.tpl' ) ) as $file )
	if( preg_match( '/<style|<script|\sstyle=|\son[a-z]+=|javascript:/i', (string) file_get_contents( $file ) ) === 1 )
		$inline[] = basename( $file );

check( 'no template of the portal or of the install unit holds a style element, a script element, a style attribute, a handler attribute or a javascript: address (the policy of the surface stays strict, the portal asks nothing of it)', $inline === [] && str_contains( (string) file_get_contents( $root. '/app/Crm/Portal/Portal.php' ), 'unsafe-inline' ) === false );
check( '...and no page of the portal holds one either: the only script is the file of the bundle, the only style the sheet of the bundle', array_filter( array_keys( $pagesOf ), static function( string $uri ) use ( &$appData ): bool {
	$one = portalPage( $appData, $uri )[1];

	return portalFind( $one, '//section[contains(@class, "crm-page")]//script | //section[contains(@class, "crm-page")]//style | //section[contains(@class, "crm-page")]//*[@style]' ) !== [] || preg_match( '/\son(?:click|load|error|change|input|submit|toggle)=/i', $one ) === 1;
} ) === [] );
$script = (string) file_get_contents( $root. \Crm\Portal::SCRIPT );
check( 'the script file is there, a strict module of its own that keeps the address true with the tab (replaceState), takes the state out of the address after it was shown (the state of a form and what Sign said) and leaves a click on a tab of the kernel\'s script on the page - and writes no markup from text', str_starts_with( ltrim( $script ), '/**' ) === true && str_contains( $script, "'use strict'" ) === true && str_contains( $script, 'replaceState' ) === true && str_contains( $script, "'sign-state'" ) === true && str_contains( $script, 'preventDefault' ) === true
	&& preg_match( '/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function/', $script ) !== 1 );

// The rest of the suite runs as it did before: Review and Events off
foreach( [ 'events', 'review' ] as $key )
	\Nino\Features::deactivate( $appData, $key );

// Members off: nobody can be mailed
foreach( [ 'sign', 'files', 'members' ] as $key )
	\Nino\Features::deactivate( $appData, $key );

$reset();
$before = count( $mails );
$withoutMembers = Documents::release( $appData, $infoB['id'], true );
check( 'with Members off a release with the mail mails nobody: the accounts are Members\'', is_array( $withoutMembers ) && $withoutMembers['mailed'] === 0 && count( $mails ) === $before );

$asAdmin();
check( '...and the panel has no accounts to offer for a signature', $post( 'crm-documents/get', [ 'id' => (string) $privacyA['id'] ] )[1]['accounts'] === [] );

ninoDone( $appData );

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
 *	A component as the request for a path draws it: the shortcode on its own, whatever the gate of the client would make of the page
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *	@param		string		$shortcode
 *
 *	@return 	string
 */
function portalComponentAt( array &$appData, string $uri, string $shortcode ): string {

	ninoVisit( $appData, $uri );

	return \Nino\Html::renderHtml( $appData, $shortcode );
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

	$signedIn = portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] )['statusCode'] === 303 && ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $account['id'];

	// What the sign-in kept for its own request is not for the next one
	\Crm\Portal::forget( $appData );
	\Crm\Projekte::forget( $appData );

	return $signedIn;
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
 *	The markup of one section of the page of a project (its id is projekt-<name>: offer, files, documents or overview)
 *
 *	@param		string		$html
 *	@param		string		$name
 *
 *	@return 	string										'' for no such section
 */
function portalSection( string $html, string $name ): string {

	$found = portalFind( $html, '//section[@id="projekt-'. $name. '"]' );

	return $found === [] ? '' : $found[0]->ownerDocument->saveHTML( $found[0] );
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

// The setup enters the Datenschutzerklärung; this suite sets its own text where it needs one
\Crm\Db::exec( $appData, 'DELETE FROM settings WHERE key IN ( \'privacy_text\', \'privacy_changed\' )' );

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
$c = \Crm\Clients::add( $appData, [ 'company' => 'Ohne Konto AG', 'street' => 'Kaistr. 9', 'zip' => '24103', 'city' => 'Kiel' ] );
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
check( '...the link in each is the absolute address of the row of the document in the list Belege (a document without a project), and the greeting names the account', str_contains( $sent[0]['body'], 'href="https://example.org/club/portal/belege#beleg-'. $invoiceA['id']. '"' ) === true && str_contains( $sent[0]['body'], 'Hallo Anna Nord,' ) === true && str_contains( $sent[1]['body'], 'Hallo Carl Nord,' ) === true );
$invoiceFull = (array) Documents::get( $appData, $invoiceA['id'] );
$invoiceDay = \Crm\Documents\Render::day( (string) $invoiceFull['due_date'] );
check( '...the subject names the kind and the number with the gross sum and the day it is due (or that it is payable at once), the body names the kind and the number, and nothing is attached: the body holds no file name, the mail no attachment', $sent[0]['subject'] === \Crm\Documents\Mail::text( 'subject.facts', \Crm\Documents\Mail::text( 'subject.head', \Crm\Documents\Mail::text( 'kind.invoice' ), $invoiceA['number'] ), \Crm\Documents\Money::format( (int) $invoiceFull['gross'], \Crm\Settings::get( $appData, 'currency' ) ). ', '. ( $invoiceDay === '' ? \Crm\Documents\Mail::text( 'subject.due-none' ) : \Crm\Documents\Mail::text( 'subject.due', $invoiceDay ) ) ) && str_contains( $sent[0]['body'], \Crm\Documents\Mail::text( 'line', \Crm\Documents\Mail::text( 'kind.invoice' ), $invoiceA['number'] ) ) === true
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
check( '...the mail names the contract by its title and its kind', portalMail( count( $mails ) - 1 )['subject'] === \Crm\Documents\Mail::text( 'subject.head', \Crm\Documents\Mail::text( 'kind.contract' ), 'Dienstleistungsvertrag' ) );

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
	&& preg_match( '/:where\(:root\)\s*\{[^}]*--crm-text-xs[^}]*--crm-text-xxl[^}]*--crm-1:[^}]*--crm-7:[^}]*--crm-surface:/s', (string) file_get_contents( dirname( __DIR__, 3 ). '/app/Crm/Portal/assets/portal.css' ) ) === 1 );
check( 'the script of the portal is in the bundle of the site, once, and the file is there', count( array_keys( $appData['/nino/html/assets']['/.cache/script.js'] ?? [], \Crm\Portal::SCRIPT, true ) ) === 1 && is_file( $root. \Crm\Portal::SCRIPT ) === true );
check( '...the list stands at the left of its column as the other lists of the page do: no auto margin on the sides', preg_match( '/\n\.crm-documents \{[^}]*margin: 0 0 var\(--crm-7\);/s', (string) file_get_contents( dirname( __DIR__, 3 ). '/app/Crm/Portal/assets/portal.css' ) ) === 1 );

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
check( 'the list has a row for each released document of the member\'s client, what asks for something first and then the newest, and none for anything else', count( $rows ) === 5 && array_map( static fn( string $row ): int => (int) preg_replace( '/^.*?data-document="(\d+)".*$/s', '$1', $row ), $rows ) === [ $invoiceA['id'], $infoA['id'], $privacyA['id'], $contractA['id'], $offerA['id'] ]
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
	'sign-state=signed&sign='. $signed['sign_id']. '%00', 'sign-state=signed&sign='. ( strtoupper( $signed['sign_id'] ) !== $signed['sign_id'] ? strtoupper( $signed['sign_id'] ) : 'A'. substr( $signed['sign_id'], 1 ) ), 'sign-state=signed&sign='. $signed['sign_id']. 'a', 'sign-state=signed&sign=..%2F..%2Fx',
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
	&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', $wordsDe ) ) !== 1 && count( $wordsDe ) === 291 && array_filter( array_keys( $wordsDe ), static fn( string $key ): bool => preg_match( '~^\[\[/project/crm-(documents/(category|state|list)|portal/[a-z]+(/[a-z]+)?(/[a-z_]+)?)/?[a-z-]*\]\]$~D', $key ) !== 1 ) === [] );
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
$made = static function( string $title, string $client, array $over = [], bool $download = true ) use ( &$appData ): string {
	$element = \Nino\Elements::insertElement( $appData, '/projekte/', [ 'title' => $title, 'client' => $client ] + $over, '*' );
	$id = is_array( $element ) === true ? \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ) : '';

	// The files of a project are for download unless a section says otherwise: the owner switches the download on
	if( $id !== '' && $download === true )
		\Crm\Projekte::setDownload( $appData, $id, true, 'owner@example.org' );

	return $id;
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

// While an offer of a project waits the files are not open. The sections that read the files of the projects hold their offers back for their length - what the offers are is
// kept, and put back with $resume()
$held = [];
$hold = static function() use ( &$appData, &$held ): void {
	foreach( Db::rows( $appData, 'SELECT id, state FROM documents WHERE project <> \'\' AND category = \'offer\' AND state IN ( \'open\', \'sent\' )' ) as $row )
		$held[(int) $row['id']] = (string) $row['state'];

	foreach( array_keys( $held ) as $id )
		Db::exec( $appData, 'UPDATE documents SET state = \'declined\' WHERE id = :id', [ 'id' => $id ] );
};
$resume = static function() use ( &$appData, &$held ): void {
	foreach( $held as $id => $was )
		Db::exec( $appData, 'UPDATE documents SET state = :state WHERE id = :id', [ 'state' => $was, 'id' => $id ] );

	$held = [];
};

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

// The offers of the first project wait for the order from here on, and the files of the project would not be open: they are held back until a section needs them
$hold();

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
check( 'the head draws the bar of the application first, a header before the navigation: the name of the site as a link to the start page with the h1 of the page in it, the account as a chip that links to Meine Daten (the initials of the name - Anna Nord is AN - and the name), and Abmelden as an icon button in a form that posts to /.members/logout', count( portalFind( $bar, '//header[@class="crm-bar"]' ) ) === 1 && strpos( $bar, 'class="crm-bar"' ) < strpos( $bar, 'class="crm-nav' ) && strpos( $bar, 'class="crm-nav' ) < strpos( $bar, 'class="crm-head"' )
	&& count( portalFind( $bar, $barNode. '/a[@class="crm-bar__brand"][@href="/"]/h1[contains(@class, "nino-sr-only")]' ) ) === 1 && count( portalFind( $bar, '//h1' ) ) === 1 && count( portalFind( $bar, $barNode. '/a[contains(@class, "crm-account")][@href="/portal/meine-daten"]' ) ) === 1
	&& portalText( $bar, $barNode. '/a[contains(@class, "crm-account")]/span[@class="crm-account__avatar"][@aria-hidden="true"]' ) === 'AN' && portalText( $bar, $barNode. '/a[contains(@class, "crm-account")]//span[@class="crm-account__name"]' ) === 'Anna Nord'
	&& count( portalFind( $bar, $barNode. '/a[@class="crm-account"]/*' ) ) === 2 && portalFind( $bar, '//*[contains(@class, "crm-account__company") or contains(@class, "crm-account__text")]' ) === []
	&& count( portalFind( $bar, $barNode. '/form[@class="crm-account__out"][@method="post"][@action="/.members/logout"]/button[@type="submit"][@class="crm-account__logout"]/svg[@aria-hidden="true"]' ) ) === 1 && portalText( $bar, $barNode. '/form/button[@class="crm-account__logout"]/span[contains(@class, "nino-sr-only")]' ) !== ''
	&& count( portalFind( $bar, $barNode. '/form/input[@type="hidden"][@name="_csrf"]' ) ) === 1 && portalFind( $bar, '//a[contains(@href, "logout")]' ) === [] );
check( '...the navigation and the head of the page hold no chip and no Abmelden - the account is the bar\'s on every width', portalFind( $bar, '//nav[contains(@class, "crm-nav")]//a[contains(@class, "crm-account")] | //nav[contains(@class, "crm-nav")]//form[contains(@action, "logout")] | //div[@class="crm-head"]//a[contains(@class, "crm-account")] | //div[@class="crm-head"]//form[contains(@action, "logout")]' ) === []
	&& count( portalFind( $bar, '//form[contains(@action, "logout")]' ) ) === 1 && count( portalFind( $bar, '//a[@href="/portal/meine-daten"]' ) ) === 2 );
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

$resume();
$page = portalPage( $appData, '/portal/projekte?projekt='. $p1 );
$html = $page[1];
$head = portalFind( $html, '//article[contains(@class, "crm-project")]' );
check( 'the page of a project is the page, not the list: one article for the project, the list is gone', $page[0] === 200 && count( $head ) === 1 && $head[0]->getAttribute( 'data-project' ) === $p1 && $head[0]->getAttribute( 'data-state' ) === 'open' && portalFind( $html, '//ul[contains(concat(" ", normalize-space(@class), " "), " crm-projects__list ")]' ) === [] );
portalHeadChecks( $appData, $html, $p2 );
$sections = portalFind( $html, '//section[contains(@class, "crm-project__section")]' );
check( '...the head is no <header>: the stylesheet of the kernel draws every header of the page as the head of the site, so no component of the portal writes one (nor a footer or a main)', portalFind( $html, '//article//header | //article//footer | //article//main | //div[contains(@class, "crm-head")]//header' ) === [] && portalFind( $list, '//section[contains(@class, "crm-projects")]//header | //section[contains(@class, "crm-projects")]//footer' ) === [] );
check( '...then four tabs with their sections, each with a heading: Angebot, Dateien, Belege, Übersicht - and no Upload: the upload is global, there is no section, no tab and no link to it in the project', array_map( static fn( \DOMElement $section ): string => preg_replace( '/^.*crm-project__section--(\w+).*$/', '$1', $section->getAttribute( 'class' ) ), $sections ) === [ 'offer', 'files', 'documents', 'overview' ]
	&& array_filter( $sections, static fn( \DOMElement $section ): bool => $section->getElementsByTagName( 'h3' )->length < 1 || trim( $section->getElementsByTagName( 'h3' )->item( 0 )->textContent ) === '' || $section->getElementsByTagName( 'h4' )->length !== 0 || $section->getElementsByTagName( 'h5' )->length !== 0 ) === [] && portalLeft( $html ) === false
	&& array_map( static fn( \DOMElement $tab ): string => $tab->getAttribute( 'data-tab' ), portalFind( $html, '//article//a[contains(@class, "crm-project__tab")]' ) ) === [ 'angebot', 'dateien', 'belege', 'uebersicht' ]
	&& portalFind( $html, '//article//*[contains(@class, "--upload")]' ) === [] && portalFind( $html, '//article//a[contains(@href, "/portal/upload")]' ) === [] );

// the offers: orderable ones open, the rest folded to a line
$offerQuery = '//section[contains(@class, "crm-project__section--offer")]//details[contains(concat(" ", normalize-space(@class), " "), " crm-offer ")]';
$offers = portalFind( $html, $offerQuery );
check( 'Angebot: the released offers of the project, newest first - not the one that is not released, not the offer of another project, not another client\'s - each a card that folds', array_map( static fn( \DOMElement $offer ): string => $offer->getAttribute( 'data-document' ), $offers ) === [ (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ]
	&& array_filter( $offers, static fn( \DOMElement $offer ): bool => count( array_filter( iterator_to_array( $offer->childNodes ), static fn( \DOMNode $node ): bool => $node->nodeName === 'summary' ) ) !== 1 || $offer->getAttribute( 'id' ) !== 'angebot-'. $offer->getAttribute( 'data-document' ) ) === []
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
	&& portalFind( $offerHtml, '//summary/a[contains(@class, "crm-offer__download")]/@href' )[0]->nodeValue === '/.crm/pdf/'. $offerRegular['id']. '?download=1' && count( portalFind( portalSection( $html, 'offer' ), '//span[@class="crm-offer__valid"]' ) ) === 3 );
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
check( '...the form of the order stands under the offers that can be ordered and under no other: as many forms in the section as there are offers open or sent that have not run out (the order itself is pinned further down)', count( portalFind( $html, '//section[contains(@class, "crm-project__section--offer")]//form[contains(@class, "crm-order--form")]' ) )
	=== count( array_filter( [ $offerRegular, $offerReverse, $offerSmall ], static fn( array $offer ): bool => Documents::orderable( (array) Documents::get( $appData, $offer['id'] ) ) === true ) ) );

// the files
$hold();
$html = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
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
check( 'with Review off there is no link that listens, no column for a player, and a file asked for with ?review= is not played', portalFind( $noReview, '//aside[contains(@class, "crm-player")]' ) === [] && portalFind( $noReview, '//a[contains(@class, "crm-files__listen")]' ) === [] && portalFind( $noReview, '//div[contains(@class, "review-player")]' ) === [] && count( portalFind( $noReview, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === 4 );
\Nino\Features::activate( $appData, 'review' );

$played = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1];
check( 'with ?review=<file> the player of Review stands beside the list (after it in the markup, over it on a narrow screen): the audio element with the file as its source, and the list is still there', count( portalFind( $played, '//section[contains(@class, "crm-project__section--files")]//div[contains(@class, "review-player")]' ) ) === 1
	&& portalFind( $played, '//audio/@src | //audio/source/@src' )[0]->nodeValue === '/.files/'. $song['id'] && count( portalFind( $played, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === 4
	&& count( portalFind( $played, '//ul[contains(concat(" ", normalize-space(@class), " "), " crm-files__list ")]/following::div[contains(@class, "review-player")]' ) ) === 1 );
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
portalTabChecks( $appData, $html, $noFolder, $filesOff, $p1, $p5, $song, $wordsOfPortal, $token );

// the look: the classes the templates carry, and a sheet that takes every value from the kernel's roles or from its own scales
$cardsOf = static fn( string $page, string $query ): int => count( portalFind( $page, $query ) );
check( 'the cards are .crm-card: the offers (details), the lists of files and of documents (a table that is as long as it is), the projects (links)', $cardsOf( $html, '//details['. $token( 'crm-offer' ). '][not('. $token( 'crm-card' ). ')]' ) === 0 && $cardsOf( $html, '//details['. $token( 'crm-offer' ). ']['. $token( 'crm-card' ). ']' ) === 3
	&& $cardsOf( $html, '//div['. $token( 'crm-table' ). ']['. $token( 'crm-card' ). ']/ul['. $token( 'crm-files__list' ). ']' ) === 1 && $cardsOf( $html, '//div['. $token( 'crm-table' ). ']['. $token( 'crm-card' ). ']/ul['. $token( 'crm-documents__list' ). ']' ) === 1
	&& $cardsOf( $list, '//li['. $token( 'crm-projects__row' ). ']/a['. $token( 'crm-card' ). ']['. $token( 'crm-card--link' ). ']' ) === 3 && $cardsOf( $list, '//li['. $token( 'crm-projects__row' ). '][not(a)]' ) === 0 );
check( '...and the states are .crm-pill, with the tone of the state in the class: the state of a project, of an offer and of a document, and the mark "neu" of a file - and no .crm-chip is left anywhere', $cardsOf( $html, '//span['. $token( 'crm-head__state' ). ']['. $token( 'crm-pill' ). ']' ) === 1 && $cardsOf( $html, '//span['. $token( 'crm-offer__state' ). '][not('. $token( 'crm-pill' ). ')]' ) === 0 && $cardsOf( $html, '//span['. $token( 'crm-offer__state' ). ']['. $token( 'crm-pill' ). ']' ) === 3
	&& $cardsOf( $html, '//span['. $token( 'crm-documents__state' ). ']/span[not('. $token( 'crm-pill' ). ')]' ) === 0 && $cardsOf( $html, '//span['. $token( 'crm-files__new' ). '][not('. $token( 'crm-pill' ). ')]' ) === 0
	&& $cardsOf( $html, '//span[contains(@class, "crm-pill")][not(contains(@class, "crm-pill--"))]' ) === 0 && str_contains( $html, 'crm-chip' ) === false );
$sheet = (string) file_get_contents( dirname( __DIR__, 3 ). '/app/Crm/Portal/assets/portal.css' );
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
	&& preg_match( '/a\.crm-account\[aria-current="page"\] \{/', $shell ) === 1 && preg_match( '/button\.crm-account__logout \{[^}]*width:\s*var\(--crm-touch\);\s*height:\s*var\(--crm-touch\);/', $shell ) === 1
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
check( '...the rows of the lists are one grid from 1024px (the body is the grid, a row and its line a subgrid), below it a row stacks as a card - and the one container query is the list of files beside the player, whose rows stack where its column is narrower than 640px -, and the sheet keeps the one literal of the pill, said in a comment', preg_match( '/@media \(min-width: 1024px\)\s*\{\s*\.crm-table__body\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*var\(--crm-cols\)[^@]*\.crm-table__row,\s*\.crm-table__fold\s*\{[^}]*grid-template-columns:\s*subgrid/s', $sheet ) === 1 && substr_count( $sheet, '@container' ) === 1 && preg_match( '/\.crm-files-layout__list\s*\{[^}]*container-type:\s*inline-size/s', $sheet ) === 1 && preg_match( '/@container \(max-width: 639\.98px\)\s*\{.*?\.crm-files \.crm-table__body\s*\{\s*display:\s*block;.*?\.crm-files \.crm-files__download > span:not\(\.nino-sr-only\)\s*\{\s*position:\s*static;/s', $sheet ) === 1
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
	&& preg_match( '/\.crm-pay__fact--iban dd,[^{]*\{[^}]*font-family:\s*var\(--fontfamily-code\)/s', $sheet ) === 1 && preg_match( '/\.crm-facts > \.crm-table__cell::before \{\s*content:\s*"·";/', $sheet ) === 1 && preg_match( '/::after \{[^}]*content:\s*"·"/', $sheet ) !== 1 );
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

// The module makes an upload folder with every client; the checks that follow are about a group that has none
foreach( [ $a, $b ] as $withFolder )
	$store::markUpload( $appData, (string) ( \Crm\Clients::uploadFolder( $appData, $withFolder )['id'] ?? '' ), false );

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

$portalSheet = (string) file_get_contents( dirname( __DIR__, 3 ). '/app/Crm/Portal/assets/portal.css' );
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
$inputs = portalFind( $data, '//form[@class="crm-data__form"]//input[@name]' );
$values = array_column( array_map( static fn( \DOMElement $input ): array => [ $input->getAttribute( 'name' ), $input->getAttribute( 'value' ) ], $inputs ), 1, 0 );
check( 'the form posts to /.crm/daten and has the fields Name, Firma, Straße, PLZ, Ort, Land (a select), Telefon, USt-IdNr. with the values of the client - and the csrf token', portalFind( $data, '//form[@class="crm-data__form"]/@action' )[0]->nodeValue === '/.crm/daten' && portalFind( $data, '//form[@class="crm-data__form"]/@method' )[0]->nodeValue === 'post'
	&& array_keys( $values ) === [ '_csrf', 'name', 'company', 'street', 'zip', 'city', 'phone', 'vat_id' ] && $values['_csrf'] === \Nino\Csrf::getToken( $appData )
	&& array_diff_key( $values, [ '_csrf' => 1 ] ) === array_replace( array_flip( [ 'name', 'company', 'street', 'zip', 'city', 'phone', 'vat_id' ] ), array_intersect_key( $client, array_flip( [ 'company', 'name', 'street', 'zip', 'city', 'phone', 'vat_id' ] ) ) )
	&& portalFind( $data, '//form//select[@name="country"]/option[@selected]/@value' )[0]->nodeValue === $client['country'] && portalFind( $data, '//form//select[@name="country"]/option[@value="DE"]' ) !== [] );
check( '...every value is text: markup and a shortcode in the company are escaped, and each field has its label and the length the client takes', str_contains( $data, 'Nord &quot;A&amp;B&quot; &lt;x&gt; &#91;members-name]' ) === true && str_contains( $data, '<x>' ) === false
	&& count( portalFind( $data, '//form[@class="crm-data__form"]//label[@for]' ) ) === 8 &&  portalFind( $data, '//input[@name="company"]/@maxlength' )[0]->nodeValue === '120' && portalFind( $data, '//input[@name="phone"]/@type' )[0]->nodeValue === 'tel' );
check( '...the e-mail of the account is no field of this form: the address it signs in with stands as text in the block Anmeldeadresse, and the form has no input that is read only, none that is an address and none that is not one of its eight fields', portalFind( $data, '//form[@class="crm-data__form"]//input[@readonly or @type="email"]' ) === []
	&& portalText( $data, '//section[contains(@class, "crm-login")]//strong[@class="crm-login__address"]' ) === 'anna@example.org' && portalFind( $data, '//form[@class="crm-data__form"]//button[@type="submit"]' ) !== [] );
check( '...the form is a plain form (no .nino-form: the kernel\'s script would post it as a contact form), every input of it the kernel\'s .nino-form-input or .nino-form-select, the fields stand in one .crm-card, and the button is the portal\'s primary one (.crm-btn)', count( portalFind( $data, '//form[contains(concat(" ", normalize-space(@class), " "), " crm-data__form ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-form "))]' ) ) === 1
	&& count( portalFind( $data, '//form[@class="crm-data__form"]//input[not(@type="hidden")]' ) ) === 7 && count( portalFind( $data, '//form[@class="crm-data__form"]//input[not(@type="hidden")][contains(concat(" ", normalize-space(@class), " "), " nino-form-input ")]' ) ) === 7 && count( portalFind( $data, '//form[@class="crm-data__form"]//select[contains(concat(" ", normalize-space(@class), " "), " nino-form-select ")]' ) ) === 1
	&& count( portalFind( $data, '//form//div[contains(concat(" ", normalize-space(@class), " "), " crm-data__card ")][contains(concat(" ", normalize-space(@class), " "), " crm-card ")]/div[contains(concat(" ", normalize-space(@class), " "), " crm-fields ")]//input[contains(concat(" ", normalize-space(@class), " "), " nino-form-input ")]' ) ) === 7
	&& count( portalFind( $data, '//form[@class="crm-data__form"]//button[@type="submit"][contains(concat(" ", normalize-space(@class), " "), " crm-btn ")][contains(concat(" ", normalize-space(@class), " "), " crm-btn--primary ")][not(contains(concat(" ", normalize-space(@class), " "), " nino-btn "))]' ) ) === 1 );
check( '...no state and no error before anything was posted', portalFind( $data, '//*[contains(@class, "crm-data__state") or contains(@class, "crm-data__error")]' ) === [] && portalFind( $data, '//*[@aria-invalid]' ) === [] );
$saved = portalPage( $appData, '/portal/meine-daten?state=saved' )[1];
$expired = portalPage( $appData, '/portal/meine-daten?state=expired' )[1];
$invalid = portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip' )[1];
check( 'the state of the query is said above the form: saved as a status in the success tone, expired and invalid as an alert in the error tone - the invalid one names the field - and the field says it beside itself, marked invalid and described by its sentence', portalFind( $saved, '//p[@role="status"][contains(@class, "crm-data__state--saved")][contains(@class, "crm-note--success")]' ) !== [] && portalFind( $expired, '//p[@role="alert"][contains(@class, "crm-data__state--expired")][contains(@class, "crm-note--error")]' ) !== []
	&& portalText( $saved, '//p[contains(@class, "crm-data__state")]' ) !== portalText( $expired, '//p[contains(@class, "crm-data__state")]' ) && count( portalFind( $invalid, '//p[@role="alert"][contains(@class, "crm-data__state--invalid")]' ) ) === 1
	&& str_contains( portalText( $invalid, '//p[@role="alert"]' ), portalText( $invalid, '//label[@for="crm-data-zip"]/text()' ) ) === true && portalFind( $invalid, '//input[@name="zip"]/@aria-invalid' )[0]->nodeValue === 'true' && portalFind( $invalid, '//input[@name="zip"]/@aria-describedby' )[0]->nodeValue === 'crm-data-zip-error'
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
	'a zip that is no zip'              => [ [ 'zip' => 'a!b' ], 'zip', 'format' ],
	'a city that is too long'           => [ [ 'city' => str_repeat( 'x', 81 ) ], 'city', 'long' ],
	'a country of three letters'        => [ [ 'country' => 'DEU' ], 'country', 'long' ],
	'a country that is no letters'      => [ [ 'country' => '1!' ], 'country', 'format' ],
	'a phone number that is none'       => [ [ 'phone' => 'abc' ], 'phone', 'format' ],
	'a VAT id that is none'             => [ [ 'vat_id' => 'x' ], 'vat_id', 'format' ],
	'a street with a control character' => [ [ 'street' => "Weg\x07 1" ], 'street', 'format' ],
	'a name with a null byte'           => [ [ 'name' => "Mi\x00a" ], 'name', 'format' ],
	'a city that is no UTF-8'           => [ [ 'city' => "Ole\xFF\xFE" ], 'city', 'format' ],
	'a company with a line break'       => [ [ 'company' => "A\nB" ], 'company', 'format' ],
	'a country nobody offers'           => [ [ 'country' => 'XX' ], 'country', 'format' ],
	'a street that is a list'           => [ [ 'street' => [ 'a', 'b' ] ], 'street', 'required' ],
	'neither a company nor a name'      => [ [ 'company' => ' ', 'name' => '' ], 'name', 'name' ],
];

foreach( $refusals as $what => [ $over, $field, $why ] ) {
	$answer = portalServe( $appData, '/.crm/daten', 'POST', $fields( $over ) );
	check( 'refused - '. $what. ': the redirect names the field ('. $field. ') and the reason ('. $why. '), nothing is saved, no mail', $answer['statusCode'] === 303 && $location( $answer ) === '/portal/meine-daten?state=invalid&field='. $field. '&why='. $why
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
	&& array_diff_key( array_diff_assoc( $after, $client ), [ 'updated' => 1, 'title' => 1 ] ) === [ 'company' => 'Nord & Söhne <GmbH>', 'city' => 'Bremen', 'country' => 'AT' ] );
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

// --- The block Anmeldeadresse: the address the account signs in with, changed with a code to the new one ---------------------------------

echo "\nThe block Anmeldeadresse, POST /.crm/anmeldeadresse and POST /.crm/anmeldeadresse/code\n";

$lgFresh = static function() use ( &$appData ): void {
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
};
$lgCode = static function( int $index ): string {
	$body = (string) ( portalMail( $index )['body'] ?? '' );

	return preg_match( '~(\d{3}) (\d{3})~', strip_tags( $body ), $found ) === 1 ? $found[1]. $found[2] : '';
};
$lgBlock = static fn( string $html, string $query ): array => portalFind( $html, '//section[contains(concat(" ", normalize-space(@class), " "), " crm-login ")]'. $query );
$lgStep = static function( string $query = '' ) use ( &$appData ): string {
	return portalPage( $appData, '/portal/meine-daten'. $query )[1];
};
$lgTrunk = [ '/.crm/anmeldeadresse', '/.crm/anmeldeadresse/code' ];
$lgPersons = [ 'visitor' => $asVisitor, 'stray' => static function() use ( &$appData, $stray ): void { portalSignIn( $appData, $stray ); } ];
$lgMembers = count( \Nino\Modules\Members::accounts( $appData ) );
$lgClientBefore = (array) \Crm\Clients::get( $appData, $a['id'] );
$lgAnswers = [];
$lgMails = 0;

foreach( $lgPersons as $who => $become ) {
	$become();
	$lgMails = count( $mails );
	foreach( $lgTrunk as $route ) {
		$lgAnswers[$who. ' '. $route] = portalServe( $appData, $route, 'POST', [ 'email' => 'any@example.org', 'code' => '123456' ] )['statusCode'];
		$lgAnswers[$who. ' '. $route. ' (get)'] = portalServe( $appData, $route )['statusCode'];
	}
}

check( 'a visitor and a member of no client are answered 404 by both routes, by post and by get - nothing is mailed, nothing is kept', array_values( array_unique( $lgAnswers ) ) === [ 404 ] && count( array_filter( array_keys( $lgAnswers ), static fn( string $key ): bool => str_ends_with( $key, '(get)' ) ) ) === 4 && count( $mails ) === $lgMails
	&& \Nino\Modules\Members::account( $appData, $stray['id'] )['email'] === 'stray@example.org' && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null );
check( '...and the page of a member of no client has no block', $lgBlock( portalPage( $appData, '/portal/meine-daten' )[1], '' ) === [] );

// The first step
$lgFresh();
portalSignIn( $appData, $accounts['anna'] );
$lgFresh();
$lgOne = $_SESSION;
$step1 = $lgStep();
$lgSection = $lgBlock( $step1, '' );
check( 'under the form stands the block Anmeldeadresse: a card of its own with a heading, the address the account signs in with as text - not a field -, and the way to change it, closed: a details whose button opens the form for the new address',
	count( $lgSection ) === 1 && portalText( $step1, '//section[contains(@class, "crm-login")]//strong[@class="crm-login__address"]' ) === 'anna@example.org' && portalFind( $step1, '//section[contains(@class, "crm-login")]//h3[@id="crm-login-heading"]' ) !== [] && portalFind( $step1, '//section[@aria-labelledby="crm-login-heading"]' ) !== []
	&& portalFind( $step1, '//section[contains(@class, "crm-login")]//details[contains(@class, "crm-login__fold")][not(@open)]/summary[contains(@class, "crm-btn")]' ) !== [] && strpos( $step1, 'crm-data__form' ) < strpos( $step1, 'crm-login' ) && portalFind( $step1, '//section[contains(@class, "crm-login")]//*[@readonly]' ) === [] );
check( '...the form of the first step posts to /.crm/anmeldeadresse with the csrf token of the session and one field, the new address: type email, required, named email, with its sentence, and the button; no field for a code is there',
	portalFind( $step1, '//details[contains(@class, "crm-login__fold")]//form/@action' )[0]->nodeValue === '/.crm/anmeldeadresse' && portalFind( $step1, '//details//form/@method' )[0]->nodeValue === 'post' && portalFind( $step1, '//details//form//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData )
	&& count( portalFind( $step1, '//details//form//input[not(@type="hidden")]' ) ) === 1 && portalFind( $step1, '//details//form//input[@name="email"][@type="email"][@required][@maxlength="254"][@autocomplete="email"]' ) !== []
	&& portalFind( $step1, '//details//form//input[@name="email"]/@aria-describedby' )[0]->nodeValue === 'crm-login-hint' && portalText( $step1, '//span[@id="crm-login-hint"]' ) !== '' && portalFind( $step1, '//details//form//button[@type="submit"][contains(@class, "crm-btn--primary")]' ) !== []
	&& portalFind( $step1, '//input[@name="code"]' ) === [] && portalFind( $step1, '//section[contains(@class, "crm-login")]//*[contains(@class, "crm-data__state")]' ) === [] );
check( '...the form of the data has no field for the sign-in address: the post of the data form does not carry it, whatever it is sent', ( function() use ( &$appData, $accounts, $fields, $a ): bool {
	$now = (array) \Crm\Clients::get( $appData, $a['id'] );
	portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'email' => 'sneaked@example.org', 'login' => '', 'login_email' => 'sneaked@example.org' ] + array_intersect_key( $now, array_flip( [ 'company', 'name', 'street', 'zip', 'city', 'country', 'phone', 'vat_id' ] ) ) ) );
	return \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna@example.org' && count( array_filter( (array) \Nino\Filesystem::getFileContent( $appData, '/data/members-tokens.php', [] ), static fn( array $entry ): bool => ( $entry['account'] ?? '' ) === $accounts['anna']['id'] ) ) === 0;
} )() );

// The refusals of the first step
$lgBefore = count( $mails );
$lgFile = (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/members.php' ) );
$lgBad = [];

foreach( [ 'none' => '', 'text' => 'nope', 'markup' => 'a<b>@example.org', 'a null byte' => "a\x00b@example.org", 'spaces only' => '   ' ] as $kind => $typed )
	$lgBad[$kind] = $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => $typed ] ) );

$lgBad['a list'] = $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => [ 'a@example.org' ] ] ) );
$lgBad['no field'] = $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST' ) );
check( 'an address that is none - empty, text, markup, a null byte, spaces, a list, no field at all - is the redirect ?state=invalid&field=login, and nothing is mailed, written or kept in the session', array_values( array_unique( $lgBad ) ) === [ '/portal/meine-daten?state=invalid&field=login' ] && count( $mails ) === $lgBefore
	&& (string) file_get_contents( \Nino\Filesystem::path( $appData, '/data/members.php' ) ) === $lgFile && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null );
$lgInvalid = $lgStep( '?state=invalid&field=login' );
check( '...the block says it as an alert in the error tone, opens the form and marks the field invalid, described by its sentence - and the form above says nothing', portalFind( $lgInvalid, '//section[contains(@class, "crm-login")]//p[@role="alert"][contains(@class, "crm-data__state--login-invalid")][contains(@class, "crm-note--error")]' ) !== []
	&& portalFind( $lgInvalid, '//section[contains(@class, "crm-login")]//details[@open]' ) !== [] && portalFind( $lgInvalid, '//input[@name="email"]/@aria-invalid' )[0]->nodeValue === 'true' && portalFind( $lgInvalid, '//form[@class="crm-data__form"]//*[contains(@class, "crm-data__state")]' ) === [] && portalFind( $lgInvalid, '//form[@class="crm-data__form"]/p[contains(@class, "crm-data__state")]' ) === [] );
$lgSame = $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => ' ANNA@Example.org ' ] ) );
check( 'the address the account has - in any case - is ?state=same: said in the block as a status, nothing is mailed or kept', $lgSame === '/portal/meine-daten?state=same' && count( $mails ) === $lgBefore && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null
	&& portalFind( $lgStep( '?state=same' ), '//section[contains(@class, "crm-login")]//p[@role="status"][contains(@class, "crm-data__state--same")]' ) !== [] );
$lgExpired = [ portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'late@example.org', '_csrf' => 'wrong' ] ), portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'late@example.org', '_csrf' => '' ] ) ];
check( 'a token that is not the session\'s is the redirect ?state=expired&login=change, and the page says it in the block with the form open; nothing is mailed or kept', array_map( $location, $lgExpired ) === [ '/portal/meine-daten?state=expired&login=change', '/portal/meine-daten?state=expired&login=change' ] && array_column( $lgExpired, 'statusCode' ) === [ 303, 303 ]
	&& count( $mails ) === $lgBefore && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null && portalFind( $lgStep( '?state=expired&login=change' ), '//section[contains(@class, "crm-login")]//p[@role="alert"][contains(@class, "crm-data__state--login-expired")]' ) !== []
	&& portalFind( $lgStep( '?state=expired&login=change' ), '//section[contains(@class, "crm-login")]//details[@open]' ) !== [] && portalFind( $lgStep( '?state=expired' ), '//form[@class="crm-data__form"]/p[contains(@class, "crm-data__state--expired")]' ) !== [] );

// The second step
$lgSent = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => ' Anna.New@Example.org ' ] );
$lgMail = portalMailsFrom( $lgBefore );
check( 'a free address is sent: the redirect ?login=code, and one mail goes to the new address - six digits, no link - and nowhere else', $lgSent['statusCode'] === 303 && $location( $lgSent ) === '/portal/meine-daten?login=code' && count( $lgMail ) === 1 && $lgMail[0]['to'] === 'anna.new@example.org'
	&& $lgCode( $lgBefore ) !== '' && preg_match( '~^\d{6}$~D', $lgCode( $lgBefore ) ) === 1 && str_contains( (string) $lgMail[0]['body'], '.members/login' ) === false && str_contains( (string) $lgMail[0]['body'], 'href=' ) === false );
$step2 = $lgStep( '?login=code' );
check( 'the second step names the new address as text, from the session, and asks for the code: a form to /.crm/anmeldeadresse/code with the csrf token, one field - text, inputmode numeric, one-time-code, autofocus, required, named code - and the button; the first step is gone',
	portalText( $step2, '//section[contains(@class, "crm-login")]//form//strong[@class="crm-login__address"]' ) === 'anna.new@example.org' && portalText( $step2, '//section[contains(@class, "crm-login")]/p/strong' ) === 'anna@example.org' && portalFind( $step2, '//section[contains(@class, "crm-login")]//form/@action' )[0]->nodeValue === '/.crm/anmeldeadresse/code'
	&& portalFind( $step2, '//section[contains(@class, "crm-login")]//form//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && count( portalFind( $step2, '//section[contains(@class, "crm-login")]//form//input[not(@type="hidden")]' ) ) === 1
	&& portalFind( $step2, '//input[@name="code"][@type="text"][@inputmode="numeric"][@autocomplete="one-time-code"][@autofocus][@required]' ) !== [] && portalFind( $step2, '//section[contains(@class, "crm-login")]//form//button[@type="submit"][contains(@class, "crm-btn--primary")]' ) !== []
	&& portalFind( $step2, '//details[contains(@class, "crm-login__fold")]' ) === [] && portalFind( $step2, '//input[@name="email"]' ) === [] && portalFind( $step2, '//section[contains(@class, "crm-login")]//a[contains(@class, "crm-btn")][@href="/portal/meine-daten"]' ) !== [] );
check( '...the address of the second step is the session\'s and never the address bar\'s: a query that names another one changes nothing, and the address of a request that ran out is not shown', portalText( $lgStep( '?login=code&email=evil%40example.org&new=evil%40example.org' ), '//section[contains(@class, "crm-login")]//form//strong[@class="crm-login__address"]' ) === 'anna.new@example.org'
	&& str_contains( $lgStep( '?login=code&email=evil%40example.org' ), 'evil' ) === false );

// Someone else's browser has no request: the second step is the first, with gone
$lgBrowserOne = $_SESSION;
portalSignIn( $appData, $accounts['carl'] );
$lgCarl = $lgStep( '?login=code' );
$lgGone = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCode( $lgBefore ) ] );
check( 'a member in another browser, with no request of his own, has the first step with the state gone for ?login=code and ?state=wrong, and a post of the code that was sent to the other one is ?state=gone - the account of the first is not changed', portalFind( $lgCarl, '//section[contains(@class, "crm-login")]//details[@open]' ) !== [] && portalFind( $lgCarl, '//input[@name="code"]' ) === []
	&& portalFind( $lgCarl, '//section[contains(@class, "crm-login")]//p[@role="alert"][contains(@class, "crm-data__state--gone")]' ) !== [] && portalFind( $lgStep( '?state=wrong&login=code' ), '//p[contains(@class, "crm-data__state--gone")]' ) !== []
	&& $location( $lgGone ) === '/portal/meine-daten?state=gone' && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna@example.org' && \Nino\Modules\Members::account( $appData, $accounts['carl']['id'] )['email'] === 'carl@example.org' );
$_SESSION = $lgBrowserOne;
check( '...and in the browser that asked, the request is still there and the code still works', \Nino\Modules\Members::changePending( $appData, (array) \Nino\Modules\Members::current( $appData ) ) === 'anna.new@example.org' );

// A browser of the account that is open besides
$_SESSION = [];
portalSignIn( $appData, $accounts['anna'] );
$lgBrowserTwo = $_SESSION;
$_SESSION = $lgBrowserOne;
$lgWrong = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => '000000' === $lgCode( $lgBefore ) ? '111111' : '000000' ] );
$lgWrongPage = $lgStep( '?state=wrong&login=code' );
check( 'a wrong code is ?state=wrong&login=code: the second step again, the field marked invalid and described, an alert in the error tone in the block - and the address is as it was', $location( $lgWrong ) === '/portal/meine-daten?state=wrong&login=code' && portalFind( $lgWrongPage, '//input[@name="code"]/@aria-invalid' )[0]->nodeValue === 'true'
	&& portalFind( $lgWrongPage, '//section[contains(@class, "crm-login")]//p[@role="alert"][contains(@class, "crm-data__state--wrong")][contains(@class, "crm-note--error")]' ) !== [] && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna@example.org' );
check( '...a code that is none - text, seven digits, a null byte - is wrong as well, and each one is a try', array_values( array_unique( array_map( static fn( string $typed ): string => $location( portalServe( $GLOBALS['appData'], '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $typed ] ) ), [ 'abcdef', '1234567', "12\x00456" ] ) ) ) === [ '/portal/meine-daten?state=wrong&login=code' ] );
$lgFifth = $location( portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => [ '123456' ] ] ) );
check( '...so the fifth wrong try (a list is one) ends the request - gone, and the right code is gone after it - and the second step is the first again', $lgFifth === '/portal/meine-daten?state=gone' && $location( portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCode( $lgBefore ) ] ) ) === '/portal/meine-daten?state=gone'
	&& portalFind( $lgStep( '?login=code' ), '//input[@name="code"]' ) === [] && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna@example.org' );

// The token of the form
$lgFresh();
portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'anna.new@example.org' ] );
$lgIndex = count( $mails ) - 1;
$lgCodeNow = $lgCode( $lgIndex );
$lgLate = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCodeNow, '_csrf' => 'wrong' ] );
check( 'a token that is not the session\'s is ?login=code&state=expired for the second step, the right code is not taken, the address is as it was and the request stands', $location( $lgLate ) === '/portal/meine-daten?state=expired&login=code' && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna@example.org'
	&& \Nino\Modules\Members::changePending( $appData, (array) \Nino\Modules\Members::current( $appData ) ) === 'anna.new@example.org' && portalFind( $lgStep( '?state=expired&login=code' ), '//input[@name="code"]' ) !== [] && portalFind( $lgStep( '?state=expired&login=code' ), '//p[contains(@class, "crm-data__state--login-expired")]' ) !== [] );

// The right code
$lgOwnerBefore = count( $mails );
$lgDone = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => substr( $lgCodeNow, 0, 3 ). ' '. substr( $lgCodeNow, 3 ) ] );
$lgDoneMails = portalMailsFrom( $lgOwnerBefore );
$lgAnna = \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] );
check( 'the right code - with a space in it, as the mail sets it - is ?state=login-changed: the account signs in with the new address, the name and the group are as they were, and the request is over', $location( $lgDone ) === '/portal/meine-daten?state=login-changed' && $lgAnna['email'] === 'anna.new@example.org' && $lgAnna['name'] === 'Anna Nord' && $lgAnna['group'] === $a['group']
	&& \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null && ( \Nino\Modules\Members::current( $appData )['email'] ?? '' ) === 'anna.new@example.org' && \Crm\Clients::ofMember( $appData, $accounts['anna']['id'] )['id'] === $a['id'] );
$lgAfter = $lgStep( '?state=login-changed' );
check( '...the page says it as a status in the success tone in the block - and the new address stands there as text', portalFind( $lgAfter, '//section[contains(@class, "crm-login")]//p[@role="status"][contains(@class, "crm-data__state--login-changed")][contains(@class, "crm-note--success")]' ) !== [] && portalText( $lgAfter, '//section[contains(@class, "crm-login")]/p/strong' ) === 'anna.new@example.org'
	&& portalFind( $lgAfter, '//form[@class="crm-data__form"]/p[contains(@class, "crm-data__state")]' ) === [] );
$lgToOld = array_values( array_filter( $lgDoneMails, static fn( array $mail ): bool => $mail['to'] === 'anna@example.org' ) );
$lgToOwner = array_values( array_filter( $lgDoneMails, static fn( array $mail ): bool => $mail['to'] === 'owner@example.org' ) );
check( 'the old address is told, once, without the new address and without a code or a link; the owner is mailed once: the number and the company, a row Anmeldeadresse with the old address and the new one, and the reply goes to the account at the new address; nobody else is mailed',
	count( $lgDoneMails ) === 2 && count( $lgToOld ) === 1 && str_contains( (string) $lgToOld[0]['body'], 'anna.new' ) === false && str_contains( (string) $lgToOld[0]['body'], '.members/login' ) === false && preg_match( '~\d{3} \d{3}~', strip_tags( (string) $lgToOld[0]['body'] ) ) !== 1
	&& count( $lgToOwner ) === 1 && $lgToOwner[0]['replyTo'] === 'anna.new@example.org' && str_contains( (string) $lgToOwner[0]['body'], 'K-0001' ) === true && str_contains( (string) $lgToOwner[0]['body'], 'anna@example.org' ) === true && str_contains( (string) $lgToOwner[0]['body'], 'anna.new@example.org' ) === true
	&& count( portalFind( (string) $lgToOwner[0]['body'], '//tbody/tr' ) ) === 1 && str_contains( (string) $lgToOwner[0]['body'], '[[' ) === false );
$lgBrowserOne = $_SESSION;
$_SESSION = $lgBrowserTwo;
check( '...the other browser of the account is signed out, where the one that confirmed is not; a code the old address let in is dead', \Nino\Modules\Members::current( $appData ) === null );
$_SESSION = $lgBrowserOne;
check( '...and the browser that confirmed is still the member, with the same token of the form', ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $accounts['anna']['id'] && portalFind( $lgStep(), '//form[@class="crm-data__form"]//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) );
$lgAgain = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCodeNow ] );
check( 'the code is spent: posted again it is ?state=gone, and the address stays', $location( $lgAgain ) === '/portal/meine-daten?state=gone' && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna.new@example.org' );

// An address another account has: the same answers, and no code
$lgFresh();
$lgTakenBefore = count( $mails );
$lgFreeSent = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'free.again@example.org' ] );
$lgFreePage = $lgStep( '?login=code' );
$lgTakenSent = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => ' CARL@example.org ' ] );
$lgTakenPage = $lgStep( '?login=code' );
check( 'an address another account has - in any case - gets the same answer as a free one: the same redirect and the same page but for the address, the second step; the free one is mailed a code, the taken one nothing', $location( $lgTakenSent ) === $location( $lgFreeSent ) && $lgTakenSent['statusCode'] === $lgFreeSent['statusCode']
	&& str_replace( 'free.again@example.org', 'X', $lgFreePage ) === str_replace( 'carl@example.org', 'X', $lgTakenPage ) && count( $mails ) === $lgTakenBefore + 1 && portalMail( $lgTakenBefore )['to'] === 'free.again@example.org' );
$lgTakenTries = [];

for( $try = 0; $try < 5; $try++ )
	$lgTakenTries[] = $location( portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCode( $lgTakenBefore ) ] ) );

check( '...a code, even the one of the free address, does not change a taken one: four times wrong, the fifth gone, and the account keeps its address', $lgTakenTries === [ '/portal/meine-daten?state=wrong&login=code', '/portal/meine-daten?state=wrong&login=code', '/portal/meine-daten?state=wrong&login=code', '/portal/meine-daten?state=wrong&login=code', '/portal/meine-daten?state=gone' ]
	&& \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna.new@example.org' && \Nino\Modules\Members::account( $appData, $accounts['carl']['id'] )['email'] === 'carl@example.org' );
portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'race@example.org' ] );
$lgRaceCode = $lgCode( count( $mails ) - 1 );
\Nino\Modules\Members::change( $appData, $accounts['carl']['id'], [ 'email' => 'race@example.org' ] );
check( '...an address another account took after the code was sent is gone for the right code, and nothing is changed, deleted or told', $location( portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgRaceCode ] ) ) === '/portal/meine-daten?state=gone' && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'anna.new@example.org'
	&& \Nino\Modules\Members::account( $appData, $accounts['carl']['id'] )['email'] === 'race@example.org' );
\Nino\Modules\Members::change( $appData, $accounts['carl']['id'], [ 'email' => 'carl@example.org' ] );

// The caps
$lgFresh();
$lgLimits = [];

for( $round = 0; $round < 4; $round++ )
	$lgLimits[] = $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'many@example.org' ] ) );

check( 'the caps of Members count here: the fourth request for one address in an hour is ?state=limit, said in the block as an alert, and the request of the session stays the third\'s', $lgLimits === [ '/portal/meine-daten?login=code', '/portal/meine-daten?login=code', '/portal/meine-daten?login=code', '/portal/meine-daten?state=limit' ]
	&& portalFind( $lgStep( '?state=limit' ), '//section[contains(@class, "crm-login")]//p[@role="alert"][contains(@class, "crm-data__state--limit")]' ) !== [] && \Nino\Modules\Members::changePending( $appData, (array) \Nino\Modules\Members::current( $appData ) ) === 'many@example.org' );

// Markup, a bracket
$lgFresh();
$lgHostile = [];

foreach( [ 'a bracket' => '"a[members-name]"@example.org', 'markup in quotes' => '"<b>x</b>"@example.org' ] as $kind => $typed ) {
	$lgFresh();
	$sent = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => $typed ] );
	$page = $lgStep( '?login=code' );
	$mail = portalMail( count( $mails ) - 1 );
	$lgHostile[$kind] = $location( $sent ) === '/portal/meine-daten?login=code' && portalText( $page, '//section[contains(@class, "crm-login")]//form//strong' ) === $typed && str_contains( $page, '<b>x' ) === false && str_contains( $page, str_contains( $typed, '[' ) ? 'a&#91;members-name]' : '&lt;b&gt;x' ) === true && str_contains( $page, 'a[members-name]' ) === false
		&& str_contains( (string) $mail['body'], '<b>x' ) === false && str_contains( (string) $mail['body'], '[[' ) === false && preg_match( '~\d{3} \d{3}~', strip_tags( (string) $mail['body'] ) ) === 1;
}

check( 'an address that has a bracket or markup in it and is valid all the same is text wherever it is shown: in the second step the text of the strong is the address, the page has it escaped - `[` as &#91; - and the mail with the code has no markup and no shortcode of it', array_values( $lgHostile ) === [ true, true ] );
check( '...and the null byte, a bracket outside quotes, markup outside quotes and a list never get there - and the address bar cannot bring one in', $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => "a\x00b@example.org" ] ) ) === '/portal/meine-daten?state=invalid&field=login'
	&& $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'a[members-name]@example.org' ] ) ) === '/portal/meine-daten?state=invalid&field=login' && $location( portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'x<b>@example.org' ] ) ) === '/portal/meine-daten?state=invalid&field=login'
	&& str_contains( $lgStep( '?login=code&email=%3Cb%3Ex&state=%3Cb%3E' ), '<b>' ) === false );

// The box of the form
portalSignIn( $appData, (array) \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] ) );
$lgFresh();
$lgSetEmail = static function( string $email ) use ( &$appData, $a ): void {
	\Crm\Clients::change( $appData, $a['id'], [ 'email' => $email ] );
	\Crm\Portal::forget( $appData );
};
$lgSetEmail( 'Studio@Example.NET' );
$lgBox = $lgStep();
check( 'where the client\'s address is another than the account\'s the form has a box: unticked, named login, value 1, with the address beside it as text; where it is the same or none the box is not there',
	portalFind( $lgBox, '//form[@class="crm-data__form"]//input[@type="checkbox"][@name="login"][@value="1"][not(@checked)]' ) !== [] && portalText( $lgBox, '//form[@class="crm-data__form"]//strong[@class="crm-data__also-address"]' ) === 'Studio@Example.NET'
	&& portalFind( $lgBox, '//form[@class="crm-data__form"]//label[@for="crm-data-also"]' ) !== [] && portalFind( $lgBox, '//form[@class="crm-data__form"]//input[@id="crm-data-also"]' ) !== [] );
$lgSetEmail( 'ANNA.new@example.org' );
check( '...the same in another case: no box', portalFind( $lgStep(), '//input[@name="login"]' ) === [] );
$lgSetEmail( '' );
check( '...none: no box', portalFind( $lgStep(), '//input[@name="login"]' ) === [] );
$lgSetEmail( 'studio@example.net' );
$lgBase = (array) \Crm\Clients::get( $appData, $a['id'] );
$lgFields = $fields( [ 'company' => (string) $lgBase['company'], 'name' => (string) $lgBase['name'], 'street' => (string) $lgBase['street'], 'zip' => (string) $lgBase['zip'], 'city' => (string) $lgBase['city'], 'country' => (string) $lgBase['country'], 'phone' => (string) $lgBase['phone'], 'vat_id' => (string) $lgBase['vat_id'] ] );
$lgBeforeBox = count( $mails );
$lgNoBox = portalServe( $appData, '/.crm/daten', 'POST', $lgFields );
check( 'saved without the box: ?state=saved, as ever - no code, no request in the session', $location( $lgNoBox ) === '/portal/meine-daten?state=saved' && count( $mails ) === $lgBeforeBox && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null );
$lgBoxed = portalServe( $appData, '/.crm/daten', 'POST', array_replace( $lgFields, [ 'login' => '1', 'city' => 'Lübeck' ] ) );
$lgBoxMails = portalMailsFrom( $lgBeforeBox );
check( 'saved with the box: the data are saved first, then a code goes to the address of the client - ?state=saved&login=code, the second step under the note that it was saved - and the owner is mailed the data, not an address change yet', $location( $lgBoxed ) === '/portal/meine-daten?state=saved&login=code' && \Crm\Clients::get( $appData, $a['id'] )['city'] === 'Lübeck'
	&& count( $lgBoxMails ) === 2 && array_column( $lgBoxMails, 'to' ) === [ 'owner@example.org', 'studio@example.net' ] && preg_match( '~^\d{6}$~D', $lgCode( $lgBeforeBox + 1 ) ) === 1 && str_contains( (string) $lgBoxMails[0]['body'], 'Anmeldeadresse' ) === false );
$lgBoxPage = $lgStep( '?state=saved&login=code' );
check( '...the page shows the note that it was saved above the form and the second step in the block with the address of the client; the code of it signs the account in with that address', portalFind( $lgBoxPage, '//form[@class="crm-data__form"]/p[@role="status"][contains(@class, "crm-data__state--saved")]' ) !== [] && portalText( $lgBoxPage, '//section[contains(@class, "crm-login")]//form//strong[@class="crm-login__address"]' ) === 'studio@example.net'
	&& portalFind( $lgBoxPage, '//input[@name="code"]' ) !== [] );
$lgBoxDone = portalServe( $appData, '/.crm/anmeldeadresse/code', 'POST', [ 'code' => $lgCode( $lgBeforeBox + 1 ) ] );
check( '...and the address is the account\'s then; the box is gone as the two are the same', $location( $lgBoxDone ) === '/portal/meine-daten?state=login-changed' && \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['email'] === 'studio@example.net' && portalFind( $lgStep(), '//input[@name="login"]' ) === [] );
$lgFields['city'] = 'Lübeck';
$lgBefore2 = count( $mails );
$lgBoxSame = portalServe( $appData, '/.crm/daten', 'POST', $lgFields + [ 'login' => '1' ] );
check( 'saved with the box where the two are the same already: ?state=saved, nothing is asked, nobody is mailed', $location( $lgBoxSame ) === '/portal/meine-daten?state=saved' && count( $mails ) === $lgBefore2 && \Nino\Runtime::getSessionValue( $appData, './members/change' ) === null );
$lgBoxHostile = [];

foreach( [ 'markup' => 'x<b>@example.org', 'bracket' => 'b[1]@example.org' ] as $kind => $typed ) {
	$lgSetEmail( $typed );
	$lgBoxHostile[$kind] = $location( portalServe( $appData, '/.crm/daten', 'POST', $lgFields + [ 'login' => '1' ] ) );
}

check( 'an address of the client that Members cannot take is saved all the same and asks for nothing: the box with markup in it is ?state=saved; the page shows what is there as text', $lgBoxHostile['markup'] === '/portal/meine-daten?state=saved' && str_contains( $lgStep(), '<b>@example.org' ) === false );
$lgSetEmail( '' );

// The owner's own change does not mail the owner
$lgFresh();
$lgBefore3 = count( $mails );
\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'email' => 'anna@example.org' ] );
check( 'the owner changing the address himself is told by Members with by owner, and the callback of the portal mails nobody but the old address: the notice, and no mail of a data change to the owner', count( portalMailsFrom( $lgBefore3 ) ) === 1 && portalMail( $lgBefore3 )['to'] === 'studio@example.net' );
check( '...none of the addresses typed here reaches the warnings of the log', array_filter( ninoWarnings(), static fn( string $line ): bool => preg_match( '~@example\.(org|net)~', $line ) === 1 ) === [] && count( \Nino\Modules\Members::accounts( $appData ) ) === $lgMembers );
$lgSetEmail( (string) $lgClientBefore['email'] );
$_SESSION = [];

// --- Markup and a shortcode in what a client or the owner typed: text everywhere it is drawn ---------------------------------------------

echo "\nEscaping\n";

$hold();

$odd = 'Q <i>[members-name]</i> & "x"';
$oddFile = $put( $folderA, $odd. '.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 30 ) );
$pOdd = $made( $odd, $a['number'], [ 'folder' => $folderA['id'] ] );
$offerOdd = $final( 'offer', $a['id'], [ 'date' => '2026-10-09', 'project' => $pOdd, 'template_key' => 'angebot', 'lines' => [ $line( 'Nur ein Posten' ) ] ] );
Documents::release( $appData, $offerOdd['id'], false );
Db::exec( $appData, 'UPDATE documents SET title = :t, state = \'accepted\' WHERE id = :id', [ 't' => $odd, 'id' => $offerOdd['id'] ] );
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
check( 'the title of a project is text in its row of the list, in the path over its page and in the tab Übersicht', $isText( $oddList, '//li[@data-project="'. $pOdd. '"]//span[@class="crm-projects__title"]' ) === true && $isText( $oddPage, '//p[@class="crm-overview__title"]' ) === true && $isText( $oddPage, '//div[contains(@class, "crm-head--path")]/h2' ) === true
	&& portalFind( $oddList, '//li[@data-project="'. $pOdd. '"]//i' ) === [] && portalFind( $oddPage, '//p[@class="crm-overview__title"]//i | //div[contains(@class, "crm-head--path")]/h2//i | //nav[contains(@class, "crm-crumbs")]//i' ) === [] && $isText( $oddPage, '//nav[contains(@class, "crm-crumbs")]/span[@aria-current="page"]' ) === true );
check( '...so is the title of an offer, the project in the cell of the documents and the name of a file in its row', $isText( $oddPage, '//details[@data-document="'. $offerOdd['id']. '"]//span[@class="crm-offer__title"]' ) === true && $isText( $oddBelege, '//li[@data-document="'. $offerOdd['id']. '"]//a[contains(@class, "crm-documents__project")]' ) === true
	&& portalText( $oddPage, '//li[@data-files-id="'. $oddFile['id']. '"]//a[contains(@class, "crm-files__name")]' ) === $odd. '.mp3' && portalFind( $oddPage, '//details[@data-document="'. $offerOdd['id']. '"]//i | //li[@data-files-id="'. $oddFile['id']. '"]//i' ) === [] && portalFind( $oddBelege, '//a[contains(@class, "crm-documents__project")]//i' ) === [] );
portalSignIn( $appData, $oddAccount );
$oddData = portalPage( $appData, '/portal/meine-daten' )[1];
check( '...and the e-mail of the account in Meine Daten is the text of its block, whatever it holds', portalText( $oddData, '//strong[@class="crm-login__address"]' ) === $oddEmail && str_contains( $oddData, '&#91;members-name]' ) === true && str_contains( $oddData, '<b>' ) === false );

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
check( 'a member of no client reads the sentence: no text, no form, and no link in the nav', portalFind( $strayPage, '//div[contains(@class, "crm-privacy--empty")]/div[contains(@class, "crm-privacy__empty")]/p[contains(@class, "crm-empty__text")]' ) !== [] && portalFind( $strayPage, '//form[not(@class="crm-account__out")]' ) === [] && count( $navHrefs( '/portal' ) ) === 6 );
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
$data = portalComponentAt( $appData, '/portal/meine-daten', '[crm-client-data]' );
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
$e = \Crm\Clients::add( $appData, [ 'company' => 'Eins E GmbH', 'name' => 'Edda E', 'street' => 'Ring 1', 'zip' => '50667', 'city' => 'Köln' ] );
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
$projectPage = portalComponentAt( $appData, '/portal/projekte?projekt='. $p1, '[crm-projects]' );
$notes = portalFind( $projectPage, '//section[contains(@class, "crm-project__section--offer")]//p[contains(@class, "crm-order--privacy")]' );
check( 'while the client has not agreed to the Datenschutz there is no form under the offers that can be ordered; the sentence that the Datenschutz comes first, with the link to the page, stands once, above the offers and not under each of them', count( $notes ) === 1 && array_unique( array_map( static fn( \DOMElement $note ): string => portalFind( $note->ownerDocument->saveHTML( $note ), '//a/@href' )[0]->nodeValue, $notes ) ) === [ '/portal/datenschutz' ]
	&& portalFind( $projectPage, '//form[contains(@class, "crm-order")]' ) === [] && portalFind( $projectPage, '//p[contains(@class, "crm-order--privacy")]/following-sibling::details[contains(@class, "crm-offer")]' ) !== [] && portalFind( $projectPage, '//details[contains(@class, "crm-offer")]//p[contains(@class, "crm-order--privacy")]' ) === []
	&& count( portalFind( portalComponentAt( $appData, '/portal/belege', '[crm-documents]' ), '//p[contains(@class, "crm-order--privacy")]' ) ) === 1 );
$privacyFirst = portalServe( $appData, '/.crm/auftrag', 'POST', [ 'document' => (string) $orderOne['id'], 'from' => 'projekt', 'agree' => '1' ] );
check( '...a post is ?state=privacy, back to the page of the project, and nothing is kept: no record, the offer is as it was', $location( $privacyFirst ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=privacy&beleg='. $orderOne['id']. '#angebot-'. $orderOne['id'] && $recordsOf( $a['id'], 'order' ) === [] && in_array( Documents::get( $appData, $orderOne['id'] )['state'], [ 'open', 'sent' ], true ) === true );
portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );

// the form: the section Angebot is the page here (the tab Übersicht holds a form for the same offers, from=uebersicht - see the tab below)
$fullPage = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
$projectPage = portalSection( $fullPage, 'offer' );
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
	&& portalText( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//label' ) === $sentence && str_contains( $fullPage, 'Studio &lt;Eins&gt; &amp; Co &#91;members-name]' ) === true && portalText( $projectPage, '//form[@data-document="'. $orderOne['id']. '"]//button[@type="submit"]' ) !== '' );
$belegePage = portalPage( $appData, '/portal/belege' )[1];
check( 'the list of documents has the form under the rows of offers that can be ordered - from=belege - and under no other row: not under the invoices, not under an offer that ran out', in_array( (string) $orderTwo['id'], $formsOf( $belegePage ), true ) === true && portalFind( $belegePage, '//li[@data-document="'. $orderTwo['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'belege'
	&& portalFind( $belegePage, '//li[@data-category="invoice"]//form' ) === [] && portalFind( $belegePage, '//li[@data-document="'. $orderExpired['id']. '"]//form' ) === [] && portalFind( $belegePage, '//li[@data-document="'. $orderUnreleased['id']. '"]' ) === [] && array_diff( $orderable, $formsOf( $belegePage ) ) === [] );
check( '...the project page lists the offer once more in its section Belege, and there is no second form: the form stands under the offer itself, and the tab Übersicht holds the other one', count( array_filter( $formsOf( $projectPage ), static fn( string $id ): bool => $id === (string) $orderOne['id'] ) ) === 1 && portalFind( $fullPage, '//section[contains(@class, "crm-project__section--documents")]//form' ) === []
	&& count( array_filter( $formsOf( $fullPage ), static fn( string $id ): bool => $id === (string) $orderOne['id'] ) ) === 2 );
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
check( '...the confirmation goes to the account that ordered, the reply to the owner: the offer, the day, the link to the card of the offer on the page of the project and the address of the PDF - no attachment, in German with Du', ( $confirmMail['to'] ?? '' ) === 'anna@example.org' && ( $confirmMail['replyTo'] ?? '' ) === 'owner@example.org' && ( $confirmMail['subject'] ?? '' ) === 'Dein Auftrag: Angebot '. $orderOne['number']
	&& str_contains( $confirmMail['body'] ?? '', 'Hallo Anna Nord,' ) === true && str_contains( $confirmMail['body'], 'href="https://example.org/club/portal/projekte?projekt='. $p1. '&amp;tab=angebot#angebot-'. $orderOne['id']. '"' ) === true && str_contains( $confirmMail['body'], 'href="https://example.org/club/.crm/pdf/'. $orderOne['id']. '?download=1"' ) === true
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

$cards = static fn( string $html ): array => array_map( static fn( \DOMElement $card ): string => $card->getAttribute( 'data-card' ), portalFind( $html, '//section[@aria-labelledby="crm-start-heading"]//article[contains(concat(" ", normalize-space(@class), " "), " crm-start__card ")]' ) );
$rowsOf = static fn( string $html, string $card ): array => portalFind( $html, '//article[@data-card="'. $card. '"]//li[contains(@class, "crm-start__row")]' );
$partOf = static fn( \DOMElement $row, string $part ): string => portalText( $row->ownerDocument->saveHTML( $row ), '//span[@class="crm-start__'. $part. '"]' );
$hrefOf = static fn( \DOMElement $row ): string => portalFind( $row->ownerDocument->saveHTML( $row ), '//a[contains(@class, "crm-start__link")]/@href' )[0]->nodeValue;
$asVisitor();
check( 'a visitor gets nothing of the start page', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-start]' ) ) === '' );
portalSignIn( $appData, $stray );
check( '...nor does a member of no client', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-start]' ) ) === '' );
$d = \Crm\Clients::add( $appData, [ 'company' => 'Leer GmbH', 'name' => 'Dora Leer', 'street' => 'Weg 2', 'zip' => '80331', 'city' => 'München' ] );
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
$privacyStart = portalComponentAt( $appData, '/portal', '[crm-portal-start]' );
check( 'the card Datenschutz stands there while the owner asks for a text that the client has not agreed to, with the link - and alone it is the only card, and still no row of key figures', $cards( $privacyStart ) === [ 'privacy' ] && portalFind( $privacyStart, '//article[@data-card="privacy"]//a/@href' )[0]->nodeValue === '/portal/datenschutz' && portalFind( $privacyStart, '//ul[contains(@class, "crm-kpis")]' ) === [] );
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
check( 'for Studio Nord the cards are there that apply - not the Datenschutz it has agreed to: the documents and the dates under the heading, the files and the offers in the section over it', $cards( $start ) === [ 'documents', 'dates' ] && portalFind( $start, '//article[@data-card="privacy"]' ) === [] );
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
check( 'the cards of the start page are .crm-card in one grid: no card without the class, and the links of the offers and of the files name their tab (angebot, dateien) and the anchor of their card', portalFind( $start, '//article[@data-card][not(contains(concat(" ", normalize-space(@class), " "), " crm-card "))]' ) === [] && count( portalFind( $start, '//div[contains(@class, "crm-start__grid")]/article[@data-card] | //section[contains(@class, "crm-start__top")]/article[@data-card]' ) ) >= 4
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
check( '...where Events is off there is no tile of the date - the row stands, as the other numbers are more than nothing', $tilesOf( $withoutEvents ) === [ 'offers', 'invoices', 'files' ] );
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
check( 'the due cell: an invoice names its day with the machine day ("fällig am"), one with no day says it is payable at once, a paid one has none - it is due no more -, and no other kind has a due cell with anything in it', portalFind( $docs, '//li[@data-document="'. $invFuture['id']. '"]//span[contains(@class, "crm-documents__due")]/time/@datetime' )[0]->nodeValue === date( 'Y-m-d', strtotime( '+14 days' ) )
	&& portalFind( $docs, '//li[@data-document="'. $invNoDue['id']. '"]//span[contains(@class, "crm-documents__due-none")]' ) !== [] && portalText( $docs, '//li[@data-document="'. $invNoDue['id']. '"]//span[contains(@class, "crm-documents__due-none")]' ) === $wordsDe['[[/project/crm-documents/list/due-none]]']
	&& portalDueCellChecks() === true && portalFocusSheetChecks() === true && portalFind( $docs, '//li[@data-document="'. $invPaid['id']. '"]//span[contains(@class, "crm-documents__due")]/time' ) === [] && trim( $cell( $docs, $invPaid['id'], 'due' )->textContent ) === '' && trim( $cell( $docs, $offerA['id'], 'due' )->textContent ) === '' && trim( $cell( $docs, $contractA['id'], 'due' )->textContent ) === '' );

// how an open invoice is paid
$payOf = static fn( string $html, int $id ): array => portalFind( $html, '//li[@data-document="'. $id. '"]/details[contains(@class, "crm-documents__fold")]/div[contains(@class, "crm-fold__body")]/dl[contains(@class, "crm-pay")]' );
$pay = $payOf( $docs, $invFuture['id'] );
$facts = $pay === [] ? [] : portalFind( $pay[0]->ownerDocument->saveHTML( $pay[0] ), '//div[contains(@class, "crm-pay__fact")]' );
check( 'an open invoice folds out to how it is paid: the amount, the payee, the bank, the IBAN and the BIC of the settings, and the number as the purpose - in a nested card, every value escaped, collapsed until it is asked for', count( $pay ) === 1 && str_contains( $pay[0]->getAttribute( 'class' ), 'crm-card--nested' ) === true
	&& array_map( static fn( \DOMElement $fact ): string => preg_replace( '/^.*crm-pay__fact--/', '', $fact->getAttribute( 'class' ) ), $facts ) === [ 'amount', 'to', 'bank', 'iban', 'bic', 'purpose' ]
	&& array_map( static fn( \DOMElement $fact ): string => portalText( $fact->ownerDocument->saveHTML( $fact ), '//dd' ), $facts ) === [ '100,00 €', portalText( $pay[0]->ownerDocument->saveHTML( $pay[0] ), '//div[contains(@class, "crm-pay__fact--to")]/dd' ), 'Hamburger <b>Sparkasse</b>', 'DE02 1203 0000 0000 2020 51 '. $wordsDe['[[/project/crm-portal/pay/copy]]'], 'BYLADEM1001', $invFuture['number'] ]
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
// PayPal: the name of PayPal.me makes a row with a button that opens the payment of the amount
$factNamesOf = static fn( string $html ): array => array_map( static fn( \DOMElement $fact ): string => preg_replace( '/^.*crm-pay__fact--/', '', $fact->getAttribute( 'class' ) ), portalFind( $html, '//div[contains(@class, "crm-pay__fact")]' ) );
$blockOf = static fn( string $html, int $id ): string => ( $found = $payOf( $html, $id ) ) === [] ? '' : $found[0]->ownerDocument->saveHTML( $found[0] );
\Crm\Settings::set( $appData, 'paypal', 'Klangwerk' );
$paypalDocs = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'with the PayPal.me name and neither a bank nor an IBAN the block is there all the same: the amount, the purpose and the PayPal row - no payee, no bank', count( $payOf( $paypalDocs, $invFuture['id'] ) ) === 1 && $factNamesOf( $blockOf( $paypalDocs, $invFuture['id'] ) ) === [ 'amount', 'purpose', 'paypal' ] && count( $payOf( $paypalDocs, $invPast['id'] ) ) === 1 );
\Crm\Settings::set( $appData, 'bank_name', 'Hamburger <b>Sparkasse</b>' );
\Crm\Settings::set( $appData, 'iban', 'DE02 1203 0000 0000 2020 51' );
$paypalDocs = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
$paypalBlock = $blockOf( $paypalDocs, $invFuture['id'] );
$paypalLink = portalFind( $paypalBlock, '//div[contains(@class, "crm-pay__fact--paypal")]//a' );
check( '...with a bank as well the row stands last, after the purpose; the button is a link to https://paypal.me/<name>/<amount>EUR made of the name and the gross sum, opens in a new window with rel noopener noreferrer, has the icon of an external link and the word', $factNamesOf( $paypalBlock ) === [ 'amount', 'to', 'bank', 'iban', 'purpose', 'paypal' ] && count( $paypalLink ) === 1
	&& $paypalLink[0]->getAttribute( 'href' ) === 'https://paypal.me/Klangwerk/100.00EUR' && $paypalLink[0]->getAttribute( 'target' ) === '_blank' && $paypalLink[0]->getAttribute( 'rel' ) === 'noopener noreferrer' && str_contains( ' '. $paypalLink[0]->getAttribute( 'class' ). ' ', ' crm-btn ' ) === true
	&& portalFind( $paypalBlock, '//div[contains(@class, "crm-pay__fact--paypal")]//a/svg[contains(@class, "crm-icon--external-link")]' ) !== [] && trim( portalText( $paypalBlock, '//div[contains(@class, "crm-pay__fact--paypal")]//a' ) ) === $wordsDe['[[/project/crm-portal/pay/paypal-button]]'] );
check( '...the sentence beside the button asks for the number of the invoice as the purpose, the number set apart as text', portalText( $paypalBlock, '//div[contains(@class, "crm-pay__fact--paypal")]//strong' ) === $invFuture['number'] && str_contains( portalText( $paypalBlock, '//div[contains(@class, "crm-pay__fact--paypal")]//span[contains(@class, "crm-pay__hint")]' ), $invFuture['number'] ) === true && str_contains( $paypalBlock, '{number}' ) === false );
check( '...no other invoice-row of the list has a different link than its own sum: the one past its day has the same name and its own amount', portalFind( $blockOf( $paypalDocs, $invPast['id'] ), '//div[contains(@class, "crm-pay__fact--paypal")]//a/@href' )[0]->nodeValue === 'https://paypal.me/Klangwerk/'. intdiv( Documents::get( $appData, $invPast['id'] )['gross'], 100 ). '.'. sprintf( '%02d', Documents::get( $appData, $invPast['id'] )['gross'] % 100 ). 'EUR' );
$sums = [ 75000 => '750.00EUR', 123450 => '1234.50EUR', 1234500 => '12345.00EUR', 5 => '0.05EUR', 100 => '1.00EUR', 99999999 => '999999.99EUR', 1 => '0.01EUR' ];
check( '...the sum is the gross in cents written with a point and two places, no sign for thousands: 750,00 € is 750.00EUR, 1.234,50 € is 1234.50EUR', array_map( static fn( int $gross ): string => \Crm\Portal::paypalLink( $appData, [ 'gross' => $gross ] ), array_keys( $sums ) ) === array_map( static fn( string $sum ): string => 'https://paypal.me/Klangwerk/'. $sum, array_values( $sums ) ) );
check( '...an invoice without a sum to pay has no link: nothing, zero, a credit, a sum that is no number', array_unique( array_map( static fn( mixed $gross ): string => \Crm\Portal::paypalLink( $appData, [ 'gross' => $gross ] ), [ 0, -500, '750', 7.5, null ] ) ) === [ '' ] && \Crm\Portal::paypalLink( $appData, [] ) === '' );
\Crm\Settings::set( $appData, 'currency', 'CHF' );
check( '...nor has an invoice in a currency other than the euro; EUR is the euro as well as the sign', \Crm\Portal::paypalLink( $appData, [ 'gross' => 75000 ] ) === '' && \Crm\Settings::set( $appData, 'currency', 'EUR' ) === true && \Crm\Portal::paypalLink( $appData, [ 'gross' => 75000 ] ) === 'https://paypal.me/Klangwerk/750.00EUR' );
\Crm\Settings::set( $appData, 'currency', '€' );
$evilNames = [ 'Evil<b>', 'a/b', 'x"onmouseover="y', 'a b', str_repeat( 'a', 21 ), 'https://evil.example/x', "Da\0vid", '..', 'a?b=c', 'ä', "a\nb", 'Klang-werk', '[[/project/company/general/name]]', 'x&y' ];
$linksFor = static function( string $name ) use ( &$appData ): array {
	Db::exec( $appData, 'UPDATE settings SET value = :value WHERE key = \'paypal\'', [ 'value' => $name ] );
	$html = \Nino\Html::renderHtml( $appData, '[crm-documents]' );

	return [ \Crm\Portal::paypalLink( $appData, [ 'gross' => 75000 ] ), str_contains( $html, 'paypal.me' ), portalFind( $html, '//div[contains(@class, "crm-pay__fact--paypal")]' ) === [] ];
};
check( '...the link is made of the checked name only: whatever else the table holds - a tag, a slash, a quote, a blank, twenty-one characters, an address, a null byte, a dot, a query, an umlaut, a line break, a hyphen, a fill, an ampersand - gives no link and no row, in the page as in the function', array_filter( $evilNames, static fn( string $name ): bool => $linksFor( $name ) !== [ '', false, true ] ) === [] );
\Crm\Settings::set( $appData, 'paypal', '' );
$noPaypal = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( '...without the name there is no PayPal row, no link to paypal.me and no sentence: the block is what it was', $factNamesOf( $blockOf( $noPaypal, $invFuture['id'] ) ) === [ 'amount', 'to', 'bank', 'iban', 'purpose' ] && str_contains( $noPaypal, 'paypal' ) === false );
\Crm\Settings::set( $appData, 'paypal', 'Klangwerk' );
\Crm\Settings::set( $appData, 'bank_name', '' );
\Crm\Settings::set( $appData, 'iban', '' );
$weird = [ 'category' => 'invoice', 'number' => 'R<b>1</b> [[/project/company/general/name]] "q" [members-name]', 'gross' => 75000 ];
$weirdBlock = \Nino\Html::renderHtml( $appData, \Crm\Portal::payBlock( $appData, $weird ) );
check( 'the number of the invoice enters the block as text, in the purpose and in the sentence beside the button: no <b> of it, no fill of the page, no shortcode', portalFind( $weirdBlock, '//b' ) === [] && str_contains( $weirdBlock, 'R&lt;b&gt;1&lt;/b&gt; &#91;&#91;/project/company/general/name]] &quot;q&quot; &#91;members-name]' ) === true && substr_count( $weirdBlock, 'R&lt;b&gt;1' ) === 2 && str_contains( $weirdBlock, 'Studio' ) === false
	&& portalFind( $weirdBlock, '//a/@href' )[0]->nodeValue === 'https://paypal.me/Klangwerk/750.00EUR' );
\Crm\Settings::set( $appData, 'paypal', '' );
check( '...and with neither a bank nor an IBAN nor a name there is no block at all', \Crm\Portal::payBlock( $appData, $weird ) === '' );
foreach( $settingsWere as $key => $value )
	\Crm\Settings::set( $appData, $key, $value );
\Crm\Settings::set( $appData, 'paypal', '' );

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
		&& portalFind( $one, '//header[@class="crm-bar"]/div/form[@action="/.members/logout"]/button[@class="crm-account__logout"]/svg[@aria-hidden="true"]' ) !== [] && count( portalFind( $one, '//header[@class="crm-bar"]/following-sibling::nav[contains(@class, "crm-nav")]' ) ) === 1 && count( portalFind( $one, '//header[@class="crm-bar"]//h1' ) ) === 1 && portalFind( $one, '//div[@class="crm-head"]//a[contains(@class, "crm-account")]' ) === []
		&& portalFind( $one, '//div[@class="crm-head"]//header' ) === [] && strpos( $one, 'crm-head__title' ) < strpos( $one, 'class="crm-' . ( [ 'portal' => 'start', 'meine-daten' => 'data', 'projekte' => 'projects', 'upload' => 'upload', 'belege' => 'documents', 'termine' => 'dates', 'datenschutz' => 'privacy' ][$name] ). '' );

	if( $headOk === false )
		$headIssues[] = $uri;
}

check( 'every page of the portal opens with the bar of the application (the name of the site with the h1, the account of the member - the initials as a link to Meine Daten - and Abmelden as an icon button in a form), the navigation and then the page head: a div (never a header), the page title as the h2 and the lead - and it is the first thing in the page, before the content', $headIssues === [] );
check( '...the calendar is in the head of Termine only, the breadcrumb in the head of a project only', array_filter( array_keys( $pagesOf ), static fn( string $uri ): bool => ( portalFind( portalPage( $appData, $uri )[1], '//div[@class="crm-head"]//a[contains(@class, "events-feed")]' ) !== [] ) !== ( $uri === '/portal/termine' ) ) === []
	&& array_filter( array_keys( $pagesOf ), static fn( string $uri ): bool => portalFind( portalPage( $appData, $uri )[1], '//div[contains(@class, "crm-head")]//nav[contains(@class, "crm-crumbs")]' ) !== [] ) === [] && portalFind( $projectDocs, '//div[contains(@class, "crm-head--path")]/nav[contains(@class, "crm-crumbs")]' ) !== [] );

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

// --- The versions of a title ------------------------------------------------------------------------------------------------------

echo "\nThe versions of a title\n";

$hold();

portalSignIn( $appData, $accounts['anna'] );
$folderV = $store::addFolder( $appData, $a['group'], 'Versionen' );
$song1 = $put( $folderV, 'Song.01.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-01T10:00:00Z' );
$song2 = $put( $folderV, 'Song.02.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-12T10:00:00Z' );
$song3 = $put( $folderV, 'Song.03.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-20T10:00:00Z' );
$song4 = $put( $folderV, 'Song.04.mp3', 'mp3', 'audio/mpeg', false, $owner, '2026-10-25T10:00:00Z' );
$songWav = $put( $folderV, 'Song.03.wav', 'wav', 'audio/wav', true, $owner, '2026-10-05T10:00:00Z' );
$mixOld = $put( $folderV, 'Mix.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-02T10:00:00Z' );
$mixOne = $put( $folderV, 'Mix.01.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-14T10:00:00Z' );
$solo = $put( $folderV, 'Solo.01.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-08T10:00:00Z' );
$text = $put( $folderV, 'Notizen.txt', 'txt', 'text/plain', true, $owner, '2026-09-20T10:00:00Z' );
$evilName = 'Evil <b>x</b> [[/project/company/general/name]]';
$evil1 = $put( $folderV, $evilName. '.01.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-03T10:00:00Z' );
$evil2 = $put( $folderV, $evilName. '.02.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-04T10:00:00Z' );
$strangerSong = $put( $folderB, 'Song.09.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-10-30T10:00:00Z' );
$pV = $made( 'Versionen', $a['number'], [ 'folder' => $folderV['id'] ] );

// Comments: two of Anna on the first version (one settled later), one on the second, one of the owner on the third
$cmA1 = \Nino\Modules\Review::add( $appData, $song1['id'], $byAnna, 12.0, 'Zu laut <i>hier</i>' );
$cmA2 = \Nino\Modules\Review::add( $appData, $song1['id'], $byAnna, 40.0, 'Ende abrupt' );
$cmB1 = \Nino\Modules\Review::add( $appData, $song2['id'], $byAnna, 5.5, 'Einsatz zu früh' );
\Nino\Modules\Review::add( $appData, $song3['id'], $owner, null, 'Ist gemacht' );
\Nino\Modules\Review::add( $appData, $song4['id'], $byAnna, null, 'Nicht zu sehen' );

$versionsUri = '/portal/projekte?projekt='. $pV;
$versionsHtml = portalPage( $appData, $versionsUri )[1];
$rowOf = static fn( string $html, string $id ): string => ( $found = portalFind( $html, '//li[@data-files-id="'. $id. '"]' ) ) === [] ? '' : $found[0]->ownerDocument->saveHTML( $found[0] );
$pillOf = static fn( string $row, string $class ): string => portalText( $row, '//span[contains(concat(" ", normalize-space(@class), " "), " crm-files__'. $class. ' ")]' );
$namesOf = static fn( string $html ): array => array_map( static fn( \DOMElement $link ): string => trim( (string) preg_replace( '/\s+/', ' ', $link->textContent ) ), portalFind( $html, '//section[contains(@class, "crm-project__section--files")]//a[contains(@class, "crm-files__name")]' ) );

check( 'Dateien lists one row for each title, not for each file: the titles in the order of their newest file, a title that is two files of the same name with another extension a row of its own, a file that says no version with its name as it is',
	$namesOf( $versionsHtml ) === [ 'Song', 'Mix', 'Solo', 'Song', $evilName, 'Notizen.txt' ] && count( portalFind( $versionsHtml, '//section[contains(@class, "crm-project__section--files")]//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) === 6 );
$songRow = $rowOf( $versionsHtml, $song3['id'] );
check( '...the row of a title is the row of its newest version - its id, its size, its day, the link to listen to it and the download of it - with the number of that version, how many versions the title has and how many comments are open over all of them',
	$songRow !== '' && $pillOf( $songRow, 'version' ) === 'v03' && (int) $pillOf( $songRow, 'versions' ) === 3 && (int) $pillOf( $songRow, 'open' ) === 3
	&& portalFind( $songRow, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pV. '&review='. $song3['id']. '#projekt-files' && portalFind( $songRow, '//a[contains(@class, "crm-files__listen")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pV. '&review='. $song3['id']. '#projekt-files'
	&& portalFind( $songRow, '//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $song3['id']. '?download=1' && portalFind( $songRow, '//time/@datetime' )[0]->nodeValue === $song3['created'] && $rowOf( $versionsHtml, $song1['id'] ) === '' && $rowOf( $versionsHtml, $song2['id'] ) === '' );
check( '...the open comments are those of members that the owner has not settled, counted over every version of the title: not the owner\'s own comment on the newest, and not a comment on a version the member may not see (Song.04 is hidden)',
	(int) $pillOf( $songRow, 'open' ) === 3 && (int) $pillOf( $songRow, 'versions' ) === 3 && str_contains( $versionsHtml, 'Nicht zu sehen' ) === false && str_contains( $versionsHtml, $song4['id'] ) === false );
$mixRow = $rowOf( $versionsHtml, $mixOne['id'] );
check( '...a file without a version joins the title that its name belongs to: Mix.mp3 and Mix.01.mp3 are the title Mix, the numbered one the newest here, and the plain one a version of it that has no number',
	$mixRow !== '' && $pillOf( $mixRow, 'version' ) === 'v01' && (int) $pillOf( $mixRow, 'versions' ) === 2 && $pillOf( $mixRow, 'open' ) === '' && $rowOf( $versionsHtml, $mixOld['id'] ) === '' );
$soloRow = $rowOf( $versionsHtml, $solo['id'] );
$textRow = $rowOf( $versionsHtml, $text['id'] );
check( '...a title of one version has its number and no count of versions; a file that says no version is a row as the page of a project always had it - its name, no pill of version, of versions or of comments, and the download',
	$pillOf( $soloRow, 'version' ) === 'v01' && $pillOf( $soloRow, 'versions' ) === '' && $pillOf( $soloRow, 'open' ) === '' && trim( portalText( $textRow, '//a[contains(@class, "crm-files__name")]' ) ) === 'Notizen.txt'
	&& $pillOf( $textRow, 'version' ) === '' && $pillOf( $textRow, 'versions' ) === '' && $pillOf( $textRow, 'open' ) === '' && portalFind( $textRow, '//a[contains(@class, "crm-files__listen")]' ) === [] && portalFind( $textRow, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/.files/'. $text['id']. '?download=1' );
$evilRow = $rowOf( $versionsHtml, $evil2['id'] );
check( '...a name with markup and a key in it is text in the row - the title, the version and the label of the buttons - and a bracket of it is no fill of the page',
	portalFind( $evilRow, '//b | //i' ) === [] && trim( portalText( $evilRow, '//a[contains(@class, "crm-files__name")]' ) ) === $evilName && str_contains( $versionsHtml, 'Evil &lt;b&gt;x&lt;/b&gt; &#91;&#91;/project/company/general/name]]' ) === true && str_contains( $evilRow, 'The Club' ) === false
	&& str_contains( portalText( $evilRow, '//a[contains(@class, "crm-files__download")]/span[contains(@class, "nino-sr-only")]' ), $evilName ) === true && $pillOf( $evilRow, 'version' ) === 'v02' );
$wavRow = $rowOf( $versionsHtml, $songWav['id'] );
check( '...the extension is a pill beside the version in the row of a title and in the label of its buttons: Song.03.mp3 and Song.03.wav are two rows of the same name that differ by it - and a file that is drawn with its own name has none, the name says it',
	$wavRow !== '' && trim( portalText( $wavRow, '//a[contains(@class, "crm-files__name")]' ) ) === 'Song' && $pillOf( $wavRow, 'version' ) === 'v03' && $pillOf( $wavRow, 'ext' ) === 'WAV' && $pillOf( $songRow, 'ext' ) === 'MP3' && $pillOf( $textRow, 'ext' ) === '' && $pillOf( $mixRow, 'ext' ) === 'MP3'
	&& str_contains( portalText( $wavRow, '//a[contains(@class, "crm-files__download")]/span[contains(@class, "nino-sr-only")]' ), 'Song 03 WAV' ) === true && str_contains( portalText( $songRow, '//a[contains(@class, "crm-files__listen")]/span[contains(@class, "nino-sr-only")]' ), 'Song 03 MP3' ) === true );
check( '...the tab counts files, as the project row and the start page do: the page lists 6 titles of the 10 files the member may see',
	(int) trim( ltrim( portalFind( $versionsHtml, '//a[@data-tab="dateien"]/span[contains(@class, "crm-project__count")]' )[0]->textContent, ', ' ) ) === 10 );

// The player: the select of the versions of the title that is played
$played2 = portalPage( $appData, $versionsUri. '&review='. $song2['id'] )[1];
$selects = portalFind( $played2, '//select' );
$options = portalFind( $played2, '//select[@name="review"]/option' );
check( 'with ?review=<version> a title of several versions has a row Version over the medium of its player: a select with one option for each version the member may see, the newest first, the one that plays selected - and no version of another title',
	count( $selects ) === 1 && array_map( static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ), $options ) === [ $song3['id'], $song2['id'], $song1['id'] ] && array_map( static fn( \DOMElement $option ): bool => $option->hasAttribute( 'selected' ), $options ) === [ false, true, false ]
	&& count( portalFind( $played2, '//div[contains(@class, "review-player")]//select' ) ) === 1 && count( portalFind( $played2, '//label[@for="'. $selects[0]->getAttribute( 'id' ). '"]' ) ) === 1 && $selects[0]->getAttribute( 'id' ) !== '' );
$order = array_map( static fn( \DOMElement $node ): string => $node->nodeName, portalFind( $played2, '//div[contains(@class, "review-player")]/*[self::h3 or self::form or self::audio]' ) );
check( '...it stands between the title and the medium of the player, and the player is the one of Review: its form of comments, the audio element with the file that was asked for',
	$order === [ 'h3', 'form', 'audio', 'form' ] && portalFind( $played2, '//audio/@src | //audio/source/@src' )[0]->nodeValue === '/.files/'. $song2['id'] && portalFind( $played2, '//form[@class="review-form"]/input[@name="file"]/@value' )[0]->nodeValue === $song2['id'] );
$switch = portalFind( $played2, '//form[contains(@class, "crm-versions")]' );
check( '...without the script the select is a form: a GET to the page of the projects with the project, the select named review and a button that sends it - the page of a version is an address that can be linked to',
	count( $switch ) === 1 && $switch[0]->getAttribute( 'method' ) === 'get' && $switch[0]->getAttribute( 'action' ) === '/portal/projekte#crm-versions' && portalFind( $played2, '//form[contains(@class, "crm-versions")]/input[@type="hidden"]/@name' )[0]->nodeValue === 'projekt'
	&& portalFind( $played2, '//form[contains(@class, "crm-versions")]/input[@type="hidden"]/@value' )[0]->nodeValue === $pV && count( portalFind( $played2, '//form[contains(@class, "crm-versions")]//button[@type="submit"]' ) ) === 1 && $switch[0]->getAttribute( 'id' ) === 'crm-versions' && portalFind( $played2, '//form[contains(@class, "crm-versions")][@onsubmit]' ) === [] );
$texts = array_map( static fn( \DOMElement $option ): string => trim( $option->textContent ), $options );
check( '...an option says the number of the version, the day it was put there and - where comments are open - how many: Song.03 has none, Song.02 one and Song.01 two',
	preg_match( '/^03 · \d\d\.\d\d\.\d{4}$/u', $texts[0] ) === 1 && preg_match( '/^02 · 12\.10\.2026 · 1 \S+$/u', $texts[1] ) === 1 && preg_match( '/^01 · 01\.10\.2026 · 2 \S+$/u', $texts[2] ) === 1 && str_contains( implode( '', $texts ), '[[' ) === false );
check( '...the title that is played is marked in the list, once - a row of its own, whichever of its versions plays', count( portalFind( $played2, '//li[contains(@class, "crm-files__row--playing")]' ) ) === 1 && portalFind( $played2, '//li[contains(@class, "crm-files__row--playing")]/@data-files-id' )[0]->nodeValue === $song3['id']
	&& portalFind( $played2, '//li[contains(@class, "crm-files__row--playing")]/@aria-current' )[0]->nodeValue === 'true' );
check( '...the comments are those of that version: the one on Song.02 and none of Song.01 or Song.03, with the player of the version', str_contains( $played2, 'Einsatz zu früh' ) === true && str_contains( $played2, 'Zu laut' ) === false && str_contains( $played2, 'Ist gemacht' ) === false && count( portalFind( $played2, '//li[contains(@class, "review-comment")]' ) ) === 1 );
$played1 = portalPage( $appData, $versionsUri. '&review='. $song1['id'] )[1];
$played3 = portalPage( $appData, $versionsUri. '&review='. $song3['id'] )[1];
check( '...and the other versions the same: Song.01 has its two, with the markup of one of them as text, Song.03 the owner\'s only, and the select names the same three with another one selected',
	count( portalFind( $played1, '//li[contains(@class, "review-comment")]' ) ) === 2 && str_contains( $played1, 'Zu laut &lt;i&gt;hier&lt;/i&gt;' ) === true && portalFind( $played1, '//li[contains(@class, "review-comment")]//i' ) === [] && count( portalFind( $played3, '//li[contains(@class, "review-comment")]' ) ) === 1
	&& array_map( static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ), portalFind( $played1, '//select/option[@selected]' ) ) === [ $song1['id'] ] && array_map( static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ), portalFind( $played3, '//select/option[@selected]' ) ) === [ $song3['id'] ] );
check( '...a title of one version has no row Version: neither Solo.01 nor a file of a title that was never versioned shows a select',
	portalFind( portalPage( $appData, $versionsUri. '&review='. $solo['id'] )[1], '//select | //form[contains(@class, "crm-versions")]' ) === [] && count( portalFind( portalPage( $appData, $versionsUri. '&review='. $solo['id'] )[1], '//div[contains(@class, "review-player")]' ) ) === 1 );
$mixPlayed = portalPage( $appData, $versionsUri. '&review='. $mixOld['id'] )[1];
check( '...a version without a number is an option of its title, after the numbered ones, with its name in place of the number',
	array_map( static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ), portalFind( $mixPlayed, '//select/option' ) ) === [ $mixOne['id'], $mixOld['id'] ] && str_starts_with( trim( portalFind( $mixPlayed, '//select/option' )[1]->textContent ), 'Mix.mp3 · ' ) === true );
$evilPlayed = portalPage( $appData, $versionsUri. '&review='. $evil1['id'] )[1];
check( '...a name with markup and a key in it is text in the option and the title of the player as well',
	portalFind( $evilPlayed, '//select//b | //h3//b' ) === [] && portalFind( $evilPlayed, '//select/option/@value' )[0]->nodeValue === $evil2['id'] && trim( portalFind( $evilPlayed, '//h3[contains(@class, "review-player__title")]' )[0]->textContent ) === $evilName. '.01.mp3' && str_contains( $evilPlayed, 'The Club' ) === false );

// A select names the files of the folder of this project and nobody else's
$foreign = portalPage( $appData, $versionsUri. '&review='. $strangerSong['id'] )[1];
$hiddenAsked = portalPage( $appData, $versionsUri. '&review='. $song4['id'] )[1];
$otherProject = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song2['id'] )[1];
check( 'a file of another client asked for with the project of this one is not played and no select names it - the page is the list; so is a version the member may not see, and a file of another project of the same client',
	array_filter( [ $foreign, $hiddenAsked, $otherProject ], static fn( string $html ): bool => portalFind( $html, '//select | //div[contains(@class, "review-player")] | //form[contains(@class, "crm-versions")]' ) !== [] ) === []
	&& str_contains( $foreign. $hiddenAsked. $otherProject, $strangerSong['id'] ) === false && str_contains( $played2, $strangerSong['id'] ) === false && str_contains( $played2, $song4['id'] ) === false
	&& in_array( $songWav['id'], array_map( static fn( \DOMElement $option ): string => $option->getAttribute( 'value' ), $options ), true ) === false );
$allIds = [];

foreach( [ $played1, $played2, $played3, $mixPlayed, $evilPlayed ] as $one )
	foreach( portalFind( $one, '//select[@name="review"]/option/@value' ) as $value )
		$allIds[] = $value->nodeValue;

$visibleHere = array_column( \Crm\Portal\Files::visible( $appData, $folderV['id'] ), 'id' );
check( '...every option of every select of the page is a file of the folder of the project that the member sees', $allIds !== [] && array_diff( $allIds, $visibleHere ) === [] );
$otherQuery = portalPage( $appData, '/portal/projekte?projekt='. $pV. '&review[]=x&projekt2='. $song2['id'] )[1];
check( 'a query that is no id - a list in the place of a file, a name that is none - leaves the page of the project with no select',
	portalFind( $otherQuery, '//select | //form[contains(@class, "crm-versions")]' ) === [] && count( portalFind( portalPage( $appData, $versionsUri. '&review=..%2F..%2Fx' )[1], '//article[contains(@class, "crm-project")]' ) ) === 1 );

// A comment settled is a comment that is not open: the pill, the options, the mark of the comment
\Nino\Modules\Review\Store::toggle( $appData, $song1['id'], $cmA1['id'] );
$settled = portalPage( $appData, $versionsUri. '&review='. $song1['id'] )[1];
check( 'a comment the owner has marked as done is no open comment on the row of its title, and the player says it is done; the options count the open ones that are left',
	(int) $pillOf( $rowOf( $settled, $song3['id'] ), 'open' ) === 2 && count( portalFind( $settled, '//li[contains(@class, "review-comment--done")]' ) ) === 1 && count( portalFind( $settled, '//li[contains(@class, "review-comment--done")]//span[contains(@class, "review-comment__done")]' ) ) === 1
	&& count( portalFind( $settled, '//li[contains(@class, "review-comment") and not(contains(@class, "review-comment--done"))]//span[contains(@class, "review-comment__done")]' ) ) === 0 );
\Nino\Modules\Review\Store::toggle( $appData, $song1['id'], $cmA2['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $song2['id'], $cmB1['id'] );
$allSettled = portalPage( $appData, $versionsUri )[1];
check( '...with every comment settled the title has no pill of open comments, and a select has no count in its options',
	$pillOf( $rowOf( $allSettled, $song3['id'] ), 'open' ) === '' && (int) $pillOf( $rowOf( $allSettled, $song3['id'] ), 'versions' ) === 3 && array_filter( portalFind( portalPage( $appData, $versionsUri. '&review='. $song3['id'] )[1], '//select/option' ), static fn( \DOMElement $option ): bool => str_contains( $option->textContent, ' · ' ) === true && substr_count( $option->textContent, ' · ' ) !== 1 ) === [] );
\Nino\Modules\Review\Store::toggle( $appData, $song1['id'], $cmA1['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $song1['id'], $cmA2['id'] );
\Nino\Modules\Review\Store::toggle( $appData, $song2['id'], $cmB1['id'] );

// Review off: the titles stay, the comments are not there to count, and nothing is played
\Nino\Features::deactivate( $appData, 'review' );
$noReviewV = portalPage( $appData, $versionsUri. '&review='. $song3['id'] )[1];
check( 'with Review off the titles are rows all the same - with their versions and no count of comments - and there is no player and no select',
	$namesOf( $noReviewV ) === [ 'Song', 'Mix', 'Solo', 'Song', $evilName, 'Notizen.txt' ] && portalFind( $noReviewV, '//span[contains(@class, "crm-files__open")] | //select | //div[contains(@class, "review-player")] | //a[contains(@class, "crm-files__listen")]' ) === [] );
\Nino\Features::activate( $appData, 'review' );

// A version that was removed from the server: still an option, its comments stay, its player has no medium
\Nino\Modules\Files\Store::removeBytes( $appData, $song3['id'] );
$goneVersion = portalPage( $appData, $versionsUri. '&review='. $song3['id'] )[1];
$goneTop = portalPage( $appData, $versionsUri )[1];
check( 'a removed version is an option that says so, with its comments and no medium; the row of the title is the newest version that is on the server - the highest number is gone, so the row is Song.02: its id, its number, its link',
	count( portalFind( $goneVersion, '//select/option' ) ) === 3 && portalFind( $goneVersion, '//audio | //video' ) === [] && portalFind( $goneVersion, '//p[contains(@class, "crm-files__gone-note")]' ) !== [] && substr_count( trim( portalFind( $goneVersion, '//select/option[@selected]' )[0]->textContent ), ' · ' ) === 2
	&& $rowOf( $goneTop, $song3['id'] ) === '' && ( $topRow = $rowOf( $goneTop, $song2['id'] ) ) !== '' && $pillOf( $topRow, 'version' ) === 'v02' && (int) $pillOf( $topRow, 'versions' ) === 3 && portalFind( $topRow, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pV. '&review='. $song2['id']. '#projekt-files'
	&& portalFind( $topRow, '//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $song2['id']. '?download=1' );
\Nino\Modules\Files\Store::removeBytes( $appData, $song2['id'] );
check( '...and the next one down when that is removed too: the row is Song.01', ( $lowRow = $rowOf( portalPage( $appData, $versionsUri )[1], $song1['id'] ) ) !== '' && $pillOf( $lowRow, 'version' ) === 'v01' );
\Nino\Modules\Files\Store::removeBytes( $appData, $song1['id'] );
$allGone = portalPage( $appData, $versionsUri )[1];
check( '...a title whose every version was removed is one row of the newest, as a removed file is drawn: its name, no link that opens it',
	count( portalFind( $allGone, '//li[contains(@class, "crm-files__row--gone")]' ) ) === 1 && portalFind( $allGone, '//li[contains(@class, "crm-files__row--gone")]/@data-files-id' )[0]->nodeValue === $song3['id'] && portalFind( $allGone, '//li[contains(@class, "crm-files__row--gone")]//a[not(starts-with(@href, "mailto:"))]' ) === [] );

// The uploads list files, not titles: a file is a row of its own
$uploadV1 = $put( $folderUp, 'Take.01.txt', 'txt', 'text/plain', true, $owner, $ago( 50 ) );
$uploadV2 = $put( $folderUp, 'Take.02.txt', 'txt', 'text/plain', true, $owner, $ago( 40 ) );
$store::markUpload( $appData, $folderUp['id'], true );
$uploadsV = \Nino\Html::renderHtml( $appData, '[crm-uploads]' );
check( 'the list of the uploads is a list of files: Take.01.txt and Take.02.txt are a row each, with their names, and no pill of version, of versions or of comments',
	array_values( array_filter( array_map( static fn( \DOMElement $link ): string => trim( $link->textContent ), portalFind( $uploadsV, '//a[contains(@class, "crm-files__name")]' ) ), static fn( string $name ): bool => str_starts_with( $name, 'Take.' ) ) ) === [ 'Take.02.txt', 'Take.01.txt' ] && portalFind( $uploadsV, '//span[contains(@class, "crm-files__version") or contains(@class, "crm-files__versions") or contains(@class, "crm-files__open")]' ) === [] );

// The script: the select sends its form when the choice changes, and the button that does it without the script goes
$portalScript = (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.js' );
$versionsScript = (string) preg_replace( '/^\s*\/\/.*$/m', '', $portalScript );
check( 'the script of the portal takes the button of the select of the versions away and sends its form - a plain GET, with requestSubmit so that a browser that checks the form does - when the choice changes with the mouse or by touch',
	preg_match( '/function watchVersions\(\s*\w*\s*\)[\s\S]*?\.hidden\s*=\s*true[\s\S]*?form\.requestSubmit\(\)[\s\S]*?addEventListener\(\s*[\'"]change[\'"][\s\S]{0,120}?keyboard === false[\s\S]{0,40}?send\(\)/', $versionsScript ) === 1 );
check( '...a choice with the keyboard - the arrows, Home, End, PageUp, PageDown, a letter - only marks the select (a keydown branch), the change that follows sends nothing, and the form is sent on Enter and when the select is left (blur) with another option than the page has chosen (defaultSelected)',
	preg_match( '/addEventListener\(\s*[\'"]keydown[\'"][\s\S]{0,900}?\'ArrowDown\'[\s\S]{0,200}?\'PageDown\'[\s\S]{0,200}?keyboard = true/', $versionsScript ) === 1 && preg_match( '/event\.key === \'Enter\'[\s\S]{0,200}?changed\(\)[\s\S]{0,120}?send\(\)/', $versionsScript ) === 1
	&& preg_match( '/addEventListener\(\s*[\'"]blur[\'"][\s\S]{0,200}?changed\(\)[\s\S]{0,60}?send\(\)/', $versionsScript ) === 1 && str_contains( $versionsScript, 'defaultSelected' ) === true );
check( '...and the page that the form leads to - the address names the form, #crm-versions - gets the focus back on the select without a jump of the scroll',
	preg_match( '/target\.id === \'crm-versions\'[\s\S]{0,200}?\.focus\(\s*\{\s*preventScroll:\s*true\s*\}\s*\)/', $portalScript ) === 1 );

// --- The gate of the client: the Datenschutz first, then the data of the invoice ----------------------------------------------------------------

echo "\nThe gate of the client\n";

$hold();

$gateText = "Wir speichern Deine Daten für das Projekt.\nStand 2026";
\Nino\Features::activate( $appData, 'files' );
\Nino\Features::activate( $appData, 'review' );
\Crm\Settings::set( $appData, 'privacy_text', $gateText );

// Studio Nord has taken note of this text, whatever the sections before left it at
if( \Crm\Records::privacy( $appData, $a['id'] )['agreed'] === false )
	\Crm\Records::add( $appData, [ 'kind' => 'privacy', 'client_id' => $a['id'], 'member' => [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ], 'shown' => $gateText ] );

$locationOf = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
$wanted = static fn( string $step, string $uri ): string => '/portal/'. $step. ( $uri === '/portal' ? '' : '?weiter='. rawurlencode( $uri ) );
$gClient = static fn( array $client ): array => (array) \Crm\Clients::get( $appData, $client['id'] );

/**
 *	A client made with an address alone, as the invitation of the owner makes it: its account has no name, its group a folder with a file, its client a project
 */
$newBare = static function( string $address ) use ( &$appData, $reset, $store, $put, $made, $owner, $ago ): array {

	$reset();
	$client = \Crm\Clients::add( $appData, [], [ 'email' => $address, 'name' => '', 'notify' => false ] );
	$account = (array) ( $client['first']['account'] ?? [] );
	$folder = $store::addFolder( $appData, $client['group'], 'Tor' );

	return [
		'client'  => $client,
		'account' => $account,
		'token'   => preg_match( '~t=([A-Za-z0-9_-]+)$~', (string) ( $client['first']['link'] ?? '' ), $found ) === 1 ? $found[1] : '',
		'file'    => $put( $folder, 'Tor.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 3600 ) ),
		'project' => $made( '<b>Tor</b> & Co [members-name]', $client['number'], [ 'folder' => $folder['id'] ] ),
	];
};

$g = $newBare( 'tor@example.org' );
$gPages = [ '/portal', '/portal/projekte', '/portal/projekte?projekt='. $g['project']. '&tab=angebot', '/portal/upload', '/portal/belege', '/portal/termine' ];
check( 'a client made with an address alone is called by it, is not ready for an invoice and has an invitation that leads to /portal', is_array( $g['client'] ) && $g['client']['title'] === 'tor@example.org' && $g['client']['ready'] === false && $g['account']['id'] !== '' && $g['token'] !== '' && $g['account']['name'] === '' && $g['project'] !== '' );

// pending(): the Datenschutz first, then the data, then nothing
$anna = (array) \Crm\Clients::get( $appData, $a['id'] );
\Crm\Settings::set( $appData, 'privacy_text', '' );
check( 'pending() asks for nothing of the Datenschutz where the owner keeps no text - the data is the step then, and a client that has it has nothing to do', \Crm\Portal::pending( $appData, $gClient( $g['client'] ) ) === 'data' && \Crm\Portal::pending( $appData, $anna ) === '' );
\Crm\Settings::set( $appData, 'privacy_text', $gateText );
check( '...with a text it is privacy for a client that has not taken note of it, whatever else is missing - the Datenschutz comes first -, and nothing for one that has and has its data', \Crm\Portal::pending( $appData, $gClient( $g['client'] ) ) === 'privacy' && \Crm\Portal::pending( $appData, $anna ) === '' );
\Crm\Settings::set( $appData, 'privacy_text', $gateText. ' Noch ein Satz.' );
check( '...a text that was changed is asked again, even of a client that has everything else', \Crm\Portal::pending( $appData, $anna ) === 'privacy' );
\Crm\Settings::set( $appData, 'privacy_text', $gateText );
check( '...and the text that is back is the text that was confirmed', \Crm\Portal::pending( $appData, $anna ) === '' );

// a visitor, a member of no client and a client with nothing pending: the pages answer as they did
$asVisitor();
$visitor = portalServe( $appData, '/portal/projekte' );
check( 'a visitor is not touched by the gate: the page answers 401 with the login in place, as the gate of Members has it - and no redirect', $visitor['statusCode'] === 401 && $locationOf( $visitor ) === '' );
portalSignIn( $appData, $stray );
check( '...nor is a member of no client', array_unique( array_map( static fn( string $uri ): int => portalServe( $appData, $uri )['statusCode'], $gPages ) ) === [ 200 ] );
portalSignIn( $appData, $accounts['anna'] );
check( '...nor a client with nothing pending: Studio Nord has taken note and has its address', array_unique( array_map( static fn( string $uri ): int => portalServe( $appData, $uri )['statusCode'], $gPages ) ) === [ 200 ] );

// the Datenschutz first
portalSignIn( $appData, $g['account'] );
$answers = array_map( static fn( string $uri ): array => [ $uri, portalServe( $appData, $uri ) ], $gPages );
check( 'a member whose client has not taken note of the Datenschutz is answered 303 on every page of the portal, to the page of the Datenschutz, with the page it wanted - path and query - in weiter, URL-encoded', array_filter( $answers, static fn( array $one ): bool => $one[1]['statusCode'] !== 303 || $locationOf( $one[1] ) !== $wanted( 'datenschutz', $one[0] ) ) === []
	&& $wanted( 'datenschutz', '/portal/projekte?projekt=X&tab=angebot' ) === '/portal/datenschutz?weiter=%2Fportal%2Fprojekte%3Fprojekt%3DX%26tab%3Dangebot' );
check( '...the redirect is not cached and has no body: nothing of the page it replaces is in it', array_filter( $answers, static fn( array $one ): bool => ( $one[1]['header']['Cache-Control'] ?? '' ) !== 'no-store' || ( $one[1]['body'] ?? '' ) !== '' ) === [] );
$onDatenschutz = portalServe( $appData, '/portal/datenschutz' );
$onData = portalServe( $appData, '/portal/meine-daten?weiter=%2Fportal%2Fbelege' );
check( '...the page of the Datenschutz answers itself; Meine Daten comes to the Datenschutz first, with itself in weiter - and where it was going on to with it', $onDatenschutz['statusCode'] === 200 && $onData['statusCode'] === 303 && $locationOf( $onData ) === $wanted( 'datenschutz', '/portal/meine-daten?weiter=%2Fportal%2Fbelege' )
	&& $locationOf( portalServe( $appData, '/portal/meine-daten' ) ) === $wanted( 'datenschutz', '/portal/meine-daten' ) );
check( '...a query that is no flat text is left out of weiter - the page it was is what it gets -, and a weiter of its own is kept on the way to the same place', $locationOf( portalServe( $appData, '/portal/belege?a[]=1&b=2&weiter=%2Fportal' ) ) === $wanted( 'datenschutz', '/portal/belege?b=2&weiter=%2Fportal' ) );
$logout = portalServe( $appData, '/.members/logout', 'POST' );
check( '...the sign-out is no page of the portal: it signs the member out and goes where it goes, not to the Datenschutz', $logout['statusCode'] === 303 && str_contains( $locationOf( $logout ), 'datenschutz' ) === false && \Nino\Modules\Members::current( $appData ) === null );
portalSignIn( $appData, $g['account'] );

// the file route is closed before the gate, for the view and the download alike, and the owner is not asked
$closed = [ portalServe( $appData, '/.files/'. $g['file']['id'] ), portalServe( $appData, '/.files/'. $g['file']['id']. '?download=1' ) ];
check( 'the file route is 404 for a member whose gate is pending - inline and as a download, no file named, the plain 404 of a stranger - and allowed() says no', array_filter( $closed, static fn( array $one ): bool => $one['statusCode'] !== 404 || ( $one['body'] ?? '' ) !== 'Not Found' || isset( $one['file'] ) === true ) === []
	&& \Nino\Modules\Files::allowed( $appData, $g['file'] ) === false && \Nino\Modules\Files::allowed( $appData, $g['file'], true ) === false );
$asAdmin();
$ownerFile = portalServe( $appData, '/.files/'. $g['file']['id'] );
check( '...the owner of the workbench opens it all the same, by the permission of the panel', $ownerFile['statusCode'] === 200 && ( $ownerFile['file'] ?? '' ) === \Nino\Modules\Files::path( $appData, $g['file']['id'] ) );
portalSignIn( $appData, $accounts['anna'] );
check( '...and the file of another group is 404 for a member whose gate is open, as it was', portalServe( $appData, '/.files/'. $g['file']['id'] )['statusCode'] === 404 );
portalSignIn( $appData, $g['account'] );

// the note over the Datenschutz and what the page carries on
$onward = '/portal/projekte?projekt='. $g['project'];
$page = portalPage( $appData, '/portal/datenschutz?weiter='. rawurlencode( $onward ) )[1];
$note = portalFind( $page, '//p[contains(@class, "crm-gate--privacy")]' );
check( 'the page of the Datenschutz says over its text what is asked first: a note in the info tone with the shield, a status - and, when the member wanted a page, where it goes on to after it: the title of the project as text, never the path', count( $note ) === 1 && str_contains( $note[0]->getAttribute( 'class' ), 'crm-note--info' ) === true && $note[0]->getAttribute( 'role' ) === 'status'
	&& portalFind( $page, '//p[contains(@class, "crm-gate--privacy")]/svg[contains(@class, "crm-icon--shield-check")]' ) !== [] && strpos( $page, 'crm-gate--privacy' ) < strpos( $page, 'crm-privacy__text' ) && portalText( $page, '//p[contains(@class, "crm-gate--privacy")]//span[contains(@class, "crm-gate__next")]/strong' ) === '<b>Tor</b> & Co [members-name]'
	&& str_contains( $page, '&lt;b&gt;Tor&lt;/b&gt; &amp; Co &#91;members-name]' ) === true && portalFind( $page, '//p[contains(@class, "crm-gate--privacy")]//a' ) === [] && portalLeft( $page ) === false && str_contains( portalText( $page, '//p[contains(@class, "crm-gate--privacy")]' ), $g['project'] ) === false );
check( '...the form carries it in a hidden field weiter - the path and the query as they were asked, escaped - and has none where the address names none, nor a second sentence', portalFind( $page, '//form[contains(@class, "crm-privacy__form")]//input[@type="hidden"][@name="weiter"]/@value' )[0]->nodeValue === $onward
	&& portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//input[@name="weiter"]' ) === [] && portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//span[contains(@class, "crm-gate__next")]' ) === [] && count( portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//p[contains(@class, "crm-gate--privacy")]' ) ) === 1 );
$areas = [ '/portal', '/portal/projekte', '/portal/belege', '/portal/upload', '/portal/termine', '/portal/meine-daten' ];
$named = array_map( static fn( string $weiter ): string => portalText( portalPage( $appData, '/portal/datenschutz?weiter='. rawurlencode( $weiter ) )[1], '//span[contains(@class, "crm-gate__next")]/strong' ), $areas );
check( '...the next page is named by the words of the pages - the start page, Projekte, Belege, Upload, Termine and Meine Daten - and a page the portal does not have, or a project that is none of this client\'s, is not named', $named === array_map( static fn( string $uri ): string => $unitDe['[[/_nino/webpage'. $uri. '/name]]'], $areas )
	&& portalFind( portalPage( $appData, '/portal/datenschutz?weiter='. rawurlencode( '/portal/gibt-es-nicht' ) )[1], '//span[contains(@class, "crm-gate__next")]' ) === []
	&& portalText( portalPage( $appData, '/portal/datenschutz?weiter='. rawurlencode( '/portal/projekte?projekt='. $p1 ) )[1], '//span[contains(@class, "crm-gate__next")]/strong' ) === $unitDe['[[/_nino/webpage/portal/projekte/name]]'] );

// a weiter that is no path of the portal is nobody's: not a field, not a name, not a target - on the page and in the post
$probes = [ '//evil.example/x', 'https://evil.example/', 'http://evil.example', 'javascript:alert(1)', '/crm', '/crm#kunden', '\\evil.example', '/\\evil.example', "/portal\0/evil.example", '%2F%2Fevil.example', '/portal/../crm', '/portal/..%2Fcrm', '/portal/%2e%2e/crm', '/portalx', 'portal/projekte', ' /portal', '/portal/projekte?x=<b>',
	'/portal/projekte?x=1&y[]=2', '/.members/logout', '/portal//evil.example', "/portal/projekte\r\nSet-Cookie: x=1", str_repeat( '/portal/a', 60 ), '' ];
$reflected = [];
$kept = [];

foreach( $probes as $probe ) {
	$view = portalPage( $appData, '/portal/datenschutz?weiter='. str_replace( '%00', "\0", rawurlencode( $probe ) ) )[1];
	$answer = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'weiter' => $probe ] );

	if( portalFind( $view, '//input[@name="weiter"]' ) !== [] || portalFind( $view, '//span[contains(@class, "crm-gate__next")]' ) !== [] || str_contains( $view, 'evil.example' ) === true || str_contains( $view, 'Set-Cookie' ) === true || str_contains( $view, '/crm' ) === true )
		$reflected[] = $probe;

	if( $locationOf( $answer ) !== '/portal/datenschutz?state=unchecked' )
		$kept[$probe] = $locationOf( $answer );
}

check( 'a weiter that is no path under /portal - a host, a scheme, a second slash, a backslash, /crm, a dot segment (plain or encoded), a null byte, a line break, an endpoint, a query that is none, one too long - is never put in the page as a field, a name or an address', $reflected === [] );
check( '...and a post that carries one answers as a post with none does: the state of the page, the Location holds nothing of it', $kept === [] );
$goodWeiter = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'weiter' => $onward ] );
check( '...while a good one is kept through a refusal of the post: the state, then weiter, encoded', $locationOf( $goodWeiter ) === '/portal/datenschutz?state=unchecked&weiter='. rawurlencode( $onward ) );

// the data does not go through before the Datenschutz: nothing is saved, nobody is mailed, no account is named
$pre = $newBare( 'vera@example.org' );
portalSignIn( $appData, $pre['account'] );
$preMails = count( $mails );
$preSaved = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Vera Vor', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE', 'weiter' => '/portal/belege' ] );
$preBare = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Vera Vor', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE' ] );
check( 'a post of the data while the Datenschutz is pending saves nothing, mails nobody and names no account: 303 to the Datenschutz, whose weiter is Meine Daten with the page it was going on to', $locationOf( $preSaved ) === $wanted( 'datenschutz', '/portal/meine-daten?weiter=%2Fportal%2Fbelege' ) && $locationOf( $preBare ) === $wanted( 'datenschutz', '/portal/meine-daten' )
	&& $gClient( $pre['client'] )['street'] === '' && $gClient( $pre['client'] )['name'] === '' && (string) \Nino\Modules\Members::account( $appData, $pre['account']['id'] )['name'] === '' && count( $mails ) === $preMails && $gClient( $pre['client'] )['ready'] === false );

// the Datenschutz confirmed with a weiter that is nobody's: the next step, nothing else
$h = $newBare( 'hanne@example.org' );
portalSignIn( $appData, $h['account'] );
$confirmed = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'weiter' => '//evil.example' ] );
$records = $recordsOf( $h['client']['id'] );
check( 'the Datenschutz confirmed goes on to the next step - the data -, and a weiter that is no path is not carried there: ?weiter= is not in the Location', $locationOf( $confirmed ) === '/portal/meine-daten' && count( $records ) === 1 && $records[0]['kind'] === 'privacy' && \Crm\Portal::pending( $appData, $gClient( $h['client'] ) ) === 'data' );

// the data is the step now: the pages go to it, it does not go back
$toData = array_map( static fn( string $uri ): array => [ $uri, portalServe( $appData, $uri ) ], [ '/portal', '/portal/belege?x=1' ] );
check( 'with the Datenschutz done and the data missing the pages go to Meine Daten with weiter; Meine Daten and the Datenschutz answer themselves - the data does not send a client back to the Datenschutz', array_filter( $toData, static fn( array $one ): bool => $one[1]['statusCode'] !== 303 || $locationOf( $one[1] ) !== $wanted( 'meine-daten', $one[0] ) ) === [] && portalServe( $appData, '/portal/meine-daten' )['statusCode'] === 200 && portalServe( $appData, '/portal/datenschutz' )['statusCode'] === 200 );
$dataPage = portalPage( $appData, '/portal/meine-daten?weiter='. rawurlencode( '/portal/belege' ) )[1];
$dataNote = portalFind( $dataPage, '//p[contains(@class, "crm-gate--data")]' );
check( 'Meine Daten says over its form what is asked: a note in the info tone with the id card, a status - and where it goes on to after it; no sentence of the Datenschutz', count( $dataNote ) === 1 && str_contains( $dataNote[0]->getAttribute( 'class' ), 'crm-note--info' ) === true && $dataNote[0]->getAttribute( 'role' ) === 'status' && portalFind( $dataPage, '//p[contains(@class, "crm-gate--data")]/svg[contains(@class, "crm-icon--id-card")]' ) !== []
	&& portalText( $dataPage, '//span[contains(@class, "crm-gate__next")]/strong' ) === $unitDe['[[/_nino/webpage/portal/belege/name]]'] && portalFind( $dataPage, '//p[contains(@class, "crm-gate--privacy")]' ) === [] && strpos( $dataPage, 'crm-gate--data' ) < strpos( $dataPage, 'crm-data__form' )
	&& portalFind( $dataPage, '//form[contains(@class, "crm-data__form")]//input[@type="hidden"][@name="weiter"]/@value' )[0]->nodeValue === '/portal/belege' && portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//input[@name="weiter"]' ) === [] );
$required = portalFind( $dataPage, '//form[contains(@class, "crm-data__form")]//*[@required]' );
check( 'street, postal code, city and country are required - the attribute, aria-required and a star on the label that a reader does not read - and company, name, phone and VAT id are not', array_map( static fn( \DOMElement $control ): string => $control->getAttribute( 'name' ), $required ) === [ 'street', 'zip', 'city', 'country' ]
	&& array_filter( $required, static fn( \DOMElement $control ): bool => $control->getAttribute( 'aria-required' ) !== 'true' ) === [] && array_map( static fn( \DOMElement $label ): string => $label->getAttribute( 'for' ), portalFind( $dataPage, '//label[span[contains(@class, "crm-field__star")][@aria-hidden="true"]]' ) ) === [ 'crm-data-street', 'crm-data-zip', 'crm-data-city', 'crm-data-country' ]
	&& portalFind( $dataPage, '//label[@for="crm-data-company" or @for="crm-data-name" or @for="crm-data-phone" or @for="crm-data-vat_id"]//span[contains(@class, "crm-field__star")]' ) === [] && portalFind( $dataPage, '//input[@name="name"]/@value' )[0]->nodeValue === '' );

// the data refused: the first field that is empty, and the sentence beside it
$hFields = [ 'company' => '', 'name' => 'Hanne Haas', 'street' => 'Ring 3', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE', 'phone' => '', 'vat_id' => '' ];
$refusedData = [];

foreach( [ 'street' => [ 'street' => '  ' ], 'zip' => [ 'zip' => '' ], 'city' => [ 'city' => " \t" ], 'country' => [ 'country' => '' ], 'name' => [ 'name' => '  ', 'company' => ' ' ] ] as $field => $over ) {
	$answer = portalServe( $appData, '/.crm/daten', 'POST', $over + $hFields + [ 'weiter' => '/portal/belege' ] );
	$refusedData[$field] = [ $answer['statusCode'], $locationOf( $answer ) ];
}

check( 'the server asks for the four fields and for a name or a company, whatever the form sent: an empty one is ?state=invalid&field=<name> - the first that is empty -, the weiter kept in the Location, nothing saved', array_filter( $refusedData, static fn( array $one, string $field ): bool => $one !== [ 303, '/portal/meine-daten?state=invalid&field='. $field. '&why='. ( $field === 'name' ? 'name' : 'required' ). '&weiter='. rawurlencode( '/portal/belege' ) ], ARRAY_FILTER_USE_BOTH ) === [] && $gClient( $h['client'] )['street'] === ''
	&& $locationOf( portalServe( $appData, '/.crm/daten', 'POST', [ 'street' => '', 'zip' => '', 'city' => '', 'country' => '', 'name' => '' ] ) ) === '/portal/meine-daten?state=invalid&field=name&why=name' && $locationOf( portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'X', 'zip' => '1', 'city' => 'Y', 'country' => 'DE' ] ) ) === '/portal/meine-daten?state=invalid&field=street&why=required'
	&& $locationOf( portalServe( $appData, '/.crm/daten', 'POST', [ 'zip' => 'a!b' ] + $hFields ) ) === '/portal/meine-daten?state=invalid&field=zip&why=format' && $locationOf( portalServe( $appData, '/.crm/daten', 'POST', [ 'weiter' => '//evil.example', 'street' => '' ] + $hFields ) ) === '/portal/meine-daten?state=invalid&field=street&why=required' );
$refusedPage = portalPage( $appData, '/portal/meine-daten?state=invalid&field=street&why=required&weiter='. rawurlencode( '/portal/belege' ) )[1];
$refusedName = portalPage( $appData, '/portal/meine-daten?state=invalid&field=name&why=name' )[1];
$refusedZip = portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip&why=required' )[1];
check( '...the page marks the field and says beside it what is wrong - that it is empty, for an empty required field; that name or company is wanted, for the pair; to check the entry, for a field that has its value - and keeps the page it goes on to', portalFind( $refusedPage, '//input[@name="street"]/@aria-invalid' )[0]->nodeValue === 'true' && portalText( $refusedPage, '//span[@id="crm-data-street-error"]' ) === $wordsDe['[[/project/crm-portal/data/field-required]]']
	&& portalText( $refusedName, '//span[@id="crm-data-name-error"]' ) === $wordsDe['[[/project/crm-portal/data/field-name]]'] && portalText( $refusedZip, '//span[@id="crm-data-zip-error"]' ) === $wordsDe['[[/project/crm-portal/data/field-required]]'] && portalFind( $refusedPage, '//input[@name="weiter"]/@value' )[0]->nodeValue === '/portal/belege'
	&& $wordsDe['[[/project/crm-portal/data/field-required]]'] !== $wordsDe['[[/project/crm-portal/data/field-error]]'] && $wordsEn['[[/project/crm-portal/data/field-required]]'] !== '' && $wordsEn['[[/project/crm-portal/data/field-name]]'] !== '' );

$whyText = static fn( string $why ): string => portalText( portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip&why='. rawurlencode( $why ) )[1], '//span[@id="crm-data-zip-error"]' );
check( '...the sentence is chosen by the reason in the address - required, name, long, format - each its own words in both languages, and a reason that is none, empty or markup says the generic sentence and is never put on the page', $whyText( 'required' ) === $wordsDe['[[/project/crm-portal/data/field-required]]'] && $whyText( 'name' ) === $wordsDe['[[/project/crm-portal/data/field-name]]'] && $whyText( 'long' ) === $wordsDe['[[/project/crm-portal/data/field-long]]'] && $whyText( 'format' ) === $wordsDe['[[/project/crm-portal/data/field-error]]']
	&& array_unique( [ $whyText( '<b>' ), $whyText( '' ), $whyText( 'x' ), $whyText( 'REQUIRED' ) ] ) === [ $wordsDe['[[/project/crm-portal/data/field-error]]'] ] && str_contains( portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip&why=%3Cb%3E' )[1], '<b>' ) === false
	&& count( array_unique( [ $wordsDe['[[/project/crm-portal/data/field-required]]'], $wordsDe['[[/project/crm-portal/data/field-name]]'], $wordsDe['[[/project/crm-portal/data/field-long]]'], $wordsDe['[[/project/crm-portal/data/field-error]]'] ] ) ) === 4 && $wordsEn['[[/project/crm-portal/data/field-long]]'] !== '' );

// the data saved: the name of the client becomes the name of the account that has none - and goes on to the page
$beforeName = (string) \Nino\Modules\Members::account( $appData, $h['account']['id'] )['name'];
$savedData = portalServe( $appData, '/.crm/daten', 'POST', $hFields + [ 'weiter' => '/portal/belege' ] );
$afterName = (string) \Nino\Modules\Members::account( $appData, $h['account']['id'] )['name'];
check( 'the data saved with nothing pending after it goes on to the page the member wanted, and not to a state; the name of the client is the name of the account that had none, and the chip of the bar reads it', $beforeName === '' && $locationOf( $savedData ) === '/portal/belege' && $afterName === 'Hanne Haas' && \Crm\Portal::pending( $appData, $gClient( $h['client'] ) ) === ''
	&& \Crm\Portal::nameOf( (array) \Nino\Modules\Members::current( $appData ) ) === 'Hanne Haas' && portalServe( $appData, '/portal/belege' )['statusCode'] === 200 );
check( '...the files of the group are served now, inline and as a download - the 404 was the gate\'s', portalServe( $appData, '/.files/'. $h['file']['id'] )['statusCode'] === 200 && portalServe( $appData, '/.files/'. $h['file']['id']. '?download=1' )['statusCode'] === 200 && \Nino\Modules\Files::allowed( $appData, $h['file'] ) === true );
$sameAgain = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Hanne Neu' ] + $hFields );
check( '...a name the account has is never overwritten: a different name of the client later leaves the account as it is - and without a weiter the form answers its own page with its state', (string) \Nino\Modules\Members::account( $appData, $h['account']['id'] )['name'] === 'Hanne Haas' && $locationOf( $sameAgain ) === '/portal/meine-daten?state=saved' && $gClient( $h['client'] )['name'] === 'Hanne Neu' );

// a client with a company and no name: the account keeps no name
$k = $newBare( 'kai@example.org' );
portalSignIn( $appData, $k['account'] );
portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
$companyOnly = portalServe( $appData, '/.crm/daten', 'POST', [ 'company' => 'Kai GmbH', 'name' => '', 'street' => 'Allee 4', 'zip' => '70173', 'city' => 'Stuttgart', 'country' => 'DE' ] );
check( 'a company without a name leaves the name of the account empty - the bar reads the address then - and the save of the last step goes on to where something waits (the landing) with its state', $locationOf( $companyOnly ) === \Crm\Portal::saved( \Crm\Portal::landing( $appData, $gClient( $k['client'] ) ) ) && (string) \Nino\Modules\Members::account( $appData, $k['account']['id'] )['name'] === '' && \Crm\Portal::nameOf( (array) \Nino\Modules\Members::current( $appData ) ) === 'kai@example.org' && $gClient( $k['client'] )['title'] === 'Kai GmbH' );
$longName = str_repeat( 'ä', 120 );
$l = $newBare( 'lena@example.org' );
portalSignIn( $appData, $l['account'] );
portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => $longName, 'street' => 'Allee 4', 'zip' => '70173', 'city' => 'Stuttgart', 'country' => 'DE' ] );
check( 'a name of the longest length the client takes is the name of the account as it is', (string) \Nino\Modules\Members::account( $appData, $l['account']['id'] )['name'] === $longName );

// the whole way as the first client walks it: the invitation, the Datenschutz, the data, the page
$i = $newBare( 'ines@example.org' );
$_SESSION = [];
unset( $appData['./nino/auth/current'] );
$opened = portalServe( $appData, '/.members/login?t='. $i['token'] );
$signed = portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $i['token'] ] );
$step1 = portalServe( $appData, $locationOf( $signed ) );
$step2 = portalServe( $appData, $locationOf( $step1 ) );
$step3 = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1' ] );
$step4 = portalServe( $appData, $locationOf( $step3 ) );
$step5 = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Ines Ide', 'street' => 'Platz 1', 'zip' => '01067', 'city' => 'Dresden', 'country' => 'DE' ] );
$step6 = portalServe( $appData, $locationOf( $step5 ) );
check( 'the invitation, the Datenschutz, the data, the page - in one session: the link signs in and leads to /portal, which goes to the Datenschutz - the start page is no page to carry on to, so no weiter -; the confirmation goes to Meine Daten; the data is saved and the member is led to the landing with the note, which answers', $opened['statusCode'] === 200 && $signed['statusCode'] === 303 && $locationOf( $signed ) === '/portal' && $step1['statusCode'] === 303 && $locationOf( $step1 ) === $wanted( 'datenschutz', '/portal' ) && $step2['statusCode'] === 200
	&& $locationOf( $step3 ) === $wanted( 'meine-daten', '/portal' ) && $step4['statusCode'] === 200 && $locationOf( $step5 ) === \Crm\Portal::saved( \Crm\Portal::landing( $appData, $gClient( $i['client'] ) ) ) && $step6['statusCode'] === 200 && \Crm\Portal::pending( $appData, $gClient( $i['client'] ) ) === '' && \Crm\Portal::nameOf( (array) \Nino\Modules\Members::current( $appData ) ) === 'Ines Ide' );
check( '...the client is called by its name and has what an invoice needs: ready for an offer and an invoice', $gClient( $i['client'] )['title'] === 'Ines Ide' && $gClient( $i['client'] )['ready'] === true );

check( '...after the gate the notes are gone: neither page of a step says what is asked first, the Datenschutz says when it was confirmed and Meine Daten has no weiter', portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//*[contains(@class, "crm-gate")]' ) === [] && portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//*[contains(@class, "crm-gate")] | //input[@name="weiter"]' ) === []
	&& portalFind( portalPage( $appData, '/portal/datenschutz' )[1], '//p[contains(@class, "crm-privacy__standing--agreed")]' ) !== [] );

// a text that was changed asks again, of a client that has everything else: the gate is at the Datenschutz, and Meine Daten goes there
\Crm\Settings::set( $appData, 'privacy_text', $gateText. ' Und noch etwas.' );
portalSignIn( $appData, $accounts['anna'] );
check( 'a client with its data whose Datenschutz changed is sent to the Datenschutz from every page, Meine Daten included - and the pages answer again once it is confirmed', $locationOf( portalServe( $appData, '/portal/belege' ) ) === $wanted( 'datenschutz', '/portal/belege' ) && $locationOf( portalServe( $appData, '/portal/meine-daten' ) ) === $wanted( 'datenschutz', '/portal/meine-daten' )
	&& $locationOf( portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'weiter' => '/portal/belege' ] ) ) === '/portal/belege' && portalServe( $appData, '/portal/belege' )['statusCode'] === 200 );

// what the gate leaves out of the log: no address
check( 'the warnings of the whole way hold no address of a client', array_filter( ninoWarnings(), static fn( string $line ): bool => preg_match( '/@example\.org/', $line ) === 1 ) === [] );

check( '...and it puts the focus on the field of Meine Daten that the server marked invalid, once the page is drawn - the script asks for aria-invalid in the form of the data and focuses it, before the address loses its state', preg_match( '/function focusRefused\(\)\s*\{[^}]*querySelector\(\s*\'\.crm-data__form \[aria-invalid="true"\]\'\s*\)[^}]*\.focus\(\)/s', $script ) === 1 && strpos( $script, 'focusRefused();' ) !== false && strpos( $script, 'focusRefused();' ) < strpos( $script, 'forgetState();', (int) strpos( $script, 'function init()' ) ) );

// --- Where a client lands after the sign-in, and the messages it reads -------------------------------------------------------------------------

echo "\nThe landing and the messages\n";

\Crm\Settings::set( $appData, 'privacy_text', '' );
$asAdmin();
$start = (string) \Nino\Features::setting( $appData, 'members', 'start', '/' );

/**
 *	Sign in by a link the way a member does, and say where the button sends the member
 */
$signInTo = static function( array $account, string $target = '' ) use ( &$appData, $locationOf ): string {

	global $mails;

	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	$count = count( $mails );

	if( \Nino\Modules\Members::link( $appData, $account, 'login', $target ) !== true || count( $mails ) !== $count + 1 || preg_match( '~t=([A-Za-z0-9_-]+)"~', (string) portalMail( $count )['body'], $found ) !== 1 )
		return '';

	portalServe( $appData, '/.members/login?t='. $found[1] );

	return $locationOf( portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $found[1] ] ) );
};

/**
 *	Sign in with a code the way a member does: the form is posted, the digits are read out of the mail, the code is posted
 */
$codeIn = static function( array $account, string $from = '' ) use ( &$appData, $locationOf ): string {

	global $mails;

	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	$count = count( $mails );

	// A visitor who asked for a page of the portal is shown the login there, and the gate keeps the page
	if( $from !== '' )
		portalServe( $appData, $from );

	portalServe( $appData, '/.members/login', 'POST', [ 'email' => $account['email'], 'return' => $from === '' ? '/' : '/portal' ] );

	if( count( $mails ) !== $count + 1 || preg_match( '~>\s*([0-9]{3}) ([0-9]{3})\s*</p>~', (string) portalMail( $count )['body'], $found ) !== 1 )
		return '';

	return $locationOf( portalServe( $appData, '/.members/code', 'POST', [ 'code' => $found[1]. $found[2], 'return' => '/' ] ) );
};

$lc = \Crm\Clients::add( $appData, [ 'company' => 'Landung GmbH', 'street' => 'Weg 1', 'zip' => '01067', 'city' => 'Dresden', 'country' => 'DE' ], [ 'email' => 'landung@example.org', 'name' => 'Lena Land', 'notify' => false ] );
$lAccount = (array) ( $lc['first']['account'] ?? [] );
$lClient = static fn(): array => (array) \Crm\Clients::get( $appData, $lc['id'] );
$folderOld = $store::addFolder( $appData, $lc['group'], 'Alt' );
$folderNew = $store::addFolder( $appData, $lc['group'], 'Neu' );
$folderDone = $store::addFolder( $appData, $lc['group'], 'Fertig' );
$pOld = $made( 'Altes Projekt', $lc['number'], [ 'started' => '2026-01-01', 'folder' => $folderOld['id'] ] );
$pPlain = $made( 'Anderes Projekt', $lc['number'], [ 'started' => '2026-02-01', 'folder' => $folderNew['id'] ] );
$pDone = $made( 'Fertiges Projekt', $lc['number'], [ 'started' => '2026-03-01', 'folder' => $folderDone['id'], 'done' => true ] );
$put( $folderOld, 'Alt.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-03-01T09:00:00Z' );
$put( $folderNew, 'Versteckt.mp3', 'mp3', 'audio/mpeg', false, $owner, '2026-12-30T09:00:00Z' );
$put( $folderDone, 'Fertig.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-12-24T09:00:00Z' );
$dateien = static fn( string $id ): string => '/portal/projekte?projekt='. $id. '&tab=dateien';
portalSignIn( $appData, $lAccount );

check( 'the fixtures: a client that is ready, with two open projects and a done one, and nothing pending', is_array( $lc ) && $lc['ready'] === true && $lAccount['id'] !== '' && $pOld !== '' && $pPlain !== '' && $pDone !== '' && \Crm\Portal::pending( $appData, $lClient() ) === '' );
check( 'the client lands on the files of the open project that was last active: the file of the owner that the member may not see does not count, a done project does not', \Crm\Portal::landing( $appData, $lClient() ) === $dateien( $pOld ) );
$put( $folderNew, 'Neu.mp3', 'mp3', 'audio/mpeg', true, $owner, '2026-04-01T09:00:00Z' );
check( '...a file in the folder of another project moves the landing there', \Crm\Portal::landing( $appData, $lClient() ) === $dateien( $pPlain ) );
$lInvoice = $final( 'invoice', $lc['id'], [ 'date' => $today, 'project' => $pOld, 'template_key' => 'rechnung', 'lines' => [ $line( 'Rechnung' ) ] ] );
Documents::release( $appData, $lInvoice['id'], false );
check( '...so does the release of a document of a project; an invoice is nothing that waits for an order', \Crm\Portal::landing( $appData, $lClient() ) === $dateien( $pOld ) );
Db::exec( $appData, 'UPDATE documents SET released = 0 WHERE id = :id', [ 'id' => $lInvoice['id'] ] );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => '' ] );
check( '...while the gate has the data pending the client lands nowhere in particular: the start page', \Crm\Portal::pending( $appData, $lClient() ) === 'data' && \Crm\Portal::landing( $appData, $lClient() ) === '' );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => 'Weg 1' ] );
\Crm\Settings::set( $appData, 'privacy_text', 'Ein Text' );
check( '...and the Datenschutz pending, the same', \Crm\Portal::pending( $appData, $lClient() ) === 'privacy' && \Crm\Portal::landing( $appData, $lClient() ) === '' );
\Crm\Settings::set( $appData, 'privacy_text', '' );

$offerNo = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Ohne Projekt' ) ] ] );
Documents::release( $appData, $offerNo['id'], false );
check( 'an offer that waits is where the client lands, before any project: an offer without a project is its row in the list of documents', \Crm\Portal::landing( $appData, $lClient() ) === '/portal/belege#beleg-'. $offerNo['id'] );
$offerP = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'project' => $pPlain, 'lines' => [ $line( 'Mit Projekt' ) ] ] );
Documents::release( $appData, $offerP['id'], false );
check( '...the newest of two is the one: an offer of a project is its card on the page of the project, in the section Angebot', \Crm\Portal::landing( $appData, $lClient() ) === '/portal/projekte?projekt='. $pPlain. '&tab=angebot#angebot-'. $offerP['id'] );
$offerExpired = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '-1 day' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Abgelaufen' ) ] ] );
$offerDeclined = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Abgelehnt' ) ] ] );
$offerAccepted = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Angenommen' ) ] ] );
$offerHidden = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Nicht freigegeben' ) ] ] );

foreach( [ $offerExpired, $offerDeclined, $offerAccepted ] as $each )
	Documents::release( $appData, $each['id'], false );

Documents::setState( $appData, $offerDeclined['id'], 'declined' );
Documents::setState( $appData, $offerAccepted['id'], 'accepted' );
check( '...an offer that ran out, one that was declined, one that was accepted and one that is not released are newer and wait for nothing: the landing stays', \Crm\Portal::landing( $appData, $lClient() ) === '/portal/projekte?projekt='. $pPlain. '&tab=angebot#angebot-'. $offerP['id']
	&& array_map( static fn( array $document ): bool => Documents::orderable( $document ), array_column( Documents::released( $appData, $lc['id'] ), null, 'id' ) )[$offerExpired['id']] === false );

check( 'the module answers /members/start, once', array_values( array_filter( \Nino\Callbacks::registered( $appData, '/members/start' ), static fn( mixed $callback ): bool => $callback === [ \Crm\Portal::class, 'callbackStart' ] ) ) === [ [ \Crm\Portal::class, 'callbackStart' ] ] );

$toOffer = '/portal/projekte?projekt='. $pPlain. '&tab=angebot#angebot-'. $offerP['id'];
check( 'a client that signs in with a link and asked for no page lands on the offer that waits', $signInTo( $lAccount ) === $toOffer );
check( '...and so does one that signs in with a code', $codeIn( $lAccount ) === $toOffer );
check( '...also one that came for the start page of the portal itself - a bookmark, which the gate keeps as the page it was asked for - by a code and by a link that carries it: the start page is no page of its own', $codeIn( $lAccount, '/portal' ) === $toOffer && $signInTo( $lAccount, '/portal' ) === $toOffer
	&& $signInTo( $lAccount, '/portal?x=1' ) === '/portal?x=1' && $codeIn( $lAccount, '/portal/projekte' ) === '/portal/projekte' );
$resetCaps = static function() use ( &$appData ): void {
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
	\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
};
$resetCaps();
$_SESSION = [];
unset( $appData['./nino/auth/current'] );
$resent = \Crm\Clients::resend( $appData, $lAccount, false );
$resentToken = is_string( $resent ) === true && preg_match( '~t=([A-Za-z0-9_-]+)$~', $resent, $resentFound ) === 1 ? $resentFound[1] : '';
portalServe( $appData, '/.members/login?t='. $resentToken );
$resentRecord = (array) ( \Nino\Filesystem::getFileContent( $appData, '/data/members-tokens.php', [] )[ hash( 'sha256', $resentToken ) ] ?? [] );
check( '...and a link that the owner sends with no page named leads where something waits: resend() and invite() have no target of their own', $resentToken !== '' && ( $resentRecord['target'] ?? null ) === '' && $locationOf( portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $resentToken ] ) ) === $toOffer );
$bare = [ 'account' => $lAccount, 'target' => '/portal', 'to' => '/portal' ];
\Crm\Portal::callbackStart( $appData, $bare );
check( '...callbackStart() takes exactly /portal for no target: not /portal with a query, a place or a longer path', $bare['to'] === $toOffer && array_filter( [ '/portal?x=1', '/portal#a', '/portal/', '/portal/belege', '/portalx' ], static function( string $target ) use ( &$appData, $lAccount ): bool {
	$args = [ 'account' => $lAccount, 'target' => $target, 'to' => $target ];
	\Crm\Portal::callbackStart( $appData, $args );

	return $args['to'] !== $target;
} ) === [] );
check( '...a page it asked for goes before: a link that carries one, with or without a query', $signInTo( $lAccount, '/portal/upload' ) === '/portal/upload' && $signInTo( $lAccount, '/portal/projekte?review=0123456789ab' ) === '/portal/projekte?review=0123456789ab' );
check( '...a member who is no client\'s is led where the sign-in leads without the module: the start page', $signInTo( $stray ) === $start );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => '' ] );
check( '...a client with a step of the gate pending lands on the start page, which the gate leads on, with no weiter of its own', $signInTo( $lAccount ) === $start );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => 'Weg 1' ] );
$noAccount = [ 'target' => '', 'to' => '/' ];
\Crm\Portal::callbackStart( $appData, $noAccount );
$withTarget = [ 'account' => $lAccount, 'target' => '/portal/upload', 'to' => '/portal/upload' ];
\Crm\Portal::callbackStart( $appData, $withTarget );
check( '...nothing is changed where there is no account in the arguments, or where there is a target', $noAccount['to'] === '/' && $withTarget['to'] === '/portal/upload' );

// The offer is ordered: the client has nothing waiting, and lands on its project that was last active
Documents::setState( $appData, $offerNo['id'], 'declined' );
Documents::setState( $appData, $offerP['id'], 'declined' );
check( 'once nothing waits any more the client lands on its project again', $signInTo( $lAccount ) === $dateien( $pPlain ) );

// The mails lead to the row of the document: the card of an offer of a project, the row of any other document of a project, the row in the list for a document without one
$mailOffer = $final( 'offer', $lc['id'], [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'project' => $pPlain, 'lines' => [ $line( 'Mail Angebot' ) ] ] );
$mailInvoice = $final( 'invoice', $lc['id'], [ 'date' => $today, 'template_key' => 'rechnung', 'project' => $pOld, 'lines' => [ $line( 'Mail Rechnung' ) ] ] );
$mailPlain = $final( 'invoice', $lc['id'], [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( 'Mail ohne Projekt' ) ] ] );
$linkOfMail = static function( array $mail ): string {
	return preg_match( '~href="(https://example\.org/club/portal/[^"]*)"~', (string) ( $mail['body'] ?? '' ), $found ) === 1 ? $found[1] : '';
};
$links = [];

foreach( [ 'offer of a project' => [ $mailOffer, 'https://example.org/club/portal/projekte?projekt='. $pPlain. '&amp;tab=angebot#angebot-'. $mailOffer['id'] ], 'invoice of a project' => [ $mailInvoice, 'https://example.org/club/portal/projekte?projekt='. $pOld. '&amp;tab=belege#beleg-'. $mailInvoice['id'] ], 'invoice without one' => [ $mailPlain, 'https://example.org/club/portal/belege#beleg-'. $mailPlain['id'] ] ] as $label => [ $document, $expected ] ) {
	$reset();
	$before = count( $mails );
	$released = Documents::release( $appData, $document['id'], true );
	$sentMails = portalMailsFrom( $before );
	$links[$label] = is_array( $released ) && $released['mailed'] === 1 && count( $sentMails ) === 1 && $linkOfMail( $sentMails[0] ) === $expected && $sentMails[0]['to'] === 'landung@example.org';
}

check( 'the mail of a release links the document: the card of an offer of a project in its section Angebot, the row of an invoice of a project in the section Belege, the row in the list for an invoice without one', array_keys( array_filter( $links, static fn( bool $same ): bool => $same === false ) ) === [] && count( $links ) === 3 );
Db::exec( $appData, 'UPDATE documents SET state = \'sent\' WHERE id = :id', [ 'id' => $mailInvoice['id'] ] );
$reset();
$before = count( $mails );
$reminded = Documents::remind( $appData, $mailInvoice['id'] );
$remindMails = portalMailsFrom( $before );
check( '...so does the reminder: the row of the invoice in the section Belege of its project', is_array( $reminded ) && $reminded['mailed'] === 1 && count( $remindMails ) === 1 && $linkOfMail( $remindMails[0] ) === 'https://example.org/club/portal/projekte?projekt='. $pOld. '&amp;tab=belege#beleg-'. $mailInvoice['id'] );

$portalSheet = (string) file_get_contents( $root. \Crm\Portal::STYLE );
check( 'the style of the message is the portal\'s own: a card with a line of the accent at 40% and the accent at 10% behind, its text in the ink of the page, the icon in the link colour - all roles, no colour of its own - and the template carries no style and no handler', preg_match( '/\n\.crm-message \{[^}]*border: 1px solid color-mix\(in srgb, var\(--crm-info\) 40%[^}]*background-color: color-mix\(in srgb, var\(--crm-info\) 10%[^}]*color: var\(--crm-ink\);/', $portalSheet ) === 1
	&& preg_match( '/\n\.crm-message > \.crm-icon \{[^}]*color: var\(--crm-link\)/', $portalSheet ) === 1 && preg_match( '/\n\.crm-message__text \{[^}]*white-space: pre-line;[^}]*font-size: var\(--crm-text-m\)/', $portalSheet ) === 1 && preg_match( '/#[0-9a-fA-F]{3,8}\b|\brgb\(|\bstyle=|\bon[a-z]+=/', (string) file_get_contents( $root. '/app/Crm/Portal/templates/message.tpl' ). (string) file_get_contents( $root. '/app/Crm/Portal/templates/message-day.tpl' ) ) !== 1 );

// The day of a message is the day of the site, not of UTC: a message written at half past eleven in the evening of New Year's Eve in UTC is the first of January in Berlin
\Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'Europe/Berlin' ] );
\Crm\Clients::setMessage( $appData, $lc['id'], 'Um Mitternacht' );
Db::exec( $appData, 'UPDATE clients SET message_at = \'2026-12-31T23:30:00Z\' WHERE id = :id', [ 'id' => $lc['id'] ] );
\Crm\Portal::forget( $appData );
$midnight = \Crm\Portal::messages( $appData, $lClient() );
\Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'America/Los_Angeles' ] );
$evening = \Crm\Portal::messages( $appData, $lClient() );
\Nino\Features::saveSettings( $appData, 'events', [ 'timezone' => 'server' ] );
\Crm\Clients::setMessage( $appData, $lc['id'], '' );
\Crm\Portal::forget( $appData );
check( 'the day a message is shown with is the day in the timezone of the site: 23:30 UTC on the 31st is the 1st of January in Berlin and the 31st in Los Angeles - the datetime and the words say the same day', str_contains( $midnight, 'datetime="2027-01-01"' ) === true && str_contains( $midnight, '01.01.2027' ) === true && str_contains( $evening, 'datetime="2026-12-31"' ) === true && str_contains( $evening, '31.12.2026' ) === true );

// The messages
$noMessage = \Crm\Portal::messages( $appData, $lClient() );
check( 'a client with no message and a site with none reads none: the head draws the bar, the navigation and the head of the page and no note', $noMessage === '' && portalFind( portalHeadAt( $appData, '/portal' ), '//*[contains(@class, "crm-message")]' ) === [] );

\Crm\Settings::set( $appData, 'portal_message', "Das Studio ist <b>zu</b>.\nDanke!\n[crm-client] [[/project/company/general/name]]" );
$one = portalHeadAt( $appData, '/portal' );
$notes = portalFind( $one, '//div[@role="note"][contains(@class, "crm-message")]' );
$noteHtml = $notes === [] ? '' : $notes[0]->ownerDocument->saveHTML( $notes[0] );
check( 'the message to every client is a note over the head: role note, the icon, who wrote it and the day, and the text', count( $notes ) === 1 && portalFind( $noteHtml, '//svg[contains(@class, "crm-icon--message-square")][@aria-hidden="true"]' ) !== []
	&& portalFind( $noteHtml, '//p[contains(@class, "crm-message__from")]/strong' )[0]->textContent === 'Studio <Eins> & Co [members-name]' && portalFind( $noteHtml, '//p[contains(@class, "crm-message__from")]//time/@datetime' )[0]->nodeValue === substr( \Crm\Settings::get( $appData, 'portal_message_at' ), 0, 10 )
	&& portalFind( $noteHtml, '//p[contains(@class, "crm-message__from")]//time' )[0]->textContent === \Crm\Documents\Render::day( substr( \Crm\Settings::get( $appData, 'portal_message_at' ), 0, 10 ) ) );
$textNode = portalFind( $noteHtml, '//p[contains(@class, "crm-message__text")]' );
check( '...the text is text: the markup in it is shown as typed, a shortcode and a key are not read, the line breaks are line breaks (two <br>) - nothing is left unfilled', $textNode !== [] && str_contains( $textNode[0]->textContent, '<b>zu</b>' ) === true && portalFind( $noteHtml, '//p[contains(@class, "crm-message__text")]//b' ) === []
	&& str_contains( $textNode[0]->textContent, '[crm-client] [[/project/company/general/name]]' ) === true && count( portalFind( $noteHtml, '//p[contains(@class, "crm-message__text")]/br' ) ) === 2 && portalLeft( $one ) === false );

$db = \Crm\Db::pdo( $appData );
\Crm\Clients::setMessage( $appData, $lc['id'], "Nur für Dich:\n<script>alert(1)</script>" );
Db::exec( $appData, 'UPDATE clients SET message = message || :nul WHERE id = :id', [ 'nul' => "\0 und [members-name]", 'id' => $lc['id'] ] );
\Crm\Portal::forget( $appData );
$two = portalHeadAt( $appData, '/portal' );
$notes = portalFind( $two, '//div[@role="note"][contains(@class, "crm-message")]' );
check( 'the message to the client stands under the one to everybody: both, in that order, each its own note', count( $notes ) === 2 && str_contains( $notes[0]->textContent, 'Das Studio ist' ) === true && str_contains( $notes[1]->textContent, 'Nur für Dich:' ) === true );
check( '...a script in it is text, a null byte in the database is left out of the page, a shortcode in it stays one that is not read', portalFind( $two, '//*[contains(@class, "crm-message")]//script' ) === [] && str_contains( $two, "\0" ) === false && str_contains( $notes[1]->textContent, '<script>alert(1)</script>' ) === true && str_contains( $notes[1]->textContent, ' und [members-name]' ) === true && str_contains( $two, '&#91;members-name]' ) === true && str_contains( $one, '&#91;crm-client]' ) === true );

$pagesWith = [ '/portal', '/portal/projekte', '/portal/projekte?projekt='. $pOld, '/portal/belege', '/portal/upload', '/portal/termine', '/portal/meine-daten', '/portal/datenschutz' ];
$orders = [];
$counts = [];

foreach( $pagesWith as $uri ) {
	[ $status, $html ] = portalPage( $appData, $uri );
	$counts[$uri] = $status === 200 ? count( portalFind( $html, '//div[@role="note"][contains(@class, "crm-message")]' ) ) : -1;
	$orders[$uri] = strpos( $html, 'class="crm-bar"' ) < strpos( $html, 'class="crm-nav' ) && strpos( $html, 'class="crm-nav' ) < strpos( $html, 'class="crm-message"' ) && strpos( $html, 'class="crm-message"' ) < strpos( $html, 'class="crm-head' );
}

check( 'every page of the portal carries both notes, under the bar and the navigation and over the head of the page: the start, the projects and one project, the documents, the upload, the dates, Meine Daten and the Datenschutz', array_values( array_unique( $counts ) ) === [ 2 ] && count( $counts ) === 8 && array_values( array_unique( $orders ) ) === [ true ] );

$put( $folderOld, 'Klang.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 60 ) );
\Crm\Clients::setMessage( $appData, $lc['id'], '' );
\Crm\Portal::forget( $appData );
check( '...a message that is removed is gone from the pages; the one to everybody stays', count( portalFind( portalPage( $appData, '/portal' )[1], '//div[@role="note"][contains(@class, "crm-message")]' ) ) === 1 );

// during the gate: not a word before the step is done
\Crm\Clients::setMessage( $appData, $lc['id'], 'Eine Nachricht' );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => '' ] );
\Crm\Portal::forget( $appData );
$data = portalPage( $appData, '/portal/meine-daten' );
\Crm\Clients::change( $appData, $lc['id'], [ 'street' => 'Weg 1' ] );
\Crm\Settings::set( $appData, 'privacy_text', 'Ein Text, der noch nicht bestätigt ist' );
\Crm\Portal::forget( $appData );
$privacy = portalPage( $appData, '/portal/datenschutz' );
\Crm\Settings::set( $appData, 'privacy_text', '' );
\Crm\Portal::forget( $appData );
check( 'while a step of the gate is pending the pages of the steps - Meine Daten, the Datenschutz - carry no message, to everybody or to the client, and the gate itself says what is asked', $data[0] === 200 && $privacy[0] === 200 && portalFind( $data[1], '//*[contains(@class, "crm-message")]' ) === [] && portalFind( $privacy[1], '//*[contains(@class, "crm-message")]' ) === []
	&& portalFind( $data[1], '//p[contains(@class, "crm-gate")]' ) !== [] && portalFind( $privacy[1], '//p[contains(@class, "crm-gate")]' ) !== [] );
check( '...and after it they are back', count( portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//div[@role="note"][contains(@class, "crm-message")]' ) ) === 2 );

// who reads them: nobody who is no client
portalSignIn( $appData, $stray );
$strayHead = portalHeadAt( $appData, '/portal' );
$asVisitor();
$visitorHead = portalHeadAt( $appData, '/portal' );
check( 'a member who is no client\'s reads no message, to everybody or any, and a visitor reads no head at all', portalFind( $strayHead, '//*[contains(@class, "crm-message")]' ) === [] && str_contains( $strayHead, 'class="crm-head' ) === true && trim( $visitorHead ) === '' );
\Crm\Clients::setMessage( $appData, $lc['id'], '' );
\Crm\Settings::set( $appData, 'portal_message', '' );
\Crm\Portal::forget( $appData );
portalSignIn( $appData, $accounts['anna'] );

// --- The download is the owner's, and a waiting offer closes the files ---------------------------------------------------------------

echo "\nThe download of a project and the offer that comes first\n";

$hold();
$reset();
\Crm\Settings::set( $appData, 'privacy_text', $gateText );
$asAdmin();
$folderL = $store::addFolder( $appData, $a['group'], 'Gesperrt' );
$lSong = $put( $folderL, 'Lied.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 7200 ) );
$lPdf = $put( $folderL, 'Vertrag.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 7300 ) );
$lMine = $put( $folderL, 'Meins.txt', 'txt', 'text/plain', false, $byAnna, $ago( 60 ) );
$pL = $made( 'Gesperrt', $a['number'], [ 'folder' => $folderL['id'] ], false );
$lPage = '/portal/projekte?projekt='. $pL;
$lRows = static fn( string $html ): array => portalFind( $html, '//section[contains(@class, "crm-project__section--files")]//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' );
$lRow = static fn( string $html, string $id ): string => ( $found = portalFind( $html, '//li[@data-files-id="'. $id. '"]' ) ) === [] ? '' : $found[0]->ownerDocument->saveHTML( $found[0] );
$lServe = static function( array $file, bool $download ) use ( &$appData ): int {
	return portalServe( $appData, '/.files/'. $file['id']. ( $download === true ? '?download=1' : '' ) )['statusCode'];
};
portalSignIn( $appData, $accounts['anna'] );

// the state: no row is no download; a project that is not there cannot be switched
$nothing = \Crm\Projekte::state( $appData, $pL );
check( 'a project without a row has no download, no day and no account; list() and get() say so', $nothing === [ 'download' => false, 'download_at' => '', 'download_by' => '' ] && \Crm\Projekte::get( $appData, $pL )['download'] === false
	&& array_column( array_filter( \Crm\Projekte::list( $appData ), static fn( array $project ): bool => $project['id'] === $pL ), 'download' ) === [ false ] );

// the page while the download is off
$off = portalPage( $appData, $lPage.'&tab=dateien' )[1];
$offSong = $lRow( $off, $lSong['id'] );
$offPdf = $lRow( $off, $lPdf['id'] );
$offMine = $lRow( $off, $lMine['id'] );
check( 'while the download is off the tab Dateien has a note over the list - in the column of the list, not of the player - with the icon of a lock, and says nothing else of it', count( portalFind( $off, '//section[contains(@class, "crm-project__section--files")]//div[contains(@class, "crm-files-layout__list")]/p[contains(@class, "crm-note")][contains(@class, "crm-project__locked")]' ) ) === 1
	&& portalFind( $off, '//p[contains(@class, "crm-project__locked")]/svg' ) !== [] && portalFind( $off, '//p[contains(@class, "crm-project__waiting")]' ) === [] && count( $lRows( $off ) ) === 3 );
check( '...the row of an audio file has the way to listen and no download: its name leads to the player, there is no button of the download and no sentence', portalFind( $offSong, '//a[contains(@class, "crm-files__listen")]' ) !== [] && portalFind( $offSong, '//a[contains(@class, "crm-files__download")]' ) === []
	&& portalFind( $offSong, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === $lPage.'&review='. $lSong['id']. '#projekt-files' && portalFind( $offSong, '//*[contains(@class, "crm-files__locked")]' ) === [] && str_contains( $offSong, 'download=1' ) === false );
check( '...the row of any other file is not locked: its name leads to the download and the button Herunterladen is in the cell of the actions, with no sentence', portalFind( $offPdf, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/.files/'. $lPdf['id']. '?download=1' && portalFind( $offPdf, '//span[contains(@class, "crm-table__actions")]/a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $lPdf['id']. '?download=1'
	&& portalFind( $offPdf, '//*[contains(@class, "crm-files__locked")]' ) === [] && portalFind( $offPdf, '//a[contains(@class, "crm-files__listen")]' ) === [] );
check( '...a file the member put there itself is the member\'s: its name leads to the download and the button is there', portalFind( $offMine, '//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue === '/.files/'. $lMine['id']. '?download=1' && portalFind( $offMine, '//*[contains(@class, "crm-files__locked")]' ) === [] );
$uploadsList = portalPage( $appData, '/portal/upload' )[1];
check( 'the list of the uploads has the download of every file, whatever a project says - they are the member\'s files', portalFind( $uploadsList, '//a[contains(@class, "crm-files__download")]' ) !== [] && portalFind( $uploadsList, '//*[contains(@class, "crm-files__locked")]' ) === [] );

// the route
$lMake = static function() use ( $put, $folderL, $owner, $ago ): array {
	return [ 'zip' => $put( $folderL, 'Satz.zip', 'zip', 'application/zip', true, $owner, $ago( 7400 ) ), 'txt' => $put( $folderL, 'Text.txt', 'txt', 'text/plain', true, $owner, $ago( 7500 ) ), 'wav' => $put( $folderL, 'Ton.wav', 'wav', 'audio/wav', true, $owner, $ago( 7600 ) ) ];
};
$lDrop = static function( array $extra ) use ( &$appData, $store ): void {
	foreach( $extra as $file )
		$store::deleteFile( $appData, $file['id'] );
};
$lExtra = $lMake();
check( 'the route: the download of an audio file of a project without the download is 404 for the member, whatever its type says (mp3, wav)', $lServe( $lSong, true ) === 404 && $lServe( $lExtra['wav'], true ) === 404 );
check( '...and the download of any other file is given: a PDF, a ZIP and a text are 200 as a download', $lServe( $lPdf, true ) === 200 && $lServe( $lExtra['zip'], true ) === 200 && $lServe( $lExtra['txt'], true ) === 200 );
check( '...shown in the browser the audio files are 200 (to be played) and so is every other file; allowed() says no to the download of a medium alone', $lServe( $lPdf, false ) === 200 && $lServe( $lExtra['zip'], false ) === 200 && $lServe( $lExtra['txt'], false ) === 200
	&& $lServe( $lSong, false ) === 200 && $lServe( $lExtra['wav'], false ) === 200 && \Nino\Modules\Files::allowed( $appData, $lPdf ) === true && \Nino\Modules\Files::allowed( $appData, $lPdf, true ) === true && \Nino\Modules\Files::allowed( $appData, $lExtra['wav'], true ) === false );
$lDrop( $lExtra );
check( '...the member\'s own file is saved all the same; allowed() says no only to the download of the others', $lServe( $lMine, true ) === 200 && $lServe( $lMine, false ) === 200
	&& \Nino\Modules\Files::allowed( $appData, $lSong, true ) === false && \Nino\Modules\Files::allowed( $appData, $lSong ) === true && \Nino\Modules\Files::allowed( $appData, $lMine, true ) === true );
$wrongBody = portalServe( $appData, '/.files/'. $lSong['id']. '?download=1' );
check( '...the 404 is the plain one of a stranger: no file is named, no header of an attachment', ( $wrongBody['body'] ?? '' ) === 'Not Found' && isset( $wrongBody['file'] ) === false && str_contains( (string) ( $wrongBody['header']['Content-Disposition'] ?? '' ), 'attachment' ) === false );
$asAdmin();
check( '...the owner of the workbench saves it, by the permission of the panel', portalServe( $appData, '/.files/'. $lSong['id']. '?download=1' )['statusCode'] === 200 && portalServe( $appData, '/.files/'. $lPdf['id']. '?download=1' )['statusCode'] === 200 );
portalSignIn( $appData, $accounts['anna'] );

// the start page does not hold the download back, the files are there to listen to
$startOff = portalComponentAt( $appData, '/portal', '[crm-portal-start]' );
check( 'the start page counts the new files of a project whose download is off: they are there to listen to', str_contains( $startOff, 'projekt='. $pL. '&amp;tab=dateien' ) === true );

// the owner switches it on
$on = \Crm\Projekte::setDownload( $appData, $pL, true, 'owner@example.org' );
check( 'setDownload() switches it on with the day and the account of the workbench; state(), get() and list() say it; a project that is not there is "unknown", an id that is none as well, and nothing is written for it',
	is_array( $on ) && $on['download'] === true && preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $on['download_at'] ) === 1 && $on['download_by'] === 'owner@example.org' && \Crm\Projekte::get( $appData, $pL )['download'] === true
	&& array_column( array_filter( \Crm\Projekte::list( $appData ), static fn( array $project ): bool => $project['id'] === $pL ), 'download' ) === [ true ]
	&& \Crm\Projekte::setDownload( $appData, '99999', true ) === 'unknown' && \Crm\Projekte::setDownload( $appData, '../x', true ) === 'unknown' && Db::row( $appData, 'SELECT project FROM project_states WHERE project = \'99999\'' ) === null );
$onPage = portalPage( $appData, $lPage.'&tab=dateien' )[1];
check( 'with the download on the note is gone, every row has its button and the name of a file that is no medium leads to the download', portalFind( $onPage, '//p[contains(@class, "crm-project__locked")]' ) === [] && count( portalFind( $onPage, '//a[contains(@class, "crm-files__download")]' ) ) === 3
	&& portalFind( $lRow( $onPage, $lPdf['id'] ), '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue === '/.files/'. $lPdf['id']. '?download=1' && portalFind( $onPage, '//*[contains(@class, "crm-files__locked")]' ) === [] );
$lExtra = $lMake();
check( '...and the route serves the download, and the files that are no medium shown in the browser as well', $lServe( $lSong, true ) === 200 && $lServe( $lPdf, true ) === 200 && $lServe( $lPdf, false ) === 200 && $lServe( $lExtra['zip'], true ) === 200 && $lServe( $lExtra['zip'], false ) === 200 && $lServe( $lExtra['txt'], false ) === 200 && $lServe( $lExtra['wav'], true ) === 200 );
$lDrop( $lExtra );
$off = \Crm\Projekte::setDownload( $appData, $pL, false, 'owner@example.org' );
check( 'switched off again the day and the account are empty, and the route is 404 again', is_array( $off ) && $off === [ 'id' => $pL, 'download' => false, 'download_at' => '', 'download_by' => '' ] && $lServe( $lSong, true ) === 404 );

// what a page asks: the state of the project and its waiting offer are read once for the page, not once for each file
$lCount = static function( string $uri ) use ( &$appData ): array {
	$connections = new \ReflectionProperty( Db::class, '_connections' );
	$was = $connections->getValue();
	$file = (string) array_key_first( $was );
	$counting = new class( 'sqlite:'. $file ) extends \PDO {
		/** @var list<string> */
		public array $log = [];

		public function prepare( string $query, array $options = [] ): \PDOStatement|false {
			$this->log[] = $query;
			return parent::prepare( $query, $options );
		}

		public function query( string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs ): \PDOStatement|false {
			$this->log[] = $query;
			return $fetchMode === null ? parent::query( $query ) : parent::query( $query, $fetchMode, ...$fetchModeArgs );
		}

		public function exec( string $statement ): int|false {
			$this->log[] = $statement;
			return parent::exec( $statement );
		}
	};
	$counting->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
	$counting->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
	$counting->exec( 'PRAGMA busy_timeout = 5000' );
	$connections->setValue( null, [ $file => $counting ] );
	$html = portalPage( $appData, $uri )[1];
	$connections->setValue( null, $was );

	return [ $html, count( $counting->log ), count( array_filter( $counting->log, static fn( string $sql ): bool => preg_match( '/project_states|FROM documents/', $sql ) === 1 ) ) ];
};
[ $fewHtml, $fewAll, $fewOwn ] = $lCount( $lPage. '&tab=dateien' );
$lMany = [];

for( $n = 0; $n < 27; $n++ )
	$lMany[] = $put( $folderL, 'Vieles-'. $n. '.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 8000 + $n ) );

[ $manyHtml, $manyAll, $manyOwn ] = $lCount( $lPage. '&tab=dateien' );
check( 'a page of thirty files reads the state of the project and its offers as often as a page of three: the answers are kept for the request', count( $lRows( $fewHtml ) ) === 3 && count( $lRows( $manyHtml ) ) === 30 && $manyOwn === $fewOwn && $fewOwn > 0 );
check( '...and the rest of the queries of the page do not grow with the files by more than the files themselves ask', $manyAll - $fewAll <= 27 * 2 );
foreach( $lMany as $file )
	$store::deleteFile( $appData, $file['id'] );

// a project that is deleted takes its state with it
$gone = $made( 'Wird gelöscht', $a['number'] );
check( 'the state of a project is a row; deleting the project through the kernel takes it with it', Db::row( $appData, 'SELECT download FROM project_states WHERE project = :project', [ 'project' => $gone ] ) !== null
	&& \Nino\Elements::deleteElement( $appData, '/projekte/'. $gone, '*' ) === true && Db::row( $appData, 'SELECT download FROM project_states WHERE project = :project', [ 'project' => $gone ] ) === null && \Crm\Projekte::state( $appData, $gone )['download'] === false );

// the offer first
$offerL = $final( 'offer', $a['id'], [ 'date' => '2026-10-05', 'valid_until' => '2099-12-31', 'project' => $pL, 'template_key' => 'angebot', 'lines' => [ $line( 'Lied' ) ] ] );
Documents::release( $appData, $offerL['id'], false );
$waiting = \Crm\Projekte::waiting( $appData, $pL, $a['id'] );
check( 'a released offer of the project that can be ordered waits: waiting() answers it', is_array( $waiting ) && $waiting['id'] === $offerL['id'] && $waiting['number'] === $offerL['number'] );
check( '...it waits for its client and its project alone: another client and another project have none, asked before and after the one it waits for', \Crm\Projekte::waiting( $appData, $pL, $b['id'] ) === null && \Crm\Projekte::waiting( $appData, $pL, $a['id'] )['id'] === $offerL['id'] && \Crm\Projekte::waiting( $appData, $pL, $b['id'] ) === null
	&& \Crm\Projekte::waiting( $appData, $p4, $a['id'] ) === null );
portalWaitingGetChecks( $post, $asAdmin, $pL, $p4, $offerL );
portalSignIn( $appData, $accounts['anna'] );
$wPage = portalPage( $appData, $lPage.'&tab=dateien' )[1];
$wLink = portalFind( $wPage, '//section[contains(@class, "crm-project__section--files")]//p[contains(@class, "crm-project__waiting")]//a' );
check( 'while it waits the tab Dateien is a note in the place of the list - the icon of a receipt, the number of the offer and a link to its card on the tab Angebot -, with no list, no player and no sentence about the download', count( $wLink ) === 1
	&& $wLink[0]->getAttribute( 'href' ) === $lPage.'&tab=angebot#angebot-'. $offerL['id'] && str_contains( $wLink[0]->textContent, (string) $offerL['number'] ) === true && portalFind( $wPage, '//section[contains(@class, "crm-project__section--files")]//ul' ) === []
	&& portalFind( $wPage, '//p[contains(@class, "crm-project__waiting")]/svg' ) !== [] && portalFind( $wPage, '//p[contains(@class, "crm-project__locked")]' ) === [] && str_contains( $wPage, 'Lied.mp3' ) === false );
check( '...the tab carries no number, the other two do', portalFind( $wPage, '//a[@data-tab="dateien"]//*[contains(@class, "crm-project__count")]' ) === [] && portalFind( $wPage, '//a[@data-tab="angebot"]//*[contains(@class, "crm-project__count")]' ) !== [] );
$wReview = portalPage( $appData, '/portal/projekte?review='. $lSong['id'] )[1];
check( '...a file asked to be played gets the same note and no player, no comments', portalFind( $wReview, '//p[contains(@class, "crm-project__waiting")]' ) !== [] && portalFind( $wReview, '//div[contains(@class, "review-player")]' ) === [] && portalFind( $wReview, '//audio' ) === [] && portalFind( $wReview, '//form[contains(@class, "review-form")] | //*[contains(@class, "review-comment")]' ) === [] );
check( '...the route says no to every file of the folder, shown or saved, the member\'s own among them - with the download on as well', array_unique( [ $lServe( $lSong, false ), $lServe( $lSong, true ), $lServe( $lPdf, false ), $lServe( $lPdf, true ), $lServe( $lMine, false ), $lServe( $lMine, true ) ] ) === [ 404 ]
	&& \Nino\Modules\Files::allowed( $appData, $lSong ) === false && \Nino\Modules\Files::allowed( $appData, $lMine ) === false );
\Crm\Projekte::setDownload( $appData, $pL, true, 'owner@example.org' );
check( '...the switch of the download does not open them', array_unique( [ $lServe( $lSong, false ), $lServe( $lPdf, true ), $lServe( $lMine, true ) ] ) === [ 404 ] && portalFind( portalPage( $appData, $lPage.'&tab=dateien' )[1], '//p[contains(@class, "crm-project__waiting")]' ) !== [] );
\Crm\Projekte::setDownload( $appData, $pL, false, 'owner@example.org' );
$asAdmin();
check( '...the owner of the workbench is not asked', $lServe( $lSong, true ) === 200 && $lServe( $lPdf, false ) === 200 );
portalSignIn( $appData, $accounts['anna'] );
$startWaiting = portalComponentAt( $appData, '/portal', '[crm-portal-start]' );
$listWaiting = portalPage( $appData, '/portal/projekte' )[1];
$rowWaiting = portalFind( $listWaiting, '//li[@data-project="'. $pL. '"]' );
check( 'the start page counts the files that came as new while the offer waits, and says they open when the offer is confirmed - the list of the projects counts none for it', str_contains( $startWaiting, 'projekt='. $pL. '&amp;tab=dateien' ) === true && str_contains( $startWaiting, $wordsDe['[[/project/crm-portal/start/new-file-waiting]]'] ) === true
	&& count( $rowWaiting ) === 1 && portalFind( $rowWaiting[0]->ownerDocument->saveHTML( $rowWaiting[0] ), '//span[contains(@class, "crm-projects__count--files")]' ) === [] );

// what lifts it: the order, a decline, the owner taking the offer back, the end of its day, and an offer that is not this project's or this client's
$cases = [
	'accepted'     => 'state = \'accepted\'',
	'declined'     => 'state = \'declined\'',
	'not released' => 'state = \'sent\', released = 0',
	'run out'      => 'state = \'sent\', released = 1, valid_until = \'2020-01-01\'',
	'sent'         => 'state = \'sent\', released = 1, valid_until = \'2099-12-31\'',
];
$lifted = [];

foreach( $cases as $name => $set ) {
	Db::exec( $appData, 'UPDATE documents SET '. $set. ' WHERE id = :id', [ 'id' => $offerL['id'] ] );
	\Crm\Projekte::forget( $appData );
	$lifted[$name] = \Crm\Projekte::waiting( $appData, $pL, $a['id'] ) === null;
}

check( 'waiting() is null for an offer that was accepted, declined, taken back from the portal or run out, and the offer again when it is sent and in its days', $lifted === [ 'accepted' => true, 'declined' => true, 'not released' => true, 'run out' => true, 'sent' => false ] );
Db::exec( $appData, 'UPDATE documents SET state = \'declined\' WHERE id = :id', [ 'id' => $offerL['id'] ] );
\Crm\Projekte::forget( $appData );
portalSignIn( $appData, $accounts['anna'] );
check( '...with the offer declined the files are open again: the list is there, the player plays, the route serves what is for listening and still not the download', count( $lRows( portalPage( $appData, $lPage.'&tab=dateien' )[1] ) ) === 3 && $lServe( $lSong, false ) === 200 && $lServe( $lSong, true ) === 404 );
check( '...waiting() asks the project and the client: another project, another client and an id that is none have none', \Crm\Projekte::waiting( $appData, $p4, $a['id'] ) === null && \Crm\Projekte::waiting( $appData, $pL, $b['id'] ) === null && \Crm\Projekte::waiting( $appData, '', $a['id'] ) === null );
$offerLb = $final( 'offer', $a['id'], [ 'date' => '2026-10-06', 'valid_until' => '2099-12-31', 'project' => $pL, 'template_key' => 'angebot', 'lines' => [ $line( 'Zwei' ) ] ] );
$offerLoose = $final( 'offer', $a['id'], [ 'date' => '2026-10-07', 'valid_until' => '2099-12-31', 'template_key' => 'angebot', 'lines' => [ $line( 'Ohne Projekt' ) ] ] );
Documents::release( $appData, $offerLb['id'], false );
Documents::release( $appData, $offerLoose['id'], false );
check( '...an offer that waits without a project holds no project back, and the second offer of the project is the one that waits now', \Crm\Projekte::waiting( $appData, $pL, $a['id'] )['id'] === $offerLb['id'] && $lServe( $lSong, false ) === 404 && \Crm\Projekte::waiting( $appData, $p4, $a['id'] ) === null );
Db::exec( $appData, 'UPDATE documents SET state = \'accepted\' WHERE id = :id', [ 'id' => $offerLb['id'] ] );
\Crm\Projekte::forget( $appData );
check( '...and an order of it opens the files', \Crm\Projekte::waiting( $appData, $pL, $a['id'] ) === null && $lServe( $lSong, false ) === 200 );

// the note names the offer as text: markup and a bracket in the number or the title are not markup
Db::exec( $appData, 'UPDATE documents SET state = \'sent\', number = :number WHERE id = :id', [ 'number' => 'A<b>1</b> [[/project/company/general/name]]', 'id' => $offerL['id'] ] );
$leak = portalPage( $appData, $lPage.'&tab=dateien' )[1];
check( 'the number of the offer in the note is text: no <b> of it, no fill of the page', portalFind( $leak, '//p[contains(@class, "crm-project__waiting")]//b' ) === [] && str_contains( $leak, 'A&lt;b&gt;1&lt;/b&gt; &#91;&#91;/project/company/general/name]]' ) === true && str_contains( $leak, 'The Club' ) === false );
Db::exec( $appData, 'UPDATE documents SET number = \'\', title = :title WHERE id = :id', [ 'title' => '<i>T</i> [members-name]', 'id' => $offerL['id'] ] );
$leakTitle = portalPage( $appData, $lPage.'&tab=dateien' )[1];
check( '...nor is the title that stands there for a number', portalFind( $leakTitle, '//p[contains(@class, "crm-project__waiting")]//i' ) === [] && str_contains( $leakTitle, '&lt;i&gt;T&lt;/i&gt; &#91;members-name]' ) === true );
Db::exec( $appData, 'UPDATE documents SET state = \'declined\' WHERE id = :id', [ 'id' => $offerL['id'] ] );

// the release of an invoice that opens the files with it
$asAdmin();
$invL = $final( 'invoice', $a['id'], [ 'date' => '2026-10-20', 'project' => $pL, 'template_key' => 'rechnung', 'lines' => [ $line( 'Teil' ) ] ] );
$invLoose = $final( 'invoice', $a['id'], [ 'date' => '2026-10-21', 'template_key' => 'rechnung', 'lines' => [ $line( 'Lose' ) ] ] );
$offerP = $final( 'offer', $a['id'], [ 'date' => '2026-10-22', 'valid_until' => '2099-12-31', 'project' => $pL, 'template_key' => 'angebot', 'lines' => [ $line( 'Angebot' ) ] ] );
\Crm\Projekte::setDownload( $appData, $pL, false );
$reset();
$before = count( $mails );
$plain = Documents::release( $appData, $invLoose['id'], true );
check( 'a release with no word of the download mails the two sentences it always had: no second sentence, no slot of the template left in the body', is_array( $plain ) && $plain['download'] === false && $plain['mailed'] > 0 && $plain['mailed'] === count( $mails ) - $before && str_contains( portalMail( $before )['body'], 'tab=dateien' ) === false && str_contains( portalMail( $before )['body'], '[[' ) === false );
$plainAnchors = substr_count( portalMail( $before )['body'], '<a ' );
check( '...and the project is as it was: download off', \Crm\Projekte::state( $appData, $pL )['download'] === false );
$invNoWord = $final( 'invoice', $a['id'], [ 'date' => '2026-10-19', 'project' => $pL, 'template_key' => 'rechnung', 'lines' => [ $line( 'Ohne Wort' ) ] ] );
$reset();
$before = count( $mails );
Documents::release( $appData, $invNoWord['id'], true );
check( '...an invoice of a project released with the mail and without the download does not speak of the files, and the project stays locked', count( $mails ) > $before && str_contains( portalMail( $before )['body'], 'tab=dateien' ) === false && substr_count( portalMail( $before )['body'], '<a ' ) === $plainAnchors && \Crm\Projekte::state( $appData, $pL )['download'] === false );
$refused = [ Documents::release( $appData, $offerP['id'], false, true ), Documents::release( $appData, $invLoose['id'], false, true ), Documents::release( $appData, 99999, false, true ) ];
check( 'the download goes with an invoice of a project and nothing else: an offer and an invoice without a project are refused "download", a document nobody has is "unknown" - and nothing was released or switched', $refused === [ 'download', 'download', 'unknown' ] && Documents::get( $appData, $offerP['id'] )['released'] === false
	&& \Crm\Projekte::state( $appData, $pL )['download'] === false );
$draftP = Documents::add( $appData, 'invoice', $a['id'], [ 'project' => $pL, 'template_key' => 'rechnung', 'lines' => [ $line( 'Entwurf' ) ] ] );
check( '...a draft is "state" before anything else, and the project stays locked', Documents::release( $appData, $draftP['id'], true, true ) === 'state' && \Crm\Projekte::state( $appData, $pL )['download'] === false );

// a release that fails leaves the project as it was: the cap of mails, a site without an address
$reset();

for( $n = 0; $n < 5; $n++ )
	Documents::release( $appData, $invoiceA['id'], true );

check( 'a release that the cap of mails refuses switches nothing on: "limit", the invoice is not released and the project is locked', Documents::release( $appData, $invL['id'], true, true ) === 'limit' && Documents::get( $appData, $invL['id'] )['released'] === false && \Crm\Projekte::state( $appData, $pL )['download'] === false );
$reset();
$noAddress = $appData;
\Nino\Html::addFills( $noAddress, [ '[[/project/website/general/url]]' => '' ], '*' );
check( '...so does a site that has no address to link to: "address", nothing released, the project locked', Documents::release( $noAddress, $invL['id'], true, true ) === 'address' && \Crm\Projekte::state( $appData, $pL )['download'] === false && Documents::get( $appData, $invL['id'] )['released'] === false );

// a failure inside the transaction takes the release back with the switch: a deferred reference that is broken by the update of the document fails the commit, after the download was switched on
Db::exec( $appData, 'CREATE TABLE zz_parent ( id INTEGER PRIMARY KEY )' );
Db::exec( $appData, 'CREATE TABLE zz_child ( ref INTEGER REFERENCES zz_parent ( id ) DEFERRABLE INITIALLY DEFERRED )' );
Db::exec( $appData, 'CREATE TRIGGER zz_break AFTER UPDATE OF released ON documents BEGIN INSERT INTO zz_child ( ref ) VALUES ( 1 ); END' );
$failed = null;

try {
	Documents::release( $appData, $invL['id'], false, true, 'owner@example.org' );
}
catch( \Throwable $e ) {
	$failed = $e;
}

check( 'a release that fails inside its transaction is not half done: it throws, the invoice is not released and the project is still locked', $failed instanceof \PDOException && Documents::get( $appData, $invL['id'] )['released'] === false && \Crm\Projekte::state( $appData, $pL ) === [ 'download' => false, 'download_at' => '', 'download_by' => '' ] );
Db::exec( $appData, 'DROP TRIGGER zz_break' );
Db::exec( $appData, 'DROP TABLE zz_child' );
Db::exec( $appData, 'DROP TABLE zz_parent' );

// the release that goes through
$reset();
$before = count( $mails );
$opened = Documents::release( $appData, $invL['id'], true, true, 'owner@example.org' );
$stateL = \Crm\Projekte::state( $appData, $pL );
check( 'the release with the download is one step: the invoice is released and sent, the project has its download on with the day and the account, and the answer says it', is_array( $opened ) && $opened['download'] === true && $opened['mailed'] > 0 && $opened['mailed'] === count( $mails ) - $before && $opened['document']['released'] === true && $opened['document']['state'] === 'sent'
	&& $stateL['download'] === true && $stateL['download_by'] === 'owner@example.org' && preg_match( '/^\d{4}-\d{2}-\d{2}T/D', $stateL['download_at'] ) === 1 );
$body = portalMail( $before )['body'];
check( '...the mail has a second sentence about the files of the project with the link to its tab Dateien, the title as text', str_contains( $body, 'href="https://example.org/club/portal/projekte?projekt='. $pL. '&amp;tab=dateien"' ) === true && str_contains( $body, 'Gesperrt' ) === true && str_contains( $body, '[[' ) === false
	&& substr_count( $body, '<a ' ) === $plainAnchors + 1 && str_contains( portalMail( $before )['subject'], (string) $invL['number'] ) === true && str_contains( portalMail( $before )['subject'], 'Gesperrt' ) === false && str_contains( $body, (string) $invL['number'] ) === true );
$wasStamp = $stateL['download_at'];
Db::exec( $appData, 'UPDATE project_states SET download_at = \'2026-01-01T00:00:00Z\', download_by = \'first@example.org\' WHERE project = :project', [ 'project' => $pL ] );
$again = Documents::release( $appData, $invL['id'], false, true, 'owner@example.org' );
check( '...a release again with the download on changes nothing of the state: the first day and the first account stay', is_array( $again ) && $again['mailed'] === 0 && \Crm\Projekte::state( $appData, $pL ) === [ 'download' => true, 'download_at' => '2026-01-01T00:00:00Z', 'download_by' => 'first@example.org' ] && $wasStamp !== '' );
\Crm\Projekte::setDownload( $appData, $pL, false );

// the mail names the title as text, whatever it holds
$evilTitle = '<b>Titel</b> [members-name] & Co';
$pEvil = $made( $evilTitle, $a['number'], [], false );
$invEvil = $final( 'invoice', $a['id'], [ 'date' => '2026-10-23', 'project' => $pEvil, 'template_key' => 'rechnung', 'lines' => [ $line( 'Evil' ) ] ] );
$reset();
$before = count( $mails );
Documents::release( $appData, $invEvil['id'], true, true );
$evilBody = portalMail( $before )['body'];
check( 'the title of the project in the mail is text: no <b> of it, no shortcode of it, the ampersand escaped', str_contains( $evilBody, '&lt;b&gt;Titel&lt;/b&gt; &#91;members-name] &amp; Co' ) === true && str_contains( $evilBody, '<b>Titel' ) === false );
$remindBody = \Crm\Documents\Mail::compose( $appData, (array) Documents::get( $appData, $invEvil['id'] ), 'remind', true );
check( '...a reminder never says it: the files are not part of a payment reminder', $remindBody !== [] && str_contains( $remindBody[0]['body'], 'tab=dateien' ) === false );

// the panel
[ $status, $answer ] = $post( 'crm-documents/release', [ 'id' => (string) $offerP['id'], 'download' => true ] );
check( 'crm-documents/release with the download of an offer is 409 crm_documents_download, and nothing was released', $status === 409 && $answer['code'] === 'crm_documents_download' && Documents::get( $appData, $offerP['id'] )['released'] === false );
$reset();
\Crm\Projekte::setDownload( $appData, $pEvil, false );
[ $status, $answer ] = $post( 'crm-documents/release', [ 'id' => (string) $invEvil['id'], 'mail' => true, 'download' => 'yes' ] );
check( 'only a true download is one: "yes" releases the invoice and leaves the project locked', $status === 200 && $answer['download'] === false && \Crm\Projekte::state( $appData, $pEvil )['download'] === false );
[ $status, $answer ] = $post( 'crm-documents/release', [ 'id' => (string) $invEvil['id'], 'mail' => true, 'download' => true ] );
$seen = $post( 'crm-documents/get', [ 'id' => (string) $invEvil['id'] ] )[1];
check( '...a true one switches it on in the same action by the account of the workbench; the answer and the document of the screen say it', $status === 200 && $answer['download'] === true && \Crm\Projekte::state( $appData, $pEvil )['download'] === true && \Crm\Projekte::state( $appData, $pEvil )['download_by'] === 'admin@example.com'
	&& array_column( $seen['projects'], 'download', 'id' )[$pEvil] === true && array_column( $seen['projects'], 'download', 'id' )[$pL] === false );
check( '...its log line names the document and the project and no address', \Crm\Documents\Admin::log( 'crm-documents/release', [] ) === 'Released document '. \Crm\Documents\Render::name( (array) Documents::get( $appData, $invEvil['id'] ) ). ', with the download of project '. $pEvil );


// the words
$wordsLocked = [ '[[/project/crm-portal/projects/files-locked]]', '[[/project/crm-portal/projects/files-waiting]]', '[[/project/crm-portal/projects/files-waiting-next]]', '[[/project/crm-portal/files/locked]]' ];
check( 'the new words are in German and English', array_diff( $wordsLocked, array_keys( $wordsDe ), array_keys( $wordsEn ) ) === [] && array_filter( $wordsLocked, static fn( string $key ): bool => trim( $wordsDe[$key] ) === '' || trim( $wordsEn[$key] ) === '' ) === [] );

/**
 *	The checks of the tabs of the page of a project
 */
function portalTabChecks( array &$appData, string $html, string $noFolder, string $filesOff, string $p1, string $p5, array $song, array $wordsOfPortal, \Closure $token ): void {

	$tabButtons = static fn( string $page ): array => portalFind( $page, '//article[@data-project]/div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']/a['. $token( 'nino-tabs-tab' ). ']' );
	$tabPanels = static fn( string $page ): array => portalFind( $page, '//div['. $token( 'nino-tabs' ). ']/section['. $token( 'nino-tabs-panel' ). ']' );
	$openIds = static fn( string $page ): array => [
		array_values( array_map( static fn( \DOMElement $button ): string => $button->getAttribute( 'data-tabs-target' ), array_filter( $tabButtons( $page ), static fn( \DOMElement $button ): bool => str_contains( ' '. $button->getAttribute( 'class' ). ' ', ' nino-is-active ' ) ) ) ),
		array_values( array_map( static fn( \DOMElement $panel ): string => $panel->getAttribute( 'id' ), array_filter( $tabPanels( $page ), static fn( \DOMElement $panel ): bool => str_contains( ' '. $panel->getAttribute( 'class' ). ' ', ' nino-is-active ' ) ) ) ),
	];
	$withTabs = portalFind( $html, '//article[@data-project]/div['. $token( 'nino-tabs' ). ']' );
	check( 'the page of a project has the kernel\'s tabs under the head: one .nino-tabs with a .nino-tabs-nav of four links - there from the server, so that nothing moves when the script of the kernel makes them tabs - and four .nino-tabs-panel sections', count( $withTabs ) === 1 && count( portalFind( $html, '//div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']' ) ) === 1
		&& count( $tabButtons( $html ) ) === 4 && count( $tabPanels( $html ) ) === 4 && count( portalFind( $html, '//div['. $token( 'nino-tabs' ). ']/div['. $token( 'nino-tabs-nav' ). ']/*[not(self::a)]' ) ) === 0 );
	check( '...each tab is a link to its panel (href #<id>, the jump list of a page without a script) with the id of the panel in data-tabs-target - Angebot, Dateien, Belege, Übersicht, in this order - and each panel has that id (the anchors of the start page stay: projekt-files)', array_map( static fn( \DOMElement $button ): string => $button->getAttribute( 'data-tabs-target' ), $tabButtons( $html ) ) === [ 'projekt-offer', 'projekt-files', 'projekt-documents', 'projekt-overview' ]
		&& array_map( static fn( \DOMElement $panel ): string => $panel->getAttribute( 'id' ), $tabPanels( $html ) ) === [ 'projekt-offer', 'projekt-files', 'projekt-documents', 'projekt-overview' ] && array_filter( $tabButtons( $html ), static fn( \DOMElement $button ): bool => $button->getAttribute( 'href' ) !== '#'. $button->getAttribute( 'data-tabs-target' ) ) === []
		&& array_map( static fn( \DOMElement $button ): string => preg_replace( '/,?\s*\d+$/', '', trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $button->textContent ) ) ), $tabButtons( $html ) ) === array_map( static fn( string $name ): string => $wordsOfPortal['[[/project/crm-portal/projects/section-'. $name. ']]'], [ 'offer', 'files', 'documents', 'overview' ] ) );
	check( '...the last, Übersicht, stands open without a query: its button and its panel carry nino-is-active and no other does', $openIds( $html ) === [ [ 'projekt-overview' ], [ 'projekt-overview' ] ] );
	check( '...?tab= chooses the tab on the server: angebot, dateien, belege, uebersicht - the button and the panel of it, and only those', array_map( static fn( string $tab ): array => $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab='. $tab )[1] ), [ 'angebot', 'dateien', 'belege', 'uebersicht' ] )
		=== [ [ [ 'projekt-offer' ], [ 'projekt-offer' ] ], [ [ 'projekt-files' ], [ 'projekt-files' ] ], [ [ 'projekt-documents' ], [ 'projekt-documents' ] ], [ [ 'projekt-overview' ], [ 'projekt-overview' ] ] ] );
	$ignored = ninoWarnings();
	check( '...a tab that is none, one with markup in it and one that is not a string leave Übersicht open - and say nothing of it', array_map( static fn( string $query ): array => $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. $query )[1] ), [ '&tab=nothing', '&tab=%3Cb%3E', '&tab[]=belege', '&tab=', '&tab=files', '&tab=upload' ] )
		=== array_fill( 0, 6, [ [ 'projekt-overview' ], [ 'projekt-overview' ] ] ) && str_contains( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=%3Cb%3E' )[1], '<b>' ) === false && ninoWarnings() === [] );
	check( '...a file asked to be played opens Dateien, whatever tab the query names', $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ]
		&& $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=belege&review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ] && $openIds( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=uebersicht&review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ] && $openIds( portalPage( $appData, '/portal/projekte?review='. $song['id'] )[1] ) === [ [ 'projekt-files' ], [ 'projekt-files' ] ] );
	$countOf = static fn( string $page, string $name ): string => trim( ltrim( portalText( $page, '//a[@data-tabs-target="projekt-'. $name. '"]/span[@class="crm-project__count"]' ), ',' ) );
	check( '...the label of a tab carries the count of what is in it: the offers, the files the member may see, the documents', $countOf( $html, 'offer' ) === (string) count( portalFind( $html, '//section[@id="projekt-offer"]//details['. $token( 'crm-offer' ). ']' ) ) && $countOf( $html, 'offer' ) === '3'
		&& $countOf( $html, 'files' ) === (string) count( portalFind( $html, '//section[@id="projekt-files"]//li[contains(concat(" ", normalize-space(@class), " "), " crm-files__row ")]' ) ) && $countOf( $html, 'files' ) === '4'
		&& $countOf( $html, 'documents' ) === (string) count( portalFind( $html, '//section[@id="projekt-documents"]//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' ) ) && $countOf( $html, 'documents' ) === '4' );
	check( '...the count says its separator for a screen reader: a hidden comma before the number, so that the label is read "Angebot, 2" and not "Angebot2" - and Übersicht carries none', count( portalFind( $html, '//a['. $token( 'nino-tabs-tab' ). ']/span[@class="crm-project__count"]/span['. $token( 'nino-sr-only' ). ']' ) ) === 3 && $countOf( $html, 'overview' ) === ''
		&& array_filter( array_slice( $tabButtons( $html ), 0, 3 ), static fn( \DOMElement $tab ): bool => preg_match( '/\S, \d+$/', trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $tab->textContent ) ) ) !== 1 ) === [] );
	check( '...a project with nothing in a tab counts 0 for the offers and the documents and says its sentence in the panel; a project without a folder and a site without Files count no files - and the panels are all there', $countOf( $noFolder, 'offer' ) === '0' && $countOf( $noFolder, 'documents' ) === '0' && $countOf( $noFolder, 'files' ) === ''
		&& portalFind( $noFolder, '//section[@id="projekt-offer"]/div[contains(@class, "crm-project__message")]' ) !== [] && portalFind( $noFolder, '//section[@id="projekt-files"]/div[contains(@class, "crm-project__message")]' ) !== [] && $countOf( $filesOff, 'files' ) === '' && count( $tabPanels( $noFolder ) ) === 4 && count( $tabPanels( $filesOff ) ) === 4
		&& $countOf( portalPage( $appData, '/portal/projekte?projekt='. $p5 )[1], 'files' ) === '0' );
	check( '...no panel is hidden in the markup (the script of the kernel hides them, and without it every panel stands open), and the page holds no script, no style and no handler of the module\'s own', portalFind( $html, '//section[@hidden or @style or @aria-hidden]' ) === [] && portalFind( $html, '//article//script | //article//style | //article//*[@style] | //article//*[@onclick]' ) === [] );
}

/**
 *	The checks of the head of the page of a project (the path alone) and of the start of the tab Übersicht (title, badges, description)
 */
function portalHeadChecks( array &$appData, string $html, string $p2 ): void {

	$overview = portalSection( $html, 'overview' );
	$pageHead = portalFind( $html, '//div[contains(@class, "crm-head")]' );
	check( '...the head of the page is the path and a heading for a screen reader: the breadcrumb Projekte > the project and the title as an h2 that is not drawn, and no lead, no badge, no paragraph and no row of actions - the tabs stand directly under it', count( $pageHead ) === 1
		&& portalText( $html, '//div[contains(@class, "crm-head--path")]/h2[contains(@class, "nino-sr-only")]' ) === 'Album 2027'
		&& str_contains( ' '. $pageHead[0]->getAttribute( 'class' ). ' ', ' crm-head--path ' ) === true && portalFind( $html, '//nav[contains(@class, "crm-crumbs")]/a/@href' )[0]->nodeValue === '/portal/projekte' && portalText( $html, '//nav[contains(@class, "crm-crumbs")]/span[@aria-current="page"]' ) === 'Album 2027'
		&& portalFind( $html, '//div[contains(@class, "crm-head")]//*[self::p or contains(@class, "crm-head__row") or contains(@class, "crm-head__badge") or contains(@class, "crm-head__lead")]' ) === []
		&& portalFind( $html, '//div[contains(@class, "crm-head")]/following-sibling::*[1][self::article][contains(@class, "crm-project")]' ) !== []
		&& portalFind( $html, '//div[contains(@class, "crm-head")]/following-sibling::article/div[contains(@class, "nino-tabs")]/div[contains(@class, "nino-tabs-nav")]' ) !== [] && portalFind( $html, '//h3[contains(@class, "crm-projects__heading")] | //h3[@class="crm-project__title"]' ) === [] );
	check( '...the title, the badges and the description are the first thing of the tab Übersicht: the title as a paragraph of its own, the state as a pill and the day it began in a paragraph under it, then the description with its line breaks as <br> and every character escaped (markup and a shortcode in it are text) - and nowhere else on the page', portalText( $overview, '//div[contains(@class, "crm-overview")]/p[contains(@class, "crm-overview__title")]' ) === 'Album 2027'
		&& str_contains( $html, 'Aufnahme &lt;b&gt;und&lt;/b&gt;<br>Mix &#91;members-name]' ) === true && substr_count( $html, 'Aufnahme &lt;b&gt;und&lt;/b&gt;' ) === 1 && portalFind( $overview, '//b | //i' ) === []
		&& portalFind( $overview, '//p[contains(@class, "crm-overview__title")]/following-sibling::p[contains(@class, "crm-head__badge")][1]/span[contains(@class, "crm-head__state")][contains(@class, "crm-pill")]' ) !== [] && portalFind( $overview, '//p[contains(@class, "crm-overview__title")]/following-sibling::p[contains(@class, "crm-head__badge")]/following-sibling::p[contains(@class, "crm-project__description")]' ) !== []
		&& portalFind( $overview, '//p[contains(@class, "crm-head__badge")]/span[contains(@class, "crm-head__since")]/time/@datetime' )[0]->nodeValue === '2026-10-01' && str_contains( portalFind( $overview, '//span[contains(@class, "crm-head__state")]' )[0]->getAttribute( 'class' ), 'crm-pill--info' ) === true
		&& portalText( $overview, '//span[contains(@class, "crm-head__state")]' ) !== portalText( portalSection( portalPage( $appData, '/portal/projekte?projekt='. $p2 )[1], 'overview' ), '//span[contains(@class, "crm-head__state")]' )
		&& portalFind( $html, '//article//h2' ) === [] && count( portalFind( $html, '//h2' ) ) === 1 && portalFind( $html, '//section[contains(@class, "crm-project__section")][not(@id="projekt-overview")]//*[contains(@class, "crm-head__badge") or contains(@class, "crm-project__description")]' ) === [] );
	check( '...the headings of the page follow each other without a jump, with the script and without: the h2 of the project over the tabs (not drawn), then h3 - the four sections and the cards of Übersicht - and no h4 below', ( static function( array $headings ): bool {

			$names = array_map( static fn( \DOMElement $heading ): string => $heading->nodeName, $headings );

			return count( $names ) >= 6 && $names[0] === 'h2' && array_diff( array_slice( $names, 1 ), [ 'h3' ] ) === [];
		} )( portalFind( $html, '//div[contains(@class, "crm-head--path")]/h2 | //article//*[self::h2 or self::h3 or self::h4 or self::h5 or self::h6]' ) ) === true );
}

// --- Die Projektseite: Übersicht, der Player als Spalte, die Sperre nur für Medien --------------------------------------------

/**
 *	The checks of the tabs of the page of a project: Übersicht, the player as a column of Dateien, and the download that holds back the media alone
 */
function portalOverviewChecks( array &$appData, array $a, array $accounts, array $owner, string $today, string $store, array $wordsDe, array $wordsEn, \Closure $hold, \Closure $reset, \Closure $asAdmin, \Closure $put, \Closure $made, \Closure $final, \Closure $line, \Closure $ago, \Closure $recordsOf, \Closure $location ): void {

	echo "\nThe page of a project: Übersicht, the player beside the list, the download of the media\n";

	$hold();
	$reset();
	$asAdmin();
	$byAnna = [ 'kind' => 'member', 'id' => $accounts['anna']['id'] ];
	$folderF = $store::addFolder( $appData, $a['group'], 'Dateien-Typen' );
	$fSong = $put( $folderF, 'Lied.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 3000 ) );
	$fWav = $put( $folderF, 'Ton.wav', 'wav', 'audio/wav', true, $owner, $ago( 3100 ) );
	$fMp4 = $put( $folderF, 'Clip.mp4', 'mp4', 'video/mp4', true, $owner, $ago( 3050 ) );
	$fPdf = $put( $folderF, 'Plan.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 3200 ) );
	$fZip = $put( $folderF, 'Satz.zip', 'zip', 'application/zip', true, $owner, $ago( 3300 ) );
	$fTxt = $put( $folderF, 'Text.txt', 'txt', 'text/plain', true, $owner, $ago( 3400 ) );
	$fMine = $put( $folderF, 'Meins.wav', 'wav', 'audio/wav', false, $byAnna, $ago( 3500 ) );
	$pF = $made( 'Typen <b>Zwei</b>', $a['number'], [ 'folder' => $folderF['id'], 'description' => "Zeile <i>eins</i>\nZeile [members-name]", 'started' => '2026-09-01' ], false );
	$folderP = $store::addFolder( $appData, $a['group'], 'Nur Papier' );
	$put( $folderP, 'Nur.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 3000 ) );
	$pP = $made( 'Nur Papier', $a['number'], [ 'folder' => $folderP['id'] ], false );
	$folderW = $store::addFolder( $appData, $a['group'], 'Wartet-Uebersicht' );
	$wSong = $put( $folderW, 'Warte.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 3000 ) );
	$wPdf = $put( $folderW, 'Warte.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 3100 ) );
	$wMine = $put( $folderW, 'Eigen.pdf', 'pdf', 'application/pdf', false, $byAnna, $ago( 3200 ) );
	$pW = $made( 'Wartet auf Auftrag', $a['number'], [ 'folder' => $folderW['id'] ], false );
	$offerW = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => '2099-12-31', 'project' => $pW, 'template_key' => 'angebot', 'lines' => [ $line( 'Auftrag' ) ] ] );
	Documents::release( $appData, $offerW['id'], false );
	$invoiceW = $final( 'invoice', $a['id'], [ 'date' => $today, 'project' => $pW, 'template_key' => 'rechnung', 'lines' => [ $line( 'Rechnung' ) ] ] );
	Documents::release( $appData, $invoiceW['id'], false );
	$folderD = $store::addFolder( $appData, $a['group'], 'Fertig-Uebersicht' );
	$put( $folderD, 'Fertig.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 3000 ) );
	$pD = $made( 'Fertig Uebersicht', $a['number'], [ 'folder' => $folderD['id'], 'done' => true ], false );
	$pNone = $made( 'Ohne alles', $a['number'], [], false );
	$pZero = $made( 'Null'. "\0". 'Titel', $a['number'], [ 'description' => 'Text'. "\0". 'Mehr' ], false );
	portalSignIn( $appData, $accounts['anna'] );

	$page = static fn( string $project, string $query = '' ): string => portalPage( $appData, '/portal/projekte?projekt='. $project. $query )[1];
	$token = static fn( string $name ): string => 'contains(concat(" ", normalize-space(@class), " "), " '. $name. ' ")';
	$serve = static fn( array $file, bool $download ): int => portalServe( $appData, '/.files/'. $file['id']. ( $download === true ? '?download=1' : '' ) )['statusCode'];
	$needs = static fn( string $html ): array => portalFind( portalSection( $html, 'overview' ), '//section[@id="crm-needs"]' );
	$items = static fn( string $html, string $kind ): array => portalFind( portalSection( $html, 'overview' ), '//section[@id="crm-needs"]//li['. $token( 'crm-overview__need--'. $kind ). ']' );
	$row = static fn( string $html, array $file ): string => ( $found = portalFind( $html, '//li[@data-files-id="'. $file['id']. '"]' ) ) === [] ? '' : $found[0]->ownerDocument->saveHTML( $found[0] );

	// the tab Übersicht: what waits
	$waiting = $page( $pW );
	check( 'the tab Übersicht holds the card of what waits only where something does: a project with nothing to do has the data and no card', $needs( $page( $pNone ) ) === [] && $needs( $page( $pD ) ) === [] && portalFind( portalSection( $page( $pNone ), 'overview' ), '//section[contains(@class, "crm-overview__data")]' ) !== [] && count( $needs( $waiting ) ) === 1 );
	check( '...the offer that waits is there with the way to its card in the tab Angebot and the form of its order - the form of the tab Angebot, posting from the tab Übersicht', count( $items( $waiting, 'offer' ) ) === 1
		&& portalFind( portalSection( $waiting, 'overview' ), '//li[@data-document="'. $offerW['id']. '"]//a/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pW. '&tab=angebot#angebot-'. $offerW['id']
		&& portalFind( portalSection( $waiting, 'overview' ), '//form[contains(@class, "crm-order")][@data-document="'. $offerW['id']. '"]/@action' )[0]->nodeValue === '/.crm/auftrag'
		&& portalFind( portalSection( $waiting, 'overview' ), '//form[@data-document="'. $offerW['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'uebersicht' && portalFind( portalSection( $waiting, 'overview' ), '//form[@data-document="'. $offerW['id']. '"]//input[@type="checkbox"][@name="agree"][@required]' ) !== []
		&& portalFind( portalSection( $waiting, 'offer' ), '//form[@data-document="'. $offerW['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'projekt' && portalLeft( $waiting ) === false );
	check( '...an open invoice of the project is there, with its amount, its day and the way to its row in the tab Belege; no approval is asked for while the offer waits', count( $items( $waiting, 'invoice' ) ) === 1
		&& portalFind( portalSection( $waiting, 'overview' ), '//li[@data-document="'. $invoiceW['id']. '"]//a/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pW. '&tab=belege#beleg-'. $invoiceW['id']
		&& portalFind( portalSection( $waiting, 'overview' ), '//li[@data-document="'. $invoiceW['id']. '"]//time/@datetime' ) !== [] && portalFind( portalSection( $waiting, 'overview' ), '//li[@data-document="'. $invoiceW['id']. '"]//span[contains(@class, "crm-pill")]' ) !== [] && $items( $waiting, 'approval' ) === [] );

	check( 'the card Daten has a download row only where the project has a folder, and says the offer comes first while one waits - not that the download is locked', portalFind( portalSection( $page( $pNone ), 'overview' ), '//div[contains(@class, "crm-overview__fact--download")]' ) === [] && portalFind( portalSection( $page( $pNone ), 'overview' ), '//div[contains(@class, "crm-overview__fact--state")]' ) !== []
		&& portalText( portalSection( $waiting, 'overview' ), '//div[contains(@class, "crm-overview__fact--download")]/dd' ) === $wordsDe['[[/project/crm-portal/projects/overview-download-waiting]]'] && $wordsDe['[[/project/crm-portal/projects/overview-download-waiting]]'] !== $wordsDe['[[/project/crm-portal/projects/overview-download-locked]]'] );
	$zero = $page( $pZero );
	check( 'a null byte in the title or in the description of a project is not in the page: not in the path, not in the heading, not in the tab Übersicht', str_contains( $zero, "\0" ) === false && str_contains( $zero, '%00' ) === false && portalText( $zero, '//nav[contains(@class, "crm-crumbs")]/span[@aria-current="page"]' ) === 'NullTitel'
		&& portalText( $zero, '//div[contains(@class, "crm-head--path")]/h2' ) === 'NullTitel' && portalText( $zero, '//p[contains(@class, "crm-overview__title")]' ) === 'NullTitel' && portalText( $zero, '//p[contains(@class, "crm-project__description")]' ) === 'TextMehr' );

	// the order from the tab Übersicht
	$orderPost = static function( array $over = [] ) use ( &$appData, $offerW ): array {

		$full = Documents::get( $appData, $offerW['id'] );

		return portalServe( $appData, '/.crm/auftrag', 'POST', $over + [ 'document' => (string) $offerW['id'], 'from' => 'uebersicht', 'agree' => '1', 'shown' => hash( 'sha256', \Crm\Records::orderShown( $appData, (array) $full, \Crm\Portal\Order::sentence( $appData, (array) $full ) ) ) ] );
	};
	$ordersBefore = count( $recordsOf( $a['id'], 'order' ) );
	$unchecked = $orderPost( [ 'agree' => '' ] );

	check( 'a post from the tab Übersicht is answered in the tab Übersicht, at the card of what waits: the state, the offer named, nothing kept', $unchecked['statusCode'] === 303 && $location( $unchecked ) === '/portal/projekte?projekt='. $pW. '&tab=uebersicht&state=unchecked&beleg='. $offerW['id']. '#crm-needs' && count( $recordsOf( $a['id'], 'order' ) ) === $ordersBefore );
	$noted = $page( $pW, '&tab=uebersicht&state=unchecked&beleg='. $offerW['id'] );
	check( '...the note stands in the card of the tab Übersicht and nowhere else: not in the tab Angebot, where the same state of the tab Angebot is said by the card of the offer', portalFind( portalSection( $noted, 'overview' ), '//section[@id="crm-needs"]//p[@role="alert"][contains(@class, "crm-order__state--unchecked")]' ) !== []
		&& portalFind( portalSection( $noted, 'offer' ), '//p[contains(@class, "crm-order__state")]' ) === [] && count( portalFind( $noted, '//p[contains(@class, "crm-order__state")]' ) ) === 1 && count( array_unique( array_map( static fn( \DOMElement $node ): string => $node->getAttribute( 'id' ), portalFind( $noted, '//*[@id]' ) ) ) ) === count( portalFind( $noted, '//*[@id]' ) )
		&& portalFind( portalSection( $page( $pW, '&tab=angebot&state=unchecked&beleg='. $offerW['id'] ), 'overview' ), '//p[contains(@class, "crm-order__state")]' ) === [] );
	check( '...a state that names no offer stands in the card as well (the offer may be gone from the page)', portalFind( portalSection( $page( $pW, '&tab=uebersicht&state=invalid' ), 'overview' ), '//section[@id="crm-needs"]//p[contains(@class, "crm-order__state--invalid")]' ) !== []
		&& $location( $orderPost( [ 'document' => '99999' ] ) ) === '/portal/belege?state=invalid' );
	\Crm\Settings::set( $appData, 'privacy_text', 'Text zur Prüfung.' );
	$privacy = portalComponentAt( $appData, '/portal/projekte?projekt='. $pW, '[crm-projects]' );
	check( 'while the Datenschutz comes first the card says so with the way to it, and has no form', portalFind( portalSection( $privacy, 'overview' ), '//li[@data-document="'. $offerW['id']. '"]//p[contains(@class, "crm-order--privacy")]' ) !== [] && portalFind( portalSection( $privacy, 'overview' ), '//form' ) === [] );
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	check( '...and the form is back when it is agreed to or none is asked for', portalFind( portalSection( $page( $pW ), 'overview' ), '//form[@data-document="'. $offerW['id']. '"]' ) !== [] );

	// while the offer waits the files are shut, all of them
	check( 'while an offer of the project waits for the order every file is 404 - the audio, the PDF, the own upload - inline and as a download', array_unique( [ $serve( $wSong, false ), $serve( $wSong, true ), $serve( $wPdf, false ), $serve( $wPdf, true ), $serve( $wMine, false ), $serve( $wMine, true ) ] ) === [ 404 ] );
	$ordered = $orderPost();
	check( 'the order from the tab Übersicht is made: the redirect is the card, the offer is accepted and one record is kept', $ordered['statusCode'] === 303 && $location( $ordered ) === '/portal/projekte?projekt='. $pW. '&tab=uebersicht&state=ordered&beleg='. $offerW['id']. '#crm-needs'
		&& Documents::get( $appData, $offerW['id'] )['state'] === 'accepted' && count( $recordsOf( $a['id'], 'order' ) ) === $ordersBefore + 1 && \Crm\Records::forDocument( $appData, (int) $offerW['id'] )['kind'] === 'order' );
	$after = $page( $pW, '&tab=uebersicht&state=ordered&beleg='. $offerW['id'] );
	check( '...the card then holds the thanks and what is left - the invoice and the approval, with the way to the block in the tab Dateien - and no form', portalFind( portalSection( $after, 'overview' ), '//section[@id="crm-needs"]//p[@role="status"][contains(@class, "crm-order__state--ordered")]' ) !== [] && portalFind( portalSection( $after, 'overview' ), '//form' ) === []
		&& count( $items( $after, 'invoice' ) ) === 1 && portalFind( portalSection( $after, 'overview' ), '//li['. $token( 'crm-overview__need--approval' ). ']//a/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $pW. '&tab=dateien#abnahme' && portalFind( portalSection( $after, 'files' ), '//div[@id="abnahme"]' ) !== [] );
	check( '...and the files are open: the PDF as a download, the own upload, the audio to be played and not saved (the download of the project is off)', $serve( $wPdf, true ) === 200 && $serve( $wMine, true ) === 200 && $serve( $wSong, false ) === 200 && $serve( $wSong, true ) === 404 );
	Db::exec( $appData, 'UPDATE documents SET state = \'paid\' WHERE id = :id', [ 'id' => $invoiceW['id'] ] );
	check( 'an invoice that is paid is not asked for, a project that is done asks for no approval, and the card is gone with the last of them', $items( $page( $pW ), 'invoice' ) === [] && $items( $page( $pD ), 'approval' ) === [] );
	check( '...an open project with files asks for the approval', count( $items( $page( $pF ), 'approval' ) ) === 1 && count( $needs( $page( $pF ) ) ) === 1 );

	// the data and the title
	$files = $page( $pF );
	$overview = portalSection( $files, 'overview' );
	check( 'the title, the day and the description of Übersicht are text: no markup of the title or the description is an element, the line break is <br>', portalText( $overview, '//p[@class="crm-overview__title"]' ) === 'Typen <b>Zwei</b>' && portalFind( $overview, '//b | //i' ) === [] && str_contains( $files, 'Zeile &lt;i&gt;eins&lt;/i&gt;<br>Zeile &#91;members-name]' ) === true
		&& portalFind( $overview, '//p[contains(@class, "crm-project__description")]' ) !== [] && portalFind( portalSection( $page( $pNone ), 'overview' ), '//p[contains(@class, "crm-project__description")]' ) === [] );
	check( '...the card Daten: the client, the start, the state, the download - locked with its word, the number of files, offers and documents with the word of one or many', portalText( $overview, '//div[contains(@class, "crm-overview__fact--client")]/dd' ) === \Crm\Clients::get( $appData, $a['id'] )['company'] && portalFind( $overview, '//div[contains(@class, "crm-overview__fact--started")]//time/@datetime' )[0]->nodeValue === '2026-09-01'
		&& portalFind( $overview, '//span[contains(@class, "crm-overview__download--locked")]' ) !== [] && portalFind( $overview, '//span[contains(@class, "crm-overview__download--free")]' ) === [] && portalFind( $overview, '//div[contains(@class, "crm-overview__fact--state")]//span[contains(@class, "crm-pill")]' ) !== []
		&& portalText( $overview, '//span[contains(@class, "crm-projects__count--files")]/span[@class="crm-projects__number"]' ) === '7' && portalText( $overview, '//span[contains(@class, "crm-projects__count--files")]' ) === '7 '. $wordsDe['[[/project/crm-portal/projects/file-many]]']
		&& portalText( $overview, '//span[contains(@class, "crm-projects__count--offers")]' ) === '0 '. $wordsDe['[[/project/crm-portal/projects/offer-many]]'] && portalText( $overview, '//span[contains(@class, "crm-projects__count--documents")]' ) === '0 '. $wordsDe['[[/project/crm-portal/projects/document-many]]']
		&& portalText( portalSection( $page( $pP ), 'overview' ), '//span[contains(@class, "crm-projects__count--files")]' ) === '1 '. $wordsDe['[[/project/crm-portal/projects/file-one]]' ] );
	check( '...the three counts are told apart for a screen reader: a hidden comma between them, so that it reads "7 Dateien, 0 Angebote, 0 Belege"', count( portalFind( $overview, '//span[contains(@class, "crm-overview__counts")]/span['. $token( 'nino-sr-only' ). ']' ) ) === 2 && preg_match( '/^7 \S+, 0 \S+, 0 \S+$/u', portalText( $overview, '//span[contains(@class, "crm-overview__counts")]' ) ) === 1 );
	\Crm\Projekte::setDownload( $appData, $pF, true, 'owner@example.org' );
	$free = portalSection( $page( $pF ), 'overview' );
	check( '...switched on the download says so, with the day', portalFind( $free, '//span[contains(@class, "crm-overview__download--free")]/time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalFind( $free, '//span[contains(@class, "crm-overview__download--locked")]' ) === [] );
	\Crm\Projekte::setDownload( $appData, $pF, false, 'owner@example.org' );

	// Dateien: the player is a column
	$listPage = $page( $pF, '&tab=dateien' );
	$filesSection = portalSection( $listPage, 'files' );
	check( 'the tab Dateien is a layout of two: the list, and the player as an aside with a label - empty without a file asked for, a card with the sentence that Anhören opens it', count( portalFind( $filesSection, '//div['. $token( 'crm-files-layout' ). ']/div['. $token( 'crm-files-layout__list' ). ']//ul['. $token( 'crm-files__list' ). ']' ) ) === 1
		&& count( portalFind( $filesSection, '//div['. $token( 'crm-files-layout' ). ']/aside['. $token( 'crm-player' ). '][@aria-label][normalize-space(@aria-label) != ""]' ) ) === 1 && portalFind( $filesSection, '//aside['. $token( 'crm-player--empty' ). ']/div['. $token( 'crm-player__empty' ). ']/p' ) !== []
		&& portalFind( $filesSection, '//aside//*['. $token( 'review-player' ). ']' ) === [] && portalLeft( $listPage ) === false && portalText( $filesSection, '//aside//p['. $token( 'crm-player__text' ). ']' ) !== '' );
	$playing = portalSection( $page( $pF, '&review='. $fSong['id'] ), 'files' );
	check( '...with a file asked for the aside holds the player of it, and the row of that file is marked', portalFind( $playing, '//div['. $token( 'crm-files-layout' ). ']/aside['. $token( 'crm-player' ). ']//div['. $token( 'review-player' ). '][@data-review-file="'. $fSong['id']. '"]' ) !== [] && portalFind( $playing, '//aside['. $token( 'crm-player--empty' ). ']' ) === [] && portalFind( $playing, '//aside//*['. $token( 'crm-player__empty' ). ']' ) === []
		&& portalFind( $playing, '//li['. $token( 'crm-files__row--playing' ). '][@data-files-id="'. $fSong['id']. '"]' ) !== [] );
	check( '...there is no way back to the list in the player - the list is beside it - and the versions of a title stay over the player', portalFind( $playing, '//*['. $token( 'review-player__back' ). ']' ) === [] && str_contains( $playing, 'review-player__back' ) === false );
	$playingWav = portalSection( $page( $pF, '&review='. $fWav['id'] ), 'files' );
	check( '...the list comes first in the markup and the aside after it, so that the keyboard goes through the list before the player when they are columns; a narrow screen has the player over the list by the order of the sheet', portalFind( $playingWav, '//div['. $token( 'crm-files-layout' ). ']/*[1]['. $token( 'crm-files-layout__list' ). ']' ) !== [] && portalFind( $playingWav, '//div['. $token( 'crm-files-layout' ). ']/*[2][self::aside]' ) !== []
		&& preg_match( '/\.crm-player\s*\{[^}]*order:\s*-1;/s', (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.css' ) ) === 1 );

	// Dateien: the rows and the note
	check( 'the row of a PDF, a ZIP and a text has the button Herunterladen and the name as a link to the download whatever the project says, no sentence of a lock, no way to listen', array_filter( [ $fPdf, $fZip, $fTxt ], static function( array $file ) use ( $listPage, $row ): bool {

		$html = $row( $listPage, $file );

		return portalFind( $html, '//a[contains(@class, "crm-files__download")]/@href' )[0]->nodeValue !== '/.files/'. $file['id']. '?download=1' || portalFind( $html, '//a[contains(@class, "crm-files__name")]/@href' )[0]->nodeValue !== '/.files/'. $file['id']. '?download=1'
			|| portalFind( $html, '//*[contains(@class, "crm-files__locked")] | //a[contains(@class, "crm-files__listen")]' ) !== [];
	} ) === [] );
	check( '...the row of an audio file has the way to listen and no download; the own upload of the member has both', array_filter( [ $fSong, $fWav ], static fn( array $file ): bool => portalFind( $row( $listPage, $file ), '//a[contains(@class, "crm-files__listen")]' ) === [] || portalFind( $row( $listPage, $file ), '//a[contains(@class, "crm-files__download")]' ) !== [] || str_contains( $row( $listPage, $file ), 'download=1' ) ) === []
		&& portalFind( $row( $listPage, $fMine ), '//a[contains(@class, "crm-files__download")]' ) !== [] );
	check( 'the note with the lock stands over the list while the download is off and the folder holds a medium; not where it holds none, and not once the download is on', count( portalFind( $filesSection, '//p['. $token( 'crm-project__locked' ). ']/svg' ) ) === 1
		&& portalFind( portalSection( $page( $pP, '&tab=dateien' ), 'files' ), '//p['. $token( 'crm-project__locked' ). ']' ) === [] );
	\Crm\Projekte::setDownload( $appData, $pF, true, 'owner@example.org' );
	check( '...and once the download is on', portalFind( portalSection( $page( $pF, '&tab=dateien' ), 'files' ), '//p['. $token( 'crm-project__locked' ). ']' ) === [] && portalFind( $row( $page( $pF, '&tab=dateien' ), $fSong ), '//a[contains(@class, "crm-files__download")]' ) !== [] );
	\Crm\Projekte::setDownload( $appData, $pF, false, 'owner@example.org' );

	// the route: the media only
	check( 'without the download of the project the media are not saved, whatever their type: mp3, wav and the own upload of another type is not asked - the download of an audio file is 404', array_unique( [ $serve( $fSong, true ), $serve( $fWav, true ) ] ) === [ 404 ] );
	check( '...and any other file is given, as a download and inline: a PDF, a ZIP and a text', array_unique( [ $serve( $fPdf, true ), $serve( $fZip, true ), $serve( $fTxt, true ), $serve( $fPdf, false ), $serve( $fZip, false ), $serve( $fTxt, false ) ] ) === [ 200 ] );
	check( 'a video is a medium as audio is: its download is 404, shown inline it is 200, its row has the way to listen and no download', $serve( $fMp4, true ) === 404 && $serve( $fMp4, false ) === 200
		&& portalFind( $row( $listPage, $fMp4 ), '//a[contains(@class, "crm-files__listen")]' ) !== [] && portalFind( $row( $listPage, $fMp4 ), '//a[contains(@class, "crm-files__download")]' ) === [] );
	check( '...the audio is given to be played, and the own upload of the member is saved all the same', $serve( $fSong, false ) === 200 && $serve( $fWav, false ) === 200 && $serve( $fMine, true ) === 200 );
	check( '...allowed() says no to the download of a medium alone', \Nino\Modules\Files::allowed( $appData, $fSong, true ) === false && \Nino\Modules\Files::allowed( $appData, $fPdf, true ) === true && \Nino\Modules\Files::allowed( $appData, $fSong ) === true );

	// with Review off nothing is played and the type still decides: the media are held back, the rest is given
	\Nino\Features::deactivate( $appData, 'review' );
	$hook = static function( array $file ) use ( &$appData, $accounts ): bool {

		$asked = [ 'file' => $file, 'member' => [ 'id' => $accounts['anna']['id'] ], 'download' => true, 'allowed' => true ];
		\Crm\Portal::callbackFileAllowed( $appData, $asked );

		return $asked['allowed'];
	};
	check( 'with Review off the media are those of the type all the same - the download of an audio file is refused, that of a PDF is not', \Crm\Crm::featureOn( $appData, 'review' ) === false && \Crm\Portal::medium( $appData, 'video/mp4' ) === true && \Crm\Portal::medium( $appData, 'application/pdf' ) === false
		&& $hook( $fSong ) === false && $hook( $fPdf ) === true );
	\Nino\Features::activate( $appData, 'review' );

	// the row of tabs on a phone: the open tab is brought into the box by the script, and the row wraps where there is none
	$script = (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.js' );
	$sheet = (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.css' );
	check( 'the script brings the open tab into the row of tabs - the box scrolls (scrollLeft), the page does not (no scrollIntoView on the tab) - when the page is drawn and when it is loaded; the row wraps below 560px, with the script and without', preg_match( '/function showOpenTab\(\) \{(?:(?!\n\t\}).)*\.nino-is-active(?:(?!\n\t\}).)*scrollLeft/s', $script ) === 1 && preg_match( '/function init\(\) \{.*?\bshowOpenTab\(\);.*?addEventListener\( \'load\', showOpenTab \)/s', $script ) === 1
		&& preg_match( '/function showOpenTab\(\) \{(?:(?!\n\t\}).)*\bscrollIntoView/s', $script ) !== 1
		&& preg_match( '/@media \(max-width: 559\.98px\)\s*\{\s*\.crm-project__nav\.nino-tabs-nav\s*\{\s*flex-wrap:\s*wrap;/s', $sheet ) === 1 && preg_match( '/@media \(scripting: none\) and \(max-width: 559\.98px\)/', $sheet ) !== 1 );

	check( 'the player is a box of the project page whose margin the sheet takes away inside the column - with the specificity that beats the rule of the project page - and the head of a project takes the class of its shortcode', preg_match( '/\.crm-project \.crm-player \.review-player\s*\{\s*margin:\s*0;/s', $sheet ) === 1
		&& portalFind( portalHeadAt( $appData, '/portal/projekte?projekt='. $pF, ' class="eigen"' ), '//div[contains(concat(" ", normalize-space(@class), " "), " crm-head--path ")][contains(concat(" ", normalize-space(@class), " "), " eigen ")]' ) !== [] );

	// the words
	$newWords = [ 'section-overview', 'offer-one', 'offer-many', 'overview-needs', 'overview-data', 'overview-offer-read', 'overview-approval', 'overview-approval-link', 'overview-invoice-link', 'overview-client', 'overview-state', 'overview-download', 'overview-download-since', 'overview-download-free', 'overview-download-locked', 'overview-download-waiting', 'overview-content' ];
	$keys = array_merge( array_map( static fn( string $name ): string => '[[/project/crm-portal/projects/'. $name. ']]', $newWords ), [ '[[/project/crm-portal/files/player]]', '[[/project/crm-portal/files/player-empty]]' ] );
	check( 'the new words are in German and English, none empty, and the note of the lock speaks of the audio files', array_diff( $keys, array_keys( $wordsDe ), array_keys( $wordsEn ) ) === [] && array_filter( $keys, static fn( string $key ): bool => trim( $wordsDe[$key] ) === '' || trim( $wordsEn[$key] ) === '' ) === []
		&& $wordsDe['[[/project/crm-portal/projects/files-locked]]'] !== $wordsDe['[[/project/crm-portal/projects/overview-download-locked]]'] );
}

// --- Abnahme: the customer accepts a project, POST /.crm/abnahme ------------------------------------------------------------

/**
 *	The checks of the approval of a project
 */
function portalApprovalChecks( array &$appData, array $a, array $b, array $accounts, array $stray, array $owner, array $invoiceA, string $p1, string $p4, string $today, string $store, array $wordsDe, array $wordsEn, \Closure $hold, \Closure $reset, \Closure $asAdmin, \Closure $asVisitor, \Closure $put, \Closure $made, \Closure $final, \Closure $line, \Closure $ago, \Closure $recordsOf, \Closure $location ): void {

	echo "\nAbnahme and POST /.crm/abnahme\n";

	$hold();
	$reset();
	$asAdmin();
	\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => 'Studio &lt;Eins&gt; &amp; Co [members-name]' ], '*' );
	$folderV = $store::addFolder( $appData, $a['group'], 'Abnahme' );
	$vMix = $put( $folderV, 'Mix.wav', 'wav', 'audio/wav', true, $owner, $ago( 7200 ) );
	$vDoc = $put( $folderV, 'Titel <b>Zwei</b> [[/project/company/general/name]]'. "\0". '.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 7100 ) );
	$vHidden = $put( $folderV, 'Intern.txt', 'txt', 'text/plain', false, $owner, $ago( 7000 ) );
	$vTitle = '<b>Abnahme</b> [crm-client] [[/project/company/general/name]]'. "\0". ' Ende';
	$pV = $made( $vTitle, $a['number'], [ 'folder' => $folderV['id'] ] );
	$folderVEmpty = $store::addFolder( $appData, $a['group'], 'Leer' );
	$pVEmpty = $made( 'Ohne Dateien', $a['number'], [ 'folder' => $folderVEmpty['id'] ] );
	$folderVDone = $store::addFolder( $appData, $a['group'], 'Fertig' );
	$put( $folderVDone, 'Fertig.wav', 'wav', 'audio/wav', true, $owner, $ago( 7000 ) );
	$pVDone = $made( 'Fertig', $a['number'], [ 'folder' => $folderVDone['id'], 'done' => true ] );
	$folderVWait = $store::addFolder( $appData, $a['group'], 'Wartet' );
	$put( $folderVWait, 'Wartet.wav', 'wav', 'audio/wav', true, $owner, $ago( 7000 ) );
	$pVWait = $made( 'Wartet', $a['number'], [ 'folder' => $folderVWait['id'] ] );
	$offerVWait = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => '2099-12-31', 'project' => $pVWait, 'template_key' => 'angebot', 'lines' => [ $line( 'Wartet' ) ] ] );
	Documents::release( $appData, $offerVWait['id'], false );
	$folderVBen = $store::addFolder( $appData, $b['group'], 'Bens Abnahme' );
	$put( $folderVBen, 'Ben.wav', 'wav', 'audio/wav', true, $owner, $ago( 7000 ) );
	$pVBen = $made( 'Bens Projekt', $b['number'], [ 'folder' => $folderVBen['id'] ] );
	$pVMixed = $made( 'Bens Projekt im Ordner von Anna', $b['number'], [ 'folder' => $folderV['id'] ] );
	$vPage = '/portal/projekte?projekt='. $pV;
	$vBlock = static fn( string $html ): array => portalFind( $html, '//section[contains(@class, "crm-project__section--files")]//div[@id="abnahme"]' );
	$vSentence = 'Ich habe die Dateien geprüft und nehme das Projekt '. str_replace( "\0", '', $vTitle ). ' in diesem Stand ab.';
	$vMails = static fn(): array => array_values( array_filter( portalMailsFrom( 0 ), static fn( array $mail ): bool => str_starts_with( (string) ( $mail['subject'] ?? '' ), 'Projekt abgenommen:' ) ) );
	$vApprovals = static fn(): array => $recordsOf( $a['id'], 'approval' );
	$vProject = static fn(): array => (array) \Crm\Projekte::get( $appData, $pV );
	$vShownOf = static function( string $id ) use ( &$appData ): string {
		$project = (array) \Crm\Projekte::get( $appData, $id );

		return \Crm\Records::approvalShown( $project, (array) \Crm\Portal\Projects::visibleOf( $appData, $project ), \Crm\Portal\Approval::sentence( $appData, $project ) );
	};
	$vFiles = static fn(): array => (array) \Crm\Portal\Projects::visibleOf( $appData, $vProject() );
	$vShown = static function() use ( &$appData, $vProject, $vFiles ): string {
		$project = $vProject();

		return \Crm\Records::approvalShown( $project, $vFiles(), \Crm\Portal\Approval::sentence( $appData, $project ) );
	};
	$vAge = static function( int $id, int $seconds ) use ( &$appData ): void {
		Db::exec( $appData, 'UPDATE records SET at = :at WHERE id = :id', [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - $seconds ), 'id' => $id ] );
	};
	portalSignIn( $appData, $accounts['anna'] );
	check( 'the fixtures: a project with two files for the member and one hidden, one without a file, a done one, one whose offer waits, and a project of another client', count( $vFiles() ) === 2 && \Crm\Projekte::get( $appData, $pVDone )['done'] === true && \Crm\Projekte::waiting( $appData, $pVWait, $a['id'] ) !== null && $vApprovals() === []
		&& \Crm\Projekte::get( $appData, $pVBen )['client'] === $b['number'] && \Crm\Portal\Projects::visibleOf( $appData, $vProject() ) !== null );

	// the block while the project can be approved
	$form = $vBlock( portalPage( $appData, $vPage. '&tab=dateien' )[1] );
	$formHtml = portalPage( $appData, $vPage. '&tab=dateien' )[1];
	check( 'under the list of files of an open project stands the block Abnahme: the form with the token, the project, the hash of what it shows and the box - required - with the sentence as its label, the title as text', count( $form ) === 1 && portalFind( $formHtml, '//div[@id="abnahme"]//form[contains(@class, "crm-approval__form")]/@action' )[0]->nodeValue === '/.crm/abnahme'
		&& portalFind( $formHtml, '//div[@id="abnahme"]//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && portalFind( $formHtml, '//div[@id="abnahme"]//input[@name="project"]/@value' )[0]->nodeValue === $pV
		&& portalFind( $formHtml, '//div[@id="abnahme"]//input[@name="shown"]/@value' )[0]->nodeValue === hash( 'sha256', $vShown() ) && portalFind( $formHtml, '//div[@id="abnahme"]//input[@type="checkbox"][@name="agree"][@required][@value="1"]' ) !== []
		&& portalText( $formHtml, '//div[@id="abnahme"]//label' ) === $vSentence && portalFind( $formHtml, '//div[@id="abnahme"]//button[@type="submit"][contains(@class, "crm-btn--primary")]' ) !== [] && portalFind( $formHtml, '//div[@id="abnahme"]//form[contains(@class, "nino-form")]' ) === [] );
	check( '...it stands after the table of the files, in the section Dateien and nowhere else: no form on the other tabs, none in the list of the projects', portalFind( $formHtml, '//section[contains(@class, "crm-project__section--files")]//div[contains(@class, "crm-files")]/following-sibling::div[@id="abnahme"]' ) !== [] && count( portalFind( $formHtml, '//form[contains(@class, "crm-approval__form")]' ) ) === 1
		&& portalFind( portalPage( $appData, '/portal/projekte' )[1], '//form[contains(@class, "crm-approval__form")]' ) === [] && portalFind( portalPage( $appData, '/portal/belege' )[1], '//form[contains(@class, "crm-approval__form")]' ) === [] );
	check( '...the title and the names of the files are text on the page: no <b> of them, no shortcode, no fill of the page, and the null byte of a title is not in the block', portalFind( $formHtml, '//div[@id="abnahme"]//b' ) === [] && str_contains( $formHtml, '&lt;b&gt;Abnahme&lt;/b&gt; &#91;crm-client] &#91;&#91;/project/company/general/name]]' ) === true && str_contains( $form[0]->ownerDocument->saveHTML( $form[0] ), "\0" ) === false && str_contains( $formHtml, 'Titel <b>Zwei' ) === false
		&& str_contains( $form[0]->ownerDocument->saveHTML( $form[0] ), 'Studio' ) === false );
	check( '...what the form shows is the JSON of the project and its files: the two the member sees, the hidden one not', array_column( (array) json_decode( $vShown(), true )['files'], 'name' ) === [ 'Mix.wav', 'Titel <b>Zwei</b> [[/project/company/general/name]]'. "\0". '.pdf' ] && json_decode( $vShown(), true )['project'] === [ 'id' => $pV, 'title' => $vTitle ] && str_contains( $vShown(), "\0" ) === false && str_contains( $vShown(), '\u0000' ) === true
		&& json_decode( $vShown(), true )['files'][0] === [ 'name' => 'Mix.wav', 'size' => 6, 'sha256' => hash( 'sha256', 'abcdef' ), 'date' => gmdate( 'Y-m-d', time() - 7200 ) ] );

	// where there is no block
	$none = [
		'a project without a file'          => $pVEmpty,
		'a project that is done'            => $pVDone,
		'a project whose offer waits'       => $pVWait,
		'a project without a folder'        => $p4,
	];
	foreach( $none as $why => $project )
		check( '...no form for '. $why. ', and no block at all', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $project. '&tab=dateien' )[1], '//div[@id="abnahme"] | //form[contains(@class, "crm-approval__form")]' ) === [] );

	$vClient = (array) \Crm\Clients::get( $appData, $a['id'] );
	$vWaiting = \Crm\Portal\Approval::standing( $appData, $vClient, (array) \Crm\Projekte::get( $appData, $pVWait ), $vFiles() );
	check( 'the offer that waits closes the approval of itself, whatever the files say: the standing of that project with files in hand is not open', $vWaiting['files'] !== [] && $vWaiting['open'] === false );
	check( '...the same files in the standing of a project nobody waits for are open', \Crm\Portal\Approval::standing( $appData, $vClient, $vProject(), $vFiles() )['open'] === true );

	check( '...a project of another client is the list with the sentence that it is unknown', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $pVBen. '&tab=dateien' )[1], '//div[@id="abnahme"] | //form[contains(@class, "crm-approval__form")] | //article[contains(@class, "crm-project")]' ) === [] );

	// the post that is refused
	$apPost = static function( array $over = [], array $server = [] ) use ( &$appData, $pV, $vShown ): array {

		$post = $over + [ 'project' => $pV, 'agree' => '1' ];

		// The form carries the hash of the files as it showed them: a post that does not say otherwise is the post of a form that showed the project of now
		if( array_key_exists( 'shown', $post ) === true && $post['shown'] === null )
			unset( $post['shown'] );
		elseif( array_key_exists( 'shown', $post ) === false )
			$post['shown'] = $post['project'] === $pV ? hash( 'sha256', $vShown() ) : hash( 'sha256', 'x' );

		return portalServe( $appData, '/.crm/abnahme', 'POST', $post, $server );
	};
	$vLocation = static fn( string $state, string $project = '' ): string => '/portal/projekte?projekt='. ( $project === '' ? $pV : $project ). '&tab=dateien&state='. $state. '#abnahme';
	$vUntouched = static fn(): bool => $vApprovals() === [] && $vMails() === [];
	$asVisitor();
	check( 'a visitor posting the form is answered 404', $apPost()['statusCode'] === 404 && $vUntouched() );
	portalSignIn( $appData, $stray );
	check( '...so is a member of no client', $apPost()['statusCode'] === 404 && $vUntouched() && portalServe( $appData, '/.crm/abnahme', 'GET' )['statusCode'] !== 200 );
	portalSignIn( $appData, $accounts['anna'] );
	$vRefusals = [
		'a token that is not the session\'s'        => [ [ '_csrf' => 'wrong' ], $vLocation( 'expired' ) ],
		'a box that is not ticked'                  => [ [ 'agree' => '' ], $vLocation( 'unchecked' ) ],
		'a box sent as anything but 1'              => [ [ 'agree' => 'on' ], $vLocation( 'unchecked' ) ],
		'files that are not the ones the form showed' => [ [ 'shown' => hash( 'sha256', 'andere Dateien' ) ], $vLocation( 'changed' ) ],
		'a form without the hash'                   => [ [ 'shown' => '' ], $vLocation( 'changed' ) ],
		'a post without the field at all'           => [ [ 'shown' => null ], $vLocation( 'changed' ) ],
		'a project of another client'               => [ [ 'project' => $pVBen ], $vLocation( 'invalid', $pVBen ) ],
		'a project of another client whose folder is the member\'s' => [ [ 'project' => $pVMixed, 'shown' => hash( 'sha256', $vShownOf( $pVMixed ) ) ], $vLocation( 'invalid', $pVMixed ) ],
		'a project that is not there'               => [ [ 'project' => '99999' ], $vLocation( 'invalid', '99999' ) ],
		'a project that is done'                    => [ [ 'project' => $pVDone ], $vLocation( 'invalid', $pVDone ) ],
		'a project without a file'                  => [ [ 'project' => $pVEmpty ], $vLocation( 'invalid', $pVEmpty ) ],
		'a project whose offer waits'               => [ [ 'project' => $pVWait ], $vLocation( 'invalid', $pVWait ) ],
		'a project without a folder'                => [ [ 'project' => $p4 ], $vLocation( 'invalid', $p4 ) ],
		'a project that is no id'                   => [ [ 'project' => '../x' ], '/portal/projekte?state=invalid' ],
		'a list as the project'                     => [ [ 'project' => [ $pV ] ], '/portal/projekte?state=invalid' ],
		'no project'                                => [ [ 'project' => '' ], '/portal/projekte?state=invalid' ],
	];

	foreach( $vRefusals as $why => [ $over, $where ] ) {
		$answer = $apPost( $over );
		check( 'refused - '. $why. ': 303 to '. $where. ', nothing is kept, nobody is mailed', $answer['statusCode'] === 303 && $location( $answer ) === $where && $vUntouched() );
	}

	// the state is said above the block, on the tab Dateien; the states of the order stand on the tab Angebot, not there
	$pStates = portalPage( $appData, $vPage. '&tab=dateien&state=unchecked' )[1];
	check( 'a state of the query is said above the block as a note - an alert for a refusal, a status for the approval - and a state that is none says nothing', portalFind( $pStates, '//div[@id="abnahme"]/p[@id="crm-approval-state"][@role="alert"][contains(@class, "crm-approval__state--unchecked")]' ) !== []
		&& portalFind( portalPage( $appData, $vPage. '&tab=dateien&state=approved' )[1], '//div[@id="abnahme"]/p[@id="crm-approval-state"][@role="status"][contains(@class, "crm-approval__state--approved")]' ) !== [] && portalFind( portalPage( $appData, $vPage. '&tab=dateien&state=bogus' )[1], '//p[@id="crm-approval-state"]' ) === []
		&& portalFind( portalPage( $appData, $vPage. '&tab=dateien&state=ordered' )[1], '//p[@id="crm-approval-state"]' ) === [] );
	check( '...the same word on another tab is the order\'s: the tab Dateien shows no note of the order, the tab Angebot none of the approval', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=dateien&state=invalid' )[1], '//p[contains(@class, "crm-order__state")]' ) === [] && portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' )[1], '//p[contains(@class, "crm-order__state")]' ) !== []
		&& portalFind( portalPage( $appData, $vPage. '&tab=angebot&state=invalid' )[1], '//p[@id="crm-approval-state"]' ) === [] );

	// a file that came in between: the form of the old page is the old list
	$formShown = hash( 'sha256', $vShown() );
	$vLate = $put( $folderV, 'Dazwischen.wav', 'wav', 'audio/wav', true, $owner, $ago( 1000 ) );
	$answer = $apPost( [ 'shown' => $formShown ] );
	check( 'a file that came into the folder after the page was drawn is ?state=changed: the form of the old list approves nothing - and the page now shows the list as it is, with a new hash', $location( $answer ) === $vLocation( 'changed' ) && $vUntouched() && portalFind( portalPage( $appData, $vPage. '&tab=dateien' )[1], '//input[@name="shown"]/@value' )[0]->nodeValue !== $formShown && count( $vFiles() ) === 3 );

	// the approval
	$reset();
	$beforeMails = count( portalMailsFrom( 0 ) );
	$vAgent = 'Browser/1.0 (Abnahme)';
	$approved = $apPost( [], [ 'HTTP_USER_AGENT' => $vAgent ] );
	$vRecords = $vApprovals();
	check( 'the approval: ?state=approved on the tab Dateien of the project, and one record of kind approval is kept', $approved['statusCode'] === 303 && $location( $approved ) === $vLocation( 'approved' ) && count( $vRecords ) === 1 && $vRecords[0]['kind'] === 'approval' );
	check( '...the record names the project and no document, the account as it was, the client, the time, the address and the browser; the project is not done - that stays the owner\'s step', $vRecords[0]['project'] === $pV && $vRecords[0]['document_id'] === null && $vRecords[0]['client_id'] === $a['id'] && $vRecords[0]['member_id'] === $accounts['anna']['id'] && $vRecords[0]['member_email'] === 'anna@example.org' && $vRecords[0]['member_name'] === 'Anna Nord'
		&& substr( $vRecords[0]['at'], 0, 10 ) === gmdate( 'Y-m-d' ) && $vRecords[0]['ip'] === '127.0.0.1' && $vRecords[0]['agent'] === $vAgent && \Crm\Projekte::get( $appData, $pV )['done'] === false && \Crm\Records::approval( $appData, $pV ) === $vRecords[0] );
	$vJson = (array) json_decode( $vRecords[0]['shown'], true );
	check( '...what the customer was shown is kept with its hash: the project, the three files with name, size, SHA-256 and day, and the sentence as read - the text can be made again from the same files', $vRecords[0]['sha256'] === hash( 'sha256', $vRecords[0]['shown'] ) && $vJson['project'] === [ 'id' => $pV, 'title' => $vTitle ] && count( $vJson['files'] ) === 3 && $vJson['files'][0]['name'] === 'Dazwischen.wav' && $vJson['sentence'] === $vSentence
		&& $vShown() === $vRecords[0]['shown'] && str_contains( $vRecords[0]['shown'], "\0" ) === false );
	$ownerMails = $vMails();
	$vSubject = 'Projekt abgenommen: '. $pV. ' '. str_replace( "\0", ' ', $vTitle ). ' von Kunde K-0001 '. ( \Crm\Clients::get( $appData, $a['id'] )['company'] ). ' durch Anna Nord';
	check( 'one mail goes out, to the owner: "Projekt abgenommen: <id and title> von Kunde <number and company> durch <account>" - a title without its null byte -, the reply to the account; none to the customer', count( $ownerMails ) === 1 && count( portalMailsFrom( $beforeMails ) ) === 1 && ( $ownerMails[0]['to'] ?? '' ) === 'owner@example.org' && ( $ownerMails[0]['replyTo'] ?? '' ) === 'anna@example.org'
		&& preg_replace( '/\s+/', ' ', (string) $ownerMails[0]['subject'] ) === preg_replace( '/\s+/', ' ', $vSubject ) && str_contains( (string) $ownerMails[0]['subject'], "\0" ) === false && array_filter( portalMailsFrom( 0 ), static fn( array $mail ): bool => ( $mail['to'] ?? '' ) === 'anna@example.org' && str_contains( (string) ( $mail['subject'] ?? '' ), 'abgenommen' ) ) === [] );
	$vBody = (string) $ownerMails[0]['body'];
	check( '...its body names the project, the client, the number of files and the account, links to the card of the project in the surface (#projekt-<id>), and every value is text: no <b>, no fill, no shortcode, no null byte, nothing of a template left', str_contains( $vBody, 'href="https://example.org/club/crm#projekt-'. $pV. '"' ) === true && str_contains( $vBody, 'K-0001' ) === true && str_contains( $vBody, 'Dateien: 3' ) === true && str_contains( $vBody, 'anna@example.org' ) === true
		&& portalFind( $vBody, '//b' ) === [] && str_contains( $vBody, '&lt;b&gt;Abnahme&lt;/b&gt;' ) === true && str_contains( $vBody, "\0" ) === false && str_contains( $vBody, '[[' ) === false && str_contains( $vBody, 'Studio' ) === false && preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', strip_tags( $vBody ) ) !== 1 );

	// the page after it
	$after = portalPage( $appData, $vPage. '&tab=dateien' )[1];
	$doneLine = portalFind( $after, '//div[@id="abnahme"]/p[contains(@class, "crm-approval__done")]' );
	check( 'after the approval the block says when and by whom - the icon of a check, the day as a time element, the name as text - and has no form', count( $doneLine ) === 1 && portalFind( $after, '//div[@id="abnahme"]/p[contains(@class, "crm-approval__done")]/svg' ) !== [] && portalFind( $after, '//div[@id="abnahme"]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' )
		&& portalText( $after, '//div[@id="abnahme"]//span[contains(@class, "crm-approval__who")]' ) === 'Anna Nord' && portalFind( $after, '//form[contains(@class, "crm-approval__form")]' ) === [] && str_contains( $doneLine[0]->getAttribute( 'class' ), 'crm-note--success' ) === true );
	check( '...the head of the page carries the badge abgenommen beside the state - and a project nobody approved does not', portalFind( portalPage( $appData, $vPage )[1], '//*[contains(@class, "crm-head__badge")]/*[contains(@class, "crm-head__approved")]' ) !== [] && portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], '//*[contains(@class, "crm-head__approved")]' ) === []
		&& portalFind( portalPage( $appData, $vPage )[1], '//*[contains(@class, "crm-head__badge")]/*[contains(@class, "crm-head__state")]' ) !== [] );
	$again = $apPost();
	check( 'a second approval with no file since is refused: ?state=invalid, nothing new is kept, nobody is mailed again', $location( $again ) === $vLocation( 'invalid' ) && count( $vApprovals() ) === 1 && count( $vMails() ) === 1 );
	$double = $apPost( [ 'shown' => hash( 'sha256', $vShown() ) ] );
	check( '...nor does a second click on the old form: the same', $location( $double ) === $vLocation( 'invalid' ) && count( $vApprovals() ) === 1 );

	// a new file: the second approval (the first one is some minutes old by now, the file is younger than it)
	$vAge( $vRecords[0]['id'], 500 );
	$vNew = $put( $folderV, 'Nachtrag.wav', 'wav', 'audio/wav', true, $owner, $ago( 100 ) );
	$news = portalPage( $appData, $vPage. '&tab=dateien' )[1];
	check( 'a file that came into the folder after the approval brings the form back under the line, with the sentence that there are new files - the old line stays', portalFind( $news, '//div[@id="abnahme"]/p[contains(@class, "crm-approval__done")]' ) !== [] && count( portalFind( $news, '//div[@id="abnahme"]/form[contains(@class, "crm-approval__form")]' ) ) === 1 && portalFind( $news, '//form[contains(@class, "crm-approval__form")]//p[contains(@class, "crm-approval__news")]' ) !== []
		&& portalFind( $news, '//div[@id="abnahme"]/p[contains(@class, "crm-approval__done")]/following-sibling::form' ) !== [] && portalFind( $news, '//input[@name="shown"]/@value' )[0]->nodeValue === hash( 'sha256', $vShown() ) );
	check( '...a file the member cannot see does not bring it back: the hidden file of the owner, a file that was removed from the server', portalFind( $after, '//p[contains(@class, "crm-approval__news")]' ) === [] );
	$second = $apPost();
	$vRecords = $vApprovals();
	check( 'the second approval is a record of its own - the newest first - with the four files; the first is as it was; the owner is mailed again', $location( $second ) === $vLocation( 'approved' ) && count( $vRecords ) === 2 && count( (array) json_decode( $vRecords[0]['shown'], true )['files'] ) === 4 && count( (array) json_decode( $vRecords[1]['shown'], true )['files'] ) === 3 && \Crm\Records::approval( $appData, $pV ) === $vRecords[0] && count( $vMails() ) === 2
		&& portalFind( portalPage( $appData, $vPage. '&tab=dateien' )[1], '//form[contains(@class, "crm-approval__form")]' ) === [] );

	// a file removed from the server is not shown, not counted and not approved
	$store::removeBytes( $appData, $vNew['id'] );
	check( '...a file that was removed from the server is left out of the JSON, and a removal does not count as a new file', count( (array) json_decode( $vShown(), true )['files'] ) === 3 && portalFind( portalPage( $appData, $vPage. '&tab=dateien' )[1], '//form[contains(@class, "crm-approval__form")]' ) === [] );

	// the mail that does not go out does not undo the approval
	$vAge( $vApprovals()[0]['id'], 300 );
	$vNew2 = $put( $folderV, 'Nachtrag Zwei.wav', 'wav', 'audio/wav', true, $owner, $ago( 50 ) );
	$reset();

	for( $n = 0; $n < 5; $n++ )
		Documents::release( $appData, $invoiceA['id'], true );

	$nomail = $apPost();
	check( 'where the mail to the owner does not go out the approval is kept all the same and the page says so: ?state=nomail', $location( $nomail ) === $vLocation( 'nomail' ) && count( $vApprovals() ) === 3 && portalFind( portalPage( $appData, $vPage. '&tab=dateien&state=nomail' )[1], '//p[@id="crm-approval-state"][@role="status"]' ) !== [] );
	$reset();

	// a project of another client has its own approvals, and the record is the client's
	$asVisitor();
	check( 'the records of the client are its own: Ben has none, and the approval of a project is read for the client that owns it', $recordsOf( $b['id'], 'approval' ) === [] && \Crm\Portal\Approval::approved( $appData, (array) \Crm\Clients::get( $appData, $b['id'] ), $vProject() ) === null && \Crm\Portal\Approval::approved( $appData, (array) \Crm\Clients::get( $appData, $a['id'] ), $vProject() )['id'] === $vApprovals()[0]['id'] );
	portalSignIn( $appData, $accounts['anna'] );

	// a project that is done after the approval says abgeschlossen and has no form; the head keeps the badge
	\Nino\Elements::updateElement( $appData, '/projekte/'. $pV, [ 'done' => true ], '*' );
	unset( $appData['./crm/portal/project'] );
	$vDone = portalPage( $appData, $vPage. '&tab=dateien' )[1];
	check( 'a project that the owner finished has no form, however many files came since; the line of the approval stays and so does the badge', portalFind( $vDone, '//form[contains(@class, "crm-approval__form")]' ) === [] && portalFind( $vDone, '//div[@id="abnahme"]/p[contains(@class, "crm-approval__done")]' ) !== [] && portalFind( $vDone, '//*[contains(@class, "crm-head__approved")]' ) !== []
		&& $location( $apPost() ) === $vLocation( 'invalid' ) );
	\Nino\Elements::updateElement( $appData, '/projekte/'. $pV, [ 'done' => false ], '*' );

	// two posts of one page: the second looks at the records again inside the write, so that the files it was handed do not approve the project a second time
	$folderVRace = $store::addFolder( $appData, $a['group'], 'Doppelt' );
	$vRaceFile = $put( $folderVRace, 'Doppelt.wav', 'wav', 'audio/wav', true, $owner, $ago( 7000 ) );
	$pVRace = $made( 'Doppelt', $a['number'], [ 'folder' => $folderVRace['id'] ] );
	$vRace = (array) \Crm\Projekte::get( $appData, $pVRace );
	// Reflection on the private keep(), against the rule of AGENTS §10: two requests cannot meet inside one process by the public route, where standing() answers the second with invalid before the write; only the write itself shows that it looks again
	$vKeep = new \ReflectionMethod( \Crm\Portal\Approval::class, 'keep' );
	$vKeepArgs = [ &$appData, $vClient, $vRace, [ $vRaceFile ], [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ], '{"project":"race"}', [] ];
	$vFirst = $vKeep->invokeArgs( null, $vKeepArgs );
	$vSecond = $vKeep->invokeArgs( null, $vKeepArgs );
	check( 'two posts that both found the project open keep one record: the second is turned away inside the write', is_array( $vFirst ) === true && $vSecond === 'state' );
	check( '...and the table holds that one record for the project', count( array_filter( $vApprovals(), static fn( array $record ): bool => $record['project'] === $pVRace ) ) === 1 );

	// the names in the subject: no control and no format character, whatever the kernel does with a subject
	$reset();
	$vBefore = count( portalMailsFrom( 0 ) );
	\Crm\Documents\Mail::approved( $appData, [ 'id' => '00099', 'title' => "Ein\x07Zwei\u{202E}Drei\u{200B}Vier" ], [ 'member_name' => "Anna\x1BNord", 'member_email' => 'anna@example.org' ], $vClient, 1 );
	$vSent = portalMailsFrom( $vBefore );
	check( 'the mail to the owner takes the title of the project and the name of the account into its subject as one line: a control character, a mark that turns the text round or has no width is a blank', count( $vSent ) === 1 && ( $vSent[0]['subject'] ?? '' ) === 'Projekt abgenommen: 00099 Ein Zwei Drei Vier von Kunde '. $vClient['number']. ' '. $vClient['title']. ' durch Anna Nord' );

	// the words
	$wordsApproval = array_merge( array_map( static fn( string $name ): string => '[[/project/crm-portal/approval/'. $name. ']]', [ 'heading', 'sentence', 'button', 'done', 'by', 'news', 'state-approved', 'state-nomail', 'state-invalid', 'state-unchecked', 'state-expired', 'state-changed' ] ),
		[ '[[/project/crm-portal/projects/state-approved]]', '[[/project/crm-portal/pay/paypal]]', '[[/project/crm-portal/pay/paypal-button]]', '[[/project/crm-portal/pay/paypal-purpose]]' ] );
	check( 'the new words are in German and English, and the sentence of the box and the one beside the button carry their place for the title and for the number', array_diff( $wordsApproval, array_keys( $wordsDe ), array_keys( $wordsEn ) ) === [] && array_filter( $wordsApproval, static fn( string $key ): bool => trim( $wordsDe[$key] ) === '' || trim( $wordsEn[$key] ) === '' ) === []
		&& str_contains( $wordsDe['[[/project/crm-portal/approval/sentence]]'], '{title}' ) && str_contains( $wordsEn['[[/project/crm-portal/approval/sentence]]'], '{title}' ) && str_contains( $wordsDe['[[/project/crm-portal/pay/paypal-purpose]]'], '{number}' ) && str_contains( $wordsEn['[[/project/crm-portal/pay/paypal-purpose]]'], '{number}' ) );
	check( '...the states of the approval are the states of the page, each with its word', array_diff( array_keys( \Crm\Portal\Approval::STATES ), array_map( static fn( string $key ): string => substr( $key, strlen( '[[/project/crm-portal/approval/state-' ), -2 ), array_filter( $wordsApproval, static fn( string $key ): bool => str_contains( $key, '/state-' ) && str_contains( $key, '/approval/' ) ) ) ) === [] );
	check( '...the mail words are German with Du', array_filter( [ 'approval.owner.subject', 'approval.owner.title', 'approval.owner.intro', 'approval.owner.project', 'approval.owner.client', 'approval.owner.files', 'approval.owner.account', 'approval.owner.button', 'approval.owner.notice' ], static fn( string $key ): bool => trim( \Crm\Documents\Mail::text( $key, '', '', '' ) ) === '' ) === [] );
	check( '...the route is registered once and only for the POST', ( $appData['/nino/http/routes']['POST://.crm/abnahme']['uri'] ?? '' ) === \Crm\Portal\Approval::ROUTE && isset( $appData['/nino/http/routes']['GET://.crm/abnahme'] ) === false );
}

portalApprovalChecks( $appData, $a, $b, $accounts, $stray, $owner, $invoiceA, $p1, $p4, $today, $store, $wordsDe, $wordsEn, $hold, $reset, $asAdmin, $asVisitor, $put, $made, $final, $line, $ago, $recordsOf, $location );
portalOverviewChecks( $appData, $a, $accounts, $owner, $today, $store, $wordsDe, $wordsEn, $hold, $reset, $asAdmin, $put, $made, $final, $line, $ago, $recordsOf, $location );
portalPlayerChecks( $appData, $a, $b, $accounts, $stray, $owner, $store, $today, $hold, $reset, $asAdmin, $asVisitor, $put, $made, $final, $line, $ago );

$resume();

portalFixRoundChecks( $appData, $a, $accounts, $owner, $store, $today, $post, $asAdmin, $asVisitor, $put, $made, $final, $line, $ago );

// --- The finishing touches of the portal ---------------------------------------------------------------------------------------------

/**
 *	The start page with the section over the rest, the list of documents in the order of what asks for something and its pills, the order of the fields of Meine Daten and the message a member puts away
 *
 *	@param		array 		&$appData
 *	@param		array 		$accounts			The accounts of the suite by name; ben is the one of another client
 *	@param		array 		$stray				A member of no client
 *
 *	@return 	void
 */
function portalFinishingTouches( array &$appData, array $accounts, array $stray ): void {

	global $mails;

	$today = date( 'Y-m-d' );
	$line = static fn( string $title ): array => [ 'title' => $title, 'description' => '', 'unit' => 'Std.', 'qty' => '2', 'price' => '50', 'vat_rate' => 0 ];
	$make = static function( string $category, int $clientId, array $input ) use ( &$appData ): int {
		$draft = Documents::add( $appData, $category, $clientId, $input );
		$done = is_array( $draft ) === true ? Documents::finalise( $appData, $draft['id'] ) : $draft;

		return is_array( $done ) === true && is_array( Documents::release( $appData, $done['id'], false ) ) === true ? $done['id'] : 0;
	};
	$location = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
	$ids = static fn( string $html ): array => array_map( static fn( \DOMElement $row ): int => (int) $row->getAttribute( 'data-document' ), portalFind( $html, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' ) );
	$pressed = static fn( string $html ): array => array_map( static fn( \DOMElement $pill ): string => $pill->getAttribute( 'data-filter' ), portalFind( $html, '//div[contains(@class, "crm-filter")]/button[@aria-pressed="true"]' ) );

	\Nino\Features::deactivate( $appData, 'events' );
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	\Crm\Settings::set( $appData, 'portal_message', '' );
	$created = \Crm\Clients::add( $appData, [ 'company' => 'Feinschliff GmbH', 'name' => 'Frieda Fein', 'street' => 'Weg 9', 'zip' => '01067', 'city' => 'Dresden', 'country' => 'DE' ], [ 'email' => 'fein@example.org', 'name' => 'Frieda Fein', 'notify' => false ] );
	$client = (int) $created['id'];
	$frieda = (array) ( $created['first']['account'] ?? [] );
	$second = \Nino\Modules\Members::add( $appData, 'fein2@example.org', 'Zweite Fein', $created['group'] );
	\Crm\Clients::link( $appData, $client, $second['id'] );

	echo "\nThe section over the start page\n";

	check( 'the fixtures of the finishing touches: a client with two accounts, ready, nothing pending', $frieda !== [] && $created['ready'] === true && is_array( $second ) === true && \Crm\Portal::pending( $appData, (array) \Crm\Clients::get( $appData, $client ) ) === '' );
	portalSignIn( $appData, $frieda );
	$bare = portalPage( $appData, '/portal' )[1];
	$top = '//section[contains(concat(" ", normalize-space(@class), " "), " crm-start__top ")]';
	$labelled = portalFind( $bare, $top. '/@aria-labelledby' );
	check( 'a client with no file and no offer has the section over the rest: with a heading of its own that the section names, one card - the files - that says no file came and has no button to the projects (the navigation leads there), and no card of offers', count( portalFind( $bare, $top ) ) === 1 && $labelled !== [] && portalFind( $bare, $top. '/*[@id="'. ( $labelled[0]->nodeValue ?? '' ). '"]' ) !== []
		&& array_map( static fn( \DOMElement $card ): string => $card->getAttribute( 'data-card' ), portalFind( $bare, $top. '/article' ) ) === [ 'files' ] && portalFind( $bare, $top. '/article//li' ) === [] && trim( portalText( $bare, $top. '/article[@data-card="files"]/p[contains(@class, "crm-start__text")]' ) ) !== ''
		&& portalFind( $bare, $top. "/article[@data-card=\"files\"]//a" ) === [] && portalFind( $bare, '//*[contains(@class, "crm-start__top--pair")]' ) === [] && portalFind( $bare, '//article[@data-card="offers"]' ) === [] );
	check( '...it stands under the head of the page and over the section "Was gerade ansteht"; the section of the rest has no card of files', strpos( $bare, 'crm-head__row' ) < strpos( $bare, 'crm-start__top' ) && strpos( $bare, 'crm-start__top' ) < strpos( $bare, 'crm-start-heading' )
		&& portalFind( $bare, '//section[@aria-labelledby="crm-start-heading"]//article[@data-card="files"]' ) === [] );
	$offer = $make( 'offer', $client, [ 'date' => $today, 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Wartet' ) ] ] );
	Db::exec( $appData, 'UPDATE documents SET released_at = \'2020-01-01T00:00:00Z\' WHERE id = :id', [ 'id' => $offer ] );
	$waiting = portalPage( $appData, '/portal' )[1];
	check( 'with an offer that waits the section has two cards - the files first, the offers beside them - in the two-column mode; the key figures stay under the heading, and no card of the offers stands there', $offer > 0
		&& array_map( static fn( \DOMElement $card ): string => $card->getAttribute( 'data-card' ), portalFind( $waiting, $top. '/article' ) ) === [ 'files', 'offers' ] && portalFind( $waiting, $top. '[contains(@class, "crm-start__top--pair")]' ) !== []
		&& count( portalFind( $waiting, $top. '/article[@data-card="offers"]//li' ) ) === 1 && portalFind( $waiting, '//section[@aria-labelledby="crm-start-heading"]//article[@data-card="offers"]' ) === []
		&& portalFind( $waiting, '//section[@aria-labelledby="crm-start-heading"]/ul[contains(@class, "crm-kpis")]' ) !== [] && portalFind( $waiting, $top. '//ul[contains(@class, "crm-kpis")]' ) === [] );
	check( '...the section of the rest has no sentence that nothing is up where an offer waits, and no empty grid', portalFind( $waiting, '//*[contains(@class, "crm-start__nothing")]' ) === [] && portalFind( $waiting, '//div[contains(@class, "crm-start__grid")]' ) === [] );
	$words = (array) require dirname( __DIR__ ). '/Portal/text/de_DE.php';
	$headingOf = static fn( string $html ): string => portalText( $html, '//h3[@id="crm-start-top-heading"]' );
	check( '...the heading of the section says what is in it: the word of the files alone where only the files card stands, the word of both where the offers stand beside it, and the two are not one word', $headingOf( $bare ) === $words['[[/project/crm-portal/start/top-files]]'] && $headingOf( $waiting ) === $words['[[/project/crm-portal/start/top]]'] && $headingOf( $bare ) !== $headingOf( $waiting ) && $headingOf( $bare ) !== '' );
	Documents::setState( $appData, $offer, 'declined' );
	$closed = portalPage( $appData, '/portal' )[1];
	check( '...and with the offer gone the section is the files card alone, and the section of the rest says that nothing is up', count( portalFind( $closed, $top. '/article' ) ) === 1 && portalFind( $closed, '//div[contains(@class, "crm-start__nothing")]' ) !== [] );

	echo "\nThe order of the documents and the pills\n";

	$overdueOld = $make( 'invoice', $client, [ 'date' => '2026-08-01', 'template_key' => 'rechnung', 'lines' => [ $line( 'Alt' ) ] ] );
	$overdueNew = $make( 'invoice', $client, [ 'date' => '2026-09-01', 'template_key' => 'rechnung', 'lines' => [ $line( 'Neu' ) ] ] );
	$offerOld = $make( 'offer', $client, [ 'date' => '2026-10-10', 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'A1' ) ] ] );
	$offerNew = $make( 'offer', $client, [ 'date' => '2026-10-12', 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'A2' ) ] ] );
	$open = $make( 'invoice', $client, [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( 'Offen' ) ] ] );
	$infoOld = $make( 'information', $client, [ 'date' => '2026-10-25', 'title' => 'Info alt', 'template_key' => 'zugang' ] );
	$infoNew = $make( 'information', $client, [ 'date' => '2026-10-26', 'title' => 'Info neu', 'template_key' => 'zugang' ] );
	$paid = $make( 'invoice', $client, [ 'date' => '2026-10-23', 'template_key' => 'rechnung', 'lines' => [ $line( 'Bezahlt' ) ] ] );
	$contract = $make( 'contract', 0, [ 'parent_id' => $offerOld, 'date' => '2026-10-22', 'title' => 'Vertrag', 'template_key' => 'vertrag', 'inputs' => [ 'zusatz' => 'x' ] ] );
	$privacy = $make( 'privacy', $client, [ 'date' => '2026-10-21', 'title' => 'Datenschutz', 'template_key' => 'datenschutz' ] );
	$expired = $make( 'offer', $client, [ 'date' => '2026-10-27', 'valid_until' => date( 'Y-m-d', strtotime( '+20 days' ) ), 'template_key' => 'angebot', 'lines' => [ $line( 'Abgelaufen' ) ] ] );
	Db::exec( $appData, 'UPDATE documents SET valid_until = \'2026-01-01\' WHERE id = :id', [ 'id' => $expired ] );
	Documents::setState( $appData, $overdueOld, 'sent', '2026-08-01' );
	Documents::setState( $appData, $overdueNew, 'sent', '2026-09-01' );
	Documents::setState( $appData, $paid, 'sent', '2026-10-23' );
	Documents::setState( $appData, $paid, 'paid', '2026-10-24' );
	Db::exec( $appData, 'UPDATE documents SET due_date = \'2026-09-15\' WHERE id IN ( :one, :two )', [ 'one' => $overdueOld, 'two' => $overdueNew ] );
	$list = portalPage( $appData, '/portal/belege' )[1];
	$rows = portalFind( $list, '//li[contains(concat(" ", normalize-space(@class), " "), " crm-documents__row ")]' );
	check( 'the documents: what asks for something first - the overdue invoices, then the offers that can be ordered, then the open invoice -, then the information, then the rest, each by its day and the newest first', $ids( $list ) === [ $overdueNew, $overdueOld, $offerNew, $offerOld, $open, $infoNew, $infoOld, $offer, $expired, $paid, $contract, $privacy ] );
	check( '...each row names its kind (data-kind), and the rows that ask for something carry data-due', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-kind' ), $rows ) === [ 'invoice', 'invoice', 'offer', 'offer', 'invoice', 'information', 'information', 'offer', 'offer', 'invoice', 'contract', 'privacy' ]
		&& array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-due' ), $rows ) === [ '1', '1', '1', '1', '1', '', '', '', '', '', '', '' ] );
	$group = portalFind( $list, '//div[contains(@class, "crm-filter")]' );
	$pills = portalFind( $list, '//div[contains(@class, "crm-filter")]/button' );
	check( 'the pills: a group with a label, hidden until the script shows it, over the list - All, Action needed, then a pill for each kind the client has, each a button of type button with aria-pressed, All pressed', count( $group ) === 1 && $group[0]->getAttribute( 'role' ) === 'group' && trim( $group[0]->getAttribute( 'aria-label' ) ) !== '' && $group[0]->hasAttribute( 'hidden' )
		&& array_map( static fn( \DOMElement $pill ): string => $pill->getAttribute( 'data-filter' ), $pills ) === [ 'all', 'due', 'offer', 'invoice', 'contract', 'privacy', 'information' ] && array_filter( $pills, static fn( \DOMElement $pill ): bool => $pill->getAttribute( 'type' ) !== 'button' || in_array( $pill->getAttribute( 'aria-pressed' ), [ 'true', 'false' ], true ) === false || trim( $pill->textContent ) === '' ) === []
		&& $pressed( $list ) === [ 'all' ] && strpos( $list, 'crm-filter' ) < strpos( $list, 'crm-documents__list' ) && portalFind( $list, '//p[contains(@class, "crm-documents__count")][@aria-live="polite"][contains(@data-shown, "{shown}")][contains(@data-shown, "{total}")]' ) !== []
		&& portalFind( $list, '//button[@data-filter="invoice"]/@data-kinds' )[0]->nodeValue === 'invoice credit' && portalFind( $list, '//button[@data-filter="all"]/@data-kinds' ) === [] );
	check( '...?art= presses the pill it names: a kind the client has, or the pill of what asks for something; every other value - another case, an unknown kind, markup, a null byte, a list, none - is All, and nothing of it reaches the markup', $pressed( portalPage( $appData, '/portal/belege?art=offer' )[1] ) === [ 'offer' ] && $pressed( portalPage( $appData, '/portal/belege?art=due' )[1] ) === [ 'due' ]
		&& $pressed( portalPage( $appData, '/portal/belege?art=credit' )[1] ) === [ 'all' ] && $pressed( portalPage( $appData, '/portal/belege?art=Offer' )[1] ) === [ 'all' ] && $pressed( portalPage( $appData, '/portal/belege?art=%3Cb%3Ex' )[1] ) === [ 'all' ] && $pressed( portalPage( $appData, '/portal/belege?art=offer%00' )[1] ) === [ 'all' ]
		&& $pressed( portalPage( $appData, '/portal/belege?art[]=offer' )[1] ) === [ 'all' ] && $pressed( portalPage( $appData, '/portal/belege?art=' )[1] ) === [ 'all' ] && str_contains( portalPage( $appData, '/portal/belege?art=%3Cb%3Ex' )[1], '<b>x' ) === false
		&& $ids( portalPage( $appData, '/portal/belege?art=offer' )[1] ) === $ids( $list ) );
	$element = \Nino\Elements::insertElement( $appData, '/projekte/', [ 'title' => 'Feinschliff-Projekt', 'client' => (string) \Crm\Clients::get( $appData, $client )['number'] ], '*' );
	$project = is_array( $element ) === true ? \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ) : '';
	Db::exec( $appData, 'UPDATE documents SET project = :project WHERE id IN ( :one, :two )', [ 'project' => $project, 'one' => $overdueOld, 'two' => $infoNew ] );
	\Crm\Portal::forget( $appData );
	$onProject = portalPage( $appData, '/portal/projekte?projekt='. $project )[1];
	check( '...the list of documents on the page of a project has no pills and keeps the newest first: the information of the later day stands over the overdue invoice there', $project !== '' && $ids( $onProject ) === [ $infoNew, $overdueOld ] && portalFind( $onProject, '//div[contains(@class, "crm-filter")]' ) === [] );
	$credit = $make( 'credit', $client, [ 'date' => '2026-10-28', 'template_key' => 'rechnung', 'lines' => [ $line( 'Gutschrift' ) ] ] );
	Documents::setState( $appData, $credit, 'sent', '2026-10-28' );
	Db::exec( $appData, 'UPDATE documents SET released = 0 WHERE id IN ( :one, :two )', [ 'one' => $contract, 'two' => $privacy ] );
	Db::exec( $appData, 'UPDATE documents SET state = \'sent\' WHERE id = :id', [ 'id' => $paid ] );
	$few = portalPage( $appData, '/portal/belege' )[1];
	$creditRow = portalFind( $few, '//li[@data-document="'. $credit. '"]' );
	check( 'a pill stands for each kind the client holds and for no other: with the contract and the privacy document withdrawn the pills are All, Action needed, Offers, Invoices and Information; the credit note is a row of the kind credit and asks for nothing, the invoice that was paid and is sent again asks', array_map( static fn( \DOMElement $pill ): string => $pill->getAttribute( 'data-filter' ), portalFind( $few, '//div[contains(@class, "crm-filter")]/button' ) ) === [ 'all', 'due', 'offer', 'invoice', 'information' ]
		&& count( $creditRow ) === 1 && $creditRow[0]->getAttribute( 'data-kind' ) === 'credit' && $creditRow[0]->hasAttribute( 'data-due' ) === false && portalFind( $few, '//li[@data-document="'. $paid. '"][@data-due="1"]' ) !== [] );
	Db::exec( $appData, 'UPDATE documents SET released = 1 WHERE id IN ( :one, :two )', [ 'one' => $contract, 'two' => $privacy ] );
	Db::exec( $appData, 'DELETE FROM document_lines WHERE document_id = :id', [ 'id' => $credit ] );
	Db::exec( $appData, 'DELETE FROM documents WHERE id = :id', [ 'id' => $credit ] );
	Db::exec( $appData, 'UPDATE documents SET state = \'paid\' WHERE category = \'invoice\' AND client_id = :client', [ 'client' => $client ] );
	Db::exec( $appData, 'UPDATE documents SET state = \'declined\' WHERE category = \'offer\' AND client_id = :client', [ 'client' => $client ] );
	$quiet = portalPage( $appData, '/portal/belege?art=due' )[1];
	check( 'a client for whom nothing asks has no pill of what asks for something, and ?art=due is All; the order is information first, then the day, the newest first', array_map( static fn( \DOMElement $pill ): string => $pill->getAttribute( 'data-filter' ), portalFind( $quiet, '//div[contains(@class, "crm-filter")]/button' ) ) === [ 'all', 'offer', 'invoice', 'contract', 'privacy', 'information' ]
		&& $pressed( $quiet ) === [ 'all' ] && portalFind( $quiet, '//li[@data-due]' ) === [] && $ids( $quiet ) === array_merge( [ $infoNew, $infoOld ], array_column( Db::rows( $appData, 'SELECT id FROM documents WHERE client_id = :client AND category != \'information\' AND released = 1 ORDER BY date DESC, id DESC', [ 'client' => $client ] ), 'id' ) ) );

	echo "\nMeine Daten: the name before the company\n";

	$data = portalPage( $appData, '/portal/meine-daten' )[1];
	check( 'the form of Meine Daten asks for the name first and then the company: the labels and the inputs stand in that order, and the refusal of a form with neither still points at the name', array_slice( array_map( static fn( \DOMElement $label ): string => $label->getAttribute( 'for' ), portalFind( $data, '//form[@class="crm-data__form"]//label[@for]' ) ), 0, 3 ) === [ 'crm-data-name', 'crm-data-company', 'crm-data-street' ]
		&& strpos( $data, 'name="name"' ) < strpos( $data, 'name="company"' ) && $location( portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => ' ', 'company' => '', 'street' => 'Weg 9', 'zip' => '01067', 'city' => 'Dresden', 'country' => 'DE' ] ) ) === '/portal/meine-daten?state=invalid&field=name&why=name' );

	echo "\nThe client of the member in the process\n";

	portalSignIn( $appData, $frieda );
	$asFrieda = $_SESSION;
	portalSignIn( $appData, $stray );
	$asStray = $_SESSION;
	$_SESSION = $asFrieda;
	$ofFrieda = \Crm\Portal::client( $appData )['id'] ?? null;
	$_SESSION = $asStray;
	$ofStray = \Crm\Portal::client( $appData );
	portalSignIn( $appData, $accounts['ben'] );
	$ofBen = \Crm\Portal::client( $appData )['id'] ?? null;
	check( 'the client that is read is the one of the member of the moment: a member that takes the place of another in the same process - the session changes, nothing forgets the last client - reads its own client, or none', $ofFrieda === $client && $ofStray === null && $ofBen !== null && $ofBen !== $client );
	portalSignIn( $appData, $frieda );

	echo "\nThe message a member puts away\n";

	$all = 'Für <b>alle</b> [members-name] [[/project/company/general/name]] Zeile';
	\Crm\Settings::set( $appData, 'portal_message', $all );
	\Crm\Clients::setMessage( $appData, $client, 'Nur für Dich' );

	// A text with a null byte cannot be written through the settings; the page reads it all the same, without the byte
	Db::exec( $appData, 'UPDATE settings SET value = replace( value, \'Zeile\', \'Ze\' || char( 0 ) || \'ile\' ) WHERE key = \'portal_message\'' );
	\Crm\Portal::forget( $appData );
	$allSum = hash( 'sha256', 'Für <b>alle</b> [members-name] [[/project/company/general/name]] Zeile' );
	$ownSum = hash( 'sha256', 'Nur für Dich' );
	$cardsOf = static fn( string $html ): array => portalFind( $html, '//div[contains(concat(" ", normalize-space(@class), " "), " crm-message ")]' );
	$sumsOf = static fn( string $html ): array => array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'value' ), portalFind( $html, '//div[contains(@class, "crm-message")]//form/input[@name="sha256"]' ) );
	$rowsOfMember = static fn( string $id ): array => array_column( Db::rows( $appData, 'SELECT sha256 FROM dismissals WHERE member = :member ORDER BY sha256', [ 'member' => $id ] ), 'sha256' );
	$page = portalPage( $appData, '/portal/belege?state=ordered&art=invoice' )[1];
	$forms = portalFind( $page, '//div[contains(@class, "crm-message")]//form' );
	check( 'each message has its form: a post to /.crm/nachricht/weg with the token, the sum of its text (without its null byte) and the page it stands on without what the page says once, and a button of its own with a name', count( $cardsOf( $page ) ) === 2 && $sumsOf( $page ) === [ $allSum, $ownSum ] && count( $forms ) === 2
		&& $forms[0]->getAttribute( 'action' ) === '/.crm/nachricht/weg' && $forms[0]->getAttribute( 'method' ) === 'post' && portalFind( $page, '//div[contains(@class, "crm-message")]//form/input[@name="weiter"]/@value' )[0]->nodeValue === '/portal/belege?art=invoice'
		&& portalFind( $page, '//div[contains(@class, "crm-message")]//form/input[@name="_csrf"]' ) !== [] && portalFind( $page, '//div[contains(@class, "crm-message")]//form/button[@type="submit"][string-length(@aria-label) > 0]/svg[@aria-hidden="true"]' ) !== [] && strpos( $page, '<b>alle' ) === false );
	check( '...the page /portal has the page itself as the way back', portalFind( portalPage( $appData, '/portal' )[1], '//div[contains(@class, "crm-message")]//form/input[@name="weiter"]/@value' )[0]->nodeValue === '/portal' );
	$post = static function( string $sum, array $over = [] ) use ( &$appData ): array {

		return portalServe( $appData, '/.crm/nachricht/weg', 'POST', $over + [ 'sha256' => $sum, 'weiter' => '/portal/belege' ] );
	};
	$before = count( $mails );
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	check( 'a visitor is answered 404 and nothing is kept', $post( $allSum )['statusCode'] === 404 && Db::rows( $appData, 'SELECT member FROM dismissals' ) === [] );
	portalSignIn( $appData, $stray );
	check( '...so is a member of no client', $post( $allSum )['statusCode'] === 404 && Db::rows( $appData, 'SELECT member FROM dismissals' ) === [] );
	portalSignIn( $appData, $frieda );
	$before = count( $mails );
	$expiredAnswer = $post( $allSum, [ '_csrf' => 'wrong' ] );
	check( '...a token that is not the session\'s is the redirect to the page with ?state=expired, and nothing is kept', $expiredAnswer['statusCode'] === 303 && $location( $expiredAnswer ) === '/portal/belege?state=expired' && Db::rows( $appData, 'SELECT member FROM dismissals' ) === []
		&& $location( $post( $allSum, [ '_csrf' => 'wrong', 'weiter' => '/portal/belege?art=offer' ] ) ) === '/portal/belege?art=offer&state=expired' && $location( $post( $allSum, [ '_csrf' => 'wrong', 'weiter' => 'https://evil.example/portal' ] ) ) === '/portal?state=expired' );
	$none = [];

	foreach( [ str_repeat( '0', 64 ), strtoupper( $allSum ), substr( $allSum, 0, 63 ), $allSum. 'a', 'zz', '', $allSum. "\0", ' '. $allSum ] as $bad )
		$none[] = $post( $bad )['statusCode'] === 303 && Db::rows( $appData, 'SELECT member FROM dismissals' ) === [];

	$none[] = portalServe( $appData, '/.crm/nachricht/weg', 'POST', [ 'sha256' => [ $allSum ], 'weiter' => '/portal' ] )['statusCode'] === 303 && portalServe( $appData, '/.crm/nachricht/weg', 'POST', [ 'weiter' => '/portal' ] )['statusCode'] === 303 && Db::rows( $appData, 'SELECT member FROM dismissals' ) === [];
	check( '...a sum that is no message of the member now - never written, in capitals, short, long, empty, with a null byte, a list, none at all - keeps no row and is answered 303', $none === array_fill( 0, count( $none ), true ) );
	$done = $post( $allSum );
	$row = Db::rows( $appData, 'SELECT member, sha256, at FROM dismissals' );
	check( 'the sum of the message to every client keeps one row - the member, the sum, the time - and is answered 303 to the page', $done['statusCode'] === 303 && $location( $done ) === '/portal/belege' && count( $row ) === 1 && $row[0]['member'] === $frieda['id'] && $row[0]['sha256'] === $allSum && preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $row[0]['at'] ) === 1 );
	$post( $allSum );
	$after = portalPage( $appData, '/portal' )[1];
	check( '...a second post keeps no second row, and the page has the other message only; no mail went out and the row is the only trace', count( Db::rows( $appData, 'SELECT member FROM dismissals' ) ) === 1 && count( $cardsOf( $after ) ) === 1 && $sumsOf( $after ) === [ $ownSum ] && count( $mails ) === $before );
	portalSignIn( $appData, $second );
	$other = portalPage( $appData, '/portal' )[1];
	check( '...another member of the client still reads both: every member puts away for itself', $sumsOf( $other ) === [ $allSum, $ownSum ] );
	$post( $ownSum );
	check( '...and the message to the client is put away on its own sum', $rowsOfMember( $second['id'] ) === [ $ownSum ] && $sumsOf( portalPage( $appData, '/portal' )[1] ) === [ $allSum ] );
	portalSignIn( $appData, $accounts['ben'] );
	$foreign = $post( $ownSum );
	check( 'the message to one client is no message of another: its sum posted by a member of another client keeps no row, and the message to every client is read by both', $foreign['statusCode'] === 303 && $rowsOfMember( $accounts['ben']['id'] ) === [] && in_array( $allSum, $sumsOf( portalPage( $appData, '/portal' )[1] ), true ) === true );
	portalSignIn( $appData, $frieda );
	\Crm\Settings::set( $appData, 'portal_message', 'Neuer Text' );
	\Crm\Portal::forget( $appData );
	$changed = portalPage( $appData, '/portal' )[1];
	check( 'a message the owner changes is a message again: its sum is another one and the card is back; the sum of the old text is no message and keeps no row', $sumsOf( $changed ) === [ hash( 'sha256', 'Neuer Text' ), $ownSum ] && $post( $allSum )['statusCode'] === 303 && $rowsOfMember( $frieda['id'] ) === [ $allSum ] && count( $cardsOf( portalPage( $appData, '/portal' )[1] ) ) === 2 );
	$ways = [];

	foreach( [ 'https://evil.example/portal' => '/portal', '//evil.example/portal' => '/portal', '/crm' => '/portal', '/portal/../crm' => '/portal', "/portal\0/x" => '/portal', 'javascript:alert(1)' => '/portal', '/portal/belege?art=offer' => '/portal/belege?art=offer', '/portal\\x' => '/portal', '/portal/projekte?projekt=p1&tab=dateien' => '/portal/projekte?projekt=p1&tab=dateien' ] as $weiter => $expect )
		$ways[] = $location( $post( hash( 'sha256', 'Neuer Text' ), [ 'weiter' => $weiter ] ) ) === $expect;

	check( '...the way back is a path under /portal and nothing else: a host, a second slash, a dot segment, another area, a null byte, a script and a backslash are the start page of the portal', $ways === array_fill( 0, count( $ways ), true ) );
	check( '...and with the new text put away too the page has the message to the client only', $sumsOf( portalPage( $appData, '/portal' )[1] ) === [ $ownSum ] );
}

portalNotesChecks( $appData, $a, $fields, $location, $accounts['anna'] );
portalFinishingTouches( $appData, $accounts, $stray );
portalCustomerUxChecks( $appData, $store, $owner, $put, $made, $ago );

/**
 *	The finishing of the customer's way through the portal: where the last step of the gate leads, what a refused form keeps, what the door says, what the mails of a release tell, the row of a file,
 *	the key figures and the sentence after an order
 *
 *	@param		array 		&$appData
 *	@param		string		$store
 *	@param		array 		$owner
 *	@param		\Closure	$put
 *	@param		\Closure	$made
 *	@param		\Closure	$ago
 *
 *	@return 	void
 */
function portalCustomerUxChecks( array &$appData, string $store, array $owner, \Closure $put, \Closure $made, \Closure $ago ): void {

	global $mails;

	$today = date( 'Y-m-d' );
	$location = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
	$line = static fn( string $title ): array => [ 'title' => $title, 'description' => '', 'unit' => 'Std.', 'qty' => '2', 'price' => '50', 'vat_rate' => 0 ];
	$bare = static function( string $address ) use ( &$appData ): array {
		$client = \Crm\Clients::add( $appData, [], [ 'email' => $address, 'name' => '', 'notify' => false ] );

		return [ 'client' => $client, 'account' => (array) $client['first']['account'] ];
	};
	$notes = static fn( string $html ): int => count( portalFind( $html, '//p[contains(concat(" ", normalize-space(@class), " "), " crm-saved ")]' ) );
	$full = [ 'name' => 'Nora Neu', 'street' => 'Allee 4', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE' ];

	echo "\nThe customer's way through the portal\n";

	\Crm\Settings::set( $appData, 'privacy_text', '' );

	// the last step of the gate leads to where something waits, with the state saved
	check( 'Portal::saved() puts state=saved into the query of an address of the portal and leaves the place on the page out; the start page for none', \Crm\Portal::saved( '' ) === '/portal?state=saved'
		&& \Crm\Portal::saved( '/portal/belege#beleg-3' ) === '/portal/belege?state=saved' && \Crm\Portal::saved( '/portal/projekte?projekt=00001&tab=dateien' ) === '/portal/projekte?projekt=00001&tab=dateien&state=saved' );
	$k1 = $bare( 'k1@example.org' );
	portalSignIn( $appData, $k1['account'] );
	$saved = portalServe( $appData, '/.crm/daten', 'POST', $full );
	check( 'the data saved as the last step of the gate, with no page to go on to, is answered 303 to the start page where nothing else waits, with state=saved', $saved['statusCode'] === 303 && $location( $saved ) === '/portal?state=saved' );
	check( '...the start page says it once as a status note; the page without the state has none, and Meine Daten says its own note and not that one', $notes( portalPage( $appData, '/portal?state=saved' )[1] ) === 1 && $notes( portalPage( $appData, '/portal' )[1] ) === 0
		&& $notes( portalPage( $appData, '/portal/meine-daten?state=saved' )[1] ) === 0 && portalFind( portalPage( $appData, '/portal?state=saved' )[1], '//p[contains(@class, "crm-saved")][@role="status"]' ) !== [] );
	$again = portalServe( $appData, '/.crm/daten', 'POST', [ 'phone' => '0221 1' ] + $full );
	check( '...a client whose data were complete stays on its page with the state: only the last step of the gate leads on', $location( $again ) === '/portal/meine-daten?state=saved' );

	$k2 = $bare( 'k2@example.org' );
	$folder = $store::addFolder( $appData, $k2['client']['group'], 'Land' );
	$put( $folder, 'Land.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 600 ) );
	$project = $made( 'Landung', $k2['client']['number'], [ 'folder' => $folder['id'] ] );
	portalSignIn( $appData, $k2['account'] );
	$landed = portalServe( $appData, '/.crm/daten', 'POST', $full );
	check( '...where the client has a project, the landing is its files: the page of the project on the tab Dateien, with the state, and the project page says the note once', $location( $landed ) === '/portal/projekte?projekt='. $project. '&tab=dateien&state=saved'
		&& $notes( portalPage( $appData, $location( $landed ) )[1] ) === 1 && $notes( portalPage( $appData, '/portal/projekte?projekt='. $project. '&tab=dateien' )[1] ) === 0 );

	$k4 = \Crm\Clients::add( $appData, [ 'company' => 'Anker GmbH', 'name' => 'Anka Anker', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE' ], [ 'email' => 'k4@example.org', 'name' => 'Anka Anker', 'notify' => false ] );
	$k4Project = $made( 'Anker', $k4['number'] );
	$k4Draft = \Crm\Documents::add( $appData, 'offer', $k4['id'], [ 'date' => $today, 'valid_until' => '2031-12-06', 'project' => $k4Project, 'template_key' => 'angebot', 'lines' => [ $line( 'Anker' ) ] ] );
	$k4Offer = is_array( $k4Draft ) === true ? \Crm\Documents::finalise( $appData, $k4Draft['id'] ) : [];
	\Crm\Documents::release( $appData, (int) ( $k4Offer['id'] ?? 0 ), false );
	\Crm\Clients::change( $appData, $k4['id'], [ 'street' => '' ] );
	portalSignIn( $appData, (array) $k4['first']['account'] );
	$toOffer = portalServe( $appData, '/.crm/daten', 'POST', $full );
	check( '...the landing on an offer that waits is the tab Angebot with the state and without the anchor of the card, which would scroll the note out of sight', is_array( $k4Offer ) === true && str_contains( $location( $toOffer ), 'tab=angebot' ) === true
		&& str_ends_with( $location( $toOffer ), '&state=saved' ) === true && str_contains( $location( $toOffer ), '#' ) === false && $notes( portalPage( $appData, $location( $toOffer ) )[1] ) === 1 );

	// a refused form keeps what was typed - once, escaped, in the session alone
	$k3 = $bare( 'k3@example.org' );
	portalSignIn( $appData, $k3['account'] );
	$evil = '"><b>x</b> [[/project/company/general/name]] <script>1</script>';
	$refused = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => $evil, 'street' => 'Allee 4', 'zip' => '', 'city' => 'Köln', 'country' => 'DE' ] );
	$typed = static fn( string $html ): array => array_map( static fn( string $name ): string => (string) ( portalFind( $html, '//input[@id="crm-data-'. $name. '"]/@value' )[0]->nodeValue ?? '' ), [ 'name', 'street', 'zip', 'city' ] );
	check( 'a refused form is the redirect with the field and carries nothing of what was typed in the address', $location( $refused ) === '/portal/meine-daten?state=invalid&field=zip&why=required' );
	$shown = portalPage( $appData, $location( $refused ) )[1];
	check( '...the next drawing puts it into the fields: the text, as it was typed, escaped - no markup, no fill of it, no script in the page', $typed( $shown ) === [ $evil, 'Allee 4', '', 'Köln' ] && str_contains( $shown, '<b>x</b>' ) === false && str_contains( $shown, '<script>1' ) === false
		&& str_contains( $shown, '[[/project/company' ) === false && portalLeft( $shown ) === false );
	check( '...once: the same address drawn again has the fields of the client, which are empty', $typed( portalPage( $appData, $location( $refused ) )[1] ) === [ '', '', '', '' ] );
	portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Rest', 'street' => 'Weg 1', 'zip' => '', 'city' => 'Ulm', 'country' => 'DE' ] );
	check( '...and a page that is not that state drops it unseen: the form without the state has the client\'s fields, and the refusal address after it is empty as well', $typed( portalPage( $appData, '/portal/meine-daten' )[1] ) === [ '', '', '', '' ] && $typed( portalPage( $appData, $location( $refused ) )[1] ) === [ '', '', '', '' ] );
	$expired = portalServe( $appData, '/.crm/daten', 'POST', [ '_csrf' => 'not the token', 'name' => 'Ella Eil', 'street' => 'Weg 2', 'zip' => '1', 'city' => 'Bonn', 'country' => 'DE' ] );
	check( '...a token that is not the session\'s keeps it too: state=expired, and the fields stand', str_contains( $location( $expired ), 'state=expired' ) === true && $typed( portalPage( $appData, $location( $expired ) )[1] ) === [ 'Ella Eil', 'Weg 2', '1', 'Bonn' ] );
	$badLogin = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'kein"><b>@' ] );
	$loginShown = portalPage( $appData, $location( $badLogin ) )[1];
	check( 'the address typed into the block Anmeldeadresse and refused stands in its field the next time, escaped and once - and is not in the address of the redirect', str_contains( $location( $badLogin ), 'kein' ) === false && ( portalFind( $loginShown, '//input[@id="crm-login-email"]/@value' )[0]->nodeValue ?? '' ) === 'kein"><b>@' && str_contains( $loginShown, '<b>@' ) === false
		&& ( portalFind( portalPage( $appData, $location( $badLogin ) )[1], '//input[@id="crm-login-email"]/@value' )[0]->nodeValue ?? '' ) === '' );

	portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'kein@' ] );
	check( '...and the address that was refused is dropped by a drawing of the page that is not that refusal: the field of the plain page is empty, and the refusal drawn after it too', ( portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//input[@id="crm-login-email"]/@value' )[0]->nodeValue ?? '' ) === ''
		&& ( portalFind( portalPage( $appData, '/portal/meine-daten?state=invalid&field=login&login=change' )[1], '//input[@id="crm-login-email"]/@value' )[0]->nodeValue ?? '' ) === '' );

	$long = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => str_repeat( 'n', 300 ), 'street' => 'Lange Gasse 7', 'zip' => '50667', 'city' => 'Köln', 'country' => 'DE' ] );
	check( 'a refusal for a value that is too long or has the wrong format keeps the other fields too: they stand when the form is drawn', str_contains( $location( $long ), 'why=long' ) === true && ( $typed( portalPage( $appData, $location( $long ) )[1] )[1] ) === 'Lange Gasse 7' );
	$dirty = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => "A\0B\r\nC". str_repeat( 'x', 450 ), 'street' => 'Weg', 'zip' => '', 'city' => 'Ulm', 'country' => 'DE' ] );
	$dirtyName = $typed( portalPage( $appData, $location( $dirty ) )[1] )[0];
	check( '...what is kept has no control character - a null byte and a line break are blanks - and is no longer than 400 characters', preg_match( '/[\x00-\x1F\x7F]/', $dirtyName ) !== 1 && mb_strlen( $dirtyName ) === 400 && str_starts_with( $dirtyName, 'A B C' ) === true );
	$own = portalServe( $appData, '/.crm/anmeldeadresse', 'POST', [ 'email' => 'k3@example.org' ] );
	check( '...the address of the account typed into the block Anmeldeadresse (state=same) stands in its field as well', str_contains( $location( $own ), 'state=same' ) === true && ( portalFind( portalPage( $appData, $location( $own ) )[1], '//input[@id="crm-login-email"]/@value' )[0]->nodeValue ?? '' ) === 'k3@example.org' );

	// the door names where the sign-in goes on to - by the name of the page, never by a title of the client
	$secret = $made( 'Geheimtitel Zwölf', $k3['client']['number'] );
	$_SESSION = [];
	unset( $appData['./nino/auth/current'] );
	$gate = portalPage( $appData, '/portal/projekte?projekt='. $secret. '&tab=angebot' );
	$next = portalFind( $gate[1], '//p[contains(@class, "members-login__next")]' );
	check( 'the gate that stands in place of a page of the portal says over the field where the sign-in goes on to: the name of the page - and no title of a project, which a visitor has not earned', $gate[0] === 401 && count( $next ) === 1 && portalText( $gate[1], '//p[contains(@class, "members-login__next")]/strong' ) !== ''
		&& str_contains( $gate[1], 'Geheimtitel' ) === false && portalLeft( $gate[1] ) === false );
	$none = array_filter( [ '/portal/Projekte', '/portal/projekte/%2e%2e', '/portal/unbekannt' ], static fn( string $uri ): bool => portalFind( portalPage( $appData, $uri )[1], '//p[contains(@class, "members-login__next")]' ) !== [] );
	check( '...and says nothing for a page that is none of the portal\'s - the sentence is for the paths onward() takes and for the names of the portal\'s areas', $none === [] );
	check( '...nor does the start page of the site, which asks for no sign-in, say it', portalFind( portalPage( $appData, '/' )[1], '//p[contains(@class, "members-login__next")]' ) === [] );

	$projectTitle = 'Titel eines Kunden';
	$titled = $made( $projectTitle, $k3['client']['number'] );
	$named = \Crm\Portal::onwardName( $appData, null, '/portal/projekte?projekt='. $titled );
	check( 'onwardName() for a visitor names the page and nothing of a project, whatever the query says; for a client it names the project by its title', $named !== '' && str_contains( $named, $projectTitle ) === false && $named === \Crm\Portal::onwardName( $appData, null, '/portal/projekte' )
		&& str_contains( \Crm\Portal::onwardName( $appData, (array) \Crm\Clients::get( $appData, $k3['client']['id'] ), '/portal/projekte?projekt='. $titled ), $projectTitle ) === true );

	// the mail of a release says what it is about, by kind
	$buyer = \Crm\Clients::add( $appData, [ 'company' => 'Post GmbH', 'name' => 'Pia Post', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE' ], [ 'email' => 'post@example.org', 'name' => 'Pia Post', 'notify' => false ] );
	$mailProject = $made( 'EP <b>Neuklang</b>', $buyer['number'] );
	$make = static function( string $category, array $input, bool $own = true ) use ( &$appData, $buyer ): array {
		$draft = \Crm\Documents::add( $appData, $category, $own === true ? $buyer['id'] : 0, $input );
		$done = is_array( $draft ) === true ? \Crm\Documents::finalise( $appData, $draft['id'] ) : $draft;

		return is_array( $done ) === true ? (array) \Crm\Documents::get( $appData, $done['id'] ) : [];
	};
	$kind = static fn( string $category ): string => \Crm\Documents\Mail::text( 'kind.'. $category );
	$money = static fn( array $document ): string => \Crm\Documents\Money::format( (int) $document['gross'], \Crm\Settings::get( $appData, 'currency' ) );
	$text = static fn( string $key, string ...$params ): string => \Crm\Documents\Mail::text( $key, ...$params );
	$offer = $make( 'offer', [ 'date' => $today, 'valid_until' => '2031-12-06', 'project' => $mailProject, 'template_key' => 'angebot', 'lines' => [ $line( 'Mix' ) ] ] );
	$offerMail = \Crm\Documents\Mail::compose( $appData, $offer )[0] ?? [];
	check( 'the mail of an offer: the subject names the number, the project, the gross sum from Money and the day it is valid until; the button says it can be ordered; one sentence says so', $offerMail !== []
		&& $offerMail['subject'] === $text( 'subject.facts', $text( 'subject.project', $text( 'subject.head', $kind( 'offer' ), $offer['number'] ), 'EP <b>Neuklang</b>' ), $money( $offer ). ', '. $text( 'subject.valid', '06.12.2031' ) )
		&& str_contains( $offerMail['body'], '>'. $text( 'button.offer' ). '</a>' ) === true && str_contains( $offerMail['body'], $text( 'detail.offer' ) ) === true && str_contains( $offerMail['body'], 'tab=angebot' ) === true );
	check( '...a title that carries markup is the plain text of the subject and nothing of it is markup in the body', str_contains( $offerMail['body'], '<b>Neuklang' ) === false && str_contains( $offerMail['body'], '[[' ) === false );
	$invoice = $make( 'invoice', [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( 'Mix' ) ] ] );
	Db::exec( $appData, 'UPDATE documents SET due_date = :day WHERE id = :id', [ 'day' => '2031-11-20', 'id' => $invoice['id'] ] );
	$invoiceDue = \Crm\Documents\Mail::compose( $appData, (array) \Crm\Documents::get( $appData, $invoice['id'] ) )[0] ?? [];
	Db::exec( $appData, 'UPDATE documents SET due_date = NULL WHERE id = :id', [ 'id' => $invoice['id'] ] );
	$invoiceNow = \Crm\Documents\Mail::compose( $appData, (array) \Crm\Documents::get( $appData, $invoice['id'] ) )[0] ?? [];
	check( 'the mail of an invoice: number, gross sum and the day it is due; the button says it is looked at and paid; the sentence names sum and day', $invoiceDue !== [] && $invoiceDue['subject'] === $text( 'subject.facts', $text( 'subject.head', $kind( 'invoice' ), $invoice['number'] ), $money( $invoice ). ', '. $text( 'subject.due', '20.11.2031' ) )
		&& str_contains( $invoiceDue['body'], '>'. $text( 'button.invoice' ). '</a>' ) === true && str_contains( $invoiceDue['body'], $text( 'detail.invoice-due', $money( $invoice ), '20.11.2031' ) ) === true );
	check( '...an invoice with no day is payable at once, in the subject and in the sentence', $invoiceNow !== [] && str_ends_with( $invoiceNow['subject'], $text( 'subject.due-none' ) ) === true && str_contains( $invoiceNow['body'], $text( 'detail.invoice-now', $money( $invoice ) ) ) === true );
	$plain = [];

	foreach( [ 'contract', 'privacy', 'information' ] as $category ) {
		$input = [ 'date' => $today, 'title' => 'Titel '. $category, 'template_key' => [ 'contract' => 'vertrag', 'privacy' => 'datenschutz', 'information' => 'zugang' ][$category] ];
		$document = $category === 'contract' ? $make( 'contract', [ 'parent_id' => $offer['id'], 'inputs' => [ 'zusatz' => 'Laufzeit' ] ] + $input, false ) : $make( $category, $input );
		$mail = \Crm\Documents\Mail::compose( $appData, $document )[0] ?? [];
		$plain[$category] = $mail !== [] && $mail['subject'] === $text( 'subject.head', $kind( $category ), 'Titel '. $category ) && str_contains( $mail['body'], '>'. $text( 'button.kind', $kind( $category ) ). '</a>' ) === true && str_contains( $mail['body'], $text( 'detail.'. $category ) ) === true;
	}

	$ibanKept = \Crm\Settings::get( $appData, 'iban' );
	$payWith = static function( string $iban ) use ( &$appData, $invoice ): string {
		\Crm\Settings::set( $appData, 'iban', $iban );

		return (string) ( \Crm\Documents\Mail::compose( $appData, (array) \Crm\Documents::get( $appData, $invoice['id'] ) )[0]['body'] ?? '' );
	};
	$withBank = $payWith( 'DE89370400440532013000' );
	$withoutBank = $payWith( '' );
	\Crm\Settings::set( $appData, 'iban', $ibanKept );
	check( '...with a bank account in the settings the sentence of the invoice names the way to pay, and without one (or PayPal) it does not', str_contains( $withBank, $text( 'detail.pay', $text( 'detail.pay-transfer' ) ) ) === true && str_contains( $withoutBank, $text( 'detail.pay', '' ) ) === false && str_contains( $withoutBank, substr( $text( 'detail.pay', 'X' ), 0, 12 ) ) === false );
	check( 'the mail of a contract, of the Datenschutz and of an information: the subject names the kind and the title, the button the kind, one sentence for each', $plain === [ 'contract' => true, 'privacy' => true, 'information' => true ] );
	$sly = [ 'title' => "Vertrag\r\nBcc: evil@example.org\n<script>" ] + $make( 'contract', [ 'date' => $today, 'title' => 'Sly', 'template_key' => 'vertrag', 'parent_id' => $offer['id'], 'inputs' => [ 'zusatz' => 'x' ] ], false );
	$slyMail = \Crm\Documents\Mail::compose( $appData, $sly )[0] ?? [];
	check( 'the subject is one line whatever a title holds: no line break and no control character in it, and the body escapes the title', $slyMail !== [] && preg_match( '/[\x00-\x1F\x7F]/', $slyMail['subject'] ) !== 1 && str_contains( $slyMail['subject'], 'Bcc: evil@example.org' ) === true && str_contains( $slyMail['body'], '<script>' ) === false );
	$remind = \Crm\Documents\Mail::compose( $appData, (array) \Crm\Documents::get( $appData, $invoice['id'] ), 'remind' )[0] ?? [];
	check( 'the reminder keeps its own words: its subject and its button are the reminder\'s, and the body has no sentence of the release', $remind !== [] && $remind['subject'] === $text( 'remind.subject', $text( 'line', $kind( 'invoice' ), (string) ( $invoice['title'] !== '' ? $invoice['title'] : $invoice['number'] ) ) )
		&& str_contains( $remind['body'], '>'. $text( 'remind.button' ). '</a>' ) === true && str_contains( $remind['body'], $text( 'detail.invoice-now', $money( $invoice ) ) ) === false );

	// the row of a file and of a document
	$rows = \Crm\Clients::add( $appData, [ 'company' => 'Zeilen GmbH', 'name' => 'Zita Zeile', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE' ], [ 'email' => 'k9@example.org', 'name' => 'Zita Zeile', 'notify' => false ] );
	$rows = [ 'client' => $rows, 'account' => (array) $rows['first']['account'] ];
	$folderV = $store::addFolder( $appData, $rows['client']['group'], 'Zeilen' );
	$put( $folderV, 'Lied.01.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 90000 ) );
	$put( $folderV, 'Lied.02.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 600 ) );
	$put( $folderV, 'Notiz.txt', 'txt', 'text/plain', true, $owner, $ago( 99999 * 10 ) );
	$projectV = $made( 'Zeilen', $rows['client']['number'], [ 'folder' => $folderV['id'] ] );
	portalSignIn( $appData, $rows['account'] );
	$pageV = portalPage( $appData, '/portal/projekte?projekt='. $projectV. '&tab=dateien' )[1];
	$songRow = '//li[contains(@class, "crm-files__row")][.//a[contains(@class, "crm-files__name")][normalize-space() = "Lied"]]';
	$meta = portalFind( $pageV, $songRow. '//span[contains(@class, "crm-files__meta")]' );
	check( 'the row of a title with versions has its version, its format and the number of versions as quiet words in the line of the facts - none of them a pill -, beside the size and the day', count( $meta ) === 1
		&& array_map( static fn( \DOMElement $part ): string => $part->getAttribute( 'class' ), portalFind( $pageV, $songRow. '//span[contains(@class, "crm-files__meta")]/span' ) ) === [ 'crm-files__version', 'crm-files__ext', 'crm-files__versions' ]
		&& portalFind( $pageV, $songRow. '//span[contains(@class, "crm-files__meta")]//*[contains(@class, "crm-pill")]' ) === [] && portalFind( $pageV, $songRow. '//span[contains(@class, "crm-facts")]/span[contains(@class, "crm-files__size")]' ) !== []
		&& portalFind( $pageV, $songRow. '//span[contains(@class, "crm-facts")]/span[contains(@class, "crm-files__meta")]/following-sibling::span[1][contains(@class, "crm-files__size")]' ) !== [] );
	$sheetNow = (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.css' );
	check( 'the box of the facts hangs the room of the dots out at its left and clips only that: a negative margin and a clip-path that leaves four pixels of the room open for the ring of a focus - the dots are the margins of the facts, so no fact has padding that the clip would cut', preg_match( '/\n\t\.crm-facts \{[^}]*margin-left:\s*calc\( -1 \* var\(--crm-4\) \);[^}]*clip-path:\s*inset\(-4px -4px -4px calc\( var\(--crm-4\) - 4px \)\);/', $sheetNow ) === 1
		&& preg_match( '/\.crm-facts > \.crm-table__cell \{[^}]*margin-left:\s*var\(--crm-4\);/', $sheetNow ) === 1 && preg_match( '/\.crm-facts > \.crm-table__cell \{[^}]*padding/', $sheetNow ) !== 1 );
	check( '...and the version, the format and the number of versions stand nowhere but in that line', portalFind( $pageV, '//*[@class="crm-files__version" or @class="crm-files__ext" or @class="crm-files__versions"][not(ancestor::span[contains(@class, "crm-files__meta")])]' ) === [] );
	check( '...the pills of the row are for its states: new, open comments, the file that plays - and a file of its own has an empty line of words', portalFind( $pageV, '//li[contains(@class, "crm-files__row")]//*[contains(@class, "crm-pill")][not(contains(@class, "crm-files__new") or contains(@class, "crm-files__open") or contains(@class, "crm-files__playing"))]' ) === []
		&& trim( portalText( $pageV, '//li[contains(@class, "crm-files__row")][.//a[contains(@class, "crm-files__name")][normalize-space() = "Notiz.txt"]]//span[contains(@class, "crm-files__meta")]' ) ) === '' );
	portalSignIn( $appData, $buyer['first']['account'] );
	\Crm\Documents::release( $appData, $invoice['id'], false );
	\Crm\Documents::release( $appData, $offer['id'], false );
	Db::exec( $appData, 'UPDATE documents SET title = :title WHERE id = :id', [ 'title' => 'Mix-Angebot', 'id' => $offer['id'] ] );
	$docs = portalPage( $appData, '/portal/belege' )[1];
	check( 'the list of documents says the saved data once as a note, as the start page and the page of a project do', $notes( portalPage( $appData, '/portal/belege?state=saved' )[1] ) === 1 && $notes( $docs ) === 0 );
	check( '...a document with a title and a number has the kind and the number in two spans of the row, with no word between them: the dot between them is the stylesheet\'s', portalFind( $docs, '//li[contains(@class, "crm-documents__row")]//span[contains(@class, "crm-documents__category")]/following-sibling::node()[1][self::span[contains(@class, "crm-documents__number")]]' ) !== [] );
	check( 'the row of a document has no dot in its markup: no text of a row ends with a dot, and the facts of the row stand in a box of their own that draws them', count( portalFind( $docs, '//li[contains(@class, "crm-documents__row")]' ) ) >= 2
		&& portalFind( $docs, '//li[contains(@class, "crm-documents__row")]//text()[substring(normalize-space(.), string-length(normalize-space(.))) = "·"]' ) === [] && portalFind( $docs, '//li[contains(@class, "crm-documents__row")]//span[contains(@class, "crm-facts")]/time[contains(@class, "crm-documents__date")]' ) !== [] );

	// the key figures leave out what is nothing, and the button to the projects stands where the card is long
	$figs = \Crm\Clients::add( $appData, [ 'company' => 'Zahl GmbH', 'name' => 'Zoe Zahl', 'street' => 'Weg 9', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE' ], [ 'email' => 'zahl@example.org', 'name' => 'Zoe Zahl', 'notify' => false ] );
	$tiles = static fn( string $html ): array => array_map( static fn( \DOMElement $tile ): string => $tile->getAttribute( 'data-kpi' ), portalFind( $html, '//a[@data-kpi]' ) );
	$values = static fn( string $html ): array => array_map( static fn( \DOMElement $tile ): string => trim( $tile->textContent ), portalFind( $html, '//a[@data-kpi]//span[contains(@class, "crm-kpi__value")]' ) );
	$news = [];

	for( $i = 1; $i <= 4; $i++ ) {
		$dir = $store::addFolder( $appData, $figs['group'], 'Neu '. $i );
		$put( $dir, 'Neu'. $i. '.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 300 ) );
		$news[] = $made( 'Neu '. $i, $figs['number'], [ 'folder' => $dir['id'] ] );
	}

	portalSignIn( $appData, $figs['first']['account'] );
	\Nino\Features::deactivate( $appData, 'events' );
	$four = portalPage( $appData, '/portal' )[1];
	check( 'the key figures leave out a tile with nothing in it: a client with new files and no offer and no invoice reads the tile of the files alone - no zero, no "nothing open"', $tiles( $four ) === [ 'files' ] && array_filter( $values( $four ), static fn( string $value ): bool => $value === '0' || $value === '–' ) === [] );
	check( '...the button to the projects stands under the card of the files where more than three projects have a row: four have', count( portalFind( $four, '//article[@data-card="files"]//li[contains(@class, "crm-start__row")]' ) ) === 4 && portalFind( $four, '//article[@data-card="files"]//a[contains(@class, "crm-start__projects")]' ) !== [] );
	\Nino\Elements::deleteElement( $appData, '/projekte/'. $news[3] );
	\Crm\Projekte::forget( $appData );
	$three = portalPage( $appData, '/portal' )[1];
	check( '...and not for three: the card has its three rows and no button', count( portalFind( $three, '//article[@data-card="files"]//li[contains(@class, "crm-start__row")]' ) ) === 3 && portalFind( $three, '//a[contains(@class, "crm-start__projects")]' ) === [] );
	\Nino\Features::activate( $appData, 'events' );

	// the note of an order says the files are free to listen to, with the way to them - where the project has files
	$files = $store::addFolder( $appData, $buyer['group'], 'Bestellt' );
	$put( $files, 'Frei.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 300 ) );
	$ordered = $made( 'Bestellt', $buyer['number'], [ 'folder' => $files['id'] ] );
	$empty = $made( 'Ohne Dateien', $buyer['number'] );
	$orderOn = static function( string $project ) use ( &$appData, $make, $line, $today ): array {
		$offer = $make( 'offer', [ 'date' => $today, 'valid_until' => '2031-12-06', 'project' => $project, 'template_key' => 'angebot', 'lines' => [ $line( 'Frei' ) ] ] );
		\Crm\Documents::release( $appData, $offer['id'], false );
		Db::exec( $appData, 'UPDATE documents SET state = \'accepted\' WHERE id = :id', [ 'id' => $offer['id'] ] );

		return $offer;
	};
	$withFiles = $orderOn( $ordered );
	$withoutFiles = $orderOn( $empty );
	portalSignIn( $appData, $buyer['first']['account'] );
	$noteOf = static fn( array $offer, string $project ): string => portalPage( $appData, '/portal/projekte?projekt='. $project. '&tab=angebot&state=ordered&beleg='. $offer['id']. '#angebot-'. $offer['id'] )[1];
	$withNote = $noteOf( $withFiles, $ordered );
	$withoutNote = $noteOf( $withoutFiles, $empty );
	$filesLink = '//p[contains(@class, "crm-order__state--ordered")]//a[contains(@class, "crm-order__files")]/@href';
	check( 'the note of an order names the way to the files - a link to the tab Dateien of the project - where the project has files for the member', ( portalFind( $withNote, $filesLink )[0]->nodeValue ?? '' ) === '/portal/projekte?projekt='. $ordered. '&tab=dateien#projekt-files'
		&& portalFind( $withoutNote, $filesLink ) === [] && portalFind( $withoutNote, '//p[contains(@class, "crm-order__state--ordered")]' ) !== [] );
	$foreign = portalPage( $appData, '/portal/projekte?projekt='. $ordered. '&tab=angebot&state=ordered&beleg=1#angebot-1' )[1];
	check( '...and for an offer that is not the client\'s the note has no such link', portalFind( $foreign, $filesLink ) === [] );
}

/**
 *	The suite of the script of the portal, where node is on the path
 *
 *	@param		string		$root					The checkout the suite runs in
 *
 *	@return 	void
 */
function portalScriptSuite( string $root ): void {

	$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

	if( $node === '' ) {
		echo "  note - node is not on the path: the suite of the portal's script was not run\n";

		return;
	}

	$output = (string) shell_exec( 'NINO_ROOT='. escapeshellarg( $root ). ' '. escapeshellarg( $node ). ' '. escapeshellarg( __DIR__. '/portal-js-smoke.js' ). ' 2>&1' );
	$ran = preg_match( '/(\d+) checks, (\d+) failed/', $output, $counts ) === 1;

	check( 'portal-js-smoke.js passes ('. ( $ran ? $counts[1]. ' checks' : 'no summary' ). ')', $ran && $counts[2] === '0' && (int) $counts[1] >= 15 && str_contains( $output, 'FAIL' ) === false );
}

portalScriptSuite( $root );
// --- Einwilligungen: the voluntary boxes beside an order, the block in Meine Daten, POST /.crm/einwilligung/widerruf ------------------

/**
 *	The checks of the consents
 */
function portalConsentChecks( array &$appData, array $a, array $b, array $accounts, array $stray, string $p1, string $today, array $wordsDe, array $wordsEn, \Closure $reset, \Closure $asAdmin, \Closure $asVisitor, \Closure $made, \Closure $location, \Closure $mkOffer ): void {

	global $mails;

	echo "\nEinwilligungen and POST /.crm/einwilligung/widerruf\n";

	$reset();
	$asAdmin();
	$privacyWas = \Crm\Settings::get( $appData, 'privacy_text' );
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => 'Studio &lt;Eins&gt; &amp; Co [members-name]' ], '*' );
	$pConsent = $made( 'Einwilligung <b>Projekt</b> [members-name]', $a['number'], [], false );
	$social = "Ich willige <b>freiwillig</b> ein.\n[crm-client] [[/project/company/general/name]] & mehr";
	$archive = 'Archiv "und" <i>mehr</i>';
	$recordsOf = static function( int $clientId, ?string $kind = null ) use ( &$appData ): array {
		return \Crm\Records::of( $appData, $clientId, $kind );
	};
	$consentMails = static fn( int $from ): array => array_values( array_filter( portalMailsFrom( $from ), static fn( array $mail ): bool => str_starts_with( (string) ( $mail['subject'] ?? '' ), (string) strstr( \Crm\Documents\Mail::text( 'revocation.owner.subject' ), '%s', true ) ) ) );
	$word = static fn( string $purpose ): string => \Crm\Documents\Mail::text( 'consent.purpose.'. $purpose );
	$sentenceOf = static fn( string $key, string $purpose ): string => \Nino\Modules\Components::escape( \Crm\Documents\Mail::text( $key, $word( $purpose ) ) );
	$boxes = static fn( string $html, int $id ): array => portalFind( $html, '(//form[@data-document="'. $id. '"])[1]//input[starts-with(@name, "consent[")]' );
	$postOrder = static function( array $offer, array $over = [], ?array $offered = null ) use ( &$appData, $a ): array {

		$document = (array) Documents::get( $appData, $offer['id'] );
		$shown = $offered ?? \Crm\Portal\Consent::offered( $appData, (array) \Crm\Clients::get( $appData, $a['id'] ), (string) $document['project'] );
		$post = $over + [ 'document' => (string) $offer['id'], 'from' => 'projekt', 'agree' => '1', 'shown' => hash( 'sha256', \Crm\Records::orderShown( $appData, $document, \Crm\Portal\Order::sentence( $appData, $document ), $shown ) ) ];

		return portalServe( $appData, '/.crm/auftrag', 'POST', $post );
	};
	$formPage = static function( string $project ) use ( &$appData ): string {
		return portalPage( $appData, '/portal/projekte?projekt='. $project )[1];
	};

	// no sentence, no box
	$offer0 = $mkOffer( 'Ohne Satz', [ 'project' => $pConsent ] );
	portalSignIn( $appData, $accounts['anna'] );
	$page = $formPage( $pConsent );
	check( 'while the owner wrote no sentence there is no box of a consent under the order, and the form is the one of the order alone: the box that must be ticked and the button', $boxes( $page, $offer0['id'] ) === [] && count( portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@type="checkbox"]' ) ) === 1
		&& \Crm\Portal\Consent::offered( $appData, (array) \Crm\Clients::get( $appData, $a['id'] ), $pConsent ) === [] && portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//*[@role="group"]' ) === [] );

	// the boxes
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_social', $social );
	portalSignIn( $appData, $accounts['anna'] );
	$page = $formPage( $pConsent );
	$box = portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="consent[social]"]' );
	check( 'a sentence for the social use makes one box under the box of the order: a checkbox consent[social] with the value 1, not required, apart from the box that agrees - and no box for the purpose without a sentence', count( $box ) === 1 && $box[0]->getAttribute( 'type' ) === 'checkbox' && $box[0]->getAttribute( 'value' ) === '1'
		&& $box[0]->hasAttribute( 'required' ) === false && $box[0]->hasAttribute( 'aria-required' ) === false && count( $boxes( $page, $offer0['id'] ) ) === 1 && portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="consent[archive]"]' ) === []
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="agree"][@required]' ) !== [] );
	check( '...the boxes stand in a group that names them as voluntary - a role and a name, once - and none of them is ticked from the start', count( portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//div[contains(@class, "crm-order__group")][@role="group"][string-length(@aria-label) > 0]' ) ) === 1
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//div[@role="group"]//input[@name="consent[social]"]' ) !== [] && portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[starts-with(@name, "consent[")][@checked]' ) === [] && trim( $wordsDe['[[/project/crm-portal/order/optional-group]]'] ) !== '' );
	$label = portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]' );
	check( '...it has a label of its own, which names the box, and the word freiwillig stands in the label before the sentence - a pill for the eye, a word for the reader', count( $label ) === 1 && portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]/input[@name="consent[social]"]' ) !== []
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//span[contains(concat(" ", normalize-space(@class), " "), " crm-pill ")]' ) !== [] && trim( portalText( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//span[contains(@class, "crm-pill")]' ) ) !== ''
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//span[contains(@class, "crm-pill")]/following-sibling::text()' ) !== [] );
	$sentenceText = portalText( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//span[contains(@class, "crm-order__sentence")]' );
	check( '...the sentence is text: <b> is no element, the line break is <br>, the brackets of a shortcode and a fill are written as typed and nothing of it is read', portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//b' ) === []
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//label[@for="'. $box[0]->getAttribute( 'id' ). '"]//br' ) !== [] && str_contains( $sentenceText, 'Ich willige <b>freiwillig</b> ein.' ) === true && str_contains( $sentenceText, '[crm-client] [[/project/company/general/name]] & mehr' ) === true
		&& str_contains( $page, '&lt;b&gt;freiwillig&lt;/b&gt;' ) === true && str_contains( $page, '[crm-client]' ) === false );
	check( '...the form carries the hash of the offer with the sentence of the box, which is not the hash of the offer without it', portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="shown"]/@value' )[0]->nodeValue === hash( 'sha256', \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $offer0['id'] ), \Crm\Portal\Order::sentence( $appData, (array) Documents::get( $appData, $offer0['id'] ) ), [ 'social' => $social ] ) )
		&& portalFind( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="shown"]/@value' )[0]->nodeValue !== hash( 'sha256', \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $offer0['id'] ), \Crm\Portal\Order::sentence( $appData, (array) Documents::get( $appData, $offer0['id'] ) ) ) ) );
	check( '...an order that offered no consent has the JSON it always had: no key of consents', array_key_exists( 'consents', (array) json_decode( \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $offer0['id'] ), 'x' ), true ) ) === false && array_keys( (array) json_decode( \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $offer0['id'] ), 'x', [ 'social' => 'y' ] ), true ) ) === array_merge( array_keys( (array) json_decode( \Crm\Records::orderShown( $appData, (array) Documents::get( $appData, $offer0['id'] ), 'x' ), true ) ), [ 'consents' ] ) );

	// both, and a null byte
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_archive', $archive );
	Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = \'consent_archive\'', [ 'v' => "Archiv\0Ende" ] );
	portalSignIn( $appData, $accounts['anna'] );
	$page = $formPage( $pConsent );
	$names = array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), $boxes( $page, $offer0['id'] ) );
	$ids = array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'id' ), portalFind( $page, '//input[starts-with(@name, "consent[")]' ) );
	check( 'a sentence for each purpose makes two boxes, the social use first; the ids are unique on the page, which holds a form for each offer', $names === [ 'consent[social]', 'consent[archive]' ] && count( $ids ) === count( array_unique( $ids ) ) && count( $ids ) >= 2
		&& array_filter( $boxes( $page, $offer0['id'] ), static fn( \DOMElement $input ): bool => $input->hasAttribute( 'required' ) === true ) === [] );
	$overviewForm = '//form[contains(@class, "crm-order--form")][@data-document="'. $offer0['id']. '"][.//input[@name="from"][@value="uebersicht"]]';
	check( 'the form in the tab Übersicht (from=uebersicht) has the boxes too, in their group, with ids of their own: the same two purposes as the form of the tab Angebot', count( portalFind( $page, $overviewForm ) ) === 1 && array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), portalFind( $page, $overviewForm. '//div[@role="group"]//input[starts-with(@name, "consent[")]' ) ) === [ 'consent[social]', 'consent[archive]' ]
		&& count( portalFind( $page, '//form[@data-document="'. $offer0['id']. '"][.//input[@name="from"][@value="projekt"]]//input[starts-with(@name, "consent[")]' ) ) === 2 );
	check( '...a null byte in the sentence is not on the page', str_contains( $page, "\0" ) === false && str_contains( portalText( $page, '(//form[@data-document="'. $offer0['id']. '"])[1]//input[@name="consent[archive]"]/ancestor::label' ), 'ArchivEnde' ) === true );
	\Crm\Settings::set( $appData, 'consent_archive', $archive );
	check( '...the list of documents draws the same boxes under an offer without a project, from=belege', array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), portalFind( portalPage( $appData, '/portal/belege' )[1], '//li[@data-category="offer"]//form//input[starts-with(@name, "consent[")]' ) ) !== [] );

	// the post: a box ticked, one that was not offered, a key that is none
	$reset();
	$mailFrom = count( $mails );
	$both = $postOrder( $offer0, [ 'consent' => [ 'social' => '1', 'archive' => '1', 'bogus' => '1' ] ], [ 'social' => $social, 'archive' => $archive ] );
	$order0 = \Crm\Records::forDocument( $appData, $offer0['id'] );
	$given = $recordsOf( $a['id'], 'consent' );
	check( 'the order with both boxes ticked is the order and a record of kind consent for each purpose, the order first: ?state=ordered', $both['statusCode'] === 303 && str_contains( $location( $both ), 'state=ordered' ) === true && $order0 !== null && Documents::get( $appData, $offer0['id'] )['state'] === 'accepted'
		&& array_column( $given, 'purpose' ) === [ 'archive', 'social' ] && $given[1]['id'] > $order0['id'] );
	$socialRow = array_values( array_filter( $given, static fn( array $row ): bool => $row['purpose'] === 'social' ) )[0] ?? [];
	check( '...a consent is a record like the order: the purpose, the client, the account as it was, the offer and its project, the sentence as it stood, its hash, the time, the address and the browser - and no parent', ( $socialRow['kind'] ?? '' ) === 'consent' && $socialRow['client_id'] === $a['id'] && $socialRow['member_id'] === $accounts['anna']['id'] && $socialRow['member_email'] === 'anna@example.org'
		&& $socialRow['document_id'] === $offer0['id'] && $socialRow['project'] === $pConsent && $socialRow['shown'] === $social && $socialRow['sha256'] === hash( 'sha256', $social ) && substr( $socialRow['at'], 0, 10 ) === gmdate( 'Y-m-d' ) && $socialRow['ip'] === '127.0.0.1' && $socialRow['parent'] === null );
	check( '...the key that is none is ignored: no record for it and no error', count( $given ) === 2 && array_diff( array_column( $given, 'purpose' ), \Crm\Records::PURPOSES ) === [] );
	$shownJson = (array) json_decode( (string) $order0['shown'], true );
	check( '...the record of the order holds the sentences of the boxes the page showed, as JSON: consents, purpose by purpose', ( $shownJson['consents'] ?? null ) === [ 'social' => $social, 'archive' => $archive ] );
	$ordered0 = portalMailsFrom( $mailFrom );
	check( '...the mail to the owner and the confirmation to the account say what was consented to, one sentence for each', count( $ordered0 ) === 2 && substr_count( $ordered0[0]['body'], $sentenceOf( 'order.owner.consent', 'social' ) ) === 1 && substr_count( $ordered0[0]['body'], $sentenceOf( 'order.owner.consent', 'archive' ) ) === 1
		&& substr_count( $ordered0[1]['body'], $sentenceOf( 'order.confirm.consent', 'social' ) ) === 1 && substr_count( $ordered0[1]['body'], $sentenceOf( 'order.confirm.consent', 'archive' ) ) === 1 && str_contains( $ordered0[0]['body'], '[[' ) === false && str_contains( $ordered0[1]['body'], '[[' ) === false );

	// without a ticked box, and the box that is not offered
	$offerOne = $mkOffer( 'Ohne Haken', [ 'project' => $p1 ] );
	$reset();
	$mailFrom = count( $mails );
	$none = $postOrder( $offerOne, [ 'consent' => [ 'social' => '' ] ] );
	$plainMails = portalMailsFrom( $mailFrom );
	check( 'an order with the boxes not ticked is an order and nothing else: no record of a consent, no sentence about one in the mails - the order does not need them', str_contains( $location( $none ), 'state=ordered' ) === true && \Crm\Records::forDocument( $appData, $offerOne['id'] ) !== null && count( $recordsOf( $a['id'], 'consent' ) ) === 2
		&& count( $plainMails ) === 2 && str_contains( $plainMails[0]['body'], $word( 'social' ) ) === false && str_contains( $plainMails[1]['body'], $word( 'social' ) ) === false
		&& str_contains( $plainMails[0]['body'], (string) strstr( \Crm\Documents\Mail::text( 'order.owner.consent' ), '%s', true ) ) === false && str_contains( $plainMails[1]['body'], (string) strstr( \Crm\Documents\Mail::text( 'order.confirm.consent' ), '%s', true ) ) === false );
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_archive', '' );
	portalSignIn( $appData, $accounts['anna'] );
	$offerTwo = $mkOffer( 'Fremder Zweck', [ 'project' => $p1 ] );
	$reset();
	$foreign = $postOrder( $offerTwo, [ 'consent' => [ 'archive' => '1', 'bogus' => '1', 'social' => '1' ] ] );
	check( 'a key of consent for a purpose that has no sentence, one that is none and a ticked box all in one post: the purpose with a sentence is given, the others are ignored without an error', str_contains( $location( $foreign ), 'state=ordered' ) === true && array_column( $recordsOf( $a['id'], 'consent' ), 'purpose' ) === [ 'social', 'archive', 'social' ] && $recordsOf( $a['id'], 'consent' )[0]['document_id'] === $offerTwo['id'] );
	$offerThree = $mkOffer( 'Kein Feld', [ 'project' => $p1 ] );
	$reset();
	$asString = $postOrder( $offerThree, [ 'consent' => 'social' ] );
	check( '...a consent that is no list is nothing either', str_contains( $location( $asString ), 'state=ordered' ) === true && count( $recordsOf( $a['id'], 'consent' ) ) === 3 );

	$pUe = $made( 'Aus der Übersicht', $a['number'], [], false );
	$offerUe = $mkOffer( 'Übersicht bestellt', [ 'project' => $pUe ] );
	$reset();
	$fromOverview = $postOrder( $offerUe, [ 'from' => 'uebersicht', 'consent' => [ 'social' => '1' ] ] );
	check( 'an order from the tab Übersicht with a box ticked is the order and the consent, and the answer goes back to that tab', str_contains( $location( $fromOverview ), 'tab=uebersicht' ) === true && str_contains( $location( $fromOverview ), 'state=ordered' ) === true && \Crm\Records::forDocument( $appData, $offerUe['id'] ) !== null && ( \Crm\Records::consent( $appData, $a['id'], 'social', $pUe )['document_id'] ?? 0 ) === $offerUe['id'] );
	// a sentence that changed between the form and the post
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_social', 'Neuer Satz.' );
	portalSignIn( $appData, $accounts['anna'] );
	$offerFour = $mkOffer( 'Satz geändert', [] );
	$stalePage = portalPage( $appData, '/portal/belege' )[1];
	$staleHash = portalFind( $stalePage, '(//form[@data-document="'. $offerFour['id']. '"])[1]//input[@name="shown"]/@value' )[0]->nodeValue;
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_social', 'Noch ein Satz.' );
	portalSignIn( $appData, $accounts['anna'] );
	$reset();
	$consentsBeforeChange = count( $recordsOf( $a['id'], 'consent' ) );
	$changed = portalServe( $appData, '/.crm/auftrag', 'POST', [ 'document' => (string) $offerFour['id'], 'from' => 'projekt', 'agree' => '1', 'shown' => $staleHash, 'consent' => [ 'social' => '1' ] ] );
	check( 'a sentence the owner changed between the form and the post answers ?state=changed: no order, no consent, the offer as it was - and the sentence that was given earlier keeps its words', str_contains( $location( $changed ), 'state=changed' ) === true && \Crm\Records::forDocument( $appData, $offerFour['id'] ) === null && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsBeforeChange
		&& Documents::get( $appData, $offerFour['id'] )['state'] !== 'accepted' && array_values( array_filter( $recordsOf( $a['id'], 'consent' ), static fn( array $row ): bool => $row['document_id'] === $offer0['id'] && $row['purpose'] === 'social' ) )[0]['shown'] === $social );
	$asAdmin();
	\Crm\Settings::set( $appData, 'consent_social', $social );
	\Crm\Settings::set( $appData, 'consent_archive', $archive );
	portalSignIn( $appData, $accounts['anna'] );

	// one transaction
	$pAtomic = $made( 'Atomar', $a['number'], [], false );
	$offerAtomic = $mkOffer( 'Atomar', [ 'project' => $pAtomic ] );
	$clientNow = (array) \Crm\Clients::get( $appData, $a['id'] );
	$ordersBefore = count( $recordsOf( $a['id'], 'order' ) );
	$consentsBefore = count( $recordsOf( $a['id'], 'consent' ) );
	$memberOf = [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ];
	Db::pdo( $appData )->exec( 'CREATE TRIGGER refuse_archive BEFORE INSERT ON records WHEN NEW.kind = \'consent\' AND NEW.purpose = \'archive\' BEGIN SELECT RAISE( ABORT, \'refused\' ); END' );
	$thrown = false;

	try {
		Documents::order( $appData, $offerAtomic['id'], $a['id'], $memberOf, 'Satz', [ 'ip' => '', 'agent' => '' ], [ 'social' => $social, 'archive' => $archive ], [ 'social', 'archive' ] );
	}
	catch( \PDOException $e ) {
		$thrown = true;
	}

	Db::pdo( $appData )->exec( 'DROP TRIGGER refuse_archive' );
	check( 'the order and its consents are one transaction: when the second consent cannot be written there is no order, no first consent and the offer is as it was', $thrown === true && count( $recordsOf( $a['id'], 'order' ) ) === $ordersBefore && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsBefore
		&& Documents::get( $appData, $offerAtomic['id'] )['state'] === $offerAtomic['state'] && \Crm\Records::forDocument( $appData, $offerAtomic['id'] ) === null );
	$clean = Documents::order( $appData, $offerAtomic['id'], $a['id'], $memberOf, 'Satz', [ 'ip' => '', 'agent' => '' ], [ 'social' => $social, 'archive' => $archive ], [ 'social' ] );
	check( '...and the same call without the failure makes both: the order, the one consent that was ticked', is_array( $clean ) && count( $clean['consents'] ) === 1 && $clean['consents'][0]['purpose'] === 'social' && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsBefore + 1 && count( $recordsOf( $a['id'], 'order' ) ) === $ordersBefore + 1 );

	$offerRace = $mkOffer( 'Zweite Einwilligung', [ 'project' => $pAtomic ] );
	$consentsHeld = count( $recordsOf( $a['id'], 'consent' ) );
	$race = Documents::order( $appData, $offerRace['id'], $a['id'], $memberOf, 'Satz', [ 'ip' => '', 'agent' => '' ], [ 'social' => $social ], [ 'social' ] );
	check( '...a consent that the client holds for the project already is not made again inside the transaction, whatever the form offered: the order is made, the consent is not', is_array( $race ) && $race['consents'] === [] && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsHeld && \Crm\Records::forDocument( $appData, $offerRace['id'] ) !== null );
	$pEmpty = $made( 'Leerer Satz', $a['number'], [], false );
	$offerEmpty = $mkOffer( 'Box ohne Satz', [ 'project' => $pEmpty ] );
	$emptySentence = Documents::order( $appData, $offerEmpty['id'], $a['id'], $memberOf, 'Satz', [ 'ip' => '', 'agent' => '' ], [ 'social' => '' ], [ 'social' ] );
	check( 'a box that was offered with no sentence is no consent: the whole order is refused as shown, nothing is kept and the offer is as it was', $emptySentence === 'shown' && \Crm\Records::forDocument( $appData, $offerEmpty['id'] ) === null && Documents::get( $appData, $offerEmpty['id'] )['state'] !== 'accepted' && \Crm\Records::consent( $appData, $a['id'], 'social', $pEmpty ) === null );
	$offerNotOffered = $mkOffer( 'Zweck nicht angeboten', [ 'project' => $pEmpty ] );
	$consentsHeld = count( $recordsOf( $a['id'], 'consent' ) );
	$notOffered = Documents::order( $appData, $offerNotOffered['id'], $a['id'], $memberOf, 'Satz', [ 'ip' => '', 'agent' => '' ], [], [ 'social', 'archive' ] );
	check( '...and a purpose that was ticked but never offered makes no consent, whatever is handed over: the order is made, no record of a consent is kept', is_array( $notOffered ) && $notOffered['consents'] === [] && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsHeld && \Crm\Records::forDocument( $appData, $offerNotOffered['id'] ) !== null );

	// who holds a consent has no box for it again
	$offerP1 = $mkOffer( 'Zweiter im Projekt', [ 'project' => $p1 ] );
	$offerNoProject = $mkOffer( 'Ohne Projekt', [] );
	$page = $formPage( $p1 );
	check( 'a client that holds a consent for the project has no box for that purpose under another offer of the project - the other purpose still has its box', array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), $boxes( $page, $offerP1['id'] ) ) === [ 'consent[archive]' ]
		&& \Crm\Records::consent( $appData, $a['id'], 'social', $p1 ) !== null && \Crm\Records::consent( $appData, $a['id'], 'archive', $p1 ) === null );
	$listPage = portalPage( $appData, '/portal/belege' )[1];
	check( '...an offer without a project has its boxes: the consent for a project is not the consent for all of them', array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), portalFind( $listPage, '//li[@data-document="'. $offerNoProject['id']. '"]//input[starts-with(@name, "consent[")]' ) ) === [ 'consent[social]', 'consent[archive]' ] );
	$reset();
	$noProject = $postOrder( $offerNoProject, [ 'from' => 'belege', 'consent' => [ 'social' => '1' ] ] );
	$wide = array_values( array_filter( $recordsOf( $a['id'], 'consent' ), static fn( array $row ): bool => $row['document_id'] === $offerNoProject['id'] ) );
	$offerNoProject2 = $mkOffer( 'Ohne Projekt, zweiter', [] );
	check( '...ordered, the consent holds for the client and has no project; a second offer without one has no box for the purpose any more', str_contains( $location( $noProject ), 'state=ordered' ) === true && count( $wide ) === 1 && $wide[0]['project'] === ''
		&& \Crm\Records::consent( $appData, $a['id'], 'social', '' ) !== null && array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), portalFind( portalPage( $appData, '/portal/belege' )[1], '//li[@data-document="'. $offerNoProject2['id']. '"]//input[starts-with(@name, "consent[")]' ) ) === [ 'consent[archive]' ] );

	$pWide = $made( 'Ohne Einwilligung', $a['number'], [], false );
	$offerWide = $mkOffer( 'Im dritten Projekt', [ 'project' => $pWide ] );
	check( 'a consent for all projects holds for every project of its client: the offer of a project the client never consented to has no box for that purpose, and still has the box of the other', array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), $boxes( $formPage( $pWide ), $offerWide['id'] ) ) === [ 'consent[archive]' ] && ( \Crm\Records::consent( $appData, $a['id'], 'social', $pWide )['id'] ?? 0 ) === $wide[0]['id'] && \Crm\Records::consent( $appData, $a['id'], 'archive', $pWide ) === null );

	// the card after the order
	$cardPage = $formPage( $pConsent );
	$lines = portalFind( $cardPage, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")]' );
	check( 'the card of an offer that was ordered with consents says which: a line for each purpose, with the day and the account, and where they are taken back', count( $lines ) === 2 && array_map( static fn( \DOMElement $line ): string => $line->getAttribute( 'data-purpose' ), $lines ) === [ 'social', 'archive' ]
		&& portalFind( $cardPage, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalText( $cardPage, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][1]//span[@class="crm-order__who"]' ) === 'Anna Nord'
		&& portalFind( $cardPage, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--hint")]//a[@href="/portal/meine-daten#crm-consents"]' ) !== [] );
	check( '...a consent that holds is said in the tone of success with the icon of a check', count( portalFind( $cardPage, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][contains(@class, "crm-note--success")][@data-state="given"]//svg[contains(@class, "crm-icon--check")]' ) ) === 2 );
	check( '...an offer ordered without a consent has no such line and no hint', portalFind( $formPage( $p1 ), '//details[@data-document="'. $offerOne['id']. '"]//p[contains(@class, "crm-order--consent") or contains(@class, "crm-order--hint")]' ) === [] && portalFind( $formPage( $p1 ), '//details[@data-document="'. $offerOne['id']. '"]//p[contains(@class, "crm-order--done")]' ) !== [] );

	// Meine Daten
	$data = portalPage( $appData, '/portal/meine-daten' )[1];
	$rows = portalFind( $data, '//section[@id="crm-consents"]//li[contains(@class, "crm-consent")]' );
	$firstIds = array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-id' ), $rows );
	$expected = array_map( 'strval', array_column( \Crm\Records::consents( $appData, $a['id'] ), 'id' ) );
	check( 'Meine Daten has the block Einwilligungen under the Datenschutz when the client holds a consent: a row for each, the newest first', count( $rows ) === count( $expected ) && $firstIds === $expected && portalFind( $data, '//section[@id="crm-consents"]/preceding-sibling::section[contains(@class, "crm-data__privacy")] | //section[@id="crm-consents"]/preceding-sibling::*' ) !== []
		&& trim( portalText( $data, '//section[@id="crm-consents"]//p[contains(@class, "crm-data__consents-hint")]' ) ) !== '' );
	\Crm\Settings::set( $appData, 'privacy_text', 'Der Text der Datenschutzerklärung.' );
	\Crm\Records::add( $appData, [ 'kind' => 'privacy', 'client_id' => $a['id'], 'member' => [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ], 'shown' => 'Der Text der Datenschutzerklärung.' ] );
	$withPrivacy = portalPage( $appData, '/portal/meine-daten' )[1];
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	check( '...the block stands after the block Datenschutzerklärung and before nothing of the page', portalFind( $withPrivacy, '//section[contains(@class, "crm-data__privacy")]/following-sibling::section[@id="crm-consents"]' ) !== [] && portalFind( $withPrivacy, '//section[@id="crm-consents"]/following-sibling::*' ) === [] );
	$rowSocial = portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]' );
	check( '...a row has the purpose, the project as a link to its page (the title is text), when and by whom, the sentence in a fold as text, and the button', count( $rowSocial ) === 1 && $rowSocial[0]->getAttribute( 'data-purpose' ) === 'social' && $rowSocial[0]->getAttribute( 'data-state' ) === 'given'
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//a[@href="/portal/projekte?projekt='. $pConsent. '"]' ) !== [] && str_contains( portalText( $data, '//li[@data-id="'. $socialRow['id']. '"]//a' ), 'Einwilligung <b>Projekt</b> [members-name]' ) === true
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalText( $data, '//li[@data-id="'. $socialRow['id']. '"]//span[@class="crm-consent__who"]' ) === 'Anna Nord'
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//details//p' ) !== [] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//details//b' ) === [] && str_contains( portalText( $data, '//li[@data-id="'. $socialRow['id']. '"]//details//p' ), '[crm-client] [[/project/company/general/name]] & mehr' ) === true && str_contains( $data, '[crm-client]' ) === false );
	$button = portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form' );
	check( '...the button is a form that posts the id of the consent with the token to /.crm/einwilligung/widerruf, a plain button with no box to tick and no question', count( $button ) === 1 && $button[0]->getAttribute( 'method' ) === 'post' && $button[0]->getAttribute( 'action' ) === '/.crm/einwilligung/widerruf'
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//input[@name="id"]/@value' )[0]->nodeValue === (string) $socialRow['id'] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData )
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//button[@type="submit"]/svg[contains(@class, "crm-icon")][@aria-hidden="true"]' ) !== [] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//input[@type="checkbox"]' ) === [] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//button[@onclick]' ) === [] );
	check( '...the button is neutral, a secondary button and no primary one: taking a consent back is no call to act', portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//button[contains(@class, "crm-btn--secondary")]' ) !== [] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form//button[contains(@class, "crm-btn--primary")]' ) === [] );
	$css = (string) file_get_contents( dirname( __DIR__, 3 ). '/app/Crm/Portal/assets/portal.css' );
	check( '...the sentence of the owner is an isolate for the direction of its letters, in the box and in the fold, so that a mark that turns a line round does not turn the page - and the page carries the sentence in the element the rule names', preg_match( '/\.crm-order__text,\s*\.crm-consent__sentence\s*\{[^}]*unicode-bidi:\s*isolate/', $css ) === 1
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//details//p[contains(@class, "crm-consent__sentence")]' ) !== [] && portalFind( $formPage( $p1 ), '//span[contains(@class, "crm-order__text")]' ) !== [] );
	$wideRow = portalFind( $data, '//li[@data-id="'. $wide[0]['id']. '"]' );
	check( '...a consent without a project says so and links nowhere', count( $wideRow ) === 1 && portalFind( $data, '//li[@data-id="'. $wide[0]['id']. '"]//p[contains(@class, "crm-consent__head")]//a' ) === [] );
	portalSignIn( $appData, $accounts['ben'] );
	check( '...a client with no consent has no block', portalFind( portalPage( $appData, '/portal/meine-daten' )[1], '//section[@id="crm-consents"]' ) === [] && str_contains( portalPage( $appData, '/portal/meine-daten' )[1], 'crm-consent' ) === false );
	portalSignIn( $appData, $accounts['anna'] );
	$start = portalPage( $appData, '/portal' )[1];
	check( '...the start page does not change by it: it names no consent and has no box and no button of one', str_contains( $start, 'crm-consent' ) === false && portalFind( $start, '//*[@data-purpose]') === [] && portalFind( $start, '//form[contains(@action, "einwilligung")]' ) === [] );

	// the route
	$asVisitor();
	$revokeFrom = count( $mails );
	$send = static function( array $post, array $server = [] ) use ( &$appData ): array {
		return portalServe( $appData, '/.crm/einwilligung/widerruf', 'POST', $post, $server );
	};
	$revocations = static function() use ( &$appData ): int {
		return count( \Crm\Db::rows( $appData, 'SELECT id FROM records WHERE kind = \'revocation\'' ) );
	};
	check( 'a visitor posting the button is answered 404, and nothing is kept', $send( [ 'id' => (string) $socialRow['id'] ] )['statusCode'] === 404 && $revocations() === 0 );
	portalSignIn( $appData, $stray );
	check( '...so is a member of no client', $send( [ 'id' => (string) $socialRow['id'] ] )['statusCode'] === 404 && $revocations() === 0 );
	portalSignIn( $appData, $accounts['anna'] );
	$page = '/portal/meine-daten?state=%s&consent=1#crm-consents';
	check( '...a token that is not the session\'s is ?state=expired, back to the block, and nothing is kept', $location( $send( [ 'id' => (string) $socialRow['id'], '_csrf' => 'wrong' ] ) ) === sprintf( $page, 'expired' ) && $revocations() === 0 );
	$benConsent = \Crm\Records::add( $appData, [ 'kind' => 'consent', 'client_id' => $b['id'], 'member' => [ 'id' => $accounts['ben']['id'], 'email' => 'ben@example.org', 'name' => 'Ben Süd' ], 'purpose' => 'social', 'shown' => 'Bens Satz', 'project' => '' ] );
	$orderRecordId = (int) \Crm\Records::forDocument( $appData, $offer0['id'] )['id'];
	$invalid = [ 'a consent of another client' => (string) $benConsent['id'], 'the id of a record that is no consent' => (string) $orderRecordId, 'an id that is not there' => '99999', 'an id that is no number' => '1; DROP TABLE records', 'no id' => '', 'an id with zeros' => '0'. $socialRow['id'] ];
	$answers = [];

	foreach( $invalid as $why => $id )
		$answers[$why] = $location( $send( [ 'id' => $id ] ) ) === sprintf( $page, 'invalid' ) && $revocations() === 0;

	$answers['a list as the id'] = $location( $send( [ 'id' => [ (string) $socialRow['id'] ] ] ) ) === sprintf( $page, 'invalid' ) && $revocations() === 0;
	check( '...an id that is not a consent of this client is ?state=invalid, whatever it is: another client\'s consent, an order, an id that is not there, text, nothing, a list - and nothing is kept', array_keys( array_filter( $answers, static fn( bool $same ): bool => $same === false ) ) === [] );
	$reset();
	$beforeGone = count( $mails );
	$gone = $send( [ 'id' => (string) $socialRow['id'] ], [ 'HTTP_USER_AGENT' => 'Browser/2.0 (Widerruf)' ] );
	$revocation = \Crm\Db::row( $appData, 'SELECT * FROM records WHERE kind = \'revocation\'' ) ?? [];
	$ownerMails = $consentMails( $beforeGone );
	$clientTitle = (string) \Crm\Clients::get( $appData, $a['id'] )['number']. ' '. (string) \Crm\Clients::get( $appData, $a['id'] )['title'];
	check( 'the revocation is ?state=revoked, back to the block, and one record of kind revocation: the consent as its parent, its purpose, its project and its sentence, the account that revoked, the time, the address and the browser', $location( $gone ) === sprintf( $page, 'revoked' ) && $revocations() === 1 && (int) $revocation['parent'] === $socialRow['id'] && $revocation['purpose'] === 'social' && $revocation['project'] === $pConsent
		&& $revocation['shown'] === $social && $revocation['sha256'] === hash( 'sha256', $social ) && (int) $revocation['client_id'] === $a['id'] && $revocation['member_id'] === $accounts['anna']['id'] && $revocation['agent'] === 'Browser/2.0 (Widerruf)' && $revocation['ip'] === '127.0.0.1' && $revocation['document_id'] === null );
	check( '...it revokes for the future and deletes nothing: the consent is as it was, with the record beside it - and holds no more', \Crm\Db::row( $appData, 'SELECT * FROM records WHERE id = :id', [ 'id' => $socialRow['id'] ] )['shown'] === $social && ( \Crm\Records::consent( $appData, $a['id'], 'social', $pConsent )['id'] ?? 0 ) === $wide[0]['id'] && count( $recordsOf( $a['id'], 'consent' ) ) === $consentsBefore + 2
		&& array_values( array_filter( \Crm\Records::consents( $appData, $a['id'] ), static fn( array $row ): bool => $row['id'] === $socialRow['id'] ) )[0]['revoked']['id'] === (int) $revocation['id'] );
	$mail = $ownerMails[0] ?? [];
	check( '...the owner is mailed, once: "Einwilligung widerrufen: <purpose>, Projekt <id and title>, Kunde <number and title> durch <account>", the reply to the account, a link to the card of the project - and the account gets none', count( $ownerMails ) === 1 && count( portalMailsFrom( $beforeGone ) ) === 1 && ( $mail['to'] ?? '' ) === 'owner@example.org' && ( $mail['replyTo'] ?? '' ) === 'anna@example.org'
		&& ( $mail['subject'] ?? '' ) === \Crm\Documents\Mail::text( 'revocation.owner.subject', $word( 'social' ), $pConsent. ' Einwilligung <b>Projekt</b> [members-name]', $clientTitle, 'Anna Nord' )
		&& str_contains( $mail['body'], 'href="https://example.org/club/crm#projekt-'. $pConsent. '"' ) === true && str_contains( $mail['body'], '&lt;b&gt;Projekt&lt;/b&gt;' ) === true && str_contains( $mail['body'], '<b>Projekt' ) === false && str_contains( $mail['body'], '[[' ) === false && str_contains( $mail['body'], 'anna@example.org' ) === true );
	$reset();
	$beforeName = count( $mails );
	\Crm\Documents\Mail::revoked( $appData, [ 'purpose' => 'social', 'project' => '', 'member_name' => "Anna\nBcc: x\u{202E}Rev", 'member_email' => 'anna@example.org' ], (array) \Crm\Clients::get( $appData, $a['id'] ) );
	$nameMail = $consentMails( $beforeName )[0] ?? [];
	check( '...the name of the account is one line in the subject: a line break and a mark that turns the text round are blanks - there is no second header in it', $nameMail !== [] && preg_match( '/[\r\n\x{202E}]/u', (string) $nameMail['subject'] ) === 0 && str_contains( $nameMail['subject'], 'Anna Bcc: x Rev' ) === true );
	$reset();
	$beforeBogus = count( $mails );
	$bogusThrown = false;

	try {
		\Crm\Documents\Mail::ordered( $appData, (array) Documents::get( $appData, $offer0['id'] ), (array) \Crm\Records::forDocument( $appData, $offer0['id'] ), (array) \Crm\Clients::get( $appData, $a['id'] ), [ [ 'purpose' => 'bogus' ], [ 'purpose' => 'social' ] ] );
	}
	catch( \Throwable $e ) {
		$bogusThrown = true;
	}

	$bogusMails = portalMailsFrom( $beforeBogus );
	check( '...the mails about an order say a sentence for the purposes there are and skip a record that names none - they do not stop', $bogusThrown === false && count( $bogusMails ) === 2 && substr_count( $bogusMails[0]['body'], $sentenceOf( 'order.owner.consent', 'social' ) ) === 1 );
	$beforeAgain = count( $mails );
	$again = $send( [ 'id' => (string) $socialRow['id'] ] );
	check( 'a second revocation of the same consent is ?state=invalid: no second record, no mail', $location( $again ) === sprintf( $page, 'invalid' ) && $revocations() === 1 && count( portalMailsFrom( $beforeAgain ) ) === 0 );
	check( '...the table refuses a second record that names the consent, whatever the code says', ( static function() use ( &$appData, $socialRow, $a ): bool {
		try {
			Db::exec( $appData, 'INSERT INTO records ( kind, client_id, member_id, sha256, shown, at, purpose, parent ) VALUES ( \'revocation\', :c, \'x\', \'x\', \'x\', \'x\', \'social\', :p )', [ 'c' => $a['id'], 'p' => $socialRow['id'] ] );
			return false;
		}
		catch( \PDOException $e ) {
			return true;
		}
	} )() === true && $revocations() === 1 );
	check( '...and the consent that a revocation names is not deleted', ( static function() use ( &$appData, $socialRow ): bool {
		try {
			Db::exec( $appData, 'DELETE FROM records WHERE id = :id', [ 'id' => $socialRow['id'] ] );
			return false;
		}
		catch( \PDOException $e ) {
			return true;
		}
	} )() === true );

	// the page after it
	$data = portalPage( $appData, '/portal/meine-daten' )[1];
	$rowGone = portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]' );
	check( 'Meine Daten says the consent was revoked, when and by whom, in a pill, and has no button for it any more - the button stays at the others', $rowGone[0]->getAttribute( 'data-state' ) === 'revoked' && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//form' ) === [] && portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//p[contains(@class, "crm-consent__revoked")]//span[contains(@class, "crm-pill--muted")]' ) !== []
		&& portalFind( $data, '//li[@data-id="'. $socialRow['id']. '"]//p[contains(@class, "crm-consent__revoked")]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalFind( $data, '//li[@data-id="'. $wide[0]['id']. '"]//form' ) !== [] );
	check( '...a state of the query with consent=1 is said in the block - revoked as a status, invalid and expired as an alert - and not above the form; a state of the form is not said in the block', portalFind( portalPage( $appData, '/portal/meine-daten?state=revoked&consent=1' )[1], '//section[@id="crm-consents"]//p[@role="status"][contains(@class, "crm-consent__state--revoked")]' ) !== []
		&& portalFind( portalPage( $appData, '/portal/meine-daten?state=invalid&consent=1' )[1], '//section[@id="crm-consents"]//p[@role="alert"][contains(@class, "crm-consent__state--invalid")]' ) !== [] && portalFind( portalPage( $appData, '/portal/meine-daten?state=expired&consent=1' )[1], '//section[@id="crm-consents"]//p[@role="alert"]' ) !== []
		&& portalFind( portalPage( $appData, '/portal/meine-daten?state=expired&consent=1' )[1], '//form[contains(@class, "crm-data__form")]//p[contains(@class, "crm-data__state")]' ) === [] && portalFind( portalPage( $appData, '/portal/meine-daten?state=expired' )[1], '//form[contains(@class, "crm-data__form")]//p[contains(@class, "crm-data__state")]' ) !== []
		&& portalFind( portalPage( $appData, '/portal/meine-daten?state=expired' )[1], '//section[@id="crm-consents"]//p[contains(@class, "crm-consent__state")]' ) === [] && portalFind( portalPage( $appData, '/portal/meine-daten?state=bogus&consent=1' )[1], '//p[contains(@class, "crm-consent__state")]' ) === [] );
	$offerAfter = $mkOffer( 'Nach dem Widerruf', [ 'project' => $pConsent ] );
	check( 'a consent for all projects holds for the project the consent for it was taken back from: the next offer of the project has no box for the purpose', $boxes( $formPage( $pConsent ), $offerAfter['id'] ) === [] );
	$lineGone = portalFind( $formPage( $pConsent ), '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][@data-purpose="social"]' );
	check( '...and the card of the offer the consent was given with says it was revoked', count( $lineGone ) === 1 && $lineGone[0]->getAttribute( 'data-state' ) === 'revoked' );
	check( '...in the tone of an info note, not of a success, with the icon of a cross', $lineGone[0]->getAttribute( 'class' ) !== '' && str_contains( $lineGone[0]->getAttribute( 'class' ), 'crm-note--info' ) === true && str_contains( $lineGone[0]->getAttribute( 'class' ), 'crm-note--success' ) === false && portalFind( $formPage( $pConsent ), '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][@data-state="revoked"]//svg[contains(@class, "crm-icon--x")]' ) !== [] );

	// the revocation of the consent without a project; the mail, the caps and the missing address
	$reset();
	$mailsAt = count( $mails );
	$wideGone = $send( [ 'id' => (string) $wide[0]['id'] ] );
	$wideMail = $consentMails( $mailsAt )[0] ?? [];
	check( 'a consent that is not for a project: the mail says all projects and links to the card of the client', $location( $wideGone ) === sprintf( $page, 'revoked' ) && ( $wideMail['subject'] ?? '' ) === \Crm\Documents\Mail::text( 'revocation.owner.subject', $word( 'social' ), \Crm\Documents\Mail::text( 'revocation.owner.all' ), $clientTitle, 'Anna Nord' ) && str_contains( $wideMail['body'] ?? '', 'href="https://example.org/club/crm#kunde-'. $a['id']. '"' ) === true );
	check( 'once the consent for all projects is taken back too, the next offer of the project asks again: the box of the purpose is back', in_array( 'consent[social]', array_map( static fn( \DOMElement $input ): string => $input->getAttribute( 'name' ), $boxes( $formPage( $pConsent ), $offerAfter['id'] ) ), true ) === true );
	$archiveRow = array_values( array_filter( $recordsOf( $a['id'], 'consent' ), static fn( array $row ): bool => $row['purpose'] === 'archive' ) )[0];
	$reset();
	$GLOBALS['delivery'] = false;
	$ignored = ninoWarnings();
	$failed = $send( [ 'id' => (string) $archiveRow['id'] ] );
	$GLOBALS['delivery'] = true;
	check( 'a mail the transport refuses does not undo the revocation: ?state=nomail, the record is kept and the log has a line', $location( $failed ) === sprintf( $page, 'nomail' ) && \Crm\Records::consent( $appData, $a['id'], 'archive', (string) $archiveRow['project'] ) === null && $revocations() === 3 && implode( ' ', ninoWarnings() ) !== '' );
	check( '...and the page says it as a status, once', count( portalFind( portalPage( $appData, '/portal/meine-daten?state=nomail&consent=1' )[1], '//p[@role="status"][contains(@class, "crm-consent__state--nomail")]' ) ) === 1 );
	$cardAfter = $formPage( $pConsent );
	check( '...the card of the offer the consents were given with says both were revoked and, with none that holds, has no hint that the revocation stands under Meine Daten', count( portalFind( $cardAfter, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][@data-state="revoked"]' ) ) === 2
		&& portalFind( $cardAfter, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--consent")][@data-state="given"]' ) === [] && portalFind( $cardAfter, '//details[@data-document="'. $offer0['id']. '"]//p[contains(@class, "crm-order--hint")]' ) === [] );
	$left = array_values( array_filter( \Crm\Records::consents( $appData, $a['id'] ), static fn( array $row ): bool => $row['revoked'] === null ) );
	\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => '' ], '*' );
	$ignored = ninoWarnings();
	$reset();
	$quiet = $send( [ 'id' => (string) $left[0]['id'] ] );
	\Nino\Html::addFills( $appData, [ '[[/project/website/general/url]]' => 'example.org/club' ], '*' );
	check( 'a site with no address has no link for the mail: the revocation is kept all the same and the page promises nothing but that', $location( $quiet ) === sprintf( $page, 'nomail' ) && $revocations() === 4 && str_contains( implode( ' ', ninoWarnings() ), 'revocation' ) === true );

	// the gate
	$left = array_values( array_filter( \Crm\Records::consents( $appData, $a['id'] ), static fn( array $row ): bool => $row['revoked'] === null ) );
	check( 'Anna holds a consent that no revocation names, for the gate to hold back', count( $left ) >= 1 );
	$asAdmin();
	\Crm\Settings::set( $appData, 'privacy_text', 'Ein neuer Text der Datenschutzerklärung.' );
	portalSignIn( $appData, $accounts['anna'] );
	$reset();
	$gated = $send( [ 'id' => (string) $left[0]['id'] ] );
	check( 'while the Datenschutz is the step that is pending the revocation is not made: the member is sent to the step, nothing is kept and nobody is mailed', str_starts_with( $location( $gated ), '/portal/datenschutz' ) === true && $revocations() === 4 && \Crm\Records::consent( $appData, $a['id'], $left[0]['purpose'], (string) $left[0]['project'] ) !== null );
	$asAdmin();
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	portalSignIn( $appData, $accounts['anna'] );

	// the step of the data is Meine Daten, where the button stands: it does not hold the revocation back
	$dora = \Crm\Clients::add( $appData, [ 'name' => 'Dora Daten' ] );
	$doraAccount = \Nino\Modules\Members::add( $appData, 'dora-konsens@example.org', 'Dora Daten', $dora['group'] );
	\Crm\Clients::link( $appData, $dora['id'], $doraAccount['id'] );
	$doraConsent = \Crm\Records::add( $appData, [ 'kind' => 'consent', 'client_id' => $dora['id'], 'member' => [ 'id' => $doraAccount['id'], 'email' => 'dora-konsens@example.org', 'name' => 'Dora Daten' ], 'purpose' => 'social', 'shown' => 'Doras Satz', 'project' => '' ] );
	portalSignIn( $appData, $doraAccount );
	$reset();
	$beforeDora = count( $mails );
	$doraPage = portalPage( $appData, '/portal/meine-daten' )[1];
	$doraBefore = $revocations();
	$doraPost = $send( [ 'id' => (string) $doraConsent['id'] ] );
	check( 'a client whose step of the data is pending - the step is Meine Daten, where the block and the button stand - takes the consent back all the same: ?state=revoked, one record of kind revocation, the owner mailed', \Crm\Portal::pending( $appData, (array) \Crm\Clients::get( $appData, $dora['id'] ) ) === 'data'
		&& portalFind( $doraPage, '//li[@data-id="'. $doraConsent['id']. '"]//form' ) !== [] && $location( $doraPost ) === sprintf( $page, 'revoked' ) && $revocations() === $doraBefore + 1 && \Crm\Records::consent( $appData, $dora['id'], 'social', '' ) === null && count( $consentMails( $beforeDora ) ) === 1 );
	portalSignIn( $appData, $accounts['anna'] );

	// a consent revoked by the class twice: the table and the check say the same
	$left = array_values( array_filter( \Crm\Records::consents( $appData, $a['id'] ), static fn( array $row ): bool => $row['revoked'] === null ) );
	$memberRow = [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ];
	check( 'Records::revoke is the one step: a consent that is revoked, is another client\'s or is none answers parent and keeps nothing', \Crm\Records::revoke( $appData, $a['id'], $socialRow['id'], $memberRow, [] ) === 'parent' && \Crm\Records::revoke( $appData, $b['id'], $left[0]['id'], $memberRow, [] ) === 'parent' && \Crm\Records::revoke( $appData, $a['id'], $orderRecordId, $memberRow, [] ) === 'parent' && $revocations() === $doraBefore + 1 );

	// the page in English
	$appData['./nino/locales/current'] = 'en_US';
	$english = \Nino\Html::renderHtml( $appData, '[crm-client-data]' );
	$appData['./nino/locales/current'] = 'de_DE';
	check( 'Meine Daten in English has the block with the English words: the title, the purpose, the button and the pill of a revoked consent', portalText( $english, '//section[@id="crm-consents"]//h3' ) === $wordsEn['[[/project/crm-portal/consent/title]]'] && portalText( $english, '//section[@id="crm-consents"]//li[@data-purpose="social"]//strong' ) === $wordsEn['[[/project/crm-portal/consent/purpose-social]]']
		&& portalText( $english, '//section[@id="crm-consents"]//li[@data-state="revoked"]//span[contains(@class, "crm-pill")]' ) === $wordsEn['[[/project/crm-portal/consent/revoked]]'] && $wordsEn['[[/project/crm-portal/consent/title]]'] !== $wordsDe['[[/project/crm-portal/consent/title]]'] && str_contains( $english, '[[' ) === false );

	// the words and the route
	$words = array_values( array_filter( array_keys( $wordsDe ), static fn( string $key ): bool => str_starts_with( $key, '[[/project/crm-portal/consent/' ) ) );
	check( 'the words of the consents are in German and English, none empty; the German is Du; each state of the route has its sentence', $words !== [] && array_diff( $words, array_keys( $wordsEn ) ) === [] && array_filter( $words, static fn( string $key ): bool => trim( $wordsDe[$key] ) === '' || trim( $wordsEn[$key] ) === '' ) === []
		&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', array_map( static fn( string $key ): string => $wordsDe[$key], $words ) ) ) !== 1 && array_diff( array_map( static fn( string $state ): string => '[[/project/crm-portal/consent/state-'. $state. ']]', array_keys( \Crm\Portal\Consent::STATES ) ), $words ) === []
		&& array_diff( array_map( static fn( string $purpose ): string => '[[/project/crm-portal/consent/purpose-'. $purpose. ']]', \Crm\Records::PURPOSES ), $words ) === [] && isset( $wordsDe['[[/project/crm-portal/order/optional]]'], $wordsEn['[[/project/crm-portal/order/optional]]'] ) === true );
	check( '...the words of the mails are German with Du, one for each purpose', array_filter( [ 'order.owner.consent', 'order.confirm.consent', 'consent.purpose.social', 'consent.purpose.archive', 'revocation.owner.subject', 'revocation.owner.title', 'revocation.owner.intro', 'revocation.owner.purpose', 'revocation.owner.project', 'revocation.owner.all', 'revocation.owner.client', 'revocation.owner.account', 'revocation.owner.button.project', 'revocation.owner.button.client', 'revocation.owner.notice' ],
		static fn( string $key ): bool => trim( \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === '' || preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === 1 ) === [] );
	check( '...the route is registered once and only for the POST', ( $appData['/nino/http/routes']['POST://.crm/einwilligung/widerruf']['uri'] ?? '' ) === \Crm\Portal\Consent::ROUTE && isset( $appData['/nino/http/routes']['GET://.crm/einwilligung/widerruf'] ) === false );

	\Crm\Settings::set( $appData, 'privacy_text', $privacyWas );
	\Crm\Settings::set( $appData, 'consent_social', '' );
	\Crm\Settings::set( $appData, 'consent_archive', '' );
}

// --- Angebot ablehnen, Frage zum Angebot: the two quiet ways under the form of the order ----------------------------------------------------------

/**
 *	The checks of the decline of an offer (POST /.crm/ablehnen, Documents::decline()), of the question by mail and of what needs() says of it
 */
function portalDeclineChecks( array &$appData, array $a, array $b, array $accounts, array $stray, string $p1, string $today, array $wordsDe, array $wordsEn, \Closure $reset, \Closure $asAdmin, \Closure $asVisitor, \Closure $final, \Closure $line, \Closure $made, \Closure $location, \Closure $mkOffer ): void {

	global $mails;

	echo "\nPOST /.crm/ablehnen and the question about an offer\n";

	$reset();
	$asAdmin();
	$privacyWas = \Crm\Settings::get( $appData, 'privacy_text' );
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	$valid = static fn( int $days ): string => date( 'Y-m-d', strtotime( ( $days >= 0 ? '+' : '' ). $days. ' days' ) );
	$declinesOf = static fn( int $clientId ): array => \Crm\Records::of( $appData, $clientId, 'decline' );
	$stateOf = static fn( int $id ): string => (string) Documents::get( $appData, $id )['state'];
	$declineMails = static fn( int $from ): array => array_values( array_filter( portalMailsFrom( $from ), static fn( array $mail ): bool => str_starts_with( (string) ( $mail['subject'] ?? '' ), 'Angebot abgelehnt' ) ) );
	$offerOne = $mkOffer( 'Ablehnen eins', [ 'project' => $p1 ] );
	$offerTwo = $mkOffer( 'Ablehnen zwei', [] );
	$offerThree = $mkOffer( 'Ablehnen drei', [ 'project' => $p1 ] );
	$offerExpired = $mkOffer( 'Ablehnen abgelaufen', [ 'project' => $p1, 'valid_until' => $valid( -1 ) ] );
	$offerUnreleased = $mkOffer( 'Ablehnen nicht freigegeben', [ 'project' => $p1 ], false );
	$offerBen = $final( 'offer', $b['id'], [ 'date' => $today, 'valid_until' => $valid( 20 ), 'template_key' => 'angebot', 'lines' => [ $line( 'Bens Angebot' ) ] ] );
	Documents::release( $appData, $offerBen['id'], false );
	$pBen = $made( 'Ablehnen Bens Projekt', $b['number'], [], false );
	$offerBenProject = $final( 'offer', $b['id'], [ 'date' => $today, 'valid_until' => $valid( 20 ), 'project' => $pBen, 'template_key' => 'angebot', 'lines' => [ $line( 'Bens Angebot mit Projekt' ) ] ] );
	Documents::release( $appData, $offerBenProject['id'], false );
	$invoice = $final( 'invoice', $a['id'], [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( 'Rechnung' ) ] ] );
	Documents::release( $appData, $invoice['id'], false );
	$pDecline = $made( 'Ablehnen Projekt', $a['number'], [], false );
	$offerFiles = $mkOffer( 'Ablehnen sperrt', [ 'project' => $pDecline ] );
	$offerOwner = $mkOffer( 'Ablehnen Inhaber', [ 'project' => $p1 ] );
	Documents::setState( $appData, $offerOwner['id'], 'declined' );
	$hash = static function( int $id, string $reason = '' ) use ( &$appData ): string {
		return hash( 'sha256', \Crm\Records::declineShown( $appData, (array) Documents::get( $appData, $id ), $reason ) );
	};
	$post = static function( array $over = [], array $server = [] ) use ( &$appData, $offerOne, $hash ): array {

		$id = is_string( $over['document'] ?? null ) === true && ctype_digit( $over['document'] ) === true ? (int) $over['document'] : $offerOne['id'];
		$over += [ 'document' => (string) $offerOne['id'], 'from' => 'projekt', 'reason' => '' ];

		if( array_key_exists( 'shown', $over ) === false )
			$over['shown'] = Documents::get( $appData, $id ) === null ? 'x' : $hash( $id );

		return portalServe( $appData, '/.crm/ablehnen', 'POST', $over, $server );
	};
	$untouched = static fn(): bool => $declinesOf( $a['id'] ) === [] && $declinesOf( $b['id'] ) === [] && in_array( Documents::get( $appData, $offerOne['id'] )['state'], [ 'open', 'sent' ], true ) === true;
	$to = static fn( string $state, int $id, string $project = '' ): string => $project === '' ? '/portal/belege?state='. $state. '&beleg='. $id. '#beleg-'. $id : '/portal/projekte?projekt='. $project. '&tab=angebot&state='. $state. '&beleg='. $id. '#angebot-'. $id;

	// the two ways under the form
	\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
	portalSignIn( $appData, $accounts['anna'] );
	$page = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
	$section = portalSection( $page, 'offer' );
	$fold = portalFind( $section, '//details[contains(concat(" ", normalize-space(@class), " "), " crm-decline ")][.//form[@data-document="'. $offerOne['id']. '"]]' );
	$form = portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]' );
	check( 'under the form of the order of each offer that can be ordered stands a fold Angebot ablehnen with a form of its own - and under no other offer: not the one that ran out, the one not released, the one the owner declined', count( $fold ) === 1 && count( $form ) === 1 && $fold[0]->hasAttribute( 'open' ) === false
		&& count( portalFind( $section, '//form[contains(@class, "crm-decline__form")]' ) ) === count( portalFind( $section, '//form[contains(@class, "crm-order--form")]' ) ) && portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerExpired['id']. '"]' ) === []
		&& portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOwner['id']. '"]' ) === [] && portalFind( $section, '//details[contains(@class, "crm-offer")][@data-document="'. $offerOne['id']. '"]//details[contains(@class, "crm-decline")]/summary' ) !== [] );
	check( '...the form is a plain form that posts to /.crm/ablehnen with the token, the offer, the page it stands on and the hash of the offer as it shows it (the JSON without a reason); its one field is a text area for the reason, voluntary and at most 500 characters, with a label', portalFind( $section, '//form[@data-document="'. $offerOne['id']. '"][contains(@class, "crm-decline__form")]/@action' )[0]->nodeValue === '/.crm/ablehnen' && $form[0]->getAttribute( 'method' ) === 'post'
		&& portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//input[@name="document"]/@value' )[0]->nodeValue === (string) $offerOne['id']
		&& portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//input[@name="from"]/@value' )[0]->nodeValue === 'projekt' && portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//input[@name="shown"]/@value' )[0]->nodeValue === $hash( $offerOne['id'] )
		&& count( portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//textarea[@name="reason"][@maxlength="500"][not(@required)]' ) ) === 1 && portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//textarea[@name="reason"]/@id' )[0]->nodeValue !== ''
		&& portalFind( $section, '//label[@for="'. portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//textarea/@id' )[0]->nodeValue. '"]' ) !== [] && portalFind( $section, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerOne['id']. '"]//button[@type="submit"]' ) !== [] && ( new \ReflectionClass( \Crm\Records::class ) )->getConstant( 'REASON' ) === 500 );
	$belege = portalPage( $appData, '/portal/belege' )[1];
	check( '...the list of documents has the fold under the row of an offer that can be ordered, from=belege, and under no row of an invoice', portalFind( $belege, '//li[@data-document="'. $offerTwo['id']. '"]//form[contains(@class, "crm-decline__form")]//input[@name="from"]/@value' )[0]->nodeValue === 'belege' && portalFind( $belege, '//li[@data-category="invoice"]//form[contains(@class, "crm-decline__form")]' ) === [] );

	// the question
	$mail = portalFind( $section, '//details[@data-document="'. $offerOne['id']. '"]//a[contains(@class, "crm-order__question")]' );
	check( 'the question is a link mailto: to the contact address of the company with the number of the offer as the subject, encoded for the query - no blank in the address of the link', count( $mail ) === 1 && str_contains( $page, '?subject=Angebot%20' ) === true && str_contains( $page, '?subject=Angebot ' ) === false && $mail[0]->getAttribute( 'href' ) === 'mailto:owner@example.org?subject='. rawurlencode( 'Angebot '. $offerOne['number'] ) && trim( $mail[0]->textContent ) !== '' );
	\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'a&b=c?d@example.org' ], '*' );
	$odd = portalFind( portalSection( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], 'offer' ), '//details[@data-document="'. $offerOne['id']. '"]//a[contains(@class, "crm-order__question")]/@href' );
	check( '...an address with characters that end a query is encoded, the sign @ stays, and nothing of it can open a second header or a second parameter', count( $odd ) === 1 && $odd[0]->nodeValue === 'mailto:a%26b%3Dc%3Fd@example.org?subject='. rawurlencode( 'Angebot '. $offerOne['number'] ) );
	$withoutAddress = [];

	foreach( [ 'none' => '', 'not an address' => 'not an address', 'a line break' => "x@example.org\r\nBcc: y@example.org", 'markup' => '<b>@example.org' ] as $label => $value ) {
		\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => $value ], '*' );
		$html = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
		$withoutAddress[$label] = portalFind( $html, '//a[contains(@class, "crm-order__question")]' ) === [] && str_contains( $html, 'mailto:' ) === false && portalFind( $html, '//details[contains(@class, "crm-decline")]' ) !== [];
	}

	check( '...there is no link without an address, with one that is none, with a line break in it or with markup: the fold stays', array_keys( array_filter( $withoutAddress, static fn( bool $ok ): bool => $ok === false ) ) === [] );
	\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );

	// the post that is refused
	$asVisitor();
	check( 'a visitor posting the form is answered 404', $post()['statusCode'] === 404 && $untouched() );
	portalSignIn( $appData, $stray );
	check( '...so is a member of no client', $post()['statusCode'] === 404 && $untouched() );
	portalSignIn( $appData, $accounts['anna'] );
	$beforeMails = count( $mails );
	check( '...a token that is not the session\'s is ?state=expired, on the page the form stood on, nothing kept', $location( $post( [ '_csrf' => 'wrong' ] ) ) === $to( 'expired', $offerOne['id'], $p1 ) && $untouched() );
	check( '...an offer that is none of the client\'s - another client\'s, one that is not there, an id that is none, an invoice - is ?state=invalid, the list of documents, no offer named and no project of the other client either', $location( $post( [ 'document' => (string) $offerBen['id'] ] ) ) === '/portal/belege?state=invalid' && $location( $post( [ 'document' => (string) $offerBenProject['id'] ] ) ) === '/portal/belege?state=invalid' && $location( $post( [ 'document' => '99999' ] ) ) === '/portal/belege?state=invalid'
		&& $location( $post( [ 'document' => '1; DROP' ] ) ) === '/portal/belege?state=invalid' && $location( $post( [ 'document' => (string) $invoice['id'], 'shown' => 'x' ] ) ) === '/portal/belege?state=invalid' && $location( $post( [ 'document' => [ 'x' ] ] ) ) === '/portal/belege?state=invalid' && $untouched() && Documents::get( $appData, $offerBen['id'] )['state'] !== 'declined' && Documents::get( $appData, $offerBenProject['id'] )['state'] !== 'declined' );
	check( '...an offer that is not released, that ran out or that the owner declined already is ?state=invalid too, on the page of its project and with no offer named', array_filter( [ $offerUnreleased, $offerExpired, $offerOwner ], static fn( array $offer ): bool => $location( $post( [ 'document' => (string) $offer['id'] ] ) ) !== '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' ) === [] && $untouched() );
	check( '...a post whose hash is not the hash of the offer as it stands - none, an old one, an array - is ?state=changed and keeps nothing', $location( $post( [ 'shown' => 'x' ] ) ) === $to( 'changed', $offerOne['id'], $p1 ) && $location( $post( [ 'shown' => null ] ) ) === $to( 'changed', $offerOne['id'], $p1 ) && $location( $post( [ 'shown' => [ 'x' ] ] ) ) === $to( 'changed', $offerOne['id'], $p1 ) && $untouched() );
	check( '...a reason that is longer than 500 characters, no text or no UTF-8 is ?state=reason, and a reason of exactly 500 characters is taken', $location( $post( [ 'reason' => str_repeat( 'x', 501 ) ] ) ) === $to( 'reason', $offerOne['id'], $p1 ) && $location( $post( [ 'reason' => [ 'x' ] ] ) ) === $to( 'reason', $offerOne['id'], $p1 ) && $location( $post( [ 'reason' => "\xff\xfe" ] ) ) === $to( 'reason', $offerOne['id'], $p1 ) && $untouched() && count( $declineMails( $beforeMails ) ) === 0
		&& \Crm\Records::reason( str_repeat( 'ä', 500 ) ) === str_repeat( 'ä', 500 ) && \Crm\Records::reason( str_repeat( 'ä', 501 ) ) === null );
	check( '...nothing was mailed by any of these', count( portalMailsFrom( $beforeMails ) ) === 0 );

	// the decline
	$reason = "Zu teuer <b>fett</b> [crm-client] [[/project/company/general/name]]\0 &amp;\r\n\tZweite Zeile\x07";
	$kept = "Zu teuer <b>fett</b> [crm-client] [[/project/company/general/name]] &amp;\n\tZweite Zeile";
	$offerBefore = (array) Documents::get( $appData, $offerOne['id'] );
	$answer = $post( [ 'reason' => $reason, 'shown' => $hash( $offerOne['id'] ) ], [ 'HTTP_USER_AGENT' => 'Browser/1.0 (Decline)' ] );
	$records = $declinesOf( $a['id'] );
	$shown = (array) json_decode( $records[0]['shown'] ?? '', true );
	check( 'the decline: ?state=declined on the page of the project with the offer named, the offer is declined and one record of kind decline is kept', $answer['statusCode'] === 303 && $location( $answer ) === $to( 'declined', $offerOne['id'], $p1 ) && $stateOf( $offerOne['id'] ) === 'declined' && count( $records ) === 1 && $records[0]['kind'] === 'decline' );
	check( '...the record names the offer and its project, the account as it was, the client, the time, the address and the browser - and leaves the purpose empty', $records[0]['document_id'] === $offerOne['id'] && $records[0]['project'] === $p1 && $records[0]['client_id'] === $a['id'] && $records[0]['member_id'] === $accounts['anna']['id'] && $records[0]['member_email'] === 'anna@example.org' && $records[0]['member_name'] === 'Anna Nord'
		&& substr( $records[0]['at'], 0, 10 ) === gmdate( 'Y-m-d' ) && $records[0]['ip'] === '127.0.0.1' && $records[0]['agent'] === 'Browser/1.0 (Decline)' && $records[0]['purpose'] === '' && $records[0]['parent'] === null && \Crm\Records::declineOf( $appData, $offerOne['id'] ) === $records[0] && \Crm\Records::forDocument( $appData, $offerOne['id'] ) === null );
	check( '...what was shown is the JSON of the offer and the reason - no control character but the line break and the tab, the null byte gone, markup and shortcodes as typed - with its hash; the same offer and reason give the same text again', $records[0]['sha256'] === hash( 'sha256', $records[0]['shown'] ) && $shown['reason'] === $kept && $shown['number'] === $offerOne['number'] && $shown['project'] === $p1 && $shown['lines'][0]['title'] === 'Ablehnen eins' && $shown['sums']['gross'] === 10000 && array_key_exists( 'sentence', $shown ) === false
		&& \Crm\Records::declineShown( $appData, $offerBefore, $kept ) === $records[0]['shown'] && \Crm\Records::reasonOf( $records[0] ) === $kept && str_contains( $records[0]['shown'], "\0" ) === false );
	$out = $declineMails( $beforeMails );
	check( 'one mail goes out, to the owner alone: "Angebot abgelehnt: <number> von Kunde <number and company> durch <account>", the reply to the account - and none to the customer', count( $out ) === 1 && count( portalMailsFrom( $beforeMails ) ) === 1 && ( $out[0]['to'] ?? '' ) === 'owner@example.org' && ( $out[0]['replyTo'] ?? '' ) === 'anna@example.org'
		&& ( $out[0]['subject'] ?? '' ) === 'Angebot abgelehnt: '. $offerOne['number']. ' von Kunde K-0001 '. \Crm\Clients::get( $appData, $a['id'] )['company']. ' durch Anna Nord' );
	$body = (string) ( $out[0]['body'] ?? '' );
	check( '...its body links to the document in the surface and gives the reason as text: the markup and the brackets of the reason are written as typed, the line break is a break, the null byte is gone - and nothing of a fill is left', str_contains( $body, 'href="https://example.org/club/crm#beleg-'. $offerOne['id']. '"' ) === true && str_contains( $body, 'Zu teuer &lt;b&gt;fett&lt;/b&gt; ' ) === true && str_contains( $body, '&#91;crm-client]' ) === true
		&& str_contains( $body, '<b>fett</b>' ) === false && str_contains( $body, '[crm-client]' ) === false && str_contains( $body, '[[' ) === false && str_contains( $body, "\0" ) === false && str_contains( $body, '<br>' ) === true && preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', strip_tags( $body ) ) !== 1 );

	// after the decline
	$after = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
	$afterSection = portalSection( $after, 'offer' );
	$done = portalFind( $afterSection, '//p[contains(@class, "crm-order--declined")][@data-document="'. $offerOne['id']. '"]' );
	check( 'the card of a declined offer says when and by whom, with the day as a machine day, and has neither the form of the order nor the fold of the decline - the other offers keep theirs', count( $done ) === 1 && portalFind( $afterSection, '//p[contains(@class, "crm-order--declined")][@data-document="'. $offerOne['id']. '"]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && str_contains( portalText( $afterSection, '//p[contains(@class, "crm-order--declined")][@data-document="'. $offerOne['id']. '"]' ), 'Anna Nord' ) === true
		&& portalFind( $afterSection, '//form[@data-document="'. $offerOne['id']. '"]' ) === [] && portalFind( $afterSection, '//form[contains(@class, "crm-decline__form")][@data-document="'. $offerThree['id']. '"]' ) !== [] && portalFind( $afterSection, '//form[contains(@class, "crm-order--form")][@data-document="'. $offerThree['id']. '"]' ) !== [] );
	check( '...the offer that the owner declined (no record) says nothing, as it did', portalFind( $afterSection, '//p[contains(@class, "crm-order--declined")][@data-document="'. $offerOwner['id']. '"]' ) === [] );
	check( '...the sentence of the state is said at the card the query names, as a status; the state reason is said as an alert', portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&state=declined&beleg='. $offerOne['id'] )[1], '//p[@role="status"][contains(@class, "crm-order__state--declined")]' ) !== [] && portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1. '&state=reason&beleg='. $offerThree['id'] )[1], '//p[@role="alert"][contains(@class, "crm-order__state--reason")]' ) !== []
		&& trim( $wordsDe['[[/project/crm-portal/order/state-declined]]'] ) !== '' && trim( $wordsEn['[[/project/crm-portal/order/state-reason]]'] ) !== '' );

	// a second post: the offer is declined, so it is invalid, and nothing is kept twice
	$sentSoFar = count( $mails );
	$twice = $post( [ 'reason' => 'noch einmal', 'shown' => $hash( $offerOne['id'] ) ] );
	check( 'the same offer declined again is ?state=invalid: no second record, no second step, no mail', $location( $twice ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' && count( $declinesOf( $a['id'] ) ) === 1 && count( $mails ) === $sentSoFar );
	check( '...and a declined offer cannot be ordered either: the order is ?state=invalid, no order record', $location( portalServe( $appData, '/.crm/auftrag', 'POST', [ 'document' => (string) $offerOne['id'], 'from' => 'projekt', 'agree' => '1', 'shown' => 'x' ] ) ) === '/portal/projekte?projekt='. $p1. '&tab=angebot&state=invalid' && \Crm\Records::forDocument( $appData, $offerOne['id'] ) === null );
	$fromList = portalServe( $appData, '/.crm/ablehnen', 'POST', [ 'document' => (string) $offerTwo['id'], 'from' => 'belege', 'shown' => $hash( $offerTwo['id'] ), 'reason' => '' ] );
	check( 'the list of documents declines too: back to the list with the offer named and the anchor of its row; no reason is a decline with an empty reason', $location( $fromList ) === '/portal/belege?state=declined&beleg='. $offerTwo['id']. '#beleg-'. $offerTwo['id'] && $stateOf( $offerTwo['id'] ) === 'declined' && \Crm\Records::reasonOf( (array) \Crm\Records::declineOf( $appData, $offerTwo['id'] ) ) === '' );
	portalSignIn( $appData, $accounts['ben'] );
	$benPost = portalServe( $appData, '/.crm/ablehnen', 'POST', [ 'document' => (string) $offerThree['id'], 'from' => 'belege', 'shown' => $hash( $offerThree['id'] ), 'reason' => '' ] );
	check( '...Ben posting the offer of Anna\'s client is ?state=invalid, nothing kept', $location( $benPost ) === '/portal/belege?state=invalid' && $stateOf( $offerThree['id'] ) !== 'declined' && $declinesOf( $a['id'] ) !== [] && count( $declinesOf( $a['id'] ) ) === 2 );
	portalSignIn( $appData, $accounts['anna'] );

	// the files of the project are free
	check( 'while a released offer of a project can be ordered the project waits for the order and its files stay closed; once the customer has declined it, nothing waits', \Crm\Projekte::waiting( $appData, $pDecline, $a['id'] ) !== null );
	$declineFiles = $post( [ 'document' => (string) $offerFiles['id'], 'shown' => $hash( $offerFiles['id'] ), 'reason' => '' ] );
	check( '...declined: waiting() answers none, in the same process', $location( $declineFiles ) === $to( 'declined', $offerFiles['id'], $pDecline ) && \Crm\Projekte::waiting( $appData, $pDecline, $a['id'] ) === null );

	// Documents::decline(): one transaction
	$offerAtomic = $mkOffer( 'Ablehnen atomar', [ 'project' => $p1 ] );
	$memberOf = [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ];
	Db::exec( $appData, 'CREATE TRIGGER zz_no_decline BEFORE UPDATE OF state ON documents WHEN NEW.state = \'declined\' BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
	$thrown = false;

	try {
		Documents::decline( $appData, $offerAtomic['id'], $a['id'], $memberOf, '', [] );
	}
	catch( \PDOException $e ) {
		$thrown = true;
	}

	Db::exec( $appData, 'DROP TRIGGER zz_no_decline' );
	check( 'the record and the step are one transaction: a step that cannot be taken leaves no record, and the offer as it was', $thrown === true && \Crm\Records::declineOf( $appData, $offerAtomic['id'] ) === null && in_array( $stateOf( $offerAtomic['id'] ), [ 'open', 'sent' ], true ) === true );
	$noAccount = Documents::decline( $appData, $offerAtomic['id'], $a['id'], [ 'id' => '', 'email' => '', 'name' => '' ], '', [] );
	$otherClient = Documents::decline( $appData, $offerAtomic['id'], $b['id'], $memberOf, '', [] );
	$gone = Documents::decline( $appData, 99999, $a['id'], $memberOf, '', [] );
	$long = Documents::decline( $appData, $offerAtomic['id'], $a['id'], $memberOf, str_repeat( 'x', 501 ), [] );
	$unclean = Documents::decline( $appData, $offerAtomic['id'], $a['id'], $memberOf, "a\0b", [] );
	$expired = Documents::decline( $appData, $offerExpired['id'], $a['id'], $memberOf, '', [] );
	$invoiceRefused = Documents::decline( $appData, $invoice['id'], $a['id'], $memberOf, '', [] );
	check( '...an account without an id, an offer of another client or none, a reason that is too long or not as it is kept, an offer that ran out and an invoice are refused with their codes and write nothing', $noAccount === 'member' && $otherClient === 'unknown' && $gone === 'unknown' && $long === 'reason' && $unclean === 'reason' && $expired === 'state' && $invoiceRefused === 'state'
		&& \Crm\Records::declineOf( $appData, $offerAtomic['id'] ) === null && in_array( $stateOf( $offerAtomic['id'] ), [ 'open', 'sent' ], true ) === true );
	$taken = Documents::decline( $appData, $offerAtomic['id'], $a['id'], $memberOf, 'Reicht nicht', [ 'ip' => '203.0.113.5', 'agent' => 'Direct' ] );
	check( '...and the decline made directly answers the document as it is now and the record, and a second one is refused with state', is_array( $taken ) && $taken['document']['state'] === 'declined' && $taken['record']['kind'] === 'decline' && $taken['record']['ip'] === '203.0.113.5' && Documents::decline( $appData, $offerAtomic['id'], $a['id'], $memberOf, '', [] ) === 'state' && count( array_filter( \Crm\Records::of( $appData, $a['id'], 'decline' ), static fn( array $record ): bool => $record['document_id'] === $offerAtomic['id'] ) ) === 1 );

	// the transaction looks at the offer again: one that was ordered since the first look is not declined
	$offerRace = $mkOffer( 'Ablehnen Rennen', [ 'project' => $p1 ] );
	$raceDocument = (array) Documents::get( $appData, $offerRace['id'] );
	Db::exec( $appData, 'UPDATE documents SET released = 0 WHERE id = :id', [ 'id' => $offerRace['id'] ] );
	$inside = ( new \ReflectionMethod( Documents::class, 'declineNow' ) )->invoke( null, $appData, $offerRace['id'], $a['id'], $memberOf, $raceDocument, \Crm\Records::declineShown( $appData, $raceDocument, '' ), [] );
	check( '...an offer that was taken back from the portal between the first look and the transaction is refused inside it with state, and no record is kept', $inside === 'state' && \Crm\Records::declineOf( $appData, $offerRace['id'] ) === null && in_array( $stateOf( $offerRace['id'] ), [ 'open', 'sent' ], true ) === true );

	// the name of the account and the reason are kept to what a header and a line can carry
	check( 'a reason loses the marks that turn a line round and the invisible ones, as a name of a file does: the text that is left is the one a person reads', \Crm\Records::reason( "a\u{202E}b\u{200B}c\u{2066}d\u{2069}e\u{FEFF}f" ) === 'abcdef' );
	$offerSubject = $mkOffer( 'Ablehnen Kopf', [ 'project' => $p1 ] );
	$nameWas = (string) \Nino\Modules\Members::account( $appData, $accounts['anna']['id'] )['name'];
	\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'name' => "Anna\r\nBcc: spy@example.org\u{202E}Nord" ] );
	portalSignIn( $appData, $accounts['anna'] );
	$fromMail = count( $mails );
	$post( [ 'document' => (string) $offerSubject['id'], 'shown' => $hash( $offerSubject['id'] ) ] );
	$subjectMail = $declineMails( $fromMail )[0] ?? [];
	check( 'the name of the account is cleaned in the subject of the mail to the company: no line break, no mark that turns a line round, no second header - and the mail has no Bcc', $subjectMail !== [] && preg_match( '/[\r\n\x{202E}]/u', (string) $subjectMail['subject'] ) !== 1 && str_ends_with( (string) $subjectMail['subject'], 'durch Anna Bcc: spy@example.org Nord' ) === true
		&& array_filter( array_keys( $subjectMail ), static fn( string $key ): bool => stripos( $key, 'bcc' ) !== false ) === [] && str_contains( (string) ( $subjectMail['replyTo'] ?? '' ), "\n" ) === false );
	\Nino\Modules\Members::change( $appData, $accounts['anna']['id'], [ 'name' => $nameWas ] );
	portalSignIn( $appData, $accounts['anna'] );

	// what needs the owner
	$needs = array_column( Documents::needs( $appData ), null, 'kind' );
	check( 'needs() has one line for the offers the customers declined in the last days, with how many and the days - after the lines of the orders and approvals', ( $needs['declined']['count'] ?? 0 ) === count( $declinesOf( $a['id'] ) ) && ( $needs['declined']['days'] ?? 0 ) === ( new \ReflectionClass( Documents::class ) )->getConstant( 'DECLINE_DAYS' ) && ( $needs['declined']['days'] ?? 0 ) === 30 && $needs['declined']['id'] === 0 );
	$kindsInOrder = array_values( array_unique( array_column( Documents::needs( $appData ), 'kind' ) ) );
	check( '...in the order overdue, payments, orders, approved, declined, sign, expiring, account', $kindsInOrder === array_values( array_intersect( [ 'overdue', 'payments', 'orders', 'approved', 'declined', 'sign', 'expiring', 'account' ], $kindsInOrder ) ) && array_search( 'declined', $kindsInOrder, true ) !== false );
	Db::exec( $appData, 'UPDATE records SET at = :at WHERE kind = \'decline\'', [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 31 * 86400 ) ] );
	check( '...a decline of more than 30 days ago is not on it any more, and an offer the owner declined (no record) was never', array_column( Documents::needs( $appData ), 'kind' ) === array_values( array_filter( array_column( Documents::needs( $appData ), 'kind' ), static fn( string $kind ): bool => $kind !== 'declined' ) ) );
	Db::exec( $appData, 'UPDATE records SET at = :at WHERE kind = \'decline\'', [ 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] );

	// the words and the route
	$wordsMail = [ 'decline.owner.subject', 'decline.owner.title', 'decline.owner.intro', 'decline.owner.offer', 'decline.owner.client', 'decline.owner.reason', 'decline.owner.reason-none', 'decline.owner.account', 'decline.owner.button', 'decline.owner.notice' ];
	check( 'every word of the mail is there, in German with Du; the route is registered for the POST alone', array_filter( $wordsMail, static fn( string $key ): bool => trim( \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === '' || preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === 1 ) === []
		&& ( $appData['/nino/http/routes']['POST://.crm/ablehnen']['uri'] ?? '' ) === \Crm\Portal\Order::ROUTE_DECLINE && isset( $appData['/nino/http/routes']['GET://.crm/ablehnen'] ) === false && in_array( 'decline', ( new \ReflectionClass( \Crm\Records::class ) )->getConstant( 'KINDS' ), true ) === true );
	check( '...and the words of the fold are in both languages', array_filter( [ 'decline-fold', 'decline-reason', 'decline-button', 'declined', 'state-declined', 'state-reason', 'question', 'question-subject' ], static fn( string $key ): bool => trim( (string) ( $wordsDe['[[/project/crm-portal/order/'. $key. ']]'] ?? '' ) ) === '' || trim( (string) ( $wordsEn['[[/project/crm-portal/order/'. $key. ']]'] ?? '' ) ) === '' ) === [] );

	\Crm\Settings::set( $appData, 'privacy_text', $privacyWas );
	$asAdmin();
}

// --- Ich habe überwiesen, IBAN kopieren ---------------------------------------------------------------------------------------------------------

/**
 *	The checks of the report that an invoice is paid (POST /.crm/bezahlt, Documents::payment(), the block of the payment) and of what needs() and the list say of it
 */
function portalPaymentChecks( array &$appData, array $a, array $b, array $accounts, array $stray, string $today, \Closure $reset, \Closure $asAdmin, \Closure $asVisitor, \Closure $final, \Closure $line, \Closure $location ): void {

	global $mails;

	echo "\nPOST /.crm/bezahlt and the copy button of the IBAN\n";

	$reset();
	$asAdmin();
	$settingsWere = [ 'bank_name' => \Crm\Settings::get( $appData, 'bank_name' ), 'iban' => \Crm\Settings::get( $appData, 'iban' ), 'bic' => \Crm\Settings::get( $appData, 'bic' ) ];
	\Crm\Settings::set( $appData, 'bank_name', 'Hamburger Sparkasse' );
	\Crm\Settings::set( $appData, 'iban', 'DE02 1203 0000 0000 2020 51' );
	\Crm\Settings::set( $appData, 'bic', 'BYLADEM1001' );
	\Nino\Html::addFills( $appData, [ '[[/project/company/contact/email]]' => 'owner@example.org' ], '*' );
	$invoice = static function( string $title, array $over = [], string $to = 'sent', int $client = 0 ) use ( $final, $line, $a, $today, &$appData ): array {

		$made = $final( 'invoice', $client === 0 ? $a['id'] : $client, $over + [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( $title ) ] ] );
		Documents::release( $appData, $made['id'], false );

		if( $to !== 'open' )
			Documents::setState( $appData, $made['id'], 'sent' );

		if( $to === 'paid' )
			Documents::setState( $appData, $made['id'], 'paid' );

		return $made;
	};
	$sent = $invoice( 'Melden eins' );
	$open = $invoice( 'Melden offen', [], 'open' );
	$late = $invoice( 'Melden spät', [ 'date' => '2026-01-10' ] );
	$paid = $invoice( 'Melden bezahlt', [], 'paid' );
	$unreleased = $final( 'invoice', $a['id'], [ 'date' => $today, 'template_key' => 'rechnung', 'lines' => [ $line( 'Melden unfrei' ) ] ] );
	$ben = $invoice( 'Melden Ben', [], 'sent', $b['id'] );
	$offer = $final( 'offer', $a['id'], [ 'date' => $today, 'template_key' => 'angebot', 'lines' => [ $line( 'Melden Angebot' ) ] ] );
	Documents::release( $appData, $offer['id'], false );
	$paymentsOf = static fn( int $clientId ): array => \Crm\Records::of( $appData, $clientId, 'payment' );
	$stateOf = static fn( int $id ): string => (string) Documents::get( $appData, $id )['state'];
	$paymentMails = static fn( int $from ): array => array_values( array_filter( portalMailsFrom( $from ), static fn( array $mail ): bool => str_starts_with( (string) ( $mail['subject'] ?? '' ), 'Zahlung gemeldet' ) ) );
	$post = static function( array $over = [], array $server = [] ) use ( &$appData, $sent ): array {
		return portalServe( $appData, '/.crm/bezahlt', 'POST', $over + [ 'document' => (string) $sent['id'] ], $server );
	};
	$to = static fn( string $state, int $id ): string => '/portal/belege?state='. $state. '&beleg='. $id. '#beleg-'. $id;
	$row = static function( string $html, int $id ): string {

		$nodes = portalFind( $html, '//li[@data-document="'. $id. '"]' );

		return $nodes === [] ? '' : (string) $nodes[0]->ownerDocument->saveHTML( $nodes[0] );
	};

	// the block: the button that copies the IBAN, and the form
	portalSignIn( $appData, $accounts['anna'] );
	$page = portalPage( $appData, '/portal/belege' )[1];
	$block = $row( $page, $sent['id'] );
	$copy = portalFind( $block, '//button[@data-crm-copy]' );
	check( 'the IBAN has a button that copies it: a button, hidden in the markup so that a page without the script has the IBAN to select and no button; it carries the IBAN as it stands and the two sentences the script says, and a line beside it that is read out when it is filled', count( $copy ) === 1 && $copy[0]->getAttribute( 'type' ) === 'button' && $copy[0]->hasAttribute( 'hidden' ) === true
		&& $copy[0]->getAttribute( 'data-crm-copy' ) === 'DE02120300000000202051' && $copy[0]->getAttribute( 'data-copied' ) === 'Die IBAN ist in der Zwischenablage.' && $copy[0]->getAttribute( 'data-failed' ) !== '' && trim( $copy[0]->textContent ) !== ''
		&& count( portalFind( $block, '//div[contains(@class, "crm-pay__fact--iban")]/dd/span[contains(@class, "crm-pay__value")]' ) ) === 1 && portalText( $block, '//div[contains(@class, "crm-pay__fact--iban")]/dd/span[contains(@class, "crm-pay__value")]' ) === 'DE02 1203 0000 0000 2020 51'
		&& count( portalFind( $block, '//div[contains(@class, "crm-pay__fact--iban")]/dd/span[@role="status"][@aria-live="polite"][contains(@class, "crm-pay__copied")]' ) ) === 1 && trim( portalText( $block, '//span[contains(@class, "crm-pay__copied")]' ) ) === '' );
	Db::exec( $appData, 'UPDATE settings SET value = :v WHERE key = \'iban\'', [ 'v' => 'DE"><b>' ] );
	$odd = portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $sent['id'] ), '//button[@data-crm-copy]' );
	check( '...what the settings hold enters the attribute as text: a quote and a tag in the IBAN neither close it nor open an element', count( $odd ) === 1 && $odd[0]->getAttribute( 'data-crm-copy' ) === 'DE"><b>' && portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $sent['id'] ), '//dd//b' ) === [] );
	\Crm\Settings::set( $appData, 'iban', 'DE02 1203 0000 0000 2020 51' );
	\Crm\Settings::set( $appData, 'iban', '' );
	check( '...no IBAN, no button; the form of the report is there all the same while the settings hold a bank', portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $sent['id'] ), '//button[@data-crm-copy]' ) === [] && portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $sent['id'] ), '//form[contains(@class, "crm-pay-notice")]' ) !== [] );
	\Crm\Settings::set( $appData, 'iban', 'DE02 1203 0000 0000 2020 51' );
	$block = $row( portalPage( $appData, '/portal/belege' )[1], $sent['id'] );
	$form = portalFind( $block, '//form[contains(@class, "crm-pay-notice")]' );
	check( 'under the facts of an open invoice stands the form Ich habe überwiesen: a plain form that posts to /.crm/bezahlt with the token and the invoice, a hint that the invoice stays open, and a button', count( $form ) === 1 && $form[0]->getAttribute( 'action' ) === '/.crm/bezahlt' && $form[0]->getAttribute( 'method' ) === 'post' && $form[0]->getAttribute( 'data-document' ) === (string) $sent['id']
		&& portalFind( $block, '//form[contains(@class, "crm-pay-notice")]//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && portalFind( $block, '//form[contains(@class, "crm-pay-notice")]//input[@name="document"]/@value' )[0]->nodeValue === (string) $sent['id']
		&& portalFind( $block, '//form[contains(@class, "crm-pay-notice")]//button[@type="submit"]' ) !== [] && portalFind( $block, '//form[contains(@class, "crm-pay-notice")]//p[contains(@class, "crm-pay-notice__hint")]' ) !== [] && portalFind( $block, '//dl[contains(@class, "crm-pay")]/following-sibling::form[contains(@class, "crm-pay-notice")]' ) !== [] );
	check( '...an invoice past its day has it, an invoice that is not paid and not sent too; a paid one, an offer and an invoice that is not released have neither the block nor the form', portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $late['id'] ), '//form[contains(@class, "crm-pay-notice")]' ) !== [] && portalFind( $row( portalPage( $appData, '/portal/belege' )[1], $open['id'] ), '//form[contains(@class, "crm-pay-notice")]' ) !== []
		&& portalFind( portalPage( $appData, '/portal/belege' )[1], '//li[@data-document="'. $paid['id']. '"]//form[contains(@class, "crm-pay-notice")] | //li[@data-document="'. $offer['id']. '"]//form[contains(@class, "crm-pay-notice")] | //li[@data-document="'. $unreleased['id']. '"]//form[contains(@class, "crm-pay-notice")]' ) === [] );

	// the post that is refused
	$asVisitor();
	check( 'a visitor posting the form is answered 404 and nothing is kept', $post()['statusCode'] === 404 && $paymentsOf( $a['id'] ) === [] );
	portalSignIn( $appData, $stray );
	check( '...so is a member of no client', $post()['statusCode'] === 404 && $paymentsOf( $a['id'] ) === [] );
	portalSignIn( $appData, $accounts['anna'] );
	$beforeMails = count( $mails );
	check( '...a token that is not the session\'s is ?state=expired on the list with the invoice named', $location( $post( [ '_csrf' => 'wrong' ] ) ) === $to( 'expired', $sent['id'] ) && $paymentsOf( $a['id'] ) === [] );
	check( '...an invoice of another client, one that is not there, an id that is none, an array, an invoice that is not released, an invoice that is paid and an offer are ?state=invalid, no document named', array_filter( [ $ben['id'], 99999, '1; DROP', [ 'x' ], $unreleased['id'], $paid['id'], $offer['id'] ], static fn( mixed $id ): bool => $location( $post( [ 'document' => is_int( $id ) === true ? (string) $id : $id ] ) ) !== '/portal/belege?state=invalid' ) === []
		&& $paymentsOf( $a['id'] ) === [] && $paymentsOf( $b['id'] ) === [] && $stateOf( $sent['id'] ) === 'sent' && count( portalMailsFrom( $beforeMails ) ) === 0 );

	// the report
	$answer = $post( [], [ 'HTTP_USER_AGENT' => 'Browser/1.0 (Pay)' ] );
	$records = $paymentsOf( $a['id'] );
	$shown = (array) json_decode( $records[0]['shown'] ?? '', true );
	check( 'the report: ?state=paid-notice on the list with the invoice named and one record of kind payment kept', $answer['statusCode'] === 303 && $location( $answer ) === $to( 'paid-notice', $sent['id'] ) && count( $records ) === 1 && $records[0]['kind'] === 'payment' );
	check( '...it names the invoice, the account as it was, the client, the time, the address and the browser, and keeps no project and no purpose; the text is the JSON of the number, the sum, the currency and the day, with its hash', $records[0]['document_id'] === $sent['id'] && $records[0]['member_id'] === $accounts['anna']['id'] && $records[0]['member_name'] === 'Anna Nord' && $records[0]['client_id'] === $a['id'] && $records[0]['project'] === '' && $records[0]['purpose'] === '' && $records[0]['agent'] === 'Browser/1.0 (Pay)'
		&& $records[0]['sha256'] === hash( 'sha256', $records[0]['shown'] ) && $shown === [ 'document' => $sent['id'], 'number' => $sent['number'], 'gross' => 10000, 'currency' => \Crm\Settings::get( $appData, 'currency' ), 'date' => gmdate( 'Y-m-d' ) ] && \Crm\Records::paymentOf( $appData, $sent['id'] ) === $records[0] );
	check( '...the state of the invoice is not touched - the owner marks it as paid - and it has no day of payment', $stateOf( $sent['id'] ) === 'sent' && Documents::get( $appData, $sent['id'] )['paid_at'] === null );
	$out = $paymentMails( $beforeMails );
	$sum = \Crm\Documents\Money::format( 10000, \Crm\Settings::get( $appData, 'currency' ) );
	check( 'one mail goes out, to the owner alone: "Zahlung gemeldet: Rechnung <number> <sum> von Kunde <number and company> durch <account>", the reply to the account; none to the customer', count( $out ) === 1 && count( portalMailsFrom( $beforeMails ) ) === 1 && ( $out[0]['to'] ?? '' ) === 'owner@example.org' && ( $out[0]['replyTo'] ?? '' ) === 'anna@example.org'
		&& ( $out[0]['subject'] ?? '' ) === 'Zahlung gemeldet: Rechnung '. $sent['number']. ' '. $sum. ' von Kunde K-0001 '. \Crm\Clients::get( $appData, $a['id'] )['company']. ' durch Anna Nord' );
	check( '...its body gives the invoice, the sum and the account and links to the document in the surface; every value is text and nothing of a fill is left', str_contains( (string) $out[0]['body'], 'href="https://example.org/club/crm#beleg-'. $sent['id']. '"' ) === true && str_contains( $out[0]['body'], $sent['number'] ) === true && str_contains( $out[0]['body'], 'anna@example.org' ) === true && str_contains( $out[0]['body'], '[[' ) === false
		&& str_contains( strip_tags( $out[0]['body'] ), $sum ) === true && preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', strip_tags( $out[0]['body'] ) ) !== 1 );
	$list = portalPage( $appData, '/portal/belege?state=paid-notice&beleg='. $sent['id'] )[1];
	$done = portalFind( $row( $list, $sent['id'] ), '//p[contains(@class, "crm-pay-notice--done")]' );
	check( 'the row of the invoice says when the payment was reported, with the day as a machine day, instead of the form; the row is open and the state of the query is said in it', count( $done ) === 1 && portalFind( $row( $list, $sent['id'] ), '//p[contains(@class, "crm-pay-notice--done")]//time/@datetime' )[0]->nodeValue === gmdate( 'Y-m-d' ) && portalFind( $row( $list, $sent['id'] ), '//form[contains(@class, "crm-pay-notice")]' ) === []
		&& portalFind( $row( $list, $sent['id'] ), '//details/@open' ) !== [] && portalFind( $row( $list, $sent['id'] ), '//p[@role="status"][contains(@class, "crm-order__state--paid-notice")]' ) !== [] && portalFind( $row( $list, $open['id'] ), '//form[contains(@class, "crm-pay-notice")]' ) !== [] );

	// once for each invoice
	$sentSoFar = count( $mails );
	check( 'a second post of the invoice is ?state=invalid: no second record, no second mail', $location( $post() ) === '/portal/belege?state=invalid' && count( $paymentsOf( $a['id'] ) ) === 1 && count( $mails ) === $sentSoFar );
	$memberOf = [ 'id' => $accounts['anna']['id'], 'email' => 'anna@example.org', 'name' => 'Anna Nord' ];
	check( '...Documents::payment() says again for it, unknown for another client\'s invoice, a missing one and an account without an id, state for a paid invoice and an offer - and writes nothing', Documents::payment( $appData, $sent['id'], $a['id'], $memberOf, [] ) === 'again' && Documents::payment( $appData, $ben['id'], $a['id'], $memberOf, [] ) === 'unknown' && Documents::payment( $appData, 99999, $a['id'], $memberOf, [] ) === 'unknown'
		&& Documents::payment( $appData, $open['id'], $a['id'], [ 'id' => '', 'email' => '', 'name' => '' ], [] ) === 'member' && Documents::payment( $appData, $paid['id'], $a['id'], $memberOf, [] ) === 'state' && Documents::payment( $appData, $offer['id'], $a['id'], $memberOf, [] ) === 'state' && Documents::payment( $appData, $unreleased['id'], $a['id'], $memberOf, [] ) === 'state' && count( $paymentsOf( $a['id'] ) ) === 1 );
	Db::exec( $appData, 'CREATE TRIGGER zz_no_payment BEFORE INSERT ON records WHEN NEW.kind = \'payment\' BEGIN SELECT RAISE( ABORT, \'no\' ); END' );
	$thrown = false;

	try {
		Documents::payment( $appData, $open['id'], $a['id'], $memberOf, [] );
	}
	catch( \PDOException $e ) {
		$thrown = true;
	}

	Db::exec( $appData, 'DROP TRIGGER zz_no_payment' );
	check( '...a record that cannot be written leaves the exception and no half report', $thrown === true && \Crm\Records::paymentOf( $appData, $open['id'] ) === null );
	$overdue = $post( [ 'document' => (string) $late['id'] ] );
	check( 'an invoice past its day is reported all the same, and an invoice that was never sent (open) too', $location( $overdue ) === $to( 'paid-notice', $late['id'] ) && $location( $post( [ 'document' => (string) $open['id'] ] ) ) === $to( 'paid-notice', $open['id'] ) && count( $paymentsOf( $a['id'] ) ) === 3 && $stateOf( $late['id'] ) === 'sent' && $stateOf( $open['id'] ) === 'open' );

	// what needs the owner, and the list of the owner
	$needs = array_column( Documents::needs( $appData ), null, 'kind' );
	check( 'needs() has one line for the invoices that were reported and are not marked as paid: how many and what they come to', ( $needs['payments']['count'] ?? 0 ) === 3 && ( $needs['payments']['gross'] ?? 0 ) === 30000 && $needs['payments']['id'] === 0 );
	Documents::setState( $appData, $sent['id'], 'paid' );
	$needs = array_column( Documents::needs( $appData ), null, 'kind' );
	check( '...an invoice the owner has marked as paid is not on it any more', ( $needs['payments']['count'] ?? 0 ) === 2 && ( $needs['payments']['gross'] ?? 0 ) === 20000 );
	$listed = array_column( Documents::list( $appData ), 'paid_notice', 'id' );
	check( '...the list of the documents carries the day of the report for each invoice and an empty text for the others', $listed[$late['id']] === gmdate( 'Y-m-d' ) && $listed[$open['id']] === gmdate( 'Y-m-d' ) && $listed[$ben['id']] === '' && $listed[$offer['id']] === '' );
	$kindsInOrder = array_values( array_unique( array_column( Documents::needs( $appData ), 'kind' ) ) );
	check( '...and the line stands after the overdue invoices and before the orders', array_search( 'payments', $kindsInOrder, true ) !== false && ( array_search( 'overdue', $kindsInOrder, true ) === false || array_search( 'overdue', $kindsInOrder, true ) < array_search( 'payments', $kindsInOrder, true ) ) && ( array_search( 'orders', $kindsInOrder, true ) === false || array_search( 'payments', $kindsInOrder, true ) < array_search( 'orders', $kindsInOrder, true ) ) );
	$wordsOfMail = [ 'payment.owner.subject', 'payment.owner.title', 'payment.owner.intro', 'payment.owner.invoice', 'payment.owner.client', 'payment.owner.sum', 'payment.owner.account', 'payment.owner.button', 'payment.owner.notice' ];
	check( '...every word of the mail is there, in German with Du; the route is registered for the POST alone', array_filter( $wordsOfMail, static fn( string $key ): bool => trim( \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === '' || preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', \Crm\Documents\Mail::text( $key, 'x', 'x', 'x', 'x' ) ) === 1 ) === []
		&& ( $appData['/nino/http/routes']['POST://.crm/bezahlt']['uri'] ?? '' ) === \Crm\Portal\Payment::ROUTE && isset( $appData['/nino/http/routes']['GET://.crm/bezahlt'] ) === false && in_array( 'payment', ( new \ReflectionClass( \Crm\Records::class ) )->getConstant( 'KINDS' ), true ) === true );

	foreach( $settingsWere as $key => $value )
		\Crm\Settings::set( $appData, $key, $value );

	$asAdmin();
}


portalConsentChecks( $appData, $a, $b, $accounts, $stray, $p1, $today, $wordsDe, $wordsEn, $reset, $asAdmin, $asVisitor, $made, $location, $mkOffer );
portalDeclineChecks( $appData, $a, $b, $accounts, $stray, $p1, $today, $wordsDe, $wordsEn, $reset, $asAdmin, $asVisitor, $final, $line, $made, $location, $mkOffer );
portalPaymentChecks( $appData, $a, $b, $accounts, $stray, $today, $reset, $asAdmin, $asVisitor, $final, $line, $location );
portalHomeChecks( $appData, $a, $accounts, $stray, (string) $p1, $asVisitor, $asAdmin, $reset );

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

// --- Der Player als Fragment: GET /.crm/player ------------------------------------------------------------------------------

/**
 *	The markup of a fragment or of the aside of a page the way a parser keeps it: re-serialised, so that the two are compared by what they hold and not by the white space of a template; the number a request gives the form of the comment is left out, as it counts the forms of the request
 *
 *	@param		string		$html
 *
 *	@return 	string
 */
function portalCanon( string $html ): string {

	$body = portalFind( $html, '//body' );

	return $body === [] ? '' : trim( (string) preg_replace( [ '/>\s+</', '/review-form-\d+/' ], [ '><', 'review-form-n' ], implode( '', array_map( static fn( \DOMNode $node ): string => $node->ownerDocument->saveHTML( $node ), iterator_to_array( $body[0]->childNodes ) ) ) ) );
}

/**
 *	What the column beside the list of a page holds, canonical
 *
 *	@param		string		$page
 *
 *	@return 	string										'' for no column
 */
function portalAsideOf( string $page ): string {

	$aside = portalFind( $page, '//aside[contains(concat(" ", normalize-space(@class), " "), " crm-player ")]' );

	return $aside === [] ? '' : portalCanon( implode( '', array_map( static fn( \DOMNode $node ): string => $node->ownerDocument->saveHTML( $node ), iterator_to_array( $aside[0]->childNodes ) ) ) );
}

/**
 *	The route of the player as a member asks it
 *
 *	@param		array 		&$appData
 *	@param		string		$query				The query string after the path, with its ?
 *
 *	@return 	array										The response
 */
function portalPlayerAsk( array &$appData, string $query ): array {

	return portalServe( $appData, '/.crm/player'. $query );
}

/**
 *	The checks of the route /.crm/player: who is answered, what the answer holds and that it is the player of the page
 */
function portalPlayerChecks( array &$appData, array $a, array $b, array $accounts, array $stray, array $owner, string $store, string $today, \Closure $hold, \Closure $reset, \Closure $asAdmin, \Closure $asVisitor, \Closure $put, \Closure $made, \Closure $final, \Closure $line, \Closure $ago ): void {

	echo "\nThe player as a fragment: GET /.crm/player\n";

	$hold();
	$reset();
	$asAdmin();
	$byAnna = [ 'kind' => 'member', 'id' => $accounts['anna']['id'] ];
	$folder = $store::addFolder( $appData, $a['group'], 'Player-Fragment' );
	$one = $put( $folder, 'Lied.01.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 5000 ) );
	$two = $put( $folder, 'Lied.02.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 4000 ) );
	$clip = $put( $folder, 'Film.mp4', 'mp4', 'video/mp4', true, $owner, $ago( 3000 ) );
	$pdf = $put( $folder, 'Plan.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 2000 ) );
	$hidden = $put( $folder, 'Geheim.mp3', 'mp3', 'audio/mpeg', false, $owner, $ago( 1000 ) );
	$gone = $put( $folder, 'Weg.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 900 ) );
	$projectA = $made( 'Fragment', $a['number'], [ 'folder' => $folder['id'] ], false );
	$strayFolder = $store::addFolder( $appData, $a['group'], 'Ohne Projekt' );
	$loose = $put( $strayFolder, 'Lose.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 800 ) );
	$folderB = $store::addFolder( $appData, $b['group'], 'Fragment-B' );
	$ofB = $put( $folderB, 'Fremd.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 700 ) );
	$made( 'Fragment B', $b['number'], [ 'folder' => $folderB['id'] ], false );
	$folderW = $store::addFolder( $appData, $a['group'], 'Fragment-wartet' );
	$waits = $put( $folderW, 'Wartet.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 600 ) );
	$waitsHidden = $put( $folderW, 'Wartet-geheim.mp3', 'mp3', 'audio/mpeg', false, $owner, $ago( 590 ) );
	$waitsPdf = $put( $folderW, 'Wartet.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 580 ) );
	$waitsOwn = $put( $folderW, 'Wartet-eigen.mp3', 'mp3', 'audio/mpeg', false, $byAnna, $ago( 585 ) );
	$waitsCarl = $put( $folderW, 'Wartet-carl.mp3', 'mp3', 'audio/mpeg', false, [ 'kind' => 'member', 'id' => $accounts['carl']['id'] ], $ago( 584 ) );
	$folderX = $store::addFolder( $appData, $b['group'], 'Fremde Gruppe' );
	$foreign = $put( $folderX, 'Fremd-gruppe.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 583 ) );
	$projectX = $made( 'Fragment fremde Gruppe', $a['number'], [ 'folder' => $folderX['id'] ], false );
	$offerX = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => '2099-12-31', 'project' => $projectX, 'template_key' => 'angebot', 'lines' => [ $line( 'Auftrag X' ) ] ] );
	Documents::release( $appData, $offerX['id'], false );
	$lost = $put( $folder, 'Verloren.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 570 ) );
	@unlink( (string) \Nino\Modules\Files\Store::bytes( $appData, $lost['id'], 'mp3' ) );
	$projectW = $made( 'Fragment wartet', $a['number'], [ 'folder' => $folderW['id'] ], false );
	$offer = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => '2099-12-31', 'project' => $projectW, 'template_key' => 'angebot', 'lines' => [ $line( 'Auftrag' ) ] ] );
	Documents::release( $appData, $offer['id'], false );
	$injected = \Nino\Modules\Review::add( $appData, $two['id'], $byAnna, 7.5, "<script>alert(1)</script> [[/nino/dir]] [crm-projects] \"x\" onload=\"y" );
	\Nino\Modules\Review::add( $appData, $gone['id'], $byAnna, 3.0, 'Kommentar zur entfernten Datei' );
	\Nino\Modules\Files\Store::removeBytes( $appData, $gone['id'] );
	portalSignIn( $appData, $accounts['anna'] );

	$ask = static function( string $file ) use ( &$appData ): array {
		return portalPlayerAsk( $appData, '?file='. $file );
	};
	$token = static fn( string $name ): string => 'contains(concat(" ", normalize-space(@class), " "), " '. $name. ' ")';
	$page = static function( string $file ) use ( &$appData, $projectA ): string {
		return portalPage( $appData, '/portal/projekte?projekt='. $projectA. '&review='. $file )[1];
	};
	$answer = $ask( $two['id'] );

	$body = (string) ( $answer['body'] ?? '' );

	check( 'the route is registered for the GET alone, and a member of the client is answered 200 with the markup of the player', ( $appData['/nino/http/routes']['GET://.crm/player']['uri'] ?? '' ) === \Crm\Portal\Projects::ROUTE && isset( $appData['/nino/http/routes']['POST://.crm/player'] ) === false
		&& $answer['statusCode'] === 200 && portalFind( $body, '//div['. $token( 'review-player' ). '][@data-review-file="'. $two['id']. '"]//audio' ) !== [] );
	check( '...as html in utf-8 that is never kept and never sniffed', $answer['header']['Content-Type'] === 'text/html; charset=utf-8' && $answer['header']['Cache-Control'] === 'no-store' && $answer['header']['X-Content-Type-Options'] === 'nosniff' );
	check( '...the markup alone: no frame of a page, no script, no head, no word left unresolved', portalFind( $body, '//body//html | //body//head | //script | //body//body | //*[@onload] | //*[@onclick]' ) === [] && stripos( $body, '<script' ) === false && portalLeft( $body ) === false && str_contains( $body, '[[' ) === false );
	check( '...it is what the page puts beside the list for that file, byte for byte once parsed: the versions over the player, the player with its comments, the form', portalCanon( $body ) === portalAsideOf( $page( $two['id'] ) ) && portalCanon( $body ) !== '' && portalFind( $body, '//form['. $token( 'crm-versions' ). ']//option' ) !== [] );
	check( '...the form of the comment returns to the page of the projects, as it does on the page, and the player stands for the file asked', portalFind( $body, '//form['. $token( 'review-form' ). ']//input[@name="return"]/@value' )[0]->nodeValue === '/portal/projekte' && portalFind( $body, '//form['. $token( 'review-form' ). ']//input[@name="file"]/@value' )[0]->nodeValue === $two['id']
		&& count( portalFind( $body, '//form['. $token( 'crm-versions' ). ']//option[@selected]/@value' ) ) === 1 && portalFind( $body, '//form['. $token( 'crm-versions' ). ']//option[@selected]/@value' )[0]->nodeValue === $two['id'] );
	check( '...the other version is the player of that version, and a video is a video', portalCanon( $ask( $one['id'] )['body'] ) === portalAsideOf( $page( $one['id'] ) ) && portalFind( $ask( $clip['id'] )['body'], '//div['. $token( 'review-player' ). ']//video' ) !== [] && portalCanon( $ask( $clip['id'] )['body'] ) === portalAsideOf( $page( $clip['id'] ) ) );
	check( '...a comment is text: the markup, the brackets and the quotes of it are escaped as they are on the page', portalFind( $body, '//script' ) === [] && str_contains( $body, '&lt;script&gt;' ) === true && str_contains( $body, '&#91;crm-projects]' ) === true && is_array( $injected ) === true );
	$removed = $ask( $gone['id'] );
	check( 'a file that was removed from the server is answered with the sentence over its comments and no medium, no form, as on the page', $removed['statusCode'] === 200 && portalFind( $removed['body'], '//audio | //video | //form['. $token( 'review-form' ). ']' ) === [] && portalFind( $removed['body'], '//p['. $token( 'crm-files__gone-note' ). ']' ) !== [] && portalCanon( $removed['body'] ) === portalAsideOf( $page( $gone['id'] ) ) );

	// what the script needs of the page: the address of the route on the column, with and without a player, and the pill of a row that plays in a template of the page
	$empty = portalPage( $appData, '/portal/projekte?projekt='. $projectA. '&tab=dateien' )[1];
	$playingPage = $page( $two['id'] );
	$column = static fn( string $html ): array => portalFind( $html, '//aside['. $token( 'crm-player' ). ']/@data-crm-player' );
	$pill = static fn( string $html ): string => portalCanon( implode( '', array_map( static fn( \DOMNode $node ): string => $node->ownerDocument->saveHTML( $node ), portalFind( $html, '//li[@data-files-id="'. $two['id']. '"]//span['. $token( 'crm-files__playing' ). ']' ) ) ) );
	$kept = portalFind( $playingPage, '//template[@id="crm-files-playing"]' );
	check( 'the column of the player names the route that answers it, with a player in it and empty, so that the script finds it where no file is chosen yet', count( $column( $empty ) ) === 1 && $column( $empty )[0]->nodeValue === \Crm\Portal\Projects::ROUTE && count( $column( $playingPage ) ) === 1 && $column( $playingPage )[0]->nodeValue === \Crm\Portal\Projects::ROUTE );
	check( '...and the page holds the pill of the row that plays in a template, outside the list and the same as the row draws it, in either state of the column: the script clones it and writes no word', count( $kept ) === 1 && $pill( $playingPage ) !== '' && portalCanon( implode( '', array_map( static fn( \DOMNode $node ): string => $node->ownerDocument->saveHTML( $node ), iterator_to_array( $kept[0]->childNodes ) ) ) ) === $pill( $playingPage )
		&& portalFind( $playingPage, '//ul['. $token( 'crm-files__list' ). ']//template' ) === [] && count( portalFind( $empty, '//template[@id="crm-files-playing"]' ) ) === 1 );

	$both = portalPlayerAsk( $appData, '?file='. $one['id']. '&review='. $two['id'] );
	check( 'the file the route is asked for wins over a ?review= in the same query', $both['statusCode'] === 200 && portalFind( $both['body'], '//div['. $token( 'review-player' ). ']/@data-review-file' )[0]->nodeValue === $one['id'] );
	check( 'a file whose bytes are gone from the disk and that is not marked as removed has no player: the 404', $ask( $lost['id'] )['statusCode'] === 404 );

	// what the route itself puts on its answers, whatever a layer around it adds: asked as the output asks it, with no other layer in between
	$direct = static function( string $file ) use ( &$appData ): array {

		$request = [ '/nino/http/request' => [ 'query' => [ 'file' => $file ] ], '/nino/http/response' => [ 'header' => [] ] ];
		\Crm\Portal\Projects::callbackPlayer( $appData, $request );

		return $request['/nino/http/response'];
	};
	$given = $direct( $two['id'] );
	$turned = $direct( '0123456789ab' );
	check( 'the route sets its headers itself, on the answer and on the refusal alike: never kept, never sniffed, html in utf-8 for the player and plain text for the refusal', $given['statusCode'] === 200 && $given['header'] === [ 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/html; charset=utf-8' ]
		&& $turned['statusCode'] === 404 && $turned['body'] === 'Not Found' && $turned['header'] === [ 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/plain; charset=utf-8' ] );

	// who is turned away
	$refused = [
		'no file'                    => $ask( '' ), 'a PDF'                         => $ask( $pdf['id'] ), 'a file the member may not see' => $ask( $hidden['id'] ), 'a file of another client' => $ask( $ofB['id'] ),
		'a file of a folder of no project' => $ask( $loose['id'] ), 'an id nobody has' => $ask( '0123456789ab' ), 'a path' => $ask( '..%2F..%2Fdata%2Fconfig' ), 'a path with dots' => $ask( '../'. $two['id'] ),
		'a null byte'                => $ask( $two['id']. '%00' ), 'a null byte inside' => $ask( substr( $two['id'], 0, 6 ). '%00'. substr( $two['id'], 6 ) ), 'a list' => portalPlayerAsk( $appData, '?file[]='. $two['id'] ), 'a keyed list' => portalPlayerAsk( $appData, '?file[a]='. $two['id'] ),
		'a long id'                  => $ask( $two['id']. 'a' ), 'upper case' => $ask( strtoupper( $two['id'] ) !== $two['id'] ? strtoupper( $two['id'] ) : 'A'. substr( $two['id'], 1 ) ), 'a blank' => $ask( '%20'. $two['id'] ), 'a new line behind' => $ask( $two['id']. '%0A' ), 'the query of the page' => portalPlayerAsk( $appData, '?review='. $two['id'] ),
		'no query'                   => portalServe( $appData, '/.crm/player' ), 'a post' => portalServe( $appData, '/.crm/player?file='. $two['id'], 'POST' ),
	];
	check( 'a post to the route is no route', $refused['a post']['statusCode'] === 404 );
	unset( $refused['a post'] );
	check( 'a PDF, a file the member may not see, a file of another client or of a folder of no project, an id nobody has, a path, a null byte, a list, an id that is not twelve of 0-9a-f, an empty query and a post are the 404 with a plain body', array_filter( $refused, static fn( array $response ): bool => $response['statusCode'] === 200 || ( $response['body'] ?? '' ) !== 'Not Found' ) === [] );
	check( '...and a refusal is never kept or sniffed either, and is plain text', array_filter( $refused, static fn( array $response ): bool => ( $response['header']['Cache-Control'] ?? '' ) !== 'no-store' || ( $response['header']['X-Content-Type-Options'] ?? '' ) !== 'nosniff' || ( $response['header']['Content-Type'] ?? '' ) !== 'text/plain; charset=utf-8' ) === [] );

	$asVisitor();
	$visitor = $ask( $two['id'] );
	check( 'a visitor is turned away, and what the visitor learns is the same as for an id nobody has', $visitor['statusCode'] === 404 && ( $visitor['body'] ?? '' ) === 'Not Found' && $visitor['header'] === $refused['an id nobody has']['header'] );
	portalSignIn( $appData, $stray );
	check( 'a member of no client is turned away', $ask( $two['id'] )['statusCode'] === 404 && ( $ask( $two['id'] )['body'] ?? '' ) === 'Not Found' );
	portalSignIn( $appData, $accounts['ben'] );
	check( 'a member of another client is turned away for the files of this one and is served the player of his own', $ask( $two['id'] )['statusCode'] === 404 && $ask( $ofB['id'] )['statusCode'] === 200 && portalFind( $ask( $ofB['id'] )['body'], '//div[@data-review-file="'. $ofB['id']. '"]' ) !== [] );
	portalSignIn( $appData, $accounts['anna'] );

	// the gate and the offer that waits
	\Crm\Settings::set( $appData, 'privacy_text', 'Text zur Prüfung des Players.' );
	check( 'while a step of the gate is pending the player is not given - nor the note of an offer that waits, which would tell the project of a file to somebody who has not taken note of the Datenschutz', $ask( $two['id'] )['statusCode'] === 404 && ( $ask( $two['id'] )['body'] ?? '' ) === 'Not Found' && $ask( $waits['id'] )['statusCode'] === 404 );
	\Crm\Settings::set( $appData, 'privacy_text', '' );
	check( '...and is given again when the step is done', $ask( $two['id'] )['statusCode'] === 200 );
	$waiting = $ask( $waits['id'] );
	check( 'while an offer of the project waits for the order the answer is the note that leads to the offer, in the place of the player: no medium, no comments, no form', $waiting['statusCode'] === 200 && portalFind( $waiting['body'], '//p['. $token( 'crm-project__waiting' ). ']//a/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $projectW. '&tab=angebot#angebot-'. $offer['id'] && portalFind( $waiting['body'], '//audio | //video | //form | //div['. $token( 'review-player' ). ']' ) === []
		&& portalCanon( $waiting['body'] ) === portalCanon( implode( '', array_map( static fn( \DOMNode $node ): string => $node->ownerDocument->saveHTML( $node ), portalFind( portalPage( $appData, '/portal/projekte?projekt='. $projectW. '&review='. $waits['id'] )[1], '//section[@id="projekt-files"]/p['. $token( 'crm-project__waiting' ). ']' ) ) ) ) );
	check( '...but only in the place of the player of a medium the member would see: a file that is hidden from the member and a PDF of that project are the 404, and so is one the member may see that is no medium - the note tells nothing about what the folder holds', $ask( $waitsHidden['id'] )['statusCode'] === 404 && $ask( $waitsPdf['id'] )['statusCode'] === 404 );
	check( '...a hidden upload of the member itself is a file the member sees, so it has the note; the hidden upload of another member of the client is the 404', $ask( $waitsOwn['id'] )['statusCode'] === 200 && $ask( $waitsCarl['id'] )['statusCode'] === 404 );
	check( '...and a project whose folder is one of another group has no note for the files in it, whatever they are: the rule of the group is the rule of Files', $ask( $foreign['id'] )['statusCode'] === 404 );
	check( '...and for a file that is none of the project the note is not given either', $ask( $ofB['id'] )['statusCode'] === 404 );
	$off = [ \Nino\Features::deactivate( $appData, 'events' ), \Nino\Features::deactivate( $appData, 'review' ) ];
		check( 'with Review off there is no player to give - and no note in its place', $off === [ true, true ] && $ask( $two['id'] )['statusCode'] === 404 && $ask( $waits['id'] )['statusCode'] === 404 );
	\Nino\Features::activate( $appData, 'review' );
	\Nino\Features::activate( $appData, 'events' );
	$off = [ \Nino\Features::deactivate( $appData, 'events' ), \Nino\Features::deactivate( $appData, 'review' ), \Nino\Features::deactivate( $appData, 'files' ) ];
	check( '...and with Files off', $off === [ true, true, true ] && $ask( $two['id'] )['statusCode'] === 404 );
	foreach( [ 'files', 'review', 'events' ] as $key )
		\Nino\Features::activate( $appData, $key );


	// one function for the page and the route
	$project = (array) \Crm\Projekte::get( $appData, $projectA );
	$client = (array) \Crm\Portal::client( $appData );
	$files = \Crm\Portal\Files::visible( $appData, $folder['id'] );
	$direct = \Crm\Portal\Projects::player( $appData, $client, $project, $two['id'], $files );
	check( 'Projects::player() draws the player for the page and for the route alike; for a file that is none of the list it draws nothing', $direct !== '' && portalFind( $direct, '//div['. $token( 'review-player' ). '][@data-review-file="'. $two['id']. '"]' ) !== [] && \Crm\Portal\Projects::player( $appData, $client, $project, $pdf['id'], $files ) === '' && \Crm\Portal\Projects::player( $appData, $client, $project, $ofB['id'], $files ) === '' && \Crm\Portal\Projects::player( $appData, $client, $project, '', $files ) === '' );

	// the player of Review takes the file and the page it returns to from its caller
	ninoVisit( $appData, '/portal/projekte' );
	$given = \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'file' => $two['id'], 'path' => '/portal/projekte' ] );
	$plain = \Nino\Modules\Review::componentReviewPlayer( $appData, [] );
	check( 'Review draws the player of the file a caller names when the request names none, and the list where nothing is named', portalFind( $given, '//div['. $token( 'review-player' ). '][@data-review-file="'. $two['id']. '"]//input[@name="return"]/@value' )[0]->nodeValue === '/portal/projekte' && portalFind( $plain, '//*[@data-review-file]' ) === [] && \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'file' => $pdf['id'] ] ) === \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'file' => '0123456789ab' ] ) );
	$asVisitor();
	check( '...and a visitor gets nothing, however the caller names it', \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'file' => $two['id'] ] ) === '' );
}

// --- Die Home-Screen-App: Manifest, Icon, Kopf, Hinweis, Alle Geräte abmelden ------------------------------------------------------------

/**
 *	A request as the site answers it: routed, the callbacks run, the fills of the request put in and the body rendered once more - which is what a body that names a fill or a shortcode meets
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *
 *	@return 	array										The request, response and all
 */
function portalAsServed( array &$appData, string $uri ): array {

	unset( $appData['./crm/portal/bar'] );
	$request = ninoVisit( $appData, $uri );
	\Nino\Http::response( $appData, $request );
	\Nino\Html::addFills( $appData, \Nino\Html::requestFills( $request, '' ), '*' );
	\Nino\Html::response( $appData, $request );

	return $request;
}

/**
 *	A page as the output sends it: served, rendered, and the last hook run that holds the bytes about to be sent
 *
 *	@param		array 		&$appData
 *	@param		string		$uri
 *
 *	@return 	array										[ status, the document of the page, the html ]
 */
function portalSent( array &$appData, string $uri ): array {

	$request = portalAsServed( $appData, $uri );
	\Nino\Callbacks::doCallbacks( $appData, '/nino/http/output', $request );
	$html = (string) ( $request['/nino/http/response']['body'] ?? '' );
	$document = new DOMDocument();
	$was = libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="utf-8"?>'. $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $was );

	return [ $request['/nino/http/response']['statusCode'], new DOMXPath( $document ), $html ];
}

/**
 *	The checks of the portal as an app on the home screen, and of the button that signs the account out of every device
 *
 *	@param		array 		&$appData
 *	@param		array 		$a						The client with the accounts
 *	@param		array 		$accounts			The accounts by name
 *	@param		array 		$stray				A member of no client
 *	@param		string		$project			A project of the client
 *	@param		\Closure	$asVisitor
 *	@param		\Closure	$asAdmin
 *	@param		\Closure	$reset
 *
 *	@return 	void
 */
function portalHomeChecks( array &$appData, array $a, array $accounts, array $stray, string $project, \Closure $asVisitor, \Closure $asAdmin, \Closure $reset ): void {

	echo "\nHome-Screen: Manifest, Icon, Kopf, Hinweis\n";

	$nameWas = \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ) ?? '';
	$name = static fn( string $text ) => \Nino\Html::addFills( $GLOBALS['appData'], [ '[[/project/company/general/name]]' => $text ], '*' );
	$theme = \Nino\Filesystem::path( $appData, '/assets/theme.css' );
	$themeWas = is_file( $theme ) === true ? (string) file_get_contents( $theme ) : null;
	$setTheme = static function( ?string $css ) use ( $theme ): void {
		if( $css === null )
			@unlink( $theme );
		else
			file_put_contents( $theme, $css );
	};
	$setTheme( ":root {\n\t--nino-default: #fafafa;\n\t--nino-on-default: #101010;\n\t--nino-brand-safe: #336699;\n}\n@media (prefers-color-scheme: dark) {\n\t:root:not([data-nino-mode=\"light\"]) {\n\t\t--nino-default: #202020;\n\t}\n}\n" );
	$name( 'Studio Nord Hamburg' );

	// The manifest: a visitor reads it, in the shape a browser asks for
	$asVisitor();
	check( 'the three routes lie under the protected prefix and are not protected: the gate asks nothing of them, the pages of the portal are still shut',
		\Nino\Modules\Members::protects( $appData, '/portal/projekte' ) === true && \Nino\Modules\Members::protects( $appData, \Crm\Portal\App::MANIFEST ) === false && \Nino\Modules\Members::protects( $appData, \Crm\Portal\App::ICON ) === false
		&& \Nino\Modules\Members::protects( $appData, \Crm\Portal\App::ICON_PNG ) === false && array_diff( [ '/portal/manifest.webmanifest', '/portal/icon.svg', '/portal/icon.png' ], \Nino\Modules\Members::open( $appData ) ) === [] );
	$served = portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response'];
	$manifest = json_decode( (string) $served['body'], true );
	check( 'GET /portal/manifest.webmanifest answers a visitor 200 with application/manifest+json and the cache of an hour, and the body is JSON',
		$served['statusCode'] === 200 && str_starts_with( (string) $served['header']['Content-Type'], 'application/manifest+json' ) && $served['header']['Cache-Control'] === 'public, max-age=3600' && is_array( $manifest ) );
	check( '...it names the website as the app (name, and a short name cut at the end of a word within 12 characters), the page it opens, the part of the site it covers, standalone, the language of the site',
		( $manifest['name'] ?? '' ) === 'Studio Nord Hamburg' && ( $manifest['short_name'] ?? '' ) === 'Studio Nord' && ( $manifest['start_url'] ?? '' ) === '/portal/projekte' && ( $manifest['scope'] ?? '' ) === '/portal' && ( $manifest['display'] ?? '' ) === 'standalone' && ( $manifest['lang'] ?? '' ) === 'de-DE' );
	check( '...the colours are those of the theme: the brand for the bar of the browser, the ground of the page of the portal - the light ground with 4% of its ink - behind the splash',
		( $manifest['theme_color'] ?? '' ) === '#336699' && ( $manifest['background_color'] ?? '' ) === '#f1f1f1' && \Crm\Portal\App::colours( $appData ) === [ 'brand' => '#336699', 'page' => '#f1f1f1', 'light' => '#fafafa', 'dark' => '#202020' ] );
	$icons = (array) ( $manifest['icons'] ?? [] );
	check( '...the icons: the SVG for any size, maskable too, and the PNG of 180 pixels',
		count( $icons ) === 2 && $icons[0] === [ 'src' => '/portal/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any maskable' ] && $icons[1] === [ 'src' => '/portal/icon.png', 'sizes' => '180x180', 'type' => 'image/png', 'purpose' => 'any' ] );
	check( '...the shortening: whole up to 12 characters, else cut at the end of a word, else where the word stands - and a name of one long word is cut at 12',
		array_map( [ \Crm\Portal\App::class, 'shortName' ], [ 'Nord', 'Nord Kreativ', 'Nord Kreativ X', 'Studio Nord Hamburg', 'Kreativagentur Nord', 'Zwölf Zeichen am Ende', 'Eins Zwei Drei', '  Pad  ', 'Äöüß Äöüß Äöüßx' ] )
			=== [ 'Nord', 'Nord Kreativ', 'Nord Kreativ', 'Studio Nord', 'Kreativagent', 'Zwölf', 'Eins Zwei', 'Pad', 'Äöüß Äöüß' ] );

	// A name that is markup, a fill, a shortcode and a null byte: text, in the JSON and in the SVG
	$name( 'Evil &lt;b&gt;x&lt;/b&gt; [members-name] [crm-client] [[/nope/key]] "q" &amp; \'s' );
	$evil = portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response'];
	$evilManifest = json_decode( (string) $evil['body'], true );
	check( 'a name that is markup, a shortcode or a fill is the text it is: in the manifest it is decoded to what was typed, and the body has no tag, no ampersand, no quote of its own and no bracket but its own',
		is_array( $evilManifest ) && $evilManifest['name'] === 'Evil <b>x</b> [members-name] [crm-client] [[/nope/key]] "q" & \'s' && str_contains( (string) $evil['body'], '<b>' ) === false && str_contains( (string) $evil['body'], '&' ) === false
		&& substr_count( (string) $evil['body'], '[' ) === 1 && substr_count( (string) $evil['body'], ']' ) === 1 );
	$name( "A\0B" );
	check( '...a null byte is left out', ( json_decode( (string) portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response']['body'], true )['name'] ?? '' ) === 'AB' );
	$name( '' );
	check( '...a website with no name is the word of the portal, in both languages', ( json_decode( (string) portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response']['body'], true )['name'] ?? '' ) === 'Portal' );

	// The icon
	$name( 'Studio Nord Hamburg' );
	$svg = portalAsServed( $appData, '/portal/icon.svg' )['/nino/http/response'];
	$vector = new DOMDocument();
	check( 'GET /portal/icon.svg is an SVG of 512 by 512 that is well formed XML: 200, image/svg+xml, the cache of a day',
		$svg['statusCode'] === 200 && str_starts_with( (string) $svg['header']['Content-Type'], 'image/svg+xml' ) && $svg['header']['Cache-Control'] === 'public, max-age=86400' && $vector->loadXML( (string) $svg['body'] ) === true
		&& $vector->documentElement?->getAttribute( 'width' ) === '512' && $vector->documentElement->getAttribute( 'viewBox' ) === '0 0 512 512' );
	$sheet = new DOMXPath( $vector );
	$sheet->registerNamespace( 's', 'http://www.w3.org/2000/svg' );
	check( '...the brand\'s surface over the whole of it and the initials of the name, the first letter of the first and of the last word, in white', $sheet->query( '//s:rect[@fill="#336699"][@width="512"][@height="512"]' )->length === 1
		&& $sheet->query( '//s:text[@fill="#ffffff"]' )->item( 0 )?->textContent === 'SH' && $sheet->query( '//s:script | //s:foreignObject | //s:a' )->length === 0 );
	$name( 'Evil &lt;b&gt;x&lt;/b&gt; [members-name] &amp; "Bo"' );
	$evilSvg = (string) portalAsServed( $appData, '/portal/icon.svg' )['/nino/http/response']['body'];
	$evilVector = new DOMDocument();
	check( '...the initials of a name that is markup are letters, and the document is as well formed as ever: no tag of the name, no entity left open', $evilVector->loadXML( $evilSvg ) === true && $evilVector->getElementsByTagName( 'text' )->item( 0 )?->textContent === 'EB' && str_contains( $evilSvg, '<b>' ) === false );
	$name( '[[/nope/key]] [members-name]' );
	$bracketSvg = (string) portalAsServed( $appData, '/portal/icon.svg' )['/nino/http/response']['body'];
	check( '...and one that is only brackets and a shortcode has no bracket in the document at all besides the escaped ones', ( new DOMDocument() )->loadXML( $bracketSvg ) === true && preg_match( '/>[^<]*[\[\]][^<]*</', $bracketSvg ) !== 1 );
	$name( 'Studio Nord Hamburg' );

	// The PNG: GD draws it, once, in a file that is answered as a file
	$png = portalAsServed( $appData, '/portal/icon.png' )['/nino/http/response'];
	$file = (string) ( $png['file'] ?? '' );
	$info = $file !== '' && is_file( $file ) === true ? getimagesize( $file ) : false;
	$image = $file !== '' && is_file( $file ) === true ? imagecreatefrompng( $file ) : false;
	$white = 0;

	if( $image !== false )
		for( $y = 50; $y < 130; $y++ )
			for( $x = 30; $x < 150; $x++ )
				if( ( ( imagecolorat( $image, $x, $y ) >> 16 ) & 0xff ) > 0xb0 )
					$white++;

	check( 'GET /portal/icon.png is a PNG of 180 by 180 pixels, answered as a file with image/png and the cache of a day: the brand in the corners, white letters in the middle',
		$png['statusCode'] === 200 && ( $png['header']['Content-Type'] ?? '' ) === 'image/png' && ( $png['header']['Cache-Control'] ?? '' ) === 'public, max-age=86400' && $info !== false && $info[0] === 180 && $info[1] === 180 && $info[2] === IMAGETYPE_PNG
		&& $image !== false && ( imagecolorat( $image, 2, 2 ) & 0xffffff ) === 0x336699 && ( imagecolorat( $image, 177, 177 ) & 0xffffff ) === 0x336699 && $white > 150 );
	touch( $file, time() - 7200 );
	clearstatcache( true, $file );
	$aged = filemtime( $file );
	$again = portalAsServed( $appData, '/portal/icon.png' )['/nino/http/response'];
	clearstatcache( true, $file );
	check( '...the file is kept under a name that holds what it was made from, in a directory of its own, and is written once: a second request answers the file that is there',
		str_contains( $file, '/data/crm-icon/' ) === true && ( $again['file'] ?? '' ) === $file && filemtime( $file ) === $aged );
	$name( 'Zeta Omega' );
	$other = (string) ( portalAsServed( $appData, '/portal/icon.png' )['/nino/http/response']['file'] ?? '' );
	check( '...another name makes another file, and only that one is left', $other !== '' && $other !== $file && is_file( $other ) === true && is_file( $file ) === false && count( glob( dirname( $other ). '/*' ) ?: [] ) === 1 );
	$name( 'Studio Nord Hamburg' );

	// Without GD: the route is not made, the manifest does not name it and the head does not either
	$bare = $appData;
	$bare['./crm/portal/gd'] = false;
	unset( $bare['/nino/http/routes']['GET://portal/icon.png'] );
	\Crm\Portal\App::init( $bare );
	$missing = portalAsServed( $bare, '/portal/icon.png' )['/nino/http/response'];
	$bareManifest = json_decode( (string) portalAsServed( $bare, '/portal/manifest.webmanifest' )['/nino/http/response']['body'], true );
	check( 'where GD is not there the route of the PNG is not made: the request is the 404 of the site, the manifest holds the SVG alone, and the head names no apple-touch-icon of its own',
		$missing['statusCode'] === 404 && isset( $bare['/nino/http/routes']['GET://portal/icon.png'] ) === false && isset( $appData['/nino/http/routes']['GET://portal/icon.png'] ) === true && count( (array) ( $bareManifest['icons'] ?? [] ) ) === 1 && str_contains( \Crm\Portal\App::headTags( $bare ), 'apple-touch-icon' ) === false && str_contains( \Crm\Portal\App::headTags( $appData ), 'apple-touch-icon' ) === true );

	// The logo of the project: a square raster image is an icon of the manifest, a wide one and one that is none are not
	$square = imagecreatetruecolor( 96, 96 );
	$wide = imagecreatetruecolor( 200, 40 );
	ob_start();
	imagepng( $square );
	$squareBytes = (string) ob_get_clean();
	ob_start();
	imagepng( $wide );
	$wideBytes = (string) ob_get_clean();
	$logo = static function( string $filename, string $bytes ) use ( &$appData ): array {
		\Nino\Filesystem::forceDir( $appData, '/images' );
		file_put_contents( \Nino\Filesystem::path( $appData, '/images/'. $filename ), $bytes );
		$appData['/nino/html/images']['/logo'] = [ 'label' => 'Logo', 'width' => 96, 'height' => 96, 'filename' => $filename, 'alt' => [] ];

		return (array) ( json_decode( (string) portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response']['body'], true )['icons'] ?? [] );
	};
	$withLogo = $logo( 'logo.png', $squareBytes );
	$withWide = $logo( 'wide.png', $wideBytes );
	$withSvg = $logo( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>' );
	$gif = imagecreatetruecolor( 96, 96 );
	ob_start();
	imagegif( $gif );
	$withGif = $logo( 'logo.gif', (string) ob_get_clean() );
	unset( $appData['/nino/html/images']['/logo'] );
	check( 'the logo of the project joins the manifest where it is a square raster image: with its size and its type - a wide one is no icon, and neither is a file that is no raster image of those types (an SVG, a GIF)',
		count( $withLogo ) === 3 && $withLogo[2]['sizes'] === '96x96' && $withLogo[2]['type'] === 'image/png' && str_ends_with( $withLogo[2]['src'], '/images/logo.png' ) && count( $withWide ) === 2 && count( $withSvg ) === 2 && count( $withGif ) === 2 );
	foreach( [ 'logo.png', 'wide.png', 'logo.svg', 'logo.gif' ] as $filename )
		@unlink( \Nino\Filesystem::path( $appData, '/images/'. $filename ) );

	// The colours of a project whose theme says nothing
	$setTheme( null );
	check( 'a project with no theme.css gets the colours the kernel\'s own theme starts from - and no colour for the dark scheme, which its theme does not have', \Crm\Portal\App::colours( $appData ) === [ 'brand' => '#147bb7', 'page' => '#f5f5f5', 'light' => '#ffffff', 'dark' => null ] );
	$setTheme( ":root { --nino-brand-safe: javascript:alert(1); --nino-default: #12; }" );
	check( '...and so does one whose tokens are no colours', \Crm\Portal\App::colours( $appData )['brand'] === '#147bb7' && \Crm\Portal\App::colours( $appData )['light'] === '#ffffff' );
	$setTheme( $themeWas );

	// The head: the pages that have the bar name the manifest and the icon of the portal, the others are left as the site has them
	$name( 'Studio Nord Hamburg' );
	$reset();
	portalSignIn( $appData, $accounts['anna'] );
	$counts = [];

	foreach( [ '/portal', '/portal/projekte', '/portal/belege', '/portal/termine', '/portal/upload', '/portal/meine-daten', '/portal/projekte?projekt='. $project ] as $uri ) {
		[ $status, $page ] = portalSent( $appData, $uri );
		$counts[$uri] = [ $status, $page->query( '//link[@rel="manifest"]' )->length, $page->query( '//link[@rel="manifest"]/@href' )->item( 0 )?->nodeValue, $page->query( '//link[@rel="apple-touch-icon"]' )->length, $page->query( '//link[@rel="apple-touch-icon"]/@href' )->item( 0 )?->nodeValue,
			$page->query( '//meta[@name="theme-color"]' )->length, $page->query( '//meta[@name="apple-mobile-web-app-capable"]/@content' )->item( 0 )?->nodeValue, $page->query( '//meta[@name="apple-mobile-web-app-title"]/@content' )->item( 0 )?->nodeValue ];
	}

	check( 'every page with the bar has exactly one manifest, the one of the portal - the manifest of the head of the site is gone -, one apple-touch-icon, two colours of the bar of the browser (light and dark by media), the line that makes iOS an app of it, and its title',
		array_values( array_unique( array_map( 'json_encode', $counts ) ) ) === [ json_encode( [ 200, 1, '/portal/manifest.webmanifest', 1, '/portal/icon.png', 2, 'yes', 'Studio Nord' ] ) ] && count( $counts ) === 7 );
	[ , $page, $html ] = portalSent( $appData, '/portal/projekte' );
	$colours = $page->query( '//meta[@name="theme-color"]' );
	check( '...the colours are the theme\'s: the ground of the light scheme for the light media and the dark ground for the dark; the lines stand in the head, before its end, and the page is the page it was',
		$colours->length === 2 && $colours->item( 0 )?->getAttribute( 'media' ) === '(prefers-color-scheme: light)' && $colours->item( 0 )->getAttribute( 'content' ) === \Crm\Portal\App::colours( $appData )['light'] && $colours->item( 1 )?->getAttribute( 'media' ) === '(prefers-color-scheme: dark)'
		&& $colours->item( 1 )->getAttribute( 'content' ) === \Crm\Portal\App::colours( $appData )['dark'] && strpos( $html, 'apple-mobile-web-app-title' ) < strpos( $html, '</head>' ) && $page->query( '//body//main' )->length === 1 && str_contains( $html, 'site.webmanifest' ) === false );
	// A name that is markup: its line in <title> is no line of the head that names a manifest, so it stays as typed and only the real manifest link is swapped
	$name( '<link rel="manifest" href="/evil"> <link rel="apple-touch-icon" href="/evil.png"> Co' );
	[ , , $named ] = portalSent( $appData, '/portal/projekte' );
	$name( 'Studio Nord Hamburg' );
	check( 'a name that is itself a link to a manifest is text in the <title> and stays there: the one manifest line is the portal\'s, and so is the one apple-touch-icon line',
		preg_match( '~<title>[^\n]*<link rel="manifest" href="/evil"> <link rel="apple-touch-icon" href="/evil.png">[^\n]*</title>~', $named ) === 1 && preg_match_all( '~^\s*<link rel="manifest"~m', $named ) === 1 && str_contains( $named, '<link rel="manifest" href="/portal/manifest.webmanifest">' ) === true
		&& preg_match_all( '~^\s*<link rel="apple-touch-icon"~m', $named ) === 1 && str_contains( $named, '<link rel="apple-touch-icon" href="/portal/icon.png">' ) === true );
	// An answer that is no html is left as it is, whatever it holds and whoever marked it
	$plain = [ '/nino/http/response' => [ 'statusCode' => 200, 'header' => [ 'Content-Type' => 'text/plain; charset=utf-8' ], 'body' => "<head>\n<link rel=\"manifest\" href=\"/x\">\n</head>" ] ];
	$appData['./crm/portal/bar'] = true;
	$keep = $plain['/nino/http/response']['body'];
	\Crm\Portal\App::callbackOutput( $appData, $plain );
	unset( $appData['./crm/portal/bar'] );
	check( 'an answer that is no html is not touched by the hook that puts the lines of the app into a head', $plain['/nino/http/response']['body'] === $keep );
	// A theme without a block for the dark scheme has no dark bar: one colour, with no media
	$setTheme( ":root {\n\t--nino-default: #fafafa;\n\t--nino-brand-safe: #336699;\n}\n" );
	[ , $plainTheme ] = portalSent( $appData, '/portal/projekte' );
	$setTheme( $themeWas );
	check( 'a theme with no block for the dark scheme gets one colour of the bar of the browser and no media on it - no dark bar over a light page', $plainTheme->query( '//meta[@name="theme-color"]' )->length === 1 && $plainTheme->query( '//meta[@name="theme-color"]/@media' )->length === 0
		&& $plainTheme->query( '//meta[@name="theme-color"]/@content' )->item( 0 )?->nodeValue === '#fafafa' );
	// A page that sets the navigation itself (nav="0") has no bar: it is not the app's
	unset( $appData['./crm/portal/bar'] );
	portalHeadAt( $appData, '/portal', ' nav="0"' );
	$withoutBar = isset( $appData['./crm/portal/bar'] );
	portalHeadAt( $appData, '/portal' );
	check( 'a page with [crm-portal-head nav="0"] has no bar and says nothing to the output hook, so its head gets no lines of the app; the head with the bar does', $withoutBar === false && ( $appData['./crm/portal/bar'] ?? false ) === true );
	unset( $appData['./crm/portal/bar'] );
	$asVisitor();
	[ , $door, $doorHtml ] = portalSent( $appData, '/' );
	[ , , $missingHtml ] = portalSent( $appData, '/no-such-page' );
	check( 'a page that has no bar - the door, a page of nobody - names nothing of the portal: the head of the site stays as it is, with its own manifest', str_contains( $doorHtml, 'portal/manifest' ) === false && str_contains( $missingHtml, 'portal/manifest' ) === false && $door->query( '//link[@rel="manifest"]' )->length === 1 && str_contains( $doorHtml, 'site.webmanifest' ) === true );

	// The card: under the message, on the start page and the list of projects, hidden until the script shows it
	$reset();
	portalSignIn( $appData, $accounts['anna'] );
	$cards = [];

	foreach( [ '/portal', '/portal/projekte' ] as $uri ) {
		[ , $page ] = portalPage( $appData, $uri ) + [ 1 => '' ];
		$cards[$uri] = portalFind( $page, '//*[@data-crm-home]' );
	}

	$card = $cards['/portal'][0] ?? null;
	check( 'the start page and the list of projects have one card for the home screen: a note (role note), drawn hidden by the server, with the icon smartphone, a sentence for iOS and one for Android, the button to install - hidden - and the one to put it away',
		count( $cards['/portal'] ) === 1 && count( $cards['/portal/projekte'] ) === 1 && $card instanceof DOMElement && $card->getAttribute( 'role' ) === 'note' && $card->hasAttribute( 'hidden' ) === true && in_array( 'crm-home', explode( ' ', $card->getAttribute( 'class' ) ), true )
		&& portalFind( $card->ownerDocument->saveHTML( $card ), '//div[@data-crm-home]/svg[contains(@class, "crm-icon--smartphone")]' ) !== [] && count( portalFind( $card->ownerDocument->saveHTML( $card ), '//p[@data-crm-system]' ) ) === 2
		&& portalFind( $card->ownerDocument->saveHTML( $card ), '//button[@data-crm-install][@hidden]' ) !== [] && count( portalFind( $card->ownerDocument->saveHTML( $card ), '//button[@data-crm-dismiss][@type="button"]' ) ) === 1 );
	$cardless = [];

	foreach( [ '/portal/belege', '/portal/termine', '/portal/upload', '/portal/meine-daten', '/portal/projekte?projekt='. $project, '/portal/datenschutz' ] as $uri )
		$cardless[$uri] = count( portalFind( portalPage( $appData, $uri )[1], '//*[@data-crm-home]' ) );

	$asVisitor();
	check( '...and no other page has it: the pages of the other areas, the page of a project, the Datenschutz - and a visitor is shown none', array_sum( $cardless ) === 0 && count( $cardless ) === 6 && portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-head]' ), '//*[@data-crm-home]' ) === [] );
	portalSignIn( $appData, $accounts['anna'] );
	\Crm\Settings::set( $appData, 'portal_message', 'Eine Nachricht' );
	$withMessage = portalPage( $appData, '/portal' )[1];
	check( '...it stands under the message of the owner and over the head of the page', strpos( $withMessage, 'crm-message' ) !== false && strpos( $withMessage, 'crm-message' ) < strpos( $withMessage, 'data-crm-home' ) && strpos( $withMessage, 'data-crm-home' ) < strpos( $withMessage, 'crm-head' ) );
	\Crm\Settings::set( $appData, 'portal_message', '' );
	\Crm\Settings::set( $appData, 'privacy_text', 'Text zur Prüfung des Hinweises.' );
	check( '...and while a step of the gate is pending - the Datenschutz to take note of - it is not drawn', portalFind( portalHeadAt( $appData, '/portal' ), '//*[@data-crm-home]' ) === [] && \Crm\Portal::pending( $appData, \Crm\Clients::get( $appData, $a['id'] ) ?? [] ) === 'privacy' );
	$pendingManifest = portalAsServed( $appData, '/portal/manifest.webmanifest' )['/nino/http/response'];
	check( '...the manifest and the icon of the app are no page of the gate: a client with a step pending is not sent away from them (the page of the portal is)', $pendingManifest['statusCode'] === 200 && isset( $pendingManifest['header']['Location'] ) === false && is_array( json_decode( (string) $pendingManifest['body'], true ) ) && portalServe( $appData, '/portal/icon.svg' )['statusCode'] === 200 && isset( portalServe( $appData, '/portal/icon.svg' )['header']['Location'] ) === false
		&& portalServe( $appData, '/portal/projekte' )['statusCode'] === 303 );
	\Crm\Settings::set( $appData, 'privacy_text', '' );

	// The words of the card, in both languages: German with Du, English, none empty, no key left standing
	$words = [ 'de_DE' => (array) require dirname( __DIR__ ). '/Portal/text/de_DE.php', 'en_US' => (array) require dirname( __DIR__ ). '/Portal/text/en_US.php' ];
	$keys = [ 'app/name', 'home/title', 'home/ios-name', 'home/ios', 'home/android-name', 'home/android', 'home/install', 'home/dismiss', 'data/devices-text', 'data/devices-button', 'data/state-devices', 'data/state-devices-failed' ];
	$has = static fn( array $set ): bool => array_filter( $keys, static fn( string $key ): bool => trim( (string) ( $set['[[/project/crm-portal/'. $key. ']]'] ?? '' ) ) === '' ) === [];
	check( 'the words of the card, of the button and of the two notes are there in both languages, none empty, the German with Du and the English other than it', $has( $words['de_DE'] ) === true && $has( $words['en_US'] ) === true
		&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', array_intersect_key( $words['de_DE'], array_flip( array_map( static fn( string $key ): string => '[[/project/crm-portal/'. $key. ']]', $keys ) ) ) ) ) !== 1
		&& array_filter( $keys, static fn( string $key ): bool => $words['de_DE']['[[/project/crm-portal/'. $key. ']]'] === $words['en_US']['[[/project/crm-portal/'. $key. ']]'] && $key !== 'app/name' && $key !== 'home/ios-name' && $key !== 'home/android-name' ) === [] );
	$appData['./nino/locales/current'] = 'en_US';
	$english = portalPage( $appData, '/portal' )[1];
	$appData['./nino/locales/current'] = 'de_DE';
	check( '...in English the card is the English one and no key is left standing', str_contains( $english, $words['en_US']['[[/project/crm-portal/home/title]]'] ) === true && str_contains( $english, '[[/project' ) === false && str_contains( $english, $words['de_DE']['[[/project/crm-portal/home/title]]'] ) === false );
	check( 'the style sheet lets [hidden] win against the display of a note, and the script holds none of it: the card has a rule for it', preg_match( '/\.crm-home\[hidden\]\s*\{\s*display:\s*none;/', (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.css' ) ) === 1 );

	// Alle Geräte abmelden
	echo "\nAlle Geräte abmelden: Meine Daten, POST /.crm/geraete/abmelden\n";
	$reset();
	portalSignIn( $appData, $accounts['anna'] );
	$data = portalPage( $appData, '/portal/meine-daten' )[1];
	$forms = portalFind( $data, '//section[contains(@class, "crm-login")]//form[contains(@class, "crm-login__devices")]' );
	check( 'the block Anmeldeadresse of Meine Daten has the button: a post form to /.crm/geraete/abmelden with the csrf token of the session and one button of the secondary kind, 44px by the class of every button',
		count( $forms ) === 1 && $forms[0]->getAttribute( 'method' ) === 'post' && $forms[0]->getAttribute( 'action' ) === '/.crm/geraete/abmelden' && portalFind( $forms[0]->ownerDocument->saveHTML( $forms[0] ), '//input[@name="_csrf"]/@value' )[0]->nodeValue === \Nino\Csrf::getToken( $appData )
		&& portalFind( $forms[0]->ownerDocument->saveHTML( $forms[0] ), '//button[@type="submit"][contains(@class, "crm-btn")]' ) !== [] );
	$state = portalFind( portalPage( $appData, '/portal/meine-daten?state=devices' )[1], '//section[contains(@class, "crm-login")]//p[contains(@class, "crm-note--success")][@role="status"]' );
	check( '...?state=devices is said in that block, as a note of success, and does not open the form that changes the address; any other state is not said there', count( $state ) === 1 && portalFind( portalPage( $appData, '/portal/meine-daten?state=devices' )[1], '//details[contains(@class, "crm-login__fold")][@open]' ) === []
		&& portalFind( portalPage( $appData, '/portal/meine-daten?state=nonsense' )[1], '//section[contains(@class, "crm-login")]//p[contains(@class, "crm-note")]' ) === [] && count( portalFind( portalPage( $appData, '/portal/meine-daten?state=devices-failed' )[1], '//section[contains(@class, "crm-login")]//p[contains(@class, "crm-note--error")][@role="alert"]' ) ) === 1 );

	$asVisitor();
	$visitor = portalServe( $appData, '/.crm/geraete/abmelden', 'POST' );
	portalSignIn( $appData, $stray );
	$none = portalServe( $appData, '/.crm/geraete/abmelden', 'POST' );
	check( 'POST /.crm/geraete/abmelden is the 404 for a visitor and for a member of no client, and nothing for a GET', $visitor['statusCode'] === 404 && $none['statusCode'] === 404 && portalServe( $appData, '/.crm/geraete/abmelden' )['statusCode'] === 404 );
	portalSignIn( $appData, $accounts['anna'] );
	$id = $accounts['anna']['id'];
	$state = (array) \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] );
	$state['accounts'][$id]['remember'] = [ [ 'hash' => hash( 'sha256', 'device' ), 'created' => '2026-11-01T10:00:00Z', 'expires' => time() + 5000, 'agent' => 'Agent' ] ];
	\Nino\Filesystem::putFileContent( $appData, '/data/members.php', $state );
	$otherDevice = $_SESSION;
	$expired = portalServe( $appData, '/.crm/geraete/abmelden', 'POST', [ '_csrf' => 'wrong' ] );
	check( '...a post without the token of the session is the redirect with ?state=expired and ends nothing', $expired['statusCode'] === 303 && ( $expired['header']['Location'] ?? '' ) === '/portal/meine-daten?state=expired' && ( \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] )['accounts'][$id]['remember'] ?? [] ) !== [] );
	$epoch = (string) ( \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] )['accounts'][$id]['epoch'] ?? '' );
	$done = portalServe( $appData, '/.crm/geraete/abmelden', 'POST' );
	$after = (array) ( \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] )['accounts'][$id] ?? [] );
	check( 'the post signs the account out of every device: the redirect to Meine Daten with ?state=devices, the list of the devices is empty, the account has an epoch of its own that is new',
		$done['statusCode'] === 303 && ( $done['header']['Location'] ?? '' ) === '/portal/meine-daten?state=devices' && isset( $after['remember'] ) === false && preg_match( '/^[0-9a-f]{32}$/D', (string) ( $after['epoch'] ?? '' ) ) === 1 && $after['epoch'] !== $epoch );
	check( '...the session that asked stays - the client is still its own, the page of the portal is served - and the session of another device, which carries the old epoch, is signed out', ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $id && portalServe( $appData, '/portal' )['statusCode'] !== 401 );
	// A write that fails is said, and nothing is ended: the lock of the accounts is a directory
	$locks = \Nino\Filesystem::path( $appData, '/data' ). '/.locks';
	foreach( glob( $locks. '/*.lock' ) ?: [] as $lockFile )
		unlink( $lockFile );
	\Nino\Filesystem::mutate( $appData, '/data/members.php', static fn( mixed $state ): ?array => null, [] );
	$accountLock = ( glob( $locks. '/*.lock' ) ?: [] )[0] ?? '';
	$state = (array) \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] );
	$state['accounts'][$id]['remember'] = [ [ 'hash' => hash( 'sha256', 'other' ), 'created' => '2026-11-01T10:00:00Z', 'expires' => time() + 5000, 'agent' => 'Agent' ] ];
	\Nino\Filesystem::putFileContent( $appData, '/data/members.php', $state );
	unlink( $accountLock );
	mkdir( $accountLock );
	$stuck = portalServe( $appData, '/.crm/geraete/abmelden', 'POST' );
	rmdir( $accountLock );
	$ignoredWarnings = ninoWarnings();
	check( 'where the accounts cannot be written the post says so - the redirect with ?state=devices-failed - and ends nothing: the list is as it was',
		( $stuck['header']['Location'] ?? '' ) === '/portal/meine-daten?state=devices-failed' && ( \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] )['accounts'][$id]['remember'] ?? [] ) !== [] );
	$_SESSION = $otherDevice;
	check( '...a session of another device, which carries the old epoch, is signed out', \Nino\Modules\Members::current( $appData ) === null );

	// A device that stays signed in is signed in on the page it asks for - and a client with a step of the gate pending is led to that step, as after a code
	$reset();
	portalSignIn( $appData, $accounts['anna'] );
	$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	$state = (array) \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] );
	$state['accounts'][$id]['remember'] = [ [ 'hash' => hash( 'sha256', $token ), 'created' => '2026-11-01T10:00:00Z', 'expires' => time() + 5000, 'agent' => 'Agent' ] ];
	\Nino\Filesystem::putFileContent( $appData, '/data/members.php', $state );
	\Crm\Settings::set( $appData, 'privacy_text', 'Text zur Prüfung des Cookies.' );
	$_SESSION = [];
	unset( $appData['./nino/auth/current'], $appData['./members/cookies'] );
	\Crm\Portal::forget( $appData );
	$_COOKIE['nino_remember'] = $id. '.'. $token;
	$led = portalServe( $appData, '/portal/projekte' );
	unset( $_COOKIE['nino_remember'] );
	check( 'a client who comes back with the cookie of a device is signed in on the page it asks for and, with the Datenschutz still to take note of, led to that step as after a code - with the page it wanted',
		( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $id && $led['statusCode'] === 303 && str_starts_with( (string) ( $led['header']['Location'] ?? '' ), '/portal/datenschutz' ) && str_contains( (string) $led['header']['Location'], 'weiter=' ) && count( (array) ( $appData['./members/cookies'] ?? [] ) ) === 1 );
	\Crm\Settings::set( $appData, 'privacy_text', '' );

	// The manifest and the icon are no business of the cookie: a request for them signs nobody in and rotates nothing
	$_SESSION = [];
	$rotated = explode( '=', explode( ';', (string) ( $appData['./members/cookies'][0] ?? '' ) )[0], 2 )[1] ?? '';
	unset( $appData['./members/cookies'] );
	$_COOKIE['nino_remember'] = $rotated;
	$manifestAsk = portalServe( $appData, '/portal/manifest.webmanifest' );
	$iconAsk = portalServe( $appData, '/portal/icon.svg' );
	unset( $_COOKIE['nino_remember'] );
	check( 'the request for the manifest or the icon with a good cookie signs nobody in, sets no cookie and uses the token up for nothing', $manifestAsk['statusCode'] === 200 && $iconAsk['statusCode'] === 200 && \Nino\Modules\Members::current( $appData ) === null && ( $appData['./members/cookies'] ?? [] ) === []
		&& ( \Nino\Filesystem::getFileContent( $appData, '/data/members.php', [] )['accounts'][$id]['remember'][0]['hash'] ?? '' ) === hash( 'sha256', explode( '.', $rotated )[1] ?? '' ) );

	// The README
	$readme = (string) file_get_contents( dirname( __DIR__ ). '/README.md' );
	check( 'the README says what the app is and how it is signed in: the manifest, the icons, the hint for the home screen, the cookie of a device, the epoch, the button and the menu entry',
		array_filter( [ '/portal/manifest.webmanifest', '/portal/icon.svg', '/portal/icon.png', 'nino_remember', 'rememberDays', '/.crm/geraete/abmelden', 'Alle Geräte abmelden', 'crm-clients/account-forget', 'data/crm-icon', 'Einheit erneut anwenden', 'GD', 'localStorage', '/members/open' ], static fn( string $needle ): bool => str_contains( $readme, $needle ) === false ) === [] );

	\Nino\Html::addFills( $appData, [ '[[/project/company/general/name]]' => $nameWas ], '*' );
	unset( $appData['./members/cookies'] );
}

/**
 *	The checks of the first round of errors: the words of the features after the setup, the locks of a project for every member of the group, the links of the owner
 *	with their kind, their days and where they lead after they ran out, the place on the page through the sign-in and the gate, and the sign-out as a post
 *
 *	@param		array 		&$appData
 *	@param		array 		$a							Studio Nord, a client whose gate is open
 *	@param		array 		$accounts				The accounts of the clients
 *	@param		array 		$owner					The author of a file the owner put there
 *	@param		string		$store					The class of the file store
 *	@param		string		$today
 *	@param		\Closure	$post						Posts an action of the panel
 *	@param		\Closure	$asAdmin
 *	@param		\Closure	$asVisitor
 *	@param		\Closure	$put
 *	@param		\Closure	$made
 *	@param		\Closure	$final
 *	@param		\Closure	$line
 *	@param		\Closure	$ago
 *
 *	@return 	void
 */
function portalFixRoundChecks( array &$appData, array $a, array $accounts, array $owner, string $store, string $today, \Closure $post, \Closure $asAdmin, \Closure $asVisitor, \Closure $put, \Closure $made, \Closure $final, \Closure $line, \Closure $ago ): void {

	global $mails;

	echo "\nThe first round of errors\n";

	$location = static fn( array $response ): string => (string) ( $response['header']['Location'] ?? '' );
	$state = static function() use ( &$appData ): void {
		$_SESSION = [];
		unset( $appData['./nino/auth/current'] );
		\Nino\Filesystem::putFileContent( $appData, '/data/members-tries.php', [] );
		\Nino\Filesystem::putFileContent( $appData, '/data/ratelimit.php', [] );
	};

	// A sign-in with a code, the way a visitor does it: the page of the first request, the form with its return, the code of the mail
	$codeFlow = static function( array $account, string $from, string $return ) use ( &$appData, $state, $location ): string {

		global $mails;

		$state();
		$count = count( $mails );

		if( $from !== '' )
			portalServe( $appData, $from );

		portalServe( $appData, '/.members/login', 'POST', [ 'email' => $account['email'], 'return' => $return ] );

		if( count( $mails ) !== $count + 1 || preg_match( '~>\s*([0-9]{3}) ([0-9]{3})\s*</p>~', (string) portalMail( $count )['body'], $found ) !== 1 )
			return '';

		return $location( portalServe( $appData, '/.members/code', 'POST', [ 'code' => $found[1]. $found[2], 'return' => '/' ] ) );
	};
	$tokenOf = static fn( string $link ): string => preg_match( '~t=([A-Za-z0-9_-]{43})$~D', $link, $found ) === 1 ? $found[1] : '';
	$tokens = static fn(): array => (array) \Nino\Filesystem::getFileContent( $appData, '/data/members-tokens.php', [] );
	$pattern = '~\[\[/(feature/(members|files|review)|template/page-members)~';

	// --- The words of the features after the press (error 1) ---------------------------------------------------------------------

	foreach( \Nino\Locales::getAvailableLocales( $appData ) as $locale )
		foreach( [ 'members', 'files', 'review' ] as $key ) {

			$file = \Nino\Features::get( $appData, $key )['dir']. '/install/text/'. $locale. '.php';
			$unit = is_file( $file ) === true ? array_keys( (array) include $file ) : [];
			\Nino\Filesystem::mutate( $appData, '/text/'. $locale. '.php', static fn( array $texts ): array => array_diff_key( $texts, array_flip( $unit ) ) );
		}

	$asVisitor();
	$state();
	$gateBefore = portalPage( $appData, '/portal/projekte' )[1];
	portalServe( $appData, '/.members/login', 'POST', [ 'email' => $accounts['anna']['email'], 'return' => '/portal' ] );
	$stepBefore = portalPage( $appData, '/portal?members=sent' )[1];
	$countBefore = count( $mails );
	$state();
	\Nino\Modules\Members::link( $appData, $accounts['anna'], 'invite', '', true, true );
	$mailBefore = portalMail( $countBefore )['subject']. ( portalMail( $countBefore )['body'] ?? '' );
	check( 'on a site whose text files miss the words of the features the login page, the page of the code and the invitation show the keys: the fixture is what the first error looked like', preg_match( $pattern, $gateBefore ) === 1 && preg_match( $pattern, $mailBefore. $stepBefore ) === 1 );

	$asAdmin();
	[ $status, $body ] = $post( 'crm/setup' );

	// The press enters the Datenschutzerklärung; this suite sets its own text where it needs one
	\Crm\Db::exec( $appData, 'DELETE FROM settings WHERE key IN ( \'privacy_text\', \'privacy_changed\' )' );
	check( 'the press brings the words back: features_units names Members, Files and Review with the number of words', $status === 200 && array_keys( $body['added']['features_units'] ) === [ 'members', 'files', 'review' ] && $body['added']['features_units']['members']['words'] > 20 );

	$asVisitor();
	$state();
	$afterPress = count( $mails );
	$gate = portalPage( $appData, '/portal/projekte' )[1];
	portalServe( $appData, '/.members/login', 'POST', [ 'email' => $accounts['anna']['email'], 'return' => '/portal' ] );
	$step = portalPage( $appData, '/portal?members=sent' )[1];
	$count = count( $mails );
	$state();
	\Nino\Modules\Members::link( $appData, $accounts['anna'], 'invite', '', true, true );
	$invite = portalMail( $count );
	check( 'after the press the login page, the page of the code, the mail of the code and the invitation hold no key: the button of the gate, the intro of the code, the subject and the intro of the invitation are words', preg_match( $pattern, $gate ) !== 1 && preg_match( $pattern, $step ) !== 1 && preg_match( $pattern, (string) $invite['subject']. (string) $invite['body'] ) !== 1
		&& portalFind( $gate, '//form[contains(@class, "members-login")]//button[@type="submit"]' ) !== [] && trim( portalFind( $gate, '//form[contains(@class, "members-login")]//button[@type="submit"]' )[0]->textContent ) !== '' && portalFind( $step, '//form[contains(@class, "members-code")]' ) !== []
		&& array_filter( portalMailsFrom( $afterPress ), static fn( array $mail ): bool => preg_match( $pattern, (string) ( $mail['subject'] ?? '' ). (string) ( $mail['body'] ?? '' ) ) === 1 ) === [] );

	// The page of the Datenschutz is named by the words of the page: the unit's own keys for name, title and description, whatever the old ones say
	$unitDe = (array) include dirname( __DIR__, 3 ). '/app/Crm/Crm/install/text/de_DE.php';
	$native = (string) $appData['/nino/locales/native'];
	\Nino\Filesystem::mutate( $appData, '/text/'. $native. '.php', static function( array $texts ): array {
		$texts['[[/_nino/webpage/portal/datenschutz/title]]'] = 'Datenschutz-Zustimmung';
		$texts['[[/_nino/webpage/portal/datenschutz/name]]'] = 'Datenschutz-Zustimmung';
		return $texts;
	} );
	portalSignIn( $appData, $accounts['anna'] );
	$fillsKept = $appData['./nino/html/fills'] ?? [];
	unset( $appData['./nino/html/fills'] );
	$privacy = portalPage( $appData, '/portal/datenschutz' )[1];
	$appData['./nino/html/fills'] = $fillsKept;
	$nav = portalNavAt( $appData, '/portal' );
	check( 'the page Datenschutz shows the words of its own keys - the title in the head and in the document, the name in the navigation - and not the ones a site brought from before', str_contains( $privacy, $unitDe['[[/template/page-portal-privacy/head/title]]'] ) === true && str_contains( $privacy, 'Datenschutz-Zustimmung' ) === false && str_contains( $nav, 'Datenschutz-Zustimmung' ) === false );
	$headTitle = portalFind( $privacy, '//title' );
	check( '...on a request of its own, with no fill of an earlier page in the data: the title, the description, the tags of the networks and the h1 of the bar - all read before a component is drawn - carry the words of the page too', $headTitle !== [] && str_contains( $headTitle[0]->textContent, $unitDe['[[/template/page-portal-privacy/head/title]]'] ) === true
		&& portalFind( $privacy, '//meta[@name="description"]/@content' )[0]->nodeValue === $unitDe['[[/template/page-portal-privacy/head/description]]'] && str_contains( portalFind( $privacy, '//meta[@property="og:title"]/@content' )[0]->nodeValue, $unitDe['[[/template/page-portal-privacy/head/title]]'] ) === true
		&& str_contains( portalText( $privacy, '//header[@class="crm-bar"]//h1' ), $unitDe['[[/template/page-portal-privacy/head/title]]'] ) === true && str_contains( $privacy, 'Datenschutz-Zustimmung' ) === false );

	// --- The locks of a project for every member of the group (error 2) --------------------------------------------------------

	$asAdmin();
	$folder = $store::addFolder( $appData, $a['group'], 'Sperre-Gruppe' );
	$song = $put( $folder, 'Sperre.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 500 ) );
	$plan = $put( $folder, 'Sperre.pdf', 'pdf', 'application/pdf', true, $owner, $ago( 400 ) );
	$unlinked = \Nino\Modules\Members::add( $appData, 'unlinked@example.org', 'Unlinked', $a['group'] );
	$mine = $put( $folder, 'Sperre-eigen.mp3', 'mp3', 'audio/mpeg', false, [ 'kind' => 'member', 'id' => $unlinked['id'] ], $ago( 300 ) );
	$project = $made( 'Sperre-Gruppe', $a['number'], [ 'folder' => $folder['id'] ], false );
	$matrix = static function() use ( &$appData, $song, $plan, $mine ): array {

		$answers = [];

		foreach( [ 'song inline' => '/.files/'. $song['id'], 'song download' => '/.files/'. $song['id']. '?download=1', 'pdf download' => '/.files/'. $plan['id']. '?download=1', 'own download' => '/.files/'. $mine['id']. '?download=1' ] as $label => $uri )
			$answers[$label] = portalServe( $appData, $uri )['statusCode'];

		return $answers;
	};

	portalSignIn( $appData, $unlinked );
	$held = $matrix();
	check( 'a member in the group of a client with no link to it is held to the project like the client: the download of a medium is 404 until the owner switches it on, the file inline, a file that is no medium and the member\'s own upload are given', \Crm\Clients::ofMember( $appData, $unlinked['id'] ) === null
		&& $held === [ 'song inline' => 200, 'song download' => 404, 'pdf download' => 200, 'own download' => 200 ] );
	portalSignIn( $appData, $accounts['anna'] );
	$clientHeld = $matrix();
	check( '...which is what the client itself gets', $clientHeld['song download'] === 404 && $clientHeld['song inline'] === 200 && $clientHeld['pdf download'] === 200 );

	$asAdmin();
	\Crm\Projekte::setDownload( $appData, $project, true, 'owner@example.org' );
	portalSignIn( $appData, $unlinked );
	check( '...and the download is given when the owner has switched it on', $matrix()['song download'] === 200 );

	$asAdmin();
	$offer = $final( 'offer', $a['id'], [ 'date' => $today, 'valid_until' => '2099-12-31', 'project' => $project, 'template_key' => 'angebot', 'lines' => [ $line( 'Sperre' ) ] ] );
	\Crm\Documents::release( $appData, $offer['id'], false );
	portalSignIn( $appData, $unlinked );
	$waiting = $matrix();
	$asAdmin();
	$ownerOpens = [ portalServe( $appData, '/.files/'. $song['id'] )['statusCode'], portalServe( $appData, '/.files/'. $song['id']. '?download=1' )['statusCode'] ];
	portalSignIn( $appData, $unlinked );
	check( 'while an offer of the project waits for the order everything is 404 for that member - inline, as a download, a file that is no medium, the member\'s own - and the player answers 404 too; the owner opens it by the permission of the panel',
		array_values( array_unique( $waiting ) ) === [ 404 ] && portalPlayerAsk( $appData, '?file='. $song['id'] )['statusCode'] === 404 && $ownerOpens === [ 200, 200 ] );
	\Crm\Db::exec( $appData, 'UPDATE documents SET state = \'declined\' WHERE id = :id', [ 'id' => $offer['id'] ] );
	\Crm\Projekte::forget( $appData );
	$asAdmin();
	$loose = $put( $store::addFolder( $appData, $a['group'], 'Ohne Projekt Gruppe' ), 'Lose.mp3', 'mp3', 'audio/mpeg', true, $owner, $ago( 200 ) );
	portalSignIn( $appData, $unlinked );
	check( '...and a file of no project stays as Files has it', portalServe( $appData, '/.files/'. $loose['id']. '?download=1' )['statusCode'] === 200 );

	// --- The links of the owner (error 3) ----------------------------------------------------------------------------------------

	$target = '/portal/belege#beleg-7';
	$state();
	$link = \Nino\Modules\Members::link( $appData, $accounts['anna'], 'send', $target, true, false );
	$token = $tokenOf( (string) $link );
	$entry = (array) ( $tokens()[ hash( 'sha256', $token ) ] ?? [] );
	$days = (int) \Nino\Features::setting( $appData, 'members', 'inviteDays', 7 ) * 86400;
	check( 'a link of the owner to an account that has signed in is of the kind send: the days of an invitation, the target in the record of the server', ( $entry['kind'] ?? '' ) === 'send' && abs( ( $entry['expires'] ?? 0 ) - time() - $days ) <= 5 && ( $entry['target'] ?? '' ) === $target );

	$file = $tokens();
	$file[ hash( 'sha256', $token ) ]['expires'] = time() - 60;
	\Nino\Filesystem::putFileContent( $appData, '/data/members-tokens.php', $file );
	$state();
	$ended = portalServe( $appData, '/.members/login?t='. $token. '&weiter=%2Fportal%2Fupload' );
	check( 'the page of a link that ran out says so (400) and keeps the target the server stored with it for the code - the address names none', $ended['statusCode'] === 400 && \Nino\Runtime::getSessionValue( $appData, './members/target' ) === $target );
	check( '...and the code of the sign-in leads to that page, place on the page and all', $codeFlow( $accounts['anna'], '/.members/login?t='. $token. '&weiter=%2Fportal%2Fupload', '/' ) === $target );

	$unknown = [];

	foreach( [ 'a token that is none' => strrev( $token ), 'the token of a spent link' => $token ] as $label => $text ) {
		$file = $tokens();
		unset( $file[ hash( 'sha256', $token ) ] );
		\Nino\Filesystem::putFileContent( $appData, '/data/members-tokens.php', $file );
		$state();
		portalServe( $appData, '/.members/login?t='. $text );
		$unknown[$label] = \Nino\Runtime::getSessionValue( $appData, './members/target' ) === null;
	}

	foreach( [ 'a target that is no path' => [ '//evil.example', time() - 60 ], 'a record that is older than it is kept' => [ $target, time() - 40 * 86400 ] ] as $label => [ $text, $expires ] ) {
		$forged = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$file = $tokens();
		$file[ hash( 'sha256', $forged ) ] = [ 'account' => $accounts['anna']['id'], 'kind' => 'send', 'expires' => $expires, 'target' => $text ];
		\Nino\Filesystem::putFileContent( $appData, '/data/members-tokens.php', $file );
		$state();
		portalServe( $appData, '/.members/login?t='. $forged );
		$unknown[$label] = \Nino\Runtime::getSessionValue( $appData, './members/target' ) === null;
	}

	check( 'no target comes of a token the server does not know, of a link that was spent, of a stored target that is no path or of a record older than the server keeps', array_filter( $unknown, static fn( bool $none ): bool => $none === false ) === [] && count( $unknown ) === 4 );

	// --- The place on the page through the sign-in and the gate (error 9) ----------------------------------------------------------

	$asked = '/portal/projekte?projekt=00001&tab=angebot';
	$anchored = $codeFlow( $accounts['anna'], $asked, '/portal/projekte#angebot-3' );
	check( 'a visitor who asked for a page of the portal and posts the login form with the place of the address behind its return lands on the page with that place', $anchored === $asked. '#angebot-3' );
	$refused = [];

	foreach( [ 'a second hash' => '/portal/projekte#a#b', 'markup' => '/portal/projekte#<img', 'an encoded hash' => '/portal/projekte#a%23b', 'nothing behind it' => '/portal/projekte#', 'too long' => '/portal/projekte#'. str_repeat( 'a', 65 ), 'another page' => '/portal/belege#x' ] as $label => $return )
		$refused[$label] = $codeFlow( $accounts['anna'], $asked, $return ) === $asked;

	check( '...and a place that is none, or that belongs to another page than the one asked for, is dropped: the page alone', array_filter( $refused, static fn( bool $same ): bool => $same === false ) === [] );
	check( 'onward() takes a place of letters, digits, hyphens and underscores behind the query and nothing else - weiter and a link name pages alike', \Crm\Portal::onward( '/portal/projekte?projekt=00001&tab=angebot#Angebot_3-a' ) === '/portal/projekte?projekt=00001&tab=angebot#Angebot_3-a' && \Crm\Portal::target( '/portal#beleg-1' ) === '/portal#beleg-1'
		&& array_filter( [ '/portal#a#b', '/portal#<img', '/portal#a%23b', '/portal#', '/portal# x', '/portal?x=1#a b', '/crm#a', '//evil.example#a' ], static fn( string $bad ): bool => \Crm\Portal::onward( $bad ) !== '' || \Crm\Portal::target( $bad ) !== '' ) === [] );

	// A client at the gate: the page asked for, with its place, goes through the Datenschutz and the data
	$privacyWas = \Crm\Settings::get( $appData, 'privacy_text' );
	\Crm\Settings::set( $appData, 'privacy_text', 'Der Text des Tors' );
	$asAdmin();
	$state();
	$fresh = \Crm\Clients::add( $appData, [], [ 'email' => 'tor-anker@example.org', 'name' => '', 'notify' => false ] );
	$freshAccount = (array) ( $fresh['first']['account'] ?? [] );
	$wanted = '/portal/projekte?projekt=00001&tab=angebot#angebot-12';
	$freshToken = $tokenOf( (string) \Nino\Modules\Members::link( $appData, $freshAccount, 'invite', $wanted, true, false ) );
	$state();
	portalServe( $appData, '/.members/login?t='. $freshToken );
	$entered = portalServe( $appData, '/.members/confirm', 'POST', [ 'token' => $freshToken ] );
	$toPrivacy = $location( $entered );
	check( 'a first-time client who follows a link with a place on a page is led to the Datenschutz with the page and its place as weiter - in one answer, from the sign-in', $toPrivacy === '/portal/datenschutz?weiter='. rawurlencode( $wanted ) );
	$page = portalPage( $appData, $toPrivacy )[1];
	check( '...the form of the Datenschutz carries it in its field weiter, place and all', portalFind( $page, '//form[contains(@class, "crm-privacy__form")]//input[@name="weiter"]/@value' )[0]->nodeValue === $wanted );
	$agreed = portalServe( $appData, '/.crm/datenschutz', 'POST', [ 'agree' => '1', 'weiter' => $wanted ] );
	check( '...the Datenschutz goes on to the data with the same weiter...', $location( $agreed ) === '/portal/meine-daten?weiter='. rawurlencode( $wanted ) );
	$saved = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Tor Anker', 'street' => 'Weg 1', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE', 'weiter' => $wanted ] );
	check( '...and the data to the page with its place', $location( $saved ) === $wanted );
	$bad = [];

	foreach( [ 'a second hash' => '/portal/belege#a#b', 'markup' => '/portal/belege#<img', 'an encoded hash' => '/portal/belege#a%23b' ] as $label => $weiter ) {
		$answer = portalServe( $appData, '/.crm/daten', 'POST', [ 'name' => 'Tor Anker', 'street' => 'Weg 1', 'zip' => '20095', 'city' => 'Hamburg', 'country' => 'DE', 'weiter' => $weiter ] );
		$bad[$label] = str_contains( $location( $answer ), '#' ) === false && str_contains( $location( $answer ), 'weiter' ) === false;
	}

	check( '...a weiter with a place that is none is carried no further: no weiter, no place in the Location', array_filter( $bad, static fn( bool $clean ): bool => $clean === false ) === [] );
	\Crm\Settings::set( $appData, 'privacy_text', $privacyWas );

	// --- The sign-out is a post with the token (error 10) ------------------------------------------------------------------------

	portalSignIn( $appData, $accounts['anna'] );
	$barPage = portalPage( $appData, '/portal' )[1];
	$csrf = portalFind( $barPage, '//header[@class="crm-bar"]//form[@action="/.members/logout"]/input[@name="_csrf"]/@value' );
	check( 'the bar draws the sign-out as a form with the token of the session and one button', count( $csrf ) === 1 && $csrf[0]->nodeValue === \Nino\Csrf::getToken( $appData ) && count( portalFind( $barPage, '//header[@class="crm-bar"]//form[@action="/.members/logout"]/button[@type="submit"]' ) ) === 1 );
	$get = portalServe( $appData, '/.members/logout' );
	$blocked = portalServe( $appData, '/.members/logout', 'POST', [ '_csrf' => 'wrong' ] );
	check( 'a GET of the endpoint is 405 and signs nobody out, a post with a token that is wrong is 403 and signs nobody out', $get['statusCode'] === 405 && ( $get['header']['Allow'] ?? '' ) === 'POST' && $blocked['statusCode'] === 403 && ( \Nino\Modules\Members::current( $appData )['id'] ?? '' ) === $accounts['anna']['id'] );
	$out = portalServe( $appData, '/.members/logout', 'POST' );
	check( '...and the post with the token signs out and leads to the start page', $out['statusCode'] === 303 && \Nino\Modules\Members::current( $appData ) === null );
}

/**
 *	The cell of the day an invoice is due says "fällig" for an invoice that is open or overdue and for none that is paid, cancelled, a draft or run out - with a day and without one -,
 *	and no other kind of document has one
 *
 *	@return 	bool
 */
function portalDueCellChecks(): bool {

	$invoice = [ 'category' => 'invoice', 'due_date' => '2026-12-01' ];
	$says = static fn( array $document, string $state ): bool => \Crm\Portal::dueCell( $document, $state ) !== '';
	$wrong = [];

	foreach( [ 'open', 'overdue' ] as $state )
		if( $says( $invoice, $state ) === false || str_contains( \Crm\Portal::dueCell( $invoice, $state ), '2026-12-01' ) === false || $says( [ 'due_date' => '' ] + $invoice, $state ) === false )
			$wrong[] = $state;

	foreach( [ 'paid', 'cancelled', 'draft', 'expired', 'credited', 'x' ] as $state )
		if( $says( $invoice, $state ) === true || $says( [ 'due_date' => '' ] + $invoice, $state ) === true )
			$wrong[] = $state;

	foreach( [ 'offer', 'contract', 'privacy', 'information' ] as $category )
		if( $says( [ 'category' => $category ] + $invoice, 'open' ) === true )
			$wrong[] = $category;

	check( '...the due cell by state: a day and "payable at once" for open and overdue, nothing for a paid, cancelled, draft or run-out invoice or any state - and nothing for the other kinds'. ( $wrong === [] ? '' : ' ('. implode( ', ', $wrong ). ')' ), $wrong === [] );

	return true;
}

/**
 *	Where the bar of tabs stands at the foot of the window - below 768px - the page keeps the room of the bar (its height and the safe area of the device) and a hand's breadth above it
 *	as the scroll padding at the foot, so that a control that takes the focus is scrolled above the bar and not under it
 *
 *	@return 	bool
 */
function portalFocusSheetChecks(): bool {

	$sheet = (string) file_get_contents( dirname( __DIR__ ). '/Portal/assets/portal.css' );
	preg_match( '/@media screen and \(max-width: 767\.98px\) \{(.*?)\n\}\n/s', $sheet, $block );
	$phone = $block[1] ?? '';

	check( '...the page below 768px keeps the room of the bar of tabs under a control that has the focus: scroll-padding-bottom is the height of the bar, the safe area and a step of the scale, on the element that has the bar - and the bar is that high',
		preg_match( '/html:has\(\.crm-nav\) \{[^}]*scroll-padding-bottom: calc\( 56px \+ env\(safe-area-inset-bottom\) \+ var\(--crm-4\) \);/', $phone ) === 1 && preg_match( '/\.crm-nav \{[^}]*height: calc\( 56px \+ env\(safe-area-inset-bottom\) \)/', $phone ) === 1
		&& preg_match( '/scroll-padding-bottom/', preg_replace( '/@media screen and \(max-width: 767\.98px\) \{.*?\n\}\n/s', '', $sheet, 1 ) ?? '' ) !== 1 );

	return true;
}

/**
 *	The card of a project in /crm knows the offer that holds its files back: crm-projekte/get answers waiting with the id and the number of the offer while it waits for the order, and null for a project none waits for
 *
 *	@param		\Closure	$post						Runs an action of the panel
 *	@param		\Closure	$asAdmin				Signs in the owner
 *	@param		string		$waits					The project the offer waits for
 *	@param		string		$free						Another project of the client
 *	@param		array 		$offer					The offer
 *
 *	@return 	void
 */
function portalWaitingGetChecks( \Closure $post, \Closure $asAdmin, string $waits, string $free, array $offer ): void {

	$asAdmin();
	[ $status, $body ] = $post( 'crm-projekte/get', [ 'id' => $waits ] );
	[ $freeStatus, $freeBody ] = $post( 'crm-projekte/get', [ 'id' => $free ] );

	check( 'crm-projekte/get says which offer holds the files of the project back: waiting is its id and its number - and null for a project no offer waits for, the key always there',
		$status === 200 && ( $body['waiting'] ?? null ) === [ 'id' => $offer['id'], 'number' => $offer['number'] ] && $freeStatus === 200 && array_key_exists( 'waiting', $freeBody ) === true && $freeBody['waiting'] === null );
}

/**
 *	A client with notes of many lines - the notes are the owner's, the form of the portal has no field for them - saves its data in the portal: the form sends the fields it has and the
 *	notes, which it does not send, are not looked at and are kept
 *
 *	@param		array 		&$appData
 *	@param		array 		$client					The client
 *	@param		\Closure	$fields					The fields of a valid post, with the changes given
 *	@param		\Closure	$location				The Location of an answer
 *	@param		array 		$member					The account of the client
 *
 *	@return 	void
 */
function portalNotesChecks( array &$appData, array $client, \Closure $fields, \Closure $location, array $member ): void {

	global $mails;

	$mailsBefore = $mails->getArrayCopy();
	portalSignIn( $appData, $member );
	Db::exec( $appData, 'UPDATE clients SET notes = :notes WHERE id = :id', [ 'notes' => "Erste Zeile\nZweite Zeile\r\nDritte", 'id' => $client['id'] ] );
	$answer = portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'street' => 'Notizweg 9' ] ) );
	$now = \Crm\Clients::get( $appData, $client['id'] );

	check( 'a client with notes of many lines saves its data in the portal: 303 ?state=saved, the street changed, the notes as they were - and not the 404 of a field the form does not have', $answer['statusCode'] === 303 && $location( $answer ) === '/portal/meine-daten?state=saved' && $now['street'] === 'Notizweg 9' && $now['notes'] === "Erste Zeile\nZweite Zeile\r\nDritte" );

	Db::exec( $appData, 'UPDATE clients SET notes = \'\' WHERE id = :id', [ 'id' => $client['id'] ] );
	portalServe( $appData, '/.crm/daten', 'POST', $fields( [ 'street' => (string) $client['street'] ] ) );
	$mails->exchangeArray( $mailsBefore );
}

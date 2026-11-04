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
 *
 *	@return 	array										The response of the request
 */
function portalServe( array &$appData, string $uri, string $method = 'GET', array $post = [] ): array {

	$_POST = $post + ( $method === 'POST' ? [ '_csrf' => \Nino\Csrf::getToken( $appData ) ] : [] );
	$request = ninoVisit( $appData, $uri, $method );
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
check( 'the style sheet of the list is in the bundle of the site, once, and declares its three properties in :where(:root)', count( array_keys( $appData['/nino/html/assets']['/.cache/style.css'] ?? [], '/app/Crm/Portal/assets/portal.css', true ) ) === 1
	&& preg_match( '/:where\(:root\)\s*\{[^}]*--crm-documents-space[^}]*--crm-documents-corners[^}]*--crm-documents-border/s', (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' ) ) === 1 );
check( '...the list stands at the left of its column as the other lists of the page do: no auto margin on the sides', preg_match( '/\n\.crm-documents \{[^}]*margin: 0 0 var\(--space-2\);/s', (string) file_get_contents( $root. '/app/Crm/Portal/assets/portal.css' ) ) === 1 );

$asVisitor();
$empty = '<div class="crm-documents crm-documents--empty"><p class="crm-documents__empty">Für Dich liegen noch keine Dokumente bereit.</p></div>';
check( 'a visitor is told there are none', preg_replace( '/>\s+</', '><', trim( \Nino\Html::renderHtml( $appData, '[crm-documents]' ) ) ) === $empty );
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
$rows = preg_split( '/(?=<li class="crm-documents__row")/', $html );
array_shift( $rows );
$rowOf = static function( int $id ) use ( $rows ): string {
	foreach( $rows as $row )
		if( str_contains( $row, 'data-document="'. $id. '"' ) === true )
			return $row;
	return '';
};
$rowOfNow = static function( int $id ) use ( &$appData ): string {
	foreach( preg_split( '/(?=<li class="crm-documents__row")/', \Nino\Html::renderHtml( $appData, '[crm-documents]' ) ) ?: [] as $row )
		if( str_contains( $row, 'data-document="'. $id. '"' ) === true )
			return $row;
	return '';
};
check( 'the list has a row for each released document of the member\'s client, newest first, and none for anything else', count( $rows ) === 5 && array_map( static fn( string $row ): int => (int) preg_replace( '/^.*?data-document="(\d+)".*$/s', '$1', $row ), $rows ) === [ $infoA['id'], $privacyA['id'], $contractA['id'], $invoiceA['id'], $offerA['id'] ]
	&& str_contains( $html, 'data-document="'. $invoiceB['id']. '"' ) === false && str_contains( $html, 'data-document="'. $draftA['id']. '"' ) === false && str_contains( $html, 'data-document="'. $invoiceA2['id']. '"' ) === false );
check( '...each says its kind, its title or its number, its day as d.m.Y and its state in words', str_contains( $rowOf( $invoiceA['id'] ), '>Rechnung</span>' ) === true && str_contains( $rowOf( $invoiceA['id'] ), '>R-2026-0001</span>' ) === true && str_contains( $rowOf( $invoiceA['id'] ), '>01.10.2026</time>' ) === true && str_contains( $rowOf( $invoiceA['id'] ), 'datetime="2026-10-01"' ) === true
	&& str_contains( $rowOf( $invoiceA['id'] ), '>versendet</span>' ) === true && str_contains( $rowOf( $infoA['id'] ), '>Information</span>' ) === true && str_contains( $rowOf( $infoA['id'] ), '>Zugang</span>' ) === true && str_contains( $rowOf( $infoA['id'] ), '>offen</span>' ) === true );
check( '...an offer past its day says it ran out, a document that is signed says it is signed', str_contains( $rowOf( $offerA['id'] ), '>abgelaufen</span>' ) === true && str_contains( $rowOf( $contractA['id'] ), '>unterschrieben</span>' ) === true );
check( '...and has the download of the PDF: /.crm/pdf/<id>?download=1', array_filter( [ $infoA, $privacyA, $contractA, $invoiceA, $offerA ], static fn( array $document ): bool => str_contains( $rowOf( $document['id'] ), 'href="/.crm/pdf/'. $document['id']. '?download=1"' ) === false ) === [] );
check( 'a document that is signed has the link to the signed file of Sign, by its request; the one that waits for the signature the link to the page of the request', str_contains( $rowOf( $contractA['id'] ), 'href="/.sign/pdf?r='. $signed['sign_id']. '&amp;kind=signed"' ) === true && str_contains( $rowOf( $contractA['id'] ), 'href="/.sign?r=' ) === false
	&& str_contains( $rowOf( $privacyA['id'] ), 'href="/.sign?r=' ) === false );

// a request that waits for the signature
$waiting = Documents::requestSignature( $appData, $infoA['id'], $accounts['anna']['id'] );
check( 'a document that Sign takes no signature for has no link to it', $waiting === 'category' && str_contains( $rowOf( $infoA['id'] ), '/.sign' ) === false );
$waits = Documents::requestSignature( $appData, $contractA3['id'], $accounts['anna']['id'] );
Documents::release( $appData, $contractA3['id'], false );
$withLink = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'where a signature is asked for (requested, viewed) the row links the request of Sign for the member: /.sign?r=<id>', is_array( $waits ) && str_contains( $withLink, 'class="crm-documents__sign" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fbelege"' ) === true && str_contains( $withLink, '>Jetzt unterschreiben</a>' ) === true );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$waits['document']['sign_id']]['status'] = 'viewed';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( '...a request the member has looked at is still one that waits: the link stays', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'class="crm-documents__sign" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fbelege"' ) === true );
\Nino\Modules\Sign::cancel( $appData, $waits['document']['sign_id'] );
check( '...and a request that is cancelled in Sign is none, the first time the portal reads it', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'class="crm-documents__sign"' ) === false && array_column( Documents::released( $appData, $a['id'] ), 'sign_state', 'id' )[$contractA3['id']] === 'cancelled' );
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
check( 'the page /portal/belege is served to the member with the list in it and the navigation above it, the area marked', $page[0] === 200 && str_contains( $page[1], '[crm-documents]' ) === false && str_contains( $page[1], 'class="crm-documents"' ) === true && str_contains( $page[1], 'Deine Belege' ) === true
	&& preg_match( '~<a class="crm-nav__link" href="/portal/belege" aria-current="page">~', $page[1] ) === 1 && substr_count( $page[1], 'aria-current="page"' ) === 1 && strpos( $page[1], 'class="crm-nav' ) < strpos( $page[1], '<h2' ) && strpos( $page[1], '<h2' ) < strpos( $page[1], 'class="crm-documents"' ) );
$appData['./nino/locales/current'] = 'en_US';
$english = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'in English the words are English: Invoice, sent, signed, Download', str_contains( $english, '>Invoice</span>' ) === true && str_contains( $english, '>sent</span>' ) === true && str_contains( $english, '>signed</span>' ) === true && str_contains( $english, '>Download</a>' ) === true && str_contains( $english, '[[/project' ) === false );
$appData['./nino/locales/current'] = 'de_DE';

// a client whose documents are all of kinds the module does not write is told there are none
Db::exec( $appData, 'UPDATE documents SET category = \'x y\' WHERE client_id = :c', [ 'c' => $b['id'] ] );
portalSignIn( $appData, $accounts['ben'] );
check( 'a list of which every row is left out is the sentence that there are none', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'crm-documents--empty' ) === true && str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), '<ul' ) === false );
Db::exec( $appData, 'UPDATE documents SET category = \'invoice\' WHERE id = :id', [ 'id' => $invoiceB['id'] ] );
Db::exec( $appData, 'UPDATE documents SET category = \'information\' WHERE id = :id', [ 'id' => $infoB['id'] ] );
$asAdmin();

// the words of the list
$wordsDe = (array) require $root. '/app/Crm/Portal/text/de_DE.php';
$wordsEn = (array) require $root. '/app/Crm/Portal/text/en_US.php';
$used = [];

foreach( glob( $root. '/app/Crm/Portal/templates/*.tpl' ) ?: [] as $file )
	if( preg_match_all( '~\[\[(/project/crm-(?:documents|portal)/[a-z_/-]+)\]\]~', (string) file_get_contents( $file ), $found ) > 0 )
		$used = array_merge( $used, $found[1] );

check( 'the words of the list are the same keys in German and English, none empty, German with Du, none a key of a text of the project', array_keys( $wordsDe ) === array_keys( $wordsEn ) && array_filter( $wordsDe + $wordsEn, static fn( string $text ): bool => trim( $text ) === '' ) === []
	&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', $wordsDe ) ) !== 1 && count( $wordsDe ) === 66 && array_filter( array_keys( $wordsDe ), static fn( string $key ): bool => preg_match( '~^\[\[/project/crm-(documents/(category|state|list)|portal/[a-z]+(/[a-z]+)?(/[a-z_]+)?)/?[a-z-]*\]\]$~D', $key ) !== 1 ) === [] );
check( '...every word the templates ask for is there, and the kinds and the states are all there is', array_diff( array_map( static fn( string $key ): string => '[['. $key. ']]', $used ), array_keys( $wordsDe ) ) === []
	&& array_diff( array_map( static fn( string $category ): string => '[[/project/crm-documents/category/'. $category. ']]', Documents::CATEGORIES ), array_keys( $wordsDe ) ) === []
	&& array_diff( array_map( static fn( string $state ): string => '[[/project/crm-documents/state/'. $state. ']]', [ 'open', 'sent', 'paid', 'cancelled', 'accepted', 'declined', 'signed', 'expired' ] ), array_keys( $wordsDe ) ) === [] );

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
$offerReverse = $final( 'offer', $a['id'], [ 'date' => '2026-10-04', 'project' => $p1, 'template_key' => 'angebot', 'reverse_charge' => true, 'lines' => [ $line( 'Beratung EU' ) ] ] );
$offerHidden = $final( 'offer', $a['id'], [ 'date' => '2026-10-06', 'project' => $p1, 'template_key' => 'angebot', 'lines' => [ $line( 'Nicht freigegeben' ) ] ] );
$offerOther = $final( 'offer', $a['id'], [ 'date' => '2026-10-07', 'project' => $p2, 'template_key' => 'angebot', 'lines' => [ $line( 'Andere Seite' ) ] ] );
$offerB = $final( 'offer', $b['id'], [ 'date' => '2026-10-08', 'project' => $p3, 'template_key' => 'angebot', 'lines' => [ $line( 'Fremdes Angebot' ) ] ] );
\Crm\Settings::set( $appData, 'vat_mode', 'small_business' );
$offerSmall = $final( 'offer', $a['id'], [ 'date' => '2026-10-03', 'project' => $p1, 'template_key' => 'angebot', 'lines' => [ $line( 'Kleinunternehmer' ) ] ] );
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

$asVisitor();
check( 'a visitor gets nothing of the navigation', trim( \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ) ) === '' );
portalSignIn( $appData, $accounts['anna'] );
$nav = portalPage( $appData, '/portal/projekte' )[1];
$links = portalFind( $nav, '//nav[contains(@class, "crm-nav")]//a[@class="crm-nav__link"]' );
check( 'a member gets the five areas as links, in the order Meine Daten, Projekte, Upload, Belege, Termine, each with the name of its page', count( $links ) === 5
	&& array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), $links ) === [ '/portal/meine-daten', '/portal/projekte', '/portal/upload', '/portal/belege', '/portal/termine' ]
	&& array_filter( $links, static fn( \DOMElement $link ): bool => trim( $link->textContent ) === '' || str_contains( $link->textContent, '[[' ) ) === [] );
check( '...the nav is a nav with a label, and the area the request is in is marked: aria-current="page" on that link and on no other', portalFind( $nav, '//nav[@aria-label]' ) !== [] && count( portalFind( $nav, '//a[@aria-current]' ) ) === 1
	&& portalFind( $nav, '//a[@aria-current]' )[0]->getAttribute( 'href' ) === '/portal/projekte' && portalFind( $nav, '//a[@aria-current]' )[0]->getAttribute( 'aria-current' ) === 'page' );
$marked = static fn( string $uri ): array => array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( portalPage( $appData, $uri )[1], '//nav//a[@aria-current="page"]' ) );
check( '...a prefix does it for the page of a project and for every query; the start has none marked; each area marks itself', $marked( '/portal/projekte?projekt='. $p1 ) === [ '/portal/projekte' ] && $marked( '/portal/projekte?review='. $song['id'] ) === [ '/portal/projekte' ] && $marked( '/portal' ) === []
	&& $marked( '/portal/meine-daten?state=saved' ) === [ '/portal/meine-daten' ] && $marked( '/portal/upload' ) === [ '/portal/upload' ] && $marked( '/portal/belege' ) === [ '/portal/belege' ] && $marked( '/portal/termine' ) === [ '/portal/termine' ] );
ninoVisit( $appData, '/portal/projekte/unterseite' );
check( '...a path below an area marks the area too, whatever the page is', array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-nav]' ), '//a[@aria-current="page"]' ) ) === [ '/portal/projekte' ] );
check( '...a class of the shortcode is added to the nav', count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-portal-nav class="wide"]' ), '//nav[@class="crm-nav wide"]' ) ) === 1 );

// --- The projects --------------------------------------------------------------------------------------------------------------

echo "\n[crm-projects]\n";

$asVisitor();
check( 'a visitor is told there is no project', str_contains( \Nino\Html::renderHtml( $appData, '[crm-projects]' ), 'crm-projects--empty' ) === true );
portalSignIn( $appData, $stray );
check( 'a member of no client is told there is none', count( portalFind( \Nino\Html::renderHtml( $appData, '[crm-projects]' ), '//div[contains(@class, "crm-projects--empty")]/p[@class="crm-projects__empty"]' ) ) === 1 );
portalSignIn( $appData, $accounts['ben'] );
$benList = portalPage( $appData, '/portal/projekte' )[1];
check( 'a client with a project sees it, and only its own: the title as a link to the page of the project', portalFind( $benList, '//a[@class="crm-projects__title"]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $p3 && count( portalFind( $benList, '//li[@class="crm-projects__row"]' ) ) === 1 && str_contains( $benList, 'Album 2027' ) === false );

portalSignIn( $appData, $accounts['anna'] );
$list = portalPage( $appData, '/portal/projekte' )[1];
$open = portalFind( $list, '//ul[@class="crm-projects__list"]/li' );
$done = portalFind( $list, '//ul[contains(@class, "crm-projects__list--done")]/li' );
check( 'the list: the open projects first, the newest on top, each a row with its id and state; the done ones in a list of their own under a heading; the project of another client is not there', count( $open ) === 2
	&& array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-project' ), $open ) === [ $p4, $p1 ] && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-state' ), $open ) === [ 'open', 'open' ]
	&& count( $done ) === 1 && $done[0]->getAttribute( 'data-project' ) === $p2 && $done[0]->getAttribute( 'data-state' ) === 'done' && count( portalFind( $list, '//h3[@class="crm-projects__heading"]' ) ) === 1
	&& str_contains( $list, 'Podcast B' ) === false && portalFind( $list, '//h3[@class="crm-projects__heading"]/following-sibling::ul[1]' )[0]->getAttribute( 'class' ) === 'crm-projects__list crm-projects__list--done' );
$row = $open[1];
$rowHtml = $row->ownerDocument->saveHTML( $row );
check( '...a row has the title as a link to the page, the day it began, the counts of files and of released documents - the files the member may see only (4 of the 6: the three visible ones and the member\'s own)', portalText( $rowHtml, '//a[@class="crm-projects__title"]' ) === 'Album 2027'
	&& portalFind( $rowHtml, '//a[@class="crm-projects__title"]/@href' )[0]->nodeValue === '/portal/projekte?projekt='. $p1 && portalFind( $rowHtml, '//time/@datetime' )[0]->nodeValue === '2026-10-01'
	&& portalText( $rowHtml, '//span[contains(@class, "crm-projects__count--files")]/span' ) === '4' && portalText( $rowHtml, '//span[contains(@class, "crm-projects__count--documents")]/span' ) === '4' );
check( '...a project without a folder has no count of files, one without documents says 0', portalFind( $open[0]->ownerDocument->saveHTML( $open[0] ), '//span[contains(@class, "crm-projects__count--files")]' ) === [] && portalText( $open[0]->ownerDocument->saveHTML( $open[0] ), '//span[contains(@class, "crm-projects__count--documents")]/span' ) === '0' );
$wordOf = static fn( string $html, string $kind ): string => trim( (string) preg_replace( '/^\d+/', '', portalText( $html, '//span[contains(@class, "crm-projects__count--'. $kind. '")]' ) ) );
$benRow = portalFind( $benList, '//li[@class="crm-projects__row"]' )[0];
check( '...the word of a count follows its number: one file and one document are said in the singular, four in the plural', portalText( $benRow->ownerDocument->saveHTML( $benRow ), '//span[contains(@class, "crm-projects__count--files")]/span' ) === '1' && portalText( $benRow->ownerDocument->saveHTML( $benRow ), '//span[contains(@class, "crm-projects__count--documents")]/span' ) === '1'
	&& $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) !== '' && $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) !== $wordOf( $rowHtml, 'files' ) && $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'documents' ) !== $wordOf( $rowHtml, 'documents' ) && portalLeft( $list ) === false );

$wordsOfPortal = (array) require $root. '/app/Crm/Portal/text/de_DE.php';
check( '...and the singular is the word of the one, the plural the word of the many, as the words file has them', $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'files' ) === $wordsOfPortal['[[/project/crm-portal/projects/file-one]]'] && $wordOf( $rowHtml, 'files' ) === $wordsOfPortal['[[/project/crm-portal/projects/file-many]]']
	&& $wordOf( $benRow->ownerDocument->saveHTML( $benRow ), 'documents' ) === $wordsOfPortal['[[/project/crm-portal/projects/document-one]]'] );

// the start page: the open ones, at most five
$short = \Nino\Html::renderHtml( $appData, '[crm-projects open="1" short="1"]' );
check( 'the start page lists the open projects only: the done one is left out, and no heading', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-project' ), portalFind( $short, '//li[@class="crm-projects__row"]' ) ) === [ $p4, $p1 ] && portalFind( $short, '//h3' ) === [] );
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
check( 'the page of a project is the page, not the list: one article for the project, the list is gone', $page[0] === 200 && count( $head ) === 1 && $head[0]->getAttribute( 'data-project' ) === $p1 && $head[0]->getAttribute( 'data-state' ) === 'open' && portalFind( $html, '//ul[@class="crm-projects__list"]' ) === [] );
check( '...the head: the title, the description with its line breaks as <br> and every character escaped (markup and a shortcode in it are text), the day it began, its state, and the way back to the list', portalText( $html, '//h3[@class="crm-project__title"]' ) === 'Album 2027'
	&& str_contains( $html, 'Aufnahme &lt;b&gt;und&lt;/b&gt;<br>Mix &#91;members-name]' ) === true && portalFind( $html, '//p[@class="crm-project__back"]/a/@href' )[0]->nodeValue === '/portal/projekte' && portalFind( $html, '//div[@class="crm-project__head"]//time/@datetime' )[0]->nodeValue === '2026-10-01'
	&& portalText( $html, '//span[@class="crm-project__state"]' ) !== '' && portalText( $html, '//span[@class="crm-project__state"]' ) !== portalText( portalPage( $appData, '/portal/projekte?projekt='. $p2 )[1], '//span[@class="crm-project__state"]' ) );
$sections = portalFind( $html, '//section[contains(@class, "crm-project__section")]' );
check( '...the head is no <header>: the stylesheet of the kernel draws every header of the page as the head of the site, so no component of the portal writes one (nor a footer or a main)', portalFind( $html, '//article//header | //article//footer | //article//main' ) === [] && portalFind( $list, '//div[contains(@class, "crm-projects")]//header | //div[contains(@class, "crm-projects")]//footer' ) === [] );
check( '...then four sections, each with a heading: Angebot, Dateien, Belege, Upload', array_map( static fn( \DOMElement $section ): string => preg_replace( '/^.*--/', '', $section->getAttribute( 'class' ) ), $sections ) === [ 'offer', 'files', 'documents', 'upload' ]
	&& array_filter( $sections, static fn( \DOMElement $section ): bool => $section->getElementsByTagName( 'h4' )->length !== 1 || trim( $section->getElementsByTagName( 'h4' )->item( 0 )->textContent ) === '' ) === [] && portalLeft( $html ) === false );

// the offers
$offers = portalFind( $html, '//section[contains(@class, "crm-project__section--offer")]//div[@class="crm-offer"]' );
check( 'Angebot: the released offers of the project, newest first - not the one that is not released, not the offer of another project, not another client\'s', array_map( static fn( \DOMElement $offer ): string => $offer->getAttribute( 'data-document' ), $offers ) === [ (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ]
	&& str_contains( $html, 'Nicht freigegeben' ) === false && str_contains( $html, 'Andere Seite' ) === false && str_contains( $html, 'Fremdes Angebot' ) === false );
$validWas = Db::row( $appData, 'SELECT valid_until FROM documents WHERE id = :id', [ 'id' => $offerReverse['id'] ] )['valid_until'];
Db::exec( $appData, 'UPDATE documents SET valid_until = \'2020-01-01\' WHERE id = :id', [ 'id' => $offerReverse['id'] ] );
$expiredOffers = portalFind( portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1], '//div[@class="crm-offer"]' );
check( '...an offer that is open or sent and past its day says it is expired, as the list of documents does', array_map( static fn( \DOMElement $offer ): string => $offer->getAttribute( 'data-state' ), $expiredOffers )[1] === 'expired' );
Db::exec( $appData, 'UPDATE documents SET valid_until = :v WHERE id = :id', [ 'v' => $validWas, 'id' => $offerReverse['id'] ] );
$offerHtml = $offers[0]->ownerDocument->saveHTML( $offers[0] );
$lineRows = portalFind( $offerHtml, '//li[@class="crm-offer__line"]' );
check( '...an offer: its number, the day, "gültig bis" with its day, its state in words, and the download of the PDF', portalText( $offerHtml, '//h5[@class="crm-offer__title"]' ) === $offerRegular['number'] && portalFind( $offerHtml, '//time[@class="crm-offer__date"]/@datetime' )[0]->nodeValue === '2026-10-05'
	&& portalFind( $offerHtml, '//span[@class="crm-offer__valid"]/time/@datetime' )[0]->nodeValue === '2026-12-31' && portalText( $offerHtml, '//span[@class="crm-offer__valid"]/time' ) === '31.12.2026' && portalText( $offerHtml, '//span[@class="crm-offer__state"]' ) !== ''
	&& portalFind( $offerHtml, '//a[@class="crm-offer__download"]/@href' )[0]->nodeValue === '/.crm/pdf/'. $offerRegular['id']. '?download=1' && count( portalFind( $html, '//span[@class="crm-offer__valid"]' ) ) === 3 );
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
check( '...no offer says a thing of "Auftrag erteilen": there is no form and no button in the section', portalFind( $html, '//section[contains(@class, "crm-project__section--offer")]//form | //section[contains(@class, "crm-project__section--offer")]//button' ) === [] );

// the files
$rows = portalFind( $html, '//section[contains(@class, "crm-project__section--files")]//li[@class="crm-files__row"]' );
$names = array_map( static fn( \DOMElement $row ): string => trim( $row->getElementsByTagName( 'a' )->item( 0 )->textContent ), $rows );
check( 'Dateien: the files the member may see (Files::allowed) - the visible ones and the member\'s own, not the hidden ones of the owner or of another account - the newest first', $names === [ 'Song.mp3', 'Meins.wav', 'Clip.mp4', 'Notizen.pdf' ] && str_contains( $html, 'Geheim.mp3' ) === false && str_contains( $html, 'Carl.mp3' ) === false );
$rowHtmls = array_map( static fn( \DOMElement $row ): string => $row->ownerDocument->saveHTML( $row ), $rows );
check( '...a row: the name as a link to the file, its size, its day, and the download as the Files browser has it (?download=1)', portalFind( $rowHtmls[0], '//a[@class="crm-files__name"]/@href' )[0]->nodeValue === '/.files/'. $song['id'] && portalFind( $rowHtmls[0], '//a[@class="crm-files__download"]/@href' )[0]->nodeValue === '/.files/'. $song['id']. '?download=1'
	&& portalText( $rowHtmls[0], '//span[@class="crm-files__size"]' ) !== '' && str_contains( portalText( $rowHtmls[0], '//span[@class="crm-files__size"]' ), '[[' ) === false && portalFind( $rowHtmls[3], '//time/@datetime' )[0]->nodeValue === '2026-01-01T09:00:00Z' && portalText( $rowHtmls[3], '//time' ) === '01.01.2026' && $rows[0]->getAttribute( 'data-files-mime' ) === 'audio/mpeg' );
check( '...a file younger than 14 days is marked "neu", an older one is not', array_map( static fn( string $row ): bool => portalFind( $row, '//span[@class="crm-files__new"]' ) !== [], $rowHtmls ) === [ true, true, true, false ] );
check( '...with Review on, "Anhören" is offered for audio and video only: for the mp3, the wav and the mp4, not for the pdf; it leads to the same page with &review=<file>', array_map( static fn( string $row ): string => portalFind( $row, '//a[@class="crm-files__listen"]' ) === [] ? '' : portalFind( $row, '//a[@class="crm-files__listen"]/@href' )[0]->nodeValue, $rowHtmls )
	=== [ '/portal/projekte?projekt='. $p1. '&review='. $song['id']. '#projekt-files', '/portal/projekte?projekt='. $p1. '&review='. $mine['id']. '#projekt-files', '/portal/projekte?projekt='. $p1. '&review='. $clip['id']. '#projekt-files', '' ] );
check( '...without ?review= there is no player on the page', portalFind( $html, '//div[contains(@class, "review-player")]' ) === [] );

\Nino\Features::deactivate( $appData, 'review' );
$noReview = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1];
check( 'with Review off there is no link that listens, and a file asked for with ?review= is not played', portalFind( $noReview, '//a[@class="crm-files__listen"]' ) === [] && portalFind( $noReview, '//div[contains(@class, "review-player")]' ) === [] && count( portalFind( $noReview, '//li[@class="crm-files__row"]' ) ) === 4 );
\Nino\Features::activate( $appData, 'review' );

$played = portalPage( $appData, '/portal/projekte?projekt='. $p1. '&review='. $song['id'] )[1];
check( 'with ?review=<file> the player of Review stands above the list: the audio element with the file as its source, and the list is still there', count( portalFind( $played, '//section[contains(@class, "crm-project__section--files")]//div[contains(@class, "review-player")]' ) ) === 1
	&& portalFind( $played, '//audio/@src | //audio/source/@src' )[0]->nodeValue === '/.files/'. $song['id'] && count( portalFind( $played, '//li[@class="crm-files__row"]' ) ) === 4
	&& count( portalFind( $played, '//div[contains(@class, "review-player")]/following::ul[@class="crm-files__list"]' ) ) === 1 );
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

	return count( portalFind( $answer, '//p[@class="crm-projects__unknown"][@role="status"]' ) ) === 1 && portalFind( $answer, '//article[contains(@class, "crm-project")]' ) === [] && count( portalFind( $answer, '//li[@class="crm-projects__row"]' ) ) === 3
		&& str_contains( $answer, 'Podcast B' ) === false && str_contains( $answer, 'Fremdes Angebot' ) === false;
};
check( 'the project of another client is the list with the sentence that the project is unknown - and nothing of that project', $unknownOf( 'projekt='. $p3 ) === true );
check( '...so is a project nobody has, an id with a path in it, one with markup in it and one longer than an id can be', $unknownOf( 'projekt=00099' ) === true && $unknownOf( 'projekt=..%2F'. $p1 ) === true && $unknownOf( 'projekt=%3Cb%3E' ) === true && $unknownOf( 'projekt='. str_repeat( 'a', 101 ) ) === true );
$ignored = ninoWarnings();
$arrayQuery = portalPage( $appData, '/portal/projekte?projekt[]='. $p1 )[1];
check( '...and a query that is not a string is no project: the plain list, no sentence, no error', $arrayQuery !== '' && portalFind( $arrayQuery, '//p[@class="crm-projects__unknown"]' ) === [] && count( portalFind( $arrayQuery, '//li[@class="crm-projects__row"]' ) ) === 3 && ninoWarnings() === [] );

// the files of a project that has no folder, and where Files is off
$noFolder = portalPage( $appData, '/portal/projekte?projekt='. $p4 )[1];
$filesOnly = static fn( string $html ): string => portalText( $html, '//section[contains(@class, "crm-project__section--files")]' );
check( 'a project without a folder says so in its Dateien section: a sentence and no list', portalFind( $noFolder, '//section[contains(@class, "crm-project__section--files")]/p[@class="crm-project__message"]' ) !== [] && portalFind( $noFolder, '//section[contains(@class, "crm-project__section--files")]//li' ) === [] );
$folderless = $filesOnly( $noFolder );
\Nino\Features::deactivate( $appData, 'review' );
\Nino\Features::deactivate( $appData, 'files' );
$filesOff = portalPage( $appData, '/portal/projekte?projekt='. $p1 )[1];
check( '...with Files off the section says so, in another sentence, and the page is still the page of the project', portalFind( $filesOff, '//section[contains(@class, "crm-project__section--files")]/p[@class="crm-project__message"]' ) !== [] && $filesOnly( $filesOff ) !== '' && $filesOnly( $filesOff ) !== $folderless
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
$documentRows = portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//li[@class="crm-documents__row"]' );
check( 'Belege: the released documents of the project, of every category, the newest first - the rows of [crm-documents] - and none of another project or of none', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-document' ), $documentRows )
	=== [ (string) $invoiceP1['id'], (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ] && array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-category' ), $documentRows ) === [ 'invoice', 'offer', 'offer', 'offer' ]
	&& portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//a[@class="crm-documents__download"]' )[0]->getAttribute( 'href' ) === '/.crm/pdf/'. $invoiceP1['id']. '?download=1' && portalFind( $html, '//section[contains(@class, "crm-project__section--documents")]//*[contains(@class, "crm-documents__project")]' ) === [] );
check( '...a project with no document says so with the sentence of the list', portalFind( $noFolder, '//section[contains(@class, "crm-project__section--documents")]//p[@class="crm-documents__empty"]' ) !== [] );
check( 'Upload: one sentence and the link to /portal/upload', portalFind( $html, '//section[contains(@class, "crm-project__section--upload")]//a/@href' )[0]->nodeValue === '/portal/upload' && portalFind( $html, '//section[contains(@class, "crm-project__section--upload")]//form' ) === [] );

// --- [crm-documents]: the project -----------------------------------------------------------------------------------------------

echo "\n[crm-documents] and the project\n";

$all = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
$projectCell = static fn( string $html, int $id ): array => portalFind( $html, '//li[@data-document="'. $id. '"]/*[contains(@class, "crm-documents__project")]' );
check( 'every row has the cell of its project: a link to the page of the project, with its title, where the document has one - and an empty cell where it has none', $projectCell( $all, $invoiceP1['id'] )[0]->nodeName === 'a' && $projectCell( $all, $invoiceP1['id'] )[0]->getAttribute( 'href' ) === '/portal/projekte?projekt='. $p1
	&& trim( $projectCell( $all, $invoiceP1['id'] )[0]->textContent ) === 'Album 2027' && $projectCell( $all, $invoiceP2['id'] )[0]->getAttribute( 'href' ) === '/portal/projekte?projekt='. $p2 && count( $projectCell( $all, $invoiceA['id'] ) ) === 1
	&& trim( $projectCell( $all, $invoiceA['id'] )[0]->textContent ) === '' && $projectCell( $all, $invoiceA['id'] )[0]->getAttribute( 'href' ) === '' && count( portalFind( $all, '//li[@class="crm-documents__row"]' ) ) === count( portalFind( $all, '//li/*[contains(@class, "crm-documents__project")]' ) ) );
Db::exec( $appData, 'UPDATE documents SET project = \'00099\' WHERE id = :id', [ 'id' => $invoiceA['id'] ] );
$gone = \Nino\Html::renderHtml( $appData, '[crm-documents]' );
check( 'a document whose project is gone from the type has the empty cell, not a link with no title', portalFind( $gone, '//li[@data-document="'. $invoiceA['id']. '"]//a[contains(@class, "crm-documents__project")]' ) === [] && count( $projectCell( $gone, $invoiceA['id'] ) ) === 1 );
Db::exec( $appData, "UPDATE documents SET project = '' WHERE id = :id", [ 'id' => $invoiceA['id'] ] );
$one = \Nino\Html::renderHtml( $appData, '[crm-documents project="'. $p1. '"]' );
check( 'project="<id>" lists the documents of that project and none other, and leaves the cell of the project out', array_map( static fn( \DOMElement $row ): string => $row->getAttribute( 'data-document' ), portalFind( $one, '//li[@class="crm-documents__row"]' ) ) === [ (string) $invoiceP1['id'], (string) $offerRegular['id'], (string) $offerReverse['id'], (string) $offerSmall['id'] ]
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
check( 'a group without an upload folder reads the sentence that the folder has to be made: a block of its own, no list, and no form of Files on the page', portalFind( $noUpload, '//div[contains(@class, "crm-uploads--empty")]/p[@class="crm-uploads__empty"]' ) !== [] && portalFind( $noUpload, '//ul' ) === []
	&& portalFind( portalPage( $appData, '/portal/upload' )[1], '//form[contains(@class, "files-upload")]' ) === [] && str_contains( $noUpload, '[[' ) === false );
$store::markUpload( $appData, $folderUp['id'], true );
$uploads = \Nino\Html::renderHtml( $appData, '[crm-uploads]' );
$uploadRows = portalFind( $uploads, '//li[@class="crm-files__row"]' );
check( 'a group with an upload folder: its files the member may see, the member\'s own among them, not another account\'s hidden ones, the newest first - name, size, day and the download', array_map( static fn( \DOMElement $row ): string => trim( $row->getElementsByTagName( 'a' )->item( 0 )->textContent ), $uploadRows ) === [ 'Eingang-eigen.txt', 'Eingang-sichtbar.txt' ]
	&& str_contains( $uploads, 'Eingang-carl.txt' ) === false && portalFind( $uploads, '//li[1]//a[@class="crm-files__download"]/@href' )[0]->nodeValue === '/.files/'. $upOwn['id']. '?download=1' && portalText( $uploads, '//li[1]//span[@class="crm-files__size"]' ) !== ''
	&& portalFind( $uploads, '//li[1]//time/@datetime' )[0]->nodeValue === $upOwn['created'] && portalFind( $uploads, '//a[@class="crm-files__listen"]' ) === [] );
check( '...the list says that uploads are temporary (a note, in its block)', portalText( $uploads, '//p[@class="crm-uploads__note"]' ) !== '' && portalText( $uploads, '//h3[@class="crm-uploads__heading"]' ) !== '' );
$uploadPage = portalPage( $appData, '/portal/upload' )[1];
check( 'the page Upload has the form of Files for the folder of the group - posting to /.files/upload with the folder - and the list under it', count( portalFind( $uploadPage, '//form[contains(@class, "files-upload")]' ) ) === 1 && portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/@action' )[0]->nodeValue === '/.files/upload'
	&& portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/input[@name="folder"]/@value' )[0]->nodeValue === $folderUp['id'] && count( portalFind( $uploadPage, '//form[contains(@class, "files-upload")]/following::div[contains(@class, "crm-uploads")]' ) ) === 1 && substr_count( $uploadPage, 'class="crm-nav__link"' ) === 5 );
$noEmpty = $put( $folderUp, 'Zusatz.txt', 'txt', 'text/plain', true, $owner, $ago( 10 ) );
check( 'a file put there later is in the list at once, on top', trim( portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//li[@class="crm-files__row"]' )[0]->getElementsByTagName( 'a' )->item( 0 )->textContent ) === 'Zusatz.txt' );
portalSignIn( $appData, $accounts['ben'] );
check( 'a client whose group has no upload folder reads the sentence, whatever another group has', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//div[contains(@class, "crm-uploads--empty")]' ) !== [] );
portalSignIn( $appData, $stray );
check( 'a member of no group too', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//div[contains(@class, "crm-uploads--empty")]' ) !== [] );
portalSignIn( $appData, $accounts['anna'] );
\Nino\Features::deactivate( $appData, 'review' );
\Nino\Features::deactivate( $appData, 'files' );
check( 'with Files off the block says so', portalFind( \Nino\Html::renderHtml( $appData, '[crm-uploads]' ), '//div[contains(@class, "crm-uploads--empty")]' ) !== [] );
\Nino\Features::activate( $appData, 'files' );
\Nino\Features::activate( $appData, 'review' );

// --- The page Termine -------------------------------------------------------------------------------------------------------------

echo "\nThe page Termine\n";

$dates = portalPage( $appData, '/portal/termine' )[1];
check( 'while there is no date, the page says so with the sentence of Events', portalFind( $dates, '//p[@class="events-empty"]' ) !== [] && portalFind( $dates, '//time[contains(@class, "events-when")]' ) === [] && str_contains( $dates, '[events' ) === false );
$soon = static fn( int $days ): string => date( 'Y-m-d\TH:i', strtotime( '+'. $days. ' days 10:00' ) );
\Nino\Elements::insertElement( $appData, '/events/aufnahme-a', [ 'title' => 'Aufnahme Gruppe A', 'start' => $soon( 12 ), 'end' => date( 'Y-m-d\TH:i', strtotime( '+12 days 12:00' ) ), 'place' => 'Studio 1', 'group' => $a['group'] ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/aufnahme-b', [ 'title' => 'Aufnahme Gruppe B', 'start' => $soon( 13 ), 'group' => $b['group'] ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/offen', [ 'title' => 'Offener Termin', 'start' => $soon( 20 ) ], 'de_DE' );
\Nino\Elements::insertElement( $appData, '/events/vorbei', [ 'title' => 'Vergangener Termin', 'start' => date( 'Y-m-d\TH:i', strtotime( '-30 days 10:00' ) ), 'group' => $a['group'] ], 'de_DE' );
$dates = portalPage( $appData, '/portal/termine' )[1];
check( 'the page lists the coming dates of the client\'s group and the dates for everyone - not those of another group, not the ones that are over: a title, the time as a chip, the place, the link to the calendar file', str_contains( $dates, 'Aufnahme Gruppe A' ) === true && str_contains( $dates, 'Offener Termin' ) === true
	&& str_contains( $dates, 'Aufnahme Gruppe B' ) === false && str_contains( $dates, 'Vergangener Termin' ) === false && count( portalFind( $dates, '//time[contains(@class, "events-when")]' ) ) === 2 && str_contains( $dates, 'Studio 1' ) === true
	&& array_map( static fn( \DOMElement $link ): string => $link->getAttribute( 'href' ), portalFind( $dates, '//a[contains(@class, "events-ics")]' ) ) === [ '/.events/aufnahme-a.ics', '/.events/offen.ics' ] );
check( '...with the link to the feed of the member (the token of the account is in it) and the navigation above, the area marked', count( portalFind( $dates, '//a[contains(@class, "events-feed")]' ) ) === 1 && preg_match( '~\?k=[A-Za-z0-9_-]+$~', portalFind( $dates, '//a[contains(@class, "events-feed")]/@href' )[0]->nodeValue ) === 1
	&& portalFind( $dates, '//nav//a[@aria-current="page"]/@href' )[0]->nodeValue === '/portal/termine' && portalLeft( $dates ) === false );
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
$inputs = portalFind( $data, '//form[@class="crm-data"]//input[@name]' );
$values = array_column( array_map( static fn( \DOMElement $input ): array => [ $input->getAttribute( 'name' ), $input->getAttribute( 'value' ) ], $inputs ), 1, 0 );
check( 'the form posts to /.crm/daten and has the fields Firma, Name, Straße, PLZ, Ort, Land, Telefon, USt-IdNr. with the values of the client - and the csrf token', portalFind( $data, '//form[@class="crm-data"]/@action' )[0]->nodeValue === '/.crm/daten' && portalFind( $data, '//form[@class="crm-data"]/@method' )[0]->nodeValue === 'post'
	&& array_keys( $values ) === [ '_csrf', 'company', 'name', 'street', 'zip', 'city', 'country', 'phone', 'vat_id' ] && $values['_csrf'] === \Nino\Csrf::getToken( $appData )
	&& array_diff_key( $values, [ '_csrf' => 1 ] ) === array_intersect_key( $client, array_flip( [ 'company', 'name', 'street', 'zip', 'city', 'country', 'phone', 'vat_id' ] ) ) );
check( '...every value is text: markup and a shortcode in the company are escaped, and each field has its label and the length the client takes', str_contains( $data, 'Nord &quot;A&amp;B&quot; &lt;x&gt; &#91;members-name]' ) === true && str_contains( $data, '<x>' ) === false
	&& count( portalFind( $data, '//form[@class="crm-data"]//label[@for]' ) ) === 9 && portalFind( $data, '//input[@name="country"]/@maxlength' )[0]->nodeValue === '2' && portalFind( $data, '//input[@name="company"]/@maxlength' )[0]->nodeValue === '120' && portalFind( $data, '//input[@name="phone"]/@type' )[0]->nodeValue === 'tel' );
$email = portalFind( $data, '//form[@class="crm-data"]//input[@readonly]' );
check( '...the e-mail of the account is shown and cannot be sent: readonly, no name, with its own sentence', count( $email ) === 1 && $email[0]->getAttribute( 'value' ) === 'anna@example.org' && $email[0]->getAttribute( 'name' ) === '' && portalFind( $data, '//*[@id="crm-data-email-hint"]' ) !== [] && portalFind( $data, '//form[@class="crm-data"]//button[@type="submit"]' ) !== [] );
check( '...no state and no error before anything was posted', portalFind( $data, '//*[contains(@class, "crm-data__state") or contains(@class, "crm-data__error")]' ) === [] && portalFind( $data, '//*[@aria-invalid]' ) === [] );
$saved = portalPage( $appData, '/portal/meine-daten?state=saved' )[1];
$expired = portalPage( $appData, '/portal/meine-daten?state=expired' )[1];
$invalid = portalPage( $appData, '/portal/meine-daten?state=invalid&field=zip' )[1];
check( 'the state of the query is said above the form: saved and expired as a status, invalid as an alert that names the field - and the field says it beside itself, marked invalid and described by its sentence', portalFind( $saved, '//p[@role="status"][contains(@class, "crm-data__state--saved")]' ) !== [] && portalFind( $expired, '//p[@role="status"][contains(@class, "crm-data__state--expired")]' ) !== []
	&& portalText( $saved, '//p[contains(@class, "crm-data__state")]' ) !== portalText( $expired, '//p[contains(@class, "crm-data__state")]' ) && count( portalFind( $invalid, '//p[@role="alert"][contains(@class, "crm-data__state--invalid")]' ) ) === 1
	&& str_contains( portalText( $invalid, '//p[@role="alert"]' ), portalText( $invalid, '//label[@for="crm-data-zip"]' ) ) === true && portalFind( $invalid, '//input[@name="zip"]/@aria-invalid' )[0]->nodeValue === 'true' && portalFind( $invalid, '//input[@name="zip"]/@aria-describedby' )[0]->nodeValue === 'crm-data-zip-error'
	&& portalText( $invalid, '//span[@id="crm-data-zip-error"]' ) !== '' && count( portalFind( $invalid, '//*[@aria-invalid]' ) ) === 1 && count( portalFind( $invalid, '//span[contains(@class, "crm-data__error")]' ) ) === 1 );
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
check( 'the title of a project is text in its row of the list and in the head of its page', $isText( $oddList, '//li[@data-project="'. $pOdd. '"]//a[@class="crm-projects__title"]' ) === true && $isText( $oddPage, '//h3[@class="crm-project__title"]' ) === true
	&& portalFind( $oddList, '//li[@data-project="'. $pOdd. '"]//i' ) === [] && portalFind( $oddPage, '//h3[@class="crm-project__title"]//i' ) === [] );
check( '...so is the title of an offer, the project in the cell of the documents and the name of a file in its row', $isText( $oddPage, '//div[@data-document="'. $offerOdd['id']. '"]//h5' ) === true && $isText( $oddBelege, '//li[@data-document="'. $offerOdd['id']. '"]//a[contains(@class, "crm-documents__project")]' ) === true
	&& portalText( $oddPage, '//li[@data-files-id="'. $oddFile['id']. '"]//a[@class="crm-files__name"]' ) === $odd. '.mp3' && portalFind( $oddPage, '//div[@data-document="'. $offerOdd['id']. '"]//i | //li[@data-files-id="'. $oddFile['id']. '"]//i' ) === [] && portalFind( $oddBelege, '//a[contains(@class, "crm-documents__project")]//i' ) === [] );
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

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

foreach( [ 'members', 'files', 'pdf', 'sign' ] as $key )
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

$panel = [ \Nino\Admin\Admin::class, 'handlePost' ];
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

[ $status, $body ] = $post( 'crm-clients/setup' );
check( 'the portal pages are set up, the page Dokumente among them', $status === 200 && in_array( 'GET://portal/dokumente', $body['added']['routes'], true ) === true && in_array( 'page-portal-documents.tpl', $body['added']['templates'], true ) === true );

// --- Clients, accounts, templates, documents -----------------------------------------------------------------------------

$blocksOf = static fn( array $extra ): array => array_merge( [ [ 'type' => 'html', 'html' => '<h1>[[document.title]] [[document.number]]</h1><p>[[client.company]] [[site.portal]]</p>' ], [ 'type' => 'slot', 'name' => 'lines' ] ], $extra );
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
check( '...the link in each is the absolute address of the page Dokumente, and the greeting names the account', str_contains( $sent[0]['body'], 'href="https://example.org/club/portal/dokumente"' ) === true && str_contains( $sent[0]['body'], 'Hallo Anna Nord,' ) === true && str_contains( $sent[1]['body'], 'Hallo Carl Nord,' ) === true );
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
check( '...Sign keeps the request of this document\'s template with the title, the account, the days and the page the member comes back to: /portal/dokumente', is_array( $request ) && $request['template'] === 'vertrag' && $request['title'] === 'Dienstleistungsvertrag' && $request['account'] === $accounts['anna']['id']
	&& $request['page'] === '/portal/dokumente' && $request['status'] === 'requested' && abs( $request['expires'] - ( time() + 7 * 86400 ) ) < 10 );
check( '...with the data the PDF was rendered with: the same tokens, slots and inputs', $request['data'] === \Nino\Modules\Sign\Store::data( Documents::dataOf( $appData, (array) Documents::get( $appData, $contractA['id'] ) ) ) && $request['data']['inputs'] === [ 'zusatz' => 'Laufzeit ein Jahr' ] );
check( '...and its mail leads back to the page', str_contains( portalMail( $before )['body'], 'return=%2Fportal%2Fdokumente' ) === true );
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
check( 'Sign is handed the client as it was frozen into the contract - Hamburg and Hafenstr. 1 - and not the client as it is now', is_array( $frozenAsk ) && $frozenData['tokens']['client']['city'] === 'Hamburg' && $frozenData['tokens']['client']['street'] === 'Hafenstr. 1'
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
check( '...a visitor never reaches the page: it is behind the gate of Members', portalServe( $appData, '/portal/dokumente' )['statusCode'] === 401 );

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
check( 'where a signature is asked for (requested, viewed) the row links the request of Sign for the member: /.sign?r=<id>', is_array( $waits ) && str_contains( $withLink, 'class="crm-documents__sign" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fdokumente"' ) === true && str_contains( $withLink, '>Jetzt unterschreiben</a>' ) === true );
$index = \Nino\Filesystem::getFileContent( $appData, '/data/sign/requests.php', [] );
$index[$waits['document']['sign_id']]['status'] = 'viewed';
\Nino\Filesystem::putFileContent( $appData, '/data/sign/requests.php', $index );
check( '...a request the member has looked at is still one that waits: the link stays', str_contains( \Nino\Html::renderHtml( $appData, '[crm-documents]' ), 'class="crm-documents__sign" href="/.sign?r='. $waits['document']['sign_id']. '&amp;return=%2Fportal%2Fdokumente"' ) === true );
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
$page = portalPage( $appData, '/portal/dokumente' );
check( 'the page /portal/dokumente is served to the member with the list in it', $page[0] === 200 && str_contains( $page[1], '[crm-documents]' ) === false && str_contains( $page[1], 'class="crm-documents"' ) === true && str_contains( $page[1], 'Deine Dokumente' ) === true && str_contains( $page[1], 'href="/portal"' ) === true );
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
	if( preg_match_all( '~\[\[(/project/crm-documents/[a-z]+/[a-z]+)\]\]~', (string) file_get_contents( $file ), $found ) > 0 )
		$used = array_merge( $used, $found[1] );

check( 'the words of the list are the same keys in German and English, none empty, German with Du, none a key of a text of the project', array_keys( $wordsDe ) === array_keys( $wordsEn ) && array_filter( $wordsDe + $wordsEn, static fn( string $text ): bool => trim( $text ) === '' ) === []
	&& preg_match( '/\b(Sie|Ihre|Ihnen|Ihr)\b/', implode( ' ', $wordsDe ) ) !== 1 && count( $wordsDe ) === 18 && array_filter( array_keys( $wordsDe ), static fn( string $key ): bool => preg_match( '~^\[\[/project/crm-documents/(category|state|list)/[a-z]+\]\]$~D', $key ) !== 1 ) === [] );
check( '...every word the templates ask for is there, and the kinds and the states are all there is', array_diff( array_map( static fn( string $key ): string => '[['. $key. ']]', $used ), array_keys( $wordsDe ) ) === [] && count( $used ) === 4
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

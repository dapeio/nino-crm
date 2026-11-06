<?php
declare(strict_types=1);
/**
 *	Crm\Portal		What the portal pages read of the CRM: the client of the member who is signed in, the head of every portal page
 *								([crm-portal-head]: the bar of the application with the account of the member and Abmelden, the navigation of the portal, then the
 *								breadcrumb where there is a parent, the title of the page or of the project, its lead and the actions of the page), the component [crm-client] that says the company, the component
 *								[crm-documents] that lists the documents released to that client (all of them, or those of one project) with their number, the amount, the
 *								day an invoice is due, the state in the customer's words and - for an open invoice - how to pay it, the navigation
 *								[crm-portal-nav] of the portal (its areas) and the door [crm-portal-door] of the site's start page. The Datenschutz
 *								([crm-privacy], Portal\Privacy), the order under an offer (Portal\Order), the offer itself (Portal\Offer), the start page
 *								(Portal\Start, [crm-portal-start]) and the dates (Portal\Dates, [crm-portal-dates]) are classes of their own. The areas
 *								themselves are the components of Portal\Projects ([crm-projects]), Portal\Files ([crm-uploads]) and Portal\Data
 *								([crm-client-data] and the route that saves it). A visitor has no client, and a page view of a visitor never opens the
 *								database. The lookup is kept for the request and dropped when a member signs in or out.
 *
 *								The gate of the client: before a client reads a page of the portal it has taken note of the Datenschutz it is asked for and given the data an invoice
 *								names it by (Clients::invoiceReady()), in this order. pending() says which step is next; callbackPending() sends a member whose client has one pending to
 *								the page of it (303, with ?weiter=<the page the member wanted>), and onward() is the one place that decides whether a weiter from a request is a path to go on to.
 *
 *								After the sign-in the client lands where something waits for it (landing(): the offer that can be ordered, else the project that was last active), unless it came
 *								for a page of its own; the callback '/members/start' of Members is where callbackStart() says so. A link to a page of the portal - in a mail, in an invitation -
 *								names it as a target() has it, and documentHref() is the address of a document in the portal. The messages the owner wrote - to every client, to this
 *								client - stand over the head of every page of the portal (messages()), once the gate is open; a member puts one away for itself (Portal\Message).
 *
 *								The look of all of it is the portal's own design, portal.css (its head comment says the system); the markup of every
 *								part is a template of templates/, read through template() and filled through render(), which also puts the icons in
 *								(Portal\Icons: a template asks for one with [[icon:<name>]]). The states a customer reads are the customer's words
 *								(customerState()): an invoice that is sent is open to the customer, one past its day is overdue.
 */
namespace Crm;

use Crm\Portal\Icons;

class Portal {

	// The markup of the component, declared once
	public static $html = [
		'client' => '<div class="crm-client[[classes]]">[[company]]</div>',
	];

	private const string CACHE = './crm/portal/client';

	// Set while the list of a folder is drawn: the files that are listed are those the route would refuse to give, too (see callbackFileAllowed())
	public const string LISTING = './crm/portal/listing';

	// The style sheet and the script of the portal, as the bundles of the site name them, and where the markup and the words of the portal are
	public const string STYLE = '/app/Crm/Portal/assets/portal.css';
	public const string SCRIPT = '/app/Crm/Portal/assets/portal.js';
	private const string NAME = '/^[a-z]{1,20}$/D';

	// The longest page to go on to a request may name: a path under /portal and a short query
	private const int ONWARD_MAX = 300;

	// The part of an address that names a place on the page: lower case words and hyphens
	private const string FRAGMENT = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

	// The entries of the navigation, in its order: the last part of the address below /portal ('' is the start page), which is also the key of the page's name, and the icon of the entry
	public const array NAV = [ '' => 'house', 'projekte' => 'folder', 'belege' => 'file-text', 'termine' => 'calendar', 'upload' => 'upload', 'meine-daten' => 'user' ];

	// The entry after them, which the navigation has only while the client has not agreed to the Datenschutz it is asked for (it is reachable from Meine Daten all the time)
	public const string PRIVACY = 'datenschutz';

	// The tone of a pill by the state a customer reads: open (and a project that runs) is the brand's ink, overdue the danger, what is done the success, what is over muted
	public const array TONES = [
		'open' => 'info', 'overdue' => 'danger', 'paid' => 'success', 'accepted' => 'success', 'signed' => 'success', 'agreed' => 'success',
		'expired' => 'muted', 'declined' => 'muted', 'cancelled' => 'muted', 'done' => 'muted', 'new' => 'success', 'pending' => 'warning',
	];

	// What Sign said on the way back (?sign-state=): the tone and the icon of the note over the list - the request is done, or it was declined
	private const array SIGN = [ 'signed' => [ 'success', 'circle-check' ], 'declined' => [ 'muted', 'info' ] ];

	// The id of a request of Sign, as the feature writes it
	private const string SIGN_ID = '/^[a-f0-9]{12}$/D';

	// The pills over the list of documents: the kinds of document each stands for (a credit note is an invoice to the client's eye), in the order the pills come after All and Action needed
	private const array FILTERS = [ 'offer' => [ 'offer' ], 'invoice' => [ 'invoice', 'credit' ], 'contract' => [ 'contract' ], 'privacy' => [ 'privacy' ], 'information' => [ 'information' ] ];

	// The icon of a document, by its kind
	private const array ICONS = [ 'offer' => 'receipt', 'invoice' => 'file-text', 'credit' => 'file-text', 'contract' => 'pen-line', 'privacy' => 'shield-check', 'information' => 'info' ];

	/**
	 *	The client of the signed-in member
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null								As Clients::get() has it; null for a visitor and for an account no client owns
	 */
	public static function client( array &$appData ): ?array {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return null;

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null )
			return null;

		// What is kept is the client of one member: another member in the same process reads its own
		if( ( $appData[self::CACHE]['member'] ?? null ) !== $member['id'] )
			$appData[self::CACHE] = [ 'member' => $member['id'], 'client' => Clients::ofMember( $appData, $member['id'] ) ];

		return $appData[self::CACHE]['client'];
	}

	/**
	 *	What a client has to do before it may read the pages of the portal
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	string										'privacy' while the Datenschutz is set and the client has not taken note of the text of now; else 'data' while the client has not given what an invoice names it by; else ''
	 */
	public static function pending( array &$appData, array $client ): string {

		$standing = Records::privacy( $appData, (int) $client['id'] );

		if( $standing['asked'] === true && $standing['agreed'] === false )
			return 'privacy';

		return Clients::invoiceReady( $client ) === false ? 'data' : '';
	}

	/**
	 *	The page to go on to, as a request names it (?weiter= or the field weiter of a form): a path under /portal and nothing else. A host, a scheme, a second
	 *	slash, a backslash, a dot segment, an encoded character in the path, a control character or a null byte make it none, and so does a path that is not
	 *	lower case words between slashes. The query is the characters a query is written with
	 *
	 *	@param		mixed			$posted
	 *
	 *	@return 	string										The path with its query; '' for none
	 */
	public static function onward( mixed $posted ): string {

		if( is_string( $posted ) === false || $posted === '' || strlen( $posted ) > self::ONWARD_MAX )
			return '';

		[ $path, $query ] = array_pad( explode( '?', $posted, 2 ), 2, '' );

		if( preg_match( '#^/portal(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#D', $path ) !== 1 || ( $query !== '' && preg_match( '/^[A-Za-z0-9_.~%=&+-]+$/D', $query ) !== 1 ) )
			return '';

		return $posted;
	}

	/**
	 *	A page of the portal a link may lead to, as a mail or an invitation names it: what onward() accepts - a path under /portal and a short query - and, after it, the place on the page
	 *	(#beleg-12). Anything else is none
	 *
	 *	@param		mixed			$posted
	 *
	 *	@return 	string										The address as it was given; '' for none
	 */
	public static function target( mixed $posted ): string {

		if( is_string( $posted ) === false || $posted === '' || strlen( $posted ) > self::ONWARD_MAX )
			return '';

		[ $page, $place ] = array_pad( explode( '#', $posted, 2 ), 2, null );

		if( $place !== null && preg_match( self::FRAGMENT, $place ) !== 1 )
			return '';

		return self::onward( $page ) === '' ? '' : $posted;
	}

	/**
	 *	The address of a document in the portal, from the directory the project is served from: its row in the list of documents, or - for a document of a project - its card on the page of the project
	 *
	 *	@param		array 		$document			id and project - as Documents::get() and released() have them
	 *	@param		string		$tab					angebot for an offer (the section Angebot), belege for any other document (the section Belege)
	 *
	 *	@return 	string										A path with its query and the place on the page, not escaped
	 */
	public static function documentHref( array $document, string $tab ): string {

		if( preg_match( Projekte::ID, (string) $document['project'] ) !== 1 )
			return Portal\Order::PAGE_DOCUMENTS. '#beleg-'. $document['id'];

		return Portal\Order::PAGE_PROJECTS. '?projekt='. rawurlencode( (string) $document['project'] ). '&tab='. $tab. '#'. ( $tab === 'angebot' ? 'angebot-' : 'beleg-' ). $document['id'];
	}

	/**
	 *	Where the portal leads a client that has just signed in and asked for no page of its own: nothing while the gate has a step pending (the gate leads it, and the
	 *	client goes on to the start page after it); else the card of the offer that can be ordered - the newest - or, without one, the files of the project that was last active
	 *	and is not done (Projects::latest()); else nothing, the start page
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	string										A path of the portal with its query and place; '' for the start page
	 */
	public static function landing( array &$appData, array $client ): string {

		if( self::pending( $appData, $client ) !== '' )
			return '';

		foreach( Documents::released( $appData, $client['id'] ) as $document )
			if( Documents::orderable( $document ) === true )
				return self::documentHref( $document, 'angebot' );

		$project = Portal\Projects::latest( $appData, $client );

		return $project === null ? '' : Portal\Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $project['id'] ). '&tab=dateien';
	}

	/**
	 *	'/members/start': a client that signs in without a page it asked for lands where landing() says. A member of no client, a sign-in with a target and a sign-in that
	 *	comes when Members is off are left as they are; so is a client whose books cannot be read - the sign-in does not fail for want of a place to land
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$args				account, target and to, as Members fires them
	 *
	 *	@return 	void
	 */
	public static function callbackStart( array &$appData, array &$args ): void {

		// The start page of the portal is no page the client asked for: a bookmark of it, which the gate of Members keeps as the target, is as good as none
		$target = (string) ( $args['target'] ?? '' );

		if( ( $target !== '' && $target !== '/portal' ) || is_string( $args['account']['id'] ?? null ) === false || Crm::featureOn( $appData, 'members' ) === false )
			return;

		try {
			$client = Clients::ofMember( $appData, $args['account']['id'] );
			$to = $client === null ? '' : self::landing( $appData, $client );
		}
		catch( \PDOException $e ) {
			return;
		}

		if( $to !== '' )
			$args['to'] = $to;
	}

	/**
	 *	The address of a step of the gate, with the page to go on to after it
	 *
	 *	@param		string		$step					privacy or data
	 *	@param		string		$onward				As onward() has it; '' for none
	 *
	 *	@return 	string
	 */
	public static function stepAddress( string $step, string $onward = '' ): string {

		return ( $step === 'privacy' ? Portal\Privacy::PAGE : Portal\Data::PAGE ). ( $onward === '' ? '' : '?weiter='. rawurlencode( $onward ) );
	}

	/**
	 *	Where a form of a step goes on to once it has done its work: the step that is pending next, else the page the member wanted
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it, read again after the form changed it
	 *	@param		string		$onward				As onward() has it
	 *
	 *	@return 	string										An address under /portal; '' for the page of the form, which answers with its state
	 */
	public static function proceed( array &$appData, array $client, string $onward ): string {

		$pending = self::pending( $appData, $client );

		if( $pending === '' )
			return $onward;

		// A page to go on to that is the pending step itself is not wrapped in it again
		return explode( '?', $onward, 2 )[0] === self::stepAddress( $pending ) ? $onward : self::stepAddress( $pending, $onward );
	}

	/**
	 *	The page a member is sent on to, named in the words of the pages for the note of a step: the name of the page - and, for a project of the client, its title
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		string		$onward				As onward() has it
	 *
	 *	@return 	string										Markup (a fill, or the escaped title); '' for no page
	 */
	public static function onwardName( array &$appData, array $client, string $onward ): string {

		if( $onward === '' )
			return '';

		[ $path, $query ] = array_pad( explode( '?', $onward, 2 ), 2, '' );
		$area = substr( $path, strlen( '/portal/' ) );

		if( $path !== '/portal' && isset( self::NAV[$area] ) === false && $area !== self::PRIVACY )
			return '';

		parse_str( $query, $asked );

		if( $area === 'projekte' && is_string( $asked['projekt'] ?? null ) === true && preg_match( Projekte::ID, $asked['projekt'] ) === 1 ) {

			$project = Projekte::get( $appData, $asked['projekt'] );

			if( $project !== null && $project['client'] === $client['number'] )
				return '[[/project/crm-portal/gate/project]] <strong>'. \Nino\Modules\Components::escape( (string) $project['title'] ). '</strong>';
		}

		return '<strong>[[/_nino/webpage'. $path. '/name]]</strong>';
	}

	/**
	 *	The note over the page of a step while the step is the pending one: what is asked first, and - where the member wanted a page - where it goes on to after it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		string		$step					privacy or data
	 *
	 *	@return 	string										Markup; '' while the step is not the pending one
	 */
	public static function gateNote( array &$appData, array $client, string $step ): string {

		if( self::pending( $appData, $client ) !== $step )
			return '';

		$name = self::onwardName( $appData, $client, self::onward( self::query( $appData )['weiter'] ?? null ) );

		return self::render( 'note', [
			'[[classes]]' => 'crm-gate crm-gate--'. $step,
			'[[tone]]'    => 'info',
			'[[role]]'    => 'status',
			'[[icon]]'    => $step === 'privacy' ? 'shield-check' : 'id-card',
			'[[text]]'    => '[[/project/crm-portal/gate/'. $step. ']]'. ( $name === '' ? '' : self::render( 'gate-next', [ '[[name]]' => $name ] ) ),
		] );
	}

	/**
	 *	The hidden field a form of a step carries the page to go on to in
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Markup; '' where the address names none
	 */
	public static function onwardField( array &$appData ): string {

		$onward = self::onward( self::query( $appData )['weiter'] ?? null );

		return $onward === '' ? '' : self::render( 'gate-field', [ '[[value]]' => \Nino\Modules\Components::escape( $onward ) ] );
	}

	/**
	 *	'/nino/http/response': the gate of the client. A member whose client has a step pending (pending()) is answered 303 on every page of the portal but the pages
	 *	of the steps themselves - the Datenschutz, and Meine Daten while the data is the step that is next; the sign-out is an endpoint of Members and no page of the
	 *	portal. The Location is the page of the step with ?weiter=<the page the member wanted, path and query> where that is a path under /portal. A client that still
	 *	has to take note of the Datenschutz is sent there from Meine Daten as well. A visitor, a member of no client and a client with nothing pending are not touched,
	 *	and a request that is no GET of a page of the portal never opens the database
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPending( array &$appData, array &$request ): void {

		$route = (string) ( $request['/nino/http/response']['uri'] ?? '' );

		if( ( $route !== '/portal' && str_starts_with( $route, '/portal/' ) === false ) || ( $request['/nino/http/request']['method'] ?? '' ) !== 'GET' || ( $request['/nino/http/response']['statusCode'] ?? 200 ) !== 200 )
			return;

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return;

		$client = self::client( $appData );
		$pending = $client === null ? '' : self::pending( $appData, $client );

		if( $pending === '' || $route === Portal\Privacy::PAGE || ( $route === Portal\Data::PAGE && $pending === 'data' ) )
			return;

		// The page the member wanted: its path and its query, a weiter of its own among them - it is kept on the way to the same place and checked by onward() once more
		$query = $request['/nino/http/request']['query'] ?? [];
		$flat = [];

		foreach( is_array( $query ) === true ? $query : [] as $name => $value )
			if( is_string( $value ) === true && preg_match( '/^[A-Za-z0-9_-]{1,40}$/D', (string) $name ) === 1 )
				$flat[(string) $name] = $value;

		$wanted = self::onward( (string) ( $request['/nino/http/request']['uri'] ?? '' ). ( $flat === [] ? '' : '?'. http_build_query( $flat, '', '&', PHP_QUERY_RFC3986 ) ) );

		// The start page is where the gate leads after its steps anyway: no weiter for it
		\Nino\Http::redirect( $appData, $request, self::stepAddress( $pending, $wanted === '/portal' ? '' : $wanted ), 303 );
	}

	/**
	 *	'/files/allowed': what Files lets a member of a client have, narrowed by what the owner holds back. A file is not for a member whose client has a step of the gate
	 *	pending - the bytes of a project are not given to somebody who has not taken note of the Datenschutz or has not given the data of the invoice -, and a file of the
	 *	folder of a project is not for its client while an offer of the project waits for the order (Projekte::waiting()), inline or as a download. Where no offer waits, the
	 *	folder is open to be played and its files are not to be saved before the owner switched the download of the project on (Projekte::state()): a file the player does not play
	 *	(Review::kind()) is not given at all, inline or saved, and listed only as a name; the files the member put there are the member's own and are given whatever the switch says. The hook only takes away: the member of no client and a file of no project of the client are
	 *	left as Files has them, and an answer of false is never turned into true
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$args				file, member, download and allowed
	 *
	 *	@return 	void
	 */
	public static function callbackFileAllowed( array &$appData, array &$args ): void {

		if( ( $args['allowed'] ?? false ) !== true )
			return;

		$client = self::client( $appData );

		if( $client === null )
			return;

		if( self::pending( $appData, $client ) !== '' ) {
			$args['allowed'] = false;
			return;
		}

		$project = Projekte::ofFolder( $appData, $client['number'], (string) ( $args['file']['folder'] ?? '' ) );

		if( $project === null )
			return;

		$own = ( $args['file']['by']['kind'] ?? '' ) === 'member' && ( $args['file']['by']['id'] ?? '' ) === ( $args['member']['id'] ?? null );

		if( Projekte::waiting( $appData, $project['id'], (int) $client['id'] ) !== null ) {
			$args['allowed'] = false;
			return;
		}

		if( $project['download'] === true || $own === true )
			return;

		// Without the download only what the player plays is open, and only to be played: a file it does not play is neither shown nor saved - the list of the portal asks to see them, to say that the studio releases them
		$plays = Crm::featureOn( $appData, 'review' ) === true && \Nino\Modules\Review::kind( (string) ( $args['file']['mime'] ?? '' ) ) !== '';

		if( ( $args['download'] ?? false ) === true || ( $plays === false && ( $appData[self::LISTING] ?? false ) !== true ) )
			$args['allowed'] = false;
	}

	/**
	 *	Drop the lookup of this request: the client was changed, and a page drawn after it reads the client as it is now
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function forget( array &$appData ): void {

		unset( $appData[self::CACHE] );
	}

	/**
	 *	[crm-client] - the company of the member's client; nothing for a visitor, nothing where the client has no company, and nothing where the
	 *	company is the name the member is greeted with (the greeting says it once)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmClient( array &$appData, array $args ): string {

		$client = self::client( $appData );

		if( $client === null || $client['company'] === '' )
			return '';

		$member = \Nino\Modules\Members::current( $appData );

		if( $member !== null && $client['company'] === ( (string) $member['name'] !== '' ? $member['name'] : $member['email'] ) )
			return '';

		return str_replace(
			[ '[[classes]]', '[[company]]' ],
			[ \Nino\Feature::classes( $args ), \Nino\Modules\Components::escape( $client['company'] ) ],
			self::$html['client']
		);
	}

	/**
	 *	[crm-portal-head] - the head of a portal page: the bar of the application (the name of the site, or its logo, as a link to the start page and
	 *	the h1 of the page, the one the head of the site has; the account of the member as a chip that links to Meine Daten; Abmelden), the navigation of the portal (see
	 *	componentCrmPortalNav()), then the title and the lead of the page (its name and description, as the head of the site reads them). The bar takes the
	 *	place of the head of the site on the page (the style sheet sets that head aside where the bar is). nav="0" leaves the bar and the navigation out,
	 *	for a template that sets the navigation itself. On the page of a project (?projekt=<id>, or ?review=<file> of a
	 *	file of one of the client's projects) the breadcrumb Projekte › the project, the project's title as the title with its state and its start,
	 *	and its description as the lead. With calendar="1" the subscription of the calendar stands among the actions (where Events is on). Nothing
	 *	for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args					calendar ('1': the subscription of the calendar of Events among the actions), nav ('0': no bar and no navigation)
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalHead( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return '';

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null )
			return '';

		self::words( $appData );

		$client = self::client( $appData );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$name = self::nameOf( $member );
		$project = $client === null ? null : Portal\Projects::current( $appData, $client );

		if( $project !== null ) {

			$day = Documents\Render::day( $project['started'] );
			$state = $project['done'] === true ? 'done' : 'open';
			$crumbs = self::render( 'head-crumbs', [ '[[href]]' => $dir. '/portal/projekte', '[[parent]]' => '[[/_nino/webpage/portal/projekte/name]]', '[[here]]' => \Nino\Modules\Components::escape( $project['title'] ) ] );
			$title = \Nino\Modules\Components::escape( $project['title'] );
			$badge = self::render( 'head-badge', [
				'[[tone]]'  => self::tone( $state ),
				'[[state]]' => '[[/project/crm-portal/projects/state-'. $state. ']]',
				'[[approved]]' => Portal\Approval::approved( $appData, $client, $project ) === null ? '' : self::template( 'head-approved' ),
				'[[since]]' => $day === '' ? '' : self::render( 'head-since', [ '[[iso]]' => \Nino\Modules\Components::escape( $project['started'] ), '[[date]]' => \Nino\Modules\Components::escape( $day ) ] ),
			] );
			$lead = $project['description'] === '' ? '' : self::render( 'head-lead', [ '[[text]]' => str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( $project['description'] ) ) ] );
		}
		else {
			$crumbs = '';
			$title = '[[/_nino/webpage[[/nino/http/response/uri]]/title]]';
			$badge = '';
			$lead = self::render( 'head-lead', [ '[[text]]' => '[[/_nino/webpage[[/nino/http/response/uri]]/description]]' ] );
		}

		$bar = '';
		$navigation = '';

		if( ( $args['nav'] ?? '1' ) !== '0' ) {

			$site = self::siteName( $appData );
			$bar = self::render( 'bar', [
				'[[dir]]'      => $dir,
				'[[site]]'     => \Nino\Modules\Components::escape( $site ),
				'[[prefix]]'   => $site === '' ? '' : \Nino\Modules\Components::escape( $site. ' | ' ),
				'[[current]]'  => rtrim( (string) ( \Nino\Http::getRequest( $appData )['/nino/http/request']['uri'] ?? '' ), '/' ) === '/portal/meine-daten' ? ' aria-current="page"' : '',
				'[[initials]]' => \Nino\Modules\Components::escape( self::initials( $name ) ),
				'[[name]]'     => \Nino\Modules\Components::escape( $name ),
			] );
			$navigation = self::componentCrmPortalNav( $appData, [] );
		}

		return $bar. $navigation. ( $client === null ? '' : self::messages( $appData, $client ) ). self::render( 'head', [
			'[[classes]]' => \Nino\Feature::classes( $args ),
			'[[crumbs]]'  => $crumbs,
			'[[title]]'   => $title,
			'[[badge]]'   => $badge,
			'[[lead]]'    => $lead,
			'[[actions]]' => ( $args['calendar'] ?? '0' ) === '1' && Crm::featureOn( $appData, 'events' ) === true ? self::template( 'head-calendar' ) : '',
		] );
	}

	/**
	 *	The name of the site: a text of the project, put in as text - what the page of the site draws as markup is shown as typed here
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										Not escaped; '' where the site has none
	 */
	private static function siteName( array &$appData ): string {

		return str_replace( "\0", '', trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 *	The messages the owner wrote to the client, as notes over the head of a page of the portal: the one to every client first (the setting portal_message), then the one to this
	 *	client (its message). Each says who wrote it - the name of the site - and the day, and holds the text as text: its line breaks are line breaks, and nothing else of it is
	 *	read as markup, a fill or a shortcode. None while the gate has a step pending: the client reads the step first, and the messages after it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	string										Markup; '' for none
	 */
	public static function messages( array &$appData, array $client ): string {

		if( self::pending( $appData, $client ) !== '' )
			return '';

		$member = \Nino\Modules\Members::current( $appData );
		$away = $member === null ? [] : Portal\Message::dismissed( $appData, $member['id'] );
		$site = self::siteName( $appData );
		$html = '';

		foreach( Portal\Message::current( $appData, $client ) as $message ) {

			if( in_array( $message['sha256'], $away, true ) === true )
				continue;

			$day = Documents\Render::day( self::dayOf( $appData, $message['at'] ) );

			$html .= self::render( 'message', [
				'[[from]]' => $site === '' ? '[[/project/crm-portal/message/plain]]' : '[[/project/crm-portal/message/from]] <strong>'. \Nino\Modules\Components::escape( $site ). '</strong>',
				'[[day]]'  => $day === '' ? '' : self::render( 'message-day', [ '[[iso]]' => \Nino\Modules\Components::escape( self::dayOf( $appData, $message['at'] ) ), '[[date]]' => \Nino\Modules\Components::escape( $day ) ] ),
				'[[text]]' => str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( $message['text'] ) ),
				'[[form]]' => $member === null ? '' : Portal\Message::form( $appData, $message['sha256'] ),
			] );
		}

		return $html;
	}

	/**
	 *	The day of a time of the server (UTC) in the timezone of the site: the zone of Events where that is on, else the one php runs in
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$at						Y-m-d\TH:i:s\Z
	 *
	 *	@return 	string										Y-m-d; '' for a text that is no time
	 */
	private static function dayOf( array &$appData, string $at ): string {

		try {
			$zone = Crm::featureOn( $appData, 'events' ) === true ? \Nino\Modules\Events::zone( $appData ) : new \DateTimeZone( date_default_timezone_get() );

			return ( new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $zone )->format( 'Y-m-d' );
		}
		catch( \Exception $e ) {
			return '';
		}
	}

	/**
	 *	[crm-portal-door] - the door of the portal on the start page of the site: the login form of Members for a visitor, and for a member who
	 *	is signed in the way into the portal. Nothing while Members is off
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalDoor( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return '';

		self::words( $appData );

		// The form is the feature's own: what stands here is its shortcode, which the rendering that follows draws
		$inner = \Nino\Modules\Members::current( $appData ) === null ? '[members-login]' : self::render( 'door-enter', [ '[[dir]]' => \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) ) ] );

		return self::render( 'door', [ '[[classes]]' => \Nino\Feature::classes( $args ), '[[inner]]' => $inner ] );
	}

	/**
	 *	[crm-portal-nav] - the navigation of the portal: the areas as links - Portal, Projekte, Belege, Termine, Upload, Meine Daten - the one the request is
	 *	in marked with aria-current. The names are the words of the pages (/_nino/webpage/portal/<area>/name). A link to the Datenschutz stands after the
	 *	areas, with a dot, while the client has not agreed to the text it is asked for. It is the same markup on every window: the style sheet draws it as a
	 *	side bar, a row of pills or a bar of tabs. The account and Abmelden are the bar's (see componentCrmPortalHead()). Nothing for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalNav( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return '';

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null )
			return '';

		self::words( $appData );

		$request = \Nino\Http::getRequest( $appData );
		$path = $request === false ? '' : rtrim( (string) ( $request['/nino/http/request']['uri'] ?? '' ), '/' );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$client = self::client( $appData );
		$areas = self::NAV;

		if( $client !== null && self::pending( $appData, $client ) === 'privacy' )
			$areas[self::PRIVACY] = 'shield-check';

		$items = '';

		foreach( $areas as $area => $icon ) {

			$here = $area === '' ? $path === '/portal' : ( $path === '/portal/'. $area || str_starts_with( $path, '/portal/'. $area. '/' ) === true );

			$items .= self::render( 'nav-item', [
				'[[area]]'    => $area === '' ? 'start' : $area,
				'[[path]]'    => $area === '' ? '' : '/'. $area,
				'[[icon]]'    => $icon,
				'[[dir]]'     => $dir,
				'[[current]]' => $here === true ? ' aria-current="page"' : '',
				'[[dot]]'     => $area === self::PRIVACY ? self::template( 'nav-dot' ) : '',
			] );
		}

		return self::render( 'nav', [
			'[[classes]]' => \Nino\Feature::classes( $args ),
			'[[items]]'   => $items,
		] );
	}

	/**
	 *	The name a member is shown with: the name of the account, the address where there is none; a null byte is left out
	 *
	 *	@param		array 		$member				As Members::current() has it
	 *
	 *	@return 	string
	 */
	public static function nameOf( array $member ): string {

		return str_replace( "\0", '', (string) $member['name'] !== '' ? (string) $member['name'] : (string) $member['email'] );
	}

	/**
	 *	The initials a chip shows for a name: the first letter (with its marks) or digit of the first and of the last word, in capitals where that stays one character; for one word its first letter,
	 *	and for an address its first letter. Not escaped
	 *
	 *	@param		string		$name
	 *
	 *	@return 	string										'?' for a name with no letter in it
	 */
	public static function initials( string $name ): string {

		$words = [];

		// mb_scrub() takes a byte that is no UTF-8 out without a word about it - a character cut off at the end as well
		foreach( preg_split( '/\s+/u', trim( mb_scrub( $name, 'UTF-8' ) ) ) ?: [] as $word )
			if( preg_match( '/\p{L}\p{M}*|\p{N}/u', $word, $found ) === 1 )
				$words[] = $found[0];

		if( $words === [] )
			return '?';

		return implode( '', array_map( static fn( string $letter ): string => mb_strlen( mb_strtoupper( $letter ) ) === mb_strlen( $letter ) ? mb_strtoupper( $letter ) : $letter, count( $words ) === 1 ? $words : [ $words[0], $words[count( $words ) - 1] ] ) );
	}

	/**
	 *	'/nino/navigation/lines': a visitor sees no page of the portal in a menu of the site - every line whose path Members protects is taken out
	 *	while nobody is signed in; a member who is signed in sees them all. The kernel hands the key of the navigation and the lines by reference
	 *	(see \Nino\Modules\Navigation::routeLines(), which needs the kernel that fires the callback)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$payload			nav, lines
	 *
	 *	@return 	void
	 */
	public static function callbackNavigationLines( array &$appData, array &$payload ): void {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) !== null || is_array( $payload['lines'] ?? null ) === false )
			return;

		$payload['lines'] = array_values( array_filter( $payload['lines'], static function( mixed $line ) use ( &$appData ): bool {

			return is_string( $line ) === false || \Nino\Modules\Members::protects( $appData, explode( ':', $line, 2 )[0] ) === false;
		} ) );
	}

	/**
	 *	[crm-documents] - the documents released to the client of the member, the newest first: the kind, the title (the number where there
	 *	is none), the project (its title, a link to the project page; empty where the document has none), the day, the gross amount, the day an
	 *	invoice is due, the state in the customer's words, the download and - where a signature is asked for - the way to it, or - once it is
	 *	signed - the way to the signed file. An offer unfolds to its card with the lines, the sums and - while it can be ordered - the form of the
	 *	order; an open invoice unfolds to how it is paid. With project="<id>" only the documents of that project, and no column for the project.
	 *	A member of no client and a visitor are told there is none. Every value enters the markup escaped
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args					project (the id of a project of the client, '' for all)
	 *
	 *	@return 	string
	 */
	public static function componentCrmDocuments( array &$appData, array $args ): string {

		self::words( $appData );

		$project = trim( (string) ( $args['project'] ?? '' ) );
		$client = self::client( $appData );
		$documents = [];

		if( $client !== null && ( $project === '' || preg_match( Projekte::ID, $project ) === 1 ) )
			$documents = Documents::released( $appData, $client['id'], $project );

		return self::documentList( $appData, $documents, \Nino\Feature::classes( $args ), $project === '', $client );
	}

	/**
	 *	The list of documents as the portal draws it: [crm-documents] and the project page both. With a client the offers in it unfold to their
	 *	card and the form of the order (the list of /portal/belege, where an offer without a project is ordered); without one an offer links the
	 *	section Angebot of its project page (the section Belege of that page). The message of an order is said once at the top where it names no
	 *	document, and in the card of the document it names; what Sign said on the way back is said there too, with the row of its request marked.
	 *	The number of documents stands over the list
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$documents		As Documents::released() has them
	 *	@param		string		$classes			The class attribute's tail, see \Nino\Feature::classes()
	 *	@param		bool			$withProject	Whether each row names its project
	 *	@param		array|null	$client				The client, where an offer in the list may be ordered from it; null for no form
	 *
	 *	@return 	string										The list, or the sentence that there is none
	 */
	public static function documentList( array &$appData, array $documents, string $classes, bool $withProject, ?array $client = null ): string {

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$rows = '';
		$orderable = false;
		$count = 0;
		$kinds = [];
		$due = false;
		$back = self::signReturn( $appData );

		foreach( $withProject === true ? self::arrange( $documents ) : $documents as $document ) {

			$orderable = $orderable === true || Documents::orderable( $document ) === true;
			$row = self::documentRow( $appData, $document, $dir, $withProject, $client, $back );
			$count += $row === '' ? 0 : 1;
			$rows .= $row;

			if( $row !== '' ) {
				$kinds[$document['category']] = true;
				$due = $due === true || self::needsAction( $document ) === true;
			}
		}

		if( $rows === '' )
			return self::render( 'documents-empty', [ '[[classes]]' => $classes, '[[empty]]' => self::emptyState( $appData, 'receipt', '[[/project/crm-documents/list/empty]]', 'crm-documents__empty' ) ] );

		return self::render( 'documents', [
			'[[classes]]' => $classes,
			'[[privacy]]' => $client !== null && $orderable === true && self::pending( $appData, $client ) === 'privacy' ? Portal\Order::privacyHint( $dir ) : '',
			'[[filter]]'  => $withProject === true ? self::filter( $appData, array_keys( $kinds ), $due ) : '',
			'[[state]]'   => $client === null ? '' : Portal\Order::state( $appData ),
			'[[sign]]'    => $client === null || $back['state'] === '' ? '' : self::render( 'sign-state', [ '[[state]]' => $back['state'], '[[tone]]' => self::SIGN[$back['state']][0], '[[icon]]' => self::SIGN[$back['state']][1] ] ),
			'[[count]]'   => $count. ' [[/project/crm-portal/projects/document-'. ( $count === 1 ? 'one' : 'many' ). ']]',
			'[[mode]]'    => $withProject === true ? '' : ' crm-table--project',
			'[[column]]'  => $withProject === true ? self::template( 'documents-column' ) : '',
			'[[rows]]'    => $rows,
		] );
	}

	/**
	 *	Whether a document asks the client for something: an offer that can be ordered, an invoice that is open or overdue
	 *
	 *	@param		array 		$document			As Documents::released() has it
	 *
	 *	@return 	bool
	 */
	public static function needsAction( array $document ): bool {

		return Documents::orderable( $document ) === true || ( $document['category'] === 'invoice' && in_array( self::customerState( $document ), [ 'open', 'overdue' ], true ) === true );
	}

	/**
	 *	The documents in the order the client reads them: first what asks for something - an invoice that is overdue, then an offer that can be ordered, then an invoice that is open -,
	 *	then the information, then the rest; each group by its day, the newest first (and the newest of a day first)
	 *
	 *	@param		array 		$documents		As Documents::released() has them
	 *
	 *	@return 	array											The same documents
	 */
	public static function arrange( array $documents ): array {

		$rank = static function( array $document ): int {

			if( self::needsAction( $document ) === true )
				return $document['category'] === 'invoice' ? ( self::customerState( $document ) === 'overdue' ? 0 : 2 ) : 1;

			return $document['category'] === 'information' ? 3 : 4;
		};

		usort( $documents, static fn( array $one, array $two ): int => [ $rank( $one ), $two['date'], $two['id'] ] <=> [ $rank( $two ), $one['date'], $one['id'] ] );

		return $documents;
	}

	/**
	 *	The pills over the list of documents: All, Action needed (where a document asks for something) and one for each kind of the list. The pill the address names
	 *	(?art=) is the one pressed, All where it names none the list has. The pills stand hidden - the script shows them and filters the rows; without it the list is whole
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$kinds				The kinds of document the list has
	 *	@param		bool			$due					Whether a document of the list asks for something
	 *
	 *	@return 	string										Markup; '' for a list of nothing
	 */
	private static function filter( array &$appData, array $kinds, bool $due ): string {

		$pills = [ 'all' => [] ];

		if( $due === true )
			$pills['due'] = [];

		foreach( self::FILTERS as $name => $members )
			if( array_intersect( $members, $kinds ) !== [] )
				$pills[$name] = $members;

		if( count( $pills ) < 2 )
			return '';

		$query = self::query( $appData );
		$chosen = is_string( $query['art'] ?? null ) === true && isset( $pills[$query['art']] ) === true ? $query['art'] : 'all';
		$html = '';

		foreach( $pills as $name => $members )
			$html .= self::render( 'filter-pill', [
				'[[name]]'    => $name,
				'[[kinds]]'   => $members === [] ? '' : ' data-kinds="'. implode( ' ', $members ). '"',
				'[[pressed]]' => $name === $chosen ? 'true' : 'false',
			] );

		return self::render( 'filter', [ '[[pills]]' => $html ] );
	}

	/**
	 *	What Sign said when it sent the member back to the list (?sign=<request>&sign-state=signed|declined): the state, from the two it knows,
	 *	and the request it is about where the query names one that has the form of an id. A word the feature does not use is no state
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											state ('' for none), id ('' for none)
	 */
	public static function signReturn( array &$appData ): array {

		$query = self::query( $appData );
		$state = is_string( $query['sign-state'] ?? null ) === true && isset( self::SIGN[$query['sign-state']] ) === true ? $query['sign-state'] : '';
		$id = $state !== '' && is_string( $query['sign'] ?? null ) === true && preg_match( self::SIGN_ID, $query['sign'] ) === 1 ? $query['sign'] : '';

		return [ 'state' => $state, 'id' => $id ];
	}

	/**
	 *	One row of the list of documents
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::released() has it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *	@param		bool			$withProject	Whether the row names its project
	 *	@param		array|null	$client				The client, where an offer unfolds to its card and its form
	 *	@param		array 		$back					What Sign said on the way back, as signReturn() has it: the row of that request is marked
	 *
	 *	@return 	string										'' for a document whose names the words do not know
	 */
	private static function documentRow( array &$appData, array $document, string $dir, bool $withProject, ?array $client, array $back ): string {

		$state = self::customerState( $document );

		// The names the module writes are lower case letters; they are the end of the key of a word
		if( preg_match( self::NAME, $document['category'] ) !== 1 || preg_match( self::NAME, $state ) !== 1 )
			return '';

		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$request = preg_match( self::SIGN_ID, $document['sign_id'] ) === 1 ? $document['sign_id'] : '';
		$number = $escape( $document['number'] !== '' ? $document['number'] : $document['title'] );
		$links = self::render( 'document-download', [ '[[dir]]' => $dir, '[[id]]' => (string) $document['id'], '[[number]]' => $number ] );

		// The electronic invoice of a document that has one, next to the PDF
		if( $document['xml'] === true )
			$links .= self::render( 'document-xml', [ '[[dir]]' => $dir, '[[id]]' => (string) $document['id'], '[[number]]' => $number ] );

		if( $request !== '' && in_array( $document['sign_state'], Documents::PENDING, true ) === true )
			$links .= self::render( 'document-sign', [ '[[dir]]' => $dir, '[[request]]' => $request, '[[number]]' => $number ] );
		elseif( $request !== '' && $document['state'] === 'signed' && $document['sign_state'] === 'signed' )
			$links .= self::render( 'document-signed', [ '[[dir]]' => $dir, '[[request]]' => $request, '[[number]]' => $number ] );

		// What the row unfolds to: the card of an offer where it may be ordered here, how an open invoice is paid; a word in the row says so
		$body = '';
		$word = '';

		if( $document['category'] === 'offer' && $client !== null ) {
			$body = Portal\Offer::card( $appData, $client, $document, $dir, 'belege', true, true );
			$word = 'order/fold';
		}
		elseif( $document['category'] === 'offer' && preg_match( Projekte::ID, $document['project'] ) === 1 )
			$links .= self::render( 'document-offer-link', [ '[[dir]]' => $dir, '[[project]]' => $escape( $document['project'] ), '[[id]]' => (string) $document['id'], '[[number]]' => $number ] );
		elseif( $document['category'] === 'invoice' && in_array( $state, [ 'open', 'overdue' ], true ) === true ) {
			$body = self::payBlock( $appData, $document );
			$word = 'pay/fold';
		}

		$project = '';

		if( $withProject === true )
			$project = self::render(
				$document['project_title'] === '' ? 'document-project-none' : ( preg_match( Projekte::ID, $document['project'] ) === 1 ? 'document-project' : 'document-project-text' ),
				[ '[[dir]]' => $dir, '[[id]]' => $escape( $document['project'] ), '[[title]]' => $escape( $document['project_title'] ) ]
			);

		$line = self::render( 'document-line', [
			'[[icon]]'    => self::ICONS[$document['category']] ?? 'file',
			'[[title]]'   => self::documentTitle( $document ),
			'[[kind]]'    => self::kindLine( $document ),
			'[[project]]' => $project,
			'[[iso]]'     => $escape( $document['date'] ),
			'[[date]]'    => $escape( Documents\Render::day( $document['date'] ) ),
			'[[amount]]'  => self::amount( $appData, $document ),
			'[[due]]'     => self::dueCell( $document, $state ),
			'[[tone]]'    => self::tone( $state ),
			'[[word]]'    => self::stateWord( $state ),
			'[[links]]'   => $links,
			'[[toggle]]'  => $body === '' ? '' : self::render( 'document-toggle', [ '[[word]]' => $word, '[[number]]' => $number ] ),
		] );

		return self::render( 'document', [
			'[[id]]'       => (string) $document['id'],
			'[[category]]' => $document['category'],
			'[[due]]'      => self::needsAction( $document ) === true ? ' data-due="1"' : '',
			'[[state]]'    => $state,
			'[[sign]]'     => $back['state'] !== '' && $request !== '' && $request === $back['id'] ? ' data-sign-state="'. $back['state']. '"' : '',
			'[[inner]]'    => $body === '' ? self::render( 'document-plain', [ '[[line]]' => $line ] ) : self::render( 'document-fold', [ '[[open]]' => Portal\Order::named( $appData ) === $document['id'] ? ' open' : '', '[[line]]' => $line, '[[body]]' => $body ] ),
		] );
	}

	/**
	 *	What a document is called: its title where it has one (a contract, the Datenschutz, an information), else its kind and the project it
	 *	belongs to - "Angebot Album 2027" -, and its kind alone where it has no project
	 *
	 *	@param		array 		$document			category, title, project_title
	 *
	 *	@return 	string										Markup: the words as fills, the rest escaped
	 */
	public static function documentTitle( array $document ): string {

		if( $document['title'] !== '' )
			return \Nino\Modules\Components::escape( $document['title'] );

		return '[[/project/crm-documents/category/'. $document['category']. ']]'. ( (string) ( $document['project_title'] ?? '' ) === '' ? '' : ' '. \Nino\Modules\Components::escape( $document['project_title'] ) );
	}

	/**
	 *	The state of a document in the customer's words: an invoice that was sent is open to the customer and one past its day is overdue, an
	 *	offer past its day has run out; a draft is never released, so it never comes here
	 *
	 *	@param		array 		$document			category, state, expired - and due_date, overdue where Documents::released() has them
	 *
	 *	@return 	string										open, overdue, paid, cancelled, accepted, declined, signed or expired; the state as it is for one the words do not know
	 */
	public static function customerState( array $document ): string {

		if( ( $document['expired'] ?? false ) === true )
			return 'expired';

		if( ( $document['overdue'] ?? false ) === true || ( $document['category'] === 'invoice' && Documents::overdue( $document ) === true ) )
			return 'overdue';

		return $document['state'] === 'sent' ? 'open' : (string) $document['state'];
	}

	/**
	 *	The tone of a pill for a state (TONES), info for one the table does not name
	 *
	 *	@param		string		$state
	 *
	 *	@return 	string
	 */
	public static function tone( string $state ): string {

		return self::TONES[$state] ?? 'info';
	}

	/**
	 *	The word of a state as the pill says it: the word of the state alone - an invoice that is overdue says its day in the cell of the day it is due
	 *
	 *	@param		string		$state				As customerState() has it
	 *
	 *	@return 	string										Markup
	 */
	public static function stateWord( string $state ): string {

		return '[[/project/crm-documents/state/'. $state. ']]';
	}

	/**
	 *	The gross amount of a document as the list writes it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			gross, where Documents::released() has it
	 *
	 *	@return 	string										Escaped; '' for a document without lines (a contract, the Datenschutz, an information)
	 */
	public static function amount( array &$appData, array $document ): string {

		if( in_array( $document['category'], Documents::PLAIN, true ) === true || is_int( $document['gross'] ?? null ) === false )
			return '';

		return self::money( $appData, $document['gross'] );
	}

	/**
	 *	An amount as the list writes it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$cents				In hundredths of the currency
	 *
	 *	@return 	string										Escaped
	 */
	public static function money( array &$appData, int $cents ): string {

		return \Nino\Modules\Components::escape( Documents\Money::format( $cents, \Crm\Settings::get( $appData, 'currency' ) ) );
	}

	/**
	 *	The cell of the day an invoice is due: "fällig am <day>" where it has one, "zahlbar sofort" for an invoice that is open and has none, nothing for the rest
	 *
	 *	@param		array 		$document			category, due_date
	 *	@param		string		$state				As customerState() has it
	 *
	 *	@return 	string										Markup
	 */
	private static function dueCell( array $document, string $state ): string {

		if( $document['category'] !== 'invoice' )
			return '';

		$day = Documents\Render::day( (string) ( $document['due_date'] ?? '' ) );

		if( $day !== '' )
			return self::render( 'document-due', [ '[[iso]]' => \Nino\Modules\Components::escape( (string) $document['due_date'] ), '[[date]]' => \Nino\Modules\Components::escape( $day ) ] );

		return in_array( $state, [ 'open', 'overdue' ], true ) === true ? self::template( 'document-due-none' ) : '';
	}

	/**
	 *	The line under the title of a document: its kind and its number where the title is its own, the number alone where the title says the
	 *	kind already (documentTitle()), and the kind alone for a document without a number
	 *
	 *	@param		array 		$document			category, number, title
	 *
	 *	@return 	string										Markup
	 */
	private static function kindLine( array $document ): string {

		$category = '[[/project/crm-documents/category/'. $document['category']. ']]';
		$number = \Nino\Modules\Components::escape( $document['number'] );

		if( $document['number'] === '' )
			return $category;

		if( $document['title'] === '' )
			return '<span class="crm-documents__number">'. $number. '</span>';

		return self::render( 'document-kind', [ '[[category]]' => $category, '[[number]]' => $number ] );
	}

	/**
	 *	How an open invoice is paid, as the PDF says it: the amount, the payee (the company of the site), the bank, the IBAN and the BIC of the
	 *	settings and the number as the purpose - and, where the settings hold the PayPal.me name (paypalLink()), a row with the button that opens the payment of this amount
	 *	at PayPal, which asks for the number as the purpose too. Nothing where the settings hold neither a bank nor an IBAN nor a PayPal.me name: then there is nothing to tell;
	 *	with the name alone the block has the amount, the purpose and the PayPal row
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::released() has it
	 *
	 *	@return 	string										Markup
	 */
	public static function payBlock( array &$appData, array $document ): string {

		$bank = \Crm\Settings::get( $appData, 'bank_name' );
		$iban = \Crm\Settings::get( $appData, 'iban' );
		$bic = \Crm\Settings::get( $appData, 'bic' );

		$link = self::paypalLink( $appData, $document );

		if( $bank === '' && $iban === '' && $link === '' )
			return '';

		$company = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$rows = '';

		foreach( [ 'to' => $bank !== '' || $iban !== '' ? $company : '', 'bank' => $bank, 'iban' => $iban, 'bic' => $bic ] as $name => $value )
			if( $value !== '' )
				$rows .= self::render( 'document-pay-row', [ '[[name]]' => $name, '[[value]]' => \Nino\Modules\Components::escape( $value ) ] );

		return self::render( 'document-pay', [
			'[[number]]' => \Nino\Modules\Components::escape( $document['number'] ),
			'[[amount]]' => self::amount( $appData, $document ),
			'[[rows]]'   => $rows,
			'[[paypal]]' => $link === '' ? '' : self::paypal( $appData, $link, (string) $document['number'] ),
		] );
	}

	/**
	 *	The address that opens the payment of an invoice at PayPal: https://paypal.me/<name>/<amount>EUR, the amount with a point and two places from the gross sum in cents (750.00,
	 *	1234.50: no sign for thousands). It is made of the name the settings hold once it is checked again (letters and digits, 1 to 20 - nothing a person typed reaches the address
	 *	unchecked) and of the sum; the currency of the module is the euro, and an invoice in any other currency or without a sum to pay has no link
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			gross, where Documents::released() has it
	 *
	 *	@return 	string										'' where the settings hold no name or the invoice has no link
	 */
	public static function paypalLink( array &$appData, array $document ): string {

		$name = \Crm\Settings::get( $appData, 'paypal' );
		$gross = $document['gross'] ?? null;

		if( preg_match( '/^[A-Za-z0-9]{1,20}$/D', $name ) !== 1 || is_int( $gross ) === false || $gross <= 0 || in_array( \Crm\Settings::get( $appData, 'currency' ), [ '€', 'EUR' ], true ) === false )
			return '';

		return 'https://paypal.me/'. $name. '/'. intdiv( $gross, 100 ). '.'. sprintf( '%02d', $gross % 100 ). 'EUR';
	}

	/**
	 *	The row of the block that tells how to pay: the button to PayPal and the sentence that the number is the purpose
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$link					As paypalLink() has it
	 *	@param		string		$number				The number of the invoice
	 *
	 *	@return 	string										Markup
	 */
	private static function paypal( array &$appData, string $link, string $number ): string {

		self::words( $appData );

		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$parts = explode( '{number}', (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/pay/paypal-purpose' ), 2 );
		$purpose = $escape( $parts[0] ). ( count( $parts ) === 2 ? '<strong class="crm-pay__number">'. $escape( $number ). '</strong>'. $escape( $parts[1] ) : '' );

		return self::render( 'document-pay-paypal', [ '[[link]]' => $escape( $link ), '[[purpose]]' => $purpose ] );
	}

	/**
	 *	The contact address of the studio, from the words of the site
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										'' where the site has none or what it has is no address
	 */
	public static function contactMail( array &$appData ): string {

		$mail = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		return filter_var( $mail, FILTER_VALIDATE_EMAIL ) !== false ? $mail : '';
	}

	/**
	 *	An empty state: an icon, the sentence that says what happens next, and the way to the studio - its e-mail address and its phone as links,
	 *	from the words of the site
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$icon					A name of Portal\Icons
	 *	@param		string		$message			Markup, a fill as a rule
	 *	@param		string		$classes			Classes of the box beside crm-empty, '' for none
	 *
	 *	@return 	string										Markup
	 */
	public static function emptyState( array &$appData, string $icon, string $message, string $classes = '' ): string {

		$mail = self::contactMail( $appData );
		$phone = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/phone' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$number = (string) preg_replace( '/[^0-9+]/', '', $phone );
		$mailLink = $mail !== '' ? self::render( 'empty-mail', [ '[[address]]' => \Nino\Modules\Components::escape( $mail ) ] ) : '';
		$phoneLink = $number !== '' && $number !== '+' ? self::render( 'empty-phone', [ '[[number]]' => \Nino\Modules\Components::escape( $number ), '[[phone]]' => \Nino\Modules\Components::escape( $phone ) ] ) : '';

		return self::render( 'empty', [
			'[[classes]]' => $classes === '' ? '' : ' '. $classes,
			'[[icon]]'    => $icon,
			'[[message]]' => $message,
			'[[contact]]' => $mailLink === '' && $phoneLink === '' ? '' : self::render( 'empty-contact', [ '[[mail]]' => $mailLink, '[[phone]]' => $phoneLink ] ),
		] );
	}

	/**
	 *	What the owner is told of the portal, none of it a setting of the CRM: the address of its start page, its pages and whether the project has
	 *	a route for each, the settings of Members that shape it and the cap of mails the kernel keeps. All of it is read, none is written
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										address ('' where the site has none), pages ( uri, present ), members ( key, label, value, unit ) and mail_cap (calls an hour; null where the kernel has no such key)
	 */
	public static function overview( array &$appData ): array {

		$manifest = \Nino\Features::readUnitManifest( dirname( __DIR__ ). '/Crm/install' ) ?? [];
		$routes = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
		$routes = is_array( $routes ) === true ? $routes : [];
		$pages = [];

		foreach( (array) ( $manifest['routes'] ?? [] ) as $key => $route )
			if( str_starts_with( (string) ( $route['uri'] ?? '' ), '/portal' ) === true )
				$pages[] = [ 'uri' => (string) $route['uri'], 'present' => isset( $routes[$key] ) === true ];

		$members = [];
		$feature = \Nino\Features::get( $appData, 'members' );

		if( $feature !== null )
			foreach( \Nino\Features::settings( $appData, 'members' ) as $name => $value ) {

				$label = \Nino\Features::localized( (array) ( $feature['settings'][$name]['label'] ?? [] ), 'de_DE' );
				$members[] = [ 'key' => (string) $name, 'label' => $label !== '' ? $label : (string) $name, 'value' => is_array( $value ) === true ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value, 'unit' => (string) ( $feature['settings'][$name]['unit'] ?? '' ) ];
			}

		return [
			'address'  => \Nino\Html::absoluteUrl( $appData, '/portal' ),
			'pages'    => $pages,
			'members'  => $members,
			'mail_cap' => isset( $appData['/nino/mail/cap'] ) === true ? (int) $appData['/nino/mail/cap'] : null,
		];
	}

	/**
	 *	'/members/login' and '/members/logout': who is here changed - the lookup of this request is dropped, so a page
	 *	rendered after it does not answer for whoever was here before
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$account				The account of Members, unused
	 *
	 *	@return 	void
	 */
	public static function callbackSession( array &$appData, array &$account ): void {

		unset( $appData[self::CACHE] );
	}

	/**
	 *	'/nino/http/response/GET://': the door of the portal. The start page of the site is the template page-door, which draws the login form
	 *	for a visitor; a member who is signed in is sent on to /portal before the page is rendered. A start page that is not the door - the
	 *	owner put a page of his own there - is left as it is
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackDoor( array &$appData, array &$request ): void {

		if( ( $request['/nino/http/response']['body'] ?? null ) !== \Crm\Crm::DOOR || \Crm\Crm::featureOn( $appData, 'members' ) === false )
			return;

		if( \Nino\Modules\Members::current( $appData ) !== null )
			\Nino\Http::redirect( $appData, $request, '/portal', 302 );
	}

	/**
	 *	The query of the request that is being answered
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											name => value; none for a request without a query
	 */
	public static function query( array &$appData ): array {

		$request = \Nino\Http::getRequest( $appData );
		$query = $request === false ? null : ( $request['/nino/http/request']['query'] ?? null );

		return is_array( $query ) === true ? $query : [];
	}

	/**
	 *	The words of the portal's components, as fills of the languages that have a file, added when a component is drawn
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function words( array &$appData ): void {

		foreach( glob( __DIR__. '/text/*.php' ) ?: [] as $file )
			\Nino\Html::addFills( $appData, (array) include $file, basename( $file, '.php' ) );
	}

	/**
	 *	The markup of a component, read from the templates/ beside this class (the module's own directory, which is where the class is wherever
	 *	the project is served from), with its icons put in ([[icon:<name>]], see Portal\Icons)
	 *
	 *	@param		string		$name					A file of templates/ without .tpl: lower case letters and hyphens
	 *
	 *	@return 	string
	 */
	public static function template( string $name ): string {

		if( preg_match( '/^[a-z]+(-[a-z]+)*$/D', $name ) !== 1 )
			throw new \InvalidArgumentException( 'There is no portal template "'. $name. '"' );

		$markup = file_get_contents( __DIR__. '/templates/'. $name. '.tpl' );

		if( $markup === false )
			throw new \RuntimeException( 'The portal template "'. $name. '" cannot be read' );

		return Icons::fill( rtrim( $markup, "\n" ) );
	}

	/**
	 *	A template filled: every token is replaced by its value, in the order given, and an icon a value asks for is put in after that
	 *	(an escaped value cannot ask for one: its brackets are entities)
	 *
	 *	@param		string		$name					A template, see template()
	 *	@param		array 		$values				'[[token]]' => markup
	 *
	 *	@return 	string
	 */
	public static function render( string $name, array $values ): string {

		return Icons::fill( str_replace( array_keys( $values ), array_values( $values ), self::template( $name ) ) );
	}
}

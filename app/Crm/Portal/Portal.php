<?php
declare(strict_types=1);
/**
 *	Crm\Portal		What the portal pages read of the CRM: the client of the member who is signed in, the head of every portal page
 *								([crm-portal-head]: the breadcrumb where there is a parent, the title of the page or of the project, its lead, the member and
 *								the company, Abmelden and the actions of the page), the component [crm-client] that says the company, the component
 *								[crm-documents] that lists the documents released to that client (all of them, or those of one project) with their number, the amount, the
 *								day an invoice is due, the state in the customer's words and - for an open invoice - how to pay it, the navigation
 *								[crm-portal-nav] of the areas of the portal and the door [crm-portal-door] of the site's start page. The Datenschutz
 *								([crm-privacy], Portal\Privacy), the order under an offer (Portal\Order), the offer itself (Portal\Offer), the start page
 *								(Portal\Start, [crm-portal-start]) and the dates (Portal\Dates, [crm-portal-dates]) are classes of their own. The areas
 *								themselves are the components of Portal\Projects ([crm-projects]), Portal\Files ([crm-uploads]) and Portal\Data
 *								([crm-client-data] and the route that saves it). A visitor has no client, and a page view of a visitor never opens the
 *								database. The lookup is kept for the request and dropped when a member signs in or out.
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

	// The style sheet and the script of the portal, as the bundles of the site name them, and where the markup and the words of the portal are
	public const string STYLE = '/app/Crm/Portal/assets/portal.css';
	public const string SCRIPT = '/app/Crm/Portal/assets/portal.js';
	private const string NAME = '/^[a-z]{1,20}$/D';

	// The areas of the portal, in the order of the navigation: the last part of the address, which is also the key of the page's name
	public const array AREAS = [ 'meine-daten', 'projekte', 'upload', 'belege', 'termine' ];

	// The sixth area, which the navigation has only while the client has not agreed to the Datenschutz it is asked for (it is reachable from Meine Daten all the time)
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

		if( array_key_exists( self::CACHE, $appData ) === false )
			$appData[self::CACHE] = Clients::ofMember( $appData, $member['id'] );

		return $appData[self::CACHE];
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
	 *	[crm-portal-head] - the head of a portal page: the title and the lead of the page (its name and description, as the head of the site reads
	 *	them), the member who is signed in with the company of the client, and Abmelden. On the page of a project (?projekt=<id>, or ?review=<file> of a
	 *	file of one of the client's projects) the breadcrumb Projekte › the project, the project's title as the title with its state and its start,
	 *	and its description as the lead. With calendar="1" the subscription of the calendar stands among the actions (where Events is on). Nothing
	 *	for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args					calendar ('1': the subscription of the calendar of Events among the actions)
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
		$name = (string) $member['name'] !== '' ? (string) $member['name'] : (string) $member['email'];
		$company = $client !== null && $client['company'] !== '' && $client['company'] !== $name ? self::render( 'head-company', [ '[[company]]' => \Nino\Modules\Components::escape( $client['company'] ) ] ) : '';
		$project = $client === null ? null : Portal\Projects::current( $appData, $client );

		if( $project !== null ) {

			$day = Documents\Render::day( $project['started'] );
			$state = $project['done'] === true ? 'done' : 'open';
			$crumbs = self::render( 'head-crumbs', [ '[[href]]' => $dir. '/portal/projekte', '[[parent]]' => '[[/_nino/webpage/portal/projekte/name]]', '[[here]]' => \Nino\Modules\Components::escape( $project['title'] ) ] );
			$title = \Nino\Modules\Components::escape( $project['title'] );
			$badge = self::render( 'head-badge', [
				'[[tone]]'  => self::tone( $state ),
				'[[state]]' => '[[/project/crm-portal/projects/state-'. $state. ']]',
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

		return self::render( 'head', [
			'[[classes]]' => \Nino\Feature::classes( $args ),
			'[[crumbs]]'  => $crumbs,
			'[[title]]'   => $title,
			'[[badge]]'   => $badge,
			'[[lead]]'    => $lead,
			'[[actions]]' => ( $args['calendar'] ?? '0' ) === '1' && Crm::featureOn( $appData, 'events' ) === true ? self::template( 'head-calendar' ) : '',
			'[[dir]]'     => $dir,
			'[[member]]'  => \Nino\Modules\Components::escape( $name ),
			'[[company]]' => $company,
		] );
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
	 *	[crm-portal-nav] - the areas of the portal as links, the one the request is in marked with aria-current. The names are the words
	 *	of the pages (/_nino/webpage/portal/<area>/name). A sixth link, to the Datenschutz, stands there while the client has not agreed to the text it is asked
	 *	for. Nothing for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalNav( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		self::words( $appData );

		$request = \Nino\Http::getRequest( $appData );
		$path = $request === false ? '' : rtrim( (string) ( $request['/nino/http/request']['uri'] ?? '' ), '/' );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$items = '';

		$areas = self::AREAS;
		$client = self::client( $appData );

		if( $client !== null ) {
			$standing = Records::privacy( $appData, $client['id'] );

			if( $standing['asked'] === true && $standing['agreed'] === false )
				$areas[] = self::PRIVACY;
		}

		foreach( $areas as $area ) {

			$here = $path === '/portal/'. $area || str_starts_with( $path, '/portal/'. $area. '/' ) === true;

			$items .= str_replace(
				[ '[[dir]]', '[[area]]', '[[current]]' ],
				[ $dir, $area, $here === true ? ' aria-current="page"' : '' ],
				self::template( 'nav-item' )
			);
		}

		return str_replace( [ '[[classes]]', '[[items]]' ], [ \Nino\Feature::classes( $args ), $items ], self::template( 'nav' ) );
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
		$back = self::signReturn( $appData );

		foreach( $documents as $document ) {

			$orderable = $orderable === true || Documents::orderable( $document ) === true;
			$row = self::documentRow( $appData, $document, $dir, $withProject, $client, $back );
			$count += $row === '' ? 0 : 1;
			$rows .= $row;
		}

		if( $rows === '' )
			return self::render( 'documents-empty', [ '[[classes]]' => $classes, '[[empty]]' => self::emptyState( $appData, 'receipt', '[[/project/crm-documents/list/empty]]', 'crm-documents__empty' ) ] );

		return self::render( 'documents', [
			'[[classes]]' => $classes,
			'[[privacy]]' => $client !== null && $orderable === true && Portal\Order::privacyFirst( $appData, $client ) === true ? Portal\Order::privacyHint( $dir ) : '',
			'[[state]]'   => $client === null ? '' : Portal\Order::state( $appData ),
			'[[sign]]'    => $client === null || $back['state'] === '' ? '' : self::render( 'sign-state', [ '[[state]]' => $back['state'], '[[tone]]' => self::SIGN[$back['state']][0], '[[icon]]' => self::SIGN[$back['state']][1] ] ),
			'[[count]]'   => $count. ' [[/project/crm-portal/projects/document-'. ( $count === 1 ? 'one' : 'many' ). ']]',
			'[[mode]]'    => $withProject === true ? '' : ' crm-table--project',
			'[[column]]'  => $withProject === true ? self::template( 'documents-column' ) : '',
			'[[rows]]'    => $rows,
		] );
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
	 *	settings and the number as the purpose. Nothing where the settings hold neither a bank nor an IBAN: then there is nothing to tell
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

		if( $bank === '' && $iban === '' )
			return '';

		$company = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$rows = '';

		foreach( [ 'to' => $company, 'bank' => $bank, 'iban' => $iban, 'bic' => $bic ] as $name => $value )
			if( $value !== '' )
				$rows .= self::render( 'document-pay-row', [ '[[name]]' => $name, '[[value]]' => \Nino\Modules\Components::escape( $value ) ] );

		return self::render( 'document-pay', [
			'[[number]]' => \Nino\Modules\Components::escape( $document['number'] ),
			'[[amount]]' => self::amount( $appData, $document ),
			'[[rows]]'   => $rows,
		] );
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

		$mail = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$phone = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/phone' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$number = (string) preg_replace( '/[^0-9+]/', '', $phone );
		$mailLink = filter_var( $mail, FILTER_VALIDATE_EMAIL ) !== false ? self::render( 'empty-mail', [ '[[address]]' => \Nino\Modules\Components::escape( $mail ) ] ) : '';
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

<?php
declare(strict_types=1);
/**
 *	Crm\Portal		What the portal pages read of the CRM: the client of the member who is signed in, the component
 *								[crm-client] that says its company, the component [crm-documents] that lists the documents released
 *								to that client (all of them, or those of one project) and the navigation [crm-portal-nav] of the areas of
 *								the portal. The Datenschutz ([crm-privacy], Portal\Privacy), the order under an offer (Portal\Order) and the start page (Portal\Start, [crm-portal-start]) are classes of their own. The areas themselves are the components of Portal\Projects ([crm-projects]), Portal\Files
 *								([crm-uploads]) and Portal\Data ([crm-client-data] and the route that saves it). A visitor has no
 *								client, and a page view of a visitor never opens the database. The lookup is kept for the request and
 *								dropped when a member signs in or out.
 */
namespace Crm;

class Portal {

	// The markup of the component, declared once
	public static $html = [
		'client' => '<div class="crm-client[[classes]]">[[company]]</div>',
	];

	private const string CACHE = './crm/portal/client';

	// The style sheet of the list, as the bundle of the site names it, and where the markup and the words of the list are
	public const string STYLE = '/app/Crm/Portal/assets/portal.css';
	private const string NAME = '/^[a-z]{1,20}$/D';

	// The areas of the portal, in the order of the navigation: the last part of the address, which is also the key of the page's name
	public const array AREAS = [ 'meine-daten', 'projekte', 'upload', 'belege', 'termine' ];

	// The sixth area, which the navigation has only while the client has not agreed to the Datenschutz it is asked for (it is reachable from Meine Daten all the time)
	public const string PRIVACY = 'datenschutz';

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
	 *	[crm-documents] - the documents released to the client of the member, the newest first: the kind, the title (the number where there
	 *	is none), the project (its title, a link to the project page; empty where the document has none), the day, the state, the download and -
	 *	where a signature is asked for - the way to it, or - once it is signed - the way to the signed file. With project="<id>" only the documents of that
	 *	project, and no column for the project. A member of no client and a visitor are told there is none. Every value enters the markup escaped
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

		return ( $client === null ? '' : Portal\Order::state( $appData ) ). self::documentList( $appData, $documents, \Nino\Feature::classes( $args ), $project === '', $client );
	}

	/**
	 *	The list of documents as the portal draws it: [crm-documents] and the project page both
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$documents		As Documents::released() has them
	 *	@param		string		$classes			The class attribute's tail, see \Nino\Feature::classes()
	 *	@param		bool			$withProject	Whether each row names its project
	 *	@param		array|null	$client				The client, where an offer in the list may be ordered from it (the project page has its form under the offer itself); null for no form
	 *
	 *	@return 	string										The list, or the sentence that there is none
	 */
	public static function documentList( array &$appData, array $documents, string $classes, bool $withProject, ?array $client = null ): string {

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$rows = '';

		foreach( $documents as $document )
			$rows .= self::documentRow( $document, $dir, $withProject, $client === null ? '' : Portal\Order::block( $appData, $client, $document, 'belege', $dir ) );

		if( $rows === '' )
			return str_replace( '[[classes]]', $classes, self::template( 'documents-empty' ) );

		return str_replace( [ '[[classes]]', '[[rows]]' ], [ $classes, $rows ], self::template( 'documents' ) );
	}

	/**
	 *	One row of the list of documents
	 *
	 *	@param		array 		$document			As Documents::released() has it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *	@param		bool			$withProject	Whether the row names its project
	 *	@param		string		$order				The form of the order under an offer, or the line that it was ordered ('' for none)
	 *
	 *	@return 	string										'' for a document whose names the words do not know
	 */
	private static function documentRow( array $document, string $dir, bool $withProject, string $order = '' ): string {

		$state = $document['expired'] === true ? 'expired' : $document['state'];

		// The names the module writes are lower case letters; they are the end of the key of a word
		if( preg_match( self::NAME, $document['category'] ) !== 1 || preg_match( self::NAME, $state ) !== 1 )
			return '';

		$request = preg_match( '/^[a-f0-9]{12}$/D', $document['sign_id'] ) === 1 ? $document['sign_id'] : '';
		$sign = '';

		if( $request !== '' && in_array( $document['sign_state'], Documents::PENDING, true ) === true )
			$sign = 'document-sign';
		elseif( $request !== '' && $document['state'] === 'signed' && $document['sign_state'] === 'signed' )
			$sign = 'document-signed';

		$project = '';

		if( $withProject === true )
			$project = str_replace(
				[ '[[dir]]', '[[id]]', '[[title]]' ],
				[ $dir, \Nino\Modules\Components::escape( $document['project'] ), \Nino\Modules\Components::escape( $document['project_title'] ) ],
				self::template( $document['project_title'] === '' ? 'document-project-none' : ( preg_match( Projekte::ID, $document['project'] ) === 1 ? 'document-project' : 'document-project-text' ) )
			);

		return str_replace(
			[ '[[category]]', '[[state]]', '[[id]]', '[[title]]', '[[project]]', '[[iso]]', '[[date]]', '[[dir]]', '[[xml]]', '[[sign]]', '[[order]]' ],
			[
				$document['category'],
				$state,
				(string) $document['id'],
				\Nino\Modules\Components::escape( $document['title'] !== '' ? $document['title'] : $document['number'] ),
				$project,
				\Nino\Modules\Components::escape( $document['date'] ),
				\Nino\Modules\Components::escape( Documents\Render::day( $document['date'] ) ),
				$dir,
				$document['xml'] === true ? str_replace( [ '[[dir]]', '[[id]]' ], [ $dir, (string) $document['id'] ], self::template( 'document-xml' ) ) : '',
				$sign === '' ? '' : str_replace( [ '[[dir]]', '[[request]]' ], [ $dir, $request ], self::template( $sign ) ),
				$order,
			],
			self::template( 'document' )
		);
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
	 *	the project is served from)
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

		return rtrim( $markup, "\n" );
	}
}

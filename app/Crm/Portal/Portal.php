<?php
declare(strict_types=1);
/**
 *	Crm\Portal		What the portal pages read of the CRM: the client of the member who is signed in, the component
 *								[crm-client] that says its company and the component [crm-documents] that lists the documents released
 *								to that client. A visitor has no client, and a page view of a visitor never opens the database. The
 *								lookup is kept for the request and dropped when a member signs in or out.
 */
namespace Crm;

class Portal {

	// The markup of the component, declared once
	public static $html = [
		'client' => '<span class="crm-client[[classes]]">[[company]]</span>',
	];

	private const string CACHE = './crm/portal/client';

	// The style sheet of the list, as the bundle of the site names it, and where the markup and the words of the list are
	public const string STYLE = '/app/Crm/Portal/assets/portal.css';
	private const string NAME = '/^[a-z]{1,20}$/D';

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
	 *	[crm-client] - the company of the member's client, its name where it has no company; nothing for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmClient( array &$appData, array $args ): string {

		$client = self::client( $appData );

		if( $client === null )
			return '';

		return str_replace(
			[ '[[classes]]', '[[company]]' ],
			[ \Nino\Feature::classes( $args ), \Nino\Modules\Components::escape( $client['company'] !== '' ? $client['company'] : $client['name'] ) ],
			self::$html['client']
		);
	}

	/**
	 *	[crm-documents] - the documents released to the client of the member, the newest first: the kind, the title (the number where there
	 *	is none), the day, the state, the download and - where a signature is asked for - the way to it, or - once it is signed - the way
	 *	to the signed file. A member of no client and a visitor are told there is none. Every value enters the markup escaped
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmDocuments( array &$appData, array $args ): string {

		self::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$client = self::client( $appData );
		$documents = $client === null ? [] : Documents::released( $appData, $client['id'] );

		if( $documents === [] )
			return str_replace( '[[classes]]', $classes, self::template( 'documents-empty' ) );

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$rows = '';

		foreach( $documents as $document ) {

			$state = $document['expired'] === true ? 'expired' : $document['state'];

			// The names the module writes are lower case letters; they are the end of the key of a word
			if( preg_match( self::NAME, $document['category'] ) !== 1 || preg_match( self::NAME, $state ) !== 1 )
				continue;

			$request = preg_match( '/^[a-f0-9]{12}$/D', $document['sign_id'] ) === 1 ? $document['sign_id'] : '';
			$sign = '';

			if( $request !== '' && in_array( $document['sign_state'], Documents::PENDING, true ) === true )
				$sign = 'document-sign';
			elseif( $request !== '' && $document['state'] === 'signed' && $document['sign_state'] === 'signed' )
				$sign = 'document-signed';

			$rows .= str_replace(
				[ '[[category]]', '[[state]]', '[[id]]', '[[title]]', '[[iso]]', '[[date]]', '[[dir]]', '[[sign]]' ],
				[
					$document['category'],
					$state,
					(string) $document['id'],
					\Nino\Modules\Components::escape( $document['title'] !== '' ? $document['title'] : $document['number'] ),
					\Nino\Modules\Components::escape( $document['date'] ),
					\Nino\Modules\Components::escape( Documents\Render::day( $document['date'] ) ),
					$dir,
					$sign === '' ? '' : str_replace( [ '[[dir]]', '[[request]]' ], [ $dir, $request ], self::template( $sign ) ),
				],
				self::template( 'document' )
			);
		}

		if( $rows === '' )
			return str_replace( '[[classes]]', $classes, self::template( 'documents-empty' ) );

		return str_replace( [ '[[classes]]', '[[rows]]' ], [ $classes, $rows ], self::template( 'documents' ) );
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
	 *	The words of the list, as fills of the languages that have a file, added when a list is drawn
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	private static function words( array &$appData ): void {

		foreach( glob( __DIR__. '/text/*.php' ) ?: [] as $file )
			\Nino\Html::addFills( $appData, (array) include $file, basename( $file, '.php' ) );
	}

	/**
	 *	The markup of the list, read from the templates/ beside this class (the module's own directory, which is where the class is wherever
	 *	the project is served from)
	 *
	 *	@param		string		$name					A file of templates/ without .tpl: lower case letters and hyphens
	 *
	 *	@return 	string
	 */
	private static function template( string $name ): string {

		if( preg_match( '/^[a-z]+(-[a-z]+)*$/D', $name ) !== 1 )
			throw new \InvalidArgumentException( 'There is no portal template "'. $name. '"' );

		$markup = file_get_contents( __DIR__. '/templates/'. $name. '.tpl' );

		if( $markup === false )
			throw new \RuntimeException( 'The portal template "'. $name. '" cannot be read' );

		return rtrim( $markup, "\n" );
	}
}

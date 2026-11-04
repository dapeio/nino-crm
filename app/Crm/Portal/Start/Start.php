<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Start		The component [crm-portal-start] of the start page of the portal - "Was liegt an": what waits for the client, as cards that stand there only
 *											when they apply: the Datenschutz that is not agreed to yet (the way to it), the offers that wait for the order (each with its number or
 *											title, the day it is valid until, the way to its project page or to the list of documents), the documents released in the last
 *											NEW_DAYS days, the files that came into the folders of the client's projects in the last NEW_DAYS days (the count for each project and the way to
 *											its page) and the dates of the next DATES_DAYS days of the member's group and for everyone (through the feature Events; where it is off, none).
 *											Where nothing applies it is one sentence. Nothing here is stored or sent, and every value enters the markup escaped. A visitor and a
 *											member of no client get nothing.
 */
namespace Crm\Portal;

use Crm\Crm;
use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;
use Crm\Records;

class Start {

	// How far back documents and files count as new, how far ahead dates count as coming, and how many documents and dates a card lists
	public const int NEW_DAYS = 14;
	public const int DATES_DAYS = 30;
	public const int ROWS = 5;

	/**
	 *	[crm-portal-start]
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPortalStart( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		$client = Portal::client( $appData );

		if( $client === null )
			return '';

		Portal::words( $appData );

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$cards = [];
		$standing = Records::privacy( $appData, $client['id'] );

		if( $standing['asked'] === true && $standing['agreed'] === false )
			$cards['privacy'] = str_replace( '[[dir]]', $dir, Portal::template( 'start-privacy' ) );

		$documents = Documents::released( $appData, $client['id'] );
		$offers = [];
		$recent = [];
		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::NEW_DAYS * 86400 );

		foreach( $documents as $document ) {

			if( Documents::orderable( $document ) === true )
				$offers[] = self::row(
					self::projectHref( $document['project'], $dir ), $escape( $document['title'] !== '' ? $document['title'] : $document['number'] ),
					$document['valid_until'] === '' ? '' : '[[/project/crm-portal/projects/offer-valid]] '. $escape( Documents\Render::day( $document['valid_until'] ) )
				);

			if( $document['released_at'] !== '' && $document['released_at'] >= $since )
				$recent[] = self::row(
					$dir. Order::PAGE_DOCUMENTS, $escape( $document['title'] !== '' ? $document['title'] : $document['number'] ),
					'[[/project/crm-documents/category/'. $document['category']. ']] · '. $escape( Documents\Render::day( substr( $document['released_at'], 0, 10 ) ) )
				);
		}

		if( $offers !== [] )
			$cards['offers'] = self::list( $offers );

		if( $recent !== [] )
			$cards['documents'] = self::list( array_slice( $recent, 0, self::ROWS ) );

		$files = self::files( $appData, $client, $dir );

		if( $files !== [] )
			$cards['files'] = self::list( $files );

		$dates = self::dates( $appData, $dir );

		if( $dates !== [] )
			$cards['dates'] = self::list( $dates );

		$html = '';

		foreach( $cards as $name => $content )
			$html .= str_replace( [ '[[name]]', '[[content]]' ], [ $name, $content ], Portal::template( 'start-card' ) );

		return str_replace( [ '[[classes]]', '[[cards]]' ], [ \Nino\Feature::classes( $args ), $html !== '' ? $html : Portal::template( 'start-nothing' ) ], Portal::template( 'start' ) );
	}

	/**
	 *	The files that came into the folders of the client's projects lately and that the member may see, a row for each project
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	array											Rows
	 */
	private static function files( array &$appData, array $client, string $dir ): array {

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null || $member['group'] === '' || Crm::featureOn( $appData, 'files' ) === false )
			return [];

		$folders = array_column( \Nino\Modules\Files::folders( $appData, $member['group'] ), 'id' );
		$limit = time() - self::NEW_DAYS * 86400;
		$rows = [];

		foreach( Projekte::ofClient( $appData, $client['number'] ) as $project ) {

			if( $project['folder'] === '' || in_array( $project['folder'], $folders, true ) === false )
				continue;

			// What the member put there is not news to the member
			$new = array_filter( Files::visible( $appData, $project['folder'] ), static function( array $file ) use ( $limit, $member ): bool {
				$created = strtotime( (string) $file['created'] );

				return $created !== false && $created > $limit && ( ( $file['by']['kind'] ?? '' ) === 'member' && ( $file['by']['id'] ?? '' ) === $member['id'] ) === false;
			} );

			if( $new !== [] )
				$rows[] = self::row(
					$dir. Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $project['id'] ). '#projekt-files', \Nino\Modules\Components::escape( $project['title'] ),
					count( $new ). ' [[/project/crm-portal/start/new-file-'. ( count( $new ) === 1 ? 'one' : 'many' ). ']]'
				);
		}

		return $rows;
	}

	/**
	 *	The dates of the next DATES_DAYS days that the member may see
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	array											Rows; none where Events is off or has no type
	 */
	private static function dates( array &$appData, string $dir ): array {

		if( Crm::featureOn( $appData, 'events' ) === false || \Nino\Filesystem::fileExists( $appData, '/elements'. \Nino\Modules\Events::TYPE. '.php' ) === false )
			return [];

		$elements = [];

		foreach( (array) \Nino\Elements::queryElements( $appData, \Nino\Modules\Events::TYPE, [], '', [] ) as $element ) {

			$with = is_array( $element ) === true && is_string( $element['.uri'] ?? null ) === true ? \Nino\Elements::getElementWithFallback( $appData, $element['.uri'], '' ) : false;

			if( is_array( $with ) === true )
				$elements[] = $with;
		}

		$limit = \Nino\Modules\Events::now( $appData )->modify( '+'. self::DATES_DAYS. ' days' );
		$rows = [];

		foreach( \Nino\Modules\Events::dates( $appData, $elements, 'upcoming', \Nino\Modules\Events::viewer( $appData ) ) as $date ) {

			if( $date['start'] > $limit || count( $rows ) >= self::ROWS )
				continue;

			$rows[] = self::row(
				$dir. '/portal/termine', \Nino\Modules\Components::escape( $date['title'] ),
				\Nino\Modules\Components::escape( Documents\Render::day( $date['start']->format( 'Y-m-d' ) ). ( $date['allDay'] === true ? '' : ' '. $date['start']->format( 'H:i' ) ) )
			);
		}

		return $rows;
	}

	/**
	 *	Where an offer of a project is read: its project page, the list of documents for an offer without one
	 *
	 *	@param		string		$project
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function projectHref( string $project, string $dir ): string {

		return preg_match( Projekte::ID, $project ) === 1 ? $dir. Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $project ) : $dir. Order::PAGE_DOCUMENTS;
	}

	/**
	 *	@param		string		$href					Escaped
	 *	@param		string		$title				Escaped
	 *	@param		string		$meta					Markup
	 *
	 *	@return 	string
	 */
	private static function row( string $href, string $title, string $meta ): string {

		return str_replace( [ '[[href]]', '[[title]]', '[[meta]]' ], [ $href, $title, $meta ], Portal::template( 'start-row' ) );
	}

	/**
	 *	@param		array 		$rows
	 *
	 *	@return 	string
	 */
	private static function list( array $rows ): string {

		return str_replace( '[[rows]]', implode( "\n\t\t\t", $rows ), Portal::template( 'start-list' ) );
	}
}

<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Start		The component [crm-portal-start] of the start page of the portal - "Was gerade ansteht": what waits for the client, as cards that stand there only
 *											when they apply: the Datenschutz that is not agreed to yet (the way to it), the offers that wait for the order (each with its title - the
 *											number where it has none -, its gross amount, its number and the day it is valid until, the way to its card on the project page or in
 *											the list of documents), the documents released in the last NEW_DAYS days (each with its title, its amount, its kind, its number and the
 *											way to its row), the files that came into the folders of the client's projects in the last NEW_DAYS days (the count for each project and the
 *											way to its page) and the dates of the next DATES_DAYS days of the member's group and for everyone (through the feature Events; where it is off, none).
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

	// The icon of each card, and the tone of the one that asks for something
	private const array ICONS = [ 'privacy' => 'shield-check', 'offers' => 'receipt', 'documents' => 'file-text', 'files' => 'music', 'dates' => 'calendar' ];

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
			$cards['privacy'] = Portal::render( 'start-privacy', [ '[[dir]]' => $dir ] );

		$documents = Documents::released( $appData, $client['id'] );
		$offers = [];
		$recent = [];
		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::NEW_DAYS * 86400 );

		foreach( $documents as $document ) {

			$title = Portal::documentTitle( $document );
			$kind = $document['number'] === '' ? '[[/project/crm-documents/category/'. $document['category']. ']]' : $escape( $document['number'] );

			if( Documents::orderable( $document ) === true )
				$offers[] = self::row(
					self::documentHref( $document, $dir, 'angebot' ), $title, Portal::amount( $appData, $document ),
					$kind. ( $document['valid_until'] === '' ? '' : ' · [[/project/crm-portal/projects/offer-valid]] '. $escape( Documents\Render::day( $document['valid_until'] ) ) )
				);

			if( $document['released_at'] !== '' && $document['released_at'] >= $since )
				$recent[] = self::row( self::documentHref( $document, $dir, 'belege' ), $title, Portal::amount( $appData, $document ), $kind. ' · '. $escape( Documents\Render::day( substr( $document['released_at'], 0, 10 ) ) ) );
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
			$html .= Portal::render( 'start-card', [ '[[name]]' => $name, '[[icon]]' => self::ICONS[$name], '[[tone]]' => $name === 'privacy' ? ' crm-card--warning' : '', '[[content]]' => $content ] );

		return Portal::render( 'start', [ '[[classes]]' => \Nino\Feature::classes( $args ), '[[cards]]' => $html !== '' ? $html : Portal::template( 'start-nothing' ) ] );
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
					$dir. Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $project['id'] ). '&amp;tab=dateien#projekt-files', \Nino\Modules\Components::escape( $project['title'] ),
					count( $new ). ' [[/project/crm-portal/start/new-file-'. ( count( $new ) === 1 ? 'one' : 'many' ). ']]', ''
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

		$limit = Dates::now( $appData )?->modify( '+'. self::DATES_DAYS. ' days' );
		$rows = [];

		foreach( $limit === null ? [] : Dates::upcoming( $appData ) as $date ) {

			if( $date['start'] > $limit || count( $rows ) >= self::ROWS )
				continue;

			$rows[] = self::row(
				$dir. '/portal/termine#termin-'. \Nino\Modules\Components::escape( $date['slug'] ), \Nino\Modules\Components::escape( $date['title'] ),
				\Nino\Modules\Components::escape( Dates::when( $appData, $date ) ), $date['place'] === '' ? '' : \Nino\Modules\Components::escape( $date['place'] )
			);
		}

		return $rows;
	}

	/**
	 *	Where a document is read: its card in the section of its project page, or its row in the list of documents for one without a project
	 *
	 *	@param		array 		$document			As Documents::released() has it
	 *	@param		string		$dir					Escaped
	 *	@param		string		$tab					angebot for an offer that waits (the section Angebot), belege for a document that is new (the section Belege)
	 *
	 *	@return 	string
	 */
	private static function documentHref( array $document, string $dir, string $tab ): string {

		if( preg_match( Projekte::ID, $document['project'] ) !== 1 )
			return $dir. Order::PAGE_DOCUMENTS. '#beleg-'. $document['id'];

		return $dir. Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $document['project'] ). '&amp;tab='. $tab. '#'. ( $tab === 'angebot' ? 'angebot-' : 'beleg-' ). $document['id'];
	}

	/**
	 *	@param		string		$href					Escaped
	 *	@param		string		$title				Escaped
	 *	@param		string		$extra				Markup, what stands at the right of the title - an amount, a count, a time; '' for nothing
	 *	@param		string		$meta					Markup, the line under the title; '' for none
	 *
	 *	@return 	string
	 */
	private static function row( string $href, string $title, string $extra, string $meta ): string {

		return Portal::render( 'start-row', [ '[[href]]' => $href, '[[title]]' => $title, '[[extra]]' => $extra === '' ? '' : Portal::render( 'start-extra', [ '[[extra]]' => $extra ] ), '[[meta]]' => $meta ] );
	}

	/**
	 *	@param		array 		$rows
	 *
	 *	@return 	string
	 */
	private static function list( array $rows ): string {

		return Portal::render( 'start-list', [ '[[rows]]' => implode( "\n\t\t\t\t", $rows ) ] );
	}
}

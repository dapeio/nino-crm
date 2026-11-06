<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Start		The component [crm-portal-start] of the start page of the portal - "Was gerade ansteht": what waits for the client, as cards that stand there only
 *											when they apply: the Datenschutz that is not agreed to yet (the way to it), the offers that wait for the order (each with its title - the
 *											number where it has none -, its gross amount, its number and the day it is valid until, the way to its card on the project page or in
 *											the list of documents), the documents released in the last NEW_DAYS days (each with its title, its amount, its kind, its number and the
 *											way to its row), the files that came into the folders of the client's projects in the last NEW_DAYS days (the count for each project and the
 *											way to its page) and the dates of the next DATES_DAYS days of the member's group and for everyone (through the feature Events; where it is off, none).
 *											Over the cards stands a row of key figures, each tile a link: the offers that wait for the order with their gross sum, the invoices that
 *											are open or overdue with theirs, the files that are new and the number of projects they are in, and the next date with the days to it;
 *											the row stands only where one of the numbers is more than nothing. Where nothing applies it is one sentence. Nothing here is stored or
 *											sent, and every value enters the markup escaped. A visitor and a member of no client get nothing.
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
		$figures = [ 'offers' => [ 'count' => 0, 'sum' => 0 ], 'invoices' => [ 'count' => 0, 'sum' => 0 ] ];
		$since = gmdate( 'Y-m-d\TH:i:s\Z', time() - self::NEW_DAYS * 86400 );

		foreach( $documents as $document ) {

			if( Documents::orderable( $document ) === true ) {
				$figures['offers']['count']++;
				$figures['offers']['sum'] += (int) $document['gross'];
			}
			elseif( $document['category'] === 'invoice' && in_array( Portal::customerState( $document ), [ 'open', 'overdue' ], true ) === true ) {
				$figures['invoices']['count']++;
				$figures['invoices']['sum'] += (int) $document['gross'];
			}

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

		$news = self::news( $appData, $client );
		$files = self::files( $news, $dir );

		if( $files !== [] )
			$cards['files'] = self::list( $files );

		$upcoming = Dates::upcoming( $appData );
		$dates = self::dates( $appData, $upcoming, $dir );

		if( $dates !== [] )
			$cards['dates'] = self::list( $dates );

		$html = '';

		foreach( $cards as $name => $content )
			$html .= Portal::render( 'start-card', [ '[[name]]' => $name, '[[icon]]' => self::ICONS[$name], '[[tone]]' => $name === 'privacy' ? ' crm-card--warning' : '', '[[content]]' => $content ] );

		return Portal::render( 'start', [
			'[[classes]]' => \Nino\Feature::classes( $args ),
			'[[kpis]]'    => self::figures( $appData, $dir, $figures, $news, $upcoming[0] ?? null ),
			'[[cards]]'   => $html !== '' ? $html : Portal::template( 'start-nothing' ),
		] );
	}

	/**
	 *	The row of key figures: the offers that wait, the invoices that are open, the new files and the next date - four tiles, each a link. Nothing
	 *	where none of the three numbers - offers, invoices, files - is more than nothing (a date alone makes no row, a client with nothing to do
	 *	reads no row of zeros); the tile of the date says a dash where there is no date or Events is off
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$dir					Escaped
	 *	@param		array 		$figures			offers and invoices, each count and sum (in hundredths)
	 *	@param		array 		$news					As news() has it
	 *	@param		array|null	$next					The date that comes next, as Dates::upcoming() has it; null for none
	 *
	 *	@return 	string										Markup, '' for no row
	 */
	private static function figures( array &$appData, string $dir, array $figures, array $news, ?array $next ): string {

		$files = array_sum( array_column( $news, 'count' ) );
		$now = Dates::now( $appData );

		if( $figures['offers']['count'] === 0 && $figures['invoices']['count'] === 0 && $files === 0 )
			return '';

		$money = static fn( array $figure ): string => $figure['count'] === 0 ? '[[/project/crm-portal/start/kpi-open-none]]' : Portal::money( $appData, $figure['sum'] ). ' [[/project/crm-portal/start/kpi-gross]]';
		$days = $next === null || $now === null ? null : max( 0, (int) $now->setTime( 0, 0 )->diff( $next['start']->setTime( 0, 0 ) )->format( '%r%a' ) );
		$when = $days === null ? '' : ( $days === 0 ? '[[/project/crm-portal/start/kpi-today]]' : ( $days === 1 ? '[[/project/crm-portal/start/kpi-tomorrow]]' : \Nino\Modules\Components::escape( str_replace( '{days}', (string) $days, (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/start/kpi-days' ) ) ) ) );
		$tiles = [
			[ 'offers', $dir. Order::PAGE_DOCUMENTS, (string) $figures['offers']['count'], $money( $figures['offers'] ), $figures['offers']['count'] > 0 ],
			[ 'invoices', $dir. Order::PAGE_DOCUMENTS, (string) $figures['invoices']['count'], $money( $figures['invoices'] ), false ],
			[ 'files', $dir. Order::PAGE_PROJECTS, (string) $files, $files === 0 ? '[[/project/crm-portal/start/kpi-files-none]]' : count( $news ). ' [[/project/crm-portal/start/kpi-project-'. ( count( $news ) === 1 ? 'one' : 'many' ). ']]', false ],
			[ 'date', $dir. '/portal/termine', $next === null ? '–' : $next['start']->format( 'd.m.' ), $next === null ? '[[/project/crm-portal/start/kpi-date-none]]' : $when, false ],
		];
		$html = '';

		foreach( $tiles as [ $name, $href, $value, $sub, $waits ] )
			$html .= Portal::render( 'start-kpi', [ '[[name]]' => $name, '[[href]]' => $href, '[[tone]]' => $waits === true ? ' crm-kpi--warn' : '', '[[value]]' => \Nino\Modules\Components::escape( $value ), '[[sub]]' => $sub ] );

		return Portal::render( 'start-kpis', [ '[[tiles]]' => $html ] );
	}

	/**
	 *	The files that came into the folders of the client's projects lately and that the member may see, for each project the number
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *
	 *	@return 	array											A list of id, title and count
	 */
	private static function news( array &$appData, array $client ): array {

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null || $member['group'] === '' || Crm::featureOn( $appData, 'files' ) === false )
			return [];

		$folders = array_column( \Nino\Modules\Files::folders( $appData, $member['group'] ), 'id' );
		$limit = time() - self::NEW_DAYS * 86400;
		$news = [];

		foreach( Projekte::ofClient( $appData, $client['number'] ) as $project ) {

			if( $project['folder'] === '' || in_array( $project['folder'], $folders, true ) === false )
				continue;

			// What the member put there is not news to the member, and a file that was removed from the server is none to anybody
			$new = array_filter( Files::visible( $appData, $project['folder'] ), static function( array $file ) use ( $limit, $member ): bool {
				$created = strtotime( (string) $file['created'] );

				return isset( $file['gone'] ) === false && $created !== false && $created > $limit && ( ( $file['by']['kind'] ?? '' ) === 'member' && ( $file['by']['id'] ?? '' ) === $member['id'] ) === false;
			} );

			if( $new !== [] )
				$news[] = [ 'id' => $project['id'], 'title' => $project['title'], 'count' => count( $new ) ];
		}

		return $news;
	}

	/**
	 *	The rows of the card of the new files: a row for each project that has some
	 *
	 *	@param		array 		$news					As news() has it
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	array											Rows
	 */
	private static function files( array $news, string $dir ): array {

		return array_map( static fn( array $project ): string => self::row(
			$dir. Order::PAGE_PROJECTS. '?projekt='. rawurlencode( $project['id'] ). '&amp;tab=dateien#projekt-files', \Nino\Modules\Components::escape( $project['title'] ),
			$project['count']. ' [[/project/crm-portal/start/new-file-'. ( $project['count'] === 1 ? 'one' : 'many' ). ']]', ''
		), $news );
	}

	/**
	 *	The dates of the next DATES_DAYS days that the member may see
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$upcoming			As Dates::upcoming() has them
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	array											Rows; none where Events is off or has no type
	 */
	private static function dates( array &$appData, array $upcoming, string $dir ): array {

		$limit = Dates::now( $appData )?->modify( '+'. self::DATES_DAYS. ' days' );
		$rows = [];

		foreach( $limit === null ? [] : $upcoming as $date ) {

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

<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Projects		The component [crm-projects] of the portal: the projects of the client of the member signed in. Without a project in the
 *												query it is the list - the open projects first, the done ones under their own heading - and with ?projekt=<id> the page of
 *												one project: its head, then the sections Angebot (the released offers of the project with their lines and sums), Dateien (the
 *												files of the project's folder in the feature Files that the member may see, with the player of Review for an audio or video
 *												file), Belege (the released documents of the project, as [crm-documents] draws them) and Upload (the way to the page Upload).
 *
 *												Nothing here is stored or sent, and every value enters the markup escaped. A project of another client and an id that is none
 *												are the list with a sentence that the project is unknown. The player of Review returns to the page it stands on without the
 *												query (its form posts the path alone and is redirected to ?review=<file>), so a request with a file and no project is
 *												answered with the project whose folder holds that file.
 */
namespace Crm\Portal;

use Crm\Crm;
use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;

class Projects {

	// The most the short list shows
	public const int SHORT = 5;

	// The page the component stands on, from the directory the project is served from
	private const string PAGE = '/portal/projekte';

	/**
	 *	[crm-projects]
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args					open ('1': leave out the done projects) and short ('1': at most SHORT, always the list)
	 *
	 *	@return 	string
	 */
	public static function componentCrmProjects( array &$appData, array $args ): string {

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$client = Portal::client( $appData );

		if( $client === null )
			return self::message( 'none', $classes );

		$query = Portal::query( $appData );
		$short = ( $args['short'] ?? '0' ) === '1';
		$open = ( $args['open'] ?? '0' ) === '1';
		$unknown = false;

		if( $short === false && $open === false ) {

			$asked = is_string( $query['projekt'] ?? null ) ? $query['projekt'] : '';
			$review = is_string( $query['review'] ?? null ) ? $query['review'] : '';
			$project = null;

			if( $asked !== '' ) {
				$project = Projekte::get( $appData, $asked );
				$unknown = $project === null || $project['client'] !== $client['number'];
			}
			elseif( $review !== '' )
				$project = self::ofFile( $appData, $client, $review );

			if( $project !== null && $unknown === false )
				return self::page( $appData, $client, $project, $review, $classes );
		}

		return self::list( $appData, $client, $open, $short, $unknown, $classes );
	}

	/**
	 *	The list of the client's projects
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		bool			$open					Leave out the done ones
	 *	@param		bool			$short				At most SHORT
	 *	@param		bool			$unknown			Say that the project asked for is none of the client's
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function list( array &$appData, array $client, bool $open, bool $short, bool $unknown, string $classes ): string {

		$projects = Projekte::list( $appData, $client['number'], $open );

		if( $short === true )
			$projects = array_slice( $projects, 0, self::SHORT );

		$say = $unknown === true ? self::template( 'projects-unknown' ) : '';

		if( $projects === [] )
			return str_replace( [ '[[classes]]', '[[unknown]]', '[[message]]' ], [ $classes, $say, '[[/project/crm-portal/projects/none]]' ], self::template( 'projects-empty' ) );

		$counts = [];

		foreach( Documents::released( $appData, $client['id'] ) as $document )
			if( $document['project'] !== '' )
				$counts[$document['project']] = ( $counts[$document['project']] ?? 0 ) + 1;

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$rows = [ 'open' => '', 'done' => '' ];

		foreach( $projects as $project )
			$rows[$project['done'] === true ? 'done' : 'open'] .= self::row( $appData, $project, $counts[$project['id']] ?? 0, $dir );

		return str_replace(
			[ '[[classes]]', '[[unknown]]', '[[open]]', '[[done]]' ],
			[
				$classes,
				$say,
				$rows['open'] === '' ? '' : str_replace( '[[rows]]', $rows['open'], self::template( 'projects-open' ) ),
				$rows['done'] === '' ? '' : str_replace( '[[rows]]', $rows['done'], self::template( 'projects-done' ) ),
			],
			self::template( 'projects' )
		);
	}

	/**
	 *	One row of the list
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project
	 *	@param		int				$documents		How many released documents name it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string
	 */
	private static function row( array &$appData, array $project, int $documents, string $dir ): string {

		$day = Documents\Render::day( $project['started'] );
		$files = self::folder( $appData, $project ) === null ? null : count( Files::visible( $appData, $project['folder'] ) );

		return str_replace(
			[ '[[id]]', '[[state]]', '[[dir]]', '[[title]]', '[[started]]', '[[files]]', '[[documents]]' ],
			[
				\Nino\Modules\Components::escape( $project['id'] ),
				$project['done'] === true ? 'done' : 'open',
				$dir,
				\Nino\Modules\Components::escape( $project['title'] ),
				$day === '' ? '' : str_replace( [ '[[iso]]', '[[date]]' ], [ \Nino\Modules\Components::escape( $project['started'] ), \Nino\Modules\Components::escape( $day ) ], self::template( 'project-started' ) ),
				$files === null ? '' : self::count( 'files', $files ),
				self::count( 'documents', $documents ),
			],
			self::template( 'project-row' )
		);
	}

	/**
	 *	A count with the word of its number
	 *
	 *	@param		string		$kind					files or documents
	 *	@param		int				$number
	 *
	 *	@return 	string
	 */
	private static function count( string $kind, int $number ): string {

		return str_replace(
			[ '[[kind]]', '[[number]]', '[[word]]' ],
			[ $kind, (string) $number, '[[/project/crm-portal/projects/'. ( $kind === 'files' ? 'file' : 'document' ). '-'. ( $number === 1 ? 'one' : 'many' ). ']]' ],
			self::template( 'project-count' )
		);
	}

	/**
	 *	The page of one project
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project			As Projekte::get() has it, one of the client's
	 *	@param		string		$review				The file asked to be played, as the query has it
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function page( array &$appData, array $client, array $project, string $review, string $classes ): string {

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$documents = Documents::released( $appData, $client['id'], $project['id'] );
		$offers = '';

		foreach( $documents as $document )
			if( $document['category'] === 'offer' )
				$offers .= self::offer( $appData, $document, $dir );

		$day = Documents\Render::day( $project['started'] );
		$text = str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( $project['description'] ) );

		$sections = [
			'offer'     => $offers !== '' ? $offers : self::template( 'project-offer-none' ),
			'files'     => self::files( $appData, $project, $review, $dir ),
			'documents' => Portal::documentList( $appData, $documents, '', false ),
			'upload'    => str_replace( '[[dir]]', $dir, self::template( 'project-upload' ) ),
		];
		$html = '';

		foreach( $sections as $name => $content )
			$html .= str_replace( [ '[[name]]', '[[content]]' ], [ $name, $content ], self::template( 'project-section' ) );

		return str_replace(
			[ '[[classes]]', '[[id]]', '[[state]]', '[[dir]]', '[[title]]', '[[started]]', '[[description]]', '[[sections]]' ],
			[
				$classes,
				\Nino\Modules\Components::escape( $project['id'] ),
				$project['done'] === true ? 'done' : 'open',
				$dir,
				\Nino\Modules\Components::escape( $project['title'] ),
				$day === '' ? '' : str_replace( [ '[[iso]]', '[[date]]' ], [ \Nino\Modules\Components::escape( $project['started'] ), \Nino\Modules\Components::escape( $day ) ], self::template( 'project-started' ) ),
				$text === '' ? '' : str_replace( '[[text]]', $text, self::template( 'project-description' ) ),
				$html,
			],
			self::template( 'project' )
		);
	}

	/**
	 *	One released offer: what it is, its lines, its sums and the download of its PDF
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$released			As Documents::released() has it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										'' for an offer that cannot be read or whose state the words do not know
	 */
	private static function offer( array &$appData, array $released, string $dir ): string {

		$state = $released['expired'] === true ? 'expired' : $released['state'];
		$document = Documents::get( $appData, $released['id'] );

		if( $document === null || preg_match( '/^[a-z]{1,20}$/D', $state ) !== 1 )
			return '';

		$currency = \Crm\Settings::get( $appData, 'currency' );
		$regular = $document['vat_mode'] === 'regular';
		$totals = Documents\Money::totals( $document['lines'], (string) $document['vat_mode'], (bool) $document['reverse_charge'] );
		$lines = '';

		foreach( $document['lines'] as $index => $line ) {

			$unit = (string) $line['unit'] === '' ? '' : ' '. $line['unit'];

			$lines .= str_replace(
				[ '[[title]]', '[[description]]', '[[qty]]', '[[price]]', '[[rate]]', '[[amount]]' ],
				[
					\Nino\Modules\Components::escape( (string) $line['title'] ),
					(string) $line['description'] === '' ? '' : str_replace( '[[text]]', str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( (string) $line['description'] ) ), self::template( 'offer-line-description' ) ),
					\Nino\Modules\Components::escape( Documents\Money::formatQty( (int) $line['qty'] ). $unit ),
					\Nino\Modules\Components::escape( Documents\Money::format( (int) $line['price_net'], $currency ) ),
					$regular === true ? str_replace( '[[rate]]', \Nino\Modules\Components::escape( Documents\Money::formatRate( (int) $line['vat_rate'] ) ), self::template( 'offer-line-rate' ) ) : '',
					\Nino\Modules\Components::escape( Documents\Money::format( $totals['amounts'][$index], $currency ) ),
				],
				self::template( 'offer-line' )
			);
		}

		$sum = static fn( string $kind, string $label, int $cents ): string => str_replace(
			[ '[[kind]]', '[[label]]', '[[amount]]' ],
			[ $kind, \Nino\Modules\Components::escape( $label ), \Nino\Modules\Components::escape( Documents\Money::format( $cents, $currency ) ) ],
			self::template( 'offer-sum' )
		);
		$sums = '';

		if( $regular === true ) {

			$sums .= $sum( 'net', Documents\Render::text( 'totals.net' ), $totals['net'] );

			foreach( $totals['rates'] as $rate => $part )
				$sums .= $sum( 'vat', Documents\Render::text( 'totals.vat', Documents\Money::formatRate( $rate ), Documents\Money::format( $part['net'], $currency ) ), $part['vat'] );
		}

		$sums .= $sum( 'gross', Documents\Render::text( 'totals.gross' ), $totals['gross'] );
		$note = $regular === false ? 'vatnote.small_business' : ( (bool) $document['reverse_charge'] === true ? 'vatnote.reverse' : '' );
		$valid = Documents\Render::day( (string) ( $document['valid_until'] ?? '' ) );

		return str_replace(
			[ '[[id]]', '[[state]]', '[[title]]', '[[iso]]', '[[date]]', '[[valid]]', '[[lines]]', '[[sums]]', '[[note]]', '[[dir]]' ],
			[
				(string) $document['id'],
				$state,
				\Nino\Modules\Components::escape( $document['title'] !== '' ? $document['title'] : $document['number'] ),
				\Nino\Modules\Components::escape( $document['date'] ),
				\Nino\Modules\Components::escape( Documents\Render::day( $document['date'] ) ),
				$valid === '' ? '' : str_replace( [ '[[iso]]', '[[date]]' ], [ \Nino\Modules\Components::escape( (string) $document['valid_until'] ), \Nino\Modules\Components::escape( $valid ) ], self::template( 'offer-valid' ) ),
				$lines,
				$sums,
				$note === '' ? '' : str_replace( '[[text]]', \Nino\Modules\Components::escape( Documents\Render::text( $note ) ), self::template( 'offer-note' ) ),
				$dir,
			],
			self::template( 'offer' )
		);
	}

	/**
	 *	The section Dateien: the player where a file is asked for, then the files the member may see
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project
	 *	@param		string		$review				The file asked to be played
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function files( array &$appData, array $project, string $review, string $dir ): string {

		if( Crm::featureOn( $appData, 'files' ) === false )
			return self::message( 'files-off', '', 'project-message' );

		$folder = self::folder( $appData, $project );

		if( $folder === null )
			return self::message( 'files-none', '', 'project-message' );

		$files = Files::visible( $appData, $folder['id'] );

		if( $files === [] )
			return self::message( 'files-empty', '', 'project-message' );

		$player = '';

		if( $review !== '' && Crm::featureOn( $appData, 'review' ) === true )
			foreach( $files as $file )
				if( $file['id'] === $review && \Nino\Modules\Review::kind( $file['mime'] ) !== '' && \Nino\Modules\Files::path( $appData, $file['id'] ) !== null )
					$player = \Nino\Modules\Review::componentReviewPlayer( $appData, [] );

		return str_replace(
			[ '[[player]]', '[[rows]]' ],
			[ $player, Files::rows( $appData, $files, \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) ). self::PAGE. '?projekt='. rawurlencode( $project['id'] ). '&review=' ) ],
			self::template( 'project-files' )
		);
	}

	/**
	 *	The folder of a project in the feature Files, as it is one of the member's group
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project
	 *
	 *	@return 	array|null								As Files::folders() has it; null where Files is off, the project has no folder or the folder is not one of the group
	 */
	private static function folder( array &$appData, array $project ): ?array {

		$member = \Nino\Modules\Members::current( $appData );

		if( $project['folder'] === '' || $member === null || $member['group'] === '' || Crm::featureOn( $appData, 'files' ) === false )
			return null;

		foreach( \Nino\Modules\Files::folders( $appData, $member['group'] ) as $folder )
			if( $folder['id'] === $project['folder'] )
				return $folder;

		return null;
	}

	/**
	 *	The project of the client whose folder holds a file: where the comment form of Review has sent the member back to
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		string		$file					An id as the query has it
	 *
	 *	@return 	array|null
	 */
	private static function ofFile( array &$appData, array $client, string $file ): ?array {

		if( preg_match( \Nino\Modules\Files\Store::ID, $file ) !== 1 || Crm::featureOn( $appData, 'files' ) === false )
			return null;

		$entry = \Nino\Modules\Files::file( $appData, $file );

		if( $entry === null )
			return null;

		foreach( Projekte::ofClient( $appData, $client['number'] ) as $project )
			if( $project['folder'] !== '' && $project['folder'] === $entry['folder'] )
				return $project;

		return null;
	}

	/**
	 *	A sentence in the place of a list
	 *
	 *	@param		string		$name					The end of the key of its word, below /project/crm-portal/projects/
	 *	@param		string		$classes
	 *	@param		string		$template
	 *
	 *	@return 	string
	 */
	private static function message( string $name, string $classes, string $template = 'projects-empty' ): string {

		return str_replace( [ '[[classes]]', '[[unknown]]', '[[message]]' ], [ $classes, '', '[[/project/crm-portal/projects/'. $name. ']]' ], self::template( $template ) );
	}

	/**
	 *	@param		string		$name
	 *
	 *	@return 	string
	 */
	private static function template( string $name ): string {

		return Portal::template( $name );
	}
}

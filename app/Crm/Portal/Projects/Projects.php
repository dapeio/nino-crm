<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Projects		The component [crm-projects] of the portal: the projects of the client of the member signed in. Without a project in the
 *												query it is the list - the open projects first, the one that was last active at the top, the done ones under their own
 *												heading - and with ?projekt=<id> the page of one project: the kernel's tabs Angebot (the released offers of the project, the
 *												ones that can be ordered first and open, the done and the expired ones folded to a line), Dateien (the files of the
 *												project's folder in the feature Files that the member may see, one row for each title - the files that are versions of one
 *												title are one row - with the player of Review for an audio or video file and, over the player, the select of the versions of
 *												its title) and Belege (the released documents of the project, as [crm-documents] draws them). The head of the page - the
 *												breadcrumb, the title, the state and the description - is the page head's ([crm-portal-head], which reads current()). The tab that stands
 *												open is the one of the query (?tab=angebot, dateien or belege; the first where there is none) and always Dateien where a
 *												file is asked to be played. The tabs are links to the three sections, there from the server: the script of the kernel makes
 *												them tabs and the portal's own script writes the tab into the address, and without them the links jump to the panels, which
 *												stand open one under the other. The label of a tab carries the count of what is in it.
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

	// The tabs of the page of a project in their order: the value of ?tab= and the name of the section (its id is projekt-<name>) it opens
	public const array TABS = [ 'angebot' => 'offer', 'dateien' => 'files', 'belege' => 'documents' ];

	// The project of this request, once it was looked up
	private const string CURRENT = './crm/portal/project';

	/**
	 *	[crm-projects]
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args					open ('1': leave out the done projects) and short ('1': at most SHORT, always the list, under its heading with the way to all of them)
	 *
	 *	@return 	string
	 */
	public static function componentCrmProjects( array &$appData, array $args ): string {

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$client = Portal::client( $appData );

		if( $client === null )
			return self::message( $appData, 'none', $classes );

		$query = Portal::query( $appData );
		$short = ( $args['short'] ?? '0' ) === '1';
		$open = ( $args['open'] ?? '0' ) === '1';
		$unknown = false;

		if( $short === false && $open === false ) {

			$asked = is_string( $query['projekt'] ?? null ) ? $query['projekt'] : '';
			$review = is_string( $query['review'] ?? null ) ? $query['review'] : '';
			$project = self::current( $appData, $client );
			$unknown = $asked !== '' && $project === null;

			if( $project !== null )
				return self::page( $appData, $client, $project, $review, is_string( $query['tab'] ?? null ) ? $query['tab'] : '', $classes );
		}

		return self::list( $appData, $client, $open, $short, $unknown, $classes );
	}

	/**
	 *	The project the request is about: the one of ?projekt=<id> where it is the client's, else the one whose folder holds the file of ?review=<file>.
	 *	Looked up once for the request; the page head and the component both ask
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *
	 *	@return 	array|null								As Projekte::get() has it; null where the request names none of the client's
	 */
	public static function current( array &$appData, array $client ): ?array {

		$query = Portal::query( $appData );
		$asked = is_string( $query['projekt'] ?? null ) ? $query['projekt'] : '';
		$review = is_string( $query['review'] ?? null ) ? $query['review'] : '';
		$key = $client['number']. '|'. $asked. '|'. $review;

		// The answer is kept with what it was asked for, so that a second request in the same process (a test, a worker) never gets the answer of the first
		if( ( $appData[self::CURRENT]['key'] ?? null ) === $key )
			return $appData[self::CURRENT]['project'];

		$project = null;

		if( $asked !== '' ) {
			$project = Projekte::get( $appData, $asked );
			$project = $project !== null && $project['client'] === $client['number'] ? $project : null;
		}
		elseif( $review !== '' )
			$project = self::ofFile( $appData, $client, $review );

		$appData[self::CURRENT] = [ 'key' => $key, 'project' => $project ];

		return $project;
	}

	/**
	 *	The list of the client's projects, the one that was last active first: the day a document of it was released, a file came into its folder or
	 *	it began, whichever is latest
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		bool			$open					Leave out the done ones
	 *	@param		bool			$short				At most SHORT, under the heading of the section
	 *	@param		bool			$unknown			Say that the project asked for is none of the client's
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function list( array &$appData, array $client, bool $open, bool $short, bool $unknown, string $classes ): string {

		$projects = Projekte::list( $appData, $client['number'], $open );
		$say = $unknown === true ? self::template( 'projects-unknown' ) : '';
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		if( $projects === [] )
			return Portal::render( 'projects-empty', [ '[[classes]]' => $classes, '[[unknown]]' => $say, '[[empty]]' => Portal::emptyState( $appData, 'folder', '[[/project/crm-portal/projects/none]]', 'crm-projects__empty' ) ] );

		$counts = [];
		$active = [];

		foreach( Documents::released( $appData, $client['id'] ) as $document )
			if( $document['project'] !== '' ) {
				$counts[$document['project']] = ( $counts[$document['project']] ?? 0 ) + 1;
				$active[$document['project']] = max( $active[$document['project']] ?? '', substr( $document['released_at'], 0, 10 ), $document['date'] );
			}

		$files = [];

		foreach( $projects as $project ) {

			$files[$project['id']] = self::folder( $appData, $project ) === null ? null : Files::visible( $appData, $project['folder'] );
			$active[$project['id']] = max( $active[$project['id']] ?? '', $project['started'] );

			foreach( $files[$project['id']] ?? [] as $file )
				$active[$project['id']] = max( $active[$project['id']], substr( (string) $file['created'], 0, 10 ) );
		}

		// The latest day first; of two on a day the newer project (the list has it first already) - a stable sort keeps it so
		usort( $projects, static fn( array $a, array $b ): int => strcmp( $active[$b['id']], $active[$a['id']] ) );

		if( $short === true )
			$projects = array_slice( $projects, 0, self::SHORT );

		$rows = [ 'open' => '', 'done' => '' ];

		foreach( $projects as $project )
			$rows[$project['done'] === true ? 'done' : 'open'] .= self::row( $project, $counts[$project['id']] ?? 0, $files[$project['id']] === null ? null : count( $files[$project['id']] ), $dir );

		return Portal::render( 'projects', [
			'[[classes]]'  => $classes. ( $short === true ? ' crm-projects--short' : '' ),
			'[[labelled]]' => $short === true ? ' aria-labelledby="crm-projects-heading"' : '',
			'[[heading]]'  => $short === true ? Portal::render( 'projects-heading', [ '[[dir]]' => $dir ] ) : '',
			'[[unknown]]'  => $say,
			'[[open]]'     => $rows['open'] === '' ? '' : Portal::render( 'projects-open', [ '[[rows]]' => $rows['open'] ] ),
			'[[done]]'     => $rows['done'] === '' ? '' : Portal::render( 'projects-done', [ '[[rows]]' => $rows['done'] ] ),
		] );
	}

	/**
	 *	One row of the list: a card that links the page of the project
	 *
	 *	@param		array 		$project
	 *	@param		int				$documents		How many released documents name it
	 *	@param		int|null	$files				How many files the member may see; null where there is no folder to count
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string
	 */
	private static function row( array $project, int $documents, ?int $files, string $dir ): string {

		$day = Documents\Render::day( $project['started'] );
		$state = $project['done'] === true ? 'done' : 'open';

		return Portal::render( 'project-row', [
			'[[id]]'        => \Nino\Modules\Components::escape( $project['id'] ),
			'[[state]]'     => $state,
			'[[tone]]'      => Portal::tone( $state ),
			'[[dir]]'       => $dir,
			'[[title]]'     => \Nino\Modules\Components::escape( $project['title'] ),
			'[[started]]'   => $day === '' ? '' : Portal::render( 'project-started', [ '[[iso]]' => \Nino\Modules\Components::escape( $project['started'] ), '[[date]]' => \Nino\Modules\Components::escape( $day ) ] ),
			'[[files]]'     => $files === null ? '' : self::count( 'files', $files ),
			'[[documents]]' => self::count( 'documents', $documents ),
		] );
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

		return Portal::render( 'project-count', [
			'[[kind]]'   => $kind,
			'[[number]]' => (string) $number,
			'[[word]]'   => '[[/project/crm-portal/projects/'. ( $kind === 'files' ? 'file' : 'document' ). '-'. ( $number === 1 ? 'one' : 'many' ). ']]',
		] );
	}

	/**
	 *	The page of one project: its tabs
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project			As Projekte::get() has it, one of the client's
	 *	@param		string		$review				The file asked to be played, as the query has it
	 *	@param		string		$tab					The tab asked for, as the query has it
	 *	@param		string		$classes
	 *
	 *	@return 	string
	 */
	private static function page( array &$appData, array $client, array $project, string $review, string $tab, string $classes ): string {

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );
		$documents = Documents::released( $appData, $client['id'], $project['id'] );
		$files = self::files( $appData, $project, $review );
		$sections = [
			'offer'     => self::offers( $appData, $client, $documents, $dir ),
			'files'     => $files,
			'documents' => [ Portal::documentList( $appData, $documents, '', false ), count( $documents ) ],
		];

		// A file asked to be played is played in Dateien; else the tab of the query, and the first where it names none
		$open = $review !== '' ? 'files' : ( self::TABS[$tab] ?? 'offer' );
		$queries = array_flip( self::TABS );
		$tabs = '';
		$html = '';

		foreach( $sections as $name => $section ) {

			$active = $name === $open ? ' nino-is-active' : '';
			$tabs .= Portal::render( 'project-tab', [
				'[[name]]'   => $name,
				'[[query]]'  => $queries[$name],
				'[[active]]' => $active,
				'[[count]]'  => $section[1] === null ? '' : Portal::render( 'project-tab-count', [ '[[number]]' => (string) $section[1] ] ),
			] );
			$html .= Portal::render( 'project-section', [ '[[name]]' => $name, '[[active]]' => $active, '[[content]]' => $section[0] ] );
		}

		return Portal::render( 'project', [
			'[[classes]]'  => $classes,
			'[[id]]'       => \Nino\Modules\Components::escape( $project['id'] ),
			'[[state]]'    => $project['done'] === true ? 'done' : 'open',
			'[[tabs]]'     => $tabs,
			'[[sections]]' => $html,
		] );
	}

	/**
	 *	The section Angebot: the released offers of the project, the ones that can be ordered first, each open, and under their own line the rest, each
	 *	folded to its head - but open where the query names it, so that the message of an order stands in sight. Where the client has to agree to the
	 *	Datenschutz first, the section says so once at its top; the message of an order that names no offer stands there too
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$documents		As Documents::released() has them
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	array											The markup and the count of offers
	 */
	private static function offers( array &$appData, array $client, array $documents, string $dir ): array {

		$named = Order::named( $appData );
		$orderable = '';
		$rest = '';
		$count = 0;
		$waits = false;

		foreach( $documents as $document ) {

			if( $document['category'] !== 'offer' )
				continue;

			$open = Documents::orderable( $document ) === true;
			$waits = $waits === true || $open === true;
			$card = Offer::card( $appData, $client, $document, $dir, 'projekt', $open === true || $named === (int) $document['id'] );

			if( $card === '' )
				continue;

			$count++;

			if( $open === true )
				$orderable .= $card;
			else
				$rest .= $card;
		}

		$hint = $waits === true && Portal::pending( $appData, $client ) === 'privacy' ? Order::privacyHint( $dir ) : '';
		$html = $hint. Order::state( $appData ). $orderable. ( $rest === '' ? '' : ( $orderable === '' ? '' : self::template( 'project-offers-rest' ) ). $rest );

		return [ $count === 0 ? self::template( 'project-offer-none' ) : $html, $count ];
	}

	/**
	 *	The section Dateien: the player where a file is asked for, with the versions of its title over it, then the titles of the files the member may see
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project
	 *	@param		string		$review				The file asked to be played
	 *
	 *	@return 	array											The markup and the count of files the member may see (null where there is no folder to count)
	 */
	private static function files( array &$appData, array $project, string $review ): array {

		if( Crm::featureOn( $appData, 'files' ) === false )
			return [ self::message( $appData, 'files-off', '', 'project-message' ), null ];

		$folder = self::folder( $appData, $project );

		if( $folder === null )
			return [ self::message( $appData, 'files-none', '', 'project-message' ), null ];

		$files = Files::visible( $appData, $folder['id'] );

		if( $files === [] )
			return [ self::message( $appData, 'files-empty', '', 'project-message' ), 0 ];

		$player = '';
		$playing = '';
		$page = \Nino\Filesystem::getDir( $appData ). self::PAGE;

		if( $review !== '' && Crm::featureOn( $appData, 'review' ) === true )
			foreach( \Nino\Modules\Files::grouped( $files ) as $title )
				foreach( $title['versions'] as $file ) {

					if( $file['id'] !== $review || \Nino\Modules\Review::kind( $file['mime'] ) === '' )
						continue;

					// The versions of the title stand over the player: the folder of this project holds them, and no file of another project or client can be among them
					$versions = Files::versions( $appData, $title, $file['id'], $project['id'], $page );

					// A file that was removed from the server has its comments and no medium: the sentence stands over them
					if( isset( $file['gone'] ) === true )
						$player = Files::goneNote( $appData ). \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'versions' => $versions ] );
					elseif( \Nino\Modules\Files::path( $appData, $file['id'] ) !== null ) {
						$player = \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'versions' => $versions ] );
						$playing = $file['id'];
					}
				}

		return [ Portal::render( 'project-files', [
			'[[player]]' => $player,
			'[[rows]]'   => Files::titles( $appData, $files, $page. '?projekt='. rawurlencode( $project['id'] ). '&review=', $playing ),
		] ), count( $files ) ];
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
	 *	A sentence in the place of a list: an empty state that says what happens next
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$name					The end of the key of its word, below /project/crm-portal/projects/
	 *	@param		string		$classes
	 *	@param		string		$template			projects-empty (the component's box) or project-message (a section of the page)
	 *
	 *	@return 	string
	 */
	private static function message( array &$appData, string $name, string $classes, string $template = 'projects-empty' ): string {

		$word = '[[/project/crm-portal/projects/'. $name. ']]';

		if( $template === 'project-message' )
			return Portal::render( 'project-message', [ '[[icon]]' => 'folder', '[[message]]' => $word ] );

		return Portal::render( 'projects-empty', [ '[[classes]]' => $classes, '[[unknown]]' => '', '[[empty]]' => Portal::emptyState( $appData, 'folder', $word, 'crm-projects__empty' ) ] );
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

<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Projects		The component [crm-projects] of the portal: the projects of the client of the member signed in. Without a project in the
 *												query it is the list - the open projects first, the one that was last active at the top, the done ones under their own
 *												heading - and with ?projekt=<id> the page of one project: the kernel's tabs Angebot (the released offers of the project, the
 *												ones that can be ordered first and open, the done and the expired ones folded to a line), Dateien (the files of the
 *												project's folder in the feature Files that the member may see, one row for each title - the files that are versions of one
 *												title are one row - with the player of Review beside the list as a column and, over the player, the select of the versions of
 *												its title), Belege (the released documents of the project, as [crm-documents] draws them) and Übersicht (the project at a glance:
 *												its title, badges and description, what waits for the client with the form of the order of an offer, and the data). The head of
 *												the page is the breadcrumb alone ([crm-portal-head], which reads current()), so that the tabs stand directly under it. The tab that stands
 *												open is the one of the query (?tab=angebot, dateien, belege or uebersicht; Übersicht where there is none) and always Dateien where a
 *												file is asked to be played. The tabs are links to the four sections, there from the server: the script of the kernel makes
 *												them tabs and the portal's own script writes the tab into the address, and without them the links jump to the panels, which
 *												stand open one under the other. The label of a tab carries the count of what is in it (Übersicht has none).
 *
 *												The files are the owner's to give: while an offer of the project waits for the order (Projekte::waiting()) the tab Dateien is a note that
 *												leads to the offer - no list, no player, no count -, and while the download of the project is not switched on the media (audio and video)
 *												are for listening and their rows have no download, which a note over the list says (Portal\Files); any other file loads always. Under the
 *												list of files stands the block Abnahme (Portal\Approval).
 *
 *												Nothing here is stored or sent, and every value enters the markup escaped. A project of another client and an id that is none
 *												are the list with a sentence that the project is unknown. The player of Review returns to the page it stands on without the
 *												query (its form posts the path alone and is redirected to ?review=<file>), so a request with a file and no project is
 *												answered with the project whose folder holds that file.
 *
 *												The player is drawn by player() for the page and for the route GET /.crm/player?file=<id> (callbackPlayer()), which the script of the portal asks to open
 *												a file or another version without the page loading again; the page with ?review=<id> stays the way without the script.
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

	// The tabs of the page of a project: the value of ?tab= and the name of the section (its id is projekt-<name>) it opens; their order on the page is that of the sections in page()
	public const array TABS = [ 'angebot' => 'offer', 'dateien' => 'files', 'belege' => 'documents', 'uebersicht' => 'overview' ];

	// The route that answers the player of a file (see callbackPlayer())
	public const string ROUTE = '/.crm/player';

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

		[ 'projects' => $projects, 'counts' => $counts, 'files' => $files ] = self::ranked( $appData, $client, $open );
		$say = $unknown === true ? self::template( 'projects-unknown' ) : '';
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		if( $projects === [] )
			return Portal::render( 'projects-empty', [ '[[classes]]' => $classes, '[[unknown]]' => $say, '[[empty]]' => Portal::emptyState( $appData, 'folder', '[[/project/crm-portal/projects/none]]', 'crm-projects__empty' ) ] );

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
	 *	The project of the client that was last active and is not done: where the portal leads a client who has nothing waiting for it (see ranked() for what active is)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *
	 *	@return 	array|null								As Projekte::get() has it; null where the client has no open project
	 */
	public static function latest( array &$appData, array $client ): ?array {

		return self::ranked( $appData, $client, true )['projects'][0] ?? null;
	}

	/**
	 *	The projects of the client in the order of the list, the one that was last active first: the day a document of it was released, a file came into its folder
	 *	(one the member may see) or it began, whichever is latest
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		bool			$open					Leave out the done ones
	 *
	 *	@return 	array											projects (as Projekte::list() has them), counts (the released documents of each project, by its id) and files (the files the member may see in the folder of each, by the id of the project; null for a project without a folder to look into)
	 */
	private static function ranked( array &$appData, array $client, bool $open ): array {

		$projects = Projekte::list( $appData, $client['number'], $open );

		if( $projects === [] )
			return [ 'projects' => [], 'counts' => [], 'files' => [] ];

		$counts = [];
		$active = [];

		foreach( Documents::released( $appData, $client['id'] ) as $document )
			if( $document['project'] !== '' ) {
				$counts[$document['project']] = ( $counts[$document['project']] ?? 0 ) + 1;
				$active[$document['project']] = max( $active[$document['project']] ?? '', substr( $document['released_at'], 0, 10 ), $document['date'] );
			}

		$files = [];

		foreach( $projects as $project ) {

			// The files of a project whose offer waits are not counted: the page of the project does not list them either
			$files[$project['id']] = self::folder( $appData, $project ) === null || Projekte::waiting( $appData, $project['id'], (int) $client['id'] ) !== null ? null : Files::visible( $appData, $project['folder'] );
			$active[$project['id']] = max( $active[$project['id']] ?? '', $project['started'] );

			foreach( $files[$project['id']] ?? [] as $file )
				$active[$project['id']] = max( $active[$project['id']], substr( (string) $file['created'], 0, 10 ) );
		}

		// The latest day first; of two on a day the newer project (the list has it first already) - a stable sort keeps it so
		usort( $projects, static fn( array $a, array $b ): int => strcmp( $active[$b['id']], $active[$a['id']] ) );

		return [ 'projects' => $projects, 'counts' => $counts, 'files' => $files ];
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
	 *	@param		string		$kind					files, documents or offers
	 *	@param		int				$number
	 *
	 *	@return 	string
	 */
	private static function count( string $kind, int $number ): string {

		return Portal::render( 'project-count', [
			'[[kind]]'   => $kind,
			'[[number]]' => (string) $number,
			'[[word]]'   => '[[/project/crm-portal/projects/'. [ 'files' => 'file', 'documents' => 'document', 'offers' => 'offer' ][$kind]. '-'. ( $number === 1 ? 'one' : 'many' ). ']]',
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
		$files = self::files( $appData, $client, $project, $review );
		$offers = self::offers( $appData, $client, $documents, $dir );
		$sections = [
			'offer'     => $offers,
			'files'     => $files,
			'documents' => [ Portal::documentList( $appData, $documents, '', false ), count( $documents ) ],
		];

		// The tab Übersicht is the last, with no count: it counts the others itself
		$sections['overview'] = [ self::overview( $appData, $client, $project, $documents, [ 'files' => $files[1], 'offers' => $offers[1], 'documents' => count( $documents ) ], $files[2], $dir ), null ];

		// A file asked to be played is played in Dateien; else the tab of the query, and Übersicht where it names none
		$open = $review !== '' ? 'files' : ( self::TABS[$tab] ?? 'overview' );
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
	 *	The tab Übersicht: the project at a glance. At the top its title, the line of its badges (the state, abgenommen, the start) and its description; then,
	 *	only where something waits, the card of what the client has to do - the offer that can be ordered with the form of its order (the form of the tab Angebot, posting
	 *	from here), the approval while the project can be approved (a sentence with the way to the block in the tab Dateien) and an invoice of the project that is
	 *	open, with the way to its row in the tab Belege; the note of an order that was made from here stands in that card -, then the card of the data: the client, the
	 *	start, the state, the download, and how many files, offers and documents the project has
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project			As Projekte::get() has it
	 *	@param		array 		$documents		As Documents::released() has them for the project
	 *	@param		array 		$counts				files (null where the tab Dateien counts none), offers and documents
	 *	@param		array|null	$files				The files the member may see, as files() read them; null where there are none to look at
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function overview( array &$appData, array $client, array $project, array $documents, array $counts, ?array $files, string $dir ): string {

		// A null byte is no part of a title or a text
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( str_replace( "\0", '', $value ) );
		$id = $escape( $project['id'] );
		$state = $project['done'] === true ? 'done' : 'open';
		$day = Documents\Render::day( $project['started'] );
		$approved = Approval::approved( $appData, $client, $project ) !== null ? Portal::template( 'head-approved' ) : '';
		$since = $day === '' ? '' : Portal::render( 'head-since', [ '[[iso]]' => $escape( $project['started'] ), '[[date]]' => $escape( $day ) ] );
		$items = '';

		foreach( $documents as $document ) {

			if( $document['category'] === 'offer' && Documents::orderable( $document ) === true ) {

				$valid = Documents\Render::day( (string) $document['valid_until'] );
				$items .= Portal::render( 'overview-offer', [
					'[[id]]'      => (string) $document['id'],
					'[[project]]' => rawurlencode( $project['id'] ),
					'[[dir]]'     => $dir,
					'[[title]]'   => Portal::documentTitle( $document ),
					'[[number]]'  => $escape( $document['number'] !== '' ? $document['number'] : $document['title'] ),
					'[[gross]]'   => Portal::amount( $appData, $document ),
					'[[valid]]'   => $valid === '' ? '' : Portal::render( 'offer-valid', [ '[[iso]]' => $escape( (string) $document['valid_until'] ), '[[date]]' => $escape( $valid ) ] ),
					'[[order]]'   => Portal::pending( $appData, $client ) === 'privacy' ? Order::privacyHint( $dir ) : Order::block( $appData, $client, $document, 'uebersicht', $dir ),
				] );
			}
		}

		if( $files !== null && Approval::standing( $appData, $client, $project, $files )['open'] === true )
			$items .= Portal::render( 'overview-approval', [ '[[project]]' => rawurlencode( $project['id'] ), '[[dir]]' => $dir ] );

		foreach( $documents as $document ) {

			$due = Portal::customerState( $document );

			if( $document['category'] === 'invoice' && in_array( $due, [ 'open', 'overdue' ], true ) === true )
				$items .= Portal::render( 'overview-invoice', [
					'[[id]]'      => (string) $document['id'],
					'[[project]]' => rawurlencode( $project['id'] ),
					'[[dir]]'     => $dir,
					'[[title]]'   => Portal::documentTitle( $document ),
					'[[number]]'  => $escape( $document['number'] !== '' ? $document['number'] : $document['title'] ),
					'[[gross]]'   => Portal::amount( $appData, $document ),
					'[[tone]]'    => Portal::tone( $due ),
					'[[word]]'    => Portal::stateWord( $due ),
					'[[due]]'     => Portal::dueCell( $document, $due ),
				] );
		}

		// The note of an order made from this tab: it stands over what waits, and alone where nothing waits any more
		$note = Order::state( $appData, Order::named( $appData ), 'uebersicht' );
		$needs = $items === '' && $note === '' ? '' : Portal::render( 'overview-needs', [ '[[state]]' => $note, '[[items]]' => $items === '' ? '' : Portal::render( 'overview-items', [ '[[items]]' => $items ] ) ] );
		$download = Projekte::state( $appData, $project['id'] );
		$released = Documents\Render::day( substr( $download['download_at'], 0, 10 ) );
		$folder = self::folder( $appData, $project ) !== null;
		$waits = $folder === true && Projekte::waiting( $appData, $project['id'], (int) $client['id'] ) !== null;
		$word = $waits === true ? 'waiting' : ( $download['download'] === false ? 'locked' : ( $released === '' ? 'free' : 'since' ) );
		$counted = array_filter( [ $counts['files'] === null ? '' : self::count( 'files', $counts['files'] ), self::count( 'offers', $counts['offers'] ), self::count( 'documents', $counts['documents'] ) ], static fn( string $count ): bool => $count !== '' );
		$facts = [
			'client'   => [ 'overview-client', $escape( $client['company'] !== '' ? $client['company'] : $client['name'] ) ],
			'started'  => [ 'started', $day === '' ? '' : Portal::render( 'overview-since', [ '[[iso]]' => $escape( $project['started'] ), '[[date]]' => $escape( $day ) ] ) ],
			'state'    => [ 'overview-state', Portal::render( 'overview-state', [ '[[tone]]' => Portal::tone( $state ), '[[state]]' => '[[/project/crm-portal/projects/state-'. $state. ']]', '[[approved]]' => $approved ] ) ],
			'download' => [ 'overview-download', $folder === false ? '' : Portal::render( 'overview-download', [
				'[[state]]' => $download['download'] === true && $waits === false ? 'free' : 'locked',
				'[[word]]'  => '[[/project/crm-portal/projects/overview-download-'. $word. ']]',
				'[[since]]' => $word === 'since' ? ' '. Portal::render( 'overview-since', [ '[[iso]]' => $escape( substr( $download['download_at'], 0, 10 ) ), '[[date]]' => $escape( $released ) ] ) : '',
			] ) ],
			// The counts are separated for a screen reader: "3 Dateien, 2 Angebote, 3 Belege"
			'content'  => [ 'overview-content', Portal::render( 'overview-content', [ '[[counts]]' => implode( Portal::template( 'overview-sep' ), $counted ) ] ) ],
		];
		$rows = '';

		foreach( $facts as $name => [ $label, $value ] )
			if( $value !== '' )
				$rows .= Portal::render( 'overview-row', [ '[[name]]' => $name, '[[label]]' => '[[/project/crm-portal/projects/'. $label. ']]', '[[value]]' => $value ] );

		return Portal::render( 'project-overview', [
			'[[title]]'       => $escape( $project['title'] ),
			'[[badge]]'       => Portal::render( 'head-badge', [ '[[tone]]' => Portal::tone( $state ), '[[state]]' => '[[/project/crm-portal/projects/state-'. $state. ']]', '[[approved]]' => $approved, '[[since]]' => $since ] ),
			'[[description]]' => $project['description'] === '' ? '' : Portal::render( 'project-description', [ '[[text]]' => str_replace( [ "\r\n", "\r", "\n" ], '<br>', $escape( $project['description'] ) ) ] ),
			'[[needs]]'       => $needs,
			'[[rows]]'        => $rows,
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
	 *	The section Dateien: the player where a file is asked for, with the versions of its title over it, then the titles of the files the member may see. While an offer
	 *	of the project waits for the order the section is the note that leads to the offer and nothing else; while the download is off a note over the list says that the
	 *	files are for listening
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project
	 *	@param		string		$review				The file asked to be played
	 *
	 *	@return 	array											The markup, the count of files the member may see (null where there is no folder to count and where the offer comes first) and those files (null where there is nothing to look at)
	 */
	private static function files( array &$appData, array $client, array $project, string $review ): array {

		if( Crm::featureOn( $appData, 'files' ) === false )
			return [ self::message( $appData, 'files-off', '', 'project-message' ), null, null ];

		$folder = self::folder( $appData, $project );

		if( $folder === null )
			return [ self::message( $appData, 'files-none', '', 'project-message' ), null, null ];

		$waiting = Projekte::waiting( $appData, $project['id'], (int) $client['id'] );

		if( $waiting !== null )
			return [ self::waitingNote( $appData, $project, $waiting ), null, null ];

		$files = Files::visible( $appData, $folder['id'] );

		if( $files === [] )
			return [ self::message( $appData, 'files-empty', '', 'project-message' ), 0, [] ];

		$page = \Nino\Filesystem::getDir( $appData ). self::PAGE;
		$review = $review !== '' && Crm::featureOn( $appData, 'review' ) === true ? $review : '';
		$found = $review === '' ? null : self::locate( $files, $review );
		$player = $found === null ? '' : self::player( $appData, $client, $project, $review, $files );

		// The row of the file that plays is marked: a file that was removed from the server has its comments and no medium, and nothing plays
		$playing = $found !== null && isset( $found['gone'] ) === false && \Nino\Modules\Files::path( $appData, $found['id'] ) !== null ? $found['id'] : '';

		// The player is a column beside the list (over it on a narrow screen); with no file chosen it is an empty card that says how to open one. Without Review there is no player
		if( Crm::featureOn( $appData, 'review' ) === true )
			$player = Portal::render( 'project-player', [ '[[state]]' => $player === '' ? ' crm-player--empty' : '', '[[source]]' => \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ). self::ROUTE ), '[[player]]' => $player === '' ? Portal::template( 'project-player-empty' ) : $player ] );

		// The note over the list is for the media: they are there to listen to until the project's download is switched on
		$locked = $project['download'] === false && array_filter( $files, static fn( array $file ): bool => Portal::medium( $appData, $file['mime'] ) === true ) !== [];

		return [ Portal::render( 'project-files', [
			'[[note]]'     => $locked === false ? '' : Portal::render( 'note', [ '[[classes]]' => 'crm-project__locked', '[[tone]]' => 'info', '[[role]]' => 'note', '[[icon]]' => 'lock', '[[text]]' => '[[/project/crm-portal/projects/files-locked]]' ] ),
			'[[player]]'   => $player,
			'[[mark]]'     => Portal::template( 'file-playing' ),
			'[[rows]]'     => Files::titles( $appData, $files, $page. '?projekt='. rawurlencode( $project['id'] ). '&review=', $playing, $project['download'] ),
			'[[approval]]' => Approval::block( $appData, $client, $project, $files, \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) ) ),
		] ), count( $files ), $files ];
	}

	/**
	 *	The player of one file of a project as the page puts it in the column beside the list and as the route /.crm/player answers it: the versions of the title over the
	 *	player of Review with its comments and the form, and over all of it the sentence that a file which was removed from the server is gone. While an offer of the project
	 *	waits for the order it is the note that leads to the offer instead, as the page puts it in the place of the files. Nothing where Review is off, the file is none of
	 *	these files, is no audio or video, or has no bytes and is not a removed one
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		array 		$project			As Projekte::get() has it, one of the client's
	 *	@param		string		$review				The id of the file, as the query has it
	 *	@param		array 		$files				The files the member may see, as Files::visible() has them
	 *
	 *	@return 	string										Markup; '' for no player
	 */
	public static function player( array &$appData, array $client, array $project, string $review, array $files ): string {

		$waiting = Projekte::waiting( $appData, $project['id'], (int) $client['id'] );

		// While an offer waits no file is given, so the list is empty: the note stands only in the place of the player of a medium that the member would see otherwise
		if( $waiting !== null ) {

			$entry = preg_match( \Nino\Modules\Files\Store::ID, $review ) === 1 ? \Nino\Modules\Files::file( $appData, $review ) : null;
			$member = \Nino\Modules\Members::current( $appData );
			$own = $entry !== null && $member !== null && ( $entry['by']['kind'] ?? '' ) === 'member' && ( $entry['by']['id'] ?? '' ) === $member['id'];
			$folder = $entry === null ? null : \Nino\Modules\Files\Store::folder( $appData, (string) $entry['folder'] );
			// The rule of Files::allowed(): the member's own upload, or a visible file in a folder of the member's group
			$grouped = $member !== null && $member['group'] !== '' && $folder !== null && $folder['group'] === $member['group'];
			$shown = $entry !== null && $entry['folder'] === $project['folder'] && ( $own === true || ( ( $entry['visible'] ?? false ) === true && $grouped === true ) ) && \Nino\Modules\Review::kind( $entry['mime'] ) !== '';

			return Crm::featureOn( $appData, 'review' ) === true && $shown === true ? self::waitingNote( $appData, $project, $waiting ) : '';
		}

		$file = Crm::featureOn( $appData, 'review' ) === true ? self::locate( $files, $review ) : null;

		if( $file === null )
			return '';

		// The versions of the title stand over the player: the folder of this project holds them, and no file of another project or client can be among them
		foreach( \Nino\Modules\Files::grouped( $files ) as $title ) {

			if( in_array( $file['id'], array_column( $title['versions'], 'id' ), true ) === false )
				continue;

			$versions = Files::versions( $appData, $title, $file['id'], $project['id'], \Nino\Filesystem::getDir( $appData ). self::PAGE );
			$player = Files::beside( \Nino\Modules\Review::componentReviewPlayer( $appData, [ 'versions' => $versions, 'file' => $file['id'], 'path' => self::PAGE ] ) );

			// A file that was removed from the server has its comments and no medium: the sentence stands over them
			if( isset( $file['gone'] ) === true )
				return Files::goneNote( $appData ). $player;

			return \Nino\Modules\Files::path( $appData, $file['id'] ) !== null ? $player : '';
		}

		return '';
	}

	/**
	 *	The file of a list that the player plays: the one with this id, if it is audio or video
	 *
	 *	@param		array 		$files				As Files::visible() has them
	 *	@param		string		$review				An id as the query has it
	 *
	 *	@return 	array|null
	 */
	private static function locate( array $files, string $review ): ?array {

		foreach( $files as $file )
			if( $file['id'] === $review && \Nino\Modules\Review::kind( $file['mime'] ) !== '' )
				return $file;

		return null;
	}

	/**
	 *	GET /.crm/player?file=<id>: the player of a file as the page of the project puts it beside the list (see player()), for the script that opens a file or another
	 *	version without leaving the page. Only a member of a client is answered, and only for a file in the folder of a project of the client that the member may see;
	 *	a visitor, a member of no client, a member whose client has a step of the gate pending, an id that is none or a list, a file of another client and a file that
	 *	is no audio or video are the 404 with a plain body. While an offer of the project waits for the order the answer is its note. The answer is the markup alone -
	 *	no frame, no script - and is never kept
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPlayer( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$query = $request['/nino/http/request']['query'] ?? [];
		$file = is_array( $query ) === true && is_string( $query['file'] ?? null ) === true ? $query['file'] : '';
		$project = $client === null || Crm::featureOn( $appData, 'review' ) === false || Portal::pending( $appData, $client ) !== '' ? null : self::ofFile( $appData, $client, $file );
		$html = '';

		if( $client !== null && $project !== null ) {

			Portal::words( $appData );
			$html = self::player( $appData, $client, $project, $file, self::visibleOf( $appData, $project ) ?? [] );
			$html = $html === '' ? '' : \Nino\Html::renderHtml( $appData, $html );
		}

		$request['/nino/http/response']['header']['Cache-Control'] = 'no-store';
		$request['/nino/http/response']['header']['X-Content-Type-Options'] = 'nosniff';

		if( $html === '' ) {
			$request['/nino/http/response']['statusCode'] = 404;
			$request['/nino/http/response']['body'] = 'Not Found';
			$request['/nino/http/response']['header']['Content-Type'] = 'text/plain; charset=utf-8';

			return;
		}

		$request['/nino/http/response']['statusCode'] = 200;
		$request['/nino/http/response']['body'] = $html;
		$request['/nino/http/response']['header']['Content-Type'] = 'text/html; charset=utf-8';
	}

	/**
	 *	The files of the folder of a project that the member signed in may see: what the page of the project lists and what an approval is of
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project			As Projekte::get() has it
	 *
	 *	@return 	array|null								As Files::files() has them; null where the project has no folder of the member's group or Files is off
	 */
	public static function visibleOf( array &$appData, array $project ): ?array {

		$folder = self::folder( $appData, $project );

		return $folder === null ? null : Files::visible( $appData, $folder['id'] );
	}

	/**
	 *	The note that stands in the place of the files while an offer waits: it names the offer and leads to its card
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$project
	 *	@param		array 		$offer				As Documents::released() has it
	 *
	 *	@return 	string										Markup
	 */
	private static function waitingNote( array &$appData, array $project, array $offer ): string {

		$name = $offer['number'] !== '' ? \Nino\Modules\Components::escape( $offer['number'] ) : Portal::documentTitle( $offer );

		return Portal::render( 'note', [
			'[[classes]]' => 'crm-project__waiting',
			'[[tone]]'    => 'warning',
			'[[role]]'    => 'note',
			'[[icon]]'    => 'receipt',
			'[[text]]'    => Portal::render( 'project-files-waiting', [
				'[[dir]]'     => \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) ),
				'[[project]]' => rawurlencode( $project['id'] ),
				'[[id]]'      => (string) $offer['id'],
				'[[name]]'    => $name,
			] ),
		] );
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

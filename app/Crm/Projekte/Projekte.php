<?php
declare(strict_types=1);
/**
 *	Crm\Projekte		What the owner works on for a client, read from the elements of the type /projekte. The projects are made, changed and
 *								removed in the screen Projekte of the surface and in the Elements panel (the surface drives the panel's own actions for this type,
 *								see Surface\Elements); this class only reads them. A document names a project by its id - the last part of its uri, the number the
 *								type gives its elements (00001) - and the folder of a project in the feature Files by the id the folder has there.
 *
 *								Every field of a project is the same in all languages, so the project is read as the element holds it ('*'). The type itself is
 *								brought by the install unit of the module (install/projekte.php, applied by Einrichten and Portal einrichten): missing() says
 *								whether it is there, and while it is not, list() has nothing. Reading a type that is not there is an error of the kernel, which is
 *								why the type is asked for first.
 *
 *								Whether the client of a project may download its files is the owner's decision, made for each project and kept in the table
 *								project_states (state(), setDownload()) - not in a field of the type, which an installed site never gets anew. A project without
 *								a row has no download: its client listens to the files and does not save them. While an offer of the project waits for the
 *								client's order (waiting()) the files are not open at all. The portal reads both (Portal::callbackFileAllowed(), Portal\Files, Portal\Projects).
 */
namespace Crm;

class Projekte {

	// The element type, and the file the kernel keeps it in
	public const string TYPE = '/projekte';
	private const string FILE = '/elements/projekte.php';

	// What an id looks like: the number the type gives (00001), or any name an element of the type has - the same pattern the screens take
	public const string ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D';

	// The time of the day a state is written with
	private const string TIME = 'Y-m-d\TH:i:s\Z';
	private const string LOOKUPS = './crm/projekte/lookups';

	/**
	 *	Whether the type is not there
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function missing( array &$appData ): bool {

		return \Nino\Filesystem::fileExists( $appData, self::FILE ) === false;
	}

	/**
	 *	The projects, the newest first (the order of their numbers, the last made on top)
	 *
	 *	@param		array 		&$appData
	 *	@param		string|null	$clientNumber	Only the projects of this client (K-0001); null for every client's
	 *	@param		bool			$openOnly			Leave out the projects that are done
	 *	@param		string		$search				Part of the title (case does not matter, no wildcards)
	 *
	 *	@return 	array											id, title, client (the number), description, started, done, folder of each - and documents (how many documents name it), open (how many of them are offers that are open or sent) and download (whether its client may download the files); one query for all the states
	 */
	public static function list( array &$appData, ?string $clientNumber = null, bool $openOnly = false, string $search = '' ): array {

		$search = trim( $search );
		$counts = self::counts( $appData );
		$downloads = self::downloads( $appData );
		$projects = [];

		foreach( self::read( $appData ) as $project ) {

			if( $clientNumber !== null && $project['client'] !== $clientNumber )
				continue;

			if( $openOnly === true && $project['done'] === true )
				continue;

			if( $search !== '' && mb_stripos( $project['title'], $search, 0, 'UTF-8' ) === false )
				continue;

			$projects[] = $project + [ 'documents' => $counts[$project['id']]['documents'] ?? 0, 'open' => $counts[$project['id']]['open'] ?? 0, 'download' => $downloads[$project['id']] ?? false ];
		}

		return $projects;
	}

	/**
	 *	One project
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The name of the element, the last part of its uri
	 *
	 *	@return 	array|null								id, title, client, description, started, done, folder, download; null for none
	 */
	public static function get( array &$appData, string $id ): ?array {

		if( preg_match( self::ID, $id ) !== 1 || self::missing( $appData ) === true )
			return null;

		$element = \Nino\Elements::getElement( $appData, self::TYPE. '/'. $id, '*' );

		if( is_array( $element ) === false || $element === [] )
			return null;

		return self::shape( $element + [ '.uri' => self::TYPE. '/'. $id ] ) + [ 'download' => self::state( $appData, $id )['download'] ];
	}

	/**
	 *	What the owner decided about a project for the portal
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The id of a project; a project without a row, and an id that is none, has no download
	 *
	 *	@return 	array											download (bool), download_at (the time it was switched on, '' for none), download_by (the account of the workbench that did it, '')
	 */
	public static function state( array &$appData, string $id ): array {

		$row = preg_match( self::ID, $id ) === 1 ? Db::row( $appData, 'SELECT download, download_at, download_by FROM project_states WHERE project = :project', [ 'project' => $id ] ) : null;

		return [ 'download' => $row !== null && (int) $row['download'] === 1, 'download_at' => (string) ( $row['download_at'] ?? '' ), 'download_by' => (string) ( $row['download_by'] ?? '' ) ];
	}

	/**
	 *	Switch the download of the files of a project on or off for its client. The time and the account are those of the first switching on - a second one leaves them - and off leaves them empty
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The id of a project
	 *	@param		bool			$on
	 *	@param		string		$by						The account of the workbench that asks
	 *
	 *	@return 	array|string							The state as state() has it, with the id of the project; 'unknown' for a project that is not there
	 */
	public static function setDownload( array &$appData, string $id, bool $on, string $by = '' ): array|string {

		if( self::get( $appData, $id ) === null )
			return 'unknown';

		// The day and the account are those of the first switching on: a project that is free stays free since that day
		Db::exec( $appData, 'INSERT INTO project_states ( project, download, download_at, download_by ) VALUES ( :project, :download, :at, :by )
			ON CONFLICT ( project ) DO UPDATE SET download = excluded.download,
				download_at = CASE WHEN excluded.download = 0 THEN \'\' WHEN project_states.download = 1 THEN project_states.download_at ELSE excluded.download_at END,
				download_by = CASE WHEN excluded.download = 0 THEN \'\' WHEN project_states.download = 1 THEN project_states.download_by ELSE excluded.download_by END', [
			'project' => $id, 'download' => $on === true ? 1 : 0, 'at' => $on === true ? gmdate( self::TIME ) : '', 'by' => $on === true ? mb_substr( $by, 0, 200, 'UTF-8' ) : '',
		] );

		self::forget( $appData );

		return [ 'id' => $id ] + self::state( $appData, $id );
	}

	/**
	 *	The project of a client whose folder in the feature Files is the one given
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$clientNumber	K-0001
	 *	@param		string		$folder				The id of a folder
	 *
	 *	@return 	array|null								As get() has it; null for a folder no project of the client has
	 */
	public static function ofFolder( array &$appData, string $clientNumber, string $folder ): ?array {

		if( $folder === '' )
			return null;

		return self::remember( $appData, 'folder|'. $clientNumber. '|'. $folder, static function() use ( &$appData, $clientNumber, $folder ): ?array {

			foreach( self::read( $appData ) as $project )
				if( $project['client'] === $clientNumber && $project['folder'] === $folder )
					return $project + [ 'download' => self::state( $appData, $project['id'] )['download'] ];

			return null;
		} );
	}

	/**
	 *	Forget what ofFolder() and waiting() kept for this request
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function forget( array &$appData ): void {

		unset( $appData[self::LOOKUPS] );
	}

	/**
	 *	The offer that holds the files of a project back: a released offer of the client's project that can be ordered (Documents::orderable()) - the newest of them.
	 *	While one waits the client has the order to give first; it is given, declined or taken back, or it runs out, and the files are open again
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$project			The id of a project
	 *	@param		int				$clientId			The client the project is of
	 *
	 *	@return 	array|null								The offer as Documents::released() has it; null where none waits
	 */
	public static function waiting( array &$appData, string $project, int $clientId ): ?array {

		if( $project === '' )
			return null;

		return self::remember( $appData, 'waiting|'. $project. '|'. $clientId, static function() use ( &$appData, $project, $clientId ): ?array {

			foreach( Documents::released( $appData, $clientId, $project ) as $document )
				if( Documents::orderable( $document ) === true )
					return $document;

			return null;
		} );
	}

	/**
	 *	The answer to a question about a folder or a project, kept for the request: a page asks it for each file of a folder, and it takes a handful of queries. The answer is kept with
	 *	the question it was read for, so that a second request in the same process (a test, a worker) never gets the answer of the first; a write that moves it - the switch of the download,
	 *	a release or a state of a document, a project written or deleted - drops what was kept (forget())
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$key
	 *	@param		callable	$read
	 *
	 *	@return 	array|null
	 */
	private static function remember( array &$appData, string $key, callable $read ): ?array {

		if( array_key_exists( $key, $appData[self::LOOKUPS] ?? [] ) === false )
			$appData[self::LOOKUPS][$key] = $read();

		return $appData[self::LOOKUPS][$key];
	}

	/**
	 *	The notification of the kernel that an element was written or deleted ('/nino/elements/committed'): the row of a project that is gone goes with it
	 *
	 *	@param		array 		&$appData
	 *	@param		mixed			&$change			operation, type and uri
	 *
	 *	@return 	void
	 */
	public static function callbackCommitted( array &$appData, mixed &$change ): void {

		if( is_array( $change ) === true && ( $change['type'] ?? '' ) === self::TYPE )
			self::forget( $appData );

		if( is_array( $change ) === false || ( $change['operation'] ?? '' ) !== 'delete' || ( $change['type'] ?? '' ) !== self::TYPE )
			return;

		$id = \Nino\Elements::getElementUriFromUri( (string) ( $change['uri'] ?? '' ) );

		try {
			if( preg_match( self::ID, $id ) === 1 && self::get( $appData, $id ) === null )
				Db::exec( $appData, 'DELETE FROM project_states WHERE project = :project', [ 'project' => $id ] );
		}
		catch( \PDOException $e ) {
			trigger_error( 'Crm: the state of the project '. $id. ' could not be removed', E_USER_WARNING );
		}
	}

	/**
	 *	The projects of a client, the newest first, the done ones among them
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$clientNumber	K-0001
	 *
	 *	@return 	array											As list() has them
	 */
	public static function ofClient( array &$appData, string $clientNumber ): array {

		return self::list( $appData, $clientNumber );
	}

	/**
	 *	How many documents name a project
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id
	 *
	 *	@return 	int
	 */
	public static function documentsOf( array &$appData, string $id ): int {

		return (int) ( Db::row( $appData, 'SELECT COUNT(*) AS n FROM documents WHERE project = :project', [ 'project' => $id ] )['n'] ?? 0 );
	}

	/**
	 *	The title of each project by its id, for the lists that show the project of a document
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id => title
	 */
	public static function titles( array &$appData ): array {

		return array_column( self::read( $appData ), 'title', 'id' );
	}

	/**
	 *	The veto of the kernel for a delete in the type /projekte ('/nino/elements/delete/projekte'): a project that a document names or that a customer approved is not deleted - in the
	 *	Elements panel of the workbench as in the surface, and anywhere else. The kernel hands over the type file as it will be written; what is gone from it is
	 *	what is being deleted
	 *
	 *	@param		array 		&$appData
	 *	@param		mixed			&$typeData		The type file after the delete
	 *
	 *	@return 	mixed											false vetoes; the type file otherwise
	 */
	public static function callbackDelete( array &$appData, mixed &$typeData ): mixed {

		if( is_array( $typeData ) === false )
			return $typeData;

		$was = self::stored( $appData );

		try {
			foreach( array_diff( array_keys( $was ), array_keys( self::bucketOf( $typeData ) ) ) as $id ) {

				if( self::documentsOf( $appData, (string) $id ) > 0 ) {
					trigger_error( 'Documents name the project '. $id. '.' );
					return false;
				}

				// The approval of a customer is evidence that names the project: the project stays as long as the record does
				if( Records::approval( $appData, (string) $id ) !== null ) {
					trigger_error( 'The customer approved the project '. $id. '.' );
					return false;
				}
			}
		}
		catch( \PDOException $e ) {
			return false;
		}

		return $typeData;
	}

	/**
	 *	The veto of the kernel for an insert and an update in the type ('/nino/elements/projekte/insert' and '.../update'): the client of a project is the number of
	 *	a client that is there, and it does not change while a document names the project
	 *
	 *	@param		array 		&$appData
	 *	@param		mixed			&$typeData		The type file after the write
	 *
	 *	@return 	mixed											false vetoes; the type file otherwise
	 */
	public static function callbackWrite( array &$appData, mixed &$typeData ): mixed {

		if( is_array( $typeData ) === false )
			return $typeData;

		$was = self::stored( $appData );

		try {
			foreach( self::bucketOf( $typeData ) as $id => $fields ) {

				$client = is_string( $fields['client'] ?? null ) === true ? $fields['client'] : '';
				$before = $was[$id] ?? null;

				// What is not changed is not asked: a project of the old days keeps its client
				if( $before !== null && ( $before['client'] ?? null ) === $client )
					continue;

				if( $client === '' || Clients::byNumber( $appData, $client ) === null ) {
					trigger_error( 'The client '. $client. ' of the project '. $id. ' is none.' );
					return false;
				}

				if( $before !== null && self::documentsOf( $appData, (string) $id ) > 0 ) {
					trigger_error( 'Documents name the project '. $id. ', its client stays.' );
					return false;
				}
			}
		}
		catch( \PDOException $e ) {
			return false;
		}

		return $typeData;
	}

	/**
	 *	The global fields of every project as the type file holds them now (what the write in progress will replace)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id => fields
	 */
	private static function stored( array &$appData ): array {

		$file = \Nino\Filesystem::getFileContent( $appData, self::FILE, [] );

		return is_array( $file ) === true ? self::bucketOf( $file ) : [];
	}

	/**
	 *	@param		array 		$typeData
	 *
	 *	@return 	array											id => fields of the bucket for all (a project has nothing per language)
	 */
	private static function bucketOf( array $typeData ): array {

		$bucket = is_array( $typeData['*'] ?? null ) === true ? $typeData['*'] : [];
		unset( $bucket['*'] );

		return array_filter( $bucket, 'is_array' );
	}

	/**
	 *	Every project as get() has it, the newest first
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array
	 */
	private static function read( array &$appData ): array {

		if( self::missing( $appData ) === true )
			return [];

		$hits = \Nino\Elements::queryElements( $appData, self::TYPE, [], '*', [] );
		$projects = [];

		foreach( is_array( $hits ) === true ? $hits : [] as $hit ) {

			if( is_array( $hit ) === true && isset( $hit['.uri'] ) === true )
				$projects[] = self::shape( $hit );
		}

		// The numbers count up with the making: a longer number is a later one, and of two of a length the greater is
		usort( $projects, static fn( array $a, array $b ): int => [ strlen( $b['id'] ), $b['id'] ] <=> [ strlen( $a['id'] ), $a['id'] ] );

		return $projects;
	}

	/**
	 *	How many documents each project has, and how many of them are offers that are open or sent: one grouped query
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id => documents, open
	 */
	private static function counts( array &$appData ): array {

		$counts = [];

		foreach( Db::rows( $appData, 'SELECT project, COUNT(*) AS documents, SUM( CASE WHEN category = \'offer\' AND state IN ( \'open\', \'sent\' ) THEN 1 ELSE 0 END ) AS open FROM documents WHERE project <> :none GROUP BY project', [ 'none' => '' ] ) as $row )
			$counts[(string) $row['project']] = [ 'documents' => (int) $row['documents'], 'open' => (int) $row['open'] ];

		return $counts;
	}

	/**
	 *	Which projects have their download on: one query
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id => true
	 */
	private static function downloads( array &$appData ): array {

		$on = [];

		foreach( Db::rows( $appData, 'SELECT project FROM project_states WHERE download = 1' ) as $row )
			$on[(string) $row['project']] = true;

		return $on;
	}

	/**
	 *	@param		array 		$element			As the kernel answers it, with its '.uri'
	 *
	 *	@return 	array
	 */
	private static function shape( array $element ): array {

		$text = static fn( mixed $value ): string => is_string( $value ) === true ? trim( $value ) : '';

		return [
			'id'          => \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ),
			'title'       => $text( $element['title'] ?? '' ),
			'client'      => $text( $element['client'] ?? '' ),
			'description' => $text( $element['description'] ?? '' ),
			'started'     => $text( $element['started'] ?? '' ),
			'done'        => ( $element['done'] ?? false ) === true,
			'folder'      => $text( $element['folder'] ?? '' ),
		];
	}
}

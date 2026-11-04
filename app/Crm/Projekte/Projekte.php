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
 */
namespace Crm;

class Projekte {

	// The element type, and the file the kernel keeps it in
	public const string TYPE = '/projekte';
	private const string FILE = '/elements/projekte.php';

	// What an id looks like: the number the type gives (00001), or any name an element of the type has - the same pattern the screens take
	public const string ID = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,99}$/D';

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
	 *	@return 	array											id, title, client (the number), description, started, done, folder of each - and documents (how many documents name it) and open (how many of them are offers that are open or sent)
	 */
	public static function list( array &$appData, ?string $clientNumber = null, bool $openOnly = false, string $search = '' ): array {

		$search = trim( $search );
		$counts = self::counts( $appData );
		$projects = [];

		foreach( self::read( $appData ) as $project ) {

			if( $clientNumber !== null && $project['client'] !== $clientNumber )
				continue;

			if( $openOnly === true && $project['done'] === true )
				continue;

			if( $search !== '' && mb_stripos( $project['title'], $search, 0, 'UTF-8' ) === false )
				continue;

			$projects[] = $project + [ 'documents' => $counts[$project['id']]['documents'] ?? 0, 'open' => $counts[$project['id']]['open'] ?? 0 ];
		}

		return $projects;
	}

	/**
	 *	One project
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The name of the element, the last part of its uri
	 *
	 *	@return 	array|null								id, title, client, description, started, done, folder; null for none
	 */
	public static function get( array &$appData, string $id ): ?array {

		if( preg_match( self::ID, $id ) !== 1 || self::missing( $appData ) === true )
			return null;

		$element = \Nino\Elements::getElement( $appData, self::TYPE. '/'. $id, '*' );

		return is_array( $element ) === true && $element !== [] ? self::shape( $element + [ '.uri' => self::TYPE. '/'. $id ] ) : null;
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
	 *	The veto of the kernel for a delete in the type /projekte ('/nino/elements/delete/projekte'): a project that a document names is not deleted - in the
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

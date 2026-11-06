<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Uploads		What the screen Uploads of the surface reads: the files the customers put into their upload folders, all in one list, and the ZIP
 *												of a choice of them. The data is the feature Files'; nothing is stored here but the ZIP for the length of one request.
 *
 *												crm-uploads/list		the files of every folder that is marked as the upload folder of a client's group (a folder of a group that is no
 *																						client's is none of it): the client, the name, who sent it, the size, the day and the age in days - and the sums of
 *																						the screen, the files that are older than OLD_DAYS apart. A file that was removed from the server is listed as that and
 *																						counts for nothing. Read under the permission of the CRM; allowed says whether the account may change them (the permission
 *																						of the Files panel), which the ZIP needs as well
 *												GET /crm/uploads.zip	?ids=<id>,<id>: the files as one ZIP, each as <number of the client>/<name>, a name that is taken already with -2, -3 ... before
 *																						its extension. The ids are checked before a byte is read: each has to be a file of an upload folder of a client's group, and
 *																						that is not removed, or the answer is 404 (410 for a removed one) and no ZIP is made. The names are those the index keeps,
 *																						cleaned once more by Files\Store::cleanName(): they have no path in them, so that nothing in a ZIP reaches out of the directory
 *																						it is unpacked into. The ZIP is made by ZipArchive (the extension zip; without it the route answers 409 and the screen has no
 *																						button) into a file of its own below /data/crm-zip, stored without compression - what is uploaded is audio, video, images
 *																						and archives already - and answered as the file the kernel streams, as an attachment that no cache keeps. The kernel has no hook
 *																						after it has sent a file: the file is removed when the request ends (a shutdown function), and what an interrupted request left
 *																						is removed by the next one that asks, once it is older than an hour.
 *
 *												Nothing of a customer's address or name is written to the log: the line of a ZIP counts the files.
 */
namespace Crm\Surface;

class Uploads {

	// A file that is older than this many days is the one the screen points at: nothing deletes it
	public const int OLD_DAYS = 30;

	// A choice of more files than this is refused: the ids are the query of a link
	public const int MAX_FILES = 400;

	// A ZIP of more bytes than this is said to be large by the screen; it is made all the same
	public const int LARGE = 2147483648;

	// Where the ZIPs are made below /data, and how long one that was left behind stays
	public const string DIRECTORY = '/data/crm-zip';
	public const int STALE = 3600;

	// The ZIPs of this request that are to be removed when it ends
	private static array $_pending = [];

	private static bool $_registered = false;

	/**
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		return [ 'crm-uploads/list' => [ self::class, 'apiList' ] ];
	}

	/**
	 *	Whether ZipArchive is there
	 *
	 *	@return 	bool
	 */
	public static function zipAvailable(): bool {

		return class_exists( '\\ZipArchive' ) === true;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, \Crm\Crm::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$on = Files::available() === true && \Crm\Crm::featureOn( $appData, 'files' ) === true;

			\Nino\Http::ok( $request, [
				'files'    => $on,
				'allowed'  => Files::allowed( $appData ),
				'zip'      => self::zipAvailable(),
				'old_days' => self::OLD_DAYS,
				'limit'    => self::MAX_FILES,
				'large'    => self::LARGE,
			] + ( $on === true ? self::read( $appData ) : [ 'rows' => [], 'summary' => self::sums( [] ) ] ) );
		} );
	}

	/**
	 *	The rows of the screen: every file of every upload folder of a client, the newest first
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											rows and summary
	 */
	private static function read( array &$appData ): array {

		$index = \Nino\Modules\Files\Store::read( $appData );
		$clients = [];

		foreach( \Crm\Clients::list( $appData ) as $client )
			$clients[\Crm\Clients::group( $client['number'] )] = $client;

		$folders = [];

		foreach( $index['folders'] as $id => $folder )
			if( $folder['upload'] === true && isset( $clients[$folder['group']] ) === true )
				$folders[(string) $id] = [ $folder, $clients[$folder['group']] ];

		$labels = [];
		$rows = [];
		$now = time();

		foreach( $index['files'] as $id => $file ) {

			if( isset( $folders[$file['folder']] ) === false )
				continue;

			[ $folder, $client ] = $folders[$file['folder']];

			$kind = $file['by']['kind'];
			$who = $file['by']['id'];

			if( $kind === 'member' && isset( $labels[$who] ) === false ) {
				$account = \Crm\Crm::featureOn( $appData, 'members' ) === true ? \Nino\Modules\Members::account( $appData, $who ) : null;
				$labels[$who] = $account === null ? '' : ( $account['name'] !== '' ? $account['name'] : $account['email'] );
			}

			$made = strtotime( $file['created'] );

			$rows[] = [
				'id'           => (string) $id,
				'client_id'    => $client['id'],
				'client'       => $client['number'],
				'client_title' => $client['company'] !== '' ? $client['company'] : $client['name'],
				'name'         => $file['name'],
				'ext'          => $file['ext'],
				'size'         => $file['size'],
				'created'      => $file['created'],
				'age'          => $made === false ? 0 : max( 0, intdiv( $now - $made, 86400 ) ),
				'by'           => [ 'kind' => $kind, 'label' => $kind === 'member' ? $labels[$who] : '' ],
				'gone'         => $file['gone'] ?? '',
			];
		}

		usort( $rows, static fn( array $a, array $b ): int => [ $b['created'], $a['id'] ] <=> [ $a['created'], $b['id'] ] );

		return [ 'rows' => $rows, 'summary' => self::sums( $rows ) ];
	}

	/**
	 *	The sums over the line at the top of the screen: the files that are on the server, and those of them that are older than OLD_DAYS
	 *
	 *	@param		array 		$rows
	 *
	 *	@return 	array											count, bytes, old_count, old_bytes
	 */
	private static function sums( array $rows ): array {

		$sum = [ 'count' => 0, 'bytes' => 0, 'old_count' => 0, 'old_bytes' => 0 ];

		foreach( $rows as $row ) {

			if( $row['gone'] !== '' )
				continue;

			$sum['count']++;
			$sum['bytes'] += $row['size'];

			if( $row['age'] > self::OLD_DAYS ) {
				$sum['old_count']++;
				$sum['old_bytes'] += $row['size'];
			}
		}

		return $sum;
	}

	/**
	 *	GET /crm/uploads.zip: the ZIP of the files that ?ids= names. A session first (401), the permission of the CRM and that of the Files panel (403), the
	 *	feature on and ZipArchive there (409), a choice that is a list of one to MAX_FILES ids (400); then every id has to be a file of an upload folder of a
	 *	client's group whose bytes are there (404 for the first that is not, 410 for one that was removed) - and only then the ZIP is made
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackZip( array &$appData, array &$request ): void {

		$user = \Nino\Auth::getCurrentUser( $appData );

		if( $user === false ) {
			\Crm\Surface::plain( $request, 401, \Crm\Surface::texts()['error.session'] );
			return;
		}

		if( \Nino\Auth::checkPermission( $appData, \Crm\Crm::MANAGE_PERM ) === false ) {
			\Crm\Surface::plain( $request, 403, \Crm\Surface::texts()['error.forbidden'] );
			return;
		}

		// The rights first, then the state of the feature
		if( Files::available() === true && \Nino\Auth::checkPermission( $appData, Files::PERM ) === false ) {
			\Crm\Surface::plain( $request, 403, \Crm\Surface::texts()['error.forbidden'] );
			return;
		}

		if( Files::available() === false || \Crm\Crm::featureOn( $appData, 'files' ) === false ) {
			\Crm\Surface::plain( $request, 409, \Crm\Surface::texts()['error.files_off'] );
			return;
		}

		if( self::zipAvailable() === false ) {
			\Crm\Surface::plain( $request, 409, \Crm\Surface::texts()['uploads.zip-off'] );
			return;
		}

		$query = $request['/nino/http/request']['query'] ?? [];
		$asked = is_array( $query ) === true && is_string( $query['ids'] ?? null ) === true ? array_values( array_unique( explode( ',', $query['ids'] ) ) ) : [];

		if( $asked === [] || count( $asked ) > self::MAX_FILES || array_filter( $asked, static fn( string $id ): bool => preg_match( \Nino\Modules\Files\Store::ID, $id ) !== 1 ) !== [] ) {
			\Crm\Surface::plain( $request, 400, \Crm\Surface::texts()['uploads.zip-ids'] );
			return;
		}

		$files = [];

		foreach( $asked as $id ) {

			$file = \Nino\Modules\Files\Store::file( $appData, $id );
			$folder = $file === null ? null : \Nino\Modules\Files\Store::folder( $appData, $file['folder'] );
			$number = $folder === null || $folder['upload'] !== true ? null : Files::clientOf( $appData, $folder['group'] );

			if( $file === null || $number === null ) {
				\Crm\Surface::plain( $request, 404, \Crm\Surface::texts()['uploads.zip-unknown'] );
				return;
			}

			if( isset( $file['gone'] ) === true ) {
				\Crm\Surface::plain( $request, 410, \Crm\Surface::texts()['uploads.zip-gone'] );
				return;
			}

			$path = \Nino\Modules\Files\Store::path( $appData, $id );

			if( $path === null ) {
				\Crm\Surface::plain( $request, 404, \Crm\Surface::texts()['uploads.zip-unknown'] );
				return;
			}

			$files[] = [ 'file' => $file, 'number' => $number, 'path' => $path ];
		}

		self::sweep( $appData );

		$directory = \Nino\Filesystem::path( $appData, self::DIRECTORY );

		if( is_dir( $directory ) === false && @mkdir( $directory, 0755, true ) === false && is_dir( $directory ) === false ) {
			\Crm\Surface::plain( $request, 500, \Crm\Surface::texts()['uploads.zip-failed'] );
			return;
		}

		$target = $directory. '/uploads-'. bin2hex( random_bytes( 8 ) ). '.zip';

		self::remember( $target );

		if( self::pack( $files, $target ) === false ) {
			self::cleanup();
			\Crm\Surface::plain( $request, 500, \Crm\Surface::texts()['uploads.zip-failed'] );
			return;
		}

		$request['/nino/http/response']['file'] = $target;
		$request['/nino/http/response']['header']['Content-Type'] = 'application/zip';
		$request['/nino/http/response']['header']['Content-Disposition'] = \Nino\Modules\Files::disposition( 'uploads-'. date( 'Y-m-d' ). '.zip', false );
		$request['/nino/http/response']['header']['Cache-Control'] = 'private, no-store';
		$request['/nino/http/response']['header']['X-Content-Type-Options'] = 'nosniff';

		// The shell of the workbench is where the activity log is written
		if( \Crm\Surface::workbench( $appData ) === true )
			\Nino\Admin\Admin::record( $appData, (string) $user['mail'], 'Downloaded '. count( $files ). ' uploaded '. ( count( $files ) === 1 ? 'file' : 'files' ). ' as a zip' );
	}

	/**
	 *	The ZIP of the files: each as <number>/<name>. A name that is there already is made unique with -2, -3 ... before its extension, whatever the case
	 *	- a file system that does not tell A.wav from a.wav must not take one for the other
	 *
	 *	@param		array 		$files				Each with file (the entry), number (K-0001) and path (the bytes)
	 *	@param		string		$target				The path of the ZIP to make
	 *
	 *	@return 	bool											Whether the ZIP is there, whole
	 */
	public static function pack( array $files, string $target ): bool {

		$zip = new \ZipArchive();

		if( $zip->open( $target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true )
			return false;

		$taken = [];

		foreach( $files as $one ) {

			[ $name ] = \Nino\Modules\Files\Store::cleanName( (string) $one['file']['name'] );
			$name = $name !== '' ? $name : $one['file']['id']. '.'. $one['file']['ext'];
			$dot = (int) strrpos( $name, '.' );
			$base = $dot > 0 ? substr( $name, 0, $dot ) : $name;
			$ext = $dot > 0 ? substr( $name, $dot ) : '';
			$entry = $one['number']. '/'. $name;
			$count = 1;

			while( isset( $taken[mb_strtolower( $entry )] ) === true )
				$entry = $one['number']. '/'. $base. '-'. ++$count. $ext;

			$taken[mb_strtolower( $entry )] = true;

			if( $zip->addFile( $one['path'], $entry ) === false || $zip->setCompressionName( $entry, \ZipArchive::CM_STORE ) === false ) {
				$zip->close();
				return false;
			}
		}

		return $zip->close() === true && is_file( $target ) === true;
	}

	/**
	 *	Remove the ZIPs - and the temporary files libzip leaves beside an unfinished one - that a request left behind and that are older than STALE seconds: the next ZIP that is asked for does it, so that nothing runs on a timer
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	int												How many went
	 */
	public static function sweep( array &$appData ): int {

		$gone = 0;

		// ...and the temporary files libzip leaves beside a ZIP it could not finish: uploads-<hex>.zip.<suffix>
		foreach( array_merge( glob( \Nino\Filesystem::path( $appData, self::DIRECTORY ). '/uploads-*.zip' ) ?: [], glob( \Nino\Filesystem::path( $appData, self::DIRECTORY ). '/uploads-*.zip.*' ) ?: [] ) as $zip )
			if( is_file( $zip ) === true && (int) @filemtime( $zip ) < time() - self::STALE && @unlink( $zip ) === true )
				$gone++;

		return $gone;
	}

	/**
	 *	Remove the ZIPs of this request: what the shutdown function does once the response is sent
	 *
	 *	@return 	int												How many went
	 */
	public static function cleanup(): int {

		$gone = 0;

		foreach( self::$_pending as $path )
			if( is_file( $path ) === true && @unlink( $path ) === true )
				$gone++;

		self::$_pending = [];

		return $gone;
	}

	/**
	 *	A ZIP that is to be removed when the request ends
	 *
	 *	@param		string		$path
	 *
	 *	@return 	void
	 */
	private static function remember( string $path ): void {

		self::$_pending[] = $path;

		if( self::$_registered === false ) {
			self::$_registered = true;
			register_shutdown_function( [ self::class, 'cleanup' ] );
		}
	}
}

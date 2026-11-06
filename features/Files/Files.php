<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\\Files						see _nino/Nino/Modules/Modules.php for the
 *											package-level docblock
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Files							Folders and files for the groups of the Members feature. A folder
	 *										belongs to one group; the owner makes folders and uploads files
	 *										in the panel, and may mark one folder of a group as the one its
	 *										members upload into. A member sees the folders of their group and
	 *										the files that are visible to them, and downloads them through
	 *										GET /.files/<id>, which answers the file the kernel streams
	 *										(\Nino\Http, "Answering a File": the length, the ranges and the
	 *										pieces are the kernel's, so that a browser can play a long audio
	 *										file); a stranger and a member of another group are answered 404
	 *										alike, so nothing tells that an id exists - and the owner, whose
	 *										workbench session holds the permission of the panel, is answered with
	 *										any file that has bytes, the hidden ones and those of any group
	 *										included, so that the panel links its files to the same route. A
	 *										member uploads through POST /.files/upload, in chunks (see
	 *										Files\Upload), or with one plain form post when there is no script.
	 *
	 *										The data is Files\Store's: the index /data/files.php and the bytes
	 *										in /data/files/ - the bytes are not part of the daily backup, the
	 *										index is, and a restore keeps the entries whose bytes are there
	 *										(callbackRestore()). A file can be removed from the server and stay in the index: the
	 *										entry keeps its name, size and sender and carries gone, the time the bytes went; its address
	 *										answers 410 to those who may have the file, and nothing offers it for download.
	 *
	 *										Two components put the file browser and the upload form on any page
	 *										the Builder composes (componentFilesBrowser() and
	 *										componentFilesUpload(); the manifest declares them), and the panel
	 *										in Admin/Admin.php manages everything. Other features build on it
	 *										through folders(), files(), file(), path(), allowed() and url() - and
	 *										version() and grouped(), which read the versions of a title from the names (Title.03.ext) -, and
	 *										the callbacks '/files/upload' (a member's upload), '/files/add' (the
	 *										owner's) and '/files/delete', each fired with the entry, and '/files/allowed',
	 *										which allowed() fires when it would say yes and which can withhold the file.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Files {

		// Where this feature's own templates are, as \Nino\Filesystem resolves them
		public const string TEMPLATES = '/features/Files/templates';

		// The counter of the chunk requests, \Nino\Feature::count() under this name: the file /data/files-tries.php.
		// Not 'files' - that is the file of the index, and a counter drops whatever in its file is no entry of its own,
		// the index among it. It is not in the manifest's 'data' either: a window of an hour is nothing a backup carries
		private const string COUNTER = 'files-tries';

		private const int WINDOW = 3600;
		private const int DEFAULT_ATTEMPTS = 300;
		private const string FEATURE_KEY = 'files';

		// How many upload forms this request has drawn
		private const string FORMS = './files/forms';

		// What the form may say besides nothing: the query 'files' is one of these, the refusals by their codes
		private const array STATES = [ 'sent', 'files_folder', 'files_type', 'files_size', 'files_quota', 'files_chunk', 'files_limit', 'files_write' ];

		/**
		 *	Register the two routes this feature owns with their handlers, the merge of a restore, and tell the
		 *	Jstext module the words the script reads
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	void
		 */
		public static function init( array &$appData ): void {

			$appData['/nino/http/routes']['GET://.files/*']				= [ 'uri' => '/.files/*' ];
			$appData['/nino/http/routes']['POST://.files/upload']	= [ 'uri' => '/.files/upload' ];

			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/GET://.files/*', [ self::class, 'callbackDownload' ] );
			\Nino\Callbacks::registerCallback( $appData, '/nino/http/response/POST://.files/upload', [ self::class, 'callbackUpload' ] );

			// A restore of the daily backup brings the index of a day that may name files whose bytes are gone,
			// since the bytes are not in a backup - see callbackRestore()
			\Nino\Callbacks::registerCallback( $appData, '/nino/admin/restore', [ self::class, 'callbackRestore' ] );

			// The words of the upload script: a published key is public, so the two lists are the words the script reads and nothing else
			if( class_exists( '\\Nino\\Modules\\Jstext' ) === true )
				\Nino\Modules\Jstext::publish( $appData, [ '/feature/files/state/', '/feature/files/script/' ] );
		}

		// The panel comes and goes with the feature
		public static function adminPanels( array &$appData ): array {

			return [ \Nino\Modules\Files\Admin::class ];
		}

		/**
		 *	The folders of a group, sorted by name
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$group				A slug, as Members writes it
		 *
		 *	@return 	array										A list of folders: id, group, name, upload (whether the members upload into it) and created
		 */
		public static function folders( array &$appData, string $group ): array {

			return \Nino\Modules\Files\Store::folders( $appData, $group );
		}

		/**
		 *	The files of a folder, the newest first - the hidden ones too: whether the current member may have one is
		 *	allowed()'s to say
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$folder				The id of a folder
		 *
		 *	@return 	array										A list of files: id, folder, name, ext, size, mime, sha256, by (kind and id), visible and created - and gone, the time the bytes were removed, for a file that was
		 */
		public static function files( array &$appData, string $folder ): array {

			return \Nino\Modules\Files\Store::files( $appData, $folder );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	array|null								The entry with its id (and gone, for a file whose bytes were removed); null for an id nobody has
		 */
		public static function file( array &$appData, string $id ): ?array {

			return \Nino\Modules\Files\Store::file( $appData, $id );
		}

		/**
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	string|null								The absolute path of the bytes; null where there is no such file, it was removed or its bytes are gone
		 */
		public static function path( array &$appData, string $id ): ?string {

			return \Nino\Modules\Files\Store::path( $appData, $id );
		}

		/**
		 *	The rule for the member who is signed in, which the browser component lists by and the features that build on
		 *	this one filter with: a member of the group the folder of the file belongs to, if the file is visible - and
		 *	the member who uploaded the file, whatever it is and wherever it is now. The owner's workbench session is no
		 *	member: nobody who is not signed in as a member is allowed anything here. The route answers the owner by the
		 *	permission of the panel on its own (see callbackDownload()) and leaves this rule as it is.
		 *
		 *	Where the rule says yes, the callback '/files/allowed' is asked before the answer is given, with the file, the member, whether the request is a download and
		 *	allowed (true): a project that has a reason to withhold a file from a member - a condition of its own, before the member has what the group is given - sets
		 *	allowed to false. It never makes a no a yes: a value other than false is ignored, a no of one callback - allowed false, or the false a callback returns as a veto - stays whatever the next sets, and a no of the rule is not asked about
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$file					An entry, as file() answers it
		 *	@param		bool			$download			Whether the file is asked for to be saved (?download=1) and not shown
		 *
		 *	@return 	bool
		 */
		public static function allowed( array &$appData, array $file, bool $download = false ): bool {

			$member = \Nino\Modules\Members::current( $appData );

			if( $member === null )
				return false;

			if( ( $file['by']['kind'] ?? null ) === 'member' && ( $file['by']['id'] ?? null ) === $member['id'] )
				$allowed = true;
			else if( ( $file['visible'] ?? false ) !== true || $member['group'] === '' )
				$allowed = false;
			else {
				$folder = \Nino\Modules\Files\Store::folder( $appData, (string) ( $file['folder'] ?? '' ) );
				$allowed = $folder !== null && $folder['group'] === $member['group'];
			}

			if( $allowed === false )
				return false;

			// Each callback is asked in turn with allowed true, and a no stays a no: what a later callback sets does not take it back
			$asked = [ 'file' => $file, 'member' => $member, 'download' => $download, 'allowed' => true ];
			$denied = false;

			foreach( \Nino\Callbacks::registered( $appData, '/files/allowed' ) as $callback ) {

				$asked['allowed'] = true;
				$replaced = call_user_func_array( $callback, [ &$appData, &$asked ] );
				$asked = is_array( $replaced ) === true ? $replaced : $asked;
				$denied = $denied === true || $replaced === false || ( $asked['allowed'] ?? true ) === false;
			}

			return $denied === false;
		}

		/**
		 *	The address a file is downloaded at, from the directory the project is served from: a page links it and an
		 *	audio element plays it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$id
		 *
		 *	@return 	string										'' for an id that is none
		 */
		public static function url( array &$appData, string $id ): string {

			return preg_match( \Nino\Modules\Files\Store::ID, $id ) === 1 ? \Nino\Filesystem::getDir( $appData ). '/.files/'. $id : '';
		}

		/**
		 *	The title and the version a file name says: Title.NN.ext, the version one to three digits between two dots and the
		 *	extension one to eight letters and digits, in any case. The title is what stands before the version, dots among it
		 *	('Kunde-Titel.final.03.wav' is the title 'Kunde-Titel.final'). A name that does not say it - no version, a version of
		 *	four digits, no extension after it - is a title of its own: the name without its extension. Pure; a name is read as
		 *	it stands and nothing is looked up
		 *
		 *	@param		string		$name					A name as the index keeps it
		 *
		 *	@return 	array											title, version (the digits as written, '' for none) and number (the version as an integer, null for none)
		 */
		public static function version( string $name ): array {

			if( preg_match( '/^(.+)\.([0-9]{1,3})\.[A-Za-z0-9]{1,8}$/D', $name, $found ) === 1 && trim( $found[1] ) !== '' )
				return [ 'title' => $found[1], 'version' => $found[2], 'number' => (int) $found[2] ];

			return [ 'title' => preg_match( '/^(.+)\.[A-Za-z0-9]{1,8}$/D', $name, $found ) === 1 ? $found[1] : $name, 'version' => '', 'number' => null ];
		}

		/**
		 *	Files of one folder as the titles they are versions of: two files belong together when the title version() reads
		 *	from their names is the same, in the case it stands in, and so is their extension (Song.03.wav and Song.03.mp3 are
		 *	two titles). The versions of a title stand from the highest
		 *	number down, the one of the same number that came later first and a file without a version after the numbered ones: it counts as the
		 *	oldest version of its title, so that the version a list shows is never older than a file it leaves out. The titles come in the order of the day of
		 *	that shown version - the first that is not removed from the server, else the first. A file whose name says no version is a title with one version
		 *	that has no number. The files of one call are the
		 *	caller's choice of one folder: nothing is looked up, so a title never spans two folders. Pure
		 *
		 *	@param		array 		$files				A list of files as files() has them
		 *
		 *	@return 	array											A list of titles: title, ext and versions - a list of the files as they were given, each with version and number added
		 */
		public static function grouped( array $files ): array {

			$titles = [];

			foreach( $files as $file ) {

				if( is_array( $file ) === false )
					continue;

				$read = self::version( (string) ( $file['name'] ?? '' ) );
				$ext = strtolower( (string) ( $file['ext'] ?? '' ) );
				$key = $ext. "\0". $read['title'];

				$titles[$key] ??= [ 'title' => $read['title'], 'ext' => $ext, 'versions' => [] ];
				$titles[$key]['versions'][] = $file + [ 'version' => $read['version'], 'number' => $read['number'] ];
			}

			foreach( $titles as $key => $title ) {

				// A stable sort: the files of the same number keep the order they were given in, the newer first among them
				usort( $title['versions'], static fn( array $a, array $b ): int => [ $b['number'] ?? -1, (string) ( $b['created'] ?? '' ) ] <=> [ $a['number'] ?? -1, (string) ( $a['created'] ?? '' ) ] );
				$titles[$key] = $title;
			}

			// The title whose shown version - the highest number on the server - is the newest first; of two of the same time the one that was listed first
			$order = array_keys( $titles );
			$newest = static function( string $key ) use ( $titles ): string {

				foreach( $titles[$key]['versions'] as $file )
					if( isset( $file['gone'] ) === false )
						return (string) ( $file['created'] ?? '' );

				return (string) ( $titles[$key]['versions'][0]['created'] ?? '' );
			};

			usort( $order, static fn( string $a, string $b ): int => strcmp( $newest( $b ), $newest( $a ) ) );

			return array_map( static fn( string $key ): array => $titles[$key], $order );
		}

		/**
		 *	The value of the header Content-Disposition for a file: shown (inline) or saved (attachment), with the name
		 *	twice - the plain one for a browser that knows no better, cut down to letters, digits and a few signs, and the
		 *	one of RFC 5987 with the whole UTF-8 name percent-encoded
		 *
		 *	@param		string		$name					The name a file is kept under
		 *	@param		bool			$inline
		 *
		 *	@return 	string
		 */
		public static function disposition( string $name, bool $inline ): string {

			$plain = trim( (string) preg_replace( '/[^A-Za-z0-9._ -]+/', '_', $name ) );

			return ( $inline === true ? 'inline' : 'attachment' ). '; filename="'. ( $plain === '' ? 'file' : $plain ). '"; filename*=UTF-8\'\''. rawurlencode( $name );
		}

		/**
		 *	GET /.files/<id>: answer a file. The segment after /.files/ is an id of 12 hex characters or the answer is
		 *	the 404; the file is named for the kernel to stream (it is the absolute path of the bytes this feature
		 *	resolved itself, never a path the request said) with the type of the index, shown for audio, video, images
		 *	and pdf and saved for the rest - and always saved with ?download=1 - under the cleaned name, kept out of
		 *	every cache but the browser's own. It is answered to a member by the rule of allowed() and to the workbench
		 *	session of the owner by the permission of the panel (\Nino\Auth::checkPermission(), which a parent of it
		 *	such as '/_admin/*' or '/*' holds as well): any file that has bytes, a hidden one and one of any group
		 *	included. A file that was removed from the server is answered 410 with a plain sentence to whoever may have it
		 *	(allowed(), or the permission) - nobody else is told that it ever was. All the rest is the same 404 with a plain
		 *	body - a visitor, a member of another group, an account at the workbench without the permission, an id nobody has
		 *	and a file whose bytes are gone
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackDownload( array &$appData, array &$request ): void {

			$uri = (string) ( $request['/nino/http/request']['uri'] ?? '' );
			$id = str_starts_with( $uri, '/.files/' ) === true ? substr( $uri, 8 ) : '';
			$file = $id !== '' ? \Nino\Modules\Files\Store::file( $appData, $id ) : null;
			$query = $request['/nino/http/request']['query'] ?? [];
			$saved = is_array( $query ) === true && ( $query['download'] ?? null ) === '1';
			$may = $file !== null && ( self::allowed( $appData, $file, $saved ) === true || \Nino\Auth::checkPermission( $appData, \Nino\Modules\Files\Admin::MANAGE_PERM ) === true );
			$path = $may === true ? \Nino\Modules\Files\Store::path( $appData, $id ) : null;

			if( $may === true && isset( $file['gone'] ) === true ) {

				$request['/nino/http/response']['statusCode']								= 410;
				$request['/nino/http/response']['body']										= trim( (string) \Nino\Html::resolveTextfill( $appData, '/feature/files/download/removed' ) );
				$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
				$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

				return;
			}

			if( $file === null || $path === null ) {

				$request['/nino/http/response']['statusCode']								= 404;
				$request['/nino/http/response']['body']										= 'Not Found';
				$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
				$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

				return;
			}

			$request['/nino/http/response']['file']															= $path;
			$request['/nino/http/response']['header']['Content-Type']						= $file['mime'] !== '' ? $file['mime'] : 'application/octet-stream';
			$request['/nino/http/response']['header']['Content-Disposition']		= self::disposition( $file['name'], $saved === false && \Nino\Modules\Files\Store::inline( $file['mime'] ) === true );
			$request['/nino/http/response']['header']['Cache-Control']					= 'private, no-store';
			$request['/nino/http/response']['header']['X-Content-Type-Options']	= 'nosniff';
		}

		/**
		 *	POST /.files/upload: one chunk of a member's upload, with the csrf token of the form (a post the csrf guard
		 *	refused is left as the kernel answers it, a 403). The visitor is counted first (the setting attempts, per
		 *	hour), whoever they are; then the member has to be signed in and the folder the one their group uploads
		 *	into - a visitor, a member of another group and a folder that is not the upload folder are the same
		 *	files_folder, a 404. The rest is Files\Upload's. The answer is json: the upload id of a chunk that is taken,
		 *	or the id, the name and the size of the file of the last chunk - and, with that, the callback '/files/upload'
		 *	and the mail to the owner.
		 *
		 *	A form post with no script is the same request with one chunk that is the whole file and no name or size of
		 *	its own: the file carries them. It is answered with a redirect (303) to the page it was posted from, with
		 *	?files=sent, or the code of the refusal
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function callbackUpload( array &$appData, array &$request ): void {

			if( \Nino\Form::blocked( $request ) === true )
				return;

			$plain = array_key_exists( 'name', $_POST ) === false;
			$return = $plain === true ? self::_returnPath( self::_posted( 'return' ) ) : '';
			$attempts = (int) \Nino\Features::setting( $appData, self::FEATURE_KEY, 'attempts', self::DEFAULT_ATTEMPTS );

			// Counted before anything is looked at: a visitor who is no member is counted as well
			if( \Nino\Feature::count( $appData, self::COUNTER, 'visitor|'. \Nino\Http::getClientIp( $appData ), $attempts, self::WINDOW ) === false ) {
				self::_refuse( $appData, $request, $return, [ 429, 'too many requests', 'files_limit', [], '' ] );
				return;
			}

			$member = \Nino\Modules\Members::current( $appData );
			$folder = \Nino\Modules\Files\Store::folder( $appData, self::_posted( 'folder' ) );

			if( $member === null || $folder === null || $member['group'] === '' || $folder['group'] !== $member['group'] || $folder['upload'] !== true ) {
				self::_refuse( $appData, $request, $return, [ 404, 'there is no such folder', 'files_folder', [], 'folder' ] );
				return;
			}

			$file = $_FILES['chunk'] ?? null;
			$post = [ 'name' => self::_posted( 'name' ), 'size' => self::_posted( 'size' ), 'upload' => self::_posted( 'upload' ), 'index' => self::_posted( 'index' ), 'count' => self::_posted( 'count' ) ];

			if( $plain === true ) {

				$post = [ 'name' => is_array( $file ) === true && is_string( $file['name'] ?? null ) === true ? $file['name'] : '',
					'size' => is_array( $file ) === true && is_int( $file['size'] ?? null ) === true ? $file['size'] : 0, 'upload' => '', 'index' => 0, 'count' => 1 ];

				// One post is one chunk: a file longer than a chunk is for the script to cut - and a file php refused for its size
				// (upload_max_filesize, or the field of the form) comes with no bytes and no size, which is no chunk that did not arrive
				$refused = is_array( $file ) === true && in_array( $file['error'] ?? null, [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true ) === true;

				if( $refused === true || $post['size'] > \Nino\Modules\Files\Store::chunkBytes( $appData ) ) {
					self::_refuse( $appData, $request, $return, [ 400, 'the file is larger than one post takes', 'files_size', [], 'size' ] );
					return;
				}
			}

			$result = \Nino\Modules\Files\Upload::receive( $appData, $folder, $post, $file, [ 'kind' => 'member', 'id' => $member['id'] ], true );

			if( isset( $result['fail'] ) === true ) {
				self::_refuse( $appData, $request, $return, $result['fail'] );
				return;
			}

			if( isset( $result['file'] ) === true ) {

				$entry = $result['file'];

				\Nino\Callbacks::doCallbacks( $appData, '/files/upload', $entry );
				self::notifyOwner( $appData, $entry, $folder, $member );

				if( $plain === true ) {
					\Nino\Http::redirect( $appData, $request, $return. '?files=sent', 303 );
					return;
				}

				\Nino\Http::ok( $request, [ 'id' => $entry['id'], 'name' => $entry['name'], 'size' => $entry['size'] ] );
				return;
			}

			\Nino\Http::ok( $request, [ 'upload' => $result['part']['upload'], 'index' => $result['part']['index'] ] );
		}

		/**
		 *	[files-browser] - for a member who is signed in, the folders of their group, each with the files that are
		 *	visible to them (allowed()): the name as a link to the file, its size, the day it was put there and - where the
		 *	attribute downloads is on - a link that saves it. A row carries the id and the MIME type of its file, for the
		 *	features and the scripts that build on it. Nothing for a visitor
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments (see \Nino\Modules\Components::dispatch())
		 *
		 *	@return 	string
		 */
		public static function componentFilesBrowser( array &$appData, array $args ): string {

			$member = \Nino\Modules\Members::current( $appData );

			if( $member === null )
				return '';

			$words = self::_words( $appData );
			$folders = '';

			foreach( $member['group'] === '' ? [] : \Nino\Modules\Files\Store::folders( $appData, $member['group'] ) as $folder ) {

				$rows = '';
				$count = 0;

				foreach( \Nino\Modules\Files\Store::files( $appData, $folder['id'] ) as $file ) {

					if( self::allowed( $appData, $file ) === false || isset( $file['gone'] ) === true )
						continue;

					$count++;
					$url = self::url( $appData, $file['id'] );

					$rows .= str_replace(
						[ '[[id]]', '[[mime]]', '[[name]]', '[[size]]', '[[created]]', '[[date]]', '[[url]]', '[[download]]' ],
						[
							$file['id'],
							\Nino\Modules\Components::escape( $file['mime'] ),
							\Nino\Modules\Components::escape( $file['name'] ),
							\Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
							\Nino\Modules\Components::escape( $file['created'] ),
							\Nino\Modules\Components::escape( substr( $file['created'], 0, 10 ) ),
							\Nino\Modules\Components::escape( $url ),
							( $args['downloads'] ?? '1' ) === '1' ? str_replace( '[[url]]', \Nino\Modules\Components::escape( $url ), \Nino\Feature::template( $appData, self::TEMPLATES, 'files-download' ) ) : '',
						],
						\Nino\Feature::template( $appData, self::TEMPLATES, 'files-row' )
					);
				}

				$folders .= str_replace(
					[ '[[id]]', '[[name]]', '[[count]]', '[[rows]]' ],
					[ $folder['id'], \Nino\Modules\Components::escape( $folder['name'] ), (string) $count, $rows !== '' ? $rows : \Nino\Feature::template( $appData, self::TEMPLATES, 'files-empty' ) ],
					\Nino\Feature::template( $appData, self::TEMPLATES, 'files-folder' )
				);
			}

			return str_replace(
				[ '[[classes]]', '[[folders]]' ],
				[ \Nino\Feature::classes( $args ), $folders !== '' ? $folders : \Nino\Feature::template( $appData, self::TEMPLATES, 'files-none' ) ],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'files-browser' )
			);
		}

		/**
		 *	[files-upload] - for a member whose group has an upload folder, the form that uploads into it: a file input,
		 *	the size of the chunks (data-files-chunk) and of the largest file (data-files-max) for the script, the
		 *	hidden folder, the csrf token and the page the form stands on, and a state line when the query says one. It
		 *	is a form that posts to /.files/upload and works without the script, for one file at a time. Nothing for a
		 *	visitor, and nothing for a group without an upload folder
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array			$args					The resolved arguments
		 *
		 *	@return 	string
		 */
		public static function componentFilesUpload( array &$appData, array $args ): string {

			$member = \Nino\Modules\Members::current( $appData );

			if( $member === null || $member['group'] === '' )
				return '';

			$target = null;

			foreach( \Nino\Modules\Files\Store::folders( $appData, $member['group'] ) as $folder )
				if( $folder['upload'] === true ) {
					$target = $folder;
					break;
				}

			if( $target === null )
				return '';

			$words = self::_words( $appData );
			$state = self::_state( $appData );
			$request = \Nino\Http::getRequest( $appData );

			// Every value escaped, and the markup that was built last: the state is a template of its own - left out
			// where there is none to say - and a token after it would be looked for in it as well
			return str_replace(
				[ '[[multiple]]', '[[id]]', '[[return]]', '[[folder]]', '[[chunk]]', '[[max]]', '[[size]]', '[[types]]', '[[classes]]', '[[state]]' ],
				[
					( $args['multiple'] ?? '1' ) === '1' ? '1' : '0',
					'files-upload-'. ( $appData[self::FORMS] = (int) ( $appData[self::FORMS] ?? 0 ) + 1 ),
					\Nino\Modules\Components::escape( $request === false ? '' : (string) ( $request['/nino/http/request']['uri'] ?? '' ) ),
					$target['id'],
					(string) \Nino\Modules\Files\Store::chunkBytes( $appData ),
					(string) \Nino\Modules\Files\Store::maxBytes( $appData ),
					\Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( \Nino\Modules\Files\Store::maxBytes( $appData ), $words ) ),
					\Nino\Modules\Components::escape( implode( ', ', \Nino\Modules\Files\Store::types( $appData ) ) ),
					\Nino\Feature::classes( $args ),
					$state === '' ? '' : str_replace( '[[name]]', $state, \Nino\Feature::template( $appData, self::TEMPLATES, 'files-state' ) ),
				],
				\Nino\Feature::template( $appData, self::TEMPLATES, 'files-upload' )
			);
		}

		/**
		 *	Tell the owner of a member's upload, where the setting notifyOwner is on: one mail to the contact address
		 *	of the website, with the name of the file, its folder and who put it there. A site without an address, or
		 *	without a contact address that is one, sends none and says so in a warning that names neither; a mail that
		 *	is not delivered, or that the kernel's cap of mails an hour refuses, is a warning too, naming the file by its
		 *	id and never an address
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$file					The entry, with its id
		 *	@param		array 		$folder				Its folder
		 *	@param		array 		$member				The account that uploaded it
		 *
		 *	@return 	bool										Whether a mail was delivered
		 */
		public static function notifyOwner( array &$appData, array $file, array $folder, array $member ): bool {

			if( \Nino\Features::setting( $appData, self::FEATURE_KEY, 'notifyOwner', false ) !== true )
				return false;

			$to = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			$link = \Nino\Html::absoluteUrl( $appData, '/_admin#files' );

			if( filter_var( $to, FILTER_VALIDATE_EMAIL ) === false || $link === '' ) {
				trigger_error( 'Files: no mail was sent to the owner, the site has no contact address or no address of its own', E_USER_WARNING );
				return false;
			}

			$mail = self::_render( $appData, 'mail-upload', 'upload', static function( array $words ) use ( $file, $folder, $member, $link ): array {

				return [
					'[[file]]'		=> \Nino\Modules\Components::escape( $file['name'] ),
					'[[size]]'		=> \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ),
					'[[folder]]'	=> \Nino\Modules\Components::escape( $folder['name'] ),
					'[[who]]'			=> \Nino\Modules\Components::escape( $member['name'] !== '' ? $member['name'] : $member['email'] ),
					'[[link]]'		=> \Nino\Modules\Components::escape( $link ),
				];
			} );

			if( \Nino\Mail::send( $appData, $to, $mail['subject'], $mail['body'], '' ) === true )
				return true;

			trigger_error( 'Files: the mail to the owner about the file '. $file['id']. ( \Nino\Mail::rateLimited( $appData ) === true ? ' was refused by the cap of mails an hour' : ' was not delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	Tell a group of files that are there for them, where the setting notifyMembers is on or the owner chose so for this action: one mail to each
		 *	active member of the folder's group naming the files - all the files of one panel request in one mail - and
		 *	linking the page members land on after they sign in. The mails go out in one call of the kernel's mail
		 *	cap, which counts a call and not a mail. Mails that are not all delivered, or that the cap refuses, are a
		 *	warning that names the files by their ids and never an address
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		$folder				The folder, with its id
		 *	@param		array 		$files				The entries that are visible now, each with its id
		 *	@param		bool|null	$notify				The owner's choice for this action: true mails the group whatever the setting says, false mails nobody, null (not asked) is the setting
		 *
		 *	@return 	bool										Whether every mail was delivered - true where there was none to send: the setting off or the choice false, no file, nobody in the group. The number delivered is in './nino/files/mailed'
		 */
		public static function notifyMembers( array &$appData, array $folder, array $files, ?bool $notify = null ): bool {

			// How many mails were delivered, for the panel's answer to say whether the members were told: none until some are
			$appData['./nino/files/mailed'] = 0;

			if( ( $notify ?? \Nino\Features::setting( $appData, self::FEATURE_KEY, 'notifyMembers', false ) ) !== true || $files === [] )
				return true;

			$recipients = array_values( array_filter( \Nino\Modules\Members::accounts( $appData ),
				static fn( array $account ): bool => $account['group'] === $folder['group'] && $account['active'] === true ) );

			if( $recipients === [] )
				return true;

			$link = \Nino\Html::absoluteUrl( $appData, (string) \Nino\Features::setting( $appData, 'members', 'start', '/' ) );

			if( $link === '' ) {
				trigger_error( 'Files: no mail was sent to the group, the site has no address of its own', E_USER_WARNING );
				return false;
			}

			$replyTo = trim( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/contact/email' ) );
			$replyTo = filter_var( $replyTo, FILTER_VALIDATE_EMAIL ) === false ? '' : $replyTo;
			$mails = [];

			foreach( $recipients as $account ) {

				$mail = self::_render( $appData, 'mail-new', 'new', function( array $words ) use ( &$appData, $folder, $files, $link, $account ): array {

					$items = '';

					foreach( $files as $file )
						$items .= str_replace( [ '[[file]]', '[[size]]' ],
							[ \Nino\Modules\Components::escape( $file['name'] ), \Nino\Modules\Components::escape( \Nino\Modules\Files\Store::size( $file['size'], $words ) ) ],
							\Nino\Feature::template( $appData, self::TEMPLATES, 'mail-item' ) );

					return [
						'[[name]]'		=> \Nino\Modules\Components::escape( $account['name'] !== '' ? $account['name'] : $account['email'] ),
						'[[folder]]'	=> \Nino\Modules\Components::escape( $folder['name'] ),
						'[[link]]'		=> \Nino\Modules\Components::escape( $link ),
						'[[files]]'		=> $items,
					];
				} );

				$mails[] = [ 'to' => $account['email'], 'subject' => $mail['subject'], 'body' => $mail['body'], 'replyTo' => $replyTo ];
			}

			if( \Nino\Mail::sendAll( $appData, $mails ) === true ) {
				$appData['./nino/files/mailed'] = count( $mails );
				return true;
			}

			trigger_error( 'Files: the mails to the group about the files '. implode( ', ', array_column( $files, 'id' ) ). ( \Nino\Mail::rateLimited( $appData ) === true ? ' were refused by the cap of mails an hour' : ' were not all delivered' ), E_USER_WARNING );

			return false;
		}

		/**
		 *	Rewrite the staged index of a backup before /_admin's Restore copies it over the live one. The bytes are not
		 *	in a backup - only the index is - so an index of a day may name files whose bytes are gone, or that were
		 *	deleted since: the entries whose bytes are in the live directory are kept and the rest dropped, so that no
		 *	member is offered a file that is not there - except an entry that was removed from the server, which has no bytes and
		 *	stays, and one the live index has removed since the day of the backup, which is taken over as removed. A file put there after the day of the backup has no entry in it,
		 *	and its bytes stay where they are. Reached through the '/nino/admin/restore' callback init() registers, with
		 *	the live /data directory and the extracted backup as arguments; a no-op if the backup carries no index. The
		 *	write is not silenced: one that fails is php's own warning, and the staged index stays as it was
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$args				(reference) { dataDir: live /data (read only), staging: extracted backup }
		 *
		 *	@return 	void
		 */
		public static function callbackRestore( array &$appData, array &$args ): void {

			$dataDir = (string) ( $args['dataDir'] ?? '' );
			$staging = (string) ( $args['staging'] ?? '' );
			$staged = $staging. '/data/files.php';

			if( $dataDir === '' || $staging === '' || is_file( $staged ) === false )
				return;

			$index = \Nino\Modules\Files\Store::parse( include $staged );
			$live = is_file( $dataDir. '/files.php' ) === true ? \Nino\Modules\Files\Store::parse( include $dataDir. '/files.php' ) : [ 'files' => [] ];

			foreach( $index['files'] as $id => $file ) {

				// An entry that was removed has no bytes to look for: it stays, as removed - and so does one the live index has removed since the backup
				if( isset( $file['gone'] ) === false && isset( $live['files'][$id]['gone'] ) === true )
					$index['files'][$id]['gone'] = $live['files'][$id]['gone'];
				elseif( isset( $file['gone'] ) === false && is_file( $dataDir. '/files/'. $id. '.'. $file['ext'] ) === false )
					unset( $index['files'][$id] );
			}

			file_put_contents( $staged, '<?php return '. var_export( $index, true ). ';' );
		}

		/**
		 *	The state the form says: what the query 'files' names, one of STATES
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	string										The name of its word below /feature/files/state/ - 'sent', or the code without 'files_'; '' for none
		 */
		private static function _state( array &$appData ): string {

			$request = \Nino\Http::getRequest( $appData );
			$query = $request === false ? null : ( $request['/nino/http/request']['query'] ?? null );
			$asked = is_array( $query ) === true ? ( $query['files'] ?? null ) : null;

			if( is_string( $asked ) === false || in_array( $asked, self::STATES, true ) === false )
				return '';

			return str_starts_with( $asked, 'files_' ) === true ? substr( $asked, 6 ) : $asked;
		}

		/**
		 *	The words of a size: the units and the separator of the decimals, in the language of the page
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		private static function _words( array &$appData ): array {

			$words = [];

			foreach( [ 'b' => 'byte', 'kb' => 'kilobyte', 'mb' => 'megabyte', 'gb' => 'gigabyte', 'separator' => 'separator' ] as $key => $name )
				$words[$key] = (string) \Nino\Html::resolveTextfill( $appData, '/feature/files/unit/'. $name );

			return $words;
		}

		/**
		 *	A mail rendered in the site's native language, whoever asked: the template and the subject are rendered
		 *	first - their words are the keys of the unit - and the values are put in afterwards, in the result, so that
		 *	nothing in them is ever read as a fill or a shortcode (the order \Nino\Form::render() keeps)
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$template			mail-upload or mail-new
		 *	@param		string		$kind					upload or new: the subject is /feature/files/mail/<kind>-subject
		 *	@param		callable	$values				( array $words ): array - token => value, the values escaped, the markup last
		 *
		 *	@return 	array										[ 'subject' => ..., 'body' => ... ]
		 */
		private static function _render( array &$appData, string $template, string $kind, callable $values ): array {

			$locale = \Nino\Locales::getCurrentLocale( $appData );

			\Nino\Locales::useLocale( $appData, \Nino\Locales::getNativeLocale( $appData ) );

			try {
				$body = \Nino\Html::renderHtml( $appData, \Nino\Feature::template( $appData, self::TEMPLATES, $template ) );
				$subject = \Nino\Html::renderHtml( $appData, '[[/feature/files/mail/'. $kind. '-subject]]' );
				$filled = $values( self::_words( $appData ) );
			}
			finally {
				\Nino\Locales::useLocale( $appData, $locale );
			}

			return [ 'subject' => $subject, 'body' => str_replace( array_keys( $filled ), array_values( $filled ), $body ) ];
		}

		/**
		 *	Answer a refusal of the upload: the failure as json, or - for the form without script - a redirect to the page
		 *	it was posted from with the code in the query
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$return				The page of a plain form post, '' for a post of the script
		 *	@param		array 		$fail					status, error, code, params and field, as Upload::receive() has them
		 *
		 *	@return 	void
		 */
		private static function _refuse( array &$appData, array &$request, string $return, array $fail ): void {

			if( $return !== '' ) {
				\Nino\Http::redirect( $appData, $request, $return. '?files='. $fail[2], 303 );
				return;
			}

			\Nino\Http::fail( $request, ...$fail );
		}

		/**
		 *	One posted value, as a string - nothing says a post carries strings, and 'folder[]=x' would be an "Array to
		 *	string conversion", a level the runtime treats as fatal
		 *
		 *	@param		string		$name
		 *
		 *	@return 	string
		 */
		private static function _posted( string $name ): string {

			return is_string( $_POST[$name] ?? null ) === true ? $_POST[$name] : '';
		}

		/**
		 *	The page the answer of a plain post goes back to: the posted 'return' without its query, where it is a path
		 *	of this site and no endpoint of a module - the front page for anything else
		 *
		 *	@param		string		$posted
		 *
		 *	@return 	string
		 */
		private static function _returnPath( string $posted ): string {

			$path = substr( $posted, 0, strcspn( $posted, '?#' ) );

			return str_starts_with( $path, '/' ) === true && str_starts_with( $path, '//' ) === false && str_starts_with( $path, '/\\' ) === false && str_starts_with( $path, '/.' ) === false
				&& preg_match( '/[\s\x00-\x1f\x7f]/', $path ) !== 1 && preg_match( '#^/[^/]*:#', $path ) !== 1 ? $path : '/';
		}
	}
}

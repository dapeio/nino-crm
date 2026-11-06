<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Nino\Modules\Files\Admin		The /_admin panel of the Files feature - see docs/features.md, "The panel"
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Files {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Files\Admin				"Files" panel (Dateien): the owner's side of the feature on one screen. A
	 *										select of the groups; the folders of the group - add, rename, delete (a
	 *										folder with files deletes its files), mark as the folder the members
	 *										upload into, or take the mark off; the files of a folder - name, size,
	 *										who put it there, the day, whether it is visible to the members,
	 *										download (a link), move to another folder of the group, delete; and the
	 *										owner's upload, in the chunks of the members' (Files\Upload) through the
	 *										action files/upload, with the limits of the server in a line of its own.
	 *										A file can be removed from the server (files/remove): its bytes go, its row
	 *										stays with the day they went - no download, no move, delete still there.
	 *
	 *										The panel validates what it is posted and answers with the codes of the
	 *										failures a person can cause; the rules of the data and the writes are
	 *										Files\Store's, the protocol of an upload is Files\Upload's, and the mail to a
	 *										group is the class's. The panel has no action that downloads: it hands every
	 *										file its address (Files::url()) and the screen links it, and the route
	 *										GET /.files/<id>, which the kernel streams, answers the workbench session of
	 *										the owner by the permission of this panel (Files::callbackDownload()).
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Admin {

		public const string MANAGE_PERM = '/_admin/files/manage';

		// The id of the file an action made or touched, by action: written by the action, read by log() in the same request
		private static array $_made = [];

		public static function perm(): string {
			return self::MANAGE_PERM;
		}

		public static function actions(): array {
			return [
				'files/list'								=> [ self::class, 'apiList' ],
				'files/folder/add'					=> [ self::class, 'apiFolderAdd' ],
				'files/folder/save'					=> [ self::class, 'apiFolderSave' ],
				'files/folder/delete'				=> [ self::class, 'apiFolderDelete' ],
				'files/folder/upload-target'	=> [ self::class, 'apiFolderTarget' ],
				'files/upload'							=> [ self::class, 'apiUpload' ],
				'files/save'								=> [ self::class, 'apiSave' ],
				'files/delete'							=> [ self::class, 'apiDelete' ],
				'files/remove'							=> [ self::class, 'apiRemove' ],
			];
		}

		// No group is named here: a panel a feature brought always lands in the rail's own "features" group
		public static function nav(): array {
			return [ 'files', '/_admin/files/nav', 55 ];
		}

		public static function icon(): string {
			return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>';
		}

		// The panel renders its own region (see templates/panel.tpl) rather than mount points a script fills
		public static function template(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/templates/panel' );
		}

		public static function assets(): array {
			return [ \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.js' ), \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/assets/admin.css' ) ];
		}

		// The panel's own strings, one <locale>.php per interface language
		public static function text(): string {
			return \Nino\Admin\Panels::relative( dirname( __DIR__ ). '/text' );
		}

		/**
		 *	The Dashboard's tile: how many files there are and how much room they take
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array
		 */
		public static function summary( array &$appData ): array {

			$fills = \Nino\Admin\Admin::textFills( $appData, '/features/Files/text', \Nino\Admin\Admin::sessionLocale( $appData ) );
			$words = [];

			foreach( [ 'b' => 'byte', 'kb' => 'kilobyte', 'mb' => 'megabyte', 'gb' => 'gigabyte', 'separator' => 'separator' ] as $key => $name )
				$words[$key] = (string) ( $fills['[[/_admin/files/unit/'. $name. ']]'] ?? '' );

			$files = Store::read( $appData )['files'];
			$kept = array_filter( $files, static fn( array $file ): bool => isset( $file['gone'] ) === false );

			return [ 'value' => count( $files ). ' ('. Store::size( (int) array_sum( array_column( $kept, 'size' ) ), $words ). ')', 'label' => '/_admin/files/label/count' ];
		}

		/**
		 *	The activity-log line of a completed action: the id of the folder or the file where there is one, never the
		 *	address or the name of a member. An upload says so once, when its last chunk is in - not for every chunk
		 *
		 *	@param		string		$action
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		public static function log( string $action, array $data ): string {

			$id = self::_id( $data );
			$of = $id === '' ? '' : ' '. $id;

			return match( $action ) {
				'files/folder/add'					=> 'Added folder'. ( self::$_made[$action] ?? '' ),
				'files/folder/save'					=> 'Renamed folder'. $of,
				'files/folder/delete'				=> 'Deleted folder'. $of,
				'files/folder/upload-target'	=> ( ( $data['on'] ?? true ) === false ? 'Took the upload mark off folder' : 'Marked as upload folder the folder' ). $of,
				'files/upload'							=> ( self::$_made[$action] ?? '' ) === '' ? '' : 'Uploaded file '. self::$_made[$action]. ( ( $data['notify'] ?? null ) === false ? ', members not mailed' : '' ),
				'files/save'								=> 'Changed file'. $of. ( ( $data['notify'] ?? null ) === false && ( $data['visible'] ?? null ) === true ? ', members not mailed' : '' ),
				'files/delete'							=> 'Deleted file'. $of,
				'files/remove'							=> ( self::$_made[$action] ?? '' ) === '' ? '' : 'Removed file '. self::$_made[$action]. ' from the server',
				default											=> '',
			};
		}

		/**
		 *	What the screen draws: the groups, the folders of one group with their number of files, the files of those
		 *	folders - each with the address it is downloaded at, Files::url() - and the limits the uploads run into. The
		 *	group is the posted one where it is among the groups, the first otherwise. A group is listed when a member
		 *	has it and when a folder has it: a folder of a group that has no member any more can still be seen and deleted
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiList( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$groups = self::_groups( $appData );
			$group = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'group' );
			$group = in_array( $group, $groups, true ) === true ? $group : ( $groups[0] ?? '' );
			$index = Store::read( $appData );
			$counts = array_count_values( array_column( $index['files'], 'folder' ) );
			$folders = [];
			$files = [];
			$labels = [];

			foreach( Store::folders( $appData, $group ) as $folder ) {

				$folders[] = $folder + [ 'count' => (int) ( $counts[$folder['id']] ?? 0 ) ];

				foreach( Store::files( $appData, $folder['id'] ) as $file ) {

					$who = $file['by']['id'];

					if( $file['by']['kind'] === 'member' && isset( $labels[$who] ) === false ) {
						$account = \Nino\Modules\Members::account( $appData, $who );
						$labels[$who] = $account === null ? '' : ( $account['name'] !== '' ? $account['name'] : $account['email'] );
					}

					$files[] = array_merge( $file, [ 'by' => $file['by'] + [ 'label' => $file['by']['kind'] === 'member' ? $labels[$who] : '' ], 'url' => \Nino\Modules\Files::url( $appData, $file['id'] ) ] );
				}
			}

			\Nino\Http::ok( $request, [
				'groups'	=> $groups,
				'group'		=> $group,
				'folders'	=> $folders,
				'files'		=> $files,
				'types'		=> Store::types( $appData ),
				'notify'	=> \Nino\Features::setting( $appData, 'files', 'notifyMembers', false ) === true,
				'limits'	=> [
					'chunk'		=> Store::chunkBytes( $appData ),
					'max'			=> Store::maxBytes( $appData ),
					'php'			=> \Nino\Images::limits()['php'],
					'quota'		=> Store::quotaBytes( $appData ),
					'used'		=> $group === '' ? 0 : Store::used( $appData, $group ),
				],
			] );
		}

		/**
		 *	Add a folder to a group of the Members feature. A group the members do not have, a name that is empty and one
		 *	that is too long are refused with their codes, the field they were posted in beside them
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiFolderAdd( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$group = \Nino\Admin\Admin::postString( $data, 'group' );

			if( in_array( $group, \Nino\Modules\Members::groups( $appData ), true ) === false ) {
				\Nino\Http::fail( $request, 400, 'the members have no such group', 'files_group', [], 'group' );
				return;
			}

			$folder = Store::addFolder( $appData, $group, \Nino\Admin\Admin::postString( $data, 'name' ) );

			if( is_string( $folder ) === true ) {
				self::_failed( $request, $folder );
				return;
			}

			self::$_made['files/folder/add'] = ' '. $folder['id'];

			\Nino\Http::ok( $request, [ 'folder' => $folder ] );
		}

		/**
		 *	Rename a folder
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiFolderSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$folder = Store::renameFolder( $appData, self::_id( $data ), \Nino\Admin\Admin::postString( $data, 'name' ) );

			if( is_string( $folder ) === true ) {
				self::_failed( $request, $folder );
				return;
			}

			\Nino\Http::ok( $request, [ 'folder' => $folder ] );
		}

		/**
		 *	Delete a folder and its files, the bytes with them; the callback '/files/delete' is told of each file
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiFolderDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$gone = Store::deleteFolder( $appData, self::_id( \Nino\Admin\Admin::postData() ) );

			if( is_string( $gone ) === true ) {
				self::_failed( $request, $gone );
				return;
			}

			foreach( $gone['files'] as $file )
				\Nino\Callbacks::doCallbacks( $appData, '/files/delete', $file );

			\Nino\Http::ok( $request, [ 'deleted' => count( $gone['files'] ) ] );
		}

		/**
		 *	Mark a folder as the one the members of its group upload into - the folder that had the mark loses it - or,
		 *	with on false, take the mark off, and the group has none
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiFolderTarget( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$folder = Store::markUpload( $appData, self::_id( $data ), ( $data['on'] ?? true ) !== false );

			if( is_string( $folder ) === true ) {
				self::_failed( $request, $folder );
				return;
			}

			\Nino\Http::ok( $request, [ 'folder' => $folder ] );
		}

		/**
		 *	The owner's choice whether the members are mailed for this action
		 *
		 *	@param		array 		$data				The posted payload
		 *
		 *	@return 	bool|null								The field notify where it is a bool; null (the setting decides) where it is absent or anything else
		 */
		private static function _notify( array $data ): ?bool {

			return is_bool( $data['notify'] ?? null ) === true ? $data['notify'] : null;
		}

		/**
		 *	The page the mail to the members links, when the owner's action names one
		 *
		 *	@param		array 		$data				The posted payload
		 *
		 *	@return 	string|null							The field page where it is a path of this site as Files::page() accepts it; null (the page members land on) where it is absent or anything else
		 */
		private static function _page( array $data ): ?string {

			return is_string( $data['page'] ?? null ) === true && \Nino\Modules\Files::page( $data['page'] ) === true ? $data['page'] : null;
		}

		/**
		 *	One chunk of the owner's upload, with the fields of the route of the members' - folder, name, size, upload,
		 *	index and count - and the bytes as the file part chunk; and visible, which is true unless it says false, for
		 *	a file that is not shown to the members yet; and notify, a bool that says whether the members are mailed (absent: the setting decides), and page, the path of the page the mail links (absent, or not a path of this site: the page members land on). A part that did not arrive is the shell's refusal
		 *	(Admin::uploadError()). The owner uploads into any folder. The last chunk puts the file in: the callback
		 *	'/files/add' is told of it and, where it is visible and the setting notifyMembers is on - or notify says true; false mails nobody - the group is mailed
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiUpload( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::$_made['files/upload'] = '';

			$failure = \Nino\Admin\Admin::uploadError( $_FILES['chunk'] ?? null );

			if( $failure !== null ) {
				\Nino\Http::fail( $request, $failure['status'], $failure['error'], $failure['code'], $failure['params'], 'chunk' );
				return;
			}

			$data = \Nino\Admin\Admin::postData();
			$folder = Store::folder( $appData, \Nino\Admin\Admin::postString( $data, 'folder' ) );

			if( $folder === null ) {
				\Nino\Http::fail( $request, 404, 'there is no such folder', 'files_folder', [], 'folder' );
				return;
			}

			$result = Upload::receive( $appData, $folder, $data, $_FILES['chunk'] ?? null, [ 'kind' => 'owner', 'id' => '' ], ( $data['visible'] ?? true ) !== false );

			if( isset( $result['fail'] ) === true ) {
				\Nino\Http::fail( $request, ...$result['fail'] );
				return;
			}

			if( isset( $result['part'] ) === true ) {
				\Nino\Http::ok( $request, [ 'upload' => $result['part']['upload'], 'index' => $result['part']['index'] ] );
				return;
			}

			$entry = $result['file'];
			self::$_made['files/upload'] = $entry['id'];

			\Nino\Callbacks::doCallbacks( $appData, '/files/add', $entry );

			$appData['./nino/files/mailed'] = 0;

			if( $entry['visible'] === true )
				\Nino\Modules\Files::notifyMembers( $appData, $folder, [ $entry ], self::_notify( $data ), self::_page( $data ) );

			\Nino\Http::ok( $request, [ 'id' => $entry['id'], 'name' => $entry['name'], 'size' => $entry['size'], 'mailed' => (int) $appData['./nino/files/mailed'] ] );
		}

		/**
		 *	Change a file: whether it is visible to the members, and the folder it is in - another folder of the same
		 *	group. A file that is made visible is told to the group where the setting notifyMembers is on or notify says true (false: not told); page names the page the mail links, as in the upload
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiSave( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$data = \Nino\Admin\Admin::postData();
			$changes = [];

			if( is_bool( $data['visible'] ?? null ) === true )
				$changes['visible'] = $data['visible'];

			if( is_string( $data['folder'] ?? null ) === true )
				$changes['folder'] = $data['folder'];

			$saved = Store::changeFile( $appData, self::_id( $data ), $changes );

			if( is_string( $saved ) === true ) {
				self::_failed( $request, $saved );
				return;
			}

			$appData['./nino/files/mailed'] = 0;

			if( $saved['before']['visible'] === false && $saved['file']['visible'] === true && isset( $saved['file']['gone'] ) === false ) {

				$folder = Store::folder( $appData, $saved['file']['folder'] );

				if( $folder !== null )
					\Nino\Modules\Files::notifyMembers( $appData, $folder, [ $saved['file'] ], self::_notify( $data ), self::_page( $data ) );
			}

			\Nino\Http::ok( $request, [ 'file' => $saved['file'], 'mailed' => (int) $appData['./nino/files/mailed'] ] );
		}

		/**
		 *	Delete a file, the bytes with it; the callback '/files/delete' is told of it
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiDelete( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			$gone = Store::deleteFile( $appData, self::_id( \Nino\Admin\Admin::postData() ) );

			if( is_string( $gone ) === true ) {
				self::_failed( $request, $gone );
				return;
			}

			\Nino\Callbacks::doCallbacks( $appData, '/files/delete', $gone );

			\Nino\Http::ok( $request );
		}

		/**
		 *	Remove the bytes of a file from the server and keep its row: the name, the size, who put it there and when stay, with the time the bytes went
		 *	in gone. A file that is removed already is answered as it is, nothing changed; the comments of other features on it stay. No callback is told:
		 *	the file is still there for them
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		array 		&$request			(reference) Current server request
		 *
		 *	@return 	void
		 */
		public static function apiRemove( array &$appData, array &$request ): void {

			if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
				return;

			self::$_made['files/remove'] = '';

			$removed = Store::removeBytes( $appData, self::_id( \Nino\Admin\Admin::postData() ) );

			if( is_string( $removed ) === true ) {
				self::_failed( $request, $removed );
				return;
			}

			self::$_made['files/remove'] = $removed['changed'] === true ? $removed['file']['id'] : '';

			\Nino\Http::ok( $request, [ 'file' => $removed['file'], 'changed' => $removed['changed'] ] );
		}

		/**
		 *	The groups the screen offers: those the members have and those that have a folder
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *
		 *	@return 	array										Slugs, sorted
		 */
		private static function _groups( array &$appData ): array {

			$groups = \Nino\Modules\Members::groups( $appData );

			foreach( Store::read( $appData )['folders'] as $folder )
				$groups[] = $folder['group'];

			$groups = array_values( array_unique( $groups ) );
			sort( $groups );

			return $groups;
		}

		/**
		 *	Answer a refusal of the store: the failures a person can cause carry a code (and its sentence is in both
		 *	languages, see text/), the failure of a write is the server's and has none
		 *
		 *	@param		array 		&$request			(reference) Current server request
		 *	@param		string		$why					What Store answered: empty, long, group, unknown, folder or write
		 *
		 *	@return 	void
		 */
		private static function _failed( array &$request, string $why ): void {

			match( $why ) {
				'empty'		=> \Nino\Http::fail( $request, 400, 'the name is empty', 'files_name', [], 'name' ),
				'long'		=> \Nino\Http::fail( $request, 400, 'the name is too long', 'files_long', [ Store::FOLDER_LENGTH ], 'name' ),
				'group'		=> \Nino\Http::fail( $request, 400, 'the members have no such group', 'files_group', [], 'group' ),
				'unknown'	=> \Nino\Http::fail( $request, 404, 'unknown folder or file', 'files_unknown' ),
				'folder'	=> \Nino\Http::fail( $request, 400, 'the folder has to be another folder of the same group', 'files_target', [], 'folder' ),
				default		=> \Nino\Http::fail( $request, 500, 'could not write the files' ),
			};
		}

		/**
		 *	The posted id of a folder or a file: 12 hex characters or nothing. Anything else is an id nobody has
		 *
		 *	@param		array 		$data					What the action was posted
		 *
		 *	@return 	string
		 */
		private static function _id( array $data ): string {

			$id = \Nino\Admin\Admin::postString( $data, 'id' );

			return preg_match( Store::ID, $id ) === 1 ? $id : '';
		}
	}
}

<?php
declare(strict_types=1);
/**
 *	Crm\Projekte\Admin		The API of the projects behind the surface's endpoint (Surface::actions()): what the screens Projekte and Projektkarte read
 *												beside the element itself - the title, the client, the note, the day, the switch and the folder come from the type /projekte,
 *												the counts from the documents and from the feature Files. The projects are made, changed and removed through the Elements
 *												panel's own actions (see Surface\Elements) and their folder through the Files panel's (see Surface\Files); these two actions only read:
 *
 *												crm-projekte/list		the projects (a client's, the open ones, a search in the title) with their client, the number of their documents
 *																						and of the offers among them that wait, and the number of their files; and native, the language the screens save in, and allowed -
 *																						whether the account holds the permission of the Files panel while the feature is on, as it is now (a setup changes it under the page)
 *												crm-projekte/get		one project with its client, its folder and the files in it - each audio or video file with the number of its comments and of the open ones,
 *																						where the feature Review is on (review says whether it is)
 *
 *												The files are read from the feature (Files::folders(), Files::files(), Files::url()) by the CRM's own read: the permission of the
 *												CRM is the owner's, and the list is for looking. The feature may be off; then there are no files to count and the answer says so.
 *												It validates what it is posted and answers the failures a person can cause with codes (their sentences are in text/); the rules of
 *												the data are Crm\Projekte's. A database failure is answered 500 with the code crm_documents_database.
 */
namespace Crm\Projekte;

use Crm\Projekte;

class Admin {

	public const string MANAGE_PERM = \Crm\Crm::MANAGE_PERM;

	public static function actions(): array {
		return [
			'crm-projekte/list' => [ self::class, 'apiList' ],
			'crm-projekte/get'  => [ self::class, 'apiGet' ],
		];
	}

	/**
	 *	Whether the files of the projects can be read: the feature is on
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function filesOn( array &$appData ): bool {

		return class_exists( '\\Nino\\Modules\\Files' ) === true && \Crm\Crm::featureOn( $appData, 'files' ) === true;
	}

	/**
	 *	Whether the comments on the files can be read: the feature Review is on
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function reviewOn( array &$appData ): bool {

		return class_exists( '\\Nino\\Modules\\Review' ) && \Crm\Crm::featureOn( $appData, 'review' ) === true;
	}

	/**
	 *	The projects by the filters: client (the id of a client), open (leave out the done ones) and q (part of the title). Answers missing - the element
	 *	type is not there yet, which Einrichten brings - and whether the feature Files is on
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$client = \Nino\Admin\Admin::postString( $data, 'client' );

		if( $client !== '' && preg_match( '/^[1-9][0-9]{0,9}$/D', $client ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the filter is not valid', 'crm_projekte_format', [], 'client' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $data, $client ): void {

			$number = null;

			if( $client !== '' ) {

				$found = \Crm\Clients::get( $appData, (int) $client );

				// A client that is not there has no projects
				if( $found === null ) {
					\Nino\Http::ok( $request, [ 'missing' => Projekte::missing( $appData ), 'native' => \Nino\Locales::getNativeLocale( $appData ), 'files' => self::filesOn( $appData ), 'allowed' => \Crm\Surface\Files::allowed( $appData ), 'projects' => [] ] );
					return;
				}

				$number = $found['number'];
			}

			$rows = Projekte::list( $appData, $number, ( $data['open'] ?? false ) === true, \Nino\Admin\Admin::postString( $data, 'q' ) );

			\Nino\Http::ok( $request, [
				'missing'  => Projekte::missing( $appData ),
				'native'   => \Nino\Locales::getNativeLocale( $appData ),
				'files'    => self::filesOn( $appData ),
				'allowed'  => \Crm\Surface\Files::allowed( $appData ),
				'projects' => self::_shown( $appData, $rows, false ),
			] );
		} );
	}

	/**
	 *	One project: id. Answers the project as list has it, its client, its folder (id and name; null where it has none or the folder is gone from Files) and
	 *	its files with the address each is downloaded at - null where the feature Files is off - and notify, the setting of Files that mails the members of a file that is made visible
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			$id = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'id' );

			if( Projekte::missing( $appData ) === true ) {
				\Nino\Http::fail( $request, 404, 'the element type of the projects is not there', 'crm_projekte_missing', [], 'id' );
				return;
			}

			$project = Projekte::get( $appData, $id );

			if( $project === null ) {
				\Nino\Http::fail( $request, 404, 'there is no such project', 'crm_projekte_unknown', [], 'id' );
				return;
			}

			// The counts come with the list of the client's projects: one grouped query
			$row = $project + [ 'documents' => 0, 'open' => 0 ];

			foreach( Projekte::list( $appData, $project['client'] ) as $one )
				if( $one['id'] === $id )
					$row = $one;

			$shown = self::_shown( $appData, [ $row ], true )[0];
			$list = $shown['file_list'];
			$folder = $shown['folder_name'] === null ? null : [ 'id' => $shown['folder'], 'name' => $shown['folder_name'] ];
			unset( $shown['file_list'], $shown['folder_name'] );

			\Nino\Http::ok( $request, [
				'native'  => \Nino\Locales::getNativeLocale( $appData ),
				'project' => $shown,
				'client'  => $shown['client_id'] === 0 ? null : [ 'id' => $shown['client_id'], 'number' => $shown['client'], 'title' => $shown['client_title'], 'active' => $shown['client_active'] ],
				'files'   => self::filesOn( $appData ),
				'allowed' => \Crm\Surface\Files::allowed( $appData ),
				'review'  => self::filesOn( $appData ) === true && self::reviewOn( $appData ) === true,
				'folder'  => $folder,
				'list'    => $list,
				// The setting of the feature Files that mails the members of a file made visible: what the box of the tab starts at
				'notify'  => self::filesOn( $appData ) === true && \Nino\Features::setting( $appData, 'files', 'notifyMembers', false ) === true,
			] );
		} );
	}

	/**
	 *	The finished projects whose files are still on the server and have lain there for long: a project that is done, whose folder is one of its client's group, holds
	 *	files that are not removed, and none of them is younger than the days. What is meant is the room those files take, which the owner may free
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$days					The age of the youngest file that is still counted
	 *
	 *	@return 	array											count (the projects), bytes (of their files on the server) and days
	 */
	public static function stale( array &$appData, int $days = 60 ): array {

		$stale = [ 'count' => 0, 'bytes' => 0, 'days' => $days ];

		if( self::filesOn( $appData ) === false || Projekte::missing( $appData ) === true )
			return $stale;

		$limit = gmdate( 'Y-m-d\TH:i:s\Z', time() - $days * 86400 );
		$folders = [];

		foreach( Projekte::list( $appData ) as $project ) {

			if( $project['done'] !== true || $project['folder'] === '' || $project['client'] === '' )
				continue;

			$group = \Crm\Clients::group( $project['client'] );
			$folders[$group] ??= array_column( \Nino\Modules\Files::folders( $appData, $group ), 'id' );

			if( in_array( $project['folder'], $folders[$group], true ) === false )
				continue;

			$kept = array_filter( \Nino\Modules\Files::files( $appData, $project['folder'] ), static fn( array $file ): bool => isset( $file['gone'] ) === false );

			if( $kept === [] || array_filter( $kept, static fn( array $file ): bool => (string) $file['created'] > $limit ) !== [] )
				continue;

			$stale['count']++;
			$stale['bytes'] += array_sum( array_column( $kept, 'size' ) );
		}

		return $stale;
	}

	/**
	 *	The projects with what the screens show beside them: the client's id and title, the number of the files and - for one project - the files
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$projects			As Projekte::list() has them
	 *	@param		bool			$withFiles		Also the list of the files of each
	 *
	 *	@return 	array
	 */
	private static function _shown( array &$appData, array $projects, bool $withFiles ): array {

		$clients = [];

		foreach( \Crm\Clients::list( $appData ) as $client )
			$clients[$client['number']] = $client;

		$on = self::filesOn( $appData );
		$folders = [];
		$shown = [];

		foreach( $projects as $project ) {

			$client = $clients[$project['client']] ?? null;
			$folder = null;
			$files = null;

			// The folder is one of the group of the client's number: a folder that is gone from Files (or lies in another group) is none
			if( $on === true && $project['folder'] !== '' && $client !== null ) {

				$group = \Crm\Clients::group( $client['number'] );
				$folders[$group] ??= \Nino\Modules\Files::folders( $appData, $group );

				foreach( $folders[$group] as $one )
					if( $one['id'] === $project['folder'] )
						$folder = $one;
			}

			if( $folder !== null )
				$files = \Nino\Modules\Files::files( $appData, $folder['id'] );

			$shown[] = $project + [
				'client_id'     => $client === null ? 0 : $client['id'],
				'client_title'  => $client === null ? '' : ( $client['company'] !== '' ? $client['company'] : $client['name'] ),
				'client_active' => $client !== null && $client['active'] === true,
				'files'         => $files === null ? ( $on === true ? 0 : null ) : count( $files ),
				'folder_name'   => $folder === null ? null : (string) $folder['name'],
				'file_list'     => $withFiles === true && $on === true ? self::_files( $appData, $files ?? [] ) : null,
			];
		}

		// The list of the projects carries the counts; the lists of files and the name of the folder are the card's
		return $withFiles === true ? $shown : array_map( static fn( array $one ): array => array_diff_key( $one, [ 'file_list' => 0, 'folder_name' => 0 ] ), $shown );
	}

	/**
	 *	The files of a folder as the tab shows them, the files of one title together (title, version - the digits of the name, '' for none -, versions, how many files the title has, and group, the place of the title in the list): the name, the size, the day, who put it there and whether the members see it, with the address it is downloaded at and - for a file that was removed from the server - the time its bytes went. An audio or video file carries its comments, all and open (comments), where the feature Review is on; null for any other
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$files				As Files::files() has them
	 *
	 *	@return 	array
	 */
	private static function _files( array &$appData, array $files ): array {

		$labels = [];
		$shown = [];
		$counts = self::reviewOn( $appData ) === true ? \Nino\Modules\Review::counts( $appData, array_map( static fn( array $file ): string => (string) $file['id'], $files ) ) : [];
		$ordered = [];

		// The files of a title together, the titles newest first and the versions of a title from the highest number down (Files::grouped()); group counts the titles
		foreach( \Nino\Modules\Files::grouped( $files ) as $index => $title )
			foreach( $title['versions'] as $file )
				$ordered[] = $file + [ 'group' => $index, 'title' => $title['title'], 'count' => count( $title['versions'] ) ];

		foreach( $ordered as $file ) {

			$kind = (string) ( $file['by']['kind'] ?? '' );
			$who = (string) ( $file['by']['id'] ?? '' );

			if( $kind === 'member' && isset( $labels[$who] ) === false ) {
				$account = \Crm\Crm::featureOn( $appData, 'members' ) === true ? \Nino\Modules\Members::account( $appData, $who ) : null;
				$labels[$who] = $account === null ? '' : ( $account['name'] !== '' ? $account['name'] : $account['email'] );
			}

			$shown[] = [
				'id'       => (string) $file['id'],
				'name'     => (string) $file['name'],
				'ext'      => (string) $file['ext'],
				'size'     => (int) $file['size'],
				'mime'     => (string) $file['mime'],
				'created'  => (string) $file['created'],
				'visible'  => ( $file['visible'] ?? false ) === true,
				'gone'     => (string) ( $file['gone'] ?? '' ),
				'by'       => [ 'kind' => $kind, 'label' => $kind === 'member' ? $labels[$who] : '' ],
				'url'      => \Nino\Modules\Files::url( $appData, (string) $file['id'] ),
				'title'    => (string) $file['title'],
				'version'  => (string) $file['version'],
				'versions' => (int) $file['count'],
				'group'    => (int) $file['group'],
				'comments' => isset( $counts[(string) $file['id']] ) === true && \Nino\Modules\Review::kind( (string) $file['mime'] ) !== '' ? $counts[(string) $file['id']] : null,
			];
		}

		return $shown;
	}
}

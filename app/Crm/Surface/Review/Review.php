<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Review		What the card of a project in the surface takes from the Review panel of the workbench: its actions review/comments (the comments on a
 *											file, with their answers) and review/done (a comment is done or open again) go through the endpoint as the panel has them, under the
 *											permission of the panel (\Nino\Modules\Review\Admin::MANAGE_PERM, besides the CRM's own) and only where the feature is on - 409 with the
 *											code review_off where it is not. One thing only the surface adds, as for the Files panel: both are for the files that lie in the folders of
 *											the clients and for no others. A file in a folder of a group that is no client's - or in no folder -  is refused before the panel is asked:
 *											404 crm_files_group, nothing delegated, so that the endpoint never reads or changes the comments of the files of the site. An id Files has no entry of is refused as the panel refuses it, 404
 *											review_unknown, and the panel is not asked: the comments left under it are not for this endpoint to switch.
 *
 *											The panel is used as it is: what it answers (and the name of the author of a comment - the account's name, else its address) and the line of
 *											the activity log are its, and so are the sentences of its codes. open() is the sum the first screen shows: the comments of members that no
 *											one has marked as done, on the files of the clients' folders.
 */
namespace Crm\Surface;

class Review {

	// The permission of the panel: the one the actions of review/* ask for
	public const string PERM = '/_admin/review/manage';

	// How many files the first screen names one by one
	public const int LIST_MAX = 10;

	// The actions of the panel that the surface offers, and the method of this class that answers each
	private const array ACTIONS = [ 'review/comments' => 'apiComments', 'review/done' => 'apiDone' ];

	/**
	 *	The callback '/review/owner-mail': a comment on a file of a project is told to the owner with the link of the card of that project and the client and the project named. A file
	 *	in no folder of a client's project is left to the panel's own mail
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$args				file, comment, link and about
	 *
	 *	@return 	void
	 */
	public static function callbackOwnerMail( array &$appData, array &$args ): void {

		if( \Crm\Projekte::missing( $appData ) === true )
			return;

		$folder = \Nino\Modules\Files\Store::folder( $appData, (string) ( $args['file']['folder'] ?? '' ) );
		$number = $folder === null ? null : Files::clientOf( $appData, (string) $folder['group'] );
		$client = $number === null ? null : \Crm\Clients::byNumber( $appData, $number );
		$project = $client === null ? null : \Crm\Projekte::ofFolder( $appData, $number, (string) $folder['id'] );

		if( $client === null || $project === null || \Nino\Html::absoluteUrl( $appData ) === '' )
			return;

		$args['link'] = \Nino\Html::absoluteUrl( $appData, \Crm\Surface::ROUTE. '#projekt-'. rawurlencode( (string) $project['id'] ) );
		$args['about'] = $client['title']. ' - '. ( $project['title'] !== '' ? $project['title'] : $project['id'] );
	}

	/**
	 *	Whether the panel is there to be asked
	 *
	 *	@return 	bool
	 */
	public static function available(): bool {

		return class_exists( '\\Nino\\Modules\\Review\\Admin' );
	}

	/**
	 *	The actions of the card: the panel's two, answered by the methods of this class
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		if( self::available() === false )
			return [];

		$actions = [];

		foreach( \Nino\Modules\Review\Admin::actions() as $action => $target )
			if( isset( self::ACTIONS[$action] ) === true )
				$actions[$action] = [ self::class, self::ACTIONS[$action] ];

		return $actions;
	}

	/**
	 *	Whether an action is one of these (and so needs the feature to be on)
	 *
	 *	@param		string		$action
	 *
	 *	@return 	bool
	 */
	public static function owns( string $action ): bool {

		return str_starts_with( $action, 'review/' ) === true;
	}

	/**
	 *	Whether the account holds the permission of the Review panel and the feature is on: what the card may offer
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function allowed( array &$appData ): bool {

		return self::available() === true && \Crm\Crm::featureOn( $appData, 'review' ) === true && \Nino\Auth::checkPermission( $appData, self::PERM ) === true;
	}

	/**
	 *	The file with the sentences of the panel
	 *
	 *	@return 	string										'' where the feature is not there
	 */
	public static function textFile(): string {

		if( self::available() === false )
			return '';

		$file = (string) ( new \ReflectionClass( '\\Nino\\Modules\\Review\\Admin' ) )->getFileName();
		$texts = dirname( $file, 2 ). '/text/de_DE.php';

		return is_file( $texts ) === true ? $texts : '';
	}

	/**
	 *	The line of the activity log: the panel's own for its actions
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return self::available() === true && isset( self::ACTIONS[$action] ) === true ? \Nino\Modules\Review\Admin::log( $action, $data ) : '';
	}

	/**
	 *	The files of the clients' folders that carry comments of members that are open - not marked as done: a file of the site's own folders and a file that is gone from Files are none of it
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											A list of file (its id), name, folder (its id) and count (the open comments); [] where the feature is off
	 */
	public static function scan( array &$appData ): array {

		if( self::available() === false || \Crm\Crm::featureOn( $appData, 'review' ) === false || \Crm\Crm::featureOn( $appData, 'files' ) === false )
			return [];

		$clients = [];
		$found = [];

		foreach( \Nino\Modules\Review\Store::read( $appData ) as $id => $comments ) {

			$open = \Nino\Modules\Review\Store::open( $comments );
			$file = $open === 0 ? null : \Nino\Modules\Files::file( $appData, (string) $id );

			if( $file === null || \Nino\Modules\Review::kind( (string) $file['mime'] ) === '' )
				continue;

			$folder = (string) $file['folder'];

			if( isset( $clients[$folder] ) === false ) {
				$known = \Nino\Modules\Files\Store::folder( $appData, $folder );
				$clients[$folder] = $known !== null && Files::clientOf( $appData, (string) $known['group'] ) !== null;
			}

			if( $clients[$folder] === true )
				$found[] = [ 'file' => (string) $id, 'name' => (string) $file['name'], 'folder' => $folder, 'count' => $open ];
		}

		return $found;
	}

	/**
	 *	The comments of members that are open on the files of the clients' folders, and on how many files: what the first screen says it needs - and, for each of the first
	 *	files, where it lies (the project whose folder it is in) and how many comments it carries, so that the screen can point at the file
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											count and files (both 0 where the feature is off) and list: file, name, count, project (its id; '' for a folder no project names) and project_title, the files with the most comments first, at most LIST_MAX
	 */
	public static function open( array &$appData ): array {

		$found = self::scan( $appData );
		$sum = [ 'count' => array_sum( array_column( $found, 'count' ) ), 'files' => count( $found ), 'list' => [] ];

		if( $found === [] )
			return $sum;

		usort( $found, static fn( array $a, array $b ): int => [ $b['count'], $a['name'], $a['file'] ] <=> [ $a['count'], $b['name'], $b['file'] ] );

		$projects = [];

		if( \Crm\Projekte::missing( $appData ) === false )
			foreach( \Crm\Projekte::list( $appData ) as $project )
				if( $project['folder'] !== '' )
					$projects[$project['folder']] = $project;

		foreach( array_slice( $found, 0, self::LIST_MAX ) as $one )
			$sum['list'][] = [ 'file' => $one['file'], 'name' => $one['name'], 'count' => $one['count'], 'project' => $projects[$one['folder']]['id'] ?? '', 'project_title' => $projects[$one['folder']]['title'] ?? '' ];

		return $sum;
	}

	/**
	 *	The open comments by folder
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											folder (its id) => [ count (the open comments), files ]; none for a folder with no open comment
	 */
	public static function folders( array &$appData ): array {

		$folders = [];

		foreach( self::scan( $appData ) as $one ) {
			$folders[$one['folder']] ??= [ 'count' => 0, 'files' => 0 ];
			$folders[$one['folder']]['count'] += $one['count'];
			$folders[$one['folder']]['files']++;
		}

		return $folders;
	}

	/**
	 *	The comments on a file of a client's folder, as the panel gives them
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiComments( array &$appData, array &$request ): void {

		if( self::fileIn( $appData, $request ) === true )
			\Nino\Modules\Review\Admin::apiComments( $appData, $request );
	}

	/**
	 *	A comment on a file of a client's folder is done, or open again
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiDone( array &$appData, array &$request ): void {

		if( self::fileIn( $appData, $request ) === true )
			\Nino\Modules\Review\Admin::apiDone( $appData, $request );
	}

	/**
	 *	The guard of both actions: the permission of the panel first (a 403 as the panel gives it), then the file the action names, which has to lie in the folder of a
	 *	client's group. A file nobody has is 404 review_unknown here, and nothing is touched by it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function fileIn( array &$appData, array &$request ): bool {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return false;

		$id = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'file' );
		$file = preg_match( \Nino\Modules\Files\Store::ID, $id ) === 1 ? \Nino\Modules\Files::file( $appData, $id ) : null;

		// A file Files has no entry of has no folder to be a client's: the panel's own sentence, and the panel is not asked - comments left under such an id are not to be switched
		if( $file === null ) {
			\Nino\Http::fail( $request, 404, 'unknown file or comment', 'review_unknown' );
			return false;
		}

		$folder = \Nino\Modules\Files\Store::folder( $appData, (string) $file['folder'] );

		if( $folder !== null && Files::clientOf( $appData, (string) $folder['group'] ) !== null )
			return true;

		\Nino\Http::fail( $request, 404, 'these actions are for the folders of the clients', 'crm_files_group' );

		return false;
	}
}

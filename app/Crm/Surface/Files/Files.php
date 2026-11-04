<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Files		What the tab Dateien of a project in the surface takes from the Files panel of the workbench: its actions files/list,
 *											files/folder/add, files/folder/save, files/upload, files/save and files/delete go through the endpoint as the panel has them,
 *											under the permission of the panel and only where the feature is on, and the sentences of their codes are the panel's own. One thing only
 *											the surface adds: every one of them is for the folders of the clients and for no others. A client's folder lies in the group of
 *											its number in Members (\Crm\Clients::group(): K-0001 is k-0001); an action that names the group of nobody - or a folder, or the folder of a
 *											file, that lies in another group - is refused before the panel is asked: 404 crm_files_group, nothing delegated, so that the endpoint never
 *											becomes an editor for the files of the site. Not offered: files/folder/delete and files/folder/upload-target - the owner takes those in the panel.
 *
 *											The panel is used as it is: what it posts (the chunks of an upload with the part chunk), what it answers and the line of the activity
 *											log are its. A file is cut into chunks and sent one after the other, as the panel's own script sends them.
 */
namespace Crm\Surface;

class Files {

	// The permission of the panel: the one the actions of files/* ask for
	public const string PERM = '/_admin/files/manage';

	// The actions that take a file with them, as a multipart post: one chunk of an upload (the part is called chunk, as the panel's script names it)
	public const array FILE_ACTIONS = [ 'files/upload' ];

	// What the group of a client looks like: the slug of its number
	private const string GROUP = '~^k-([0-9]{4,})$~D';

	// The actions of the panel that the surface offers, and the method of this class that answers each
	private const array ACTIONS = [
		'files/list' => 'apiList', 'files/folder/add' => 'apiFolderAdd', 'files/folder/save' => 'apiFolderSave',
		'files/upload' => 'apiUpload', 'files/save' => 'apiSave', 'files/delete' => 'apiDelete',
	];

	/**
	 *	Whether the panel is there to be asked
	 *
	 *	@return 	bool
	 */
	public static function available(): bool {

		return class_exists( '\\Nino\\Modules\\Files\\Admin' );
	}

	/**
	 *	The actions of the tab: the panel's six, answered by the methods of this class
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		if( self::available() === false )
			return [];

		$actions = [];

		foreach( \Nino\Modules\Files\Admin::actions() as $action => $target )
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

		return str_starts_with( $action, 'files/' ) === true;
	}

	/**
	 *	Whether the account holds the permission of the Files panel and the feature is on: what the screens may offer to change
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function allowed( array &$appData ): bool {

		return self::available() === true && \Crm\Crm::featureOn( $appData, 'files' ) === true && \Nino\Auth::checkPermission( $appData, self::PERM ) === true;
	}

	/**
	 *	The file with the sentences of the panel
	 *
	 *	@return 	string										'' where the feature is not there
	 */
	public static function textFile(): string {

		if( self::available() === false )
			return '';

		$file = (string) ( new \ReflectionClass( '\\Nino\\Modules\\Files\\Admin' ) )->getFileName();
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

		return self::available() === true && isset( self::ACTIONS[$action] ) === true ? \Nino\Modules\Files\Admin::log( $action, $data ) : '';
	}

	/**
	 *	The number of the client whose group this is
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$group
	 *
	 *	@return 	string|null								K-0001; null where the group is no client's (not the slug of a number, or no client has that number)
	 */
	public static function clientOf( array &$appData, string $group ): ?string {

		if( preg_match( self::GROUP, $group, $found ) !== 1 )
			return null;

		$number = 'K-'. $found[1];

		try {
			return \Crm\Clients::group( $number ) === $group && \Crm\Clients::byNumber( $appData, $number ) !== null ? $number : null;
		}
		catch( \PDOException $e ) {
			return null;
		}
	}

	/**
	 *	The guard of every action: the permission of the panel first (a 403 as the panel gives it)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function guard( array &$appData, array &$request ): bool {

		return \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM );
	}

	/**
	 *	Refuse an action that is for the folder of nobody's client
	 *
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	private static function refuse( array &$request ): void {

		\Nino\Http::fail( $request, 404, 'these actions are for the folders of the clients', 'crm_files_group' );
	}

	/**
	 *	Whether a group is a client's; the refusal is made where it is not
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$group
	 *
	 *	@return 	bool
	 */
	private static function clients( array &$appData, array &$request, string $group ): bool {

		if( self::clientOf( $appData, $group ) !== null )
			return true;

		self::refuse( $request );

		return false;
	}

	/**
	 *	Whether a folder lies in a client's group; the refusal is made where it does not (or is none)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$id						The id of a folder
	 *
	 *	@return 	bool
	 */
	private static function folderIn( array &$appData, array &$request, string $id ): bool {

		$folder = preg_match( \Nino\Modules\Files\Store::ID, $id ) === 1 ? \Nino\Modules\Files\Store::folder( $appData, $id ) : null;

		if( $folder !== null && self::clientOf( $appData, (string) $folder['group'] ) !== null )
			return true;

		self::refuse( $request );

		return false;
	}

	/**
	 *	The files of the folders of a client's group: the panel's answer, for the group that was asked for. The panel falls back to its first group for one it does not
	 *	know - a client without an account and without a folder - and the surface must not show that group's folders as this client's
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === false )
			return;

		$group = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'group' );

		if( self::clients( $appData, $request, $group ) === false )
			return;

		\Nino\Modules\Files\Admin::apiList( $appData, $request );

		$body = $request['/nino/http/response']['body'] ?? null;

		if( (int) ( $request['/nino/http/response']['statusCode'] ?? 200 ) === 200 && is_array( $body ) === true && ( $body['group'] ?? null ) !== $group )
			\Nino\Http::ok( $request, [ 'group' => $group, 'folders' => [], 'files' => [] ] + $body );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiFolderAdd( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true && self::clients( $appData, $request, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'group' ) ) === true )
			\Nino\Modules\Files\Admin::apiFolderAdd( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiFolderSave( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true && self::folderIn( $appData, $request, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'id' ) ) === true )
			\Nino\Modules\Files\Admin::apiFolderSave( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiUpload( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true && self::folderIn( $appData, $request, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'folder' ) ) === true )
			\Nino\Modules\Files\Admin::apiUpload( $appData, $request );
	}

	/**
	 *	A file is changed or deleted where the folder it lies in is a client's. A file nobody has is the panel's to answer: nothing is touched by it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool
	 */
	private static function fileIn( array &$appData, array &$request ): bool {

		if( self::guard( $appData, $request ) === false )
			return false;

		$file = \Nino\Modules\Files::file( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'id' ) );

		return $file === null || self::folderIn( $appData, $request, (string) $file['folder'] );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiSave( array &$appData, array &$request ): void {

		if( self::fileIn( $appData, $request ) === true )
			\Nino\Modules\Files\Admin::apiSave( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiDelete( array &$appData, array &$request ): void {

		if( self::fileIn( $appData, $request ) === true )
			\Nino\Modules\Files\Admin::apiDelete( $appData, $request );
	}
}

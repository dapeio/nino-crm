<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Elements		What the screen Positionen of the surface takes from the Elements panel of the workbench: its actions
 *													elements/list, elements/get, elements/save and elements/delete go through the endpoint as the panel has them, under the
 *													permission of the panel, and the sentences of their codes are the panel's own. One thing only the surface adds: every one
 *													of them is for the element type /positionen and for no other. A post that names another type (or none) is refused before
 *													the panel is asked - 404 crm_positionen_type -, so that the endpoint never becomes an editor for the elements of the site.
 *
 *													The panel is used as it is: what a save posts (type, uri, locale, isNew, fields), what it answers and the line of the
 *													activity log are its. The panel saves one language per post; the editor of the screen posts the native language with the
 *													numbers first and then each other language that has words. Not offered: elements/types (the Types tab is the developer's),
 *													elements/uploadimage and elements/removeimage (the type has no image field).
 */
namespace Crm\Surface;

class Elements {

	// The permission of the panel: the one the actions of elements/* ask for
	public const string PERM = '/_admin/elements/manage';

	// The one type the actions are for: the name the panel posts, which is the element type /positionen without its slash
	public const string TYPE = 'positionen';

	// The actions of the panel that the surface offers, and the method of this class that answers each
	private const array ACTIONS = [ 'elements/list' => 'apiList', 'elements/get' => 'apiGet', 'elements/save' => 'apiSave', 'elements/delete' => 'apiDelete' ];

	/**
	 *	The actions of the screen: the panel's four, answered by the methods of this class
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		$actions = [];

		foreach( \Nino\Modules\Elements\Admin::actions() as $action => $target )
			if( isset( self::ACTIONS[$action] ) === true )
				$actions[$action] = [ self::class, self::ACTIONS[$action] ];

		return $actions;
	}

	/**
	 *	Whether the account holds the permission of the Elements panel
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function allowed( array &$appData ): bool {

		return \Nino\Auth::checkPermission( $appData, self::PERM ) === true;
	}

	/**
	 *	The file with the sentences of the panel
	 *
	 *	@return 	string										'' where the panel has none
	 */
	public static function textFile(): string {

		$file = (string) ( new \ReflectionClass( '\\Nino\\Modules\\Elements\\Admin' ) )->getFileName();
		$texts = dirname( $file, 2 ). '/text/de_DE.php';

		return is_file( $texts ) === true ? $texts : '';
	}

	/**
	 *	The line of the activity log: the panel's own for a save and a delete, none for a read
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return isset( self::ACTIONS[$action] ) === true ? \Nino\Modules\Elements\Admin::log( $action, $data ) : '';
	}

	/**
	 *	The guard of every action: the permission of the panel first (a 403 as the panel gives it), then the type
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function guard( array &$appData, array &$request ): bool {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return false;

		if( \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'type' ) !== self::TYPE ) {
			\Nino\Http::fail( $request, 404, 'these actions are for the element type '. self::TYPE, 'crm_positionen_type' );
			return false;
		}

		return true;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true )
			\Nino\Modules\Elements\Admin::apiList( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true )
			\Nino\Modules\Elements\Admin::apiGet( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiSave( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true )
			\Nino\Modules\Elements\Admin::apiSave( $appData, $request );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiDelete( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true )
			\Nino\Modules\Elements\Admin::apiDelete( $appData, $request );
	}
}

<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Elements		What the screens Positionen, Projekte and Termine of the surface take from the Elements panel of the workbench: its actions
 *													elements/list, elements/get, elements/save and elements/delete go through the endpoint as the panel has them, under the
 *													permission of the panel, and the sentences of their codes are the panel's own. One thing only the surface adds: every one
 *													of them is for the element types /positionen, /projekte and /events and for no other. A post that names another type (or none) is refused before
 *													the panel is asked - 404 crm_positionen_type -, so that the endpoint never becomes an editor for the elements of the site.
 *													For the type projekte two things more are the surface's, because the panel does not know the documents: a project that a
 *													document names is not deleted (409 crm_project_documents, with the count), nor one the customer approved (409 crm_project_approved) or one that a consent or its revocation names (409 crm_project_records), and the client of a project is the number of a
 *													client that is there (400 crm_project_client_unknown) and does not change while a document names the project (409
 *													crm_project_client_locked) - the documents of a project are the client's. For the type events one thing is the surface's: the group of a date is the group of a client that is there
 *													(k-0003, the number as a slug) or none, and a new date has a name of lower-case letters and digits divided by hyphens (400 crm_event_uri), so that a date made here is for one client or for everyone and for no group of Members that the
 *													owner did not mean (400 crm_event_group); a save that names no group leaves the one the date has.
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

	// The types the actions are for: the names the panel posts, which are the element types /positionen and /projekte without their slash
	public const string TYPE = 'positionen';
	public const string PROJECTS = 'projekte';
	public const string EVENTS = 'events';
	public const array TYPES = [ self::TYPE, self::PROJECTS, self::EVENTS ];

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

		if( in_array( \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'type' ), self::TYPES, true ) === false ) {
			\Nino\Http::fail( $request, 404, 'these actions are for the element types '. implode( ' and ', self::TYPES ), 'crm_positionen_type' );
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

		if( self::guard( $appData, $request ) === true && self::projectSaved( $appData, $request ) === true && self::eventSaved( $appData, $request ) === true ) {
			\Nino\Modules\Elements\Admin::apiSave( $appData, $request );
			self::coded( $appData, $request );
		}
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiDelete( array &$appData, array &$request ): void {

		if( self::guard( $appData, $request ) === true && self::projectDeleted( $appData, $request ) === true ) {
			\Nino\Modules\Elements\Admin::apiDelete( $appData, $request );
			self::coded( $appData, $request );
		}
	}

	/**
	 *	A refusal of the panel that has no code gets one - the panel answers some of its refusals with the kernel's English sentence alone -, and the field it is about where it names
	 *	one, so that the page says it in its own words at the field
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request					The answer of the panel
	 *
	 *	@return 	void
	 */
	private static function coded( array &$appData, array &$request ): void {

		$body = $request['/nino/http/response']['body'] ?? null;

		if( (int) ( $request['/nino/http/response']['statusCode'] ?? 200 ) < 400 || is_array( $body ) === false || isset( $body['code'] ) === true || is_string( $body['error'] ?? null ) === false )
			return;

		$error = $body['error'];
		$data = \Nino\Admin\Admin::postData();
		$type = \Nino\Admin\Admin::postString( $data, 'type' );
		$field = '';

		if( $error === 'invalid type, uri or locale' || $error === 'invalid type or uri' ) {

			$code = match( true ) {
				$error === 'invalid type, uri or locale' && \Nino\Locales::verifyLocale( $appData, \Nino\Admin\Admin::postString( $data, 'locale' ) ) === false => 'locale',
				in_array( $type, \Nino\Modules\Elements\Admin::types( $appData ), true ) === false => 'type',
				default => 'uri',
			};
			$code = 'crm_elements_'. $code;
			$field = substr( $code, 13 );
		}
		elseif( str_starts_with( $error, 'not allowed to' ) === true )
			$code = 'crm_elements_denied';
		elseif( str_contains( $error, 'does not exist' ) === true || $error === 'unknown type or element' || $error === 'element not found' ) {
			$code = 'crm_elements_unknown';
			$field = 'uri';
		}
		elseif( str_contains( $error, 'already exists' ) === true ) {
			$code = 'crm_elements_exists';
			$field = 'uri';
		}
		elseif( preg_match( '/(?:required element key|var type|Element value|Element reference) \'([^\']+)\'/', $error, $found ) === 1 ) {
			$code = 'crm_elements_value';
			$field = $found[1];
		}
		elseif( $error === 'delete vetoed' || (int) $request['/nino/http/response']['statusCode'] === 409 )
			$code = 'crm_elements_vetoed';
		else
			$code = 'crm_elements_failed';

		$request['/nino/http/response']['body']['code'] = $code;

		if( $field !== '' )
			$request['/nino/http/response']['body']['field'] = $field;
	}

	/**
	 *	What a save of a project must hold to: the client it names is a client, and it is the client it was while a document names the project
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function projectSaved( array &$appData, array &$request ): bool {

		$data = \Nino\Admin\Admin::postData();

		if( \Nino\Admin\Admin::postString( $data, 'type' ) !== self::PROJECTS || is_array( $data['fields'] ?? null ) === false || array_key_exists( 'client', $data['fields'] ) === false )
			return true;

		$number = is_string( $data['fields']['client'] ) === true ? $data['fields']['client'] : '';
		$allowed = false;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $data, $number, &$allowed ): void {

			if( $number === '' || \Crm\Clients::byNumber( $appData, $number ) === null ) {
				\Nino\Http::fail( $request, 400, 'the client is none', 'crm_project_client_unknown', [], 'client' );
				return;
			}

			$id = \Nino\Admin\Admin::postString( $data, 'uri' );
			$before = ( $data['isNew'] ?? false ) === true ? null : \Crm\Projekte::get( $appData, $id );
			$count = $before !== null && $before['client'] !== $number ? \Crm\Projekte::documentsOf( $appData, $id ) : 0;

			if( $count > 0 ) {
				\Nino\Http::fail( $request, 409, 'documents name this project', 'crm_project_client_locked', [ $count ], 'client' );
				return;
			}

			$allowed = true;
		} );

		return $allowed;
	}

	/**
	 *	What a save of a date must hold to: a new date has a name the site serves it by (lower-case letters and digits, divided by single hyphens, as the feed and the portal read it), and the group it names, if it names one, is none or the group of a client that is there and active
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function eventSaved( array &$appData, array &$request ): bool {

		$data = \Nino\Admin\Admin::postData();

		if( \Nino\Admin\Admin::postString( $data, 'type' ) !== self::EVENTS )
			return true;

		if( ( $data['isNew'] ?? false ) === true && preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', \Nino\Admin\Admin::postString( $data, 'uri' ) ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the name of a date is lower-case letters and digits divided by hyphens', 'crm_event_uri', [], 'uri' );
			return false;
		}

		if( is_array( $data['fields'] ?? null ) === false || array_key_exists( 'group', $data['fields'] ) === false )
			return true;

		$group = $data['fields']['group'];
		$allowed = false;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $group, &$allowed ): void {

			if( is_string( $group ) === false || \Crm\Termine::groupAllowed( $appData, $group ) === false ) {
				\Nino\Http::fail( $request, 400, 'the group is none of a client', 'crm_event_group', [], 'group' );
				return;
			}

			$allowed = true;
		} );

		return $allowed;
	}

	/**
	 *	A project that a document names is not deleted: the document would name a project nobody can find (the folder of the project in Files stays in any case); nor one the customer approved, whose record names it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool											Whether the panel may be asked
	 */
	private static function projectDeleted( array &$appData, array &$request ): bool {

		$data = \Nino\Admin\Admin::postData();

		if( \Nino\Admin\Admin::postString( $data, 'type' ) !== self::PROJECTS )
			return true;

		$count = null;
		$approved = false;
		$named = false;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, $data, &$count, &$approved, &$named ): void {
			$uri = \Nino\Admin\Admin::postString( $data, 'uri' );
			$count = \Crm\Projekte::documentsOf( $appData, $uri );
			$approved = \Crm\Records::approval( $appData, $uri ) !== null;
			$named = \Crm\Records::names( $appData, $uri );
		} );

		// The database failed, and the answer says so
		if( $count === null )
			return false;

		if( $count > 0 ) {
			\Nino\Http::fail( $request, 409, 'documents name this project', 'crm_project_documents', [ $count ] );
			return false;
		}

		// The customer approved the project: the record names it, and the kernel's veto would refuse the panel - the surface says it with its own sentence
		if( $approved === true ) {
			\Nino\Http::fail( $request, 409, 'the customer approved this project', 'crm_project_approved' );
			return false;
		}

		// A consent or its revocation names the project (a project with an approval was answered above): the same, with a sentence of its own
		if( $named === true ) {
			\Nino\Http::fail( $request, 409, 'a record of the evidence names this project', 'crm_project_records' );
			return false;
		}

		return true;
	}
}

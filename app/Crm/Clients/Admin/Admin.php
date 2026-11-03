<?php
declare(strict_types=1);
/**
 *	Crm\Clients\Admin		The panel Kunden of the CRM module: the clients (list in the shell's table, card, add, change,
 *											deactivate) and the accounts of their logins in Members - made with the client, invited, linked,
 *											sent a link again, switched off or on, renamed and deleted from the card, so that the panel
 *											Members need not be opened. The panel calls Members' PHP API, never its files; the permission of
 *											the panel covers it. The button that
 *											applies the install unit - the portal pages - and the status that tells what the module misses.
 *											The panel validates what it is posted and answers the failures a person can cause with codes;
 *											the rules of the data are Crm\Clients', the storage Crm\Db's. A database failure is answered
 *											500 with the code crm_clients_database.
 */
namespace Crm\Clients;

use Crm\Clients;

class Admin {

	public const string MANAGE_PERM = '/_admin/crm-clients/manage';

	// The id of an account of Members: 12 hex characters
	private const string ACCOUNT_ID = '/^[0-9a-f]{12}$/D';

	// The features the portal draws its pages with, in the order the status names them
	private const array FEATURES = [ 'members', 'files', 'review', 'sign', 'pdf' ];

	// What Members and the model answer when an account is refused, as the panel answers it: the status, the sentence for the log, the code, its parameters and the field
	private const array REFUSALS = [
		'invalid'  => [ 400, 'the address is not valid', 'crm_clients_email', [], 'email' ],
		'long'     => [ 400, 'the name is too long', 'crm_clients_long', [ 120 ], 'name' ],
		'taken'    => [ 409, 'the address is an account already', 'crm_clients_taken', [], 'email' ],
		'unknown'  => [ 404, 'the client has no such account', 'crm_clients_member', [], 'account' ],
		'member'   => [ 404, 'the client has no such account', 'crm_clients_member', [], 'account' ],
		'held'     => [ 409, 'a client holds the account already', 'crm_clients_held', [], 'account' ],
		'inactive' => [ 409, 'the client is not active', 'crm_clients_inactive', [], '' ],
		'write'    => [ 500, 'the account could not be written', 'crm_clients_write', [], '' ],
		'database' => [ 500, 'the database failed', 'crm_clients_database', [], '' ],
	];

	// The number of the client an action made or touched, by action: written by the action, read by log() in the same request
	private static array $_made = [];

	public static function perm(): string {
		return self::MANAGE_PERM;
	}

	public static function actions(): array {
		return [
			'crm-clients/list'     => [ self::class, 'apiList' ],
			'crm-clients/get'      => [ self::class, 'apiGet' ],
			'crm-clients/save'     => [ self::class, 'apiSave' ],
			'crm-clients/active'   => [ self::class, 'apiActive' ],
			'crm-clients/invite'   => [ self::class, 'apiInvite' ],
			'crm-clients/accounts' => [ self::class, 'apiAccounts' ],
			'crm-clients/unlinked' => [ self::class, 'apiUnlinked' ],
			'crm-clients/account-link'   => [ self::class, 'apiAccountLink' ],
			'crm-clients/account-resend' => [ self::class, 'apiAccountResend' ],
			'crm-clients/account-active' => [ self::class, 'apiAccountActive' ],
			'crm-clients/account-name'   => [ self::class, 'apiAccountName' ],
			'crm-clients/account-remove' => [ self::class, 'apiAccountRemove' ],
			'crm-clients/setup'    => [ self::class, 'apiSetup' ],
			'crm-clients/status'   => [ self::class, 'apiStatus' ],
		];
	}

	public static function nav(): array {
		return [ 'crm-clients', '/_admin/crm-clients/nav', 30, 'content' ];
	}

	public static function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
	}

	// The panel draws into the one region of templates/panel.tpl
	public static function template(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/templates/panel' );
	}

	public static function assets(): array {
		return [ \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.css' ) ];
	}

	public static function text(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/text' );
	}

	/**
	 *	The Dashboard's tile: the active clients. A database that cannot be opened leaves the tile out rather than the
	 *	Dashboard: the panel itself says the failure when it is opened
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null
	 */
	public static function summary( array &$appData ): ?array {

		try {
			return [ 'value' => \Crm\Clients::countActive( $appData ), 'label' => '/_admin/crm-clients/label/count' ];
		}
		catch( \PDOException $e ) {
			return null;
		}
	}

	/**
	 *	The activity-log line of a completed action: the number of the client, never an address or a name
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		$number = self::$_made[$action] ?? '';
		$of = $number === '' ? '' : ' '. $number;
		$id = is_string( $data['account'] ?? null ) === true && preg_match( self::ACCOUNT_ID, $data['account'] ) === 1 ? ' '. $data['account'] : '';

		return match( $action ) {
			'crm-clients/save'           => ( isset( $data['id'] ) === true && $data['id'] !== '' ? 'Changed client' : 'Added client' ). $of,
			'crm-clients/active'         => 'Switched client'. $of,
			'crm-clients/invite'         => 'Invited an account for client'. $of,
			'crm-clients/account-link'   => 'Linked account'. $id. ' to client'. $of,
			'crm-clients/account-resend' => 'Sent a link to account'. $id. ' of client'. $of,
			'crm-clients/account-active' => 'Switched account'. $id. ' of client'. $of,
			'crm-clients/account-name'   => 'Renamed account'. $id. ' of client'. $of,
			'crm-clients/account-remove' => 'Deleted account'. $id. ' of client'. $of,
			'crm-clients/setup'          => 'Applied the portal unit',
			default                      => '',
		};
	}

	/**
	 *	The clients, by number - all of them: the table of the panel searches, sorts and pages them where it stands
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {
			\Nino\Http::ok( $request, [ 'clients' => \Crm\Clients::list( $appData ) ] );
		} );
	}

	/**
	 *	One client with its accounts
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$client = self::_client( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $client !== null )
				\Nino\Http::ok( $request, [ 'client' => self::_withDocuments( $appData, $client ), 'accounts' => self::_accounts( $appData, $client['id'] ) ] );
		} );
	}

	/**
	 *	Add a client (no id) or change one (id)
	 */
	public static function apiSave( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$input = [];

		foreach( array_keys( \Crm\Clients::FIELDS ) as $field ) {

			if( array_key_exists( $field, $data ) === false )
				continue;

			if( is_string( $data[$field] ) === false ) {
				\Nino\Http::fail( $request, 400, 'the value is no text', 'crm_clients_format', [], $field );
				return;
			}

			$input[$field] = $data[$field];
		}

		foreach( [ 'account_email', 'account_name' ] as $field )
			if( array_key_exists( $field, $data ) === true && is_string( $data[$field] ) === false ) {
				\Nino\Http::fail( $request, 400, 'the value is no text', 'crm_clients_format', [], $field );
				return;
			}

		self::_run( $request, static function() use ( &$appData, &$request, $data, $input ): void {

			$first = null;

			if( \Nino\Admin\Admin::postString( $data, 'id' ) === '' ) {

				if( trim( \Nino\Admin\Admin::postString( $data, 'account_email' ) ) !== '' ) {

					if( self::_members( $appData, $request ) === false )
						return;

					$first = [ 'email' => trim( \Nino\Admin\Admin::postString( $data, 'account_email' ) ), 'name' => \Nino\Admin\Admin::postString( $data, 'account_name' ) ];
				}

				$client = \Crm\Clients::add( $appData, $input, $first );
			}
			else {
				$existing = self::_client( $appData, $request, $data );

				if( $existing === null )
					return;

				$client = \Crm\Clients::change( $appData, $existing['id'], $input );
			}

			if( is_string( $client ) === true ) {
				self::_refused( $request, $client );
				return;
			}

			self::$_made['crm-clients/save'] = $client['number'];

			$made = $client['first'] ?? null;
			unset( $client['first'] );

			$answer = [ 'client' => self::_withDocuments( $appData, $client ) ];

			// A client made with an account that was refused is made all the same: the answer says so, with the code of the refusal
			if( $first !== null )
				$answer['first'] = is_string( $made ) === true ? [ 'account' => null, 'mailed' => false, 'refused' => self::_account( $made ) ] : [ 'account' => $made['account'] ?? null, 'mailed' => $made['mailed'] ?? false, 'refused' => null ];

			\Nino\Http::ok( $request, $answer );
		} );
	}

	/**
	 *	Switch a client on or off: active true or false, without it the other way round. A client that is switched off has its accounts
	 *	switched off with it; the answer says how many, and carries the accounts as they are now
	 */
	public static function apiActive( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$client = self::_client( $appData, $request, $data );

			if( $client === null )
				return;

			$active = is_bool( $data['active'] ?? null ) === true ? $data['active'] : $client['active'] === false;
			$changed = \Crm\Clients::setActive( $appData, $client['id'], $active );

			if( is_string( $changed ) === true ) {
				self::_refused( $request, $changed );
				return;
			}

			self::$_made['crm-clients/active'] = $changed['number'];

			$switched = $changed['switched'];
			unset( $changed['switched'] );

			\Nino\Http::ok( $request, [ 'client' => self::_withDocuments( $appData, $changed ), 'accounts' => self::_accounts( $appData, $changed['id'] ), 'switched' => $switched ] );
		} );
	}

	/**
	 *	Invite an account: a Members account in the client's group, linked to the client, with the invitation mail.
	 *	An account that is made and cannot be mailed is answered with mailed false - the card shows it
	 */
	public static function apiInvite( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$client = self::_client( $appData, $request, $data );

			if( $client === null )
				return;

			if( $client['active'] === false ) {
				\Nino\Http::fail( $request, 409, 'the client is not active', 'crm_clients_inactive' );
				return;
			}

			if( self::_members( $appData, $request ) === false )
				return;

			$made = \Crm\Clients::invite( $appData, $client, trim( \Nino\Admin\Admin::postString( $data, 'email' ) ), \Nino\Admin\Admin::postString( $data, 'name' ) );

			if( is_string( $made ) === true ) {
				self::_refuse( $request, $made );
				return;
			}

			self::$_made['crm-clients/invite'] = $client['number'];

			\Nino\Http::ok( $request, $made );
		} );
	}

	/**
	 *	The accounts of a client with their state in Members
	 */
	public static function apiAccounts( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$client = self::_client( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $client !== null )
				\Nino\Http::ok( $request, [ 'accounts' => self::_accounts( $appData, $client['id'] ) ] );
		} );
	}

	/**
	 *	Apply the install unit of the module - the portal pages: routes, templates and words, each only where the
	 *	project has none, and /portal among the protected paths of Members, and as its start page, where the project has none of its own.
	 *	Answers what was added and what was there
	 */
	public static function apiSetup( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		if( \Crm\Crm::featureOn( $appData, 'members' ) === false ) {
			\Nino\Http::fail( $request, 409, 'the Members feature is not active', 'crm_clients_members' );
			return;
		}

		$unit = self::unitDir();
		$manifest = \Nino\Features::readUnitManifest( $unit ) ?? [];
		$native = (string) $appData['/nino/locales/native'];

		$routes = \Nino\AppData::stored( $appData, '/nino/http/routes', \Nino\AppData::DEFAULTS['/nino/http/routes'] );
		$routes = is_array( $routes ) ? $routes : \Nino\AppData::DEFAULTS['/nino/http/routes'];
		$before = $routes;
		$blacklist = [];
		$config = [];

		$templates = array_values( (array) ( $manifest['templates'] ?? [] ) );
		$hadTemplates = array_values( array_filter( $templates, static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/templates/'. $file ) ) );
		$textsBefore = count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] ) );

		$applied = \Nino\Features::applyUnit( $appData, $unit, \Nino\Locales::getAvailableLocales( $appData ), $routes, $blacklist, $config, false );

		if( $applied !== true ) {
			\Nino\Http::fail( $request, 500, 'the portal unit could not be applied: '. $applied );
			return;
		}

		$addedRoutes = [];

		foreach( $routes as $key => $route )
			if( ( $before[$key] ?? null ) !== $route )
				$addedRoutes[$key] = $route;

		// The persisted routes get the new ones and nothing else; the live ones keep what this request booted with
		if( $addedRoutes !== [] ) {

			$live = (array) ( $appData['/nino/http/routes'] ?? [] );

			$written = \Nino\Filesystem::mutate( $appData, '/config.php', static function( array $content ) use ( $addedRoutes ): array {

				$stored = is_array( $content['/nino/http/routes'] ?? null ) ? $content['/nino/http/routes'] : \Nino\AppData::DEFAULTS['/nino/http/routes'];

				foreach( $addedRoutes as $key => $route )
					if( isset( $stored[$key] ) === false )
						$stored[$key] = $route;

				$content['/nino/http/routes'] = $stored;

				return $content;
			} );

			if( $written === false ) {
				\Nino\Http::fail( $request, 500, 'the routes could not be written' );
				return;
			}

			$appData['/nino/http/routes'] = $live + $routes;
		}

		// A unit's config cannot write the setting of a feature, so the paths and the start page are set here - each only where
		// the project has nothing yet: no protected path, and the start page the site's root (the setting's own default)
		$posted = [];

		if( array_filter( (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), 'is_string' ) === [] )
			$posted['paths'] = [ '/portal' ];

		if( in_array( \Nino\Features::setting( $appData, 'members', 'start', '/' ), [ '', '/' ], true ) === true )
			$posted['start'] = '/portal';

		if( $posted !== [] && \Nino\Features::saveSettings( $appData, 'members', $posted ) !== [] ) {
			\Nino\Http::fail( $request, 500, 'the settings of Members could not be written' );
			return;
		}

		$paths = isset( $posted['paths'] );
		$start = isset( $posted['start'] );

		\Nino\Http::ok( $request, [
			'added' => [
				'routes'    => array_keys( $addedRoutes ),
				'templates' => array_values( array_diff( $templates, $hadTemplates ) ),
				'texts'     => count( (array) \Nino\Filesystem::getFileContent( $appData, '/text/'. $native. '.php', [] ) ) - $textsBefore,
				'paths'     => $paths,
				'start'     => $start,
			],
			'existed' => [
				'routes'    => array_values( array_diff( array_keys( (array) ( $manifest['routes'] ?? [] ) ), array_keys( $addedRoutes ) ) ),
				'templates' => $hadTemplates,
				'paths'     => $paths === false,
				'start'     => $start === false,
			],
		] );
	}

	/**
	 *	What the module misses: each item has a key and a state
	 *	  module   ok, off (the class is not in /nino/modules)
	 *	  data     ok, unwritable (the data directory)
	 *	  members, files, review, sign, pdf     ok, off (installed, not active), missing (not installed), problem
	 *	  portal   ok (routes, templates and the protected path are there), partial, none
	 */
	public static function apiStatus( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$items = [];

		$modules = array_map( static fn( mixed $class ): string => ltrim( (string) $class, '\\' ), (array) ( $appData['/nino/modules'] ?? [] ) );
		$items[] = [ 'key' => 'module', 'state' => in_array( \Crm\Crm::class, $modules, true ) === true ? 'ok' : 'off' ];

		// A read answers a read: the directory is looked at and not made - one that is not there yet is made by the first
		// connection, so what has to be writable is the directory above it
		$data = \Nino\Filesystem::path( $appData, '/data' );
		$items[] = [ 'key' => 'data', 'state' => is_writable( is_dir( $data ) === true ? $data : dirname( $data ) ) === true ? 'ok' : 'unwritable' ];

		foreach( self::FEATURES as $key ) {

			$feature = \Nino\Features::get( $appData, $key );

			$items[] = [ 'key' => $key, 'state' => match( true ) {
				$feature === null                 => 'missing',
				$feature['problems'] !== []       => 'problem',
				$feature['active'] === false      => 'off',
				default                           => 'ok',
			} ];
		}

		$manifest = \Nino\Features::readUnitManifest( self::unitDir() ) ?? [];
		$routes = \Nino\AppData::stored( $appData, '/nino/http/routes', [] );
		$routes = is_array( $routes ) ? $routes : [];
		$wanted = array_keys( (array) ( $manifest['routes'] ?? [] ) );
		$present = count( array_filter( $wanted, static fn( string $key ): bool => isset( $routes[$key] ) === true ) )
			+ count( array_filter( (array) ( $manifest['templates'] ?? [] ), static fn( string $file ): bool => \Nino\Filesystem::fileExists( $appData, '/templates/'. $file ) ) )
			+ ( in_array( '/portal', (array) \Nino\Features::setting( $appData, 'members', 'paths', [] ), true ) === true ? 1 : 0 );
		$total = count( $wanted ) + count( (array) ( $manifest['templates'] ?? [] ) ) + 1;

		$items[] = [ 'key' => 'portal', 'state' => $present === $total ? 'ok' : ( $present === 0 ? 'none' : 'partial' ) ];

		\Nino\Http::ok( $request, [ 'items' => $items ] );
	}

	/**
	 *	The directory of the install unit of the module
	 *
	 *	@return 	string
	 */
	public static function unitDir(): string {

		return dirname( __DIR__, 2 ). '/unit';
	}

	/**
	 *	Run an action over the database: a failure of it is a 500 with its code
	 *
	 *	@param		array 		&$request
	 *	@param		callable	$run
	 *
	 *	@return 	void
	 */
	private static function _run( array &$request, callable $run ): void {

		try {
			$run();
		}
		catch( \PDOException $e ) {
			\Nino\Http::fail( $request, 500, 'the database failed', 'crm_clients_database' );
		}
	}

	/**
	 *	The client a request names by id, or the 400/404 that says why not
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		array 		$data
	 *
	 *	@return 	array|null
	 */
	private static function _client( array &$appData, array &$request, array $data ): ?array {

		$id = \Nino\Admin\Admin::postString( $data, 'id' );

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the id is not valid', 'crm_clients_id', [], 'id' );
			return null;
		}

		$client = \Crm\Clients::get( $appData, (int) $id );

		if( $client === null )
			\Nino\Http::fail( $request, 404, 'there is no such client', 'crm_clients_unknown', [], 'id' );

		return $client;
	}

	/**
	 *	The answer to a refusal of the model
	 *
	 *	@param		array 		&$request
	 *	@param		string		$why					'empty', 'email:email', 'long:<field>', 'format:<field>' or 'unknown'
	 *
	 *	@return 	void
	 */
	private static function _refused( array &$request, string $why ): void {

		[ $kind, $field ] = array_pad( explode( ':', $why, 2 ), 2, '' );

		match( $kind ) {
			'empty'   => \Nino\Http::fail( $request, 400, 'a client needs a company or a name', 'crm_clients_empty', [], 'company' ),
			'email'   => \Nino\Http::fail( $request, 400, 'the address is not valid', 'crm_clients_email', [], 'email' ),
			'long'    => \Nino\Http::fail( $request, 400, 'the value is too long', 'crm_clients_long', [ \Crm\Clients::FIELDS[$field] ?? 0 ], $field ),
			'format'  => \Nino\Http::fail( $request, 400, 'the value has a wrong format', 'crm_clients_format', [], $field ),
			'write'   => self::_refuse( $request, 'write' ),
			default   => \Nino\Http::fail( $request, 404, 'there is no such client', 'crm_clients_unknown', [], 'id' ),
		};
	}

	/**
	 *	A client with the count of its documents: total and released to the portal
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *
	 *	@return 	array
	 */
	private static function _withDocuments( array &$appData, array $client ): array {

		return $client + [ 'documents' => \Crm\Documents::counts( $appData, $client['id'] ) ];
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		int				$clientId
	 *
	 *	@return 	array										The accounts of the client as Clients::accountsDetailed() has them
	 */
	private static function _accounts( array &$appData, int $clientId ): array {

		return \Crm\Clients::accountsDetailed( $appData, $clientId );
	}

	/**
	 *	Answer a refusal of Members or of the model about an account
	 *
	 *	@param		array 		&$request
	 *	@param		string		$why					A key of REFUSALS
	 *
	 *	@return 	void
	 */
	private static function _refuse( array &$request, string $why ): void {

		[ $status, $error, $code, $params, $field ] = self::REFUSALS[$why] ?? self::REFUSALS['write'];

		\Nino\Http::fail( $request, $status, $error, $code, $params, $field );
	}

	/**
	 *	A refusal as the answer of a save that made the client carries it
	 *
	 *	@param		string		$why					A key of REFUSALS
	 *
	 *	@return 	array										code, params and field
	 */
	private static function _account( string $why ): array {

		[ , , $code, $params, $field ] = self::REFUSALS[$why] ?? self::REFUSALS['write'];

		return [ 'code' => $code, 'params' => $params, 'field' => $field ];
	}

	/**
	 *	Whether the feature Members is on; if not, the 409 that says so
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	bool
	 */
	private static function _members( array &$appData, array &$request ): bool {

		if( \Crm\Crm::featureOn( $appData, 'members' ) === true )
			return true;

		\Nino\Http::fail( $request, 409, 'the Members feature is not active', 'crm_clients_members' );

		return false;
	}

	/**
	 *	The client and the account an action of an account names: the client by id, the feature Members on, the account by its id - one the client
	 *	holds, and for an action on a live account one that Members still has
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		array 		$data
	 *	@param		bool			$live					Whether the account has to be there in Members (deleting a gone one is allowed)
	 *	@param		bool			$mustBeActive	Whether the client has to be active
	 *
	 *	@return 	array|null								[ client, account ]; null once the answer is written
	 */
	private static function _target( array &$appData, array &$request, array $data, bool $live, bool $mustBeActive ): ?array {

		$client = self::_client( $appData, $request, $data );

		if( $client === null || self::_members( $appData, $request ) === false )
			return null;

		if( $mustBeActive === true && $client['active'] === false ) {
			self::_refuse( $request, 'inactive' );
			return null;
		}

		$id = \Nino\Admin\Admin::postString( $data, 'account' );

		if( preg_match( self::ACCOUNT_ID, $id ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the id of the account is not valid', 'crm_clients_account', [], 'account' );
			return null;
		}

		$account = \Crm\Clients::accountOf( $appData, $client['id'], $id );

		if( $account === null || ( $live === true && $account['gone'] === true ) ) {
			self::_refuse( $request, 'unknown' );
			return null;
		}

		return [ $client, $account ];
	}

	/**
	 *	The accounts of Members that no client holds, to choose one to link
	 */
	public static function apiUnlinked( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			if( self::_members( $appData, $request ) === false )
				return;

			\Nino\Http::ok( $request, [ 'accounts' => array_map( static fn( array $account ): array => [ 'id' => $account['id'], 'email' => $account['email'], 'name' => $account['name'], 'group' => $account['group'] ], \Crm\Clients::unlinked( $appData ) ) ] );
		} );
	}

	/**
	 *	Link an account of Members that no client holds to the client, and move it into the client's group
	 */
	public static function apiAccountLink( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$client = self::_client( $appData, $request, $data );

			if( $client === null || self::_members( $appData, $request ) === false )
				return;

			$id = \Nino\Admin\Admin::postString( $data, 'account' );

			if( preg_match( self::ACCOUNT_ID, $id ) !== 1 ) {
				\Nino\Http::fail( $request, 400, 'the id of the account is not valid', 'crm_clients_account', [], 'account' );
				return;
			}

			$account = \Crm\Clients::linkExisting( $appData, $client['id'], $id );

			if( is_string( $account ) === true ) {
				self::_refuse( $request, $account );
				return;
			}

			self::$_made['crm-clients/account-link'] = $client['number'];

			\Nino\Http::ok( $request, [ 'account' => $account ] );
		} );
	}

	/**
	 *	Send an account its link again: the invitation where it never signed in, a link to sign in where it did.
	 *	An account that is switched off has nothing to sign in to (409 crm_clients_off); the cap of mails of the kernel is a 429 of its own
	 */
	public static function apiAccountResend( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$target = self::_target( $appData, $request, \Nino\Admin\Admin::postData(), true, true );

			if( $target === null )
				return;

			[ $client, $account ] = $target;

			if( $account['active'] === false ) {
				\Nino\Http::fail( $request, 409, 'the account is switched off', 'crm_clients_off', [], 'account' );
				return;
			}

			if( \Crm\Clients::resend( $appData, $account ) === false ) {

				if( \Nino\Mail::rateLimited( $appData ) === true )
					\Nino\Http::fail( $request, 429, 'the kernel allows five mails an hour from one address', 'crm_clients_ratelimit' );
				else
					\Nino\Http::fail( $request, 500, 'the link could not be sent', 'crm_clients_mail' );

				return;
			}

			self::$_made['crm-clients/account-resend'] = $client['number'];

			\Nino\Http::ok( $request, [ 'sent' => true, 'kind' => $account['login'] === null ? 'invite' : 'login' ] );
		} );
	}

	/**
	 *	Switch an account on or off: active true or false, without it the other way round. An account of a client that is switched off
	 *	stays off until the client is on again
	 */
	public static function apiAccountActive( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$target = self::_target( $appData, $request, $data, true, false );

			if( $target === null )
				return;

			[ $client, $account ] = $target;
			$active = is_bool( $data['active'] ?? null ) === true ? $data['active'] : $account['active'] === false;

			if( $active === true && $client['active'] === false ) {
				self::_refuse( $request, 'inactive' );
				return;
			}

			$changed = \Crm\Clients::switchAccount( $appData, $account['id'], $active );

			if( is_string( $changed ) === true ) {
				self::_refuse( $request, $changed );
				return;
			}

			self::$_made['crm-clients/account-active'] = $client['number'];

			\Nino\Http::ok( $request, [ 'account' => $changed ] );
		} );
	}

	/**
	 *	Change the name of an account
	 */
	public static function apiAccountName( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$target = self::_target( $appData, $request, $data, true, false );

			if( $target === null )
				return;

			[ $client, $account ] = $target;
			$changed = \Crm\Clients::renameAccount( $appData, $account['id'], \Nino\Admin\Admin::postString( $data, 'name' ) );

			if( is_string( $changed ) === true ) {
				self::_refuse( $request, $changed );
				return;
			}

			self::$_made['crm-clients/account-name'] = $client['number'];

			\Nino\Http::ok( $request, [ 'account' => $changed ] );
		} );
	}

	/**
	 *	Delete an account in Members, and its row. The documents of the client and the requests of Sign are not touched
	 */
	public static function apiAccountRemove( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$target = self::_target( $appData, $request, \Nino\Admin\Admin::postData(), false, false );

			if( $target === null )
				return;

			[ $client, $account ] = $target;

			if( \Crm\Clients::removeAccount( $appData, $client['id'], $account['id'] ) !== 'ok' ) {
				self::_refuse( $request, 'write' );
				return;
			}

			self::$_made['crm-clients/account-remove'] = $client['number'];

			\Nino\Http::ok( $request, [ 'removed' => true ] );
		} );
	}

}

<?php
declare(strict_types=1);
/**
 *	Crm\Clients\Admin		The API of the clients behind the surface's endpoint (Surface::actions()): the clients (list, card, add with
 *											the first account, change, deactivate), the accounts of their logins in Members - made with the client,
 *											invited, linked, sent a link again, switched off or on, renamed and deleted from the card, so that the
 *											panel Members need not be opened - and what the client sees in the portal. It calls Members' PHP API,
 *											never its files; the one permission of the CRM covers it. It is no panel of the workbench any more: the
 *											workbench has the panel CRM (Crm\Crm\Admin), which also holds the status of the module and the button
 *											that applies the install unit. It validates what it is posted and answers the failures a person can
 *											cause with codes (their sentences are in text/); the rules of the data are Crm\Clients', the storage
 *											Crm\Db's. A database failure is answered 500 with the code crm_clients_database.
 */
namespace Crm\Clients;

use Crm\Clients;

class Admin {

	public const string MANAGE_PERM = \Crm\Crm::MANAGE_PERM;

	// The id of an account of Members: 12 hex characters
	private const string ACCOUNT_ID = '/^[0-9a-f]{12}$/D';

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
			'crm-clients/portal'   => [ self::class, 'apiPortal' ],
		];
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
	 *	What the client sees in the portal, as far as the module can tell cheaply and truly: the documents released to it, the folders of Files
	 *	its group has with the files it may see in them, and - where Review is on - how many of those files can be commented on and how many
	 *	comments they hold. A feature that is off has its part empty, and the answer says which are on
	 */
	public static function apiPortal( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		self::_run( $request, static function() use ( &$appData, &$request ): void {

			$client = self::_client( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $client === null )
				return;

			$files = \Crm\Crm::featureOn( $appData, 'files' );
			$accounts = \Crm\Clients::accounts( $appData, $client['id'] );
			$review = $files === true && \Crm\Crm::featureOn( $appData, 'review' );
			$folders = [];
			$media = 0;
			$comments = 0;

			if( $files === true )
				foreach( \Nino\Modules\Files::folders( $appData, $client['group'] ) as $folder ) {

					// What the accounts of the client see, by the rule of Files::allowed() - which asks for the member who is signed in, so the owner cannot call it: a visible file of the group's
					// folder, and a file an account uploaded itself, which stays its own when the owner hides it
					$visible = array_values( array_filter( \Nino\Modules\Files::files( $appData, $folder['id'] ), static fn( array $file ): bool => ( $file['visible'] ?? false ) === true
						|| ( ( $file['by']['kind'] ?? null ) === 'member' && in_array( $file['by']['id'] ?? null, $accounts, true ) === true ) ) );

					if( $review === true )
						foreach( $visible as $file ) {

							if( \Nino\Modules\Review::kind( (string) $file['mime'] ) === '' )
								continue;

							$media++;
							$comments += array_sum( array_map( static fn( array $comment ): int => 1 + count( $comment['answers'] ), \Nino\Modules\Review::comments( $appData, $file['id'] ) ) );
						}

					$folders[] = [ 'id' => $folder['id'], 'name' => $folder['name'], 'upload' => $folder['upload'] === true, 'files' => count( $visible ) ];
				}

			\Nino\Http::ok( $request, [
				'documents' => \Crm\Documents::released( $appData, $client['id'] ),
				'features'  => [ 'files' => $files, 'review' => $review ],
				'folders'   => $folders,
				'review'    => [ 'media' => $media, 'comments' => $comments ],
				'records'   => array_map( static fn( array $record ): array => $record + [ 'document_number' => $record['document_id'] === null ? '' : (string) ( \Crm\Documents::get( $appData, $record['document_id'] )['number'] ?? '' ) ], \Crm\Records::of( $appData, $client['id'] ) ),
			] );
		} );
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
					\Nino\Http::fail( $request, 429, 'the mail cap of the kernel for one address is reached', 'crm_clients_ratelimit' );
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

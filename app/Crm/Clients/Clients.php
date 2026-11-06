<?php
declare(strict_types=1);
/**
 *	Crm\Clients		The clients of the business and the accounts of the Members feature that belong to them. A client
 *								has a number K-0001, counted inside a write transaction; its group in Members is that number
 *								as a slug (k-0001). All storage is Crm\Db; every statement is prepared.
 *
 *								A client carries privacy (Records::privacy(): whether the portal asks for a text, whether the client has agreed to it, when and by whom) from get() and list().
 *								A client is known by its title(): the company, else the name, else the address - the client's own or, for one made with nothing but the address of its first
 *								account, that one. Only the address is needed to make a client (add() with a first account whose address is valid); change() keeps asking for a company
 *								or a name. A client is ready for an invoice (invoiceReady()) when it has a company or a name and a street, a postal code, a city and a country; get() and list()
 *								carry ready and title.
 *
 *								A write answers the client as list() has it, or a string: 'empty' (neither company nor name - and, for add(), no valid address of a first account),
 *								'email:email', 'long:<field>' or 'format:<field>' (the field a value was refused for; format:business for a flag that is none),
 *								'unknown' (no such client). A database failure is a \PDOException the caller answers.
 *
 *								The accounts of a client are rows of client_accounts and accounts of Members. An account belongs
 *								to at most one client (a unique index) and is the client's while it is in the client's group in
 *								Members: one moved to another group there - in the Members panel - is no longer the client's,
 *								whatever the row says. An account Members does not have any more (deleted there) stays as a row
 *								that is gone, until the panel removes it. Without the feature Members nothing is known of the
 *								accounts: every row counts, and its state is members-off.
 */
namespace Crm;

class Clients {

	// The fields a client is edited by, with the longest value each takes
	public const array FIELDS = [
		'company' => 120, 'name' => 120, 'street' => 120, 'zip' => 12, 'city' => 80, 'country' => 2,
		'email' => 160, 'phone' => 40, 'vat_id' => 20, 'buyer_reference' => 100, 'notes' => 2000,
	];

	// What a field may hold besides its length, as a full-match pattern (an empty value always passes)
	private const array FORMATS = [
		'zip'     => '/^[A-Za-z0-9][A-Za-z0-9 -]*$/D',
		'country' => '/^[A-Z]{2}$/D',
		'phone'   => '/^[0-9+][0-9 +()\/.-]*$/D',
		'vat_id'  => '/^[A-Z]{2}[A-Za-z0-9]{2,18}$/D',
	];

	private const string TIME = 'Y-m-d\TH:i:s\Z';

	/**
	 *	The clients, by number
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										A list of clients, each with the number of its accounts and of the active ones
	 */
	public static function list( array &$appData ): array {

		$rows = Db::rows( $appData, 'SELECT c.* FROM clients c ORDER BY c.number' );
		$linked = [];

		foreach( Db::rows( $appData, 'SELECT client_id, member_id FROM client_accounts ORDER BY rowid' ) as $link )
			$linked[(int) $link['client_id']][] = $link['member_id'];

		$members = self::members( $appData );
		$privacy = Records::privacies( $appData, array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );

		return array_map( static fn( array $row ): array => self::shape( $row, $linked[(int) $row['id']] ?? [], $members ) + [ 'privacy' => $privacy[(int) $row['id']] ], $rows );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|null
	 */
	public static function get( array &$appData, int $id ): ?array {

		$row = Db::row( $appData, 'SELECT c.* FROM clients c WHERE c.id = :id', [ 'id' => $id ] );

		return $row === null ? null : self::shape( $row, self::linked( $appData, $id ), self::members( $appData ) ) + [ 'privacy' => Records::privacy( $appData, $id ) ];
	}

	/**
	 *	The client with a number
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$number				K-0001
	 *
	 *	@return 	array|null								As get() has it; null for a number nobody has
	 */
	public static function byNumber( array &$appData, string $number ): ?array {

		$row = Db::row( $appData, 'SELECT c.id FROM clients c WHERE c.number = :number', [ 'number' => $number ] );

		return $row === null ? null : self::get( $appData, (int) $row['id'] );
	}

	/**
	 *	How many clients are active
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	int
	 */
	public static function countActive( array &$appData ): int {

		return (int) ( Db::row( $appData, 'SELECT COUNT(*) AS n FROM clients WHERE active = 1' )['n'] ?? 0 );
	}

	/**
	 *	How a client is called: its company, else its name, else its address
	 *
	 *	@param		array 		$client				company, name and email - a frozen client of a document has them as well
	 *	@param		string		$account			The address of its first account, for a client that has none of its own
	 *
	 *	@return 	string										'' for a client with nothing to be called by
	 */
	public static function title( array $client, string $account = '' ): string {

		foreach( [ 'company', 'name', 'email' ] as $field )
			if( trim( (string) ( $client[$field] ?? '' ) ) !== '' )
				return trim( (string) $client[$field] );

		return trim( $account );
	}

	/**
	 *	Whether a client has what an offer or an invoice names it by: a company or a name, and a street, a postal code, a city and a country
	 *
	 *	@param		array 		$client				company, name, street, zip, city and country
	 *
	 *	@return 	bool
	 */
	public static function invoiceReady( array $client ): bool {

		if( trim( (string) ( $client['company'] ?? '' ) ) === '' && trim( (string) ( $client['name'] ?? '' ) ) === '' )
			return false;

		foreach( [ 'street', 'zip', 'city', 'country' ] as $field )
			if( trim( (string) ( $client[$field] ?? '' ) ) === '' )
				return false;

		return true;
	}

	/**
	 *	Add a client with the next number
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input				Any of FIELDS, and business (a boolean)
	 *	@param		array|null	$first				The first account of the client: email and name, and notify (false: the invitation is not mailed, its link is in the answer). Without an email the client is made alone
	 *
	 *	@return 	array|string							The client; or why not, see the class. With a first account the client has the key first: what invite()
	 *																		answered - the account and whether it was mailed - or the code it was refused with (Members' invalid, long,
	 *																		taken, write; database where the account could not be linked). The client is made all the same: it is
	 *																		in the books when its account is refused
	 */
	public static function add( array &$appData, array $input, ?array $first = null ): array|string {

		$address = self::address( (string) ( $first['email'] ?? '' ) );
		$values = self::clean( $input, $address !== '' );

		if( is_string( $values ) === true )
			return $values;

		// The address of the first account is the client's own where the master data has none: a client made with nothing but an address is known by it
		if( $values['email'] === '' )
			$values['email'] = $address;

		$pdo = Db::pdo( $appData );
		$now = gmdate( self::TIME );

		// IMMEDIATE takes the write lock before the number is read, so two requests cannot count the same one
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$max = (int) $pdo->query( 'SELECT COALESCE( MAX( CAST( SUBSTR( number, 3 ) AS INTEGER ) ), 0 ) FROM clients' )->fetchColumn();
			$number = 'K-'. sprintf( '%04d', $max + 1 );

			$statement = $pdo->prepare( 'INSERT INTO clients ( number, company, name, street, zip, city, country, email, phone, vat_id, buyer_reference, business, notes, active, created, updated )
				VALUES ( :number, :company, :name, :street, :zip, :city, :country, :email, :phone, :vat_id, :buyer_reference, :business, :notes, 1, :now, :now )' );
			$statement->execute( [ 'number' => $number, 'now' => $now ] + $values );
			$id = (int) $pdo->lastInsertId();

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		$client = (array) self::get( $appData, $id );
		$email = trim( (string) ( $first['email'] ?? '' ) );

		if( $email !== '' ) {

			try {
				$client['first'] = self::invite( $appData, $client, $email, (string) ( $first['name'] ?? '' ), ( $first['notify'] ?? true ) !== false );
			}
			catch( \PDOException $e ) {
				$client['first'] = 'database';
			}

			// The client as it is with its account: the counts are the account's too
			$client = (array) self::get( $appData, $id ) + [ 'first' => $client['first'] ];
		}

		return $client;
	}

	/**
	 *	Change the fields given; the others, the number and the active flag stay
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		array 		$input				Any of FIELDS, and business (a boolean)
	 *
	 *	@return 	array|string
	 */
	public static function change( array &$appData, int $id, array $input ): array|string {

		$current = self::get( $appData, $id );

		if( $current === null )
			return 'unknown';

		$values = self::clean( $input + $current );

		if( is_string( $values ) === true )
			return $values;

		Db::exec( $appData, 'UPDATE clients SET company = :company, name = :name, street = :street, zip = :zip, city = :city, country = :country,
			email = :email, phone = :phone, vat_id = :vat_id, buyer_reference = :buyer_reference, business = :business, notes = :notes, updated = :now WHERE id = :id', [ 'id' => $id, 'now' => gmdate( self::TIME ) ] + $values );

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Switch a client on or off. A client is never deleted: its documents belong to the books. Switching it off
	 *	switches its accounts off too - a client that is off must not reach the portal; switching it on does not
	 *	switch them on again, that is for the owner to do by account
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$active
	 *
	 *	@return 	array|string							The client with switched, how many accounts were switched off; or 'unknown' or 'write' (an account could not be written - the client stays as it was)
	 */
	public static function setActive( array &$appData, int $id, bool $active ): array|string {

		if( self::get( $appData, $id ) === null )
			return 'unknown';

		$switched = 0;

		if( $active === false ) {

			foreach( self::accountsDetailed( $appData, $id ) as $account ) {

				if( $account['gone'] === true || $account['active'] === false )
					continue;

				if( is_string( \Nino\Modules\Members::change( $appData, $account['id'], [ 'active' => false ] ) ) === true )
					return 'write';

				$switched++;
			}
		}

		Db::exec( $appData, 'UPDATE clients SET active = :active, updated = :now WHERE id = :id', [ 'active' => $active === true ? 1 : 0, 'now' => gmdate( self::TIME ), 'id' => $id ] );

		return (array) self::get( $appData, $id ) + [ 'switched' => $switched ];
	}

	/**
	 *	The group a client's accounts have in Members: its number as a slug
	 *
	 *	@param		string		$number				K-0001
	 *
	 *	@return 	string										k-0001
	 */
	public static function group( string $number ): string {

		return \Nino\Feature::slug( $number );
	}

	/**
	 *	Link an account of Members to a client, once. An account that another client holds is not linked: the unique
	 *	index of member_id keeps it with the first
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$memberId
	 *
	 *	@return 	bool											Whether the client holds the account now
	 */
	public static function link( array &$appData, int $id, string $memberId ): bool {

		Db::exec( $appData, 'INSERT OR IGNORE INTO client_accounts ( client_id, member_id ) VALUES ( :client, :member )', [ 'client' => $id, 'member' => $memberId ] );

		return (int) ( Db::row( $appData, 'SELECT client_id FROM client_accounts WHERE member_id = :member', [ 'member' => $memberId ] )['client_id'] ?? 0 ) === $id;
	}

	/**
	 *	The ids of the accounts of a client
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array										Member ids, in the order they were linked; an account that Members has in another group is not among them
	 */
	public static function accounts( array &$appData, int $id ): array {

		$number = Db::row( $appData, 'SELECT number FROM clients WHERE id = :id', [ 'id' => $id ] )['number'] ?? null;

		return $number === null ? [] : self::kept( (string) $number, self::linked( $appData, $id ), self::members( $appData ) );
	}

	/**
	 *	The accounts of a client as Members has them, with their state
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array										Each as row() has it, in the order they were linked
	 */
	public static function accountsDetailed( array &$appData, int $id ): array {

		$members = self::members( $appData );

		// Without the feature nothing is known of an account: the row says that, not that the account is gone
		if( Crm::featureOn( $appData, 'members' ) === false )
			return array_map( static fn( string $member ): array => [ 'id' => $member, 'gone' => true, 'state' => 'members-off' ], self::accounts( $appData, $id ) );

		return array_map( static fn( string $member ): array => self::row( $member, $members[$member] ?? null ), self::accounts( $appData, $id ) );
	}

	/**
	 *	One account of a client as accountsDetailed() has it
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$memberId
	 *
	 *	@return 	array|null								null where the client does not hold the account
	 */
	public static function accountOf( array &$appData, int $id, string $memberId ): ?array {

		foreach( self::accountsDetailed( $appData, $id ) as $account )
			if( $account['id'] === $memberId )
				return $account;

		return null;
	}

	/**
	 *	The accounts of Members that no client holds: the ones without a row, and the ones whose row is a client's they have left
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										Accounts as Members has them, sorted by name
	 */
	public static function unlinked( array &$appData ): array {

		$holders = self::holders( $appData );

		return array_values( array_filter( self::members( $appData ), static fn( array $account ): bool => ( $holders[$account['id']] ?? null ) !== $account['group'] ) );
	}

	/**
	 *	Link an account of Members that no client holds to a client, and move it into the client's group
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$memberId
	 *
	 *	@return 	array|string							The account as accountsDetailed() has it; or why not: 'unknown' (no such client), 'inactive' (the client is off), 'member' (Members has no such account), 'held' (a client holds it), 'write'
	 */
	public static function linkExisting( array &$appData, int $id, string $memberId ): array|string {

		$client = self::get( $appData, $id );

		if( $client === null )
			return 'unknown';

		if( $client['active'] === false )
			return 'inactive';

		$account = self::members( $appData )[$memberId] ?? null;

		if( $account === null )
			return 'member';

		$pdo = Db::pdo( $appData );
		$held = false;

		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$holder = $pdo->prepare( 'SELECT c.number FROM client_accounts a JOIN clients c ON c.id = a.client_id WHERE a.member_id = :member' );
			$holder->execute( [ 'member' => $memberId ] );
			$number = $holder->fetchColumn();

			// A row of a client the account has left is no holder: it goes, the account is free
			if( $number !== false && self::group( (string) $number ) === $account['group'] )
				$held = true;
			else {
				$pdo->prepare( 'DELETE FROM client_accounts WHERE member_id = :member' )->execute( [ 'member' => $memberId ] );
				$pdo->prepare( 'INSERT INTO client_accounts ( client_id, member_id ) VALUES ( :client, :member )' )->execute( [ 'client' => $id, 'member' => $memberId ] );
			}

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		if( $held === true )
			return 'held';

		if( $account['group'] !== $client['group'] ) {

			$moved = \Nino\Modules\Members::change( $appData, $memberId, [ 'group' => $client['group'] ] );

			// An account that could not be moved is not the client's: the row goes again
			if( is_string( $moved ) === true ) {
				Db::exec( $appData, 'DELETE FROM client_accounts WHERE member_id = :member', [ 'member' => $memberId ] );
				return $moved === 'unknown' ? 'member' : 'write';
			}

			$account = $moved;
		}

		return self::row( $memberId, $account );
	}

	/**
	 *	Make an account of Members in the group of a client, link it and mail the invitation - or, with $notify false, keep the invitation and mail
	 *	nobody: the answer carries its link, once
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client
	 *	@param		string		$email
	 *	@param		string		$name
	 *	@param		bool			$notify				Whether the invitation is mailed
	 *
	 *	@return 	array|string							account (as accountsDetailed() has it) and mailed - and link, with $notify false; or Members' reason: 'invalid', 'long', 'taken' or 'write'. An account that cannot be linked is taken back and the \PDOException goes on
	 */
	public static function invite( array &$appData, array $client, string $email, string $name, bool $notify = true ): array|string {

		// A link that is to be shown is charged to the cap of mails: where the cap is spent already nothing is made - no account to be asked for again, no 'taken' at the retry
		if( $notify === false && \Nino\Feature::spent( $appData, 'ratelimit', \Nino\Http::getClientIp( $appData ), (int) ( $appData['/nino/mail/cap'] ?? \Nino\AppData::DEFAULTS['/nino/mail/cap'] ) ) === true ) {
			$appData['./nino/mail/ratelimited'] = true;
			return 'ratelimit';
		}

		$account = \Nino\Modules\Members::add( $appData, $email, $name, $client['group'] );

		if( is_string( $account ) === true )
			return $account;

		// An account that cannot be linked is of no use to anybody and would make the retry a 'taken': it goes again
		try {
			self::link( $appData, $client['id'], $account['id'] );
		}
		catch( \PDOException $e ) {
			\Nino\Modules\Members::remove( $appData, $account['id'] );
			throw $e;
		}

		// The invited member lands on the portal
		$made = \Nino\Modules\Members::link( $appData, $account, 'invite', '/portal', true, $notify );

		// A link that was to be shown and could not be made is a refusal, never an empty link in an answer: the account is there and its link can be asked for again
		if( $notify === false && is_string( $made ) === false )
			return \Nino\Mail::rateLimited( $appData ) === true ? 'ratelimit' : 'link';

		return [ 'account' => self::row( $account['id'], $account ), 'mailed' => $made === true ] + ( $notify === false ? [ 'link' => (string) $made ] : [] );
	}

	/**
	 *	Send an account its link again: the invitation where it never signed in, a link to sign in where it did
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$account			As accountsDetailed() has it, and not gone
	 *	@param		bool			$notify				Whether the mail is sent; with false nobody is mailed and the link is the answer, once
	 *
	 *	@return 	bool|string								Whether the mail was delivered; with $notify false the link, or false where it could not be made
	 */
	public static function resend( array &$appData, array $account, bool $notify = true ): bool|string {

		return \Nino\Modules\Members::link( $appData, $account, $account['login'] === null ? 'invite' : 'login', '/portal', true, $notify );
	}

	/**
	 *	Switch an account of Members on or off
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$memberId
	 *	@param		bool			$active
	 *
	 *	@return 	array|string							The account as accountsDetailed() has it; or 'unknown' or 'write'
	 */
	public static function switchAccount( array &$appData, string $memberId, bool $active ): array|string {

		$changed = \Nino\Modules\Members::change( $appData, $memberId, [ 'active' => $active ] );

		return is_string( $changed ) === true ? $changed : self::row( $memberId, $changed );
	}

	/**
	 *	Change the name of an account of Members
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$memberId
	 *	@param		string		$name
	 *
	 *	@return 	array|string							The account as accountsDetailed() has it; or 'long', 'unknown' or 'write'
	 */
	public static function renameAccount( array &$appData, string $memberId, string $name ): array|string {

		$changed = \Nino\Modules\Members::change( $appData, $memberId, [ 'name' => $name ] );

		return is_string( $changed ) === true ? $changed : self::row( $memberId, $changed );
	}

	/**
	 *	Change the sign-in address of an account of Members, as the owner does: no code, no question to the member - the owner is the one who knows. The rules of
	 *	Members::change() hold: the links and codes of the account die with the old address, its other sessions end and the old address is told. The address of
	 *	the client itself is not touched here
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$memberId
	 *	@param		string		$email
	 *
	 *	@return 	array|string							The account as accountsDetailed() has it; or 'invalid', 'taken' (another account has the address), 'unknown' or 'write'
	 */
	public static function changeAccountEmail( array &$appData, string $memberId, string $email ): array|string {

		$changed = \Nino\Modules\Members::change( $appData, $memberId, [ 'email' => $email ] );

		return is_string( $changed ) === true ? $changed : self::row( $memberId, $changed );
	}

	/**
	 *	Delete an account: in Members, and its row. The documents of the client and the requests of Sign that name the account are not
	 *	touched. An account Members has not got any more is only a row: the row goes
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$memberId
	 *
	 *	@return 	string										'ok' or 'write' (Members could not write: nothing changed)
	 */
	public static function removeAccount( array &$appData, int $id, string $memberId ): string {

		if( \Nino\Modules\Members::remove( $appData, $memberId ) === 'write' )
			return 'write';

		Db::exec( $appData, 'DELETE FROM client_accounts WHERE client_id = :client AND member_id = :member', [ 'client' => $id, 'member' => $memberId ] );

		return 'ok';
	}

	/**
	 *	The client an account of Members belongs to: the one that holds it, while the account is in the client's group
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$memberId
	 *
	 *	@return 	array|null
	 */
	public static function ofMember( array &$appData, string $memberId ): ?array {

		$row = Db::row( $appData, 'SELECT c.* FROM clients c JOIN client_accounts a ON a.client_id = c.id WHERE a.member_id = :member', [ 'member' => $memberId ] );

		if( $row === null )
			return null;

		$members = self::members( $appData );

		return self::kept( (string) $row['number'], [ $memberId ], $members ) === [] ? null : self::shape( $row, [ $memberId ], $members );
	}

	/**
	 *	The accounts of Members by id; none where the feature is not active
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										id => account, sorted by name
	 */
	private static function members( array &$appData ): array {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return [];

		$accounts = [];

		foreach( \Nino\Modules\Members::accounts( $appData ) as $account )
			$accounts[$account['id']] = $account;

		return $accounts;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array										The ids of the rows of a client, in the order they were linked
	 */
	private static function linked( array &$appData, int $id ): array {

		return array_column( Db::rows( $appData, 'SELECT member_id FROM client_accounts WHERE client_id = :id ORDER BY rowid', [ 'id' => $id ] ), 'member_id' );
	}

	/**
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										member id => the group of the client whose row it is
	 */
	private static function holders( array &$appData ): array {

		$holders = [];

		foreach( Db::rows( $appData, 'SELECT a.member_id, c.number FROM client_accounts a JOIN clients c ON c.id = a.client_id' ) as $row )
			$holders[$row['member_id']] = self::group( (string) $row['number'] );

		return $holders;
	}

	/**
	 *	The ids of a client's rows that are still the client's
	 *
	 *	@param		string		$number
	 *	@param		array 		$ids
	 *	@param		array 		$members			As members() has them
	 *
	 *	@return 	array
	 */
	private static function kept( string $number, array $ids, array $members ): array {

		return array_values( array_filter( $ids, static fn( string $id ): bool => isset( $members[$id] ) === false || $members[$id]['group'] === self::group( $number ) ) );
	}

	/**
	 *	An account of a client
	 *
	 *	@param		string		$id
	 *	@param		array|null	$account			As Members has it; null for one it has not (any more)
	 *
	 *	@return 	array										id, gone and state - gone, members-off (the feature is not active), off, invited (never signed in) or signed - and for one that is not gone email, name, active and login
	 */
	private static function row( string $id, ?array $account ): array {

		if( $account === null )
			return [ 'id' => $id, 'gone' => true, 'state' => 'gone' ];

		return [ 'id' => $id, 'gone' => false, 'state' => $account['active'] === false ? 'off' : ( $account['login'] === null ? 'invited' : 'signed' ),
			'email' => $account['email'], 'name' => $account['name'], 'active' => $account['active'], 'login' => $account['login'] ];
	}

	/**
	 *	Trim and check the fields of a client
	 *
	 *	@param		array 		$input
	 *	@param		bool			$bare					Whether a client with neither company nor name is allowed (add() has a valid address of a first account to call it by)
	 *
	 *	@return 	array|string							name => value for every field; or why not
	 */
	private static function clean( array $input, bool $bare = false ): array|string {

		$values = [];

		foreach( self::FIELDS as $field => $max ) {

			$value = $input[$field] ?? '';

			if( is_string( $value ) === false )
				return 'format:'. $field;

			$value = trim( $value );

			if( $field === 'country' || $field === 'vat_id' )
				$value = strtoupper( str_replace( ' ', '', $value ) );

			if( $field === 'country' && $value === '' )
				$value = 'DE';

			if( mb_strlen( $value, 'UTF-8' ) > $max )
				return 'long:'. $field;

			if( $value !== '' && isset( self::FORMATS[$field] ) === true && preg_match( self::FORMATS[$field], $value ) !== 1 )
				return 'format:'. $field;

			if( $field === 'email' && $value !== '' && filter_var( $value, FILTER_VALIDATE_EMAIL ) === false )
				return 'email:email';

			$values[$field] = $value;
		}

		if( $bare === false && $values['company'] === '' && $values['name'] === '' )
			return 'empty';

		// Whether the client is a business, and so gets an electronic invoice: a flag the editor posts as a boolean; a text is none
		$business = $input['business'] ?? false;

		if( is_bool( $business ) === false && $business !== 0 && $business !== 1 )
			return 'format:business';

		$values['business'] = $business === true || $business === 1 ? 1 : 0;

		return $values;
	}

	/**
	 *	The address of a first account as the model takes it
	 *
	 *	@param		string		$email
	 *
	 *	@return 	string										The address trimmed; '' for one that is none or longer than a client's email takes
	 */
	private static function address( string $email ): string {

		$email = trim( $email );

		return $email !== '' && mb_strlen( $email, 'UTF-8' ) <= self::FIELDS['email'] && filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false ? $email : '';
	}

	/**
	 *	@param		array 		$row
	 *	@param		array 		$linked				The ids of the rows of the client
	 *	@param		array 		$members			As members() has them
	 *
	 *	@return 	array										With accounts, how many are the client's, and active_accounts, how many of them are on; title, how the client is called; and ready, whether an invoice can name it
	 */
	private static function shape( array $row, array $linked, array $members ): array {

		$ids = self::kept( (string) $row['number'], $linked, $members );

		$row['id'] = (int) $row['id'];
		$row['active'] = (int) $row['active'] === 1;
		$row['business'] = (int) $row['business'] === 1;
		$row['accounts'] = count( $ids );
		$row['active_accounts'] = count( array_filter( $ids, static fn( string $id ): bool => ( $members[$id]['active'] ?? false ) === true ) );
		$row['group'] = self::group( (string) $row['number'] );
		$row['ready'] = self::invoiceReady( $row );
		$row['title'] = self::title( $row, (string) ( $members[$ids[0] ?? '']['email'] ?? '' ) );

		return $row;
	}
}

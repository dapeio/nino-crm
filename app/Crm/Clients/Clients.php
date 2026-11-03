<?php
declare(strict_types=1);
/**
 *	Crm\Clients		The clients of the business and the accounts of the Members feature that belong to them. A client
 *								has a number K-0001, counted inside a write transaction; its group in Members is that number
 *								as a slug (k-0001). All storage is Crm\Db; every statement is prepared.
 *
 *								A write answers the client as list() has it, or a string: 'empty' (neither company nor name),
 *								'email:email', 'long:<field>' or 'format:<field>' (the field a value was refused for),
 *								'unknown' (no such client). A database failure is a \PDOException the caller answers.
 */
namespace Crm;

class Clients {

	// The fields a client is edited by, with the longest value each takes
	public const array FIELDS = [
		'company' => 120, 'name' => 120, 'street' => 120, 'zip' => 12, 'city' => 80, 'country' => 2,
		'email' => 160, 'phone' => 40, 'vat_id' => 20, 'notes' => 2000,
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
	 *	The clients, by number; a search matches number, company, name and email (LIKE, the wildcards of the
	 *	search are taken literally)
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$search
	 *
	 *	@return 	array										A list of clients, each with the number of its accounts
	 */
	public static function list( array &$appData, string $search = '' ): array {

		$where = '';
		$params = [];
		$search = trim( $search );

		if( $search !== '' ) {
			$where = ' WHERE c.number LIKE :s ESCAPE \'\\\' OR c.company LIKE :s ESCAPE \'\\\' OR c.name LIKE :s ESCAPE \'\\\' OR c.email LIKE :s ESCAPE \'\\\'';
			$params = [ 's' => '%'. addcslashes( $search, '%_\\' ). '%' ];
		}

		$rows = Db::rows( $appData, 'SELECT c.*, ( SELECT COUNT(*) FROM client_accounts a WHERE a.client_id = c.id ) AS accounts FROM clients c'. $where. ' ORDER BY c.number', $params );

		return array_map( [ self::class, 'shape' ], $rows );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|null
	 */
	public static function get( array &$appData, int $id ): ?array {

		$row = Db::row( $appData, 'SELECT c.*, ( SELECT COUNT(*) FROM client_accounts a WHERE a.client_id = c.id ) AS accounts FROM clients c WHERE c.id = :id', [ 'id' => $id ] );

		return $row === null ? null : self::shape( $row );
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
	 *	Add a client with the next number
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input				Any of FIELDS
	 *
	 *	@return 	array|string							The client; or why not, see the class
	 */
	public static function add( array &$appData, array $input ): array|string {

		$values = self::clean( $input );

		if( is_string( $values ) === true )
			return $values;

		$pdo = Db::pdo( $appData );
		$now = gmdate( self::TIME );

		// IMMEDIATE takes the write lock before the number is read, so two requests cannot count the same one
		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$max = (int) $pdo->query( 'SELECT COALESCE( MAX( CAST( SUBSTR( number, 3 ) AS INTEGER ) ), 0 ) FROM clients' )->fetchColumn();
			$number = 'K-'. sprintf( '%04d', $max + 1 );

			$statement = $pdo->prepare( 'INSERT INTO clients ( number, company, name, street, zip, city, country, email, phone, vat_id, notes, active, created, updated )
				VALUES ( :number, :company, :name, :street, :zip, :city, :country, :email, :phone, :vat_id, :notes, 1, :now, :now )' );
			$statement->execute( [ 'number' => $number, 'now' => $now ] + $values );
			$id = (int) $pdo->lastInsertId();

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Change the fields given; the others, the number and the active flag stay
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		array 		$input				Any of FIELDS
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
			email = :email, phone = :phone, vat_id = :vat_id, notes = :notes, updated = :now WHERE id = :id', [ 'id' => $id, 'now' => gmdate( self::TIME ) ] + $values );

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Switch a client on or off. A client is never deleted: its documents belong to the books
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$active
	 *
	 *	@return 	array|string
	 */
	public static function setActive( array &$appData, int $id, bool $active ): array|string {

		if( Db::exec( $appData, 'UPDATE clients SET active = :active, updated = :now WHERE id = :id', [ 'active' => $active === true ? 1 : 0, 'now' => gmdate( self::TIME ), 'id' => $id ] ) === 0 )
			return 'unknown';

		return (array) self::get( $appData, $id );
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
	 *	Link an account of Members to a client, once
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		string		$memberId
	 *
	 *	@return 	void
	 */
	public static function link( array &$appData, int $id, string $memberId ): void {

		Db::exec( $appData, 'INSERT OR IGNORE INTO client_accounts ( client_id, member_id ) VALUES ( :client, :member )', [ 'client' => $id, 'member' => $memberId ] );
	}

	/**
	 *	The ids of the accounts of a client
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array										Member ids, in the order they were linked
	 */
	public static function accounts( array &$appData, int $id ): array {

		return array_column( Db::rows( $appData, 'SELECT member_id FROM client_accounts WHERE client_id = :id ORDER BY rowid', [ 'id' => $id ] ), 'member_id' );
	}

	/**
	 *	The client an account of Members belongs to: an active one before an inactive one, the first linked first
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$memberId
	 *
	 *	@return 	array|null
	 */
	public static function ofMember( array &$appData, string $memberId ): ?array {

		$row = Db::row( $appData, 'SELECT c.*, 0 AS accounts FROM clients c JOIN client_accounts a ON a.client_id = c.id WHERE a.member_id = :member ORDER BY c.active DESC, c.id LIMIT 1', [ 'member' => $memberId ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	Trim and check the fields of a client
	 *
	 *	@param		array 		$input
	 *
	 *	@return 	array|string							name => value for every field; or why not
	 */
	private static function clean( array $input ): array|string {

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

		if( $values['company'] === '' && $values['name'] === '' )
			return 'empty';

		return $values;
	}

	/**
	 *	@param		array 		$row
	 *
	 *	@return 	array
	 */
	private static function shape( array $row ): array {

		$row['id'] = (int) $row['id'];
		$row['active'] = (int) $row['active'] === 1;
		$row['accounts'] = (int) $row['accounts'];
		$row['group'] = self::group( (string) $row['number'] );

		return $row;
	}
}

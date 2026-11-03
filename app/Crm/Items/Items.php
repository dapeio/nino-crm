<?php
declare(strict_types=1);
/**
 *	Crm\Items		The catalogue of what the owner sells: a title, a description, a unit, a net price in cents and a VAT rate.
 *							An item is a template for a line: a line copies title, description, unit, price and rate when it is
 *							added and never follows the item afterwards. All storage is Crm\Db; every statement is prepared.
 *
 *							A write answers the item as get() has it, or a string: 'unknown' (no such item), 'empty:title',
 *							'long:<field>', 'format:<field>' (a value that is no text), 'amount:price' or 'rate:vat_rate' (the
 *							field a value was refused for). A database failure is a \PDOException the caller answers.
 */
namespace Crm;

class Items {

	// The texts of an item with the longest value each takes
	public const array FIELDS = [ 'title' => 200, 'description' => 2000, 'unit' => 20 ];

	private const string TIME = 'Y-m-d\TH:i:s\Z';

	/**
	 *	The items in their order
	 *
	 *	@param		array 		&$appData
	 *	@param		bool			$activeOnly
	 *	@param		string		$search				Matches title and description (LIKE, the wildcards taken literally)
	 *
	 *	@return 	array
	 */
	public static function list( array &$appData, bool $activeOnly = false, string $search = '' ): array {

		$where = [];
		$params = [];
		$search = trim( $search );

		if( $activeOnly === true )
			$where[] = 'active = 1';

		if( $search !== '' ) {
			$where[] = '( title LIKE :s ESCAPE \'\\\' OR description LIKE :s ESCAPE \'\\\' )';
			$params['s'] = '%'. addcslashes( $search, '%_\\' ). '%';
		}

		$rows = Db::rows( $appData, 'SELECT * FROM items'. ( $where === [] ? '' : ' WHERE '. implode( ' AND ', $where ) ). ' ORDER BY sort, id', $params );

		return array_map( [ self::class, 'shape' ], $rows );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *
	 *	@return 	array|null
	 */
	public static function get( array &$appData, int $id ): ?array {

		$row = Db::row( $appData, 'SELECT * FROM items WHERE id = :id', [ 'id' => $id ] );

		return $row === null ? null : self::shape( $row );
	}

	/**
	 *	Add an item at the end of the order
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input				title, description, unit, price (text or cents), vat_rate
	 *
	 *	@return 	array|string
	 */
	public static function add( array &$appData, array $input ): array|string {

		$values = self::clean( $appData, $input, Settings::get( $appData, 'vat_mode' ) );

		if( is_string( $values ) === true )
			return $values;

		$now = gmdate( self::TIME );

		Db::exec( $appData, 'INSERT INTO items ( title, description, unit, price_net, vat_rate, active, sort, created, updated )
			VALUES ( :title, :description, :unit, :price_net, :vat_rate, 1, ( SELECT COALESCE( MAX( sort ), 0 ) + 1 FROM items ), :now, :now )', [ 'now' => $now ] + $values );

		return (array) self::get( $appData, (int) Db::pdo( $appData )->lastInsertId() );
	}

	/**
	 *	Change the fields given; the others, the order and the active flag stay
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		array 		$input
	 *
	 *	@return 	array|string
	 */
	public static function change( array &$appData, int $id, array $input ): array|string {

		$current = self::get( $appData, $id );

		if( $current === null )
			return 'unknown';

		$values = self::clean( $appData, $input + [ 'price' => $current['price_net'] ] + $current, Settings::get( $appData, 'vat_mode' ) );

		if( is_string( $values ) === true )
			return $values;

		Db::exec( $appData, 'UPDATE items SET title = :title, description = :description, unit = :unit, price_net = :price_net, vat_rate = :vat_rate, updated = :now WHERE id = :id', [ 'id' => $id, 'now' => gmdate( self::TIME ) ] + $values );

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Switch an item on or off. An item is never deleted from the catalogue: it is out of the search of the editor
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$id
	 *	@param		bool			$active
	 *
	 *	@return 	array|string
	 */
	public static function setActive( array &$appData, int $id, bool $active ): array|string {

		if( Db::exec( $appData, 'UPDATE items SET active = :active, updated = :now WHERE id = :id', [ 'active' => $active === true ? 1 : 0, 'now' => gmdate( self::TIME ), 'id' => $id ] ) === 0 )
			return 'unknown';

		return (array) self::get( $appData, $id );
	}

	/**
	 *	Put the items in an order: the ids given first, in their order, then the others as they were
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$ids					Item ids, each once
	 *
	 *	@return 	true|string								'unknown' for an id nobody has or one twice
	 */
	public static function order( array &$appData, array $ids ): true|string {

		$known = array_column( Db::rows( $appData, 'SELECT id FROM items ORDER BY sort, id' ), 'id' );
		$known = array_map( 'intval', $known );
		$ids = array_values( $ids );

		if( count( array_unique( $ids ) ) !== count( $ids ) || array_filter( $ids, static fn( mixed $id ): bool => is_int( $id ) === false || in_array( $id, $known, true ) === false ) !== [] )
			return 'unknown';

		$sequence = array_merge( $ids, array_values( array_diff( $known, $ids ) ) );
		$pdo = Db::pdo( $appData );

		$pdo->exec( 'BEGIN IMMEDIATE' );

		try {
			$statement = $pdo->prepare( 'UPDATE items SET sort = :sort WHERE id = :id' );

			foreach( $sequence as $position => $id )
				$statement->execute( [ 'sort' => $position + 1, 'id' => $id ] );

			$pdo->exec( 'COMMIT' );
		}
		catch( \Throwable $e ) {
			$pdo->exec( 'ROLLBACK' );
			throw $e;
		}

		return true;
	}

	/**
	 *	The fields of an item or of a line: trimmed and checked
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$input				title, description, unit, price (text, or cents as an int), vat_rate
	 *	@param		string		$vatMode			Whose rates the rate is held to
	 *
	 *	@return 	array|string							title, description, unit, price_net, vat_rate; or why not
	 */
	public static function clean( array &$appData, array $input, string $vatMode ): array|string {

		$values = [];

		foreach( self::FIELDS as $field => $max ) {

			$value = $input[$field] ?? '';

			if( is_string( $value ) === false )
				return 'format:'. $field;

			$value = trim( $value );

			if( mb_strlen( $value, 'UTF-8' ) > $max )
				return 'long:'. $field;

			$values[$field] = $value;
		}

		if( $values['title'] === '' )
			return 'empty:title';

		$price = self::amount( $input['price'] ?? 0, 'price' );

		if( $price === null )
			return 'amount:price';

		$rate = $input['vat_rate'] ?? 0;

		if( is_int( $rate ) === false || Settings::rateAllowed( $appData, $rate, $vatMode ) === false )
			return 'rate:vat_rate';

		return $values + [ 'price_net' => $price, 'vat_rate' => $rate ];
	}

	/**
	 *	A price or a quantity as a whole number: a text is parsed, an int is taken as the smallest part
	 *
	 *	@param		mixed			$value
	 *	@param		string		$kind					price or qty
	 *
	 *	@return 	int|null									Null for a value that is none or out of range
	 */
	public static function amount( mixed $value, string $kind ): ?int {

		if( is_string( $value ) === true )
			return $kind === 'qty' ? Documents\Money::parseQty( trim( $value ) ) : Documents\Money::parse( trim( $value ) );

		if( is_int( $value ) === false )
			return null;

		return abs( $value ) <= ( $kind === 'qty' ? Documents\Money::MAX_QTY : Documents\Money::MAX_PRICE ) ? $value : null;
	}

	/**
	 *	@param		array 		$row
	 *
	 *	@return 	array
	 */
	private static function shape( array $row ): array {

		$row['id'] = (int) $row['id'];
		$row['price_net'] = (int) $row['price_net'];
		$row['vat_rate'] = (int) $row['vat_rate'];
		$row['active'] = (int) $row['active'] === 1;
		$row['sort'] = (int) $row['sort'];

		return $row;
	}
}

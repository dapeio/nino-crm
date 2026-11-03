<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Lines		The words and numbers of a line: trimmed and checked. A line is made from an item of the catalogue
 *												(\Crm\Positionen) or typed, and what it holds is its own from then on.
 *
 *												clean() answers the values or a string: 'long:<field>', 'format:<field>' (a value that is no text),
 *												'empty:title', 'amount:price' or 'rate:vat_rate' (the field a value was refused for).
 */
namespace Crm\Documents;

use Crm\Settings;

class Lines {

	// The texts of a line with the longest value each takes
	public const array FIELDS = [ 'title' => 200, 'description' => 2000, 'unit' => 20 ];

	/**
	 *	The fields of a line: trimmed and checked
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
			return $kind === 'qty' ? Money::parseQty( trim( $value ) ) : Money::parse( trim( $value ) );

		if( is_int( $value ) === false )
			return null;

		return abs( $value ) <= ( $kind === 'qty' ? Money::MAX_QTY : Money::MAX_PRICE ) ? $value : null;
	}
}

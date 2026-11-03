<?php
declare(strict_types=1);
/**
 *	Crm\Settings		The settings of the module in the table settings, with their defaults and the rules of every key.
 *									get() answers the value a key has, set() writes one that passes its rule. A rule that fails is a
 *									string: 'unknown' (no such key), 'value:<key>' (the value is none of the key's) or 'format:<key>'
 *									(a number format that does not hold the parts a format needs). A database failure is a
 *									\PDOException the caller answers.
 */
namespace Crm;

class Settings {

	// The keys, with what the migration writes where they are missing
	public const array DEFAULTS = [
		'vat_mode'       => 'small_business',
		'vat_rates'      => '1900,700,0',
		'payment_days'   => '14',
		'offer_days'     => '30',
		'currency'       => '€',
		'number_offer'   => 'A-{YYYY}-{NNNN}',
		'number_invoice' => 'R-{YYYY}-{NNNN}',
		'number_credit'  => 'G-{YYYY}-{NNNN}',
	];

	public const array VAT_MODES = [ 'small_business', 'regular' ];

	// The number format of a category
	public const array FORMATS = [ 'offer' => 'number_offer', 'invoice' => 'number_invoice', 'credit' => 'number_credit' ];

	public const int MAX_DAYS = 365;
	public const int MAX_RATE = 10000;
	public const int MAX_FORMAT = 40;
	public const int MAX_WIDTH = 9;

	/**
	 *	All settings, a key for every default
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										key => value, as text
	 */
	public static function all( array &$appData ): array {

		$stored = array_column( Db::rows( $appData, 'SELECT key, value FROM settings' ), 'value', 'key' );
		$all = [];

		foreach( self::DEFAULTS as $key => $default )
			$all[$key] = isset( $stored[$key] ) === true ? (string) $stored[$key] : $default;

		return $all;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		string		$key					One of DEFAULTS
	 *
	 *	@return 	string
	 */
	public static function get( array &$appData, string $key ): string {

		if( isset( self::DEFAULTS[$key] ) === false )
			throw new \InvalidArgumentException( 'There is no setting "'. $key. '"' );

		return self::all( $appData )[$key];
	}

	/**
	 *	The rates the regular mode offers
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array										Hundredths of a percent, in the order the setting lists them
	 */
	public static function rates( array &$appData ): array {

		return self::parseRates( self::get( $appData, 'vat_rates' ) ) ?? [];
	}

	/**
	 *	Whether a line may carry a rate: 0 in small_business, one of the rates of the project in regular
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$rate
	 *	@param		string		$vatMode
	 *
	 *	@return 	bool
	 */
	public static function rateAllowed( array &$appData, int $rate, string $vatMode ): bool {

		return $vatMode === 'small_business' ? $rate === 0 : in_array( $rate, self::rates( $appData ), true );
	}

	/**
	 *	Write a setting that passes the rule of its key
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$key
	 *	@param		mixed			$value
	 *
	 *	@return 	true|string								Why not, see the class
	 */
	public static function set( array &$appData, string $key, mixed $value ): true|string {

		if( isset( self::DEFAULTS[$key] ) === false )
			return 'unknown';

		$clean = self::clean( $key, $value );

		if( is_array( $clean ) === true )
			return $clean[0];

		Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( :k, :v ) ON CONFLICT( key ) DO UPDATE SET value = excluded.value', [ 'k' => $key, 'v' => $clean ] );

		return true;
	}

	/**
	 *	The value a key takes, or why not
	 *
	 *	@param		string		$key
	 *	@param		mixed			$value
	 *
	 *	@return 	string|array							The text to store; or [ code ] for a value that fails
	 */
	public static function clean( string $key, mixed $value ): string|array {

		if( is_string( $value ) === false && is_int( $value ) === false )
			return [ 'value:'. $key ];

		$value = trim( (string) $value );

		switch( $key ) {

			case 'vat_mode':
				return in_array( $value, self::VAT_MODES, true ) === true ? $value : [ 'value:'. $key ];

			case 'vat_rates':
				$rates = self::parseRates( $value );

				return $rates === null ? [ 'value:'. $key ] : implode( ',', $rates );

			// The rates as a person writes them, in percent: the form of the screen, stored as the hundredths vat_rates holds
			case 'vat_rates_percent':
				$rates = self::parsePercent( $value );

				return $rates === null ? [ 'value:'. $key ] : implode( ',', $rates );

			case 'payment_days':
			case 'offer_days':
				return preg_match( '/^(0|[1-9][0-9]{0,2})$/D', $value ) === 1 && (int) $value <= self::MAX_DAYS ? $value : [ 'value:'. $key ];

			case 'currency':
				return $value !== '' && mb_strlen( $value, 'UTF-8' ) <= 5 && preg_match( '/^[\p{L}\p{Sc}.]+$/Du', $value ) === 1 ? $value : [ 'value:'. $key ];
		}

		return self::validFormat( $value ) === true ? $value : [ 'format:'. $key ];
	}

	/**
	 *	The rates of a list: whole hundredths of a percent, from 0 to 10000, none twice
	 *
	 *	@param		string		$list					1900,700,0
	 *
	 *	@return 	array|null								Null for a list that is none
	 */
	public static function parseRates( string $list ): ?array {

		$rates = [];

		foreach( explode( ',', $list ) as $part ) {

			$part = trim( $part );

			if( preg_match( '/^(0|[1-9][0-9]{0,4})$/D', $part ) !== 1 || (int) $part > self::MAX_RATE || in_array( (int) $part, $rates, true ) === true )
				return null;

			$rates[] = (int) $part;
		}

		return $rates;
	}

	/**
	 *	The rates of a list written in percent: apart by a semicolon or white space, each a number of up to three digits with a decimal
	 *	comma or point and up to two decimals - 19; 7; 5,5; 0. Anything else is refused as a whole, 19,7,0 among it (a comma is the
	 *	decimal mark, never a separator)
	 *
	 *	@param		string		$text
	 *
	 *	@return 	array|null								Hundredths of a percent, as parseRates() takes them; null for a list that is none
	 */
	public static function parsePercent( string $text ): ?array {

		$hundredths = [];

		foreach( (array) preg_split( '/[;\s]+/', trim( $text ) ) as $part ) {

			if( preg_match( '/^(\d{1,3})(?:[.,](\d{1,2}))?$/D', (string) $part, $found ) !== 1 )
				return null;

			$hundredths[] = (string) ( (int) $found[1] * 100 + (int) str_pad( $found[2] ?? '', 2, '0' ) );
		}

		return self::parseRates( implode( ',', $hundredths ) );
	}

	/**
	 *	Whether a number format is one: {YYYY} or {YY} at most once, {N...} exactly once, and otherwise letters, digits,
	 *	hyphen, underscore and dot - a slash would be a path
	 *
	 *	@param		string		$format
	 *
	 *	@return 	bool
	 */
	public static function validFormat( string $format ): bool {

		if( strlen( $format ) > self::MAX_FORMAT )
			return false;

		$years = preg_match_all( '/\{(YYYY|YY)\}/', $format );
		$counters = preg_match_all( '/\{(N+)\}/', $format, $found );

		if( $years > 1 || $counters !== 1 || strlen( $found[1][0] ) > self::MAX_WIDTH )
			return false;

		return preg_match( '/^[A-Za-z0-9._-]*$/D', (string) preg_replace( '/\{(YYYY|YY|N+)\}/', '', $format ) ) === 1;
	}
}

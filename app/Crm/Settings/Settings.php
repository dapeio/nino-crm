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
		'tax_id'         => '',
		'bank_name'      => '',
		'iban'           => '',
		'bic'            => '',
		'paypal'         => '',
		'seller_name'    => '',
		'seller_street'  => '',
		'seller_zip'     => '',
		'seller_city'    => '',
		'seller_country' => 'DE',
		'seller_contact' => '',
		'seller_phone'   => '',
		'seller_email'   => '',
		'vat_id'         => '',
		'vat_exempt_text' => 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG',
		'privacy_text'   => '',
		'privacy_changed' => '',
		'portal_message' => '',
		'portal_message_at' => '',
		'consent_social' => '',
		'consent_archive' => '',
		'notify_files'   => '1',
	];

	// The keys the module keeps itself: a post of the surface never writes them. privacy_changed is the day privacy_text last changed, portal_message_at the time portal_message last changed
	public const array READ_ONLY = [ 'privacy_changed', 'portal_message_at' ];

	public const array VAT_MODES = [ 'small_business', 'regular' ];

	// The number format of a category
	public const array FORMATS = [ 'offer' => 'number_offer', 'invoice' => 'number_invoice', 'credit' => 'number_credit' ];

	public const int MAX_DAYS = 365;
	public const int MAX_RATE = 10000;
	public const int MAX_FORMAT = 40;
	public const int MAX_WIDTH = 9;
	public const int MAX_TAX = 40;
	public const int MAX_BANK = 80;
	public const int MAX_PRIVACY = 20000;
	public const int MAX_MESSAGE = 2000;

	// The seller's particulars an electronic invoice names (the block Rechnungsaussteller): the longest value each takes. The text of the
	// exemption is used under the VAT mode small_business only
	public const array SELLER_TEXTS = [ 'seller_name' => 120, 'seller_street' => 120, 'seller_zip' => 12, 'seller_city' => 80, 'seller_contact' => 120, 'vat_exempt_text' => 200 ];

	// The pattern of the seller's VAT id: the one of the clients'
	public const string VAT_ID = '/^[A-Z]{2}[A-Za-z0-9]{2,18}$/D';

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

	// The day a run of the setup went through (the screen Einrichtung): in the table, and no setting of the screen Einstellungen
	public const string SETUP_KEY = 'setup_done';

	/**
	 *	The day the setup last went through, '' where it never did. A read: where there is no database yet, there was no setup
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string
	 */
	public static function setupDone( array &$appData ): string {

		if( is_file( \Nino\Filesystem::path( $appData, Db::FILE ) ) === false )
			return '';

		try {
			return (string) ( Db::row( $appData, 'SELECT value FROM settings WHERE key = :k', [ 'k' => self::SETUP_KEY ] )['value'] ?? '' );
		}
		catch( \PDOException $e ) {
			return '';
		}
	}

	/**
	 *	Write down that the setup went through, today
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	public static function markSetupDone( array &$appData ): void {

		Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( :k, :v ) ON CONFLICT( key ) DO UPDATE SET value = excluded.value', [ 'k' => self::SETUP_KEY, 'v' => gmdate( 'Y-m-d' ) ] );
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

		// A text of the Datenschutz that is different from the one before is a new text: the day is kept beside it, and the customers are asked again
		$changed = $key === 'privacy_text' && self::get( $appData, 'privacy_text' ) !== $clean;

		$messaged = $key === 'portal_message' && self::get( $appData, 'portal_message' ) !== $clean;

		Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( :k, :v ) ON CONFLICT( key ) DO UPDATE SET value = excluded.value', [ 'k' => $key, 'v' => $clean ] );

		// A message that is different from the one before is written now; none is written at no time
		if( $messaged === true )
			Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( \'portal_message_at\', :v ) ON CONFLICT( key ) DO UPDATE SET value = excluded.value', [ 'v' => $clean === '' ? '' : gmdate( 'Y-m-d\TH:i:s\Z' ) ] );

		if( $changed === true )
			Db::exec( $appData, 'INSERT INTO settings ( key, value ) VALUES ( \'privacy_changed\', :v ) ON CONFLICT( key ) DO UPDATE SET value = excluded.value', [ 'v' => date( 'Y-m-d' ) ] );

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

			// The text of the Datenschutz is the one multi-line setting: line breaks are kept as \n, nothing else that is a control character
			case 'privacy_text':
				$value = trim( str_replace( [ "\r\n", "\r" ], "\n", $value ) );

				return mb_strlen( $value, 'UTF-8' ) <= self::MAX_PRIVACY && preg_match( '/[^\P{C}\n\t]/u', $value ) === 0 ? $value : [ 'value:'. $key ];

			// The message every client reads in the portal: text with line breaks, as long as a client's own - and the sentence beside the box of a consent, of the same kind: the box does not exist while it is empty
			case 'portal_message':
			case 'consent_social':
			case 'consent_archive':
				$value = trim( str_replace( [ "\r\n", "\r" ], "\n", $value ) );

				return mb_strlen( $value, 'UTF-8' ) <= self::MAX_MESSAGE && preg_match( '/[^\P{Cc}\n\t]|[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value ) === 0 ? $value : [ 'value:'. $key ];

			// Whether the customers are mailed for the files the owner uploads into a project: the box of the upload starts at it
			case 'notify_files':
				return in_array( $value, [ '0', '1' ], true ) === true ? $value : [ 'value:'. $key ];

			// Set by the module when the text changes, never by a post
			case 'privacy_changed':
			case 'portal_message_at':
				return [ 'value:'. $key ];

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

			// The particulars of an invoice: each may stay empty, and what is written is a plausible one
			case 'tax_id':
				return mb_strlen( $value, 'UTF-8' ) <= self::MAX_TAX && preg_match( '/^[\p{L}\p{N} .\/-]*$/Du', $value ) === 1 ? $value : [ 'value:'. $key ];

			case 'seller_name':
			case 'seller_street':
			case 'seller_city':
			case 'seller_contact':
			case 'vat_exempt_text':
				return mb_strlen( $value, 'UTF-8' ) <= self::SELLER_TEXTS[$key] && preg_match( '/^[^\p{C}]*$/Du', $value ) === 1 ? $value : [ 'value:'. $key ];

			case 'seller_zip':
				return mb_strlen( $value, 'UTF-8' ) <= self::SELLER_TEXTS[$key] && ( $value === '' || preg_match( '/^[A-Za-z0-9][A-Za-z0-9 -]*$/D', $value ) === 1 ) ? $value : [ 'value:'. $key ];

			// The country is a code of two capitals, as the clients' are; the seller's phone is written as theirs
			case 'seller_country':
				$value = strtoupper( $value );

				return $value === '' || preg_match( '/^[A-Z]{2}$/D', $value ) === 1 ? $value : [ 'value:'. $key ];

			case 'seller_phone':
				return mb_strlen( $value, 'UTF-8' ) <= 40 && ( $value === '' || preg_match( '/^[0-9+][0-9 +()\/.-]*$/D', $value ) === 1 ) ? $value : [ 'value:'. $key ];

			case 'seller_email':
				return mb_strlen( $value, 'UTF-8' ) <= 160 && ( $value === '' || filter_var( $value, FILTER_VALIDATE_EMAIL ) !== false ) ? $value : [ 'value:'. $key ];

			case 'vat_id':
				$value = strtoupper( str_replace( ' ', '', $value ) );

				return $value === '' || preg_match( self::VAT_ID, $value ) === 1 ? $value : [ 'value:'. $key ];

			case 'bank_name':
				return mb_strlen( $value, 'UTF-8' ) <= self::MAX_BANK && preg_match( '/^[^\p{C}]*$/Du', $value ) === 1 ? $value : [ 'value:'. $key ];

			case 'iban':
				$iban = self::iban( $value );

				return $iban === null ? [ 'value:'. $key ] : $iban;

			// The name of PayPal.me, nothing else: the address https://paypal.me/<name> or https://www.paypal.com/paypalme/<name> is cut down to it, and the link of the portal is made of the name
			case 'paypal':
				$name = preg_match( '#^https://(?:paypal\.me|www\.paypal\.com/paypalme)/([^/?\#]*)/?$#Di', $value, $found ) === 1 ? $found[1] : $value;

				return $value === '' || preg_match( '/^[A-Za-z0-9]{1,20}$/D', $name ) === 1 ? $name : [ 'value:'. $key ];

			case 'bic':
				$bic = strtoupper( (string) preg_replace( '/\s+/', '', $value ) );

				return $bic === '' || preg_match( '/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D', $bic ) === 1 ? $bic : [ 'value:'. $key ];
		}

		return self::validFormat( $value ) === true ? $value : [ 'format:'. $key ];
	}

	/**
	 *	An IBAN as it is written on an invoice: capitals in groups of four. The check is the one every bank makes - the country and the check
	 *	digits, 15 to 34 characters, and the remainder 1 of the number the letters make, divided by 97 - not a list of the countries
	 *
	 *	@param		string		$text					As typed: blanks anywhere
	 *
	 *	@return 	string|null								'' for nothing; null for a text that is no IBAN
	 */
	public static function iban( string $text ): ?string {

		$iban = strtoupper( (string) preg_replace( '/\s+/', '', $text ) );

		if( $iban === '' )
			return '';

		if( preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $iban ) !== 1 )
			return null;

		$digits = '';

		foreach( str_split( substr( $iban, 4 ). substr( $iban, 0, 4 ) ) as $char )
			$digits .= ctype_digit( $char ) === true ? $char : (string) ( ord( $char ) - 55 );

		$rest = 0;

		foreach( str_split( $digits ) as $digit )
			$rest = ( $rest * 10 + (int) $digit ) % 97;

		return $rest === 1 ? trim( chunk_split( $iban, 4, ' ' ) ) : null;
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

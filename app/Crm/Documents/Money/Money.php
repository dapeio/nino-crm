<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Money		Cents, thousandths and hundredths of a percent: the arithmetic of a document, pure and in integers.
 *												A price and an amount are cents, a quantity is thousandths (1500 = 1,5), a rate is hundredths
 *												of a percent (1900 = 19 %). Every rounding is half away from zero, in integer arithmetic. The
 *												script of the panel Belege mirrors these functions; both suites run the cases of
 *												tests/money-cases.json.
 */
namespace Crm\Documents;

class Money {

	// The largest price a line or an item takes, in cents; the largest quantity, in thousandths; the largest amount of a line and of a document
	public const int MAX_PRICE = 1000000000;
	public const int MAX_QTY = 10000000;
	public const int MAX_LINE = 100000000000;
	public const int MAX_TOTAL = 1000000000000;

	// The non-breaking space between a number and its currency
	public const string NBSP = "\u{00A0}";

	/**
	 *	qty x price / 1000, rounded half away from zero
	 *
	 *	@param		int				$qty					Thousandths
	 *	@param		int				$price				Cents
	 *
	 *	@return 	int												Cents
	 */
	public static function lineAmount( int $qty, int $price ): int {

		return self::divide( $qty * $price, 1000 );
	}

	/**
	 *	net x rate / 10000, rounded half away from zero
	 *
	 *	@param		int				$net					Cents
	 *	@param		int				$rate					Hundredths of a percent
	 *
	 *	@return 	int												Cents
	 */
	public static function vat( int $net, int $rate ): int {

		return self::divide( $net * $rate, 10000 );
	}

	/**
	 *	The totals of the lines of a document: net per rate, the VAT of each rate's net, and the sums. In small_business
	 *	and in a reverse charge every rate counts as 0, whatever a line says
	 *
	 *	@param		array 		$lines				Each with qty, price_net and vat_rate
	 *	@param		string		$vatMode			small_business or regular
	 *	@param		bool			$reverse
	 *
	 *	@return 	array											net, vat, gross, rates (rate => [ net, vat ], the highest rate first), amounts (the amount of each line)
	 */
	public static function totals( array $lines, string $vatMode, bool $reverse = false ): array {

		$zero = $vatMode === 'small_business' || $reverse === true;
		$rates = [];
		$amounts = [];

		foreach( $lines as $line ) {

			$amount = self::lineAmount( (int) $line['qty'], (int) $line['price_net'] );
			$rate = $zero === true ? 0 : (int) $line['vat_rate'];
			$amounts[] = $amount;
			$rates[$rate] = ( $rates[$rate] ?? 0 ) + $amount;
		}

		krsort( $rates );

		$net = 0;
		$vat = 0;
		$perRate = [];

		foreach( $rates as $rate => $sum ) {

			$tax = $zero === true ? 0 : self::vat( $sum, $rate );
			$perRate[$rate] = [ 'net' => $sum, 'vat' => $tax ];
			$net += $sum;
			$vat += $tax;
		}

		return [ 'net' => $net, 'vat' => $vat, 'gross' => $net + $vat, 'rates' => $perRate, 'amounts' => $amounts ];
	}

	/**
	 *	A number written by a person as cents: 1.234,56 and 1234,56 and 1234.56 and -12,5. The dots of the German form
	 *	group three digits and need the comma behind them; a dot alone is a decimal point
	 *
	 *	@param		string		$text
	 *
	 *	@return 	int|null									Cents; null for any other text and for one past MAX_PRICE
	 */
	public static function parse( string $text ): ?int {

		$value = self::parseNumber( $text, 2 );

		return $value === null || abs( $value ) > self::MAX_PRICE ? null : $value;
	}

	/**
	 *	A quantity written by a person as thousandths, the same forms with up to three decimals - but 1.000 and 1.500 are refused, being a thousand or one
	 *
	 *	@param		string		$text
	 *
	 *	@return 	int|null									Thousandths; null for any other text and for one past MAX_QTY
	 */
	public static function parseQty( string $text ): ?int {

		$value = self::parseNumber( $text, 3 );

		return $value === null || abs( $value ) > self::MAX_QTY ? null : $value;
	}

	/**
	 *	A number with up to $decimals decimals as a whole number of its smallest part
	 *
	 *	@param		string		$text
	 *	@param		int				$decimals
	 *
	 *	@return 	int|null
	 */
	public static function parseNumber( string $text, int $decimals ): ?int {

		$german = '/^(-?)(\d{1,3}(?:\.\d{3})+),(\d{1,'. $decimals. '})$/D';
		$plain = '/^(-?)(\d{1,15})(?:[.,](\d{1,'. $decimals. '}))?$/D';

		// 1.000 is a thousand where it is German and one where it is English: a number of one to three digits, a dot and three more is neither
		// (for a price it is no number anyway: it has three decimals)
		if( preg_match( '/^-?\d{1,3}\.\d{3}$/D', $text ) === 1 )
			return null;

		if( preg_match( $german, $text, $found ) === 1 )
			$found[2] = str_replace( '.', '', $found[2] );
		elseif( preg_match( $plain, $text, $found ) !== 1 )
			return null;

		$fraction = str_pad( $found[3] ?? '', $decimals, '0' );
		$value = (int) ( $found[2]. $fraction );

		return $found[1] === '-' ? -$value : $value;
	}

	/**
	 *	Cents as 1.234,56, the currency behind a non-breaking space
	 *
	 *	@param		int				$cents
	 *	@param		string		$currency			'' for the number alone
	 *
	 *	@return 	string
	 */
	public static function format( int $cents, string $currency = '' ): string {

		$text = ( $cents < 0 ? '-' : '' ). self::group( intdiv( abs( $cents ), 100 ) ). ','. sprintf( '%02d', abs( $cents ) % 100 );

		return $currency === '' ? $text : $text. self::NBSP. $currency;
	}

	/**
	 *	Thousandths as 1,5 or 2: the trailing zeros cut, at most three decimals
	 *
	 *	@param		int				$thousandths
	 *
	 *	@return 	string
	 */
	public static function formatQty( int $thousandths ): string {

		$text = ( $thousandths < 0 ? '-' : '' ). self::group( intdiv( abs( $thousandths ), 1000 ) );
		$fraction = rtrim( sprintf( '%03d', abs( $thousandths ) % 1000 ), '0' );

		return $fraction === '' ? $text : $text. ','. $fraction;
	}

	/**
	 *	Hundredths of a percent as 19 %, 7 % or 5,5 %
	 *
	 *	@param		int				$rate
	 *
	 *	@return 	string
	 */
	public static function formatRate( int $rate ): string {

		$fraction = rtrim( sprintf( '%02d', $rate % 100 ), '0' );

		return intdiv( $rate, 100 ). ( $fraction === '' ? '' : ','. $fraction ). ' %';
	}

	/**
	 *	A dividend by a divisor, rounded half away from zero
	 *
	 *	@param		int				$dividend
	 *	@param		int				$divisor
	 *
	 *	@return 	int
	 */
	private static function divide( int $dividend, int $divisor ): int {

		$quotient = intdiv( abs( $dividend ) + intdiv( $divisor, 2 ), $divisor );

		return $dividend < 0 ? -$quotient : $quotient;
	}

	/**
	 *	A whole number with a dot between the groups of three digits
	 *
	 *	@param		int				$number
	 *
	 *	@return 	string
	 */
	private static function group( int $number ): string {

		return (string) preg_replace( '/\B(?=(\d{3})+(?!\d))/', '.', (string) $number );
	}
}

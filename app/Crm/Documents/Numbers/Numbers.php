<?php
declare(strict_types=1);
/**
 *	Crm\Documents\Numbers		The number sequences of the documents: one counter per category and year, in the table
 *													sequences, taken inside the write transaction of the caller. The counter is read and raised in
 *													the transaction that finalises the document, so a number is spent with its document and with
 *													nothing else: a rollback gives it back, and the numbers of a category and year are gapless.
 */
namespace Crm\Documents;

use Crm\Db;
use Crm\Settings;

class Numbers {

	/**
	 *	The next number of a category in a year. The caller holds a write transaction (BEGIN IMMEDIATE)
	 *
	 *	@param		\PDO			$pdo
	 *	@param		string		$category			offer, invoice or credit
	 *	@param		int				$year
	 *	@param		string		$format				A number format, see Settings::validFormat()
	 *
	 *	@return 	string|null								Null for a format that is none and for a number a document has already
	 */
	public static function next( \PDO $pdo, string $category, int $year, string $format ): ?string {

		if( Settings::validFormat( $format ) === false )
			return null;

		$statement = $pdo->prepare( 'INSERT OR IGNORE INTO sequences ( category, year, next ) VALUES ( :category, :year, 1 )' );
		$statement->execute( [ 'category' => $category, 'year' => $year ] );

		$statement = $pdo->prepare( 'SELECT next FROM sequences WHERE category = :category AND year = :year' );
		$statement->execute( [ 'category' => $category, 'year' => $year ] );
		$counter = (int) $statement->fetchColumn();

		$statement = $pdo->prepare( 'UPDATE sequences SET next = next + 1 WHERE category = :category AND year = :year' );
		$statement->execute( [ 'category' => $category, 'year' => $year ] );

		$number = self::render( $format, $year, $counter );

		$statement = $pdo->prepare( 'SELECT COUNT(*) FROM documents WHERE number = :number' );
		$statement->execute( [ 'number' => $number ] );

		return (int) $statement->fetchColumn() === 0 ? $number : null;
	}

	/**
	 *	The number a category would get next in a year, as it is now: read, never taken - the counter does not move, which is what the settings
	 *	screen shows beside the format. A year with no document yet starts at 1
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$category			offer, invoice or credit
	 *	@param		int				$year
	 *	@param		string		$format				A number format, see Settings::validFormat()
	 *
	 *	@return 	string										'' for a format that is none
	 */
	public static function upcoming( array &$appData, string $category, int $year, string $format ): string {

		if( Settings::validFormat( $format ) === false )
			return '';

		$row = Db::row( $appData, 'SELECT next FROM sequences WHERE category = :category AND year = :year', [ 'category' => $category, 'year' => $year ] );

		return self::render( $format, $year, (int) ( $row['next'] ?? 1 ) );
	}

	/**
	 *	A format filled: the year in four or two digits, the counter zero-padded to the width of the N's and never cut
	 *
	 *	@param		string		$format
	 *	@param		int				$year
	 *	@param		int				$counter
	 *
	 *	@return 	string
	 */
	public static function render( string $format, int $year, int $counter ): string {

		$format = str_replace( [ '{YYYY}', '{YY}' ], [ sprintf( '%04d', $year ), sprintf( '%02d', $year % 100 ) ], $format );

		return (string) preg_replace_callback( '/\{(N+)\}/', static fn( array $found ): string => str_pad( (string) $counter, strlen( $found[1] ), '0', STR_PAD_LEFT ), $format );
	}
}

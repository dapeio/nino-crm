<?php
declare(strict_types=1);
/**
 *	Crm\Db		The SQLite layer of the CRM module: one file, /data/crm.sqlite, one connection per request,
 *						the schema version in PRAGMA user_version and the migrations - numbered PHP files in
 *						migrations/ that return their SQL statements - run on the first connection of a request.
 *						Statements are always prepared; a failure is a \PDOException the caller answers.
 */
namespace Crm;

class Db {

	public const string FILE = '/data/crm.sqlite';

	// The connections of this request by file, and how many migrations the last connection ran
	private static array $_connections = [];
	private static int $_migrated = 0;

	/**
	 *	The connection to the project's database, opened and migrated on first use
	 *
	 *	@param		array 		&$appData			(reference) Array with current app data
	 *
	 *	@return 	\PDO
	 */
	public static function pdo( array &$appData ): \PDO {

		$file = \Nino\Filesystem::path( $appData, self::FILE );

		if( isset( self::$_connections[$file] ) === true )
			return self::$_connections[$file];

		\Nino\Filesystem::forceDir( $appData, '/data' );

		$pdo = new \PDO( 'sqlite:'. $file, null, null, [
			\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			\PDO::ATTR_EMULATE_PREPARES => false,
		] );

		$pdo->exec( 'PRAGMA busy_timeout = 5000' );
		$pdo->exec( 'PRAGMA journal_mode = WAL' );
		$pdo->exec( 'PRAGMA foreign_keys = ON' );

		self::$_migrated = self::migrate( $pdo );
		self::$_connections[$file] = $pdo;

		return $pdo;
	}

	/**
	 *	Let go of the connections of this request - the next pdo() opens and checks the file anew
	 *
	 *	@return 	void
	 */
	public static function close(): void {

		self::$_connections = [];
	}

	/**
	 *	How many migrations the last connection opened by pdo() had to run
	 *
	 *	@return 	int
	 */
	public static function migrated(): int {

		return self::$_migrated;
	}

	/**
	 *	The migration files in the order they run: number => path
	 *
	 *	@return 	array
	 */
	public static function migrations(): array {

		$found = [];

		foreach( glob( __DIR__. '/migrations/[0-9][0-9][0-9][0-9]-*.php' ) ?: [] as $path )
			$found[(int) substr( basename( $path ), 0, 4 )] = $path;

		ksort( $found );

		return $found;
	}

	/**
	 *	Run the migrations the file has not seen, each in a transaction of its own, and set user_version after each.
	 *	The version is read again inside the transaction, so two requests that start together run a migration once
	 *
	 *	@param		\PDO			$pdo
	 *
	 *	@return 	int											How many migrations ran
	 */
	public static function migrate( \PDO $pdo ): int {

		$ran = 0;

		foreach( self::migrations() as $number => $path ) {

			if( (int) $pdo->query( 'PRAGMA user_version' )->fetchColumn() >= $number )
				continue;

			$pdo->exec( 'BEGIN IMMEDIATE' );

			try {
				if( (int) $pdo->query( 'PRAGMA user_version' )->fetchColumn() < $number ) {

					foreach( (array) include $path as $statement )
						$pdo->exec( (string) $statement );

					$pdo->exec( 'PRAGMA user_version = '. $number );
					$ran++;
				}

				$pdo->exec( 'COMMIT' );
			}
			catch( \Throwable $e ) {
				$pdo->exec( 'ROLLBACK' );
				throw $e;
			}
		}

		return $ran;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		string		$sql
	 *	@param		array 		$params
	 *
	 *	@return 	array										All rows
	 */
	public static function rows( array &$appData, string $sql, array $params = [] ): array {

		$statement = self::pdo( $appData )->prepare( $sql );
		$statement->execute( $params );

		return $statement->fetchAll();
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		string		$sql
	 *	@param		array 		$params
	 *
	 *	@return 	array|null								The first row, null for none
	 */
	public static function row( array &$appData, string $sql, array $params = [] ): ?array {

		return self::rows( $appData, $sql, $params )[0] ?? null;
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		string		$sql
	 *	@param		array 		$params
	 *
	 *	@return 	int											The rows the statement changed
	 */
	public static function exec( array &$appData, string $sql, array $params = [] ): int {

		$statement = self::pdo( $appData )->prepare( $sql );
		$statement->execute( $params );

		return $statement->rowCount();
	}
}

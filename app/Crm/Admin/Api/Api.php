<?php
declare(strict_types=1);
/**
 *	Crm\Admin\Api		What the panels of the module after Kunden share: running an action over the database, and reading the id
 *									of a record from a post. A panel's codes are its own (crm_items_..., crm_documents_...); the code of a
 *									failure is handed in.
 */
namespace Crm\Admin;

class Api {

	/**
	 *	Run an action over the database: a failure of it is a 500 with the code
	 *
	 *	@param		array 		&$request
	 *	@param		string		$code					The panel's database code
	 *	@param		callable	$run
	 *
	 *	@return 	void
	 */
	public static function run( array &$request, string $code, callable $run ): void {

		try {
			$run();
		}
		catch( \PDOException $e ) {
			\Nino\Http::fail( $request, 500, 'the database failed', $code );
		}
	}

	/**
	 *	The id a post names
	 *
	 *	@param		array 		&$request
	 *	@param		array 		$data
	 *	@param		string		$code					The panel's code for an id that is none
	 *	@param		string		$key
	 *
	 *	@return 	int|null									Null after the 400 that says why
	 */
	public static function id( array &$request, array $data, string $code, string $key = 'id' ): ?int {

		$id = \Nino\Admin\Admin::postString( $data, $key );

		if( preg_match( '/^[1-9][0-9]{0,9}$/D', $id ) !== 1 ) {
			\Nino\Http::fail( $request, 400, 'the id is not valid', $code, [], $key );
			return null;
		}

		return (int) $id;
	}
}

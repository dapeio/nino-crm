<?php
declare(strict_types=1);
/**
 *	Crm\Termine\Admin		The API of the dates behind the surface's endpoint (Surface::actions()): what the screens Termine and Termin, the tab Termine of the
 *											client's card and the tile of the Übersicht read. The dates are made, changed and removed through the Elements panel's own actions
 *											(see Surface\Elements, the type events); these two actions only read:
 *
 *											crm-termine/list		the dates (a client's, a number of days ahead) with their client: upcoming - those that are not over and begin within the days
 *																					(the soonest first, 60 days by default) - and past - those that ended within the last 30 days -, and missing (the feature
 *																					Events is off or has no type), native (the language the screens save in), now (the moment in the timezone of the site) and
 *																					allowed (whether the account holds the permission of the Elements panel)
 *											crm-termine/get			one date with its place, its text as the type holds it and its link, and the same counts and flags
 *
 *											It validates what it is posted and answers the failures a person can cause with codes (their sentences are in text/); the rules of
 *											the data are Crm\Termine's. A database failure is answered 500 with the code crm_documents_database.
 */
namespace Crm\Termine;

use Crm\Termine;

class Admin {

	public const string MANAGE_PERM = \Crm\Crm::MANAGE_PERM;

	public static function actions(): array {
		return [
			'crm-termine/list' => [ self::class, 'apiList' ],
			'crm-termine/get'  => [ self::class, 'apiGet' ],
		];
	}

	/**
	 *	The activity-log line of a completed action: both actions only read
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return '';
	}

	/**
	 *	The dates by the filters: client (the id of a client) and days (how many days ahead, 1 to 3660, 60 by default)
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$client = \Nino\Admin\Admin::postString( $data, 'client' );
		$days = $data['days'] ?? Termine::AHEAD;

		if( ( array_key_exists( 'client', $data ) === true && is_string( $data['client'] ) === false ) || ( $client !== '' && preg_match( '/^[1-9][0-9]{0,9}$/D', $client ) !== 1 ) || is_int( $days ) === false || $days < 1 || $days > 3660 ) {
			\Nino\Http::fail( $request, 400, 'the filter is not valid', 'crm_termine_format' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request, $client, $days ): void {

			$group = null;
			$none = false;

			if( $client !== '' ) {

				$found = \Crm\Clients::get( $appData, (int) $client );

				// A client that is not there has no dates
				if( $found === null )
					$none = true;
				else
					$group = \Crm\Clients::group( $found['number'] );
			}

			$dates = $none === true || Termine::missing( $appData ) === true ? [ 'upcoming' => [], 'past' => [] ] : Termine::list( $appData, $group, $days );

			\Nino\Http::ok( $request, self::_context( $appData ) + $dates );
		} );
	}

	/**
	 *	One date: id (the name of the element, the slug)
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_documents_database', static function() use ( &$appData, &$request ): void {

			if( Termine::missing( $appData ) === true ) {
				\Nino\Http::fail( $request, 404, 'the dates are not there', 'crm_termine_missing', [], 'id' );
				return;
			}

			$date = Termine::get( $appData, \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'id' ) );

			if( $date === null ) {
				\Nino\Http::fail( $request, 404, 'there is no such date', 'crm_termine_unknown', [], 'id' );
				return;
			}

			\Nino\Http::ok( $request, self::_context( $appData ) + [ 'date' => $date ] );
		} );
	}

	/**
	 *	What every answer carries
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array
	 */
	private static function _context( array &$appData ): array {

		$missing = Termine::missing( $appData );

		return [
			'missing' => $missing,
			'native'  => \Nino\Locales::getNativeLocale( $appData ),
			'now'     => $missing === true ? '' : Termine::now( $appData )->format( 'Y-m-d\TH:i' ),
			'allowed' => \Crm\Surface\Elements::allowed( $appData ),
		];
	}
}

<?php
declare(strict_types=1);
/**
 *	Crm\Portal		What the portal pages read of the CRM: the client of the member who is signed in, and the component
 *								[crm-client] that says its company. A visitor has no client, and a page view of a visitor never
 *								opens the database. The lookup is kept for the request and dropped when a member signs in or out.
 */
namespace Crm;

class Portal {

	// The markup of the component, declared once
	public static $html = [
		'client' => '<span class="crm-client[[classes]]">[[company]]</span>',
	];

	private const string CACHE = './crm/portal/client';

	/**
	 *	The client of the signed-in member
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null								As Clients::get() has it; null for a visitor and for an account no client owns
	 */
	public static function client( array &$appData ): ?array {

		if( Crm::featureOn( $appData, 'members' ) === false )
			return null;

		$member = \Nino\Modules\Members::current( $appData );

		if( $member === null )
			return null;

		if( array_key_exists( self::CACHE, $appData ) === false )
			$appData[self::CACHE] = Clients::ofMember( $appData, $member['id'] );

		return $appData[self::CACHE];
	}

	/**
	 *	[crm-client] - the company of the member's client, its name where it has no company; nothing for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmClient( array &$appData, array $args ): string {

		$client = self::client( $appData );

		if( $client === null )
			return '';

		return str_replace(
			[ '[[classes]]', '[[company]]' ],
			[ \Nino\Feature::classes( $args ), \Nino\Modules\Components::escape( $client['company'] !== '' ? $client['company'] : $client['name'] ) ],
			self::$html['client']
		);
	}

	/**
	 *	'/members/login' and '/members/logout': who is here changed - the lookup of this request is dropped, so a page
	 *	rendered after it does not answer for whoever was here before
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$account				The account of Members, unused
	 *
	 *	@return 	void
	 */
	public static function callbackSession( array &$appData, array &$account ): void {

		unset( $appData[self::CACHE] );
	}
}

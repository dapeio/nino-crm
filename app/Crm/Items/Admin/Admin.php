<?php
declare(strict_types=1);
/**
 *	Crm\Items\Admin		The panel Positionen of the CRM module: the catalogue of what the owner sells (list, card, add, change,
 *										deactivate, move up and down). The panel validates what it is posted and answers the failures a
 *										person can cause with codes; the rules of the data are Crm\Items', the storage Crm\Db's. A database
 *										failure is answered 500 with the code crm_items_database.
 */
namespace Crm\Items;

use Crm\Items;

class Admin {

	public const string MANAGE_PERM = '/_admin/crm-items/manage';

	// The id of the item an action made or touched, by action: written by the action, read by log() in the same request
	private static array $_made = [];

	public static function perm(): string {
		return self::MANAGE_PERM;
	}

	public static function actions(): array {
		return [
			'crm-items/list'   => [ self::class, 'apiList' ],
			'crm-items/get'    => [ self::class, 'apiGet' ],
			'crm-items/save'   => [ self::class, 'apiSave' ],
			'crm-items/active' => [ self::class, 'apiActive' ],
			'crm-items/order'  => [ self::class, 'apiOrder' ],
		];
	}

	public static function nav(): array {
		return [ 'crm-items', '/_admin/crm-items/nav', 50, 'content' ];
	}

	public static function icon(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/></svg>';
	}

	// The panel draws into the one region of templates/panel.tpl
	public static function template(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/templates/panel' );
	}

	public static function assets(): array {
		return [ \Nino\Admin\Panels::relative( dirname( __DIR__, 2 ). '/Documents/Money/assets/money.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.js' ), \Nino\Admin\Panels::relative( __DIR__. '/assets/admin.css' ) ];
	}

	public static function text(): string {
		return \Nino\Admin\Panels::relative( __DIR__. '/text' );
	}

	/**
	 *	The activity-log line of a completed action: the id of the item, never a title or a price
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		$id = self::$_made[$action] ?? '';
		$of = $id === '' ? '' : ' '. $id;

		return match( $action ) {
			'crm-items/save'   => ( isset( $data['id'] ) === true && $data['id'] !== '' ? 'Changed item' : 'Added item' ). $of,
			'crm-items/active' => 'Switched item'. $of,
			'crm-items/order'  => 'Ordered the items',
			default            => '',
		};
	}

	/**
	 *	The items in their order, with what the card needs to draw a price and a rate
	 */
	public static function apiList( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$search = \Nino\Admin\Admin::postString( \Nino\Admin\Admin::postData(), 'search' );

		if( mb_strlen( $search, 'UTF-8' ) > 100 ) {
			\Nino\Http::fail( $request, 400, 'the search is too long', 'crm_items_long', [ 100 ], 'search' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_items_database', static function() use ( &$appData, &$request, $search ): void {
			\Nino\Http::ok( $request, [ 'items' => Items::list( $appData, false, $search ), 'settings' => self::_settings( $appData ) ] );
		} );
	}

	/**
	 *	One item
	 */
	public static function apiGet( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_items_database', static function() use ( &$appData, &$request ): void {

			$item = self::_item( $appData, $request, \Nino\Admin\Admin::postData() );

			if( $item !== null )
				\Nino\Http::ok( $request, [ 'item' => $item ] );
		} );
	}

	/**
	 *	Add an item (no id) or change one (id)
	 */
	public static function apiSave( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$data = \Nino\Admin\Admin::postData();
		$input = [];

		foreach( [ 'title', 'description', 'unit', 'price' ] as $field )
			if( array_key_exists( $field, $data ) === true )
				$input[$field] = $data[$field];

		if( array_key_exists( 'vat_rate', $data ) === true )
			$input['vat_rate'] = $data['vat_rate'];

		\Crm\Admin\Api::run( $request, 'crm_items_database', static function() use ( &$appData, &$request, $data, $input ): void {

			if( \Nino\Admin\Admin::postString( $data, 'id' ) === '' )
				$item = Items::add( $appData, $input );
			else {
				$existing = self::_item( $appData, $request, $data );

				if( $existing === null )
					return;

				$item = Items::change( $appData, $existing['id'], $input );
			}

			if( is_string( $item ) === true ) {
				self::_refused( $request, $item );
				return;
			}

			self::$_made['crm-items/save'] = (string) $item['id'];

			\Nino\Http::ok( $request, [ 'item' => $item ] );
		} );
	}

	/**
	 *	Switch an item on or off: active true or false, without it the other way round
	 */
	public static function apiActive( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		\Crm\Admin\Api::run( $request, 'crm_items_database', static function() use ( &$appData, &$request ): void {

			$data = \Nino\Admin\Admin::postData();
			$item = self::_item( $appData, $request, $data );

			if( $item === null )
				return;

			$changed = Items::setActive( $appData, $item['id'], is_bool( $data['active'] ?? null ) === true ? $data['active'] : $item['active'] === false );

			if( is_string( $changed ) === true ) {
				self::_refused( $request, $changed );
				return;
			}

			self::$_made['crm-items/active'] = (string) $changed['id'];

			\Nino\Http::ok( $request, [ 'item' => $changed ] );
		} );
	}

	/**
	 *	Put the items in the order of ids: [ 3, 1, 2 ]
	 */
	public static function apiOrder( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::MANAGE_PERM ) === false )
			return;

		$ids = \Nino\Admin\Admin::postData()['ids'] ?? null;

		if( is_array( $ids ) === false || array_is_list( $ids ) === false || array_filter( $ids, static fn( mixed $id ): bool => is_int( $id ) === false ) !== [] ) {
			\Nino\Http::fail( $request, 400, 'the ids are not valid', 'crm_items_id', [], 'ids' );
			return;
		}

		\Crm\Admin\Api::run( $request, 'crm_items_database', static function() use ( &$appData, &$request, $ids ): void {

			$ordered = Items::order( $appData, $ids );

			if( is_string( $ordered ) === true ) {
				self::_refused( $request, $ordered );
				return;
			}

			\Nino\Http::ok( $request, [ 'items' => Items::list( $appData ) ] );
		} );
	}

	/**
	 *	What the screen needs to draw a price and a rate
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											vat_mode, rates, currency
	 */
	private static function _settings( array &$appData ): array {

		return [ 'vat_mode' => \Crm\Settings::get( $appData, 'vat_mode' ), 'rates' => \Crm\Settings::rates( $appData ), 'currency' => \Crm\Settings::get( $appData, 'currency' ) ];
	}

	/**
	 *	The item a request names by id, or the 400/404 that says why not
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		array 		$data
	 *
	 *	@return 	array|null
	 */
	private static function _item( array &$appData, array &$request, array $data ): ?array {

		$id = \Crm\Admin\Api::id( $request, $data, 'crm_items_id' );

		if( $id === null )
			return null;

		$item = Items::get( $appData, $id );

		if( $item === null )
			\Nino\Http::fail( $request, 404, 'there is no such item', 'crm_items_unknown', [], 'id' );

		return $item;
	}

	/**
	 *	The answer to a refusal of the model
	 *
	 *	@param		array 		&$request
	 *	@param		string		$why					See Crm\Items
	 *
	 *	@return 	void
	 */
	private static function _refused( array &$request, string $why ): void {

		[ $kind, $field ] = array_pad( explode( ':', $why, 2 ), 2, '' );

		match( $kind ) {
			'empty'  => \Nino\Http::fail( $request, 400, 'an item needs a title', 'crm_items_empty', [], 'title' ),
			'long'   => \Nino\Http::fail( $request, 400, 'the value is too long', 'crm_items_long', [ Items::FIELDS[$field] ?? 0 ], $field ),
			'format' => \Nino\Http::fail( $request, 400, 'the value is no text', 'crm_items_format', [], $field ),
			'amount' => \Nino\Http::fail( $request, 400, 'the price is no amount', 'crm_items_amount', [], 'price' ),
			'rate'   => \Nino\Http::fail( $request, 400, 'the rate is none of the rates', 'crm_items_rate', [], 'vat_rate' ),
			default  => \Nino\Http::fail( $request, 404, 'there is no such item', 'crm_items_unknown', [], 'id' ),
		};
	}
}

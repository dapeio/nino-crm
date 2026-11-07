<?php
declare(strict_types=1);
/**
 *	Crm\Surface\Texts		What the block Firmendaten of the Einstellungen takes from the Text panel of the workbench: its actions text/keys and
 *											text/savebatch go through the endpoint as the panel has them, under the permission of the panel (and the panel's own
 *											permission for each key), and the sentences it has are the panel's own. One thing only the surface adds: every one of
 *											them is for the keys below /project/company/ and for no other. A batch that names another key is refused before the
 *											panel is asked - 404 crm_texts_key, nothing written, nothing delegated -, and the list of the keys is the panel's, cut down
 *											to the company's, so that the endpoint never becomes an editor for the texts of the site. An item whose value or language is no text
 *											is refused with 400 crm_texts_value at the field items, before the panel reads it.
 *
 *											The panel is used as it is: what a save posts (items of key, locale and value - one language to the batch, since the panel
 *											answers one result to each key), what it answers and the line of the activity log are its.
 */
namespace Crm\Surface;

class Texts {

	// The permission of the panel: the one the actions of text/* ask for
	public const string PERM = '/_admin/text/manage';

	// The keys the actions are for: the company the wizard writes (name, description, address, country, email, phone)
	public const string PREFIX = '/project/company/';

	// What a key of the company looks like: the prefix, then words of lower case letters, digits and hyphens between slashes - no dots, no spaces
	private const string KEY = '~^/project/company/[a-z0-9-]+(?:/[a-z0-9-]+)*$~D';

	// The actions of the panel that the surface offers, and the method of this class that answers each
	private const array ACTIONS = [ 'text/keys' => 'apiKeys', 'text/savebatch' => 'apiSaveBatch' ];

	/**
	 *	Whether the panel is there to be asked
	 *
	 *	@return 	bool
	 */
	public static function available(): bool {

		return class_exists( '\\Nino\\Modules\\Text\\Admin' );
	}

	/**
	 *	The actions of the block: the panel's two, answered by the methods of this class
	 *
	 *	@return 	array											action => [ class, method ]
	 */
	public static function actions(): array {

		if( self::available() === false )
			return [];

		$actions = [];

		foreach( \Nino\Modules\Text\Admin::actions() as $action => $target )
			if( isset( self::ACTIONS[$action] ) === true )
				$actions[$action] = [ self::class, self::ACTIONS[$action] ];

		return $actions;
	}

	/**
	 *	Whether the account holds the permission of the Text panel
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function allowed( array &$appData ): bool {

		return self::available() === true && \Nino\Auth::checkPermission( $appData, self::PERM ) === true;
	}

	/**
	 *	The file with the sentences of the panel
	 *
	 *	@return 	string										'' where the panel has none
	 */
	public static function textFile(): string {

		if( self::available() === false )
			return '';

		$file = (string) ( new \ReflectionClass( '\\Nino\\Modules\\Text\\Admin' ) )->getFileName();
		$texts = dirname( $file, 2 ). '/text/de_DE.php';

		return is_file( $texts ) === true ? $texts : '';
	}

	/**
	 *	The line of the activity log: the panel's own for a save, none for a read
	 *
	 *	@param		string		$action
	 *	@param		array 		$data
	 *
	 *	@return 	string
	 */
	public static function log( string $action, array $data ): string {

		return self::available() === true && isset( self::ACTIONS[$action] ) === true ? \Nino\Modules\Text\Admin::log( $action, $data ) : '';
	}

	/**
	 *	The texts of the company as the CRM reads them for the Einstellungen, for an account that holds the permission of the CRM alone: the keys below the prefix with
	 *	their values, the languages that have a text file and the native one. The texts are the site's own words (its PDFs, mails and pages show them), so reading them is
	 *	no permission of the Text panel; the panel's actions are for editing
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											keys ([ { key, global, values } ]), locales ([ { locale, active } ]), native
	 */
	public static function company( array &$appData ): array {

		$keys = [];

		foreach( \Nino\Text::entries( $appData, false ) as $entry )
			if( str_starts_with( $entry['key'], self::PREFIX ) === true )
				$keys[] = [ 'key' => $entry['key'], 'global' => $entry['global'], 'values' => $entry['values'] ];

		return [ 'keys' => $keys, 'locales' => \Nino\Admin\Admin::textLocales( $appData ), 'native' => \Nino\Locales::getNativeLocale( $appData ) ];
	}

	/**
	 *	The keys of the company with their values in every language, and the languages
	 *
	 *	The panel answers every key of the site with the arrangement its own form needs; the surface needs the keys of the company, the
	 *	languages that have a text file and the native one
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiKeys( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return;

		\Nino\Modules\Text\Admin::apiKeys( $appData, $request );

		$body = $request['/nino/http/response']['body'] ?? null;

		if( (int) ( $request['/nino/http/response']['statusCode'] ?? 200 ) !== 200 || is_array( $body ) === false || is_array( $body['keys'] ?? null ) === false )
			return;

		$keys = array_values( array_filter( $body['keys'], static fn( mixed $entry ): bool => is_array( $entry ) === true && str_starts_with( (string) ( $entry['key'] ?? '' ), self::PREFIX ) === true ) );

		\Nino\Http::ok( $request, [ 'keys' => $keys, 'locales' => $body['locales'] ?? [], 'native' => \Nino\Locales::getNativeLocale( $appData ) ] );
	}

	/**
	 *	Save the values of keys of the company: a batch with another key in it is refused as a whole
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function apiSaveBatch( array &$appData, array &$request ): void {

		if( \Nino\Admin\Admin::guardPerm( $appData, $request, self::PERM ) === false )
			return;

		$items = \Nino\Admin\Admin::postData()['items'] ?? [];

		foreach( is_array( $items ) === true ? $items : [] as $item ) {

			$key = is_array( $item ) === true && is_string( $item['key'] ?? null ) === true ? $item['key'] : '';

			if( preg_match( self::KEY, $key ) !== 1 ) {
				\Nino\Http::fail( $request, 404, 'these actions are for the keys below '. self::PREFIX, 'crm_texts_key' );
				return;
			}

			// The panel reads a value and a language as text: a list or an object in their place is refused here, with nothing written
			foreach( [ 'value', 'locale' ] as $field ) {

				if( array_key_exists( $field, $item ) === true && is_string( $item[$field] ) === false ) {
					\Nino\Http::fail( $request, 400, 'the '. $field. ' of an item is text', 'crm_texts_value', [], 'items' );
					return;
				}
			}
		}

		\Nino\Modules\Text\Admin::apiSaveBatch( $appData, $request );
	}
}

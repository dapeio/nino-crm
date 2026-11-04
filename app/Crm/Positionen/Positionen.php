<?php
declare(strict_types=1);
/**
 *	Crm\Positionen		What the owner sells, read from the elements of the type /positionen. The items are made, changed and removed in
 *								the screen Positionen of the surface and in the Elements panel (the surface drives the panel's own actions for this type, see
 *								Surface\Elements) and the Builder draws them on a public page; this class only reads them, in the site's native
 *								language and with \Nino\Elements::getElementWithFallback(), so a field that has no text in it shows the
 *								text of another language. A line of a document copies the words and numbers of an item when the editor
 *								adds it and never follows the element afterwards.
 *
 *								The type itself is brought by the install unit of the module (unit/positionen.php, applied by the button
 *								Portal einrichten in the panel CRM and in the settings of the surface): missing() says whether it is there, and while it is not, list() has nothing.
 *								Reading a type that is not there is an error of the kernel, which is why the type is asked for first.
 */
namespace Crm;

class Positionen {

	// The element type, and the file the kernel keeps it in
	public const string TYPE = '/positionen';
	private const string FILE = '/elements/positionen.php';

	// The longest value of a number in hundredths, beyond which a line refuses the price in any case
	private const int LIMIT = \Crm\Documents\Money::MAX_PRICE + 1;

	/**
	 *	Whether the type is not there
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	bool
	 */
	public static function missing( array &$appData ): bool {

		return \Nino\Filesystem::fileExists( $appData, self::FILE ) === false;
	}

	/**
	 *	The items in the order of their field sort, in the native language
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$search				Part of the title (case does not matter, no wildcards)
	 *	@param		bool			$activeOnly		Leave out the items whose field hidden is on (the row's active is its opposite)
	 *
	 *	@return 	array											id, title, description, unit, price (cents), vat (hundredths), active, sort of each
	 */
	public static function list( array &$appData, string $search = '', bool $activeOnly = true ): array {

		if( self::missing( $appData ) === true )
			return [];

		$native = \Nino\Locales::getNativeLocale( $appData );
		$hits = \Nino\Elements::queryElements( $appData, self::TYPE, $activeOnly === true ? [ 'hidden' => '0' ] : [], $native, [], [ 'sort' => 'sort' ] );
		$search = trim( $search );
		$items = [];

		foreach( $hits as $hit ) {

			$element = \Nino\Elements::getElementWithFallback( $appData, (string) $hit['.uri'], $native );

			if( $element === false )
				continue;

			$item = self::shape( $element );

			if( $search === '' || mb_stripos( $item['title'], $search, 0, 'UTF-8' ) !== false )
				$items[] = $item;
		}

		return $items;
	}

	/**
	 *	Every item, hidden ones too, in the order of their field sort, with its words in each language of the site - exactly what the
	 *	element holds in that language, without the fallback to another (a language that has no text shows empty, which is what the
	 *	Elements panel shows as well). The numbers are global
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											id, sort (null for an item that has none), price (cents), vat (hundredths), active and words ( locale => title, description, unit ) of each
	 */
	public static function all( array &$appData ): array {

		if( self::missing( $appData ) === true )
			return [];

		$locales = \Nino\Locales::getAvailableLocales( $appData );
		$hits = \Nino\Elements::queryElements( $appData, self::TYPE, [], '*', [], [ 'sort' => 'sort' ] );
		$items = [];

		foreach( is_array( $hits ) === true ? $hits : [] as $hit ) {

			$uri = (string) $hit['.uri'];
			$first = \Nino\Elements::getElement( $appData, $uri, '*' );

			if( is_array( $first ) === false )
				continue;

			$item = self::shape( $first + [ '.uri' => $uri ] );
			$words = [];

			foreach( $locales as $locale ) {

				$element = \Nino\Elements::getElement( $appData, $uri, (string) $locale );
				$words[$locale] = self::shape( is_array( $element ) === true ? $element + [ '.uri' => $uri ] : [ '.uri' => $uri ] );
				$words[$locale] = array_intersect_key( $words[$locale], [ 'title' => 1, 'description' => 1, 'unit' => 1 ] );
			}

			$items[] = [ 'id' => $item['id'], 'sort' => is_int( $first['sort'] ?? null ) === true ? $item['sort'] : null, 'price' => $item['price'], 'vat' => $item['vat'], 'active' => $item['active'], 'words' => $words ];
		}

		return $items;
	}

	/**
	 *	One item
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$id						The name of the element, the last part of its uri
	 *
	 *	@return 	array|null								As list() has it; null for none
	 */
	public static function get( array &$appData, string $id ): ?array {

		if( self::missing( $appData ) === true )
			return null;

		$element = \Nino\Elements::getElementWithFallback( $appData, self::TYPE. '/'. $id, \Nino\Locales::getNativeLocale( $appData ) );

		return $element === false ? null : self::shape( $element );
	}

	/**
	 *	@param		array 		$element			As getElementWithFallback() answers it
	 *
	 *	@return 	array
	 */
	private static function shape( array $element ): array {

		$text = static fn( mixed $value ): string => is_string( $value ) === true ? trim( $value ) : '';

		return [
			'id'          => \Nino\Elements::getElementUriFromUri( (string) $element['.uri'] ),
			'title'       => $text( $element['title'] ?? '' ),
			'description' => $text( $element['description'] ?? '' ),
			'unit'        => $text( $element['unit'] ?? '' ),
			'price'       => self::hundredths( $element['price'] ?? 0 ),
			'vat'         => self::hundredths( $element['vat'] ?? 0 ),
			'active'      => ( $element['hidden'] ?? false ) !== true,
			'sort'        => is_int( $element['sort'] ?? null ) === true ? $element['sort'] : 0,
		];
	}

	/**
	 *	A number of the element in hundredths: euros in cents, percent in hundredths of a percent. Rounded half away from zero, and
	 *	kept inside the range a line takes, so that the line refuses what is beyond it
	 *
	 *	@param		mixed			$value
	 *
	 *	@return 	int
	 */
	private static function hundredths( mixed $value ): int {

		if( is_int( $value ) === false && is_float( $value ) === false )
			return 0;

		return (int) max( -self::LIMIT, min( self::LIMIT, round( $value * 100 ) ) );
	}
}

<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Layout\Tokens	The words in double brackets a document is filled with
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Tokens						The tokens of a document's HTML, filled in before it is parsed.
	 *
	 *										A token with a leading slash is a key. The caller is asked first:
	 *										the tokens it handed in may hold the key itself, flat -
	 *										'/customer/name' => 'Ada' - and nothing else; an array under
	 *										'customer' is not read for it. What the caller does not have is the
	 *										site's text for that key, as the kernel's fills have it
	 *										(\Nino\Html::resolveTextfill()). A plain or dotted name is a value the
	 *										caller handed in too, a dotted one walking nested arrays - the slash
	 *										key and the dotted name are two different tokens; page and pages, in a header or a footer
	 *										only, are left where they stand for the engine to fill in once
	 *										the pages are counted. What a token stands for is escaped as text
	 *										before it enters the HTML - a value that holds a tag arrives as
	 *										the characters of the tag, and a square bracket as its character
	 *										reference, so that a value that holds [[pages]] is the characters
	 *										of it and not the number of pages - and is not read for tokens
	 *										again. A token that stands for nothing is removed, or, in a
	 *										preview, a name of the caller's - a key nobody has included - is
	 *										written in square brackets, so the person sees where the data will
	 *										land.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Tokens {

		/**
		 *	Fill the tokens of a piece of HTML
		 *
		 *	@param		array 		&$appData			(reference) Array with current app data
		 *	@param		string		$html
		 *	@param		array 		$tokens				'/slash/key' => string flat, name => string, nested arrays allowed for the dotted form
		 *	@param		bool			$preview			Unknown names and keys become [name] and [/key]
		 *	@param		bool			$band					A header or a footer: page and pages stay
		 *
		 *	@return 	string
		 */
		public static function fill( array &$appData, string $html, array $tokens, bool $preview = false, bool $band = false ): string {

			return (string) preg_replace_callback( '/\[\[(\/[A-Za-z0-9_.\/-]{1,200}|[A-Za-z][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_-]+)*)\]\]/', static function( array $found ) use ( &$appData, $tokens, $preview, $band ): string {

				$name = $found[1];

				if( $name[0] === '/' ) {

					$given = $tokens[$name] ?? null;

					if( is_string( $given ) === true || is_int( $given ) === true || is_float( $given ) === true )
						return self::_escape( (string) $given );

					$value = \Nino\Html::resolveTextfill( $appData, $name );

					if( $value !== null )
						return self::_escape( $value );

					return $preview === true ? self::_escape( '['. $name. ']' ) : '';
				}

				if( $band === true && ( $name === 'page' || $name === 'pages' ) )
					return $found[0];

				$value = $tokens;

				foreach( explode( '.', $name ) as $part ) {

					if( is_array( $value ) === false || array_key_exists( $part, $value ) === false ) {
						$value = null;
						break;
					}

					$value = $value[$part];
				}

				if( is_string( $value ) === true || is_int( $value ) === true || is_float( $value ) === true )
					return self::_escape( (string) $value );

				return $preview === true ? self::_escape( '['. $name. ']' ) : '';
			}, $html );
		}

		/**
		 *	A value as text for HTML: the characters that mean markup, and the opening square bracket,
		 *	as character references - what reads [[ in the HTML afterwards, a page number in a header
		 *	for one, finds none in a value
		 *
		 *	@param		string		$value
		 *
		 *	@return 	string
		 */
		private static function _escape( string $value ): string {

			return str_replace( '[', '&#91;', htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );
		}
	}
}

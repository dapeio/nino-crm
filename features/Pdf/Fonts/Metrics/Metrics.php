<?php
declare(strict_types=1);
/**
 *	Nino									A compact filesystembased php framework
 *	Modules\Pdf\Fonts\Metrics	The widths of the standard fonts
 *
 *	@package							Dape/Nino
 *	@author								David Perchermeier <mail@dape.io>
 *	@link									https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Fonts {

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Metrics						What a layout has to know about the twelve standard fonts it
	 *										can set text in - the Helvetica, Times and Courier families in
	 *										regular, bold, italic and bold italic - to measure a string:
	 *										the base font names a PDF is told, the advance width of every
	 *										WinAnsi code from 32 to 255, and the ascender and descender
	 *										of a family.
	 *
	 *										The widths are data, one file for each font beside this class
	 *										(Metrics/<Font>.php): a list of 224 numbers in units of 1/1000
	 *										em, the first for code 32, or the one number a monospaced font
	 *										has for every code. They are the character metrics of Adobe's
	 *										Core 14 AFM files; the head of each file carries Adobe's
	 *										notice and the terms they are distributed under, and the
	 *										catalogue's THIRD-PARTY-NOTICES.md quotes them. A font's
	 *										kerning pairs are not used: a viewer does not kern a simple
	 *										font either, so the measure and the page agree.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Metrics {

		// The families a layout offers, by the word its panel stores
		public const array FAMILIES = [ 'helvetica', 'times', 'courier' ];

		// family => regular, bold, italic, bold italic
		private const array NAMES = [
			'helvetica'	=> [ 'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique' ],
			'times'			=> [ 'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic' ],
			'courier'		=> [ 'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique' ],
		];

		// The Ascender and Descender of the AFM files, which are the same for the four fonts of a family
		private const array VERTICAL = [ 'helvetica' => [ 718, -207 ], 'times' => [ 683, -217 ], 'courier' => [ 629, -157 ] ];

		// font => the widths, read once
		private static array $_widths = [];

		/**
		 *	The base font name of a family in a style
		 *
		 *	@param		string		$family				helvetica, times or courier; anything else is helvetica
		 *	@param		bool			$bold
		 *	@param		bool			$italic
		 *
		 *	@return 	string										eg. Times-BoldItalic
		 */
		public static function font( string $family, bool $bold, bool $italic ): string {

			return self::NAMES[ isset( self::NAMES[$family] ) === true ? $family : 'helvetica' ][ ( $bold === true ? 1 : 0 ) + ( $italic === true ? 2 : 0 ) ];
		}

		/**
		 *	The family a base font name belongs to
		 *
		 *	@param		string		$font
		 *
		 *	@return 	string
		 */
		public static function family( string $font ): string {

			return str_starts_with( $font, 'Times' ) === true ? 'times' : ( str_starts_with( $font, 'Courier' ) === true ? 'courier' : 'helvetica' );
		}

		/**
		 *	The width of a string in units of 1/1000 em
		 *
		 *	@param		string		$font					A base font name, see font()
		 *	@param		string		$ansi					WinAnsi bytes
		 *
		 *	@return 	float
		 */
		public static function width( string $font, string $ansi ): float {

			if( $ansi === '' )
				return 0.0;

			if( isset( self::$_widths[$font] ) === false ) {

				$file = __DIR__. '/'. $font. '.php';
				self::$_widths[$font] = in_array( $font, array_merge( ...array_values( self::NAMES ) ), true ) === true && is_file( $file ) === true ? require $file : 600;
			}

			$widths = self::$_widths[$font];

			if( is_int( $widths ) === true )
				return (float) ( strlen( $ansi ) * $widths );

			$sum = 0;

			foreach( (array) unpack( 'C*', $ansi ) as $code )
				$sum += $code >= 32 ? $widths[$code - 32] : 0;

			return (float) $sum;
		}

		/**
		 *	How far a family's glyphs reach above and below the baseline, in units of 1/1000 em
		 *
		 *	@param		string		$font					A base font name
		 *
		 *	@return 	array										[ ascender, descender ], the descender negative
		 */
		public static function vertical( string $font ): array {

			return self::VERTICAL[ self::family( $font ) ];
		}
	}
}

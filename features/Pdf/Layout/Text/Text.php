<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Layout\Text	Words, their widths and the lines they wrap into
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	use Nino\Modules\Pdf\Fonts\Metrics;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Text							Wrapping and measuring over the metrics of the standard fonts.
	 *
	 *										Text is turned into WinAnsi bytes first (iconv, a character the
	 *										code page cannot carry becomes a question mark) and then into
	 *										atoms, each measured: a word, a space, a forced break, a picture.
	 *										Whitespace collapses the way a browser collapses it - a run of it
	 *										is one space - and a no-break space stays inside its word.
	 *
	 *										wrap() breaks atoms into lines at the spaces, greedily, on the
	 *										measured widths. Words that touch with no space between them (a
	 *										bold word in a word) stay together; a word wider than the whole
	 *										line is broken at the width, character by character. A line
	 *										answers its width, the biggest type on it, its height - the line
	 *										height is 1.35 times the type, taller where a picture stands on
	 *										the line - and how far its baseline is from its top.
	 *
	 *										An atom is a map: t (w word, s space, b break, i image), s (the
	 *										bytes), w (the width in points), f (the base font), z (the size),
	 *										u (underlined) and, for a picture, h and i (the Image).
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Text {

		// The line height, in times the size of the type
		public const float LEADING = 1.35;

		/**
		 *	UTF-8 as WinAnsi (Windows-1252) bytes. What the code page cannot carry becomes a question
		 *	mark; the soft hyphen and the zero width characters are dropped
		 *
		 *	@param		string		$text
		 *
		 *	@return 	string
		 */
		public static function encode( string $text ): string {

			$text = str_replace( [ "\u{AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}" ], '', $text );

			if( $text === '' )
				return '';

			$ansi = @iconv( 'UTF-8', 'WINDOWS-1252//TRANSLIT', $text );

			if( $ansi === false ) {

				// Bytes that are not UTF-8: each one a question mark, the rest as it is
				$substitute = mb_substitute_character();
				mb_substitute_character( 0x3F );
				$ansi = (string) mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
				mb_substitute_character( $substitute );
			}

			return (string) preg_replace( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $ansi );
		}

		/**
		 *	The width of WinAnsi bytes in points
		 *
		 *	@param		string		$ansi
		 *	@param		string		$font					A base font name
		 *	@param		float			$size
		 *
		 *	@return 	float
		 */
		public static function width( string $ansi, string $font, float $size ): float {

			return Metrics::width( $font, $ansi ) * $size / 1000;
		}

		/**
		 *	A text as atoms in one style
		 *
		 *	@param		string		$text					UTF-8
		 *	@param		array 		$style				[ f => base font, z => size, u => underlined ]
		 *
		 *	@return 	array
		 */
		public static function atoms( string $text, array $style ): array {

			$atoms = [];
			$space = self::width( ' ', $style['f'], $style['z'] );

			foreach( preg_split( '/([ \t\r\n\f]+)/', self::encode( $text ), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: [] as $part ) {

				if( trim( $part, " \t\r\n\f" ) === '' )
					$atoms[] = [ 't' => 's', 's' => ' ', 'w' => $space, 'f' => $style['f'], 'z' => $style['z'], 'u' => $style['u'] ];
				else
					$atoms[] = [ 't' => 'w', 's' => $part, 'w' => self::width( $part, $style['f'], $style['z'] ), 'f' => $style['f'], 'z' => $style['z'], 'u' => $style['u'] ];
			}

			return $atoms;
		}

		/**
		 *	Break atoms into lines
		 *
		 *	@param		array 		$atoms
		 *	@param		float			$width				The room of a line in points
		 *	@param		array 		$base					[ f, z ] - the type an empty line, or one with only a picture on it, is as high as
		 *
		 *	@return 	array											Lines: a (atoms), w, z, f, ih (the tallest picture), h (the height) and base (the baseline from the top)
		 */
		public static function wrap( array $atoms, float $width, array $base ): array {

			$lines = [];
			$line = [];
			$used = 0.0;

			// Runs of atoms that touch are one unit; a space or a break is its own
			$units = [];
			$unit = [];

			foreach( $atoms as $atom ) {

				if( $atom['t'] === 'w' || $atom['t'] === 'i' ) {
					$unit[] = $atom;
					continue;
				}

				if( $unit !== [] ) {
					$units[] = $unit;
					$unit = [];
				}

				$units[] = [ $atom ];
			}

			if( $unit !== [] )
				$units[] = $unit;

			foreach( $units as $unit ) {

				$first = $unit[0];

				if( $first['t'] === 'b' ) {
					self::_close( $lines, $line, $used, $base );
					continue;
				}

				if( $first['t'] === 's' ) {
					// Never at the start of a line, never twice in a row
					if( $line !== [] && $line[ count( $line ) - 1 ]['t'] !== 's' ) {
						$line[] = $first;
						$used += $first['w'];
					}
					continue;
				}

				$span = array_sum( array_column( $unit, 'w' ) );

				if( $used + $span <= $width + 0.01 ) {
					array_push( $line, ...$unit );
					$used += $span;
					continue;
				}

				// It does not fit here: a line of its own, if it fits there
				if( $line !== [] && $span <= $width + 0.01 ) {
					self::_close( $lines, $line, $used, $base );
					array_push( $line, ...$unit );
					$used = $span;
					continue;
				}

				if( $line !== [] )
					self::_close( $lines, $line, $used, $base );

				foreach( $unit as $atom ) {

					if( $used > 0 && $used + $atom['w'] > $width + 0.01 )
						self::_close( $lines, $line, $used, $base );

					if( $atom['t'] === 'w' && $atom['w'] > $width + 0.01 ) {

						foreach( self::_pieces( $atom, $width ) as $at => $piece ) {

							if( $at > 0 || ( $used > 0 && $used + $piece['w'] > $width + 0.01 ) )
								self::_close( $lines, $line, $used, $base );

							$line[] = $piece;
							$used += $piece['w'];
						}

						continue;
					}

					$line[] = $atom;
					$used += $atom['w'];
				}
			}

			if( $line !== [] || $lines === [] )
				self::_close( $lines, $line, $used, $base );

			return $lines;
		}

		/**
		 *	End the line that is being filled: the spaces at its end go, it is measured and kept, and the next is empty
		 *
		 *	@param		array 		&$lines				The finished lines
		 *	@param		array 		&$line				The atoms of the line being filled
		 *	@param		float			&$used				Its width
		 *	@param		array 		$base
		 *
		 *	@return 	void
		 */
		private static function _close( array &$lines, array &$line, float &$used, array $base ): void {

			while( $line !== [] && $line[ count( $line ) - 1 ]['t'] === 's' ) {
				$used -= $line[ count( $line ) - 1 ]['w'];
				array_pop( $line );
			}

			$lines[] = self::_line( $line, $used, $base );
			$line = [];
			$used = 0.0;
		}

		/**
		 *	A word cut into pieces that fit the width, character by character
		 *
		 *	@param		array 		$atom
		 *	@param		float			$width
		 *
		 *	@return 	array
		 */
		private static function _pieces( array $atom, float $width ): array {

			$pieces = [];
			$text = '';

			foreach( str_split( $atom['s'] ) as $char ) {

				if( $text !== '' && self::width( $text. $char, $atom['f'], $atom['z'] ) > $width + 0.01 ) {
					$pieces[] = [ 's' => $text, 'w' => self::width( $text, $atom['f'], $atom['z'] ) ] + $atom;
					$text = '';
				}

				$text .= $char;
			}

			if( $text !== '' )
				$pieces[] = [ 's' => $text, 'w' => self::width( $text, $atom['f'], $atom['z'] ) ] + $atom;

			return $pieces;
		}

		/**
		 *	One finished line with its measures
		 *
		 *	@param		array 		$atoms
		 *	@param		float			$used
		 *	@param		array 		$base
		 *
		 *	@return 	array
		 */
		private static function _line( array $atoms, float $used, array $base ): array {

			$size = 0.0;
			$font = $base['f'];
			$picture = 0.0;

			foreach( $atoms as $atom ) {

				if( $atom['t'] === 'i' )
					$picture = max( $picture, $atom['h'] );
				else if( $atom['z'] > $size ) {
					$size = $atom['z'];
					$font = $atom['f'];
				}
			}

			$type = $size > 0 ? $size : $base['z'];
			[ $up, $down ] = Metrics::vertical( $font );
			$lead = self::LEADING * $type;
			$rise = ( $lead - ( $up - $down ) * $type / 1000 ) / 2 + $up * $type / 1000;
			$fall = $lead - $rise;

			if( $picture > $rise )
				$rise = $picture;

			return [ 'a' => $atoms, 'w' => $used, 'z' => $type, 'f' => $font, 'ih' => $picture, 'h' => $rise + $fall, 'base' => $rise ];
		}
	}
}

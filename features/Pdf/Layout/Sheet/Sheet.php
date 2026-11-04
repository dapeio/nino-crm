<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Layout\Sheet	The design of a PDF as a sheet in CSS notation
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	use Nino\Modules\Pdf\Document\Document;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Sheet							The rules a PDF is set with, written as CSS: a documented subset that the
	 *										feature reads itself, with no library.
	 *
	 *										The feature has a sheet of its own - defaults(), which text() writes out
	 *										as CSS and which the engine reads, so that the numbers the engine sets
	 *										with are the numbers the panel shows - and the owner may write rules
	 *										over it, a text in the same notation. merge() lays the second over the
	 *										first, property by property: a rule of the owner wins where it names a
	 *										property, and every other property stays what the feature sets.
	 *
	 *										Selectors: body, h1, h2, h3, p, li, ul, ol, table, th, td, hr, a and the
	 *										classes .address (a p or a div with class="address"), .header and
	 *										.footer (the text of the header and of the footer) and .signature (the
	 *										box of a signature field and its label). A list of selectors with commas
	 *										is a rule for each.
	 *
	 *										Properties, with the selectors they are read for:
	 *
	 *										  font-family       Helvetica, Times or Courier
	 *										  font-size         12pt, or 1.4em: a factor of the base size
	 *										  font-weight       normal or bold
	 *										  font-style        normal or italic
	 *										  color             #rrggbb
	 *										  line-height       a factor of the font size, 1.35
	 *										  text-align        left, center or right
	 *										  margin            millimetres, one value, two (vertical horizontal),
	 *										                    three (top horizontal bottom) or four (top right bottom left); the top and the
	 *										                    bottom are the space above and below a block, the
	 *										                    left of ul and ol is the indent of a level, the
	 *										                    bottom of .header and the top of .footer the gap
	 *										                    to the text, the top of .signature the gap between
	 *										                    the line and the label; li reads the bottom only.
	 *										                    A side nothing reads (see MARGINS) takes 0 and
	 *										                    is refused with any other value
	 *										  padding           millimetres, as margin; th and td
	 *										  border-top        <pt> solid #rrggbb, or 0; the line over a row of th
	 *										  border-bottom     and td, under a row; hr and .signature (top), the
	 *										                    line under .header (bottom) and over .footer (top)
	 *										  background        #rrggbb; th and td
	 *										  text-decoration   none or underline; a
	 *
	 *										check() reads a text into its rules or names what is wrong - the line,
	 *										the kind and the word - and parse() is the same as one sentence. A rule
	 *										is a map selector => property => value, the values in the shapes that
	 *										the table in SHAPES names; an absent property is not set.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Sheet {

		public const array SELECTORS = [ 'body', 'h1', 'h2', 'h3', 'p', 'li', 'ul', 'ol', 'table', 'th', 'td', 'hr', 'a', '.address', '.header', '.footer', '.signature' ];

		public const array PROPERTIES = [ 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'line-height', 'text-align', 'margin', 'padding', 'border-top', 'border-bottom', 'background', 'text-decoration' ];

		// What a property is once it is read: font-family a lower case family, font-size [ pt|em, number ], font-weight and font-style a word, color and background six hex digits
		// in lower case, line-height a number, text-align and text-decoration a word, margin and padding [ top, right, bottom, left ] in mm, border-* [ pt, color ] or false
		public const array SHAPES = [
			'font-family' => 'string', 'font-size' => 'array', 'font-weight' => 'string', 'font-style' => 'string', 'color' => 'string', 'line-height' => 'float',
			'text-align' => 'string', 'margin' => 'array', 'padding' => 'array', 'border-top' => 'array|false', 'border-bottom' => 'array|false', 'background' => 'string', 'text-decoration' => 'string',
		];

		// The properties of a text
		private const array TYPE = [ 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'line-height', 'text-align' ];

		// The properties each selector is read for
		public const array APPLIES = [
			'body'			=> [ 'font-family', 'font-size', 'font-weight', 'font-style', 'color', 'line-height' ],
			'h1'				=> [ ...self::TYPE, 'margin' ],
			'h2'				=> [ ...self::TYPE, 'margin' ],
			'h3'				=> [ ...self::TYPE, 'margin' ],
			'p'					=> [ ...self::TYPE, 'margin' ],
			'li'				=> [ ...self::TYPE, 'margin' ],
			'ul'				=> [ 'margin' ],
			'ol'				=> [ 'margin' ],
			'table'			=> [ 'margin' ],
			'th'				=> [ ...self::TYPE, 'padding', 'border-top', 'border-bottom', 'background' ],
			'td'				=> [ ...self::TYPE, 'padding', 'border-top', 'border-bottom', 'background' ],
			'hr'				=> [ 'margin', 'border-top' ],
			'a'					=> [ 'font-weight', 'font-style', 'color', 'text-decoration' ],
			'.address'	=> [ ...self::TYPE, 'margin' ],
			'.header'		=> [ ...self::TYPE, 'margin', 'border-bottom' ],
			'.footer'		=> [ ...self::TYPE, 'margin', 'border-top' ],
			'.signature'=> [ 'font-family', 'font-size', 'color', 'margin', 'border-top' ],
		];

		// The sides of a margin each selector reads - top, right, bottom, left. A value that is not zero on a side that is not read is refused:
		// it would do nothing. h1-h3, p, .address, table and hr: the space above and below; li: below; ul and ol: above, below and the indent;
		// .header: the gap under it; .footer: the gap over it; .signature: the gap between the line and the label
		public const array MARGINS = [
			'h1' => [ true, false, true, false ], 'h2' => [ true, false, true, false ], 'h3' => [ true, false, true, false ], 'p' => [ true, false, true, false ], '.address' => [ true, false, true, false ],
			'table' => [ true, false, true, false ], 'hr' => [ true, false, true, false ], 'li' => [ false, false, true, false ], 'ul' => [ true, false, true, true ], 'ol' => [ true, false, true, true ],
			'.header' => [ false, false, true, false ], '.footer' => [ true, false, false, false ], '.signature' => [ true, false, false, false ],
		];

		private const array SIDES = [ 'top', 'right', 'bottom', 'left' ];

		// The ranges a number is held to
		public const array RANGES = [ 'pt' => [ 4.0, 96.0 ], 'em' => [ 0.3, 10.0 ], 'line' => [ 0.8, 4.0 ], 'margin' => [ 0.0, 100.0 ], 'padding' => [ 0.0, 50.0 ], 'border' => [ 0.0, 10.0 ] ];

		private const array FAMILIES = [ 'helvetica', 'times', 'courier' ];

		/**
		 *	The rules the feature sets with, for a base font and a base size: the numbers the engine used to hold as constants.
		 *	Distances that scale with the size - the space after a paragraph, the space around a heading - are written in
		 *	millimetres at this size
		 *
		 *	@param		string		$family				helvetica, times or courier
		 *	@param		int|float	$size					The base size in points
		 *
		 *	@return 	array
		 */
		public static function defaults( string $family, int|float $size ): array {

			// A distance of lines of the base size, in millimetres: the line is 1.35 times the size
			$lines = static fn( float $count ): float => round( $count * Text::LEADING * (float) $size / Document::MM, 7 );

			return [
				'body'			=> [ 'font-family' => $family, 'font-size' => [ 'pt', (float) $size ], 'font-weight' => 'normal', 'font-style' => 'normal', 'color' => '000000', 'line-height' => Text::LEADING ],
				'h1'				=> [ 'font-size' => [ 'em', 1.8 ], 'font-weight' => 'bold', 'margin' => [ $lines( 1.2 ), 0.0, $lines( 0.5 ), 0.0 ] ],
				'h2'				=> [ 'font-size' => [ 'em', 1.4 ], 'font-weight' => 'bold', 'margin' => [ $lines( 1.0 ), 0.0, $lines( 0.4 ), 0.0 ] ],
				'h3'				=> [ 'font-size' => [ 'em', 1.15 ], 'font-weight' => 'bold', 'margin' => [ $lines( 0.8 ), 0.0, $lines( 0.3 ), 0.0 ] ],
				'p'					=> [ 'margin' => [ 0.0, 0.0, $lines( 0.6 ), 0.0 ] ],
				'li'				=> [ 'margin' => [ 0.0, 0.0, $lines( 0.15 ), 0.0 ] ],
				'ul'				=> [ 'margin' => [ 0.0, 0.0, $lines( 0.6 ), 6.0 ] ],
				'ol'				=> [ 'margin' => [ 0.0, 0.0, $lines( 0.6 ), 6.0 ] ],
				'table'			=> [ 'margin' => [ 0.0, 0.0, $lines( 0.6 ), 0.0 ] ],
				'th'				=> [ 'font-weight' => 'bold', 'text-align' => 'left', 'padding' => [ 1.5, 1.5, 1.5, 1.5 ], 'border-top' => false, 'border-bottom' => [ 0.8, '000000' ] ],
				'td'				=> [ 'text-align' => 'left', 'padding' => [ 1.5, 1.5, 1.5, 1.5 ], 'border-top' => false, 'border-bottom' => [ 0.4, '808080' ] ],
				'hr'				=> [ 'margin' => [ $lines( 0.4 ), 0.0, $lines( 0.4 ), 0.0 ], 'border-top' => [ 0.5, '8c8c8c' ] ],
				'a'					=> [ 'text-decoration' => 'underline' ],
				'.address'	=> [],
				'.header'		=> [ 'margin' => [ 0.0, 0.0, 4.0, 0.0 ], 'border-bottom' => false ],
				'.footer'		=> [ 'margin' => [ 4.0, 0.0, 0.0, 0.0 ], 'border-top' => false ],
				'.signature'=> [ 'font-size' => [ 'em', 0.8 ], 'margin' => [ 1.0, 0.0, 0.0, 0.0 ], 'border-top' => [ 0.5, '000000' ] ],
			];
		}

		/**
		 *	Rules laid over rules, property by property: what the owner names wins, the rest stays
		 *
		 *	@param		array 		$defaults
		 *	@param		array 		$overrides
		 *
		 *	@return 	array
		 */
		public static function merge( array $defaults, array $overrides ): array {

			foreach( $overrides as $selector => $properties )
				$defaults[$selector] = array_replace( $defaults[$selector] ?? [], $properties );

			return $defaults;
		}

		/**
		 *	The rules as the CSS text that is read back into the same rules
		 *
		 *	@param		array 		$rules
		 *
		 *	@return 	string
		 */
		public static function text( array $rules ): string {

			$out = [];

			foreach( self::SELECTORS as $selector ) {

				if( isset( $rules[$selector] ) === false )
					continue;

				$declarations = [];

				foreach( self::PROPERTIES as $property )
					if( array_key_exists( $property, $rules[$selector] ) === true )
						$declarations[] = $property. ': '. self::_write( $property, $rules[$selector][$property] );

				$out[] = str_pad( $selector, 11 ). ' { '. implode( '; ', $declarations ). ( $declarations === [] ? '' : ' ' ). '}';
			}

			return implode( "\n", $out );
		}

		/**
		 *	A text read into rules, or the sentence that says what is wrong with it, line included
		 *
		 *	@param		string		$css
		 *
		 *	@return 	array|string
		 */
		public static function parse( string $css ): array|string {

			$read = self::check( $css );

			if( isset( $read['rules'] ) === true )
				return $read['rules'];

			return 'line '. $read['line']. ': '. match( $read['code'] ) {
				'selector'	=> 'the selector "'. $read['detail']. '" is not one the sheet knows',
				'property'	=> 'the property "'. $read['detail']. '" is not one the sheet knows',
				'applies'		=> 'the property "'. $read['detail']. '" is not read for "'. $read['selector']. '"',
				'value'			=> 'the value of "'. $read['detail']. '" is not one the sheet takes',
				'comment'		=> 'a comment is not closed',
				'semicolon'	=> 'a semicolon is missing after the declaration in line '. $read['line'],
				'noselector'=> 'a rule needs a selector',
				'brace'			=> 'the braces of a rule do not match - a rule is selector { property: value; }',
				default			=> '"'. $read['detail']. '" is not a declaration - write property: value',
			};
		}

		/**
		 *	A text read into rules, or the first thing that is wrong with it
		 *
		 *	@param		string		$css
		 *
		 *	@return 	array											[ rules => ... ] or [ line, code (brace, comment, semicolon, noselector, declaration, selector, property, applies, value), detail, selector ]
		 */
		public static function check( string $css ): array {

			$css = str_replace( [ "\r\n", "\r" ], "\n", $css );

			// A comment is blanked and keeps its newlines, so that the lines stay where they were; one that is not closed is refused at its opening
			$blank = '';
			$from = 0;

			while( ( $open = strpos( $css, '/*', $from ) ) !== false ) {

				$close = strpos( $css, '*/', $open + 2 );

				if( $close === false )
					return self::_refuse( 1 + substr_count( $css, "\n", 0, $open ), 'comment', '' );

				$blank .= substr( $css, $from, $open - $from ). (string) preg_replace( '/[^\n]/', ' ', substr( $css, $open, $close + 2 - $open ) );
				$from = $close + 2;
			}

			$css = $blank. substr( $css, $from );
			$rules = [];
			$length = strlen( $css );
			$at = 0;
			$line = 1;

			while( true ) {

				// Space before a rule
				while( $at < $length && ctype_space( $css[$at] ) === true ) {
					$line += $css[$at] === "\n" ? 1 : 0;
					$at++;
				}

				if( $at >= $length )
					break;

				$begin = $line;
				$head = '';

				while( $at < $length && $css[$at] !== '{' && $css[$at] !== '}' ) {
					$line += $css[$at] === "\n" ? 1 : 0;
					$head .= $css[$at++];
				}

				if( $at >= $length )
					return self::_refuse( $begin, 'brace', '' );

				if( $css[$at] === '}' )
					return self::_refuse( $line, 'brace', '' );

				$at++;

				$selectors = [];

				$offset = 0;

				foreach( explode( ',', $head ) as $part ) {

					$selector = trim( $part );
					$lower = strtolower( $selector );

					// The line of this selector, not of the list's first
					$where = $begin + substr_count( $head, "\n", 0, $offset + strlen( $part ) - strlen( ltrim( $part ) ) );
					$offset += strlen( $part ) + 1;

					if( $selector === '' )
						return self::_refuse( $where, 'noselector', '' );

					if( in_array( $lower, self::SELECTORS, true ) === false )
						return self::_refuse( $where, 'selector', $selector );

					$selectors[] = $lower;
				}

				// The declarations up to the closing brace
				$block = '';
				$start = $line;

				while( $at < $length && $css[$at] !== '}' ) {

					if( $css[$at] === '{' )
						return self::_refuse( $line, 'brace', '' );

					$line += $css[$at] === "\n" ? 1 : 0;
					$block .= $css[$at++];
				}

				if( $at >= $length )
					return self::_refuse( $begin, 'brace', '' );

				$at++;
				$row = $start;
				$found = [];

				foreach( explode( ';', $block ) as $declaration ) {

					$lead = strlen( $declaration ) - strlen( ltrim( $declaration ) );
					$place = $row + substr_count( substr( $declaration, 0, $lead ), "\n" );
					$row += substr_count( $declaration, "\n" );

					if( trim( $declaration ) === '' )
						continue;

					$pair = explode( ':', $declaration, 2 );

					if( count( $pair ) !== 2 )
						return self::_refuse( $place, 'declaration', mb_substr( trim( strtok( trim( $declaration ), "\n" ) ?: '' ), 0, 40 ) );

					$property = strtolower( trim( $pair[0] ) );
					$word = trim( $pair[1] );

					// A property that runs over a line is a declaration that lost its colon; a value that runs into a line that starts another declaration lost its semicolon
					if( str_contains( $property, "\n" ) === true )
						return self::_refuse( $place, 'declaration', $property );

					if( preg_match( '/\n\s*[A-Za-z-]+\s*:/', $word ) === 1 )
						return self::_refuse( $place, 'semicolon', $property );

					if( in_array( $property, self::PROPERTIES, true ) === false )
						return self::_refuse( $place, 'property', $property === '' ? ':' : $property );

					foreach( $selectors as $selector )
						if( in_array( $property, self::APPLIES[$selector], true ) === false )
							return self::_refuse( $place, 'applies', $property, $selector );

					$value = self::_value( $property, $word );

					if( $value === null )
						return self::_refuse( $place, 'value', $property. ': '. mb_substr( trim( strtok( $word, "\n" ) ?: '' ), 0, 40 ) );

					// A distance on a side nothing reads would do nothing
					if( $property === 'margin' )
						foreach( $selectors as $selector )
							foreach( self::SIDES as $side => $name )
								if( ( self::MARGINS[$selector][$side] ?? false ) === false && (float) $value[0][$side] !== 0.0 )
									return self::_refuse( $place, 'applies', 'margin-'. $name, $selector );

					$found[$property] = $value[0];
				}

				foreach( $selectors as $selector )
					$rules[$selector] = array_replace( $rules[$selector] ?? [], $found );
			}

			return [ 'rules' => $rules ];
		}

		/**
		 *	The size of a font in points for a rule: points as they are, em a factor of the base
		 *
		 *	@param		array 		$rule					A map property => value
		 *	@param		float			$base
		 *
		 *	@return 	float
		 */
		public static function size( array $rule, float $base ): float {

			$size = $rule['font-size'] ?? null;

			if( is_array( $size ) === false )
				return $base;

			return $size[0] === 'em' ? $base * $size[1] : (float) $size[1];
		}

		/**
		 *	A refusal
		 *
		 *	@param		int				$line
		 *	@param		string		$code
		 *	@param		string		$detail
		 *
		 *	@return 	array
		 */
		private static function _refuse( int $line, string $code, string $detail, string $selector = '' ): array {

			// A detail is one line, whatever the shape of the text that was refused
			return [ 'line' => $line, 'code' => $code, 'detail' => trim( strtok( $detail, "\n" ) ?: '' ), 'selector' => $selector ];
		}

		/**
		 *	One value in its shape
		 *
		 *	@param		string		$property
		 *	@param		string		$word
		 *
		 *	@return 	array|null								[ value ], or null for a value the property does not take
		 */
		private static function _value( string $property, string $word ): ?array {

			$number = '(\d{1,4}(?:\.\d{1,7})?)';
			$word = trim( $word );

			switch( $property ) {

				case 'font-family':
					return in_array( strtolower( $word ), self::FAMILIES, true ) === true ? [ strtolower( $word ) ] : null;

				case 'font-size':

					if( preg_match( '/^'. $number. '(pt|em)$/Di', $word, $found ) !== 1 )
						return null;

					$unit = strtolower( $found[2] );

					return self::_within( (float) $found[1], self::RANGES[$unit] ) ? [ [ $unit, (float) $found[1] ] ] : null;

				case 'font-weight':
					return in_array( strtolower( $word ), [ 'normal', 'bold' ], true ) === true ? [ strtolower( $word ) ] : null;

				case 'font-style':
					return in_array( strtolower( $word ), [ 'normal', 'italic' ], true ) === true ? [ strtolower( $word ) ] : null;

				case 'color':
				case 'background':
					return preg_match( '/^#([0-9a-f]{6})$/Di', $word, $found ) === 1 ? [ strtolower( $found[1] ) ] : null;

				case 'line-height':
					return preg_match( '/^'. $number. '$/D', $word, $found ) === 1 && self::_within( (float) $found[1], self::RANGES['line'] ) ? [ (float) $found[1] ] : null;

				case 'text-align':
					return in_array( strtolower( $word ), [ 'left', 'center', 'right' ], true ) === true ? [ strtolower( $word ) ] : null;

				case 'text-decoration':
					return in_array( strtolower( $word ), [ 'none', 'underline' ], true ) === true ? [ strtolower( $word ) ] : null;

				case 'margin':
				case 'padding':

					$values = preg_split( '/\s+/', $word ) ?: [];

					if( in_array( count( $values ), [ 1, 2, 3, 4 ], true ) === false )
						return null;

					$mm = [];

					foreach( $values as $one ) {

						if( preg_match( '/^'. $number. '(?:mm)?$/Di', $one, $found ) !== 1 || self::_within( (float) $found[1], self::RANGES[$property] ) === false )
							return null;

						$mm[] = (float) $found[1];
					}

					return [ match( count( $mm ) ) { 1 => [ $mm[0], $mm[0], $mm[0], $mm[0] ], 2 => [ $mm[0], $mm[1], $mm[0], $mm[1] ], 3 => [ $mm[0], $mm[1], $mm[2], $mm[1] ], default => $mm } ];

				case 'border-top':
				case 'border-bottom':

					if( $word === '0' )
						return [ false ];

					if( preg_match( '/^'. $number. 'pt\s+solid\s+#([0-9a-f]{6})$/Di', $word, $found ) !== 1 || self::_within( (float) $found[1], self::RANGES['border'] ) === false )
						return null;

					return [ (float) $found[1] === 0.0 ? false : [ (float) $found[1], strtolower( $found[2] ) ] ];
			}

			return null;
		}

		/**
		 *	Whether a number lies within a range
		 *
		 *	@param		float			$number
		 *	@param		array 		$range
		 *
		 *	@return 	bool
		 */
		private static function _within( float $number, array $range ): bool {

			return $number >= $range[0] && $number <= $range[1];
		}

		/**
		 *	A number the shortest way it can be written
		 *
		 *	@param		float			$number
		 *
		 *	@return 	string
		 */
		private static function _number( float $number ): string {

			$text = rtrim( rtrim( number_format( $number, 7, '.', '' ), '0' ), '.' );

			return $text === '' || $text === '-0' ? '0' : $text;
		}

		/**
		 *	One value as CSS
		 *
		 *	@param		string		$property
		 *	@param		mixed			$value
		 *
		 *	@return 	string
		 */
		private static function _write( string $property, mixed $value ): string {

			switch( $property ) {

				case 'font-family':
					return ucfirst( (string) $value );

				case 'font-size':
					return self::_number( (float) $value[1] ). $value[0];

				case 'color':
				case 'background':
					return '#'. $value;

				case 'line-height':
					return self::_number( (float) $value );

				case 'margin':
				case 'padding':
					if( $value[0] === $value[1] && $value[1] === $value[2] && $value[2] === $value[3] )
						$value = [ $value[0] ];
					else if( $value[0] === $value[2] && $value[1] === $value[3] )
						$value = [ $value[0], $value[1] ];
					else if( $value[1] === $value[3] )
						$value = [ $value[0], $value[1], $value[2] ];

					return implode( ' ', array_map( static fn( float $mm ): string => $mm == 0.0 ? '0' : self::_number( $mm ). 'mm', $value ) );

				case 'border-top':
				case 'border-bottom':
					return $value === false ? '0' : self::_number( (float) $value[0] ). 'pt solid #'. $value[1];
			}

			return (string) $value;
		}
	}
}

<?php
declare(strict_types=1);
/**
 *	Nino								A compact filesystembased php framework
 *	Modules\Pdf\Layout\Html	The small subset of HTML a layout understands, read into blocks
 *
 *	@package						Dape/Nino
 *	@author							David Perchermeier <mail@dape.io>
 *	@link								https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	use Nino\Modules\Pdf\Fonts\Metrics;
	use Nino\Modules\Pdf\Document\Document;
	use Nino\Modules\Pdf\Images\Image;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Html							A string of HTML parsed with \DOMDocument into blocks the engine
	 *										lays out: paragraphs of measured atoms, rules and tables.
	 *
	 *										Understood: h1, h2 and h3 (bold, 1.8, 1.4 and 1.15 times the base
	 *										size, space above), p and div (a paragraph, 0.6 line after), br,
	 *										strong and b, em and i (both: bold italic), ul, ol and li (indented
	 *										6 mm, a bullet or the number), a (its text, underlined), hr, table
	 *										with thead, tbody, tfoot, tr, th and td, span (its text), img with
	 *										the src pdf:logo and a width in mm, and align="right|center" or
	 *										style="text-align:..." on a paragraph, a heading or a cell.
	 *
	 *										That is the list of Allow, and the reader is handed nothing else:
	 *										the text is parsed and held to the list before it is read (see
	 *										Allow::body()), so whatever a caller hands in as a slot, and the
	 *										header and the footer, are held to it as a saved block is. A tag
	 *										that is not listed has been unwrapped to its text, every attribute
	 *										not named there is gone, and a script or a style is not text at
	 *										all.
	 *
	 *										A block is a map. p: atoms, align, indent, marker (the atoms of a
	 *										bullet or a number, or null), before, after, keep (a heading, kept
	 *										with what follows it) and base (the type of an empty line);
	 *										hr: before, after; table: rows - head, and the cells: blocks,
	 *										th, pct (the width the first row asked for) - border, before and
	 *										after. All distances are in points.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Html {

		// The space after a paragraph, a list and a table, and between the items of a list, in lines of the base size
		private const float AFTER = 0.6;
		private const float ITEM = 0.15;

		// tag => size factor, lines above, lines below
		private const array HEADINGS = [ 'h1' => [ 1.8, 1.2, 0.5 ], 'h2' => [ 1.4, 1.0, 0.4 ], 'h3' => [ 1.15, 0.8, 0.3 ] ];

		/**
		 *	Parse html into blocks
		 *
		 *	@param		string		$html
		 *	@param		array 		$base					[ family => helvetica|times|courier, size => the base size in points ]
		 *	@param		Image|null	$logo				What src="pdf:logo" is
		 *	@param		float			$width				The width of the column in points, which a picture is held to
		 *
		 *	@return 	array
		 */
		public static function blocks( string $html, array $base, ?Image $logo, float $width ): array {

			$body = Allow::body( $html );

			if( $body === null )
				return [];

			$ctx = [ 'family' => $base['family'], 'size' => (float) $base['size'], 'logo' => $logo, 'width' => $width, 'indent' => 0.0, 'align' => 'left', 'depth' => 0, 'cell' => false ];

			return self::_content( $body, [ 'b' => false, 'i' => false, 'u' => false, 'z' => $ctx['size'] ], $ctx );
		}

		/**
		 *	The blocks the children of a node make
		 *
		 *	@param		\DOMNode	$node
		 *	@param		array 		$style
		 *	@param		array 		$ctx
		 *
		 *	@return 	array
		 */
		private static function _content( \DOMNode $node, array $style, array $ctx ): array {

			$blocks = [];
			$inline = [];

			foreach( iterator_to_array( $node->childNodes ) as $child )
				self::_node( $child, $style, $ctx, $blocks, $inline );

			self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * Text::LEADING * $ctx['size'], false );

			return $blocks;
		}

		/**
		 *	One node: text goes to the inline run, a block closes the run and makes blocks of its own
		 *
		 *	@param		\DOMNode	$node
		 *	@param		array 		$style
		 *	@param		array 		$ctx
		 *	@param		array 		&$blocks
		 *	@param		array 		&$inline
		 *
		 *	@return 	void
		 */
		private static function _node( \DOMNode $node, array $style, array $ctx, array &$blocks, array &$inline ): void {

			if( $node instanceof \DOMText ) {
				array_push( $inline, ...Text::atoms( $node->data, self::_type( $style, $ctx ) ) );
				return;
			}

			if( $node instanceof \DOMElement === false )
				return;

			$tag = strtolower( $node->nodeName );
			$line = Text::LEADING * $ctx['size'];

			switch( $tag ) {

				case 'br':
					$inline[] = [ 't' => 'b', 'z' => $style['z'] ];
					return;

				case 'strong':
				case 'b':
					$style['b'] = true;
					break;

				case 'em':
				case 'i':
					$style['i'] = true;
					break;

				case 'a':
					$style['u'] = true;
					break;

				case 'img':
					$atom = self::_picture( $node, $ctx );
					if( $atom !== null )
						$inline[] = $atom;
					return;

				case 'hr':
					self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * $line, false );
					$blocks[] = [ 'k' => 'hr', 'before' => 0.4 * $line, 'after' => 0.4 * $line ];
					return;

				case 'h1':
				case 'h2':
				case 'h3':
					[ $scale, $above, $below ] = self::HEADINGS[$tag];
					self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * $line, false );
					$style['b'] = true;
					$style['z'] = $ctx['size'] * $scale;
					$ctx['align'] = self::_align( $node, $ctx['align'] );
					$inner = [];
					foreach( iterator_to_array( $node->childNodes ) as $child )
						self::_node( $child, $style, $ctx, $blocks, $inner );
					self::_flush( $blocks, $inner, $ctx, $style, $above * $line, $below * $line, true );
					return;

				case 'p':
				case 'div':
					self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * $line, false );
					$ctx['align'] = self::_align( $node, $ctx['align'] );
					$inner = [];
					foreach( iterator_to_array( $node->childNodes ) as $child )
						self::_node( $child, $style, $ctx, $blocks, $inner );
					self::_flush( $blocks, $inner, $ctx, $style, 0.0, self::AFTER * $line, false );
					return;

				case 'ul':
				case 'ol':
					self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * $line, false );
					self::_list( $node, $style, $ctx, $blocks );
					return;

				case 'table':
					if( $ctx['cell'] === false ) {
						self::_flush( $blocks, $inline, $ctx, $style, 0.0, self::AFTER * $line, false );
						$blocks[] = self::_table( $node, $style, $ctx );
						return;
					}
					break;
			}

			foreach( iterator_to_array( $node->childNodes ) as $child )
				self::_node( $child, $style, $ctx, $blocks, $inline );
		}

		/**
		 *	The atoms' type for a style: the base font of the family, the size, the underline
		 *
		 *	@param		array 		$style
		 *	@param		array 		$ctx
		 *
		 *	@return 	array
		 */
		private static function _type( array $style, array $ctx ): array {

			return [ 'f' => Metrics::font( $ctx['family'], $style['b'], $style['i'] ), 'z' => (float) $style['z'], 'u' => $style['u'] ];
		}

		/**
		 *	The inline run as a paragraph - when there is anything in it
		 *
		 *	@param		array 		&$blocks
		 *	@param		array 		&$inline			Emptied
		 *	@param		array 		$ctx
		 *	@param		array 		$style
		 *	@param		float			$before
		 *	@param		float			$after
		 *	@param		bool			$keep
		 *
		 *	@return 	void
		 */
		private static function _flush( array &$blocks, array &$inline, array $ctx, array $style, float $before, float $after, bool $keep ): void {

			$atoms = $inline;
			$inline = [];

			while( $atoms !== [] && $atoms[0]['t'] === 's' )
				array_shift( $atoms );

			while( $atoms !== [] && $atoms[ count( $atoms ) - 1 ]['t'] === 's' )
				array_pop( $atoms );

			if( $atoms === [] )
				return;

			$type = self::_type( $style, $ctx );

			$blocks[] = [ 'k' => 'p', 'atoms' => $atoms, 'align' => $ctx['align'], 'indent' => $ctx['indent'], 'marker' => null, 'before' => $before, 'after' => $after, 'keep' => $keep, 'base' => [ 'f' => $type['f'], 'z' => $type['z'] ] ];
		}

		/**
		 *	A list: one paragraph for the text of each item, the first of an item with its marker
		 *
		 *	@param		\DOMElement	$list
		 *	@param		array 			$style
		 *	@param		array 			$ctx
		 *	@param		array 			&$blocks
		 *
		 *	@return 	void
		 */
		private static function _list( \DOMElement $list, array $style, array $ctx, array &$blocks ): void {

			$ctx['depth']++;
			$ctx['indent'] = $ctx['depth'] * 6 * Document::MM;
			$ordered = strtolower( $list->nodeName ) === 'ol';
			$number = 0;
			$line = Text::LEADING * $ctx['size'];

			foreach( iterator_to_array( $list->childNodes ) as $item ) {

				if( $item instanceof \DOMElement === false || strtolower( $item->nodeName ) !== 'li' )
					continue;

				$inner = [];
				$inline = [];

				foreach( iterator_to_array( $item->childNodes ) as $child )
					self::_node( $child, $style, $ctx, $inner, $inline );

				self::_flush( $inner, $inline, $ctx, $style, 0.0, self::ITEM * $line, false );
				$number++;

				foreach( $inner as $at => $block )
					if( $block['k'] === 'p' ) {
						$inner[$at]['marker'] = Text::atoms( $ordered === true ? $number. '.' : "\u{2022}", self::_type( $style, $ctx ) );
						break;
					}

				array_push( $blocks, ...$inner );
			}

			if( $ctx['depth'] === 1 && $blocks !== [] && $blocks[ count( $blocks ) - 1 ]['k'] === 'p' )
				$blocks[ count( $blocks ) - 1 ]['after'] = self::AFTER * $line;
		}

		/**
		 *	A table: rows of cells, each cell the blocks of its content
		 *
		 *	@param		\DOMElement	$table
		 *	@param		array 			$style
		 *	@param		array 			$ctx
		 *
		 *	@return 	array
		 */
		private static function _table( \DOMElement $table, array $style, array $ctx ): array {

			$rows = [];

			$read = static function( \DOMElement $tr, bool $head ) use ( &$rows, $style, $ctx ): void {

				$cells = [];

				foreach( iterator_to_array( $tr->childNodes ) as $cell ) {

					$tag = $cell instanceof \DOMElement ? strtolower( $cell->nodeName ) : '';

					if( $tag !== 'td' && $tag !== 'th' )
						continue;

					$inner = $ctx;
					$inner['cell'] = true;
					$inner['indent'] = 0.0;
					$inner['depth'] = 0;
					$inner['align'] = self::_align( $cell, 'left' );
					$look = $style;
					$look['b'] = $tag === 'th' ? true : $look['b'];

					$cells[] = [
						'blocks'	=> self::_content( $cell, $look, $inner ),
						'th'			=> $tag === 'th',
						'pct'			=> preg_match( '/^\s*(\d+(?:\.\d+)?)\s*%\s*$/', $cell->getAttribute( 'width' ), $found ) === 1 ? (float) $found[1] : null,
					];
				}

				if( $cells !== [] )
					$rows[] = [ 'head' => $head, 'cells' => $cells ];
			};

			foreach( iterator_to_array( $table->childNodes ) as $child ) {

				if( $child instanceof \DOMElement === false )
					continue;

				$tag = strtolower( $child->nodeName );

				if( $tag === 'tr' )
					$read( $child, false );
				else if( $tag === 'thead' || $tag === 'tbody' || $tag === 'tfoot' )
					foreach( iterator_to_array( $child->childNodes ) as $tr )
						if( $tr instanceof \DOMElement && strtolower( $tr->nodeName ) === 'tr' )
							$read( $tr, $tag === 'thead' );
			}

			return [ 'k' => 'table', 'rows' => $rows, 'border' => trim( $table->getAttribute( 'border' ) ) !== '0', 'before' => 0.0, 'after' => self::AFTER * Text::LEADING * $ctx['size'] ];
		}

		/**
		 *	The alignment an element asks for, else the one it stands in
		 *
		 *	@param		\DOMElement	$element
		 *	@param		string			$current
		 *
		 *	@return 	string											left, right or center
		 */
		private static function _align( \DOMElement $element, string $current ): string {

			$align = strtolower( trim( $element->getAttribute( 'align' ) ) );

			if( in_array( $align, [ 'left', 'right', 'center' ], true ) === true )
				return $align;

			if( preg_match( '/text-align\s*:\s*(left|right|center)/i', $element->getAttribute( 'style' ), $found ) === 1 )
				return strtolower( $found[1] );

			return $current;
		}

		/**
		 *	The picture atom of an img: the logo, as wide as asked for in mm - 150 dpi where it says
		 *	nothing - and never wider than the column; any other src leaves nothing
		 *
		 *	@param		\DOMElement	$img
		 *	@param		array 			$ctx
		 *
		 *	@return 	array|null
		 */
		private static function _picture( \DOMElement $img, array $ctx ): ?array {

			$logo = $ctx['logo'];

			if( $logo === null || strtolower( trim( $img->getAttribute( 'src' ) ) ) !== 'pdf:logo' )
				return null;

			$wide = preg_match( '/^\s*(\d+(?:\.\d+)?)\s*(?:mm)?\s*$/', $img->getAttribute( 'width' ), $found ) === 1 ? (float) $found[1] * Document::MM : $logo->width() * 72 / 150;
			$wide = min( max( $wide, 1.0 ), max( $ctx['width'] - $ctx['indent'], 1.0 ) );

			return [ 't' => 'i', 'w' => $wide, 'h' => $wide * $logo->height() / $logo->width(), 'i' => $logo ];
		}
	}
}

<?php
declare(strict_types=1);
/**
 *	Nino									A compact filesystembased php framework
 *	Modules\Pdf\Layout\Engine	HTML to pages: lines, boxes, page breaks, header, footer, fields
 *
 *	@package							Dape/Nino
 *	@author								David Perchermeier <mail@dape.io>
 *	@link									https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	use Nino\Modules\Pdf\Document\Document;
	use Nino\Modules\Pdf\Images\Image;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Engine						Lays a document out into a \Nino\Modules\Pdf\Document\Document.
	 *
	 *										It is handed the layout (the paper, the margins, the font and the
	 *										base size), the logo, and run() the parts of the document in order
	 *										- html, a text a person typed, a page break - with the HTML of the
	 *										header and the footer and the fields to draw at a position. The
	 *										parts are parsed into blocks (see Html), the blocks wrapped into
	 *										lines (see Text) and the lines set on pages from the top of the
	 *										column down, with a gap between two blocks that is the larger of the
	 *										space after the first and the space before the second, no gap at
	 *										the top of a page, two lines of a paragraph kept together at either
	 *										end of a page break and a heading kept with what follows it. A
	 *										table is placed row by row (see Table).
	 *
	 *										The page is the paper less the margins. The header band is the
	 *										header HTML laid out at the top of the column and the footer band
	 *										the footer HTML at its bottom, each at most 40 mm and each the same
	 *										height on every page, with a gap of 4 mm to the text; the text
	 *										flows in what is left. They are laid out twice: once to measure
	 *										them, with the page numbers 1 and 1, and once for each page when
	 *										the document has all its pages and [[page]] and [[pages]] can be
	 *										filled in. The fields - a signature box at x, y, w and h in
	 *										millimetres from the top-left corner of the paper, on a page or the
	 *										last - are drawn last and by position alone. A part handed in to be
	 *										appended is laid out after the last block on a page of its own, with
	 *										the header and the footer like any other page; the last page of a
	 *										field is the template's, the one before it, and the fields lie on the
	 *										template's pages only.
	 *
	 *										An item is what the engine draws, a positional array: r (a run of
	 *										text: x, baseline, font, size, bytes, underlined, width), i (a
	 *										picture: x, top, w, h, the Image) and l (a horizontal line: x, y,
	 *										length, thickness, grey). Their coordinates are points from the
	 *										top-left corner of the box they belong to.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Engine {

		// The paper in millimetres: width, height
		public const array PAPER = [ 'a4' => [ 210.0, 297.0 ], 'letter' => [ 215.9, 279.4 ] ];

		// The height a header or a footer may take, and the gap between it and the text, in millimetres
		public const float BAND = 40.0;
		public const float GAP = 4.0;

		// The small size of a field's label, in times the base size
		public const float SMALL = 0.8;

		// The page being filled, and where
		private int $_page = -1;
		private float $_y = 0.0;
		private float $_gap = 0.0;
		private bool $_fresh = true;
		private bool $_break = false;

		// How many pages are the template's own: the pages before an appended part, 0 until there is one
		private int $_body = 0;

		// The column: where the text starts and stops on every page
		private float $_left = 0.0;
		private float $_width = 0.0;
		private float $_top = 0.0;
		private float $_bottom = 0.0;
		private float $_paper = 0.0;
		private float $_sheet = 0.0;

		/**
		 *	@param		Document		$_doc
		 *	@param		array 			$_layout				format, margins ([ top, right, bottom, left ] in mm), font, size, header, footer
		 *	@param		Image|null	$_logo
		 */
		public function __construct( private Document $_doc, private array $_layout, private ?Image $_logo ) {
		}

		/**
		 *	Set a whole document
		 *
		 *	@param		array 		$parts					[ k => html, html ] | [ k => text, text, multiline ] | [ k => break ] | [ k => mark ] - the place of a field on the last page | [ k => append, html ] - laid out on a page of its own after the others
		 *	@param		string		$header					The header HTML, [[page]] and [[pages]] still in it
		 *	@param		string		$footer					The footer HTML
		 *	@param		array 		$fields					[ page (last or a number), x, y, w, h (mm), line, label, image (Image|null) ]
		 *
		 *	@return 	void
		 */
		public function run( array $parts, string $header, string $footer, array $fields ): void {

			[ $paperW, $paperH ] = self::PAPER[ $this->_layout['format'] ] ?? self::PAPER['a4'];
			[ $top, $right, $bottom, $left ] = array_map( static fn( mixed $mm ): float => (float) $mm * Document::MM, $this->_layout['margins'] );

			$this->_paper = $paperW * Document::MM;
			$this->_sheet = $paperH * Document::MM;
			$this->_left = $left;
			$this->_width = $this->_paper - $left - $right;

			$headH = min( $this->_band( $header, 1, 1 )['h'], self::BAND * Document::MM );
			$footH = min( $this->_band( $footer, 1, 1 )['h'], self::BAND * Document::MM );

			$this->_top = $top + $headH + ( $headH > 0 ? self::GAP * Document::MM : 0.0 );
			$this->_bottom = $this->_sheet - $bottom - $footH - ( $footH > 0 ? self::GAP * Document::MM : 0.0 );

			$this->newPage();

			foreach( $parts as $part ) {

				if( $part['k'] === 'break' ) {
					$this->_break = true;
					continue;
				}

				// A field on the last page stands where the text ends: a page break before it is a page of its own
				if( $part['k'] === 'mark' ) {

					if( $this->_break === true && $this->_fresh === false )
						$this->newPage();

					$this->_break = false;
					continue;
				}

				// The appended part begins a page of its own, after whatever the template set - an empty page of the template
				// included - and the pages before it are the template's: its fields end there. Nothing to set is no page. A page
				// break of the template that is still waiting is spent by the first block, which stands on a fresh page
				if( $part['k'] === 'append' ) {

					$blocks = Html::blocks( $part['html'], $this->_base(), $this->_logo, $this->_width );

					if( $blocks === [] )
						continue;

					$this->_body = $this->_doc->pages();
					$this->newPage();

					foreach( $blocks as $block )
						$this->_flow( $block );

					continue;
				}

				foreach( $part['k'] === 'html' ? Html::blocks( $part['html'], $this->_base(), $this->_logo, $this->_width ) : [ $this->_typed( $part['text'], $part['multiline'] ) ] as $block )
					if( $block !== null )
						$this->_flow( $block );
			}

			$pages = $this->_doc->pages();

			// Without an appended part every page is the template's
			$this->_body = $this->_body === 0 ? $pages : $this->_body;

			for( $page = 0; $page < $pages; $page++ ) {

				$head = $this->_band( $header, $page + 1, $pages );
				$foot = $this->_band( $footer, $page + 1, $pages );

				if( $head['h'] > 0 )
					$this->_emit( $page, $left, $top, $head['items'], [ $left, $top, $this->_width, $headH ] );

				if( $foot['h'] > 0 ) {
					$at = $this->_sheet - $bottom - min( $foot['h'], $footH );
					$this->_emit( $page, $left, $at, $foot['items'], [ $left, $this->_sheet - $bottom - $footH, $this->_width, $footH ] );
				}
			}

			$this->_fields( $fields );
		}

		/**
		 *	The width of the column in points
		 *
		 *	@return 	float
		 */
		public function width(): float {
			return $this->_width;
		}

		/**
		 *	The height of the body of a page in points
		 *
		 *	@return 	float
		 */
		public function body(): float {
			return $this->_bottom - $this->_top;
		}

		/**
		 *	The body of a page as [ x, y, w, h ] in points from the top-left corner of the paper
		 *
		 *	@return 	array
		 */
		public function area(): array {
			return [ $this->_left, $this->_top, $this->_width, $this->_bottom - $this->_top ];
		}

		/**
		 *	Whether nothing has been set on the page yet
		 *
		 *	@return 	bool
		 */
		public function fresh(): bool {
			return $this->_fresh;
		}

		/**
		 *	The gap that goes above a block that asks for $before: none at the top of a page
		 *
		 *	@param		float			$before
		 *
		 *	@return 	float
		 */
		public function gap( float $before ): float {
			return $this->_fresh === true ? 0.0 : max( $this->_gap, $before );
		}

		/**
		 *	Whether $height more fits on the page
		 *
		 *	@param		float			$height
		 *
		 *	@return 	bool
		 */
		public function fits( float $height ): bool {
			return $this->_y + $height <= $this->_bottom + 0.01;
		}

		/**
		 *	Start the next page: the column empty, no gap pending
		 *
		 *	@return 	void
		 */
		public function newPage(): void {

			$this->_page = $this->_doc->page( $this->_paper, $this->_sheet );
			$this->_y = $this->_top;
			$this->_gap = 0.0;
			$this->_fresh = true;
		}

		/**
		 *	Set items on the page, $gap below the last block, and move on by their height
		 *
		 *	@param		array 		$items
		 *	@param		float			$gap
		 *	@param		float			$height
		 *	@param		float			$after				The space the next block keeps from this one
		 *	@param		array|null	$clip					[ x, y, w, h ] in points from the top-left corner of the paper
		 *
		 *	@return 	void
		 */
		public function put( array $items, float $gap, float $height, float $after, ?array $clip = null ): void {

			$this->_y += $gap;
			$this->_emit( $this->_page, $this->_left, $this->_y, $items, $clip );
			$this->_y += $height;
			$this->_gap = $after;
			$this->_fresh = false;
		}

		/**
		 *	Blocks stacked in a box of a given width, with no page to break: the height and the items
		 *
		 *	@param		array 		$blocks
		 *	@param		float			$width
		 *
		 *	@return 	array											[ h => float, items => array ]
		 */
		public function stack( array $blocks, float $width ): array {

			$y = 0.0;
			$after = 0.0;
			$items = [];

			foreach( $blocks as $block ) {

				$gap = $y > 0 ? max( $after, $block['before'] ) : 0.0;

				if( $block['k'] === 'p' ) {

					$y += $gap;

					foreach( $this->_paragraph( $block, $width ) as $line ) {
						array_push( $items, ...self::shift( $line['items'], 0.0, $y ) );
						$y += $line['h'];
					}
				}
				else if( $block['k'] === 'hr' ) {
					$items[] = [ 'l', 0.0, $y + $gap, $width, 0.5, 0.55 ];
					$y += $gap + 0.5;
				}
				else {
					$table = Table::stack( $this, $block, $width );
					$y += $gap;
					array_push( $items, ...self::shift( $table['items'], 0.0, $y ) );
					$y += $table['h'];
				}

				$after = $block['after'];
			}

			return [ 'h' => $y, 'items' => $items ];
		}

		/**
		 *	Items moved by a distance
		 *
		 *	@param		array 		$items
		 *	@param		float			$dx
		 *	@param		float			$dy
		 *
		 *	@return 	array
		 */
		public static function shift( array $items, float $dx, float $dy ): array {

			foreach( $items as $at => $item ) {
				$items[$at][1] += $dx;
				$items[$at][2] += $dy;
			}

			return $items;
		}

		/**
		 *	One block set on the page
		 *
		 *	@param		array 		$block
		 *
		 *	@return 	void
		 */
		private function _flow( array $block ): void {

			if( $this->_break === true ) {

				if( $this->_fresh === false )
					$this->newPage();

				$this->_break = false;
			}

			if( $block['k'] === 'table' ) {
				Table::flow( $this, $block );
				return;
			}

			if( $block['k'] === 'hr' ) {

				$gap = $this->gap( $block['before'] );

				if( $this->fits( $gap + 0.5 ) === false ) {
					$this->newPage();
					$gap = 0.0;
				}

				$this->put( [ [ 'l', 0.0, 0.0, $this->_width, 0.5, 0.55 ] ], $gap, 0.5, $block['after'] );
				return;
			}

			$lines = $this->_paragraph( $block, $this->_width );
			$count = count( $lines );
			$at = 0;

			// A heading stays with the start of what follows it: room for it and two lines of the base size
			if( $block['keep'] === true && $this->_fresh === false ) {

				$need = $this->gap( $block['before'] ) + array_sum( array_column( $lines, 'h' ) ) + 2 * Text::LEADING * (float) $this->_layout['size'];

				if( $this->fits( $need ) === false )
					$this->newPage();
			}

			while( $at < $count ) {

				$gap = $at === 0 ? $this->gap( $block['before'] ) : 0.0;
				$room = $this->_bottom - $this->_y - $gap;
				$fit = 0;
				$used = 0.0;

				for( $i = $at; $i < $count && $used + $lines[$i]['h'] <= $room + 0.01; $i++ ) {
					$used += $lines[$i]['h'];
					$fit++;
				}

				$left = $count - $at;

				// A paragraph that goes on on the next page keeps two lines at either end
				if( $fit < $left ) {

					if( $at === 0 && $fit < 2 && $left >= 2 )
						$fit = 0;
					else if( $left - $fit < 2 && $left >= 4 )
						$fit = $left - 2;
				}

				if( $fit === 0 ) {

					if( $this->_fresh === true )
						$fit = 1;
					else {
						$this->newPage();
						continue;
					}
				}

				$items = [];
				$height = 0.0;

				for( $i = $at; $i < $at + $fit; $i++ ) {
					array_push( $items, ...self::shift( $lines[$i]['items'], 0.0, $height ) );
					$height += $lines[$i]['h'];
				}

				$at += $fit;
				$this->put( $items, $gap, $height, $at < $count ? 0.0 : $block['after'] );

				if( $at < $count )
					$this->newPage();
			}
		}

		/**
		 *	A paragraph as lines, each with the items of its text, x from the left of the column
		 *
		 *	@param		array 		$block
		 *	@param		float			$width
		 *
		 *	@return 	array											Lines: h, items
		 */
		private function _paragraph( array $block, float $width ): array {

			$avail = max( $width - $block['indent'], 1.0 );
			$out = [];

			foreach( Text::wrap( $block['atoms'], $avail, $block['base'] ) as $n => $line ) {

				$x = $block['indent'] + match( $block['align'] ) {
					'right'		=> max( $avail - $line['w'], 0.0 ),
					'center'	=> max( $avail - $line['w'], 0.0 ) / 2,
					default		=> 0.0,
				};

				$items = [];
				$run = null;

				foreach( $line['a'] as $atom ) {

					if( $atom['t'] === 'i' ) {

						if( $run !== null )
							$items[] = $run;

						$run = null;
						$items[] = [ 'i', $x, $line['base'] - $atom['h'], $atom['w'], $atom['h'], $atom['i'] ];
						$x += $atom['w'];
						continue;
					}

					if( $run !== null && $run[3] === $atom['f'] && $run[4] === $atom['z'] && $run[6] === $atom['u'] ) {
						$run[5] .= $atom['s'];
						$run[7] += $atom['w'];
					}
					else {

						if( $run !== null )
							$items[] = $run;

						$run = [ 'r', $x, $line['base'], $atom['f'], $atom['z'], $atom['s'], $atom['u'], $atom['w'] ];
					}

					$x += $atom['w'];
				}

				if( $run !== null )
					$items[] = $run;

				// The marker of a list item, right-aligned to 1.5 mm in front of its text
				if( $n === 0 && $block['marker'] !== null && $block['marker'] !== [] ) {

					$mark = $block['marker'][0];
					$items[] = [ 'r', $block['indent'] - 1.5 * Document::MM - $mark['w'], $line['base'], $mark['f'], $mark['z'], $mark['s'], false, $mark['w'] ];
				}

				$out[] = [ 'h' => $line['h'], 'items' => $items ];
			}

			return $out;
		}

		/**
		 *	A text a person typed as a paragraph: one line of words, or - when it may have several -
		 *	a line break for every newline
		 *
		 *	@param		string		$text
		 *	@param		bool			$multiline
		 *
		 *	@return 	array|null
		 */
		private function _typed( string $text, bool $multiline ): ?array {

			$base = $this->_base();
			$type = [ 'f' => \Nino\Modules\Pdf\Fonts\Metrics::font( $base['family'], false, false ), 'z' => $base['size'], 'u' => false ];
			$atoms = [];

			foreach( $multiline === true ? preg_split( '/\r\n|\r|\n/', $text ) ?: [] : [ $text ] as $at => $line ) {

				if( $at > 0 )
					$atoms[] = [ 't' => 'b', 'z' => $type['z'] ];

				array_push( $atoms, ...Text::atoms( $line, $type ) );
			}

			while( $atoms !== [] && $atoms[0]['t'] === 's' )
				array_shift( $atoms );

			while( $atoms !== [] && $atoms[ count( $atoms ) - 1 ]['t'] === 's' )
				array_pop( $atoms );

			if( $atoms === [] )
				return null;

			return [ 'k' => 'p', 'atoms' => $atoms, 'align' => 'left', 'indent' => 0.0, 'marker' => null, 'before' => 0.0, 'after' => 0.6 * Text::LEADING * $base['size'], 'keep' => false, 'base' => [ 'f' => $type['f'], 'z' => $type['z'] ] ];
		}

		/**
		 *	The font and size the document is set in
		 *
		 *	@return 	array
		 */
		private function _base(): array {

			return [ 'family' => (string) $this->_layout['font'], 'size' => (float) $this->_layout['size'] ];
		}

		/**
		 *	A band, header or footer, laid out for one page number
		 *
		 *	@param		string		$html
		 *	@param		int				$page
		 *	@param		int				$pages
		 *
		 *	@return 	array											[ h, items ]
		 */
		private function _band( string $html, int $page, int $pages ): array {

			$blocks = Html::blocks( str_replace( [ '[[page]]', '[[pages]]' ], [ (string) $page, (string) $pages ], $html ), $this->_base(), $this->_logo, $this->_width );

			return $blocks === [] ? [ 'h' => 0.0, 'items' => [] ] : $this->stack( $blocks, $this->_width );
		}

		/**
		 *	The fields, by position
		 *
		 *	@param		array 		$fields
		 *
		 *	@return 	void
		 */
		private function _fields( array $fields ): void {

			$pages = $this->_body;
			$base = $this->_base();

			foreach( $fields as $field ) {

				$page = $field['page'] === 'last' ? $pages - 1 : (int) $field['page'] - 1;

				if( $page < 0 || $page >= $pages )
					continue;

				$x = $field['x'] * Document::MM;
				$y = $field['y'] * Document::MM;
				$w = $field['w'] * Document::MM;
				$h = $field['h'] * Document::MM;
				$items = [];

				if( $field['image'] !== null ) {

					$scale = min( $w / $field['image']->width(), $h / $field['image']->height() );
					$drawn = [ $field['image']->width() * $scale, $field['image']->height() * $scale ];
					$items[] = [ 'i', $x, $y + $h - $drawn[1], $drawn[0], $drawn[1], $field['image'] ];
				}

				if( $field['line'] === true )
					$items[] = [ 'l', $x, $y + $h, $w, 0.5, 0.0 ];

				$small = $base['size'] * self::SMALL;
				$font = \Nino\Modules\Pdf\Fonts\Metrics::font( $base['family'], false, false );
				$text = '';
				$wide = 0.0;

				foreach( Text::atoms( (string) $field['label'], [ 'f' => $font, 'z' => $small, 'u' => false ] ) as $atom ) {
					$text .= $atom['s'];
					$wide += $atom['w'];
				}

				// Under the box, one millimetre below the line
				if( trim( $text ) !== '' )
					$items[] = [ 'r', $x, $y + $h + 1.0 * Document::MM + 0.72 * $small, $font, $small, trim( $text ), false, $wide ];

				$this->_emit( $page, 0.0, 0.0, $items );
			}
		}

		/**
		 *	Write items into the content stream of a page
		 *
		 *	@param		int				$page
		 *	@param		float			$ox						Where the box starts, in points from the left of the paper
		 *	@param		float			$oy						... and from the top
		 *	@param		array 		$items
		 *	@param		array|null	$clip					[ x, y, w, h ]: nothing outside is drawn
		 *
		 *	@return 	void
		 */
		private function _emit( int $page, float $ox, float $oy, array $items, ?array $clip = null ): void {

			if( $items === [] )
				return;

			$n = Document::num( ... );
			$ops = $clip === null ? '' : 'q '. Document::num( $clip[0] ). ' '. Document::num( $this->_sheet - $clip[1] - $clip[3] ). ' '. Document::num( $clip[2] ). ' '. Document::num( $clip[3] ). " re W n\n";

			foreach( $items as $item ) {

				$x = $ox + $item[1];
				$y = $this->_sheet - ( $oy + $item[2] );

				if( $item[0] === 'r' ) {

					$ops .= 'BT '. $this->_doc->font( $page, $item[3] ). ' '. $n( $item[4] ). ' Tf '. $n( $x ). ' '. $n( $y ). ' Td '. Document::literal( $item[5] )." Tj ET\n";

					if( $item[6] === true )
						$ops .= 'q '. $n( 0.05 * $item[4] ). ' w '. $n( $x ). ' '. $n( $y - 0.12 * $item[4] ). ' m '. $n( $x + $item[7] ). ' '. $n( $y - 0.12 * $item[4] )." l S Q\n";
				}
				else if( $item[0] === 'i' )
					$ops .= 'q '. $n( $item[3] ). ' 0 0 '. $n( $item[4] ). ' '. $n( $x ). ' '. $n( $y - $item[4] ). ' cm '. $this->_doc->image( $page, $item[5] )." Do Q\n";
				else
					$ops .= 'q '. $n( $item[4] ). ' w '. $n( $item[5] ). ' G '. $n( $x ). ' '. $n( $y ). ' m '. $n( $x + $item[3] ). ' '. $n( $y )." l S Q\n";
			}

			$this->_doc->draw( $page, $ops. ( $clip === null ? '' : "Q\n" ) );
		}
	}
}

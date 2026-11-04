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
	 *										It is handed the layout (the paper and the margins), the rules of the
	 *										sheet (see Sheet: the font and the base size are the body's, and every
	 *										size, space, colour and line below is read from them), the logo, and
	 *										run() the parts of the document in order
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
	 *										height on every page, with the gap of the rules of .header and .footer
	 *										(4 mm) to the text and the line of their border where they have one; the text
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
	 *										text: x, baseline, font, size, bytes, underlined, width, colour), i (a
	 *										picture: x, top, w, h, the Image), l (a horizontal line: x, y,
	 *										length, thickness, colour) and f (a filled box: x, y, w, h, colour).
	 *										A colour is six hex digits; a text without one is black, and a colour
	 *										is written with two decimals a channel, grey as grey. Their coordinates
	 *										are points from the top-left corner of the box they belong to.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Engine {

		// The paper in millimetres: width, height
		public const array PAPER = [ 'a4' => [ 210.0, 297.0 ], 'letter' => [ 215.9, 279.4 ] ];

		// The height a header or a footer may take, in millimetres
		public const float BAND = 40.0;

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
		 *	@param		array 		$_layout				format, margins ([ top, right, bottom, left ] in mm)
		 *	@param		array 			$_rules					The merged sheet, with the body's font-family and font-size in points
		 *	@param		Image|null	$_logo
		 */
		public function __construct( private Document $_doc, private array $_layout, private array $_rules, private ?Image $_logo ) {
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

			$headH = min( $this->_band( $header, 1, 1, '.header' )['h'], self::BAND * Document::MM );
			$footH = min( $this->_band( $footer, 1, 1, '.footer' )['h'], self::BAND * Document::MM );

			$headGap = (float) ( $this->_rules['.header']['margin'][2] ?? 0.0 ) * Document::MM;
			$footGap = (float) ( $this->_rules['.footer']['margin'][0] ?? 0.0 ) * Document::MM;

			$this->_top = $top + $headH + ( $headH > 0 ? $headGap : 0.0 );
			$this->_bottom = $this->_sheet - $bottom - $footH - ( $footH > 0 ? $footGap : 0.0 );

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

					$blocks = Html::blocks( $part['html'], $this->_rules, $this->_logo, $this->_width );

					if( $blocks === [] )
						continue;

					$this->_body = $this->_doc->pages();
					$this->newPage();

					foreach( $blocks as $block )
						$this->_flow( $block );

					continue;
				}

				foreach( $part['k'] === 'html' ? Html::blocks( $part['html'], $this->_rules, $this->_logo, $this->_width ) : [ $this->_typed( $part['text'], $part['multiline'] ) ] as $block )
					if( $block !== null )
						$this->_flow( $block );
			}

			$pages = $this->_doc->pages();

			// Without an appended part every page is the template's
			$this->_body = $this->_body === 0 ? $pages : $this->_body;

			for( $page = 0; $page < $pages; $page++ ) {

				$head = $this->_band( $header, $page + 1, $pages, '.header' );
				$foot = $this->_band( $footer, $page + 1, $pages, '.footer' );

				if( $head['h'] > 0 ) {

					$this->_emit( $page, $left, $top, $head['items'], [ $left, $top, $this->_width, $headH ] );

					// The line under the header: at the foot of its band
					$rule = $this->_rules['.header']['border-bottom'] ?? false;

					if( $rule !== false )
						$this->_emit( $page, $left, $top + $headH, [ [ 'l', 0.0, 0.0, $this->_width, $rule[0], $rule[1] ] ] );
				}

				if( $foot['h'] > 0 ) {

					$at = $this->_sheet - $bottom - min( $foot['h'], $footH );
					$this->_emit( $page, $left, $at, $foot['items'], [ $left, $this->_sheet - $bottom - $footH, $this->_width, $footH ] );

					// The line over the footer: at the head of its band
					$rule = $this->_rules['.footer']['border-top'] ?? false;

					if( $rule !== false )
						$this->_emit( $page, $left, $this->_sheet - $bottom - $footH, [ [ 'l', 0.0, 0.0, $this->_width, $rule[0], $rule[1] ] ] );
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

					$thick = $block['rule'] === false ? 0.0 : (float) $block['rule'][0];

					if( $block['rule'] !== false )
						$items[] = [ 'l', 0.0, $y + $gap, $width, $thick, $block['rule'][1] ];

					$y += $gap + $thick;
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
				$thick = $block['rule'] === false ? 0.0 : (float) $block['rule'][0];

				if( $this->fits( $gap + $thick ) === false ) {
					$this->newPage();
					$gap = 0.0;
				}

				$this->put( $block['rule'] === false ? [] : [ [ 'l', 0.0, 0.0, $this->_width, $thick, $block['rule'][1] ] ], $gap, $thick, $block['after'] );
				return;
			}

			$lines = $this->_paragraph( $block, $this->_width );
			$count = count( $lines );
			$at = 0;

			// A heading stays with the start of what follows it: room for it and two lines of the base size
			if( $block['keep'] === true && $this->_fresh === false ) {

				$base = $this->_base();
				$need = $this->gap( $block['before'] ) + array_sum( array_column( $lines, 'h' ) ) + 2 * $base['l'] * $base['size'];

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

					if( $run !== null && $run[3] === $atom['f'] && $run[4] === $atom['z'] && $run[6] === $atom['u'] && $run[8] === $atom['c'] ) {
						$run[5] .= $atom['s'];
						$run[7] += $atom['w'];
					}
					else {

						if( $run !== null )
							$items[] = $run;

						$run = [ 'r', $x, $line['base'], $atom['f'], $atom['z'], $atom['s'], $atom['u'], $atom['w'], $atom['c'] ];
					}

					$x += $atom['w'];
				}

				if( $run !== null )
					$items[] = $run;

				// The marker of a list item, right-aligned to 1.5 mm in front of its text
				if( $n === 0 && $block['marker'] !== null && $block['marker'] !== [] ) {

					$mark = $block['marker'][0];
					$items[] = [ 'r', $block['indent'] - 1.5 * Document::MM - $mark['w'], $line['base'], $mark['f'], $mark['z'], $mark['s'], false, $mark['w'], $mark['c'] ];
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

			// A text a person typed is a paragraph: the body's type with the rules of p over it
			$base = $this->_base();
			$rule = $this->_rules['p'] ?? [];
			$bold = isset( $rule['font-weight'] ) === true ? $rule['font-weight'] === 'bold' : $base['b'];
			$italic = isset( $rule['font-style'] ) === true ? $rule['font-style'] === 'italic' : $base['i'];
			$type = [ 'f' => \Nino\Modules\Pdf\Fonts\Metrics::font( (string) ( $rule['font-family'] ?? $base['family'] ), $bold, $italic ), 'z' => Sheet::size( $rule, $base['size'] ), 'u' => false, 'c' => (string) ( $rule['color'] ?? $base['c'] ), 'l' => (float) ( $rule['line-height'] ?? $base['l'] ) ];
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

			return [ 'k' => 'p', 'atoms' => $atoms, 'align' => (string) ( $rule['text-align'] ?? 'left' ), 'indent' => 0.0, 'marker' => null, 'before' => (float) ( $rule['margin'][0] ?? 0.0 ) * Document::MM, 'after' => (float) ( $rule['margin'][2] ?? 0.0 ) * Document::MM, 'keep' => false, 'base' => [ 'f' => $type['f'], 'z' => $type['z'], 'l' => $type['l'] ] ];
		}

		/**
		 *	The type the document is set in: the body's family, size, weight, slant, colour and line height
		 *
		 *	@param		string		$scope				.header or .footer
		 *
		 *	@return 	array											See Html::base()
		 */
		private function _base( string $scope = '' ): array {

			return Html::base( $this->_rules, $scope );
		}

		/**
		 *	A band, header or footer, laid out for one page number
		 *
		 *	@param		string		$html
		 *	@param		int				$page
		 *	@param		int				$pages
		 *	@param		string		$scope				.header or .footer
		 *
		 *	@return 	array											[ h, items ]
		 */
		private function _band( string $html, int $page, int $pages, string $scope ): array {

			$blocks = Html::blocks( str_replace( [ '[[page]]', '[[pages]]' ], [ (string) $page, (string) $pages ], $html ), $this->_rules, $this->_logo, $this->_width, $scope );

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
			$rule = $this->_rules['.signature'] ?? [];
			$small = Sheet::size( $rule, $base['size'] );
			$family = (string) ( $rule['font-family'] ?? $base['family'] );
			$colour = (string) ( $rule['color'] ?? $base['c'] );
			$line = $rule['border-top'] ?? false;
			$gap = (float) ( $rule['margin'][0] ?? 0.0 ) * Document::MM;

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

				if( $field['line'] === true && $line !== false )
					$items[] = [ 'l', $x, $y + $h, $w, $line[0], $line[1] ];

				$font = \Nino\Modules\Pdf\Fonts\Metrics::font( $family, false, false );
				$text = '';
				$wide = 0.0;

				foreach( Text::atoms( (string) $field['label'], [ 'f' => $font, 'z' => $small, 'u' => false ] ) as $atom ) {
					$text .= $atom['s'];
					$wide += $atom['w'];
				}

				// Under the box, one millimetre below the line
				if( trim( $text ) !== '' )
					$items[] = [ 'r', $x, $y + $h + $gap + 0.72 * $small, $font, $small, trim( $text ), false, $wide, $colour ];

				$this->_emit( $page, 0.0, 0.0, $items );
			}
		}

		/**
		 *	A colour as the operator that sets it: each channel with two decimals, a grey as one number
		 *
		 *	@param		string		$colour				Six hex digits
		 *	@param		bool			$fill					The fill colour, else the stroke colour
		 *
		 *	@return 	string
		 */
		private static function _paint( string $colour, bool $fill ): string {

			$n = Document::num( ... );
			$channels = array_map( static fn( string $hex ): float => round( hexdec( $hex ) / 255, 2 ), str_split( $colour, 2 ) );

			if( $channels[0] === $channels[1] && $channels[1] === $channels[2] )
				return $n( $channels[0] ). ( $fill === true ? ' g' : ' G' );

			return $n( $channels[0] ). ' '. $n( $channels[1] ). ' '. $n( $channels[2] ). ( $fill === true ? ' rg' : ' RG' );
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

					// A text in a colour is drawn in a state of its own, the underline with it; black is what the page starts with
					$coloured = isset( $item[8] ) === true && $item[8] !== '000000';
					$ops .= $coloured === true ? 'q '. self::_paint( $item[8], true ). ' ' : '';
					$ops .= 'BT '. $this->_doc->font( $page, $item[3] ). ' '. $n( $item[4] ). ' Tf '. $n( $x ). ' '. $n( $y ). ' Td '. Document::literal( $item[5] )." Tj ET\n";

					if( $item[6] === true )
						$ops .= 'q '. $n( 0.05 * $item[4] ). ' w '. ( $coloured === true ? self::_paint( $item[8], false ). ' ' : '' ). $n( $x ). ' '. $n( $y - 0.12 * $item[4] ). ' m '. $n( $x + $item[7] ). ' '. $n( $y - 0.12 * $item[4] )." l S Q\n";

					$ops .= $coloured === true ? "Q\n" : '';
				}
				else if( $item[0] === 'i' )
					$ops .= 'q '. $n( $item[3] ). ' 0 0 '. $n( $item[4] ). ' '. $n( $x ). ' '. $n( $y - $item[4] ). ' cm '. $this->_doc->image( $page, $item[5] )." Do Q\n";
				else if( $item[0] === 'f' )
					$ops .= 'q '. self::_paint( $item[5], true ). ' '. $n( $x ). ' '. $n( $y - $item[4] ). ' '. $n( $item[3] ). ' '. $n( $item[4] )." re f Q\n";
				else
					$ops .= 'q '. $n( $item[4] ). ' w '. self::_paint( $item[5], false ). ' '. $n( $x ). ' '. $n( $y ). ' m '. $n( $x + $item[3] ). ' '. $n( $y )." l S Q\n";
			}

			$this->_doc->draw( $page, $ops. ( $clip === null ? '' : "Q\n" ) );
		}
	}
}

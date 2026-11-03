<?php
declare(strict_types=1);
/**
 *	Nino									A compact filesystembased php framework
 *	Modules\Pdf\Layout\Table	A table, laid out row by row
 *
 *	@package							Dape/Nino
 *	@author								David Perchermeier <mail@dape.io>
 *	@link									https://github.com/dapeio/nino
 */
namespace Nino\Modules\Pdf\Layout {

	use Nino\Modules\Pdf\Document\Document;

	/**
	 *	Nino							A compact filesystembased php framework
	 *	Table							What the engine does with a table block (see Html).
	 *
	 *										The columns take the widths the cells of the first row ask for
	 *										in percent - width="30%" - and share the rest equally; a cell has
	 *										1.5 mm of padding all round and holds blocks of its own, set in the
	 *										width that is left. A row is as high as its highest cell, a th is
	 *										bold, a row with a th has a rule under it and every other row a
	 *										hairline, unless the table says border="0".
	 *
	 *										On the page a row is not split. A row that does not fit goes to
	 *										the next page, and the rows of the thead are drawn again there
	 *										first. A single row taller than the whole body of a page is drawn
	 *										on a page of its own, clipped to it, with an E_USER_WARNING.
	 *										stack() sets a table in a box that has no page to break - a cell,
	 *										a header.
	 *
	 *	@package					Dape/Nino
	 *	@author						David Perchermeier <mail@dape.io>
	 *	@link							https://github.com/dapeio/nino
	 */
	class Table {

		// The padding of a cell in millimetres
		public const float PAD = 1.5;

		/**
		 *	Set a table on the pages
		 *
		 *	@param		Engine		$engine
		 *	@param		array 		$block
		 *
		 *	@return 	void
		 */
		public static function flow( Engine $engine, array $block ): void {

			$rows = self::_rows( $engine, $block, $engine->width() );

			if( $rows === [] )
				return;

			$heads = array_values( array_filter( $rows, static fn( array $row ): bool => $row['head'] === true ) );
			$repeat = array_sum( array_column( $heads, 'h' ) );
			$last = count( $rows ) - 1;
			$started = false;

			foreach( $rows as $at => $row ) {

				$gap = $started === false ? $engine->gap( $block['before'] ) : 0.0;
				$after = $at === $last ? $block['after'] : 0.0;

				if( $row['h'] > $engine->body() + 0.01 ) {

					if( $engine->fresh() === false ) {
						$engine->newPage();
						$gap = 0.0;
					}

					trigger_error( 'Pdf: a table row is taller than a page and is drawn clipped to it', E_USER_WARNING );
					$engine->put( $row['items'], $gap, $engine->body(), $after, $engine->area() );
					$started = true;
					continue;
				}

				// A head row is not left alone at the foot of a page: the row after it comes along
				$need = $gap + $row['h'] + ( $started === false && $row['head'] === true && isset( $rows[$at + 1] ) === true ? $rows[$at + 1]['h'] : 0.0 );

				if( $engine->fits( $need ) === false ) {

					$engine->newPage();
					$gap = 0.0;

					if( $started === true && $row['head'] === false && $heads !== [] && $repeat + $row['h'] <= $engine->body() )
						foreach( $heads as $head )
							$engine->put( $head['items'], 0.0, $head['h'], 0.0 );
				}

				$engine->put( $row['items'], $gap, $row['h'], $after );
				$started = true;
			}
		}

		/**
		 *	A table in a box without pages: its height and its items
		 *
		 *	@param		Engine		$engine
		 *	@param		array 		$block
		 *	@param		float			$width
		 *
		 *	@return 	array											[ h, items ]
		 */
		public static function stack( Engine $engine, array $block, float $width ): array {

			$y = 0.0;
			$items = [];

			foreach( self::_rows( $engine, $block, $width ) as $row ) {
				array_push( $items, ...Engine::shift( $row['items'], 0.0, $y ) );
				$y += $row['h'];
			}

			return [ 'h' => $y, 'items' => $items ];
		}

		/**
		 *	The rows of a table, each laid out in the columns: its height, its items from its own top-left
		 *	corner - the rule under it among them - and whether it belongs to the thead
		 *
		 *	@param		Engine		$engine
		 *	@param		array 		$block
		 *	@param		float			$width
		 *
		 *	@return 	array
		 */
		private static function _rows( Engine $engine, array $block, float $width ): array {

			$widths = self::_columns( $block, $width );
			$pad = self::PAD * Document::MM;
			$rows = [];

			foreach( $block['rows'] as $row ) {

				$x = 0.0;
				$height = 0.0;
				$items = [];
				$heading = false;

				foreach( $row['cells'] as $at => $cell ) {

					$stack = $engine->stack( $cell['blocks'], max( $widths[$at] - 2 * $pad, 1.0 ) );
					$height = max( $height, $stack['h'] );
					$heading = $heading || $cell['th'] === true;
					array_push( $items, ...Engine::shift( $stack['items'], $x + $pad, $pad ) );
					$x += $widths[$at];
				}

				$height += 2 * $pad;

				if( $heading === true )
					$items[] = [ 'l', 0.0, $height, $x, 0.8, 0.0 ];
				else if( $block['border'] === true )
					$items[] = [ 'l', 0.0, $height, $x, 0.4, 0.5 ];

				$rows[] = [ 'h' => $height, 'items' => $items, 'head' => $row['head'] ];
			}

			return $rows;
		}

		/**
		 *	The widths of the columns in points
		 *
		 *	@param		array 		$block
		 *	@param		float			$width
		 *
		 *	@return 	array
		 */
		private static function _columns( array $block, float $width ): array {

			$count = 0;

			foreach( $block['rows'] as $row )
				$count = max( $count, count( $row['cells'] ) );

			if( $count === 0 )
				return [];

			$wide = array_fill( 0, $count, null );
			$sum = 0.0;

			foreach( $block['rows'][0]['cells'] as $at => $cell )
				if( $cell['pct'] !== null ) {
					$wide[$at] = $cell['pct'] / 100 * $width;
					$sum += $wide[$at];
				}

			if( $sum > $width ) {

				foreach( $wide as $at => $one )
					if( $one !== null )
						$wide[$at] = $one * $width / $sum;

				$sum = $width;
			}

			$free = count( array_filter( $wide, static fn( mixed $one ): bool => $one === null ) );

			foreach( $wide as $at => $one )
				if( $one === null )
					$wide[$at] = max( ( $width - $sum ) / $free, 1.0 );

			return $wide;
		}
	}
}

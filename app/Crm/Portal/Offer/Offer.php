<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Offer		One released offer as the portal draws it: a card that folds - its head is one line with the title (the number where
 *											there is none), the number, the day, the day it is valid until, the gross amount, the state in the customer's words and the
 *											download; its body the lines, the sums as the PDF has them, the note of the VAT mode, the line that says it has no day it
 *											runs out, the message of an order where the query names this offer and the part about ordering it (Portal\Order). The
 *											section Angebot of a project page draws every offer of the project with it, open where the offer can be ordered and
 *											folded where it is done; the list of /portal/belege unfolds an offer under its row, where an offer without a project is
 *											ordered. Nothing here is stored, and every value enters the markup escaped
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;

class Offer {

	/**
	 *	The card of an offer
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				The client of the member
	 *	@param		array 		$released			As Documents::released() has it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *	@param		string		$from					Where the form of the order posts from: projekt or belege (Order::FROM)
	 *	@param		bool			$open					Whether the card stands open
	 *	@param		bool			$bare					The body alone, without the head that folds: for the row of a list, which says the head already
	 *
	 *	@return 	string										'' for an offer that cannot be read or whose state the words do not know
	 */
	public static function card( array &$appData, array $client, array $released, string $dir, string $from, bool $open, bool $bare = false ): string {

		$state = Portal::customerState( $released );
		$document = Documents::get( $appData, $released['id'] );

		if( $document === null || preg_match( '/^[a-z]{1,20}$/D', $state ) !== 1 )
			return '';

		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$currency = \Crm\Settings::get( $appData, 'currency' );
		$regular = $document['vat_mode'] === 'regular';
		$totals = Documents\Money::totals( $document['lines'], (string) $document['vat_mode'], (bool) $document['reverse_charge'] );
		$lines = '';

		foreach( $document['lines'] as $index => $line ) {

			$unit = (string) $line['unit'] === '' ? '' : ' '. $line['unit'];

			$lines .= Portal::render( 'offer-line', [
				'[[title]]'       => $escape( (string) $line['title'] ),
				'[[description]]' => (string) $line['description'] === '' ? '' : Portal::render( 'offer-line-description', [ '[[text]]' => str_replace( [ "\r\n", "\r", "\n" ], '<br>', $escape( (string) $line['description'] ) ) ] ),
				'[[qty]]'         => $escape( Documents\Money::formatQty( (int) $line['qty'] ). $unit ),
				'[[price]]'       => $escape( Documents\Money::format( (int) $line['price_net'], $currency ) ),
				'[[rate]]'        => $regular === true ? Portal::render( 'offer-line-rate', [ '[[rate]]' => $escape( Documents\Money::formatRate( (int) $line['vat_rate'] ) ) ] ) : '',
				'[[amount]]'      => $escape( Documents\Money::format( $totals['amounts'][$index], $currency ) ),
			] );
		}

		$sum = static fn( string $kind, string $label, int $cents ): string => Portal::render( 'offer-sum', [ '[[kind]]' => $kind, '[[label]]' => $escape( $label ), '[[amount]]' => $escape( Documents\Money::format( $cents, $currency ) ) ] );
		$sums = '';

		if( $regular === true ) {

			$sums .= $sum( 'net', Documents\Render::text( 'totals.net' ), $totals['net'] );

			foreach( $totals['rates'] as $rate => $part )
				$sums .= $sum( 'vat', Documents\Render::text( 'totals.vat', Documents\Money::formatRate( $rate ), Documents\Money::format( $part['net'], $currency ) ), $part['vat'] );
		}

		$sums .= $sum( 'gross', Documents\Render::text( 'totals.gross' ), $totals['gross'] );
		$note = $regular === false ? 'vatnote.small_business' : ( (bool) $document['reverse_charge'] === true ? 'vatnote.reverse' : '' );
		$valid = Documents\Render::day( (string) ( $document['valid_until'] ?? '' ) );
		$number = $escape( $document['number'] !== '' ? $document['number'] : $document['title'] );

		return Portal::render( $bare === true ? 'offer-bare' : 'offer', [
			'[[id]]'        => (string) $document['id'],
			'[[state]]'     => $state,
			'[[open]]'      => $open === true ? ' open' : '',
			'[[title]]'     => Portal::documentTitle( $document ),
			'[[number]]'    => $number,
			'[[iso]]'       => $escape( $document['date'] ),
			'[[date]]'      => $escape( Documents\Render::day( $document['date'] ) ),
			'[[valid]]'     => $valid === '' ? '' : Portal::render( 'offer-valid', [ '[[iso]]' => $escape( (string) $document['valid_until'] ), '[[date]]' => $escape( $valid ) ] ),
			'[[gross]]'     => $escape( Documents\Money::format( $totals['gross'], $currency ) ),
			'[[tone]]'      => Portal::tone( $state ),
			'[[word]]'      => Portal::stateWord( $released, $state ),
			'[[dir]]'       => $dir,
			'[[lines]]'     => $lines,
			'[[sums]]'      => $sums,
			'[[note]]'      => $note === '' ? '' : Portal::render( 'offer-note', [ '[[text]]' => $escape( Documents\Render::text( $note ) ) ] ),
			'[[validnote]]' => $valid === '' && Documents::orderable( $released ) === true ? Portal::template( 'offer-valid-none' ) : '',
			'[[message]]'   => Order::state( $appData, (int) $document['id'] ),
			'[[order]]'     => Order::block( $appData, $client, $released, $from, $dir ),
		] );
	}
}

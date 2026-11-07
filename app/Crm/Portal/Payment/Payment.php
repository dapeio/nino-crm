<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Payment		The customer reports in the portal that it has paid an invoice. In the block that says how an open invoice is paid (Portal::payBlock()) stands the form
 *												Ich habe überwiesen while no report of the invoice exists; once there is one, a line says when it was made and by whom is kept in the record. The report changes nothing
 *												of the invoice: it stays open or overdue until the owner marks it as paid, because it is the owner who sees the money.
 *
 *												POST /.crm/bezahlt takes the form. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404, as the other forms of the
 *												portal answer a stranger). Every answer is a redirect to the list of the documents with a state in the query: paid-notice, invalid (the invoice is none of the
 *												client's, is not released, is paid already, is no invoice, or was reported already) or expired (the token of the form was not the session's). The record (kind payment)
 *												holds the JSON of the number, the sum and the day (Documents::payment(): one transaction that looks at the invoice and at the reports again). Then the owner is mailed
 *												with the link to the document in the surface; a mail that does not go out leaves the report kept. The customer gets no mail.
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;
use Crm\Records;

class Payment {

	// The route the form posts to
	public const string ROUTE = '/.crm/bezahlt';

	/**
	 *	The part of the payment block that is about the report: the form while the invoice has none, the line that says when it was made once it has one
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::released() has it: id and number
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string
	 */
	public static function block( array &$appData, array $document, string $dir ): string {

		$state = Order::state( $appData, (int) $document['id'] );
		$record = Records::paymentOf( $appData, (int) $document['id'] );

		if( $record === null )
			return $state. Portal::render( 'pay-notice', [ '[[id]]' => (string) $document['id'], '[[dir]]' => $dir ] );

		Portal::words( $appData );

		$day = substr( (string) $record['at'], 0, 10 );
		$parts = explode( '{date}', (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/pay/noticed' ), 2 );
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );
		$time = '<time datetime="'. $escape( $day ). '">'. $escape( Documents\Render::day( $day ) ). '</time>';

		return $state. Portal::render( 'pay-noticed', [ '[[id]]' => (string) $document['id'], '[[sentence]]' => $escape( $parts[0] ). ( count( $parts ) === 2 ? $time. $escape( $parts[1] ) : '' ) ] );
	}

	/**
	 *	'/nino/http/response/POST://.crm/bezahlt': the form in the payment block. A visitor and a member of no client are answered 404; a token that is not the session's is the redirect
	 *	with ?state=expired; an invoice that is not the client's, is not released, is not open, is not there or was reported already is ?state=invalid. Else the report is kept, the owner
	 *	is mailed and the answer is ?state=paid-notice
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackPaid( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {
			Order::notFound( $request );

			return;
		}

		$id = is_string( $_POST['document'] ?? null ) === true && preg_match( '/^[1-9][0-9]{0,9}$/D', $_POST['document'] ) === 1 ? (int) $_POST['document'] : 0;
		$document = $id === 0 ? null : Documents::get( $appData, $id );

		// The client's document, worked out once for every path below: another client's invoice is not told apart from one that is not there
		if( $document !== null && $document['client_id'] !== $client['id'] )
			$document = null;

		if( \Nino\Form::blocked( $request ) === true ) {
			Order::redirect( $appData, $request, 'expired', 'belege', $document );

			return;
		}

		if( $document === null ) {
			Order::redirect( $appData, $request, 'invalid', 'belege', null );

			return;
		}

		$paid = Documents::payment( $appData, $id, $client['id'], [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], Records::source( $appData, $request ) );

		if( is_string( $paid ) === true ) {
			Order::redirect( $appData, $request, 'invalid', 'belege', $document );

			return;
		}

		Documents\Mail::paid( $appData, $paid['document'], $paid['record'], $client );
		Order::redirect( $appData, $request, 'paid-notice', 'belege', $paid['document'] );
	}
}

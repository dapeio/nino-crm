<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Order		The customer orders an offer in the portal. Under the content of an offer - on the page of its project and in the list of the
 *											documents - stands the form of the order while the offer can be ordered (an offer that is released to the portal, open or sent and
 *											not past its day): the sentence "Ich beauftrage <company> verbindlich mit dem Angebot <number> zu den genannten Konditionen." as the label of a
 *											box that must be ticked, and the button. While the client has not agreed to the Datenschutz the form is replaced by the sentence that the
 *											Datenschutz comes first, with the way to it. An offer that was ordered says when and by whom instead.
 *
 *											POST /.crm/auftrag takes the form. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404,
 *											as the other forms of the portal answer a stranger). Every answer is a redirect to the page the form stood on with a state in the query:
 *											ordered, invalid (the offer is none of the client's, is not released, cannot be ordered any more or is not there), unchecked (the box was
 *											not ticked), privacy (the Datenschutz comes first) or expired (the token of the form was not the session's). The order is Documents::order(): the
 *											record of what the customer was shown and the step of the offer to accepted are one transaction. Then the owner is mailed that the order
 *											was placed and the account gets the confirmation; a mail that does not go out does not undo the order.
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;
use Crm\Records;

class Order {

	// The route the form posts to, the pages it goes back to
	public const string ROUTE = '/.crm/auftrag';
	public const string PAGE_DOCUMENTS = '/portal/belege';
	public const string PAGE_PROJECTS = '/portal/projekte';

	// The states a page says, and where the form was: the list of documents or the page of a project
	public const array STATES = [ 'ordered', 'nomail', 'invalid', 'unchecked', 'privacy', 'expired', 'changed' ];
	public const array FROM = [ 'belege', 'projekt' ];

	/**
	 *	The part of an offer's content that is about ordering it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		array 		$offer				A document of the client: id, category, number, title, state, valid_until, expired and project (Documents::released() has them as Documents::get() does)
	 *	@param		string		$from					belege (the list of documents) or projekt (the page of a project)
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										The form; the sentence that the Datenschutz comes first; the line that says it was ordered; or nothing for an offer that cannot be ordered
	 */
	public static function block( array &$appData, array $client, array $offer, string $from, string $dir ): string {

		if( $offer['category'] !== 'offer' )
			return '';

		$record = Records::forDocument( $appData, (int) $offer['id'] );

		if( $record !== null )
			return self::done( $record, $dir );

		if( Documents::orderable( $offer ) === false )
			return '';

		$standing = Records::privacy( $appData, (int) $client['id'] );

		if( $standing['asked'] === true && $standing['agreed'] === false )
			return str_replace( [ '[[id]]', '[[dir]]' ], [ (string) $offer['id'], $dir ], Portal::template( 'order-privacy' ) );

		$full = Documents::get( $appData, (int) $offer['id'] );

		if( $full === null )
			return '';

		$sentence = self::sentence( $appData, $offer );

		return str_replace(
			[ '[[id]]', '[[dir]]', '[[from]]', '[[sentence]]', '[[hash]]' ],
			[ (string) $offer['id'], $dir, $from, \Nino\Modules\Components::escape( $sentence ), Records::hash( Records::orderShown( $appData, $full, $sentence ) ) ],
			Portal::template( 'order-form' )
		);
	}

	/**
	 *	The sentence of the box, as the customer reads it and as the record keeps it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$offer				number and title
	 *
	 *	@return 	string										Plain text
	 */
	public static function sentence( array &$appData, array $offer ): string {

		Portal::words( $appData );

		$company = trim( html_entity_decode( (string) \Nino\Html::resolveTextfill( $appData, '/project/company/general/name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		$name = (string) $offer['number'] !== '' ? (string) $offer['number'] : (string) $offer['title'];

		return str_replace(
			[ '{company}', '{number}' ],
			[ $company !== '' ? $company : (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/order/company-none' ), $name ],
			(string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/order/sentence' )
		);
	}

	/**
	 *	The sentence about the state the query names, for the page the form stood on
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										'' where the query names none
	 */
	public static function state( array &$appData ): string {

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && in_array( $query['state'], self::STATES, true ) === true ? $query['state'] : '';

		return $state === '' ? '' : str_replace( [ '[[state]]', '[[role]]' ], [ $state, in_array( $state, [ 'ordered', 'nomail' ], true ) === true ? 'status' : 'alert' ], Portal::template( 'order-state' ) );
	}

	/**
	 *	'/nino/http/response/POST://.crm/auftrag': the form under an offer. A visitor and a member of no client are answered 404; a token that is not the
	 *	session's is the redirect with ?state=expired; an offer that is not the client's, is not released, cannot be ordered or is not there is
	 *	?state=invalid; a client that has not agreed to the Datenschutz it is asked for is ?state=privacy; a box that is not ticked is ?state=unchecked. Else the
	 *	order is made (Documents::order()), the mails go out and the answer is ?state=ordered
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackOrder( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {

			$request['/nino/http/response']['statusCode']								= 404;
			$request['/nino/http/response']['body']										= 'Not Found';
			$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

			return;
		}

		$from = is_string( $_POST['from'] ?? null ) === true && in_array( $_POST['from'], self::FROM, true ) === true ? $_POST['from'] : 'belege';
		$id = is_string( $_POST['document'] ?? null ) === true && preg_match( '/^[1-9][0-9]{0,9}$/D', $_POST['document'] ) === 1 ? (int) $_POST['document'] : 0;
		$document = $id === 0 ? null : Documents::get( $appData, $id );

		// The client's document, worked out once for every path below: another client's offer is not told apart from one that is not there, and no answer names its project
		if( $document !== null && $document['client_id'] !== $client['id'] )
			$document = null;

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired', $from, $document );

			return;
		}

		if( $document === null || $document['released'] !== true || $document['pdf_path'] === '' || Documents::orderable( $document ) === false ) {
			self::redirect( $appData, $request, 'invalid', $from, $document );

			return;
		}

		$standing = Records::privacy( $appData, $client['id'] );

		if( $standing['asked'] === true && $standing['agreed'] === false ) {
			self::redirect( $appData, $request, 'privacy', $from, $document );

			return;
		}

		// What the customer read is what is kept: the form carries the hash of the offer as it showed it (and the sentence), and an offer that was changed meanwhile is not ordered by a click on the old one
		$sentence = self::sentence( $appData, $document );

		if( ( $_POST['shown'] ?? null ) !== Records::hash( Records::orderShown( $appData, $document, $sentence ) ) ) {
			self::redirect( $appData, $request, 'changed', $from, $document );

			return;
		}

		if( ( $_POST['agree'] ?? null ) !== '1' ) {
			self::redirect( $appData, $request, 'unchecked', $from, $document );

			return;
		}

		$ordered = Documents::order( $appData, $id, $client['id'], [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], $sentence, Records::source( $appData, $request ) );

		if( is_string( $ordered ) === true ) {
			self::redirect( $appData, $request, 'invalid', $from, $document );

			return;
		}

		// The page promises the confirmation only where a mail went out; the order is kept either way
		$mailed = Documents\Mail::ordered( $appData, $ordered['document'], $ordered['record'], $client );
		self::redirect( $appData, $request, is_int( $mailed ) === true && $mailed > 0 ? 'ordered' : 'nomail', $from, $ordered['document'] );
	}

	/**
	 *	The line that says an offer was ordered, and by whom
	 *
	 *	@param		array 		$record				As Records::of() has it
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function done( array $record, string $dir ): string {

		$name = (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'];

		return str_replace(
			[ '[[id]]', '[[iso]]', '[[date]]', '[[name]]' ],
			[ (string) $record['document_id'], \Nino\Modules\Components::escape( substr( (string) $record['at'], 0, 10 ) ), \Nino\Modules\Components::escape( Documents\Render::day( substr( (string) $record['at'], 0, 10 ) ) ), \Nino\Modules\Components::escape( $name ) ],
			Portal::template( 'order-done' )
		);
	}

	/**
	 *	The redirect to the page the form stood on, with a state
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				One of STATES
	 *	@param		string		$from					One of FROM
	 *	@param		array|null	$document			The offer, as get() has it, where it is the client's: its project is the page of the project
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state, string $from, ?array $document ): void {

		$project = $document !== null && $from === 'projekt' && preg_match( Projekte::ID, (string) $document['project'] ) === 1 ? (string) $document['project'] : '';
		$target = $project === '' ? self::PAGE_DOCUMENTS. '?state='. $state : self::PAGE_PROJECTS. '?projekt='. rawurlencode( $project ). '&state='. $state;

		\Nino\Http::redirect( $appData, $request, $target, 303 );
	}
}

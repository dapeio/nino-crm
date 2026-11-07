<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Order		The customer orders an offer in the portal. Under the content of an offer - on the page of its project and in the list of the
 *											documents - stands the form of the order while the offer can be ordered (an offer that is released to the portal, open or sent and
 *											not past its day): the sentence "Ich beauftrage <company> verbindlich mit dem Angebot <number> zu den genannten Konditionen." as the label of a
 *											box that must be ticked, and the button. While the client has not agreed to the Datenschutz there is no form: the list that holds the offers
 *											says once, at its top, that the Datenschutz comes first, with the way to it (privacyHint()). An offer that was ordered says when and by whom instead.
 *
 *											POST /.crm/auftrag takes the form. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404,
 *											as the other forms of the portal answer a stranger). Every answer is a redirect to the page the form stood on with a state in the query:
 *											ordered, invalid (the offer is none of the client's, is not released, cannot be ordered any more or is not there), unchecked (the box was
 *											not ticked), privacy (the Datenschutz comes first) or expired (the token of the form was not the session's). The order is Documents::order(): the
 *											record of what the customer was shown and the step of the offer to accepted are one transaction. Then the owner is mailed that the order
 *											was placed and the account gets the confirmation; a mail that does not go out does not undo the order.
 *
 *											Under the box that must be ticked stands one voluntary box for each consent the owner wrote a sentence for and the client does not hold yet for the
 *											project of the offer (Consent): never required, and the order does not need them. The JSON the form's hash is made of holds the sentences of the boxes the
 *											page showed, so that a sentence that was changed meanwhile answers the post with the state changed; a ticked box is a record of kind consent in the
 *											transaction of the order, and a key of consent that names no box that was shown is ignored. The mails say which consents were given.
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;
use Crm\Records;

class Order {

	// The route the form posts to, the pages it goes back to
	public const string ROUTE = '/.crm/auftrag';
	public const string ROUTE_DECLINE = '/.crm/ablehnen';
	public const string PAGE_DOCUMENTS = '/portal/belege';
	public const string PAGE_PROJECTS = '/portal/projekte';

	// The states a page says - each with the tone and the icon of its note and whether it is a status or an alert -, and where the form was: the list
	// of documents or the page of a project
	public const array STATES = [
		'ordered'   => [ 'success', 'circle-check', 'status' ],
		'nomail'    => [ 'success', 'circle-check', 'status' ],
		'invalid'   => [ 'error', 'circle-alert', 'alert' ],
		'unchecked' => [ 'error', 'circle-alert', 'alert' ],
		'privacy'   => [ 'warning', 'shield-check', 'alert' ],
		'expired'   => [ 'error', 'circle-alert', 'alert' ],
		'changed'   => [ 'error', 'circle-alert', 'alert' ],
		'declined'  => [ 'success', 'circle-check', 'status' ],
		'reason'    => [ 'error', 'circle-alert', 'alert' ],
		'paid-notice' => [ 'success', 'circle-check', 'status' ],
	];
	public const array FROM = [ 'belege', 'projekt', 'uebersicht' ];

	/**
	 *	The part of an offer's content that is about ordering it
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		array 		$offer				A document of the client: id, category, number, title, state, valid_until, expired and project (Documents::released() has them as Documents::get() does)
	 *	@param		string		$from					belege (the list of documents), projekt (the tab Angebot of a project) or uebersicht (its tab Übersicht)
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										The form; the line that says it was ordered; or nothing for an offer that cannot be ordered and while the Datenschutz comes first
	 */
	public static function block( array &$appData, array $client, array $offer, string $from, string $dir ): string {

		if( $offer['category'] !== 'offer' )
			return '';

		$record = Records::forDocument( $appData, (int) $offer['id'] );

		if( $record !== null )
			return self::done( $record, $dir ). Consent::done( $appData, (int) $offer['id'], $dir );

		$declined = Records::declineOf( $appData, (int) $offer['id'] );

		if( $declined !== null && $offer['state'] === 'declined' )
			return self::declinedLine( $declined );

		if( Documents::orderable( $offer ) === false || Portal::pending( $appData, $client ) === 'privacy' )
			return '';

		$full = Documents::get( $appData, (int) $offer['id'] );

		if( $full === null )
			return '';

		$sentence = self::sentence( $appData, $offer );
		$offered = Consent::offered( $appData, $client, (string) $full['project'] );

		return Portal::render( 'order-form', [
			'[[id]]'       => (string) $offer['id'],
			'[[dir]]'      => $dir,
			'[[from]]'     => $from,
			'[[sentence]]' => \Nino\Modules\Components::escape( $sentence ),
			'[[consents]]' => Consent::boxes( $offered, $from, (int) $offer['id'] ),
			'[[hash]]'     => Records::hash( Records::orderShown( $appData, $full, $sentence, $offered ) ),
		] ). self::ways( $appData, $full, $from, $dir );
	}

	/**
	 *	The two quiet ways under the form: the fold that declines the offer and the question by mail. The form of the decline carries the hash of the offer as it is shown; the question is
	 *	a link to the contact address of the company with the number as the subject - and nothing where the site has no valid address
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$document			As Documents::get() has it
	 *	@param		string		$from					One of FROM
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function ways( array &$appData, array $document, string $from, string $dir ): string {

		Portal::words( $appData );

		$address = Portal::contactMail( $appData );
		$name = (string) $document['number'] !== '' ? (string) $document['number'] : (string) $document['title'];
		$subject = str_replace( '{number}', (string) preg_replace( '/[\s\p{C}]+/u', ' ', $name ), (string) \Nino\Html::resolveTextfill( $appData, '/project/crm-portal/order/question-subject' ) );

		// The address and the subject are encoded for the query of the link: an address may hold characters that end a query, the @ stays
		$question = $address === '' ? '' : Portal::render( 'order-question', [ '[[href]]' => \Nino\Modules\Components::escape( 'mailto:'. str_replace( '%40', '@', rawurlencode( $address ) ). '?subject='. rawurlencode( $subject ) ) ] );

		return Portal::render( 'order-ways', [
			'[[decline]]'  => Portal::render( 'order-decline', [
				'[[id]]'   => (string) $document['id'],
				'[[dir]]'  => $dir,
				'[[from]]' => $from,
				'[[hash]]' => Records::hash( Records::declineShown( $appData, $document, '' ) ),
				'[[max]]'  => (string) Records::REASON,
			] ),
			'[[question]]' => $question,
		] );
	}

	/**
	 *	The line that says an offer was declined, and by whom
	 *
	 *	@param		array 		$record				As Records::declineOf() has it
	 *
	 *	@return 	string
	 */
	private static function declinedLine( array $record ): string {

		$name = (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'];

		return Portal::render( 'order-declined', [
			'[[id]]'   => (string) $record['document_id'],
			'[[iso]]'  => \Nino\Modules\Components::escape( substr( (string) $record['at'], 0, 10 ) ),
			'[[date]]' => \Nino\Modules\Components::escape( Documents\Render::day( substr( (string) $record['at'], 0, 10 ) ) ),
			'[[name]]' => \Nino\Modules\Components::escape( $name ),
		] );
	}

	/**
	 *	The sentence that the Datenschutz comes first, with the way to it: said once at the top of a list that holds an offer that could be ordered
	 *
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	public static function privacyHint( string $dir ): string {

		return Portal::render( 'order-privacy', [ '[[id]]' => '', '[[dir]]' => $dir ] );
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
	 *	The sentence about the state the query names, for the page the form stood on: in the card of the offer the query names (?beleg=<id>) where
	 *	it names one, else at the top of the list or the section. A form that stood in the tab Übersicht (?tab=uebersicht) is answered there and nowhere else
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$for					The document whose card asks, 0 for the slot at the top
	 *	@param		string		$at						'' for the places of the list and the tab Angebot, uebersicht for the card of the tab Übersicht
	 *
	 *	@return 	string										'' where the query names no state, or names it for another place
	 */
	public static function state( array &$appData, int $for = 0, string $at = '' ): string {

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true ? $query['state'] : '';
		$overview = ( $query['tab'] ?? null ) === 'uebersicht';

		// The states of a request for the tab Dateien are those of the approval
		if( $state === '' || Approval::asked( $appData ) === true || self::named( $appData ) !== $for || $overview !== ( $at === 'uebersicht' ) )
			return '';

		return Portal::render( 'order-state', [
			'[[state]]' => $state,
			'[[tone]]'  => self::STATES[$state][0],
			'[[icon]]'  => self::STATES[$state][1],
			'[[role]]'  => self::STATES[$state][2],
			'[[id]]'    => (string) $for,
			'[[at]]'    => $at === '' ? '' : '-'. $at,
			'[[more]]'  => in_array( $state, [ 'ordered', 'nomail' ], true ) === true ? self::filesFree( $appData, $for ) : '',
		] );
	}

	/**
	 *	The sentence after the note of an order that says what the order opened: the files of the project are free to listen to, as a link to the tab Dateien. Only where the offer is
	 *	the client's, has a project of the client and the project has files for the member to see
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$for					The offer the query names
	 *
	 *	@return 	string										Markup, with the blank before it; '' for none
	 */
	private static function filesFree( array &$appData, int $for ): string {

		$client = Portal::client( $appData );
		$document = $for === 0 || $client === null ? null : Documents::get( $appData, $for );

		if( $document === null || $document['client_id'] !== $client['id'] || preg_match( Projekte::ID, (string) $document['project'] ) !== 1 )
			return '';

		$project = Projekte::get( $appData, $document['project'] );

		if( $project === null || $project['client'] !== $client['number'] || ( Projects::visibleOf( $appData, $project ) ?? [] ) === [] )
			return '';

		return ' '. Portal::render( 'order-files', [ '[[dir]]' => \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) ), '[[project]]' => rawurlencode( $project['id'] ) ] );
	}

	/**
	 *	The document the query names beside a state (?beleg=<id>): the one whose card says the sentence
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	int												0 where it names none
	 */
	public static function named( array &$appData ): int {

		$query = Portal::query( $appData );

		return is_string( $query['beleg'] ?? null ) === true && preg_match( '/^[1-9][0-9]{0,9}$/D', $query['beleg'] ) === 1 ? (int) $query['beleg'] : 0;
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
			self::notFound( $request );

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

		if( Portal::pending( $appData, $client ) === 'privacy' ) {
			self::redirect( $appData, $request, 'privacy', $from, $document );

			return;
		}

		// What the customer read is what is kept: the form carries the hash of the offer as it showed it (and the sentence), and an offer that was changed meanwhile is not ordered by a click on the old one
		$sentence = self::sentence( $appData, $document );
		$offered = Consent::offered( $appData, $client, (string) $document['project'] );

		if( ( $_POST['shown'] ?? null ) !== Records::hash( Records::orderShown( $appData, $document, $sentence, $offered ) ) ) {
			self::redirect( $appData, $request, 'changed', $from, $document );

			return;
		}

		if( ( $_POST['agree'] ?? null ) !== '1' ) {
			self::redirect( $appData, $request, 'unchecked', $from, $document );

			return;
		}

		$ordered = Documents::order( $appData, $id, $client['id'], [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], $sentence, Records::source( $appData, $request ), $offered, Consent::ticked( $offered ) );

		if( is_string( $ordered ) === true ) {
			self::redirect( $appData, $request, 'invalid', $from, $document );

			return;
		}

		// The page promises the confirmation only where a mail went out; the order is kept either way
		$mailed = Documents\Mail::ordered( $appData, $ordered['document'], $ordered['record'], $client, $ordered['consents'] );
		self::redirect( $appData, $request, is_int( $mailed ) === true && $mailed > 0 ? 'ordered' : 'nomail', $from, $ordered['document'] );
	}

	/**
	 *	'/nino/http/response/POST://.crm/ablehnen': the fold under an offer. A visitor and a member of no client are answered 404; a token that is not the session's is the redirect with
	 *	?state=expired; an offer that is not the client's, is not released, cannot be ordered any more or is not there is ?state=invalid; a reason that is no text or too long is ?state=reason;
	 *	an offer that was changed since the page was drawn is ?state=changed. Else the decline is made (Documents::decline()), the owner is mailed and the answer is ?state=declined; a mail that
	 *	does not go out leaves the decline kept
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackDecline( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {
			self::notFound( $request );

			return;
		}

		$from = is_string( $_POST['from'] ?? null ) === true && in_array( $_POST['from'], self::FROM, true ) === true ? $_POST['from'] : 'belege';
		$id = is_string( $_POST['document'] ?? null ) === true && preg_match( '/^[1-9][0-9]{0,9}$/D', $_POST['document'] ) === 1 ? (int) $_POST['document'] : 0;
		$document = $id === 0 ? null : Documents::get( $appData, $id );

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

		if( ( $_POST['shown'] ?? null ) !== Records::hash( Records::declineShown( $appData, $document, '' ) ) ) {
			self::redirect( $appData, $request, 'changed', $from, $document );

			return;
		}

		$reason = Records::reason( $_POST['reason'] ?? null );

		if( $reason === null ) {
			self::redirect( $appData, $request, 'reason', $from, $document );

			return;
		}

		$declined = Documents::decline( $appData, $id, $client['id'], [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], $reason, Records::source( $appData, $request ) );

		if( is_string( $declined ) === true ) {
			self::redirect( $appData, $request, 'invalid', $from, $document );

			return;
		}

		Documents\Mail::declined( $appData, $declined['document'], $declined['record'], $client, $reason );
		self::redirect( $appData, $request, 'declined', $from, $declined['document'] );
	}

	/**
	 *	The answer to a post of someone who is no member of a client: 404, as the other forms of the portal answer a stranger
	 *
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function notFound( array &$request ): void {

		$request['/nino/http/response']['statusCode']								= 404;
		$request['/nino/http/response']['body']										= 'Not Found';
		$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';
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

		return Portal::render( 'order-done', [
			'[[id]]'   => (string) $record['document_id'],
			'[[iso]]'  => \Nino\Modules\Components::escape( substr( (string) $record['at'], 0, 10 ) ),
			'[[date]]' => \Nino\Modules\Components::escape( Documents\Render::day( substr( (string) $record['at'], 0, 10 ) ) ),
			'[[name]]' => \Nino\Modules\Components::escape( $name ),
		] );
	}

	/**
	 *	The redirect to the page the form stood on, with a state - and, where the offer is still shown, the offer it is about (?beleg=<id>) and the
	 *	anchor of its card, so that the sentence stands at the offer: #angebot-<id> in the section Angebot of the project page, #beleg-<id> in the list
	 *	of documents, #crm-needs in the tab Übersicht. An offer that may not be shown any more (invalid) is not named: the sentence stands at the top
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				A key of STATES
	 *	@param		string		$from					One of FROM
	 *	@param		array|null	$document			The offer, as get() has it, where it is the client's: its project is the page of the project
	 *
	 *	@return 	void
	 */
	public static function redirect( array &$appData, array &$request, string $state, string $from, ?array $document ): void {

		$project = $document !== null && $from !== 'belege' && preg_match( Projekte::ID, (string) $document['project'] ) === 1 ? (string) $document['project'] : '';
		$tab = $from === 'uebersicht' ? 'uebersicht' : 'angebot';
		$beleg = $document !== null && $state !== 'invalid' ? '&beleg='. $document['id'] : '';

		// The anchor is the card of the offer; in the tab Übersicht the card of what waits, whether an offer is named or not
		if( $project !== '' && $tab === 'uebersicht' )
			$named = $beleg. '#crm-needs';
		else
			$named = $beleg === '' ? '' : $beleg. '#'. ( $project === '' ? 'beleg-' : 'angebot-' ). $document['id'];

		$target = $project === '' ? self::PAGE_DOCUMENTS. '?state='. $state. $named : self::PAGE_PROJECTS. '?projekt='. rawurlencode( $project ). '&tab='. $tab. '&state='. $state. $named;

		\Nino\Http::redirect( $appData, $request, $target, 303 );
	}
}

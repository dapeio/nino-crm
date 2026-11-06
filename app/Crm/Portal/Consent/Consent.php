<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Consent		What the customer agrees to beyond the contract, and takes back. The owner writes one sentence per purpose in the settings (consent_social, the
 *												use of the work for the references and channels of the studio, and consent_archive, the keeping of it beyond the periods); where the sentence is
 *												empty there is no box. Under the box that must be ticked to order an offer stands one box for each purpose that has a sentence and for which the client
 *												holds no consent yet for the project of the offer (offered(), boxes()): voluntary - never required, with the word freiwillig before the sentence -, apart
 *												from the order and from the Datenschutz. The order carries the sentences of the boxes it showed, so that a sentence the owner changed meanwhile
 *												answers the post with the state changed (Order); a ticked box becomes a record of kind consent in the transaction of the order (Documents::order()).
 *												Once the offer was ordered its card says which consents were given with it (done()) and where they are taken back.
 *
 *												The page Meine Daten has the block Einwilligungen (block()) when the client holds at least one consent: a row for each, newest first, with the purpose,
 *												the project, who gave it and when, the sentence it was given with (folded) and - while it holds - the button Widerrufen. POST /.crm/einwilligung/widerruf
 *												takes the form. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404); a token that is not the session's is
 *												the redirect with ?state=expired, a consent that is none of the client's or is taken back already is ?state=invalid, and a client that has to take note of the
 *												Datenschutz first is sent to that page (the step of the data is Meine Daten, where the button stands: it stops nothing). Else the record of kind revocation is kept (Records::revoke(): it holds the purpose, the project and the sentence of the
 *												consent), the owner is mailed - the customer is not - and the answer is ?state=revoked, or nomail where the mail did not go out. Every answer is a redirect to
 *												Meine Daten, to the block (#crm-consents), with consent=1 beside the state, which says that the state is the block's and not the form's. A revocation acts for
 *												the future and deletes nothing.
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;
use Crm\Projekte;
use Crm\Records;

class Consent {

	// The route the form posts to, and the block it goes back to
	public const string ROUTE = '/.crm/einwilligung/widerruf';
	public const string ANCHOR = 'crm-consents';

	// The states the block says - each with the tone and the icon of its note and whether it is a status or an alert
	public const array STATES = [
		'revoked' => [ 'success', 'circle-check', 'status' ],
		'nomail'  => [ 'success', 'circle-check', 'status' ],
		'invalid' => [ 'error', 'circle-alert', 'alert' ],
		'expired' => [ 'error', 'circle-alert', 'alert' ],
	];

	/**
	 *	The boxes the order of an offer shows: the purposes that have a sentence and for which the client holds no consent for the project
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		string		$project			The project of the offer, '' for none
	 *
	 *	@return 	array											purpose => the sentence, in the order of Records::PURPOSES
	 */
	public static function offered( array &$appData, array $client, string $project ): array {

		$offered = [];

		foreach( Records::PURPOSES as $purpose ) {

			$sentence = Records::sentence( $appData, $purpose );

			if( $sentence !== '' && Records::consent( $appData, (int) $client['id'], $purpose, $project ) === null )
				$offered[$purpose] = $sentence;
		}

		return $offered;
	}

	/**
	 *	The purposes of the boxes that were ticked in a post
	 *
	 *	@param		array 		$offered			See offered()
	 *
	 *	@return 	array											The purposes among those offered whose box the post ticked (consent[<purpose>]=1); any other key is ignored
	 */
	public static function ticked( array $offered ): array {

		$posted = is_array( $_POST['consent'] ?? null ) === true ? $_POST['consent'] : [];

		return array_values( array_filter( array_keys( $offered ), static fn( string $purpose ): bool => ( $posted[$purpose] ?? null ) === '1' ) );
	}

	/**
	 *	The boxes under the box of the order
	 *
	 *	@param		array 		$offered			See offered()
	 *	@param		string		$from					belege or projekt (Order::FROM)
	 *	@param		int				$id						The offer
	 *
	 *	@return 	string										Markup, the boxes in a group that names them as voluntary; '' where none is offered. The sentence is text: its line breaks are <br>, all else is escaped
	 */
	public static function boxes( array $offered, string $from, int $id ): string {

		$boxes = '';

		if( $offered === [] )
			return '';

		foreach( $offered as $purpose => $sentence )
			$boxes .= Portal::render( 'order-consent', [
				'[[purpose]]'  => $purpose,
				'[[field]]'    => 'consent['. $purpose. ']',
				'[[from]]'     => $from,
				'[[id]]'       => (string) $id,
				'[[sentence]]' => self::text( $sentence ),
			] );

		return Portal::render( 'order-consents', [ '[[boxes]]' => $boxes ] );
	}

	/**
	 *	What the card of an ordered offer says of the consents given with the order: a line for each, and where one holds the sentence that it is taken back under Meine Daten
	 *
	 *	@param		array 		&$appData
	 *	@param		int				$document			The offer
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										'' where the order gave none
	 */
	public static function done( array &$appData, int $document, string $dir ): string {

		$lines = '';
		$holds = false;

		foreach( array_reverse( Records::consentsOfDocument( $appData, $document ) ) as $consent ) {

			$record = $consent['revoked'] ?? $consent;
			$holds = $holds || $consent['revoked'] === null;
			$lines .= Portal::render( 'order-consent-done', [
				'[[purpose]]' => (string) $consent['purpose'],
				'[[state]]'   => $consent['revoked'] === null ? 'given' : 'revoked',
				'[[icon]]'    => $consent['revoked'] === null ? 'check' : 'x',
				'[[tone]]'    => $consent['revoked'] === null ? 'success' : 'info',
				'[[when]]'    => $consent['revoked'] === null ? '[[/project/crm-portal/consent/on]]' : '[[/project/crm-portal/consent/revoked-on]]',
				'[[iso]]'     => \Nino\Modules\Components::escape( substr( (string) $record['at'], 0, 10 ) ),
				'[[date]]'    => \Nino\Modules\Components::escape( Documents\Render::day( substr( (string) $record['at'], 0, 10 ) ) ),
				'[[name]]'    => \Nino\Modules\Components::escape( self::who( $record ) ),
			] );
		}

		return $lines === '' ? '' : $lines. ( $holds === true ? Portal::render( 'order-consent-hint', [ '[[dir]]' => $dir, '[[page]]' => Data::PAGE, '[[anchor]]' => self::ANCHOR ] ) : '' );
	}

	/**
	 *	The block Einwilligungen of the page Meine Daten
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *	@param		string		$dir					The directory the project is served from, escaped
	 *
	 *	@return 	string										'' where the client holds no consent at all
	 */
	public static function block( array &$appData, array $client, string $dir ): string {

		$consents = Records::consents( $appData, (int) $client['id'] );

		if( $consents === [] )
			return '';

		$rows = '';

		foreach( $consents as $consent )
			$rows .= self::row( $appData, $consent, $dir );

		return Portal::render( 'consent', [ '[[anchor]]' => self::ANCHOR, '[[state]]' => self::state( $appData ), '[[rows]]' => $rows ] );
	}

	/**
	 *	The sentence about the state the query names (?state=&consent=1)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										'' where the query names none for the block
	 */
	public static function state( array &$appData ): string {

		$state = self::asked( $appData );

		return $state === '' ? '' : Portal::render( 'consent-state', [ '[[state]]' => $state, '[[tone]]' => self::STATES[$state][0], '[[icon]]' => self::STATES[$state][1], '[[role]]' => self::STATES[$state][2] ] );
	}

	/**
	 *	The state of the block the query names
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										A key of STATES; '' where the query has none or is not about the block
	 */
	public static function asked( array &$appData ): string {

		$query = Portal::query( $appData );

		return ( $query['consent'] ?? null ) === '1' && is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true ? $query['state'] : '';
	}

	/**
	 *	'/nino/http/response/POST://.crm/einwilligung/widerruf': the button of a consent in the block Einwilligungen. A visitor and a member of no client are answered 404; a
	 *	token that is not the session's is the redirect with ?state=expired; a client that has to take note of the Datenschutz first is sent to that page; a consent that is not the
	 *	client's, is none or is taken back is ?state=invalid. Else the revocation is kept, the owner is mailed and the answer is ?state=revoked (nomail where the mail did not go out)
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackRevoke( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {

			$request['/nino/http/response']['statusCode']								= 404;
			$request['/nino/http/response']['body']										= 'Not Found';
			$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

			return;
		}

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired' );

			return;
		}

		// The Datenschutz comes first; the step of the data is the page Meine Daten itself, where the button stands, so it stops nothing
		if( Portal::pending( $appData, $client ) === 'privacy' ) {
			\Nino\Http::redirect( $appData, $request, Portal::stepAddress( 'privacy' ), 303 );

			return;
		}

		$id = is_string( $_POST['id'] ?? null ) === true && preg_match( '/^[1-9][0-9]{0,9}$/D', $_POST['id'] ) === 1 ? (int) $_POST['id'] : 0;
		$record = $id === 0 ? 'parent' : Records::revoke( $appData, (int) $client['id'], $id, [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], Records::source( $appData, $request ) );

		if( is_array( $record ) === false ) {
			self::redirect( $appData, $request, 'invalid' );

			return;
		}

		// The page promises nothing about the mail to the owner except that the revocation is kept; it says so where the mail did not go out
		$mailed = Documents\Mail::revoked( $appData, $record, $client );
		self::redirect( $appData, $request, is_int( $mailed ) === true && $mailed > 0 ? 'revoked' : 'nomail' );
	}

	/**
	 *	A row of the block: one consent
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$consent			As Records::consents() has it
	 *	@param		string		$dir					Escaped
	 *
	 *	@return 	string
	 */
	private static function row( array &$appData, array $consent, string $dir ): string {

		$project = (string) $consent['project'] === '' ? null : Projekte::get( $appData, (string) $consent['project'] );
		$revoked = $consent['revoked'];
		$escape = static fn( string $value ): string => \Nino\Modules\Components::escape( $value );

		if( $project === null )
			$where = (string) $consent['project'] === '' ? '[[/project/crm-portal/consent/project-all]]' : $escape( (string) $consent['project'] );
		else
			$where = Portal::render( 'consent-project', [
				'[[href]]'  => $escape( Order::PAGE_PROJECTS. '?projekt='. rawurlencode( (string) $project['id'] ) ),
				'[[dir]]'   => $dir,
				'[[title]]' => $escape( (string) $project['title'] !== '' ? (string) $project['title'] : (string) $project['id'] ),
			] );

		return Portal::render( 'consent-row', [
			'[[id]]'       => (string) $consent['id'],
			'[[purpose]]'  => (string) $consent['purpose'],
			'[[state]]'    => $revoked === null ? 'given' : 'revoked',
			'[[where]]'    => $where,
			'[[iso]]'      => $escape( substr( (string) $consent['at'], 0, 10 ) ),
			'[[date]]'     => $escape( Documents\Render::day( substr( (string) $consent['at'], 0, 10 ) ) ),
			'[[name]]'     => $escape( self::who( $consent ) ),
			'[[sentence]]' => self::text( (string) $consent['shown'] ),
			'[[after]]'    => $revoked === null
				? Portal::render( 'consent-form', [ '[[id]]' => (string) $consent['id'], '[[dir]]' => $dir ] )
				: Portal::render( 'consent-revoked', [
					'[[iso]]'  => $escape( substr( (string) $revoked['at'], 0, 10 ) ),
					'[[date]]' => $escape( Documents\Render::day( substr( (string) $revoked['at'], 0, 10 ) ) ),
					'[[name]]' => $escape( self::who( $revoked ) ),
				] ),
		] );
	}

	/**
	 *	A sentence of the owner as the page shows it: text, with its line breaks as <br>
	 *
	 *	@param		string		$sentence
	 *
	 *	@return 	string
	 */
	private static function text( string $sentence ): string {

		return str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( str_replace( "\0", '', $sentence ) ) );
	}

	/**
	 *	@param		array 		$record				member_name and member_email
	 *
	 *	@return 	string										The name of the account that agreed, its address where it has no name
	 */
	private static function who( array $record ): string {

		return (string) $record['member_name'] !== '' ? (string) $record['member_name'] : (string) $record['member_email'];
	}

	/**
	 *	The redirect to the block, with a state
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				A key of STATES
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state ): void {

		\Nino\Http::redirect( $appData, $request, Data::PAGE. '?state='. $state. '&consent=1#'. self::ANCHOR, 303 );
	}
}

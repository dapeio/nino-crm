<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Message		The messages the owner wrote to the client, as the portal shows them over the head of a page, and the member's way to put one away. A message is
 *												its text - the one to every client (the setting portal_message) and the one to this client (its message) - and the SHA-256 of that text names it:
 *												POST /.crm/nachricht/weg keeps a row (the member, the sum, the time) in the table dismissals, and the card of a message whose sum has a row of
 *												the signed-in member is not drawn. A message the owner changes has another sum and is there again; every member puts away for itself, and nobody
 *												else sees a trace of it (no count in the surface, no entry in a log, no mail).
 *
 *												Only the signed-in member of a client may post (a visitor and a member of no client are answered 404). A token that is not the session's is the
 *												redirect to the page with ?state=expired; a sum that is no message of the member now - a text the owner has changed since the page was drawn, or
 *												one that was never written - keeps no row. Every answer that is no 404 is a redirect (303) to the page the form stood on (weiter, see
 *												Portal::onward()), the start page of the portal where it names none.
 */
namespace Crm\Portal;

use Crm\Db;
use Crm\Portal;
use Crm\Settings;

class Message {

	// The route the form posts to and the page it goes back to where it names none
	public const string ROUTE = '/.crm/nachricht/weg';
	public const string PAGE = '/portal';

	// The parts of the query a page says once and a redirect to it does not repeat
	private const array TRANSIENT = [ 'state', 'field', 'why', 'login', 'sign', 'sign-state' ];

	/**
	 *	The messages the client reads now: the one to every client first, then the one to this client - those with a text
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$client				As Clients::get() has it
	 *
	 *	@return 	array											A list of text (without a null byte, trimmed), at (the time it was written, UTC) and sha256 (of the text)
	 */
	public static function current( array &$appData, array $client ): array {

		$all = Settings::all( $appData );
		$messages = [];

		foreach( [ [ (string) $all['portal_message'], (string) $all['portal_message_at'] ], [ (string) $client['message'], (string) $client['message_at'] ] ] as [ $text, $at ] ) {

			$text = trim( str_replace( "\0", '', $text ) );

			if( $text !== '' )
				$messages[] = [ 'text' => $text, 'at' => $at, 'sha256' => hash( 'sha256', $text ) ];
		}

		return $messages;
	}

	/**
	 *	The sums of the messages a member has put away
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$member				The id of the member
	 *
	 *	@return 	array											A list of sums
	 */
	public static function dismissed( array &$appData, string $member ): array {

		return array_column( Db::rows( $appData, 'SELECT sha256 FROM dismissals WHERE member = :member', [ 'member' => $member ] ), 'sha256' );
	}

	/**
	 *	The form on the card of a message
	 *
	 *	@param		array 		&$appData
	 *	@param		string		$sum					The sum of the message
	 *
	 *	@return 	string										Markup
	 */
	public static function form( array &$appData, string $sum ): string {

		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		return Portal::render( 'message-form', [ '[[dir]]' => $dir, '[[sha256]]' => $sum, '[[weiter]]' => \Nino\Modules\Components::escape( self::here( $appData ) ) ] );
	}

	/**
	 *	The page the request is for, with its query but the parts a page says once: what a form of the card goes back to
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	string										A path under /portal with its query as Portal::onward() has it; '' for any other page
	 */
	private static function here( array &$appData ): string {

		$request = \Nino\Http::getRequest( $appData );
		$query = [];

		foreach( Portal::query( $appData ) as $name => $value )
			if( is_string( $value ) === true && preg_match( '/^[A-Za-z0-9_-]{1,40}$/D', (string) $name ) === 1 && in_array( $name, self::TRANSIENT, true ) === false )
				$query[(string) $name] = $value;

		return Portal::onward( (string) ( $request === false ? '' : ( $request['/nino/http/request']['uri'] ?? '' ) ). ( $query === [] ? '' : '?'. http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) ) );
	}

	/**
	 *	'/nino/http/response/POST://.crm/nachricht/weg': the form on the card of a message
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackDismiss( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = $client === null ? null : \Nino\Modules\Members::current( $appData );

		if( $client === null || $member === null ) {

			$request['/nino/http/response']['statusCode']								= 404;
			$request['/nino/http/response']['body']										= 'Not Found';
			$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
			$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

			return;
		}

		$onward = Portal::onward( $_POST['weiter'] ?? null );
		$page = $onward === '' ? self::PAGE : $onward;

		if( \Nino\Form::blocked( $request ) === true ) {
			\Nino\Http::redirect( $appData, $request, $page. ( str_contains( $page, '?' ) === true ? '&' : '?' ). 'state=expired', 303 );

			return;
		}

		$sum = $_POST['sha256'] ?? null;

		// Only the sum of a message that is there now is kept: nothing else is a message of this member
		if( is_string( $sum ) === true && in_array( $sum, array_column( self::current( $appData, $client ), 'sha256' ), true ) === true )
			Db::exec( $appData, 'INSERT OR IGNORE INTO dismissals ( member, sha256, at ) VALUES ( :member, :sha256, :at )', [ 'member' => $member['id'], 'sha256' => $sum, 'at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ] );

		\Nino\Http::redirect( $appData, $request, $page, 303 );
	}
}

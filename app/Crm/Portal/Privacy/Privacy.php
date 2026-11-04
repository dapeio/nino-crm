<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Privacy		The Datenschutz in the portal: the component [crm-privacy] draws the text the owner keeps in the settings of the CRM (with its line breaks), where the
 *												client stands with it and - while it has not agreed - the form with the box and the button; POST /.crm/datenschutz keeps the agreement as a
 *												record (Records): the text as it was shown, its hash, the account, the time and the address. A client has agreed to the text of now; a text
 *												that is changed is asked again. Where the owner keeps no text the portal asks for nothing and says so.
 *
 *												Only the signed-in member of a client may post (a visitor and a member of no client are answered 404). Every answer is a redirect to the
 *												page with a state in the query: agreed (the record is kept - or was kept before; a second post makes no second record), unchecked (the box was
 *												not ticked), invalid (there is no text to agree to) or expired (the token of the form was not the session's). No mail goes out.
 */
namespace Crm\Portal;

use Crm\Documents;
use Crm\Portal;
use Crm\Records;
use Crm\Settings;

class Privacy {

	// The route the form posts to, and the page it comes from and goes back to
	public const string ROUTE = '/.crm/datenschutz';
	public const string PAGE = '/portal/datenschutz';

	private const array STATES = [ 'agreed', 'unchecked', 'invalid', 'expired', 'changed' ];

	/**
	 *	[crm-privacy] - the text, the state and the form. A member of no client is told that the page is for clients; nothing for a visitor
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmPrivacy( array &$appData, array $args ): string {

		if( \Crm\Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$client = Portal::client( $appData );

		if( $client === null )
			return str_replace( [ '[[classes]]', '[[message]]' ], [ $classes, '[[/project/crm-portal/privacy/none]]' ], Portal::template( 'privacy-none' ) );

		$text = Records::privacyText( $appData );

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && in_array( $query['state'], self::STATES, true ) === true ? $query['state'] : '';
		$said = static fn( string $name ): string => str_replace( [ '[[state]]', '[[role]]' ], [ $name, $name === 'agreed' ? 'status' : 'alert' ], Portal::template( 'privacy-state' ) );

		// There is nothing to agree to: a post without a text was refused (invalid) - the sentence says so; the same word in the address of a page that has a text says nothing wrong
		if( $text === '' )
			return ( $state === 'invalid' ? $said( 'invalid' ) : '' ). str_replace( [ '[[classes]]', '[[message]]' ], [ $classes, '[[/project/crm-portal/privacy/empty]]' ], Portal::template( 'privacy-none' ) );

		if( $state === 'invalid' )
			$state = '';

		$standing = Records::privacy( $appData, $client['id'] );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		if( $standing['agreed'] === true )
			$line = str_replace(
				[ '[[iso]]', '[[date]]', '[[name]]' ],
				[ \Nino\Modules\Components::escape( substr( $standing['at'], 0, 10 ) ), \Nino\Modules\Components::escape( Documents\Render::day( substr( $standing['at'], 0, 10 ) ) ), \Nino\Modules\Components::escape( $standing['member_name'] ) ],
				Portal::template( 'privacy-agreed' )
			);
		else {
			$changed = Settings::get( $appData, 'privacy_changed' );
			$line = str_replace( '[[changed]]', Records::latestPrivacy( $appData, $client['id'] ) === null || Documents\Render::day( $changed ) === '' ? '' : str_replace(
				[ '[[iso]]', '[[date]]' ], [ \Nino\Modules\Components::escape( $changed ), \Nino\Modules\Components::escape( Documents\Render::day( $changed ) ) ], Portal::template( 'privacy-changed' )
			), Portal::template( 'privacy-open' ) );
		}

		return str_replace(
			[ '[[classes]]', '[[state]]', '[[text]]', '[[standing]]', '[[form]]' ],
			[
				$classes,
				$state === '' ? '' : $said( $state ),
				str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( $text ) ),
				$line,
				$standing['agreed'] === true ? '' : str_replace( [ '[[dir]]', '[[hash]]' ], [ $dir, Records::hash( $text ) ], Portal::template( 'privacy-form' ) ),
			],
			Portal::template( 'privacy' )
		);
	}

	/**
	 *	The line about the Datenschutz for the page Meine Daten and the start page: whether the client has agreed and when. Empty where the owner keeps no text
	 *
	 *	@param		array 		$standing			As Records::privacy() has it
	 *
	 *	@return 	string										A sentence in the words of the portal, escaped
	 */
	public static function standingLine( array $standing ): string {

		if( $standing['agreed'] === false )
			return '[[/project/crm-portal/privacy/open]]';

		return '[[/project/crm-portal/privacy/agreed-on]] '. \Nino\Modules\Components::escape( Documents\Render::day( substr( $standing['at'], 0, 10 ) ) ). ' [[/project/crm-portal/privacy/agreed-by]] '. \Nino\Modules\Components::escape( $standing['member_name'] ). '.';
	}

	/**
	 *	'/nino/http/response/POST://.crm/datenschutz': the form of the page Datenschutz
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackAgree( array &$appData, array &$request ): void {

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

		$text = Records::privacyText( $appData );

		if( $text === '' ) {
			self::redirect( $appData, $request, 'invalid' );

			return;
		}

		// What the customer read is what is kept: the form carries the hash of the text it showed, and a text that was changed meanwhile is not agreed to by a click on the old one
		if( ( $_POST['shown'] ?? null ) !== Records::hash( $text ) ) {
			self::redirect( $appData, $request, 'changed' );

			return;
		}

		if( ( $_POST['agree'] ?? null ) !== '1' ) {
			self::redirect( $appData, $request, 'unchecked' );

			return;
		}

		// Posted twice - a double click, the back button - the text is agreed to once
		if( Records::privacy( $appData, $client['id'] )['agreed'] === false )
			Records::add( $appData, [
				'kind' => 'privacy', 'client_id' => $client['id'], 'member' => [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], 'shown' => $text,
			] + Records::source( $appData, $request ) );

		self::redirect( $appData, $request, 'agreed' );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				One of STATES
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state ): void {

		\Nino\Http::redirect( $appData, $request, self::PAGE. '?state='. $state, 303 );
	}
}

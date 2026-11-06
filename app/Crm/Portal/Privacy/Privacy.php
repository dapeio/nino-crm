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
 *
 *												What the portal keeps is that the client took note of the text, not a consent: the box says it has read it and taken note of it, the button confirms,
 *												and the lines of the standing say confirmed. The page is the first step of the gate of the client (Portal::pending()): its form carries the page to go on to
 *												(weiter, see Portal::onward()), and once the text is confirmed the form goes on to the next step or to that page.
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

	// The states a page says, each with the tone and the icon of its note and whether it is a status or an alert
	private const array STATES = [
		'agreed'    => [ 'success', 'circle-check', 'status' ],
		'unchecked' => [ 'error', 'circle-alert', 'alert' ],
		'invalid'   => [ 'error', 'circle-alert', 'alert' ],
		'expired'   => [ 'error', 'circle-alert', 'alert' ],
		'changed'   => [ 'warning', 'circle-alert', 'alert' ],
	];

	/**
	 *	[crm-privacy] - the text, the state and the form; while the page is the step the client is asked for (Portal::pending()) a note over it says so and where it goes
	 *	on to. A member of no client is told that the page is for clients; nothing for a visitor
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
			return Portal::render( 'privacy-none', [ '[[classes]]' => $classes, '[[state]]' => '', '[[empty]]' => Portal::emptyState( $appData, 'shield-check', '[[/project/crm-portal/privacy/none]]', 'crm-privacy__empty' ) ] );

		$text = Records::privacyText( $appData );

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true ? $query['state'] : '';
		$said = static fn( string $name ): string => Portal::render( 'privacy-state', [ '[[state]]' => $name, '[[tone]]' => self::STATES[$name][0], '[[icon]]' => self::STATES[$name][1], '[[role]]' => self::STATES[$name][2] ] );

		// There is nothing to agree to: a post without a text was refused (invalid) - the sentence says so; the same word in the address of a page that has a text says nothing wrong
		if( $text === '' )
			return Portal::render( 'privacy-none', [ '[[classes]]' => $classes, '[[state]]' => $state === 'invalid' ? $said( 'invalid' ) : '', '[[empty]]' => Portal::emptyState( $appData, 'shield-check', '[[/project/crm-portal/privacy/empty]]', 'crm-privacy__empty' ) ] );

		if( $state === 'invalid' )
			$state = '';

		$standing = Records::privacy( $appData, $client['id'] );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		if( $standing['agreed'] === true )
			$line = Portal::render( 'privacy-agreed', [
				'[[iso]]'  => \Nino\Modules\Components::escape( substr( $standing['at'], 0, 10 ) ),
				'[[date]]' => \Nino\Modules\Components::escape( Documents\Render::day( substr( $standing['at'], 0, 10 ) ) ),
				'[[name]]' => \Nino\Modules\Components::escape( $standing['member_name'] ),
			] );
		else {
			$changed = Settings::get( $appData, 'privacy_changed' );
			$line = Portal::render( 'privacy-open', [ '[[changed]]' => Records::latestPrivacy( $appData, $client['id'] ) === null || Documents\Render::day( $changed ) === '' ? '' : Portal::render( 'privacy-changed', [
				'[[iso]]' => \Nino\Modules\Components::escape( $changed ), '[[date]]' => \Nino\Modules\Components::escape( Documents\Render::day( $changed ) ),
			] ) ] );
		}

		return Portal::render( 'privacy', [
			'[[classes]]'  => $classes,
			'[[gate]]'     => Portal::gateNote( $appData, $client, 'privacy' ),
			'[[state]]'    => $state === '' ? '' : $said( $state ),
			'[[text]]'     => str_replace( [ "\r\n", "\r", "\n" ], '<br>', \Nino\Modules\Components::escape( $text ) ),
			'[[standing]]' => $line,
			'[[form]]'     => $standing['agreed'] === true ? '' : Portal::render( 'privacy-form', [ '[[dir]]' => $dir, '[[hash]]' => Records::hash( $text ), '[[weiter]]' => Portal::onwardField( $appData ) ] ),
		] );
	}

	/**
	 *	The line about the Datenschutz for the page Meine Daten and the start page: whether the client has confirmed it and when. Empty where the owner keeps no text
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

		$onward = Portal::onward( $_POST['weiter'] ?? null );

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired', $onward );

			return;
		}

		$text = Records::privacyText( $appData );

		if( $text === '' ) {
			self::redirect( $appData, $request, 'invalid', $onward );

			return;
		}

		// What the customer read is what is kept: the form carries the hash of the text it showed, and a text that was changed meanwhile is not agreed to by a click on the old one
		if( ( $_POST['shown'] ?? null ) !== Records::hash( $text ) ) {
			self::redirect( $appData, $request, 'changed', $onward );

			return;
		}

		if( ( $_POST['agree'] ?? null ) !== '1' ) {
			self::redirect( $appData, $request, 'unchecked', $onward );

			return;
		}

		// Posted twice - a double click, the back button - the text is agreed to once
		if( Records::privacy( $appData, $client['id'] )['agreed'] === false )
			Records::add( $appData, [
				'kind' => 'privacy', 'client_id' => $client['id'], 'member' => [ 'id' => $member['id'], 'email' => $member['email'], 'name' => $member['name'] ], 'shown' => $text,
			] + Records::source( $appData, $request ) );

		// The next step (the data of the invoice) or the page the member wanted; with neither, this page and its state
		self::redirect( $appData, $request, 'agreed', $onward, Portal::proceed( $appData, $client, $onward ) );
	}

	/**
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				One of STATES
	 *	@param		string		$onward				The page to go on to, as Portal::onward() has it; the page of the form keeps it
	 *	@param		string		$next					Where a confirmed text goes on to, as Portal::proceed() has it; '' for the page itself
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state, string $onward, string $next = '' ): void {

		\Nino\Http::redirect( $appData, $request, $next !== '' ? $next : self::PAGE. '?state='. $state. ( $onward === '' ? '' : '&weiter='. rawurlencode( $onward ) ), 303 );
	}
}

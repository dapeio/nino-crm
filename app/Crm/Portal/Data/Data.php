<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Data		The data of the client in the portal: the component [crm-client-data] draws the form of the page Meine Daten, and
 *										POST /.crm/daten keeps what the member typed - through Clients::change(), with its validation - and tells the owner by mail
 *										what changed. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404, as the feature
 *										Files answers a stranger). Every answer is a redirect to the page with a state in the query: saved, invalid (with the field
 *										that was refused) or expired (the token of the form was not the session's). What was typed under a refusal or an expired token is
 *										kept once, in the session of the member alone (never in the address): the next drawing of the form puts it into the fields -
 *										escaped, the text fields and the country code only - and drops it, and a page that is not that state drops it unseen. The sentence
 *										names the field. The e-mail of the account is Members' and is shown, never posted; the e-mail of the client is not on the form. A finalised
 *										document keeps the client it was made with (its client_json): the next document carries the new data.
 *
 *										Under the form stands the block Anmeldeadresse: the address the account signs in with and a way to change it that needs
 *										the new address - POST /.crm/anmeldeadresse asks Members for a code to it (askChange()), the second step of the block takes it
 *										(POST /.crm/anmeldeadresse/code, confirmChange()). Both answer like the form: a redirect with a state in the query - login=code
 *										for the second step, and for the state sent nothing else -, 404 for whoever is no member of a client, ?state=expired for a token
 *										that is not the session's. A box in the form of the Stammdaten does the same for the address of the client: with it, saving asks
 *										for the code as well and the page shows the second step under the note that it was saved. Nothing of what the address says is
 *										kept in the page: an address a code was asked for is read from the session, and every address is written escaped. The owner is
 *										mailed when the member has changed the address (callbackChanged(), what Members tells under '/members/changed').
 *
 *										The form is the second step of the gate of the client (Portal::pending()): street, postal code, city and country are required, and a name
 *										or a company - the server asks for all of it, Clients::change() does not (the owner may keep a client without an address). The form carries
 *										the page to go on to (weiter, see Portal::onward()); saved, it goes on to the next step or to that page. A name the client gives is the name of the
 *										member's account too, where the account has none. A form that was the pending step (the Stammdaten were missing) and is saved
 *										without a page to go on to leads the client to where something waits (Portal::landing(), else /portal), with ?state=saved,
 *										which the pages there say once as a note (Portal::savedNote()); a form saved by a client whose data were complete stays on its page.
 *
 *										Under the block Datenschutzerklärung stands the block Einwilligungen (Portal\Consent) while the client holds a consent: each with a button that takes it back.
 *										A state in the query that comes with consent=1 is the block's (revoked, invalid, expired), not the form's.
 */
namespace Crm\Portal;

use Crm\Clients;
use Crm\Crm;
use Crm\Documents;
use Crm\Portal;
use Crm\Records;

class Data {

	// The route the form posts to, and the page it comes from and goes back to
	public const string ROUTE = '/.crm/daten';
	public const string ROUTE_LOGIN = '/.crm/anmeldeadresse';
	public const string ROUTE_CODE = '/.crm/anmeldeadresse/code';
	public const string ROUTE_DEVICES = '/.crm/geraete/abmelden';
	public const string PAGE = '/portal/meine-daten';

	// The fields of the form, in their order: the client's fields the member may change, how the input is made, whether it takes the whole line and whether it is required
	// (a company or a name is required too, either of the two: the server asks it of the pair)
	public const array FIELDS = [
		'name'    => [ 'type' => 'text', 'autocomplete' => 'name', 'wide' => false, 'required' => false ],
		'company' => [ 'type' => 'text', 'autocomplete' => 'organization', 'wide' => false, 'required' => false ],
		'street'  => [ 'type' => 'text', 'autocomplete' => 'street-address', 'wide' => true, 'required' => true ],
		'zip'     => [ 'type' => 'text', 'autocomplete' => 'postal-code', 'wide' => false, 'required' => true ],
		'city'    => [ 'type' => 'text', 'autocomplete' => 'address-level2', 'wide' => false, 'required' => true ],
		'country' => [ 'type' => 'select', 'autocomplete' => 'country', 'wide' => true, 'required' => true ],
		'phone'   => [ 'type' => 'tel', 'autocomplete' => 'tel', 'wide' => false, 'required' => false ],
		'vat_id'  => [ 'type' => 'text', 'autocomplete' => 'off', 'wide' => false, 'required' => false ],
	];

	// The countries the select offers, by their code (Clients keeps the code): the neighbours and the usual ones, Deutschland first; the client's own
	// code is offered besides them where it is none of these, so that nothing is lost
	public const array COUNTRIES = [ 'DE', 'AT', 'CH', 'NL', 'BE', 'LU', 'DK', 'FR', 'IT', 'ES', 'PT', 'PL', 'CZ', 'SE', 'NO', 'FI', 'IE', 'GB', 'US' ];

	// The states a page says, each with the tone and the icon of its note and whether it is a status or an alert
	private const array STATES = [ 'saved' => [ 'success', 'circle-check', 'status' ], 'invalid' => [ 'error', 'circle-alert', 'alert' ], 'expired' => [ 'error', 'circle-alert', 'alert' ] ];

	// The states of the block Anmeldeadresse, in the same shape: what the two routes answer besides saved. invalid says it with the field login, and expired is said
	// in the block where the address names a step (login=change or login=code); login-invalid and login-expired are the keys of the words of invalid and expired
	private const array LOGIN_STATES = [
		'login-changed' => [ 'success', 'circle-check', 'status' ],
		'devices'       => [ 'success', 'circle-check', 'status' ],
		'devices-failed' => [ 'error', 'circle-alert', 'alert' ],
		'same'          => [ 'info', 'info', 'status' ],
		'limit'         => [ 'error', 'circle-alert', 'alert' ],
		'gone'          => [ 'error', 'circle-alert', 'alert' ],
		'wrong'         => [ 'error', 'circle-alert', 'alert' ],
		'login-invalid' => [ 'error', 'circle-alert', 'alert' ],
		'login-expired' => [ 'error', 'circle-alert', 'alert' ],
	];

	// Where what was typed is kept for the next drawing of the form, and the most of a value it keeps (the longest field plus the room a "too long" needs to show)
	private const string KEPT = './crm/portal/data-kept';
	private const int KEPT_MAX = 400;

	// The most of a name that the account of Members takes (Members keeps it private; the name of a client is held to the same length)
	private const int ACCOUNT_NAME = 120;


	/**
	 *	[crm-client-data] - the form with the client's company, name, address (the postal code and the city on one line), country (a select,
	 *	Deutschland where the client has none), phone and VAT id - street, postal code, city and country required, with a star -, the e-mail of the account beside it as a line that is only read, and a button;
	 *	under the form the block Anmeldeadresse - the address the account signs in with, the button that opens the form for a new one and, once a code is out,
	 *	the second step that asks for it (see loginBlock()) - and the block Datenschutzerklärung: whether the client has confirmed it, and the way to the page. While the form is the step
	 *	the client is asked for (Portal::pending()) a note over it says so and where it goes on to after it. The state the query names is said above the form, or in the block Anmeldeadresse
	 *	where it is one of that block's, and an invalid field beside the field. A box in the form offers the address of the client as the sign-in address, where the account has another.
	 *	A member of no client is told there is no client; nothing for a visitor

	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$args
	 *
	 *	@return 	string
	 */
	public static function componentCrmClientData( array &$appData, array $args ): string {

		if( Crm::featureOn( $appData, 'members' ) === false || \Nino\Modules\Members::current( $appData ) === null )
			return '';

		Portal::words( $appData );

		$classes = \Nino\Feature::classes( $args );
		$client = Portal::client( $appData );

		if( $client === null )
			return Portal::render( 'data-none', [ '[[classes]]' => $classes, '[[empty]]' => Portal::emptyState( $appData, 'user', '[[/project/crm-portal/data/none]]', 'crm-data__empty' ) ] );

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true && Consent::asked( $appData ) === '' ? $query['state'] : '';
		$field = is_string( $query['field'] ?? null ) === true && isset( self::FIELDS[$query['field']] ) === true ? $query['field'] : '';

		if( $state === 'invalid' && $field === '' )
			$state = '';

		// A token that ran out in the block Anmeldeadresse is said there, not above the form (its other states are none of STATES)
		if( $state === 'expired' && in_array( $query['login'] ?? null, [ 'change', 'code' ], true ) === true )
			$state = '';
		$why = is_string( $query['why'] ?? null ) === true ? $query['why'] : '';

		$fields = '';

		// What was typed under a refusal stands in the fields once; any other state drops it
		$typed = self::kept( $appData );
		$kept = $state === 'invalid' || $state === 'expired' ? (array) ( $typed['form'] ?? [] ) : [];
		$login = self::keptLogin( $query, (string) ( $typed['email'] ?? '' ) );

		foreach( self::FIELDS as $name => $kind ) {

			$invalid = $state === 'invalid' && $field === $name;
			$value = (string) ( $kept[$name] ?? $client[$name] ?? '' );
			$described = ( $invalid === true ? ' aria-invalid="true" aria-describedby="crm-data-'. $name. '-error"' : '' ). ( $kind['required'] === true ? ' required aria-required="true"' : '' );

			if( $kind['type'] === 'select' )
				$input = Portal::render( 'data-select', [ '[[name]]' => $name, '[[autocomplete]]' => $kind['autocomplete'], '[[described]]' => $described, '[[options]]' => self::countries( $value ) ] );
			else
				$input = Portal::render( 'data-input', [
					'[[name]]'         => $name,
					'[[type]]'         => $kind['type'],
					'[[autocomplete]]' => $kind['autocomplete'],
					'[[value]]'        => \Nino\Modules\Components::escape( $value ),
					'[[max]]'          => (string) Clients::FIELDS[$name],
					'[[described]]'    => $described,
				] );

			$fields .= Portal::render( 'data-field', [
				'[[name]]'    => $name,
				'[[star]]'    => $kind['required'] === true ? Portal::template( 'data-star' ) : '',
				'[[wide]]'    => $kind['wide'] === true ? ' crm-field--wide' : '',
				'[[invalid]]' => $invalid === true ? ' crm-field--invalid crm-data__field--invalid' : '',
				'[[input]]'   => $input,
				'[[error]]'   => $invalid === true ? Portal::render( 'data-error', [ '[[name]]' => $name, '[[sentence]]' => self::sentence( $why ) ] ) : '',
			] );
		}

		$member = (array) \Nino\Modules\Members::current( $appData );
		$standing = Records::privacy( $appData, $client['id'] );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		if( $state === 'invalid' )
			$note = Portal::render( 'data-state-invalid', [ '[[field]]' => $field ] );
		elseif( $state !== '' )
			$note = Portal::render( 'data-state', [ '[[state]]' => $state, '[[tone]]' => self::STATES[$state][0], '[[icon]]' => self::STATES[$state][1], '[[role]]' => self::STATES[$state][2] ] );
		else
			$note = '';

		return Portal::render( 'data', [
			'[[classes]]' => $classes,
			'[[dir]]'     => $dir,
			'[[gate]]'    => Portal::gateNote( $appData, $client, 'data' ),
			'[[weiter]]'  => Portal::onwardField( $appData ),
			'[[state]]'   => $note,
			'[[fields]]'  => $fields,
			'[[also]]'    => $client['email'] !== '' && mb_strtolower( $client['email'], 'UTF-8' ) !== $member['email'] ? Portal::render( 'data-login-also', [ '[[address]]' => \Nino\Modules\Components::escape( $client['email'] ) ] ) : '',
			'[[login]]'   => self::loginBlock( $appData, $member, $dir, $query, $login ),
			'[[consents]]' => Consent::block( $appData, $client, $dir ),
			'[[privacy]]' => $standing['asked'] === false ? '' : Portal::render( 'data-privacy', [
				'[[agreed]]'   => $standing['agreed'] === true ? '1' : '0',
				'[[tone]]'     => $standing['agreed'] === true ? 'success' : 'warning',
				'[[pill]]'     => $standing['agreed'] === true ? 'agreed' : 'open',
				'[[link]]'     => $standing['agreed'] === true ? 'view' : 'link',
				'[[standing]]' => Privacy::standingLine( $standing ),
				'[[dir]]'      => $dir,
			] ),
		] );
	}

	/**
	 *	Keep what the member typed into the form of the Stammdaten for the next drawing of the form: the text fields and the country code, as strings of at most KEPT_MAX
	 *	characters without control characters, in the session of the member and nowhere else
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	private static function keepForm( array &$appData ): void {

		$form = [];

		foreach( array_keys( self::FIELDS ) as $name )
			if( is_string( $_POST[$name] ?? null ) === true )
				$form[$name] = mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $_POST[$name] ), 0, self::KEPT_MAX, 'UTF-8' );

		$kept = (array) \Nino\Runtime::getSessionValue( $appData, self::KEPT, [] );
		$kept['form'] = $form;
		\Nino\Runtime::setSessionValue( $appData, self::KEPT, $kept );
	}

	/**
	 *	Keep the address the member typed into the field of the block Anmeldeadresse, the same way (an address is 254 characters at most)
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	void
	 */
	private static function keepEmail( array &$appData ): void {

		if( is_string( $_POST['email'] ?? null ) === false )
			return;

		$kept = (array) \Nino\Runtime::getSessionValue( $appData, self::KEPT, [] );
		$kept['email'] = mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $_POST['email'] ), 0, 254, 'UTF-8' );
		\Nino\Runtime::setSessionValue( $appData, self::KEPT, $kept );
	}

	/**
	 *	What was kept, taken out of the session: a drawing of the form reads it once, whether it puts it in the fields or not
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array											form (name => value) and email, as far as they were kept; [] for none
	 */
	private static function kept( array &$appData ): array {

		$kept = \Nino\Runtime::getSessionValue( $appData, self::KEPT );
		\Nino\Runtime::unsetSessionValue( $appData, self::KEPT );

		return is_array( $kept ) === true ? $kept : [];
	}

	/**
	 *	The address kept for the field of the block Anmeldeadresse, where the query says the block was answered with a refusal that leaves the field to be typed again (invalid,
	 *	same, limit, expired at the first step)
	 *
	 *	@param		array 		$query
	 *	@param		string		$typed				What was kept
	 *
	 *	@return 	string										'' for none
	 */
	private static function keptLogin( array $query, string $typed ): string {

		$state = is_string( $query['state'] ?? null ) === true ? $query['state'] : '';
		$refused = ( $state === 'invalid' && ( $query['field'] ?? null ) === 'login' ) || in_array( $state, [ 'same', 'limit' ], true ) === true || ( $state === 'expired' && ( $query['login'] ?? null ) === 'change' );

		return $refused === true ? $typed : '';
	}

	/**
	 *	The sentence beside a field that was refused, by the reason the server put in the address (?why=): required (the field is empty), name (company and name are both
	 *	empty), long, format - and the generic one for any other word, which is never put on the page
	 *
	 *	@param		string		$why
	 *
	 *	@return 	string										The end of the key of the words: field-required, field-name, field-long or field-error
	 */
	private static function sentence( string $why ): string {

		return [ 'required' => 'field-required', 'name' => 'field-name', 'long' => 'field-long', 'format' => 'field-error' ][$why] ?? 'field-error';
	}

	/**
	 *	The options of the select of the country: COUNTRIES with their names in the language of the page (the intl extension's where it is loaded,
	 *	the code where not), the client's own code among them, and the client's country selected - Deutschland where the client has none
	 *
	 *	@param		string		$country			The client's code
	 *
	 *	@return 	string										Markup
	 */
	public static function countries( string $country ): string {

		$chosen = preg_match( '/^[A-Z]{2}$/D', $country ) === 1 ? $country : 'DE';
		$codes = in_array( $chosen, self::COUNTRIES, true ) === true ? self::COUNTRIES : array_merge( self::COUNTRIES, [ $chosen ] );
		$options = '';

		foreach( $codes as $code )
			$options .= Portal::render( 'data-option', [ '[[code]]' => $code, '[[selected]]' => $code === $chosen ? ' selected' : '', '[[name]]' => \Nino\Modules\Components::escape( Documents\Render::country( $code ) ) ] );

		return $options;
	}

	/**
	 *	'/nino/http/response/POST://.crm/daten': the form of the page Meine Daten. A visitor and a member of no client are answered 404; a token
	 *	that is not the session's is the redirect with ?state=expired. The input is handed to Clients::change() - the fields of the form
	 *	only, whatever else was posted - and a refusal is the redirect with ?state=invalid&field=<name>; else ?state=saved, and where a
	 *	field is different now the owner is mailed (what it was and what it is, the client, the account); no mail where nothing changed
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackSave( array &$appData, array &$request ): void {

		$client = Portal::client( $appData );
		$member = self::memberOfClient( $appData );

		if( $client === null || $member === null ) {
			self::notFound( $request );

			return;
		}

		if( \Nino\Form::blocked( $request ) === true ) {
			self::keepForm( $appData );
			self::redirect( $appData, $request, 'expired', Portal::onward( $_POST['weiter'] ?? null ) );

			return;
		}

		$input = [];
		$onward = Portal::onward( $_POST['weiter'] ?? null );
		$wasPending = Portal::pending( $appData, $client ) === 'data';

		// The Datenschutz comes first: nothing is saved, nobody is mailed and no account is named before it is confirmed
		if( Portal::pending( $appData, $client ) === 'privacy' ) {
			\Nino\Http::redirect( $appData, $request, Portal::stepAddress( 'privacy', self::PAGE. ( $onward === '' ? '' : '?weiter='. rawurlencode( $onward ) ) ), 303 );

			return;
		}

		foreach( array_keys( self::FIELDS ) as $name )
			$input[$name] = $_POST[$name] ?? '';

		// What an invoice names the client by: a company or a name, then the four fields of the address - the first that is empty is the one the page points at
		foreach( [ 'name' => [ 'company', 'name' ], 'street' => [ 'street' ], 'zip' => [ 'zip' ], 'city' => [ 'city' ], 'country' => [ 'country' ] ] as $field => $names ) {

			if( array_filter( $names, static fn( string $name ): bool => is_string( $input[$name] ) === true && trim( $input[$name] ) !== '' ) === [] ) {
				self::keepForm( $appData );
				self::redirect( $appData, $request, 'invalid', $onward, $field, '', $field === 'name' ? 'name' : 'required' );

				return;
			}
		}

		$changed = Clients::change( $appData, $client['id'], $input );

		if( is_string( $changed ) === true ) {

			// 'empty' is neither company nor name; 'long:zip', 'format:zip' name their field
			$field = $changed === 'empty' ? 'name' : substr( $changed, (int) strpos( $changed, ':' ) + 1 );

			if( $changed === 'unknown' || isset( self::FIELDS[$field] ) === false ) {
				self::notFound( $request );

				return;
			}

			self::keepForm( $appData );
			self::redirect( $appData, $request, 'invalid', $onward, $field, '', $changed === 'empty' ? 'name' : ( str_starts_with( $changed, 'long:' ) === true ? 'long' : 'format' ) );

			return;
		}

		Portal::forget( $appData );

		// The name of the client is the name of the account that has none: the chip of the bar, the comments and the mail to the owner read it from there
		if( trim( (string) $changed['name'] ) !== '' && trim( (string) $member['name'] ) === '' ) {

			$named = \Nino\Modules\Members::change( $appData, $member['id'], [ 'name' => mb_substr( trim( (string) $changed['name'] ), 0, self::ACCOUNT_NAME, 'UTF-8' ) ] );
			$member = is_array( $named ) === true ? $named : $member;
		}

		$differences = [];

		foreach( array_keys( self::FIELDS ) as $name )
			if( (string) $client[$name] !== (string) $changed[$name] )
				$differences[$name] = [ (string) $client[$name], (string) $changed[$name] ];

		if( $differences !== [] )
			Documents\Mail::dataChanged( $appData, $changed, $differences, $member );

		// The box of the form: the address the Stammdaten have is the sign-in address as well - saved first, then a code to it, and the page shows the second step
		// under the note that it was saved. An address the account has already, or none, asks for nothing
		if( ( $_POST['login'] ?? null ) === '1' && (string) $changed['email'] !== '' && mb_strtolower( (string) $changed['email'], 'UTF-8' ) !== $member['email'] ) {

			$asked = \Nino\Modules\Members::askChange( $appData, $member, (string) $changed['email'] );

			if( $asked === 'sent' ) {
				self::redirect( $appData, $request, 'saved', $onward, '', '', '', 'code' );

				return;
			}

			if( $asked === 'limit' ) {
				self::redirect( $appData, $request, 'limit', $onward );

				return;
			}
		}

		$next = Portal::proceed( $appData, $changed, $onward );

		// The last step of the gate, with no page asked for: on to where something waits
		if( $next === '' && $wasPending === true )
			$next = Portal::saved( Portal::landing( $appData, $changed ) );

		self::redirect( $appData, $request, 'saved', $onward, '', $next );
	}

	/**
	 *	The block Anmeldeadresse: the address of the account as text and, below it, the first step - a button that opens the form for a new address, closed
	 *	until the query says login=change or a state that is about that form - or, where a code is out in the session of the member (Members::changePending())
	 *	and the query says login=code, the second step, which names the address the code was asked for and asks for the digits. The second step without a
	 *	request that is live is the first one with the state gone. The state of the query is said above the step: the states of LOGIN_STATES, invalid with the
	 *	field login, expired where the address names a step. The address a code was asked for is the session's and never the query's
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		$member				The account who is signed in
	 *	@param		string		$dir					The escaped directory of the project
	 *	@param		array 		$query				The query of the request
	 *	@param		string		$typed				The address the member typed into the field under a refusal, as keptLogin() has it; '' for none
	 *
	 *	@return 	string										Markup
	 */
	private static function loginBlock( array &$appData, array $member, string $dir, array $query, string $typed ): string {

		$state = is_string( $query['state'] ?? null ) === true ? $query['state'] : '';
		$asked = in_array( $query['login'] ?? null, [ 'change', 'code' ], true ) === true ? $query['login'] : '';
		$new = $asked === 'code' ? \Nino\Modules\Members::changePending( $appData, $member ) : null;

		if( $asked === 'code' && $new === null ) {
			$asked = 'change';
			$state = 'gone';
		}

		if( $state === 'wrong' && $new === null )
			$state = 'gone';

		$key = $state === 'invalid' && ( $query['field'] ?? null ) === 'login' ? 'login-invalid' : ( $state === 'expired' && $asked !== '' ? 'login-expired' : $state );
		$tone = self::LOGIN_STATES[$key] ?? null;
		$bad = $key === 'login-invalid' || $key === 'wrong' ? [ '[[invalid]]' => ' crm-field--invalid', '[[described]]' => ' aria-invalid="true"' ] : [ '[[invalid]]' => '', '[[described]]' => '' ];

		if( $new !== null )
			$step = Portal::render( 'data-login-code', [ '[[dir]]' => $dir, '[[address]]' => \Nino\Modules\Components::escape( $new ) ] + $bad );
		else
			$step = Portal::render( 'data-login-change', [ '[[dir]]' => $dir, '[[open]]' => $asked === 'change' || ( $tone !== null && in_array( $key, [ 'login-changed', 'devices', 'devices-failed' ], true ) === false ) ? ' open' : '', '[[typed]]' => $typed === '' ? '' : ' value="'. \Nino\Modules\Components::escape( $typed ). '"' ] + $bad );

		return Portal::render( 'data-login', [
			'[[address]]' => \Nino\Modules\Components::escape( (string) $member['email'] ),
			'[[dir]]'     => $dir,
			'[[state]]'   => $tone === null ? '' : Portal::render( 'data-state', [ '[[state]]' => $key, '[[tone]]' => $tone[0], '[[icon]]' => $tone[1], '[[role]]' => $tone[2] ] ),
			'[[step]]'    => $step,
		] );
	}

	/**
	 *	'/nino/http/response/POST://.crm/anmeldeadresse': the first step of the block Anmeldeadresse. A visitor and a member of no client are answered 404; a token
	 *	that is not the session's is the redirect with ?state=expired&login=change. The address is handed to Members::askChange(): sent - a code is on its way if the
	 *	address is free, and nothing here says whether it is - goes to ?login=code, the second step; invalid to ?state=invalid&field=login; same (the address the
	 *	account has) and limit (the caps of Members) to ?state=same and ?state=limit
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackLogin( array &$appData, array &$request ): void {

		$member = self::memberOfClient( $appData );

		if( $member === null ) {
			self::notFound( $request );

			return;
		}

		if( \Nino\Form::blocked( $request ) === true ) {
			self::keepEmail( $appData );
			self::redirect( $appData, $request, 'expired', '', '', '', '', 'change' );

			return;
		}

		$status = \Nino\Modules\Members::askChange( $appData, $member, is_string( $_POST['email'] ?? null ) === true ? $_POST['email'] : '' );

		if( $status !== 'sent' )
			self::keepEmail( $appData );

		match( $status ) {
			'sent'    => self::redirect( $appData, $request, '', '', '', '', '', 'code' ),
			'invalid' => self::redirect( $appData, $request, 'invalid', '', 'login' ),
			default   => self::redirect( $appData, $request, $status ),
		};
	}

	/**
	 *	'/nino/http/response/POST://.crm/anmeldeadresse/code': the second step. A visitor and a member of no client are answered 404; a token that is not the
	 *	session's is the redirect with ?login=code&state=expired and the request as it was. The code is handed to Members::confirmChange(): ok is ?state=login-changed,
	 *	wrong is ?login=code&state=wrong, and gone - no request, one that ran out or was used up, an address that is another account's by now, and a write that
	 *	failed - is ?state=gone
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackCode( array &$appData, array &$request ): void {

		$member = self::memberOfClient( $appData );

		if( $member === null ) {
			self::notFound( $request );

			return;
		}

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired', '', '', '', '', 'code' );

			return;
		}

		$status = \Nino\Modules\Members::confirmChange( $appData, $member, is_string( $_POST['code'] ?? null ) === true ? $_POST['code'] : '' );

		match( $status ) {
			'ok'    => self::redirect( $appData, $request, 'login-changed' ),
			'wrong' => self::redirect( $appData, $request, 'wrong', '', '', '', '', 'code' ),
			default => self::redirect( $appData, $request, 'gone' ),
		};
	}

	/**
	 *	'/nino/http/response/POST://.crm/geraete/abmelden': the button of the block Anmeldeadresse signs the account out of every device. A visitor and a member of no client are answered 404; a
	 *	token that is not the session's is the redirect with ?state=expired. Members::forgetAll() empties the list of the devices that stay signed in and gives the account an epoch of its
	 *	own, so that every other session ends and no cookie signs it in again; the session that asks stays - it is written again with the new epoch - and the redirect is ?state=devices, which the
	 *	block says. A write that failed is ?state=devices-failed
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	public static function callbackDevices( array &$appData, array &$request ): void {

		$member = self::memberOfClient( $appData );

		if( $member === null ) {
			self::notFound( $request );

			return;
		}

		if( \Nino\Form::blocked( $request ) === true ) {
			self::redirect( $appData, $request, 'expired' );

			return;
		}

		self::redirect( $appData, $request, \Nino\Modules\Members::forgetAll( $appData, $member['id'] ) === true ? 'devices' : 'devices-failed' );
	}

	/**
	 *	'/members/changed': the address of an account was changed. Where the member changed it (the owner knows what he did himself) the owner is mailed, as for
	 *	any change of the data in the portal: the client, the line Anmeldeadresse with the address it was and the one it is, and the account, which the reply goes to.
	 *	An account of no client is nobody's business here, and a mail that does not go out is a warning of Documents\Mail and no reason to undo the change
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$told				account (the new address in it), old and by, as Members fires it
	 *
	 *	@return 	void
	 */
	public static function callbackChanged( array &$appData, array &$told ): void {

		if( ( $told['by'] ?? null ) !== 'member' || is_array( $told['account'] ?? null ) === false || is_string( $told['old'] ?? null ) === false )
			return;

		try {
			$client = Clients::ofMember( $appData, (string) ( $told['account']['id'] ?? '' ) );
		}
		catch( \PDOException $e ) {
			trigger_error( 'Crm: the client of an account whose sign-in address was changed could not be read', E_USER_WARNING );

			return;
		}

		if( $client !== null )
			Documents\Mail::dataChanged( $appData, $client, [ 'login' => [ $told['old'], (string) $told['account']['email'] ] ], $told['account'] );
	}

	/**
	 *	The member of a client who is signed in
	 *
	 *	@param		array 		&$appData
	 *
	 *	@return 	array|null								The account as Members has it; null for a visitor and for a member of no client
	 */
	private static function memberOfClient( array &$appData ): ?array {

		return Portal::client( $appData ) === null ? null : \Nino\Modules\Members::current( $appData );
	}

	/**
	 *	The answer to whoever is no member of a client: 404, as the feature Files answers a stranger
	 *
	 *	@param		array 		&$request
	 *
	 *	@return 	void
	 */
	private static function notFound( array &$request ): void {

		$request['/nino/http/response']['statusCode']								= 404;
		$request['/nino/http/response']['body']										= 'Not Found';
		$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
		$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';
	}

	/**
	 *	The redirect to the page with a state
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				One of STATES
	 *	@param		string		$onward				The page to go on to, as Portal::onward() has it; the page of the form keeps it
	 *	@param		string		$field				For invalid: the field that was refused
	 *	@param		string		$next					Where a saved form goes on to, as Portal::proceed() has it; '' for the page itself
	 *	@param		string		$why					For invalid: required, name, long or format - the page words the sentence beside the field by it
	 *	@param		string		$login				change or code: the step of the block Anmeldeadresse the page shows
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state, string $onward = '', string $field = '', string $next = '', string $why = '', string $login = '' ): void {

		$query = array_filter( [ 'state' => $state, 'field' => $field, 'why' => $why, 'login' => $login, 'weiter' => $onward ], static fn( string $value ): bool => $value !== '' );

		\Nino\Http::redirect( $appData, $request, $next !== '' ? $next : self::PAGE. '?'. http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), 303 );
	}
}

<?php
declare(strict_types=1);
/**
 *	Crm\Portal\Data		The data of the client in the portal: the component [crm-client-data] draws the form of the page Meine Daten, and
 *										POST /.crm/daten keeps what the member typed - through Clients::change(), with its validation - and tells the owner by mail
 *										what changed. Only the signed-in member of a client may post (a visitor and a member of no client are answered 404, as the feature
 *										Files answers a stranger). Every answer is a redirect to the page with a state in the query: saved, invalid (with the field
 *										that was refused) or expired (the token of the form was not the session's). What was typed is not kept, so the sentence names the
 *										field. The e-mail of the account is Members' and is shown, never posted; the e-mail of the client is not on the form. A finalised
 *										document keeps the client it was made with (its client_json): the next document carries the new data.
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
	public const string PAGE = '/portal/meine-daten';

	// The fields of the form, in their order: the client's fields the member may change, how the input is made and whether it takes the whole line
	public const array FIELDS = [
		'company' => [ 'type' => 'text', 'autocomplete' => 'organization', 'wide' => false ],
		'name'    => [ 'type' => 'text', 'autocomplete' => 'name', 'wide' => false ],
		'street'  => [ 'type' => 'text', 'autocomplete' => 'street-address', 'wide' => true ],
		'zip'     => [ 'type' => 'text', 'autocomplete' => 'postal-code', 'wide' => false ],
		'city'    => [ 'type' => 'text', 'autocomplete' => 'address-level2', 'wide' => false ],
		'country' => [ 'type' => 'select', 'autocomplete' => 'country', 'wide' => true ],
		'phone'   => [ 'type' => 'tel', 'autocomplete' => 'tel', 'wide' => false ],
		'vat_id'  => [ 'type' => 'text', 'autocomplete' => 'off', 'wide' => false ],
	];

	// The countries the select offers, by their code (Clients keeps the code): the neighbours and the usual ones, Deutschland first; the client's own
	// code is offered besides them where it is none of these, so that nothing is lost
	public const array COUNTRIES = [ 'DE', 'AT', 'CH', 'NL', 'BE', 'LU', 'DK', 'FR', 'IT', 'ES', 'PT', 'PL', 'CZ', 'SE', 'NO', 'FI', 'IE', 'GB', 'US' ];

	// The states a page says, each with the tone and the icon of its note and whether it is a status or an alert
	private const array STATES = [ 'saved' => [ 'success', 'circle-check', 'status' ], 'invalid' => [ 'error', 'circle-alert', 'alert' ], 'expired' => [ 'error', 'circle-alert', 'alert' ] ];

	/**
	 *	[crm-client-data] - the form with the client's company, name, address (the postal code and the city on one line), country (a select,
	 *	Deutschland where the client has none), phone and VAT id, the e-mail of the account beside it as a line that is only read, and a button;
	 *	under the form the block Datenschutz-Zustimmung: whether the client has agreed, and the way to the page. The state the query names is said
	 *	above the form, and an invalid field beside the field. A member of no client is told there is no client; nothing for a visitor
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
		$state = is_string( $query['state'] ?? null ) === true && isset( self::STATES[$query['state']] ) === true ? $query['state'] : '';
		$field = is_string( $query['field'] ?? null ) === true && isset( self::FIELDS[$query['field']] ) === true ? $query['field'] : '';

		if( $state === 'invalid' && $field === '' )
			$state = '';

		$fields = '';

		foreach( self::FIELDS as $name => $kind ) {

			$invalid = $state === 'invalid' && $field === $name;
			$value = (string) ( $client[$name] ?? '' );
			$described = $invalid === true ? ' aria-invalid="true" aria-describedby="crm-data-'. $name. '-error"' : '';

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
				'[[wide]]'    => $kind['wide'] === true ? ' crm-field--wide' : '',
				'[[invalid]]' => $invalid === true ? ' crm-field--invalid crm-data__field--invalid' : '',
				'[[input]]'   => $input,
				'[[error]]'   => $invalid === true ? Portal::render( 'data-error', [ '[[name]]' => $name ] ) : '',
			] );
		}

		$member = \Nino\Modules\Members::current( $appData );
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
			'[[state]]'   => $note,
			'[[fields]]'  => $fields,
			'[[email]]'   => \Nino\Modules\Components::escape( (string) ( $member['email'] ?? '' ) ),
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

		$input = [];

		foreach( array_keys( self::FIELDS ) as $name )
			$input[$name] = $_POST[$name] ?? '';

		$changed = Clients::change( $appData, $client['id'], $input );

		if( is_string( $changed ) === true ) {

			// 'empty' is neither company nor name; 'long:zip', 'format:zip' name their field
			$field = $changed === 'empty' ? 'name' : substr( $changed, (int) strpos( $changed, ':' ) + 1 );

			if( $changed === 'unknown' || isset( self::FIELDS[$field] ) === false ) {
				$request['/nino/http/response']['statusCode']								= 404;
				$request['/nino/http/response']['body']										= 'Not Found';
				$request['/nino/http/response']['header']['Content-Type']		= 'text/plain; charset=utf-8';
				$request['/nino/http/response']['header']['Cache-Control']	= 'no-store';

				return;
			}

			self::redirect( $appData, $request, 'invalid', $field );

			return;
		}

		Portal::forget( $appData );
		$differences = [];

		foreach( array_keys( self::FIELDS ) as $name )
			if( (string) $client[$name] !== (string) $changed[$name] )
				$differences[$name] = [ (string) $client[$name], (string) $changed[$name] ];

		if( $differences !== [] )
			Documents\Mail::dataChanged( $appData, $changed, $differences, $member );

		self::redirect( $appData, $request, 'saved' );
	}

	/**
	 *	The redirect to the page with a state
	 *
	 *	@param		array 		&$appData
	 *	@param		array 		&$request
	 *	@param		string		$state				One of STATES
	 *	@param		string		$field				For invalid: the field that was refused
	 *
	 *	@return 	void
	 */
	private static function redirect( array &$appData, array &$request, string $state, string $field = '' ): void {

		\Nino\Http::redirect( $appData, $request, self::PAGE. '?state='. $state. ( $field === '' ? '' : '&field='. $field ), 303 );
	}
}

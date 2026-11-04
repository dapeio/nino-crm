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

	// The fields of the form, in their order: the client's fields the member may change, and how the input is made
	public const array FIELDS = [
		'company' => [ 'type' => 'text', 'autocomplete' => 'organization' ],
		'name'    => [ 'type' => 'text', 'autocomplete' => 'name' ],
		'street'  => [ 'type' => 'text', 'autocomplete' => 'street-address' ],
		'zip'     => [ 'type' => 'text', 'autocomplete' => 'postal-code' ],
		'city'    => [ 'type' => 'text', 'autocomplete' => 'address-level2' ],
		'country' => [ 'type' => 'text', 'autocomplete' => 'country' ],
		'phone'   => [ 'type' => 'tel', 'autocomplete' => 'tel' ],
		'vat_id'  => [ 'type' => 'text', 'autocomplete' => 'off' ],
	];

	private const array STATES = [ 'saved', 'invalid', 'expired' ];

	/**
	 *	[crm-client-data] - the form with the client's company, name, address, country, phone and VAT id, the e-mail of the account beside it
	 *	as a line that is only read, and a button. The state the query names is said above the form, and an invalid field beside the field.
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
			return str_replace( '[[classes]]', $classes, Portal::template( 'data-none' ) );

		$query = Portal::query( $appData );
		$state = is_string( $query['state'] ?? null ) === true && in_array( $query['state'], self::STATES, true ) === true ? $query['state'] : '';
		$field = is_string( $query['field'] ?? null ) === true && isset( self::FIELDS[$query['field']] ) === true ? $query['field'] : '';

		if( $state === 'invalid' && $field === '' )
			$state = '';

		$fields = '';

		foreach( self::FIELDS as $name => $kind )
			$fields .= str_replace(
				[ '[[name]]', '[[type]]', '[[autocomplete]]', '[[value]]', '[[max]]', '[[invalid]]', '[[described]]', '[[error]]' ],
				[
					$name,
					$kind['type'],
					$kind['autocomplete'],
					\Nino\Modules\Components::escape( (string) ( $client[$name] ?? '' ) ),
					(string) Clients::FIELDS[$name],
					$state === 'invalid' && $field === $name ? ' crm-data__field--invalid' : '',
					$state === 'invalid' && $field === $name ? ' aria-invalid="true" aria-describedby="crm-data-'. $name. '-error"' : '',
					$state === 'invalid' && $field === $name ? str_replace( '[[name]]', $name, Portal::template( 'data-error' ) ) : '',
				],
				Portal::template( 'data-field' )
			);

		$member = \Nino\Modules\Members::current( $appData );
		$standing = Records::privacy( $appData, $client['id'] );
		$dir = \Nino\Modules\Components::escape( \Nino\Filesystem::getDir( $appData ) );

		return str_replace(
			[ '[[classes]]', '[[dir]]', '[[privacy]]', '[[state]]', '[[fields]]', '[[email]]' ],
			[
				$classes,
				$dir,
				$standing['asked'] === false ? '' : str_replace( [ '[[agreed]]', '[[standing]]', '[[dir]]' ], [ $standing['agreed'] === true ? '1' : '0', Privacy::standingLine( $standing ), $dir ], Portal::template( 'data-privacy' ) ),
				$state === '' ? '' : str_replace( [ '[[state]]', '[[field]]' ], [ $state, $state === 'invalid' ? $field : '' ], Portal::template( $state === 'invalid' ? 'data-state-invalid' : 'data-state' ) ),
				$fields,
				\Nino\Modules\Components::escape( (string) ( $member['email'] ?? '' ) ),
			],
			Portal::template( 'data' )
		);
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

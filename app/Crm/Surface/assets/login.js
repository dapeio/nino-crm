/**
 *	Crm		The login of the surface in the browser: the page at /crm shows it where nobody is signed in. It posts to the kernel's own
 *				login (POST /.nino/auth/login) the way Nino.auth.login does: the mail and the password as HTTP Basic credentials in the
 *				Authorization header - the kernel reads them nowhere else, a field of a form named user or pw is never looked at - and the
 *				token of the page in X-CSRF-Token. Where the kernel says yes it reads /crm again, with the hash of the link the person came
 *				by, so that a deep link survives the login. The kernel answers 200 for a login, 401 for a pair that does not fit and 403
 *				for a token that is no longer the session's.
 *
 *				It reads what it needs from the JSON block of the page (base, csrf, texts) and writes sentences with textContent only.
 */

( function( wn, dc ) {

	'use strict';

	wn.Nino = wn.Nino || {};
	wn.Nino.crm = wn.Nino.crm || {};

	/**
	 *	The credentials as the kernel reads them: Basic, the pair encoded as UTF-8 first (btoa() alone would send the latin-1 byte of an
	 *	umlaut and throw on a character above U+00FF), as Nino.js does
	 *
	 *	@param		{string}	user
	 *	@param		{string}	pw
	 *
	 *	@return		{string}									The value of the Authorization header
	 */
	const basic = function( user, pw ) {

		let binary = '';

		new wn.TextEncoder().encode( user+ ':'+ pw ).forEach( function( byte ) { binary += String.fromCharCode( byte ) } );

		return 'Basic '+ wn.btoa( binary );
	};

	/**
	 *	Wire the form of the page
	 *
	 *	@param		{Object}	[block]					The element with the JSON; the page's own by default
	 *
	 *	@return		{void}
	 */
	const boot = function( block ) {

		const data = JSON.parse( ( block || dc.getElementById( 'crm-data' ) ).textContent );
		const form = dc.getElementById( 'login' );
		const user = dc.getElementById( 'login-user' );
		const pw = dc.getElementById( 'login-pw' );
		const message = dc.getElementById( 'login-msg' );
		const submit = dc.getElementById( 'login-submit' );

		const say = function( key, ...params ) {
			let text = String( data.texts[key] ?? '' );
			params.forEach( function( param ) { text = text.replace( '%s', String( param ) ) } );
			message.textContent = text;
		};

		form.addEventListener( 'submit', function( event ) {

			event.preventDefault();

			if( submit.disabled === true )
				return;

			message.textContent = '';
			submit.disabled = true;

			wn.fetch( data.base+ '/.nino/auth/login', { method : 'POST', credentials : 'same-origin', headers : { 'X-CSRF-Token' : data.csrf, 'Authorization' : basic( user.value, pw.value ) } } ).then( function( response ) {

				submit.disabled = false;

				if( response.status === 200 ) {

					// The page is at /crm already: to that address with the same hash the browser only moves to the hash and reads nothing again
					if( wn.location.hash !== '' )
						wn.location.reload();
					else
						wn.location.replace( data.base+ '/crm' );

					return;
				}

				if( response.status === 401 ) {
					say( 'login.wrong' );
					pw.value = '';
					pw.focus();
				}
				else if( response.status === 403 )
					say( 'login.stale' );
				else
					say( 'login.status', response.status );
			}, function() {
				submit.disabled = false;
				say( 'login.offline' );
			} );
		} );
	};

	wn.Nino.crm.login = { boot : boot };

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', function() { boot() } );
	else if( dc.readyState === 'interactive' || dc.readyState === 'complete' )
		boot();

} )( window, document );

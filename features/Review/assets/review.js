/**
 *	Nino - Review
 *	review.js	The script of the player [review-player] on the site. The page works without it: the
 *						time of a comment is a link to the file at that second, the form is a plain post that
 *						reloads the page, and the time field is typed as m:ss or left empty. With it, a click
 *						on the time of a comment takes the player there and plays from it, and the switch beside
 *						the time field - on to begin with - makes the field say where the player is: when the
 *						player stops, is moved or ends, and at every keystroke in the comment, as m:ss and the
 *						tenth of a second after a point where there is one. A person who types in the field has
 *						the time for themself, and the switch goes off; set on again, it writes the position at
 *						once. A button on each comment prepares an answer: the id of the comment goes into the
 *						hidden field parent, a line says who is answered and has a button to give that up, and
 *						the comment is written without a time unless one is typed. The form is sent without
 *						leaving the page, so that the player plays on: the script posts it with fetch() and
 *						takes the list of the comments and the line of the state from the page the post is
 *						answered with - the media element is never touched. Where that fails, or the answer is
 *						not the page of the player, the form is posted the plain way.
 *
 *						Bundled into the project's script bundle by the manifest's assets, so it is a static
 *						file that is never rendered through the fill engine: every word is asked for with
 *						Nino.content.getText() - the class publishes /feature/review/script/ to the Jstext
 *						module for it -, and every value that comes from the page is set as text.
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	'use strict';

	// The latest second a comment can name, as Review\Store::LAST has it
	const LAST = 359999.9;

	/**
	 *	A time as a person types it, in seconds: m:ss, h:mm:ss or a number, a point or a comma before the decimals,
	 *	round to one decimal. The rules of Review::seconds(). Pure
	 *
	 *	@param		{string}	text						eg. '1:05', '0:03.5', '75', '1:02:03'
	 *
	 *	@return		{number|null}							null for nothing, and for anything that is no time
	 */
	const seconds = function( text ) {

		const input = String( text ).trim().replace( ',', '.' );
		let found = /^(\d+):([0-5]\d):([0-5]\d)(\.\d+)?$/.exec( input );
		let value = 0;

		if( found !== null )
			value = Number( found[1] ) * 3600 + Number( found[2] ) * 60 + Number( found[3] ) + Number( found[4] || 0 );
		else if( ( found = /^(\d+):([0-5]?\d)(\.\d+)?$/.exec( input ) ) !== null )
			value = Number( found[1] ) * 60 + Number( found[2] ) + Number( found[3] || 0 );
		else if( /^\d+(?:\.\d+)?$/.test( input ) === true )
			value = Number( input );
		else
			return null;

		return value <= LAST ? Math.round( value * 10 ) / 10 : null;
	};

	/**
	 *	A position of the player the way the page writes a time: m:ss, the minutes as many as there are and the decimals
	 *	left out. The rule of Review::clock(). Pure
	 *
	 *	@param		{number}	value						Seconds
	 *
	 *	@return		{string}									eg. '1:05'; '0:00' for anything that is no time
	 */
	const clock = function( value ) {

		const whole = Number.isFinite( value ) === true ? Math.floor( Math.max( 0, value ) ) : 0;

		return Math.floor( whole / 60 )+ ':'+ String( whole % 60 ).padStart( 2, '0' );
	};

	/**
	 *	A position of the player the way the time field is written when it takes the player's: m:ss, and the tenth of a second
	 *	after a point where there is one - the data holds one decimal, and seconds() reads this back. A tenth of 0 stays m:ss.
	 *	Pure
	 *
	 *	@param		{number}	value						Seconds
	 *
	 *	@return		{string}									eg. '0:02.5', '1:05'; '0:00' for anything that is no time
	 */
	const position = function( value ) {

		const tenths = Number.isFinite( value ) === true ? Math.floor( Math.max( 0, value ) * 10 ) : 0;

		return clock( tenths / 10 )+ ( tenths % 10 === 0 ? '' : '.'+ ( tenths % 10 ) );
	};

	/**
	 *	A word of the script, as the page publishes it
	 *
	 *	@param		{string}	key							Below /feature/review/script/
	 *
	 *	@return		{string}
	 */
	const word = function( key ) {
		return Nino.content.getText( '/feature/review/script/'+ key );
	};

	/**
	 *	Take the player to a second and play from there. A browser that refuses to play without a gesture of the
	 *	person is not an error: the player has moved all the same
	 *
	 *	@param		{Element}	media						The audio or video element
	 *	@param		{number}	at							Seconds
	 *
	 *	@return		{boolean}									Whether the player was moved - not for a second that is none
	 */
	const seek = function( media, at ) {

		if( Number.isFinite( at ) === false || at < 0 )
			return false;

		media.currentTime = at;

		const played = typeof media.play === 'function' ? media.play() : null;

		if( played !== null && played !== undefined && typeof played.catch === 'function' )
			played.catch( function() {} );

		return true;
	};

	/**
	 *	Take a player over: the time links, the position of the player in the form and the answers
	 *
	 *	@param		{Element}	root						A .review-player
	 *
	 *	@return		void
	 */
	const take = function( root ) {

		const media = root.querySelector('audio, video');
		const form = root.querySelector('form.review-form');

		// The list of the files has neither
		if( root.dataset.reviewBound === '1' || media === null || form === null )
			return;

		const at = form.querySelector('input[name="at"]');
		const parent = form.querySelector('input[name="parent"]');
		const text = form.querySelector('textarea');
		const follow = form.querySelector('.review-form__follow-input');
		const send = form.querySelector('button[type="submit"]');

		if( at === null || parent === null || text === null || follow === null )
			return;

		root.dataset.reviewBound = '1';

		// The controls that do nothing without the script are drawn hidden, and shown now
		Array.from( root.querySelectorAll('[data-review-reply], .review-form__follow') ).forEach( function( control ) { control.hidden = false } );

		let line = null;
		let sending = false;

		// With the switch on the field says where the player is; a timeupdate is left out on purpose - the number would never keep still
		const update = function() {
			if( follow.checked === true )
				at.value = position( media.currentTime );
		};

		[ 'pause', 'seeked', 'ended' ].forEach( function( name ) { media.addEventListener( name, update ) } );
		text.addEventListener( 'input', update );

		// A time typed is the person's own; the switch set on again takes the player's at once
		at.addEventListener( 'input', function() { follow.checked = false } );
		follow.addEventListener( 'change', update );

		/**
		 *	Give up the answer: the comment is one of its own again, and the switch is on again, with the position of the player
		 *	in the field - unless a time is typed in it, which is the person's
		 *
		 *	@return		void
		 */
		const cancel = function() {
			parent.value = '';
			follow.checked = at.value.trim() === '';
			update();

			if( line !== null )
				line.box.hidden = true;
		};

		/**
		 *	Prepare an answer to a comment or to an answer: its id into the field parent, who is answered in the line above
		 *	the comment, no time unless one is typed - the switch goes off and the field is emptied -, and the focus in the comment
		 *
		 *	@param		{Element}	item						The element with the data-review-id of the comment answered
		 *
		 *	@return		void
		 */
		const answer = function( item ) {

			const who = item.querySelector('.review-comment__who');

			if( line === null ) {

				line = { box : dc.createElement('p'), label : dc.createElement('span'), cancel : dc.createElement('button') };
				line.box.className = 'review-form__answering';
				line.label.className = 'review-form__answering-label';
				line.cancel.type = 'button';
				line.cancel.className = 'review-form__cancel';
				line.cancel.textContent = word('cancel');
				line.cancel.addEventListener( 'click', cancel );
				line.box.appendChild( line.label );
				line.box.appendChild( line.cancel );
				form.insertBefore( line.box, text.parentNode );
			}

			parent.value = item.getAttribute('data-review-id') || '';
			line.label.textContent = word('answering').split('%s').join( who !== null ? who.textContent : '' )+ ' ';
			line.box.hidden = false;
			at.value = '';
			follow.checked = false;
			text.focus();
		};

		/**
		 *	The element of the player that is the list of the comments, or the line that says there are none
		 *
		 *	@param		{Element}	box						A .review-player
		 *
		 *	@return		{Element|null}
		 */
		const listOf = function( box ) {
			return Array.from( box.children ).find( function( child ) { return child.classList.contains('review-comments') } ) || null;
		};

		/**
		 *	The player of this file in the page the post was answered with: the one that has the form, whatever else the page holds
		 *
		 *	@param		{Document}	page
		 *
		 *	@return		{Element|null}
		 */
		const blockOf = function( page ) {
			return Array.from( page.querySelectorAll('.review-player') ).find( function( box ) {
				return box.getAttribute('data-review-file') === root.getAttribute('data-review-file') && listOf( box ) !== null && box.querySelector('form.review-form') !== null;
			} ) || null;
		};

		/**
		 *	Take the list of the comments, the count and the line of the state from the player of the page the post was
		 *	answered with. What is put in is no markup made from data here: it is the nodes of the page that Review draws, from
		 *	the address the form posted to and the redirect it was answered with - the same origin - and exactly what a reload of
		 *	this page would show, every value in it escaped on the server. The media element is not among them, and nothing of
		 *	the form but the line of the state
		 *
		 *	@param		{Element}	fresh					The .review-player of the answer
		 *
		 *	@return		void
		 */
		const renew = function( fresh ) {

			const list = listOf( root );
			const next = listOf( fresh );
			const count = root.querySelector('.review-player__count');
			const counted = fresh.querySelector('.review-player__count');
			const state = form.querySelector('.review-state');
			const issuing = fresh.querySelector('form.review-form');
			const stated = issuing !== null ? issuing.querySelector('.review-state') : null;

			const token = form.querySelector('input[name="_csrf"]');
			const issued = issuing !== null ? issuing.querySelector('input[name="_csrf"]') : null;

			// The token of the session as the page now holds it: a form that stood open past a login in another tab posts again with it
			if( token !== null && issued !== null )
				token.value = issued.value;

			Array.from( next.querySelectorAll('[data-review-reply]') ).forEach( function( control ) { control.hidden = false } );

			if( list !== null )
				list.replaceWith( next );

			if( count !== null && counted !== null )
				count.textContent = counted.textContent;

			if( state !== null && stated !== null )
				state.replaceWith( stated );
			else if( stated !== null && send !== null )
				form.insertBefore( stated, send );
			else if( state !== null )
				state.remove();
		};

		/**
		 *	The answer to the post is in: a comment that was saved empties the form, whatever else the page said stays under
		 *	the comment for the person to put right. The focus is in the comment, ready for the next
		 *
		 *	@param		{Element}	fresh					The .review-player of the answer
		 *	@param		{boolean}	saved					Whether the state of the answer is sent
		 *
		 *	@return		void
		 */
		const settle = function( fresh, saved ) {

			try {
				renew( fresh );

				if( saved === true ) {
					text.value = '';
					at.value = '';
					cancel();
				}
			}
			finally {
				sending = false;

				if( send !== null )
					send.disabled = false;
			}

			text.focus();
		};

		/**
		 *	Post the form the plain way, which the page answers with a reload: nothing is lost, and the page says what is wrong
		 *
		 *	@return		void
		 */
		const plain = function() {
			form.submit();
		};

		form.addEventListener( 'submit', function( event ) {

			// A browser that cannot do it posts the form as it always did
			if( typeof wn.fetch !== 'function' || typeof wn.FormData !== 'function' || typeof wn.DOMParser !== 'function' )
				return;

			event.preventDefault();

			if( sending === true )
				return;

			sending = true;

			if( send !== null )
				send.disabled = true;

			// The answer is read first; only what is wrong with it - and not what is wrong in this script - posts the form again, as a comment that is saved is not to be posted twice
			wn.fetch( form.getAttribute('action'), { method : 'POST', body : new wn.FormData( form ), credentials : 'same-origin', redirect : 'follow' } ).then( function( response ) {

				if( response.ok !== true )
					return null;

				return response.text().then( function( html ) {

					const fresh = blockOf( new wn.DOMParser().parseFromString( html, 'text/html' ) );
					// The state the redirect ended on: the answer is a saved comment only when it says sent
					const said = /[?&]review-state=([a-z]+)(?:[&#]|$)/.exec( String( response.url ) );

					return fresh === null ? null : { fresh : fresh, saved : said !== null && said[1] === 'sent' };
				} );
			} ).then( function( result ) {

				if( result === null )
					plain();
				else
					settle( result.fresh, result.saved );
			}, plain );
		} );

		root.addEventListener( 'click', function( event ) {

			const time = event.target.closest('.review-comment__time');

			if( time !== null ) {

				const item = time.closest('[data-review-at]');

				if( item !== null && seek( media, Number( item.getAttribute('data-review-at') ) ) === true )
					event.preventDefault();

				return;
			}

			const reply = event.target.closest('[data-review-reply]');
			const target = reply !== null ? reply.closest('[data-review-id]') : null;

			if( target !== null )
				answer( target );
		} );
	};

	/**
	 *	Take over every player of the page
	 *
	 *	@return		void
	 */
	const init = function() {
		Array.from( dc.querySelectorAll('.review-player') ).forEach( take );
	};

	wn.Nino.review = { seconds : seconds, clock : clock, position : position, seek : seek, init : init };

	Nino.events.bindCallback( 'ready', init );

} )(window,document);

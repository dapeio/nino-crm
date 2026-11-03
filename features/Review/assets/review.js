/**
 *	Nino - Review
 *	review.js	The script of the player [review-player] on the site. The page works without it: the
 *						time of a comment is a link to the file at that second, the form is a plain post, and
 *						the time field is typed as m:ss or left empty. With it, a click on the time of a comment
 *						takes the player there and plays from it, the time field follows the player - it is
 *						filled when the player stops or is moved, until the person has typed in it or in the
 *						comment, and a button writes the position of the player into it on demand, as m:ss
 *						and the tenth of a second after a point where there is one - and a
 *						button on each comment prepares an answer: the id of the comment goes into the hidden
 *						field parent, a line says who is answered and has a button to give that up, and the
 *						comment is written without a time unless one is typed. The form is posted by the
 *						browser, never by this script.
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
		const now = form.querySelector('.review-form__now');

		if( at === null || parent === null || text === null )
			return;

		root.dataset.reviewBound = '1';

		// The controls that do nothing without the script are drawn hidden, and shown now
		Array.from( root.querySelectorAll('[data-review-reply], .review-form__now') ).forEach( function( control ) { control.hidden = false } );

		// Whether the time field is the person's now: typed in, or the comment is under way
		let typed = false;
		let line = null;

		const follow = function() {
			if( typed === false )
				at.value = position( media.currentTime );
		};

		[ 'pause', 'seeked', 'ended' ].forEach( function( name ) { media.addEventListener( name, follow ) } );
		at.addEventListener( 'input', function() { typed = true } );
		text.addEventListener( 'input', function() { typed = true } );

		if( now !== null )
			now.addEventListener( 'click', function() {
				at.value = position( media.currentTime );
				typed = false;
			} );

		/**
		 *	Give up the answer: the comment is one of its own again, and the time field follows the player again - unless the
		 *	person has typed a time or a comment since, which is theirs
		 *
		 *	@return		void
		 */
		const cancel = function() {
			parent.value = '';
			typed = at.value.trim() !== '' || text.value.trim() !== '';

			if( line !== null )
				line.box.hidden = true;
		};

		/**
		 *	Prepare an answer to a comment or to an answer: its id into the field parent, who is answered in the line above
		 *	the comment, no time unless one is typed, and the focus in the comment
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
			typed = true;
			text.focus();
		};

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

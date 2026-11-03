/**
 *	Nino - Sign
 *	sign.js		The script of the pad on the signing page of [sign-form]. The form works without it:
 *						the member types a name in the field under the pad, and that signs. With it the
 *						canvas of the form (data-sign-pad) is shown - the box around it, data-sign-draw, comes
 *						with the attribute hidden - and takes a stroke with the mouse, a finger or a pen
 *						(pointer events): a line of a few pixels, drawn as the pointer moves. The button
 *						data-sign-clear empties it. While the pad holds a stroke the field of the typed name,
 *						data-sign-name, is hidden - there is one way to sign at a time -, and on submit the
 *						strokes are drawn again, in a dark ink on a transparent ground whatever the pad's own
 *						colours are, into a canvas of the same size, and its PNG is written as a data URL into
 *						the hidden field data-sign-image, which the server reads as the member's picture. A form
 *						without a stroke leaves the field empty.
 *
 *						Bundled into the project's script bundle by the manifest's assets, so it is a static
 *						file that is never rendered through the fill engine. It reads no word and writes no
 *						text: the page's own words are in the template.
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	'use strict';

	// The ink of the picture that is posted: dark, whatever ground the pad is drawn on, since the picture is laid on the white of a PDF
	const INK = '#10162f';

	/**
	 *	The place of a pointer on the canvas, in the pixels of the canvas: the canvas is drawn at the size the page
	 *	gives it, which is not its own. Pure
	 *
	 *	@param		{Object}	rect						{ left, top, width, height } of the canvas on the page
	 *	@param		{Object}	size						{ width, height } of the canvas in pixels
	 *	@param		{number}	x								clientX of the pointer
	 *	@param		{number}	y								clientY of the pointer
	 *
	 *	@return		{Object}									{ x, y }
	 */
	const place = function( rect, size, x, y ) {

		const width = rect.width > 0 ? rect.width : size.width;
		const height = rect.height > 0 ? rect.height : size.height;

		return { x : ( x - rect.left ) * size.width / width, y : ( y - rect.top ) * size.height / height };
	};

	/**
	 *	Whether there is anything to draw: some stroke has a point. Pure
	 *
	 *	@param		{Array}		strokes					A list of strokes, each a list of { x, y }
	 *
	 *	@return		{boolean}
	 */
	const inked = function( strokes ) {
		return strokes.some( function( stroke ) { return stroke.length > 0 } );
	};

	/**
	 *	Draw the strokes on a 2d context: a dot for a stroke of one point, else a line through the points
	 *
	 *	@param		{Object}	context					A 2d context
	 *	@param		{Array}		strokes
	 *	@param		{number}	width						The width of the canvas in pixels
	 *	@param		{number}	height					The height of the canvas in pixels
	 *	@param		{string}	color						A css colour
	 *
	 *	@return		void
	 */
	const paint = function( context, strokes, width, height, color ) {

		const line = Math.max( 2, Math.round( width * 3 / 600 ) );

		context.clearRect( 0, 0, width, height );
		context.strokeStyle = color;
		context.fillStyle = color;
		context.lineWidth = line;
		context.lineCap = 'round';
		context.lineJoin = 'round';

		strokes.forEach( function( stroke ) {

			if( stroke.length === 0 )
				return;

			if( stroke.length === 1 ) {
				context.beginPath();
				context.arc( stroke[0].x, stroke[0].y, line / 2, 0, Math.PI * 2 );
				context.fill();
				return;
			}

			context.beginPath();
			context.moveTo( stroke[0].x, stroke[0].y );
			stroke.slice( 1 ).forEach( function( point ) { context.lineTo( point.x, point.y ) } );
			context.stroke();
		} );
	};

	/**
	 *	The picture the strokes make as the server takes it: a PNG data URL of a canvas of the pad's size, in the
	 *	dark ink on a ground that is transparent. '' where there is no stroke
	 *
	 *	@param		{Array}		strokes
	 *	@param		{number}	width
	 *	@param		{number}	height
	 *
	 *	@return		{string}
	 */
	const picture = function( strokes, width, height ) {

		if( inked( strokes ) === false )
			return '';

		const canvas = dc.createElement('canvas');

		canvas.width = width;
		canvas.height = height;

		const context = canvas.getContext('2d');

		if( context === null )
			return '';

		paint( context, strokes, width, height, INK );

		return canvas.toDataURL('image/png');
	};

	/**
	 *	Take a form over: the pad is shown, strokes are drawn, and the picture is written when the form is sent
	 *
	 *	@param		{Element}	form						A form with data-sign-form
	 *
	 *	@return		void
	 */
	const take = function( form ) {

		if( form.dataset.signBound === '1' )
			return;

		const canvas = form.querySelector('canvas[data-sign-pad]');
		const image = form.querySelector('input[data-sign-image]');
		const draw = form.querySelector('[data-sign-draw]');
		const clear = form.querySelector('[data-sign-clear]');
		const typed = form.querySelector('[data-sign-name]');

		// Without what it needs the form is left to the browser, which posts the typed name
		if( canvas === null || image === null || draw === null || typeof canvas.getContext !== 'function' )
			return;

		const context = canvas.getContext('2d');

		if( context === null )
			return;

		form.dataset.signBound = '1';
		draw.hidden = false;

		const size = { width : canvas.width, height : canvas.height };
		let strokes = [];
		let current = null;

		const refresh = function() {

			paint( context, strokes, size.width, size.height, wn.getComputedStyle( canvas ).color || INK );

			if( typed !== null )
				typed.hidden = inked( strokes );

			if( clear !== null )
				clear.disabled = inked( strokes ) === false;
		};

		const at = function( event ) {
			return place( canvas.getBoundingClientRect(), size, event.clientX, event.clientY );
		};

		canvas.addEventListener( 'pointerdown', function( event ) {

			// The primary button of a mouse; a finger and a pen have no other
			if( event.pointerType === 'mouse' && event.button !== 0 )
				return;

			event.preventDefault();

			if( typeof canvas.setPointerCapture === 'function' && event.pointerId !== undefined )
				canvas.setPointerCapture( event.pointerId );

			current = [ at( event ) ];
			strokes.push( current );
			refresh();
		} );

		canvas.addEventListener( 'pointermove', function( event ) {

			if( current === null )
				return;

			current.push( at( event ) );
			refresh();
		} );

		[ 'pointerup', 'pointercancel' ].forEach( function( name ) {
			canvas.addEventListener( name, function() { current = null } );
		} );

		if( clear !== null )
			clear.addEventListener( 'click', function() {
				strokes = [];
				current = null;
				image.value = '';
				refresh();
			} );

		form.addEventListener( 'submit', function() {
			image.value = picture( strokes, size.width, size.height );
		} );

		refresh();
	};

	/**
	 *	Take over every signing form of the page
	 *
	 *	@return		void
	 */
	const init = function() {
		Array.from( dc.querySelectorAll('form[data-sign-form]') ).forEach( take );
	};

	wn.Nino.sign = { place : place, inked : inked, paint : paint, picture : picture, init : init };

	Nino.events.bindCallback( 'ready', init );

} )(window,document);

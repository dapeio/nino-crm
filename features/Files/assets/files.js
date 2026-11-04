/**
 *	Nino - Files
 *	files.js	The script of the upload form [files-upload] on the site. A form with
 *						the class files-upload posts to /.files/upload and works without this
 *						script, for one file at a time; with it, the submit is taken over:
 *						every file is cut into chunks of data-files-chunk bytes and posted in
 *						order with the fields of the form (folder, the csrf token) and the
 *						ones of the upload (name, size, upload, index and count), so that a
 *						file is not bound by the post size of the server. A web server that
 *						takes less than that (its own limit, which php does not see) answers
 *						a chunk with 413: the file then starts again as a new upload in chunks
 *						of half the size, down to 128 KiB, and the size that works is kept
 *						for the files after it. Each file has a row
 *						in the list of the form with its progress and, when it is done or
 *						refused, the word of the answer: a refusal names its code, and the
 *						word of the code is one of the fills the Jstext module hands the
 *						script (/feature/files/state/ and /feature/files/script/, which the
 *						class publishes). A file over data-files-max is refused before a byte
 *						is sent. When every file is in, the page is loaded again with
 *						?files=sent, so that the state line says what happened and a browser
 *						component on the page lists the new files.
 *
 *						Bundled into the project's script bundle by the manifest's assets, so
 *						it is a static file that is never rendered through the fill engine:
 *						every word is asked for with Nino.content.getText(), and every value
 *						that comes from the page or from a file is set as text.
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	'use strict';

	// What the script reads off the form: data-files-chunk, data-files-max and data-files-multiple, by the kernel's reader of such attributes
	const SCHEMA = {
		chunk		: { type : 'int', fallback : 0 },
		max			: { type : 'int', fallback : 0 },
		multiple	: { type : 'flag', fallback : false },
	};

	// The smallest chunk a file is cut into when the web server refuses a request with 413: 128 KiB
	const FLOOR = 131072;

	/**
	 *	The chunk an earlier upload of this tab found the web server takes, kept over the reload that ends a submit. Storage may be missing or full:
	 *	then nothing is kept and the next submit starts at the setting
	 *
	 *	@param		{string}	key
	 *
	 *	@return		{number}									The bytes; 0 for none, or for a value that is none a chunk can be
	 */
	const kept = function( key ) {

		try {
			const value = parseInt( wn.sessionStorage.getItem( key ), 10 );
			return Number.isInteger( value ) === true && value >= FLOOR ? value : 0;
		}
		catch( e ) {
			return 0;
		}
	};

	/**
	 *	@param		{string}	key
	 *	@param		{number}	chunk
	 *
	 *	@return		{void}
	 */
	const keep = function( key, chunk ) {

		try {
			wn.sessionStorage.setItem( key, String( chunk ) );
		}
		catch( e ) {
			// Storage is not there: the next submit starts at the setting again
		}
	};

	// A code of a refusal is files_ and a word; its sentence is /feature/files/state/<word>
	const CODE = /^files_([a-z]+)$/;

	/**
	 *	The chunks a file of a size is cut into: every one but the last is exactly a chunk long. Pure
	 *
	 *	@param		{number}	size						The bytes of the file
	 *	@param		{number}	chunk						The bytes of a chunk
	 *
	 *	@return		{Array}										[ [ start, end ], ... ], end not included - none for a size or a chunk that is no whole number above 0
	 */
	const chunks = function( size, chunk ) {

		const parts = [];

		if( Number.isInteger( size ) === false || Number.isInteger( chunk ) === false || size < 1 || chunk < 1 )
			return parts;

		for( let start = 0; start < size; start += chunk )
			parts.push( [ start, Math.min( start + chunk, size ) ] );

		return parts;
	};

	/**
	 *	The chunk to try after a request of the chunk's size was answered 413 (the web server takes no request so large): half of it,
	 *	not below the floor. A file shorter than the chunk is sent in one piece, so it is its length that is halved. Pure
	 *
	 *	@param		{number}	size						The bytes of the file
	 *	@param		{number}	chunk						The bytes of the chunk that was refused
	 *
	 *	@return		{number}									The smaller chunk; 0 when the chunk is at the floor already - nothing smaller is tried
	 */
	const halve = function( size, chunk ) {

		const sent = Math.min( size, chunk );

		return Number.isInteger( sent ) === true && sent > FLOOR ? Math.max( FLOOR, Math.floor( sent / 2 ) ) : 0;
	};

	/**
	 *	How far a file is, in whole percent. Pure
	 *
	 *	@param		{number}	sent						The bytes that are in
	 *	@param		{number}	size						The bytes of the file
	 *
	 *	@return		{number}									0 to 100
	 */
	const percent = function( sent, size ) {
		return size > 0 ? Math.max( 0, Math.min( 100, Math.floor( sent * 100 / size ) ) ) : 0;
	};

	/**
	 *	A word of the feature, as the page publishes it
	 *
	 *	@param		{string}	key							Below /feature/files/, eg. 'state/type'
	 *
	 *	@return		{string}
	 */
	const word = function( key ) {
		return Nino.content.getText( '/feature/files/'+ key );
	};

	/**
	 *	What an answer that is none of a success says: the word of its code, else - a post the csrf guard refused is a 403
	 *	with no body - that the session has run out, else - no answer at all - that the connection failed, else the write
	 *	that could not be made
	 *
	 *	@param		{Object}	xhr							The finished request: the status, the body in responseJSON
	 *
	 *	@return		{string}
	 */
	const refusal = function( xhr ) {

		const body = xhr.responseJSON !== null && typeof xhr.responseJSON === 'object' ? xhr.responseJSON : {};
		const found = typeof body.code === 'string' ? CODE.exec( body.code ) : null;

		if( found !== null && word( 'state/'+ found[1] ) !== '' )
			return word( 'state/'+ found[1] );

		if( typeof xhr.getAllResponseHeaders === 'function' && xhr.getAllResponseHeaders() === '' )
			return word('script/offline');

		return xhr.status === 403 ? word('script/expired') : word('state/write');
	};

	/**
	 *	A row of the list for one file
	 *
	 *	@param		{Element}	list
	 *	@param		{string}	name
	 *
	 *	@return		{Object}									{ item, status, bar }
	 */
	const row = function( list, name ) {

		const item = dc.createElement('li');
		const label = dc.createElement('span');
		const status = dc.createElement('span');
		const bar = dc.createElement('progress');

		item.className = 'files-upload__item';
		item.dataset.state = 'waiting';
		label.className = 'files-upload__name';
		label.textContent = name;
		status.className = 'files-upload__status';
		status.textContent = word('script/waiting');
		bar.className = 'files-upload__bar';
		bar.max = 100;
		bar.value = 0;

		item.appendChild( label );
		item.appendChild( status );
		item.appendChild( bar );
		list.appendChild( item );

		return { item : item, status : status, bar : bar };
	};

	/**
	 *	Put a row in a state
	 *
	 *	@param		{Object}	line
	 *	@param		{string}	state						sending, done or failed
	 *	@param		{string}	text
	 *
	 *	@return		void
	 */
	const mark = function( line, state, text ) {
		line.item.dataset.state = state;
		line.status.textContent = text;
	};

	/**
	 *	Send one file, chunk after chunk: the next is posted when the answer of the one before has come, and the upload id
	 *	the first one was answered with goes with every one after it. A chunk the web server answers 413 is the sign that the
	 *	server takes no request of this size (its own limit, which php does not see): the file starts again from its first
	 *	chunk, as a new upload, in chunks of half the size - down to the floor, where it fails - and the size that works is
	 *	left in form.chunk for the files after it
	 *
	 *	@param		{Object}		form						The form's settings: { element, folder, token, chunk }
	 *	@param		{File}			file
	 *	@param		{Object}		line						The row of the file
	 *	@param		{Function}	finished				Called with true once the file is in, false when it is not
	 *
	 *	@return		void
	 */
	const send = function( form, file, line, finished ) {

		let parts = chunks( file.size, form.chunk );
		let upload = '';

		mark( line, 'sending', word('script/sending') );

		const next = function( index ) {

			Nino.http.sendRequest( Nino.dir+ '/.files/upload', 'POST', function( xhr ) {

				const body = xhr.responseJSON !== null && typeof xhr.responseJSON === 'object' ? xhr.responseJSON : {};

				if( xhr.status === 413 ) {

					const smaller = halve( file.size, form.chunk );

					if( smaller === 0 ) {

						// A refusal that names its cause (a code the script has a word for) says it; one that does not is the web server's
						const found = typeof body.code === 'string' ? CODE.exec( body.code ) : null;

						mark( line, 'failed', found !== null && word( 'state/'+ found[1] ) !== '' ? refusal( xhr ) : ( word('script/floor') !== '' ? word('script/floor') : word('state/chunk') ) );
						finished( false );
						return;
					}

					form.chunk = smaller;
					parts = chunks( file.size, smaller );
					upload = '';
					line.bar.value = 0;
					// A site that took the feature in before these words existed has them only once its texts are brought up to date: until then the word of the upload stands
					mark( line, 'sending', word('script/smaller') !== '' ? word('script/smaller') : word('script/sending') );
					next( 0 );
					return;
				}

				if( xhr.status !== 200 ) {
					mark( line, 'failed', refusal( xhr ) );
					finished( false );
					return;
				}

				if( typeof body.upload === 'string' )
					upload = body.upload;

				line.bar.value = percent( parts[index][1], file.size );

				if( index + 1 < parts.length ) {
					next( index + 1 );
					return;
				}

				mark( line, 'done', word('script/done') );
				finished( true );
			}, {
				folder	: form.folder,
				name		: file.name,
				size		: file.size,
				upload	: upload,
				index		: index,
				count		: parts.length,
				chunk		: file.slice( parts[index][0], parts[index][1] ),
				_csrf		: form.token,
			} );
		};

		next( 0 );
	};

	/**
	 *	The page again with ?files=sent: the state line says what happened, and a browser component lists what came
	 *
	 *	@return		void
	 */
	const reload = function() {

		const url = new URL( wn.location.href );

		url.searchParams.set( 'files', 'sent' );
		wn.location.assign( url.pathname+ url.search );
	};

	/**
	 *	Take a form over: its submit sends every file the person chose, one after the other
	 *
	 *	@param		{Element}	element					A form.files-upload
	 *
	 *	@return		void
	 */
	const take = function( element ) {

		if( element.dataset.filesBound === '1' || typeof wn.FormData !== 'function' || typeof wn.File !== 'function' )
			return;

		const input = element.querySelector('input[type="file"]');
		const list = element.querySelector('.files-upload__list');
		const button = element.querySelector('button[type="submit"]');
		const folder = element.querySelector('input[name="folder"]');
		const token = element.querySelector('input[name="_csrf"]');
		const options = Nino.ui.options( element, 'files', SCHEMA );

		// Without what it needs the form is left to the browser, which posts it whole
		if( input === null || list === null || folder === null || options.chunk < 1 )
			return;

		element.dataset.filesBound = '1';
		input.multiple = options.multiple;

		let busy = false;
		// Kept per form, by its action, over the reload at the end of a submit
		const memory = 'nino-files-chunk:'+ ( element.getAttribute('action') || '' );
		let working = kept( memory );

		element.addEventListener( 'submit', function( event ) {

			const files = Array.from( input.files || [] );

			if( busy === true ) {
				event.preventDefault();
				return;
			}

			// Nothing chosen: the browser asks for a file, as the attribute required says
			if( files.length === 0 )
				return;

			event.preventDefault();

			busy = true;
			list.hidden = false;
			list.textContent = '';

			if( button !== null )
				button.disabled = true;

			const settings = { element : element, folder : folder.value, token : token === null ? '' : token.value, chunk : working > 0 ? Math.min( working, options.chunk ) : options.chunk };
			let ok = true;

			// Every file has its row from the start - the ones that are not at their turn yet wait in it
			const lines = files.map( function( file ) { return row( list, file.name ) } );

			const go = function( at ) {

				if( at === files.length ) {

					busy = false;
					input.value = '';

					if( button !== null )
						button.disabled = false;

					if( ok === true )
						reload();

					return;
				}

				const file = files[at];
				const line = lines[at];

				// Refused before a byte is sent: a file that is too large, or empty
				if( ( options.max > 0 && file.size > options.max ) || file.size < 1 ) {
					mark( line, 'failed', word( file.size < 1 ? 'state/chunk' : 'state/size' ) );
					ok = false;
					go( at + 1 );
					return;
				}

				send( settings, file, line, function( sent ) {
					ok = ok === true && sent === true;
					working = settings.chunk;

					if( working < options.chunk )
						keep( memory, working );
					go( at + 1 );
				} );
			};

			go( 0 );
		} );
	};

	/**
	 *	Take over every upload form of the page
	 *
	 *	@return		void
	 */
	const init = function() {
		Array.from( dc.querySelectorAll('form.files-upload') ).forEach( take );
	};

	wn.Nino.files = { chunks : chunks, halve : halve, floor : FLOOR, percent : percent, init : init };

	Nino.events.bindCallback( 'ready', init );

} )(window,document);

/**
 *	Crm		The surface of the CRM in the browser: the page at /crm draws its screens into the main region from the data of one
 *				JSON block (the words, the token, the user, the links) and the answers of its endpoint. It is not part of the
 *				workbench and loads none of its scripts; money.js (the arithmetic of the documents, case for case Money.php) is the
 *				only other file it reads.
 *
 *				  the model		pure functions: the names of a document, the filters and the sort of the list, the draft of the editor and
 *								what it posts, the totals it shows
 *				  the shell		the element helper (every element is made from data, no markup is written), the toast, the menus, the dialogs
 *								(<dialog>), the keys (/ finds the search, Esc closes), the router (#uebersicht, #belege, #beleg-12,
 *								#entwurf-16, #neu-invoice, #kunden, #kunde-3, #kunde-3-portal, #kunde-neu, #positionen, #einstellungen, #einrichtung) and the
 *								answers of the endpoint (the session that ended, the token that is stale)
 *				  the screens	Übersicht, Belege, Beleg and Entwurf, then Kunden, Kundenkarte, Neuer Kunde, Positionen and Einstellungen, and the
 *								Einrichtung of the first run (the page starts there while crm/status says the product is not whole)
 *
 *				Every string that comes from the server or from a person enters the page as textContent or as a property of an
 *				element; nothing here builds HTML.
 */

( function( wn, dc ) {

	'use strict';

	wn.Nino = wn.Nino || {};
	wn.Nino.crm = wn.Nino.crm || {};

	const PLAIN = [ 'contract', 'privacy', 'information' ];
	const SIGNABLE = [ 'contract', 'privacy' ];
	const PAGE_SIZE = 50;
	const NEW_KINDS = [ 'invoice', 'offer', 'contract', 'privacy', 'information' ];
	const CLIENT_FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id' ];
	const CLIENT_TABS = [ 'belege', 'portal', 'notizen' ];
	const RELOAD_KEY = 'crm-reloaded';
	const AUTOSAVE_MS = 800;

	// The properties the element helper sets as properties; every other key is an attribute
	const PROPERTIES = [ 'value', 'checked', 'disabled', 'hidden', 'selected', 'title', 'placeholder', 'type', 'id', 'name', 'rows', 'src', 'tabIndex', 'max', 'min' ];
	const BOOLEANS = [ 'checked', 'disabled', 'hidden', 'selected' ];

	const state = {};

	/**
	 *	Everything the page remembers; boot() starts it again
	 *
	 *	@return		{void}
	 */
	const reset = function() {

		Object.keys( state ).forEach( function( key ) { delete state[key] } );

		Object.assign( state, {
			base : '', csrf : '', perm : '', user : '', texts : {}, links : {},
			settings : { currency : '', vat_mode : 'small_business', rates : [], payment_days : '14', offer_days : '30' },
			clients : [], items : [], offers : [], years : [], docs : [], summary : null, people : [], owed : {},
			filter : { chip : 'all', q : '', sort : 'date', dir : -1, year : 0, page : 1 },
			cfilter : { q : '', sort : 'number', dir : 1, page : 1 },
			view : 0, entered : false, seen : null, tab : '', draft : null, saving : false, queued : false, pending : null, timer : null, main : null, toast : null, dialogs : null, count : null, clientCount : null,
		} );
	};

	reset();


	// --- the element helper ------------------------------------------------------------------------------------------

	/**
	 *	Put children into an element: strings and numbers as text, arrays flattened, nothing for null, undefined and false
	 *
	 *	@param		{Object}	node
	 *	@param		{Array}		kids
	 *
	 *	@return		{void}
	 */
	const append = function( node, kids ) {

		kids.forEach( function( kid ) {

			if( kid === null || kid === undefined || kid === false )
				return;

			if( Array.isArray( kid ) === true )
				append( node, kid );
			else if( typeof kid === 'string' || typeof kid === 'number' )
				node.appendChild( dc.createTextNode( String( kid ) ) );
			else
				node.appendChild( kid );
		} );
	};

	/**
	 *	An element: the tag, its attributes and properties as an object (class, text, dataset, on for the listeners, the
	 *	names of PROPERTIES as properties, the rest - href among them - as attributes) and its children
	 *
	 *	@param		{string}	tag
	 *	@param		{Object}	[attrs]
	 *	@param		{...*}		kids
	 *
	 *	@return		{Object}
	 */
	const el = function( tag, attrs, ...kids ) {

		const node = dc.createElement( tag );
		const later = [];

		Object.keys( attrs || {} ).forEach( function( key ) {

			const value = attrs[key];

			if( value === null || value === undefined || ( value === false && BOOLEANS.includes( key ) === true ) )
				return;

			if( key === 'class' )
				node.className = value;
			else if( key === 'text' )
				node.textContent = String( value );
			else if( key === 'dataset' )
				Object.keys( value ).forEach( function( name ) { node.dataset[name] = String( value[name] ) } );
			else if( key === 'on' )
				Object.keys( value ).forEach( function( name ) { node.addEventListener( name, value[name] ) } );
			else if( key === 'value' )
				later.push( [ key, String( value ) ] );
			else if( PROPERTIES.includes( key ) === true )
				node[key] = BOOLEANS.includes( key ) === true || typeof value === 'number' ? value : String( value );
			else
				node.setAttribute( key, String( value ) );
		} );

		append( node, kids );

		// A select takes its value when its options are there
		later.forEach( function( pair ) { node[pair[0]] = pair[1] } );

		return node;
	};

	/**
	 *	@param		{Object}	node
	 *
	 *	@return		{void}										Nothing left in it
	 */
	const clear = function( node ) {

		node.textContent = '';

		while( node.firstChild !== null && node.firstChild !== undefined )
			node.removeChild( node.firstChild );
	};

	/**
	 *	@param		{string}	key
	 *	@param		{...*}		params			Values for the %s of the word
	 *
	 *	@return		{string}									The word; its key where there is none, which a test would catch
	 */
	const t = function( key, ...params ) {

		const word = state.texts[key];

		if( typeof word !== 'string' )
			return key;

		let at = 0;

		return word.replace( /%s/g, function() {
			const value = params[at++];
			return value === undefined ? '' : String( value );
		} );
	};


	// --- the model ---------------------------------------------------------------------------------------------------

	const pad = function( number ) { return String( number ).padStart( 2, '0' ) };

	/**
	 *	@param		{Date}		[date]
	 *
	 *	@return		{string}									Y-m-d, in the browser's own time
	 */
	const today = function( date ) {
		const at = date || new Date();
		return at.getFullYear()+ '-'+ pad( at.getMonth()+ 1 )+ '-'+ pad( at.getDate() );
	};

	/**
	 *	@param		{string}	day							Y-m-d
	 *	@param		{number}	days
	 *
	 *	@return		{string}
	 */
	const addDays = function( day, days ) {
		const at = new Date( day+ 'T12:00:00' );
		at.setDate( at.getDate()+ days );
		return today( at );
	};

	/**
	 *	@param		{string}	iso							Y-m-d, or a time that begins with it
	 *
	 *	@return		{string}									d.m.Y; '' for none
	 */
	const dmy = function( iso ) {
		return typeof iso === 'string' && /^\d{4}-\d{2}-\d{2}/.test( iso ) === true ? iso.slice( 8, 10 )+ '.'+ iso.slice( 5, 7 )+ '.'+ iso.slice( 0, 4 ) : '';
	};

	/**
	 *	@param		{string}	iso							A time of the server, Y-m-dTH:i:sZ
	 *
	 *	@return		{string}									The time of day in the browser's time, HH:MM; '' for none
	 */
	const hm = function( iso ) {
		const at = typeof iso === 'string' ? new Date( iso ) : null;
		return at === null || isNaN( at.getTime() ) === true ? '' : pad( at.getHours() )+ ':'+ pad( at.getMinutes() );
	};

	/**
	 *	@param		{string}	iso							A time of the server
	 *
	 *	@return		{string}									The day, and the time of day unless the time is midnight (a day somebody named)
	 */
	const stamp = function( iso ) {

		if( typeof iso !== 'string' || iso === '' )
			return '';

		return /T00:00:00Z$/.test( iso ) === true ? dmy( iso ) : dmy( iso )+ ' '+ hm( iso );
	};

	const money = function() { return wn.Nino.crm.money };

	/**
	 *	@param		{number}	cents
	 *
	 *	@return		{string}									With the currency of the settings
	 */
	const cash = function( cents ) { return money().format( cents, state.settings.currency ) };

	/**
	 *	@param		{Object}	doc							A document of the list, or one document
	 *
	 *	@return		{string}									Its number; a document without one its title; a draft without either the word for it
	 */
	const docName = function( doc ) {
		return doc.number || doc.title || t( 'state.draft' );
	};

	/**
	 *	@param		{Object}	doc
	 *
	 *	@return		{boolean}
	 */
	const isPlain = function( doc ) { return PLAIN.includes( doc.category ) };

	const chips = [
		{ key : 'all', test : function() { return true } },
		{ key : 'open', test : function( doc ) { return doc.category !== 'offer' && [ 'open', 'sent' ].includes( doc.state ) === true && doc.overdue !== true } },
		{ key : 'overdue', test : function( doc ) { return doc.overdue === true } },
		{ key : 'drafts', test : function( doc ) { return doc.state === 'draft' } },
		{ key : 'offers', test : function( doc ) { return doc.category === 'offer' } },
		{ key : 'invoices', test : function( doc ) { return doc.category === 'invoice' || doc.category === 'credit' } },
		{ key : 'plain', test : function( doc ) { return isPlain( doc ) } },
		{ key : 'paid', test : function( doc ) { return doc.state === 'paid' } },
	];

	const sortKeys = {
		number : function( doc ) { return docName( doc ).toLowerCase() },
		client : function( doc ) { return String( doc.client_title ).toLowerCase() },
		date : function( doc ) { return doc.date },
		due : function( doc ) { return doc.due_date || doc.valid_until || '' },
		gross : function( doc ) { return doc.gross },
	};

	/**
	 *	The rows the list shows: the chip, then the search, then the order
	 *
	 *	@param		{Array}		docs
	 *	@param		{Object}	filter					chip, q, sort, dir
	 *
	 *	@return		{Array}
	 */
	const visible = function( docs, filter ) {

		const chip = chips.find( function( one ) { return one.key === filter.chip } ) || chips[0];
		const query = filter.q.trim().toLowerCase();
		const key = sortKeys[filter.sort] || sortKeys.date;

		const rows = docs.filter( chip.test ).filter( function( doc ) {
			return query === '' || ( docName( doc )+ ' '+ doc.title+ ' '+ doc.client_title+ ' '+ t( 'category.'+ doc.category ) ).toLowerCase().includes( query );
		} );

		rows.sort( function( a, b ) {

			const left = key( a );
			const right = key( b );
			const order = typeof left === 'string' ? left.localeCompare( right, 'de' ) : left - right;

			return order !== 0 ? order * filter.dir : b.id - a.id;
		} );

		return rows;
	};

	/**
	 *	One line of the editor, as the person types it: the quantity and the price are the text of the fields
	 *
	 *	@param		{Object}	[line]					A line of a document
	 *	@param		{number}	[rate]
	 *
	 *	@return		{Object}
	 */
	const lineOf = function( line, rate ) {

		if( line === undefined )
			return { title : '', description : '', unit : '', qty : '1', price : '', vat_rate : rate || 0 };

		return { title : line.title, description : line.description, unit : line.unit, qty : money().formatQty( line.qty ), price : money().format( line.price_net ), vat_rate : line.vat_rate };
	};

	/**
	 *	What a line is worth for the sums: a field that is no number counts 0 and says so
	 *
	 *	@param		{Object}	line
	 *
	 *	@return		{Object}									qty, price_net, vat_rate and valid
	 */
	const valuesOf = function( line ) {

		const qty = money().parseQty( line.qty.trim() );
		const price = money().parse( line.price.trim() );

		return { qty : qty === null ? 0 : qty, price_net : price === null ? 0 : price, vat_rate : line.vat_rate, valid : qty !== null && price !== null };
	};

	/**
	 *	@param		{Object}	draft
	 *
	 *	@return		{Object}									The totals of its lines, as Money has them
	 */
	const totalsOf = function( draft ) {
		return money().totals( draft.lines.map( valuesOf ), draft.vat_mode, draft.reverse_charge === true && draft.vat_mode === 'regular' );
	};

	/**
	 *	A draft of the editor for a document that is a draft
	 *
	 *	@param		{Object}	doc
	 *
	 *	@return		{Object}
	 */
	const draftOf = function( doc ) {

		return {
			id : doc.id, category : doc.category, vat_mode : doc.vat_mode, client_id : String( doc.client_id ), parent_id : doc.parent_id === null ? '' : String( doc.parent_id ),
			date : doc.date, due_date : doc.due_date || '', valid_until : doc.valid_until || '', period_from : doc.period_from || '', period_to : doc.period_to || '',
			template_key : doc.template_key, inputs : Object.assign( {}, doc.inputs ), reverse_charge : doc.reverse_charge === true, title : doc.title, notes : doc.notes,
			lines : doc.lines.map( function( line ) { return lineOf( line ) } ), updated : doc.updated, dirty : false, templates : [],
		};
	};

	/**
	 *	A draft of the editor for a document that is not made yet
	 *
	 *	@param		{string}	category
	 *	@param		{Object}	settings
	 *	@param		{string}	[offer]					For a contract: the offer it hangs on
	 *
	 *	@return		{Object}
	 */
	const draftNew = function( category, settings, offer ) {

		const day = today();

		return {
			id : 0, category : category, vat_mode : settings.vat_mode, client_id : '', parent_id : offer || '', date : day,
			due_date : category === 'invoice' ? addDays( day, Number( settings.payment_days ) ) : '', valid_until : category === 'offer' ? addDays( day, Number( settings.offer_days ) ) : '',
			period_from : '', period_to : '', template_key : '', inputs : {}, reverse_charge : false, title : '', notes : '',
			lines : [], updated : '', dirty : false, templates : [],
		};
	};

	/**
	 *	What the endpoint is posted to save a draft
	 *
	 *	@param		{Object}	draft
	 *
	 *	@return		{Object}
	 */
	const payloadOf = function( draft ) {

		const plain = isPlain( draft ) === true;
		const names = ( draft.templates.find( function( one ) { return one.key === draft.template_key } ) || { inputs : [] } ).inputs.map( function( input ) { return input.name } );
		const inputs = {};

		names.forEach( function( name ) { inputs[name] = draft.inputs[name] ?? '' } );

		const data = { date : draft.date, template_key : draft.template_key, inputs : inputs };

		if( draft.id !== 0 )
			data.id = String( draft.id );
		else
			data.category = draft.category;

		if( draft.category === 'contract' )
			data.parent_id = draft.parent_id;
		else
			data.client_id = draft.client_id;

		if( plain === true ) {
			data.title = draft.title;
			return data;
		}

		data.period_from = draft.period_from;
		data.period_to = draft.period_to;
		data.reverse_charge = draft.reverse_charge === true && draft.vat_mode === 'regular';

		if( draft.category === 'invoice' )
			data.due_date = draft.due_date;

		if( draft.category === 'offer' )
			data.valid_until = draft.valid_until;

		data.lines = draft.lines.map( function( line ) {
			return { title : line.title, description : line.description, unit : line.unit, qty : line.qty, price : line.price, vat_rate : line.vat_rate };
		} );

		return data;
	};

	/**
	 *	Whether a draft is whole enough to be saved without a word of the server: a client (or the offer), a title or lines whose fields are
	 *	filled and are numbers. It decides whether the editor saves by itself when a field is left
	 *
	 *	@param		{Object}	draft
	 *
	 *	@return		{boolean}
	 */
	const ready = function( draft ) {

		if( draft.category === 'contract' ? draft.parent_id === '' : draft.client_id === '' )
			return false;

		if( isPlain( draft ) === true )
			return draft.title.trim() !== '';

		return draft.lines.every( function( line ) { return line.title.trim() !== '' && valuesOf( line ).valid === true && valuesOf( line ).qty !== 0 } );
	};

	/**
	 *	@param		{Object}	doc
	 *
	 *	@return		{string}									The address of its stored PDF
	 */
	const pdfUrl = function( doc, saved ) {
		return state.base+ '/.crm/pdf/'+ doc.id+ ( saved === true ? '?download=1' : '' );
	};


	// --- the shell ---------------------------------------------------------------------------------------------------

	/**
	 *	Say something in the toast
	 *
	 *	@param		{string}	message
	 *	@param		{boolean}	[bad]						A refusal stays longer
	 *
	 *	@return		{void}
	 */
	const toast = function( message, bad ) {

		const node = state.toast;

		node.textContent = message;
		node.classList.add( 'show' );
		node.classList.toggle( 'bad', bad === true );
		wn.clearTimeout( state.timer );
		state.timer = wn.setTimeout( function() { node.classList.remove( 'show' ) }, bad === true ? 6000 : 2600 );
	};

	/**
	 *	The sentence for an answer that failed: the one of its code, with its params; else the word for what was being done
	 *
	 *	@param		{Object}	answer
	 *	@param		{string}	[fallback]			A key of the words
	 *
	 *	@return		{string}
	 */
	const errorText = function( answer, fallback ) {

		const body = answer.body !== null && typeof answer.body === 'object' ? answer.body : {};

		if( answer.status === 0 || body.code === 'offline' )
			return t( 'error.offline' );

		if( typeof body.code === 'string' && /^[a-z][a-z0-9_]*$/.test( body.code ) === true && typeof state.texts['error.'+ body.code] === 'string' )
			return t( 'error.'+ body.code, ...( Array.isArray( body.params ) === true ? body.params : [] ) );

		return answer.status === 403 ? t( 'error.forbidden' ) : t( fallback || 'error.request' );
	};

	/**
	 *	Tell a person a request failed, unless the page has said already why (the session)
	 *
	 *	@param		{Object}	answer
	 *	@param		{string}	[fallback]
	 *
	 *	@return		{string}									The sentence
	 */
	const failed = function( answer, fallback ) {

		const text = errorText( answer, fallback );

		if( answer.handled !== true )
			toast( text, true );

		return text;
	};

	/**
	 *	A dialog with one question: the text, optionally fields, and two buttons. Escape and the second button are the no
	 *
	 *	@param		{Object}	spec						title, text, yes, no, danger, fields ([ { name, label, type, value, options } ]), link ({ href, text } instead of the buttons)
	 *
	 *	@return		{Promise}									The values of the fields (an empty object without fields) for the yes; null for the no
	 */
	const dialog = function( spec ) {

		return new Promise( function( resolve ) {

			let answer = null;
			let closed = false;
			let decided = false;
			const controls = {};

			const box = el( 'dialog', { class: 'dlg', 'aria-label': spec.title || spec.text } );
			const finish = function( value ) {

				if( decided === true )
					return;

				decided = true;
				answer = value;
				box.close();
			};

			const accept = function() {

				const values = {};

				Object.keys( controls ).forEach( function( name ) { values[name] = controls[name].value } );
				finish( values );
			};

			const fields = ( spec.fields || [] ).map( function( field ) {

				const control = field.options !== undefined
					? el( 'select', { id: 'dlg-'+ field.name }, field.options.map( function( option ) { return el( 'option', { value: option.value, selected: option.value === field.value, text: option.label } ) } ) )
					: el( 'input', { id: 'dlg-'+ field.name, type: field.type || 'text', value: field.value ?? '', min: field.min, max: field.max } );

				controls[field.name] = control;

				return el( 'label', { class: 'field' }, el( 'span', { text: field.label } ), control );
			} );

			box.addEventListener( 'close', function() {

				if( closed === true )
					return;

				closed = true;

				if( box.parentNode !== null )
					box.parentNode.removeChild( box );

				resolve( answer );
			} );

			const form = el( 'form', { on: { submit: function( event ) { event.preventDefault(); accept() } } },
				spec.title ? el( 'h2', { text: spec.title } ) : null,
				el( 'p', { text: spec.text } ),
				fields,
				el( 'div', { class: 'actions end' },
					spec.link !== undefined
						? el( 'a', { class: 'btn primary', href: spec.link.href, text: spec.link.text, on: { click: function( event ) {
							if( spec.link.reload === true ) {
								event.preventDefault();
								wn.location.reload();
							}
						} } } )
						: [
							el( 'button', { type: 'button', class: 'btn', text: spec.no || t( 'dialog.no' ), on: { click: function() { finish( null ) } } } ),
							el( 'button', { type: 'button', class: 'btn '+ ( spec.danger === true ? 'danger' : 'primary' ), text: spec.yes || t( 'dialog.yes' ), on: { click: accept } } ),
						] ) );

			box.appendChild( form );
			state.dialogs.appendChild( box );
			box.showModal();
		} );
	};

	/**
	 *	@param		{Object}	spec						See dialog()
	 *
	 *	@return		{Promise}									true for the yes
	 */
	const ask = function( spec ) {
		return dialog( spec ).then( function( values ) { return values !== null } );
	};

	/**
	 *	The page tells a session that ended: a dialog with the link to this page, which shows its login where nobody is signed in (the link keeps the hash,
	 *	and a click reads the page again, since a link to the address it is at would only move to the hash)
	 *
	 *	@return		{void}
	 */
	const sessionLost = function() {

		if( state.lost === true )
			return;

		state.lost = true;
		dialog( { title: t( 'session.title' ), text: t( 'session.text' ), link: { href: state.base+ '/crm'+ wn.location.hash, text: t( 'session.link' ), reload : true } } ).then( function() { state.lost = false } );
	};

	/**
	 *	Post an action to the endpoint
	 *
	 *	@param		{string}	action					eg. crm-documents/list
	 *	@param		{Object}	[data]
	 *
	 *	@return		{Promise}									{ ok, status, body, handled }; a request that did not arrive has status 0
	 */
	const api = function( action, data ) {

		return wn.fetch( state.base+ '/crm/api', {
			method : 'POST',
			credentials : 'same-origin',
			headers : { 'Content-Type' : 'application/json', 'X-CSRF-Token' : state.csrf },
			body : JSON.stringify( { action : action, data : data || {} } ),
		} ).then( function( response ) {

			return response.json().then( function( body ) { return body }, function() { return null } ).then( function( body ) {

				const answer = { ok : response.status >= 200 && response.status < 300, status : response.status, body : body, handled : false };
				const code = body !== null && typeof body === 'object' ? body.code : '';

				if( answer.status === 401 && code === 'session' ) {
					answer.handled = true;
					sessionLost();
				}

				// A token that is no longer this session's: the page is read again, once; what it still gets then is the person's to read
				if( answer.status === 403 && code === 'csrf' ) {

					try {
						if( wn.sessionStorage.getItem( RELOAD_KEY ) !== '1' ) {
							wn.sessionStorage.setItem( RELOAD_KEY, '1' );
							wn.location.reload();
							answer.handled = true;
						}
					}
					catch( e ) {
						answer.handled = false;
					}
				}

				if( answer.ok === true ) {
					try {
						wn.sessionStorage.removeItem( RELOAD_KEY );
					}
					catch( e ) {
						// Storage is not there: no flag was set, none needs to go
					}
				}

				return answer;
			} );
		}, function() {
			return { ok : false, status : 0, body : { code : 'offline' }, handled : false };
		} );
	};

	const closeMenus = function( except ) {

		dc.querySelectorAll( '.menu' ).forEach( function( menu ) {
			if( menu !== except ) {
				menu.classList.remove( 'open' );
				const button = menu.querySelector( 'button' );
				if( button !== null )
					button.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	};

	/**
	 *	A menu: a button and the list under it
	 *
	 *	@param		{Object}	spec						label, aria (what a screen reader calls the button), button (the class of the button), align, items ([ { label, run, href, saved, danger } | 'sep' ])
	 *
	 *	@return		{Object}
	 */
	const menu = function( spec ) {

		const root = el( 'div', { class: 'menu' } );
		const button = el( 'button', { type: 'button', class: 'btn '+ ( spec.button || '' ), 'aria-haspopup': 'true', 'aria-expanded': 'false', 'aria-label': spec.aria, text: spec.label, on: { click: function() {
			const open = root.classList.contains( 'open' ) === false;
			closeMenus( root );
			root.classList.toggle( 'open', open );
			button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} } } );

		root.appendChild( button );
		root.appendChild( el( 'div', { class: 'list', role: 'menu' }, spec.items.map( function( item ) {

			if( item === 'sep' )
				return el( 'hr' );

			if( item.href !== undefined )
				return el( 'a', { role: 'menuitem', href: item.href, target: '_blank', rel: 'noopener', text: item.label, on: { click: function() { closeMenus() } } } );

			return el( 'button', { type: 'button', role: 'menuitem', class: item.danger === true ? 'danger' : '', text: item.label, on: { click: function() { closeMenus(); item.run() } } } );
		} ) ) );

		return root;
	};

	/**
	 *	The menu that makes a new document
	 *
	 *	@param		{string}	label
	 *	@param		{number}	[client]				The client the document is for: it is chosen in the editor, and a contract - which follows its offer - is not offered
	 *
	 *	@return		{Object}
	 */
	const newMenu = function( label, client ) {

		const items = [];

		NEW_KINDS.forEach( function( kind ) {

			if( client !== undefined && kind === 'contract' )
				return;

			if( client === undefined && kind === 'contract' )
				items.push( 'sep' );

			items.push( { label : t( 'new.'+ kind ), run : function() { wn.location.hash = '#neu-'+ kind+ ( client === undefined ? '' : '-k'+ client ) } } );
		} );

		return menu( { label : label, button : 'primary', items : items } );
	};

	/**
	 *	The head of a screen
	 *
	 *	@param		{string|Object}	title				The words, or the h1 itself where the screen changes them
	 *	@param		{Array}		parts						After the title: badges, the sub line, the actions
	 *
	 *	@return		{Object}
	 */
	const head = function( title, ...parts ) {
		return el( 'div', { class: 'head' }, typeof title === 'string' ? el( 'h1', { text: title } ) : title, parts );
	};

	/**
	 *	The search field of a head
	 *
	 *	@param		{string}	placeholder
	 *	@param		{string}	value
	 *	@param		{Object}	on							The listeners of the field
	 *
	 *	@return		{Object}
	 */
	const search = function( placeholder, value, on ) {

		return el( 'div', { class: 'search' },
			el( 'input', { id: 'q', type: 'search', placeholder: placeholder, value: value, 'aria-label': placeholder, autocomplete: 'off', on: on } ),
			el( 'kbd', { text: '/', 'aria-hidden': 'true' } ) );
	};

	const badge = function( kind, text, title ) {
		return el( 'span', { class: 'badge b-'+ kind, text: text, title: title } );
	};

	/**
	 *	@param		{Object}	doc
	 *
	 *	@return		{Object}									The badge of the state; an offer past its date or an invoice past its due date says so
	 */
	const stateBadge = function( doc ) {

		if( doc.overdue === true )
			return badge( 'overdue', t( 'state.overdue' ) );

		if( doc.expired === true )
			return badge( 'expired', t( 'state.expired' ) );

		return badge( doc.state, t( 'state.'+ doc.state ) );
	};

	/**
	 *	The small marks beside the state: released to the portal, a signature that waits
	 *
	 *	@param		{Object}	doc
	 *
	 *	@return		{Array}
	 */
	const marks = function( doc ) {

		const marked = [];

		if( doc.released === true )
			marked.push( badge( 'portal', t( 'mark.released' ), t( 'mark.released-title' ) ) );

		if( [ 'requested', 'viewed', 'expired' ].includes( doc.sign_state ) === true && [ 'open', 'sent' ].includes( doc.state ) === true )
			marked.push( badge( 'portal', t( 'sign.badge-'+ doc.sign_state ) ) );

		return marked;
	};

	/**
	 *	The state of a document and its marks, one space between each
	 *
	 *	@param		{Object}	doc
	 *
	 *	@return		{Array}
	 */
	const badges = function( doc ) {

		const list = [ stateBadge( doc ) ].concat( marks( doc ) );

		return list.flatMap( function( one, index ) { return index === 0 ? [ one ] : [ ' ', one ] } );
	};

	const link = function( href, text, attrs ) {
		return el( 'a', Object.assign( { href : href, text : text }, attrs || {} ) );
	};

	const external = function( href, text ) {
		return link( href, text, { target : '_blank', rel : 'noopener' } );
	};

	const note = function( kind, ...kids ) {
		return el( 'div', { class: 'note'+ ( kind === '' ? '' : ' '+ kind ), role: kind === 'bad' ? 'alert' : null }, kids );
	};

	const card = function( title, ...kids ) {
		return el( 'div', { class: 'card pad' }, title === '' ? null : el( 'h3', { text: title } ), kids );
	};

	/**
	 *	A definition list of the pairs that have a value
	 *
	 *	@param		{Array}		pairs						[ [ term, node or text ], ... ]; a pair with an empty value is left out
	 *
	 *	@return		{Object}
	 */
	const kv = function( pairs ) {

		return el( 'dl', { class: 'kv' }, pairs.filter( function( pair ) { return pair[1] !== '' && pair[1] !== null && pair[1] !== false } ).map( function( pair ) {
			return [ el( 'dt', { text: pair[0] } ), el( 'dd', {}, pair[1] ) ];
		} ) );
	};

	/**
	 *	The anchor to a screen of the surface
	 *
	 *	@param		{Object}	doc
	 *
	 *	@return		{string}
	 */
	const hashOf = function( doc ) {
		return ( doc.state === 'draft' ? '#entwurf-' : '#beleg-' )+ doc.id;
	};

	const loading = function() {

		clear( state.main );
		state.main.setAttribute( 'aria-busy', 'true' );
		state.main.appendChild( el( 'p', { class: 'state-note', role: 'status', text: t( 'loading' ) } ) );
	};

	const reveal = function() {
		clear( state.main );
		state.main.setAttribute( 'aria-busy', 'false' );
	};

	/**
	 *	A screen that could not load: the sentence and a button to try again
	 *
	 *	@param		{Object}	answer
	 *	@param		{string}	fallback
	 *	@param		{Function}	again
	 *
	 *	@return		{void}
	 */
	const loadFailed = function( answer, fallback, again ) {

		reveal();
		state.main.appendChild( note( 'bad', el( 'span', { text: failed( answer, fallback ) } ), el( 'button', { type: 'button', class: 'btn sm', text: t( 'retry' ), on: { click: again } } ) ) );
	};

	/**
	 *	Take over what the list and the document answer share
	 *
	 *	@param		{Object}	body
	 *
	 *	@return		{void}
	 */
	const adopt = function( body ) {

		[ 'settings', 'clients', 'items', 'offers', 'years' ].forEach( function( key ) {
			if( body[key] !== undefined )
				state[key] = body[key];
		} );
	};

	/**
	 *	The counts in the rail: the open invoices at Belege, the active clients at Kunden
	 *
	 *	@param		{Object}	summary
	 *
	 *	@return		{void}
	 */
	const countOpen = function( summary ) {

		state.summary = summary;
		state.count.textContent = summary.count > 0 ? String( summary.count ) : '';

		if( state.clientCount !== null )
			state.clientCount.textContent = summary.clients > 0 ? String( summary.clients ) : '';
	};

	/**
	 *	Read the books at a glance again: the rail's count follows what an action changed
	 *
	 *	@return		{Promise}
	 */
	const refresh = function() {

		return api( 'crm-documents/overview' ).then( function( answer ) {

			if( answer.ok === true ) {
				adopt( answer.body );
				countOpen( answer.body.summary );
			}

			return answer;
		} );
	};

	/**
	 *	Post one step on a document; the rail's count is read again when it went through
	 *
	 *	@param		{string}	action
	 *	@param		{Object}	data
	 *	@param		{string}	fallback				The word for what failed
	 *
	 *	@return		{Promise}									The answer; its ok says whether to go on
	 */
	const step = function( action, data, fallback ) {

		return api( action, data ).then( function( answer ) {

			if( answer.ok === true )
				refresh();
			else
				failed( answer, fallback );

			return answer;
		} );
	};

	// --- Übersicht ---------------------------------------------------------------------------------------------------

	const tile = function( label, value, hint, tone ) {

		return el( 'div', { class: 'card tile' },
			el( 'div', { class: 'l', text: label } ),
			el( 'div', { class: 'v'+ ( tone === '' ? '' : ' '+ tone ), text: value } ),
			el( 'div', { class: 'h', text: hint } ) );
	};

	/**
	 *	@param		{Object}	need						An entry of needs()
	 *
	 *	@return		{Object}									Its line
	 */
	const needLine = function( need ) {

		const dot = function( tone ) { return el( 'span', { class: 'dot '+ tone } ) };

		if( need.kind === 'overdue' )
			return el( 'li', {}, el( 'span', {}, dot( 'bad' ), link( '#beleg-'+ need.id, need.number ), ' · '+ need.client_title+ ' · '+ t( 'needs.overdue', dmy( need.date ) ) ), el( 'span', { class: 'r', text: cash( need.gross ) } ) );

		if( need.kind === 'sign' )
			return el( 'li', {}, el( 'span', {}, dot( 'warn' ), link( '#beleg-'+ need.id, need.title ), ' · '+ need.client_title+ ' · '+ t( 'needs.sign-'+ need.sign_state ) ), el( 'span', { class: 'r', text: t( 'needs.since', dmy( need.date ) ) } ) );

		if( need.kind === 'expiring' )
			return el( 'li', {}, el( 'span', {}, dot( 'warn' ), link( '#beleg-'+ need.id, need.number ), ' · '+ need.client_title+ ' · '+ t( 'needs.expiring', dmy( need.date ) ) ), el( 'span', { class: 'r', text: cash( need.gross ) } ) );

		return el( 'li', {}, el( 'span', {}, dot( 'muted' ), link( '#kunde-'+ need.client_id, need.client_title ), ' '+ t( 'needs.account' ) ), el( 'span', { class: 'r' }, link( '#kunde-'+ need.client_id+ '-einladen', t( 'needs.invite' ) ) ) );
	};

	const uebersicht = function( where, mine ) {

		loading();

		api( 'crm-documents/overview' ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			adopt( answer.body );
			countOpen( answer.body.summary );

			const sum = answer.body.summary;
			const needs = answer.body.needs;
			const plural = function( count, one, many ) { return count === 1 ? t( one, count ) : t( many, count ) };

			reveal();

			const find = search( t( 'search.all' ), '', { keydown: function( event ) {
				if( event.key === 'Enter' ) {
					state.filter.q = event.target.value;
					state.filter.page = 1;
					wn.location.hash = '#belege';
				}
			} } );

			state.main.appendChild( head( t( 'overview.title' ), el( 'span', { class: 'sub', text: new Date().toLocaleDateString( 'de-DE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } ) } ), el( 'span', { class: 'grow' } ), find, newMenu( t( 'new.label' ) ) ) );

			state.main.appendChild( el( 'div', { class: 'tiles' },
				tile( t( 'tile.open' ), cash( sum.gross ), plural( sum.count, 'tile.docs-one', 'tile.docs-many' ), '' ),
				tile( t( 'tile.overdue' ), cash( sum.overdue_gross ), sum.overdue_count === 0 ? t( 'tile.overdue-none' ) : plural( sum.overdue_count, 'tile.overdue-one', 'tile.overdue-many' )+ ', '+ t( 'tile.oldest', dmy( sum.overdue_oldest ) ), sum.overdue_count > 0 ? 'bad' : '' ),
				tile( t( 'tile.offers' ), cash( sum.offers_gross ), plural( sum.offers_count, 'tile.offers-one', 'tile.offers-many' ), '' ),
				tile( t( 'tile.paid', sum.paid_year ), cash( sum.paid_gross ), t( 'tile.paid-'+ state.settings.vat_mode ), 'good' ),
				tile( t( 'tile.drafts' ), String( sum.drafts_count ), sum.drafts_count > 0 ? t( 'tile.drafts-last', dmy( sum.drafts_last ) ) : t( 'tile.drafts-none' ), '' ) ) );

			const shown = needs.slice( 0, 10 );

			state.main.appendChild( el( 'div', { class: 'cols' },
				card( t( 'needs.title' ), el( 'ul', { class: 'list-mini' },
					shown.length === 0 ? el( 'li', { class: 'muted', text: t( 'needs.none' ) } ) : shown.map( needLine ),
					needs.length > shown.length ? el( 'li', { class: 'muted', text: t( 'needs.more', needs.length - shown.length ) } ) : null ) ),
				card( t( 'recent.title' ), el( 'ul', { class: 'list-mini' },
					answer.body.recent.length === 0 ? el( 'li', { class: 'muted', text: t( 'recent.none' ) } )
						: answer.body.recent.map( function( doc ) {
							return el( 'li', {}, el( 'span', {}, link( hashOf( doc ), docName( doc ) ), ' ', el( 'span', { class: 'muted', text: t( 'category.'+ doc.category )+ ' · '+ doc.client_title } ) ), el( 'span', { class: 'r', text: dmy( doc.date ) } ) );
						} ) ) ) ) );
		} );
	};


	// --- Belege ------------------------------------------------------------------------------------------------------

	/**
	 *	The head of a sortable column: a button that sorts by it, and again to turn the order
	 *
	 *	@param		{Object}	filter					sort and dir of the table
	 *	@param		{string}	key
	 *	@param		{string}	label
	 *	@param		{boolean}	right					A column of numbers
	 *	@param		{Function}	again				Draws the table again
	 *	@param		{number}	first					The order a column is sorted in the first time: 1 up, -1 down
	 *
	 *	@return		{Object}
	 */
	const sortHead = function( filter, key, label, right, again, first ) {

		const on = filter.sort === key;

		return el( 'th', { class: ( right === true ? 'r ' : '' )+ 'sortable', scope: 'col', 'aria-sort': on ? ( filter.dir > 0 ? 'ascending' : 'descending' ) : 'none' },
			el( 'button', { type: 'button', class: 'th', dataset: { sort: key }, text: label+ ( on ? ( filter.dir > 0 ? ' ↑' : ' ↓' ) : '' ), on: { click: function() {
				if( filter.sort === key )
					filter.dir = -filter.dir;
				else {
					filter.sort = key;
					filter.dir = first;
				}
				again();
			} } } ) );
	};

	/**
	 *	The pages of a table: Zurück, where it is, Weiter; nothing for a single page
	 *
	 *	@param		{Object}	filter					page of the table
	 *	@param		{number}	pages
	 *	@param		{Function}	again				Draws the table again
	 *
	 *	@return		{Object|null}
	 */
	const pagerOf = function( filter, pages, again ) {

		if( pages === 1 )
			return null;

		return el( 'div', { class: 'pager' },
			el( 'button', { type: 'button', class: 'btn sm', id: 'prev', text: t( 'list.prev' ), disabled: filter.page === 1, on: { click: function() { filter.page--; again() } } } ),
			el( 'span', { class: 'state-note', text: t( 'list.page', filter.page, pages ) } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'next', text: t( 'list.next' ), disabled: filter.page === pages, on: { click: function() { filter.page++; again() } } } ) );
	};

	const belege = function( where, mine ) {

		const filter = state.filter;

		if( filter.year === 0 )
			filter.year = new Date().getFullYear();

		loading();

		api( 'crm-documents/list', { year : String( filter.year ) } ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			adopt( answer.body );
			state.docs = answer.body.documents;
			drawBelege();
		} );
	};

	const drawBelege = function() {

		const filter = state.filter;
		const rows = visible( state.docs, filter );
		const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );

		filter.page = Math.min( Math.max( 1, filter.page ), pages );

		const shown = rows.slice( ( filter.page - 1 ) * PAGE_SIZE, filter.page * PAGE_SIZE );
		const sum = rows.reduce( function( total, doc ) { return total+ doc.gross }, 0 );
		const years = state.years.slice();

		if( years.includes( filter.year ) === false )
			years.push( filter.year );

		years.sort( function( a, b ) { return b - a } );

		const heading = function( key, label, right ) { return sortHead( filter, key, label, right, redraw, key === 'date' ? -1 : 1 ) };

		const body = el( 'tbody', {}, shown.length === 0
			? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '8', class: 'muted', text: state.docs.length === 0 ? t( 'list.empty' ) : t( 'list.none' ) } ) )
			: shown.map( function( doc ) {

				const pdf = doc.state === 'draft' ? state.base+ '/crm/preview/'+ doc.id : pdfUrl( doc );

				return el( 'tr', { dataset: { id: doc.id }, on: { click: function() { wn.location.hash = hashOf( doc ) } } },
					el( 'td', { class: 'num' }, link( hashOf( doc ), docName( doc ) ) ),
					el( 'td', { text: t( 'category.'+ doc.category ) } ),
					el( 'td', { text: doc.client_title } ),
					el( 'td', { text: dmy( doc.date ) } ),
					el( 'td', { class: doc.overdue === true ? 'bad' : 'muted', text: dmy( doc.due_date || doc.valid_until ) } ),
					el( 'td', { class: 'r num' }, isPlain( doc ) ? el( 'span', { class: 'muted', text: '–' } ) : cash( doc.gross ) ),
					el( 'td', {}, badges( doc ) ),
					el( 'td', { class: 'r' }, el( 'a', { class: 'btn sm quiet', href: pdf, target: '_blank', rel: 'noopener', text: t( 'list.pdf' ), on: { click: function( event ) { event.stopPropagation() } } } ) ) );
			} ) );

		const table = el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
			el( 'thead', {}, el( 'tr', {}, heading( 'number', t( 'column.number' ) ), el( 'th', { scope: 'col', text: t( 'column.category' ) } ), heading( 'client', t( 'column.client' ) ), heading( 'date', t( 'column.date' ) ), heading( 'due', t( 'column.due' ) ), heading( 'gross', t( 'column.gross' ), true ), el( 'th', { scope: 'col', text: t( 'column.state' ) } ), el( 'th', { scope: 'col' } ) ) ),
			body,
			el( 'tfoot', {}, el( 'tr', {}, el( 'td', { colspan: '5', text: t( 'list.sum' ) } ), el( 'td', { class: 'r num', id: 'sum', text: cash( sum ) } ), el( 'td', { colspan: '2' } ) ) ) ) );

		const pager = pagerOf( filter, pages, redraw );

		const tabs = el( 'div', { class: 'tabs top', role: 'tablist' }, chips.map( function( chip ) {
			return el( 'button', { type: 'button', role: 'tab', 'aria-selected': filter.chip === chip.key ? 'true' : 'false', class: filter.chip === chip.key ? 'on' : '', dataset: { chip: chip.key }, on: { click: function() {
				filter.chip = chip.key;
				filter.page = 1;
				redraw();
			} } }, t( 'chip.'+ chip.key ), el( 'span', { class: 'n', text: String( state.docs.filter( chip.test ).length ) } ) );
		} ) );

		const year = el( 'select', { id: 'year', 'aria-label': t( 'list.year' ), value: String( filter.year ), on: { change: function( event ) {
			filter.year = Number( event.target.value );
			filter.page = 1;
			route();
		} } }, years.map( function( one ) { return el( 'option', { value: String( one ), text: String( one ) } ) } ) );

		reveal();

		state.main.appendChild( head( t( 'list.title' ), el( 'span', { class: 'sub', text: t( 'list.count', rows.length, state.docs.length ) } ), el( 'span', { class: 'grow' } ),
			search( t( 'search.documents' ), filter.q, { input: function( event ) {
				filter.q = event.target.value;
				filter.page = 1;
				redraw( true );
			} } ), newMenu( t( 'new.label' ) ) ) );
		state.main.appendChild( el( 'div', { class: 'toolbar' }, tabs, year ) );
		append( state.main, [ table, pager ] );
	};

	/**
	 *	Draw a list again with the search field kept as it is, the cursor in it
	 *
	 *	@param		{Function}	draw						Draws the list
	 *	@param		{boolean}	[typing]				The search is what changed
	 *
	 *	@return		{void}
	 */
	const keepSearch = function( draw, typing ) {

		const field = typing === true ? dc.getElementById( 'q' ) : null;
		const at = field === null ? 0 : field.selectionStart;

		draw();

		if( field !== null ) {
			const next = dc.getElementById( 'q' );
			next.focus();
			next.setSelectionRange( at, at );
		}
	};

	const redraw = function( typing ) { keepSearch( drawBelege, typing ) };


	// --- Beleg -------------------------------------------------------------------------------------------------------

	const beleg = function( where, mine ) {

		loading();

		api( 'crm-documents/get', { id : String( where.id ) } ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			adopt( answer.body );

			// A draft is edited, not looked at
			if( answer.body.document.state === 'draft' ) {
				wn.history.replaceState( null, '', '#entwurf-'+ where.id );
				editor( answer.body, mine );
				return;
			}

			state.tab = '';
			drawBeleg( answer.body );
		} );
	};

	/**
	 *	Whether the mail of a release reaches anybody: the client has an account that is on
	 *
	 *	@param		{Object[]}	accounts				The accounts of the client, as get has them
	 *
	 *	@return		{boolean}
	 */
	const reachable = function( accounts ) {
		return accounts.some( function( account ) { return account.active === true } );
	};

	/**
	 *	The primary action of a document by its state, or null. Versenden releases and mails; where the document is released already and the
	 *	client has no account to mail, what is left of sending is to say that it was sent
	 *
	 *	@param		{Object}	doc
	 *	@param		{Object[]}	[accounts]			The accounts of the client, as get has them
	 *
	 *	@return		{string|null}
	 */
	const primaryOf = function( doc, accounts ) {

		if( doc.state === 'open' && isPlain( doc ) === false && doc.released === true && reachable( accounts || [] ) === false )
			return 'sent';

		if( doc.state === 'open' && ( isPlain( doc ) === false || doc.released === false ) )
			return 'send';

		if( doc.state === 'sent' && doc.category === 'invoice' )
			return 'paid';

		if( doc.category === 'offer' && [ 'sent', 'accepted' ].includes( doc.state ) === true )
			return 'invoice';

		if( SIGNABLE.includes( doc.category ) === true && [ 'open', 'sent' ].includes( doc.state ) === true && doc.released === true && [ 'requested', 'viewed' ].includes( doc.sign_state ) === false )
			return 'sign';

		return null;
	};

	const tabsOf = function( doc ) {

		if( isPlain( doc ) === false )
			return [ 'lines', 'portal', 'notes', 'history' ];

		return SIGNABLE.includes( doc.category ) === true ? [ 'document', 'portal', 'sign', 'notes', 'history' ] : [ 'document', 'portal', 'notes', 'history' ];
	};

	/**
	 *	Post a step on a document and draw the answer: the document is read again with everything it brings
	 *
	 *	@param		{Object}	view						The answer of get, which the screen was drawn from
	 *	@param		{string}	action
	 *	@param		{Object}	data
	 *	@param		{string}	fallback
	 *	@param		{Function}	[say]						The toast for the answer
	 *
	 *	@return		{Promise}
	 */
	const move = function( view, action, data, fallback, say ) {

		const mine = state.view;

		return step( action, Object.assign( { id : String( view.document.id ) }, data ), fallback ).then( function( answer ) {

			if( answer.ok !== true )
				return answer;

			if( say !== undefined )
				toast( say( answer.body ) );

			return api( 'crm-documents/get', { id : String( view.document.id ) } ).then( function( again ) {

				if( state.view !== mine )
					return answer;

				if( again.ok === true )
					drawBeleg( again.body );
				else
					failed( again, 'error.load' );

				return answer;
			} );
		} );
	};

	/**
	 *	A draft the step made: its editor is opened
	 *
	 *	@param		{string}	action
	 *	@param		{Object}	view
	 *	@param		{string}	fallback
	 *	@param		{string}	said
	 *
	 *	@return		{Promise}
	 */
	const derive = function( action, view, fallback, said ) {

		return step( action, { id : String( view.document.id ) }, fallback ).then( function( answer ) {

			if( answer.ok === true ) {
				toast( t( said, docName( view.document ) ) );
				wn.location.hash = '#entwurf-'+ answer.body.document.id;
			}

			return answer;
		} );
	};

	const send = function( view ) {

		return move( view, 'crm-documents/release', { mail : true }, 'error.release', function( body ) {

			if( body.mailed > 0 )
				return t( 'send.done', body.mailed );

			return reachable( view.accounts ) === true ? t( 'release.mailed-none' ) : t( 'send.no-account' );
		} );
	};

	/**
	 *	Ask on which day a document was sent, then say so
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const markSent = function( view ) {

		dialog( { title : t( 'menu.mark-sent' ), text : t( 'sent.text' ), yes : t( 'sent.yes' ), fields : [ { name : 'date', label : t( 'sent.date' ), type : 'date', value : today() } ] } ).then( function( values ) {
			if( values !== null )
				move( view, 'crm-documents/state', { state : 'sent', date : values.date }, 'error.state', function() { return t( 'sent.done' ) } );
		} );
	};

	/**
	 *	Ask which account is asked for the signature, and for how long, then ask
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const askSignature = function( view ) {

		const accounts = view.accounts.filter( function( account ) { return account.active === true } );

		if( accounts.length === 0 ) {
			toast( t( 'sign.no-account' ), true );
			return;
		}

		dialog( { title : t( 'sign.ask-title' ), text : t( 'sign.ask-text' ), yes : t( 'sign.ask' ), fields : [
			{ name : 'account', label : t( 'sign.account' ), options : accounts.map( function( account ) { return { value : account.id, label : account.name !== '' ? account.name+ ' <'+ account.email+ '>' : account.email } } ), value : accounts[0].id },
			{ name : 'days', label : t( 'sign.days' ), type : 'number', value : String( view.sign_days ), min : '1', max : '90' },
		] } ).then( function( values ) {

			if( values === null )
				return;

			move( view, 'crm-documents/sign', { account : values.account, days : values.days }, 'error.sign', function( body ) { return body.mailed === true ? t( 'sign.asked' ) : t( 'sign.asked-unmailed' ) } );
		} );
	};

	const menuOf = function( view ) {

		const doc = view.document;
		const items = [
			{ label : t( 'menu.pdf' ), href : pdfUrl( doc ) },
			{ label : t( 'menu.download' ), href : pdfUrl( doc, true ) },
			'sep',
		];

		if( doc.state === 'open' )
			items.push( { label : t( 'menu.mark-sent' ), run : function() { markSent( view ) } } );

		if( doc.category === 'offer' && [ 'open', 'sent' ].includes( doc.state ) === true )
			items.push(
				{ label : t( 'menu.accept' ), run : function() { move( view, 'crm-documents/state', { state : 'accepted' }, 'error.state', function() { return t( 'accept.done' ) } ) } },
				{ label : t( 'menu.decline' ), run : function() { move( view, 'crm-documents/state', { state : 'declined' }, 'error.state', function() { return t( 'decline.done' ) } ) } } );

		if( doc.category === 'offer' && [ 'open', 'sent', 'accepted' ].includes( doc.state ) === true )
			items.push( { label : t( 'menu.invoice' ), run : function() { derive( 'crm-documents/from-offer', view, 'error.from-offer', 'invoice.done' ) } } );

		if( doc.category === 'offer' && doc.state !== 'draft' )
			items.push( { label : t( 'menu.contract' ), run : function() { wn.location.hash = '#neu-contract-'+ doc.id } } );

		if( doc.category === 'invoice' && [ 'open', 'sent', 'paid' ].includes( doc.state ) === true )
			items.push( { label : t( 'menu.credit' ), run : function() { derive( 'crm-documents/credit', view, 'error.credit', 'credit.done' ) } } );

		items.push( { label : t( 'menu.copy' ), run : function() { derive( 'crm-documents/copy', view, 'error.copy', 'copy.done' ) } } );

		if( doc.overdue === true && doc.released === true )
			items.push( { label : t( 'menu.remind' ), run : function() { remind( view ) } } );

		if( SIGNABLE.includes( doc.category ) === true && [ 'open', 'sent' ].includes( doc.state ) === true ) {

			items.push( 'sep' );

			if( [ 'requested', 'viewed', 'expired' ].includes( doc.sign_state ) === true )
				items.push( { label : t( 'menu.sign-remind' ), run : function() { signRemind( view ) } } );

			if( [ 'requested', 'viewed' ].includes( doc.sign_state ) === true )
				items.push( { label : t( 'menu.sign-cancel' ), run : function() { signCancel( view ) } } );
			else
				items.push( { label : t( 'menu.sign' ), run : function() { askSignature( view ) } } );
		}

		if( doc.released === true )
			items.push( 'sep', { label : t( 'menu.unrelease' ), run : function() { move( view, 'crm-documents/unrelease', {}, 'error.unrelease', function() { return t( 'release.undone' ) } ) } } );

		items.push( 'sep', { label : t( 'menu.notes' ), run : function() { state.tab = 'notes'; drawBeleg( view ) } } );

		return menu( { label : '⋯', items : items } );
	};

	const remind = function( view ) {

		return move( view, 'crm-documents/remind', {}, 'error.remind', function( body ) { return body.mailed > 0 ? t( 'remind.done', body.mailed ) : t( 'remind.none' ) } );
	};

	const signRemind = function( view ) {

		return move( view, 'crm-documents/sign-remind', {}, 'error.sign-remind', function() { return t( 'sign.reminded' ) } );
	};

	const signCancel = function( view ) {

		ask( { title : t( 'sign.cancel-title' ), text : t( 'sign.cancel-text' ), yes : t( 'sign.cancel-yes' ), danger : true } ).then( function( yes ) {
			if( yes === true )
				move( view, 'crm-documents/sign-cancel', {}, 'error.sign-cancel', function() { return t( 'sign.cancelled' ) } );
		} );
	};

	/**
	 *	The totals box of a finalised document: the sums the server stored, the VAT by rate from the lines
	 *
	 *	@param		{Object}	doc
	 *
	 *	@return		{Object}
	 */
	const totalsBox = function( doc ) {

		const regular = doc.vat_mode === 'regular';
		const totals = money().totals( doc.lines.map( function( line ) { return { qty : line.qty, price_net : line.price_net, vat_rate : line.vat_rate } } ), doc.vat_mode, doc.reverse_charge );
		const days = doc.due_date ? Math.round( ( new Date( doc.due_date+ 'T12:00:00' ) - new Date( doc.date+ 'T12:00:00' ) ) / 86400000 ) : null;

		return el( 'div', { class: 'card totals' },
			el( 'div', { class: 'row' }, el( 'span', { text: t( 'totals.net' ) } ), el( 'span', { class: 'num', text: cash( doc.net ) } ) ),
			regular && doc.reverse_charge === false ? totals.rates.map( function( entry ) {
				return el( 'div', { class: 'row' }, el( 'span', { text: t( 'totals.vat', money().formatRate( entry.rate ), cash( entry.net ) ) } ), el( 'span', { class: 'num', text: cash( entry.vat ) } ) );
			} ) : null,
			el( 'div', { class: 'row sum' }, el( 'span', { text: t( 'totals.gross' ) } ), el( 'span', { class: 'num', text: cash( doc.gross ) } ) ),
			el( 'p', { class: 'state-note', text: [ t( 'vat.'+ doc.vat_mode ), doc.reverse_charge === true ? t( 'vat.reverse' ) : '', days === null ? '' : t( 'totals.terms', days ) ].filter( function( part ) { return part !== '' } ).join( ' · ' ) } ) );
	};

	const linesTable = function( doc ) {

		const regular = doc.vat_mode === 'regular';

		return el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl static' },
			el( 'thead', {}, el( 'tr', {}, el( 'th', { text: '#' } ), el( 'th', { text: t( 'column.title' ) } ), el( 'th', { class: 'r', text: t( 'column.qty' ) } ), el( 'th', { text: t( 'column.unit' ) } ), el( 'th', { class: 'r', text: t( 'column.price' ) } ), regular ? el( 'th', { class: 'r', text: t( 'column.rate' ) } ) : null, el( 'th', { class: 'r', text: t( 'column.amount' ) } ) ) ),
			el( 'tbody', {}, doc.lines.map( function( line, index ) {
				return el( 'tr', {},
					el( 'td', { class: 'muted', text: String( index + 1 ) } ),
					el( 'td', { class: 'wrap' }, line.title, line.description !== '' ? el( 'div', { class: 'muted small pre', text: line.description } ) : null ),
					el( 'td', { class: 'r num', text: money().formatQty( line.qty ) } ),
					el( 'td', { text: line.unit } ),
					el( 'td', { class: 'r num', text: cash( line.price_net ) } ),
					regular ? el( 'td', { class: 'r num', text: doc.reverse_charge === true ? money().formatRate( 0 ) : money().formatRate( line.vat_rate ) } ) : null,
					el( 'td', { class: 'r num', text: cash( line.amount_net ) } ) );
			} ) ) ) );
	};

	const tabLines = function( view ) {
		return el( 'div', { class: 'docline' }, linesTable( view.document ), totalsBox( view.document ) );
	};

	/**
	 *	@param		{Object}	view						The answer of get
	 *
	 *	@return		{string}									The name of the template of the document; its key where the template is gone; '' for none
	 */
	const templateName = function( view ) {

		const template = view.templates.find( function( one ) { return one.key === view.document.template_key } );

		return template === undefined ? view.document.template_key : template.name;
	};

	const tabDocument = function( view ) {

		const doc = view.document;
		const template = view.templates.find( function( one ) { return one.key === doc.template_key } );
		const inputs = ( template === undefined ? [] : template.inputs ).filter( function( input ) { return ( doc.inputs[input.name] ?? '' ) !== '' } );

		const pairs = [
			[ t( 'document.template' ), templateName( view ) ],
			[ t( 'document.offer' ), doc.parent === null ? '' : link( '#beleg-'+ doc.parent.id, doc.parent.number || doc.parent.title ) ],
		].concat( inputs.map( function( input ) { return [ input.label, el( 'span', { class: 'pre', text: doc.inputs[input.name] } ) ] } ), [
			[ t( 'document.pdf' ), external( pdfUrl( doc ), docFile( doc ) ) ],
			[ t( 'document.hash' ), el( 'span', { class: 'num', text: doc.pdf_sha256.slice( 0, 16 ) } ) ],
		] );

		return el( 'div', { class: 'cols-3' }, card( t( 'document.title' ), kv( pairs ) ), SIGNABLE.includes( doc.category ) === true ? signCard( view ) : null );
	};

	/**
	 *	@param		{Object}	doc
	 *
	 *	@return		{string}									The name of the stored PDF as the person sees it
	 */
	const docFile = function( doc ) {
		return ( doc.number || doc.category+ '-'+ doc.id )+ '.pdf';
	};

	const signCard = function( view ) {

		const doc = view.document;
		const waiting = [ 'requested', 'viewed' ].includes( doc.sign_state );
		const actions = [];

		if( doc.state === 'signed' )
			actions.push( doc.sign_signed_url !== '' ? external( doc.sign_signed_url, t( 'sign.signed-pdf' ) ) : null, doc.sign_shown_url !== '' ? external( doc.sign_shown_url, t( 'sign.shown-pdf' ) ) : null );
		else if( [ 'open', 'sent' ].includes( doc.state ) === true ) {

			if( waiting === true || doc.sign_state === 'expired' )
				actions.push( el( 'button', { type: 'button', class: 'btn sm', id: 'sign-remind', text: t( 'sign.remind' ), on: { click: function() { signRemind( view ) } } } ) );

			if( waiting === true )
				actions.push( el( 'button', { type: 'button', class: 'btn sm danger', id: 'sign-cancel', text: t( 'sign.cancel' ), on: { click: function() { signCancel( view ) } } } ) );
			else
				actions.push( el( 'button', { type: 'button', class: 'btn sm', id: 'sign-ask', text: t( 'sign.ask' ), on: { click: function() { askSignature( view ) } } } ) );
		}

		return card( t( 'sign.heading' ),
			el( 'p', { class: 'state-note' }, doc.sign_state === '' ? t( 'sign.none' ) : [ badge( doc.sign_state === 'signed' ? 'signed' : 'portal', t( 'sign.state-'+ doc.sign_state ) ), doc.signed_at ? ' '+ t( 'sign.signed-on', dmy( doc.signed_at ) ) : '' ] ),
			el( 'div', { class: 'actions' }, actions ) );
	};

	const tabPortal = function( view ) {

		const doc = view.document;
		let mail = null;

		const portal = doc.released === true
			? [
				el( 'p', { class: 'state-note' }, badge( 'portal', t( 'mark.released' ) ), ' '+ t( 'release.on', dmy( doc.released_at ) ) ),
				el( 'div', { class: 'actions' },
					el( 'button', { type: 'button', class: 'btn sm', id: 'unrelease', text: t( 'menu.unrelease' ), on: { click: function() { move( view, 'crm-documents/unrelease', {}, 'error.unrelease', function() { return t( 'release.undone' ) } ) } } } ),
					el( 'button', { type: 'button', class: 'btn sm', id: 'mail-again', text: t( 'release.again' ), on: { click: function() { send( view ) } } } ) ),
			]
			: [
				el( 'p', { class: 'state-note', text: t( 'release.not' ) } ),
				el( 'label', { class: 'check' }, mail = el( 'input', { type: 'checkbox', id: 'release-mail', checked: true } ), ' '+ t( 'release.mail' ) ),
				el( 'div', { class: 'actions' }, el( 'button', { type: 'button', class: 'btn sm primary', id: 'release', text: t( 'release.do' ), on: { click: function() {
					move( view, 'crm-documents/release', { mail : mail.checked }, 'error.release', function( body ) {
						return body.mail === true ? ( body.mailed > 0 ? t( 'release.mailed', body.mailed ) : t( 'release.mailed-none' ) ) : t( 'release.silent' );
					} );
				} } } ) ),
			];

		let day = null;
		const other = doc.state === 'open'
			? [
				el( 'p', { class: 'state-note', text: t( 'other.text' ) } ),
				el( 'div', { class: 'actions' }, day = el( 'input', { type: 'date', id: 'sent-date', value: today(), 'aria-label': t( 'sent.date' ) } ),
					el( 'button', { type: 'button', class: 'btn sm', id: 'mark-sent', text: t( 'menu.mark-sent' ), on: { click: function() {
						move( view, 'crm-documents/state', { state : 'sent', date : day.value }, 'error.state', function() { return t( 'sent.done' ) } );
					} } } ) ),
			]
			: [ el( 'p', { class: 'state-note', text: doc.sent_at ? t( 'other.sent', stamp( doc.sent_at ) ) : t( 'other.none' ) } ) ];

		return el( 'div', { class: 'cols' }, card( t( 'portal.title' ), portal ), card( t( 'other.title' ), other ) );
	};

	const tabNotes = function( view ) {

		const area = el( 'textarea', { id: 'notes', rows: 5, class: 'area', 'aria-label': t( 'notes.title' ), value: view.document.notes } );

		return card( t( 'notes.title' ), area, el( 'div', { class: 'actions top' }, el( 'button', { type: 'button', class: 'btn sm', id: 'notes-save', text: t( 'notes.save' ), on: { click: function() {
			step( 'crm-documents/save', { id : String( view.document.id ), notes : area.value }, 'error.save' ).then( function( answer ) {
				if( answer.ok === true ) {
					view.document.notes = answer.body.document.notes;
					toast( t( 'notes.done' ) );
				}
			} );
		} } } ) ) );
	};

	const tabHistory = function( view ) {

		const doc = view.document;
		const entries = [
			[ doc.created, t( 'history.created' ) ],
			[ doc.finalised, doc.number !== '' ? t( 'history.finalised', doc.number ) : t( 'history.finalised-plain' ) ],
			[ doc.released_at, t( 'history.released' ) ],
			[ doc.sent_at, t( 'history.sent' ) ],
			[ doc.paid_at, t( 'history.paid' ) ],
			[ doc.signed_at, t( 'history.signed' ) ],
		].filter( function( entry ) { return typeof entry[0] === 'string' && entry[0] !== '' } );

		entries.sort( function( a, b ) { return a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0 } );

		return card( t( 'history.title' ), el( 'ul', { class: 'log list-mini' }, entries.map( function( entry ) {
			return el( 'li', {}, el( 'span', { class: 't', text: stamp( entry[0] ) } ), el( 'span', { text: entry[1] } ) );
		} ) ) );
	};

	const drawBeleg = function( view ) {

		const doc = view.document;
		const client = doc.client;
		const tabs = tabsOf( doc );
		const primary = primaryOf( doc, view.accounts );
		const start = state.tab !== '' && tabs.includes( state.tab ) === true ? state.tab : tabs[0];
		const mine = state.view;

		state.tab = start;

		const primaryButton = primary === null ? null : el( 'button', { type: 'button', class: 'btn primary', id: 'primary', dataset: { action: primary }, text: t( 'primary.'+ primary ), on: { click: function() {
			if( primary === 'send' )
				send( view );
			else if( primary === 'sent' )
				markSent( view );
			else if( primary === 'paid' )
				move( view, 'crm-documents/state', { state : 'paid' }, 'error.state', function() { return t( 'paid.done' ) } );
			else if( primary === 'invoice' )
				derive( 'crm-documents/from-offer', view, 'error.from-offer', 'invoice.done' );
			else
				askSignature( view );
		} } } );

		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#belege', t( 'list.title' ) ), ' › '+ t( 'category.'+ doc.category ) ) );
		state.main.appendChild( head( docName( doc ), stateBadge( doc ), marks( doc ),
			el( 'span', { class: 'sub' }, link( '#kunde-'+ doc.client_id, client.company || client.name ), ' · '+ dmy( doc.date )+ ( doc.due_date ? ' · '+ t( 'head.due', dmy( doc.due_date ) ) : doc.valid_until ? ' · '+ t( 'head.valid', dmy( doc.valid_until ) ) : '' ) ),
			el( 'span', { class: 'grow' } ), isPlain( doc ) ? null : el( 'span', { class: 'big num', id: 'gross', text: cash( doc.gross ) } ), primaryButton, menuOf( view ) ) );

		if( doc.overdue === true )
			state.main.appendChild( note( '', t( 'overdue.note', dmy( doc.due_date ) ), ' ', doc.released === true ? el( 'button', { type: 'button', class: 'btn sm', id: 'remind', text: t( 'menu.remind' ), on: { click: function() { remind( view ) } } } ) : t( 'overdue.release' ) ) );

		if( doc.expired === true )
			state.main.appendChild( note( '', t( 'expired.note', dmy( doc.valid_until ) ) ) );

		const address = [ client.street, ( client.zip+ ' '+ client.city ).trim(), client.country !== '' && client.country !== 'DE' ? client.country : '' ].filter( function( part ) { return part !== '' } );

		state.main.appendChild( el( 'div', { class: 'cols-3 gap' },
			card( t( 'block.client' ), kv( [
				[ client.company ? t( 'client.company' ) : t( 'client.name' ), el( 'span', {}, el( 'b', { text: client.company || client.name } ), client.company && client.name ? [ el( 'br' ), client.name ] : null ) ],
				[ t( 'client.address' ), address.length === 0 ? '' : el( 'span', {}, address.map( function( part, index ) { return [ index === 0 ? null : el( 'br' ), part ] } ) ) ],
				[ t( 'client.email' ), client.email ],
				[ t( 'client.vat' ), client.vat_id ],
				[ t( 'client.number' ), client.number ],
			] ) ),
			card( t( 'block.document' ), kv( [
				[ t( 'document.kind' ), t( 'category.'+ doc.category ) ],
				[ t( 'document.template' ), templateName( view ) ],
				[ t( 'document.date' ), dmy( doc.date ) ],
				[ t( 'document.due' ), dmy( doc.due_date ) ],
				[ t( 'document.valid' ), dmy( doc.valid_until ) ],
				[ t( 'document.period' ), doc.period_from && doc.period_to ? dmy( doc.period_from )+ ' – '+ dmy( doc.period_to ) : '' ],
				[ t( 'document.from' ), doc.parent === null ? '' : link( '#beleg-'+ doc.parent.id, doc.parent.number || doc.parent.title ) ],
				[ t( 'document.children' ), doc.children.length === 0 ? '' : el( 'span', {}, doc.children.map( function( child, index ) { return [ index === 0 ? null : ', ', link( hashOf( child ), docName( child ) ) ] } ) ) ],
				[ t( 'document.pdf' ), doc.pdf_path === '' ? '' : external( pdfUrl( doc ), docFile( doc ) ) ],
			] ) ) ) );

		const body = el( 'div', { id: 'tab' } );
		const strip = el( 'div', { class: 'tabs', role: 'tablist' }, tabs.map( function( key ) {
			return el( 'button', { type: 'button', role: 'tab', 'aria-selected': key === start ? 'true' : 'false', class: key === start ? 'on' : '', dataset: { tab: key }, text: t( 'tab.'+ key ), on: { click: function() {
				if( state.view !== mine )
					return;
				state.tab = key;
				drawBeleg( view );
			} } } );
		} ) );

		state.main.appendChild( strip );
		state.main.appendChild( body );
		body.appendChild( { lines : tabLines, document : tabDocument, portal : tabPortal, sign : signCard, notes : tabNotes, history : tabHistory }[start]( view ) );
	};


	// --- Entwurf -----------------------------------------------------------------------------------------------------

	/**
	 *	The editor of a draft; for a new one the data it needs comes with a list
	 *
	 *	@param		{Object}	where						id, or category (and offer) for a new one
	 *	@param		{number}	mine
	 *
	 *	@return		{void}
	 */
	const entwurf = function( where, mine ) {

		loading();

		if( where.id !== undefined ) {

			api( 'crm-documents/get', { id : String( where.id ) } ).then( function( answer ) {

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					loadFailed( answer, 'error.load', function() { route() } );
					return;
				}

				adopt( answer.body );

				if( answer.body.document.state !== 'draft' ) {
					wn.history.replaceState( null, '', '#beleg-'+ where.id );
					state.tab = '';
					drawBeleg( answer.body );
					return;
				}

				editor( answer.body, mine );
			} );

			return;
		}

		Promise.all( [ api( 'crm-documents/list', { year : String( new Date().getFullYear() ) } ), api( 'crm-documents/templates', { category : where.category } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			const failure = answers.find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			adopt( answers[0].body );

			const draft = draftNew( where.category, state.settings, where.offer );

			if( where.client !== '' && state.clients.some( function( one ) { return String( one.id ) === where.client && one.active === true } ) === true )
				draft.client_id = where.client;

			draft.templates = answers[1].body.templates;
			state.offers = answers[1].body.offers;
			editor( { document : null, draft : draft }, mine );
		} );
	};

	/**
	 *	Draw the editor. A document that is a draft comes as the answer of get; a new draft as { document: null, draft }
	 *
	 *	@param		{Object}	view
	 *	@param		{number}	mine
	 *
	 *	@return		{void}
	 */
	const editor = function( view, mine ) {

		const draft = view.document === null ? view.draft : draftOf( view.document );
		const fields = {};
		const plain = isPlain( draft );
		const regular = draft.vat_mode === 'regular';

		if( view.document !== null )
			draft.templates = view.templates;

		state.draft = draft;
		reveal();

		// --- what the person types goes into the draft; a field that matters for a sum draws the sums again
		const touch = function() { draft.dirty = true };
		const mark = function( name, on ) {
			if( fields[name] !== undefined ) {
				fields[name].classList.toggle( 'err', on );
				if( on === true )
					fields[name].setAttribute( 'aria-invalid', 'true' );
				else
					fields[name].removeAttribute( 'aria-invalid' );
			}
		};

		const bind = function( name, key, node ) {
			fields[name] = node;
			node.addEventListener( 'input', function() {
				draft[key] = node.value;
				touch();
				mark( name, false );
			} );
			watch( node );
			return node;
		};

		// --- the head
		const gross = el( 'span', { class: 'big num', id: 'gross' } );
		const savedAt = el( 'span', { class: 'sub', id: 'saved' } );
		const message = el( 'div', { id: 'message' } );

		const heading = el( 'h1' );
		const showTitle = function() { heading.textContent = draft.id === 0 ? t( 'editor.new', t( 'category.'+ draft.category ) ) : t( 'editor.title', t( 'category.'+ draft.category ) ) };
		const showSaved = function() { showTitle(); savedAt.textContent = draft.id === 0 ? t( 'editor.unsaved' ) : t( 'editor.saved', hm( draft.updated ) ) };

		const refuse = function( answer, fallback ) {

			const sentence = failed( answer, fallback );
			const field = answer.body !== null && typeof answer.body === 'object' && typeof answer.body.field === 'string' ? answer.body.field : '';

			clear( message );
			message.appendChild( note( 'bad', sentence ) );

			if( field !== '' && fields[field] !== undefined ) {
				mark( field, true );
				fields[field].focus();
			}
		};

		/**
		 *	Save the draft: made on the first save, changed on the others. The answer is the document; the address follows the id
		 *
		 *	@param		{boolean}	quiet						A save the person did not ask for says nothing of a refusal
		 *
		 *	@return		{Promise}									true when the draft is stored as it stands
		 */
		const save = function( quiet ) {

			wn.clearTimeout( state.pending );
			state.pending = null;

			if( state.saving === true ) {
				state.queued = true;
				return Promise.resolve( false );
			}

			state.saving = true;

			return api( 'crm-documents/save', payloadOf( draft ) ).then( function( answer ) {

				state.saving = false;

				if( state.view !== mine )
					return false;

				if( answer.ok !== true ) {

					if( quiet !== true )
						refuse( answer, 'error.save' );

					return false;
				}

				const saved = answer.body.document;

				clear( message );
				Object.keys( fields ).forEach( function( name ) { mark( name, false ) } );

				if( draft.id === 0 ) {
					draft.id = saved.id;
					wn.history.replaceState( null, '', '#entwurf-'+ saved.id );
				}

				draft.updated = saved.updated;
				draft.dirty = false;
				showSaved();
				refresh();

				if( state.queued === true ) {
					state.queued = false;
					return save( quiet );
				}

				return true;
			} );
		};

		/**
		 *	The draft changed: store it when it is whole enough to be stored - not at once, but when nothing has changed for a moment, so that a row of
		 *	changes is one save. A field that is left stores it at once
		 *
		 *	@param		{boolean}	[now]						true where a field was left
		 *
		 *	@return		{void}
		 */
		const autosave = function( now ) {

			wn.clearTimeout( state.pending );
			state.pending = null;

			if( draft.dirty !== true || ready( draft ) !== true )
				return;

			if( now === true ) {
				save( true );
				return;
			}

			state.pending = wn.setTimeout( function() {
				state.pending = null;

				if( state.view === mine )
					autosave( true );
			}, AUTOSAVE_MS );
		};

		/**
		 *	A control of the draft: a change stores it after a moment, leaving it at once
		 *
		 *	@param		{Object}	node
		 *
		 *	@return		{void}
		 */
		const watch = function( node ) {
			node.addEventListener( 'change', function() { autosave() } );
			node.addEventListener( 'blur', function() { autosave( true ) } );
		};

		/**
		 *	Save what is unsaved before a step that needs the draft as it stands
		 *
		 *	@return		{Promise}									true when the draft is stored
		 */
		const stored = function() {
			return draft.id !== 0 && draft.dirty === false ? Promise.resolve( true ) : save( false );
		};

		const finalise = function() {

			stored().then( function( yes ) {

				if( yes !== true )
					return null;

				return ask( { title : t( 'finalise.title' ), text : plain ? t( 'finalise.text-plain' ) : t( 'finalise.text' ), yes : t( 'finalise.yes' ) } );
			} ).then( function( yes ) {

				if( yes !== true )
					return null;

				return step( 'crm-documents/finalise', { id : String( draft.id ) }, 'error.finalise' ).then( function( answer ) {

					if( answer.ok !== true ) {
						refuse( answer, 'error.finalise' );
						return;
					}

					const made = answer.body.document;

					toast( made.number !== '' ? t( 'finalise.done', made.number ) : t( 'finalise.done-plain' ) );
					draft.dirty = false;
					wn.location.hash = '#beleg-'+ made.id;
				} );
			} );
		};

		const remove = function() {

			if( draft.id === 0 ) {
				wn.location.hash = '#belege';
				return;
			}

			ask( { title : t( 'delete.title' ), text : t( 'delete.text' ), yes : t( 'delete.yes' ), danger : true } ).then( function( yes ) {

				if( yes !== true )
					return;

				step( 'crm-documents/remove', { id : String( draft.id ) }, 'error.remove' ).then( function( answer ) {

					if( answer.ok === true ) {
						toast( t( 'delete.done' ) );
						draft.dirty = false;
						wn.location.hash = '#belege';
					}
				} );
			} );
		};

		const preview = function() {

			stored().then( function( yes ) {
				if( yes === true )
					wn.open( state.base+ '/crm/preview/'+ draft.id, '_blank', 'noopener' );
			} );
		};

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#belege', t( 'list.title' ) ), ' › '+ t( 'category.'+ draft.category )+ ' · '+ t( 'state.draft' ) ) );
		state.main.appendChild( head( heading, badge( 'draft', t( 'state.draft' ) ), savedAt, el( 'span', { class: 'grow' } ), plain ? null : gross,
			el( 'button', { type: 'button', class: 'btn', id: 'save', text: t( 'editor.save' ), on: { click: function() { save( false ) } } } ),
			el( 'button', { type: 'button', class: 'btn primary', id: 'finalise', text: t( 'editor.finalise' ), on: { click: finalise } } ),
			menu( { label : '⋯', items : [ { label : t( 'editor.preview' ), run : preview }, 'sep', { label : t( 'editor.delete' ), danger : true, run : remove } ] } ) ) );
		state.main.appendChild( message );

		// --- the block Beleg
		const clientOptions = state.clients.filter( function( one ) { return one.active === true || String( one.id ) === draft.client_id } );
		const clientSelect = el( 'select', { id: 'f-client_id', value: draft.client_id }, el( 'option', { value: '', text: t( 'field.client-choose' ) } ), clientOptions.map( function( one ) { return el( 'option', { value: String( one.id ), text: one.number+ ' · '+ one.title } ) } ) );
		const offerSelect = el( 'select', { id: 'f-parent_id', value: draft.parent_id }, el( 'option', { value: '', text: t( 'field.offer-choose' ) } ), state.offers.map( function( one ) { return el( 'option', { value: String( one.id ), text: one.number+ ' · '+ one.client_title } ) } ) );
		const templateSelect = el( 'select', { id: 'f-template_key', value: draft.template_key }, el( 'option', { value: '', text: t( 'field.template-choose' ) } ), draft.templates.map( function( one ) { return el( 'option', { value: one.key, text: one.name } ) } ) );
		const inputsBox = el( 'div', { class: 'inputs' } );
		const clientBox = el( 'div', { class: 'card pad', id: 'client-box' } );

		const drawInputs = function() {

			clear( inputsBox );

			const template = draft.templates.find( function( one ) { return one.key === draft.template_key } );

			( template === undefined ? [] : template.inputs ).forEach( function( input ) {

				const name = 'inputs.'+ input.name;
				const control = input.multiline === true ? el( 'textarea', { id: 'f-'+ name, rows: 4, class: 'area', value: draft.inputs[input.name] ?? '' } ) : el( 'input', { id: 'f-'+ name, type: 'text', value: draft.inputs[input.name] ?? '' } );

				fields[name] = control;
				control.addEventListener( 'input', function() {
					draft.inputs[input.name] = control.value;
					touch();
					mark( name, false );
				} );
				watch( control );
				inputsBox.appendChild( el( 'label', { class: 'field' }, el( 'span', { text: input.label+ ( input.required === true ? ' *' : '' ) } ), control ) );
			} );
		};

		bind( 'client_id', 'client_id', clientSelect );
		bind( 'parent_id', 'parent_id', offerSelect );
		bind( 'template_key', 'template_key', templateSelect );
		templateSelect.addEventListener( 'change', drawInputs );

		const drawClient = function() {

			clear( clientBox );

			const chosen = draft.category === 'contract'
				? state.offers.find( function( one ) { return String( one.id ) === draft.parent_id } )
				: null;
			const id = chosen === undefined || chosen === null ? draft.client_id : String( chosen.client_id );
			const known = state.clients.find( function( one ) { return String( one.id ) === id } );
			const owed = el( 'span', { id: 'client-open', text: '…' } );
			const where = el( 'span', { id: 'client-address', text: '…' } );
			const who = el( 'span', { id: 'client-accounts', text: '…' } );

			clientBox.appendChild( el( 'h3', { text: t( 'block.client' ) } ) );

			if( known === undefined ) {
				clientBox.appendChild( el( 'p', { class: 'state-note', text: t( 'client.none' ) } ) );
				return;
			}

			clientBox.appendChild( kv( [
				[ t( 'client.name' ), known.title ],
				[ t( 'client.number' ), known.number ],
				[ t( 'client.address' ), where ],
				[ t( 'client.accounts' ), who ],
				[ t( 'client.open' ), owed ],
			] ) );

			// The address and the accounts are the client's own: asked for when the client is chosen, and in no list
			api( 'crm-clients/get', { id : id } ).then( function( answer ) {

				if( answer.ok !== true || state.view !== mine || where.parentNode === null )
					return;

				const card = answer.body.client;
				const lines = [ card.street, ( card.zip+ ' '+ card.city ).trim(), card.country !== '' && card.country !== 'DE' ? card.country : '' ].filter( function( part ) { return part !== '' } );
				const accounts = answer.body.accounts.filter( function( account ) { return account.gone === false } );

				clear( where );
				append( where, lines.length === 0 ? [ '–' ] : lines.map( function( part, index ) { return [ index === 0 ? null : el( 'br' ), part ] } ) );
				clear( who );
				append( who, accounts.length === 0
					? [ t( 'client.no-account' ), ' ', link( '#kunde-'+ id+ '-einladen', t( 'client.invite' ) ) ]
					: [ accounts.map( function( account ) { return account.name !== '' ? account.name : account.email } ).join( ', ' ), ' ', link( '#kunde-'+ id, t( 'client.accounts-link' ) ) ] );
			} );

			api( 'crm-documents/list', { client : id } ).then( function( answer ) {

				if( answer.ok === true && state.view === mine && owed.parentNode !== null )
					owed.textContent = cash( answer.body.documents.filter( function( doc ) { return doc.category === 'invoice' && [ 'open', 'sent' ].includes( doc.state ) } ).reduce( function( total, doc ) { return total+ doc.gross }, 0 ) );
			} );
		};

		clientSelect.addEventListener( 'change', drawClient );
		offerSelect.addEventListener( 'change', drawClient );

		const rows = [
			[ t( 'document.kind' ), t( 'category.'+ draft.category ) ],
			draft.category === 'contract' ? [ t( 'field.offer' ), el( 'span', {}, offerSelect, el( 'span', { class: 'state-note', text: t( 'field.offer-follow' ) } ) ) ] : [ t( 'field.client' ), clientSelect ],
		];

		const dl = el( 'dl', { class: 'kv' }, rows.map( function( row ) { return [ el( 'dt', { text: row[0] } ), el( 'dd', {}, row[1] ) ] } ) );

		dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-date', text: t( 'field.date' ) } ) ) );
		dl.appendChild( el( 'dd', {}, bind( 'date', 'date', el( 'input', { id: 'f-date', type: 'date', value: draft.date } ) ) ) );

		if( plain === true ) {
			dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-title', text: t( 'field.title' ) } ) ) );
			dl.appendChild( el( 'dd', {}, bind( 'title', 'title', el( 'input', { id: 'f-title', type: 'text', value: draft.title, maxlength: '120' } ) ) ) );
		}
		else {
			if( draft.category === 'invoice' ) {
				dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-due_date', text: t( 'field.due' ) } ) ) );
				dl.appendChild( el( 'dd', {}, bind( 'due_date', 'due_date', el( 'input', { id: 'f-due_date', type: 'date', value: draft.due_date } ) ) ) );
			}

			if( draft.category === 'offer' ) {
				dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-valid_until', text: t( 'field.valid' ) } ) ) );
				dl.appendChild( el( 'dd', {}, bind( 'valid_until', 'valid_until', el( 'input', { id: 'f-valid_until', type: 'date', value: draft.valid_until } ) ) ) );
			}

			dl.appendChild( el( 'dt', { text: t( 'field.period' ) } ) );
			dl.appendChild( el( 'dd', { class: 'pair' },
				bind( 'period_from', 'period_from', el( 'input', { id: 'f-period_from', type: 'date', value: draft.period_from, 'aria-label': t( 'field.period-from' ) } ) ),
				bind( 'period_to', 'period_to', el( 'input', { id: 'f-period_to', type: 'date', value: draft.period_to, 'aria-label': t( 'field.period-to' ) } ) ) ) );
		}

		dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-template_key', text: t( 'field.template' ) } ) ) );
		dl.appendChild( el( 'dd', {}, templateSelect ) );

		const reverse = plain === false && regular === true
			? el( 'label', { class: 'check' }, el( 'input', { type: 'checkbox', id: 'f-reverse_charge', checked: draft.reverse_charge, on: { change: function( event ) {
				draft.reverse_charge = event.target.checked;
				touch();
				drawLines();
				autosave();
			} } } ), ' '+ t( 'field.reverse' ) )
			: null;

		state.main.appendChild( el( 'div', { class: 'cols-3' }, el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'block.document' ) } ), dl, inputsBox, reverse ), clientBox ) );

		drawInputs();
		drawClient();
		showSaved();

		// --- the lines
		const lineBox = el( 'div', { class: 'docline gap', id: 'lines' } );
		let totalsNode = null;
		let adder = null;

		const refreshSums = function() {

			const totals = totalsOf( draft );

			gross.textContent = cash( totals.gross );
			lineBox.querySelectorAll( '.amount' ).forEach( function( cell, index ) { cell.textContent = cash( totals.amounts[index] ) } );

			const box = totalsNode;

			if( box === null )
				return;

			clear( box );
			box.appendChild( el( 'div', { class: 'row' }, el( 'span', { text: t( 'totals.net' ) } ), el( 'span', { class: 'num', text: cash( totals.net ) } ) ) );

			if( regular === true && draft.reverse_charge === false )
				totals.rates.forEach( function( entry ) {
					box.appendChild( el( 'div', { class: 'row' }, el( 'span', { text: t( 'totals.vat', money().formatRate( entry.rate ), cash( entry.net ) ) } ), el( 'span', { class: 'num', text: cash( entry.vat ) } ) ) );
				} );

			box.appendChild( el( 'div', { class: 'row sum' }, el( 'span', { text: t( 'totals.gross' ) } ), el( 'span', { class: 'num', text: cash( totals.gross ) } ) ) );
			box.appendChild( el( 'p', { class: 'state-note', text: draft.reverse_charge === true && regular === true ? t( 'vat.regular' )+ ' · '+ t( 'vat.reverse' ) : t( 'vat.'+ draft.vat_mode ) } ) );
		};

		const lineField = function( index, key, props, cls ) {

			const name = 'lines.'+ index+ '.'+ key;
			const line = draft.lines[index];
			const control = key === 'description'
				? el( 'textarea', Object.assign( { rows: Math.min( 4, Math.max( 1, line[key].split( '\n' ).length ) ), class: 'desc', value: line[key] }, props ) )
				: el( 'input', Object.assign( { type: 'text', class: cls || '', value: line[key] }, props ) );

			fields[name] = control;
			control.addEventListener( 'input', function() {
				line[key] = control.value;
				touch();
				mark( name, false );

				if( key === 'qty' || key === 'price' )
					control.classList.toggle( 'err', key === 'qty' ? money().parseQty( control.value.trim() ) === null : money().parse( control.value.trim() ) === null );

				refreshSums();
			} );
			watch( control );

			return control;
		};

		const drawLines = function() {

			clear( lineBox );

			Object.keys( fields ).filter( function( name ) { return name.indexOf( 'lines.' ) === 0 } ).forEach( function( name ) { delete fields[name] } );

			const rates = state.settings.rates;
			const table = el( 'table', { class: 'tbl ed' },
				el( 'thead', {}, el( 'tr', {}, el( 'th', { text: '#' } ), el( 'th', { class: 'wide', text: t( 'column.title' ) } ), el( 'th', { class: 'r qty', text: t( 'column.qty' ) } ), el( 'th', { class: 'unit', text: t( 'column.unit' ) } ), el( 'th', { class: 'r price', text: t( 'column.price' ) } ), regular ? el( 'th', { class: 'r rate', text: t( 'column.rate' ) } ) : null, el( 'th', { class: 'r amount-head', text: t( 'column.amount' ) } ), el( 'th', {} ) ) ),
				el( 'tbody', {}, draft.lines.map( function( line, index ) {

					const rate = regular === true
						? el( 'select', { 'aria-label': t( 'column.rate' ), value: String( draft.reverse_charge === true ? 0 : line.vat_rate ), disabled: draft.reverse_charge === true, on: { change: function( event ) {
							line.vat_rate = Number( event.target.value );
							touch();
							refreshSums();
							autosave();
						} } }, ( rates.includes( line.vat_rate ) ? rates : rates.concat( [ line.vat_rate ] ) ).map( function( one ) { return el( 'option', { value: String( one ), text: money().formatRate( one ) } ) } ) )
						: null;

					if( rate !== null )
						fields['lines.'+ index+ '.vat_rate'] = rate;

					return el( 'tr', { class: 'plain' },
						el( 'td', { class: 'muted', text: String( index + 1 ) } ),
						el( 'td', {}, lineField( index, 'title', { 'aria-label': t( 'column.title' ) } ), lineField( index, 'description', { placeholder: t( 'line.description' ), 'aria-label': t( 'column.description' ) } ) ),
						el( 'td', {}, lineField( index, 'qty', { 'aria-label': t( 'column.qty' ) }, 'r' ) ),
						el( 'td', {}, lineField( index, 'unit', { 'aria-label': t( 'column.unit' ) } ) ),
						el( 'td', {}, lineField( index, 'price', { 'aria-label': t( 'column.price' ) }, 'r' ) ),
						rate === null ? null : el( 'td', {}, rate ),
						el( 'td', { class: 'r num amount', text: '' } ),
						el( 'td', {}, el( 'button', { type: 'button', class: 'del', title: t( 'line.remove' ), 'aria-label': t( 'line.remove' ), text: '×', dataset: { del: index }, on: { click: function() {
							draft.lines.splice( index, 1 );
							touch();
							drawLines();
							autosave();
						} } } ) ) );
				} ),
				el( 'tr', { class: 'plain' }, el( 'td' ), el( 'td', { colspan: regular ? '7' : '6' }, picker() ) ) ) );

			totalsNode = el( 'div', { class: 'card totals', id: 'totals' } );
			lineBox.appendChild( el( 'div', { class: 'card wrap-x' }, table ) );
			lineBox.appendChild( totalsNode );
			refreshSums();
		};

		/**
		 *	The row that finds an item of the catalogue and inserts it as a line
		 *
		 *	@return		{Object}
		 */
		const picker = function() {

			const input = el( 'input', { id: 'additem', type: 'text', list: 'items', placeholder: t( 'line.find' ), autocomplete: 'off', 'aria-label': t( 'line.find' ) } );

			adder = input;

			/**
			 *	Insert a line: the item of the catalogue the field names; with free true - the Enter - a title no item carries is a line of its own,
			 *	one piece, empty unit and price, and the cursor goes to its price
			 *
			 *	@param		{boolean}	free
			 *
			 *	@return		{void}
			 */
			const insert = function( free ) {

				const typed = input.value.trim();
				const wanted = typed.toLowerCase();
				const item = wanted === '' ? undefined : state.items.find( function( one ) { return one.title.toLowerCase() === wanted } );

				if( item === undefined && ( free !== true || typed === '' ) )
					return;

				// The field is empty from here on: the change a browser reports after the Enter finds nothing to insert
				input.value = '';

				const rates = state.settings.rates;
				const line = lineOf( undefined, regular === true ? ( item !== undefined && rates.includes( item.vat_rate ) ? item.vat_rate : ( rates[0] ?? 0 ) ) : 0 );

				line.title = item === undefined ? typed : item.title;

				if( item !== undefined ) {
					line.description = item.description;
					line.unit = item.unit;
					line.price = money().format( item.price_net );
				}

				draft.lines.push( line );
				touch();
				drawLines();

				if( item === undefined )
					fields['lines.'+ ( draft.lines.length - 1 )+ '.price'].focus();
				else
					adder.focus();

				autosave();
			};

			input.addEventListener( 'change', function() { insert( false ) } );
			input.addEventListener( 'keydown', function( event ) {
				if( event.key === 'Enter' ) {
					event.preventDefault();
					insert( true );
				}
			} );

			return el( 'span', { class: 'picker' }, input,
				el( 'datalist', { id: 'items' }, state.items.map( function( one ) { return el( 'option', { value: one.title, text: one.unit+ ' · '+ cash( one.price_net ) } ) } ) ),
				el( 'button', { type: 'button', class: 'btn sm', id: 'addline', text: t( 'line.add' ), on: { click: function() {
					draft.lines.push( lineOf( undefined, regular === true ? ( state.settings.rates[0] ?? 0 ) : 0 ) );
					touch();
					drawLines();
					fields['lines.'+ ( draft.lines.length - 1 )+ '.title'].focus();
				} } } ) );
		};

		if( plain === false ) {
			state.main.appendChild( lineBox );
			drawLines();
		}
	};


	// --- forms -------------------------------------------------------------------------------------------------------

	/**
	 *	Post an action, and say the refusal in a toast: the shape of a step that changes nothing the rail counts
	 *
	 *	@param		{string}	action
	 *	@param		{Object}	data
	 *	@param		{string}	fallback				The word for what failed
	 *
	 *	@return		{Promise}									The answer; its ok says whether to go on
	 */
	const call = function( action, data, fallback ) {

		return api( action, data ).then( function( answer ) {

			if( answer.ok !== true )
				failed( answer, fallback );

			return answer;
		} );
	};

	/**
	 *	A form of a screen: rows of a label, a control and the place where a refusal is said beside the control. What is typed marks the
	 *	form as changed, which the guard of the page asks before it lets go of the screen
	 *
	 *	@param		{Array}		rows						[ { name, label, control, hint } ]
	 *	@param		{Object}	form						Gets controls (name => control) and notes (name => the element of the refusal)
	 *
	 *	@return		{Object}									The definition list
	 */
	const formList = function( rows, form ) {

		return el( 'dl', { class: 'kv' }, rows.map( function( row ) {

			const note = el( 'span', { class: 'state-note bad', role: 'alert' } );
			const clear = function() {
				row.control.classList.remove( 'err' );
				row.control.removeAttribute( 'aria-invalid' );
				note.textContent = '';
				form.dirty = true;
			};

			form.controls[row.name] = row.control;
			form.notes[row.name] = note;
			row.control.addEventListener( 'input', clear );
			row.control.addEventListener( 'change', clear );

			return [ el( 'dt', {}, el( 'label', { for : row.control.id, text : row.label } ) ), el( 'dd', {}, row.control, row.hint === undefined ? null : el( 'span', { class: 'state-note', text: row.hint } ), note ) ];
		} ) );
	};

	/**
	 *	A form that the guard of the page knows: a fresh one holds nothing unsaved
	 *
	 *	@return		{Object}
	 */
	const formOf = function() {

		const form = { controls : {}, notes : {}, dirty : false, form : true };

		state.draft = form;

		return form;
	};

	/**
	 *	@param		{Object}	form
	 *
	 *	@return		{Object}									name => the text of each control
	 */
	const valuesOfForm = function( form ) {

		const values = {};

		Object.keys( form.controls ).forEach( function( name ) { values[name] = form.controls[name].value } );

		return values;
	};

	/**
	 *	A refusal of the server beside the field it names: the sentence in the toast and under the control, the control marked and focused
	 *
	 *	@param		{Object}	form
	 *	@param		{Object}	answer
	 *	@param		{string}	fallback
	 *
	 *	@return		{string}									The sentence
	 */
	const refuseAt = function( form, answer, fallback ) {

		const sentence = failed( answer, fallback );
		const field = answer.body !== null && typeof answer.body === 'object' && typeof answer.body.field === 'string' ? answer.body.field : '';

		if( field !== '' && form.controls[field] !== undefined ) {
			form.controls[field].classList.add( 'err' );
			form.controls[field].setAttribute( 'aria-invalid', 'true' );
			form.notes[field].textContent = sentence;
			form.controls[field].focus();
		}

		return sentence;
	};

	/**
	 *	@param		{string}	name
	 *	@param		{string}	value
	 *
	 *	@return		{Object}									The text field of a client's property
	 */
	const clientControl = function( name, value, id ) {

		const limit = name === 'country' ? '2' : '160';

		return el( 'input', { id : id || 'f-'+ name, type : name === 'email' ? 'email' : 'text', value : value, maxlength : limit, autocomplete : 'off', inputmode : name === 'phone' ? 'tel' : null, list : name === 'country' ? 'countries' : null } );
	};

	/**
	 *	@param		{Object}	client
	 *
	 *	@return		{Array}										The lines of its address: street, zip and city, the country where it is not Germany
	 */
	const addressOf = function( client ) {
		return [ client.street, ( client.zip+ ' '+ client.city ).trim(), client.country !== '' && client.country !== 'DE' ? client.country : '' ].filter( function( part ) { return part !== '' } );
	};

	/**
	 *	@param		{Array}		lines
	 *
	 *	@return		{Object|string}						The lines one under the other; '' for none, which a definition list leaves out
	 */
	const stacked = function( lines ) {
		return lines.length === 0 ? '' : el( 'span', {}, lines.map( function( part, index ) { return [ index === 0 ? null : el( 'br' ), part ] } ) );
	};


	// --- Kunden ------------------------------------------------------------------------------------------------------

	/**
	 *	@param		{Object}	client
	 *
	 *	@return		{string}									Its company, its name where it has none
	 */
	const clientTitle = function( client ) {
		return client.company !== '' ? client.company : client.name;
	};

	const clientKeys = {
		number : function( client ) { return client.number },
		company : function( client ) { return client.company.toLowerCase() },
		person : function( client ) { return client.name.toLowerCase() },
		city : function( client ) { return client.city.toLowerCase() },
		accounts : function( client ) { return client.active_accounts },
		open : function( client ) { return client.open },
		active : function( client ) { return client.active === true ? 1 : 0 },
	};

	/**
	 *	The rows the list of the clients shows: the search over number, company, name, mail and city, then the order (a tie goes by the id)
	 *
	 *	@param		{Array}		clients					With open, the cents an invoice of each still asks
	 *	@param		{Object}	filter					q, sort, dir
	 *
	 *	@return		{Array}
	 */
	const visibleClients = function( clients, filter ) {

		const query = filter.q.trim().toLowerCase();
		const key = clientKeys[filter.sort] || clientKeys.number;

		const rows = clients.filter( function( client ) {
			return query === '' || ( client.number+ ' '+ client.company+ ' '+ client.name+ ' '+ client.email+ ' '+ client.city ).toLowerCase().includes( query );
		} );

		rows.sort( function( a, b ) {

			const left = key( a );
			const right = key( b );
			const order = typeof left === 'string' ? left.localeCompare( right, 'de' ) : left - right;

			return order !== 0 ? order * filter.dir : a.id - b.id;
		} );

		return rows;
	};

	const clientBadge = function( client ) {
		return client.active === true ? badge( 'paid', t( 'clients.active' ) ) : badge( 'draft', t( 'clients.inactive' ) );
	};

	const kunden = function( where, mine ) {

		loading();

		Promise.all( [ api( 'crm-clients/list' ), api( 'crm-documents/list', { category : 'invoice' } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			if( answers[0].ok !== true ) {
				loadFailed( answers[0], 'error.load', function() { route() } );
				return;
			}

			// What an invoice still asks of each client is the books' answer: where they do not come, the column says nothing instead of nought
			const owed = {};

			if( answers[1].ok === true ) {
				adopt( answers[1].body );
				answers[1].body.documents.forEach( function( doc ) {
					if( doc.category === 'invoice' && [ 'open', 'sent' ].includes( doc.state ) === true )
						owed[doc.client_id] = ( owed[doc.client_id] || 0 ) + doc.gross;
				} );
			}

			state.people = answers[0].body.clients.map( function( client ) { return Object.assign( {}, client, { open : owed[client.id] || 0 } ) } );
			state.owed = answers[1].ok === true ? owed : null;
			drawKunden();
		} );
	};

	const drawKunden = function() {

		const filter = state.cfilter;
		const rows = visibleClients( state.people, filter );
		const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );

		filter.page = Math.min( Math.max( 1, filter.page ), pages );

		const shown = rows.slice( ( filter.page - 1 ) * PAGE_SIZE, filter.page * PAGE_SIZE );
		const again = function() { drawKunden() };
		const heading = function( key, label, right ) { return sortHead( filter, key, label, right, again, key === 'open' ? -1 : 1 ) };

		const body = el( 'tbody', {}, shown.length === 0
			? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '7', class: 'muted', text: state.people.length === 0 ? t( 'clients.empty' ) : t( 'clients.none' ) } ) )
			: shown.map( function( client ) {
				return el( 'tr', { dataset: { id: client.id }, on: { click: function() { wn.location.hash = '#kunde-'+ client.id } } },
					el( 'td', { class: 'num' }, link( '#kunde-'+ client.id, client.number ) ),
					el( 'td', {}, client.company === '' ? el( 'span', { class: 'muted', text: '–' } ) : client.company ),
					el( 'td', { text: client.name } ),
					el( 'td', {}, client.city, client.country !== 'DE' ? el( 'span', { class: 'muted', text: ' ('+ client.country+ ')' } ) : null ),
					el( 'td', {}, client.accounts === 0 ? el( 'span', { class: 'muted', text: t( 'clients.accounts-none' ) } ) : client.active_accounts+ ' / '+ client.accounts ),
					el( 'td', { class: 'r num' }, state.owed !== null && client.open > 0 ? cash( client.open ) : el( 'span', { class: 'muted', text: '–' } ) ),
					el( 'td', {}, clientBadge( client ) ) );
			} ) );

		const table = el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
			el( 'thead', {}, el( 'tr', {}, heading( 'number', t( 'column.number' ) ), heading( 'company', t( 'column.company' ) ), heading( 'person', t( 'column.person' ) ), heading( 'city', t( 'column.city' ) ), heading( 'accounts', t( 'column.accounts' ) ), heading( 'open', t( 'column.open' ), true ), heading( 'active', t( 'column.state' ) ) ) ),
			body ) );

		reveal();

		state.main.appendChild( head( t( 'clients.title' ), el( 'span', { class: 'sub', text: t( 'clients.count', rows.length, state.people.length ) } ), el( 'span', { class: 'grow' } ),
			search( t( 'clients.search' ), filter.q, { input: function( event ) {
				filter.q = event.target.value;
				filter.page = 1;
				keepSearch( drawKunden, true );
			} } ), link( '#kunde-neu', t( 'clients.new' ), { class: 'btn primary', id: 'new-client' } ) ) );
		append( state.main, [ table, pagerOf( filter, pages, again ) ] );
	};


	// --- Kundenkarte -------------------------------------------------------------------------------------------------

	const kunde = function( where, mine ) {

		loading();

		Promise.all( [ api( 'crm-clients/get', { id : String( where.id ) } ), api( 'crm-documents/list', { client : String( where.id ) } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			const failure = answers.find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			adopt( answers[1].body );

			// The link "einladen" of the Übersicht leads to the invitation: the tab Portal, the form open
			state.tab = where.tab === 'einladen' ? 'portal' : where.tab;

			drawKunde( { client : answers[0].body.client, accounts : answers[0].body.accounts, documents : answers[1].body.documents, portal : null, editing : false, inviting : where.tab === 'einladen', invite : null, box : null, master : null } );
		} );
	};

	/**
	 *	The card's Stammdaten: the facts of the client, or - after Stammdaten bearbeiten - the form that changes them
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const masterCard = function( view ) {

		const client = view.client;

		if( view.editing === false )
			return el( 'div', { class: 'card pad', id: 'master' }, el( 'h3', { text: t( 'card.master' ) } ), kv( [
				[ t( 'client.company' ), client.company ],
				[ t( 'client.name' ), client.name ],
				[ t( 'client.email' ), client.email ],
				[ t( 'card.phone' ), client.phone ],
				[ t( 'client.address' ), stacked( addressOf( client ) ) ],
				[ t( 'client.vat' ), client.vat_id ],
				[ t( 'card.since' ), dmy( client.created ) ],
			] ) );

		const form = formOf();
		const rows = CLIENT_FIELDS.map( function( name ) { return { name : name, label : t( 'clientfield.'+ name ), control : clientControl( name, client[name] ) } } );
		const save = el( 'button', { type: 'submit', class: 'btn sm primary', id: 'client-save', text: t( 'form.save' ) } );

		return el( 'form', { class: 'card pad', id: 'master', novalidate: 'novalidate', on: { submit: function( event ) {

			event.preventDefault();
			save.disabled = true;

			api( 'crm-clients/save', clientPayload( client, valuesOfForm( form ) ) ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== view.mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'error.client-save' );
					return;
				}

				view.client = answer.body.client;
				view.editing = false;
				toast( t( 'card.saved' ) );
				drawKunde( view );
			} );
		} } },
		el( 'h3', { text: t( 'card.master' ) } ), formList( rows, form ),
		el( 'div', { class: 'actions top' }, save, el( 'button', { type: 'button', class: 'btn sm', id: 'client-cancel', text: t( 'form.cancel' ), on: { click: function() {
			view.editing = false;
			state.draft = null;
			view.master.replaceWith( view.master = masterCard( view ) );
		} } } ) ),
		el( 'datalist', { id: 'countries' }, [ 'DE', 'AT', 'CH' ].map( function( code ) { return el( 'option', { value: code } ) } ) ) );
	};

	/**
	 *	What a save of a client posts: every field of it, since the model takes a field that is not posted for empty - the notes among them
	 *
	 *	@param		{Object}	client
	 *	@param		{Object}	[over]					Fields that take the place of the client's
	 *
	 *	@return		{Object}
	 */
	const clientPayload = function( client, over ) {

		const data = { id : String( client.id ) };

		CLIENT_FIELDS.concat( [ 'notes' ] ).forEach( function( name ) { data[name] = client[name] } );

		return Object.assign( data, over || {} );
	};

	/**
	 *	The accounts of the client: each a row with its state and the menu of what can be done with it, the buttons that invite and link, and
	 *	the sentence that stands in place of all of it where Members is off or the client is
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const accountsCard = function( view ) {

		const client = view.client;
		const box = el( 'div', { class: 'card pad accounts', id: 'accounts' }, el( 'h3', { text: t( 'card.accounts' ) } ) );
		const members = link( state.links.members, t( 'acc.members-link' ), { target : '_blank', rel : 'noopener' } );

		// Without the feature Members nothing is known of the accounts: one sentence instead of rows that would all read "deleted"
		if( view.accounts.some( function( account ) { return account.state === 'members-off' } ) === true ) {
			box.appendChild( el( 'p', { class: 'state-note', text: t( 'acc.members-off' ) } ) );
			return box;
		}

		if( view.accounts.length === 0 )
			box.appendChild( el( 'p', { class: 'state-note', text: t( 'acc.none' ) } ) );
		else
			view.accounts.forEach( function( account ) { box.appendChild( accountRow( view, account ) ) } );

		// A client that is off takes no account, no link and no switch-on: the card says so once and does not offer them
		if( client.active === false ) {
			box.appendChild( el( 'p', { class: 'state-note', text: t( 'acc.inactive' ) } ) );
			box.appendChild( el( 'p', { class: 'state-note' }, members ) );
			return box;
		}

		box.appendChild( el( 'div', { class: 'actions top' },
			el( 'button', { type: 'button', class: 'btn sm primary', id: 'invite-open', text: t( 'acc.invite' ), on: { click: function() { view.inviting = true; view.invite = null; replaceAccounts( view ) } } } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'link-open', text: t( 'acc.link' ), on: { click: function() { askLink( view ) } } } ) ) );

		// The form stays the one that was opened while it is open, so that what was typed in it survives a step on another account
		if( view.inviting === true )
			box.appendChild( view.invite = view.invite ?? inviteForm( view ) );

		box.appendChild( el( 'p', { class: 'state-note' }, members ) );

		return box;
	};

	const replaceAccounts = function( view ) {

		if( view.box !== null && view.box.parentNode !== null && view.mine === state.view )
			view.box.replaceWith( view.box = accountsCard( view ) );
	};

	/**
	 *	@param		{Object}	account
	 *
	 *	@return		{string}									The name it goes by: its name, its address where it has no name; a row that is gone the last four characters of its id
	 */
	const whoIs = function( account ) {

		if( account.gone === true )
			return t( 'acc.gone', account.id.slice( -4 ) );

		return account.name !== '' ? account.name : account.email;
	};

	const accountRow = function( view, account ) {

		const client = view.client;
		const gone = account.gone === true;
		const post = function() { return { id : String( client.id ), account : account.id } };
		const swap = function( change ) {
			view.accounts = change( view.accounts );
			replaceAccounts( view );
		};
		const items = [];

		if( gone === false ) {

			if( account.active === true && client.active === true )
				items.push( { label : t( account.login === null ? 'acc.resend-invite' : 'acc.resend-login' ), run : function() {
					call( 'crm-clients/account-resend', post(), 'error.account-resend' ).then( function( answer ) {
						if( answer.ok === true )
							toast( t( answer.body.kind === 'invite' ? 'acc.resent-invite' : 'acc.resent-login' ) );
					} );
				} } );

			items.push( { label : t( 'acc.rename' ), run : function() {
				dialog( { title : t( 'acc.rename' ), text : t( 'acc.rename-text' ), yes : t( 'acc.rename-yes' ), fields : [ { name : 'name', label : t( 'acc.rename-field' ), value : account.name } ] } ).then( function( values ) {

					if( values === null )
						return;

					call( 'crm-clients/account-name', Object.assign( post(), { name : values.name } ), 'error.account-name' ).then( function( answer ) {
						if( answer.ok === true ) {
							swap( function( list ) { return list.map( function( each ) { return each.id === account.id ? answer.body.account : each } ) } );
							toast( t( 'acc.renamed' ) );
						}
					} );
				} );
			} } );

			if( account.active === true || client.active === true )
				items.push( { label : t( account.active === true ? 'acc.switch-off' : 'acc.switch-on' ), run : function() {
					call( 'crm-clients/account-active', Object.assign( post(), { active : account.active === false } ), 'error.account-active' ).then( function( answer ) {
						if( answer.ok === true ) {
							swap( function( list ) { return list.map( function( each ) { return each.id === account.id ? answer.body.account : each } ) } );
							toast( t( account.active === true ? 'acc.switched-off' : 'acc.switched-on' ) );
						}
					} );
				} } );

			items.push( 'sep' );
		}

		// A row whose account is gone from Members is only a row: it says so, and only the row goes
		items.push( { label : t( gone === true ? 'acc.remove-row' : 'acc.delete' ), danger : true, run : function() {
			ask( { title : t( gone === true ? 'acc.remove-row' : 'acc.delete-title' ), text : gone === true ? t( 'acc.remove-row-text' ) : t( 'acc.delete-text', whoIs( account ) ), yes : t( gone === true ? 'acc.remove-row' : 'acc.delete' ), danger : true } ).then( function( yes ) {

				if( yes !== true )
					return;

				call( 'crm-clients/account-remove', post(), 'error.account-remove' ).then( function( answer ) {
					if( answer.ok === true ) {
						swap( function( list ) { return list.filter( function( each ) { return each.id !== account.id } ) } );
						toast( t( gone === true ? 'acc.row-removed' : 'acc.deleted' ) );
					}
				} );
			} );
		} } );

		const login = gone === true ? '' : dmy( account.login );
		const kind = { signed : 'paid', invited : 'open', off : 'draft', gone : 'draft' }[account.state];

		return el( 'div', { class: 'acc', dataset: { id: account.id } },
			el( 'div', { class: 'who' }, el( 'b', { text: whoIs( account ) } ), ' ', badge( kind, t( 'acc.state-'+ account.state ) ),
				gone === true ? null : el( 'div', { text: ( account.name !== '' ? account.email+ ' · ' : '' )+ ( login === '' ? t( 'acc.never' ) : t( 'acc.last', login ) ) } ) ),
			menu( { label : '⋯', button : 'sm', aria : t( 'acc.menu' ), items : items } ) );
	};

	/**
	 *	A form that is sent or closed holds nothing unsaved any more: it stops being the page's guard
	 *
	 *	@param		{Object}	form
	 *
	 *	@return		void
	 */
	const dropGuard = function( form ) {

		if( state.draft === form )
			state.draft = null;
	};

	/**
	 *	The form that invites an account: an address and a name, in the card of the accounts
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const inviteForm = function( view ) {

		const form = { controls : {}, notes : {}, dirty : false };
		const rows = [
			{ name : 'email', label : t( 'invite.email' ), control : el( 'input', { id : 'f-invite-email', type : 'email', maxlength : '160', autocomplete : 'off' } ) },
			{ name : 'name', label : t( 'invite.name' ), control : el( 'input', { id : 'f-invite-name', type : 'text', maxlength : '120', autocomplete : 'off' } ) },
		];
		const send = el( 'button', { type: 'submit', class: 'btn sm primary', id: 'invite-send', text: t( 'invite.send' ) } );

		const box = el( 'form', { id : 'invite', novalidate : 'novalidate', on : { submit : function( event ) {

			event.preventDefault();
			send.disabled = true;

			api( 'crm-clients/invite', { id : String( view.client.id ), email : form.controls.email.value, name : form.controls.name.value } ).then( function( answer ) {

				send.disabled = false;

				if( state.view !== view.mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'error.account-invite' );
					return;
				}

				view.inviting = false;
				view.invite = null;
				dropGuard( form );
				view.accounts = view.accounts.concat( [ answer.body.account ] );
				replaceAccounts( view );
				toast( t( answer.body.mailed === true ? 'invite.sent' : 'invite.not-mailed' ), answer.body.mailed !== true );
			} );
		} } }, formList( rows, form ), el( 'div', { class: 'actions top' }, send,
			el( 'button', { type: 'button', class: 'btn sm', id: 'invite-cancel', text: t( 'form.cancel' ), on: { click: function() { view.inviting = false; view.invite = null; dropGuard( form ); replaceAccounts( view ) } } } ) ) );

		// What is typed in it is unsaved input like that of any form: the guard of the page asks before the screen is left - unless a form that holds changes is the guard's already
		form.form = true;
		Object.keys( form.controls ).forEach( function( name ) {
			form.controls[name].addEventListener( 'input', function() {
				if( state.draft === null || state.draft.dirty !== true )
					state.draft = form;
			} );
		} );

		// Opened from the Übersicht the cursor is in the form already
		wn.setTimeout( function() { if( form.controls.email.parentNode !== null && state.view === view.mine ) form.controls.email.focus() }, 0 );

		return box;
	};

	/**
	 *	Ask which account of Members that no client holds is linked to the client, then link it
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const askLink = function( view ) {

		call( 'crm-clients/unlinked', {}, 'error.unlinked' ).then( function( answer ) {

			if( answer.ok !== true )
				return;

			if( answer.body.accounts.length === 0 ) {
				toast( t( 'link.none' ) );
				return;
			}

			dialog( { title : t( 'link.title' ), text : t( 'link.text' ), yes : t( 'link.yes' ), fields : [
				{ name : 'account', label : t( 'link.field' ), options : answer.body.accounts.map( function( account ) { return { value : account.id, label : account.name !== '' ? account.name+ ' · '+ account.email : account.email } } ), value : answer.body.accounts[0].id },
			] } ).then( function( values ) {

				if( values === null )
					return;

				call( 'crm-clients/account-link', { id : String( view.client.id ), account : values.account }, 'error.account-link' ).then( function( linked ) {
					if( linked.ok === true && state.view === view.mine ) {
						view.accounts = view.accounts.concat( [ linked.body.account ] );
						replaceAccounts( view );
						toast( t( 'link.done' ) );
					}
				} );
			} );
		} );
	};

	/**
	 *	The tabs of the card: the documents of the client, what it sees in the portal and the notes
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const tabClientDocuments = function( view ) {

		return el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
			el( 'thead', {}, el( 'tr', {}, el( 'th', { text: t( 'column.number' ) } ), el( 'th', { text: t( 'column.category' ) } ), el( 'th', { text: t( 'column.date' ) } ), el( 'th', { class: 'r', text: t( 'column.gross' ) } ), el( 'th', { text: t( 'column.state' ) } ) ) ),
			el( 'tbody', {}, view.documents.length === 0
				? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '5', class: 'muted', text: t( 'cdocs.empty' ) } ) )
				: view.documents.map( function( doc ) {
					return el( 'tr', { dataset: { id: doc.id }, on: { click: function() { wn.location.hash = hashOf( doc ) } } },
						el( 'td', { class: 'num' }, link( hashOf( doc ), docName( doc ) ) ),
						el( 'td', { text: t( 'category.'+ doc.category ) } ),
						el( 'td', { text: dmy( doc.date ) } ),
						el( 'td', { class: 'r num' }, isPlain( doc ) ? el( 'span', { class: 'muted', text: '–' } ) : cash( doc.gross ) ),
						el( 'td', {}, badges( doc ) ) );
				} ) ) ) );
	};

	const tabClientPortal = function( view ) {

		const holder = el( 'div' );
		const body = el( 'div', { class: 'card pad', id: 'client-portal' }, el( 'h3', { text: t( 'cportal.title' ) } ), holder );
		const mine = state.view;
		const draw = function( portal ) {

			const plural = function( count ) { return count === 1 ? t( 'cportal.file-one', count ) : t( 'cportal.file-many', count ) };

			append( holder, [
				el( 'h4', { text: t( 'cportal.documents' ) } ),
				el( 'ul', { class: 'list-mini' }, portal.documents.length === 0 ? el( 'li', { class: 'muted', text: t( 'cportal.documents-none' ) } ) : portal.documents.map( function( doc ) {
					return el( 'li', {}, el( 'span', {}, link( '#beleg-'+ doc.id, doc.title !== '' ? doc.title : doc.number ), ' ', el( 'span', { class: 'muted', text: t( 'category.'+ doc.category ) } ) ), el( 'span', { class: 'r', text: dmy( doc.date ) } ) );
				} ) ),
				el( 'h4', { text: t( 'cportal.files' ) } ),
				portal.features.files === false
					? el( 'p', { class: 'state-note', text: t( 'cportal.files-off' ) } )
					: el( 'ul', { class: 'list-mini' }, portal.folders.length === 0 ? el( 'li', { class: 'muted', text: t( 'cportal.files-none' ) } ) : portal.folders.map( function( folder ) {
						return el( 'li', {}, el( 'span', { text: t( 'cportal.folder', folder.name, plural( folder.files ), t( folder.upload === true ? 'cportal.upload-yes' : 'cportal.upload-no' ) ) } ) );
					} ) ),
				portal.features.review === true ? el( 'p', { class: 'state-note', text: portal.review.media === 0 ? t( 'cportal.review-none' ) : t( 'cportal.review', portal.review.media, portal.review.comments ) } ) : null,
				el( 'p', { class: 'state-note', text: t( 'cportal.rest' ) } ),
			] );
		};

		if( view.portal !== null )
			draw( view.portal );
		else {
			holder.appendChild( el( 'p', { class: 'state-note', role: 'status', text: t( 'loading' ) } ) );

			call( 'crm-clients/portal', { id : String( view.client.id ) }, 'error.client-portal' ).then( function( answer ) {

				if( state.view !== mine || answer.ok !== true )
					return;

				view.portal = answer.body;
				clear( holder );
				draw( view.portal );
			} );
		}

		return body;
	};

	const tabClientNotes = function( view ) {

		const area = el( 'textarea', { id: 'notes', rows: 5, maxlength: '2000', class: 'area', 'aria-label': t( 'cnotes.title' ), value: view.client.notes } );

		return card( t( 'cnotes.title' ), area, el( 'div', { class: 'actions top' }, el( 'button', { type: 'button', class: 'btn sm', id: 'notes-save', text: t( 'notes.save' ), on: { click: function() {
			call( 'crm-clients/save', clientPayload( view.client, { notes : area.value } ), 'error.client-save' ).then( function( answer ) {
				if( answer.ok === true ) {
					view.client = answer.body.client;
					toast( t( 'notes.done' ) );
				}
			} );
		} } } ) ) );
	};

	/**
	 *	Switch the client on or off: the accounts of a client that goes off go off with it, and the answer says how many
	 *
	 *	@param		{Object}	view
	 *	@param		{boolean}	active
	 *
	 *	@return		{Promise}
	 */
	const switchClient = function( view, active ) {

		return step( 'crm-clients/active', { id : String( view.client.id ), active : active }, 'error.client-active' ).then( function( answer ) {

			if( answer.ok !== true || state.view !== view.mine )
				return answer;

			view.client = answer.body.client;
			view.accounts = answer.body.accounts;
			view.inviting = false;
			view.invite = null;
			view.editing = false;
			toast( active === true ? t( 'card.activated' ) : t( 'card.deactivated', answer.body.switched ) );
			drawKunde( view );

			return answer;
		} );
	};

	const drawKunde = function( view ) {

		const client = view.client;
		const tabs = CLIENT_TABS;
		const start = tabs.includes( state.tab ) === true ? state.tab : tabs[0];

		view.mine = state.view;
		state.tab = start;
		state.draft = null;
		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#kunden', t( 'clients.title' ) ), ' › '+ client.number ) );
		state.main.appendChild( head( el( 'h1', { text: clientTitle( client ) } ), clientBadge( client ), el( 'span', { class: 'sub num', text: client.number } ), el( 'span', { class: 'grow' } ),
			client.active === true ? newMenu( t( 'card.document-new' ), client.id ) : null,
			menu( { label : '⋯', items : [
				{ label : t( 'card.edit' ), run : function() {
					if( view.editing === true )
						return;
					view.editing = true;
					view.master.replaceWith( view.master = masterCard( view ) );
				} },
				'sep',
				client.active === true ? { label : t( 'card.deactivate' ), danger : true, run : function() { switchClient( view, false ) } } : { label : t( 'card.activate' ), run : function() { switchClient( view, true ) } },
			] } ) ) );

		if( client.active === false )
			state.main.appendChild( note( '', t( 'card.inactive-note' ) ) );

		view.master = masterCard( view );
		view.box = accountsCard( view );
		state.main.appendChild( el( 'div', { class: 'cols-3 gap' }, view.master, view.box ) );

		const body = el( 'div', { id: 'tab' } );
		const buttons = [];

		// A tab is the body under the strip: the card above it - a form that is open in it, an invitation half typed - is not drawn again
		const show = function( key ) {

			state.tab = key;
			buttons.forEach( function( button ) {
				button.setAttribute( 'aria-selected', button.dataset.tab === key ? 'true' : 'false' );
				button.classList.toggle( 'on', button.dataset.tab === key );
			} );
			clear( body );
			body.appendChild( { belege : tabClientDocuments, portal : tabClientPortal, notizen : tabClientNotes }[key]( view ) );
		};

		const strip = el( 'div', { class: 'tabs', role: 'tablist' }, tabs.map( function( key ) {

			const button = el( 'button', { type: 'button', role: 'tab', 'aria-selected': key === start ? 'true' : 'false', class: key === start ? 'on' : '', dataset: { tab: key }, on: { click: function() {
				if( state.view === view.mine )
					show( key );
			} } }, t( 'ctab.'+ key ), key === 'belege' ? el( 'span', { class: 'n', text: String( view.documents.length ) } ) : null );

			buttons.push( button );

			return button;
		} ) );

		state.main.appendChild( strip );
		state.main.appendChild( body );
		show( start );
	};


	// --- Neuer Kunde -------------------------------------------------------------------------------------------------

	const kundeNeu = function( where, mine ) {

		const form = formOf();
		const rows = CLIENT_FIELDS.map( function( name ) { return { name : name, label : t( 'clientfield.'+ name ), control : clientControl( name, name === 'country' ? 'DE' : '' ), hint : [ 'company', 'phone', 'vat_id' ].includes( name ) ? t( 'form.optional' ) : undefined } } );
		const first = [
			{ name : 'account_email', label : t( 'clientfield.account_email' ), control : el( 'input', { id : 'f-account_email', type : 'email', maxlength : '160', autocomplete : 'off' } ) },
			{ name : 'account_name', label : t( 'clientfield.account_name' ), control : el( 'input', { id : 'f-account_name', type : 'text', maxlength : '120', autocomplete : 'off', placeholder : t( 'form.optional' ) } ) },
		];
		const create = el( 'button', { type: 'submit', class: 'btn primary', id: 'client-create', text: t( 'newclient.create' ) } );
		const master = formList( rows, form );
		const account = formList( first, form );

		// With an address the account is made with the client and invited: the button says so
		form.controls.account_email.addEventListener( 'input', function() {
			create.textContent = form.controls.account_email.value.trim() === '' ? t( 'newclient.create' ) : t( 'newclient.create-invite' );
		} );

		const submit = function( event ) {

			event.preventDefault();
			create.disabled = true;

			api( 'crm-clients/save', valuesOfForm( form ) ).then( function( answer ) {

				create.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'error.client-save' );
					return;
				}

				const made = answer.body.client;
				const asked = answer.body.first;

				form.dirty = false;
				state.draft = null;

				if( asked === undefined )
					toast( t( 'newclient.done', made.number ) );
				else if( asked.refused !== null )
					toast( t( 'newclient.refused', made.number, errorText( { status : 400, body : asked.refused }, 'error.account-invite' ) ), true );
				else
					toast( t( asked.mailed === true ? 'newclient.done-invited' : 'newclient.done-unmailed', made.number ), asked.mailed !== true );

				refresh();
				wn.location.hash = '#kunde-'+ made.id;
			} );
		};

		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#kunden', t( 'clients.title' ) ), ' › '+ t( 'newclient.title' ).toLowerCase() ) );
		state.main.appendChild( el( 'form', { id: 'new-client-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'newclient.title' ), el( 'span', { class: 'grow' } ), link( '#kunden', t( 'form.cancel' ), { class: 'btn', id: 'client-abort' } ), create ),
			el( 'div', { class: 'cols' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'newclient.master' ) } ), master ),
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'newclient.first' ) } ), el( 'p', { class: 'state-note', text: t( 'newclient.first-text' ) } ), account ) ),
			el( 'datalist', { id: 'countries' }, [ 'DE', 'AT', 'CH' ].map( function( code ) { return el( 'option', { value: code } ) } ) ) ) );

		form.controls.company.focus();
	};


	// --- Positionen --------------------------------------------------------------------------------------------------

	/**
	 *	@param		{string}	locale					de_DE
	 *
	 *	@return		{string}									de
	 */
	const languageOf = function( locale ) {
		return String( locale ).split( '_' )[0];
	};

	/**
	 *	@param		{Object}	item						An item with its words per language
	 *	@param		{Array}		locales					The languages of the site
	 *
	 *	@return		{string}									The unit in each language that has one, once each: Std. / h
	 */
	const units = function( item, locales ) {

		const seen = [];

		locales.forEach( function( locale ) {

			const unit = ( item.words[locale] || { unit : '' } ).unit;

			if( unit !== '' && seen.includes( unit ) === false )
				seen.push( unit );
		} );

		return seen.join( ' / ' );
	};

	const positionen = function( where, mine ) {

		loading();

		api( 'crm-documents/positionen' ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			adopt( answer.body );

			const body = answer.body;
			const small = state.settings.vat_mode === 'small_business';
			reveal();

			state.main.appendChild( head( t( 'items.title' ), el( 'span', { class: 'sub', text: t( 'items.sub' ) } ), el( 'span', { class: 'grow' } ), link( state.links.items, t( 'items.edit' ), { class: 'btn primary', id: 'items-edit', target : '_blank', rel : 'noopener' } ) ) );

			if( body.missing === true ) {
				state.main.appendChild( note( '', t( 'items.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) ) );
				return;
			}

			const words = function( item, locale ) {
				const one = item.words[locale] || { title : '', description : '', unit : '' };
				return [ one.title, one.description !== '' ? el( 'div', { class: 'muted small' }, one.description.split( '\n' ).join( ' · ' ) ) : null ];
			};

			state.main.appendChild( el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl static', id: 'items' },
				el( 'thead', {}, el( 'tr', {}, body.locales.map( function( locale ) { return el( 'th', { text: t( 'items.title-in', languageOf( locale ) ) } ) } ),
					el( 'th', { text: t( 'items.unit' ) } ), el( 'th', { class: 'r', text: t( 'items.net' ) } ), el( 'th', { class: 'r', text: t( 'items.vat' ) } ), el( 'th', { text: t( 'items.active' ) } ) ) ),
				el( 'tbody', {}, body.items.length === 0
					? el( 'tr', { class: 'plain' }, el( 'td', { colspan: String( body.locales.length + 4 ), class: 'muted', text: t( 'items.empty' ) } ) )
					: body.items.map( function( item ) {
						return el( 'tr', { dataset: { id: item.id } },
							body.locales.map( function( locale ) { return el( 'td', { class: 'wrap' }, words( item, locale ) ) } ),
							el( 'td', { text: units( item, body.locales ) } ),
							el( 'td', { class: 'r num', text: cash( item.price ) } ),
							el( 'td', { class: 'r num', text: small ? '–' : money().formatRate( item.vat ) } ),
							el( 'td', { text: t( item.active === true ? 'items.yes' : 'items.no' ) } ) );
					} ) ) ) ) );
			state.main.appendChild( el( 'p', { class: 'state-note top', text: t( 'items.note' ) } ) );
		} );
	};


	// --- Einrichtung -------------------------------------------------------------------------------------------------

	/**
	 *	The checklist of the first run: a row for each thing the status names, its word, whether it is there and - where it is not - the sentence
	 *	that says what is missing; the one button runs crm/setup and the screen is drawn again from the status it brought about. A whole product
	 *	says "Fertig." with the way on to the Übersicht
	 *
	 *	@param		{Array}		items						The items of the status
	 *	@param		{string}	said						What the setup said, kept over the screen that is drawn again
	 *	@param		{number}	mine						The view that draws it
	 *
	 *	@return		{void}
	 */
	const drawEinrichtung = function( items, said, mine ) {

		const whole = items.every( complete );

		const run = el( 'button', { type: 'button', class: 'btn primary', id: 'einrichtung-run', hidden: whole, text: t( 'einrichtung.run' ), on: { click: function() {

			run.disabled = true;

			call( 'crm/setup', {}, 'error.setup' ).then( function( answer ) {

				run.disabled = false;

				if( state.view !== mine )
					return;

				// A run that was refused went part of the way: the rows show what it did, and the refusal stays in the toast
				call( 'crm/status', {}, 'error.load' ).then( function( again ) {
					if( again.ok === true && state.view === mine )
						drawEinrichtung( again.body.items, answer.ok === true ? setupSentence( answer.body ) : '', mine );
				} );
			} );
		} } } );

		reveal();
		state.main.appendChild( head( t( 'einrichtung.title' ) ) );
		state.main.appendChild( el( 'div', { class: 'card pad' },
			el( 'p', { class: 'state-note', text: t( 'einrichtung.intro' ) } ),
			el( 'h3', { text: t( 'einrichtung.rows' ) } ),
			el( 'ul', { class: 'list-mini', id: 'einrichtung-rows' }, items.map( function( item ) {

				const word = 'einrichtung.row-'+ item.key;

				return el( 'li', { 'data-key': item.key, 'data-state': complete( item ) ? 'ok' : 'todo' },
					el( 'span', {}, el( 'b', { text: typeof state.texts[word] === 'string' ? t( word ) : item.key } ), item.state === 'ok' ? null : el( 'span', { class: 'state-note block', text: statusLine( item ) } ) ),
					badge( complete( item ) ? 'signed' : 'portal', t( complete( item ) ? 'einrichtung.state-ok' : 'einrichtung.state-todo' ) ) );
			} ) ),
			el( 'div', { class: 'actions top' }, run ),
			whole ? el( 'p', { class: 'state-note', id: 'einrichtung-done' }, el( 'b', { text: t( 'einrichtung.done' ) } ), ' ', link( '#uebersicht', t( 'einrichtung.next' ) ) ) : null,
			whole ? el( 'p', { class: 'state-note', id: 'einrichtung-after', text: t( 'einrichtung.after' ) } ) : null,
			el( 'p', { class: 'state-note', id: 'einrichtung-said', role: 'status', text: said } ) ) );
	};

	/**
	 *	The first run: the status is read, and a product that is whole has no use for this screen
	 *
	 *	@param		{Object}	where
	 *	@param		{number}	mine
	 *
	 *	@return		{void}
	 */
	const einrichtung = function( where, mine ) {

		state.entered = true;

		if( state.seen !== null ) {
			const items = state.seen;

			state.seen = null;
			drawEinrichtung( items, '', mine );
			return;
		}

		loading();

		api( 'crm/status' ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			if( answer.body.whole === true ) {
				wn.location.hash = '#uebersicht';
				return;
			}

			drawEinrichtung( answer.body.items, '', mine );
		} );
	};


	// --- Einstellungen -----------------------------------------------------------------------------------------------

	/**
	 *	@param		{string}	stored					1900,700,550,0 - hundredths of a percent
	 *
	 *	@return		{string}									19; 7; 5,5; 0
	 */
	const ratesText = function( stored ) {
		return stored.split( ',' ).map( function( rate ) { return money().formatRate( Number( rate ) ).replace( ' %', '' ) } ).join( '; ' );
	};

	const einstellungen = function( where, mine ) {

		loading();

		const seen = state.seen;

		state.seen = null;

		Promise.all( [ api( 'crm-documents/settings-get' ), seen !== null ? Promise.resolve( { ok : true, status : 200, body : { items : seen }, handled : false } ) : api( 'crm/status' ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			const failure = answers.find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			drawEinstellungen( answers[0].body, answers[1].body.items, '' );
		} );
	};

	/**
	 *	The sentence for an item of the status that is not in order
	 *
	 *	@param		{Object}	item						key, state and - for the portal - lacks
	 *
	 *	@return		{string}
	 */
	// An item that needs nothing done: in order, or - the start page - the owner's own
	const complete = function( item ) {
		return item.state === 'ok' || item.state === 'own';
	};

	const statusLine = function( item ) {

		const key = 'status.'+ item.key+ '-'+ item.state;

		// What a portal lacks is a kind with a word; what the PDF templates lack are their keys
		if( item.state === 'partial' && Array.isArray( item.lacks ) === true )
			return t( key, item.lacks.map( function( kind ) { return item.key === 'pdf-templates' ? kind : t( 'status.lacks-'+ kind ) } ).join( ', ' ) );

		return t( key );
	};

	/**
	 *	What the setup says it did, in one sentence
	 *
	 *	@param		{Object}	answer
	 *
	 *	@return		{string}
	 */
	const setupSentence = function( answer ) {

		const none = { features : [], routes : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : false };
		const added = Object.assign( {}, none, answer.added );
		const existed = Object.assign( {}, none, answer.existed );
		const there = existed.features.length+ existed.routes.length+ existed.templates.length+ existed.types.length+ existed.pdf.templates.length;
		const sentence = t( 'setup.done', added.features.length, added.routes.length, added.templates.length, added.types.length, added.texts, added.pdf.templates.length, there );

		// What became of the start page, said in the answer: made the door where there was none or the wizard's page, or kept where it is the owner's
		return typeof added.home === 'string' ? sentence+ ' '+ t( 'setup.home-'+ added.home ) : ( existed.home === 'own' ? sentence+ ' '+ t( 'setup.home-own' ) : sentence );
	};

	/**
	 *	The card Portal of the settings: what the portal is, read only, and the state of the module with the button that sets it up. The button
	 *	draws the card again with the state it brought about, and says what it did
	 *
	 *	@param		{Object}	portal					The portal as settings-get has it
	 *	@param		{Array}		items					The items of the status
	 *	@param		{string}	said						What the setup said, kept over the card that is drawn again
	 *	@param		{number}	mine
	 *
	 *	@return		{Object}
	 */
	const portalCard = function( portal, items, said, mine ) {

		const missing = items.filter( function( item ) { return item.state !== 'ok' && item.state !== 'own' } );
		const ready = missing.length === 0;
		const pages = portal.pages.map( function( page ) { return page.present === true ? page.uri : t( 'settings.portal-page-missing', page.uri ) } ).join( ' · ' );
		const setup = el( 'button', { type: 'button', class: 'btn sm', id: 'portal-setup', hidden: ready, text: t( 'settings.portal-setup' ), on: { click: function() {

			setup.disabled = true;

			call( 'crm/setup', {}, 'error.setup' ).then( function( answer ) {

				setup.disabled = false;

				if( answer.ok !== true || state.view !== mine )
					return;

				// The setup changed the routes and the settings of Members the card shows as well as the status: both are read again
				Promise.all( [ call( 'crm/status', {}, 'error.load' ), call( 'crm-documents/settings-get', {}, 'error.load' ) ] ).then( function( again ) {
					if( again[0].ok === true && again[1].ok === true && state.view === mine )
						card.replaceWith( card = portalCard( again[1].body.portal, again[0].body.items, setupSentence( answer.body ), mine ) );
				} );
			} );
		} } } );

		let card = el( 'div', { class: 'card pad', id: 'portal' }, el( 'h3', { text: t( 'settings.portal' ) } ), kv( [
			[ t( 'settings.portal-address' ), portal.address === '' ? el( 'span', { class: 'bad', text: t( 'settings.portal-no-address' ) } ) : portal.address ],
			[ t( 'settings.portal-pages' ), pages ],
			[ t( 'settings.portal-members' ), portal.members.length === 0 ? '' : el( 'span', {}, portal.members.map( function( one, index ) { return [ index === 0 ? null : el( 'br' ), one.label+ ': '+ one.value+ ( typeof state.texts['unit.'+ one.unit] === 'string' ? ' '+ t( 'unit.'+ one.unit ) : '' ) ] } ) ) ],
			[ t( 'settings.portal-cap' ), portal.mail_cap === null ? '' : ( portal.mail_cap === 0 ? t( 'settings.portal-cap-none' ) : t( 'settings.portal-cap-value', portal.mail_cap ) ) ],
		] ),
		el( 'p', { class: 'state-note', text: t( 'settings.portal-members-note' ) } ),
		el( 'h4', { text: t( 'settings.portal-status' ) } ),
		missing.length === 0 ? el( 'p', { class: 'state-note', id: 'status-ok', text: t( 'status.allok' ) } ) : el( 'ul', { class: 'list-mini', id: 'status-missing' }, missing.map( function( item ) { return el( 'li', { text: statusLine( item ) } ) } ) ),
		el( 'div', { class: 'actions' }, setup ), el( 'p', { class: 'state-note', id: 'setup-said', role: 'status', text: said } ) );

		return card;
	};

	const drawEinstellungen = function( view, items, said ) {

		const settings = view.settings;
		const form = formOf();
		const mine = state.view;
		const select = el( 'select', { id: 'f-vat_mode', value: settings.vat_mode }, [ 'small_business', 'regular' ].map( function( mode ) { return el( 'option', { value: mode, text: t( 'vat.'+ mode ) } ) } ) );
		const day = function( id, value ) { return el( 'input', { id: id, type: 'text', value: value, maxlength: '3', autocomplete: 'off', class: 'short' } ) };

		const taxes = formList( [
			{ name : 'vat_mode', label : t( 'settings.vat-mode' ), control : select, hint : t( 'settings.vat-mode-hint' ) },
			{ name : 'vat_rates_percent', label : t( 'settings.vat-rates' ), control : el( 'input', { id: 'f-vat_rates_percent', type: 'text', value: ratesText( settings.vat_rates ), maxlength: '40', autocomplete: 'off' } ), hint : t( 'settings.vat-rates-hint' ) },
			{ name : 'payment_days', label : t( 'settings.payment-days' ), control : day( 'f-payment_days', settings.payment_days ) },
			{ name : 'offer_days', label : t( 'settings.offer-days' ), control : day( 'f-offer_days', settings.offer_days ) },
			{ name : 'currency', label : t( 'settings.currency' ), control : el( 'input', { id: 'f-currency', type: 'text', value: settings.currency, maxlength: '5', autocomplete: 'off', class: 'short' } ) },
		], form );

		const numbers = formList( [ 'offer', 'invoice', 'credit' ].map( function( category ) {
			return { name : 'number_'+ category, label : t( 'settings.number-'+ category ), control : el( 'input', { id: 'f-number_'+ category, type: 'text', value: settings['number_'+ category], maxlength: '40', autocomplete: 'off' } ) };
		} ), form );

		const next = el( 'p', { class: 'state-note' }, el( 'b', { text: t( 'settings.next' )+ ': ' } ), el( 'span', { class: 'num', id: 'next-numbers', text: [ 'offer', 'invoice', 'credit' ].map( function( category ) { return view.next[category] } ).filter( function( number ) { return number !== '' } ).join( ' · ' ) } ) );

		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'settings-save', text: t( 'settings.save' ) } );

		const submit = function( event ) {

			event.preventDefault();
			save.disabled = true;

			api( 'crm-documents/settings-save', valuesOfForm( form ) ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'error.settings' );
					return;
				}

				// What the other screens compute with, and the next numbers the new formats give
				state.settings = Object.assign( {}, state.settings, answer.body.settings, { rates : answer.body.settings.vat_rates.split( ',' ).map( Number ) } );
				form.dirty = false;
				state.draft = null;
				toast( t( 'settings.saved' ) );

				call( 'crm-documents/settings-get', {}, 'error.load' ).then( function( again ) {
					if( again.ok === true && state.view === mine )
						drawEinstellungen( again.body, items, said );
				} );
			} );
		};

		reveal();
		state.main.appendChild( el( 'form', { id: 'settings-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'settings.title' ), el( 'span', { class: 'grow' } ), save ),
			el( 'div', { class: 'cols' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'settings.vat' ) } ), taxes ),
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'settings.numbers' ) } ), numbers, next, el( 'p', { class: 'state-note', text: t( 'settings.numbers-note' ) } ) ) ) ) );

		// Vorlagen of the feature PDF: the assignment, read only
		const templates = el( 'div', { class: 'card pad', id: 'templates' }, el( 'h3', { text: t( 'settings.templates' ) } ),
			view.pdf === false ? el( 'p', { class: 'state-note', text: t( 'settings.templates-off' ) } ) : kv( [ 'offer', 'invoice', 'contract', 'privacy', 'information' ].map( function( tag ) {
				return [ t( 'tag.'+ tag ), view.templates[tag].length === 0 ? t( 'settings.templates-none' ) : view.templates[tag].join( ' · ' ) ];
			} ) ),
			el( 'p', { class: 'state-note' }, t( 'settings.templates-note' ), ' ', link( state.links.pdf, t( 'settings.templates-link' ), { target : '_blank', rel : 'noopener' } ) ) );

		state.main.appendChild( el( 'div', { class: 'cols gap' }, templates, portalCard( view.portal, items, said, mine ) ) );

		state.main.appendChild( el( 'div', { class: 'card pad gap', id: 'access' }, el( 'h3', { text: t( 'settings.access' ) } ),
			el( 'p', { class: 'state-note', text: t( 'settings.access-text', state.perm ) } ),
			el( 'p', { class: 'state-note' }, link( state.links.users, t( 'settings.access-link' ), { target : '_blank', rel : 'noopener' } ) ) ) );
	};


	// --- the router --------------------------------------------------------------------------------------------------

	/**
	 *	Which screen a hash names
	 *
	 *	@param		{string}	hash
	 *
	 *	@return		{Object}									screen, and id, or category and offer
	 */
	const parse = function( hash ) {

		const text = String( hash || '' ).replace( /^#/, '' );
		let found = null;

		if( text === 'belege' )
			return { screen : 'belege' };

		found = /^beleg-([1-9][0-9]{0,9})$/.exec( text );

		if( found !== null )
			return { screen : 'beleg', id : Number( found[1] ) };

		found = /^entwurf-([1-9][0-9]{0,9})$/.exec( text );

		if( found !== null )
			return { screen : 'entwurf', id : Number( found[1] ) };

		found = /^neu-(invoice|offer|contract|privacy|information)(?:-([1-9][0-9]{0,9}))?(?:-k([1-9][0-9]{0,9}))?$/.exec( text );

		if( found !== null )
			return { screen : 'entwurf', category : found[1], offer : found[2] === undefined ? '' : found[2], client : found[3] === undefined ? '' : found[3] };

		if( text === 'kunden' )
			return { screen : 'kunden' };

		if( text === 'kunde-neu' )
			return { screen : 'kundeneu' };

		found = /^kunde-([1-9][0-9]{0,9})(?:-(belege|portal|notizen|einladen))?$/.exec( text );

		if( found !== null )
			return { screen : 'kunde', id : Number( found[1] ), tab : found[2] === undefined ? '' : found[2] };

		if( text === 'positionen' )
			return { screen : 'positionen' };

		if( text === 'einstellungen' )
			return { screen : 'einstellungen' };

		if( text === 'einrichtung' )
			return { screen : 'einrichtung' };

		return { screen : 'uebersicht' };
	};

	const screens = { uebersicht : uebersicht, belege : belege, beleg : beleg, entwurf : entwurf, kunden : kunden, kunde : kunde, kundeneu : kundeNeu, positionen : positionen, einstellungen : einstellungen, einrichtung : einrichtung };
	const rail = { uebersicht : 'uebersicht', belege : 'belege', beleg : 'belege', entwurf : 'belege', kunden : 'kunden', kunde : 'kunden', kundeneu : 'kunden', positionen : 'positionen', einstellungen : 'einstellungen', einrichtung : '' };

	const route = function() {

		const where = parse( wn.location.hash );

		state.view++;
		state.draft = null;
		closeMenus();

		dc.querySelectorAll( '[data-r]' ).forEach( function( anchor ) {

			const on = anchor.dataset.r === rail[where.screen];

			anchor.classList.toggle( 'on', on );

			if( on === true )
				anchor.setAttribute( 'aria-current', 'page' );
			else
				anchor.removeAttribute( 'aria-current' );
		} );

		// The first showing of the page, whichever screen the address names, once per page: a product that was never set up starts on the Einrichtung.
		// A status that cannot be read lets the screen draw
		if( where.screen !== 'einrichtung' && state.entered === false ) {

			const mine = state.view;

			state.entered = true;
			loading();

			api( 'crm/status' ).then( function( answer ) {

				if( state.view !== mine )
					return;

				// The answer is handed on: the Einrichtung draws from it and does not ask again
				if( answer.ok === true && answer.body.first_run === true ) {
					state.seen = answer.body.items;
					wn.location.hash = '#einrichtung';
				}
				else {
					// ...and so is it to the Einstellungen, which read the status as well
					state.seen = answer.ok === true && where.screen === 'einstellungen' ? answer.body.items : null;
					screens[where.screen]( where, mine );
				}
			} );

			return;
		}

		screens[where.screen]( where, state.view );
	};

	/**
	 *	Start the page: read its data block, set the shell up and draw the screen the hash names
	 *
	 *	@param		{Object}	[block]					The element with the JSON; the page's own by default
	 *
	 *	@return		{void}
	 */
	const boot = function( block ) {

		const data = JSON.parse( ( block || dc.getElementById( 'crm-data' ) ).textContent );

		reset();

		state.base = data.base;
		state.csrf = data.csrf;
		state.perm = data.perm;
		state.user = data.user.name;
		state.texts = data.texts;
		state.links = data.links;
		state.main = dc.getElementById( 'main' );
		state.toast = dc.getElementById( 'toast' );
		state.dialogs = dc.getElementById( 'dialogs' );
		state.count = dc.getElementById( 'n-belege' );
		state.clientCount = dc.getElementById( 'n-kunden' ) ?? null;

		wn.addEventListener( 'hashchange', route );

		dc.addEventListener( 'click', function( event ) {

			const target = event.target !== null && event.target !== undefined && typeof event.target.closest === 'function' ? event.target : null;

			closeMenus( target === null ? null : target.closest( '.menu' ) );

			// A link away from a draft that holds what was not stored asks first
			const anchor = target === null ? null : target.closest( 'a' );
			const href = anchor === null ? '' : anchor.getAttribute( 'href' );

			if( state.draft !== null && state.draft.dirty === true && typeof href === 'string' && href.charAt( 0 ) === '#' ) {
				event.preventDefault();
				ask( { title : t( 'leave.title' ), text : t( state.draft.form === true ? 'leave.text-form' : 'leave.text' ), yes : t( 'leave.yes' ), danger : true } ).then( function( yes ) {
					if( yes === true && state.draft !== null ) {
						state.draft.dirty = false;
						wn.location.hash = href;
					}
				} );
			}
		} );

		wn.addEventListener( 'beforeunload', function( event ) {
			if( state.draft !== null && state.draft.dirty === true ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		dc.addEventListener( 'keydown', function( event ) {

			if( event.key === 'Escape' ) {
				closeMenus();
				return;
			}

			const tag = dc.activeElement !== null && dc.activeElement !== undefined ? String( dc.activeElement.tagName ) : '';

			if( event.key === '/' && [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( tag ) === false ) {
				const field = dc.getElementById( 'q' );

				if( field !== null ) {
					event.preventDefault();
					field.focus();
				}
			}
		} );

		route();

		// The count in the rail: the first screen reads the books itself when it is the Übersicht
		if( [ 'uebersicht', 'einrichtung' ].includes( parse( wn.location.hash ).screen ) === false )
			refresh();
	};

	wn.Nino.crm.app = {
		boot : boot,
		route : route,
		parse : parse,
		state : state,
		el : el,
		t : t,
		model : { docName : docName, visible : visible, visibleClients : visibleClients, clientTitle : clientTitle, ratesText : ratesText, chips : chips, draftOf : draftOf, draftNew : draftNew, payloadOf : payloadOf, totalsOf : totalsOf, ready : ready, lineOf : lineOf, primaryOf : primaryOf, tabsOf : tabsOf, errorText : errorText },
	};

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', function() { boot() } );
	else if( dc.readyState === 'interactive' || dc.readyState === 'complete' )
		boot();

} )( window, document );

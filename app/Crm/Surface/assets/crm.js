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
 *								#entwurf-16, #neu-invoice) and the answers of the endpoint (the session that ended, the token that is stale)
 *				  the screens	Übersicht, Belege, Beleg and Entwurf
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
			base : '', csrf : '', user : '', texts : {}, links : {},
			settings : { currency : '', vat_mode : 'small_business', rates : [], payment_days : '14', offer_days : '30' },
			clients : [], items : [], offers : [], years : [], docs : [], summary : null,
			filter : { chip : 'all', q : '', sort : 'date', dir : -1, year : 0, page : 1 },
			view : 0, tab : '', draft : null, saving : false, queued : false, pending : null, timer : null, main : null, toast : null, dialogs : null, count : null,
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
	 *	@param		{Object}	spec						label, button (the class of the button), align, items ([ { label, run, href, saved, danger } | 'sep' ])
	 *
	 *	@return		{Object}
	 */
	const menu = function( spec ) {

		const root = el( 'div', { class: 'menu' } );
		const button = el( 'button', { type: 'button', class: 'btn '+ ( spec.button || '' ), 'aria-haspopup': 'true', 'aria-expanded': 'false', text: spec.label, on: { click: function() {
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
	 *
	 *	@return		{Object}
	 */
	const newMenu = function( label ) {

		const items = [];

		NEW_KINDS.forEach( function( kind ) {

			if( kind === 'contract' )
				items.push( 'sep' );

			items.push( { label : t( 'new.'+ kind ), run : function() { wn.location.hash = '#neu-'+ kind } } );
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
	 *	The count of the open invoices in the rail
	 *
	 *	@param		{Object}	summary
	 *
	 *	@return		{void}
	 */
	const countOpen = function( summary ) {

		state.summary = summary;
		state.count.textContent = summary.count > 0 ? String( summary.count ) : '';
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

		return el( 'li', {}, el( 'span', {}, dot( 'muted' ), link( state.links.clients, need.client_title ), ' '+ t( 'needs.account' ) ), el( 'span', { class: 'r' }, link( state.links.clients, t( 'needs.invite' ) ) ) );
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

		const heading = function( key, label, right ) {

			const on = filter.sort === key;

			return el( 'th', { class: ( right === true ? 'r ' : '' )+ 'sortable', scope: 'col', 'aria-sort': on ? ( filter.dir > 0 ? 'ascending' : 'descending' ) : 'none' },
				el( 'button', { type: 'button', class: 'th', dataset: { sort: key }, text: label+ ( on ? ( filter.dir > 0 ? ' ↑' : ' ↓' ) : '' ), on: { click: function() {
					if( filter.sort === key )
						filter.dir = -filter.dir;
					else {
						filter.sort = key;
						filter.dir = key === 'date' ? -1 : 1;
					}
					redraw();
				} } } ) );
		};

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

		const pager = pages === 1 ? null : el( 'div', { class: 'pager' },
			el( 'button', { type: 'button', class: 'btn sm', id: 'prev', text: t( 'list.prev' ), disabled: filter.page === 1, on: { click: function() { filter.page--; redraw() } } } ),
			el( 'span', { class: 'state-note', text: t( 'list.page', filter.page, pages ) } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'next', text: t( 'list.next' ), disabled: filter.page === pages, on: { click: function() { filter.page++; redraw() } } } ) );

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
	 *	Draw the list again with the search field kept as it is, the cursor in it
	 *
	 *	@param		{boolean}	[typing]				The search is what changed
	 *
	 *	@return		{void}
	 */
	const redraw = function( typing ) {

		const field = typing === true ? dc.getElementById( 'q' ) : null;
		const at = field === null ? 0 : field.selectionStart;

		drawBelege();

		if( field !== null ) {
			const next = dc.getElementById( 'q' );
			next.focus();
			next.setSelectionRange( at, at );
		}
	};


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
			el( 'span', { class: 'sub' }, link( state.links.clients, client.company || client.name ), ' · '+ dmy( doc.date )+ ( doc.due_date ? ' · '+ t( 'head.due', dmy( doc.due_date ) ) : doc.valid_until ? ' · '+ t( 'head.valid', dmy( doc.valid_until ) ) : '' ) ),
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
					? [ t( 'client.no-account' ), ' ', link( state.links.clients, t( 'client.invite' ) ) ]
					: [ accounts.map( function( account ) { return account.name !== '' ? account.name : account.email } ).join( ', ' ), ' ', link( state.links.clients, t( 'client.accounts-link' ) ) ] );
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

		found = /^neu-(invoice|offer|contract|privacy|information)(?:-([1-9][0-9]{0,9}))?$/.exec( text );

		if( found !== null )
			return { screen : 'entwurf', category : found[1], offer : found[2] === undefined ? '' : found[2] };

		return { screen : 'uebersicht' };
	};

	const screens = { uebersicht : uebersicht, belege : belege, beleg : beleg, entwurf : entwurf };
	const rail = { uebersicht : 'uebersicht', belege : 'belege', beleg : 'belege', entwurf : 'belege' };

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
		state.user = data.user.name;
		state.texts = data.texts;
		state.links = data.links;
		state.main = dc.getElementById( 'main' );
		state.toast = dc.getElementById( 'toast' );
		state.dialogs = dc.getElementById( 'dialogs' );
		state.count = dc.getElementById( 'n-belege' );

		wn.addEventListener( 'hashchange', route );

		dc.addEventListener( 'click', function( event ) {

			const target = event.target !== null && event.target !== undefined && typeof event.target.closest === 'function' ? event.target : null;

			closeMenus( target === null ? null : target.closest( '.menu' ) );

			// A link away from a draft that holds what was not stored asks first
			const anchor = target === null ? null : target.closest( 'a' );
			const href = anchor === null ? '' : anchor.getAttribute( 'href' );

			if( state.draft !== null && state.draft.dirty === true && typeof href === 'string' && href.charAt( 0 ) === '#' ) {
				event.preventDefault();
				ask( { title : t( 'leave.title' ), text : t( 'leave.text' ), yes : t( 'leave.yes' ), danger : true } ).then( function( yes ) {
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
		if( parse( wn.location.hash ).screen !== 'uebersicht' )
			refresh();
	};

	wn.Nino.crm.app = {
		boot : boot,
		route : route,
		parse : parse,
		state : state,
		el : el,
		t : t,
		model : { docName : docName, visible : visible, chips : chips, draftOf : draftOf, draftNew : draftNew, payloadOf : payloadOf, totalsOf : totalsOf, ready : ready, lineOf : lineOf, primaryOf : primaryOf, tabsOf : tabsOf, errorText : errorText },
	};

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', function() { boot() } );
	else if( dc.readyState === 'interactive' || dc.readyState === 'complete' )
		boot();

} )( window, document );

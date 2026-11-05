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
 *								#entwurf-16, #neu-invoice, #kunden, #kunde-3, #kunde-3-portal, #kunde-neu, #projekte, #projekte-neu, #projekt-<id>, #positionen, #positionen-neu, #position-<uri>, #einstellungen, #einrichtung, and
 *								for PDF-Vorlagen #pdf, #pdf-bausteine, #pdf-layout, #pdf-design, #pdf-vorlage-<key>, #pdf-neue-vorlage-<tag>, #pdf-baustein-<key>,
 *								#pdf-neuer-baustein) and the answers of the endpoint (the session that ended, the token that is stale)
 *				  the screens	Übersicht, Belege, Beleg and Entwurf, then Kunden, Kundenkarte (with the tab Projekte), Neuer Kunde, Projekte (the list, Neues Projekt and the Projektkarte with its tabs
 *								Belege and Dateien, which drives the Elements panel's actions for the type projekte and the Files panel's for the project's folder), Positionen (for an account with the permission of the
 *								Elements panel with the editor of an item, Position, which edits the elements of the type positionen through the panel's actions) and Einstellungen, the
 *								Einrichtung of the first run (the page starts there while crm/status says the product is not whole) and - for an account
 *								with the permission of the Pdf panel - PDF-Vorlagen: Vorlagen with the editor of a template, Bausteine with the editor of a
 *								block, Layout and Design, which edit what the feature Pdf keeps through its own actions
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
	const CLIENT_TABS = [ 'belege', 'projekte', 'portal', 'notizen' ];
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
			base : '', csrf : '', perm : '', perms : { crm : true, pdf : false, elements : false, text : false, files : false, members : false, sign : false }, user : '', texts : {}, links : {},
			settings : { currency : '', vat_mode : 'small_business', rates : [], payment_days : '14', offer_days : '30' },
			clients : [], items : [], offers : [], years : [], docs : [], summary : null, people : [], owed : {},
			filter : { chip : 'all', q : '', sort : 'date', dir : -1, year : 0, page : 1 },
			cfilter : { q : '', sort : 'number', dir : 1, page : 1 },
			pfilter : { q : '', sort : 'name', dir : 1 }, ifilter : { q : '', sort : 'sort', dir : 1, page : 1 }, pbody : null, jfilter : { q : '', all : false, page : 1 }, jbody : null, busy : null, pdf : { site : [] }, saver : {}, company : null,
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
	 *	The day a setting of days comes to after a day: the prefill of the second date of an offer or an invoice
	 *
	 *	@param		{string}	day							Y-m-d
	 *	@param		{string}	days						The setting: whole days, '0' or '' for none
	 *
	 *	@return		{string}									Y-m-d; '' where the setting is none
	 */
	const daysAfter = function( day, days ) {
		return Number( days ) > 0 ? addDays( day, Number( days ) ) : '';
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
		{ key : 'accepted', test : function( doc ) { return doc.state === 'accepted' } },
		{ key : 'invoices', test : function( doc ) { return doc.category === 'invoice' || doc.category === 'credit' } },
		{ key : 'plain', test : function( doc ) { return isPlain( doc ) } },
		{ key : 'paid', test : function( doc ) { return doc.state === 'paid' } },
	];

	const sortKeys = {
		number : function( doc ) { return docName( doc ).toLowerCase() },
		client : function( doc ) { return String( doc.client_title ).toLowerCase() },
		project : function( doc ) { return String( doc.project_title ).toLowerCase() },
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
			return query === '' || ( docName( doc )+ ' '+ doc.title+ ' '+ doc.client_title+ ' '+ doc.project_title+ ' '+ t( 'category.'+ doc.category ) ).toLowerCase().includes( query );
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
			template_key : doc.template_key, inputs : Object.assign( {}, doc.inputs ), reverse_charge : doc.reverse_charge === true, title : doc.title, notes : doc.notes, project : doc.project || '',
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
			due_date : category === 'invoice' ? daysAfter( day, settings.payment_days ) : '', valid_until : category === 'offer' ? daysAfter( day, settings.offer_days ) : '',
			period_from : '', period_to : '', template_key : '', inputs : {}, reverse_charge : false, title : '', notes : '', project : '',
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

		const data = { date : draft.date, template_key : draft.template_key, inputs : inputs, project : draft.project };

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
	 *	The block of a link that is shown once: a field that cannot be typed in, the button that copies it (where the browser has a clipboard; else the field is
	 *	selected for the person to copy) and the sentence that it is shown now and kept nowhere
	 *
	 *	@param		{Object}	spec						label, value, note
	 *
	 *	@return		{Object}									node and field
	 */
	const shownLink = function( spec ) {

		const field = el( 'input', { id: 'dlg-link', type: 'text', readonly: 'readonly', 'aria-label': spec.label, value: spec.value } );
		const line = el( 'span', { class: 'state-note', id: 'dlg-link-state', role: 'status' } );
		const copy = el( 'button', { type: 'button', class: 'btn sm', id: 'dlg-link-copy', text: t( 'shown.copy' ), on: { click: function() {

			field.focus();
			field.select();

			if( wn.navigator !== undefined && wn.navigator.clipboard !== undefined && typeof wn.navigator.clipboard.writeText === 'function' )
				wn.navigator.clipboard.writeText( field.value ).then( function() { line.textContent = t( 'shown.copied' ) }, function() { line.textContent = t( 'shown.selected' ) } );
			else
				line.textContent = t( 'shown.selected' );
		} } } );

		field.readOnly = true;

		return { field : field, node : el( 'div', { class: 'secret' }, el( 'label', { class: 'field' }, el( 'span', { text: spec.label } ), field ), el( 'div', { class: 'actions' }, copy, line ), el( 'p', { class: 'state-note', text: spec.note } ) ) };
	};

	/**
	 *	A dialog with one question: the text, optionally fields, and two buttons. Escape and the second button are the no
	 *
	 *	@param		{Object}	spec						title, text, yes, no, danger, fields ([ { name, label, type, value, options } ]; the type checkbox is a box, ticked where the value is true, and its value is a boolean), link ({ href, text } instead of the buttons),
	 *														secret ({ label, value, note }: a link shown once, in a field nobody can type in, with a button that copies it - and the dialog closes with a button)
	 *
	 *	@return		{Promise}									The values of the fields (an empty object without fields) for the yes; null for the no
	 */
	const dialog = function( spec ) {

		return new Promise( function( resolve ) {

			let answer = null;
			let closed = false;
			let decided = false;
			const controls = {};

			const box = el( 'dialog', { class: spec.wide === true ? 'dlg wide' : 'dlg', 'aria-label': spec.title || spec.text } );
			const finish = function( value ) {

				if( decided === true )
					return;

				decided = true;
				answer = value;
				box.close();
			};

			const accept = function() {

				const values = {};

				Object.keys( controls ).forEach( function( name ) { values[name] = controls[name].type === 'checkbox' ? controls[name].checked === true : controls[name].value } );
				finish( values );
			};

			const fields = ( spec.fields || [] ).map( function( field ) {

				if( field.type === 'checkbox' ) {

					const box = el( 'input', { id: 'dlg-'+ field.name, type: 'checkbox', checked: field.value === true } );

					controls[field.name] = box;

					return el( 'label', { class: 'check' }, box, ' '+ field.label );
				}

				const control = field.options !== undefined
					? el( 'select', { id: 'dlg-'+ field.name }, field.options.map( function( option ) { return el( 'option', { value: option.value, selected: option.value === field.value, text: option.label } ) } ) )
					: el( 'input', { id: 'dlg-'+ field.name, type: field.type || 'text', value: field.value ?? '', min: field.min, max: field.max } );

				controls[field.name] = control;

				return el( 'label', { class: 'field' }, el( 'span', { text: field.label } ), control );
			} );

			const secret = spec.secret === undefined ? null : shownLink( spec.secret );

			box.addEventListener( 'close', function() {

				if( closed === true )
					return;

				closed = true;

				// A link that was shown is gone with the dialog: not the field, nor the text of it
				if( secret !== null )
					secret.field.value = '';

				if( box.parentNode !== null )
					box.parentNode.removeChild( box );

				resolve( answer );
			} );

			const form = el( 'form', { on: { submit: function( event ) { event.preventDefault(); accept() } } },
				spec.title ? el( 'h2', { text: spec.title } ) : null,
				spec.text === undefined ? null : el( 'p', { text: spec.text } ),
				spec.pre === undefined ? null : el( 'pre', { class: 'sheet', text: spec.pre } ),
				secret === null ? null : secret.node,
				fields,
				el( 'div', { class: 'actions end' },
					spec.link !== undefined
						? el( 'a', { class: 'btn primary', href: spec.link.href, target: spec.link.blank === true ? '_blank' : null, rel: spec.link.blank === true ? 'noopener' : null, text: spec.link.text, on: { click: function( event ) {
							if( spec.link.reload === true ) {
								event.preventDefault();
								wn.location.reload();
							}
						} } } )
						: spec.close === true ? el( 'button', { type: 'button', class: 'btn primary', text: t( 'dialog.close' ), on: { click: function() { finish( null ) } } } ) : [
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
	 *	The box of a link that may be shown instead of mailed: only for an account that holds the permission of the feature that makes the link (kind: members
	 *	or sign). A link without the mail signs in, or signs, as the customer's account, so the CRM's permission alone does not offer it
	 *
	 *	@param		{string}	kind
	 *	@param		{string}	label					The key of the words of the box
	 *
	 *	@return		{Array}										The fields of a dialog: the box, or none
	 */
	const quietField = function( kind, label ) {
		return state.perms[kind] === true ? [ { name : 'notify', label : t( label ), type : 'checkbox', value : true } ] : [];
	};

	/**
	 *	The sentence of a dialog: its own where there is a box, and where there is none the sentence without the word of the box and the one that says why the link
	 *	goes by mail only
	 *
	 *	@param		{string}	kind
	 *	@param		{string}	key						The key of the sentence; the one without the box is the key with -plain
	 *
	 *	@return		{string}
	 */
	const quietText = function( kind, key ) {
		return state.perms[kind] === true ? t( key ) : t( key+ '-plain' )+ ' '+ t( 'notify.noperm-'+ kind );
	};

	/**
	 *	Show a link the server made without a mail, once, in a dialog of its own: it is in no screen and in no list, and the dialog takes it along when it closes
	 *
	 *	@param		{string}	kind						sign, invite or login
	 *	@param		{string}	link
	 *
	 *	@return		{Promise}
	 */
	const showLink = function( kind, link ) {

		// A link that is not there is no dialog
		if( typeof link !== 'string' || link === '' )
			return Promise.resolve( null );

		return dialog( { title: t( 'shown.title-'+ kind ), text: t( 'shown.text-'+ kind ), secret: { label: t( 'shown.field' ), value: link, note: t( 'shown.once' ) }, close: true } );
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
	 *	Post to the endpoint and read the answer: the session that ended and the token that is stale are said by the page, not by the screen
	 *
	 *	@param		{Object}	init						headers and body of the request
	 *
	 *	@return		{Promise}									{ ok, status, body, handled }; a request that did not arrive has status 0
	 */
	const post = function( init ) {

		return wn.fetch( state.base+ '/crm/api', Object.assign( { method : 'POST', credentials : 'same-origin' }, init ) ).then( function( response ) {

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

	/**
	 *	Post an action to the endpoint
	 *
	 *	@param		{string}	action					eg. crm-documents/list
	 *	@param		{Object}	[data]
	 *
	 *	@return		{Promise}									See post()
	 */
	const api = function( action, data ) {
		return post( { headers : { 'Content-Type' : 'application/json', 'X-CSRF-Token' : state.csrf }, body : JSON.stringify( { action : action, data : data || {} } ) } );
	};

	/**
	 *	Post an action with a file, as the Pdf panel posts a picture: a form with the action, the data (as JSON) and the file. The browser
	 *	writes the content type with the boundary itself
	 *
	 *	@param		{string}	action
	 *	@param		{Object}	data
	 *	@param		{Object}	file						A File, or a piece of one
	 *	@param		{string}	[part]					The name of the part the file comes in: file, and chunk for the Files panel's upload
	 *
	 *	@return		{Promise}									See post()
	 */
	const upload = function( action, data, file, part ) {

		const form = new wn.FormData();

		form.append( 'action', action );
		form.append( 'data', JSON.stringify( data || {} ) );
		form.append( part || 'file', file );

		return post( { headers : { 'X-CSRF-Token' : state.csrf }, body : form } );
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
	 *	@param		{Object}	spec						label, aria (what a screen reader calls the button), button (the class of the button), align, tall (a long list that scrolls), items ([ { label, run, href, saved, danger } | { heading } | 'sep' ])
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
		root.appendChild( el( 'div', { class: spec.tall === true ? 'list tall' : 'list', role: 'menu' }, spec.items.map( function( item ) {

			if( item === 'sep' )
				return el( 'hr' );

			if( item.heading !== undefined )
				return el( 'div', { class: 'heading', role: 'presentation', text: item.heading } );

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
	 *	@param		{string}	[project]				The project the document is for, which the editor starts with
	 *
	 *	@return		{Object}
	 */
	const newMenu = function( label, client, project ) {

		const items = [];

		NEW_KINDS.forEach( function( kind ) {

			if( client !== undefined && kind === 'contract' )
				return;

			if( client === undefined && kind === 'contract' )
				items.push( 'sep' );

			items.push( { label : t( 'new.'+ kind ), run : function() { wn.location.hash = '#neu-'+ kind+ ( client === undefined ? '' : '-k'+ client+ ( project === undefined ? '' : '-p'+ project ) ) } } );
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

	// --- Strg+S / Cmd+S -----------------------------------------------------------------------------------------------

	/**
	 *	Make a save of the screen known to the shell: what Strg+S (Cmd+S) runs. A screen has one for each form it holds, by a name of its own; a form that is
	 *	drawn again takes its name again, and a screen that is left has none (route() forgets them all)
	 *
	 *	@param		{string}		slot						The name of the form on the screen
	 *	@param		{Object}		node						The form or the card; the form that holds the focus is the one that is saved
	 *	@param		{Function}	run							Saves it
	 *	@param		{Function}	[dirty]					Whether it holds input that was not stored; without it the form is always the one to save - on a screen with one form
	 *	@param		{Function}	[busy]					Whether a save of it is running; without it the shell cannot tell
	 *	@param		{boolean}		[strict]				A form with nothing unstored is nothing to save even where it holds the focus
	 *
	 *	@return		{void}
	 */
	const saver = function( slot, node, run, dirty, busy, strict ) {
		state.saver[slot] = { node : node, run : run, dirty : dirty, busy : busy, strict : strict === true };
	};

	const unsaver = function( slot ) {
		delete state.saver[slot];
	};

	/**
	 *	The save of a form that has a Speichern: the form is submitted as its button would, and a button that is disabled is a save that is running
	 *
	 *	@param		{string}		slot
	 *	@param		{Object}		form
	 *	@param		{Object}		button
	 *	@param		{Function}	[dirty]
	 *
	 *	@return		{void}
	 */
	const submitter = function( slot, form, button, dirty ) {
		saver( slot, form, function() { form.requestSubmit() }, dirty, function() { return button.disabled === true } );
	};

	/**
	 *	Whether the node holds the one that has the focus (or is it)
	 *
	 *	@param		{Object}	node
	 *	@param		{Object}	inner
	 *
	 *	@return		{boolean}
	 */
	const holds = function( node, inner ) {

		for( let at = inner; at !== null && at !== undefined; at = at.parentNode )
			if( at === node )
				return true;

		return false;
	};

	/**
	 *	The form Strg+S saves: of the forms of the screen the one that holds the focused field, else the first that holds input that was not stored (a form that cannot
	 *	say is always one), else none
	 *
	 *	@return		{Object|null}
	 */
	const chosenSaver = function() {

		const all = Object.keys( state.saver ).map( function( slot ) { return state.saver[slot] } );
		const focused = all.find( function( one ) { return holds( one.node, dc.activeElement ) && ( one.strict !== true || one.dirty() === true ) } );

		if( focused !== undefined )
			return focused;

		return all.find( function( one ) { return typeof one.dirty !== 'function' || one.dirty() === true } ) ?? null;
	};

	/**
	 *	Strg+S or Cmd+S: the save of the form on screen. The browser's own save of the page is kept off only where a form took the key; a save that is running is not
	 *	started a second time; a question that stands over the page is not what the key is for
	 *
	 *	@param		{Event}		event
	 *
	 *	@return		{void}
	 */
	const shortcut = function( event ) {

		if( Array.from( state.dialogs.children ).some( function( box ) { return box.open === true } ) === true )
			return;

		const one = chosenSaver();

		if( one === null )
			return;

		event.preventDefault();

		if( typeof one.busy === 'function' && one.busy() === true )
			return;

		one.run();
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

		// The orders of the portal that no invoice has followed: one line, and the link shows the offers that are accepted
		if( need.kind === 'orders' )
			return el( 'li', {}, el( 'span', {}, dot( 'good' ), link( '#belege', t( need.count === 1 ? 'needs.orders-one' : 'needs.orders-many', need.count ), { id: 'needs-orders', on: { click: function() {
				state.filter.chip = 'accepted';
				state.filter.page = 1;
			} } } ) ), el( 'span', { class: 'r', text: cash( need.gross ) } ) );

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
			? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '9', class: 'muted', text: state.docs.length === 0 ? t( 'list.empty' ) : t( 'list.none' ) } ) )
			: shown.map( function( doc ) {

				const pdf = doc.state === 'draft' ? state.base+ '/crm/preview/'+ doc.id : pdfUrl( doc );

				return el( 'tr', { dataset: { id: doc.id }, on: { click: function() { wn.location.hash = hashOf( doc ) } } },
					el( 'td', { class: 'num' }, link( hashOf( doc ), docName( doc ) ) ),
					el( 'td', { text: t( 'category.'+ doc.category ) } ),
					el( 'td', { text: doc.client_title } ),
					el( 'td', {}, doc.project === '' ? el( 'span', { class: 'muted', text: '–' } ) : link( '#projekt-'+ doc.project, doc.project_title !== '' ? doc.project_title : doc.project, { on: { click: function( event ) { event.stopPropagation() } } } ) ),
					el( 'td', { text: dmy( doc.date ) } ),
					el( 'td', { class: doc.overdue === true ? 'bad' : 'muted', text: dmy( doc.due_date || doc.valid_until ) } ),
					el( 'td', { class: 'r num' }, isPlain( doc ) ? el( 'span', { class: 'muted', text: '–' } ) : cash( doc.gross ) ),
					el( 'td', {}, badges( doc ) ),
					el( 'td', { class: 'r' }, el( 'a', { class: 'btn sm quiet', href: pdf, target: '_blank', rel: 'noopener', text: t( 'list.pdf' ), on: { click: function( event ) { event.stopPropagation() } } } ) ) );
			} ) );

		const table = el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
			el( 'thead', {}, el( 'tr', {}, heading( 'number', t( 'column.number' ) ), el( 'th', { scope: 'col', text: t( 'column.category' ) } ), heading( 'client', t( 'column.client' ) ), heading( 'project', t( 'column.project' ) ), heading( 'date', t( 'column.date' ) ), heading( 'due', t( 'column.due' ) ), heading( 'gross', t( 'column.gross' ), true ), el( 'th', { scope: 'col', text: t( 'column.state' ) } ), el( 'th', { scope: 'col' } ) ) ),
			body,
			el( 'tfoot', {}, el( 'tr', {}, el( 'td', { colspan: '6', text: t( 'list.sum' ) } ), el( 'td', { class: 'r num', id: 'sum', text: cash( sum ) } ), el( 'td', { colspan: '2' } ) ) ) ) );

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

		dialog( { title : t( 'sign.ask-title' ), text : quietText( 'sign', 'sign.ask-text' ), yes : t( 'sign.ask' ), fields : [
			{ name : 'account', label : t( 'sign.account' ), options : accounts.map( function( account ) { return { value : account.id, label : account.name !== '' ? account.name+ ' <'+ account.email+ '>' : account.email } } ), value : accounts[0].id },
			{ name : 'days', label : t( 'sign.days' ), type : 'number', value : String( view.sign_days ), min : '1', max : '90' },
		].concat( quietField( 'sign', 'notify.link' ) ) } ).then( function( values ) {

			if( values === null )
				return;

			move( view, 'crm-documents/sign', { account : values.account, days : values.days, notify : values.notify !== false }, 'error.sign', function( body ) {

				if( typeof body.link === 'string' ) {
					showLink( 'sign', body.link );
					return t( 'sign.asked-quiet' );
				}

				return body.mailed === true ? t( 'sign.asked' ) : t( 'sign.asked-unmailed' );
			} );
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

		if( doc.category === 'invoice' && [ 'open', 'sent' ].includes( doc.state ) === true && doc.released === true )
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

		items.push( 'sep', { label : t( 'menu.project' ), run : function() { changeProject( view ) } }, { label : t( 'menu.notes' ), run : function() { state.tab = 'notes'; drawBeleg( view ) } } );

		return menu( { label : '⋯', items : items } );
	};

	/**
	 *	The project of a document of any state, a finalised one included: the question with the projects of its client, and the step
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const changeProject = function( view ) {

		const doc = view.document;
		const options = [ { value : '', label : t( 'field.project-none' ) } ].concat( ( view.projects || [] ).filter( function( one ) { return one.done !== true || one.id === doc.project } ).map( function( one ) { return { value : one.id, label : one.title } } ) );

		dialog( { title : t( 'menu.project' ), text : t( 'project.change-text' ), yes : t( 'project.change-yes' ), fields : [ { name : 'project', label : t( 'field.project' ), options : options, value : doc.project } ] } ).then( function( values ) {

			if( values === null || values.project === doc.project || state.view !== view.mine )
				return;

			move( view, 'crm-documents/project', { project : values.project }, 'error.project', function() { return t( 'project.changed' ) } );
		} );
	};

	/**
	 *	The payment reminder: the box says whether the accounts are mailed (it is, at first); without the mail the reminder is noted all the same
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const remind = function( view ) {

		dialog( { title : t( 'remind.title' ), text : t( 'remind.text' ), yes : t( 'remind.yes' ), fields : [ { name : 'notify', label : t( 'remind.notify' ), type : 'checkbox', value : true } ] } ).then( function( values ) {

			if( values === null )
				return;

			move( view, 'crm-documents/remind', { notify : values.notify }, 'error.remind', function( body ) {

				if( body.notify === false )
					return t( 'remind.noted' );

				return body.mailed > 0 ? t( 'remind.done', body.mailed ) : t( 'remind.none' );
			} );
		} );
	};

	/**
	 *	The link of a signature request again, with a new token: the old link is dead. The box says whether the account is mailed (it is, at first); without the
	 *	mail the new link is shown, once
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const signRemind = function( view ) {

		dialog( { title : t( 'sign.remind-title' ), text : quietText( 'sign', 'sign.remind-text' ), yes : t( 'sign.remind-yes' ), fields : quietField( 'sign', 'notify.link' ) } ).then( function( values ) {

			if( values === null )
				return;

			move( view, 'crm-documents/sign-remind', { notify : values.notify !== false }, 'error.sign-remind', function( body ) {

				if( typeof body.link === 'string' ) {
					showLink( 'sign', body.link );
					return t( 'sign.reminded-quiet' );
				}

				return t( 'sign.reminded' );
			} );
		} );
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

	/**
	 *	@param		{Object}	record					A record of the portal, see Records
	 *
	 *	@return		{string}									Who agreed: the name of the account, its address where it has no name
	 */
	const recordWho = function( record ) {
		return record.member_name !== '' ? record.member_name : record.member_email;
	};

	/**
	 *	What the customer was shown, in a dialog: the text or the JSON as text, with its hash over it
	 *
	 *	@param		{Object}	record
	 *
	 *	@return		{Promise}
	 */
	const showRecord = function( record ) {
		return dialog( { title: t( 'record.title-'+ record.kind ), text: t( 'record.hash', record.sha256 ), pre: record.shown, close: true, wide: true } );
	};

	/**
	 *	The card of the order in the tab Portal of a document: when, by whom and from where the customer ordered, the hash, and the way to the text that was shown
	 *
	 *	@param		{Object}	order						The record
	 *
	 *	@return		{Object}
	 */
	const orderCard = function( order ) {

		return el( 'div', { class: 'card pad gap', id: 'order-card' }, el( 'h3', { text: t( 'order.title' ) } ), kv( [
			[ t( 'order.at' ), stamp( order.at ) ],
			[ t( 'order.by' ), order.member_name !== '' ? order.member_name+ ' <'+ order.member_email+ '>' : order.member_email ],
			[ t( 'order.ip' ), order.ip ],
			[ t( 'order.agent' ), order.agent ],
			[ t( 'order.hash' ), el( 'code', { class: 'num', text: order.sha256 } ) ],
		] ), el( 'div', { class: 'actions' }, el( 'button', { type: 'button', class: 'btn sm', id: 'order-show', text: t( 'order.show' ), on: { click: function() { showRecord( order ) } } } ) ) );
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

		const cols = el( 'div', { class: 'cols' }, card( t( 'portal.title' ), portal ), card( t( 'other.title' ), other ) );

		return view.order === null || view.order === undefined ? cols : el( 'div', { class: 'gap' }, cols, orderCard( view.order ) );
	};

	const tabNotes = function( view ) {

		const area = el( 'textarea', { id: 'notes', rows: 5, class: 'area', 'aria-label': t( 'notes.title' ), value: view.document.notes } );

		let running = false;

		const save = function() {

			running = true;

			step( 'crm-documents/save', { id : String( view.document.id ), notes : area.value }, 'error.save' ).then( function( answer ) {

				running = false;

				if( answer.ok === true ) {
					view.document.notes = answer.body.document.notes;
					toast( t( 'notes.done' ) );
				}
			} );
		};

		const box = card( t( 'notes.title' ), area, el( 'div', { class: 'actions top' }, el( 'button', { type: 'button', class: 'btn sm', id: 'notes-save', title: t( 'save.shortcut' ), text: t( 'notes.save' ), on: { click: save } } ) ) );

		saver( 'notes', box, save, function() { return area.value !== String( view.document.notes ?? '' ) }, function() { return running }, true );

		return box;
	};

	const tabHistory = function( view ) {

		const doc = view.document;
		const entries = [
			[ doc.created, t( 'history.created' ) ],
			[ doc.finalised, doc.number !== '' ? t( 'history.finalised', doc.number ) : t( 'history.finalised-plain' ) ],
			[ doc.released_at, t( 'history.released' ) ],
			[ doc.sent_at, t( 'history.sent' ) ],
			[ doc.reminded_at, t( doc.reminded_mail === true ? 'history.reminded-mail' : 'history.reminded-quiet' ) ],
			[ doc.paid_at, t( 'history.paid' ) ],
			[ doc.signed_at, t( 'history.signed' ) ],
			[ view.order ? view.order.at : '', view.order ? t( 'history.ordered', recordWho( view.order ) ) : '' ],
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
		view.mine = mine;

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
			el( 'span', { class: 'sub' }, link( '#kunde-'+ doc.client_id, client.company || client.name ), ' · '+ dmy( doc.date )+ ( doc.due_date ? ' · '+ t( 'head.due', dmy( doc.due_date ) ) : doc.valid_until ? ' · '+ t( 'head.valid', dmy( doc.valid_until ) ) : '' ), doc.project === '' ? null : [ ' · ', link( '#projekt-'+ doc.project, doc.project_title !== '' ? doc.project_title : doc.project, { id: 'doc-project' } ) ] ),
			el( 'span', { class: 'grow' } ), isPlain( doc ) ? null : el( 'span', { class: 'big num', id: 'gross', text: cash( doc.gross ) } ), primaryButton, menuOf( view ) ) );

		// An offer the customer ordered in the portal says so under the head; one the owner accepted has no record and says nothing
		if( view.order !== null && view.order !== undefined )
			state.main.appendChild( el( 'p', { class: 'state-note', id: 'order-head' }, badge( 'portal', t( 'order.badge' ) ), ' '+ t( 'order.head', dmy( view.order.at ), recordWho( view.order ) ) ) );

		if( doc.overdue === true )
			state.main.appendChild( note( '', t( 'overdue.note', dmy( doc.due_date ) ), ' ', doc.released === true && [ 'open', 'sent' ].includes( doc.state ) === true ? el( 'button', { type: 'button', class: 'btn sm', id: 'remind', text: t( 'menu.remind' ), on: { click: function() { remind( view ) } } } ) : t( 'overdue.release' ) ) );

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
				[ t( 'document.project' ), doc.project === '' ? '' : link( '#projekt-'+ doc.project, doc.project_title !== '' ? doc.project_title : doc.project ) ],
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
		unsaver( 'notes' );
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

			if( where.client !== '' && state.clients.some( function( one ) { return String( one.id ) === where.client && one.active === true } ) === true ) {
				draft.client_id = where.client;
				draft.project = where.project || '';
			}

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
			el( 'button', { type: 'button', class: 'btn', id: 'save', title: t( 'save.shortcut' ), text: t( 'editor.save' ), on: { click: function() { save( false ) } } } ),
			el( 'button', { type: 'button', class: 'btn primary', id: 'finalise', text: t( 'editor.finalise' ), on: { click: finalise } } ),
			menu( { label : '⋯', items : [ { label : t( 'editor.preview' ), run : preview }, 'sep', { label : t( 'editor.delete' ), danger : true, run : remove } ] } ) ) );
		state.main.appendChild( message );

		// Strg+S is the button Speichern; the autosave that is running is a save that is running
		saver( 'draft', state.main, function() { save( false ) }, undefined, function() { return state.saving === true } );

		// --- the block Beleg
		const clientOptions = state.clients.filter( function( one ) { return one.active === true || String( one.id ) === draft.client_id } );
		const projectSelect = el( 'select', { id: 'f-project', value: draft.project } );
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

		/**
		 *	The projects the select offers: the client's open ones and the one the draft names. A list that came from the server is the last word - a project that is not in it
		 *	is no project of this client, and the draft names none
		 *
		 *	@param		{Array}			projects
		 *	@param		{boolean}		[settled]
		 *
		 *	@return		{void}
		 */
		const fillProjects = function( projects, settled ) {

			const found = projects.some( function( one ) { return one.id === draft.project } );

			if( settled === true && found === false )
				draft.project = '';

			clear( projectSelect );
			projectSelect.appendChild( el( 'option', { value: '', text: t( 'field.project-none' ) } ) );
			projects.filter( function( one ) { return one.done !== true || one.id === draft.project } ).forEach( function( one ) { projectSelect.appendChild( el( 'option', { value: one.id, text: one.title } ) ) } );
			projectSelect.value = draft.project;
		};

		fillProjects( view.document === null ? [] : ( view.projects || [] ) );
		bind( 'client_id', 'client_id', clientSelect );
		bind( 'project', 'project', projectSelect );
		bind( 'parent_id', 'parent_id', offerSelect );
		bind( 'template_key', 'template_key', templateSelect );
		templateSelect.addEventListener( 'change', drawInputs );

		/**
		 *	The client the draft is for now: the chosen one, for a contract the one of its offer
		 *
		 *	@return		{string}									Its id; '' for none
		 */
		const chosenClient = function() {

			const chosen = draft.category === 'contract'
				? state.offers.find( function( one ) { return String( one.id ) === draft.parent_id } )
				: null;

			return chosen === undefined || chosen === null ? draft.client_id : String( chosen.client_id );
		};

		const drawClient = function() {

			clear( clientBox );

			const id = chosenClient();
			const known = state.clients.find( function( one ) { return String( one.id ) === id } );
			const owed = el( 'span', { id: 'client-open', text: '…' } );
			const where = el( 'span', { id: 'client-address', text: '…' } );
			const who = el( 'span', { id: 'client-accounts', text: '…' } );

			clientBox.appendChild( el( 'h3', { text: t( 'block.client' ) } ) );

			if( known === undefined ) {
				fillProjects( [], true );
				clientBox.appendChild( el( 'p', { class: 'state-note', text: t( 'client.none' ) } ) );
				return;
			}

			// The projects of the client are the field Projekt's options
			api( 'crm-projekte/list', { client : id } ).then( function( answer ) {
				// The answer of a client that was left again, before it came, is for nobody: the list of the client chosen now is the one that is drawn
				if( answer.ok === true && state.view === mine && projectSelect.parentNode !== null && chosenClient() === id )
					fillProjects( answer.body.projects, true );
			} );

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

		// Another client has other projects: the draft names none until one is chosen
		const clientChanged = function() {
			draft.project = '';
			drawClient();
		};

		clientSelect.addEventListener( 'change', clientChanged );
		offerSelect.addEventListener( 'change', clientChanged );

		const rows = [
			[ t( 'document.kind' ), t( 'category.'+ draft.category ) ],
			draft.category === 'contract' ? [ t( 'field.offer' ), el( 'span', {}, offerSelect, el( 'span', { class: 'state-note', text: t( 'field.offer-follow' ) } ) ) ] : [ t( 'field.client' ), clientSelect ],
			[ t( 'field.project' ), projectSelect ],
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
				dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-due_date', text: t( 'field.due' )+ ' ('+ t( 'form.optional' )+ ')' } ) ) );
				dl.appendChild( el( 'dd', {}, bind( 'due_date', 'due_date', el( 'input', { id: 'f-due_date', type: 'date', value: draft.due_date } ) ) ) );
			}

			if( draft.category === 'offer' ) {
				dl.appendChild( el( 'dt', {}, el( 'label', { for: 'f-valid_until', text: t( 'field.valid' )+ ' ('+ t( 'form.optional' )+ ')' } ) ) );
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

			return [ el( 'dt', { class: row.control.tagName === 'TEXTAREA' ? 'top' : null }, el( 'label', { for : row.control.id, text : row.label } ) ), el( 'dd', {}, row.control, row.hint === undefined ? null : el( 'span', { class: 'state-note', text: row.hint } ), note ) ];
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

		Promise.all( [ api( 'crm-clients/get', { id : String( where.id ) } ), api( 'crm-documents/list', { client : String( where.id ) } ), api( 'crm-projekte/list', { client : String( where.id ) } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			// The projects are a tab of their own: where they cannot be read the card has the others
			const failure = answers.slice( 0, 2 ).find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			adopt( answers[1].body );

			// The link "einladen" of the Übersicht leads to the invitation: the tab Portal, the form open
			state.tab = where.tab === 'einladen' ? 'portal' : where.tab;

			drawKunde( { client : answers[0].body.client, accounts : answers[0].body.accounts, documents : answers[1].body.documents, projects : answers[2].ok === true && answers[2].body.missing !== true ? answers[2].body.projects : null, portal : null, editing : false, inviting : where.tab === 'einladen', invite : null, box : null, master : null } );
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

		if( view.editing === false ) {
			unsaver( 'master' );
			return el( 'div', { class: 'card pad', id: 'master' }, el( 'h3', { text: t( 'card.master' ) } ), kv( [
				[ t( 'client.company' ), client.company ],
				[ t( 'client.name' ), client.name ],
				[ t( 'client.email' ), client.email ],
				[ t( 'card.phone' ), client.phone ],
				[ t( 'client.address' ), stacked( addressOf( client ) ) ],
				[ t( 'client.vat' ), client.vat_id ],
				[ t( 'card.since' ), dmy( client.created ) ],
			] ) );
		}

		const form = formOf();
		const rows = CLIENT_FIELDS.map( function( name ) { return { name : name, label : t( 'clientfield.'+ name ), control : clientControl( name, client[name] ) } } );
		const save = el( 'button', { type: 'submit', class: 'btn sm primary', id: 'client-save', title: t( 'save.shortcut' ), text: t( 'form.save' ) } );

		const box = el( 'form', { class: 'card pad', id: 'master', novalidate: 'novalidate', on: { submit: function( event ) {

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

		submitter( 'master', box, save, function() { return form.dirty === true } );

		return box;
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

		if( view.inviting !== true || client.active === false )
			unsaver( 'invite' );
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

					// The box says whether the account is mailed (it is, at first); without the mail the link is shown, once
					dialog( { title : t( account.login === null ? 'acc.resend-invite' : 'acc.resend-login' ), text : quietText( 'members', account.login === null ? 'acc.resend-invite-text' : 'acc.resend-login-text' ), yes : t( 'acc.resend-yes' ),
						fields : quietField( 'members', 'notify.invite' ) } ).then( function( values ) {

						if( values === null )
							return;

						call( 'crm-clients/account-resend', Object.assign( post(), { notify : values.notify !== false } ), 'error.account-resend' ).then( function( answer ) {

							if( answer.ok !== true )
								return;

							if( typeof answer.body.link === 'string' ) {
								showLink( answer.body.kind === 'invite' ? 'invite' : 'login', answer.body.link );
								toast( t( answer.body.kind === 'invite' ? 'acc.resent-invite-quiet' : 'acc.resent-login-quiet' ) );
								return;
							}

							toast( t( answer.body.kind === 'invite' ? 'acc.resent-invite' : 'acc.resent-login' ) );
						} );
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
		const send = el( 'button', { type: 'submit', class: 'btn sm primary', id: 'invite-send', title: t( 'save.shortcut' ), text: t( 'invite.send' ) } );
		const notify = state.perms.members === true ? el( 'input', { type: 'checkbox', id: 'f-invite-notify', checked: true } ) : null;

		const box = el( 'form', { id : 'invite', novalidate : 'novalidate', on : { submit : function( event ) {

			event.preventDefault();
			send.disabled = true;

			api( 'crm-clients/invite', { id : String( view.client.id ), email : form.controls.email.value, name : form.controls.name.value, notify : notify === null || notify.checked === true } ).then( function( answer ) {

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

				if( typeof answer.body.link === 'string' ) {
					showLink( 'invite', answer.body.link );
					toast( t( 'invite.sent-quiet' ) );
					return;
				}

				toast( t( answer.body.mailed === true ? 'invite.sent' : 'invite.not-mailed' ), answer.body.mailed !== true );
			} );
		} } }, formList( rows, form ), notify === null ? el( 'p', { class: 'state-note', id: 'invite-noquiet', text: t( 'notify.noperm-members' ) } ) : el( 'label', { class: 'check' }, notify, ' '+ t( 'notify.invite' ) ), el( 'div', { class: 'actions top' }, send,
			el( 'button', { type: 'button', class: 'btn sm', id: 'invite-cancel', text: t( 'form.cancel' ), on: { click: function() { view.inviting = false; view.invite = null; dropGuard( form ); replaceAccounts( view ) } } } ) ) );

		// What is typed in it is unsaved input like that of any form: the guard of the page asks before the screen is left - unless a form that holds changes is the guard's already
		form.form = true;
		Object.keys( form.controls ).forEach( function( name ) {
			form.controls[name].addEventListener( 'input', function() {
				if( state.draft === null || state.draft.dirty !== true )
					state.draft = form;
			} );
		} );

		submitter( 'invite', box, send, function() { return form.dirty === true } );

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
	const tabClientDocuments = function( view, empty ) {

		return el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
			el( 'thead', {}, el( 'tr', {}, el( 'th', { text: t( 'column.number' ) } ), el( 'th', { text: t( 'column.category' ) } ), el( 'th', { text: t( 'column.date' ) } ), el( 'th', { class: 'r', text: t( 'column.gross' ) } ), el( 'th', { text: t( 'column.state' ) } ) ) ),
			el( 'tbody', {}, view.documents.length === 0
				? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '5', class: 'muted', text: t( empty || 'cdocs.empty' ) } ) )
				: view.documents.map( function( doc ) {
					return el( 'tr', { dataset: { id: doc.id }, on: { click: function() { wn.location.hash = hashOf( doc ) } } },
						el( 'td', { class: 'num' }, link( hashOf( doc ), docName( doc ) ) ),
						el( 'td', { text: t( 'category.'+ doc.category ) } ),
						el( 'td', { text: dmy( doc.date ) } ),
						el( 'td', { class: 'r num' }, isPlain( doc ) ? el( 'span', { class: 'muted', text: '–' } ) : cash( doc.gross ) ),
						el( 'td', {}, badges( doc ) ) );
				} ) ) ) );
	};

	/**
	 *	The projects of the client, the newest first, the done ones greyed; Neues Projekt starts one for the client
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const tabClientProjects = function( view ) {

		const client = view.client;

		if( view.projects === null )
			return note( '', t( 'projects.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) );

		return el( 'div', { id: 'client-projects' },
			state.perms.elements === true && client.active === true ? el( 'div', { class: 'actions top' }, link( '#projekte-neu-k'+ client.id, t( 'projects.new' ), { class: 'btn primary', id: 'client-project-new' } ) ) : null,
			el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl' },
				el( 'thead', {}, el( 'tr', {}, el( 'th', { text: t( 'projects.column-project' ) } ), el( 'th', { text: t( 'projects.column-started' ) } ), el( 'th', { class: 'r', text: t( 'projects.column-documents' ) } ), el( 'th', { text: t( 'column.state' ) } ) ) ),
				el( 'tbody', {}, view.projects.length === 0
					? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '4', class: 'muted', text: t( 'projects.client-empty' ) } ) )
					: view.projects.map( function( project ) {
						return el( 'tr', { dataset: { id: project.id }, class: project.done === true ? 'dim' : null, on: { click: function() { wn.location.hash = '#projekt-'+ project.id } } },
							el( 'td', { class: 'wrap' }, projectLink( project ) ),
							el( 'td', { text: dmy( project.started ) } ),
							el( 'td', { class: 'r num', text: String( project.documents ) } ),
							el( 'td', {}, projectBadge( project ) ) );
					} ) ) ) ) );
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
				Array.isArray( portal.records ) === false ? null : [
					el( 'h4', { text: t( 'cportal.records' ) } ),
					el( 'ul', { class: 'list-mini', id: 'client-records' }, portal.records.length === 0 ? el( 'li', { class: 'muted', text: t( 'cportal.records-none' ) } ) : portal.records.map( function( record ) {
						return el( 'li', { dataset: { kind: record.kind } }, el( 'span', {}, t( 'record.kind-'+ record.kind ), ' · ', stamp( record.at ), ' · ', recordWho( record ),
							record.document_id === null ? null : [ ' · ', link( '#beleg-'+ record.document_id, record.document_number !== undefined && record.document_number !== '' ? record.document_number : t( 'record.document' ) ) ] ),
							el( 'span', { class: 'r' }, el( 'button', { type: 'button', class: 'btn sm quiet', text: t( 'record.show' ), on: { click: function() { showRecord( record ) } } } ) ) );
					} ) ),
				],
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
		let running = false;

		const save = function() {

			running = true;

			call( 'crm-clients/save', clientPayload( view.client, { notes : area.value } ), 'error.client-save' ).then( function( answer ) {

				running = false;

				if( answer.ok === true ) {
					view.client = answer.body.client;
					toast( t( 'notes.done' ) );
				}
			} );
		};

		const box = card( t( 'cnotes.title' ), area, el( 'div', { class: 'actions top' }, el( 'button', { type: 'button', class: 'btn sm', id: 'notes-save', title: t( 'save.shortcut' ), text: t( 'notes.save' ), on: { click: save } } ) ) );

		saver( 'tab', box, save, function() { return area.value !== String( view.client.notes ?? '' ) }, function() { return running } );

		return box;
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

	/**
	 *	The line of the head of the Kundenkarte about the Datenschutz: agreed to the text of now - when and by whom - or still to come. Nothing where the portal asks for no text
	 *
	 *	@param		{Object}	client					With the standing as Clients::get() has it
	 *
	 *	@return		{Object|null}
	 */
	const privacyLine = function( client ) {

		const privacy = client.privacy;

		if( privacy === undefined || privacy === null || privacy.asked !== true )
			return null;

		return el( 'span', { class: 'sub', id: 'client-privacy', dataset: { agreed: privacy.agreed === true ? '1' : '0' }, text: privacy.agreed === true ? t( 'privacy.head-agreed', dmy( privacy.at ), privacy.member_name ) : t( 'privacy.head-open' ) } );
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
		state.main.appendChild( head( el( 'h1', { text: clientTitle( client ) } ), clientBadge( client ), el( 'span', { class: 'sub num', text: client.number } ), privacyLine( client ), el( 'span', { class: 'grow' } ),
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
			unsaver( 'tab' );
			body.appendChild( { belege : tabClientDocuments, projekte : tabClientProjects, portal : tabClientPortal, notizen : tabClientNotes }[key]( view ) );
		};

		const strip = el( 'div', { class: 'tabs', role: 'tablist' }, tabs.map( function( key ) {

			const button = el( 'button', { type: 'button', role: 'tab', 'aria-selected': key === start ? 'true' : 'false', class: key === start ? 'on' : '', dataset: { tab: key }, on: { click: function() {
				if( state.view === view.mine )
					show( key );
			} } }, t( 'ctab.'+ key ), key === 'belege' ? el( 'span', { class: 'n', text: String( view.documents.length ) } ) : null, key === 'projekte' && view.projects !== null ? el( 'span', { class: 'n', text: String( view.projects.length ) } ) : null );

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
		const create = el( 'button', { type: 'submit', class: 'btn primary', id: 'client-create', title: t( 'save.shortcut' ), text: t( 'newclient.create' ) } );
		const notify = state.perms.members === true ? el( 'input', { type: 'checkbox', id: 'f-account_notify', checked: true } ) : null;
		const master = formList( rows, form );
		const account = formList( first, form );

		// With an address the account is made with the client and invited: the button says so
		form.controls.account_email.addEventListener( 'input', function() {
			create.textContent = form.controls.account_email.value.trim() === '' ? t( 'newclient.create' ) : t( 'newclient.create-invite' );
		} );

		const submit = function( event ) {

			event.preventDefault();
			create.disabled = true;

			api( 'crm-clients/save', Object.assign( valuesOfForm( form ), { notify : notify === null || notify.checked === true } ) ).then( function( answer ) {

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
				else if( typeof asked.link === 'string' ) {
					showLink( 'invite', asked.link );
					toast( t( 'newclient.done-invited-quiet', made.number ) );
				}
				else
					toast( t( asked.mailed === true ? 'newclient.done-invited' : 'newclient.done-unmailed', made.number ), asked.mailed !== true );

				refresh();
				wn.location.hash = '#kunde-'+ made.id;
			} );
		};

		reveal();

		const box = el( 'form', { id: 'new-client-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'newclient.title' ), el( 'span', { class: 'grow' } ), link( '#kunden', t( 'form.cancel' ), { class: 'btn', id: 'client-abort' } ), create ),
			el( 'div', { class: 'cols' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'newclient.master' ) } ), master ),
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'newclient.first' ) } ), el( 'p', { class: 'state-note', text: t( 'newclient.first-text' ) } ), account, notify === null ? el( 'p', { class: 'state-note', id: 'account-noquiet', text: t( 'notify.noperm-members' ) } ) : el( 'label', { class: 'check' }, notify, ' '+ t( 'notify.invite' ) ) ) ),
			el( 'datalist', { id: 'countries' }, [ 'DE', 'AT', 'CH' ].map( function( code ) { return el( 'option', { value: code } ) } ) ) );

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#kunden', t( 'clients.title' ) ), ' › '+ t( 'newclient.title' ).toLowerCase() ) );
		state.main.appendChild( box );
		submitter( 'new', box, create );

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

	/**
	 *	The languages of the site, the native one first: the order of the cards of the editor and of its posts
	 *
	 *	@param		{Array}		locales
	 *	@param		{string}	native
	 *
	 *	@return		{Array}
	 */
	const nativeFirst = function( locales, native ) {
		return ( locales.includes( native ) === true ? [ native ] : [] ).concat( locales.filter( function( locale ) { return locale !== native } ) );
	};

	/**
	 *	The items the table shows: those whose titles (in every language) or units hold the search, in the order of the column
	 *
	 *	@param		{Array}		items						As crm-documents/positionen has them
	 *	@param		{Object}	filter					q, sort, dir
	 *	@param		{string}	native
	 *
	 *	@return		{Array}
	 */
	const visibleItems = function( items, filter, native ) {

		const query = filter.q.trim().toLowerCase();
		const keys = {
			title : function( item ) { return ( item.words[native] || { title : '' } ).title.toLowerCase() }, price : function( item ) { return item.price }, vat : function( item ) { return item.vat },
			active : function( item ) { return item.active === true ? 1 : 0 }, sort : function( item ) { return item.sort === null ? 0 : item.sort },
		};
		const key = keys[filter.sort] || keys.sort;

		const rows = items.filter( function( item ) {
			return query === '' || Object.keys( item.words ).some( function( locale ) { return ( item.words[locale].title+ ' '+ item.words[locale].unit ).toLowerCase().includes( query ) } );
		} );

		rows.sort( function( a, b ) {

			const left = key( a );
			const right = key( b );
			const order = typeof left === 'string' ? left.localeCompare( right, 'de' ) : left - right;

			return order !== 0 ? order * filter.dir : ( ( a.sort || 0 ) - ( b.sort || 0 ) || String( a.id ).localeCompare( String( b.id ) ) );
		} );

		return rows;
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
			state.pbody = answer.body;
			drawPositionen();
		} );
	};

	const drawPositionen = function() {

		const body = state.pbody;
		const filter = state.ifilter;
		const small = state.settings.vat_mode === 'small_business';
		const edits = state.perms.elements === true;
		const native = body.native;

		reveal();

		// An account with the permission of the Elements panel makes, changes and removes the items here; the panel stays the second place. One without it sees the table
		state.main.appendChild( head( t( 'items.title' ), el( 'span', { class: 'sub', text: t( edits === true ? 'items.sub-edit' : 'items.sub' ) } ), el( 'span', { class: 'grow' } ),
			body.missing === true ? null : search( t( 'items.search' ), filter.q, { input: function( event ) {
				filter.q = event.target.value;
				filter.page = 1;
				keepSearch( drawPositionen, true );
			} } ),
			link( state.links.items, t( edits === true ? 'items.panel' : 'items.edit' ), { class: 'btn', id: 'items-edit', target : '_blank', rel : 'noopener' } ),
			edits === true ? link( '#positionen-neu', t( 'items.new' ), { class: 'btn primary', id: 'items-new' } ) : null ) );

		if( body.missing === true ) {
			state.main.appendChild( note( '', t( 'items.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) ) );
			return;
		}

		const rows = visibleItems( body.items, filter, native );
		const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );

		filter.page = Math.min( Math.max( 1, filter.page ), pages );

		const shown = rows.slice( ( filter.page - 1 ) * PAGE_SIZE, filter.page * PAGE_SIZE );
		const again = function() { drawPositionen() };
		const heading = function( key, label, right ) { return sortHead( filter, key, label, right, again, 1 ) };

		const words = function( item, locale ) {
			const one = item.words[locale] || { title : '', description : '', unit : '' };
			const title = edits === true && locale === native ? link( '#position-'+ item.id, one.title !== '' ? one.title : item.id ) : one.title;
			return [ title, one.description !== '' ? el( 'div', { class: 'muted small' }, one.description.split( '\n' ).join( ' · ' ) ) : null ];
		};

		state.main.appendChild( el( 'div', { class: 'card wrap-x' }, el( 'table', { class: edits === true ? 'tbl' : 'tbl static', id: 'items' },
			el( 'thead', {}, el( 'tr', {}, body.locales.map( function( locale ) {
					return locale === native ? heading( 'title', t( 'items.title-in', languageOf( locale ) ) ) : el( 'th', { text: t( 'items.title-in', languageOf( locale ) ) } );
				} ),
				el( 'th', { text: t( 'items.unit' ) } ), heading( 'price', t( 'items.net' ), true ), heading( 'vat', t( 'items.vat' ), true ), heading( 'active', t( 'items.active' ) ), heading( 'sort', t( 'items.f-sort' ), true ) ) ),
			el( 'tbody', {}, shown.length === 0
				? el( 'tr', { class: 'plain' }, el( 'td', { colspan: String( body.locales.length + 5 ), class: 'muted', text: t( body.items.length > 0 ? 'items.none' : ( edits === true ? 'items.empty-edit' : 'items.empty' ) ) } ) )
				: shown.map( function( item ) {
					return el( 'tr', { dataset: { id: item.id }, on: edits === true ? { click: function() { wn.location.hash = '#position-'+ item.id } } : {} },
						body.locales.map( function( locale ) { return el( 'td', { class: 'wrap' }, words( item, locale ) ) } ),
						el( 'td', { text: units( item, body.locales ) } ),
						el( 'td', { class: 'r num', text: cash( item.price ) } ),
						el( 'td', { class: 'r num', text: small ? '–' : money().formatRate( item.vat ) } ),
						el( 'td', { text: t( item.active === true ? 'items.yes' : 'items.no' ) } ),
						el( 'td', { class: 'r num', text: item.sort === null ? '' : String( item.sort ) } ) );
				} ) ) ) ) );
		append( state.main, [ pagerOf( filter, pages, again ), el( 'p', { class: 'state-note top', text: t( edits === true ? 'items.note-edit' : 'items.note' ) } ) ] );
	};

	// --- Position: the editor of an item -----------------------------------------------------------------------------
	// The surface drives the Elements panel's own actions for the type positionen: elements/get reads an item, elements/save writes one language
	// per post (the native language with the numbers first), elements/delete removes it. The list of the items and the languages come from
	// crm-documents/positionen, which the screen of the table reads as well.

	const POSITION_TYPE = 'positionen';
	const POSITION_WORDS = [ 'title', 'description', 'unit' ];
	const POSITION_TRIES = 9;

	/**
	 *	The name of an item (the last part of its uri) from its title: lower case words, umlauts spelled out, a dash between them
	 *
	 *	@param		{string}	title
	 *	@param		{number}	[attempt]				1 for the first name, 2 and on for the names after a name that was taken
	 *
	 *	@return		{string}									mueller-angebot; -2, -3 ... on a later attempt
	 */
	const positionKey = function( title, attempt ) {

		const umlauts = { 'ä' : 'ae', 'ö' : 'oe', 'ü' : 'ue', 'ß' : 'ss' };
		let slug = String( title ).toLowerCase().replace( /[äöüß]/g, function( char ) { return umlauts[char] } ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 40 ).replace( /-+$/, '' );

		if( slug === '' )
			slug = 'position';

		return attempt === undefined || attempt < 2 ? slug : slug+ '-'+ attempt;
	};

	/**
	 *	@param		{string}	locale					de_DE
	 *
	 *	@return		{string}									Deutsch where the browser knows the name of the language, else DE
	 */
	const languageName = function( locale ) {

		const code = languageOf( locale );

		try {
			return new wn.Intl.DisplayNames( [ 'de' ], { type : 'language' } ).of( code ) || code.toUpperCase();
		}
		catch( e ) {
			return code.toUpperCase();
		}
	};

	/**
	 *	@param		{number}	euros
	 *
	 *	@return		{string}									90,50
	 */
	const priceText = function( euros ) { return money().format( Math.round( euros * 100 ), '' ) };

	/**
	 *	@param		{number}	percent
	 *
	 *	@return		{string}									19 and 7,5
	 */
	const percentText = function( percent ) { return money().formatRate( Math.round( percent * 100 ) ).replace( / %$/, '' ) };

	/**
	 *	What the editor starts from
	 *
	 *	@param		{Object|null}	got					The answer of elements/get; null for a new item
	 *	@param		{Array}				locales
	 *	@param		{Object}			defaults		vat and sort of a new item
	 *
	 *	@return		{Object}										words (locale => title, description, unit), price, vat, sort as typed, and active
	 */
	const positionDraftOf = function( got, locales, defaults ) {

		const shared = got === null ? {} : got.global || {};
		const words = {};

		locales.forEach( function( locale ) {

			const one = got === null ? {} : ( got.locales || {} )[locale] || {};
			words[locale] = {};
			POSITION_WORDS.forEach( function( name ) { words[locale][name] = typeof one[name] === 'string' ? one[name] : '' } );
		} );

		return {
			words : words,
			price : typeof shared.price === 'number' ? priceText( shared.price ) : '',
			vat : typeof shared.vat === 'number' ? percentText( shared.vat ) : ( got === null ? defaults.vat : '' ),
			active : got === null ? true : shared.hidden !== true,
			sort : Number.isInteger( shared.sort ) ? String( shared.sort ) : ( got === null ? defaults.sort : '' ),
		};
	};

	/**
	 *	What a save of the editor posts, in the order it posts it, or what keeps it back. The panel saves one language per post; the first post is the
	 *	native language and carries the numbers (and creates a new item), then each other language whose words were changed. A new item posts everything it
	 *	has; a change posts only what was changed - the words of a language and the shared values the person touched - so that an account that may change
	 *	some fields is not refused for the ones it left alone, and a value another place left out is not made up. The title is required in the native
	 *	language of a new item and in every language whose words are written: the model asks for it of each
	 *
	 *	@param		{Object}	read						What the controls hold: words, price, vat, sort as typed, active
	 *	@param		{Object}	base						What the editor started from: words (locale => title, description, unit), price, vat, sort, active
	 *	@param		{Array}		locales					The native language first
	 *	@param		{boolean}	isNew						Whether the item is made by this save
	 *
	 *	@return		{Object}									problems ([ { name, key } ]: the control and the word of the sentence) and posts ([ { locale, fields } ])
	 */
	const positionPosts = function( read, base, locales, isNew ) {

		const problems = [];
		const posts = [];
		const clean = function( one ) { return { title : one.title.trim(), description : one.description.trim(), unit : one.unit.trim() } };
		const changed = {};
		const touched = function( name ) { return isNew === true || String( read[name] ).trim() !== String( base[name] ).trim() };

		locales.forEach( function( locale, index ) {

			const words = clean( read.words[locale] );
			const before = clean( base.words[locale] );
			changed[locale] = POSITION_WORDS.filter( function( name ) { return words[name] !== before[name] } );

			if( words.title === '' && ( ( isNew === true && index === 0 ) || changed[locale].length > 0 ) )
				problems.push( { name : 'title.'+ locale, key : index === 0 ? 'items.err-title' : 'items.err-title-lang' } );
		} );

		const cents = money().parse( read.price.trim() );
		const rate = read.vat.trim() === '' ? 0 : money().parse( read.vat.trim() );
		const sort = read.sort.trim() === '' ? 0 : ( /^-?[0-9]{1,9}$/.test( read.sort.trim() ) === true ? Number( read.sort.trim() ) : null );

		if( touched( 'price' ) === true && ( cents === null || cents < 0 ) )
			problems.push( { name : 'price', key : 'items.err-price' } );

		if( touched( 'vat' ) === true && ( rate === null || rate < 0 || rate > 10000 ) )
			problems.push( { name : 'vat', key : 'items.err-vat' } );

		if( touched( 'sort' ) === true && sort === null )
			problems.push( { name : 'sort', key : 'items.err-sort' } );

		if( problems.length > 0 )
			return { problems : problems, posts : [] };

		locales.forEach( function( locale, index ) {

			const words = clean( read.words[locale] );
			const fields = {};

			if( index > 0 && changed[locale].length === 0 )
				return;

			POSITION_WORDS.forEach( function( name ) {
				if( isNew === true || changed[locale].includes( name ) === true )
					fields[name] = words[name];
			} );

			if( index === 0 ) {
				if( touched( 'price' ) === true )
					fields.price = cents / 100;

				if( touched( 'vat' ) === true )
					fields.vat = rate / 100;

				if( isNew === true || read.active !== base.active )
					fields.hidden = read.active !== true;

				if( touched( 'sort' ) === true )
					fields.sort = sort;
			}

			if( Object.keys( fields ).length > 0 )
				posts.push( { locale : locale, fields : fields } );
		} );

		return { problems : [], posts : posts };
	};

	/**
	 *	The screen of one item: #position-<uri>, or #positionen-neu for a new one
	 *
	 *	@param		{Object}	where						uri, absent for a new item
	 *	@param		{number}	mine						The view that draws
	 *
	 *	@return		{void}
	 */
	const position = function( where, mine ) {

		if( state.perms.elements !== true ) {
			reveal();
			state.main.appendChild( head( t( 'items.title' ) ) );
			state.main.appendChild( note( 'bad', t( 'items.forbidden' ) ) );
			return;
		}

		loading();

		Promise.all( [ api( 'crm-documents/positionen' ), where.uri === undefined ? Promise.resolve( null ) : api( 'elements/get', { type : POSITION_TYPE, uri : where.uri } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			if( answers[0].ok !== true ) {
				loadFailed( answers[0], 'error.load', function() { route() } );
				return;
			}

			const list = answers[0].body;

			if( answers[1] !== null && answers[1].ok !== true && answers[1].status !== 404 ) {
				loadFailed( answers[1], 'error.load', function() { route() } );
				return;
			}

			reveal();

			if( list.missing === true || ( answers[1] !== null && answers[1].ok !== true ) ) {
				state.main.appendChild( head( t( 'items.title' ) ) );
				state.main.appendChild( note( list.missing === true ? '' : 'bad', list.missing === true ? [ t( 'items.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) ] : [ el( 'span', { text: t( 'items.gone' ) } ), ' ', link( '#positionen', t( 'items.back' ) ) ] ) );
				return;
			}

			drawPosition( list, answers[1] === null ? null : answers[1].body, where.uri );
		} );
	};

	/**
	 *	The editor: a card for each language of the site (the native one first), one for what is the same in all of them, Speichern, and the menu with Löschen
	 *
	 *	@param		{Object}				list				The answer of crm-documents/positionen: locales, native, items, settings
	 *	@param		{Object|null}		got					The answer of elements/get; null for a new item
	 *	@param		{string}				[given]			The uri of an item that is there
	 *
	 *	@return		{void}
	 */
	const drawPosition = function( list, got, given ) {

		const mine = state.view;
		const locales = nativeFirst( list.locales, list.native );
		const rates = Array.isArray( list.settings.rates ) === true ? list.settings.rates : [];
		const defaults = {
			vat : list.settings.vat_mode === 'small_business' ? '0' : ( rates.length > 0 ? percentText( Math.max( ...rates ) / 100 ) : '' ),
			sort : String( list.items.reduce( function( top, item ) { return Math.max( top, item.sort === null ? 0 : item.sort ) }, 0 ) + 1 ),
		};
		const draft = positionDraftOf( got, locales, defaults );
		const form = formOf();
		const taken = list.items.map( function( item ) { return String( item.id ).toLowerCase() } );
		const heading = given === undefined ? t( 'items.new-title' ) : ( draft.words[locales[0]].title !== '' ? draft.words[locales[0]].title : given );
		let uri = given;
		let saving = false;

		const cardOf = function( locale, index ) {

			const set = draft.words[locale];
			const rows = [
				{ name : 'title.'+ locale, label : t( 'items.f-title' ), control : el( 'input', { id: 'f-title-'+ locale, type: 'text', value: set.title, maxlength: '200', autocomplete: 'off' } ), hint : index === 0 ? undefined : t( 'items.hint-title-lang' ) },
				{ name : 'description.'+ locale, label : t( 'items.f-description' ), control : el( 'textarea', { id: 'f-description-'+ locale, rows: 4, maxlength: '2000', value: set.description } ) },
				{ name : 'unit.'+ locale, label : t( 'items.f-unit' ), control : el( 'input', { id: 'f-unit-'+ locale, type: 'text', value: set.unit, maxlength: '20', autocomplete: 'off', class: 'short' } ), hint : t( 'items.hint-unit' ) },
			];

			return el( 'div', { class: 'card pad', id: 'position-lang-'+ locale, dataset: { locale: locale } }, el( 'h3', { text: index === 0 ? t( 'items.card-native', languageName( locale ) ) : languageName( locale ) } ), formList( rows, form ) );
		};

		const shared = el( 'div', { class: 'card pad', id: 'position-shared' }, el( 'h3', { text: t( 'items.card-shared' ) } ), formList( [
			{ name : 'price', label : t( 'items.f-price' ), control : el( 'input', { id: 'f-price', type: 'text', value: draft.price, inputmode: 'decimal', autocomplete: 'off', class: 'short' } ), hint : t( 'items.hint-price' ) },
			{ name : 'vat', label : t( 'items.f-vat' ), control : el( 'input', { id: 'f-vat', type: 'text', value: draft.vat, inputmode: 'decimal', autocomplete: 'off', class: 'short' } ), hint : t( 'items.hint-vat' ) },
			{ name : 'active', label : t( 'items.f-active' ), control : el( 'input', { id: 'f-active', type: 'checkbox', role: 'switch', checked: draft.active } ), hint : t( 'items.hint-active' ) },
			{ name : 'sort', label : t( 'items.f-sort' ), control : el( 'input', { id: 'f-sort', type: 'text', value: draft.sort, inputmode: 'numeric', autocomplete: 'off', class: 'short' } ), hint : t( 'items.hint-sort' ) },
		], form ) );

		const uriNote = el( 'span', { class: 'sub num', id: 'position-uri', text: uri === undefined ? '' : '/'+ POSITION_TYPE+ '/'+ uri } );
		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'position-save', title: t( 'save.shortcut' ), text: t( 'form.save' ) } );

		/**
		 *	Put the sentence of a refusal beside a control
		 *
		 *	@param		{string}	name
		 *	@param		{string}	sentence
		 *
		 *	@return		{boolean}									Whether there is a control of that name
		 */
		const mark = function( name, sentence ) {

			if( form.controls[name] === undefined )
				return false;

			form.controls[name].classList.add( 'err' );
			form.controls[name].setAttribute( 'aria-invalid', 'true' );
			form.notes[name].textContent = sentence;

			return true;
		};

		const read = function() {

			const values = { words : {}, price : form.controls.price.value, vat : form.controls.vat.value, sort : form.controls.sort.value, active : form.controls.active.checked };

			locales.forEach( function( locale ) {
				values.words[locale] = {};
				POSITION_WORDS.forEach( function( name ) { values.words[locale][name] = form.controls[name+ '.'+ locale].value } );
			} );

			return values;
		};

		const remove = function() {

			ask( { title : t( 'items.delete' ), text : t( 'items.delete-text', heading ), yes : t( 'items.delete' ), danger : true } ).then( function( yes ) {

				if( yes !== true || state.view !== mine )
					return;

				call( 'elements/delete', { type : POSITION_TYPE, uri : uri }, 'items.error-delete' ).then( function( answer ) {
					if( answer.ok === true && state.view === mine ) {
						toast( t( 'items.deleted' ) );
						wn.location.hash = '#positionen';
					}
				} );
			} );
		};

		const submit = function( event ) {

			event.preventDefault();

			if( saving === true )
				return;

			Object.keys( form.controls ).forEach( function( name ) {
				form.controls[name].classList.remove( 'err' );
				form.controls[name].removeAttribute( 'aria-invalid' );
				form.notes[name].textContent = '';
			} );

			const typed = read();
			const made = positionPosts( typed, draft, locales, uri === undefined );
			let attempt = 1;

			// The name of a new item is made of its title once, at the first save; a name that is taken is left for the next: -2, -3, ... up to nine
			const free = function() {

				while( taken.includes( positionKey( typed.words[locales[0]].title, attempt ) ) === true && attempt < POSITION_TRIES )
					attempt++;

				return taken.includes( positionKey( typed.words[locales[0]].title, attempt ) ) === false;
			};

			if( made.problems.length === 0 && uri === undefined && free() === false )
				made.problems.push( { name : 'title.'+ locales[0], key : 'items.err-uri' } );

			if( made.problems.length > 0 ) {

				made.problems.forEach( function( problem ) { mark( problem.name, t( problem.key ) ) } );
				form.controls[made.problems[0].name].focus();
				toast( t( made.problems[0].key ), true );
				return;
			}

			// Nothing was changed: nothing is posted, and the item is read again
			if( made.posts.length === 0 ) {
				route();
				return;
			}

			// While the posts go out, a way out of the screen waits for them (see route())
			let release = function() {};

			state.busy = new Promise( function( resolve ) { release = resolve } );
			saving = true;
			save.disabled = true;

			const over = function() {
				saving = false;
				save.disabled = false;
				state.busy = null;
				release();
			};

			// Where a refusal belongs: the panel names the posted key (hidden, title ...), the control is the one of the language that was being saved. An answer
			// that comes after the screen was left is said in the toast all the same: the item may be half saved, and the person is to know
			const refuse = function( answer, locale ) {

				const body = answer.body !== null && typeof answer.body === 'object' ? answer.body : null;

				// The keys of the model are the panel's words: the sentence names the fields as the screen does
				if( body !== null && body.code === 'elements_fields_not_allowed' && Array.isArray( body.params ) === true )
					body.params = body.params.map( function( names ) {
						return String( names ).split( ', ' ).map( function( name ) { return typeof state.texts['items.k-'+ name] === 'string' ? t( 'items.k-'+ name ) : name } ).join( ', ' );
					} );

				if( body !== null && typeof body.field === 'string' )
					body.field = body.field === 'hidden' ? 'active' : ( form.controls[body.field+ '.'+ locale] !== undefined ? body.field+ '.'+ locale : body.field );

				refuseAt( form, answer, 'items.error-save' );
			};

			const send = function( index ) {

				const one = made.posts[index];
				const first = index === 0 && uri === undefined;

				if( first === true )
					uri = positionKey( typed.words[locales[0]].title, attempt );

				api( 'elements/save', { type : POSITION_TYPE, uri : uri, locale : one.locale, isNew : first, fields : one.fields } ).then( function( answer ) {

					if( answer.ok !== true ) {

						// The name was free when the list was read; another place may have taken it since: read the list again, and try the next name
						if( first === true && answer.status === 400 && ( answer.body === null || typeof answer.body.code !== 'string' ) && attempt < POSITION_TRIES ) {

							const tried = uri;
							uri = undefined;

							api( 'crm-documents/positionen' ).then( function( again ) {

								if( again.ok === true )
									taken.splice( 0, taken.length, ...again.body.items.map( function( item ) { return String( item.id ).toLowerCase() } ) );

								if( taken.includes( tried ) === true && free() === true ) {
									send( index );
									return;
								}

								refuse( answer, one.locale );
								over();
							} );
							return;
						}

						// A later language was refused after the item was made: the item is there now, and the next save changes it
						if( first === true )
							uri = undefined;

						refuse( answer, one.locale );
						over();

						return;
					}

					if( index === 0 )
						uriNote.textContent = '/'+ POSITION_TYPE+ '/'+ uri;

					if( index + 1 < made.posts.length ) {
						send( index + 1 );
						return;
					}

					over();

					if( state.view !== mine )
						return;

					toast( t( 'items.saved' ) );

					if( given === undefined )
						wn.location.hash = '#position-'+ uri;
					else
						route();
				} );
			};

			send( 0 );
		};

		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#positionen', t( 'items.title' ) ), ' › ', heading ) );
		const box = el( 'form', { id: 'position-form', novalidate: 'novalidate', on: { submit: submit } },
			head( heading, uriNote, el( 'span', { class: 'grow' } ), given === undefined ? null : menu( { label : '⋯', aria : t( 'items.more' ), items : [ { label : t( 'items.delete' ), danger : true, run : remove } ] } ), save ),
			el( 'div', { class: 'tpl-grid' }, el( 'div', { class: 'stack' }, locales.map( cardOf ) ), shared ) );

		state.main.appendChild( box );
		submitter( 'position', box, save );
	};


	// --- Projekte ----------------------------------------------------------------------------------------------------
	// A project is an element of the type projekte. The surface drives the Elements panel's own actions for it (elements/save makes one - the uri of a
	// new one is the number the type gives it, so none is posted - and changes it, elements/delete removes it) and the Files panel's for its folder
	// (files/folder/add, files/folder/save, files/upload ...); crm-projekte/list and crm-projekte/get read it with its counts and its files.

	const PROJECT_TYPE = 'projekte';
	const PROJECT_TABS = [ 'belege', 'dateien' ];
	const FOLDER_NAME = 80;

	/**
	 *	The group of a client in Members, as Clients::group() writes it: its number as a slug
	 *
	 *	@param		{string}	number					K-0001
	 *
	 *	@return		{string}									k-0001
	 */
	const groupOf = function( number ) {
		return String( number ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
	};

	/**
	 *	The cut of a file: the chunks a size is divided into, every one but the last exactly a chunk long. As the Files panel's own script cuts
	 *
	 *	@param		{number}	size
	 *	@param		{number}	chunk						The bytes of a chunk
	 *
	 *	@return		{Array}										[ [ start, end ], ... ], end not included - none for a size or a chunk that is no whole number above 0
	 */
	const chunksOf = function( size, chunk ) {

		const parts = [];

		if( Number.isInteger( size ) === false || Number.isInteger( chunk ) === false || size < 1 || chunk < 1 )
			return parts;

		for( let start = 0; start < size; start += chunk )
			parts.push( [ start, Math.min( start + chunk, size ) ] );

		return parts;
	};

	// The smallest chunk an upload is cut into when the web server refuses a request with 413: 128 KiB
	const CHUNK_FLOOR = 131072;

	// The chunk the uploads of this page found the web server takes, in bytes - 0 while no request was refused with 413
	let workingChunk = 0;

	/**
	 *	The chunk to try after a request of the chunk's size was answered 413 (the web server's, or the endpoint's post_too_large): half of it, not
	 *	below the floor. As the Files panel's own script halves
	 *
	 *	@param		{number}	chunk						The bytes of the chunk that was refused - the length it really had, which is the file's for a file shorter than the setting
	 *
	 *	@return		{number}									The smaller chunk; 0 when the chunk is at the floor already
	 */
	const halveChunk = function( chunk ) {
		return Number.isInteger( chunk ) === true && chunk > CHUNK_FLOOR ? Math.max( CHUNK_FLOOR, Math.floor( chunk / 2 ) ) : 0;
	};

	/**
	 *	@param		{number}	bytes
	 *
	 *	@return		{string}									1,5 MB
	 */
	const sizeText = function( bytes ) {

		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let value = bytes;
		let at = 0;

		while( value >= 1024 && at < units.length - 1 ) {
			value /= 1024;
			at++;
		}

		return ( at === 0 || value >= 10 ? String( Math.round( value ) ) : String( Math.round( value * 10 ) / 10 ).replace( '.', ',' ) )+ ' '+ units[at];
	};

	/**
	 *	The projects the list shows: the done ones only where the switch is on, then the search over the title, the client and its number
	 *
	 *	@param		{Array}		projects				As crm-projekte/list has them, the newest first
	 *	@param		{Object}	filter					q, all (the done ones too)
	 *
	 *	@return		{Array}
	 */
	const visibleProjects = function( projects, filter ) {

		const query = filter.q.trim().toLowerCase();

		return projects.filter( function( project ) {
			return ( filter.all === true || project.done === false ) && ( query === '' || ( project.title+ ' '+ project.client_title+ ' '+ project.client ).toLowerCase().includes( query ) );
		} );
	};

	const projectBadge = function( project ) {
		return project.done === true ? badge( 'draft', t( 'projects.done' ) ) : badge( 'open', t( 'projects.running' ) );
	};

	const projectName = function( project ) {
		return project.title !== '' ? project.title : project.id;
	};

	const projectLink = function( project ) {
		return link( '#projekt-'+ project.id, projectName( project ) );
	};

	/**
	 *	Save fields of a project through the Elements panel's own action; a new one is posted without a uri and gets the next number of the type
	 *
	 *	@param		{string}	uri							'' for a new project
	 *	@param		{string}	native					The language the site is written in
	 *	@param		{Object}	fields
	 *
	 *	@return		{Promise}
	 */
	const projectSave = function( uri, native, fields ) {
		return api( 'elements/save', { type : PROJECT_TYPE, uri : uri, locale : native, isNew : uri === '', fields : fields } );
	};

	const projekte = function( where, mine ) {

		loading();

		api( 'crm-projekte/list' ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			state.jbody = answer.body;
			drawProjekte();
		} );
	};

	const drawProjekte = function() {

		const body = state.jbody;
		const filter = state.jfilter;
		const edits = state.perms.elements === true;
		const rows = body.missing === true ? [] : visibleProjects( body.projects, filter );
		const pages = Math.max( 1, Math.ceil( rows.length / PAGE_SIZE ) );

		filter.page = Math.min( Math.max( 1, filter.page ), pages );

		const shown = rows.slice( ( filter.page - 1 ) * PAGE_SIZE, filter.page * PAGE_SIZE );
		const again = function() { drawProjekte() };

		reveal();

		state.main.appendChild( head( t( 'projects.title' ), el( 'span', { class: 'sub', text: t( 'projects.count', rows.length, body.projects.length ) } ), el( 'span', { class: 'grow' } ),
			body.missing === true ? null : search( t( 'projects.search' ), filter.q, { input: function( event ) {
				filter.q = event.target.value;
				filter.page = 1;
				keepSearch( drawProjekte, true );
			} } ),
			body.missing === true ? null : el( 'label', { class: 'sw', for: 'p-all' }, el( 'input', { type: 'checkbox', role: 'switch', id: 'p-all', checked: filter.all, on: { change: function( event ) {
				filter.all = event.target.checked;
				filter.page = 1;
				again();
			} } } ), el( 'span', { text: t( 'projects.show-done' ) } ) ),
			edits === true && body.missing !== true ? link( '#projekte-neu', t( 'projects.new' ), { class: 'btn primary', id: 'projects-new' } ) : null ) );

		if( body.missing === true ) {
			state.main.appendChild( note( '', t( 'projects.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) ) );
			return;
		}

		state.main.appendChild( el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl', id: 'projects' },
			el( 'thead', {}, el( 'tr', {}, el( 'th', { scope: 'col', text: t( 'projects.column-project' ) } ), el( 'th', { scope: 'col', text: t( 'column.client' ) } ), el( 'th', { scope: 'col', text: t( 'projects.column-started' ) } ),
				el( 'th', { scope: 'col', class: 'r', text: t( 'projects.column-documents' ) } ), el( 'th', { scope: 'col', class: 'r', text: t( 'projects.column-files' ) } ), el( 'th', { scope: 'col', text: t( 'column.state' ) } ) ) ),
			el( 'tbody', {}, shown.length === 0
				? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '6', class: 'muted', text: t( body.projects.length === 0 ? 'projects.empty' : 'projects.none' ) } ) )
				: shown.map( function( project ) {
					return el( 'tr', { dataset: { id: project.id }, class: project.done === true ? 'dim' : null, on: { click: function() { wn.location.hash = '#projekt-'+ project.id } } },
						el( 'td', { class: 'wrap' }, projectLink( project ) ),
						el( 'td', {}, project.client_id === 0 ? project.client : link( '#kunde-'+ project.client_id, project.client_title, { on: { click: function( event ) { event.stopPropagation() } } } ) ),
						el( 'td', { text: dmy( project.started ) } ),
						el( 'td', { class: 'r num' }, project.documents === 0 ? el( 'span', { class: 'muted', text: '–' } ) : project.documents+ '', project.open > 0 ? el( 'span', { class: 'muted', text: ' · '+ t( 'projects.open-offers', project.open ) } ) : null ),
						el( 'td', { class: 'r num' }, project.files === null ? el( 'span', { class: 'muted', text: '–' } ) : String( project.files ) ),
						el( 'td', {}, projectBadge( project ) ) );
				} ) ) ) ) );
		append( state.main, [ pagerOf( filter, pages, again ), el( 'p', { class: 'state-note top', text: t( edits === true ? 'projects.note-edit' : 'projects.note' ) } ) ] );
	};

	/**
	 *	The screen of a new project: #projekte-neu, or #projekte-neu-k<id> with the client chosen
	 *
	 *	@param		{Object}	where						client (the id of the client), absent or '' for none
	 *	@param		{number}	mine
	 *
	 *	@return		{void}
	 */
	const projektNeu = function( where, mine ) {

		if( state.perms.elements !== true ) {
			reveal();
			state.main.appendChild( head( t( 'projects.title' ) ) );
			state.main.appendChild( note( 'bad', t( 'projects.forbidden' ) ) );
			return;
		}

		loading();

		Promise.all( [ api( 'crm-clients/list' ), api( 'crm-projekte/list', { open : true } ) ] ).then( function( answers ) {

			if( state.view !== mine )
				return;

			const failure = answers.find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			if( answers[1].body.missing === true ) {
				reveal();
				state.main.appendChild( head( t( 'projects.title' ) ) );
				state.main.appendChild( note( '', t( 'projects.missing' ), ' ', link( '#einstellungen', t( 'items.missing-link' ) ) ) );
				return;
			}

			drawProjektNeu( answers[0].body.clients.filter( function( client ) { return client.active === true } ), answers[1].body, where.client || '', mine );
		} );
	};

	/**
	 *	Make a folder for a project in Files and write its id into the project. Both steps are the panels' own actions; each says whether it went through
	 *
	 *	@param		{string}	id							The project
	 *	@param		{string}	title
	 *	@param		{string}	number					The number of its client
	 *	@param		{string}	native
	 *
	 *	@return		{Promise}									{ ok, folder (the id), text (the sentence that says why not) }
	 */
	const makeFolder = function( id, title, number, native ) {

		return api( 'files/folder/add', { group : groupOf( number ), name : title.slice( 0, FOLDER_NAME ) } ).then( function( made ) {

			if( made.ok !== true )
				return { ok : false, folder : '', text : errorText( made, 'projects.error-folder' ) };

			const folder = made.body.folder.id;

			return projectSave( id, native, { folder : folder } ).then( function( saved ) {
				return saved.ok === true ? { ok : true, folder : folder, text : '' } : { ok : false, folder : folder, text : errorText( saved, 'projects.error-save' ) };
			} );
		} );
	};

	const drawProjektNeu = function( clients, list, preset, mine ) {

		const form = formOf();
		const files = list.allowed === true && list.files === true;
		const known = clients.some( function( client ) { return String( client.id ) === preset } );
		const clientSelect = el( 'select', { id: 'f-client', value: known ? preset : '' }, el( 'option', { value: '', text: t( 'field.client-choose' ) } ), clients.map( function( client ) { return el( 'option', { value: String( client.id ), text: client.number+ ' · '+ clientTitle( client ) } ) } ) );
		const folder = files ? el( 'input', { type: 'checkbox', role: 'switch', id: 'f-folder', checked: true } ) : null;
		const create = el( 'button', { type: 'submit', class: 'btn primary', id: 'project-create', title: t( 'save.shortcut' ), text: t( 'projects.create' ) } );
		const rows = [
			{ name : 'title', label : t( 'projects.f-title' ), control : el( 'input', { id: 'f-title', type: 'text', maxlength: '200', autocomplete: 'off' } ) },
			{ name : 'client', label : t( 'projects.f-client' ), control : clientSelect },
			{ name : 'description', label : t( 'projects.f-description' ), control : el( 'textarea', { id: 'f-description', rows: 4, maxlength: '2000' } ), hint : t( 'form.optional' ) },
			{ name : 'started', label : t( 'projects.f-started' ), control : el( 'input', { id: 'f-started', type: 'date' } ), hint : t( 'form.optional' ) },
		];

		if( folder !== null )
			rows.push( { name : 'folder', label : t( 'projects.f-folder' ), control : folder, hint : t( 'projects.hint-folder' ) } );

		let creating = false;

		const submit = function( event ) {

			event.preventDefault();

			if( creating === true )
				return;

			Object.keys( form.controls ).forEach( function( name ) {
				form.controls[name].classList.remove( 'err' );
				form.controls[name].removeAttribute( 'aria-invalid' );
				form.notes[name].textContent = '';
			} );

			const title = form.controls.title.value.trim();
			const client = clients.find( function( one ) { return String( one.id ) === form.controls.client.value } );
			const problem = title === '' ? [ 'title', 'projects.err-title' ] : ( client === undefined ? [ 'client', 'projects.err-client' ] : null );

			if( problem !== null ) {
				form.controls[problem[0]].classList.add( 'err' );
				form.controls[problem[0]].setAttribute( 'aria-invalid', 'true' );
				form.notes[problem[0]].textContent = t( problem[1] );
				form.controls[problem[0]].focus();
				toast( t( problem[1] ), true );
				return;
			}

			const fields = { title : title, client : client.number };

			if( form.controls.description.value.trim() !== '' )
				fields.description = form.controls.description.value;

			if( form.controls.started.value !== '' )
				fields.started = form.controls.started.value;

			const withFolder = folder !== null && folder.checked === true;
			let release = function() {};

			// While the posts go out, a way out of the screen waits for them (see route())
			state.busy = new Promise( function( resolve ) { release = resolve } );
			creating = true;
			create.disabled = true;

			const over = function() {
				creating = false;
				create.disabled = false;
				state.busy = null;
				release();
			};

			// The project is made; what is left to say is said in the toast, the folder with it
			const made = function( id, sentence, bad ) {

				over();
				toast( sentence, bad );

				if( state.view === mine ) {
					form.dirty = false;
					state.draft = null;
					wn.location.hash = '#projekt-'+ id;
				}
			};

			projectSave( '', list.native, fields ).then( function( answer ) {

				if( answer.ok !== true ) {
					over();
					refuseAt( form, answer, 'projects.error-save' );
					return;
				}

				const id = String( answer.body.element['.uri'] ).split( '/' ).pop();

				if( withFolder === false ) {
					made( id, t( 'projects.made', title ), false );
					return;
				}

				makeFolder( id, title, client.number, list.native ).then( function( result ) {
					made( id, result.ok === true ? t( 'projects.made-folder', title ) : t( 'projects.made-no-folder', title, result.text ), result.ok !== true );
				} );
			} );
		};

		reveal();

		const box = el( 'form', { id: 'project-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'projects.new-title' ), el( 'span', { class: 'grow' } ), link( '#projekte', t( 'form.cancel' ), { class: 'btn', id: 'project-abort' } ), create ),
			el( 'div', { class: 'card pad' }, formList( rows, form ) ) );

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#projekte', t( 'projects.title' ) ), ' › '+ t( 'projects.new-title' ).toLowerCase() ) );
		state.main.appendChild( box );
		submitter( 'new-project', box, create, function() { return form.dirty === true } );
		form.controls.title.focus();
	};

	// --- Projektkarte ------------------------------------------------------------------------------------------------

	const projekt = function( where, mine ) {

		loading();

		api( 'crm-projekte/get', { id : where.id } ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.status === 404 ) {
				reveal();
				state.main.appendChild( head( t( 'projects.title' ) ) );
				state.main.appendChild( note( answer.body !== null && answer.body.code === 'crm_projekte_missing' ? '' : 'bad', el( 'span', { text: errorText( answer, 'projects.gone' ) } ), ' ', link( '#projekte', t( 'projects.back' ) ) ) );
				return;
			}

			if( answer.ok !== true ) {
				loadFailed( answer, 'error.load', function() { route() } );
				return;
			}

			state.tab = where.tab;
			drawProjekt( Object.assign( {}, answer.body, { documents : null, limits : null, editing : false, info : null, mine : mine } ) );
		} );
	};

	/**
	 *	Read the project again and draw its card, on the tab that was open
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const reloadProjekt = function( view ) {

		const mine = state.view;

		api( 'crm-projekte/get', { id : view.project.id } ).then( function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				failed( answer, 'error.load' );
				return;
			}

			drawProjekt( Object.assign( {}, answer.body, { documents : view.documents, limits : view.limits, editing : false, info : null, mine : mine, notifyChoice : view.notifyChoice } ) );
		} );
	};

	/**
	 *	The facts of the project, or - after Bearbeiten - the form that changes the title, the note and the day
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const projectCard = function( view ) {

		const project = view.project;

		if( view.editing === false ) {

			unsaver( 'project' );

			return el( 'div', { class: 'card pad', id: 'project-info' }, el( 'h3', { text: t( 'projects.card' ) } ), kv( [
				[ t( 'projects.f-client' ), view.client === null ? project.client : link( '#kunde-'+ view.client.id, view.client.title+ ' · '+ view.client.number ) ],
				[ t( 'projects.f-started' ), dmy( project.started ) ],
				[ t( 'projects.f-description' ), project.description === '' ? '' : stacked( project.description.split( '\n' ) ) ],
				[ t( 'projects.column-documents' ), String( project.documents )+ ( project.open > 0 ? ' · '+ t( 'projects.open-offers', project.open ) : '' ) ],
				[ t( 'projects.folder' ), view.folder === null ? '' : view.folder.name ],
			] ) );
		}

		const form = formOf();
		const save = el( 'button', { type: 'submit', class: 'btn sm primary', id: 'project-save', title: t( 'save.shortcut' ), text: t( 'form.save' ) } );
		const rows = [
			{ name : 'title', label : t( 'projects.f-title' ), control : el( 'input', { id: 'f-title', type: 'text', value: project.title, maxlength: '200', autocomplete: 'off' } ) },
			{ name : 'description', label : t( 'projects.f-description' ), control : el( 'textarea', { id: 'f-description', rows: 4, maxlength: '2000', value: project.description } ) },
			{ name : 'started', label : t( 'projects.f-started' ), control : el( 'input', { id: 'f-started', type: 'date', value: project.started } ) },
		];
		let saving = false;

		const box = el( 'form', { class: 'card pad', id: 'project-info', novalidate: 'novalidate', on: { submit: function( event ) {

			event.preventDefault();

			if( saving === true )
				return;

			Object.keys( form.controls ).forEach( function( name ) {
				form.controls[name].classList.remove( 'err' );
				form.controls[name].removeAttribute( 'aria-invalid' );
				form.notes[name].textContent = '';
			} );

			const typed = { title : form.controls.title.value.trim(), description : form.controls.description.value, started : form.controls.started.value };

			if( typed.title === '' ) {
				form.controls.title.classList.add( 'err' );
				form.controls.title.setAttribute( 'aria-invalid', 'true' );
				form.notes.title.textContent = t( 'projects.err-title' );
				form.controls.title.focus();
				toast( t( 'projects.err-title' ), true );
				return;
			}

			// Only what changed is posted
			const fields = {};

			Object.keys( typed ).forEach( function( name ) {
				if( typed[name] !== project[name] )
					fields[name] = typed[name];
			} );

			if( Object.keys( fields ).length === 0 ) {
				form.dirty = false;
				view.editing = false;
				view.info.replaceWith( view.info = projectCard( view ) );
				return;
			}

			let release = function() {};

			state.busy = new Promise( function( resolve ) { release = resolve } );
			saving = true;
			save.disabled = true;

			const over = function() {
				saving = false;
				save.disabled = false;
				state.busy = null;
				release();
			};

			projectSave( project.id, view.native, fields ).then( function( answer ) {

				if( answer.ok !== true ) {
					over();
					refuseAt( form, answer, 'projects.error-save' );
					return null;
				}

				// A new title renames the folder, as well as it can: the project is saved whatever the folder says
				if( fields.title === undefined || view.folder === null || view.allowed !== true )
					return { ok : true, text : '' };

				return api( 'files/folder/save', { id : view.folder.id, name : fields.title.slice( 0, FOLDER_NAME ) } ).then( function( renamed ) {
					return renamed.ok === true ? { ok : true, text : '' } : { ok : false, text : errorText( renamed, 'projects.error-rename' ) };
				} );
			} ).then( function( result ) {

				if( result === null )
					return;

				over();
				form.dirty = false;
				toast( result.ok === true ? t( 'projects.saved' ) : t( 'projects.saved-no-rename', result.text ), result.ok !== true );

				if( state.view === view.mine )
					reloadProjekt( view );
			} );
		} } },
		el( 'h3', { text: t( 'projects.card' ) } ), formList( rows, form ),
		el( 'div', { class: 'actions top' }, save, el( 'button', { type: 'button', class: 'btn sm', id: 'project-cancel', text: t( 'form.cancel' ), on: { click: function() {
			view.editing = false;
			state.draft = null;
			view.info.replaceWith( view.info = projectCard( view ) );
		} } } ) ) );

		submitter( 'project', box, save, function() { return form.dirty === true } );

		return box;
	};

	/**
	 *	The documents of a project: the same table as the card of a client, the documents of the project and nothing else
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const tabProjectDocuments = function( view ) {

		const holder = el( 'div', { id: 'project-documents' } );
		const mine = state.view;
		const draw = function() {
			clear( holder );
			holder.appendChild( tabClientDocuments( { documents : view.documents }, 'projects.docs-empty' ) );
		};

		if( view.documents !== null )
			draw();
		else {
			holder.appendChild( el( 'p', { class: 'state-note', role: 'status', text: t( 'loading' ) } ) );

			call( 'crm-documents/list', { project : view.project.id }, 'error.load' ).then( function( answer ) {

				if( state.view !== mine || answer.ok !== true )
					return;

				adopt( answer.body );
				view.documents = answer.body.documents;
				draw();
			} );
		}

		return holder;
	};

	/**
	 *	Send the files a person chose, one after the other and each in chunks, as the Files panel's own script sends them: a post with the action files/upload,
	 *	the fields of the chunk as the data and the bytes as the part chunk. The progress is drawn per file. A file over the largest size and an empty one are
	 *	refused before a byte is sent. A chunk answered 413 starts the file again as a new upload in chunks of half the size, down to the floor, where it
	 *	fails; the size that works is kept for the files after it and for the next uploads of the page
	 *
	 *	@param		{Object}		view
	 *	@param		{Array}			chosen					The File objects
	 *	@param		{Element}		list						Where the progress is drawn
	 *	@param		{Element}		button					The button, which is off while this runs
	 *	@param		{Element}		input						The file input, which is emptied when this is over
	 *
	 *	@return		{void}
	 */
	const sendFiles = function( view, chosen, list, button, input ) {

		const mine = state.view;
		const limits = view.limits;
		let sent = 0;
		let mailed = false;

		button.disabled = true;
		list.hidden = false;
		clear( list );

		const finish = function() {

			button.disabled = false;
			input.value = '';

			if( sent === 0 )
				return;

			// What the toast says of the mail is what the server says: the members are informed where a mail was delivered, not where the box was ticked
			toast( t( sent === 1 ? 'projects.uploaded-one' : 'projects.uploaded', sent )+ ' '+ t( mailed === true ? 'projects.informed' : 'projects.not-informed' ) );

			if( state.view !== mine )
				return;

			// The files are read again and the table is drawn anew; the list of the progress stays, with the reasons of the files that did not go in
			api( 'crm-projekte/get', { id : view.project.id } ).then( function( answer ) {

				if( answer.ok !== true || state.view !== mine )
					return;

				view.project = answer.body.project;
				view.list = answer.body.list;
				view.redraw();
			} );
		};

		// Every file has its row from the start - the ones that are not at their turn yet wait in it
		const lines = chosen.map( function( file ) {

			const status = el( 'small', { text: t( 'projects.waiting' ) } );
			const bar = el( 'progress', { max: '100', value: 0 } );
			const row = el( 'li', { class: 'up-row', dataset: { state: 'waiting' } }, el( 'strong', { text: file.name } ), status, bar );

			list.appendChild( row );

			return { row : row, status : status, bar : bar };
		} );

		const go = function( at ) {

			if( at === chosen.length ) {
				finish();
				return;
			}

			const file = chosen[at];
			const line = lines[at];
			const fail = function( text ) {
				line.row.dataset.state = 'failed';
				line.status.textContent = text;
				go( at + 1 );
			};

			if( file.size < 1 )
				return fail( t( 'projects.up-empty' ) );

			if( limits.max > 0 && file.size > limits.max )
				return fail( t( 'projects.up-large', sizeText( limits.max ) ) );

			let parts = chunksOf( file.size, workingChunk > 0 ? Math.min( workingChunk, limits.chunk ) : limits.chunk );
			let id = '';

			if( parts.length === 0 )
				return fail( t( 'projects.up-failed' ) );

			line.row.dataset.state = 'sending';
			line.status.textContent = t( 'projects.sending' );

			const next = function( index ) {

				upload( 'files/upload', { folder : view.folder.id, name : file.name, size : file.size, upload : id, index : index, count : parts.length, visible : true, notify : wantsNotify( view ) }, file.slice( parts[index][0], parts[index][1] ), 'chunk' ).then( function( answer ) {

					if( answer.status === 413 ) {

						const smaller = halveChunk( parts[0][1] - parts[0][0] );

						// A refusal that names its cause (a code with a sentence) says it; one that does not is the web server's
						if( smaller === 0 )
							return fail( answer.body !== null && typeof answer.body === 'object' && typeof answer.body.code === 'string' && typeof state.texts['error.'+ answer.body.code] === 'string' ? errorText( answer, 'projects.up-failed' ) : t( 'projects.up-floor' ) );

						workingChunk = smaller;
						parts = chunksOf( file.size, smaller );
						id = '';
						line.bar.value = 0;
						line.status.textContent = t( 'projects.up-smaller' );
						next( 0 );
						return;
					}

					if( answer.ok !== true )
						return fail( errorText( answer, 'projects.up-failed' ) );

					if( answer.body !== null && typeof answer.body.upload === 'string' )
						id = answer.body.upload;

					line.bar.value = Math.floor( parts[index][1] * 100 / file.size );

					if( index + 1 < parts.length ) {
						next( index + 1 );
						return;
					}

					line.row.dataset.state = 'done';
					line.status.textContent = t( 'projects.up-done' );
					sent++;

					if( answer.body !== null && typeof answer.body === 'object' && answer.body.mailed > 0 )
						mailed = true;

					go( at + 1 );
				} );
			};

			next( 0 );
		};

		go( 0 );
	};

	/**
	 *	Whether the box of the tab Dateien is ticked: the customer is mailed for every file that is uploaded or made visible while it stands. Without the box
	 *	(the tab is not drawn) nobody is
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{boolean}
	 */
	const wantsNotify = function( view ) {
		return view.notifyBox !== undefined && view.notifyBox !== null && view.notifyBox.checked === true;
	};

	/**
	 *	The upload block of the tab Dateien: the file input, the button and the list of the progress
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const uploadCard = function( view ) {

		const input = el( 'input', { type: 'file', id: 'up-input', multiple: 'multiple', 'aria-label': t( 'projects.up-choose' ) } );
		const list = el( 'ul', { class: 'up-list', id: 'up-list', hidden: true } );
		const send = el( 'button', { type: 'button', class: 'btn sm primary', id: 'up-send', text: t( 'projects.up-send' ), on: { click: function() {

			const chosen = Array.from( input.files || [] );

			if( chosen.length === 0 ) {
				toast( t( 'projects.up-none' ), true );
				return;
			}

			sendFiles( view, chosen, list, send, input );
		} } } );

		const box = card( t( 'projects.up-title' ), el( 'div', { class: 'actions' }, input, send ), list );

		box.id = 'project-upload';

		return box;
	};

	/**
	 *	The tab Dateien: the files of the project's folder, and - for an account that holds the permission of the Files panel - what changes them
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{Object}
	 */
	const tabProjectFiles = function( view ) {

		const project = view.project;
		const holder = el( 'div', { id: 'project-files' } );
		const editable = view.allowed === true;
		const mine = state.view;

		if( view.files !== true ) {
			holder.appendChild( note( '', t( 'projects.files-off' ) ) );
			return holder;
		}

		if( view.folder === null ) {

			holder.appendChild( note( '', t( project.folder === '' ? 'projects.folder-none' : 'projects.folder-gone' ) ) );

			if( editable === true && state.perms.elements === true && view.client !== null ) {

				const make = el( 'button', { type: 'button', class: 'btn', id: 'folder-make', text: t( 'projects.folder-make' ), on: { click: function() {

					make.disabled = true;

					makeFolder( project.id, project.title, view.client.number, view.native ).then( function( result ) {

						make.disabled = false;

						if( result.ok !== true )
							toast( t( 'projects.error-folder-text', result.text ), true );
						else
							toast( t( 'projects.folder-made' ) );

						if( state.view === mine && result.folder !== '' )
							reloadProjekt( view );
					} );
				} } } );

				holder.appendChild( el( 'div', { class: 'actions top' }, make ) );
			}
			else
				holder.appendChild( note( '', t( editable === false ? 'projects.files-forbidden' : 'projects.folder-elements' ) ) );

			return holder;
		}

		if( editable === false )
			holder.appendChild( note( '', t( 'projects.files-forbidden' ) ) );

		const tableBox = el( 'div', { id: 'project-file-box' } );

		// One box for the tab: it says whether the customer is mailed for each upload and each switch of "Sichtbar" while it stands. It starts at the setting of Dateien
		view.notifyBox = null;

		if( editable === true ) {
			// The owner's choice stays on the view while the screen stands - a deleted file, a redrawn table, another tab of the card do not give the box back to the setting
			view.notifyBox = el( 'input', { type: 'checkbox', id: 'project-notify', checked: typeof view.notifyChoice === 'boolean' ? view.notifyChoice : view.notify === true, on: { change: function( event ) { view.notifyChoice = event.target.checked === true } } } );
			holder.appendChild( el( 'label', { class: 'check', id: 'project-notify-label' }, view.notifyBox, ' '+ t( 'notify.files' ) ) );
		}

		holder.appendChild( tableBox );

		const filesTable = function() {

			const files = view.list || [];

			return el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl static', id: 'project-file-table' },
				el( 'thead', {}, el( 'tr', {}, el( 'th', { text: t( 'projects.file-name' ) } ), el( 'th', { class: 'r', text: t( 'projects.file-size' ) } ), el( 'th', { text: t( 'projects.file-day' ) } ), el( 'th', { text: t( 'projects.file-by' ) } ),
					el( 'th', { text: t( 'projects.file-visible' ) } ), el( 'th' ) ) ),
				el( 'tbody', {}, files.length === 0
					? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '6', class: 'muted', text: t( 'projects.files-empty' ) } ) )
					: files.map( function( file ) {

						const visibleBox = el( 'input', { type: 'checkbox', role: 'switch', checked: file.visible, disabled: editable === false, 'aria-label': t( 'projects.file-visible' ), dataset: { id: file.id }, on: { change: function( event ) {

							const box = event.target;
							const wanted = box.checked;

							box.disabled = true;

							call( 'files/save', { id : file.id, visible : wanted, notify : wantsNotify( view ) }, 'projects.error-file' ).then( function( answer ) {

								box.disabled = false;

								if( answer.ok === true ) {
									file.visible = wanted;

									if( wanted === true )
										toast( t( answer.body !== null && typeof answer.body === 'object' && answer.body.mailed > 0 ? 'projects.shown-informed' : 'projects.shown-not-informed' ) );
								}
								else
									box.checked = file.visible;
							} );
						} } } );

						return el( 'tr', { dataset: { id: file.id } },
							el( 'td', { class: 'wrap' }, editable === true && file.url !== '' ? link( file.url, file.name, { target: '_blank', rel: 'noopener' } ) : file.name ),
							el( 'td', { class: 'r num', text: sizeText( file.size ) } ),
							el( 'td', { text: dmy( file.created ) } ),
							el( 'td', { text: file.by.kind === 'owner' ? t( 'projects.by-owner' ) : ( file.by.label !== '' ? file.by.label : t( 'projects.by-member' ) ) } ),
							el( 'td', {}, visibleBox ),
							el( 'td', { class: 'r' }, editable === true ? el( 'button', { type: 'button', class: 'btn sm quiet danger', text: t( 'projects.file-delete' ), dataset: { del: file.id }, on: { click: function() {

								ask( { title : t( 'projects.file-delete' ), text : t( 'projects.file-delete-text', file.name ), yes : t( 'projects.file-delete' ), danger : true } ).then( function( yes ) {

									if( yes !== true || state.view !== mine )
										return;

									call( 'files/delete', { id : file.id }, 'projects.error-file' ).then( function( answer ) {
										if( answer.ok === true && state.view === mine ) {
											toast( t( 'projects.file-deleted' ) );
											reloadProjekt( view );
										}
									} );
								} );
							} } } ) : null ) );
					} ) ) ) );
		};

		// The table is drawn again where the files changed under it (an upload); the rest of the tab stays as it is
		view.redraw = function() {

			clear( tableBox );
			tableBox.appendChild( filesTable() );

			if( view.countNode !== null && view.countNode !== undefined )
				view.countNode.textContent = String( view.project.files );
		};

		tableBox.appendChild( filesTable() );

		if( editable === true ) {

			const box = el( 'div', { id: 'project-upload-holder', class: 'gap' }, el( 'p', { class: 'state-note', role: 'status', text: t( 'loading' ) } ) );

			holder.appendChild( box );

			// The sizes the uploads run into are the panel's: the chunk and the largest file
			const drawUpload = function() {
				clear( box );
				box.appendChild( uploadCard( view ) );
			};

			if( view.limits !== null )
				drawUpload();
			else
				call( 'files/list', { group : groupOf( view.client === null ? '' : view.client.number ) }, 'projects.error-limits' ).then( function( answer ) {

					if( state.view !== mine )
						return;

					if( answer.ok !== true ) {
						clear( box );
						box.appendChild( note( 'bad', t( 'projects.error-limits' ) ) );
						return;
					}

					view.limits = answer.body.limits;
					drawUpload();
				} );
		}

		return holder;
	};

	/**
	 *	Delete a project, after the question: the Elements panel's own delete. The folder in Files stays
	 *
	 *	@param		{Object}	view
	 *
	 *	@return		{void}
	 */
	const removeProjekt = function( view ) {

		const project = view.project;

		ask( { title : t( 'projects.delete' ), text : t( view.folder === null ? 'projects.delete-text' : 'projects.delete-text-folder', projectName( project ) ), yes : t( 'projects.delete' ), danger : true } ).then( function( yes ) {

			if( yes !== true || state.view !== view.mine )
				return;

			call( 'elements/delete', { type : PROJECT_TYPE, uri : project.id }, 'projects.error-delete' ).then( function( answer ) {
				if( answer.ok === true && state.view === view.mine ) {
					toast( t( 'projects.deleted' ) );
					wn.location.hash = '#projekte';
				}
			} );
		} );
	};

	const drawProjekt = function( view ) {

		const project = view.project;
		const edits = state.perms.elements === true;
		const start = PROJECT_TABS.includes( state.tab ) === true ? state.tab : PROJECT_TABS[0];

		view.mine = state.view;
		state.tab = start;
		state.draft = null;
		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#projekte', t( 'projects.title' ) ), ' › '+ projectName( project ) ) );
		state.main.appendChild( head( el( 'h1', { text: projectName( project ) } ), projectBadge( project ),
			el( 'span', { class: 'sub' }, view.client === null ? project.client : link( '#kunde-'+ view.client.id, view.client.title ), project.started === '' ? null : ' · '+ t( 'projects.since', dmy( project.started ) ) ),
			el( 'span', { class: 'grow' } ),
			view.client !== null && view.client.active === true ? newMenu( t( 'card.document-new' ), view.client.id, project.id ) : null,
			edits === false ? null : menu( { label : '⋯', aria : t( 'items.more' ), items : [
				{ label : t( 'projects.edit' ), run : function() {
					if( view.editing === true )
						return;
					view.editing = true;
					view.info.replaceWith( view.info = projectCard( view ) );
				} },
				{ label : t( project.done === true ? 'projects.reopen' : 'projects.finish' ), run : function() {
					projectSave( project.id, view.native, { done : project.done !== true } ).then( function( answer ) {
						if( answer.ok !== true ) {
							failed( answer, 'projects.error-save' );
							return;
						}

						toast( t( project.done === true ? 'projects.reopened' : 'projects.finished' ) );

						if( state.view === view.mine )
							reloadProjekt( view );
					} );
				} },
				'sep',
				{ label : t( 'projects.delete' ), danger : true, run : function() { removeProjekt( view ) } },
			] } ) ) );

		view.info = projectCard( view );
		state.main.appendChild( view.info );

		const body = el( 'div', { id: 'tab' } );
		const buttons = [];

		const show = function( key ) {

			state.tab = key;
			buttons.forEach( function( button ) {
				button.setAttribute( 'aria-selected', button.dataset.tab === key ? 'true' : 'false' );
				button.classList.toggle( 'on', button.dataset.tab === key );
			} );
			clear( body );
			body.appendChild( { belege : tabProjectDocuments, dateien : tabProjectFiles }[key]( view ) );
		};

		const strip = el( 'div', { class: 'tabs', role: 'tablist' }, PROJECT_TABS.map( function( key ) {

			const count = key === 'belege' ? project.documents : project.files;
			const badge = count === null ? null : el( 'span', { class: 'n', text: String( count ) } );
			const button = el( 'button', { type: 'button', role: 'tab', 'aria-selected': key === start ? 'true' : 'false', class: key === start ? 'on' : '', dataset: { tab: key }, on: { click: function() {
				if( state.view === view.mine )
					show( key );
			} } }, t( 'ptab.'+ key ), badge );

			if( key === 'dateien' )
				view.countNode = badge;

			buttons.push( button );

			return button;
		} ) );

		state.main.appendChild( strip );
		state.main.appendChild( body );
		show( start );
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
					el( 'span', {}, el( 'b', { text: typeof state.texts[word] === 'string' ? t( word ) : item.key } ), item.state === 'ok' && item.key !== 'mail-cap' ? null : el( 'span', { class: 'state-note block', text: statusLine( item ) } ) ),
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
		state.company = null;

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

		// The mail cap is said with the number in force
		return item.key === 'mail-cap' ? t( key, item.value ) : t( key );
	};

	/**
	 *	What the setup says it did, in one sentence
	 *
	 *	@param		{Object}	answer
	 *
	 *	@return		{string}
	 */
	const setupSentence = function( answer ) {

		const none = { features : [], routes : [], navs : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : false, cap : false };
		const added = Object.assign( {}, none, answer.added );
		const existed = Object.assign( {}, none, answer.existed );
		const there = existed.features.length+ existed.routes.length+ existed.templates.length+ existed.types.length+ existed.pdf.templates.length;
		const done = t( 'setup.done', added.features.length, added.routes.length, added.navs.length, added.templates.length, added.types.length, added.texts, added.pdf.templates.length, there );
		const sentence = typeof added.cap === 'number' ? done+ ' '+ t( 'setup.cap', added.cap ) : done;

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
			[ t( 'settings.portal-cap' ), portal.mail_cap === null ? '' : el( 'span', { id: 'portal-cap' }, portal.mail_cap === 0 ? t( 'settings.portal-cap-none' ) : t( 'settings.portal-cap-value', portal.mail_cap ), ' ', link( state.links.workbench+ '#config', t( 'settings.portal-cap-link' ), { target : '_blank', rel : 'noopener' } ) ) ],
		] ),
		el( 'p', { class: 'state-note', text: t( 'settings.portal-members-note' ) } ),
		portal.mail_cap === null ? null : el( 'p', { class: 'state-note', id: 'portal-cap-note', text: t( 'settings.portal-cap-note' ) } ),
		el( 'h4', { text: t( 'settings.portal-status' ) } ),
		missing.length === 0 ? el( 'p', { class: 'state-note', id: 'status-ok', text: t( 'status.allok' ) } ) : el( 'ul', { class: 'list-mini', id: 'status-missing' }, missing.map( function( item ) { return el( 'li', { text: ( item.key === 'mail-cap' ? t( 'einrichtung.row-mail-cap' )+ ': ' : '' )+ statusLine( item ) } ) } ) ),
		el( 'div', { class: 'actions' }, setup ), el( 'p', { class: 'state-note', id: 'setup-said', role: 'status', text: said } ) );

		return card;
	};

	// --- Firmendaten -------------------------------------------------------------------------------------------------

	// The texts of the company the wizard writes: the surface edits these six through the Text panel's actions (text/keys, text/savebatch), for the keys below the prefix alone
	const COMPANY_PREFIX = '/project/company/';
	const COMPANY_KEYS = [ 'general/name', 'general/description', 'contact/address', 'contact/country', 'contact/email', 'contact/phone' ];
	const COMPANY_AREAS = [ 'general/description', 'contact/address' ];

	/**
	 *	What the block shows: a field for each of the six texts in each language that has it - a text of all languages in the first card (the native language's) alone
	 *
	 *	@param		{Object}	body						The answer of text/keys (for an account with the Text panel) or of settings-get (company): keys, locales, native
	 *	@param		{boolean}	editable				Whether the answer is the panel's, which says for each key whether it may be written
	 *
	 *	@return		{Object}									locales (the native first), fields ([ { id, name, locale, value, writable } ] - locale '*' for a text of all), base and typed (id => text)
	 */
	const companyOf = function( body, editable ) {

		const entries = {};
		const locales = nativeFirst( ( body.locales || [] ).map( function( one ) { return typeof one === 'string' ? one : one.locale } ), body.native );
		const fields = [];
		const base = {};

		( body.keys || [] ).forEach( function( entry ) {
			if( typeof entry.key === 'string' && entry.key.indexOf( COMPANY_PREFIX ) === 0 )
				entries[entry.key.slice( COMPANY_PREFIX.length )] = entry;
		} );

		locales.forEach( function( locale, index ) {
			COMPANY_KEYS.forEach( function( name ) {

				const entry = entries[name];

				if( entry === undefined || ( entry.global === true && index > 0 ) )
					return;

				const where = entry.global === true ? '*' : locale;
				const id = name+ '|'+ where;
				const value = String( ( entry.values || {} )[where] ?? '' );

				base[id] = value;
				fields.push( { id : id, name : name, locale : where, card : locale, value : value, writable : editable === true && entry.writable !== false } );
			} );
		} );

		return { locales : locales, fields : fields, base : base, typed : {}, editable : editable };
	};

	/**
	 *	What the person changed: the fields whose text is not the stored one, as the items of a batch of the Text panel
	 *
	 *	@param		{Object}	company
	 *
	 *	@return		{Array}										[ { id, key, locale, value } ]
	 */
	const companyChanges = function( company ) {

		return company.fields.filter( function( field ) {
			return field.writable === true && company.typed[field.id] !== undefined && company.typed[field.id] !== company.base[field.id];
		} ).map( function( field ) {
			return { id : field.id, key : COMPANY_PREFIX+ field.name, locale : field.locale, value : company.typed[field.id] };
		} );
	};

	/**
	 *	The sentence beside a field the panel refused: its answer for a key is a word of its own ("not allowed" for a key of a role that may not write it), the sentence of
	 *	the panel's own failure otherwise
	 *
	 *	@param		{*}				result
	 *
	 *	@return		{string}
	 */
	const companySentence = function( result ) {
		return result !== undefined && result !== null && result.error === 'not allowed' ? t( 'firma.error-denied' ) : t( 'text.error-save' );
	};

	/**
	 *	The block Firmendaten: a card for each language of the site, the native one first, with the fields of the company; Speichern posts the changed ones (the texts that are the
	 *	same in all languages with the first language that changed, then each other language that changed, since the panel answers one result to a key). Without the permission of
	 *	the Text panel the values are read, and the sentence says what is missing
	 *
	 *	@param		{Object}	company
	 *	@param		{Object}	sibling					The form of the screen next to it, which keeps the page's guard when this one is saved
	 *
	 *	@return		{Object}
	 */
	const firmaCard = function( company, sibling ) {

		const later = [];
		const form = { controls : {}, notes : {}, dirty : false, form : true };
		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'firma-save', title: t( 'save.shortcut' ), text: t( 'firma.save' ) } );
		const cards = company.locales.map( function( locale, index ) {

			const mine = company.fields.filter( function( field ) { return field.card === locale } );

			if( mine.length === 0 )
				return null;

			const title = el( 'h4', { text: index === 0 ? t( 'items.card-native', languageName( locale ) ) : languageName( locale ) } );

			if( company.editable === false )
				return el( 'div', { class: 'card pad', id: 'firma-lang-'+ locale, dataset: { locale: locale } }, title, kv( mine.map( function( field ) { return [ t( 'firma.'+ field.name.replace( '/', '-' ) ), field.value === '' ? '' : stacked( field.value.split( '\n' ) ) ] } ) ) );

			const rows = mine.map( function( field ) {

				const id = 'f-firma-'+ field.name.replace( '/', '-' )+ ( field.locale === '*' ? '' : '-'+ locale );
				const shown = company.typed[field.id] ?? field.value;
				const control = COMPANY_AREAS.includes( field.name ) === true
					? el( 'textarea', { id: id, rows: 3, maxlength: '2000', value: shown } )
					: el( 'input', { id: id, type: field.name === 'contact/email' ? 'email' : 'text', value: shown, maxlength: '2000', autocomplete: 'off', inputmode: field.name === 'contact/phone' ? 'tel' : null } );

				if( field.writable === false ) {
					control.setAttribute( 'readonly', 'readonly' );
					control.classList.add( 'ro' );
				}

				// What is typed is kept over a drawing of the screen; the form is dirty while a text differs from the stored one (after the listener of formList(), which marks it for any input)
				later.push( function() {
					[ 'input', 'change' ].forEach( function( name ) {
						control.addEventListener( name, function() {
							company.typed[field.id] = control.value;
							form.dirty = companyChanges( company ).length > 0;

							if( form.dirty === true && ( state.draft === null || state.draft.dirty !== true ) )
								state.draft = form;
						} );
					} );
				} );

				return { name : field.id, label : t( 'firma.'+ field.name.replace( '/', '-' ) ), control : control, hint : field.locale === '*' ? t( 'firma.shared' ) : undefined };
			} );

			const list = formList( rows, form );

			later.forEach( function( watch ) { watch() } );
			later.length = 0;

			return el( 'div', { class: 'card pad', id: 'firma-lang-'+ locale, dataset: { locale: locale } }, title, list );
		} ).filter( function( one ) { return one !== null } );

		if( cards.length === 0 )
			return el( 'div', { class: 'card pad gap', id: 'firma' }, el( 'h3', { text: t( 'firma.title' ) } ), el( 'p', { class: 'state-note', text: t( 'firma.none' ) } ) );

		if( company.editable === false )
			return el( 'div', { class: 'card pad gap', id: 'firma' }, el( 'h3', { text: t( 'firma.title' ) } ), el( 'p', { class: 'state-note', id: 'firma-readonly', text: t( 'firma.readonly' ) } ), el( 'div', { class: 'cols' }, cards ) );

		const mine = state.view;

		const submit = function( event ) {

			event.preventDefault();

			const changes = companyChanges( company );

			// Nothing was changed: nothing is posted
			if( changes.length === 0 )
				return;

			// One batch to a language: the globals ride with the first language that changed
			const order = company.locales.filter( function( locale ) { return changes.some( function( change ) { return change.locale === locale } ) } );
			const batches = ( order.length > 0 ? order : [ '*' ] ).map( function( locale, index ) {
				return changes.filter( function( change ) { return change.locale === locale || ( index === 0 && change.locale === '*' ) } );
			} );

			save.disabled = true;
			Object.keys( form.notes ).forEach( function( id ) {
				form.notes[id].textContent = '';
				form.controls[id].classList.remove( 'err' );
				form.controls[id].removeAttribute( 'aria-invalid' );
			} );

			const send = function( index ) {

				if( index >= batches.length ) {
					save.disabled = false;
					form.dirty = companyChanges( company ).length > 0;

					if( form.dirty === false && state.draft === form )
						state.draft = sibling.dirty === true ? sibling : null;

					toast( t( 'firma.saved' ) );
					return;
				}

				call( 'text/savebatch', { items : batches[index].map( function( change ) { return { key : change.key, locale : change.locale, value : change.value } } ) }, 'text.error-save' ).then( function( answer ) {

					const results = answer.ok === true && answer.body !== null && typeof answer.body === 'object' && answer.body.results !== null && typeof answer.body.results === 'object' ? answer.body.results : {};
					let refused = false;

					batches[index].forEach( function( change ) {

						const result = results[change.key];

						if( result !== undefined && result !== null && result.ok === true ) {
							company.base[change.id] = String( result.value ?? '' );
							delete company.typed[change.id];

							if( form.controls[change.id] !== undefined && form.controls[change.id].parentNode !== null )
								form.controls[change.id].value = company.base[change.id];

							return;
						}

						// A key the panel did not write: its sentence beside the field. A request that failed as a whole has said so in the toast
						refused = true;

						if( answer.ok === true && form.controls[change.id] !== undefined ) {
							form.controls[change.id].classList.add( 'err' );
							form.controls[change.id].setAttribute( 'aria-invalid', 'true' );
							form.notes[change.id].textContent = companySentence( result );
						}
					} );

					if( answer.ok !== true || refused === true ) {
						save.disabled = false;
						form.dirty = companyChanges( company ).length > 0;

						if( answer.ok === true && state.view === mine ) {
							const first = batches[index].find( function( change ) { return form.controls[change.id] !== undefined && form.notes[change.id].textContent !== '' } );

							toast( companySentence( results[first === undefined ? '' : first.key] ), true );

							if( first !== undefined && form.controls[first.id].parentNode !== null )
								form.controls[first.id].focus();
						}

						return;
					}

					send( index+ 1 );
				} );
			};

			send( 0 );
		};

		form.dirty = companyChanges( company ).length > 0;

		const box = el( 'form', { class: 'card pad gap', id: 'firma', novalidate: 'novalidate', on: { submit: submit } },
			el( 'h3', { text: t( 'firma.title' ) } ), el( 'p', { class: 'state-note', text: t( 'firma.note' ) } ),
			el( 'div', { class: 'cols' }, cards ),
			el( 'div', { class: 'actions top' }, save ) );

		if( form.dirty === true )
			state.draft = form;

		submitter( 'firma', box, save, function() { return companyChanges( company ).length > 0 } );

		return box;
	};

	/**
	 *	Draw the block Firmendaten into its place: from what the screen holds already, from the Text panel (for an account with its permission: the texts and whether each may be
	 *	written), else from the CRM's own read of the texts
	 *
	 *	@param		{Object}	holder
	 *	@param		{Object}	view						The answer of settings-get
	 *	@param		{Object}	sibling					The form of the screen next to it
	 *	@param		{number}	mine
	 *
	 *	@return		{void}
	 */
	const drawFirma = function( holder, view, sibling, mine ) {

		const draw = function( company ) {
			clear( holder );
			holder.appendChild( firmaCard( company, sibling ) );
		};

		if( state.company !== null ) {
			draw( state.company );
			return;
		}

		if( state.perms.text !== true ) {
			draw( companyOf( view.company, false ) );
			return;
		}

		holder.appendChild( el( 'p', { class: 'state-note', role: 'status', text: t( 'loading' ) } ) );

		api( 'text/keys' ).then( function( answer ) {

			if( state.view !== mine )
				return;

			clear( holder );

			if( answer.ok !== true ) {
				holder.appendChild( note( 'bad', el( 'span', { text: errorText( answer, 'text.error-load' ) } ), el( 'button', { type: 'button', class: 'btn sm', id: 'firma-retry', text: t( 'retry' ), on: { click: function() {
					clear( holder );
					drawFirma( holder, view, sibling, mine );
				} } } ) ) );
				return;
			}

			state.company = companyOf( answer.body, true );
			draw( state.company );
		} );
	};

	/**
	 *	The block Datenschutz of the Einstellungen: the text the portal asks the customers to agree to, a field with Speichern of its own (and Strg+S). The day the text last
	 *	changed is the module's and only shown; a text that is saved different from the one before asks every customer again, which the note says. An empty text
	 *	means the portal asks for nothing
	 *
	 *	@param		{Object}	settings				As settings-get has them
	 *	@param		{Object}	sibling					The form of the screen next to it, which keeps the page's guard when this one is saved
	 *
	 *	@return		{Object}
	 */
	const privacyCard = function( settings, sibling ) {

		const form = { controls : {}, notes : {}, dirty : false, form : true };
		const area = el( 'textarea', { id: 'f-privacy_text', rows: 10, maxlength: '20000', spellcheck: 'true', value: settings.privacy_text ?? '' } );
		const day = el( 'span', { class: 'state-note', id: 'privacy-changed', text: ( settings.privacy_changed ?? '' ) === '' ? t( 'privacy.never' ) : t( 'privacy.changed', dmy( settings.privacy_changed ) ) } );
		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'privacy-save', title: t( 'save.shortcut' ), text: t( 'privacy.save' ) } );
		const mine = state.view;
		let stored = area.value;

		const list = formList( [ { name : 'privacy_text', label : t( 'privacy.label' ), control : area, hint : t( 'privacy.hint' ) } ], form );

		// What is typed is unsaved while it differs from the stored text; the guard of the page asks before it lets go
		[ 'input', 'change' ].forEach( function( name ) {
			area.addEventListener( name, function() {
				form.dirty = area.value !== stored;

				if( form.dirty === true && ( state.draft === null || state.draft.dirty !== true ) )
					state.draft = form;
			} );
		} );

		const box = el( 'form', { class: 'card pad gap', id: 'privacy', novalidate: 'novalidate', on: { submit: function( event ) {

			event.preventDefault();
			save.disabled = true;

			api( 'crm-documents/settings-save', { privacy_text : area.value } ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'error.settings' );
					return;
				}

				stored = answer.body.settings.privacy_text;
				area.value = stored;
				form.dirty = false;
				day.textContent = answer.body.settings.privacy_changed === '' ? t( 'privacy.never' ) : t( 'privacy.changed', dmy( answer.body.settings.privacy_changed ) );

				if( state.draft === form )
					state.draft = sibling.dirty === true ? sibling : null;

				toast( t( 'privacy.saved' ) );
			} );
		} } },
			el( 'h3', { text: t( 'privacy.title' ) } ), el( 'p', { class: 'state-note', text: t( 'privacy.note' ) } ),
			list, el( 'p', {}, day ),
			el( 'div', { class: 'actions top' }, save ) );

		submitter( 'privacy', box, save, function() { return area.value !== stored } );

		return box;
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
			{ name : 'payment_days', label : t( 'settings.payment-days' ), control : day( 'f-payment_days', settings.payment_days ), hint : t( 'settings.days-hint' ) },
			{ name : 'offer_days', label : t( 'settings.offer-days' ), control : day( 'f-offer_days', settings.offer_days ), hint : t( 'settings.days-hint' ) },
			{ name : 'currency', label : t( 'settings.currency' ), control : el( 'input', { id: 'f-currency', type: 'text', value: settings.currency, maxlength: '5', autocomplete: 'off', class: 'short' } ) },
		], form );

		const particulars = formList( [
			{ name : 'tax_id', label : t( 'settings.tax-id' ), control : el( 'input', { id: 'f-tax_id', type: 'text', value: settings.tax_id ?? '', maxlength: '40', autocomplete: 'off' } ), hint : t( 'settings.tax-id-hint' ) },
			{ name : 'bank_name', label : t( 'settings.bank-name' ), control : el( 'input', { id: 'f-bank_name', type: 'text', value: settings.bank_name ?? '', maxlength: '80', autocomplete: 'off' } ) },
			{ name : 'iban', label : t( 'settings.iban' ), control : el( 'input', { id: 'f-iban', type: 'text', value: settings.iban ?? '', maxlength: '42', autocomplete: 'off', spellcheck: 'false' } ) },
			{ name : 'bic', label : t( 'settings.bic' ), control : el( 'input', { id: 'f-bic', type: 'text', value: settings.bic ?? '', maxlength: '11', autocomplete: 'off', spellcheck: 'false', class: 'bic' } ) },
		], form );

		const numbers = formList( [ 'offer', 'invoice', 'credit' ].map( function( category ) {
			return { name : 'number_'+ category, label : t( 'settings.number-'+ category ), control : el( 'input', { id: 'f-number_'+ category, type: 'text', value: settings['number_'+ category], maxlength: '40', autocomplete: 'off' } ) };
		} ), form );

		const next = el( 'p', { class: 'state-note' }, el( 'b', { text: t( 'settings.next' )+ ': ' } ), el( 'span', { class: 'num', id: 'next-numbers', text: [ 'offer', 'invoice', 'credit' ].map( function( category ) { return view.next[category] } ).filter( function( number ) { return number !== '' } ).join( ' · ' ) } ) );

		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'settings-save', title: t( 'save.shortcut' ), text: t( 'settings.save' ) } );

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

		const box = el( 'form', { id: 'settings-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'settings.title' ), el( 'span', { class: 'grow' } ), save ),
			el( 'div', { class: 'cols' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'settings.vat' ) } ), taxes ),
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'settings.numbers' ) } ), numbers, next, el( 'p', { class: 'state-note', text: t( 'settings.numbers-note' ) } ) ) ),
			el( 'div', { class: 'card pad gap', id: 'particulars' }, el( 'h3', { text: t( 'settings.particulars' ) } ), particulars, el( 'p', { class: 'state-note', text: t( 'settings.particulars-note' ) } ) ) );

		state.main.appendChild( box );
		submitter( 'settings', box, save, function() { return form.dirty === true } );

		state.main.appendChild( privacyCard( settings, form ) );

		const firma = el( 'div', { class: 'gap', id: 'firma-holder' } );

		state.main.appendChild( firma );
		drawFirma( firma, view, form, mine );

		// Vorlagen of the feature PDF: the assignment, read only
		const templates = el( 'div', { class: 'card pad', id: 'templates' }, el( 'h3', { text: t( 'settings.templates' ) } ),
			view.pdf === false ? el( 'p', { class: 'state-note', text: t( 'settings.templates-off' ) } ) : kv( [ 'offer', 'invoice', 'contract', 'privacy', 'information' ].map( function( tag ) {
				return [ t( 'tag.'+ tag ), view.templates[tag].length === 0 ? t( 'settings.templates-none' ) : view.templates[tag].join( ' · ' ) ];
			} ) ),
			el( 'p', { class: 'state-note' }, t( 'settings.templates-note' ), ' ', state.perms.pdf === true ? link( '#pdf', t( 'settings.templates-link' ) ) : link( state.links.pdf, t( 'settings.templates-link-panel' ), { target : '_blank', rel : 'noopener' } ) ) );

		state.main.appendChild( el( 'div', { class: 'cols gap' }, templates, portalCard( view.portal, items, said, mine ) ) );

		state.main.appendChild( el( 'div', { class: 'card pad gap', id: 'access' }, el( 'h3', { text: t( 'settings.access' ) } ),
			el( 'p', { class: 'state-note', text: t( 'settings.access-text', state.perm ) } ),
			el( 'p', { class: 'state-note' }, link( state.links.users, t( 'settings.access-link' ), { target : '_blank', rel : 'noopener' } ) ) ) );
	};

	// --- PDF-Vorlagen ------------------------------------------------------------------------------------------------

	// The placeholders a template may use, by group: the vocabulary of \Crm\Documents\Render::data(). The word of each is tok.<group>-<name>
	const PDF_TOKENS = [
		[ 'document', [ '/document/number', '/document/title', '/document/date', '/document/parent', '/document/vatnote', '/document/net', '/document/vat', '/document/gross' ] ],
		[ 'invoice', [ '/invoice/due', '/invoice/due-sentence', '/invoice/period', '/site/tax', '/site/bank', '/site/iban', '/site/bic' ] ],
		[ 'offer', [ '/offer/number', '/offer/date', '/offer/valid', '/offer/valid-sentence', '/offer/gross', '/offer/period' ] ],
		[ 'customer', [ '/customer/number', '/customer/company', '/customer/name', '/customer/street', '/customer/zip', '/customer/city', '/customer/country', '/customer/email', '/customer/vat' ] ],
		[ 'portal', [ '/site/portal', '/site/login' ] ],
	];

	// The page numbers, which a header and a footer know
	const PDF_PAGE_TOKENS = [ 'page', 'pages' ];

	// The areas of a document the app fills, the names of the slots
	const PDF_SLOTS = [ 'address', 'lines', 'totals' ];

	// The tabs of the screens, in order: the key and the hash
	const PDF_TABS = [ [ 'vorlagen', '#pdf' ], [ 'bausteine', '#pdf-bausteine' ], [ 'layout', '#pdf-layout' ], [ 'design', '#pdf-design' ] ];

	// The codes of the save of a template that name a block, by the number in their params; the others belong to the template
	const PDF_BLOCK_CODES = [ 'pdf_block_type', 'pdf_block_html', 'pdf_block_ref', 'pdf_block_name', 'pdf_block_label', 'pdf_signature_party', 'pdf_signature_page', 'pdf_signature_box' ];

	/**
	 *	@param		{string}	tag
	 *
	 *	@return		{string}									The word of a tag of the feature; the tag itself where the surface has no word for it
	 */
	const tagWord = function( tag ) {
		return typeof state.texts['tag.'+ tag] === 'string' ? t( 'tag.'+ tag ) : tag;
	};

	/**
	 *	@param		{string}	token						/document/number
	 *
	 *	@return		{string}									The word of a placeholder of the vocabulary
	 */
	const tokenWord = function( token ) {
		return t( 'tok.'+ token.slice( 1 ).replace( '/', '-' ) );
	};

	/**
	 *	@param		{string}	path						/project/company/general/name
	 *
	 *	@return		{string}									The word of a text of the site, else the key without the company
	 */
	const siteWord = function( path ) {

		const rest = path.split( '/' ).slice( 3 ).join( '-' );

		return typeof state.texts['site.'+ rest] === 'string' ? t( 'site.'+ rest ) : path.split( '/' ).slice( 3 ).join( '/' );
	};

	/**
	 *	The placeholders as the menu and the legend group them
	 *
	 *	@param		{boolean}	band						Whether the page numbers belong to them (a header and a footer)
	 *
	 *	@return		{Array}										[ { title, tokens: [ { token, label } ] } ]
	 */
	const tokenGroups = function( band ) {

		const groups = PDF_TOKENS.map( function( group ) {
			return { title : t( 'pdf.group-'+ group[0] ), tokens : group[1].map( function( token ) { return { token : token, label : tokenWord( token ) } } ) };
		} );

		if( state.pdf.site.length > 0 )
			groups.push( { title : t( 'pdf.group-site' ), tokens : state.pdf.site.map( function( path ) { return { token : path, label : siteWord( path ) } } ) } );

		if( band === true )
			groups.push( { title : t( 'pdf.group-page' ), tokens : PDF_PAGE_TOKENS.map( function( name ) { return { token : name, label : t( 'tok.'+ name ) } } ) } );

		return groups;
	};

	/**
	 *	Put a text into a field where the caret is, in place of what is selected, and leave the caret behind it
	 *
	 *	@param		{Object}	area
	 *	@param		{string}	text
	 *
	 *	@return		{void}
	 */
	const insertAt = function( area, text ) {

		const from = typeof area.selectionStart === 'number' ? area.selectionStart : area.value.length;
		const to = typeof area.selectionEnd === 'number' ? area.selectionEnd : from;
		const at = from+ text.length;

		area.value = area.value.slice( 0, from )+ text+ area.value.slice( to );
		area.focus();
		area.selectionStart = at;
		area.selectionEnd = at;
	};

	/**
	 *	The menu "Platzhalter" of a text: the vocabulary by group, then the texts of the site; a choice goes into the text where the caret is
	 *
	 *	@param		{Object}	area						The textarea
	 *	@param		{boolean}	band						Whether it is a header or a footer
	 *	@param		{Function}	changed					Called after an insert, as the text was typed
	 *
	 *	@return		{Object}
	 */
	const placeholderMenu = function( area, band, changed ) {

		const items = [];

		tokenGroups( band ).forEach( function( group ) {

			items.push( { heading : group.title } );
			group.tokens.forEach( function( one ) {
				items.push( { label : one.label+ '  [['+ one.token+ ']]', run : function() {
					insertAt( area, '[['+ one.token+ ']]' );
					changed();
				} } );
			} );
		} );

		return menu( { label : t( 'pdf.placeholders' ), aria : t( 'pdf.placeholders-aria' ), button : 'sm', tall : true, items : items } );
	};

	/**
	 *	The tabs of the screens PDF-Vorlagen
	 *
	 *	@param		{string}	active					vorlagen, bausteine, layout or design
	 *
	 *	@return		{Object}
	 */
	const pdfTabs = function( active ) {

		return el( 'nav', { class: 'tabs top', id: 'pdf-tabs', 'aria-label': t( 'pdf.tabs' ) }, PDF_TABS.map( function( tab ) {
			return el( 'a', { href: tab[1], class: tab[0] === active ? 'on' : '', 'aria-current': tab[0] === active ? 'page' : null, text: t( 'pdf.tab-'+ tab[0] ) } );
		} ) );
	};

	/**
	 *	The screens need the permission of the Pdf panel: without it the screen says so, and no request is made
	 *
	 *	@return		{boolean}
	 */
	const pdfAllowed = function() {

		if( state.perms.pdf === true && state.pdfAvailable === true )
			return true;

		// The feature is not there for an account that has the permission, which is another sentence than the permission that is missing
		reveal();
		state.main.appendChild( head( t( 'pdf.title' ) ) );
		state.main.appendChild( note( 'bad', t( state.perms.pdf === true ? 'pdf.not-installed' : 'pdf.forbidden' ) ) );

		return false;
	};

	/**
	 *	Read the lists a screen of PDF-Vorlagen starts from, and draw it when they all came
	 *
	 *	@param		{Array}		asks						[ [ action, data ] ... ]; the context of the CRM is always read as the last
	 *	@param		{number}	mine						The view that draws
	 *	@param		{Function}	draw						Called with the bodies of the answers
	 *
	 *	@return		{void}
	 */
	const pdfRead = function( asks, mine, draw ) {

		loading();

		Promise.all( asks.concat( [ [ 'crm-pdf/context' ] ] ).map( function( ask ) { return api( ask[0], ask[1] ) } ) ).then( function( answers ) {

			if( state.view !== mine )
				return;

			const failure = answers.find( function( answer ) { return answer.ok !== true } );

			if( failure !== undefined ) {
				loadFailed( failure, 'error.load', function() { route() } );
				return;
			}

			const bodies = answers.map( function( answer ) { return answer.body } );

			state.pdf.site = Array.isArray( bodies[bodies.length - 1].site ) === true ? bodies[bodies.length - 1].site : [];
			draw( ...bodies );
		} );
	};

	/**
	 *	Open a PDF the surface serves itself - the sample of a template or of the layout - in a new tab: the response carries its own headers, the page's
	 *	policy has no frame or blob for it
	 *
	 *	@param		{string}	path						template/<key> or layout
	 *
	 *	@return		{void}
	 */
	const openPreview = function( path ) {

		const url = state.base+ '/crm/pdf-preview/'+ path;
		const tab = wn.open( url, '_blank' );

		// A browser that does not open a tab after an asynchronous save (it asks for the click itself): the link is shown, for the owner to click
		if( tab === null || tab === undefined ) {
			dialog( { title : t( 'pdf.preview' ), text : t( 'pdf.preview-blocked' ), link : { href : url, text : t( 'pdf.preview-open' ), blank : true } } );
			return;
		}

		tab.opener = null;
	};

	/**
	 *	The link of a preview that does not depend on what is typed (the layout, the design): a link to the sample, in a new tab
	 *
	 *	@param		{string}	id
	 *
	 *	@return		{Object}
	 */
	const previewLink = function( id ) {
		return el( 'a', { class: 'btn', id: id, href: state.base+ '/crm/pdf-preview/layout', target: '_blank', rel: 'noopener', text: t( 'pdf.preview' ) } );
	};

	/**
	 *	A text of the feature with the menu of the placeholders under it
	 *
	 *	@param		{Object}	area
	 *	@param		{boolean}	band
	 *	@param		{Function}	changed
	 *	@param		{Object}	[hint]
	 *
	 *	@return		{Object}
	 */
	const withPlaceholders = function( area, band, changed, hint ) {
		return el( 'div', { class: 'textbox' }, area, el( 'div', { class: 'tools' }, placeholderMenu( area, band, changed ), hint === undefined ? null : hint ) );
	};

	/**
	 *	@param		{string}	html
	 *
	 *	@return		{string}									The text of a piece of HTML, in one line and short
	 */
	const plainText = function( html ) {

		const text = String( html ).replace( /<[^>]*>/g, ' ' ).replace( /&nbsp;/g, ' ' ).replace( /\s+/g, ' ' ).trim();

		return text.length > 90 ? text.slice( 0, 90 )+ '…' : text;
	};

	// --- Vorlagen: the list ------------------------------------------------------------------------------------------

	const pdfKeys = {
		name : function( one ) { return one.name.toLowerCase() },
		tag : function( one ) { return tagWord( one.tag ).toLowerCase() },
		changed : function( one ) { return one.changed },
		used : function( one ) { return one.used },
	};

	/**
	 *	The templates of the list the filter shows, in its order
	 *
	 *	@param		{Array}		rows						The templates with changed and used
	 *	@param		{Object}	filter					q, sort, dir
	 *
	 *	@return		{Array}
	 */
	const pdfVisible = function( rows, filter ) {

		const query = filter.q.trim().toLowerCase();
		const key = pdfKeys[filter.sort] || pdfKeys.name;

		return rows.filter( function( one ) {
			return query === '' || ( one.name+ ' '+ one.key+ ' '+ tagWord( one.tag ) ).toLowerCase().indexOf( query ) !== -1;
		} ).sort( function( a, b ) {

			const left = key( a );
			const right = key( b );
			const order = typeof left === 'string' ? left.localeCompare( right, 'de' ) : ( left < right ? -1 : ( left > right ? 1 : 0 ) );

			return order === 0 ? ( a.key < b.key ? -1 : 1 ) : order * filter.dir;
		} );
	};

	const pdfVorlagen = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		pdfRead( [ [ 'pdf/templates/list' ] ], mine, function( list, context ) {

			state.pdf.templates = list.templates.map( function( one ) {
				return Object.assign( {}, one, { changed : context.changed[one.key] || '', used : context.used[one.key] || 0 } );
			} );
			state.pdf.tags = list.tags;
			state.pdf.library = list.library;
			drawPdfVorlagen();
		} );
	};

	const drawPdfVorlagen = function() {

		const filter = state.pfilter;
		const rows = pdfVisible( state.pdf.templates, filter );
		const again = function() { drawPdfVorlagen() };
		const heading = function( key, label, right ) { return sortHead( filter, key, label, right, again, key === 'changed' || key === 'used' ? -1 : 1 ) };

		const body = el( 'tbody', {}, rows.length === 0
			? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '4', class: 'muted', text: state.pdf.templates.length === 0 ? t( 'pdf.none' ) : t( 'pdf.nomatch' ) } ) )
			: rows.map( function( one ) {
				return el( 'tr', { dataset: { key: one.key }, on: { click: function() { wn.location.hash = '#pdf-vorlage-'+ one.key } } },
					el( 'td', {}, el( 'b', {}, link( '#pdf-vorlage-'+ one.key, one.name ) ), el( 'div', { class: 'muted small', text: one.key } ) ),
					el( 'td', {}, badge( 'open', tagWord( one.tag ) ), one.known === false ? [ ' ', badge( 'portal', t( 'pdf.tag-gone' ) ) ] : null ),
					el( 'td', { class: 'num', text: dmy( one.changed ) } ),
					el( 'td', { class: 'r num' }, one.used > 0 ? String( one.used ) : el( 'span', { class: 'muted', text: '–' } ) ) );
			} ) );

		const table = el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl', id: 'pdf-templates' },
			el( 'thead', {}, el( 'tr', {}, heading( 'name', t( 'pdf.col-name' ) ), heading( 'tag', t( 'pdf.col-tag' ) ), heading( 'changed', t( 'pdf.col-changed' ) ), heading( 'used', t( 'pdf.col-used' ), true ) ) ),
			body ) );

		const fresh = state.pdf.tags.length === 0 ? null : menu( { label : t( 'pdf.new-template' ), button : 'primary', aria : t( 'pdf.new-template' ), items : state.pdf.tags.map( function( tag ) {
			return { label : tagWord( tag ), run : function() { wn.location.hash = '#pdf-neue-vorlage-'+ tag } };
		} ) } );

		reveal();

		state.main.appendChild( head( t( 'pdf.title' ), el( 'span', { class: 'sub', text: t( 'pdf.count', state.pdf.templates.length, state.pdf.library.length ) } ), el( 'span', { class: 'grow' } ),
			search( t( 'pdf.search' ), filter.q, { input: function( event ) {
				filter.q = event.target.value;
				keepSearch( drawPdfVorlagen, true );
			} } ), fresh ) );
		state.main.appendChild( pdfTabs( 'vorlagen' ) );
		append( state.main, [ table, el( 'p', { class: 'state-note top', text: t( 'pdf.list-note' ) } ) ] );
	};

	// --- Vorlage: the editor of a template ---------------------------------------------------------------------------

	/**
	 *	A number of millimetres as it is posted: a number where the text is one (a decimal comma is read as a point), else the text, which the store refuses
	 *
	 *	@param		{string}	text
	 *
	 *	@return		{number|string}
	 */
	const millimetres = function( text ) {

		const clean = String( text ).trim().replace( ',', '.' );

		return /^-?\d{1,4}(?:\.\d+)?$/.test( clean ) === true ? Number( clean ) : clean;
	};

	/**
	 *	A block of the store as the editor holds it: the numbers of a signature as the text of their fields, the page as the word the person chooses
	 *
	 *	@param		{Object}	block
	 *
	 *	@return		{Object}
	 */
	const blockDraft = function( block ) {

		if( block.type === 'html' )
			return block.block !== undefined ? { type : 'html', mode : 'ref', block : block.block, html : '' } : { type : 'html', mode : 'own', block : '', html : block.html ?? '' };

		if( block.type === 'slot' )
			return { type : 'slot', name : block.name };

		if( block.type === 'input' )
			return { type : 'input', name : block.name, label : block.label, multiline : block.multiline === true, required : block.required === true };

		if( block.type === 'signature' )
			return { type : 'signature', name : block.name, party : block.party, label : block.label, page : block.page === 'last' ? 'last' : ( block.page === 1 ? 'first' : 'number' ), number : block.page === 'last' ? '2' : String( block.page ),
				x : String( block.x ), y : String( block.y ), w : String( block.w ), h : String( block.h ), line : block.line === true };

		return { type : 'pagebreak' };
	};

	/**
	 *	A block of the editor as the store takes it
	 *
	 *	@param		{Object}	b
	 *
	 *	@return		{Object}
	 */
	const blockPayload = function( b ) {

		if( b.type === 'html' )
			return b.mode === 'ref' ? { type : 'html', block : b.block } : { type : 'html', html : b.html };

		if( b.type === 'slot' )
			return { type : 'slot', name : b.name };

		if( b.type === 'input' )
			return { type : 'input', name : b.name, label : b.label, multiline : b.multiline, required : b.required };

		if( b.type === 'signature' ) {

			const number = b.number.trim();
			const page = b.page === 'last' ? 'last' : ( b.page === 'first' ? 1 : ( /^[1-9][0-9]{0,3}$/.test( number ) === true ? Number( number ) : number ) );

			return { type : 'signature', name : b.name, party : b.party, label : b.label, page : page, x : millimetres( b.x ), y : millimetres( b.y ), w : millimetres( b.w ), h : millimetres( b.h ), line : b.line };
		}

		return { type : 'pagebreak' };
	};

	/**
	 *	@param		{string}	name						What a person called a template
	 *	@param		{string}	tag
	 *	@param		{number}	[attempt]				1 for the first key, 2 and on for the keys after a key that was taken
	 *
	 *	@return		{string}									The key the template is saved under: the name as a slug (umlauts spelled out), else the tag with -vorlage; -2, -3 ... on a later attempt
	 */
	const templateKey = function( name, tag, attempt ) {

		const umlauts = { 'ä' : 'ae', 'ö' : 'oe', 'ü' : 'ue', 'ß' : 'ss' };
		let slug = String( name ).toLowerCase().replace( /[äöüß]/g, function( char ) { return umlauts[char] } ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, 34 ).replace( /-+$/, '' );

		if( /^[a-z][a-z0-9-]+$/.test( slug ) === false )
			slug = ( tag+ '-vorlage' ).slice( 0, 34 );

		return attempt === undefined || attempt < 2 ? slug : slug+ '-'+ attempt;
	};

	/**
	 *	A name no other block of the template has: feld, feld-2, ...
	 *
	 *	@param		{Array}		blocks
	 *	@param		{string}	base
	 *
	 *	@return		{string}
	 */
	const freeName = function( blocks, base ) {

		const taken = blocks.map( function( block ) { return block.name } );
		let name = base;
		let at = 2;

		while( taken.includes( name ) === true )
			name = base+ '-'+ ( at++ );

		return name;
	};

	/**
	 *	A block of a kind, as "Hinzufügen" makes it
	 *
	 *	@param		{Array}		blocks					The blocks there are
	 *	@param		{string}	kind						html, ref (with a key), slot (with a name), input, signature, pagebreak
	 *	@param		{string}	[what]
	 *
	 *	@return		{Object}
	 */
	const blockNew = function( blocks, kind, what ) {

		if( kind === 'ref' )
			return { type : 'html', mode : 'ref', block : what, html : '' };

		if( kind === 'slot' )
			return { type : 'slot', name : what };

		if( kind === 'input' )
			return { type : 'input', name : freeName( blocks, 'feld' ), label : '', multiline : false, required : false };

		if( kind === 'signature' )
			return { type : 'signature', name : freeName( blocks, 'unterschrift' ), party : 'owner', label : '', page : 'last', number : '2', x : '20', y : '230', w : '70', h : '20', line : true };

		return kind === 'pagebreak' ? { type : 'pagebreak' } : { type : 'html', mode : 'own', block : '', html : '' };
	};

	/**
	 *	@param		{string}	name						address, lines, totals - or the name another template gave a slot
	 *
	 *	@return		{string}									Its word, else the name
	 */
	const slotWord = function( name ) {
		return typeof state.texts['pdf.slot-'+ name] === 'string' ? t( 'pdf.slot-'+ name ) : name;
	};

	const pdfVorlage = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		const asks = where.key === undefined ? [ [ 'pdf/templates/list' ] ] : [ [ 'pdf/templates/get', { key : where.key } ] ];

		pdfRead( asks, mine, function( body, context ) {

			const used = where.key === undefined ? 0 : ( context.used[where.key] || 0 );

			if( where.key === undefined )
				drawPdfVorlage( { isNew : true, key : '', name : '', tag : where.tag, blocks : [] }, body, used );
			else
				drawPdfVorlage( { isNew : false, key : body.key, name : body.template.name, tag : body.template.tag, blocks : body.template.blocks.map( blockDraft ) }, body, used );
		} );
	};

	/**
	 *	The editor of a template: its name and tag, its blocks as cards in order, and what Hinzufügen offers
	 *
	 *	@param		{Object}	start						isNew, key, name, tag, blocks (as blockDraft makes them)
	 *	@param		{Object}	offer						tags, library, ranges (what the get and the list answer)
	 *	@param		{number}	used						How many documents name the template
	 *
	 *	@return		{void}
	 */
	const drawPdfVorlage = function( start, offer, used ) {

		const mine = state.view;
		const draft = Object.assign( { dirty : false, form : true, errors : {} }, start );
		const message = el( 'p', { class: 'state-note bad', id: 'tpl-note', role: 'alert' } );
		const box = el( 'div', { class: 'blocks', id: 'blocks' } );
		const library = offer.library;

		state.draft = draft;

		const touch = function() {
			draft.dirty = true;
			message.textContent = '';
			nameField.classList.remove( 'err' );
			tagField.classList.remove( 'err' );
		};

		const nameField = el( 'input', { id: 'tpl-name', type: 'text', value: draft.name, maxlength: String( offer.ranges.title ), autocomplete: 'off', placeholder: t( 'pdf.name' ), 'aria-label': t( 'pdf.name' ), on: { input: function( event ) { draft.name = event.target.value; touch() } } } );
		const tags = offer.tags.indexOf( draft.tag ) === -1 ? [ draft.tag ].concat( offer.tags ) : offer.tags;
		const tagField = el( 'select', { id: 'tpl-tag', value: draft.tag, 'aria-label': t( 'pdf.col-tag' ), on: { change: function( event ) { draft.tag = event.target.value; touch() } } }, tags.map( function( tag ) {
			return el( 'option', { value: tag, text: tagWord( tag )+ ( offer.tags.indexOf( tag ) === -1 ? ' ('+ t( 'pdf.tag-gone' )+ ')' : '' ) } );
		} ) );

		const shift = function( index, by ) {

			const to = index+ by;

			if( to < 0 || to >= draft.blocks.length )
				return;

			draft.blocks.splice( to, 0, draft.blocks.splice( index, 1 )[0] );
			draft.errors = {};
			draft.dirty = true;
			drawBlocks();
		};

		const drop = function( index ) {
			draft.blocks.splice( index, 1 );
			draft.errors = {};
			draft.dirty = true;
			drawBlocks();
		};

		const field = function( label, control ) {
			return el( 'label', { class: 'sf' }, el( 'span', { text: label } ), control );
		};

		const text = function( b, key, label, size ) {
			return field( label, el( 'input', { type: 'text', value: b[key], maxlength: size, autocomplete: 'off', dataset: { f: key }, on: { input: function( event ) { b[key] = event.target.value; draft.dirty = true } } } ) );
		};

		const check = function( b, key, label ) {
			return el( 'label', { class: 'check sf' }, el( 'input', { type: 'checkbox', checked: b[key] === true, dataset: { f: key }, on: { change: function( event ) { b[key] = event.target.checked; draft.dirty = true } } } ), el( 'span', { text: label } ) );
		};

		const choose = function( b, key, label, options ) {
			return field( label, el( 'select', { value: b[key], dataset: { f: key }, on: { change: function( event ) { b[key] = event.target.value; draft.dirty = true; drawBlocks() } } }, options.map( function( option ) { return el( 'option', { value: option[0], text: option[1] } ) } ) ) );
		};

		const kindOf = function( b ) {

			if( b.type === 'html' && b.mode === 'ref' ) {
				const known = library.find( function( one ) { return one.key === b.block } );
				return [ t( 'pdf.kind-html' ), t( 'pdf.kind-ref', known === undefined ? b.block : known.title ) ];
			}

			const extra = { slot : slotWord( b.name ), input : b.label, signature : t( 'pdf.party-'+ b.party )+ ( b.label === '' ? '' : ' · '+ b.label ) }[b.type];

			return [ t( 'pdf.kind-'+ b.type ), extra === undefined || extra === '' ? null : extra ];
		};

		const bodyOf = function( b, index ) {

			if( b.type === 'html' && b.mode === 'ref' ) {

				const known = library.some( function( one ) { return one.key === b.block } );
				const options = ( known === true ? [] : [ [ b.block, b.block ] ] ).concat( library.map( function( one ) { return [ one.key, one.title ] } ) );

				return [ choose( b, 'block', t( 'pdf.block-of' ), options ), el( 'p', { class: 'state-note' }, t( 'pdf.ref-note' ), ' ', known === true ? link( '#pdf-baustein-'+ b.block, t( 'pdf.ref-edit' ) ) : null ) ];
			}

			if( b.type === 'html' ) {

				const area = el( 'textarea', { class: 'code', rows: 7, spellcheck: 'false', maxlength: String( offer.ranges.html ), 'aria-label': t( 'pdf.kind-html' ), value: b.html, on: { input: function( event ) { b.html = event.target.value; draft.dirty = true } } } );

				return withPlaceholders( area, false, function() { b.html = area.value; draft.dirty = true }, el( 'span', { class: 'state-note', text: t( 'pdf.html-note' ) } ) );
			}

			if( b.type === 'slot' )
				return [ choose( b, 'name', t( 'pdf.slot' ), ( PDF_SLOTS.indexOf( b.name ) === -1 ? [ b.name ] : [] ).concat( PDF_SLOTS ).map( function( name ) { return [ name, slotWord( name ) ] } ) ), el( 'p', { class: 'state-note', text: typeof state.texts['pdf.slot-note-'+ b.name] === 'string' ? t( 'pdf.slot-note-'+ b.name ) : '' } ) ];

			if( b.type === 'input' )
				return [ el( 'div', { class: 'sig' }, text( b, 'name', t( 'pdf.input-name' ), '40' ), text( b, 'label', t( 'pdf.input-label' ), String( offer.ranges.label ) ) ), el( 'div', { class: 'sig' }, check( b, 'multiline', t( 'pdf.input-multiline' ) ), check( b, 'required', t( 'pdf.input-required' ) ) ), el( 'p', { class: 'state-note', text: t( 'pdf.input-note' ) } ) ];

			if( b.type === 'signature' )
				return [
					el( 'div', { class: 'sig' }, choose( b, 'party', t( 'pdf.sig-party' ), [ [ 'owner', t( 'pdf.party-owner' ) ], [ 'member', t( 'pdf.party-member' ) ] ] ), text( b, 'name', t( 'pdf.input-name' ), '40' ), text( b, 'label', t( 'pdf.input-label' ), String( offer.ranges.label ) ) ),
					el( 'div', { class: 'sig' }, choose( b, 'page', t( 'pdf.sig-page' ), [ [ 'first', t( 'pdf.page-first' ) ], [ 'last', t( 'pdf.page-last' ) ], [ 'number', t( 'pdf.page-number' ) ] ] ), b.page === 'number' ? text( b, 'number', t( 'pdf.page-no' ), '4' ) : null ),
					el( 'div', { class: 'sig' }, text( b, 'x', t( 'pdf.sig-x' ), '8' ), text( b, 'y', t( 'pdf.sig-y' ), '8' ), text( b, 'w', t( 'pdf.sig-w' ), '8' ), text( b, 'h', t( 'pdf.sig-h' ), '8' ) ),
					check( b, 'line', t( 'pdf.sig-line' ) ),
					el( 'p', { class: 'state-note', text: t( 'pdf.sig-note', offer.paper[0], offer.paper[1] ) } ),
				];

			return el( 'p', { class: 'state-note', text: t( 'pdf.pagebreak-note' ) } );
		};

		const blockCard = function( b, index ) {

			const kind = kindOf( b );
			const err = el( 'p', { class: 'state-note bad berr', role: 'alert', text: draft.errors[index] || '' } );

			return el( 'div', { class: 'block'+ ( draft.errors[index] === undefined ? '' : ' err' ), dataset: { i: index, type: b.type }, on: { input: function() { err.textContent = ''; delete draft.errors[index] } } },
				el( 'div', { class: 'bmain' },
					el( 'span', { class: 'kind' }, kind[0], kind[1] === null ? null : [ ' ', el( 'span', { class: 'tag', text: kind[1] } ) ] ),
					bodyOf( b, index ), err ),
				el( 'div', { class: 'ctl' },
					el( 'button', { type: 'button', class: 'up', 'aria-label': t( 'pdf.up' ), title: t( 'pdf.up' ), text: '↑', disabled: index === 0, on: { click: function() { shift( index, -1 ) } } } ),
					el( 'button', { type: 'button', class: 'down', 'aria-label': t( 'pdf.down' ), title: t( 'pdf.down' ), text: '↓', disabled: index === draft.blocks.length - 1, on: { click: function() { shift( index, 1 ) } } } ),
					el( 'button', { type: 'button', class: 'del', 'aria-label': t( 'pdf.remove' ), title: t( 'pdf.remove' ), text: '×', on: { click: function() { drop( index ) } } } ) ) );
		};

		const drawBlocks = function() {

			clear( box );
			box.setAttribute( 'aria-label', t( 'pdf.blocks' ) );

			if( draft.blocks.length === 0 )
				box.appendChild( el( 'p', { class: 'state-note', id: 'no-blocks', text: t( 'pdf.no-blocks' ) } ) );

			draft.blocks.forEach( function( b, index ) { box.appendChild( blockCard( b, index ) ) } );
		};

		const add = function( kind, what ) {
			draft.blocks.push( blockNew( draft.blocks, kind, what ) );
			draft.dirty = true;
			drawBlocks();
		};

		const payload = function( attempt ) {
			return { key : draft.isNew === true ? templateKey( draft.name, draft.tag, attempt ) : draft.key, isNew : draft.isNew, name : draft.name, tag : draft.tag, blocks : draft.blocks.map( blockPayload ) };
		};

		// The refusal of a save or a preview: beside the block it names, else beside the name or the tag, else under the head
		const refuse = function( answer, fallback ) {

			const sentence = failed( answer, fallback );
			const body = answer.body !== null && typeof answer.body === 'object' ? answer.body : {};
			const at = Array.isArray( body.params ) === true ? Number( body.params[0] ) : 0;

			if( body.field === 'blocks' && PDF_BLOCK_CODES.includes( body.code ) === true && Number.isInteger( at ) === true && at >= 1 && at <= draft.blocks.length ) {
				draft.errors[at - 1] = sentence;
				drawBlocks();

				if( typeof box.children[at - 1].scrollIntoView === 'function' )
					box.children[at - 1].scrollIntoView();

				return;
			}

			message.textContent = sentence;

			if( body.field === 'name' || body.field === 'key' ) {
				nameField.classList.add( 'err' );
				nameField.focus();
			}
			else if( body.field === 'tag' ) {
				tagField.classList.add( 'err' );
				tagField.focus();
			}
		};

		const saveButton = el( 'button', { type: 'button', class: 'btn primary', id: 'tpl-save', title: t( 'save.shortcut' ), text: t( 'pdf.save' ), on: { click: function() { save( 1 ) } } } );
		const previewButton = el( 'button', { type: 'button', class: 'btn', id: 'tpl-preview', text: t( 'pdf.preview' ), on: { click: function() {

			// The sample is the saved template's: what is not saved is saved first, as the preview of a draft does
			if( draft.isNew === false && draft.dirty === false )
				openPreview( 'template/'+ draft.key );
			else
				save( 1, true );
		} } } );

		const save = function( attempt, preview ) {

			saveButton.disabled = true;

			api( 'pdf/templates/save', payload( attempt ) ).then( function( answer ) {

				saveButton.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok === true ) {
					draft.dirty = false;
					toast( t( 'pdf.saved' ) );

					if( preview === true )
						openPreview( 'template/'+ answer.body.key );

					if( draft.isNew === true )
						wn.location.hash = '#pdf-vorlage-'+ answer.body.key;

					return;
				}

				// A key the name gives that another template has: the next one, a few times
				if( draft.isNew === true && answer.body !== null && typeof answer.body === 'object' && answer.body.code === 'pdf_key_taken' && attempt < 9 ) {
					save( attempt+ 1, preview );
					return;
				}

				refuse( answer, 'pdf.error-save' );
			} );
		};

		const duplicate = function() {
			dialog( { title : t( 'pdf.duplicate' ), text : t( 'pdf.duplicate-text' ), yes : t( 'pdf.duplicate' ), fields : [ { name : 'name', label : t( 'pdf.name' ), value : t( 'pdf.copy-of', draft.name ) } ] } ).then( function( values ) {

				if( values === null || state.view !== mine )
					return;

				call( 'pdf/templates/duplicate', { key : draft.key, name : values.name }, 'pdf.error-duplicate' ).then( function( answer ) {
					if( answer.ok === true && state.view === mine ) {
						draft.dirty = false;
						toast( t( 'pdf.duplicated' ) );
						wn.location.hash = '#pdf-vorlage-'+ answer.body.key;
					}
				} );
			} );
		};

		const remove = function() {

			if( used > 0 ) {
				toast( t( 'pdf.delete-used', used ), true );
				return;
			}

			ask( { title : t( 'pdf.delete' ), text : t( 'pdf.delete-text', draft.name ), yes : t( 'pdf.delete' ), danger : true } ).then( function( yes ) {

				if( yes !== true || state.view !== mine )
					return;

				call( 'pdf/templates/delete', { key : draft.key }, 'pdf.error-delete' ).then( function( answer ) {
					if( answer.ok === true && state.view === mine ) {
						draft.dirty = false;
						toast( t( 'pdf.deleted' ) );
						wn.location.hash = '#pdf';
					}
				} );
			} );
		};

		const more = draft.isNew === true ? null : menu( { label : '⋯', aria : t( 'pdf.more' ), items : [ { label : t( 'pdf.duplicate' ), run : duplicate }, 'sep', { label : t( 'pdf.delete' ), danger : true, run : remove } ] } );

		const adder = el( 'div', { class: 'addblock', id: 'add' }, el( 'span', { class: 'state-note', text: t( 'pdf.add' ) } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'add-html', text: t( 'pdf.kind-html' ), on: { click: function() { add( 'html' ) } } } ),
			menu( { label : t( 'pdf.add-ref' ), button : 'sm', items : library.length === 0 ? [ { heading : t( 'pdf.add-ref-none' ) } ] : library.map( function( one ) { return { label : one.title, run : function() { add( 'ref', one.key ) } } } ) } ),
			menu( { label : t( 'pdf.add-slot' ), button : 'sm', items : PDF_SLOTS.map( function( name ) { return { label : slotWord( name ), run : function() { add( 'slot', name ) } } } ) } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'add-input', text: t( 'pdf.kind-input' ), on: { click: function() { add( 'input' ) } } } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'add-pagebreak', text: t( 'pdf.kind-pagebreak' ), on: { click: function() { add( 'pagebreak' ) } } } ),
			el( 'button', { type: 'button', class: 'btn sm', id: 'add-signature', text: t( 'pdf.kind-signature' ), on: { click: function() { add( 'signature' ) } } } ) );

		drawBlocks();
		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#pdf', t( 'pdf.title' ) ), ' › ', draft.isNew === true ? t( 'pdf.new-title' ) : draft.name ) );
		state.main.appendChild( el( 'div', { class: 'head' }, el( 'div', { class: 'tagline' }, nameField, tagField ), el( 'span', { class: 'grow' } ), previewButton, saveButton, more ) );
		append( state.main, [ message, box, adder, el( 'p', { class: 'state-note top', id: 'tpl-help', text: t( 'pdf.editor-note' ) } ) ] );
		saver( 'template', state.main, function() { save( 1 ) }, undefined, function() { return saveButton.disabled === true } );
	};

	// --- Bausteine ---------------------------------------------------------------------------------------------------

	/**
	 *	The legend of the placeholders: the vocabulary and the texts of the site, in one card
	 *
	 *	@return		{Object}
	 */
	const legendCard = function() {

		return el( 'div', { class: 'card pad', id: 'legend' }, el( 'h3', { text: t( 'pdf.legend' ) } ), el( 'p', { class: 'state-note', text: t( 'pdf.legend-note' ) } ),
			tokenGroups( true ).map( function( group ) {
				return [ el( 'h4', { text: group.title } ), el( 'table', { class: 'tbl static' }, el( 'tbody', {}, group.tokens.map( function( one ) {
					return el( 'tr', {}, el( 'td', {}, el( 'span', { class: 'num', text: '[['+ one.token+ ']]' } ) ), el( 'td', { class: 'muted', text: one.label } ) );
				} ) ) ) ];
			} ) );
	};

	const pdfBausteine = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		pdfRead( [ [ 'pdf/blocks/list' ] ], mine, function( list, context ) {

			const rows = list.blocks.map( function( block ) { return Object.assign( {}, block, { used : context.blocks[block.key] || [] } ) } );

			reveal();

			state.main.appendChild( head( t( 'pdf.blocks-title' ), el( 'span', { class: 'sub', text: t( 'pdf.blocks-sub' ) } ), el( 'span', { class: 'grow' } ), link( '#pdf-neuer-baustein', t( 'pdf.new-block' ), { class: 'btn primary', id: 'new-block' } ) ) );
			state.main.appendChild( pdfTabs( 'bausteine' ) );
			state.main.appendChild( el( 'div', { class: 'tpl-grid' },
				el( 'div', {}, el( 'div', { class: 'card wrap-x' }, el( 'table', { class: 'tbl', id: 'pdf-blocks' },
					el( 'thead', {}, el( 'tr', {}, el( 'th', { text: t( 'pdf.col-block' ) } ), el( 'th', { text: t( 'pdf.col-title' ) } ), el( 'th', { class: 'r', text: t( 'pdf.col-used-in' ) } ) ) ),
					el( 'tbody', {}, rows.length === 0
						? el( 'tr', { class: 'plain' }, el( 'td', { colspan: '3', class: 'muted', text: t( 'pdf.blocks-none' ) } ) )
						: rows.map( function( block ) {
							return el( 'tr', { dataset: { key: block.key }, on: { click: function() { wn.location.hash = '#pdf-baustein-'+ block.key } } },
								el( 'td', { class: 'num' }, link( '#pdf-baustein-'+ block.key, block.key ) ),
								el( 'td', { class: 'wrap' }, el( 'b', { text: block.title } ), el( 'div', { class: 'muted small', text: plainText( block.html ) } ) ),
								el( 'td', { class: 'r num' }, block.used.length > 0 ? String( block.used.length ) : el( 'span', { class: 'muted', text: '–' } ) ) );
						} ) ) ) ) ),
				legendCard() ) );
		} );
	};

	const pdfBaustein = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		pdfRead( [ [ 'pdf/blocks/list' ] ], mine, function( list, context ) {

			const found = where.key === undefined ? { key : '', title : '', html : '' } : list.blocks.find( function( block ) { return block.key === where.key } );

			if( found === undefined ) {
				reveal();
				state.main.appendChild( head( t( 'pdf.blocks-title' ) ) );
				state.main.appendChild( pdfTabs( 'bausteine' ) );
				state.main.appendChild( note( 'bad', el( 'span', { text: t( 'error.pdf_block_missing' ) } ), link( '#pdf-bausteine', t( 'pdf.back' ) ) ) );
				return;
			}

			drawPdfBaustein( found, where.key === undefined, list.ranges, context.blocks[found.key] || [] );
		} );
	};

	/**
	 *	The editor of a block: its key (a slug that stays after the first save), its title and its HTML with the menu of the placeholders
	 *
	 *	@param		{Object}	block						key, title, html
	 *	@param		{boolean}	isNew
	 *	@param		{Object}	ranges					The limits of the fields
	 *	@param		{Array}		users						The names of the templates that use it
	 *
	 *	@return		{void}
	 */
	const drawPdfBaustein = function( block, isNew, ranges, users ) {

		const mine = state.view;
		const form = formOf();
		const area = el( 'textarea', { id: 'f-html', class: 'code', rows: 14, spellcheck: 'false', maxlength: String( ranges.html ), 'aria-label': t( 'pdf.html' ), value: block.html } );
		const areaNote = el( 'span', { class: 'state-note bad', role: 'alert' } );

		form.controls.html = area;
		form.notes.html = areaNote;
		area.addEventListener( 'input', function() {
			area.classList.remove( 'err' );
			areaNote.textContent = '';
			form.dirty = true;
		} );

		const rows = formList( [
			{ name : 'key', label : t( 'pdf.block-key' ), control : el( 'input', { id: 'f-key', type: 'text', value: block.key, maxlength: '40', autocomplete: 'off', disabled: isNew === false } ), hint : t( 'pdf.block-key-hint' ) },
			{ name : 'title', label : t( 'pdf.block-title' ), control : el( 'input', { id: 'f-title', type: 'text', value: block.title, maxlength: String( ranges.title ), autocomplete: 'off' } ) },
		], form );

		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'block-save', title: t( 'save.shortcut' ), text: t( 'pdf.save' ) } );

		const remove = function() {

			if( users.length > 0 ) {
				toast( t( 'error.pdf_block_used', users.join( ', ' ) ), true );
				return;
			}

			ask( { title : t( 'pdf.delete' ), text : t( 'pdf.block-delete-text', block.title ), yes : t( 'pdf.delete' ), danger : true } ).then( function( yes ) {

				if( yes !== true || state.view !== mine )
					return;

				call( 'pdf/blocks/delete', { key : block.key }, 'pdf.error-delete' ).then( function( answer ) {
					if( answer.ok === true && state.view === mine ) {
						form.dirty = false;
						toast( t( 'pdf.deleted' ) );
						wn.location.hash = '#pdf-bausteine';
					}
				} );
			} );
		};

		const submit = function( event ) {

			event.preventDefault();
			save.disabled = true;

			const key = form.controls.key.value;

			api( 'pdf/blocks/save', { key : key, isNew : isNew, title : form.controls.title.value, html : area.value } ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'pdf.error-save' );
					return;
				}

				form.dirty = false;
				toast( t( 'pdf.saved' ) );

				// The answer is the library again: the block as the store keeps it - its HTML held to the list of the feature - is in it
				const kept = answer.body.blocks.find( function( one ) { return one.key === key } );

				if( isNew === true )
					wn.location.hash = '#pdf-baustein-'+ key;
				else if( kept !== undefined )
					area.value = kept.html;
			} );
		};

		reveal();

		state.main.appendChild( el( 'div', { class: 'crumb' }, link( '#pdf-bausteine', t( 'pdf.blocks-title' ) ), ' › ', isNew === true ? t( 'pdf.new-block' ) : block.title ) );
		const box = el( 'form', { id: 'block-form', novalidate: 'novalidate', on: { submit: submit } },
			head( isNew === true ? t( 'pdf.new-block' ) : block.title, el( 'span', { class: 'grow' } ),
				isNew === true ? null : menu( { label : '⋯', aria : t( 'pdf.more' ), items : [ { label : t( 'pdf.delete' ), danger : true, run : remove } ] } ), save ),
			el( 'div', { class: 'card pad' }, rows,
				el( 'div', { class: 'field' }, el( 'span', { text: t( 'pdf.html' ) } ), withPlaceholders( area, false, function() { form.dirty = true }, el( 'span', { class: 'state-note', text: t( 'pdf.html-note' ) } ) ), areaNote ),
				isNew === true ? null : el( 'p', { class: 'state-note', id: 'block-used', text: users.length === 0 ? t( 'pdf.block-unused' ) : t( 'pdf.block-used-by', users.join( ', ' ) ) } ) ) );

		state.main.appendChild( box );
		submitter( 'block', box, save );
	};

	// --- Layout ------------------------------------------------------------------------------------------------------

	/**
	 *	@param		{string}	text
	 *
	 *	@return		{number|string}						A whole number where the text is one; else the text, which the server refuses
	 */
	const wholeNumber = function( text ) {
		return /^\d{1,6}$/.test( text.trim() ) === true ? Number( text.trim() ) : text;
	};

	/**
	 *	The layout the way the server takes it: what a save posts of the fields that are on the screen
	 *
	 *	@param		{Object}	layout					The layout as the server has it
	 *
	 *	@return		{Object}
	 */
	const layoutPost = function( layout ) {
		return { format : layout.format, margins : layout.margins.slice(), font : layout.font, size : layout.size, header : layout.header, footer : layout.footer };
	};

	const pdfLayout = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		pdfRead( [ [ 'pdf/layout/get' ] ], mine, function( body ) { drawPdfLayout( body ) } );
	};

	/**
	 *	The card of a picture of the layout (the logo, the owner's signature): the picture, what it is for, the upload and the removal. The answer of
	 *	either is the layout again, which draws the card again
	 *
	 *	@param		{string}	kind						logo or signature
	 *	@param		{Object}	view						The answer of pdf/layout/get (the current one in .now)
	 *
	 *	@return		{Object}
	 */
	const pictureCard = function( kind, view ) {

		const mine = state.view;
		const stored = view.now.layout[kind];
		const shown = view.now.images[kind];
		const said = el( 'p', { class: 'state-note bad', role: 'alert' } );
		const take = function( answer ) {

			if( state.view !== mine )
				return;

			if( answer.ok !== true ) {
				said.textContent = errorText( answer, 'pdf.error-image' );
				return;
			}

			view.now = answer.body;
			toast( t( 'pdf.saved' ) );
			card.replaceWith( card = pictureCard( kind, view ) );
		};

		const file = el( 'input', { type: 'file', id: 'file-'+ kind, class: 'file', accept: 'image/png,image/jpeg', 'aria-label': t( 'pdf.upload-'+ kind ), on: { change: function( event ) {

			const chosen = event.target.files !== undefined && event.target.files !== null ? event.target.files[0] : undefined;

			if( chosen === undefined )
				return;

			said.textContent = '';
			upload( 'pdf/layout/image', { kind : kind }, chosen ).then( function( answer ) {
				if( answer.ok !== true )
					failed( answer, 'pdf.error-image' );

				take( answer );
			} );
		} } } );

		const drop = function() {
			ask( { title : t( 'pdf.delete' ), text : t( 'pdf.picture-delete' ), yes : t( 'pdf.delete' ), danger : true } ).then( function( yes ) {

				if( yes !== true || state.view !== mine )
					return;

				call( 'pdf/layout/image/delete', { kind : kind }, 'pdf.error-image' ).then( take );
			} );
		};

		let card = el( 'div', { class: 'card pad', id: 'pic-'+ kind }, el( 'h3', { text: t( 'pdf.'+ kind ) } ),
			stored === '' ? el( 'p', { class: 'state-note', text: t( 'pdf.picture-none' ) } )
				: ( shown === '' ? el( 'p', { class: 'state-note', text: t( 'pdf.picture-large', stored ) } ) : el( 'div', { class: 'pic' }, el( 'img', { src: shown, alt: t( 'pdf.'+ kind ) } ) ) ),
			el( 'p', { class: 'state-note', text: t( 'pdf.picture-hint-'+ kind ) } ),
			el( 'div', { class: 'actions' }, el( 'label', { class: 'btn sm', for: 'file-'+ kind }, stored === '' ? t( 'pdf.upload' ) : t( 'pdf.replace' ) ), file,
				stored === '' ? null : el( 'button', { type: 'button', class: 'btn sm danger', id: 'drop-'+ kind, text: t( 'pdf.delete' ), on: { click: drop } } ) ),
			said );

		return card;
	};

	/**
	 *	The screen Layout: the paper and the font, the margins, the header and the footer, the two pictures, and a preview of a page
	 *
	 *	@param		{Object}	body						The answer of pdf/layout/get
	 *
	 *	@return		{void}
	 */
	const drawPdfLayout = function( body ) {

		const mine = state.view;
		const layout = body.layout;
		const form = formOf();
		const view = { now : body };
		const margins = layout.margins.map( function( mm, at ) {
			return el( 'input', { type: 'text', inputmode: 'numeric', value: String( mm ), maxlength: '3', autocomplete: 'off', class: 'mm', 'aria-label': t( 'pdf.margin-'+ [ 'top', 'right', 'bottom', 'left' ][at] ), dataset: { margin: at } } );
		} );
		const marginBox = el( 'div', { id: 'f-margins', class: 'pair' }, margins, el( 'span', { class: 'state-note', text: t( 'pdf.margins-hint' ) } ) );
		const header = el( 'textarea', { id: 'f-header', class: 'code', rows: 5, spellcheck: 'false', maxlength: String( body.ranges.band ), value: layout.header } );
		const footer = el( 'textarea', { id: 'f-footer', class: 'code', rows: 5, spellcheck: 'false', maxlength: String( body.ranges.band ), value: layout.footer } );

		margins.forEach( function( input ) { input.addEventListener( 'input', function() { form.dirty = true } ) } );
		marginBox.focus = function() { margins[0].focus() };

		const fields = formList( [
			{ name : 'format', label : t( 'pdf.paper' ), control : el( 'select', { id: 'f-format', value: layout.format }, body.papers.map( function( paper ) { return el( 'option', { value: paper, text: t( 'pdf.paper-'+ paper ) } ) } ) ) },
			{ name : 'font', label : t( 'pdf.font' ), control : el( 'select', { id: 'f-font', value: layout.font }, body.fonts.map( function( font ) { return el( 'option', { value: font, text: t( 'pdf.font-'+ font ) } ) } ) ) },
			{ name : 'size', label : t( 'pdf.size' ), control : el( 'input', { id: 'f-size', type: 'text', inputmode: 'numeric', value: String( layout.size ), maxlength: '3', autocomplete: 'off', class: 'short' } ), hint : t( 'pdf.size-hint', body.ranges.size[0], body.ranges.size[1] ) },
			{ name : 'margins', label : t( 'pdf.margins' ), control : marginBox },
		], form );

		const band = function( name, control, label ) {

			const said = el( 'span', { class: 'state-note bad', role: 'alert' } );

			form.controls[name] = control;
			form.notes[name] = said;
			control.addEventListener( 'input', function() {
				control.classList.remove( 'err' );
				said.textContent = '';
				form.dirty = true;
			} );

			return el( 'div', { class: 'card pad', id: 'band-'+ name }, el( 'h3', { text: label } ), withPlaceholders( control, true, function() { form.dirty = true }, el( 'span', { class: 'state-note', text: t( 'pdf.band-note' ) } ) ), said );
		};

		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'layout-save', title: t( 'save.shortcut' ), text: t( 'pdf.save' ) } );
		const preview = previewLink( 'layout-preview' );

		const submit = function( event ) {

			event.preventDefault();
			save.disabled = true;

			const data = { format : form.controls.format.value, font : form.controls.font.value, size : wholeNumber( form.controls.size.value ), margins : margins.map( function( input ) { return wholeNumber( input.value ) } ), header : header.value, footer : footer.value };

			api( 'pdf/layout/save', data ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {
					refuseAt( form, answer, 'pdf.error-save' );
					return;
				}

				form.dirty = false;
				view.now = answer.body;
				toast( t( 'pdf.saved' ) );
			} );
		};

		reveal();

		const box = el( 'form', { id: 'layout-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'pdf.layout-title' ), el( 'span', { class: 'sub', text: t( 'pdf.layout-sub' ) } ), el( 'span', { class: 'grow' } ), preview, save ),
			pdfTabs( 'layout' ),
			el( 'div', { class: 'stack' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'pdf.paper-font' ) } ), fields ),
				band( 'header', header, t( 'pdf.header' ) ),
				band( 'footer', footer, t( 'pdf.footer' ) ),
				el( 'div', { class: 'cols' }, pictureCard( 'logo', view ), pictureCard( 'signature', view ) ),
				el( 'p', { class: 'state-note', text: t( 'pdf.layout-note' ) } ) ) );

		state.main.appendChild( box );
		submitter( 'layout', box, save );
	};

	// --- Design ------------------------------------------------------------------------------------------------------

	/**
	 *	Select a line of a text in its field, so that the person lands where a refusal says
	 *
	 *	@param		{Object}	area
	 *	@param		{number}	line						From 1
	 *
	 *	@return		{void}
	 */
	const selectLine = function( area, line ) {

		const lines = area.value.split( '\n' );

		if( line < 1 || line > lines.length )
			return;

		const from = lines.slice( 0, line - 1 ).reduce( function( sum, one ) { return sum+ one.length+ 1 }, 0 );

		area.focus();
		area.selectionStart = from;
		area.selectionEnd = from+ lines[line - 1].length;
	};

	const pdfDesign = function( where, mine ) {

		if( pdfAllowed() === false )
			return;

		pdfRead( [ [ 'pdf/layout/get' ] ], mine, function( body ) { drawPdfDesign( body ) } );
	};

	/**
	 *	The screen Design: the rules of the feature as they are, read only, and the owner's rules over them with the refusal that a text which is no
	 *	sheet gets - the line, and the sentence of the panel - and a preview of the design as it is saved
	 *
	 *	@param		{Object}	body						The answer of pdf/layout/get
	 *
	 *	@return		{void}
	 */
	const drawPdfDesign = function( body ) {

		const mine = state.view;
		const form = formOf();
		const area = el( 'textarea', { id: 'f-design', class: 'sheet', rows: 12, spellcheck: 'false', maxlength: String( body.ranges.design ), placeholder: t( 'pdf.design-placeholder' ), 'aria-label': t( 'pdf.design-own' ), value: body.layout.design } );
		const said = el( 'p', { class: 'state-note bad', id: 'design-refusal', role: 'alert' } );
		const stored = el( 'p', { class: 'note bad', id: 'design-stored', role: 'alert', hidden: body.refusal === null || body.refusal === undefined, text: body.refusal === null || body.refusal === undefined ? '' : t( 'pdf.design-stored', errorText( { status : 400, body : body.refusal } ) ) } );

		form.controls.design = area;
		form.notes.design = said;
		area.addEventListener( 'input', function() {
			area.classList.remove( 'err' );
			said.textContent = '';
			form.dirty = true;
		} );

		const save = el( 'button', { type: 'submit', class: 'btn primary', id: 'design-save', title: t( 'save.shortcut' ), text: t( 'pdf.save' ) } );
		const preview = previewLink( 'design-preview' );

		const submit = function( event ) {

			event.preventDefault();
			save.disabled = true;

			api( 'pdf/layout/save', Object.assign( layoutPost( body.layout ), { design : area.value } ) ).then( function( answer ) {

				save.disabled = false;

				if( state.view !== mine )
					return;

				if( answer.ok !== true ) {

					const sentence = refuseAt( form, answer, 'pdf.error-save' );
					const params = answer.body !== null && typeof answer.body === 'object' && Array.isArray( answer.body.params ) === true ? answer.body.params : [];

					// A refusal of the design names a line: it is selected
					if( answer.body !== null && typeof answer.body === 'object' && answer.body.field === 'design' && /^pdf_design_/.test( String( answer.body.code ) ) === true && Number.isInteger( Number( params[0] ) ) === true ) {
						said.textContent = sentence;
						selectLine( area, Number( params[0] ) );
					}

					return;
				}

				form.dirty = false;
				body = answer.body;
				stored.hidden = true;
				stored.textContent = '';
				toast( t( 'pdf.saved' ) );
			} );
		};

		reveal();

		const box = el( 'form', { id: 'design-form', novalidate: 'novalidate', on: { submit: submit } },
			head( t( 'pdf.design-title' ), el( 'span', { class: 'sub', text: t( 'pdf.design-sub' ) } ), el( 'span', { class: 'grow' } ), preview, save ),
			pdfTabs( 'design' ),
			stored,
			el( 'div', { class: 'cols' },
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'pdf.design-sheet' ) } ), el( 'pre', { class: 'sheet ro', id: 'design-sheet', tabIndex: 0, text: body.sheet } ), el( 'p', { class: 'state-note', text: t( 'pdf.design-sheet-note' ) } ) ),
				el( 'div', { class: 'card pad' }, el( 'h3', { text: t( 'pdf.design-own' ) } ), area, said, el( 'p', { class: 'state-note', text: t( 'pdf.design-own-note' ) } ) ) ) );

		state.main.appendChild( box );
		submitter( 'design', box, save );
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

		found = /^neu-(invoice|offer|contract|privacy|information)(?:-([1-9][0-9]{0,9}))?(?:-k([1-9][0-9]{0,9})(?:-p([A-Za-z0-9_]{1,40}))?)?$/.exec( text );

		if( found !== null )
			return { screen : 'entwurf', category : found[1], offer : found[2] === undefined ? '' : found[2], client : found[3] === undefined ? '' : found[3], project : found[4] === undefined ? '' : found[4] };

		if( text === 'kunden' )
			return { screen : 'kunden' };

		if( text === 'kunde-neu' )
			return { screen : 'kundeneu' };

		found = /^kunde-([1-9][0-9]{0,9})(?:-(belege|projekte|portal|notizen|einladen))?$/.exec( text );

		if( found !== null )
			return { screen : 'kunde', id : Number( found[1] ), tab : found[2] === undefined ? '' : found[2] };

		if( text === 'projekte' )
			return { screen : 'projekte' };

		found = /^projekte-neu(?:-k([1-9][0-9]{0,9}))?$/.exec( text );

		if( found !== null )
			return { screen : 'projektneu', client : found[1] === undefined ? '' : found[1] };

		found = /^projekt-([A-Za-z0-9_]{1,40})(?:-(belege|dateien))?$/.exec( text );

		if( found !== null )
			return { screen : 'projekt', id : found[1], tab : found[2] === undefined ? '' : found[2] };

		if( text === 'positionen' )
			return { screen : 'positionen' };

		if( text === 'positionen-neu' )
			return { screen : 'position' };

		found = /^position-([A-Za-z0-9][A-Za-z0-9_-]{0,99})$/.exec( text );

		if( found !== null )
			return { screen : 'position', uri : found[1] };

		if( text === 'einstellungen' )
			return { screen : 'einstellungen' };

		if( text === 'pdf' )
			return { screen : 'pdfvorlagen' };

		if( text === 'pdf-bausteine' )
			return { screen : 'pdfbausteine' };

		if( text === 'pdf-layout' )
			return { screen : 'pdflayout' };

		if( text === 'pdf-design' )
			return { screen : 'pdfdesign' };

		if( text === 'pdf-neuer-baustein' )
			return { screen : 'pdfbaustein' };

		found = /^pdf-vorlage-([a-z][a-z0-9-]{1,39})$/.exec( text );

		if( found !== null )
			return { screen : 'pdfvorlage', key : found[1] };

		found = /^pdf-neue-vorlage-([a-z][a-z0-9-]{1,39})$/.exec( text );

		if( found !== null )
			return { screen : 'pdfvorlage', tag : found[1] };

		found = /^pdf-baustein-([a-z][a-z0-9-]{1,39})$/.exec( text );

		if( found !== null )
			return { screen : 'pdfbaustein', key : found[1] };

		if( text === 'einrichtung' )
			return { screen : 'einrichtung' };

		return { screen : 'uebersicht' };
	};

	const screens = { uebersicht : uebersicht, belege : belege, beleg : beleg, entwurf : entwurf, kunden : kunden, kunde : kunde, kundeneu : kundeNeu, projekte : projekte, projektneu : projektNeu, projekt : projekt, positionen : positionen, position : position, einstellungen : einstellungen, einrichtung : einrichtung,
		pdfvorlagen : pdfVorlagen, pdfvorlage : pdfVorlage, pdfbausteine : pdfBausteine, pdfbaustein : pdfBaustein, pdflayout : pdfLayout, pdfdesign : pdfDesign };
	const rail = { uebersicht : 'uebersicht', belege : 'belege', beleg : 'belege', entwurf : 'belege', kunden : 'kunden', kunde : 'kunden', kundeneu : 'kunden', projekte : 'projekte', projektneu : 'projekte', projekt : 'projekte', positionen : 'positionen', position : 'positionen', einstellungen : 'einstellungen', einrichtung : '',
		pdfvorlagen : 'pdf', pdfvorlage : 'pdf', pdfbausteine : 'pdf', pdfbaustein : 'pdf', pdflayout : 'pdf', pdfdesign : 'pdf' };

	const route = function() {

		const where = parse( wn.location.hash );

		state.view++;
		state.draft = null;
		state.saver = {};
		closeMenus();

		// An item is being saved: the screen that was asked for is drawn once the posts are through, so that it holds what they made
		if( state.busy !== null ) {
			clear( state.main );
			state.main.appendChild( el( 'p', { class: 'state-note', role: 'status', text: t( 'items.saving' ) } ) );
			state.busy.then( function() { route() } );
			return;
		}

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
		state.perms = Object.assign( { crm : true, pdf : false, elements : false, text : false, files : false, members : false, sign : false }, data.perms );
		state.pdfAvailable = data.pdf_available !== false;
		state.user = data.user.name;
		state.texts = data.texts;
		state.links = data.links;
		state.main = dc.getElementById( 'main' );
		state.toast = dc.getElementById( 'toast' );
		state.dialogs = dc.getElementById( 'dialogs' );
		state.count = dc.getElementById( 'n-belege' );
		state.clientCount = dc.getElementById( 'n-kunden' ) ?? null;

		// The entry PDF-Vorlagen is in the rail for an account with the permission of the Pdf panel only
		const entry = dc.getElementById( 'nav-pdf' );

		if( entry !== null && entry !== undefined )
			entry.hidden = state.perms.pdf !== true;

		wn.addEventListener( 'hashchange', route );

		dc.addEventListener( 'click', function( event ) {

			const target = event.target !== null && event.target !== undefined && typeof event.target.closest === 'function' ? event.target : null;

			closeMenus( target === null ? null : target.closest( '.menu' ) );

			// A save is running: the way out waits for it (see route()), and nothing is asked
			if( state.busy !== null && typeof target?.closest === 'function' && target.closest( 'a' ) !== null && String( target.closest( 'a' ).getAttribute( 'href' ) ).charAt( 0 ) === '#' ) {
				toast( t( 'items.saving' ) );
				return;
			}

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

			// Strg+S or Cmd+S, not with Alt or Shift: as the workbench has it
			if( ( event.ctrlKey === true || event.metaKey === true ) && event.altKey !== true && event.shiftKey !== true && String( event.key ).toLowerCase() === 's' ) {
				shortcut( event );
				return;
			}

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
		model : { docName : docName, visible : visible, visibleClients : visibleClients, clientTitle : clientTitle, ratesText : ratesText, chips : chips, draftOf : draftOf, draftNew : draftNew, payloadOf : payloadOf, totalsOf : totalsOf, ready : ready, lineOf : lineOf, primaryOf : primaryOf, tabsOf : tabsOf, errorText : errorText,
			templateKey : templateKey, positionKey : positionKey, positionDraftOf : positionDraftOf, positionPosts : positionPosts, blockDraft : blockDraft, blockPayload : blockPayload, millimetres : millimetres, tokenGroups : tokenGroups, insertAt : insertAt },
	};

	if( dc.readyState === 'loading' )
		dc.addEventListener( 'DOMContentLoaded', function() { boot() } );
	else if( dc.readyState === 'interactive' || dc.readyState === 'complete' )
		boot();

} )( window, document );

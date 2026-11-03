/**
 *	Crm\Documents\Admin		The panel Belege of the CRM module: the list of the documents with its filters and a search, the
 *												editor of a draft (client, dates, the template of Pdf and its inputs, the lines with the live
 *												totals, notes), the page of a finalised document (the steps, the PDF, the documents it comes from
 *												and the ones that come from it) and the screen of the settings. Draws into the one region of
 *												templates/panel.tpl with the workbench's own builders; every word is a fill, every value of the
 *												server goes in as text, and every request goes through the panel's call(), which posts
 *												crm-documents/<action>. The totals the editor shows while a person types are those of money.js,
 *												which mirrors Money.php; the numbers of a finalised document are the server's.
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	const money = wn.Nino.crm.money;
	const K = '/_admin/crm-documents/';

	// The steps a finalised document offers by category and state: [ state to set, label key ]
	const STEPS = {
		offer   : { open : [ [ 'sent', 'sent' ], [ 'accepted', 'accepted' ], [ 'declined', 'declined' ] ], sent : [ [ 'accepted', 'accepted' ], [ 'declined', 'declined' ] ] },
		invoice : { open : [ [ 'sent', 'sent' ] ], sent : [ [ 'paid', 'paid' ] ] },
		credit  : { open : [ [ 'sent', 'sent' ] ] },
	};

	// The settings in the order the screen draws them: [ key, kind ]
	const SETTINGS = [
		[ 'vat_mode', 'select' ], [ 'vat_rates', 'text' ], [ 'payment_days', 'text' ], [ 'offer_days', 'text' ], [ 'currency', 'text' ],
		[ 'number_offer', 'text' ], [ 'number_invoice', 'text' ], [ 'number_credit', 'text' ],
	];

	const docs = Nino.adminUi.panel( {
		uri			: 'crm-documents',
		mount		: 'crm-documents-app',
		payload	: function() { return Object.assign( {}, Nino.admin['crm-documents']._filter ) },
		dirty		: {
			isDirty : function() { return Nino.admin['crm-documents']._dirty() },
			save		: function( done ) { Nino.admin['crm-documents']._save( done ) },
			discard : function() { Nino.admin['crm-documents']._discard() },
		},
		render	: function( data ) {

			const panel = Nino.admin['crm-documents'];

			panel._documents = Array.isArray( data.documents ) === true ? data.documents : [];
			panel._years = Array.isArray( data.years ) === true ? data.years : [];
			panel._clients = Array.isArray( data.clients ) === true ? data.clients : [];
			panel._settings = data.settings ?? panel._settings;
			panel._catalogue = Array.isArray( data.items ) === true ? data.items : [];
			panel._card = null;
			panel._render();
		},
	} );

	Nino.admin['crm-documents'] = docs;

	Object.assign( docs, {

		_documents	: [],
		_years			: [],
		_clients		: [],
		_catalogue	: [],
		_settings		: { vat_mode : 'small_business', rates : [], currency : '' },
		_filter			: { category : '', state : '', year : '', client : '', search : '' },
		_status			: null,
		// The open editor: { document (null for a new one), category, view, form, save, line, fields, rows, ... } - null while the list shows
		_card				: null,

		_text : function( key, ...params ) {
			return Nino.adminUi.format( Nino.content.getText( key ), ...params );
		},

		_line : function() {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ) );
		},

		/**
		 *	@param		{string}	ymd						Y-m-d
		 *
		 *	@return		{string}									d.m.Y; '' for none
		 */
		_day : function( ymd ) {
			return typeof ymd === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( ymd ) === true ? ymd.slice( 8, 10 )+ '.'+ ymd.slice( 5, 7 )+ '.'+ ymd.slice( 0, 4 ) : '';
		},

		/**
		 *	@return		{string}									Today, Y-m-d
		 */
		_today : function() {

			const now = new Date();

			return now.getFullYear()+ '-'+ String( now.getMonth()+ 1 ).padStart( 2, '0' )+ '-'+ String( now.getDate() ).padStart( 2, '0' );
		},

		/**
		 *	@param		{string}	ymd
		 *	@param		{number}	days
		 *
		 *	@return		{string}									The day $days after it, Y-m-d
		 */
		_addDays : function( ymd, days ) {
			return new Date( Date.UTC( Number( ymd.slice( 0, 4 ) ), Number( ymd.slice( 5, 7 ) ) - 1, Number( ymd.slice( 8, 10 ) ) + days ) ).toISOString().slice( 0, 10 );
		},

		/**
		 *	@param		{Object}	client			id, number, title
		 *
		 *	@return		{string}
		 */
		_clientName : function( client ) {
			return client === null || client === undefined ? '' : ( client.title ?? ( client.company !== '' ? client.company : client.name ) );
		},

		/**
		 *	The list screen: the status of the module, the filters, the documents and the buttons for a new one
		 *
		 *	@return		void
		 */
		_render : function() {

			const mount = docs.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			docs._card = null;

			const status = Nino.adminUi.el( 'div', 'crm-status' );
			status.id = 'crm-status';
			mount.appendChild( status );
			docs._drawStatus( status );

			mount.appendChild( docs._filters() );

			if( docs._documents.length === 0 ) {
				const filtered = Object.keys( docs._filter ).some( function( name ) { return docs._filter[name] !== '' } );
				mount.appendChild( Nino.adminUi.emptyState( Nino.content.getText( filtered === true ? K+ 'empty/found' : K+ 'empty/documents' ) ) );
			}
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list' );

				docs._documents.forEach( function( document ) { list.appendChild( docs._row( document ) ) } );
				mount.appendChild( list );
			}

			const buttons = [ 'invoice', 'offer', 'credit' ].map( function( category ) {
				return Nino.adminUi.button( Nino.content.getText( K+ 'label/new-'+ category ), function() { docs._new( category ) }, { className : category === 'invoice' ? 'nino-admin-btn-primary' : 'nino-admin-btn-secondary' } );
			} );

			buttons.push( Nino.adminUi.button( Nino.content.getText( K+ 'label/settings' ), function() { docs._openSettings() }, { className : 'nino-admin-btn-secondary' } ) );
			mount.appendChild( Nino.adminUi.listActions( buttons ) );
		},

		/**
		 *	The form of the filters: category, state, year, client and a search
		 *
		 *	@return		{Element}
		 */
		_filters : function() {

			const form = Nino.adminUi.el( 'form', 'crm-filters' );
			const word = function( key ) { return Nino.content.getText( K+ key ) };
			const choose = function( name, all, options ) {

				const field = Nino.adminUi.selectField( {
					key			: name,
					label		: word( 'filter/'+ name ),
					value		: docs._filter[name],
					options	: [ { value : '', label : word( all ) } ].concat( options ),
				} );

				field.querySelector('select').name = name;

				return field;
			};

			const states = [ 'draft', 'open', 'sent', 'paid', 'cancelled', 'accepted', 'declined', 'expired' ].map( function( state ) { return { value : state, label : word( 'state/'+ state ) } } );

			form.appendChild( choose( 'category', 'filter/all', docs._categories().map( function( category ) { return { value : category, label : word( 'category/'+ category ) } } ) ) );
			form.appendChild( choose( 'state', 'filter/all', states ) );
			form.appendChild( choose( 'year', 'filter/all', docs._years.map( function( year ) { return { value : String( year ), label : String( year ) } } ) ) );
			form.appendChild( choose( 'client', 'filter/all', docs._clients.map( function( client ) { return { value : String( client.id ), label : client.number+ ' '+ client.title } } ) ) );

			const search = Nino.adminUi.textField( { label : word( 'label/search' ), value : docs._filter.search, maxLength : 100, type : 'search', autocomplete : 'off' } );
			search.querySelector('input').name = 'search';
			form.appendChild( search );
			form.appendChild( Nino.adminUi.button( word( 'label/find' ), null, { type : 'submit', className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			form.addEventListener( 'submit', function( event ) {

				event.preventDefault();

				[ 'category', 'state', 'year', 'client' ].forEach( function( name ) { docs._filter[name] = form.querySelector( '[name="'+ name+ '"]' ).value } );
				docs._filter.search = search.querySelector('input').value;
				docs.reload();
			} );

			return form;
		},

		_categories : function() {
			return [ 'offer', 'invoice', 'credit' ];
		},

		/**
		 *	@param		{Object}	document			As list() has it
		 *
		 *	@return		{Element}
		 */
		_row : function( document ) {

			const li = Nino.adminUi.el( 'li' );
			const row = Nino.adminUi.el( 'button', 'nino-admin-list-link' );
			const copy = Nino.adminUi.el( 'span', 'nino-admin-list-copy' );
			const details = [ Nino.content.getText( K+ 'category/'+ document.category ), document.client_title, docs._day( document.date ), money.format( document.gross, docs._settings.currency ) ];

			row.type = 'button';
			row.dataset.id = String( document.id );
			copy.appendChild( Nino.adminUi.el( 'strong', '', document.number !== '' ? document.number : Nino.content.getText( K+ 'label/draft' ) ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', details.filter( function( part ) { return part !== '' } ).join(' · ') ) );
			row.appendChild( copy );
			row.appendChild( docs._badge( document ) );
			row.addEventListener( 'click', function() { docs._open( document.id ) } );
			li.appendChild( row );

			return li;
		},

		/**
		 *	@param		{Object}	document			With a state and, for an offer, expired
		 *
		 *	@return		{Element}									The badge of its state; an offer past its validity wears the badge of that
		 */
		_badge : function( document ) {

			const state = document.expired === true ? 'expired' : document.state;
			const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText( K+ 'state/'+ state ) );

			// A state that is done well is ok, one that is over or not begun is muted, an offer past its day warns; the rest is a badge as it is
			const kind = { paid : 'ok', accepted : 'ok', draft : 'muted', cancelled : 'muted', declined : 'muted', expired : 'warn' }[state];

			if( kind !== undefined )
				badge.dataset.state = kind;

			return badge;
		},

		/**
		 *	The status of the module: one line each for what it needs
		 *
		 *	@param		{Element}	box
		 *
		 *	@return		void
		 */
		_drawStatus : function( box ) {

			docs.call( 'status', {}, function( status, response ) {

				box.innerHTML = '';

				if( status !== 200 ) {
					Nino.adminUi.showError( box, status, response, K+ 'error/status' );
					return;
				}

				docs._status = Array.isArray( response.items ) === true ? response.items : [];

				const missing = docs._status.filter( function( item ) { return item.state !== 'ok' } );

				if( missing.length === 0 ) {
					box.appendChild( Nino.adminUi.hint( Nino.content.getText( K+ 'status/allok' ) ) );
					return;
				}

				const list = Nino.adminUi.el( 'ul', 'crm-missing' );

				missing.forEach( function( item ) { list.appendChild( Nino.adminUi.el( 'li', '', Nino.content.getText( K+ 'status/'+ item.key+ '-'+ item.state ) ) ) } );
				box.appendChild( list );
			} );
		},

		/**
		 *	Open a document, a draft in the editor and a finalised one on its page
		 *
		 *	@param		{number}	id
		 *
		 *	@return		void
		 */
		_open : function( id ) {

			docs.call( 'get', { id : String( id ) }, function( status, response ) {

				if( status !== 200 ) {
					docs.fail( docs.mount(), status, response, K+ 'error/load' );
					return;
				}

				docs._draw( response );
			} );
		},

		/**
		 *	A new draft of a category: the templates of its tag are asked for, the rest the list holds
		 *
		 *	@param		{string}	category
		 *
		 *	@return		void
		 */
		_new : function( category ) {

			docs.call( 'templates', { category : category }, function( status, response ) {

				if( status !== 200 ) {
					docs.fail( docs.mount(), status, response, K+ 'error/load' );
					return;
				}

				docs._draw( { document : null, category : category, templates : response.templates, clients : docs._clients, settings : docs._settings, items : docs._catalogue } );
			} );
		},

		/**
		 *	Draw what the server sent of a document, or of a new one
		 *
		 *	@param		{Object}	view					document (null for a new draft), category, templates, clients, settings, items
		 *	@param		{boolean}	[saved]				Whether the line of the screen says it is saved
		 *
		 *	@return		void
		 */
		_draw : function( view, saved ) {
			// saved is true where a save went through and 'finalised' where the document was finalised just now

			docs._settings = view.settings;
			docs._clients = view.clients;
			docs._catalogue = view.items;

			if( view.document !== null && view.document.state !== 'draft' ) {
				docs._drawFinal( view, saved );
				return;
			}

			docs._drawEditor( view, saved === true );
		},

		/**
		 *	The back link of a screen: to the list, after the question where the editor holds what is not saved
		 *
		 *	@param		{Element}	mount
		 *
		 *	@return		void
		 */
		_back : function( mount ) {

			const back = Nino.adminUi.el( 'a', 'nino-admin-back-link', Nino.content.getText( K+ 'label/back' ) );

			back.href = '#';
			back.addEventListener( 'click', function( event ) {
				event.preventDefault();
				docs._close();
			} );
			mount.appendChild( back );
		},

		/**
		 *	Back to the list - after the question where the editor holds what is not saved
		 *
		 *	@return		void
		 */
		_close : function() {

			const leave = function() { docs.reload() };

			if( docs._dirty() === true && typeof Nino.admin.dirty === 'object' ) {
				Nino.admin.dirty.guard( [ 'crm-documents' ], leave, function() {} );
				return;
			}

			leave();
		},

		/**
		 *	The editor of a draft
		 *
		 *	@param		{Object}		view
		 *	@param		{boolean}		saved					Whether the line says the draft is saved
		 *
		 *	@return		void
		 */
		_drawEditor : function( view, saved ) {

			const mount = docs.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			docs._back( mount );

			const document = view.document;
			const category = document === null ? view.category : document.category;
			const draftMode = document === null ? docs._settings.vat_mode : document.vat_mode;
			const today = docs._today();
			const date = document === null ? today : document.date;

			const model = {
				client_id      : document === null ? '' : String( document.client_id ),
				date           : date,
				due_date       : document === null ? docs._addDays( date, Number( docs._settings.payment_days ) ) : ( document.due_date ?? '' ),
				valid_until    : document === null ? docs._addDays( date, Number( docs._settings.offer_days ) ) : ( document.valid_until ?? '' ),
				period_from    : document === null ? '' : ( document.period_from ?? '' ),
				period_to      : document === null ? '' : ( document.period_to ?? '' ),
				template_key   : document === null ? '' : document.template_key,
				inputs         : document === null ? {} : Object.assign( {}, document.inputs ),
				reverse_charge : document === null ? false : document.reverse_charge,
				notes          : document === null ? '' : document.notes,
				lines          : document === null ? [] : document.lines.map( function( line ) {
					return { title : line.title, description : line.description, unit : line.unit, qty : money.formatQty( line.qty ), price : money.format( line.price_net ), vat_rate : line.vat_rate };
				} ),
			};

			const line = docs._line();
			const form = Nino.adminUi.el( 'form', 'crm-doc' );
			const save = Nino.adminUi.button( Nino.content.getText( K+ 'label/save' ), null, { type : 'submit', className : 'nino-admin-btn-primary' } );

			form.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText( K+ 'category/'+ category )+ ' - '+ Nino.content.getText( K+ 'label/draft' ) ) );

			docs._card = { document : document, category : category, view : view, model : model, vatMode : draftMode, form : form, save : save, line : line, rows : null, totals : null, inputsBox : null, initial : '' };

			form.appendChild( docs._headFields( model, view, category ) );

			const templateBox = Nino.adminUi.el( 'div', 'crm-template' );
			form.appendChild( templateBox );
			docs._drawTemplate( templateBox, model, view );

			if( draftMode === 'regular' )
				form.appendChild( docs._reverseField( model ) );

			form.appendChild( docs._linesBox( model, draftMode ) );

			const notesLabel = Nino.adminUi.el( 'label', 'nino-admin-field' );
			const notes = Nino.adminUi.el( 'textarea', 'nino-admin-input' );

			notes.name = 'notes';
			notes.rows = 3;
			notes.maxLength = 5000;
			notes.value = model.notes;
			notes.addEventListener( 'input', function() { model.notes = notes.value } );
			notesLabel.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText( K+ 'field/notes' ) ) );
			notesLabel.appendChild( notes );
			form.appendChild( notesLabel );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );
			actions.appendChild( save );

			if( document !== null ) {
				actions.appendChild( Nino.adminUi.button( Nino.content.getText( K+ 'label/finalise' ), function() { docs._finalise() }, { className : 'nino-admin-btn-secondary', id : 'crm-finalise' } ) );
				actions.appendChild( Nino.adminUi.button( Nino.content.getText( K+ 'label/delete' ), function() { docs._remove() }, { className : 'nino-admin-btn-danger' } ) );
			}

			actions.appendChild( line.element );
			form.appendChild( actions );

			if( view.templates.length === 0 )
				form.appendChild( Nino.adminUi.hint( Nino.content.getText( K+ 'status/templates-none' ), 'error' ) );

			if( document !== null )
				form.appendChild( docs._relations( document ) );

			docs._card.initial = JSON.stringify( docs._payload() );
			line.bind( form, function() { return docs._dirty() } );

			if( saved === true )
				line.saved( new Date() );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				docs._save( function() {} );
			} );

			mount.appendChild( form );
			docs._totals();
		},

		/**
		 *	The fields above the lines: client, dates and the period
		 *
		 *	@param		{Object}	model
		 *	@param		{Object}	view
		 *	@param		{string}	category
		 *
		 *	@return		{Element}
		 */
		_headFields : function( model, view, category ) {

			const box = Nino.adminUi.el( 'div', 'crm-head' );
			const word = function( key ) { return Nino.content.getText( K+ key ) };
			const clients = view.clients.filter( function( client ) { return client.active === true || String( client.id ) === model.client_id } );
			const client = Nino.adminUi.selectField( {
				key			: 'client_id',
				label		: word( 'field/client' ),
				value		: model.client_id,
				options	: [ { value : '', label : word( 'field/client-choose' ) } ].concat( clients.map( function( entry ) { return { value : String( entry.id ), label : entry.number+ ' '+ entry.title } } ) ),
				onChange	: function( value ) { model.client_id = value },
			} );

			client.querySelector('select').name = 'client_id';
			box.appendChild( client );

			// An offer is valid until a day, an invoice is due on one, a credit note returns money and has neither
			const dates = [ [ 'date', 'field/date' ] ];

			if( category !== 'credit' )
				dates.push( [ category === 'offer' ? 'valid_until' : 'due_date', category === 'offer' ? 'field/valid-until' : 'field/due-date' ] );

			dates.push( [ 'period_from', 'field/period-from' ], [ 'period_to', 'field/period-to' ] );

			dates.forEach( function( [ name, key ] ) {

				const field = Nino.adminUi.textField( { label : word( key ), value : model[name], type : 'date', autocomplete : 'off', onInput : function( value ) { model[name] = value } } );

				field.querySelector('input').name = name;
				box.appendChild( field );
			} );

			return box;
		},

		/**
		 *	The select of the template and, under it, the inputs the template asks for
		 *
		 *	@param		{Element}	box
		 *	@param		{Object}	model
		 *	@param		{Object}	view
		 *
		 *	@return		void
		 */
		_drawTemplate : function( box, model, view ) {

			box.innerHTML = '';

			const select = Nino.adminUi.selectField( {
				key			: 'template_key',
				label		: Nino.content.getText( K+ 'field/template' ),
				value		: model.template_key,
				options	: [ { value : '', label : Nino.content.getText( K+ 'field/template-choose' ) } ].concat( view.templates.map( function( template ) { return { value : template.key, label : template.name } } ) ),
				onChange	: function( value ) {
					model.template_key = value;
					docs._drawTemplate( box, model, view );
				},
			} );

			select.querySelector('select').name = 'template_key';
			box.appendChild( select );

			const template = view.templates.find( function( entry ) { return entry.key === model.template_key } );

			( template === undefined ? [] : template.inputs ).forEach( function( input ) {

				const label = Nino.adminUi.el( 'label', 'nino-admin-field crm-input' );
				const control = Nino.adminUi.el( input.multiline === true ? 'textarea' : 'input', 'nino-admin-input' );
				const name = Nino.adminUi.el( 'span', '', input.label !== '' ? input.label : input.name );

				if( input.multiline === true )
					control.rows = 4;
				else
					control.type = 'text';

				control.name = 'inputs.'+ input.name;
				control.value = model.inputs[input.name] ?? '';
				control.addEventListener( 'input', function() { model.inputs[input.name] = control.value } );

				if( input.required === true ) {
					const star = Nino.adminUi.el( 'span', 'nino-admin-required', '*' );
					star.setAttribute( 'aria-hidden', 'true' );
					name.appendChild( star );
					control.required = true;
				}

				label.appendChild( name );
				label.appendChild( control );
				box.appendChild( label );
			} );
		},

		/**
		 *	@param		{Object}	model
		 *
		 *	@return		{Element}									The checkbox of the reverse charge
		 */
		_reverseField : function( model ) {

			const label = Nino.adminUi.el( 'label', 'nino-admin-field crm-reverse' );
			const box = Nino.adminUi.el( 'input' );

			box.type = 'checkbox';
			box.name = 'reverse_charge';
			box.checked = model.reverse_charge;
			box.addEventListener( 'change', function() {
				model.reverse_charge = box.checked;
				docs._drawLines();
			} );
			label.appendChild( box );
			label.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText( K+ 'field/reverse-charge' ) ) );

			return label;
		},

		/**
		 *	The lines: the table, the button for a free row and the totals
		 *
		 *	@param		{Object}	model
		 *	@param		{string}	vatMode					The VAT mode of the document
		 *
		 *	@return		{Element}
		 */
		_linesBox : function( model, vatMode ) {

			const box = Nino.adminUi.el( 'div', 'crm-lines-box' );
			const table = Nino.adminUi.el( 'table', 'crm-lines' );
			const head = Nino.adminUi.el( 'tr' );
			const columns = [ 'title', 'qty', 'unit', 'price' ].concat( vatMode === 'regular' ? [ 'rate' ] : [] ).concat( [ 'amount', 'actions' ] );

			columns.forEach( function( column ) { head.appendChild( Nino.adminUi.el( 'th', '', Nino.content.getText( K+ 'column/'+ column ) ) ) } );
			table.appendChild( Nino.adminUi.el( 'thead' ) ).appendChild( head );

			const body = Nino.adminUi.el( 'tbody' );

			table.appendChild( body );
			box.appendChild( table );

			const list = Nino.adminUi.el( 'datalist' );

			list.id = 'crm-items-list';
			box.appendChild( list );

			docs._catalogue.forEach( function( item ) {
				const option = Nino.adminUi.el( 'option' );
				option.value = item.title;
				list.appendChild( option );
			} );

			box.appendChild( Nino.adminUi.button( Nino.content.getText( K+ 'label/add-line' ), function() {
				model.lines.push( { title : '', description : '', unit : '', qty : '1', price : '', vat_rate : vatMode === 'regular' ? ( docs._settings.rates[0] ?? 0 ) : 0 } );
				docs._drawLines();
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small', id : 'crm-add-line' } ) );

			const totals = Nino.adminUi.el( 'div', 'crm-totals' );

			box.appendChild( totals );
			docs._card.rows = body;
			docs._card.totals = totals;
			docs._drawLines();

			return box;
		},

		/**
		 *	The rows of the lines, drawn from the model; the totals under them
		 *
		 *	@return		void
		 */
		_drawLines : function() {

			const card = docs._card;
			const model = card.model;
			const regular = card.vatMode === 'regular';
			const reverse = regular === true && model.reverse_charge === true;

			card.rows.innerHTML = '';

			model.lines.forEach( function( line, index ) {

				const row = Nino.adminUi.el( 'tr', 'crm-line' );
				const cell = function( control ) {
					const td = Nino.adminUi.el( 'td' );
					td.appendChild( control );
					row.appendChild( td );
					return td;
				};
				const input = function( name, value, label ) {
					const control = Nino.adminUi.el( 'input', 'nino-admin-input' );
					control.type = 'text';
					control.name = 'lines.'+ index+ '.'+ name;
					control.value = value;
					control.setAttribute( 'aria-label', label );
					control.addEventListener( 'input', function() {
						line[name] = control.value;
						docs._fillFromItem( line, name, index );
						docs._totals();
					} );
					return control;
				};

				row.dataset.index = String( index );

				const title = input( 'title', line.title, Nino.content.getText( K+ 'column/title' ) );
				title.setAttribute( 'list', 'crm-items-list' );
				title.maxLength = 200;

				const first = cell( title );
				const description = Nino.adminUi.el( 'textarea', 'nino-admin-input crm-description' );

				description.rows = 2;
				description.name = 'lines.'+ index+ '.description';
				description.value = line.description;
				description.maxLength = 2000;
				description.setAttribute( 'aria-label', Nino.content.getText( K+ 'column/description' ) );
				description.placeholder = Nino.content.getText( K+ 'column/description' );
				description.addEventListener( 'input', function() { line.description = description.value } );
				first.appendChild( description );

				cell( input( 'qty', line.qty, Nino.content.getText( K+ 'column/qty' ) ) );
				cell( input( 'unit', line.unit, Nino.content.getText( K+ 'column/unit' ) ) );
				cell( input( 'price', line.price, Nino.content.getText( K+ 'column/price' ) ) );

				if( regular === true ) {

					const rates = docs._settings.rates.slice();

					if( rates.indexOf( line.vat_rate ) === -1 )
						rates.push( line.vat_rate );

					const select = Nino.adminUi.el( 'select', 'nino-admin-input' );

					select.name = 'lines.'+ index+ '.vat_rate';
					select.setAttribute( 'aria-label', Nino.content.getText( K+ 'column/rate' ) );
					rates.forEach( function( rate ) {
						const option = Nino.adminUi.el( 'option' );
						option.value = String( rate );
						option.textContent = money.formatRate( rate );
						select.appendChild( option );
					} );
					select.value = String( reverse === true ? 0 : line.vat_rate );
					select.disabled = reverse === true;
					select.addEventListener( 'change', function() {
						line.vat_rate = Number( select.value );
						docs._totals();
					} );
					cell( select );
				}

				const amount = Nino.adminUi.el( 'td', 'crm-amount' );

				amount.dataset.amount = '1';
				row.appendChild( amount );

				const tools = Nino.adminUi.el( 'td', 'crm-tools' );
				const move = function( by ) {
					const [ taken ] = model.lines.splice( index, 1 );
					model.lines.splice( index+ by, 0, taken );
					docs._drawLines();
				};

				tools.appendChild( Nino.adminUi.button( '↑', function() { move( -1 ) }, { name : Nino.content.getText( K+ 'label/up' ), disabled : index === 0, className : 'crm-up' } ) );
				tools.appendChild( Nino.adminUi.button( '↓', function() { move( 1 ) }, { name : Nino.content.getText( K+ 'label/down' ), disabled : index === model.lines.length - 1, className : 'crm-down' } ) );
				tools.appendChild( Nino.adminUi.button( '✕', function() {
					model.lines.splice( index, 1 );
					docs._drawLines();
				}, { name : Nino.content.getText( K+ 'label/remove-line' ), className : 'crm-remove' } ) );
				row.appendChild( tools );

				card.rows.appendChild( row );
			} );

			docs._totals();
		},

		/**
		 *	A row whose title names an item of the catalogue and which has nothing else yet takes the item's unit, price and rate
		 *
		 *	@param		{Object}	line
		 *	@param		{string}	name					The field that was typed in
		 *	@param		{number}	index
		 *
		 *	@return		void
		 */
		_fillFromItem : function( line, name, index ) {

			if( name !== 'title' || line.price !== '' || line.unit !== '' || line.description !== '' )
				return;

			const item = docs._catalogue.find( function( entry ) { return entry.title.toLowerCase() === line.title.trim().toLowerCase() } );

			if( item === undefined )
				return;

			const card = docs._card;

			line.title = item.title;
			line.description = item.description;
			line.unit = item.unit;
			line.price = money.format( item.price_net );
			line.vat_rate = item.vat_rate;
			docs._drawLines();

			const title = card.rows.querySelector( '[name="lines.'+ index+ '.title"]' );

			if( title !== null )
				title.focus();
		},

		/**
		 *	The amounts of the rows and the totals under them, from what is typed: the rules of money.js
		 *
		 *	@return		void
		 */
		_totals : function() {

			const card = docs._card;

			if( card === null || card.totals === null )
				return;

			const model = card.model;
			const reverse = card.vatMode === 'regular' && model.reverse_charge === true;
			const valid = [];
			const rows = card.rows.children;

			model.lines.forEach( function( line, index ) {

				const qty = money.parseQty( line.qty.trim() );
				const price = money.parse( line.price.trim() );
				const ok = qty !== null && qty !== 0 && price !== null;
				const row = rows[index];

				if( row === undefined )
					return;

				row.querySelectorAll('input').forEach( function( control ) {
					if( control.name === 'lines.'+ index+ '.qty' || control.name === 'lines.'+ index+ '.price' ) {
						const bad = control.name.slice( -3 ) === 'qty' ? ( qty === null || qty === 0 ) : price === null;

						if( bad === true )
							control.setAttribute( 'aria-invalid', 'true' );
						else
							control.removeAttribute('aria-invalid');
					}
				} );

				const amount = row.querySelector('[data-amount]');

				amount.textContent = ok === true ? money.format( money.lineAmount( qty, price ) ) : '';

				if( ok === true )
					valid.push( { qty : qty, price_net : price, vat_rate : reverse === true ? 0 : line.vat_rate } );
			} );

			const totals = money.totals( valid, card.vatMode, reverse );
			const currency = docs._settings.currency;
			const box = card.totals;

			box.innerHTML = '';

			const add = function( label, cents, className ) {
				const row = Nino.adminUi.el( 'div', 'crm-total '+ className );
				row.appendChild( Nino.adminUi.el( 'span', 'crm-total-label', label ) );
				row.appendChild( Nino.adminUi.el( 'strong', 'crm-total-amount', money.format( cents, currency ) ) );
				box.appendChild( row );
			};

			if( card.vatMode === 'regular' ) {
				add( Nino.content.getText( K+ 'totals/net' ), totals.net, 'crm-total-net' );
				totals.rates.forEach( function( entry ) { add( docs._text( K+ 'totals/vat', money.formatRate( entry.rate ), money.format( entry.net, currency ) ), entry.vat, 'crm-total-vat' ) } );
			}

			add( Nino.content.getText( K+ 'totals/gross' ), totals.gross, 'crm-total-gross' );
		},

		/**
		 *	What the editor posts: the fields, the inputs and the lines as typed - the server reads them
		 *
		 *	@return		{Object}
		 */
		_payload : function() {

			const card = docs._card;
			const model = card.model;
			const regular = card.vatMode === 'regular';
			const reverse = regular === true && model.reverse_charge === true;
			const payload = {
				client_id      : model.client_id,
				date           : model.date,
				period_from    : model.period_from,
				period_to      : model.period_to,
				template_key   : model.template_key,
				inputs         : docs._usedInputs(),
				reverse_charge : reverse,
				notes          : model.notes,
				lines          : model.lines.map( function( line ) {
					return { title : line.title, description : line.description, unit : line.unit, qty : line.qty, price : line.price, vat_rate : regular === true && reverse === false ? line.vat_rate : 0 };
				} ),
			};

			if( card.category !== 'credit' )
				payload[card.category === 'offer' ? 'valid_until' : 'due_date'] = model[card.category === 'offer' ? 'valid_until' : 'due_date'];

			return payload;
		},

		/**
		 *	@return		{Object}									The inputs of the chosen template that have a value
		 */
		_usedInputs : function() {

			const card = docs._card;
			const template = card.view.templates.find( function( entry ) { return entry.key === card.model.template_key } );
			const used = {};

			( template === undefined ? [] : template.inputs ).forEach( function( input ) {
				if( ( card.model.inputs[input.name] ?? '' ) !== '' )
					used[input.name] = card.model.inputs[input.name];
			} );

			return used;
		},

		_dirty : function() {

			const card = docs._card;

			if( card === null )
				return false;

			if( card.final === true )
				return card.model.notes !== card.initial;

			return JSON.stringify( docs._payload() ) !== card.initial;
		},

		_discard : function() {

			const card = docs._card;

			if( card === null )
				return;

			if( card.final === true )
				card.notes.value = card.initial;
			else
				docs._draw( card.view, false );
		},

		/**
		 *	Post the editor. Ends in done( true ) once it is saved and done( false ) on every other way
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_save : function( done ) {

			const card = docs._card;

			if( card === null || card.save.disabled === true ) {
				done( false );
				return;
			}

			card.save.disabled = true;
			card.line.saving();

			let payload;

			if( card.final === true )
				payload = { id : String( card.document.id ), notes : card.model.notes };
			else {
				payload = docs._payload();

				if( card.document === null )
					payload.category = card.category;
				else
					payload.id = String( card.document.id );
			}

			docs.call( 'save', payload, function( status, response ) {

				card.save.disabled = false;

				if( status !== 200 ) {
					card.line.error( status, response, K+ 'error/save' );
					done( false );
					return;
				}

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				// The editor is drawn again from what is stored, and its line says it is saved
				docs._draw( Object.assign( {}, card.view, { document : response.document, category : response.document.category } ), true );
				done( true );
			} );
		},

		/**
		 *	Finalise the draft: it is saved first where it holds what is not saved, then the question is asked
		 *
		 *	@return		void
		 */
		_finalise : function() {

			const card = docs._card;

			if( card === null || card.document === null )
				return;

			const ask = function() {
				Nino.adminUi.choiceDialog( {
					title		: Nino.content.getText( K+ 'label/finalise' ),
					message	: Nino.content.getText( K+ 'confirm/finalise' ),
					choices	: [
						{ value : 'yes', label : Nino.content.getText( K+ 'label/finalise' ), kind : 'primary' },
						{ value : 'no', label : Nino.content.getText( K+ 'label/cancel' ), kind : 'secondary' },
					],
					onChoose : function( choice ) {

						// The editor may have been drawn again by the save that came first
						const current = docs._card;

						if( choice !== 'yes' || current === null )
							return;

						current.line.saving();

						docs.call( 'finalise', { id : String( current.document.id ) }, function( status, response ) {

							if( status !== 200 ) {
								current.line.error( status, response, K+ 'error/finalise' );
								return;
							}

							if( typeof Nino.admin.dirty === 'object' )
								Nino.admin.dirty.refresh();

							docs._draw( Object.assign( {}, current.view, { document : response.document, category : response.document.category } ), 'finalised' );
						} );
					},
				} );
			};

			if( docs._dirty() === false ) {
				ask();
				return;
			}

			docs._save( function( ok ) {
				if( ok === true )
					ask();
			} );
		},

		/**
		 *	Delete the draft after the question
		 *
		 *	@return		void
		 */
		_remove : function() {

			const card = docs._card;

			Nino.adminUi.choiceDialog( {
				title		: Nino.content.getText( K+ 'label/delete' ),
				message	: Nino.content.getText( K+ 'confirm/delete' ),
				choices	: [
					{ value : 'yes', label : Nino.content.getText( K+ 'label/delete' ), kind : 'danger' },
					{ value : 'no', label : Nino.content.getText( K+ 'label/cancel' ), kind : 'secondary' },
				],
				onChoose : function( choice ) {

					if( choice !== 'yes' )
						return;

					card.line.saving();

					docs.call( 'remove', { id : String( card.document.id ) }, function( status, response ) {

						if( status !== 200 ) {
							card.line.error( status, response, K+ 'error/remove' );
							return;
						}

						if( typeof Nino.admin.dirty === 'object' )
							Nino.admin.dirty.refresh();

						docs.reload();
					} );
				},
			} );
		},

		/**
		 *	The documents a document comes from and the ones that come from it, as buttons that open them
		 *
		 *	@param		{Object}	document
		 *
		 *	@return		{Element}
		 */
		_relations : function( document ) {

			const box = Nino.adminUi.el( 'div', 'crm-relations' );
			const link = function( related, key ) {

				const button = Nino.adminUi.button( docs._text( key, related.number !== '' ? related.number : Nino.content.getText( K+ 'label/draft' ) ), function() { docs._open( related.id ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } );

				button.dataset.id = String( related.id );
				box.appendChild( button );
			};

			if( document.parent !== null )
				link( document.parent, K+ 'label/from-'+ document.parent.category );

			document.children.forEach( function( child ) { link( child, K+ 'label/to-'+ child.category ) } );

			return box;
		},

		/**
		 *	The page of a finalised document: its facts, its lines, its steps and its notes
		 *
		 *	@param		{Object}		view
		 *	@param		{boolean}		saved
		 *
		 *	@return		void
		 */
		_drawFinal : function( view, saved ) {

			const mount = docs.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			docs._back( mount );

			const document = view.document;
			const currency = docs._settings.currency;
			const line = docs._line();
			const head = Nino.adminUi.el( 'h3', '', document.number+ ' - '+ Nino.content.getText( K+ 'category/'+ document.category ) );
			const badge = docs._badge( document );

			// The badge stands beside the title with a space of its own
			badge.classList.add('crm-state');
			head.appendChild( badge );
			mount.appendChild( head );

			const facts = Nino.adminUi.el( 'table', 'crm-facts' );
			const fact = function( label, value ) {

				if( value === '' )
					return;

				const row = Nino.adminUi.el( 'tr' );
				row.appendChild( Nino.adminUi.el( 'th', '', Nino.content.getText( K+ 'field/'+ label ) ) );
				row.appendChild( Nino.adminUi.el( 'td', '', value ) );
				facts.appendChild( row );
			};

			fact( 'client', docs._clientName( document.client ) );
			fact( 'date', docs._day( document.date ) );
			fact( 'due-date', docs._day( document.due_date ?? '' ) );
			fact( 'valid-until', docs._day( document.valid_until ?? '' ) );
			fact( 'period-from', docs._day( document.period_from ?? '' ) );
			fact( 'period-to', docs._day( document.period_to ?? '' ) );
			fact( 'sent-at', docs._day( ( document.sent_at ?? '' ).slice( 0, 10 ) ) );
			fact( 'paid-at', docs._day( ( document.paid_at ?? '' ).slice( 0, 10 ) ) );
			fact( 'reverse-charge', document.reverse_charge === true ? Nino.content.getText( K+ 'label/yes' ) : '' );
			mount.appendChild( facts );

			const table = Nino.adminUi.el( 'table', 'crm-lines crm-lines-final' );
			const regular = document.vat_mode === 'regular';
			const columns = [ 'title', 'qty', 'unit', 'price' ].concat( regular === true ? [ 'rate' ] : [] ).concat( [ 'amount' ] );
			const header = Nino.adminUi.el( 'tr' );

			columns.forEach( function( column ) { header.appendChild( Nino.adminUi.el( 'th', '', Nino.content.getText( K+ 'column/'+ column ) ) ) } );
			table.appendChild( Nino.adminUi.el( 'thead' ) ).appendChild( header );

			const body = Nino.adminUi.el( 'tbody' );

			document.lines.forEach( function( entry ) {

				const row = Nino.adminUi.el( 'tr', 'crm-line' );
				const cells = [ entry.title, money.formatQty( entry.qty ), entry.unit, money.format( entry.price_net, currency ) ].concat( regular === true ? [ money.formatRate( entry.vat_rate ) ] : [] ).concat( [ money.format( entry.amount_net, currency ) ] );

				cells.forEach( function( text ) { row.appendChild( Nino.adminUi.el( 'td', '', text ) ) } );
				body.appendChild( row );
			} );

			table.appendChild( body );
			mount.appendChild( table );

			const totals = Nino.adminUi.el( 'div', 'crm-totals' );
			const sums = money.totals( document.lines, document.vat_mode, document.reverse_charge );
			const add = function( label, cents, className ) {
				const row = Nino.adminUi.el( 'div', 'crm-total '+ className );
				row.appendChild( Nino.adminUi.el( 'span', 'crm-total-label', label ) );
				row.appendChild( Nino.adminUi.el( 'strong', 'crm-total-amount', money.format( cents, currency ) ) );
				totals.appendChild( row );
			};

			if( regular === true ) {
				add( Nino.content.getText( K+ 'totals/net' ), document.net, 'crm-total-net' );
				sums.rates.forEach( function( entry ) { add( docs._text( K+ 'totals/vat', money.formatRate( entry.rate ), money.format( entry.net, currency ) ), entry.vat, 'crm-total-vat' ) } );
			}

			add( Nino.content.getText( K+ 'totals/gross' ), document.gross, 'crm-total-gross' );
			mount.appendChild( totals );

			const notesLabel = Nino.adminUi.el( 'label', 'nino-admin-field' );
			const notes = Nino.adminUi.el( 'textarea', 'nino-admin-input' );
			const form = Nino.adminUi.el( 'form', 'crm-doc crm-final' );
			const save = Nino.adminUi.button( Nino.content.getText( K+ 'label/save-notes' ), null, { type : 'submit', className : 'nino-admin-btn-secondary' } );

			notes.name = 'notes';
			notes.rows = 3;
			notes.maxLength = 5000;
			notes.value = document.notes;
			notesLabel.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText( K+ 'field/notes' ) ) );
			notesLabel.appendChild( notes );
			form.appendChild( notesLabel );

			docs._card = { document : document, category : document.category, view : view, model : { notes : document.notes }, final : true, form : form, save : save, line : line, notes : notes, initial : document.notes };
			notes.addEventListener( 'input', function() { docs._card.model.notes = notes.value } );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			actions.appendChild( save );
			actions.appendChild( line.element );
			form.appendChild( actions );
			line.bind( form, function() { return docs._dirty() } );

			if( saved === true )
				line.saved( new Date() );

			// A document finalised just now says so and when, not that it was saved
			if( saved === 'finalised' )
				line.idle( docs._text( K+ 'label/finalised-on', docs._day( ( document.finalised ?? '' ).slice( 0, 10 ) ) ) );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				docs._save( function() {} );
			} );

			mount.appendChild( docs._steps( document ) );
			mount.appendChild( form );
			mount.appendChild( docs._relations( document ) );
		},

		/**
		 *	The steps of a finalised document: the PDF, the download and the buttons that move it on
		 *
		 *	@param		{Object}	document
		 *
		 *	@return		{Element}
		 */
		_steps : function( document ) {

			const box = Nino.adminUi.el( 'div', 'crm-steps' );
			const word = function( key ) { return Nino.content.getText( K+ key ) };
			const result = docs._line();

			const open = Nino.adminUi.el( 'a', 'nino-admin-btn-secondary crm-pdf', word( 'label/pdf' ) );
			open.href = document.pdf_url;
			open.target = '_blank';
			open.rel = 'noopener';
			box.appendChild( open );

			const download = Nino.adminUi.el( 'a', 'nino-admin-btn-secondary crm-download', word( 'label/download' ) );
			download.href = document.pdf_url+ '?download=1';
			box.appendChild( download );

			const steps = ( STEPS[document.category] ?? {} )[document.state] ?? [];

			if( steps.length > 0 ) {

				const day = Nino.adminUi.textField( { label : word( 'field/step-date' ), value : docs._today(), type : 'date', autocomplete : 'off' } );

				day.querySelector('input').name = 'step_date';
				box.appendChild( day );

				steps.forEach( function( [ state ] ) {
					box.appendChild( Nino.adminUi.button( word( 'label/set-'+ state ), function() {

						result.saving();

						docs.call( 'state', { id : String( document.id ), state : state, date : state === 'sent' || state === 'paid' ? day.querySelector('input').value : '' }, function( status, response ) {

							if( status !== 200 ) {
								result.error( status, response, K+ 'error/state' );
								return;
							}

							docs._draw( Object.assign( {}, docs._card.view, { document : response.document } ), false );
						} );
					}, { className : 'nino-admin-btn-secondary', id : 'crm-set-'+ state } ) );
				} );
			}

			const derive = function( action, label ) {
				box.appendChild( Nino.adminUi.button( word( label ), function() {

					result.saving();

					docs.call( action, { id : String( document.id ) }, function( status, response ) {

						if( status !== 200 ) {
							result.error( status, response, K+ 'error/'+ action );
							return;
						}

						docs._open( response.document.id );
					} );
				}, { className : 'nino-admin-btn-secondary', id : 'crm-'+ action } ) );
			};

			if( document.category === 'offer' && [ 'open', 'sent', 'accepted' ].indexOf( document.state ) !== -1 )
				derive( 'from-offer', 'label/make-invoice' );

			if( document.category === 'invoice' && [ 'open', 'sent', 'paid' ].indexOf( document.state ) !== -1 )
				derive( 'credit', 'label/make-credit' );

			box.appendChild( result.element );

			return box;
		},

		/**
		 *	The settings screen
		 *
		 *	@return		void
		 */
		/**
		 *	The rates as they are shown: percentages, the German comma for a fraction, a semicolon and a blank between them
		 *
		 *	@param		{string}	stored				1900,700,550,0 - hundredths of a percent
		 *
		 *	@return		{string}									19; 7; 5,5; 0
		 */
		_ratesText : function( stored ) {
			return stored.split(',').map( function( rate ) { return money.formatRate( Number( rate ) ).replace( ' %', '' ) } ).join('; ');
		},

		_openSettings : function() {

			docs.call( 'settings-get', {}, function( status, response ) {

				if( status !== 200 ) {
					docs.fail( docs.mount(), status, response, K+ 'error/load' );
					return;
				}

				docs._drawSettings( response.settings );
			} );
		},

		/**
		 *	@param		{Object}	settings			key => value
		 *
		 *	@return		void
		 */
		_drawSettings : function( settings ) {

			const mount = docs.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			docs._card = null;

			const back = Nino.adminUi.el( 'a', 'nino-admin-back-link', Nino.content.getText( K+ 'label/back' ) );

			back.href = '#';
			back.addEventListener( 'click', function( event ) {
				event.preventDefault();
				docs.reload();
			} );
			mount.appendChild( back );

			const form = Nino.adminUi.el( 'form', 'crm-settings' );
			const fields = {};
			const line = docs._line();
			const save = Nino.adminUi.button( Nino.content.getText( K+ 'label/save' ), null, { type : 'submit', className : 'nino-admin-btn-primary' } );

			form.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText( K+ 'label/settings' ) ) );

			SETTINGS.forEach( function( [ key, kind ] ) {

				const dashed = key.replace( /_/g, '-' );
				let field;

				if( kind === 'select' ) {
					field = Nino.adminUi.selectField( {
						key		: key,
						label	: Nino.content.getText( K+ 'setting/'+ dashed ),
						value	: settings[key],
						options	: [ 'small_business', 'regular' ].map( function( mode ) { return { value : mode, label : Nino.content.getText( K+ 'setting/mode-'+ mode.replace( /_/g, '-' ) ) } } ),
					} );
					fields[key] = field.querySelector('select');
				}
				else {
					field = Nino.adminUi.textField( { label : Nino.content.getText( K+ 'setting/'+ dashed ), value : key === 'vat_rates' ? docs._ratesText( settings[key] ) : settings[key], maxLength : 40, autocomplete : 'off', hint : Nino.content.getText( K+ 'setting-hint/'+ dashed ) } );
					fields[key] = field.querySelector('input');
				}

				fields[key].name = key === 'vat_rates' ? 'vat_rates_percent' : key;
				form.appendChild( field );

				// A select has no room for its hint inside: the hint is a paragraph of its own after it
				if( kind === 'select' )
					form.appendChild( Nino.adminUi.hint( Nino.content.getText( K+ 'setting-hint/'+ dashed ) ) );
			} );

			form.appendChild( Nino.adminUi.hint( Nino.content.getText( K+ 'setting/numbers-note' ) ) );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			actions.appendChild( save );
			actions.appendChild( line.element );
			form.appendChild( actions );
			line.bind( form, function() { return true } );

			form.addEventListener( 'submit', function( event ) {

				event.preventDefault();

				if( save.disabled === true )
					return;

				save.disabled = true;
				line.saving();

				const payload = {};

				SETTINGS.forEach( function( [ key ] ) { payload[key === 'vat_rates' ? 'vat_rates_percent' : key] = fields[key].value } );

				docs.call( 'settings-save', payload, function( code, response ) {

					save.disabled = false;

					if( code !== 200 ) {
						line.error( code, response, K+ 'error/settings' );
						return;
					}

					docs._settings = Object.assign( {}, docs._settings, response.settings, { rates : response.settings.vat_rates.split(',').map( Number ) } );
					line.saved( new Date() );
				} );
			} );

			mount.appendChild( form );
		},
	} );

} )(window);

/**
 *	Crm\Clients\Admin		The panel Kunden of the CRM module: the list of the clients in the shell's table (Nino.adminUi.table()), the card of one
 *											(its fields, active or not, the first account a new client is made with and, for a client that
 *											exists, its accounts of Members - each row with its state and the actions: send the link again,
 *											switch off or on, change the name, delete - and the dialog that links an existing account and the
 *											form that invites one) and, over
 *											the list, the status of the module with the button that applies the install unit - the portal
 *											pages. Draws into the one region of templates/panel.tpl with the workbench's own builders;
 *											every word is a fill, every value of the server goes in as text, and every request goes
 *											through the panel's call(), which posts crm-clients/<action>.
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The fields of a client in the order the card draws them: [ name, type, wide ]
	const FIELDS = [
		[ 'company', 'text', true ], [ 'name', 'text', true ], [ 'street', 'text', true ], [ 'zip', 'text', false ], [ 'city', 'text', false ],
		[ 'country', 'text', false ], [ 'email', 'email', true ], [ 'phone', 'text', false ], [ 'vat_id', 'text', false ],
	];

	const crm = Nino.adminUi.panel( {
		uri			: 'crm-clients',
		mount		: 'crm-clients-app',
		dirty		: {
			isDirty : function() { return Nino.admin['crm-clients']._dirty() },
			save		: function( done ) { Nino.admin['crm-clients']._save( done ) },
			discard : function() { Nino.admin['crm-clients']._discard() },
		},
		render	: function( data ) {

			const panel = Nino.admin['crm-clients'];

			panel._clients = Array.isArray( data.clients ) === true ? data.clients : [];
			panel._card = null;
			panel._render();
		},
	} );

	Nino.admin['crm-clients'] = crm;

	Object.assign( crm, {

		_clients	: [],
		_status		: null,
		// The open card: { client (null for a new one), accounts, form, save, line, fields } - null while the list shows
		_card			: null,
		// The id of the client whose card was opened from the list: the row the focus returns to
		_opened		: null,

		_text : function( key, ...params ) {
			return Nino.adminUi.format( Nino.content.getText( key ), ...params );
		},

		/**
		 *	The name a client goes by: its company, its name where it has none
		 *
		 *	@param		{Object}	client
		 *
		 *	@return		{string}
		 */
		_title : function( client ) {
			return client.company !== '' ? client.company : client.name;
		},

		/**
		 *	The name of an account of Members: its name, its address where it has no name
		 *
		 *	@param		{Object}	account
		 *
		 *	@return		{string}
		 */
		_who : function( account ) {

			if( account.gone === true )
				return Nino.content.getText('/_admin/crm-clients/state/gone');

			return account.name !== '' ? account.name : account.email;
		},

		/**
		 *	The day of a time of the server - 'Y-m-d\TH:i:s\Z', UTC
		 *
		 *	@param		{*}				time
		 *
		 *	@return		{string}									'' for none
		 */
		_when : function( time ) {
			return typeof time === 'string' && /^\d{4}-\d{2}-\d{2}T/.test( time ) === true ? time.slice( 0, 10 ) : '';
		},

		_line : function() {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ) );
		},

		/**
		 *	The list screen: the status of the module, the clients in the shell's table and the button for a new one
		 *
		 *	@return		void
		 */
		_render : function() {

			const mount = crm.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			crm._card = null;

			const status = Nino.adminUi.el( 'div', 'crm-status' );
			status.id = 'crm-status';
			mount.appendChild( status );
			crm._drawStatus( status );

			// The shell's data table: search, sort and pages over the whole set, here in the browser
			const table = Nino.adminUi.el( 'div', 'crm-table' );
			table.id = 'crm-clients-table';
			mount.appendChild( table );

			Nino.adminUi.table( {
				mount			: table,
				rowKey		: 'id',
				columns		: [
					{ key : 'number', label : Nino.content.getText('/_admin/crm-clients/column/number'), type : 'string' },
					{ key : 'company', label : Nino.content.getText('/_admin/crm-clients/field/company'), type : 'string' },
					{ key : 'name', label : Nino.content.getText('/_admin/crm-clients/field/name'), type : 'string' },
					{ key : 'email', label : Nino.content.getText('/_admin/crm-clients/field/email'), type : 'string' },
					{ key : 'city', label : Nino.content.getText('/_admin/crm-clients/field/city'), type : 'string' },
					{ key : 'accounts', label : Nino.content.getText('/_admin/crm-clients/column/accounts'), type : 'integer' },
					{ key : 'active_accounts', label : Nino.content.getText('/_admin/crm-clients/column/active-accounts'), type : 'integer' },
					{ key : 'active', label : Nino.content.getText('/_admin/crm-clients/column/active'), type : 'boolean' },
				],
				rows			: crm._clients,
				labels		: {
					search	: Nino.content.getText('/_admin/crm-clients/label/search'),
					empty		: Nino.content.getText('/_admin/crm-clients/empty/clients'),
					noMatch	: Nino.content.getText('/_admin/crm-clients/empty/found'),
				},
				onRowClick : function( client ) { crm._open( client.id ) },
			} );

			const add = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/new'), function() { crm._open( null ) }, { className : 'nino-admin-btn-primary' } );
			mount.appendChild( Nino.adminUi.listActions( [ add ] ) );

			// Back from a card the focus is on the row that was opened, as in the Elements panel
			const opened = crm._opened === null ? null : table.querySelector( '[data-row="'+ crm._opened+ '"]' );

			if( opened !== null )
				opened.focus();

			crm._opened = null;
		},

		/**
		 *	The status of the module: one line each for what it needs, and the button that applies the install unit
		 *
		 *	@param		{Element}	box
		 *
		 *	@return		void
		 */
		_drawStatus : function( box, said ) {

			crm.call( 'status', {}, function( status, response ) {

				box.innerHTML = '';

				if( status !== 200 ) {
					Nino.adminUi.showError( box, status, response, '/_admin/crm-clients/error/status' );
					return;
				}

				crm._status = Array.isArray( response.items ) === true ? response.items : [];

				const missing = crm._status.filter( function( item ) { return item.state !== 'ok' } );

				if( missing.length === 0 )
					box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/crm-clients/status/allok') ) );
				else {
					const list = Nino.adminUi.el( 'ul', 'crm-missing' );

					missing.forEach( function( item ) {
						const key = '/_admin/crm-clients/status/'+ item.key+ '-'+ item.state;
						list.appendChild( Nino.adminUi.el( 'li', '', Nino.content.getText( key ) ) );
					} );

					box.appendChild( list );
				}

				const portal = crm._status.find( function( item ) { return item.key === 'portal' } );
				const line = crm._line();

				// What the setup said stays: the box was drawn again after it, so the sentence is drawn again with it
				if( typeof said === 'string' )
					line.idle( said );

				const setup = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/setup'), function() {

					setup.disabled = true;
					line.saving();

					crm.call( 'setup', {}, function( code, answer ) {

						setup.disabled = false;

						if( code !== 200 ) {
							line.error( code, answer, '/_admin/crm-clients/error/setup' );
							return;
						}

						crm._drawStatus( box, crm._setupSentence( answer ) );
					} );
				}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } );

				const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

				setup.hidden = portal !== undefined && portal.state === 'ok';
				actions.appendChild( setup );
				actions.appendChild( line.element );
				box.appendChild( actions );
			} );
		},

		/**
		 *	What the setup says it did, in one sentence
		 *
		 *	@param		{Object}	answer
		 *
		 *	@return		{string}
		 */
		_setupSentence : function( answer ) {

			const added = answer.added ?? { routes : [], templates : [], texts : 0, paths : false };
			const existed = answer.existed ?? { routes : [], templates : [] };

			return crm._text( '/_admin/crm-clients/setup/done', added.routes.length, added.templates.length, added.texts, existed.routes.length+ existed.templates.length );
		},

		/**
		 *	Open the card of a client, or an empty one
		 *
		 *	@param		{number|null}	id
		 *
		 *	@return		void
		 */
		_open : function( id ) {

			if( id === null ) {
				crm._drawCard( null, [] );
				return;
			}

			crm._opened = String( id );

			crm.call( 'get', { id : String( id ) }, function( status, response ) {

				if( status !== 200 ) {
					crm.fail( crm.mount(), status, response, '/_admin/crm-clients/error/load' );
					return;
				}

				crm._drawCard( response.client, response.accounts );
			} );
		},

		/**
		 *	The card: the back link, the form of the client and - for a client that exists - its accounts
		 *
		 *	@param		{Object|null}	client
		 *	@param		{Array}				accounts
		 *	@param		{boolean}			[saved]			Whether the line says the card is saved
		 *	@param		{Object}			[typed]			name => value of what the person typed and nobody saved, put back into the fields
		 *	@param		{string}			[notice]		A sentence the box of the accounts says over the list
		 *
		 *	@return		void
		 */
		_drawCard : function( client, accounts, saved, typed, notice ) {

			const mount = crm.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';

			const back = Nino.adminUi.el( 'a', 'nino-admin-back-link', Nino.content.getText('/_admin/crm-clients/label/back') );
			back.href = '#';
			back.addEventListener( 'click', function( event ) {
				event.preventDefault();
				crm._close();
			} );
			mount.appendChild( back );

			// What became of the first account, of an invitation or of a switch-off: at the top, where it is seen
			if( typeof notice === 'string' && notice !== '' )
				mount.appendChild( Nino.adminUi.el( 'p', 'crm-notice', notice ) );

			const form = Nino.adminUi.el( 'form', 'crm-card' );
			const fields = {};
			const line = crm._line();
			const save = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/save'), null, { type : 'submit', className : 'nino-admin-btn-primary' } );

			form.appendChild( Nino.adminUi.el( 'h3', '', client === null ? Nino.content.getText('/_admin/crm-clients/label/new') : client.number+ ' '+ crm._title( client ) ) );

			FIELDS.forEach( function( [ name, type ] ) {

				const field = Nino.adminUi.textField( {
					label			: Nino.content.getText('/_admin/crm-clients/field/'+ name.replace( '_', '-' ) ),
					value			: client === null ? ( name === 'country' ? 'DE' : '' ) : client[name],
					type			: type,
					maxLength	: name === 'country' ? 2 : 160,
					autocomplete	: 'off',
				} );

				fields[name] = field.querySelector('input');
				fields[name].name = name;

				if( name === 'phone' )
					fields[name].inputMode = 'tel';
				form.appendChild( field );
			} );

			const notesLabel = Nino.adminUi.el( 'label', 'nino-admin-field' );
			const notes = Nino.adminUi.el( 'textarea', 'nino-admin-input' );

			notes.name = 'notes';
			notes.rows = 4;
			notes.maxLength = 2000;
			notes.value = client === null ? '' : client.notes;
			notesLabel.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText('/_admin/crm-clients/field/notes') ) );
			notesLabel.appendChild( notes );
			fields.notes = notes;
			form.appendChild( notesLabel );

			// A new client is made with its first account where an address is given
			if( client === null )
				[ [ 'account_email', 'email', 'first-email', 160 ], [ 'account_name', 'text', 'first-name', 120 ] ].forEach( function( [ name, type, key, max ] ) {

					const field = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-clients/field/'+ key), type : type, maxLength : max, autocomplete : 'off' } );

					fields[name] = field.querySelector('input');
					fields[name].name = name;
					form.appendChild( field );
				} );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );
			actions.appendChild( save );

			if( client !== null )
				actions.appendChild( Nino.adminUi.button( Nino.content.getText( client.active === true ? '/_admin/crm-clients/label/deactivate' : '/_admin/crm-clients/label/activate' ), function() {

					line.saving();

					const unsaved = crm._values( fields );

					crm.call( 'active', { id : String( client.id ), active : client.active === false }, function( status, response ) {

						if( status !== 200 ) {
							line.error( status, response, '/_admin/crm-clients/error/active' );
							return;
						}

						// What was typed and not saved is typed again into the card that is drawn, which still counts it as unsaved; the
						// accounts are the ones the server has now - a client that is off has them off
						crm._drawCard( response.client, response.accounts, false, unsaved, response.switched > 0 ? crm._text( '/_admin/crm-clients/active/switched', response.switched ) : '' );
					} );
				}, { className : 'nino-admin-btn-secondary' } ) );

			actions.appendChild( line.element );
			form.appendChild( actions );

			crm._card = { client : client, accounts : accounts, form : form, save : save, line : line, fields : fields, values : crm._values( fields ) };
			line.bind( form, function() { return crm._dirty() } );

			if( saved === true )
				line.saved( new Date() );

			if( typed !== undefined )
				Object.keys( typed ).forEach( function( name ) { fields[name].value = typed[name] } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				crm._save( function() {} );
			} );

			mount.appendChild( form );

			if( client !== null ) {
				mount.appendChild( crm._accountsBox( client, accounts ) );
				mount.appendChild( crm._documentsBox( client ) );
			}

			if( client === null )
				fields.company.focus();
		},

		/**
		 *	@param		{Object}	fields
		 *
		 *	@return		{Object}									name => value
		 */
		_values : function( fields ) {

			const values = {};

			Object.keys( fields ).forEach( function( name ) { values[name] = fields[name].value } );

			return values;
		},

		_dirty : function() {

			const card = crm._card;

			if( card === null )
				return false;

			const now = crm._values( card.fields );

			return Object.keys( now ).some( function( name ) { return now[name] !== card.values[name] } );
		},

		_discard : function() {

			const card = crm._card;

			if( card === null )
				return;

			Object.keys( card.fields ).forEach( function( name ) { card.fields[name].value = card.values[name] } );
		},

		/**
		 *	Back to the list - after the question where the card holds what is not saved
		 *
		 *	@return		void
		 */
		_close : function() {

			const leave = function() { crm.reload() };

			if( crm._dirty() === true && typeof Nino.admin.dirty === 'object' ) {
				Nino.admin.dirty.guard( [ 'crm-clients' ], leave, function() {} );
				return;
			}

			leave();
		},

		/**
		 *	Post the card. Ends in done( true ) once it is saved and done( false ) on every other way
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_save : function( done ) {

			const card = crm._card;

			if( card === null || card.save.disabled === true ) {
				done( false );
				return;
			}

			card.save.disabled = true;
			card.line.saving();

			const payload = crm._values( card.fields );

			if( card.client !== null )
				payload.id = String( card.client.id );

			crm.call( 'save', payload, function( status, response ) {

				card.save.disabled = false;

				if( status !== 200 ) {
					card.line.error( status, response, '/_admin/crm-clients/error/save' );
					done( false );
					return;
				}

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				// The card is drawn again from what is stored, and its line says it is saved. A client made with a first account has it
				// in the list, and the box says what became of the account - also when it was refused: the client is made all the same
				const first = response.first;
				const accounts = first !== undefined && first.account !== null ? [ first.account ] : card.accounts;

				crm._drawCard( response.client, accounts, true, undefined, first === undefined ? '' : crm._firstSentence( first ) );
				done( true );
			} );
		},

		/**
		 *	What became of the first account of a client that was made with one
		 *
		 *	@param		{Object}	first				{ account, mailed, refused: { code, params } or null }
		 *
		 *	@return		{string}
		 */
		_firstSentence : function( first ) {

			if( first.refused !== null )
				return crm._text( '/_admin/crm-clients/first/refused', Nino.adminUi.format( Nino.content.getText( '/_admin/error/'+ first.refused.code ), ...first.refused.params ) );

			return Nino.content.getText( first.mailed === true ? '/_admin/crm-clients/invite/sent' : '/_admin/crm-clients/invite/not-mailed' );
		},

		/**
		 *	Draw the box of the accounts again from a change of the list of the card - when the card is still the one of the client
		 *
		 *	@param		{Object}		client
		 *	@param		{Function}	change			Gets the accounts of the card, answers the new list
		 *	@param		{string}		[notice]		A sentence for the top of the card
		 *
		 *	@return		void
		 */
		_update : function( client, change, notice ) {

			const card = crm._card;
			const old = crm.mount() === null ? null : crm.mount().querySelector('.crm-accounts');

			if( card === null || card.client === null || card.client.id !== client.id || old === null )
				return;

			card.accounts = change( card.accounts );
			old.replaceWith( crm._accountsBox( card.client, card.accounts ) );

			if( typeof notice === 'string' && notice !== '' )
				crm._say( notice );
		},

		/**
		 *	The sentence at the top of the card, under the back link: one at a time
		 *
		 *	@param		{string}	text
		 *
		 *	@return		void
		 */
		_say : function( text ) {

			const mount = crm.mount();
			const old = mount.querySelector('.crm-notice');
			const note = Nino.adminUi.el( 'p', 'crm-notice', text );

			if( old !== null )
				old.replaceWith( note );
			else
				mount.querySelector('.nino-admin-back-link').after( note );
		},

		/**
		 *	Post an action of an account, with the button off and the line busy while it is out
		 *
		 *	@param		{Element}		button
		 *	@param		{Object}		line
		 *	@param		{string}		action
		 *	@param		{Object}		payload
		 *	@param		{string}		failure			The key of the sentence of the action
		 *	@param		{Function}	done				Gets the answer of a 200
		 *
		 *	@return		void
		 */
		_act : function( button, line, action, payload, failure, done ) {

			button.disabled = true;
			line.saving();

			crm.call( action, payload, function( status, response ) {

				button.disabled = false;

				if( status !== 200 ) {
					line.error( status, response, failure );
					return;
				}

				done( response );
			} );
		},

		/**
		 *	The accounts of the client with their state and, for each, its actions; a link to the panel of Members, the dialog that
		 *	links an account that exists and the form that invites a new one
		 *
		 *	@param		{Object}	client
		 *	@param		{Array}		accounts
		 *
		 *	@return		{Element}
		 */
		_accountsBox : function( client, accounts ) {

			const box = Nino.adminUi.el( 'div', 'crm-accounts' );

			box.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText('/_admin/crm-clients/label/accounts-title') ) );

			// Without the feature Members nothing is known of the accounts: one sentence instead of rows that would all read "deleted"
			if( accounts.some( function( account ) { return account.state === 'members-off' } ) === true ) {
				box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/crm-clients/accounts/members-off') ) );
				return box;
			}

			if( accounts.length === 0 )
				box.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/crm-clients/empty/accounts') ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

				accounts.forEach( function( account ) { list.appendChild( crm._accountRow( client, account ) ) } );
				box.appendChild( list );
			}

			const link = Nino.adminUi.el( 'a', 'crm-members-link', Nino.content.getText('/_admin/crm-clients/label/members') );
			link.href = Nino.admin.router.href( 'members', [] );

			// A client that is off takes no account, no link and no switch-on: the card says so once and does not offer them
			if( client.active === false ) {
				box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/crm-clients/accounts/inactive') ) );
				box.appendChild( link );
				return box;
			}

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions crm-link' );
			const line = crm._line();

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/link'), function() { crm._askLink( client, line ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			actions.appendChild( line.element );
			box.appendChild( actions );
			box.appendChild( link );
			box.appendChild( crm._inviteForm( client ) );

			return box;
		},

		/**
		 *	One account: who it is, when it signed in last, whether it is off - and what can be done with it
		 *
		 *	@param		{Object}	client
		 *	@param		{Object}	account
		 *
		 *	@return		{Element}
		 */
		_accountRow : function( client, account ) {

			const li = Nino.adminUi.el( 'li', 'crm-account' );
			const head = Nino.adminUi.el( 'div', 'crm-account-head' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const login = crm._when( account.login );
			const line = crm._line();
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions crm-account-actions' );
			const small = 'nino-admin-btn-secondary nino-admin-btn--small';
			const post = function() { return { id : String( client.id ), account : account.id } };

			li.dataset.id = account.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', crm._who( account ) ) );

			if( account.gone === false ) {

				// An account without a name is named by its address already
				if( account.name !== '' )
					copy.appendChild( Nino.adminUi.el( 'small', '', account.email ) );

				copy.appendChild( Nino.adminUi.el( 'small', '', login === '' ? Nino.content.getText('/_admin/crm-clients/state/never') : crm._text( '/_admin/crm-clients/state/last', login ) ) );
			}

			head.appendChild( copy );

			if( account.gone === false && account.active === false ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/crm-clients/state/off') );
				badge.dataset.state = 'muted';
				head.appendChild( badge );
			}

			li.appendChild( head );

			if( account.gone === false ) {

				// The invitation again for an account that never signed in, a link to sign in for one that did
				const resend = Nino.adminUi.button( Nino.content.getText( account.login === null ? '/_admin/crm-clients/label/resend-invite' : '/_admin/crm-clients/label/resend-login' ), function() {
					crm._act( resend, line, 'account-resend', post(), '/_admin/crm-clients/error/resend', function( response ) {
						line.idle( Nino.content.getText( response.kind === 'invite' ? '/_admin/crm-clients/resend/invite' : '/_admin/crm-clients/resend/login' ) );
					} );
				}, { className : small, disabled : account.active === false || client.active === false } );

				const toggle = Nino.adminUi.button( Nino.content.getText( account.active === true ? '/_admin/crm-clients/label/switch-off' : '/_admin/crm-clients/label/switch-on' ), function() {
					crm._act( toggle, line, 'account-active', Object.assign( post(), { active : account.active === false } ), '/_admin/crm-clients/error/account-active', function( response ) {
						crm._update( client, function( list ) { return list.map( function( each ) { return each.id === account.id ? response.account : each } ) } );
					} );
				}, { className : small, disabled : account.active === false && client.active === false } );

				actions.appendChild( resend );
				actions.appendChild( toggle );
				actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/rename'), function() { crm._renameForm( client, account, li ) }, { className : small } ) );
			}

			// A row whose account is gone from Members is only a row: it says so, and only the row goes
			const gone = account.gone === true;
			const removeLabel = Nino.content.getText( gone === true ? '/_admin/crm-clients/label/remove-row' : '/_admin/crm-clients/label/delete-account' );
			const remove = Nino.adminUi.button( removeLabel, function() {

				Nino.adminUi.choiceDialog( {
					title		: removeLabel,
					message	: gone === true ? Nino.content.getText('/_admin/crm-clients/confirm/remove-row') : crm._text( '/_admin/crm-clients/confirm/delete-account', crm._who( account ) ),
					choices	: [
						{ value : 'yes', label : removeLabel, kind : 'danger' },
						{ value : 'no', label : Nino.content.getText('/_admin/crm-clients/label/cancel'), kind : 'secondary' },
					],
					onChoose : function( choice ) {

						if( choice !== 'yes' )
							return;

						crm._act( remove, line, 'account-remove', post(), '/_admin/crm-clients/error/account-remove', function() {
							crm._update( client, function( list ) { return list.filter( function( each ) { return each.id !== account.id } ) } );
						} );
					},
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } );

			actions.appendChild( remove );
			actions.appendChild( line.element );
			li.appendChild( actions );

			return li;
		},

		/**
		 *	The form that changes the name of an account, in its row
		 *
		 *	@param		{Object}		client
		 *	@param		{Object}		account
		 *	@param		{Element}		li
		 *
		 *	@return		void
		 */
		_renameForm : function( client, account, li ) {

			if( li.querySelector('.crm-account-name') !== null )
				return;

			const form = Nino.adminUi.el( 'form', 'crm-account-name' );
			const name = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-clients/field/account-name'), value : account.name, maxLength : 120, autocomplete : 'off' } );
			const save = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/save'), null, { type : 'submit', className : 'nino-admin-btn-primary nino-admin-btn--small' } );
			const cancel = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/cancel'), function() { form.remove() }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } );
			const line = crm._line();
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			name.querySelector('input').name = 'name';
			form.appendChild( name );
			actions.appendChild( save );
			actions.appendChild( cancel );
			actions.appendChild( line.element );
			form.appendChild( actions );

			form.addEventListener( 'submit', function( event ) {

				event.preventDefault();

				crm._act( save, line, 'account-name', { id : String( client.id ), account : account.id, name : name.querySelector('input').value }, '/_admin/crm-clients/error/account-name', function( response ) {
					crm._update( client, function( list ) { return list.map( function( each ) { return each.id === account.id ? response.account : each } ) } );
				} );
			} );

			li.appendChild( form );
			name.querySelector('input').focus();
		},

		/**
		 *	The dialog that links an account of Members that no client holds: it asks which accounts there are, and a select offers them
		 *	by name and address
		 *
		 *	@param		{Object}	client
		 *	@param		{Object}	line				The line beside the button, which says a refusal
		 *
		 *	@return		void
		 */
		_askLink : function( client, line ) {

			line.saving();

			crm.call( 'unlinked', {}, function( status, response ) {

				if( status !== 200 ) {
					line.error( status, response, '/_admin/crm-clients/error/unlinked' );
					return;
				}

				if( response.accounts.length === 0 ) {
					line.idle( Nino.content.getText('/_admin/crm-clients/link/none') );
					return;
				}

				line.idle( '' );

				const select = Nino.adminUi.selectField( {
					key			: 'link_account',
					label		: Nino.content.getText('/_admin/crm-clients/field/link-account'),
					value		: response.accounts[0].id,
					options	: response.accounts.map( function( account ) { return { value : account.id, label : account.name !== '' ? account.name+ ' · '+ account.email : account.email } } ),
				} );
				const content = Nino.adminUi.el( 'div', 'crm-link-form' );
				const first = response.accounts[0];

				select.querySelector('select').name = 'link_account';

				// The dialog draws its content in place of the message: the sentence is part of the content
				content.appendChild( Nino.adminUi.el( 'p', '', Nino.content.getText('/_admin/crm-clients/link/message') ) );
				content.appendChild( select );

				Nino.adminUi.choiceDialog( {
					title		: Nino.content.getText('/_admin/crm-clients/link/title'),
					// What a browser without <dialog> is asked: it can only agree to the first account, so it names it
					message	: crm._text( '/_admin/crm-clients/link/confirm', first.name !== '' ? first.name+ ' · '+ first.email : first.email ),
					content	: content,
					choices	: [
						{ value : 'yes', label : Nino.content.getText('/_admin/crm-clients/label/link-do'), kind : 'primary' },
						{ value : 'no', label : Nino.content.getText('/_admin/crm-clients/label/cancel'), kind : 'secondary' },
					],
					onChoose : function( choice ) {

						if( choice !== 'yes' )
							return;

						line.saving();

						crm.call( 'account-link', { id : String( client.id ), account : select.querySelector('select').value }, function( code, answer ) {

							if( code !== 200 ) {
								line.error( code, answer, '/_admin/crm-clients/error/account-link' );
								return;
							}

							crm._update( client, function( list ) { return list.concat( [ answer.account ] ) }, Nino.content.getText('/_admin/crm-clients/link/done') );
						} );
					},
				} );
			} );
		},

		/**
		 *	How many documents the client has and how many of them are released to the portal, and the way into the panel Belege, where the
		 *	documents of a client are found: the address carries the client (#crm-documents/client/<id>), which Belege reads as its filter Kunde
		 *
		 *	@param		{Object}	client				With documents: { total, released }
		 *
		 *	@return		{Element}
		 */
		_documentsBox : function( client ) {

			const box = Nino.adminUi.el( 'div', 'crm-documents-box' );
			const link = Nino.adminUi.el( 'a', 'crm-documents-link', Nino.content.getText('/_admin/crm-clients/label/documents') );

			box.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText('/_admin/crm-clients/label/documents-title') ) );
			box.appendChild( Nino.adminUi.el( 'p', 'crm-documents-count', crm._text( '/_admin/crm-clients/documents/count', String( client.documents.total ), String( client.documents.released ) ) ) );
			link.href = Nino.admin.router.href( 'crm-documents', [ 'client', String( client.id ) ] );
			box.appendChild( link );

			return box;
		},

		/**
		 *	The form that invites an account for the client: an address and a name
		 *
		 *	@param		{Object}	client
		 *
		 *	@return		{Element}
		 */
		_inviteForm : function( client ) {

			const form = Nino.adminUi.el( 'form', 'crm-invite' );
			const email = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-clients/field/invite-email'), type : 'email', required : true, maxLength : 160, autocomplete : 'off' } );
			const name = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-clients/field/invite-name'), maxLength : 120, autocomplete : 'off' } );
			const send = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/invite'), null, { type : 'submit', className : 'nino-admin-btn-secondary' } );
			const line = crm._line();
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			email.querySelector('input').name = 'email';
			name.querySelector('input').name = 'name';
			form.appendChild( email );
			form.appendChild( name );
			actions.appendChild( send );
			actions.appendChild( line.element );
			form.appendChild( actions );

			form.addEventListener( 'submit', function( event ) {

				event.preventDefault();

				if( send.disabled === true )
					return;

				send.disabled = true;
				line.saving();

				crm.call( 'invite', { id : String( client.id ), email : email.querySelector('input').value, name : name.querySelector('input').value }, function( status, response ) {

					send.disabled = false;

					if( status !== 200 ) {
						line.error( status, response, '/_admin/crm-clients/error/invite' );
						return;
					}

					// The new box says whether the mail went out
					crm._update( client, function( list ) { return list.concat( [ response.account ] ) }, Nino.content.getText( response.mailed === true ? '/_admin/crm-clients/invite/sent' : '/_admin/crm-clients/invite/not-mailed' ) );
				} );
			} );

			return form;
		},
	} );

} )(window);

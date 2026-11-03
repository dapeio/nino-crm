/**
 *	Crm\Clients\Admin		The panel Kunden of the CRM module: the list of the clients with a search, the card of one
 *											(its fields, active or not, its accounts of Members and the form that invites one) and, over
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
		payload	: function() { return { search : Nino.admin['crm-clients']._search } },
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
		_search		: '',
		_status		: null,
		// The open card: { client (null for a new one), accounts, form, save, line, fields } - null while the list shows
		_card			: null,

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
		 *	The list screen: the status of the module, the search, the clients and the button for a new one
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

			const form = Nino.adminUi.el( 'form', 'crm-search' );
			const field = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-clients/label/search'), value : crm._search, maxLength : 100, type : 'search', autocomplete : 'off' } );
			field.querySelector('input').name = 'search';
			form.appendChild( field );
			form.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/find'), null, { type : 'submit', className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				crm._search = field.querySelector('input').value;
				crm.reload();
			} );
			mount.appendChild( form );

			if( crm._clients.length === 0 )
				mount.appendChild( Nino.adminUi.emptyState( Nino.content.getText( crm._search === '' ? '/_admin/crm-clients/empty/clients' : '/_admin/crm-clients/empty/found' ) ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list' );

				crm._clients.forEach( function( client ) { list.appendChild( crm._row( client ) ) } );
				mount.appendChild( list );
			}

			const add = Nino.adminUi.button( Nino.content.getText('/_admin/crm-clients/label/new'), function() { crm._open( null ) }, { className : 'nino-admin-btn-primary' } );
			mount.appendChild( Nino.adminUi.listActions( [ add ] ) );

		},

		/**
		 *	@param		{Object}	client
		 *
		 *	@return		{Element}
		 */
		_row : function( client ) {

			const li = Nino.adminUi.el( 'li' );
			const row = Nino.adminUi.el( 'button', 'nino-admin-list-link' );
			const copy = Nino.adminUi.el( 'span', 'nino-admin-list-copy' );

			row.type = 'button';
			row.dataset.id = String( client.id );
			copy.appendChild( Nino.adminUi.el( 'strong', '', client.number+ ' '+ crm._title( client ) ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', crm._text( client.accounts === 1 ? '/_admin/crm-clients/label/account' : '/_admin/crm-clients/label/accounts', client.accounts ) ) );
			row.appendChild( copy );

			if( client.active === false ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/crm-clients/state/inactive') );
				badge.dataset.state = 'muted';
				row.appendChild( badge );
			}

			row.addEventListener( 'click', function() { crm._open( client.id ) } );
			li.appendChild( row );

			return li;
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
		 *
		 *	@return		void
		 */
		_drawCard : function( client, accounts, saved, typed ) {

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

						// What was typed and not saved is typed again into the card that is drawn, which still counts it as unsaved
						crm._drawCard( response.client, accounts, false, unsaved );
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

			if( client !== null )
				mount.appendChild( crm._accountsBox( client, accounts ) );

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

				// The card is drawn again from what is stored, and its line says it is saved
				crm._drawCard( response.client, card.accounts, true );
				done( true );
			} );
		},

		/**
		 *	The accounts of the client with their state, a link to the panel of Members and the form that invites one
		 *
		 *	@param		{Object}	client
		 *	@param		{Array}		accounts
		 *
		 *	@return		{Element}
		 */
		_accountsBox : function( client, accounts ) {

			const box = Nino.adminUi.el( 'div', 'crm-accounts' );

			box.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText('/_admin/crm-clients/label/accounts-title') ) );

			if( accounts.length === 0 )
				box.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/crm-clients/empty/accounts') ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

				accounts.forEach( function( account ) {

					const li = Nino.adminUi.el( 'li' );
					const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
					const login = crm._when( account.login );

					copy.appendChild( Nino.adminUi.el( 'strong', '', crm._who( account ) ) );

					if( account.gone === false ) {

						// An account without a name is named by its address already
						if( account.name !== '' )
							copy.appendChild( Nino.adminUi.el( 'small', '', account.email ) );

						copy.appendChild( Nino.adminUi.el( 'small', '', login === '' ? Nino.content.getText('/_admin/crm-clients/state/never') : crm._text( '/_admin/crm-clients/state/last', login ) ) );
					}

					li.appendChild( copy );

					if( account.gone === false && account.active === false ) {
						const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/crm-clients/state/off') );
						badge.dataset.state = 'muted';
						li.appendChild( badge );
					}

					list.appendChild( li );
				} );

				box.appendChild( list );
			}

			const link = Nino.adminUi.el( 'a', 'crm-members-link', Nino.content.getText('/_admin/crm-clients/label/members') );
			link.href = Nino.admin.router.href( 'members', [] );
			box.appendChild( link );

			box.appendChild( crm._inviteForm( client, accounts ) );

			return box;
		},

		/**
		 *	The form that invites an account for the client: an address and a name
		 *
		 *	@param		{Object}	client
		 *	@param		{Array}		accounts
		 *
		 *	@return		{Element}
		 */
		_inviteForm : function( client, accounts ) {

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

					crm._drawCard( client, accounts.concat( [ response.account ] ) );

					// The new card says whether the mail went out
					const message = Nino.adminUi.el( 'p', 'crm-invited', Nino.content.getText( response.mailed === true ? '/_admin/crm-clients/invite/sent' : '/_admin/crm-clients/invite/not-mailed' ) );
					const target = crm.mount().querySelector('.crm-invite');

					if( target !== null )
						target.parentNode.insertBefore( message, target );
				} );
			} );

			return form;
		},
	} );

} )(window);

/**
 *	Nino										A compact filesystembased php framework
 *	Modules\Members\Admin		The feature's /_admin panel, "Members": the accounts of
 *													the members' area on one screen - the button that signs
 *													every member out, the form for a new member and the list,
 *													sorted by name and filtered by group, each row with the
 *													three things done to an account: change it, send it a link
 *													to sign in, delete it. See Modules\Members\Admin beside
 *													this file. Draws into the regions of templates/panel.tpl
 *													with the workbench's own builders; every word is a fill,
 *													every server-sent value goes in as text, and every request
 *													goes through the panel's call().
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The list is asked for when the panel is first shown; a change of the
	// accounts reloads it, and the form for a new member is not drawn again with
	// it - what is typed into it stays
	Nino.admin.members = Nino.adminUi.panel( {
		uri		: 'members',
		mount	: 'members-app',
		dirty	: {
			isDirty : function() { return Nino.admin.members._dirty() },
			save		: function( done ) { Nino.admin.members._saveOpen( done ) },
			discard : function() { Nino.admin.members._discard() },
		},
		render	: function( data ) {

			const members = Nino.admin.members;

			members._take( data );
			members._render();

			// A change that was saved while that answer was out is not in it: ask again
			if( members._again === true ) {
				members._again = false;
				members.reload();
			}
		},
		onError	: function() { Nino.admin.members._again = false },
	} );

	Object.assign( Nino.admin.members, {

		// What members/list answered: every account, sorted by name, and the groups in use
		_accounts	: [],
		_groups		: [],
		// The group the list is on: '' for all of them, NONE for the accounts without one, else a group
		_filter		: '',
		// The id of the account that is open for change, '' for none
		_editing	: '',
		// Whether the list is to be asked for again once the answer that is out has come back
		_again		: false,
		// The form for a new member - its fields and its live line - and the open
		// form of a change, drawn with the screen and thrown away with it
		_add			: null,
		_edit			: null,
		_signout	: null,

		// A slug is never a lone hyphen, so this cannot be a group
		NONE			: '-',

		/**
		 *	Adopt the answer of the server
		 *
		 *	@param		{Object}	data						{ accounts, groups }
		 *
		 *	@return		void
		 */
		_take : function( data ) {

			Nino.admin.members._accounts = Array.isArray( data.accounts ) === true ? data.accounts : [];
			Nino.admin.members._groups = Array.isArray( data.groups ) === true ? data.groups : [];

			// A group that is gone is no filter any more, and an account that is
			// gone is no form to keep open
			const members = Nino.admin.members;

			if( members._filter !== '' && members._filter !== members.NONE && members._groups.indexOf( members._filter ) === -1 )
				members._filter = '';

			if( members._accounts.some( function( account ) { return account.id === members._editing } ) === false )
				members._editing = '';
		},

		/**
		 *	Ask for the list again after a change. A panel declines to load while a load is
		 *	out, and the answer to that one was asked for before the change: it is asked
		 *	for again when it has come back (see render)
		 *
		 *	@return		void
		 */
		_refresh : function() {

			const members = Nino.admin.members;

			if( members.loading === true )
				members._again = true;
			else
				members.reload();
		},

		/**
		 *	The accounts the filter lets through
		 *
		 *	@param		{Array}		accounts
		 *	@param		{string}	filter					'' for all, NONE for those without a group, else a group
		 *
		 *	@return		{Array}
		 */
		_visible : function( accounts, filter ) {

			if( filter === '' )
				return accounts;

			return accounts.filter( function( account ) {
				return filter === Nino.admin.members.NONE ? account.group === '' : account.group === filter;
			} );
		},

		/**
		 *	What names an account in a row and in a question: the name, the email
		 *	address where it has none
		 *
		 *	@param		{Object}	account
		 *
		 *	@return		{string}
		 */
		_label : function( account ) {
			return account.name !== '' ? account.name : account.email;
		},

		/**
		 *	A time of the server - 'Y-m-d\TH:i:s\Z', UTC - as the minute it is, in
		 *	words that say which clock it is: 2026-11-02 10:15 UTC
		 *
		 *	@param		{string|null}	time
		 *
		 *	@return		{string}									'' for none
		 */
		_when : function( time ) {
			return typeof time === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test( time ) === true ? time.slice( 0, 10 )+ ' '+ time.slice( 11, 16 )+ ' UTC' : '';
		},

		/**
		 *	The live line of a part of the screen: where it says what happened, in
		 *	its own words for "done"
		 *
		 *	@param		{string}	saved						The fill key of its words for a success, if it has some of its own
		 *
		 *	@return		{Object}									See Nino.adminUi.status()
		 */
		_line : function( saved ) {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ), saved === undefined ? undefined : { saved : Nino.content.getText( saved ) } );
		},

		/**
		 *	The screen: the sign-out, the form for a new member - both only the
		 *	first time, so that nothing typed is lost to a list that is drawn again -
		 *	and the list
		 *
		 *	@return		void
		 */
		_render : function() {

			const members = Nino.admin.members;
			const mount = members.mount();

			if( mount === null )
				return;

			// The regions of the template are gone when a load that failed wrote its
			// error into the mount: they are made again, and so are the forms in them
			if( dc.getElementById('members-people') === null ) {

				mount.innerHTML = '';

				[ 'members-tools', 'members-new', 'members-people' ].forEach( function( id ) {
					const region = Nino.adminUi.el( 'div' );
					region.id = id;
					mount.appendChild( region );
				} );

				members._signout = null;
				members._add = null;
			}

			const tools = dc.getElementById('members-tools');
			const form = dc.getElementById('members-new');
			const people = dc.getElementById('members-people');

			if( members._signout === null ) {
				tools.appendChild( members._renderSignout() );
				form.appendChild( members._renderAdd() );
			}

			people.innerHTML = '';
			people.appendChild( members._renderFilter() );

			const rows = Nino.adminUi.el( 'div' );
			rows.id = 'members-rows';
			people.appendChild( rows );

			members._renderRows();
		},

		/**
		 *	Sign every member out: one button, asked about first
		 *
		 *	@return		{Element}
		 */
		_renderSignout : function() {

			const members = Nino.admin.members;
			const box = Nino.adminUi.el( 'div' );
			const line = members._line( '/_admin/members/msg/signedout' );

			members._signout = line;

			box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/members/hint/intro') ) );

			const row = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			row.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/members/label/signout'), function() {

				if( wn.confirm( Nino.content.getText('/_admin/members/confirm/signout') ) === false )
					return;

				line.saving();

				members.call( 'signout', {}, function( status, response ) {

					if( status !== 200 )
						return line.error( status, response, '/_admin/members/error/signout' );

					line.saved();
				} );
			}, { id : 'members-signout', className : 'nino-admin-btn-danger nino-admin-btn--small' } ) );

			row.appendChild( line.element );
			box.appendChild( row );

			return box;
		},

		/**
		 *	The form for a new member: the address, the name, the group, and whether
		 *	an invitation goes with it - ticked, since a member nobody tells of the
		 *	account has nothing to sign in with
		 *
		 *	@return		{Element}
		 */
		_renderAdd : function() {

			const members = Nino.admin.members;
			const form = Nino.adminUi.el( 'form' );
			const box = Nino.adminUi.el( 'fieldset' );

			form.id = 'members-add';
			box.appendChild( Nino.adminUi.el( 'legend', '', Nino.content.getText('/_admin/members/label/new') ) );

			const fields = {
				email		: Nino.adminUi.textField( { label : Nino.content.getText('/_admin/members/label/email'), type : 'email', required : true, maxLength : 254, autocomplete : 'off', id : 'members-add-email' } ),
				name		: Nino.adminUi.textField( { label : Nino.content.getText('/_admin/members/label/name'), maxLength : 120, autocomplete : 'off', id : 'members-add-name' } ),
				group		: Nino.adminUi.textField( { label : Nino.content.getText('/_admin/members/label/group'), hint : Nino.content.getText('/_admin/members/hint/group'), maxLength : 60, autocomplete : 'off', id : 'members-add-group' } ),
			};
			const invite = Nino.adminUi.switchField( { key : 'invite', checked : true, label : Nino.content.getText('/_admin/members/label/invite'), hint : Nino.content.getText('/_admin/members/hint/invite') } );
			const inputs = {};
			const line = members._line();

			Object.keys( fields ).forEach( function( name ) {
				inputs[name] = fields[name].querySelector('input');
				inputs[name].name = name;
				box.appendChild( fields[name] );
			} );

			box.appendChild( invite );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );
			const submit = Nino.adminUi.button( Nino.content.getText('/_admin/members/label/add'), null, { type : 'submit', id : 'members-add-submit', className : 'nino-admin-btn-primary' } );

			actions.appendChild( submit );
			actions.appendChild( line.element );
			box.appendChild( actions );
			form.appendChild( box );

			members._add = { form : form, inputs : inputs, invite : invite.querySelector('input'), submit : submit, line : line };

			line.bind( form, function() { return members._addDirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				members._submitAdd( function() {} );
			} );

			return form;
		},

		/**
		 *	Whether anything is typed into the form for a new member
		 *
		 *	@return		{boolean}
		 */
		_addDirty : function() {

			const add = Nino.admin.members._add;

			return add !== null && Object.keys( add.inputs ).some( function( name ) { return add.inputs[name].value !== '' } );
		},

		/**
		 *	Post the new member. Ends in done( true ) once the account is there and
		 *	done( false ) on every other way, a refusal before anything is sent
		 *	among them. The account is there as well when the invitation could not
		 *	be sent - the server says so with the code members_mail - and the list
		 *	is asked for again then, too
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_submitAdd : function( done ) {

			const members = Nino.admin.members;
			const add = members._add;

			if( add === null || add.submit.disabled === true ) {
				done( false );
				return;
			}

			if( add.inputs.email.value.trim() === '' ) {
				add.line.fail( Nino.content.getText('/_admin/members/error/email') );
				add.inputs.email.focus();
				done( false );
				return;
			}

			add.submit.disabled = true;
			add.line.saving();

			members.call( 'add', { email : add.inputs.email.value, name : add.inputs.name.value, group : add.inputs.group.value, invite : add.invite.checked }, function( status, response ) {

				add.submit.disabled = false;

				if( status !== 200 ) {
					add.line.error( status, response, '/_admin/members/error/add' );

					// The account is there when only the invitation could not be sent: the mail
					// failed, or the kernel's cap of mails refused it
					if( response !== null && typeof response === 'object' && ( response.code === 'members_mail' || response.code === 'members_ratelimit' ) )
						members._refresh();

					done( false );
					return;
				}

				Object.keys( add.inputs ).forEach( function( name ) { add.inputs[name].value = '' } );
				add.line.idle( Nino.content.getText( response.invited === true ? '/_admin/members/msg/invited' : '/_admin/members/msg/added' ) );

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				members._refresh();
				done( true );
			} );
		},

		/**
		 *	The group the list is on, as a select over the groups in use - a lone
		 *	option for a project without a group is no choice, so there is none
		 *
		 *	@return		{Element}
		 */
		_renderFilter : function() {

			const members = Nino.admin.members;
			const wrap = Nino.adminUi.el( 'div' );
			const options = [ { value : '', label : Nino.content.getText('/_admin/members/filter/all') } ];

			members._groups.forEach( function( group ) { options.push( { value : group, label : group } ) } );

			if( members._accounts.some( function( account ) { return account.group === '' } ) === true && members._groups.length > 0 )
				options.push( { value : members.NONE, label : Nino.content.getText('/_admin/members/filter/none') } );

			wrap.id = 'members-filter';

			if( options.length > 1 )
				wrap.appendChild( Nino.adminUi.selectField( {
					key			: 'members-filter',
					label		: Nino.content.getText('/_admin/members/label/filter'),
					options	: options,
					value		: members._filter,
					onChange	: function( value ) {
						members._filter = value;
						members._renderRows();
					},
				} ) );

			return wrap;
		},

		/**
		 *	The rows: one for each account the filter lets through, an open form in
		 *	the one that is being changed
		 *
		 *	@return		void
		 */
		_renderRows : function() {

			const members = Nino.admin.members;
			const wrap = dc.getElementById('members-rows');

			if( wrap === null )
				return;

			wrap.innerHTML = '';
			members._edit = null;

			if( members._accounts.length === 0 ) {
				wrap.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/members/empty') ) );
				return;
			}

			const shown = members._visible( members._accounts, members._filter );

			if( shown.length === 0 ) {
				wrap.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/members/empty/filter') ) );
				return;
			}

			const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

			shown.forEach( function( account ) { list.appendChild( members._renderRow( account ) ) } );
			wrap.appendChild( list );
		},

		/**
		 *	One row: who the member is, whether the account is active, when it was
		 *	last used, and the buttons
		 *
		 *	@param		{Object}	account
		 *
		 *	@return		{Element}
		 */
		_renderRow : function( account ) {

			const members = Nino.admin.members;
			const row = Nino.adminUi.el( 'li', 'members-row' );
			const head = Nino.adminUi.el( 'div', 'members-row-head' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const line = members._line();
			const when = members._when( account.login );

			row.dataset.id = account.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', members._label( account ) ) );

			if( account.name !== '' )
				copy.appendChild( Nino.adminUi.el( 'small', '', account.email ) );

			copy.appendChild( Nino.adminUi.el( 'small', '', ( account.group !== '' ? account.group+ ' · ' : '' )+ ( when !== '' ? Nino.content.getText('/_admin/members/label/login')+ ' '+ when : Nino.content.getText('/_admin/members/label/never') ) ) );
			head.appendChild( copy );

			const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText( account.active === true ? '/_admin/members/state/active' : '/_admin/members/state/inactive' ) );

			badge.dataset.state = account.active === true ? 'ok' : 'muted';
			head.appendChild( badge );
			row.appendChild( head );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions members-row-actions' );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/members/label/edit'), function() { members._open( account.id ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/members/label/link'), function() {

				line.saving();

				members.call( 'link', { id : account.id }, function( status, response ) {

					if( status !== 200 )
						return line.error( status, response, '/_admin/members/error/link' );

					line.idle( Nino.content.getText('/_admin/members/msg/sent') );
				} );
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small', disabled : account.active !== true } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/members/label/delete'), function() {

				if( wn.confirm( Nino.adminUi.format( Nino.content.getText('/_admin/members/confirm/delete'), members._label( account ) ) ) === false )
					return;

				line.saving();

				members.call( 'delete', { id : account.id }, function( status, response ) {

					if( status !== 200 )
						return line.error( status, response, '/_admin/members/error/delete' );

					members._refresh();
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } ) );

			actions.appendChild( line.element );
			row.appendChild( actions );

			if( members._editing === account.id )
				row.appendChild( members._renderEdit( account ) );

			return row;
		},

		/**
		 *	Open an account for change - the one that was open is closed, after a
		 *	question where it holds what is not saved
		 *
		 *	@param		{string}	id
		 *
		 *	@return		void
		 */
		_open : function( id ) {

			const members = Nino.admin.members;

			if( members._editDirty() === true && wn.confirm( Nino.content.getText('/_admin/members/confirm/discard') ) === false )
				return;

			members._editing = id;
			members._renderRows();
		},

		/**
		 *	The form of a change: the name, the group and whether the account is
		 *	active - the address and the times stay what they are
		 *
		 *	@param		{Object}	account
		 *
		 *	@return		{Element}
		 */
		_renderEdit : function( account ) {

			const members = Nino.admin.members;
			const form = Nino.adminUi.el( 'form', 'members-edit' );
			const name = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/members/label/name'), value : account.name, maxLength : 120, autocomplete : 'off' } );
			const group = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/members/label/group'), value : account.group, hint : Nino.content.getText('/_admin/members/hint/group'), maxLength : 60, autocomplete : 'off' } );
			const active = Nino.adminUi.switchField( { key : 'active', checked : account.active === true, label : Nino.content.getText('/_admin/members/label/active'), hint : Nino.content.getText('/_admin/members/hint/active') } );
			const line = members._line();
			const save = Nino.adminUi.button( Nino.content.getText('/_admin/members/label/save'), null, { type : 'submit', className : 'nino-admin-btn-primary nino-admin-btn--small' } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			name.querySelector('input').name = 'name';
			group.querySelector('input').name = 'group';

			form.appendChild( name );
			form.appendChild( group );
			form.appendChild( active );

			actions.appendChild( save );
			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/members/label/cancel'), function() {
				members._editing = '';
				members._renderRows();
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			actions.appendChild( line.element );
			form.appendChild( actions );

			members._edit = { id : account.id, account : account, name : name.querySelector('input'), group : group.querySelector('input'), active : active.querySelector('input'), save : save, line : line };

			line.bind( form, function() { return members._editDirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				members._submitEdit( function() {} );
			} );

			return form;
		},

		/**
		 *	Whether the open form of a change holds what the account does not
		 *
		 *	@return		{boolean}
		 */
		_editDirty : function() {

			const edit = Nino.admin.members._edit;

			return edit !== null && ( edit.name.value !== edit.account.name || edit.group.value !== edit.account.group || edit.active.checked !== ( edit.account.active === true ) );
		},

		/**
		 *	Post the change of the open form. Ends in done( true ) once it is saved
		 *	and done( false ) on every other way
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_submitEdit : function( done ) {

			const members = Nino.admin.members;
			const edit = members._edit;

			if( edit === null || edit.save.disabled === true ) {
				done( false );
				return;
			}

			edit.save.disabled = true;
			edit.line.saving();

			members.call( 'save', { id : edit.id, name : edit.name.value, group : edit.group.value, active : edit.active.checked }, function( status, response ) {

				edit.save.disabled = false;

				if( status !== 200 ) {
					edit.line.error( status, response, '/_admin/members/error/save' );
					done( false );
					return;
				}

				members._editing = '';

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				members._refresh();
				done( true );
			} );
		},

		/**
		 *	What the shell asks about unsaved input: whether the screen holds some
		 *
		 *	@return		{boolean}
		 */
		_dirty : function() {
			return Nino.admin.members._addDirty() === true || Nino.admin.members._editDirty() === true;
		},

		/**
		 *	The shell's Save: the open change first, then the new member - each when it
		 *	holds something unsaved, one after the other, since both can be open at once.
		 *	Ends in done( true ) once all that was unsaved is saved and in done( false )
		 *	when any of it is not; the second is tried when the first was refused, so
		 *	that what can be saved is
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_saveOpen : function( done ) {

			const members = Nino.admin.members;
			const steps = [];

			if( members._editDirty() === true )
				steps.push( members._submitEdit );

			if( members._addDirty() === true )
				steps.push( members._submitAdd );

			if( steps.length === 0 ) {
				done( true );
				return;
			}

			let saved = true;

			( function next( at ) {

				if( at === steps.length ) {
					done( saved );
					return;
				}

				steps[at]( function( ok ) {
					saved = saved === true && ok === true;
					next( at + 1 );
				} );
			} )( 0 );
		},

		/**
		 *	The shell's Discard: what is typed is forgotten, the change is closed
		 *
		 *	@return		void
		 */
		_discard : function() {

			const members = Nino.admin.members;

			if( members._add !== null )
				Object.keys( members._add.inputs ).forEach( function( name ) { members._add.inputs[name].value = '' } );

			members._edit = null;
			members._editing = '';
		},
	} );

} )(window,document);

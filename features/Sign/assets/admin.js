/**
 *	Nino										A compact filesystembased php framework
 *	Modules\Sign\Admin			The feature's /_admin panel, "Signatures": the requests for a
 *													signature on one screen - a form that asks a member to sign a
 *													document of the PDF feature, and the list of the requests, the
 *													newest first, each with how it stands, the two PDFs, and the
 *													record of a signing on a row that is opened; a request that waits
 *													is reminded or cancelled, any request is deleted. See
 *													Modules\Sign\Admin beside this file. Draws into the region of
 *													templates/panel.tpl with the workbench's own builders; every word
 *													is a fill, every server-sent value goes in as text, and every
 *													request goes through the panel's call().
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The list is asked for when the panel is first shown; a change of the requests reloads it, and the form for a new
	// request is not drawn again with it - what is typed into it stays
	Nino.admin.sign = Nino.adminUi.panel( {
		uri		: 'sign',
		mount	: 'sign-app',
		dirty	: {
			isDirty : function() { return Nino.admin.sign._dirty() },
			save		: function( done ) { Nino.admin.sign._submit( done ) },
			discard : function() { Nino.admin.sign._discard() },
		},
		render	: function( data ) {

			const sign = Nino.admin.sign;

			sign._take( data );
			sign._render();

			// A change that was saved while that answer was out is not in it: ask again
			if( sign._again === true ) {
				sign._again = false;
				sign.reload();
			}
		},
		onError	: function() { Nino.admin.sign._again = false },
	} );

	Object.assign( Nino.admin.sign, {

		// What sign/list answered: every request, the newest first, and the days a link is valid
		_requests	: [],
		_days			: 14,
		// What sign/options answered: the accounts and the templates the form offers, and its limits; null until it came
		_options	: null,
		// The id of the request whose record is open, '' for none
		_open			: '',
		// Whether the list is to be asked for again once the answer that is out has come back
		_again		: false,
		// The form for a new request - its controls and its live line - drawn once and kept
		_form			: null,

		// The states in which a request can be reminded and cancelled: it waits, or its time is up
		WAITING		: [ 'requested', 'viewed', 'expired' ],

		/**
		 *	Adopt the answer of the server
		 *
		 *	@param		{Object}	data						{ requests, days }
		 *
		 *	@return		void
		 */
		_take : function( data ) {

			const sign = Nino.admin.sign;

			sign._requests = Array.isArray( data.requests ) === true ? data.requests : [];
			sign._days = Number.isInteger( data.days ) === true ? data.days : 14;

			// A request that is gone is no record to keep open
			if( sign._requests.some( function( request ) { return request.id === sign._open } ) === false )
				sign._open = '';
		},

		/**
		 *	Ask for the list again after a change. A panel declines to load while a load is out, and the answer to
		 *	that one was asked for before the change: it is asked for again when it has come back (see render)
		 *
		 *	@return		void
		 */
		_refresh : function() {

			const sign = Nino.admin.sign;

			if( sign.loading === true )
				sign._again = true;
			else
				sign.reload();
		},

		/**
		 *	What names the member of a request in a row and in a question: the name, the address where there is no
		 *	name, a word for a member who is gone
		 *
		 *	@param		{Object}	request
		 *
		 *	@return		{string}
		 */
		_who : function( request ) {

			const member = request.member !== null && typeof request.member === 'object' ? request.member : {};

			return typeof member.label === 'string' && member.label !== '' ? member.label : Nino.content.getText('/_admin/sign/label/member-gone');
		},

		/**
		 *	A time of the server - 'Y-m-d\TH:i:s\Z', UTC - as the minute it is, in words that say which clock it is:
		 *	2026-11-02 10:15 UTC
		 *
		 *	@param		{string|null}	time
		 *
		 *	@return		{string}									'' for none
		 */
		_when : function( time ) {
			return typeof time === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test( time ) === true ? time.slice( 0, 10 )+ ' '+ time.slice( 11, 16 )+ ' UTC' : '';
		},

		/**
		 *	The time a link is good until, which the server gives as seconds since 1970, as _when() writes a time
		 *
		 *	@param		{number}	seconds
		 *
		 *	@return		{string}									'' for none
		 */
		_until : function( seconds ) {
			return Number.isInteger( seconds ) === true && seconds > 0 ? Nino.admin.sign._when( new Date( seconds * 1000 ).toISOString() ) : '';
		},

		/**
		 *	An address of the server that is a path of this site and nothing else, to be the href of a link
		 *
		 *	@param		{*}				url
		 *
		 *	@return		{string}									'' where it is none
		 */
		_safe : function( url ) {
			return typeof url === 'string' && /^\/(?!\/)[^\s\\]*$/.test( url ) === true ? url : '';
		},

		/**
		 *	The live line of a part of the screen: where it says what happened, in its own words for "done"
		 *
		 *	@param		{string}	saved						The fill key of its words for a success, if it has some of its own
		 *
		 *	@return		{Object}									See Nino.adminUi.status()
		 */
		_line : function( saved ) {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ), saved === undefined ? undefined : { saved : Nino.content.getText( saved ) } );
		},

		/**
		 *	The screen: the form for a new request - the first time only, so that nothing typed is lost to a list that
		 *	is drawn again - and the list
		 *
		 *	@return		void
		 */
		_render : function() {

			const sign = Nino.admin.sign;
			const mount = sign.mount();

			if( mount === null )
				return;

			// The regions are gone when a load that failed wrote its error into the mount: they are made again, and so
			// is the form in them
			if( dc.getElementById('sign-rows') === null ) {

				mount.innerHTML = '';

				[ 'sign-new', 'sign-rows' ].forEach( function( id ) {
					const region = Nino.adminUi.el( 'div', id === 'sign-new' ? 'sign-new' : 'sign-rows' );
					region.id = id;
					mount.appendChild( region );
				} );

				sign._form = null;
				sign._options = null;
			}

			if( sign._form === null )
				sign._loadOptions();

			sign._renderRows();
		},

		/**
		 *	Ask for what the form offers, and draw the form with it
		 *
		 *	@return		void
		 */
		_loadOptions : function() {

			const sign = Nino.admin.sign;

			sign.call( 'options', {}, function( status, response ) {

				const region = dc.getElementById('sign-new');

				if( region === null )
					return;

				if( status !== 200 ) {
					Nino.adminUi.showError( region, status, response, '/_admin/sign/error/load' );
					return;
				}

				sign._options = response;
				region.innerHTML = '';
				region.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/sign/hint/intro') ) );

				if( Array.isArray( response.accounts ) === false || response.accounts.length === 0 )
					region.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/sign/hint/members') ) );
				else if( Array.isArray( response.templates ) === false || response.templates.length === 0 )
					region.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/sign/hint/none') ) );
				else
					region.appendChild( sign._renderForm( response ) );
			} );
		},

		/**
		 *	The form for a new request: the member, the template, the free texts the template asks for, a title
		 *
		 *	@param		{Object}	options					What sign/options answered
		 *
		 *	@return		{Element}
		 */
		_renderForm : function( options ) {

			const sign = Nino.admin.sign;
			const form = Nino.adminUi.el( 'form' );
			const box = Nino.adminUi.el( 'fieldset' );
			const line = sign._line();

			form.id = 'sign-ask';
			box.appendChild( Nino.adminUi.el( 'legend', '', Nino.content.getText('/_admin/sign/label/new') ) );

			const choose = { value : '', label : Nino.content.getText('/_admin/sign/label/choose') };
			const account = Nino.adminUi.selectField( {
				key		: 'account',
				label	: Nino.content.getText('/_admin/sign/label/account'),
				options	: [ choose ].concat( options.accounts.map( function( each ) {
					return { value : each.id, label : each.name !== '' ? each.name+ ' ('+ each.email+ ')' : each.email };
				} ) ),
				value	: '',
			} );
			const template = Nino.adminUi.selectField( {
				key		: 'template',
				label	: Nino.content.getText('/_admin/sign/label/template'),
				options	: [ choose ].concat( options.templates.map( function( each ) { return { value : each.key, label : each.name } } ) ),
				value	: '',
				onChange	: function( value ) { sign._drawInputs( value ) },
			} );
			const title = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/sign/label/title'), hint : Nino.content.getText('/_admin/sign/hint/title'), maxLength : options.limits.title, autocomplete : 'off', id : 'sign-ask-title' } );
			const area = Nino.adminUi.el( 'div', 'sign-input-area' );
			const submit = Nino.adminUi.button( Nino.content.getText('/_admin/sign/label/ask'), null, { type : 'submit', id : 'sign-ask-submit', className : 'nino-admin-btn-primary' } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			account.querySelector('select').name = 'account';
			template.querySelector('select').name = 'template';
			title.querySelector('input').name = 'title';
			area.id = 'sign-inputs';

			box.appendChild( account );
			box.appendChild( template );
			box.appendChild( area );
			box.appendChild( title );
			box.appendChild( Nino.adminUi.hint( Nino.adminUi.format( Nino.content.getText('/_admin/sign/hint/days'), sign._days ) ) );

			actions.appendChild( submit );
			actions.appendChild( line.element );
			box.appendChild( actions );
			form.appendChild( box );

			sign._form = { form : form, account : account.querySelector('select'), template : template.querySelector('select'), title : title.querySelector('input'), area : area, inputs : [], submit : submit, line : line };

			line.bind( form, function() { return sign._formDirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				sign._submit( function() {} );
			} );

			return form;
		},

		/**
		 *	The free texts a template asks for, drawn in place of those of the template before: a line or several, the star
		 *	of a required one, the limit of the server
		 *
		 *	@param		{string}	key							The key of the template, '' for none
		 *
		 *	@return		void
		 */
		_drawInputs : function( key ) {

			const sign = Nino.admin.sign;
			const form = sign._form;

			if( form === null || sign._options === null )
				return;

			form.area.innerHTML = '';
			form.inputs = [];

			const template = sign._options.templates.find( function( each ) { return each.key === key } );

			if( template === undefined )
				return;

			template.inputs.forEach( function( input ) {

				const field = Nino.adminUi.el( 'label', 'nino-admin-field' );
				const name = Nino.adminUi.el( 'span', '', input.label !== '' ? input.label : input.name );
				const control = Nino.adminUi.el( input.multiline === true ? 'textarea' : 'input', 'nino-admin-input' );

				if( input.required === true ) {
					const star = Nino.adminUi.el( 'span', 'nino-admin-required', '*' );
					star.setAttribute( 'aria-hidden', 'true' );
					name.appendChild( star );
					control.required = true;
					control.setAttribute( 'aria-required', 'true' );
				}

				control.name = 'input-'+ input.name;
				control.dataset.field = 'input-'+ input.name;
				control.maxLength = sign._options.limits.input;

				field.appendChild( name );
				field.appendChild( control );
				form.area.appendChild( field );
				form.inputs.push( { input : input, control : control } );
			} );
		},

		/**
		 *	Whether anything is typed into the form for a new request
		 *
		 *	@return		{boolean}
		 */
		_formDirty : function() {

			const form = Nino.admin.sign._form;

			return form !== null && ( form.title.value !== '' || form.inputs.some( function( each ) { return each.control.value !== '' } ) );
		},

		/**
		 *	Post the new request. Ends in done( true ) once it is made and done( false ) on every other way, a refusal
		 *	before anything is sent among them. The request is there as well when only its mail could not be sent - the
		 *	server says so with the code sign_mail or sign_ratelimit - and the list is asked for again then, too
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_submit : function( done ) {

			const sign = Nino.admin.sign;
			const form = sign._form;

			if( form === null || form.submit.disabled === true ) {
				done( false );
				return;
			}

			if( form.account.value === '' ) {
				form.line.fail( Nino.content.getText('/_admin/sign/error/account') );
				form.account.focus();
				done( false );
				return;
			}

			if( form.template.value === '' ) {
				form.line.fail( Nino.content.getText('/_admin/sign/error/template') );
				form.template.focus();
				done( false );
				return;
			}

			const empty = form.inputs.find( function( each ) { return each.input.required === true && each.control.value.trim() === '' } );

			if( empty !== undefined ) {
				form.line.fail( Nino.adminUi.format( Nino.content.getText('/_admin/sign/error/input'), empty.input.label !== '' ? empty.input.label : empty.input.name ) );
				empty.control.focus();
				done( false );
				return;
			}

			const inputs = {};

			form.inputs.forEach( function( each ) { inputs[each.input.name] = each.control.value } );

			form.submit.disabled = true;
			form.line.saving();

			sign.call( 'request', { account : form.account.value, template : form.template.value, inputs : inputs, title : form.title.value }, function( status, response ) {

				form.submit.disabled = false;

				if( status !== 200 ) {
					form.line.error( status, response, '/_admin/sign/error/ask' );

					// The request is there when only the mail could not be sent: the mail failed, or the kernel's cap of mails refused it
					if( response !== null && typeof response === 'object' && ( response.code === 'sign_mail' || response.code === 'sign_ratelimit' ) )
						sign._refresh();

					done( false );
					return;
				}

				form.title.value = '';
				form.inputs.forEach( function( each ) { each.control.value = '' } );
				form.line.idle( Nino.content.getText('/_admin/sign/msg/asked') );

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				sign._refresh();
				done( true );
			} );
		},

		/**
		 *	The rows: one for each request, the record open in the one that was opened
		 *
		 *	@return		void
		 */
		_renderRows : function() {

			const sign = Nino.admin.sign;
			const wrap = dc.getElementById('sign-rows');

			if( wrap === null )
				return;

			wrap.innerHTML = '';

			if( sign._requests.length === 0 ) {
				wrap.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/sign/empty') ) );
				return;
			}

			const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

			sign._requests.forEach( function( request ) { list.appendChild( sign._renderRow( request ) ) } );
			wrap.appendChild( list );
		},

		/**
		 *	One row: what the request is, who it is of, how it stands and when, and the buttons
		 *
		 *	@param		{Object}	request
		 *
		 *	@return		{Element}
		 */
		_renderRow : function( request ) {

			const sign = Nino.admin.sign;
			const row = Nino.adminUi.el( 'li', 'sign-row' );
			const head = Nino.adminUi.el( 'div', 'sign-row-head' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const line = sign._line();
			const waiting = sign.WAITING.indexOf( request.status ) !== -1;
			const dates = [ [ 'created', request.created ], [ 'viewed', request.viewed ], [ 'signed', request.signed ], [ 'declined', request.declined ], [ 'changed', request.changed ] ]
				.filter( function( each ) { return sign._when( each[1] ) !== '' } )
				.map( function( each ) { return Nino.content.getText('/_admin/sign/label/'+ each[0])+ ' '+ sign._when( each[1] ) } );

			row.dataset.id = request.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', request.title ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', sign._who( request )+ ( request.templateName !== '' ? ' · '+ request.templateName : '' ) ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', dates.join(' · ') ) );
			head.appendChild( copy );

			const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/sign/state/'+ request.status) );

			badge.dataset.state = { signed : 'ok', requested : 'warn', viewed : 'warn', declined : 'error', changed : 'warn' }[request.status] ?? 'muted';
			head.appendChild( badge );
			row.appendChild( head );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions sign-row-actions' );

			[ [ 'shown', request.files.shown, '/_admin/sign/label/shown' ], [ 'signed', request.files.signed, '/_admin/sign/label/signed-document' ] ].forEach( function( each ) {

				const href = sign._safe( request.urls[each[0]] );

				if( each[1] !== true || href === '' )
					return;

				const link = Nino.adminUi.el( 'a', 'sign-open nino-admin-btn--small', Nino.content.getText( each[2] ) );

				link.href = href;
				link.target = '_blank';
				link.rel = 'noopener';
				actions.appendChild( link );
			} );

			if( waiting === true ) {

				actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/sign/label/remind'), function() {

					line.saving();

					sign.call( 'remind', { id : request.id }, function( status, response ) {

						if( status !== 200 )
							return line.error( status, response, '/_admin/sign/error/remind' );

						line.idle( Nino.content.getText('/_admin/sign/msg/reminded') );
						sign._refresh();
					} );
				}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

				actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/sign/label/cancel'), function() {

					if( wn.confirm( Nino.adminUi.format( Nino.content.getText('/_admin/sign/confirm/cancel'), sign._who( request ) ) ) === false )
						return;

					line.saving();

					sign.call( 'cancel', { id : request.id }, function( status, response ) {

						if( status !== 200 )
							return line.error( status, response, '/_admin/sign/error/cancel' );

						sign._refresh();
					} );
				}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			}

			// A request whose document was no longer the one shown: a new one of the same, in one click
			if( request.status === 'changed' )
				actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/sign/label/again'), function() {

					line.saving();

					sign.call( 'again', { id : request.id }, function( status, response ) {

						if( status !== 200 ) {
							line.error( status, response, '/_admin/sign/error/again' );

							// The new request is there when only its mail could not be sent
							if( response !== null && typeof response === 'object' && ( response.code === 'sign_mail' || response.code === 'sign_ratelimit' ) )
								sign._refresh();

							return;
						}

						line.idle( Nino.content.getText('/_admin/sign/msg/again') );
						sign._refresh();
					} );
				}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText( sign._open === request.id ? '/_admin/sign/label/hide' : '/_admin/sign/label/details' ), function() {
				sign._open = sign._open === request.id ? '' : request.id;
				sign._renderRows();
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/sign/label/delete'), function() {

				if( wn.confirm( Nino.adminUi.format( Nino.content.getText('/_admin/sign/confirm/delete'), sign._who( request ) ) ) === false )
					return;

				line.saving();

				sign.call( 'delete', { id : request.id }, function( status, response ) {

					if( status !== 200 )
						return line.error( status, response, '/_admin/sign/error/delete' );

					sign._refresh();
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } ) );

			actions.appendChild( line.element );
			row.appendChild( actions );

			if( sign._open === request.id )
				row.appendChild( sign._renderRecord( request ) );

			return row;
		},

		/**
		 *	The record of a request as a list of terms: the time the link is good until, and - once it is signed - the address,
		 *	the browser, how it was signed, the sentence agreed to and the hashes of the two documents. Every value is text
		 *
		 *	@param		{Object}	request
		 *
		 *	@return		{Element}
		 */
		_renderRecord : function( request ) {

			const sign = Nino.admin.sign;
			const list = Nino.adminUi.el( 'dl', 'sign-record' );
			const record = request.record !== null && typeof request.record === 'object' ? request.record : {};
			const terms = [
				[ 'expires', sign._until( request.expires ) ],
				[ 'address', record.ip ],
				[ 'agent', record.agent ],
				[ 'method', request.method !== '' ? Nino.content.getText('/_admin/sign/method/'+ request.method) : '' ],
				[ 'consent', record.consent ],
				[ 'hash-shown', request.hashShown ],
				[ 'hash-signed', request.hashSigned ],
			];

			terms.forEach( function( term ) {

				if( typeof term[1] !== 'string' || term[1] === '' )
					return;

				list.appendChild( Nino.adminUi.el( 'dt', '', Nino.content.getText('/_admin/sign/label/'+ term[0]) ) );
				list.appendChild( Nino.adminUi.el( 'dd', '', term[1] ) );
			} );

			return list;
		},

		/**
		 *	What the shell asks about unsaved input: whether the form holds some
		 *
		 *	@return		{boolean}
		 */
		_dirty : function() {
			return Nino.admin.sign._formDirty();
		},

		/**
		 *	The shell's Discard: what is typed is forgotten
		 *
		 *	@return		void
		 */
		_discard : function() {

			const form = Nino.admin.sign._form;

			if( form === null )
				return;

			form.title.value = '';
			form.inputs.forEach( function( each ) { each.control.value = '' } );
		},
	} );

} )(window,document);

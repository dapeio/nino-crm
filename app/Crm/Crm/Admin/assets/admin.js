/**
 *	Crm\Crm\Admin		The panel CRM of the workbench: one screen with the way into the surface at /crm, a button, the status of the module - one line
 *									for each thing it misses, or the sentence that all is well - and the button that applies the install unit,
 *									the portal pages. Draws into the one region of templates/panel.tpl with the workbench's own builders;
 *									every word is a fill, every value of the server goes in as text, and every request goes through the panel's
 *									call(), which posts crm/<action>. The first showing asks for the status.
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	const crm = Nino.adminUi.panel( {
		uri			: 'crm',
		mount		: 'crm-app',
		action	: 'status',
		show		: 'always',
		render	: function( data ) {
			Nino.admin.crm._render( Array.isArray( data.items ) === true ? data.items : [] );
		},
	} );

	Nino.admin.crm = crm;

	Object.assign( crm, {

		_text : function( key, ...params ) {
			return Nino.adminUi.format( Nino.content.getText( key ), ...params );
		},

		/**
		 *	The screen: the link to the surface, then the status
		 *
		 *	@param		{Array}		items			The items of crm/status
		 *	@param		{string}	[said]		What the setup said, kept over a screen that is drawn again
		 *
		 *	@return		void
		 */
		_render : function( items, said ) {

			const mount = crm.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';

			const open = Nino.adminUi.el( 'p', 'crm-open' );
			const to = ( typeof Nino.dir === 'string' ? Nino.dir : '' )+ '/crm';

			// A button that leaves the page: the kernel's primary look is a class of a button, a link has none
			const link = Nino.adminUi.button( Nino.content.getText('/_admin/crm/label/open'), function() { wn.location.href = to }, { className : 'nino-admin-btn-primary' } );

			link.id = 'crm-open';
			link.dataset.href = to;
			open.appendChild( link );
			mount.appendChild( open );

			const box = Nino.adminUi.el( 'div', 'crm-status' );

			box.id = 'crm-status';
			mount.appendChild( box );

			const missing = items.filter( function( item ) { return item.state !== 'ok' && item.state !== 'own' } );

			if( missing.length === 0 )
				box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/crm/status/allok') ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'crm-missing' );

				missing.forEach( function( item ) {
					const key = '/_admin/crm/status/'+ item.key+ '-'+ item.state;

					// A portal that is only partly there names what it lacks; the PDF templates that are not there are named by their keys
					const text = item.state === 'partial' && Array.isArray( item.lacks ) === true
						? crm._text( key, item.lacks.map( function( kind ) { return item.key === 'pdf-templates' ? kind : Nino.content.getText( '/_admin/crm/status/lacks-'+ kind ) } ).join(', ') )
						: Nino.content.getText( key );

					list.appendChild( Nino.adminUi.el( 'li', '', text ) );
				} );

				box.appendChild( list );
			}

			const line = Nino.adminUi.status( Nino.adminUi.el( 'span' ) );

			if( typeof said === 'string' )
				line.idle( said );

			const setup = Nino.adminUi.button( Nino.content.getText('/_admin/crm/label/setup'), function() {

				setup.disabled = true;
				line.saving();

				crm.call( 'setup', {}, function( code, answer ) {

					setup.disabled = false;

					if( code !== 200 ) {
						line.error( code, answer, '/_admin/crm/error/setup' );
						return;
					}

					crm.call( 'status', {}, function( status, response ) {

						if( status !== 200 ) {
							line.error( status, response, '/_admin/crm/error/status' );
							return;
						}

						crm._render( Array.isArray( response.items ) === true ? response.items : [], crm._setupSentence( answer ) );
					} );
				} );
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			setup.id = 'crm-setup';
			setup.hidden = missing.length === 0;
			actions.appendChild( setup );
			actions.appendChild( line.element );
			box.appendChild( actions );
		},

		/**
		 *	What the setup says it did, in one sentence
		 *
		 *	@param		{Object}	answer
		 *
		 *	@return		{string}
		 */
		_setupSentence : function( answer ) {

			const none = { features : [], routes : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : false };
			const added = Object.assign( {}, none, answer.added );
			const existed = Object.assign( {}, none, answer.existed );
			const there = existed.features.length+ existed.routes.length+ existed.templates.length+ existed.types.length+ existed.pdf.templates.length;
			const sentence = crm._text( '/_admin/crm/setup/done', added.features.length, added.routes.length, added.templates.length, added.types.length, added.texts, added.pdf.templates.length, there );

			// What became of the start page, said in the answer
			const home = typeof added.home === 'string' ? added.home : ( existed.home === 'own' ? 'own' : '' );

			return home !== '' ? sentence+ ' '+ Nino.content.getText( '/_admin/crm/setup/home-'+ home ) : sentence;
		},
	} );

} )(window);

/**
 *	Crm\Items\Admin		The panel Positionen of the CRM module: the catalogue of what the owner sells - a list with a
 *										search, the card of one (title, description, unit, price and, with VAT, its rate), active or not,
 *										and the arrows that put the items in their order. Draws into the one region of templates/panel.tpl
 *										with the workbench's own builders; every word is a fill, every value of the server goes in as text,
 *										and every request goes through the panel's call(), which posts crm-items/<action>. The price is
 *										typed as text and the server reads it; the money functions are those of money.js.
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	const money = wn.Nino.crm.money;

	const items = Nino.adminUi.panel( {
		uri			: 'crm-items',
		mount		: 'crm-items-app',
		payload	: function() { return { search : Nino.admin['crm-items']._search } },
		dirty		: {
			isDirty : function() { return Nino.admin['crm-items']._dirty() },
			save		: function( done ) { Nino.admin['crm-items']._save( done ) },
			discard : function() { Nino.admin['crm-items']._discard() },
		},
		render	: function( data ) {

			const panel = Nino.admin['crm-items'];

			panel._items = Array.isArray( data.items ) === true ? data.items : [];
			panel._settings = data.settings ?? { vat_mode : 'small_business', rates : [], currency : '' };
			panel._card = null;
			panel._render();
		},
	} );

	Nino.admin['crm-items'] = items;

	Object.assign( items, {

		_items		: [],
		_settings	: { vat_mode : 'small_business', rates : [], currency : '' },
		_search		: '',
		// The open card: { item (null for a new one), form, save, line, fields, values } - null while the list shows
		_card			: null,

		_text : function( key, ...params ) {
			return Nino.adminUi.format( Nino.content.getText( key ), ...params );
		},

		_line : function() {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ) );
		},

		_regular : function() {
			return items._settings.vat_mode === 'regular';
		},

		/**
		 *	The list screen: the search, the items with their arrows and the button for a new one
		 *
		 *	@return		void
		 */
		_render : function() {

			const mount = items.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			items._card = null;

			const form = Nino.adminUi.el( 'form', 'crm-search' );
			const field = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/crm-items/label/search'), value : items._search, maxLength : 100, type : 'search', autocomplete : 'off' } );
			field.querySelector('input').name = 'search';
			form.appendChild( field );
			form.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/crm-items/label/find'), null, { type : 'submit', className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				items._search = field.querySelector('input').value;
				items.reload();
			} );
			mount.appendChild( form );

			const line = items._line();
			line.element.classList.add('crm-order-line');

			if( items._items.length === 0 )
				mount.appendChild( Nino.adminUi.emptyState( Nino.content.getText( items._search === '' ? '/_admin/crm-items/empty/items' : '/_admin/crm-items/empty/found' ) ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list' );

				items._items.forEach( function( item, index ) { list.appendChild( items._row( item, index, line ) ) } );
				mount.appendChild( list );
			}

			const add = Nino.adminUi.button( Nino.content.getText('/_admin/crm-items/label/new'), function() { items._drawCard( null ) }, { className : 'nino-admin-btn-primary' } );
			mount.appendChild( Nino.adminUi.listActions( [ add, line.element ] ) );
		},

		/**
		 *	One row: the item, and the arrows that move it. The order is only changed on a list that shows every item
		 *
		 *	@param		{Object}	item
		 *	@param		{number}	index
		 *	@param		{Object}	line				The status line the order says its failures in
		 *
		 *	@return		{Element}
		 */
		_row : function( item, index, line ) {

			const li = Nino.adminUi.el( 'li', 'crm-item-row' );
			const row = Nino.adminUi.el( 'button', 'nino-admin-list-link' );
			const copy = Nino.adminUi.el( 'span', 'nino-admin-list-copy' );
			const details = [ item.unit, money.format( item.price_net, items._settings.currency ) ];

			if( items._regular() === true )
				details.push( money.formatRate( item.vat_rate ) );

			row.type = 'button';
			row.dataset.id = String( item.id );
			copy.appendChild( Nino.adminUi.el( 'strong', '', item.title ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', details.filter( function( part ) { return part !== '' } ).join(' · ') ) );
			row.appendChild( copy );

			if( item.active === false ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/crm-items/state/inactive') );
				badge.dataset.state = 'muted';
				row.appendChild( badge );
			}

			row.addEventListener( 'click', function() { items._drawCard( item ) } );
			li.appendChild( row );

			const whole = items._search === '';
			const move = function( by ) {

				const ids = items._items.map( function( other ) { return other.id } );
				const [ taken ] = ids.splice( index, 1 );

				ids.splice( index+ by, 0, taken );
				line.saving();

				items.call( 'order', { ids : ids }, function( status, response ) {

					if( status !== 200 ) {
						line.error( status, response, '/_admin/crm-items/error/order' );
						return;
					}

					items._items = response.items;
					items._render();
				} );
			};

			const up = Nino.adminUi.button( '↑', function() { move( -1 ) }, { name : Nino.content.getText('/_admin/crm-items/label/up'), disabled : whole === false || index === 0, className : 'crm-move crm-move-up' } );
			const down = Nino.adminUi.button( '↓', function() { move( 1 ) }, { name : Nino.content.getText('/_admin/crm-items/label/down'), disabled : whole === false || index === items._items.length - 1, className : 'crm-move crm-move-down' } );

			li.appendChild( up );
			li.appendChild( down );

			return li;
		},

		/**
		 *	The card: the back link and the form of the item
		 *
		 *	@param		{Object|null}	item
		 *	@param		{boolean}			[saved]			Whether the line says the card is saved
		 *	@param		{Object}			[typed]			name => value of what the person typed and nobody saved, put back into the fields
		 *
		 *	@return		void
		 */
		_drawCard : function( item, saved, typed ) {

			const mount = items.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';

			const back = Nino.adminUi.el( 'a', 'nino-admin-back-link', Nino.content.getText('/_admin/crm-items/label/back') );
			back.href = '#';
			back.addEventListener( 'click', function( event ) {
				event.preventDefault();
				items._close();
			} );
			mount.appendChild( back );

			const form = Nino.adminUi.el( 'form', 'crm-card' );
			const fields = {};
			const line = items._line();
			const save = Nino.adminUi.button( Nino.content.getText('/_admin/crm-items/label/save'), null, { type : 'submit', className : 'nino-admin-btn-primary' } );

			form.appendChild( Nino.adminUi.el( 'h3', '', item === null ? Nino.content.getText('/_admin/crm-items/label/new') : item.title ) );

			[ [ 'title', 200 ], [ 'unit', 20 ], [ 'price', 20 ] ].forEach( function( [ name, max ] ) {

				const field = Nino.adminUi.textField( {
					label			: Nino.content.getText('/_admin/crm-items/field/'+ name ),
					value			: item === null ? '' : ( name === 'price' ? money.format( item.price_net ) : item[name] ),
					maxLength	: max,
					autocomplete	: 'off',
					required	: name === 'title',
				} );

				fields[name] = field.querySelector('input');
				fields[name].name = name;

				if( name === 'price' )
					fields[name].inputMode = 'decimal';

				form.appendChild( field );
			} );

			if( items._regular() === true ) {

				const rates = items._settings.rates.slice();

				if( item !== null && rates.indexOf( item.vat_rate ) === -1 )
					rates.push( item.vat_rate );

				const select = Nino.adminUi.selectField( {
					key		: 'vat_rate',
					label	: Nino.content.getText('/_admin/crm-items/field/vat-rate'),
					value	: item === null ? ( rates[0] ?? 0 ) : item.vat_rate,
					options	: rates.map( function( rate ) { return { value : rate, label : money.formatRate( rate ) } } ),
				} );

				fields.vat_rate = select.querySelector('select');
				fields.vat_rate.name = 'vat_rate';
				form.appendChild( select );
			}

			const descriptionLabel = Nino.adminUi.el( 'label', 'nino-admin-field' );
			const description = Nino.adminUi.el( 'textarea', 'nino-admin-input' );

			description.name = 'description';
			description.rows = 4;
			description.maxLength = 2000;
			description.value = item === null ? '' : item.description;
			descriptionLabel.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText('/_admin/crm-items/field/description') ) );
			descriptionLabel.appendChild( description );
			fields.description = description;
			form.appendChild( descriptionLabel );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );
			actions.appendChild( save );

			if( item !== null )
				actions.appendChild( Nino.adminUi.button( Nino.content.getText( item.active === true ? '/_admin/crm-items/label/deactivate' : '/_admin/crm-items/label/activate' ), function() {

					line.saving();

					const unsaved = items._values( fields );

					items.call( 'active', { id : String( item.id ), active : item.active === false }, function( status, response ) {

						if( status !== 200 ) {
							line.error( status, response, '/_admin/crm-items/error/active' );
							return;
						}

						// What was typed and not saved is typed again into the card that is drawn, which still counts it as unsaved
						items._drawCard( response.item, false, unsaved );
					} );
				}, { className : 'nino-admin-btn-secondary' } ) );

			actions.appendChild( line.element );
			form.appendChild( actions );

			items._card = { item : item, form : form, save : save, line : line, fields : fields, values : items._values( fields ) };
			line.bind( form, function() { return items._dirty() } );

			if( saved === true )
				line.saved( new Date() );

			if( typed !== undefined )
				Object.keys( typed ).forEach( function( name ) { fields[name].value = typed[name] } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				items._save( function() {} );
			} );

			mount.appendChild( form );

			if( item === null )
				fields.title.focus();
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

			const card = items._card;

			if( card === null )
				return false;

			const now = items._values( card.fields );

			return Object.keys( now ).some( function( name ) { return now[name] !== card.values[name] } );
		},

		_discard : function() {

			const card = items._card;

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

			const leave = function() { items.reload() };

			if( items._dirty() === true && typeof Nino.admin.dirty === 'object' ) {
				Nino.admin.dirty.guard( [ 'crm-items' ], leave, function() {} );
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

			const card = items._card;

			if( card === null || card.save.disabled === true ) {
				done( false );
				return;
			}

			card.save.disabled = true;
			card.line.saving();

			const payload = items._values( card.fields );

			// Without VAT an item has no rate but 0, whatever the setting held when it was made
			payload.vat_rate = items._regular() === true ? Number( payload.vat_rate ) : 0;

			if( card.item !== null )
				payload.id = String( card.item.id );

			items.call( 'save', payload, function( status, response ) {

				card.save.disabled = false;

				if( status !== 200 ) {
					card.line.error( status, response, '/_admin/crm-items/error/save' );
					done( false );
					return;
				}

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				// The card is drawn again from what is stored, and its line says it is saved
				items._drawCard( response.item, true );
				done( true );
			} );
		},
	} );

} )(window);

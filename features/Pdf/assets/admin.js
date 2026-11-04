/**
 *	Nino									A compact filesystembased php framework
 *	Modules\Pdf							The feature's /_admin panel, "PDF": a pane of three tabs - the Layout, the text
 *												blocks and the templates - and the Settings tab the kernel attaches, drawn by one
 *												script of three namespaces, as the shell asks for a screen by the uri of its tab
 *												(Nino.admin[<uri>]): Nino.admin.pdf is the Layout, the panel's own screen,
 *												Nino.admin['pdf-blocks'] the library of text blocks and Nino.admin['pdf-templates']
 *												the templates and their editor.
 *
 *												Layout: the paper, the four margins, the font and the base size, the header and the
 *												footer as HTML, the design - the feature's own rules as CSS, read-only, the owner's
 *												rules over them, and a preview of the sample text with the design as it is saved -
 *												and the logo and the owner's signature, which are put in and taken away when the
 *												button is pressed and are no part of the form. Text blocks: a list
 *												and, a level below, the title and the HTML of one block. Templates: a list - name,
 *												tag, how many blocks - and, a level below, the editor: a name, a tag, the blocks of
 *												the document in their order, each with the form of its type, and a preview, which
 *												asks the server for the PDF of what the editor holds - saved or not - and shows it
 *												in a frame from a blob, with a link to download it.
 *
 *												Every request goes through Nino.adminUi.api.call(), every text of the server or of
 *												a person is set as text, every word is a fill. Ships with the feature and is loaded
 *												exactly while it is active.
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn,dc) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The words are fills; a request is one of the panel's actions
	const word = function( key ) { return Nino.content.getText( key ) };
	const call = function( action, payload, done, extra ) { Nino.adminUi.api.call( 'pdf/'+ action, payload, done, extra ) };
	const el = function( tag, className, text ) { return Nino.adminUi.el( tag, className, text ) };
	const dirtyRegistry = function() { return typeof Nino.admin.dirty === 'object' && Nino.admin.dirty !== null ? Nino.admin.dirty : null };
	const refresh = function() { const registry = dirtyRegistry(); if( registry !== null && typeof registry.refresh === 'function' ) registry.refresh() };

	// The tokens a header, a footer and a block may hold, as the hint names them. The text key's form is put together
	// here, where a fill's value cannot carry it: a double bracket in a value is read as a fill
	const TOKENS = '[[page]], [[pages]], [[name]], [[' + '/text/key]]';
	const BODY_TOKENS = '[[name]], [[a.b]], [[' + '/text/key]]';

	// A line of the status of a screen, bound to the root it marks refused fields in; isDirty says whether the screen still differs from what is saved
	const statusLine = function( root, isDirty ) {
		const line = Nino.adminUi.status( el( 'span' ) );
		line.bind( root, isDirty );
		return line;
	};

	// Something the form's own events do not tell: a block was added, moved or taken away
	const touched = function( line, isDirty ) {
		if( isDirty() === true )
			line.dirty();
		refresh();
	};

	// A labelled textarea, the control the field's name marks for a refusal
	const area = function( options ) {

		const label = el( 'label', 'nino-admin-field nino-admin-field-wide' );
		const input = el( 'textarea', 'nino-admin-input pdf-area' );

		label.appendChild( el( 'span', '', options.label ) );
		input.value = options.value;
		input.maxLength = options.max;
		input.rows = options.rows ?? 8;
		input.setAttribute( 'data-field', options.field );
		input.addEventListener( 'input', function() { options.onInput( input.value ) } );
		label.appendChild( input );

		if( options.hint )
			label.appendChild( el( 'small', 'nino-admin-hint', options.hint ) );

		return label;
	};

	// The select of a field gets the name the server calls it by in a refusal, so that the refusal can mark it
	const choice = function( field, name ) {
		field.querySelector('select').setAttribute( 'data-field', name );
		return field;
	};

	// A number input of the shape the server holds the field to
	const number = function( options ) {

		const field = Nino.adminUi.textField( { label : options.label, type : 'number', value : String( options.value ), onInput : function( value ) {
			options.onInput( value.trim() !== '' && isNaN( Number( value ) ) === false ? Number( value ) : value );
		} } );
		const input = field.querySelector('input');

		input.min = String( options.min );
		input.max = String( options.max );
		input.step = String( options.step );
		input.setAttribute( 'data-field', options.field );

		return field;
	};

	// The question before something is thrown away
	const confirmDelete = function( title, message, onYes ) {

		Nino.adminUi.choiceDialog( {
			title		: title,
			message	: message,
			choices	: [ { value : 'delete', label : word('/_admin/pdf/label/delete'), kind : 'danger' }, { value : 'cancel', label : word('/_admin/pdf/label/cancel'), kind : 'secondary' } ],
			onChoose: function( value ) { if( value === 'delete' ) onYes() },
		} );
	};

	// The step of a list row that opens a level
	const row = function( name, meta, onOpen ) {

		const li = el( 'li' );
		const open = el( 'button', 'nino-admin-list-link' );

		open.type = 'button';
		open.appendChild( el( 'strong', '', name ) );
		open.appendChild( el( 'small', '', meta ) );
		open.addEventListener( 'click', function() { onOpen( open ) } );
		li.appendChild( open );

		return { li : li, open : open };
	};

	// The back link of a level
	const back = function( onBack ) {

		const link = el( 'a', 'nino-admin-back-link', word('/_admin/pdf/label/back') );

		link.href = '#';
		link.addEventListener( 'click', function( event ) { event.preventDefault(); onBack() } );

		return link;
	};

	/*	Whether what the screen holds is saved: it compares the model with the string it held when it was drawn, which
		is how every one of the three screens answers the shell's question about unsaved input	*/

	// ------------------------------------------------------------------ Layout

	const layout = {

		model : null,
		saved : '',
		line : null,

		// The model of a form that is drawn again for a picture, which is kept as the person left it
		keep : null,

		fields : function( data ) {
			return { format : data.layout.format, margins : data.layout.margins.slice(), font : data.layout.font, size : data.layout.size, header : data.layout.header, footer : data.layout.footer, design : data.layout.design ?? '' };
		},

		isDirty : function() {
			return layout.model !== null && JSON.stringify( layout.model ) !== layout.saved;
		},

		save : function( done ) {

			if( layout.model === null ) {
				done( false );
				return;
			}

			// What is posted: the answer says what was stored of the header and the footer, which is not always what was typed
			const posted = JSON.stringify( layout.model );

			layout.line.saving();

			call( 'layout/save', layout.model, function( status, response ) {

				const form = dc.getElementById('pdf-layout-form');

				// The field a refusal names is described by the status line that says why
				form.querySelectorAll('[data-field]').forEach( function( field ) { field.removeAttribute('aria-describedby') } );

				if( status !== 200 ) {
					layout.line.error( status, response, '/_admin/pdf/error/save' );

					const refused = response !== null && typeof response === 'object' && typeof response.field === 'string' ? form.querySelector('[data-field="'+ response.field+ '"]') : null;

					if( refused !== null )
						refused.setAttribute( 'aria-describedby', 'pdf-layout-status' );

					done( false );
					return;
				}

				layout.stored( response.refusal ?? null );
				const sent = JSON.parse( posted );
				const unchanged = JSON.stringify( layout.model ) === posted;

				// The texts are the ones the server stored - the list of what a document may hold has cleaned the two bands, the design
				// is trimmed - and the form shows them from now on, unless the person has gone on typing since the post
				[ 'header', 'footer', 'design' ].forEach( function( band ) {

					sent[band] = response.layout[band];

					if( unchanged === true ) {
						layout.model[band] = sent[band];
						dc.getElementById('pdf-layout-form').querySelector('[data-field="'+ band+ '"]').value = sent[band];
					}
				} );

				layout.saved = JSON.stringify( sent );
				layout.line.saved();
				refresh();
				done( true );
			} );
		},

		render : function( data, panel ) {

			const mount = panel.mount();
			const form = el( 'form', 'pdf-form' );
			const grid = el( 'div', 'nino-admin-fieldgrid' );
			const margins = el( 'fieldset', 'nino-admin-card pdf-margins' );
			const bar = Nino.adminUi.actionBar( el( 'div' ) );
			const wide = data.ranges.margin;

			layout.model = layout.keep !== null ? layout.keep : layout.fields( data );

			if( layout.keep === null )
				layout.saved = JSON.stringify( layout.model );

			layout.keep = null;
			mount.innerHTML = '';
			layout.line = statusLine( form, layout.isDirty );
			layout.line.element.id = 'pdf-layout-status';

			grid.appendChild( choice( Nino.adminUi.selectField( { key : 'format', label : word('/_admin/pdf/label/paper'), value : layout.model.format, onChange : function( value ) { layout.model.format = value },
				options : data.papers.map( function( paper ) { return { value : paper, label : word('/_admin/pdf/paper/'+ paper) } } ) } ), 'format' ) );
			grid.appendChild( choice( Nino.adminUi.selectField( { key : 'font', label : word('/_admin/pdf/label/font'), value : layout.model.font, onChange : function( value ) { layout.model.font = value },
				options : data.fonts.map( function( font ) { return { value : font, label : word('/_admin/pdf/font/'+ font) } } ) } ), 'font' ) );
			grid.appendChild( number( { label : word('/_admin/pdf/label/size'), field : 'size', value : layout.model.size, min : data.ranges.size[0], max : data.ranges.size[1], step : 1,
				onInput : function( value ) { layout.model.size = value } } ) );
			form.appendChild( grid );

			margins.appendChild( el( 'legend', '', word('/_admin/pdf/label/margins') ) );
			[ 'top', 'right', 'bottom', 'left' ].forEach( function( side, at ) {
				const field = number( { label : word('/_admin/pdf/label/'+ side), field : at === 0 ? 'margins' : 'margins-'+ side, value : layout.model.margins[at], min : wide[0], max : wide[1], step : 1,
					onInput : function( value ) { layout.model.margins[at] = value } } );
				margins.appendChild( field );
			} );
			form.appendChild( margins );

			[ 'header', 'footer' ].forEach( function( band ) {
				form.appendChild( area( { label : word('/_admin/pdf/label/'+ band), field : band, value : layout.model[band], max : data.ranges.band, rows : 5,
					hint : Nino.adminUi.format( word('/_admin/pdf/hint/band'), TOKENS ), onInput : function( value ) { layout.model[band] = value } } ) );
			} );

			form.appendChild( layout.design( data ) );
			form.appendChild( layout.pictures( data ) );

			form.addEventListener( 'submit', function( event ) { event.preventDefault(); layout.save( function() {} ) } );

			bar.appendChild( layout.line.element );
			bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/save'), function() { layout.save( function() {} ) }, { className : 'nino-admin-btn-primary' } ) );

			mount.appendChild( form );
			mount.appendChild( bar );
		},

		// The design: the feature's rules as CSS, to read, the owner's rules over them, to write, and a preview of the sample text
		// with the design as it is saved. A refusal of the sheet names its line in the status line and marks the textarea
		design : function( data ) {

			const card = el( 'fieldset', 'nino-admin-card pdf-design' );
			const sheet = el( 'textarea', 'nino-admin-input pdf-area pdf-sheet' );
			const frame = layout.previewBox();

			card.appendChild( el( 'legend', '', word('/_admin/pdf/label/design') ) );
			card.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/design') ) );

			const defaults = el( 'label', 'nino-admin-field nino-admin-field-wide' );
			sheet.value = data.sheet ?? '';
			sheet.readOnly = true;
			sheet.rows = 18;
			sheet.setAttribute( 'spellcheck', 'false' );
			sheet.setAttribute( 'data-field', 'sheet' );
			defaults.appendChild( el( 'span', '', word('/_admin/pdf/label/design-sheet') ) );
			defaults.appendChild( sheet );
			card.appendChild( defaults );

			const own = area( { label : word('/_admin/pdf/label/design-own'), field : 'design', value : layout.model.design, max : data.ranges.design, rows : 8,
				hint : word('/_admin/pdf/hint/design-own'), onInput : function( value ) { layout.model.design = value } } );
			own.querySelector('textarea').setAttribute( 'spellcheck', 'false' );
			card.appendChild( own );

			// A stored design that is no sheet is shown with why it is not read; nothing of it is lost
			const stored = el( 'p', 'nino-admin-error pdf-design-stored' );
			stored.id = 'pdf-design-stored';
			card.appendChild( stored );
			layout.stored( data.refusal ?? null, stored );

			const bar = el( 'div', 'pdf-design-bar' );
			bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/preview'), function() { layout.preview() }, { className : 'nino-admin-btn--small', id : 'pdf-design-preview-button' } ) );
			card.appendChild( bar );
			card.appendChild( frame );

			return card;
		},

		// The sentence about a stored design that is not read, or nothing
		stored : function( refusal, paragraph ) {

			const target = paragraph ?? dc.getElementById('pdf-design-stored');

			if( target === null )
				return;

			// The sentence is the panel's own for the code of the refusal, the one a save of the same text says
			target.textContent = refusal === null ? '' : Nino.adminUi.format( word('/_admin/pdf/label/design-stored'), Nino.adminUi.api.errorText( 400, refusal, '/_admin/pdf/error/save' ) );
			target.hidden = refusal === null;
		},

		// The frame of the preview and the link that downloads what it shows
		previewBox : function() {

			const box = el( 'div', 'pdf-preview' );
			const frame = el( 'iframe', 'pdf-preview-frame' );
			const link = el( 'a', 'nino-admin-btn nino-admin-btn--small pdf-preview-link', word('/_admin/pdf/label/download') );

			box.id = 'pdf-design-preview';
			box.hidden = true;
			frame.setAttribute( 'title', word('/_admin/pdf/label/preview') );
			link.setAttribute( 'download', 'design.pdf' );
			box.appendChild( link );
			box.appendChild( frame );

			return box;
		},

		// The sample text with the layout and the design as they are saved
		preview : function() {

			layout.line.saving();

			call( 'layout/preview', {}, function( status, response ) {

				if( status !== 200 ) {
					layout.line.error( status, response, '/_admin/pdf/error/preview' );
					return;
				}

				const bytes = Uint8Array.from( atob( response.pdf ), function( char ) { return char.charCodeAt( 0 ) } );
				const box = dc.getElementById('pdf-design-preview');

				if( layout.url !== '' )
					URL.revokeObjectURL( layout.url );

				layout.url = URL.createObjectURL( new Blob( [ bytes ], { type : 'application/pdf' } ) );
				box.querySelector('iframe').src = layout.url;
				box.querySelector('a').href = layout.url;
				box.hidden = false;
				if( layout.isDirty() === true )
					layout.line.dirty();
				else
					layout.line.idle( '' );
			} );
		},

		// The blob address of the last preview
		url : '',

		// The logo and the owner's signature: a picture, a file to put in, and a button to take it away. They are written
		// when they are chosen - the layout the form holds is not part of it
		pictures : function( data ) {

			const card = el( 'fieldset', 'nino-admin-card pdf-pictures' );

			card.appendChild( el( 'legend', '', word('/_admin/pdf/label/pictures') ) );
			card.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/pictures') ) );

			[ 'logo', 'signature' ].forEach( function( kind ) {

				const box = el( 'div', 'pdf-picture' );
				const name = data.layout[kind];
				const uri = data.images[kind];
				const input = el( 'input', 'pdf-file' );
				const notice = el( 'p', 'nino-admin-hint' );

				box.appendChild( el( 'h3', '', word('/_admin/pdf/label/'+ kind) ) );

				if( uri !== '' ) {
					const img = el( 'img', 'pdf-picture-image' );
					img.src = uri;
					img.alt = word('/_admin/pdf/label/'+ kind);
					box.appendChild( img );
				}
				else if( name !== '' )
					box.appendChild( el( 'p', 'nino-admin-hint', name ) );
				else
					box.appendChild( Nino.adminUi.emptyState( word('/_admin/pdf/label/none') ) );

				input.type = 'file';
				input.setAttribute( 'accept', 'image/png,image/jpeg' );
				input.setAttribute( 'aria-label', word('/_admin/pdf/label/choose') );
				input.addEventListener( 'change', function() {

					const file = input.files && input.files[0] ? input.files[0] : null;

					if( file === null )
						return;

					Nino.adminUi.checkImage( file, function( refusal ) {

						if( refusal !== null ) {
							notice.textContent = Nino.adminUi.api.errorText( 400, refusal, '/_admin/pdf/error/image' );
							return;
						}

						notice.textContent = '';
						call( 'layout/image', { kind : kind }, function( status, response ) { layout.picturesDone( status, response, notice ) }, { file : file } );
					} );
				} );
				box.appendChild( input );

				const hint = Nino.adminUi.uploadHint();

				if( hint !== null )
					box.appendChild( hint );

				if( name !== '' )
					box.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/remove'), function() {
						confirmDelete( word('/_admin/pdf/label/'+ kind), word('/_admin/pdf/msg/remove-picture'), function() {
							call( 'layout/image/delete', { kind : kind }, function( status, response ) { layout.picturesDone( status, response, notice ) } );
						} );
					}, { className : 'nino-admin-btn--small nino-admin-btn-danger' } ) );

				box.appendChild( notice );
				card.appendChild( box );
			} );

			return card;
		},

		// A picture was written or removed: the screen is drawn again from the answer, with the form as the person left it
		picturesDone : function( status, response, notice ) {

			if( status !== 200 ) {
				notice.textContent = Nino.adminUi.api.errorText( status, response, '/_admin/pdf/error/image' );
				return;
			}

			layout.keep = layout.model;
			Nino.admin.pdf.data = response;
			layout.render( response, Nino.admin.pdf );
		},
	};

	Nino.admin.pdf = Nino.adminUi.panel( {
		uri		: 'pdf',
		mount	: 'pdf-layout-form',
		action: 'layout/get',
		dirty	: { isDirty : layout.isDirty, save : layout.save, discard : function() { if( layout.model !== null ) layout.model = JSON.parse( layout.saved ) } },
		render: layout.render,
	} );

	// ------------------------------------------------------------------ Text blocks

	const blocks = {

		items : [],
		edit : null,
		saved : '',
		line : null,
		ranges : { title : 120, html : 20000 },

		isDirty : function() {
			return blocks.edit !== null && JSON.stringify( blocks.edit ) !== blocks.saved;
		},

		render : function( data ) {

			blocks.items = data.blocks;
			blocks.ranges = data.ranges;
			blocks.edit = null;
			blocks.list();
		},

		list : function() {

			const mount = dc.getElementById('pdf-blocks-list');
			const list = el( 'ul', 'nino-admin-list' );
			const add = Nino.adminUi.button( word('/_admin/pdf/label/new'), function() { blocks.open( null, add ) }, { className : 'nino-admin-btn-primary' } );

			mount.innerHTML = '';
			mount.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/blocks') ) );

			if( blocks.items.length === 0 )
				mount.appendChild( Nino.adminUi.emptyState( word('/_admin/pdf/label/no-blocks') ) );

			blocks.items.forEach( function( block ) {
				const item = row( block.title, block.key, function( opener ) { blocks.open( block, opener ) } );
				item.open.setAttribute( 'data-key', block.key );
				list.appendChild( item.li );
			} );

			mount.appendChild( list );
			mount.appendChild( Nino.adminUi.listActions( [ add ] ) );
			Nino.adminUi.showLevel( 'pdf-blocks-list', { focus : false } );
		},

		open : function( block, opener ) {

			const mount = dc.getElementById('pdf-blocks-edit');
			const form = el( 'form', 'pdf-form' );
			const bar = Nino.adminUi.actionBar( el( 'div' ) );

			blocks.edit = { key : block === null ? '' : block.key, isNew : block === null, title : block === null ? '' : block.title, html : block === null ? '' : block.html };
			blocks.saved = JSON.stringify( blocks.edit );
			mount.innerHTML = '';

			mount.appendChild( Nino.adminUi.contextBar( back( function() { blocks.leave( opener ) } ) ) );
			mount.appendChild( el( 'h2', '', word( block === null ? '/_admin/pdf/label/new-block' : '/_admin/pdf/label/edit-block' ) ) );

			const title = Nino.adminUi.textField( { label : word('/_admin/pdf/label/title'), value : blocks.edit.title, required : true, maxLength : blocks.ranges.title, onInput : function( value ) { blocks.edit.title = value; if( blocks.edit.isNew === true && blocks.keyTouched === false ) blocks.suggest() } } );
			title.querySelector('input').setAttribute( 'data-field', 'title' );
			form.appendChild( title );

			const key = Nino.adminUi.textField( { label : word('/_admin/pdf/label/key'), value : blocks.edit.key, required : true, maxLength : 40, hint : word('/_admin/pdf/hint/key'), onInput : function( value ) { blocks.edit.key = value; blocks.keyTouched = true } } );
			key.querySelector('input').setAttribute( 'data-field', 'key' );
			key.querySelector('input').disabled = blocks.edit.isNew === false;
			blocks.keyTouched = blocks.edit.isNew === false;
			form.appendChild( key );

			form.appendChild( area( { label : word('/_admin/pdf/label/html'), field : 'html', value : blocks.edit.html, max : blocks.ranges.html, rows : 14,
				hint : Nino.adminUi.format( word('/_admin/pdf/hint/block-html'), BODY_TOKENS ), onInput : function( value ) { blocks.edit.html = value } } ) );

			blocks.line = statusLine( form, blocks.isDirty );
			form.addEventListener( 'submit', function( event ) { event.preventDefault(); blocks.save( function() {} ) } );

			bar.appendChild( blocks.line.element );
			bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/save'), function() { blocks.save( function() {} ) }, { className : 'nino-admin-btn-primary' } ) );

			if( blocks.edit.isNew === false )
				bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/delete'), function() {
					confirmDelete( blocks.edit.title, word('/_admin/pdf/msg/delete-block'), function() { blocks.remove() } );
				}, { className : 'nino-admin-btn-danger' } ) );

			mount.appendChild( form );
			mount.appendChild( bar );
			Nino.adminUi.showLevel( 'pdf-blocks-edit', { focus : title.querySelector('input') } );
		},

		keyTouched : false,

		// A new block's key follows its title until the key is written by hand
		suggest : function() {

			const input = dc.getElementById('pdf-blocks-edit').querySelector('[data-field="key"]');

			blocks.edit.key = Nino.slug( blocks.edit.title, 40 );

			if( input !== null )
				input.value = blocks.edit.key;
		},

		leave : function( opener ) {

			const proceed = function() {
				blocks.edit = null;
				blocks.list();
				Nino.adminUi.backTo( 'pdf-blocks-list', opener );
				refresh();
			};

			const registry = dirtyRegistry();

			if( registry !== null && blocks.isDirty() === true )
				registry.guard( [ 'pdf-blocks' ], proceed, function() {} );
			else
				proceed();
		},

		save : function( done ) {

			if( blocks.edit === null ) {
				done( false );
				return;
			}

			// What is posted: the answer says what was stored of it, which is not always what was typed
			const posted = JSON.stringify( blocks.edit );

			blocks.line.saving();

			call( 'blocks/save', blocks.edit, function( status, response ) {

				if( status !== 200 ) {
					blocks.line.error( status, response, '/_admin/pdf/error/save' );
					done( false );
					return;
				}

				const mount = dc.getElementById('pdf-blocks-edit');
				const sent = JSON.parse( posted );
				const stored = response.blocks.find( function( one ) { return one.key === sent.key } );

				// The text is the one the server stored - the list of what a document may hold has cleaned it - and the editor shows it from now
				// on, unless the person has gone on typing since the post: that text stays, and the entry still says it is not saved
				sent.isNew = false;

				if( stored !== undefined ) {

					sent.html = stored.html;

					if( JSON.stringify( blocks.edit ) === posted ) {
						blocks.edit.html = stored.html;
						mount.querySelector('[data-field="html"]').value = stored.html;
					}
				}

				blocks.items = response.blocks;
				blocks.edit.isNew = false;
				blocks.saved = JSON.stringify( sent );
				mount.querySelector('[data-field="key"]').disabled = true;
				blocks.line.saved();
				refresh();
				done( true );
			} );
		},

		remove : function() {

			call( 'blocks/delete', { key : blocks.edit.key }, function( status, response ) {

				if( status !== 200 ) {
					blocks.line.error( status, response, '/_admin/pdf/error/delete' );
					return;
				}

				blocks.items = response.blocks;
				blocks.edit = null;
				blocks.list();
				refresh();
			} );
		},
	};

	Nino.admin['pdf-blocks'] = Nino.adminUi.panel( {
		uri		: 'pdf-blocks',
		mount	: 'pdf-blocks-list',
		dirty	: { isDirty : blocks.isDirty, save : blocks.save, discard : function() { blocks.edit = null } },
		render: blocks.render,
	} );

	// This tab's requests are the panel's: pdf/blocks/<action>
	Nino.admin['pdf-blocks'].call = function( action, payload, done, extra ) { call( 'blocks/'+ action, payload, done, extra ) };

	// ------------------------------------------------------------------ Templates

	const templates = {

		items : [],
		offer : { tags : [], library : [], paper : [ 210, 297 ], ranges : { title : 120, html : 20000, label : 120, blocks : 60 } },
		edit : null,
		saved : '',
		line : null,
		url : '',

		TYPES : [ 'html', 'slot', 'input', 'signature', 'pagebreak' ],

		isDirty : function() {
			return templates.edit !== null && JSON.stringify( templates.edit ) !== templates.saved;
		},

		render : function( data ) {

			templates.items = data.templates;
			templates.offer = { tags : data.tags, library : data.library, paper : data.paper, ranges : data.ranges };
			templates.edit = null;
			templates.list();
		},

		list : function() {

			const mount = dc.getElementById('pdf-templates-list');
			const list = el( 'ul', 'nino-admin-list pdf-templates' );
			const add = Nino.adminUi.button( word('/_admin/pdf/label/new'), function() { templates.create( add ) }, { className : 'nino-admin-btn-primary' } );

			mount.innerHTML = '';
			mount.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/templates') ) );

			if( templates.items.length === 0 )
				mount.appendChild( Nino.adminUi.emptyState( word('/_admin/pdf/label/no-templates') ) );

			templates.items.forEach( function( template ) {

				const item = row( template.name, template.tag+ ' - '+ Nino.adminUi.format( word('/_admin/pdf/label/blocks'), template.blocks ), function( opener ) { templates.fetch( template.key, opener ) } );
				const actions = el( 'div', 'nino-admin-actions' );

				item.open.setAttribute( 'data-key', template.key );

				if( template.known === false )
					item.open.appendChild( el( 'span', 'nino-admin-badge', word('/_admin/pdf/label/tag-gone') ) );

				actions.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/duplicate'), function() { templates.duplicate( template ) }, { className : 'nino-admin-btn--small' } ) );
				actions.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/delete'), function() {
					confirmDelete( template.name, word('/_admin/pdf/msg/delete-template'), function() { templates.removeFromList( template.key ) } );
				}, { className : 'nino-admin-btn--small nino-admin-btn-danger' } ) );

				item.li.appendChild( actions );
				list.appendChild( item.li );
			} );

			mount.appendChild( list );
			mount.appendChild( Nino.adminUi.listActions( [ add ] ) );
			Nino.adminUi.showLevel( 'pdf-templates-list', { focus : false } );
		},

		fetch : function( key, opener ) {

			call( 'templates/get', { key : key }, function( status, response ) {

				if( status !== 200 ) {
					Nino.adminUi.showError( dc.getElementById('pdf-templates-list'), status, response, '/_admin/pdf/error/load' );
					return;
				}

				templates.offer = { tags : response.tags, library : response.library, paper : response.paper, ranges : response.ranges };
				templates.open( { key : response.key, name : response.template.name, tag : response.template.tag, blocks : response.template.blocks }, opener );
			} );
		},

		// A new template is offered what the project has now - the library may have grown in the other tab
		create : function( opener ) {

			call( 'templates/list', {}, function( status, response ) {

				if( status === 200 ) {
					templates.items = response.templates;
					templates.offer = { tags : response.tags, library : response.library, paper : response.paper, ranges : response.ranges };
				}

				templates.open( null, opener );
			} );
		},

		duplicate : function( template ) {

			call( 'templates/duplicate', { key : template.key, name : Nino.adminUi.format( word('/_admin/pdf/label/copy-of'), template.name ) }, function( status, response ) {

				if( status !== 200 ) {
					Nino.adminUi.showError( dc.getElementById('pdf-templates-list'), status, response, '/_admin/pdf/error/save' );
					return;
				}

				templates.render( response );
			} );
		},

		removeFromList : function( key ) {

			call( 'templates/delete', { key : key }, function( status, response ) {

				if( status !== 200 ) {
					Nino.adminUi.showError( dc.getElementById('pdf-templates-list'), status, response, '/_admin/pdf/error/delete' );
					return;
				}

				templates.render( response );
			} );
		},

		// The block of a template as the editor holds it: every field of its type, the html block with the library's key or a text of its own
		held : function( block ) {

			if( block.type === 'html' )
				return { type : 'html', source : typeof block.block === 'string' ? block.block : '', html : typeof block.html === 'string' ? block.html : '' };

			return Object.assign( {}, block, block.type === 'signature' ? { page : String( block.page ) } : {} );
		},

		// ... and as the server takes it
		sent : function( block ) {

			if( block.type === 'html' )
				return block.source !== '' ? { type : 'html', block : block.source } : { type : 'html', html : block.html };

			return block;
		},

		payload : function() {

			return { key : templates.edit.key, isNew : templates.edit.isNew, name : templates.edit.name, tag : templates.edit.tag, blocks : templates.edit.blocks.map( templates.sent ) };
		},

		fresh : function( type ) {

			const library = templates.offer.library;

			return {
				html				: { type : 'html', source : library.length > 0 ? library[0].key : '', html : '' },
				slot				: { type : 'slot', name : 'table' },
				input				: { type : 'input', name : 'input', label : '', multiline : false, required : false },
				signature		: { type : 'signature', name : 'signature', party : 'owner', label : '', page : 'last', x : 20, y : Math.max( templates.offer.paper[1] - 60, 20 ), w : 60, h : 20, line : true },
				pagebreak		: { type : 'pagebreak' },
			}[type];
		},

		open : function( template, opener ) {

			const mount = dc.getElementById('pdf-templates-edit');

			templates.edit = {
				key		: template === null ? '' : template.key,
				isNew	: template === null,
				name	: template === null ? '' : template.name,
				tag		: template === null ? ( templates.offer.tags[0] ?? '' ) : template.tag,
				blocks	: template === null ? [] : template.blocks.map( templates.held ),
			};
			templates.saved = JSON.stringify( templates.edit );
			templates.keyTouched = templates.edit.isNew === false;
			templates.revoke();

			mount.innerHTML = '';
			mount.appendChild( Nino.adminUi.contextBar( back( function() { templates.leave( opener ) } ) ) );
			mount.appendChild( el( 'h2', '', word( template === null ? '/_admin/pdf/label/new-template' : '/_admin/pdf/label/edit-template' ) ) );

			const form = el( 'form', 'pdf-form' );
			const grid = el( 'div', 'nino-admin-fieldgrid' );
			const name = Nino.adminUi.textField( { label : word('/_admin/pdf/label/name'), value : templates.edit.name, required : true, maxLength : templates.offer.ranges.title,
				onInput : function( value ) { templates.edit.name = value; if( templates.keyTouched === false ) templates.suggest() } } );
			const key = Nino.adminUi.textField( { label : word('/_admin/pdf/label/key'), value : templates.edit.key, required : true, maxLength : 40, hint : word('/_admin/pdf/hint/key'),
				onInput : function( value ) { templates.edit.key = value; templates.keyTouched = true } } );
			const tags = templates.offer.tags.slice();

			// A tag the project no longer lists stays what the template has until another is chosen
			if( templates.edit.tag !== '' && tags.indexOf( templates.edit.tag ) === -1 )
				tags.push( templates.edit.tag );

			name.querySelector('input').setAttribute( 'data-field', 'name' );
			key.querySelector('input').setAttribute( 'data-field', 'key' );
			key.querySelector('input').disabled = templates.edit.isNew === false;
			grid.appendChild( name );
			grid.appendChild( key );
			grid.appendChild( choice( Nino.adminUi.selectField( { key : 'tag', label : word('/_admin/pdf/label/tag'), value : templates.edit.tag, onChange : function( value ) { templates.edit.tag = value },
				options : tags.map( function( tag ) { return { value : tag, label : tag } } ) } ), 'tag' ) );
			form.appendChild( grid );

			const stack = el( 'ol', 'pdf-blocklist' );
			stack.id = 'pdf-templates-blocks';
			form.appendChild( el( 'h3', '', word('/_admin/pdf/label/blocks-title') ) );
			form.appendChild( stack );
			form.appendChild( templates.adder( stack ) );
			form.appendChild( templates.previewBox() );

			templates.line = statusLine( form, templates.isDirty );
			form.addEventListener( 'submit', function( event ) { event.preventDefault(); templates.save( function() {} ) } );

			const bar = Nino.adminUi.actionBar( el( 'div' ) );
			bar.appendChild( templates.line.element );
			bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/preview'), function() { templates.preview() }, { className : 'nino-admin-btn--small', id : 'pdf-preview-button' } ) );
			bar.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/save'), function() { templates.save( function() {} ) }, { className : 'nino-admin-btn-primary' } ) );

			mount.appendChild( form );
			mount.appendChild( bar );
			templates.draw( stack );
			Nino.adminUi.showLevel( 'pdf-templates-edit', { focus : name.querySelector('input') } );
		},

		keyTouched : false,

		suggest : function() {

			const input = dc.getElementById('pdf-templates-edit').querySelector('[data-field="key"]');

			templates.edit.key = Nino.slug( templates.edit.name, 40 );

			if( input !== null )
				input.value = templates.edit.key;
		},

		// The select and the button that add a block of a type at the end
		adder : function( stack ) {

			const wrap = el( 'div', 'nino-admin-actions pdf-adder' );
			const pick = Nino.adminUi.selectField( { key : 'type', label : word('/_admin/pdf/label/add-type'), value : 'html', options : templates.TYPES.map( function( type ) { return { value : type, label : word('/_admin/pdf/type/'+ type) } } ) } );

			wrap.appendChild( pick );
			wrap.appendChild( Nino.adminUi.button( word('/_admin/pdf/label/add'), function() {

				if( templates.edit.blocks.length >= templates.offer.ranges.blocks ) {
					templates.line.fail( Nino.adminUi.format( word('/_admin/error/pdf_blocks_many'), templates.offer.ranges.blocks ) );
					return;
				}

				templates.edit.blocks.push( templates.fresh( pick.querySelector('select').value ) );
				templates.draw( stack );
				touched( templates.line, templates.isDirty );
			}, { className : 'nino-admin-btn--small' } ) );

			return wrap;
		},

		// The blocks, drawn from the model: one card each, with the controls that move it and the form of its type
		draw : function( stack, focus ) {

			stack.innerHTML = '';

			templates.edit.blocks.forEach( function( block, at ) {

				const card = el( 'li', 'nino-admin-card pdf-block' );
				const head = el( 'div', 'pdf-block-head' );
				const move = el( 'span', 'nino-admin-move' );

				card.setAttribute( 'data-type', block.type );
				head.appendChild( el( 'strong', '', ( at + 1 )+ '. '+ word('/_admin/pdf/type/'+ block.type) ) );

				[ [ '↑', 'up', -1 ], [ '↓', 'down', 1 ] ].forEach( function( step ) {
					const button = Nino.adminUi.button( step[0], function() { templates.move( stack, at, step[2], step[1] ) }, { className : 'nino-admin-btn--small', name : word('/_admin/pdf/label/'+ step[1]),
						disabled : at + step[2] < 0 || at + step[2] >= templates.edit.blocks.length } );
					button.setAttribute( 'data-step', step[1] );
					move.appendChild( button );
				} );
				move.appendChild( Nino.adminUi.button( '×', function() {
					templates.edit.blocks.splice( at, 1 );
					templates.draw( stack );
					touched( templates.line, templates.isDirty );
				}, { className : 'nino-admin-btn--small nino-admin-btn-danger', name : word('/_admin/pdf/label/remove') } ) );
				head.appendChild( move );
				card.appendChild( head );
				card.appendChild( templates.form( block ) );
				stack.appendChild( card );
			} );

			// The focus follows the block that was moved, on the button that moved it - or on the other where that is at the end of the stack
			if( focus !== undefined && stack.children[focus.at] ) {

				const up = stack.children[focus.at].querySelector('[data-step="up"]');
				const down = stack.children[focus.at].querySelector('[data-step="down"]');
				const first = focus.step === 'up' ? up : down;

				( first.disabled === false ? first : ( focus.step === 'up' ? down : up ) ).focus();
			}
		},

		move : function( stack, at, step, name ) {

			const to = at + step;

			if( to < 0 || to >= templates.edit.blocks.length )
				return;

			templates.edit.blocks.splice( to, 0, templates.edit.blocks.splice( at, 1 )[0] );
			templates.draw( stack, { at : to, step : name } );
			touched( templates.line, templates.isDirty );
		},

		// The form of one block, by its type
		form : function( block ) {

			const body = el( 'div', 'pdf-block-body' );
			const text = function( label, field, max ) {
				const control = Nino.adminUi.textField( { label : word('/_admin/pdf/label/'+ label), value : String( block[field] ), maxLength : max, onInput : function( value ) { block[field] = value } } );
				return control;
			};
			const flag = function( label, field ) {
				return Nino.adminUi.switchField( { key : field, label : word('/_admin/pdf/label/'+ label), checked : block[field] === true } );
			};

			if( block.type === 'pagebreak' ) {
				body.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/pagebreak') ) );
				return body;
			}

			if( block.type === 'html' ) {

				const own = area( { label : word('/_admin/pdf/label/html'), field : 'html', value : block.html, max : templates.offer.ranges.html, rows : 6, hint : Nino.adminUi.format( word('/_admin/pdf/hint/block-html'), BODY_TOKENS ),
					onInput : function( value ) { block.html = value } } );
				const options = [ { value : '', label : word('/_admin/pdf/label/own-text') } ].concat( templates.offer.library.map( function( one ) { return { value : one.key, label : one.title } } ) );
				const source = Nino.adminUi.selectField( { key : 'source', label : word('/_admin/pdf/label/source'), value : block.source, options : options, onChange : function( value ) {
					block.source = value;
					own.hidden = value !== '';
					touched( templates.line, templates.isDirty );
				} } );

				own.hidden = block.source !== '';
				body.appendChild( source );
				body.appendChild( own );
				return body;
			}

			body.appendChild( text( 'name', 'name', 40 ) );

			if( block.type === 'slot' ) {
				body.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/slot') ) );
				return body;
			}

			body.appendChild( text( 'label', 'label', templates.offer.ranges.label ) );

			if( block.type === 'input' ) {
				body.appendChild( flag( 'multiline', 'multiline' ) ).querySelector('input').addEventListener( 'change', function( event ) { block.multiline = event.target.checked === true; touched( templates.line, templates.isDirty ) } );
				body.appendChild( flag( 'required', 'required' ) ).querySelector('input').addEventListener( 'change', function( event ) { block.required = event.target.checked === true; touched( templates.line, templates.isDirty ) } );
				return body;
			}

			body.appendChild( Nino.adminUi.selectField( { key : 'party', label : word('/_admin/pdf/label/party'), value : block.party, onChange : function( value ) { block.party = value },
				options : [ 'owner', 'member' ].map( function( party ) { return { value : party, label : word('/_admin/pdf/party/'+ party) } } ) } ) );
			body.appendChild( text( 'page', 'page', 4 ) );
			body.appendChild( el( 'p', 'nino-admin-hint', word('/_admin/pdf/hint/page') ) );

			[ [ 'x', templates.offer.paper[0] ], [ 'y', templates.offer.paper[1] ], [ 'w', templates.offer.paper[0] ], [ 'h', templates.offer.paper[1] ] ].forEach( function( edge ) {
				body.appendChild( number( { label : word('/_admin/pdf/label/'+ edge[0]), field : 'blocks', value : block[edge[0]], min : 0, max : edge[1], step : 0.1, onInput : function( value ) { block[edge[0]] = value } } ) );
			} );

			body.appendChild( flag( 'line', 'line' ) ).querySelector('input').addEventListener( 'change', function( event ) { block.line = event.target.checked === true; touched( templates.line, templates.isDirty ) } );

			return body;
		},

		// The preview: a frame for the PDF and the link that downloads it
		previewBox : function() {

			const box = el( 'div', 'pdf-preview' );
			const frame = el( 'iframe', 'pdf-preview-frame' );
			const link = el( 'a', 'nino-admin-btn nino-admin-btn--small pdf-preview-link', word('/_admin/pdf/label/download') );

			box.id = 'pdf-preview';
			box.hidden = true;
			frame.setAttribute( 'title', word('/_admin/pdf/label/preview') );
			link.setAttribute( 'download', 'preview.pdf' );
			box.appendChild( link );
			box.appendChild( frame );

			return box;
		},

		// The blob address of the last preview is let go of
		revoke : function() {

			if( templates.url !== '' )
				URL.revokeObjectURL( templates.url );

			templates.url = '';
		},

		preview : function() {

			if( templates.edit === null )
				return;

			templates.line.saving();

			call( 'preview', templates.payload(), function( status, response ) {

				if( status !== 200 ) {
					templates.line.error( status, response, '/_admin/pdf/error/preview' );
					return;
				}

				const bytes = Uint8Array.from( atob( response.pdf ), function( char ) { return char.charCodeAt( 0 ) } );
				const box = dc.getElementById('pdf-preview');

				templates.revoke();
				templates.url = URL.createObjectURL( new Blob( [ bytes ], { type : 'application/pdf' } ) );
				box.querySelector('iframe').src = templates.url;
				box.querySelector('a').href = templates.url;
				box.querySelector('a').setAttribute( 'download', ( templates.edit.key !== '' ? templates.edit.key : 'preview' )+ '.pdf' );
				box.hidden = false;
				templates.line.idle( '' );
			} );
		},

		leave : function( opener ) {

			const proceed = function() {
				templates.revoke();
				templates.edit = null;
				templates.list();
				Nino.adminUi.backTo( 'pdf-templates-list', opener );
				refresh();
			};

			const registry = dirtyRegistry();

			if( registry !== null && templates.isDirty() === true )
				registry.guard( [ 'pdf-templates' ], proceed, function() {} );
			else
				proceed();
		},

		save : function( done ) {

			if( templates.edit === null ) {
				done( false );
				return;
			}

			templates.line.saving();

			call( 'templates/save', templates.payload(), function( status, response ) {

				if( status !== 200 ) {
					templates.line.error( status, response, '/_admin/pdf/error/save' );
					done( false );
					return;
				}

				templates.items = response.templates;
				templates.offer = { tags : response.tags, library : response.library, paper : response.paper, ranges : response.ranges };
				templates.edit.isNew = false;
				templates.edit.key = response.key;
				templates.saved = JSON.stringify( templates.edit );
				dc.getElementById('pdf-templates-edit').querySelector('[data-field="key"]').disabled = true;
				templates.line.saved();
				refresh();
				done( true );
			} );
		},
	};

	Nino.admin['pdf-templates'] = Nino.adminUi.panel( {
		uri		: 'pdf-templates',
		mount	: 'pdf-templates-list',
		dirty	: { isDirty : templates.isDirty, save : templates.save, discard : function() { templates.revoke(); templates.edit = null } },
		render: templates.render,
	} );

	Nino.admin['pdf-templates'].call = function( action, payload, done, extra ) { call( 'templates/'+ action, payload, done, extra ) };

} )(window,document);

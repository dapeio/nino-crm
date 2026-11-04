/**
 *	Nino										A compact filesystembased php framework
 *	Modules\Files\Admin			The feature's /_admin panel, "Files": the folders of a group
 *													and the files in them on one screen - a select of the groups,
 *													the folders (add, rename, delete, mark as the folder the members
 *													upload into), the files of the open folder (the day, who put it
 *													there, whether the members see it, a link that downloads it,
 *													move, delete) and
 *													the owner's upload, which cuts every file into chunks of the
 *													size the server gives and posts them in order through the
 *													action files/upload; a chunk the web server answers 413 makes it
 *													start the file again in halves. See Modules\Files\Admin beside this file.
 *													Draws into the one region of templates/panel.tpl with the
 *													workbench's own builders; every word is a fill, every
 *													server-sent value goes in as text, and every request goes
 *													through the panel's call().
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The list is asked for when the panel is first shown, for the group the select is on; a change of the folders or the
	// files asks for it again. What is typed into the form for a new folder stays through that
	Nino.admin.files = Nino.adminUi.panel( {
		uri		: 'files',
		mount	: 'files-app',
		payload	: function() { return { group : Nino.admin.files._group } },
		dirty	: {
			isDirty : function() { return Nino.admin.files._dirty() },
			save		: function( done ) { Nino.admin.files._saveOpen( done ) },
			discard : function() { Nino.admin.files._discard() },
		},
		render	: function( data ) {

			const files = Nino.admin.files;

			files._take( data );
			files._render();
		},
	} );

	Object.assign( Nino.admin.files, {

		// What files/list answered: the groups, the group the screen is on, its folders and their files, the limits and the types
		_data		: { groups : [], group : '', folders : [], files : [], types : [], limits : { chunk : 0, max : 0, php : 0, quota : 0, used : 0 } },
		// The group the select is on, the folder whose files are shown, the folder whose name is being changed - '' for none
		_group		: '',
		_folder		: '',
		_editing	: '',
		// Whether an upload is under way: a second press of the button does nothing while it is
		_busy		: false,
		// The chunk an upload of this session found the web server takes, in bytes - 0 while no request was refused with 413
		_working	: 0,
		// The form for a new folder and the open form of a change, drawn with the screen and thrown away with it
		_add		: null,
		_edit		: null,

		/**
		 *	Adopt the answer of the server
		 *
		 *	@param		{Object}	data						See files/list
		 *
		 *	@return		void
		 */
		_take : function( data ) {

			const files = Nino.admin.files;

			files._data = {
				groups	: Array.isArray( data.groups ) === true ? data.groups : [],
				group		: typeof data.group === 'string' ? data.group : '',
				folders	: Array.isArray( data.folders ) === true ? data.folders : [],
				files		: Array.isArray( data.files ) === true ? data.files : [],
				types		: Array.isArray( data.types ) === true ? data.types : [],
				limits	: Object.assign( {}, files._data.limits, data.limits ),
			};

			files._group = files._data.group;

			// A folder that is gone is no folder to show, and the first is the one a group opens on
			if( files._data.folders.some( function( folder ) { return folder.id === files._folder } ) === false )
				files._folder = files._data.folders.length > 0 ? files._data.folders[0].id : '';

			if( files._data.folders.some( function( folder ) { return folder.id === files._editing } ) === false )
				files._editing = '';
		},

		/**
		 *	A sentence of the panel with its params in it
		 *
		 *	@param		{string}	key							A fill key
		 *	@param		{...*}		params
		 *
		 *	@return		{string}
		 */
		_text : function( key, ...params ) {
			return Nino.adminUi.format( Nino.content.getText( key ), ...params );
		},

		/**
		 *	A sentence with a number of files in it, in the words for one file where the number is 1 and in the others
		 *	where it is not - "1 file", "2 files". The number is the last of the params
		 *
		 *	@param		{string}	one							The fill key of the sentence for a single file
		 *	@param		{string}	many						The fill key of the sentence for any other number
		 *	@param		{number}	count
		 *	@param		{...*}		params					What the sentence takes in front of the number
		 *
		 *	@return		{string}
		 */
		_plural : function( one, many, count, ...params ) {
			return Nino.admin.files._text( count === 1 ? one : many, ...params, count );
		},

		/**
		 *	The cut of a file: the chunks a size is divided into, every one but the last exactly a chunk long. Pure
		 *
		 *	@param		{number}	size						The bytes of the file
		 *	@param		{number}	chunk						The bytes of a chunk
		 *
		 *	@return		{Array}									[ [ start, end ], ... ], end not included - none for a size or a chunk that is no whole number above 0
		 */
		_chunks : function( size, chunk ) {

			const parts = [];

			if( Number.isInteger( size ) === false || Number.isInteger( chunk ) === false || size < 1 || chunk < 1 )
				return parts;

			for( let start = 0; start < size; start += chunk )
				parts.push( [ start, Math.min( start + chunk, size ) ] );

			return parts;
		},

		/**
		 *	A size in the largest unit that leaves a number of one digit or more, with the unit and the separator of the
		 *	language of the workbench: 1.5 MB, 120 MB, 512 KB. The rounding is that of Files\Store::size()
		 *
		 *	@param		{number}	bytes
		 *
		 *	@return		{string}
		 */
		_size : function( bytes ) {

			const word = function( name ) { return Nino.content.getText('/_admin/files/unit/'+ name) };
			const separator = word('separator') || '.';

			for( const [ unit, factor ] of [ [ 'gigabyte', 1073741824 ], [ 'megabyte', 1048576 ], [ 'kilobyte', 1024 ] ] )
				if( bytes >= factor ) {

					const value = bytes / factor;
					const text = value < 10 ? ( Math.round( value * 10 ) / 10 ).toFixed( 1 ).replace( /\.0$/, '' ) : String( Math.round( value ) );

					return text.replace( '.', separator )+ ' '+ word( unit );
				}

			return bytes+ ' '+ word('byte');
		},

		/**
		 *	The day of a time of the server - 'Y-m-d\TH:i:s\Z', UTC
		 *
		 *	@param		{string}	time
		 *
		 *	@return		{string}									'' for none
		 */
		_when : function( time ) {
			return typeof time === 'string' && /^\d{4}-\d{2}-\d{2}T/.test( time ) === true ? time.slice( 0, 10 ) : '';
		},

		/**
		 *	Who put a file there: the owner, the member by name, a member who is gone
		 *
		 *	@param		{Object}	file
		 *
		 *	@return		{string}
		 */
		_who : function( file ) {

			if( file.by.kind === 'owner' )
				return Nino.content.getText('/_admin/files/label/by-owner');

			return file.by.label !== '' ? file.by.label : Nino.content.getText('/_admin/files/label/by-gone');
		},

		/**
		 *	The live line of a part of the screen: where it says what happened
		 *
		 *	@param		{string}	[saved]					The fill key of its words for a success, if it has some of its own
		 *
		 *	@return		{Object}									See Nino.adminUi.status()
		 */
		_line : function( saved ) {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ), saved === undefined ? undefined : { saved : Nino.content.getText( saved ) } );
		},

		/**
		 *	The question before something is thrown away, in the workbench's own dialog
		 *
		 *	@param		{string}		title
		 *	@param		{string}		message
		 *	@param		{Function}	onYes
		 *
		 *	@return		void
		 */
		_confirm : function( title, message, onYes ) {

			Nino.adminUi.choiceDialog( {
				title		: title,
				message	: message,
				choices	: [
					{ value : 'delete', label : Nino.content.getText('/_admin/files/label/delete'), kind : 'danger' },
					{ value : 'cancel', label : Nino.content.getText('/_admin/files/label/cancel'), kind : 'secondary' },
				],
				onChoose : function( value ) { if( value === 'delete' ) onYes() },
			} );
		},

		/**
		 *	The screen: what the server says of its limits, the select of the groups, the folders of the group, and - for
		 *	the folder that is open - its files and the form that uploads into it
		 *
		 *	@return		void
		 */
		_render : function() {

			const files = Nino.admin.files;
			const mount = files.mount();

			if( mount === null )
				return;

			// What was typed in the form for a new folder is put back into the one that is drawn now
			const typed = files._add !== null ? files._add.input.value : '';

			mount.innerHTML = '';
			files._add = null;
			files._edit = null;

			mount.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/files/hint/intro') ) );
			mount.appendChild( files._renderLimits() );

			if( files._data.groups.length === 0 ) {
				mount.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/files/hint/nogroup') ) );
				return;
			}

			mount.appendChild( files._renderGroups() );
			mount.appendChild( files._renderFolders( typed ) );

			const open = files._data.folders.find( function( folder ) { return folder.id === files._folder } );

			if( open !== undefined ) {
				mount.appendChild( files._renderFiles( open ) );
				mount.appendChild( files._renderUpload( open ) );
			}
		},

		/**
		 *	The limits an upload runs into, in sentences: what the server takes in one request, the chunks a file is cut
		 *	into, the largest file, the room of the group, and that the files are not in the daily backup
		 *
		 *	@return		{Element}
		 */
		_renderLimits : function() {

			const files = Nino.admin.files;
			const limits = files._data.limits;
			const box = Nino.adminUi.el( 'div', 'files-limits' );
			const php = limits.php > 0 ? files._size( limits.php ) : Nino.content.getText('/_admin/files/label/no-limit');

			box.appendChild( Nino.adminUi.hint( files._text( '/_admin/files/hint/limits', php, files._size( limits.chunk ), files._size( limits.max ) ) ) );

			if( limits.quota > 0 )
				box.appendChild( Nino.adminUi.hint( files._text( '/_admin/files/hint/quota', files._size( limits.used ), files._size( limits.quota ) ) ) );

			box.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/files/hint/backup') ) );

			return box;
		},

		/**
		 *	The group the screen is on, as a select over the groups
		 *
		 *	@return		{Element}
		 */
		_renderGroups : function() {

			const files = Nino.admin.files;
			const wrap = Nino.adminUi.el( 'div', 'files-groups' );

			wrap.appendChild( Nino.adminUi.selectField( {
				key			: 'files-group',
				label		: Nino.content.getText('/_admin/files/label/group'),
				options	: files._data.groups.map( function( group ) { return { value : group, label : group } } ),
				value		: files._group,
				onChange	: function( value ) {
					files._group = value;
					files._folder = '';
					files._editing = '';
					files.reload();
				},
			} ) );

			return wrap;
		},

		/**
		 *	The folders of the group, each with its buttons, and the form for a new one
		 *
		 *	@param		{string}	typed						What was typed into the form for a new folder before the screen was drawn again
		 *
		 *	@return		{Element}
		 */
		_renderFolders : function( typed ) {

			const files = Nino.admin.files;
			const box = Nino.adminUi.el( 'div', 'files-folders' );

			box.id = 'files-folders';
			box.appendChild( Nino.adminUi.el( 'h3', '', Nino.content.getText('/_admin/files/label/folders') ) );

			if( files._data.folders.length === 0 )
				box.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/files/empty/folders') ) );
			else {
				const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

				files._data.folders.forEach( function( folder ) { list.appendChild( files._renderFolder( folder ) ) } );
				box.appendChild( list );
			}

			box.appendChild( files._renderAdd( typed ) );

			return box;
		},

		/**
		 *	One folder: its name, how many files it has, whether the members upload into it, and what is done to it
		 *
		 *	@param		{Object}	folder
		 *
		 *	@return		{Element}
		 */
		_renderFolder : function( folder ) {

			const files = Nino.admin.files;
			const row = Nino.adminUi.el( 'li', 'files-folder-row' );
			const head = Nino.adminUi.el( 'div', 'files-row-head' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const line = files._line();

			row.dataset.id = folder.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', folder.name ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', files._plural( '/_admin/files/label/count-file', '/_admin/files/label/count-files', folder.count ) ) );
			head.appendChild( copy );

			if( folder.upload === true ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText('/_admin/files/state/upload') );
				badge.dataset.state = 'ok';
				head.appendChild( badge );
			}

			row.appendChild( head );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions files-row-actions' );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/files/label/open'), function() {
				files._folder = folder.id;
				files._render();
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small', disabled : files._folder === folder.id } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/files/label/rename'), function() { files._open( folder.id ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText( folder.upload === true ? '/_admin/files/label/unmark' : '/_admin/files/label/mark' ), function() {

				line.saving();

				files.call( 'folder/upload-target', { id : folder.id, on : folder.upload !== true }, function( status, response ) {

					if( status !== 200 )
						return line.error( status, response, '/_admin/files/error/target' );

					files.reload();
				} );
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/files/label/delete'), function() {

				files._confirm( Nino.content.getText('/_admin/files/title/folder'), folder.count > 0 ? files._plural( '/_admin/files/confirm/folder-file', '/_admin/files/confirm/folder-files', folder.count, folder.name ) : files._text( '/_admin/files/confirm/folder', folder.name ), function() {

					line.saving();

					files.call( 'folder/delete', { id : folder.id }, function( status, response ) {

						if( status !== 200 )
							return line.error( status, response, '/_admin/files/error/folder-delete' );

						files.reload();
					} );
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } ) );

			actions.appendChild( line.element );
			row.appendChild( actions );

			if( files._editing === folder.id )
				row.appendChild( files._renderEdit( folder ) );

			return row;
		},

		/**
		 *	Open a folder for a new name - the one that was open is closed, after a question where it holds what is not saved
		 *
		 *	@param		{string}	id
		 *
		 *	@return		void
		 */
		_open : function( id ) {

			const files = Nino.admin.files;

			if( files._editDirty() === true && wn.confirm( Nino.content.getText('/_admin/files/confirm/discard') ) === false )
				return;

			files._editing = id;
			files._render();
		},

		/**
		 *	The form of a new name for a folder
		 *
		 *	@param		{Object}	folder
		 *
		 *	@return		{Element}
		 */
		_renderEdit : function( folder ) {

			const files = Nino.admin.files;
			const form = Nino.adminUi.el( 'form', 'files-edit' );
			const field = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/files/label/name'), value : folder.name, maxLength : 80, autocomplete : 'off' } );
			const line = files._line();
			const save = Nino.adminUi.button( Nino.content.getText('/_admin/files/label/save'), null, { type : 'submit', className : 'nino-admin-btn-primary nino-admin-btn--small' } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			field.querySelector('input').name = 'name';
			form.appendChild( field );

			actions.appendChild( save );
			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/files/label/cancel'), function() {
				files._editing = '';
				files._render();
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			actions.appendChild( line.element );
			form.appendChild( actions );

			files._edit = { id : folder.id, folder : folder, input : field.querySelector('input'), save : save, line : line };

			line.bind( form, function() { return files._editDirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				files._submitEdit( function() {} );
			} );

			return form;
		},

		/**
		 *	Whether the open form of a rename holds a name the folder does not have
		 *
		 *	@return		{boolean}
		 */
		_editDirty : function() {

			const edit = Nino.admin.files._edit;

			return edit !== null && edit.input.value !== edit.folder.name;
		},

		/**
		 *	Post the new name of the open folder. Ends in done( true ) once it is saved and done( false ) on every other way
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_submitEdit : function( done ) {

			const files = Nino.admin.files;
			const edit = files._edit;

			if( edit === null || edit.save.disabled === true ) {
				done( false );
				return;
			}

			edit.save.disabled = true;
			edit.line.saving();

			files.call( 'folder/save', { id : edit.id, name : edit.input.value }, function( status, response ) {

				edit.save.disabled = false;

				if( status !== 200 ) {
					edit.line.error( status, response, '/_admin/files/error/folder-save' );
					done( false );
					return;
				}

				files._editing = '';

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				files.reload();
				done( true );
			} );
		},

		/**
		 *	The form for a new folder: a name, in the group the screen is on
		 *
		 *	@param		{string}	typed						What was typed before the screen was drawn again
		 *
		 *	@return		{Element}
		 */
		_renderAdd : function( typed ) {

			const files = Nino.admin.files;
			const form = Nino.adminUi.el( 'form', 'files-add' );
			const field = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/files/label/folder-new'), value : typed, maxLength : 80, autocomplete : 'off', id : 'files-add-name' } );
			const line = files._line();
			const submit = Nino.adminUi.button( Nino.content.getText('/_admin/files/label/add'), null, { type : 'submit', id : 'files-add-submit', className : 'nino-admin-btn-primary' } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );
			const input = field.querySelector('input');

			input.name = 'name';
			form.id = 'files-add';
			form.appendChild( field );
			actions.appendChild( submit );
			actions.appendChild( line.element );
			form.appendChild( actions );

			files._add = { form : form, input : input, submit : submit, line : line };

			line.bind( form, function() { return files._addDirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				files._submitAdd( function() {} );
			} );

			return form;
		},

		/**
		 *	@return		{boolean}									Whether anything is typed into the form for a new folder
		 */
		_addDirty : function() {

			const add = Nino.admin.files._add;

			return add !== null && add.input.value.trim() !== '';
		},

		/**
		 *	Post the new folder. Ends in done( true ) once it is there and done( false ) on every other way, a refusal before
		 *	anything is sent among them
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_submitAdd : function( done ) {

			const files = Nino.admin.files;
			const add = files._add;

			if( add === null || add.submit.disabled === true ) {
				done( false );
				return;
			}

			if( add.input.value.trim() === '' ) {
				add.line.fail( Nino.content.getText('/_admin/files/error/name') );
				add.input.focus();
				done( false );
				return;
			}

			add.submit.disabled = true;
			add.line.saving();

			files.call( 'folder/add', { group : files._group, name : add.input.value }, function( status, response ) {

				add.submit.disabled = false;

				if( status !== 200 ) {
					add.line.error( status, response, '/_admin/files/error/folder-add' );
					done( false );
					return;
				}

				add.input.value = '';
				files._folder = response.folder.id;

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				files.reload();
				done( true );
			} );
		},

		/**
		 *	The files of the open folder, one row each
		 *
		 *	@param		{Object}	folder
		 *
		 *	@return		{Element}
		 */
		_renderFiles : function( folder ) {

			const files = Nino.admin.files;
			const box = Nino.adminUi.el( 'div', 'files-files' );
			const mine = files._data.files.filter( function( file ) { return file.folder === folder.id } );

			box.id = 'files-files';
			box.appendChild( Nino.adminUi.el( 'h3', '', files._text( '/_admin/files/label/files-in', folder.name ) ) );

			if( mine.length === 0 ) {
				box.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/files/empty/files') ) );
				return box;
			}

			const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );

			mine.forEach( function( file ) { list.appendChild( files._renderFile( file ) ) } );
			box.appendChild( list );

			return box;
		},

		/**
		 *	One file: its name, its size, who put it there and the day, whether the members see it, and what is done to it
		 *
		 *	@param		{Object}	file
		 *
		 *	@return		{Element}
		 */
		_renderFile : function( file ) {

			const files = Nino.admin.files;
			const row = Nino.adminUi.el( 'li', 'files-file-row' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const line = files._line();
			const visible = Nino.adminUi.switchField( { key : 'visible', checked : file.visible === true, label : Nino.content.getText('/_admin/files/label/visible') } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions files-row-actions' );

			row.dataset.id = file.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', file.name ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', [ files._size( file.size ), files._who( file ), files._when( file.created ) ].filter( function( part ) { return part !== '' } ).join(' · ') ) );
			row.appendChild( copy );

			const toggle = visible.querySelector('input');
			let reverting = false;

			toggle.addEventListener( 'change', function() {

				// The switch puts its own word beside the knob on every change, the one that puts it back included
				if( reverting === true )
					return;

				const wanted = toggle.checked;

				line.saving();

				files.call( 'save', { id : file.id, visible : wanted }, function( status, response ) {

					if( status !== 200 ) {
						reverting = true;
						toggle.checked = wanted === false;
						toggle.dispatchEvent( new Event( 'change' ) );
						reverting = false;
						return line.error( status, response, '/_admin/files/error/save' );
					}

					file.visible = response.file.visible === true;
					line.saved();
				} );
			} );

			row.appendChild( visible );

			// The download is a link to the route the members' files are streamed by, which answers the owner's session too: a
			// tab of its own, and the browser saves what the route says to save
			const download = Nino.adminUi.el( 'a', 'nino-admin-btn-secondary nino-admin-btn--small files-download', Nino.content.getText('/_admin/files/label/download') );

			download.href = file.url+ '?download=1';
			download.target = '_blank';
			download.rel = 'noopener';
			actions.appendChild( download );

			const others = files._data.folders.filter( function( folder ) { return folder.id !== file.folder } );

			if( others.length > 0 )
				actions.appendChild( Nino.adminUi.selectField( {
					key			: 'move',
					label		: Nino.content.getText('/_admin/files/label/move'),
					options	: [ { value : '', label : '-' } ].concat( others.map( function( folder ) { return { value : folder.id, label : folder.name } } ) ),
					value		: '',
					onChange	: function( value ) {

						if( value === '' )
							return;

						line.saving();

						files.call( 'save', { id : file.id, folder : value }, function( status, response ) {

							if( status !== 200 )
								return line.error( status, response, '/_admin/files/error/save' );

							files.reload();
						} );
					},
				} ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/files/label/delete'), function() {

				files._confirm( Nino.content.getText('/_admin/files/title/file'), files._text( '/_admin/files/confirm/file', file.name ), function() {

					line.saving();

					files.call( 'delete', { id : file.id }, function( status, response ) {

						if( status !== 200 )
							return line.error( status, response, '/_admin/files/error/delete' );

						files.reload();
					} );
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } ) );

			actions.appendChild( line.element );
			row.appendChild( actions );

			return row;
		},

		/**
		 *	The owner's upload into the open folder: a file input that takes several files, whether the members see them, a
		 *	button, and the list the progress of each file is drawn in
		 *
		 *	@param		{Object}	folder
		 *
		 *	@return		{Element}
		 */
		_renderUpload : function( folder ) {

			const files = Nino.admin.files;
			const box = Nino.adminUi.el( 'fieldset', 'files-upload-box' );
			const input = Nino.adminUi.el( 'input' );
			const label = Nino.adminUi.el( 'label', 'nino-admin-field', Nino.content.getText('/_admin/files/label/choose') );
			const visible = Nino.adminUi.switchField( { key : 'visible', checked : true, label : Nino.content.getText('/_admin/files/label/upload-visible'), hint : Nino.content.getText('/_admin/files/hint/upload-visible') } );
			const line = files._line();
			const list = Nino.adminUi.el( 'ul', 'files-upload-list nino-admin-list nino-admin-list-dense' );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			box.id = 'files-upload';
			input.type = 'file';
			input.id = 'files-upload-input';
			input.multiple = true;
			input.name = 'chunk';
			list.id = 'files-upload-list';
			list.hidden = true;
			label.appendChild( input );

			const send = Nino.adminUi.button( Nino.content.getText('/_admin/files/label/upload'), function() {

				if( files._busy === true )
					return;

				const chosen = Array.from( input.files || [] );

				if( chosen.length === 0 )
					return line.fail( Nino.content.getText('/_admin/files/error/choose') );

				files._upload( folder, chosen, visible.querySelector('input').checked, list, line, send, input );
			}, { className : 'nino-admin-btn-primary', id : 'files-upload-send' } );

			box.appendChild( Nino.adminUi.el( 'legend', '', files._text( '/_admin/files/label/upload-to', folder.name ) ) );
			box.appendChild( label );
			box.appendChild( visible );
			actions.appendChild( send );
			actions.appendChild( line.element );
			box.appendChild( actions );
			box.appendChild( list );

			return box;
		},

		/**
		 *	The chunk to try after a request of the chunk's size was answered 413 (the web server takes no request so large):
		 *	half of it, not below the floor of 128 KiB. A file shorter than the chunk is sent in one piece, so it is its length
		 *	that is halved. Pure
		 *
		 *	@param		{number}	size						The bytes of the file
		 *	@param		{number}	chunk						The bytes of the chunk that was refused
		 *
		 *	@return		{number}									The smaller chunk; 0 when the chunk is at the floor already
		 */
		_halve : function( size, chunk ) {

			const sent = Math.min( size, chunk );

			return Number.isInteger( sent ) === true && sent > 131072 ? Math.max( 131072, Math.floor( sent / 2 ) ) : 0;
		},

		/**
		 *	Send the files the owner chose, one after the other and each in chunks. A file over the largest size, and an
		 *	empty one, is refused before a byte is sent. A chunk the web server answers 413 starts the file again as a new
		 *	upload in chunks of half the size (files._working keeps the size that works, for the files after it and for the
		 *	next uploads of the session); at the floor it fails. When all are done the screen asks for the list again
		 *
		 *	@param		{Object}		folder
		 *	@param		{Array}			chosen					The File objects
		 *	@param		{boolean}		visible					Whether the members see them
		 *	@param		{Element}		list						Where the progress is drawn
		 *	@param		{Object}		line						The live line of the form
		 *	@param		{Element}		button					The button of the form, which is off while this runs
		 *	@param		{Element}		input						The file input, which is emptied when this is over
		 *
		 *	@return		void
		 */
		_upload : function( folder, chosen, visible, list, line, button, input ) {

			const files = Nino.admin.files;
			const limits = files._data.limits;
			let sent = 0;

			files._busy = true;
			button.disabled = true;
			list.hidden = false;
			list.innerHTML = '';
			line.saving();

			const finish = function() {

				files._busy = false;
				button.disabled = false;
				input.value = '';

				if( sent > 0 ) {
					line.idle( files._plural( '/_admin/files/msg/uploaded-file', '/_admin/files/msg/uploaded', sent ) );
					files.reload();
				}
				else
					line.idle( '' );
			};

			// Every file has its row from the start - the ones that are not at their turn yet wait in it
			const lines = chosen.map( function( file ) {

				const row = Nino.adminUi.el( 'li', 'files-upload-row' );
				const status = Nino.adminUi.el( 'small', '', Nino.content.getText('/_admin/files/label/waiting') );
				const bar = Nino.adminUi.el( 'progress' );

				bar.max = 100;
				bar.value = 0;
				row.dataset.state = 'waiting';
				row.appendChild( Nino.adminUi.el( 'strong', '', file.name ) );
				row.appendChild( status );
				row.appendChild( bar );
				list.appendChild( row );

				return { row : row, status : status, bar : bar };
			} );

			const go = function( at ) {

				if( at === chosen.length ) {
					finish();
					return;
				}

				const file = chosen[at];
				const row = lines[at].row;
				const status = lines[at].status;
				const bar = lines[at].bar;

				const fail = function( text ) {
					row.dataset.state = 'failed';
					status.textContent = text;
					go( at + 1 );
				};

				if( file.size < 1 )
					return fail( files._text( '/_admin/files/error/empty', file.name ) );

				if( limits.max > 0 && file.size > limits.max )
					return fail( files._text( '/_admin/files/error/toolarge', file.name, files._size( limits.max ) ) );

				let parts = files._chunks( file.size, files._working > 0 ? Math.min( files._working, limits.chunk ) : limits.chunk );
				let upload = '';

				row.dataset.state = 'sending';
				status.textContent = Nino.content.getText('/_admin/files/label/sending');

				const next = function( index ) {

					files.call( 'upload', { folder : folder.id, name : file.name, size : file.size, upload : upload, index : index, count : parts.length, visible : visible }, function( answered, response ) {

						if( answered === 413 ) {

							const smaller = files._halve( file.size, parts[0][1] - parts[0][0] );

							// A refusal that names its cause (a code the panel has a sentence for) says it; one that does not is the web server's
							if( smaller === 0 )
								return fail( response !== null && typeof response === 'object' && typeof response.code === 'string' && Nino.content.getText( '/_admin/error/'+ response.code ) !== '' ? Nino.adminUi.api.errorText( answered, response, '/_admin/files/error/upload' ) : Nino.content.getText('/_admin/files/error/floor') );

							files._working = smaller;
							parts = files._chunks( file.size, smaller );
							upload = '';
							bar.value = 0;
							status.textContent = Nino.content.getText('/_admin/files/label/smaller');
							next( 0 );
							return;
						}

						if( answered !== 200 )
							return fail( Nino.adminUi.api.errorText( answered, response, '/_admin/files/error/upload' ) );

						if( response !== null && typeof response === 'object' && typeof response.upload === 'string' )
							upload = response.upload;

						bar.value = Math.floor( parts[index][1] * 100 / file.size );

						if( index + 1 < parts.length ) {
							next( index + 1 );
							return;
						}

						row.dataset.state = 'done';
						status.textContent = Nino.content.getText('/_admin/files/label/done');
						sent++;
						go( at + 1 );
					}, { chunk : file.slice( parts[index][0], parts[index][1] ) } );
				};

				next( 0 );
			};

			go( 0 );
		},

		/**
		 *	What the shell asks about unsaved input: whether the screen holds some
		 *
		 *	@return		{boolean}
		 */
		_dirty : function() {
			return Nino.admin.files._addDirty() === true || Nino.admin.files._editDirty() === true;
		},

		/**
		 *	The shell's Save: the open rename first, then the new folder - each when it holds something unsaved, one after
		 *	the other. Ends in done( true ) once all that was unsaved is saved and in done( false ) when any of it is not
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_saveOpen : function( done ) {

			const files = Nino.admin.files;
			const steps = [];

			if( files._editDirty() === true )
				steps.push( files._submitEdit );

			if( files._addDirty() === true )
				steps.push( files._submitAdd );

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
		 *	The shell's Discard: what is typed is forgotten, the rename is closed - and the screen is drawn again, so that
		 *	it shows what the server has
		 *
		 *	@return		void
		 */
		_discard : function() {

			const files = Nino.admin.files;

			if( files._add !== null )
				files._add.input.value = '';

			files._edit = null;
			files._editing = '';
			files._render();
		},
	} );

} )(window);

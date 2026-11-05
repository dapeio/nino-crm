/**
 *	Nino										A compact filesystembased php framework
 *	Modules\Review\Admin		The feature's /_admin panel, "Review": the files that have comments on one screen,
 *													by group and folder with their number of comments and of the open ones, and - for the
 *													file that is open - a player that plays it through the route of the Files feature (where
 *													the account may: otherwise the screen says so and draws none), the
 *													comments in the order of the track with their answers, and what is done with them: a
 *													click on the time of a comment takes the player there, the owner answers (with a time,
 *													or at the position of the player), marks a comment as done or opens it again, and
 *													deletes it after the question the recipe asks. See Modules\Review\Admin beside this file.
 *													Draws into the one region of templates/panel.tpl with the workbench's own builders;
 *													every word is a fill, every server-sent value goes in as text, and every request goes
 *													through the panel's call().
 *
 *	@package								Dape/Nino
 *	@author									David Perchermeier <mail@dape.io>
 *	@link										https://github.com/dapeio/nino
 */

( function(wn) {

	wn.Nino.admin = wn.Nino.admin || {};

	// The list is asked for when the panel is first shown; the comments on a file are asked for when it is opened and again
	// after every change, and only their list is drawn again - the player keeps playing, and what is typed stays
	Nino.admin.review = Nino.adminUi.panel( {
		uri		: 'review',
		mount	: 'review-app',
		dirty	: {
			isDirty : function() { return Nino.admin.review._dirty() },
			save		: function( done ) { Nino.admin.review._send( done ) },
			discard : function() { Nino.admin.review._discard() },
		},
		render	: function( data ) {

			const review = Nino.admin.review;

			review._files = Array.isArray( data.files ) === true ? data.files : [];
			review._render();
		},
	} );

	Object.assign( Nino.admin.review, {

		// What review/list answered: the files that have comments
		_files		: [],
		// The id of the file whose comments are shown, '' for the list - and what review/comments answered for it
		_open			: '',
		_view			: null,
		// The comment the form answers: { id, who }; null for a comment of its own
		_answering	: null,
		// The player of the open file, the element that holds its comments and the form, drawn with the screen and thrown away with it
		_media		: null,
		_box			: null,
		_form			: null,

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
		 *	A time of the track the way the site writes it: m:ss, the minutes as many as there are and the decimals left out.
		 *	The rule of Review::clock(). Pure
		 *
		 *	@param		{number}	value						Seconds
		 *
		 *	@return		{string}									'0:00' for anything that is no time
		 */
		_clock : function( value ) {

			const whole = Number.isFinite( value ) === true ? Math.floor( Math.max( 0, value ) ) : 0;

			return Math.floor( whole / 60 )+ ':'+ String( whole % 60 ).padStart( 2, '0' );
		},

		/**
		 *	A position of the player the way the time field is written when it takes the player's: m:ss, and the tenth of a second
		 *	after a point where there is one - the data holds one decimal, and the server reads this back. A tenth of 0 stays m:ss.
		 *	Pure
		 *
		 *	@param		{number}	value						Seconds
		 *
		 *	@return		{string}									eg. '0:02.5', '1:05'; '0:00' for anything that is no time
		 */
		_position : function( value ) {

			const tenths = Number.isFinite( value ) === true ? Math.floor( Math.max( 0, value ) * 10 ) : 0;

			return Nino.admin.review._clock( tenths / 10 )+ ( tenths % 10 === 0 ? '' : '.'+ ( tenths % 10 ) );
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
		 *	The live line of a part of the screen: where it says what happened
		 *
		 *	@return		{Object}									See Nino.adminUi.status()
		 */
		_line : function() {
			return Nino.adminUi.status( Nino.adminUi.el( 'span' ) );
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
					{ value : 'delete', label : Nino.content.getText('/_admin/review/label/delete'), kind : 'danger' },
					{ value : 'cancel', label : Nino.content.getText('/_admin/review/label/cancel'), kind : 'secondary' },
				],
				onChoose : function( value ) { if( value === 'delete' ) onYes() },
			} );
		},

		/**
		 *	Take the player of the open file to a second and play from there
		 *
		 *	@param		{number}	at							Seconds
		 *
		 *	@return		{boolean}									Whether the player was moved - not where the file cannot be played
		 */
		_seek : function( at ) {

			const media = Nino.admin.review._media;

			if( media === null || Number.isFinite( at ) === false || at < 0 )
				return false;

			media.currentTime = at;

			const played = typeof media.play === 'function' ? media.play() : null;

			if( played !== null && played !== undefined && typeof played.catch === 'function' )
				played.catch( function() {} );

			return true;
		},

		/**
		 *	The screen: the list of the files, or the comments on the one that is open
		 *
		 *	@return		void
		 */
		_render : function() {

			const review = Nino.admin.review;
			const mount = review.mount();

			if( mount === null )
				return;

			mount.innerHTML = '';
			review._media = null;
			review._box = null;
			review._form = null;

			if( review._open !== '' && review._view !== null )
				review._renderFile( mount );
			else
				review._renderList( mount );
		},

		/**
		 *	The files that have comments, a heading for each group and each folder and a row for each file
		 *
		 *	@param		{Element}	mount
		 *
		 *	@return		void
		 */
		_renderList : function( mount ) {

			const review = Nino.admin.review;

			mount.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/review/hint/intro') ) );

			if( review._files.length === 0 ) {
				mount.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/review/empty/files') ) );
				return;
			}

			let group = null;
			let folder = null;
			let list = null;

			review._files.forEach( function( file ) {

				if( group !== file.group ) {
					group = file.group;
					folder = null;
					mount.appendChild( Nino.adminUi.el( 'h3', 'review-group', group === '' ? Nino.content.getText('/_admin/review/label/nogroup') : group ) );
				}

				if( folder !== file.folder || list === null ) {
					folder = file.folder;

					if( file.folderName !== '' )
						mount.appendChild( Nino.adminUi.el( 'h4', 'review-folder', file.folderName ) );

					list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense' );
					mount.appendChild( list );
				}

				list.appendChild( review._renderFileRow( file ) );
			} );
		},

		/**
		 *	One file of the list: its name, its kind, how many comments it has and how many are open, and the button that opens it
		 *
		 *	@param		{Object}	file
		 *
		 *	@return		{Element}
		 */
		_renderFileRow : function( file ) {

			const review = Nino.admin.review;
			const row = Nino.adminUi.el( 'li', 'review-file-row' );
			const head = Nino.adminUi.el( 'div', 'review-row-head' );
			const copy = Nino.adminUi.el( 'div', 'nino-admin-list-copy' );
			const line = review._line();
			const counts = [
				Nino.content.getText( '/_admin/review/kind/'+ ( file.kind === 'video' ? 'video' : 'audio' ) ),
				review._text( file.count === 1 ? '/_admin/review/label/count-comment' : '/_admin/review/label/count-comments', file.count ),
			];

			row.dataset.id = file.id;
			copy.appendChild( Nino.adminUi.el( 'strong', '', file.name ) );
			copy.appendChild( Nino.adminUi.el( 'small', '', counts.join(' · ') ) );
			head.appendChild( copy );

			if( file.open > 0 ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', review._text( '/_admin/review/label/count-open', file.open ) );
				badge.dataset.state = 'warn';
				head.appendChild( badge );
			}

			row.appendChild( head );

			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions review-row-actions' );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/review/label/open-file'), function() {

				line.saving();

				review._load( file.id, function( ok, status, response ) {

					if( ok === false )
						line.error( status, response, '/_admin/review/error/comments' );
				} );
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( line.element );
			row.appendChild( actions );

			return row;
		},

		/**
		 *	Ask for the comments on a file and show them: the whole screen when the file is not the one that is open, the list of
		 *	the comments alone when it is
		 *
		 *	@param		{string}		id
		 *	@param		{Function}	done						Called with ok, and the status and the body of a request that failed
		 *
		 *	@return		void
		 */
		_load : function( id, done ) {

			const review = Nino.admin.review;

			review.call( 'comments', { file : id }, function( status, response ) {

				if( status !== 200 ) {
					done( false, status, response );
					return;
				}

				const same = review._open === id && review._box !== null;

				review._open = id;
				review._view = response;

				// The comment the form answers may be gone: deleted, or an answer that went with its comment
				if( review._answering !== null && review._find( review._answering.id ) === null )
					review._answer( null );

				if( same === true )
					review._fillComments();
				else {
					review._answering = null;
					review._render();
				}

				done( true, status, response );
			} );
		},

		/**
		 *	A comment or an answer of the open file by its id
		 *
		 *	@param		{string}	id
		 *
		 *	@return		{Object|null}
		 */
		_find : function( id ) {

			const view = Nino.admin.review._view;
			const all = [];

			( view === null ? [] : view.comments ).forEach( function( comment ) {
				all.push( comment );
				( comment.answers || [] ).forEach( function( answer ) { all.push( answer ) } );
			} );

			return all.find( function( comment ) { return comment.id === id } ) ?? null;
		},

		/**
		 *	Back to the list of the files, which is asked for again: the counts have moved - after a question where the form
		 *	holds what is not saved
		 *
		 *	@return		void
		 */
		_back : function() {

			const review = Nino.admin.review;

			if( review._dirty() === true && wn.confirm( Nino.content.getText('/_admin/review/confirm/discard') ) === false )
				return;

			review._open = '';
			review._view = null;
			review._answering = null;
			review.reload();
		},

		/**
		 *	The screen of the open file: the way back, its name, the player, the comments and the form
		 *
		 *	@param		{Element}	mount
		 *
		 *	@return		void
		 */
		_renderFile : function( mount ) {

			const review = Nino.admin.review;
			const file = review._view.file;

			mount.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/review/label/back'), function() { review._back() }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );
			mount.appendChild( Nino.adminUi.el( 'h3', 'review-file-title', review._text( '/_admin/review/label/comments-on', file.name ) ) );

			// The route of the Files feature answers a workbench account by the permission of the Files panel: without it the player
			// would stand there and play nothing, so the screen says so instead
			if( review._view.files !== true )
				mount.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/review/hint/files-permission') ) );
			else if( file.playable === true ) {

				const media = Nino.adminUi.el( file.kind === 'video' ? 'video' : 'audio', 'review-media' );

				media.controls = true;
				media.preload = 'metadata';
				media.src = file.url;
				mount.appendChild( media );
				review._media = media;
			}
			else
				mount.appendChild( Nino.adminUi.hint( Nino.content.getText('/_admin/review/hint/unplayable') ) );

			review._box = Nino.adminUi.el( 'div', 'review-comments-box' );
			mount.appendChild( review._box );
			review._fillComments();
			mount.appendChild( review._renderForm() );
		},

		/**
		 *	The comments on the open file in the box: drawn again from what review/comments answered last, the form and the player
		 *	untouched
		 *
		 *	@return		void
		 */
		_fillComments : function() {

			const review = Nino.admin.review;
			const box = review._box;

			if( box === null || review._view === null )
				return;

			box.innerHTML = '';

			if( review._view.comments.length === 0 ) {
				box.appendChild( Nino.adminUi.emptyState( Nino.content.getText('/_admin/review/empty/comments') ) );
				return;
			}

			const list = Nino.adminUi.el( 'ul', 'nino-admin-list nino-admin-list-dense review-comments-list' );

			review._view.comments.forEach( function( comment ) { list.appendChild( review._renderComment( comment, false ) ) } );
			box.appendChild( list );
		},

		/**
		 *	Who wrote a comment: the owner, the name of the member - their address where the account has no name -, a member who is gone
		 *
		 *	@param		{Object}	by
		 *
		 *	@return		{string}
		 */
		_who : function( by ) {

			if( by.kind === 'owner' )
				return Nino.content.getText('/_admin/review/label/owner');

			return by.label !== '' ? by.label : Nino.content.getText('/_admin/review/label/gone');
		},

		/**
		 *	One comment, or one answer: the time that takes the player there, who wrote it and when, whether it is done, the text, the
		 *	buttons - answer, done or open again, delete - and under a comment its answers
		 *
		 *	@param		{Object}		comment
		 *	@param		{boolean}		isAnswer
		 *
		 *	@return		{Element}
		 */
		_renderComment : function( comment, isAnswer ) {

			const review = Nino.admin.review;
			const row = Nino.adminUi.el( 'li', isAnswer === true ? 'review-comment-row review-answer-row' : 'review-comment-row' );
			const head = Nino.adminUi.el( 'div', 'review-comment-head' );
			const line = review._line();
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions review-row-actions' );

			row.dataset.id = comment.id;
			row.dataset.state = comment.done === true ? 'done' : 'open';

			if( comment.at !== null )
				head.appendChild( Nino.adminUi.button( review._clock( comment.at ), function() { review._seek( comment.at ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small review-time' } ) );

			head.appendChild( Nino.adminUi.el( 'strong', 'review-who', review._who( comment.by ) ) );
			head.appendChild( Nino.adminUi.el( 'small', '', review._when( comment.created ) ) );

			if( comment.by.kind === 'member' ) {
				const badge = Nino.adminUi.el( 'span', 'nino-admin-badge', Nino.content.getText( comment.done === true ? '/_admin/review/state/done' : '/_admin/review/state/open' ) );
				badge.dataset.state = comment.done === true ? 'ok' : 'warn';
				head.appendChild( badge );
			}

			row.appendChild( head );
			row.appendChild( Nino.adminUi.el( 'p', 'review-text', comment.text ) );

			actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/review/label/reply'), function() { review._answer( comment ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			// One request of a button at a time: a second click while the first is out would turn the state around again
			const doneButton = Nino.adminUi.button( Nino.content.getText( comment.done === true ? '/_admin/review/label/reopen' : '/_admin/review/label/mark-done' ), function() {

				if( doneButton.disabled === true )
					return;

				doneButton.disabled = true;
				line.saving();

				review.call( 'done', { file : review._open, id : comment.id }, function( status, response ) {

					if( status !== 200 ) {
						doneButton.disabled = false;
						return line.error( status, response, '/_admin/review/error/done' );
					}

					review._load( review._open, function() { doneButton.disabled = false } );
				} );
			}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } );

			actions.appendChild( doneButton );

			const deleteButton = Nino.adminUi.button( Nino.content.getText('/_admin/review/label/delete'), function() {

				if( deleteButton.disabled === true )
					return;

				const answers = Array.isArray( comment.answers ) === true ? comment.answers.length : 0;
				const message = answers === 0 ? Nino.content.getText('/_admin/review/confirm/comment')
					: ( answers === 1 ? Nino.content.getText('/_admin/review/confirm/comment-answer') : review._text( '/_admin/review/confirm/comment-answers', answers ) );

				review._confirm( Nino.content.getText('/_admin/review/title/comment'), message, function() {

					deleteButton.disabled = true;
					line.saving();

					review.call( 'delete', { file : review._open, id : comment.id }, function( status, response ) {

						if( status !== 200 ) {
							deleteButton.disabled = false;
							return line.error( status, response, '/_admin/review/error/delete' );
						}

						review._load( review._open, function() { deleteButton.disabled = false } );
					} );
				} );
			}, { className : 'nino-admin-btn-danger nino-admin-btn--small' } );

			actions.appendChild( deleteButton );

			actions.appendChild( line.element );
			row.appendChild( actions );

			if( isAnswer === false && Array.isArray( comment.answers ) === true && comment.answers.length > 0 ) {

				const answers = Nino.adminUi.el( 'ul', 'review-answers-list' );

				comment.answers.forEach( function( answer ) { answers.appendChild( review._renderComment( answer, true ) ) } );
				row.appendChild( answers );
			}

			return row;
		},

		/**
		 *	Prepare an answer: the form says who is answered, the comment goes to the comment's id, the focus to the text
		 *
		 *	@param		{Object|null}	comment				The comment answered; null to give the answer up
		 *
		 *	@return		void
		 */
		_answer : function( comment ) {

			const review = Nino.admin.review;
			const form = review._form;

			review._answering = comment === null ? null : { id : comment.id, who : review._who( comment.by ) };

			if( form === null )
				return;

			form.answering.hidden = review._answering === null;
			form.answeringText.textContent = review._answering === null ? '' : review._text( '/_admin/review/label/answering', review._answering.who );

			if( review._answering !== null )
				form.text.focus();
		},

		/**
		 *	The form of the owner's comment: the text, the time - typed, or taken from the player - and the button
		 *
		 *	@return		{Element}
		 */
		_renderForm : function() {

			const review = Nino.admin.review;
			const form = Nino.adminUi.el( 'form', 'review-form' );
			const answering = Nino.adminUi.el( 'p', 'review-answering' );
			const answeringText = Nino.adminUi.el( 'span' );
			const textField = Nino.adminUi.el( 'label', 'nino-admin-field' );
			const text = Nino.adminUi.el( 'textarea', 'nino-admin-input' );
			const atField = Nino.adminUi.textField( { label : Nino.content.getText('/_admin/review/label/at'), value : '', maxLength : 12, autocomplete : 'off' } );
			const at = atField.querySelector('input');
			const line = review._line();
			const notify = Nino.adminUi.switchField( { key : 'notify', checked : review._view !== null && review._view.notify === true, label : Nino.content.getText('/_admin/review/label/notify') } );
			const send = Nino.adminUi.button( Nino.content.getText('/_admin/review/label/send'), null, { type : 'submit', className : 'nino-admin-btn-primary' } );
			const actions = Nino.adminUi.el( 'div', 'nino-admin-actions' );

			text.name = 'text';
			text.rows = 4;
			text.maxLength = review._view.length;
			at.name = 'at';
			answering.hidden = true;
			answering.appendChild( answeringText );
			answering.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/review/label/cancel'), function() { review._answer( null ) }, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			textField.appendChild( Nino.adminUi.el( 'span', '', Nino.content.getText('/_admin/review/label/text') ) );
			textField.appendChild( text );

			form.appendChild( Nino.adminUi.el( 'h4', '', Nino.content.getText('/_admin/review/label/write') ) );
			form.appendChild( answering );
			form.appendChild( textField );
			form.appendChild( atField );
			form.appendChild( notify );

			if( review._media !== null )
				actions.appendChild( Nino.adminUi.button( Nino.content.getText('/_admin/review/label/now'), function() {
					at.value = review._position( review._media.currentTime );
				}, { className : 'nino-admin-btn-secondary nino-admin-btn--small' } ) );

			actions.appendChild( send );
			actions.appendChild( line.element );
			form.appendChild( actions );

			review._form = { form : form, notify : notify.querySelector('input'), answering : answering, answeringText : answeringText, text : text, at : at, send : send, line : line };

			line.bind( form, function() { return review._dirty() } );

			form.addEventListener( 'submit', function( event ) {
				event.preventDefault();
				review._send( function() {} );
			} );

			return form;
		},

		/**
		 *	@return		{boolean}									Whether the form holds a comment that is not sent
		 */
		_dirty : function() {

			const form = Nino.admin.review._form;

			return form !== null && form.text.value.trim() !== '';
		},

		/**
		 *	Post the comment. Ends in done( true ) once it is stored and done( false ) on every other way, a refusal before anything
		 *	is sent among them
		 *
		 *	@param		{Function}	done
		 *
		 *	@return		void
		 */
		_send : function( done ) {

			const review = Nino.admin.review;
			const form = review._form;

			if( form === null || form.send.disabled === true ) {
				done( false );
				return;
			}

			if( form.text.value.trim() === '' ) {
				form.line.fail( Nino.content.getText('/_admin/review/error/empty') );
				form.text.focus();
				done( false );
				return;
			}

			form.send.disabled = true;
			form.line.saving();

			review.call( 'answer', { file : review._open, text : form.text.value, at : form.at.value, parent : review._answering === null ? '' : review._answering.id, notify : form.notify.checked }, function( status, response ) {

				form.send.disabled = false;

				if( status !== 200 ) {
					form.line.error( status, response, '/_admin/review/error/answer' );
					done( false );
					return;
				}

				form.text.value = '';
				form.at.value = '';
				form.notify.checked = review._view !== null && review._view.notify === true;
				form.notify.dispatchEvent( new Event( 'change' ) );
				review._answer( null );

				if( typeof Nino.admin.dirty === 'object' )
					Nino.admin.dirty.refresh();

				form.line.saved();
				review._load( review._open, function() {} );
				done( true );
			} );
		},

		/**
		 *	The shell's Discard: what is typed is forgotten and the answer given up
		 *
		 *	@return		void
		 */
		_discard : function() {

			const review = Nino.admin.review;

			if( review._form !== null ) {
				review._form.text.value = '';
				review._form.at.value = '';
			}

			review._answer( null );
		},
	} );

	// Showing the panel again is a new screen: the box of the answer is back at the setting
	( function() {

		const shown = Nino.admin.review.showCurrent;

		Nino.admin.review.showCurrent = function() {

			const review = Nino.admin.review;

			if( review._form !== null && review._view !== null ) {
				review._form.notify.checked = review._view.notify === true;
				review._form.notify.dispatchEvent( new Event( 'change' ) );
			}

			shown();
		};
	} )();

} )(window);

/**
 *	Nino
 *	files-js-smoke.js	What the two scripts of the Files feature do. The panel's
 *											(admin.js) over the kernel's own Nino.admin.js: it asks for
 *											files/list when the pane is first shown and draws the answer
 *											with textContent only - the groups, the folders of the group,
 *											the files of the open folder -, the form for a new folder posts
 *											files/folder/add and says what came of it in the sentence of
 *											the code, a folder is renamed, marked as the one the members
 *											upload into, or deleted after the question the recipe asks, the
 *											visible switch of a file posts files/save, a file is moved,
 *											deleted, and downloaded by a link to the route of the members,
 *											and an upload cuts every file into the
 *											chunks the server names and posts them in order with the right
 *											fields - a refused chunk shows the sentence of its code, a file
 *											that is too large is refused before a byte is sent. The site's
 *											(files.js): the cut of a size into chunks and the progress, which
 *											are pure, and the form it takes over - the rows of its list, the
 *											requests it makes, the word of each refusal, the reload when
 *											every file is in. Only Nino itself is a stand-in: the requests
 *											are kept for the test to answer, and the words are the ones of
 *											the text files, in both interface languages.
 *
 *											A pin names the words it expects by their keys and not by what they
 *											say: w.said( key, ...params ) is the fill with its params put in as
 *											the panel puts them in, so that rewording a sentence breaks nothing and
 *											a screen that shows the wrong one - the plural where the singular is
 *											meant - does. The names and the days of the fixtures are literal.
 *
 *											The helpers - check(), done(), fills(), load(), findAll(), fire(),
 *											element() and documentOf() - are those of tests/harness.js of the
 *											checkout, three levels up or the one NINO_ROOT names.
 *											files-smoke.php runs this where node is on the path.
 *
 *	Usage: node features/Files/tests/files-js-smoke.js
 *	       NINO_ROOT=../nino node features/Files/tests/files-js-smoke.js
 */

'use strict';

/* global require, __dirname, process */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

/**
 *	A file of the feature the way load() and fills() want it: from the root of the checkout, which the feature sits outside
 *	of when it is tested from the catalogue
 *
 *	@param		{string}	relative		A path below features/Files/
 *
 *	@return		{string}
 */
function own( relative ) {
	return path.relative( root, fs.realpathSync( path.join( __dirname, '..', relative ) ) );
}

/**
 *	A file the browser hands a script: a name, a size and a slice of it
 *
 *	@param		{string}	name
 *	@param		{number}	size
 *
 *	@return		{Object}
 */
function stood( name, size ) {
	return { name : name, size : size, slice : function( from, to ) { return { from : from, to : to, size : to - from } } };
}


// --- The panel -----------------------------------------------------------------

/**
 *	The panel's script in a page of its own, in one interface language: the kernel's Nino.admin.js and the feature's
 *	admin.js, over a Nino that keeps the requests and the words of the text files. The pane has the region of
 *	templates/panel.tpl
 *
 *	@param		{string}	locale		en_US or de_DE
 *
 *	@return		{Object}
 */
function world( locale ) {

	const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( own( 'text/'+ locale+ '.php' ) ) );
	const missing = [];
	const requests = [];
	const registered = [];
	const asked = [];
	let agree = true;

	const mount = element('div');
	mount.id = 'files-app';

	const page = documentOf( mount );
	const make = page.createElement;

	page.createElement = function( tag ) {
		const el = make( tag );
		Object.defineProperty( el, 'name', { get : function() { return el.attributes.name ?? '' }, set : function( value ) { el.setAttribute( 'name', value ) } } );
		return el;
	};

	const Nino = {
		content : { getText : function( key ) {
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		admin : { dirty : { register : function( name, entry ) { registered.push( { name : name, entry : entry } ) }, refresh : function() {} } },
	};

	const window = {
		Nino : Nino,
		confirm : function( text ) { asked.push( text ); return agree },
	};

	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	load( own( 'assets/admin.js' ), context, 'admin.js' );

	const find = function( root, predicate ) { return findAll( root ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin.files,
		mount : mount,
		requests : requests,
		registered : registered,
		missing : missing,
		asked : asked,
		agree : function( yes ) { agree = yes },
		entry : function() { return registered[0].entry },
		find : find,
		all : function( root, predicate ) { return findAll( root ?? mount, predicate ) },
		rows : function( cls ) { return findAll( mount, function( el ) { return el.tagName === 'LI' && String( el.className ).split(' ').indexOf( cls ) !== -1 } ) },
		buttons : function( root ) { return findAll( root, function( el ) { return el.tagName === 'BUTTON' } ) },
		button : function( root, text ) { return find( root, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		input : function( root, name ) { return find( root, function( el ) { return el.tagName === 'INPUT' && el.name === name } ) },
		word : function( key ) { return words[key] },
		// A word of the panel by its name below /_admin/files/label/: what a button is looked up by
		label : function( name ) { return words['/_admin/files/label/'+ name] },
		// Whether the text files have a fill: a pin on a word that is not there says no
		has : function( key ) { return typeof words[key] === 'string' && words[key] !== '' },
		// The fill with its params put in as the panel puts them in (Nino.adminUi.format) - '' for a key the text files lack, which no pin takes for the sentence
		said : function( key, ...params ) { return words[key] === undefined ? '' : Nino.adminUi.format( words[key], ...params ) },
		// What a sentence says before its first param
		lead : function( key ) { return ( words[key] ?? '' ).split('%s')[0] },
		last : function() { return requests[requests.length - 1] },

		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
	};
}

const LIMITS = { chunk : 1048576, max : 5242880, php : 2097152, quota : 0, used : 6000 };

/** The address the list gives a file: below the directory of the project, as Files::url() writes it */
function at( id ) {
	return '/club/.files/'+ id;
}
const ROOM = { id : 'aaaaaaaaaaaa', group : 'staff', name : 'Room', upload : true, created : '2026-01-02T03:04:05Z', count : 2 };
const BETA = { id : 'bbbbbbbbbbbb', group : 'staff', name : '<img src=x onerror=alert(1)>', upload : false, created : '2026-01-03T03:04:05Z', count : 0 };
const TRACK = { id : 'cccccccccccc', url : at('cccccccccccc'), folder : 'aaaaaaaaaaaa', name : 'Track.mp3', ext : 'mp3', size : 2500000, mime : 'audio/mpeg', sha256 : 'x', by : { kind : 'owner', id : '', label : '' }, visible : true, created : '2026-11-02T10:15:30Z' };
const HIDDEN = { id : 'dddddddddddd', url : at('dddddddddddd'), folder : 'aaaaaaaaaaaa', name : '<b>Hidden</b>.mp3', ext : 'mp3', size : 1536, mime : 'audio/mpeg', sha256 : 'x', by : { kind : 'member', id : 'eeeeeeeeeeee', label : 'Ada Lovelace' }, visible : false, created : '2026-11-03T10:15:30Z' };
const LIST = { groups : [ 'guests', 'staff' ], group : 'staff', folders : [ ROOM, BETA ], files : [ TRACK, HIDDEN ], types : [ 'mp3', 'txt' ], limits : LIMITS };

/** The panel shown and its list answered: the screen is drawn */
function drawn( locale, data ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, data ?? LIST );
	return w;
}

/** What a row says in its text */
function copyOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } );
}

/** A size as the panel writes it, put together from the fills of the language: the whole part, the decimal if there is one - after the sign of the language - and the unit's word */
function sized( w, whole, tenth, unit ) {
	return whole+ ( tenth === '' ? '' : w.word('/_admin/files/unit/separator')+ tenth )+ ' '+ w.word('/_admin/files/unit/'+ unit);
}

/** The line of a count of files: the sentence for a single file where the count is 1 and the other where it is not, with what stands before the number and the number in it */
function counted( w, one, many, count, ...before ) {
	return w.said( count === 1 ? one : many, ...before, count );
}

/** Whether two keys are two words: both are in the fills, and for a single file they say it otherwise - the singular is not the plural with a 1 in it */
function twoWords( w, one, many, ...before ) {
	return w.has( one ) === true && w.has( many ) === true && w.said( one, ...before, 1 ) !== w.said( many, ...before, 1 );
}

/** The keys of the count of files of a folder, of the question before it is deleted and of the line after an upload: for one file, for another number */
const COUNT = [ '/_admin/files/label/count-file', '/_admin/files/label/count-files' ];
const QUESTION = [ '/_admin/files/confirm/folder-file', '/_admin/files/confirm/folder-files' ];
const UPLOADED = [ '/_admin/files/msg/uploaded-file', '/_admin/files/msg/uploaded' ];


console.log( 'Nino.admin.files - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown, and the shell has no entry yet', w.requests.length === 0 && w.registered.length === 0 );

	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for files/list, once however often the shell shows it while the answer is out, for no group yet', w.requests.length === 1 && w.requests[0].action === 'files/list' && JSON.stringify( w.requests[0].payload ) === '{"group":""}' );
	check( '...and it registers its entry under its uri, which holds nothing unsaved while nothing is drawn', w.registered.length === 1 && w.registered[0].name === 'files' && w.entry().isDirty() === false );
	check( 'the namespace is the nav uri of the panel', w.panel.uri === 'files' && typeof w.panel.showCurrent === 'function' );

	w.answer( 200, LIST );
	check( 'the screen is drawn into the one region of the template: the hints, the select of the groups, the folders, the files of the open folder and the form that uploads', w.mount.children.length > 4 );
	check( '...one row for each folder in the order the server gave them, each marked with its id', w.rows('files-folder-row').map( function( row ) { return row.dataset.id } ).join() === [ ROOM.id, BETA.id ].join() );
	check( '...and the files of the folder that is open - the first - one row each, in the order of the answer', w.rows('files-file-row').map( function( row ) { return row.dataset.id } ).join() === [ TRACK.id, HIDDEN.id ].join() );

	w.panel.showCurrent();
	check( 'showing the pane again asks nothing: the screen is the answer of the first showing', w.requests.length === 1 );
}

console.log( '\nNino.admin.files - what the rows say\n' );

{
	const w = drawn('en_US');
	const folders = w.rows('files-folder-row');
	const files = w.rows('files-file-row');

	check( 'a folder says its name and how many files it has, and the folder the members upload into says so in a badge', copyOf( w, folders[0] ).join('|') === [ ROOM.name, counted( w, ...COUNT, ROOM.count ) ].join('|') && w.has('/_admin/files/state/upload') === true
		&& w.find( folders[0], function( el ) { return el.classList.contains('nino-admin-badge') } ).textContent === w.word('/_admin/files/state/upload') && w.find( folders[1], function( el ) { return el.classList.contains('nino-admin-badge') } ) === null );
	check( 'what the server sends is text and nothing else: a name that is markup is a string in a strong, and no element comes of it', copyOf( w, folders[1] )[0] === BETA.name && copyOf( w, files[1] )[0] === HIDDEN.name
		&& w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'B' || el.tagName === 'SCRIPT' } ).length === 0 );
	check( 'a file says its size in the unit of the language, who put it there and the day', w.has('/_admin/files/label/by-owner') === true
		&& copyOf( w, files[0] ).join('|') === TRACK.name+ '|'+ [ sized( w, '2', '4', 'megabyte' ), w.word('/_admin/files/label/by-owner'), '2026-11-02' ].join(' · ')
		&& copyOf( w, files[1] ).join('|') === HIDDEN.name+ '|'+ [ sized( w, '1', '5', 'kilobyte' ), HIDDEN.by.label, '2026-11-03' ].join(' · ') );

	const switches = files.map( function( row ) { return w.find( row, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } ) } );
	check( '...the switch says whether the members see it', switches[0].checked === true && switches[1].checked === false );

	const limits = w.all( w.mount, function( el ) { return el.className === 'nino-admin-hint' } ).map( function( el ) { return el.textContent } );
	check( 'the limits are told in sentences: what the server takes in a request, the chunks, the largest file - and that the bytes are not in the daily backup', w.has('/_admin/files/hint/limits') === true && w.has('/_admin/files/hint/backup') === true
		&& limits.some( function( text ) { return text === w.said( '/_admin/files/hint/limits', sized( w, '2', '', 'megabyte' ), sized( w, '1', '', 'megabyte' ), sized( w, '5', '', 'megabyte' ) ) } )
		&& limits.some( function( text ) { return text === w.word('/_admin/files/hint/backup') } ) );
	check( '...and the quota only where there is one', w.lead('/_admin/files/hint/quota') !== '' && limits.some( function( text ) { return text.indexOf( w.lead('/_admin/files/hint/quota') ) === 0 } ) === false );

	const quota = drawn( 'en_US', Object.assign( {}, LIST, { limits : Object.assign( {}, LIMITS, { quota : 10485760, used : 3145728 } ) } ) );
	check( '...with a quota the group says how much it uses of it', quota.has('/_admin/files/hint/quota') === true
		&& quota.all( quota.mount, function( el ) { return el.className === 'nino-admin-hint' && el.textContent === quota.said( '/_admin/files/hint/quota', sized( quota, '3', '', 'megabyte' ), sized( quota, '10', '', 'megabyte' ) ) } ).length === 1 );
	const open = drawn( 'en_US', Object.assign( {}, LIST, { limits : Object.assign( {}, LIMITS, { php : 0 } ) } ) );
	check( '...and a server with no limit of a request says so, in the word for it where the size of a request would stand', open.has('/_admin/files/label/no-limit') === true
		&& open.all( open.mount, function( el ) { return el.className === 'nino-admin-hint' && el.textContent === open.said( '/_admin/files/hint/limits', open.word('/_admin/files/label/no-limit'), sized( open, '1', '', 'megabyte' ), sized( open, '5', '', 'megabyte' ) ) } ).length === 1 );
}

{
	const w = drawn('en_US', { groups : [], group : '', folders : [], files : [], types : [], limits : LIMITS } );

	check( 'a project whose members have no group says so, with no select, no folder and no form', w.rows('files-folder-row').length === 0 && w.find( w.mount, function( el ) { return el.tagName === 'SELECT' } ) === null
		&& w.find( w.mount, function( el ) { return el.tagName === 'FORM' } ) === null && w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/files/hint/nogroup') } ).length === 1 );
}

{
	const w = drawn( 'en_US', Object.assign( {}, LIST, { folders : [], files : [] } ) );

	check( 'a group without a folder says so and offers the form for a new one - and no form to upload', w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/files/empty/folders') } ).length === 1
		&& w.find( w.mount, function( el ) { return el.id === 'files-add-name' } ) !== null && w.find( w.mount, function( el ) { return el.id === 'files-upload' } ) === null );
}

{
	// A member who has left: the file says so, in the words of the panel
	const gone = Object.assign( {}, HIDDEN, { id : 'eeeeeeeeeeee', url : at('eeeeeeeeeeee'), by : { kind : 'member', id : 'ffffffffffff', label : '' } } );
	const w = drawn( 'en_US', Object.assign( {}, LIST, { files : [ TRACK, gone ] } ) );

	check( 'a file of a member who has left says so instead of a name', w.has('/_admin/files/label/by-gone') === true && copyOf( w, w.rows('files-file-row')[1] )[1] === [ sized( w, '1', '5', 'kilobyte' ), w.word('/_admin/files/label/by-gone'), '2026-11-03' ].join(' · ') );
}

{
	// The files shown are those of the folder that is open; opening another shows its files, and a folder without a file says so
	const GAMMA = Object.assign( {}, ROOM, { id : 'gggggggggggg', name : 'Gamma', upload : false, count : 0 } );
	const OTHER = Object.assign( {}, TRACK, { id : 'hhhhhhhhhhhh', url : at('hhhhhhhhhhhh'), folder : BETA.id, name : 'Other.mp3' } );
	const w = drawn( 'en_US', Object.assign( {}, LIST, { folders : [ ROOM, BETA, GAMMA ], files : [ TRACK, HIDDEN, OTHER ] } ) );

	check( 'the files of the folder that is open are the ones shown - not those of the other folders', w.rows('files-file-row').map( function( row ) { return row.dataset.id } ).join() === [ TRACK.id, HIDDEN.id ].join() );

	w.button( w.rows('files-folder-row')[1], w.label('open') ).click();
	check( 'a folder is opened by its button: its files are shown, and the form that uploads is for it', w.rows('files-file-row').map( function( row ) { return row.dataset.id } ).join() === OTHER.id
		&& w.all( w.mount, function( el ) { return el.tagName === 'LEGEND' } )[0].textContent === w.said( '/_admin/files/label/upload-to', BETA.name ) && w.has('/_admin/files/label/upload-to') === true && w.requests.length === 1 );

	w.button( w.rows('files-folder-row')[2], w.label('open') ).click();
	check( '...a folder without a file says so', w.rows('files-file-row').length === 0 && w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/files/empty/files') } ).length === 1 );
}

{
	// What is typed in the form for a new folder is still there when the screen is drawn again
	const w = drawn('en_US');

	w.input( w.find( w.mount, function( el ) { return el.id === 'files-add' } ), 'name' ).value = 'Half a name';
	w.button( w.rows('files-folder-row')[1], w.label('mark') ).click();
	w.answer( 200, { folder : BETA } );
	w.answer( 200, LIST );
	check( 'what was typed in the form for a new folder is in the form when the screen is drawn again', w.input( w.find( w.mount, function( el ) { return el.id === 'files-add' } ), 'name' ).value === 'Half a name' );
}

{
	// A rename that is refused says why, in the form; a rename of a folder that is gone does not come back with it
	const w = drawn('en_US');

	w.button( w.rows('files-folder-row')[0], w.label('rename') ).click();
	const edit = w.find( w.mount, function( el ) { return el.className === 'files-edit' } );
	w.input( edit, 'name' ).value = 'x'.repeat( 90 );
	fire( edit, 'submit' );
	w.answer( 400, { error : 'x', code : 'files_long', params : [ 80 ], field : 'name' } );
	check( 'a rename that is refused says why in its form, which stays open with what was typed', w.has('/_admin/error/files_long') === true && w.all( edit, function( el ) { return el.textContent === w.said( '/_admin/error/files_long', 80 ) } ).length > 0 && w.input( edit, 'name' ).value === 'x'.repeat( 90 ) );

	w.panel.reload();
	w.answer( 200, Object.assign( {}, LIST, { folders : [ BETA ], files : [] } ) );
	w.panel.reload();
	w.answer( 200, LIST );
	check( 'a folder that was being renamed and is gone from the list is not being renamed when it is back', w.all( w.mount, function( el ) { return el.className === 'files-edit' } ).length === 0 );
}

{
	// One file is "1 file", not "1 files": the count of a folder, the question before it is deleted and the line after an upload. Each says the
	// sentence of the key for one file where the count is 1 and of the key for several where it is 0 or 2 - the count in it - in both languages
	const SOLO = Object.assign( {}, ROOM, { id : 'iiiiiiiiiiii', name : 'Solo', upload : false, count : 1 } );
	const NONE = Object.assign( {}, ROOM, { id : 'jjjjjjjjjjjj', name : 'Nothing', upload : false, count : 0 } );
	const data = Object.assign( {}, LIST, { folders : [ ROOM, SOLO, NONE ], files : [] } );
	const counts = function( w ) { return w.rows('files-folder-row').map( function( row ) { return copyOf( w, row )[1] } ) };
	const carried = function( lines, wanted ) { return lines.every( function( line, at ) { return line.indexOf( String( wanted[at] ) ) !== -1 } ) };

	[ 'en_US', 'de_DE' ].forEach( function( locale ) {

		const w = drawn( locale, data );
		const shown = counts( w );
		const numbers = [ ROOM.count, SOLO.count, NONE.count ];

		check( 'a folder tells how many files it has in the sentence of the key for one file where it has one and of the key for several where it has two or none, the count in it - '+ locale,
			twoWords( w, ...COUNT ) === true && shown.join('|') === numbers.map( function( count ) { return counted( w, ...COUNT, count ) } ).join('|') && carried( shown, numbers ) === true );

		const row = function( at ) { return w.rows('files-folder-row')[at] };

		w.button( row(1), w.label('delete') ).click();
		w.button( row(0), w.label('delete') ).click();
		w.button( row(2), w.label('delete') ).click();

		check( 'the question before a folder is deleted is the sentence of the key for one file where it has one, of the key for several where it has two, and of the one that speaks of no files where it has none - the name and the count in it - '+ locale,
			twoWords( w, ...QUESTION, 'Solo' ) === true && w.has('/_admin/files/confirm/folder') === true
			&& w.asked.join('|') === [ counted( w, ...QUESTION, SOLO.count, SOLO.name ), counted( w, ...QUESTION, ROOM.count, ROOM.name ), w.said( '/_admin/files/confirm/folder', NONE.name ) ].join('|')
			&& w.asked[0].indexOf('Solo') !== -1 && w.asked[1].indexOf('Room') !== -1 && w.asked[1].indexOf('2') !== -1 && w.asked[2].indexOf('Nothing') !== -1 );
	} );

	const finished = function( locale, names ) {

		const w = drawn( locale );
		const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );

		w.find( box, function( el ) { return el.id === 'files-upload-input' } ).files = names.map( function( name ) { return stood( name, 5 ) } );
		w.find( box, function( el ) { return el.id === 'files-upload-send' } ).click();
		names.forEach( function( name ) { w.answer( 200, { id : 'ffffffffffff', name : name, size : 5 } ) } );

		return { w : w, lines : w.all( box, function( el ) { return el.tagName === 'SPAN' } ).map( function( el ) { return el.textContent } ) };
	};

	[ 'en_US', 'de_DE' ].forEach( function( locale ) {

		const one = finished( locale, [ 'One.mp3' ] );
		const two = finished( locale, [ 'A.mp3', 'B.mp3' ] );

		check( 'the line after an upload is the sentence of the key for one file where one file is in and of the key for several where two are, the count in it - '+ locale,
			twoWords( one.w, ...UPLOADED ) === true && one.lines.includes( counted( one.w, ...UPLOADED, 1 ) ) === true && two.lines.includes( counted( two.w, ...UPLOADED, 2 ) ) === true && counted( two.w, ...UPLOADED, 2 ).indexOf('2') !== -1 );
	} );
}

console.log( '\nNino.admin.files - the pure parts\n' );

{
	const w = world('en_US');
	const files = w.panel;

	check( 'the cut of a size: the right count, and the bounds - every chunk but the last exactly a chunk long, the last what is left', JSON.stringify( files._chunks( 2500000, 1048576 ) ) === '[[0,1048576],[1048576,2097152],[2097152,2500000]]'
		&& files._chunks( 1048576, 1048576 ).length === 1 && files._chunks( 1048577, 1048576 ).length === 2 && JSON.stringify( files._chunks( 1, 1048576 ) ) === '[[0,1]]' );
	check( '...and none for a size or a chunk that is no whole number above nothing', files._chunks( 0, 5 ).length === 0 && files._chunks( 5, 0 ).length === 0 && files._chunks( -5, 3 ).length === 0 && files._chunks( 5.5, 3 ).length === 0 && files._chunks( 5, '3' ).length === 0 );

	const sizes = [ 0, 1023, 1536, 1024, 3000, 10240, 500 * 1048576, 5 * 1073741824 + 1, 2500000, 12900000 ].map( function( bytes ) { return files._size( bytes ) } );
	check( 'a size in the largest unit that leaves a number of one digit or more, with one decimal under ten and none above', sizes.join('|') === [ sized( w, '0', '', 'byte' ), sized( w, '1023', '', 'byte' ), sized( w, '1', '5', 'kilobyte' ), sized( w, '1', '', 'kilobyte' ), sized( w, '2', '9', 'kilobyte' ),
		sized( w, '10', '', 'kilobyte' ), sized( w, '500', '', 'megabyte' ), sized( w, '5', '', 'gigabyte' ), sized( w, '2', '4', 'megabyte' ), sized( w, '12', '', 'megabyte' ) ].join('|') );

	const wde = world('de_DE');
	const german = wde.panel;
	check( '...with the sign of the language between the whole part and the decimal - the language\'s own word, which is not the other one\'s', wde.word('/_admin/files/unit/separator') !== w.word('/_admin/files/unit/separator') && german._size( 1536 ) === sized( wde, '1', '5', 'kilobyte' )
		&& german._size( 2500000 ) === sized( wde, '2', '4', 'megabyte' ) && german._size( 500 * 1048576 ) === sized( wde, '500', '', 'megabyte' ) );
	check( 'a time of the server is its day', files._when( '2026-11-02T10:15:30Z' ) === '2026-11-02' && files._when( null ) === '' && files._when( 'yesterday' ) === '' );
}

console.log( '\nNino.admin.files - the group and the folders\n' );

{
	const w = drawn('en_US');
	const select = w.find( w.mount, function( el ) { return el.tagName === 'SELECT' && el.dataset.key === 'files-group' } );
	const options = w.all( select, function( el ) { return el.tagName === 'OPTION' } ).map( function( option ) { return option.value } );

	check( 'the select offers the groups and stands on the one the server answered', options.join() === 'guests,staff' && select.value === 'staff' );

	select.value = 'guests';
	fire( select, 'change' );
	check( 'a group asks for the list again, for that group - and the folder that was open is not carried over', w.requests.length === 2 && w.last().action === 'files/list' && JSON.stringify( w.last().payload ) === '{"group":"guests"}' );
	w.answer( 200, Object.assign( {}, LIST, { group : 'guests', folders : [ Object.assign( {}, ROOM, { id : 'ffffffffffff', group : 'guests', name : 'Lobby', count : 0 } ) ], files : [] } ) );
	check( '...and the folders of the other group are what is shown', w.rows('files-folder-row').map( function( row ) { return row.dataset.id } ).join() === 'ffffffffffff' );

	const back = w.find( w.mount, function( el ) { return el.tagName === 'SELECT' && el.dataset.key === 'files-group' } );
	back.value = 'staff';
	fire( back, 'change' );
	w.answer( 200, Object.assign( {}, LIST, { group : 'staff', folders : [], files : [] } ) );
	check( 'a group without a folder shows none of the folders of the group before it - it says there are none', w.rows('files-folder-row').length === 0 && w.rows('files-file-row').length === 0
		&& w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/files/empty/folders') } ).length === 1 );
}

{
	const w = drawn('en_US');
	const form = w.find( w.mount, function( el ) { return el.id === 'files-add' } );
	const input = w.input( form, 'name' );
	const submit = w.find( form, function( el ) { return el.id === 'files-add-submit' } );

	fire( form, 'submit' );
	check( 'a folder without a name is refused before anything is sent, with the sentence for it, and the focus goes to the field', w.requests.length === 1 && w.all( form, function( el ) { return el.textContent === w.word('/_admin/files/error/name') } ).length > 0 && input.focused === true );

	input.value = 'Concert tapes';
	check( 'what is typed in the form is unsaved input for the shell, and nothing before', w.entry().isDirty() === true );
	fire( form, 'submit' );
	check( 'the form for a new folder posts files/folder/add with the group the screen is on and the name', w.requests.length === 2 && w.last().action === 'files/folder/add' && JSON.stringify( w.last().payload ) === '{"group":"staff","name":"Concert tapes"}' && submit.disabled === true );

	w.answer( 400, { error : 'x', code : 'files_long', params : [ 80 ], field : 'name' } );
	check( 'a refusal is said in the sentence of its code, with the limit in it, and the form is given back with what was typed', w.has('/_admin/error/files_long') === true && w.all( form, function( el ) { return el.textContent === w.said( '/_admin/error/files_long', 80 ) && el.textContent.indexOf('80') !== -1 } ).length > 0
		&& submit.disabled === false && input.value === 'Concert tapes' );

	fire( form, 'submit' );
	w.answer( 200, { folder : { id : '999999999999', group : 'staff', name : 'Concert tapes', upload : false, created : '' } } );
	check( 'once it is added the form is empty, the list is asked for again for the group and the new folder is the one that opens', input.value === '' && w.last().action === 'files/list' && JSON.stringify( w.last().payload ) === '{"group":"staff"}' && w.panel._folder === '999999999999' );
}

{
	const w = drawn('en_US');
	const row = w.rows('files-folder-row')[0];

	// The screen is drawn again when the form opens: the row is looked for again
	w.button( row, w.label('rename') ).click();
	const edit = w.find( w.rows('files-folder-row')[0], function( el ) { return el.tagName === 'FORM' } );
	const input = w.input( edit, 'name' );

	check( 'rename opens a form in the row with the name that is', edit !== null && input.value === 'Room' && w.entry().isDirty() === false && w.all( w.mount, function( el ) { return el.className === 'files-edit' } ).length === 1 );
	input.value = 'Rehearsal room';
	check( '...a different name is unsaved input', w.entry().isDirty() === true );
	fire( edit, 'submit' );
	check( '...and the form posts files/folder/save with the id and the name', w.last().action === 'files/folder/save' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : ROOM.id, name : 'Rehearsal room' } ) );
	w.answer( 200, { folder : ROOM } );
	check( '...and once it is saved the list is asked for again', w.last().action === 'files/list' );
	w.answer( 200, Object.assign( {}, LIST, { folders : [ Object.assign( {}, ROOM, { name : 'Rehearsal room' } ), BETA ] } ) );
	check( '...and once it is answered the form is closed, the new name is shown and nothing is unsaved', w.all( w.mount, function( el ) { return el.className === 'files-edit' } ).length === 0 && copyOf( w, w.rows('files-folder-row')[0] )[0] === 'Rehearsal room' && w.entry().isDirty() === false );

	// A second row opened while the first holds a changed name asks before it throws that name away
	const again = drawn('en_US');
	again.button( again.rows('files-folder-row')[0], again.label('rename') ).click();
	again.input( again.find( again.mount, function( el ) { return el.className === 'files-edit' } ), 'name' ).value = 'Changed';
	again.agree( false );
	again.button( again.rows('files-folder-row')[1], again.label('rename') ).click();
	check( 'a rename opened over another one with a changed name asks first - and a "no" leaves the first one open', again.asked.length === 1 && again.asked[0] === again.word('/_admin/files/confirm/discard') && again.rows('files-folder-row')[1].children.length > 0
		&& again.all( again.mount, function( el ) { return el.className === 'files-edit' } ).length === 1 && again.input( again.find( again.rows('files-folder-row')[0], function( el ) { return el.className === 'files-edit' } ), 'name' ).value === 'Changed' );
	again.agree( true );
	again.button( again.rows('files-folder-row')[1], again.label('rename') ).click();
	check( '...a "yes" opens the other and the changed name is gone', again.all( again.rows('files-folder-row')[1], function( el ) { return el.className === 'files-edit' } ).length === 1 && again.all( again.rows('files-folder-row')[0], function( el ) { return el.className === 'files-edit' } ).length === 0 );
}

{
	const w = drawn('en_US');
	const [ room, beta ] = w.rows('files-folder-row');

	w.button( beta, w.label('mark') ).click();
	check( 'a folder that is not the upload folder is marked: files/folder/upload-target with on true', w.last().action === 'files/folder/upload-target' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : BETA.id, on : true } ) );
	w.answer( 200, { folder : BETA } );
	check( '...and once the mark is set the list is asked for again', w.last().action === 'files/list' );
	w.answer( 200, LIST );

	w.button( w.rows('files-folder-row')[0], w.label('unmark') ).click();
	check( 'the upload folder has the button that takes the mark off, which posts on false', JSON.stringify( w.last().payload ) === JSON.stringify( { id : ROOM.id, on : false } ) );
	w.answer( 500, { error : 'could not write the files' } );
	check( '...a failure that no person can cause shows the message of the server with the status in front', w.all( room, function( el ) { return el.textContent.indexOf('(500)') !== -1 } ).length > 0 || w.all( w.mount, function( el ) { return el.textContent.indexOf('(500)') !== -1 } ).length > 0 );
}

{
	const w = drawn('en_US');
	const [ room, beta ] = w.rows('files-folder-row');
	const before = w.requests.length;

	w.agree( false );
	w.button( room, w.label('delete') ).click();
	check( 'deleting a folder with files asks first, with the number of its files in the question - and a "no" sends nothing', w.asked.length === 1 && w.asked[0] === counted( w, ...QUESTION, ROOM.count, ROOM.name ) && w.asked[0].indexOf( String( ROOM.count ) ) !== -1 && w.requests.length === before );

	w.agree( true );
	w.button( beta, w.label('delete') ).click();
	check( 'a folder without a file asks as well, in shorter words, and a "yes" posts files/folder/delete with the id', w.asked[1] === w.said( '/_admin/files/confirm/folder', BETA.name ) && w.has('/_admin/files/confirm/folder') === true && w.last().action === 'files/folder/delete' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : BETA.id } ) );
	w.answer( 200, { deleted : 0 } );
	check( '...and once the folder is deleted the list is asked for again', w.last().action === 'files/list' );
}

console.log( '\nNino.admin.files - the files\n' );

{
	const w = drawn('en_US');
	const [ track, hidden ] = w.rows('files-file-row');
	const toggle = w.find( hidden, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );

	toggle.checked = true;
	fire( toggle, 'change' );
	check( 'the visible switch posts files/save with the id and the flag', w.last().action === 'files/save' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : HIDDEN.id, visible : true, notify : false } ) );
	w.answer( 200, { file : Object.assign( {}, HIDDEN, { visible : true } ) } );
	check( '...and no list is asked for again: the row is the answer', w.requests.length === 2 && w.panel._data.files[1].visible === true );

	const on = w.find( track, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	on.checked = false;
	fire( on, 'change' );
	check( 'turned off, the switch posts files/save with the id and visible false', w.last().action === 'files/save' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : TRACK.id, visible : false, notify : false } ) );
	w.answer( 403, { error : 'x', code : 'files_unknown' } );
	check( 'a switch that was refused goes back to where it was, and says why in the sentence of the code', on.checked === true && w.has('/_admin/error/files_unknown') === true && w.all( track, function( el ) { return el.textContent === w.word('/_admin/error/files_unknown') } ).length > 0 && w.requests.length === 3 );
}

{
	const w = drawn('en_US');
	const [ track ] = w.rows('files-file-row');
	const move = w.find( track, function( el ) { return el.tagName === 'SELECT' && el.dataset.key === 'move' } );
	const options = w.all( move, function( el ) { return el.tagName === 'OPTION' } ).map( function( option ) { return option.value } );

	check( 'a file can be moved to the other folders of the group - the folder it is in is not offered, and nothing is chosen at first', options.join() === ','+ BETA.id && move.value === '' );
	move.value = '';
	fire( move, 'change' );
	check( '...the choice of nothing moves nothing', w.requests.length === 1 );
	move.value = BETA.id;
	fire( move, 'change' );
	check( '...choosing one posts files/save with the id and the folder', JSON.stringify( w.last().payload ) === JSON.stringify( { id : TRACK.id, folder : BETA.id } ) );
	w.answer( 400, { error : 'x', code : 'files_target', field : 'folder' } );
	check( '...a refusal says so', w.has('/_admin/error/files_target') === true && w.all( track, function( el ) { return el.textContent === w.word('/_admin/error/files_target') } ).length > 0 );
}

{
	const w = drawn('en_US');
	const [ track ] = w.rows('files-file-row');

	w.agree( false );
	w.button( track, w.label('delete') ).click();
	check( 'deleting a file asks first, with its name - a "no" sends nothing', w.has('/_admin/files/confirm/file') === true && w.asked[0] === w.said( '/_admin/files/confirm/file', TRACK.name ) && w.requests.length === 1 );
	w.agree( true );
	w.button( track, w.label('delete') ).click();
	check( '...a "yes" posts files/delete with the id, and the list is asked for again', w.last().action === 'files/delete' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : TRACK.id } ) );
	w.answer( 200, {} );
	check( '...the list is asked for again', w.last().action === 'files/list' );
}

{
	// A file removed from the server: its row stays, with what is left of it
	const GONE = Object.assign( {}, TRACK, { id : 'ffffffffffff', url : at('ffffffffffff'), name : '<i>Gone</i>.mp3', visible : true, gone : '2026-11-04T08:09:10Z' } );

	[ 'en_US', 'de_DE' ].forEach( function( locale ) {

		const w = drawn( locale, Object.assign( {}, LIST, { files : [ TRACK, GONE ] } ) );
		const [ track, gone ] = w.rows('files-file-row');
		const kinds = function( row ) { return w.all( row, function( el ) { return el.tagName === 'A' || el.tagName === 'INPUT' || el.tagName === 'SELECT' } ).map( function( el ) { return el.tagName } ) };

		check( 'a file that was removed has a row of its own, in its place - with its size, who put it there, the day it came and the day its bytes went, in the words of the key - '+ locale,
			gone !== undefined && gone.dataset.id === GONE.id && w.has('/_admin/files/label/removed') === true
			&& copyOf( w, gone ).join('|') === GONE.name+ '|'+ [ sized( w, '2', '4', 'megabyte' ), w.word('/_admin/files/label/by-owner'), '2026-11-02', w.said( '/_admin/files/label/removed', '2026-11-04' ) ].join(' · ') );
		check( '...it has no link to download, no switch and no move - what is not there cannot be offered - and keeps the button that deletes it',
			kinds( gone ).length === 0 && w.button( gone, w.label('delete') ) !== null && w.button( gone, w.label('remove') ) === null && kinds( track ).indexOf('A') !== -1 && kinds( track ).indexOf('INPUT') !== -1 );
		check( '...and the name stays text: what is written in it is no markup', w.all( gone, function( el ) { return el.tagName === 'I' } ).length === 0 && copyOf( w, gone )[0] === GONE.name );
	} );
}

{
	const w = drawn('en_US');
	const [ track ] = w.rows('files-file-row');

	w.agree( false );
	w.button( track, w.label('remove') ).click();
	check( 'removing a file from the server asks first, with its name, in the words of its own key - a "no" sends nothing', w.has('/_admin/files/confirm/remove') === true && w.asked[0] === w.said( '/_admin/files/confirm/remove', TRACK.name ) && w.requests.length === 1 );
	w.agree( true );
	w.button( track, w.label('remove') ).click();
	check( '...a "yes" posts files/remove with the id and nothing else, and the list is asked for again', w.last().action === 'files/remove' && JSON.stringify( w.last().payload ) === JSON.stringify( { id : TRACK.id } ) );
	w.answer( 200, { file : Object.assign( {}, TRACK, { gone : '2026-11-04T08:09:10Z' } ), changed : true } );
	check( '...the list is asked for again', w.last().action === 'files/list' );

	const refused = drawn('en_US');
	refused.agree( true );
	refused.button( refused.rows('files-file-row')[0], refused.label('remove') ).click();
	refused.answer( 404, { error : 'x', code : 'files_unknown' } );
	check( 'a refusal of the remove says so in the row, with the sentence of its code, and the list is not asked for again', refused.has('/_admin/error/files_unknown') === true && refused.requests.length === 2 && refused.all( refused.rows('files-file-row')[0], function( el ) { return el.textContent === refused.word('/_admin/error/files_unknown') } ).length > 0 );
}

{
	const w = drawn('en_US');
	const [ track ] = w.rows('files-file-row');

	const link = w.find( track, function( el ) { return el.tagName === 'A' } );
	const hiddenLink = w.find( w.rows('files-file-row')[1], function( el ) { return el.tagName === 'A' } );

	check( 'a file is downloaded by a link and not by a request: an a with the address the list gave the file and ?download=1, opened in a tab of its own with noopener - there is no button for it', link !== null && link.href === TRACK.url+ '?download=1' && link.target === '_blank' && link.rel === 'noopener'
		&& w.has('/_admin/files/label/download') === true && link.textContent === w.label('download') && w.button( track, w.label('download') ) === null );
	link.click();
	check( '...each file has the link of its own, and neither a click nor the list calls an action that downloads', hiddenLink !== null && hiddenLink.href === HIDDEN.url+ '?download=1' && w.requests.length === 1 && w.requests.every( function( request ) { return request.action === 'files/list' } ) );
}

console.log( '\nNino.admin.files - the owner\'s upload\n' );

{
	const w = drawn('en_US');
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
	const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );

	check( 'the form that uploads stands under the files of the open folder: a file input that takes several files, the switch for the members, the button and a list that is hidden', box !== null && input.multiple === true && list.hidden === true
		&& w.all( box, function( el ) { return el.tagName === 'LEGEND' } )[0].textContent === w.said( '/_admin/files/label/upload-to', ROOM.name ) && w.has('/_admin/files/label/upload-to') === true );

	send.click();
	check( 'no file chosen is a sentence and no request', w.requests.length === 1 && w.all( box, function( el ) { return el.textContent === w.word('/_admin/files/error/choose') } ).length > 0 );

	input.files = [ stood( 'One.mp3', 2500000 ), stood( 'Two.txt', 5 ) ];
	input.value = 'C:\\fakepath\\One.mp3';
	send.click();
	const first = w.last();
	check( 'the first chunk of the first file is posted through files/upload with the folder, the name, the whole size, no upload id yet, the index, the count of three and whether the members see it - and the bytes of the first chunk as the file part chunk',
		first.action === 'files/upload' && JSON.stringify( first.payload ) === JSON.stringify( { folder : ROOM.id, name : 'One.mp3', size : 2500000, upload : '', index : 0, count : 3, visible : true, notify : false } ) && first.extra.chunk.from === 0 && first.extra.chunk.to === 1048576
		&& send.disabled === true && list.hidden === false );

	send.click();
	check( 'a second press while the upload runs sends nothing', w.requests.length === 2 );

	w.answer( 200, { upload : '0123456789abcdef', index : 0 } );
	check( 'the next chunk is posted when the answer of the one before has come, with the upload id it brought and the next index', JSON.stringify( w.last().payload ) === JSON.stringify( { folder : ROOM.id, name : 'One.mp3', size : 2500000, upload : '0123456789abcdef', index : 1, count : 3, visible : true, notify : false } )
		&& w.last().extra.chunk.from === 1048576 && w.last().extra.chunk.to === 2097152 );
	w.answer( 200, { upload : '0123456789abcdef', index : 1 } );
	check( '...and the third is the rest of the file', w.last().payload.index === 2 && w.last().extra.chunk.from === 2097152 && w.last().extra.chunk.to === 2500000 );

	const rows = w.all( list, function( el ) { return el.tagName === 'LI' } );
	const bar = function( row ) { return Number( w.find( row, function( el ) { return el.tagName === 'PROGRESS' } ).value ) };
	check( 'the list has a row for each file from the start: the file at its turn is on its way and shows how far it is, the other waits with nothing done', rows.length === 2 && rows[0].dataset.state === 'sending' && bar( rows[0] ) === 83
		&& rows[1].dataset.state === 'waiting' && copyOf( w, rows[1] ).join('|') === 'Two.txt|'+ w.word('/_admin/files/label/waiting') && bar( rows[1] ) === 0 && copyOf( w, rows[0] )[1] === w.word('/_admin/files/label/sending') );

	w.answer( 200, { id : 'ffffffffffff', name : 'One.mp3', size : 2500000 } );
	check( 'the last chunk of a file ends it and the next file begins at once - one chunk, since it is five bytes', w.last().payload.name === 'Two.txt' && w.last().payload.count === 1 && w.last().payload.index === 0 && w.last().payload.upload === ''
		&& w.all( list, function( el ) { return el.tagName === 'LI' } ).map( function( row ) { return row.dataset.state } ).join() === 'done,sending' && copyOf( w, w.all( list, function( el ) { return el.tagName === 'LI' } )[0] )[1] === w.word('/_admin/files/label/done') );

	w.answer( 400, { error : 'x', code : 'files_type', field : 'name' } );
	const after = w.all( list, function( el ) { return el.tagName === 'LI' } );
	check( 'a refused chunk shows the sentence of its code in the row of the file and that row is failed - the file before it stays done', after[1].dataset.state === 'failed' && copyOf( w, after[1] )[1] === w.word('/_admin/error/files_type') && after[0].dataset.state === 'done' );
	check( '...and when all are over the form is given back, the input emptied, and - since one file is in - the list is asked for again, with the number said', send.disabled === false && input.value === '' && w.last().action === 'files/list' );
}

console.log( '\nNino.admin.files - whether the members are mailed\n' );

{
	const w = drawn('en_US');
	const notify = w.find( w.mount, function( el ) { return el.id === 'files-notify' } );
	const tick = w.find( notify, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	const [ , hidden ] = w.rows('files-file-row');
	const toggle = w.find( hidden, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );

	check( 'the screen has one box for the mail to the members, a real checkbox with its label - outside the form of the upload and above the files, so that it stands for both', notify !== null && tick !== null && tick.dataset.key === 'notify'
		&& w.has('/_admin/files/label/notify') === true && w.all( notify, function( el ) { return el.textContent === w.word('/_admin/files/label/notify') } ).length > 0
		&& w.all( box, function( el ) { return el.id === 'files-notify' } ).length === 0 && w.rows('files-file-row').every( function( row ) { return w.all( row, function( el ) { return el.id === 'files-notify' } ).length === 0 } ) );
	check( '...it starts at the setting, which files/list says: off here', tick.checked === false );

	tick.checked = true;
	fire( tick, 'change' );
	toggle.checked = true;
	fire( toggle, 'change' );
	check( 'with it ticked the switch of visibility posts notify true', w.last().action === 'files/save' && w.last().payload.notify === true );
	w.answer( 200, { file : Object.assign( {}, HIDDEN, { visible : true } ) } );

	input.files = [ stood( 'Mailed.mp3', 10 ) ];
	send.click();
	check( '...and the upload posts it too', w.last().action === 'files/upload' && w.last().payload.notify === true );
	w.answer( 200, { id : 'ffffffffffff', name : 'Mailed.mp3', size : 10 } );
	w.answer( 200, LIST );

	const again = w.find( w.mount, function( el ) { return el.id === 'files-notify' } );
	check( '...and the choice stands when the list is drawn again after the upload', w.find( again, function( el ) { return el.tagName === 'INPUT' } ).checked === true );
}

{
	const w = drawn('en_US');
	const tick = w.find( w.find( w.mount, function( el ) { return el.id === 'files-notify' } ), function( el ) { return el.tagName === 'INPUT' } );

	tick.checked = true;
	fire( tick, 'change' );
	check( 'a screen that is shown again starts at the setting: the box ticked on the screen before is unticked again - and the list is not asked for again', w.panel._notify === true && ( w.panel.showCurrent(), tick.checked === false && w.panel._notify === null && w.requests.length === 1 ) );
}

{
	const w = drawn( 'en_US', Object.assign( {}, LIST, { notify : true } ) );
	const tick = w.find( w.find( w.mount, function( el ) { return el.id === 'files-notify' } ), function( el ) { return el.tagName === 'INPUT' } );
	const [ , hidden ] = w.rows('files-file-row');
	const toggle = w.find( hidden, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );

	check( 'where the setting is on the box starts ticked and the switch posts notify true without it being touched', tick.checked === true );
	toggle.checked = true;
	fire( toggle, 'change' );
	check( '...and the switch posts notify true', w.last().payload.notify === true );
	w.answer( 200, { file : Object.assign( {}, HIDDEN, { visible : true } ) } );
	tick.checked = false;
	fire( tick, 'change' );
	toggle.checked = false;
	fire( toggle, 'change' );
	check( '...unticked, the next switch posts notify false', w.last().payload.notify === false );
}

{
	const w = drawn('en_US');
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
	const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );
	const before = w.requests.length;

	input.files = [ stood( 'Huge.mp3', LIMITS.max + 1 ), stood( 'Empty.mp3', 0 ) ];
	send.click();
	const rows = w.all( list, function( el ) { return el.tagName === 'LI' } );

	check( 'a file over the largest size and an empty one are refused before a byte is sent, each in its own row with its sentence', w.requests.length === before && rows.length === 2 && rows.every( function( row ) { return row.dataset.state === 'failed' } )
		&& w.has('/_admin/files/error/toolarge') === true && w.has('/_admin/files/error/empty') === true
		&& copyOf( w, rows[0] )[1] === w.said( '/_admin/files/error/toolarge', 'Huge.mp3', sized( w, '5', '', 'megabyte' ) ) && copyOf( w, rows[1] )[1] === w.said( '/_admin/files/error/empty', 'Empty.mp3' ) && send.disabled === false && w.requests.length === before );

	const hiddenFor = w.find( box, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	hiddenFor.checked = false;
	input.files = [ stood( 'Quiet.mp3', 10 ) ];
	send.click();
	check( 'with the switch off the upload says visible false', w.last().payload.visible === false );
	w.answer( 200, { id : 'ffffffffffff', name : 'Quiet.mp3', size : 10 } );
	check( 'a file of one chunk is one request, and its end asks for the list again', w.last().action === 'files/list' );
}

{
	const w = drawn('en_US');
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
	const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );

	input.files = [ stood( '<img src=x onerror=alert(1)>.mp3', 10 ) ];
	send.click();
	w.answer( 413, { error : 'x', code : 'upload_too_large', params : [ 2 ] } );
	const rows = w.all( list, function( el ) { return el.tagName === 'LI' } );
	check( 'the name of a file is text in its row, and the shell\'s own code for a part that php refused is said in its sentence', copyOf( w, rows[0] )[0] === '<img src=x onerror=alert(1)>.mp3' && w.has('/_admin/error/upload_too_large') === true && copyOf( w, rows[0] )[1] === w.said( '/_admin/error/upload_too_large', 2 )
		&& w.all( list, function( el ) { return el.tagName === 'IMG' } ).length === 0 && send.disabled === false );
}

console.log( '\nNino.admin.files - unsaved input, and both languages\n' );

{
	const w = drawn('en_US');
	const form = w.find( w.mount, function( el ) { return el.id === 'files-add' } );
	const input = w.input( form, 'name' );

	check( 'a screen that holds nothing is clean', w.entry().isDirty() === false );
	input.value = 'Typed';
	check( '...and a screen with a name typed in the form for a new folder is not', w.entry().isDirty() === true );
	w.entry().discard();
	const fresh = w.input( w.find( w.mount, function( el ) { return el.id === 'files-add' } ), 'name' );
	check( 'discard forgets what was typed, and the screen is drawn again', input.value === '' && fresh.value === '' && w.entry().isDirty() === false );
	fresh.value = 'Saved by the shell';
	let result = null;
	w.entry().save( function( ok ) { result = ok } );
	check( 'the shell\'s Save posts the new folder and ends in done( true ) once it is there - and done( false ) when it is refused', w.last().action === 'files/folder/add' && result === null );
	w.answer( 200, { folder : { id : '999999999999', group : 'staff', name : 'Saved by the shell', upload : false, created : '' } } );
	check( '...done is called with true', result === true );

	fresh.value = 'Again';
	w.entry().save( function( ok ) { result = ok } );
	w.answer( 400, { error : 'x', code : 'files_name', field : 'name' } );
	check( '...and with false for a refusal', result === false );
}

{
	// A rename that is open and a folder that is typed: the shell's Save posts the rename, then the folder; Discard closes the rename
	const w = drawn('en_US');

	w.button( w.rows('files-folder-row')[0], w.label('rename') ).click();
	const edit = w.find( w.mount, function( el ) { return el.className === 'files-edit' } );
	w.input( edit, 'name' ).value = 'Renamed by the shell';
	w.input( w.find( w.mount, function( el ) { return el.id === 'files-add' } ), 'name' ).value = 'Typed beside';
	let result = null;
	w.entry().save( function( ok ) { result = ok } );
	check( 'the shell\'s Save posts the open rename first - and the new folder when that is in', w.requests.filter( function( request ) { return request.action.indexOf('files/folder/') === 0 } ).map( function( request ) { return request.action } ).join() === 'files/folder/save' && result === null );
	w.requests.filter( function( request ) { return request.action === 'files/folder/save' } )[0].answered = true;
	w.requests.filter( function( request ) { return request.action === 'files/folder/save' } )[0].callback( 200, { folder : ROOM } );
	check( '...and says it is saved once both are', w.requests.filter( function( request ) { return request.action.indexOf('files/folder/') === 0 } ).map( function( request ) { return request.action } ).join() === 'files/folder/save,files/folder/add'
		&& JSON.stringify( w.last().payload ) === JSON.stringify( { group : 'staff', name : 'Typed beside' } ) && result === null );
	w.answer( 200, { folder : { id : '999999999999', group : 'staff', name : 'Typed beside', upload : false, created : '' } } );
	check( '...done is true once the folder is in as well', result === true );

	const second = drawn('en_US');

	second.button( second.rows('files-folder-row')[0], second.label('rename') ).click();
	second.input( second.find( second.mount, function( el ) { return el.className === 'files-edit' } ), 'name' ).value = 'Never saved';
	check( 'a rename with a changed name is unsaved input', second.entry().isDirty() === true );
	second.entry().discard();
	check( 'discard closes the rename and draws the folder with the name it has', second.all( second.mount, function( el ) { return el.className === 'files-edit' } ).length === 0 && second.entry().isDirty() === false
		&& copyOf( second, second.rows('files-folder-row')[0] )[0] === 'Room' );
}

{
	const w = world('en_US');
	w.panel.showCurrent();
	w.answer( 500, {} );
	check( 'a list that could not be loaded says so in the pane, in the sentence of the panel with the status in front', w.mount.children.length === 1 && w.mount.children[0].textContent === '(500) '+ w.word('/_admin/files/error/load') && w.mount.children[0].className === 'nino-admin-error' );

	const again = world('en_US');
	again.panel.showCurrent();
	again.answer( 500, { error : 'could not read' } );
	check( '...and a message the server gave is said instead of it', again.mount.children.length === 1 && again.mount.children[0].textContent === '(500) could not read' );
}

{
	const english = drawn('en_US');
	const german = drawn('de_DE');

	// Every path of the screen once, in each language, and no word that was asked for is missing
	[ english, german ].forEach( function( w ) {
		const [ room ] = w.rows('files-folder-row');
		w.button( room, w.word('/_admin/files/label/rename') ).click();
		w.button( room, w.word('/_admin/files/label/delete') ).click();
		w.button( room, w.word('/_admin/files/label/mark') ) ?? w.button( room, w.word('/_admin/files/label/unmark') );
		const [ track ] = w.rows('files-file-row');
		w.button( track, w.word('/_admin/files/label/delete') ).click();
	} );

	check( 'every word the panel asks for - in the list, the rows, the questions, the refusals - is a fill of the text file, in both languages', english.missing.length === 0 && german.missing.length === 0 );
	check( '...and the screen reads in the language of the workbench: the same row says its size in the separator of German', copyOf( german, german.rows('files-file-row')[1] )[1] === [ sized( german, '1', '5', 'kilobyte' ), HIDDEN.by.label, '2026-11-03' ].join(' · ')
		&& german.word('/_admin/files/unit/separator') !== english.word('/_admin/files/unit/separator') );
}

{
	// A web server that takes less than the chunk: its 413 makes the upload start again in halves
	const w = drawn('en_US');
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
	const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );
	const rowsOf = function() { return w.all( list, function( el ) { return el.tagName === 'LI' } ) };
	const shape = function( request ) { return request.payload.index+ '/'+ request.payload.count+ ':'+ ( request.extra.chunk.to - request.extra.chunk.from )+ ':'+ request.payload.upload };
	// The chunk the server of the test takes at most
	const takes = 300000;
	const serve = function() {
		const request = w.last();
		const size = request.extra.chunk.to - request.extra.chunk.from;
		if( size > takes )
			w.answer( 413, null );
		else if( request.payload.index + 1 < request.payload.count )
			w.answer( 200, { upload : 'abcdef0123456789', index : request.payload.index } );
		else
			w.answer( 200, { id : 'ffffffffffff', name : request.payload.name, size : request.payload.size } );
	};

	check( 'the halving is pure: half of the chunk that was refused, a file shorter than the chunk halves its own length, and nothing below 128 KiB - 0 where the chunk is there already',
		w.panel._halve( 2500000, 1048576 ) === 524288 && w.panel._halve( 2500000, 262144 ) === 131072 && w.panel._halve( 2500000, 131072 ) === 0 && w.panel._halve( 200000, 1048576 ) === 131072 && w.panel._halve( 100000, 1048576 ) === 0 && w.panel._halve( 0, 1048576 ) === 0 );

	input.files = [ stood( 'Big.mp3', 2500000 ), stood( 'Next.mp3', 1500000 ) ];
	send.click();
	w.answer( 200, { upload : 'abcdef0123456789', index : 0 } );
	const before = w.requests.length;
	serve();
	check( 'a chunk the server answers 413 starts the file again from its first chunk with no upload id, in chunks of half the size',
		w.requests.length === before + 1 && shape( w.last() ) === '0/5:524288:' && rowsOf()[0].dataset.state === 'sending' );
	check( '...and the row says the parts were made smaller, with its own word, and starts its bar again', copyOf( w, rowsOf()[0] )[1] === w.word('/_admin/files/label/smaller') && w.has('/_admin/files/label/smaller') === true
		&& w.word('/_admin/files/label/smaller') !== w.word('/_admin/files/label/sending') && Number( w.find( rowsOf()[0], function( el ) { return el.tagName === 'PROGRESS' } ).value ) === 0 );
	serve();
	check( '...a chunk of that size is refused again: half once more, again from the first chunk', shape( w.last() ) === '0/10:262144:' && w.requests.filter( function( request ) { return request.payload.index === 0 } ).length === 3 );

	while( w.last().payload.name === 'Big.mp3' )
		serve();

	check( 'the file goes on at the size that passes and is whole: ten chunks of 262144 were not too large', w.requests.filter( function( request ) { return request.payload.name === 'Big.mp3' && request.payload.upload === 'abcdef0123456789' } ).length > 0
		&& rowsOf()[0].dataset.state === 'done' && copyOf( w, rowsOf()[0] )[1] === w.word('/_admin/files/label/done') );
	check( '...the next file of the session starts at the size that worked - 262144, not the setting\'s - and needs no halving', shape( w.last() ) === '0/6:262144:' );

	while( w.last().action === 'files/upload' )
		serve();

	check( '...and so does the next upload of the same session (the panel keeps the working size)', rowsOf().every( function( row ) { return row.dataset.state === 'done' } ) && w.last().action === 'files/list' );

	input.files = [ stood( 'Third.mp3', 2500000 ) ];
	send.click();
	check( '...a third, pressed again, begins at the working size as well', shape( w.last() ) === '0/10:262144:' );
	w.answer( 200, { upload : 'abcdef0123456789', index : 0 } );
	w.answer( 500, { code : 'offline' } );
	check( 'a connection that dropped (the panel is given 500 and the code offline) is not a 413: the file fails with the sentence of the shell and nothing is sent again', rowsOf()[0].dataset.state === 'failed'
		&& copyOf( w, rowsOf()[0] )[1] === w.said( '/_admin/common/error/offline' ) + '' && w.requests.filter( function( request ) { return request.action === 'files/upload' && request.payload.name === 'Third.mp3' } ).length === 2 );
}

{
	// At the floor a 413 is a failure that names the cause
	const w = drawn('en_US');
	const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
	const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
	const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
	const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );
	const sizes = [];

	input.files = [ stood( 'Never.mp3', 2500000 ), stood( 'After.txt', 10 ) ];
	send.click();

	while( w.last().payload.name === 'Never.mp3' && sizes.length < 20 ) {
		sizes.push( w.last().extra.chunk.to - w.last().extra.chunk.from );
		w.answer( 413, null );
	}

	const rows = w.all( list, function( el ) { return el.tagName === 'LI' } );
	check( 'a server that refuses every size is tried at the setting and at each half down to 128 KiB, and no further', sizes.join() === '1048576,524288,262144,131072' );
	check( '...the file then fails with the sentence of the floor, which is not the sentence of any code - and the next file goes on', rows[0].dataset.state === 'failed' && copyOf( w, rows[0] )[1] === w.word('/_admin/files/error/floor') && w.has('/_admin/files/error/floor') === true
		&& w.last().payload.name === 'After.txt' && w.last().payload.count === 1 );
}

{
	// A refusal that is not a 413 is not a sign of a web server limit: nothing is halved
	[ [ 400, { error : 'x', code : 'files_type', field : 'name' } ], [ 429, { error : 'x', code : 'files_limit' } ], [ 500, { error : 'x' } ] ].forEach( function( each ) {
		const w = drawn('en_US');
		const box = w.find( w.mount, function( el ) { return el.id === 'files-upload' } );
		const input = w.find( box, function( el ) { return el.id === 'files-upload-input' } );
		const send = w.find( box, function( el ) { return el.id === 'files-upload-send' } );
		const list = w.find( box, function( el ) { return el.id === 'files-upload-list' } );

		input.files = [ stood( 'Plain.mp3', 2500000 ) ];
		send.click();
		w.answer( each[0], each[1] );
		const row = w.all( list, function( el ) { return el.tagName === 'LI' } )[0];
		check( 'the panel does not halve a '+ each[0]+ ': one request, the row is failed and does not say the parts were made smaller', w.requests.filter( function( request ) { return request.action === 'files/upload' } ).length === 1 && row.dataset.state === 'failed' && copyOf( w, row )[1] !== w.word('/_admin/files/label/smaller') );
	} );
}


// --- The site's script ----------------------------------------------------------

console.log( '\nNino.files - the script of the form on the site\n' );

/**
 *	files.js in a page of its own: a form as [files-upload] writes it, over a Nino that keeps the requests and the
 *	words of the unit's text file
 *
 *	@param		{Object}	[options]		{ chunk, max, multiple, supported }
 *
 *	@return		{Object}
 */
function site( options ) {

	options = Object.assign( { chunk : '1048576', max : '5242880', multiple : '1', supported : true, without : [], storage : undefined }, options );

	const words = Object.assign( fills( own( 'install/text/en_US.php' ) ) );
	options.without.forEach( function( key ) { delete words[key] } );
	const requests = [];
	const ready = [];
	const assigned = [];
	const missing = [];

	const form = element('form');
	form.className = 'files-upload';
	form.setAttribute( 'action', '/club/.files/upload' );
	form.setAttribute( 'data-files-chunk', options.chunk );
	form.setAttribute( 'data-files-max', options.max );
	form.setAttribute( 'data-files-multiple', options.multiple );

	const make = function( tag, attributes ) {
		const el = element( tag );
		Object.keys( attributes ).forEach( function( name ) { el.setAttribute( name, attributes[name] ) } );
		form.appendChild( el );
		return el;
	};

	const folder = make( 'input', { type : 'hidden', name : 'folder' } );
	const token = make( 'input', { type : 'hidden', name : '_csrf' } );
	const input = make( 'input', { type : 'file', name : 'chunk' } );
	const button = make( 'button', { type : 'submit' } );
	const list = make( 'ul', {} );

	folder.value = 'aaaaaaaaaaaa';
	token.value = 'the-token';
	list.className = 'files-upload__list';
	list.hidden = true;

	const page = documentOf( form );
	page.querySelectorAll = function( selector ) { return selector === 'form.files-upload' ? [ form ] : [] };

	const Nino = {
		dir : '/club',
		content : { getText : function( key ) {
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		events : { bindCallback : function( event, callback ) { ready.push( { event : event, callback : callback } ) } },
		http : { sendRequest : function( uri, method, callback, data ) { requests.push( { uri : uri, method : method, callback : callback, data : data, answered : false } ) } },
		// The reader of data attributes the kernel has (Nino.ui.options), for the two types the script uses
		ui : { options : function( el, prefix, schema ) {
			const result = {};
			Object.keys( schema ).forEach( function( name ) {
				const raw = el.getAttribute( 'data-'+ prefix+ '-'+ name );
				result[name] = schema[name].fallback;
				if( raw === null )
					return;
				if( schema[name].type === 'int' && isNaN( parseInt( raw, 10 ) ) === false )
					result[name] = parseInt( raw, 10 );
				if( schema[name].type === 'flag' )
					result[name] = [ '1', 'true', 'yes', 'on' ].indexOf( raw ) !== -1;
			} );
			return result;
		} },
	};

	const window = {
		Nino : Nino,
		location : { href : 'https://example.org/club/files?x=1', assign : function( to ) { assigned.push( to ) } },
		FormData : options.supported === true ? function() {} : undefined,
		sessionStorage : options.storage,
		File : options.supported === true ? function() {} : undefined,
	};

	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, URL : URL } );
	load( own( 'assets/files.js' ), context, 'files.js' );

	return {
		Nino : Nino, form : form, input : input, button : button, list : list, requests : requests, ready : ready, assigned : assigned, missing : missing, window : window,
		word : function( key ) { return words[key] },
		rows : function() { return findAll( list, function( el ) { return el.tagName === 'LI' } ) },
		answer : function( status, body, headers ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( { status : status, responseJSON : body, getAllResponseHeaders : function() { return headers ?? 'content-type: application/json' } } );
		},
		submit : function() {
			const result = { prevented : false };
			fire( form, 'submit', { preventDefault : function() { result.prevented = true } } );
			return result;
		},
	};
}

{
	const s = site();
	const chunks = s.window.Nino.files.chunks;

	check( 'the cut of a size into chunks: the right count and the right bounds - every one but the last exactly a chunk long', JSON.stringify( chunks( 2500000, 1048576 ) ) === '[[0,1048576],[1048576,2097152],[2097152,2500000]]'
		&& chunks( 1048576, 1048576 ).length === 1 && chunks( 1048577, 1048576 ).length === 2 && chunks( 3, 1 ).length === 3 && JSON.stringify( chunks( 1, 4 ) ) === '[[0,1]]' );
	check( '...none for a size or a chunk that is no whole number above nothing', chunks( 0, 4 ).length === 0 && chunks( 4, 0 ).length === 0 && chunks( -4, 2 ).length === 0 && chunks( 4.5, 2 ).length === 0 && chunks( 4, '2' ).length === 0 );
	check( 'the progress is whole percent, held between 0 and 100', s.window.Nino.files.percent( 0, 10 ) === 0 && s.window.Nino.files.percent( 5, 10 ) === 50 && s.window.Nino.files.percent( 10, 10 ) === 100 && s.window.Nino.files.percent( 1, 3 ) === 33
		&& s.window.Nino.files.percent( 20, 10 ) === 100 && s.window.Nino.files.percent( 1, 0 ) === 0 );
}

{
	const s = site();

	check( 'the script waits for the page: it binds its init to the ready event of Nino and takes nothing over before', s.ready.length === 1 && s.ready[0].event === 'ready' && s.form.dataset.filesBound === undefined && s.input.multiple !== true );
	s.ready[0].callback();
	check( 'a form is taken over once, with the input asked to take several files because the form says so', s.form.dataset.filesBound === '1' && s.input.multiple === true && ( s.form.listeners.submit || [] ).length === 1 );
	s.window.Nino.files.init();
	check( '...and not twice', ( s.form.listeners.submit || [] ).length === 1 );

	const unsaid = site();
	unsaid.form.removeAttribute( 'data-files-multiple' );
	unsaid.ready[0].callback();
	check( 'a form that says nothing about several files gets an input that takes one', unsaid.input.multiple === false );

	const single = site( { multiple : '0' } );
	single.ready[0].callback();
	check( 'a form that says one file at a time gets an input that takes one', single.input.multiple === false );

	const bare = site( { supported : false } );
	bare.ready[0].callback();
	check( 'a browser without FormData and File leaves the form to the browser, which posts it whole', bare.form.dataset.filesBound === undefined && ( bare.form.listeners.submit || [] ).length === 0 );

	const noChunk = site( { chunk : '0' } );
	noChunk.ready[0].callback();
	check( 'a form that names no chunk is left alone as well', noChunk.form.dataset.filesBound === undefined );
}

{
	const s = site();
	s.ready[0].callback();

	const none = s.submit();
	check( 'a submit with no file chosen is left to the browser, which asks for one', none.prevented === false && s.requests.length === 0 );

	s.input.files = [ stood( 'One.mp3', 2500000 ), stood( 'Two.txt', 5 ) ];
	s.input.value = 'C:\\fakepath\\One.mp3';
	const taken = s.submit();
	const first = s.requests[0];

	check( 'a submit with files is taken over: the browser does not post, the list is shown, the button is off', taken.prevented === true && s.list.hidden === false && s.button.disabled === true );
	check( 'the first chunk goes to /.files/upload below the directory of the project, as a post, with the fields of the form and of the upload - and the bytes of the chunk', first.uri === '/club/.files/upload' && first.method === 'POST'
		&& JSON.stringify( Object.assign( {}, first.data, { chunk : undefined } ) ) === JSON.stringify( { folder : 'aaaaaaaaaaaa', name : 'One.mp3', size : 2500000, upload : '', index : 0, count : 3, chunk : undefined, _csrf : 'the-token' } ) && first.data.chunk.from === 0 && first.data.chunk.to === 1048576 );

	const second = s.submit();
	check( 'a second submit while it runs is stopped and sends nothing', second.prevented === true && s.requests.length === 1 );

	const spans = function( row ) { return findAll( row, function( el ) { return el.tagName === 'SPAN' } ).map( function( el ) { return el.textContent } ) };
	const bar = function( row ) { return Number( findAll( row, function( el ) { return el.tagName === 'PROGRESS' } )[0].value ) };

	check( 'every file has its row from the start: the file at its turn says it is on its way, the other waits - each is named as text, and their progress is at nothing', s.rows().length === 2 && s.rows()[0].dataset.state === 'sending' && s.rows()[1].dataset.state === 'waiting'
		&& spans( s.rows()[0] ).join('|') === 'One.mp3|'+ s.word('/feature/files/script/sending') && spans( s.rows()[1] ).join('|') === 'Two.txt|'+ s.word('/feature/files/script/waiting') && bar( s.rows()[0] ) === 0 && bar( s.rows()[1] ) === 0 );

	s.answer( 200, { upload : '0123456789abcdef', index : 0 } );
	check( 'the answer of a chunk brings the upload id, which every chunk after it names, and the next index', s.requests[1].data.upload === '0123456789abcdef' && s.requests[1].data.index === 1 && s.requests[1].data.chunk.from === 1048576
		&& Number( findAll( s.rows()[0], function( el ) { return el.tagName === 'PROGRESS' } )[0].value ) === 41 );
	s.answer( 200, { upload : '0123456789abcdef', index : 1 } );
	check( '...and the third is the rest of the file, as the script of the site sends it', s.requests[2].data.index === 2 && s.requests[2].data.chunk.to === 2500000 && s.requests[2].data.upload === '0123456789abcdef' );
	s.answer( 200, { id : 'ffffffffffff', name : 'One.mp3', size : 2500000 } );
	check( 'a file that is in is done, with the word for it, and the next one begins', s.rows()[0].dataset.state === 'done' && spans( s.rows()[0] )[1] === s.word('/feature/files/script/done') && s.rows()[1].dataset.state === 'sending' && s.requests[3].data.name === 'Two.txt' && s.requests[3].data.count === 1 );

	s.answer( 200, { id : 'eeeeeeeeeeee', name : 'Two.txt', size: 5 } );
	check( 'when every file is in the page is loaded again with ?files=sent, the other parts of its address kept - the form is given back, and the files that were chosen are forgotten', s.assigned.length === 1 && s.assigned[0] === '/club/files?x=1&files=sent' && s.button.disabled === false && s.input.value === '' );
}

{
	const s = site();
	s.ready[0].callback();
	s.input.files = [ stood( 'Big.mp3', 2000 ), stood( 'Next.mp3', 5 ) ];
	s.submit();
	s.answer( 400, { error : 'x', code : 'files_quota', params : [ 3 ] } );
	check( 'a refusal shows the word of its code in the row of the file - the unit\'s word, read through Nino.content - and the file is failed', s.rows()[0].dataset.state === 'failed' && findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/state/quota') );
	check( '...the next file is sent all the same, and the page is not loaded again when one of them failed', s.requests.length === 2 && s.requests[1].data.name === 'Next.mp3' );
	s.answer( 200, { id : 'eeeeeeeeeeee', name : 'Next.mp3', size : 5 } );
	check( '...and when all are over the form is given back', s.assigned.length === 0 && s.button.disabled === false && s.input.value === '' );
}

{
	const kinds = [ [ 403, '', 'content-type: x', 'script/expired' ], [ 500, '', '', 'script/offline' ], [ 500, { error : 'x' }, 'content-type: x', 'state/write' ], [ 400, { error : 'x', code : 'files_nonsense' }, 'content-type: x', 'state/write' ],
		[ 404, { error : 'x', code : 'files_folder' }, 'content-type: x', 'state/folder' ], [ 429, { error : 'x', code : 'files_limit' }, 'content-type: x', 'state/limit' ] ];
	const said = kinds.map( function( kind ) {
		const s = site();
		s.ready[0].callback();
		s.input.files = [ stood( 'A.mp3', 5 ) ];
		s.submit();
		s.answer( kind[0], kind[1], kind[2] );
		return findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === site().word( '/feature/files/'+ kind[3] );
	} );

	check( 'each way an answer can fail has a word: a post the csrf guard refused (a 403 with no body) says the session ran out, no answer at all that the connection failed, a failure with no code that the file could not be stored, a code the script does not know the same, and the folder and the limit their own',
		said.every( function( same ) { return same === true } ) );
}

{
	const s = site();
	s.ready[0].callback();
	s.input.files = [ stood( 'Huge.mp3', 5242881 ), stood( 'Empty.mp3', 0 ) ];
	s.submit();

	check( 'a file over data-files-max, and an empty one, are refused before a byte is sent, each with the word of its refusal', s.requests.length === 0 && s.rows().length === 2 && s.rows().every( function( row ) { return row.dataset.state === 'failed' } )
		&& findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/state/size') && findAll( s.rows()[1], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/state/chunk') );
	check( '...and nothing is loaded again, the form is given back', s.assigned.length === 0 && s.button.disabled === false );
}

{
	const s = site();
	s.ready[0].callback();
	s.input.files = [ stood( '<img src=x onerror=alert(1)>.mp3', 5 ) ];
	s.submit();

	check( 'the name of a file is text in its row: markup in it is a string and no element', findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[0].textContent === '<img src=x onerror=alert(1)>.mp3' && findAll( s.list, function( el ) { return el.tagName === 'IMG' } ).length === 0 );
	check( 'every word the script asked for is a fill of the unit\'s text file', s.missing.length === 0 );
}

{
	// A web server that takes less than the chunk: its 413 makes the upload start again in halves
	const s = site();
	const halve = s.window.Nino.files.halve;
	const takes = 300000;
	const say = function( row ) { return findAll( row, function( el ) { return el.tagName === 'SPAN' } )[1].textContent };
	const shape = function( request ) { return request.data.index+ '/'+ request.data.count+ ':'+ ( request.data.chunk.to - request.data.chunk.from )+ ':'+ request.data.upload };
	const serve = function() {
		const request = s.requests[s.requests.length - 1];
		const size = request.data.chunk.to - request.data.chunk.from;
		if( size > takes )
			s.answer( 413, null );
		else if( request.data.index + 1 < request.data.count )
			s.answer( 200, { upload : 'abcdef0123456789', index : request.data.index } );
		else
			s.answer( 200, { id : 'ffffffffffff', name : request.data.name, size : request.data.size } );
	};

	check( 'the halving is pure: half of the chunk that was refused, a file shorter than the chunk halves its own length, and nothing below 128 KiB - 0 where the chunk is there already',
		halve( 2500000, 1048576 ) === 524288 && halve( 2500000, 262144 ) === 131072 && halve( 2500000, 131072 ) === 0 && halve( 200000, 1048576 ) === 131072 && halve( 100000, 1048576 ) === 0 && s.window.Nino.files.floor === 131072 );

	s.ready[0].callback();
	s.input.files = [ stood( 'Big.mp3', 2500000 ), stood( 'Next.mp3', 1500000 ) ];
	s.submit();
	s.answer( 200, { upload : 'abcdef0123456789', index : 0 } );
	const before = s.requests.length;
	serve();
	check( 'a chunk the web server answers 413 starts the file again from its first chunk with no upload id, in chunks of half the size - and the row says the parts were made smaller',
		s.requests.length === before + 1 && shape( s.requests[s.requests.length - 1] ) === '0/5:524288:' && s.rows()[0].dataset.state === 'sending' && say( s.rows()[0] ) === s.word('/feature/files/script/smaller')
		&& s.word('/feature/files/script/smaller') !== s.word('/feature/files/script/sending') && Number( findAll( s.rows()[0], function( el ) { return el.tagName === 'PROGRESS' } )[0].value ) === 0 );
	serve();
	check( '...refused again, half once more, from the first chunk again', shape( s.requests[s.requests.length - 1] ) === '0/10:262144:' && s.requests.filter( function( request ) { return request.data.index === 0 } ).length === 3 );

	while( s.requests[s.requests.length - 1].data.name === 'Big.mp3' )
		serve();

	check( '...the file goes on with the upload id of the new upload and is done; the next file of the form begins at the size that worked, not at the setting', s.rows()[0].dataset.state === 'done' && say( s.rows()[0] ) === s.word('/feature/files/script/done')
		&& shape( s.requests[s.requests.length - 1] ) === '0/6:262144:' );

	while( s.assigned.length === 0 )
		serve();

	check( '...and when every file is in the page is loaded again as before', s.rows().every( function( row ) { return row.dataset.state === 'done' } ) && s.assigned.length === 1 );

	s.input.files = [ stood( 'Third.mp3', 2500000 ) ];
	s.submit();
	check( '...a later submit of the same page starts at the working size too', shape( s.requests[s.requests.length - 1] ) === '0/10:262144:' );
	s.answer( 200, { upload : 'abcdef0123456789', index : 0 } );
	const sent = s.requests.length;
	s.answer( 500, null, '' );
	check( 'a connection that dropped (no headers at all) is not a 413: the file fails as it did, with the word of the connection, and nothing is sent again', s.requests.length === sent && s.rows()[2].dataset.state === 'failed' && say( s.rows()[2] ) === s.word('/feature/files/script/offline') );
}

{
	// At the floor a 413 is a failure that names the cause
	const s = site();
	const sizes = [];

	s.ready[0].callback();
	s.input.files = [ stood( 'Never.mp3', 2500000 ), stood( 'After.txt', 10 ) ];
	s.submit();

	while( s.requests[s.requests.length - 1].data.name === 'Never.mp3' && sizes.length < 20 ) {
		const request = s.requests[s.requests.length - 1];
		sizes.push( request.data.chunk.to - request.data.chunk.from );
		s.answer( 413, null );
	}

	check( 'a server that refuses every size is tried at the setting and at each half down to 128 KiB, and no further', sizes.join() === '1048576,524288,262144,131072' );
	check( '...the file then fails with the sentence of the floor, the next file goes on and the page is not loaded again',
		s.rows()[0].dataset.state === 'failed' && findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/script/floor') && s.word('/feature/files/script/floor') !== s.word('/feature/files/state/write')
		&& s.requests[s.requests.length - 1].data.name === 'After.txt' );

	s.answer( 200, { id : 'ffffffffffff', name : 'After.txt', size : 10 } );
	check( '...and a small file of 10 bytes follows at the floor without a word of halving', s.rows()[1].dataset.state === 'done' && s.assigned.length === 0 && s.missing.length === 0 );
}

{
	// A 413 that carries a code the script has a word for says that word: the cause is named
	const s = site();
	s.ready[0].callback();
	s.input.files = [ stood( 'Tiny.txt', 10 ) ];
	s.submit();
	s.answer( 413, { code : 'files_size' } );
	check( 'a 413 at the floor that names a code the script knows says the word of the code, not the sentence of the web server', findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/state/size') );
}

{
	// A site whose texts were stored before the two new words existed
	const s = site( { without : [ '/feature/files/script/smaller', '/feature/files/script/floor' ] } );
	s.ready[0].callback();
	s.input.files = [ stood( 'Old.mp3', 300000 ) ];
	s.submit();
	s.answer( 413, null );
	check( 'a site that has not the word for the smaller parts yet shows the word of the upload in its row, and the file goes on in halves', findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/script/sending') && s.requests[s.requests.length - 1].data.count === 2 );
	s.answer( 413, null );
	s.answer( 413, null );
	check( '...and at the floor, without the word of the floor, the word for a chunk that did not arrive whole - never an empty row', s.rows()[0].dataset.state === 'failed' && findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent === s.word('/feature/files/state/chunk') );
}

{
	// The working size is kept over the reload that ends a submit, in the storage of the tab, by the action of the form
	const store = { data : {}, getItem : function( key ) { return Object.prototype.hasOwnProperty.call( this.data, key ) ? this.data[key] : null }, setItem : function( key, value ) { this.data[key] = String( value ) } };
	const takes = 300000;
	const run = function( s ) {
		s.ready[0].callback();
		s.input.files = [ stood( 'Kept.mp3', 2500000 ) ];
		s.submit();
		while( s.assigned.length === 0 && s.rows()[s.rows().length - 1].dataset.state !== 'failed' ) {
			const request = s.requests[s.requests.length - 1];
			if( request.data.chunk.to - request.data.chunk.from > takes )
				s.answer( 413, null );
			else
				s.answer( 200, request.data.index + 1 < request.data.count ? { upload : 'abcdef0123456789', index : request.data.index } : { id : 'ffffffffffff', name : 'Kept.mp3', size : 2500000 } );
		}
		return s;
	};
	const first = run( site( { storage : store } ) );
	check( 'the size that worked is written to the storage of the tab, once, under a key of its own', Object.keys( store.data ).length === 1 && store.data[Object.keys( store.data )[0]] === '262144' && first.assigned.length === 1 );

	const second = site( { storage : store } );
	second.ready[0].callback();
	second.input.files = [ stood( 'Again.mp3', 2500000 ) ];
	second.submit();
	check( '...and the next submit, on the page the reload loaded, starts at that size - no request is wasted on the setting', second.requests.length === 1 && second.requests[0].data.count === 10 && second.requests[0].data.chunk.to - second.requests[0].data.chunk.from === 262144 );

	const odd = [ 'abc', '10', '-5', '', '99999999999' ].map( function( value ) {
		const bad = { getItem : function() { return value }, setItem : function() {} };
		const s = site( { storage : bad } );
		s.ready[0].callback();
		s.input.files = [ stood( 'Odd.mp3', 2500000 ) ];
		s.submit();
		return s.requests[0].data.chunk.to - s.requests[0].data.chunk.from;
	} );
	check( '...a value that is no chunk (words, below the floor, negative, empty) is not taken: the submit starts at the setting; one above the setting is held to it', odd.slice( 0, 4 ).every( function( size ) { return size === 1048576 } ) && odd[4] === 1048576 );

	const none = [ undefined, { getItem : function() { throw new Error('blocked') }, setItem : function() { throw new Error('blocked') } } ].map( function( broken ) {
		const s = run( site( { storage : broken } ) );
		return s.assigned.length === 1 && s.rows()[0].dataset.state === 'done';
	} );
	check( '...storage that is missing or throws is harmless: the upload halves, goes through and the page is loaded again', none.every( function( ok ) { return ok === true } ) );
}

{
	// A refusal that is not a 413 is not a sign of a web server limit: nothing is halved
	[ [ 429, { code : 'files_limit' }, 'limit' ], [ 400, { code : 'files_chunk' }, 'chunk' ], [ 404, { code : 'files_folder' }, 'folder' ] ].forEach( function( each ) {
		const s = site();
		s.ready[0].callback();
		s.input.files = [ stood( 'Plain.mp3', 2500000 ) ];
		s.submit();
		s.answer( each[0], each[1] );
		const said = findAll( s.rows()[0], function( el ) { return el.tagName === 'SPAN' } )[1].textContent;
		check( 'a '+ each[0]+ ' is not halved: one request, the file fails with the word of its code, no word about smaller parts', s.requests.length === 1 && s.rows()[0].dataset.state === 'failed' && said === s.word('/feature/files/state/'+ each[2]) && said !== s.word('/feature/files/script/smaller') );
	} );
}

done();

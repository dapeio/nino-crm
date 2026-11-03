/**
 *	Nino
 *	review-js-smoke.js	What the two scripts of the Review feature do. The site's (review.js): the
 *											reading of a time and the writing of one, which are pure and read as the
 *											php of the feature reads them, the move of the player to a second over a
 *											stand-in for the media element, and the player it takes over - a click on
 *											the time of a comment takes the player there and plays, the time field
 *											follows the player until the person types, the button for the position
 *											writes it, the buttons that do nothing without the script are shown, and an
 *											answer fills the parent, says who is answered and can be given up. The
 *											panel's (admin.js) over the kernel's own Nino.admin.js: it asks for
 *											review/list when the pane is first shown and draws the answer with
 *											textContent only - the files by group and folder with their counts -, opens a
 *											file to its player, the comments in the order of the answer with their
 *											answers, a time that takes the player there, the form of the owner's
 *											comment that posts review/answer and says what came of it in the sentence of
 *											the code, done and delete (after the question the recipe asks). Only Nino
 *											itself is a stand-in: the requests are kept for the test to answer, and the
 *											words are the ones of the text files, in both interface languages.
 *
 *											A pin names the words it expects by their keys and not by what they say:
 *											w.said( key, ...params ) is the fill with its params put in as the panel puts
 *											them in, so that rewording a sentence breaks nothing and a screen that shows
 *											the wrong one does. The names and the days of the fixtures are literal.
 *
 *											The helpers - check(), done(), fills(), load(), findAll(), fire(), element()
 *											and documentOf() - are those of tests/harness.js of the checkout, three levels
 *											up or the one NINO_ROOT names. review-smoke.php runs this where node is on the
 *											path.
 *
 *	Usage: node features/Review/tests/review-js-smoke.js
 *	       NINO_ROOT=../nino node features/Review/tests/review-js-smoke.js
 */

'use strict';

/* global require, __dirname, process, setImmediate */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

/**
 *	A file of the feature the way load() and fills() want it: from the root of the checkout, which the feature sits outside
 *	of when it is tested from the catalogue
 *
 *	@param		{string}	relative		A path below features/Review/
 *
 *	@return		{string}
 */
function own( relative ) {
	return path.relative( root, fs.realpathSync( path.join( __dirname, '..', relative ) ) );
}

/**
 *	@param		{string}	relative		A path below features/Review/
 *
 *	@return		{string}							The text of the file
 */
function text( relative ) {
	return fs.readFileSync( path.join( __dirname, '..', relative ), 'utf8' );
}

/**
 *	A call whose failure is a failed pin and not the end of the run
 *
 *	@param		{Function}	call
 *
 *	@return		{{ threw: boolean, value: * }}
 */
function attempt( call ) {
	try {
		return { threw : false, value : call() };
	}
	catch( error ) {
		return { threw : true, value : undefined };
	}
}

// A refused play is a promise that is rejected: one nobody handles ends the process on its own, so it is counted and pinned at the end
let unhandled = 0;
process.on( 'unhandledRejection', function() { unhandled++ } );


// --- The site's script ------------------------------------------------------------

console.log( '\nNino.review - the script of the player on the site\n' );

/**
 *	A comment or an answer the way the templates write it: the item with its id and - for one with a time - the second, the
 *	link of the time, the name of the author and the button that answers it
 *
 *	@param		{string}				id
 *	@param		{number|null}		at
 *	@param		{string}				who
 *
 *	@return		{Object}
 */
function item( id, at, who ) {

	const li = element('li');
	const head = element('div');
	const name = element('strong');
	const reply = element('button');

	li.setAttribute( 'data-review-id', id );
	name.className = 'review-comment__who';
	name.textContent = who;
	reply.setAttribute( 'data-review-reply', '' );
	reply.hidden = true;

	if( at !== null ) {
		const link = element('a');
		li.setAttribute( 'data-review-at', String( at ) );
		link.className = 'review-comment__time';
		head.appendChild( link );
		li.link = link;
	}

	head.appendChild( name );
	li.appendChild( head );
	li.appendChild( reply );
	li.reply = reply;

	return li;
}

/**
 *	review.js in a page of its own: a player as [review-player] writes it - the media element, the comments with an answer under
 *	one of them, and the form -, over a Nino that keeps the words it is asked for
 *
 *	@param		{Object}	[options]		{ locale, list, rejects }
 *
 *	@return		{Object}
 */
function site( options ) {

	options = Object.assign( { locale : 'en_US', list : false, rejects : false }, options );

	const words = fills( own( 'install/text/'+ options.locale+ '.php' ) );
	const asked = [];
	const missing = [];
	const ready = [];

	const box = element('div');
	box.className = 'review-player';

	const media = element('audio');
	media.currentTime = 0;
	media.plays = 0;
	media.play = function() {
		media.plays++;
		return options.rejects === true ? Promise.reject( new Error('not allowed') ) : Promise.resolve();
	};

	const comments = element('ol');
	const first = item( 'aaaaaaaaaaaa', 65, 'Ada $& Lovelace' );
	const second = item( 'bbbbbbbbbbbb', null, 'Owner' );
	const answers = element('ul');
	const answerTimed = item( 'cccccccccccc', 70, 'Cyd' );
	const answerPlain = item( 'dddddddddddd', null, 'Owner' );
	const form = element('form');
	const parent = element('input');
	const atField = element('p');
	const at = element('input');
	const now = element('button');
	const textField = element('p');
	const textarea = element('textarea');

	form.className = 'review-form';
	parent.setAttribute( 'name', 'parent' );
	parent.value = '';
	at.setAttribute( 'name', 'at' );
	at.value = '';
	now.className = 'review-form__now';
	now.hidden = true;
	textarea.setAttribute( 'name', 'text' );

	answers.appendChild( answerTimed );
	answers.appendChild( answerPlain );
	first.appendChild( answers );
	comments.appendChild( first );
	comments.appendChild( second );
	atField.appendChild( at );
	atField.appendChild( now );
	textField.appendChild( textarea );
	form.appendChild( parent );
	form.appendChild( atField );
	form.appendChild( textField );

	if( options.list === false ) {
		box.appendChild( media );
		box.appendChild( comments );
		box.appendChild( form );
	}

	const page = documentOf( box );
	page.querySelectorAll = function( selector ) { return selector === '.review-player' ? [ box ] : [] };

	const Nino = {
		content : { getText : function( key ) {
			asked.push( key );
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		events : { bindCallback : function( event, callback ) { ready.push( { event : event, callback : callback } ) } },
	};

	const window = { Nino : Nino };
	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window } );

	load( own( 'assets/review.js' ), context, 'review.js' );

	return {
		Nino : Nino, box : box, media : media, form : form, parent : parent, at : at, now : now, textarea : textarea, first : first, second : second, answerTimed : answerTimed, answerPlain : answerPlain,
		review : window.Nino.review, ready : ready, asked : asked, missing : missing, word : function( key ) { return words[key] },
		click : function( target ) {
			const result = { prevented : false };
			fire( box, 'click', { target : target, preventDefault : function() { result.prevented = true } } );
			return result;
		},
		start : function() { ready[0].callback() },
	};
}

{
	const s = site();
	const seconds = s.review.seconds;
	const table = [ [ '1:05', 65 ], [ '0:03.5', 3.5 ], [ '75', 75 ], [ '1:02:03', 3723 ], [ ' 1:05 ', 65 ], [ '62:03', 3723 ], [ '0:00', 0 ], [ '3,5', 3.5 ], [ '1:05,5', 65.5 ], [ '1:5', 65 ], [ '0', 0 ], [ '12.34', 12.3 ], [ '99:59:59.9', 359999.9 ],
		[ 'x', null ], [ '', null ], [ ' ', null ], [ '-5', null ], [ '-1:05', null ], [ '1:75', null ], [ '1:60', null ], [ '1:02:60', null ], [ '1:2:3', null ], [ '1e3', null ], [ '.5', null ], [ '5.', null ], [ '1:05:', null ], [ ':05', null ], [ '1::05', null ],
		[ '1.5.2', null ], [ '100:00:00', null ], [ '99999999999999999999', null ], [ 'NaN', null ], [ '0x10', null ], [ '１:０５', null ] ];
	const wrong = table.filter( function( row ) { return seconds( row[0] ) !== row[1] } ).map( function( row ) { return JSON.stringify( row[0] )+ ' is '+ seconds( row[0] ) } );

	check( 'seconds() reads a time as Review::seconds() does - m:ss, h:mm:ss and a number, a comma for the point, round to one decimal, and nothing, a negative, a clock past 59 seconds and anything that is no time as null ('+ table.length+ ' cases)'+ ( wrong.length === 0 ? '' : ' - '+ wrong.slice( 0, 3 ).join('; ') ), wrong.length === 0 );
	check( '...the table of the plan', seconds('1:05') === 65 && seconds('0:03.5') === 3.5 && seconds('75') === 75 && seconds('1:02:03') === 3723 && seconds('x') === null && seconds('') === null && seconds('-3') === null );

	const clock = s.review.clock;

	check( 'clock() writes m:ss as Review::clock() does: the minutes as many as there are, the decimals left out, the seconds below 60', clock( 65 ) === '1:05' && clock( 3.5 ) === '0:03' && clock( 0 ) === '0:00' && clock( 3723 ) === '62:03' && clock( 59.99 ) === '0:59' && clock( 359999.9 ) === '5999:59' );
	check( '...a position that is no time - negative, not a number, infinite - is 0:00, and what it writes seconds() reads back', clock( -4 ) === '0:00' && clock( NaN ) === '0:00' && clock( Infinity ) === '0:00' && clock( undefined ) === '0:00' && seconds( clock( 3723 ) ) === 3723 && seconds( clock( 65.9 ) ) === 65 );

	const position = s.review.position;

	check( 'position() writes the position of the player the way the time field takes it: m:ss, and the tenth of a second after a point where there is one - 2.5 is 0:02.5, 65.7 is 1:05.7 -, a tenth of 0 is left out - 4 is 0:04, 4.04 is 0:04 - and a tenth is cut and not rounded: 59.99 is 0:59.9',
		position( 2.5 ) === '0:02.5' && position( 65.7 ) === '1:05.7' && position( 4 ) === '0:04' && position( 4.0 ) === '0:04' && position( 4.04 ) === '0:04' && position( 59.99 ) === '0:59.9' && position( 3723.5 ) === '62:03.5' && position( 0.1 ) === '0:00.1' );
	check( '...a position that is no time - negative, not a number, infinite - is 0:00', position( -4 ) === '0:00' && position( NaN ) === '0:00' && position( Infinity ) === '0:00' && position( undefined ) === '0:00' && position( -0.4 ) === '0:00' );

	// Every tenth of the first twelve minutes: what is written is what seconds() reads back, to the tenth - and no tenth is lost on the way
	const lost = [];
	for( let tenths = 0; tenths <= 7200; tenths++ )
		if( seconds( position( tenths / 10 ) ) !== tenths / 10 )
			lost.push( tenths / 10 );
	check( '...and what it writes seconds() reads back to the very tenth: every tenth of the first twelve minutes survives the trip'+ ( lost.length === 0 ? '' : ' - lost '+ lost.slice( 0, 3 ).join(', ') ), lost.length === 0 );
}

{
	const s = site();
	const media = { currentTime : 0, plays : 0, play : function() { media.plays++; return Promise.reject( new Error('not allowed') ) } };
	const moved = s.review.seek( media, 65.5 );

	check( 'seek() takes the player to the second and plays from there - and a browser that refuses to play without a gesture is not an error: the player has moved all the same', moved === true && media.currentTime === 65.5 && media.plays === 1 );
	check( '...a second that is none - negative, not a number, infinite - moves nothing and plays nothing', s.review.seek( media, -1 ) === false && s.review.seek( media, NaN ) === false && s.review.seek( media, Infinity ) === false && s.review.seek( media, '5' ) === false && media.currentTime === 65.5 && media.plays === 1 );
	check( '...the second 0 is a second', s.review.seek( media, 0 ) === true && media.currentTime === 0 );
	const bare = attempt( function() { return s.review.seek( { currentTime : 5 }, 9 ) } );
	check( '...and a stand-in that cannot play is moved without a word', bare.threw === false && bare.value === true );
}

{
	const s = site();

	check( 'the script waits for the page: it binds its init to the ready event of Nino and takes nothing over before - the controls that do nothing without it are still hidden', s.ready.length === 1 && s.ready[0].event === 'ready' && s.box.dataset.reviewBound === undefined
		&& s.first.reply.hidden === true && s.now.hidden === true && ( s.box.listeners.click || [] ).length === 0 );
	s.start();
	check( 'a player is taken over once, and the buttons that answer a comment and take the position of the player are shown', s.box.dataset.reviewBound === '1' && ( s.box.listeners.click || [] ).length === 1 && s.first.reply.hidden === false && s.second.reply.hidden === false
		&& s.answerTimed.reply.hidden === false && s.answerPlain.reply.hidden === false && s.now.hidden === false );
	s.review.init();
	check( '...and not twice', ( s.box.listeners.click || [] ).length === 1 && ( s.media.listeners.pause || [] ).length === 1 );

	const list = site( { list : true } );
	const taken = attempt( function() { list.start() } );
	check( 'the list of the files has neither a player nor a form: it is left alone', taken.threw === false && list.box.dataset.reviewBound === undefined && ( list.box.listeners.click || [] ).length === 0 );
}

{
	const s = site();
	s.start();

	const first = s.click( s.first.link );
	check( 'a click on the time of a comment takes the player to its second and plays from there - and the link does not follow its address', s.media.currentTime === 65 && s.media.plays === 1 && first.prevented === true );

	const answer = s.click( s.answerTimed.link );
	check( 'the time of an answer takes the player to the answer\'s own second, not to the second of the comment it stands under', s.media.currentTime === 70 && s.media.plays === 2 && answer.prevented === true );

	const outside = s.click( s.first.reply.parentNode );
	check( 'a click anywhere else on the player moves nothing', outside.prevented === false && s.media.currentTime === 70 && s.media.plays === 2 );

	// A time link of a comment that has no second: the browser follows the link as it would without the script
	const stray = element('a');
	stray.className = 'review-comment__time';
	s.second.appendChild( stray );
	const plain = s.click( stray );
	check( 'a time link whose comment names no second is left to the browser', plain.prevented === false && s.media.currentTime === 70 );

	const refused = site( { rejects : true } );
	refused.start();
	refused.click( refused.first.link );
	check( 'a player that refuses to play still moves, and the page sees no error', refused.media.currentTime === 65 && refused.media.plays === 1 );
}

{
	const s = site();
	s.start();

	s.media.currentTime = 65.7;
	fire( s.media, 'pause' );
	check( 'when the player stops, the time field says where it is - m:ss and the tenth of a second', s.at.value === '1:05.7' );
	s.media.currentTime = 140;
	fire( s.media, 'seeked' );
	check( '...and when it is moved', s.at.value === '2:20' );
	s.media.currentTime = 200;
	fire( s.media, 'ended' );
	check( '...and when it ends', s.at.value === '3:20' );
	s.media.currentTime = 2.5;
	fire( s.media, 'pause' );
	check( '...a position with a tenth keeps it: at 2.5 the field reads 0:02.5, at 4.0 it reads 0:04', s.at.value === '0:02.5' && ( s.media.currentTime = 4.0, fire( s.media, 'pause' ), s.at.value === '0:04' ) );

	s.at.value = '9:99';
	fire( s.at, 'input' );
	s.media.currentTime = 5;
	fire( s.media, 'pause' );
	check( 'a time the person typed is theirs: the player does not write over it', s.at.value === '9:99' );

	s.now.click();
	check( 'the button for the position writes the position of the player into the field, as m:ss, whatever was in it', s.at.value === '0:05' );
	s.media.currentTime = 7.3;
	s.now.click();
	check( '...with its tenth where it has one', s.at.value === '0:07.3' && s.review.seconds( s.at.value ) === 7.3 );
	s.media.currentTime = 31;
	fire( s.media, 'pause' );
	check( '...and the field follows the player again', s.at.value === '0:31' );

	fire( s.textarea, 'input' );
	s.media.currentTime = 90;
	fire( s.media, 'pause' );
	check( 'a person who has begun to write the comment has settled where it belongs: the field stays where it was', s.at.value === '0:31' );
	s.now.click();
	check( '...until they ask for the position again', s.at.value === '1:30' );
}

{
	const s = site();
	s.start();

	s.at.value = '1:05';
	s.click( s.first.reply );
	const line = s.form.children.find( function( el ) { return el.className === 'review-form__answering' } );
	const label = line.children[0];
	const cancel = line.children[1];

	check( 'answering a comment puts its id into the field parent, says who is answered above the comment, empties the time and puts the focus in the comment', s.parent.value === 'aaaaaaaaaaaa' && line.hidden === false && label.textContent === s.word('/feature/review/script/answering').split('%s').join('Ada $& Lovelace')+ ' '
		&& s.at.value === '' && s.textarea.focused === true );
	check( '...the name is put in as text: nothing in it is read as a pattern', label.textContent.indexOf('Ada $& Lovelace') !== -1 && label.textContent.indexOf('%s') === -1 && s.form.children.indexOf( line ) < s.form.children.indexOf( s.textarea.parentNode ) );

	s.media.currentTime = 12;
	fire( s.media, 'pause' );
	check( '...and an answer has no time unless one is typed: the player does not fill it', s.at.value === '' );

	s.click( s.answerTimed.reply );
	check( 'answering an answer takes the id of that answer - the server files it under the comment - and the line says who', s.parent.value === 'cccccccccccc' && label.textContent === s.word('/feature/review/script/answering').split('%s').join('Cyd')+ ' ' && s.form.children.filter( function( el ) { return el.className === 'review-form__answering' } ).length === 1 );

	cancel.click();
	check( 'the button of the line gives the answer up: the comment is one of its own again, the line is gone', s.parent.value === '' && line.hidden === true && cancel.textContent === s.word('/feature/review/script/cancel') && cancel.type === 'button' );

	s.click( s.second.reply );
	check( '...and an answer can be prepared again afterwards', s.parent.value === 'bbbbbbbbbbbb' && line.hidden === false && label.textContent.indexOf('Owner') !== -1 );
}

{
	const s = site();
	s.start();

	s.click( s.first.reply );
	const line = s.form.children.find( function( el ) { return el.className === 'review-form__answering' } );
	const cancel = line.children[1];

	s.media.currentTime = 12;
	fire( s.media, 'pause' );
	const whileAnswering = s.at.value;
	cancel.click();
	s.media.currentTime = 31.5;
	fire( s.media, 'pause' );
	check( 'once an answer is given up the time field follows the player again: the comment is one of its own, and its time is the player\'s - while the answer was prepared the player did not fill it', whileAnswering === '' && s.parent.value === '' && s.at.value === '0:31.5' );

	s.click( s.second.reply );
	s.textarea.value = 'Half written';
	fire( s.textarea, 'input' );
	cancel.click();
	s.media.currentTime = 90;
	fire( s.media, 'pause' );
	check( '...but not where the person has begun to write since: that comment has settled where it belongs, and the field stays as it is', s.parent.value === '' && s.at.value === '' );

	s.textarea.value = '';
	s.click( s.first.reply );
	s.at.value = '2:00';
	fire( s.at, 'input' );
	cancel.click();
	s.media.currentTime = 150;
	fire( s.media, 'pause' );
	check( '...nor where a time was typed since: it is theirs', s.at.value === '2:00' );
}

{
	const en = site( { locale : 'en_US' } );
	const de = site( { locale : 'de_DE' } );
	en.start();
	de.start();
	en.click( en.first.reply );
	de.click( de.first.reply );

	check( 'every word the script asks for is a fill of the unit\'s text file, in both languages - and it asks for the words of the script and for nothing else', en.missing.length === 0 && de.missing.length === 0 && en.asked.length > 0
		&& en.asked.filter( function( key ) { return key.indexOf('/feature/review/script/') !== 0 } ).length === 0 && en.word('/feature/review/script/answering') !== de.word('/feature/review/script/answering') && en.word('/feature/review/script/answering').indexOf('%s') !== -1 && de.word('/feature/review/script/answering').indexOf('%s') !== -1 );

	const source = text('assets/review.js');
	check( 'the script writes text and nothing else: no innerHTML, no outerHTML, no insertAdjacentHTML, no document.write, no inline handler, no eval - and it posts nothing: no request of its own', /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\son[a-z]+\s*=|\beval\s*\(|new Function/.test( source ) === false
		&& /sendRequest|XMLHttpRequest|fetch\s*\(|FormData|\.submit\s*\(/.test( source ) === false );
}


// --- The panel -----------------------------------------------------------------------

console.log( '\nNino.admin.review - the screen\n' );

/**
 *	The panel's script in a page of its own, in one interface language: the kernel's Nino.admin.js and the feature's admin.js, over a
 *	Nino that keeps the requests and the words of the text files. The pane has the region of templates/panel.tpl
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
	mount.id = 'review-app';

	const page = documentOf( mount );
	const make = page.createElement;

	page.createElement = function( tag ) {
		const el = make( tag );
		Object.defineProperty( el, 'name', { get : function() { return el.attributes.name ?? '' }, set : function( value ) { el.setAttribute( 'name', value ) } } );

		// A browser that refuses to play without a gesture of the person: the promise is rejected
		if( tag === 'audio' || tag === 'video' ) {
			el.plays = 0;
			el.play = function() { el.plays++; return Promise.reject( new Error('not allowed') ) };
		}

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
		confirm : function( message ) { asked.push( message ); return agree },
	};

	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	load( own( 'assets/admin.js' ), context, 'admin.js' );

	const find = function( from, predicate ) { return findAll( from ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin.review,
		mount : mount,
		requests : requests,
		registered : registered,
		missing : missing,
		asked : asked,
		agree : function( yes ) { agree = yes },
		entry : function() { return registered[0].entry },
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		rows : function( cls ) { return findAll( mount, function( el ) { return el.tagName === 'LI' && String( el.className ).split(' ').indexOf( cls ) !== -1 } ) },
		button : function( from, label ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === label } ) },
		word : function( key ) { return words[key] },
		has : function( key ) { return typeof words[key] === 'string' && words[key] !== '' },
		// The fill with its params put in as the panel puts them in (Nino.adminUi.format) - '' for a key the text files lack, which no pin takes for the sentence
		said : function( key, ...params ) { return words[key] === undefined ? '' : Nino.adminUi.format( words[key], ...params ) },
		last : function() { return requests[requests.length - 1] },
		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );

			// A screen that asked for nothing has nothing to answer: a failed pin, not a stop
			if( open.length === 0 ) {
				check( 'a request was out for the answer of the server', false );
				return;
			}

			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
	};
}

const MEMBER = { kind : 'member', id : 'eeeeeeeeeeee', label : 'Ada Lovelace' };
const OWNER = { kind : 'owner', id : '', label : '' };
const GONE = { kind : 'member', id : 'ffffffffffff', label : '' };
const ROWS = [
	{ id : 'a00000000001', name : 'Clip.mp4', kind : 'video', folder : 'f00000000001', folderName : 'Room', group : 'staff', count : 1, open : 1 },
	{ id : 'a00000000002', name : '<b>Demo</b>.mp3', kind : 'audio', folder : 'f00000000001', folderName : 'Room', group : 'staff', count : 3, open : 0 },
	{ id : 'a00000000003', name : 'Other.mp3', kind : 'audio', folder : 'f00000000002', folderName : '<img src=x onerror=alert(1)>', group : 'staff', count : 2, open : 2 },
	{ id : 'a00000000004', name : 'Ghost.mp3', kind : 'audio', folder : 'f00000000003', folderName : '', group : '', count : 1, open : 0 },
];
const FILE = { id : 'a00000000002', name : '<b>Demo</b>.mp3', kind : 'audio', url : '/club/.files/a00000000002', playable : true };
const COMMENTS = [
	{ id : 'c00000000001', by : MEMBER, at : 12.3, text : 'Intro is long\n<img src=x onerror=alert(1)>', parent : '', done : false, created : '2026-11-02T10:15:30Z', page : '/club/review', answers : [] },
	{ id : 'c00000000002', by : MEMBER, at : 65, text : 'Snare is late', parent : '', done : true, created : '2026-11-03T10:15:30Z', page : '', answers: [
		{ id : 'c00000000003', by : OWNER, at : null, text : 'Fixed', parent : 'c00000000002', done : false, created : '2026-11-04T10:15:30Z', page : '' },
		{ id : 'c00000000004', by : GONE, at : 70, text : 'Thanks', parent : 'c00000000002', done : false, created : '2026-11-05T10:15:30Z', page : '' } ] },
	{ id : 'c00000000005', by : OWNER, at : null, text : 'A note', parent : '', done : false, created : '2026-11-06T10:15:30Z', page : '', answers : [] },
];
const VIEW = { file : FILE, comments : COMMENTS, length : 2000, files : true };

/** The panel shown and its list answered: the screen is drawn */
function drawn( locale, rows ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, { files : rows ?? ROWS } );
	return w;
}

/** The panel with a file opened: its comments are answered and the screen of the file is drawn */
function opened( locale, view ) {
	const w = drawn( locale );
	w.button( w.rows('review-file-row')[1], w.word('/_admin/review/label/open-file') ).click();
	w.answer( 200, view ?? VIEW );
	return w;
}

/** The strong and the small of a row: what it says */
function copyOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } );
}

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown, and the shell has no entry yet', w.requests.length === 0 && w.registered.length === 0 );
	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for review/list, once however often the shell shows it while the answer is out', w.requests.length === 1 && w.requests[0].action === 'review/list' && JSON.stringify( w.requests[0].payload ) === '{}' );
	const idle = attempt( function() { return w.entry().isDirty() } );
	check( '...and it registers its entry under its uri, which holds nothing unsaved while nothing is drawn', w.registered.length === 1 && w.registered[0].name === 'review' && idle.threw === false && idle.value === false );
	check( 'the namespace is the nav uri of the panel', w.panel.uri === 'review' && typeof w.panel.showCurrent === 'function' );

	w.answer( 200, { files : ROWS } );
	check( 'the screen is drawn into the one region of the template: the hint and the files', w.mount.children.length > 4 && w.all( w.mount, function( el ) { return el.className === 'nino-admin-hint' } ).length === 1 );
	w.panel.showCurrent();
	check( 'showing the pane again asks nothing: the screen is the answer of the first showing', w.requests.length === 1 );
}

{
	const w = drawn('en_US');
	const rows = w.rows('review-file-row');
	const headings = w.all( w.mount, function( el ) { return el.tagName === 'H3' || el.tagName === 'H4' } ).map( function( el ) { return el.tagName+ ' '+ el.textContent } );

	check( 'the files are listed by group and folder: a heading for each group and each folder, a row for each file in the order the server gave them, each marked with its id',
		rows.map( function( row ) { return row.dataset.id } ).join() === ROWS.map( function( row ) { return row.id } ).join()
		&& headings.join('|') === [ 'H3 staff', 'H4 Room', 'H4 '+ ROWS[2].folderName, 'H3 '+ w.word('/_admin/review/label/nogroup') ].join('|') && w.has('/_admin/review/label/nogroup') === true );
	check( '...a file says its name, its kind and how many comments it has - one in the singular, the others in the plural -, and a badge says how many are open, where some are',
		copyOf( w, rows[0] ).join('|') === [ ROWS[0].name, [ w.word('/_admin/review/kind/video'), w.said( '/_admin/review/label/count-comment', 1 ) ].join(' · ') ].join('|')
		&& copyOf( w, rows[1] ).join('|') === [ ROWS[1].name, [ w.word('/_admin/review/kind/audio'), w.said( '/_admin/review/label/count-comments', 3 ) ].join(' · ') ].join('|')
		&& w.said( '/_admin/review/label/count-comment', 1 ) !== w.said( '/_admin/review/label/count-comments', 1 ) && w.has('/_admin/review/label/count-open') === true
		&& w.all( rows[0], function( el ) { return el.classList.contains('nino-admin-badge') } ).map( function( el ) { return el.textContent } ).join() === w.said( '/_admin/review/label/count-open', 1 )
		&& w.all( rows[1], function( el ) { return el.classList.contains('nino-admin-badge') } ).length === 0 && w.all( rows[2], function( el ) { return el.classList.contains('nino-admin-badge') } )[0].textContent === w.said( '/_admin/review/label/count-open', 2 ) );
	const lists = w.all( w.mount, function( el ) { return el.tagName === 'UL' } );
	check( '...each folder is a dense list, whose rows the shell pads', lists.length === 3 && lists.filter( function( el ) { return String( el.className ).split(' ').indexOf('nino-admin-list-dense') === -1 || String( el.className ).split(' ').indexOf('nino-admin-list') === -1 } ).length === 0 );
	check( 'what the server sends is text and nothing else: a name that is markup is a string in a strong, and no element comes of it', copyOf( w, rows[1] )[0] === ROWS[1].name && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'B' || el.tagName === 'SCRIPT' } ).length === 0
		&& w.all( w.mount, function( el ) { return el.tagName === 'H4' && el.textContent === ROWS[2].folderName } ).length === 1 );
}

{
	const w = drawn( 'en_US', [] );

	check( 'a project with no comment says so, with no row', w.rows('review-file-row').length === 0 && w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/review/empty/files') } ).length === 1 && w.has('/_admin/review/empty/files') === true );

	const odd = world('en_US');
	odd.panel.showCurrent();
	const drew = attempt( function() { odd.answer( 200, {} ) } );
	check( '...and so does an answer that holds no list of files at all', drew.threw === false && odd.rows('review-file-row').length === 0 && odd.all( odd.mount, function( el ) { return el.textContent === odd.word('/_admin/review/empty/files') } ).length === 1 );
}

{
	const w = drawn('en_US');
	const open = w.button( w.rows('review-file-row')[1], w.word('/_admin/review/label/open-file') );

	open.click();
	check( 'the button of a file asks for review/comments with its id', w.last().action === 'review/comments' && JSON.stringify( w.last().payload ) === JSON.stringify( { file : ROWS[1].id } ) && w.requests.length === 2 );

	w.answer( 404, { error : 'x', code : 'review_unknown' } );
	check( '...a file that is gone says so in the sentence of its code, in its row, and the list stays', w.has('/_admin/error/review_unknown') === true && w.rows('review-file-row').length === 4
		&& w.all( w.rows('review-file-row')[1], function( el ) { return el.textContent === w.word('/_admin/error/review_unknown') } ).length > 0 && w.panel._open === '' );

	open.click();
	w.answer( 200, VIEW );
	check( '...and when it is there the screen is the file\'s: no list any more', w.rows('review-file-row').length === 0 && w.panel._open === ROWS[1].id && w.rows('review-comment-row').length === 5 );
}

console.log( '\nNino.admin.review - the file\n' );

{
	const w = opened('en_US');
	const media = w.find( w.mount, function( el ) { return el.tagName === 'AUDIO' } );
	const rows = w.rows('review-comment-row');
	const top = rows.filter( function( row ) { return String( row.className ).indexOf('review-answer-row') === -1 } );
	const heads = w.all( w.mount, function( el ) { return el.tagName === 'H3' } ).map( function( el ) { return el.textContent } );

	const classesOf = function( el ) { return String( el.className ).split(' ') };
	const commentList = w.find( w.mount, function( el ) { return el.tagName === 'UL' && classesOf( el ).indexOf('review-comments-list') !== -1 } );
	check( 'the comments are a dense list: the shell pads the rows of such a list and of no other, so that a comment has room round it', commentList !== null && classesOf( commentList ).indexOf('nino-admin-list') !== -1 && classesOf( commentList ).indexOf('nino-admin-list-dense') !== -1
		&& commentList.children.filter( function( row ) { return classesOf( row ).indexOf('review-comment-row') !== -1 } ).length === 3 );

	check( 'the file is shown under its name, with the way back, a player whose source is the address the server gave - the route of the Files feature - and the comments', heads.indexOf( w.said( '/_admin/review/label/comments-on', FILE.name ) ) !== -1
		&& media !== null && media.src === FILE.url && media.controls === true && media.preload === 'metadata' && w.button( w.mount, w.word('/_admin/review/label/back') ) !== null );
	check( '...the comments in the order the server gave them, each marked with its id, the answers under theirs', top.map( function( row ) { return row.dataset.id } ).join() === 'c00000000001,c00000000002,c00000000005' && rows.length === 5
		&& w.all( top[1], function( el ) { return el.tagName === 'UL' && el.className === 'review-answers-list' } ).length === 1 && w.all( top[1], function( el ) { return el.tagName === 'LI' } ).map( function( row ) { return row.dataset.id } ).join() === 'c00000000003,c00000000004' );

	// The head of a comment only: the answers under it are rows of their own
	const headOf = function( row ) { return row.children.find( function( el ) { return el.className === 'review-comment-head' } ) };
	const timeOf = function( row ) { return w.all( headOf( row ), function( el ) { return el.tagName === 'BUTTON' && el.classList.contains('review-time') } ).map( function( el ) { return el.textContent } ); };
	const whoOf = function( row ) { return w.find( headOf( row ), function( el ) { return el.className === 'review-who' } ).textContent; };

	check( 'a comment with a time shows it as m:ss on a button, one without shows none', timeOf( top[0] ).join() === '0:12' && timeOf( top[1] ).join() === '1:05' && timeOf( top[2] ).length === 0 && timeOf( rows[2] ).length === 0 && timeOf( rows[3] ).join() === '1:10' );
	check( '...it says who wrote it: the name, the word for the owner, the word for a member who is gone - and the day', whoOf( top[0] ) === 'Ada Lovelace' && whoOf( top[2] ) === w.word('/_admin/review/label/owner') && whoOf( rows[3] ) === w.word('/_admin/review/label/gone')
		&& w.has('/_admin/review/label/owner') === true && w.has('/_admin/review/label/gone') === true && w.all( headOf( top[0] ), function( el ) { return el.tagName === 'SMALL' } )[0].textContent === '2026-11-02' );
	check( '...the text is text: a line break is kept by the style and markup in it is no element', w.find( top[0], function( el ) { return el.className === 'review-text' } ).textContent === COMMENTS[0].text && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'B' } ).length === 0 );
	const badges = function( row ) { return w.all( headOf( row ), function( el ) { return el.classList.contains('nino-admin-badge') } ).map( function( el ) { return el.textContent+ ':'+ el.dataset.state } ); };
	check( '...a comment of a member says whether it is done or open, one of the owner says nothing of the kind', badges( top[0] )[0] === w.word('/_admin/review/state/open')+ ':warn' && badges( top[1] )[0] === w.word('/_admin/review/state/done')+ ':ok' && badges( top[2] ).length === 0 && top[1].dataset.state === 'done' && top[0].dataset.state === 'open' );

	w.button( top[0], '0:12' ).click();
	check( 'a click on a time takes the player there and plays from it - and a browser that refuses to play is no error', media.currentTime === 12.3 && media.plays === 1 );
	w.button( top[1], '1:05' ).click();
	check( '...and another time takes it there', media.currentTime === 65 && media.plays === 2 );
	check( '...in the panel a second that is none - negative, not a number, infinite - moves nothing and plays nothing either', w.panel._seek( -1 ) === false && w.panel._seek( NaN ) === false && w.panel._seek( Infinity ) === false && w.panel._seek( 0 ) === true && media.currentTime === 0 && media.plays === 3 );
}

{
	const w = opened('en_US', Object.assign( {}, VIEW, { file : Object.assign( {}, FILE, { kind : 'video' } ) } ) );
	check( 'a video file has a video element', w.find( w.mount, function( el ) { return el.tagName === 'VIDEO' } ) !== null && w.find( w.mount, function( el ) { return el.tagName === 'AUDIO' } ) === null );

	const gone = opened('en_US', Object.assign( {}, VIEW, { file : Object.assign( {}, FILE, { playable : false } ) } ) );
	const button = gone.button( gone.rows('review-comment-row')[0], '0:12' );
	button.click();
	check( 'a file whose bytes are gone has no player but says so - and its comments can be read, their times do nothing', gone.find( gone.mount, function( el ) { return el.tagName === 'AUDIO' || el.tagName === 'VIDEO' } ) === null
		&& gone.all( gone.mount, function( el ) { return el.textContent === gone.word('/_admin/review/hint/unplayable') } ).length === 1 && gone.has('/_admin/review/hint/unplayable') === true && gone.rows('review-comment-row').length === 5 && gone.panel._media === null );
	const off = opened('en_US', Object.assign( {}, VIEW, { files : false } ) );
	const permission = function( w ) { return w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/review/hint/files-permission') } ) };
	const title = off.all( off.mount, function( el ) { return el.tagName === 'H3' && el.textContent === off.said( '/_admin/review/label/comments-on', FILE.name ) } )[0];
	check( 'an account that may not play files is told so where the player would stand - under the title, over the comments, in the words of the panel - and gets no player and no button for the position; the comments and the form are there',
		off.has('/_admin/review/hint/files-permission') === true && permission( off ).length === 1 && off.mount.children.indexOf( permission( off )[0] ) === off.mount.children.indexOf( title ) + 1
		&& off.find( off.mount, function( el ) { return el.tagName === 'AUDIO' || el.tagName === 'VIDEO' } ) === null && off.panel._media === null && off.button( off.mount, off.word('/_admin/review/label/now') ) === null
		&& off.rows('review-comment-row').length === 5 && off.find( off.mount, function( el ) { return el.tagName === 'FORM' } ) !== null && off.button( off.mount, off.word('/_admin/review/label/send') ) !== null );
	const mayPlay = opened('en_US');
	const neither = opened('en_US', Object.assign( {}, VIEW, { files : false, file : Object.assign( {}, FILE, { playable : false } ) } ) );
	check( '...an account that may gets the player and no such hint, and an account that may not is told of the permission and of nothing else when the bytes are gone as well', permission( mayPlay ).length === 0 && mayPlay.find( mayPlay.mount, function( el ) { return el.tagName === 'AUDIO' } ) !== null
		&& permission( neither ).length === 1 && neither.all( neither.mount, function( el ) { return el.textContent === neither.word('/_admin/review/hint/unplayable') } ).length === 0 );
	const unsaid = opened('en_US', Object.assign( {}, VIEW, { files : undefined } ) );
	check( '...and an answer that does not say the account may is not taken for a yes: the hint, and no player', permission( unsaid ).length === 1 && unsaid.find( unsaid.mount, function( el ) { return el.tagName === 'AUDIO' || el.tagName === 'VIDEO' } ) === null );
	check( '...and the form has no button for the position of a player that is not there', gone.button( gone.mount, gone.word('/_admin/review/label/now') ) === null && gone.button( gone.mount, gone.word('/_admin/review/label/send') ) !== null );

	const empty = opened('en_US', Object.assign( {}, VIEW, { comments : [] } ) );
	check( 'a file without a comment says so, and the form is there', empty.all( empty.mount, function( el ) { return el.textContent === empty.word('/_admin/review/empty/comments') } ).length === 1 && empty.find( empty.mount, function( el ) { return el.tagName === 'FORM' } ) !== null );

	const back = opened('en_US');
	back.button( back.mount, back.word('/_admin/review/label/back') ).click();
	check( 'the way back asks for the list again - the counts have moved - and draws it', back.last().action === 'review/list' && ( back.answer( 200, { files : ROWS.slice( 0, 2 ) } ), back.rows('review-file-row').length === 2 ) && back.panel._open === '' && back.panel._view === null );
}

console.log( '\nNino.admin.review - what the owner does\n' );

{
	const w = opened('en_US');
	const media = w.find( w.mount, function( el ) { return el.tagName === 'AUDIO' } );
	const now = w.button( w.mount, w.word('/_admin/review/label/now') );
	const at = w.find( w.mount, function( el ) { return el.tagName === 'INPUT' && el.name === 'at' } );

	media.currentTime = 2.5;
	if( now !== null && at !== null )
		now.click();
	const first = at === null ? null : at.value;
	media.currentTime = 4.0;
	if( now !== null && at !== null )
		now.click();
	check( 'the position of the player is written with its tenth where it has one: at 2.5 the field reads 0:02.5, at 4.0 it reads 0:04', at !== null && first === '0:02.5' && at.value === '0:04' );
	check( '...a tenth is cut and not rounded, a position that is no time is 0:00, and what is written the server reads back to the tenth: every tenth of the first twelve minutes', w.panel._position( 59.99 ) === '0:59.9' && w.panel._position( 4.04 ) === '0:04' && w.panel._position( 65.7 ) === '1:05.7'
		&& w.panel._position( -2 ) === '0:00' && w.panel._position( NaN ) === '0:00' && w.panel._position( Infinity ) === '0:00' && ( function() {
			for( let tenths = 0; tenths <= 7200; tenths++ )
				if( w.panel._position( tenths / 10 ) !== ( Math.floor( Math.floor( tenths / 10 ) / 60 ) )+ ':'+ String( Math.floor( tenths / 10 ) % 60 ).padStart( 2, '0' )+ ( tenths % 10 === 0 ? '' : '.'+ ( tenths % 10 ) ) )
					return false;
			return true;
		} )() );
}

{
	const w = opened('en_US');
	const form = w.find( w.mount, function( el ) { return el.className === 'review-form' } );
	const text = w.find( form, function( el ) { return el.tagName === 'TEXTAREA' } );
	const at = w.find( form, function( el ) { return el.tagName === 'INPUT' && el.name === 'at' } );
	const send = w.button( form, w.word('/_admin/review/label/send') );
	const media = w.find( w.mount, function( el ) { return el.tagName === 'AUDIO' } );

	check( 'the form has the comment, as long as the server says at most, the time and the button - and the button for the position of the player', text.maxLength === 2000 && text.name === 'text' && at !== null && send.type === 'submit' && w.button( form, w.word('/_admin/review/label/now') ) !== null );

	fire( form, 'submit' );
	check( 'a comment without a text is refused before anything is sent, with the sentence for it, and the focus goes to the field', w.requests.length === 2 && w.all( form, function( el ) { return el.textContent === w.word('/_admin/review/error/empty') } ).length > 0 && text.focused === true );

	text.value = ' \n\t ';
	check( '...blanks are no comment: nothing is unsaved, and a submit of them sends nothing either', w.entry().isDirty() === false && ( fire( form, 'submit' ), w.requests.length === 2 ) );

	media.currentTime = 125.9;
	w.button( form, w.word('/_admin/review/label/now') ).click();
	check( 'the button for the position writes the position of the player into the time field, as m:ss and the tenth of a second', at.value === '2:05.9' );

	text.value = 'Good point';
	check( 'what is typed is unsaved input for the shell, and nothing before', w.entry().isDirty() === true );
	fire( form, 'submit' );
	check( 'the form posts review/answer with the file, the text, the time as typed and no parent - and gives the button back only when the answer is there', w.last().action === 'review/answer' && JSON.stringify( w.last().payload ) === JSON.stringify( { file : FILE.id, text : 'Good point', at : '2:05.9', parent : '' } ) && send.disabled === true );
	const out = w.requests.length;
	fire( form, 'submit' );
	check( '...and a second submit while the first is out sends nothing', w.requests.length === out );

	w.answer( 400, { error : 'x', code : 'review_long', params : [ 2000 ], field : 'text' } );
	check( 'a refusal is said in the sentence of its code, with the limit in it, and the form is given back with what was typed', w.has('/_admin/error/review_long') === true && w.all( form, function( el ) { return el.textContent === w.said( '/_admin/error/review_long', 2000 ) && el.textContent.indexOf('2000') !== -1 } ).length > 0
		&& send.disabled === false && text.value === 'Good point' && at.value === '2:05.9' );

	fire( form, 'submit' );
	w.answer( 400, { error : 'x', code : 'review_time', params : [], field : 'at' } );
	check( '...a time that is none is refused the same way', w.all( form, function( el ) { return el.textContent === w.word('/_admin/error/review_time') } ).length > 0 && w.has('/_admin/error/review_time') === true && text.value === 'Good point' );

	fire( form, 'submit' );
	w.answer( 200, { comment : { id : 'c00000000009' } } );
	check( 'once it is stored the form is empty, nothing is unsaved, and the comments are asked for again - the player is not drawn again, it keeps playing', text.value === '' && at.value === '' && w.entry().isDirty() === false && w.last().action === 'review/comments'
		&& JSON.stringify( w.last().payload ) === JSON.stringify( { file : FILE.id } ) );
	w.answer( 200, Object.assign( {}, VIEW, { comments : COMMENTS.concat( [ Object.assign( {}, COMMENTS[2], { id : 'c00000000009', text : 'Good point' } ) ] ) } ) );
	check( '...and the new comment is in the list, while the player is the same element, where it was', w.rows('review-comment-row').length === 6 && w.find( w.mount, function( el ) { return el.tagName === 'AUDIO' } ) === media && media.currentTime === 125.9 );
}

{
	const w = opened('en_US');
	const form = w.find( w.mount, function( el ) { return el.className === 'review-form' } );
	const text = w.find( form, function( el ) { return el.tagName === 'TEXTAREA' } );
	const rows = w.rows('review-comment-row');
	const answering = w.find( form, function( el ) { return el.className === 'review-answering' } );

	check( 'the line that says who is answered is hidden until a comment is answered', answering.hidden === true );
	w.button( rows[1], w.word('/_admin/review/label/reply') ).click();
	check( 'a button on a comment prepares the answer: the form says who is answered, in the words of the panel, and the focus goes to the text', answering.hidden === false && w.has('/_admin/review/label/answering') === true && answering.children[0].textContent === w.said( '/_admin/review/label/answering', 'Ada Lovelace' ) && text.focused === true );

	text.value = 'Answer text';
	fire( form, 'submit' );
	check( '...and the answer posts the id of that comment as the parent', JSON.stringify( w.last().payload ) === JSON.stringify( { file : FILE.id, text : 'Answer text', at : '', parent : 'c00000000002' } ) );
	w.answer( 200, { comment : { id : 'c00000000009' } } );
	check( '...and the line is gone again once it is stored', answering.hidden === true && w.panel._answering === null );

	w.answer( 200, VIEW );
	w.button( w.rows('review-comment-row')[3], w.word('/_admin/review/label/reply') ).click();
	check( 'an answer is answered with its own id, and the line says who wrote it - a member who is gone, here', JSON.stringify( [ w.panel._answering.id, w.panel._answering.who ] ) === JSON.stringify( [ 'c00000000004', w.word('/_admin/review/label/gone') ] ) );

	w.button( answering, w.word('/_admin/review/label/cancel') ).click();
	check( 'the button of the line gives it up', w.panel._answering === null && answering.hidden === true );

	// A comment that is answered and then goes: the answer cannot be sent to a parent that is not there
	w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/reply') ).click();
	w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).click();
	w.answer( 200, { deleted : 3 } );
	w.answer( 200, Object.assign( {}, VIEW, { comments : [ COMMENTS[0], COMMENTS[2] ] } ) );
	check( '...and one that is answered and then deleted, with its answers, gives the answer up by itself', w.panel._answering === null && w.find( w.mount, function( el ) { return el.className === 'review-answering' } ).hidden === true );
}

{
	const w = opened('en_US');
	const rows = w.rows('review-comment-row');

	w.button( rows[0], w.word('/_admin/review/label/mark-done') ).click();
	check( 'the button of an open comment posts review/done with the file and the id', w.last().action === 'review/done' && JSON.stringify( w.last().payload ) === JSON.stringify( { file : FILE.id, id : 'c00000000001' } ) && w.has('/_admin/review/label/mark-done') === true );
	w.answer( 200, { comment : { id : 'c00000000001', done : true } } );
	check( '...and the comments are asked for again', w.last().action === 'review/comments' );
	w.answer( 200, VIEW );

	check( 'the button of a comment that is done opens it again', w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/reopen') ) !== null && w.has('/_admin/review/label/reopen') === true && w.word('/_admin/review/label/reopen') !== w.word('/_admin/review/label/mark-done') );

	const mark = w.button( w.rows('review-comment-row')[0], w.word('/_admin/review/label/mark-done') );
	mark.click();
	const sent = w.requests.length;
	mark.click();
	check( 'a second click on the button while its request is out sends nothing, and the button waits for the answer - else the second would turn the state around again', w.requests.length === sent && w.last().action === 'review/done' && mark.disabled === true );
	w.answer( 500, { error : 'could not write' } );
	check( 'a change that fails is said in its row, with the words of the panel and the status, and nothing is asked for again', w.requests.length === 5 && w.all( w.rows('review-comment-row')[0], function( el ) { return el.textContent.indexOf( w.word('/_admin/review/error/done') ) !== -1 || el.textContent.indexOf('500') !== -1 } ).length > 0 );
	check( '...and the button of Done is given back after a refusal, so that it can be tried again', mark.disabled === false && ( mark.click(), w.requests.length === 6 && w.last().action === 'review/done' ) );
	w.answer( 200, { comment : { id : 'c00000000001', done : true } } );
	w.answer( 200, VIEW );
	check( '...and once it went through, the comments are asked for again and drawn with buttons that can be used', w.last().action === 'review/comments' && w.button( w.rows('review-comment-row')[0], w.word('/_admin/review/label/mark-done') ).disabled === false );
}

{
	const w = opened('en_US');

	w.agree( false );
	w.button( w.rows('review-comment-row')[0], w.word('/_admin/review/label/delete') ).click();
	check( 'deleting a comment asks first, in the words for a comment with no answer - a "no" sends nothing', w.asked.length === 1 && w.asked[0] === w.word('/_admin/review/confirm/comment') && w.requests.length === 2 );

	w.agree( true );
	w.button( w.rows('review-comment-row')[0], w.word('/_admin/review/label/delete') ).click();
	check( '...a "yes" posts review/delete with the file and the id, and the comments are asked for again', w.last().action === 'review/delete' && JSON.stringify( w.last().payload ) === JSON.stringify( { file : FILE.id, id : 'c00000000001' } ) );
	w.answer( 200, { deleted : 1 } );
	check( '...asked for again', w.last().action === 'review/comments' );
	w.answer( 200, VIEW );

	w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).click();
	check( 'a comment with two answers asks with the number of them', w.asked[2] === w.said( '/_admin/review/confirm/comment-answers', 2 ) && w.asked[2].indexOf('2') !== -1
		&& w.has('/_admin/review/confirm/comment-answers') === true && w.has('/_admin/review/confirm/comment-answer') === true && w.word('/_admin/review/confirm/comment-answer') !== w.word('/_admin/review/confirm/comment') );
	const sentDelete = w.requests.length;
	const askedDelete = w.asked.length;
	w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).click();
	check( 'a second click on Delete while its request is out asks nothing and sends nothing', w.requests.length === sentDelete && w.asked.length === askedDelete && w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).disabled === true );
	w.answer( 404, { error : 'x', code : 'review_unknown' } );
	check( 'a comment that is gone already says so in its row', w.all( w.rows('review-comment-row')[1], function( el ) { return el.textContent === w.word('/_admin/error/review_unknown') } ).length > 0 );
	check( '...and the button of Delete is given back after a refusal, so that it can be tried again', w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).disabled === false );
}

{
	const one = opened('en_US', Object.assign( {}, VIEW, { comments : [ Object.assign( {}, COMMENTS[1], { answers : [ COMMENTS[1].answers[0] ] } ) ] } ) );

	one.button( one.rows('review-comment-row')[0], one.word('/_admin/review/label/delete') ).click();
	check( 'a comment with one answer asks in the words for one - the sentence of none, of one and of several are three', one.asked[0] === one.word('/_admin/review/confirm/comment-answer') && one.word('/_admin/review/confirm/comment-answer') !== one.word('/_admin/review/confirm/comment')
		&& one.word('/_admin/review/confirm/comment-answer') !== one.said( '/_admin/review/confirm/comment-answers', 1 ) );
}

{
	const w = opened('en_US');
	const text = w.find( w.mount, function( el ) { return el.tagName === 'TEXTAREA' } );

	text.value = 'Not sent';
	const save = w.entry().save;
	save( function( ok ) { w.saved = ok } );
	check( 'the shell\'s Save sends what is typed, and ends in done( true ) once it is stored', w.last().action === 'review/answer' && ( w.answer( 200, { comment : { id : 'c00000000009' } } ), w.saved === true ) );
	w.answer( 200, VIEW );

	text.value = 'Refused';
	const refused = [];
	w.entry().save( function( ok ) { refused.push( ok ) } );
	w.answer( 400, { error : 'x', code : 'review_empty', params : [], field : 'text' } );
	check( '...a Save the server refuses ends in done( false ), and what was typed is still there', refused.join() === 'false' && text.value === 'Refused' && w.entry().isDirty() === true );

	text.value = 'Not sent either';
	w.entry().discard();
	check( '...Discard forgets it, and the screen holds nothing unsaved', text.value === '' && w.entry().isDirty() === false );

	const empty = [];
	w.entry().save( function( ok ) { empty.push( ok ) } );
	check( '...a Save with nothing to send ends in done( false ) and sends nothing', empty.join() === 'false' && w.requests.filter( function( request ) { return request.action === 'review/answer' } ).length === 2 );

	text.value = 'Left behind';
	w.agree( false );
	w.button( w.mount, w.word('/_admin/review/label/back') ).click();
	check( 'the way back with an unsent comment asks first - a "no" stays', w.asked[w.asked.length - 1] === w.word('/_admin/review/confirm/discard') && w.panel._open === FILE.id && w.has('/_admin/review/confirm/discard') === true );
}

console.log( '\nNino.admin.review - the words\n' );

{
	const en = opened('en_US');
	const de = opened('de_DE');

	// Every path of the screen once, in each language, and no word that was asked for is missing
	[ en, de ].forEach( function( w ) {
		const text = w.find( w.mount, function( el ) { return el.tagName === 'TEXTAREA' } );
		w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/reply') ).click();
		w.button( w.rows('review-comment-row')[1], w.word('/_admin/review/label/delete') ).click();
		w.button( w.mount, w.word('/_admin/review/label/back') ).click();
		text.value = '';
	} );

	// ...and the path of an account that may not play files, which draws a word of its own
	const offEn = opened('en_US', Object.assign( {}, VIEW, { files : false } ) );
	const offDe = opened('de_DE', Object.assign( {}, VIEW, { files : false } ) );

	check( 'every word the screen asked for is a fill of the text files, in both languages - the hint for an account that may not play files among them', en.missing.length === 0 && de.missing.length === 0 && offEn.missing.length === 0 && offDe.missing.length === 0
		&& offEn.has('/_admin/review/hint/files-permission') === true && offDe.has('/_admin/review/hint/files-permission') === true );

	const source = text('assets/admin.js');
	check( 'the script writes text and nothing else, and posts through the panel\'s call(): no innerHTML but the emptying of a region, no outerHTML, no insertAdjacentHTML, no document.write, no inline handler, no request of its own',
		( source.match( /innerHTML/g ) || [] ).length === ( source.match( /innerHTML\s*=\s*''/g ) || [] ).length && /outerHTML|insertAdjacentHTML|document\.write|\son[a-z]+\s*=|\beval\s*\(|sendRequest|XMLHttpRequest|fetch\s*\(/.test( source ) === false && source.indexOf('Nino.adminUi.panel(') !== -1 );
	check( 'the words of the two languages are not the same words', en.word('/_admin/review/hint/intro') !== de.word('/_admin/review/hint/intro') && en.word('/_admin/review/label/send') !== de.word('/_admin/review/label/send')
		&& offEn.word('/_admin/review/hint/files-permission') !== offDe.word('/_admin/review/hint/files-permission') );
}

// The refused plays of the panel and of the site were rejected promises: the event for one nobody handled comes after this turn
setImmediate( function() {
	check( 'no refused play is left unhandled: the player of the site and the one of the panel both take a promise that is rejected without a word', unhandled === 0 );
	done();
} );

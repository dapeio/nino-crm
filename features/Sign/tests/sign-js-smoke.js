/**
 *	Nino
 *	sign-js-smoke.js	What the two scripts of the Sign feature do. The panel's (admin.js) over the kernel's
 *										own Nino.admin.js: it asks for sign/list when the pane is first shown, draws the
 *										list of the requests with textContent only - the title, who is asked, how it
 *										stands, the days -, asks for sign/options once and draws the form for a new request
 *										from it (the member, the template, the free texts the template asks for, a title),
 *										posts sign/request with what the form holds and says a refusal in the sentence of its
 *										code, reminds, cancels and deletes after the question the recipe asks, opens the
 *										record of a signing under its row, links the two PDFs only by an address of the site,
 *										and tells the shell when the form holds what nobody has sent. The site's (sign.js):
 *										the pad of the signing page over a stand-in canvas - the place of a pointer, the
 *										strokes drawn as they are made, the picture that is written into the hidden field
 *										when the form is sent (dark ink, a ground that is transparent, nothing where there
 *										is no stroke), the button that empties it, the field of the typed name that is
 *										hidden while the pad holds a stroke, and a page whose canvas cannot draw, which is
 *										left to the browser. Only Nino itself is a stand-in: the requests of the panel are
 *										kept for the test to answer, and the words are the ones of the text files, in both
 *										interface languages.
 *
 *										A pin names the words it expects by their keys and not by what they say:
 *										w.said( key, ...params ) is the fill with its params put in as the panel puts them
 *										in, so that rewording a sentence breaks nothing and a screen that shows the wrong
 *										one, or none, does. The names, the titles and the dates of the fixtures are literal.
 *
 *										The helpers - check(), done(), fills(), load(), source(), findAll(), fire(),
 *										element() and documentOf() - are those of tests/harness.js of the checkout, three
 *										levels up or the one NINO_ROOT names. sign-smoke.php runs this where node is on the
 *										path.
 *
 *	Usage: node features/Sign/tests/sign-js-smoke.js
 *	       NINO_ROOT=../nino node features/Sign/tests/sign-js-smoke.js
 */

'use strict';

/* global require, __dirname, process, Buffer */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, source, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

/**
 *	A file of the feature the way load(), source() and fills() want it: from the root of the checkout, which the
 *	feature sits outside of when it is tested from the catalogue
 *
 *	@param		{string}	relative		A path below features/Sign/
 *
 *	@return		{string}
 */
function own( relative ) {
	return path.relative( root, fs.realpathSync( path.join( __dirname, '..', relative ) ) );
}


// --- The panel -----------------------------------------------------------------

/**
 *	The panel's script in a page of its own, in one interface language: the kernel's Nino.admin.js and the feature's
 *	admin.js, over a Nino that keeps the requests, the words of the text files and the questions asked. The pane has the
 *	region of templates/panel.tpl
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
	const refreshed = [];
	let agree = true;

	const mount = element('div');
	mount.id = 'sign-app';

	const page = documentOf( mount );

	const Nino = {
		content : { getText : function( key ) {
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		admin : { dirty : { register : function( name, entry ) { registered.push( { name : name, entry : entry } ) }, refresh : function() { refreshed.push( 1 ) } } },
	};

	const window = {
		Nino : Nino,
		confirm : function( text ) { asked.push( text ); return agree },
	};

	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	// The one way a panel talks to the server is the kernel's api.call(): what it is asked for is kept, to be answered
	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	load( own( 'assets/admin.js' ), context, 'admin.js' );

	const find = function( from, predicate ) { return findAll( from ?? mount, predicate )[0] ?? null };
	const has = function( el, name ) { return String( el.className ).split(/\s+/).indexOf( name ) !== -1 };

	return {
		panel : Nino.admin.sign,
		Nino : Nino,
		mount : mount,
		requests : requests,
		registered : registered,
		refreshed : refreshed,
		missing : missing,
		asked : asked,
		agree : function( yes ) { agree = yes },
		entry : function() { return registered[0].entry },
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		rows : function() { return findAll( mount, function( el ) { return el.tagName === 'LI' && has( el, 'sign-row' ) } ) },
		button : function( from, text ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		byId : function( id ) { return page.getElementById( id ) },
		field : function( name ) { return find( mount, function( el ) { return el.dataset.field === name } ) },
		word : function( key ) { return words[key] },
		// The fill with its params put in as the panel puts them in (Nino.adminUi.format) - '' for a key the text files lack, which no pin takes for the sentence
		said : function( key, ...params ) { return words[key] === undefined ? '' : Nino.adminUi.format( words[key], ...params ) },
		last : function() { return requests[requests.length - 1] },

		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
		open : function() { return requests.filter( function( request ) { return request.answered === false } ).length },
	};
}

const ADA = { name : 'Ada Lovelace', email : 'ada@example.org', label : 'Ada Lovelace' };
const BOB = { name : '', email : 'bob@example.org', label : 'bob@example.org' };
const NOBODY = { name : '', email : '', label : '' };
const HASH_A = 'a'.repeat(32)+ 'b'.repeat(32);
const HASH_B = 'c'.repeat(32)+ 'd'.repeat(32);

/** The address the list gives the two PDFs of a request: below the directory of the project, as Admin::apiList() writes it */
function urls( id ) {
	return { shown : '/club/.sign/pdf?r='+ id+ '&kind=shown', signed : '/club/.sign/pdf?r='+ id+ '&kind=signed' };
}

/** One request as sign/list gives it, with what a still-open one has: the time it is good until, no hash of a signed document */
function request( id, status, more ) {
	return Object.assign( {
		id : id, title : 'Rental agreement', template : 'contract-one', templateName : 'Contract One', account : 'aaaaaaaaaaaa', member : ADA,
		status : status, created : '2026-11-02T10:15:30Z', viewed : null, signed : null, declined : null, expires : Date.UTC( 2026, 10, 9, 10, 15, 30 ) / 1000,
		hashShown : HASH_A, hashSigned : '', method : '', record : { ip : '', agent : '', consent : '' },
		files : { shown : true, signed : false }, urls : urls( id ),
	}, more ?? {} );
}

const WAITING = request( '000000000001', 'requested', { title : 'Rental <b>agreement</b>' } );
const OPENED = request( '000000000002', 'viewed', { member : BOB, viewed : '2026-11-02T11:00:00Z' } );
const SIGNED = request( '000000000003', 'signed', {
	member : ADA, created : '2026-10-31T08:00:00Z', viewed : '2026-11-01T09:00:00Z', signed : '2026-11-01T09:05:00Z', hashSigned : HASH_B, method : 'drawn',
	record : { ip : '203.0.113.7', agent : 'Mozilla/5.0 <script>x()</script>', consent : 'I have read it and sign it.' }, files : { shown : true, signed : true },
} );
const DECLINED = request( '000000000004', 'declined', { declined : '2026-10-30T08:00:00Z' } );
const CANCELLED = request( '000000000005', 'cancelled', { member : NOBODY } );
const EXPIRED = request( '000000000006', 'expired', { files : { shown : true, signed : false } } );
const LIST = { requests : [ WAITING, OPENED, SIGNED, DECLINED, CANCELLED, EXPIRED ], days : 21 };

const INPUTS = [ { name : 'rent', label : 'Rent', multiline : false, required : true }, { name : 'note', label : '', multiline : true, required : false } ];
const OPTIONS = {
	accounts : [ { id : 'aaaaaaaaaaaa', name : 'Ada Lovelace', email : 'ada@example.org', group : 'staff' }, { id : 'bbbbbbbbbbbb', name : '', email : 'bob@example.org', group : 'staff' }, { id : 'cccccccccccc', name : '<img src=x onerror=alert(1)>', email : 'x@example.org', group : '' } ],
	templates : [ { key : 'contract-one', name: 'Contract One', tag : 'contract', inputs : INPUTS }, { key : 'plain', name : 'Plain', tag : 'privacy', inputs : [] } ],
	limits : { title : 120, input : 5000 },
};

/** The panel shown, its list answered and its form drawn */
function drawn( locale, list, options ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, list ?? LIST );
	w.answer( 200, options ?? OPTIONS );
	return w;
}

/** What a row says in its text: the title, the member and the template, the dates */
function copyOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } );
}

/** The labels of the buttons of a row, in the order they stand, and of its links */
function buttonsOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'BUTTON' } ).map( function( el ) { return el.textContent } );
}

function linksOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'A' } );
}

/** The state badge of a row */
function badgeOf( w, row ) {
	return w.find( row, function( el ) { return String( el.className ).split(/\s+/).indexOf('nino-admin-badge') !== -1 } );
}

/** The live line of a row (the last of its actions) */
function lineOf( w, row ) {
	return w.find( row, function( el ) { return String( el.className ).split(/\s+/).indexOf('nino-admin-status') !== -1 } );
}

/** Fill the form: the member, the template and - when the template draws them - the free texts */
function fillIn( w, account, template, inputs ) {
	const form = w.byId('sign-ask');
	const select = function( name ) { return w.find( form, function( el ) { return el.tagName === 'SELECT' && el.name === name } ) };

	select('account').value = account;
	fire( select('account'), 'change' );
	select('template').value = template;
	fire( select('template'), 'change' );

	Object.keys( inputs ?? {} ).forEach( function( name ) { w.field( 'input-'+ name ).value = inputs[name] } );

	return { form : form, account : select('account'), template : select('template'), title : w.byId('sign-ask-title'), submit : w.byId('sign-ask-submit') };
}


console.log( 'Nino.admin.sign - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown, and the shell has no entry yet', w.requests.length === 0 && w.registered.length === 0 );

	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for sign/list, once however often the shell shows it while the answer is out', w.requests.length === 1 && w.requests[0].action === 'sign/list' && JSON.stringify( w.requests[0].payload ) === '{}' );
	check( '...and it registers its entry under its uri, which holds nothing unsaved while nothing is drawn', w.registered.length === 1 && w.registered[0].name === 'sign' && w.entry().isDirty() === false );
	check( 'the namespace is the nav uri of the panel', w.panel.uri === 'sign' && typeof w.panel.showCurrent === 'function' );

	w.answer( 200, LIST );
	check( 'the answer draws the two regions of the screen, the form above the list, in the one mount of the template', w.mount.children.length === 2 && w.mount.children[0].id === 'sign-new' && w.mount.children[1].id === 'sign-rows' );
	check( '...and the form asks the server what it offers - sign/options, no payload - while the list is already there', w.requests.length === 2 && w.last().action === 'sign/options' && JSON.stringify( w.last().payload ) === '{}' && w.rows().length === 6 );

	w.answer( 200, OPTIONS );
	check( 'the options draw the form into its region: a hint of what it does, then the form with the member, the template, the title and the button', w.byId('sign-ask') !== null
		&& w.all( w.byId('sign-new'), function( el ) { return el.className === 'nino-admin-hint' } )[0].textContent === w.word('/_admin/sign/hint/intro')
		&& w.all( w.byId('sign-ask'), function( el ) { return el.tagName === 'SELECT' } ).length === 2 && w.byId('sign-ask-title') !== null && w.byId('sign-ask-submit').textContent === w.word('/_admin/sign/label/ask') );

	w.panel.showCurrent();
	check( 'showing the pane again asks nothing: the screen is the answer of the first showing', w.requests.length === 2 );
	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

console.log( '\nNino.admin.sign - what the rows say\n' );

{
	const w = drawn('en_US');
	const rows = w.rows();

	check( 'one row for each request in the order the server gave them, each marked with its id', rows.map( function( row ) { return row.dataset.id } ).join() === LIST.requests.map( function( each ) { return each.id } ).join() );
	check( 'a row says the title, who is asked with the template, and the times it knows - each with the word of its kind', copyOf( w, rows[0] ).join('|') === [ 'Rental <b>agreement</b>', 'Ada Lovelace · Contract One', w.word('/_admin/sign/label/created')+ ' 2026-11-02 10:15 UTC' ].join('|')
		&& copyOf( w, rows[2] )[2] === [ w.word('/_admin/sign/label/created')+ ' 2026-10-31 08:00 UTC', w.word('/_admin/sign/label/viewed')+ ' 2026-11-01 09:00 UTC', w.word('/_admin/sign/label/signed')+ ' 2026-11-01 09:05 UTC' ].join(' · ')
		&& copyOf( w, rows[3] )[2] === [ w.word('/_admin/sign/label/created')+ ' 2026-11-02 10:15 UTC', w.word('/_admin/sign/label/declined')+ ' 2026-10-30 08:00 UTC' ].join(' · ') );
	check( '...a member without a name is named by the address, one who is gone by the word for it', copyOf( w, rows[1] )[1] === 'bob@example.org · Contract One' && copyOf( w, rows[4] )[1] === w.word('/_admin/sign/label/member-gone')+ ' · Contract One' );
	check( 'what the server sends is text and nothing else: a title that is markup is a string in a strong, and no element comes of it', copyOf( w, rows[0] )[0] === 'Rental <b>agreement</b>'
		&& w.all( w.mount, function( el ) { return el.tagName === 'B' || el.tagName === 'IMG' || el.tagName === 'SCRIPT' } ).length === 0 );

	check( 'a request says how it stands in a badge, in the word of its state', [ 'requested', 'viewed', 'signed', 'declined', 'cancelled', 'expired' ].every( function( state, at ) { return badgeOf( w, rows[at] ).textContent === w.word('/_admin/sign/state/'+ state) } ) );
	check( '...whose colour says it too: signed is ok, a request that waits is a warning, declined an error, the rest are muted', [ 'ok', 'warn', 'warn', 'error', 'muted', 'muted' ].join() === [ 2, 0, 1, 3, 4, 5 ].map( function( at ) { return badgeOf( w, rows[at] ).dataset.state } ).join()
		&& w.word('/_admin/sign/state/requested') !== w.word('/_admin/sign/state/viewed') );

	check( 'a request that waits - asked, opened or run out - has Remind and Cancel; one that is over - signed, declined, cancelled - has neither', [ 0, 1, 5 ].every( function( at ) {
		return w.button( rows[at], w.word('/_admin/sign/label/remind') ) !== null && w.button( rows[at], w.word('/_admin/sign/label/cancel') ) !== null;
	} ) && [ 2, 3, 4 ].every( function( at ) { return w.button( rows[at], w.word('/_admin/sign/label/remind') ) === null && w.button( rows[at], w.word('/_admin/sign/label/cancel') ) === null } ) );
	check( '...every request has the record and Delete, after them: Remind, Cancel, the record, Delete', buttonsOf( w, rows[0] ).join('|') === [ 'remind', 'cancel', 'details', 'delete' ].map( function( name ) { return w.word('/_admin/sign/label/'+ name) } ).join('|')
		&& buttonsOf( w, rows[2] ).join('|') === [ 'details', 'delete' ].map( function( name ) { return w.word('/_admin/sign/label/'+ name) } ).join('|') );

	const open = linksOf( w, rows[2] );
	check( 'a signed request links both PDFs, to the addresses of the list, in a tab of their own and without handing the page over', open.length === 2 && open[0].textContent === w.word('/_admin/sign/label/shown') && open[1].textContent === w.word('/_admin/sign/label/signed-document')
		&& open[0].href === SIGNED.urls.shown && open[1].href === SIGNED.urls.signed && open.every( function( a ) { return a.target === '_blank' && a.rel === 'noopener' } ) );
	check( '...one that has only the document shown links that one only - the link is drawn for a file there is', linksOf( w, rows[0] ).length === 1 && linksOf( w, rows[0] )[0].href === WAITING.urls.shown );

	const bad = [ '//evil.example/x', 'javascript:alert(1)', 'https://evil.example/', '/club\\evil', '/club/a b', '', null, 5 ];
	const lists = bad.map( function( url ) { return request( '1000000000'+ String( bad.indexOf( url ) ).padStart( 2, '0' ), 'requested', { urls : { shown : url, signed : url }, files : { shown : true, signed : true } } ) } );
	const unsafe = drawn( 'en_US', { requests : lists, days : 14 } );
	check( 'an address that is not a path of this site - another host, a scheme, a backslash, a space, nothing - makes no link', unsafe.rows().length === bad.length && unsafe.all( unsafe.mount, function( el ) { return el.tagName === 'A' } ).length === 0 );

	const none = drawn( 'en_US', { requests : [], days : 14 } );
	check( 'a project without a request says so, in the word for it, and draws no list', none.all( none.byId('sign-rows'), function( el ) { return el.textContent === none.word('/_admin/sign/empty') } ).length === 1 && none.rows().length === 0 );
	check( '...and no word was asked of the fills that the text files lack', w.missing.length === 0 && none.missing.length === 0 && unsafe.missing.length === 0 );
}

console.log( '\nNino.admin.sign - the record of a signing\n' );

{
	const w = drawn('en_US');
	const signed = function() { return w.rows()[2] };
	const terms = function( row ) { return w.all( row, function( el ) { return el.tagName === 'DT' || el.tagName === 'DD' } ).map( function( el ) { return el.textContent } ) };

	check( 'the record is closed on every row until it is asked for', w.all( w.mount, function( el ) { return el.tagName === 'DL' } ).length === 0 );

	w.button( signed(), w.word('/_admin/sign/label/details') ).click();
	check( 'the record button opens it under its row: the time the link was good until, the address, the browser, how it was signed, the sentence agreed to and the hashes of the two documents - each a term and its text',
		terms( signed() ).join('|') === [ [ 'expires', '2026-11-09 10:15 UTC' ], [ 'address', '203.0.113.7' ], [ 'agent', 'Mozilla/5.0 <script>x()</script>' ], [ 'method', w.word('/_admin/sign/method/drawn') ], [ 'consent', 'I have read it and sign it.' ], [ 'hash-shown', HASH_A ], [ 'hash-signed', HASH_B ] ]
			.map( function( term ) { return [ w.word('/_admin/sign/label/'+ term[0]), term[1] ] } ).reduce( function( all, pair ) { return all.concat( pair ) }, [] ).join('|') );
	check( '...what the browser said stays text, and the button now hides the record', w.all( w.mount, function( el ) { return el.tagName === 'SCRIPT' } ).length === 0 && w.button( signed(), w.word('/_admin/sign/label/hide') ) !== null && w.button( signed(), w.word('/_admin/sign/label/details') ) === null );
	check( '...only the row asked is opened', w.all( w.mount, function( el ) { return el.tagName === 'DL' } ).length === 1 );

	w.button( w.rows()[0], w.word('/_admin/sign/label/details') ).click();
	check( 'another record opened closes the first: one at a time', w.all( w.mount, function( el ) { return el.tagName === 'DL' } ).length === 1 && w.all( w.rows()[0], function( el ) { return el.tagName === 'DL' } ).length === 1
		&& terms( w.rows()[0] ).join('|') === [ w.word('/_admin/sign/label/expires'), '2026-11-09 10:15 UTC', w.word('/_admin/sign/label/hash-shown'), HASH_A ].join('|') );

	w.button( w.rows()[0], w.word('/_admin/sign/label/hide') ).click();
	check( 'the record of a request that is not signed holds what there is - the time the link is good until and the hash of the document shown, no empty term - and the hide button closes it', w.all( w.mount, function( el ) { return el.tagName === 'DL' } ).length === 0 );

	// The record stays open across the list being asked for again, and closes when its request is gone
	w.button( signed(), w.word('/_admin/sign/label/details') ).click();
	w.panel.reload();
	w.answer( 200, LIST );
	check( 'a list that is drawn again keeps the record open', w.all( signed(), function( el ) { return el.tagName === 'DL' } ).length === 1 );
	w.panel.reload();
	w.answer( 200, { requests : [ WAITING ], days : 14 } );
	w.panel.reload();
	w.answer( 200, LIST );
	check( '...and one whose request is gone is not opened again when the request comes back', w.all( w.mount, function( el ) { return el.tagName === 'DL' } ).length === 0 );
	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

console.log( '\nNino.admin.sign - remind, cancel, delete\n' );

{
	const w = drawn('en_US');
	const rows = w.rows();
	const before = w.requests.length;

	w.button( rows[0], w.word('/_admin/sign/label/remind') ).click();
	check( 'Remind posts sign/remind with the id of the request and nothing else, and the line of the row says it is on its way', w.last().action === 'sign/remind' && JSON.stringify( w.last().payload ) === '{"id":"000000000001"}'
		&& lineOf( w, rows[0] ).dataset.state === 'saving' && w.requests.length === before + 1 );

	w.answer( 200, { id : '000000000001' } );
	check( '...an answer says the link is sent again in the line, and the list is asked for again', lineOf( w, rows[0] ).dataset.state === 'idle' && lineOf( w, rows[0] ).textContent === w.word('/_admin/sign/msg/reminded')
		&& w.last().action === 'sign/list' && w.open() === 1 );
	w.answer( 200, LIST );

	w.button( w.rows()[1], w.word('/_admin/sign/label/remind') ).click();
	w.answer( 429, { error : 'x', code : 'sign_ratelimit' } );
	check( 'a reminder that is refused is told in the sentence of its code, and the list is not asked for again', lineOf( w, w.rows()[1] ).dataset.state === 'error' && lineOf( w, w.rows()[1] ).textContent === w.said('/_admin/error/sign_ratelimit') && w.word('/_admin/error/sign_ratelimit') !== undefined && w.open() === 0 );

	w.button( w.rows()[1], w.word('/_admin/sign/label/remind') ).click();
	w.answer( 500, {} );
	check( '...and one that fails with a code the workbench does not know is told in the panel\'s own sentence with the status in front', lineOf( w, w.rows()[1] ).textContent === '(500) '+ w.word('/_admin/sign/error/remind') );

	const asked = w.asked.length;
	w.button( w.rows()[0], w.word('/_admin/sign/label/cancel') ).click();
	check( 'Cancel asks first, by name: the sentence of the question with the member in it', w.asked.length === asked + 1 && w.asked[asked] === w.said( '/_admin/sign/confirm/cancel', 'Ada Lovelace' ) && w.word('/_admin/sign/confirm/cancel') !== undefined );
	check( '...and posts sign/cancel with the id once it is confirmed, and asks for the list again when the server answers', w.last().action === 'sign/cancel' && JSON.stringify( w.last().payload ) === '{"id":"000000000001"}' );
	w.answer( 200, { id : '000000000001' } );
	check( '...so that the list shows the new state', w.last().action === 'sign/list' && w.open() === 1 );
	w.answer( 200, LIST );

	w.agree( false );
	const posted = w.requests.length;
	w.button( w.rows()[0], w.word('/_admin/sign/label/cancel') ).click();
	w.button( w.rows()[0], w.word('/_admin/sign/label/delete') ).click();
	check( 'a question that is answered no posts nothing - not for Cancel and not for Delete', w.requests.length === posted && w.asked.length === asked + 3 );
	w.agree( true );

	w.button( w.rows()[0], w.word('/_admin/sign/label/cancel') ).click();
	w.answer( 409, { error : 'x', code : 'sign_state' } );
	check( 'a cancel that is refused because the request is over is told in the sentence of its code', lineOf( w, w.rows()[0] ).dataset.state === 'error' && lineOf( w, w.rows()[0] ).textContent === w.said('/_admin/error/sign_state') );

	w.button( w.rows()[2], w.word('/_admin/sign/label/delete') ).click();
	check( 'Delete asks first, by name - for any request, a signed one among them -, and posts sign/delete with the id', w.asked[w.asked.length - 1] === w.said( '/_admin/sign/confirm/delete', 'Ada Lovelace' ) && w.last().action === 'sign/delete' && JSON.stringify( w.last().payload ) === '{"id":"000000000003"}' );
	w.answer( 404, { error : 'x', code : 'sign_unknown' } );
	check( '...a request that is gone is told in the sentence of its code', lineOf( w, w.rows()[2] ).textContent === w.said('/_admin/error/sign_unknown') );
	w.button( w.rows()[2], w.word('/_admin/sign/label/delete') ).click();
	w.answer( 200, { id : '000000000003' } );
	check( '...and one that went through asks for the list again', w.last().action === 'sign/list' );
	w.answer( 200, { requests : LIST.requests.filter( function( each ) { return each.id !== '000000000003' } ), days : 21 } );
	check( '...which no longer has the row', w.rows().length === 5 && w.rows().every( function( row ) { return row.dataset.id !== '000000000003' } ) );

	w.button( w.rows()[3], w.word('/_admin/sign/label/delete') ).click();
	check( 'the question for a member who is gone names the word for it', w.asked[w.asked.length - 1] === w.said( '/_admin/sign/confirm/delete', w.word('/_admin/sign/label/member-gone') ) );
	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

console.log( '\nNino.admin.sign - the form for a new request\n' );

{
	const w = drawn('en_US');
	const accounts = w.all( w.byId('sign-ask'), function( el ) { return el.tagName === 'SELECT' && el.name === 'account' } )[0];
	const templates = w.all( w.byId('sign-ask'), function( el ) { return el.tagName === 'SELECT' && el.name === 'template' } )[0];
	const labels = function( select ) { return select.children.map( function( option ) { return option.textContent } ) };

	check( 'the member is chosen from the active accounts - the name with the address, the address alone where there is no name - after the word to choose', labels( accounts ).join('|') === [ w.word('/_admin/sign/label/choose'), 'Ada Lovelace (ada@example.org)', 'bob@example.org', '<img src=x onerror=alert(1)> (x@example.org)' ].join('|')
		&& accounts.children.map( function( option ) { return option.value } ).join() === ',aaaaaaaaaaaa,bbbbbbbbbbbb,cccccccccccc' && accounts.value === '' );
	check( '...a name that is markup stays text: no element comes of it, and the option says the characters as they were written', w.all( w.mount, function( el ) { return el.tagName === 'IMG' } ).length === 0 && labels( accounts )[3] === '<img src=x onerror=alert(1)> (x@example.org)' );
	check( 'the template is chosen from the templates the server offers, by the name it gives', labels( templates ).join('|') === [ w.word('/_admin/sign/label/choose'), 'Contract One', 'Plain' ].join('|') && templates.children.map( function( option ) { return option.value } ).join() === ',contract-one,plain' );
	check( 'the title is limited to what the server takes, and says what it is left empty for; the days the link is good for are told', w.byId('sign-ask-title').maxLength === 120 && w.byId('sign-ask-title').name === 'title'
		&& w.all( w.byId('sign-ask'), function( el ) { return el.tagName === 'SMALL' && el.textContent === w.word('/_admin/sign/hint/title') } ).length === 1
		&& w.all( w.byId('sign-ask'), function( el ) { return el.className === 'nino-admin-hint' && el.textContent === w.said( '/_admin/sign/hint/days', 21 ) } ).length === 1 );
	check( 'no free text is drawn until a template is chosen', w.all( w.byId('sign-inputs'), function() { return true } ).length === 0 );

	templates.value = 'contract-one';
	fire( templates, 'change' );
	const area = w.all( w.byId('sign-inputs'), function( el ) { return el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' } );
	check( 'a template draws the free texts it asks for, in its order: a line for one, a box for a multiline one, each named by its label or - without one - by its name, each limited as the server limits it',
		area.map( function( el ) { return el.tagName+ ':'+ el.name } ).join() === 'INPUT:input-rent,TEXTAREA:input-note' && area.every( function( el ) { return el.maxLength === 5000 && el.dataset.field === el.name } )
		&& w.all( w.byId('sign-inputs'), function( el ) { return el.tagName === 'SPAN' } ).map( function( el ) { return el.textContent } ).filter( function( text ) { return text !== '' } ).join() === 'Rent,*,note' );
	check( '...a required one is marked for the eye and for a reader, an optional one is not', area[0].required === true && area[0].attributes['aria-required'] === 'true' && area[1].required !== true && area[1].attributes['aria-required'] === undefined );

	templates.value = 'plain';
	fire( templates, 'change' );
	check( 'another template takes its place: one without free texts leaves none', w.all( w.byId('sign-inputs'), function() { return true } ).length === 0 );
	templates.value = '';
	fire( templates, 'change' );
	check( '...and so does choosing nothing', w.all( w.byId('sign-inputs'), function() { return true } ).length === 0 );
	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

{
	const w = drawn('en_US');
	const before = w.requests.length;
	let f = fillIn( w, '', '', {} );

	fire( f.form, 'submit' );
	check( 'a form sent with no member says so in its line, puts the focus on the member and sends nothing', w.requests.length === before && f.form.querySelector('.nino-admin-status').dataset.state === 'error'
		&& f.form.querySelector('.nino-admin-status').textContent === w.word('/_admin/sign/error/account') && f.account.focused === true );

	f = fillIn( w, 'aaaaaaaaaaaa', '', {} );
	fire( f.form, 'submit' );
	check( '...with no template it says that and sends nothing', w.requests.length === before && f.form.querySelector('.nino-admin-status').textContent === w.word('/_admin/sign/error/template') && f.template.focused === true );

	f = fillIn( w, 'aaaaaaaaaaaa', 'contract-one', {} );
	fire( f.form, 'submit' );
	check( '...with a text the template asks for left empty it names the text - by its label - and puts the focus there', w.requests.length === before && f.form.querySelector('.nino-admin-status').textContent === w.said( '/_admin/sign/error/input', 'Rent' ) && w.field('input-rent').focused === true );

	w.field('input-rent').value = '   ';
	fire( f.form, 'submit' );
	check( '...a text of blanks is as empty as none', w.requests.length === before && f.form.querySelector('.nino-admin-status').textContent === w.said( '/_admin/sign/error/input', 'Rent' ) );

	w.field('input-rent').value = '1200 euros';
	w.field('input-note').value = 'Second floor';
	f.title.value = 'My own title';
	fire( f.form, 'submit' );
	check( 'a complete form posts sign/request with the member, the template, the texts by their names - the empty optional one among them - and the title, and the button waits for the answer', w.last().action === 'sign/request'
		&& JSON.stringify( w.last().payload ) === '{"account":"aaaaaaaaaaaa","template":"contract-one","inputs":{"rent":"1200 euros","note":"Second floor"},"title":"My own title"}' && f.submit.disabled === true
		&& f.form.querySelector('.nino-admin-status').dataset.state === 'saving' );

	fire( f.form, 'submit' );
	check( '...a second send while the first is out posts nothing', w.requests.length === before + 1 );

	w.answer( 200, { id : '000000000009' } );
	check( '...an answer says the request is made, empties what was typed - the title and the texts - and gives the button back', f.form.querySelector('.nino-admin-status').dataset.state === 'idle' && f.form.querySelector('.nino-admin-status').textContent === w.word('/_admin/sign/msg/asked')
		&& f.title.value === '' && w.field('input-rent').value === '' && w.field('input-note').value === '' && f.submit.disabled === false );
	check( '...the member and the template stay chosen, the list is asked for again, and the shell is told the form is clean', f.account.value === 'aaaaaaaaaaaa' && f.template.value === 'contract-one' && w.last().action === 'sign/list' && w.refreshed.length > 0 && w.entry().isDirty() === false );
	w.answer( 200, LIST );
	check( 'the list drawn again leaves the form as it is: it is not asked for again, and the line of the form still has its words', w.requests.filter( function( request ) { return request.action === 'sign/options' } ).length === 1 && w.byId('sign-ask') === f.form
		&& f.form.querySelector('.nino-admin-status').textContent === w.word('/_admin/sign/msg/asked') );

	w.field('input-rent').value = '300';
	fire( f.form, 'submit' );
	check( 'a text the template does not need that is left empty is posted empty - not left out, not filled in - and a title left empty is posted empty too', w.last().action === 'sign/request' && JSON.stringify( w.last().payload ) === '{"account":"aaaaaaaaaaaa","template":"contract-one","inputs":{"rent":"300","note":""},"title":""}' );
	w.answer( 400, { error : 'x', code : 'sign_template' } );
}

{
	const w = drawn('en_US');
	const f = fillIn( w, 'aaaaaaaaaaaa', 'contract-one', { rent : '1' } );

	fire( f.form, 'submit' );
	w.answer( 400, { error : 'x', code : 'sign_input', params : [ 'Rent', 5000 ], field : 'input-rent' } );
	check( 'a refusal of a text is told in the sentence of its code with the params in it, and the field the server named is marked and focused', f.form.querySelector('.nino-admin-status').dataset.state === 'error'
		&& f.form.querySelector('.nino-admin-status').textContent === w.said( '/_admin/error/sign_input', 'Rent', 5000 ) && w.field('input-rent').attributes['aria-invalid'] === 'true' && w.field('input-rent').focused === true
		&& f.submit.disabled === false && w.field('input-rent').value === '1' );
	check( '...nothing typed is lost to it, and the list is not asked for again', f.title.value === '' && w.open() === 0 && w.last().action === 'sign/request' );

	fire( f.form, 'submit' );
	w.answer( 500, { error : 'x', code : 'sign_mail' } );
	check( 'a request whose mail could not be sent is told so in the sentence of its code - and is there: the list is asked for again', f.form.querySelector('.nino-admin-status').textContent === w.said('/_admin/error/sign_mail') && w.last().action === 'sign/list' && w.open() === 1 );
	w.answer( 200, LIST );

	fire( f.form, 'submit' );
	w.answer( 429, { error : 'x', code : 'sign_ratelimit' } );
	check( '...so is one that the kernel\'s cap of mails refused', f.form.querySelector('.nino-admin-status').textContent === w.said('/_admin/error/sign_ratelimit') && w.last().action === 'sign/list' && w.open() === 1 );
	w.answer( 200, LIST );

	fire( f.form, 'submit' );
	w.answer( 400, { error : 'x', code : 'sign_account', field : 'account' } );
	check( 'a refusal of the member is told in the sentence of its code, and does not ask for the list', f.form.querySelector('.nino-admin-status').textContent === w.said('/_admin/error/sign_account') && w.last().action === 'sign/request' && w.open() === 0 );

	fire( f.form, 'submit' );
	w.answer( 500, {} );
	check( 'a failure with no code is told in the panel\'s own sentence with the status in front', f.form.querySelector('.nino-admin-status').textContent === '(500) '+ w.word('/_admin/sign/error/ask') );
	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

console.log( '\nNino.admin.sign - what is typed\n' );

{
	const w = drawn('en_US');
	const f = fillIn( w, '', '', {} );

	check( 'a form that holds nothing is clean, to the shell and to its own line, which has said nothing yet', w.entry().isDirty() === false && f.form.querySelector('.nino-admin-status').dataset.state === undefined );

	f.title.value = 'Half a title';
	f.title.dispatchEvent( { type : 'input', bubbles : true } );
	check( 'a title typed makes the entry unsaved changes, and the line of the form says so', w.entry().isDirty() === true && f.form.querySelector('.nino-admin-status').dataset.state === 'dirty' );

	w.entry().discard();
	check( 'the shell\'s Discard forgets what was typed', f.title.value === '' && w.entry().isDirty() === false );

	const g = fillIn( w, '', 'contract-one', { rent : '12' } );
	check( 'a text of the template that is typed makes it unsaved as well, and Discard forgets that too', w.entry().isDirty() === true );
	w.entry().discard();
	check( '...and it is the one form all along', w.field('input-rent').value === '' && w.entry().isDirty() === false && g.form === f.form );

	let verdict = null;
	const sent = w.requests.length;
	w.entry().save( function( ok ) { verdict = ok } );
	check( 'the shell\'s Save of a form that cannot be sent - no member chosen - ends in done( false ) and sends nothing', verdict === false && w.requests.length === sent );

	fillIn( w, 'aaaaaaaaaaaa', 'contract-one', { rent : '7' } );
	const posted = w.requests.length;
	verdict = null;
	w.entry().save( function( ok ) { verdict = ok } );
	check( 'the shell\'s Save of a form that is complete sends it, and has no verdict before the server has answered', w.requests.length === posted + 1 && w.last().action === 'sign/request' && verdict === null );
	w.answer( 200, { id : '00000000000a' } );
	check( '...the answer of the server decides: done( true ) once it has made the request', verdict === true );

	fillIn( w, 'aaaaaaaaaaaa', 'contract-one', { rent : '8' } );
	verdict = null;
	w.entry().save( function( ok ) { verdict = ok } );
	w.answer( 400, { error : 'x', code : 'sign_template' } );
	check( '...and in done( false ) where the server refused it', verdict === false );
}

console.log( '\nNino.admin.sign - what the form has nothing to offer\n' );

{
	const w = drawn( 'en_US', LIST, Object.assign( {}, OPTIONS, { accounts : [] } ) );

	check( 'a project with no active member says so and draws no form', w.byId('sign-ask') === null && w.all( w.byId('sign-new'), function( el ) { return el.textContent === w.word('/_admin/sign/hint/members') } ).length === 1 );

	const none = drawn( 'en_US', LIST, Object.assign( {}, OPTIONS, { templates : [] } ) );
	check( 'one with no template that can be asked for says that, and draws no form', none.byId('sign-ask') === null && none.all( none.byId('sign-new'), function( el ) { return el.textContent === none.word('/_admin/sign/hint/none') } ).length === 1 );
	check( '...the list is there all the same', none.rows().length === 6 && w.rows().length === 6 );

	const failed = world('en_US');
	failed.panel.showCurrent();
	failed.answer( 200, LIST );
	failed.answer( 500, {} );
	check( 'a form that could not be loaded says so in its region, and the list is there all the same', failed.all( failed.byId('sign-new'), function( el ) { return el.className === 'nino-admin-error' && el.textContent === '(500) '+ failed.word('/_admin/sign/error/load') } ).length === 1 && failed.rows().length === 6 );
}

{
	const w = world('en_US');
	w.panel.showCurrent();
	w.answer( 500, {} );
	check( 'a list that could not be loaded says so in the mount, in the panel\'s own sentence', w.mount.children.length === 1 && w.mount.children[0].className === 'nino-admin-error' && w.mount.children[0].textContent === '(500) '+ w.word('/_admin/sign/error/load') );

	w.panel.showCurrent();
	check( '...and showing the pane again asks again', w.last().action === 'sign/list' && w.open() === 1 );
	w.answer( 200, LIST );
	check( '...whose answer draws the screen and asks for the form again, since its region went with the error', w.byId('sign-rows') !== null && w.last().action === 'sign/options' );
}

{
	// A change that is saved while the list is out is not in that answer: it is asked for again
	const w = drawn('en_US');
	const f = fillIn( w, 'aaaaaaaaaaaa', 'plain', {} );

	w.panel.reload();
	fire( f.form, 'submit' );
	check( 'a list that is out and a request that is sent are both out', w.open() === 2 );
	w.answer( 200, { id : '00000000000b' } );
	check( 'a request that is made while the list is out does not ask for it at once: a panel declines a load while one is out', w.open() === 1 && w.requests.filter( function( request ) { return request.action === 'sign/list' } ).length === 2 );
	w.answer( 200, LIST );
	check( '...it asks for the list again once the one that was out has come back, which was asked for before the change', w.requests.filter( function( request ) { return request.action === 'sign/list' } ).length === 3 && w.open() === 1 );
	w.answer( 200, LIST );
	check( '...and once only', w.open() === 0 && w.requests.filter( function( request ) { return request.action === 'sign/list' } ).length === 3 );

	// A list that failed forgets the wish: it is not asked for in a loop
	const v = drawn('en_US');
	const g = fillIn( v, 'aaaaaaaaaaaa', 'plain', {} );
	v.panel.reload();
	fire( g.form, 'submit' );
	v.answer( 200, { id : '00000000000c' } );
	v.answer( 500, {} );
	check( 'a list that failed does not ask for itself again', v.open() === 0 );

	// ...and the wish is gone for good: the list that is asked for next is drawn, and nothing is asked on top of it
	v.panel.reload();
	v.answer( 200, LIST );
	// What is out then is the form, whose region went with the error: asked for by the screen that is drawn again - not a second list
	check( '...nor does it ask again once the next list has come: it is drawn, and the one thing out is the form the error took away - no second list', v.rows().length === 6 && v.open() === 1 && v.last().action === 'sign/options'
		&& v.requests.filter( function( request ) { return request.action === 'sign/list' && request.answered === false } ).length === 0 );
}

console.log( '\nNino.admin.sign - a request that is changed\n' );

{
	const CHANGED = request( '00000000000d', 'changed', { changed : '2026-11-02T12:00:00Z', title : 'Contract' } );
	const w = drawn( 'en_US', { requests : [ CHANGED ], days : 14 } );
	const row = function() { return w.rows()[0] };

	check( 'a changed request says so in its badge, as a warning, and its time with the others in the words of its kind', badgeOf( w, row() ).textContent === w.word('/_admin/sign/state/changed') && badgeOf( w, row() ).dataset.state === 'warn'
		&& copyOf( w, row() ).join('|').indexOf( w.word('/_admin/sign/label/changed')+ ' 2026-11-02 12:00 UTC' ) !== -1 );
	check( '...it has Ask again, the record and Delete - no Remind and no Cancel, which are for a request that waits', buttonsOf( w, row() ).join('|') === [ w.word('/_admin/sign/label/again'), w.word('/_admin/sign/label/details'), w.word('/_admin/sign/label/delete') ].join('|') );

	w.button( row(), w.word('/_admin/sign/label/again') ).click();
	check( 'Ask again posts sign/again with the id of the request and nothing else, without a question - it is one click - and the line of the row says it is on its way', w.last().action === 'sign/again' && JSON.stringify( w.last().payload ) === '{"id":"00000000000d"}' && w.asked.length === 0
		&& lineOf( w, row() ).textContent !== '' );
	w.answer( 200, { id : '00000000000e' } );
	check( '...an answer says the new request is made in the line, and the list is asked for again, which shows the new one beside the old', w.last().action === 'sign/list' );
	w.answer( 200, { requests : [ request( '00000000000e', 'requested' ), CHANGED ], days : 14 } );
	check( '...and the changed one stays in the list, as it was', w.rows().length === 2 && w.rows()[1].dataset.id === '00000000000d' && buttonsOf( w, w.rows()[0] ).indexOf( w.word('/_admin/sign/label/again') ) === -1 );

	w.button( w.rows()[1], w.word('/_admin/sign/label/again') ).click();
	w.answer( 409, { error : 'x', code : 'sign_state' } );
	check( 'a refusal is told in the sentence of its code, and the list is not asked for again', lineOf( w, w.rows()[1] ).textContent === w.said('/_admin/error/sign_state') && w.open() === 0 );

	w.button( w.rows()[1], w.word('/_admin/sign/label/again') ).click();
	w.answer( 500, { error : 'x', code : 'sign_mail' } );
	check( 'a new request whose mail could not be sent is told in the sentence of its code - and is there: the list is asked for again', lineOf( w, w.rows()[1] ).textContent === w.said('/_admin/error/sign_mail') && w.last().action === 'sign/list' );
	w.answer( 200, { requests : [ CHANGED ], days : 14 } );

	w.button( w.rows()[0], w.word('/_admin/sign/label/again') ).click();
	w.answer( 500, {} );
	check( 'a failure with no code is told in the panel\'s own sentence with the status in front, and the list is not asked for again', lineOf( w, w.rows()[0] ).textContent === '(500) '+ w.word('/_admin/sign/error/again') && w.open() === 0 );
}

console.log( '\nNino.admin.sign - in German\n' );

{
	const en = world('en_US');
	const w = drawn('de_DE');
	const rows = w.rows();
	const keys = Object.keys( fills( own('text/en_US.php') ) );
	const german = fills( own('text/de_DE.php') );

	check( 'the text files of the two languages have the same keys', Object.keys( german ).sort().join() === keys.slice().sort().join() );
	check( '...and say the German in other words than the English, key by key, but for the few that are the same word in both', keys.filter( function( key ) { return german[key] === en.word( key ) } ).length <= 3 );

	check( 'the list, in German: the states and the dates are those of the language, and the record opens under a row', badgeOf( w, rows[2] ).textContent === w.word('/_admin/sign/state/signed') && badgeOf( w, rows[2] ).textContent !== en.word('/_admin/sign/state/signed')
		&& copyOf( w, rows[0] )[2] === w.word('/_admin/sign/label/created')+ ' 2026-11-02 10:15 UTC' && w.button( rows[2], w.word('/_admin/sign/label/details') ) !== null );

	w.button( rows[2], w.word('/_admin/sign/label/details') ).click();
	check( '...the record of a signing, in German: the terms are the words of the language, the method too', w.all( w.rows()[2], function( el ) { return el.tagName === 'DT' } ).map( function( el ) { return el.textContent } ).join() === [ 'expires', 'address', 'agent', 'method', 'consent', 'hash-shown', 'hash-signed' ].map( function( name ) { return w.word('/_admin/sign/label/'+ name) } ).join()
		&& w.all( w.rows()[2], function( el ) { return el.textContent === w.word('/_admin/sign/method/drawn') } ).length === 1 );

	w.button( w.rows()[0], w.word('/_admin/sign/label/cancel') ).click();
	check( 'the question before a cancel, in German, names the member', w.asked[0] === w.said( '/_admin/sign/confirm/cancel', 'Ada Lovelace' ) && w.asked[0].indexOf('Ada Lovelace') !== -1 );
	w.answer( 409, { error : 'x', code : 'sign_state' } );
	check( '...and a refusal is told in the German sentence of its code', lineOf( w, w.rows()[0] ).textContent === w.said('/_admin/error/sign_state') && lineOf( w, w.rows()[0] ).textContent !== en.said('/_admin/error/sign_state') );

	const f = fillIn( w, 'aaaaaaaaaaaa', 'contract-one', {} );
	fire( f.form, 'submit' );
	check( 'the form, in German: a missing text is named by its label in the German sentence', f.form.querySelector('.nino-admin-status').textContent === w.said( '/_admin/sign/error/input', 'Rent' ) && w.word('/_admin/sign/error/input').indexOf('%s') !== -1 );

	check( 'no word was asked of the fills that the text files lack', w.missing.length === 0 );
}

console.log( '\nsign.js - the pad\n' );

/**
 *	A canvas stand-in: the size it has, the box the page gives it, and a 2d context that writes down what it is told to draw. Its
 *	picture is the list of those calls, so that a test reads what an offscreen canvas was drawn with out of the data URL
 *
 *	@param		{number}	width
 *	@param		{number}	height
 *	@param		{Object}	rect							{ left, top, width, height }
 *	@param		{boolean}	[drawable]				false for a canvas that has no 2d context
 *
 *	@return		{Object}
 */
function canvasOf( width, height, rect, drawable ) {

	const canvas = element('canvas');

	canvas.width = width;
	canvas.height = height;
	canvas.log = [];
	canvas.captured = [];

	const note = function( name ) { return function( ...args ) { canvas.log.push( [ name ].concat( args ) ) } };
	const context = {
		clearRect : note('clearRect'), beginPath : note('beginPath'), moveTo : note('moveTo'), lineTo : note('lineTo'), arc : note('arc'),
		stroke : function() { canvas.log.push( [ 'stroke', context.strokeStyle, context.lineWidth, context.lineCap ] ) },
		fill : function() { canvas.log.push( [ 'fill', context.fillStyle ] ) },
		fillRect : note('fillRect'),
	};

	canvas.getContext = function( kind ) { return kind === '2d' && drawable !== false ? context : null };
	canvas.toDataURL = function( type ) { return 'data:'+ type+ ';base64,'+ Buffer.from( JSON.stringify( canvas.log ) ).toString('base64') };
	canvas.getBoundingClientRect = function() { return rect };
	canvas.setPointerCapture = function( id ) { canvas.captured.push( id ) };

	return canvas;
}

/** What an offscreen canvas was drawn with, out of the data URL the script made of it */
function drawnWith( url ) {
	const data = String( url ).split(',')[1];
	return data === undefined ? [] : JSON.parse( Buffer.from( data, 'base64' ).toString() );
}

/**
 *	The pad's script in a page of its own: one signing form with the pieces sign.js looks for - the box of the canvas, which comes
 *	hidden, the canvas, the button that empties it, the field of the typed name and the hidden field of the picture - over a
 *	Nino that keeps the callbacks bound to the ready event
 *
 *	@param		{Object}	[options]					{ drawable : false for a canvas without a context, pieces : false to leave out the field of the picture }
 *
 *	@return		{Object}
 */
function pad( options ) {

	options = options ?? {};

	const offscreen = [];
	const ready = [];
	const form = element('form');
	const draw = element('div');
	const canvas = canvasOf( 600, 200, { left : 10, top : 20, width : 300, height : 100 }, options.drawable );
	const clear = element('button');
	const typed = element('div');
	const image = element('input');

	form.setAttribute( 'data-sign-form', '' );
	draw.setAttribute( 'data-sign-draw', '' );
	draw.hidden = true;
	canvas.setAttribute( 'data-sign-pad', '' );
	clear.setAttribute( 'data-sign-clear', '' );
	typed.setAttribute( 'data-sign-name', '' );
	image.setAttribute( 'data-sign-image', '' );

	draw.appendChild( canvas );
	draw.appendChild( clear );
	form.appendChild( draw );
	form.appendChild( typed );

	if( options.pieces !== false )
		form.appendChild( image );

	const Nino = { events : { bindCallback : function( name, fn ) { ready.push( { name : name, fn : fn } ) } } };
	const page = {
		createElement : function( tag ) {
			const el = tag === 'canvas' ? canvasOf( 0, 0, { left : 0, top : 0, width : 0, height : 0 }, options.offscreen ) : element( tag );
			if( tag === 'canvas' )
				offscreen.push( el );
			return el;
		},
		querySelectorAll : function( selector ) { return selector === 'form[data-sign-form]' ? [ form ] : [] },
	};
	// Whatever the script says to the person: it says nothing, the words of the page are the page's
	const spoken = [];
	const window = { Nino : Nino, getComputedStyle : function() { return { color : 'rgb(240, 240, 240)' } }, alert : function( text ) { spoken.push( text ) }, confirm : function( text ) { spoken.push( text ); return true } };
	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window } );

	load( own( 'assets/sign.js' ), context, 'sign.js' );

	return {
		Nino : Nino, spoken : spoken, sign : window.Nino.sign, ready : ready, form : form, draw : draw, canvas : canvas, clear : clear, typed : typed, image : image, offscreen : offscreen,
		start : function() { ready.forEach( function( each ) { each.fn() } ) },

		// A pointer on the page: at a point in the pixels of the page, as an event of the pointer would say it
		point : function( name, x, y, more ) {
			const stopped = { prevented : 0 };
			fire( canvas, name, Object.assign( { pointerType : 'mouse', button : 0, pointerId : 1, clientX : x, clientY : y, preventDefault : function() { stopped.prevented++ } }, more ) );
			return stopped;
		},
		submit : function() { fire( form, 'submit' ) },
	};
}

{
	const p = pad();

	check( 'the script gives Nino.sign its four pure functions and the start, and binds the start to the ready event, nothing more', Object.keys( p.sign ).sort().join() === 'init,inked,paint,picture,place' && p.ready.length === 1 && p.ready[0].name === 'ready' && p.ready[0].fn === p.sign.init );
	check( 'nothing is changed on the page before the ready event: the box of the canvas is hidden, the typed name is shown', p.draw.hidden === true && p.typed.hidden === false && p.form.listeners.submit === undefined );

	// place(): the pixels of the page into the pixels of the canvas, which is drawn at another size
	const rect = { left : 10, top : 20, width : 300, height : 100 };
	const size = { width : 600, height : 200 };
	const at = p.sign.place( rect, size, 40, 70 );
	check( 'a pointer is placed on the canvas by the size the page gives it against the size it has - twice as many pixels here - and the corner is the corner', at.x === 60 && at.y === 100 && p.sign.place( rect, size, 10, 20 ).x === 0 && p.sign.place( rect, size, 310, 120 ).y === 200 );
	check( '...a canvas the page gives no size (hidden, not laid out) is placed as if it were drawn at its own', p.sign.place( { left : 0, top : 0, width : 0, height : 0 }, size, 40, 70 ).x === 40 && p.sign.place( { left : 0, top : 0, width : 0, height : 0 }, size, 40, 70 ).y === 70 );

	check( 'there is something to draw when some stroke has a point, and not before', p.sign.inked( [] ) === false && p.sign.inked( [ [] ] ) === false && p.sign.inked( [ [], [ { x : 1, y : 1 } ] ] ) === true );

	// paint(): a dot for a stroke of one point, a line through the points for more
	const log = [];
	const brush = { clearRect : function( ...a ) { log.push( [ 'clear', ...a ] ) }, beginPath : function() { log.push( [ 'begin' ] ) }, moveTo : function( ...a ) { log.push( [ 'move', ...a ] ) }, lineTo : function( ...a ) { log.push( [ 'line', ...a ] ) },
		arc : function( ...a ) { log.push( [ 'arc', ...a ] ) }, stroke : function() { log.push( [ 'stroke', brush.strokeStyle, brush.lineWidth ] ) }, fill : function() { log.push( [ 'fill', brush.fillStyle ] ) } };
	p.sign.paint( brush, [ [ { x : 5, y : 6 } ], [ { x : 1, y : 1 }, { x : 2, y : 3 }, { x : 4, y : 3 } ], [] ], 600, 200, '#123456' );
	check( 'paint() empties the canvas first and draws a dot for a stroke of one point, a line through the points of a longer one, and nothing for a stroke without a point, all in the colour given and in a line of 3 pixels at this width',
		JSON.stringify( log ) === JSON.stringify( [ [ 'clear', 0, 0, 600, 200 ], [ 'begin' ], [ 'arc', 5, 6, 1.5, 0, Math.PI * 2 ], [ 'fill', '#123456' ], [ 'begin' ], [ 'move', 1, 1 ], [ 'line', 2, 3 ], [ 'line', 4, 3 ], [ 'stroke', '#123456', 3 ] ] )
		&& brush.lineCap === 'round' && brush.lineJoin === 'round' );
	p.sign.paint( brush, [ [ { x : 1, y : 1 }, { x : 2, y : 2 } ] ], 100, 40, '#000' );
	check( '...and a line of at least 2 pixels on a canvas that is small', brush.lineWidth === 2 );

	check( 'picture() of no stroke is nothing at all, and makes no canvas to draw it on', p.sign.picture( [], 600, 200 ) === '' && p.sign.picture( [ [] ], 600, 200 ) === '' && p.offscreen.length === 0 );
	const shot = p.sign.picture( [ [ { x : 1, y : 1 }, { x : 20, y : 9 } ] ], 600, 200 );
	const ops = drawnWith( shot );
	check( 'picture() of a stroke is a PNG data URL of a canvas of the size given, drawn in the dark ink whatever the colours of the pad are, on a ground that is cleared and never filled', shot.indexOf('data:image/png;base64,') === 0
		&& p.offscreen.length === 1 && p.offscreen[0].width === 600 && p.offscreen[0].height === 200 && ( ops[0] ?? [] ).join() === 'clearRect,0,0,600,200' && ops.some( function( op ) { return op[0] === 'stroke' && op[1] === '#10162f' } )
		&& ops.some( function( op ) { return op[0] === 'fillRect' } ) === false );
	const blind = pad( { offscreen : false } );
	check( 'a browser that gives no context to the second canvas gives no picture and no error', blind.sign.picture( [ [ { x : 1, y : 1 } ] ], 600, 200 ) === '' );
}

{
	const p = pad();
	p.start();

	check( 'at the ready event the box of the canvas is shown, and the form is marked as taken over', p.draw.hidden === false && p.form.dataset.signBound === '1' && p.form.listeners.submit.length === 1 );
	check( '...an empty pad has nothing to clear and leaves the typed name where it is', p.clear.disabled === true && p.typed.hidden === false );
	p.start();
	check( 'a second start takes the form over once only', p.form.listeners.submit.length === 1 && p.canvas.listeners.pointerdown.length === 1 );

	const down = p.point( 'pointerdown', 40, 70 );
	check( 'a stroke begins at a pointer going down on the canvas: the page does not scroll under it, the pointer is captured, and the pad draws a dot in the colour of the pad', down.prevented === 1 && p.canvas.captured.join() === '1'
		&& p.canvas.log.filter( function( op ) { return op[0] === 'arc' } ).length === 1 && p.canvas.log.filter( function( op ) { return op[0] === 'arc' } )[0].slice( 1, 3 ).join() === '60,100' && p.canvas.log.filter( function( op ) { return op[0] === 'fill' } ).pop()[1] === 'rgb(240, 240, 240)' );
	check( '...the field of the typed name goes, there is one way to sign at a time, and the button that empties the pad is ready', p.typed.hidden === true && p.clear.disabled === false );

	p.point( 'pointermove', 100, 40 );
	p.point( 'pointermove', 160, 80 );
	const lines = p.canvas.log.filter( function( op ) { return op[0] === 'lineTo' } );
	check( 'a pointer that moves with the button down extends the stroke, in the pixels of the canvas, and the pad is drawn again from its strokes every time', lines.length >= 2 && lines[lines.length - 1].slice( 1 ).join() === '300,120' && lines[lines.length - 2].slice( 1 ).join() === '180,40'
		&& p.canvas.log.filter( function( op ) { return op[0] === 'clearRect' } ).length === 4 );

	p.point( 'pointerup', 160, 80 );
	const count = p.canvas.log.length;
	p.point( 'pointermove', 200, 90 );
	check( 'a pointer that moves after the button is up draws nothing', p.canvas.log.length === count );

	p.point( 'pointerdown', 60, 50 );
	p.point( 'pointercancel', 60, 50 );
	const cancelled = p.canvas.log.length;
	p.point( 'pointermove', 80, 60 );
	check( 'a pointer that was cancelled ends its stroke too: a move after it draws nothing', p.canvas.log.length === cancelled );

	const right = p.point( 'pointerdown', 70, 70, { button : 2 } );
	check( 'the secondary button of a mouse starts no stroke and keeps the context menu of the browser', p.canvas.log.length === cancelled && right.prevented === 0 );
	const touch = p.point( 'pointerdown', 70, 70, { pointerType : 'touch', button : 0 } );
	p.point( 'pointerup', 70, 70 );
	check( '...a finger and a pen start one, whatever their button says', p.canvas.log.length > cancelled && touch.prevented === 1 );

	p.submit();
	const ink = drawnWith( p.image.value );
	check( 'sending the form writes the picture into the hidden field: a PNG of the strokes in the dark ink on a ground that is cleared, the size of the canvas', p.image.value.indexOf('data:image/png;base64,') === 0 && p.offscreen.length === 1
		&& p.offscreen[0].width === 600 && p.offscreen[0].height === 200 && ( ink[0] ?? [] ).join() === 'clearRect,0,0,600,200' && ink.filter( function( op ) { return op[0] === 'stroke' || op[0] === 'fill' } ).every( function( op ) { return op[1] === '#10162f' } )
		&& ink.filter( function( op ) { return op[0] === 'stroke' } ).length === 1 && ink.filter( function( op ) { return op[0] === 'arc' } ).length === 2 && ink.some( function( op ) { return op[0] === 'fillRect' } ) === false );
	check( '...whatever the pad itself is painted in: the pad on the screen was painted in the colour the page gave it, the picture only in the ink', p.canvas.log.filter( function( op ) { return op[0] === 'fill' } ).every( function( op ) { return op[1] === 'rgb(240, 240, 240)' } ) );

	p.clear.click();
	check( 'the button that empties the pad empties it - the strokes, the field of the picture, the pad on screen - and gives the typed name back', p.image.value === '' && p.typed.hidden === false && p.clear.disabled === true
		&& p.canvas.log.slice( -1 )[0].join() === 'clearRect,0,0,600,200' );
	p.submit();
	check( 'a form sent with no stroke leaves the field of the picture empty, and makes no picture', p.image.value === '' && p.offscreen.length === 1 );

	p.point( 'pointerdown', 40, 70 );
	p.point( 'pointerup', 40, 70 );
	p.submit();
	check( 'a single touch is a stroke - a dot -, and signs', drawnWith( p.image.value ).some( function( op ) { return op[0] === 'arc' } ) === true );
	check( 'the pad says nothing to the person in the whole of a signing - no alert, no question: the words of the page are the page\'s', p.spoken.length === 0 );
}

{
	const p = pad( { drawable : false } );
	p.start();
	check( 'a canvas that cannot draw leaves the form to the browser: the box stays hidden, the typed name stays, and nothing is listened to', p.draw.hidden === true && p.typed.hidden === false && p.form.listeners.submit === undefined && p.canvas.listeners.pointerdown === undefined && p.form.dataset.signBound === undefined );

	const noField = pad( { pieces : false } );
	noField.start();
	check( 'a form without the hidden field of the picture is left alone too', noField.draw.hidden === true && noField.form.listeners.submit === undefined );

	const plain = pad();
	plain.canvas.getContext = undefined;
	plain.start();
	check( 'so is one whose canvas has no getContext at all', plain.draw.hidden === true && plain.form.listeners.submit === undefined );
}


console.log( '\nthe sources - what no script of the feature does\n' );

{
	const panel = source( own('assets/admin.js') );
	const site = source( own('assets/sign.js') );
	const code = function( text ) { return text.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*\/\/.*$/gm, '' ) };
	const markup = /\binnerHTML\s*=(?!\s*'')|\.outerHTML\b|insertAdjacentHTML|document\.write|\beval\s*\(|new\s+Function|\bon[a-z]+\s*=\s*['"]/;

	check( 'neither script writes markup: innerHTML is only ever emptied, nothing is evaluated or written into the document', markup.test( code( panel ) ) === false && markup.test( code( site ) ) === false );
	check( 'the panel owns no word: every text it shows is a fill it asks for, or a value of the server set as text - no literal sentence', /(?:textContent|text|hint|Hint|fail|idle)\s*[=(]\s*['"][A-Za-z]{3,}[ A-Za-z]*['"]/.test( code( panel ) ) === false );
	check( 'the pad reads and writes no word at all: no fill, no textContent, no alert', /getText|textContent|innerText|alert\s*\(|confirm\s*\(/.test( code( site ) ) === false );
	check( 'the panel asks the server through its panel only - no request of its own', /XMLHttpRequest|fetch\s*\(|\.open\s*\(\s*['"](?:GET|POST)/.test( code( panel ) ) === false && /sign\.call\(/.test( code( panel ) ) === true );
}

done();

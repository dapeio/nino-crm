/**
 *	Crm
 *	crm-js-smoke.js		What the script of the panel Kunden does (Clients/Admin/assets/admin.js) over the kernel's own
 *										Nino.admin.js: it registers Nino.admin['crm-clients'] under the uri of the panel and posts
 *										crm-clients/<action>; the first showing asks for the list with the search, and the search asks
 *										again; the rows say the number and the company (the name where there is none), how many accounts
 *										and whether the client is inactive - as text and as nothing else; the status of the module is
 *										asked for and drawn, the button that sets the portal up posts {} and is off while the request is
 *										out, and what it says stays on the screen after the box is drawn again, as does a refusal; the
 *										card is asked for by id, saves exactly the fields of a client (and the id as a string), says
 *										a refusal in the sentence of its code and saved after it is drawn again, ends the save of the
 *										registry in done() on every way, knows when it holds what nobody saved, takes it back on discard
 *										and keeps it through the switch of active; the form that invites posts id, email and name and
 *										says whether the mail went out or why not, and the accounts are rows of text with their actions - the
 *										link again (the invitation for an account that never signed in, the link to sign in for one that did),
 *										switch off and on, the name, delete after a question - each posting crm-clients/account-<action> with the
 *										id of the client and of the account and replacing the row with what the server answers; the dialog that
 *										links an account of Members that no client holds; the fields of the first account on a new client; and
 *										the accounts a client that is switched off has off. Only Nino itself
 *										is a stand-in: the requests are kept for the test to answer, and the words are the ones of the
 *										text files, in both interface languages.
 *
 *										A pin names the words it expects by their keys, not by what they say. The helpers are those of
 *										tests/harness.js of the checkout, three levels up or the one NINO_ROOT names. crm-smoke.php runs
 *										this where node is on the path.
 *
 *	Usage: node app/Crm/tests/crm-js-smoke.js
 */

'use strict';

/* global require, __dirname, process */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

const OWN = 'app/Crm/Clients/Admin/';

/**
 *	The panel's script in a page of its own, in one interface language: the kernel's Nino.admin.js and admin.js, over a Nino
 *	that keeps the requests and the words of the text files
 *
 *	@param		{string}	locale		en_US or de_DE
 *	@param		{boolean}	[modal]		Whether a <dialog> opens and closes the way a browser's does (showModal(), close()), so that a question with controls in it can be answered; else it is asked with confirm()
 *
 *	@return		{Object}
 */
function world( locale, modal ) {

	const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( OWN+ 'text/'+ locale+ '.php' ) );
	const missing = [];
	const requests = [];
	const registered = [];
	const guarded = [];
	const asked = [];
	const dialogs = [];
	const scrolled = [];

	const mount = element('div');
	mount.id = 'crm-clients-app';

	const page = documentOf( mount );
	const make = page.createElement;

	page.createElement = function( tag ) {
		const el = make( tag );
		Object.defineProperty( el, 'name', { get : function() { return el.attributes.name ?? '' }, set : function( value ) { el.setAttribute( 'name', value ) } } );

		el.scrollIntoView = function( options ) { scrolled.push( { element : el, options : options } ) };

		if( tag === 'dialog' && modal === true ) {
			el.open = false;
			el.showModal = function() { el.open = true; dialogs.push( el ) };
			el.close = function() { el.open = false; fire( el, 'close' ) };
		}

		return el;
	};

	if( modal === true )
		page.body = element('body');

	const Nino = {
		content : { getText : function( key ) {
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		admin : {
			dirty : {
				register : function( name, entry ) { registered.push( { name : name, entry : entry } ) },
				refresh : function() {},
				guard : function( names, proceed ) { guarded.push( names ); proceed() },
			},
			router : { href : function( panel, parts ) { return '#'+ [ panel ].concat( parts ?? [] ).join('/') } },
		},
	};

	const window = { Nino : Nino, confirm : function( message ) { asked.push( message ); return false } };
	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	load( OWN+ 'assets/admin.js', context, 'admin.js' );

	const find = function( from, predicate ) { return findAll( from ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin['crm-clients'],
		mount : mount,
		requests : requests,
		registered : registered,
		guarded : guarded,
		asked : asked,
		dialogs : dialogs,
		scrolled : scrolled,
		missing : missing,
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		button : function( from, text ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		select : function( from, name ) { return find( from, function( el ) { return el.tagName === 'SELECT' && el.name === name } ) },
		input : function( from, name ) { return find( from, function( el ) { return ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' ) && el.name === name } ) },
		word : function( key ) { return words[key] },
		said : function( key, ...params ) { return words[key] === undefined ? '' : Nino.adminUi.format( words[key], ...params ) },
		has : function( key ) { return typeof words[key] === 'string' && words[key] !== '' },
		entry : function() { return registered[0].entry },
		last : function() { return requests[requests.length - 1] },
		texts : function( from ) { return findAll( from ?? mount, function() { return true } ).map( function( el ) { return el.textContent } ) },
		line : function( from ) { return find( from, function( el ) { return String( el.className ).split(' ').indexOf('nino-admin-status') !== -1 } ) },

		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
	};
}

const A = { id : 1, number : 'K-0001', company : 'Studio Nord', name : 'Anna Nord', street : 'Hafenstr. 1', zip : '20095', city : 'Hamburg', country : 'DE', email : 'anna@example.org', phone : '+49 40 1', vat_id : 'DE123456789', notes : 'Notes', active : true, accounts : 2, active_accounts : 1, group : 'k-0001', documents : { total : 5, released : 2 } };
const B = { id : 2, number : 'K-0002', company : '', name : 'Ben Süd', street : '', zip : '', city : '', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : false, accounts : 1, active_accounts : 0, group : 'k-0002', documents : { total : 0, released : 0 } };
const C = { id : 3, number : 'K-0003', company : '<img src=x onerror=alert(1)>', name : '', street : '', zip : '', city : '', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : true, accounts : 0, active_accounts : 0, group : 'k-0003', documents : { total : 1, released : 0 } };
const ALL = [ 'module', 'data', 'members', 'files', 'review', 'sign', 'pdf', 'portal' ];
const OK = ALL.map( function( key ) { return { key : key, state : 'ok' } } );
const FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id', 'notes' ];

/** The panel shown, its list answered and its status answered */
function drawn( locale, clients, items, modal ) {
	const w = world( locale, modal );
	w.panel.showCurrent();
	w.answer( 200, { clients : clients ?? [ A, B, C ] } );
	w.answer( 200, { items : items ?? OK } );
	return w;
}

/** The card of a client open: the row pressed and the card answered */
function opened( locale, client, accounts, modal ) {
	const w = drawn( locale, undefined, undefined, modal );
	w.find( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === String( client.id ) } ).click();
	w.answer( 200, { client : client, accounts : accounts ?? [] } );
	return w;
}

const OFF_ACCOUNT = { id : 'cccccccccccc', gone : false, state : 'off', email : 'c@example.org', name : 'Carl', active : false, login : '2026-10-01T10:00:00Z' };
const ACCOUNT = { id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'anna@example.org', name : 'Anna Nord', active : true, login : '2026-11-02T10:15:30Z' };

console.log( 'Nino.admin[\'crm-clients\'] - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown', w.requests.length === 0 && w.registered.length === 0 );
	check( 'the namespace is the nav uri of the panel, with its mount', w.panel.uri === 'crm-clients' && w.panel.mount() === w.mount && typeof w.panel.showCurrent === 'function' );

	w.panel.call( 'probe', { x : 1 }, function() {} );
	check( 'the panel posts crm-clients/<action>: its call() is the one of the shell, with no override', w.last().action === 'crm-clients/probe' && Object.keys( w.panel ).indexOf('_actions') === -1 );

	w.requests.length = 0;
	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for crm-clients/list, once while the answer is out, with nothing posted: the table searches', w.requests.length === 1 && w.requests[0].action === 'crm-clients/list' && JSON.stringify( w.requests[0].payload ) === '{}' );
	check( '...and registers its entry under its uri, which holds nothing unsaved', w.registered.length === 1 && w.registered[0].name === 'crm-clients' && w.entry().isDirty() === false );

	w.answer( 200, { clients : [ A, B, C ] } );
	check( 'the status is asked for once the list is drawn', w.last().action === 'crm-clients/status' && JSON.stringify( w.last().payload ) === '{}' );
}

console.log( '\nThe rows - the shell\'s table\n' );

{
	const w = drawn('en_US');
	const head = w.all( w.mount, function( el ) { return el.tagName === 'TH' } );
	const rows = w.all( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } );
	const cells = function( row ) { return w.all( row, function( el ) { return el.tagName === 'TD' } ).map( function( el ) { return el.textContent } ) };
	const table = w.find( w.mount, function( el ) { return el.id === 'crm-clients-table' } );

	check( 'the list is the shell\'s table: it has the columns number, company, name, email, city, accounts, accounts active and active, with their words', table !== null && head.map( function( th ) { return th.dataset.key } ).join() === 'number,company,name,email,city,accounts,active_accounts,active'
		&& [ 'column/number', 'field/company', 'field/name', 'field/email', 'field/city', 'column/accounts', 'column/active-accounts', 'column/active' ].every( function( key ) { return w.has('/_admin/crm-clients/'+ key) && w.texts( table ).indexOf( w.word('/_admin/crm-clients/'+ key) ) !== -1 } ) );
	check( 'one row for each client, keyed by its id, in the order of the answer', rows.map( function( row ) { return row.dataset.row } ).join() === '1,2,3' );
	check( 'a row says the number, the company, the name, the address, the city, how many accounts, how many of them are on - and a glyph for active', cells( rows[0] ).join('|') === 'K-0001|Studio Nord|Anna Nord|anna@example.org|Hamburg|2|1|✓'
		&& cells( rows[1] ).join('|') === 'K-0002||Ben Süd|||1|0|–' );
	check( 'what the server sends is text and nothing else: a company that is markup is a string, no element comes of it', cells( rows[2] )[1] === C.company && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'SCRIPT' } ).length === 0 );
	check( 'no word is missing from the text files', w.missing.length === 0 );

	rows[1].click();
	check( 'a click on a row asks for the card of that client', w.last().action === 'crm-clients/get' && JSON.stringify( w.last().payload ) === '{"id":"2"}' );

	const kids = w.mount.children;
	const add = w.button( w.mount, w.word('/_admin/crm-clients/label/new') );
	check( '"new client" stands below the table, in the actions of the list', add !== null && kids.indexOf( table ) !== -1 && kids.indexOf( table ) < kids.findIndex( function( el ) { return el.contains === undefined ? w.all( el, function( x ) { return x === add } ).length > 0 : el.contains( add ) } ) );

	const before = w.requests.length;
	const search = w.find( table, function( el ) { return el.tagName === 'INPUT' && el.type === 'search' } );
	search.value = 'ben';
	fire( search, 'input' );
	check( 'the table searches where it stands: nothing is asked of the server, the rows are filtered', w.requests.length === before && w.all( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } ).map( function( row ) { return row.dataset.row } ).join() === '2' );

	search.value = 'zzz';
	fire( search, 'input' );
	check( '...a search that finds nothing says that, and not that there are no clients', w.texts().indexOf( w.word('/_admin/crm-clients/empty/found') ) !== -1 && w.texts().indexOf( w.word('/_admin/crm-clients/empty/clients') ) === -1 );

	const many = drawn( 'en_US', [ Object.assign( {}, A, { id : 11, number : 'K-0011', accounts : 10 } ), Object.assign( {}, A, { id : 12, number : 'K-0012', accounts : 9 } ) ] );
	many.find( many.mount, function( el ) { return el.tagName === 'TH' && el.dataset.key === 'accounts' } ).querySelector('button').click();
	check( 'the accounts columns are numbers: their cells are set right', many.all( many.mount, function( el ) { return el.tagName === 'TD' && String( el.className ).indexOf('nino-admin-table-num') !== -1 } ).length === 4 );
	check( 'the accounts are sorted as numbers: 9 before 10', many.all( many.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } ).map( function( row ) { return row.dataset.row } ).join() === '12,11' );

	const empty = drawn( 'en_US', [] );
	check( 'no client says so', empty.texts().indexOf( empty.word('/_admin/crm-clients/empty/clients') ) !== -1 && empty.texts().indexOf( empty.word('/_admin/crm-clients/empty/found') ) === -1 );
	check( 'the only search box on the list is the table\'s own, inside the table: none stands above it', w.all( w.mount, function( el ) { return el.tagName === 'INPUT' && el.type === 'search' } ).length === 1 && w.all( table, function( el ) { return el.tagName === 'INPUT' && el.type === 'search' } ).length === 1 );
	check( 'the list has no search box of its own', w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-search') !== -1 } ) === null && w.has('/_admin/crm-clients/label/find') === false );
}

console.log( '\nThe status and the setup\n' );

{
	const partial = drawn( 'en_US', undefined, OK.map( function( item ) { return item.key === 'portal' ? { key : 'portal', state : 'partial', lacks : [ 'types', 'paths' ] } : item } ) );
	const names = partial.said( '/_admin/crm-clients/status/lacks-types' )+ ', '+ partial.said( '/_admin/crm-clients/status/lacks-paths' );
	check( 'a portal that is only partly there names what it lacks, one name for each part', partial.has('/_admin/crm-clients/status/lacks-types') === true && partial.texts().indexOf( partial.said( '/_admin/crm-clients/status/portal-partial', names ) ) !== -1 && partial.missing.length === 0 );
}

{
	const w = drawn('en_US');
	check( 'a module that misses nothing says so, and the button that sets the portal up is hidden', w.texts().indexOf( w.word('/_admin/crm-clients/status/allok') ) !== -1 && w.button( w.mount, w.word('/_admin/crm-clients/label/setup') ).hidden === true );

	const missing = drawn( 'en_US', undefined, OK.map( function( item ) { return item.key === 'sign' ? { key : 'sign', state : 'off' } : item.key === 'portal' ? { key : 'portal', state : 'none' } : item } ) );
	check( 'what is missing is told line by line, in the words of its state, and the button is there', missing.has('/_admin/crm-clients/status/sign-off') === true && missing.has('/_admin/crm-clients/status/portal-none') === true
		&& missing.texts().indexOf( missing.word('/_admin/crm-clients/status/sign-off') ) !== -1 && missing.texts().indexOf( missing.word('/_admin/crm-clients/status/portal-none') ) !== -1
		&& missing.texts().indexOf( missing.word('/_admin/crm-clients/status/allok') ) === -1 && missing.button( missing.mount, missing.word('/_admin/crm-clients/label/setup') ).hidden === false );

	const button = missing.button( missing.mount, missing.word('/_admin/crm-clients/label/setup') );
	button.click();
	check( 'the button posts crm-clients/setup with nothing, and is off while the request is out', missing.last().action === 'crm-clients/setup' && JSON.stringify( missing.last().payload ) === '{}' && button.disabled === true );

	missing.answer( 200, { added : { routes : [ 'a', 'b' ], templates : [ 'x' ], types : [ 'positionen.php' ], texts : 7, paths : true, start : true }, existed : { routes : [ 'c' ], templates : [ 'y', 'z' ], types : [ 'q.php' ], paths : false } } );
	check( 'the box is asked for again after it', missing.last().action === 'crm-clients/status' );
	missing.answer( 200, { items : OK } );
	check( 'what the setup did stays on the screen after the box is drawn again', missing.has('/_admin/crm-clients/setup/done') === true && missing.texts().indexOf( missing.said( '/_admin/crm-clients/setup/done', 2, 1, 1, 7, 4 ) ) !== -1 );
	check( '...and the sentence has a place for each of the five numbers: routes, templates, element types, words, and what was there', missing.word( '/_admin/crm-clients/setup/done' ).split('%s').length === 6 );

	const refused = drawn( 'en_US', undefined, OK.map( function( item ) { return item.key === 'portal' ? { key : 'portal', state : 'none' } : item } ) );
	const again = refused.button( refused.mount, refused.word('/_admin/crm-clients/label/setup') );
	again.click();
	refused.answer( 409, { error : 'x', code : 'crm_clients_members', params : [] } );
	check( 'a refusal says its sentence, and the button is back', refused.has('/_admin/error/crm_clients_members') === true && refused.texts().indexOf( refused.word('/_admin/error/crm_clients_members') ) !== -1 && again.disabled === false );

	const failed = drawn( 'en_US', undefined, [ { key : 'module', state : 'off' } ] );
	check( 'a module that is off is told in its words', failed.texts().indexOf( failed.word('/_admin/crm-clients/status/module-off') ) !== -1 );
}

console.log( '\nThe card\n' );

{
	const w = drawn('en_US');
	w.find( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '1' } ).click();
	check( 'a row asks for crm-clients/get with the id as a string', w.last().action === 'crm-clients/get' && JSON.stringify( w.last().payload ) === '{"id":"1"}' );

	w.answer( 200, { client : A, accounts : [] } );
	check( 'the card holds the fields of the client', FIELDS.every( function( name ) { return w.input( w.mount, name ).value === A[name] } ) && w.all( w.mount, function( el ) { return el.tagName === 'H3' } )[0].textContent === 'K-0001 Studio Nord' );
	check( 'the phone is a text field for the workbench, and asks a phone keyboard', w.input( w.mount, 'phone' ).type === 'text' && w.input( w.mount, 'phone' ).inputMode === 'tel' );

	const fresh = drawn('en_US');
	fresh.button( fresh.mount, fresh.word('/_admin/crm-clients/label/new') ).click();
	check( 'a new client starts with the country DE and the rest empty, and asks nothing of the server', fresh.input( fresh.mount, 'country' ).value === 'DE' && fresh.input( fresh.mount, 'company' ).value === '' && fresh.requests.filter( function( request ) { return request.action === 'crm-clients/get' } ).length === 0 );
}

console.log( '\nThe documents of the client\n' );

{
	const w = opened( 'en_US', A );
	const box = w.find( w.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-documents-box') !== -1 } );

	check( 'the card of a client shows how many documents it has and how many of them are released to the portal', box !== null && w.texts( box ).indexOf( 'Documents: 5, released to the portal: 2' ) !== -1 );
	check( '...and a link into the panel Documents, which carries the client in the address', w.find( box, function( el ) { return el.tagName === 'A' && el.href === '#crm-documents/client/1' && el.textContent === w.word('/_admin/crm-clients/label/documents') } ) !== null );

	w.input( w.mount, 'city' ).value = 'Bremen';
	w.entry().save( function() {} );
	w.answer( 200, { client : Object.assign( {}, A, { city : 'Bremen' } ) } );
	check( '...it stays after a save, drawn from the client the server answers', w.texts().indexOf( 'Documents: 5, released to the portal: 2' ) !== -1 );

	const fresh = drawn('de_DE');
	fresh.button( fresh.mount, fresh.word('/_admin/crm-clients/label/new') ).click();
	check( 'a client that is not saved yet has no documents to count', fresh.find( fresh.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-documents-box') !== -1 } ) === null );

	const german = opened( 'de_DE', C );
	check( 'the words are German with the same counts', german.texts().indexOf( 'Dokumente: 1, davon im Portal freigegeben: 0' ) !== -1 );
}

console.log( '\nSaving\n' );

{
	const w = opened( 'en_US', A );
	w.input( w.mount, 'city' ).value = 'Bremen';
	const result = [];
	w.entry().save( function( ok ) { result.push( ok ) } );
	const payload = w.last().payload;

	check( 'a save posts crm-clients/save with the ten fields and the id, as a string', w.last().action === 'crm-clients/save' && Object.keys( payload ).sort().join() === FIELDS.concat( [ 'id' ] ).sort().join() && payload.id === '1' && payload.city === 'Bremen' && payload.company === 'Studio Nord' );

	w.answer( 200, { client : Object.assign( {}, A, { city : 'Bremen' } ) } );
	check( 'a save that goes through ends in done( true )', result.join() === 'true' );
	check( '...the card is drawn again from what is stored, and its line says it is saved', w.input( w.mount, 'city' ).value === 'Bremen' && w.entry().isDirty() === false && ( w.line( w.mount ) || { dataset : {} } ).dataset.state === 'saved' );

	const second = [];
	w.input( w.mount, 'email' ).value = 'nope';
	w.entry().save( function( ok ) { second.push( ok ) } );
	w.answer( 400, { error : 'x', code : 'crm_clients_email', params : [], field : 'email' } );
	check( 'a refusal says its sentence in the line, ends in done( false ) and leaves what was typed', w.has('/_admin/error/crm_clients_email') === true && second.join() === 'false' && w.texts().indexOf( w.word('/_admin/error/crm_clients_email') ) !== -1 && w.input( w.mount, 'email' ).value === 'nope' );

	const busy = [];
	w.entry().save( function( ok ) { busy.push( ok ) } );
	w.entry().save( function( ok ) { busy.push( ok ) } );
	check( 'a second save while one is out ends in done( false ) at once', busy.join() === 'false' );

	const create = drawn('en_US');
	create.button( create.mount, create.word('/_admin/crm-clients/label/new') ).click();
	create.input( create.mount, 'name' ).value = 'Carla';
	fire( create.find( create.mount, function( el ) { return el.tagName === 'FORM' } ), 'submit' );
	check( 'a new client is posted without an id', create.last().action === 'crm-clients/save' && Object.keys( create.last().payload ).indexOf('id') === -1 && create.last().payload.country === 'DE' && create.last().payload.name === 'Carla' );
}

console.log( '\nUnsaved input and the switch of active\n' );

{
	const w = opened( 'en_US', A );
	check( 'a card as it is drawn holds nothing unsaved', w.entry().isDirty() === false );

	w.input( w.mount, 'company' ).value = 'Typed';
	check( 'an edit is unsaved input', w.entry().isDirty() === true );

	w.entry().discard();
	check( 'discard puts the fields back, and the card is clean', w.input( w.mount, 'company' ).value === 'Studio Nord' && w.entry().isDirty() === false );

	w.input( w.mount, 'company' ).value = 'Typed';
	w.button( w.mount, w.word('/_admin/crm-clients/label/deactivate') ).click();
	check( 'the switch posts crm-clients/active with the id and the value it wants', w.last().action === 'crm-clients/active' && JSON.stringify( w.last().payload ) === '{"id":"1","active":false}' );

	w.answer( 200, { client : Object.assign( {}, A, { active : false } ), accounts : [], switched : 0 } );
	check( '...the card is drawn again with the word of the other state, and what was typed is still typed, and still unsaved', w.button( w.mount, w.word('/_admin/crm-clients/label/activate') ) !== null && w.input( w.mount, 'company' ).value === 'Typed' && w.entry().isDirty() === true );

	const refused = opened( 'en_US', A );
	refused.button( refused.mount, refused.word('/_admin/crm-clients/label/deactivate') ).click();
	refused.answer( 404, { error : 'x', code : 'crm_clients_unknown', params : [] } );
	check( 'a switch that is refused says why', refused.texts().indexOf( refused.word('/_admin/error/crm_clients_unknown') ) !== -1 );

	const back = opened( 'en_US', A );
	back.input( back.mount, 'company' ).value = 'Typed';
	back.find( back.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	check( 'the back link asks the registry before it leaves unsaved input', back.guarded.length === 1 && back.guarded[0].join() === 'crm-clients' );
}

console.log( '\nThe accounts and the invitation\n' );

{
	const accounts = [ ACCOUNT, { id : 'bbbbbbbbbbbb', gone : false, email : 'b@example.org', name : '', active : false, login : null }, { id : 'cccccccccccc', gone : false, email : 'c@example.org', name : '<b>Bold</b>', active : true, login : null }, { id : 'dddddddddddd', gone : true } ];
	const w = opened( 'en_US', A, accounts );
	const box = w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-accounts') !== -1 } );
	const rows = w.all( box, function( el ) { return el.tagName === 'LI' } );
	const copy = function( row ) { return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } ) };

	check( 'one row for each account: the name, the address and when the member signed in last - or that they never did', rows.length === 4
		&& copy( rows[0] ).join('|') === [ 'Anna Nord', 'anna@example.org', w.said( '/_admin/crm-clients/state/last', '02.11.2026' ) ].join('|') && copy( rows[2] )[2] === w.word('/_admin/crm-clients/state/never') );
	check( 'an account without a name is named by its address once', copy( rows[1] ).join('|') === [ 'b@example.org', w.word('/_admin/crm-clients/state/never') ].join('|') );
	check( 'an account that is off wears the badge, one that is gone says so', w.all( rows[1], function( el ) { return el.textContent === w.word('/_admin/crm-clients/state/off') } ).length === 1 && copy( rows[3] ).join('|') === w.said( '/_admin/crm-clients/state/gone', 'dddd' ) );
	check( 'what Members sends is text: a name that is markup makes no element', copy( rows[2] )[0] === '<b>Bold</b>' && w.all( w.mount, function( el ) { return el.tagName === 'B' } ).length === 0 );
	check( 'a link leads to the panel of Members', w.find( box, function( el ) { return el.tagName === 'A' && el.href === '#members' } ) !== null );

	const empty = opened( 'en_US', A, [] );
	check( 'a client without accounts says so', empty.texts().indexOf( empty.word('/_admin/crm-clients/empty/accounts') ) !== -1 );

	const form = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } );
	w.input( form, 'email' ).value = 'new@example.org';
	w.input( form, 'name' ).value = 'New One';
	fire( form, 'submit' );
	check( 'the form posts crm-clients/invite with the id, the address and the name', w.last().action === 'crm-clients/invite' && JSON.stringify( w.last().payload ) === '{"id":"1","email":"new@example.org","name":"New One"}' );

	w.answer( 200, { account : Object.assign( {}, ACCOUNT, { id : 'eeeeeeeeeeee', email : 'new@example.org', name : 'New One', login : null } ), mailed : true } );
	check( 'an invitation that went out says so, and the new account is in the list', w.has('/_admin/crm-clients/invite/sent') === true && w.texts().indexOf( w.word('/_admin/crm-clients/invite/sent') ) !== -1 && w.texts().indexOf( 'New One' ) !== -1 );

	const unsent = opened( 'en_US', A, [] );
	const unsentForm = unsent.find( unsent.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } );
	unsent.input( unsentForm, 'email' ).value = 'x@example.org';
	fire( unsentForm, 'submit' );
	unsent.answer( 200, { account : Object.assign( {}, ACCOUNT, { id : 'ffffffffffff', email : 'x@example.org', name : '' } ), mailed : false } );
	check( 'an account made and not mailed says that, and not that the invitation is on its way', unsent.has('/_admin/crm-clients/invite/not-mailed') === true && unsent.texts().indexOf( unsent.word('/_admin/crm-clients/invite/not-mailed') ) !== -1 && unsent.texts().indexOf( unsent.word('/_admin/crm-clients/invite/sent') ) === -1 );

	const taken = opened( 'en_US', A, [] );
	const takenForm = taken.find( taken.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } );
	taken.input( takenForm, 'email' ).value = 'anna@example.org';
	fire( takenForm, 'submit' );
	taken.answer( 409, { error : 'x', code : 'crm_clients_taken', params : [], field : 'email' } );
	check( 'a refused invitation says its sentence in the form, which stays as it was', taken.has('/_admin/error/crm_clients_taken') === true && taken.texts().indexOf( taken.word('/_admin/error/crm_clients_taken') ) !== -1 && taken.input( takenForm, 'email' ).value === 'anna@example.org' );
}

console.log( '\nThe actions of an account\n' );

{
	const INVITED = { id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'b@example.org', name : '', active : true, login : null };
	const OFF = { id : 'cccccccccccc', gone : false, state : 'off', email : 'c@example.org', name : 'Carl', active : false, login : null };
	const GONE = { id : 'dddddddddddd', gone : true, state : 'gone' };
	const opens = function( locale ) { return opened( locale ?? 'en_US', A, [ ACCOUNT, INVITED, OFF, GONE ], true ) };
	const row = function( w, id ) { return w.find( w.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === id } ) };
	const labels = function( w, id ) { return w.all( row( w, id ), function( el ) { return el.tagName === 'BUTTON' } ).map( function( el ) { return el.textContent } ) };
	const text = function( w, key, ...params ) { return w.said( '/_admin/crm-clients/'+ key, ...params ) };
	const ids = function( w ) { return w.all( w.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id !== undefined } ).map( function( el ) { return el.dataset.id } ).join() };

	const w = opens();
	check( 'a signed-in account offers the link to sign in, switching off, the name and delete', labels( w, 'aaaaaaaaaaaa' ).join('|') === [ 'label/resend-login', 'label/switch-off', 'label/rename', 'label/delete-account' ].map( function( key ) { return w.word('/_admin/crm-clients/'+ key) } ).join('|') );
	check( 'an account that never signed in offers the invitation again instead', labels( w, 'bbbbbbbbbbbb' )[0] === w.word('/_admin/crm-clients/label/resend-invite') && labels( w, 'bbbbbbbbbbbb' ).indexOf( w.word('/_admin/crm-clients/label/resend-login') ) === -1 );
	check( 'an account that is off offers switching on, and its link button is off - and never calls itself a sign-in link when it never signed in', labels( w, 'cccccccccccc' ).indexOf( w.word('/_admin/crm-clients/label/switch-on') ) !== -1
		&& w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/resend-invite') ).disabled === true && w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/resend-login') ) === null );
	check( 'an account Members has not got any more offers delete and nothing else', labels( w, 'dddddddddddd' ).join('|') === w.word('/_admin/crm-clients/label/remove-row') );

	w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/resend-login') ).click();
	check( 'the link posts crm-clients/account-resend with the client and the account, and the button is off while it is out', w.last().action === 'crm-clients/account-resend' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"aaaaaaaaaaaa"}'
		&& w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/resend-login') ).disabled === true );
	w.answer( 200, { sent : true, kind : 'login' } );
	check( '...a link to sign in says so in the row, and the button is back', w.texts( row( w, 'aaaaaaaaaaaa' ) ).indexOf( text( w, 'resend/login' ) ) !== -1 && w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/resend-login') ).disabled === false );

	w.button( row( w, 'bbbbbbbbbbbb' ), w.word('/_admin/crm-clients/label/resend-invite') ).click();
	w.answer( 200, { sent : true, kind : 'invite' } );
	check( '...the invitation says that it is the invitation', w.texts( row( w, 'bbbbbbbbbbbb' ) ).indexOf( text( w, 'resend/invite' ) ) !== -1 && w.texts( row( w, 'bbbbbbbbbbbb' ) ).indexOf( text( w, 'resend/login' ) ) === -1 );

	w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/resend-login') ).click();
	w.answer( 429, { error : 'x', code : 'crm_clients_ratelimit', params : [] } );
	check( 'a cap of mails says its sentence in the row, and the row stays', w.has('/_admin/error/crm_clients_ratelimit') === true && w.texts( row( w, 'aaaaaaaaaaaa' ) ).indexOf( w.word('/_admin/error/crm_clients_ratelimit') ) !== -1 && ids( w ) === 'aaaaaaaaaaaa,bbbbbbbbbbbb,cccccccccccc,dddddddddddd' );

	w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/switch-off') ).click();
	check( 'switching off posts crm-clients/account-active with the value it wants', w.last().action === 'crm-clients/account-active' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"aaaaaaaaaaaa","active":false}' );
	w.answer( 200, { account : Object.assign( {}, ACCOUNT, { active : false, state : 'off' } ) } );
	check( '...the row is drawn again from the answer: the badge, the word of the other state, the link off, the order kept', w.all( row( w, 'aaaaaaaaaaaa' ), function( el ) { return el.textContent === w.word('/_admin/crm-clients/state/off') } ).length === 1
		&& w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/switch-on') ) !== null && w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/resend-login') ).disabled === true && ids( w ) === 'aaaaaaaaaaaa,bbbbbbbbbbbb,cccccccccccc,dddddddddddd' );

	w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/switch-on') ).click();
	check( 'switching on posts active true', JSON.stringify( w.last().payload ) === '{"id":"1","account":"cccccccccccc","active":true}' );
	w.answer( 409, { error : 'x', code : 'crm_clients_inactive', params : [] } );
	check( '...a client that is off refuses it, and the row says why', w.texts( row( w, 'cccccccccccc' ) ).indexOf( w.word('/_admin/error/crm_clients_inactive') ) !== -1 && w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/switch-on') ) !== null );

	w.button( row( w, 'bbbbbbbbbbbb' ), w.word('/_admin/crm-clients/label/rename') ).click();
	w.button( row( w, 'bbbbbbbbbbbb' ), w.word('/_admin/crm-clients/label/rename') ).click();
	const forms = w.all( row( w, 'bbbbbbbbbbbb' ), function( el ) { return el.tagName === 'FORM' } );
	check( 'the name opens one form in the row, with the name the account has', forms.length === 1 && w.input( forms[0], 'name' ).value === '' && w.requests.filter( function( request ) { return request.action === 'crm-clients/account-name' } ).length === 0 );
	w.button( forms[0], w.word('/_admin/crm-clients/label/cancel') ).click();
	check( '...Cancel closes it and asks nothing', w.all( row( w, 'bbbbbbbbbbbb' ), function( el ) { return el.tagName === 'FORM' } ).length === 0 );

	w.button( row( w, 'aaaaaaaaaaaa' ), w.word('/_admin/crm-clients/label/rename') ).click();
	const rename = w.find( row( w, 'aaaaaaaaaaaa' ), function( el ) { return el.tagName === 'FORM' } );
	check( '...and starts with the name it has', w.input( rename, 'name' ).value === 'Anna Nord' );
	w.input( rename, 'name' ).value = '<b>Anna</b> N.';
	fire( rename, 'submit' );
	check( 'saving posts crm-clients/account-name with the client, the account and the name', w.last().action === 'crm-clients/account-name' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"aaaaaaaaaaaa","name":"<b>Anna</b> N."}' );
	w.answer( 200, { account : Object.assign( {}, ACCOUNT, { name : '<b>Anna</b> N.', active : false, state : 'off' } ) } );
	check( '...the row has the new name as text and the form is gone', w.texts( row( w, 'aaaaaaaaaaaa' ) ).indexOf( '<b>Anna</b> N.' ) !== -1 && w.all( w.mount, function( el ) { return el.tagName === 'B' } ).length === 0 && w.all( row( w, 'aaaaaaaaaaaa' ), function( el ) { return el.tagName === 'FORM' } ).length === 0 );

	w.button( row( w, 'bbbbbbbbbbbb' ), w.word('/_admin/crm-clients/label/rename') ).click();
	fire( w.find( row( w, 'bbbbbbbbbbbb' ), function( el ) { return el.tagName === 'FORM' } ), 'submit' );
	w.answer( 400, { error : 'x', code : 'crm_clients_long', params : [ 120 ], field : 'name' } );
	check( 'a name that is refused says its sentence in the form, which stays', w.texts( row( w, 'bbbbbbbbbbbb' ) ).indexOf( w.said( '/_admin/error/crm_clients_long', 120 ) ) !== -1 && w.all( row( w, 'bbbbbbbbbbbb' ), function( el ) { return el.tagName === 'FORM' } ).length === 1 );

	const before = w.requests.length;
	w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/delete-account') ).click();
	const dialog = w.dialogs[0];
	check( 'delete asks first, in the words that say what goes and what stays', w.dialogs.length === 1 && dialog.open === true && w.texts( dialog ).indexOf( text( w, 'confirm/delete-account', 'Carl' ) ) !== -1 && w.requests.length === before );
	w.button( dialog, w.word('/_admin/crm-clients/label/cancel') ).click();
	check( '...No posts nothing', w.requests.length === before && ids( w ).indexOf('cccccccccccc') !== -1 );

	w.button( row( w, 'cccccccccccc' ), w.word('/_admin/crm-clients/label/delete-account') ).click();
	w.button( w.dialogs[1], w.word('/_admin/crm-clients/label/delete-account') ).click();
	check( '...Yes posts crm-clients/account-remove with the client and the account', w.last().action === 'crm-clients/account-remove' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"cccccccccccc"}' );
	w.answer( 200, { removed : true } );
	check( '...and the row is gone', ids( w ) === 'aaaaaaaaaaaa,bbbbbbbbbbbb,dddddddddddd' );

	w.button( row( w, 'dddddddddddd' ), w.word('/_admin/crm-clients/label/remove-row') ).click();
	w.button( w.dialogs[2], w.word('/_admin/crm-clients/label/remove-row') ).click();
	w.answer( 500, { error : 'x', code : 'crm_clients_write', params : [] } );
	check( 'an account that cannot be deleted says why in its row, which stays', w.texts( row( w, 'dddddddddddd' ) ).indexOf( w.word('/_admin/error/crm_clients_write') ) !== -1 );

	const plain = opened( 'en_US', A, [ ACCOUNT ] );
	plain.button( row( plain, 'aaaaaaaaaaaa' ), plain.word('/_admin/crm-clients/label/delete-account') ).click();
	check( 'without a <dialog> the question is a confirm() and the answer no posts nothing', plain.asked.length === 1 && plain.asked[0] === plain.said( '/_admin/crm-clients/confirm/delete-account', 'Anna Nord' ) && plain.last().action === 'crm-clients/get' );

	const late = opens();
	late.button( row( late, 'aaaaaaaaaaaa' ), late.word('/_admin/crm-clients/label/switch-off') ).click();
	const pending = late.last();
	late.find( late.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	late.answer( 200, { clients : [ A ] } );
	pending.callback( 200, { account : Object.assign( {}, ACCOUNT, { active : false, state : 'off' } ) } );
	check( 'an answer that comes after the card was left changes nothing: the list is on the screen and no account row', late.find( late.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === 'aaaaaaaaaaaa' } ) === null && late.find( late.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '1' } ) !== null );

	const swapped = opened( 'en_US', A, [ ACCOUNT ] );
	const inviteForm = swapped.find( swapped.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } );
	swapped.input( inviteForm, 'email' ).value = 'late@example.org';
	fire( inviteForm, 'submit' );
	const inviting = swapped.last();
	swapped.find( swapped.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	swapped.answer( 200, { clients : [ A, B ] } );
	swapped.find( swapped.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '2' } ).click();
	swapped.answer( 200, { client : B, accounts : [] } );
	inviting.callback( 200, { account : Object.assign( {}, ACCOUNT, { id : 'abababababab', email : 'late@example.org' } ), mailed : true } );
	check( 'an answer for the client that was left does not put its account into the card of another', swapped.find( swapped.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === 'abababababab' } ) === null );

	check( 'no word is missing from the text files', w.missing.length === 0 );
}

console.log( '\nLinking an account that exists\n' );

{
	const w = opened( 'en_US', A, [ ACCOUNT ], true );
	const link = function() { return w.button( w.mount, w.word('/_admin/crm-clients/label/link') ) };

	link().click();
	check( 'the button asks crm-clients/unlinked for the accounts that no client holds', w.last().action === 'crm-clients/unlinked' && JSON.stringify( w.last().payload ) === '{}' && w.dialogs.length === 0 );

	w.answer( 200, { accounts : [] } );
	check( 'none says so and opens no dialog', w.dialogs.length === 0 && w.texts().indexOf( w.word('/_admin/crm-clients/link/none') ) !== -1 );

	link().click();
	w.answer( 200, { accounts : [ { id : 'eeeeeeeeeeee', email : 'e@example.org', name : 'Emma', group : '' }, { id : 'ffffffffffff', email : 'f@example.org', name : '', group : 'staff' } ] } );
	const dialog = w.dialogs[0];
	check( 'the accounts are offered in a select by name and address, the address alone where there is no name', w.dialogs.length === 1 && dialog.open === true && w.all( w.select( dialog, 'link_account' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value+ '='+ el.textContent } ).join('|') === 'eeeeeeeeeeee=Emma · e@example.org|ffffffffffff=f@example.org'
		&& w.texts( dialog ).indexOf( w.word('/_admin/crm-clients/link/title') ) !== -1 );
	check( '...and the dialog says what linking does: the sentence is in its content, above the select', w.has('/_admin/crm-clients/link/message') === true && w.texts( dialog ).indexOf( w.word('/_admin/crm-clients/link/message') ) !== -1
		&& w.all( dialog, function( el ) { return el.tagName === 'P' && el.textContent === w.word('/_admin/crm-clients/link/message') } ).length === 1 );
	w.button( dialog, w.word('/_admin/crm-clients/label/cancel') ).click();
	check( 'Cancel posts nothing', w.requests.filter( function( request ) { return request.action === 'crm-clients/account-link' } ).length === 0 );

	link().click();
	w.answer( 200, { accounts : [ { id : 'eeeeeeeeeeee', email : 'e@example.org', name : 'Emma', group : '' }, { id : 'ffffffffffff', email : 'f@example.org', name : '', group : 'staff' } ] } );
	w.select( w.dialogs[1], 'link_account' ).value = 'ffffffffffff';
	w.button( w.dialogs[1], w.word('/_admin/crm-clients/label/link-do') ).click();
	check( 'Link posts crm-clients/account-link with the client and the account chosen', w.last().action === 'crm-clients/account-link' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"ffffffffffff"}' );

	w.answer( 200, { account : { id : 'ffffffffffff', gone : false, state : 'invited', email : 'f@example.org', name : '', active : true, login : null } } );
	check( '...the account is in the list and the box says it is in the group of the client', w.find( w.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === 'ffffffffffff' } ) !== null && w.texts().indexOf( w.word('/_admin/crm-clients/link/done') ) !== -1 );

	link().click();
	w.answer( 200, { accounts : [ { id : 'eeeeeeeeeeee', email : 'e@example.org', name : 'Emma', group : '' } ] } );
	w.button( w.dialogs[2], w.word('/_admin/crm-clients/label/link-do') ).click();
	w.answer( 409, { error : 'x', code : 'crm_clients_held', params : [], field : 'account' } );
	check( 'an account another client holds meanwhile is refused with its sentence beside the button', w.texts( w.find( w.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-link') !== -1 } ) ).indexOf( w.word('/_admin/error/crm_clients_held') ) !== -1 );

	link().click();
	w.answer( 409, { error : 'x', code : 'crm_clients_members', params : [] } );
	check( 'the accounts that cannot be asked for are a refusal with its sentence', w.texts().indexOf( w.word('/_admin/error/crm_clients_members') ) !== -1 );
}

console.log( '\nThe dialog without <dialog>, a row that is gone, a client that is off, Members off\n' );

{
	const plain = opened( 'en_US', A, [ ACCOUNT ] );
	plain.button( plain.mount, plain.word('/_admin/crm-clients/label/link') ).click();
	plain.answer( 200, { accounts : [ { id : 'eeeeeeeeeeee', email : 'e@example.org', name : 'Emma', group : '' }, { id : 'ffffffffffff', email : 'f@example.org', name : '', group : '' } ] } );
	check( 'a browser without <dialog> is asked about the account it would link - the first - by name, and the answer no posts nothing', plain.asked.length === 1 && plain.asked[0] === plain.said( '/_admin/crm-clients/link/confirm', 'Emma · e@example.org' ) && plain.requests.filter( function( request ) { return request.action === 'crm-clients/account-link' } ).length === 0 );

	const GONE = { id : 'dddddddddddd', gone : true, state : 'gone' };
	const w = opened( 'en_US', A, [ ACCOUNT, GONE ], true );
	const row = w.find( w.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === 'dddddddddddd' } );
	check( 'the row of an account that is gone has the button "remove row", not "delete account"', w.button( row, w.word('/_admin/crm-clients/label/remove-row') ) !== null && w.button( row, w.word('/_admin/crm-clients/label/delete-account') ) === null );
	w.button( row, w.word('/_admin/crm-clients/label/remove-row') ).click();
	check( '...it asks in its own words - the row of a deleted account - and does not say the account is deleted in Members', w.has('/_admin/crm-clients/confirm/remove-row') === true && w.texts( w.dialogs[0] ).indexOf( w.word('/_admin/crm-clients/confirm/remove-row') ) !== -1
		&& w.texts( w.dialogs[0] ).indexOf( w.said( '/_admin/crm-clients/confirm/delete-account', w.said( '/_admin/crm-clients/state/gone', 'dddd' ) ) ) === -1 );
	w.button( w.dialogs[0], w.word('/_admin/crm-clients/label/remove-row') ).click();
	check( '...and posts the removal', w.last().action === 'crm-clients/account-remove' && JSON.stringify( w.last().payload ) === '{"id":"1","account":"dddddddddddd"}' );

	// A row that is gone names itself: the last four characters of the id - or the address the row has stored
	const twins = opened( 'en_US', A, [ GONE, { id : 'eeeeeeee0042', gone : true, state : 'gone' }, { id : 'ffffffffffff', gone : true, state : 'gone', email : 'old@example.org' } ] );
	const named = twins.all( twins.mount, function( el ) { return el.tagName === 'STRONG' } ).map( function( el ) { return el.textContent } );
	check( 'two rows that are gone are told apart: each says so and names the last four characters of its id', named[0] === twins.said( '/_admin/crm-clients/state/gone', 'dddd' ) && named[1] === twins.said( '/_admin/crm-clients/state/gone', '0042' ) && named[0] !== named[1]
		&& twins.word('/_admin/crm-clients/state/gone').indexOf('%s') !== -1 );
	check( '...and a row that has an address stored names that', named[2] === twins.said( '/_admin/crm-clients/state/gone', 'old@example.org' ) );
	const german = opened( 'de_DE', A, [ GONE ], true );
	check( 'the words are German', german.has('/_admin/crm-clients/confirm/remove-row') === true && german.button( german.mount, german.word('/_admin/crm-clients/label/remove-row') ) !== null && german.missing.length === 0 );

	const off = opened( 'en_US', B, [ OFF_ACCOUNT, ACCOUNT ] );
	check( 'the card of a client that is off says so once, and offers no link, no invitation and no switch-on', off.texts().indexOf( off.word('/_admin/crm-clients/accounts/inactive') ) !== -1 && off.button( off.mount, off.word('/_admin/crm-clients/label/link') ) === null
		&& off.find( off.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } ) === null && off.button( off.mount, off.word('/_admin/crm-clients/label/switch-on') ).disabled === true
		&& off.button( off.mount, off.word('/_admin/crm-clients/label/resend-login') ).disabled === true );
	check( '...a client that is on says nothing of the kind', opened( 'en_US', A, [ ACCOUNT ] ).texts().indexOf( off.word('/_admin/crm-clients/accounts/inactive') ) === -1 );

	const members = opened( 'en_US', A, [ { id : 'aaaaaaaaaaaa', gone : true, state : 'members-off' }, { id : 'bbbbbbbbbbbb', gone : true, state : 'members-off' } ] );
	check( 'with Members off the card says that in one sentence instead of rows that read "deleted", and offers nothing to do', members.texts().indexOf( members.word('/_admin/crm-clients/accounts/members-off') ) !== -1 && members.texts().indexOf( members.word('/_admin/crm-clients/state/gone') ) === -1
		&& members.all( members.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id !== undefined } ).length === 0 && members.button( members.mount, members.word('/_admin/crm-clients/label/link') ) === null );
}

console.log( '\nThe notices and the focus\n' );

{
	const card = function( w ) { return w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ) };
	const invite = function( w ) { return w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-invite') !== -1 } ) };
	const notices = function( w ) { return w.all( w.mount, function( el ) { return String( el.className ).indexOf('crm-notice') !== -1 } ) };

	const w = opened( 'en_US', A, [ ACCOUNT ] );
	w.input( invite( w ), 'email' ).value = 'n@example.org';
	fire( invite( w ), 'submit' );
	w.answer( 200, { account : Object.assign( {}, ACCOUNT, { id : 'eeeeeeeeeeee', email : 'n@example.org', login : null, state : 'invited' } ), mailed : false } );
	check( 'the notice of an invitation stands at the top of the card, under the back link and above the form of the client - not in the box of the accounts', notices( w ).length === 1 && notices( w )[0].textContent === w.word('/_admin/crm-clients/invite/not-mailed')
		&& w.mount.children.indexOf( notices( w )[0] ) === 1 && w.mount.children.indexOf( notices( w )[0] ) < w.mount.children.indexOf( card( w ) ) );
	check( '...and it is scrolled into view where it stands: an invitation sent from the bottom of a long card does not say its sentence out of sight', w.scrolled.length === 1 && w.scrolled[0].element === notices( w )[0] && JSON.stringify( w.scrolled[0].options ) === '{"block":"nearest"}' );

	w.input( invite( w ), 'email' ).value = 'o@example.org';
	fire( invite( w ), 'submit' );
	w.answer( 200, { account : Object.assign( {}, ACCOUNT, { id : 'ffffffffffff', email : 'o@example.org' } ), mailed : true } );
	check( '...and there is one notice at a time, which is scrolled into view as well', notices( w ).length === 1 && notices( w )[0].textContent === w.word('/_admin/crm-clients/invite/sent') && w.scrolled.length === 2 && w.scrolled[1].element === notices( w )[0] );

	const refused = drawn('en_US');
	refused.button( refused.mount, refused.word('/_admin/crm-clients/label/new') ).click();
	refused.input( refused.mount, 'name' ).value = 'Carla';
	refused.input( refused.mount, 'account_email' ).value = 'taken@example.org';
	fire( card( refused ), 'submit' );
	refused.answer( 200, { client : Object.assign( {}, A, { id : 7, number : 'K-0007' } ), first : { account : null, mailed : false, refused : { code : 'crm_clients_taken', params : [], field : 'email' } } } );
	check( 'the sentence about a refused first account is at the top of the card as well, and in view', notices( refused ).length === 1 && refused.mount.children.indexOf( notices( refused )[0] ) === 1 && refused.scrolled.length === 1 && refused.scrolled[0].element === notices( refused )[0] && refused.scrolled[0].options.block === 'nearest' );

	const back = drawn('en_US');
	back.find( back.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '2' } ).click();
	back.answer( 200, { client : B, accounts : [] } );
	back.find( back.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	back.answer( 200, { clients : [ A, B, C ] } );
	check( 'back from a card the focus is on the row that was opened', back.find( back.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '2' } ).focused === true && back.find( back.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '1' } ).focused !== true );
}

console.log( '\nThe first account of a new client\n' );

{
	const w = drawn('en_US');
	w.button( w.mount, w.word('/_admin/crm-clients/label/new') ).click();
	const form = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } );

	check( 'a new client has the fields of its first account: an address and a name, empty', w.input( form, 'account_email' ).value === '' && w.input( form, 'account_name' ).value === '' && w.input( form, 'account_email' ).type === 'email' );
	check( 'the card of a client that exists has none', opened( 'en_US', A ).input( opened( 'en_US', A ).mount, 'account_email' ) === null );

	w.input( form, 'name' ).value = 'Carla';
	w.input( form, 'account_email' ).value = 'carla@example.org';
	w.input( form, 'account_name' ).value = 'Carla C.';
	check( 'what is typed in them is unsaved input', w.entry().isDirty() === true );
	fire( form, 'submit' );
	check( 'a new client is posted with the address and the name of the first account', w.last().action === 'crm-clients/save' && w.last().payload.account_email === 'carla@example.org' && w.last().payload.account_name === 'Carla C.' && w.last().payload.name === 'Carla' && Object.keys( w.last().payload ).indexOf('id') === -1 );

	const made = Object.assign( {}, A, { id : 7, number : 'K-0007', company : '', name : 'Carla', accounts : 1, active_accounts : 1 } );
	const account = { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : 'carla@example.org', name : 'Carla C.', active : true, login : null };
	w.answer( 200, { client : made, first : { account : account, mailed : true, refused : null } } );
	check( 'the card of the new client lists the account and says the invitation is on its way', w.find( w.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id === 'eeeeeeeeeeee' } ) !== null && w.texts().indexOf( w.word('/_admin/crm-clients/invite/sent') ) !== -1 && w.input( w.mount, 'account_email' ) === null );

	const unmailed = drawn('en_US');
	unmailed.button( unmailed.mount, unmailed.word('/_admin/crm-clients/label/new') ).click();
	unmailed.input( unmailed.mount, 'name' ).value = 'Carla';
	unmailed.input( unmailed.mount, 'account_email' ).value = 'carla@example.org';
	fire( unmailed.find( unmailed.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ), 'submit' );
	unmailed.answer( 200, { client : made, first : { account : account, mailed : false, refused : null } } );
	check( 'an account made and not mailed says that', unmailed.texts().indexOf( unmailed.word('/_admin/crm-clients/invite/not-mailed') ) !== -1 && unmailed.texts().indexOf( unmailed.word('/_admin/crm-clients/invite/sent') ) === -1 );

	const refused = drawn('en_US');
	refused.button( refused.mount, refused.word('/_admin/crm-clients/label/new') ).click();
	refused.input( refused.mount, 'name' ).value = 'Carla';
	refused.input( refused.mount, 'account_email' ).value = 'taken@example.org';
	fire( refused.find( refused.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ), 'submit' );
	refused.answer( 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_taken', params : [], field : 'email' } } } );
	check( 'a client made with an account that was refused is made: its card is there, no account, and the sentence says the client is made and why the account is not', refused.texts().indexOf( refused.said( '/_admin/crm-clients/first/refused', refused.word('/_admin/error/crm_clients_taken') ) ) !== -1
		&& refused.all( refused.mount, function( el ) { return el.tagName === 'LI' && el.dataset.id !== undefined } ).length === 0 && refused.texts( refused.mount ).indexOf( 'K-0007 Carla' ) !== -1 );

	const long = drawn('en_US');
	long.button( long.mount, long.word('/_admin/crm-clients/label/new') ).click();
	long.input( long.mount, 'name' ).value = 'Carla';
	long.input( long.mount, 'account_name' ).value = 'x';
	long.input( long.mount, 'account_email' ).value = 'a@example.org';
	fire( long.find( long.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ), 'submit' );
	long.answer( 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_long', params : [ 120 ], field : 'name' } } } );
	check( '...the parameters of the code are put into its sentence', long.texts().indexOf( long.said( '/_admin/crm-clients/first/refused', long.said( '/_admin/error/crm_clients_long', 120 ) ) ) !== -1 );

	const alone = drawn('en_US');
	alone.button( alone.mount, alone.word('/_admin/crm-clients/label/new') ).click();
	alone.input( alone.mount, 'name' ).value = 'Carla';
	fire( alone.find( alone.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ), 'submit' );
	alone.answer( 200, { client : made } );
	check( 'a client made without an address is made alone: no sentence about an account', alone.texts().indexOf( alone.word('/_admin/crm-clients/invite/sent') ) === -1 && alone.texts().indexOf( alone.word('/_admin/crm-clients/invite/not-mailed') ) === -1 && alone.texts().indexOf( alone.word('/_admin/crm-clients/empty/accounts') ) !== -1 );
}

console.log( '\nA client that is switched off\n' );

{
	const w = opened( 'en_US', A, [ ACCOUNT ] );
	w.button( w.mount, w.word('/_admin/crm-clients/label/deactivate') ).click();
	w.answer( 200, { client : Object.assign( {}, A, { active : false } ), accounts : [ Object.assign( {}, ACCOUNT, { active : false, state : 'off' } ) ], switched : 1 } );
	check( 'the accounts are drawn as the server has them - off - and the box says how many were switched off, and that they stay off', w.texts().indexOf( w.said( '/_admin/crm-clients/active/switched', 1 ) ) !== -1
		&& w.all( w.mount, function( el ) { return el.textContent === w.word('/_admin/crm-clients/state/off') } ).length === 1 );

	const none = opened( 'en_US', A, [] );
	none.button( none.mount, none.word('/_admin/crm-clients/label/deactivate') ).click();
	none.answer( 200, { client : Object.assign( {}, A, { active : false } ), accounts : [], switched : 0 } );
	check( 'a client without accounts to switch off says nothing about it', none.texts().indexOf( none.said( '/_admin/crm-clients/active/switched', 0 ) ) === -1 );
}

console.log( '\nThe script itself\n' );

{
	// The harness does not parse markup, so an element made of the server's text cannot be seen above: the pin is on the
	// source - every value goes in as text, and the only markup assigned is the empty string that clears a region
	const source = fs.readFileSync( path.join( root, OWN+ 'assets/admin.js' ), 'utf8' );

	check( 'the script assigns no markup: innerHTML only to clear, no outerHTML, no insertAdjacentHTML, no document.write', source.match( /innerHTML\s*=(?!\s*'';)/g ) === null && /outerHTML|insertAdjacentHTML|document\.write/.test( source ) === false );
}

console.log( '\nThe German words\n' );

{
	const w = drawn('de_DE');
	check( 'the German screen has every word it asks for', w.missing.length === 0 && w.texts().indexOf( w.said( '/_admin/crm-clients/label/accounts', 2, 1 ) ) !== -1 );

	const card = opened( 'de_DE', A, [ ACCOUNT, { id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'b@example.org', name : '', active : true, login : null }, { id : 'dddddddddddd', gone : true, state : 'gone' } ], true );
	card.button( card.mount, card.word('/_admin/crm-clients/label/link') ).click();
	card.answer( 200, { accounts : [ { id : 'eeeeeeeeeeee', email : 'e@example.org', name : '', group : '' } ] } );
	check( 'the German card with its rows, its dialog and its first-account fields has every word', card.missing.length === 0 && card.has('/_admin/crm-clients/label/resend-invite') === true && card.has('/_admin/crm-clients/confirm/delete-account') === true
		&& card.texts( card.dialogs[0] ).indexOf( card.word('/_admin/crm-clients/link/title') ) !== -1 && /\bDu\b|Deine|Dein/.test( card.word('/_admin/crm-clients/active/switched') ) === true );
	check( '...and the sentence of the setup puts no noun behind a number, so that one of anything does not read as many', /%s\s+\p{L}/u.test( card.word('/_admin/crm-clients/setup/done') ) === false );
	check( '...a row that is gone and the sentence of the setup have a place for what they name, in German as in English', card.word('/_admin/crm-clients/state/gone').split('%s').length === 2 && card.word('/_admin/crm-clients/setup/done').split('%s').length === 6
		&& card.texts().indexOf( card.said( '/_admin/crm-clients/state/gone', 'dddd' ) ) !== -1 );
}

done();

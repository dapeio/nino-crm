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
 *										says whether the mail went out or why not, and the accounts are rows of text. Only Nino itself
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
 *
 *	@return		{Object}
 */
function world( locale ) {

	const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( OWN+ 'text/'+ locale+ '.php' ) );
	const missing = [];
	const requests = [];
	const registered = [];
	const guarded = [];

	const mount = element('div');
	mount.id = 'crm-clients-app';

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
		admin : {
			dirty : {
				register : function( name, entry ) { registered.push( { name : name, entry : entry } ) },
				refresh : function() {},
				guard : function( names, proceed ) { guarded.push( names ); proceed() },
			},
			router : { href : function( panel ) { return '#'+ panel } },
		},
	};

	const window = { Nino : Nino };
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
		missing : missing,
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		button : function( from, text ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
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

const A = { id : 1, number : 'K-0001', company : 'Studio Nord', name : 'Anna Nord', street : 'Hafenstr. 1', zip : '20095', city : 'Hamburg', country : 'DE', email : 'anna@example.org', phone : '+49 40 1', vat_id : 'DE123456789', notes : 'Notes', active : true, accounts : 2, group : 'k-0001' };
const B = { id : 2, number : 'K-0002', company : '', name : 'Ben Süd', street : '', zip : '', city : '', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : false, accounts : 1, group : 'k-0002' };
const C = { id : 3, number : 'K-0003', company : '<img src=x onerror=alert(1)>', name : '', street : '', zip : '', city : '', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : true, accounts : 0, group : 'k-0003' };
const ALL = [ 'module', 'data', 'members', 'files', 'review', 'sign', 'pdf', 'portal' ];
const OK = ALL.map( function( key ) { return { key : key, state : 'ok' } } );
const FIELDS = [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id', 'notes' ];

/** The panel shown, its list answered and its status answered */
function drawn( locale, clients, items ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, { clients : clients ?? [ A, B, C ] } );
	w.answer( 200, { items : items ?? OK } );
	return w;
}

/** The card of a client open: the row pressed and the card answered */
function opened( locale, client, accounts ) {
	const w = drawn( locale );
	w.find( w.mount, function( el ) { return el.tagName === 'BUTTON' && el.dataset.id === String( client.id ) } ).click();
	w.answer( 200, { client : client, accounts : accounts ?? [] } );
	return w;
}

const ACCOUNT = { id : 'aaaaaaaaaaaa', gone : false, email : 'anna@example.org', name : 'Anna Nord', active : true, login : '2026-11-02T10:15:30Z' };

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
	check( 'showing it asks for crm-clients/list, once while the answer is out, with the search that is empty', w.requests.length === 1 && w.requests[0].action === 'crm-clients/list' && JSON.stringify( w.requests[0].payload ) === '{"search":""}' );
	check( '...and registers its entry under its uri, which holds nothing unsaved', w.registered.length === 1 && w.registered[0].name === 'crm-clients' && w.entry().isDirty() === false );

	w.answer( 200, { clients : [ A, B, C ] } );
	check( 'the status is asked for once the list is drawn', w.last().action === 'crm-clients/status' && JSON.stringify( w.last().payload ) === '{}' );
}

console.log( '\nThe rows\n' );

{
	const w = drawn('en_US');
	const rows = w.all( w.mount, function( el ) { return el.tagName === 'LI' && el.children.length === 1 && el.children[0].tagName === 'BUTTON' } );
	const copy = function( row ) { return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } ) };

	check( 'one row for each client, in the order of the answer', rows.length === 3 && rows.map( function( row ) { return row.children[0].dataset.id } ).join() === '1,2,3' );
	check( 'a row says the number and the company, the name where there is no company, and the accounts - one in the singular', w.has('/_admin/crm-clients/label/account') === true && w.has('/_admin/crm-clients/label/accounts') === true
		&& copy( rows[0] ).join('|') === 'K-0001 Studio Nord|'+ w.said( '/_admin/crm-clients/label/accounts', 2 ) && copy( rows[1] ).join('|') === 'K-0002 Ben Süd|'+ w.said( '/_admin/crm-clients/label/account', 1 )
		&& copy( rows[2] )[1] === w.said( '/_admin/crm-clients/label/accounts', 0 ) );
	check( 'the singular is not the plural with a 1 in it', w.said( '/_admin/crm-clients/label/account', 1 ) !== w.said( '/_admin/crm-clients/label/accounts', 1 ) );
	check( 'only the inactive client wears the badge', w.has('/_admin/crm-clients/state/inactive') === true && w.all( rows[1], function( el ) { return el.textContent === w.word('/_admin/crm-clients/state/inactive') } ).length === 1
		&& w.all( rows[0], function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } ).length === 0 );
	check( 'what the server sends is text and nothing else: a company that is markup is a string, no element comes of it', copy( rows[2] )[0] === 'K-0003 '+ C.company && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'SCRIPT' } ).length === 0 );
	check( 'no word is missing from the text files', w.missing.length === 0 );

	const empty = drawn( 'en_US', [] );
	check( 'no client says so', empty.texts().indexOf( empty.word('/_admin/crm-clients/empty/clients') ) !== -1 && empty.texts().indexOf( empty.word('/_admin/crm-clients/empty/found') ) === -1 );

	const form = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-search') !== -1 } );
	w.input( form, 'search' ).value = 'nord';
	fire( form, 'submit' );
	check( 'the search posts crm-clients/list again with what was typed', w.last().action === 'crm-clients/list' && JSON.stringify( w.last().payload ) === '{"search":"nord"}' );

	w.answer( 200, { clients : [] } );
	check( '...and a search that finds nothing says that, and not that there are no clients', w.texts().indexOf( w.word('/_admin/crm-clients/empty/found') ) !== -1 && w.texts().indexOf( w.word('/_admin/crm-clients/empty/clients') ) === -1 );
}

console.log( '\nThe status and the setup\n' );

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

	missing.answer( 200, { added : { routes : [ 'a', 'b' ], templates : [ 'x' ], texts : 7, paths : true, start : true }, existed : { routes : [ 'c' ], templates : [ 'y', 'z' ], paths : false } } );
	check( 'the box is asked for again after it', missing.last().action === 'crm-clients/status' );
	missing.answer( 200, { items : OK } );
	check( 'what the setup did stays on the screen after the box is drawn again', missing.has('/_admin/crm-clients/setup/done') === true && missing.texts().indexOf( missing.said( '/_admin/crm-clients/setup/done', 2, 1, 7, 3 ) ) !== -1 );

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
	w.find( w.mount, function( el ) { return el.tagName === 'BUTTON' && el.dataset.id === '1' } ).click();
	check( 'a row asks for crm-clients/get with the id as a string', w.last().action === 'crm-clients/get' && JSON.stringify( w.last().payload ) === '{"id":"1"}' );

	w.answer( 200, { client : A, accounts : [] } );
	check( 'the card holds the fields of the client', FIELDS.every( function( name ) { return w.input( w.mount, name ).value === A[name] } ) && w.all( w.mount, function( el ) { return el.tagName === 'H3' } )[0].textContent === 'K-0001 Studio Nord' );
	check( 'the phone is a text field for the workbench, and asks a phone keyboard', w.input( w.mount, 'phone' ).type === 'text' && w.input( w.mount, 'phone' ).inputMode === 'tel' );

	const fresh = drawn('en_US');
	fresh.button( fresh.mount, fresh.word('/_admin/crm-clients/label/new') ).click();
	check( 'a new client starts with the country DE and the rest empty, and asks nothing of the server', fresh.input( fresh.mount, 'country' ).value === 'DE' && fresh.input( fresh.mount, 'company' ).value === '' && fresh.requests.filter( function( request ) { return request.action === 'crm-clients/get' } ).length === 0 );
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

	w.answer( 200, { client : Object.assign( {}, A, { active : false } ) } );
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
		&& copy( rows[0] ).join('|') === [ 'Anna Nord', 'anna@example.org', w.said( '/_admin/crm-clients/state/last', '2026-11-02' ) ].join('|') && copy( rows[2] )[2] === w.word('/_admin/crm-clients/state/never') );
	check( 'an account without a name is named by its address once', copy( rows[1] ).join('|') === [ 'b@example.org', w.word('/_admin/crm-clients/state/never') ].join('|') );
	check( 'an account that is off wears the badge, one that is gone says so', w.all( rows[1], function( el ) { return el.textContent === w.word('/_admin/crm-clients/state/off') } ).length === 1 && copy( rows[3] ).join('|') === w.word('/_admin/crm-clients/state/gone') );
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
	check( 'the German screen has every word it asks for', w.missing.length === 0 && w.texts().indexOf( w.said( '/_admin/crm-clients/label/accounts', 2 ) ) !== -1 );
}

done();

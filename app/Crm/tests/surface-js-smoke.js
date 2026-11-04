/**
 *	Crm
 *	surface-js-smoke.js		What the script of the surface does (Surface/assets/crm.js, with money.js) over the stand-in of the
 *												kernel's tests/harness.js: the router (each hash draws its screen and marks the rail), the shell (the
 *												search that "/" finds, the menus that Escape and a click elsewhere close, the dialogs built as <dialog>
 *												with a question and two buttons, the toasts, the element helper that makes a string text and never
 *												markup), the Übersicht from a fixture, Belege (the chips with their counts, the search, the sort by
 *												cents, the sum, the pages of fifty, the click on a row), Beleg (the primary action and the menu by
 *												category and state, the tabs, the steps it posts), Entwurf (the arithmetic of the lines mirrored from
 *												money.js, the insert from the catalogue, removing a line, the question before finalising, the payload
 *												that is posted, a refusal beside its field), the answers of the endpoint that end a session and the screens of
 *												part 2: Kunden (the table with its sort, search, pages and the click on a row), the Kundenkarte (the blocks,
 *												the accounts' rows and every step on them, the link dialog, the invitation, the gone row, the client that is
 *												off, Members off, the Stammdaten edited, the tabs Belege, Portal and Notizen), Neuer Kunde (the first
 *												account, the refusals), Positionen (a column of words for each language), Einstellungen (the percent text and
 *												the formats posted, the refusal at the field, the status and the button) and, over the kernel's Nino.admin.js,
 *												the script of the one panel CRM of the workbench; the money arithmetic, case for case with Money.php. Only
 *												the browser is a stand-in; the words are the real ones, from Surface/text/de_DE.php and the text files
 *												of the panels.
 *
 *	Usage: node app/Crm/tests/surface-js-smoke.js
 */

'use strict';

/* global require, __dirname, process, setImmediate, Buffer */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, source, load, findAll, fire, element, documentOf, matches } = require( path.join( root, 'tests/harness.js' ) );

const OWN = 'app/Crm/Surface/';
const NBSP = '\u00a0';

// --- the words: the real ones --------------------------------------------------------------------------------------

/**
 *	The flat words of the surface and the sentences of the codes of the panels, as the page gets them
 *
 *	@return		{Object}
 */
function words() {

	const map = {};
	const re = /^\t'([a-z0-9._-]+)' => '((?:[^'\\]|\\.)*)',$/gm;
	const text = source( OWN+ 'text/de_DE.php' );
	let found;

	while( ( found = re.exec( text ) ) )
		map[found[1]] = found[2].replace( /\\(['\\])/g, '$1' );

	[ 'app/Crm/Clients/Admin/text/de_DE.php', 'app/Crm/Documents/Admin/text/de_DE.php', 'app/Crm/Crm/Admin/text/de_DE.php' ].forEach( function( file ) {
		const sentences = fills( file );
		Object.keys( sentences ).forEach( function( key ) {

			const found = /^\/_admin\/crm\/(status|setup)\/([a-z-]+)$/.exec( key );

			if( key.indexOf( '/_admin/error/' ) === 0 )
				map['error.'+ key.slice( '/_admin/error/'.length )] = sentences[key];
			else if( found !== null )
				map[found[1]+ '.'+ found[2]] = sentences[key];
		} );
	} );

	return map;
}

const texts = words();

// --- fixtures ------------------------------------------------------------------------------------------------------

const settings = { vat_mode : 'regular', vat_rates : '1900,700,0', payment_days : '14', offer_days : '30', currency : '€', rates : [ 1900, 700, 0 ] };
const smallBusiness = Object.assign( {}, settings, { vat_mode : 'small_business' } );

const client = { company : 'Nordlicht Records GmbH', name : 'Lena Brandt', street : 'Hafenstraße 12', zip : '20457', city : 'Hamburg', country : 'DE', number : 'K-0001', vat_id : 'DE123456789', email : 'lena@nordlicht.example' };
const clients = [
	{ id : 1, number : 'K-0001', title : 'Nordlicht Records GmbH', active : true },
	{ id : 2, number : 'K-0002', title : 'Jonas Richter', active : true },
	{ id : 3, number : 'K-0003', title : 'Alt AG', active : false },
];
const items = [
	{ id : 1, title : 'Mixing', description : 'Mischung eines Songs\nbis 48 Spuren', unit : 'Std.', price_net : 9000, vat_rate : 1900, active : true },
	{ id : 2, title : 'Mastering', description : '', unit : 'Song', price_net : 11000, vat_rate : 700, active : true },
];
const templates = [
	{ key : 'rechnung', name : 'Rechnung Standard', inputs : [ { name : 'intro', label : 'Einleitung', multiline : true, required : false } ] },
	{ key : 'vertrag', name : 'Dienstleistungsvertrag', inputs : [ { name : 'zusatz', label : 'Zusatz', multiline : false, required : true } ] },
];
const offers = [ { id : 13, number : 'A-2026-0011', date : '2026-10-28', client_id : 2, client_title : 'Jonas Richter' } ];

/**
 *	A row of the list
 *
 *	@param		{Object}	over
 *
 *	@return		{Object}
 */
function row( over ) {
	return Object.assign( { id : 1, category : 'invoice', number : 'R-2026-0001', title : '', state : 'open', released : false, sign_state : '', expired : false, overdue : false, date : '2026-10-02', due_date : '2026-10-16', valid_until : null,
		gross : 100000, client_id : 1, client_title : 'Nordlicht Records GmbH', parent_id : null }, over );
}

/**
 *	A document as get has it
 *
 *	@param		{Object}	over
 *
 *	@return		{Object}
 */
function full( over ) {
	return Object.assign( { id : 11, category : 'invoice', client_id : 1, parent_id : null, number : 'R-2026-0007', state : 'open', date : '2026-10-02', due_date : '2026-10-16', valid_until : null, period_from : '2026-09-14', period_to : '2026-09-30',
		vat_mode : 'regular', reverse_charge : false, net : 100000, vat : 19000, gross : 119000, template_key : 'rechnung', inputs : {}, notes : 'Rechnungen an die Buchhaltung', pdf_path : '/data/crm/pdf/2026/R-2026-0007.pdf', pdf_sha256 : 'abcdef0123456789abcdef',
		finalised : '2026-10-02T09:12:00Z', sent_at : null, paid_at : null, created : '2026-10-01T08:00:00Z', updated : '2026-10-02T09:12:00Z', title : '', released : false, released_at : null, sign_id : '', sign_state : '', signed_at : null, expired : false, overdue : false,
		lines : [
			{ id : 1, document_id : 11, sort : 1, title : 'Recording-Tag', description : 'Studio A', qty : 4000, unit : 'Tag', price_net : 20000, vat_rate : 1900, amount_net : 80000 },
			{ id : 2, document_id : 11, sort : 2, title : 'Mixing', description : '', qty : 2000, unit : 'Std.', price_net : 10000, vat_rate : 1900, amount_net : 20000 },
		],
		client : client, parent : null, parent_number : '', children : [], pdf_url : '/.crm/pdf/11', sign_shown_url : '', sign_signed_url : '' }, over );
}

/**
 *	The answer of get
 *
 *	@param		{Object}	over						Fields of the document
 *	@param		{Object}	[extra]					Fields of the answer
 *
 *	@return		{Object}
 */
function view( over, extra ) {
	return Object.assign( { document : full( over ), templates : templates, clients : clients, settings : settings, items : items, offers : offers, accounts : [], sign_days : 14 }, extra || {} );
}

const summary = { count : 3, gross : 150000, overdue_count : 2, overdue_gross : 90000, overdue_oldest : '2026-03-15', offers_count : 1, offers_gross : 214200, paid_year : 2026, paid_count : 4, paid_gross : 2030000, drafts_count : 1, drafts_last : '2026-11-03' };

// --- the browser: a stand-in ---------------------------------------------------------------------------------------

/**
 *	The page, its window, a server that answers what the test says, and the script loaded into it
 *
 *	@param		{Object}	[options]				hash, answers ({ action: function( data ) { return [ status, body ] } }), search ('?')
 *
 *	@return		{Object}
 */
function world( options ) {

	const opts = Object.assign( { hash : '', answers : {} }, options || {} );
	const calls = [];
	const timers = [];
	const hashListeners = [];
	const documentListeners = {};
	const windowListeners = {};
	const dialogs = [];
	const opened = [];
	const storage = {};
	const reloads = [];

	const mount = element('div');
	mount.id = 'root';

	const nav = element('nav');
	nav.id = 'nav';
	[ 'uebersicht', 'belege', 'kunden', 'positionen', 'einstellungen' ].forEach( function( key ) {
		const anchor = element('a');
		anchor.setAttribute( 'data-r', key );
		anchor.setAttribute( 'href', '#'+ key );
		nav.appendChild( anchor );
	} );
	const main = element('main');
	main.id = 'main';
	const toast = element('div');
	toast.id = 'toast';
	const dialogRoot = element('div');
	dialogRoot.id = 'dialogs';
	const count = element('span');
	count.id = 'n-belege';
	const clientCount = element('span');
	clientCount.id = 'n-kunden';
	[ nav, main, toast, dialogRoot, count, clientCount ].forEach( function( node ) { mount.appendChild( node ) } );

	const block = element('script');
	block.id = 'crm-data';
	block.textContent = JSON.stringify( { base : '', csrf : 'token-1', user : { name : 'crm@example.com' }, perm : '/_admin/crm/manage', texts : texts, links : { workbench : '/_admin/', site : '/', items : '/_admin/#elements/positionen', pdf : '/_admin/#pdf', members : '/_admin/#members', users : '/_admin/#users' } } );
	mount.appendChild( block );

	const page = documentOf( mount );
	const make = page.createElement;

	page.createElement = function( tag ) {
		const el = make( tag );

		if( tag === 'dialog' ) {
			el.open = false;
			el.showModal = function() { el.open = true; dialogs.push( el ) };
			el.close = function() {
				if( el.open === true ) {
					el.open = false;
					fire( el, 'close' );
				}
			};
		}

		return el;
	};

	page.addEventListener = function( name, fn ) { ( documentListeners[name] = documentListeners[name] || [] ).push( fn ) };

	const location = {
		_hash : opts.hash,
		get hash() { return this._hash },
		set hash( value ) {
			this._hash = value;
			hashListeners.forEach( function( fn ) { fn() } );
		},
		reload : function() { reloads.push( 1 ) },
	};

	const win = {
		location : location,
		history : { replaceState : function( a, b, hash ) { location._hash = hash } },
		addEventListener : function( name, fn ) {
			if( name === 'hashchange' )
				hashListeners.push( fn );
			else
				( windowListeners[name] = windowListeners[name] || [] ).push( fn );
		},
		setTimeout : function( fn, ms ) { timers.push( { fn : fn, ms : ms } ); return timers.length },
		clearTimeout : function( id ) { if( id > 0 && timers[id - 1] ) timers[id - 1].cleared = true },
		sessionStorage : { getItem : function( key ) { return storage[key] ?? null }, setItem : function( key, value ) { storage[key] = value }, removeItem : function( key ) { delete storage[key] } },
		open : function( url, target, features ) { opened.push( [ url, target, features ] ) },
		fetch : function( url, init ) {

			const request = JSON.parse( init.body );
			const answer = typeof opts.answers[request.action] === 'function' ? opts.answers[request.action]( request.data ) : [ 404, { error : 'no answer in the test for '+ request.action, code : 'action' } ];

			calls.push( { url : url, init : init, action : request.action, data : request.data } );

			// An answer may be a promise of one, which the test keeps back until it lets it go
			return Promise.resolve( answer ).then( function( given ) {

				if( given === 'offline' )
					throw new Error('offline');

				return { status : given[0], json : function() { return Promise.resolve( given[1] ) } };
			} );
		},
	};

	const context = vm.createContext( { window : win, document : page, console : console } );
	load( 'app/Crm/Documents/Money/assets/money.js', context );
	load( OWN+ 'assets/crm.js', context );

	const app = win.Nino.crm.app;

	return {
		app : app, win : win, page : page, calls : calls, main : main, toast : toast, dialogRoot : dialogRoot, count : count, clientCount : clientCount, nav : nav, timers : timers, dialogs : dialogs, opened : opened, reloads : reloads, storage : storage, answers : opts.answers,
		location : location, documentListeners : documentListeners, windowListeners : windowListeners,
		boot : function() { app.boot( block ) },
		last : function( action ) { return calls.filter( function( call ) { return call.action === action } ).pop() },
		all : function( action ) { return calls.filter( function( call ) { return call.action === action } ) },
		sent : function() { return calls.map( function( call ) { return call.action } ) },
	};
}

/**
 *	Let the promises of the script settle
 *
 *	@return		{Promise}
 */
async function settle() {
	for( let n = 0; n < 8; n++ )
		await new Promise( function( resolve ) { setImmediate( resolve ) } );
}

/**
 *	@param		{Object}	node
 *
 *	@return		{string}									The text of a node and of everything under it
 */
function textOf( node ) {
	return String( node.textContent ?? '' )+ ( node.children || [] ).map( textOf ).join('');
}

/**
 *	@param		{Object}	root
 *	@param		{string}	selector				A tag, #id, .class or [data-x="y"], alone or one after the other
 *
 *	@return		{Object[]}
 */
function all( root, selector ) {

	const parts = selector.trim().split( /\s+/ );
	const last = parts.pop();

	return root.querySelectorAll( last ).filter( function( node ) {

		let from = node.parentNode;

		return parts.slice().reverse().every( function( part ) {
			while( from !== null && from !== undefined && from !== root.parentNode ) {
				const hit = matches( from, part );
				from = from.parentNode;
				if( hit === true )
					return true;
			}
			return false;
		} );
	} );
}

/**
 *	A click: the listeners of the element and of the ones above it, as far as it is not stopped, then the document's
 *
 *	@param		{Object}	w
 *	@param		{Object}	el
 *
 *	@return		{void}
 */
function click( w, el ) {

	let stopped = false;
	let prevented = false;
	const event = { type : 'click', target : el, preventDefault : function() { prevented = true }, stopPropagation : function() { stopped = true } };

	for( let node = el; node !== null && stopped === false; node = node.parentNode )
		fire( node, 'click', event );

	( w.documentListeners.click || [] ).forEach( function( fn ) { fn( event ) } );

	return prevented;
}

/**
 *	A person types into a field: the value, then input; the change and the blur follow where the field is left
 *
 *	@param		{Object}	el
 *	@param		{string}	value
 *	@param		{boolean}	[leave]					false to type without leaving
 *
 *	@return		{void}
 */
function type( el, value, leave ) {
	el.value = value;
	fire( el, 'input', { target : el } );

	if( leave !== false ) {
		fire( el, 'change', { target : el } );
		fire( el, 'blur', { target : el } );
	}
}

/**
 *	@param		{Object}	w
 *	@param		{string}	key
 *	@param		{Object}	[target]
 *
 *	@return		{boolean}									Whether the key was kept from its default
 */
function press( w, key, target ) {
	let prevented = false;
	( w.documentListeners.keydown || [] ).forEach( function( fn ) { fn( { type : 'keydown', key : key, target : target || null, preventDefault : function() { prevented = true } } ) } );
	return prevented;
}

// --- the pins ------------------------------------------------------------------------------------------------------

( async function() {

	// === the script itself
	const script = source( OWN+ 'assets/crm.js' );

	check( 'the script builds no markup from data: no innerHTML, outerHTML, insertAdjacentHTML, document.write, eval or Function in it', /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function/.test( script ) === false );
	check( 'it sets no inline style: the look is in the stylesheet (no style attribute, no .style. assignment)', /setAttribute\( *'style'|\.style\.|cssText/.test( script ) === false );
	check( 'it carries no address and no name of the owner, and posts only to the endpoint of the page: one fetch', ( script.match( /wn\.fetch\(/g ) || [] ).length === 1 && script.indexOf( "'/crm/api'" ) !== -1 && /https?:\/\//.test( script ) === false );

	// every key the script asks for is a word of the page: the ones it names in a call of t(), as a fallback, as a toast to say, and the families it builds
	const asked = new Set();
	const collect = function( pattern, group ) {
		let found;
		while( ( found = pattern.exec( script ) ) )
			asked.add( found[group] );
	};
	collect( /\bt\( '([a-z0-9.-]+)'/g, 1 );
	collect( /'(error\.[a-z-]+)'/g, 1 );
	collect( /'([a-z]+\.done)'/g, 1 );
	collect( /'(tile\.[a-z]+-(?:one|many))'/g, 1 );

	const families = {
		'category.' : [ 'offer', 'invoice', 'credit', 'contract', 'privacy', 'information' ], 'state.' : [ 'draft', 'open', 'sent', 'paid', 'accepted', 'declined', 'cancelled', 'signed', 'overdue', 'expired' ],
		'chip.' : [ 'all', 'open', 'overdue', 'drafts', 'offers', 'invoices', 'plain', 'paid' ], 'tab.' : [ 'lines', 'document', 'portal', 'sign', 'notes', 'history' ], 'primary.' : [ 'send', 'sent', 'paid', 'invoice', 'sign' ],
		'new.' : [ 'invoice', 'offer', 'contract', 'privacy', 'information' ], 'sign.badge-' : [ 'requested', 'viewed', 'expired' ], 'sign.state-' : [ 'requested', 'viewed', 'signed', 'declined', 'cancelled', 'expired', 'changed' ],
		'needs.sign-' : [ 'viewed', 'expired' ], 'tile.paid-' : [ 'small_business', 'regular' ], 'vat.' : [ 'small_business', 'regular', 'reverse' ],
	};

	Object.keys( families ).forEach( function( stem ) {
		check( 'the family '+ stem+ '* is built by the script', script.indexOf( "'"+ stem+ "'" ) !== -1 );
		families[stem].forEach( function( one ) { asked.add( stem+ one ) } );
	} );

	const unknownLiterals = [ ...asked ].filter( function( key ) { return /[.-]$/.test( key ) === false && ( typeof texts[key] !== 'string' || texts[key] === '' ) } );

	if( unknownLiterals.length > 0 )
		console.log( '  missing words: '+ unknownLiterals.join( ', ' ) );

	check( 'every word the script asks for is in the dictionary of the page', unknownLiterals.length === 0 && asked.size > 250 );
	check( 'the words are German with Du: no Sie, Ihr, Ihnen', Object.keys( texts ).filter( function( key ) { return /\b(Sie|Ihr|Ihre|Ihren|Ihnen)\b/.test( texts[key] ) } ).length === 0 );

	// === the router
	const router = world( { hash : '', answers : {
		'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
		'crm-documents/list' : function() { return [ 200, { documents : [ row( {} ) ], years : [ 2026, 2025 ], clients : clients, settings : settings, items : items, offers : offers } ] },
		'crm-documents/get' : function( data ) { return Number( data.id ) === 16 ? [ 200, view( { id : 16, state : 'draft', number : '' } ) ] : [ 200, view( { id : Number( data.id ) } ) ] },
		'crm-documents/templates' : function() { return [ 200, { templates : templates, offers : offers } ] },
	} } );
	router.boot();
	await settle();

	check( 'the first screen is the Übersicht: it asks for the overview, and the rail marks it', router.last( 'crm-documents/overview' ) !== undefined && all( router.nav, '[data-r]' )[0].classList.contains( 'on' ) === true && all( router.nav, '[data-r]' )[1].classList.contains( 'on' ) === false );
	check( '...every post is JSON with the token in X-CSRF-Token, to /crm/api, with the cookie of the session', router.calls[0].url === '/crm/api' && router.calls[0].init.method === 'POST' && router.calls[0].init.headers['X-CSRF-Token'] === 'token-1' && router.calls[0].init.headers['Content-Type'] === 'application/json'
		&& router.calls[0].init.credentials === 'same-origin' && JSON.parse( router.calls[0].init.body ).action === 'crm-documents/overview' );
	check( '...the rail counts the open invoices', router.count.textContent === '3' );

	const routes = [
		[ '#belege', { screen : 'belege' }, 'crm-documents/list', 1 ],
		[ '#beleg-12', { screen : 'beleg', id : 12 }, 'crm-documents/get', 1 ],
		[ '#entwurf-16', { screen : 'entwurf', id : 16 }, 'crm-documents/get', 1 ],
		[ '#neu-invoice', { screen : 'entwurf', category : 'invoice', offer : '', client : '' }, 'crm-documents/templates', 1 ],
		[ '#neu-contract-13', { screen : 'entwurf', category : 'contract', offer : '13', client : '' }, 'crm-documents/templates', 1 ],
		[ '#neu-invoice-k3', { screen : 'entwurf', category : 'invoice', offer : '', client : '3' }, 'crm-documents/templates', 1 ],
		[ '#uebersicht', { screen : 'uebersicht' }, 'crm-documents/overview', 1 ],
		[ '#unsinn', { screen : 'uebersicht' }, 'crm-documents/overview', 1 ],
		[ '#beleg-0', { screen : 'uebersicht' }, 'crm-documents/overview', 1 ],
		[ '#beleg-12x', { screen : 'uebersicht' }, 'crm-documents/overview', 1 ],
		[ '#neu-evil', { screen : 'uebersicht' }, 'crm-documents/overview', 1 ],
	];

	for( const [ hash, parsed, action ] of routes ) {
		check( 'the hash '+ hash+ ' names the screen '+ parsed.screen+ ( parsed.id ? ' '+ parsed.id : '' ), JSON.stringify( router.app.parse( hash ) ) === JSON.stringify( parsed ) );
		const before = router.all( action ).length;
		router.location.hash = hash;
		await settle();
		check( '...and draws it: it asks for '+ action, router.all( action ).length === before + 1 );
	}

	router.location.hash = '#beleg-12';
	await settle();
	check( 'the screen Beleg marks Belege in the rail, the Entwurf too', all( router.nav, '[data-r]' )[1].classList.contains( 'on' ) === true && all( router.nav, '[data-r]' )[0].classList.contains( 'on' ) === false );
	router.location.hash = '#beleg-16';
	await settle();
	check( 'a draft opened as a document is drawn in the editor, and the address says so', router.location.hash === '#entwurf-16' && all( router.main, '#finalise' ).length === 1 && all( router.main, '#primary' ).length === 0 );

	// === the element helper and the shell
	const shell = world( { answers : {} } );
	const { el } = shell.app;
	const hostile = '<img src=x onerror=alert(1)> & "quotes" [[/key]]';
	const made = el( 'p', { class : 'x', title : hostile, text : hostile }, hostile, [ 'a', null, false, 3 ] );

	check( 'the element helper makes text of every string: a string with markup in it is the text of a node, not a child element', made.textContent === hostile && made.title === hostile && textOf( made ) === hostile+ hostile+ 'a3'
		&& findAll( made, function( node ) { return node.tagName === 'IMG' } ).length === 0 );
	check( '...null, undefined and false are no child; a listener, a dataset and an attribute are set', el( 'b', {}, null, undefined, false ).children.length === 0 && ( function() {
		let hit = 0;
		const node = el( 'button', { on : { click : function() { hit++ } }, dataset : { id : 5 }, role : 'menuitem', hidden : false } );
		fire( node, 'click' );
		return hit === 1 && node.dataset.id === '5' && node.getAttribute( 'role' ) === 'menuitem' && node.hidden === false;
	} )() );

	// === Übersicht from a fixture
	const needs = [
		{ kind : 'overdue', id : 11, client_id : 1, client_title : 'Nordlicht Records GmbH', number : 'R-2026-0007', title : '', date : '2026-10-16', gross : 119000, sign_state : '' },
		{ kind : 'sign', id : 18, client_id : 1, client_title : 'Nordlicht Records GmbH', number : '', title : 'Vertrag Album 2027', date : '2026-10-30', gross : 0, sign_state : 'viewed' },
		{ kind : 'sign', id : 19, client_id : 1, client_title : 'Nordlicht Records GmbH', number : '', title : 'AV-Vertrag', date : '2026-10-31', gross : 0, sign_state : 'expired' },
		{ kind : 'expiring', id : 13, client_id : 3, client_title : 'Podcast Süd', number : 'A-2026-0011', title : '', date : '2026-11-05', gross : 214200, sign_state : '' },
		{ kind : 'account', id : 0, client_id : 3, client_title : 'Podcast Süd', number : 'K-0003', title : '', date : '', gross : 0, sign_state : '' },
	];
	const recent = [ row( { id : 11, number : 'R-2026-0007', date : '2026-10-02' } ), row( { id : 16, category : 'invoice', number : '', state : 'draft', date : '2026-11-03', client_title : 'Podcast Süd' } ), row( { id : 18, category : 'contract', number : '', title : 'Vertrag Album 2027', state : 'open', date : '2026-10-30' } ) ];
	const overview = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : needs, recent : recent, settings : smallBusiness } ] } } } );
	overview.boot();
	await settle();
	const tiles = all( overview.main, '.tile' );

	check( 'Übersicht draws five tiles: open invoices, overdue, open offers, paid this year, drafts', tiles.length === 5 && [ 'Offene Rechnungen', 'Überfällig', 'Offene Angebote', 'Bezahlt 2026', 'Entwürfe' ].every( function( label, index ) { return textOf( tiles[index] ).indexOf( label ) === 0 } ) );
	check( '...with the sums by the currency of the settings, in German form, and the counts', textOf( tiles[0] ).indexOf( '1.500,00'+ NBSP+ '€' ) !== -1 && textOf( tiles[0] ).indexOf( '3 Belege' ) !== -1 && textOf( tiles[1] ).indexOf( '900,00'+ NBSP+ '€' ) !== -1 && textOf( tiles[1] ).indexOf( '2 Rechnungen, älteste seit 15.03.2026' ) !== -1
		&& textOf( tiles[2] ).indexOf( '2.142,00'+ NBSP+ '€' ) !== -1 && textOf( tiles[2] ).indexOf( '1 Angebot' ) !== -1 && textOf( tiles[3] ).indexOf( '20.300,00'+ NBSP+ '€' ) !== -1 && textOf( tiles[4] ).indexOf( 'zuletzt 03.11.2026' ) !== -1 );
	check( '...the overdue tile is red when there is something overdue, the paid tile says the VAT mode in a sentence', tiles[1].children[1].classList.contains( 'bad' ) === true && textOf( tiles[3] ).indexOf( 'nach § 19 UStG ohne USt' ) !== -1 );
	const lists = all( overview.main, '.list-mini' );
	const needLines = lists[0].children;

	check( '"Braucht Dich" lists each kind: an overdue invoice with its due day and amount, a signature that was looked at and one that ran out, an offer that runs out, a client with no account', needLines.length === 5 && textOf( needLines[0] ).indexOf( 'R-2026-0007 · Nordlicht Records GmbH · fällig seit 16.10.2026' ) === 0 && textOf( needLines[0] ).indexOf( '1.190,00'+ NBSP+ '€' ) !== -1
		&& textOf( needLines[1] ).indexOf( 'Vertrag Album 2027 · Nordlicht Records GmbH · vom Kunden angesehen, noch nicht unterschrieben' ) === 0 && textOf( needLines[1] ).indexOf( 'seit 30.10.2026' ) !== -1 && textOf( needLines[2] ).indexOf( 'die Frist zum Unterschreiben ist abgelaufen' ) !== -1
		&& textOf( needLines[3] ).indexOf( 'A-2026-0011 · Podcast Süd · offen, läuft ab am 05.11.2026' ) === 0 && textOf( needLines[4] ).indexOf( 'Podcast Süd hat noch kein Portal-Konto' ) === 0 );
	check( '...the lines link to their documents (#beleg-<id>), the client with no account to the card of that client - the name to the card, "einladen" to its tab Portal with the invitation open', all( needLines[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-11' && all( needLines[1], 'a' )[0].getAttribute( 'href' ) === '#beleg-18'
		&& all( needLines[4], 'a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join() === '#kunde-3,#kunde-3-einladen' && textOf( all( needLines[4], 'a' )[1] ) === 'einladen' );
	const recentLines = lists[1].children;
	check( '"Zuletzt" lists the latest documents: a draft opens in the editor, the others as a document, each with its kind, client and day', recentLines.length === 3 && all( recentLines[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-11' && all( recentLines[1], 'a' )[0].getAttribute( 'href' ) === '#entwurf-16'
		&& textOf( recentLines[1] ).indexOf( 'Entwurf' ) === 0 && textOf( recentLines[1] ).indexOf( 'Rechnung · Podcast Süd' ) !== -1 && textOf( recentLines[1] ).indexOf( '03.11.2026' ) !== -1 && textOf( recentLines[2] ).indexOf( 'Vertrag Album 2027' ) === 0 );
	check( '...everything came from one call', overview.sent().join() === 'crm-documents/overview' );
	check( 'the head has the search and the menu of the new documents', all( overview.main, '#q' ).length === 1 && all( all( overview.main, '.menu' )[0], 'button' ).length === 6 && all( all( overview.main, '.menu' )[0], 'button' ).slice( 1 ).map( textOf ).join( '|' ) === 'Rechnung|Angebot|Vertrag (aus Angebot)|Datenschutz|Information' );

	const empty = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : Object.assign( {}, summary, { count : 0, overdue_count : 0, overdue_gross : 0, overdue_oldest : '', drafts_count : 0, drafts_last : '' } ), needs : [], recent : [], settings : settings } ] } } } );
	empty.boot();
	await settle();
	check( 'with nothing to do the screen says so, and the overdue tile is not red', textOf( all( empty.main, '.list-mini' )[0] ).indexOf( 'Nichts, das auf Dich wartet.' ) === 0 && all( empty.main, '.tile' )[1].children[1].classList.contains( 'bad' ) === false && textOf( all( empty.main, '.tile' )[4] ).indexOf( 'keine' ) !== -1 && empty.count.textContent === '' );
	empty.location.hash = '#uebersicht';
	const many = Array.from( { length : 14 }, function( unused, index ) { return Object.assign( {}, needs[0], { id : 100 + index } ) } );
	const crowded = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : many, recent : [], settings : settings } ] } } } );
	crowded.boot();
	await settle();
	check( 'ten things that need the owner are shown, the others counted: "und 4 weitere"', all( crowded.main, '.list-mini' )[0].children.length === 11 && textOf( all( crowded.main, '.list-mini' )[0].children[10] ) === 'und 4 weitere' );

	const search = all( overview.main, '#q' )[0];
	search.value = 'R-2026';
	fire( search, 'keydown', { key : 'Enter', target : search } );
	check( 'Enter in the search of the Übersicht takes the words to the list of the documents', overview.location.hash === '#belege' && overview.app.state.filter.q === 'R-2026' );

	// === Belege
	const docs = [
		row( { id : 11, number : 'R-2026-0007', client_title : 'Nordlicht Records GmbH', date : '2026-10-02', due_date : '2026-10-16', overdue : true, state : 'sent', gross : 119000, released : true } ),
		row( { id : 12, number : 'R-2026-0008', client_title : 'Jonas Richter', date : '2026-10-20', due_date : '2026-11-03', state : 'sent', gross : 39270 } ),
		row( { id : 13, category : 'offer', number : 'A-2026-0011', client_title : 'Podcast Süd', date : '2026-10-28', due_date : null, valid_until : '2026-11-27', state : 'open', gross : 214200 } ),
		row( { id : 14, number : 'R-2026-0006', client_title : 'Nordlicht Records GmbH', date : '2026-09-05', due_date : '2026-09-19', state : 'paid', gross : 99000 } ),
		row( { id : 16, number : '', state : 'draft', date : '2026-11-03', due_date : '2026-11-17', client_title : 'Podcast Süd', gross : 107100 } ),
		row( { id : 17, category : 'credit', number : 'G-2026-0001', client_title : 'Alt AG', date : '2026-04-02', due_date : null, state : 'open', gross : -27000 } ),
		row( { id : 18, category : 'contract', number : '', title : 'Dienstleistungsvertrag Album 2027', state : 'open', released : true, sign_state : 'viewed', date : '2026-10-30', due_date : null, gross : 0, client_title : 'Nordlicht Records GmbH' } ),
		row( { id : 19, category : 'offer', number : 'A-2026-0010', state : 'accepted', date : '2026-10-05', due_date : null, valid_until : '2026-11-04', gross : 39270, client_title : 'Jonas Richter' } ),
	];
	const lists2 = [];
	const listing = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
		'crm-documents/list' : function( data ) { lists2.push( data ); return [ 200, { documents : docs, years : [ 2026, 2025 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	listing.boot();
	await settle();
	const state = listing.app.state;
	const tabs = function() { return all( listing.main, '[role="tab"]' ) };
	const cells = function( r ) { return r.children.map( textOf ) };
	const rows = function() { return all( listing.main, 'tbody' )[0].children };
	const numbers = function() { return rows().map( function( r ) { return textOf( r.children[0] ) } ) };

	check( 'Belege asks for the list of the year, and shows eight tabs with the counts of the documents each takes', lists2.length === 1 && lists2[0].year === String( new Date().getFullYear() ) && tabs().length === 8
		&& tabs().map( textOf ).join( '|' ) === 'Alle8|Offen3|Überfällig1|Entwürfe1|Angebote2|Rechnungen5|Verträge & Dokumente1|Bezahlt1' );
	check( '...the table has the columns, the number in the first, and the foot has the sum of what is shown: the credit note is taken off', all( listing.main, 'thead th' ).map( textOf ).join( '|' ) === 'Nummer|Art|Kunde|Datum ↓|Fällig / gültig|Betrag|Status|' && rows().length === 8
		&& textOf( all( listing.main, '#sum' )[0] ) === '5.908,40'+ NBSP+ '€' );
	check( '...a row has the kind, the client, the day, the due or valid day, the gross in the currency and the state', ( function() {
		const first = rows().find( function( r ) { return textOf( r.children[0] ) === 'R-2026-0007' } );
		return cells( first ).slice( 1, 6 ).join( '|' ) === 'Rechnung|Nordlicht Records GmbH|02.10.2026|16.10.2026|1.190,00'+ NBSP+ '€' && first.children[4].classList.contains( 'bad' ) === true;
	} )() );
	check( '...an overdue invoice says überfällig, a document in the portal says Portal, a signature that waits says angesehen, an offer says its state, a draft Entwurf and has no number - its kind is the name', ( function() {
		const by = function( id ) { return rows().find( function( r ) { return r.dataset.id === String( id ) } ) };
		return textOf( by( 11 ).children[6] ) === 'überfällig Portal' && textOf( by( 18 ).children[6] ) === 'offen Portal angesehen' && textOf( by( 13 ).children[6] ) === 'offen' && textOf( by( 19 ).children[6] ) === 'angenommen' && textOf( by( 16 ).children[6] ) === 'Entwurf' && textOf( by( 16 ).children[0] ) === 'Entwurf'
			&& textOf( by( 18 ).children[0] ) === 'Dienstleistungsvertrag Album 2027' && textOf( by( 18 ).children[5] ) === '–';
	} )() );
	check( '...the PDF button of a document leads to its file, the one of a draft to the preview', ( function() {
		const by = function( id ) { return rows().find( function( r ) { return r.dataset.id === String( id ) } ) };
		return all( by( 11 ), 'a' ).pop().getAttribute( 'href' ) === '/.crm/pdf/11' && all( by( 16 ), 'a' ).pop().getAttribute( 'href' ) === '/crm/preview/16' && all( by( 11 ), 'a' ).pop().getAttribute( 'target' ) === '_blank';
	} )() );

	const tabNamed = function( text ) { return tabs().find( function( t ) { return textOf( t ).indexOf( text ) === 0 } ) };
	click( listing, tabNamed( 'Überfällig' ) );
	check( 'a tab takes the documents it names: Überfällig is the overdue invoice, and the sum follows', numbers().join() === 'R-2026-0007' && textOf( all( listing.main, '#sum' )[0] ) === '1.190,00'+ NBSP+ '€' && tabNamed( 'Überfällig' ).getAttribute( 'aria-selected' ) === 'true' );
	click( listing, tabNamed( 'Offen' ) );
	check( '...Offen takes the open and sent documents that are not offers and not overdue: an invoice that is sent, the credit note, the contract', numbers().sort().join() === 'Dienstleistungsvertrag Album 2027,G-2026-0001,R-2026-0008' );
	click( listing, tabNamed( 'Angebote' ) );
	check( '...Angebote the offers, whatever their state', numbers().sort().join() === 'A-2026-0010,A-2026-0011' );
	click( listing, tabNamed( 'Rechnungen' ) );
	check( '...Rechnungen the invoices and the credit notes, Verträge & Dokumente the three kinds without lines, Entwürfe the drafts, Bezahlt the paid ones', numbers().length === 5 && ( click( listing, tabNamed( 'Verträge' ) ), numbers().join() === 'Dienstleistungsvertrag Album 2027' ) && ( click( listing, tabNamed( 'Entwürfe' ) ), numbers().join() === 'Entwurf' ) && ( click( listing, tabNamed( 'Bezahlt' ) ), numbers().join() === 'R-2026-0006' ) );
	click( listing, tabNamed( 'Alle' ) );

	// the search is local over what was loaded
	const found = all( listing.main, '#q' )[0];
	const callsBefore = listing.calls.length;
	type( found, 'podcast', false );
	check( 'the search narrows the loaded documents by number, title, client and kind, without asking the server again, and keeps the cursor where it was', numbers().sort().join() === 'A-2026-0011,Entwurf' && listing.calls.length === callsBefore && all( listing.main, '#q' )[0].value === 'podcast' && all( listing.main, '#q' )[0].focused === true );
	type( all( listing.main, '#q' )[0], 'vertrag', false );
	check( '...a title is found, and so is a kind: "Stornorechnung"', numbers().join() === 'Dienstleistungsvertrag Album 2027' && ( type( all( listing.main, '#q' )[0], 'stornorechnung', false ), numbers().join() === 'G-2026-0001' ) );
	type( all( listing.main, '#q' )[0], 'zzz', false );
	check( '...nothing found says so, in the table', textOf( rows()[0] ) === 'Kein Beleg passt zu Filter und Suche.' && textOf( all( listing.main, '#sum' )[0] ) === '0,00'+ NBSP+ '€' );
	type( all( listing.main, '#q' )[0], '', false );

	// the sort is by the cents, not by the text
	const heads = function() { return all( listing.main, 'thead th' ) };
	click( listing, all( heads()[5], 'button' )[0] );
	check( 'a click on the head of the amount sorts by cents, up: -270,00 before 392,70 before 990,00 before 1.071,00 and so on - not as text', numbers().join() === 'G-2026-0001,Dienstleistungsvertrag Album 2027,A-2026-0010,R-2026-0008,R-2026-0006,Entwurf,R-2026-0007,A-2026-0011' && heads()[5].getAttribute( 'aria-sort' ) === 'ascending' );
	click( listing, all( heads()[5], 'button' )[0] );
	check( '...a second click turns it round', numbers()[0] === 'A-2026-0011' && numbers()[7] === 'G-2026-0001' && heads()[5].getAttribute( 'aria-sort' ) === 'descending' );
	click( listing, all( heads()[2], 'button' )[0] );
	check( 'by client the names are in the order of the German alphabet, by number the numbers in text', textOf( rows()[0].children[2] ) === 'Alt AG' && ( click( listing, all( heads()[0], 'button' )[0] ), numbers()[0] === 'A-2026-0010' ) );
	click( listing, all( heads()[3], 'button' )[0] );
	check( 'by date a first click sorts the newest first, by the due day the days of an offer are among the due days', numbers()[0] === 'Entwurf' && ( click( listing, all( heads()[4], 'button' )[0] ), textOf( rows()[0].children[4] ) === '' ) );

	// a row opens the document
	click( listing, all( rows().find( function( r ) { return r.dataset.id === '11' } ), 'td' )[2] );
	check( 'a click on a row opens the document, a click on a draft the editor, a click on the PDF button only the PDF', listing.location.hash === '#beleg-11' && ( function() {
		listing.location.hash = '#belege';
		return true;
	} )() );
	await settle();
	click( listing, all( rows().find( function( r ) { return r.dataset.id === '16' } ), 'td' )[0] );
	check( '...(the draft)', listing.location.hash === '#entwurf-16' );
	listing.location.hash = '#belege';
	await settle();
	const pdfRow = rows().find( function( r ) { return r.dataset.id === '12' } );
	click( listing, all( pdfRow, 'a' ).pop() );
	check( '...(the PDF button does not open the document)', listing.location.hash === '#belege' );

	// the year
	const year = all( listing.main, '#year' )[0];
	check( 'the year is a select of the years of the documents and the one now', all( year, 'option' ).map( textOf ).join() === [ String( new Date().getFullYear() ), '2026', '2025' ].filter( function( one, index, list ) { return list.indexOf( one ) === index } ).sort().reverse().join() );
	type( year, '2025' );
	await settle();
	check( '...another year asks the server for it', lists2[lists2.length - 1].year === '2025' && state.filter.year === 2025 );

	// pages of fifty
	const lot = Array.from( { length : 120 }, function( unused, index ) { return row( { id : 1000 + index, number : 'R-2026-'+ String( index + 1 ).padStart( 4, '0' ), gross : 1000 * ( index + 1 ), date : '2026-01-01' } ) } );
	const paged = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : lot, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	paged.boot();
	await settle();
	const pageRows = function() { return all( paged.main, 'tbody' )[0].children };
	check( 'the table shows fifty rows a page, and says which page of how many', pageRows().length === 50 && textOf( all( paged.main, '.pager' )[0] ).indexOf( 'Seite 1 von 3' ) !== -1 && all( paged.main, '#prev' )[0].disabled === true && all( paged.main, '#next' )[0].disabled === false );
	check( '...the sum is the sum of all 120 documents, not of the page', textOf( all( paged.main, '#sum' )[0] ) === '72.600,00'+ NBSP+ '€' );
	click( paged, all( paged.main, '#next' )[0] );
	click( paged, all( paged.main, '#next' )[0] );
	check( '...the last page has what is left: twenty rows, and no way on', pageRows().length === 20 && textOf( all( paged.main, '.pager' )[0] ).indexOf( 'Seite 3 von 3' ) !== -1 && all( paged.main, '#next' )[0].disabled === true );
	type( all( paged.main, '#q' )[0], '0001', false );
	check( '...a search goes back to the first page', textOf( all( paged.main, '.pager' )[0] || { textContent : '' } ) === '' && pageRows().length >= 1 );

	// errors
	const failing = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 500, { error : 'x', code : 'crm_documents_database' } ] } } } );
	failing.boot();
	await settle();
	check( 'a list that fails says the sentence of its code and offers to try again', textOf( failing.main ).indexOf( 'Die Datenbank hat die Anfrage nicht angenommen.' ) !== -1 && all( failing.main, 'button' ).map( textOf ).indexOf( 'Noch einmal versuchen' ) !== -1 && failing.toast.classList.contains( 'bad' ) === true );
	const emptyList = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : [], years : [], clients : [], settings : settings, items : [], offers : [] } ] } } } );
	emptyList.boot();
	await settle();
	check( 'a year with no document says so and offers the first', textOf( all( emptyList.main, 'tbody' )[0] ).indexOf( 'Es gibt in diesem Jahr noch keine Belege.' ) === 0 );

	// === Beleg: the primary action and the menu by category and state
	/**
	 *	@param		{Object}	over						The document
	 *	@param		{Object}	[extra]
	 *	@param		{Object}	[answers]
	 *
	 *	@return		{Promise}
	 */
	async function opened( over, extra, answers ) {
		const w = world( { hash : '#beleg-'+ ( over.id ?? 11 ), answers : Object.assign( {
			'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
			'crm-documents/get' : function( data ) { return [ 200, Number( data.id ) >= 90 ? view( { id : Number( data.id ), state : 'draft', number : '' } ) : view( over, extra ) ] },
		}, answers || {} ) } );
		w.boot();
		await settle();
		return w;
	}

	const primaryOf = function( w ) { const b = all( w.main, '#primary' )[0]; return b === undefined ? '' : textOf( b ) };
	const menuOf = function( w ) { return all( all( w.main, '.menu' )[0], '[role="menuitem"]' ).map( textOf ) };
	const cases = [
		[ 'an open invoice', { state : 'open' }, 'Versenden' ],
		[ 'a sent invoice', { state : 'sent' }, 'Als bezahlt markieren' ],
		[ 'a paid invoice', { state : 'paid' }, '' ],
		[ 'a cancelled invoice', { state : 'cancelled' }, '' ],
		[ 'an open offer', { id : 13, category : 'offer', state : 'open', due_date : null, valid_until : '2026-11-27' }, 'Versenden' ],
		[ 'a sent offer', { id : 13, category : 'offer', state : 'sent', due_date : null, valid_until : '2026-11-27' }, 'Rechnung erstellen' ],
		[ 'an accepted offer', { id : 13, category : 'offer', state : 'accepted', due_date : null, valid_until : '2026-11-27' }, 'Rechnung erstellen' ],
		[ 'a declined offer', { id : 13, category : 'offer', state : 'declined', due_date : null, valid_until : '2026-11-27' }, '' ],
		[ 'an open credit note', { id : 17, category : 'credit', state : 'open', due_date : null }, 'Versenden' ],
		[ 'an open contract that is not released', { id : 18, category : 'contract', state : 'open', lines : [], net : 0, gross : 0, vat : 0, title : 'Vertrag', number : '', due_date : null }, 'Versenden' ],
		[ 'an open contract that is released', { id : 18, category : 'contract', state : 'open', released : true, lines : [], net : 0, gross : 0, vat : 0, title : 'Vertrag', number : '', due_date : null }, 'Unterschrift anfordern' ],
		[ 'a contract whose signature is asked for', { id : 18, category : 'contract', state : 'open', released : true, sign_state : 'requested', lines : [], net : 0, gross : 0, vat : 0, title : 'Vertrag', number : '', due_date : null }, '' ],
		[ 'a released information document', { id : 20, category : 'information', state : 'open', released : true, lines : [], net : 0, gross : 0, vat : 0, title : 'Zugang', number : '', due_date : null }, '' ],
		[ 'a signed contract', { id : 18, category : 'contract', state : 'signed', released : true, sign_state : 'signed', lines : [], net : 0, gross : 0, vat : 0, title : 'Vertrag', number : '', due_date : null }, '' ],
	];

	for( const [ label, over, expected ] of cases ) {
		const w = await opened( over );
		check( 'the one primary action of '+ label+ ': '+ ( expected === '' ? 'none' : expected ), primaryOf( w ) === expected && all( w.main, '.btn.primary' ).length === ( expected === '' ? 0 : 1 ) );
	}

	const menus = [
		[ 'an open invoice', { state : 'open' }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Stornieren|Als Entwurf kopieren|Notizen' ],
		[ 'a sent invoice that is overdue and in the portal', { state : 'sent', overdue : true, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Freigabe zurücknehmen|Notizen' ],
		[ 'a paid invoice', { state : 'paid' }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Notizen' ],
		[ 'a cancelled invoice', { state : 'cancelled' }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Notizen' ],
		[ 'an open offer', { id : 13, category : 'offer', state : 'open', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als angenommen markieren|Als abgelehnt markieren|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Notizen' ],
		[ 'an accepted offer', { id : 13, category : 'offer', state : 'accepted', due_date : null }, 'PDF ansehen|PDF herunterladen|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Notizen' ],
		[ 'a credit note', { id : 17, category : 'credit', state : 'sent', due_date : null }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Notizen' ],
		[ 'a contract that waits for a signature', { id : 18, category : 'contract', state : 'open', sign_state : 'viewed', lines : [], title : 'V', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Erinnern|Anfrage zurückziehen|Notizen' ],
		[ 'a contract with no request', { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Unterschrift anfordern|Freigabe zurücknehmen|Notizen' ],
		[ 'an information document', { id : 20, category : 'information', state : 'open', lines : [], title : 'Z', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Notizen' ],
	];

	for( const [ label, over, expected ] of menus ) {
		const w = await opened( over );
		check( 'the menu of '+ label+ ': '+ expected.replace( /\|/g, ', ' ), menuOf( w ).join( '|' ) === expected );
	}

	// the head and the blocks
	const invoice = await opened( { state : 'sent', overdue : true, released : true, id : 11 } );
	const headNode = all( invoice.main, '.head' )[0];
	check( 'the head of a document: its number, the state, the mark of the portal, the client, the day, the due day, the gross in large and the primary action', textOf( headNode.children[0] ) === 'R-2026-0007' && textOf( headNode ).indexOf( 'überfällig' ) !== -1 && textOf( headNode ).indexOf( 'Portal' ) !== -1
		&& textOf( headNode ).indexOf( 'Nordlicht Records GmbH · 02.10.2026 · fällig 16.10.2026' ) !== -1 && textOf( all( invoice.main, '#gross' )[0] ) === '1.190,00'+ NBSP+ '€' );
	check( '...the client is a link to the card of the client, in the surface', all( headNode, 'a' )[0].getAttribute( 'href' ) === '#kunde-1' );
	check( '...an overdue invoice says since when and offers the reminder', textOf( all( invoice.main, '.note' )[0] ).indexOf( 'Fällig seit 16.10.2026.' ) === 0 && all( invoice.main, '#remind' ).length === 1 );
	const blocks = all( invoice.main, '.card.pad' );
	check( 'the blocks Kunde and Beleg: the company and the name, the address on two lines, the mail, the VAT id, the number; the kind, the days, the period, the PDF', textOf( blocks[0] ).indexOf( 'Nordlicht Records GmbHLena Brandt' ) !== -1 && textOf( blocks[0] ).indexOf( 'Hafenstraße 1220457 Hamburg' ) !== -1 && textOf( blocks[0] ).indexOf( 'lena@nordlicht.example' ) !== -1 && textOf( blocks[0] ).indexOf( 'DE123456789' ) !== -1
		&& textOf( blocks[1] ).indexOf( 'RechnungVorlageRechnung StandardDatum02.10.2026Fällig16.10.2026Leistung14.09.2026 – 30.09.2026' ) !== -1 && all( blocks[1], 'a' )[0].getAttribute( 'href' ) === '/.crm/pdf/11' );
	check( 'the tabs of an invoice: Positionen, Portal & Versand, Notizen, Verlauf; the first is shown', all( invoice.main, '[role="tab"]' ).map( textOf ).join( '|' ) === 'Positionen|Portal & Versand|Notizen|Verlauf' && all( invoice.main, '[role="tab"]' )[0].getAttribute( 'aria-selected' ) === 'true' );
	const body = function( w ) { return all( w.main, '#tab' )[0] };
	check( '...Positionen: the lines with the number, the title and its description, the quantity as 4 and 2, the unit, the price and the rate and the amount; and the totals box with the VAT by rate', textOf( all( body( invoice ), 'tbody' )[0].children[0] ) === '1Recording-TagStudio A4Tag200,00'+ NBSP+ '€19 %800,00'+ NBSP+ '€'
		&& textOf( body( invoice ) ).indexOf( 'Netto1.000,00'+ NBSP+ '€' ) !== -1 && textOf( body( invoice ) ).indexOf( 'zzgl. 19 % USt. auf 1.000,00'+ NBSP+ '€190,00'+ NBSP+ '€' ) !== -1 && textOf( body( invoice ) ).indexOf( 'Brutto1.190,00'+ NBSP+ '€' ) !== -1 && textOf( body( invoice ) ).indexOf( 'Regelbesteuerung · Zahlungsziel 14 Tage' ) !== -1 );

	const small = await opened( { vat_mode : 'small_business', vat : 0, gross : 100000, lines : full().lines.map( function( l ) { return Object.assign( {}, l, { vat_rate : 0 } ) } ) } );
	check( 'a document without VAT shows no rate column and no VAT row, and says why', all( body( small ), 'thead th' ).map( textOf ).join( '|' ) === '#|Bezeichnung|Menge|Einheit|Einzelpreis|Betrag' && textOf( body( small ) ).indexOf( 'USt. auf' ) === -1 && textOf( body( small ) ).indexOf( 'Kleinunternehmer (§ 19 UStG)' ) !== -1 );

	const tabClick = async function( w, name ) { click( w, all( w.main, '[role="tab"]' ).find( function( t ) { return textOf( t ) === name } ) ); await settle(); };
	await tabClick( invoice, 'Verlauf' );
	check( 'Verlauf lists the dates of the document: created, finalised under its number, released... in the order they happened', all( body( invoice ), 'li' ).map( function( li ) { return textOf( li.children[1] ) } ).join( '|' ) === 'Als Entwurf angelegt|Festgeschrieben als R-2026-0007, PDF erzeugt' );
	await tabClick( invoice, 'Portal & Versand' );
	check( 'Portal & Versand of a document in the portal: the day, the way back and the mail again', textOf( body( invoice ) ).indexOf( 'Portal' ) !== -1 && all( body( invoice ), '#unrelease' ).length === 1 && all( body( invoice ), '#mail-again' ).length === 1 && all( body( invoice ), '#release' ).length === 0 );
	await tabClick( invoice, 'Notizen' );
	check( 'Notizen: the text of the notes, and a button that saves them', all( body( invoice ), '#notes' )[0].value === 'Rechnungen an die Buchhaltung' && all( body( invoice ), '#notes-save' ).length === 1 );

	// the steps
	const stepper = async function( over, answers ) {
		const calls = [];
		const w = await opened( over, undefined, Object.assign( {
			'crm-documents/release' : function( data ) { calls.push( [ 'release', data ] ); return [ 200, { document : full( over ), mailed : 2, mail : true } ] },
			'crm-documents/state' : function( data ) { calls.push( [ 'state', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/from-offer' : function( data ) { calls.push( [ 'from-offer', data ] ); return [ 200, { document : full( { id : 99, state : 'draft' } ) } ] },
			'crm-documents/credit' : function( data ) { calls.push( [ 'credit', data ] ); return [ 200, { document : full( { id : 98, state : 'draft' } ) } ] },
			'crm-documents/copy' : function( data ) { calls.push( [ 'copy', data ] ); return [ 200, { document : full( { id : 97, state : 'draft' } ) } ] },
			'crm-documents/remind' : function( data ) { calls.push( [ 'remind', data ] ); return [ 200, { document : full( over ), mailed : 2 } ] },
			'crm-documents/unrelease' : function( data ) { calls.push( [ 'unrelease', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/sign' : function( data ) { calls.push( [ 'sign', data ] ); return [ 200, { document : full( over ), mailed : true } ] },
			'crm-documents/sign-remind' : function( data ) { calls.push( [ 'sign-remind', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/sign-cancel' : function( data ) { calls.push( [ 'sign-cancel', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/save' : function( data ) { calls.push( [ 'save', data ] ); return [ 200, { document : full( Object.assign( {}, over, { notes : data.notes } ) ) } ] },
		}, answers || {} ) );
		w.steps = calls;
		return w;
	};
	const menuClick = function( w, label ) { click( w, all( all( w.main, '.menu' )[0], '[role="menuitem"]' ).find( function( item ) { return textOf( item ) === label } ) ); };

	let step = await stepper( { state : 'open' } );
	click( step, all( step.main, '#primary' )[0] );
	await settle();
	check( 'Versenden releases the document with the mail: crm-documents/release { id: "11", mail: true }, says how many accounts were mailed, and reads the document again', step.steps[0][0] === 'release' && JSON.stringify( step.steps[0][1] ) === '{"id":"11","mail":true}' && step.toast.textContent === 'Versendet: freigegeben und 2 Mail(s) an die Konten des Kunden.'
		&& step.all( 'crm-documents/get' ).length === 2 );
	step = await stepper( { state : 'sent' } );
	click( step, all( step.main, '#primary' )[0] );
	await settle();
	check( 'Als bezahlt markieren posts the step: crm-documents/state { id: "11", state: "paid" }', JSON.stringify( step.steps[0] ) === '["state",{"id":"11","state":"paid"}]' && step.toast.textContent === 'Als bezahlt markiert.' );
	step = await stepper( { id : 13, category : 'offer', number : 'A-2026-0011', state : 'sent', due_date : null } );
	click( step, all( step.main, '#primary' )[0] );
	await settle();
	check( 'Rechnung erstellen makes the draft and opens its editor', JSON.stringify( step.steps[0] ) === '["from-offer",{"id":"13"}]' && step.location.hash === '#entwurf-99' && step.toast.textContent === 'Rechnungsentwurf aus A-2026-0011 angelegt.' );
	step = await stepper( { state : 'paid' } );
	menuClick( step, 'Stornieren' );
	await settle();
	check( 'Stornieren makes the credit note draft: crm-documents/credit, the editor opens', step.steps[0][0] === 'credit' && step.location.hash === '#entwurf-98' );
	step = await stepper( { state : 'paid' } );
	menuClick( step, 'Als Entwurf kopieren' );
	await settle();
	check( 'Als Entwurf kopieren makes a draft of it: crm-documents/copy, the editor opens', JSON.stringify( step.steps[0] ) === '["copy",{"id":"11"}]' && step.location.hash === '#entwurf-97' );
	step = await stepper( { state : 'sent', overdue : true, released : true } );
	menuClick( step, 'Zahlungserinnerung senden' );
	await settle();
	check( 'Zahlungserinnerung senden posts crm-documents/remind and says how many accounts were mailed', JSON.stringify( step.steps[0] ) === '["remind",{"id":"11"}]' && step.toast.textContent === 'Zahlungserinnerung gesendet: 2 Mail(s).' );
	step = await stepper( { state : 'sent', released : true } );
	menuClick( step, 'Freigabe zurücknehmen' );
	await settle();
	check( 'Freigabe zurücknehmen posts crm-documents/unrelease', JSON.stringify( step.steps[0] ) === '["unrelease",{"id":"11"}]' && step.toast.textContent === 'Die Freigabe ist zurückgenommen.' );
	step = await stepper( { id : 13, category : 'offer', state : 'open', due_date : null } );
	menuClick( step, 'Als angenommen markieren' );
	await settle();
	menuClick( step, 'Als abgelehnt markieren' );
	await settle();
	check( 'an offer is marked accepted or declined: crm-documents/state with the state', JSON.stringify( step.steps.map( function( s ) { return s[1].state } ) ) === '["accepted","declined"]' );
	menuClick( step, 'Vertrag erstellen' );
	check( 'Vertrag erstellen opens the editor of a contract that hangs on this offer', step.location.hash === '#neu-contract-13' );
	step = await stepper( { state : 'open' } );
	menuClick( step, 'Als versendet markieren' );
	check( 'Als versendet markieren asks for the day first, in a dialog with a date field: nothing is posted yet', step.dialogs.length === 1 && step.dialogs[0].tagName === 'DIALOG' && step.steps.length === 0 && all( step.dialogs[0], 'input' )[0].type === 'date' && all( step.dialogs[0], 'input' )[0].value.length === 10 );
	all( step.dialogs[0], 'input' )[0].value = '2026-10-31';
	click( step, all( step.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...the yes posts the step with the day: crm-documents/state { state: "sent", date: "2026-10-31" }', JSON.stringify( step.steps[0] ) === '["state",{"id":"11","state":"sent","date":"2026-10-31"}]' && step.dialogRoot.children.length === 0 );

	// the dialogs: a question and two buttons, escape is the no
	step = await stepper( { id : 18, category : 'contract', state : 'open', sign_state : 'viewed', lines : [], title : 'V', number : '', due_date : null, released : true } );
	menuClick( step, 'Anfrage zurückziehen' );
	const asking = step.dialogs[0];
	check( 'taking a request back asks first: a dialog with the question and two buttons, the yes in red', asking !== undefined && asking.tagName === 'DIALOG' && asking.open === true && textOf( all( asking, 'h2' )[0] ) === 'Anfrage zurückziehen?' && all( asking, 'button' ).map( textOf ).join( '|' ) === 'Abbrechen|Zurückziehen'
		&& all( asking, 'button' )[1].classList.contains( 'danger' ) === true && step.steps.length === 0 );
	asking.close();
	await settle();
	check( '...closing it, as Escape does, is the no: nothing is posted, the dialog is gone', step.steps.length === 0 && step.dialogRoot.children.length === 0 );
	menuClick( step, 'Anfrage zurückziehen' );
	click( step, all( step.dialogs[1], 'button' )[0] );
	await settle();
	check( '...the first button is the no as well', step.steps.length === 0 && step.dialogs[1].open === false );
	menuClick( step, 'Anfrage zurückziehen' );
	click( step, all( step.dialogs[2], 'button' )[1] );
	await settle();
	check( '...the second is the yes: crm-documents/sign-cancel { id }', JSON.stringify( step.steps[0] ) === '["sign-cancel",{"id":"18"}]' && step.toast.textContent === 'Die Anfrage ist zurückgezogen.' );
	menuClick( step, 'Erinnern' );
	await settle();
	check( 'Erinnern posts crm-documents/sign-remind', JSON.stringify( step.steps[1] ) === '["sign-remind",{"id":"18"}]' && step.toast.textContent === 'Der Link ist noch einmal gesendet, mit neuer Frist.' );

	const accounts = [ { id : 'aaaaaaaaaaaa', email : 'anna@example.org', name : 'Anna', active : true }, { id : 'bbbbbbbbbbbb', email : 'olga@example.org', name : 'Olga', active : false } ];
	const signing = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : accounts, sign_days : 21 }, { 'crm-documents/sign' : function() { return [ 200, { document : full( {} ), mailed : true } ] } } );
	click( signing, all( signing.main, '#primary' )[0] );
	const ask = signing.dialogs[0];
	check( 'Unterschrift anfordern asks which account and for how many days: the accounts that are on, the days of Sign\'s setting', all( ask, 'option' ).length === 1 && textOf( all( ask, 'option' )[0] ) === 'Anna <anna@example.org>' && all( ask, 'input' )[0].value === '21' && all( ask, 'input' )[0].type === 'number' );
	all( ask, 'input' )[0].value = '7';
	click( signing, all( ask, 'button' )[1] );
	await settle();
	check( '...and posts crm-documents/sign { id, account, days }', JSON.stringify( signing.last( 'crm-documents/sign' ).data ) === '{"id":"18","account":"aaaaaaaaaaaa","days":"7"}' && signing.toast.textContent === 'Die Unterschrift ist angefordert, das Konto hat eine Mail bekommen.' );
	const nobody = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : [ accounts[1] ] } );
	click( nobody, all( nobody.main, '#primary' )[0] );
	check( '...where no account is on it says so and posts nothing', nobody.dialogs.length === 0 && nobody.toast.textContent.indexOf( 'Der Kunde hat kein aktives Konto.' ) === 0 && nobody.calls.filter( function( c ) { return c.action === 'crm-documents/sign' } ).length === 0 );

	// a refusal is the sentence of its code
	step = await stepper( { state : 'sent' }, { 'crm-documents/state' : function() { return [ 409, { error : 'x', code : 'crm_documents_state' } ] } } );
	click( step, all( step.main, '#primary' )[0] );
	await settle();
	check( 'a step the server refuses shows the sentence of its code as a toast that stays longer, and the screen is not redrawn', step.toast.textContent === 'Dieser Schritt ist für den Beleg in seinem Status nicht möglich.' && step.toast.classList.contains( 'bad' ) === true && step.timers[step.timers.length - 1].ms === 6000 && step.all( 'crm-documents/get' ).length === 1 );
	step = await stepper( { state : 'sent' }, { 'crm-documents/state' : function() { return [ 500, { error : 'boom' } ] } } );
	click( step, all( step.main, '#primary' )[0] );
	await settle();
	check( '...without a code it is the sentence for what was done: "Der Schritt konnte nicht gespeichert werden."', step.toast.textContent === 'Der Schritt konnte nicht gespeichert werden.' );

	// Portal & Versand of a document that is not released
	const portal = await opened( { state : 'open' } );
	await tabClick( portal, 'Portal & Versand' );
	check( 'Portal & Versand of a document that is not in the portal: the tick for the mail, the button Freigeben, and the way to enter a step taken elsewhere', textOf( body( portal ) ).indexOf( 'Der Kunde sieht den Beleg noch nicht im Portal.' ) !== -1 && all( body( portal ), '#release-mail' )[0].checked === true && all( body( portal ), '#release' ).length === 1
		&& all( body( portal ), '#sent-date' )[0].type === 'date' && all( body( portal ), '#mark-sent' ).length === 1 );
	all( body( portal ), '#release-mail' )[0].checked = false;
	portal.answers['crm-documents/release'] = function() { return [ 200, { document : full( { released : true } ), mailed : 0, mail : false } ] };
	click( portal, all( body( portal ), '#release' )[0] );
	await settle();
	check( '...Freigeben without the tick posts mail: false and says it was released without a mail', JSON.stringify( portal.last( 'crm-documents/release' ).data ) === '{"id":"11","mail":false}' && portal.toast.textContent === 'Freigegeben, ohne Mail.' );

	// Dokument and Unterschrift
	const contract = await opened( { id : 18, category : 'contract', state : 'open', released : true, sign_state : 'viewed', sign_id : 'aaaaaaaaaaaa', lines : [], net : 0, vat : 0, gross : 0, title : 'Dienstleistungsvertrag Album 2027', number : '', due_date : null, period_from : null, period_to : null,
		template_key : 'vertrag', inputs : { zusatz : 'Laufzeit ein Jahr' }, parent : { id : 13, number : 'A-2026-0011', title : '', category : 'offer', state : 'open' }, pdf_path : '/data/crm/pdf/2026/contract-18.pdf' } );
	check( 'a contract has the tabs Dokument, Portal & Versand, Unterschrift, Notizen, Verlauf, no gross in the head and no table of lines', all( contract.main, '[role="tab"]' ).map( textOf ).join( '|' ) === 'Dokument|Portal & Versand|Unterschrift|Notizen|Verlauf' && all( contract.main, '#gross' ).length === 0 && all( contract.main, 'table' ).length === 0 );
	check( '...Dokument: the template by its name, the offer as a link, the free field, the PDF and the state of the signature with the way to remind or take it back', textOf( body( contract ) ).indexOf( 'VorlageDienstleistungsvertrag' ) !== -1 && all( body( contract ), 'a' )[0].getAttribute( 'href' ) === '#beleg-13' && textOf( body( contract ) ).indexOf( 'ZusatzLaufzeit ein Jahr' ) !== -1
		&& textOf( body( contract ) ).indexOf( 'Angesehen, noch nicht unterschrieben' ) !== -1 && all( body( contract ), '#sign-remind' ).length === 1 && all( body( contract ), '#sign-cancel' ).length === 1 );
	await tabClick( contract, 'Unterschrift' );
	check( '...Unterschrift is the same card', all( body( contract ), '#sign-remind' ).length === 1 && all( body( contract ), '#sign-cancel' ).length === 1 && all( body( contract ), '#sign-ask' ).length === 0 );
	const info = await opened( { id : 20, category : 'information', state : 'open', lines : [], net : 0, vat : 0, gross : 0, title : 'Zugang', number : '', due_date : null } );
	check( 'an information document has no tab Unterschrift', all( info.main, '[role="tab"]' ).map( textOf ).join( '|' ) === 'Dokument|Portal & Versand|Notizen|Verlauf' );
	const signed = await opened( { id : 18, category : 'contract', state : 'signed', released : true, sign_state : 'signed', sign_id : 'aaaaaaaaaaaa', signed_at : '2026-10-31T10:00:00Z', lines : [], title : 'V', number : '', due_date : null, sign_signed_url : '/.sign/pdf?r=aaaaaaaaaaaa&kind=signed', sign_shown_url : '/.sign/pdf?r=aaaaaaaaaaaa&kind=shown' } );
	check( 'a signed contract says when, and links the signed and the shown PDF', textOf( body( signed ) ).indexOf( 'Unterschrieben am 31.10.2026' ) !== -1 && all( body( signed ), 'a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join( '|' ).indexOf( '/.sign/pdf?r=aaaaaaaaaaaa&kind=signed|/.sign/pdf?r=aaaaaaaaaaaa&kind=shown' ) !== -1 );

	// notes
	const notes = await stepper( { state : 'open' } );
	await tabClick( notes, 'Notizen' );
	type( all( body( notes ), '#notes' )[0], 'Neu notiert' );
	click( notes, all( body( notes ), '#notes-save' )[0] );
	await settle();
	check( 'the notes are saved with crm-documents/save { id, notes } and nothing else, and the toast says so', JSON.stringify( notes.steps[0] ) === '["save",{"id":"11","notes":"Neu notiert"}]' && notes.toast.textContent === 'Notizen gespeichert.' );
	const fromMenu = await stepper( { state : 'open' } );
	menuClick( fromMenu, 'Notizen' );
	check( 'the item Notizen of the menu shows the tab', all( fromMenu.main, '[role="tab"]' ).find( function( t ) { return textOf( t ) === 'Notizen' } ).getAttribute( 'aria-selected' ) === 'true' && all( fromMenu.main, '#notes' ).length === 1 );

	// the text a document holds is text
	const markup = await opened( { title : '<script>alert(1)</script>', category : 'contract', lines : [], net : 0, vat : 0, gross : 0, number : '', due_date : null, client : Object.assign( {}, client, { company : '<b>Fett</b> & Co', name : '"><img src=x onerror=alert(1)>' } ) } );
	check( 'a client and a title with markup in them are text on the page: no script, no img, no b element anywhere in it', findAll( markup.main, function( n ) { return [ 'SCRIPT', 'IMG', 'B' ].indexOf( n.tagName ) !== -1 && ( n.tagName !== 'B' || textOf( n ) !== '<b>Fett</b> & Co' ) } ).length === 0 && textOf( markup.main ).indexOf( '<b>Fett</b> & Co' ) !== -1 );

	// === Entwurf
	const draftDoc = { id : 16, state : 'draft', number : '', client_id : 1, date : '2026-11-03', due_date : '2026-11-17', period_from : '', period_to : '', template_key : 'rechnung', inputs : { intro : 'Vielen Dank.' }, reverse_charge : false, pdf_path : '', pdf_sha256 : '', finalised : null, updated : '2026-11-03T13:02:00Z',
		lines : [ { id : 1, document_id : 16, sort : 1, title : 'Editing', description : 'Staffel 3', qty : 10000, unit : 'Std.', price_net : 6000, vat_rate : 1900, amount_net : 60000 }, { id : 2, document_id : 16, sort : 2, title : 'Mastering', description : '', qty : 3000, unit : 'Song', price_net : 11000, vat_rate : 700, amount_net : 33000 } ],
		net : 93000, vat : 14130, gross : 107130 };
	/**
	 *	@param		{Object}	[over]					The draft
	 *	@param		{Object}	[answers]
	 *	@param		{string}	[hash]
	 *
	 *	@return		{Promise}
	 */
	async function editing( over, answers, hash ) {
		const saves = [];
		const w = world( { hash : hash ?? '#entwurf-16', answers : Object.assign( {
			'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
			'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, over || {} ) ) ] },
			'crm-documents/list' : function( data ) { return [ 200, { documents : data.client ? [ row( { id : 11, state : 'sent', gross : 119000 } ), row( { id : 12, state : 'open', gross : 39270 } ), row( { id : 14, state : 'paid', gross : 99000 } ), row( { id : 13, category : 'offer', state : 'open', gross : 5000 } ) ] : [], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
			'crm-documents/templates' : function( data ) { return [ 200, { templates : templates, offers : offers, category : data.category } ] },
			'crm-clients/get' : function( data ) { return [ 200, { client : Object.assign( {}, client, { id : Number( data.id ) }, data.id === '2' ? { company : '', name : 'Jonas Richter', street : 'Lindenweg 4', zip : '79098', city : 'Freiburg', country : 'DE' } : {} ), accounts : data.id === '1' ? [ { id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'lena@nordlicht.example', name : 'Lena Brandt', active : true, login : '2026-10-29' }, { id : 'bbbbbbbbbbbb', gone : true, state : 'gone' } ] : [] } ] },
			'crm-documents/save' : function( data ) { saves.push( data ); return [ 200, { document : full( Object.assign( {}, draftDoc, over || {}, { id : data.id ? Number( data.id ) : 77, updated : '2026-11-03T14:30:00Z' } ) ) } ] },
		}, answers || {} ) } );
		w.saves = saves;
		w.boot();
		await settle();
		return w;
	}
	const field = function( w, id ) { return findAll( w.main, function( node ) { return node.id === id } )[0] };
	const lineRows = function( w ) { return all( w.main, '#lines tbody' )[0].children.slice( 0, -1 ) };

	let edit = await editing();
	const parts = all( edit.main, '.head' )[0];
	check( 'the editor: the title, the badge Entwurf, when it was saved (the local time of the stamp), the gross live, Speichern, Festschreiben and the menu', textOf( parts.children[0] ) === 'Entwurf · Rechnung' && textOf( parts ).indexOf( 'Entwurf' ) !== -1 && textOf( field( edit, 'saved' ) ).indexOf( 'Gespeichert um ' ) === 0 && /\d\d:\d\d$/.test( textOf( field( edit, 'saved' ) ) )
		&& textOf( field( edit, 'gross' ) ) === '1.067,10'+ NBSP+ '€' && textOf( field( edit, 'save' ) ) === 'Speichern' && textOf( field( edit, 'finalise' ) ) === 'Festschreiben' && edit.app.state.draft.id === 16 );
	check( '...the menu has the preview and the delete', all( all( edit.main, '.menu' )[0], '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'PDF-Vorschau|Entwurf löschen' );
	check( 'the block Beleg: the kind (fixed), the client of the active clients, the day, the due day, the period, the template; the template\'s own fields below', textOf( all( edit.main, 'dl' )[0] ).indexOf( 'ArtRechnung' ) === 0 && all( field( edit, 'f-client_id' ), 'option' ).map( textOf ).join( '|' ) === 'Kunde wählen|K-0001 · Nordlicht Records GmbH|K-0002 · Jonas Richter' && field( edit, 'f-client_id' ).value === '1'
		&& field( edit, 'f-date' ).value === '2026-11-03' && field( edit, 'f-due_date' ).value === '2026-11-17' && field( edit, 'f-period_from' ).type === 'date' && field( edit, 'f-template_key' ).value === 'rechnung' && field( edit, 'f-inputs.intro' ).value === 'Vielen Dank.' );
	check( '...the regular mode has the rate per line and the reverse charge', field( edit, 'f-reverse_charge' ) !== undefined && all( lineRows( edit )[0], 'select' ).length === 1 && all( lineRows( edit )[0], 'select' )[0].value === '1900' && all( all( lineRows( edit )[0], 'select' )[0], 'option' ).map( textOf ).join() === '19 %,7 %,0 %' );
	check( 'the block Kunde: the name, the number, the way to the accounts, and the open sum of the client over all years - sent and open invoices, not the paid and not the offers', textOf( field( edit, 'client-box' ) ).indexOf( 'Nordlicht Records GmbH' ) !== -1 && textOf( field( edit, 'client-open' ) ) === '1.582,70'+ NBSP+ '€' && edit.last( 'crm-documents/list' ).data.client === '1' );

	check( '...with the address of the client on two lines and its accounts by name - asked for from the client\'s own action, since no list carries them - and the way to the card', textOf( field( edit, 'client-address' ) ) === 'Hafenstraße 1220457 Hamburg' && textOf( field( edit, 'client-accounts' ) ).indexOf( 'Lena Brandt Zur Kundenkarte' ) === 0
		&& edit.last( 'crm-clients/get' ).data.id === '1' && JSON.stringify( edit.all( 'crm-documents/list' ).map( function( c ) { return Object.keys( c.data ).join() } ) ).indexOf( 'client' ) !== -1 );
	check( 'the lines are inputs: the title and the description, the quantity as it is read (10, 3), the unit, the price as 60,00 and 110,00, the amount computed, a remove button', lineRows( edit ).length === 2 && field( edit, 'f-' ) === undefined && all( lineRows( edit )[0], 'input' ).map( function( i ) { return i.value } ).join( '|' ) === 'Editing|10|Std.|60,00' && all( lineRows( edit )[0], 'textarea' )[0].value === 'Staffel 3'
		&& textOf( all( lineRows( edit )[0], '.amount' )[0] ) === '600,00'+ NBSP+ '€' && textOf( all( lineRows( edit )[1], '.amount' )[0] ) === '330,00'+ NBSP+ '€' && all( lineRows( edit )[0], '.del' ).length === 1 );
	const totalsText = function() { return textOf( field( edit, 'totals' ) ) };
	check( 'the totals box: the net, the VAT by rate - 19 % on 600,00 and 7 % on 330,00 -, the gross; the same sums the server stores', totalsText().indexOf( 'Netto930,00'+ NBSP+ '€' ) === 0 && totalsText().indexOf( 'zzgl. 19 % USt. auf 600,00'+ NBSP+ '€114,00'+ NBSP+ '€' ) !== -1 && totalsText().indexOf( 'zzgl. 7 % USt. auf 330,00'+ NBSP+ '€23,10'+ NBSP+ '€' ) !== -1 && totalsText().indexOf( 'Brutto1.067,10'+ NBSP+ '€' ) !== -1 );

	// the arithmetic follows what is typed
	const qtyField = all( lineRows( edit )[0], 'input' )[1];
	const priceField = all( lineRows( edit )[0], 'input' )[3];
	type( qtyField, '1,5', false );
	type( priceField, '90,00', false );
	check( 'typing a quantity and a price draws the amount, the totals and the gross of the head at once - 1,5 x 90,00 = 135,00, with money.js\'s rounding', textOf( all( lineRows( edit )[0], '.amount' )[0] ) === '135,00'+ NBSP+ '€' && totalsText().indexOf( 'Netto465,00'+ NBSP+ '€' ) === 0 && totalsText().indexOf( 'zzgl. 19 % USt. auf 135,00'+ NBSP+ '€25,65'+ NBSP+ '€' ) !== -1
		&& textOf( field( edit, 'gross' ) ) === '513,75'+ NBSP+ '€' && edit.app.state.draft.dirty === true );
	type( qtyField, '0,333', false );
	type( priceField, '10,00', false );
	check( '...a quantity of thousandths: 0,333 x 10,00 = 3,33 (half away from zero on the cent)', textOf( all( lineRows( edit )[0], '.amount' )[0] ) === '3,33'+ NBSP+ '€' );
	type( qtyField, '1x', false );
	check( '...a quantity that is no number counts 0 and the field is marked', textOf( all( lineRows( edit )[0], '.amount' )[0] ) === '0,00'+ NBSP+ '€' && qtyField.classList.contains( 'err' ) === true );
	type( qtyField, '2', false );
	check( '...and typing it right takes the mark off', qtyField.classList.contains( 'err' ) === false );
	const rateSelect = all( lineRows( edit )[0], 'select' )[0];
	type( rateSelect, '0' );
	await settle();
	check( 'a rate chosen redraws the VAT: 0 % on the first line leaves only the 7 % row', totalsText().indexOf( 'zzgl. 19 %' ) === -1 && totalsText().indexOf( 'zzgl. 0 % USt. auf 20,00'+ NBSP+ '€0,00'+ NBSP+ '€' ) !== -1 );
	const reverse = field( edit, 'f-reverse_charge' );
	reverse.checked = true;
	fire( reverse, 'change', { target : reverse } );
	await settle();
	check( 'the reverse charge takes the rates off: no VAT row, the gross is the net, the rate selects are off and show 0 %', totalsText().indexOf( 'zzgl.' ) === -1 && all( lineRows( edit )[0], 'select' )[0].disabled === true && all( lineRows( edit )[0], 'select' )[0].value === '0' && totalsText().indexOf( 'Reverse-Charge' ) !== -1 );
	reverse.checked = false;
	fire( reverse, 'change', { target : reverse } );

	// the catalogue
	edit = await editing();
	let adder = field( edit, 'additem' );
	check( 'the row "Position suchen" has a datalist of the catalogue: the title as the value, the unit and the price as the text', all( edit.main, 'datalist' )[0].children.map( function( o ) { return o.value+ ':'+ o.textContent } ).join( '|' ) === 'Mixing:Std. · 90,00'+ NBSP+ '€|Mastering:Song · 110,00'+ NBSP+ '€' && adder.getAttribute( 'list' ) === 'items' );
	adder.value = 'mixing';
	fire( adder, 'keydown', { key : 'Enter', target : adder, preventDefault : function() {} } );
	check( 'Enter on the title of an item (the case does not matter) inserts it as a line: the title, the first line and all of the description, the unit, the price, the rate of the item, a quantity of 1', lineRows( edit ).length === 3 && all( lineRows( edit )[2], 'input' ).map( function( i ) { return i.value } ).join( '|' ) === 'Mixing|1|Std.|90,00' && all( lineRows( edit )[2], 'textarea' )[0].value === 'Mischung eines Songs\nbis 48 Spuren'
		&& all( lineRows( edit )[2], 'select' )[0].value === '1900' && field( edit, 'additem' ).value === '' && field( edit, 'additem' ).focused === true );
	check( '...and the sums have it', totalsText().indexOf( 'Netto1.020,00'+ NBSP+ '€' ) === 0 );
	adder = field( edit, 'additem' );
	adder.value = 'Mastering';
	fire( adder, 'change', { target : adder } );
	check( 'a choice from the list (a change with the whole title) inserts too, with the rate of that item: 7 %', lineRows( edit ).length === 4 && all( lineRows( edit )[3], 'select' )[0].value === '700' );
	adder = field( edit, 'additem' );
	adder.value = 'Mast';
	fire( adder, 'change', { target : adder } );
	check( 'a half title inserts nothing: leaving the field is no Enter', lineRows( edit ).length === 4 );
	click( edit, all( lineRows( edit )[2], '.del' )[0] );
	check( 'the cross removes the line, and the sums follow', lineRows( edit ).length === 3 && totalsText().indexOf( 'Netto1.020,00'+ NBSP+ '€' ) === 0 === false && edit.app.state.draft.lines.length === 3 );
	click( edit, field( edit, 'addline' ) );
	check( 'an empty line is added at the end with a quantity of 1 and the focus in its title', lineRows( edit ).length === 4 && all( lineRows( edit )[3], 'input' )[0].value === '' && all( lineRows( edit )[3], 'input' )[1].value === '1' && all( lineRows( edit )[3], 'input' )[0].focused === true );

	// what is posted
	edit = await editing();
	type( field( edit, 'f-client_id' ), '2' );
	await settle();
	check( 'the client is chosen: the open sum of the other client is asked for, and the draft is saved with it', edit.all( 'crm-documents/list' ).filter( function( c ) { return c.data.client === '2' } ).length === 1 && edit.saves.length === 1 && edit.saves[0].client_id === '2' );
	check( '...the block shows that client: the address, and no account yet with the way to invite one', textOf( field( edit, 'client-address' ) ) === 'Lindenweg 479098 Freiburg' && textOf( field( edit, 'client-accounts' ) ) === 'noch keins, einladen' && all( field( edit, 'client-accounts' ), 'a' )[0].getAttribute( 'href' ) === '#kunde-2-einladen' );
	edit.saves.length = 0;
	type( field( edit, 'f-inputs.intro' ), 'Zeile 1\nZeile 2', false );
	type( field( edit, 'f-period_from' ), '2026-10-01', false );
	type( field( edit, 'f-period_to' ), '2026-10-31', false );
	click( edit, field( edit, 'save' ) );
	await settle();
	check( 'Speichern posts the draft as the panel does: the id as a string, the client as a string, the dates, the template and the inputs of that template, the reverse charge, the lines with the quantity and the price as typed', JSON.stringify( edit.saves[0] ) === JSON.stringify( {
		date : '2026-11-03', template_key : 'rechnung', inputs : { intro : 'Zeile 1\nZeile 2' }, id : '16', client_id : '2', period_from : '2026-10-01', period_to : '2026-10-31', reverse_charge : false, due_date : '2026-11-17',
		lines : [ { title : 'Editing', description : 'Staffel 3', unit : 'Std.', qty : '10', price : '60,00', vat_rate : 1900 }, { title : 'Mastering', description : '', unit : 'Song', qty : '3', price : '110,00', vat_rate : 700 } ] } ) );
	check( '...the time of the save is shown, the draft is clean again, and the rail\'s count is read again', textOf( field( edit, 'saved' ) ).indexOf( 'Gespeichert um ' ) === 0 && edit.app.state.draft.dirty === false && edit.all( 'crm-documents/overview' ).length >= 2 && field( edit, 'message' ).children.length === 0 );

	// autosave
	edit = await editing();
	type( field( edit, 'f-date' ), '2026-11-04' );
	await settle();
	check( 'a field that is left saves the draft when it is whole: one save, no word of it', edit.saves.length === 1 && edit.saves[0].date === '2026-11-04' && edit.toast.classList.contains( 'show' ) === false );
	edit.saves.length = 0;
	click( edit, field( edit, 'addline' ) );
	await settle();
	check( '...adding an empty line saves nothing: the line has no title', edit.saves.length === 0 && edit.app.model.ready( edit.app.state.draft ) === false );
	type( all( lineRows( edit )[2], 'input' )[0], 'Neu', false );
	type( all( lineRows( edit )[2], 'input' )[3], '10', true );
	await settle();
	check( '...it saves once the line is a line: a title, a quantity and a price that are numbers', edit.saves.length === 1 && edit.saves[0].lines.length === 3 && edit.saves[0].lines[2].title === 'Neu' );
	edit.saves.length = 0;
	type( all( lineRows( edit )[0], 'input' )[1], '', true );
	await settle();
	check( '...a quantity left empty is no save either', edit.saves.length === 0 );
	type( all( lineRows( edit )[0], 'input' )[1], '5', true );
	await settle();

	// a refusal beside its field
	edit = await editing( {}, { 'crm-documents/save' : function() { return [ 400, { error : 'x', code : 'crm_documents_line_empty', params : [ 2 ], field : 'lines.1.title' } ] } } );
	click( edit, field( edit, 'save' ) );
	await settle();
	check( 'a refusal with a field marks the field and puts the focus in it, shows the sentence of its code with the number of the line in the editor and as a toast', edit.toast.textContent === 'Position 2: Die Bezeichnung fehlt.' && textOf( field( edit, 'message' ) ) === 'Position 2: Die Bezeichnung fehlt.'
		&& all( lineRows( edit )[1], 'input' )[0].classList.contains( 'err' ) === true && all( lineRows( edit )[1], 'input' )[0].focused === true && all( lineRows( edit )[1], 'input' )[0].getAttribute( 'aria-invalid' ) === 'true' );
	type( all( lineRows( edit )[1], 'input' )[0], 'Mastering 2', false );
	check( '...typing in it takes the mark off', all( lineRows( edit )[1], 'input' )[0].classList.contains( 'err' ) === false );
	edit = await editing( {}, { 'crm-documents/save' : function() { return [ 400, { error : 'x', code : 'crm_documents_date', field : 'due_date' } ] } } );
	click( edit, field( edit, 'save' ) );
	await settle();
	check( '...a field of the block Beleg is marked the same way', field( edit, 'f-due_date' ).classList.contains( 'err' ) === true && edit.toast.textContent === 'Das Datum ist nicht gültig.' );

	// finalising
	let finalised = false;
	edit = await editing( {}, { 'crm-documents/finalise' : function() { finalised = true; return [ 200, { document : full( { id : 16, number : 'R-2026-0009' } ) } ] },
		'crm-documents/get' : function() { return [ 200, finalised ? view( { id : 16, number : 'R-2026-0009' } ) : view( draftDoc ) ] } } );
	click( edit, field( edit, 'finalise' ) );
	await settle();
	check( 'Festschreiben asks first, in a dialog: the number is given and the PDF made, then nothing changes; nothing is posted before the yes - not even a save, the draft is clean', edit.dialogs.length === 1 && textOf( all( edit.dialogs[0], 'p' )[0] ) === 'Die Nummer wird vergeben und die PDF erzeugt. Danach ist der Beleg unveränderlich.'
		&& all( edit.dialogs[0], 'button' ).map( textOf ).join( '|' ) === 'Abbrechen|Jetzt festschreiben' && edit.all( 'crm-documents/finalise' ).length === 0 && edit.saves.length === 0 );
	click( edit, all( edit.dialogs[0], 'button' )[0] );
	await settle();
	check( '...the no leaves the draft as it is', edit.all( 'crm-documents/finalise' ).length === 0 && edit.location.hash === '#entwurf-16' );
	click( edit, field( edit, 'finalise' ) );
	await settle();
	click( edit, all( edit.dialogs[1], 'button' )[1] );
	await settle();
	check( '...the yes posts crm-documents/finalise { id: "16" }, says the number in a toast and shows the document', JSON.stringify( edit.last( 'crm-documents/finalise' ).data ) === '{"id":"16"}' && edit.toast.textContent === 'Festgeschrieben als R-2026-0009, PDF erzeugt.' && edit.location.hash === '#beleg-16' );

	edit = await editing( {}, { 'crm-documents/finalise' : function() { return [ 409, { error : 'x', code : 'crm_documents_template', field : 'template_key' } ] } } );
	type( field( edit, 'f-date' ), '2026-11-05', false );
	click( edit, field( edit, 'finalise' ) );
	await settle();
	check( 'a draft with something unsaved is saved before the question', edit.saves.length === 1 && edit.saves[0].date === '2026-11-05' && edit.dialogs.length === 1 );
	click( edit, all( edit.dialogs[0], 'button' )[1] );
	await settle();
	check( '...a refusal of the finalising stays in the editor, with the sentence and the field marked: the template', edit.location.hash === '#entwurf-16' && textOf( field( edit, 'message' ) ) === 'Wähle eine Vorlage aus PDF für diese Art von Beleg.' && field( edit, 'f-template_key' ).classList.contains( 'err' ) === true );
	edit = await editing( {}, { 'crm-documents/save' : function() { return [ 400, { error : 'x', code : 'crm_documents_client', field : 'client_id' } ] } } );
	type( field( edit, 'f-date' ), '2026-11-05', false );
	click( edit, field( edit, 'finalise' ) );
	await settle();
	check( 'where the save is refused there is no question: nothing could be finalised', edit.dialogs.length === 0 && edit.all( 'crm-documents/finalise' ).length === 0 );

	// delete and preview
	edit = await editing( {}, { 'crm-documents/remove' : function() { return [ 200, { removed : true } ] } } );
	menuClick( edit, 'Entwurf löschen' );
	check( 'Entwurf löschen asks: "Der Entwurf wird gelöscht." with a red yes', edit.dialogs.length === 1 && textOf( all( edit.dialogs[0], 'p' )[0] ) === 'Der Entwurf wird gelöscht.' && all( edit.dialogs[0], 'button' )[1].classList.contains( 'danger' ) === true );
	click( edit, all( edit.dialogs[0], 'button' )[1] );
	await settle();
	check( '...the yes posts crm-documents/remove and goes back to the list', JSON.stringify( edit.last( 'crm-documents/remove' ).data ) === '{"id":"16"}' && edit.location.hash === '#belege' && edit.toast.textContent === 'Der Entwurf ist gelöscht.' );
	edit = await editing();
	menuClick( edit, 'PDF-Vorschau' );
	await settle();
	check( 'the preview opens the PDF of the saved draft in a tab of its own, without opener', edit.opened.length === 1 && edit.opened[0].join( '|' ) === '/crm/preview/16|_blank|noopener' );
	type( field( edit, 'f-date' ), '2026-11-09', false );
	menuClick( edit, 'PDF-Vorschau' );
	await settle();
	check( '...saving the draft first where there is something unsaved', edit.saves.length === 1 && edit.opened.length === 2 );

	// leaving
	edit = await editing();
	type( field( edit, 'f-date' ), '2026-11-10', false );
	const crumb = all( edit.main, '.crumb a' )[0];
	const prevented = click( edit, crumb );
	check( 'a link away from a draft that holds something unsaved asks first and does not follow', prevented === true && edit.dialogs.length === 1 && textOf( all( edit.dialogs[0], 'h2' )[0] ) === 'Änderungen verwerfen?' && edit.location.hash === '#entwurf-16' );
	click( edit, all( edit.dialogs[0], 'button' )[1] );
	await settle();
	check( '...the yes goes there', edit.location.hash === '#belege' );
	edit = await editing();
	check( 'a clean draft is left without a question', click( edit, all( edit.main, '.crumb a' )[0] ) === false && edit.dialogs.length === 0 );

	// a new draft
	const fresh = await editing( {}, {}, '#neu-invoice' );
	check( 'a new invoice: the title says new, nothing is saved, the date is today, the due day follows the setting, the client is to be chosen and there is no line yet', textOf( all( fresh.main, '.head' )[0].children[0] ) === 'Neu: Rechnung' && textOf( field( fresh, 'saved' ) ) === 'Noch nicht gespeichert' && field( fresh, 'f-date' ).value.length === 10
		&& field( fresh, 'f-due_date' ).value > field( fresh, 'f-date' ).value && field( fresh, 'f-client_id' ).value === '' && lineRows( fresh ).length === 0 && fresh.app.state.draft.id === 0 );
	type( field( fresh, 'f-client_id' ), '1', true );
	await settle();
	check( 'a new draft is not saved before it can be: with a client and no line it is whole, and the first save makes it - the category and the client, no id', fresh.saves.length === 1 && fresh.saves[0].category === 'invoice' && fresh.saves[0].client_id === '1' && fresh.saves[0].id === undefined && fresh.saves[0].lines.length === 0 );
	check( '...the address follows the id: #entwurf-77, and the next save changes that draft', fresh.location.hash === '#entwurf-77' && fresh.app.state.draft.id === 77 );
	type( field( fresh, 'f-date' ), '2026-12-01', true );
	await settle();
	check( '...the next save carries the id', fresh.saves[1].id === '77' && fresh.saves[1].category === undefined );
	const offerNew = await editing( {}, {}, '#neu-offer' );
	check( 'a new offer has the valid-until day and no due day', field( offerNew, 'f-valid_until' ) !== undefined && field( offerNew, 'f-due_date' ) === undefined );

	// a contract
	const contractNew = await editing( {}, {}, '#neu-contract-13' );
	check( 'a new contract is made on an offer: the offer is chosen, there is no client to choose and no lines; the client of the offer is the one shown', field( contractNew, 'f-parent_id' ).value === '13' && field( contractNew, 'f-client_id' ) === undefined && field( contractNew, 'lines' ) === undefined && field( contractNew, 'gross' ) === undefined
		&& textOf( field( contractNew, 'client-box' ) ).indexOf( 'Jonas Richter' ) !== -1 && field( contractNew, 'f-title' ) !== undefined );
	type( field( contractNew, 'f-title' ), 'Dienstleistungsvertrag', true );
	await settle();
	check( '...it is saved with the offer and its title, without a client or lines', contractNew.saves.length === 1 && contractNew.saves[0].parent_id === '13' && contractNew.saves[0].title === 'Dienstleistungsvertrag' && contractNew.saves[0].category === 'contract' && contractNew.saves[0].client_id === undefined && contractNew.saves[0].lines === undefined );
	type( field( contractNew, 'f-template_key' ), 'vertrag' );
	await settle();
	check( '...the template brings its own required field, marked with a star, and its words go with the save', textOf( field( contractNew, 'f-inputs.zusatz' ).parentNode ).indexOf( 'Zusatz *' ) === 0 );
	type( field( contractNew, 'f-inputs.zusatz' ), 'Laufzeit ein Jahr', true );
	await settle();
	check( '...', contractNew.saves[contractNew.saves.length - 1].inputs.zusatz === 'Laufzeit ein Jahr' && contractNew.saves[contractNew.saves.length - 1].template_key === 'vertrag' );
	click( contractNew, field( contractNew, 'finalise' ) );
	await settle();
	check( 'a document without lines is finalised with its own question: the PDF is made', textOf( all( contractNew.dialogs[0], 'p' )[0] ) === 'Die PDF wird erzeugt. Danach ist das Dokument unveränderlich.' );

	// small business: no rate, no reverse charge
	const plainVat = await editing( {}, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { vat_mode : 'small_business' } ), { settings : smallBusiness } ) ] } } );
	check( 'a draft without VAT has no rate select and no reverse charge, and the totals say why', all( plainVat.main, 'select' ).filter( function( s ) { return s.id === '' } ).length === 0 && field( plainVat, 'f-reverse_charge' ) === undefined && textOf( field( plainVat, 'totals' ) ).indexOf( 'Kleinunternehmer (§ 19 UStG)' ) !== -1 && textOf( field( plainVat, 'totals' ) ).indexOf( 'USt. auf' ) === -1 );


	// === fix round 1: the login of the surface
	const loginScript = source( OWN+ 'assets/login.js' );

	check( 'the script of the login builds no markup, sets no style and asks for no word the page does not have', /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function|\.style\./.test( loginScript ) === false
		&& [ ...loginScript.matchAll( /say\( '([a-z.-]+)'/g ) ].map( function( found ) { return found[1] } ).every( function( key ) { return typeof texts[key] === 'string' && texts[key] !== '' } ) && ( loginScript.match( /wn\.fetch\(/g ) || [] ).length === 1 );

	/**
	 *	The login page with its script over the stand-in of the browser
	 *
	 *	@param		{Function}	answer					The answer of the kernel: ( init ) => { status } or a promise of it, or 'offline'
	 *	@param		{string}	[hash]
	 *
	 *	@return		{Object}
	 */
	function loginWorld( answer, hash ) {

		const mount = element('div');
		const form = element('form');
		const user = element('input');
		const pw = element('input');
		const msg = element('p');
		const submit = element('button');
		const block = element('script');
		const calls = [];
		const replaced = [];
		const reloaded = [];

		[ [ form, 'login' ], [ user, 'login-user' ], [ pw, 'login-pw' ], [ msg, 'login-msg' ], [ submit, 'login-submit' ], [ block, 'crm-data' ] ].forEach( function( pair ) { pair[0].id = pair[1]; mount.appendChild( pair[0] ) } );
		block.textContent = JSON.stringify( { base : '/club', csrf : 'token-9', texts : texts } );
		user.value = 'anna@example.org';
		pw.value = 'geheim';

		const win = {
			TextEncoder : TextEncoder,
			btoa : btoa,
			location : { hash : hash ?? '', replace : function( url ) { replaced.push( url ) }, reload : function() { reloaded.push( 1 ) } },
			fetch : function( url, init ) { calls.push( { url : url, init : init } ); return Promise.resolve( answer( init ) ).then( function( given ) { if( given === 'offline' ) throw new Error('offline'); return given } ) },
		};
		const context = vm.createContext( { window : win, document : documentOf( mount ), console : console } );

		load( OWN+ 'assets/login.js', context );
		win.Nino.crm.login.boot( block );

		return { calls : calls, replaced : replaced, reloaded : reloaded, user : user, pw : pw, msg : msg, submit : submit, form : form, submitForm : function() { let prevented = false; fire( form, 'submit', { preventDefault : function() { prevented = true } } ); return prevented } };
	}

	let signIn = loginWorld( function() { return { status : 200 } }, '#belege' );
	check( 'the form is posted to the login of the kernel under the directory of the site, the token in X-CSRF-Token, with the cookie of the session; the form is not sent by the browser', signIn.submitForm() === true && signIn.calls.length === 1 && signIn.calls[0].url === '/club/.nino/auth/login' && signIn.calls[0].init.method === 'POST'
		&& signIn.calls[0].init.headers['X-CSRF-Token'] === 'token-9' && signIn.calls[0].init.credentials === 'same-origin' );
	check( '...the pair travels as Basic credentials in the Authorization header - the one place the kernel reads it - and in no field of a body', Buffer.from( signIn.calls[0].init.headers['Authorization'].replace( /^Basic /, '' ), 'base64' ).toString( 'utf8' ) === 'anna@example.org:geheim' && signIn.calls[0].init.body === undefined );
	const umlaut = loginWorld( function() { return { status : 200 } } );
	umlaut.pw.value = 'Pässwörd:€';
	umlaut.submitForm();
	check( '...encoded as UTF-8, a colon in the password and a letter above latin-1 included', Buffer.from( umlaut.calls[0].init.headers['Authorization'].replace( /^Basic /, '' ), 'base64' ).toString( 'utf8' ) === 'anna@example.org:Pässwörd:€' );
	check( '...the button is off while the request runs, so that it cannot be sent twice', signIn.submit.disabled === true && ( signIn.submitForm(), signIn.calls.length === 1 ) );
	await settle();
	check( '200 reads /crm again where the link had a hash: a reload, which keeps the hash - an address that differs in nothing but is the one it is at only moves to the hash and reads nothing -, and never the workbench', signIn.reloaded.length === 1 && signIn.replaced.length === 0 );

	signIn = loginWorld( function() { return { status : 200 } }, '' );
	signIn.submitForm();
	await settle();
	check( '...with no hash it is /crm under the directory of the site, replaced in the history', signIn.replaced.length === 1 && signIn.replaced[0] === '/club/crm' && signIn.reloaded.length === 0 );

	signIn = loginWorld( function() { return { status : 401 } } );
	signIn.submitForm();
	await settle();
	check( '401 says the pair does not fit, empties the password and puts the cursor in it; the mail stays; nothing is read again; the button is on again', signIn.msg.textContent === texts['login.wrong'] && signIn.pw.value === '' && signIn.pw.focused === true && signIn.user.value === 'anna@example.org' && signIn.replaced.length === 0 && signIn.reloaded.length === 0 && signIn.submit.disabled === false );

	signIn = loginWorld( function() { return { status : 403 } } );
	signIn.submitForm();
	await settle();
	check( '403 says the page was open too long and has to be loaded again; the password is kept', signIn.msg.textContent === texts['login.stale'] && signIn.pw.value === 'geheim' && signIn.replaced.length === 0 && signIn.submit.disabled === false );

	signIn = loginWorld( function() { return { status : 502 } } );
	signIn.submitForm();
	await settle();
	check( 'any other status is named', signIn.msg.textContent === texts['login.status'].replace( '%s', '502' ) && signIn.msg.textContent.indexOf( '502' ) !== -1 && signIn.submit.disabled === false );

	signIn = loginWorld( function() { return 'offline' } );
	signIn.submitForm();
	await settle();
	check( 'a request that never arrived says so, and the button is on again', signIn.msg.textContent === texts['login.offline'] && signIn.submit.disabled === false );

	// === fix round 1: the session that ended leads back to /crm
	const gone = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 401, { error : 'not logged in', code : 'session' } ] }, 'crm-documents/list' : function() { return [ 401, { error : 'not logged in', code : 'session' } ] } } } );
	gone.boot();
	await settle();
	const toLogin = all( gone.dialogs[0], 'a' )[0];
	check( 'the dialog of a session that ended leads to /crm with the hash of the screen - the login of the surface shows there - and a click reads the page again, since a link to the address one is at only moves to the hash', toLogin.getAttribute( 'href' ) === '/crm#belege' && click( gone, toLogin ) === true && gone.reloads.length === 1 );

	// === fix round 1: Versenden for a client with no account, and the primary action then
	const reach = [ { id : 'aaaaaaaaaaaa', email : 'anna@example.org', name : 'Anna', active : true }, { id : 'bbbbbbbbbbbb', email : 'olga@example.org', name : 'Olga', active : false } ];
	const byAccount = [
		[ 'an open invoice, not released, no account', { state : 'open' }, [], 'Versenden' ],
		[ 'an open invoice, released, no account', { state : 'open', released : true }, [], 'Als versendet markieren' ],
		[ 'an open invoice, released, an account that is off', { state : 'open', released : true }, [ reach[1] ], 'Als versendet markieren' ],
		[ 'an open invoice, released, an account that is on', { state : 'open', released : true }, [ reach[0] ], 'Versenden' ],
		[ 'an open invoice, not released, an account that is on', { state : 'open', released : false }, [ reach[0] ], 'Versenden' ],
		[ 'an open offer, released, no account', { id : 13, category : 'offer', state : 'open', released : true, due_date : null }, [], 'Als versendet markieren' ],
		[ 'a sent invoice, released, no account', { state : 'sent', released : true }, [], 'Als bezahlt markieren' ],
		[ 'an open contract, released, no account: the signature, not the mail', { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, [], 'Unterschrift anfordern' ],
	];

	for( const [ label, over, accs, expected ] of byAccount ) {
		const w = await opened( over, { accounts : accs } );
		check( 'the primary action of '+ label+ ': '+ expected, primaryOf( w ) === expected && all( w.main, '.btn.primary' ).length === 1 );
	}

	check( '...the model has it by state, released and the accounts alike', [ [ { state : 'open', released : true, category : 'invoice' }, [], 'sent' ], [ { state : 'open', released : true, category : 'invoice' }, [ reach[0] ], 'send' ], [ { state : 'open', released : false, category : 'invoice' }, [], 'send' ] ]
		.every( function( one ) { return router.app.model.primaryOf( one[0], one[1] ) === one[2] } ) );

	const nobodyReached = await stepper( { state : 'open' }, { 'crm-documents/release' : function() { return [ 200, { document : full( { state : 'open', released : true } ), mailed : 0, mail : true } ] } } );
	click( nobodyReached, all( nobodyReached.main, '#primary' )[0] );
	await settle();
	check( 'Versenden for a client with no account releases and says there was no mail - it does not claim one: the sentence of send.no-account, as a plain toast', nobodyReached.toast.textContent === texts['send.no-account'] && nobodyReached.toast.classList.contains( 'bad' ) === false && nobodyReached.toast.textContent !== texts['send.done'].replace( '%s', '0' ) );

	const withAccount = await opened( { state : 'open' }, { accounts : [ reach[0] ] }, { 'crm-documents/release' : function() { return [ 200, { document : full( { state : 'open', released : true } ), mailed : 0, mail : true } ] } } );
	click( withAccount, all( withAccount.main, '#primary' )[0] );
	await settle();
	check( '...where an account is on and no mail arrived it says that instead: the mail did not come, and the account is not blamed', withAccount.toast.textContent === texts['release.mailed-none'] && withAccount.toast.textContent !== texts['send.no-account'] );

	const marking = await opened( { state : 'open', released : true }, { accounts : [] }, { 'crm-documents/state' : function() { return [ 200, { document : full( { state : 'sent' } ) } ] } } );
	click( marking, all( marking.main, '#primary' )[0] );
	check( 'the primary action Als versendet markieren asks for the day in the dialog of the menu item: a date field, nothing posted yet', marking.dialogs.length === 1 && all( marking.dialogs[0], 'input' )[0].type === 'date' && marking.calls.filter( function( c ) { return c.action === 'crm-documents/state' } ).length === 0 );
	all( marking.dialogs[0], 'input' )[0].value = '2026-11-02';
	click( marking, all( marking.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...and the yes posts crm-documents/state { state: "sent", date }, as the menu item does', JSON.stringify( marking.last( 'crm-documents/state' ).data ) === '{"id":"11","state":"sent","date":"2026-11-02"}' );

	// === fix round 1: no toast claims a mail that was not sent
	const mailless = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : [ reach[0] ], sign_days : 14 }, { 'crm-documents/sign' : function() { return [ 200, { document : full( {} ), mailed : false } ] } } );
	click( mailless, all( mailless.main, '#primary' )[0] );
	click( mailless, all( mailless.dialogs[0], 'button' )[1] );
	await settle();
	check( 'a signature asked for where Sign could not mail the account says so: the link has to be sent again - and does not say the account has a mail', mailless.toast.textContent === texts['sign.asked-unmailed'] && mailless.toast.textContent !== texts['sign.asked'] );

	const releasedSilent = await opened( { state : 'open' }, { accounts : [ reach[0] ] }, { 'crm-documents/release' : function() { return [ 200, { document : full( { released : true } ), mailed : 3, mail : true } ] } } );
	click( releasedSilent, all( releasedSilent.main, '#primary' )[0] );
	await settle();
	check( '...and Versenden with three mails delivered counts three', releasedSilent.toast.textContent === texts['send.done'].replace( '%s', '3' ) );

	// === fix round 1: the menu by state, released or not
	const moreMenus = [
		[ 'an invoice that is overdue and not in the portal: no reminder, no way back', { state : 'sent', overdue : true, released : false }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Notizen' ],
		[ 'an invoice that is in the portal and not overdue: no reminder, the way back', { state : 'sent', overdue : false, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Freigabe zurücknehmen|Notizen' ],
		[ 'an open invoice that is overdue and in the portal: marked as sent, the reminder', { state : 'open', overdue : true, released : true }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Freigabe zurücknehmen|Notizen' ],
		[ 'a declined offer: no invoice, a contract is still possible', { id : 13, category : 'offer', state : 'declined', due_date : null }, 'PDF ansehen|PDF herunterladen|Vertrag erstellen|Als Entwurf kopieren|Notizen' ],
		[ 'a sent offer', { id : 13, category : 'offer', state : 'sent', due_date : null }, 'PDF ansehen|PDF herunterladen|Als angenommen markieren|Als abgelehnt markieren|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Notizen' ],
		[ 'a cancelled invoice that is in the portal', { state : 'cancelled', released : true }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Freigabe zurücknehmen|Notizen' ],
	];

	for( const [ label, over, expected ] of moreMenus ) {
		const w = await opened( over );
		check( 'the menu of '+ label+ ': '+ expected.replace( /\|/g, ', ' ), menuOf( w ).join( '|' ) === expected );
	}

	// === fix round 1: the Vorlage in the block Beleg
	const withTemplate = await opened( { template_key : 'rechnung' } );
	const noTemplate = await opened( { template_key : '' } );
	const goneTemplate = await opened( { template_key : 'weg' } );
	const termsOf = function( w ) { return all( all( w.main, '.card.pad' )[1], 'dt' ).map( textOf ) };
	check( 'the block Beleg of a document that has a template names it: the row Vorlage with the name of the template; one whose template is gone shows its key; a document with none has no row', all( all( withTemplate.main, '.card.pad' )[1], 'dd' )[1] !== undefined && termsOf( withTemplate ).indexOf( 'Vorlage' ) === 1
		&& textOf( all( all( withTemplate.main, '.card.pad' )[1], 'dd' )[1] ) === 'Rechnung Standard' && termsOf( noTemplate ).indexOf( 'Vorlage' ) === -1 && textOf( all( all( goneTemplate.main, '.card.pad' )[1], 'dd' )[1] ) === 'weg' );

	// === fix round 1: a late answer never draws over the screen that came after it
	let release;
	const held = new Promise( function( resolve ) { release = resolve } );
	const slow = world( { hash : '#beleg-11', answers : {
		'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
		'crm-documents/get' : function() { return held },
		'crm-documents/list' : function() { return [ 200, { documents : [ row( {} ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
	} } );
	slow.boot();
	await settle();
	slow.location.hash = '#belege';
	await settle();
	release( [ 200, view( { id : 11 } ) ] );
	await settle();
	check( 'the answer of a screen that arrives after the person went on does not draw: the list stays, and nothing of the document is on the page', all( slow.main, '#q' ).length === 1 && all( slow.main, '#primary' ).length === 0 && textOf( slow.main ).indexOf( 'R-2026-0007' ) === -1 && slow.app.state.view === 2 );

	let rereads = 0;
	let again;
	const reread = new Promise( function( resolve ) { again = resolve } );
	const late = await opened( { state : 'sent' }, undefined, {
		'crm-documents/get' : function() { rereads++; return rereads >= 2 ? reread : [ 200, view( { state : 'sent' } ) ] },
		'crm-documents/state' : function() { return [ 200, { document : full( { state : 'paid' } ) } ] },
		'crm-documents/list' : function() { return [ 200, { documents : [ row( {} ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
	} );
	click( late, all( late.main, '#primary' )[0] );
	await settle();
	late.location.hash = '#belege';
	await settle();
	again( [ 200, view( { state : 'paid' } ) ] );
	await settle();
	check( 'a step whose document is read again after the person went on does not draw it over the next screen: the list stays', rereads === 2 && all( late.main, '#q' ).length === 1 && all( late.main, '#primary' ).length === 0 && all( late.main, '#gross' ).length === 0 );

	// === fix round 1: the editor
	const sums = JSON.parse( fs.readFileSync( path.join( root, 'app/Crm/tests/money-cases.json' ), 'utf8' ) ).totals;
	const money = router.win.Nino.crm.money;
	const fieldsOf = function( cases ) {
		return cases.lines.map( function( line ) { return { title : 'x', description : '', unit : '', qty : money.formatQty( line.qty ), price : money.format( line.price_net ), vat_rate : line.vat_rate } } );
	};

	sums.forEach( function( one ) {
		const totals = router.app.model.totalsOf( { vat_mode : one.mode, reverse_charge : one.reverse, lines : fieldsOf( one ) } );
		check( 'the live sums of the editor are the sums of the cases of money-cases.json: '+ one.name, totals.net === one.expect.net && totals.vat === one.expect.vat && totals.gross === one.expect.gross
			&& JSON.stringify( totals.rates.map( function( rate ) { return [ rate.rate, rate.net, rate.vat ] } ) ) === JSON.stringify( one.expect.rates ) && JSON.stringify( totals.amounts ) === JSON.stringify( one.expect.amounts ) );
	} );

	check( '...a reverse charge asked for in the small business mode counts as none: the mode decides', ( function() {
		const one = sums[0];
		const totals = router.app.model.totalsOf( { vat_mode : 'small_business', reverse_charge : true, lines : fieldsOf( one ) } );
		return totals.gross === one.expect.gross && totals.vat === 0;
	} )() === true );

	const reverseDoc = await editing( { reverse_charge : true } );
	check( 'the editor of a draft under reverse charge draws the head and the totals without VAT: the gross is the net, 930,00, and no row of VAT', textOf( field( reverseDoc, 'gross' ) ) === '930,00'+ NBSP+ '€' && textOf( field( reverseDoc, 'totals' ) ).indexOf( 'USt. auf' ) === -1 && all( lineRows( reverseDoc )[0], 'select' )[0].value === '0' );
	const smallView = world( { hash : '#entwurf-16', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { vat_mode : 'small_business' } ), { settings : smallBusiness } ) ] }, 'crm-documents/list' : function() { return [ 200, { documents : [], years : [], clients : clients, settings : smallBusiness, items : items, offers : offers } ] }, 'crm-clients/get' : function() { return [ 200, { client : client, accounts : [] } ] } } } );
	smallView.boot();
	await settle();
	check( '...and in the small business mode the head shows the net as the gross with no VAT, whatever the rates of the lines say: 930,00 for 10 x 60,00 + 3 x 110,00', textOf( field( smallView, 'gross' ) ) === '930,00'+ NBSP+ '€' && textOf( field( smallView, 'totals' ) ).indexOf( 'USt. auf' ) === -1 );

	// a save made while one runs is not lost: at most one request in flight, the next one waits and sends the latest
	let letGo;
	const queued = await editing( {}, {
		'crm-documents/save' : function( data ) { queuedSaves.push( data ); return queuedSaves.length === 1 ? new Promise( function( resolve ) { letGo = function() { resolve( [ 200, { document : full( Object.assign( {}, draftDoc, { id : 16, updated : '2026-11-03T14:30:00Z' } ) ) } ] ) } } ) : [ 200, { document : full( Object.assign( {}, draftDoc, { id : 16, date : data.date, updated : '2026-11-03T14:31:00Z' } ) ) } ] },
	} );
	const queuedSaves = [];
	type( field( queued, 'f-date' ), '2026-12-02' );
	await settle();
	type( field( queued, 'f-date' ), '2026-12-03' );
	type( field( queued, 'f-date' ), '2026-12-04' );
	await settle();
	check( 'a field left while a save runs sends nothing yet: one request in flight', queuedSaves.length === 1 && queuedSaves[0].date === '2026-12-02' );
	letGo();
	await settle();
	check( '...when it is through the changes made meanwhile go out as one more request, with the latest state, and no third', queuedSaves.length === 2 && queuedSaves[1].date === '2026-12-04' && queued.app.state.draft.dirty === false );

	// autosave waits for a moment: a row of changes is one save, and leaving a field saves at once
	const wait = await editing();
	const waitDate = field( wait, 'f-date' );
	const moments = function() { return wait.timers.filter( function( timer ) { return timer.ms === 800 && timer.cleared !== true } ) };
	waitDate.value = '2026-12-05';
	fire( waitDate, 'input', { target : waitDate } );
	fire( waitDate, 'change', { target : waitDate } );
	waitDate.value = '2026-12-06';
	fire( waitDate, 'input', { target : waitDate } );
	fire( waitDate, 'change', { target : waitDate } );
	waitDate.value = '2026-12-07';
	fire( waitDate, 'input', { target : waitDate } );
	fire( waitDate, 'change', { target : waitDate } );
	await settle();
	check( 'three changes in a row start a timer of 800 ms each and restart it: one is left standing, and nothing is posted before it runs', wait.timers.filter( function( timer ) { return timer.ms === 800 } ).length === 3 && moments().length === 1 && wait.saves.length === 0 );
	const ran = moments()[0];
	ran.fn();
	ran.cleared = true;
	await settle();
	check( '...when it runs the draft is posted once, with the last of the changes', wait.saves.length === 1 && wait.saves[0].date === '2026-12-07' );
	type( waitDate, '2026-12-08' );
	await settle();
	check( 'leaving a field saves at once, without waiting for the timer', wait.saves.length === 2 && wait.saves[1].date === '2026-12-08' && moments().length === 0 );
	const pending = await editing();
	fire( field( pending, 'f-date' ), 'input', { target : field( pending, 'f-date' ) } );
	field( pending, 'f-date' ).value = '2026-12-09';
	fire( field( pending, 'f-date' ), 'input', { target : field( pending, 'f-date' ) } );
	fire( field( pending, 'f-date' ), 'change', { target : field( pending, 'f-date' ) } );
	pending.location.hash = '#belege';
	await settle();
	const standing = pending.timers.filter( function( timer ) { return timer.ms === 800 && timer.cleared !== true } );
	standing.forEach( function( timer ) { timer.fn() } );
	await settle();
	check( 'a timer that runs after the person went on posts nothing', pending.saves.length === 0 );

	// the title of the editor follows the first save
	const titled = await editing( {}, {}, '#neu-invoice' );
	check( 'a new draft is titled "Neu: Rechnung"', textOf( all( titled.main, '.head' )[0].children[0] ) === 'Neu: Rechnung' );
	type( field( titled, 'f-client_id' ), '1', true );
	await settle();
	check( '...and after the first save "Entwurf · Rechnung", as a draft that was opened is, with the category word: the same h1, not another', titled.saves.length === 1 && textOf( all( titled.main, '.head' )[0].children[0] ) === texts['editor.title'].replace( '%s', texts['category.invoice'] ) && all( titled.main, 'h1' ).length === 1 );

	// Enter in the search of a position
	const entering = await editing();
	let adder2 = field( entering, 'additem' );
	const countBefore = lineRows( entering ).length;
	const enter = function( node ) { fire( node, 'keydown', { key : 'Enter', target : node, preventDefault : function() {} } ) };
	adder2.value = 'Beratung vor Ort';
	enter( adder2 );
	const added = lineRows( entering );
	const addedFields = all( added[added.length - 1], 'input' );
	check( 'Enter with a title that no position carries inserts a line with that title, one piece, no unit and no price - the same as an empty line with the title typed -, and the field is empty', added.length === countBefore + 1 && addedFields[0].value === 'Beratung vor Ort' && addedFields[1].value === '1' && addedFields[2].value === '' && addedFields[3].value === '' && field( entering, 'additem' ).value === '' );
	check( '...the cursor is in the price of the new line', addedFields[3].focused === true && entering.app.state.draft.lines[countBefore].title === 'Beratung vor Ort' && entering.app.state.draft.lines[countBefore].qty === '1' );
	check( '...it takes the first rate of the setting in the regular mode, as an empty line does', all( added[added.length - 1], 'select' )[0].value === '1900' );
	adder2 = field( entering, 'additem' );
	adder2.value = '   ';
	enter( adder2 );
	check( 'Enter with an empty title does nothing', lineRows( entering ).length === countBefore + 1 );
	adder2.value = 'Noch eine Leistung';
	fire( adder2, 'change', { target : adder2 } );
	check( 'leaving the field with a title typed is no Enter: nothing is inserted until it is pressed', lineRows( entering ).length === countBefore + 1 );

	// === the answers that end a session
	const lost = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 401, { error : 'not logged in', code : 'session' } ] }, 'crm-documents/list' : function() { return [ 401, { error : 'not logged in', code : 'session' } ] } } } );
	lost.boot();
	await settle();
	check( 'a 401 with the code session shows "Deine Sitzung ist abgelaufen" in a dialog with a link to the login, once, however many requests got it', lost.dialogs.length === 1 && textOf( all( lost.dialogs[0], 'h2' )[0] ) === 'Sitzung abgelaufen' && textOf( all( lost.dialogs[0], 'p' )[0] ).indexOf( 'Deine Sitzung ist abgelaufen.' ) === 0
		&& all( lost.dialogs[0], 'a' )[0].getAttribute( 'href' ) === '/crm#belege' && textOf( all( lost.dialogs[0], 'a' )[0] ) === 'Zur Anmeldung' && all( lost.dialogs[0], 'button' ).length === 0 );
	check( '...and no toast on top of it', lost.toast.textContent === '' );

	const stale = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 403, { error : 'x', code : 'csrf' } ] }, 'crm-documents/list' : function() { return [ 403, { error : 'the session token is no longer valid - reload the page', code : 'csrf' } ] } } } );
	stale.boot();
	await settle();
	check( 'a 403 with the code csrf reloads the page, once: the flag is set, no toast', stale.reloads.length === 1 && stale.storage['crm-reloaded'] === '1' && stale.toast.textContent === '' );
	stale.location.hash = '#belege';
	await settle();
	check( '...the second time it does not reload again and says the sentence of the code', stale.reloads.length === 1 && stale.toast.textContent === 'Das Sitzungs-Token dieser Seite gilt nicht mehr. Lade die Seite neu.' );
	stale.answers['crm-documents/list'] = function() { return [ 200, { documents : [], years : [], clients : [], settings : settings, items : [], offers : [] } ] };
	stale.answers['crm-documents/overview'] = function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] };
	stale.location.hash = '#belege';
	await settle();
	check( '...an answer that went through clears the flag, so that the next stale token reloads again', stale.storage['crm-reloaded'] === undefined );

	const offline = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return 'offline' } } } );
	offline.boot();
	await settle();
	check( 'a request that never arrived says so: no connection', textOf( offline.main ).indexOf( 'Keine Verbindung zum Server.' ) !== -1 );
	check( '403 without a code is the sentence for a missing permission, any other code is looked up as error.<code> and gets its params', ( function() {
		const e = router.app.model.errorText;
		return e( { status : 403, body : { error : 'not allowed' } } ) === 'Dafür fehlt Deinem Konto die Berechtigung.' && e( { status : 400, body : { code : 'crm_documents_lines', params : [ 200 ] } } ) === 'Ein Beleg hat höchstens 200 Positionen.' && e( { status : 500, body : null }, 'error.save' ) === 'Der Beleg konnte nicht gespeichert werden.'
			&& e( { status : 400, body : { code: 'not_a_code_we_know' } } ) === 'Die Anfrage ist fehlgeschlagen.';
	} )() );

	// === the shell: keys, menus, toasts
	const keys = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : docs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	keys.boot();
	await settle();
	const q = all( keys.main, '#q' )[0];
	keys.page.activeElement = null;
	const kept = press( keys, '/' );
	check( 'the slash finds the search field and keeps the slash out of it', kept === true && q.focused === true );
	q.focused = false;
	keys.page.activeElement = q;
	check( '...while the person types in a field it is a slash', press( keys, '/' ) === false && q.focused === false );
	keys.page.activeElement = null;

	const newer = all( keys.main, '.menu' )[0];
	const trigger = all( newer, 'button' )[0];
	click( keys, trigger );
	check( 'the button of a menu opens it, and says so', newer.classList.contains( 'open' ) === true && trigger.getAttribute( 'aria-expanded' ) === 'true' && trigger.getAttribute( 'aria-haspopup' ) === 'true' );
	click( keys, trigger );
	check( '...a second click closes it', newer.classList.contains( 'open' ) === false && trigger.getAttribute( 'aria-expanded' ) === 'false' );
	click( keys, trigger );
	press( keys, 'Escape' );
	check( 'Escape closes an open menu', newer.classList.contains( 'open' ) === false );
	click( keys, trigger );
	click( keys, keys.main );
	check( 'so does a click anywhere else', newer.classList.contains( 'open' ) === false );
	click( keys, trigger );
	click( keys, all( newer, '[role="menuitem"]' )[1] );
	check( 'a click on an item runs it and closes the menu: Angebot opens the editor of a new offer', keys.location.hash === '#neu-offer' && newer.classList.contains( 'open' ) === false );

	keys.timers.length = 0;
	keys.app.state.toast.classList.remove( 'show' );
	const shown = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/get' : function() { return [ 200, view( { state : 'sent' } ) ] },
		'crm-documents/state' : function() { return [ 200, { document : full( { state : 'paid' } ) } ] } } } );
	shown.location._hash = '#beleg-11';
	shown.boot();
	await settle();
	click( shown, all( shown.main, '#primary' )[0] );
	await settle();
	const lastTimer = shown.timers[shown.timers.length - 1];
	check( 'a toast is shown for a few seconds and then goes: the class show is on, a timer is set, and when it runs the toast is off', shown.toast.classList.contains( 'show' ) === true && lastTimer.ms === 2600 && ( lastTimer.fn(), shown.toast.classList.contains( 'show' ) === false ) );
	click( shown, all( shown.main, '#primary' )[0] );
	await settle();
	click( shown, all( shown.main, '#primary' )[0] );
	await settle();
	check( '...a second toast takes the timer of the first one away, so that it is not taken off early', shown.timers.filter( function( timer ) { return timer.cleared === true } ).length >= 1 );
	check( 'the toast is a status for a screen reader and the main region says when it is busy', source( 'app/Crm/Surface/templates/page.tpl' ).indexOf( 'id="toast" role="status" aria-live="polite"' ) !== -1 && script.indexOf( "'aria-busy'" ) !== -1 );

	// === C08: the screens Kunden, Kundenkarte, Neuer Kunde, Positionen and Einstellungen, and the panel CRM

	const kclient = { id : 1, number : 'K-0001', company : 'Nordlicht Records GmbH', name : 'Lena Brandt', street : 'Hafenstraße 12', zip : '20457', city : 'Hamburg', country : 'DE', email : 'lena@nordlicht.example', phone : '+49 40 123456', vat_id : 'DE123456789',
		notes : 'Zahlt pünktlich.', active : true, created : '2025-02-11T09:00:00Z', updated : '2026-10-01T09:00:00Z', accounts : 3, active_accounts : 2, group : 'k-0001', documents : { total : 2, released : 1 } };
	const kaccounts = [
		{ id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'lena@nordlicht.example', name : 'Lena Brandt', active : true, login : '2026-10-30T10:00:00Z' },
		{ id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'tom@nordlicht.example', name : '', active : true, login : null },
		{ id : 'cccccccccccc', gone : false, state : 'off', email : 'alt@nordlicht.example', name : '<b>Alt</b> Konto', active : false, login : '2026-01-02T10:00:00Z' },
		{ id : 'dddddddddddd', gone : true, state : 'gone' },
	];
	const kclients = [
		Object.assign( {}, kclient ),
		{ id : 2, number : 'K-0002', company : '', name : 'Jonas Richter', street : 'Lindenweg 3', zip : '1010', city : 'Wien', country : 'AT', email : 'jonas@example.org', phone : '', vat_id : '', notes : '', active : true, created : '2025-03-01T09:00:00Z', accounts : 1, active_accounts : 1, group : 'k-0002' },
		{ id : 3, number : 'K-0003', company : 'Ohne Konto AG', name : 'Carla Ohne', street : '', zip : '', city : 'Köln', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : true, accounts : 0, active_accounts : 0, group : 'k-0003' },
		{ id : 4, number : 'K-0004', company : 'Alt AG', name : 'Dora Alt', street : '', zip : '', city : 'Dresden', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : false, accounts : 12, active_accounts : 0, group : 'k-0004' },
		{ id : 5, number : 'K-0005', company : '<img src=x onerror=alert(1)> GmbH', name : 'Hostile', street : '', zip : '', city : 'Bonn', country : 'DE', email : '', phone : '', vat_id : '', notes : '', active : true, accounts : 12, active_accounts : 11, group : 'k-0005' },
	];
	const kdocs = [
		row( { id : 11, number : 'R-2026-0007', client_id : 1, client_title : 'Nordlicht Records GmbH', state : 'sent', gross : 119000, released : true } ),
		row( { id : 12, number : 'R-2026-0008', client_id : 1, client_title : 'Nordlicht Records GmbH', state : 'open', gross : 50000, date : '2026-10-20' } ),
		row( { id : 13, number : 'R-2026-0001', client_id : 1, client_title : 'Nordlicht Records GmbH', state : 'paid', gross : 99900, date : '2026-02-01' } ),
		row( { id : 14, number : 'R-2026-0009', client_id : 2, client_title : 'Jonas Richter', state : 'open', gross : 21420, date : '2026-10-25' } ),
		row( { id : 15, category : 'offer', number : 'A-2026-0011', client_id : 3, client_title : 'Ohne Konto AG', state : 'open', gross : 77700, date : '2026-10-26' } ),
		row( { id : 16, category : 'invoice', number : '', client_id : 3, client_title : 'Ohne Konto AG', state : 'draft', gross : 5000, date : '2026-11-03' } ),
		row( { id : 17, category : 'contract', number : '', title : 'Vertrag Album 2027', client_id : 1, client_title : 'Nordlicht Records GmbH', state : 'open', gross : 0, date : '2026-10-30' } ),
	];
	const kportal = { documents : [ { id : 11, category : 'invoice', number : 'R-2026-0007', title : '', state : 'sent', expired : false, date : '2026-10-02', sign_id : '', sign_state : '' }, { id : 17, category : 'contract', number : '', title : 'Vertrag Album 2027', state : 'open', expired : false, date : '2026-10-30', sign_id : 'a', sign_state : 'viewed' } ],
		features : { files : true, review : true }, folders : [ { id : 'f1', name : 'Nordlicht <Album>', upload : true, files : 14 }, { id : 'f2', name : 'Cover', upload : false, files : 1 } ], review : { media : 3, comments : 7 } };

	/**
	 *	@param		{Object}	[over]					Answers that take the place of the defaults: the clients, the card of client 1, the documents, the overview with its count of clients
	 *
	 *	@return		{Object}
	 */
	const kanswers = function( over ) {
		return Object.assign( {
			'crm-documents/overview' : function() { return [ 200, { summary : Object.assign( {}, summary, { clients : 3 } ), needs : [], recent : [], settings : settings } ] },
			'crm-clients/list' : function() { return [ 200, { clients : kclients } ] },
			'crm-clients/get' : function( data ) { return [ 200, { client : kclients.find( function( one ) { return String( one.id ) === data.id } ) || kclient, accounts : kaccounts } ] },
			'crm-documents/list' : function() { return [ 200, { documents : kdocs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
			'crm-clients/portal' : function() { return [ 200, kportal ] },
		}, over || {} );
	};

	/**
	 *	@param		{string}	hash
	 *	@param		{Object}	[over]
	 *
	 *	@return		{Promise}									The world, booted on the hash and settled
	 */
	const kopen = async function( hash, over ) {
		const w = world( { hash : hash, answers : kanswers( over ) } );
		w.boot();
		await settle();
		return w;
	};

	const kcells = function( tr ) { return Array.from( tr.children ).map( textOf ) };
	const krowsOf = function( w ) { return all( w.main, 'tbody' )[0].children };
	const kitem = function( menuRoot, label ) { return all( menuRoot, '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === label } ) };

	// --- Kunden
	const kl = await kopen( '#kunden' );

	check( 'Kunden asks for the clients and, for what each still owes, for the invoices of every year; the rail counts the active clients', kl.last( 'crm-clients/list' ) !== undefined && JSON.stringify( kl.last( 'crm-documents/list' ).data ) === '{"category":"invoice"}' && kl.clientCount.textContent === '3'
		&& all( kl.nav, '[data-r]' ).find( function( a ) { return a.getAttribute( 'data-r' ) === 'kunden' } ).classList.contains( 'on' ) === true );
	check( '...the head says how many of how many, and the table has the columns of the mock, the number first and ascending', textOf( all( kl.main, 'h1' )[0] ) === 'Kunden' && textOf( all( kl.main, '.sub' )[0] ) === '5 von 5'
		&& all( kl.main, 'th' ).map( textOf ).join( '|' ) === 'Nummer ↑|Firma|Name|Ort|Konten|Offen|Status' );
	check( '...a row says the number, the company (a stroke where there is none), the name, the city with the country where it is not DE, the accounts that are on of all of them (keins for none), what is owed and the badge of the state',
		kcells( krowsOf( kl )[0] ).join( '|' ) === 'K-0001|Nordlicht Records GmbH|Lena Brandt|Hamburg|2 / 3|1.690,00'+ NBSP+ '€|aktiv' && kcells( krowsOf( kl )[1] ).join( '|' ) === 'K-0002|–|Jonas Richter|Wien (AT)|1 / 1|214,20'+ NBSP+ '€|aktiv' );
	const kfirst = kcells( krowsOf( kl )[0] );
	const kthird = kcells( krowsOf( kl )[2] );
	check( '...what is owed is the sum of the invoices of the client that are open or sent - 1.190,00 and 500,00; the paid one, the offer and the draft do not count -, nothing owed is a stroke, no account is keins, and a client that is off wears the grey badge, an active one the green',
		kfirst[5] === '1.690,00'+ NBSP+ '€' && kthird[4] === 'keins' && kthird[5] === '–' && kcells( krowsOf( kl )[3] )[6] === 'inaktiv' && all( krowsOf( kl )[3], '.badge' )[0].classList.contains( 'b-draft' ) === true && all( krowsOf( kl )[0], '.badge' )[0].classList.contains( 'b-paid' ) === true );
	check( '...what the server sends is text and nothing else: a company that is markup makes no element', textOf( krowsOf( kl )[4].children[1] ) === '<img src=x onerror=alert(1)> GmbH' && findAll( kl.main, function( node ) { return node.tagName === 'IMG' } ).length === 0 );

	click( kl, all( kl.main, 'tbody tr' )[1] );
	check( 'a click on a row opens the card of that client', kl.location.hash === '#kunde-2' );
	kl.location.hash = '#kunden';
	await settle();

	// sort: by the column, again to turn it, numbers as numbers
	const kids = function( w ) { return Array.from( krowsOf( w ) ).map( function( tr ) { return tr.dataset.id } ).join() };
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'company' } ) );
	check( 'a click on a column head sorts by it, ascending: the company, a client with none first, then by the word', kids( kl ) === '2,5,4,1,3' && all( kl.main, 'th' )[1].getAttribute( 'aria-sort' ) === 'ascending' && textOf( all( kl.main, 'th' )[1] ) === 'Firma ↑' && all( kl.main, 'th' )[0].getAttribute( 'aria-sort' ) === 'none' );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'company' } ) );
	check( '...and a second click on the same head turns the order', kids( kl ) === '3,1,4,5,2' && all( kl.main, 'th' )[1].getAttribute( 'aria-sort' ) === 'descending' );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'accounts' } ) );
	check( 'the accounts are sorted as numbers, the ones that are on: 0 (twice, by the id), 1, 2, 11 - not as the text would be, where 11 comes before 2', kids( kl ) === '3,4,2,1,5' );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'open' } ) );
	check( 'what is owed is sorted by the cents, the most first when the column is chosen: 1.690,00, 214,20, then those that owe nothing in the order of their ids', kids( kl ) === '1,2,3,4,5' && all( kl.main, 'th' )[5].getAttribute( 'aria-sort' ) === 'descending' );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'number' } ) );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'active' } ) );
	check( 'the state sorts too: the client that is off comes first when it is ascending', kids( kl ).split( ',' )[0] === '4' );
	click( kl, all( kl.main, 'button' ).find( function( b ) { return b.dataset.sort === 'number' } ) );

	// search: over number, company, name, mail and city; the count; the sentence for none
	type( all( kl.main, '#q' )[0], 'wien', false );
	check( 'the search finds by city, case does not matter: one of five, and the field keeps its text', kids( kl ) === '2' && textOf( all( kl.main, '.sub' )[0] ) === '1 von 5' && all( kl.main, '#q' )[0].value === 'wien' );
	type( all( kl.main, '#q' )[0], 'jonas@', false );
	check( '...by mail, by number and by name as well', kids( kl ) === '2' && ( type( all( kl.main, '#q' )[0], 'k-0003', false ), krowsOf( kl )[0].dataset.id === '3' ) && ( type( all( kl.main, '#q' )[0], 'dora', false ), krowsOf( kl )[0].dataset.id === '4' ) );
	type( all( kl.main, '#q' )[0], 'zzz', false );
	check( '...a search that finds nothing says that, and not that there are no clients', textOf( all( kl.main, 'tbody' )[0] ) === 'Kein Kunde passt zur Suche.' );
	type( all( kl.main, '#q' )[0], '', false );

	const klot = Array.from( { length : 120 }, function( unused, index ) { return Object.assign( {}, kclients[2], { id : 100 + index, number : 'K-'+ String( 100 + index ).padStart( 4, '0' ), company : 'Kunde '+ index } ) } );
	const kpaged = await kopen( '#kunden', { 'crm-clients/list' : function() { return [ 200, { clients : klot } ] } } );
	check( 'a list of 120 clients is shown fifty a page, and says which page of how many', krowsOf( kpaged ).length === 50 && textOf( all( kpaged.main, '.pager' )[0] ).indexOf( 'Seite 1 von 3' ) !== -1 && all( kpaged.main, '#prev' )[0].disabled === true );
	click( kpaged, all( kpaged.main, '#next' )[0] );
	click( kpaged, all( kpaged.main, '#next' )[0] );
	check( '...the last page has what is left, and no way on; a search goes back to the first page', krowsOf( kpaged ).length === 20 && all( kpaged.main, '#next' )[0].disabled === true && ( type( all( kpaged.main, '#q' )[0], 'Kunde 1', false ), kpaged.app.state.cfilter.page === 1 ) );

	const knone = await kopen( '#kunden', { 'crm-clients/list' : function() { return [ 200, { clients : [] } ] } } );
	check( 'no client says so and offers the first', textOf( all( knone.main, 'tbody' )[0] ) === 'Es gibt noch keine Kunden. Lege den ersten an.' && all( knone.main, '#new-client' )[0].getAttribute( 'href' ) === '#kunde-neu' );
	const kbooks = await kopen( '#kunden', { 'crm-documents/list' : function() { return [ 500, { error : 'x', code : 'crm_documents_database' } ] } } );
	check( 'where the books do not answer the list is there all the same and the column of what is owed says nothing, not nought', krowsOf( kbooks ).length === 5 && Array.from( krowsOf( kbooks ) ).every( function( tr ) { return textOf( tr.children[5] ) === '–' } ) );
	const kbad = await kopen( '#kunden', { 'crm-clients/list' : function() { return [ 500, { error : 'x', code : 'crm_clients_database' } ] } } );
	check( 'a list that fails says the sentence of its code and offers to try again', textOf( kbad.main ).indexOf( 'Die Datenbank hat die Anfrage nicht angenommen.' ) !== -1 && all( kbad.main, 'button' ).map( textOf ).indexOf( 'Noch einmal versuchen' ) !== -1 );
	check( 'the rail marks Kunden on the card and on the new client, and Positionen and Einstellungen on theirs', await ( async function() {
		const marks = [];
		for( const hash of [ '#kunde-1', '#kunde-neu', '#positionen', '#einstellungen' ] ) {
			kl.location.hash = hash;
			await settle();
			marks.push( all( kl.nav, '[data-r]' ).filter( function( a ) { return a.classList.contains( 'on' ) } ).map( function( a ) { return a.getAttribute( 'data-r' ) } ).join() );
		}
		return marks.join( '|' ) === 'kunden|kunden|positionen|einstellungen';
	} )() === true );

	// --- the router knows the new screens
	check( 'the hashes of the new screens name them: #kunden, #kunde-neu, #kunde-12 with its tab, #positionen and #einstellungen - and what is none of them is the Übersicht', [
		[ '#kunden', { screen : 'kunden' } ], [ '#kunde-neu', { screen : 'kundeneu' } ], [ '#kunde-12', { screen : 'kunde', id : 12, tab : '' } ], [ '#kunde-12-portal', { screen : 'kunde', id : 12, tab : 'portal' } ], [ '#kunde-12-belege', { screen : 'kunde', id : 12, tab : 'belege' } ],
		[ '#kunde-12-notizen', { screen : 'kunde', id : 12, tab : 'notizen' } ], [ '#kunde-12-einladen', { screen : 'kunde', id : 12, tab : 'einladen' } ], [ '#positionen', { screen : 'positionen' } ], [ '#einstellungen', { screen : 'einstellungen' } ],
		[ '#kunde-0', { screen : 'uebersicht' } ], [ '#kunde-12-evil', { screen : 'uebersicht' } ], [ '#kunde-x', { screen : 'uebersicht' } ], [ '#kunden-1', { screen : 'uebersicht' } ], [ '#neu-invoice-k0', { screen : 'uebersicht' } ],
	].every( function( pair ) { return JSON.stringify( kl.app.parse( pair[0] ) ) === JSON.stringify( pair[1] ) } ) );

	// --- Kundenkarte
	const kc = await kopen( '#kunde-1' );
	const accountsCard = function( w ) { return all( w.main, '#accounts' )[0] };
	const arows = function( w ) { return all( accountsCard( w ), '.acc' ) };
	const kmenu = function( w, label ) { return all( w.main, '.menu' ).find( function( menu ) { return textOf( all( menu, 'button' )[0] ) === label } ) };
	const labels = function( row ) { return all( row, '[role="menuitem"]' ).map( textOf ).join( '|' ) };
	const submit = function( w, form ) { fire( form, 'submit', { target : form, preventDefault : function() {} } ) };
	const yes = function( w, index ) { click( w, all( w.dialogs[index], 'button' ).pop() ) };
	const no = function( w, index ) { click( w, all( w.dialogs[index], 'button' )[0] ) };

	check( 'the card asks for the client and for its documents, by the id as a string', JSON.stringify( kc.last( 'crm-clients/get' ).data ) === '{"id":"1"}' && JSON.stringify( kc.last( 'crm-documents/list' ).data ) === '{"client":"1"}' );
	check( '...the head says the company, the badge of the state and the number, and the way back to the list', textOf( all( kc.main, 'h1' )[0] ) === 'Nordlicht Records GmbH' && textOf( all( kc.main, '.head .badge' )[0] ) === 'aktiv' && textOf( all( kc.main, '.head .sub' )[0] ) === 'K-0001'
		&& all( kc.main, '.crumb a' )[0].getAttribute( 'href' ) === '#kunden' && textOf( all( kc.main, '.crumb' )[0] ).indexOf( 'K-0001' ) !== -1 );
	const masterText = all( all( kc.main, '#master' )[0], 'dd' ).map( textOf );
	check( '...the Stammdaten are the facts of the client: the company, the name, the mail, the phone, the address on two lines, the VAT id and since when - and nothing that is empty', masterText.join( '|' ) === 'Nordlicht Records GmbH|Lena Brandt|lena@nordlicht.example|+49 40 123456|Hafenstraße 1220457 Hamburg|DE123456789|11.02.2025'
		&& all( all( kc.main, '#master' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Firma|Name|E-Mail|Telefon|Anschrift|USt-IdNr.|Kunde seit' );
	const kc2 = await kopen( '#kunde-2' );
	check( '...a client without a company, a phone and a VAT id leaves those rows out, and a country that is not DE is on the third line of the address', all( all( kc2.main, '#master' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Name|E-Mail|Anschrift|Kunde seit' && textOf( all( kc2.main, '#master dd' )[2] ) === 'Lindenweg 31010 WienAT' );
	check( '...the tabs are Belege with the count, Portal and Notizen, and the first is open', all( kc.main, '.tabs button' ).map( textOf ).join( '|' ) === 'Belege7|Portal|Notizen' && all( kc.main, '.tabs button' )[0].getAttribute( 'aria-selected' ) === 'true' && all( kc.main, '.tabs button' )[0].classList.contains( 'on' ) === true );
	const docRows = all( all( kc.main, '#tab' )[0], 'tbody tr' );
	check( '...Belege lists the documents of the client with the number (the title for a document without one, the word for a draft), the kind, the day, the amount - a stroke where there are no lines - and the badges; a click opens the document, a draft its editor',
		docRows.length === 7 && kcells( docRows[0] ).join( '|' ) === 'R-2026-0007|Rechnung|02.10.2026|1.190,00'+ NBSP+ '€|versendet Portal' && kcells( docRows[6] )[0] === 'Vertrag Album 2027' && kcells( docRows[6] )[3] === '–' && kcells( docRows[5] )[0] === 'Entwurf'
		&& ( click( kc, docRows[0] ), kc.location.hash === '#beleg-11' ) && ( click( kc, docRows[5] ), kc.location.hash === '#entwurf-16' ) );
	kc.location.hash = '#kunde-1';
	await settle();

	// the accounts
	check( 'the accounts are a card of rows: the name - the address where there is none - with the badge of the state, and under it the address and when the member signed in last or that they never did', arows( kc ).length === 4
		&& arows( kc ).map( function( row ) { return textOf( all( row, 'b' )[0] ) } ).join( '|' ) === 'Lena Brandt|tom@nordlicht.example|<b>Alt</b> Konto|Konto (dddd)'
		&& arows( kc ).map( function( row ) { return textOf( all( row, '.badge' )[0] ) } ).join( '|' ) === 'angemeldet|eingeladen|gesperrt|gelöscht'
		&& textOf( all( arows( kc )[0], '.who div' )[0] ) === 'lena@nordlicht.example · zuletzt angemeldet am 30.10.2026' && textOf( all( arows( kc )[1], '.who div' )[0] ) === 'noch nie angemeldet' && textOf( all( arows( kc )[2], '.who div' )[0] ) === 'alt@nordlicht.example · zuletzt angemeldet am 02.01.2026' );
	check( '...a name that is markup is text, and a row that is gone says so by the last four characters of its id and has no second line', findAll( accountsCard( kc ), function( node ) { return node.tagName === 'B' } ).length === 4 && all( arows( kc )[3], '.who div' ).length === 0 );
	check( '...what can be done with an account is its menu: a link to sign in for one that signed in, the invitation again for one that did not, nothing to send to one that is off - which is freed instead -, and for a row that is gone only the row to remove',
		labels( arows( kc )[0] ) === 'Anmeldelink senden|Name ändern|Sperren|Löschen' && labels( arows( kc )[1] ) === 'Einladung erneut senden|Name ändern|Sperren|Löschen' && labels( arows( kc )[2] ) === 'Name ändern|Freigeben|Löschen' && labels( arows( kc )[3] ) === 'Zeile entfernen'
		&& all( arows( kc )[0], '.menu button' )[0].getAttribute( 'aria-label' ) === 'Aktionen des Kontos' );
	check( '...Konto einladen and Bestehendes verknüpfen are below the rows, and the way to the panel of Members', textOf( all( accountsCard( kc ), '#invite-open' )[0] ) === 'Konto einladen' && textOf( all( accountsCard( kc ), '#link-open' )[0] ) === 'Bestehendes verknüpfen'
		&& all( accountsCard( kc ), 'a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join() === '/_admin/#members' );

	// the link again
	let resend = null;
	const kr = await kopen( '#kunde-1', { 'crm-clients/account-resend' : function( data ) { resend = data; return [ 200, { sent : true, kind : data.account === 'aaaaaaaaaaaa' ? 'login' : 'invite' } ] } } );
	click( kr, kitem( arows( kr )[0], 'Anmeldelink senden' ) );
	await settle();
	check( 'a link again posts crm-clients/account-resend with the client and the account, and says that the link to sign in is on its way', JSON.stringify( resend ) === '{"id":"1","account":"aaaaaaaaaaaa"}' && kr.toast.textContent === 'Der Link zum Anmelden ist unterwegs.' && kr.toast.classList.contains( 'bad' ) === false );
	click( kr, kitem( arows( kr )[1], 'Einladung erneut senden' ) );
	await settle();
	check( '...and for an account that never signed in, that the invitation is', kr.toast.textContent === 'Die Einladung ist unterwegs.' && JSON.stringify( resend ) === '{"id":"1","account":"bbbbbbbbbbbb"}' );
	const capped = await kopen( '#kunde-1', { 'crm-clients/account-resend' : function() { return [ 429, { error : 'x', code : 'crm_clients_ratelimit' } ] } } );
	click( capped, kitem( arows( capped )[0], 'Anmeldelink senden' ) );
	await settle();
	check( '...a cap of mails says its sentence, in the red toast, and the rows stay', capped.toast.textContent.indexOf( 'Grenze für E-Mails pro Stunde' ) !== -1 && capped.toast.classList.contains( 'bad' ) === true && arows( capped ).length === 4 );

	// the name
	let renamed = null;
	const kn = await kopen( '#kunde-1', { 'crm-clients/account-name' : function( data ) { renamed = data; return [ 200, { account : Object.assign( {}, kaccounts[0], { name : data.name } ) } ] } } );
	click( kn, kitem( arows( kn )[0], 'Name ändern' ) );
	check( 'the name opens a dialog with the name the account has, and nothing is posted before the yes', kn.dialogs.length === 1 && all( kn.dialogs[0], 'input' )[0].value === 'Lena Brandt' && renamed === null && textOf( all( kn.dialogs[0], 'h2' )[0] ) === 'Name ändern' );
	no( kn, 0 );
	await settle();
	check( '...the no posts nothing', renamed === null && kn.all( 'crm-clients/account-name' ).length === 0 );
	click( kn, kitem( arows( kn )[0], 'Name ändern' ) );
	type( all( kn.dialogs[1], 'input' )[0], 'Lena B.', false );
	yes( kn, 1 );
	await settle();
	check( '...the yes posts crm-clients/account-name with the client, the account and the name, the row is drawn again with the name the server answers, and the toast says so', JSON.stringify( renamed ) === '{"id":"1","account":"aaaaaaaaaaaa","name":"Lena B."}' && textOf( all( arows( kn )[0], 'b' )[0] ) === 'Lena B.' && arows( kn ).length === 4 && kn.toast.textContent === 'Der Name ist geändert.' );
	const longName = await kopen( '#kunde-1', { 'crm-clients/account-name' : function() { return [ 400, { error : 'x', code : 'crm_clients_long', params : [ 120 ], field : 'name' } ] } } );
	click( longName, kitem( arows( longName )[0], 'Name ändern' ) );
	yes( longName, 0 );
	await settle();
	check( '...a name that is refused says its sentence with the limit in it, and the row is as it was', longName.toast.textContent === 'Der Wert darf höchstens 120 Zeichen haben.' && textOf( all( arows( longName )[0], 'b' )[0] ) === 'Lena Brandt' );

	// switch
	let switched = null;
	const ks = await kopen( '#kunde-1', { 'crm-clients/account-active' : function( data ) { switched = data; return [ 200, { account : Object.assign( {}, kaccounts[data.active ? 2 : 0], { active : data.active, state : data.active ? 'invited' : 'off', login : data.active ? null : kaccounts[0].login } ) } ] } } );
	click( ks, kitem( arows( ks )[0], 'Sperren' ) );
	await settle();
	check( 'Sperren posts crm-clients/account-active with the value it wants; the row wears the badge gesperrt and offers Freigeben, and no link to send', JSON.stringify( switched ) === '{"id":"1","account":"aaaaaaaaaaaa","active":false}' && textOf( all( arows( ks )[0], '.badge' )[0] ) === 'gesperrt'
		&& labels( arows( ks )[0] ) === 'Name ändern|Freigeben|Löschen' && ks.toast.textContent === 'Das Konto ist gesperrt.' );
	click( ks, kitem( arows( ks )[2], 'Freigeben' ) );
	await settle();
	check( '...Freigeben posts active true, and says so', JSON.stringify( switched ) === '{"id":"1","account":"cccccccccccc","active":true}' && ks.toast.textContent === 'Das Konto ist freigegeben.' );
	const kInactiveAnswer = await kopen( '#kunde-1', { 'crm-clients/account-active' : function() { return [ 409, { error : 'x', code : 'crm_clients_inactive' } ] } } );
	click( kInactiveAnswer, kitem( arows( kInactiveAnswer )[2], 'Freigeben' ) );
	await settle();
	check( '...a client that is off refuses it, and the sentence says why, in the red toast', kInactiveAnswer.toast.textContent.indexOf( 'Der Kunde ist deaktiviert.' ) === 0 && textOf( all( arows( kInactiveAnswer )[2], '.badge' )[0] ) === 'gesperrt' );

	// delete
	let removed = [];
	const kd = await kopen( '#kunde-1', { 'crm-clients/account-remove' : function( data ) { removed.push( data ); return data.account === 'cccccccccccc' ? [ 500, { error : 'x', code : 'crm_clients_write' } ] : [ 200, { removed : true } ] } } );
	click( kd, kitem( arows( kd )[1], 'Löschen' ) );
	check( 'Löschen asks first, in the words that say what goes and what stays, with the name of the account', kd.dialogs.length === 1 && textOf( all( kd.dialogs[0], 'p' )[0] ) === 'tom@nordlicht.example löschen? Das Konto wird in Mitglieder gelöscht und ist dann endgültig weg. Die Dokumente des Kunden und die Anfragen der Unterschrift bleiben, wie sie sind.'
		&& textOf( all( kd.dialogs[0], 'h2' )[0] ) === 'Konto löschen?' && all( kd.dialogs[0], 'button' ).map( textOf ).join( '|' ) === 'Abbrechen|Löschen' && all( kd.dialogs[0], 'button' )[1].classList.contains( 'danger' ) === true );
	no( kd, 0 );
	await settle();
	check( '...the no posts nothing and the row stays', removed.length === 0 && arows( kd ).length === 4 );
	click( kd, kitem( arows( kd )[1], 'Löschen' ) );
	yes( kd, 1 );
	await settle();
	check( '...the yes posts crm-clients/account-remove with the client and the account, the row is gone and the others stay in their order', JSON.stringify( removed ) === '[{"id":"1","account":"bbbbbbbbbbbb"}]' && arows( kd ).length === 3 && kd.toast.textContent === 'Das Konto ist gelöscht.'
		&& arows( kd ).map( function( row ) { return row.dataset.id } ).join() === 'aaaaaaaaaaaa,cccccccccccc,dddddddddddd' );
	click( kd, kitem( arows( kd )[1], 'Löschen' ) );
	yes( kd, 2 );
	await settle();
	check( '...an account that cannot be deleted says why, and its row stays', kd.toast.textContent === 'Das Konto konnte nicht geschrieben werden.' && arows( kd ).length === 3 );
	click( kd, kitem( arows( kd )[2], 'Zeile entfernen' ) );
	check( 'the row of an account that is gone has its own question: the row, and not the account in Members, goes', kd.dialogs.length === 4 && textOf( all( kd.dialogs[3], 'p' )[0] ) === 'Die Zeile des gelöschten Kontos entfernen?' && textOf( all( kd.dialogs[3], 'h2' )[0] ) === 'Zeile entfernen' );
	yes( kd, 3 );
	await settle();
	check( '...and it posts the removal of that account', removed[removed.length - 1].account === 'dddddddddddd' && arows( kd ).length === 2 && kd.toast.textContent === 'Die Zeile ist entfernt.' );

	// invite
	let invited = null;
	const ki = await kopen( '#kunde-1', { 'crm-clients/invite' : function( data ) {
		invited = data;
		if( data.email === 'taken@example.org' )
			return [ 409, { error : 'x', code : 'crm_clients_taken', field : 'email' } ];
		return [ 200, { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.email, name : data.name, active : true, login : null }, mailed : data.email !== 'unsent@example.org' } ];
	} } );
	check( 'there is no form to invite until the button is pressed', all( ki.main, '#invite' ).length === 0 );
	click( ki, all( ki.main, '#invite-open' )[0] );
	const iform = all( ki.main, '#invite' )[0];
	check( 'Konto einladen opens the form in the card: the address and the name, the way to send and to cancel', iform !== undefined && all( iform, 'input' ).map( function( i ) { return i.type } ).join() === 'email,text' && all( iform, 'label' ).map( textOf ).join( '|' ) === 'E-Mail-Adresse des Kontos|Name (optional)'
		&& textOf( all( iform, '#invite-send' )[0] ) === 'Einladung senden' && all( iform, '#invite-cancel' ).length === 1 );
	type( all( iform, 'input' )[0], 'neu@example.org', false );
	type( all( iform, 'input' )[1], 'Neu Person', false );
	submit( ki, iform );
	await settle();
	check( 'sending posts crm-clients/invite with the client, the address and the name, and the account is a row of the card - the form is gone - with the sentence that the invitation is on its way', JSON.stringify( invited ) === '{"id":"1","email":"neu@example.org","name":"Neu Person"}' && arows( ki ).length === 5
		&& textOf( all( arows( ki )[4], 'b' )[0] ) === 'Neu Person' && textOf( all( arows( ki )[4], '.badge' )[0] ) === 'eingeladen' && all( ki.main, '#invite' ).length === 0 && ki.toast.textContent === 'Das Konto ist angelegt, die Einladung ist unterwegs.' && ki.toast.classList.contains( 'bad' ) === false );
	click( ki, all( ki.main, '#invite-open' )[0] );
	type( all( all( ki.main, '#invite' )[0], 'input' )[0], 'unsent@example.org', false );
	submit( ki, all( ki.main, '#invite' )[0] );
	await settle();
	check( '...an account that was made and could not be mailed says that, and not that the invitation is on its way', ki.toast.textContent === 'Das Konto ist angelegt, aber die Einladung konnte nicht gesendet werden. Sende den Link über das Menü des Kontos noch einmal.' && arows( ki ).length === 6 );
	click( ki, all( ki.main, '#invite-open' )[0] );
	const taken = all( ki.main, '#invite' )[0];
	type( all( taken, 'input' )[0], 'taken@example.org', false );
	submit( ki, taken );
	await settle();
	check( '...a refusal is said beside the field it names, and the form stays with what was typed: the address in the red, the sentence under it, the cursor in it', all( ki.main, '#invite' )[0] === taken && all( taken, 'input' )[0].value === 'taken@example.org' && all( taken, 'input' )[0].classList.contains( 'err' ) === true
		&& all( taken, 'input' )[0].getAttribute( 'aria-invalid' ) === 'true' && textOf( all( taken, '.state-note' )[0] ) === 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.' && all( taken, 'input' )[0].focused === true && arows( ki ).length === 6 );
	type( all( taken, 'input' )[0], 'other@example.org', false );
	check( '...typing again takes the mark and the sentence away', all( taken, 'input' )[0].classList.contains( 'err' ) === false && textOf( all( taken, '.state-note' )[0] ) === '' );
	click( ki, all( taken, '#invite-cancel' )[0] );
	check( '...Abbrechen closes the form', all( ki.main, '#invite' ).length === 0 );

	// link an account that exists
	let linked = null;
	const kk = await kopen( '#kunde-1', {
		'crm-clients/unlinked' : function() { return [ 200, { accounts : [ { id : 'ffffffffffff', email : 'frei@example.org', name : 'Frei Konto', group : 'sonstige' }, { id : 'abababababab', email : 'nur@example.org', name : '', group : '' } ] } ] },
		'crm-clients/account-link' : function( data ) { linked = data; return [ 200, { account : { id : data.account, gone : false, state : 'invited', email : 'frei@example.org', name : 'Frei Konto', active : true, login : null } } ] },
	} );
	click( kk, all( kk.main, '#link-open' )[0] );
	await settle();
	check( 'Bestehendes verknüpfen asks crm-clients/unlinked and opens a dialog with the sentence of what linking does and a select of the accounts by name and address - the address alone where there is no name', JSON.stringify( kk.last( 'crm-clients/unlinked' ).data ) === '{}' && kk.dialogs.length === 1
		&& textOf( all( kk.dialogs[0], 'p' )[0] ) === 'Wähle ein Konto aus Mitglieder, das noch keinem Kunden gehört. Es kommt in die Gruppe dieses Kunden.' && all( all( kk.dialogs[0], 'select' )[0], 'option' ).map( textOf ).join( '|' ) === 'Frei Konto · frei@example.org|nur@example.org'
		&& textOf( all( kk.dialogs[0], 'h2' )[0] ) === 'Konto verknüpfen' && textOf( all( kk.dialogs[0], 'button' )[1] ) === 'Verknüpfen' );
	no( kk, 0 );
	await settle();
	check( '...Abbrechen posts nothing', linked === null && kk.all( 'crm-clients/account-link' ).length === 0 );
	click( kk, all( kk.main, '#link-open' )[0] );
	await settle();
	yes( kk, 1 );
	await settle();
	check( '...Verknüpfen posts crm-clients/account-link with the client and the account chosen - the first, as it stands - and the account is a row of the card, with the sentence that it is in the group of the client', JSON.stringify( linked ) === '{"id":"1","account":"ffffffffffff"}' && arows( kk ).length === 5 && kk.toast.textContent === 'Das Konto ist verknüpft und in der Gruppe des Kunden.' );
	const kheld = await kopen( '#kunde-1', { 'crm-clients/unlinked' : function() { return [ 200, { accounts : [ { id : 'ffffffffffff', email : 'frei@example.org', name : '', group : '' } ] } ] }, 'crm-clients/account-link' : function() { return [ 409, { error : 'x', code : 'crm_clients_held', field : 'account' } ] } } );
	click( kheld, all( kheld.main, '#link-open' )[0] );
	await settle();
	yes( kheld, 0 );
	await settle();
	check( '...an account another client holds meanwhile is refused with its sentence, and the rows are as they were', kheld.toast.textContent === 'Dieses Konto gehört schon einem Kunden.' && arows( kheld ).length === 4 );
	const knoneToLink = await kopen( '#kunde-1', { 'crm-clients/unlinked' : function() { return [ 200, { accounts : [] } ] } } );
	click( knoneToLink, all( knoneToLink.main, '#link-open' )[0] );
	await settle();
	check( '...none to link says so, and opens no dialog', knoneToLink.dialogs.length === 0 && knoneToLink.toast.textContent === 'Es gibt kein Konto, das noch keinem Kunden gehört.' );
	const kfail = await kopen( '#kunde-1', { 'crm-clients/unlinked' : function() { return [ 409, { error : 'x', code : 'crm_clients_members' } ] } } );
	click( kfail, all( kfail.main, '#link-open' )[0] );
	await settle();
	check( '...the accounts that cannot be asked for are a refusal with its sentence', kfail.dialogs.length === 0 && kfail.toast.textContent === 'Das Feature Mitglieder ist nicht aktiv. Schalte es im Panel Features ein.' );

	// a client that is off, Members off
	const koff = await kopen( '#kunde-4', { 'crm-clients/get' : function() { return [ 200, { client : kclients[3], accounts : kaccounts } ] } } );
	check( 'the card of a client that is off says so once - in a note at the top and in the box of the accounts - and offers no invitation, no link and no new document; the badge is the grey one', all( koff.main, '.note' ).length === 1 && textOf( all( koff.main, '.note' )[0] ) === 'Der Kunde ist deaktiviert: Er bekommt keinen neuen Beleg, und seine Konten sind aus.'
		&& textOf( accountsCard( koff ) ).indexOf( 'Der Kunde ist deaktiviert: Konten lassen sich weder einladen noch verknüpfen noch einschalten.' ) !== -1 && all( accountsCard( koff ), '#invite-open' ).length === 0 && all( accountsCard( koff ), '#link-open' ).length === 0
		&& kmenu( koff, 'Neuer Beleg ▾' ) === undefined && textOf( all( koff.main, '.head .badge' )[0] ) === 'inaktiv' && all( koff.main, '.head .badge' )[0].classList.contains( 'b-draft' ) === true );
	check( '...its accounts offer no link and no switch-on - an account that is off stays off - but the rename and the delete; and the menu of the client has the switch-on', labels( arows( koff )[0] ) === 'Name ändern|Sperren|Löschen'
		&& labels( arows( koff )[2] ) === 'Name ändern|Löschen' && all( kmenu( koff, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Aktivieren' );
	const kmembers = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : kclient, accounts : [ { id : 'aaaaaaaaaaaa', gone : true, state : 'members-off' }, { id : 'bbbbbbbbbbbb', gone : true, state : 'members-off' } ] } ] } } );
	check( 'with Members off the card says that in one sentence instead of rows that would all read "gelöscht", and offers nothing to do', arows( kmembers ).length === 0 && textOf( accountsCard( kmembers ) ).indexOf( 'Das Feature Mitglieder ist nicht aktiv. Die Konten dieses Kunden lassen sich erst wieder verwalten, wenn Du es im Panel Features einschaltest.' ) !== -1
		&& all( accountsCard( kmembers ), 'button' ).length === 0 );

	// the Stammdaten, edited
	let savedClient = [];
	const ke = await kopen( '#kunde-1', { 'crm-clients/save' : function( data ) {
		savedClient.push( data );
		if( data.email === 'kaputt' )
			return [ 400, { error : 'x', code : 'crm_clients_email', field : 'email' } ];
		return [ 200, { client : Object.assign( {}, kclient, data, { id : 1, documents : { total : 2, released : 1 } } ) } ];
	} } );
	check( 'the menu of the client has Stammdaten bearbeiten, and the form is not there before', all( kmenu( ke, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Deaktivieren' && all( ke.main, '#client-save' ).length === 0 );
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	const mform = all( ke.main, '#master' )[0];
	check( 'Stammdaten bearbeiten draws a form in the place of the facts: the nine fields with their words, filled, the mail a field of mail, the phone asking a phone keyboard, the country two characters long', all( mform, 'input' ).map( function( i ) { return i.id.slice( 2 ) } ).join() === 'company,name,street,zip,city,country,email,phone,vat_id'
		&& all( mform, 'label' ).map( textOf ).join( '|' ) === 'Firma|Name|Straße und Nummer|PLZ|Ort|Land (Kürzel)|E-Mail|Telefon|USt-IdNr.' && all( mform, 'input' ).map( function( i ) { return i.value } ).join( '|' ) === 'Nordlicht Records GmbH|Lena Brandt|Hafenstraße 12|20457|Hamburg|DE|lena@nordlicht.example|+49 40 123456|DE123456789'
		&& all( mform, '#f-email' )[0].type === 'email' && all( mform, '#f-phone' )[0].getAttribute( 'inputmode' ) === 'tel' && all( mform, '#f-country' )[0].getAttribute( 'maxlength' ) === '2' && ke.app.state.draft.dirty === false );
	type( all( mform, '#f-city' )[0], 'Bremen', false );
	check( '...what is typed is unsaved input: the guard of the page knows it', ke.app.state.draft.dirty === true && ke.app.state.draft.form === true );
	const guardClick = click( ke, all( ke.main, '.crumb a' )[0] );
	check( '...a link away asks first, in the words of a form - not of a draft - and the answer Verwerfen goes on', guardClick === true && ke.dialogs.length === 1 && textOf( all( ke.dialogs[0], 'p' )[0] ) === 'Die Seite enthält Änderungen, die noch nicht gespeichert sind.' && ke.location.hash === '#kunde-1' );
	yes( ke, 0 );
	await settle();
	check( '...Verwerfen goes on to the list, and the guard holds nothing any more', ke.location.hash === '#kunden' && ke.app.state.draft === null );
	ke.location.hash = '#kunde-1';
	await settle();
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	type( all( all( ke.main, '#master' )[0], '#f-email' )[0], 'kaputt', false );
	submit( ke, all( ke.main, '#master' )[0] );
	await settle();
	const refusedForm = all( ke.main, '#master' )[0];
	check( 'a save that is refused says the sentence beside the field the answer names: the mail is marked, the sentence stands under it and the cursor is in it, in the toast as well; what was typed stays', all( refusedForm, '#f-email' )[0].classList.contains( 'err' ) === true && all( refusedForm, '#f-email' )[0].focused === true
		&& textOf( all( refusedForm, '.state-note' ).find( function( n ) { return textOf( n ) !== '' } ) ) === 'Die E-Mail-Adresse ist nicht gültig.' && ke.toast.textContent === 'Die E-Mail-Adresse ist nicht gültig.' && all( refusedForm, '#f-email' )[0].value === 'kaputt' );
	type( all( refusedForm, '#f-email' )[0], 'lena@neu.example', false );
	type( all( refusedForm, '#f-city' )[0], 'Bremen', false );
	submit( ke, refusedForm );
	await settle();
	const lastSave = savedClient[savedClient.length - 1];
	check( 'a save posts crm-clients/save with the id as a string and every field of the client - the notes among them, which the model would empty if they were left out -, as typed', Object.keys( lastSave ).sort().join() === 'city,company,country,email,id,name,notes,phone,street,vat_id,zip' && lastSave.id === '1' && lastSave.city === 'Bremen' && lastSave.email === 'lena@neu.example' && lastSave.notes === 'Zahlt pünktlich.' && lastSave.company === 'Nordlicht Records GmbH' );
	check( '...the card is drawn again from the client the server answers - the facts, no form -, the toast says it, and the guard holds nothing', all( ke.main, '#client-save' ).length === 0 && all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[2] === 'lena@neu.example' && all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[4].indexOf( 'Bremen' ) !== -1 && ke.toast.textContent === 'Der Kunde ist gespeichert.' && ke.app.state.draft === null );
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	type( all( all( ke.main, '#master' )[0], '#f-name' )[0], 'Anderer', false );
	click( ke, all( ke.main, '#client-cancel' )[0] );
	check( '...Abbrechen puts the facts back as they were and drops the guard; no second form is made by a second press of the menu item', all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[1] === 'Lena Brandt' && ke.app.state.draft === null && ( click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) ), click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) ), all( ke.main, '#client-save' ).length === 1 ) );

	// a tab is the body under the strip: what is open above it stays open
	const ktab = await kopen( '#kunde-1' );
	click( ktab, kitem( kmenu( ktab, '⋯' ), 'Stammdaten bearbeiten' ) );
	const openForm = all( ktab.main, '#master' )[0];
	type( all( openForm, '#f-city' )[0], 'Lübeck', false );
	click( ktab, all( ktab.main, '.tabs button' )[2] );
	click( ktab, all( ktab.main, '.tabs button' )[0] );
	check( 'a tab changes the body under the strip and nothing above it: the form of the Stammdaten that is open is the same form, with what was typed, and the guard still knows it', all( ktab.main, '#master' )[0] === openForm && all( openForm, '#f-city' )[0].value === 'Lübeck' && ktab.app.state.draft.dirty === true
		&& all( ktab.main, '.tabs button' ).map( function( b ) { return b.classList.contains( 'on' ) } ).join() === 'true,false,false' && all( ktab.main, '.tabs button' ).map( function( b ) { return b.getAttribute( 'aria-selected' ) } ).join() === 'true,false,false' );
	click( ktab, kitem( kmenu( ktab, '⋯' ), 'Stammdaten bearbeiten' ) );
	check( '...and the menu item that opens the form, pressed again, does not make a new one: what was typed is still there', all( ktab.main, '#master' )[0] === openForm && all( openForm, '#f-city' )[0].value === 'Lübeck' && all( ktab.main, '#client-save' ).length === 1 );
	const kopenInvite = await kopen( '#kunde-1', { 'crm-clients/account-name' : function() { return [ 200, { account : Object.assign( {}, kaccounts[0], { name : 'Neu' } ) } ] } } );
	click( kopenInvite, all( kopenInvite.main, '#invite-open' )[0] );
	const halfTyped = all( kopenInvite.main, '#invite' )[0];
	type( all( halfTyped, 'input' )[0], 'halb@example.org', false );
	click( kopenInvite, kitem( arows( kopenInvite )[0], 'Name ändern' ) );
	yes( kopenInvite, 0 );
	await settle();
	check( '...and so is an invitation that is half typed when a step is made on another account: the form is the one that was opened, with its text', all( kopenInvite.main, '#invite' )[0] === halfTyped && all( halfTyped, 'input' )[0].value === 'halb@example.org' && textOf( all( arows( kopenInvite )[0], 'b' )[0] ) === 'Neu' );

	// off and on
	let activeAnswer = null;
	const kz = await kopen( '#kunde-1', { 'crm-clients/active' : function( data ) {
		activeAnswer = data;
		return data.active === false ? [ 200, { client : Object.assign( {}, kclient, { active : false } ), accounts : kaccounts.map( function( a ) { return a.gone ? a : Object.assign( {}, a, { active : false, state : 'off' } ) } ), switched : 2 } ] : [ 200, { client : kclient, accounts : kaccounts.map( function( a ) { return a.gone ? a : Object.assign( {}, a, { active : false, state : 'off' } ) } ), switched : 0 } ];
	} } );
	const countsBefore = kz.all( 'crm-documents/overview' ).length;
	click( kz, kitem( kmenu( kz, '⋯' ), 'Deaktivieren' ) );
	await settle();
	check( 'Deaktivieren posts crm-clients/active with the id and the value it wants, and says how many accounts went off with it, and that they stay off when the client is on again', JSON.stringify( activeAnswer ) === '{"id":"1","active":false}' && kz.toast.textContent === 'Der Kunde ist deaktiviert. Ausgeschaltete Konten: 2. Schaltest Du ihn wieder ein, bleiben die Konten aus - schalte sie einzeln ein.' );
	check( '...the card is drawn again from the answer: the badge, the note, the accounts as the server has them - gesperrt -, no way to invite; and the rail counts the clients again', textOf( all( kz.main, '.head .badge' )[0] ) === 'inaktiv' && all( kz.main, '.note' ).length === 1 && arows( kz ).slice( 0, 3 ).every( function( row ) { return textOf( all( row, '.badge' )[0] ) === 'gesperrt' } )
		&& all( kz.main, '#invite-open' ).length === 0 && kz.all( 'crm-documents/overview' ).length === countsBefore + 1 );
	click( kz, kitem( kmenu( kz, '⋯' ), 'Aktivieren' ) );
	await settle();
	check( '...Aktivieren posts active true, and says that the accounts stay off', JSON.stringify( activeAnswer ) === '{"id":"1","active":true}' && kz.toast.textContent === 'Der Kunde ist aktiviert. Seine Konten bleiben aus - schalte sie einzeln ein.' && textOf( all( kz.main, '.head .badge' )[0] ) === 'aktiv' && all( kz.main, '.note' ).length === 0 );

	// the tabs
	const kp = await kopen( '#kunde-1' );
	click( kp, all( kp.main, '.tabs button' )[1] );
	await settle();
	const portalText = function( w ) { return all( w.main, '#client-portal' )[0] };
	check( 'the tab Portal asks crm-clients/portal for the client, and shows the documents the client sees as links to them - the title for a document that has one, the number else - with their kind and day', JSON.stringify( kp.last( 'crm-clients/portal' ).data ) === '{"id":"1"}' && all( portalText( kp ), '.list-mini' )[0].children.length === 2
		&& all( all( portalText( kp ), '.list-mini' )[0], 'a' ).map( function( a ) { return a.getAttribute( 'href' ) + ':' + textOf( a ) } ).join( '|' ) === '#beleg-11:R-2026-0007|#beleg-17:Vertrag Album 2027' && all( kp.main, '.tabs button' )[1].classList.contains( 'on' ) === true );
	check( '...the folders of Files with how many files the client may see in each and whether it uploads - a name that is markup is text -, and what Review counts', all( all( portalText( kp ), '.list-mini' )[1], 'li' ).map( textOf ).join( '|' ) === 'Ordner „Nordlicht <Album>“ · 14 Dateien · Upload erlaubt|Ordner „Cover“ · 1 Datei · kein Upload'
		&& findAll( portalText( kp ), function( node ) { return node.tagName === 'ALBUM' } ).length === 0 && textOf( portalText( kp ) ).indexOf( 'Review: 3 zum Kommentieren, 7 Kommentare' ) !== -1 && textOf( portalText( kp ) ).indexOf( 'Gezählt sind die Dateien, die der Kunde sehen darf' ) !== -1 );
	click( kp, all( kp.main, '.tabs button' )[2] );
	click( kp, all( kp.main, '.tabs button' )[1] );
	await settle();
	check( '...it is asked for once: the tab drawn again takes what it has', kp.all( 'crm-clients/portal' ).length === 1 && textOf( portalText( kp ) ).indexOf( 'Review: 3 zum Kommentieren' ) !== -1 );
	const koffp = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, { documents : [], features : { files : false, review : false }, folders : [], review : { media : 0, comments : 0 } } ] } } );
	check( 'with Files off the tab says that the portal shows none, with Review off it says nothing of comments, and with no document it says that nothing is released', textOf( portalText( koffp ) ).indexOf( 'Das Feature Dateien ist nicht aktiv, das Portal zeigt keine Dateien.' ) !== -1 && textOf( portalText( koffp ) ).indexOf( 'Review' ) === -1 && textOf( portalText( koffp ) ).indexOf( 'Nichts freigegeben.' ) !== -1 );
	const kfolderless = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, { documents : [], features : { files : true, review : true }, folders : [], review : { media : 0, comments : 0 } } ] } } );
	check( '...a client with no folder and nothing to comment on says that', textOf( portalText( kfolderless ) ).indexOf( 'Der Kunde hat noch keinen Ordner.' ) !== -1 && textOf( portalText( kfolderless ) ).indexOf( 'Review: nichts zum Kommentieren' ) !== -1 );
	check( 'the address of a tab opens it: #kunde-1-portal draws the Portal tab at once', all( koffp.main, '.tabs button' )[1].classList.contains( 'on' ) === true && koffp.last( 'crm-clients/portal' ) !== undefined );

	let notesSaved = null;
	const kt = await kopen( '#kunde-1-notizen', { 'crm-clients/save' : function( data ) { notesSaved = data; return [ 200, { client : Object.assign( {}, kclient, data ) } ] } } );
	check( 'the tab Notizen is a field with the notes of the client and a button', all( kt.main, '#notes' )[0].value === 'Zahlt pünktlich.' && textOf( all( kt.main, '#notes-save' )[0] ) === 'Speichern' );
	all( kt.main, '#notes' )[0].value = 'Seit Oktober nur noch per Überweisung.';
	click( kt, all( kt.main, '#notes-save' )[0] );
	await settle();
	check( '...Speichern posts the whole client with the notes as typed - the other fields as they are, for the model empties what is not posted - and says so', Object.keys( notesSaved ).sort().join() === 'city,company,country,email,id,name,notes,phone,street,vat_id,zip' && notesSaved.notes === 'Seit Oktober nur noch per Überweisung.' && notesSaved.company === 'Nordlicht Records GmbH' && notesSaved.id === '1' && kt.toast.textContent === 'Notizen gespeichert.' );

	// "einladen": the card of the client, the tab Portal, the form open and its field focused
	const kinv = await kopen( '#kunde-3-einladen', { 'crm-clients/get' : function() { return [ 200, { client : kclients[2], accounts : [] } ] } } );
	const timerCount = kinv.timers.length;
	kinv.timers.slice().reverse().forEach( function( timer ) { if( timer.cleared !== true ) timer.fn() } );
	check( 'the address #kunde-3-einladen - what "einladen" in the Übersicht leads to - is the card of that client at the tab Portal with the invitation open, the address field the one with the cursor in it', all( kinv.main, '.tabs button' )[1].classList.contains( 'on' ) === true && all( kinv.main, '#invite' ).length === 1 && all( kinv.main, '#f-invite-email' )[0].focused === true
		&& JSON.stringify( kinv.last( 'crm-clients/get' ).data ) === '{"id":"3"}' && timerCount >= 1 );
	check( '...a client without accounts says so above the buttons', textOf( accountsCard( kinv ) ).indexOf( 'Noch kein Konto. Der Kunde sieht das Portal erst mit einem.' ) !== -1 );

	// a new document for this client
	const knew = await kopen( '#kunde-1' );
	check( 'the card has a menu for a new document for this client: Rechnung, Angebot, Datenschutz, Information - no contract, which follows its offer', all( kmenu( knew, 'Neuer Beleg ▾' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Rechnung|Angebot|Datenschutz|Information' );
	click( knew, kitem( kmenu( knew, 'Neuer Beleg ▾' ), 'Angebot' ) );
	check( '...each opens the editor with the client in the address', knew.location.hash === '#neu-offer-k1' );
	const kedit = await kopen( '#neu-invoice-k1', { 'crm-documents/templates' : function() { return [ 200, { templates : templates, offers : offers } ] } } );
	check( '...and the editor has that client chosen; one that is not among the active clients is not taken', all( kedit.main, '#f-client_id' )[0].value === '1' && ( await ( async function() {
		const other = await kopen( '#neu-invoice-k99', { 'crm-documents/templates' : function() { return [ 200, { templates : templates, offers : offers } ] } } );
		return all( other.main, '#f-client_id' )[0].value === '';
	} )() ) === true );

	// a late answer never draws over the screen that came after it
	let kRelease;
	const klate = world( { hash : '#kunde-1', answers : kanswers( { 'crm-clients/get' : function() { return new Promise( function( resolve ) { kRelease = function() { resolve( [ 200, { client : kclient, accounts : kaccounts } ] ) } } ) } } ) } );
	klate.boot();
	await settle();
	klate.location.hash = '#kunden';
	await settle();
	kRelease();
	await settle();
	check( 'the answer of the card that arrives after the screen was left draws nothing: the list stays', textOf( all( klate.main, 'h1' )[0] ) === 'Kunden' && all( klate.main, '#accounts' ).length === 0 );
	const lateAccount = await kopen( '#kunde-1', { 'crm-clients/account-name' : function() { return new Promise( function( resolve ) { kRelease = function() { resolve( [ 200, { account : Object.assign( {}, kaccounts[0], { name : 'Spät' } ) } ] ) } } ) } } );
	click( lateAccount, kitem( arows( lateAccount )[0], 'Name ändern' ) );
	yes( lateAccount, 0 );
	await settle();
	lateAccount.location.hash = '#kunden';
	await settle();
	kRelease();
	await settle();
	check( '...and neither does the answer of an account that arrives after the card was left', textOf( all( lateAccount.main, 'h1' )[0] ) === 'Kunden' && all( lateAccount.main, '.acc' ).length === 0 );
	const kmissing = await kopen( '#kunde-99', { 'crm-clients/get' : function() { return [ 404, { error : 'x', code : 'crm_clients_unknown', field : 'id' } ] } } );
	check( 'a client nobody has is told in the sentence of its code, with the way to try again', textOf( kmissing.main ).indexOf( 'Diesen Kunden gibt es nicht.' ) !== -1 && all( kmissing.main, 'button' ).map( textOf ).indexOf( 'Noch einmal versuchen' ) !== -1 );

	// --- Neuer Kunde
	let created = null;
	const knewc = await kopen( '#kunde-neu', { 'crm-clients/save' : function( data ) {
		created = data;
		if( data.company === '' && data.name === '' )
			return [ 400, { error : 'x', code : 'crm_clients_empty', field : 'company' } ];
		const made = Object.assign( {}, kclient, { id : 6, number : 'K-0006', company : data.company, name : data.name } );
		if( data.account_email === '' )
			return [ 200, { client : made } ];
		if( data.account_email === 'taken@example.org' )
			return [ 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_taken', params : [], field : 'email' } } } ];
		if( data.account_email === 'long@example.org' )
			return [ 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_long', params : [ 120 ], field : 'name' } } } ];
		return [ 200, { client : made, first : { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.account_email, name : data.account_name, active : true, login : null }, mailed : data.account_email !== 'nUnsent@example.org', refused : null } } ];
	} } );
	const nform = all( knewc.main, '#new-client-form' )[0];
	const nfield = function( name ) { return all( nform, '#f-'+ name )[0] };
	check( 'Neuer Kunde draws the form without asking for anything: the Stammdaten with their words and the first account, the country DE and a list of countries to choose from, the rail marks Kunden', nform !== undefined && knewc.all( 'crm-clients/save' ).length === 0
		&& all( nform, 'input' ).map( function( i ) { return i.id.slice( 2 ) } ).join() === 'company,name,street,zip,city,country,email,phone,vat_id,account_email,account_name' && nfield( 'country' ).value === 'DE' && all( nform, 'option' ).map( function( o ) { return o.value } ).join() === 'DE,AT,CH'
		&& nfield( 'account_email' ).type === 'email' && all( nform, 'h3' ).map( textOf ).join( '|' ) === 'Stammdaten|Erstes Portal-Konto' && all( knewc.nav, '[data-r]' ).find( function( a ) { return a.getAttribute( 'data-r' ) === 'kunden' } ).classList.contains( 'on' ) === true );
	check( '...the fields that may stay empty say so, and the button says Anlegen until an address for the first account is typed, then Anlegen und einladen - and Anlegen again when it is cleared', all( nform, '.state-note' ).map( textOf ).filter( function( t ) { return t === 'optional' } ).length === 3 && textOf( all( nform, '#client-create' )[0] ) === 'Anlegen'
		&& ( type( nfield( 'account_email' ), 'nina@example.org', false ), textOf( all( nform, '#client-create' )[0] ) === 'Anlegen und einladen' ) && ( type( nfield( 'account_email' ), '  ', false ), textOf( all( nform, '#client-create' )[0] ) === 'Anlegen' ) );
	check( '...what is typed is unsaved input, and a link away asks first', knewc.app.state.draft.dirty === true && click( knewc, all( knewc.main, '#client-abort' )[0] ) === true && knewc.dialogs.length === 1 );
	no( knewc, 0 );
	await settle();
	type( nfield( 'account_email' ), '', false );
	submit( knewc, nform );
	await settle();
	check( 'a client without a company and a name is refused beside the field the answer names - the company - with its sentence, the cursor in it; the form stays, nothing opens', nfield( 'company' ).classList.contains( 'err' ) === true && nfield( 'company' ).focused === true && textOf( all( nform, '.state-note' ).find( function( n ) { return textOf( n ) !== '' && textOf( n ) !== 'optional' } ) ) === 'Ein Kunde braucht eine Firma oder einen Namen.'
		&& knewc.location.hash === '#kunde-neu' && knewc.toast.classList.contains( 'bad' ) === true );
	type( nfield( 'company' ), 'Neu GmbH', false );
	type( nfield( 'name' ), 'Nina Neu', false );
	type( nfield( 'city' ), 'Bonn', false );
	const refreshBefore = knewc.all( 'crm-documents/overview' ).length;
	submit( knewc, nform );
	await settle();
	check( 'without an address the client is made alone: crm-clients/save with every field and the two of the account empty, no id, no notes - and the card of the new client opens, with the sentence that it is made, the guard dropped and the rail counted again', Object.keys( created ).sort().join() === 'account_email,account_name,city,company,country,email,name,phone,street,vat_id,zip' && created.company === 'Neu GmbH' && created.account_email === '' && created.country === 'DE' && created.city === 'Bonn'
		&& knewc.toast.textContent === 'Kunde K-0006 angelegt.' && knewc.location.hash === '#kunde-6' && knewc.app.state.draft === null && knewc.all( 'crm-documents/overview' ).length >= refreshBefore + 1 );

	const nsubmit = async function( fields ) {
		const w = await kopen( '#kunde-neu', { 'crm-clients/save' : knewc.answers['crm-clients/save'] } );
		const form = all( w.main, '#new-client-form' )[0];
		Object.keys( fields ).forEach( function( name ) { type( all( form, '#f-'+ name )[0], fields[name], false ) } );
		submit( w, form );
		await settle();
		return w;
	};
	const nWith = await nsubmit( { company : 'Konto GmbH', account_email : ' neu@example.org ', account_name : 'Neu Person' } );
	check( 'with an address the account is posted with the client, as typed, and the sentence says that the invitation is on its way', created.account_email === ' neu@example.org ' && created.account_name === 'Neu Person' && nWith.toast.textContent === 'Kunde K-0006 angelegt, die Einladung ist unterwegs.' && nWith.toast.classList.contains( 'bad' ) === false && nWith.location.hash === '#kunde-6' );
	const nUnsent = await nsubmit( { company : 'Konto GmbH', account_email : 'nUnsent@example.org' } );
	check( '...an account that was made and not mailed says that, and not that the invitation is on its way', nUnsent.toast.textContent === 'Kunde K-0006 und sein Konto sind angelegt, aber die Einladung konnte nicht gesendet werden. Sende den Link über das Menü des Kontos noch einmal.' && nUnsent.toast.classList.contains( 'bad' ) === true );
	const nRefused = await nsubmit( { company : 'Konto GmbH', account_email : 'taken@example.org' } );
	check( '...a client made with an account that was refused is made: its card opens, and the sentence says that the client is made and why the account is not', nRefused.location.hash === '#kunde-6' && nRefused.toast.textContent === 'Kunde K-0006 ist angelegt, das Konto nicht: Zu dieser E-Mail-Adresse gibt es schon ein Konto.' );
	const nLong = await nsubmit( { company : 'Konto GmbH', account_email : 'long@example.org' } );
	check( '...the parameters of the code are put into its sentence', nLong.toast.textContent === 'Kunde K-0006 ist angelegt, das Konto nicht: Der Wert darf höchstens 120 Zeichen haben.' );

	// --- Positionen
	const pitems = [
		{ id : 'mixing', sort : 1, price : 9000, vat : 1900, active : true, words : { de_DE : { title : 'Mixing', description : 'Mischung eines Songs\nbis 48 Spuren', unit : 'Std.' }, en_US : { title : 'Mixing', description : 'Mixing of one song', unit : 'h' } } },
		{ id : 'recording', sort : 2, price : 20000, vat : 700, active : true, words : { de_DE : { title : '', description : '', unit : '' }, en_US : { title : 'Recording day', description : '', unit : 'day' } } },
		{ id : 'alt', sort : 3, price : 100, vat : 0, active : false, words : { de_DE : { title : '<b>Alt</b>', description : '', unit : 'Std.' }, en_US : { title : '', description : '', unit : 'Std.' } } },
	];
	const popen = async function( body, answerSettings ) {
		return kopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 200, Object.assign( { missing : false, locales : [ 'de_DE', 'en_US' ], native : 'de_DE', items : pitems, settings : answerSettings || settings }, body || {} ) ] } } );
	};
	const kp1 = await popen();
	const ptable = all( kp1.main, '#items' )[0];
	check( 'Positionen asks crm-documents/positionen and nothing else, and draws a read-only table: a column of words for each language of the site, then the unit, the net price, the rate and whether it is active', JSON.stringify( kp1.last( 'crm-documents/positionen' ).data ) === '{}' && all( ptable, 'th' ).map( textOf ).join( '|' ) === 'Titel (de)|Titel (en)|Einheit|Netto|USt|Aktiv'
		&& all( ptable, 'input' ).length === 0 && all( ptable, 'tbody tr' ).length === 3 && textOf( all( kp1.main, 'h1' )[0] ) === 'Positionen' );
	const prow = function( index ) { return Array.from( all( ptable, 'tbody tr' )[index].children ) };
	check( '...a row has the title and the description of each language - a language with no text is empty - in the order of the sort the server gives, the unit once for each language that has one, the price and the rate as the line copies them, and ja or nein', textOf( prow( 0 )[0] ) === 'MixingMischung eines Songs · bis 48 Spuren' && textOf( prow( 0 )[1] ) === 'MixingMixing of one song' && textOf( prow( 0 )[2] ) === 'Std. / h'
		&& textOf( prow( 0 )[3] ) === '90,00'+ NBSP+ '€' && textOf( prow( 0 )[4] ) === '19 %' && textOf( prow( 0 )[5] ) === 'ja' && textOf( prow( 1 )[0] ) === '' && textOf( prow( 1 )[1] ) === 'Recording day' && textOf( prow( 1 )[2] ) === 'day' && textOf( prow( 1 )[4] ) === '7 %'
		&& textOf( prow( 2 )[5] ) === 'nein' && textOf( prow( 2 )[2] ) === 'Std.' );
	check( '...a title that is markup is text', textOf( prow( 2 )[0] ) === '<b>Alt</b>' && findAll( ptable, function( node ) { return node.tagName === 'B' } ).length === 0 );
	check( '...the primary action is the link Im Panel Elemente bearbeiten, to the Elements panel at the type, in a tab of its own', textOf( all( kp1.main, '#items-edit' )[0] ) === 'Im Panel Elemente bearbeiten' && all( kp1.main, '#items-edit' )[0].getAttribute( 'href' ) === '/_admin/#elements/positionen' && all( kp1.main, '#items-edit' )[0].classList.contains( 'primary' ) === true
		&& all( kp1.main, '#items-edit' )[0].getAttribute( 'target' ) === '_blank' && textOf( all( kp1.main, '.state-note' ).pop() ).indexOf( 'Nur zum Ansehen' ) === 0 );
	const kp2 = await popen( { locales : [ 'de_DE' ] }, smallBusiness );
	check( 'a site with one language has one column of words, and a small business has no rate to show: a stroke, as the line copies 0', all( all( kp2.main, '#items' )[0], 'th' ).map( textOf ).join( '|' ) === 'Titel (de)|Einheit|Netto|USt|Aktiv' && textOf( all( all( kp2.main, '#items' )[0], 'tbody tr' )[0].children[3] ) === '–' );
	const kp3 = await popen( { missing : true, items : [] } );
	check( 'where the element type is not there the screen says so and points to the settings, where the portal is set up - no table', all( kp3.main, '#items' ).length === 0 && textOf( all( kp3.main, '.note' )[0] ).indexOf( 'Den Elementtyp Positionen gibt es noch nicht.' ) === 0 && all( all( kp3.main, '.note' )[0], 'a' )[0].getAttribute( 'href' ) === '#einstellungen' );
	const kp4 = await popen( { items : [] } );
	check( 'a type with no item says so', textOf( all( all( kp4.main, '#items' )[0], 'tbody' )[0] ) === 'Noch keine Position. Lege sie im Panel Elemente an.' );
	const kp5 = await kopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 403, { error : 'x', code : 'x' } ] } } );
	check( 'a refusal says that the account may not, and offers to try again', textOf( kp5.main ).indexOf( 'Dafür fehlt Deinem Konto die Berechtigung.' ) !== -1 );

	// --- Einstellungen
	const esettings = { vat_mode : 'regular', vat_rates : '1900,700,550,0', payment_days : '14', offer_days : '30', currency : '€', number_offer : 'A-{YYYY}-{NNNN}', number_invoice : 'R-{YYYY}-{NNNN}', number_credit : 'G-{YYYY}-{NNNN}' };
	const eview = {
		settings : esettings, next : { offer : 'A-2026-0012', invoice : 'R-2026-0009', credit : 'G-2026-0002' }, pdf : true,
		templates : { offer : [ 'Angebot Standard' ], invoice : [ 'Rechnung Standard', 'Rechnung Englisch' ], contract : [ 'Dienstleistungsvertrag' ], privacy : [], information : [] },
		portal : { address : 'https://studio.example/portal', pages : [ { uri : '/portal', present : true }, { uri : '/portal/dokumente', present : false } ], members : [ { key : 'paths', label : 'Geschützte Pfade', value : '/portal', unit : '' }, { key : 'linkMinutes', label : 'Login-Link gültig für', value : '15', unit : 'minutes' }, { key : 'inviteDays', label : 'Einladung gültig für', value : '7', unit : 'days' }, { key : 'attempts', label : 'Anforderungen', value : '5', unit : 'per-hour' } ], mail_cap : 50 },
	};
	const eok = [ 'module', 'data', 'members', 'files', 'review', 'sign', 'pdf', 'portal' ].map( function( key ) { return { key : key, state : 'ok' } } );
	let posted = [];
	const eopen = async function( viewOver, items, over, hash ) {
		const w = await kopen( hash || '#einstellungen', Object.assign( {
			'crm-documents/settings-get' : function() { return [ 200, Object.assign( {}, eview, viewOver || {} ) ] },
			'crm/status' : function() { return [ 200, { items : items || eok } ] },
			'crm-documents/settings-save' : function( data ) { posted.push( data ); return [ 200, { settings : Object.assign( {}, esettings, { vat_rates : '1900,700,550,0' }, { currency : data.currency, vat_mode : data.vat_mode } ) } ] },
		}, over || {} ) );
		return w;
	};
	const es = await eopen();
	const efield = function( w, name ) { return all( w.main, '#f-'+ name )[0] };
	check( 'Einstellungen asks for the settings and for the status of the module, and has a field for each setting: the mode a select of the two modes, the rates written in percent - 19; 7; 5,5; 0 - and not as the hundredths they are stored as, the days, the currency, the three formats',
		es.last( 'crm-documents/settings-get' ) !== undefined && es.last( 'crm/status' ) !== undefined && efield( es, 'vat_mode' ).value === 'regular' && all( efield( es, 'vat_mode' ), 'option' ).map( textOf ).join( '|' ) === 'Kleinunternehmer (§ 19 UStG)|Regelbesteuerung' && efield( es, 'vat_rates_percent' ).value === '19; 7; 5,5; 0'
		&& efield( es, 'payment_days' ).value === '14' && efield( es, 'offer_days' ).value === '30' && efield( es, 'currency' ).value === '€' && efield( es, 'number_offer' ).value === 'A-{YYYY}-{NNNN}' && efield( es, 'number_invoice' ).value === 'R-{YYYY}-{NNNN}' && efield( es, 'number_credit' ).value === 'G-{YYYY}-{NNNN}' );
	check( '...beside the formats the next number of each sequence, as the server reads it; the hint says what a change of the mode does and what a change of a format does', textOf( all( es.main, '#next-numbers' )[0] ) === 'A-2026-0012 · R-2026-0009 · G-2026-0002' && textOf( es.main ).indexOf( 'Gilt für neue Entwürfe.' ) !== -1 && textOf( es.main ).indexOf( 'das verantwortest Du' ) !== -1 );
	check( '...the templates of the feature PDF by tag, read only, with the way to the panel of Pdf; with no template the word keine', all( all( es.main, '#templates' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Angebot|Rechnung|Vertrag|Datenschutz|Information' && all( all( es.main, '#templates' )[0], 'dd' ).map( textOf ).join( '|' ) === 'Angebot Standard|Rechnung Standard · Rechnung Englisch|Dienstleistungsvertrag|keine|keine'
		&& all( all( es.main, '#templates' )[0], 'a' )[0].getAttribute( 'href' ) === '/_admin/#pdf' && all( all( es.main, '#templates' )[0], 'input' ).length === 0 );
	check( '...the portal, read only: the address, the pages with the ones the site has no route for marked, the settings of Members and the cap of mails of the kernel', all( all( es.main, '#portal' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Adresse|Seiten|Mitglieder|Mail-Deckel' && all( all( es.main, '#portal' )[0], 'dd' ).map( textOf ).join( '|' ) === 'https://studio.example/portal|/portal · /portal/dokumente (fehlt)|Geschützte Pfade: /portalLogin-Link gültig für: 15 MinutenEinladung gültig für: 7 TageAnforderungen: 5 je Stunde|50 Aufrufe je Stunde (Config)'
		&& all( all( es.main, '#portal' )[0], 'input' ).length === 0 );
	check( '...the status of the module says that all is well and the button that sets the portal up is hidden; the access block names the permission and links the panel of the users', textOf( all( es.main, '#status-ok' )[0] ) === 'Alles ist bereit: Das Portal ist eingerichtet und die Features laufen.' && all( es.main, '#portal-setup' )[0].hidden === true
		&& textOf( all( es.main, '#access' )[0] ).indexOf( 'Die Oberfläche braucht die Berechtigung /_admin/crm/manage.' ) !== -1 && all( all( es.main, '#access' )[0], 'a' )[0].getAttribute( 'href' ) === '/_admin/#users' );
	const ecapless = await eopen( { portal : Object.assign( {}, eview.portal, { address : '', mail_cap : 0, members : [] } ), pdf : false } );
	check( 'a site with no address says that no mail with a link goes out; a cap of 0 is no cap; a kernel with no key leaves the row out; Pdf off says that there are no templates', textOf( all( ecapless.main, '#portal' )[0] ).indexOf( 'Die Website hat keine Adresse: Ohne sie schickt das CRM keine Mail mit einem Link.' ) !== -1 && textOf( all( ecapless.main, '#portal' )[0] ).indexOf( 'kein Deckel (Config)' ) !== -1
		&& textOf( all( ecapless.main, '#templates' )[0] ).indexOf( 'Das Feature PDF ist nicht aktiv, ohne es gibt es keine Vorlagen.' ) !== -1 && ( await ( async function() {
			const none = await eopen( { portal : Object.assign( {}, eview.portal, { mail_cap : null } ) } );
			return all( all( none.main, '#portal' )[0], 'dt' ).map( textOf ).indexOf( 'Mail-Deckel' ) === -1;
		} )() ) === true );

	// the save
	type( efield( es, 'vat_rates_percent' ), '19,7,0', false );
	type( efield( es, 'payment_days' ), '21', false );
	check( 'what is typed is unsaved input, and a link away - the rail - asks first, in the words of a form', es.app.state.draft.dirty === true && click( es, all( es.nav, '[data-r]' )[0] ) === true && es.dialogs.length === 1 && textOf( all( es.dialogs[0], 'p' )[0] ) === 'Die Seite enthält Änderungen, die noch nicht gespeichert sind.' && es.location.hash === '#einstellungen' );
	const es2 = await eopen( {}, undefined, { 'crm-documents/settings-save' : function( data ) { posted.push( data ); return data.vat_rates_percent === '19,7,0' ? [ 400, { error : 'x', code : 'crm_documents_setting', field : 'vat_rates_percent' } ] : data.number_invoice === 'R/{NNNN}' ? [ 400, { error : 'x', code : 'crm_documents_numberformat', field : 'number_invoice' } ]
		: [ 200, { settings : Object.assign( {}, esettings, { vat_mode : data.vat_mode, currency : data.currency, vat_rates : '1900,700,550,0' } ) } ]; } } );
	type( efield( es2, 'vat_rates_percent' ), '19,7,0', false );
	type( efield( es2, 'payment_days' ), '21', false );
	submit( es2, all( es2.main, '#settings-form' )[0] );
	await settle();
	check( 'a save posts crm-documents/settings-save with the eight settings as text - the rates as typed, 19,7,0 too: the server reads them, the screen has no conversion - and a refusal is said beside the field it names, the rates', Object.keys( posted[posted.length - 1] ).sort().join() === 'currency,number_credit,number_invoice,number_offer,offer_days,payment_days,vat_mode,vat_rates_percent'
		&& posted[posted.length - 1].vat_rates_percent === '19,7,0' && posted[posted.length - 1].payment_days === '21' && efield( es2, 'vat_rates_percent' ).classList.contains( 'err' ) === true && efield( es2, 'vat_rates_percent' ).focused === true
		&& textOf( all( all( es2.main, '#settings-form' )[0], '.state-note' ).find( function( n ) { return textOf( n ).indexOf( 'Der Wert der Einstellung ist nicht gültig' ) === 0 } ) ).indexOf( 'Semikolon' ) !== -1 && efield( es2, 'payment_days' ).value === '21' );
	type( efield( es2, 'vat_rates_percent' ), '19; 7; 5,5; 0', false );
	type( efield( es2, 'number_invoice' ), 'R/{NNNN}', false );
	submit( es2, all( es2.main, '#settings-form' )[0] );
	await settle();
	check( '...a format that is refused is marked at its own field, with the sentence of its code', efield( es2, 'number_invoice' ).classList.contains( 'err' ) === true && efield( es2, 'vat_rates_percent' ).classList.contains( 'err' ) === false
		&& textOf( all( all( es2.main, '#settings-form' )[0], '.state-note' ).find( function( n ) { return textOf( n ).indexOf( 'Nummernformat' ) !== -1 || textOf( n ).indexOf( 'Format' ) !== -1 } ) ) !== '' );
	type( efield( es2, 'number_invoice' ), 'RE-{YYYY}-{NNN}', false );
	const readsBefore = es2.all( 'crm-documents/settings-get' ).length;
	submit( es2, all( es2.main, '#settings-form' )[0] );
	await settle();
	check( 'a save that goes through says so, asks for the settings again - the next numbers follow the formats - and the other screens work with what is saved: the rates are those of the percent text, the guard holds nothing', posted[posted.length - 1].vat_rates_percent === '19; 7; 5,5; 0' && es2.toast.textContent === 'Die Einstellungen sind gespeichert.'
		&& es2.all( 'crm-documents/settings-get' ).length === readsBefore + 1 && JSON.stringify( es2.app.state.settings.rates ) === '[1900,700,550,0]' && es2.app.state.draft !== null && es2.app.state.draft.dirty === false );

	// the status and the button
	const pitemsStatus = [ { key : 'module', state : 'ok' }, { key : 'data', state : 'unwritable' }, { key : 'sign', state : 'off' }, { key : 'pdf', state : 'missing' }, { key : 'portal', state : 'partial', lacks : [ 'routes', 'types' ] } ];
	let setups = 0;
	let statusItems = pitemsStatus;
	const est = await eopen( {}, undefined, {
		'crm/status' : function() { return [ 200, { items : statusItems } ] },
		'crm/setup' : function() { setups++; return [ 200, { added : { routes : [ 'a', 'b', 'c' ], templates : [ 'x' ], types : [ 'positionen.php' ], texts : 12, paths : false, start : false }, existed : { routes : [ 'd' ], templates : [ 'y', 'z' ], types : [], paths : true, start : true } } ] },
	} );
	check( 'a module that misses something says it line by line, in the words of the state - a portal that is partly there names what it lacks, in the words of the parts - and the button Portal einrichten is there', all( all( est.main, '#status-missing' )[0], 'li' ).map( textOf ).join( '|' ) === [
		'Das Verzeichnis data/ ist nicht beschreibbar, die Datenbank kann nicht angelegt werden.', 'Das Feature Unterschrift ist installiert, aber nicht aktiv. Schalte es im Panel Features ein.', 'Das Feature PDF ist nicht installiert.',
		'Das Portal ist nur zum Teil eingerichtet, es fehlt: Routen, der Elementtyp Positionen. Drück auf Portal einrichten, es ergänzt, was fehlt.' ].join( '|' ) && all( est.main, '#portal-setup' )[0].hidden === false && textOf( all( est.main, '#portal-setup' )[0] ) === 'Portal einrichten' );
	type( efield( est, 'currency' ), 'CHF', false );
	statusItems = eok;
	click( est, all( est.main, '#portal-setup' )[0] );
	check( 'the button posts crm/setup with nothing, and is off while the request is out', est.last( 'crm/setup' ) !== undefined && JSON.stringify( est.last( 'crm/setup' ).data ) === '{}' && all( est.main, '#portal-setup' )[0].disabled === true );
	await settle();
	check( '...the status is asked for again and drawn: all is well, the button is gone, and what the setup did stands in one sentence with a place for each of the five numbers - what a person typed in the form above stays where it is', setups === 1 && est.all( 'crm/status' ).length === 2 && all( est.main, '#status-ok' ).length === 1 && all( est.main, '#portal-setup' )[0].hidden === true
		&& textOf( all( est.main, '#setup-said' )[0] ) === 'Eingerichtet. Neu: Routen 3, Vorlagen 1, Elementtypen 1, Worte 12. Schon da: 3.' && efield( est, 'currency' ).value === 'CHF' );
	const emembers = await eopen( {}, pitemsStatus, { 'crm/setup' : function() { return [ 409, { error : 'x', code : 'crm_setup_members' } ] } } );
	click( emembers, all( emembers.main, '#portal-setup' )[0] );
	await settle();
	check( 'a setup that is refused says the sentence of its code, the button is back and the status is as it was', emembers.toast.textContent === 'Das Feature Mitglieder ist nicht aktiv. Schalte es im Panel Features ein.' && all( emembers.main, '#portal-setup' )[0].disabled === false && all( emembers.main, '#status-missing' ).length === 1 && emembers.all( 'crm/status' ).length === 1 );
	const efailed = await eopen( {}, undefined, { 'crm/status' : function() { return [ 500, { error : 'x', code : 'crm_clients_database' } ] } } );
	check( 'a status that cannot be read fails the screen with its sentence and a way to try again', textOf( efailed.main ).indexOf( 'Die Datenbank hat die Anfrage nicht angenommen.' ) !== -1 && all( efailed.main, '#settings-form' ).length === 0 );

	// --- the words of the new screens: every key the script names is a word, and so is every one of the families it builds
	const kwords = new Set();
	let kfound;
	const kpattern = /'((?:acc|card|ctab|cdocs|cportal|cnotes|clients|clientfield|invite|link|newclient|items|settings|tag|status|setup|form|column|error)\.[a-z0-9_-]+)'/g;

	while( ( kfound = kpattern.exec( script ) ) )
		kwords.add( kfound[1] );

	const kfamilies = { 'acc.state-' : [ 'signed', 'invited', 'off', 'gone' ], 'status.lacks-' : [ 'routes', 'templates', 'types', 'paths' ], 'ctab.' : [ 'belege', 'portal', 'notizen' ], 'tag.' : [ 'offer', 'invoice', 'contract', 'privacy', 'information' ], 'settings.number-' : [ 'offer', 'invoice', 'credit' ],
		'clientfield.' : [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id', 'account_email', 'account_name' ], 'status.' : [ 'allok', 'module-off', 'data-unwritable', 'portal-none', 'portal-partial', 'members-missing', 'members-off', 'members-problem', 'files-off', 'review-off', 'sign-off', 'pdf-missing', 'pdf-problem' ],
		'acc.resend-' : [ 'invite', 'login' ], 'acc.resent-' : [ 'invite', 'login' ], 'acc.switch-' : [ 'off', 'on' ], 'acc.switched-' : [ 'off', 'on' ], 'newclient.done-' : [ 'invited', 'unmailed' ], 'error.' : [ 'client-save', 'client-active', 'client-portal', 'account-invite', 'account-resend', 'account-active', 'account-name', 'account-remove', 'account-link', 'unlinked', 'settings', 'setup' ] };

	Object.keys( kfamilies ).forEach( function( stem ) { kfamilies[stem].forEach( function( one ) { kwords.add( stem+ one ) } ) } );

	const kunknown = [ ...kwords ].filter( function( key ) { return /[.-]$/.test( key ) === false && ( typeof texts[key] !== 'string' || texts[key] === '' ) } );
	check( 'every word the new screens ask for is a word of the page: the keys the script names and the families it builds - the states of an account, the parts the portal lacks, the tabs, the tags, the fields of a client', kunknown.length === 0 && kwords.size > 90 );
	check( '...and none of the words is left that the screens do not use any more: the old words of the panels - Im Panel Kunden ansehen - are gone', Object.keys( texts ).filter( function( key ) { return /Panel Kunden|Panel Belege/.test( texts[key] ) } ).length === 0 );

	// --- the money functions, case for case with Money.php: what the panel Belege's script proved moves here with the script that has it now
	const moneyCases = JSON.parse( fs.readFileSync( path.join( root, 'app/Crm/tests/money-cases.json' ), 'utf8' ) );
	const mf = router.win.Nino.crm.money;
	const wrong = [];

	moneyCases.lineAmount.forEach( function( [ qty, price, expected ] ) { if( mf.lineAmount( qty, price ) !== expected ) wrong.push( 'lineAmount '+ qty+ ' '+ price ) } );
	check( 'the amount of a line is qty x price / 1000 rounded half away from zero: '+ moneyCases.lineAmount.length+ ' cases, the largest past 2^53', wrong.length === 0 && moneyCases.lineAmount.length >= 18 );
	moneyCases.vat.forEach( function( [ net, rate, expected ] ) { if( mf.vat( net, rate ) !== expected ) wrong.push( 'vat '+ net+ ' '+ rate ) } );
	check( 'the VAT is net x rate / 10000 rounded half away from zero: '+ moneyCases.vat.length+ ' cases', wrong.length === 0 && moneyCases.vat.length >= 15 );
	moneyCases.parse.forEach( function( [ text, expected ] ) { if( mf.parse( text ) !== expected ) wrong.push( 'parse '+ JSON.stringify( text ) ) } );
	check( 'a price is read from 1.234,56 and 1234,56 and 1234.56 and -12,5; anything else is none: '+ moneyCases.parse.length+ ' cases', wrong.length === 0 && moneyCases.parse.length >= 25 );
	moneyCases.parseQty.forEach( function( [ text, expected ] ) { if( mf.parseQty( text ) !== expected ) wrong.push( 'parseQty '+ JSON.stringify( text ) ) } );
	check( 'a quantity is read the same way with three decimals: '+ moneyCases.parseQty.length+ ' cases', wrong.length === 0 && moneyCases.parseQty.length >= 12 );
	moneyCases.format.forEach( function( [ cents, currency, expected ] ) { if( mf.format( cents, currency ) !== expected ) wrong.push( 'format '+ cents ) } );
	check( 'cents are written 1.234,56 with the currency behind a non-breaking space', wrong.length === 0 && NBSP === ' ' );
	moneyCases.formatQty.forEach( function( [ qty, expected ] ) { if( mf.formatQty( qty ) !== expected ) wrong.push( 'formatQty '+ qty ) } );
	check( 'thousandths are written 1,5 and 2: the trailing zeros cut, three decimals at most', wrong.length === 0 );
	moneyCases.formatRate.forEach( function( [ rate, expected ] ) { if( mf.formatRate( rate ) !== expected ) wrong.push( 'formatRate '+ rate ) } );
	check( 'a rate is written 19 %, 7 % and 5,5 %', wrong.length === 0 );
	moneyCases.totals.forEach( function( entry ) {
		const totals = mf.totals( entry.lines, entry.mode, entry.reverse );
		const rates = totals.rates.map( function( sums ) { return [ sums.rate, sums.net, sums.vat ] } );
		check( 'totals: '+ entry.name, JSON.stringify( [ totals.net, totals.vat, totals.gross, rates, totals.amounts ] ) === JSON.stringify( [ entry.expect.net, entry.expect.vat, entry.expect.gross, entry.expect.rates, entry.expect.amounts ] ) );
	} );
	check( 'the limits are those of the server', mf.MAX_PRICE === 1000000000 && mf.MAX_QTY === 10000000 && mf.MAX_LINE === 100000000000 && mf.MAX_TOTAL === 1000000000000 );

	// === fix round: the answers that come after the screen was left, the portal card after the setup, the invitation that is half typed
	const hold = function() {
		const held = {};
		held.promise = new Promise( function( resolve ) { held.release = resolve } );
		return held;
	};
	const lateScreen = async function( hash, action, release ) {
		const held = hold();
		const w = await kopen( '#kunde-neu', {} );
		w.answers[action] = function() { return held.promise };
		w.location.hash = hash;
		await settle();
		w.location.hash = '#kunde-neu';
		await settle();
		held.release( release );
		await settle();
		return w;
	};
	const onNew = function( w ) { const h = all( w.main, 'h1' )[0]; return h !== undefined && textOf( h ) === 'Neuer Kunde' && all( w.main, '#new-client-form' ).length === 1 };

	const lateKunden = await lateScreen( '#kunden', 'crm-clients/list', [ 200, { clients : kclients } ] );
	check( 'the answer of the list of the clients that arrives after the screen was left draws nothing: the screen the address names stays', onNew( lateKunden ) && all( lateKunden.main, 'table' ).length === 0 );
	const latePositionen = await lateScreen( '#positionen', 'crm-documents/positionen', [ 200, { missing : false, locales : [ 'de_DE' ], native : 'de_DE', items : pitems, settings : settings } ] );
	check( '...so does the answer of the items', onNew( latePositionen ) && all( latePositionen.main, '#items' ).length === 0 );
	const lateSettings = await lateScreen( '#einstellungen', 'crm-documents/settings-get', [ 200, eview ] );
	check( '...and that of the settings: the form of the settings is not drawn over the new client', onNew( lateSettings ) && all( lateSettings.main, '#settings-form' ).length === 0 );

	// a save whose answer comes late: nothing is said, nothing opens, nothing is read again
	const heldCreate = hold();
	const lateCreate = await kopen( '#kunde-neu', { 'crm-clients/save' : function() { return heldCreate.promise } } );
	type( all( lateCreate.main, '#f-company' )[0], 'Spät GmbH', false );
	submit( lateCreate, all( lateCreate.main, '#new-client-form' )[0] );
	await settle();
	lateCreate.location.hash = '#kunden';
	await settle();
	const toastBefore = lateCreate.toast.textContent;
	heldCreate.release( [ 200, { client : Object.assign( {}, kclient, { id : 6, number : 'K-0006' } ) } ] );
	await settle();
	check( 'the answer of the save of a new client that arrives after the screen was left opens no card and says no sentence: the list stays', lateCreate.location.hash === '#kunden' && textOf( all( lateCreate.main, 'h1' )[0] ) === 'Kunden' && lateCreate.toast.textContent === toastBefore );
	const heldSave = hold();
	const lateSave = await eopen( {}, undefined, { 'crm-documents/settings-save' : function() { return heldSave.promise } } );
	type( efield( lateSave, 'currency' ), 'CHF', false );
	submit( lateSave, all( lateSave.main, '#settings-form' )[0] );
	await settle();
	lateSave.location.hash = '#kunde-neu';
	await settle();
	const readsLate = lateSave.all( 'crm-documents/settings-get' ).length;
	const toastLate = lateSave.toast.textContent;
	heldSave.release( [ 200, { settings : Object.assign( {}, esettings, { currency : 'CHF' } ) } ] );
	await settle();
	check( 'the answer of the save of the settings that arrives after the screen was left changes nothing: no sentence, no second read, the settings of the other screens as they are, the new client on the screen', onNew( lateSave ) && lateSave.toast.textContent === toastLate && lateSave.all( 'crm-documents/settings-get' ).length === readsLate && lateSave.app.state.settings.currency === '€' );

	// the portal card after the setup: the pages, the settings of Members, the status - all of it as the setup left it
	let reads = 0;
	const portalBefore = Object.assign( {}, eview.portal, { pages : [ { uri : '/portal', present : false }, { uri : '/portal/konto', present : false } ], members : [ { key : 'paths', label : 'Geschützte Pfade', value : '', unit : '' }, { key : 'start', label : 'Startseite', value : '/', unit : '' } ] } );
	const portalAfterSetup = Object.assign( {}, eview.portal, { pages : [ { uri : '/portal', present : true }, { uri : '/portal/konto', present : true } ], members : [ { key : 'paths', label : 'Geschützte Pfade', value : '/portal', unit : '' }, { key : 'start', label : 'Startseite', value : '/portal', unit : '' } ] } );
	let setupDone = false;
	const stalePortal = await eopen( {}, undefined, {
		'crm-documents/settings-get' : function() { reads++; return [ 200, Object.assign( {}, eview, { portal : setupDone === true ? portalAfterSetup : portalBefore } ) ] },
		'crm/status' : function() { return [ 200, { items : setupDone === true ? eok : pitemsStatus } ] },
		'crm/setup' : function() { setupDone = true; return [ 200, { added : { routes : [ 'a' ], templates : [], types : [], texts : 0, paths : true, start : true }, existed : { routes : [], templates : [], types : [] } } ] },
	} );
	const portalDd = function( w ) { return all( all( w.main, '#portal' )[0], 'dd' ).map( textOf ) };
	check( 'before the setup the card of the portal says what is missing: both pages marked, no protected path, the start page the root', portalDd( stalePortal )[1] === '/portal (fehlt) · /portal/konto (fehlt)' && portalDd( stalePortal )[2] === 'Geschützte Pfade: Startseite: /' );
	click( stalePortal, all( stalePortal.main, '#portal-setup' )[0] );
	await settle();
	check( 'after the setup the card is drawn from what the setup left - the settings are read again with the status -: the pages there, the protected path and the start page of Members, not the values from before', reads === 2 && portalDd( stalePortal )[1] === '/portal · /portal/konto' && portalDd( stalePortal )[2] === 'Geschützte Pfade: /portalStartseite: /portal' && all( stalePortal.main, '#status-ok' ).length === 1
		&& textOf( all( stalePortal.main, '#setup-said' )[0] ).indexOf( 'Eingerichtet.' ) === 0 );

	// the invitation that is half typed is unsaved input
	const kguard = await kopen( '#kunde-1' );
	click( kguard, all( kguard.main, '#invite-open' )[0] );
	check( 'an invitation form that is open and empty is no unsaved input: a link away goes on', click( kguard, all( kguard.main, '.crumb a' )[0] ) === false );
	kguard.location.hash = '#kunde-1';
	await settle();
	click( kguard, all( kguard.main, '#invite-open' )[0] );
	type( all( all( kguard.main, '#invite' )[0], 'input' )[0], 'halb@example.org', false );
	check( '...typed in, it is: a link away asks first, in the words of a form, and the answer Abbrechen stays', click( kguard, all( kguard.main, '.crumb a' )[0] ) === true && kguard.dialogs.length === 1 && textOf( all( kguard.dialogs[0], 'p' )[0] ) === 'Die Seite enthält Änderungen, die noch nicht gespeichert sind.' && kguard.location.hash === '#kunde-1' );
	no( kguard, 0 );
	await settle();
	click( kguard, all( kguard.main, '#invite-cancel' )[0] );
	check( '...closing the form drops the guard: a link away goes on', kguard.app.state.draft === null && click( kguard, all( kguard.main, '.crumb a' )[0] ) === false );
	const kSent = await kopen( '#kunde-1', { 'crm-clients/invite' : function( data ) { return [ 200, { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.email, name : '', active : true, login : null }, mailed : true } ] } } );
	click( kSent, all( kSent.main, '#invite-open' )[0] );
	type( all( all( kSent.main, '#invite' )[0], 'input' )[0], 'neu@example.org', false );
	submit( kSent, all( kSent.main, '#invite' )[0] );
	await settle();
	check( '...an invitation that was sent holds nothing unsaved either: a link away goes on', arows( kSent ).length === 5 && kSent.app.state.draft === null && click( kSent, all( kSent.main, '.crumb a' )[0] ) === false );
	check( 'the row of an account that is gone names the account, and the badge says that it is gone - once', textOf( all( arows( kguard )[3], 'b' )[0] ) === 'Konto (dddd)' && textOf( arows( kguard )[3] ).split( 'gelöscht' ).length === 2 && textOf( all( arows( kguard )[3], '.badge' )[0] ) === 'gelöscht' );

	// --- the panel CRM of the workbench: its script over the kernel's own Nino.admin.js
	const panelWorld = function( locale ) {

		const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( 'app/Crm/Crm/Admin/text/'+ locale+ '.php' ) );
		const missing = [];
		const requests = [];
		const mount = element('div');
		mount.id = 'crm-app';

		const page = documentOf( mount );
		const Nino = { dir : '', content : { getText : function( key ) { if( words[key] === undefined ) missing.push( key ); return words[key] ?? '' } }, admin : {} };
		const win = { Nino : Nino, location : { href : '' } };
		const context = vm.createContext( { console : console, document : page, Nino : Nino, window : win, Event : Event } );

		load( '_admin/assets/Nino.admin.js', context );
		Nino.adminUi.api.call = function( action, payload, callback ) { requests.push( { action : action, payload : payload, callback : callback, answered : false } ) };
		load( 'app/Crm/Crm/Admin/assets/admin.js', context, 'admin.js' );

		return { win : win, panel : Nino.admin.crm, mount : mount, requests : requests, missing : missing, words : words, Nino : Nino,
			last : function() { return requests[requests.length - 1] },
			answer : function( status, body ) { const open = requests.filter( function( r ) { return r.answered === false } ); open[open.length - 1].answered = true; open[open.length - 1].callback( status, body ) },
			find : function( predicate ) { return findAll( mount, predicate )[0] ?? null },
			texts : function() { return findAll( mount, function() { return true } ).map( function( node ) { return node.textContent } ) },
			said : function( key, ...params ) { return Nino.adminUi.format( words[key], ...params ) } };
	};

	const pw = panelWorld( 'de_DE' );
	check( 'the panel CRM is Nino.admin.crm, under the uri of the panel, with its mount; nothing is asked before it is shown', pw.panel.uri === 'crm' && pw.panel.mount() === pw.mount && pw.requests.length === 0 );
	pw.panel.showCurrent();
	check( '...showing it asks for crm/status with nothing posted - the one screen is the link, the status and the button', pw.last().action === 'crm/status' && JSON.stringify( pw.last().payload ) === '{}' && pw.requests.length === 1 );
	pw.answer( 200, { items : eok } );
	const openLink = pw.find( function( node ) { return node.id === 'crm-open' } );
	check( 'the screen has the way to the surface: a button CRM öffnen in the kernel\'s primary look - the class of a button, a link has none, and no class the kernel does not have - which goes to /crm', openLink !== null && openLink.tagName === 'BUTTON' && openLink.textContent === 'CRM öffnen' && String( openLink.className ) === 'nino-admin-btn-primary' && openLink.dataset.href === '/crm' && pw.win.location.href === '' && ( openLink.click(), pw.win.location.href === '/crm' ) );
	check( '...a module that misses nothing says so, and the button Portal einrichten is hidden', pw.texts().indexOf( pw.words['/_admin/crm/status/allok'] ) !== -1 && pw.find( function( node ) { return node.id === 'crm-setup' } ).hidden === true );
	check( '...and the script of the panel asks for no word the text files do not have', pw.missing.length === 0 );

	const pm = panelWorld( 'de_DE' );
	pm.panel.showCurrent();
	pm.answer( 200, { items : pitemsStatus } );
	const pmLines = findAll( pm.mount, function( node ) { return node.tagName === 'LI' } ).map( function( node ) { return node.textContent } );
	check( 'what is missing is told line by line in the words of its state, a portal that is partly there names what it lacks, and the button is there', pmLines.length === 4 && pmLines[1] === pm.words['/_admin/crm/status/sign-off'] && pmLines[3] === pm.said( '/_admin/crm/status/portal-partial', 'Routen, der Elementtyp Positionen' )
		&& pm.find( function( node ) { return node.id === 'crm-setup' } ).hidden === false && pm.find( function( node ) { return node.id === 'crm-setup' } ).textContent === 'Portal einrichten' );
	const pmButton = pm.find( function( node ) { return node.id === 'crm-setup' } );
	pmButton.click();
	check( 'the button posts crm/setup with nothing, and is off while the request is out', pm.last().action === 'crm/setup' && JSON.stringify( pm.last().payload ) === '{}' && pmButton.disabled === true );
	pm.answer( 200, { added : { routes : [ 'a', 'b', 'c' ], templates : [ 'x' ], types : [ 'positionen.php' ], texts : 12, paths : false, start : false }, existed : { routes : [ 'd' ], templates : [ 'y', 'z' ], types : [], paths : true, start : true } } );
	check( '...the status is asked for again after it', pm.last().action === 'crm/status' && pm.requests.length === 3 );
	pm.answer( 200, { items : eok } );
	check( '...and what the setup did stays on the screen after the box is drawn again: one sentence with a place for each of the five numbers', pm.texts().indexOf( 'Eingerichtet. Neu: Routen 3, Vorlagen 1, Elementtypen 1, Worte 12. Schon da: 3.' ) !== -1 && pm.words['/_admin/crm/setup/done'].split( '%s' ).length === 6
		&& pm.find( function( node ) { return node.id === 'crm-setup' } ).hidden === true );
	const pr = panelWorld( 'de_DE' );
	pr.panel.showCurrent();
	pr.answer( 200, { items : pitemsStatus } );
	pr.find( function( node ) { return node.id === 'crm-setup' } ).click();
	pr.answer( 409, { error : 'x', code : 'crm_setup_members' } );
	check( 'a refusal of the setup says its sentence in the line, and the button is back', pr.texts().indexOf( pr.words['/_admin/error/crm_setup_members'] ) !== -1 && pr.find( function( node ) { return node.id === 'crm-setup' } ).disabled === false && pr.requests.length === 2 );
	const pf = panelWorld( 'en_US' );
	pf.panel.showCurrent();
	pf.answer( 200, { items : pitemsStatus } );
	check( 'the English panel has every word as well, in the same keys', pf.missing.length === 0 && pf.texts().indexOf( pf.words['/_admin/crm/status/sign-off'] ) !== -1 && Object.keys( fills( 'app/Crm/Crm/Admin/text/de_DE.php' ) ).join() === Object.keys( fills( 'app/Crm/Crm/Admin/text/en_US.php' ) ).join() );
	const panelScript = source( 'app/Crm/Crm/Admin/assets/admin.js' );
	check( 'the script of the panel assigns no markup: innerHTML only to clear, no outerHTML, no insertAdjacentHTML, no document.write; and it asks for nothing but crm/status and crm/setup', panelScript.match( /innerHTML\s*=(?!\s*'';)/g ) === null && /outerHTML|insertAdjacentHTML|document\.write/.test( panelScript ) === false
		&& [ ...panelScript.matchAll( /call\( '([a-z/-]+)'/g ) ].map( function( found ) { return found[1] } ).filter( function( action, index, list ) { return list.indexOf( action ) === index } ).sort().join() === 'setup,status' );

	done();
	process.exit( process.exitCode ?? 0 );

} )();

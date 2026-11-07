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

	[ 'app/Crm/Clients/Admin/text/de_DE.php', 'app/Crm/Documents/Admin/text/de_DE.php', 'app/Crm/Crm/Admin/text/de_DE.php', 'features/Pdf/text/de_DE.php', 'features/Files/text/de_DE.php', 'features/Review/text/de_DE.php', '_admin/Nino/Modules/Elements/text/de_DE.php', '_admin/Nino/Modules/Text/text/de_DE.php' ].forEach( function( file ) {
		const sentences = fills( file );
		Object.keys( sentences ).forEach( function( key ) {

			const found = /^\/_admin\/crm\/(status|setup)\/([a-z-]+)$/.exec( key );

			if( key.indexOf( '/_admin/error/' ) === 0 )
				map['error.'+ key.slice( '/_admin/error/'.length )] = sentences[key];
			else if( /^\/_admin\/text\/error\/(save|load)$/.test( key ) )
				map['text.error-'+ key.slice( '/_admin/text/error/'.length )] = sentences[key];
			else if( found !== null )
				map[found[1]+ '.'+ found[2]] = sentences[key];
		} );
	} );

	return map;
}

const texts = words();

// --- fixtures ------------------------------------------------------------------------------------------------------

const settings = { vat_mode : 'regular', vat_rates : '1900,700,0', payment_days : '14', offer_days : '30', currency : '€', tax_id : '', bank_name : '', iban : '', bic : '', rates : [ 1900, 700, 0 ] };
const smallBusiness = Object.assign( {}, settings, { vat_mode : 'small_business' } );

const client = { company : 'Nordlicht Records GmbH', name : 'Lena Brandt', street : 'Hafenstraße 12', zip : '20457', city : 'Hamburg', country : 'DE', number : 'K-0001', vat_id : 'DE123456789', email : 'lena@nordlicht.example', business : false, buyer_reference : '' };
const clients = [
	{ id : 1, number : 'K-0001', title : 'Nordlicht Records GmbH', active : true, business : true, buyer_reference : 'PO-4711' },
	{ id : 2, number : 'K-0002', title : 'Jonas Richter', active : true, business : true, buyer_reference : 'JR-2' },
	{ id : 3, number : 'K-0003', title : 'Alt AG', active : false, business : false, buyer_reference : '' },
];
const items = [
	{ id : 1, title : 'Mixing', description : 'Mischung eines Songs\nbis 48 Spuren', unit : 'Std.', unit_code : 'HUR', price_net : 9000, vat_rate : 1900, active : true },
	{ id : 2, title : 'Mastering', description : '', unit : 'Song', unit_code : '', price_net : 11000, vat_rate : 700, active : true },
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
		gross : 100000, client_id : 1, client_title : 'Nordlicht Records GmbH', parent_id : null, project : '', project_title : '' }, over );
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
		finalised : '2026-10-02T09:12:00Z', sent_at : null, paid_at : null, created : '2026-10-01T08:00:00Z', updated : '2026-10-02T09:12:00Z', title : '', released : false, released_at : null, sign_id : '', sign_state : '', signed_at : null, expired : false, overdue : false, project : '', project_title : '',
		buyer_reference : '', xml_path : '', xml_sha256 : '', xml_lacks : [], xml_url : '', erechnung : null,
		lines : [
			{ id : 1, document_id : 11, sort : 1, title : 'Recording-Tag', description : 'Studio A', qty : 4000, unit : 'Tag', unit_code : 'DAY', price_net : 20000, vat_rate : 1900, amount_net : 80000 },
			{ id : 2, document_id : 11, sort : 2, title : 'Mixing', description : '', qty : 2000, unit : 'Std.', unit_code : 'HUR', price_net : 10000, vat_rate : 1900, amount_net : 20000 },
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
	return Object.assign( { document : full( over ), templates : templates, clients : clients, settings : settings, items : items, offers : offers, projects : [], accounts : [], sign_days : 14 }, extra || {} );
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

	const opts = Object.assign( { hash : '', answers : {}, perms : undefined, pdfAvailable : undefined, blocked : false }, options || {} );
	const calls = [];
	const timers = [];
	const hashListeners = [];
	const documentListeners = {};
	const windowListeners = {};
	const scrolled = [];
	const scrolls = [];
	const dialogs = [];
	const opened = [];
	const downloads = [];
	const storage = {};
	const reloads = [];
	const tabs = [];

	const mount = element('div');
	mount.id = 'root';

	const nav = element('nav');
	nav.id = 'nav';
	[ 'uebersicht', 'belege', 'kunden', 'projekte', 'uploads', 'positionen', 'pdf', 'einstellungen' ].forEach( function( key ) {
		const anchor = element('a');
		anchor.setAttribute( 'data-r', key );
		anchor.setAttribute( 'href', '#'+ key );

		// The entry PDF-Vorlagen is hidden in the markup; the script shows it for the permission
		if( key === 'pdf' ) {
			anchor.id = 'nav-pdf';
			anchor.hidden = true;
		}

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
	block.textContent = JSON.stringify( { base : '', csrf : 'token-1', user : { name : 'crm@example.com' }, perm : '/_admin/crm/manage', perms : opts.perms === undefined ? { crm : true, members : true, sign : true } : opts.perms, pdf_available : opts.pdfAvailable, texts : texts, links : { workbench : '/_admin/', site : '/', items : '/_admin/#elements/positionen', projects : '/_admin/#elements/projekte', files : '/_admin/#files', pdf : '/_admin/#pdf', members : '/_admin/#members', users : '/_admin/#users' } } );
	mount.appendChild( block );

	const page = documentOf( mount );
	const make = page.createElement;

	page.createElement = function( tag ) {
		const el = make( tag );

		// A form submits as the browser's requestSubmit() does: its listeners of submit hear it
		if( tag === 'form' )
			el.requestSubmit = function() { fire( el, 'submit', { target : el, preventDefault : function() {} } ) };

		// A link that is clicked is a download the page asked for: its address is kept, and nothing is loaded
		if( tag === 'a' ) {
			const press = el.click;
			el.click = function() { downloads.push( el.getAttribute( 'href' ) ); return press === undefined ? undefined : press.call( el ) };
		}

		// A node that is scrolled to says so; the browser's own box of a node is none here
		el.scrollIntoView = function( options ) { scrolled.push( [ el.id, options, el ] ) };

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
	page.removeEventListener = function( name, fn ) { documentListeners[name] = ( documentListeners[name] || [] ).filter( function( one ) { return one !== fn } ) };

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
		removeEventListener : function( name, fn ) { windowListeners[name] = ( windowListeners[name] || [] ).filter( function( one ) { return one !== fn } ) },
		scrollY : 0,
		scrollTo : function( x, y ) { scrolls.push( [ x, y ] ) },
		setTimeout : function( fn, ms ) { timers.push( { fn : fn, ms : ms } ); return timers.length },
		clearTimeout : function( id ) { if( id > 0 && timers[id - 1] ) timers[id - 1].cleared = true },
		sessionStorage : { getItem : function( key ) { return storage[key] ?? null }, setItem : function( key, value ) { storage[key] = value }, removeItem : function( key ) { delete storage[key] } },
		open : function( url, target, features ) { opened.push( [ url, target, features ] ); if( opts.blocked === true ) return null; const tab = { opener : 'window' }; tabs.push( tab ); return tab; },
		// A picture is posted as a form: the action, the data and the file as its fields
		FormData : function FormData() {
			const fields = [];
			this.fields = fields;
			this.append = function( name, value ) { fields.push( [ name, value ] ) };
			this.get = function( name ) { const one = fields.find( function( field ) { return field[0] === name } ); return one === undefined ? null : one[1] };
		},
		fetch : function( url, init ) {

			const form = init.body instanceof win.FormData;
			const request = form ? { action : init.body.get( 'action' ), data : JSON.parse( init.body.get( 'data' ) ), file : init.body.get( 'file' ) ?? init.body.get( 'chunk' ), part : init.body.get( 'chunk' ) === null ? 'file' : 'chunk' } : JSON.parse( init.body );
			const answer = typeof opts.answers[request.action] === 'function' ? opts.answers[request.action]( request.data, request.file ) : [ 404, { error : 'no answer in the test for '+ request.action, code : 'action' } ];

			calls.push( { url : url, init : init, action : request.action, data : request.data, file : request.file, part : request.part, form : form } );

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
		app : app, win : win, page : page, calls : calls, tabs : tabs, main : main, toast : toast, dialogRoot : dialogRoot, count : count, clientCount : clientCount, nav : nav, timers : timers, dialogs : dialogs, opened : opened, downloads : downloads, reloads : reloads, storage : storage, answers : opts.answers,
		location : location, documentListeners : documentListeners, windowListeners : windowListeners, scrolled : scrolled, scrolls : scrolls,
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
function press( w, key, target, mods ) {
	let prevented = false;
	( w.documentListeners.keydown || [] ).forEach( function( fn ) { fn( Object.assign( { type : 'keydown', key : key, target : target || null, preventDefault : function() { prevented = true } }, mods || {} ) ) } );
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
		'chip.' : [ 'all', 'open', 'overdue', 'drafts', 'offers', 'accepted', 'invoices', 'plain', 'paid' ], 'tab.' : [ 'lines', 'document', 'portal', 'sign', 'notes', 'history' ], 'primary.' : [ 'send', 'sent', 'paid', 'invoice', 'sign' ],
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
	check( '...every post is JSON with the token in X-CSRF-Token, to /crm/api, with the cookie of the session', router.calls.length > 0 && router.calls.every( function( call ) { return call.url === '/crm/api' && call.init.method === 'POST' && call.init.headers['X-CSRF-Token'] === 'token-1' && call.init.headers['Content-Type'] === 'application/json' } )
		&& router.calls.every( function( call ) { return call.init.credentials === 'same-origin' } ) && JSON.parse( router.calls[0].init.body ).action === 'crm/status' && JSON.parse( router.calls[1].init.body ).action === 'crm-documents/overview' );
	check( '...the rail counts the open invoices', router.count.textContent === '3' );

	const routes = [
		[ '#belege', { screen : 'belege' }, 'crm-documents/list', 1 ],
		[ '#beleg-12', { screen : 'beleg', id : 12 }, 'crm-documents/get', 1 ],
		[ '#entwurf-16', { screen : 'entwurf', id : 16 }, 'crm-documents/get', 1 ],
		[ '#neu-invoice', { screen : 'entwurf', category : 'invoice', offer : '', client : '', project : '' }, 'crm-documents/templates', 1 ],
		[ '#neu-contract-13', { screen : 'entwurf', category : 'contract', offer : '13', client : '', project : '' }, 'crm-documents/templates', 1 ],
		[ '#neu-invoice-k3', { screen : 'entwurf', category : 'invoice', offer : '', client : '3', project : '' }, 'crm-documents/templates', 1 ],
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
	check( '...everything came from two calls: the status of the first run, then the overview', overview.sent().join() === 'crm/status,crm-documents/overview' );
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

	check( 'Belege asks for the list of the year, and shows nine tabs with the counts of the documents each takes', lists2.length === 1 && lists2[0].year === String( new Date().getFullYear() ) && tabs().length === 9
		&& tabs().map( textOf ).join( '|' ) === 'Alle8|Offen3|Überfällig1|Entwürfe1|Angebote2|Angenommen1|Rechnungen5|Verträge & Dokumente1|Bezahlt1' );
	check( '...the table has the columns, the number in the first, and the foot has the sum of what is shown: the credit note is taken off', all( listing.main, 'thead th' ).map( textOf ).join( '|' ) === 'Nummer|Art|Kunde|Projekt|Datum ↓|Fällig / gültig|Betrag|Status|' && rows().length === 8
		&& textOf( all( listing.main, '#sum' )[0] ) === '5.908,40'+ NBSP+ '€' );
	check( '...a row has the kind, the client, the day, the due or valid day, the gross in the currency and the state', ( function() {
		const first = rows().find( function( r ) { return textOf( r.children[0] ) === 'R-2026-0007' } );
		return cells( first ).slice( 1, 7 ).join( '|' ) === 'Rechnung|Nordlicht Records GmbH|–|02.10.2026|16.10.2026|1.190,00'+ NBSP+ '€' && first.children[5].classList.contains( 'bad' ) === true;
	} )() );
	check( '...an overdue invoice says überfällig, a document in the portal says Portal, a signature that waits says angesehen, an offer says its state, a draft Entwurf and has no number - its kind is the name', ( function() {
		const by = function( id ) { return rows().find( function( r ) { return r.dataset.id === String( id ) } ) };
		return textOf( by( 11 ).children[7] ) === 'überfällig Portal' && textOf( by( 18 ).children[7] ) === 'offen Portal angesehen' && textOf( by( 13 ).children[7] ) === 'offen' && textOf( by( 19 ).children[7] ) === 'angenommen' && textOf( by( 16 ).children[7] ) === 'Entwurf' && textOf( by( 16 ).children[0] ) === 'Entwurf'
			&& textOf( by( 18 ).children[0] ) === 'Dienstleistungsvertrag Album 2027' && textOf( by( 18 ).children[6] ) === '–';
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
	click( listing, all( heads()[6], 'button' )[0] );
	check( 'a click on the head of the amount sorts by cents, up: -270,00 before 392,70 before 990,00 before 1.071,00 and so on - not as text', numbers().join() === 'G-2026-0001,Dienstleistungsvertrag Album 2027,A-2026-0010,R-2026-0008,R-2026-0006,Entwurf,R-2026-0007,A-2026-0011' && heads()[6].getAttribute( 'aria-sort' ) === 'ascending' );
	click( listing, all( heads()[6], 'button' )[0] );
	check( '...a second click turns it round', numbers()[0] === 'A-2026-0011' && numbers()[7] === 'G-2026-0001' && heads()[6].getAttribute( 'aria-sort' ) === 'descending' );
	click( listing, all( heads()[2], 'button' )[0] );
	check( 'by client the names are in the order of the German alphabet, by number the numbers in text', textOf( rows()[0].children[2] ) === 'Alt AG' && ( click( listing, all( heads()[0], 'button' )[0] ), numbers()[0] === 'A-2026-0010' ) );
	click( listing, all( heads()[4], 'button' )[0] );
	check( 'by date a first click sorts the newest first, by the due day the days of an offer are among the due days', numbers()[0] === 'Entwurf' && ( click( listing, all( heads()[5], 'button' )[0] ), textOf( rows()[0].children[5] ) === '' ) );

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
	async function opened( over, extra, answers, perms ) {
		const w = world( { perms : perms, hash : '#beleg-'+ ( over.id ?? 11 ), answers : Object.assign( {
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
		[ 'an open invoice', { state : 'open' }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Stornieren|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a sent invoice that is overdue and in the portal', { state : 'sent', overdue : true, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'a paid invoice', { state : 'paid' }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a cancelled invoice', { state : 'cancelled' }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'an open offer', { id : 13, category : 'offer', state : 'open', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als angenommen markieren|Als abgelehnt markieren|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'an accepted offer', { id : 13, category : 'offer', state : 'accepted', due_date : null }, 'PDF ansehen|PDF herunterladen|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a credit note', { id : 17, category : 'credit', state : 'sent', due_date : null }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a contract that waits for a signature', { id : 18, category : 'contract', state : 'open', sign_state : 'viewed', lines : [], title : 'V', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Erinnern|Anfrage zurückziehen|Projekt ändern|Notizen' ],
		[ 'a contract with no request', { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Unterschrift anfordern|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'an information document', { id : 20, category : 'information', state : 'open', lines : [], title : 'Z', number : '', due_date : null }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Als Entwurf kopieren|Projekt ändern|Notizen' ],
	];

	for( const [ label, over, expected ] of menus ) {
		const w = await opened( over );
		check( 'the menu of '+ label+ ': '+ expected.replace( /\|/g, ', ' ), menuOf( w ).join( '|' ) === expected );
	}

	// the electronic invoice on the card of a finalised document
	const withFile = await opened( { state : 'open', xml_path : '/data/crm/pdf/2026/R-2026-0007.xml', xml_url : '/.crm/xml/11', erechnung : { xml : true, lacks : [] } } );
	const xmlButton = all( withFile.main, '#xml-download' )[0];
	check( 'the card of a document with a file has the button "XRechnung": a link to /.crm/xml/<id> that is no new window - the server answers a file to save - and the row has the word E-Rechnung', xmlButton !== undefined && xmlButton.tagName === 'A' && xmlButton.getAttribute( 'href' ) === '/.crm/xml/11' && textOf( xmlButton ) === texts['erechnung.button'] && xmlButton.getAttribute( 'target' ) === null
		&& all( withFile.main, '.cols-3 dt' ).some( function( dt ) { return textOf( dt ) === texts['document.xml'] } ) && all( withFile.main, '#xml-none' ).length === 0 );
	check( '...and the menu has the download of the file beside the two of the PDF', menuOf( withFile ).join( '|' ) === [ 'PDF ansehen', 'PDF herunterladen', texts['menu.xml'], 'Als versendet markieren', 'Stornieren', 'Als Entwurf kopieren', 'Projekt ändern', 'Notizen' ].join( '|' ) && all( all( withFile.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.xml'] } ).getAttribute( 'href' ) === '/.crm/xml/11' );
	const withoutFile = await opened( { state : 'open', xml_lacks : [ 'buyer_reference', 'line_unit' ], erechnung : { xml : false, lacks : [ { key : 'buyer_reference', text : 'Es fehlt die Käuferreferenz: Trage sie hier am Beleg oder beim Kunden ein.' }, { key : 'line_unit', text : 'Mindestens eine Position hat keine Einheit: Wähle sie in der Zeile aus.' } ] } } );
	const xmlNone = all( withoutFile.main, '#xml-none' )[0];
	check( 'the card of a document that got none says "ohne E-Rechnung" and what was missing then, one line each - and has no button', xmlNone !== undefined && textOf( all( xmlNone, 'span' )[0] ) === texts['erechnung.none'] && all( xmlNone, 'li' ).map( textOf ).join( '|' ) === 'Es fehlt die Käuferreferenz: Trage sie hier am Beleg oder beim Kunden ein.|Mindestens eine Position hat keine Einheit: Wähle sie in der Zeile aus.'
		&& all( withoutFile.main, '#xml-download' ).length === 0 && menuOf( withoutFile ).indexOf( texts['menu.xml'] ) === -1 );
	const plainCard = await opened( { state : 'open' } );
	check( 'a document that gets none - a private client\'s, an offer, one finalised before the flag - has neither the button nor the words', all( plainCard.main, '#xml-download' ).length === 0 && all( plainCard.main, '#xml-none' ).length === 0 && textOf( plainCard.main ).indexOf( texts['document.xml'] ) === -1 );

	// the head and the blocks
	const invoice = await opened( { state : 'sent', overdue : true, released : true, id : 11 } );
	const headNode = all( invoice.main, '.head' )[0];
	check( 'the head of a document: its number, the state, the mark of the portal, the client, the day, the due day, the gross in large and the primary action', textOf( headNode.children[0] ) === 'R-2026-0007' && textOf( headNode ).indexOf( 'überfällig' ) !== -1 && textOf( headNode ).indexOf( 'Portal' ) !== -1
		&& textOf( headNode ).indexOf( 'Nordlicht Records GmbH · 02.10.2026 · fällig 16.10.2026' ) !== -1 && textOf( all( invoice.main, '#gross' )[0] ) === '1.190,00'+ NBSP+ '€' );
	check( '...the client is a link to the card of the client, in the surface', all( headNode, 'a' )[0].getAttribute( 'href' ) === '#kunde-1' );
	// a document without its second date says nothing of it: the head ends with the day, the block has no row for it, the box of sums no terms
	const undatedInvoice = await opened( { id : 11, state : 'open', overdue : false, due_date : null, valid_until : null } );
	const undatedOffer = await opened( { id : 13, category : 'offer', state : 'open', due_date : null, valid_until : null, expired : false } );
	check( 'an invoice without a due date: the head ends with the day of the document - no dot, no word of the due day', textOf( all( all( undatedInvoice.main, '.head' )[0], '.sub' )[0] ) === 'Nordlicht Records GmbH · 02.10.2026' && all( undatedInvoice.main, '.note' ).length === 0 && all( undatedInvoice.main, '#remind' ).length === 0 );
	check( '...an offer without a validity day too, and neither block of the document has a row for the date that is none', textOf( all( all( undatedOffer.main, '.head' )[0], '.sub' )[0] ).indexOf( ' · ' ) === textOf( all( all( undatedOffer.main, '.head' )[0], '.sub' )[0] ).lastIndexOf( ' · ' ) && textOf( all( all( undatedOffer.main, '.head' )[0], '.sub' )[0] ).slice( -4 ) === '2026'
		&& textOf( all( undatedInvoice.main, '.card.pad' )[1] ).indexOf( texts['document.due'] ) === -1 && textOf( all( undatedOffer.main, '.card.pad' )[1] ).indexOf( texts['document.valid'] ) === -1 );
	check( '...and the sums box does not say a term in days: only the kind of VAT', textOf( all( all( undatedInvoice.main, '.totals' )[0], '.state-note' )[0] ) === texts['vat.regular'] && textOf( all( all( invoice.main, '.totals' )[0], '.state-note' )[0] ).indexOf( ' · ' ) !== -1 );
	const undatedDocs = [ row( { id : 31, number : 'R-2026-0031', client_title : 'Nordlicht Records GmbH', date : '2026-11-01', due_date : null, valid_until : null, state : 'open', gross : 11900 } ),
		row( { id : 32, category : 'offer', number : 'A-2026-0032', client_title : 'Podcast Süd', date : '2026-11-01', due_date : null, valid_until : null, state : 'open', gross : 11900 } ),
		row( { id : 33, number : 'R-2026-0033', client_title : 'Podcast Süd', date : '2026-11-01', due_date : '2026-11-10', valid_until : null, state : 'open', gross : 11900 } ) ];
	const undatedList = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
		'crm-documents/list' : function() { return [ 200, { documents : undatedDocs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	undatedList.boot();
	await settle();
	const undatedCells = all( all( undatedList.main, 'tbody' )[0], 'tr' ).map( function( r ) { return r.children } ).sort( function( x, y ) { return textOf( x[0] ) < textOf( y[0] ) ? -1 : 1 } );
	check( 'the list has an empty cell where a document has neither day, and the day where it has one - the empty cell is not the colour of a late one', undatedCells.length === 3 && undatedCells.slice( 0, 2 ).every( function( c ) { return textOf( c[5] ) === '' && c[5].classList.contains( 'bad' ) === false } ) && textOf( undatedCells[2][5] ) === '10.11.2026' );
	check( '...an overdue invoice says since when and offers the reminder', textOf( all( invoice.main, '.note' )[0] ).indexOf( 'Fällig seit 16.10.2026.' ) === 0 && all( invoice.main, '#remind' ).length === 1 );
	const openLate = await opened( { id : 11, state : 'open', overdue : true, released : true } );
	const paidLate = await opened( { id : 11, state : 'paid', overdue : true, released : true } );
	check( '...an overdue invoice that is open has the button of the reminder in its note as a sent one has; a paid one has none', textOf( all( openLate.main, '.note' )[0] ).indexOf( 'Fällig seit 16.10.2026.' ) === 0 && all( openLate.main, '#remind' ).length === 1 && all( paidLate.main, '#remind' ).length === 0 );
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
	// Versenden and Freigeben ask first, always: the yes of the dialog is the last of its buttons
	const sendYes = async function( w ) { click( w, all( w.dialogs[0], 'button' ).pop() ); await settle(); };
	await tabClick( invoice, 'Verlauf' );
	check( 'Verlauf lists the dates of the document: created, finalised under its number, released... in the order they happened', all( body( invoice ), 'li' ).map( function( li ) { return textOf( li.children[1] ) } ).join( '|' ) === 'Als Entwurf angelegt|Festgeschrieben als R-2026-0007, PDF erzeugt' );
	const reminded = await opened( { reminded_at : '2026-11-03T09:00:00Z', reminded_mail : false } );
	await tabClick( reminded, 'Verlauf' );
	check( 'Verlauf has the reminder with its day and says whether it went by mail or was noted without one', all( body( reminded ), 'li' ).map( function( li ) { return textOf( li.children[1] ) } ).indexOf( texts['history.reminded-quiet'] ) !== -1 && texts['history.reminded-quiet'].indexOf( 'ohne E-Mail' ) !== -1 && texts['history.reminded-mail'].indexOf( 'per E-Mail' ) !== -1 );
	const remindedMail = await opened( { reminded_at : '2026-11-03T09:00:00Z', reminded_mail : true } );
	await tabClick( remindedMail, 'Verlauf' );
	check( '...and for a reminder with the mail the other words', all( body( remindedMail ), 'li' ).map( function( li ) { return textOf( li.children[1] ) } ).indexOf( texts['history.reminded-mail'] ) !== -1 );
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
			'crm-documents/remind' : function( data ) { calls.push( [ 'remind', data ] ); return [ 200, { document : full( over ), mailed : data.notify === false ? 0 : 2, notify : data.notify !== false } ] },
			'crm-documents/unrelease' : function( data ) { calls.push( [ 'unrelease', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/sign' : function( data ) { calls.push( [ 'sign', data ] ); return [ 200, Object.assign( { document : full( over ), mailed : data.notify !== false }, data.notify === false ? { link : 'https://example.org/club/.sign?t=ASKED' } : {} ) ] },
			'crm-documents/sign-remind' : function( data ) { calls.push( [ 'sign-remind', data ] ); return [ 200, Object.assign( { document : full( over ) }, data.notify === false ? { link : 'https://example.org/club/.sign?t=AGAIN' } : {} ) ] },
			'crm-documents/sign-cancel' : function( data ) { calls.push( [ 'sign-cancel', data ] ); return [ 200, { document : full( over ) } ] },
			'crm-documents/save' : function( data ) { calls.push( [ 'save', data ] ); return [ 200, { document : full( Object.assign( {}, over, { notes : data.notes } ) ) } ] },
		}, answers || {} ) );
		w.steps = calls;
		return w;
	};
	const menuClick = function( w, label ) { click( w, all( all( w.main, '.menu' )[0], '[role="menuitem"]' ).find( function( item ) { return textOf( item ) === label } ) ); };

	let step = await stepper( { state : 'open' } );
	click( step, all( step.main, '#primary' )[0] );
	await sendYes( step );
	check( 'Versenden releases the document with the mail: crm-documents/release { id: "11", mail: true }, says how many accounts were mailed, and reads the document again', step.steps[0][0] === 'release' && JSON.stringify( step.steps[0][1] ) === '{"id":"11","mail":true}' && step.toast.textContent === 'Versendet: freigegeben und 2 E-Mail(s) an die Konten des Kunden.'
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
	const remindBox = step.dialogs[0];
	check( 'Zahlungserinnerung senden asks first, in a dialog with a box "Erinnerung per E-Mail senden" - a real checkbox, ticked at first - and posts nothing yet', remindBox !== undefined && all( remindBox, 'input' )[0].type === 'checkbox' && all( remindBox, 'input' )[0].checked === true
		&& textOf( all( remindBox, 'label' )[0] ).indexOf( texts['remind.notify'] ) !== -1 && texts['remind.notify'] === 'Erinnerung per E-Mail senden' && step.steps.length === 0 );
	click( step, all( remindBox, 'button' )[1] );
	await settle();
	check( '...the yes posts crm-documents/remind with notify true and says how many accounts were mailed', JSON.stringify( step.steps[0] ) === '["remind",{"id":"11","notify":true}]' && step.toast.textContent === 'Zahlungserinnerung gesendet: 2 Mail(s).' );
	menuClick( step, 'Zahlungserinnerung senden' );
	check( '...and a dialog starts at its default again: ticked, whatever the one before was left at', all( step.dialogs[1], 'input' )[0].checked === true );
	check( '...Strg+S with a dialog over the page posts nothing and is left to the browser, as for every other dialog', press( step, 's', null, { ctrlKey : true } ) === false && step.steps.length === 1 );
	all( step.dialogs[1], 'input' )[0].checked = false;
	click( step, all( step.dialogs[1], 'button' )[1] );
	await settle();
	check( '...unticked it posts notify false, and the toast says that the reminder is noted, without a mail', JSON.stringify( step.steps[1] ) === '["remind",{"id":"11","notify":false}]' && step.toast.textContent === texts['remind.noted'] );
	menuClick( step, 'Zahlungserinnerung senden' );
	click( step, all( step.dialogs[2], 'button' )[0] );
	await settle();
	check( '...the no posts nothing', step.steps.length === 2 );
	step = await stepper( { state : 'sent', released : true } );
	menuClick( step, 'Freigabe zurücknehmen' );
	await settle();
	check( 'Freigabe zurücknehmen posts crm-documents/unrelease', JSON.stringify( step.steps[0] ) === '["unrelease",{"id":"11"}]' && step.toast.textContent === 'Die Freigabe ist zurückgenommen.' );

	// Kunden hierher einladen, from the menu of a document that is in the portal
	const hereAccounts = [
		{ id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'lena@nordlicht.example', name : 'Lena Brandt', active : true, login : '2026-10-30T10:00:00Z' },
		{ id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'tom@nordlicht.example', name : '', active : true, login : null },
		{ id : 'cccccccccccc', gone : false, state : 'off', email : 'alt@nordlicht.example', name : 'Alt', active : false, login : '2026-01-02T10:00:00Z' },
		{ id : 'dddddddddddd', gone : true, state : 'gone' },
	];
	const hereSent = [];
	const hereAnswers = function( refuse ) {
		return {
			'crm-clients/accounts' : function() { return [ 200, { accounts : hereAccounts } ] },
			'crm-clients/account-resend' : function( data ) { hereSent.push( data ); return data.account === refuse ? [ 429, { error : 'x', code : 'crm_clients_ratelimit', params : [], field : '' } ] : [ 200, { sent : true, kind : 'login' } ] },
		};
	};
	const hereTargets = {};
	const hereYes = function( w, index ) { click( w, all( w.dialogs[index], 'button' ).pop() ) };
	const hereNo = function( w, index ) { click( w, all( w.dialogs[index], 'button' )[0] ) };

	for( const [ label, over ] of Object.entries( {
		'an invoice without a project' : { state : 'sent', released : true },
		'an invoice of a project' : { state : 'sent', released : true, project : '00001', project_title : 'Album 2027' },
		'an offer of a project' : { id : 13, category : 'offer', state : 'sent', released : true, project : 'Album_2027-b', project_title : 'Album', due_date : null },
		'an offer without a project' : { id : 14, category : 'offer', state : 'open', released : true, due_date : null },
	} ) ) {
		hereSent.length = 0;
		const here = await stepper( over, hereAnswers( '' ) );
		menuClick( here, texts['here.menu'] );
		await settle();
		hereYes( here, 0 );
		await settle();
		hereTargets[label] = Array.from( new Set( hereSent.map( function( post ) { return post.target } ) ) ).join( '|' );
	}

	check( 'a document that is in the portal has Kunden hierher einladen in its menu, before the way back: the link leads to its card on the page of the project (Angebot for an offer, Belege for another document) or to its row in the list of documents', hereTargets['an invoice without a project'] === '/portal/belege#beleg-11' && hereTargets['an invoice of a project'] === '/portal/projekte?projekt=00001&tab=belege#beleg-11'
		&& hereTargets['an offer of a project'] === '/portal/projekte?projekt=Album_2027-b&tab=angebot#angebot-13' && hereTargets['an offer without a project'] === '/portal/belege#beleg-14' );
	hereSent.length = 0;
	const hereBox = await stepper( { state : 'sent', released : true }, hereAnswers( '' ) );
	menuClick( hereBox, texts['here.menu'] );
	await settle();
	const hereDialog = hereBox.dialogs[0];
	check( '...it asks crm-clients/accounts for the client of the document, then offers the accounts that are on - one tick for each, all ticked, the word of the link beside the name: an invitation for an account that never signed in, a link to sign in for the other - and the page the link leads to', JSON.stringify( hereBox.last( 'crm-clients/accounts' ).data ) === '{"id":"1"}'
		&& all( hereDialog, 'input' ).map( function( input ) { return input.type+ ':'+ input.checked } ).join() === 'checkbox:true,checkbox:true' && all( hereDialog, 'label' ).map( textOf ).join( '|' ) === ' Lena Brandt · '+ texts['here.login']+ '| tom@nordlicht.example · '+ texts['here.invite'] && textOf( all( hereDialog, 'h2' )[0] ) === texts['here.title']
		&& textOf( all( hereDialog, 'p' )[0] ) === texts['here.text'].replace( '%s', texts['here.page-document'] ) && hereSent.length === 0 );
	hereNo( hereBox, 0 );
	await settle();
	check( '...the no sends nothing', hereSent.length === 0 && hereBox.toast.textContent === '' );
	menuClick( hereBox, texts['here.menu'] );
	await settle();
	all( hereBox.dialogs[1], 'input' )[1].checked = false;
	hereYes( hereBox, 1 );
	await settle();
	check( '...the yes sends one link to each account that stays ticked, by the action of the card of the client with the target, and the toast says how many: one', JSON.stringify( hereSent ) === '[{"id":"1","account":"aaaaaaaaaaaa","target":"/portal/belege#beleg-11"}]' && hereBox.toast.textContent === texts['here.sent-one'] );
	hereSent.length = 0;
	menuClick( hereBox, texts['here.menu'] );
	await settle();
	hereYes( hereBox, 2 );
	await settle();
	check( '...two ticked are two links, in the order of the accounts: the toast says two', hereSent.map( function( post ) { return post.account } ).join() === 'aaaaaaaaaaaa,bbbbbbbbbbbb' && hereBox.toast.textContent === texts['here.sent-many'].replace( '%s', '2' ) );
	hereSent.length = 0;
	menuClick( hereBox, texts['here.menu'] );
	await settle();
	all( hereBox.dialogs[3], 'input' ).forEach( function( input ) { input.checked = false } );
	hereYes( hereBox, 3 );
	await settle();
	check( '...none ticked sends nothing and says so', hereSent.length === 0 && hereBox.toast.textContent === texts['here.nobody'] && hereBox.toast.classList.contains( 'bad' ) === true );
	const hereRefused = await stepper( { state : 'sent', released : true }, hereAnswers( 'aaaaaaaaaaaa' ) );
	hereSent.length = 0;
	menuClick( hereRefused, texts['here.menu'] );
	await settle();
	hereYes( hereRefused, 0 );
	await settle();
	check( '...a link the server refuses is said with its sentence and does not stop the next: both are tried, the toast counts the one that went', hereSent.length === 2 && hereRefused.toast.textContent === texts['here.sent-one'] );
	const hereNone = await stepper( { state : 'sent', released : true }, { 'crm-clients/accounts' : function() { return [ 200, { accounts : [ hereAccounts[2], hereAccounts[3] ] } ] } } );
	menuClick( hereNone, texts['here.menu'] );
	await settle();
	check( '...a client with no account that is on is told so and nothing is asked', hereNone.dialogs.length === 0 && hereNone.toast.textContent === texts['here.none'] && hereNone.toast.classList.contains( 'bad' ) === true );
	const hereDraft = await stepper( { state : 'sent', released : false } );
	check( '...a document that is not in the portal has no such item', all( all( hereDraft.main, '.menu' )[0], '[role="menuitem"]' ).map( textOf ).indexOf( texts['here.menu'] ) === -1 );
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
	const signAgain = step.dialogs[3];
	check( 'Erinnern asks first: the box "Link per E-Mail an das Konto senden", ticked, and the sentence that the old link is dead', all( signAgain, 'input' )[0].type === 'checkbox' && all( signAgain, 'input' )[0].checked === true && textOf( all( signAgain, 'label' )[0] ).indexOf( texts['notify.link'] ) !== -1 && step.steps.length === 1
		&& textOf( all( signAgain, 'p' )[0] ) === texts['sign.remind-text'] );
	click( step, all( signAgain, 'button' )[1] );
	await settle();
	check( '...the yes posts crm-documents/sign-remind with notify true and says the link is sent again', JSON.stringify( step.steps[1] ) === '["sign-remind",{"id":"18","notify":true}]' && step.toast.textContent === 'Der Link ist noch einmal gesendet, mit neuer Frist.' );
	menuClick( step, 'Erinnern' );
	all( step.dialogs[4], 'input' )[0].checked = false;
	click( step, all( step.dialogs[4], 'button' )[1] );
	await settle();
	const shownLink = step.dialogs[5];
	check( '...unticked it posts notify false and shows the new link, once, in a dialog: a field nobody can type in, with the link, a button that copies it and the sentence that it is shown once',
		JSON.stringify( step.steps[2] ) === '["sign-remind",{"id":"18","notify":false}]' && step.toast.textContent === texts['sign.reminded-quiet'] && shownLink !== undefined && shownLink.open === true
		&& all( shownLink, '#dlg-link' )[0].value === 'https://example.org/club/.sign?t=AGAIN' && all( shownLink, '#dlg-link' )[0].readOnly === true && all( shownLink, '#dlg-link' )[0].getAttribute( 'readonly' ) !== null
		&& all( shownLink, '#dlg-link-copy' ).length === 1 && textOf( all( shownLink, '#dlg-link-copy' )[0] ) === texts['shown.copy'] && textOf( all( shownLink, 'p' ).pop() ) === texts['shown.once'] && all( shownLink, 'button' ).map( textOf ).join( '|' ) === 'Kopieren|Schließen' );
	step.win.navigator = { clipboard : { writeText : function( text ) { step.copied = text; return { then : function( done ) { done() } } } } };
	click( step, all( shownLink, '#dlg-link-copy' )[0] );
	check( '...the copy button hands the link to the clipboard where the browser has one, and says so', step.copied === 'https://example.org/club/.sign?t=AGAIN' && textOf( all( shownLink, '#dlg-link-state' )[0] ) === texts['shown.copied'] );
	delete step.win.navigator;
	click( step, all( shownLink, '#dlg-link-copy' )[0] );
	check( '...and where it has none the field is selected and the sentence says to copy it by hand', textOf( all( shownLink, '#dlg-link-state' )[0] ) === texts['shown.selected'] );
	click( step, all( shownLink, 'button' ).pop() );
	await settle();
	check( '...closing the dialog takes the link away: the dialog is gone, nothing of it is left on the page', step.dialogRoot.children.length === 0 && step.main.textContent.indexOf( 'AGAIN' ) === -1 && step.dialogRoot.textContent.indexOf( 'AGAIN' ) === -1 );

	const accounts = [ { id : 'aaaaaaaaaaaa', email : 'anna@example.org', name : 'Anna', active : true }, { id : 'bbbbbbbbbbbb', email : 'olga@example.org', name : 'Olga', active : false } ];
	const signing = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : accounts, sign_days : 21 }, { 'crm-documents/sign' : function() { return [ 200, { document : full( {} ), mailed : true } ] } } );
	click( signing, all( signing.main, '#primary' )[0] );
	const ask = signing.dialogs[0];
	check( 'Unterschrift anfordern asks which account and for how many days: the accounts that are on, the days of Sign\'s setting', all( ask, 'option' ).length === 1 && textOf( all( ask, 'option' )[0] ) === 'Anna <anna@example.org>' && all( ask, 'input' )[0].value === '21' && all( ask, 'input' )[0].type === 'number' );
	check( '...and has the box for the mail too - ticked at first, after the days', all( ask, 'input' )[1].type === 'checkbox' && all( ask, 'input' )[1].checked === true && textOf( all( ask, 'label' ).pop() ).indexOf( texts['notify.link'] ) !== -1 );
	all( ask, 'input' )[0].value = '7';
	click( signing, all( ask, 'button' )[1] );
	await settle();
	check( '...and posts crm-documents/sign { id, account, days, notify }', JSON.stringify( signing.last( 'crm-documents/sign' ).data ) === '{"id":"18","account":"aaaaaaaaaaaa","days":"7","notify":true}' && signing.toast.textContent === 'Die Unterschrift ist angefordert, das Konto hat eine Mail bekommen.' );
	const nobody = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : [ accounts[1] ] } );
	click( nobody, all( nobody.main, '#primary' )[0] );
	check( '...where no account is on it says so and posts nothing', nobody.dialogs.length === 0 && nobody.toast.textContent.indexOf( 'Der Kunde hat kein aktives Konto.' ) === 0 && nobody.calls.filter( function( c ) { return c.action === 'crm-documents/sign' } ).length === 0 );

	// an account that holds the permission of the CRM alone has no box for a link without the mail, and says why: the link goes by mail
	const plain = await opened( { id : 18, category : 'contract', state : 'open', released : true, lines : [], title : 'V', number : '', due_date : null }, { accounts : accounts, sign_days : 21 }, { 'crm-documents/sign' : function() { return [ 200, { document : full( {} ), mailed : true } ] } }, { crm : true } );
	click( plain, all( plain.main, '#primary' )[0] );
	check( 'without the permission of Sign the dialog to ask for a signature has no box for the mail and says why - the account and the days are there', all( plain.dialogs[0], 'input' ).every( function( i ) { return i.type !== 'checkbox' } ) && all( plain.dialogs[0], '#dlg-notify' ).length === 0 && textOf( plain.dialogs[0] ).indexOf( texts['notify.noperm-sign'] ) !== -1 && all( plain.dialogs[0], 'select' ).length === 1 && textOf( plain.dialogs[0] ).indexOf( 'Häkchen' ) === -1 && textOf( plain.dialogs[0] ).indexOf( texts['sign.ask-text'] ) === -1 );
	click( plain, all( plain.dialogs[0], 'button' )[1] );
	await settle();
	check( '...and posts notify true: the link goes by mail', plain.last( 'crm-documents/sign' ).data.notify === true );
	const plainAgain = await opened( { id : 18, category : 'contract', state : 'open', sign_state : 'viewed', released : true, lines : [], title : 'V', number : '', due_date : null }, {}, {}, { crm : true } );
	menuClick( plainAgain, 'Erinnern' );
	check( 'the dialog of the link again has no box either, with the sentence, and posts notify true', all( plainAgain.dialogs[0], '#dlg-notify' ).length === 0 && textOf( plainAgain.dialogs[0] ).indexOf( texts['notify.noperm-sign'] ) !== -1 && textOf( plainAgain.dialogs[0] ).indexOf( 'Häkchen' ) === -1 && ( click( plainAgain, all( plainAgain.dialogs[0], 'button' )[1] ), await settle(), plainAgain.last( 'crm-documents/sign-remind' ).data.notify === true ) );

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
	await sendYes( portal );
	check( '...Freigeben without the tick posts mail: false and says it was released without a mail', JSON.stringify( portal.last( 'crm-documents/release' ).data ) === '{"id":"11","mail":false}' && portal.toast.textContent === 'Freigegeben, ohne E-Mail.' );

	// The release of an invoice of a project whose download is off: a dialog with the box that switches it on, ticked
	const lockedProject = [ { id : '00001', title : 'Album 2027', done : false, download : false } ];
	const openProject = [ { id : '00001', title : 'Album 2027', done : false, download : true } ];
	const releaseT = function( key, value ) { return texts[key].replace( '%s', String( value ) ) };
	const stepperWith = async function( over, projects, answers ) {
		const calls = [];
		const w = await opened( over, { projects : projects }, Object.assign( {
			'crm-documents/release' : function( data ) { calls.push( [ 'release', data ] ); return [ 200, { document : full( over ), mailed : 2, mail : true, download : data.download === true } ] },
		}, answers || {} ) );
		w.steps = calls;
		return w;
	};
	let lockedStep = await stepperWith( { state : 'open', project : '00001', project_title : 'Album 2027' }, lockedProject );
	click( lockedStep, all( lockedStep.main, '#primary' )[0] );
	await settle();
	check( 'Versenden of an invoice whose project has the download off asks first: a dialog with the box "Download der Projektdateien freischalten", ticked - and nothing is posted before the answer', lockedStep.dialogs.length === 1 && lockedStep.steps.length === 0
		&& all( lockedStep.dialogs[0], '#dlg-download' ).length === 1 && all( lockedStep.dialogs[0], '#dlg-download' )[0].type === 'checkbox' && all( lockedStep.dialogs[0], '#dlg-download' )[0].checked === true && textOf( all( lockedStep.dialogs[0], 'label' ).find( function( one ) { return all( one, '#dlg-download' ).length === 1 } ) ).trim() === texts['release.download'] && all( lockedStep.dialogs[0], '#dlg-mail' )[0].checked === true );
	click( lockedStep, all( lockedStep.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...the yes posts the release with the mail and download true, in one action, and the toast says that the download is on', lockedStep.steps.length === 1 && JSON.stringify( lockedStep.steps[0][1] ) === '{"id":"11","mail":true,"download":true}' && lockedStep.toast.textContent === releaseT( 'send.done', 2 ) + ' ' + texts['release.download-on'] );
	lockedStep = await stepperWith( { state : 'open', project : '00001', project_title : 'Album 2027' }, lockedProject );
	click( lockedStep, all( lockedStep.main, '#primary' )[0] );
	all( lockedStep.dialogs[0], '#dlg-download' )[0].checked = false;
	click( lockedStep, all( lockedStep.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...unticked it posts the release without the word download, and the toast does not speak of it', JSON.stringify( lockedStep.steps[0][1] ) === '{"id":"11","mail":true}' && lockedStep.toast.textContent === releaseT( 'send.done', 2 ) );
	lockedStep = await stepperWith( { state : 'open', project : '00001', project_title : 'Album 2027' }, lockedProject );
	click( lockedStep, all( lockedStep.main, '#primary' )[0] );
	click( lockedStep, all( lockedStep.dialogs[0], 'button' )[0] );
	await settle();
	check( '...the no posts nothing and releases nothing', lockedStep.steps.length === 0 && lockedStep.all( 'crm-documents/get' ).length === 1 );
	const noBox = [
		[ 'a project whose download is on', { state : 'open', project : '00001' }, openProject ],
		[ 'an invoice without a project', { state : 'open', project : '' }, lockedProject ],
		[ 'an offer of the locked project', { id : 13, category : 'offer', number : 'A-2026-0011', state : 'open', project : '00001', due_date : null }, lockedProject ],
	];

	for( const [ why, over, projects ] of noBox ) {
		const plainStep = await stepperWith( over, projects );
		click( plainStep, all( plainStep.main, '#primary' )[0] );
		check( 'Versenden of '+ why +' asks too, with the box of the mail and the sentence to the customer and no box of the download - nothing is posted before the answer', plainStep.dialogs.length === 1 && plainStep.steps.length === 0 && all( plainStep.dialogs[0], '#dlg-mail' ).length === 1 && all( plainStep.dialogs[0], '#dlg-note' ).length === 1 && all( plainStep.dialogs[0], '#dlg-download' ).length === 0 );
		await sendYes( plainStep );
		check( '...and its yes posts the release with the mail and no download, and no word of a note', plainStep.steps.length === 1 && JSON.stringify( plainStep.steps[0][1] ) === JSON.stringify( { id : String( over.id ?? 11 ), mail : true } ) );
	}

	// ...and the tab Portal & Versand, for a document that is not released yet
	const lockedPortal = await stepperWith( { state : 'open', project : '00001' }, lockedProject );
	await tabClick( lockedPortal, 'Portal & Versand' );
	check( 'the tab Portal & Versand has the same box beside the tick of the mail, ticked, for an invoice of a project whose download is off - and none for a project whose download is on, an offer or an invoice without a project', all( body( lockedPortal ), '#release-download' ).length === 1 && all( body( lockedPortal ), '#release-download' )[0].checked === true && all( body( lockedPortal ), '#release-download' )[0].type === 'checkbox'
		&& all( body( await stepperWith( { state : 'open', project : '00001' }, openProject ) ), '#release-download' ).length === 0 && all( body( await stepperWith( { state : 'open', project : '' }, lockedProject ) ), '#release-download' ).length === 0 );
	click( lockedPortal, all( body( lockedPortal ), '#release' )[0] );
	await sendYes( lockedPortal );
	check( '...Freigeben posts mail and download true; with the box unticked the word is not posted', JSON.stringify( lockedPortal.steps[0][1] ) === '{"id":"11","mail":true,"download":true}' && lockedPortal.toast.textContent.indexOf( texts['release.download-on'] ) !== -1 );
	const unticked = await stepperWith( { state : 'open', project : '00001' }, lockedProject );
	await tabClick( unticked, 'Portal & Versand' );
	all( body( unticked ), '#release-download' )[0].checked = false;
	click( unticked, all( body( unticked ), '#release' )[0] );
	check( '...and the dialog it opens starts with the boxes of the tab: the download unticked here is unticked there', all( unticked.dialogs[0], '#dlg-download' )[0].checked === false && all( unticked.dialogs[0], '#dlg-mail' )[0].checked === true );
	await sendYes( unticked );
	check( '...unticked', JSON.stringify( unticked.steps[0][1] ) === '{"id":"11","mail":true}' );

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
		lines : [ { id : 1, document_id : 16, sort : 1, title : 'Editing', description : 'Staffel 3', qty : 10000, unit : 'Std.', unit_code : 'HUR', price_net : 6000, vat_rate : 1900, amount_net : 60000 }, { id : 2, document_id : 16, sort : 2, title : 'Mastering', description : '', qty : 3000, unit : 'Song', unit_code : '', price_net : 11000, vat_rate : 700, amount_net : 33000 } ],
		buyer_reference : 'PO-4711', erechnung : { xml : false, lacks : [ { key : 'line_unit', text : 'Mindestens eine Position hat keine Einheit: Wähle sie in der Zeile aus.' } ] },
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
	type( field( edit, 'f-due_date' ), '', true );
	await settle();
	check( 'clearing the due day saves it empty - the server keeps none - and the field stays empty; the date can be typed again', edit.saves.length > 0 && edit.saves[edit.saves.length - 1].due_date === '' && field( edit, 'f-due_date' ).value === '' && edit.app.state.draft.due_date === '' );
	type( field( edit, 'f-due_date' ), '2026-11-17', true );
	await settle();
	check( '...and typing it again saves the day', edit.saves[edit.saves.length - 1].due_date === '2026-11-17' );
	check( '...the regular mode has the rate per line and the reverse charge', field( edit, 'f-reverse_charge' ) !== undefined && all( lineRows( edit )[0], 'select.rate' ).length === 1 && all( lineRows( edit )[0], 'select.rate' )[0].value === '1900' && all( all( lineRows( edit )[0], 'select.rate' )[0], 'option' ).map( textOf ).join() === '19 %,7 %,0 %' );
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
	const rateSelect = all( lineRows( edit )[0], 'select.rate' )[0];
	type( rateSelect, '0' );
	await settle();
	check( 'a rate chosen redraws the VAT: 0 % on the first line leaves only the 7 % row', totalsText().indexOf( 'zzgl. 19 %' ) === -1 && totalsText().indexOf( 'zzgl. 0 % USt. auf 20,00'+ NBSP+ '€0,00'+ NBSP+ '€' ) !== -1 );
	const reverse = field( edit, 'f-reverse_charge' );
	reverse.checked = true;
	fire( reverse, 'change', { target : reverse } );
	await settle();
	check( 'the reverse charge takes the rates off: no VAT row, the gross is the net, the rate selects are off and show 0 %', totalsText().indexOf( 'zzgl.' ) === -1 && all( lineRows( edit )[0], 'select.rate' )[0].disabled === true && all( lineRows( edit )[0], 'select.rate' )[0].value === '0' && totalsText().indexOf( 'Reverse-Charge' ) !== -1 );
	reverse.checked = false;
	fire( reverse, 'change', { target : reverse } );

	// the catalogue
	edit = await editing();
	let adder = field( edit, 'additem' );
	check( 'the row "Position suchen" has a datalist of the catalogue: the title as the value, the unit and the price as the text', all( edit.main, 'datalist' )[0].children.map( function( o ) { return o.value+ ':'+ o.textContent } ).join( '|' ) === 'Mixing:Std. · 90,00'+ NBSP+ '€|Mastering:Song · 110,00'+ NBSP+ '€' && adder.getAttribute( 'list' ) === 'items' );
	adder.value = 'mixing';
	fire( adder, 'keydown', { key : 'Enter', target : adder, preventDefault : function() {} } );
	check( 'Enter on the title of an item (the case does not matter) inserts it as a line: the title, the first line and all of the description, the unit, the price, the rate of the item, a quantity of 1', lineRows( edit ).length === 3 && all( lineRows( edit )[2], 'input' ).map( function( i ) { return i.value } ).join( '|' ) === 'Mixing|1|Std.|90,00' && all( lineRows( edit )[2], 'textarea' )[0].value === 'Mischung eines Songs\nbis 48 Spuren'
		&& all( lineRows( edit )[2], 'select.rate' )[0].value === '1900' && field( edit, 'additem' ).value === '' && field( edit, 'additem' ).focused === true );
	check( '...and the sums have it', totalsText().indexOf( 'Netto1.020,00'+ NBSP+ '€' ) === 0 );
	adder = field( edit, 'additem' );
	adder.value = 'Mastering';
	fire( adder, 'change', { target : adder } );
	check( 'a choice from the list (a change with the whole title) inserts too, with the rate of that item: 7 %', lineRows( edit ).length === 4 && all( lineRows( edit )[3], 'select.rate' )[0].value === '700' );
	adder = field( edit, 'additem' );
	adder.value = 'Mast';
	fire( adder, 'change', { target : adder } );
	check( 'a half title inserts nothing: leaving the field is no Enter', lineRows( edit ).length === 4 );
	click( edit, all( lineRows( edit )[2], '.del' )[0] );
	check( 'the cross removes the line, and the sums follow', lineRows( edit ).length === 3 && totalsText().indexOf( 'Netto1.020,00'+ NBSP+ '€' ) === 0 === false && edit.app.state.draft.lines.length === 3 );
	click( edit, field( edit, 'addline' ) );
	check( 'an empty line is added at the end with a quantity of 1 and the focus in its title', lineRows( edit ).length === 4 && all( lineRows( edit )[3], 'input' )[0].value === '' && all( lineRows( edit )[3], 'input' )[1].value === '1' && all( lineRows( edit )[3], 'input' )[0].focused === true );

	// the electronic invoice in the editor: the unit of each line, the checklist, the buyer reference
	edit = await editing();
	const xrUnit = function( w, index ) { return all( lineRows( w )[index], 'select.unit-code' )[0] };
	check( 'every line has a select of the unit of the electronic invoice, in the cell of the unit: "Einheit wählen" and the nine codes by their words, the code of the line chosen - the empty one for a line that has none', lineRows( edit ).every( function( r ) { return all( r, 'select.unit-code' ).length === 1 && all( all( r, 'td' )[3], 'select.unit-code' ).length === 1 } )
		&& all( xrUnit( edit, 0 ), 'option' ).map( function( o ) { return o.value+ ':'+ textOf( o ) } ).join( '|' ) === [ '' ].concat( [ 'H87', 'HUR', 'MIN', 'DAY', 'WEE', 'MON', 'LS', 'C62', 'KMT' ] ).map( function( code ) { return code+ ':'+ texts[code === '' ? 'unitcode.none' : 'unitcode.'+ code.toLowerCase()] } ).join( '|' ) && xrUnit( edit, 0 ).value === 'HUR' && xrUnit( edit, 1 ).value === ''
		&& xrUnit( edit, 0 ).getAttribute( 'aria-label' ) === texts['column.unit-code'] );
	edit.saves.length = 0;
	type( xrUnit( edit, 1 ), 'DAY' );
	await settle();
	check( '...choosing a code saves the draft with it, and the text of the unit is untouched', edit.saves.length >= 1 && edit.saves[edit.saves.length - 1].lines[1].unit_code === 'DAY' && edit.saves[edit.saves.length - 1].lines[1].unit === 'Song' && edit.app.state.draft.lines[1].unit_code === 'DAY' );
	let xrAdder = field( edit, 'additem' );
	xrAdder.value = 'mixing';
	fire( xrAdder, 'keydown', { key : 'Enter', target : xrAdder, preventDefault : function() {} } );
	xrAdder = field( edit, 'additem' );
	xrAdder.value = 'Mastering';
	fire( xrAdder, 'change', { target : xrAdder } );
	click( edit, field( edit, 'addline' ) );
	check( '...a line from an item has the code of the item (Mixing HUR, Mastering none), an empty line none - the person chooses it', xrUnit( edit, 2 ).value === 'HUR' && xrUnit( edit, 3 ).value === '' && xrUnit( edit, 4 ).value === '' );
	const sumsBox = field( edit, 'totals' );
	const xrList = all( sumsBox, '#erechnung' )[0];
	check( 'the sums box has the checklist of the electronic invoice: what is missing, in the sentences of the server, one line each - and the sentence that says the document can be finalised without it', xrList !== undefined && xrList.dataset.state === 'lacks' && all( xrList, 'li' ).map( textOf ).join( '|' ) === 'Mindestens eine Position hat keine Einheit: Wähle sie in der Zeile aus.'
		&& all( xrList, 'li' )[0].dataset.key === 'line_unit' && textOf( all( xrList, 'p' )[0] ) === texts['erechnung.lacks'] );
	const xrWhole = await editing( { erechnung : { xml : false, lacks : [] } } );
	check( '...where nothing is missing it says "E-Rechnung: vollständig"', all( field( xrWhole, 'totals' ), '#erechnung' )[0].dataset.state === 'complete' && textOf( all( field( xrWhole, 'totals' ), '#erechnung' )[0] ) === texts['erechnung.complete'] && all( all( field( xrWhole, 'totals' ), '#erechnung' )[0], 'li' ).length === 0 );
	const xrPrivate = await editing( { erechnung : null } );
	check( '...and for a client that gets none there is no checklist at all', all( field( xrPrivate, 'totals' ), '#erechnung' ).length === 0 && textOf( field( xrPrivate, 'totals' ) ).indexOf( texts['erechnung.complete'] ) === -1 && textOf( field( xrPrivate, 'totals' ) ).indexOf( texts['erechnung.lacks'] ) === -1 );
	const xrAfter = await editing( {}, { 'crm-documents/save' : function( data ) { return [ 200, { document : full( Object.assign( {}, draftDoc, { id : Number( data.id ), updated : '2026-11-03T14:30:00Z', erechnung : { xml : false, lacks : [] } } ) ) } ] } } );
	type( field( xrAfter, 'f-buyer_reference' ), 'Neu', true );
	await settle();
	check( 'the checklist is drawn again from the answer of every save: the line is gone when the server no longer misses it', all( field( xrAfter, 'totals' ), '#erechnung' )[0].dataset.state === 'complete' );
	const xrRef = await editing();
	check( 'the block Beleg of an invoice has the buyer reference, with the words of the card and "optional", filled with the draft\'s, 100 characters at most', textOf( all( field( xrRef, 'f-buyer_reference' ).parentNode.parentNode, 'dt' ).find( function( dt ) { return textOf( dt ).indexOf( 'Käuferreferenz' ) === 0 } ) ) === texts['clientfield.buyer_reference']+ ' ('+ texts['form.optional']+ ')' && field( xrRef, 'f-buyer_reference' ).value === 'PO-4711' && field( xrRef, 'f-buyer_reference' ).getAttribute( 'maxlength' ) === '100' );
	xrRef.saves.length = 0;
	type( field( xrRef, 'f-buyer_reference' ), 'Bestellung 5', true );
	await settle();
	check( '...it is posted with the draft, as typed', xrRef.saves.length >= 1 && xrRef.saves[xrRef.saves.length - 1].buyer_reference === 'Bestellung 5' );
	xrRef.saves.length = 0;
	type( field( xrRef, 'f-client_id' ), '2' );
	await settle();
	check( '...another client does not take the reference the person typed', field( xrRef, 'f-buyer_reference' ).value === 'Bestellung 5' && xrRef.saves.every( function( save ) { return save.buyer_reference === 'Bestellung 5' } ) );
	const xrFollow = await editing();
	type( field( xrFollow, 'f-client_id' ), '2' );
	await settle();
	check( '...but takes the reference of the client where the person typed none: the list of the clients has it, and the draft is saved with it, once', field( xrFollow, 'f-buyer_reference' ).value === 'JR-2' && xrFollow.app.state.draft.buyer_reference === 'JR-2' && xrFollow.saves.length === 1 && xrFollow.saves[0].buyer_reference === 'JR-2' );
	const xrOffer = await editing( { category : 'offer', due_date : null, valid_until : '2026-12-01', erechnung : null } );
	check( 'an offer has neither the buyer reference nor the checklist, and does not post the reference', field( xrOffer, 'f-buyer_reference' ) === undefined && all( xrOffer.main, '#erechnung' ).length === 0 );
	xrOffer.saves.length = 0;
	type( field( xrOffer, 'f-date' ), '2026-11-04', true );
	await settle();
	check( '...the offer is saved without it', xrOffer.saves.length >= 1 && 'buyer_reference' in xrOffer.saves[xrOffer.saves.length - 1] === false );

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
		date : '2026-11-03', template_key : 'rechnung', inputs : { intro : 'Zeile 1\nZeile 2' }, project : '', id : '16', client_id : '2', period_from : '2026-10-01', period_to : '2026-10-31', reverse_charge : false, due_date : '2026-11-17',
		lines : [ { title : 'Editing', description : 'Staffel 3', unit : 'Std.', unit_code : 'HUR', qty : '10', price : '60,00', vat_rate : 1900 }, { title : 'Mastering', description : '', unit : 'Song', unit_code : '', qty : '3', price : '110,00', vat_rate : 700 } ], buyer_reference : 'JR-2' } ) );
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
	const labelOf = function( w, id ) { return findAll( w.main, function( node ) { return node.tagName === 'LABEL' && node.getAttribute( 'for' ) === id } )[0] };
	check( 'the second date says it is optional: the label of the due day and of the validity day is the word and the word optional in brackets - and the first date does not', textOf( labelOf( fresh, 'f-due_date' ) ) === texts['field.due']+ ' ('+ texts['form.optional']+ ')'
		&& textOf( labelOf( offerNew, 'f-valid_until' ) ) === texts['field.valid']+ ' ('+ texts['form.optional']+ ')' && textOf( labelOf( fresh, 'f-date' ) ) === texts['field.date'] );
	const noDays = { 'crm-documents/list' : function() { return [ 200, { documents : [], years : [ 2026 ], clients : clients, settings : Object.assign( {}, settings, { payment_days : '0', offer_days : '0' } ), items : items, offers : offers } ] } };
	const invoiceNoDays = await editing( {}, noDays, '#neu-invoice' );
	const offerNoDays = await editing( {}, noDays, '#neu-offer' );
	check( 'a setting of 0 days prefills nothing: the new invoice has no due day and the new offer no validity day - while the settings of 14 and 30 do', field( invoiceNoDays, 'f-due_date' ).value === '' && field( offerNoDays, 'f-valid_until' ).value === '' && field( fresh, 'f-due_date' ).value !== '' && field( offerNew, 'f-valid_until' ).value !== ''
		&& invoiceNoDays.app.state.draft.due_date === '' && offerNoDays.app.state.draft.valid_until === '' );
	type( field( invoiceNoDays, 'f-client_id' ), '1', true );
	await settle();
	check( '...and the first save posts the empty day as it is: the server reads it as none', invoiceNoDays.saves.length === 1 && invoiceNoDays.saves[0].due_date === '' );
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
	// The template a draft starts with
	const tplOne = [ templates[0] ];
	const thisYear = String( new Date().getFullYear() );
	const tplWorld = function( now, before, standard, hash, over, only ) {
		return editing( over || {}, {
			'crm-documents/list' : function( data ) { return [ 200, { documents : data.year === thisYear ? now : ( data.year === String( Number( thisYear ) - 1 ) ? before : [] ), years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
			'crm-documents/templates' : function( data ) { return [ 200, { templates : only === true ? tplOne : templates, standard : standard, offers : offers, category : data.category } ] },
		}, hash );
	};
	const tplMade = [
		row( { id : 20, category : 'invoice', client_id : 2, template_key : 'vertrag' } ), row( { id : 21, category : 'invoice', client_id : 1, template_key : 'rechnung' } ), row( { id : 22, category : 'credit', client_id : 1, template_key : 'vertrag' } ),
		row( { id : 23, category : 'invoice', client_id : 2, template_key : 'weg' } ), row( { id : 24, category : 'invoice', client_id : 1, template_key : '' } ),
	];
	const tplSolo = await tplWorld( tplMade, [], 'vertrag', '#neu-invoice', {}, true );
	const tplClient1 = await tplWorld( tplMade, [], 'vertrag', '#neu-invoice-k1' );
	const tplClient2 = await tplWorld( tplMade, [], 'rechnung', '#neu-invoice-k2' );
	const tplStandard = await tplWorld( tplMade, [], 'vertrag', '#neu-invoice-k3' );
	const tplNobody = await tplWorld( tplMade, [], 'rechnung', '#neu-invoice' );
	const tplNothing = await tplWorld( tplMade, [], '', '#neu-invoice-k3' );
	const tplStale = await tplWorld( tplMade, [], 'weg', '#neu-invoice-k3' );
	check( 'a new draft starts with the only template of its kind; with several, with the one the newest document of the same kind and client was made with - a credit note is not an invoice, and a key that is gone is none -; else with the template Einrichten made for the kind; else with none',
		field( tplSolo, 'f-template_key' ).value === 'rechnung' && tplSolo.app.state.draft.template_key === 'rechnung' && field( tplClient1, 'f-template_key' ).value === 'rechnung' && field( tplClient2, 'f-template_key' ).value === 'vertrag'
		&& field( tplStandard, 'f-template_key' ).value === 'vertrag' && field( tplNobody, 'f-template_key' ).value === 'rechnung' && field( tplNothing, 'f-template_key' ).value === '' && field( tplStale, 'f-template_key' ).value === '' );
	check( '...the choice is the draft\'s own from the start, and the inputs of the template are drawn', tplClient1.app.state.draft.template_key === 'rechnung' && field( tplClient1, 'f-inputs.intro' ) !== undefined );
	const tplPair = [ row( { id : 30, category : 'invoice', client_id : 2, template_key : 'vertrag' } ), row( { id : 31, category : 'invoice', client_id : 2, template_key : 'rechnung' } ) ];
	const tplNewestLast = await tplWorld( tplPair, [], '', '#neu-invoice-k2' );
	const tplNewestFirst = await tplWorld( tplPair.slice().reverse(), [], '', '#neu-invoice-k2' );
	check( 'of two documents of one client with other templates the newer one decides, whatever the order of the list', field( tplNewestLast, 'f-template_key' ).value === 'rechnung' && field( tplNewestFirst, 'f-template_key' ).value === 'rechnung' );
	const tplJanuary = await tplWorld( [ row( { id : 5, category : 'invoice', client_id : 2, template_key : '' } ) ], [ row( { id : 4, category : 'invoice', client_id : 2, template_key : 'vertrag' } ) ], 'rechnung', '#neu-invoice-k2' );
	const tplYear = await tplWorld( [ row( { id : 6, category : 'invoice', client_id : 2, template_key : 'rechnung' } ) ], [ row( { id : 4, category : 'invoice', client_id : 2, template_key : 'vertrag' } ) ], 'vertrag', '#neu-invoice-k2' );
	check( 'where no document of the year has a template the year before is looked through, so that January has a history - and where one has, the year before is not asked for', field( tplJanuary, 'f-template_key' ).value === 'vertrag' && tplJanuary.all( 'crm-documents/list' ).filter( function( call ) { return call.data.year !== undefined } ).length === 2
		&& field( tplYear, 'f-template_key' ).value === 'rechnung' && tplYear.all( 'crm-documents/list' ).filter( function( call ) { return call.data.year !== undefined } ).length === 1 );
	const tplFrom = await tplWorld( tplMade, [], 'vertrag', '#entwurf-16', { template_key : '', inputs : {}, category : 'invoice', client_id : 1 } );
	const tplFromCredit = await tplWorld( tplMade, [], 'rechnung', '#entwurf-16', { template_key : '', inputs : {}, category : 'credit', client_id : 1 } );
	const tplFromNothing = await tplWorld( [], [], 'rechnung', '#entwurf-16', { template_key : '', inputs : {}, category : 'invoice', client_id : 1 } );
	const tplKept = await tplWorld( tplMade, [], 'vertrag', '#entwurf-16', { template_key : 'vertrag', category : 'invoice', client_id : 1 } );
	check( 'a draft that came from another document and has none yet starts as a new one of its kind and client does - an invoice with the template of the earlier invoice of its client, a credit note not with that of an invoice, one with no history with the template of the kind - and is marked as changed, so that Festschreiben stores it first',
		field( tplFrom, 'f-template_key' ).value === 'rechnung' && tplFrom.app.state.draft.dirty === true && field( tplFromCredit, 'f-template_key' ).value === 'vertrag' && field( tplFromNothing, 'f-template_key' ).value === 'rechnung' && tplFromNothing.app.state.draft.dirty === true );
	check( '...a draft that has a template keeps it, and nothing is asked for it', field( tplKept, 'f-template_key' ).value === 'vertrag' && tplKept.app.state.draft.dirty === false && tplKept.all( 'crm-documents/templates' ).length === 0 );

	const bareClients = clients.map( function( one ) { return Object.assign( {}, one, { bare : one.id === 1 } ) } );
	const bareDraft = await editing( {}, { 'crm-documents/get' : function() { return [ 200, view( draftDoc, { clients : bareClients } ) ] } } );
	check( 'the box of the client in the draft names what it shows: the address of a client with neither company nor name is labelled E-Mail, the title of any other Name', textOf( field( bareDraft, 'client-box' ) ).indexOf( texts['client.email']+ clients[0].title ) !== -1 && textOf( field( edit, 'client-box' ) ).indexOf( texts['client.name']+ clients[0].title ) !== -1 );
	// a client that has not given its address yet: the draft is written and seen, not finalised
	const unreadyClients = clients.map( function( one ) { return Object.assign( {}, one, { ready : false } ) } );
	const unready = await editing( {}, {
		'crm-documents/list' : function( data ) { return [ 200, { documents : [], years : [ 2026 ], clients : unreadyClients, settings : settings, items : items, offers : offers } ] },
		'crm-clients/get' : function( data ) { return [ 200, { client : Object.assign( {}, client, { id : Number( data.id ), ready : false } ), accounts : [] } ] },
	} );
	check( 'while the client of an invoice is not ready for it the button Festschreiben is off and the sentence stands over the draft - the draft is saved and seen all the same: Speichern and the preview are on', field( unready, 'finalise' ).disabled === true && field( unready, 'finalise-unready' ).hidden === false && textOf( field( unready, 'finalise-unready' ) ) === texts['finalise.unready'] && texts['finalise.unready'] !== ''
		&& field( unready, 'save' ).disabled !== true && all( all( unready.main, '.menu' )[0], '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'PDF-Vorschau|Entwurf löschen' );
	check( 'a client that is ready leaves the button on and the sentence hidden', field( edit, 'finalise' ).disabled !== true && field( edit, 'finalise-unready' ).hidden === true );
	const readyAfter = await editing( {}, {
		'crm-documents/list' : function( data ) { return [ 200, { documents : [], years : [ 2026 ], clients : unreadyClients, settings : settings, items : items, offers : offers } ] },
	} );
	check( '...and so does one whose list said no and whose card says yes: the card is the later word', field( readyAfter, 'finalise' ).disabled === false && field( readyAfter, 'finalise-unready' ).hidden === true );
	const unreadyContract = await editing( {}, {
		'crm-documents/list' : function( data ) { return [ 200, { documents : [], years : [ 2026 ], clients : unreadyClients, settings : settings, items : items, offers : offers } ] },
		'crm-clients/get' : function( data ) { return [ 200, { client : Object.assign( {}, client, { id : Number( data.id ), ready : false } ), accounts : [] } ] },
	}, '#neu-contract-13' );
	check( 'a contract, the Datenschutz and an information do not wait for the address: the button is on and the sentence hidden', field( unreadyContract, 'finalise' ).disabled === false && field( unreadyContract, 'finalise-unready' ).hidden === true );

	// small business: no rate, no reverse charge
	const plainVat = await editing( {}, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { vat_mode : 'small_business' } ), { settings : smallBusiness } ) ] } } );
	check( 'a draft without VAT has no rate select and no reverse charge, and the totals say why', all( plainVat.main, 'select.rate' ).length === 0 && field( plainVat, 'f-reverse_charge' ) === undefined && textOf( field( plainVat, 'totals' ) ).indexOf( 'Kleinunternehmer (§ 19 UStG)' ) !== -1 && textOf( field( plainVat, 'totals' ) ).indexOf( 'USt. auf' ) === -1 );


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
	await sendYes( nobodyReached );
	check( 'Versenden for a client with no account releases and says there was no mail - it does not claim one: the sentence of send.no-account, as a plain toast', nobodyReached.toast.textContent === texts['send.no-account'] && nobodyReached.toast.classList.contains( 'bad' ) === false && nobodyReached.toast.textContent !== texts['send.done'].replace( '%s', '0' ) );

	const withAccount = await opened( { state : 'open' }, { accounts : [ reach[0] ] }, { 'crm-documents/release' : function() { return [ 200, { document : full( { state : 'open', released : true } ), mailed : 0, mail : true } ] } } );
	click( withAccount, all( withAccount.main, '#primary' )[0] );
	await sendYes( withAccount );
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
	await sendYes( releasedSilent );
	check( '...and Versenden with three mails delivered counts three', releasedSilent.toast.textContent === texts['send.done'].replace( '%s', '3' ) );

	// === fix round 1: the menu by state, released or not
	const moreMenus = [
		[ 'an invoice that is overdue and not in the portal: no reminder, no way back', { state : 'sent', overdue : true, released : false }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a sent invoice in the portal that is not due yet: the reminder is offered, the owner decides when', { state : 'sent', overdue : false, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'an open invoice that is overdue and in the portal: marked as sent, and the reminder all the same', { state : 'open', overdue : true, released : true }, 'PDF ansehen|PDF herunterladen|Als versendet markieren|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'a sent invoice in the portal with no due date: the reminder is offered', { state : 'sent', overdue : false, due_date : null, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Zahlungserinnerung senden|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'a paid invoice in the portal: no reminder', { state : 'paid', overdue : false, released : true }, 'PDF ansehen|PDF herunterladen|Stornieren|Als Entwurf kopieren|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
		[ 'a declined offer: no invoice, a contract is still possible', { id : 13, category : 'offer', state : 'declined', due_date : null }, 'PDF ansehen|PDF herunterladen|Vertrag erstellen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a sent offer', { id : 13, category : 'offer', state : 'sent', due_date : null }, 'PDF ansehen|PDF herunterladen|Als angenommen markieren|Als abgelehnt markieren|Rechnung erstellen|Vertrag erstellen|Als Entwurf kopieren|Projekt ändern|Notizen' ],
		[ 'a cancelled invoice that is in the portal', { state : 'cancelled', released : true }, 'PDF ansehen|PDF herunterladen|Als Entwurf kopieren|Kunden hierher einladen|Freigabe zurücknehmen|Projekt ändern|Notizen' ],
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
	check( 'the editor of a draft under reverse charge draws the head and the totals without VAT: the gross is the net, 930,00, and no row of VAT', textOf( field( reverseDoc, 'gross' ) ) === '930,00'+ NBSP+ '€' && textOf( field( reverseDoc, 'totals' ) ).indexOf( 'USt. auf' ) === -1 && all( lineRows( reverseDoc )[0], 'select.rate' )[0].value === '0' );
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
	check( '...it takes the first rate of the setting in the regular mode, as an empty line does', all( added[added.length - 1], 'select.rate' )[0].value === '1900' );
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
	check( 'a 403 with the code csrf reloads the page, once: the flag is set - the second refusal of the same page, which the browser has already left, says the sentence of the code and reloads nothing', stale.reloads.length === 1 && stale.storage['crm-reloaded'] === '1' && stale.toast.textContent === texts['error.csrf'] );
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

	const kclient = { id : 1, number : 'K-0001', company : 'Nordlicht Records GmbH', name : 'Lena Brandt', street : 'Hafenstraße 12', zip : '20457', city : 'Hamburg', country : 'DE', email : 'lena@nordlicht.example', phone : '+49 40 123456', vat_id : 'DE123456789', business : false, buyer_reference : '',
		notes : 'Zahlt pünktlich.', active : true, created : '2025-02-11T09:00:00Z', updated : '2026-10-01T09:00:00Z', accounts : 3, active_accounts : 2, group : 'k-0001', documents : { total : 2, released : 1 } };
	const kaccounts = [
		{ id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'lena@nordlicht.example', name : 'Lena Brandt', active : true, login : '2026-10-30T10:00:00Z' },
		{ id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'tom@nordlicht.example', name : '', active : true, login : null },
		{ id : 'cccccccccccc', gone : false, state : 'off', email : 'alt@nordlicht.example', name : '<b>Alt</b> Konto', active : false, login : '2026-01-02T10:00:00Z' },
		{ id : 'dddddddddddd', gone : true, state : 'gone' },
	];
	const kclients = [
		Object.assign( {}, kclient ),
		{ id : 2, number : 'K-0002', company : '', name : 'Jonas Richter', street : 'Lindenweg 3', zip : '1010', city : 'Wien', country : 'AT', email : 'jonas@example.org', phone : '', vat_id : '', business : false, buyer_reference : '', notes : '', active : true, created : '2025-03-01T09:00:00Z', accounts : 1, active_accounts : 1, group : 'k-0002' },
		{ id : 3, number : 'K-0003', company : 'Ohne Konto AG', name : 'Carla Ohne', street : '', zip : '', city : 'Köln', country : 'DE', email : '', phone : '', vat_id : '', business : false, buyer_reference : '', notes : '', active : true, accounts : 0, active_accounts : 0, group : 'k-0003' },
		{ id : 4, number : 'K-0004', company : 'Alt AG', name : 'Dora Alt', street : '', zip : '', city : 'Dresden', country : 'DE', email : '', phone : '', vat_id : '', business : false, buyer_reference : '', notes : '', active : false, accounts : 12, active_accounts : 0, group : 'k-0004' },
		{ id : 5, number : 'K-0005', company : '<img src=x onerror=alert(1)> GmbH', name : 'Hostile', street : '', zip : '', city : 'Bonn', country : 'DE', email : '', phone : '', vat_id : '', business : false, buyer_reference : '', notes : '', active : true, accounts : 12, active_accounts : 11, group : 'k-0005' },
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

	// a client that was made with an address alone: known by it, grey, and with the words that it has no data yet
	const kbare = Object.assign( {}, kclients[2], { id : 9, number : 'K-0009', company : '', name : '', street : '', zip : '', city : '', email : 'mika@example.org', title : 'mika@example.org', ready : false, accounts : 1, active_accounts : 1, group : 'k-0009' } );
	const kbareList = await kopen( '#kunden', { 'crm-clients/list' : function() { return [ 200, { clients : [ kbare, kclients[0] ] } ] } } );
	const kbareRow = Array.from( krowsOf( kbareList ) ).find( function( tr ) { return tr.dataset.id === '9' } );
	const kbareCell = kbareRow.children[1];
	check( 'a client with neither company nor name is told by its address in the second tone of the text (AA, not the grey of what is missing), with the words that it has no data yet - and a client with data has neither', textOf( kbareCell ) === 'mika@example.org · '+ texts['clients.bare'] && all( kbareCell, '.bare' ).length === 2 && texts['clients.bare'] !== ''
		&& all( krowsOf( kbareList )[0].children[1], '.bare' ).length === 0 && textOf( krowsOf( kbareList )[0].children[1] ) === 'Nordlicht Records GmbH' );
	check( '...and its address is found by the search, which reads the e-mail of the client', ( type( all( kbareList.main, '#q' )[0], 'mika@', false ), krowsOf( kbareList ).length === 1 ) );
	const kbareCard = await kopen( '#kunde-9', { 'crm-clients/get' : function() { return [ 200, { client : kbare, accounts : kaccounts } ] } } );
	check( 'the card of such a client is headed by the address and says in a sentence under the head that the client enters company and address in the portal - and the card of a client that is ready, or says nothing of it, does not', textOf( all( kbareCard.main, 'h1' )[0] ) === 'mika@example.org' && all( kbareCard.main, '#client-unready' ).length === 1 && textOf( all( kbareCard.main, '#client-unready' )[0] ) === texts['card.unready-note']
		&& Array.from( kbareCard.main.children ).indexOf( all( kbareCard.main, '#client-unready' )[0] ) === Array.from( kbareCard.main.children ).indexOf( all( kbareCard.main, '.head' )[0] ) + 1 );
	const kreadyCard = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclients[0], { ready : true } ), accounts : kaccounts } ] } } );
	check( '...a client that is ready, and one whose card says nothing of it, has no such sentence', all( kreadyCard.main, '#client-unready' ).length === 0 && all( (await kopen( '#kunde-1' )).main, '#client-unready' ).length === 0 );
	check( 'the title of a client is the company, else the name, else the address - what the server calls it first - and a client frozen into a document, which has no title, is called by the same rule', kl.app.model.clientTitle( { company : 'A', name : 'B', email : 'c@example.org' } ) === 'A' && kl.app.model.clientTitle( { company : '', name : 'B', email : 'c@example.org' } ) === 'B'
		&& kl.app.model.clientTitle( { company : '', name : '', email : 'c@example.org' } ) === 'c@example.org' && kl.app.model.clientTitle( { company : 'A', name : '', email : '', title : 'Server' } ) === 'Server' && kl.app.model.clientTitle( { company : '', name : '', email : '' } ) === '' );

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
	check( '...the Stammdaten are the facts of the client: the company, the name, the mail, the phone, the address on two lines, the VAT id and since when - and nothing that is empty', masterText.join( '|' ) === 'Nordlicht Records GmbH|Lena Brandt|lena@nordlicht.example|+49 40 123456|Hafenstraße 1220457 Hamburg|DE123456789|'+ texts['card.business-no']+ '|11.02.2025'
		&& all( all( kc.main, '#master' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Firma|Name|E-Mail|Telefon|Anschrift|USt-IdNr.|'+ texts['card.business']+ '|Kunde seit' );
	const kc2 = await kopen( '#kunde-2' );
	check( '...a client without a company, a phone and a VAT id leaves those rows out, and a country that is not DE is on the third line of the address', all( all( kc2.main, '#master' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Name|E-Mail|Anschrift|'+ texts['card.business']+ '|Kunde seit' && textOf( all( kc2.main, '#master dd' )[2] ) === 'Lindenweg 31010 WienAT' );
	check( '...the tabs are Belege with the count, Portal and Notizen, and the first is open', all( kc.main, '.tabs button' ).map( textOf ).join( '|' ) === 'Belege7|Projekte|Portal|Notizen' && all( kc.main, '.tabs button' )[0].getAttribute( 'aria-selected' ) === 'true' && all( kc.main, '.tabs button' )[0].classList.contains( 'on' ) === true );
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
		labels( arows( kc )[0] ) === 'Anmeldelink senden|Name ändern|Adresse ändern|Sperren|Alle Geräte abmelden|Löschen' && labels( arows( kc )[1] ) === 'Einladung erneut senden|Name ändern|Adresse ändern|Sperren|Alle Geräte abmelden|Löschen' && labels( arows( kc )[2] ) === 'Name ändern|Adresse ändern|Freigeben|Alle Geräte abmelden|Löschen' && labels( arows( kc )[3] ) === 'Zeile entfernen'
		&& all( arows( kc )[0], '.menu button' )[0].getAttribute( 'aria-label' ) === 'Aktionen des Kontos' );
	check( '...Konto einladen and Bestehendes verknüpfen are below the rows, and the way to the panel of Members', textOf( all( accountsCard( kc ), '#invite-open' )[0] ) === 'Konto einladen' && textOf( all( accountsCard( kc ), '#link-open' )[0] ) === 'Bestehendes verknüpfen'
		&& all( accountsCard( kc ), 'a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join() === '/_admin/#members' );

	// the link again
	let resend = null;
	const kr = await kopen( '#kunde-1', { 'crm-clients/account-resend' : function( data ) { resend = data; return [ 200, Object.assign( { sent : data.notify !== false, kind : data.account === 'aaaaaaaaaaaa' ? 'login' : 'invite' }, data.notify === false ? { link : 'https://example.org/club/.members/login?t=QUIET' } : {} ) ] } } );
	click( kr, kitem( arows( kr )[0], 'Anmeldelink senden' ) );
	check( 'a link again asks first, in a dialog with the box "Link per E-Mail senden" - a real checkbox, ticked - and posts nothing yet', kr.dialogs.length === 1 && all( kr.dialogs[0], 'input' )[0].type === 'checkbox' && all( kr.dialogs[0], 'input' )[0].checked === true && textOf( all( kr.dialogs[0], 'label' )[0] ).indexOf( texts['notify.invite'] ) !== -1 && resend === null );
	yes( kr, 0 );
	await settle();
	check( '...the yes posts crm-clients/account-resend with the client, the account and notify true, and says that the link to sign in is on its way', JSON.stringify( resend ) === '{"id":"1","account":"aaaaaaaaaaaa","notify":true}' && kr.toast.textContent === 'Der Link zum Anmelden ist unterwegs.' && kr.toast.classList.contains( 'bad' ) === false );
	click( kr, kitem( arows( kr )[1], 'Einladung erneut senden' ) );
	yes( kr, 1 );
	await settle();
	check( '...and for an account that never signed in, that the invitation is', kr.toast.textContent === 'Die Einladung ist unterwegs.' && JSON.stringify( resend ) === '{"id":"1","account":"bbbbbbbbbbbb","notify":true}' );
	click( kr, kitem( arows( kr )[1], 'Einladung erneut senden' ) );
	all( kr.dialogs[2], 'input' )[0].checked = false;
	yes( kr, 2 );
	await settle();
	check( '...unticked the box posts notify false: no mail, the toast says so, and the invitation link is shown in a dialog of its own - the field holds it, the dialog has the copy button', JSON.stringify( resend ) === '{"id":"1","account":"bbbbbbbbbbbb","notify":false}' && kr.toast.textContent === texts['acc.resent-invite-quiet']
		&& all( kr.dialogs[3], '#dlg-link' )[0].value === 'https://example.org/club/.members/login?t=QUIET' && all( kr.dialogs[3], '#dlg-link-copy' ).length === 1 && textOf( all( kr.dialogs[3], 'h2' )[0] ) === texts['shown.title-invite'] );
	const shownField = all( kr.dialogs[3], '#dlg-link' )[0];
	click( kr, all( kr.dialogs[3], 'button' ).pop() );
	await settle();
	check( '...and once it is closed the link is nowhere on the page - the field itself is emptied', kr.dialogRoot.children.length === 0 && textOf( kr.main ).indexOf( 'QUIET' ) === -1 && shownField.value === '' );
	click( kr, kitem( arows( kr )[0], 'Anmeldelink senden' ) );
	all( kr.dialogs[4], 'input' )[0].checked = false;
	yes( kr, 4 );
	await settle();
	check( '...the link to sign in without a mail is titled as that, and not as an invitation', textOf( all( kr.dialogs[5], 'h2' )[0] ) === texts['shown.title-login'] && texts['shown.title-login'] !== texts['shown.title-invite'] && kr.toast.textContent === texts['acc.resent-login-quiet'] );
	click( kr, all( kr.dialogs[5], 'button' ).pop() );
	click( kr, kitem( arows( kr )[0], 'Anmeldelink senden' ) );
	no( kr, 6 );
	await settle();
	check( '...the no posts nothing', kr.all( 'crm-clients/account-resend' ).length === 4 );
	const capped = await kopen( '#kunde-1', { 'crm-clients/account-resend' : function() { return [ 429, { error : 'x', code : 'crm_clients_ratelimit' } ] } } );
	click( capped, kitem( arows( capped )[0], 'Anmeldelink senden' ) );
	yes( capped, 0 );
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

	// the address
	let readdressed = null;
	const kmail = await kopen( '#kunde-1', { 'crm-clients/account-email' : function( data ) {
		readdressed = data;
		return [ 200, Object.assign( { account : Object.assign( {}, kaccounts[0], { email : data.email.toLowerCase() } ) }, data.master === true ? { client : Object.assign( {}, kclient, { email : data.email.toLowerCase() } ) } : {} ) ];
	} } );
	click( kmail, kitem( arows( kmail )[0], 'Adresse ändern' ) );
	const mailDialog = kmail.dialogs[0];
	check( 'the address opens a dialog with the address the account has in a field of the kind email, the sentence that says what the change ends and that the address of the client is another one, and a box for the client\'s own address - unticked - and nothing is posted before the yes',
		kmail.dialogs.length === 1 && textOf( all( mailDialog, 'h2' )[0] ) === 'Adresse ändern' && all( mailDialog, 'input' )[0].type === 'email' && all( mailDialog, 'input' )[0].value === 'lena@nordlicht.example' && all( mailDialog, 'input' )[1].type === 'checkbox' && all( mailDialog, 'input' )[1].checked === false
		&& textOf( mailDialog ).indexOf( texts['acc.email-text'] ) !== -1 && textOf( all( mailDialog, 'label' )[1] ).indexOf( texts['acc.email-master'] ) !== -1 && readdressed === null );
	no( kmail, 0 );
	await settle();
	check( '...the no posts nothing', readdressed === null && kmail.all( 'crm-clients/account-email' ).length === 0 );
	click( kmail, kitem( arows( kmail )[0], 'Adresse ändern' ) );
	type( all( kmail.dialogs[1], 'input' )[0], 'Lena.New@Nordlicht.example', false );
	yes( kmail, 1 );
	await settle();
	check( '...the yes posts crm-clients/account-email with the client, the account as member, the address as typed and master false; the row is drawn again with the address the server answers - as text - and the toast says so; the card of the client is as it was',
		JSON.stringify( readdressed ) === '{"id":"1","member":"aaaaaaaaaaaa","email":"Lena.New@Nordlicht.example","master":false}' && textOf( all( arows( kmail )[0], '.who div' )[0] ).indexOf( 'lena.new@nordlicht.example' ) === 0 && arows( kmail ).length === 4 && kmail.toast.textContent === texts['acc.email-done']
		&& textOf( all( all( kmail.main, '#master' )[0], 'dd' )[2] ) === 'lena@nordlicht.example' );
	click( kmail, kitem( arows( kmail )[0], 'Adresse ändern' ) );
	type( all( kmail.dialogs[2], 'input' )[0], 'both@nordlicht.example', false );
	all( kmail.dialogs[2], 'input' )[1].checked = true;
	yes( kmail, 2 );
	await settle();
	check( '...with the box ticked master is true and the card of the client shows the address the server answers for it as well', JSON.stringify( readdressed ) === '{"id":"1","member":"aaaaaaaaaaaa","email":"both@nordlicht.example","master":true}' && textOf( all( all( kmail.main, '#master' )[0], 'dd' )[2] ) === 'both@nordlicht.example'
		&& textOf( all( arows( kmail )[0], '.who div' )[0] ).indexOf( 'both@nordlicht.example' ) === 0 );
	click( kmail, kitem( arows( kmail )[0], 'Adresse ändern' ) );
	check( '...and a dialog starts at its default again: the box is unticked, whatever the one before was left at', all( kmail.dialogs[3], 'input' )[1].checked === false );
	const refusedMail = [];

	for( const refusal of [ { status : 409, code : 'crm_clients_taken' }, { status : 400, code : 'crm_clients_email' }, { status : 403, code : 'crm_clients_link_perm' } ] ) {
		const kr = await kopen( '#kunde-1', { 'crm-clients/account-email' : function() { return [ refusal.status, { error : 'x', code : refusal.code, field : 'email' } ] } } );
		click( kr, kitem( arows( kr )[0], 'Adresse ändern' ) );
		yes( kr, 0 );
		await settle();
		refusedMail.push( kr.toast.textContent === texts['error.'+ refusal.code] && kr.toast.classList.contains( 'bad' ) === true && textOf( all( arows( kr )[0], '.who div' )[0] ).indexOf( 'lena@nordlicht.example' ) === 0 );
	}

	check( '...a refusal - the address is an account\'s already, is none, or the account holds no permission of Members - says the sentence of its code in the red toast, and the row is as it was', refusedMail.join() === 'true,true,true' && texts['error.crm_clients_taken'] !== undefined && texts['error.crm_clients_email'] !== undefined );

	// the message to the client
	const messageClient = Object.assign( {}, kclients[0], { message : 'Zu bis Montag.\n<b>Danke!</b>', message_at : '2026-11-02T10:00:00Z' } );
	const kempty = await kopen( '#kunde-1' );
	const kemptyBlock = all( kempty.main, '#client-message' )[0];
	const kemptyOrder = Array.from( kempty.main.children );
	check( 'the card has the block Nachricht im Portal under the head and before the columns of the card: with no message it is one line - a sentence that there is none and the button that writes one, no card and no heading - and nothing to remove', kemptyBlock !== undefined && all( kemptyBlock, 'h3' ).length === 0 && kemptyBlock.classList.contains( 'card' ) === false && kemptyBlock.classList.contains( 'msg-line' ) === true && textOf( all( kempty.main, '#client-message-empty' )[0] ) === texts['message.empty']
		&& textOf( all( kempty.main, '#message-edit' )[0] ) === texts['message.write'] && all( kempty.main, '#message-remove' ).length === 0 && all( kempty.main, '#client-message-text' ).length === 0
		&& kemptyOrder.indexOf( kemptyBlock ) > kemptyOrder.indexOf( all( kempty.main, '.head' )[0] ) && kemptyOrder.indexOf( kemptyBlock ) < kemptyOrder.indexOf( all( kempty.main, '.cols-3' )[0] ) );

	const messagePosts = [];
	const kmessage = await kopen( '#kunde-1', {
		'crm-clients/get' : function() { return [ 200, { client : messageClient, accounts : kaccounts } ] },
		'crm-clients/message' : function( data ) { messagePosts.push( data ); return data.text.length > 2000 ? [ 400, { error : 'x', code : 'crm_clients_long', params : [ 2000 ], field : 'message' } ] : [ 200, { client : Object.assign( {}, messageClient, { message : data.text.trim(), message_at : data.text.trim() === '' ? '' : '2026-11-06T09:00:00Z' } ) } ] },
	} );
	const kmessageText = all( kmessage.main, '#client-message-text' )[0];
	check( 'with a message the block shows the text as text - the line break is in the string, the markup is no element - the day since which the client reads it, Bearbeiten and Entfernen', kmessageText.textContent === 'Zu bis Montag.\n<b>Danke!</b>' && all( all( kmessage.main, '#client-message' )[0], 'b' ).length === 0
		&& textOf( all( kmessage.main, '#client-message-day' )[0] ) === texts['message.since'].replace( '%s', '02.11.2026' ) && textOf( all( kmessage.main, '#message-edit' )[0] ) === texts['message.edit'] && textOf( all( kmessage.main, '#message-remove' )[0] ) === texts['message.remove'] && all( kmessage.main, '#client-message-empty' ).length === 0 );
	click( kmessage, all( kmessage.main, '#message-edit' )[0] );
	const kmessageBox = kmessage.dialogs[0];
	const kmessageArea = all( kmessageBox, '#dlg-text' )[0];
	check( 'Bearbeiten opens a dialog with a textarea that holds the text, limited to 2000 characters, and nothing is posted before the yes', kmessage.dialogs.length === 1 && kmessageArea.tagName === 'TEXTAREA' && kmessageArea.value === messageClient.message && kmessageArea.getAttribute( 'maxlength' ) === '2000' && messagePosts.length === 0
		&& textOf( all( kmessageBox, 'h2' )[0] ) === texts['message.dialog'] && textOf( all( kmessageBox, 'button' ).pop() ) === texts['message.save'] );
	kmessageArea.value = 'Neuer Text\nzweite Zeile';
	fire( kmessageBox, 'keydown', { ctrlKey : true, key : 'x' } );
	await settle();
	check( '...Strg+X is nothing for the dialog', messagePosts.length === 0 && kmessageBox.open === true );
	fire( kmessageBox, 'keydown', { ctrlKey : true, key : 's' } );
	await settle();
	check( '...Strg+S in the dialog is the yes: crm-clients/message with the client and the text, the block is drawn again with what the server answers, the toast says it is saved', JSON.stringify( messagePosts ) === '[{"id":"1","text":"Neuer Text\\nzweite Zeile"}]' && all( kmessage.main, '#client-message-text' )[0].textContent === 'Neuer Text\nzweite Zeile'
		&& kmessage.toast.textContent === texts['message.saved'] && textOf( all( kmessage.main, '#client-message-day' )[0] ) === texts['message.since'].replace( '%s', '06.11.2026' ) && kmessageBox.open === false && all( kmessage.main, '#client-message' ).length === 1 );
	click( kmessage, all( kmessage.main, '#message-edit' )[0] );
	yes( kmessage, 1 );
	await settle();
	check( '...a yes over the text as it is posts nothing', messagePosts.length === 1 );
	click( kmessage, all( kmessage.main, '#message-edit' )[0] );
	all( kmessage.dialogs[2], '#dlg-text' )[0].value = 'x'.repeat( 2001 );
	yes( kmessage, 2 );
	await settle();
	check( '...a text the server refuses is said with its sentence and the limit, and the block stays as it was', messagePosts.length === 2 && kmessage.toast.textContent === 'Der Wert darf höchstens 2000 Zeichen haben.' && all( kmessage.main, '#client-message-text' )[0].textContent === 'Neuer Text\nzweite Zeile' );
	click( kmessage, all( kmessage.main, '#message-remove' )[0] );
	const kmessageAsk = kmessage.dialogs[3];
	fire( kmessageAsk, 'keydown', { ctrlKey : true, key : 's' } );
	await settle();
	check( 'Entfernen asks first, with the danger button: nothing is posted before the yes, and Strg+S is no yes of a question', textOf( all( kmessageAsk, 'h2' )[0] ) === texts['message.remove-title'] && messagePosts.length === 2 && kmessageAsk.open === true && all( kmessageAsk, 'button' ).pop().classList.contains( 'danger' ) === true );
	yes( kmessage, 3 );
	await settle();
	check( '...the yes posts the empty text: the block says there is none, the button writes one, the toast says it is removed', JSON.stringify( messagePosts[2] ) === '{"id":"1","text":""}' && textOf( all( kmessage.main, '#client-message-empty' )[0] ) === texts['message.empty'] && all( kmessage.main, '#message-remove' ).length === 0 && kmessage.toast.textContent === texts['message.removed'] );

	const klistMessage = await kopen( '#kunden', { 'crm-clients/list' : function() { return [ 200, { clients : kclients.map( function( one ) { return Object.assign( {}, one, { message : one.id === 2 ? 'Eine Nachricht' : '' } ) } ) } ] } } );
	const kmessageRows = Array.from( krowsOf( klistMessage ) );
	const kicons = all( klistMessage.main, '.msg-icon' );
	check( 'the list of the clients marks a client with a message: an icon message-square in the column Kunde, with its words as the title and for a reader - and only that one', kicons.length === 1 && kmessageRows.map( function( row ) { return all( row, '.msg-icon' ).length } ).join() === '0,1,0,0,0' && kicons[0].parentNode === kmessageRows[1].children[1] && kicons[0].dataset.icon === 'message-square'
		&& kicons[0].title === texts['clients.has-message'] && kicons[0].getAttribute( 'aria-label' ) === texts['clients.has-message'] && kicons[0].getAttribute( 'role' ) === 'img' );

	// switch
	let switched = null;
	const ks = await kopen( '#kunde-1', { 'crm-clients/account-active' : function( data ) { switched = data; return [ 200, { account : Object.assign( {}, kaccounts[data.active ? 2 : 0], { active : data.active, state : data.active ? 'invited' : 'off', login : data.active ? null : kaccounts[0].login } ) } ] } } );
	click( ks, kitem( arows( ks )[0], 'Sperren' ) );
	await settle();
	check( 'Sperren posts crm-clients/account-active with the value it wants; the row wears the badge gesperrt and offers Freigeben, and no link to send', JSON.stringify( switched ) === '{"id":"1","account":"aaaaaaaaaaaa","active":false}' && textOf( all( arows( ks )[0], '.badge' )[0] ) === 'gesperrt'
		&& labels( arows( ks )[0] ) === 'Name ändern|Adresse ändern|Freigeben|Alle Geräte abmelden|Löschen' && ks.toast.textContent === 'Das Konto ist gesperrt.' );
	click( ks, kitem( arows( ks )[2], 'Freigeben' ) );
	await settle();
	check( '...Freigeben posts active true, and says so', JSON.stringify( switched ) === '{"id":"1","account":"cccccccccccc","active":true}' && ks.toast.textContent === 'Das Konto ist freigegeben.' );
	const kInactiveAnswer = await kopen( '#kunde-1', { 'crm-clients/account-active' : function() { return [ 409, { error : 'x', code : 'crm_clients_inactive' } ] } } );
	click( kInactiveAnswer, kitem( arows( kInactiveAnswer )[2], 'Freigeben' ) );
	await settle();
	check( '...a client that is off refuses it, and the sentence says why, in the red toast', kInactiveAnswer.toast.textContent.indexOf( 'Der Kunde ist deaktiviert.' ) === 0 && textOf( all( arows( kInactiveAnswer )[2], '.badge' )[0] ) === 'gesperrt' );

	// delete
	let forgotten = [];
	const kf = await kopen( '#kunde-1', { 'crm-clients/account-forget' : function( data ) { forgotten.push( data ); return data.account === 'cccccccccccc' ? [ 500, { error : 'x', code : 'crm_clients_write' } ] : [ 200, { forgotten : true } ] } } );
	click( kf, kitem( arows( kf )[0], 'Alle Geräte abmelden' ) );
	check( 'Alle Geräte abmelden asks first, with the name of the account and what happens to the devices - nothing is posted before the yes', kf.dialogs.length === 1 && forgotten.length === 0 && textOf( all( kf.dialogs[0], 'h2' )[0] ) === 'Alle Geräte abmelden' && textOf( all( kf.dialogs[0], 'p' )[0] ).indexOf( 'Lena Brandt' ) === 0
		&& all( kf.dialogs[0], 'button' ).map( textOf ).join( '|' ) === 'Abbrechen|Abmelden' && all( kf.dialogs[0], 'button' )[1].classList.contains( 'danger' ) === false );
	no( kf, 0 );
	await settle();
	check( '...the no posts nothing', forgotten.length === 0 && arows( kf ).length === 4 );
	click( kf, kitem( arows( kf )[0], 'Alle Geräte abmelden' ) );
	yes( kf, 1 );
	await settle();
	check( '...the yes posts crm-clients/account-forget with the client and the account and says so; the row stays as it is', JSON.stringify( forgotten ) === '[{"id":"1","account":"aaaaaaaaaaaa"}]' && kf.toast.textContent === 'Das Konto ist von allen Geräten abgemeldet.' && arows( kf ).length === 4 && labels( arows( kf )[0] ).indexOf( 'Alle Geräte abmelden' ) !== -1 );
	click( kf, kitem( arows( kf )[2], 'Alle Geräte abmelden' ) );
	yes( kf, 2 );
	await settle();
	check( '...a refusal is said in the red toast, with the sentence of its code', kf.toast.textContent === 'Das Konto konnte nicht geschrieben werden.' && kf.toast.classList.contains( 'bad' ) === true );

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

	// each dialog asks the permission of its own feature: Sign's for the signature and its link, Members' for the account menu
	{
		const contract = { id : 18, category : 'contract', state : 'open', sign_state : 'viewed', released : true, lines : [], title : 'V', number : '', due_date : null };
		const onlyMembers = await opened( contract, {}, {}, { crm : true, members : true, sign : false } );
		menuClick( onlyMembers, 'Erinnern' );
		check( 'an account with the permission of Members alone has no box in the dialog of the link again - that is Sign\'s', all( onlyMembers.dialogs[0], '#dlg-notify' ).length === 0 && textOf( onlyMembers.dialogs[0] ).indexOf( texts['notify.noperm-sign'] ) !== -1 );
		const onlySign = await opened( contract, {}, {}, { crm : true, members : false, sign : true } );
		menuClick( onlySign, 'Erinnern' );
		check( '...and the account with the permission of Sign alone has it', all( onlySign.dialogs[0], '#dlg-notify' ).length === 1 && textOf( onlySign.dialogs[0] ).indexOf( texts['notify.noperm-sign'] ) === -1 );
		const asMembers = world( { hash : '#kunde-1', perms : { crm : true, members : true, sign : false }, answers : kanswers() } );
		asMembers.boot();
		await settle();
		click( asMembers, kitem( arows( asMembers )[0], 'Anmeldelink senden' ) );
		check( 'the dialog of the account menu has its box for the account with the permission of Members - and the invitation form too', all( asMembers.dialogs[0], '#dlg-notify' ).length === 1 && ( click( asMembers, all( asMembers.main, '#invite-open' )[0] ), all( asMembers.main, '#f-invite-notify' ).length === 1 ) );
		const asSign = world( { hash : '#kunde-1', perms : { crm : true, members : false, sign : true }, answers : kanswers() } );
		asSign.boot();
		await settle();
		click( asSign, kitem( arows( asSign )[0], 'Anmeldelink senden' ) );
		check( '...and none for the account with the permission of Sign alone', all( asSign.dialogs[0], '#dlg-notify' ).length === 0 && textOf( asSign.dialogs[0] ).indexOf( texts['notify.noperm-members'] ) !== -1 );
		check( 'the entry to change the address is the permission of Members\' alone: the account that holds it has it, the one that holds Sign\'s has not', labels( arows( asMembers )[0] ).indexOf( 'Adresse ändern' ) !== -1 && labels( arows( asSign )[0] ).indexOf( 'Adresse ändern' ) === -1 );
	}

	// no permission of Members: no box in the invitation, in the menu of an account and in Neuer Kunde - the link goes by mail
	{
		const kplain = world( { hash : '#kunde-1', perms : { crm : true }, answers : kanswers( { 'crm-clients/invite' : function( data ) { invitedPlain = data; return [ 200, { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.email, name : data.name, active : true, login : null }, mailed : true } ] }, 'crm-clients/account-resend' : function( data ) { resendPlain = data; return [ 200, { sent : true, kind : 'login' } ] } } ) } );
		let invitedPlain = null;
		let resendPlain = null;
		kplain.boot();
		await settle();
		click( kplain, all( kplain.main, '#invite-open' )[0] );
		const pform = all( kplain.main, '#invite' )[0];
		check( 'without the permission of Members the invitation form has no box and says why', all( pform, 'input' ).every( function( i ) { return i.type !== 'checkbox' } ) && textOf( all( pform, '#invite-noquiet' )[0] ) === texts['notify.noperm-members'] );
		type( all( pform, 'input' )[0], 'plain@example.org', false );
		submit( kplain, pform );
		await settle();
		check( '...and it posts notify true', invitedPlain !== null && invitedPlain.notify === true );
		click( kplain, kitem( arows( kplain )[0], 'Anmeldelink senden' ) );
		check( 'the dialog of the account menu has no box either, and the yes posts notify true', all( kplain.dialogs[0], '#dlg-notify' ).length === 0 && textOf( kplain.dialogs[0] ).indexOf( texts['notify.noperm-members'] ) !== -1 && textOf( kplain.dialogs[0] ).indexOf( 'Häkchen' ) === -1 && ( yes( kplain, 0 ), await settle(), resendPlain !== null && resendPlain.notify === true ) );
		check( 'the menu of an account has no entry to change the address without the permission of Members - the new address is where the next code goes - whatever else the account holds; the entries the CRM\'s permission covers are there', labels( arows( kplain )[0] ) === 'Anmeldelink senden|Name ändern|Sperren|Alle Geräte abmelden|Löschen' && labels( arows( kplain )[1] ) === 'Einladung erneut senden|Name ändern|Sperren|Alle Geräte abmelden|Löschen' );
		const nplain = world( { hash : '#kunde-neu', perms : { crm : true }, answers : kanswers() } );
		nplain.boot();
		await settle();
		check( 'Neuer Kunde has no box for the first account either, and says why', all( nplain.main, '#f-account_notify' ).length === 0 && textOf( all( nplain.main, '#account-noquiet' )[0] ) === texts['notify.noperm-members'] );
		type( all( nplain.main, '#f-company' )[0], 'Ohne Recht GmbH', false );
		type( all( nplain.main, '#f-account_email' )[0], 'ohne@example.org', false );
		submit( nplain, all( nplain.main, '#new-client-form' )[0] );
		await settle();
		check( '...and what it posts for the first account is notify true: the invitation goes by mail', nplain.last( 'crm-clients/save' ).data.notify === true && nplain.last( 'crm-clients/save' ).data.account_email === 'ohne@example.org' );
	}

	// a link the server could not make is no dialog: an answer that holds an empty link opens nothing
	{
		const kempty = await kopen( '#kunde-1', { 'crm-clients/invite' : function( data ) { return [ 200, { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.email, name : data.name, active : true, login : null }, mailed : false, link : '' } ] } } );
		click( kempty, all( kempty.main, '#invite-open' )[0] );
		type( all( all( kempty.main, '#invite' )[0], 'input' )[0], 'empty@example.org', false );
		all( all( kempty.main, '#invite' )[0], 'input' )[2].checked = false;
		submit( kempty, all( kempty.main, '#invite' )[0] );
		await settle();
		check( 'a link that is empty opens no dialog', kempty.dialogs.length === 0 );
		const krefused = await kopen( '#kunde-1', { 'crm-clients/invite' : function() { return [ 500, { error : 'x', code : 'crm_clients_link' } ] } } );
		click( krefused, all( krefused.main, '#invite-open' )[0] );
		type( all( all( krefused.main, '#invite' )[0], 'input' )[0], 'refused@example.org', false );
		all( all( krefused.main, '#invite' )[0], 'input' )[2].checked = false;
		submit( krefused, all( krefused.main, '#invite' )[0] );
		await settle();
		check( '...and a refusal to make it is the sentence of its code, with no dialog', krefused.dialogs.length === 0 && krefused.toast.textContent === texts['error.crm_clients_link'] && texts['error.crm_clients_link_perm'] !== undefined );
	}

	// invite
	let invited = null;
	const ki = await kopen( '#kunde-1', { 'crm-clients/invite' : function( data ) {
		invited = data;
		if( data.email === 'taken@example.org' )
			return [ 409, { error : 'x', code : 'crm_clients_taken', field : 'email' } ];
		return [ 200, Object.assign( { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.email, name : data.name, active : true, login : null }, mailed : data.email !== 'unsent@example.org' && data.notify !== false }, data.notify === false ? { link : 'https://example.org/club/.members/login?t=INVITED' } : {} ) ];
	} } );
	check( 'there is no form to invite until the button is pressed', all( ki.main, '#invite' ).length === 0 );
	click( ki, all( ki.main, '#invite-open' )[0] );
	const iform = all( ki.main, '#invite' )[0];
	check( 'Konto einladen opens the form in the card: the address and the name, the way to send and to cancel', iform !== undefined && all( iform, 'input' ).map( function( i ) { return i.type } ).join() === 'email,text,checkbox' && all( iform, 'label' ).map( textOf ).join( '|' ) === 'E-Mail-Adresse des Kontos|Name (optional)| '+ texts['notify.invite'] && all( iform, 'input' )[2].checked === true
		&& textOf( all( iform, '#invite-send' )[0] ) === 'Einladung senden' && all( iform, '#invite-cancel' ).length === 1 );
	type( all( iform, 'input' )[0], 'neu@example.org', false );
	type( all( iform, 'input' )[1], 'Neu Person', false );
	submit( ki, iform );
	await settle();
	check( 'sending posts crm-clients/invite with the client, the address and the name, and the account is a row of the card - the form is gone - with the sentence that the invitation is on its way', JSON.stringify( invited ) === '{"id":"1","email":"neu@example.org","name":"Neu Person","notify":true}' && arows( ki ).length === 5
		&& textOf( all( arows( ki )[4], 'b' )[0] ) === 'Neu Person' && textOf( all( arows( ki )[4], '.badge' )[0] ) === 'eingeladen' && all( ki.main, '#invite' ).length === 0 && ki.toast.textContent === 'Das Konto ist angelegt, die Einladung ist unterwegs.' && ki.toast.classList.contains( 'bad' ) === false );
	click( ki, all( ki.main, '#invite-open' )[0] );
	type( all( all( ki.main, '#invite' )[0], 'input' )[0], 'unsent@example.org', false );
	submit( ki, all( ki.main, '#invite' )[0] );
	await settle();
	check( '...an account that was made and could not be mailed says that, and not that the invitation is on its way', ki.toast.textContent === 'Das Konto ist angelegt, aber die Einladung konnte nicht gesendet werden. Sende den Link über das Menü des Kontos noch einmal.' && arows( ki ).length === 6 );
	click( ki, all( ki.main, '#invite-open' )[0] );
	type( all( all( ki.main, '#invite' )[0], 'input' )[0], 'quiet@example.org', false );
	all( all( ki.main, '#invite' )[0], 'input' )[2].checked = false;
	submit( ki, all( ki.main, '#invite' )[0] );
	await settle();
	check( '...unticked, the box posts notify false: the account is a row, the toast says that no mail went, and the link of the invitation is shown in a dialog of its own', invited.notify === false && arows( ki ).length === 7 && ki.toast.textContent === texts['invite.sent-quiet']
		&& all( ki.dialogs[0], '#dlg-link' )[0].value === 'https://example.org/club/.members/login?t=INVITED' && textOf( all( ki.dialogs[0], 'h2' )[0] ) === texts['shown.title-invite'] );
	click( ki, all( ki.dialogs[0], 'button' ).pop() );
	click( ki, all( ki.main, '#invite-open' )[0] );
	check( '...a form starts at its default again: the box is ticked', all( all( ki.main, '#invite' )[0], 'input' )[2].checked === true );
	click( ki, all( all( ki.main, '#invite' )[0], '#invite-cancel' )[0] );
	click( ki, all( ki.main, '#invite-open' )[0] );
	const taken = all( ki.main, '#invite' )[0];
	type( all( taken, 'input' )[0], 'taken@example.org', false );
	submit( ki, taken );
	await settle();
	check( '...a refusal is said beside the field it names, and the form stays with what was typed: the address in the red, the sentence under it, the cursor in it', all( ki.main, '#invite' )[0] === taken && all( taken, 'input' )[0].value === 'taken@example.org' && all( taken, 'input' )[0].classList.contains( 'err' ) === true
		&& all( taken, 'input' )[0].getAttribute( 'aria-invalid' ) === 'true' && textOf( all( taken, '.state-note' )[0] ) === 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.' && all( taken, 'input' )[0].focused === true && arows( ki ).length === 7 );
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
	check( '...its accounts offer no link and no switch-on - an account that is off stays off - but the rename and the delete; and the menu of the client has the switch-on', labels( arows( koff )[0] ) === 'Name ändern|Adresse ändern|Sperren|Alle Geräte abmelden|Löschen'
		&& labels( arows( koff )[2] ) === 'Name ändern|Adresse ändern|Alle Geräte abmelden|Löschen' && all( kmenu( koff, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Aktivieren' );
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
	check( 'the menu of the client has the two links for the accounts that are on, Stammdaten bearbeiten and Deaktivieren, and the form is not there before', all( kmenu( ke, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === [ texts['plink.menu-data'], texts['plink.menu-privacy'], 'Stammdaten bearbeiten', 'Deaktivieren' ].join( '|' ) && all( ke.main, '#client-save' ).length === 0 );
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	const mform = all( ke.main, '#master' )[0];
	check( 'Stammdaten bearbeiten draws a form in the place of the facts: the nine fields with their words, filled, the mail a field of mail, the phone asking a phone keyboard, the country two characters long', all( mform, 'input' ).map( function( i ) { return i.id.slice( 2 ) } ).join() === 'company,name,street,zip,city,country,email,phone,vat_id,buyer_reference,business'
		&& all( mform, 'label' ).map( textOf ).join( '|' ) === 'Firma|Name|Straße und Nummer|PLZ|Ort|Land (Kürzel)|E-Mail|Telefon|USt-IdNr.|'+ texts['clientfield.buyer_reference']+ '|'+ texts['clientfield.business'] && all( mform, 'input' ).map( function( i ) { return i.value } ).join( '|' ) === 'Nordlicht Records GmbH|Lena Brandt|Hafenstraße 12|20457|Hamburg|DE|lena@nordlicht.example|+49 40 123456|DE123456789||'
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
	check( 'a save posts crm-clients/save with the id as a string and every field of the client - the notes among them, which the model would empty if they were left out -, as typed', Object.keys( lastSave ).sort().join() === 'business,buyer_reference,city,company,country,email,id,name,notes,phone,street,vat_id,zip' && lastSave.id === '1' && lastSave.city === 'Bremen' && lastSave.email === 'lena@neu.example' && lastSave.notes === 'Zahlt pünktlich.' && lastSave.company === 'Nordlicht Records GmbH' );
	check( '...the card is drawn again from the client the server answers - the facts, no form -, the toast says it, and the guard holds nothing', all( ke.main, '#client-save' ).length === 0 && all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[2] === 'lena@neu.example' && all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[4].indexOf( 'Bremen' ) !== -1 && ke.toast.textContent === 'Der Kunde ist gespeichert.' && ke.app.state.draft === null );
	// the client is a business: a switch, and the reference it quotes
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	const bform = all( ke.main, '#master' )[0];
	const businessBox = all( bform, '#f-business' )[0];
	check( 'the form of a client has the switch "Unternehmen – bekommt eine E-Rechnung" - a checkbox, off for a client that is none - and the field of the buyer reference, a hundred characters long', businessBox.type === 'checkbox' && businessBox.checked === false && all( bform, '#f-buyer_reference' )[0].getAttribute( 'maxlength' ) === '100'
		&& all( bform, 'label' ).map( textOf ).slice( -2 ).join( '|' ) === texts['clientfield.buyer_reference']+ '|'+ texts['clientfield.business'] );
	businessBox.checked = true;
	fire( businessBox, 'change', { target : businessBox } );
	type( all( bform, '#f-buyer_reference' )[0], 'PO-4711', false );
	check( '...switching it is unsaved input', ke.app.state.draft.dirty === true );
	submit( ke, bform );
	await settle();
	const businessSave = savedClient[savedClient.length - 1];
	check( '...a save posts the switch as a boolean and the reference as a text; the card then says it is a business and quotes the reference', businessSave.business === true && businessSave.buyer_reference === 'PO-4711' && typeof businessSave.business === 'boolean'
		&& all( all( ke.main, '#master' )[0], 'dt' ).map( textOf ).join( '|' ).indexOf( texts['card.business']+ '|'+ texts['card.buyer-reference']+ '|' ) !== -1 && all( all( ke.main, '#master' )[0], 'dd' ).map( textOf ).join( '|' ).indexOf( texts['card.business-yes']+ '|PO-4711' ) !== -1 );
	click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) );
	type( all( all( ke.main, '#master' )[0], '#f-name' )[0], 'Anderer', false );
	click( ke, all( ke.main, '#client-cancel' )[0] );
	check( '...Abbrechen puts the facts back as they were and drops the guard; no second form is made by a second press of the menu item', all( all( ke.main, '#master' )[0], 'dd' ).map( textOf )[1] === 'Lena Brandt' && ke.app.state.draft === null && ( click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) ), click( ke, kitem( kmenu( ke, '⋯' ), 'Stammdaten bearbeiten' ) ), all( ke.main, '#client-save' ).length === 1 ) );

	// a tab is the body under the strip: what is open above it stays open
	const ktab = await kopen( '#kunde-1' );
	click( ktab, kitem( kmenu( ktab, '⋯' ), 'Stammdaten bearbeiten' ) );
	const openForm = all( ktab.main, '#master' )[0];
	type( all( openForm, '#f-city' )[0], 'Lübeck', false );
	click( ktab, all( ktab.main, '.tabs button' )[3] );
	click( ktab, all( ktab.main, '.tabs button' )[0] );
	check( 'a tab changes the body under the strip and nothing above it: the form of the Stammdaten that is open is the same form, with what was typed, and the guard still knows it', all( ktab.main, '#master' )[0] === openForm && all( openForm, '#f-city' )[0].value === 'Lübeck' && ktab.app.state.draft.dirty === true
		&& all( ktab.main, '.tabs button' ).map( function( b ) { return b.classList.contains( 'on' ) } ).join() === 'true,false,false,false' && all( ktab.main, '.tabs button' ).map( function( b ) { return b.getAttribute( 'aria-selected' ) } ).join() === 'true,false,false,false' );
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
	click( kp, all( kp.main, '.tabs button' )[2] );
	await settle();
	const portalText = function( w ) { return all( w.main, '#client-portal' )[0] };
	check( 'the tab Portal asks crm-clients/portal for the client, and shows the documents the client sees as links to them - the title for a document that has one, the number else - with their kind and day', JSON.stringify( kp.last( 'crm-clients/portal' ).data ) === '{"id":"1"}' && all( portalText( kp ), '.list-mini' )[0].children.length === 2
		&& all( all( portalText( kp ), '.list-mini' )[0], 'a' ).map( function( a ) { return a.getAttribute( 'href' ) + ':' + textOf( a ) } ).join( '|' ) === '#beleg-11:R-2026-0007|#beleg-17:Vertrag Album 2027' && all( kp.main, '.tabs button' )[2].classList.contains( 'on' ) === true );
	check( '...the folders of Files with how many files the client may see in each and whether it uploads - a name that is markup is text -, and what Review counts', all( all( portalText( kp ), '.list-mini' )[1], 'li' ).map( textOf ).join( '|' ) === 'Ordner „Nordlicht <Album>“ · 14 Dateien · Upload erlaubt|Ordner „Cover“ · 1 Datei · kein Upload'
		&& findAll( portalText( kp ), function( node ) { return node.tagName === 'ALBUM' } ).length === 0 && textOf( portalText( kp ) ).indexOf( 'Review: 3 zum Kommentieren, 7 Kommentare' ) !== -1 && textOf( portalText( kp ) ).indexOf( 'Gezählt sind die Dateien, die der Kunde sehen darf' ) !== -1 );
	click( kp, all( kp.main, '.tabs button' )[3] );
	click( kp, all( kp.main, '.tabs button' )[2] );
	await settle();
	check( '...it is asked for once: the tab drawn again takes what it has', kp.all( 'crm-clients/portal' ).length === 1 && textOf( portalText( kp ) ).indexOf( 'Review: 3 zum Kommentieren' ) !== -1 );
	const koffp = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, { documents : [], features : { files : false, review : false }, folders : [], review : { media : 0, comments : 0 } } ] } } );
	check( 'with Files off the tab says that the portal shows none, with Review off it says nothing of comments, and with no document it says that nothing is released', textOf( portalText( koffp ) ).indexOf( 'Das Feature Dateien ist nicht aktiv, das Portal zeigt keine Dateien.' ) !== -1 && textOf( portalText( koffp ) ).indexOf( 'Review' ) === -1 && textOf( portalText( koffp ) ).indexOf( 'Nichts freigegeben.' ) !== -1 );
	const kfolderless = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, { documents : [], features : { files : true, review : true }, folders : [], review : { media : 0, comments : 0 } } ] } } );
	check( '...a client with no folder and nothing to comment on says that', textOf( portalText( kfolderless ) ).indexOf( 'Der Kunde hat noch keinen Ordner.' ) !== -1 && textOf( portalText( kfolderless ) ).indexOf( 'Review: nichts zum Kommentieren' ) !== -1 );
	check( 'the address of a tab opens it: #kunde-1-portal draws the Portal tab at once', all( koffp.main, '.tabs button' )[2].classList.contains( 'on' ) === true && koffp.last( 'crm-clients/portal' ) !== undefined );

	let notesSaved = null;
	const kt = await kopen( '#kunde-1-notizen', { 'crm-clients/save' : function( data ) { notesSaved = data; return [ 200, { client : Object.assign( {}, kclient, data ) } ] } } );
	check( 'the tab Notizen is a field with the notes of the client and a button', all( kt.main, '#notes' )[0].value === 'Zahlt pünktlich.' && textOf( all( kt.main, '#notes-save' )[0] ) === 'Speichern' );
	all( kt.main, '#notes' )[0].value = 'Seit Oktober nur noch per Überweisung.';
	click( kt, all( kt.main, '#notes-save' )[0] );
	await settle();
	check( '...Speichern posts the whole client with the notes as typed - the other fields as they are, for the model empties what is not posted - and says so', Object.keys( notesSaved ).sort().join() === 'business,buyer_reference,city,company,country,email,id,name,notes,phone,street,vat_id,zip' && typeof notesSaved.business === 'boolean' && notesSaved.business === kclient.business && notesSaved.notes === 'Seit Oktober nur noch per Überweisung.' && notesSaved.company === 'Nordlicht Records GmbH' && notesSaved.id === '1' && kt.toast.textContent === 'Notizen gespeichert.' );

	// "einladen": the card of the client, the tab Portal, the form open and its field focused
	const kinv = await kopen( '#kunde-3-einladen', { 'crm-clients/get' : function() { return [ 200, { client : kclients[2], accounts : [] } ] } } );
	const timerCount = kinv.timers.length;
	kinv.timers.slice().reverse().forEach( function( timer ) { if( timer.cleared !== true ) timer.fn() } );
	check( 'the address #kunde-3-einladen - what "einladen" in the Übersicht leads to - is the card of that client at the tab Portal with the invitation open, the address field the one with the cursor in it', all( kinv.main, '.tabs button' )[2].classList.contains( 'on' ) === true && all( kinv.main, '#invite' ).length === 1 && all( kinv.main, '#f-invite-email' )[0].focused === true
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
		if( data.company === '' && data.name === '' && data.account_email.trim() === '' )
			return [ 400, { error : 'x', code : 'crm_clients_empty', field : 'company' } ];
		const made = Object.assign( {}, kclient, { id : 6, number : 'K-0006', company : data.company, name : data.name } );
		if( data.account_email === '' )
			return [ 200, { client : made } ];
		if( data.account_email === 'taken@example.org' )
			return [ 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_taken', params : [], field : 'email' } } } ];
		if( data.account_email === 'long@example.org' )
			return [ 200, { client : made, first : { account : null, mailed : false, refused : { code : 'crm_clients_long', params : [ 120 ], field : 'name' } } } ];
		return [ 200, { client : made, first : Object.assign( { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.account_email, name : data.account_name, active : true, login : null }, mailed : data.account_email !== 'nUnsent@example.org' && data.notify !== false, refused : null }, data.notify === false ? { link : 'https://example.org/club/.members/login?t=FIRST' } : {} ) } ];
	} } );
	const nform = all( knewc.main, '#new-client-form' )[0];
	const nfield = function( name ) { return all( nform, '#f-'+ name )[0] };
	check( 'Neuer Kunde draws the form without asking for anything: the Stammdaten with their words and the first account, the country DE and a list of countries to choose from, the rail marks Kunden', nform !== undefined && knewc.all( 'crm-clients/save' ).length === 0
		&& all( nform, 'input' ).map( function( i ) { return i.id.slice( 2 ) } ).join() === 'account_email,account_name,account_notify,company,name,street,zip,city,country,email,phone,vat_id,buyer_reference,business' && nfield( 'account_notify' ).type === 'checkbox' && nfield( 'account_notify' ).checked === true && nfield( 'country' ).value === 'DE' && all( nform, 'option' ).map( function( o ) { return o.value } ).join() === 'DE,AT,CH'
		&& nfield( 'account_email' ).type === 'email' && all( nform, 'h3' ).map( textOf ).join( '|' ) === 'Erstes Portal-Konto|Stammdaten' && all( knewc.nav, '[data-r]' ).find( function( a ) { return a.getAttribute( 'data-r' ) === 'kunden' } ).classList.contains( 'on' ) === true );
	check( '...the Stammdaten say in a sentence that the client may enter company and name in the portal itself and that an address for the first account is enough - the sentence of the surface, under the heading of the block and before the fields', all( nform, '#newclient-master-text' ).length === 1 && textOf( all( nform, '#newclient-master-text' )[0] ) === texts['newclient.master-text'] && all( nform, '#newclient-master-text' )[0].parentNode === all( nform, 'h3' )[1].parentNode && texts['newclient.master-text'] !== '' );
	const ncols = all( nform, '.cols' )[0].children;
	check( '...the block of the first account comes first - the left card on the desktop, the top one on the phone - with its sentence under the heading, and the cursor starts in its field', ncols[0].id === 'newclient-first' && ncols[1].id === 'newclient-master' && all( ncols[0], '#f-account_email' ).length === 1 && all( ncols[1], '#f-company' ).length === 1 && all( ncols[1], '#f-account_email' ).length === 0 && textOf( all( nform, '#newclient-first-text' )[0] ) === texts['newclient.first-text'] && all( nform, '#newclient-first-text' )[0].parentNode === ncols[0] && nfield( 'account_email' ).focused === true );
	check( '...that the fields may stay empty is said once, in a sentence over the form, and not under some of the fields; the button says Anlegen until an address for the first account is typed, then Anlegen und einladen - and Anlegen again when it is cleared', textOf( all( nform, '#newclient-optional' )[0] ) === texts['newclient.optional'] && Array.from( nform.children ).indexOf( all( nform, '#newclient-optional' )[0] ) < Array.from( nform.children ).indexOf( all( nform, '.cols' )[0] ) && all( nform, '.state-note' ).filter( function( n ) { return textOf( n ) === texts['form.optional'] } ).length === 0 && nfield( 'account_name' ).getAttribute( 'placeholder' ) === null && textOf( all( nform, '#client-create' )[0] ) === 'Anlegen'
		&& ( type( nfield( 'account_email' ), 'nina@example.org', false ), textOf( all( nform, '#client-create' )[0] ) === 'Anlegen und einladen' ) && ( type( nfield( 'account_email' ), '  ', false ), textOf( all( nform, '#client-create' )[0] ) === 'Anlegen' ) );
	check( '...what is typed is unsaved input, and a link away asks first', knewc.app.state.draft.dirty === true && click( knewc, all( knewc.main, '#client-abort' )[0] ) === true && knewc.dialogs.length === 1 );
	no( knewc, 0 );
	await settle();
	type( nfield( 'account_email' ), '', false );
	submit( knewc, nform );
	await settle();
	check( 'a client without a company and a name is refused beside the field the answer names - the company - with its sentence, the cursor in it; the form stays, nothing opens', nfield( 'company' ).classList.contains( 'err' ) === true && nfield( 'company' ).focused === true && textOf( all( nform, '.state-note' ).find( function( n ) { return textOf( n ) !== '' && [ 'newclient-master-text', 'newclient-first-text', 'newclient-optional' ].indexOf( n.id ) === -1 } ) ) === texts['error.crm_clients_empty']
		&& knewc.location.hash === '#kunde-neu' && knewc.toast.classList.contains( 'bad' ) === true );
	type( nfield( 'company' ), 'Neu GmbH', false );
	type( nfield( 'name' ), 'Nina Neu', false );
	type( nfield( 'city' ), 'Bonn', false );
	const refreshBefore = knewc.all( 'crm-documents/overview' ).length;
	submit( knewc, nform );
	await settle();
	check( 'without an address the client is made alone: crm-clients/save with every field and the two of the account empty, no id, no notes - and the card of the new client opens, with the sentence that it is made, the guard dropped and the rail counted again', Object.keys( created ).sort().join() === 'account_email,account_name,business,buyer_reference,city,company,country,email,name,notify,phone,street,vat_id,zip' && created.notify === true && created.company === 'Neu GmbH' && created.account_email === '' && created.country === 'DE' && created.city === 'Bonn'
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
	const nBare = await nsubmit( { account_email : 'nur@example.org' } );
	check( 'with nothing but the address of the first account the client is made: crm-clients/save posts the account with company and name empty, the card of the new client opens, and the sentence says that the invitation is on its way', created.company === '' && created.name === '' && created.account_email === 'nur@example.org' && nBare.toast.textContent === 'Kunde K-0006 angelegt, die Einladung ist unterwegs.' && nBare.location.hash === '#kunde-6' );
	const nQuiet = await kopen( '#kunde-neu', { 'crm-clients/save' : knewc.answers['crm-clients/save'] } );
	const quietForm = all( nQuiet.main, '#new-client-form' )[0];
	type( all( quietForm, '#f-company' )[0], 'Still GmbH', false );
	type( all( quietForm, '#f-account_email' )[0], 'still@example.org', false );
	all( quietForm, '#f-account_notify' )[0].checked = false;
	submit( nQuiet, quietForm );
	await settle();
	check( 'with the box unticked the client is posted with notify false: no mail, the sentence says so, and the link of the invitation is shown in a dialog of its own while the card of the client opens', created.notify === false && nQuiet.toast.textContent === texts['newclient.done-invited-quiet'].replace( '%s', 'K-0006' ) && nQuiet.location.hash === '#kunde-6'
		&& all( nQuiet.dialogs[0], '#dlg-link' )[0].value === 'https://example.org/club/.members/login?t=FIRST' && nQuiet.dialogs[0].open === true );
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
	check( 'Positionen asks crm-documents/positionen and nothing else, and draws a read-only table: a column of words for each language of the site, then the unit, the net price, the rate and whether it is active', JSON.stringify( kp1.last( 'crm-documents/positionen' ).data ) === '{}' && all( ptable, 'th' ).map( textOf ).join( '|' ) === 'Titel (de)|Titel (en)|Einheit|Netto|USt|Aktiv|Reihenfolge ↑'
		&& all( ptable, 'input' ).length === 0 && all( ptable, 'tbody tr' ).length === 3 && textOf( all( kp1.main, 'h1' )[0] ) === 'Positionen' );
	const prow = function( index ) { return Array.from( all( ptable, 'tbody tr' )[index].children ) };
	check( '...a row has the title and the description of each language - a language with no text is empty - in the order of the sort the server gives, the unit once for each language that has one, the price and the rate as the line copies them, and ja or nein', textOf( prow( 0 )[0] ) === 'MixingMischung eines Songs · bis 48 Spuren' && textOf( prow( 0 )[1] ) === 'MixingMixing of one song' && textOf( prow( 0 )[2] ) === 'Std. / h'
		&& textOf( prow( 0 )[3] ) === '90,00'+ NBSP+ '€' && textOf( prow( 0 )[4] ) === '19 %' && textOf( prow( 0 )[5] ) === 'ja' && textOf( prow( 1 )[0] ) === '' && textOf( prow( 1 )[1] ) === 'Recording day' && textOf( prow( 1 )[2] ) === 'day' && textOf( prow( 1 )[4] ) === '7 %'
		&& textOf( prow( 2 )[5] ) === 'nein' && textOf( prow( 2 )[2] ) === 'Std.' );
	check( '...a title that is markup is text', textOf( prow( 2 )[0] ) === '<b>Alt</b>' && findAll( ptable, function( node ) { return node.tagName === 'B' } ).length === 0 );
	check( '...the link Im Panel Elemente bearbeiten is a secondary one, to the Elements panel at the type, in a tab of its own - there is no primary button, the account may not change anything', textOf( all( kp1.main, '#items-edit' )[0] ) === 'Im Panel Elemente bearbeiten' && all( kp1.main, '#items-edit' )[0].getAttribute( 'href' ) === '/_admin/#elements/positionen' && all( kp1.main, '#items-edit' )[0].classList.contains( 'primary' ) === false && all( kp1.main, '.primary' ).length === 0
		&& all( kp1.main, '#items-edit' )[0].getAttribute( 'target' ) === '_blank' && textOf( all( kp1.main, '.state-note' ).pop() ).indexOf( 'Nur zum Ansehen' ) === 0 );
	const kp2 = await popen( { locales : [ 'de_DE' ] }, smallBusiness );
	check( 'a site with one language has one column of words, and a small business has no rate to show: a stroke, as the line copies 0', all( all( kp2.main, '#items' )[0], 'th' ).map( textOf ).join( '|' ) === 'Titel (de)|Einheit|Netto|USt|Aktiv|Reihenfolge ↑' && textOf( all( all( kp2.main, '#items' )[0], 'tbody tr' )[0].children[3] ) === '–' );
	const kp3 = await popen( { missing : true, items : [] } );
	check( 'where the element type is not there the screen says so and points to the settings, where the portal is set up - no table', all( kp3.main, '#items' ).length === 0 && textOf( all( kp3.main, '.note' )[0] ).indexOf( 'Den Elementtyp Positionen gibt es noch nicht.' ) === 0 && all( all( kp3.main, '.note' )[0], 'a' )[0].getAttribute( 'href' ) === '#einstellungen' );
	const kp4 = await popen( { items : [] } );
	check( 'a type with no item says so', textOf( all( all( kp4.main, '#items' )[0], 'tbody' )[0] ) === 'Noch keine Position.' );
	const kp5 = await kopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 403, { error : 'x', code : 'x' } ] } } );
	check( 'a refusal says that the account may not, and offers to try again', textOf( kp5.main ).indexOf( 'Dafür fehlt Deinem Konto die Berechtigung.' ) !== -1 );

	// --- Einstellungen
	const esettings = { vat_mode : 'regular', vat_rates : '1900,700,550,0', payment_days : '14', offer_days : '30', currency : '€', number_offer : 'A-{YYYY}-{NNNN}', number_invoice : 'R-{YYYY}-{NNNN}', number_credit : 'G-{YYYY}-{NNNN}' };
	const eview = {
		settings : esettings, next : { offer : 'A-2026-0012', invoice : 'R-2026-0009', credit : 'G-2026-0002' }, pdf : true,
		templates : { offer : [ 'Angebot Standard' ], invoice : [ 'Rechnung Standard', 'Rechnung Englisch' ], contract : [ 'Dienstleistungsvertrag' ], privacy : [], information : [] },
		portal : { address : 'https://studio.example/portal', pages : [ { uri : '/portal', present : true }, { uri : '/portal/dokumente', present : false } ], members : [ { key : 'paths', label : 'Geschützte Pfade', value : '/portal', unit : '' }, { key : 'linkMinutes', label : 'Login-Link gültig für', value : '15', unit : 'minutes' }, { key : 'inviteDays', label : 'Einladung gültig für', value : '7', unit : 'days' }, { key : 'attempts', label : 'Anforderungen', value : '5', unit : 'per-hour' } ], mail_cap : 50 },
	};
	const ecompany = {
		native : 'de_DE', locales : [ { locale : 'en_US', active : true }, { locale : 'de_DE', active : true } ],
		keys : [
			{ key : '/project/company/contact/address', global : true, values : { '*' : 'Hafenstraße 12\n20457 Hamburg' } },
			{ key : '/project/company/contact/country', global : false, values : { de_DE : 'Deutschland', en_US : 'Germany' } },
			{ key : '/project/company/contact/email', global : true, values : { '*' : 'hallo@studio.example' } },
			{ key : '/project/company/contact/phone', global : true, values : { '*' : '+49 40 123456' } },
			{ key : '/project/company/general/description', global : false, values : { de_DE : 'Ein Tonstudio.', en_US : 'A recording studio.' } },
			{ key : '/project/company/general/name', global : true, values : { '*' : 'Studio Nordlicht' } },
		],
	};
	eview.company = ecompany;
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
	check( '...the portal, read only: the address, the pages with the ones the site has no route for marked, the settings of Members and the cap of mails of the kernel', all( all( es.main, '#portal' )[0], 'dt' ).map( textOf ).join( '|' ) === 'Adresse|Seiten|Mitglieder|Mail-Deckel' && all( all( es.main, '#portal' )[0], 'dd' ).map( textOf ).join( '|' ) === 'https://studio.example/portal|/portal · /portal/dokumente (fehlt)|Geschützte Pfade: /portalLogin-Link gültig für: 15 MinutenEinladung gültig für: 7 TageAnforderungen: 5 je Stunde|'+ texts['settings.portal-cap-value'].replace( '%s', '50' )+ ' '+ texts['settings.portal-cap-link']
		&& all( all( es.main, '#portal' )[0], 'input' ).map( function( i ) { return i.id } ).join() === 'settings-notify-files' );
	check( '...the two settings of days say beside their field that 0 enters no date - the hint is under both', findAll( es.main, function( node ) { return node.tagName === 'SPAN' && textOf( node ) === texts['settings.days-hint'] } ).length === 2
		&& textOf( efield( es, 'payment_days' ).parentNode ).indexOf( texts['settings.days-hint'] ) !== -1 && textOf( efield( es, 'offer_days' ).parentNode ).indexOf( texts['settings.days-hint'] ) !== -1 );
	check( '...the cap is shown with the way to the Config panel of the workbench, where it is changed (a link of its own, in a new tab), and the sentence under the block says that it is changed there and what the setup does', all( all( es.main, '#portal-cap' )[0], 'a' ).length === 1 && all( all( es.main, '#portal-cap' )[0], 'a' )[0].getAttribute( 'href' ) === '/_admin/#config' && all( all( es.main, '#portal-cap' )[0], 'a' )[0].getAttribute( 'target' ) === '_blank'
		&& textOf( all( es.main, '#portal-cap-note' )[0] ) === texts['settings.portal-cap-note'] && texts['settings.portal-cap-note'] !== '' && all( all( es.main, '#portal' )[0], 'input' ).map( function( i ) { return i.id } ).join() === 'settings-notify-files' );
	check( '...the status of the module says that all is well and the button that sets the portal up is hidden; the access block names the permission and links the panel of the users', textOf( all( es.main, '#status-ok' )[0] ) === 'Alles ist bereit: Das Portal ist eingerichtet und die Features laufen.' && all( es.main, '#portal-setup' )[0].hidden === true
		&& textOf( all( es.main, '#access' )[0] ).indexOf( 'Die Oberfläche braucht die Berechtigung /_admin/crm/manage.' ) !== -1 && all( all( es.main, '#access' )[0], 'a' )[0].getAttribute( 'href' ) === '/_admin/#users' );
	const ecapless = await eopen( { portal : Object.assign( {}, eview.portal, { address : '', mail_cap : 0, members : [] } ), pdf : false } );
	check( 'a site with no address says that no mail with a link goes out; a cap of 0 is no cap; a kernel with no key leaves the row out; Pdf off says that there are no templates', textOf( all( ecapless.main, '#portal' )[0] ).indexOf( 'Die Website hat keine Adresse: Ohne sie schickt das CRM keine Mail mit einem Link.' ) !== -1 && textOf( all( ecapless.main, '#portal-cap' )[0] ).indexOf( texts['settings.portal-cap-none'] ) !== -1 && texts['settings.portal-cap-none'] !== '' && all( all( ecapless.main, '#portal-cap' )[0], 'a' ).length === 1
		&& textOf( all( ecapless.main, '#templates' )[0] ).indexOf( 'Das Feature PDF ist nicht aktiv, ohne es gibt es keine Vorlagen.' ) !== -1 && ( await ( async function() {
			const none = await eopen( { portal : Object.assign( {}, eview.portal, { mail_cap : null } ) } );
			return all( all( none.main, '#portal' )[0], 'dt' ).map( textOf ).indexOf( 'Mail-Deckel' ) === -1 && all( none.main, '#portal-cap' ).length === 0 && all( none.main, '#portal-cap-note' ).length === 0;
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
	check( 'a save posts crm-documents/settings-save with the twenty-three settings as text - the rates as typed, 19,7,0 too: the server reads them, the screen has no conversion - and a refusal is said beside the field it names, the rates', Object.keys( posted[posted.length - 1] ).sort().join() === 'bank_name,bic,currency,iban,number_credit,number_invoice,number_offer,offer_days,payment_days,paypal,seller_city,seller_contact,seller_country,seller_email,seller_name,seller_phone,seller_street,seller_zip,tax_id,vat_exempt_text,vat_id,vat_mode,vat_rates_percent'
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
	const pitemsStatus = [ { key : 'module', state : 'ok' }, { key : 'data', state : 'unwritable' }, { key : 'sign', state : 'off' }, { key : 'pdf', state : 'missing' }, { key : 'portal', state : 'partial', lacks : [ 'routes', 'type-projekte' ] } ];
	let setups = 0;
	let statusItems = pitemsStatus;
	const est = await eopen( {}, undefined, {
		'crm/status' : function() { return [ 200, { items : statusItems } ] },
		'crm/setup' : function() { setups++; return [ 200, { added : { routes : [ 'a', 'b', 'c' ], templates : [ 'x' ], types : [ 'positionen.php' ], texts : 12, paths : false, start : false }, existed : { routes : [ 'd' ], templates : [ 'y', 'z' ], types : [], paths : true, start : true } } ] },
	} );
	check( 'a module that misses something says it line by line, in the words of the state - a portal that is partly there names what it lacks, in the words of the parts - and the button Portal einrichten is there', all( all( est.main, '#status-missing' )[0], 'li' ).map( textOf ).join( '|' ) === [
		'Das Verzeichnis data/ ist nicht beschreibbar, die Datenbank kann nicht angelegt werden.', texts['status.sign-off'], 'Das Feature PDF ist nicht installiert.',
		texts['status.portal-partial'].replace( '%s', 'Routen, der Elementtyp Projekte' ) ].join( '|' ) && all( est.main, '#portal-setup' )[0].hidden === false && textOf( all( est.main, '#portal-setup' )[0] ) === 'Portal einrichten' );
	type( efield( est, 'currency' ), 'CHF', false );
	statusItems = eok;
	click( est, all( est.main, '#portal-setup' )[0] );
	check( 'the button posts crm/setup with nothing, and is off while the request is out', est.last( 'crm/setup' ) !== undefined && JSON.stringify( est.last( 'crm/setup' ).data ) === '{}' && all( est.main, '#portal-setup' )[0].disabled === true );
	await settle();
	check( '...the status is asked for again and drawn: all is well, the button is gone, and what the setup did stands in one sentence with a place for each of the seven numbers - what a person typed in the form above stays where it is', setups === 1 && est.all( 'crm/status' ).length === 2 && all( est.main, '#status-ok' ).length === 1 && all( est.main, '#portal-setup' )[0].hidden === true
		&& textOf( all( est.main, '#setup-said' )[0] ) === 'Eingerichtet. Neu: Features 0, Routen 3, Vorlagen 1, Elementtypen 1, Worte 12, PDF-Vorlagen 0. Schon da: 3.' && efield( est, 'currency' ).value === 'CHF' );
	const ehome = await eopen( {}, eok.concat( [ { key : 'pdf-templates', state : 'none', lacks : [ 'rechnung' ] }, { key : 'home', state : 'other' } ] ) );
	check( 'the card of the portal offers the button while anything of the product is missing, the portal itself being in order: the PDF templates and the start page are lines of the status as well', all( ehome.main, '#portal-setup' )[0].hidden === false && all( all( ehome.main, '#status-missing' )[0], 'li' ).map( textOf ).join( '|' ) === texts['status.pdf-templates-none']+ '|'+ texts['status.home-other'] );
	const eunits = await eopen( {}, eok.concat( [ { key : 'features-units', state : 'partial', lacks : [ 'Mitglieder (27)', 'Dateien (1)' ], count : 28 } ] ), { 'crm/setup' : function() { return [ 200, { added : { features_units : { members : { words : 27, templates : [ 'page-members-confirm.tpl' ] }, files : { words : 1, templates : [] } } }, existed : {} } ] } } );
	check( 'the words and templates of the features that the site misses are a line of the status in the card of the portal: the features by name with the number each lacks, and the button is there', all( all( eunits.main, '#status-missing' )[0], 'li' ).map( textOf ).join( '|' ) === texts['status.features-units-partial'].replace( '%s', 'Mitglieder (27), Dateien (1)' ) && all( eunits.main, '#portal-setup' )[0].hidden === false );
	click( eunits, all( eunits.main, '#portal-setup' )[0] );
	await settle();
	check( '...and the sentence of the setup says how many words and templates the units of the features brought, in one place for each number', textOf( all( eunits.main, '#setup-said' )[0] ).indexOf( texts['setup.units'].replace( '%s', '28' ).replace( '%s', '1' ) ) !== -1 );
	// The run that enters the Datenschutzerklärung shows it in the field above, so that a save does not write the empty text the field had
	let entered = false;
	const privacyRun = await eopen( {}, eok.concat( [ { key : 'pdf-templates', state : 'none', lacks : [ 'rechnung' ] } ] ), {
		'crm/setup' : function() { entered = true; return [ 200, { added : { privacy : true }, existed : {} } ] },
		'crm-documents/settings-get' : function() { return [ 200, Object.assign( {}, eview, { settings : Object.assign( {}, eview.settings, entered === true ? { privacy_text : 'Datenschutzerklärung nach DSGVO', privacy_changed : '2026-11-05' } : { privacy_text : '', privacy_changed : '' } ) } ) ] },
	} );
	const privacyBefore = efield( privacyRun, 'privacy_text' ).value;
	click( privacyRun, all( privacyRun.main, '#portal-setup' )[0] );
	await settle();
	check( 'after Portal einrichten the field of the Datenschutzerklärung holds the text the run entered and the line under it names the day - and the field was empty before', privacyBefore === '' && efield( privacyRun, 'privacy_text' ).value === 'Datenschutzerklärung nach DSGVO' && textOf( all( privacyRun.main, '#privacy-changed' )[0] ) === texts['privacy.changed'].replace( '%s', '05.11.2026' ) );
	submit( privacyRun, all( privacyRun.main, '#privacy' )[0] );
	await settle();
	check( '...and a save posts that text and not the empty one', privacyRun.all( 'crm-documents/settings-save' ).length > 0 && privacyRun.all( 'crm-documents/settings-save' ).every( function( post ) { return post.data.privacy_text !== '' } ) );
	entered = false;
	const typedPrivacy = await eopen( {}, eok.concat( [ { key : 'pdf-templates', state : 'none', lacks : [ 'rechnung' ] } ] ), {
		'crm/setup' : function() { entered = true; return [ 200, { added : { privacy : true }, existed : {} } ] },
		'crm-documents/settings-get' : function() { return [ 200, entered === false ? eview : Object.assign( {}, eview, { settings : Object.assign( {}, eview.settings, { privacy_text : 'Der Text des Moduls', privacy_changed : '2026-11-05' } ), templates : Object.assign( {}, eview.templates, { offer : [ 'Angebot Neu' ] } ) } ) ] },
	} );
		check( 'before the run the note under the field of the Datenschutzerklärung is hidden', all( typedPrivacy.main, '#privacy-setup-note' )[0].hidden === true && all( all( typedPrivacy.main, '#templates' )[0], 'dd' ).map( textOf )[0] === 'Angebot Standard' );
	type( efield( typedPrivacy, 'privacy_text', 'privacy_text' ), 'Mein eigener Text', false );
	click( typedPrivacy, all( typedPrivacy.main, '#portal-setup' )[0] );
	await settle();
	check( '...a field the person is typing in is left as it is', efield( typedPrivacy, 'privacy_text' ).value === 'Mein eigener Text' );
	check( '...and a line under it says that the run entered a text and that the typed one is not saved yet - it goes when the text is back at what is stored', textOf( all( typedPrivacy.main, '#privacy-setup-note' )[0] ) === texts['privacy.setup-note'] && all( typedPrivacy.main, '#privacy-setup-note' )[0].hidden === false
		&& ( type( efield( typedPrivacy, 'privacy_text' ), efield( typedPrivacy, 'privacy_text' ).value === '' ? '' : '', false ), all( typedPrivacy.main, '#privacy-setup-note' )[0].hidden === true ) );
	check( 'the card Vorlagen shows what the run brought, from the answer of the same read: the row of the offer names the new template', all( all( typedPrivacy.main, '#templates' )[0], 'dd' ).map( textOf )[0] === 'Angebot Neu' && all( typedPrivacy.main, '#templates' ).length === 1 );
	const eown = await eopen( {}, eok.concat( [ { key : 'pdf-templates', state : 'ok' }, { key : 'home', state : 'own' } ] ) );
	check( 'a start page of the owner\'s is no line of what is missing in the card of the portal: all is well, and the button is hidden', all( eown.main, '#status-ok' ).length === 1 && all( eown.main, '#status-missing' ).length === 0 && all( eown.main, '#portal-setup' )[0].hidden === true );
	const emembers = await eopen( {}, pitemsStatus, { 'crm/setup' : function() { return [ 409, { error : 'x', code : 'crm_setup_feature', params : [ 'Mitglieder' ] } ] } } );
	click( emembers, all( emembers.main, '#portal-setup' )[0] );
	await settle();
	check( 'a setup that is refused says the sentence of its code, the button is back and the status is as it was', emembers.toast.textContent === 'Das Feature Mitglieder konnte nicht eingeschaltet werden. Das Panel Features nennt den Grund.' && all( emembers.main, '#portal-setup' )[0].disabled === false && all( emembers.main, '#status-missing' ).length === 1 && emembers.all( 'crm/status' ).length === 1 );
	const efailed = await eopen( {}, undefined, { 'crm/status' : function() { return [ 500, { error : 'x', code : 'crm_clients_database' } ] } } );
	check( 'a status that cannot be read fails the screen with its sentence and a way to try again', textOf( efailed.main ).indexOf( 'Die Datenbank hat die Anfrage nicht angenommen.' ) !== -1 && all( efailed.main, '#settings-form' ).length === 0 );

	// --- Einrichtung: the screen of the first run, from a status fixture
	const pdfKeys = [ 'angebot', 'rechnung', 'stornorechnung', 'vertrag', 'datenschutz', 'information' ];
	const ifresh = [ { key : 'module', state : 'ok' }, { key : 'data', state : 'ok' }, { key : 'members', state : 'off' }, { key : 'files', state : 'off' }, { key : 'review', state : 'off' }, { key : 'sign', state : 'off' }, { key : 'pdf', state : 'off' }, { key : 'events', state : 'missing' },
		{ key : 'portal', state : 'partial', lacks : [ 'paths', 'start' ] }, { key : 'pdf-templates', state : 'partial', lacks : [ 'Rechnung', 'Vertrag' ] }, { key : 'home', state : 'other' }, { key : 'mail-cap', state : 'default', value : 5 }, { key : 'notify', state : 'default', value : false }, { key : 'privacy', state : 'default' } ];
	const idone = ifresh.map( function( item ) { return { key : item.key, state : 'ok' } } );
	let iran = 0;
	let iwhole = false;
	const ianswers = function( over ) {
		return Object.assign( {
			'crm/status' : function() { return [ 200, { items : iwhole === true ? idone : ifresh, whole : iwhole, first_run : iwhole === false } ] },
			'crm/setup' : function() { iran++; iwhole = true; return [ 200, { added : { features : [ 'members', 'files' ], routes : [], templates : [], types : [], texts : 3, paths : true, start : true, pdf : { templates : pdfKeys, blocks : [], layout : [] }, home : 'starter', cap : 100, notify : true }, existed : { features : [], routes : [ 'a' ], templates : [ 'b' ], types : [], pdf : { templates : [] }, home : false, cap : false } } ] },
			'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
		}, over || {} );
	};
	const iopen = async function( hash, over ) {
		const w = world( { hash : hash, answers : ianswers( over ) } );
		w.boot();
		await settle();
		return w;
	};
	const irows = function( w ) { return all( w.main, '#einrichtung-rows' )[0].children };

	const first = await iopen( '' );
	check( 'the first showing of the page asks for the status before it draws the Übersicht, and a module that is not whole sends it to the Einrichtung: the overview is not asked for', first.sent().join() === 'crm/status' && first.location.hash === '#einrichtung' && first.all( 'crm-documents/overview' ).length === 0 && all( first.main, '#einrichtung-rows' ).length === 1 );
	check( '...the rail has its eight entries (PDF-Vorlagen hidden for an account without the Pdf permission) and none is marked: the Einrichtung is no entry of it', all( first.nav, '[data-r]' ).length === 8 && all( first.nav, '[data-r]' ).filter( function( a ) { return a.classList.contains( 'on' ) } ).length === 0 );
	check( '...a row for each item of the status, its word and its state: done for the module and the data directory, to do for the rest', irows( first ).length === ifresh.length && Array.from( irows( first ) ).map( function( li ) { return li.getAttribute( 'data-key' )+ ':'+ li.getAttribute( 'data-state' ) } ).join() === 'module:ok,data:ok,members:todo,files:todo,review:todo,sign:todo,pdf:todo,events:todo,portal:todo,pdf-templates:todo,home:todo,mail-cap:todo,notify:todo,privacy:todo'
		&& textOf( irows( first )[2] ).indexOf( texts['einrichtung.row-members'] ) === 0 && textOf( irows( first )[2] ).indexOf( texts['status.members-off'] ) !== -1 && textOf( irows( first )[2] ).indexOf( texts['einrichtung.state-todo'] ) !== -1 && textOf( irows( first )[0] ).indexOf( texts['status.allok'] ) === -1 );
	check( '...what is missing is said in one sentence of the status: the portal names what it lacks, the PDF templates their keys, the start page that it is not the door yet', textOf( irows( first )[8] ).indexOf( texts['status.portal-partial'].replace( '%s', texts['status.lacks-paths']+ ', '+ texts['status.lacks-start'] ) ) !== -1
		&& textOf( irows( first )[9] ).indexOf( texts['status.pdf-templates-partial'].replace( '%s', 'Rechnung, Vertrag' ) ) !== -1 && textOf( irows( first )[10] ).indexOf( texts['status.home-other'] ) !== -1 );
	check( '...the cap of mails is the last row: its word, the kernel\'s default said with its number (5), and to do - and a cap that config.php carries is done, with no sentence', textOf( irows( first )[11] ).indexOf( texts['einrichtung.row-mail-cap'] ) === 0 && textOf( irows( first )[11] ).indexOf( texts['status.mail-cap-default'].replace( '%s', '5' ) ) !== -1 && texts['status.mail-cap-default'].split( '%s' ).length === 2
		&& textOf( irows( first )[11] ).indexOf( texts['einrichtung.state-todo'] ) !== -1 && texts['einrichtung.row-mail-cap'] !== '' );
	check( '...the word of the row stands once in it, not again at the start of the sentence', textOf( irows( first )[11] ).split( texts['einrichtung.row-mail-cap'] ).length === 2 );
	check( '...the notification of the owner is a row of its own after the cap: its word, the sentence of the status for a setting without a value, to do - and a setting that has a value (the owner\'s, off among them) is done with no sentence', textOf( irows( first )[12] ).indexOf( texts['einrichtung.row-notify'] ) === 0 && textOf( irows( first )[12] ).indexOf( texts['status.notify-default'] ) !== -1 && irows( first )[12].getAttribute( 'data-state' ) === 'todo'
		&& textOf( irows( first )[12] ).split( texts['einrichtung.row-notify'] ).length === 2 && texts['einrichtung.row-notify'] !== '' && texts['status.notify-default'] !== '' && texts['status.notify-ok'] !== '' );
	const notifyOk = await iopen( '', { 'crm/status' : function() { return [ 200, { items : ifresh.map( function( item ) { return item.key === 'notify' ? { key : 'notify', state : 'ok', value : false } : item } ), whole : false, first_run : true } ] } } );
	check( '...done with the value off: the row is ok and says no sentence', irows( notifyOk )[12].getAttribute( 'data-state' ) === 'ok' && textOf( irows( notifyOk )[12] ).indexOf( texts['status.notify-ok'] ) === -1 && textOf( irows( notifyOk )[12] ).indexOf( texts['status.notify-default'] ) === -1 );
	const capOk = await iopen( '', { 'crm/status' : function() { return [ 200, { items : ifresh.map( function( item ) { return item.key === 'mail-cap' ? { key : 'mail-cap', state : 'ok', value : 100 } : item } ), whole : false, first_run : true } ] } } );
	check( '...a cap that config.php carries is done and its row says it with the number in force (the sentence of an ok line is drawn for this row, the others are not)', irows( capOk )[11].getAttribute( 'data-state' ) === 'ok' && textOf( irows( capOk )[11] ).indexOf( texts['status.mail-cap-ok'].replace( '%s', '100' ) ) !== -1 && textOf( irows( capOk )[11] ).split( texts['einrichtung.row-mail-cap'] ).length === 2
		&& textOf( irows( capOk )[0] ).indexOf( texts['status.allok'] ) === -1 && textOf( irows( capOk )[0] ).length < 40 );
	const capCard = await eopen( {}, eok.concat( [ { key : 'mail-cap', state : 'default', value : 5 } ] ) );
	check( '...and in the card of the portal, where the line stands among the others, it is named by the word of the row and then says its sentence', textOf( all( all( capCard.main, '#status-missing' )[0], 'li' )[0] ) === texts['einrichtung.row-mail-cap']+ ': '+ texts['status.mail-cap-default'].replace( '%s', '5' ) );
	const notifyCard = await eopen( {}, eok.concat( [ { key : 'notify', state : 'default', value : false } ] ) );
	check( '...and in the card of the portal the notification is named by the word of its row and then says its sentence', textOf( all( all( notifyCard.main, '#status-missing' )[0], 'li' )[0] ) === texts['einrichtung.row-notify']+ ': '+ texts['status.notify-default'] );
	check( '...one button Einrichten, there, and no Fertig. while it is not whole', all( first.main, '#einrichtung-run' ).length === 1 && all( first.main, '#einrichtung-run' )[0].hidden === false && textOf( all( first.main, '#einrichtung-run' )[0] ) === 'Einrichten' && all( first.main, '#einrichtung-done' ).length === 0 && all( first.main, '#einrichtung-after' ).length === 0 );
	click( first, all( first.main, '#einrichtung-run' )[0] );
	check( 'the button posts crm/setup with nothing, and is off while the request is out', first.last( 'crm/setup' ) !== undefined && JSON.stringify( first.last( 'crm/setup' ).data ) === '{}' && all( first.main, '#einrichtung-run' )[0].disabled === true );
	await settle();
	check( '...the status is asked for again and drawn: every row done, the button gone, Fertig. with the way on to the Übersicht, and what the run did in a sentence - and the start page said once', iran === 1 && first.all( 'crm/status' ).length === 2 && Array.from( irows( first ) ).every( function( li ) { return li.getAttribute( 'data-state' ) === 'ok' } )
		&& all( first.main, '#einrichtung-run' )[0].hidden === true && textOf( all( first.main, '#einrichtung-done' )[0] ).indexOf( 'Fertig.' ) === 0 && all( all( first.main, '#einrichtung-done' )[0], 'a' )[0].getAttribute( 'href' ) === '#uebersicht' && textOf( all( first.main, '#einrichtung-after' )[0] ) === texts['einrichtung.after']
		&& textOf( all( first.main, '#einrichtung-said' )[0] ) === 'Eingerichtet. Neu: Features 2, Routen 0, Vorlagen 0, Elementtypen 0, Worte 3, PDF-Vorlagen 6. Schon da: 2. '+ texts['setup.cap'].replace( '%s', '100' )+ ' '+ texts['setup.notify']+ ' '+ texts['setup.home-starter'] && texts['setup.cap'].split( '%s' ).length === 2 && texts['setup.notify'] !== '' );

	// The Datenschutzerklärung: a row of its own after the notification, and the sentence of the run says what became of it
	const privacyFresh = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : ifresh, whole : false, first_run : true } ] } } );
	check( 'the Datenschutzerklärung is the row after the notification: its word, the sentence of the status for an empty setting, to do - and done with no sentence once a text is there', textOf( irows( privacyFresh )[13] ).indexOf( texts['einrichtung.row-privacy'] ) === 0 && textOf( irows( privacyFresh )[13] ).indexOf( texts['status.privacy-default'] ) !== -1 && irows( privacyFresh )[13].getAttribute( 'data-state' ) === 'todo'
		&& texts['einrichtung.row-privacy'] !== '' && texts['status.privacy-default'] !== '' && texts['status.privacy-ok'] !== '' );
	const privacyCard = await eopen( {}, eok.concat( [ { key : 'privacy', state : 'default' } ] ) );
	check( '...and in the card of the portal it is named by the word of its row and then says its sentence', textOf( all( all( privacyCard.main, '#status-missing' )[0], 'li' )[0] ) === texts['einrichtung.row-privacy']+ ': '+ texts['status.privacy-default'] );
	// A Datenschutzerklärung that was emptied on purpose
	const privacyEmpty = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : ifresh.map( function( item ) { return item.key === 'privacy' ? { key : 'privacy', state : 'ok', empty : true } : item } ), whole : false, first_run : true } ] } } );
	const privacyFull = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : ifresh.map( function( item ) { return item.key === 'privacy' ? { key : 'privacy', state : 'ok' } : item } ), whole : false, first_run : true } ] } } );
	const privacyRowOf = function( w ) { return irows( w ).find( function( one ) { return textOf( one ).indexOf( texts['einrichtung.row-privacy'] ) === 0 } ) };
	check( 'a Datenschutzerklärung that was emptied on purpose is done and says so in its row, with the word for it - an ok row with a text says nothing',
		privacyRowOf( privacyEmpty ) !== undefined && privacyRowOf( privacyEmpty ).getAttribute( 'data-state' ) !== 'todo' && texts['status.privacy-empty'] !== '' && textOf( privacyRowOf( privacyEmpty ) ).indexOf( texts['status.privacy-empty'] ) !== -1 && textOf( privacyRowOf( privacyFull ) ).indexOf( texts['status.privacy-empty'] ) === -1 );
	for( const [ kind, word ] of [ [ 'added', 'setup.privacy' ], [ 'existed', 'setup.privacy-there' ] ] ) {
		const said = { added : { features : [], routes : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : false, privacy : kind === 'added' }, existed : { features : [], routes : [], templates : [], types : [], pdf : { templates : [] }, home : false, privacy : kind === 'existed' } };
		let ran = false;
		const sayScreen = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : ran === true ? idone : ifresh, whole : ran, first_run : ran === false } ] }, 'crm/setup' : function() { ran = true; return [ 200, said ] } } );
		click( sayScreen, all( sayScreen.main, '#einrichtung-run' )[0] );
		await settle();
		const sentence = textOf( all( sayScreen.main, '#einrichtung-said' )[0] );
		check( 'the run that answers privacy in '+ kind+ ' ends its sentence with the words of '+ word+ ', and the other one is not said', texts[word] !== '' && sentence.slice( -texts[word].length ) === texts[word] && sentence.indexOf( texts[word === 'setup.privacy' ? 'setup.privacy-there' : 'setup.privacy'] ) === -1 );
	}

	// The start page of the owner's own: no lack, kept - the row says what it is, the answer says that it stays
	let ownRun = false;
	const ownItems = function( items ) { return items.map( function( item ) { return item.key === 'home' ? { key : 'home', state : 'own' } : item } ) };
	const ownScreen = await iopen( '#einrichtung', {
		'crm/status' : function() { return [ 200, { items : ownItems( ownRun === true ? idone : ifresh ), whole : false } ] },
		'crm/setup' : function() { ownRun = true; return [ 200, { added : { features : [], routes : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : false }, existed : { features : [], routes : [], templates : [], types : [], pdf : { templates : [] }, home : 'own' } } ] },
	} );
	check( 'a start page of the owner\'s is no lack: its row is done, and says what it is - not Fehlt', irows( ownScreen )[10].getAttribute( 'data-state' ) === 'ok' && textOf( irows( ownScreen )[10] ).indexOf( texts['status.home-own'] ) !== -1 && textOf( irows( ownScreen )[10] ).indexOf( texts['einrichtung.state-todo'] ) === -1 );
	click( ownScreen, all( ownScreen.main, '#einrichtung-run' )[0] );
	await settle();
	check( '...after the run the product is whole with it - Fertig. - and the answer says that the start page stays and where the door is, the sentence of the words file', all( ownScreen.main, '#einrichtung-done' ).length === 1 && textOf( all( ownScreen.main, '#einrichtung-said' )[0] ).slice( -texts['setup.home-own'].length ) === texts['setup.home-own'] && texts['setup.home-own'] === 'Deine Startseite bleibt; das Tor des Portals liegt unter /portal.' );
	let newRun = false;
	const newScreen = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : newRun === true ? idone : ifresh, whole : false } ] }, 'crm/setup' : function() { newRun = true; return [ 200, { added : { features : [], routes : [], templates : [], types : [], texts : 0, pdf : { templates : [] }, home : 'new' }, existed : { features : [], routes : [], templates : [], types : [], pdf : { templates : [] }, home : false } } ] } } );
	click( newScreen, all( newScreen.main, '#einrichtung-run' )[0] );
	await settle();
	check( 'where there was no start page the answer says the door is the start page now, in the sentence of the words file, without the page of the wizard', all( newScreen.main, '#einrichtung-done' ).length === 1 && textOf( all( newScreen.main, '#einrichtung-said' )[0] ).slice( -texts['setup.home-new'].length ) === texts['setup.home-new'] && texts['setup.home-new'] === 'Die Startseite ist jetzt das Tor des Portals.' && textOf( all( newScreen.main, '#einrichtung-said' )[0] ).indexOf( '/home' ) === -1 );
	iwhole = false;

	const unfinished = await iopen( '', { 'crm/status' : function() { return [ 200, { items : ifresh, whole : false, first_run : false } ] } } );
	check( 'a product that is not whole but was set up once (first_run false) is not sent to the Einrichtung: a template that was deleted does not bring the screen back on every load', unfinished.all( 'crm-documents/overview' ).length === 1 && unfinished.location.hash === '' && all( unfinished.main, '#einrichtung-rows' ).length === 0 );
	const reached = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 200, { items : ifresh, whole : false, first_run : false } ] } } );
	check( '...and the screen is there for the one who opens it by its address: it lists what is missing', all( reached.main, '#einrichtung-rows' ).length === 1 && Array.from( irows( reached ) ).filter( function( li ) { return li.getAttribute( 'data-state' ) === 'todo' } ).length === 12 );
	check( 'no sentence of the status names the button by a label of its own - the screen\'s button is Einrichten, the card\'s Portal einrichten -: the sentences speak of das Einrichten', Object.keys( texts ).filter( function( key ) { return /^status\./.test( key ) && /Drück|Portal einrichten/.test( texts[key] ) } ).length === 0 && /^status\.portal-/.test( 'status.portal-none' ) );
	iwhole = true;
	const whole = await iopen( '' );
	check( 'a module that is whole starts on the Übersicht: the status is asked once, then the overview, and the Einrichtung is not shown', whole.sent().join() === 'crm/status,crm-documents/overview' && whole.location.hash === '' && all( whole.main, '#einrichtung-rows' ).length === 0 && all( whole.nav, '[data-r]' )[0].classList.contains( 'on' ) === true );
	const direct = await iopen( '#einrichtung' );
	check( 'the router sends #einrichtung to the Übersicht when the status is whole', direct.location.hash === '#uebersicht' && direct.all( 'crm-documents/overview' ).length === 1 && all( direct.main, '#einrichtung-rows' ).length === 0 );
	const unreadable = await iopen( '', { 'crm/status' : function() { return [ 500, { error : 'x', code : 'crm_clients_database' } ] } } );
	check( 'a status that cannot be read does not hold the page back: the Übersicht is drawn', unreadable.all( 'crm-documents/overview' ).length === 1 && all( unreadable.main, '#einrichtung-rows' ).length === 0 );
	const elsewhere = await iopen( '#belege', { 'crm-documents/list' : function() { return [ 200, { documents : [ row( {} ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } );
	check( 'the first showing of ANY screen asks for the status, once: a deep link to Belege of a product that is whole is drawn as it is named, and the status is not asked again by the next screen', elsewhere.all( 'crm/status' ).length === 1 && elsewhere.location.hash === '#belege' && elsewhere.all( 'crm-documents/list' ).length === 1 && all( elsewhere.main, '#einrichtung-rows' ).length === 0 );
	iwhole = false;
	const deep = await iopen( '#kunden' );
	check( '...and a deep link of a product that was never set up goes to the Einrichtung all the same: the screen of the address is not drawn', deep.all( 'crm/status' ).length === 1 && deep.all( 'crm-clients/list' ).length === 0 && deep.location.hash === '#einrichtung' && all( deep.main, '#einrichtung-rows' ).length === 1 );
	const refused = await iopen( '#einrichtung', { 'crm/setup' : function() { return [ 409, { error : 'x', code : 'crm_setup_feature', params : [ 'Termine' ] } ] } } );
	click( refused, all( refused.main, '#einrichtung-run' )[0] );
	await settle();
	check( 'a run that is refused names the feature in the sentence of its code, the button is back, the status is read again and the rows are drawn from it, the said-line is empty - no sentence of a run that did not go through - and the refusal stays in the toast', textOf( all( refused.main, '#einrichtung-said' )[0] ) === '' && refused.toast.textContent === 'Das Feature Termine konnte nicht eingeschaltet werden. Das Panel Features nennt den Grund.' && all( refused.main, '#einrichtung-run' )[0].disabled === false && all( refused.main, '#einrichtung-run' )[0].hidden === false && refused.all( 'crm/status' ).length === 2 && irows( refused ).length === ifresh.length );
	const stopped = await iopen( '#einrichtung', { 'crm/status' : function() { return [ 500, { error : 'x', code : 'crm_clients_database' } ] } } );
	check( 'a status that cannot be read fails the Einrichtung with its sentence and a way to try again', textOf( stopped.main ).indexOf( 'Die Datenbank hat die Anfrage nicht angenommen.' ) !== -1 && all( stopped.main, '#einrichtung-rows' ).length === 0 );
	let releaseRun = null;
	const lateRun = await iopen( '#einrichtung', { 'crm/setup' : function() { return new Promise( function( resolve ) { releaseRun = resolve } ) } } );
	click( lateRun, all( lateRun.main, '#einrichtung-run' )[0] );
	lateRun.location.hash = '#kunden';
	await settle();
	const statusBefore = lateRun.all( 'crm/status' ).length;
	releaseRun( [ 200, { added : {}, existed : {} } ] );
	await settle();
	check( 'the answer of a run that arrives after the screen was left changes nothing: no second status, the screen of the clients stays', lateRun.all( 'crm/status' ).length === statusBefore && all( lateRun.main, '#einrichtung-rows' ).length === 0 );
	check( 'every word the Einrichtung asks for is a word of the page: the title, the intro, the states, the button, Fertig., the way on, and a word for every item of the status', [ 'title', 'intro', 'rows', 'state-ok', 'state-todo', 'run', 'done', 'next', 'after' ].concat( ifresh.map( function( item ) { return 'row-'+ item.key } ) ).every( function( key ) { return typeof texts['einrichtung.'+ key] === 'string' && texts['einrichtung.'+ key] !== '' } ) );

	// --- the words of the new screens: every key the script names is a word, and so is every one of the families it builds
	const kwords = new Set();
	let kfound;
	const kpattern = /'((?:acc|card|ctab|cdocs|cportal|cnotes|clients|clientfield|invite|link|newclient|items|settings|tag|status|setup|form|column|error)\.[a-z0-9_-]+)'/g;

	while( ( kfound = kpattern.exec( script ) ) )
		kwords.add( kfound[1] );

	const kfamilies = { 'acc.state-' : [ 'signed', 'invited', 'off', 'gone' ], 'status.lacks-' : [ 'routes', 'templates', 'texts', 'type-positionen', 'type-projekte', 'paths', 'start' ], 'ctab.' : [ 'belege', 'portal', 'notizen' ], 'tag.' : [ 'offer', 'invoice', 'contract', 'privacy', 'information' ], 'settings.number-' : [ 'offer', 'invoice', 'credit' ],
		'clientfield.' : [ 'company', 'name', 'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id', 'account_email', 'account_name' ], 'status.' : [ 'allok', 'module-off', 'data-unwritable', 'portal-none', 'portal-partial', 'members-missing', 'members-off', 'members-problem', 'files-off', 'review-off', 'sign-off', 'pdf-missing', 'pdf-problem', 'events-off', 'pdf-templates-none', 'pdf-templates-partial', 'home-other' ],
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

	const po = panelWorld( 'de_DE' );
	po.panel.showCurrent();
	po.answer( 200, { items : eok.concat( [ { key : 'pdf-templates', state : 'ok' }, { key : 'home', state : 'own' } ] ) } );
	check( 'the panel does not count a start page of the owner\'s as missing: all is well, the button is hidden', po.texts().indexOf( po.words['/_admin/crm/status/allok'] ) !== -1 && po.find( function( node ) { return node.id === 'crm-setup' } ).hidden === true && po.missing.length === 0 );
	const pm = panelWorld( 'de_DE' );
	pm.panel.showCurrent();
	pm.answer( 200, { items : pitemsStatus } );
	const pmLines = findAll( pm.mount, function( node ) { return node.tagName === 'LI' } ).map( function( node ) { return node.textContent } );
	check( 'what is missing is told line by line in the words of its state, a portal that is partly there names what it lacks, and the button is there', pmLines.length === 4 && pmLines[1] === pm.words['/_admin/crm/status/sign-off'] && pmLines[3] === pm.said( '/_admin/crm/status/portal-partial', 'Routen, der Elementtyp Projekte' )
		&& pm.find( function( node ) { return node.id === 'crm-setup' } ).hidden === false && pm.find( function( node ) { return node.id === 'crm-setup' } ).textContent === 'Portal einrichten' );
	const pmButton = pm.find( function( node ) { return node.id === 'crm-setup' } );
	pmButton.click();
	check( 'the button posts crm/setup with nothing, and is off while the request is out', pm.last().action === 'crm/setup' && JSON.stringify( pm.last().payload ) === '{}' && pmButton.disabled === true );
	pm.answer( 200, { added : { routes : [ 'a', 'b', 'c' ], templates : [ 'x' ], types : [ 'positionen.php' ], texts : 12, paths : false, start : false }, existed : { routes : [ 'd' ], templates : [ 'y', 'z' ], types : [], paths : true, start : true } } );
	check( '...the status is asked for again after it', pm.last().action === 'crm/status' && pm.requests.length === 3 );
	pm.answer( 200, { items : eok } );
	check( '...and what the setup did stays on the screen after the box is drawn again: one sentence with a place for each of the seven numbers', pm.texts().indexOf( 'Eingerichtet. Neu: Features 0, Routen 3, Vorlagen 1, Elementtypen 1, Worte 12, PDF-Vorlagen 0. Schon da: 3.' ) !== -1 && pm.words['/_admin/crm/setup/done'].split( '%s' ).length === 8
		&& pm.find( function( node ) { return node.id === 'crm-setup' } ).hidden === true );
	const pr = panelWorld( 'de_DE' );
	pr.panel.showCurrent();
	pr.answer( 200, { items : pitemsStatus } );
	pr.find( function( node ) { return node.id === 'crm-setup' } ).click();
	pr.answer( 409, { error : 'x', code : 'crm_setup_feature', params : [ 'Mitglieder' ] } );
	check( 'a refusal of the setup says its sentence in the line, and the button is back', pr.texts().indexOf( pr.said( '/_admin/error/crm_setup_feature', 'Mitglieder' ) ) !== -1 && pr.find( function( node ) { return node.id === 'crm-setup' } ).disabled === false && pr.requests.length === 2 );
	const pf = panelWorld( 'en_US' );
	pf.panel.showCurrent();
	pf.answer( 200, { items : pitemsStatus } );
	check( 'the English panel has every word as well, in the same keys', pf.missing.length === 0 && pf.texts().indexOf( pf.words['/_admin/crm/status/sign-off'] ) !== -1 && Object.keys( fills( 'app/Crm/Crm/Admin/text/de_DE.php' ) ).join() === Object.keys( fills( 'app/Crm/Crm/Admin/text/en_US.php' ) ).join() );
	const panelScript = source( 'app/Crm/Crm/Admin/assets/admin.js' );
	check( 'the script of the panel assigns no markup: innerHTML only to clear, no outerHTML, no insertAdjacentHTML, no document.write; and it asks for nothing but crm/status and crm/setup', panelScript.match( /innerHTML\s*=(?!\s*'';)/g ) === null && /outerHTML|insertAdjacentHTML|document\.write/.test( panelScript ) === false
		&& [ ...panelScript.matchAll( /call\( '([a-z/-]+)'/g ) ].map( function( found ) { return found[1] } ).filter( function( action, index, list ) { return list.indexOf( action ) === index } ).sort().join() === 'setup,status' );

	// === PDF-Vorlagen: the rail, the four screens and the two editors, from fixtures (a block of its own: the names of the fixtures are its)
	{
	const pperms = { crm : true, pdf : true };
	const poffer = { tags : [ 'offer', 'invoice', 'contract' ], library : [ { key : 'zahlung', title : 'Zahlung' }, { key : 'kontakt', title : 'Kontakt' } ], paper : [ 210, 297 ], ranges : { title : 120, html : 20000, label : 120, blocks : 60 } };
	const ptemplates = [
		{ key : 'rechnung', name : 'Rechnung', tag : 'invoice', blocks : 6, known : true },
		{ key : 'angebot', name : 'Angebot', tag : 'offer', blocks : 2, known : true },
		{ key : 'alt', name : 'Älteres Format', tag : 'legacy', blocks : 1, known : false },
	];
	const pcontext = { used : { rechnung : 3 }, changed : { rechnung : '2026-10-05', angebot : '2026-09-01', alt : '2025-01-02' }, blocks : { zahlung : [ 'Rechnung', 'Angebot' ] }, site : [ '/project/company/contact/email', '/project/company/general/name', '/project/company/general/legal' ] };
	const pblocks = [
		{ type : 'html', html : '<h1>Rechnung</h1>' }, { type : 'html', block : 'zahlung' }, { type : 'slot', name : 'lines' },
		{ type : 'input', name : 'hinweis', label : 'Hinweis', multiline : true, required : false },
		{ type : 'signature', name : 'inhaber', party : 'owner', label : 'Inhaber', page : 'last', x : 20, y : 230.5, w : 70, h : 20, line : true },
		{ type : 'pagebreak' },
	];
	const playout = { format : 'a4', margins : [ 20, 20, 25, 20 ], font : 'helvetica', size : 10, header : '<p>Kopf</p>', footer : '<p>Fuß</p>', design : 'h1 { font-size: 20pt }', logo : 'logo.png', signature : '' };
	const pimage = 'data:image/png;base64,iVBORw0KGgo=';
	const playoutBody = function( over ) {
		return Object.assign( { layout : playout, sheet : 'body { font-size: 10pt }', refusal : null, images : { logo : pimage, signature : '' }, papers : [ 'a4', 'letter' ], fonts : [ 'helvetica', 'times', 'courier' ],
			ranges : { margin : [ 5, 50 ], size : [ 8, 14 ], band : 4000, design : 8000 } }, over || {} );
	};
	const psaves = [];

	const panswers = function( over ) {
		return Object.assign( {
			'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
			'pdf/templates/list' : function() { return [ 200, Object.assign( { templates : ptemplates }, poffer ) ] },
			'pdf/templates/get' : function( data ) { return data.key === 'rechnung' ? [ 200, Object.assign( { key : 'rechnung', template : { name : 'Rechnung', tag : 'invoice', blocks : pblocks } }, poffer ) ] : [ 404, { error : 'x', code : 'pdf_template_missing' } ] },
			'pdf/templates/save' : function( data ) { psaves.push( data ); return [ 200, Object.assign( { key : data.key, templates : ptemplates }, poffer ) ] },
			'pdf/blocks/list' : function() { return [ 200, { blocks : [ { key : 'zahlung', title : 'Zahlung', html : '<p>Zahlbar <b>sofort</b>.</p>' }, { key : 'kontakt', title : 'Kontakt', html : '<p>Wir</p>' } ], ranges : { title : 120, html : 20000 } } ] },
			'pdf/layout/get' : function() { return [ 200, playoutBody() ] },
			'crm-pdf/context' : function() { return [ 200, pcontext ] },
		}, over || {} );
	};

	const popen = async function( hash, over, perms ) {
		const w = world( { hash : hash, answers : panswers( over ), perms : perms === undefined ? pperms : perms } );
		w.boot();
		await settle();
		return w;
	};

	const pdfCalls = function( w ) { return w.sent().filter( function( action ) { return /^(pdf|crm-pdf)\//.test( action ) } ) };
	const itemOf = function( root, label ) { return all( root, '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === label } ) };
	const menuOf = function( root, label ) { return all( root, '.menu' ).find( function( one ) { return textOf( all( one, 'button' )[0] ) === label } ) };
	const cards = function( w ) { return all( w.main, '#blocks .block' ) };
	const fieldOf = function( card, name ) { return all( card, '[data-f="'+ name+ '"]' )[0] };
	const answerFail = function( status, code, params, field ) { return [ status, { error : 'x', code : code, params : params || [], field : field || '' } ] };

	// --- the rail and the permission
	const p0 = await popen( '#pdf' );
	const pNone = await popen( '#einstellungen', {}, null );
	check( 'the rail shows PDF-Vorlagen for the account that holds the permission of the Pdf panel and keeps it hidden for the one that does not', all( p0.nav, '#nav-pdf' )[0].hidden === false && all( pNone.nav, '#nav-pdf' )[0].hidden === true );
	check( '...the entry is marked on every screen of PDF-Vorlagen and on none of the others', all( p0.nav, '[data-r]' ).filter( function( a ) { return a.classList.contains( 'on' ) } ).map( function( a ) { return a.getAttribute( 'data-r' ) } ).join() === 'pdf'
		&& [ '#pdf-bausteine', '#pdf-layout', '#pdf-design', '#pdf-vorlage-rechnung', '#pdf-neue-vorlage-offer', '#pdf-baustein-zahlung', '#pdf-neuer-baustein' ].every( function( hash ) { return p0.app.parse( hash ).screen.indexOf( 'pdf' ) === 0 } ) );
	const pDenied = await popen( '#pdf', {}, { crm : true, pdf : false } );
	check( 'a deep link without the permission draws the sentence of the screen and asks for nothing of the Pdf panel', pdfCalls( pDenied ).length === 0 && all( pDenied.main, '.note' ).length === 1 && textOf( all( pDenied.main, '.note' )[0] ) === texts['pdf.forbidden'] && all( pDenied.main, 'table' ).length === 0 );
	const pDeniedEditor = await popen( '#pdf-vorlage-rechnung', {}, { crm : true, pdf : false } );
	const pDeniedLayout = await popen( '#pdf-layout', {}, { crm : true, pdf : false } );
	check( '...the editors and the other screens say the same', pdfCalls( pDeniedEditor ).length === 0 && pdfCalls( pDeniedLayout ).length === 0 && textOf( all( pDeniedEditor.main, '.note' )[0] ) === texts['pdf.forbidden'] && textOf( all( pDeniedLayout.main, '.note' )[0] ) === texts['pdf.forbidden'] );

	// --- Vorlagen
	check( 'Vorlagen asks for the templates of the Pdf panel and for what only the CRM knows, and has the four tabs with the first marked', p0.sent().indexOf( 'pdf/templates/list' ) !== -1 && p0.sent().indexOf( 'crm-pdf/context' ) !== -1
		&& all( p0.main, '#pdf-tabs a' ).map( textOf ).join( '|' ) === 'Vorlagen|Bausteine|Layout|Design' && all( p0.main, '#pdf-tabs a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join() === '#pdf,#pdf-bausteine,#pdf-layout,#pdf-design'
		&& all( p0.main, '#pdf-tabs a' ).map( function( a ) { return a.classList.contains( 'on' ) } ).join() === 'true,false,false,false' );
	const ptr = function( w ) { return all( all( w.main, '#pdf-templates' )[0], 'tbody' )[0].children };
	check( '...the table has Name, Art, Geändert and Benutzt von; a row has the name with its key, the word of the tag, the day written and how many documents name it - a dash for none', all( p0.main, '#pdf-templates th' ).map( function( th ) { return textOf( th ).replace( / [↑↓]$/, '' ) } ).join( '|' ) === 'Name|Art|Geändert|Benutzt von'
		&& Array.from( ptr( p0 ) ).map( function( tr ) { return Array.from( tr.children ).map( textOf ).join( '|' ) } ).join( '\n' ) === 'Älteres Formatalt|legacy Art nicht mehr auf der Liste|02.01.2025|–\nAngebotangebot|Angebot|01.09.2026|–\nRechnungrechnung|Rechnung|05.10.2026|3' );
	check( '...the head counts the templates and the blocks', textOf( all( p0.main, '.head .sub' )[0] ) === '3 Vorlagen · 2 Bausteine' );
	type( all( p0.main, '#q' )[0], 'rech', false );
	check( 'the search narrows the rows by name, key and the word of the tag', ptr( p0 ).length === 1 && textOf( ptr( p0 )[0].children[0] ).indexOf( 'Rechnung' ) === 0 );
	type( all( p0.main, '#q' )[0], 'Angebot', false );
	check( '...and says so when nothing is left', ptr( p0 ).length === 1 && ( type( all( p0.main, '#q' )[0], 'xyz', false ), textOf( ptr( p0 )[0] ) === texts['pdf.nomatch'] ) );
	type( all( p0.main, '#q' )[0], '', false );
	click( p0, all( p0.main, '[data-sort="used"]' )[0] );
	const usedFirst = Array.from( ptr( p0 ) ).map( function( tr ) { return tr.dataset.key } ).join();
	click( p0, all( p0.main, '[data-sort="used"]' )[0] );
	check( 'a click on a heading sorts by it - the count of documents, most first, then the other way', usedFirst === 'rechnung,alt,angebot' && Array.from( ptr( p0 ) ).map( function( tr ) { return tr.dataset.key } ).join() === 'alt,angebot,rechnung' );
	click( p0, all( p0.main, '[data-sort="tag"]' )[0] );
	check( '...by the word of the tag too', Array.from( ptr( p0 ) ).map( function( tr ) { return tr.dataset.key } ).join() === 'angebot,alt,rechnung' );
	const newMenu1 = all( p0.main, '.head .menu' )[0];
	check( '"Neue Vorlage" is a menu of the tags the Pdf settings know, in their words', textOf( all( newMenu1, 'button' )[0] ) === 'Neue Vorlage ▾' && all( newMenu1, '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Angebot|Rechnung|Vertrag' );
	click( p0, all( newMenu1, 'button' )[0] );
	click( p0, itemOf( newMenu1, 'Rechnung' ) );
	check( '...a tag opens the editor of a new template of it', p0.location.hash === '#pdf-neue-vorlage-invoice' );
	p0.location.hash = '#pdf';
	await settle();
	const firstKey = ptr( p0 )[0].dataset.key;
	click( p0, ptr( p0 )[0] );
	check( 'a click on a row opens the editor of the template', p0.location.hash === '#pdf-vorlage-'+ firstKey );

	// --- the editor of a template
	const pe = await popen( '#pdf-vorlage-rechnung' );
	check( 'the editor asks for the template and for the context, shows the name and the tag as fields of the head, and the blocks as cards in their order with the kind of each', pe.last( 'pdf/templates/get' ).data.key === 'rechnung' && all( pe.main, '#tpl-name' )[0].value === 'Rechnung' && all( pe.main, '#tpl-tag' )[0].value === 'invoice'
		&& all( all( pe.main, '#tpl-tag' )[0], 'option' ).map( textOf ).join( '|' ) === 'Angebot|Rechnung|Vertrag' && cards( pe ).length === 6 && cards( pe ).map( function( c ) { return c.dataset.type } ).join() === 'html,html,slot,input,signature,pagebreak'
		&& cards( pe ).map( function( c ) { return textOf( all( c, '.kind' )[0] ) } ).join( '|' ) === 'Text|Text Baustein „Zahlung“|Bereich Positionen|Eingabe Hinweis|Unterschrift Inhaber · Inhaber|Seitenumbruch' );
	check( '...a text is a textarea with the HTML, a block by reference a select of the library, a slot a select of the three areas, an input has its name, label and two switches, a signature its party, page, millimetres and line',
		all( cards( pe )[0], 'textarea' )[0].value === '<h1>Rechnung</h1>' && fieldOf( cards( pe )[1], 'block' ).value === 'zahlung' && all( fieldOf( cards( pe )[1], 'block' ), 'option' ).map( textOf ).join() === 'Zahlung,Kontakt' && fieldOf( cards( pe )[2], 'name' ).value === 'lines' && all( fieldOf( cards( pe )[2], 'name' ), 'option' ).map( textOf ).join() === 'Adresse,Positionen,Summen'
		&& fieldOf( cards( pe )[3], 'name' ).value === 'hinweis' && fieldOf( cards( pe )[3], 'label' ).value === 'Hinweis' && fieldOf( cards( pe )[3], 'multiline' ).checked === true && fieldOf( cards( pe )[3], 'required' ).checked === false
		&& fieldOf( cards( pe )[4], 'party' ).value === 'owner' && fieldOf( cards( pe )[4], 'page' ).value === 'last' && [ 'x', 'y', 'w', 'h' ].map( function( n ) { return fieldOf( cards( pe )[4], n ).value } ).join() === '20,230.5,70,20' && fieldOf( cards( pe )[4], 'line' ).checked === true && fieldOf( cards( pe )[4], 'number' ) === undefined );
	check( '...a reference leads to its block in the library; the paper\'s size is said at the signature', all( cards( pe )[1], 'a' )[0].getAttribute( 'href' ) === '#pdf-baustein-zahlung' && all( cards( pe )[4], '.state-note' ).some( function( n ) { return textOf( n ).indexOf( '210 × 297 mm' ) !== -1 } ) );

	// the moves
	click( pe, all( cards( pe )[0], '.down' )[0] );
	check( 'the arrows move a card: down moves the first behind the second, the first has no arrow up and the last none down', cards( pe ).map( function( c ) { return c.dataset.type } ).join() === 'html,html,slot,input,signature,pagebreak' && fieldOf( cards( pe )[0], 'block' ).value === 'zahlung' && all( cards( pe )[1], 'textarea' )[0].value === '<h1>Rechnung</h1>'
		&& all( cards( pe )[0], '.up' )[0].disabled === true && all( cards( pe )[5], '.down' )[0].disabled === true && pe.app.state.draft.dirty === true );
	click( pe, all( cards( pe )[2], '.up' )[0] );
	check( '...up moves it the other way', cards( pe ).map( function( c ) { return c.dataset.type } ).join() === 'html,slot,html,input,signature,pagebreak' );
	click( pe, all( cards( pe )[5], '.del' )[0] );
	check( '...and the cross takes a card out', cards( pe ).length === 5 && cards( pe ).map( function( c ) { return c.dataset.type } ).join() === 'html,slot,html,input,signature' );

	// the placeholder menu
	const area = all( cards( pe )[2], 'textarea' )[0];
	area.value = 'Hallo Welt';
	area.selectionStart = 6;
	area.selectionEnd = 6;
	const ph = menuOf( cards( pe )[2], 'Platzhalter ▾' );
	check( 'the placeholder menu offers the vocabulary of the CRM by group - Beleg, Rechnung, Angebot, Kunde, Portal - and then the texts of the site', all( ph, '.heading' ).map( textOf ).join( '|' ) === 'Beleg|Rechnung|Angebot|Kunde|Portal|Texte der Website'
		&& itemOf( ph, 'Nummer des Belegs  [[/document/number]]' ) !== undefined && itemOf( ph, 'Steuernummer oder USt-IdNr.  [[/site/tax]]' ) !== undefined && itemOf( ph, 'E-Mail der Firma  [[/project/company/contact/email]]' ) !== undefined && itemOf( ph, 'Firmenname  [[/project/company/general/name]]' ) !== undefined
		&& itemOf( ph, 'general/legal  [[/project/company/general/legal]]' ) !== undefined && all( ph, '[role="menuitem"]' ).length === 32 + 3 );
	click( pe, all( ph, 'button' )[0] );
	click( pe, itemOf( ph, 'Nummer des Belegs  [[/document/number]]' ) );
	check( '...a choice goes in where the caret is, and the caret stands behind it', area.value === 'Hallo [[/document/number]]Welt' && area.selectionStart === 6+ '[[/document/number]]'.length && area.selectionEnd === area.selectionStart && area.focused === true );
	area.selectionStart = 0;
	area.selectionEnd = 5;
	click( pe, all( ph, 'button' )[0] );
	click( pe, itemOf( ph, 'E-Mail der Firma  [[/project/company/contact/email]]' ) );
	check( '...a choice replaces what is selected, and the text of the card is the text of the template', area.value === '[[/project/company/contact/email]] [[/document/number]]Welt'.replace( ' [[', '[[' ).replace( ']]', ']] ' ).replace( '] [[/document', ']][[/document' ) || area.value.indexOf( '[[/project/company/contact/email]]' ) === 0 );

	// the signature in millimetres
	const sigCard = function() { return cards( pe ).find( function( c ) { return c.dataset.type === 'signature' } ) };
	type( fieldOf( sigCard(), 'x' ), '12,5' );
	type( fieldOf( sigCard(), 'w' ), '80' );
	fieldOf( sigCard(), 'page' ).value = 'number';
	fire( fieldOf( sigCard(), 'page' ), 'change', { target : fieldOf( sigCard(), 'page' ) } );
	check( 'a page that is a number shows a field for it; the page word first or last shows none', fieldOf( sigCard(), 'number' ) !== undefined && fieldOf( sigCard(), 'number' ).value === '2' && textOf( all( sigCard(), 'label' ).find( function( l ) { return fieldOf( l, 'number' ) !== undefined } ) ).indexOf( 'Seitennummer' ) === 0 );
	type( fieldOf( sigCard(), 'number' ), '3' );
	type( fieldOf( sigCard(), 'y' ), '240' );
	fieldOf( sigCard(), 'line' ).checked = false;
	fire( fieldOf( sigCard(), 'line' ), 'change', { target : fieldOf( sigCard(), 'line' ) } );
	type( fieldOf( cards( pe )[3], 'label' ), 'Anmerkung' );
	fieldOf( cards( pe )[3], 'required' ).checked = true;
	fire( fieldOf( cards( pe )[3], 'required' ), 'change', { target : fieldOf( cards( pe )[3], 'required' ) } );
	fieldOf( cards( pe )[1], 'name' ).value = 'totals';
	fire( fieldOf( cards( pe )[1], 'name' ), 'change', { target : fieldOf( cards( pe )[1], 'name' ) } );
	click( pe, all( pe.main, '#tpl-save' )[0] );
	await settle();
	const sent = psaves[psaves.length - 1];
	check( 'Speichern posts pdf/templates/save with the key, isNew false, the name, the tag and every block as the store wants it: the text, the reference, the slot, the input with its switches as booleans', sent.key === 'rechnung' && sent.isNew === false && sent.name === 'Rechnung' && sent.tag === 'invoice'
		&& JSON.stringify( sent.blocks.slice( 0, 4 ) ) === JSON.stringify( [ { type : 'html', block : 'zahlung' }, { type : 'slot', name : 'totals' }, { type : 'html', html : area.value }, { type : 'input', name : 'hinweis', label : 'Anmerkung', multiline : true, required : true } ] ) );
	check( '...a signature with its millimetres as numbers - a decimal comma read as a point - and the page as the number the store takes: 3, and last or 1 for the words', JSON.stringify( sent.blocks[4] ) === JSON.stringify( { type : 'signature', name : 'inhaber', party : 'owner', label : 'Inhaber', page : 3, x : 12.5, y : 240, w : 80, h : 20, line : false } ) );
	check( '...and the answer is a sentence "Gespeichert." and nothing unsaved is left', textOf( pe.toast ) === 'Gespeichert.' && pe.app.state.draft.dirty === false );
	fieldOf( sigCard(), 'page' ).value = 'first';
	fire( fieldOf( sigCard(), 'page' ), 'change', { target : fieldOf( sigCard(), 'page' ) } );
	type( fieldOf( sigCard(), 'h' ), 'zwanzig' );
	click( pe, all( pe.main, '#tpl-save' )[0] );
	await settle();
	check( '...the page "first" is 1; a number that is none is posted as typed, for the server to refuse', psaves[psaves.length - 1].blocks[4].page === 1 && psaves[psaves.length - 1].blocks[4].h === 'zwanzig' );

	// adding
	const addTypes = function() { return cards( pe ).map( function( c ) { return c.dataset.type } ).join() };
	click( pe, all( pe.main, '#add-html' )[0] );
	click( pe, all( pe.main, '#add-input' )[0] );
	click( pe, all( pe.main, '#add-input' )[0] );
	click( pe, all( pe.main, '#add-signature' )[0] );
	click( pe, all( pe.main, '#add-pagebreak' )[0] );
	const refMenu = menuOf( all( pe.main, '#add' )[0], 'Baustein ▾' );
	click( pe, all( refMenu, 'button' )[0] );
	click( pe, itemOf( refMenu, 'Kontakt' ) );
	const slotMenu = menuOf( all( pe.main, '#add' )[0], 'Bereich ▾' );
	click( pe, all( slotMenu, 'button' )[0] );
	click( pe, itemOf( slotMenu, 'Summen' ) );
	check( 'Hinzufügen offers Text, Baustein ▾ (the library), Bereich ▾ (address, lines, totals), Eingabe, Seitenumbruch and Unterschrift - and each adds a card at the end', all( all( pe.main, '#add' )[0], '.btn' ).map( textOf ).join( '|' ) === 'Text|Baustein ▾|Bereich ▾|Eingabe|Seitenumbruch|Unterschrift' && addTypes() === 'html,slot,html,input,signature,html,input,input,signature,pagebreak,html,slot'
		&& all( refMenu, '[role="menuitem"]' ).map( textOf ).join() === 'Zahlung,Kontakt' && all( slotMenu, '[role="menuitem"]' ).map( textOf ).join() === 'Adresse,Positionen,Summen' );
	check( '...the cards added have names no other card has: feld, feld-2, unterschrift, and a signature that is new stands where the seed puts one', [ cards( pe )[6], cards( pe )[7] ].map( function( c ) { return fieldOf( c, 'name' ).value } ).join() === 'feld,feld-2' && fieldOf( cards( pe )[8], 'name' ).value === 'unterschrift' && fieldOf( cards( pe )[8], 'party' ).value === 'owner' && fieldOf( cards( pe )[8], 'page' ).value === 'last'
		&& [ 'x', 'y', 'w', 'h' ].map( function( n ) { return fieldOf( cards( pe )[8], n ).value } ).join() === '20,230,70,20' && fieldOf( cards( pe )[10] ?? cards( pe )[10], 'block' ).value === 'kontakt' );

	// the refusals beside the block
	const peRefuse = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/save' : function() { return answerFail( 400, 'pdf_signature_box', [ 5 ], 'blocks' ) } } );
	click( peRefuse, all( peRefuse.main, '#tpl-save' )[0] );
	await settle();
	check( 'a save that the store refuses for a block puts the sentence of its code beside that card - the fifth - and marks it; no other card has one, the head says nothing', textOf( all( cards( peRefuse )[4], '.berr' )[0] ).indexOf( 'Block 5: Der Kasten muss innerhalb der Seite liegen' ) === 0 && cards( peRefuse )[4].classList.contains( 'err' ) === true
		&& cards( peRefuse ).filter( function( c ) { return textOf( all( c, '.berr' )[0] ) !== '' } ).length === 1 && textOf( all( peRefuse.main, '#tpl-note' )[0] ) === '' && peRefuse.toast.classList.contains( 'bad' ) === true );
	fieldOf( cards( peRefuse )[4], 'x' ).dispatchEvent( { type : 'input', bubbles : true } );
	check( '...and typing into the card takes the sentence away', textOf( all( cards( peRefuse )[4], '.berr' )[0] ) === '' );
	const peName = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/save' : function() { return answerFail( 400, 'pdf_title', [ 120 ], 'name' ) } } );
	click( peName, all( peName.main, '#tpl-save' )[0] );
	await settle();
	check( 'a refusal for the name stands under the head and marks the name field; the cards have none', all( peName.main, '#tpl-name' )[0].classList.contains( 'err' ) === true && textOf( all( peName.main, '#tpl-note' )[0] ) === 'Name oder Titel brauchen 1 bis 120 Zeichen.' && cards( peName ).filter( function( c ) { return textOf( all( c, '.berr' )[0] ) !== '' } ).length === 0 );
	const peLong = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/save' : function() { return answerFail( 400, 'pdf_html_long', [ 20000 ], 'blocks' ) } } );
	click( peLong, all( peLong.main, '#tpl-save' )[0] );
	await settle();
	check( '...so does a code that names no block (the text too long: its param is the limit, not a number of a block)', textOf( all( peLong.main, '#tpl-note' )[0] ) === 'Der Text darf bis zu 20000 Zeichen haben.' && cards( peLong ).filter( function( c ) { return textOf( all( c, '.berr' )[0] ) !== '' } ).length === 0 );

	// the preview
	const savesBefore = psaves.length;
	click( pe, all( pe.main, '#tpl-preview' )[0] );
	await settle();
	check( 'PDF-Vorschau of an editor that holds unsaved changes saves first - pdf/templates/save with the blocks - and then opens the sample the surface serves for the saved template, in a new tab: no PDF travels through the page', psaves.length === savesBefore+ 1 && pe.all( 'pdf/preview' ).length === 0
		&& pe.opened.length === 1 && pe.opened[0][0] === '/crm/pdf-preview/template/rechnung' && pe.opened[0][1] === '_blank' && pe.dialogs.length === 0 && pe.dialogs.length === 0 && pe.app.state.draft.dirty === false );
	check( '...the tab is opened without the noopener feature - which would make window.open answer null always, and a blocked tab could not be told - and the opener of the tab that opened is cut afterwards', pe.opened[0][2] === undefined && pe.tabs.length === 1 && pe.tabs[0].opener === null );
	click( pe, all( pe.main, '#tpl-preview' )[0] );
	await settle();
	check( '...with nothing unsaved it only opens the sample again', psaves.length === savesBefore+ 1 && pe.opened.length === 2 && pe.opened[1][0] === '/crm/pdf-preview/template/rechnung' );
	check( 'the note under the editor is the sentence of the key the script asks for, and it says what the preview does: it saves what is not saved and opens the sample in a new tab', textOf( all( pe.main, '#tpl-help' )[0] ) === texts['pdf.editor-note'] && texts['pdf.editor-note'].indexOf( 'speichert' ) !== -1 && texts['pdf.editor-note'].indexOf( 'gespeichert oder nicht' ) === -1 );
	const peBlocked = world( { hash : '#pdf-vorlage-rechnung', answers : panswers(), perms : pperms, blocked : true } );
	peBlocked.boot();
	await settle();
	click( peBlocked, all( peBlocked.main, '#tpl-preview' )[0] );
	await settle();
	check( 'a browser that does not open the tab (window.open answers null) shows the link of the preview in a dialog, to be clicked: a link to the sample that opens in a new tab', peBlocked.opened.length === 1 && peBlocked.dialogs.length === 1 && all( peBlocked.dialogs[0], 'a' )[0].getAttribute( 'href' ) === '/crm/pdf-preview/template/rechnung'
		&& all( peBlocked.dialogs[0], 'a' )[0].getAttribute( 'target' ) === '_blank' && textOf( all( peBlocked.dialogs[0], 'a' )[0] ) === 'Vorschau öffnen' && textOf( all( peBlocked.dialogs[0], 'p' )[0] ) === texts['pdf.preview-blocked'] );
	const pNotThere = await ( async function() { const w = world( { hash : '#pdf', answers : panswers(), perms : pperms, pdfAvailable : false } ); w.boot(); await settle(); return w; } )();
	check( 'the feature Pdf not installed, for an account that has the permission: the sentence says so - not the one for a missing permission - and nothing is asked of it; the entry is still in the rail', textOf( all( pNotThere.main, '.note' )[0] ) === 'Das Feature PDF ist nicht installiert.' && pdfCalls( pNotThere ).length === 0 && all( pNotThere.nav, '#nav-pdf' )[0].hidden === false );
	const peBad = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/save' : function() { return answerFail( 400, 'pdf_block_ref', [ 2 ], 'blocks' ) } } );
	click( peBad, all( peBad.main, '#add-pagebreak' )[0] );
	click( peBad, all( peBad.main, '#tpl-preview' )[0] );
	await settle();
	check( 'a template the store refuses says it beside the block, and opens nothing', peBad.opened.length === 0 && textOf( all( cards( peBad )[1], '.berr' )[0] ).indexOf( 'Block 2 nennt einen Textbaustein, den es nicht gibt.' ) === 0 );

	// duplicate and delete
	const more = menuOf( pe.main, '⋯' );
	click( pe, all( more, 'button' )[0] );
	check( 'the menu ⋯ has Duplizieren and Löschen, the second marked as a danger', all( more, '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Duplizieren|Löschen' && itemOf( more, 'Löschen' ).className.indexOf( 'danger' ) !== -1 );
	let duplicated = null;
	const pd = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/duplicate' : function( data ) { duplicated = data; return [ 200, Object.assign( { key : 'rechnung-copy', templates : ptemplates }, poffer ) ] } } );
	click( pd, itemOf( menuOf( pd.main, '⋯' ), 'Duplizieren' ) );
	check( 'Duplizieren asks for the name of the copy, with "(Kopie)" proposed', pd.dialogs.length === 1 && all( pd.dialogs[0], 'input' )[0].value === 'Rechnung (Kopie)' );
	type( all( pd.dialogs[0], 'input' )[0], 'Rechnung kurz', false );
	click( pd, all( pd.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...and posts pdf/templates/duplicate with the key and the name; the copy is opened', JSON.stringify( duplicated ) === '{"key":"rechnung","name":"Rechnung kurz"}' && pd.location.hash === '#pdf-vorlage-rechnung-copy' );
	const pdel = await popen( '#pdf-vorlage-rechnung' );
	click( pdel, itemOf( menuOf( pdel.main, '⋯' ), 'Löschen' ) );
	check( 'a template that documents name is not deleted: the toast says how many, no question is asked, nothing is posted', textOf( pdel.toast ) === 'Nicht löschbar: Belege nennen diese Vorlage (3).' && pdel.dialogs.length === 0 && pdel.all( 'pdf/templates/delete' ).length === 0 );
	let deleted = null;
	const pfree = await popen( '#pdf-vorlage-angebot', {
		'pdf/templates/get' : function() { return [ 200, Object.assign( { key : 'angebot', template : { name : 'Angebot', tag : 'offer', blocks : [] } }, poffer ) ] },
		'pdf/templates/delete' : function( data ) { deleted = data; return [ 200, Object.assign( { templates : ptemplates }, poffer ) ] },
	} );
	click( pfree, itemOf( menuOf( pfree.main, '⋯' ), 'Löschen' ) );
	check( 'any other is asked about: the sentence names it', pfree.dialogs.length === 1 && textOf( all( pfree.dialogs[0], 'p' )[0] ) === 'Die Vorlage „Angebot“ wird endgültig gelöscht.' && deleted === null );
	click( pfree, all( pfree.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...and the yes posts pdf/templates/delete with the key and goes back to the list', JSON.stringify( deleted ) === '{"key":"angebot"}' && pfree.location.hash === '#pdf' );
	const prace = await popen( '#pdf-vorlage-angebot', {
		'pdf/templates/get' : function() { return [ 200, Object.assign( { key : 'angebot', template : { name : 'Angebot', tag : 'offer', blocks : [] } }, poffer ) ] },
		'pdf/templates/delete' : function() { return answerFail( 409, 'crm_pdf_template_used', [ 2 ] ) },
	} );
	click( prace, itemOf( menuOf( prace.main, '⋯' ), 'Löschen' ) );
	click( prace, all( prace.dialogs[0], 'button' ).pop() );
	await settle();
	check( 'a document that named the template since the list was read: the endpoint refuses and the toast says its sentence; the editor stays', textOf( prace.toast ) === 'Nicht löschbar: Belege nennen diese Vorlage (2).' && prace.location.hash === '#pdf-vorlage-angebot' );
	const pmissing = await popen( '#pdf-vorlage-weg' );
	check( 'a template that is not there says the sentence of the panel', textOf( pmissing.main ).indexOf( 'Diese Vorlage gibt es nicht mehr.' ) !== -1 );

	// a new template
	let saveCount = 0;
	const pn = await popen( '#pdf-neue-vorlage-offer', { 'pdf/templates/save' : function( data ) { psaves.push( data ); saveCount++; return saveCount < 3 ? answerFail( 400, 'pdf_key_taken', [], 'key' ) : [ 200, Object.assign( { key : data.key, templates : ptemplates }, poffer ) ] } } );
	check( 'a new template asks for the list, not for a template; the tag is the one that was chosen, the name empty, there is no block yet and the menu ⋯ is not offered', pn.all( 'pdf/templates/get' ).length === 0 && pn.all( 'pdf/templates/list' ).length === 1 && all( pn.main, '#tpl-tag' )[0].value === 'offer' && all( pn.main, '#tpl-name' )[0].value === ''
		&& cards( pn ).length === 0 && all( pn.main, '#no-blocks' ).length === 1 && menuOf( pn.main, '⋯' ) === undefined );
	type( all( pn.main, '#tpl-name' )[0], 'Müller Angebot Groß', false );
	click( pn, all( pn.main, '#add-html' )[0] );
	type( all( cards( pn )[0], 'textarea' )[0], '<p>Hallo</p>', false );
	click( pn, all( pn.main, '#tpl-save' )[0] );
	await settle();
	const keysTried = psaves.slice( -3 ).map( function( one ) { return one.key } );
	check( 'the key of a new template is the name as a slug - umlauts spelled out - with isNew true; a key that is taken is tried again as -2 and -3', JSON.stringify( keysTried ) === '["mueller-angebot-gross","mueller-angebot-gross-2","mueller-angebot-gross-3"]' && psaves.slice( -3 ).every( function( one ) { return one.isNew === true && one.tag === 'offer' } ) );
	check( '...and the editor of the saved template opens', pn.location.hash === '#pdf-vorlage-mueller-angebot-gross-3' );
	check( 'a name that gives no key falls back on the tag with -vorlage; the templateKey is bounded by the store\'s 40 characters', pn.app.model.templateKey( '€€', 'offer' ) === 'offer-vorlage' && pn.app.model.templateKey( 'Ärger mit ß', 'offer', 2 ) === 'aerger-mit-ss-2' && pn.app.model.templateKey( 'x'.repeat( 90 ), 'offer', 9 ).length <= 40 );
	const pnTag = await popen( '#pdf-neue-vorlage-legacy' );
	check( 'a tag the Pdf settings no longer have is in the select, marked, so that it is not changed behind the person\'s back', textOf( all( all( pnTag.main, '#tpl-tag' )[0], 'option' )[0] ) === 'legacy (Art nicht mehr auf der Liste)' );

	// --- Bausteine
	const pb = await popen( '#pdf-bausteine' );
	check( 'Bausteine asks for the library and the context; the table has key, title with the text and in how many templates the block is used; Neuer Baustein is a link', pb.last( 'pdf/blocks/list' ) !== undefined && pb.last( 'crm-pdf/context' ) !== undefined && all( all( pb.main, '#pdf-blocks' )[0], 'th' ).map( textOf ).join( '|' ) === 'Schlüssel|Titel|Vorlagen'
		&& Array.from( all( all( pb.main, '#pdf-blocks' )[0], 'tbody' )[0].children ).map( function( tr ) { return Array.from( tr.children ).map( textOf ).join( '|' ) } ).join( '\n' ) === 'zahlung|ZahlungZahlbar sofort .|2\nkontakt|KontaktWir|–'
		&& all( pb.main, '#new-block' )[0].getAttribute( 'href' ) === '#pdf-neuer-baustein' && all( pb.main, '#pdf-tabs a' )[1].classList.contains( 'on' ) === true );
	const legend = all( pb.main, '#legend' )[0];
	check( '...beside it the legend Platzhalter: the vocabulary by group and the texts of the site in one card, each with its token', all( legend, 'h4' ).map( textOf ).join( '|' ) === 'Beleg|Rechnung|Angebot|Kunde|Portal|Texte der Website|Seite' && all( legend, '.num' ).length === 32 + 3 + 2 && textOf( all( legend, '.num' )[0] ) === '[[/document/number]]' && all( legend, 'tr' ).some( function( tr ) { return textOf( tr ) === '[[/project/company/general/name]]Firmenname' } ) );
	click( pb, all( all( pb.main, '#pdf-blocks' )[0], 'tbody' )[0].children[0] );
	check( '...a click on a row opens the editor of the block', pb.location.hash === '#pdf-baustein-zahlung' );
	await settle();
	let blockPosts = [];
	const pbe = await popen( '#pdf-baustein-zahlung', { 'pdf/blocks/save' : function( data ) { blockPosts.push( data ); return data.key === 'neu' ? answerFail( 400, 'pdf_key_taken', [], 'key' ) : [ 200, { blocks : [ { key : data.key, title : data.title, html : '<p>sauber</p>' } ], ranges : { title : 120, html : 20000 } } ] } } );
	check( 'the editor of a block: the key is fixed - the field is off -, the title and the HTML are fields with the menu of placeholders under the text', all( pbe.main, '#f-key' )[0].value === 'zahlung' && all( pbe.main, '#f-key' )[0].disabled === true && all( pbe.main, '#f-title' )[0].value === 'Zahlung' && all( pbe.main, '#f-html' )[0].value === '<p>Zahlbar <b>sofort</b>.</p>'
		&& menuOf( pbe.main, 'Platzhalter ▾' ) !== undefined && textOf( all( pbe.main, '#block-used' )[0] ) === 'Diese Vorlagen nutzen den Baustein: Rechnung, Angebot.' );
	type( all( pbe.main, '#f-title' )[0], 'Zahlung neu', false );
	all( pbe.main, '#f-html' )[0].selectionStart = 3;
	all( pbe.main, '#f-html' )[0].selectionEnd = 3;
	const bph = menuOf( pbe.main, 'Platzhalter ▾' );
	click( pbe, all( bph, 'button' )[0] );
	click( pbe, itemOf( bph, 'IBAN  [[/site/iban]]' ) );
	submit( pbe, all( pbe.main, '#block-form' )[0] );
	await settle();
	check( 'Speichern posts pdf/blocks/save with the key, isNew false, the title and the HTML - the placeholder in it -; the HTML as the store keeps it comes back into the field', blockPosts.length === 1 && blockPosts[0].key === 'zahlung' && blockPosts[0].isNew === false && blockPosts[0].title === 'Zahlung neu' && blockPosts[0].html === '<p>[[/site/iban]]Zahlbar <b>sofort</b>.</p>'
		&& all( pbe.main, '#f-html' )[0].value === '<p>sauber</p>' && pbe.app.state.draft.dirty === false );
	click( pbe, itemOf( menuOf( pbe.main, '⋯' ), 'Löschen' ) );
	check( 'a block that templates use is not deleted: the toast names them with the sentence of the panel, nothing is asked', textOf( pbe.toast ) === 'Diese Vorlagen nutzen den Textbaustein: Rechnung, Angebot. Nimm ihn zuerst aus ihnen heraus.' && pbe.dialogs.length === 0 );
	let blockGone = null;
	const pbf = await popen( '#pdf-baustein-kontakt', { 'pdf/blocks/delete' : function( data ) { blockGone = data; return [ 200, { blocks : [], ranges : { title : 120, html : 20000 } } ] } } );
	click( pbf, itemOf( menuOf( pbf.main, '⋯' ), 'Löschen' ) );
	click( pbf, all( pbf.dialogs[0], 'button' ).pop() );
	await settle();
	check( 'any other is asked about and goes: pdf/blocks/delete with the key, back to the list', JSON.stringify( blockGone ) === '{"key":"kontakt"}' && pbf.location.hash === '#pdf-bausteine' );
	const pbn = await popen( '#pdf-neuer-baustein', { 'pdf/blocks/save' : function( data ) { blockPosts.push( data ); return answerFail( 400, 'pdf_key_taken', [], 'key' ) } } );
	check( 'a new block has a key field that is on, a hint how a key is written, and no menu ⋯', all( pbn.main, '#f-key' )[0].disabled === false && all( pbn.main, '#f-key' )[0].value === '' && menuOf( pbn.main, '⋯' ) === undefined && textOf( pbn.main ).indexOf( 'Nach dem ersten Speichern lässt er sich nicht mehr ändern.' ) !== -1 );
	type( all( pbn.main, '#f-key' )[0], 'neu', false );
	type( all( pbn.main, '#f-title' )[0], 'Neu', false );
	type( all( pbn.main, '#f-html' )[0], '<p>x</p>', false );
	submit( pbn, all( pbn.main, '#block-form' )[0] );
	await settle();
	check( '...it posts isNew true; a refusal is said beside the field it names: the sentence under the key, the field marked', blockPosts[blockPosts.length - 1].isNew === true && blockPosts[blockPosts.length - 1].key === 'neu' && all( pbn.main, '#f-key' )[0].classList.contains( 'err' ) === true
		&& textOf( all( all( pbn.main, '#block-form' )[0], '.state-note.bad' ).find( function( n ) { return textOf( n ) !== '' } ) ) === 'Dieser Schlüssel ist schon vergeben.' );
	const pbOk = await popen( '#pdf-neuer-baustein', { 'pdf/blocks/save' : function( data ) { return [ 200, { blocks : [ { key : data.key, title : data.title, html : data.html } ], ranges : { title : 120, html : 20000 } } ] } } );
	type( all( pbOk.main, '#f-key' )[0], 'neu', false );
	type( all( pbOk.main, '#f-title' )[0], 'Neu', false );
	type( all( pbOk.main, '#f-html' )[0], '<p>x</p>', false );
	submit( pbOk, all( pbOk.main, '#block-form' )[0] );
	await settle();
	check( '...and once saved, the editor of the block opens, with the key fixed', pbOk.location.hash === '#pdf-baustein-neu' );
	const pbMissing = await popen( '#pdf-baustein-weg' );
	check( 'a block that is not there says the sentence of the panel and the way back', textOf( pbMissing.main ).indexOf( 'Diesen Textbaustein gibt es nicht mehr.' ) !== -1 && all( pbMissing.main, 'a' ).some( function( a ) { return a.getAttribute( 'href' ) === '#pdf-bausteine' } ) );

	// --- Layout
	let layoutPosts = [];
	let uploads = [];
	const pl = await popen( '#pdf-layout', {
		'pdf/layout/save' : function( data ) { layoutPosts.push( data ); return data.size === 'groß' ? answerFail( 400, 'pdf_size', [ 8, 14 ], 'size' ) : data.margins[1] === 'x' ? answerFail( 400, 'pdf_margin', [ 5, 50 ], 'margins' ) : [ 200, playoutBody( { layout : Object.assign( {}, playout, data ) } ) ] },
		'pdf/layout/image' : function( data ) { uploads.push( data ); return [ 200, playoutBody( { layout : Object.assign( {}, playout, { signature : 'signature.png' } ), images : { logo : pimage, signature : pimage } } ) ] },
		'pdf/layout/image/delete' : function( data ) { uploads.push( data ); return [ 200, playoutBody( { layout : Object.assign( {}, playout, { logo : '' } ), images : { logo : '', signature : '' } } ) ] },
	} );
	const lf = function( name ) { return all( pl.main, '#f-'+ name )[0] };
	check( 'Layout: paper and font are selects of what the panel lists, the size and the four margins fields with the values, header and footer textareas, each with the menu of placeholders', lf( 'format' ).value === 'a4' && all( lf( 'format' ), 'option' ).map( textOf ).join( '|' ) === 'A4|US Letter' && lf( 'font' ).value === 'helvetica' && all( lf( 'font' ), 'option' ).map( textOf ).join( '|' ) === 'Helvetica|Times|Courier'
		&& lf( 'size' ).value === '10' && all( lf( 'margins' ), 'input' ).map( function( i ) { return i.value } ).join() === '20,20,25,20' && lf( 'header' ).value === '<p>Kopf</p>' && lf( 'footer' ).value === '<p>Fuß</p>' && all( pl.main, '.textbox' ).length === 2 );
	check( '...the menu of a header and a footer has the page numbers as well, the one of a template has not', all( menuOf( all( pl.main, '#band-footer' )[0], 'Platzhalter ▾' ), '.heading' ).map( textOf ).pop() === 'Seite' && all( menuOf( cards( pe )[2], 'Platzhalter ▾' ), '.heading' ).map( textOf ).indexOf( 'Seite' ) === -1 );
	const lfoot = menuOf( all( pl.main, '#band-footer' )[0], 'Platzhalter ▾' );
	lf( 'footer' ).selectionStart = 6;
	lf( 'footer' ).selectionEnd = 6;
	click( pl, all( lfoot, 'button' )[0] );
	click( pl, itemOf( lfoot, 'Seitenzahl  [[page]]' ) );
	type( lf( 'size' ), '11', false );
	type( all( lf( 'margins' ), 'input' )[2], '30', false );
	fire( lf( 'format' ), 'change', { target : lf( 'format' ) } );
	submit( pl, all( pl.main, '#layout-form' )[0] );
	await settle();
	check( 'Speichern posts pdf/layout/save with the paper, the font, the size and the margins as whole numbers, the header and the footer - and no design, which stays as it is', Object.keys( layoutPosts[0] ).sort().join() === 'font,footer,format,header,margins,size' && layoutPosts[0].size === 11 && JSON.stringify( layoutPosts[0].margins ) === '[20,20,30,20]'
		&& layoutPosts[0].footer === '<p>Fuß[[page]]</p>' && layoutPosts[0].format === 'a4' && textOf( pl.toast ) === 'Gespeichert.' && pl.app.state.draft.dirty === false );
	type( lf( 'size' ), 'groß', false );
	submit( pl, all( pl.main, '#layout-form' )[0] );
	await settle();
	check( 'a refusal is said beside the field it names - the size - in the sentence of the panel, with its range', lf( 'size' ).classList.contains( 'err' ) === true && lf( 'size' ).focused === true && textOf( all( pl.main, '.state-note.bad' ).find( function( n ) { return textOf( n ) !== '' } ) ) === 'Die Größe ist eine ganze Zahl von Punkt von 8 bis 14.' );
	type( lf( 'size' ), '10', false );
	type( all( lf( 'margins' ), 'input' )[1], 'x', false );
	submit( pl, all( pl.main, '#layout-form' )[0] );
	await settle();
	check( '...the margins are one field of four: the refusal stands beside them', lf( 'margins' ).classList.contains( 'err' ) === true && layoutPosts[layoutPosts.length - 1].margins[1] === 'x' && textOf( all( pl.main, '.state-note.bad' ).filter( function( n ) { return textOf( n ) !== '' } ).pop() ).indexOf( 'Ein Rand ist eine ganze Zahl von Millimetern von 5 bis 50.' ) === 0 );

	const logoCard = all( pl.main, '#pic-logo' )[0];
	check( 'the pictures: the logo is shown as the panel gave it (a data address), with the hint how the header shows it, an upload and a removal; the signature has none yet and says so', all( logoCard, 'img' )[0].src === pimage && textOf( logoCard ).indexOf( 'pdf:logo' ) !== -1 && all( logoCard, 'input' )[0].getAttribute( 'accept' ) === 'image/png,image/jpeg' && all( logoCard, '#drop-logo' ).length === 1
		&& textOf( all( pl.main, '#pic-signature' )[0] ).indexOf( 'Noch kein Bild.' ) !== -1 && all( pl.main, '#drop-signature' ).length === 0 && all( pl.main, '#pic-signature img' ).length === 0 );
	const chosen = { name : 'signatur.png', type : 'image/png' };
	const fileInput = all( pl.main, '#file-signature' )[0];
	fileInput.files = [ chosen ];
	fire( fileInput, 'change', { target : fileInput } );
	await settle();
	const up = pl.last( 'pdf/layout/image' );
	check( 'a chosen file is posted the way the panel posts a picture: a form with the action, the kind as JSON data and the file - not JSON -, with the token in the header and no content type of our own', up.form === true && up.action === 'pdf/layout/image' && JSON.stringify( up.data ) === '{"kind":"signature"}' && up.file === chosen
		&& up.init.headers['X-CSRF-Token'] === 'token-1' && up.init.headers['Content-Type'] === undefined && up.init.method === 'POST' && up.init.credentials === 'same-origin' );
	check( '...the card is drawn again from the answer: the picture there, the button says Ersetzen, the removal is offered', all( pl.main, '#pic-signature img' ).length === 1 && textOf( all( pl.main, '#pic-signature label' )[0] ) === 'Ersetzen' && all( pl.main, '#drop-signature' ).length === 1 );
	click( pl, all( pl.main, '#drop-logo' )[0] );
	check( 'the removal asks first', pl.dialogs.length === 1 && textOf( all( pl.dialogs[0], 'p' )[0] ) === 'Das Bild wird aus dem Layout entfernt und gelöscht.' && uploads.length === 1 );
	click( pl, all( pl.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...and posts pdf/layout/image/delete with the kind; the card has no picture then', JSON.stringify( uploads[1] ) === '{"kind":"logo"}' && all( pl.main, '#pic-logo img' ).length === 0 && textOf( all( pl.main, '#pic-logo' )[0] ).indexOf( 'Noch kein Bild.' ) !== -1 );
	const plBad = await popen( '#pdf-layout', { 'pdf/layout/image' : function() { return answerFail( 400, 'pdf_image_type', [], 'file' ) } } );
	const badInput = all( plBad.main, '#file-logo' )[0];
	badInput.files = [ { name : 'x.gif' } ];
	fire( badInput, 'change', { target : badInput } );
	await settle();
	check( 'a picture the panel refuses is said in the card, in the panel\'s sentence, and the picture there stays', textOf( all( all( plBad.main, '#pic-logo' )[0], '.state-note.bad' )[0] ) === 'Das Bild muss ein PNG oder ein JPEG sein.' && all( plBad.main, '#pic-logo img' ).length === 1 );
	const plKernel = await popen( '#pdf-layout', { 'pdf/layout/image' : function() { return answerFail( 413, 'upload_too_large', [ 8 ], 'file' ) } } );
	const bigInput = all( plKernel.main, '#file-logo' )[0];
	bigInput.files = [ { name : 'big.png' } ];
	fire( bigInput, 'change', { target : bigInput } );
	await settle();
	check( '...and so is a refusal of the kernel: the sentence for a file the server does not take', textOf( all( all( plKernel.main, '#pic-logo' )[0], '.state-note.bad' )[0] ) === 'Die Datei ist größer, als dieser Server annimmt (PHP-Upload-Limit: 8 MB).' );
	const plLarge = await popen( '#pdf-layout', { 'pdf/layout/get' : function() { return [ 200, playoutBody( { images : { logo : '', signature : '' } } ) ] } } );
	check( 'a picture too large to show is told by its file name, and can still be replaced or removed', textOf( all( plLarge.main, '#pic-logo' )[0] ).indexOf( 'Das Bild (logo.png) ist zu groß, um es hier zu zeigen' ) !== -1 && all( plLarge.main, '#drop-logo' ).length === 1 );
	check( 'Vorschau of the layout is a link to the sample the surface serves, in a new tab - no request of the endpoint, no dialog', all( pl.main, '#layout-preview' )[0].getAttribute( 'href' ) === '/crm/pdf-preview/layout' && all( pl.main, '#layout-preview' )[0].getAttribute( 'target' ) === '_blank' && all( pl.main, '#layout-preview' )[0].getAttribute( 'rel' ) === 'noopener' && pl.last( 'pdf/layout/preview' ) === undefined && pl.dialogs.length === 1 );

	// --- Design
	let designPosts = [];
	const pg = await popen( '#pdf-design', { 'pdf/layout/save' : function( data ) {
		designPosts.push( data );
		return data.design.indexOf( 'banane' ) !== -1 ? answerFail( 400, 'pdf_design_selector', [ 2, 'banane' ], 'design' ) : [ 200, playoutBody( { layout : Object.assign( {}, playout, data ) } ) ];
	} } );
	check( 'Design shows the rules of the feature read only - the text of the panel\'s sheet - and the overrides in a field of their own', all( pg.main, '#design-sheet' )[0].textContent === 'body { font-size: 10pt }' && all( pg.main, '#design-sheet' )[0].className.indexOf( 'ro' ) !== -1 && all( pg.main, '#f-design' )[0].value === 'h1 { font-size: 20pt }'
		&& all( pg.main, '#pdf-tabs a' )[3].classList.contains( 'on' ) === true && all( pg.main, '#design-stored' )[0].hidden === true );
	type( all( pg.main, '#f-design' )[0], 'h1 { font-size: 20pt }\nbanane { color: red }\np { color: #333 }', false );
	submit( pg, all( pg.main, '#design-form' )[0] );
	await settle();
	const dz = all( pg.main, '#f-design' )[0];
	check( 'Speichern posts the layout as it is with the design; a refusal is said with its line, in the sentence of the panel, beside the field - and the line is selected', Object.keys( designPosts[0] ).sort().join() === 'design,font,footer,format,header,margins,size' && designPosts[0].size === 10 && JSON.stringify( designPosts[0].margins ) === '[20,20,25,20]'
		&& textOf( all( pg.main, '#design-refusal' )[0] ) === 'Zeile 2: "banane" ist kein Selektor, den das Design kennt.' && dz.classList.contains( 'err' ) === true && dz.focused === true );
	check( '...the selection is the second line - its first character to its last', dz.value.slice( dz.selectionStart, dz.selectionEnd ) === 'banane { color: red }' );
	type( dz, 'h1 { font-size: 22pt }', false );
	check( 'typing takes the refusal away', textOf( all( pg.main, '#design-refusal' )[0] ) === '' && dz.classList.contains( 'err' ) === false );
	submit( pg, all( pg.main, '#design-form' )[0] );
	await settle();
	check( 'a sheet is saved: the sentence "Gespeichert."', textOf( pg.toast ) === 'Gespeichert.' && designPosts[designPosts.length - 1].design === 'h1 { font-size: 22pt }' && pg.app.state.draft.dirty === false );
	const pgStored = await popen( '#pdf-design', { 'pdf/layout/get' : function() { return [ 200, playoutBody( { refusal : { code : 'pdf_design_value', params : [ 3, 'color', 'rot' ] } } ) ] } } );
	check( 'a stored design that is no sheet is said at the top, with the line and the sentence of the panel for its code', all( pgStored.main, '#design-stored' )[0].hidden === false && textOf( all( pgStored.main, '#design-stored' )[0] ) === 'Das gespeicherte Design wird nicht gelesen, es gelten allein die Regeln des Features: Zeile 3: "color" ist kein Wert, den das Design annimmt.' );
	check( 'the design has the same link: Vorschau shows the sample page with the saved design', all( pg.main, '#design-preview' )[0].getAttribute( 'href' ) === '/crm/pdf-preview/layout' && all( pg.main, '#design-preview' )[0].getAttribute( 'target' ) === '_blank' );

	// --- the feature off, and an endpoint that fails
	const pOff = await popen( '#pdf', { 'pdf/templates/list' : function() { return answerFail( 409, 'pdf_off' ) } } );
	check( 'where the feature is off the endpoint says so and the screen shows the sentence with the way to try again', textOf( all( pOff.main, '.note' )[0] ).indexOf( 'Das Feature PDF ist nicht aktiv.' ) !== -1 );

	// --- Einstellungen: the particulars
	const pset = await eopen( { settings : Object.assign( {}, esettings, { tax_id : '12/345/67890', vat_id : 'DE123456789', bank_name : 'Sparkasse', iban : 'DE89 3704 0044 0532 0130 00', bic : 'COBADEFFXXX', paypal : 'StudioNord' } ) } );
	check( 'the Einstellungen have a block Rechnungsangaben with six fields - Steuernummer, USt-IdNr., Bank, IBAN, BIC, PayPal.me-Name - holding the settings, and say that the footer of the layout shows them', textOf( all( pset.main, '#particulars h3' )[0] ) === 'Rechnungsangaben' && all( pset.main, '#particulars dt' ).map( textOf ).join( '|' ) === 'Steuernummer|USt-IdNr.|Bank|IBAN|BIC|PayPal.me-Name'
		&& [ 'tax_id', 'vat_id', 'bank_name', 'iban', 'bic', 'paypal' ].map( function( n ) { return efield( pset, n ).value } ).join( '|' ) === '12/345/67890|DE123456789|Sparkasse|DE89 3704 0044 0532 0130 00|COBADEFFXXX|StudioNord' && textOf( all( pset.main, '#particulars' )[0] ).indexOf( 'Der Fuß des Layouts zeigt Steuernummer und IBAN' ) !== -1 );
	check( '...the field of the BIC is wide enough for eleven characters: it has its own class, and the stylesheet gives that class 150 px - not the 90 px of a short field', efield( pset, 'bic' ).className === 'bic' && /\.kv input\.bic \{ width: 150px \}/.test( source( OWN+ 'assets/crm.css' ) ) === true );
	type( efield( pset, 'iban' ), 'DE00', false );
	const pposted = [];
	const pset2 = await eopen( {}, undefined, { 'crm-documents/settings-save' : function( data ) { pposted.push( data ); return data.iban === 'DE00' ? answerFail( 400, 'crm_documents_particular_iban', [], 'iban' ) : [ 200, { settings : Object.assign( {}, esettings, data, { vat_rates : '1900,700,550,0' } ) } ] } } );
	type( efield( pset2, 'iban' ), 'DE00', false );
	type( efield( pset2, 'tax_id' ), 'DE123456789', false );
	submit( pset2, all( pset2.main, '#settings-form' )[0] );
	await settle();
	check( 'they are saved with the others, and a wrong IBAN is said beside its field, in its own sentence', pposted[0].iban === 'DE00' && pposted[0].tax_id === 'DE123456789' && efield( pset2, 'iban' ).classList.contains( 'err' ) === true && efield( pset2, 'iban' ).focused === true
		&& textOf( all( all( pset2.main, '#particulars' )[0], '.state-note.bad' ).find( function( n ) { return textOf( n ) !== '' } ) ).indexOf( 'Das ist keine gültige IBAN' ) === 0 );
	// the seller of an electronic invoice
	const sellerSettings = { seller_name : 'Studio Beispiel', seller_street : 'Weg 1', seller_zip : '10115', seller_city : 'Berlin', seller_country : 'DE', seller_contact : 'Jan Beispiel', seller_phone : '+49 30 123456', seller_email : 'jan@beispiel.example', vat_exempt_text : 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG' };
	const sposted = [];
	const sset = await eopen( { settings : Object.assign( {}, esettings, sellerSettings, { vat_mode : 'small_business' } ) }, undefined, { 'crm-documents/settings-save' : function( data ) { sposted.push( data ); return data.seller_email === 'kaputt' ? answerFail( 400, 'crm_documents_particular_seller_email', [], 'seller_email' ) : [ 200, { settings : Object.assign( {}, esettings, data, { vat_rates : '1900,700,550,0' } ) } ] } } );
	check( 'the Einstellungen have a block Rechnungsaussteller: the name, the street, the postcode, the town, the country, the contact, the phone and the e-mail, holding the settings, and the sentence that says what they are for', textOf( all( sset.main, '#seller h3' )[0] ) === texts['settings.seller']
		&& all( sset.main, '#seller dt' ).map( textOf ).join( '|' ) === [ 'seller-name', 'seller-street', 'seller-zip', 'seller-city', 'seller-country', 'seller-contact', 'seller-phone', 'seller-email', 'exempt-text' ].map( function( k ) { return texts['settings.'+ k] } ).join( '|' )
		&& [ 'seller_name', 'seller_street', 'seller_zip', 'seller_city', 'seller_country', 'seller_contact', 'seller_phone', 'seller_email', 'vat_exempt_text' ].map( function( n ) { return efield( sset, n ).value } ).join( '|' ) === 'Studio Beispiel|Weg 1|10115|Berlin|DE|Jan Beispiel|+49 30 123456|jan@beispiel.example|Kein Ausweis von Umsatzsteuer gemäß § 19 UStG'
		&& efield( sset, 'seller_email' ).type === 'email' && efield( sset, 'seller_country' ).getAttribute( 'maxlength' ) === '2' && efield( sset, 'seller_phone' ).getAttribute( 'inputmode' ) === 'tel' && all( sset.main, '#seller .state-note' ).map( textOf ).indexOf( texts['settings.seller-note'] ) !== -1 );
	check( '...the note on the tax number says the USt-IdNr. is needed as well, and the USt-IdNr. field is 20 characters at most', efield( sset, 'vat_id' ).getAttribute( 'maxlength' ) === '20' && textOf( all( sset.main, '#particulars' )[0] ).indexOf( texts['settings.tax-id-hint'] ) !== -1 );
	const holder = all( sset.main, '#exempt-holder' )[0];
	const regularSet = await eopen( {} );
	check( 'the text of the exemption is there while the VAT mode is small_business, and goes with the mode: hidden under regular, back under small_business', holder.hidden === false && ( efield( sset, 'vat_mode' ).value = 'regular', fire( efield( sset, 'vat_mode' ), 'change', { target : efield( sset, 'vat_mode' ) } ), holder.hidden === true )
		&& ( efield( sset, 'vat_mode' ).value = 'small_business', fire( efield( sset, 'vat_mode' ), 'change', { target : efield( sset, 'vat_mode' ) } ), holder.hidden === false ) && all( regularSet.main, '#exempt-holder' )[0].hidden === true );
	type( efield( sset, 'seller_email' ), 'kaputt', false );
	submit( sset, all( sset.main, '#settings-form' )[0] );
	await settle();
	check( 'a wrong e-mail address of the seller is said beside its field, in its own sentence, and the others went with the post', sposted[0].seller_email === 'kaputt' && sposted[0].seller_name === 'Studio Beispiel' && sposted[0].vat_exempt_text === 'Kein Ausweis von Umsatzsteuer gemäß § 19 UStG' && efield( sset, 'seller_email' ).classList.contains( 'err' ) === true && efield( sset, 'seller_email' ).focused === true
		&& textOf( all( all( sset.main, '#seller' )[0], '.state-note.bad' ).find( function( n ) { return textOf( n ) !== '' } ) ) === texts['error.crm_documents_particular_seller_email'] );
	const pcard = await eopen( {}, undefined, {} );
	check( 'the card of the templates in the Einstellungen leads to the screen of the surface for an account that holds the permission, and to the panel of the workbench for one that does not', all( all( pcard.main, '#templates' )[0], 'a' )[0].getAttribute( 'href' ) === '/_admin/#pdf' );
	const pcard2 = world( { hash : '#einstellungen', perms : pperms, answers : { 'crm-documents/settings-get' : function() { return [ 200, eview ] }, 'crm/status' : function() { return [ 200, { items : eok } ] } } } );
	pcard2.boot();
	await settle();
	check( '...here: #pdf, in the same window', all( all( pcard2.main, '#templates' )[0], 'a' )[0].getAttribute( 'href' ) === '#pdf' && all( all( pcard2.main, '#templates' )[0], 'a' )[0].getAttribute( 'target' ) === null );

	// --- the words
	const pdfFamilies = {
		'pdf.group-' : [ 'document', 'invoice', 'offer', 'customer', 'portal', 'site', 'page' ], 'pdf.tab-' : [ 'vorlagen', 'bausteine', 'layout', 'design' ], 'pdf.slot-' : [ 'address', 'lines', 'totals' ], 'pdf.slot-note-' : [ 'address', 'lines', 'totals' ], 'pdf.paper-' : [ 'a4', 'letter' ], 'pdf.font-' : [ 'helvetica', 'times', 'courier' ],
		'pdf.margin-' : [ 'top', 'right', 'bottom', 'left' ], 'pdf.upload-' : [ 'logo', 'signature' ], 'pdf.picture-hint-' : [ 'logo', 'signature' ], 'pdf.party-' : [ 'owner', 'member' ], 'pdf.kind-' : [ 'html', 'ref', 'slot', 'input', 'signature', 'pagebreak' ],
		'pdf.' : [ 'logo', 'signature' ], 'site.' : [ 'general-name', 'contact-address', 'contact-email', 'contact-phone' ],
		'tok.' : [ 'page', 'pages' ].concat( [ 'document-number', 'document-title', 'document-date', 'document-parent', 'document-vatnote', 'document-net', 'document-vat', 'document-gross', 'invoice-due', 'invoice-due-sentence', 'invoice-period', 'site-tax', 'site-bank', 'site-iban', 'site-bic', 'offer-number', 'offer-date', 'offer-valid', 'offer-valid-sentence', 'offer-gross', 'offer-period',
			'customer-number', 'customer-company', 'customer-name', 'customer-street', 'customer-zip', 'customer-city', 'customer-country', 'customer-email', 'customer-vat', 'site-portal', 'site-login' ] ),
	};
	const pdfWords = new Set( [ ...script.matchAll( /\bt\( '(pdf\.[a-z0-9.-]+)'/g ) ].map( function( found ) { return found[1] } ).filter( function( key ) { return /[.-]$/.test( key ) === false } ).concat(
		[ ...script.matchAll( /'((?:pdf\.error|settings)-?[a-z.-]*)'/g ) ].map( function( found ) { return found[1] } ).filter( function( key ) { return /^(pdf\.error-|settings\.(?:tax|bank|iban|bic|particulars|templates-link))/.test( key ) } ) ) );
	Object.keys( pdfFamilies ).forEach( function( stem ) {
		check( 'the family '+ stem+ '* is built by the script', script.indexOf( "'"+ stem+ "'" ) !== -1 );
		pdfFamilies[stem].forEach( function( one ) { pdfWords.add( stem+ one ) } );
	} );
	const pdfMissing = [ ...pdfWords ].filter( function( key ) { return typeof texts[key] !== 'string' || texts[key] === '' } );

	if( pdfMissing.length > 0 )
		console.log( '  missing words: '+ pdfMissing.join( ', ' ) );

	check( 'every word the screens of PDF-Vorlagen ask for is a word of the page, the families they build with it - the groups of the placeholders, the vocabulary, the slots, the papers, the fonts - and the sentences of the Pdf panel\'s codes the script names', pdfMissing.length === 0 && pdfWords.size > 120
		&& typeof texts['error.pdf_block_missing'] === 'string' && typeof texts['error.pdf_block_used'] === 'string' && typeof texts['error.pdf_off'] === 'string' && typeof texts['error.upload_too_large'] === 'string' );
	check( '...and the vocabulary the menu has is the one the script lists: 32 placeholders of the CRM, the page numbers apart', [ ...script.matchAll( /\n\t\t\[ '[a-z]+', \[ ([^\]]*) \] \],/g ) ].reduce( function( sum, found ) { return sum+ found[1].split( ',' ).length }, 0 ) === 32 );

	// --- C13: Strg+S on the editors of PDF-Vorlagen
	const pkey = function( w, mods ) { return press( w, 's', null, mods || { ctrlKey : true } ) };
	const pk1 = await popen( '#pdf-vorlage-rechnung' );
	check( 'Strg+S in the editor of a template saves it as the button does (pdf/templates/save), and the button carries the shortcut', all( pk1.main, '#tpl-save' )[0].title === 'Strg+S' && pkey( pk1 ) === true && ( await settle(), pk1.all( 'pdf/templates/save' ).length === 1 ) && pkey( pk1, { metaKey : true } ) === true && ( await settle(), pk1.all( 'pdf/templates/save' ).length === 2 ) );
	const pkHold = ( function() { const held = {}; held.promise = new Promise( function( resolve ) { held.release = resolve } ); return held } )();
	const pk2 = await popen( '#pdf-vorlage-rechnung', { 'pdf/templates/save' : function() { return pkHold.promise } } );
	pkey( pk2 );
	pkey( pk2 );
	await settle();
	check( '...a save that is running is not started again', pk2.all( 'pdf/templates/save' ).length === 1 );
	pkHold.release( [ 200, Object.assign( { key : 'rechnung', templates : ptemplates }, poffer ) ] );
	await settle();
	const pk3 = await popen( '#pdf-baustein-zahlung', { 'pdf/blocks/save' : function() { return [ 200, { blocks : [ { key : 'zahlung', title : 'Zahlung', html : '<p>Zahlbar <b>sofort</b>.</p>' } ] } ] } } );
	check( 'the editor of a block: Strg+S posts pdf/blocks/save, and the button carries the shortcut', all( pk3.main, '#block-save' )[0].title === 'Strg+S' && pkey( pk3 ) === true && ( await settle(), pk3.all( 'pdf/blocks/save' ).length === 1 ) );
	const pk4 = await popen( '#pdf-layout', { 'pdf/layout/save' : function() { return [ 200, playoutBody() ] } } );
	check( 'Layout: Strg+S posts pdf/layout/save, and the button carries the shortcut', all( pk4.main, '#layout-save' )[0].title === 'Strg+S' && pkey( pk4 ) === true && ( await settle(), pk4.all( 'pdf/layout/save' ).length === 1 ) );
	const pk5 = await popen( '#pdf-design', { 'pdf/layout/save' : function() { return [ 200, playoutBody() ] } } );
	check( 'Design: Strg+S posts pdf/layout/save with the design, and the button carries the shortcut', all( pk5.main, '#design-save' )[0].title === 'Strg+S' && pkey( pk5 ) === true && ( await settle(), pk5.all( 'pdf/layout/save' ).length === 1 && typeof pk5.last( 'pdf/layout/save' ).data.design === 'string' ) );
	const pk6 = await popen( '#pdf-bausteine' );
	check( 'the lists of PDF-Vorlagen have no form: the key does nothing there', pkey( pk6 ) === false );

	}

	// === C12: the Positionen are made, changed and removed in the surface - the Elements panel's own actions, for the type positionen
	{
	const eperms = { crm : true, pdf : false, elements : true };
	const eitems = [
		{ id : 'mixing', sort : 1, price : 9050, vat : 1900, active : true, words : { de_DE : { title : 'Mixing', description : 'Mischung eines Songs\nbis 48 Spuren', unit : 'Std.' }, en_US : { title : 'Mixing', description : 'Mixing of one song', unit : 'h' } } },
		{ id : 'recording', sort : 2, price : 5000, vat : 700, active : true, words : { de_DE : { title : '', description : '', unit : '' }, en_US : { title : 'Recording day', description : '', unit : 'day' } } },
		{ id : 'alt', sort : 7, price : 100, vat : 0, active : false, words : { de_DE : { title : '<b>Alt</b>', description : '', unit : 'Std.' }, en_US : { title : '', description : '', unit : '' } } },
	];
	const elist = function( over ) {
		return Object.assign( { missing : false, locales : [ 'en_US', 'de_DE' ], native : 'de_DE', items : eitems, settings : settings }, over || {} );
	};
	const egot = function( over ) {
		return Object.assign( { global : { price : 90.5, vat : 19, hidden : false, sort : 1 }, locales : { de_DE : { title : 'Mixing', description : 'Mischung eines Songs\nbis 48 Spuren', unit : 'Std.' }, en_US : { title : 'Mixing', description : 'Mixing of one song', unit : 'h' } }, raw : {} }, over || {} );
	};
	const eposts = [];
	const eanswers = function( over ) {
		return Object.assign( {
			'crm/status' : function() { return [ 200, { first_run : false, items : [] } ] },
			'crm-documents/positionen' : function() { return [ 200, elist() ] },
			'elements/get' : function( data ) { return data.uri === 'mixing' ? [ 200, egot() ] : data.uri === 'versteckt' ? [ 200, egot( { global : { price : 1234.5, vat : 7.5, hidden : true, sort : 4 } } ) ] : [ 404, { error : 'element not found' } ] },
			'elements/save' : function( data ) { eposts.push( data ); return [ 200, { element : { '.uri' : '/positionen/'+ data.uri, '.locale' : data.locale } } ] },
			'elements/delete' : function() { return [ 200, { ok : true } ] },
		}, over || {} );
	};
	const xopen = async function( hash, over, perms ) {
		eposts.length = 0;
		const w = world( { hash : hash, answers : eanswers( over ), perms : perms === undefined ? eperms : perms } );
		w.boot();
		await settle();
		return w;
	};
	const itemOf = function( root, label ) { return all( root, '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === label } ) };
	const menuOf = function( root, label ) { return all( root, '.menu' ).find( function( one ) { return textOf( all( one, 'button' )[0] ) === label } ) };
	const xf = function( w, id ) { return all( w.main, '#'+ id )[0] };
	const xcards = function( w ) { return all( w.main, '#position-form .card' ) };
	const xfail = function( status, code, params, field ) { return [ status, code === '' ? { error : 'Element already exists.' } : { error : 'x', code : code, params : params || [], field : field || '' } ] };
	const xtoggle = function( input, checked ) { input.checked = checked; fire( input, 'change', { target : input } ) };
	const xnote = function( w, id ) {
		const row = xf( w, id ).parentNode;
		const notes = all( row, '.state-note.bad' );
		return notes.length === 0 ? '' : textOf( notes[0] );
	};
	const xsave = async function( w ) { submit( w, xf( w, 'position-form' ) ); await settle() };

	// --- the screen: the table of C08, and for the account with the permission the way into the editor
	const xs = await xopen( '#positionen' );
	check( 'with the permission of the Elements panel the table keeps its columns and gains the way in: Neue Position is the primary link, the Elements panel a secondary one, in a tab of its own - and still only the one read of the items', JSON.stringify( xs.all( 'crm-documents/positionen' ).map( function( call ) { return call.data } ) ) === '[{}]'
		&& all( xs.main, 'th' ).map( textOf ).join( '|' ) === 'Titel (en)|Titel (de)|Einheit|Netto|USt|Aktiv|Reihenfolge ↑' && textOf( xf( xs, 'items-new' ) ) === 'Neue Position' && xf( xs, 'items-new' ).getAttribute( 'href' ) === '#positionen-neu' && xf( xs, 'items-new' ).classList.contains( 'primary' ) === true
		&& textOf( xf( xs, 'items-edit' ) ) === 'Im Panel Elemente' && xf( xs, 'items-edit' ).getAttribute( 'href' ) === '/_admin/#elements/positionen' && xf( xs, 'items-edit' ).classList.contains( 'primary' ) === false && xf( xs, 'items-edit' ).getAttribute( 'target' ) === '_blank' );
	const xrows = all( xf( xs, 'items' ), 'tbody tr' );
	check( '...a row leads to the editor of its item: the title in the native language is the link (the site lists en_US first), and a click on the row goes there as well; an item with no title in that language is named by its uri', all( xrows[0], 'a' ).length === 1 && all( xrows[0].children[1], 'a' )[0].getAttribute( 'href' ) === '#position-mixing' && textOf( all( xrows[0], 'a' )[0] ) === 'Mixing'
		&& textOf( all( xrows[1], 'a' )[0] ) === 'recording' && all( xrows[1], 'a' )[0].getAttribute( 'href' ) === '#position-recording' && xf( xs, 'items' ).classList.contains( 'static' ) === false );
	const xclick = await xopen( '#positionen' );
	click( xclick, all( xf( xclick, 'items' ), 'tbody tr' )[2] );
	check( '...a click on a row goes to its editor', xclick.location.hash === '#position-alt' );
	check( '...the subtitle is the one of the editor, and the read-only screen has a sentence of its own that does not say the type is kept in the panel beside the one that says the permission is missing', textOf( all( xs.main, '.sub' )[0] ) === texts['items.sub-edit'] && texts['items.sub'] !== texts['items.sub-edit'] );
	check( '...its sentence under the table says where else they are kept, and the empty table invites to the first one', textOf( all( xs.main, '.state-note' ).pop() ).indexOf( 'zweite Ort' ) !== -1
		&& textOf( all( xf( await xopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : [] } ) ] } } ), 'items' ), 'tbody' )[0] ).indexOf( 'Neue Position' ) !== -1 );
	const xr = await xopen( '#positionen', {}, { crm : true, pdf : false, elements : false } );
	check( 'without the permission the screen is the read-only one of C08: the table with no way in - no link, no click -, no primary button, the link to the Elements panel as a secondary one, and the sentence that says the permission is missing', xf( xr, 'items-new' ) === undefined && textOf( xf( xr, 'items-edit' ) ) === 'Im Panel Elemente bearbeiten' && xf( xr, 'items-edit' ).classList.contains( 'primary' ) === false && all( xr.main, '.primary' ).length === 0
		&& all( xf( xr, 'items' ), 'a' ).length === 0 && xf( xr, 'items' ).classList.contains( 'static' ) === true && ( click( xr, all( xf( xr, 'items' ), 'tbody tr' )[0] ), xr.location.hash === '#positionen' ) && textOf( all( xr.main, '.sub' )[0] ) === texts['items.sub'] && texts['items.sub'].indexOf( 'Panel Elemente' ) === -1 && textOf( all( xr.main, '.state-note' ).pop() ) === texts['items.note'] && texts['items.note'].indexOf( 'Berechtigung des Panels Elemente' ) !== -1 );

	// --- the router
	check( 'the hashes name the editor: #positionen-neu a new item, #position-<uri> one that is there - the uri as the panel allows it - and the rail marks Positionen; what is no uri is the Übersicht', xs.app.parse( '#positionen-neu' ).screen === 'position' && xs.app.parse( '#positionen-neu' ).uri === undefined
		&& JSON.stringify( xs.app.parse( '#position-neu' ) ) === '{"screen":"position","uri":"neu"}' && JSON.stringify( xs.app.parse( '#position-mueller-2_x' ) ) === '{"screen":"position","uri":"mueller-2_x"}' && xs.app.parse( '#position-' ).screen === 'uebersicht' && xs.app.parse( '#position-a/b' ).screen === 'uebersicht' && xs.app.parse( '#position--x' ).screen === 'uebersicht'
		&& all( ( await xopen( '#position-mixing' ) ).nav, '[data-r]' ).filter( function( a ) { return a.classList.contains( 'on' ) } ).map( function( a ) { return a.getAttribute( 'data-r' ) } ).join() === 'positionen' );

	// --- the table: search, the sort by the heads, pages of fifty
	const xt0 = await xopen( '#positionen' );
	const xids = function( w ) { return all( xf( w, 'items' ), 'tbody tr' ).map( function( tr ) { return tr.dataset.id } ).join() };
	const xhead = function( w, key ) { return all( xf( w, 'items' ), 'button' ).find( function( b ) { return b.dataset.sort === key } ) };
	check( 'the table has the heads of the sort: the native title, Netto, USt, Aktiv and Reihenfolge are buttons, the other languages and the unit are not; it starts in the order of the field sort, ascending', all( xf( xt0, 'items' ), 'button' ).map( function( b ) { return b.dataset.sort } ).join() === 'title,price,vat,active,sort'
		&& xids( xt0 ) === 'mixing,recording,alt' && all( xf( xt0, 'items' ), 'th' )[6].getAttribute( 'aria-sort' ) === 'ascending' && textOf( xhead( xt0, 'sort' ) ) === 'Reihenfolge ↑' );
	click( xt0, xhead( xt0, 'title' ) );
	const xbyTitle = xids( xt0 );
	click( xt0, xhead( xt0, 'title' ) );
	check( '...the native title sorts the words (no title first, markup as the text it is) and turns on a second click', xbyTitle === 'recording,alt,mixing' && xids( xt0 ) === 'mixing,alt,recording' && all( xf( xt0, 'items' ), 'th' )[1].getAttribute( 'aria-sort' ) === 'descending' );
	click( xt0, xhead( xt0, 'price' ) );
	const xbyPrice = xids( xt0 );
	click( xt0, xhead( xt0, 'vat' ) );
	const xbyVat = xids( xt0 );
	click( xt0, xhead( xt0, 'active' ) );
	const xbyActive = xids( xt0 );
	click( xt0, xhead( xt0, 'sort' ) );
	click( xt0, xhead( xt0, 'sort' ) );
	check( '...Netto sorts by the cents (100, 5000, 9050), USt by the hundredths, Aktiv with the ones that are off first, Reihenfolge by the number - and turns', xbyPrice === 'alt,recording,mixing' && xbyVat === 'alt,recording,mixing' && xbyActive === 'alt,mixing,recording' && xids( xt0 ) === 'alt,recording,mixing' );
	const xq = function( w, text ) { type( xf( w, 'q' ), text, false ) };
	xq( xt0, 'RECORDING' );
	const xfoundTitle = xids( xt0 );
	xq( xt0, 'day' );
	const xfoundOther = xids( xt0 );
	xq( xt0, 'std' );
	const xfoundUnit = xids( xt0 );
	xq( xt0, 'nichts da' );
	check( 'the search looks in the titles of every language and in the units, whatever the case: a title of the other language and a unit are found; nothing found says so', xfoundTitle === 'recording' && xfoundOther === 'recording' && xfoundUnit === 'alt,mixing' && textOf( all( xf( xt0, 'items' ), 'tbody' )[0] ) === texts['items.none'] );
	const xhundred = [];
	for( let n = 1; n <= 120; n++ )
		xhundred.push( { id : 'p'+ String( n ).padStart( 3, '0' ), sort : n, price : n * 100, vat : 0, active : true, words : { de_DE : { title : 'Position '+ n, description : '', unit : n % 2 === 0 ? 'Std.' : '' }, en_US : { title : '', description : '', unit : '' } } } );
	const xpg = await xopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : xhundred } ) ] } } );
	check( 'a hundred and twenty items are three pages of fifty: the pager says which, Zurück is off on the first', all( xf( xpg, 'items' ), 'tbody tr' ).length === 50 && textOf( all( xpg.main, '.pager .state-note' )[0] ) === 'Seite 1 von 3' && xf( xpg, 'prev' ).disabled === true && xf( xpg, 'next' ).disabled === false );
	click( xpg, xf( xpg, 'next' ) );
	click( xpg, xf( xpg, 'next' ) );
	check( '...the last page has twenty, the next is off', all( xf( xpg, 'items' ), 'tbody tr' ).length === 20 && textOf( all( xpg.main, '.pager .state-note' )[0] ) === 'Seite 3 von 3' && xf( xpg, 'next' ).disabled === true && xids( xpg ).split( ',' )[0] === 'p101' );
	xq( xpg, 'Position 7' );
	check( '...a search goes back to the first page and counts what is found: Position 7, 70 to 79 - eleven items', xids( xpg ).split( ',' ).length === 11 && all( xpg.main, '.pager' ).length === 0 );
	const xro = await xopen( '#positionen', {}, { crm : true, pdf : false, elements : false } );
	click( xro, xhead( xro, 'price' ) );
	check( 'the read-only screen has the search and the sort as well', xids( xro ) === 'alt,recording,mixing' && xf( xro, 'q' ) !== undefined );

	// --- numbers sort as numbers, an order that is not there is empty
	const xitem = function( id, sort, price ) { return { id : id, sort : sort, price : price, vat : 0, active : true, words : { de_DE : { title : id, description : '', unit : '' }, en_US : { title : '', description : '', unit : '' } } } };
	const xbig = await xopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : [ xitem( 'a', 1, 1200000 ), xitem( 'b', 2, 900000 ), xitem( 'c', 3, 99900 ) ] } ) ] } } );
	click( xbig, xhead( xbig, 'price' ) );
	check( 'Netto sorts as a number, not as text: 999,00 and 9.000,00 come before 12.000,00 although "1200000" comes before "900000" as a text', xids( xbig ) === 'c,b,a' );
	const xnosortTable = await xopen( '#positionen', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : [ xitem( 'ohne', null, 100 ), xitem( 'null', 0, 100 ), xitem( 'drei', 3, 100 ) ] } ) ] } } );
	check( 'an item with no order shows an empty cell in Reihenfolge, not 0 - and one whose order is 0 shows 0', all( xf( xnosortTable, 'items' ), 'tbody tr' ).map( function( tr ) { return tr.dataset.id+ '=' + textOf( tr.children[6] ) } ).sort().join( '|' ) === 'drei=3|null=0|ohne=' );
	const xnosortEditor = await xopen( '#position-mixing', { 'elements/get' : function() { return [ 200, egot( { global : { price : 90.5, vat : 19, hidden : false } } ) ] } } );
	const xzeroEditor = await xopen( '#position-mixing', { 'elements/get' : function() { return [ 200, egot( { global : { price : 90.5, vat : 19, hidden : false, sort : 0 } } ) ] } } );
	check( '...and the editor of an item with no order has an empty field, where an order of 0 is 0', xf( xnosortEditor, 'f-sort' ).value === '' && xf( xzeroEditor, 'f-sort' ).value === '0' );

	// --- a change posts what was changed
	const xc1 = await xopen( '#position-mixing' );
	type( xf( xc1, 'f-price' ), '99', false );
	await xsave( xc1 );
	check( 'a changed price alone posts the price alone: no words, no other number, and no second language', eposts.length === 1 && JSON.stringify( eposts[0].fields ) === '{"price":99}' && eposts[0].isNew === false );
	const xc0 = await xopen( '#position-mixing' );
	await xsave( xc0 );
	check( '...a save with nothing changed posts nothing, and the item is read again', eposts.length === 0 && xc0.all( 'elements/get' ).length === 2 );
	const xnosort = await xopen( '#position-mixing', { 'elements/get' : function() { return [ 200, egot( { global : { price : 90.5, vat : 19, hidden : false } } ) ] } } );
	type( xf( xnosort, 'f-title-de_DE' ), 'Mixing Plus', false );
	await xsave( xnosort );
	check( '...an item made elsewhere without an order is not given one by a change of its title: only the title goes', eposts.length === 1 && JSON.stringify( eposts[0].fields ) === '{"title":"Mixing Plus"}' );
	// the unit of the electronic invoice, where the type has the field
	const withCode = function( over ) { return { 'crm-documents/positionen' : function() { return [ 200, elist( { unit_code : true } ) ] }, 'elements/get' : function() { return [ 200, egot( { global : Object.assign( { unit_code : 'HUR', price : 90.5, vat : 19, hidden : false, sort : 1 }, over || {} ) } ) ] } } };
	const xcode = await xopen( '#position-mixing', withCode() );
	check( 'the shared card of an item has the select of the unit of the electronic invoice where the type has the field: "Einheit wählen" and the nine codes by their words, the code of the item chosen, first among the shared fields', xf( xcode, 'f-unit_code' ).tagName === 'SELECT' && xf( xcode, 'f-unit_code' ).value === 'HUR'
		&& all( xf( xcode, 'f-unit_code' ), 'option' ).map( function( o ) { return o.value+ ':'+ textOf( o ) } ).join( '|' ) === [ '' ].concat( [ 'H87', 'HUR', 'MIN', 'DAY', 'WEE', 'MON', 'LS', 'C62', 'KMT' ] ).map( function( code ) { return code+ ':'+ texts[code === '' ? 'unitcode.none' : 'unitcode.'+ code.toLowerCase()] } ).join( '|' )
		&& all( xcards( xcode )[2], 'dt' ).map( textOf ).join( '|' ) === [ 'items.f-unit-code', 'items.f-price', 'items.f-vat', 'items.f-active', 'items.f-sort' ].map( function( k ) { return texts[k] } ).join( '|' ) && all( xcode.main, '#position-no-unit-code' ).length === 0 );
	type( xf( xcode, 'f-unit_code' ), 'DAY', false );
	await xsave( xcode );
	check( '...a changed code alone posts the code alone, and an unchanged one posts nothing', eposts.length === 1 && JSON.stringify( eposts[0].fields ) === '{"unit_code":"DAY"}' && eposts[0].isNew === false );
	const xcode0 = await xopen( '#position-mixing', withCode() );
	await xsave( xcode0 );
	check( '...(nothing changed: nothing posted)', eposts.length === 0 );
	const xnocode = await xopen( '#position-mixing' );
	check( 'where the type has no such field (a project that had the type before) there is no select and no post of the code - the sentence says where to make the field', xf( xnocode, 'f-unit_code' ) === undefined && textOf( xf( xnocode, 'position-no-unit-code' ) ) === texts['items.no-unit-code'] );
	type( xf( xnocode, 'f-price' ), '91', false );
	await xsave( xnocode );
	check( '...and then a save posts the price alone', JSON.stringify( eposts[0].fields ) === '{"price":91}' );
	const xnewCode = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { unit_code : true } ) ] } } );
	type( xf( xnewCode, 'f-title-de_DE' ), 'Neu mit Einheit', false );
	type( xf( xnewCode, 'f-price' ), '1', false );
	await xsave( xnewCode );
	const noCodePosted = Object.keys( eposts[0].fields ).join();
	const xnewCode2 = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { unit_code : true } ) ] } } );
	type( xf( xnewCode2, 'f-title-de_DE' ), 'Neu mit Einheit', false );
	type( xf( xnewCode2, 'f-price' ), '1', false );
	type( xf( xnewCode2, 'f-unit_code' ), 'MON', false );
	await xsave( xnewCode2 );
	check( 'a new item posts the code only where one is chosen: none, none in the post - MON, with the others', noCodePosted === 'title,description,unit,price,vat,hidden,sort' && eposts.length === 1 && eposts[0].fields.unit_code === 'MON' && Object.keys( eposts[0].fields ).join() === 'title,description,unit,price,vat,unit_code,hidden,sort' );

	const xnew0 = await xopen( '#positionen-neu' );
	type( xf( xnew0, 'f-title-de_DE' ), 'Ganz neu', false );
	type( xf( xnew0, 'f-price' ), '1', false );
	await xsave( xnew0 );
	check( '...a new item posts everything it has, the numbers it was started with included', JSON.stringify( Object.keys( eposts[0].fields ) ) === '["title","description","unit","price","vat","hidden","sort"]' );
	const xstored = await xopen( '#position-mixing', { 'elements/get' : function() { return [ 200, egot( { global : { price : -5, vat : 19, hidden : false, sort : 1 } } ) ] } } );
	type( xf( xstored, 'f-title-de_DE' ), 'Mixing Rabatt', false );
	await xsave( xstored );
	check( 'a price that is not looked at is not refused: an item that holds a price below nought and has only its title changed posts the title alone', eposts.length === 1 && JSON.stringify( eposts[0].fields ) === '{"title":"Mixing Rabatt"}' && all( xstored.main, '.err' ).length === 0 );
	const xmapped = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return xfail( 403, 'elements_fields_not_allowed', [ 'price, vat, hidden, sort, title, description, unit' ], 'price' ) } } );
	type( xf( xmapped, 'f-price' ), '3', false );
	await xsave( xmapped );
	check( 'the fields a refusal names are called as the screen calls them, not by the keys of the model: Preis netto, Steuersatz, Angeboten, Reihenfolge, Titel, Beschreibung, Einheit', xnote( xmapped, 'f-price' ) === 'Dein Konto darf diese Felder nicht ändern: Preis netto, Steuersatz, Angeboten, Reihenfolge, Titel, Beschreibung, Einheit' );
	const xhint = await xopen( '#position-mixing' );
	check( 'the hint under the title of another language says what is the case, in the sentence that has its key (and not under the native one)', textOf( all( xcards( xhint )[1], '.state-note' )[0] ) === texts['items.hint-title-lang'] && texts['items.hint-title-lang'] !== 'Ohne Titel wird diese Sprache nicht gespeichert.' && textOf( xcards( xhint )[0] ).indexOf( texts['items.hint-title-lang'] ) === -1 );
	const xdefault = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { settings : Object.assign( {}, settings, { rates : [ 700, 1900 ] } ) } ) ] } } );
	check( 'a new item starts with the highest rate the settings list, wherever it stands in the list: 7 and 19 make 19', xf( xdefault, 'f-vat' ).value === '19' );
	const xneg = await xopen( '#positionen-neu' );
	type( xf( xneg, 'f-title-de_DE' ), 'Rabatt', false );
	type( xf( xneg, 'f-price' ), '-5', false );
	await xsave( xneg );
	check( 'a price below nought is refused beside the price, and nothing is posted', eposts.length === 0 && xnote( xneg, 'f-price' ) === texts['items.err-price'] && xf( xneg, 'f-price' ).classList.contains( 'err' ) === true );
	const xneu = await xopen( '#position-neu', { 'elements/get' : function( data ) { return data.uri === 'neu' ? [ 200, egot() ] : [ 404, { error : 'x' } ] } } );
	check( 'an item that is named neu opens at #position-neu like any other: the address of a new item is #positionen-neu', xcards( xneu ).length === 3 && textOf( xf( xneu, 'position-uri' ) ) === '/positionen/neu' && xneu.all( 'elements/get' ).length === 1 );

	// --- a save that is running: the way out waits for it
	const xcreated = [];
	const xrun = hold();
	const xwaiting = await xopen( '#positionen-neu', { 'elements/save' : function( data ) { eposts.push( data ); return xrun.promise.then( function() { if( data.isNew === true ) xcreated.push( { id : data.uri, sort : 30, price : 1000, vat : 0, active : true, words : { de_DE : { title : data.fields.title, description : '', unit : '' }, en_US : { title : '', description : '', unit : '' } } } ); return [ 200, { element : {} } ] } ) },
		'crm-documents/positionen' : function() { return [ 200, elist( { items : eitems.concat( xcreated ) } ) ] } } );
	type( xf( xwaiting, 'f-title-de_DE' ), 'Wartend', false );
	type( xf( xwaiting, 'f-price' ), '10', false );
	await xsave( xwaiting );
	const xlink = all( xwaiting.nav, '[data-r]' )[3];
	click( xwaiting, xlink );
	xwaiting.location.hash = '#positionen';
	await settle();
	check( 'while the posts are on their way a click on a link away asks nothing - the toast says Speichern läuft - and the screen that is asked for is not drawn yet, so that it cannot lack the item', xwaiting.dialogs.length === 0 && textOf( xwaiting.toast ) === texts['items.saving'] && textOf( xwaiting.main ) === texts['items.saving'] && xf( xwaiting, 'items' ) === undefined );
	xrun.release();
	await settle();
	check( '...once they are through the table is drawn from a read that came after them: it holds the new item', xf( xwaiting, 'items' ) !== undefined && xids( xwaiting ).split( ',' ).includes( 'wartend' ) === true && xwaiting.location.hash === '#positionen' );

	// --- the name of an item
	const xkey = xs.app.model.positionKey;
	check( 'the name of a new item is its native title as a slug, umlauts spelled out, as the key of a template is: Äpfel & Öl – Größe is aepfel-oel-groesse; -2 and on after a name that was taken; neu is a name like another, never empty, at most 40 characters without a dash at the end',
		xkey( 'Äpfel & Öl – Größe' ) === 'aepfel-oel-groesse' && xkey( 'Müller Beratung', 1 ) === 'mueller-beratung' && xkey( 'Müller Beratung', 2 ) === 'mueller-beratung-2' && xkey( 'Müller Beratung', 9 ) === 'mueller-beratung-9' && xkey( 'Neu' ) === 'neu' && xkey( '!!!' ) === 'position' && xkey( '' ) === 'position'
		&& xkey( 'Ein sehr langer Titel einer Position, der kein Ende nehmen will' ).length <= 40 && /^[a-z0-9]+(-[a-z0-9]+)*$/.test( xkey( 'Ein sehr langer Titel einer Position, der kein Ende nehmen will' ) ) === true && /^[A-Za-z0-9][A-Za-z0-9_-]*$/.test( xkey( 'é à ñ 12' ) ) === true );

	// --- the editor of an item that is there
	const xe = await xopen( '#position-mixing' );
	check( 'the editor asks the panel for the item (type positionen, its uri) and the list for the languages and the names taken; the cards of the languages are in the order of the site with the native one first - the site lists en_US first -, then the card of what is shared',
		JSON.stringify( xe.last( 'elements/get' ).data ) === '{"type":"positionen","uri":"mixing"}' && xe.all( 'crm-documents/positionen' ).length === 1 && xcards( xe ).map( function( card ) { return card.id } ).join() === 'position-lang-de_DE,position-lang-en_US,position-shared' && xcards( xe ).length === 3
		&& textOf( all( xcards( xe )[0], 'h3' )[0] ).indexOf( 'Muttersprache der Website' ) !== -1 && textOf( all( xcards( xe )[1], 'h3' )[0] ).indexOf( 'Muttersprache' ) === -1 && textOf( all( xcards( xe )[2], 'h3' )[0] ) === 'Für alle Sprachen' );
	check( '...a language card has the title, the description (a text area) and the unit, with what the panel holds in that language; the shared card has the price with its decimal comma, the rate, the switch Angeboten (on: the item is not hidden) and the order', xf( xe, 'f-title-de_DE' ).value === 'Mixing' && xf( xe, 'f-description-de_DE' ).value === 'Mischung eines Songs\nbis 48 Spuren' && xf( xe, 'f-description-de_DE' ).tagName === 'TEXTAREA'
		&& xf( xe, 'f-unit-de_DE' ).value === 'Std.' && xf( xe, 'f-unit-en_US' ).value === 'h' && xf( xe, 'f-description-en_US' ).value === 'Mixing of one song' && xf( xe, 'f-price' ).value === '90,50' && xf( xe, 'f-vat' ).value === '19' && xf( xe, 'f-sort' ).value === '1'
		&& xf( xe, 'f-active' ).type === 'checkbox' && xf( xe, 'f-active' ).checked === true && xf( xe, 'f-active' ).getAttribute( 'role' ) === 'switch'
		&& all( xcards( xe )[2], 'dt' ).map( textOf ).join( '|' ) === 'Preis netto (€)|Steuersatz (%)|Angeboten|Reihenfolge' && all( xcards( xe )[0], 'dt' ).map( textOf ).join( '|' ) === 'Titel|Beschreibung|Einheit' );
	check( '...the uri is shown, and it is text - there is no field for it; the menu ⋯ has Löschen; nothing is unsaved yet', textOf( xf( xe, 'position-uri' ) ) === '/positionen/mixing' && all( xf( xe, 'position-form' ), 'input' ).filter( function( i ) { return i.value === 'mixing' } ).length === 0 && itemOf( xe.main, 'Löschen' ) !== undefined && xe.app.state.draft.dirty === false );
	const xh = await xopen( '#position-versteckt' );
	check( '...an item that is hidden has the switch off; a price of more than a thousand has its dot, a rate its comma', xf( xh, 'f-active' ).checked === false && xf( xh, 'f-price' ).value === '1.234,50' && xf( xh, 'f-vat' ).value === '7,5' && xf( xh, 'f-sort' ).value === '4' );
	const xl = await xopen( '#position-versteckt', { 'crm-documents/positionen' : function() { return [ 200, elist( { locales : [ 'de_DE' ] } ) ] } } );
	check( '...a site with one language has one card of words and the shared one', xcards( xl ).map( function( card ) { return card.id } ).join() === 'position-lang-de_DE,position-shared' );

	// --- Speichern: one post per language, the native one first with the numbers, and only the languages that were changed
	type( xf( xe, 'f-title-de_DE' ), 'Mixing Pro', false );
	type( xf( xe, 'f-price' ), '1.234,5', false );
	type( xf( xe, 'f-vat' ), '7,5', false );
	xtoggle( xf( xe, 'f-active' ), false );
	type( xf( xe, 'f-sort' ), '3', false );
	check( 'what is typed is unsaved input: the guard of the page knows it, and a link away asks first in the words of a form', xe.app.state.draft.dirty === true && click( xe, all( xe.nav, '[data-r]' )[0] ) === true && xe.dialogs.length === 1 && textOf( all( xe.dialogs[0], 'p' )[0] ) === 'Die Seite enthält Änderungen, die noch nicht gespeichert sind.' );
	click( xe, all( xe.dialogs[0], 'button' )[0] );
	xe.dialogs[0].close();
	await xsave( xe );
	check( 'Speichern posts one language: the native one, with what was changed - the title and the numbers: the price as a number (1234.5), the rate as a number (7.5), hidden the opposite of the switch, the order as a whole number - and what was not changed - the description, the unit, the language that was left alone - is not posted',
		eposts.length === 1 && JSON.stringify( eposts[0] ) === JSON.stringify( { type : 'positionen', uri : 'mixing', locale : 'de_DE', isNew : false, fields : { title : 'Mixing Pro', price : 1234.5, vat : 7.5, hidden : true, sort : 3 } } ) );
	check( '...it says Gespeichert., nothing is unsaved, and the item is read again from the panel so that the editor shows what is stored', textOf( xe.toast ) === 'Gespeichert.' && xe.app.state.draft.dirty === false && xe.all( 'elements/get' ).length === 2 );
	const xe2 = await xopen( '#position-mixing' );
	type( xf( xe2, 'f-title-de_DE' ), 'Mixing', false );
	type( xf( xe2, 'f-unit-en_US' ), 'hour', false );
	type( xf( xe2, 'f-description-de_DE' ), 'Mischung', false );
	await xsave( xe2 );
	check( '...a changed second language is the second post: its words alone, the uri of the first, isNew false - the order of the posts is the order of the cards, and a number that was not touched is not posted at all', eposts.length === 2 && eposts[0].locale === 'de_DE' && eposts[1].locale === 'en_US' && JSON.stringify( eposts[0].fields ) === '{"description":"Mischung"}'
		&& JSON.stringify( eposts[1] ) === JSON.stringify( { type : 'positionen', uri : 'mixing', locale : 'en_US', isNew : false, fields : { unit : 'hour' } } ) );

	// --- a new item
	const xn = await xopen( '#positionen-neu' );
	check( 'a new item reads the list and nothing of the panel; the cards are empty, the switch is on, the rate is the highest the settings list, the order is the next after the last, there is no uri to show and no menu', xn.all( 'elements/get' ).length === 0 && xf( xn, 'f-title-de_DE' ).value === '' && xf( xn, 'f-price' ).value === '' && xf( xn, 'f-active' ).checked === true
		&& xf( xn, 'f-vat' ).value === '19' && xf( xn, 'f-sort' ).value === '8' && textOf( xf( xn, 'position-uri' ) ) === '' && itemOf( xn.main, 'Löschen' ) === undefined && textOf( all( xn.main, 'h1' )[0] ) === 'Neue Position' );
	const xs0 = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { settings : smallBusiness } ) ] } } );
	check( '...a small business has the rate 0 to start from', xf( xs0, 'f-vat' ).value === '0' );
	type( xf( xn, 'f-title-de_DE' ), 'Müller Beratung & Söhne', false );
	type( xf( xn, 'f-unit-de_DE' ), 'Tag', false );
	type( xf( xn, 'f-title-en_US' ), 'Consulting', false );
	type( xf( xn, 'f-price' ), '90', false );
	await xsave( xn );
	check( 'Speichern posts the native language first - with the uri made of its title, isNew true and the numbers - and then the other, as an update of the item the first post made', eposts.length === 2 && eposts[0].uri === 'mueller-beratung-soehne' && eposts[0].locale === 'de_DE' && eposts[0].isNew === true
		&& JSON.stringify( eposts[0].fields ) === JSON.stringify( { title : 'Müller Beratung & Söhne', description : '', unit : 'Tag', price : 90, vat : 19, hidden : false, sort : 8 } ) && eposts[1].uri === 'mueller-beratung-soehne' && eposts[1].locale === 'en_US' && eposts[1].isNew === false
		&& JSON.stringify( eposts[1].fields ) === '{"title":"Consulting","description":"","unit":""}' );
	check( '...and the editor of the item that is there opens: the address names it', xn.location.hash === '#position-mueller-beratung-soehne' );
	const xt = await xopen( '#positionen-neu' );
	type( xf( xt, 'f-title-de_DE' ), 'Mixing', false );
	type( xf( xt, 'f-price' ), '10', false );
	await xsave( xt );
	check( 'a name that is taken is the next: Mixing is mixing-2 when mixing is there, and mixing-3 when both are', eposts[0].uri === 'mixing-2' );
	const xt3 = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : eitems.concat( [ { id : 'mixing-2', sort : 9, price : 1, vat : 0, active : true, words : { de_DE : { title : 'x', description : '', unit : '' }, en_US : { title : '', description : '', unit : '' } } } ] ) } ) ] } } );
	type( xf( xt3, 'f-title-de_DE' ), 'Mixing', false );
	type( xf( xt3, 'f-price' ), '10', false );
	await xsave( xt3 );
	check( '...and mixing-3 when mixing-2 is there as well', eposts[0].uri === 'mixing-3' );
	const xmany = [ 'mixing' ].concat( [ 2, 3, 4, 5, 6, 7, 8, 9 ].map( function( n ) { return 'mixing-'+ n } ) ).map( function( id ) { return Object.assign( {}, eitems[0], { id : id } ) } );
	const xt9 = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { items : xmany } ) ] } } );
	type( xf( xt9, 'f-title-de_DE' ), 'Mixing', false );
	type( xf( xt9, 'f-price' ), '10', false );
	await xsave( xt9 );
	check( '...after nine tries there is none: nothing is posted and the sentence is beside the title', eposts.length === 0 && xnote( xt9, 'f-title-de_DE' ) === texts['items.err-uri'] && xf( xt9, 'f-title-de_DE' ).classList.contains( 'err' ) === true );
	const xlate = await xopen( '#positionen-neu', { 'elements/save' : function( data ) { eposts.push( data ); return eposts.length === 1 ? xfail( 400, '' ) : [ 200, { element : {} } ] },
		'crm-documents/positionen' : (function() { let reads = 0; return function() { reads++; return [ 200, elist( reads === 1 ? {} : { items : eitems.concat( [ { id : 'frisch', sort : 9, price : 1, vat : 0, active : true, words : { de_DE : { title : 'Frisch', description : '', unit : '' }, en_US : { title : '', description : '', unit : '' } } } ] ) } ) ] } })() } );
	type( xf( xlate, 'f-title-de_DE' ), 'Frisch', false );
	type( xf( xlate, 'f-price' ), '10', false );
	await xsave( xlate );
	check( 'a name that was free when the list was read and is taken now - the panel refuses the first post - is tried again as the next name, once the list says so', eposts.map( function( post ) { return post.uri } ).join() === 'frisch,frisch-2' && eposts[1].isNew === true && xlate.location.hash === '#position-frisch-2' );

	// --- what keeps a save back, said beside the field and before anything is posted
	const xv = await xopen( '#positionen-neu' );
	await xsave( xv );
	check( 'a new item with no title and no price posts nothing: the sentences are beside the title (of the native language) and the price, the controls marked, the focus on the first, the toast says the first', eposts.length === 0 && xnote( xv, 'f-title-de_DE' ) === texts['items.err-title'] && xnote( xv, 'f-price' ) === texts['items.err-price']
		&& xf( xv, 'f-title-de_DE' ).classList.contains( 'err' ) === true && xf( xv, 'f-price' ).getAttribute( 'aria-invalid' ) === 'true' && xf( xv, 'f-title-en_US' ).classList.contains( 'err' ) === false && textOf( xv.toast ) === texts['items.err-title'] && xv.toast.classList.contains( 'bad' ) === true );
	type( xf( xv, 'f-title-de_DE' ), 'Ok', false );
	check( 'typing in a field takes its refusal away', xnote( xv, 'f-title-de_DE' ) === '' && xf( xv, 'f-title-de_DE' ).classList.contains( 'err' ) === false && xf( xv, 'f-price' ).classList.contains( 'err' ) === true );
	type( xf( xv, 'f-price' ), '1,234', false );
	type( xf( xv, 'f-vat' ), '120', false );
	type( xf( xv, 'f-sort' ), '1,5', false );
	type( xf( xv, 'f-description-en_US' ), 'Only a description', false );
	await xsave( xv );
	check( '...a price with three decimals, a rate of more than a hundred, an order that is no whole number and another language that has words and no title are each refused beside their field', eposts.length === 0 && xnote( xv, 'f-price' ) === texts['items.err-price'] && xnote( xv, 'f-vat' ) === texts['items.err-vat'] && xnote( xv, 'f-sort' ) === texts['items.err-sort'] && xnote( xv, 'f-title-en_US' ) === texts['items.err-title-lang'] );
	type( xf( xv, 'f-price' ), '1.234,50', false );
	type( xf( xv, 'f-vat' ), '', false );
	type( xf( xv, 'f-sort' ), '', false );
	type( xf( xv, 'f-title-en_US' ), 'Title', false );
	await xsave( xv );
	check( '...and with them put right it goes: an empty rate and an empty order are 0, the price is a number', eposts.length === 2 && eposts[0].fields.price === 1234.5 && eposts[0].fields.vat === 0 && eposts[0].fields.sort === 0 );
	const xcl = await xopen( '#position-mixing' );
	type( xf( xcl, 'f-title-en_US' ), '', false );
	await xsave( xcl );
	check( 'a language that had words cannot be emptied (the title is required of every language that is written): the sentence is beside its title and nothing is posted', eposts.length === 0 && xnote( xcl, 'f-title-en_US' ) === texts['items.err-title-lang'] );

	// --- the refusals of the panel, beside the field they name
	const xr1 = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return xfail( 403, 'elements_fields_not_allowed', [ 'price' ], 'price' ) } } );
	type( xf( xr1, 'f-price' ), '99', false );
	await xsave( xr1 );
	check( 'the panel\'s refusal with a field is put beside that field, in the panel\'s sentence with its params: the price', xnote( xr1, 'f-price' ) === 'Dein Konto darf diese Felder nicht ändern: Preis netto' && xf( xr1, 'f-price' ).classList.contains( 'err' ) === true && xr1.toast.classList.contains( 'bad' ) === true && xr1.app.state.draft.dirty === true && all( xr1.main, '#position-save' )[0].disabled === false );
	xr1.answers['elements/save'] = function( data ) { eposts.push( data ); return xfail( 400, '' ) };
	await xsave( xr1 );
	check( '...a second Speichern takes the old refusals away before it posts: the answer that names no field leaves none marked', xnote( xr1, 'f-price' ) === '' && all( xr1.main, '.err' ).length === 0 && textOf( xr1.toast ) === texts['items.error-save'] );
	const xr2 = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return xfail( 403, 'elements_fields_not_allowed', [ 'hidden' ], 'hidden' ) } } );
	xtoggle( xf( xr2, 'f-active' ), false );
	await xsave( xr2 );
	check( '...hidden is the switch Angeboten, the title of a language the card of the language that was being saved', xnote( xr2, 'f-active' ) === 'Dein Konto darf diese Felder nicht ändern: Angeboten' && xf( xr2, 'f-active' ).classList.contains( 'err' ) === true );
	const xr3 = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return data.locale === 'en_US' ? xfail( 403, 'elements_fields_not_allowed', [ 'title' ], 'title' ) : [ 200, { element : {} } ] } } );
	type( xf( xr3, 'f-title-de_DE' ), 'Mixing 1', false );
	type( xf( xr3, 'f-title-en_US' ), 'Mixing 2', false );
	await xsave( xr3 );
	check( '...the first language went, the second was refused: the sentence is beside the title of the English card, not of the German one, and the page stays unsaved so that a second Speichern tries again', eposts.length === 2 && xnote( xr3, 'f-title-en_US' ) === 'Dein Konto darf diese Felder nicht ändern: Titel' && xnote( xr3, 'f-title-de_DE' ) === '' && xr3.app.state.draft.dirty === true && xr3.location.hash === '#position-mixing' );
	const xr4 = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return xfail( 400, '' ) } } );
	type( xf( xr4, 'f-price' ), '99', false );
	await xsave( xr4 );
	check( 'a refusal with no code and no field - the kernel\'s message as the panel gives it - is the sentence of the screen in the toast, and nothing is marked', textOf( xr4.toast ) === texts['items.error-save'] && all( xr4.main, '.err' ).length === 0 );
	const xp = await xopen( '#positionen-neu', { 'elements/save' : function( data ) { eposts.push( data ); return data.locale === 'en_US' && eposts.length === 2 ? xfail( 400, '' ) : [ 200, { element : {} } ] } } );
	type( xf( xp, 'f-title-de_DE' ), 'Neu angelegt', false );
	type( xf( xp, 'f-title-en_US' ), 'Newly made', false );
	type( xf( xp, 'f-price' ), '5', false );
	await xsave( xp );
	check( 'a refused second language leaves the uri of the item that was made on the screen: it is there, and the next save is no new item', textOf( xf( xp, 'position-uri' ) ) === '/positionen/neu-angelegt' && eposts.length === 2 );
	await xsave( xp );
	check( 'a new item whose second language was refused is there: the next Speichern updates it under the uri it was made under, and does not make it twice', eposts.length === 4 && eposts[0].isNew === true && eposts[2].isNew === false && eposts[2].uri === 'neu-angelegt' && eposts[3].uri === 'neu-angelegt' && eposts[3].locale === 'en_US' && xp.location.hash === '#position-neu-angelegt' );

	// --- Löschen
	let xgone = null;
	const xd = await xopen( '#position-mixing', { 'elements/delete' : function( data ) { xgone = data; return [ 200, { ok : true } ] } } );
	click( xd, itemOf( menuOf( xd.main, '⋯' ), 'Löschen' ) );
	check( 'Löschen asks first, with the title and with what stays: the lines of documents keep what they copied', xd.dialogs.length === 1 && textOf( all( xd.dialogs[0], 'p' )[0] ) === 'Die Position „Mixing“ wird in allen Sprachen gelöscht. Die Zeilen von Belegen behalten, was sie kopiert haben.' && xgone === null );
	click( xd, all( xd.dialogs[0], 'button' )[0] );
	await settle();
	check( '...no is no: nothing is posted, the editor stays', xgone === null && xd.location.hash === '#position-mixing' );
	click( xd, itemOf( menuOf( xd.main, '⋯' ), 'Löschen' ) );
	click( xd, all( xd.dialogs[1], 'button' ).pop() );
	await settle();
	check( '...yes posts elements/delete with the type and the uri, says Gelöscht. and leads back to the table', JSON.stringify( xgone ) === '{"type":"positionen","uri":"mixing"}' && textOf( xd.toast ) === 'Gelöscht.' && xd.location.hash === '#positionen' );
	const xdf = await xopen( '#position-mixing', { 'elements/delete' : function() { return xfail( 403, 'elements_fields_not_allowed', [ 'x' ] ) } } );
	click( xdf, itemOf( menuOf( xdf.main, '⋯' ), 'Löschen' ) );
	click( xdf, all( xdf.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...a refusal of the delete is said in the toast and the editor stays', xdf.location.hash === '#position-mixing' && xdf.toast.classList.contains( 'bad' ) === true );

	// --- an item that is not there, the type that is not there, the account without the permission
	const xg = await xopen( '#position-weg' );
	check( 'an item that is not there says so and leads back to the table; no editor is drawn', xcards( xg ).length === 0 && textOf( xg.main ).indexOf( texts['items.gone'] ) !== -1 && all( xg.main, 'a' ).some( function( a ) { return a.getAttribute( 'href' ) === '#positionen' } ) );
	const xm = await xopen( '#positionen-neu', { 'crm-documents/positionen' : function() { return [ 200, elist( { missing : true, items : [] } ) ] } } );
	check( 'where the type is not there the editor says so and points to the settings', xcards( xm ).length === 0 && all( xm.main, 'a' ).some( function( a ) { return a.getAttribute( 'href' ) === '#einstellungen' } ) );
	const xx = await xopen( '#position-mixing', {}, { crm : true, pdf : false, elements : false } );
	check( 'a deep link without the permission draws the sentence of the screen and asks for nothing of the panel: no read of the item, no list', xx.sent().filter( function( action ) { return /^elements\//.test( action ) } ).length === 0 && xx.all( 'crm-documents/positionen' ).length === 0 && textOf( all( xx.main, '.note' )[0] ) === texts['items.forbidden'] && xcards( xx ).length === 0 );
	const xhostile = await xopen( '#position-versteckt', { 'elements/get' : function() { return [ 200, egot( { locales : { de_DE : { title : '<img src=x onerror=alert(1)>', description : '<b>x</b>', unit : '' }, en_US : {} } } ) ] } } );
	check( 'what the panel holds is a value of a field and nothing else: a title that is markup makes no element', xf( xhostile, 'f-title-de_DE' ).value === '<img src=x onerror=alert(1)>' && findAll( xhostile.main, function( node ) { return node.tagName === 'IMG' || node.tagName === 'B' } ).length === 0 );

	// --- the answers that come after the screen was left
	const xhold = hold();
	const xwait = await xopen( '#kunde-neu' );
	xwait.answers['elements/get'] = function() { return xhold.promise };
	xwait.location.hash = '#position-mixing';
	await settle();
	xwait.location.hash = '#kunde-neu';
	await settle();
	xhold.release( [ 200, egot() ] );
	await settle();
	check( 'the answer of the item that arrives after the screen was left draws nothing: the screen the address names stays', onNew( xwait ) && xcards( xwait ).length === 0 );
	const xsaveHold = hold();
	const xlateSave = await xopen( '#position-mixing', { 'elements/save' : function( data ) { eposts.push( data ); return eposts.length === 1 ? xsaveHold.promise : [ 200, { element : {} } ] } } );
	type( xf( xlateSave, 'f-title-de_DE' ), 'Mixing 3', false );
	type( xf( xlateSave, 'f-title-en_US' ), 'Mixing 3', false );
	await xsave( xlateSave );
	xlateSave.location.hash = '#kunde-neu';
	await settle();
	const xtoastBefore = xlateSave.toast.textContent;
	const xgets = xlateSave.all( 'elements/get' ).length;
	xsaveHold.release( [ 200, { element : {} } ] );
	await settle();
	check( 'a save whose answer comes late: nothing is said, nothing opens, nothing is read again - and the language that was still to go is posted, so that the item is not left half saved', onNew( xlateSave ) && xlateSave.toast.textContent === xtoastBefore && xlateSave.all( 'elements/get' ).length === xgets && eposts.length === 2 && eposts[1].locale === 'en_US' );
	const xrefHold = hold();
	const xlateRefuse = await xopen( '#position-mixing', { 'elements/save' : function() { return xrefHold.promise } } );
	type( xf( xlateRefuse, 'f-price' ), '99', false );
	await xsave( xlateRefuse );
	xlateRefuse.location.hash = '#kunde-neu';
	await settle();
	xrefHold.release( xfail( 403, 'elements_fields_not_allowed', [ 'price' ], 'price' ) );
	await settle();
	check( '...a refusal that comes late is said in the toast and marks nothing on the screen that is there now', onNew( xlateRefuse ) && xlateRefuse.toast.textContent === 'Dein Konto darf diese Felder nicht ändern: Preis netto' && all( xlateRefuse.main, '.err' ).length === 0 );
	const xdelHold = hold();
	const xlateDelete = await xopen( '#position-mixing', { 'elements/delete' : function() { return xdelHold.promise } } );
	click( xlateDelete, itemOf( menuOf( xlateDelete.main, '⋯' ), 'Löschen' ) );
	click( xlateDelete, all( xlateDelete.dialogs[0], 'button' ).pop() );
	await settle();
	xlateDelete.location.hash = '#kunde-neu';
	await settle();
	xdelHold.release( [ 200, { ok : true } ] );
	await settle();
	check( '...so does the answer of a delete: nothing opens', onNew( xlateDelete ) && xlateDelete.location.hash === '#kunde-neu' );

	// --- the words
	const xwords = [ ...new Set( [ ...script.matchAll( /'(items\.[a-z0-9-]+)'/g ) ].map( function( found ) { return found[1] } ).filter( function( key ) { return /-$/.test( key ) === false } ).concat( [ 'title', 'description', 'unit', 'price', 'vat', 'hidden', 'sort' ].map( function( name ) { return 'items.k-'+ name } ) ) ) ];
	const xmissing = xwords.filter( function( key ) { return typeof texts[key] !== 'string' || texts[key] === '' } );
	check( 'every word the screen and the editor of the Positionen ask for is a word of the page, and so are the sentences of the panel\'s code the editor shows and of the restriction', xmissing.length === 0 && xwords.length >= 40 && typeof texts['error.elements_fields_not_allowed'] === 'string' && texts['error.crm_positionen_type'].indexOf( texts['nav.items'] ) !== -1 && texts['error.crm_positionen_type'].indexOf( texts['nav.projects'] ) !== -1 );
	check( 'the editor posts to the panel\'s actions for the type and to nothing else of it: elements/get, save and delete, with the type positionen, and save and delete with the type projekte - nothing else', [ ...script.matchAll( /api\( '(elements\/[a-z]+)'/g ), ...script.matchAll( /call\( '(elements\/[a-z]+)'/g ) ].map( function( found ) { return found[1] } ).sort().join() === 'elements/delete,elements/delete,elements/get,elements/save,elements/save'
		&& script.indexOf( "POSITION_TYPE = 'positionen'" ) !== -1 && script.indexOf( "PROJECT_TYPE = 'projekte'" ) !== -1 );

	// --- C13: Strg+S in the editor of a Position
	const xk = await xopen( '#position-mixing' );
	type( xf( xk, 'f-title-de_DE' ), 'Mixing Pro', false );
	check( 'Strg+S in the editor of a Position saves it as the button does - one post of the changed language - and the button carries the shortcut', xf( xk, 'position-save' ).title === 'Strg+S' && press( xk, 's', null, { ctrlKey : true } ) === true && ( await settle(), eposts.length === 1 && eposts[0].locale === 'de_DE' ) );
	check( '...on the list of the Positionen there is no form and the key does nothing', press( await xopen( '#positionen' ), 's', null, { ctrlKey : true } ) === false );
	}

	// === C13: the company's texts are edited in the Einstellungen, and Strg+S / Cmd+S saves the form on screen
	{
	const CTRL = { ctrlKey : true };
	const META = { metaKey : true };
	const tperms = { crm : true, pdf : false, elements : false, text : true };
	const writable = function( keys ) { return keys.map( function( entry ) { return Object.assign( { writable : true }, entry ) } ) };
	const tsaves = [];
	const tanswers = function( over ) {
		return Object.assign( {
			'crm-documents/settings-get' : function() { return [ 200, eview ] },
			'crm/status' : function() { return [ 200, { items : eok } ] },
			'crm-documents/settings-save' : function( data ) { tsaves.push( [ 'settings', data ] ); return [ 200, { settings : Object.assign( {}, esettings, { vat_mode : data.vat_mode, currency : data.currency } ) } ] },
			'text/keys' : function() { return [ 200, Object.assign( {}, ecompany, { keys : writable( ecompany.keys ) } ) ] },
			'text/savebatch' : function( data ) {
				tsaves.push( [ 'text', data ] );
				const results = {};
				data.items.forEach( function( item ) { results[item.key] = { ok : true, value : item.value } } );
				return [ 200, { results : results } ];
			},
		}, over || {} );
	};
	const topen = async function( over, perms, hash ) {
		tsaves.length = 0;
		const w = world( { hash : hash ?? '#einstellungen', perms : perms === undefined ? tperms : perms, answers : tanswers( over ) } );
		w.boot();
		await settle();
		return w;
	};
	const tf = function( w, id ) { return all( w.main, '#'+ id )[0] };
	const tnote = function( w, id ) { const notes = all( tf( w, id ).parentNode, '.state-note.bad' ); return notes.length === 0 ? '' : textOf( notes[0] ) };
	const chord = function( w, mods ) { return press( w, 's', null, mods ) };
	const focus = function( w, node ) { node.focus(); w.page.activeElement = node };
	const texts_of = function( w ) { return all( w.main, '#firma dt' ).map( textOf ) };

	// --- Firmendaten
	const tf1 = await topen();
	check( 'with the Text panel\'s permission the block asks for the keys through the panel\'s action, and has a card for each language of the site - the native one first, whatever order the panel lists them in - headed by the language', tf1.sent().filter( function( a ) { return a === 'text/keys' } ).length === 1 && JSON.stringify( tf1.last( 'text/keys' ).data ) === '{}'
		&& all( tf1.main, '#firma .card' ).map( function( c ) { return c.dataset.locale } ).join() === 'de_DE,en_US' && textOf( all( tf( tf1, 'firma-lang-de_DE' ), 'h4' )[0] ).indexOf( 'Muttersprache der Website' ) !== -1
		&& textOf( all( tf( tf1, 'firma-lang-en_US' ), 'h4' )[0] ).indexOf( 'Muttersprache' ) === -1 && textOf( all( tf( tf1, 'firma-lang-en_US' ), 'h4' )[0] ) !== '' );
	check( '...the first card has the six texts in the order Firma, Beschreibung, Anschrift, Land, E-Mail, Telefon - a text of all languages once, here - and the card of another language has the ones that are per language', all( tf( tf1, 'firma-lang-de_DE' ), 'dt' ).map( textOf ).join( '|' ) === 'Firma|Beschreibung|Anschrift|Land|E-Mail|Telefon'
		&& all( tf( tf1, 'firma-lang-en_US' ), 'dt' ).map( textOf ).join( '|' ) === 'Beschreibung|Land' && tf( tf1, 'f-firma-general-name' ).value === 'Studio Nordlicht' && tf( tf1, 'f-firma-general-description-de_DE' ).value === 'Ein Tonstudio.' && tf( tf1, 'f-firma-general-description-en_US' ).value === 'A recording studio.'
		&& tf( tf1, 'f-firma-contact-country-en_US' ).value === 'Germany' && tf( tf1, 'f-firma-contact-phone' ).value === '+49 40 123456' );
	check( '...the address and the description are multiline fields, the others one line, the mail is of type email; the texts of all languages say so', tf( tf1, 'f-firma-contact-address' ).tagName === 'TEXTAREA' && tf( tf1, 'f-firma-contact-address' ).value === 'Hafenstraße 12\n20457 Hamburg' && tf( tf1, 'f-firma-general-description-de_DE' ).tagName === 'TEXTAREA' && tf( tf1, 'f-firma-general-name' ).tagName === 'INPUT'
		&& tf( tf1, 'f-firma-contact-email' ).type === 'email' && textOf( tf( tf1, 'f-firma-general-name' ).parentNode ).indexOf( texts['firma.shared'] ) !== -1 && textOf( tf( tf1, 'f-firma-general-description-de_DE' ).parentNode ).indexOf( texts['firma.shared'] ) === -1 );
	check( '...it is a form of its own beside the settings\' form, with a Speichern that carries the shortcut', tf( tf1, 'firma' ).tagName === 'FORM' && tf( tf1, 'firma' ).parentNode !== tf( tf1, 'settings-form' ) && findAll( tf( tf1, 'settings-form' ), function( n ) { return n.id === 'firma' } ).length === 0
		&& textOf( tf( tf1, 'firma-save' ) ) === 'Speichern' && tf( tf1, 'firma-save' ).title === 'Strg+S' );

	const tfEntities = await topen( { 'text/keys' : function() { return [ 200, Object.assign( {}, ecompany, { keys : writable( ecompany.keys.map( function( entry ) { return entry.key === '/project/company/general/name' ? Object.assign( {}, entry, { values : { '*' : 'Atelier &quot;Nord&quot; &#91;x] &amp; Co &amp;quot; &lt;b&gt; &#xZZ; &#1114112;' } } ) : entry } ) ) } ) ] } } );
	check( 'the field of the company shows a stored text as the person reads it: the entities of a quote, a bracket, an ampersand and the signs of markup are those signs - once, so that &amp;quot; is &quot; - and one that is no sign stays as it is; saving it unchanged posts nothing',
		tf( tfEntities, 'f-firma-general-name' ).value === 'Atelier "Nord" [x] & Co &quot; <b> &#xZZ; &#1114112;' && ( submit( tfEntities, tf( tfEntities, 'firma' ) ), await settle(), tfEntities.all( 'text/savebatch' ).length === 0 ) );
	submit( tf1, tf( tf1, 'firma' ) );
	await settle();
	check( 'Speichern with nothing changed posts nothing', tf1.all( 'text/savebatch' ).length === 0 );
	type( tf( tf1, 'f-firma-general-name' ), 'Nordlicht Audio', false );
	type( tf( tf1, 'f-firma-contact-phone' ), '+49 40 999', false );
	type( tf( tf1, 'f-firma-general-description-en_US' ), 'A studio.', false );
	type( tf( tf1, 'f-firma-contact-email' ), 'hallo@studio.example', false );
	check( 'what is typed is unsaved input: the guard of the page holds it', tf1.app.state.draft !== null && tf1.app.state.draft.dirty === true && tf1.app.state.draft !== tf1.app.state.saver );
	submit( tf1, tf( tf1, 'firma' ) );
	await settle();
	const batches = tf1.all( 'text/savebatch' ).map( function( c ) { return c.data.items } );
	check( 'a save posts the changed keys only, with the locales that changed - the texts of all languages (locale *) with the first language that changed, then each other language that changed - in the batches the panel takes (key, locale, value), the key as the wizard writes it; an unchanged text (the mail, typed back to what it was) is not posted',
		JSON.stringify( batches ) === JSON.stringify( [ [ { key : '/project/company/general/name', locale : '*', value : 'Nordlicht Audio' }, { key : '/project/company/contact/phone', locale : '*', value : '+49 40 999' }, { key : '/project/company/general/description', locale : 'en_US', value : 'A studio.' } ] ] ) );
	check( '...the toast says it, the guard holds nothing, and a second save posts nothing', tf1.toast.textContent === texts['firma.saved'] && ( tf1.app.state.draft === null || tf1.app.state.draft.dirty !== true ) && ( submit( tf1, tf( tf1, 'firma' ) ), await settle(), tf1.all( 'text/savebatch' ).length === 1 ) );

	// two languages changed: one batch each, since the panel answers one result to a key
	const tf2 = await topen();
	type( tf( tf2, 'f-firma-general-description-de_DE' ), 'Neu de', false );
	type( tf( tf2, 'f-firma-general-description-en_US' ), 'New en', false );
	type( tf( tf2, 'f-firma-general-name' ), 'Neu', false );
	submit( tf2, tf( tf2, 'firma' ) );
	await settle();
	check( 'a key changed in two languages is two batches - the native language first, with the text of all languages - and each has one item for the key', tf2.all( 'text/savebatch' ).length === 2 && JSON.stringify( tf2.all( 'text/savebatch' ).map( function( c ) { return c.data.items.map( function( i ) { return i.locale+ ':'+ i.value } ) } ) ) === '[["*:Neu","de_DE:Neu de"],["en_US:New en"]]' );

	// a refusal beside the field
	const tf3 = await topen( { 'text/savebatch' : function( data ) {
		tsaves.push( [ 'text', data ] );
		return [ 200, { results : { '/project/company/general/name' : { ok : false, error : 'not allowed' }, '/project/company/contact/phone' : { ok : true, value : '1' }, '/project/company/contact/country' : { ok : false, error : 'invalid locale' } } } ];
	} } );
	type( tf( tf3, 'f-firma-general-name' ), 'X', false );
	type( tf( tf3, 'f-firma-contact-phone' ), '1', false );
	type( tf( tf3, 'f-firma-contact-country-de_DE' ), 'Y', false );
	submit( tf3, tf( tf3, 'firma' ) );
	await settle();
	check( 'a key the panel refuses is marked beside its own field with a sentence - the one for a key the account may not write, the panel\'s own failure sentence for the others - and focused, while the ones it wrote stay written', tf( tf3, 'f-firma-general-name' ).classList.contains( 'err' ) === true && tnote( tf3, 'f-firma-general-name' ) === texts['firma.error-denied']
		&& tnote( tf3, 'f-firma-contact-country-de_DE' ) === texts['text.error-save'] && texts['text.error-save'] !== '' && tf( tf3, 'f-firma-contact-phone' ).classList.contains( 'err' ) === false && tnote( tf3, 'f-firma-contact-phone' ) === '' && tf3.toast.classList.contains( 'bad' ) === true && tf( tf3, 'firma-save' ).disabled === false );
	submit( tf3, tf( tf3, 'firma' ) );
	await settle();
	check( '...the next save posts what is still unsaved and not what was written', JSON.stringify( tf3.all( 'text/savebatch' ).pop().data.items.map( function( i ) { return i.key } ) ) === '["/project/company/general/name","/project/company/contact/country"]' );
	const tf4 = await topen( { 'text/savebatch' : function() { return [ 500, { error : 'x' } ] } } );
	type( tf( tf4, 'f-firma-general-name' ), 'X', false );
	submit( tf4, tf( tf4, 'firma' ) );
	await settle();
	check( 'a refusal of the whole request is a toast with the panel\'s sentence, and the save can be tried again', tf4.toast.classList.contains( 'bad' ) === true && tf4.toast.textContent === texts['text.error-save'] && tf( tf4, 'firma-save' ).disabled === false && tf4.app.state.draft.dirty === true );

	// a key the account may not write is shown and not offered
	const tf5 = await topen( { 'text/keys' : function() { return [ 200, Object.assign( {}, ecompany, { keys : ecompany.keys.map( function( e ) { return Object.assign( { writable : e.key.indexOf( 'general/name' ) === -1 }, e ) } ) } ) ] } } );
	type( tf( tf5, 'f-firma-general-name' ), 'X', true );
	type( tf( tf5, 'f-firma-contact-phone' ), '1', false );
	submit( tf5, tf( tf5, 'firma' ) );
	await settle();
	check( 'a key the panel says is not writable for the account is a read-only field and is never posted', tf( tf5, 'f-firma-general-name' ).getAttribute( 'readonly' ) === 'readonly' && tf( tf5, 'f-firma-contact-phone' ).getAttribute( 'readonly' ) === null && JSON.stringify( tf5.last( 'text/savebatch' ).data.items.map( function( i ) { return i.key } ) ) === '["/project/company/contact/phone"]' );
	check( '...and it looks read-only: the field carries the class of the muted look, an editable one does not', tf( tf5, 'f-firma-general-name' ).classList.contains( 'ro' ) === true && tf( tf5, 'f-firma-contact-phone' ).classList.contains( 'ro' ) === false );

	// what is typed survives the drawing of the screen after the settings were saved
	const tf6 = await topen();
	type( tf( tf6, 'f-firma-general-name' ), 'Halb getippt', false );
	type( tf( tf6, 'f-currency' ), 'CHF', false );
	submit( tf6, tf( tf6, 'settings-form' ) );
	await settle();
	check( 'the screen is drawn again after the settings were saved: the texts that are typed in Firmendaten are still there and still unsaved, the keys are not asked for a second time, and the guard still holds them', tf( tf6, 'f-firma-general-name' ).value === 'Halb getippt' && tf6.all( 'text/keys' ).length === 1 && tf6.app.state.draft !== null && tf6.app.state.draft.dirty === true && tf6.all( 'text/savebatch' ).length === 0 );

	// without the permission
	const tr = await topen( {}, { crm : true, pdf : false, elements : false, text : false } );
	check( 'without the permission of the Text panel the block shows the values read only - from the CRM\'s own read in settings-get - with the sentence that says what is missing; no field, no Speichern, and the panel\'s actions are not asked', tr.sent().indexOf( 'text/keys' ) === -1 && all( tf( tr, 'firma' ), 'input' ).length === 0 && all( tf( tr, 'firma' ), 'textarea' ).length === 0 && tf( tr, 'firma-save' ) === undefined
		&& textOf( tf( tr, 'firma-readonly' ) ) === texts['firma.readonly'] && texts['firma.readonly'].indexOf( 'Berechtigung des Panels Texte' ) !== -1 && texts_of( tr ).join( '|' ) === 'Firma|Beschreibung|Anschrift|Land|E-Mail|Telefon|Beschreibung|Land'
		&& all( tf( tr, 'firma' ), 'dd' ).map( textOf ).indexOf( 'Studio Nordlicht' ) !== -1 && tr.app.state.perms.text === false );
	check( 'the read-only block keeps the lines of a value: the two-line address is two text nodes with a br between, nothing built from markup', ( function() {
		const dd = all( tf( tr, 'firma' ), 'dd' ).find( function( one ) { return textOf( one ).indexOf( 'Hafenstraße 12' ) === 0 } );
		return dd !== undefined && findAll( dd, function( n ) { return n.tagName === 'BR' } ).length === 1 && textOf( dd ) === 'Hafenstraße 1220457 Hamburg';
	} )() );
	check( '...and Strg+S does nothing there but what the settings form does', ( await ( async function() { focus( tr, tf( tr, 'f-currency' ) ); tr.calls.length = 0; chord( tr, CTRL ); await settle(); return tr.sent()[0] } )() ) === 'crm-documents/settings-save' );
	const tmarkup = await topen( { 'text/keys' : function() { return [ 200, { native : 'de_DE', locales : [ { locale : 'de_DE', active : true } ], keys : writable( [ { key : '/project/company/general/name', global : true, values : { '*' : '<img src=x onerror=alert(1)>' } } ] ) } ] } } );
	check( 'a text with markup is text in the field, and a language with no key to show has no card; a site with no key of the company says so', tf( tmarkup, 'f-firma-general-name' ).value === '<img src=x onerror=alert(1)>' && findAll( tmarkup.main, function( n ) { return n.tagName === 'IMG' } ).length === 0 && all( tmarkup.main, '#firma .card' ).length === 1
		&& textOf( tf( await topen( { 'text/keys' : function() { return [ 200, { native : 'de_DE', locales : [ { locale : 'de_DE', active : true } ], keys : [] } ] } } ), 'firma' ) ).indexOf( texts['firma.none'] ) !== -1 );
	const tfail = await topen( { 'text/keys' : function() { return [ 500, { error : 'x' } ] } } );
	check( 'where the keys cannot be read the block says so and offers to try again, which asks again', tf( tfail, 'firma-retry' ) !== undefined && textOf( tf( tfail, 'firma-holder' ) ).indexOf( texts['text.error-load'] ) !== -1 && ( click( tfail, tf( tfail, 'firma-retry' ) ), await settle(), tfail.all( 'text/keys' ).length === 2 ) );

	// --- Strg+S: the shell
	const tsAnswers = tanswers( {
		'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
	} );
	const tov = world( { hash : '#uebersicht', perms : tperms, answers : tsAnswers } );
	tov.boot();
	await settle();
	const before = tov.calls.length;
	check( 'on a screen without a form Strg+S and Cmd+S do nothing: nothing is posted and the key is left to the browser', chord( tov, CTRL ) === false && chord( tov, META ) === false && tov.calls.length === before );
	const te = await topen();
	type( tf( te, 'f-currency' ), 'CHF', false );
	focus( te, tf( te, 'f-currency' ) );
	tsaves.length = 0;
	check( 'with Alt or Shift, or without Ctrl and Cmd, or another letter, the key saves nothing and is left alone', [ { ctrlKey : true, altKey : true }, { ctrlKey : true, shiftKey : true }, { metaKey : true, shiftKey : true } ].every( function( mods ) { return chord( te, mods ) === false } )
		&& press( te, 's', null, {} ) === false && press( te, 'd', null, CTRL ) === false && te.calls.filter( function( c ) { return c.action === 'crm-documents/settings-save' || c.action === 'text/savebatch' } ).length === 0 );
	check( 'Strg+S and Cmd+S, in either case of the letter, save the form that is open and keep the browser\'s own save away', chord( te, CTRL ) === true && ( await settle(), te.all( 'crm-documents/settings-save' ).length === 1 ) );
	type( tf( te, 'f-currency' ), 'GBP', false );
	check( '...Cmd+S the same, and the capital letter too', press( te, 'S', null, META ) === true && ( await settle(), te.all( 'crm-documents/settings-save' ).length === 2 ) );

	// a question stands over the page
	const tdlg = await topen();
	type( tf( tdlg, 'f-currency' ), 'CHF', false );
	click( tdlg, all( tdlg.nav, '[data-r]' )[0] );
	const tdlgCalls = tdlg.calls.length;
	check( 'while a question stands over the page (the guard asks to leave) the key saves nothing, and is not kept from the browser', tdlg.dialogs.length === 1 && tdlg.dialogs[0].open === true && chord( tdlg, CTRL ) === false && tdlg.calls.length === tdlgCalls );

	// the screens, one by one: the form on screen is saved
	const tl = await topen();
	focus( tl, tf( tl, 'f-currency' ) );
	check( 'Einstellungen: with the focus in the settings\' form that form is saved - and not the Firmendaten, which are not changed either', chord( tl, CTRL ) === true && ( await settle(), tsaves.map( function( one ) { return one[0] } ).join() === 'settings' ) );
	const tm = await topen();
	type( tf( tm, 'f-firma-general-name' ), 'Fokus', false );
	type( tf( tm, 'f-currency' ), 'CHF', false );
	focus( tm, tf( tm, 'f-firma-general-name' ) );
	chord( tm, CTRL );
	await settle();
	check( '...with the focus in a field of Firmendaten that form is saved, though the settings are changed as well', tsaves.map( function( one ) { return one[0] } ).join() === 'text' );
	const tn = await topen();
	type( tf( tn, 'f-firma-general-name' ), 'Nur dieser', false );
	focus( tn, tf( tn, 'settings-save' ) );
	tn.page.activeElement = null;
	chord( tn, CTRL );
	await settle();
	check( '...with no focus in either, the form that holds changes is saved - here Firmendaten - and with changes in both the first, the settings', tsaves.map( function( one ) { return one[0] } ).join() === 'text' && ( tsaves.length = 0, type( tf( tn, 'f-currency' ), 'CHF', false ), type( tf( tn, 'f-firma-general-name' ), 'Nochmal', false ), tn.page.activeElement = null, chord( tn, CTRL ), await settle(), tsaves.map( function( one ) { return one[0] } ).join() === 'settings' ) );
	const tp = await topen();
	check( '...and with nothing changed in either and no focus, nothing is saved and the browser keeps its key', ( tp.page.activeElement = null, chord( tp, CTRL ) === false ) && ( await settle(), tsaves.length === 0 ) );

	// Entwurf: the explicit save; a running save is not doubled
	const heldSave = hold();
	const td = await editing( {}, { 'crm-documents/save' : function() { return heldSave.promise } } );
	chord( td, CTRL );
	chord( td, META );
	await settle();
	check( 'Entwurf: Strg+S is the button Speichern - one post of the draft - and a second press while it runs is no second post, though the key is still kept from the browser', td.all( 'crm-documents/save' ).length === 1 && field( td, 'save' ).title === 'Strg+S' );
	heldSave.release( [ 200, { document : full( Object.assign( {}, draftDoc, { updated : '2026-11-03T14:30:00Z' } ) ) } ] );
	await settle();
	chord( td, CTRL );
	await settle();
	check( '...and once it is through the key saves again', td.all( 'crm-documents/save' ).length === 2 );
	td.location.hash = '#uebersicht';
	await settle();
	check( 'a screen that is left has no save any more: on the Übersicht the key does nothing', chord( td, CTRL ) === false );

	// Beleg: the notes
	const tnotes = await stepper( { state : 'open' } );
	await tabClick( tnotes, 'Notizen' );
	type( all( body( tnotes ), '#notes' )[0], 'Per Tastatur', false );
	check( 'Beleg, Notizen: Strg+S saves the notes as the button does, and the button carries the shortcut', all( body( tnotes ), '#notes-save' )[0].title === 'Strg+S' && chord( tnotes, CTRL ) === true && ( await settle(), JSON.stringify( tnotes.steps[0] ) === '["save",{"id":"11","notes":"Per Tastatur"}]' ) );
	const tn2 = await stepper( { state : 'open' } );
	await tabClick( tn2, 'Notizen' );
	type( all( body( tn2 ), '#notes' )[0], 'Verworfen', false );
	await tabClick( tn2, 'Verlauf' );
	tn2.page.activeElement = null;
	check( 'Beleg: notes that were typed on a tab that is left are no form on screen - Strg+S on another tab saves nothing and leaves the key to the browser', chord( tn2, CTRL ) === false && tn2.steps.length === 0 );
	const tn3 = await stepper( { state : 'open' } );
	await tabClick( tn3, 'Notizen' );
	focus( tn3, all( body( tn3 ), '#notes' )[0] );
	check( '...and unchanged notes, with the focus in the field, are nothing to save: nothing is posted and the key stays with the browser', chord( tn3, CTRL ) === false && tn3.steps.length === 0 );
	const tdoc = await opened( { id : 11 } );
	check( '...and a Beleg on another tab has no form: the key is left alone', chord( tdoc, CTRL ) === false );

	// Kundenkarte: Stammdaten, Notizen, the invitation - the form that holds the focus, else the one that is dirty
	const csaves = [];
	const kk = await kopen( '#kunde-1-notizen', { 'crm-clients/save' : function( data ) { csaves.push( data ); return [ 200, { client : Object.assign( {}, kclient, data ) } ] }, 'crm-clients/invite' : function( data ) { csaves.push( { invite : data } ); return [ 200, { account : Object.assign( {}, kaccounts[0], { id : 'cccccccccccc' } ), mailed : true } ] } } );
	chord( kk, CTRL );
	await settle();
	check( 'Kundenkarte: with the Notizen open and nothing typed the key saves nothing and is left to the browser', csaves.length === 0 && chord( kk, CTRL ) === false );
	click( kk, kitem( kmenu( kk, '⋯' ), 'Stammdaten bearbeiten' ) );
	click( kk, all( kk.main, '#invite-open' )[0] );
	const kmaster = all( kk.main, '#master' )[0];
	type( all( kmaster, '#f-city' )[0], 'Lübeck', false );
	type( all( kk.main, '#notes' )[0], 'Notiert', false );
	type( all( kk.main, '#f-invite-email' )[0], 'neu@example.org', false );
	focus( kk, all( kk.main, '#notes' )[0] );
	check( '...with the focus in the Notizen it is the notes that are saved - the other forms hold changes as well', chord( kk, CTRL ) === true && ( await settle(), csaves.length === 1 && csaves[0].notes === 'Notiert' && csaves[0].city === 'Hamburg' ) );
	focus( kk, all( kk.main, '#f-invite-email' )[0] );
	chord( kk, META );
	await settle();
	check( '...with the focus in the invitation it is the invitation', csaves.length === 2 && csaves[1].invite !== undefined && csaves[1].invite.email === 'neu@example.org' );
	const kk2 = await kopen( '#kunde-1', { 'crm-clients/save' : function( data ) { csaves.push( data ); return [ 200, { client : Object.assign( {}, kclient, data ) } ] } } );
	csaves.length = 0;
	click( kk2, kitem( kmenu( kk2, '⋯' ), 'Stammdaten bearbeiten' ) );
	kk2.page.activeElement = null;
	check( 'Kundenkarte: the Stammdaten form open and nothing typed: nothing is saved', chord( kk2, CTRL ) === false && csaves.length === 0 );
	type( all( all( kk2.main, '#master' )[0], '#f-city' )[0], 'Kiel', false );
	kk2.page.activeElement = null;
	check( '...typed, and no focus in any form: the one that holds changes is saved, with every field of the client', chord( kk2, CTRL ) === true && ( await settle(), csaves.length === 1 && csaves[0].city === 'Kiel' && csaves[0].company === kclient.company ) );
	check( '...and after it the card is the facts again and the key has nothing to save', all( kk2.main, '#client-save' ).length === 0 && chord( kk2, CTRL ) === false );

	// a tab, a closed form and a screen that is loading hold no save
	const kt2 = await kopen( '#kunde-1-notizen' );
	type( all( kt2.main, '#notes' )[0], 'Nur im Tab', false );
	click( kt2, all( kt2.main, '.tabs button' )[0] );
	kt2.page.activeElement = null;
	check( 'Kundenkarte: the Notizen that were typed in a tab that is left are no form on screen: the key saves nothing', chord( kt2, CTRL ) === false && kt2.all( 'crm-clients/save' ).length === 0 );
	const kt3 = await kopen( '#kunde-1' );
	click( kt3, kitem( kmenu( kt3, '⋯' ), 'Stammdaten bearbeiten' ) );
	type( all( kt3.main, '#f-city' )[0], 'Weg', false );
	click( kt3, all( kt3.main, '#client-cancel' )[0] );
	kt3.page.activeElement = null;
	check( '...and neither is the Stammdaten form that was closed with Abbrechen', chord( kt3, CTRL ) === false && kt3.all( 'crm-clients/save' ).length === 0 );
	const kt4 = await kopen( '#kunde-1' );
	click( kt4, all( kt4.main, '#invite-open' )[0] );
	type( all( kt4.main, '#f-invite-email' )[0], 'weg@example.org', false );
	click( kt4, all( kt4.main, '#invite-cancel' )[0] );
	kt4.page.activeElement = null;
	check( '...nor the invitation that was closed', chord( kt4, CTRL ) === false && kt4.all( 'crm-clients/invite' ).length === 0 );
	const tload = hold();
	const tlw = await editing( {}, { 'crm-clients/list' : function() { return tload.promise } } );
	tlw.location.hash = '#kunden';
	await settle();
	check( '...nor a screen that is loading: the draft that was left has no save any more', chord( tlw, CTRL ) === false && tlw.all( 'crm-documents/save' ).length === 0 );
	tload.release( [ 200, { clients : kclients } ] );
	await settle();

	// Neuer Kunde
	const created = [];
	const kn = await kopen( '#kunde-neu', { 'crm-clients/save' : function( data ) { created.push( data ); return [ 200, { client : Object.assign( {}, kclient, { id : 9, number : 'K-0009' } ) } ] } } );
	type( all( kn.main, '#f-company' )[0], 'Neu GmbH', false );
	chord( kn, CTRL );
	await settle();
	check( 'Neuer Kunde: Strg+S creates the client as the button does, with the box of the mail as it stands - ticked at first', created.length === 1 && created[0].company === 'Neu GmbH' && kn.location.hash === '#kunde-9' && created[0].notify === true );
	const kn2 = await kopen( '#kunde-neu', { 'crm-clients/save' : function( data ) { created.push( data ); return [ 200, { client : Object.assign( {}, kclient, { id : 9, number : 'K-0009' } ) } ] } } );
	type( all( kn2.main, '#f-company' )[0], 'Still GmbH', false );
	all( kn2.main, '#f-account_notify' )[0].checked = false;
	chord( kn2, CTRL );
	await settle();
	check( '...and with the box unticked Strg+S posts notify false', created.length === 2 && created[1].notify === false );

	// the shortcut on every Speichern
	const titled = [];
	const tkn = await kopen( '#kunde-neu' );
	titled.push( [ 'client-create', all( tkn.main, '#client-create' )[0] ] );
	const tkc = await kopen( '#kunde-1' );
	click( tkc, kitem( kmenu( tkc, '⋯' ), 'Stammdaten bearbeiten' ) );
	click( tkc, all( tkc.main, '#invite-open' )[0] );
	titled.push( [ 'client-save', all( tkc.main, '#client-save' )[0] ], [ 'invite-send', all( tkc.main, '#invite-send' )[0] ] );
	titled.push( [ 'notes-save', all( ( await kopen( '#kunde-1-notizen' ) ).main, '#notes-save' )[0] ], [ 'settings-save', tf( tp, 'settings-save' ) ], [ 'firma-save', tf( tp, 'firma-save' ) ], [ 'save', field( await editing(), 'save' ) ] );
	check( 'every Speichern of the editors carries title="Strg+S": the draft, the Stammdaten, the invitation, the notes, the new client, the settings and the Firmendaten', titled.length === 7 && titled.every( function( pair ) { return pair[1] !== undefined && pair[1].title === 'Strg+S' } ) && texts['save.shortcut'] === 'Strg+S' );
	check( 'the script carries no Speichern without it: every button made with one of the words of the save has a title', ( script.match( /id: '[a-z-]*(?:save|create|send)[a-z-]*', (?:title: t\( 'save\.shortcut' \), )?text: t\( '/g ) || [] ).every( function( found ) { return found.indexOf( 'title: t( \'save.shortcut\' )' ) !== -1 || /id: 'invite-cancel|client-cancel|up-send/.test( found ) } ) );
	}

	// === C16: Projekte - the element type, the project of a document, the project's folder of files, and the screens
	// the records of a consent and of its revocation, as the surface reads them (Einwilligungen)
	const c14_consent = function( over ) { return Object.assign( { id : 31, kind : 'consent', client_id : 1, member_id : 'aaaaaaaaaaaa', member_email : 'lena@nordlicht.example', member_name : 'Lena <b>Brandt</b>', document_id : 12, project : '00001', sha256 : 'd'.repeat( 64 ), shown : 'Ich willige <b>ein</b>.\n[crm-client]', at : '2026-11-03T10:15:00Z', ip : '203.0.113.7', agent : 'Browser/1.0', purpose : 'social', parent : null, revoked : null }, over || {} ) };
	const c14_revocation = { id : 32, kind : 'revocation', client_id : 1, member_id : 'aaaaaaaaaaaa', member_email : 'lena@nordlicht.example', member_name : 'Lena <b>Brandt</b>', document_id : null, project : '00001', sha256 : 'd'.repeat( 64 ), shown : 'Ich willige <b>ein</b>.\n[crm-client]', at : '2026-11-05T08:00:00Z', ip : '203.0.113.8', agent : 'Browser/2.0', purpose : 'social', parent : 31 };

	{
	const jperms = { crm : true, pdf : false, elements : true, text : false, files : true };
	const jclient1 = { id : 1, number : 'K-0001', title : 'Nordlicht Records GmbH', active : true, active_accounts : 2, signed_accounts : 1 };
	const jrow = function( over ) {
		return Object.assign( { id : '00001', title : 'Album 2027', client : 'K-0001', description : 'Aufnahme\nund Mix', started : '2026-10-01', done : false, folder : 'f00000000001', documents : 3, open : 1, client_id : 1, client_title : 'Nordlicht Records GmbH', client_active : true, files : 1, download : false, download_at : '', approval : null }, over || {} );
	};
	const jprojects = [
		jrow( { id : '00003', title : 'Podcast', client : 'K-0002', client_id : 2, client_title : 'Jonas Richter', description : '', started : '', folder : '', documents : 0, open : 0, files : 0 } ),
		jrow( { id : '00002', title : 'Webseite', started : '2026-05-01', done : true, folder : 'f00000000002', documents : 4, open : 0, files : 2 } ),
		jrow(),
	];
	const jfiles = [
		{ id : 'a00000000001', name : 'Notizen.txt', ext : 'txt', size : 1140004, mime : 'text/plain', created : '2026-11-03T10:00:00Z', visible : true, by : { kind : 'owner', label : '' }, url : '/.files/a00000000001', title : 'Notizen', version : '', versions : 1, group : 0, comments : null },
		{ id : 'a00000000002', name : '<b>Cover</b>.png', ext : 'png', size : 52000, mime : 'image/png', created : '2026-11-02T09:00:00Z', visible : false, by : { kind : 'member', label : 'Lena Brandt' }, url : '/.files/a00000000002', title : '<b>Cover</b>', version : '', versions : 1, group : 1, comments : null },
	];
	let jpermsNow = jperms;
	const jlist = function( over ) { return Object.assign( { missing : false, native : 'de_DE', files : true, allowed : jpermsNow.files === true, projects : jprojects }, over || {} ) };
	const jget = function( over ) {
		return JSON.parse( JSON.stringify( Object.assign( { native : 'de_DE', project : jrow(), client : jclient1, files : true, allowed : jpermsNow.files === true, folder : { id : 'f00000000001', name : 'Album 2027' }, list : jfiles, review : false }, over || {} ) ) );
	};
	const jposts = [];
	let jnext = 5;
	const janswers = function( over ) {
		return Object.assign( {
			'crm/status' : function() { return [ 200, { first_run : false, items : [] } ] },
			'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
			'crm-projekte/list' : function( data ) { return [ 200, jlist( data.client === undefined ? {} : { projects : jprojects.filter( function( one ) { return String( one.client_id ) === data.client } ) } ) ] },
			'crm-projekte/get' : function( data ) { return data.id === '00001' ? [ 200, jget() ] : [ 404, { error : 'x', code : 'crm_projekte_unknown', params : [], field : 'id' } ] },
			'crm-clients/list' : function() { return [ 200, { clients : kclients } ] },
			'crm-clients/get' : function( data ) { return [ 200, { client : kclients.find( function( one ) { return String( one.id ) === data.id } ) || kclient, accounts : kaccounts } ] },
			'crm-documents/list' : function( data ) { return [ 200, { documents : data.client !== undefined ? kdocs : data.project === undefined ? [] : [ row( { id : 11, project : data.project, project_title : 'Album 2027', state : 'sent' } ), row( { id : 31, category : 'offer', number : 'A-2026-0030', state : 'open', gross : 50000, project : data.project, project_title : 'Album 2027' } ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] },
			'elements/save' : function( data ) { jposts.push( [ 'elements/save', data ] ); return [ 200, { element : { '.uri' : '/projekte/'+ ( data.uri === '' ? String( jnext++ ).padStart( 5, '0' ) : data.uri ) } } ] },
			'elements/delete' : function( data ) { jposts.push( [ 'elements/delete', data ] ); return [ 200, {} ] },
			'files/folder/add' : function( data ) { jposts.push( [ 'files/folder/add', data ] ); return [ 200, { folder : { id : 'f00000000099', group : data.group, name : data.name } } ] },
			'files/folder/save' : function( data ) { jposts.push( [ 'files/folder/save', data ] ); return [ 200, { folder : { id : data.id, name : data.name } } ] },
			'files/list' : function() { return [ 200, { groups : [ 'k-0001' ], group : 'k-0001', folders : [], files : [], types : [ 'txt' ], limits : { chunk : 1000, max : 5000, php : 0, quota : 0, used : 0 } } ] },
			'files/save' : function( data ) { jposts.push( [ 'files/save', data ] ); return [ 200, { file : {}, mailed : data.notify === true && data.visible === true ? 2 : 0 } ] },
			'files/delete' : function( data ) { jposts.push( [ 'files/delete', data ] ); return [ 200, {} ] },
			'files/remove' : function( data ) { jposts.push( [ 'files/remove', data ] ); return [ 200, { file : {}, changed : true } ] },
			'files/upload' : function( data ) { return [ 200, data.index + 1 < data.count ? { upload : '0123456789abcdef', index : data.index } : { id : 'b00000000001', name : data.name, size : data.size, mailed : data.notify === true ? 2 : 0 } ] },
			'files/notify' : function( data ) { jposts.push( [ 'files/notify', data ] ); return [ 200, { mailed : 2 } ] },
		}, over || {} );
	};
	const jopen = async function( hash, over, perms ) {
		jposts.length = 0;
		jnext = 5;
		jpermsNow = perms === undefined ? jperms : perms;
		const w = world( { hash : hash, answers : janswers( over ), perms : perms === undefined ? jperms : perms } );
		w.boot();
		await settle();
		return w;
	};
	const jf = function( w, id ) { return all( w.main, '#'+ id )[0] };
	const jrows = function( w ) { return all( jf( w, 'projects' ), 'tbody tr' ) };
	const jmenu = function( w, label ) { return all( w.main, '.menu' ).find( function( one ) { return textOf( all( one, 'button' )[0] ) === label } ) };
	const jitem = function( w, label, menuLabel ) { return all( jmenu( w, menuLabel ?? '⋯' ), '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === label } ) };
	const jsubmit = function( form ) { fire( form, 'submit', { target : form, preventDefault : function() {} } ) };
	const jtoggle = function( input, checked ) { input.checked = checked; fire( input, 'change', { target : input } ) };
	const jyes = function( w, index ) { click( w, all( w.dialogs[index], 'button' ).pop() ) };
	const jtab = function( w, name ) { return all( w.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === name } ) };

	// --- the router
	const jr = await jopen( '#projekte' );
	check( 'the hashes name the screens: #projekte the list, #projekte-neu a new project, #projekte-neu-k<id> with the client chosen, #projekt-<id> the card and #projekt-<id>-belege|dateien its tabs; what is no id is the Übersicht', JSON.stringify( jr.app.parse( '#projekte' ) ) === '{"screen":"projekte"}' && JSON.stringify( jr.app.parse( '#projekte-neu' ) ) === '{"screen":"projektneu","client":""}'
		&& JSON.stringify( jr.app.parse( '#projekte-neu-k3' ) ) === '{"screen":"projektneu","client":"3"}' && JSON.stringify( jr.app.parse( '#projekt-00001' ) ) === '{"screen":"projekt","id":"00001","tab":""}' && JSON.stringify( jr.app.parse( '#projekt-00001-dateien' ) ) === '{"screen":"projekt","id":"00001","tab":"dateien"}'
		&& jr.app.parse( '#projekt-00001-belege' ).tab === 'belege' && [ '#projekt-', '#projekt-a/b', '#projekt-00001-nichts', '#projekte-neu-k0', '#projekte-neu-x' ].every( function( hash ) { return jr.app.parse( hash ).screen === 'uebersicht' } ) );
	check( '...the address of a draft takes the project: #neu-invoice-k1-p00001; a project needs a client to go with', JSON.stringify( jr.app.parse( '#neu-invoice-k1-p00001' ) ) === '{"screen":"entwurf","category":"invoice","offer":"","client":"1","project":"00001"}' && jr.app.parse( '#neu-invoice-p00001' ).screen === 'uebersicht' && jr.app.parse( '#neu-invoice-k1-p' ).screen === 'uebersicht' && jr.app.parse( '#kunde-1-projekte' ).tab === 'projekte' );
	check( '...and the rail marks Projekte on the list, the new project and the card', await ( async function() {
		const marks = [];
		for( const hash of [ '#projekte', '#projekte-neu', '#projekt-00001' ] )
			marks.push( all( ( await jopen( hash ) ).nav, '[data-r]' ).filter( function( a ) { return a.classList.contains( 'on' ) } ).map( function( a ) { return a.getAttribute( 'data-r' ) } ).join() );
		return marks.join( '|' ) === 'projekte|projekte|projekte';
	} )() );

	// --- the list
	check( 'the list asks crm-projekte/list once and nothing else; the head says Projekte, how many of how many, the search, the switch and Neues Projekt', jr.all( 'crm-projekte/list' ).length === 1 && JSON.stringify( jr.last( 'crm-projekte/list' ).data ) === '{}' && textOf( all( jr.main, 'h1' )[0] ) === 'Projekte' && textOf( all( jr.main, '.sub' )[0] ) === '2 von 3'
		&& jf( jr, 'q' ) !== undefined && jf( jr, 'p-all' ).type === 'checkbox' && jf( jr, 'p-all' ).getAttribute( 'role' ) === 'switch' && jf( jr, 'p-all' ).checked === false && textOf( jf( jr, 'projects-new' ) ) === texts['projects.new'] && jf( jr, 'projects-new' ).getAttribute( 'href' ) === '#projekte-neu' && jf( jr, 'projects-new' ).classList.contains( 'primary' ) === true );
	check( '...the table has the columns Projekt, Kunde, Beginn, Belege, Dateien, Kommentare offen and Status; the done projects are not in it unless the switch is on - the newest first', all( jf( jr, 'projects' ), 'thead th' ).map( textOf ).join( '|' ) === [ texts['projects.column-project'], texts['column.client'], texts['projects.column-started'], texts['projects.column-documents'], texts['projects.column-files'], texts['projects.column-comments'], texts['column.state'] ].join( '|' ) && jrows( jr ).map( function( tr ) { return tr.dataset.id } ).join() === '00003,00001' );
	const j1 = jrows( jr )[1];
	check( '...a row has the title as the link to the card, the client as the link to its card, the day, the documents with the offers that wait, the files and the state', all( j1, 'a' )[0].getAttribute( 'href' ) === '#projekt-00001' && textOf( all( j1, 'a' )[0] ) === 'Album 2027' && all( j1, 'a' )[1].getAttribute( 'href' ) === '#kunde-1' && textOf( all( j1, 'a' )[1] ) === 'Nordlicht Records GmbH'
		&& j1.children.map( textOf ).slice( 2 ).join( '|' ) === '01.10.2026|3 · '+ texts['projects.open-offers'].replace( '%s', '1' )+ '|1|–|'+ texts['projects.running'] && textOf( jrows( jr )[0].children[3] ) === '–' && textOf( jrows( jr )[0].children[2] ) === '' );
	jtoggle( jf( jr, 'p-all' ), true );
	check( '...the switch Abgeschlossene zeigen draws the done ones too - greyed, with their state - without asking the server again', jrows( jr ).map( function( tr ) { return tr.dataset.id } ).join() === '00003,00002,00001' && jrows( jr )[1].classList.contains( 'dim' ) === true && textOf( jrows( jr )[1].children[6] ) === texts['projects.done'] && jr.all( 'crm-projekte/list' ).length === 1 && textOf( all( jr.main, '.sub' )[0] ) === '3 von 3' );
	type( jf( jr, 'q' ), 'richter', false );
	check( '...the search finds by the title, the client and its number, and says so when nothing is found', jrows( jr ).map( function( tr ) { return tr.dataset.id } ).join() === '00003' && ( type( jf( jr, 'q' ), 'K-0001', false ), jrows( jr ).length === 2 ) && ( type( jf( jr, 'q' ), 'zzz', false ), textOf( jrows( jr )[0] ) === texts['projects.none'] ) );
	type( jf( jr, 'q' ), '', false );
	click( jr, jrows( jr )[0] );
	check( '...a click on a row opens the card, and a click on the client\'s link does not', jr.location.hash === '#projekt-00003' );
	jr.location.hash = '#projekte';
	await settle();
	click( jr, all( jrows( jr )[0], 'a' )[1] );
	check( '...(the click on the link of the client goes to the client\'s card and not to the project\'s)', jr.location.hash !== '#projekt-00003' );
	const jnofiles = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { files : false, projects : jprojects.map( function( one ) { return Object.assign( {}, one, { files : null } ) } ) } ) ] } } );
	check( 'where the feature Files is off the column Dateien has no count: a dash', jrows( jnofiles ).map( function( tr ) { return textOf( tr.children[4] ) } ).join() === '–,–' );
	const jview = await jopen( '#projekte', {}, { crm : true, pdf : false, elements : false } );
	check( 'without the permission of the Elements panel the list is for looking: no Neues Projekt, the rows keep their links', jf( jview, 'projects-new' ) === undefined && jrows( jview ).length === 2 && all( jrows( jview )[0], 'a' )[0].getAttribute( 'href' ) === '#projekt-00003' && textOf( all( jview.main, '.state-note' ).pop() ) === texts['projects.note'] );
	const jmissing = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { missing : true, projects : [] } ) ] } } );
	check( 'where the element type is not there the screen says so, points to the settings and offers nothing: no table, no search, no switch, no Neues Projekt', jf( jmissing, 'projects' ) === undefined && jf( jmissing, 'q' ) === undefined && jf( jmissing, 'p-all' ) === undefined && jf( jmissing, 'projects-new' ) === undefined
		&& textOf( all( jmissing.main, '.note' )[0] ).indexOf( texts['projects.missing'] ) === 0 && all( all( jmissing.main, '.note' )[0], 'a' )[0].getAttribute( 'href' ) === '#einstellungen' );
	const jfail = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 500, { error : 'x', code : 'crm_documents_database' } ] } } );
	check( 'a list that fails says the sentence of its code and offers to try again', textOf( jfail.main ).indexOf( texts['error.crm_documents_database'] ) !== -1 && all( jfail.main, 'button' ).map( textOf ).indexOf( 'Noch einmal versuchen' ) !== -1 );

	// --- a new project
	const jn = await jopen( '#projekte-neu-k2' );
	check( 'a new project asks for the clients and for the type: the form has the title, the client (the active ones, the one named by the address chosen), the note, the day and - with the permission of the Files panel - the box that makes the folder, on', jn.all( 'crm-clients/list' ).length === 1 && jn.all( 'crm-projekte/list' ).length === 1
		&& jf( jn, 'f-title' ) !== undefined && jf( jn, 'f-client' ).value === '2' && all( jf( jn, 'f-client' ), 'option' ).map( function( o ) { return o.value } ).join() === ',1,2,3,5' && jf( jn, 'f-description' ).tagName === 'TEXTAREA' && jf( jn, 'f-started' ).type === 'date'
		&& jf( jn, 'f-folder' ).checked === true && jf( jn, 'f-folder' ).getAttribute( 'role' ) === 'switch' && textOf( all( jn.main, 'h1' )[0] ) === texts['projects.new'] && jf( jn, 'project-create' ).title === 'Strg+S' && textOf( jf( jn, 'project-create' ) ) === texts['projects.create'] );
	const jstale = await jopen( '#projekte-neu', { 'crm-projekte/list' : function() { return [ 200, jlist( { allowed : true } ) ] } }, { crm : true, pdf : false, elements : true, text : false, files : false } );
	const jstale2 = await jopen( '#projekte-neu', { 'crm-projekte/list' : function() { return [ 200, jlist( { allowed : false } ) ] } } );
	check( 'whether the box is offered is what the answer of the server says now, not the data block of the page - which is old once a setup switched the feature on under it: allowed true makes the box, allowed false takes it away', jf( jstale, 'f-folder' ) !== undefined && jf( jstale2, 'f-folder' ) === undefined );
	const jnb = await jopen( '#projekte-neu', {}, { crm : true, pdf : false, elements : true, text : false, files : false } );
	check( '...without the permission of the Files panel there is no box, and with no client in the address none is chosen; without the permission of the Elements panel the screen says so', jf( jnb, 'f-folder' ) === undefined && jf( jnb, 'f-client' ).value === '' && jf( await jopen( '#projekte-neu', {}, { crm : true, pdf : false, elements : false } ), 'f-title' ) === undefined
		&& textOf( all( ( await jopen( '#projekte-neu', {}, { crm : true, pdf : false, elements : false } ) ).main, '.note' )[0] ) === texts['projects.forbidden'] );
	check( '...where the feature Files is off (the list says so) there is no box either, and where the type is missing the form is not offered', jf( await jopen( '#projekte-neu', { 'crm-projekte/list' : function() { return [ 200, jlist( { files : false } ) ] } } ), 'f-folder' ) === undefined
		&& jf( await jopen( '#projekte-neu', { 'crm-projekte/list' : function() { return [ 200, jlist( { missing : true } ) ] } } ), 'f-title' ) === undefined );
	jsubmit( jf( jn, 'project-form' ) );
	await settle();
	check( 'a title that is missing is said at the field, a client that is missing the same, and nothing is posted', jposts.length === 0 && textOf( jf( jn, 'f-title' ).parentNode.children.pop() ) === texts['projects.err-title'] && jf( jn, 'f-title' ).classList.contains( 'err' ) === true );
	type( jf( jn, 'f-title' ), '  Neues Album  ', false );
	jf( jn, 'f-client' ).value = '';
	jsubmit( jf( jn, 'project-form' ) );
	check( '...the client: the sentence at its field', jposts.length === 0 && textOf( all( jf( jn, 'f-client' ).parentNode, '.state-note.bad' )[0] ) === texts['projects.err-client'] && jf( jn, 'f-title' ).classList.contains( 'err' ) === false );
	jf( jn, 'f-client' ).value = '2';
	type( jf( jn, 'f-description' ), 'Zeile 1\nZeile 2', false );
	type( jf( jn, 'f-started' ), '2026-12-01', false );
	jsubmit( jf( jn, 'project-form' ) );
	await settle();
	check( 'Projekt anlegen saves the element through the Elements panel\'s action: the type, no uri (the type numbers its projects), the native language, isNew, and the fields - the title trimmed, the client\'s number, the note and the day', JSON.stringify( jposts[0] ) === JSON.stringify( [ 'elements/save', { type : 'projekte', uri : '', locale : 'de_DE', isNew : true, fields : { title : 'Neues Album', client : 'K-0002', description : 'Zeile 1\nZeile 2', started : '2026-12-01' } } ] ) );
	check( '...then the folder through the Files panel\'s action, in the group of the client\'s number and named as the project', JSON.stringify( jposts[1] ) === JSON.stringify( [ 'files/folder/add', { group : 'k-0002', name : 'Neues Album' } ] ) );
	check( '...and the id of the folder into the project, by the number the type gave it - then the card of the project opens, and the toast says that the folder is made', JSON.stringify( jposts[2] ) === JSON.stringify( [ 'elements/save', { type : 'projekte', uri : '00005', locale : 'de_DE', isNew : false, fields : { folder : 'f00000000099' } } ] ) && jposts.length === 3 && jn.location.hash === '#projekt-00005' && jn.toast.textContent === texts['projects.made-folder'].replace( '%s', 'Neues Album' ) );
	const jn2 = await jopen( '#projekte-neu' );
	type( jf( jn2, 'f-title' ), 'Ohne Ordner', false );
	jf( jn2, 'f-client' ).value = '1';
	jtoggle( jf( jn2, 'f-folder' ), false );
	jsubmit( jf( jn2, 'project-form' ) );
	await settle();
	check( 'with the box off no folder is made: one post, the fields without the empty ones (no note, no day, no folder), and the card opens', JSON.stringify( jposts ) === JSON.stringify( [ [ 'elements/save', { type : 'projekte', uri : '', locale : 'de_DE', isNew : true, fields : { title : 'Ohne Ordner', client : 'K-0001' } } ] ] ) && jn2.location.hash === '#projekt-00005' );
	const jn3 = await jopen( '#projekte-neu-k1', { 'files/folder/add' : function() { return [ 400, { error : 'x', code : 'files_group', params : [], field : 'group' } ] } } );
	type( jf( jn3, 'f-title' ), 'X'.repeat( 150 ), false );
	jsubmit( jf( jn3, 'project-form' ) );
	await settle();
	check( 'a folder the Files panel refuses leaves the project made and says so in the toast, with the sentence of the refusal; no second save, and the card opens', jposts.length === 1 && jn3.location.hash === '#projekt-00005' && jn3.toast.classList.contains( 'bad' ) === true && jn3.toast.textContent === texts['projects.made-no-folder'].replace( '%s', 'X'.repeat( 150 ) ).replace( '%s', texts['error.files_group'] ) );
	const jn4 = await jopen( '#projekte-neu-k1' );
	type( jf( jn4, 'f-title' ), 'Y'.repeat( 150 ), false );
	jsubmit( jf( jn4, 'project-form' ) );
	await settle();
	check( '...the name of the folder is the title cut to what the folder takes (80)', jposts[1][0] === 'files/folder/add' && jposts[1][1].name === 'Y'.repeat( 80 ) && jposts[0][1].fields.title === 'Y'.repeat( 150 ) );
	const jn5 = await jopen( '#projekte-neu-k1', { 'elements/save' : function( data ) { jposts.push( [ 'elements/save', data ] ); return [ 400, { error : 'x', code : 'crm_project_client_unknown', params : [], field : 'client' } ] } } );
	type( jf( jn5, 'f-title' ), 'Z', false );
	jsubmit( jf( jn5, 'project-form' ) );
	await settle();
	check( 'a refusal of the save is said beside the field it names, in the toast, and the form stays: nothing else is posted and the button is on again', jn5.location.hash === '#projekte-neu-k1' && jposts.length === 1 && textOf( all( jf( jn5, 'f-client' ).parentNode, '.state-note.bad' )[0] ) === texts['error.crm_project_client_unknown'] && jf( jn5, 'f-client' ).classList.contains( 'err' ) === true && jf( jn5, 'project-create' ).disabled === false );
	const jhold = hold();
	const jn6 = await jopen( '#projekte-neu-k1', { 'elements/save' : function( data ) { jposts.push( [ 'elements/save', data ] ); return jhold.promise } } );
	type( jf( jn6, 'f-title' ), 'Doppelt', false );
	jsubmit( jf( jn6, 'project-form' ) );
	jsubmit( jf( jn6, 'project-form' ) );
	check( 'a second press while the first is on its way posts nothing, and the button is off', jposts.length === 1 && jf( jn6, 'project-create' ).disabled === true );
	jn6.location.hash = '#kunden';
	await settle();
	check( '...a way out of the screen waits for the posts (the screen that was asked for is drawn once they are through)', all( jn6.main, 'table' ).length === 0 );
	jhold.release( [ 200, { element : { '.uri' : '/projekte/00007' } } ] );
	await settle();
	check( '...and then the screen that was asked for is there, and the project is not announced over it by a hash', jn6.location.hash === '#kunden' && all( jn6.main, 'table' ).length === 1 && jposts.filter( function( post ) { return post[0] === 'files/folder/add' } ).length === 1 );

	// --- the shortcut and the guard of the form
	const jk = await jopen( '#projekte-neu-k1' );
	type( jf( jk, 'f-title' ), 'Per Tastatur', false );
	check( 'Strg+S in the form of a new project makes the project, as the button does', press( jk, 's', null, { ctrlKey : true } ) === true && ( await settle(), jposts.length === 3 && jposts[0][1].fields.title === 'Per Tastatur' ) );
	const jg = await jopen( '#projekte-neu-k1' );
	type( jf( jg, 'f-title' ), 'Angefangen', false );
	click( jg, all( jg.main, '.crumb a' )[0] );
	check( 'a form with something typed asks before a link takes the person away: the question, and the screen stays until it is answered yes', jg.dialogs.length === 1 && jg.location.hash === '#projekte-neu-k1' && ( jyes( jg, 0 ), true ) && ( await settle(), jg.location.hash === '#projekte' ) );
	const jg2 = await jopen( '#projekte-neu-k1' );
	click( jg2, all( jg2.main, '.crumb a' )[0] );
	check( '...and one with nothing typed does not ask', jg2.dialogs.length === 0 );

	// --- the card
	const jc = await jopen( '#projekt-00001' );
	check( 'the card asks crm-projekte/get with the id as a string; the head has the title, the state, the client as a link and since when, the menu Neuer Beleg and ⋯', JSON.stringify( jc.last( 'crm-projekte/get' ).data ) === '{"id":"00001"}' && textOf( all( jc.main, 'h1' )[0] ) === 'Album 2027' && textOf( all( jc.main, '.head .badge' )[0] ) === texts['projects.running']
		&& all( all( jc.main, '.head .sub' )[0], 'a' )[0].getAttribute( 'href' ) === '#kunde-1' && textOf( all( jc.main, '.head .sub' )[0] ).indexOf( 'seit 01.10.2026' ) !== -1 && all( jc.main, '.crumb a' )[0].getAttribute( 'href' ) === '#projekte' && jmenu( jc, texts['card.document-new'] ) !== undefined && jmenu( jc, '⋯' ) !== undefined );
	check( '...the menu ⋯ has Kunden hierher einladen, Bearbeiten, Abschließen and Löschen (the last one in red); a done project offers Wieder öffnen', all( jmenu( jc, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === [ texts['here.menu'], texts['projects.edit'], texts['projects.finish'], texts['projects.delete'] ].join( '|' ) && jitem( jc, texts['projects.delete'] ).classList.contains( 'danger' ) === true
		&& all( jmenu( await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { done : true } ) } ) ] } } ), '⋯' ), '[role="menuitem"]' ).map( textOf )[2] === texts['projects.reopen'] );
	const jcn = await jopen( '#projekt-00001' );
	click( jcn, all( jmenu( jcn, texts['card.document-new'] ), '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === 'Rechnung' } ) );
	check( '...Neuer Beleg opens the editor for the client with the project chosen: the address carries both', jcn.location.hash === '#neu-invoice-k1-p00001' );
	check( '...a client that is off gets no Neuer Beleg, and a card without the permission of the Elements panel a menu ⋯ with Kunden hierher einladen alone', jmenu( await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { client : Object.assign( {}, jclient1, { active : false } ) } ) ] } } ), texts['card.document-new'] ) === undefined && jmenu( await jopen( '#projekt-00001', {}, { crm : true, pdf : false, elements : false, files : true } ), '⋯' ) !== undefined
		&& all( jmenu( await jopen( '#projekt-00001', {}, { crm : true, pdf : false, elements : false, files : true } ), '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === texts['here.menu'] );
	// --- the download of a project: the state in the head, the switch, the sentence over the files, the mark in the list
	const jdl = function( w ) { return jf( w, 'project-download' ) };
	const jdlOn = { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { download : true, download_at : '2026-11-01T09:30:00Z' } ) } ) ] } };
	check( 'the head says the download is locked, as a badge beside a button that switches it on - and the tab Dateien has the sentence for the owner over the files', jdl( jc ) !== undefined && jdl( jc ).dataset.download === 'off' && textOf( all( jdl( jc ), '.badge' )[0] ) === texts['projects.download-locked']
		&& all( jdl( jc ), '.badge' )[0].classList.contains( 'b-draft' ) === true && textOf( jf( jc, 'project-download-switch' ) ) === texts['projects.download-on'] && jf( jc, 'project-download-switch' ).type === 'button' && jf( jc, 'project-download-switch' ).classList.contains( 'sm' ) === true );
	const jdlFiles = await jopen( '#projekt-00001-dateien' );
	check( '...the sentence is text over the table, a note of the card and not of the portal', jf( jdlFiles, 'project-download-note' ) !== undefined && textOf( jf( jdlFiles, 'project-download-note' ) ) === texts['projects.download-note'] && jf( jdlFiles, 'project-download-note' ).tagName === 'P' );
	const jdlSwitch = await jopen( '#projekt-00001', Object.assign( {}, { 'crm-projekte/download' : function( data ) { jposts.push( [ 'crm-projekte/download', data ] ); return [ 200, { id : data.id, download : data.on, download_at : '2026-11-01T09:30:00Z' } ] } } ) );
	click( jdlSwitch, jf( jdlSwitch, 'project-download-switch' ) );
	await settle();
	check( 'switching it on posts crm-projekte/download with the id as a string and on true - no question -, says so in a toast and reads the card again', JSON.stringify( jposts ) === JSON.stringify( [ [ 'crm-projekte/download', { id : '00001', on : true } ] ] ) && jdlSwitch.dialogs.length === 0 && jdlSwitch.toast.textContent === texts['projects.download-on-done'] && jdlSwitch.all( 'crm-projekte/get' ).length === 2 );
	const jdlNow = await jopen( '#projekt-00001', Object.assign( {}, jdlOn, { 'crm-projekte/download' : function( data ) { jposts.push( [ 'crm-projekte/download', data ] ); return [ 200, { id : data.id, download : data.on, download_at : '' } ] } } ) );
	check( 'with the download on the head says since when, in the badge of a good state, and the button switches it off; the sentence over the files is gone', jdl( jdlNow ).dataset.download === 'on' && textOf( all( jdl( jdlNow ), '.badge' )[0] ) === texts['projects.download-since'].replace( '%s', '01.11.2026' ) && all( jdl( jdlNow ), '.badge' )[0].classList.contains( 'b-paid' ) === true
		&& textOf( jf( jdlNow, 'project-download-switch' ) ) === texts['projects.download-off'] && jf( await jopen( '#projekt-00001-dateien', jdlOn ), 'project-download-note' ) === undefined );
	click( jdlNow, jf( jdlNow, 'project-download-switch' ) );
	check( 'switching it off asks first, in a dialog with the title of the project and a red yes: nothing is posted before the answer', jdlNow.dialogs.length === 1 && jposts.length === 0 && textOf( all( jdlNow.dialogs[0], 'p' )[0] ) === texts['projects.download-off-text'].replace( '%s', 'Album 2027' ) && all( jdlNow.dialogs[0], 'button' ).pop().classList.contains( 'danger' ) === true );
	click( jdlNow, all( jdlNow.dialogs[0], 'button' )[0] );
	await settle();
	check( '...the no leaves it on and posts nothing', jposts.length === 0 && jdlNow.all( 'crm-projekte/get' ).length === 1 );
	click( jdlNow, jf( jdlNow, 'project-download-switch' ) );
	jyes( jdlNow, 1 );
	await settle();
	check( '...the yes posts on false, says so and reads the card again', JSON.stringify( jposts ) === JSON.stringify( [ [ 'crm-projekte/download', { id : '00001', on : false } ] ] ) && jdlNow.toast.textContent === texts['projects.download-off-done'] && jdlNow.all( 'crm-projekte/get' ).length === 2 );
	const jdlRefused = await jopen( '#projekt-00001', { 'crm-projekte/download' : function() { return [ 404, { error : 'x', code : 'crm_projekte_unknown', params : [], field : 'id' } ] } } );
	click( jdlRefused, jf( jdlRefused, 'project-download-switch' ) );
	await settle();
	check( 'a refusal says its sentence, keeps the card as it is and gives the button back', jdlRefused.toast.textContent === texts['error.crm_projekte_unknown'] && jdlRefused.toast.classList.contains( 'bad' ) === true && jdlRefused.all( 'crm-projekte/get' ).length === 1 && jf( jdlRefused, 'project-download-switch' ).disabled === false );
	const jdlList = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [ jrow( { id : '00005', title: 'Offen', download : true } ), jrow( { id : '00004', title : 'Zu', download : false } ) ] } ) ] } } );
	const jdlRows = jrows( jdlList );
	check( 'the list marks a project whose download is on with an arrow beside its state - with its words as the title and for a reader - and a locked one with nothing', all( jdlRows[0], '.dl-mark' ).length === 1 && all( jdlRows[0], '.dl-mark' )[0].title === texts['projects.download-on-title'] && all( jdlRows[0], '.dl-mark' )[0].getAttribute( 'aria-label' ) === texts['projects.download-on-title'] && all( jdlRows[0], '.dl-mark' )[0].getAttribute( 'role' ) === 'img'
		&& all( jdlRows[1], '.dl-mark' ).length === 0 && textOf( jdlRows[0].children[6] ).indexOf( texts['projects.running'] ) === 0 );
	const jdlSearch = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [ jrow( { id : '00005', title: 'Offen', download : true } ) ] } ) ] } } );
	const jdlFind = function( text ) {
		jf( jdlSearch, 'q' ).value = text;
		fire( jf( jdlSearch, 'q' ), 'input', { target : jf( jdlSearch, 'q' ) } );
		return jrows( jdlSearch ).filter( function( one ) { return one.dataset.id !== undefined } ).length;
	};
	check( '...and the search does not find the mark: neither its arrow nor its words are part of what a project is found by, its title is', jdlFind( '↓' ) === 0 && jdlFind( 'herunterladen' ) === 0 && jdlFind( 'offen' ) === 1 );

	// --- the approval of a project: the line under the head with the way to what the customer saw, the state in the list
	const japproval = { id : 7, kind : 'approval', client_id : 1, member_id : 'aaaaaaaaaaaa', member_email : 'lena@nordlicht.example', member_name : 'Lena <b>Brandt</b>', document_id : null, project : '00001', sha256 : 'c'.repeat( 64 ), shown : '{"files":[{"name":"<img src=x onerror=alert(1)>"}]}', at : '2026-11-03T10:15:00Z', ip : '203.0.113.7', agent : 'Browser/1.0' };
	const japproved = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { approval : japproval } ) } ) ] } } );
	check( 'a project the customer approved says so under the head: "Vom Kunden abgenommen am … durch …", the name as text, and the state beside the title is abgenommen in the badge of a good state', textOf( jf( japproved, 'project-approval' ) ).indexOf( texts['approval.head'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena <b>Brandt</b>' ) ) !== -1
		&& jf( japproved, 'project-approval' ).tagName === 'P' && jf( japproved, 'project-approval' ).classList.contains( 'state-note' ) === true && findAll( jf( japproved, 'project-approval' ), function( node ) { return node.tagName === 'B' } ).length === 0
		&& textOf( all( japproved.main, '.head .badge' )[0] ) === '✓ '+ texts['projects.approved'] && all( japproved.main, '.head .badge' )[0].classList.contains( 'b-paid' ) === true );
	check( '...the line comes after the head and before the facts of the card', all( japproved.main, '#project-approval' ).length === 1 && japproved.main.children.indexOf( jf( japproved, 'project-approval' ) ) === japproved.main.children.indexOf( jf( japproved, 'project-info' ) ) - 1 && japproved.main.children.indexOf( jf( japproved, 'project-approval' ) ) > japproved.main.children.findIndex( function( node ) { return node.classList.contains( 'head' ) } ) );
	click( japproved, jf( japproved, 'project-approval-show' ) );
	check( '...Anzeigen opens a dialog with the JSON the customer was shown, as text - markup in it is not markup - and its hash', japproved.dialogs.length === 1 && textOf( all( japproved.dialogs[0], 'pre' )[0] ) === japproval.shown && findAll( japproved.dialogs[0], function( node ) { return node.tagName === 'IMG' } ).length === 0
		&& textOf( all( japproved.dialogs[0], 'p' )[0] ) === texts['record.hash'].replace( '%s', 'c'.repeat( 64 ) ) && japproved.dialogs[0].getAttribute( 'aria-label' ) === texts['record.title-approval'] );
	const japprovedFacts = all( japproved.dialogs[0], 'dl.kv' );
	check( '...the dialog also says when (the day and the time), from which address and with which browser the customer approved, in that order and before the JSON', japprovedFacts.length === 1 && all( japprovedFacts[0], 'dt' ).map( textOf ).join( '|' ) === [ texts['record.at'], texts['record.ip'], texts['record.agent'] ].join( '|' )
		&& textOf( all( japprovedFacts[0], 'dd' )[0] ).indexOf( '03.11.2026 ' ) === 0 && /\d\d:\d\d$/.test( textOf( all( japprovedFacts[0], 'dd' )[0] ) ) === true && textOf( all( japprovedFacts[0], 'dd' )[1] ) === '203.0.113.7' && textOf( all( japprovedFacts[0], 'dd' )[2] ) === 'Browser/1.0'
		&& Array.from( all( japproved.dialogs[0], 'form' )[0].children ).indexOf( japprovedFacts[0] ) < Array.from( all( japproved.dialogs[0], 'form' )[0].children ).indexOf( all( japproved.dialogs[0], 'pre' )[0] ) );
	const jappHostile = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { approval : Object.assign( {}, japproval, { ip : '<b>1</b>', agent : '<img src=x onerror=alert(1)>' } ) } ) } ) ] } } );
	click( jappHostile, jf( jappHostile, 'project-approval-show' ) );
	check( '...what the customer\'s browser said of itself is text in it: no element is made of an address or a browser', all( jappHostile.dialogs[0], 'dd' ).length === 3 && textOf( all( jappHostile.dialogs[0], 'dd' )[1] ) === '<b>1</b>' && textOf( all( jappHostile.dialogs[0], 'dd' )[2] ) === '<img src=x onerror=alert(1)>'
		&& findAll( jappHostile.dialogs[0], function( node ) { return node.tagName === 'IMG' || node.tagName === 'B' } ).length === 0 );
	check( 'a project nobody approved has no such line and the state running', jf( jc, 'project-approval' ) === undefined && textOf( all( jc.main, '.head .badge' )[0] ) === texts['projects.running'] );
	const jappDone = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { done : true, approval : japproval } ) } ) ] } } );
	check( '...a project that is done says abgeschlossen and not abgenommen - the line stays, it is the record', textOf( all( jappDone.main, '.head .badge' )[0] ) === texts['projects.done'] && jf( jappDone, 'project-approval' ) !== undefined );
	const jappList = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [ jrow( { id : '00005', title : 'Abgenommen', approval : { id : 7, client_id : 1, project : '00005', at : '2026-11-03T10:15:00Z', member_email : 'lena@nordlicht.example', member_name : 'Lena Brandt', sha256 : 'c'.repeat( 64 ) } } ), jrow( { id : '00004', title : 'Läuft' } ), jrow( { id : '00003', title : 'Fertig', done : true, approval : { id : 8, client_id : 1, project : '00003', at : '2026-11-01T10:15:00Z', member_email : 'lena@nordlicht.example', member_name : '', sha256 : 'd'.repeat( 64 ) } } ) ] } ) ] } } );
	const jappRows = jrows( jappList );
	check( 'the list says abgenommen for a project the customer approved while it is not done - with the day and the name as the title - running for one nobody approved, and done for one that is done', textOf( jappRows[0].children[6] ) === '✓ '+ texts['projects.approved'] && all( jappRows[0].children[6], '.badge' )[0].classList.contains( 'b-paid' ) === true
		&& all( jappRows[0].children[6], '.badge' )[0].title === texts['approval.head'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) && textOf( jappRows[1].children[6] ) === texts['projects.running'] );
	check( '...and the done project is not among the rows until the switch for the done ones is on', jappRows.length === 2 );

	// --- Einwilligungen: the lines on the card of a project, the mark in the list, the records of the client, the sentences in the settings
	const c14_open = function( lines, row ) { return jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( Object.assign( { consents : lines }, row || {} ) ) } ) ] } } ) };
	const c14_given = await c14_open( [ { purpose : 'social', consent : c14_consent() }, { purpose : 'archive', consent : null } ] );
	const c14_lines = all( jf( c14_given, 'project-consents' ), 'p' );
	check( 'a project the client consented to says so under the head: a line for each purpose - "Referenznutzung: eingewilligt am … durch …" with the name as text, and "keine Einwilligung" for a purpose with a sentence and no consent', c14_lines.length === 2 && c14_lines[0].dataset.purpose === 'social' && c14_lines[0].dataset.state === 'given' && c14_lines[1].dataset.purpose === 'archive' && c14_lines[1].dataset.state === 'none'
		&& textOf( c14_lines[0] ).indexOf( texts['consent.line-given'].replace( '%s', texts['consent.purpose-social'] ).replace( '%s', '03.11.2026' ).replace( '%s', 'Lena <b>Brandt</b>' ) ) !== -1 && textOf( c14_lines[1] ).indexOf( texts['consent.line-none'].replace( '%s', texts['consent.purpose-archive'] ) ) !== -1
		&& findAll( jf( c14_given, 'project-consents' ), function( node ) { return node.tagName === 'B' } ).length === 0 && c14_lines.every( function( one ) { return one.classList.contains( 'state-note' ) } ) );
	check( '...a line with a consent has Anzeigen, a line without has none; the lines stand after the head and before the facts of the card', all( c14_lines[0], 'button' ).length === 1 && all( c14_lines[1], 'button' ).length === 0 && c14_given.main.children.indexOf( jf( c14_given, 'project-consents' ) ) === c14_given.main.children.indexOf( jf( c14_given, 'project-info' ) ) - 1 );
	click( c14_given, all( c14_lines[0], 'button' )[0] );
	check( '...Anzeigen opens the dialog of the consent: the title, the hash, the sentence as text - markup and a shortcode in it are not made anything of - and, before it, the time, the address and the browser', c14_given.dialogs.length === 1 && c14_given.dialogs[0].getAttribute( 'aria-label' ) === texts['record.title-consent'] && textOf( all( c14_given.dialogs[0], 'pre' )[0] ) === 'Ich willige <b>ein</b>.\n[crm-client]'
		&& findAll( c14_given.dialogs[0], function( node ) { return node.tagName === 'B' } ).length === 0 && textOf( all( c14_given.dialogs[0], 'p' )[0] ) === texts['record.hash'].replace( '%s', 'd'.repeat( 64 ) ) && all( c14_given.dialogs[0], 'dl.kv dt' ).map( textOf ).join( '|' ) === [ texts['record.at'], texts['record.ip'], texts['record.agent'] ].join( '|' )
		&& textOf( all( c14_given.dialogs[0], 'dl.kv dd' )[1] ) === '203.0.113.7' && textOf( all( c14_given.dialogs[0], 'dl.kv dd' )[2] ) === 'Browser/1.0' );
	const c14_revoked = await c14_open( [ { purpose : 'social', consent : c14_consent( { revoked : c14_revocation } ) } ] );
	const c14_rline = all( jf( c14_revoked, 'project-consents' ), 'p' )[0];
	check( 'a consent that was taken back says so: "widerrufen am … durch …" with the day and the name of the revocation, the state revoked, the badge not of a good state', c14_rline.dataset.state === 'revoked' && textOf( c14_rline ).indexOf( texts['consent.line-revoked'].replace( '%s', texts['consent.purpose-social'] ).replace( '%s', '05.11.2026' ).replace( '%s', 'Lena <b>Brandt</b>' ) ) !== -1 && all( c14_rline, '.badge.b-paid' ).length === 0 && all( c14_rline, '.badge' ).length === 1 );
	click( c14_revoked, all( c14_rline, 'button' )[0] );
	check( '...Anzeigen shows the consent that was given, with its sentence and its time - and says that it was taken back: when, by whom (as text), from which address and with which browser', textOf( all( c14_revoked.dialogs[0], 'pre' )[0] ) === 'Ich willige <b>ein</b>.\n[crm-client]' && textOf( all( c14_revoked.dialogs[0], 'dl.kv dd' )[0] ).indexOf( '03.11.2026 ' ) === 0
		&& all( c14_revoked.dialogs[0], 'dl.kv dt' ).map( textOf ).join( '|' ) === [ texts['record.at'], texts['record.ip'], texts['record.agent'], texts['record.revoked-at'], texts['record.revoked-by'], texts['record.revoked-ip'], texts['record.revoked-agent'] ].join( '|' )
		&& textOf( all( c14_revoked.dialogs[0], 'dl.kv dd' )[3] ).indexOf( '05.11.2026 ' ) === 0 && textOf( all( c14_revoked.dialogs[0], 'dl.kv dd' )[4] ) === 'Lena <b>Brandt</b>' && textOf( all( c14_revoked.dialogs[0], 'dl.kv dd' )[5] ) === '203.0.113.8' && textOf( all( c14_revoked.dialogs[0], 'dl.kv dd' )[6] ) === 'Browser/2.0'
		&& all( c14_given.dialogs[0], 'dl.kv dt' ).length === 3 );
	const c14_none = await c14_open( [] );
	check( 'a project with no line to say has no block of lines at all', jf( c14_none, 'project-consents' ) === undefined && jf( jc, 'project-consents' ) === undefined );
	const c14_old = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow() } ) ] } } );
	check( '...nor has a project whose answer carries no lines', jf( c14_old, 'project-consents' ) === undefined );

	// the list: the megaphone while the consent holds for the social use
	const c14_list = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [ jrow( { id : '00005', title : 'Mit Einwilligung', consent_social : true } ), jrow( { id : '00006', title : 'Ohne', consent_social : false } ), jrow( { id : '00007', title : 'Alt' } ) ] } ) ] } } );
	const c14_rows = jrows( c14_list );
	const c14_marks = c14_rows.map( function( row ) { return all( row, '.mega-icon' ) } );
	check( 'the list marks a project whose client consented to the social use with a megaphone in the column of the state: an image with the words "Referenznutzung eingewilligt" as its title and its name, and nothing for the others - the word is not the mark', c14_marks.map( function( marks ) { return marks.length } ).join() === '1,0,0' && c14_marks[0][0].title === texts['consent.mark-title'] && c14_marks[0][0].getAttribute( 'aria-label' ) === texts['consent.mark-title']
		&& c14_marks[0][0].getAttribute( 'role' ) === 'img' && c14_marks[0][0].dataset.icon === 'megaphone' && c14_marks[0][0].parentNode === c14_rows[0].children[6] && textOf( c14_rows[0].children[6] ).indexOf( texts['consent.mark-title'] ) === -1 );
	const c14_find = function( text ) {
		jf( c14_list, 'q' ).value = text;
		fire( jf( c14_list, 'q' ), 'input', { target : jf( c14_list, 'q' ) } );
		return jrows( c14_list ).filter( function( one ) { return one.dataset.id !== undefined } ).length;
	};
	check( '...and the search does not find the mark: its words are no part of what a project is found by', c14_find( 'Referenznutzung' ) === 0 && c14_find( 'Mit' ) === 1 );

	const jinfo = jf( jc, 'project-info' );
	check( 'the card shows the facts: the client as a link, the day, the note on two lines, the documents with the offers that wait, the folder - and nothing that is empty', all( jinfo, 'dt' ).map( textOf ).join( '|' ) === [ texts['projects.f-client'], texts['projects.f-started'], texts['projects.f-description'], texts['projects.column-documents'], texts['projects.folder'] ].join( '|' ) && all( jinfo, 'dd' ).map( textOf ).join( '|' ).indexOf( 'Nordlicht Records GmbH · K-0001|01.10.2026|Aufnahmeund Mix|3 · '+ texts['projects.open-offers'].replace( '%s', '1' )+ '|Album 2027' ) === 0
		&& all( jinfo, 'br' ).length === 1 );
	check( '...the tabs are Belege and Dateien, each with its count, and the first is open', all( jc.main, '.tabs button' ).map( textOf ).join( '|' ) === 'Belege3|Dateien1' && jtab( jc, 'belege' ).getAttribute( 'aria-selected' ) === 'true' );
	check( '...the tab Belege asks crm-documents/list for the project, and shows the table of the documents with links to them, their kind, day, amount and state', JSON.stringify( jc.last( 'crm-documents/list' ).data ) === '{"project":"00001"}' && all( jf( jc, 'project-documents' ), 'tbody tr' ).length === 2
		&& all( all( jf( jc, 'project-documents' ), 'tbody tr' )[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-11' && textOf( all( jf( jc, 'project-documents' ), 'tbody tr' )[1].children[1] ) === 'Angebot' );
	check( '...a project with no document says so; the address #projekt-00001-dateien opens the other tab', textOf( jf( await jopen( '#projekt-00001', { 'crm-documents/list' : function() { return [ 200, { documents : [], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } ), 'project-documents' ) ).indexOf( texts['projects.docs-empty'] ) !== -1 && jtab( await jopen( '#projekt-00001-dateien' ), 'dateien' ).classList.contains( 'on' ) === true );
	const jgone = await jopen( '#projekt-00099' );
	check( 'a project that is not there says so and leads back to the list', all( jgone.main, '.note.bad' ).length === 1 && textOf( all( jgone.main, '.note' )[0] ).indexOf( texts['error.crm_projekte_unknown'] ) === 0 && all( all( jgone.main, '.note' )[0], 'a' )[0].getAttribute( 'href' ) === '#projekte' );

	// --- editing
	click( jc, jitem( jc, texts['projects.edit'] ) );
	check( 'Bearbeiten makes the facts a form: the title, the note and the day with what they hold - the client is not a field - and Speichern carries the shortcut; a second press of the menu item makes no second form', jf( jc, 'project-info' ).tagName === 'FORM' && jf( jc, 'f-title' ).value === 'Album 2027' && jf( jc, 'f-description' ).value === 'Aufnahme\nund Mix' && jf( jc, 'f-started' ).value === '2026-10-01' && jf( jc, 'f-client' ) === undefined
		&& jf( jc, 'project-save' ).title === 'Strg+S' && ( click( jc, jitem( jc, texts['projects.edit'] ) ), all( jc.main, '#project-save' ).length === 1 ) );
	click( jc, jf( jc, 'project-cancel' ) );
	check( '...Abbrechen puts the facts back and drops the guard', jf( jc, 'project-info' ).tagName === 'DIV' && jc.app.state.draft === null && jposts.length === 0 );
	click( jc, jitem( jc, texts['projects.edit'] ) );
	jsubmit( jf( jc, 'project-info' ) );
	await settle();
	check( '...saved with nothing changed it posts nothing and closes', jposts.length === 0 && jf( jc, 'project-info' ).tagName === 'DIV' );
	click( jc, jitem( jc, texts['projects.edit'] ) );
	type( jf( jc, 'f-title' ), '  ', false );
	jsubmit( jf( jc, 'project-info' ) );
	check( '...a title that is empty is said at the field and posts nothing', jposts.length === 0 && textOf( jf( jc, 'f-title' ).parentNode.children.pop() ) === texts['projects.err-title'] );
	type( jf( jc, 'f-title' ), 'Album 2027 (Mix)', false );
	check( 'Strg+S saves the form: only what changed is posted - the title - as a change of the project by its number', press( jc, 's', null, { ctrlKey : true } ) === true && ( await settle(), jposts[0] !== undefined && JSON.stringify( jposts[0] ) === JSON.stringify( [ 'elements/save', { type : 'projekte', uri : '00001', locale : 'de_DE', isNew : false, fields : { title : 'Album 2027 (Mix)' } } ] ) ) );
	check( '...the folder is renamed with it, by the Files panel\'s action, to the new title; the toast says it is saved, and the card is read again', JSON.stringify( jposts[1] ) === JSON.stringify( [ 'files/folder/save', { id : 'f00000000001', name : 'Album 2027 (Mix)' } ] ) && jc.toast.textContent === texts['projects.saved'] && jc.all( 'crm-projekte/get' ).length === 2 && jf( jc, 'project-info' ).tagName === 'DIV' );
	const jc2 = await jopen( '#projekt-00001', { 'files/folder/save' : function() { return [ 400, { error : 'x', code : 'files_long', params : [ 80 ], field : 'name' } ] } } );
	click( jc2, jitem( jc2, texts['projects.edit'] ) );
	type( jf( jc2, 'f-title' ), 'Neu', false );
	type( jf( jc2, 'f-description' ), 'Anders', false );
	type( jf( jc2, 'f-started' ), '2026-11-01', false );
	jsubmit( jf( jc2, 'project-info' ) );
	await settle();
	check( 'the note and the day are posted with it; a refusal of the folder\'s new name is said (the project is saved all the same, the folder keeps its name)', JSON.stringify( jposts[0][1].fields ) === '{"title":"Neu","description":"Anders","started":"2026-11-01"}' && jc2.toast.classList.contains( 'bad' ) === true && jc2.toast.textContent === texts['projects.saved-no-rename'].replace( '%s', texts['error.files_long'].replace( '%s', '80' ) ) );
	const jc3 = await jopen( '#projekt-00001', {}, { crm : true, pdf : false, elements : true, text : false, files : false } );
	click( jc3, jitem( jc3, texts['projects.edit'] ) );
	type( jf( jc3, 'f-title' ), 'Ohne Recht', false );
	jsubmit( jf( jc3, 'project-info' ) );
	await settle();
	check( '...without the permission of the Files panel no rename is tried', jposts.length === 1 && jposts[0][0] === 'elements/save' );
	const jc4 = await jopen( '#projekt-00001', { 'elements/save' : function() { return [ 400, { error : 'Missing required element key' } ] } } );
	click( jc4, jitem( jc4, texts['projects.edit'] ) );
	type( jf( jc4, 'f-title' ), 'Neu', false );
	jsubmit( jf( jc4, 'project-info' ) );
	await settle();
	check( 'a refusal of the save keeps the form with what was typed, says the sentence, and the button is on again', jf( jc4, 'project-info' ).tagName === 'FORM' && jf( jc4, 'f-title' ).value === 'Neu' && jc4.toast.textContent === texts['projects.error-save'] && jf( jc4, 'project-save' ).disabled === false && jc4.app.state.draft.dirty === true );
	click( jc4, all( jc4.main, '.crumb a' )[0] );
	check( '...and the guard asks before a link takes the form away', jc4.dialogs.length === 1 && jc4.location.hash === '#projekt-00001' );

	// --- the switch Abgeschlossen, and the delete
	const jd = await jopen( '#projekt-00001' );
	click( jd, jitem( jd, texts['projects.finish'] ) );
	await settle();
	check( 'Abschließen is a change of the field done by the Elements panel\'s action - done true, nothing else -, the toast says so and the card is read again', JSON.stringify( jposts[0] ) === JSON.stringify( [ 'elements/save', { type : 'projekte', uri : '00001', locale : 'de_DE', isNew : false, fields : { done : true } } ] ) && jd.toast.textContent === texts['projects.finished'] && jd.all( 'crm-projekte/get' ).length === 2 );
	const jfin = await jopen( '#projekt-00001', { 'elements/save' : function( data ) { jposts.push( [ 'elements/save', data ] ); return [ 403, { error : 'x', code : 'elements_fields_not_allowed', params : [ 'done' ], field : 'done' } ] } } );
	click( jfin, jitem( jfin, texts['projects.finish'] ) );
	await settle();
	check( '...a change the panel refuses says its sentence and not that the project is done: the card is not read again, and nothing is announced', jfin.toast.classList.contains( 'bad' ) === true && jfin.toast.textContent !== texts['projects.finished'] && jfin.all( 'crm-projekte/get' ).length === 1 && textOf( all( jfin.main, '.head .badge' )[0] ) === texts['projects.running'] );
	const jdone = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { done : true } ) } ) ] } } );
	click( jdone, jitem( jdone, texts['projects.reopen'] ) );
	await settle();
	check( '...Wieder öffnen posts done false; a done project says so in the head', jposts[0][1].fields.done === false && jdone.toast.textContent === texts['projects.reopened'] && textOf( all( jdone.main, '.head .badge' )[0] ) === texts['projects.done'] );
	click( jd, jitem( jd, texts['projects.delete'] ) );
	check( 'Löschen asks first, with the title and with the sentence that the folder and its files stay in Files; nothing is posted before the yes', jd.dialogs.length === 1 && jposts.length === 1 && textOf( all( jd.dialogs[0], 'p' )[0] ) === texts['projects.delete-text-folder'].replace( '%s', 'Album 2027' ) && all( jd.dialogs[0], 'button' ).pop().classList.contains( 'danger' ) === true );
	jyes( jd, 0 );
	await settle();
	check( '...the yes deletes through the Elements panel\'s action, for the type and the number, and leaves for the list', JSON.stringify( jposts[1] ) === JSON.stringify( [ 'elements/delete', { type : 'projekte', uri : '00001' } ] ) && jd.location.hash === '#projekte' && jd.toast.textContent === texts['projects.deleted'] );
	const jnf = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { folder : null, list : [] } ) ] } } );
	click( jnf, jitem( jnf, texts['projects.delete'] ) );
	check( '...a project with no folder has the shorter sentence', textOf( all( jnf.dialogs[0], 'p' )[0] ) === texts['projects.delete-text'].replace( '%s', 'Album 2027' ) );
	const jrefused = await jopen( '#projekt-00001', { 'elements/delete' : function() { return [ 409, { error : 'x', code : 'crm_project_documents', params : [ 3 ] } ] } } );
	click( jrefused, jitem( jrefused, texts['projects.delete'] ) );
	jyes( jrefused, 0 );
	await settle();
	check( 'a delete the endpoint refuses because documents name the project is said with the count, and the card stays', jrefused.location.hash === '#projekt-00001' && jrefused.toast.classList.contains( 'bad' ) === true && jrefused.toast.textContent === texts['error.crm_project_documents'].replace( '%s', '3' ) && textOf( all( jrefused.main, 'h1' )[0] ) === 'Album 2027' );

	// --- the tab Dateien
	const jdf = await jopen( '#projekt-00001-dateien' );
	const jfrows = function( w ) { return all( jf( w, 'project-file-table' ), 'tbody tr' ) };
	check( 'the tab Dateien lists the files of the folder: the name as the link to the download, the size, the day, who put it there (Du, or the member by name) and a switch for whether the members see it; a name that is markup is text', all( jf( jdf, 'project-file-table' ), 'thead th' ).map( textOf ).join( '|' ) === [ texts['projects.file-name'], texts['projects.file-size'], texts['projects.file-day'], texts['projects.file-by'], texts['projects.file-visible'] ].join( '|' )+ '|' && jfrows( jdf ).length === 2
		&& all( jfrows( jdf )[0], 'a' )[0].getAttribute( 'href' ) === '/.files/a00000000001' && textOf( jfrows( jdf )[0].children[0] ) === 'Notizen.txt' && textOf( jfrows( jdf )[0].children[1] ) === '1,1 MB' && textOf( jfrows( jdf )[0].children[2] ) === '03.11.2026' && textOf( jfrows( jdf )[0].children[3] ) === texts['projects.by-owner']
		&& textOf( jfrows( jdf )[1].children[3] ) === 'Lena Brandt' && textOf( jfrows( jdf )[1].children[1] ) === '51 KB' && textOf( jfrows( jdf )[1].children[0] ) === '<b>Cover</b>.png' && findAll( jf( jdf, 'project-file-table' ), function( n ) { return n.tagName === 'B' } ).length === 0
		&& all( jfrows( jdf )[0], 'input' )[0].checked === true && all( jfrows( jdf )[1], 'input' )[0].checked === false && all( jfrows( jdf )[0], 'input' )[0].getAttribute( 'role' ) === 'switch' );
	jtoggle( all( jfrows( jdf )[1], 'input' )[0], true );
	await settle();
	check( 'the switch sichtbar posts the Files panel\'s action files/save with the id and the value; a refusal puts the switch back and says the sentence', JSON.stringify( jposts[0] ) === JSON.stringify( [ 'files/save', { id : 'a00000000002', visible : true, notify : false, page : '/portal/projekte?projekt=00001&tab=dateien' } ] ) && all( jfrows( jdf )[1], 'input' )[0].checked === true && all( jfrows( jdf )[1], 'input' )[0].disabled === false );
	const jbox = jf( jdf, 'project-notify' );
	check( 'the tab has one box "Kunden per E-Mail informieren" above the table and the upload - a real checkbox, unticked here since the card says the setting of Dateien is off - and a switch to visible says in the toast that no mail went', jbox !== undefined && jbox.type === 'checkbox' && jbox.checked === false && textOf( jf( jdf, 'project-notify-label' ) ).indexOf( texts['notify.files'] ) !== -1
		&& texts['notify.files'] === 'Kunden per E-Mail informieren' && jdf.toast.textContent === texts['projects.shown-not-informed'] );
	jbox.checked = true;
	jtoggle( all( jfrows( jdf )[1], 'input' )[0], false );
	await settle();
	jtoggle( all( jfrows( jdf )[1], 'input' )[0], true );
	await settle();
	check( '...ticked, the next switches post notify true - to hidden as well as to visible - and the toast for visible says that the accounts of the customer are informed', jposts.slice( -2 ).every( function( post ) { return post[0] === 'files/save' && post[1].notify === true } ) && jdf.toast.textContent === texts['projects.shown-informed'] );
	const jon = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { notify : true } ) ] } } );
	check( '...where the card says the setting is on, the box starts ticked: the customer is informed by default as the setting says - and a box of the tab is not carried to another visit of it', jf( jon, 'project-notify' ).checked === true && jf( jdf, 'project-notify' ).checked === true );
	jf( jon, 'project-notify' ).checked = false;
	fire( jf( jon, 'project-notify' ), 'change', { target : jf( jon, 'project-notify' ) } );
	click( jon, all( jfrows( jon )[0], 'button' ).find( function( b ) { return b.dataset.del !== undefined } ) );
	await settle();
	click( jon, all( jon.dialogs[0], 'button' ).pop() );
	await settle();
	check( 'the choice of the owner stays on the screen: the box unticked, a file deleted - the card is read again and the tab drawn anew - and the box is still unticked, and the next upload posts notify false', jon.all( 'crm-projekte/get' ).length >= 2 && jf( jon, 'project-notify' ).checked === false );
	jf( jon, 'up-input' ).files = [ { name : 'Stays.txt', size : 300, slice : function( from, to ) { return { size : to - from, from : from, to : to } } } ];
	click( jon, jf( jon, 'up-send' ) );
	await settle();
	check( '...the upload after the redraw posts notify false', jon.all( 'files/upload' ).length === 1 && jon.all( 'files/upload' )[0].data.notify === false );
	check( '...with the box unticked the upload makes no call that mails: the chunk says notify false and files/notify is not called', jon.all( 'files/upload' ).every( function( call ) { return call.data.notify === false } ) && jon.all( 'files/upload' ).length === 1 && jon.all( 'files/notify' ).length === 0 );
	jon.location.hash = '#projekte';
	await settle();
	jon.location.hash = '#projekt-00001-dateien';
	await settle();
	check( 'the choice goes with the screen: after another screen and back the box is at the setting again - ticked here, since the card says the setting is on', jf( jon, 'project-notify' ).checked === true );
	const jdf2 = await jopen( '#projekt-00001-dateien', { 'files/save' : function() { return [ 404, { error : 'x', code : 'files_unknown' } ] } } );
	jtoggle( all( jfrows( jdf2 )[1], 'input' )[0], true );
	await settle();
	check( '...(the file was gone: the switch is as it was)', all( jfrows( jdf2 )[1], 'input' )[0].checked === false && jdf2.toast.textContent === texts['error.files_unknown'] );
	const jdel = await jopen( '#projekt-00001-dateien' );
	click( jdel, all( jfrows( jdel )[0], 'button' ).find( function( b ) { return b.dataset.del !== undefined } ) );
	check( 'Löschen of a file asks first, with its name; nothing is posted before the yes', jdel.dialogs.length === 1 && jposts.length === 0 && textOf( all( jdel.dialogs[0], 'p' )[0] ).indexOf( 'Notizen.txt' ) !== -1 && all( jdel.dialogs[0], 'button' ).pop().classList.contains( 'danger' ) === true );
	jyes( jdel, 0 );
	await settle();
	check( '...the yes posts files/delete with the id, and the card is read again', JSON.stringify( jposts[0] ) === JSON.stringify( [ 'files/delete', { id : 'a00000000001' } ] ) && jdel.all( 'crm-projekte/get' ).length === 2 && jdel.toast.textContent === texts['projects.file-deleted'] );
	const jdr = await jopen( '#projekt-00001-dateien', {}, { crm : true, pdf : false, elements : true, text : false, files : false } );
	check( 'without the permission of the Files panel the files are read-only: the names are text - no download link, which the route would refuse -, the switches are off, there is no Löschen and no upload, and the sentence says why', all( jfrows( jdr )[0], 'a' ).length === 0 && all( jfrows( jdr )[0], 'input' )[0].disabled === true && all( jf( jdr, 'project-file-table' ), 'button' ).length === 0 && jf( jdr, 'project-upload' ) === undefined && jf( jdr, 'up-input' ) === undefined
		&& textOf( jf( jdr, 'project-files' ) ).indexOf( texts['projects.files-forbidden'] ) !== -1 && jdr.all( 'files/list' ).length === 0 );
	const joff = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { files : null } ), files : false, folder : null, list : null } ) ] } } );
	check( 'where the feature is off the tab says so and has nothing else', jf( joff, 'project-file-table' ) === undefined && textOf( jf( joff, 'project-files' ) ) === texts['projects.files-off'] && all( jtab( joff, 'dateien' ), '.n' ).length === 0 );
	const jedStale = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { allowed : true } ) ] } }, { crm : true, pdf : false, elements : true, text : false, files : false } );
	const jedStale2 = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { allowed : false } ) ] } } );
	check( '...so are the files of the card: what the answer says now decides whether they can be changed and uploaded', jf( jedStale, 'up-input' ) !== undefined && all( jf( jedStale, 'project-file-table' ), 'button' ).filter( function( b ) { return b.dataset.del !== undefined } ).length === 2 && jf( jedStale2, 'up-input' ) === undefined && all( jf( jedStale2, 'project-file-table' ), 'button' ).length === 0 );

	// --- a file removed from the server: the tab, the line of the Übersicht and the screen Uploads
	const fillT = function( key, ...values ) { let at = 0; return texts[key].replace( /%s/g, function() { return String( values[at++] ?? '' ) } ) };
	const rgone = { id : 'a00000000009', name : 'Gone <i>Take</i>.wav', ext : 'wav', size : 3000000, mime : 'audio/wav', created : '2026-09-01T10:00:00Z', visible : true, gone : '2026-11-02T08:00:00Z', by : { kind : 'owner', label : '' }, url : '/.files/a00000000009', title : 'Gone <i>Take</i>', version : '', versions : 1, group : 7, comments : null };
	const rwithGone = { 'crm-projekte/get' : function() { return [ 200, jget( { list : [ jfiles[0], rgone, jfiles[1] ] } ) ] } };
	const rrm = await jopen( '#projekt-00001-dateien', rwithGone );
	const rremoveOf = function( row ) { return all( row, 'button' ).find( function( b ) { return b.dataset.remove !== undefined } ) };
	const rdeleteOf = function( row ) { return all( row, 'button' ).find( function( b ) { return b.dataset.del !== undefined } ) };
	check( 'a file that was removed from the server stays in the table: its name is text with the day its bytes went under it, it has no link, no switch and no Vom Server entfernen - Löschen stays - and the others have both buttons',
		jfrows( rrm ).length === 3 && textOf( jfrows( rrm )[1].children[0] ) === rgone.name+ fillT( 'projects.file-gone', '02.11.2026' ) && all( jfrows( rrm )[1], 'a' ).length === 0 && all( jfrows( rrm )[1], 'input' ).length === 0 && rremoveOf( jfrows( rrm )[1] ) === undefined && rdeleteOf( jfrows( rrm )[1] ) !== undefined
		&& jfrows( rrm )[1].classList.contains( 'dim' ) === true && all( jfrows( rrm )[1], '[data-gone]' ).length === 1 && findAll( jf( rrm, 'project-file-table' ), function( n ) { return n.tagName === 'I' } ).length === 0
		&& [ 0, 2 ].every( function( at ) { return rremoveOf( jfrows( rrm )[at] ) !== undefined && rdeleteOf( jfrows( rrm )[at] ) !== undefined && textOf( rremoveOf( jfrows( rrm )[at] ) ) === texts['projects.file-remove'] } ) );
	click( rrm, rdeleteOf( jfrows( rrm )[1] ) );
	check( '...deleting it asks in the words for a row whose bytes are gone already, with its name', rrm.dialogs.length === 1 && textOf( all( rrm.dialogs[0], 'p' )[0] ) === fillT( 'projects.file-delete-gone-text', rgone.name ) && jposts.length === 0 );
	const rrm2 = await jopen( '#projekt-00001-dateien', rwithGone );
	click( rrm2, rremoveOf( jfrows( rrm2 )[0] ) );
	check( 'Vom Server entfernen asks first, with the name of the file and in danger colours; nothing is posted before the yes', rrm2.dialogs.length === 1 && jposts.length === 0 && textOf( all( rrm2.dialogs[0], 'p' )[0] ) === fillT( 'projects.file-remove-text', 'Notizen.txt' ) && all( rrm2.dialogs[0], 'button' ).pop().classList.contains( 'danger' ) === true );
	jyes( rrm2, 0 );
	await settle();
	check( '...the yes posts the Files panel\'s files/remove with the id and nothing else, the card is read again and the toast says the file is removed', JSON.stringify( jposts ) === JSON.stringify( [ [ 'files/remove', { id : 'a00000000001' } ] ] ) && rrm2.all( 'crm-projekte/get' ).length === 2 && rrm2.toast.textContent === texts['projects.file-removed'] );

	const rall = await jopen( '#projekt-00001-dateien', rwithGone );
	const rallButton = jf( rall, 'files-remove-all' );
	check( 'above the table stands Alle Dateien des Projekts entfernen - once, before the list - where there is a file on the server', rallButton !== undefined && rallButton.textContent === texts['projects.remove-all'] && all( rall.main, '#files-remove-all' ).length === 1 );
	click( rall, rallButton );
	check( '...it asks with the number and the size of the files that are on the server - the one that was removed does not count: 2 files, 1,1 MB - and posts nothing yet', rall.dialogs.length === 1 && jposts.length === 0 && textOf( all( rall.dialogs[0], 'p' )[0] ) === fillT( 'projects.remove-all-text', 2, '1,1 MB' ) );
	jyes( rall, 0 );
	await settle();
	check( '...the yes removes them one after the other, those two and not the one that is gone, reads the card again and says how many', JSON.stringify( jposts ) === JSON.stringify( [ [ 'files/remove', { id : 'a00000000001' } ], [ 'files/remove', { id : 'a00000000002' } ] ] ) && rall.all( 'crm-projekte/get' ).length === 2 && rall.toast.textContent === fillT( 'projects.removed-all', 2 ) );
	const rone = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { list : [ rgone, jfiles[1] ] } ) ] } } );
	click( rone, jf( rone, 'files-remove-all' ) );
	check( '...one file is asked for with the sentence for one, and the size', textOf( all( rone.dialogs[0], 'p' )[0] ) === fillT( 'projects.remove-all-text-one', '51 KB' ) );
	let rrefusals = 0;
	const rthird = Object.assign( {}, jfiles[0], { id : 'a00000000003', name : 'Dritte.txt', url : '/.files/a00000000003' } );
	const rrefuse = await jopen( '#projekt-00001-dateien', Object.assign( {}, rwithGone, { 'crm-projekte/get' : function() { return [ 200, jget( { list : [ jfiles[0], rgone, jfiles[1], rthird ] } ) ] }, 'files/remove' : function( data ) { jposts.push( [ 'files/remove', data ] ); return ++rrefusals === 2 ? [ 404, { error : 'x', code : 'files_unknown' } ] : [ 200, { file : {}, changed : true } ] } } ) );
	click( rrefuse, jf( rrefuse, 'files-remove-all' ) );
	jyes( rrefuse, 0 );
	await settle();
	check( '...a refusal stops it where it is: of three files the second is refused, there is no third, the toast is the refusal and not the success - and the card is read again, to show what is there', jposts.length === 2 && rrefuse.toast.textContent === texts['error.files_unknown'] && rrefuse.all( 'crm-projekte/get' ).length === 2 );
	const rallGone = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { list : [ rgone ] } ) ] } } );
	const rlocked = await jopen( '#projekt-00001-dateien', rwithGone, { crm : true, pdf : false, elements : true, text : false, files : false } );
	check( 'there is no Alle Dateien des Projekts entfernen where every file is removed already, and neither it nor Vom Server entfernen where the account may not change the files - it does not even ask', jf( rallGone, 'files-remove-all' ) === undefined && jf( rlocked, 'files-remove-all' ) === undefined
		&& all( jf( rlocked, 'project-file-table' ), 'button' ).length === 0 && rlocked.all( 'files/remove' ).length === 0 );

	// The Übersicht: the finished projects whose files lie on the server
	const rstale = async function( stale ) { return jopen( '#uebersicht', { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], stale : stale, recent : [], settings : settings } ] } } ) };
	const rsOne = await rstale( { count : 1, bytes : 1048576, days : 60 } );
	const rsMany = await rstale( { count : 3, bytes : 5368709120, days : 60 } );
	const rsNone = await rstale( { count : 0, bytes : 0, days : 60 } );
	const rsLine = function( w ) { return all( w.main, '#needs-stale' )[0] };
	check( 'the Übersicht has the line of the finished projects whose files lie on the server, with their number, the days and the size - a line in the card that needs the owner, which leads to the projects',
		rsLine( rsOne ) !== undefined && textOf( rsLine( rsOne ) ) === fillT( 'needs.stale-one', 1, 60, '1 MB' ) && textOf( rsLine( rsMany ) ) === fillT( 'needs.stale-many', 3, 60, '5 GB' ) && all( rsLine( rsMany ), 'a' )[0].getAttribute( 'href' ) === '#projekte'
		&& all( rsLine( rsMany ), 'a' )[0].parentNode.parentNode === rsLine( rsMany ) && rsLine( rsMany ).parentNode.classList.contains( 'list-mini' ) === true && texts['needs.stale-one'] !== texts['needs.stale-many'] );
	check( '...none for no such project - and then the card says there is nothing - and one is something that waits: the sentence for nothing is gone', rsLine( rsNone ) === undefined && textOf( rsNone.main ).indexOf( texts['needs.none'] ) !== -1 && textOf( rsOne.main ).indexOf( texts['needs.none'] ) === -1 );
	click( rsMany, all( rsLine( rsMany ), 'a' )[0] );
	check( '...and the click on it leaves the done projects shown in the list', rsMany.app.state.jfilter.all === true );

	// The Übersicht: the comments nobody has settled
	const rvOverview = async function( review ) { return jopen( '#uebersicht', { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], stale : { count : 0, bytes : 0, days : 60 }, review : review, recent : [], settings : settings } ] } } ) };
	const rvMany = await rvOverview( { count : 3, files : 2 } );
	const rvFew = await rvOverview( { count : 2, files : 1 } );
	const rvOne = await rvOverview( { count : 1, files : 1 } );
	const rvNone = await rvOverview( { count : 0, files : 0 } );
	const rvLine = function( w ) { return all( w.main, '#needs-review' )[0] };
	check( 'the Übersicht has a line for the comments that nobody has settled - how many, on how many files, in the words of its number - in the card that needs the owner, with a link to the projects',
		rvLine( rvMany ) !== undefined && textOf( rvLine( rvMany ) ) === fillT( 'needs.review-many', 3, 2 ) && textOf( rvLine( rvFew ) ) === fillT( 'needs.review-few', 2, 1 ) && textOf( rvLine( rvOne ) ) === fillT( 'needs.review-one', 1, 1 )
		&& new Set( [ texts['needs.review-many'], texts['needs.review-few'], texts['needs.review-one'] ] ).size === 3 && all( rvLine( rvMany ), 'a' )[0].getAttribute( 'href' ) === '#projekte' && rvLine( rvMany ).parentNode.classList.contains( 'list-mini' ) === true );
	check( '...none where nothing is open - and then the card says there is nothing - and the line is something that waits: the sentence for nothing is gone', rvLine( rvNone ) === undefined && textOf( rvNone.main ).indexOf( texts['needs.none'] ) !== -1 && textOf( rvMany.main ).indexOf( texts['needs.none'] ) === -1 );
	click( rvMany, all( rvLine( rvMany ), 'a' )[0] );
	check( '...the click leaves the done projects shown, where the files are too, and an answer without the count has no line at all', rvMany.app.state.jfilter.all === true && rvLine( await jopen( '#uebersicht' ) ) === undefined );

	// The card: titles over their versions, the counters of the comments, the comments of a file with the box that settles each
	const cm = function( id, over ) { return Object.assign( { id : id, by : { kind : 'member', id : 'm00000000001', label : 'Lena Brandt' }, at : null, text : 'Text', parent : '', done : false, created : '2026-11-03T08:00:00Z', page : '', answers : [] }, over || {} ) };
	const thread1 = [
		cm( 'c00000000001', { at : 12.0, text : 'Zu laut <i>hier</i>\nZweite Zeile', answers : [ cm( 'c00000000003', { by : { kind : 'owner', id : '', label : '' }, text : 'Ist gemacht', done : false } ) ] } ),
		cm( 'c00000000002', { at : 65.4, text : 'Ende abrupt', by : { kind : 'member', id : 'm00000000002', label : '' } } ),
	];
	const vfile = function( id, name, title, version, versions, group, comments, over ) { return Object.assign( { id : id, name : name, ext : 'wav', size : 3000000, mime : 'audio/wav', created : '2026-10-02T10:00:00Z', visible : true, gone : '', by : { kind : 'owner', label : '' }, url : '/.files/'+ id, title : title, version : version, versions : versions, group : group, comments : comments }, over || {} ) };
	const vfiles = [
		vfile( 'v00000000001', 'Andere.wav', 'Andere', '', 1, 0, { total : 0, open : 0 } ),
		vfile( 'v00000000002', 'Song.02.wav', 'Song', '02', 2, 1, { total : 1, open : 1 } ),
		vfile( 'v00000000003', 'Song.01.wav', 'Song', '01', 2, 1, { total : 3, open : 2 } ),
		vfile( 'v00000000004', 'Lies mich.txt', 'Lies mich', '', 1, 2, null, { ext : 'txt', mime : 'text/plain' } ),
		vfile( 'v00000000005', '<b>Dub</b>.01.wav', '<b>Dub</b>', '01', 1, 3, { total : 0, open : 0 } ),
	];
	const vposts = [];
	const vanswers = function( over ) {
		return Object.assign( {
			'crm-projekte/get' : function() { return [ 200, jget( { review : true, list : vfiles } ) ] },
			'review/comments' : function( data ) { vposts.push( [ 'review/comments', data ] ); return [ 200, { file : { id : data.file, name : 'x', kind : 'audio', url : '/.files/'+ data.file, playable : true }, comments : data.file === 'v00000000003' ? JSON.parse( JSON.stringify( thread1 ) ) : [], length : 2000, notify : false, files : true } ] },
			'review/done' : function( data ) { vposts.push( [ 'review/done', data ] ); return [ 200, { comment : { id : data.id, done : true } } ] },
		}, over || {} );
	};
	const vopen = async function( over, perms ) { vposts.length = 0; return jopen( '#projekt-00001-dateien', vanswers( over ), perms === undefined ? Object.assign( {}, jperms, { review : true } ) : perms ); };
	const vtrs = function( w ) { return all( jf( w, 'project-file-table' ), 'tbody tr' ) };
	const vrows = function( w ) { return vtrs( w ).filter( function( r ) { return r.classList.contains( 'cm-row' ) === false } ) };
	const vfileRows = function( w ) { return vrows( w ).filter( function( r ) { return r.dataset.id !== undefined } ) };
	const vw = await vopen();
	check( 'with Review on the table has a column Kommentare, and the files of a title stand under a line of their own - the title, how many versions, the comments that are open over all of them - the versions with their number, the highest first; a title of one version has no line',
		all( jf( vw, 'project-file-table' ), 'thead th' ).map( textOf ).join( '|' ) === [ texts['projects.file-name'], texts['projects.file-size'], texts['projects.file-day'], texts['projects.file-by'], texts['projects.file-visible'], texts['projects.file-comments'], '' ].join( '|' )
		&& vrows( vw ).map( function( r ) { return r.dataset.id || ( r.classList.contains( 'group' ) ? 'group '+ r.dataset.group : r.className ) } ).join( ' ' ) === 'v00000000001 group 1 v00000000002 v00000000003 v00000000004 v00000000005'
		&& all( vrows( vw )[1], 'strong' )[0].textContent === 'Song' && textOf( all( vrows( vw )[1], 'td' )[0] ).indexOf( fillT( 'projects.versions', 2 ) ) !== -1 && all( vrows( vw )[1], 'td' )[0].getAttribute( 'colspan' ) === '7' );
	check( '...the number of a version is a badge beside the name - v02, v01 - a file that says none has no badge, the name of a title is text and so is the markup in a name',
		all( vfileRows( vw )[1], '.ver-no' ).map( textOf ).join() === 'v02' && all( vfileRows( vw )[2], '.ver-no' ).map( textOf ).join() === 'v01' && all( vfileRows( vw )[0], '.ver-no' ).length === 0 && all( vfileRows( vw )[4], '.ver-no' ).map( textOf ).join() === 'v01' && all( vfileRows( vw )[3], '.ver-no' ).length === 0
		&& findAll( jf( vw, 'project-file-table' ), function( n ) { return n.tagName === 'B' } ).length === 0 && textOf( vfileRows( vw )[4].children[0] ).indexOf( '<b>Dub</b>.01.wav' ) === 0 && vfileRows( vw )[1].classList.contains( 'ver' ) === true && vfileRows( vw )[0].classList.contains( 'ver' ) === false );
	const counterOf = function( w, id ) { return all( w.main, '[data-counter="'+ id+ '"]' )[0] };
	const headOf = function( w ) { return all( vrows( w )[1], '.badge' ).find( function( b ) { return b.classList.contains( 'b-portal' ) } ) };
	check( '...each audio and video file has its counter - open and all, in the words of the page - and a file that is neither has a dash; the line of a title says how many comments are open over its versions',
		textOf( counterOf( vw, 'v00000000002' ) ) === fillT( 'projects.comments-count', 1, 1 ) && textOf( counterOf( vw, 'v00000000003' ) ) === fillT( 'projects.comments-count', 2, 3 ) && textOf( counterOf( vw, 'v00000000001' ) ) === fillT( 'projects.comments-count', 0, 0 ) && counterOf( vw, 'v00000000004' ) === undefined
		&& textOf( vfileRows( vw )[3].children[5] ) === '–' && counterOf( vw, 'v00000000003' ).classList.contains( 'open' ) === true && counterOf( vw, 'v00000000001' ).classList.contains( 'open' ) === false && headOf( vw ) !== undefined && textOf( headOf( vw ) ) === fillT( 'projects.comments-open', 3 ) && headOf( vw ).hidden === false );
	const opener = function( w, id ) { return all( w.main, '[data-comments="'+ id+ '"]' )[0] };
	check( '...a file with comments to read has the button Kommentare, closed, that names the row it opens; a file that is no medium has none',
		opener( vw, 'v00000000003' ) !== undefined && opener( vw, 'v00000000003' ).getAttribute( 'aria-expanded' ) === 'false' && opener( vw, 'v00000000004' ) === undefined && all( vw.main, '#'+ opener( vw, 'v00000000003' ).getAttribute( 'aria-controls' ) )[0].hidden === true && vposts.length === 0 );
	const rowOfThread = function( w, id ) { return all( w.main, '[data-thread="'+ id+ '"]' )[0] };

	click( vw, opener( vw, 'v00000000003' ) );
	await settle();
	check( 'the button reads the comments from the Review panel\'s action review/comments with the id of the file - nothing else is posted - and opens the row under the file',
		JSON.stringify( vposts ) === JSON.stringify( [ [ 'review/comments', { file : 'v00000000003' } ] ] ) && rowOfThread( vw, 'v00000000003' ).hidden === false && opener( vw, 'v00000000003' ).getAttribute( 'aria-expanded' ) === 'true' && vtrs( vw )[vtrs( vw ).indexOf( rowOfThread( vw, 'v00000000003' ) ) - 1].dataset.id === 'v00000000003' );
	const vitems = all( rowOfThread( vw, 'v00000000003' ), '.cm' );
	check( '...each comment shows its time as m:ss, who wrote it - the name, Du for the owner, the word for a member without a name -, the day and its text with the markup in it as text; the answer stands under its comment',
		vitems.length === 3 && textOf( all( vitems[0], '.cm-at' )[0] ) === '0:12' && textOf( all( vitems[0], 'strong' )[0] ) === 'Lena Brandt' && textOf( all( vitems[0], '.cm-head .muted' )[0] ) === '03.11.2026' && all( vitems[0], '.cm-text' )[0].textContent === 'Zu laut <i>hier</i>\nZweite Zeile'
		&& findAll( rowOfThread( vw, 'v00000000003' ), function( n ) { return n.tagName === 'I' } ).length === 0 && textOf( all( vitems[1], 'strong' )[0] ) === texts['projects.by-owner'] && all( vitems[1].parentNode.parentNode, '.cm-text' )[0].textContent.indexOf( 'Zu laut' ) === 0 && vitems[1].parentNode.classList.contains( 'cm-answers' ) === true
		&& textOf( all( vitems[2], '.cm-at' )[0] ) === '1:05' && textOf( all( vitems[2], 'strong' )[0] ) === texts['projects.by-member'] && all( vitems[1], '.cm-at' ).length === 0 );
	check( '...and a box Erledigt for each comment of a member - not for the answer of the owner, which is never open - ticked for one that is done and not for one that is not',
		all( rowOfThread( vw, 'v00000000003' ), 'input' ).map( function( n ) { return n.checked } ).join() === 'false,false' && textOf( all( vitems[0], 'label' )[0] ).trim() === texts['projects.comment-done'] && all( rowOfThread( vw, 'v00000000003' ), 'input' ).filter( function( n ) { return n.type === 'checkbox' } ).length === 2 && all( vitems[1], 'input' ).length === 0 && all( vitems[1], 'label' ).length === 0 );

	vposts.length = 0;
	jtoggle( all( vitems[0], 'input' )[0], true );
	await settle();
	check( 'the box posts review/done with the file and the comment - the panel turns the mark around, so nothing more - and the counter follows at once: one comment less is open, in the row and in the line of the title',
		JSON.stringify( vposts ) === JSON.stringify( [ [ 'review/done', { file : 'v00000000003', id : 'c00000000001' } ] ] ) && all( vitems[0], 'input' )[0].checked === true && all( vitems[0], 'input' )[0].disabled === false && textOf( counterOf( vw, 'v00000000003' ) ) === fillT( 'projects.comments-count', 1, 3 )
		&& textOf( headOf( vw ) ) === fillT( 'projects.comments-open', 2 ) && counterOf( vw, 'v00000000003' ).classList.contains( 'open' ) === true );
	vposts.length = 0;
	jtoggle( all( vitems[2], 'input' )[0], true );
	await settle();
	check( '...the last open comment of a file takes its counter off the warning, and a title with none open hides its badge - the answer of the owner was never open', counterOf( vw, 'v00000000003' ).classList.contains( 'open' ) === false && textOf( counterOf( vw, 'v00000000003' ) ) === fillT( 'projects.comments-count', 0, 3 ) && textOf( headOf( vw ) ) === fillT( 'projects.comments-open', 1 ) );
	vposts.length = 0;
	click( vw, opener( vw, 'v00000000003' ) );
	check( '...the button closes the row again and reads nothing a second time, the box stays as it was set',
		rowOfThread( vw, 'v00000000003' ).hidden === true && opener( vw, 'v00000000003' ).getAttribute( 'aria-expanded' ) === 'false' && vposts.length === 0 );
	click( vw, opener( vw, 'v00000000003' ) );
	await settle();
	check( '...opened again it shows the comments it has, as the box was set', rowOfThread( vw, 'v00000000003' ).hidden === false && vposts.length === 0 && all( rowOfThread( vw, 'v00000000003' ), 'input' )[0].checked === true );

	// A refusal puts the box back and says the panel's sentence; a refused read leaves the row closed
	const vno = await vopen( { 'review/done' : function( data ) { vposts.push( [ 'review/done', data ] ); return [ 404, { error : 'x', code : 'review_unknown' } ] } } );
	click( vno, opener( vno, 'v00000000003' ) );
	await settle();
	jtoggle( all( rowOfThread( vno, 'v00000000003' ), 'input' )[0], true );
	await settle();
	check( 'a refusal of the switch puts the box back as it was, says the sentence of the code and leaves the counters alone', all( rowOfThread( vno, 'v00000000003' ), 'input' )[0].checked === false && all( rowOfThread( vno, 'v00000000003' ), 'input' )[0].disabled === false && vno.toast.classList.contains( 'bad' ) === true && vno.toast.textContent === texts['error.review_unknown']
		&& textOf( counterOf( vno, 'v00000000003' ) ) === fillT( 'projects.comments-count', 2, 3 ) );
	const vdenied = await vopen( { 'review/comments' : function( data ) { vposts.push( [ 'review/comments', data ] ); return [ 409, { error : 'x', code : 'review_off' } ] } } );
	click( vdenied, opener( vdenied, 'v00000000003' ) );
	await settle();
	check( '...a refusal of the read - the feature went off under the page - says its sentence, the row stays closed and the button is closed, and it can be tried again', rowOfThread( vdenied, 'v00000000003' ).hidden === true && opener( vdenied, 'v00000000003' ).getAttribute( 'aria-expanded' ) === 'false' && vdenied.toast.textContent === texts['error.review_off'] && vposts.length === 1 );
	click( vdenied, opener( vdenied, 'v00000000003' ) );
	await settle();
	check( '...tried again it asks again, as nothing was read', vposts.length === 2 );
	const vempty = await vopen();
	click( vempty, opener( vempty, 'v00000000001' ) );
	await settle();
	check( 'a file with no comments says so under its row, with no box', textOf( rowOfThread( vempty, 'v00000000001' ) ) === texts['projects.comments-none'] && all( rowOfThread( vempty, 'v00000000001' ), 'input' ).length === 0 );

	const vzero = await jopen( '#projekt-00001-dateien', vanswers( { 'crm-projekte/get' : function() { return [ 200, jget( { review : true, list : vfiles.map( function( f ) { return Object.assign( {}, f, { comments : f.comments === null ? null : { total : f.comments.total, open : 0 } } ) } ) } ) ] } } ), Object.assign( {}, jperms, { review : true } ) );
	check( 'a title with no open comment over its versions has no badge of open ones: it is there, hidden', headOf( vzero ) !== undefined && headOf( vzero ).hidden === true );

	// Without the permission of the Review panel the counters are there and nothing is offered; with the feature off there is no column at all
	const vlocked = await vopen( {}, Object.assign( {}, jperms, { review : false } ) );
	check( 'without the permission of the Review panel the counters can be read - the numbers come with the card - and there is no button Kommentare and no row for the comments: the read would be refused',
		counterOf( vlocked, 'v00000000003' ) !== undefined && textOf( counterOf( vlocked, 'v00000000003' ) ) === fillT( 'projects.comments-count', 2, 3 ) && all( vlocked.main, '[data-comments]' ).length === 0 && all( vlocked.main, '[data-thread]' ).length === 0 && all( vlocked.main, 'tr.cm-row' ).length === 0 );
	const voff = await jopen( '#projekt-00001-dateien', vanswers( { 'crm-projekte/get' : function() { return [ 200, jget( { review : false, list : vfiles.map( function( f ) { return Object.assign( {}, f, { comments : null } ) } ) } ) ] } } ), Object.assign( {}, jperms, { review : true } ) );
	check( 'with the feature off the column is not drawn - seven columns become six - and a title still stands over its versions', all( jf( voff, 'project-file-table' ), 'thead th' ).length === 6 && all( voff.main, '[data-counter]' ).length === 0 && all( voff.main, '[data-comments]' ).length === 0 && all( voff.main, 'tr.group' ).length === 1 && all( voff.main, 'tr.group td' )[0].getAttribute( 'colspan' ) === '6' );

	// The screen Uploads
	const urow = function( over ) { return Object.assign( { id : 'u00000000001', client_id : 1, client : 'K-0001', client_title : 'Nordlicht Records GmbH', name : 'Take 1.wav', ext : 'wav', size : 5000000, created : '2026-09-20T10:00:00Z', age : 46, by : { kind : 'member', label : 'Lena Brandt' }, gone : '' }, over || {} ) };
	const urows = [
		urow( { id : 'u00000000002', name : 'Notiz.txt', size : 1200, created : '2026-11-02T09:00:00Z', age : 3, by : { kind : 'member', label : '' } } ),
		urow( { id : 'u00000000003', client_id : 2, client : 'K-0002', client_title : 'Jonas <b>Richter</b>', name : 'Zeta <i>mix</i>.mp3', size : 2000000, created : '2026-10-26T09:00:00Z', age : 10, by : { kind : 'owner', label : '' } } ),
		urow(),
		urow( { id : 'u00000000004', name : 'Weg.wav', size : 777, created : '2026-07-01T09:00:00Z', age : 100, gone : '2026-10-30T09:00:00Z' } ),
	];
	const usummary = { count : 3, bytes : 7001200, old_count : 1, old_bytes : 5000000 };
	const ubody = function( over ) { return Object.assign( { files : true, allowed : true, zip : true, old_days : 30, limit : 400, large : 2147483648, rows : urows, summary : usummary }, over || {} ) };
	const uopen = async function( over, perms, extra ) { return jopen( '#uploads', Object.assign( { 'crm-uploads/list' : function() { return [ 200, ubody( over ) ] } }, extra || {} ), perms ); };
	const uf = function( w, id ) { return all( w.main, '#'+ id )[0] };
	const urowsOf = function( w ) { return all( uf( w, 'uploads' ), 'tbody tr' ) };
	const unamesOf = function( w ) { return urowsOf( w ).filter( function( r ) { return r.dataset.id !== undefined } ).map( function( r ) { return textOf( r.children[2] ) } ) };
	const uw = await uopen();
	check( 'the rail has Uploads between Projekte and Positionen, the hash #uploads is its screen and the rail marks it', JSON.stringify( uw.app.parse( '#uploads' ) ) === '{"screen":"uploads"}' && all( uw.nav, '[data-r="uploads"]' ).length === 1 && all( uw.nav, '[data-r="uploads"]' )[0].classList.contains( 'on' ) === true );
	check( 'the screen lists the files of the upload folders: the client with its number, the name, who sent it (the name, Du for the owner, the word for a member without a name), the size, the day and the age in days - the oldest first, and a name that is markup is text',
		all( uf( uw, 'uploads' ), 'thead th' ).map( textOf ).slice( 1 ).join( '|' ) === [ texts['uploads.client'], texts['uploads.file'], texts['uploads.by'], texts['uploads.size'], texts['uploads.day'], texts['uploads.age']+ ' ↓' ].join( '|' )
		&& unamesOf( uw ).join( '|' ) === [ 'Weg.wav'+ fillT( 'projects.file-gone', '30.10.2026' ), 'Take 1.wav', 'Zeta <i>mix</i>.mp3', 'Notiz.txt' ].join( '|' ) && urowsOf( uw ).map( function( r ) { return textOf( r.children[6] ) } ).join() === '100,46,10,3'
		&& textOf( urowsOf( uw )[1].children[1] ) === 'Nordlicht Records GmbH K-0001' && all( urowsOf( uw )[1], 'a' )[0].getAttribute( 'href' ) === '#kunde-1' && textOf( urowsOf( uw )[1].children[3] ) === 'Lena Brandt' && textOf( urowsOf( uw )[2].children[3] ) === texts['projects.by-owner'] && textOf( urowsOf( uw )[3].children[3] ) === texts['projects.by-member']
		&& textOf( urowsOf( uw )[1].children[4] ) === '4,8 MB' && textOf( urowsOf( uw )[1].children[5] ) === '20.09.2026' && findAll( uf( uw, 'uploads' ), function( n ) { return n.tagName === 'I' || n.tagName === 'B' } ).length === 0 );
	check( '...above the table one line says how many files there are, what they take and how many of them are older than 30 days - the sums of the answer, not a count of the rows - and the row that was removed has no box to tick',
		textOf( uf( uw, 'uploads-sum' ) ) === fillT( 'uploads.sum', fillT( 'uploads.files-many', 3 ), '6,7 MB', 30, fillT( 'uploads.files-one', 1 ), '4,8 MB' ) && all( urowsOf( uw )[0], 'input' )[0].disabled === true && all( urowsOf( uw )[1], 'input' )[0].disabled === false && urowsOf( uw )[0].classList.contains( 'dim' ) === true );

	// search, sort, pages
	const usearch = function( w, text ) { const field = uf( w, 'q' ); field.value = text; fire( field, 'input', { target : field } ) };
	usearch( uw, 'zeta' );
	check( 'the search looks in the client, its number, the name and the sender: "zeta" finds the file, "K-0002" the client\'s file, "lena" the files of a sender, "nichts" none - and the line says so', unamesOf( uw ).join() === 'Zeta <i>mix</i>.mp3' && ( usearch( uw, 'K-0002' ), unamesOf( uw ).join() === 'Zeta <i>mix</i>.mp3' ) && ( usearch( uw, 'lena' ), unamesOf( uw ).length === 2 )
		&& ( usearch( uw, 'nichts' ), textOf( urowsOf( uw )[0] ) === texts['uploads.none'] ) && textOf( all( uw.main, '.head .sub' )[0] ) === fillT( 'uploads.count', 0, 4 ) );
	usearch( uw, '' );
	click( uw, all( uf( uw, 'uploads' ), '[data-sort="size"]' )[0] );
	const bySize = unamesOf( uw ).join( '|' );
	click( uw, all( uf( uw, 'uploads' ), '[data-sort="size"]' )[0] );
	click( uw, all( uf( uw, 'uploads' ), '[data-sort="name"]' )[0] );
	check( 'every column sorts: size from the largest, again from the smallest, the name from A - and the sign of the column says which way', bySize === [ 'Take 1.wav', 'Zeta <i>mix</i>.mp3', 'Notiz.txt', 'Weg.wav'+ fillT( 'projects.file-gone', '30.10.2026' ) ].join( '|' ) && unamesOf( uw )[0].indexOf( 'Notiz' ) === 0 && unamesOf( uw )[3].indexOf( 'Zeta' ) === 0 );
	const manyRows = Array.from( { length : 120 }, function( _, at ) { return urow( { id : 'm'+ String( at + 1 ).padStart( 11, '0' ), name : 'Datei '+ String( at + 1 ).padStart( 3, '0' )+ '.wav', age : at, size : 10 } ) } );
	const upaged = await uopen( { rows : manyRows, summary : { count : 120, bytes : 1200, old_count : 0, old_bytes : 0 } } );
	check( 'the table has pages of fifty, as the others have: 120 files are 50, 50 and 20 - Weiter and Zurück turn them, and the choice is kept from one page to the next',
		urowsOf( upaged ).length === 50 && uf( upaged, 'next' ) !== undefined );
	all( urowsOf( upaged )[0], 'input' )[0].checked = true;
	fire( all( urowsOf( upaged )[0], 'input' )[0], 'change', { target : all( urowsOf( upaged )[0], 'input' )[0] } );
	click( upaged, uf( upaged, 'next' ) );
	const secondPage = urowsOf( upaged ).length;
	click( upaged, uf( upaged, 'next' ) );
	check( '...a box ticked on the first page is still chosen on the third: the line counts it', secondPage === 50 && urowsOf( upaged ).length === 20 && textOf( uf( upaged, 'uploads-picked' ) ) === fillT( 'uploads.picked', 1, '10 B' ) );

	// the choice, the ZIP and the deletion
	const utick = function( w, at, on ) { const box = all( urowsOf( w )[at], 'input' )[0]; box.checked = on; fire( box, 'change', { target : box } ) };
	const uzip = uf( uw, 'uploads-zip' );
	check( 'with nothing chosen the button is the ZIP of what the list shows - the files on the server, not the one that was removed - and Löschen is off', uzip.textContent === fillT( 'uploads.zip-all', 3 ) && uzip.disabled === false && uf( uw, 'uploads-delete' ).disabled === true && textOf( uf( uw, 'uploads-picked' ) ) === '' );
	click( uw, uzip );
	check( '...it loads /crm/uploads.zip with the ids of those files, as a download the page asks the browser for', uw.downloads.length === 1 && uw.downloads[0].split( '?ids=' )[0] === '/crm/uploads.zip' && uw.downloads[0].split( '?ids=' )[1].split( ',' ).sort().join() === 'u00000000001,u00000000002,u00000000003' );
	const uw2 = await uopen();
	utick( uw2, 2, true );
	utick( uw2, 3, true );
	check( 'a box on a row chooses it: the line says how many and how large, the button is the ZIP of the choice, Löschen is on - and the box of all follows', textOf( uf( uw2, 'uploads-picked' ) ) === fillT( 'uploads.picked', 2, '1,9 MB' ) && uf( uw2, 'uploads-zip' ).textContent === fillT( 'uploads.zip-chosen', 2 ) && uf( uw2, 'uploads-delete' ).disabled === false && uf( uw2, 'uploads-all' ).checked === false );
	click( uw2, uf( uw2, 'uploads-zip' ) );
	check( '...the ZIP is of the two and no other', uw2.downloads.length === 1 && uw2.downloads[0].split( '?ids=' )[1].split( ',' ).sort().join() === 'u00000000002,u00000000003' );
	const uall = uf( uw2, 'uploads-all' );
	uall.checked = true;
	fire( uall, 'change', { target : uall } );
	check( 'the box of all chooses every file of the list that is on the server - three, not the one that was removed - and unticked it takes the choice back', textOf( uf( uw2, 'uploads-picked' ) ) === fillT( 'uploads.picked', 3, '6,7 MB' ) && all( urowsOf( uw2 )[0], 'input' )[0].checked === false && all( urowsOf( uw2 )[1], 'input' )[0].checked === true && ( uall.checked = false, fire( uall, 'change', { target : uall } ), textOf( uf( uw2, 'uploads-picked' ) ) === '' ) );
	const ulimit = await uopen( { limit : 2 } );
	utick( ulimit, 1, true ); utick( ulimit, 2, true ); utick( ulimit, 3, true );
	click( ulimit, uf( ulimit, 'uploads-zip' ) );
	check( 'a choice of more files than a ZIP takes is said in a toast and loads nothing', ulimit.downloads.length === 0 && ulimit.toast.textContent === fillT( 'uploads.zip-many', 2 ) );
	const ularge = await uopen( { large : 1000000 } );
	utick( ularge, 1, true );
	check( 'a choice of 2 GB or more says that it is large - the download runs all the same', textOf( uf( ularge, 'uploads-picked' ) ).indexOf( texts['uploads.large'] ) !== -1 && uf( ularge, 'uploads-zip' ).disabled === false );
	const udel = await uopen();
	utick( udel, 1, true );
	utick( udel, 3, true );
	click( udel, uf( udel, 'uploads-delete' ) );
	check( 'Löschen asks first with the number and the size of the choice - nothing is posted before the yes', udel.dialogs.length === 1 && jposts.length === 0 && textOf( all( udel.dialogs[0], 'p' )[0] ) === fillT( 'uploads.delete-text', 2, '4,8 MB' ) && all( udel.dialogs[0], 'button' ).pop().classList.contains( 'danger' ) === true );
	jyes( udel, 0 );
	await settle();
	check( '...the yes deletes each file with the panel\'s files/delete, one after the other, and reads the list again - the choice is gone with the files', JSON.stringify( jposts.map( function( post ) { return [ post[0], post[1].id ] } ).sort() ) === JSON.stringify( [ [ 'files/delete', 'u00000000001' ], [ 'files/delete', 'u00000000002' ] ].sort() ) && udel.all( 'crm-uploads/list' ).length === 2 && udel.toast.textContent === fillT( 'uploads.deleted', 2 ) && textOf( uf( udel, 'uploads-picked' ) ) === '' );
	let udeleted = 0;
	const urefuse = await uopen( {}, undefined, { 'files/delete' : function( data ) { jposts.push( [ 'files/delete', data ] ); return ++udeleted === 2 ? [ 404, { error : 'x', code : 'files_unknown' } ] : [ 200, {} ] } } );
	utick( urefuse, 1, true ); utick( urefuse, 2, true ); utick( urefuse, 3, true );
	click( urefuse, uf( urefuse, 'uploads-delete' ) );
	jyes( urefuse, 0 );
	await settle();
	check( '...a refusal stops it: the second is refused, there is no third, the toast is the refusal - and the list is read again for what is left', jposts.length === 2 && urefuse.toast.textContent === texts['error.files_unknown'] && urefuse.all( 'crm-uploads/list' ).length === 2 );

	// A "no" to a question posts nothing and changes nothing on the screen
	const unot = function( w ) { click( w, all( w.dialogs[0], 'button' )[0] ) };
	const ularge2 = await uopen( { large : 1000000 } );
	check( 'the hint for a large download stands over the button of all as well, with nothing chosen: the ZIP of what the list shows is as large as the choice would be', textOf( uf( ularge2, 'uploads-picked' ) ) === texts['uploads.large'] && uf( ularge2, 'uploads-zip' ).disabled === false && textOf( uf( uw, 'uploads-picked' ) ) === '' );
	const uno = await uopen();
	utick( uno, 1, true );
	click( uno, uf( uno, 'uploads-delete' ) );
	unot( uno );
	await settle();
	check( 'a "no" to the question before the uploads are deleted posts nothing, reads nothing again and keeps the choice', uno.dialogs.length === 1 && jposts.length === 0 && uno.all( 'crm-uploads/list' ).length === 1 && textOf( uf( uno, 'uploads-picked' ) ) !== '' );
	const rno = await jopen( '#projekt-00001-dateien', rwithGone );
	click( rno, rremoveOf( jfrows( rno )[0] ) );
	unot( rno );
	await settle();
	check( '...and so for Vom Server entfernen of one file', rno.dialogs.length === 1 && jposts.length === 0 && rno.all( 'crm-projekte/get' ).length === 1 );
	click( rno, jf( rno, 'files-remove-all' ) );
	rno.dialogs[1] && click( rno, all( rno.dialogs[1], 'button' )[0] );
	await settle();
	check( '...and for Alle Dateien des Projekts entfernen: nothing is posted, the card is not read again and the button can be used once more', rno.dialogs.length === 2 && jposts.length === 0 && rno.all( 'crm-projekte/get' ).length === 1 && jf( rno, 'files-remove-all' ).disabled === false );

	// what the screen says where there is nothing to do
	const ulocked = await uopen( { allowed : false } );
	const uzipOff = await uopen( { zip : false } );
	const ufilesOff = await uopen( { files : false, rows : [], summary : { count : 0, bytes : 0, old_count : 0, old_bytes : 0 } } );
	const uempty = await uopen( { rows : [], summary : { count : 0, bytes : 0, old_count : 0, old_bytes : 0 } } );
	check( 'without the permission of the Files panel the list is to read: the sentence says why, there is no Löschen and the ZIP is off, since the route asks for the permission as well',
		textOf( ulocked.main ).indexOf( texts['uploads.forbidden'] ) !== -1 && uf( ulocked, 'uploads-delete' ) === undefined && uf( ulocked, 'uploads-zip' ).disabled === true && urowsOf( ulocked ).length === 4 );
	check( '...without ZipArchive the button is off and the sentence says why; with the feature off there is only that sentence; and a list with no upload says so', uf( uzipOff, 'uploads-zip' ).disabled === true && textOf( uf( uzipOff, 'uploads-zip-off' ) ) === texts['uploads.zip-off'] && uf( uzipOff, 'uploads-delete' ) !== undefined
		&& uf( ufilesOff, 'uploads' ) === undefined && textOf( ufilesOff.main ).indexOf( texts['uploads.files-off'] ) !== -1 && textOf( urowsOf( uempty )[0] ) === texts['uploads.empty'] );
	const ufail = await jopen( '#uploads', { 'crm-uploads/list' : function() { return [ 500, { error : 'x', code : 'crm_documents_database' } ] } } );
	check( 'a list that cannot be read says so, with the way to try again, and draws no table', uf( ufail, 'uploads' ) === undefined && textOf( ufail.main ).indexOf( texts['error.crm_documents_database'] ) !== -1 && all( ufail.main, 'button' ).map( textOf ).includes( texts['retry'] ) );
	const jrenStale = await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { allowed : false } ) ] } } );
	click( jrenStale, jitem( jrenStale, texts['projects.edit'] ) );
	type( jf( jrenStale, 'f-title' ), 'Neu', false );
	jsubmit( jf( jrenStale, 'project-info' ) );
	await settle();
	check( '...and so is the rename of the folder: an answer that says no permission renames nothing', jposts.length === 1 && jposts[0][0] === 'elements/save' );
	const jnoel = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { folder : '', files : null } ), folder : null, list : [] } ) ] } }, { crm : true, pdf : false, elements : false, text : false, files : true } );
	click( jnoel, jf( jnoel, 'folder-make' ) || all( jnoel.main, 'h1' )[0] );
	await settle();
	check( 'Ordner anlegen is offered only to an account that can write the folder\'s id into the project as well - the Elements permission: without it no button (a folder would be made and be nobody\'s), the sentence says what is needed, and nothing is posted', jf( jnoel, 'folder-make' ) === undefined && textOf( jf( jnoel, 'project-files' ) ).indexOf( texts['projects.folder-elements'] ) !== -1 && jnoel.all( 'files/folder/add' ).length === 0 );
	const jnofolder = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { folder : '', files : null } ), folder : null, list : [] } ) ] } } );
	check( 'a project with no folder says so and offers Ordner anlegen - without the permission of the Files panel only the sentences', textOf( jf( jnofolder, 'project-files' ) ).indexOf( texts['projects.folder-none'] ) !== -1 && textOf( jf( jnofolder, 'folder-make' ) ) === texts['projects.folder-make'] && jf( jnofolder, 'project-file-table' ) === undefined
		&& jf( await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { folder : null, list : [] } ) ] } }, { crm : true, pdf : false, elements : true, text : false, files : false } ), 'folder-make' ) === undefined );
	click( jnofolder, jf( jnofolder, 'folder-make' ) );
	await settle();
	check( '...the button makes the folder in the group of the client\'s number, named as the project, and writes its id into the project; the card is read again', JSON.stringify( jposts ) === JSON.stringify( [ [ 'files/folder/add', { group : 'k-0001', name : 'Album 2027' } ], [ 'elements/save', { type : 'projekte', uri : '00001', locale : 'de_DE', isNew : false, fields : { folder : 'f00000000099' } } ] ] ) && jnofolder.all( 'crm-projekte/get' ).length === 2 && jnofolder.toast.textContent === texts['projects.folder-made'] );
	const jgone2 = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { folder : null, list : [] } ) ] } } );
	check( 'a folder id that Files does not have any more is told apart from none: its own sentence, and the button makes a new one', textOf( jf( jgone2, 'project-files' ) ).indexOf( texts['projects.folder-gone'] ) !== -1 && jf( jgone2, 'folder-make' ) !== undefined );
	const jbad = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { folder : '' } ), folder : null, list : [] } ) ] } , 'files/folder/add' : function() { return [ 400, { error : 'x', code : 'files_group' } ] } } );
	click( jbad, jf( jbad, 'folder-make' ) );
	await settle();
	check( '...a folder the panel refuses is said with its sentence, and the button is on again - the project is untouched', jbad.toast.classList.contains( 'bad' ) === true && jbad.toast.textContent.indexOf( texts['error.files_group'] ) !== -1 && jf( jbad, 'folder-make' ).disabled === false && jbad.all( 'elements/save' ).length === 0 );

	// --- the upload, in chunks
	const jup = await jopen( '#projekt-00001-dateien' );
	const jfile = function( name, size ) { return { name : name, size : size, slice : function( from, to ) { return { size : to - from, from : from, to : to } } } };
	check( 'the upload block asks the Files panel for the limits once, in the group of the client, and has a file input that takes several files and the button Hochladen', jup.all( 'files/list' ).length === 1 && JSON.stringify( jup.last( 'files/list' ).data ) === '{"group":"k-0001"}' && jf( jup, 'up-input' ).type === 'file' && jf( jup, 'up-input' ).getAttribute( 'multiple' ) === 'multiple' && textOf( jf( jup, 'up-send' ) ) === texts['projects.up-send'] );
	click( jup, jf( jup, 'up-send' ) );
	check( '...nothing chosen says so and posts nothing', jup.all( 'files/upload' ).length === 0 && jup.toast.textContent === texts['projects.up-none'] );
	jf( jup, 'up-input' ).files = [ jfile( 'lang.wav', 2500 ), jfile( 'klein.txt', 400 ), jfile( 'leer.txt', 0 ), jfile( 'gross.zip', 9000 ) ];
	click( jup, jf( jup, 'up-send' ) );
	await settle();
	const jchunks = jup.all( 'files/upload' );
	check( 'a file is cut into chunks of the size the panel says, sent one after the other to the Files panel\'s upload as a multipart post with the bytes as the part chunk: 2500 bytes in chunks of 1000 are three posts', jchunks.length === 4 && jchunks.slice( 0, 3 ).map( function( c ) { return c.data.index+ '/'+ c.data.count } ).join() === '0/3,1/3,2/3' && jchunks.every( function( c ) { return c.form === true && c.part === 'chunk' } )
		&& jchunks.slice( 0, 3 ).map( function( c ) { return c.file.from+ '-'+ c.file.to } ).join() === '0-1000,1000-2000,2000-2500' );
	check( '...each chunk carries notify, false at first since the box is not ticked', jchunks.every( function( c ) { return c.data.notify === false } ) );
	check( '...each carries the folder of the project, the name and the size, the upload id the first answer gave (none for the first chunk) and visible', jchunks[0].data.folder === 'f00000000001' && jchunks[0].data.name === 'lang.wav' && jchunks[0].data.size === 2500 && jchunks[0].data.upload === '' && jchunks[1].data.upload === '0123456789abcdef' && jchunks[2].data.upload === '0123456789abcdef' && jchunks[0].data.visible === true
		&& jchunks[3].data.name === 'klein.txt' && jchunks[3].data.index === 0 && jchunks[3].data.count === 1 && jchunks[3].data.upload === '' && jchunks.every( function( c ) { return c.init.headers['X-CSRF-Token'] === 'token-1' && c.init.headers['Content-Type'] === undefined } ) );
	check( '...an empty file and a file over the largest size (5000) are refused before a byte is sent, each in its row, and the others go on', jchunks.filter( function( c ) { return c.data.name === 'leer.txt' || c.data.name === 'gross.zip' } ).length === 0 && all( jf( jup, 'up-list' ), 'li' ).map( function( li ) { return li.dataset.state } ).join() === 'done,done,failed,failed' );
	check( '...the progress is drawn per file: a row with the name, the state in words and a bar that reached 100; the failed rows say why', all( jf( jup, 'up-list' ), 'li' ).length === 4 && Number( all( all( jf( jup, 'up-list' ), 'li' )[0], 'progress' )[0].value ) === 100 && textOf( all( all( jf( jup, 'up-list' ), 'li' )[0], 'small' )[0] ) === texts['projects.up-done'] && textOf( all( all( jf( jup, 'up-list' ), 'li' )[2], 'small' )[0] ) === texts['projects.up-empty']
		&& textOf( all( all( jf( jup, 'up-list' ), 'li' )[3], 'small' )[0] ) === texts['projects.up-large'].replace( '%s', '4,9 KB' ) && jf( jup, 'up-send' ).disabled === false && jf( jup, 'up-input' ).value === '' );
	check( '...when all are through the toast counts the files that went in and the card is read again', jup.toast.textContent === texts['projects.uploaded'].replace( '%s', '2' ) + ' '+ texts['projects.not-informed'] && jup.all( 'crm-projekte/get' ).length === 2 );
	const jup4 = await jopen( '#projekt-00001-dateien' );
	jf( jup4, 'up-input' ).files = [ jfile( 'genau.txt', 2000 ) ];
	click( jup4, jf( jup4, 'up-send' ) );
	await settle();
	check( 'a file that is a whole number of chunks long is that many posts and no empty one after them: 2000 bytes in chunks of 1000 are two', jup4.all( 'files/upload' ).map( function( c ) { return c.data.index+ '/'+ c.data.count+ ':'+ ( c.file.to - c.file.from ) } ).join() === '0/2:1000,1/2:1000' );
	const jup2 = await jopen( '#projekt-00001-dateien', { 'files/upload' : function( data ) { return data.name === 'voll.txt' ? [ 400, { error : 'x', code : 'files_quota', params : [ 10 ] } ] : [ 200, { id : 'b00000000002', name : data.name, size : data.size } ] } } );
	jf( jup2, 'up-input' ).files = [ jfile( 'voll.txt', 3000 ), jfile( 'danach.txt', 10 ) ];
	click( jup2, jf( jup2, 'up-send' ) );
	await settle();
	check( 'a chunk the panel refuses stops that file with the sentence of its code (the other chunks of it are not sent); the next file goes on', jup2.all( 'files/upload' ).map( function( c ) { return c.data.name+ c.data.index } ).join() === 'voll.txt0,danach.txt0' && all( jf( jup2, 'up-list' ), 'li' ).map( function( li ) { return li.dataset.state } ).join() === 'failed,done'
		&& textOf( all( all( jf( jup2, 'up-list' ), 'li' )[0], 'small' )[0] ) === texts['error.files_quota'].replace( '%s', '10' ) && jup2.toast.textContent === texts['projects.uploaded-one'] + ' '+ texts['projects.not-informed'] );
	const jinf = await jopen( '#projekt-00001-dateien' );
	jf( jinf, 'project-notify' ).checked = true;
	jf( jinf, 'up-input' ).files = [ { name : 'Mail.txt', size : 300, slice : function( from, to ) { return { size : to - from, from : from, to : to } } } ];
	click( jinf, jf( jinf, 'up-send' ) );
	await settle();
	check( 'with the box ticked the chunks post notify false and one files/notify at the end mails the upload - the id of the file and the page of the project with the tab Dateien, so that the mail leads there and not to the start page - and the toast says that the accounts of the customer are informed', jinf.all( 'files/upload' ).length === 1 && jinf.all( 'files/upload' )[0].data.notify === false
		&& jinf.all( 'files/notify' ).length === 1 && JSON.stringify( jinf.all( 'files/notify' )[0].data ) === JSON.stringify( { ids : [ 'b00000000001' ], notify : true, page : '/portal/projekte?projekt=00001&tab=dateien' } ) && jinf.toast.textContent === texts['projects.uploaded-one']+ ' '+ texts['projects.informed-many'].replace( '%s', '2' ) );
	const jmany = await jopen( '#projekt-00001-dateien', { 'files/upload' : function( data ) { return data.name === 'leer.txt' ? [ 400, { error : 'x', code : 'files_quota', params : [ 10 ] } ] : [ 200, { id : 'c'+ data.name.charCodeAt( 0 ).toString().padStart( 11, '0' ), name : data.name, size : data.size } ] } } );
	jf( jmany, 'project-notify' ).checked = true;
	jf( jmany, 'up-input' ).files = [ jfile( 'a.txt', 10 ), jfile( 'leer.txt', 10 ), jfile( 'b.txt', 10 ), jfile( 'c.txt', 10 ) ];
	click( jmany, jf( jmany, 'up-send' ) );
	await settle();
	check( 'several files are one mail: after the last one a single files/notify names the ids of the files that went in, in the order they were sent - the one the panel refused is not among them - and the toast counts them', jmany.all( 'files/upload' ).length === 4 && jmany.all( 'files/upload' ).every( function( call ) { return call.data.notify === false } ) && jmany.all( 'files/notify' ).length === 1
		&& JSON.stringify( jmany.all( 'files/notify' )[0].data.ids ) === JSON.stringify( [ 'c00000000097', 'c00000000098', 'c00000000099' ] ) && jmany.all( 'files/notify' )[0].data.notify === true && jmany.toast.textContent === texts['projects.uploaded'].replace( '%s', '3' )+ ' '+ texts['projects.informed-many'].replace( '%s', '2' ) );
	const jlone = await jopen( '#projekt-00001-dateien', { 'files/upload' : function() { return [ 400, { error : 'x', code : 'files_quota', params : [ 10 ] } ] } } );
	jf( jlone, 'project-notify' ).checked = true;
	jf( jlone, 'up-input' ).files = [ jfile( 'a.txt', 10 ) ];
	click( jlone, jf( jlone, 'up-send' ) );
	await settle();
	check( '...and where no file went in nobody is mailed: no files/notify', jlone.all( 'files/upload' ).length === 1 && jlone.all( 'files/notify' ).length === 0 );
	const jnone = await jopen( '#projekt-00001-dateien', { 'files/notify' : function() { return [ 200, { mailed : 0 } ] }, 'files/upload' : function( data ) { return [ 200, data.index + 1 < data.count ? { upload : '0123456789abcdef', index : data.index } : { id : 'b00000000001', name : data.name, size : data.size, mailed : 0 } ] }, 'files/save' : function( data ) { jposts.push( [ 'files/save', data ] ); return [ 200, { file : {}, mailed : 0 } ] } } );
	jf( jnone, 'project-notify' ).checked = true;
	jf( jnone, 'up-input' ).files = [ { name : 'Nobody.txt', size : 300, slice : function( from, to ) { return { size : to - from, from : from, to : to } } } ];
	click( jnone, jf( jnone, 'up-send' ) );
	await settle();
	check( 'the toast of an upload says what the server says: with the box ticked and no mail delivered (a client without an account, a refused mail) the members are not said to be informed', jnone.all( 'files/notify' ).length === 1 && jnone.toast.textContent === texts['projects.uploaded-one']+ ' '+ texts['projects.informed-failed'] );
	jtoggle( all( jfrows( jnone )[1], 'input' )[0], true );
	await settle();
	check( '...and so does the toast of a switch to visible', jnone.toast.textContent === texts['projects.shown-not-informed'] );
	// The sentence over the files while an offer waits for the order
	const waitNote = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { waiting : { id : 31, number : 'A-2026-0030' } } ) ] } } );
	const waitNone = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { waiting : null } ) ] } } );
	check( 'while an offer waits for the order the tab Dateien says the files are shut until the customer confirms it - the number of the offer in the sentence, instead of the one about listening - and says nothing of it where none waits',
		all( waitNote.main, '#project-waiting-note' ).length === 1 && textOf( all( waitNote.main, '#project-waiting-note' )[0] ) === texts['projects.waiting-note'].replace( '%s', 'A-2026-0030' ) && all( waitNote.main, '#project-download-note' ).length === 0
		&& all( waitNone.main, '#project-waiting-note' ).length === 0 && all( waitNone.main, '#project-download-note' ).length === 1 && texts['projects.waiting-note'].split( '%s' ).length === 2 );
	check( 'the words of the release and of the send say E-Mail as the others do: no bare Mail in them', Object.keys( texts ).filter( function( key ) { return /^(release|send)\./.test( key ) } ).every( function( key ) { return /(?<!E-)\bMails?\b/.test( texts[key] ) === false } ) );
	// --- the upload finds the size of a request the web server takes: a chunk answered 413 is sent again in halves
	const jbig = function( takes, extra ) {
		return Object.assign( {
			'files/list' : function() { return [ 200, { groups : [ 'k-0001' ], group : 'k-0001', folders : [], files : [], types : [ 'mp3', 'txt' ], limits : { chunk : 1048576, max : 5242880, php : 0, quota : 0, used : 0 } } ] },
			'files/upload' : function( data, file ) { return file.size > takes ? [ 413, null ] : [ 200, data.index + 1 < data.count ? { upload : 'abcdef0123456789', index : data.index } : { id : 'b00000000005', name : data.name, size : data.size } ] },
		}, extra );
	};
	const jshape = function( c ) { return c.data.name+ ' '+ c.data.index+ '/'+ c.data.count+ ':'+ ( c.file.to - c.file.from )+ ':'+ c.data.upload };
	const jsay = function( w, at ) { return textOf( all( all( jf( w, 'up-list' ), 'li' )[at], 'small' )[0] ) };
	const jhalf = await jopen( '#projekt-00001-dateien', jbig( 300000 ) );
	jf( jhalf, 'up-input' ).files = [ jfile( 'album.mp3', 2500000 ), jfile( 'bonus.mp3', 1500000 ) ];
	click( jhalf, jf( jhalf, 'up-send' ) );
	await settle();
	const jfirsts = jhalf.all( 'files/upload' ).filter( function( c ) { return c.data.index === 0 } );
	check( 'a chunk the web server answers 413 (no body at all) starts the file again from its first chunk with a new upload (no id), in chunks of half the size, and again until a size passes: 1048576, 524288, 262144 for the first file',
		jfirsts.slice( 0, 3 ).map( jshape ).join() === 'album.mp3 0/3:1048576:,album.mp3 0/5:524288:,album.mp3 0/10:262144:' );
	check( '...the file is whole in the end (ten chunks of the size that passed, the last with what is left) and done; the broken uploads are not continued', jhalf.all( 'files/upload' ).filter( function( c ) { return c.data.name === 'album.mp3' && c.data.count === 10 } ).map( function( c ) { return c.data.index } ).join() === '0,1,2,3,4,5,6,7,8,9'
		&& jhalf.all( 'files/upload' ).filter( function( c ) { return c.data.name === 'album.mp3' && c.data.count !== 10 && c.data.index > 0 } ).length === 0 && all( jf( jhalf, 'up-list' ), 'li' )[0].dataset.state === 'done' );
	check( '...the working size is kept for the next file, which starts at it and is not refused: 1500000 bytes in chunks of 262144 are six', jhalf.all( 'files/upload' ).filter( function( c ) { return c.data.name === 'bonus.mp3' } ).map( jshape ).join() === [ 0, 1, 2, 3, 4, 5 ].map( function( n ) { return 'bonus.mp3 '+ n+ '/6:'+ ( n < 5 ? 262144 : 1500000 - 5 * 262144 )+ ':'+ ( n === 0 ? '' : 'abcdef0123456789' ) } ).join() );
	check( '...the row said the parts were made smaller while it was at it - the word is its own - and the toast counts both files', texts['projects.up-smaller'] !== undefined && texts['projects.up-smaller'] !== texts['projects.sending'] && all( jf( jhalf, 'up-list' ), 'li' ).map( function( li ) { return li.dataset.state } ).join() === 'done,done' && jhalf.toast.textContent === texts['projects.uploaded'].replace( '%s', '2' ) + ' '+ texts['projects.not-informed'] );

	// the word in the line is seen while the file is under way: a request held back after the first 413
	const jmid = hold();
	let jtoo = 0;
	const jwatch = await jopen( '#projekt-00001-dateien', jbig( 300000, { 'files/upload' : function( data, file ) { if( data.index > 0 && file.size > 300000 ) { jtoo++; return [ 413, null ]; } return file.size > 300000 ? [ 200, { upload : 'abcdef0123456789', index : data.index } ] : jmid.promise; } } ) );
	jf( jwatch, 'up-input' ).files = [ jfile( 'slow.mp3', 2500000 ) ];
	click( jwatch, jf( jwatch, 'up-send' ) );
	await settle();
	check( 'while the file is on its way again (its first chunk of the third try is out, the second chunk of the two tries before was refused) the line says the parts were made smaller, the bar is at the start and the state is sending', jtoo === 2 && jsay( jwatch, 0 ) === texts['projects.up-smaller'] && Number( all( jf( jwatch, 'up-list' ), 'progress' )[0].value ) === 0 && all( jf( jwatch, 'up-list' ), 'li' )[0].dataset.state === 'sending' );
	check( '...every try began with no upload id, though the chunks before the refusal had been answered with one', jwatch.all( 'files/upload' ).filter( function( c ) { return c.data.index === 0 } ).length === 3 && jwatch.all( 'files/upload' ).filter( function( c ) { return c.data.index === 0 } ).every( function( c ) { return c.data.upload === '' } ) );
	jmid.release( [ 200, { id : 'b00000000006', name: 'slow.mp3', size : 2500000 } ] );
	await settle();

	// a file shorter than the chunk is halved by its own length: 200000 bytes sent in one chunk, refused above 150000, go in two of 131072
	const jshort = await jopen( '#projekt-00001-dateien', jbig( 150000 ) );
	jf( jshort, 'up-input' ).files = [ jfile( 'short.mp3', 200000 ) ];
	click( jshort, jf( jshort, 'up-send' ) );
	await settle();
	check( 'a file shorter than the chunk is halved by its own length, not by the chunk: 200000 bytes refused in one piece go on in two chunks of 131072 at once', jshort.all( 'files/upload' ).map( function( c ) { return c.data.index+ '/'+ c.data.count+ ':'+ ( c.file.to - c.file.from ) } ).join() === '0/1:200000,0/2:131072,1/2:68928' && all( jf( jshort, 'up-list' ), 'li' )[0].dataset.state === 'done' );

	// the endpoint's own 413, post_too_large (php dropped the body), is the same sign
	const jphp = await jopen( '#projekt-00001-dateien', jbig( 300000, { 'files/upload' : function( data, file ) { return file.size > 300000 ? [ 413, { error : 'x', code : 'post_too_large', params : [ 2 ] } ] : [ 200, data.index + 1 < data.count ? { upload : 'abcdef0123456789', index : data.index } : { id : 'b00000000007', name : data.name, size : data.size } ]; } } ) );
	jf( jphp, 'up-input' ).files = [ jfile( 'php.mp3', 1000000 ) ];
	click( jphp, jf( jphp, 'up-send' ) );
	await settle();
	check( 'the 413 post_too_large the endpoint answers when php dropped the body is the same sign: the file is sent again in halves and done', jphp.all( 'files/upload' ).filter( function( c ) { return c.data.index === 0 } ).map( function( c ) { return c.data.count } ).join() === '1,2,4' && all( jf( jphp, 'up-list' ), 'li' )[0].dataset.state === 'done' );

	// at the floor
	const jfloor = await jopen( '#projekt-00001-dateien', jbig( 10 ) );
	jf( jfloor, 'up-input' ).files = [ jfile( 'never.mp3', 2500000 ), jfile( 'ok.txt', 5 ) ];
	click( jfloor, jf( jfloor, 'up-send' ) );
	await settle();
	check( 'a server that takes nothing is tried at 1048576, 524288, 262144 and 131072, and no further - the file fails with the sentence that names the cause, which is not the generic one nor the one of the code', jfloor.all( 'files/upload' ).filter( function( c ) { return c.data.name === 'never.mp3' } ).map( function( c ) { return c.file.to - c.file.from } ).join() === '1048576,524288,262144,131072'
		&& all( jf( jfloor, 'up-list' ), 'li' )[0].dataset.state === 'failed' && jsay( jfloor, 0 ) === texts['projects.up-floor'] && texts['projects.up-floor'] !== texts['projects.up-failed'] && texts['projects.up-floor'] !== texts['error.post_too_large']
		&& jfloor.all( 'files/upload' ).filter( function( c ) { return c.data.name === 'ok.txt' } ).length === 1 );

	// at the floor a 413 that carries a code with a sentence says that sentence
	const jcode = await jopen( '#projekt-00001-dateien', jbig( 10, { 'files/upload' : function() { return [ 413, { error : 'x', code : 'post_too_large', params : [ 2 ] } ] } } ) );
	jf( jcode, 'up-input' ).files = [ jfile( 'tiny.txt', 10 ) ];
	click( jcode, jf( jcode, 'up-send' ) );
	await settle();
	check( 'a 413 at the floor that names a code with a sentence of its own (the endpoint\'s post_too_large) says that sentence, not the one of the web server', jcode.all( 'files/upload' ).length === 1 && jsay( jcode, 0 ) === texts['error.post_too_large'].replace( '%s', '2' ) && jsay( jcode, 0 ) !== texts['projects.up-floor'] );

	// a refusal that is not a 413 is not halved
	for( const refusal of [ [ 400, { error : 'x', code : 'files_quota', params : [ 10 ] } ], [ 429, { error : 'x', code : 'files_limit' } ], [ 500, { error : 'x' } ] ] ) {
		const jplain = await jopen( '#projekt-00001-dateien', jbig( 10, { 'files/upload' : function() { return refusal } } ) );
		jf( jplain, 'up-input' ).files = [ jfile( 'plain.mp3', 2500000 ) ];
		click( jplain, jf( jplain, 'up-send' ) );
		await settle();
		check( 'a '+ refusal[0]+ ' is not a sign of a web server limit: one request, the file fails, and the line does not say the parts were made smaller', jplain.all( 'files/upload' ).length === 1 && all( jf( jplain, 'up-list' ), 'li' )[0].dataset.state === 'failed' && jsay( jplain, 0 ) !== texts['projects.up-smaller'] );

	// === /crm im Alltag, the screens of the projects and the uploads
	await everydayProjectChecks();

	/**
	 *	The upload with its drop area and the sentence about the mail, the uploads in the singular, the column of the open comments and the filter by client of the projects
	 *
	 *	@return		{Promise}
	 */
	async function everydayProjectChecks() {

		const fill = function( key, ...values ) { let at = 0; return texts[key].replace( /%s/g, function() { return String( values[at++] ?? '' ) } ) };
		const fireOn = function( node, name, extra ) { let prevented = false; fire( node, name, Object.assign( { type : name, target : node, preventDefault : function() { prevented = true }, stopPropagation : function() {} }, extra || {} ) ); return prevented; };

		// --- the upload: a drop area, the sentence about the mail
		const dropper = await jopen( '#projekt-00001-dateien' );
		const zone = jf( dropper, 'up-drop' );
		check( 'the upload has an area to drop files on, with the field and the button inside it: dragging over it marks it, and the drop keeps the browser from opening the file', zone !== undefined && jf( dropper, 'up-input' ).parentNode.parentNode === zone && jf( dropper, 'up-send' ).parentNode.parentNode === zone && fireOn( zone, 'dragover' ) === true && zone.classList.contains( 'over' ) === true && ( fireOn( zone, 'dragleave' ), zone.classList.contains( 'over' ) === false ) );
		fireOn( zone, 'drop', { dataTransfer : { files : [ jfile( 'gezogen.wav', 400 ) ] } } );
		await settle();
		check( '...a drop uploads the files as the button does - the same chunks, the same toast - and the area is not marked any more', dropper.all( 'files/upload' ).map( function( c ) { return c.data.name } ).join() === 'gezogen.wav' && zone.classList.contains( 'over' ) === false && dropper.toast.textContent.indexOf( texts['projects.uploaded-one'] ) === 0 );
		const empty = await jopen( '#projekt-00001-dateien' );
		fireOn( jf( empty, 'up-drop' ), 'drop', { dataTransfer : { files : [] } } );
		await settle();
		check( '...a drop of nothing uploads nothing and says to choose the files first', empty.all( 'files/upload' ).length === 0 && empty.toast.textContent === texts['projects.up-none'] );
		const sentenceOf = async function( client, mailed, ticked ) {
			const w = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { client : client } ) ] }, 'files/notify' : function() { return [ 200, { mailed : mailed } ] } } );
			jf( w, 'project-notify' ).checked = ticked;
			jf( w, 'up-input' ).files = [ jfile( 'neu.txt', 100 ) ];
			click( w, jf( w, 'up-send' ) );
			await settle();
			return w.toast.textContent.slice( texts['projects.uploaded-one'].length + 1 );
		};
		check( 'the toast of an upload names how many accounts the mail went to - one, or the number - and adds that the customer has not entered the portal yet where no account has', await sentenceOf( jclient1, 1, true ) === texts['projects.informed-one'] && await sentenceOf( jclient1, 2, true ) === fill( 'projects.informed-many', 2 )
			&& await sentenceOf( Object.assign( {}, jclient1, { signed_accounts : 0 } ), 2, true ) === fill( 'projects.informed-many', 2 )+ ' '+ texts['projects.informed-unseen'] );
		check( '...and why none went where none did: the box was not ticked, the customer has no account, the mail did not go out', await sentenceOf( jclient1, 0, false ) === texts['projects.not-informed'] && await sentenceOf( Object.assign( {}, jclient1, { active_accounts : 0, signed_accounts : 0 } ), 0, true ) === texts['projects.informed-none'] && await sentenceOf( jclient1, 0, true ) === texts['projects.informed-failed'] );
		const preset = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { notify : true } ) ] } } );
		const unticked = await jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { notify : false } ) ] } } );
		check( 'the box of the upload starts at what the card says (the setting of the CRM): ticked for true, not for false', jf( preset, 'project-notify' ).checked === true && jf( unticked, 'project-notify' ).checked === false );

		// --- the lists: files in the singular, the comments, the client
		const one = await uopen( { summary : { count : 1, bytes : 1200, old_count : 0, old_bytes : 0 } } );
		check( 'the head of the uploads says 1 Datei, not 1 Dateien - and keeps the plural for the others', textOf( uf( one, 'uploads-sum' ) ) === fill( 'uploads.sum', fill( 'uploads.files-one', 1 ), '1,2 KB', 30, fill( 'uploads.files-many', 0 ), '0 B' ) && fill( 'uploads.files-one', 1 ) === '1 Datei' && fill( 'uploads.files-many', 3 ) === '3 Dateien' );
		const commented = await jopen( '#projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects: [ jrow( { id : '00003', comments : 3, comment_files : 2 } ), jrow( { id : '00001', comments : 1, comment_files : 1 } ), jrow( { id : '00004', comments : 0, comment_files : 0 } ), jrow( { id : '00005', comments : null, comment_files : null } ), jrow( { id : '00006', comments : 2, comment_files : 1 } ) ] } ) ] } } );
		const commentCells = jrows( commented ).map( function( tr ) { return tr.children[5] } );
		check( 'the list of the projects has the column Kommentare offen: the number, in the warning colour where there are some, with the count of files in its title - a stroke where Review does not run', all( jf( commented, 'projects' ), 'thead th' ).map( textOf )[5] === texts['projects.column-comments'] && commentCells.map( textOf ).join( '|' ) === '3|1|0|–|2'
			&& commentCells[0].title === fill( 'projects.open-comments-many', 3, 2 ) && commentCells[4].title === fill( 'projects.open-comments-few', 2 ) && commentCells[1].title === texts['projects.open-comments-one'] && commentCells[2].title === texts['projects.open-comments-none'] && all( commentCells[0], '.open' ).length === 1 && all( commentCells[2], '.open' ).length === 0 );
		const filtered = await jopen( '#projekte' );
		const clientSelect = jf( filtered, 'p-client' );
		check( 'the list has a filter by client, the clients of the list of the clients as options with All first', clientSelect !== undefined && clientSelect.tagName === 'SELECT' && all( clientSelect, 'option' )[0].value === '' && all( clientSelect, 'option' ).length === kclients.length + 1 && filtered.all( 'crm-clients/list' ).length === 1 );
		const allIds = jrows( filtered ).map( function( tr ) { return tr.dataset.id } ).join();
		clientSelect.value = '2';
		fireOn( clientSelect, 'change', { target : clientSelect } );
		check( '...a client shows its projects alone, without asking the server again, and All shows them all again', jrows( filtered ).every( function( tr ) { return jprojects.find( function( one ) { return one.id === tr.dataset.id } ).client_id === 2 } ) && jrows( filtered ).length < allIds.split( ',' ).length && filtered.all( 'crm-projekte/list' ).length === 1
			&& ( clientSelect.value = '', fireOn( clientSelect, 'change', { target : clientSelect } ), jrows( filtered ).map( function( tr ) { return tr.dataset.id } ).join() === allIds ) );
		const nofilter = await jopen( '#projekte', { 'crm-clients/list' : function() { return [ 500, { error : 'x' } ] } } );
		check( '...a list of clients that cannot be read leaves the filter out and the list stays', jf( nofilter, 'p-client' ) === undefined && jrows( nofilter ).length > 0 );
	}
	}

	// a dropped connection is not a 413
	const jdrop = await jopen( '#projekt-00001-dateien', jbig( 10, { 'files/upload' : function() { return 'offline' } } ) );
	jf( jdrop, 'up-input' ).files = [ jfile( 'gone.mp3', 2500000 ) ];
	click( jdrop, jf( jdrop, 'up-send' ) );
	await settle();
	check( 'a connection that dropped (status 0) is not a 413: the file fails as before, with the sentence of the offline code, and is not sent again', jdrop.all( 'files/upload' ).length === 1 && jsay( jdrop, 0 ) === texts['error.offline'] && all( jf( jdrop, 'up-list' ), 'li' )[0].dataset.state === 'failed' );

	const jholdup = hold();
	const jup3 = await jopen( '#projekt-00001-dateien', { 'files/upload' : function() { return jholdup.promise } } );
	jf( jup3, 'up-input' ).files = [ jfile( 'warten.txt', 10 ) ];
	click( jup3, jf( jup3, 'up-send' ) );
	check( 'while a file is on its way the button is off and the bar is not full', jf( jup3, 'up-send' ).disabled === true && Number( all( jf( jup3, 'up-list' ), 'progress' )[0].value ) === 0 && all( jf( jup3, 'up-list' ), 'li' )[0].dataset.state === 'sending' );
	jup3.location.hash = '#projekte';
	await settle();
	jholdup.release( [ 200, { id : 'b00000000003', name : 'warten.txt', size : 10 } ] );
	await settle();
	check( '...a person who has left the screen keeps what is on its way: the file arrives and the toast says so, but the card is not read again over the screen they are on', jup3.toast.textContent === texts['projects.uploaded-one'] + ' '+ texts['projects.not-informed'] && jup3.all( 'crm-projekte/get' ).length === 1 && jup3.location.hash === '#projekte' );

	// --- the Kundenkarte: the tab Projekte
	const jkc = await jopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : kclient, accounts : kaccounts } ] }, 'crm-documents/list' : function() { return [ 200, { documents : kdocs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } );
	check( 'the card of a client asks crm-projekte/list for the client\'s projects, by its id; the tabs are Belege, Projekte (with the count), Portal and Notizen', JSON.stringify( jkc.last( 'crm-projekte/list' ).data ) === '{"client":"1"}' && all( jkc.main, '.tabs button' ).map( textOf ).join( '|' ) === [ texts['ctab.belege']+ '7', texts['ctab.projekte']+ '2', texts['ctab.portal'], texts['ctab.notizen'] ].join( '|' ) );
	click( jkc, jtab( jkc, 'projekte' ) );
	const jkrows = all( jf( jkc, 'client-projects' ), 'tbody tr' );
	check( '...the tab lists them, the newest first as the server has them, with the day, the documents and the state - the done ones greyed - and the title as the link to the card', jkrows.length === 2 && jkrows.map( function( tr ) { return tr.dataset.id } ).join() === '00002,00001' && jkrows[0].classList.contains( 'dim' ) === true && jkrows[1].classList.contains( 'dim' ) === false
		&& all( jkrows[1], 'a' )[0].getAttribute( 'href' ) === '#projekt-00001' && jkrows[1].children.map( textOf ).join( '|' ) === 'Album 2027|01.10.2026|3|'+ texts['projects.running'] && jkrows[0].children.map( textOf )[3] === texts['projects.done'] );
	check( '...Neues Projekt starts one with the client chosen; a client that is off, or an account without the permission of the Elements panel, has no such button', textOf( jf( jkc, 'client-project-new' ) ) === texts['projects.new'] && jf( jkc, 'client-project-new' ).getAttribute( 'href' ) === '#projekte-neu-k1'
		&& jf( ( await ( async function() { const w = await jopen( '#kunde-4-projekte', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { id : 4, active : false } ), accounts : [] } ] }, 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [] } ) ] } } ); return w } )() ), 'client-project-new' ) === undefined
		&& jf( await jopen( '#kunde-1-projekte', {}, { crm : true, pdf : false, elements : false } ), 'client-project-new' ) === undefined );
	check( '...a client with no project says so; where the type is missing the tab points to the settings and has no count', textOf( jf( await jopen( '#kunde-1-projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [] } ) ] } } ), 'client-projects' ) ).indexOf( texts['projects.client-empty'] ) !== -1
		&& ( function( w ) { return all( w.main, '.tabs button' ).map( textOf ).join( '|' ) === [ texts['ctab.belege']+ '7', texts['ctab.projekte'], texts['ctab.portal'], texts['ctab.notizen'] ].join( '|' ) && all( w.main, '.note a' )[0].getAttribute( 'href' ) === '#einstellungen' } )( await jopen( '#kunde-1-projekte', { 'crm-projekte/list' : function() { return [ 200, jlist( { missing : true, projects : [] } ) ] } } ) ) );
	check( '...a card whose projects cannot be read is still the card: the other tabs are there', ( function( w ) { return all( w.main, '.tabs button' ).length === 4 && textOf( all( w.main, 'h1' )[0] ) === 'Nordlicht Records GmbH' } )( await jopen( '#kunde-1', { 'crm-projekte/list' : function() { return [ 500, { error : 'x', code : 'crm_documents_database' } ] } } ) ) );

	// --- the Entwurf: the field Projekt
	const jprojs = [ { id : '00001', title : 'Album 2027', done : false }, { id : '00002', title : 'Webseite', done : true } ];
	const jopts = function( w ) { return all( jf( w, 'f-project' ), 'option' ).map( function( o ) { return o.value+ ':'+ textOf( o ) } ).join() };
	let je = await editing( { project : '00001', project_title : 'Album 2027' }, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { project : '00001', project_title : 'Album 2027' } ), { projects : jprojs } ) ] }, 'crm-projekte/list' : function( data ) { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return String( one.client_id ) === data.client } ) } ) ] } } );
	check( 'the draft has the field Projekt beside the client: a select with kein Projekt first, then the open projects of the client - the done one is not offered - and the one the draft names is chosen', field( je, 'f-project' ).tagName === 'SELECT' && jopts( je ) === ':'+ texts['field.project-none']+ ',00001:Album 2027' && field( je, 'f-project' ).value === '00001' && je.app.state.draft.project === '00001'
		&& je.all( 'crm-projekte/list' ).length === 1 && JSON.stringify( je.last( 'crm-projekte/list' ).data ) === '{"client":"1"}' );
	type( field( je, 'f-project' ), '', false );
	check( '...a project chosen is part of what Speichern posts: project as the id of the project, an empty one takes the draft out of it', je.app.state.draft.project === '' && je.app.model.payloadOf( je.app.state.draft ).project === '' && je.app.state.draft.dirty === true );
	type( field( je, 'f-project' ), '00001', false );
	click( je, field( je, 'save' ) );
	await settle();
	check( '...Speichern posts it', je.saves.length === 1 && je.saves[0].project === '00001' && je.saves[0].client_id === '1' );
	type( field( je, 'f-client_id' ), '2' );
	const jnamedAtOnce = je.app.state.draft.project;
	await settle();
	check( 'another client has other projects: the draft names none at once - not only when the list of the new client is there - the field is read for the new client (crm-projekte/list with its id) and offers its projects', jnamedAtOnce === '' && je.app.state.draft.project === '' && JSON.stringify( je.last( 'crm-projekte/list' ).data ) === '{"client":"2"}' && jopts( je ) === ':'+ texts['field.project-none']+ ',00003:Podcast' && field( je, 'f-project' ).value === '' );
	const jdoneP = await editing( { project : '00002', project_title : 'Webseite' }, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { project : '00002', project_title : 'Webseite' } ), { projects : jprojs } ) ] }, 'crm-projekte/list' : function() { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return one.client_id === 1 } ) } ) ] } } );
	check( '...a draft that names a project that is done keeps it in the select, so that saving does not take it away', jopts( jdoneP ) === ':'+ texts['field.project-none']+ ',00002:Webseite,00001:Album 2027' && field( jdoneP, 'f-project' ).value === '00002' );
	const jgoneP = await editing( { project : '00009', project_title : '' }, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { project : '00009', project_title : '' } ), { projects : [] } ) ] }, 'crm-projekte/list' : function() { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return one.client_id === 1 } ) } ) ] } } );
	check( '...a project that is gone from the client\'s list is no project of the draft any more: the select shows none and so does the draft', field( jgoneP, 'f-project' ).value === '' && jgoneP.app.state.draft.project === '' );
	const jnew = await editing( {}, { 'crm-documents/list' : function( data ) { return [ 200, { documents : [], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] }, 'crm-projekte/list' : function( data ) { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return String( one.client_id ) === data.client } ) } ) ] } }, '#neu-invoice-k1-p00001' );
	check( 'a new draft from the card of a project has the client and the project chosen: the address #neu-invoice-k1-p00001 - the select has the project once the list of the client is there; a project that is not the client\'s is no choice', field( jnew, 'f-client_id' ).value === '1' && field( jnew, 'f-project' ).value === '00001' && jnew.app.state.draft.project === '00001'
		&& field( await editing( {}, { 'crm-projekte/list' : function() { return [ 200, jlist( { projects : [] } ) ] } }, '#neu-invoice-k1-p00001' ), 'f-project' ).value === '' );
	const jrefuse = await editing( { project : '00001', project_title : 'Album 2027' }, { 'crm-documents/save' : function() { return [ 409, { error : 'x', code : 'crm_project_client', params : [], field : 'project' } ] }, 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { project : '00001', project_title : 'Album 2027' } ), { projects : jprojs } ) ] }, 'crm-projekte/list' : function() { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return one.client_id === 1 } ) } ) ] } } );
	click( jrefuse, field( jrefuse, 'save' ) );
	await settle();
	check( 'a refusal at the field project is said at the field: the sentence in the message, the select marked', textOf( field( jrefuse, 'message' ) ).indexOf( texts['error.crm_project_client'] ) !== -1 && field( jrefuse, 'f-project' ).classList.contains( 'err' ) === true );
	// the lists of two clients that answer in the wrong order: the one that was asked for first comes last
	const jheld = { 2 : hold(), 1 : hold() };
	let jarmed = false;
	const jorder = await editing( {}, { 'crm-projekte/list' : function( data ) { return jarmed === true ? jheld[data.client].promise : [ 200, jlist( { projects : jprojects.filter( function( one ) { return String( one.client_id ) === data.client } ) } ) ] } } );
	jarmed = true;
	type( field( jorder, 'f-client_id' ), '2' );
	type( field( jorder, 'f-client_id' ), '1' );
	jheld[1].release( [ 200, jlist( { projects : jprojects.filter( function( one ) { return one.client_id === 1 } ) } ) ] );
	await settle();
	jheld[2].release( [ 200, jlist( { projects : jprojects.filter( function( one ) { return one.client_id === 2 } ) } ) ] );
	await settle();
	check( 'the projects of a client that was left again are not offered under the client that is chosen: the list of client 2 comes after the one of client 1, and the field still has the projects of client 1', field( jorder, 'f-client_id' ).value === '1' && jopts( jorder ).indexOf( '00003:Podcast' ) === -1 && jopts( jorder ).indexOf( '00001:Album 2027' ) !== -1 );
	// a contract follows its offer: another offer, and the draft names no project
	const joffers = [ { id : 13, number : 'A-2026-0011', date : '2026-10-28', client_id : 1, client_title : 'Nordlicht Records GmbH' }, { id : 14, number : 'A-2026-0012', date : '2026-10-29', client_id : 2, client_title : 'Jonas Richter' } ];
	const jcontract = await editing( { id : 18, category : 'contract', parent_id : 13, client_id : 1, title : 'V', lines : [], project : '00001', project_title : 'Album 2027' }, { 'crm-documents/get' : function() { return [ 200, view( Object.assign( {}, draftDoc, { id : 18, category : 'contract', parent_id : 13, client_id : 1, title : 'V', lines : [], project : '00001', project_title : 'Album 2027' } ), { offers : joffers, projects : jprojs } ) ] }, 'crm-projekte/list' : function( data ) { return [ 200, jlist( { projects : jprojects.filter( function( one ) { return String( one.client_id ) === data.client } ) } ) ] } }, '#entwurf-18' );
	const jstays = jcontract.app.state.draft.project;
	type( field( jcontract, 'f-parent_id' ), '14' );
	const jnamedAtOnceC = jcontract.app.state.draft.project;
	await settle();
	check( 'a contract takes the client of its offer, so another offer is another client: the draft names no project from that moment, and the field offers the projects of the other client', jstays === '00001' && jnamedAtOnceC === '' && JSON.stringify( jcontract.last( 'crm-projekte/list' ).data ) === '{"client":"2"}' && jopts( jcontract ) === ':'+ texts['field.project-none']+ ',00003:Podcast' );
	const jlate = hold();
	const jelate = await editing( {}, { 'crm-projekte/list' : function() { return jlate.promise } } );
	jelate.location.hash = '#kunden';
	await settle();
	jlate.release( [ 200, jlist() ] );
	await settle();
	check( 'the projects that arrive after the editor was left are not drawn into anything', all( jelate.main, 'select' ).length === 0 );

	// --- the Beleg: the project in the head, and Projekt ändern
	const jb = await opened( { project : '00001', project_title : 'Album 2027' }, { projects : jprojs }, { 'crm-documents/project' : function( data ) { jposts.push( [ 'crm-documents/project', data ] ); return [ 200, { document : full( { project : data.project } ) } ] } } );
	jposts.length = 0;
	check( 'a document in a project shows it in the head - the link to the card - and in the block Beleg; one without a project shows nothing', textOf( all( jb.main, '.head .sub' )[0] ).indexOf( 'Album 2027' ) !== -1 && jf( jb, 'doc-project' ).getAttribute( 'href' ) === '#projekt-00001' && textOf( all( jb.main, '.cols-3 .card' )[1] ).indexOf( texts['document.project']+ 'Album 2027' ) !== -1
		&& jf( await opened( {} ), 'doc-project' ) === undefined && textOf( all( ( await opened( {} ) ).main, '.cols-3 .card' )[1] ).indexOf( 'Projekt' ) === -1 );
	check( '...a project that is gone shows its id instead of a title', textOf( jf( await opened( { project : '00009', project_title : '' } ), 'doc-project' ) ) === '00009' );
	check( '...the menu ⋯ has Projekt ändern before Notizen', menuOf( jb ).slice( -2 ).join( '|' ) === [ texts['menu.project'], texts['menu.notes'] ].join( '|' ) );
	click( jb, all( all( jb.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.project'] } ) );
	const jdlg = jb.dialogs[0];
	check( 'Projekt ändern asks in a dialog: a select with kein Projekt and the projects of the client (the open ones and the document\'s own), the document\'s project chosen; nothing is posted before the yes', jb.dialogs.length === 1 && all( jdlg, 'select' ).length === 1 && all( all( jdlg, 'select' )[0], 'option' ).map( function( o ) { return o.value+ ':'+ textOf( o ) } ).join() === ':'+ texts['field.project-none']+ ',00001:Album 2027' && all( jdlg, 'select' )[0].value === '00001' && jposts.length === 0 );
	all( jdlg, 'select' )[0].value = '';
	click( jb, all( jdlg, 'button' ).pop() );
	await settle();
	check( '...the yes posts crm-documents/project with the id and the project - for a finalised document too - and draws the document again from the server', JSON.stringify( jposts[0] ) === JSON.stringify( [ 'crm-documents/project', { id : '11', project : '' } ] ) && jb.all( 'crm-documents/get' ).length === 2 && jb.toast.textContent === texts['project.changed'] );
	const jbd = await opened( { project : '00002', project_title : 'Webseite' }, { projects : jprojs } );
	click( jbd, all( all( jbd.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.project'] } ) );
	check( '...a project that is done is no choice - unless it is the document\'s own, which the dialog keeps so that a click on Ändern does not take the document out of it', all( all( jbd.dialogs[0], 'select' )[0], 'option' ).map( function( o ) { return o.value } ).join() === ',00001,00002' && all( jbd.dialogs[0], 'select' )[0].value === '00002' );
	const jb2 = await opened( { project : '00001', project_title : 'Album 2027' }, { projects : jprojs }, { 'crm-documents/project' : function( data ) { jposts.push( [ 'crm-documents/project', data ] ); return [ 200, { document : full() } ] } } );
	jposts.length = 0;
	click( jb2, all( all( jb2.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.project'] } ) );
	click( jb2, all( jb2.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...the same project chosen again posts nothing; the no posts nothing either', jposts.length === 0 && ( click( jb2, all( all( jb2.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.project'] } ) ), click( jb2, all( jb2.dialogs[1], 'button' )[0] ), await settle(), jposts.length === 0 ) );
	const jb3 = await opened( { project : '' }, { projects : jprojs }, { 'crm-documents/project' : function() { return [ 409, { error : 'x', code : 'crm_project_client', params : [], field : 'project' } ] } } );
	click( jb3, all( all( jb3.main, '.menu' )[0], '[role="menuitem"]' ).find( function( one ) { return textOf( one ) === texts['menu.project'] } ) );
	all( jb3.dialogs[0], 'select' )[0].value = '00001';
	click( jb3, all( jb3.dialogs[0], 'button' ).pop() );
	await settle();
	check( '...a refusal is said with its sentence, and the document stays as it was', jb3.toast.classList.contains( 'bad' ) === true && jb3.toast.textContent === texts['error.crm_project_client'] && jb3.all( 'crm-documents/get' ).length === 1 );

	// --- the list of the Belege: the column Projekt
	const jl = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : [ row( { id : 11, number : 'R-2026-0007', project : '00001', project_title : 'Album 2027' } ), row( { id : 12, number : 'R-2026-0008', project : '00003', project_title : 'Zebra' } ), row( { id : 13, number : 'R-2026-0009', project : '00009', project_title : '' } ), row( { id : 14, number : 'R-2026-0010' } ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	jl.boot();
	await settle();
	const jlr = function() { return all( jl.main, 'tbody tr' ) };
	check( 'the Belege list has the column Projekt after Kunde: the title as the link to the card, a dash for a document with none, the id for a project that is gone - and a click on the link does not open the document', all( jl.main, 'thead th' ).map( textOf ).slice( 2, 4 ).join( '|' ) === [ texts['column.client'], texts['column.project'] ].join( '|' ) && textOf( jlr().find( function( tr ) { return tr.dataset.id === '11' } ).children[3] ) === 'Album 2027'
		&& all( jlr().find( function( tr ) { return tr.dataset.id === '11' } ).children[3], 'a' )[0].getAttribute( 'href' ) === '#projekt-00001' && textOf( jlr().find( function( tr ) { return tr.dataset.id === '14' } ).children[3] ) === '–' && textOf( jlr().find( function( tr ) { return tr.dataset.id === '12' } ).children[3] ) === 'Zebra' && textOf( jlr().find( function( tr ) { return tr.dataset.id === '13' } ).children[3] ) === '00009' );
	type( all( jl.main, '#q' )[0], 'album', false );
	check( '...the search finds a document by the title of its project', jlr().map( function( tr ) { return tr.dataset.id } ).join() === '11' );
	type( all( jl.main, '#q' )[0], '', false );
	click( jl, all( all( jl.main, 'thead th' )[3], 'button' )[0] );
	check( '...and the column sorts by it', all( all( jl.main, 'thead th' )[3], 'button' ).length === 1 && jlr().map( function( tr ) { return tr.dataset.id } ).join() === '14,13,11,12' );

	// --- an answer that arrives after the screen was left draws nothing
	const lateList = await lateScreen( '#projekte', 'crm-projekte/list', [ 200, jlist() ] );
	check( 'the answer of the list of the projects that arrives after the screen was left draws nothing: the screen the address names stays', onNew( lateList ) && all( lateList.main, 'table' ).length === 0 );
	const lateCard = await lateScreen( '#projekt-00001', 'crm-projekte/get', [ 200, jget() ] );
	check( '...so does the answer of the card', onNew( lateCard ) && all( lateCard.main, '#project-info' ).length === 0 );
	const lateNew = await lateScreen( '#projekte-neu', 'crm-projekte/list', [ 200, jlist() ] );
	check( '...and that of the form of a new project', onNew( lateNew ) && all( lateNew.main, '#project-form' ).length === 0 );
	const lateCardEdit = hold();
	const jlc = await jopen( '#projekt-00001', { 'elements/save' : function() { return lateCardEdit.promise } } );
	click( jlc, jitem( jlc, texts['projects.finish'] ) );
	jlc.location.hash = '#kunden';
	await settle();
	const jlcGets = jlc.all( 'crm-projekte/get' ).length;
	lateCardEdit.release( [ 200, { element : {} } ] );
	await settle();
	check( 'a change that is answered after the screen was left says nothing over the screen that is there and reads nothing again', jlc.all( 'crm-projekte/get' ).length === jlcGets && all( jlc.main, 'table' ).length === 1 );

	check( 'the waiting offers of a project are told with their number in a form that is right for every count: the word has a place for the number, and the number does not stand before a singular noun', texts['projects.open-offers'].indexOf( '%s' ) !== -1 && /%s Angebot\b/.test( texts['projects.open-offers'] ) === false );

	// --- Kunden hierher einladen, from the card of a project
	const jhereSent = [];
	const jhereAnswers = { 'crm-clients/accounts' : function() { return [ 200, { accounts : kaccounts } ] }, 'crm-clients/account-resend' : function( data ) { jhereSent.push( data ); return [ 200, { sent : true, kind : data.account === 'bbbbbbbbbbbb' ? 'invite' : 'login' } ] } };
	const jhere = await jopen( '#projekt-00001', jhereAnswers );
	click( jhere, jitem( jhere, texts['here.menu'] ) );
	await settle();
	const jhereBox = jhere.dialogs[0];
	check( 'the card of a project has Kunden hierher einladen first in the menu ⋯: the accounts of the client that are on are asked for and offered, all ticked, with the page the link leads to named - the files of the project', jhere.last( 'crm-clients/accounts' ) !== undefined && JSON.stringify( jhere.last( 'crm-clients/accounts' ).data ) === '{"id":"1"}'
		&& all( jhereBox, 'input' ).length === 2 && all( jhereBox, 'input' ).every( function( input ) { return input.type === 'checkbox' && input.checked === true } ) && textOf( all( jhereBox, 'p' )[0] ) === texts['here.text'].replace( '%s', texts['here.page-project'] ) );
	jyes( jhere, 0 );
	await settle();
	check( '...the yes sends a link to each, to the page of the files of the project - its id encoded, the tab named - and the toast says how many', JSON.stringify( jhereSent ) === JSON.stringify( [ { id : '1', account : 'aaaaaaaaaaaa', target : '/portal/projekte?projekt=00001&tab=dateien' }, { id : '1', account : 'bbbbbbbbbbbb', target : '/portal/projekte?projekt=00001&tab=dateien' } ] )
		&& jhere.toast.textContent === texts['here.sent-many'].replace( '%s', '2' ) );
	const jhereReader = await jopen( '#projekt-00001', jhereAnswers, { crm : true, pdf : false, elements : false, text : false, files : true } );
	check( '...a card without the permission of the Elements panel has the menu with this item alone, and one whose client is not known has none of it', all( jmenu( jhereReader, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === texts['here.menu']
		&& jmenu( await jopen( '#projekt-00001', { 'crm-projekte/get' : function() { return [ 200, jget( { client : null } ) ] } }, { crm : true, pdf : false, elements : false, text : false, files : true } ), '⋯' ) === undefined );

	// --- the script
	check( 'the script posts a file to the Files panel\'s upload only as chunks, with the part named chunk, and keeps no folder of its own: the actions it names of that panel are the eight of the endpoint', [ ...new Set( [ ...script.matchAll( /(?:api|call|upload)\( '(files\/[a-z/]+)'/g ) ].map( function( found ) { return found[1] } ) ) ].sort().join() === 'files/delete,files/folder/add,files/folder/save,files/list,files/notify,files/remove,files/save,files/upload' && /upload\( 'files\/upload', \{[^}]*\}, file\.slice\([^)]*\), 'chunk' \)/.test( script ) === true );
	check( '...every word the screens ask for is a word of the page', [ ...script.matchAll( /\bt\( '(projects\.[a-z-]+|project\.[a-z-]+|ptab\.[a-z]+)'/g ) ].map( function( found ) { return found[1] } ).filter( function( key ) { return typeof texts[key] !== 'string' || texts[key] === '' } ).length === 0 && typeof texts['nav.projects'] === 'string' );

	// === C20: Link für den Kunden - the button at the project, its files, a document and the client, and the dialog

	const x20_perms = Object.assign( {}, jperms, { members : true } );
	const x20_jopen = function( hash, over, perms ) { return jopen( hash, over, perms ?? x20_perms ) };
	const x20_accounts = [
		{ id : 'aaaaaaaaaaaa', gone : false, state : 'signed', email : 'lena@nordlicht.example', name : 'Lena Brandt', active : true, login : '2026-10-30T10:00:00Z' },
		{ id : 'bbbbbbbbbbbb', gone : false, state : 'invited', email : 'tom@nordlicht.example', name : '', active : true, login : null },
		{ id : 'cccccccccccc', gone : false, state : 'off', email : 'alt@nordlicht.example', name : 'Alt', active : false, login : '2026-01-02T10:00:00Z' },
	];
	const x20_posts = [];
	const x20_answers = function( accountList, resend ) {
		return {
			'crm-clients/accounts' : function() { return [ 200, { accounts : accountList } ] },
			'crm-clients/account-resend' : resend ?? function( data ) {
				x20_posts.push( data );
				return [ 200, Object.assign( { sent : data.notify !== false, kind : data.account === 'bbbbbbbbbbbb' ? 'invite' : 'login' }, data.notify === false ? { link : 'https://example.org/club/.members/login?t=SHOWN', expires : data.account === 'bbbbbbbbbbbb' ? 604800 : 900 } : {} ) ];
			},
		};
	};
	const x20_radios = function( node ) { return all( node, 'input' ).filter( function( input ) { return input.type === 'radio' } ) };
	const x20_target = function( node ) { return node === undefined ? '' : node.dataset.target };
	const x20_open = function( w ) { return w.dialogs[w.dialogs.length - 1] };
	const x20_yes = function( w ) { click( w, all( x20_open( w ), 'button' ).pop() ) };

	// the card of a project: the head, the tab Dateien and its rows (each version is a row of its own)
	const x20_versions = jfiles.concat( [
		{ id : 'a00000000003', name : 'Mix.wav', ext : 'wav', size : 5000, mime : 'audio/wav', created : '2026-11-04T10:00:00Z', visible : true, by : { kind : 'owner', label : '' }, url : '/.files/a00000000003', title : 'Mix', version : '1', versions : 2, group : 2, comments : null },
		{ id : 'a00000000004', name : 'Mix.wav', ext : 'wav', size : 5100, mime : 'audio/wav', created : '2026-11-05T10:00:00Z', visible : true, by : { kind : 'owner', label : '' }, url : '/.files/a00000000004', title : 'Mix', version : '2', versions : 2, group : 2, comments : null },
		{ id : 'a00000000005', name : 'Weg.wav', ext : 'wav', size : 5000, mime : 'audio/wav', created : '2026-11-04T10:00:00Z', visible : true, gone : '2026-11-06T10:00:00Z', by : { kind : 'owner', label : '' }, url : '', title : 'Weg', version : '', versions : 1, group : 3, comments : null },
	] );
	const x20_p = await x20_jopen( '#projekt-00001-dateien', Object.assign( { 'crm-projekte/get' : function() { return [ 200, jget( { list : x20_versions } ) ] } }, x20_answers( x20_accounts ) ) );
	check( 'the head of the card of a project has Link für den Kunden: a button that is 44 pixels high in the style, with its title, leading to the overview of the project in the portal', x20_target( jf( x20_p, 'project-link' ) ) === '/portal/projekte?projekt=00001' && jf( x20_p, 'project-link' ).title === texts['plink.button'] && textOf( jf( x20_p, 'project-link' ) ) === texts['plink.short'] && jf( x20_p, 'project-link' ).getAttribute( 'aria-label' ).indexOf( texts['plink.button'] ) === 0 );
	check( '...the tab Dateien has one for its head - the files of the project - and one for each file that the customer sees, by the file: a file that is hidden has none; of two versions of a title each has its own', x20_target( jf( x20_p, 'files-link' ) ) === '/portal/projekte?projekt=00001&tab=dateien'
		&& x20_target( jf( x20_p, 'file-link-a00000000001' ) ) === '/portal/projekte?projekt=00001&review=a00000000001&tab=dateien' && jf( x20_p, 'file-link-a00000000002' ) === undefined && jf( x20_p, 'file-link-a00000000005' ) === undefined
		&& x20_target( jf( x20_p, 'file-link-a00000000003' ) ) === '/portal/projekte?projekt=00001&review=a00000000003&tab=dateien' && x20_target( jf( x20_p, 'file-link-a00000000004' ) ) === '/portal/projekte?projekt=00001&review=a00000000004&tab=dateien' );
	check( '...and none where the client has no account that is on, where the client is off, or where the account may not change the files', jf( await x20_jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { client : Object.assign( {}, jclient1, { active_accounts : 0 } ) } ) ] } } ), 'project-link' ) === undefined
		&& all( ( await x20_jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { client : Object.assign( {}, jclient1, { active_accounts : 0 } ) } ) ] } } ) ).main, '.link-btn' ).length === 0
		&& all( ( await x20_jopen( '#projekt-00001-dateien', { 'crm-projekte/get' : function() { return [ 200, jget( { client : Object.assign( {}, jclient1, { active : false } ) } ) ] } } ) ).main, '.link-btn' ).length === 0
		&& all( ( await x20_jopen( '#projekt-00001-dateien', {}, { crm : true, pdf : false, elements : false, files : false } ) ).main, '.link-btn' ).map( function( button ) { return button.id } ).join() === 'project-link' );

	// the dialog: the object and the target as text, the account, the box of the mail, and the link shown once with the sentence of how long it stays good
	x20_posts.length = 0;
	click( x20_p, jf( x20_p, 'file-link-a00000000004' ) );
	await settle();
	const x20_d = x20_open( x20_p );
	check( 'the button asks for the accounts of the client and opens the dialog: the file with its version and the target as text - nothing to type -, a choice of the two accounts that are on (the first is chosen), and the box of the mail, not ticked', x20_p.all( 'crm-clients/accounts' ).length === 1 && x20_p.last( 'crm-clients/accounts' ).data.id === '1'
		&& textOf( x20_d ).indexOf( 'Mix.wav' ) !== -1 && textOf( x20_d ).indexOf( 'Mix.wav v2' ) === -1 && all( x20_d, '#dlg-target' )[0].textContent === '/portal/projekte?projekt=00001&review=a00000000004&tab=dateien'
		&& x20_radios( x20_d ).length === 2 && x20_radios( x20_d ).map( function( input ) { return input.value+ ':'+ input.checked } ).join() === 'aaaaaaaaaaaa:true,bbbbbbbbbbbb:false'
		&& all( x20_d, '#dlg-mail' ).length === 1 && all( x20_d, '#dlg-mail' )[0].type === 'checkbox' && all( x20_d, '#dlg-mail' )[0].checked === false && all( x20_d, 'input' ).filter( function( input ) { return input.type === 'text' } ).length === 0 );
	x20_yes( x20_p );
	await settle();
	check( '...Link erzeugen posts the action of the card of the client with the account, the target and notify false, shows the link in the field that nobody can type in, with the button that copies it and the sentence of how long it stays good (from the answer)', JSON.stringify( x20_posts ) === '[{"id":"1","account":"aaaaaaaaaaaa","target":"/portal/projekte?projekt=00001&review=a00000000004&tab=dateien","notify":false}]'
		&& all( x20_d, '#dlg-link' )[0].value === 'https://example.org/club/.members/login?t=SHOWN' && all( x20_d, '#dlg-link' )[0].readOnly === true && all( x20_d, '#dlg-link-copy' ).length === 1
		&& all( x20_d, '#dlg-note' )[0].textContent === texts['plink.valid-minutes'].replace( '%s', '15' ) && all( x20_d, 'button' ).map( textOf ).join( '|' ) === texts['shown.copy']+ '|'+ texts['dialog.close'] && x20_radios( x20_d ).length === 0 );
	click( x20_p, all( x20_d, 'button' ).pop() );
	await settle();
	check( '...and takes the link along when it closes: not the field, nor the text of it', x20_p.dialogRoot.children.length === 0 && x20_p.dialogRoot.textContent.indexOf( 'SHOWN' ) === -1 && all( x20_d, '#dlg-link' )[0].value === '' );

	x20_posts.length = 0;
	click( x20_p, jf( x20_p, 'project-link' ) );
	await settle();
	click( x20_p, x20_radios( x20_open( x20_p ) )[1] );
	x20_radios( x20_open( x20_p ) )[0].checked = false;
	x20_radios( x20_open( x20_p ) )[1].checked = true;
	all( x20_open( x20_p ), '#dlg-mail' )[0].checked = true;
	x20_yes( x20_p );
	await settle();
	check( '...the other account and the box ticked post notify true and that account; the dialog closes with the toast of the mail, and no link is shown', JSON.stringify( x20_posts ) === '[{"id":"1","account":"bbbbbbbbbbbb","target":"/portal/projekte?projekt=00001","notify":true}]' && x20_p.dialogRoot.children.length === 0 && x20_p.toast.textContent === texts['acc.resent-invite'] );

	x20_posts.length = 0;
	const x20_one = await x20_jopen( '#projekt-00001', x20_answers( [ x20_accounts[1], x20_accounts[2] ] ) );
	click( x20_one, jf( x20_one, 'project-link' ) );
	await settle();
	all( x20_open( x20_one ), '#dlg-mail' )[0].checked = false;
	check( 'with one account that is on there is no choice: the account stands in the dialog as a line, and it is the one that is posted - and an invitation that is shown says its days', x20_radios( x20_open( x20_one ) ).length === 0 && textOf( x20_open( x20_one ) ).indexOf( 'tom@nordlicht.example' ) !== -1 && textOf( x20_open( x20_one ) ).indexOf( 'alt@nordlicht.example' ) === -1 );
	x20_yes( x20_one );
	await settle();
	check( '...the post names that account, and the sentence says the days of an invitation (from the answer)', x20_posts[0].account === 'bbbbbbbbbbbb' && all( x20_open( x20_one ), '#dlg-note' )[0].textContent === texts['plink.valid-days'].replace( '%s', '7' ) );

	// a refusal is said in the dialog, which stays; the target that is bad is the server's to refuse
	const x20_refused = await x20_jopen( '#projekt-00001', x20_answers( x20_accounts, function() { return [ 400, { error : 'x', code : 'crm_clients_target', params : [], field : 'target' } ] } ) );
	click( x20_refused, jf( x20_refused, 'project-link' ) );
	await settle();
	x20_yes( x20_refused );
	await settle();
	check( 'a refusal of the server is said in the dialog with the sentence of its code, which stays open with the yes on again and no link in it', x20_refused.dialogs.length === 1 && x20_refused.dialogRoot.children.length === 1 && all( x20_open( x20_refused ), '#dlg-problem' )[0].hidden === false && textOf( all( x20_open( x20_refused ), '#dlg-problem' )[0] ) === texts['error.crm_clients_target']
		&& all( all( x20_open( x20_refused ), 'button' ).pop(), 'span' ).length === 0 && all( x20_open( x20_refused ), 'button' ).pop().disabled === false && all( x20_open( x20_refused ), '#dlg-link' ).length === 0 );
	const x20_off = await x20_jopen( '#projekt-00001', x20_answers( x20_accounts, function() { return [ 409, { error : 'x', code : 'crm_clients_off', params : [], field : 'account' } ] } ) );
	click( x20_off, jf( x20_off, 'project-link' ) );
	await settle();
	x20_yes( x20_off );
	await settle();
	check( '...so is an account that was switched off meanwhile', textOf( all( x20_open( x20_off ), '#dlg-problem' )[0] ) === texts['error.crm_clients_off'] );
	const x20_none = await x20_jopen( '#projekt-00001', x20_answers( [ x20_accounts[2] ] ) );
	click( x20_none, jf( x20_none, 'project-link' ) );
	await settle();
	check( 'a client whose accounts are all off since the card was read gets no dialog, and the toast says so', x20_none.dialogs.length === 0 && x20_none.toast.textContent === texts['here.none'] );

	// the permission of Members: without it there is no box and the link goes by mail
	const x20_plain = world( { hash : '#projekt-00001', perms : { crm : true, elements : true, files : true }, answers : janswers( x20_answers( x20_accounts ) ) } );
	x20_plain.boot();
	await settle();
	x20_posts.length = 0;
	click( x20_plain, jf( x20_plain, 'project-link' ) );
	await settle();
	check( 'an account without the permission of Members has no box of the mail: the link goes by mail, and the button says Senden', all( x20_open( x20_plain ), '#dlg-mail' ).length === 0 && textOf( all( x20_open( x20_plain ), 'button' ).pop() ) === texts['plink.send'] );
	x20_yes( x20_plain );
	await settle();
	check( '...and the post says notify true', x20_posts.length === 1 && x20_posts[0].notify === true && x20_plain.dialogRoot.children.length === 0 );

	// what the owner writes is text: a title and a file name with markup stand in the dialog as text
	const x20_hostile = '<img src=x onerror=alert(1)>';
	const x20_h = await x20_jopen( '#projekt-00001-dateien', Object.assign( { 'crm-projekte/get' : function() { return [ 200, jget( { project : jrow( { title : x20_hostile } ), list : [ Object.assign( {}, jfiles[0], { name : x20_hostile+ '.txt' } ) ] } ) ] } }, x20_answers( x20_accounts ) ) );
	click( x20_h, jf( x20_h, 'project-link' ) );
	await settle();
	check( 'the title of a project with markup stands in the dialog as text and makes no element', all( x20_open( x20_h ), 'img' ).length === 0 && textOf( x20_open( x20_h ) ).indexOf( x20_hostile ) !== -1 );
	click( x20_h, all( x20_open( x20_h ), 'button' )[0] );
	click( x20_h, jf( x20_h, 'file-link-a00000000001' ) );
	await settle();
	check( '...as does the name of a file', all( x20_open( x20_h ), 'img' ).length === 0 && textOf( x20_open( x20_h ) ).indexOf( x20_hostile+ '.txt' ) !== -1 );

	// a document that the customer sees in the portal: its screen and the card of the tab Portal
	const x20_docs = [
		[ 'an invoice without a project', { state : 'sent', released : true }, '/portal/belege#beleg-11' ],
		[ 'an invoice of a project', { state : 'sent', released : true, project : '00001', project_title : 'Album 2027' }, '/portal/projekte?projekt=00001&tab=belege#beleg-11' ],
		[ 'a credit note of a project', { id : 17, category : 'credit', state : 'open', released : true, project : '00001', project_title : 'Album 2027', due_date : null }, '/portal/projekte?projekt=00001&tab=belege#beleg-17' ],
		[ 'an offer of a project', { id : 13, category : 'offer', state : 'sent', released : true, project : 'Album_2027-b', project_title : 'Album', due_date : null }, '/portal/projekte?projekt=Album_2027-b&tab=angebot#angebot-13' ],
		[ 'an offer without a project', { id : 14, category : 'offer', state : 'open', released : true, due_date : null }, '/portal/belege#beleg-14' ],
	];

	for( const [ label, over, target ] of x20_docs ) {
		const w = await opened( over, { accounts : x20_accounts } );
		click( w, all( w.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === 'portal' } ) );
		check( 'the screen of '+ label+ ' that is released has Link für den Kunden in its head and on the card of its tab Portal, for the page the portal gives it: '+ target, x20_target( all( w.main, '#doc-link' )[0] ) === target && x20_target( all( w.main, '#portal-link' )[0] ) === target );
	}

	const x20_nodocs = [
		[ 'an invoice that is not released', { state : 'sent', released : false }, { accounts : x20_accounts } ],
		[ 'a draft', { state : 'draft', released : false }, { accounts : [] } ],
		[ 'a released invoice of a client without an account that is on', { state : 'sent', released : true }, { accounts : [ x20_accounts[2] ] } ],
		[ 'a released invoice of a client that is off', { state : 'sent', released : true }, { accounts : x20_accounts, clients : [ { id : 1, number : 'K-0001', title : 'X', active : false } ] } ],
		[ 'a released contract', { id : 18, category : 'contract', state : 'open', released : true, lines : [], net : 0, gross : 0, vat : 0, title : 'V', number : '', due_date : null }, { accounts : x20_accounts } ],
	];

	for( const [ label, over, extra ] of x20_nodocs ) {
		const w = await opened( over, extra );
		check( 'the screen of '+ label+ ' has no Link für den Kunden: the portal does not show it, or there is nobody to give the link to', all( w.main, '.link-btn' ).length === 0 );
	}

	const x20_d1 = await opened( { state : 'sent', released : true }, { accounts : x20_accounts }, x20_answers( x20_accounts ) );
	click( x20_d1, all( x20_d1.main, '#doc-link' )[0] );
	await settle();
	check( 'the button of a document opens the dialog with its number and the target of its row; the post carries that target', textOf( x20_open( x20_d1 ) ).indexOf( 'R-2026-0007' ) !== -1 && all( x20_open( x20_d1 ), '#dlg-target' )[0].textContent === '/portal/belege#beleg-11' );

	// the card of the client: two entries of the menu for the pages of the portal that are the client's own
	const x20_k = await kopen( '#kunde-1', x20_answers( x20_accounts ) );
	x20_posts.length = 0;
	click( x20_k, kitem( kmenu( x20_k, '⋯' ), texts['plink.menu-data'] ) );
	await settle();
	check( 'the menu ⋯ of the card of a client has Link zu Meine Daten, which opens the dialog for /portal/meine-daten', all( x20_open( x20_k ), '#dlg-target' )[0].textContent === '/portal/meine-daten' );
	click( x20_k, all( x20_open( x20_k ), 'button' )[0] );
	click( x20_k, kitem( kmenu( x20_k, '⋯' ), texts['plink.menu-privacy'] ) );
	await settle();
	all( x20_open( x20_k ), '#dlg-mail' )[0].checked = true;
	x20_yes( x20_k );
	await settle();
	check( '...and Link zur Datenschutzerklärung for /portal/datenschutz, posted with the target and notify true', JSON.stringify( x20_posts ) === '[{"id":"1","account":"aaaaaaaaaaaa","target":"/portal/datenschutz","notify":true}]' );
	const x20_koff = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : kclient, accounts : [ kaccounts[2], kaccounts[3] ] } ] } } );
	check( 'a client with no account that is on has neither entry; one that is off has none either', all( kmenu( x20_koff, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Deaktivieren'
		&& all( kmenu( await kopen( '#kunde-4' ), '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Aktivieren' );

	const x20_last = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : kclient, accounts : [ kaccounts[0], kaccounts[2], kaccounts[3] ] } ] }, 'crm-clients/account-active' : function() { return [ 200, { account : Object.assign( {}, kaccounts[0], { active : false, state : 'off' } ) } ] } } );
	const x20_before = all( kmenu( x20_last, '⋯' ), '[role="menuitem"]' ).length;
	click( x20_last, kitem( arows( x20_last )[0], 'Sperren' ) );
	await settle();
	check( 'the links of the menu of the card follow the accounts: with the last account that is on switched off the two entries are gone from the menu', x20_before === 4 && all( kmenu( x20_last, '⋯' ), '[role="menuitem"]' ).map( textOf ).join( '|' ) === 'Stammdaten bearbeiten|Deaktivieren' );

	check( 'every word the buttons and the dialog of Link für den Kunden ask for is a word of the page', [ ...script.matchAll( /'(plink\.[a-z-]+)'/g ) ].map( function( found ) { return found[1] } ).every( function( key ) { return typeof texts[key] === 'string' } ) && [ ...script.matchAll( /'(plink\.[a-z-]+)'/g ) ].length >= 15 );

	}

	// === C18: the Datenschutz and the orders of the portal in the surface

	const pkey18 = function( w ) { return press( w, 's', null, { ctrlKey : true } ) };
	const x18_record = function( over ) {
		return Object.assign( { id : 1, kind : 'privacy', client_id : 1, member_id : 'aaaaaaaaaaaa', member_email : 'lena@nordlicht.example', member_name : 'Lena Brandt', document_id : null, project : '', sha256 : 'a'.repeat( 64 ), shown : 'Zeile eins\nZeile <b>zwei</b>', at : '2026-11-03T10:15:00Z', ip : '203.0.113.7', agent : 'Browser/1.0' }, over );
	};
	const x18_ordered = x18_record( { id : 2, kind : 'order', document_id : 31, document_number : 'A-2026-0031', project : '00001', shown : '{\n    "number": "A-2026-0031"\n}', sha256 : 'b'.repeat( 64 ) } );

	// the block Datenschutz of the Einstellungen
	let privacyPosts = [];
	const x18_pr = await eopen( { settings : Object.assign( {}, esettings, { privacy_text : 'Alter Text\nzweite Zeile', privacy_changed : '2026-10-01' } ) }, undefined, {
		'crm-documents/settings-save' : function( data ) { privacyPosts.push( data ); return data.privacy_text === 'zu\x07' ? [ 400, { error : 'x', code : 'crm_documents_setting', field : 'privacy_text' } ] : [ 200, { settings : Object.assign( {}, esettings, { privacy_text : data.privacy_text, privacy_changed : '2026-11-04' } ) } ] },
	} );
	const x18_pbox = all( x18_pr.main, '#privacy' )[0];
	const x18_parea = efield( x18_pr, 'privacy_text' );
	check( 'the Einstellungen have the block Datenschutz: a textarea with the text as it is stored - line breaks kept - the day it last changed, a note that a change asks every customer again, and a Speichern that carries the shortcut', x18_pbox !== undefined && x18_pbox.tagName === 'FORM' && x18_parea.tagName === 'TEXTAREA' && x18_parea.value === 'Alter Text\nzweite Zeile' && textOf( all( x18_pr.main, '#privacy-changed' )[0] ) === texts['privacy.changed'].replace( '%s', '01.10.2026' )
		&& textOf( x18_pbox ).indexOf( texts['privacy.note'] ) !== -1 && all( x18_pr.main, '#privacy-save' )[0].title === texts['save.shortcut'] && textOf( all( x18_pr.main, '#privacy-save' )[0] ) === texts['privacy.save'] );
	check( '...it is a form of its own: the settings form does not hold the text and does not post it', all( all( x18_pr.main, '#settings-form' )[0], '#f-privacy_text' ).length === 0 );
	check( '...nothing typed, nothing to save: Strg+S posts nothing', pkey18( x18_pr ) === false || privacyPosts.length === 0 );
	type( x18_parea, 'Neuer Text\nmit Umbruch', false );
	check( 'what is typed is unsaved input, and the guard of the page knows', x18_pr.app.state.draft.dirty === true );
	check( 'Strg+S in the field saves it as the button does: crm-documents/settings-save with the text alone', ( x18_parea.focused = true, pkey18( x18_pr ) ) === true && ( await settle(), privacyPosts.length === 1 ) && JSON.stringify( privacyPosts[0] ) === JSON.stringify( { privacy_text : 'Neuer Text\nmit Umbruch' } ) );
	check( '...the day the module set is shown, the toast says it is saved, the guard holds nothing and the text stays as saved', textOf( all( x18_pr.main, '#privacy-changed' )[0] ) === texts['privacy.changed'].replace( '%s', '04.11.2026' ) && x18_pr.toast.textContent === texts['privacy.saved'] && x18_pr.app.state.draft === null && x18_parea.value === 'Neuer Text\nmit Umbruch' );
	type( x18_parea, 'zu\x07', false );
	submit( x18_pr, x18_pbox );
	await settle();
	check( 'a refusal is said at the field it names, the field marked and focused, and the text stays as typed', x18_parea.classList.contains( 'err' ) === true && x18_parea.focused === true && textOf( all( x18_pbox, '.state-note' ).find( function( n ) { return textOf( n ).indexOf( texts['error.crm_documents_setting'].slice( 0, 12 ) ) === 0 } ) ?? element( 'span' ) ) !== '' && x18_parea.value === 'zu\x07' && privacyPosts.length === 2 );
	const x18_pr0 = await eopen( {} );
	check( 'where no text is stored yet the block says so and the field is empty', efield( x18_pr0, 'privacy_text' ).value === '' && textOf( all( x18_pr0.main, '#privacy-changed' )[0] ) === texts['privacy.never'] );

	// the block Nachricht an alle Kunden of the Einstellungen
	const messageAllPosts = [];
	const messageAll = await eopen( { settings : Object.assign( {}, esettings, { portal_message : 'Zu bis Montag.\nDanke!', portal_message_at : '2026-11-02T10:00:00Z' } ) }, undefined, {
		'crm-documents/settings-save' : function( data ) { messageAllPosts.push( data ); return data.portal_message.length > 2000 ? [ 400, { error : 'x', code : 'crm_documents_portal_message', field : 'portal_message' } ] : [ 200, { settings : Object.assign( {}, esettings, { portal_message : data.portal_message, portal_message_at : data.portal_message === '' ? '' : '2026-11-06T09:00:00Z' } ) } ] },
	} );
	const messageAllBox = all( messageAll.main, '#message-all' )[0];
	const messageAllArea = efield( messageAll, 'portal_message' );
	check( 'the Einstellungen have the block Nachricht an alle Kunden: a form of its own with a textarea that holds the message as stored - line breaks kept, 2000 characters at most -, the day it last changed and a Speichern that carries the shortcut; the settings form does not hold it', messageAllBox !== undefined && messageAllBox.tagName === 'FORM' && messageAllArea.tagName === 'TEXTAREA'
		&& messageAllArea.value === 'Zu bis Montag.\nDanke!' && messageAllArea.getAttribute( 'maxlength' ) === '2000' && textOf( all( messageAll.main, '#message-all-changed' )[0] ) === texts['message-all.changed'].replace( '%s', '02.11.2026' ) && textOf( all( messageAllBox, 'h3' )[0] ) === texts['message-all.title']
		&& all( messageAll.main, '#message-all-save' )[0].title === texts['save.shortcut'] && all( all( messageAll.main, '#settings-form' )[0], '#f-portal_message' ).length === 0 );
	check( '...nothing typed, nothing to save', pkey18( messageAll ) === false && messageAllPosts.length === 0 );
	type( messageAllArea, 'Neu\nText', false );
	check( 'what is typed is unsaved input, and Strg+S in the field saves it: crm-documents/settings-save with the message alone, the time the module set is shown, the toast says it is saved', messageAll.app.state.draft.dirty === true && ( messageAllArea.focused = true, pkey18( messageAll ) ) === true && ( await settle(), messageAllPosts.length === 1 ) && JSON.stringify( messageAllPosts[0] ) === '{"portal_message":"Neu\\nText"}'
		&& textOf( all( messageAll.main, '#message-all-changed' )[0] ) === texts['message-all.changed'].replace( '%s', '06.11.2026' ) && messageAll.toast.textContent === texts['message-all.saved'] && messageAll.app.state.draft === null );
	type( messageAllArea, 'x'.repeat( 2001 ), false );
	submit( messageAll, messageAllBox );
	await settle();
	check( '...a refusal is said at the field, which is marked and focused, and the text stays as typed', messageAllArea.classList.contains( 'err' ) === true && messageAllArea.focused === true && messageAllArea.value.length === 2001 && messageAllPosts.length === 2 );
	type( messageAllArea, '', false );
	submit( messageAll, messageAllBox );
	await settle();
	check( '...an empty text removes the message: the block says there is none and the toast says it is removed', messageAllPosts[2].portal_message === '' && textOf( all( messageAll.main, '#message-all-changed' )[0] ) === texts['message-all.never'] && messageAll.toast.textContent === texts['message-all.removed'] );
	const messageAll0 = await eopen( {} );
	check( '...where none is stored the block says so and the field is empty', efield( messageAll0, 'portal_message' ).value === '' && textOf( all( messageAll0.main, '#message-all-changed' )[0] ) === texts['message-all.never'] );

	// the Kundenkarte: the consents and the revocations among the records
	const c14_records = [ Object.assign( {}, c14_revocation, { document_number : '' } ), Object.assign( {}, c14_consent(), { document_number : 'A-2026-0001' } ) ];
	const c14_k = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { records : c14_records } ) ] } } );
	const c14_krows = all( all( c14_k.main, '#client-records' )[0], 'li' );
	check( 'the tab Portal lists a revocation and a consent among the records: the kind, the purpose, the time, the account as text, the project as a link to its card - and for the consent the offer it was given with', c14_krows.length === 2 && c14_krows[0].dataset.kind === 'revocation' && c14_krows[1].dataset.kind === 'consent'
		&& textOf( c14_krows[0] ).indexOf( texts['record.kind-revocation']+ ' · 05.11.2026 ' ) === 0 && textOf( c14_krows[0] ).indexOf( texts['consent.purpose-social'] ) !== -1 && textOf( c14_krows[1] ).indexOf( texts['record.kind-consent']+ ' · 03.11.2026 ' ) === 0 && textOf( c14_krows[1] ).indexOf( texts['consent.purpose-social'] ) !== -1
		&& textOf( c14_krows[1] ).indexOf( 'Lena <b>Brandt</b>' ) !== -1 && findAll( all( c14_k.main, '#client-records' )[0], function( node ) { return node.tagName === 'B' } ).length === 0 && all( c14_krows[1], 'a' ).map( function( one ) { return one.getAttribute( 'href' ) } ).join() === '#beleg-12,#projekt-00001' && all( c14_krows[0], 'a' ).map( function( one ) { return one.getAttribute( 'href' ) } ).join() === '#projekt-00001' );
	click( c14_k, all( c14_krows[0], 'button' )[0] );
	check( '...Anzeigen opens the dialog of a revocation with its own title and the sentence that was revoked; the one of a consent has the title of a consent', c14_k.dialogs[0].getAttribute( 'aria-label' ) === texts['record.title-revocation'] && textOf( all( c14_k.dialogs[0], 'pre' )[0] ) === 'Ich willige <b>ein</b>.\n[crm-client]' && textOf( all( c14_k.dialogs[0], 'dl.kv dd' )[1] ) === '203.0.113.8' );
	click( c14_k, all( c14_krows[1], 'button' )[0] );
	check( '...the dialog of a consent in the list of the records has the title of a consent', c14_k.dialogs[c14_k.dialogs.length - 1].getAttribute( 'aria-label' ) === texts['record.title-consent'] );

	// the Einstellungen: the block Einwilligungen
	const c14_posts = [];
	const c14_s = await eopen( { settings : Object.assign( {}, esettings, { consent_social : 'Satz <b>eins</b>\nzwei', consent_archive : '' } ) }, undefined, {
		'crm-documents/settings-save' : function( data ) { c14_posts.push( data ); return data.consent_social.length > 2000 ? [ 400, { error : 'x', code : 'crm_documents_consent_social', field : 'consent_social' } ] : [ 200, { settings : Object.assign( {}, esettings, data ) } ] },
	} );
	const c14_box = all( c14_s.main, '#consents' )[0];
	check( 'the Einstellungen have the block Einwilligungen: a form of its own with a textarea for each purpose - the sentence as stored, line breaks kept, 2000 characters at most - and a Speichern that carries the shortcut; the settings form does not hold them; the block stands after the Datenschutz', c14_box !== undefined && c14_box.tagName === 'FORM' && efield( c14_s, 'consent_social' ).tagName === 'TEXTAREA' && efield( c14_s, 'consent_archive' ).tagName === 'TEXTAREA'
		&& efield( c14_s, 'consent_social' ).value === 'Satz <b>eins</b>\nzwei' && efield( c14_s, 'consent_archive' ).value === '' && efield( c14_s, 'consent_social' ).getAttribute( 'maxlength' ) === '2000' && efield( c14_s, 'consent_archive' ).getAttribute( 'maxlength' ) === '2000'
		&& all( c14_box, 'dt' ).map( textOf ).join( '|' ) === [ texts['consents.label-social'], texts['consents.label-archive'] ].join( '|' ) && textOf( all( c14_box, 'h3' )[0] ) === texts['consents.title'] && all( c14_s.main, '#consents-save' )[0].title === texts['save.shortcut']
		&& all( all( c14_s.main, '#settings-form' )[0], '#f-consent_social' ).length === 0 && c14_s.main.children.indexOf( c14_box ) > c14_s.main.children.indexOf( all( c14_s.main, '#settings-form' )[0] ) && textOf( c14_box ).indexOf( texts['consents.note'] ) !== -1 );
	check( '...the hints give an example of each sentence and the box says that a purpose without a sentence has no box', textOf( c14_box ).indexOf( texts['consents.hint-social'] ) !== -1 && textOf( c14_box ).indexOf( texts['consents.hint-archive'] ) !== -1 );
	check( '...nothing typed, nothing to save', pkey18( c14_s ) === false && c14_posts.length === 0 );
	type( efield( c14_s, 'consent_archive' ), 'Archiv\nSatz', false );
	check( 'what is typed is unsaved input, and Strg+S in a field saves both sentences: crm-documents/settings-save with the two keys alone, the toast says it is saved', c14_s.app.state.draft.dirty === true && ( efield( c14_s, 'consent_archive' ).focused = true, pkey18( c14_s ) ) === true && ( await settle(), c14_posts.length === 1 ) && JSON.stringify( c14_posts[0] ) === '{"consent_social":"Satz <b>eins</b>\\nzwei","consent_archive":"Archiv\\nSatz"}'
		&& c14_s.toast.textContent === texts['consents.saved'] && c14_s.app.state.draft === null );
	type( efield( c14_s, 'consent_social' ), 'x'.repeat( 2001 ), false );
	submit( c14_s, c14_box );
	await settle();
	check( '...a refusal is said at the field it names, which is marked and focused, and the text stays as typed; the other field is not marked', efield( c14_s, 'consent_social' ).classList.contains( 'err' ) === true && efield( c14_s, 'consent_social' ).focused === true && efield( c14_s, 'consent_social' ).value.length === 2001 && efield( c14_s, 'consent_archive' ).classList.contains( 'err' ) === false && c14_posts.length === 2 );
	type( efield( c14_s, 'consent_social' ), '', false );
	submit( c14_s, c14_box );
	await settle();
	check( '...an empty sentence is posted as it is: the box goes', c14_posts[2].consent_social === '' && c14_posts[2].consent_archive === 'Archiv\nSatz' && efield( c14_s, 'consent_social' ).value === '' );
	const c14_s0 = await eopen( {} );
	check( '...where none is stored both fields are empty', efield( c14_s0, 'consent_social' ).value === '' && efield( c14_s0, 'consent_archive' ).value === '' && all( c14_s0.main, '#consents' ).length === 1 );
	check( 'the script asks for the words of the consents as texts, and every one is a word of the page', [ 'consent.purpose-', 'consent.line-given', 'consent.line-revoked', 'consent.line-none', 'consent.mark-title', 'consent.badge', 'consents.title', 'consents.note', 'consents.label-', 'consents.hint-', 'consents.save', 'consents.saved', 'record.kind-', 'record.title-', 'record.project', 'record.at', 'record.ip', 'record.agent', 'record.revoked-at', 'record.revoked-by', 'record.revoked-ip', 'record.revoked-agent' ].every( function( key ) { return script.indexOf( "'"+ key ) !== -1 } )
		&& [ 'record.kind-consent', 'record.kind-revocation', 'record.title-consent', 'record.title-revocation', 'consent.purpose-social', 'consent.purpose-archive', 'consents.label-social', 'consents.label-archive', 'consents.hint-social', 'consents.hint-archive' ].every( function( key ) { return typeof texts[key] === 'string' && texts[key] !== '' } ) );

	// the Kundenkarte: the head and the tab Portal
	const x18_kagreed = Object.assign( {}, kclient, { privacy : { asked : true, agreed : true, at : '2026-11-03T10:15:00Z', member_name : 'Lena Brandt' } } );
	const x18_kask = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : x18_kagreed, accounts : kaccounts } ] } } );
	check( 'the head of the Kundenkarte says "Datenschutz: zugestimmt am … durch …" for a client that has agreed to the text of now', textOf( all( x18_kask.main, '#client-privacy' )[0] ) === texts['privacy.head-agreed'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) && all( x18_kask.main, '#client-privacy' )[0].dataset.agreed === '1' );
	const x18_kopenPrivacy = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { privacy : { asked : true, agreed : false, at : '', member_name : '' } } ), accounts : kaccounts } ] } } );
	check( '...and "ausstehend" for one that has not', textOf( all( x18_kopenPrivacy.main, '#client-privacy' )[0] ) === texts['privacy.head-open'] && all( x18_kopenPrivacy.main, '#client-privacy' )[0].dataset.agreed === '0' );
	const x18_knone = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { privacy : { asked : false, agreed : false, at : '', member_name : '' } } ), accounts : kaccounts } ] } } );
	check( '...and nothing where the portal asks for no text, or where the answer has no standing', all( x18_knone.main, '#client-privacy' ).length === 0 && all( x18_kask.main, '.head' )[0] !== undefined && ( await kopen( '#kunde-1' ) ).main.querySelectorAll( '#client-privacy' ).length === 0 );
	const x18_krecords = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { records : [ x18_ordered, x18_record( { member_name : '', member_email : 'tom@nordlicht.example', shown : '<img src=x onerror=alert(1)>' } ) ] } ) ] } } );
	const x18_recordRows = all( all( x18_krecords.main, '#client-records' )[0], 'li' );
	check( 'the tab Portal lists the records of the client, the newest first: the kind, the time, the account - its address where it has no name - and, for an order, the link to the document', x18_recordRows.length === 2 && textOf( x18_recordRows[0] ).indexOf( texts['record.kind-order']+ ' · 03.11.2026 ' ) === 0 && textOf( x18_recordRows[0] ).indexOf( 'Lena Brandt' ) !== -1 && all( x18_recordRows[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-31' && textOf( all( x18_recordRows[0], 'a' )[0] ) === 'A-2026-0031'
		&& textOf( x18_recordRows[1] ).indexOf( texts['record.kind-privacy'] ) === 0 && textOf( x18_recordRows[1] ).indexOf( 'tom@nordlicht.example' ) !== -1 && all( x18_recordRows[1], 'a' ).length === 0 );
	click( x18_krecords, all( x18_recordRows[1], 'button' )[0] );
	check( '...a click on Anzeigen opens a dialog with the text that was shown as text - markup in it is not markup - and its hash', x18_krecords.dialogs.length === 1 && textOf( all( x18_krecords.dialogs[0], 'pre' )[0] ) === '<img src=x onerror=alert(1)>' && findAll( x18_krecords.dialogs[0], function( node ) { return node.tagName === 'IMG' } ).length === 0
		&& textOf( all( x18_krecords.dialogs[0], 'p' )[0] ) === texts['record.hash'].replace( '%s', 'a'.repeat( 64 ) ) && x18_krecords.dialogs[0].classList.contains( 'wide' ) === true && all( x18_krecords.dialogs[0], 'pre' )[0].classList.contains( 'sheet' ) === true && all( x18_krecords.dialogs[0], 'button' ).length === 1 && textOf( all( x18_krecords.dialogs[0], 'button' )[0] ) === texts['dialog.close'] );
	click( x18_krecords, all( x18_krecords.dialogs[0], 'button' )[0] );
	check( '...and Schließen closes it', x18_krecords.dialogs[0].open === false );
	const x18_knorecords = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { records : [] } ) ] } } );
	check( 'a client with no x18_record says so', textOf( all( x18_knorecords.main, '#client-records' )[0] ) === texts['cportal.records-none'] );

	// the Beleg: head, history, tab Portal
	const x18_orderView = function( over ) { return view( Object.assign( { id : 31, category : 'offer', number : 'A-2026-0031', state : 'accepted', due_date : null, valid_until : '2026-12-01', released : true, released_at : '2026-11-01T09:00:00Z', finalised : '2026-10-30T09:00:00Z' }, over || {} ), { order : x18_ordered } ); };
	const x18_bo = world( { hash : '#beleg-31', answers: { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/get' : function() { return [ 200, x18_orderView() ] } } } );
	x18_bo.boot();
	await settle();
	check( 'the head of an offer that was x18_ordered in the portal says so under the head: "Beauftragt im Portal am … durch …"', textOf( all( x18_bo.main, '#order-head' )[0] ).indexOf( texts['order.head'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) ) !== -1 );
	click( x18_bo, all( x18_bo.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === 'history' } ) );
	check( 'the history has the line of the order with its time among the others, in the order of the times', textOf( all( x18_bo.main, '.log' )[0] ).indexOf( texts['history.ordered'].replace( '%s', 'Lena Brandt' ) ) !== -1 && textOf( all( x18_bo.main, '.log' )[0] ).indexOf( texts['history.ordered'].replace( '%s', 'Lena Brandt' ) ) > textOf( all( x18_bo.main, '.log' )[0] ).indexOf( texts['history.released'] ) );
	click( x18_bo, all( x18_bo.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === 'portal' } ) );
	const x18_ocard = all( x18_bo.main, '#order-card' )[0];
	check( 'the tab Portal shows the x18_record: the time, the account with its address, the address of the request, the browser and the hash', x18_ocard !== undefined && textOf( x18_ocard ).indexOf( '03.11.2026' ) !== -1 && textOf( x18_ocard ).indexOf( 'Lena Brandt <lena@nordlicht.example>' ) !== -1 && textOf( x18_ocard ).indexOf( '203.0.113.7' ) !== -1 && textOf( x18_ocard ).indexOf( 'Browser/1.0' ) !== -1 && textOf( x18_ocard ).indexOf( 'b'.repeat( 64 ) ) !== -1 );
	click( x18_bo, all( x18_bo.main, '#order-show' )[0] );
	check( '...the card has its space under the columns', x18_ocard.classList.contains( 'gap' ) === true );
	check( '...and the JSON that was shown opens in a dialog as text', x18_bo.dialogs.length === 1 && textOf( all( x18_bo.dialogs[0], 'pre' )[0] ) === x18_ordered.shown );
	const x18_bn = world( { hash : '#beleg-31', answers: { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/get' : function() { return [ 200, view( { id : 31, category : 'offer', state : 'accepted' } ) ] } } } );
	x18_bn.boot();
	await settle();
	click( x18_bn, all( x18_bn.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === 'portal' } ) );
	check( 'an offer the owner accepted has no line under the head, no card of an order and no line in the history', all( x18_bn.main, '#order-head' ).length === 0 && all( x18_bn.main, '#order-card' ).length === 0 );

	// the overview
	const x18_ov = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ { kind : 'orders', id : 0, client_id : 0, client_title : '', number : '', title : '', date : '', gross : 119000, sign_state : '', count : 3 } ], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : [ row( { id : 5, category : 'offer', state : 'accepted' }), row( { id : 6, category : 'offer', state : 'open' }) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
	x18_ov.boot();
	await settle();
	const x18_orderLine = all( x18_ov.main, '#needs-orders' )[0];
	check( '"Braucht Dich" has one line for the orders of the portal: how many, the sum, and a link to the Belege', x18_orderLine !== undefined && textOf( x18_orderLine ) === texts['needs.orders-many'].replace( '%s', '3' ) && x18_orderLine.getAttribute( 'href' ) === '#belege' && textOf( all( x18_ov.main, '.list-mini' )[0].children[0] ).indexOf( '1.190,00'+ NBSP+ '€' ) !== -1 );
	click( x18_ov, x18_orderLine );
	x18_ov.location.hash = '#belege';
	await settle();
	check( '...the click shows the Belege filtered by the state accepted: the chip is on and only the accepted offer is in the table', all( x18_ov.main, '.tabs button' ).find( function( b ) { return b.dataset.chip === 'accepted' } ).classList.contains( 'on' ) === true && all( x18_ov.main, 'tbody tr' ).length === 1 );
	const x18_ov1 = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ { kind : 'orders', id : 0, client_id : 0, client_title : '', number : '', title : '', date : '', gross : 100, sign_state : '', count : 1 } ], recent : [], settings : settings } ] } } } );
	x18_ov1.boot();
	await settle();
	check( 'a single order is said in the singular', textOf( all( x18_ov1.main, '#needs-orders' )[0] ) === texts['needs.orders-one'].replace( '%s', '1' ) );
	const x13_need = function( count ) { return { kind : 'approved', id : 0, client_id : 0, client_title : '', number : '', title : '', date : '', gross : 0, sign_state : '', count : count } };
	const x13_ov = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ x13_need( 3 ) ], recent : [], settings : settings } ] } } } );
	x13_ov.boot();
	await settle();
	check( '"Braucht Dich" has one line for the approved projects that no invoice names: how many, with a link to the projects', textOf( all( x13_ov.main, '#needs-approved' )[0] ) === texts['needs.approved-many'].replace( '%s', '3' ) && all( x13_ov.main, '#needs-approved' )[0].getAttribute( 'href' ) === '#projekte' && all( x13_ov.main, '.list-mini' )[0].children.length === 1 );
	click( x13_ov, all( x13_ov.main, '#needs-approved' )[0] );
	check( '...the click asks for the done projects too: the filter of the projects shows all', x13_ov.app.state.jfilter.all === true );
	const x13_ov1 = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ x13_need( 1 ) ], recent : [], settings : settings } ] } } } );
	x13_ov1.boot();
	await settle();
	check( '...one project is said in the singular, and with no such need there is no such line', textOf( all( x13_ov1.main, '#needs-approved' )[0] ) === texts['needs.approved-one'].replace( '%s', '1' ) && all( x13_ov.main, '#needs-orders' ).length === 0 );
	const x13_ov0 = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] } } } );
	x13_ov0.boot();
	await settle();
	check( '...nor does an empty list of needs', all( x13_ov0.main, '#needs-approved' ).length === 0 );
	const x13_pp = await eopen( { settings : Object.assign( {}, esettings, { paypal : 'StudioNord' } ) } );
	check( 'the Einstellungen have the field of the PayPal.me name in the block Rechnungsangaben: a text field of 80 characters with the name as saved, and the hint that the customer gets a link with the amount', efield( x13_pp, 'paypal' ).value === 'StudioNord' && efield( x13_pp, 'paypal' ).getAttribute( 'maxlength' ) === '80' && efield( x13_pp, 'paypal' ).type === 'text'
		&& all( x13_pp.main, '#particulars dt' ).map( textOf ).pop() === texts['settings.paypal'] && textOf( all( x13_pp.main, '#particulars' )[0] ).indexOf( texts['settings.paypal-hint'] ) !== -1 );
	const x13_posted = [];
	const x13_pp2 = await eopen( {}, undefined, { 'crm-documents/settings-save' : function( data ) { x13_posted.push( data ); return data.paypal === '<b>' ? [ 400, { error : 'x', code : 'crm_documents_particular_paypal', params : [], field : 'paypal' } ] : [ 200, { settings : Object.assign( {}, esettings, data, { vat_rates : '1900,700,550,0' } ) } ] } } );
	type( efield( x13_pp2, 'paypal' ), '<b>', false );
	submit( x13_pp2, all( x13_pp2.main, '#settings-form' )[0] );
	await settle();
	check( 'a name that is none is said beside its field in its own sentence, the field marked and focused, and the post carried the typed value as text', x13_posted[0].paypal === '<b>' && efield( x13_pp2, 'paypal' ).classList.contains( 'err' ) === true && efield( x13_pp2, 'paypal' ).focused === true );
	const x13_approval = { id : 7, kind : 'approval', client_id : 1, member_id : 'aaaaaaaaaaaa', member_email : 'lena@nordlicht.example', member_name : 'Lena <b>Brandt</b>', document_id : null, project : '00001', sha256 : 'c'.repeat( 64 ), shown : '{"files":[{"name":"<img src=x onerror=alert(1)>"}]}', at : '2026-11-03T10:15:00Z', ip : '203.0.113.7', agent : 'Browser/1.0' };
	const x13_krecords = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { records : [ Object.assign( {}, x13_approval, { document_number : '' } ) ] } ) ] } } );
	const x13_row = all( all( x13_krecords.main, '#client-records' )[0], 'li' )[0];
	check( 'the tab Portal lists an approval among the records: the kind, the time, the account as text and, instead of a document, the link to the card of the project', x13_row.dataset.kind === 'approval' && textOf( x13_row ).indexOf( texts['record.kind-approval']+ ' · 03.11.2026 ' ) === 0 && textOf( x13_row ).indexOf( 'Lena <b>Brandt</b>' ) !== -1 && all( x13_row, 'a' )[0].getAttribute( 'href' ) === '#projekt-00001' && textOf( all( x13_row, 'a' )[0] ) === texts['record.project']+ ' 00001' );
	click( x13_krecords, all( x13_row, 'button' )[0] );
	check( '...Anzeigen opens the dialog with the title of an approval', x13_krecords.dialogs[0].getAttribute( 'aria-label' ) === texts['record.title-approval'] && textOf( all( x13_krecords.dialogs[0], 'pre' )[0] ) === x13_approval.shown );
	check( 'the script keeps to its rules for the new screens: the texts are asked for as texts, and every new key is a word of the page', [ 'privacy.title', 'privacy.note', 'privacy.label', 'privacy.hint', 'privacy.never', 'privacy.changed', 'privacy.save', 'privacy.saved', 'privacy.head-agreed', 'privacy.head-open', 'record.document', 'record.show', 'record.hash', 'order.badge', 'order.head', 'order.title', 'order.at', 'order.by', 'order.ip', 'order.agent', 'order.hash', 'order.show', 'cportal.records', 'cportal.records-none', 'history.ordered', 'needs.orders-one', 'needs.orders-many', 'dialog.close' ].every( function( key ) { return typeof texts[key] === 'string' && texts[key] !== '' && script.indexOf( "'"+ key+ "'" ) !== -1 } ) && [ 'record.kind-', 'record.title-' ].every( function( stem ) { return script.indexOf( "'"+ stem+ "'" ) !== -1 && [ 'privacy', 'order' ].every( function( kind ) { return typeof texts[stem+ kind] === 'string' } ) } ) && typeof texts['chip.accepted'] === 'string' );

	// === /crm im Alltag
	await everydayChecks();

	/**
	 *	The small things of every day: the Enter of the catalogue, the phone's rail and tiles, the Einstellungen (read again after the setup, the bar that jumps, a sentence at each save,
	 *	the box of the mail for new files), the buttons that say what the box makes of them, Neuer Kunde with its first account first and the invitation that did not go out, the
	 *	Kundenkarte (what is owed, a message as one line, mail and phone as links), the upload with its drop area and the sentence about the mail, what Braucht Dich names, the search of the
	 *	Übersicht, the dialog of every release with its sentence to the customer, the reminder in sight, the unit of the electronic invoice, the files of the Uploads in the singular and the
	 *	projects of a client
	 *
	 *	@return		{Promise}
	 */
	async function everydayChecks() {

		const model = ( await kopen( '#kunden' ) ).app.model;
		const fill = function( key, ...values ) { let at = 0; return texts[key].replace( /%s/g, function() { return String( values[at++] ?? '' ) } ) };
		const fireOn = function( node, name, extra ) { let prevented = false; fire( node, name, Object.assign( { type : name, target : node, preventDefault : function() { prevented = true }, stopPropagation : function() {} }, extra || {} ) ); return prevented; };

		// --- the model: what the pure functions answer
		const catalogue = [ { title : 'Aufnahme Studio 1' }, { title : 'Auf' }, { title : 'Mastering' }, { title : 'Re-Mastering' } ];
		check( 'the item the Enter of the catalogue takes: the one with the title as typed, else the first whose title begins with the text, else the first that holds it - and none for an empty text or a text nothing holds', model.firstItem( catalogue, 'Auf' ).title === 'Auf' && model.firstItem( catalogue, 'auf' ).title === 'Auf' && model.firstItem( catalogue, 'Aufn' ).title === 'Aufnahme Studio 1'
			&& model.firstItem( catalogue, 'master' ).title === 'Mastering' && model.firstItem( [ { title : 'Re-Mastering' }, { title : 'Mastering' } ], 'mas' ).title === 'Mastering' && model.firstItem( catalogue, 'stering' ).title === 'Mastering' && model.firstItem( catalogue, '  ' ) === undefined && model.firstItem( catalogue, 'zzz' ) === undefined );
		check( 'the owed of a client counts the invoices that are open or sent - their sum, how many, how many of those are late - and nothing else', JSON.stringify( model.owedOf( [ row( { state : 'sent', gross : 1000 } ), row( { state : 'open', gross : 500, overdue : true } ), row( { state : 'paid', gross : 700 } ), row( { category : 'offer', state : 'open', gross : 900 } ), row( { state : 'draft', gross : 400 } ) ] ) ) === '{"cents":1500,"count":2,"late":1}' && model.owedOf( [] ).count === 0 );
		check( 'a link is made of an address or a number that has the plain shape and of nothing else: no second address, no query, no scheme, no script - an address with a ? or a & or a % or a comma is text', model.mailtoOf( 'lena@nordlicht.example' ) === 'mailto:lena@nordlicht.example' && model.mailtoOf( 'a.b+c@sub.example.org' ) === 'mailto:a.b+c@sub.example.org'
			&& [ "o'hara?subject=x@b.de", 'a&b=c@d.de', 'a@b.de?bcc=x@y.de', 'a@b.de,c@d.de', 'a%40b@c.de', 'javascript:alert(1)', '<a@b.de>', 'a@b', 'a b@c.de', '', null, 7 ].every( function( bad ) { return model.mailtoOf( bad ) === '' } )
			&& model.telOf( '+49 40 123456' ) === 'tel:+4940123456' && model.telOf( '+49 (0) 40 123456' ) === 'tel:+4940123456' && model.telOf( '040 (0) 12345' ) === 'tel:040012345' && model.telOf( '040/123-456' ) === 'tel:040123456' && [ 'javascript:1', '+49;ATD', '12', 'abc', '', '+49 (0) 40 <b>', null ].every( function( bad ) { return model.telOf( bad ) === '' } ) );
		const pool = { clients : Array.from( { length : 7 }, function( unused, index ) { return { id : index + 1, number : 'K-000'+ ( index + 1 ), title : 'Test '+ ( index + 1 ), company : 'Test '+ ( index + 1 ), name : '', email : '', city : '' } } ),
			projects : [ { id : '00001', title : 'Test Album', client : 'K-0001', client_title : 'Test 1' }, { id : '00002', title : 'Anders', client : 'K-0002', client_title : 'Test 2' } ], documents : [ row( { id : 5, number : 'R-2026-0005', client_title : 'Test 1' } ), row( { id : 6, category : 'offer', number : 'A-2026-0006', client_title : 'Test 3' } ), row( { id : 7, state : 'draft', number : '', title : '', client_title : 'Anders' } ) ] };
		const found = model.hitsOf( pool, 'test' );
		check( 'the hits of a text are the clients, the projects and the documents in this order, at most five of each, each with the address of its screen - a client its card, a project its card, a document its screen, a draft its editor', found.map( function( hit ) { return hit.kind } ).join() === 'client,client,client,client,client,project,project,document,document'
			&& found[0].href === '#kunde-1' && found[5].href === '#projekt-00001' && found[7].href === '#beleg-5' && model.hitsOf( pool, 'anders' ).map( function( hit ) { return hit.href } ).join() === '#projekt-00002,#entwurf-7' && model.hitsOf( pool, '   ' ).length === 0 );

		// --- the catalogue: Enter takes the first hit, and a refused line is marked
		let edit = await editing();
		const adder = field( edit, 'additem' );
		adder.value = 'Mas';
		const kept = fireOn( adder, 'keydown', { key : 'Enter' } );
		check( 'Enter in the search of the catalogue takes the first item the text finds: Mas becomes the line Mastering with its unit and its price from the catalogue, the field is empty and keeps the cursor - and the Enter is kept from the form', kept === true && lineRows( edit ).length === 3 && all( lineRows( edit )[2], 'input' )[0].value === 'Mastering' && all( lineRows( edit )[2], 'input' )[3].value === '110,00' && field( edit, 'additem' ).value === '' && field( edit, 'additem' ).focused === true );
		const adderAgain = field( edit, 'additem' );
		adderAgain.value = 'xyz';
		fireOn( adderAgain, 'keydown', { key : 'Enter' } );
		check( '...a text no title holds is a line of its own as it was: the text as its title, one piece, no price, and the cursor goes to the price', lineRows( edit ).length === 4 && all( lineRows( edit )[3], 'input' )[0].value === 'xyz' && all( lineRows( edit )[3], 'input' )[3].value === '' && all( lineRows( edit )[3], 'input' )[3].focused === true );
		const refusing = await editing( {}, { 'crm-documents/save' : function() { return [ 400, { error : 'x', code : 'crm_documents_line_amount', params : [ 1 ], field : 'lines.0.price' } ] } } );
		click( refusing, field( refusing, 'save' ) );
		await settle();
		const priceOne = all( lineRows( refusing )[0], 'input' )[3];
		check( 'a line the server refuses is marked at the field it names - aria-invalid, the mark of the error, the cursor in it - and the sentence stands over the editor as it did', priceOne.getAttribute( 'aria-invalid' ) === 'true' && priceOne.classList.contains( 'err' ) === true && priceOne.focused === true && textOf( field( refusing, 'message' ) ).indexOf( 'Position 1' ) !== -1 );
		const unnamed = await editing( {}, { 'crm-documents/save' : function() { return [ 400, { error : 'x', code : 'crm_documents_line_format', params : [ 2 ], field : 'lines.1' } ] } } );
		click( unnamed, field( unnamed, 'save' ) );
		await settle();
		check( '...a line refused for something no field of it holds is marked at its title', all( lineRows( unnamed )[1], 'input' )[0].getAttribute( 'aria-invalid' ) === 'true' && all( lineRows( unnamed )[1], 'input' )[0].focused === true );

		// --- the unit of the electronic invoice is for a business client
		const unitSelects = function( w ) { return all( w.main, 'select' ).filter( function( one ) { return one.classList.contains( 'unit-code' ) } ); };
		const privateClients = clients.map( function( one ) { return one.id === 1 ? Object.assign( {}, one, { business : false } ) : one } );
		const privateDraft = await editing( {}, { 'crm-documents/get' : function() { return [ 200, view( draftDoc, { clients : privateClients } ) ] } } );
		check( 'the second unit - the one of the electronic invoice - is not shown for a client who is no business: the lines have the unit as text only', unitSelects( privateDraft ).length === 0 && unitSelects( edit ).length === 4 );
		click( privateDraft, field( privateDraft, 'save' ) );
		await settle();
		check( '...and the line keeps the code of its position: it is posted as it was', privateDraft.saves[0].lines[0].unit_code === 'HUR' && privateDraft.saves[0].lines[1].unit_code === '' );
		type( field( privateDraft, 'f-client_id' ), '2' );
		await settle();
		check( '...another client who is a business gives each line the second unit', unitSelects( privateDraft ).length === 2 );

		// --- the phone: one row of the rail that scrolls, the tiles flat
		const rail = world( { hash : '#einstellungen', answers : kanswers( { 'crm-documents/settings-get' : function() { return [ 200, Object.assign( {}, eview ) ] }, 'crm/status' : function() { return [ 200, { items : eok } ] } } ) } );
		rail.nav.scrollLeft = 0;
		rail.nav.getBoundingClientRect = function() { return { left : 0, right : 300 } };
		all( rail.nav, '[data-r]' ).forEach( function( anchor, index ) { anchor.getBoundingClientRect = function() { return { left : index * 110 - rail.nav.scrollLeft, right : index * 110 + 100 - rail.nav.scrollLeft } } } );
		rail.boot();
		await settle();
		check( 'the rail is brought to the open entry: Einstellungen, the last, is beyond the right edge of a row that scrolls, so the row scrolls by the part that was out of sight - and the page does not', rail.nav.scrollLeft === 7 * 110 + 100 - 300 && rail.scrolls.length === 0 );
		const css = source( OWN+ 'assets/crm.css' );
		const phone = css.slice( css.lastIndexOf( '@media (max-width: 760px)' ) );
		check( 'on the phone the rail is one row that scrolls and does not wrap, the tiles are a grid of two columns of equal width - minmax(0, 1fr), so that no long word widens one - with the number above its word and no line that cannot wrap, and the entries are 44px high', /\.rail \{[^}]*flex-wrap: nowrap/.test( phone ) && /\.nav \{[^}]*overflow-x: auto/.test( phone ) && /\.nav \{[^}]*flex-wrap: nowrap/.test( phone ) && /\.tiles \{[^}]*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\)/.test( phone ) && /\.tile \{[^}]*min-width: 0/.test( phone ) && /\.tile \{[^}]*flex-direction: column/.test( phone ) && /nowrap/.test( phone.slice( phone.indexOf( '.tile ' ), phone.indexOf( '.head .search-box' ) ) ) === false && css.indexOf( '.tiles, .tiles' ) === -1 && /\.tile \.v \{[^}]*order: -1/.test( phone ) && /\.nav a \{[^}]*min-height: 44px/.test( phone ) );
		const start = await kopen( '#uebersicht', { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ { kind : 'overdue', id : 5, client_id : 1, client_title : 'Nordlicht', number : 'R-1', title : '', date : '2026-10-01', gross : 100, sign_state : '', reminded_at : '' } ], recent : [ { id : 1, category : 'invoice', number : 'R-2', title : '', state : 'open', client_title : 'N', date : '2026-10-02' } ], settings : settings } ] } } );
		const startCards = all( start.main, '.cols' )[0].children;
		check( 'the Übersicht has five tiles, each the number and its word, and "Braucht Dich" before "Zuletzt"', all( start.main, '.tile' ).length === 5 && all( start.main, '.tile' ).every( function( tile ) { return all( tile, '.v' ).length === 1 && all( tile, '.l' ).length === 1 } ) && textOf( all( startCards[0], 'h3' )[0] ) === texts['needs.title'] && textOf( all( startCards[1], 'h3' )[0] ) === texts['recent.title'] );

		// --- Einstellungen
		const setting = await eopen();
		await settle();
		const jump = all( setting.main, '#settings-jump' )[0];
		check( 'a bar over the Einstellungen jumps to each block - Umsatzsteuer, Nummern, Rechnungsangaben, Aussteller, Datenschutz, Einwilligungen, Nachricht, Firma, Portal - as buttons, so that the address still names the screen, and every block it names is on the screen', jump !== undefined && all( jump, 'button' ).map( function( button ) { return button.dataset.block } ).join() === 'vat,numbers,particulars,seller,privacy,consents,message-all,firma-holder,portal'
			&& all( jump, 'button' ).length === 9 && all( jump, 'a' ).length === 0 && all( jump, 'button' ).every( function( button ) { return findAll( setting.main, function( node ) { return node.id === button.dataset.block } ).length === 1 } ) && Array.from( setting.main.children ).indexOf( jump ) < Array.from( setting.main.children ).indexOf( all( setting.main, '#settings-form' )[0] ) );
		click( setting, all( jump, 'button' )[4] );
		check( '...a button brings its block into view and is marked as the open one', setting.scrolled.pop()[0] === 'privacy' && all( jump, '.on' ).map( function( b ) { return b.dataset.block } ).join() === 'privacy' && all( jump, 'button' )[4].getAttribute( 'aria-current' ) === 'true' );
		jump.scrollLeft = 0;
		jump.getBoundingClientRect = function() { return { left : 0, right : 300 } };
		all( jump, 'button' ).forEach( function( button, index ) { button.getBoundingClientRect = function() { return { left : index * 110 - jump.scrollLeft, right : index * 110 + 100 - jump.scrollLeft } } } );
		click( setting, all( jump, 'button' )[8] );
		check( '...the bar scrolls sideways on the phone, so the button of the block that is open is brought into it - the bar scrolls, the page does not', jump.scrollLeft === 8 * 110 + 100 - 300 && all( jump, '.on' )[0].dataset.block === 'portal' );
		all( jump, 'button' ).forEach( function( button, index ) { const target = findAll( setting.main, function( node ) { return node.id === button.dataset.block } )[0]; target.getBoundingClientRect = function() { return { top : index < 6 ? index * 10 - 200 : 500 + index } } } );
		( setting.windowListeners.scroll || [] ).forEach( function( fn ) { fn() } );
		check( '...a choice made with a click stands while the page scrolls by itself: the scroll the click made does not mark another block', all( jump, '.on' ).map( function( b ) { return b.dataset.block } ).join() === 'portal' );
		( setting.windowListeners.wheel || [] ).forEach( function( fn ) { fn() } );
		( setting.windowListeners.scroll || [] ).forEach( function( fn ) { fn() } );
		check( '...and the block that is in view marks itself while the page scrolls: the last whose top has passed the bar', all( jump, '.on' ).map( function( b ) { return b.dataset.block } ).join() === 'consents' && ( setting.windowListeners.scroll || [] ).length === 1 );
		const rects = function( tops ) { all( jump, 'button' ).forEach( function( button, index ) { findAll( setting.main, function( node ) { return node.id === button.dataset.block } )[0].getBoundingClientRect = function() { return { top : tops[index] } } } ); };
		rects( [ -300, -300, 400, 500, 600, 700, 800, 900, 1000 ] );
		( setting.windowListeners.wheel || [] ).forEach( function( fn ) { fn() } );
		( setting.windowListeners.scroll || [] ).forEach( function( fn ) { fn() } );
		check( 'two blocks side by side share a top: the first of them is the one in view - Umsatzsteuer, not Nummern', all( jump, '.on' ).map( function( b ) { return b.dataset.block } ).join() === 'vat' );
		setting.page.documentElement = { scrollHeight : 2000 };
		setting.win.scrollY = 1200;
		setting.win.innerHeight = 800;
		rects( [ -1100, -1100, -900, -700, -500, -300, -100, 300, 700 ] );
		( setting.windowListeners.scroll || [] ).forEach( function( fn ) { fn() } );
		check( 'at the foot of the page the last block that is in the picture is the one in view, though its top never passes the bar', all( jump, '.on' ).map( function( b ) { return b.dataset.block } ).join() === 'portal' );
		setting.win.scrollY = 0;
		setting.win.innerHeight = undefined;
		const saveNotes = [ 'settings-form', 'privacy', 'consents', 'message-all' ].map( function( id ) { return all( all( setting.main, '#'+ id )[0], '.save-what' ).length } );
		check( 'every Speichern says in a sentence beside it what it saves: the settings, the Datenschutz, the Einwilligungen, the message to all (the Firmendaten have theirs for an account that may write them)', saveNotes.join() === '1,1,1,1' && textOf( all( setting.main, '#settings-what' )[0] ) === texts['settings.saves'] && texts['settings.saves'] !== texts['privacy.saves'] );
		const notifyBox = all( setting.main, '#settings-notify-files' )[0];
		check( 'the block Portal has the box Kunden bei neuen Dateien informieren: ticked at the default, which the settings say with "1"', notifyBox !== undefined && notifyBox.checked === true && all( all( setting.main, '#portal' )[0], 'label' ).some( function( label ) { return textOf( label ).trim() === texts['settings.notify-files'] } ) );
		const notifyPosts = [];
		const off = await eopen( { settings : Object.assign( {}, esettings, { notify_files : '0' } ) }, undefined, { 'crm-documents/settings-save' : function( data ) { notifyPosts.push( data ); return [ 200, { settings : Object.assign( {}, esettings, { vat_rates : '1900,700,550,0', notify_files : data.notify_files } ) } ] } } );
		check( '...a setting of "0" leaves it unticked', all( off.main, '#settings-notify-files' )[0].checked === false );
		all( off.main, '#settings-notify-files' )[0].checked = true;
		fireOn( all( off.main, '#settings-notify-files' )[0], 'change' );
		await settle();
		check( '...a change of the box is saved at once and alone: crm-documents/settings-save { notify_files: "1" }, and the toast says what it means', JSON.stringify( notifyPosts ) === JSON.stringify( [ { notify_files : '1' } ] ) && off.toast.textContent === texts['settings.notify-files-on'] );
		const offPosts = [];
		const unticking = await eopen( {}, undefined, { 'crm-documents/settings-save' : function( data ) { offPosts.push( data ); return [ 200, { settings : Object.assign( {}, esettings, { vat_rates : '1900,700,550,0', notify_files : data.notify_files } ) } ]; } } );
		all( unticking.main, '#settings-notify-files' )[0].checked = false;
		fireOn( all( unticking.main, '#settings-notify-files' )[0], 'change' );
		await settle();
		check( '...and taking the tick away posts "0" and says so', JSON.stringify( offPosts ) === JSON.stringify( [ { notify_files : '0' } ] ) && unticking.toast.textContent === texts['settings.notify-files-off'] );
		const refusedOff = await eopen( { settings : Object.assign( {}, esettings, { notify_files : '0' } ) }, undefined, { 'crm-documents/settings-save' : function() { return [ 400, { error : 'x', code : 'crm_documents_setting', params : [], field : 'notify_files' } ] } } );
		all( refusedOff.main, '#settings-notify-files' )[0].checked = true;
		fireOn( all( refusedOff.main, '#settings-notify-files' )[0], 'change' );
		await settle();
		check( '...a refusal puts the box back as it was', all( refusedOff.main, '#settings-notify-files' )[0].checked === false && all( refusedOff.main, '#settings-notify-files' )[0].disabled === false );

		// --- the buttons say what the box makes of them
		const kc = await kopen( '#kunde-1' );
		const accountMenu = function( row ) { return all( row, '.menu' )[0]; };
		const invitedRow = all( kc.main, '.acc' ).find( function( one ) { return one.dataset.id === 'bbbbbbbbbbbb' } );
		click( kc, all( accountMenu( invitedRow ), 'button' )[0] );
		click( kc, all( accountMenu( invitedRow ), '[role="menuitem"]' ).find( function( item ) { return textOf( item ) === texts['acc.resend-invite'] } ) );
		const resend = kc.dialogs[kc.dialogs.length - 1];
		const resendYes = function() { return all( resend, 'button' ).pop(); };
		check( 'the dialog that sends an invitation again says Einladung senden on its button while the box of the mail is ticked, and Link anzeigen when it is not - and the mail again when it is ticked again', textOf( resendYes() ) === texts['acc.resend-yes-invite'] && ( all( resend, '#dlg-notify' )[0].checked = false, fireOn( all( resend, '#dlg-notify' )[0], 'change' ), textOf( resendYes() ) === texts['acc.resend-show'] )
			&& ( all( resend, '#dlg-notify' )[0].checked = true, fireOn( all( resend, '#dlg-notify' )[0], 'change' ), textOf( resendYes() ) === texts['acc.resend-yes-invite'] ) );
		const signedRow = all( kc.main, '.acc' ).find( function( one ) { return one.dataset.id === 'aaaaaaaaaaaa' } );
		click( kc, all( accountMenu( signedRow ), 'button' )[0] );
		click( kc, all( accountMenu( signedRow ), '[role="menuitem"]' ).find( function( item ) { return textOf( item ) === texts['acc.resend-login'] } ) );
		check( '...for an account that has signed in it is the sign-in link: Anmeldelink senden', textOf( all( kc.dialogs[kc.dialogs.length - 1], 'button' ).pop() ) === texts['acc.resend-yes-login'] );
		click( kc, all( kc.main, '#invite-open' )[0] );
		const inviting = await kopen( '#kunde-1', { 'crm-clients/invite' : function( data ) { return [ 200, { account : { id : data.email === 'ok@example.org' ? 'ffffffffffff' : 'gggggggggggg', gone : false, state : 'invited', email : data.email, name : '', active : true, login : null }, mailed : data.email === 'ok@example.org' } ]; } } );
		click( inviting, all( inviting.main, '#invite-open' )[0] );
		type( all( inviting.main, '#f-invite-email' )[0], 'ok@example.org', false );
		fire( all( inviting.main, '#invite' )[0], 'submit', { target : all( inviting.main, '#invite' )[0], preventDefault : function() {} } );
		await settle();
		const sentOk = all( inviting.main, '#unsent-ffffffffffff' ).length;
		click( inviting, all( inviting.main, '#invite-open' )[0] );
		type( all( inviting.main, '#f-invite-email' )[0], 'fail@example.org', false );
		fire( all( inviting.main, '#invite' )[0], 'submit', { target : all( inviting.main, '#invite' )[0], preventDefault : function() {} } );
		await settle();
		check( 'an invitation sent from the form of the card that did not go out stays said in red at its account, with the button; one that went out has no such line', sentOk === 0 && all( inviting.main, '#unsent-gggggggggggg' ).length === 1 && all( inviting.main, '#unsent-ffffffffffff' ).length === 0 );
		const inviteNow = function() { return all( kc.main, '#invite-send' )[0]; };
		check( 'the form that invites a new account has the same button: Einladung senden with the box, Link anzeigen without it', textOf( inviteNow() ) === texts['invite.send'] && ( all( kc.main, '#f-invite-notify' )[0].checked = false, fireOn( all( kc.main, '#f-invite-notify' )[0], 'change' ), textOf( inviteNow() ) === texts['invite.show'] ) );
		const linked = await kopen( '#kunde-1', { 'crm-clients/accounts' : function() { return [ 200, { accounts : kaccounts } ] } } );
		click( linked, all( all( linked.main, '.menu' ).find( function( one ) { return all( one, '[role="menuitem"]' ).some( function( item ) { return textOf( item ) === texts['plink.menu-data'] } ) } ), 'button' )[0] );
		click( linked, all( linked.main, '[role="menuitem"]' ).find( function( item ) { return textOf( item ) === texts['plink.menu-data'] } ) );
		await settle();
		const linkDialog = linked.dialogs[linked.dialogs.length - 1];
		check( 'the dialog of the link for the customer makes the link while the box of the mail is off - Link erzeugen - and sends it when the box is ticked - Link senden', textOf( all( linkDialog, 'button' ).pop() ) === texts['plink.make'] && ( all( linkDialog, '#dlg-mail' )[0].checked = true, fireOn( all( linkDialog, '#dlg-mail' )[0], 'change' ), textOf( all( linkDialog, 'button' ).pop() ) === texts['plink.send'] ) );

		// --- Neuer Kunde: the invitation that did not go out stays said at the account
		const neu = await kopen( '#kunde-neu', { 'crm-clients/save' : function( data ) {
			return [ 200, { client : Object.assign( {}, kclient, { id : 9, number : 'K-0009' } ), first : { account : { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : data.account_email, name : '', active : true, login : null }, mailed : false, refused : null } } ];
		}, 'crm-clients/get' : function( data ) { return [ 200, { client : Object.assign( {}, kclient, { id : Number( data.id ) } ), accounts : [ { id : 'eeeeeeeeeeee', gone : false, state : 'invited', email : 'neu@example.org', name : '', active : true, login : null } ] } ] } } );
		const newForm = all( neu.main, '#new-client-form' )[0];
		type( all( newForm, '#f-account_email' )[0], 'neu@example.org', false );
		all( newForm, '#f-account_notify' )[0].checked = false;
		fireOn( all( newForm, '#f-account_notify' )[0], 'change' );
		check( 'the button of Neuer Kunde follows the box too: Anlegen und einladen with it, Anlegen und Link anzeigen without it', textOf( all( newForm, '#client-create' )[0] ) === texts['newclient.create-link'] && ( all( newForm, '#f-account_notify' )[0].checked = true, fireOn( all( newForm, '#f-account_notify' )[0], 'change' ), textOf( all( newForm, '#client-create' )[0] ) === texts['newclient.create-invite'] ) );
		fire( newForm, 'submit', { target : newForm, preventDefault : function() {} } );
		await settle();
		neu.location.hash = '#kunde-9';
		await settle();
		const unsent = all( neu.main, '#unsent-eeeeeeeeeeee' )[0];
		check( 'a customer made with an invitation that did not go out has the account on its card with a red line - the invitation has not gone out - and the button Noch einmal senden, which stays on the screen and not in a toast', unsent !== undefined && unsent.getAttribute( 'role' ) === 'alert' && textOf( all( unsent, 'span' )[0] ) === texts['acc.unsent'] && textOf( all( unsent, 'button' )[0] ) === texts['acc.unsent-again'] );
		neu.answers['crm-clients/account-resend'] = function() { return [ 500, { error : 'x', code : 'crm_clients_mail' } ]; };
		click( neu, all( unsent, 'button' )[0] );
		await settle();
		check( '...a mail that does not go out again leaves the line and the button where they are, with the sentence of the refusal as a toast', all( neu.main, '#unsent-eeeeeeeeeeee' ).length === 1 && neu.toast.classList.contains( 'bad' ) === true && all( unsent, 'button' )[0].disabled === false );
		neu.answers['crm-clients/account-resend'] = function() { return [ 200, { sent : true, kind : 'invite' } ]; };
		click( neu, all( all( neu.main, '#unsent-eeeeeeeeeeee' )[0], 'button' )[0] );
		await settle();
		check( '...a mail that goes out posts crm-clients/account-resend { id, account, notify: true }, takes the line away and says the invitation is on its way', JSON.stringify( neu.last( 'crm-clients/account-resend' ).data ) === '{"id":"9","account":"eeeeeeeeeeee","notify":true}' && all( neu.main, '#unsent-eeeeeeeeeeee' ).length === 0 && neu.toast.textContent === texts['acc.resent-invite'] );

		// --- the Kundenkarte: what is owed in the head, the message as one line, mail and phone as links
		const ownDocs = kdocs.filter( function( doc ) { return doc.client_id === 1 } );
		const ownList = function( docs ) { return function() { return [ 200, { documents : docs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } };
		const own = await kopen( '#kunde-1', { 'crm-documents/list' : ownList( ownDocs ) } );
		const owed = all( own.main, '#client-owed' )[0];
		check( 'the head of the card says what the client owes - the open and sent invoices -, and how many of them are overdue where any is', owed !== undefined && textOf( owed ) === fill( 'card.owed', '1.690,00'+ NBSP+ '€' ) && owed.classList.contains( 'bad' ) === false );
		const late = await kopen( '#kunde-1', { 'crm-documents/list' : ownList( ownDocs.map( function( doc ) { return doc.id === 12 ? Object.assign( {}, doc, { overdue : true } ) : doc } ) ) } );
		check( '...with one overdue the line says so and is red', textOf( all( late.main, '#client-owed' )[0] ) === fill( 'card.owed-late', '1.690,00'+ NBSP+ '€', 1 ) && all( late.main, '#client-owed' )[0].classList.contains( 'bad' ) === true );
		const nothing = await kopen( '#kunde-1', { 'crm-documents/list' : ownList( ownDocs.filter( function( doc ) { return doc.state === 'paid' } ) ) } );
		check( '...and a client who owes nothing has no such line', all( nothing.main, '#client-owed' ).length === 0 );
		check( 'the address is a mailto: link and the phone a tel: link, with the number cut down to its digits - the text is as the client has it', all( kc.main, '#client-mail' )[0].getAttribute( 'href' ) === 'mailto:lena@nordlicht.example' && textOf( all( kc.main, '#client-mail' )[0] ) === 'lena@nordlicht.example' && all( kc.main, '#client-tel' )[0].getAttribute( 'href' ) === 'tel:+4940123456' && textOf( all( kc.main, '#client-tel' )[0] ) === '+49 40 123456' );
		const hostile = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { email : 'a@b.de?bcc=x@y.de', phone : 'javascript:alert(1)' } ), accounts : kaccounts } ] } } );
		check( '...an address or a number that is not of the plain shape is text and no link - nothing a person typed reaches an href', all( hostile.main, '#client-mail' ).length === 0 && all( hostile.main, '#client-tel' ).length === 0 && textOf( all( hostile.main, '#master' )[0] ).indexOf( 'a@b.de?bcc=x@y.de' ) !== -1 && findAll( hostile.main, function( node ) { return node.tagName === 'A' && /^(mailto|tel|javascript):/.test( node.getAttribute( 'href' ) ?? '' ) } ).length === 0 );
		const lineMessage = all( kc.main, '#client-message' )[0];
		const withText = await kopen( '#kunde-1', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { message : 'Bis Montag.', message_at : '2026-11-02T10:00:00Z' } ), accounts : kaccounts } ] } } );
		check( 'the block of the message is one line with the button as long as there is no text and the card with the heading and the buttons Bearbeiten and Entfernen once there is', lineMessage.classList.contains( 'msg-line' ) === true && all( lineMessage, 'button' ).length === 1 && all( all( withText.main, '#client-message' )[0], 'h3' ).length === 1 && all( withText.main, '#message-remove' ).length === 1 );

		// --- Braucht Dich: a line for each order and each file with comments
		const needing = await kopen( '#uebersicht', { 'crm-documents/overview' : function() { return [ 200, { summary : summary, recent : [], settings : settings, needs : [
			{ kind : 'overdue', id : 21, client_id : 1, client_title : 'Nordlicht', number : 'R-2026-0021', title : '', date : '2026-10-01', gross : 5000, sign_state : '', reminded_at : '2026-11-05T09:00:00Z' },
			{ kind : 'overdue', id : 22, client_id : 1, client_title : 'Nordlicht', number : 'R-2026-0022', title : '', date : '2026-10-02', gross : 5000, sign_state : '', reminded_at : '' },
			{ kind : 'orders', id : 0, client_id : 0, client_title : '', number : '', title : '', date : '', gross : 23800, sign_state : '', count : 2, items : [
				{ id : 31, number : 'A-2026-0031', title : '', client_title : 'Carla <b>Neu</b>', project : '00007', project_title : 'EP Neuklang', gross : 11900 }, { id : 32, number : '', title : 'Angebot ohne Nummer', client_title : 'Ben', project : '', project_title : '', gross : 11900 } ] } ],
			review : { count : 3, files : 2, list : [ { file : 'a00000000001', name : 'Intro.mp3', count : 2, project : '00007', project_title : 'EP Neuklang' }, { file : 'a00000000002', name : 'Outro.mp3', count : 1, project : '', project_title : '' } ] } } ] } } );
		const subs = all( needing.main, 'li.sub' );
		check( 'under the line that counts the orders there is a line for each, the number, the customer and the project and the way on - and the link leads to the offer, where the button is; the counting line stays as the heading', textOf( all( needing.main, '#needs-orders' )[0] ) === fill( 'needs.orders-many', 2 ) && subs.length === 4
			&& all( subs[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-31' && textOf( subs[0] ) === fill( 'needs.order-line-project', 'A-2026-0031', 'Carla <b>Neu</b>', 'EP Neuklang' )+ '119,00'+ NBSP+ '€' && all( subs[1], 'a' )[0].getAttribute( 'href' ) === '#beleg-32' && textOf( all( subs[1], 'a' )[0] ) === fill( 'needs.order-line', 'Angebot ohne Nummer', 'Ben' )
			&& findAll( needing.main, function( node ) { return node.tagName === 'B' } ).length === 0 );
		check( '...a file with open comments has a line too, with its project, the count, and a link to the tab Dateien of the project - or to the projects where it lies in no project', textOf( all( needing.main, '#needs-review' )[0] ) === fill( 'needs.review-many', 3, 2 ) && subs[2].dataset.file === 'a00000000001' && textOf( subs[2] ) === fill( 'needs.file-many', 'Intro.mp3 · EP Neuklang', 2 ) && all( subs[2], 'a' )[0].getAttribute( 'href' ) === '#projekt-00007-dateien'
			&& textOf( subs[3] ) === fill( 'needs.file-one', 'Outro.mp3', 1 ) && all( subs[3], 'a' )[0].getAttribute( 'href' ) === '#projekte' );
		const clocks = all( needing.main, '.clock' );
		check( 'an overdue invoice that was reminded has the small clock with the day as its title and its name; one that was not has none', clocks.length === 1 && clocks[0].title === fill( 'reminded.title', '05.11.2026' ) && clocks[0].getAttribute( 'aria-label' ) === fill( 'reminded.title', '05.11.2026' ) && clocks[0].getAttribute( 'role' ) === 'img' && all( all( needing.main, '.list-mini li' )[1], '.clock' ).length === 0 );

		// --- the search of the Übersicht
		const finder = await kopen( '#uebersicht', { 'crm-projekte/list' : function() { return [ 200, { missing : false, projects : [ { id : '00007', title : 'EP Neuklang', client : 'K-0003', client_id : 3, client_title : 'Ohne Konto AG' }, { id : '00008', title : 'Carla-Cover', client : 'K-0001', client_id : 1, client_title : 'Nordlicht' } ] } ] } } );
		const q = all( finder.main, '#q' )[0];
		check( 'the search has no list before anything is typed, and asks for no list', all( finder.main, '#search-results' )[0].hidden === true && finder.all( 'crm-clients/list' ).length === 0 && q.getAttribute( 'role' ) === 'combobox' && q.getAttribute( 'aria-expanded' ) === 'false' );
		type( q, 'o', false );
		await settle();
		const results = all( finder.main, '#search-results' )[0];
		check( 'a text gives a list of what the pages hold: the clients, the projects and the documents in groups of their own, each hit a link to its screen - the list is a listbox with an option for each hit, the first one marked', results.hidden === false && results.getAttribute( 'role' ) === 'listbox' && all( results, '[role="group"]' ).map( function( group ) { return group.dataset.kind } ).join() === 'client,project,document'
			&& all( results, '[role="option"]' ).length === all( results, 'a' ).length && all( results, '[role="option"]' )[0].getAttribute( 'aria-selected' ) === 'true' && q.getAttribute( 'aria-activedescendant' ) === 'hit-0' && q.getAttribute( 'aria-expanded' ) === 'true' );
		type( q, 'carla', false );
		await settle();
		const groupsNow = all( results, '[role="group"]' ).map( function( group ) { return group.dataset.kind+ ':'+ all( group, 'a' ).map( function( a ) { return a.getAttribute( 'href' ) } ).join( '+' ) } ).join( ' ' );
		check( '...Carla finds the client by her name and the project by its title: the card of the client, the card of the project - and nothing is asked of the server for a text, the lists are loaded once', groupsNow === 'client:#kunde-3 project:#projekt-00008' && finder.all( 'crm-clients/list' ).length === 1 && finder.all( 'crm-projekte/list' ).length === 1 && finder.all( 'crm-documents/list' ).length === 1 );
		fireOn( q, 'keydown', { key : 'Enter' } );
		check( '...Enter takes the first hit', finder.location.hash === '#kunde-3' );
		type( q, 'o', false );
		await settle();
		const total = all( results, 'a' ).length;
		fireOn( q, 'keydown', { key : 'ArrowDown' } );
		check( 'the arrows move the mark through all the hits and round again: down marks the second, up the first and then the last', all( results, '[role="option"]' )[1].getAttribute( 'aria-selected' ) === 'true' && all( results, '[role="option"]' )[0].getAttribute( 'aria-selected' ) === 'false' && q.getAttribute( 'aria-activedescendant' ) === 'hit-1'
			&& ( fireOn( q, 'keydown', { key : 'ArrowUp' } ), q.getAttribute( 'aria-activedescendant' ) === 'hit-0' ) && ( fireOn( q, 'keydown', { key : 'ArrowUp' } ), q.getAttribute( 'aria-activedescendant' ) === 'hit-'+ ( total - 1 ) ) );
		fireOn( q, 'keydown', { key : 'ArrowUp' } );
		fireOn( q, 'keydown', { key : 'Enter' } );
		check( '...and Enter takes the one that is marked', finder.location.hash === all( results, 'a' )[total - 2].getAttribute( 'href' ) );
		const quick = await kopen( '#uebersicht' );
		type( all( quick.main, '#q' )[0], 'o', false );
		type( all( quick.main, '#q' )[0], '', false );
		await settle();
		check( 'a text that was typed and taken away before the lists came does not bring the list back over the empty field', all( quick.main, '#search-results' )[0].hidden === true && all( all( quick.main, '#search-results' )[0], 'a' ).length === 0 && quick.all( 'crm-clients/list' ).length === 1 );
		const closing = await kopen( '#uebersicht' );
		type( all( closing.main, '#q' )[0], 'o', false );
		await settle();
		check( 'a list that is open is there to be closed', all( closing.main, '#search-results' )[0].hidden === false );
		fireOn( all( closing.main, '#q' )[0], 'keydown', { key : 'Escape' } );
		check( 'Escape closes the list', all( closing.main, '#search-results' )[0].hidden === true && all( closing.main, '#q' )[0].getAttribute( 'aria-expanded' ) === 'false' && all( all( closing.main, '#search-results' )[0], 'a' ).length === 0 );
		const nothing2 = await kopen( '#uebersicht' );
		type( all( nothing2.main, '#q' )[0], 'zzz-nothing', false );
		await settle();
		const noList = all( nothing2.main, '#search-results' )[0].hidden === true;
		fireOn( all( nothing2.main, '#q' )[0], 'keydown', { key : 'Enter' } );
		check( 'a text nothing holds has no list, and Enter shows the documents with it as their filter', noList === true && nothing2.location.hash === '#belege' && nothing2.app.state.filter.q === 'zzz-nothing' );
		const crowd = await kopen( '#uebersicht', { 'crm-clients/list' : function() { return [ 200, { clients : Array.from( { length : 9 }, function( unused, index ) { return Object.assign( {}, kclient, { id : 40 + index, number : 'K-01'+ index, company : index === 0 ? '<img src=x onerror=alert(1)> Test' : 'Test '+ index, name : '', email : '' } ) } ) } ] } } );
		type( all( crowd.main, '#q' )[0], 'test', false );
		await settle();
		check( 'at most five hits of a kind are listed, and a name with markup in it is text', all( all( crowd.main, '#search-results' )[0], 'a' ).length === 5 && findAll( crowd.main, function( node ) { return node.tagName === 'IMG' } ).length === 0 && textOf( all( crowd.main, '#search-results' )[0] ).indexOf( '<img src=x onerror=alert(1)> Test' ) !== -1 );
		click( crowd, all( crowd.main, '.tile' )[0] );
		check( 'a click elsewhere closes the list', all( crowd.main, '#search-results' )[0].hidden === true );

		// --- the release asks first, and may carry a sentence to the customer
		const releasing = await stepper( { state : 'open' } );
		click( releasing, all( releasing.main, '#primary' )[0] );
		const releaseBox = releasing.dialogs[0];
		const releaseNote = all( releaseBox, '#dlg-note' )[0];
		check( 'Versenden opens a dialog before anything is posted, always: the box of the mail ticked, and a field for a sentence to the customer - text, five hundred characters at most, empty at first', releasing.dialogs.length === 1 && releasing.steps.length === 0 && all( releaseBox, '#dlg-mail' )[0].checked === true && releaseNote.tagName === 'TEXTAREA' && releaseNote.getAttribute( 'maxlength' ) === '500' && releaseNote.value === '' && releaseNote.disabled === false );
		const releaseYes = function() { return all( releaseBox, 'button' ).pop(); };
		check( '...the button says Versenden while the mail is ticked and Freigeben when it is not, and the field of the sentence is off then - the sentence goes with the mail', textOf( releaseYes() ) === texts['primary.send'] && ( all( releaseBox, '#dlg-mail' )[0].checked = false, fireOn( all( releaseBox, '#dlg-mail' )[0], 'change' ), textOf( releaseYes() ) === texts['release.do'] && releaseNote.disabled === true )
			&& ( all( releaseBox, '#dlg-mail' )[0].checked = true, fireOn( all( releaseBox, '#dlg-mail' )[0], 'change' ), textOf( releaseYes() ) === texts['primary.send'] && releaseNote.disabled === false ) );
		releaseNote.value = '  Hallo Carla,\nwie besprochen: <b>hier</b> ist es.  ';
		click( releasing, releaseYes() );
		await settle();
		check( '...the yes posts the release with the mail and the sentence, trimmed - as the text it is, not as markup', JSON.stringify( releasing.steps[0][1] ) === JSON.stringify( { id : '11', mail : true, note : 'Hallo Carla,\nwie besprochen: <b>hier</b> ist es.' } ) );
		const silent = await stepper( { state : 'open' } );
		silent.answers['crm-documents/release'] = function( data ) { silent.steps.push( [ 'release', data ] ); return [ 200, { document : full( { state : 'open' } ), mailed : 0, mail : data.mail === true } ] };
		click( silent, all( silent.main, '#primary' )[0] );
		all( silent.dialogs[0], '#dlg-note' )[0].value = 'wird nicht gesendet';
		all( silent.dialogs[0], '#dlg-mail' )[0].checked = false;
		fireOn( all( silent.dialogs[0], '#dlg-mail' )[0], 'change' );
		click( silent, all( silent.dialogs[0], 'button' ).pop() );
		await settle();
		check( '...without the mail the release is posted without the sentence, whatever stands in the field, and the toast says it was released without a mail', JSON.stringify( silent.steps[0][1] ) === '{"id":"11","mail":false}' && silent.toast.textContent === texts['release.silent'] );
		const refusedNote = await stepper( { state : 'open' }, { 'crm-documents/release' : function() { return [ 400, { error : 'x', code : 'crm_documents_note', params : [], field : 'note' } ] } } );
		click( refusedNote, all( refusedNote.main, '#primary' )[0] );
		all( refusedNote.dialogs[0], '#dlg-note' )[0].value = 'Mit Satz';
		click( refusedNote, all( refusedNote.dialogs[0], 'button' ).pop() );
		await settle();
		check( '...a refusal of the server is said in the dialog, which stays open with the sentence that was typed - nothing is drawn again and no toast says it', refusedNote.dialogs[0].open === true && textOf( all( refusedNote.dialogs[0], '#dlg-problem' )[0] ) === texts['error.crm_documents_note'] && all( refusedNote.dialogs[0], '#dlg-problem' )[0].hidden === false && all( refusedNote.dialogs[0], '#dlg-note' )[0].value === 'Mit Satz' && refusedNote.all( 'crm-documents/get' ).length === 1 && refusedNote.toast.classList.contains( 'bad' ) === false );
		refusedNote.answers['crm-documents/release'] = function() { return [ 200, { document : full( { state : 'open', released : true } ), mailed : 2, mail : true } ]; };
		click( refusedNote, all( refusedNote.dialogs[0], 'button' ).pop() );
		await settle();
		check( '...and the yes that follows posts it again from the same dialog, which closes and says what was done', refusedNote.dialogs[0].open === false && refusedNote.last( 'crm-documents/release' ).data.note === 'Mit Satz' && refusedNote.toast.textContent === fill( 'send.done', 2 ) && refusedNote.all( 'crm-documents/get' ).length === 2 );
		const again = await stepper( { state : 'sent', released : true } );
		await tabClick( again, 'Portal & Versand' );
		click( again, all( body( again ), '#mail-again' )[0] );
		check( 'E-Mail erneut senden asks the same: the dialog, with the sentence', again.dialogs.length === 1 && all( again.dialogs[0], '#dlg-note' ).length === 1 && again.steps.length === 0 );
		const noted = await opened( { state : 'sent', released : true, release_note : 'Hallo <b>Carla</b>', release_note_at : '2026-11-06T10:15:00Z' } );
		await tabClick( noted, 'Verlauf' );
		const noteLine = all( body( noted ), 'li' ).find( function( li ) { return textOf( li ).indexOf( 'Hallo' ) !== -1 } );
		check( 'the history of the document keeps what the customer was told: the time and the sentence, as text', noteLine !== undefined && textOf( noteLine.children[1] ) === fill( 'history.note', 'Hallo <b>Carla</b>' ) && findAll( body( noted ), function( node ) { return node.tagName === 'B' } ).length === 0 );

		// --- the reminder in sight
		const reminded = await opened( { state : 'sent', released : true, reminded_at : '2026-11-06T09:30:00Z', reminded_mail : true } );
		const headLine = all( reminded.main, '#reminded-head' )[0];
		check( 'the head of an invoice that was reminded says it - the day and that it went by mail - with the clock', headLine !== undefined && textOf( headLine ) === ' '+ fill( 'reminded.head-mail', '06.11.2026' ) && all( headLine, '.clock' ).length === 1 );
		const quiet = await opened( { state : 'open', reminded_at : '2026-11-06T09:30:00Z', reminded_mail : false } );
		check( '...one noted without a mail says so, and an invoice that is paid, an offer or one that was never reminded has no such line', textOf( all( quiet.main, '#reminded-head' )[0] ) === ' '+ fill( 'reminded.head-quiet', '06.11.2026' ) && all( ( await opened( { state : 'paid', reminded_at : '2026-11-06T09:30:00Z' } ) ).main, '#reminded-head' ).length === 0
			&& all( ( await opened( { state : 'sent', reminded_at : '' } ) ).main, '#reminded-head' ).length === 0 && all( ( await opened( { id : 13, category : 'offer', state : 'sent', reminded_at : '2026-11-06T09:30:00Z' } ) ).main, '#reminded-head' ).length === 0 );
		const listed = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] },
			'crm-documents/list' : function() { return [ 200, { documents : [ row( { id : 1, state : 'sent', reminded_at : '2026-11-06T09:30:00Z' } ), row( { id : 2, number : 'R-2026-0002', state : 'sent' } ), row( { id : 3, number : 'R-2026-0003', state : 'paid', reminded_at : '2026-11-01T09:30:00Z' } ) ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
		listed.boot();
		await settle();
		const listClocks = all( listed.main, 'tbody .clock' );
		check( 'the list of the documents has the clock with the day beside the state of an invoice that was reminded and is still asked for - and none beside the others', listClocks.length === 1 && listClocks[0].title === fill( 'reminded.title', '06.11.2026' ) && listClocks[0].parentNode.parentNode.dataset.id === '1' );
	}


	// === Kundenaktionen: a declined offer, a reported payment, the upload folder

	const kundenaktionen = async function() {

		const record = function( over ) {
			return x18_record( Object.assign( { id : 9, kind : 'decline', document_id : 31, document_number : 'A-2026-0031', project : '00001', sha256 : 'd'.repeat( 64 ), shown : '{\n    "number": "A-2026-0031",\n    "reason": "Zu teuer <b>x</b>"\n}', reason : 'Zu teuer <b>x</b>\nZweite Zeile' }, over ) );
		};
		const declined = record( {} );
		const noReason = record( { id : 10, shown : '{\n    "reason": ""\n}', reason : '' } );
		const payment = record( { id : 11, kind : 'payment', document_id : 11, document_number : 'R-2026-0007', project : '', shown : '{\n    "number": "R-2026-0007",\n    "gross": 119000\n}', reason : undefined } );
		const detail = async function( hash, over, extra ) {
			const w = world( { hash : hash, answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/get' : function() { return [ 200, view( over, extra ) ] } } } );
			w.boot();
			await settle();
			return w;
		};
		const tabOf = function( w, name ) { click( w, all( w.main, '.tabs button' ).find( function( b ) { return b.dataset.tab === name } ) ) };

		// the head, the card and the history of the offer
		const offer = await detail( '#beleg-31', { id : 31, category : 'offer', number : 'A-2026-0031', state : 'declined', due_date : null, valid_until : '2026-12-01', released : true, released_at : '2026-11-01T09:00:00Z' }, { decline : declined } );
		const headLine = all( offer.main, '#decline-head' )[0];
		check( 'the head of an offer the customer declined says so under the head: when, by whom and the reason, as text', headLine !== undefined && textOf( headLine ).indexOf( texts['decline.head-reason'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) ) !== -1 && textOf( headLine ).indexOf( 'Zu teuer <b>x</b>' ) !== -1
			&& findAll( headLine, function( node ) { return node.tagName === 'B' } ).length === 0 && all( headLine, '.pre' ).length === 1 && all( offer.main, '#payment-head' ).length === 0 && all( offer.main, '#order-head' ).length === 0 );
		const quiet = await detail( '#beleg-31', { id : 31, category : 'offer', number : 'A-2026-0031', state : 'declined', valid_until : '2026-12-01', released : true }, { decline : noReason } );
		check( '...a decline with no reason says when and by whom and nothing else: no colon, no reason', textOf( all( quiet.main, '#decline-head' )[0] ).indexOf( texts['decline.head'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) ) !== -1 && all( all( quiet.main, '#decline-head' )[0], '.pre' ).length === 0 );
		tabOf( offer, 'portal' );
		const card = all( offer.main, '#decline-card' )[0];
		check( 'the tab Portal has the card of the decline: the time, the account with its address, the address, the browser, the reason with its line breaks kept, the hash - the card has its space like the order\'s', card !== undefined && textOf( card ).indexOf( texts['decline.title'] ) === 0 && textOf( card ).indexOf( 'Lena Brandt <lena@nordlicht.example>' ) !== -1 && textOf( card ).indexOf( '203.0.113.7' ) !== -1 && textOf( card ).indexOf( 'Zu teuer <b>x</b>' ) !== -1
			&& textOf( card ).indexOf( 'd'.repeat( 64 ) ) !== -1 && all( card, '.pre' ).length === 1 && card.classList.contains( 'gap' ) === true && all( offer.main, '#order-card' ).length === 0 && all( offer.main, '#payment-card' ).length === 0 );
		click( offer, all( offer.main, '#decline-show' )[0] );
		check( '...the button opens the JSON that was shown as text, and the reason among the facts', offer.dialogs.length === 1 && textOf( all( offer.dialogs[0], 'pre' )[0] ) === declined.shown && textOf( offer.dialogs[0] ).indexOf( texts['decline.reason'] ) !== -1 && textOf( offer.dialogs[0] ).indexOf( 'Zu teuer <b>x</b>' ) !== -1 && findAll( offer.dialogs[0], function( node ) { return node.tagName === 'B' } ).length === 0 );
		tabOf( offer, 'history' );
		check( '...the history has the line of the decline', textOf( all( offer.main, '.log' )[0] ).indexOf( texts['history.declined'].replace( '%s', 'Lena Brandt' ) ) !== -1 );
		tabOf( quiet, 'portal' );
		check( '...a decline with no reason says so in the card', textOf( all( quiet.main, '#decline-card' )[0] ).indexOf( texts['decline.reason-none'] ) !== -1 );
		const invoice = await detail( '#beleg-11', { id : 11, state : 'sent', released : true }, { payment : payment } );
		check( 'the head of an invoice the customer reported as paid says so under the head: "Kunde meldet Zahlung am … durch …" - the state of the invoice is the one it has', textOf( all( invoice.main, '#payment-head' )[0] ).indexOf( texts['payment.head'].replace( '%s', '03.11.2026' ).replace( '%s', 'Lena Brandt' ) ) !== -1 && all( invoice.main, '#decline-head' ).length === 0 && all( invoice.main, '#order-head' ).length === 0 );
		tabOf( invoice, 'portal' );
		check( '...and the tab Portal has the card of the report and the history its line', textOf( all( invoice.main, '#payment-card' )[0] ).indexOf( texts['payment.title'] ) === 0 && all( invoice.main, '#decline-card' ).length === 0 );
		tabOf( invoice, 'history' );
		check( '...the history has the line of the report', textOf( all( invoice.main, '.log' )[0] ).indexOf( texts['history.payment'].replace( '%s', 'Lena Brandt' ) ) !== -1 );
		const plain = await detail( '#beleg-11', { id : 11 }, {} );
		check( 'a document with neither has no line under the head and no card', all( plain.main, '#decline-head' ).length === 0 && all( plain.main, '#payment-head' ).length === 0 );

		// the list: the mark of a report and the chips that are there while they have a document
		const docs = [
			row( { id : 21, number : 'R-2026-0021', state : 'sent', paid_notice : '2026-11-03', gross : 10000 } ),
			row( { id : 22, number : 'R-2026-0022', state : 'sent', paid_notice : '', gross : 20000 } ),
			row( { id : 23, number : 'R-2026-0023', state : 'paid', paid_notice : '2026-11-02', gross : 30000 } ),
			row( { id : 24, category : 'offer', number : 'A-2026-0024', state : 'declined', paid_notice : '', gross : 40000 } ),
		];
		const listing = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : docs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
		listing.boot();
		await settle();
		const rowOf = function( w, id ) { return all( w.main, 'tbody tr' ).find( function( tr ) { return tr.dataset.id === String( id ) } ) };
		const mark = all( rowOf( listing, 21 ), '.pay-icon' );
		check( 'an invoice the customer reported as paid has a small icon in the state cell with a title that names the day - an image for a reader, the title as text - and no other row has one: not an invoice with no report, not a paid one, not an offer', mark.length === 1 && mark[0].getAttribute( 'role' ) === 'img' && mark[0].title === texts['payment.mark-title'].replace( '%s', '03.11.2026' ) && mark[0].getAttribute( 'aria-label' ) === mark[0].title
			&& all( listing.main, '.pay-icon' ).length === 1 );
		const chipOf = function( w, key ) { return all( w.main, '.tabs button' ).find( function( b ) { return b.dataset.chip === key } ) };
		check( '...the chips Abgelehnt and Zahlung gemeldet are there while a row fits them - with their counts - after the others', textOf( chipOf( listing, 'declined' ) ) === texts['chip.declined']+ '1' && textOf( chipOf( listing, 'notified' ) ) === texts['chip.notified']+ '1' && all( listing.main, '.tabs button' ).length === 11 );
		click( listing, chipOf( listing, 'notified' ) );
		check( '...the chip Zahlung gemeldet shows the invoice that was reported and is not paid: the paid one with a report is not among them', all( listing.main, 'tbody tr' ).length === 1 && all( listing.main, 'tbody tr' )[0].dataset.id === '21' );
		click( listing, chipOf( listing, 'declined' ) );
		check( '...and Abgelehnt the declined offer', all( listing.main, 'tbody tr' ).length === 1 && all( listing.main, 'tbody tr' )[0].dataset.id === '24' );
		const without = world( { hash : '#belege', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : [ docs[1], docs[2] ], years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
		without.boot();
		await settle();
		check( 'with no such row the two chips are not there, and the row of a paid invoice has no mark', chipOf( without, 'declined' ) === undefined && chipOf( without, 'notified' ) === undefined && all( without.main, '.tabs button' ).length === 9 && all( without.main, '.pay-icon' ).length === 0 );

		check( 'a reason without a blank wraps inside the head of the document and in the card: the class of the text keeps its line breaks and may break anywhere - a line of 480 letters does not make the page scroll sideways', /\.pre \{ white-space: pre-wrap; overflow-wrap: anywhere \}/.test( source( OWN+ 'assets/crm.css' ) ) === true && all( offer.main, '#decline-head .pre' ).length === 1 );

		// Braucht Dich
		const need = function( over ) { return Object.assign( { kind : 'payments', id : 0, client_id : 0, client_title : '', number : '', title : '', date : '', gross : 238000, sign_state : '', count : 2 }, over ) };
		const overview = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ need( {} ), need( { kind : 'declined', gross : 0, count : 3, days : 30 } ) ], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : docs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
		overview.boot();
		await settle();
		const payLine = all( overview.main, '#needs-payments' )[0];
		const goneLine = all( overview.main, '#needs-declined' )[0];
		check( '"Braucht Dich" has a line for the payments that were reported and are not marked as paid: how many, the sum, a link to the Belege - and a line for the offers declined in the last days: how many and the days', payLine !== undefined && textOf( payLine ) === texts['needs.payments-many'].replace( '%s', '2' ) && payLine.getAttribute( 'href' ) === '#belege' && textOf( payLine.parentNode.parentNode ).indexOf( '2.380,00'+ NBSP+ '€' ) !== -1
			&& goneLine !== undefined && textOf( goneLine ) === texts['needs.declined-many'].replace( '%s', '3' ).replace( '%s', '30' ) && goneLine.getAttribute( 'href' ) === '#belege' );
		click( overview, payLine );
		overview.location.hash = '#belege';
		await settle();
		const nearest = overview.scrolled.filter( function( one ) { return one[1] !== undefined && one[1].inline === 'nearest' && one[1].block === 'nearest' } );
		const chosenTab = nearest.length === 1 ? nearest[0][2] : null;
		check( 'the tab that a line of Braucht Dich chose is brought into view in the row of the tabs, as near as it is: the row is wider than the window with all the tabs there - it is the tab that is on, the one of the payments', nearest.length === 1 && chosenTab.dataset.chip === 'notified' && chosenTab.className.split( ' ' ).includes( 'on' ) === true && chosenTab.getAttribute( 'aria-selected' ) === 'true' );
		check( '...the click on the payments shows the Belege filtered by the report: the chip is on and only the invoice that was reported is in the table', chipOf( overview, 'notified' ).classList.contains( 'on' ) === true && all( overview.main, 'tbody tr' ).length === 1 );
		const overview2 = world( { hash : '#uebersicht', answers : { 'crm-documents/overview' : function() { return [ 200, { summary : summary, needs : [ need( { count : 1 } ), need( { kind : 'declined', gross : 0, count : 1, days : 30 } ) ], recent : [], settings : settings } ] }, 'crm-documents/list' : function() { return [ 200, { documents : docs, years : [ 2026 ], clients : clients, settings : settings, items : items, offers : offers } ] } } } );
		overview2.boot();
		await settle();
		check( '...one of each is said in the singular', textOf( all( overview2.main, '#needs-payments' )[0] ) === texts['needs.payments-one'].replace( '%s', '1' ) && textOf( all( overview2.main, '#needs-declined' )[0] ) === texts['needs.declined-one'].replace( '%s', '1' ).replace( '%s', '30' ) );
		click( overview2, all( overview2.main, '#needs-declined' )[0] );
		overview2.location.hash = '#belege';
		await settle();
		check( '...and the click on the declines shows the declined offers', chipOf( overview2, 'declined' ).classList.contains( 'on' ) === true && all( overview2.main, 'tbody tr' ).length === 1 && all( overview2.main, 'tbody tr' )[0].dataset.id === '24' );

		// the tab Portal of the client: the records, and the button of the upload folder
		const records = await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { records : [ payment, declined, noReason ] } ) ] } } );
		const rows = all( all( records.main, '#client-records' )[0], 'li' );
		check( 'the tab Portal lists the decline and the payment among the records: the kind in words, the time, the account and the link to the document', rows.length === 3 && rows[0].dataset.kind === 'payment' && rows[1].dataset.kind === 'decline' && textOf( rows[0] ).indexOf( texts['record.kind-payment']+ ' · 03.11.2026 ' ) === 0 && textOf( rows[1] ).indexOf( texts['record.kind-decline']+ ' · 03.11.2026 ' ) === 0
			&& all( rows[1], 'a' )[0].getAttribute( 'href' ) === '#beleg-31' && textOf( all( rows[1], 'a' )[0] ) === 'A-2026-0031' && all( rows[0], 'a' )[0].getAttribute( 'href' ) === '#beleg-11' );
		click( records, all( rows[1], 'button' )[0] );
		check( '...Anzeigen on a decline opens the dialog with the title of a decline, the reason among the facts and the JSON as text - markup in it is not markup', records.dialogs[0].getAttribute( 'aria-label' ) === texts['record.title-decline'] && textOf( records.dialogs[0] ).indexOf( 'Zu teuer <b>x</b>' ) !== -1 && textOf( all( records.dialogs[0], 'pre' )[0] ) === declined.shown && findAll( records.dialogs[0], function( node ) { return node.tagName === 'B' } ).length === 0 );
		click( records, all( rows[0], 'button' )[0] );
		check( '...and on a payment the title of a payment', records.dialogs[1].getAttribute( 'aria-label' ) === texts['record.title-payment'] && textOf( records.dialogs[1] ).indexOf( texts['decline.reason'] ) === -1 );
		check( 'a client whose group has an upload folder has no button; nor has one while Files is off or the client is off', all( ( await kopen( '#kunde-1-portal' ) ).main, '#client-upload-folder' ).length === 0
			&& all( ( await kopen( '#kunde-1-portal', { 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { features : { files : false, review : false }, folders : [] } ) ] } } ) ).main, '#client-upload-folder' ).length === 0
			&& all( ( await kopen( '#kunde-1-portal', { 'crm-clients/get' : function() { return [ 200, { client : Object.assign( {}, kclient, { active : false } ), accounts : kaccounts } ] }, 'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { folders : [] } ) ] } } ) ).main, '#client-upload-folder' ).length === 0 );
		const folders = [ { id : 'f9', name : 'Eigener', upload : false, files : 2 } ];
		const posted = [];
		let made = false;
		const folder = await kopen( '#kunde-1-portal', {
			'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { folders : made ? [ { id : 'f10', name : 'K-0001 Nordlicht Records GmbH', upload : true, files : 0 } ].concat( folders ) : folders } ) ] },
			'crm-clients/upload-folder' : function( data ) { posted.push( data ); made = true; return [ 200, { folder : { id : 'f10', name : 'K-0001 Nordlicht Records GmbH', upload : true, files : 0 }, created : true } ]; },
		} );
		const button = all( folder.main, '#client-upload-folder' )[0];
		check( 'a client with folders and none to upload into has the button Upload-Ordner anlegen with a hint, in the tab Portal, under the list of the folders', button !== undefined && textOf( button ) === texts['cportal.upload-folder'] && textOf( all( folder.main, '#client-upload-folder-box' )[0] ).indexOf( texts['cportal.upload-folder-hint'] ) !== -1 && button.disabled === false );
		click( folder, button );
		await settle();
		check( '...a click posts crm-clients/upload-folder { id }, says that the folder is made and reads the portal of the client again: the folder is in the list as the folder to upload into and the button is gone', JSON.stringify( posted ) === '[{"id":"1"}]' && folder.toast.textContent === texts['cportal.upload-folder-done'] && folder.all( 'crm-clients/portal' ).length === 2
			&& textOf( all( folder.main, '#client-portal' )[0] ).indexOf( 'K-0001 Nordlicht Records GmbH' ) !== -1 && all( folder.main, '#client-upload-folder' ).length === 0 );
		const refused = await kopen( '#kunde-1-portal', {
			'crm-clients/portal' : function() { return [ 200, Object.assign( {}, kportal, { folders : folders } ) ] },
			'crm-clients/upload-folder' : function() { return [ 500, { error : 'x', code : 'crm_clients_folder', params : [], field : '' } ]; },
		} );
		click( refused, all( refused.main, '#client-upload-folder' )[0] );
		await settle();
		check( '...a refusal is said in the sentence of its code, the button is on again and the portal is not read twice', refused.toast.textContent === texts['error.crm_clients_folder'] && all( refused.main, '#client-upload-folder' )[0].disabled === false && refused.all( 'crm-clients/portal' ).length === 1 );
	};

	await kundenaktionen();


	done();
	process.exit( process.exitCode ?? 0 );

} )();

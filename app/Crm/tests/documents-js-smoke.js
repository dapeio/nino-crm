/**
 *	Crm
 *	documents-js-smoke.js		What the scripts of the panel Belege do (Documents/Admin/assets/admin.js and the arithmetic of
 *													Documents/Money/assets/money.js) over the kernel's own Nino.admin.js: the money functions give
 *													the answers of money-cases.json, case for case, which the PHP suite checks against Money.php;
 *													the panel registers Nino.admin['crm-documents'] under the uri of the panel and posts
 *													crm-documents/<action>; the list is asked for with the filters and the status of the module;
 *													the rows, the filters, the editor of a draft (client, dates, template and its inputs, the
 *													lines with their live amounts and totals, the reverse charge, the items of the catalogue, notes),
 *													what it posts, what it says to a refusal and where it marks the field, the questions before
 *													finalising and deleting, the page of a finalised document (facts, lines, totals, the PDF, the
 *													steps of each state, the documents it comes from), its notes and the screen of the settings.
 *													Only Nino itself is a stand-in: the requests are kept for the test to answer, and the words
 *													are the ones of the text files, in both interface languages.
 *
 *													A pin names the words it expects by their keys, not by what they say. The helpers are those of
 *													tests/harness.js of the checkout, three levels up or the one NINO_ROOT names. documents-smoke.php
 *													runs this where node is on the path.
 *
 *	Usage: node app/Crm/tests/documents-js-smoke.js
 */

'use strict';

/* global require, __dirname, process */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

const OWN = 'app/Crm/Documents/Admin/';
const K = '/_admin/crm-documents/';
const NBSP = '\u00a0';

/**
 *	The panel's scripts in a page of their own, in one interface language: the kernel's Nino.admin.js, the money functions
 *	and the script of the panel, over a Nino that keeps the requests and the words of the text files
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
	const answers = { confirm : true };

	const address = { panel : '', parts : [] };
	const mount = element('div');
	mount.id = 'crm-documents-app';

	const page = documentOf( mount );
	const make = page.createElement;

	const dialogs = [];
	let context = null;

	page.createElement = function( tag ) {
		const el = make( tag );

		// The table takes a node a column renders into its cell as it is, if it is an Object of its own context: a stand-in made outside is not
		if( context !== null ) {
			Object.setPrototypeOf( el, vm.runInContext( 'Object.prototype', context ) );
			el.nodeType = 1;
		}

		Object.defineProperty( el, 'name', { get : function() { return el.attributes.name ?? '' }, set : function( value ) { el.setAttribute( 'name', value ) } } );

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
			router : { href : function( panel ) { return '#'+ panel }, current : function() { return address } },
		},
	};

	const window = { Nino : Nino, confirm : function( message ) { asked.push( message ); return answers.confirm } };
	context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	load( 'app/Crm/Documents/Money/assets/money.js', context, 'money.js' );
	load( OWN+ 'assets/admin.js', context, 'admin.js' );

	const find = function( from, predicate ) { return findAll( from ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin['crm-documents'],
		money : Nino.crm.money,
		mount : mount,
		requests : requests,
		registered : registered,
		guarded : guarded,
		asked : asked,
		dialogs : dialogs,
		address : address,
		answers : answers,
		missing : missing,
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		button : function( from, text ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		input : function( from, name ) { return find( from, function( el ) { return ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' ) && el.name === name } ) },
		select : function( from, name ) { return find( from, function( el ) { return el.tagName === 'SELECT' && el.name === name } ) },
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

// --- what the server answers ------------------------------------------------------------------------------------------

const cases = JSON.parse( fs.readFileSync( path.join( __dirname, 'money-cases.json' ), 'utf8' ) );

const SMALL = { vat_mode : 'small_business', vat_rates : '1900,700,0', payment_days : '14', offer_days : '30', currency : '€', number_offer : 'A-{YYYY}-{NNNN}', number_invoice : 'R-{YYYY}-{NNNN}', number_credit : 'G-{YYYY}-{NNNN}', rates : [ 1900, 700, 0 ] };
const REGULAR = Object.assign( {}, SMALL, { vat_mode : 'regular' } );
const CLIENTS = [ { id : 1, number : 'K-0001', title : 'Studio Nord', active : true }, { id : 2, number : 'K-0002', title : 'Ben Süd', active : true }, { id : 3, number : 'K-0003', title : 'Dritte AG', active : false } ];
const ITEMS = [
	{ id : 1, title : 'Webdesign', description : 'Pro Stunde', unit : 'Std.', price_net : 9000, vat_rate : 1900, active : true, sort : 1 },
	{ id : 2, title : 'Hosting', description : '', unit : 'Monat', price_net : 1200, vat_rate : 0, active : true, sort : 2 },
];
const TEMPLATES = [ { key : 'rechnung', name : 'Rechnung', inputs : [ { name : 'intro', label : 'Intro', multiline : true, required : true }, { name : 'footnote', label : 'Fußnote', multiline : false, required : false } ] } ];
const OFFERS = [
	{ id : 9, number : 'A-2026-0003', date : '2026-10-20', client_id : 2, client_title : 'Ben Süd' },
	{ id : 7, number : 'A-2020-0001', date : '2020-01-01', client_id : 2, client_title : 'Ben Süd' },
];
const CLIENT = { id : 1, number : 'K-0001', company : 'Studio Nord', name : 'Anna Nord', street : 'Hafenstr. 1', zip : '20095', city : 'Hamburg', country : 'DE', email : 'anna@example.org', vat_id : '' };

const LISTED = [
	{ id : 5, category : 'invoice', number : '', state : 'draft', expired : false, title : '', released : false, sign_state : '', date : '2026-11-03', gross : 23700, client_id : 1, client_title : 'Studio Nord', parent_id : null },
	{ id : 6, category : 'invoice', number : 'R-2026-0001', state : 'sent', expired : false, title : '', released : false, sign_state : '', date : '2026-10-01', gross : 11900, client_id : 1, client_title : '<img src=x onerror=alert(1)>', parent_id : null },
	{ id : 7, category : 'offer', number : 'A-2020-0001', state : 'open', expired : true, title : '', released : false, sign_state : '', date : '2020-01-01', gross : 500, client_id : 2, client_title : 'Ben Süd', parent_id : null },
	{ id : 8, category : 'credit', number : 'G-2026-0001', state : 'paid', expired : false, title : '', released : false, sign_state : '', date : '2026-10-05', gross : -1200, client_id : 1, client_title : 'Studio Nord', parent_id : 6 },
];
const ALL = [ 'module', 'data', 'pdf', 'templates-offer', 'templates-invoice', 'pdf-dir' ];
const OK = ALL.map( function( key ) { return { key : key, state : 'ok' } } );

/** A document as the server's get() has it */
function doc( over ) {
	return Object.assign( {
		id : 5, category : 'invoice', client_id : 1, parent_id : null, number : '', state : 'draft', date : '2026-11-03', due_date : '2026-11-17', valid_until : null, period_from : null, period_to : null,
		vat_mode : 'small_business', reverse_charge : false, net : 23700, vat : 0, gross : 23700, template_key : 'rechnung', inputs : { intro : 'Hallo' }, notes : 'intern', pdf_path : '', pdf_sha256 : '', pdf_url : '',
		title : '', released : false, released_at : null, sign_id : '', sign_state : '', signed_at : null, sign_shown_url : '', sign_signed_url : '',
		finalised : null, sent_at : null, paid_at : null, created : '2026-11-03T10:00:00Z', updated : '2026-11-03T10:00:00Z', expired : false,
		lines : [
			{ id : 1, document_id : 5, sort : 1, title : 'Webdesign', description : 'Pro Stunde', qty : 2500, unit : 'Std.', price_net : 9000, vat_rate : 0, amount_net : 22500 },
			{ id : 2, document_id : 5, sort : 2, title : 'Hosting', description : '', qty : 1000, unit : 'Monat', price_net : 1200, vat_rate : 0, amount_net : 1200 },
		],
		client : CLIENT, parent : null, parent_number : '', children : [],
	}, over );
}

/** The answer of get() */
function view( document, settings ) {
	return { document : document, templates : TEMPLATES, clients : CLIENTS, settings : settings ?? SMALL, items : ITEMS, offers : OFFERS, accounts : [], sign_days : 14 };
}

/** A finalised invoice of the given state */
function sealed( state, over ) {
	return doc( Object.assign( { id : 6, number : 'R-2026-0001', state : state, finalised : '2026-10-01T09:00:00Z', pdf_path : '/data/crm/pdf/2026/R-2026-0001.pdf', pdf_sha256 : 'x'.repeat( 64 ), pdf_url : '/.crm/pdf/6', sent_at : state === 'draft' || state === 'open' ? null : '2026-10-02T00:00:00Z' }, over ?? {} ) );
}

/** The panel shown, its list answered and its status answered */
function drawn( locale, documents, settings ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, { documents : documents ?? LISTED, years : [ 2026, 2020 ], clients : CLIENTS, settings : settings ?? SMALL, items : ITEMS, offers : OFFERS } );
	w.answer( 200, { items : OK } );
	return w;
}

/** A document open: its row pressed and get() answered */
function opened( locale, document, settings ) {
	const w = drawn( locale, undefined, settings );
	w.find( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === String( document.id ) } ).click();
	w.answer( 200, view( document, settings ) );
	return w;
}

/** A new draft of a category open */
function created( locale, category, settings, templates ) {
	const w = drawn( locale, undefined, settings );
	w.button( w.mount, w.word( K+ 'label/new-'+ category ) ).click();
	w.answer( 200, { templates : templates ?? TEMPLATES, offers : OFFERS } );
	return w;
}

function today() {
	const now = new Date();
	return [ now.getFullYear(), String( now.getMonth() + 1 ).padStart( 2, '0' ), String( now.getDate() ).padStart( 2, '0' ) ].join('-');
}

function later( ymd, days ) {
	return new Date( Date.UTC( Number( ymd.slice( 0, 4 ) ), Number( ymd.slice( 5, 7 ) ) - 1, Number( ymd.slice( 8, 10 ) ) + days ) ).toISOString().slice( 0, 10 );
}

console.log( 'The money functions - the cases of money-cases.json\n' );

{
	const w = world('en_US');
	const m = w.money;
	const wrong = [];

	cases.lineAmount.forEach( function( [ qty, price, expected ] ) { if( m.lineAmount( qty, price ) !== expected ) wrong.push( 'lineAmount '+ qty+ ' '+ price ) } );
	check( 'the amount of a line is qty x price / 1000 rounded half away from zero: '+ cases.lineAmount.length+ ' cases, the largest past 2^53', wrong.length === 0 && cases.lineAmount.length >= 18 );

	cases.vat.forEach( function( [ net, rate, expected ] ) { if( m.vat( net, rate ) !== expected ) wrong.push( 'vat '+ net+ ' '+ rate ) } );
	check( 'the VAT is net x rate / 10000 rounded half away from zero: '+ cases.vat.length+ ' cases', wrong.length === 0 && cases.vat.length >= 15 );

	cases.parse.forEach( function( [ text, expected ] ) { if( m.parse( text ) !== expected ) wrong.push( 'parse '+ JSON.stringify( text ) ) } );
	check( 'a price is read from 1.234,56 and 1234,56 and 1234.56 and -12,5; anything else is none: '+ cases.parse.length+ ' cases', wrong.length === 0 && cases.parse.length >= 25 );

	cases.parseQty.forEach( function( [ text, expected ] ) { if( m.parseQty( text ) !== expected ) wrong.push( 'parseQty '+ JSON.stringify( text ) ) } );
	check( 'a quantity is read the same way with three decimals: '+ cases.parseQty.length+ ' cases', wrong.length === 0 && cases.parseQty.length >= 12 );

	cases.format.forEach( function( [ cents, currency, expected ] ) { if( m.format( cents, currency ) !== expected ) wrong.push( 'format '+ cents ) } );
	check( 'cents are written 1.234,56 with the currency behind a non-breaking space', wrong.length === 0 && NBSP === '\u00a0' );

	cases.formatQty.forEach( function( [ qty, expected ] ) { if( m.formatQty( qty ) !== expected ) wrong.push( 'formatQty '+ qty ) } );
	check( 'thousandths are written 1,5 and 2: the trailing zeros cut, three decimals at most', wrong.length === 0 );

	cases.formatRate.forEach( function( [ rate, expected ] ) { if( m.formatRate( rate ) !== expected ) wrong.push( 'formatRate '+ rate ) } );
	check( 'a rate is written 19 %, 7 % and 5,5 %', wrong.length === 0 );

	cases.totals.forEach( function( entry ) {

		const totals = m.totals( entry.lines, entry.mode, entry.reverse );
		const rates = totals.rates.map( function( sums ) { return [ sums.rate, sums.net, sums.vat ] } );
		const expected = entry.expect;

		check( 'totals: '+ entry.name, JSON.stringify( [ totals.net, totals.vat, totals.gross, rates, totals.amounts ] ) === JSON.stringify( [ expected.net, expected.vat, expected.gross, expected.rates, expected.amounts ] ) );
	} );

	check( 'the limits are those of the server', m.MAX_PRICE === 1000000000 && m.MAX_QTY === 10000000 && m.MAX_LINE === 100000000000 && m.MAX_TOTAL === 1000000000000 );
}

console.log( '\nNino.admin[\'crm-documents\'] - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown', w.requests.length === 0 && w.registered.length === 0 );
	check( 'the namespace is the nav uri of the panel, with its mount', w.panel.uri === 'crm-documents' && w.panel.mount() === w.mount && typeof w.panel.showCurrent === 'function' );

	w.panel.call( 'probe', { x : 1 }, function() {} );
	check( 'the panel posts crm-documents/<action>: its call() is the one of the shell, with no override', w.last().action === 'crm-documents/probe' );

	w.requests.length = 0;
	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for crm-documents/list, once while the answer is out, with the filters empty and no search', w.requests.length === 1 && w.requests[0].action === 'crm-documents/list' && JSON.stringify( w.requests[0].payload ) === '{"category":"","state":"","year":"","client":""}' );
	check( '...and registers its entry under its uri, which holds nothing unsaved', w.registered.length === 1 && w.registered[0].name === 'crm-documents' && w.entry().isDirty() === false );

	w.answer( 200, { documents : LISTED, years : [ 2026, 2020 ], clients : CLIENTS, settings : SMALL, items : ITEMS } );
	check( 'the status is asked for once the list is drawn', w.last().action === 'crm-documents/status' && JSON.stringify( w.last().payload ) === '{}' );
}

console.log( '\nThe rows - the shell\'s table\n' );

{
	const w = drawn('en_US');
	const table = w.find( w.mount, function( el ) { return el.id === 'crm-documents-table' } );
	const head = w.all( table, function( el ) { return el.tagName === 'TH' } );
	const rows = function() { return w.all( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } ) };
	const cells = function( row ) { return w.all( row, function( el ) { return el.tagName === 'TD' } ).map( function( el ) { return el.textContent } ) };
	const badge = function( row ) { return w.all( row, function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } ).map( function( el ) { return el.textContent } ) };
	const kinds = function( row ) { return w.all( row, function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } ).map( function( el ) { return el.dataset.state } ).join() };
	const column = function( key ) { return rows().map( function( row ) { return row.dataset.row } ).join() };

	check( 'the list is the shell\'s table with the columns number, kind, client, date, gross and state, their words, and the id of the document as the key of a row', table !== null
		&& head.map( function( th ) { return th.dataset.key } ).join() === 'name,category_word,client_title,date,gross_value,state_word'
		&& [ 'column/number', 'column/category', 'column/client', 'column/date', 'column/gross', 'column/state' ].every( function( key ) { return w.has( K+ key ) && w.texts( table ).indexOf( w.word( K+ key ) ) !== -1 } )
		&& column() === '5,6,7,8' );
	check( 'a row says the number - the word for a draft where there is none -, the kind, the client, the day as d.m.Y and the gross with the currency', cells( rows()[0] ).slice( 0, 5 ).join('|') === [ w.word( K+ 'label/draft' ), w.word( K+ 'category/invoice' ), 'Studio Nord', '03.11.2026', '237,00'+ NBSP+ '€' ].join('|')
		&& cells( rows()[3] ).slice( 0, 5 ).join('|') === [ 'G-2026-0001', w.word( K+ 'category/credit' ), 'Studio Nord', '05.10.2026', '-12,00'+ NBSP+ '€' ].join('|') );
	check( '...and wears the badge of its state; an offer past its validity wears the badge of that, whatever its state', badge( rows()[0] ).join() === w.word( K+ 'state/draft' ) && badge( rows()[1] ).join() === w.word( K+ 'state/sent' ) && badge( rows()[2] ).join() === w.word( K+ 'state/expired' ) && badge( rows()[3] ).join() === w.word( K+ 'state/paid' ) );
	check( '...the badge says it in its colour as well: paid is ok, a draft is muted, an offer past its validity warns, a state under way has the badge as it is', kinds( rows()[3] ) === 'ok' && kinds( rows()[0] ) === 'muted' && kinds( rows()[2] ) === 'warn' && kinds( rows()[1] ) === '' );
	check( 'what the server sends is text and nothing else: a client that is markup is a string, no element comes of it', cells( rows()[1] )[2] === '<img src=x onerror=alert(1)>' && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'SCRIPT' } ).length === 0 );
	check( 'no word is missing from the text files', w.missing.length === 0 );

	const SORTING = [ 100000, 90000, -500, -1200 ].map( function( gross, at ) { return Object.assign( {}, LISTED[0], { id : 31 + at, number : 'R-2026-010'+ at, state : 'open', gross : gross } ) } );
	const sorting = drawn( 'en_US', SORTING );
	const sortBy = function( world, key, rowsOf ) { world.find( world.mount, function( el ) { return el.tagName === 'TH' && el.dataset.key === key } ).querySelector('button').click(); return rowsOf( world ) };
	const order = function( world ) { return world.all( world.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } ).map( function( row ) { return row.dataset.row } ).join() };
	check( 'the gross sorts by the cents: -12,00 before -5,00 before 900,00 before 1.000,00 - the order of the amounts, which is not the order of their text', sortBy( sorting, 'gross_value', order ) === '34,33,32,31' );

	const german = drawn( 'de_DE' );
	check( 'the state column is sorted by the word it shows - abgelaufen, bezahlt, Entwurf, versendet - and not by the raw state (draft, open, paid, sent)', sortBy( german, 'state_word', order ) === '7,8,5,6' );
	const find = german.find( german.mount, function( el ) { return el.tagName === 'INPUT' && el.type === 'search' } );
	find.value = 'abgelaufen';
	fire( find, 'input' );
	check( '...and it is searched by that word: "abgelaufen" finds the offer past its validity, whose raw state is open', order( german ) === '7' );

	const sorter = w.find( head[4], function( el ) { return el.tagName === 'BUTTON' } );
	sorter.click();
	check( 'the gross sorts by the cents, not by the text: -12,00 before 5,00 before 119,00 before 237,00', column() === '8,7,6,5' );
	sorter.click();
	check( '...and the other way round', column() === '5,6,7,8' );

	rows()[1].click();
	check( 'a click on a row asks for that document', w.last().action === 'crm-documents/get' && JSON.stringify( w.last().payload ) === '{"id":"6"}' );

	const search = w.find( table, function( el ) { return el.tagName === 'INPUT' && el.type === 'search' } );
	const before = w.requests.length;
	search.value = 'ben';
	fire( search, 'input' );
	check( 'the table searches where it stands: nothing is asked of the server', w.requests.length === before && column() === '7' );

	const back = drawn('en_US');
	back.find( back.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '7' } ).click();
	back.answer( 200, view( doc( { id : 7, category : 'offer', number : 'A-2020-0001', state : 'open' } ) ) );
	back.find( back.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	back.answer( 200, { documents : LISTED, years : [ 2026, 2020 ], clients : CLIENTS, settings : SMALL, items : ITEMS } );
	check( 'back from a document the focus is on the row that was opened', back.find( back.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '7' } ).focused === true );

	const empty = drawn( 'en_US', [] );
	check( 'no document says so', empty.texts().indexOf( empty.word( K+ 'empty/documents' ) ) !== -1 && empty.texts().indexOf( empty.word( K+ 'empty/found' ) ) === -1 );
	check( 'the new-document buttons stand below the table', w.button( w.mount, w.word( K+ 'label/new-invoice' ) ) !== null && w.mount.children.indexOf( table ) < w.mount.children.length - 1 );
}

console.log( '\nThe filters\n' );

{
	const w = drawn('en_US');
	const form = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-filters') !== -1 } );
	const options = function( name ) { return w.all( w.select( form, name ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value } ) };

	check( 'the filters are a select for the kind, the state, the year and the client - and no search box and no button', options('category').join() === ',offer,invoice,credit,contract,privacy,information' && options('state').join() === ',draft,open,sent,paid,cancelled,accepted,declined,signed,expired' && options('year').join() === ',2026,2020'
		&& options('client').join() === ',1,2,3' && w.all( form, function( el ) { return el.tagName === 'INPUT' || el.tagName === 'BUTTON' } ).length === 0 );
	check( '...the options are the words of the kinds and the states', w.all( w.select( form, 'state' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.textContent } ).slice( 1 ).join() === [ 'draft', 'open', 'sent', 'paid', 'cancelled', 'accepted', 'declined', 'signed', 'expired' ].map( function( s ) { return w.word( K+ 'state/'+ s ) } ).join() );

	w.select( form, 'category' ).value = 'invoice';
	fire( w.select( form, 'category' ), 'change' );
	check( 'a filter that is changed asks crm-documents/list at once, with the filters as they stand', w.last().action === 'crm-documents/list' && JSON.stringify( w.last().payload ) === '{"category":"invoice","state":"","year":"","client":""}' );
	w.answer( 200, { documents : [ LISTED[0] ], years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS } );
	check( 'the list is drawn again with the keyboard focus on the select that was changed, so that it can be stepped through', w.select( w.mount, 'category' ).focused === true && w.select( w.mount, 'category' ).value === 'invoice' );
	const form2 = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-filters') !== -1 } );
	w.select( form2, 'year' ).value = '2026';
	fire( w.select( form2, 'year' ), 'change' );
	check( '...a second filter adds to the first', JSON.stringify( w.last().payload ) === '{"category":"invoice","state":"","year":"2026","client":""}' );

	w.answer( 200, { documents : [], years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS } );
	check( '...and a filter that finds nothing says that, and not that there are no documents; the filters stay as they were set', w.texts().indexOf( w.word( K+ 'empty/found' ) ) !== -1 && w.texts().indexOf( w.word( K+ 'empty/documents' ) ) === -1
		&& w.select( w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-filters') !== -1 } ), 'category' ).value === 'invoice' );
}

console.log( '\nThe status\n' );

{
	const w = drawn('en_US');
	check( 'a module that misses nothing says so', w.texts().indexOf( w.word( K+ 'status/allok' ) ) !== -1 );

	const w2 = world('en_US');
	w2.panel.showCurrent();
	w2.answer( 200, { documents : [], years : [], clients : [], settings : SMALL, items : [] } );
	w2.answer( 200, { items : OK.map( function( item ) { return item.key === 'pdf' ? { key : 'pdf', state : 'off' } : item.key === 'templates-offer' ? { key : 'templates-offer', state : 'none' } : item.key === 'pdf-dir' ? { key : 'pdf-dir', state : 'unwritable' } : item } ) } );
	check( 'what is missing is told line by line, in the words of its state', w2.has( K+ 'status/pdf-off' ) === true && w2.texts().indexOf( w2.word( K+ 'status/pdf-off' ) ) !== -1 && w2.texts().indexOf( w2.word( K+ 'status/templates-offer-none' ) ) !== -1
		&& w2.texts().indexOf( w2.word( K+ 'status/pdf-dir-unwritable' ) ) !== -1 && w2.texts().indexOf( w2.word( K+ 'status/allok' ) ) === -1 && w2.missing.length === 0 );

	const failed = world('en_US');
	failed.panel.showCurrent();
	failed.answer( 200, { documents : [], years : [], clients : [], settings : SMALL, items : [] } );
	failed.answer( 500, { error : 'x', code : 'crm_documents_database', params : [] } );
	check( 'a status that cannot be read says so in its sentence', failed.has('/_admin/error/crm_documents_database') === true && failed.texts().indexOf( failed.word('/_admin/error/crm_documents_database') ) !== -1 );
}

console.log( '\nA new draft\n' );

{
	const w = drawn('en_US');
	w.button( w.mount, w.word( K+ 'label/new-invoice' ) ).click();
	check( 'a button for each kind; the one for an invoice asks for the templates of the kind, with nothing else', w.last().action === 'crm-documents/templates' && JSON.stringify( w.last().payload ) === '{"category":"invoice"}' && [ 'offer', 'contract', 'privacy', 'information' ].every( function( kind ) { return w.button( w.mount, w.word( K+ 'label/new-'+ kind ) ) !== null } )
		&& w.word( K+ 'label/new-credit' ) === undefined );

	w.answer( 200, { templates : TEMPLATES, offers : OFFERS } );
	const day = today();
	const clients = w.all( w.select( w.mount, 'client_id' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value } );

	check( 'the editor offers the clients that are active, and a choice to make', clients.join() === ',1,2' && w.select( w.mount, 'client_id' ).value === '' );
	check( '...the date is today and the due date follows by the term of payment of the settings; an invoice has no validity date', w.input( w.mount, 'date' ).value === day && w.input( w.mount, 'due_date' ).value === later( day, 14 ) && w.input( w.mount, 'valid_until' ) === null
		&& w.input( w.mount, 'period_from' ).value === '' && w.input( w.mount, 'period_to' ).value === '' && w.input( w.mount, 'date' ).type === 'date' );
	check( '...the templates of the tag are in the select, with a choice to make, and no input is asked for before one is chosen', w.all( w.select( w.mount, 'template_key' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value } ).join() === ',rechnung'
		&& w.all( w.mount, function( el ) { return String( el.className ).indexOf('crm-input') !== -1 } ).length === 0 );
	check( 'a draft in small_business has no reverse charge and no column of the rate', w.input( w.mount, 'reverse_charge' ) === null && w.all( w.mount, function( el ) { return el.tagName === 'TH' && el.textContent === w.word( K+ 'column/rate' ) } ).length === 0 );
	check( 'a draft that is new holds nothing unsaved, and has no delete and no finalise', w.entry().isDirty() === false && w.button( w.mount, w.word( K+ 'label/delete' ) ) === null && w.button( w.mount, w.word( K+ 'label/finalise' ) ) === null );

	const offer = created( 'en_US', 'offer' );
	check( 'an offer has a validity date by the days of an offer and no due date', offer.input( offer.mount, 'valid_until' ).value === later( day, 30 ) && offer.input( offer.mount, 'due_date' ) === null );

	const none = created( 'en_US', 'invoice', undefined, [] );
	check( 'a kind with no template in Pdf says so in the editor', none.texts().indexOf( none.word( K+ 'status/templates-none' ) ) !== -1 );

	w.select( w.mount, 'template_key' ).value = 'rechnung';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	const intro = w.input( w.mount, 'inputs.intro' );
	const footnote = w.input( w.mount, 'inputs.footnote' );
	check( 'choosing a template asks for its inputs: a field of several lines for the one that is multiline, one of a line for the other, and the required one is marked', intro.tagName === 'TEXTAREA' && footnote.tagName === 'INPUT' && intro.required === true && footnote.required !== true
		&& w.all( w.mount, function( el ) { return String( el.className ).indexOf('nino-admin-required') !== -1 } ).length === 1 );
	intro.value = 'Hallo';
	fire( intro, 'input' );
	w.select( w.mount, 'template_key' ).value = '';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	check( '...taking the template away takes its inputs away', w.input( w.mount, 'inputs.intro' ) === null );
	w.select( w.mount, 'template_key' ).value = 'rechnung';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	check( '...choosing it again brings back what was typed', w.input( w.mount, 'inputs.intro' ).value === 'Hallo' );
}

console.log( '\nThe lines and the live totals\n' );

{
	const w = created( 'en_US', 'invoice' );
	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();
	check( 'a button adds a line: one row with a quantity of 1, and the amount empty', w.all( w.mount, function( el ) { return el.tagName === 'TR' && String( el.className ).indexOf('crm-line') !== -1 } ).length === 1 && w.input( w.mount, 'lines.0.qty' ).value === '1' && w.input( w.mount, 'lines.0.price' ).value === '' );

	const total = function( kind ) { return w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-total-'+ kind) !== -1 } ) };
	const amount = function( index ) { return w.all( w.mount, function( el ) { return el.dataset.amount === '1' } )[index].textContent };

	w.input( w.mount, 'lines.0.title' ).value = 'Webdesign';
	fire( w.input( w.mount, 'lines.0.title' ), 'input' );
	w.input( w.mount, 'lines.0.qty' ).value = '2,5';
	fire( w.input( w.mount, 'lines.0.qty' ), 'input' );
	w.input( w.mount, 'lines.0.price' ).value = '90';
	fire( w.input( w.mount, 'lines.0.price' ), 'input' );
	check( 'typing a quantity and a price shows the amount of the row at once, by the rules of the server: 2,5 x 90 is 225,00', amount( 0 ) === '225,00' );
	check( '...and the totals: in small_business the gross alone', total('gross').children[1].textContent === '225,00'+ NBSP+ '€' && total('net') === null && total('vat') === null );

	w.input( w.mount, 'lines.0.qty' ).value = 'viel';
	fire( w.input( w.mount, 'lines.0.qty' ), 'input' );
	check( 'a quantity that is no number empties the amount, marks the field and counts the row as nothing', amount( 0 ) === '' && w.input( w.mount, 'lines.0.qty' ).attributes['aria-invalid'] === 'true' && total('gross').children[1].textContent === '0,00'+ NBSP+ '€' );
	w.input( w.mount, 'lines.0.qty' ).value = '0,333';
	fire( w.input( w.mount, 'lines.0.qty' ), 'input' );
	w.input( w.mount, 'lines.0.price' ).value = '1,50';
	fire( w.input( w.mount, 'lines.0.price' ), 'input' );
	check( '...the mark goes with the next good value; 0,333 x 1,50 rounds half away from zero to 0,50', w.input( w.mount, 'lines.0.qty' ).attributes['aria-invalid'] === undefined && amount( 0 ) === '0,50' );
	w.input( w.mount, 'lines.0.qty' ).value = '-2';
	fire( w.input( w.mount, 'lines.0.qty' ), 'input' );
	check( 'a negative quantity is an amount below zero', amount( 0 ) === '-3,00' && total('gross').children[1].textContent === '-3,00'+ NBSP+ '€' );

	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();
	w.input( w.mount, 'lines.1.title' ).value = 'Hosting';
	fire( w.input( w.mount, 'lines.1.title' ), 'input' );
	w.input( w.mount, 'lines.1.price' ).value = '12';
	fire( w.input( w.mount, 'lines.1.price' ), 'input' );
	check( 'a second row keeps the first as it was typed, and the totals add up', w.input( w.mount, 'lines.0.title' ).value === 'Webdesign' && w.input( w.mount, 'lines.0.qty' ).value === '-2' && amount( 1 ) === '12,00' && total('gross').children[1].textContent === '9,00'+ NBSP+ '€' );

	w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-down') !== -1 } )[0].click();
	check( 'a row moves down; the first row cannot move up and the last cannot move down', w.input( w.mount, 'lines.0.title' ).value === 'Hosting' && w.input( w.mount, 'lines.1.title' ).value === 'Webdesign'
		&& w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-up') !== -1 } )[0].disabled === true && w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-down') !== -1 } )[1].disabled === true );
	w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-remove') !== -1 } )[0].click();
	check( 'a row is removed, and the totals follow', w.all( w.mount, function( el ) { return el.tagName === 'TR' && String( el.className ).indexOf('crm-line') !== -1 } ).length === 1 && w.input( w.mount, 'lines.0.title' ).value === 'Webdesign' && total('gross').children[1].textContent === '-3,00'+ NBSP+ '€' );

	const lineButtons = w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-up') !== -1 } );
	check( 'the arrows and the cross have names for a screen reader', lineButtons.length === 1 && lineButtons[0].attributes['aria-label'] === w.word( K+ 'label/up' ) );
}

console.log( '\nThe items of the catalogue\n' );

{
	const w = created( 'en_US', 'invoice' );
	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();
	const list = w.find( w.mount, function( el ) { return el.tagName === 'DATALIST' } );

	check( 'the title of a row offers the active items of the catalogue by their titles', list !== null && w.all( list, function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value } ).join() === 'Webdesign,Hosting' && w.input( w.mount, 'lines.0.title' ).attributes.list === list.id );

	w.input( w.mount, 'lines.0.title' ).value = 'webdesign';
	fire( w.input( w.mount, 'lines.0.title' ), 'input' );
	check( 'a title that names an item fills the row from it: unit, price, description - and the rate 0, as the document has no VAT', w.input( w.mount, 'lines.0.title' ).value === 'Webdesign' && w.input( w.mount, 'lines.0.unit' ).value === 'Std.' && w.input( w.mount, 'lines.0.price' ).value === '90,00' && w.input( w.mount, 'lines.0.description' ).value === 'Pro Stunde' && w.input( w.mount, 'lines.0.qty' ).value === '1' );

	w.input( w.mount, 'lines.0.title' ).value = 'Hosting';
	fire( w.input( w.mount, 'lines.0.title' ), 'input' );
	check( 'a row that has a price is not filled again over what was typed', w.input( w.mount, 'lines.0.price' ).value === '90,00' && w.input( w.mount, 'lines.0.unit' ).value === 'Std.' );

	const regular = created( 'en_US', 'invoice', REGULAR );
	regular.button( regular.mount, regular.word( K+ 'label/add-line' ) ).click();
	regular.input( regular.mount, 'lines.0.title' ).value = 'Webdesign';
	fire( regular.input( regular.mount, 'lines.0.title' ), 'input' );
	check( 'in regular the rate of the item comes with it', regular.select( regular.mount, 'lines.0.vat_rate' ).value === '1900' );
}

console.log( '\nVAT: regular and reverse charge\n' );

{
	const w = created( 'en_US', 'invoice', REGULAR );
	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();
	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();

	const total = function( kind ) { return w.all( w.mount, function( el ) { return String( el.className ).indexOf('crm-total-'+ kind) !== -1 } ) };
	const type = function( name, value, event ) { w.input( w.mount, name ).value = value; fire( w.input( w.mount, name ), event ?? 'input' ) };

	check( 'in regular a row has a select for the rate, with the rates of the settings, and the table a column for it', w.select( w.mount, 'lines.0.vat_rate' ) !== null && w.all( w.select( w.mount, 'lines.0.vat_rate' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.textContent } ).join() === '19 %,7 %,0 %'
		&& w.all( w.mount, function( el ) { return el.tagName === 'TH' && el.textContent === w.word( K+ 'column/rate' ) } ).length === 1 && w.select( w.mount, 'lines.0.vat_rate' ).value === '1900' );

	type( 'lines.0.price', '225' );
	type( 'lines.1.price', '20' );
	w.select( w.mount, 'lines.1.vat_rate' ).value = '700';
	fire( w.select( w.mount, 'lines.1.vat_rate' ), 'change' );
	check( 'the totals have the net, the VAT of each rate on its net - the highest rate first - and the gross: 225,00 at 19 % and 20,00 at 7 %', total('net')[0].children[1].textContent === '245,00'+ NBSP+ '€' && total('vat').map( function( el ) { return el.children[1].textContent } ).join() === [ '42,75', '1,40' ].map( function( s ) { return s+ NBSP+ '€' } ).join()
		&& total('vat')[0].children[0].textContent === w.said( K+ 'totals/vat', '19 %', '225,00'+ NBSP+ '€' ) && total('gross')[0].children[1].textContent === '289,15'+ NBSP+ '€' );

	const reverse = w.input( w.mount, 'reverse_charge' );
	check( 'a reverse charge can be chosen in regular', reverse !== null && reverse.type === 'checkbox' && reverse.checked === false );
	reverse.checked = true;
	fire( reverse, 'change' );
	check( '...it takes the rate of every row to 0, locks the selects, and the VAT to 0', w.select( w.mount, 'lines.0.vat_rate' ).value === '0' && w.select( w.mount, 'lines.0.vat_rate' ).disabled === true && total('vat').map( function( el ) { return el.children[1].textContent } ).join() === '0,00'+ NBSP+ '€' && total('gross')[0].children[1].textContent === '245,00'+ NBSP+ '€' );
	reverse.checked = false;
	fire( reverse, 'change' );
	check( '...and without it the rows have their own rates again', w.select( w.mount, 'lines.0.vat_rate' ).value === '1900' && w.select( w.mount, 'lines.0.vat_rate' ).disabled === false && w.select( w.mount, 'lines.1.vat_rate' ).value === '700' );
}

console.log( '\nSaving a draft\n' );

{
	const w = created( 'en_US', 'invoice' );
	w.select( w.mount, 'client_id' ).value = '2';
	fire( w.select( w.mount, 'client_id' ), 'change' );
	w.select( w.mount, 'template_key' ).value = 'rechnung';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	w.input( w.mount, 'inputs.intro' ).value = 'Hallo';
	fire( w.input( w.mount, 'inputs.intro' ), 'input' );
	w.input( w.mount, 'period_from' ).value = '2026-01-01';
	fire( w.input( w.mount, 'period_from' ), 'input' );
	w.input( w.mount, 'period_to' ).value = '2026-01-31';
	fire( w.input( w.mount, 'period_to' ), 'input' );
	w.input( w.mount, 'notes' ).value = 'intern';
	fire( w.input( w.mount, 'notes' ), 'input' );
	w.button( w.mount, w.word( K+ 'label/add-line' ) ).click();
	[ [ 'title', 'Webdesign' ], [ 'description', 'Pro Stunde' ], [ 'unit', 'Std.' ], [ 'qty', '2,5' ], [ 'price', '90' ] ].forEach( function( [ name, value ] ) {
		w.input( w.mount, 'lines.0.'+ name ).value = value;
		fire( w.input( w.mount, 'lines.0.'+ name ), 'input' );
	} );

	check( 'what is typed is unsaved input', w.entry().isDirty() === true );

	const result = [];
	w.entry().save( function( ok ) { result.push( ok ) } );
	const payload = w.last().payload;

	check( 'a new draft is posted as crm-documents/save with the kind and no id, the client as a string, the dates, the template, the inputs it uses, the notes and the lines as they were typed', w.last().action === 'crm-documents/save' && payload.category === 'invoice' && payload.id === undefined && payload.client_id === '2'
		&& payload.date === today() && payload.due_date === later( today(), 14 ) && payload.valid_until === undefined && payload.period_from === '2026-01-01' && payload.period_to === '2026-01-31' && payload.template_key === 'rechnung' && JSON.stringify( payload.inputs ) === '{"intro":"Hallo"}'
		&& payload.reverse_charge === false && payload.notes === 'intern' && JSON.stringify( payload.lines ) === '[{"title":"Webdesign","description":"Pro Stunde","unit":"Std.","qty":"2,5","price":"90","vat_rate":0}]' );

	const saved = doc( { id : 9, client_id : 2, period_from : '2026-01-01', period_to : '2026-01-31', due_date : payload.due_date, date : payload.date, notes : 'intern', net : 22500, gross : 22500, lines : [ doc().lines[0] ] } );
	w.answer( 200, { document : saved } );
	check( 'a save that goes through ends in done( true ), and the editor is drawn again from what is stored with its line saying so', result.join() === 'true' && w.entry().isDirty() === false && ( w.line( w.mount ) || { dataset : {} } ).dataset.state === 'saved'
		&& w.input( w.mount, 'lines.0.qty' ).value === '2,5' && w.input( w.mount, 'lines.0.price' ).value === '90,00' && w.select( w.mount, 'client_id' ).value === '2' );

	w.input( w.mount, 'notes' ).value = 'andere';
	fire( w.input( w.mount, 'notes' ), 'input' );
	w.entry().save( function() {} );
	check( 'a draft that exists is posted with its id and not with a kind', w.last().payload.id === '9' && w.last().payload.category === undefined && w.last().payload.notes === 'andere' );
	w.answer( 400, { error : 'x', code : 'crm_documents_line_amount', params : [ 1 ], field : 'lines.0.qty' } );
	check( 'a refusal says its sentence with the row, ends in done( false ), keeps what was typed and marks the field it names', w.has('/_admin/error/crm_documents_line_amount') === true && w.texts().indexOf( w.said('/_admin/error/crm_documents_line_amount', 1 ) ) !== -1
		&& w.input( w.mount, 'notes' ).value === 'andere' && w.input( w.mount, 'lines.0.qty' ).attributes['aria-invalid'] === 'true' );

	const busy = [];
	w.entry().save( function( ok ) { busy.push( ok ) } );
	w.entry().save( function( ok ) { busy.push( ok ) } );
	check( 'a second save while one is out ends in done( false ) at once', busy.join() === 'false' );

	w.entry().discard();
	check( 'discard puts the editor back as it was drawn', w.input( w.mount, 'notes' ).value === 'intern' && w.entry().isDirty() === false );

	const back = created( 'en_US', 'invoice' );
	back.input( back.mount, 'notes' ).value = 'x';
	fire( back.input( back.mount, 'notes' ), 'input' );
	back.find( back.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	check( 'the back link asks the registry before it leaves unsaved input', back.guarded.length === 1 && back.guarded[0].join() === 'crm-documents' && back.last().action === 'crm-documents/list' );

	const refused = created( 'en_US', 'invoice' );
	refused.entry().save( function() {} );
	refused.answer( 400, { error : 'x', code : 'crm_documents_client', params : [], field : 'client_id' } );
	check( 'a refusal that names a field marks it - the client that is not chosen', refused.select( refused.mount, 'client_id' ).attributes['aria-invalid'] === 'true' && refused.texts().indexOf( refused.word('/_admin/error/crm_documents_client') ) !== -1 );
}

console.log( '\nFinalising and deleting\n' );

{
	const w = opened( 'en_US', doc() );
	check( 'an editor of a draft that exists has the buttons finalise and delete', w.button( w.mount, w.word( K+ 'label/finalise' ) ) !== null && w.button( w.mount, w.word( K+ 'label/delete' ) ) !== null );

	w.answers.confirm = false;
	w.button( w.mount, w.word( K+ 'label/finalise' ) ).click();
	check( 'finalising asks first, in the words that say what happens; the answer no posts nothing', w.asked.length === 1 && w.asked[0] === w.word( K+ 'confirm/finalise' ) && w.last().action === 'crm-documents/get' );

	w.answers.confirm = true;
	w.button( w.mount, w.word( K+ 'label/finalise' ) ).click();
	check( 'the answer yes posts crm-documents/finalise with the id', w.last().action === 'crm-documents/finalise' && JSON.stringify( w.last().payload ) === '{"id":"5"}' );

	w.answer( 200, view( sealed( 'open', { id : 5 } ) ) );
	check( '...and the page of the finalised document is drawn with its number', w.texts().some( function( text ) { return text.indexOf('R-2026-0001') === 0 } ) && w.button( w.mount, w.word( K+ 'label/finalise' ) ) === null && w.input( w.mount, 'lines.0.title' ) === null );

	const dirty = opened( 'en_US', doc() );
	dirty.input( dirty.mount, 'notes' ).value = 'noch nicht gespeichert';
	fire( dirty.input( dirty.mount, 'notes' ), 'input' );
	dirty.button( dirty.mount, dirty.word( K+ 'label/finalise' ) ).click();
	check( 'a draft that holds what is not saved is saved first, then asked about', dirty.last().action === 'crm-documents/save' && dirty.asked.length === 0 );
	dirty.answer( 200, { document : doc( { notes : 'noch nicht gespeichert' } ) } );
	check( '...once it is saved the question comes, and the answer yes posts the finalising', dirty.asked.length === 1 && dirty.last().action === 'crm-documents/finalise' && dirty.last().payload.id === '5' );

	const refused = opened( 'en_US', doc() );
	refused.button( refused.mount, refused.word( K+ 'label/finalise' ) ).click();
	refused.answer( 409, { error : 'x', code : 'crm_documents_inputs', params : [], field : 'inputs.intro' } );
	check( 'a finalising that is refused says why in the line and leaves the editor', refused.texts().indexOf( refused.word('/_admin/error/crm_documents_inputs') ) !== -1 && refused.input( refused.mount, 'lines.0.title' ) !== null && refused.input( refused.mount, 'inputs.intro' ).attributes['aria-invalid'] === 'true' );

	const removing = opened( 'en_US', doc() );
	removing.answers.confirm = false;
	removing.button( removing.mount, removing.word( K+ 'label/delete' ) ).click();
	check( 'deleting asks first; the answer no posts nothing', removing.asked.length === 1 && removing.asked[0] === removing.word( K+ 'confirm/delete' ) && removing.last().action === 'crm-documents/get' );
	removing.answers.confirm = true;
	removing.button( removing.mount, removing.word( K+ 'label/delete' ) ).click();
	check( 'the answer yes posts crm-documents/remove with the id, and the list is asked for again', removing.last().action === 'crm-documents/remove' && JSON.stringify( removing.last().payload ) === '{"id":"5"}' );
	removing.answer( 200, { removed : true } );
	check( '...', removing.last().action === 'crm-documents/list' );
}

console.log( '\nA finalised document\n' );

{
	const w = opened( 'en_US', sealed( 'sent', { period_from : '2026-09-01', period_to : '2026-09-30', notes : 'Hinweis' } ) );
	const facts = w.all( w.mount, function( el ) { return el.tagName === 'TR' && el.children.length === 2 && el.children[0].tagName === 'TH' && el.parentNode !== null && String( el.parentNode.className ).indexOf('crm-facts') !== -1 } ).map( function( row ) { return row.children[0].textContent+ '=' + row.children[1].textContent } );
	const rows = w.all( w.mount, function( el ) { return el.tagName === 'TR' && String( el.className ).indexOf('crm-line') !== -1 } );

	check( 'the page names the number and the kind, wears the badge of its state, and says the client, the dates and the period as d.m.Y', w.texts().indexOf( 'R-2026-0001 - '+ w.word( K+ 'category/invoice' ) ) !== -1 && w.texts().indexOf( w.word( K+ 'state/sent' ) ) !== -1
		&& facts.indexOf( w.word( K+ 'field/client' )+ '=Studio Nord' ) !== -1 && facts.indexOf( w.word( K+ 'field/date' )+ '=03.11.2026' ) !== -1 && facts.indexOf( w.word( K+ 'field/period-from' )+ '=01.09.2026' ) !== -1 && facts.indexOf( w.word( K+ 'field/sent-at' )+ '=02.10.2026' ) !== -1 );
	check( '...the lines are text, not fields: title, quantity, unit, price and amount with the currency', rows.length === 2 && w.all( w.mount, function( el ) { return el.tagName === 'INPUT' && String( el.name ).indexOf('lines.') === 0 } ).length === 0
		&& rows[0].children.map( function( cell ) { return cell.textContent } ).join('|') === [ 'Webdesign', '2,5', 'Std.', '90,00'+ NBSP+ '€', '225,00'+ NBSP+ '€' ].join('|') );
	check( '...the totals are those of the document', w.all( w.mount, function( el ) { return String( el.className ).indexOf('crm-total-gross') !== -1 } )[0].children[1].textContent === '237,00'+ NBSP+ '€' );

	const pdf = w.find( w.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('crm-pdf') !== -1 } );
	const download = w.find( w.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('crm-download') !== -1 } );
	check( 'a link shows the PDF and one saves it: the address of the route of the module, and ?download=1', pdf.href === '/.crm/pdf/6' && pdf.target === '_blank' && pdf.rel === 'noopener' && download.href === '/.crm/pdf/6?download=1' && pdf.textContent === w.word( K+ 'label/pdf' ) );
	check( 'an invoice that is sent offers paid and the credit note, and nothing else', w.button( w.mount, w.word( K+ 'label/set-paid' ) ) !== null && w.button( w.mount, w.word( K+ 'label/set-sent' ) ) === null && w.button( w.mount, w.word( K+ 'label/make-credit' ) ) !== null && w.button( w.mount, w.word( K+ 'label/make-invoice' ) ) === null );

	w.input( w.mount, 'step_date' ).value = '2026-11-02';
	w.button( w.mount, w.word( K+ 'label/set-paid' ) ).click();
	check( 'a step posts crm-documents/state with the id, the state and the day, which is today until it is changed', w.last().action === 'crm-documents/state' && JSON.stringify( w.last().payload ) === '{"id":"6","state":"paid","date":"2026-11-02"}' && w.input( w.mount, 'step_date' ).type === 'date' );
	w.answer( 200, { document : sealed( 'paid', { paid_at : '2026-11-02T00:00:00Z' } ) } );
	check( '...and the page is drawn again in the state it is in now: paid, with the credit note as the only step', w.texts().indexOf( w.word( K+ 'state/paid' ) ) !== -1 && w.button( w.mount, w.word( K+ 'label/set-paid' ) ) === null && w.button( w.mount, w.word( K+ 'label/make-credit' ) ) !== null );

	const refused = opened( 'en_US', sealed( 'open' ) );
	refused.button( refused.mount, refused.word( K+ 'label/set-sent' ) ).click();
	check( 'an invoice that is open offers sent, with the day today', refused.last().action === 'crm-documents/state' && refused.last().payload.state === 'sent' && refused.last().payload.date === today() );
	refused.answer( 409, { error : 'x', code : 'crm_documents_state', params : [] } );
	check( '...a step that is refused says why and leaves the page', refused.texts().indexOf( refused.word('/_admin/error/crm_documents_state') ) !== -1 && refused.button( refused.mount, refused.word( K+ 'label/set-sent' ) ) !== null );

	const credit = opened( 'en_US', sealed( 'paid' ) );
	credit.button( credit.mount, credit.word( K+ 'label/make-credit' ) ).click();
	check( 'the credit note posts crm-documents/credit with the id, and the draft that comes back is opened', credit.last().action === 'crm-documents/credit' && JSON.stringify( credit.last().payload ) === '{"id":"6"}' );
	credit.answer( 200, { document : doc( { id : 12, category : 'credit' } ) } );
	check( '...asked for by get', credit.last().action === 'crm-documents/get' && credit.last().payload.id === '12' );

	const offer = opened( 'en_US', sealed( 'open', { category : 'offer', number : 'A-2026-0001', id : 6 } ) );
	check( 'an offer that is open offers sent, accepted, declined and the invoice', [ 'set-sent', 'set-accepted', 'set-declined', 'make-invoice' ].every( function( key ) { return offer.button( offer.mount, offer.word( K+ 'label/'+ key ) ) !== null } ) && offer.button( offer.mount, offer.word( K+ 'label/make-credit' ) ) === null );
	offer.button( offer.mount, offer.word( K+ 'label/make-invoice' ) ).click();
	check( '...the invoice posts crm-documents/from-offer', offer.last().action === 'crm-documents/from-offer' && JSON.stringify( offer.last().payload ) === '{"id":"6"}' );

	const accepted = opened( 'en_US', sealed( 'accepted', { category : 'offer', number : 'A-2026-0001' } ) );
	check( 'an accepted offer offers the invoice and no step', accepted.button( accepted.mount, accepted.word( K+ 'label/make-invoice' ) ) !== null && accepted.button( accepted.mount, accepted.word( K+ 'label/set-sent' ) ) === null && accepted.button( accepted.mount, accepted.word( K+ 'label/set-accepted' ) ) === null );

	const cancelled = opened( 'en_US', sealed( 'cancelled' ) );
	check( 'a cancelled invoice offers nothing but its PDF', cancelled.button( cancelled.mount, cancelled.word( K+ 'label/make-credit' ) ) === null && cancelled.button( cancelled.mount, cancelled.word( K+ 'label/set-paid' ) ) === null && cancelled.find( cancelled.mount, function( el ) { return String( el.className ).indexOf('crm-pdf') !== -1 } ) !== null );

	const creditNote = opened( 'en_US', sealed( 'open', { category : 'credit', number : 'G-2026-0001' } ) );
	check( 'a credit note that is open offers sent and nothing else', creditNote.button( creditNote.mount, creditNote.word( K+ 'label/set-sent' ) ) !== null && creditNote.button( creditNote.mount, creditNote.word( K+ 'label/make-credit' ) ) === null && creditNote.button( creditNote.mount, creditNote.word( K+ 'label/make-invoice' ) ) === null );

	const related = opened( 'en_US', sealed( 'sent', { parent : { id : 4, number : 'A-2026-0003', category : 'offer', state : 'accepted' }, children : [ { id : 8, number : 'G-2026-0001', category : 'credit', state : 'open', gross : -100 } ] } ) );
	const links = related.all( related.mount, function( el ) { return el.tagName === 'BUTTON' && el.dataset.id !== undefined && el.parentNode !== null && String( el.parentNode.className ).indexOf('crm-relations') !== -1 } );
	check( 'the offer it comes from and the credit notes under it are buttons that open them, in the words of their kind', links.length === 2 && links[0].textContent === related.said( K+ 'label/from-offer', 'A-2026-0003' ) && links[1].textContent === related.said( K+ 'label/to-credit', 'G-2026-0001' ) );
	links[1].click();
	check( '...by get', related.last().action === 'crm-documents/get' && related.last().payload.id === '8' );
}

console.log( '\nThe notes of a finalised document\n' );

{
	const w = opened( 'en_US', sealed( 'sent', { notes : 'Hinweis' } ) );
	check( 'the notes are a field of their own, with their own button', w.input( w.mount, 'notes' ).value === 'Hinweis' && w.button( w.mount, w.word( K+ 'label/save-notes' ) ) !== null && w.entry().isDirty() === false );

	w.input( w.mount, 'notes' ).value = 'Gemahnt';
	fire( w.input( w.mount, 'notes' ), 'input' );
	check( 'an edit is unsaved input', w.entry().isDirty() === true );

	const result = [];
	w.entry().save( function( ok ) { result.push( ok ) } );
	check( 'a save posts the id and the notes and nothing else', w.last().action === 'crm-documents/save' && JSON.stringify( w.last().payload ) === '{"id":"6","notes":"Gemahnt"}' );
	w.answer( 200, { document : sealed( 'sent', { notes : 'Gemahnt' } ) } );
	check( '...and the page is drawn again with its line saying it is saved', result.join() === 'true' && w.input( w.mount, 'notes' ).value === 'Gemahnt' && w.entry().isDirty() === false && w.all( w.mount, function( el ) { return String( el.className ).indexOf('nino-admin-status') !== -1 } ).pop().dataset.state === 'saved' );

	w.input( w.mount, 'notes' ).value = 'x';
	fire( w.input( w.mount, 'notes' ), 'input' );
	w.entry().discard();
	check( 'discard puts the notes back', w.input( w.mount, 'notes' ).value === 'Gemahnt' );
}

console.log( '\nThe settings\n' );

{
	const w = drawn('en_US');
	w.button( w.mount, w.word( K+ 'label/settings' ) ).click();
	check( 'the button asks for crm-documents/settings-get', w.last().action === 'crm-documents/settings-get' );

	w.answer( 200, { settings : SMALL } );
	const names = [ 'vat_mode', 'vat_rates', 'payment_days', 'offer_days', 'currency', 'number_offer', 'number_invoice', 'number_credit' ];
	const field = function( name ) { const named = name === 'vat_rates' ? 'vat_rates_percent' : name; return w.input( w.mount, named ) ?? w.select( w.mount, named ) };

	check( 'a field for each setting, filled with its value; the mode is a select of the two modes', names.every( function( name ) { return field( name ) !== null && field( name ).value === ( name === 'vat_rates' ? '19; 7; 0' : SMALL[name] ) } ) && w.all( w.select( w.mount, 'vat_mode' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value } ).join() === 'small_business,regular' );
	check( '...and the screen says that a changed mode applies to new drafts only, and that a changed number format is the owner\'s to answer for', w.has( K+ 'setting-hint/vat-mode' ) === true && w.has( K+ 'setting/numbers-note' ) === true && w.texts().indexOf( w.word( K+ 'setting/numbers-note' ) ) !== -1
		&& w.all( w.mount, function( el ) { return el.textContent === w.word( K+ 'setting-hint/vat-mode' ) } ).length === 1 );

	field('payment_days').value = '21';
	field('vat_mode').value = 'regular';
	fire( w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-settings') !== -1 } ), 'submit' );
	check( 'a save posts crm-documents/settings-save with all eight settings as text', w.last().action === 'crm-documents/settings-save' && Object.keys( w.last().payload ).join() === names.map( function( name ) { return name === 'vat_rates' ? 'vat_rates_percent' : name } ).join() && w.last().payload.payment_days === '21' && w.last().payload.vat_mode === 'regular' && w.last().payload.currency === '€' );

	check( 'the rates are shown as percentages, apart by semicolons, and posted as typed - the server reads them, the screen converts nothing', w.last().payload.vat_rates_percent === '19; 7; 0' && w.last().payload.vat_rates === undefined && w.input( w.mount, 'vat_rates_percent' ) !== null );
	w.answer( 400, { error : 'x', code : 'crm_documents_numberformat', params : [], field : 'number_credit' } );
	check( 'a refusal says its sentence and marks the field it names', w.texts().indexOf( w.word('/_admin/error/crm_documents_numberformat') ) !== -1 && field('number_credit').attributes['aria-invalid'] === 'true' );
	const formSettings = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-settings') !== -1 } );
	const posted = function( typed ) { field('vat_rates').value = typed; fire( formSettings, 'submit' ); const sent = w.last().payload.vat_rates_percent; w.answer( 400, { error : 'x', code : 'crm_documents_setting', params : [], field : 'vat_rates_percent' } ); return sent };
	check( '...whatever is typed is posted as it is, 19,7,0 included; a refusal marks the field of the rates', posted( '19,7,0' ) === '19,7,0' && posted( '19,5;7 0' ) === '19,5;7 0' && field('vat_rates').attributes['aria-invalid'] === 'true' );
	check( '...the screen has no conversion of its own', typeof w.panel._ratesStored === 'undefined' );

	fire( w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-settings') !== -1 } ), 'submit' );
	w.answer( 200, { settings : Object.assign( {}, SMALL, { vat_mode : 'regular', payment_days : '21', vat_rates : '1900,700' } ) } );
	check( 'a save that goes through says saved, and the editor that follows works with the settings as they are now: the rates, the mode', ( w.line( w.mount ) || { dataset : {} } ).dataset.state === 'saved' && w.panel._settings.vat_mode === 'regular' && w.panel._settings.rates.join() === '1900,700' );
}

console.log( '\nWhat the editor posts, once more\n' );

{
	const type = function( w, name, value ) { w.input( w.mount, name ).value = value; fire( w.input( w.mount, name ), 'input' ) };
	const pick = function( w, name, value ) { w.select( w.mount, name ).value = value; fire( w.select( w.mount, name ), 'change' ) };

	const two = created( 'en_US', 'invoice', undefined, TEMPLATES.concat( [ { key : 'schlicht', name : 'Schlicht', inputs : [] } ] ) );
	pick( two, 'client_id', '1' );
	pick( two, 'template_key', 'rechnung' );
	type( two, 'inputs.intro', 'Hallo' );
	pick( two, 'template_key', 'schlicht' );
	two.entry().save( function() {} );
	check( 'the inputs posted are those of the template that is chosen: what was typed for another is left out', JSON.stringify( two.last().payload.inputs ) === '{}' && two.last().payload.template_key === 'schlicht' );

	const offer = drawn('en_US');
	offer.button( offer.mount, offer.word( K+ 'label/new-offer' ) ).click();
	check( 'the button for an offer asks for the templates of an offer', offer.last().action === 'crm-documents/templates' && offer.last().payload.category === 'offer' );
	offer.answer( 200, { templates : TEMPLATES } );
	offer.entry().save( function() {} );
	check( '...and what is saved is an offer, with a validity date and no due date', offer.last().payload.category === 'offer' && offer.last().payload.valid_until === later( today(), 30 ) && offer.last().payload.due_date === undefined );

	const regular = created( 'en_US', 'invoice', REGULAR );
	regular.button( regular.mount, regular.word( K+ 'label/add-line' ) ).click();
	regular.entry().save( function() {} );
	check( 'in regular a line posts the rate it has, and no reverse charge', regular.last().payload.lines[0].vat_rate === 1900 && regular.last().payload.reverse_charge === false );
	const reverse = created( 'en_US', 'invoice', REGULAR );
	reverse.button( reverse.mount, reverse.word( K+ 'label/add-line' ) ).click();
	reverse.input( reverse.mount, 'reverse_charge' ).checked = true;
	fire( reverse.input( reverse.mount, 'reverse_charge' ), 'change' );
	reverse.entry().save( function() {} );
	check( 'with the reverse charge it posts the flag, and the rate 0 for every line', reverse.last().payload.reverse_charge === true && reverse.last().payload.lines[0].vat_rate === 0 );

	const small = opened( 'en_US', doc( { lines : [ Object.assign( {}, doc().lines[0], { vat_rate : 1900 } ) ] } ) );
	small.entry().save( function() {} );
	check( 'in small_business a line posts the rate 0, whatever the line had', small.last().payload.lines[0].vat_rate === 0 && small.last().payload.reverse_charge === false );

	const filled = created( 'en_US', 'invoice' );
	filled.button( filled.mount, filled.word( K+ 'label/add-line' ) ).click();
	type( filled, 'lines.0.title', 'Webdesign' );
	filled.entry().save( function() {} );
	check( 'a line filled from an item whose rate is 19 % posts the rate 0 in small_business', filled.last().payload.lines[0].vat_rate === 0 && filled.last().payload.lines[0].price === '90,00' );

	const text = created( 'en_US', 'invoice' );
	text.button( text.mount, text.word( K+ 'label/add-line' ) ).click();
	type( text, 'lines.0.description', 'Zeile eins' );
	type( text, 'lines.0.price', '5' );
	type( text, 'lines.0.qty', '0' );
	check( 'a quantity of 0 is no amount: the row is empty and marked', text.all( text.mount, function( el ) { return el.dataset.amount === '1' } )[0].textContent === '' && text.input( text.mount, 'lines.0.qty' ).attributes['aria-invalid'] === 'true' );
	text.entry().save( function() {} );
	check( 'a description typed is posted', text.last().payload.lines[0].description === 'Zeile eins' );
	text.answer( 400, { error : 'x', code : 'crm_documents_client', params : [], field : 'client_id' } );
	const failed = [];
	text.entry().save( function( ok ) { failed.push( ok ) } );
	text.answer( 400, { error : 'x', code : 'crm_documents_client', params : [], field : 'client_id' } );
	check( 'a save that is refused ends in done( false )', failed.join() === 'false' );

	const row = drawn('en_US');
	row.find( row.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '6' } ).click();
	check( 'a row asks for the document it shows', row.last().action === 'crm-documents/get' && row.last().payload.id === '6' );

	const accept = opened( 'en_US', sealed( 'open', { category : 'offer', number : 'A-2026-0001' } ) );
	accept.button( accept.mount, accept.word( K+ 'label/set-accepted' ) ).click();
	check( 'accepting an offer posts the state and no day', JSON.stringify( accept.last().payload ) === '{"id":"6","state":"accepted","date":""}' );

	const open = opened( 'en_US', sealed( 'open' ) );
	check( 'an invoice that is open offers sent and not paid', open.button( open.mount, open.word( K+ 'label/set-sent' ) ) !== null && open.button( open.mount, open.word( K+ 'label/set-paid' ) ) === null );
	const declined = opened( 'en_US', sealed( 'declined', { category : 'offer', number : 'A-2026-0001' } ) );
	check( 'a declined offer offers no invoice and no step', declined.button( declined.mount, declined.word( K+ 'label/make-invoice' ) ) === null && declined.button( declined.mount, declined.word( K+ 'label/set-accepted' ) ) === null );
	const final = opened( 'en_US', sealed( 'sent', { vat_mode : 'regular', net : 1000, vat : 190, gross : 1190, lines : [ Object.assign( {}, doc().lines[1], { price_net : 1000, qty : 1000, amount_net : 1000, vat_rate : 1900 } ) ] } ), REGULAR );
	check( 'the page of a regular document has the column of the rate and the rate of each line, the net and the VAT in the totals', final.all( final.mount, function( el ) { return el.tagName === 'TH' && el.textContent === final.word( K+ 'column/rate' ) } ).length === 1
		&& final.all( final.mount, function( el ) { return el.tagName === 'TD' && el.textContent === '19 %' } ).length === 1 && final.all( final.mount, function( el ) { return String( el.className ).indexOf('crm-total-vat') !== -1 } )[0].children[1].textContent === '1,90'+ NBSP+ '€' );
}

console.log( '\nFrom the review\n' );

{
	const type = function( w, name, value ) { w.input( w.mount, name ).value = value; fire( w.input( w.mount, name ), 'input' ) };

	const lined = opened( 'en_US', doc( { lines : [ Object.assign( {}, doc().lines[0], { description : 'Zeile eins\nZeile zwei' } ) ] } ) );
	const description = lined.input( lined.mount, 'lines.0.description' );
	check( 'the description of a line is a field of several lines, shown with its line break', description.tagName === 'TEXTAREA' && description.value === 'Zeile eins\nZeile zwei' && description.rows >= 2 );
	type( lined, 'lines.0.qty', '3' );
	lined.entry().save( function() {} );
	check( 'a description with a line break survives an edit of the row and is posted as it was', lined.last().payload.lines[0].description === 'Zeile eins\nZeile zwei' );
	lined.answer( 200, { document : doc() } );
	type( lined, 'lines.0.description', 'Erste\nZweite\nDritte' );
	lined.entry().save( function() {} );
	check( '...and one typed with several breaks is posted with them', lined.last().payload.lines[0].description === 'Erste\nZweite\nDritte' );

	// A credit note is made from an invoice and never from the list: its draft is opened from there
	const credit = opened( 'en_US', doc( { id : 5, category : 'credit', due_date : null } ) );
	check( 'a credit note has neither a due date nor a validity date in its editor', credit.input( credit.mount, 'due_date' ) === null && credit.input( credit.mount, 'valid_until' ) === null && credit.input( credit.mount, 'date' ) !== null );
	credit.entry().save( function() {} );
	check( '...and posts neither', credit.last().payload.id === '5' && credit.last().payload.due_date === undefined && credit.last().payload.valid_until === undefined );

	const sent = opened( 'en_US', sealed( 'open', { id : 5, finalised : '2026-10-01T09:00:00Z' } ) );
	check( 'the state badge in the title of a finalised document has a class of its own, which gives it its space', sent.all( sent.mount, function( el ) { return String( el.className ).indexOf('crm-state') !== -1 } ).length === 1 );

	const fin = opened( 'en_US', doc() );
	fin.button( fin.mount, fin.word( K+ 'label/finalise' ) ).click();
	fin.answer( 200, view( sealed( 'open', { id : 5, finalised : '2026-10-01T09:00:00Z' } ) ) );
	const lines = fin.all( fin.mount, function( el ) { return String( el.className ).indexOf('nino-admin-status') !== -1 } ).map( function( el ) { return el.textContent } );
	check( 'a document finalised just now says when, and not that it was saved', lines.indexOf( fin.said( K+ 'label/finalised-on', '01.10.2026' ) ) !== -1 && lines.every( function( text ) { return text.indexOf( fin.word('/_admin/common/msg/saved').slice( 0, 8 ) ) === -1 || fin.word('/_admin/common/msg/saved') === undefined } ) );
}

console.log( '\nThe documents without lines - the rows\n' );

const TEMPLATES_PLAIN = [
	{ key : 'vertrag', name : 'Dienstleistungsvertrag', inputs : [] },
	{ key : 'nda', name : 'Geheimhaltung', inputs : [ { name : 'fall', label : 'Fall', multiline : false, required : false } ] },
];
const ACCOUNTS = [
	{ id : 'aaaaaaaaaaaa', email : 'anna@example.org', name : 'Anna Nord', active : true },
	{ id : 'bbbbbbbbbbbb', email : 'off@example.org', name : '', active : false },
	{ id : 'cccccccccccc', email : 'carl@example.org', name : '', active : true },
];
const PLAIN_LISTED = [
	{ id : 20, category : 'contract', number : '', title : 'Dienstleistungsvertrag', state : 'open', expired : false, date : '2026-10-21', gross : 0, client_id : 2, client_title : 'Ben Süd', parent_id : 9, released : true, sign_state : 'requested' },
	{ id : 21, category : 'privacy', number : '', title : 'Datenschutz <b>', state : 'signed', expired : false, date : '2026-10-22', gross : 0, client_id : 1, client_title : 'Studio Nord', parent_id : null, released : false, sign_state : 'signed' },
	{ id : 22, category : 'information', number : '', title : '', state : 'draft', expired : false, date : '2026-10-23', gross : 0, client_id : 1, client_title : 'Studio Nord', parent_id : null, released : false, sign_state : '' },
];

/** A document without lines as the server's get() has it */
function plain( category, state, over ) {
	const final = state !== 'draft';

	return doc( Object.assign( {
		id : 20, category : category, client_id : 2, parent_id : category === 'contract' ? 9 : null, number : '', title : 'Dienstleistungsvertrag', state : state, date : '2026-10-21', due_date : null, valid_until : null,
		template_key : 'vertrag', inputs : {}, notes : 'intern', net : 0, vat : 0, gross : 0, lines : [], reverse_charge : false,
		finalised : final ? '2026-10-21T09:00:00Z' : null, pdf_path : final ? '/data/crm/pdf/2026/'+ category+ '-20.pdf' : '', pdf_sha256 : final ? 'x'.repeat( 64 ) : '', pdf_url : final ? '/.crm/pdf/20' : '',
		client : { id : 2, number : 'K-0002', company : '', name : 'Ben Süd', street : '', zip : '', city : '', country : 'DE', email : '', vat_id : '' },
		parent : category === 'contract' ? { id : 9, number : 'A-2026-0003', title : '', category : 'offer', state : 'accepted' } : null, parent_number : category === 'contract' ? 'A-2026-0003' : '',
	}, over ?? {} ) );
}

/** The panel with the plain documents in its list, one of them opened */
function openedPlain( locale, document, extras, modal ) {
	const w = world( locale, modal );

	w.panel.showCurrent();
	w.answer( 200, { documents : PLAIN_LISTED.concat( LISTED ), years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	w.answer( 200, { items : OK } );
	w.find( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === String( document.id ) } ).click();
	w.answer( 200, Object.assign( view( document ), { templates : TEMPLATES_PLAIN }, extras ?? {} ) );

	return w;
}

{
	const w = world('en_US');

	w.panel.showCurrent();
	w.answer( 200, { documents : PLAIN_LISTED, years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	w.answer( 200, { items : OK } );

	const rows = w.all( w.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row !== undefined } );
	const cells = function( row ) { return w.all( row, function( el ) { return el.tagName === 'TD' } ).map( function( el ) { return el.textContent } ) };
	const badges = function( row ) { return w.all( row, function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } ).map( function( el ) { return el.textContent } ) };

	check( 'a document without lines is named by its title, with the kind, the client and the day and no amount', cells( rows[0] ).slice( 0, 5 ).join('|') === [ 'Dienstleistungsvertrag', w.word( K+ 'category/contract' ), 'Ben Süd', '21.10.2026', '' ].join('|')
		&& cells( rows[1] )[0] === 'Datenschutz <b>' && cells( rows[2] )[0] === w.word( K+ 'label/draft' ) );
	check( '...a document that is released wears the mark of it, and one that asked for a signature the state of the request', badges( rows[0] ).join() === [ w.word( K+ 'state/open' ), w.word( K+ 'label/released' ), w.word( K+ 'sign/state-requested' ) ].join()
		&& badges( rows[1] ).join() === w.word( K+ 'state/signed' ) && badges( rows[2] ).join() === w.word( K+ 'state/draft' ) );
	check( '...a document that is signed says it once: the badge of the request is for a request under way', w.all( rows[1], function( el ) { return String( el.className ).indexOf('crm-sign-state') !== -1 } ).length === 0 );
	check( '...the colours say it too: released and signed are ok, the request that waits has the badge as it is', w.all( rows[0], function( el ) { return String( el.className ).indexOf('crm-released') !== -1 } )[0].dataset.state === 'ok'
		&& w.all( rows[0], function( el ) { return String( el.className ).indexOf('crm-sign-state') !== -1 } )[0].dataset.state === undefined
		&& w.all( rows[1], function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } )[0].dataset.state === 'ok' );
	check( '...and no word is missing', w.missing.length === 0 && w.all( w.mount, function( el ) { return el.tagName === 'B' } ).length === 0 );
}

console.log( '\nThe documents without lines - the editor\n' );

{
	const w = created( 'en_US', 'contract', SMALL, TEMPLATES_PLAIN );
	const field = function( name ) { return w.input( w.mount, name ) };

	check( 'a new contract asks the templates of its kind and the offers, and nothing else', w.requests.filter( function( request ) { return request.action === 'crm-documents/templates' } ).pop().payload.category === 'contract' );
	check( '...it asks first for the offer: a choice of the finalised offers, the newest first, with their client; the client is not chosen but follows', w.all( w.select( w.mount, 'parent_id' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value+ '=' +el.textContent } ).join('|')
		=== '='+ w.word( K+ 'field/offer-choose' )+ '|9=A-2026-0003 Ben Süd 20.10.2026|7=A-2020-0001 Ben Süd 01.01.2020' && w.select( w.mount, 'client_id' ) === null );
	check( '...a title, the date and the template; no lines, no totals, no reverse charge, no due date, validity or period', field('title') !== null && field('title').value === '' && field('date') !== null && w.select( w.mount, 'template_key' ) !== null
		&& w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-lines') !== -1 } ) === null && w.find( w.mount, function( el ) { return el.id === 'crm-add-line' } ) === null
		&& [ 'due_date', 'valid_until', 'period_from', 'period_to', 'reverse_charge' ].every( function( name ) { return field( name ) === null } ) );
	check( '...it says whose client it will be until an offer is chosen, and who after', w.texts().indexOf( w.word( K+ 'field/offer-follow' ) ) !== -1 );

	w.select( w.mount, 'parent_id' ).value = '9';
	fire( w.select( w.mount, 'parent_id' ), 'change' );
	check( '...the offer chosen names the client', w.texts().indexOf( w.word( K+ 'field/client' )+ ': Ben Süd' ) !== -1 && w.texts().indexOf( w.word( K+ 'field/offer-follow' ) ) === -1 );

	w.select( w.mount, 'template_key' ).value = 'vertrag';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	check( 'the name of the template is proposed as the title while the title is empty', field('title').value === 'Dienstleistungsvertrag' );

	w.select( w.mount, 'template_key' ).value = 'nda';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	check( '...and follows the template while the person has not changed it, with its inputs', field('title').value === 'Geheimhaltung' && w.input( w.mount, 'inputs.fall' ) !== null );

	field('title').value = 'Mein Titel';
	fire( field('title'), 'input' );
	w.select( w.mount, 'template_key' ).value = 'vertrag';
	fire( w.select( w.mount, 'template_key' ), 'change' );
	check( '...but a title the person wrote is theirs', field('title').value === 'Mein Titel' );

	w.entry().save( function() {} );
	check( 'a save posts the title, the date, the template, the inputs, the notes and the offer, with the category - and neither client nor lines', w.last().action === 'crm-documents/save'
		&& Object.keys( w.last().payload ).sort().join() === 'category,date,inputs,notes,parent_id,template_key,title' && w.last().payload.category === 'contract' && w.last().payload.parent_id === '9' && w.last().payload.title === 'Mein Titel' && w.last().payload.template_key === 'vertrag' );

	w.answer( 400, { error : 'x', code : 'crm_documents_title', params : [], field : 'title' } );
	check( 'a refusal says its sentence', w.has('/_admin/error/crm_documents_title') && w.texts().indexOf( w.word('/_admin/error/crm_documents_title') ) !== -1 && w.missing.length === 0 );

	const privacy = created( 'en_US', 'privacy', SMALL, TEMPLATES_PLAIN );
	check( 'a privacy document asks for the client and not for an offer', privacy.select( privacy.mount, 'client_id' ) !== null && privacy.select( privacy.mount, 'parent_id' ) === null && privacy.input( privacy.mount, 'title' ) !== null );
	privacy.select( privacy.mount, 'client_id' ).value = '1';
	fire( privacy.select( privacy.mount, 'client_id' ), 'change' );
	privacy.input( privacy.mount, 'title' ).value = 'Datenschutz';
	fire( privacy.input( privacy.mount, 'title' ), 'input' );
	privacy.entry().save( function() {} );
	check( '...and posts the client', Object.keys( privacy.last().payload ).sort().join() === 'category,client_id,date,inputs,notes,template_key,title' && privacy.last().payload.client_id === '1' && privacy.last().payload.category === 'privacy' );

	const info = created( 'de_DE', 'information', SMALL, TEMPLATES_PLAIN );
	check( 'an information document is the same, in German too', info.select( info.mount, 'client_id' ) !== null && info.missing.length === 0 && info.find( info.mount, function( el ) { return String( el.className ).indexOf('crm-lines') !== -1 } ) === null && info.input( info.mount, 'title' ) !== null );
	info.entry().save( function() {} );
	check( '...and posts no lines either', info.last().payload.lines === undefined && info.last().payload.category === 'information' );

	const offerPage = opened( 'en_US', sealed( 'accepted', { id : 7, category : 'offer', number : 'A-2020-0001', children : [ { id : 20, number : '', title : 'Dienstleistungsvertrag', category : 'contract', state : 'open', gross : 0 } ] } ) );
	check( 'an offer names the contract that hangs on it by its title, and opens it', offerPage.button( offerPage.mount, 'Contract Dienstleistungsvertrag' ) !== null );

	const draft = openedPlain( 'en_US', plain( 'contract', 'draft', { title : 'Entwurf V', template_key : 'vertrag' } ) );
	draft.entry().save( function() {} );
	check( 'a draft that exists is posted with its id, its offer and its title, and carries the delete and the finalise buttons', draft.last().payload.id === '20' && draft.last().payload.parent_id === '9' && draft.last().payload.title === 'Entwurf V' && draft.last().payload.category === undefined
		&& draft.button( draft.mount, draft.word( K+ 'label/finalise' ) ) !== null && draft.button( draft.mount, draft.word( K+ 'label/delete' ) ) !== null );
	check( '...and links the offer it hangs on', draft.button( draft.mount, 'Offer A-2026-0003' ) !== null );
}

console.log( '\nThe documents without lines - the page, the release\n' );

{
	const w = openedPlain( 'en_US', plain( 'privacy', 'open' ), { accounts : ACCOUNTS } );
	const releaseBox = w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-release') !== -1 } );

	check( 'a finalised document without lines is its title and its kind, with the facts and neither lines nor totals', w.all( w.mount, function( el ) { return el.tagName === 'H3' } )[0].textContent.indexOf( 'Dienstleistungsvertrag - '+ w.word( K+ 'category/privacy' ) ) === 0
		&& w.find( w.mount, function( el ) { return String( el.className ).indexOf('crm-lines') !== -1 || String( el.className ).indexOf('crm-totals') !== -1 } ) === null && w.texts().indexOf( 'Ben Süd' ) !== -1 );
	check( '...the PDF to see and to save, and one step: sent', w.find( w.mount, function( el ) { return el.tagName === 'A' && el.href === '/.crm/pdf/20' } ) !== null && w.button( w.mount, w.word( K+ 'label/set-sent' ) ) !== null && w.button( w.mount, w.word( K+ 'label/set-paid' ) ) === null );
	check( 'it is not released: the page says so, with the tick for the mail on and the button that releases; no Versenden, as it is no offer or invoice', releaseBox !== null && w.texts( releaseBox ).indexOf( w.word( K+ 'release/not' ) ) !== -1
		&& w.input( releaseBox, 'release_mail' ).checked === true && w.button( releaseBox, w.word( K+ 'label/release' ) ) !== null && w.button( releaseBox, w.word( K+ 'label/send' ) ) === null );

	w.button( releaseBox, w.word( K+ 'label/release' ) ).click();
	check( 'releasing posts crm-documents/release with the id and the tick', w.last().action === 'crm-documents/release' && JSON.stringify( w.last().payload ) === '{"id":"20","mail":true}' );
	w.answer( 200, { document : plain( 'privacy', 'open', { released : true, released_at : '2026-10-24T08:00:00Z' } ), mailed : 2, mail : true } );
	check( '...the page is drawn again with the day of the release and the count of the mails, and the button that takes it back', w.texts().indexOf( 'Released on 24.10.2026' ) !== -1 && w.texts().indexOf( 'Released. Mails sent: 2' ) !== -1
		&& w.button( w.mount, w.word( K+ 'label/unrelease' ) ) !== null && w.button( w.mount, w.word( K+ 'label/release' ) ) === null );

	w.button( w.mount, w.word( K+ 'label/unrelease' ) ).click();
	check( 'taking it back posts crm-documents/unrelease with the id alone', w.last().action === 'crm-documents/unrelease' && JSON.stringify( w.last().payload ) === '{"id":"20"}' );
	w.answer( 200, { document : plain( 'privacy', 'open' ) } );
	check( '...and says so; the document is not released again', w.texts().indexOf( w.word( K+ 'release/undone' ) ) !== -1 && w.button( w.mount, w.word( K+ 'label/release' ) ) !== null );

	w.input( w.mount, 'release_mail' ).checked = false;
	w.button( w.mount, w.word( K+ 'label/release' ) ).click();
	check( 'without the tick the post says mail false', JSON.stringify( w.last().payload ) === '{"id":"20","mail":false}' );
	w.answer( 200, { document : plain( 'privacy', 'open', { released : true, released_at : '2026-10-24T08:00:00Z' } ), mailed : 0, mail : false } );
	check( '...and the page says it was released without a mail', w.texts().indexOf( w.word( K+ 'release/silent' ) ) !== -1 );

	const none = openedPlain( 'en_US', plain( 'information', 'open' ) );
	none.button( none.mount, none.word( K+ 'label/release' ) ).click();
	none.answer( 200, { document : plain( 'information', 'open', { released : true, released_at : '2026-10-24T08:00:00Z' } ), mailed : 0, mail : true } );
	check( 'a release with the tick that mailed nobody says that', none.texts().indexOf( none.word( K+ 'release/mailed-none' ) ) !== -1 );
	none.find( none.mount, function( el ) { return el.tagName === 'A' && el.className === 'nino-admin-back-link' } ).click();
	none.answer( 200, { documents : PLAIN_LISTED, years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	none.answer( 200, { items : OK } );
	none.find( none.mount, function( el ) { return el.tagName === 'TR' && el.dataset.row === '20' } ).click();
	none.answer( 200, Object.assign( view( plain( 'information', 'open', { released : true, released_at : '2026-10-24T08:00:00Z' } ) ), { templates : TEMPLATES_PLAIN } ) );
	check( '...what a release said is said once: the page opened again does not say it', none.texts().indexOf( none.word( K+ 'release/mailed-none' ) ) === -1 && none.button( none.mount, none.word( K+ 'label/unrelease' ) ) !== null );

	const failing = openedPlain( 'en_US', plain( 'information', 'open' ) );
	failing.button( failing.mount, failing.word( K+ 'label/release' ) ).click();
	failing.answer( 500, { code : 'surprise', params : [] } );
	check( 'a refusal the panel has no sentence for says the sentence of the action: release', failing.texts().some( function( text ) { return text === '(500) '+ failing.word( K+ 'error/release' ) } ) );
	const failingBack = openedPlain( 'en_US', plain( 'information', 'open', { released : true, released_at : '2026-10-24T08:00:00Z' } ) );
	failingBack.button( failingBack.mount, failingBack.word( K+ 'label/unrelease' ) ).click();
	failingBack.answer( 500, { code : 'surprise', params : [] } );
	check( '...and unrelease', failingBack.texts().some( function( text ) { return text === '(500) '+ failingBack.word( K+ 'error/unrelease' ) } ) );

	const capped = openedPlain( 'en_US', plain( 'information', 'open' ) );
	capped.button( capped.mount, capped.word( K+ 'label/release' ) ).click();
	capped.answer( 429, { error : 'x', code : 'crm_documents_limit', params : [] } );
	check( 'the cap on mails is answered in its sentence, on the page that was open', capped.has('/_admin/error/crm_documents_limit') && capped.texts().indexOf( capped.word('/_admin/error/crm_documents_limit') ) !== -1 && capped.button( capped.mount, capped.word( K+ 'label/release' ) ) !== null );

	const invoice = opened( 'en_US', sealed( 'open' ) );
	check( 'an invoice that is open has Versenden, which is the release with the mail', invoice.button( invoice.mount, invoice.word( K+ 'label/send' ) ) !== null && invoice.button( invoice.mount, invoice.word( K+ 'label/release' ) ) !== null );
	invoice.button( invoice.mount, invoice.word( K+ 'label/send' ) ).click();
	check( '...it posts release with mail true whatever the tick says', invoice.last().action === 'crm-documents/release' && JSON.stringify( invoice.last().payload ) === '{"id":"6","mail":true}' );
	invoice.answer( 200, { document : sealed( 'sent', { released : true, released_at : '2026-10-24T08:00:00Z' } ), mailed : 1, mail : true } );
	check( '...and once it is sent the button is gone', invoice.button( invoice.mount, invoice.word( K+ 'label/send' ) ) === null && invoice.texts().indexOf( 'Released. Mails sent: 1' ) !== -1 );
	const sentInvoice = opened( 'en_US', sealed( 'sent' ) );
	check( 'an invoice that is sent has no Versenden', sentInvoice.button( sentInvoice.mount, sentInvoice.word( K+ 'label/send' ) ) === null );
	const credit = opened( 'en_US', sealed( 'open', { id : 8, category : 'credit', number : 'G-2026-0001' } ) );
	check( '...a credit note that is open has it', credit.button( credit.mount, credit.word( K+ 'label/send' ) ) !== null );
	check( 'no word is missing', w.missing.length === 0 && none.missing.length === 0 && capped.missing.length === 0 && invoice.missing.length === 0 );
}

console.log( '\nFixes of the review\n' );

{
	// B1: a contract just finalised offers the signature at once: the answer is the view, with the accounts of the client
	const draft = openedPlain( 'en_US', plain( 'contract', 'draft' ), { accounts : [] } );
	draft.button( draft.mount, draft.word( K+ 'label/finalise' ) ).click();
	check( 'finalising a document without lines asks in the words of it: no number is given', draft.asked.length === 1 && draft.asked[0] === draft.word( K+ 'confirm/finalise-plain' ) && draft.has( K+ 'confirm/finalise-plain' ) );
	draft.answer( 200, Object.assign( view( plain( 'contract', 'open' ) ), { templates : TEMPLATES_PLAIN, accounts : ACCOUNTS } ) );
	check( 'a contract just finalised has the button that asks for a signature, with the accounts of the client that come with the answer', draft.button( draft.mount, draft.word( K+ 'label/ask-sign' ) ) !== null && draft.texts().indexOf( draft.word( K+ 'sign/no-account' ) ) === -1 );
	check( '...an invoice finalised is asked in the old words', ( function() { const i = opened( 'en_US', doc() ); i.button( i.mount, i.word( K+ 'label/finalise' ) ).click(); return i.asked[0] === i.word( K+ 'confirm/finalise' ) } )() );

	// NB3: the address #crm-documents/client/<id> is the filter Kunde, once
	const linked = world('en_US');
	linked.address.panel = 'crm-documents';
	linked.address.parts = [ 'client', '2' ];
	linked.panel.showCurrent();
	check( 'the address of a client is the filter Kunde of the first list', linked.last().action === 'crm-documents/list' && linked.last().payload.client === '2' );
	linked.answer( 200, { documents : LISTED, years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	linked.answer( 200, { items : OK } );
	check( '...and the select shows it', linked.select( linked.mount, 'client' ).value === '2' );
	const form = linked.find( linked.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-filters') !== -1 } );
	linked.select( form, 'client' ).value = '';
	fire( linked.select( form, 'client' ), 'change' );
	check( '...a filter that is cleared afterwards stays cleared', linked.last().payload.client === '' );
	const bad = world('en_US');
	bad.address.panel = 'crm-documents';
	bad.address.parts = [ 'client', '2; DROP' ];
	bad.panel.showCurrent();
	const other = world('en_US');
	other.address.panel = 'members';
	other.address.parts = [ 'client', '2' ];
	other.panel.showCurrent();
	check( '...an address that is not a client id, or is another panel\'s, sets nothing', bad.last().payload.client === '' && other.last().payload.client === '' );

	// The link on the card is followed while Belege is loaded: every switch to the tab asks for the list of the client of the address
	const loaded = drawn('en_US');
	loaded.address.panel = 'crm-documents';
	loaded.address.parts = [ 'client', '2' ];
	const before = loaded.requests.length;
	loaded.panel.showCurrent();
	check( 'with Belege loaded, an address of a client asks for the list again with that client', loaded.requests.length === before + 1 && loaded.last().action === 'crm-documents/list' && loaded.last().payload.client === '2' );
	loaded.answer( 200, { documents : [], years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	loaded.answer( 200, { items : OK } );
	loaded.address.parts = [ 'client', '1' ];
	loaded.panel.showCurrent();
	check( '...and another client the next time: the filter follows the address, it does not keep the first client', loaded.last().action === 'crm-documents/list' && loaded.last().payload.client === '1' && loaded.select( loaded.find( loaded.mount, function( el ) { return el.tagName === 'FORM' } ), 'client' ) !== null );
	loaded.answer( 200, { documents : LISTED, years : [ 2026 ], clients : CLIENTS, settings : SMALL, items : ITEMS, offers : OFFERS } );
	loaded.answer( 200, { items : OK } );
	const count = loaded.requests.length;
	loaded.panel.showCurrent();
	check( '...a switch to the tab with the same address asks for nothing', loaded.requests.length === count );

	const editing = opened( 'en_US', doc() );
	editing.address.panel = 'crm-documents';
	editing.address.parts = [ 'client', '2' ];
	editing.input( editing.mount, 'notes' ).value = 'noch nicht gespeichert';
	fire( editing.input( editing.mount, 'notes' ), 'input' );
	const asked = editing.requests.length;
	editing.panel.showCurrent();
	check( 'an editor that holds what is not saved stays as it is: no list is asked for', editing.requests.length === asked && editing.input( editing.mount, 'notes' ).value === 'noch nicht gespeichert' );

	// NB8: the PDF Sign shows the member is linked while the request waits, and no longer
	const waiting = openedPlain( 'en_US', plain( 'contract', 'open', { sign_id : 'abcdefabcdef', sign_state : 'requested', sign_shown_url : '/.sign/pdf?r=abcdefabcdef&kind=shown' } ), { accounts : ACCOUNTS } );
	const shown = waiting.find( waiting.mount, function( el ) { return el.tagName === 'A' && el.textContent === waiting.word( K+ 'label/sign-shown' ) } );
	check( 'while a request waits the panel links the PDF Sign shows the member', shown !== null && shown.href === '/.sign/pdf?r=abcdefabcdef&kind=shown' && shown.target === '_blank' );
	const done = openedPlain( 'en_US', plain( 'contract', 'signed', { sign_id : 'abcdefabcdef', sign_state : 'signed', signed_at : '2026-10-25T10:00:00Z', sign_shown_url : '/.sign/pdf?r=abcdefabcdef&kind=shown', sign_signed_url : '/.sign/pdf?r=abcdefabcdef&kind=signed' } ), { accounts : ACCOUNTS } );
	check( '...a signed document links the signed file and not the shown one', done.find( done.mount, function( el ) { return el.tagName === 'A' && el.textContent === done.word( K+ 'label/sign-shown' ) } ) === null && done.find( done.mount, function( el ) { return el.tagName === 'A' && el.textContent === done.word( K+ 'label/sign-pdf' ) } ) !== null );
	check( '...and no word is missing', draft.missing.length === 0 && linked.missing.length === 0 && waiting.missing.length === 0 && done.missing.length === 0 );
}

console.log( '\nThe documents without lines - the signature\n' );

{
	const w = openedPlain( 'en_US', plain( 'contract', 'open' ), { accounts : ACCOUNTS }, true );
	const box = function() { return w.find( w.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-sign') !== -1 } ) };

	check( 'a contract has a block for the signature: it says none was asked for and offers the button', box() !== null && w.texts( box() ).indexOf( w.word( K+ 'sign/none' ) ) !== -1 && w.button( box(), w.word( K+ 'label/ask-sign' ) ) !== null );
	check( '...with no link to the panel of Sign and none to a signed file while nothing was asked', w.find( box(), function( el ) { return el.tagName === 'A' } ) === null );
	check( '...a contract that is open has its step, sent', w.button( w.mount, w.word( K+ 'label/set-sent' ) ) !== null );
	const info = openedPlain( 'en_US', plain( 'information', 'open' ), { accounts : ACCOUNTS } );
	check( '...an information document has none', info.find( info.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-sign') !== -1 } ) === null && info.button( info.mount, info.word( K+ 'label/ask-sign' ) ) === null );

	w.button( box(), w.word( K+ 'label/ask-sign' ) ).click();
	const dialog = w.dialogs[0];
	const control = function( name ) { return w.find( dialog, function( el ) { return ( el.tagName === 'SELECT' || el.tagName === 'INPUT' ) && el.name === name } ) };

	check( 'the button opens a dialog with the active accounts of the client to choose from, and the days with the setting of Sign in them', w.dialogs.length === 1 && dialog.open === true && w.requests.filter( function( request ) { return request.action === 'crm-documents/sign' } ).length === 0
		&& w.all( control('sign_account'), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.value+ '='+ el.textContent } ).join('|') === 'aaaaaaaaaaaa=Anna Nord · anna@example.org|cccccccccccc=carl@example.org'
		&& control('sign_days').value === '14' && control('sign_days').min === '1' && control('sign_days').max === '90' );
	check( '...with its words', w.texts( dialog ).indexOf( w.word( K+ 'sign/title' ) ) !== -1 && w.button( dialog, w.word( K+ 'label/ask-sign' ) ) !== null && w.button( dialog, w.word( K+ 'label/cancel' ) ) !== null );

	w.button( dialog, w.word( K+ 'label/cancel' ) ).click();
	check( 'cancelling asks nothing of the server', w.requests.filter( function( request ) { return request.action === 'crm-documents/sign' } ).length === 0 && dialog.open === false );

	w.button( box(), w.word( K+ 'label/ask-sign' ) ).click();
	const second = w.dialogs[1];
	const pick = function( name ) { return w.find( second, function( el ) { return ( el.tagName === 'SELECT' || el.tagName === 'INPUT' ) && el.name === name } ) };

	pick('sign_account').value = 'cccccccccccc';
	pick('sign_days').value = '7';
	w.button( second, w.word( K+ 'label/ask-sign' ) ).click();
	check( 'asking posts crm-documents/sign with the id, the account and the days', w.last().action === 'crm-documents/sign' && JSON.stringify( w.last().payload ) === '{"id":"20","account":"cccccccccccc","days":"7"}' );

	w.answer( 200, { document : plain( 'contract', 'open', { sign_id : 'abcdefabcdef', sign_state : 'requested', sign_shown_url : '/.sign/pdf?r=abcdefabcdef&kind=shown' } ), mailed : true } );
	check( '...the page is drawn again: the state of the request, the sentence that the account was mailed, a link into the panel of Sign and no button to ask again', w.texts( box() ).indexOf( w.word( K+ 'sign/state-requested' ) ) !== -1 && w.texts( box() ).indexOf( w.word( K+ 'sign/asked' ) ) !== -1
		&& w.find( box(), function( el ) { return el.tagName === 'A' && el.href === '#sign' && el.textContent === w.word( K+ 'label/sign-panel' ) } ) !== null && w.button( box(), w.word( K+ 'label/ask-sign' ) ) === null );

	const unmailed = openedPlain( 'en_US', plain( 'privacy', 'sent' ), { accounts : ACCOUNTS }, true );
	unmailed.button( unmailed.mount, unmailed.word( K+ 'label/ask-sign' ) ).click();
	unmailed.button( unmailed.dialogs[0], unmailed.word( K+ 'label/ask-sign' ) ).click();
	check( 'the first account and the days of the setting are what is posted when nothing is changed', JSON.stringify( unmailed.last().payload ) === '{"id":"20","account":"aaaaaaaaaaaa","days":"14"}' );
	unmailed.answer( 200, { document : plain( 'privacy', 'sent', { sign_id : 'abcdefabcdef', sign_state : 'requested' } ), mailed : false } );
	check( '...a request whose mail did not go out says that', unmailed.texts().indexOf( unmailed.word( K+ 'sign/asked-unmailed' ) ) !== -1 );

	const generic = openedPlain( 'en_US', plain( 'contract', 'open' ), { accounts : ACCOUNTS }, true );
	generic.button( generic.mount, generic.word( K+ 'label/ask-sign' ) ).click();
	generic.button( generic.dialogs[0], generic.word( K+ 'label/ask-sign' ) ).click();
	generic.answer( 500, { code : 'surprise', params : [] } );
	check( 'a refusal the panel has no sentence for says the sentence of the action: sign', generic.texts().some( function( text ) { return text === '(500) '+ generic.word( K+ 'error/sign' ) } ) );

	const refused = openedPlain( 'en_US', plain( 'contract', 'open' ), { accounts : ACCOUNTS }, true );
	refused.button( refused.mount, refused.word( K+ 'label/ask-sign' ) ).click();
	refused.button( refused.dialogs[0], refused.word( K+ 'label/ask-sign' ) ).click();
	refused.answer( 409, { error : 'x', code : 'crm_documents_field', params : [], field : 'template_key' } );
	check( 'a refusal of the request says its sentence on the page, which stays', refused.has('/_admin/error/crm_documents_field') && refused.texts().indexOf( refused.word('/_admin/error/crm_documents_field') ) !== -1 && refused.button( refused.mount, refused.word( K+ 'label/ask-sign' ) ) !== null );

	const states = { viewed : 'Viewed, not signed yet', declined : 'Declined', cancelled : 'Cancelled', expired : 'Expired', changed : 'The document changed, nothing was signed' };
	const lines = Object.keys( states ).map( function( state ) {
		const s = openedPlain( 'en_US', plain( 'contract', state === 'declined' ? 'declined' : 'open', { sign_id : 'abcdefabcdef', sign_state : state } ), { accounts : ACCOUNTS } );
		const sign = s.find( s.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-sign') !== -1 } );
		return s.texts( sign ).indexOf( states[state] ) !== -1 ? s.button( sign, s.word( K+ 'label/ask-sign' ) ) !== null : 'wrong '+ state;
	} );
	check( 'a request is told in the word of its state; one that ended without a signature (cancelled, expired, changed) may be asked again, one that waits (viewed) or was declined may not', lines.join() === 'false,false,true,true,true' );

	const signed = openedPlain( 'en_US', plain( 'contract', 'signed', { sign_id : 'abcdefabcdef', sign_state : 'signed', signed_at : '2026-10-25T10:00:00Z', sign_signed_url : '/.sign/pdf?r=abcdefabcdef&kind=signed' } ), { accounts : ACCOUNTS } );
	const signBox = signed.find( signed.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-sign') !== -1 } );
	const link = signed.find( signBox, function( el ) { return el.tagName === 'A' && el.textContent === signed.word( K+ 'label/sign-pdf' ) } );
	check( 'a document that is signed says when, links the signed PDF of Sign and cannot be asked again', signed.texts( signBox ).indexOf( 'Signed on 25.10.2026' ) !== -1 && link !== null && link.href === '/.sign/pdf?r=abcdefabcdef&kind=signed' && link.target === '_blank'
		&& signed.button( signBox, signed.word( K+ 'label/ask-sign' ) ) === null && signed.button( signed.mount, signed.word( K+ 'label/set-sent' ) ) === null );

	const longer = openedPlain( 'en_US', plain( 'contract', 'open' ), { accounts : ACCOUNTS, sign_days : 21 }, true );
	longer.button( longer.mount, longer.word( K+ 'label/ask-sign' ) ).click();
	check( 'the days offered are the setting of Sign as the server says it', longer.find( longer.dialogs[0], function( el ) { return el.tagName === 'INPUT' && el.name === 'sign_days' } ).value === '21' );

	const lonely = openedPlain( 'en_US', plain( 'contract', 'open' ), { accounts : [ ACCOUNTS[1] ] } );
	const lonelyBox = lonely.find( lonely.mount, function( el ) { return String( el.className ).split(' ').indexOf('crm-sign') !== -1 } );
	check( 'a client with no active account is told to invite one, and there is no button', lonely.texts( lonelyBox ).indexOf( lonely.word( K+ 'sign/no-account' ) ) !== -1 && lonely.button( lonelyBox, lonely.word( K+ 'label/ask-sign' ) ) === null );

	const german = openedPlain( 'de_DE', plain( 'contract', 'open' ), { accounts : ACCOUNTS }, true );
	german.button( german.mount, german.word( K+ 'label/ask-sign' ) ).click();
	check( 'the German page and dialog have every word they ask for', german.missing.length === 0 && german.texts( german.dialogs[0] ).indexOf( german.word( K+ 'sign/title' ) ) !== -1 );
	check( 'no word is missing in English either', w.missing.length === 0 && unmailed.missing.length === 0 && refused.missing.length === 0 && signed.missing.length === 0 && lonely.missing.length === 0 );
}

console.log( '\nThe script itself\n' );

{
	// The harness does not parse markup, so an element made of the server's text cannot be seen above: the pin is on the
	// source - every value goes in as text, and the only markup assigned is the empty string that clears a region
	const pieces = [ OWN+ 'assets/admin.js', 'app/Crm/Documents/Money/assets/money.js', 'app/Crm/Items/Admin/assets/admin.js' ].map( function( file ) { return fs.readFileSync( path.join( root, file ), 'utf8' ) } );

	check( 'the scripts assign no markup: innerHTML only to clear, no outerHTML, no insertAdjacentHTML, no document.write, no eval', pieces.every( function( source ) {
		return source.match( /innerHTML\s*=(?!\s*'';)/g ) === null && /outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/.test( source ) === false;
	} ) );
}

console.log( '\nThe German words\n' );

{
	const w = drawn('de_DE');
	check( 'the German screen has every word it asks for', w.missing.length === 0 && w.texts().indexOf( w.word( K+ 'state/expired' ) ) !== -1 );

	const editor = opened( 'de_DE', doc() );
	const final = opened( 'de_DE', sealed( 'sent' ) );
	check( 'so have the editor and the page of a finalised document', editor.missing.length === 0 && final.missing.length === 0 );

	const settings = drawn('de_DE');
	settings.button( settings.mount, settings.word( K+ 'label/settings' ) ).click();
	settings.answer( 200, { settings : SMALL } );
	check( 'and the settings', settings.missing.length === 0 );
}

done();

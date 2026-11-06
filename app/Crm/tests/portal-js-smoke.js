/**
 *	Crm
 *	portal-js-smoke.js		What the script of the portal does with the list of documents and the messages of the owner (Portal/assets/portal.js) over the
 *												stand-in of the kernel's tests/harness.js: the pills over the list are shown and filter the rows (hidden, aria-pressed, the
 *												sentence over the list that says how many are shown, the pill the server pressed applied at once, a pill of kinds that takes
 *												two kinds), a page without the portal is left alone, a row that is hidden is not unfolded by an anchor, and the form on the card
 *												of a message is posted in the background: the card goes when the server answered, stays when the answer is no page, says the
 *												token ran out or never comes, and a form that posts to another site is left to the browser. Only the browser is a stand-in.
 *
 *	Usage: node app/Crm/tests/portal-js-smoke.js
 */

'use strict';

/* global require, __dirname, process, setImmediate */

const path = require('path');
const vm = require('vm');

const root = require('fs').realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, load, element, documentOf, fire, findAll } = require( path.join( root, 'tests/harness.js' ) );

const SCRIPT = 'app/Crm/Portal/assets/portal.js';
const ORIGIN = 'https://example.org';

/**
 *	An element with a class, attributes and children
 *
 *	@param		{string}		tag
 *	@param		{string}		className
 *	@param		{Object}		[attributes]
 *	@param		{Object[]}	[children]
 *
 *	@return		{Object}
 */
function make( tag, className, attributes, children ) {
	const el = element( tag );
	el.className = className;
	Object.keys( attributes || {} ).forEach( function( name ) { el.setAttribute( name, attributes[name] ); } );
	( children || [] ).forEach( function( child ) { el.appendChild( child ); } );
	return el;
}

/**
 *	A page of the portal: the list of documents with its pills, and two messages with their forms
 *
 *	@param		{Object}	options				pressed: the pill the server pressed; portal: whether the page is a page of the portal; action: where the forms post to
 *
 *	@return		{Object}
 */
function page( options ) {
	const kinds = [ [ 1, 'invoice', '1' ], [ 2, 'offer', '1' ], [ 3, 'information', '' ], [ 4, 'credit', '' ], [ 5, 'contract', '' ] ];
	const rows = kinds.map( function( kind ) {
		const attributes = { 'data-document' : String( kind[0] ), 'data-kind' : kind[1] };
		if( kind[2] === '1' )
			attributes['data-due'] = '1';
		const details = make( 'details', 'crm-documents__fold' );
		details.open = ( options.open || [] ).indexOf( kind[0] ) !== -1;
		const row = make( 'li', 'crm-documents__row crm-table__row', attributes, [ details ] );
		row.id = 'beleg-'+ kind[0];
		row.details = details;
		return row;
	} );
	const pillSpecs = [ [ 'all', null ], [ 'due', null ], [ 'offer', 'offer' ], [ 'invoice', 'invoice credit' ], [ 'information', 'information' ] ];
	const pills = pillSpecs.map( function( spec ) {
		const attributes = { type : 'button', 'data-filter' : spec[0], 'aria-pressed' : spec[0] === ( options.pressed || 'all' ) ? 'true' : 'false' };
		if( spec[1] !== null )
			attributes['data-kinds'] = spec[1];
		return make( 'button', 'crm-filter__pill', attributes );
	} );
	const group = make( 'div', 'crm-filter', { role : 'group' }, pills );
	group.hidden = true;
	group.setAttribute( 'hidden', '' );
	const count = make( 'p', 'crm-documents__count', { 'aria-live' : 'polite', 'data-shown' : 'Es werden {shown} von {total} Belegen gezeigt.' } );
	count.textContent = '5 Belege';
	const list = make( 'ul', 'crm-documents__list', {}, rows );
	const forms = [ 'a', 'b' ].map( function( sum ) {
		const form = make( 'form', 'crm-message__dismiss', { method : 'post' }, [
			make( 'input', '', { type : 'hidden', name : '_csrf' } ), make( 'input', '', { type : 'hidden', name : 'sha256' } ), make( 'input', '', { type : 'hidden', name : 'weiter' } ),
			make( 'button', 'crm-message__close', { type : 'submit' } ),
		] );
		form.action = options.action || ORIGIN+ '/.crm/nachricht/weg';
		form.children[0].value = 'token';
		form.children[1].value = sum.repeat( 64 );
		form.children[2].value = '/portal/belege?art=offer';
		form.card = make( 'div', 'crm-message', {}, [ form ] );
		return form;
	} );
	const title = make( 'h2', 'crm-head__title' );
	const children = [ title ].concat( forms.map( function( form ) { return form.card; } ), [ group, count, list ] );
	if( options.portal !== false )
		children.unshift( make( 'div', 'crm-portal' ) );
	const mount = make( 'div', 'root', {}, children );
	findAll( mount, function() { return true; } ).concat( [ mount ] ).forEach( function( el ) {
		Object.defineProperty( el, 'parentElement', { get : function() { return el.parentNode; } } );
	} );
	return { mount : mount, rows : rows, pills : pills, group : group, count : count, forms : forms, title : title, list : list };
}

/**
 *	Run the script over a page the way a browser does
 *
 *	@param		{Object}		model				From page()
 *	@param		{Object}		[over]			hash and fetch of the world
 *
 *	@return		{Object}									The calls the script made
 */
function run( model, over ) {
	const calls = { scrolled : [], fetches : [] };
	const doc = Object.assign( documentOf( model.mount ), { readyState : 'complete', addEventListener : function() {} } );
	const parsed = new URL( ORIGIN+ '/portal/belege' );
	const win = { addEventListener : function() {}, requestAnimationFrame : function( fn ) { fn(); } };
	const context = vm.createContext( {
		window : win, document : doc, console : console, URL : URL, URLSearchParams : URLSearchParams, Promise : Promise,
		location : { search : '', hash : ( over && over.hash ) || '', pathname : parsed.pathname, href : parsed.href, origin : parsed.origin },
		history : { state : null, replaceState : function() {} },
		fetch : over && over.fetch !== undefined ? over.fetch : function( url, options ) {
			calls.fetches.push( { url : url, options : options } );
			return Promise.resolve( { ok : true, url : ORIGIN+ '/portal/belege' } );
		},
	} );
	model.rows.forEach( function( row ) {
		row.scrollIntoView = function() { calls.scrolled.push( row.id ); };
	} );
	load( SCRIPT, context );
	calls.doc = doc;
	return calls;
}

/**
 *	Let the promises of a post run
 *
 *	@return		{Promise}
 */
function settle() {
	return new Promise( function( resolve ) { setImmediate( resolve ); } );
}

/**
 *	The rows that are shown
 *
 *	@param		{Object}		model
 *
 *	@return		{number[]}
 */
function shown( model ) {
	return model.rows.filter( function( row ) { return row.hidden !== true; } ).map( function( row ) { return Number( row.getAttribute( 'data-document' ) ); } );
}

/**
 *	The pills that are pressed
 *
 *	@param		{Object}		model
 *
 *	@return		{string[]}
 */
function pressed( model ) {
	return model.pills.filter( function( pill ) { return pill.getAttribute( 'aria-pressed' ) === 'true'; } ).map( function( pill ) { return pill.getAttribute( 'data-filter' ); } );
}

/**
 *	A click on a pill
 *
 *	@param		{Object}		model
 *	@param		{string}		name
 *
 *	@return		{void}
 */
function press( model, name ) {
	fire( model.pills.find( function( pill ) { return pill.getAttribute( 'data-filter' ) === name; } ), 'click' );
}

/**
 *	A submit of a form with the spy that says whether the browser's own submit was stopped
 *
 *	@param		{Object}		form
 *
 *	@return		{boolean}									Whether the script stopped the browser's own submit
 */
function submit( form ) {
	let stopped = false;
	fire( form, 'submit', { preventDefault : function() { stopped = true; } } );
	return stopped;
}

/**
 *	The suite
 *
 *	@return		{Promise}
 */
async function suite() {

	console.log( '\nThe pills' );

	let model = page( {} );
	check( 'without the script the pills are hidden and every row is shown', model.group.hidden === true && shown( model ).length === 5 );
	run( model );
	check( 'the script shows the pills; all is pressed and every row stays, and the sentence over the list is as the server wrote it', model.group.hidden === false && pressed( model ).join() === 'all' && shown( model ).join() === '1,2,3,4,5' && model.count.textContent === '5 Belege' );
	press( model, 'offer' );
	check( 'a pill of a kind shows the rows of that kind and hides the others (hidden), presses itself and lets go of the one before', shown( model ).join() === '2' && pressed( model ).join() === 'offer' && model.rows[0].hidden === true );
	check( '...the sentence over the list says how many are shown of how many', model.count.textContent === 'Es werden 1 von 5 Belegen gezeigt.' );
	press( model, 'invoice' );
	check( 'a pill of two kinds shows both: an invoice and a credit note', shown( model ).join() === '1,4' && pressed( model ).join() === 'invoice' && model.count.textContent === 'Es werden 2 von 5 Belegen gezeigt.' );
	press( model, 'due' );
	check( 'the pill of what asks for something shows the rows with data-due', shown( model ).join() === '1,2' && pressed( model ).join() === 'due' );
	press( model, 'information' );
	press( model, 'all' );
	check( 'all shows every row again, and the sentence is the one of the server', shown( model ).join() === '1,2,3,4,5' && pressed( model ).join() === 'all' && model.count.textContent === '5 Belege' );
	model = page( { pressed : 'offer' } );
	run( model );
	check( 'the pill the server pressed applies at once: its rows are shown and the rest hidden before a click', shown( model ).join() === '2' && pressed( model ).join() === 'offer' && model.count.textContent === 'Es werden 1 von 5 Belegen gezeigt.' );
	model = page( { portal : false } );
	run( model );
	check( 'a page that is no page of the portal is left alone: the pills stay hidden, no row is hidden, the forms are not taken over', model.group.hidden === true && shown( model ).length === 5 && model.forms[0].listeners.submit === undefined );

	model = page( { pressed : 'offer', open : [ 1, 2 ] } );
	run( model );
	check( 'a row the filter hides is folded, the one it shows keeps its fold: the server opened the row the address names, and it does not stand open when All shows it', model.rows[0].details.open === false && model.rows[1].details.open === true );
	press( model, 'all' );
	check( '...so All shows the row folded', model.rows[0].hidden === false && model.rows[0].details.open === false );

	console.log( '\nAn anchor' );

	model = page( { pressed : 'offer' } );
	let calls = run( model, { hash : '#beleg-1' } );
	check( 'a row that is hidden is not unfolded and not scrolled to, whatever the anchor says', model.rows[0].hidden === true && model.rows[0].details.open !== true && calls.scrolled.length === 0 );
	model = page( { pressed : 'offer' } );
	calls = run( model, { hash : '#beleg-2' } );
	check( '...a row that is shown is unfolded and scrolled to', model.rows[1].hidden !== true && model.rows[1].details.open === true && calls.scrolled.join() === 'beleg-2' );

	console.log( '\nThe message that is put away' );

	model = page( {} );
	calls = run( model );
	const stopped = submit( model.forms[0] );
	const sent = calls.fetches[0];
	check( 'the form of a card is posted in the background: the browser\'s own submit is stopped, the post goes to the action of the form with the cookies of the site, and its body is the fields of the form', stopped === true && calls.fetches.length === 1 && sent.url === ORIGIN+ '/.crm/nachricht/weg'
		&& sent.options.method === 'POST' && sent.options.credentials === 'same-origin' && sent.options.body.get( '_csrf' ) === 'token' && sent.options.body.get( 'sha256' ) === 'a'.repeat( 64 ) && sent.options.body.get( 'weiter' ) === '/portal/belege?art=offer' && Array.from( sent.options.body.keys() ).join() === '_csrf,sha256,weiter' );
	check( '...the button waits meanwhile', model.forms[0].children[3].disabled === true && model.forms[0].card.isConnected === true );
	await settle();
	check( '...and when the server answered the card is gone from the page, the other card stays, and the focus goes to the title of the page', model.forms[0].card.isConnected === false && model.forms[1].card.isConnected === true && model.title.focused === true );

	const outcomes = {
		'an answer that is no page (404)' : function() { return Promise.resolve( { ok : false, url : ORIGIN+ '/.crm/nachricht/weg' } ); },
		'an answer that says the token ran out' : function() { return Promise.resolve( { ok : true, url : ORIGIN+ '/portal/belege?state=expired' } ); },
		'no answer at all' : function() { return Promise.reject( new Error( 'offline' ) ); },
	};

	for( const name of Object.keys( outcomes ) ) {
		model = page( {} );
		run( model, { fetch : outcomes[name] } );
		submit( model.forms[0] );
		await settle();
		check( 'the card stays and the button is free again on '+ name, model.forms[0].card.isConnected === true && model.forms[0].children[3].disabled === false );
	}

	outcomes['an answer that comes from another site'] = function() { return Promise.resolve( { ok : true, url : 'https://evil.example/portal/belege' } ); };
	model = page( {} );
	run( model, { fetch : outcomes['an answer that comes from another site'] } );
	submit( model.forms[0] );
	await settle();
	check( 'the card stays and the button is free again on an answer that comes from another site', model.forms[0].card.isConnected === true && model.forms[0].children[3].disabled === false );

	model = page( { action : 'https://evil.example/.crm/nachricht/weg' } );
	calls = run( model );
	check( 'a form that posts to another site is left to the browser: no stop, no post', submit( model.forms[0] ) === false && calls.fetches.length === 0 && model.forms[0].card.isConnected === true );
	model = page( {} );
	calls = run( model, { fetch : null } );
	check( 'where the browser has no fetch it posts the form itself: no stop', submit( model.forms[0] ) === false && model.forms[0].card.isConnected === true );

	done();
}

suite();

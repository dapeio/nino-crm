/**
 *	Crm
 *	portal-js-smoke.js		What the script of the portal does with the list of documents and the messages of the owner (Portal/assets/portal.js) over the
 *												stand-in of the kernel's tests/harness.js: the pills over the list are shown and filter the rows (hidden, aria-pressed, the
 *												sentence over the list that says how many are shown, the pill the server pressed applied at once, a pill of kinds that takes
 *												two kinds), a page without the portal is left alone, a row that is hidden is not unfolded by an anchor, and the form on the card
 *												of a message is posted in the background: the card goes when the server answered, stays when the answer is no page, says the
 *												token ran out or never comes, and a form that posts to another site is left to the browser. The player of a project page without the
 *												page loading again (a click on Anhören, the choice of a version and the position that goes with it, an answer that is not the route's)
 *												is the last part. Only the browser is a stand-in.
 *
 *	Usage: node app/Crm/tests/portal-js-smoke.js
 */

'use strict';

/* global require, __dirname, process, setImmediate */

const path = require('path');
const vm = require('vm');

const root = require('fs').realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, source, load, element, documentOf, fire, findAll } = require( path.join( root, 'tests/harness.js' ) );

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
 *	A page of a project with its row of tabs: four tabs of 100px in a box of 358px that is scrolled to the left, the open one the last
 *
 *	@param		{number}		open				The index of the open tab
 *	@param		{number}		[scrolled]	The scroll of the box at the start
 *
 *	@return		{Object}
 */
function tabsPage( open, scrolled ) {
	const tabs = [ 0, 1, 2, 3 ].map( function( index ) { return make( 'a', 'crm-project__tab nino-tabs-tab'+ ( index === open ? ' nino-is-active' : '' ), { 'data-tab' : String( index ) } ); } );
	const nav = make( 'div', 'crm-project__nav nino-tabs-nav', {}, tabs );
	nav.scrollLeft = scrolled || 0;
	nav.getBoundingClientRect = function() { return { left : 16, right : 374, width : 358 }; };
	tabs.forEach( function( tab, index ) {
		tab.getBoundingClientRect = function() { return { left : 16 + index * 100 - nav.scrollLeft, right : 116 + index * 100 - nav.scrollLeft, width : 100 }; };
	} );
	const mount = make( 'div', 'root', {}, [ make( 'div', 'crm-portal' ), nav ] );
	findAll( mount, function() { return true; } ).concat( [ mount ] ).forEach( function( el ) {
		Object.defineProperty( el, 'parentElement', { get : function() { return el.parentNode; } } );
	} );
	return { mount : mount, rows : [], nav : nav, tabs : tabs };
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

	// The row of tabs of a project page, wider than the box: the open tab is brought into the box by the box's own scroll
	model = tabsPage( 3 );
	run( model );
	check( 'a row of tabs wider than its box is scrolled at the start so that the open tab lies in it: the last tab ends at the right edge of the box, and nothing else is scrolled', model.nav.scrollLeft === 42 && model.tabs[3].getBoundingClientRect().right === 374 && model.tabs[3].getBoundingClientRect().left >= 16 );
	model = tabsPage( 0 );
	run( model );
	check( '...the first tab stands where it is: no scroll', model.nav.scrollLeft === 0 );
	model = tabsPage( 0, 60 );
	run( model );
	check( '...an open tab that lies left of the box is scrolled back into it', model.nav.scrollLeft === 0 );

	await playerSuite();

	done();
}

/**
 *	The player without the page loading: Anhören, the choice of a version and the position that goes with it
 *
 *	@return		{Promise}
 */
async function playerSuite() {
const SCRIPT = 'app/Crm/Portal/assets/portal.js';
const ORIGIN = 'https://crm.example';

// A refused play is a promise that is rejected: one nobody handles ends the process on its own, so it is counted and pinned at the end
let unhandled = 0;
process.on( 'unhandledRejection', function() { unhandled++ } );

/**
 *	Let the promises of the script run
 *
 *	@return		{Promise}
 */
async function settle() {
	for( let turn = 0; turn < 8; turn++ )
		await new Promise( function( resolve ) { setImmediate( resolve ) } );
}

/**
 *	An element with a class and attributes
 *
 *	@param		{string}	tag
 *	@param		{string}	className
 *	@param		{Object}	[attributes]
 *
 *	@return		{Object}
 */
function make( tag, className, attributes ) {

	const el = element( tag );
	el.className = className;
	Object.keys( attributes || {} ).forEach( function( name ) { el.setAttribute( name, attributes[name] ) } );

	return el;
}

/**
 *	The player of a file as the route draws it: the select of the versions of its title over the medium
 *
 *	@param		{string}		file
 *	@param		{string[]}	versions			The ids of the versions, the newest first; none for a title of one version
 *	@param		{Object}		[over]				{ duration, readyState, paused, currentTime, rejects }
 *
 *	@return		{Object}
 */
function player( file, versions, over ) {

	over = Object.assign( { duration : 100, readyState : 0, paused : true, currentTime : 0, rejects : false }, over );

	const box = make( 'div', 'review-player', { 'data-review-file' : file } );
	const media = element('audio');
	const state = { plays : 0 };

	media.currentTime = over.currentTime;
	media.duration = over.duration;
	media.readyState = over.readyState;
	media.paused = over.paused;
	media.play = function() {
		state.plays++;
		media.paused = false;
		return over.rejects === true ? Promise.reject( new Error('not allowed') ) : Promise.resolve();
	};

	const result = { box : box, media : media, state : state, form : null, select : null, go : null };

	if( versions.length > 1 ) {

		const form = make( 'form', 'crm-versions', { id : 'crm-versions' } );
		const select = make( 'select', 'crm-versions__select' );
		const go = make( 'button', 'crm-versions__go' );

		select.options = versions.map( function( id ) {
			const option = element('option');
			option.value = id;
			option.selected = id === file;
			option.defaultSelected = id === file;
			select.appendChild( option );
			return option;
		} );
		select.selectedIndex = versions.indexOf( file );
		form.submitted = 0;
		form.submit = function() { form.submitted++ };
		form.requestSubmit = function() { fire( form, 'submit', { preventDefault : function() { form.prevented = true } } ) };
		form.appendChild( select );
		form.appendChild( go );
		box.appendChild( form );
		Object.assign( result, { form : form, select : select, go : go } );
	}

	box.appendChild( media );

	return result;
}

/**
 *	The page of a project in the tab Dateien and portal.js over it: the column of the player (empty, or with a player), the list with a row for a title of three
 *	versions and one for a file of its own, the template of the pill, and the window of a browser whose requests the test answers
 *
 *	@param		{Object}	[options]		{ playing, fetch, domparser, narrow, search }
 *
 *	@return		{Object}
 */
function site( options ) {

	options = Object.assign( { playing : null, fetch : true, domparser : true, narrow : false, search : '?projekt=00032', hash : '' }, options );

	const shell = make( 'div', 'crm-portal' );
	const layout = make( 'div', 'crm-files-layout' );
	const aside = make( 'aside', 'crm-player'+ ( options.playing === null ? ' crm-player--empty' : '' ), { 'data-crm-player' : '/.crm/player' } );
	const list = make( 'ul', 'crm-files__list' );
	const rows = {};
	const calls = { scrolled : [], assigned : [], replaced : [], reviewInit : [], plays : [] };

	aside.replaceChildren = function() {
		aside.children.forEach( function( node ) { node.parentNode = null } );
		aside.children = Array.from( arguments );
		aside.children.forEach( function( node ) { node.parentNode = aside } );
	};
	aside.scrollIntoView = function( how ) { calls.scrolled.push( how ) };

	// The pill the template carries: what the page wrote, cloned and not made
	const template = make( 'template', '', { id : 'crm-files-playing' } );
	template.id = 'crm-files-playing';
	template.content = { cloneNode : function() { return make( 'span', 'crm-files__playing crm-pill' ) } };

	/**
	 *	A row of the list: its text with the name, the pill that says how many comments are open where there are some, and the links
	 */
	const row = function( id, title, review, open ) {

		const li = make( 'li', 'crm-files__row', { 'data-files-id' : id } );
		const text = make( 'span', 'crm-files__text' );
		const name = make( 'a', 'crm-files__name' );
		const listen = make( 'a', 'crm-files__listen crm-btn' );
		const save = make( 'a', 'crm-files__download crm-btn' );

		name.href = ORIGIN+ '/portal/projekte?projekt=00032&review='+ review+ '#projekt-files';
		listen.href = name.href;
		save.href = ORIGIN+ '/.files/'+ id+ '?download=1';
		text.appendChild( name );

		if( open === true )
			text.appendChild( make( 'span', 'crm-files__open crm-pill' ) );

		li.appendChild( text );
		li.appendChild( listen );
		li.appendChild( save );
		list.appendChild( li );
		rows[id] = { li : li, text : text, name : name, listen : listen, save : save };

		return rows[id];
	};

	// The title of three versions has the row of the newest, with the pill of its open comments; the other file is a title of its own
	row( 'ccccccccccc3', 'Lied', 'ccccccccccc3', true );
	row( 'dddddddddddd', 'Film', 'dddddddddddd', false );

	layout.appendChild( aside );
	layout.appendChild( list );
	layout.appendChild( template );
	shell.appendChild( layout );

	const world = { fragments : {}, answer : null, requests : [], fails : false };

	// What the route answers for a file: the markup is a key to the tree the parser stand-in hands back
	const fragment = function( file, versions, over ) {
		const made = player( file, versions, over );
		world.fragments[ 'markup-'+ file ] = made;
		return made;
	};

	const response = function( file, over ) {
		return Object.assign( {
			status : 200,
			url : ORIGIN+ '/.crm/player?file='+ file,
			headers : { get : function( name ) { return name.toLowerCase() === 'content-type' ? 'text/html; charset=utf-8' : null } },
			text : function() { return Promise.resolve( 'markup-'+ file ) },
		}, over );
	};

	const fetchStandIn = function( url, init ) {
		world.requests.push( { url : url, init : init } );

		if( world.fails === true )
			return Promise.reject( new Error('offline') );

		const file = decodeURIComponent( String( url ).split('file=')[1] );

		return Promise.resolve( world.answer !== null ? world.answer( file ) : response( file ) );
	};

	const DOMParserStandIn = function() {};
	DOMParserStandIn.prototype.parseFromString = function( html ) {
		const made = world.fragments[html];
		const wrapper = element('div');
		if( made !== undefined )
			wrapper.appendChild( made.box );
		return { body : { childNodes : wrapper.children.slice() }, querySelector : function( selector ) { return wrapper.querySelector( selector ) }, querySelectorAll : function( selector ) { return wrapper.querySelectorAll( selector ) } };
	};

	// A document whose events the test fires: the click of the page is caught on it
	const page = {
		listeners : {},
		readyState : 'complete',
		addEventListener : function( name, fn ) { ( page.listeners[name] = page.listeners[name] || [] ).push( fn ) },
		querySelector : function( selector ) { return page.querySelectorAll( selector )[0] ?? null },
		querySelectorAll : function( selector ) { return shell.matches === undefined ? [].concat( selector === '.crm-portal' ? [ shell ] : [], shell.querySelectorAll( selector ) ) : [] },
		getElementById : function( id ) { return id === 'crm-files-playing' ? template : null },
		importNode : function( node ) { return node },
		createElement : element,
	};

	const location = {
		origin : ORIGIN,
		pathname : '/portal/projekte',
		search : options.search,
		hash : options.hash,
		get href() { return ORIGIN+ this.pathname+ this.search+ this.hash },
		assign : function( url ) { calls.assigned.push( url ) },
	};
	const history = { state : null, replaceState : function( state, title, url ) { calls.replaced.push( url ) } };

	const Nino = { review : { init : function( root ) { calls.reviewInit.push( root ) } } };
	const window = { Nino : Nino, addEventListener : function() {}, requestAnimationFrame : function( fn ) { fn() } };

	if( options.fetch === true )
		window.fetch = fetchStandIn;

	if( options.domparser === true )
		window.DOMParser = DOMParserStandIn;

	window.matchMedia = function() { return { matches : options.narrow } };

	let old = null;

	if( options.playing !== null ) {
		old = player( options.playing.file, options.playing.versions, options.playing.over );
		aside.appendChild( old.box );
	}

	const context = vm.createContext( { console : console, document : page, window : window, location : location, history : history, URL : URL, URLSearchParams : URLSearchParams, Nino : Nino } );

	load( SCRIPT, context, 'portal.js' );

	return {
		aside : aside, rows : rows, calls : calls, world : world, old : old, location : location, window : window, fragment : fragment, response : response, template : template,
		click : function( target, over ) {
			const result = { prevented : false };
			fire( page, 'click', Object.assign( { target : target, button : 0, preventDefault : function() { result.prevented = true } }, over ) );
			return result;
		},
		playerNow : function() { return aside.children[0] ?? null },
	};
}

/**
 *	Whether a row is marked as the one that plays: the class, aria-current and the pill
 *
 *	@param		{Object}	row
 *
 *	@return		{boolean}
 */
function marked( row ) {
	return row.li.className.split(' ').indexOf('crm-files__row--playing') !== -1 && row.li.getAttribute('aria-current') === 'true' && row.text.querySelector('.crm-files__playing') !== null;
}

/**
 *	@param		{Object}	row
 *
 *	@return		{boolean}
 */
function unmarked( row ) {
	return row.li.className.split(' ').indexOf('crm-files__row--playing') === -1 && row.li.getAttribute('aria-current') === null && row.text.querySelector('.crm-files__playing') === null;
}


	// --- Anhören -----------------------------------------------------------------------------------------------

	console.log( '\nportal.js - the player of a project page without the page loading again\n' );

	{
		const s = site();
		const made = s.fragment( 'dddddddddddd', [] );
		const click = s.click( s.rows['dddddddddddd'].listen );

		check( 'a click on Anhören is caught before the browser follows the link, and asks the route that the column of the player names for that file - from the same origin, as html', click.prevented === true && s.world.requests.length === 1
			&& s.world.requests[0].url === '/.crm/player?file=dddddddddddd' && s.world.requests[0].init.credentials === 'same-origin' && s.world.requests[0].init.headers.Accept === 'text/html' );
		await settle();
		check( '...the answer is put in the column as the nodes it parses to, in place of what stood there, and the column is no longer the empty card', s.aside.children.length === 1 && s.aside.children[0] === made.box && s.aside.className.split(' ').indexOf('crm-player--empty') === -1 && s.aside.className.split(' ').indexOf('crm-player') !== -1 );
		check( '...Review is told to take the column over - the new player only - and the page was not left', s.calls.reviewInit.length === 1 && s.calls.reviewInit[0] === s.aside && s.calls.assigned.length === 0 );
		check( '...the row of the file is marked as the one that plays - the class, aria-current and the pill, which is cloned from the template of the page - and the other is not', marked( s.rows['dddddddddddd'] ) && unmarked( s.rows['ccccccccccc3'] ) );
		check( '...the address follows: the file and the tab in the query, the project kept, no anchor - and written over the entry, not added to the history', s.calls.replaced.length === 1 && s.calls.replaced[0] === '/portal/projekte?projekt=00032&review=dddddddddddd&tab=dateien' );
		check( '...on a wide screen the page is not scrolled', s.calls.scrolled.length === 0 );

		const second = s.fragment( 'ccccccccccc3', [ 'ccccccccccc3', 'ccccccccccc2', 'ccccccccccc1' ] );
		s.click( s.rows['ccccccccccc3'].name );
		await settle();
		check( 'the name of a file that is played does the same, and a title of several versions marks its row whatever version plays - the select of the answer names them -, the row that played before loses its mark', s.aside.children[0] === second.box && marked( s.rows['ccccccccccc3'] ) && unmarked( s.rows['dddddddddddd'] ) && s.rows['dddddddddddd'].text.querySelector('.crm-files__playing') === null );
		check( '...the pill of a title with open comments stands before the pill of them, as the server draws it', s.rows['ccccccccccc3'].text.children.indexOf( s.rows['ccccccccccc3'].text.querySelector('.crm-files__playing') ) < s.rows['ccccccccccc3'].text.children.indexOf( s.rows['ccccccccccc3'].text.querySelector('.crm-files__open') ) );

		const stay = s.fragment( 'ccccccccccc2', [ 'ccccccccccc3', 'ccccccccccc2', 'ccccccccccc1' ] );
		s.old = null;
		s.click( s.rows['ccccccccccc3'].listen );
		await settle();
		check( 'the pill is not added twice to a row that plays already', stay !== null && s.rows['ccccccccccc3'].text.children.filter( function( el ) { return el.className.indexOf('crm-files__playing') !== -1 } ).length === 1 );
	}

	{
		const s = site();
		s.fragment( 'dddddddddddd', [] );
		const keys = [ { ctrlKey : true }, { metaKey : true }, { shiftKey : true }, { altKey : true }, { button : 1 }, { button : 2 } ].map( function( over ) { return s.click( s.rows['dddddddddddd'].listen, over ).prevented } );
		check( 'a click with a key held (to open the link elsewhere) or with another button than the first is the browser\'s: nothing is caught, nothing asked', keys.every( function( kept ) { return kept === false } ) && s.world.requests.length === 0 );

		const save = s.click( s.rows['dddddddddddd'].save );
		const odd = make( 'a', 'crm-files__listen' );
		odd.href = '/portal/projekte?projekt=00032&review=..%2Fx';
		const longer = make( 'a', 'crm-files__listen' );
		longer.href = '/portal/projekte?projekt=00032&review=dddddddddddd0';
		const none = make( 'a', 'crm-files__listen' );
		none.href = '/portal/projekte?projekt=00032';
		const other = make( 'a', 'crm-files__listen' );
		other.href = '/portal/andere?review=dddddddddddd';
		const away = make( 'a', 'crm-files__listen' );
		away.href = 'https://elsewhere.example/portal/projekte?review=dddddddddddd';
		const list = [ odd, longer, none, other, away ].map( function( link ) { return s.click( link ).prevented } );
		check( 'the link to the download, a link without a file or with an id that is none, one to another page and one to another site are no way to the player: left to the browser, nothing asked', save.prevented === false && list.every( function( kept ) { return kept === false } ) && s.world.requests.length === 0 );
		check( 'a click that something else has prevented already is not taken', s.click( s.rows['dddddddddddd'].listen, { defaultPrevented : true } ).prevented === false && s.world.requests.length === 0 );
		check( 'a click on something that is no link is nothing to the script', s.click( make( 'div', 'x' ) ).prevented === false && s.world.requests.length === 0 );
	}

	{
		const refusals = {
			'a status that is not 200' : function( s ) { s.world.answer = function( file ) { return s.response( file, { status : 404 } ) } },
			'an answer from another origin' : function( s ) { s.world.answer = function( file ) { return s.response( file, { url : 'https://evil.example/.crm/player?file='+ file } ) } },
			'an answer from another path (a redirect to the door, say)' : function( s ) { s.world.answer = function( file ) { return s.response( file, { url : ORIGIN+ '/portal/anmelden' } ) } },
			'an answer that is no html' : function( s ) { s.world.answer = function( file ) { return s.response( file, { headers : { get : function() { return 'text/plain; charset=utf-8' } } } ) } },
			'an answer without a content type' : function( s ) { s.world.answer = function( file ) { return s.response( file, { headers : { get : function() { return null } } } ) } },
			'an answer that holds no player' : function( s ) { s.world.answer = function( file ) { return s.response( file, { text : function() { return Promise.resolve( 'markup-none' ) } } ) } },
			'a network that fails' : function( s ) { s.world.fails = true },
			'a body that cannot be read' : function( s ) { s.world.answer = function( file ) { return s.response( file, { text : function() { return Promise.reject( new Error('cut off') ) } } ) } },
		};

		for( const name of Object.keys( refusals ) ) {

			const s = site();
			const kept = make( 'p', 'kept' );
			s.aside.appendChild( kept );
			s.fragment( 'dddddddddddd', [] );
			refusals[name]( s );
			s.click( s.rows['dddddddddddd'].listen );
			await settle();

			check( name+ ': nothing is put in the column, no row is marked, the address stays - and the browser follows the link', s.aside.children.length === 1 && s.aside.children[0] === kept && unmarked( s.rows['dddddddddddd'] ) && s.calls.replaced.length === 0 && s.calls.reviewInit.length === 0
				&& s.calls.assigned.length === 1 && s.calls.assigned[0] === ORIGIN+ '/portal/projekte?projekt=00032&review=dddddddddddd#projekt-files' );
		}
	}

	{
		const s = site( { narrow : true } );
		s.fragment( 'dddddddddddd', [] );
		s.click( s.rows['dddddddddddd'].listen );
		await settle();
		check( 'on a screen under 1024 px the page is scrolled to the player, to its start', s.calls.scrolled.length === 1 && s.calls.scrolled[0].block === 'start' );

		const failing = site( { narrow : true } );
		failing.world.fails = true;
		failing.click( failing.rows['dddddddddddd'].listen );
		await settle();
		check( '...and not where the player did not come: the page loads', failing.calls.scrolled.length === 0 && failing.calls.assigned.length === 1 );
	}

	{
		// The later click wins: the answer to the first is late and is dropped
		const s = site();
		const early = s.fragment( 'dddddddddddd', [] );
		const late = s.fragment( 'ccccccccccc3', [ 'ccccccccccc3', 'ccccccccccc2' ] );
		const held = [];
		s.world.answer = function( file ) { return new Promise( function( resolve ) { held.push( function() { resolve( s.response( file ) ) } ) } ) };
		s.click( s.rows['dddddddddddd'].listen );
		s.click( s.rows['ccccccccccc3'].listen );
		held[1]();
		await settle();
		held[0]();
		await settle();
		check( 'two files asked for one after the other: the player of the last click stays, the late answer to the first is dropped', s.aside.children[0] === late.box && s.aside.children.indexOf( early.box ) === -1 && s.calls.reviewInit.length === 1 && s.calls.replaced.length === 1 && marked( s.rows['ccccccccccc3'] ) && unmarked( s.rows['dddddddddddd'] ) );
	}

	{
		const plain = site( { fetch : false } );
		const noParser = site( { domparser : false } );
		check( 'a browser that cannot fetch or parse leaves every link to the browser, and every form to the page', plain.click( plain.rows['dddddddddddd'].listen ).prevented === false && noParser.click( noParser.rows['dddddddddddd'].listen ).prevented === false && plain.world.requests.length === 0 && noParser.world.requests.length === 0 );

		const noReview = site();
		delete noReview.window.Nino.review;
		noReview.fragment( 'dddddddddddd', [] );
		noReview.click( noReview.rows['dddddddddddd'].listen );
		await settle();
		check( 'where the script of Review is not there the player is put in all the same and nothing breaks: the form is the plain post', noReview.aside.children.length === 1 && noReview.calls.assigned.length === 0 );
	}

	// --- Versionswechsel -----------------------------------------------------------------------------------------

	console.log( '\nportal.js - another version of a title\n' );

	const versions = [ 'ccccccccccc3', 'ccccccccccc2', 'ccccccccccc1' ];

	{
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 12.5, paused : false, readyState : 4 } } } );
		const next = s.fragment( 'ccccccccccc2', versions, { duration : 100, readyState : 0 } );
		const select = s.old.select;

		check( 'the button of the select of the versions is taken away', s.old.go.hidden === true );
		select.value = 'ccccccccccc2';
		select.selectedIndex = 1;
		select.options[1].defaultSelected = false;
		fire( select, 'change' );
		check( 'a choice with the mouse sends the form, and the script catches it: the page is not loaded, the route is asked for the version chosen', s.old.form.prevented === true && s.old.form.submitted === 0 && s.world.requests.length === 1 && s.world.requests[0].url === '/.crm/player?file=ccccccccccc2' );
		await settle();
		check( '...the new player is in the column, Review has it, the rows are marked, and the address holds the file and the anchor of the select', s.aside.children[0] === next.box && s.calls.reviewInit.length === 1 && marked( s.rows['ccccccccccc3'] ) && s.calls.replaced.length === 1 && s.calls.replaced[0] === '/portal/projekte?projekt=00032&review=ccccccccccc2&tab=dateien#crm-versions' );
		check( '...the focus is in the select of the new player, where the person left it', next.select.focused === true && s.old.select.focused !== true );
		check( '...the position waits for the metadata of the new file, and nothing is played before', next.media.currentTime === 0 && next.state.plays === 0 && ( next.media.listeners.loadedmetadata || [] ).length === 1 );
		fire( next.media, 'loadedmetadata' );
		check( '...and then the new player stands where the old one stood, 12.5 s, and plays on, because the old one played', next.media.currentTime === 12.5 && next.state.plays === 1 && next.media.paused === false );
		check( '...the select of the new player is bound as the old one was: its button is taken away and a choice there asks again', next.go.hidden === true && ( next.select.listeners.change || [] ).length === 1 );
	}

	{
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 30, paused : true, readyState : 4 } } } );
		const next = s.fragment( 'ccccccccccc1', versions, { duration : 8, readyState : 0 } );
		s.old.select.value = 'ccccccccccc1';
		s.old.select.selectedIndex = 2;
		s.old.select.options[2].defaultSelected = false;
		fire( s.old.select, 'change' );
		await settle();
		fire( next.media, 'loadedmetadata' );
		check( 'a position past the end of the new file is its end, at the most: 30 s in a file of 8 s is 8 s - and a player that stood still stays still', next.media.currentTime === 8 && next.state.plays === 0 );
	}

	{
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 4, paused : false, readyState : 4 } } } );
		const next = s.fragment( 'ccccccccccc2', versions, { duration : 100, readyState : 4, rejects : true } );
		s.old.select.value = 'ccccccccccc2';
		s.old.select.selectedIndex = 1;
		s.old.select.options[1].defaultSelected = false;
		fire( s.old.select, 'change' );
		await settle();
		check( 'a medium whose metadata is there already takes the position at once, and a browser that refuses to play without a gesture is no error: the position is the same', next.media.currentTime === 4 && next.state.plays === 1 && ( next.media.listeners.loadedmetadata || [] ).length === 0 && unhandled === 0 );
	}

	{
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 0, paused : true, readyState : 4 } } } );
		const next = s.fragment( 'ccccccccccc2', versions, { duration : 100, readyState : 0, currentTime : 0 } );
		s.old.select.value = 'ccccccccccc2';
		s.old.select.selectedIndex = 1;
		s.old.select.options[1].defaultSelected = false;
		fire( s.old.select, 'change' );
		await settle();
		check( 'a player that stood at the start and stood still leaves the new one alone: no wait for metadata, no play, no move', ( next.media.listeners.loadedmetadata || [] ).length === 0 && next.state.plays === 0 && next.media.currentTime === 0 );
	}

	{
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 12, paused : false, readyState : 4 } } } );
		s.old.select.value = 'ccccccccccc2';
		s.old.select.selectedIndex = 1;
		s.old.select.options[1].defaultSelected = false;
		s.world.answer = function( file ) { return s.response( file, { status : 404 } ) };
		fire( s.old.select, 'change' );
		await settle();
		check( 'where the route does not answer the form is sent the plain way - the page loads the version, as it always did - and the player in the column is the old one, untouched', s.old.form.submitted === 1 && s.aside.children[0] === s.old.box && s.calls.replaced.length === 0 && s.old.state.plays === 0 && s.calls.reviewInit.length === 0 );

		const gone = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 12, paused : false, readyState : 4 } } } );
		gone.old.select.value = 'ccccccccccc2';
		gone.old.select.selectedIndex = 1;
		gone.old.select.options[1].defaultSelected = false;
		gone.world.fails = true;
		fire( gone.old.select, 'change' );
		await settle();
		check( '...so does a network that fails', gone.old.form.submitted === 1 && gone.aside.children[0] === gone.old.box );
	}

	{
		// The keyboard: an arrow only marks the choice, Enter sends it
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 12, paused : false, readyState : 4 } } } );
		const next = s.fragment( 'ccccccccccc2', versions );
		s.old.select.value = 'ccccccccccc2';
		s.old.select.selectedIndex = 1;
		s.old.select.options[1].defaultSelected = false;
		fire( s.old.select, 'keydown', { key : 'ArrowDown' } );
		fire( s.old.select, 'change' );
		await settle();
		check( 'a choice with the arrow keys asks nothing until it is sent', s.world.requests.length === 0 && s.aside.children[0] === s.old.box );
		const enter = { prevented : false };
		fire( s.old.select, 'keydown', { key : 'Enter', preventDefault : function() { enter.prevented = true } } );
		await settle();
		check( '...Enter sends it, and the player of that version comes in the same way', enter.prevented === true && s.world.requests.length === 1 && s.aside.children[0] === next.box );
	}

	{
		const plain = site( { fetch : false, playing : { file : 'ccccccccccc3', versions : versions } } );
		plain.old.select.value = 'ccccccccccc2';
		plain.old.select.selectedIndex = 1;
		plain.old.select.options[1].defaultSelected = false;
		fire( plain.old.select, 'change' );
		check( 'where the browser cannot fetch the form is sent as it is: nothing is caught', plain.old.form.prevented !== true && plain.world.requests.length === 0 );
	}

	{
		// The late answer to the first choice of a version is dropped
		const s = site( { playing : { file : 'ccccccccccc3', versions : versions, over : { currentTime : 12, paused : true, readyState : 4 } } } );
		const first = s.fragment( 'ccccccccccc2', versions );
		const last = s.fragment( 'ccccccccccc1', versions );
		const held = [];
		s.world.answer = function( file ) { return new Promise( function( resolve ) { held.push( function() { resolve( s.response( file ) ) } ) } ) };
		s.old.select.value = 'ccccccccccc2';
		s.old.select.selectedIndex = 1;
		s.old.select.options[1].defaultSelected = false;
		fire( s.old.select, 'change' );
		s.old.select.value = 'ccccccccccc1';
		s.old.select.selectedIndex = 2;
		fire( s.old.select, 'change' );
		held[1]();
		await settle();
		held[0]();
		await settle();
		check( 'two choices of a version one after the other: the player of the last stays and the late answer to the first is dropped, with no second write to the address', s.aside.children[0] === last.box && s.aside.children.indexOf( first.box ) === -1 && s.calls.replaced.length === 1 && s.calls.reviewInit.length === 1 );
	}

	// --- what the script is ---------------------------------------------------------------------------------------

	{
		const text = source( SCRIPT );
		check( 'the script puts nothing in by markup text and runs nothing from a string: no innerHTML, outerHTML, insertAdjacentHTML, document.write, eval or Function', /innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new Function/.test( text ) === false );
		check( '...no string of it holds the address of the route: the column names it, as the server wrote it from the directory the project is served from', /['"`][^'"`\n]*\/\.crm\/player/.test( text ) === false && text.indexOf('data-crm-player') !== -1 );
		check( '...no promise is left unhandled by a play that is refused', unhandled === 0 );
	}
}

suite();

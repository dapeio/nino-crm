/**
 *	Nino
 *	members-js-smoke.js	What the panel's script does over the kernel's own
 *											Nino.admin.js: it asks for members/list when the pane is
 *											first shown and draws the answer into the regions of the
 *											template - every value of an account as text -, the group
 *											filter sets the rows and keeps its select, the form for a
 *											new member posts members/add and says what came of it in
 *											the sentence of the code, a row is changed, sent a link
 *											or deleted after the question the recipe asks, everybody
 *											is signed out after one, and the entry the shell asks
 *											about unsaved input follows what is typed. Only Nino
 *											itself is a stand-in: the requests a panel makes through
 *											Nino.adminUi.api.call() are kept for the test to answer,
 *											and the words are the ones of the text files, in both
 *											interface languages.
 *
 *											The helpers - check(), done(), fills(), load(), findAll(),
 *											fire(), element() and documentOf() - are those of
 *											tests/harness.js of the checkout, three levels up or the
 *											one NINO_ROOT names. members-smoke.php runs this where
 *											node is on the path.
 *
 *	Usage: node features/Members/tests/members-js-smoke.js
 *	       NINO_ROOT=../nino node features/Members/tests/members-js-smoke.js
 */

'use strict';

/* global require, __dirname, process */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

/**
 *	A file of the feature the way load() and fills() want it: from the root of
 *	the checkout, which the feature sits outside of when it is tested from the
 *	catalogue
 *
 *	@param		{string}	relative		A path below features/Members/
 *
 *	@return		{string}
 */
function own( relative ) {
	return path.relative( root, fs.realpathSync( path.join( __dirname, '..', relative ) ) );
}

/**
 *	The panel's script in a page of its own, in one interface language: the
 *	kernel's Nino.admin.js and the feature's admin.js, over a Nino that keeps
 *	the requests, the entry the panel registers and the words it asks for. The
 *	pane has the three regions templates/panel.tpl gives it
 *
 *	@param		{string}		locale		en_US or de_DE: the words of the text files
 *	@param		{boolean}		[shell]		Whether the page has the workbench's shell, which registers the dirty entries; true by default
 *
 *	@return		{Object}
 */
function world( locale, shell ) {

	// The words as the workbench has them: its own, and the feature's merged in
	const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( own( 'text/'+ locale+ '.php' ) ) );
	const missing = [];
	const requests = [];
	const registered = [];
	const asked = [];
	let refreshed = 0;
	let agree = true;

	const mount = element('div');
	mount.id = 'members-app';

	[ 'members-tools', 'members-new', 'members-people' ].forEach( function( id ) {
		const region = element('div');
		region.id = id;
		mount.appendChild( region );
	} );

	// A control's name is its attribute as well, as in a page: the status line finds the field the server refused by it
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
	};

	if( shell !== false )
		Nino.admin = { dirty : { register : function( name, entry ) { registered.push( { name : name, entry : entry } ) }, refresh : function() { refreshed++ } } };

	const window = { Nino : Nino, confirm : function( text ) { asked.push( text ); return agree } };
	const context = vm.createContext( { console : console, document : page, Nino : Nino, window : window, Event : Event } );
	load( '_admin/assets/Nino.admin.js', context );

	// The one way a panel talks to the server is the kernel's api.call(): what it is asked for is kept, to be answered
	Nino.adminUi.api.call = function( action, payload, callback ) {
		requests.push( { action : action, payload : payload, callback : callback, answered : false } );
	};

	load( own( 'assets/admin.js' ), context, 'admin.js' );

	const find = function( root, predicate ) { return findAll( root ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin.members,
		window : window,
		mount : mount,
		requests : requests,
		registered : registered,
		missing : missing,
		asked : asked,
		refreshed : function() { return refreshed },
		agree : function( yes ) { agree = yes },
		entry : function() { return registered[0].entry },
		find : find,
		all : function( root, predicate ) { return findAll( root ?? mount, predicate ) },
		region : function( id ) { return find( mount, function( el ) { return el.id === id } ) },
		rows : function() { return findAll( mount, function( el ) { return el.tagName === 'LI' } ) },
		buttons : function( root ) { return findAll( root, function( el ) { return el.tagName === 'BUTTON' } ) },
		button : function( root, text ) { return find( root, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		input : function( root, name ) { return find( root, function( el ) { return el.tagName === 'INPUT' && el.name === name } ) },
		select : function() { return find( mount, function( el ) { return el.tagName === 'SELECT' } ) },
		word : function( key ) { return words[key] },
		last : function() { return requests[requests.length - 1] },

		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
	};
}

const A = { id : 'aaaaaaaaaaaa', email : 'ada@example.org', name : 'Ada Lovelace', group : 'staff', active : true, created : '2026-01-02T03:04:05Z', login : '2026-11-02T10:15:30Z' };
const B = { id : 'bbbbbbbbbbbb', email : 'bare@example.org', name : '', group : '', active : true, created : '2026-01-03T03:04:05Z', login : null };
const C = { id : 'cccccccccccc', email : 'guest@example.org', name : '<img src=x onerror=alert(1)>', group : 'guests', active : false, created : '2026-01-04T03:04:05Z', login : null };
const LIST = { accounts : [ A, B, C ], groups : [ 'guests', 'staff' ] };

/** The panel shown and its list answered: the screen is drawn */
function drawn( locale, data, shell ) {
	const w = world( locale, shell );
	w.panel.showCurrent();
	w.answer( 200, data ?? LIST );
	return w;
}

/** What a row says in its text: the name, the address where there is a name, and the line of the group and the sign-in */
function copyOf( w, row ) {
	return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } );
}


console.log( 'Nino.admin.members - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown, and the shell has no entry yet', w.requests.length === 0 && w.registered.length === 0 );

	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for members/list, once however often the shell shows it while the answer is out', w.requests.length === 1 && w.requests[0].action === 'members/list' && JSON.stringify( w.requests[0].payload ) === '{}' );
	check( '...and it registers its entry under its uri, which holds nothing unsaved while nothing is drawn', w.registered.length === 1 && w.registered[0].name === 'members' && w.entry().isDirty() === false );

	w.answer( 200, LIST );
	check( 'the screen is drawn into the three regions of the template: the sign-out, the form for a new member and the list', w.region('members-tools').children.length > 0 && w.region('members-new').children.length > 0
		&& w.region('members-people').children.length > 0 && w.mount.children.length === 3 );
	check( '...one row for each account, in the order the server gave them, each marked with its id', w.rows().map( function( row ) { return row.dataset.id } ).join() === [ A.id, B.id, C.id ].join() );

	w.panel.showCurrent();
	check( 'showing the pane again asks nothing: the screen is the answer of the first showing', w.requests.length === 1 );
}


console.log( '\nNino.admin.members - the rows\n' );

{
	const w = drawn('en_US');
	const rows = w.rows();

	check( 'a row says who the member is: the name, the address under it where there is one, and when they last signed in - in UTC', copyOf( w, rows[0] ).join('|') === 'Ada Lovelace|ada@example.org|staff · Last sign-in 2026-11-02 10:15 UTC' );
	check( '...an account without a name is its address, once, and one that never signed in says so', copyOf( w, rows[1] ).join('|') === 'bare@example.org|'+ w.word('/_admin/members/label/never') );

	const badges = rows.map( function( row ) { return w.find( row, function( el ) { return el.classList.contains('nino-admin-badge') } ) } );
	check( '...whether the account is active, in words and in the state of the badge', badges.map( function( badge ) { return badge.dataset.state } ).join() === 'ok,ok,muted'
		&& badges[0].textContent === w.word('/_admin/members/state/active') && badges[2].textContent === w.word('/_admin/members/state/inactive') );

	check( 'what the server sends is text and nothing else: a name that is markup is a string in a strong, and no element comes of it', copyOf( w, rows[2] )[0] === C.name
		&& w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'B' || el.tagName === 'SCRIPT' } ).length === 0 );

	const buttons = rows.map( function( row ) { return w.buttons( row ).map( function( button ) { return button.textContent } ).join() } );
	check( 'each row has the three buttons: edit, send a link, delete', new Set( buttons ).size === 1 && buttons[0] === [ 'Edit', 'Send login link', 'Delete' ].join() );
	check( '...and the link of an account that is switched off cannot be sent', w.button( rows[0], 'Send login link' ).disabled === false && w.button( rows[2], 'Send login link' ).disabled === true );
}

{
	const w = drawn( 'en_US', { accounts : [], groups : [] } );
	check( 'a project without a member says so, and has no list, no filter and no row', w.rows().length === 0 && w.select() === null && w.region('members-rows').children.length === 1
		&& w.region('members-rows').children[0].textContent === w.word('/_admin/members/empty') );
}


console.log( '\nNino.admin.members - the filter\n' );

{
	const w = drawn('en_US');
	const select = w.select();
	const options = w.all( select, function( el ) { return el.tagName === 'OPTION' } ).map( function( option ) { return option.value } );

	check( 'the filter offers all groups, each group in use, and - since an account has none - the accounts without a group', options.join() === [ '', 'guests', 'staff', '-' ].join() && select.value === '' );

	select.value = 'staff';
	fire( select, 'change' );
	check( 'a group shows the accounts of that group and no others', w.rows().map( function( row ) { return row.dataset.id } ).join() === A.id );

	select.value = '-';
	fire( select, 'change' );
	check( '...so does the choice of no group', w.rows().map( function( row ) { return row.dataset.id } ).join() === B.id );

	select.value = '';
	fire( select, 'change' );
	check( '...and all of them bring every row back - on the select that was there, which keeps the focus', w.rows().length === 3 && w.select() === select && w.requests.length === 1 );
}

{
	const w = drawn( 'en_US', { accounts : [ A ], groups : [ 'staff' ] } );
	check( 'a project whose accounts all have a group is not offered the choice of none', w.all( w.select(), function( el ) { return el.tagName === 'OPTION' } ).map( function( option ) { return option.value } ).join() === ',staff' );
}

{
	const w = drawn( 'en_US', { accounts : [ B ], groups : [] } );
	check( 'a project without a group has no filter to offer', w.select() === null && w.rows().length === 1 );
}


console.log( '\nNino.admin.members - a new member\n' );

{
	const w = drawn('en_US');
	const form = w.region('members-new');
	const email = w.input( form, 'email' );
	const name = w.input( form, 'name' );
	const group = w.input( form, 'group' );
	const invite = w.find( form, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	const nothing = [];

	w.entry().save( function( ok ) { nothing.push( ok ) } );
	check( 'the shell\'s Save with nothing unsaved posts nothing and ends in done( true )', nothing.join() === 'true' && w.requests.length === 1 );

	check( 'the form has a field for the address, the name and the group, and the invitation ticked', email !== null && name !== null && group !== null && invite.checked === true && email.type === 'email' && email.required === true );
	check( '...and holds nothing unsaved while nothing is typed', w.entry().isDirty() === false );

	const status = function( root ) { return w.find( root, function( el ) { return el.classList.contains('nino-admin-status') } ) };

	// An event does not bubble in the stand-in: the form is told, with the field as its target
	email.value = 'new@example.org';
	fire( w.find( form, function( el ) { return el.tagName === 'FORM' } ), 'input', { target : email } );
	check( 'what is typed is unsaved input, to the shell and to the line of the form', w.entry().isDirty() === true && status( form ).dataset.state === 'dirty' );

	const told = [];
	w.entry().save( function( ok ) { told.push( ok ) } );
	const sent = w.last();
	check( 'save posts members/add with the four values of the form - the invitation as a flag - and the button waits for the answer', sent.action === 'members/add' && JSON.stringify( sent.payload ) === '{"email":"new@example.org","name":"","group":"","invite":true,"notify":true}'
		&& w.button( form, w.word('/_admin/members/label/add') ).disabled === true );

	name.value = 'New One';
	invite.checked = false;
	w.answer( 200, { account : A, invited : false } );
	check( 'a member added empties the form, says so, asks for the list again and ends in done( true )', told.join() === 'true' && email.value === '' && name.value === '' && w.requests.length === 3 && w.last().action === 'members/list'
		&& w.find( form, function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === w.word('/_admin/members/msg/added') && w.refreshed() === 1 );
	w.answer( 200, LIST );
	check( '...and does not draw the form again with the list: it is the one form there is, and the invitation stays as it was left', w.region('members-new').children.length === 1 && w.input( w.region('members-new'), 'name' ) === name && invite.checked === false );

	email.value = 'quiet@example.org';
	invite.checked = false;
	w.entry().save( function() {} );
	const quiet = JSON.stringify( w.last().payload );
	w.answer( 200, { account : A, invited : false } );
	w.answer( 200, LIST );
	check( 'an invitation that is not ticked is not asked for: the flag is false', quiet === '{"email":"quiet@example.org","name":"","group":"","invite":false,"notify":true}' );

	email.value = 'x@example.org';
	invite.checked = true;
	w.entry().save( function() {} );
	w.answer( 200, { account : A, invited : true } );
	check( 'with an invitation the line says so', w.find( form, function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === w.word('/_admin/members/msg/invited') );
	w.answer( 200, LIST );
}

{
	const w = drawn('en_US');
	const form = w.region('members-new');
	const email = w.input( form, 'email' );
	const line = w.find( form, function( el ) { return el.classList.contains('nino-admin-status') } );
	const told = [];

	w.input( form, 'name' ).value = 'Typed, but no address';
	w.entry().save( function( ok ) { told.push( ok ) } );
	check( 'a form without an address sends nothing, says what is missing and ends in done( false )', w.requests.length === 1 && told.join() === 'false' && line.dataset.state === 'error' && line.textContent === w.word('/_admin/members/error/email') );

	email.value = 'ada@example.org';
	w.entry().save( function( ok ) { told.push( ok ) } );
	w.answer( 400, { error : 'x', code : 'members_taken', field : 'email' } );
	check( 'a refused address is told in the sentence of its code, ends in done( false ), marks the field the server named and keeps what was typed', line.dataset.state === 'error'
		&& line.textContent === 'A member with this address exists already.' && told.join() === 'false,false' && email.attributes['aria-invalid'] === 'true' && email.value === 'ada@example.org' );

	fire( email, 'input', { bubbles : true } );
	check( '...the mark goes with the next thing typed', email.hasAttribute('aria-invalid') === false );

	w.entry().save( function() {} );
	w.answer( 400, { error : 'x', code : 'members_long', params : [ 120 ], field : 'name' } );
	check( 'a code with a limit puts it into the sentence', line.textContent === 'The name may have up to 120 characters.' );

	w.entry().save( function() {} );
	const before = w.requests.length;
	w.answer( 500, { error : 'could not write the members' } );
	check( 'a failure nobody can cause shows what the server said, the status in front of it', line.textContent === '(500) could not write the members' && w.requests.length === before );

	w.entry().save( function() {} );
	w.answer( 500, { error : 'x', code : 'members_mail' } );
	check( 'an invitation whose mail did not go out is told in its sentence - and the list is asked for again, since the account is there', line.textContent.startsWith('The mail could not be sent.') === true && w.last().action === 'members/list' );

	w.answer( 200, LIST );
	w.entry().save( function() {} );
	w.answer( 429, { error : 'x', code : 'members_ratelimit' } );
	check( 'a mail the kernel\'s cap refused is told in its sentence too - and the list is asked for again, since the account is there', line.textContent === w.word('/_admin/error/members_ratelimit') && line.textContent !== '' && w.last().action === 'members/list' );

	const twice = [];
	w.answer( 200, LIST );
	w.entry().save( function( ok ) { twice.push( ok ) } );
	w.entry().save( function( ok ) { twice.push( ok ) } );
	check( 'a second save while one is out is no second request, and ends in done( false )', twice.join() === 'false' && w.requests.filter( function( request ) { return request.answered === false } ).length === 1 );
}


console.log( '\nNino.admin.members - changing an account\n' );

{
	const w = drawn('en_US');
	const rows = w.rows();

	check( 'no row holds a form to begin with', w.all( w.mount, function( el ) { return el.tagName === 'FORM' && el.className === 'members-edit' } ).length === 0 );

	fire( w.button( rows[0], 'Edit' ), 'click' );
	const edit = w.find( w.rows()[0], function( el ) { return el.tagName === 'FORM' } );
	const name = w.input( edit, 'name' );
	const group = w.input( edit, 'group' );
	const active = w.find( edit, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );

	check( 'edit opens a form in that row, with the name, the group and whether the account is active - and in no other', edit !== null && name.value === 'Ada Lovelace' && group.value === 'staff' && active.checked === true
		&& w.all( w.mount, function( el ) { return el.tagName === 'FORM' && el.className === 'members-edit' } ).length === 1 && w.requests.length === 1 );
	check( '...it holds nothing unsaved until something is changed', w.entry().isDirty() === false );

	name.value = 'Ada L.';
	active.checked = false;
	fire( edit, 'input', { target : name } );
	check( '...and does when it is - to the shell and to the line of the form', w.entry().isDirty() === true && w.find( edit, function( el ) { return el.classList.contains('nino-admin-status') } ).dataset.state === 'dirty' );

	const told = [];
	w.entry().save( function( ok ) { told.push( ok ) } );
	check( 'the shell\'s save posts members/save with the id and the three values', w.last().action === 'members/save' && JSON.stringify( w.last().payload ) === '{"id":"aaaaaaaaaaaa","name":"Ada L.","group":"staff","active":false}' );

	w.answer( 400, { error : 'x', code : 'members_long', params : [ 120 ], field : 'name' } );
	check( 'a refusal is told in the form, which stays open with what was typed, and ends in done( false )', told.join() === 'false' && name.value === 'Ada L.' && w.find( edit, function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === 'The name may have up to 120 characters.'
		&& name.attributes['aria-invalid'] === 'true' && w.find( w.rows()[0], function( el ) { return el === edit } ) === edit );

	w.entry().save( function( ok ) { told.push( ok ) } );
	w.answer( 200, { account : Object.assign( {}, A, { name : 'Ada L.', active : false } ) } );
	check( 'a saved change closes the form, tells the shell, asks for the list again and ends in done( true )', told.join() === 'false,true' && w.last().action === 'members/list' && w.refreshed() === 1 );
	w.answer( 200, { accounts : [ Object.assign( {}, A, { name : 'Ada L.', active : false } ), B, C ], groups : LIST.groups } );
	check( '...and the list shows it', w.all( w.mount, function( el ) { return el.tagName === 'FORM' && el.className === 'members-edit' } ).length === 0 && copyOf( w, w.rows()[0] )[0] === 'Ada L.' );
}

{
	const w = drawn('en_US');

	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	fire( w.button( w.rows()[0], 'Cancel' ), 'click' );
	check( 'cancel closes the form without a request', w.all( w.mount, function( el ) { return el.tagName === 'FORM' && el.className === 'members-edit' } ).length === 0 && w.requests.length === 1 );

	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	fire( w.button( w.rows()[1], 'Edit' ), 'click' );
	check( 'opening another row closes the one that is open, which holds nothing unsaved, without a question', w.asked.length === 0 && w.rows()[0].children.length === 2 && w.find( w.rows()[1], function( el ) { return el.tagName === 'FORM' } ) !== null );

	w.input( w.rows()[1], 'name' ).value = 'typed';
	w.agree( false );
	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	check( '...and with something unsaved in it, asks first - and stays where it is on a no', w.asked.length === 1 && w.find( w.rows()[1], function( el ) { return el.tagName === 'FORM' } ) !== null && w.input( w.rows()[1], 'name' ) !== null );

	w.agree( true );
	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	check( '...and moves on a yes', w.asked.length === 2 && w.find( w.rows()[0], function( el ) { return el.tagName === 'FORM' } ) !== null && w.find( w.rows()[1], function( el ) { return el.tagName === 'FORM' } ) === null );

	// What is typed into the form of a change and into the one for a new member is forgotten, and the change is closed
	w.input( w.rows()[0], 'name' ).value = 'again';
	w.input( w.region('members-new'), 'email' ).value = 'typed@example.org';
	const typed = w.entry().isDirty();
	w.entry().discard();
	check( 'discard forgets what was typed', typed === true && w.entry().isDirty() === false && w.input( w.region('members-new'), 'email' ).value === '' );
}

console.log( '\nNino.admin.members - whether the account is mailed\n' );

{
	const w = drawn('en_US');
	const mail = w.region('members-mail');
	const tick = w.find( mail, function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );
	const region = w.region('members-link');

	check( 'the box for the mail is a real checkbox with its label, ticked at first, among the tools - and the region for the link beside it is hidden and empty', tick !== null && tick.dataset.key === 'notify' && tick.checked === true
		&& w.region('members-tools').children.indexOf( mail ) !== -1 && w.all( mail, function( el ) { return el.textContent === w.word('/_admin/members/label/notify') } ).length > 0 && region.hidden === true && region.children.length === 0 );

	tick.checked = false;
	const form = w.region('members-new');
	w.input( form, 'email' ).value = 'link@example.org';
	w.entry().save( function() {} );
	check( 'unticked, the form posts members/add with notify false', w.last().action === 'members/add' && w.last().payload.notify === false && w.last().payload.invite === true );

	let copied = null;
	w.window.navigator = { clipboard : { writeText : function( text ) { copied = text; return { then : function( done ) { done() } } } } };
	w.answer( 200, { account : A, invited : true, link : 'https://example.org/.members/login?t=abc' } );
	const field = w.region('members-link-field');
	check( 'the link of the answer is shown in a read-only field with the sentence that it is shown once, a button that copies it and one that closes it - and the form says that no mail went',
		region.hidden === false && field !== null && field.readOnly === true && field.value === 'https://example.org/.members/login?t=abc'
		&& w.all( region, function( el ) { return el.textContent === w.word('/_admin/members/hint/link-once') } ).length > 0 && w.button( region, w.word('/_admin/members/label/copy') ) !== null && w.button( region, w.word('/_admin/members/label/close-link') ) !== null
		&& w.all( form, function( el ) { return el.textContent === w.word('/_admin/members/msg/invited-quiet') } ).length > 0 );

	w.input( form, 'email' ).value = 'again@example.org';
	w.entry().save( function() {} );
	check( 'a new add takes the link of the one before away at once, before it asks', region.hidden === true && region.children.length === 0 && w.region('members-link-field') === null && w.last().action === 'members/add' );
	w.answer( 200, { account : A, invited : true, link : 'https://example.org/.members/login?t=abc' } );
	w.button( region, w.word('/_admin/members/label/copy') ).click();
	check( '...the copy button hands the link to the clipboard where there is one', copied === 'https://example.org/.members/login?t=abc' );
	delete w.window.navigator;
	w.button( region, w.word('/_admin/members/label/copy') ).click();
	check( '...and where there is none it selects the field and says so', w.all( region, function( el ) { return el.textContent === w.word('/_admin/members/msg/selected') } ).length > 0 );

	w.button( region, w.word('/_admin/members/label/close-link') ).click();
	check( 'closing takes the link away: the field is gone, the region hidden and empty', region.hidden === true && region.children.length === 0 && w.region('members-link-field') === null );

	w.answer( 200, LIST );
	fire( w.button( w.rows()[0], 'Send login link' ), 'click' );
	check( 'the rows ask the same box: Send login link posts notify false while it stands unticked', w.last().action === 'members/link' && w.last().payload.notify === false );
	w.answer( 200, { sent : false, link : 'https://example.org/.members/login?t=def' } );
	check( '...and its link is shown, with the line of a link made without a mail', w.region('members-link-field').value === 'https://example.org/.members/login?t=def'
		&& w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === w.word('/_admin/members/msg/sent-quiet') );

	fire( w.button( w.rows()[0], 'Send login link' ), 'click' );
	check( 'a new action takes the old link away before it asks', w.region('members-link').hidden === true && w.region('members-link-field') === null );
	w.answer( 500, { error : 'x', code : 'members_mail' } );
}


{
	const w = drawn('en_US');
	const tick = w.find( w.region('members-mail'), function( el ) { return el.tagName === 'INPUT' && el.type === 'checkbox' } );

	tick.checked = false;
	fire( tick, 'change' );
	w.input( w.region('members-new'), 'email' ).value = 'again@example.org';
	w.entry().save( function() {} );
	w.answer( 200, { account : A, invited : true, link : 'https://example.org/.members/login?t=shown' } );
	check( 'a screen that is shown again starts at the default: the box is ticked again and the link that was shown is gone - the panel is not asked for anything', w.region('members-link').hidden === false && tick.checked === false
		&& ( w.panel.showCurrent(), tick.checked === true && w.region('members-link').hidden === true && w.region('members-link').children.length === 0 && w.region('members-link-field') === null && w.panel._notify.checked === true ) );
}

{
	const w = drawn('en_US');

	fire( w.button( w.rows()[0], 'Send login link' ), 'click' );
	check( 'send login link posts members/link with the id', w.last().action === 'members/link' && JSON.stringify( w.last().payload ) === '{"id":"aaaaaaaaaaaa","notify":true}' );
	w.answer( 200, { sent : true } );
	check( '...and the row says the link is sent', w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === w.word('/_admin/members/msg/sent') );

	fire( w.button( w.rows()[0], 'Send login link' ), 'click' );
	w.answer( 500, { error : 'x', code : 'members_mail' } );
	check( '...a mail that did not go out is told in the row', w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') } ).textContent.startsWith('The mail could not be sent.') === true );

	fire( w.button( w.rows()[0], 'Send login link' ), 'click' );
	w.answer( 429, { error : 'x', code : 'members_ratelimit' } );
	check( '...so is a mail the kernel\'s cap refused', w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === w.word('/_admin/error/members_ratelimit') );
}

{
	const w = drawn('en_US');

	w.agree( false );
	fire( w.button( w.rows()[0], 'Delete' ), 'click' );
	check( 'delete asks first, naming the member - and sends nothing on a no', w.asked.length === 1 && w.asked[0].includes('Ada Lovelace') === true && w.requests.length === 1 );

	w.agree( true );
	fire( w.button( w.rows()[1], 'Delete' ), 'click' );
	check( '...it names the address where the account has no name, and on a yes posts members/delete with the id', w.asked[1].includes('bare@example.org') === true && w.last().action === 'members/delete' && JSON.stringify( w.last().payload ) === '{"id":"bbbbbbbbbbbb"}' );
	w.answer( 200, { ok : true } );
	check( '...and asks for the list again', w.last().action === 'members/list' );
	w.answer( 200, { accounts : [ A, C ], groups : LIST.groups } );
	check( '...which no longer has the row', w.rows().map( function( row ) { return row.dataset.id } ).join() === [ A.id, C.id ].join() );

	fire( w.button( w.rows()[0], 'Delete' ), 'click' );
	w.answer( 404, { error : 'x', code : 'members_unknown' } );
	check( 'a member that is gone already is told in the row, in its sentence', w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') } ).textContent === 'There is no such member (any more).' );
}


console.log( '\nNino.admin.members - the shell\'s Save, with a change and a new member both open\n' );

{
	const w = drawn('en_US');
	const added = { id : 'dddddddddddd', email : 'new@example.org', name : '', group : '', active : true, created : '2026-11-02T03:04:05Z', login : null };

	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	w.input( w.rows()[0], 'name' ).value = 'Ada L.';
	w.input( w.region('members-new'), 'email' ).value = added.email;

	const told = [];
	w.entry().save( function( ok ) { told.push( ok ) } );
	check( 'the shell\'s Save with a change and a new member both open saves the change first, and posts nothing else until that is answered', w.last().action === 'members/save' && w.requests.length === 2 && told.length === 0 );

	w.answer( 200, { account : Object.assign( {}, A, { name : 'Ada L.' } ) } );
	check( '...then the new member, with what was typed into the form for it', w.last().action === 'members/add' && w.last().payload.email === added.email && told.length === 0 );

	w.answer( 200, { account : added, invited : true } );
	check( '...and ends in done( true ), once, when both are saved', told.join() === 'true' );

	// The list that was asked for after the change was asked for before the member existed: it is asked for again when it has come back
	w.answer( 200, { accounts : [ Object.assign( {}, A, { name : 'Ada L.' } ), B, C ], groups : LIST.groups } );
	check( '...and the list is asked for again once the one that was out has come back, so that the member is on the screen', w.last().action === 'members/list' && w.requests.filter( function( request ) { return request.answered === false } ).length === 1 );
	w.answer( 200, { accounts : [ Object.assign( {}, A, { name : 'Ada L.' } ), B, C, added ], groups : LIST.groups } );
	check( '...and it shows both', w.rows().map( function( row ) { return row.dataset.id } ).join() === [ A.id, B.id, C.id, added.id ].join() && copyOf( w, w.rows()[0] )[0] === 'Ada L.' );
}

{
	const w = drawn('en_US');
	const added = { id : 'dddddddddddd', email : 'new@example.org', name : '', group : '', active : true, created : '2026-11-02T03:04:05Z', login : null };

	fire( w.button( w.rows()[0], 'Edit' ), 'click' );
	const name = w.input( w.rows()[0], 'name' );
	name.value = 'Ada L.';
	w.input( w.region('members-new'), 'email' ).value = added.email;

	const told = [];
	w.entry().save( function( ok ) { told.push( ok ) } );
	w.answer( 400, { error : 'x', code : 'members_long', params : [ 120 ], field : 'name' } );
	check( 'a change that is refused does not keep the new member from being saved: it is posted all the same', w.last().action === 'members/add' && told.length === 0 );

	w.answer( 200, { account : added, invited : true } );
	check( '...and the Save ends in done( false ), since the change is not saved - it is open still, with what was typed', told.join() === 'false' && name.value === 'Ada L.' && w.entry().isDirty() === true );
}

{
	const w = drawn('en_US');

	// A list is out when the new member is saved: it is asked for again when it is back - and when it does not come back, nothing is left that asks once more
	w.panel.reload();
	w.input( w.region('members-new'), 'email' ).value = 'x@example.org';
	w.entry().save( function() {} );
	w.answer( 200, { account : A, invited : false } );
	w.answer( 500, null );
	w.panel.showCurrent();

	const asked = w.requests.length;
	w.answer( 200, LIST );
	check( 'a list that was to be asked for again and did not come back leaves nothing behind that asks once more', w.requests.length === asked && w.rows().length === 3 );
}


console.log( '\nNino.admin.members - signing everybody out\n' );

{
	const w = drawn('en_US');
	const tools = w.region('members-tools');
	const line = w.find( tools, function( el ) { return el.classList.contains('nino-admin-status') } );

	w.agree( false );
	fire( w.button( tools, 'Sign everybody out' ), 'click' );
	check( 'the button asks first, and sends nothing on a no', w.asked.length === 1 && w.requests.length === 1 );

	w.agree( true );
	fire( w.button( tools, 'Sign everybody out' ), 'click' );
	check( '...and on a yes posts members/signout', w.last().action === 'members/signout' && JSON.stringify( w.last().payload ) === '{}' );
	w.answer( 200, { signedOut : true } );
	check( '...and says it', line.dataset.state === 'saved' && line.textContent === w.word('/_admin/members/msg/signedout') );

	fire( w.button( tools, 'Sign everybody out' ), 'click' );
	w.answer( 500, null );
	check( '...a failure is told in the line, in the panel\'s sentence', line.dataset.state === 'error' && line.textContent === '(500) Nobody could be signed out.' );
}


console.log( '\nNino.admin.members - a page without the shell, and a load that failed\n' );

{
	const w = drawn( 'en_US', LIST, false );
	let threw = null;

	try {
		w.region('members-new').querySelector('input').value = 'x@example.org';
		w.panel._submitAdd( function() {} );
		w.answer( 200, { account : A, invited : true } );
	}
	catch( error ) { threw = error }

	check( 'a page without the shell\'s registry works all the same', threw === null && w.registered.length === 0 && w.last().action === 'members/list' );
}

{
	const w = world('en_US');

	w.panel.showCurrent();
	w.answer( 500, null );
	check( 'a failed load is said in the mount, in the panel\'s own sentence', w.mount.children.length === 1 && w.mount.children[0].className === 'nino-admin-error' && w.mount.children[0].textContent === '(500) The members could not be loaded.' );

	let drawing = null;

	try {
		w.panel.showCurrent();
		w.answer( 200, LIST );
	}
	catch( error ) { drawing = error }

	check( '...and showing the pane again asks again, and draws the whole screen anew - the regions the error replaced among it', drawing === null && w.requests.length === 2 && w.mount.children.length === 3 && w.rows().length === 3
		&& w.region('members-new').children.length > 0 && w.region('members-tools').children.length > 0 );
}


console.log( '\nNino.admin.members - the words, in both languages\n' );

[ 'en_US', 'de_DE' ].forEach( function( locale ) {

	const w = drawn( locale );
	const taken = { en_US : 'A member with this address exists already.', de_DE : 'Ein Mitglied mit dieser Adresse gibt es schon.' }[locale];

	// Everything the screen can say: a form refused, a change refused, every button asked
	w.region('members-new').querySelector('input').value = 'ada@example.org';
	w.entry().save( function() {} );
	w.answer( 400, { error : 'x', code : 'members_taken', field : 'email' } );
	const told = w.find( w.region('members-new'), function( el ) { return el.classList.contains('nino-admin-status') } ).textContent;

	w.panel._discard();
	fire( w.buttons( w.rows()[0] )[0], 'click' );
	w.input( w.rows()[0], 'name' ).value = 'Changed';
	w.entry().save( function() {} );
	w.answer( 400, { error : 'x', code : 'members_long', params : [ 120 ], field : 'name' } );
	const long = w.find( w.rows()[0], function( el ) { return el.classList.contains('nino-admin-status') && el.dataset.state === 'error' } ).textContent;
	fire( w.buttons( w.rows()[1] )[1], 'click' );
	w.answer( 200, { sent : true } );
	fire( w.buttons( w.rows()[1] )[2], 'click' );
	w.answer( 404, { error : 'x', code : 'members_unknown' } );
	fire( w.button( w.region('members-tools'), w.word('/_admin/members/label/signout') ), 'click' );
	w.answer( 200, { signedOut : true } );

	const failed = world( locale );
	failed.panel.showCurrent();
	failed.answer( 500, null );

	check( locale+ ': a code is told in its sentence, the labels and the buttons say their words', told === taken && long === { en_US : 'The name may have up to 120 characters.', de_DE : 'Der Name darf bis zu 120 Zeichen haben.' }[locale]
		&& w.buttons( w.rows()[0] ).length === 5 && w.buttons( w.rows()[0] ).every( function( button ) { return button.textContent !== '' } ) );
	check( locale+ ': a load that failed is told in the panel\'s sentence', failed.mount.children[0].textContent === '(500) '+ failed.word('/_admin/members/error/load') );
	check( locale+ ': every word the script asked for is in the text files', w.missing.length === 0 && failed.missing.length === 0 );
} );

done();

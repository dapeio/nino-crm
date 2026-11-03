/**
 *	Crm
 *	items-js-smoke.js		What the script of the panel Positionen does (Items/Admin/assets/admin.js) over the kernel's own
 *											Nino.admin.js and the money functions of money.js: it registers Nino.admin['crm-items'] under the
 *											uri of the panel and posts crm-items/<action>; the first showing asks for the list with the search,
 *											and the search asks again; the rows say the title, the unit, the price with the currency and - with
 *											VAT - the rate, as text and as nothing else, and wear the badge of an item that is off; the arrows
 *											move an item and post the whole order, and are off where the list is not the whole catalogue or the
 *											item cannot move; the card is the fields of an item (the rate a select of the rates in regular and
 *											no field without VAT), saves exactly those fields with the id as a string, says a refusal in the
 *											sentence of its code and marks the field, knows when it holds what nobody saved, takes it back on
 *											discard and keeps it through the switch of active. Only Nino itself is a stand-in.
 *
 *											A pin names the words it expects by their keys, not by what they say. documents-smoke.php runs this
 *											where node is on the path.
 *
 *	Usage: node app/Crm/tests/items-js-smoke.js
 */

'use strict';

/* global require, __dirname, process */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = fs.realpathSync( process.env.NINO_ROOT || path.join( __dirname, '../../..' ) );
const { check, done, fills, load, findAll, fire, element, documentOf } = require( path.join( root, 'tests/harness.js' ) );

const OWN = 'app/Crm/Items/Admin/';
const K = '/_admin/crm-items/';
const NBSP = '\u00a0';

/**
 *	The panel's scripts in a page of their own, in one interface language
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
	mount.id = 'crm-items-app';

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

	Nino.adminUi.api.call = function( action, payload, callback ) {
		requests.push( { action : action, payload : payload, callback : callback, answered : false } );
	};

	load( 'app/Crm/Documents/Money/assets/money.js', context, 'money.js' );
	load( OWN+ 'assets/admin.js', context, 'admin.js' );

	const find = function( from, predicate ) { return findAll( from ?? mount, predicate )[0] ?? null };

	return {
		panel : Nino.admin['crm-items'],
		mount : mount,
		requests : requests,
		registered : registered,
		guarded : guarded,
		missing : missing,
		find : find,
		all : function( from, predicate ) { return findAll( from ?? mount, predicate ) },
		button : function( from, text ) { return find( from, function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		input : function( from, name ) { return find( from, function( el ) { return ( el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' ) && el.name === name } ) },
		select : function( from, name ) { return find( from, function( el ) { return el.tagName === 'SELECT' && el.name === name } ) },
		word : function( key ) { return words[key] },
		has : function( key ) { return typeof words[key] === 'string' && words[key] !== '' },
		entry : function() { return registered[0].entry },
		last : function() { return requests[requests.length - 1] },
		texts : function( from ) { return findAll( from ?? mount, function() { return true } ).map( function( el ) { return el.textContent } ) },
		line : function( from ) { return find( from, function( el ) { return String( el.className ).split(' ').indexOf('nino-admin-status') !== -1 } ) },
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
	};
}

const SMALL = { vat_mode : 'small_business', rates : [ 1900, 700, 0 ], currency : '€' };
const REGULAR = { vat_mode : 'regular', rates : [ 1900, 700, 0 ], currency : '€' };
const A = { id : 1, title : 'Webdesign', description : 'Pro Stunde', unit : 'Std.', price_net : 9000, vat_rate : 0, active : true, sort : 1 };
const B = { id : 2, title : 'Hosting', description : '', unit : '', price_net : 1200, vat_rate : 700, active : false, sort : 2 };
const C = { id : 3, title : '<img src=x onerror=alert(1)>', description : '', unit : 'Monat', price_net : -500, vat_rate : 1900, active : true, sort : 3 };
const FIELDS = [ 'title', 'unit', 'price', 'description' ];

/** The panel shown and its list answered */
function drawn( locale, items, settings ) {
	const w = world( locale );
	w.panel.showCurrent();
	w.answer( 200, { items : items ?? [ A, B, C ], settings : settings ?? SMALL } );
	return w;
}

/** The card of an item open */
function opened( locale, item, settings ) {
	const w = drawn( locale, [ A, B, C ].map( function( entry ) { return entry.id === item.id ? item : entry } ), settings );
	w.find( w.mount, function( el ) { return el.tagName === 'BUTTON' && el.dataset.id === String( item.id ) } ).click();
	return w;
}

console.log( 'Nino.admin[\'crm-items\'] - the screen\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before the pane is shown', w.requests.length === 0 && w.registered.length === 0 );
	check( 'the namespace is the nav uri of the panel, with its mount', w.panel.uri === 'crm-items' && w.panel.mount() === w.mount );

	w.panel.showCurrent();
	w.panel.showCurrent();
	check( 'showing it asks for crm-items/list, once while the answer is out, with the search that is empty', w.requests.length === 1 && w.requests[0].action === 'crm-items/list' && JSON.stringify( w.requests[0].payload ) === '{"search":""}' );
	check( '...and registers its entry under its uri, which holds nothing unsaved', w.registered.length === 1 && w.registered[0].name === 'crm-items' && w.entry().isDirty() === false );
}

console.log( '\nThe rows\n' );

{
	const w = drawn('en_US');
	const rows = w.all( w.mount, function( el ) { return el.tagName === 'LI' } );
	const copy = function( row ) { return w.all( row, function( el ) { return el.tagName === 'STRONG' || el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } ) };

	check( 'one row for each item, in the order of the answer', rows.length === 3 && rows.map( function( row ) { return row.children[0].dataset.id } ).join() === '1,2,3' );
	check( 'a row says the title, the unit and the price with the currency - and no rate without VAT', copy( rows[0] ).join('|') === 'Webdesign|Std. · 90,00'+ NBSP+ '€' && copy( rows[1] ).join('|') === 'Hosting|12,00'+ NBSP+ '€' && copy( rows[2] )[1] === 'Monat · -5,00'+ NBSP+ '€' );
	check( 'only the item that is off wears the badge', w.all( rows[1], function( el ) { return el.textContent === w.word( K+ 'state/inactive' ) } ).length === 1 && w.all( rows[0], function( el ) { return String( el.className ).indexOf('nino-admin-badge') !== -1 } ).length === 0 );
	check( 'what the server sends is text and nothing else: a title that is markup is a string, no element comes of it', copy( rows[2] )[0] === C.title && w.all( w.mount, function( el ) { return el.tagName === 'IMG' || el.tagName === 'SCRIPT' } ).length === 0 );
	check( 'no word is missing from the text files', w.missing.length === 0 );

	const regular = drawn( 'en_US', undefined, REGULAR );
	const copyR = function( row ) { return regular.all( row, function( el ) { return el.tagName === 'SMALL' } ).map( function( el ) { return el.textContent } ) };
	check( 'with VAT a row says the rate as well', copyR( regular.all( regular.mount, function( el ) { return el.tagName === 'LI' } )[1] ).join() === '12,00'+ NBSP+ '€ · 7 %' );

	const empty = drawn( 'en_US', [] );
	check( 'no item says so', empty.texts().indexOf( empty.word( K+ 'empty/items' ) ) !== -1 && empty.texts().indexOf( empty.word( K+ 'empty/found' ) ) === -1 );

	const form = w.find( w.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-search') !== -1 } );
	w.input( form, 'search' ).value = 'web';
	fire( form, 'submit' );
	check( 'the search posts crm-items/list again with what was typed', w.last().action === 'crm-items/list' && JSON.stringify( w.last().payload ) === '{"search":"web"}' );
	w.answer( 200, { items : [], settings : SMALL } );
	check( '...and a search that finds nothing says that, and not that there are no items', w.texts().indexOf( w.word( K+ 'empty/found' ) ) !== -1 && w.texts().indexOf( w.word( K+ 'empty/items' ) ) === -1 );
}

console.log( '\nThe order\n' );

{
	const w = drawn('en_US');
	const arrows = function( kind ) { return w.all( w.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-move-'+ kind) !== -1 } ) };

	check( 'each row has an arrow up and one down, with a name; the first cannot go up and the last cannot go down', arrows('up').length === 3 && arrows('down').length === 3 && arrows('up')[0].disabled === true && arrows('up')[1].disabled === false && arrows('down')[2].disabled === true
		&& arrows('up')[1].attributes['aria-label'] === w.word( K+ 'label/up' ) && arrows('down')[0].attributes['aria-label'] === w.word( K+ 'label/down' ) );

	arrows('up')[1].click();
	check( 'an arrow up on the second item puts it first', JSON.stringify( w.last().payload ) === '{"ids":[2,1,3]}' );
	w.answer( 500, { error : 'x', code : 'crm_items_database', params : [] } );
	arrows('down')[0].click();
	check( 'an arrow posts crm-items/order with the ids in the order they would have, all of them', w.last().action === 'crm-items/order' && JSON.stringify( w.last().payload ) === '{"ids":[2,1,3]}' );
	w.answer( 200, { items : [ B, A, C ] } );
	check( '...and the list is drawn in the new order', w.all( w.mount, function( el ) { return el.tagName === 'LI' } ).map( function( row ) { return row.children[0].dataset.id } ).join() === '2,1,3' );

	arrows('up')[2].click();
	check( 'an arrow up moves the item before the one above: the last of the order B, A, C goes to second place', JSON.stringify( w.last().payload ) === '{"ids":[2,3,1]}' );
	w.answer( 500, { error : 'x', code : 'crm_items_database', params : [] } );
	check( 'an order that is refused says its sentence and leaves the list', w.texts().indexOf( w.word('/_admin/error/crm_items_database') ) !== -1 && w.all( w.mount, function( el ) { return el.tagName === 'LI' } ).length === 3 );

	const search = drawn('en_US');
	search.find( search.mount, function( el ) { return el.tagName === 'FORM' } );
	const filtered = world('en_US');
	filtered.panel._search = 'x';
	filtered.panel.showCurrent();
	filtered.answer( 200, { items : [ A, B ], settings : SMALL } );
	check( 'where the list is a search it is not the whole catalogue, and the arrows are off', filtered.all( filtered.mount, function( el ) { return el.tagName === 'BUTTON' && String( el.className ).indexOf('crm-move') !== -1 } ).every( function( el ) { return el.disabled === true } ) );
}

console.log( '\nThe card\n' );

{
	const w = opened( 'en_US', A );
	check( 'a row opens the card at once, from the list: the fields of the item, price in the form of the server', FIELDS.every( function( name ) { return w.input( w.mount, name ) !== null } ) && w.input( w.mount, 'title' ).value === 'Webdesign' && w.input( w.mount, 'price' ).value === '90,00' && w.input( w.mount, 'unit' ).value === 'Std.'
		&& w.input( w.mount, 'description' ).value === 'Pro Stunde' && w.input( w.mount, 'price' ).inputMode === 'decimal' );
	check( '...no field for the rate without VAT', w.select( w.mount, 'vat_rate' ) === null );

	const regular = opened( 'en_US', B, REGULAR );
	check( 'with VAT the rate is a select of the rates of the settings, and the rate of the item', regular.all( regular.select( regular.mount, 'vat_rate' ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.textContent } ).join() === '19 %,7 %,0 %' && regular.select( regular.mount, 'vat_rate' ).value === '700' );
	const odd = opened( 'en_US', Object.assign( {}, B, { vat_rate : 1600 } ), REGULAR );
	check( '...an item with a rate the settings no longer list keeps it in the select', odd.select( odd.mount, 'vat_rate' ).value === '1600' );

	const fresh = drawn('en_US');
	fresh.button( fresh.mount, fresh.word( K+ 'label/new' ) ).click();
	check( 'a new item starts empty and asks nothing of the server', fresh.input( fresh.mount, 'title' ).value === '' && fresh.input( fresh.mount, 'price' ).value === '' && fresh.requests.length === 1 );
}

console.log( '\nSaving\n' );

{
	const w = opened( 'en_US', A );
	w.input( w.mount, 'price' ).value = '95,5';
	const result = [];
	w.entry().save( function( ok ) { result.push( ok ) } );
	const payload = w.last().payload;

	check( 'a save posts crm-items/save with the four fields as typed, the rate 0 without VAT and the id as a string', w.last().action === 'crm-items/save' && Object.keys( payload ).sort().join() === [ 'description', 'id', 'price', 'title', 'unit', 'vat_rate' ].sort().join() && payload.id === '1' && payload.price === '95,5' && payload.vat_rate === 0 && payload.title === 'Webdesign' );

	w.answer( 200, { item : Object.assign( {}, A, { price_net : 9550 } ) } );
	check( 'a save that goes through ends in done( true ); the card is drawn again from what is stored, and its line says it is saved', result.join() === 'true' && w.input( w.mount, 'price' ).value === '95,50' && w.entry().isDirty() === false && ( w.line( w.mount ) || { dataset : {} } ).dataset.state === 'saved' );

	w.input( w.mount, 'price' ).value = 'teuer';
	const second = [];
	w.entry().save( function( ok ) { second.push( ok ) } );
	w.answer( 400, { error : 'x', code : 'crm_items_amount', params : [], field : 'price' } );
	check( 'a refusal says its sentence in the line, ends in done( false ), leaves what was typed and marks the field', w.texts().indexOf( w.word('/_admin/error/crm_items_amount') ) !== -1 && second.join() === 'false' && w.input( w.mount, 'price' ).value === 'teuer' && w.input( w.mount, 'price' ).attributes['aria-invalid'] === 'true' );

	const busy = [];
	w.entry().save( function( ok ) { busy.push( ok ) } );
	w.entry().save( function( ok ) { busy.push( ok ) } );
	check( 'a second save while one is out ends in done( false ) at once', busy.join() === 'false' );

	const regular = opened( 'en_US', C, REGULAR );
	regular.entry().save( function() {} );
	check( 'with VAT the rate is posted as a number', regular.last().payload.vat_rate === 1900 );

	const create = drawn('en_US');
	create.button( create.mount, create.word( K+ 'label/new' ) ).click();
	create.input( create.mount, 'title' ).value = 'Neu';
	fire( create.find( create.mount, function( el ) { return el.tagName === 'FORM' && String( el.className ).indexOf('crm-card') !== -1 } ), 'submit' );
	check( 'a new item is posted without an id', create.last().action === 'crm-items/save' && Object.keys( create.last().payload ).indexOf('id') === -1 && create.last().payload.title === 'Neu' );
}

console.log( '\nUnsaved input and the switch of active\n' );

{
	const w = opened( 'en_US', A );
	check( 'a card as it is drawn holds nothing unsaved', w.entry().isDirty() === false );
	w.input( w.mount, 'title' ).value = 'Typed';
	check( 'an edit is unsaved input', w.entry().isDirty() === true );
	w.entry().discard();
	check( 'discard puts the fields back, and the card is clean', w.input( w.mount, 'title' ).value === 'Webdesign' && w.entry().isDirty() === false );

	w.input( w.mount, 'title' ).value = 'Typed';
	w.button( w.mount, w.word( K+ 'label/deactivate' ) ).click();
	check( 'the switch posts crm-items/active with the id and the value it wants', w.last().action === 'crm-items/active' && JSON.stringify( w.last().payload ) === '{"id":"1","active":false}' );
	w.answer( 200, { item : Object.assign( {}, A, { active : false } ) } );
	check( '...the card is drawn again with the word of the other state, and what was typed is still typed, and still unsaved', w.button( w.mount, w.word( K+ 'label/activate' ) ) !== null && w.input( w.mount, 'title' ).value === 'Typed' && w.entry().isDirty() === true );

	const back = opened( 'en_US', A );
	back.input( back.mount, 'title' ).value = 'Typed';
	back.find( back.mount, function( el ) { return el.tagName === 'A' && String( el.className ).indexOf('nino-admin-back-link') !== -1 } ).click();
	check( 'the back link asks the registry before it leaves unsaved input', back.guarded.length === 1 && back.guarded[0].join() === 'crm-items' );
}

console.log( '\nThe script itself\n' );

{
	const source = fs.readFileSync( path.join( root, OWN+ 'assets/admin.js' ), 'utf8' );

	check( 'the script assigns no markup: innerHTML only to clear, no outerHTML, no insertAdjacentHTML, no document.write', source.match( /innerHTML\s*=(?!\s*'';)/g ) === null && /outerHTML|insertAdjacentHTML|document\.write/.test( source ) === false );
}

console.log( '\nThe German words\n' );

{
	const w = drawn('de_DE');
	const card = opened( 'de_DE', B, REGULAR );
	check( 'the German screens have every word they ask for', w.missing.length === 0 && card.missing.length === 0 );
}

done();

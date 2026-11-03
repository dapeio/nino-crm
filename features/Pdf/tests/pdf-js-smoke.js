/**
 *	Nino
 *	pdf-js-smoke.js		What the panel's script does over the kernel's own Nino.admin.js:
 *										the Layout asks for pdf/layout/get when its tab is first shown,
 *										draws the form from the answer, posts what the model holds and
 *										says a refusal in the sentence of its code; the picture a person
 *										chooses goes to pdf/layout/image with the file; the text blocks
 *										are a list and an editor; the template editor draws the blocks
 *										of a template with textContent, a move changes the order that
 *										is posted, a block is added and taken away, and the preview
 *										makes a blob address of the base64 the server answers and puts
 *										it in a frame and in the link that downloads it. Only Nino
 *										itself is a stand-in: the requests a panel makes through
 *										Nino.adminUi.api.call() are kept for the test to answer, and
 *										the words are the ones of the text files, in both interface
 *										languages. A test pins what the screen does with a word - a
 *										refusal shows the sentence of its code with its params, a button
 *										carries the label of its key - and not the word: what a screen
 *										should say is read from the same fills (say()), so that rewording
 *										a sentence breaks nothing and a screen that says the wrong one,
 *										or none, does.
 *
 *										The helpers - check(), done(), fills(), load(), findAll(),
 *										fire(), element() and documentOf() - are those of
 *										tests/harness.js of the checkout, three levels up or the one
 *										NINO_ROOT names. pdf-smoke.php runs this where node is on the
 *										path.
 *
 *	Usage: node features/Pdf/tests/pdf-js-smoke.js
 *	       NINO_ROOT=../nino node features/Pdf/tests/pdf-js-smoke.js
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
 *	@param		{string}	relative		A path below features/Pdf/
 *
 *	@return		{string}
 */
function own( relative ) {
	return path.relative( root, fs.realpathSync( path.join( __dirname, '..', relative ) ) );
}

/**
 *	The panel's script in a page of its own, in one interface language: the
 *	kernel's Nino.admin.js and the feature's admin.js over a Nino that keeps
 *	the requests, the dirty entries, the questions asked and the blobs made
 *
 *	@param		{string}		locale		en_US or de_DE: the words of the text files
 *
 *	@return		{Object}
 */
function world( locale ) {

	const words = Object.assign( fills( '_admin/text/'+ locale+ '.php' ), fills( own( 'text/'+ locale+ '.php' ) ) );
	const missing = [];
	const requests = [];
	const registered = [];
	const dialogs = [];
	const blobs = [];
	const revoked = [];
	const guarded = [];

	// The mounts of the three screens, as the shell's template and the tabs' panes give them: levels of one pane are siblings
	const page = element('div');
	const mounts = {};
	[ 'pdf-layout-form', 'pdf-blocks-list', 'pdf-blocks-edit', 'pdf-templates-list', 'pdf-templates-edit' ].forEach( function( id ) {
		mounts[id] = element('div');
		mounts[id].id = id;
		page.appendChild( mounts[id] );
	} );
	const doc = documentOf( page );

	const Nino = {
		content : { getText : function( key ) {
			if( words[key] === undefined )
				missing.push( key );
			return words[key] ?? '';
		} },
		slug : function( text, max ) { return String( text ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' ).slice( 0, max ) },
		admin : { dirty : {
			register : function( name, entry ) { registered.push( { name : name, entry : entry } ) },
			refresh : function() {},
			guard : function( names, proceed ) { guarded.push( names ); proceed( 'discard' ) },
		} },
	};

	const URL = {
		createObjectURL : function( blob ) { blobs.push( blob ); return 'blob:test/'+ blobs.length },
		revokeObjectURL : function( url ) { revoked.push( url ) },
	};

	function Blob( parts, options ) {
		this.parts = parts;
		this.type = options.type;
	}

	const context = vm.createContext( { console : console, document : doc, Nino : Nino, window : { Nino : Nino }, URL : URL, Blob : Blob, atob : atob } );
	load( '_admin/assets/Nino.admin.js', context );

	// The one way a panel talks to the server is the kernel's api.call(): what it is asked for is kept, to be answered
	Nino.adminUi.api.call = function( action, payload, callback, extra ) {
		requests.push( { action : action, payload : payload, callback : callback, extra : extra, answered : false } );
	};

	// A question is kept as well, and answered by the test
	Nino.adminUi.choiceDialog = function( options ) { dialogs.push( options ); return true };

	load( own( 'assets/admin.js' ), context, 'admin.js' );

	const find = function( root, predicate ) { return findAll( root, predicate )[0] ?? null };

	return {
		Nino : Nino,
		mounts : mounts,
		requests : requests,
		registered : registered,
		dialogs : dialogs,
		blobs : blobs,
		revoked : revoked,
		guarded : guarded,
		missing : missing,
		layout : Nino.admin.pdf,
		blocks : Nino.admin['pdf-blocks'],
		templates : Nino.admin['pdf-templates'],
		entry : function( name ) { return registered.find( function( one ) { return one.name === name } ).entry },

		// The words of a fill, with its params put in as the script does: a key the text files do not have is a mistake of the test
		say : function( key, ...params ) {

			if( words[key] === undefined )
				throw new Error( 'no such fill: '+ key );

			return Nino.adminUi.format( words[key], ...params );
		},
		find : find,
		field : function( mount, name ) { return find( mounts[mount], function( el ) { return el.attributes['data-field'] === name } ) },
		button : function( mount, text ) { return find( mounts[mount], function( el ) { return el.tagName === 'BUTTON' && el.textContent === text } ) },
		texts : function( mount, tag ) { return findAll( mounts[mount], function( el ) { return el.tagName === tag.toUpperCase() } ).map( function( el ) { return el.textContent } ) },
		line : function( mount ) { return find( mounts[mount], function( el ) { return el.classList.contains('nino-admin-status') } ) },

		// The answer of the server to the request that is out, as api.call() hands it to its callback
		answer : function( status, body ) {
			const open = requests.filter( function( request ) { return request.answered === false } );
			open[open.length - 1].answered = true;
			open[open.length - 1].callback( status, body );
		},
		last : function() { return requests[requests.length - 1] },
	};
}

const LAYOUT = {
	layout : { format : 'a4', margins : [ 20, 21, 22, 23 ], font : 'helvetica', size : 10, header : '<p>Head [[page]]</p>', footer : '', logo : 'logo.png', signature : '' },
	images : { logo : 'data:image/png;base64,AAAA', signature : '' },
	papers : [ 'a4', 'letter' ],
	fonts : [ 'helvetica', 'times', 'courier' ],
	ranges : { margin : [ 5, 50 ], size : [ 8, 14 ], band : 4000 },
};
const OFFER = {
	tags : [ 'offer', 'invoice' ],
	library : [ { key : 'intro', title : 'Intro' }, { key : 'terms', title : 'Terms <b>' } ],
	paper : [ 210, 297 ],
	ranges : { title : 120, html : 20000, label : 120, blocks : 60 },
};
const BLOCKS = { blocks : [ { key : 'intro', title : 'Intro', html : '<p>Hello</p>' }, { key : 'evil', title : '<img src=x onerror=alert(1)>', html : '<p>x</p>' } ], ranges : { title : 120, html : 20000 } };
const TEMPLATE = { key : 'offer-one', template : { name : 'Offer one', tag : 'offer', blocks : [
	{ type : 'html', block : 'intro' },
	{ type : 'slot', name : 'table' },
	{ type : 'signature', name : 'seller', party : 'owner', label : 'The seller', page : 'last', x : 20, y : 200, w : 60, h : 20, line : true },
] } };

console.log( 'Nino.admin.pdf - the layout\n' );

{
	const w = world('en_US');

	check( 'nothing is asked before a tab is shown, and the shell has no entry yet', w.requests.length === 0 && w.registered.length === 0 );
	w.layout.showCurrent();
	w.layout.showCurrent();
	check( 'showing the Layout asks for pdf/layout/get, once however often the shell shows it while the answer is out', w.requests.length === 1 && w.requests[0].action === 'pdf/layout/get' && JSON.stringify( w.requests[0].payload ) === '{}' );
	check( '...and it registers its entry under the uri of the panel, which holds nothing unsaved while nothing is drawn', w.registered.length === 1 && w.registered[0].name === 'pdf' && w.entry('pdf').isDirty() === false );

	w.answer( 200, LAYOUT );
	check( 'the form is drawn from the answer: the paper and the font in selects, the size, the four margins, the header and the footer', w.texts( 'pdf-layout-form', 'select' ).length === 2
		&& w.field( 'pdf-layout-form', 'size' ).value === '10' && w.field( 'pdf-layout-form', 'margins' ).value === '20' && w.field( 'pdf-layout-form', 'margins-right' ).value === '21'
		&& w.field( 'pdf-layout-form', 'margins-bottom' ).value === '22' && w.field( 'pdf-layout-form', 'margins-left' ).value === '23' && w.field( 'pdf-layout-form', 'header' ).value === '<p>Head [[page]]</p>' && w.field( 'pdf-layout-form', 'footer' ).value === '' );
	check( '...the numbers are limited the way the server holds them to it, the texts to the length it takes', w.field( 'pdf-layout-form', 'margins' ).min === '5' && w.field( 'pdf-layout-form', 'margins' ).max === '50' && w.field( 'pdf-layout-form', 'size' ).max === '14'
		&& w.field( 'pdf-layout-form', 'header' ).maxLength === 4000 );
	check( '...the picture that is there is drawn as a picture, with its remove button, the one that is not as the empty state; the tokens the header takes are named', w.find( w.mounts['pdf-layout-form'], function( el ) { return el.tagName === 'IMG' } ).src === 'data:image/png;base64,AAAA'
		&& w.button( 'pdf-layout-form', w.say('/_admin/pdf/label/remove') ) !== null && w.texts( 'pdf-layout-form', 'p' ).indexOf( w.say('/_admin/pdf/label/none') ) !== -1
		&& w.texts( 'pdf-layout-form', 'small' ).some( function( text ) { return text.indexOf('[[page]], [[pages]]') !== -1 } ) );

	w.field( 'pdf-layout-form', 'margins' ).value = '60';
	fire( w.field( 'pdf-layout-form', 'margins' ), 'input', { bubbles : true } );
	check( 'a changed field turns the entry to unsaved and the line to unsaved changes', w.entry('pdf').isDirty() === true );

	const told = [];
	w.entry('pdf').save( function( ok ) { told.push( ok ) } );
	check( 'a save posts pdf/layout/save with what the model holds, numbers as numbers, and the line says it is saving', w.last().action === 'pdf/layout/save'
		&& JSON.stringify( w.last().payload ) === '{"format":"a4","margins":[60,21,22,23],"font":"helvetica","size":10,"header":"<p>Head [[page]]</p>","footer":""}' && w.line('pdf-layout-form').dataset.state === 'saving' );

	w.answer( 400, { error : 'x', code : 'pdf_margin', params : [ 5, 50 ], field : 'margins' } );
	check( '...a refusal is told in the sentence of its code, with the limits in it, ends in done( false ), and the field the server named is marked', w.line('pdf-layout-form').dataset.state === 'error'
		&& w.line('pdf-layout-form').textContent === w.say( '/_admin/error/pdf_margin', 5, 50 ) && told.join() === 'false' && w.field( 'pdf-layout-form', 'margins' ).attributes['aria-invalid'] === 'true' );

	w.field( 'pdf-layout-form', 'margins' ).value = '12';
	fire( w.field( 'pdf-layout-form', 'margins' ), 'input', { bubbles : true } );
	w.entry('pdf').save( function( ok ) { told.push( ok ) } );
	w.answer( 200, LAYOUT );
	check( '...and the next save that goes through ends in done( true ), the entry saying nothing is unsaved', told.join() === 'false,true' && w.entry('pdf').isDirty() === false && w.line('pdf-layout-form').dataset.state === 'saved' );
	check( '...what it posted was what the field said', JSON.stringify( w.requests[w.requests.length - 1].payload.margins ) === '[12,21,22,23]' );

	// The header and the footer are shown as the server stored them, not as they were typed
	w.field( 'pdf-layout-form', 'header' ).value = '<p onclick="x()">Typed head</p><script>y()</script>';
	fire( w.field( 'pdf-layout-form', 'header' ), 'input', { bubbles : true } );
	w.entry('pdf').save( function() {} );
	const typedHeader = w.last().payload.header;
	w.answer( 200, Object.assign( {}, LAYOUT, { layout : Object.assign( {}, LAYOUT.layout, { header : '<p>Typed head</p>', footer : '<p>Stored foot</p>' } ) } ) );
	check( 'a save of the layout shows the header and the footer as the server stored them: what was posted is what was typed, what the fields show and the model hold afterwards is the stored text, and nothing is unsaved',
		typedHeader.indexOf('onclick') !== -1 && w.field( 'pdf-layout-form', 'header' ).value === '<p>Typed head</p>' && w.field( 'pdf-layout-form', 'footer' ).value === '<p>Stored foot</p>' && w.entry('pdf').isDirty() === false );
	w.entry('pdf').save( function() {} );
	check( '...and the next save posts the stored texts', w.last().payload.header === '<p>Typed head</p>' && w.last().payload.footer === '<p>Stored foot</p>' );
	w.field( 'pdf-layout-form', 'header' ).value = '<p>Typed on</p>';
	fire( w.field( 'pdf-layout-form', 'header' ), 'input', { bubbles : true } );
	w.answer( 200, Object.assign( {}, LAYOUT, { layout : Object.assign( {}, LAYOUT.layout, { header : '<p>Typed head</p>', footer : '<p>Stored foot</p>' } ) } ) );
	check( '...a text typed while the request was out stays as it was typed, and the entry still says it is not saved', w.field( 'pdf-layout-form', 'header' ).value === '<p>Typed on</p>' && w.entry('pdf').isDirty() === true );

	w.field( 'pdf-layout-form', 'format' ).value = 'letter';
	fire( w.field( 'pdf-layout-form', 'format' ), 'change' );
	w.entry('pdf').save( function( ok ) { told.push( ok ) } );
	w.answer( 409, { error : 'x', code : 'pdf_format_boxes', params : [ 'Offer one, Offer two' ], field : 'format' } );
	check( 'a change of the paper that the server refuses for the boxes of templates is told in the sentence of its code with their names, and the select it names is marked', w.line('pdf-layout-form').dataset.state === 'error'
		&& w.line('pdf-layout-form').textContent === w.say( '/_admin/error/pdf_format_boxes', 'Offer one, Offer two' ) && w.line('pdf-layout-form').textContent.indexOf('Offer one, Offer two') !== -1
		&& w.field( 'pdf-layout-form', 'format' ).attributes['aria-invalid'] === 'true' && told.join() === 'false,true,false' );
	w.field( 'pdf-layout-form', 'format' ).value = 'a4';
	fire( w.field( 'pdf-layout-form', 'format' ), 'change' );
	w.field( 'pdf-layout-form', 'header' ).value = '<p>Typed head</p>';
	fire( w.field( 'pdf-layout-form', 'header' ), 'input', { bubbles : true } );

	const fileInput = w.find( w.mounts['pdf-layout-form'], function( el ) { return el.tagName === 'INPUT' && el.type === 'file' } );
	const file = { name : 'logo.png', size : 10 };
	fileInput.files = [ file ];
	fire( fileInput, 'change' );
	check( 'a chosen picture goes to pdf/layout/image with the kind and the file itself', w.last().action === 'pdf/layout/image' && JSON.stringify( w.last().payload ) === '{"kind":"logo"}' && w.last().extra.file === file );

	w.field( 'pdf-layout-form', 'size' ).value = '12';
	fire( w.field( 'pdf-layout-form', 'size' ), 'input', { bubbles : true } );
	w.answer( 200, Object.assign( {}, LAYOUT, { images : { logo : 'data:image/png;base64,BBBB', signature : '' } } ) );
	check( '...the answer draws the screen again with the new picture, and keeps what the person had typed that is not saved', w.find( w.mounts['pdf-layout-form'], function( el ) { return el.tagName === 'IMG' } ).src === 'data:image/png;base64,BBBB'
		&& w.field( 'pdf-layout-form', 'size' ).value === '12' && w.entry('pdf').isDirty() === true );

	w.button( 'pdf-layout-form', w.say('/_admin/pdf/label/remove') ).dispatchEvent( { type : 'click' } );
	check( '...taking a picture away asks first, and the question is a danger and a way out', w.dialogs.length === 1 && w.dialogs[0].choices.map( function( one ) { return one.kind } ).join() === 'danger,secondary' );
	w.dialogs[0].onChoose( 'cancel' );
	check( '...and a no sends nothing', w.last().action === 'pdf/layout/image' );
	w.dialogs[0].onChoose( 'delete' );
	check( '...a yes posts pdf/layout/image/delete with the kind', w.last().action === 'pdf/layout/image/delete' && JSON.stringify( w.last().payload ) === '{"kind":"logo"}' );
	w.answer( 400, { error : 'x', code : 'pdf_image_kind' } );
	check( '...and a refusal of it is told under the picture, in the sentence of its code', w.texts( 'pdf-layout-form', 'p' ).indexOf( w.say('/_admin/error/pdf_image_kind') ) !== -1 );
}


console.log( '\nNino.admin[\'pdf-blocks\'] - the text blocks\n' );

{
	const w = world('en_US');

	w.blocks.showCurrent();
	check( 'the tab asks for pdf/blocks/list and answers the panel\'s own action', w.requests.length === 1 && w.requests[0].action === 'pdf/blocks/list' && w.registered[0].name === 'pdf-blocks' );
	w.answer( 200, BLOCKS );

	const rows = w.texts( 'pdf-blocks-list', 'strong' );
	check( 'the list draws a row for each block, a title and a key, as text', rows.length === 2 && rows[0] === 'Intro' && rows[1] === '<img src=x onerror=alert(1)>'
		&& findAll( w.mounts['pdf-blocks-list'], function( el ) { return el.tagName === 'IMG' } ).length === 0 && w.texts( 'pdf-blocks-list', 'small' ).join() === 'intro,evil' );

	w.find( w.mounts['pdf-blocks-list'], function( el ) { return el.tagName === 'BUTTON' && el.attributes['data-key'] === 'intro' } ).dispatchEvent( { type : 'click' } );
	check( 'a row opens the editor of the block: the title, the key - which cannot be changed any more - and the text', w.field( 'pdf-blocks-edit', 'title' ).value === 'Intro' && w.field( 'pdf-blocks-edit', 'key' ).value === 'intro'
		&& w.field( 'pdf-blocks-edit', 'key' ).disabled === true && w.field( 'pdf-blocks-edit', 'html' ).value === '<p>Hello</p>' && w.field( 'pdf-blocks-edit', 'html' ).maxLength === 20000
		&& w.mounts['pdf-blocks-edit'].classList.contains('admin-hidden') === false && w.mounts['pdf-blocks-list'].classList.contains('admin-hidden') === true );
	check( '...the text the person writes into it names the tokens a block may hold', w.texts( 'pdf-blocks-edit', 'small' ).some( function( text ) { return text.indexOf('[[name]], [[a.b]]') !== -1 && text.indexOf('[[page]]') === -1 } ) );

	w.field( 'pdf-blocks-edit', 'html' ).value = '<p>Changed</p>';
	fire( w.field( 'pdf-blocks-edit', 'html' ), 'input', { bubbles : true } );
	const told = [];
	w.entry('pdf-blocks').save( function( ok ) { told.push( ok ) } );
	check( 'a save posts pdf/blocks/save with the key, the title, the text and that the block is not new', w.last().action === 'pdf/blocks/save' && JSON.stringify( w.last().payload ) === '{"key":"intro","isNew":false,"title":"Intro","html":"<p>Changed</p>"}' );
	w.answer( 200, { blocks : [ { key : 'intro', title : 'Intro', html : '<p>Changed</p>' } ], ranges : BLOCKS.ranges } );
	check( '...and ends in done( true ), the entry saying nothing is unsaved', told.join() === 'true' && w.entry('pdf-blocks').isDirty() === false );

	// What is shown after a save is the text the server stored, not the one that was typed
	w.field( 'pdf-blocks-edit', 'html' ).value = '<p onclick="x()">Typed</p><script>y()</script>';
	fire( w.field( 'pdf-blocks-edit', 'html' ), 'input', { bubbles : true } );
	w.entry('pdf-blocks').save( function() {} );
	const typed = w.last().payload.html;
	w.answer( 200, { blocks : [ { key : 'intro', title : 'Intro', html : '<p>Typed</p>' } ], ranges : BLOCKS.ranges } );
	check( 'a save shows the text as the server stored it: what was posted is what was typed, what the textarea shows and the model holds afterwards is the stored text, and nothing is unsaved', typed.indexOf('onclick') !== -1
		&& w.field( 'pdf-blocks-edit', 'html' ).value === '<p>Typed</p>' && w.entry('pdf-blocks').isDirty() === false );
	w.entry('pdf-blocks').save( function() {} );
	check( '...and the next save posts the stored text', w.last().payload.html === '<p>Typed</p>' );
	w.field( 'pdf-blocks-edit', 'html' ).value = '<p>Typed on</p>';
	fire( w.field( 'pdf-blocks-edit', 'html' ), 'input', { bubbles : true } );
	w.answer( 200, { blocks : [ { key : 'intro', title : 'Intro', html : '<p>Typed</p>' } ], ranges : BLOCKS.ranges } );
	check( '...a text typed while the request was out stays as it was typed, and the entry still says it is not saved', w.field( 'pdf-blocks-edit', 'html' ).value === '<p>Typed on</p>' && w.entry('pdf-blocks').isDirty() === true );

	w.button( 'pdf-blocks-edit', w.say('/_admin/pdf/label/delete') ).dispatchEvent( { type : 'click' } );
	check( 'delete asks first, naming the block', w.dialogs.length === 1 && w.dialogs[0].title === 'Intro' );
	w.dialogs[0].onChoose( 'delete' );
	check( '...and a yes posts pdf/blocks/delete with the key', w.last().action === 'pdf/blocks/delete' && JSON.stringify( w.last().payload ) === '{"key":"intro"}' );
	w.answer( 409, { error : 'used', code : 'pdf_block_used', params : [ 'Offer one, Offer two' ] } );
	check( '...a 409 is told in its sentence with the names of the templates, and the editor stays', w.line('pdf-blocks-edit').textContent === w.say( '/_admin/error/pdf_block_used', 'Offer one, Offer two' )
		&& w.mounts['pdf-blocks-edit'].classList.contains('admin-hidden') === false );

	w.button( 'pdf-blocks-list', w.say('/_admin/pdf/label/new') ).dispatchEvent( { type : 'click' } );
	w.field( 'pdf-blocks-edit', 'title' ).value = 'Terms of sale';
	fire( w.field( 'pdf-blocks-edit', 'title' ), 'input', { bubbles : true } );
	check( 'a new block: a key is made of the title as it is typed, until the person writes one of their own', w.field( 'pdf-blocks-edit', 'key' ).value === 'terms-of-sale' && w.field( 'pdf-blocks-edit', 'key' ).disabled === false );
	w.field( 'pdf-blocks-edit', 'key' ).value = 'terms';
	fire( w.field( 'pdf-blocks-edit', 'key' ), 'input', { bubbles : true } );
	w.field( 'pdf-blocks-edit', 'title' ).value = 'Terms of sale 2';
	fire( w.field( 'pdf-blocks-edit', 'title' ), 'input', { bubbles : true } );
	w.entry('pdf-blocks').save( function() {} );
	check( '...which then stays, and the post says the block is new', JSON.stringify( w.last().payload ) === '{"key":"terms","isNew":true,"title":"Terms of sale 2","html":""}' );
	w.answer( 400, { error : 'x', code : 'pdf_key_taken', field : 'key' } );
	check( '...a key that is taken is told, and marked', w.line('pdf-blocks-edit').textContent === w.say('/_admin/error/pdf_key_taken') && w.field( 'pdf-blocks-edit', 'key' ).attributes['aria-invalid'] === 'true' );
}


console.log( '\nNino.admin[\'pdf-templates\'] - the templates and their editor\n' );

{
	const w = world('en_US');
	const body = function( kind ) { return w.texts( 'pdf-templates-edit', 'strong' ).filter( function( text ) { return /^\d+\. /.test( text ) } ) };

	// The heads of the cards of a stack of blocks of these types: the number, a full stop and the word of the type
	const heads = function( ...types ) { return types.map( function( type, at ) { return ( at + 1 )+ '. '+ w.say('/_admin/pdf/type/'+ type) } ).join() };

	w.templates.showCurrent();
	check( 'the tab asks for pdf/templates/list', w.requests.length === 1 && w.requests[0].action === 'pdf/templates/list' && w.registered[0].name === 'pdf-templates' );
	w.answer( 200, Object.assign( { templates : [ { key : 'offer-one', name : 'Offer one', tag : 'offer', blocks : 3, known : true }, { key : 'old', name : 'Old', tag : 'retired', blocks : 1, known : false } ] }, OFFER ) );
	check( 'the list draws a row for each template: its name, its tag and its number of blocks, and a mark on a tag the project no longer has', w.texts( 'pdf-templates-list', 'strong' ).join() === 'Offer one,Old'
		&& w.texts( 'pdf-templates-list', 'small' ).join() === 'offer - '+ w.say( '/_admin/pdf/label/blocks', 3 )+ ',retired - '+ w.say( '/_admin/pdf/label/blocks', 1 )
		&& w.texts( 'pdf-templates-list', 'span' ).filter( function( text ) { return text === w.say('/_admin/pdf/label/tag-gone') } ).length === 1 );

	w.find( w.mounts['pdf-templates-list'], function( el ) { return el.tagName === 'BUTTON' && el.attributes['data-key'] === 'offer-one' } ).dispatchEvent( { type : 'click' } );
	check( 'a row asks for the template, pdf/templates/get with its key', w.last().action === 'pdf/templates/get' && JSON.stringify( w.last().payload ) === '{"key":"offer-one"}' );
	w.answer( 200, Object.assign( {}, TEMPLATE, OFFER ) );

	check( 'the editor draws the name, the key - fixed - and the tag, with the tags of the project', w.field( 'pdf-templates-edit', 'name' ).value === 'Offer one' && w.field( 'pdf-templates-edit', 'key' ).value === 'offer-one' && w.field( 'pdf-templates-edit', 'key' ).disabled === true
		&& w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'tag' } ).value === 'offer'
		&& findAll( w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'tag' } ), function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.textContent } ).join() === 'offer,invoice' );
	check( '...and the blocks in their order, a card each, headed by its number and its type, as text', body().join() === heads( 'html', 'slot', 'signature' ) );
	check( '...the html block offers the library by title - a title with a tag in it is text - and a text of its own, with the library\'s block chosen and the text of its own hidden',
		findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.tagName === 'OPTION' } ).map( function( el ) { return el.textContent } ).indexOf('Terms <b>') !== -1
		&& w.field( 'pdf-templates-edit', 'html' ).parentNode.hidden === true && w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'source' } ).value === 'intro' );
	const own = w.field( 'pdf-templates-edit', 'html' ).parentNode;
	const source = w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'source' } );
	source.value = '';
	fire( source, 'change' );
	const shownOwn = own.hidden;
	source.value = 'intro';
	fire( source, 'change' );
	check( '...the text of its own is hidden while a block of the library is chosen, shown when a text of its own is, and hidden again with the next choice of the library', shownOwn === false && own.hidden === true );
	check( '...and the preview is hidden until one has been made: there is no empty frame and no link to a file that is not there', w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.id === 'pdf-preview' } ).hidden === true );
	check( '...the signature block has its numbers, to a tenth, limited to the paper', findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.tagName === 'INPUT' && el.step === '0.1' } ).map( function( el ) { return el.value } ).join() === '20,200,60,20'
		&& findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.tagName === 'INPUT' && el.step === '0.1' } ).map( function( el ) { return el.max } ).join() === '210,297,210,297' );

	// Move the first block down
	w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-step'] === 'down' } ).dispatchEvent( { type : 'click' } );
	check( 'moving the first block down changes the order: the cards are drawn again from the model, and the focus follows the block', body().join() === heads( 'slot', 'html', 'signature' )
		&& w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.focused === true } ).attributes['data-step'] === 'down' );
	check( '...and the first card has no way up, the last none down', findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-step'] === 'up' } )[0].disabled === true
		&& findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-step'] === 'down' } ).pop().disabled === true );
	check( '...the entry says there is something to save', w.entry('pdf-templates').isDirty() === true && w.line('pdf-templates-edit').dataset.state === 'dirty' );

	// Add an input block
	const type = w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'type' } );
	type.value = 'input';
	w.button( 'pdf-templates-edit', w.say('/_admin/pdf/label/add') ).dispatchEvent( { type : 'click' } );
	check( 'a block of a type is added at the end, with its form', body().join() === heads( 'slot', 'html', 'signature', 'input' ) );

	const told = [];
	w.entry('pdf-templates').save( function( ok ) { told.push( ok ) } );
	const sent = w.last();
	check( 'a save posts pdf/templates/save: the blocks in the order shown, an html block as a reference or a text and never both, the numbers as numbers, the page as it was written',
		sent.action === 'pdf/templates/save' && sent.payload.isNew === false && sent.payload.key === 'offer-one' && sent.payload.name === 'Offer one' && sent.payload.tag === 'offer'
		&& sent.payload.blocks.map( function( block ) { return block.type } ).join() === 'slot,html,signature,input' && JSON.stringify( sent.payload.blocks[1] ) === '{"type":"html","block":"intro"}'
		&& sent.payload.blocks[2].x === 20 && sent.payload.blocks[2].page === 'last' && JSON.stringify( sent.payload.blocks[3] ) === '{"type":"input","name":"input","label":"","multiline":false,"required":false}' );
	w.answer( 400, { error : 'x', code : 'pdf_signature_box', params : [ 3 ], field : 'blocks' } );
	check( '...a refusal names the block in its sentence and ends in done( false )', w.line('pdf-templates-edit').textContent === w.say( '/_admin/error/pdf_signature_box', 3 ) && told.join() === 'false' );
	w.entry('pdf-templates').save( function( ok ) { told.push( ok ) } );
	w.answer( 200, Object.assign( { key : 'offer-one', templates : [ { key : 'offer-one', name : 'Offer one', tag : 'offer', blocks : 4, known : true } ] }, OFFER ) );
	check( '...and the next that goes through ends in done( true )', told.join() === 'false,true' && w.entry('pdf-templates').isDirty() === false && w.line('pdf-templates-edit').dataset.state === 'saved' );

	// Take the input away again
	findAll( w.mounts['pdf-templates-edit'], function( el ) { return el.tagName === 'BUTTON' && el.textContent === '×' } ).pop().dispatchEvent( { type : 'click' } );
	check( 'a block is taken away by its own button', body().join() === heads( 'slot', 'html', 'signature' ) && w.entry('pdf-templates').isDirty() === true );

	// The preview
	w.button( 'pdf-templates-edit', w.say('/_admin/pdf/label/preview') ).dispatchEvent( { type : 'click' } );
	check( 'the preview asks the server for the PDF of what the editor holds, saved or not', w.last().action === 'pdf/preview' && w.last().payload.blocks.length === 3 && w.last().payload.name === 'Offer one' );
	w.answer( 200, { pdf : 'JVBERi0xLjQgeA==', file : 'preview.pdf' } );
	const frame = w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.tagName === 'IFRAME' } );
	const link = w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.className.indexOf('pdf-preview-link') !== -1 } );
	check( '...and makes a blob of the base64 - the bytes of a PDF, as application/pdf - and puts its address in the frame and in the link that downloads it, named after the key',
		w.blobs.length === 1 && w.blobs[0].type === 'application/pdf' && Array.from( w.blobs[0].parts[0] ).map( function( code ) { return String.fromCharCode( code ) } ).join('') === '%PDF-1.4 x'
		&& frame.src === 'blob:test/1' && link.href === 'blob:test/1' && link.attributes['download'] === 'offer-one.pdf' && w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.id === 'pdf-preview' } ).hidden === false );
	w.button( 'pdf-templates-edit', w.say('/_admin/pdf/label/preview') ).dispatchEvent( { type : 'click' } );
	w.answer( 200, { pdf : 'JVBERi0xLjQgeQ==', file : 'preview.pdf' } );
	check( '...a second preview lets go of the first address', w.revoked.join() === 'blob:test/1' && frame.src === 'blob:test/2' );
	w.button( 'pdf-templates-edit', w.say('/_admin/pdf/label/preview') ).dispatchEvent( { type : 'click' } );
	w.answer( 400, { error : 'x', code : 'pdf_tag', field : 'tag' } );
	check( '...a preview the server refuses is told in the sentence of its code, and the last PDF stays', w.line('pdf-templates-edit').textContent === w.say('/_admin/error/pdf_tag') && frame.src === 'blob:test/2' );
	check( '...and the select the server named is marked, as the field of a form is', w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'tag' } ).attributes['aria-invalid'] === 'true' );

	// Leaving
	w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.className === 'nino-admin-back-link' } ).dispatchEvent( { type : 'click', preventDefault : function() {} } );
	check( 'the back link of an editor that holds unsaved input asks the shell first, and the list is there again when it is let through; the last address is let go of', w.guarded.join() === 'pdf-templates'
		&& w.mounts['pdf-templates-list'].classList.contains('admin-hidden') === false && w.revoked.indexOf('blob:test/2') !== -1 );

	// Duplicate and delete from the list
	w.button( 'pdf-templates-list', w.say('/_admin/pdf/label/duplicate') ).dispatchEvent( { type : 'click' } );
	check( 'duplicate posts the key and a name the interface language makes - the original\'s with the word for a copy, which the server has none of', w.last().action === 'pdf/templates/duplicate'
		&& JSON.stringify( w.last().payload ) === JSON.stringify( { key : 'offer-one', name : w.say( '/_admin/pdf/label/copy-of', 'Offer one' ) } ) && w.last().payload.name.indexOf('Offer one') !== -1 && w.last().payload.name !== 'Offer one' );
	w.answer( 200, Object.assign( { key : 'offer-one-copy', templates : [ { key : 'offer-one', name : 'Offer one', tag : 'offer', blocks : 3, known : true }, { key : 'offer-one-copy', name : 'Offer one (copy)', tag : 'offer', blocks : 3, known : true } ] }, OFFER ) );
	check( '...the list is drawn again from the answer', w.texts( 'pdf-templates-list', 'strong' ).join() === 'Offer one,Offer one (copy)' );
	w.button( 'pdf-templates-list', w.say('/_admin/pdf/label/delete') ).dispatchEvent( { type : 'click' } );
	w.dialogs[w.dialogs.length - 1].onChoose( 'delete' );
	check( '...delete asks first and posts the key on a yes', w.last().action === 'pdf/templates/delete' && JSON.stringify( w.last().payload ) === '{"key":"offer-one"}' );

	// A new template starts from what the project has now
	w.button( 'pdf-templates-list', w.say('/_admin/pdf/label/new') ).dispatchEvent( { type : 'click' } );
	check( 'a new template asks for the list first, so that it is offered the library as it is now', w.last().action === 'pdf/templates/list' );
	w.answer( 200, Object.assign( { templates : [] }, OFFER, { library : [ { key : 'fresh', title : 'Fresh' } ] } ) );
	w.field( 'pdf-templates-edit', 'name' ).value = 'Brand new';
	fire( w.field( 'pdf-templates-edit', 'name' ), 'input', { bubbles : true } );
	check( '...an empty editor with the first tag, a key made of the name, and no block', w.field( 'pdf-templates-edit', 'key' ).value === 'brand-new' && w.field( 'pdf-templates-edit', 'key' ).disabled === false && body().length === 0
		&& w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'tag' } ).value === 'offer' );
	w.button( 'pdf-templates-edit', w.say('/_admin/pdf/label/add') ).dispatchEvent( { type : 'click' } );
	check( '...a block added to it starts with the first block of the library', w.find( w.mounts['pdf-templates-edit'], function( el ) { return el.attributes['data-key'] === 'source' } ).value === 'fresh' );
	w.entry('pdf-templates').save( function() {} );
	check( '...and the post says the template is new', w.last().payload.isNew === true && w.last().payload.key === 'brand-new' && w.last().payload.blocks.length === 1 );
}


console.log( '\nThe stylesheet\n' );

{
	// The rules of the sheet, in the order they are written: a selector and what it declares. A browser decides what is shown by the
	// cascade, which a script of stand-ins cannot - so what the cascade needs of the sheet is pinned in the sheet
	const css = fs.readFileSync( path.join( __dirname, '../assets/admin.css' ), 'utf8' ).replace( /\/\*[\s\S]*?\*\//g, '' );
	const rules = [];

	css.replace( /([^{}]+)\{([^{}]*)\}/g, function( all, selector, declarations ) { rules.push( { selector : selector.trim(), declarations : declarations } ); return all } );

	const sets = function( rule, property, value ) { return new RegExp( '(^|;)\\s*'+ property+ '\\s*:\\s*'+ value+ '\\s*(;|$)' ).test( rule.declarations ) };
	const rule = function( selector ) { return rules.findIndex( function( one ) { return one.selector === selector } ) };
	const hide = rule('#admin-content-pdf [hidden]');
	const margins = rule('#admin-content-pdf .pdf-margins > .nino-admin-field');

	check( 'every rule of the sheet is scoped to the root of the panel, and the sheet writes into the layer of the tools', rules.length > 10 && rules.every( function( one ) { return one.selector.split(',').every( function( part ) { return part.trim().indexOf('#admin-content-pdf') === 0 } ) } )
		&& /@layer nino\.system, nino\.tool, nino\.local;\s*@layer nino\.tool \{/.test( css ) === true );
	check( '...what the script hides with the attribute is hidden by a rule of the panel - a display of the shell or of a rule of the panel beats the browser\'s own - and that rule is written after every other that sets a display', hide !== -1 && sets( rules[hide], 'display', 'none' ) === true
		&& rules.every( function( one, at ) { return at === hide || at < hide || /(^|;)\s*display\s*:/.test( one.declarations ) === false } ) );
	check( '...and the margins stand over their inputs: a field in them is a block, the label above its input, not a row of the shell\'s columns of which the input is left a few pixels', margins !== -1 && sets( rules[margins], 'display', 'block' ) === true );
}


console.log( '\nThe words, in both languages\n' );

// What the screens of each language showed: a label, the sentence of a refusal, a button - to be told apart from the other language's
const shown = {};

[ 'en_US', 'de_DE' ].forEach( function( locale ) {

	const w = world( locale );

	w.layout.showCurrent();
	w.answer( 200, LAYOUT );
	w.entry('pdf').save( function() {} );
	w.answer( 400, { error : 'x', code : 'pdf_margin', params : [ 5, 50 ], field : 'margins' } );
	w.blocks.showCurrent();
	w.answer( 200, BLOCKS );
	w.find( w.mounts['pdf-blocks-list'], function( el ) { return el.tagName === 'BUTTON' && el.attributes['data-key'] === 'intro' } ).dispatchEvent( { type : 'click' } );
	w.templates.showCurrent();
	w.answer( 200, Object.assign( { templates : [ { key : 'offer-one', name : 'Offer one', tag : 'offer', blocks : 3, known : true } ] }, OFFER ) );
	w.find( w.mounts['pdf-templates-list'], function( el ) { return el.tagName === 'BUTTON' && el.attributes['data-key'] === 'offer-one' } ).dispatchEvent( { type : 'click' } );
	w.answer( 200, Object.assign( {}, TEMPLATE, OFFER ) );

	shown[locale] = { margin : w.line('pdf-layout-form').textContent, paper : w.say('/_admin/pdf/label/paper'), add : w.say('/_admin/pdf/label/add-type'), button : w.say('/_admin/pdf/label/save') };

	check( locale+ ': the labels, the buttons and the sentence of a refusal are the words of the language\'s own text file, the params in the sentence', w.texts( 'pdf-layout-form', 'span' ).indexOf( shown[locale].paper ) !== -1
		&& w.line('pdf-layout-form').textContent === w.say( '/_admin/error/pdf_margin', 5, 50 ) && /5\D+50/.test( shown[locale].margin ) === true
		&& w.texts( 'pdf-templates-edit', 'span' ).indexOf( shown[locale].add ) !== -1 && w.button( 'pdf-layout-form', shown[locale].button ) !== null );
	check( locale+ ': every word the script asked for is in the text files', w.missing.length === 0 );
} );

check( 'the two languages do not say the same: the screens of one are not the words of the other', Object.keys( shown.en_US ).every( function( key ) { return shown.en_US[key] !== shown.de_DE[key] } ) );

done();
